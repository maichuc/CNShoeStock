<?php
/**
 * QR Code Manager Class
 * Quản lý việc đọc và xử lý QR codes
 */

class QRCodeManager {
    protected $pdo;
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Lấy thông tin sản phẩm từ QR (trả thêm quantity, unit_price, location, last receipt)
     */
    // changed code: cập nhật getProductFromQR để nhận JSON QR (variant_id, receipt_id, uuid, quantity, location_code)
    public function getProductFromQR($qr) {
        $pdo = $this->pdo;
        $raw = trim($qr);
        
        // Thử clean JSON trước
        $clean_qr = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $raw);
        
        $decoded = json_decode($clean_qr, true);
        $data = (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : [];
        
        // Nếu JSON lỗi, thử parse UUID trực tiếp từ string
        if (empty($data) && preg_match('/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i', $raw, $matches)) {
            $data['uuid'] = $matches[1];
        }

        $variantId = $data['variant_id'] ?? null;
        $uuid = $data['uuid'] ?? ($data['qr_uuid'] ?? null);
        $receiptId = $data['receipt_id'] ?? null;
        


        $result = [
            'qr_raw' => $raw,
            'product_id' => null,
            'variant_id' => null,
            'product_name' => null,
            'brand' => null,
            'type' => null,
            'sku' => null,
            'color' => null,
            'size' => null,
            'total_inventory_quantity' => null,
            'last_receipt_quantity' => null,
            'last_unit_price' => null,
            'location_code' => null,
            'last_receipt_date' => null
        ];

        // 1) Nếu có receipt_id (+ optional variant_id) -> tìm trực tiếp trong stock_receipt_items (ưu tiên)
        if ($receiptId || $uuid || $variantId) {
            $conds = [];
            $params = [];

            if ($receiptId) {
                $conds[] = "sri.receipt_id = :rid";
                $params[':rid'] = $receiptId;
            }
            if ($variantId) {
                $conds[] = "sri.variant_id = :vid";
                $params[':vid'] = $variantId;
            }
            // UUID search removed - now using product_qr_codes table

            if ($conds) {
                $sql = "SELECT sri.*, sr.created_at AS receipt_created_at
                        FROM stock_receipt_items sri
                        JOIN stock_receipts sr ON sri.receipt_id = sr.receipt_id
                        WHERE " . implode(' AND ', $conds) . "
                        ORDER BY sr.created_at DESC
                        LIMIT 1";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $sri = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Nếu không tìm thấy exact match, tìm chỉ bằng variant_id
                if (!$sri && $variantId) {
                    $sql_fallback = "SELECT sri.*, sr.created_at AS receipt_created_at
                            FROM stock_receipt_items sri
                            JOIN stock_receipts sr ON sri.receipt_id = sr.receipt_id
                            WHERE sri.variant_id = :vid AND sr.status = 'confirmed'
                            ORDER BY sr.created_at DESC
                            LIMIT 1";
                    $stmt_fallback = $pdo->prepare($sql_fallback);
                    $stmt_fallback->execute([':vid' => $variantId]);
                    $sri = $stmt_fallback->fetch(PDO::FETCH_ASSOC);
                }
                
                if ($sri) {
                    // điền dữ liệu ưu tiên từ stock_receipt_items
                    $result['variant_id'] = isset($sri['variant_id']) ? (int)$sri['variant_id'] : $result['variant_id'];
                    $result['last_receipt_quantity'] = isset($sri['quantity']) ? (int)$sri['quantity'] : $result['last_receipt_quantity'];
                    $result['last_unit_price'] = isset($sri['unit_price']) && $sri['unit_price'] !== null ? (float)$sri['unit_price'] : $result['last_unit_price'];
                    $result['location_code'] = !empty($sri['location_code']) ? $sri['location_code'] : $result['location_code'];
                    $result['last_receipt_date'] = $sri['receipt_created_at'] ?? $result['last_receipt_date'];

                    // nếu cần thêm thông tin variant/product thì lấy thêm
                    if (!empty($result['variant_id'])) {
                        $vstmt = $pdo->prepare("SELECT pv.*, p.name AS product_name, p.brand, p.type FROM product_variants pv LEFT JOIN products p ON pv.product_id = p.product_id WHERE pv.variant_id = ? LIMIT 1");
                        $vstmt->execute([$result['variant_id']]);
                        $v = $vstmt->fetch(PDO::FETCH_ASSOC);
                        if ($v) {
                            $result['product_id'] = $v['product_id'] ?? $result['product_id'];
                            $result['product_name'] = $v['product_name'] ?? $result['product_name'];
                            $result['brand'] = $v['brand'] ?? null;
                            $result['type'] = $v['type'] ?? null;
                            $result['sku'] = $v['sku'] ?? $result['sku'];
                            $result['color'] = $v['color'] ?? $result['color'];
                            $result['size'] = $v['size'] ?? $result['size'];
                            if (empty($result['last_unit_price']) && isset($v['price'])) $result['last_unit_price'] = (float)$v['price'];
                        }
                    }
                }
            }
        }

        // 2) Nếu chưa có variant, thử tìm trong product_qr_codes bằng raw QR
        if (empty($result['variant_id'])) {
            $stmt = $pdo->prepare("
                SELECT pqr.*, pv.sku, pv.color, pv.size, pv.variant_id, p.name AS product_name, p.brand, p.type, pv.price AS variant_price
                FROM product_qr_codes pqr
                LEFT JOIN product_variants pv ON pqr.variant_id = pv.variant_id
                LEFT JOIN products p ON pqr.product_id = p.product_id
                WHERE pqr.qr_code = :qr OR pqr.qr_code LIKE :qr_like
                LIMIT 1
            ");
            $stmt->execute([':qr' => $raw, ':qr_like' => $raw . '%']);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $result['variant_id'] = $row['variant_id'] ? (int)$row['variant_id'] : $result['variant_id'];
                $result['product_id'] = $row['product_id'] ?? $result['product_id'];
                $result['product_name'] = $row['product_name'] ?? $result['product_name'];
                $result['brand'] = $row['brand'] ?? null;
                $result['type'] = $row['type'] ?? null;
                $result['sku'] = $row['sku'] ?? $result['sku'];
                $result['color'] = $row['color'] ?? $result['color'];
                $result['size'] = $row['size'] ?? $result['size'];
                if (isset($row['variant_price']) && empty($result['last_unit_price'])) $result['last_unit_price'] = (float)$row['variant_price'];
                if (!empty($row['location_code']) && empty($result['location_code'])) $result['location_code'] = $row['location_code'];
            }
        }

        // 3) Tổng tồn kho - SỬ DỤNG CÔNG THỨC TÍNH TỔNG (giống như tính tiền)
        if (!empty($result['variant_id'])) {
            // Lấy warehouse_id từ session nếu có (để chỉ hiển thị tồn kho của warehouse hiện tại)
            $warehouseId = isset($_SESSION['warehouse_id']) ? $_SESSION['warehouse_id'] : null;
            $inventory_quantity = $this->calculateCurrentStock($result['variant_id'], $warehouseId);
            $result['total_inventory_quantity'] = $inventory_quantity;
        }

        // 4) Nếu JSON chứa quantity/location, ghi đè (QR export thường có sẵn)
        if (isset($data['quantity'])) $result['last_receipt_quantity'] = (int)$data['quantity'];
        if (!empty($data['location_code'])) $result['location_code'] = $data['location_code'];

        // chuẩn hóa empty -> null (trừ variant_id và product_id quan trọng)
        foreach ($result as $k => $v) {
            if ($v === '' && !in_array($k, ['variant_id', 'product_id'])) {
                $result[$k] = null;
            }
        }

        // nếu không có variant/product trả null để frontend hiện thông báo
        if (empty($result['variant_id']) && empty($result['product_id'])) return null;

        // --- Thêm compatibility aliases used by frontend ---
        // map old keys to what frontend likely expects
        // LUÔN ưu tiên total_inventory_quantity (tổng tồn kho) thay vì last_receipt_quantity
        if (!isset($result['quantity'])) {
            $result['quantity'] = $result['total_inventory_quantity'] ?? 0;
        }
        if (!isset($result['unit_price'])) {
            $result['unit_price'] = $result['last_unit_price'] ?? null;
        }
        if (!isset($result['location'])) {
            // map frontend 'location' to backend 'location_code'
            $result['location'] = $result['location_code'] ?? null;
        }

        // optional: keep legacy keys as well
        $result['last_receipt_quantity'] = $result['last_receipt_quantity'] ?? $result['quantity'];
        $result['last_unit_price'] = $result['last_unit_price'] ?? $result['unit_price'];
        $result['location_code'] = $result['location_code'] ?? $result['location'];

        return $result;
    }

    /**
     * Kiểm tra QR code có tồn tại không
     * @param int $productId ID sản phẩm
     * @param int $variantId ID variant
     * @return array|null Thông tin QR code nếu tồn tại, null nếu không
     */
    public function getQRCode($productId, $variantId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT pqr.*, pv.sku, pv.color, pv.size
                FROM product_qr_codes pqr
                LEFT JOIN product_variants pv ON pqr.variant_id = pv.variant_id
                WHERE pqr.product_id = ? AND pqr.variant_id = ? AND pqr.is_active = 1
                LIMIT 1
            ");
            $stmt->execute([$productId, $variantId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);

        } catch (Exception $e) {
            error_log("Get QR Code Error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Tạo QR code cho sản phẩm variant
     * @param int $productId ID sản phẩm
     * @param int $variantId ID variant
     * @param int $userId ID người tạo
     * @return array|null Thông tin QR code hoặc null nếu thất bại
     */
    public function generateQRCode($productId, $variantId, $userId) {
        try {
            // Lấy thông tin sản phẩm và variant (bỏ categories vì bảng không tồn tại)
            $stmt = $this->pdo->prepare("
                SELECT p.product_id, p.name as product_name, p.type, p.warehouse_id,
                       pv.variant_id, pv.sku, pv.color, pv.size, pv.price
                FROM products p
                LEFT JOIN product_variants pv ON p.product_id = pv.product_id
                WHERE p.product_id = ? AND pv.variant_id = ?
                LIMIT 1
            ");
            $stmt->execute([$productId, $variantId]);
            $productData = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$productData) {
                error_log("Product or variant not found: product_id=$productId, variant_id=$variantId");
                return null;
            }

            // Lấy thông tin inventory hiện tại
            $stmt = $this->pdo->prepare("
                SELECT COALESCE(SUM(quantity), 0) as current_quantity
                FROM inventory
                WHERE variant_id = ?
            ");
            $stmt->execute([$variantId]);
            $inventoryData = $stmt->fetch(PDO::FETCH_ASSOC);

            // Chuẩn bị dữ liệu cho QR code
            $qrData = [
                'product_id' => $productData['product_id'],
                'product_name' => $productData['product_name'],
                'category' => $productData['type'] ?? 'Unknown', // Dùng type thay cho category
                'variant_id' => $productData['variant_id'],
                'sku' => $productData['sku'],
                'color' => $productData['color'],
                'size' => $productData['size'],
                'current_quantity' => $inventoryData['current_quantity'],
                'unit_price' => $productData['price'],
                'warehouse_id' => $productData['warehouse_id'],
                'created_by' => $userId
            ];

            // Sử dụng QRCodeGenerator để tạo hoặc cập nhật QR code
            return QRCodeGenerator::createOrUpdateProductQR($this->pdo, $qrData);

        } catch (Exception $e) {
            error_log("Generate QR Code Error: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            return null;
        }
    }

    /**
     * Lấy tất cả QR codes của một sản phẩm
     */
    public function getProductQRs($productId) {
        try {
            // QR codes từ bảng product_qr_codes (mới)
            $enhancedQRs = [];
            $stmt = $this->pdo->prepare("
                SELECT 
                    pqr.*,
                    pv.sku,
                    pv.color,
                    pv.size,

                    s.name as supplier_name,
                    w.name as warehouse_name
                FROM product_qr_codes pqr
                LEFT JOIN product_variants pv ON pqr.variant_id = pv.variant_id
                LEFT JOIN suppliers s ON pqr.supplier_id = s.supplier_id
                LEFT JOIN warehouses w ON pqr.warehouse_id = w.warehouse_id
                WHERE pqr.product_id = ? AND pqr.is_active = 1
                ORDER BY pqr.created_at DESC
            ");
            $stmt->execute([$productId]);
            $enhancedQRs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Legacy QR codes removed - all QR now in product_qr_codes
            $legacyQRs = [];
            
            return [
                'enhanced' => $enhancedQRs,
                'legacy' => $legacyQRs
            ];
            
        } catch (Exception $e) {
            error_log("Get Product QRs Error: " . $e->getMessage());
            return ['enhanced' => [], 'legacy' => []];
        }
    }

    /**
     * Tính số lượng tồn kho hiện tại (giống công thức tính tổng tiền)
     * @param int $variantId ID variant
     * @param int|null $warehouseId ID warehouse (nếu null thì lấy tất cả warehouses)
     * @return int Số lượng tồn kho hiện tại
     */
    public function calculateCurrentStock($variantId, $warehouseId = null) {
        // PHƯƠNG PHÁP 1: Từ inventory table (ưu tiên cao nhất)
        $inventory_stock = $this->getStockFromInventory($variantId, $warehouseId);
        if ($inventory_stock > 0) {
            return $inventory_stock;
        }

        // PHƯƠNG PHÁP 2: Tính từ các giao dịch (nhập - xuất)
        $transaction_stock = $this->getStockFromTransactions($variantId, $warehouseId);
        if ($transaction_stock !== null) {
            return $transaction_stock;
        }

        // PHƯƠNG PHÁP 3: Tính từ receipts confirmed (fallback)
        return $this->getStockFromReceipts($variantId, $warehouseId);
    }

    /**
     * Lấy tồn kho từ bảng inventory
     */
    private function getStockFromInventory($variantId, $warehouseId = null) {
        if ($warehouseId !== null) {
            $stmt = $this->pdo->prepare("SELECT COALESCE(SUM(quantity), 0) FROM inventory WHERE variant_id = ? AND warehouse_id = ?");
            $stmt->execute([$variantId, $warehouseId]);
        } else {
            $stmt = $this->pdo->prepare("SELECT COALESCE(SUM(quantity), 0) FROM inventory WHERE variant_id = ?");
            $stmt->execute([$variantId]);
        }
        return (int)$stmt->fetchColumn();
    }

    /**
     * Tính tồn kho từ giao dịch (nhập - xuất) - GIỐNG CÔNG THỨC TÍNH TIỀN
     */
    private function getStockFromTransactions($variantId, $warehouseId = null) {
        // Kiểm tra xem có bảng inventory_transactions không
        try {
            if ($warehouseId !== null) {
                $stmt = $this->pdo->prepare("
                    SELECT 
                        COALESCE(SUM(CASE WHEN transaction_type = 'in' THEN quantity ELSE 0 END), 0) as total_in,
                        COALESCE(SUM(CASE WHEN transaction_type = 'out' THEN quantity ELSE 0 END), 0) as total_out
                    FROM inventory_transactions 
                    WHERE variant_id = ? AND warehouse_id = ?
                ");
                $stmt->execute([$variantId, $warehouseId]);
            } else {
                $stmt = $this->pdo->prepare("
                    SELECT 
                        COALESCE(SUM(CASE WHEN transaction_type = 'in' THEN quantity ELSE 0 END), 0) as total_in,
                        COALESCE(SUM(CASE WHEN transaction_type = 'out' THEN quantity ELSE 0 END), 0) as total_out
                    FROM inventory_transactions 
                    WHERE variant_id = ?
                ");
                $stmt->execute([$variantId]);
            }
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($row) {
                return (int)($row['total_in'] - $row['total_out']);
            }
        } catch (Exception $e) {
            // Bảng inventory_transactions không tồn tại, return null để fallback
            return null;
        }
        
        return null;
    }

    /**
     * Tính tồn kho từ receipts confirmed (phương pháp fallback)
     */
    private function getStockFromReceipts($variantId, $warehouseId = null) {
        if ($warehouseId !== null) {
            $stmt = $this->pdo->prepare("
                SELECT COALESCE(SUM(sri.quantity), 0) 
                FROM stock_receipt_items sri 
                INNER JOIN stock_receipts sr ON sri.receipt_id = sr.receipt_id 
                WHERE sri.variant_id = ? AND sr.status = 'confirmed' AND sr.warehouse_id = ?
            ");
            $stmt->execute([$variantId, $warehouseId]);
        } else {
            $stmt = $this->pdo->prepare("
                SELECT COALESCE(SUM(sri.quantity), 0) 
                FROM stock_receipt_items sri 
                INNER JOIN stock_receipts sr ON sri.receipt_id = sr.receipt_id 
                WHERE sri.variant_id = ? AND sr.status = 'confirmed'
            ");
            $stmt->execute([$variantId]);
        }
        return (int)$stmt->fetchColumn();
    }

    /**
     * Tính tồn kho theo công thức kế toán chuẩn
     * Tồn đầu + Nhập - Xuất = Tồn cuối
     */
    public function calculateStockByAccountingFormula($variantId, $startDate = null, $endDate = null) {
        $pdo = $this->pdo;
        
        // 1. Tồn đầu kỳ (nếu có startDate)
        $opening_stock = 0;
        if ($startDate) {
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(sri.quantity), 0)
                FROM stock_receipt_items sri
                INNER JOIN stock_receipts sr ON sri.receipt_id = sr.receipt_id
                WHERE sri.variant_id = ? AND sr.status = 'confirmed' AND sr.created_at < ?
            ");
            $stmt->execute([$variantId, $startDate]);
            $opening_stock = (int)$stmt->fetchColumn();
        }

        // 2. Nhập trong kỳ
        $inbound = 0;
        $sql_in = "
            SELECT COALESCE(SUM(sri.quantity), 0)
            FROM stock_receipt_items sri
            INNER JOIN stock_receipts sr ON sri.receipt_id = sr.receipt_id
            WHERE sri.variant_id = ? AND sr.status = 'confirmed'
        ";
        $params_in = [$variantId];
        
        if ($startDate && $endDate) {
            $sql_in .= " AND sr.created_at BETWEEN ? AND ?";
            $params_in[] = $startDate;
            $params_in[] = $endDate;
        } elseif ($startDate) {
            $sql_in .= " AND sr.created_at >= ?";
            $params_in[] = $startDate;
        }
        
        $stmt = $pdo->prepare($sql_in);
        $stmt->execute($params_in);
        $inbound = (int)$stmt->fetchColumn();

        // 3. Xuất trong kỳ (tạm thời = 0)
        $outbound = 0;

        // 4. Công thức: Tồn cuối = Tồn đầu + Nhập - Xuất
        $closing_stock = $opening_stock + $inbound - $outbound;

        return [
            'opening_stock' => $opening_stock,
            'inbound' => $inbound,
            'outbound' => $outbound,
            'closing_stock' => $closing_stock,
            'formula' => "$opening_stock + $inbound - $outbound = $closing_stock"
        ];
    }
}
?>
