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
     * Lấy thông tin sản phẩm từ QR code
     * 
     * @param string $qr - Chuỗi QR code (có thể là JSON hoặc UUID)
     * @return array|null - Thông tin sản phẩm hoặc null nếu không tìm thấy
     */
    public function getProductFromQR($qr) {
        $raw = trim($qr);
        
        // Làm sạch và parse JSON
        $clean_qr = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $raw);
        $decoded = json_decode($clean_qr, true);
        $data = (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : [];
        
        // Nếu không phải JSON, thử tìm UUID trong chuỗi
        if (empty($data) && preg_match('/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i', $raw, $matches)) {
            $data['uuid'] = $matches[1];
        }

        $variantId = $data['variant_id'] ?? null;
        $receiptId = $data['receipt_id'] ?? null;

        // Khởi tạo kết quả
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

        // Bước 1: Tìm trong stock_receipt_items nếu có receipt_id hoặc variant_id
        if ($receiptId || $variantId) {
            $sql = "SELECT sri.*, sr.created_at AS receipt_created_at
                    FROM stock_receipt_items sri
                    JOIN stock_receipts sr ON sri.receipt_id = sr.receipt_id
                    WHERE ";
            
            $params = [];
            if ($receiptId && $variantId) {
                $sql .= "sri.receipt_id = ? AND sri.variant_id = ?";
                $params = [$receiptId, $variantId];
            } elseif ($receiptId) {
                $sql .= "sri.receipt_id = ?";
                $params = [$receiptId];
            } else {
                $sql .= "sri.variant_id = ? AND sr.status = 'confirmed'";
                $params = [$variantId];
            }
            
            $sql .= " ORDER BY sr.created_at DESC LIMIT 1";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $sri = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($sri) {
                $result['variant_id'] = (int)$sri['variant_id'];
                $result['last_receipt_quantity'] = (int)$sri['quantity'];
                $result['last_unit_price'] = isset($sri['unit_price']) ? (float)$sri['unit_price'] : null;
                $result['location_code'] = $sri['location_code'] ?? null;
                $result['last_receipt_date'] = $sri['receipt_created_at'];

                // Lấy thông tin chi tiết variant và product
                $vstmt = $this->pdo->prepare("
                    SELECT pv.*, p.name AS product_name, p.brand, p.type 
                    FROM product_variants pv 
                    LEFT JOIN products p ON pv.product_id = p.product_id 
                    WHERE pv.variant_id = ?
                ");
                $vstmt->execute([$result['variant_id']]);
                $v = $vstmt->fetch(PDO::FETCH_ASSOC);
                
                if ($v) {
                    $result['product_id'] = $v['product_id'];
                    $result['product_name'] = $v['product_name'];
                    $result['brand'] = $v['brand'];
                    $result['type'] = $v['type'];
                    $result['sku'] = $v['sku'];
                    $result['color'] = $v['color'];
                    $result['size'] = $v['size'];
                    // Lưu cả giá nhập và giá bán
                    if (!$result['last_unit_price']) {
                        $result['last_unit_price'] = (float)$v['price'];
                    }
                    // Giá bán lấy từ product_variants
                    $result['variant_price'] = (float)$v['price'];
                }
            }
        }

        // Bước 2: Nếu chưa tìm thấy, tìm trong product_qr_codes
        if (empty($result['variant_id'])) {
            $stmt = $this->pdo->prepare("
                SELECT pqr.*, pv.sku, pv.color, pv.size, pv.variant_id, pv.price,
                       p.name AS product_name, p.brand, p.type
                FROM product_qr_codes pqr
                LEFT JOIN product_variants pv ON pqr.variant_id = pv.variant_id
                LEFT JOIN products p ON pqr.product_id = p.product_id
                WHERE pqr.qr_code = ? OR pqr.qr_code LIKE ?
                LIMIT 1
            ");
            $stmt->execute([$raw, $raw . '%']);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($row) {
                $result['variant_id'] = (int)$row['variant_id'];
                $result['product_id'] = $row['product_id'];
                $result['product_name'] = $row['product_name'];
                $result['brand'] = $row['brand'];
                $result['type'] = $row['type'];
                $result['sku'] = $row['sku'];
                $result['color'] = $row['color'];
                $result['size'] = $row['size'];
                if (!$result['last_unit_price']) {
                    $result['last_unit_price'] = (float)$row['price'];
                }
                if (!$result['location_code']) {
                    $result['location_code'] = $row['location_code'];
                }
            }
        }

        // Bước 3: Tính tổng tồn kho hiện tại
        if (!empty($result['variant_id'])) {
            $warehouseId = $_SESSION['warehouse_id'] ?? null;
            $result['total_inventory_quantity'] = $this->calculateCurrentStock($result['variant_id'], $warehouseId);
        }

        // Bước 4: Ghi đè nếu QR chứa dữ liệu quantity/location
        if (isset($data['quantity'])) {
            $result['last_receipt_quantity'] = (int)$data['quantity'];
        }
        if (!empty($data['location_code'])) {
            $result['location_code'] = $data['location_code'];
        }

        // Chuẩn hóa empty string thành null
        foreach ($result as $k => $v) {
            if ($v === '' && !in_array($k, ['variant_id', 'product_id'])) {
                $result[$k] = null;
            }
        }

        // Trả null nếu không tìm thấy sản phẩm
        if (empty($result['variant_id']) && empty($result['product_id'])) {
            return null;
        }

        // Thêm các trường tương thích với frontend
        $result['quantity'] = $result['total_inventory_quantity'] ?? 0;
        $result['unit_price'] = $result['last_unit_price']; // Giá nhập
        // Giá bán lấy từ variant_price (product_variants.price), không phải unit_price (giá nhập)
        $result['price'] = isset($result['variant_price']) ? $result['variant_price'] : $result['last_unit_price'];
        $result['location'] = $result['location_code'];

        return $result;
    }

    /**
     * Kiểm tra QR code có tồn tại không
     * 
     * @param int $productId - ID sản phẩm
     * @param int $variantId - ID variant
     * @return array|null - Thông tin QR code hoặc null nếu không tồn tại
     */
    public function getQRCode($productId, $variantId) {
        $stmt = $this->pdo->prepare("
            SELECT pqr.*, pv.sku, pv.color, pv.size
            FROM product_qr_codes pqr
            LEFT JOIN product_variants pv ON pqr.variant_id = pv.variant_id
            WHERE pqr.product_id = ? AND pqr.variant_id = ? AND pqr.is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$productId, $variantId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Tạo QR code cho sản phẩm variant
     * 
     * @param int $productId - ID sản phẩm
     * @param int $variantId - ID variant
     * @param int $userId - ID người tạo
     * @return array|null - Thông tin QR code hoặc null nếu thất bại
     */
    public function generateQRCode($productId, $variantId, $userId) {
        // Lấy thông tin sản phẩm và variant
        $stmt = $this->pdo->prepare("
            SELECT p.product_id, p.name as product_name, p.type, p.warehouse_id,
                   pv.variant_id, pv.sku, pv.color, pv.size, pv.price
            FROM products p
            LEFT JOIN product_variants pv ON p.product_id = pv.product_id
            WHERE p.product_id = ? AND pv.variant_id = ?
        ");
        $stmt->execute([$productId, $variantId]);
        $productData = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$productData) {
            return null;
        }

        // Lấy số lượng tồn kho hiện tại
        $currentQuantity = $this->calculateCurrentStock($variantId, $productData['warehouse_id']);

        // Chuẩn bị dữ liệu cho QR code
        $qrData = [
            'product_id' => $productData['product_id'],
            'product_name' => $productData['product_name'],
            'category' => $productData['type'] ?? 'Unknown',
            'variant_id' => $productData['variant_id'],
            'sku' => $productData['sku'],
            'color' => $productData['color'],
            'size' => $productData['size'],
            'current_quantity' => $currentQuantity,
            'unit_price' => $productData['price'],
            'warehouse_id' => $productData['warehouse_id'],
            'created_by' => $userId
        ];

        return QRCodeGenerator::createOrUpdateProductQR($this->pdo, $qrData);
    }

    /**
     * Lấy tất cả QR codes của một sản phẩm
     * 
     * @param int $productId - ID sản phẩm
     * @return array - Danh sách QR codes
     */
    public function getProductQRs($productId) {
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
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Tính số lượng tồn kho hiện tại
     * Thử 3 phương pháp theo thứ tự ưu tiên
     * 
     * @param int $variantId - ID variant
     * @param int|null $warehouseId - ID kho (null = tất cả kho)
     * @return int - Số lượng tồn kho
     */
    public function calculateCurrentStock($variantId, $warehouseId = null) {
        // Phương pháp 1: Từ bảng inventory (ưu tiên cao nhất)
        $stock = $this->getStockFromInventory($variantId, $warehouseId);
        if ($stock > 0) return $stock;

        // Phương pháp 2: Từ inventory_transactions (nếu có)
        $stock = $this->getStockFromTransactions($variantId, $warehouseId);
        if ($stock !== null) return $stock;

        // Phương pháp 3: Từ stock_receipts (fallback)
        return $this->getStockFromReceipts($variantId, $warehouseId);
    }

    /**
     * Lấy tồn kho từ bảng inventory
     */
    private function getStockFromInventory($variantId, $warehouseId = null) {
        $sql = "SELECT COALESCE(SUM(quantity), 0) FROM inventory WHERE variant_id = ?";
        $params = [$variantId];
        
        if ($warehouseId !== null) {
            $sql .= " AND warehouse_id = ?";
            $params[] = $warehouseId;
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Tính tồn kho từ giao dịch (nhập - xuất)
     */
    private function getStockFromTransactions($variantId, $warehouseId = null) {
        try {
            $sql = "SELECT 
                        COALESCE(SUM(CASE WHEN transaction_type = 'in' THEN quantity ELSE 0 END), 0) as total_in,
                        COALESCE(SUM(CASE WHEN transaction_type = 'out' THEN quantity ELSE 0 END), 0) as total_out
                    FROM inventory_transactions 
                    WHERE variant_id = ?";
            $params = [$variantId];
            
            if ($warehouseId !== null) {
                $sql .= " AND warehouse_id = ?";
                $params[] = $warehouseId;
            }
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $row ? (int)($row['total_in'] - $row['total_out']) : null;
        } catch (Exception $e) {
            return null; // Bảng không tồn tại
        }
    }

    /**
     * Tính tồn kho từ phiếu nhập đã xác nhận
     */
    private function getStockFromReceipts($variantId, $warehouseId = null) {
        $sql = "SELECT COALESCE(SUM(sri.quantity), 0) 
                FROM stock_receipt_items sri 
                INNER JOIN stock_receipts sr ON sri.receipt_id = sr.receipt_id 
                WHERE sri.variant_id = ? AND sr.status = 'confirmed'";
        $params = [$variantId];
        
        if ($warehouseId !== null) {
            $sql .= " AND sr.warehouse_id = ?";
            $params[] = $warehouseId;
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }
}
?>