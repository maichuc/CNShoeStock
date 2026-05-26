<?php
// Suppress all PHP errors and warnings for clean JSON output
error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json');

// session_start();

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../app/Classes/TaoMaQR.php';
require_once __DIR__ . '/../../app/Classes/TaoMaQRDonGian.php';

// Basic error handler
function handleError($message) {
    echo json_encode(['success' => false, 'message' => $message]);
    exit();
}

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    // Auto login for testing
    $_SESSION['user_id'] = 1;
    $_SESSION['role'] = 'admin';
    $_SESSION['username'] = 'admin_test';
}

$database = new Database();
$pdo = $database->getConnection();

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? 'admin';

// Lấy dữ liệu POST
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

try {
    switch ($action) {
        case 'test_connection':
            echo json_encode(['success' => true, 'message' => 'API hoạt động bình thường']);
            break;
            
        case 'get_draft_receipts':
            echo json_encode(getDraftReceipts($pdo));
            break;
            
        case 'get_receipt_details':
            echo json_encode(getReceiptDetails($pdo, $input['receipt_id'] ?? 0));
            break;
            
        case 'update_receipt_item':
            echo json_encode(updateReceiptItem($pdo, $input, $userId));
            break;
            
        case 'confirm_receipt':
            if ($userRole !== 'admin' && $userRole !== 'manager') {
                handleError('Không có quyền xác nhận phiếu');
            }
            echo json_encode(confirmReceiptSimple($pdo, $input, $userId));
            break;
            
        case 'generate_item_qr':
            echo json_encode(generateItemQR($input));
            break;
            
        case 'get_receipt_qrs':
            echo json_encode(getReceiptQRs($pdo, $input['receipt_id'] ?? 0));
            break;
            
        case 'get_suppliers':
            echo json_encode(getSuppliers($pdo));
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Action không hợp lệ: ' . $action]);
    }
} catch (Exception $e) {
    error_log("API Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Có lỗi xảy ra: ' . $e->getMessage()]);
}

/**
 * Lấy danh sách phiếu nhập ở trạng thái bản nháp
 */
function getDraftReceipts($pdo) {
    try {
        $sql = "SELECT sr.receipt_id, 
                       CONCAT('RC-', LPAD(sr.receipt_id, 6, '0')) as receipt_code,
                       sr.created_at, sr.notes,
                       COALESCE(s.name, 'N/A') as supplier_name, 
                       COALESCE(w.name, 'N/A') as warehouse_name,
                       COALESCE(u.full_name, u.username, 'N/A') as created_by,
                       COUNT(sri.receipt_item_id) as total_items,
                       COALESCE(SUM(sri.quantity * sri.unit_price), 0) as total_value
                FROM stock_receipts sr
                LEFT JOIN suppliers s ON sr.supplier_id = s.supplier_id
                LEFT JOIN warehouses w ON sr.warehouse_id = w.warehouse_id
                LEFT JOIN users u ON sr.user_id = u.user_id
                LEFT JOIN stock_receipt_items sri ON sr.receipt_id = sri.receipt_id
                WHERE sr.status = 'draft'
                GROUP BY sr.receipt_id
                ORDER BY sr.created_at DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $receipts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'success' => true,
            'receipts' => $receipts
        ];
        
    } catch (Exception $e) {
        error_log("Get pending receipts error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Lỗi khi tải danh sách phiếu nhập: ' . $e->getMessage()];
    }
}

/**
 * Hiển thị thông tin chi tiết phiếu nhập
 */
function getReceiptDetails($pdo, $receiptId) {
    try {
        if (!$receiptId) {
            return ['success' => false, 'message' => 'Thiếu receipt_id'];
        }
        
        // Lấy thông tin phiếu nhập
    $sql = "SELECT sr.*, 
               COALESCE(s.name, 'N/A') as supplier_name, 
               COALESCE(s.phone, '') as supplier_phone,
               COALESCE(w.name, 'N/A') as warehouse_name, 
               COALESCE(w.address, '') as warehouse_address,
               COALESCE(u.full_name, u.username, 'N/A') as created_by,
               u.employee_code as created_by_code,
               COALESCE(u2.full_name, u2.username) as confirmed_by_name,
               u2.employee_code as confirmed_by_code
        FROM stock_receipts sr
        LEFT JOIN suppliers s ON sr.supplier_id = s.supplier_id
        LEFT JOIN warehouses w ON sr.warehouse_id = w.warehouse_id
        LEFT JOIN users u ON sr.user_id = u.user_id
        LEFT JOIN users u2 ON sr.confirmed_by = u2.user_id
        WHERE sr.receipt_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$receiptId]);
        $receipt = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$receipt) {
            return ['success' => false, 'message' => 'Không tìm thấy phiếu nhập'];
        }
        // Gộp tên nhân viên và mã nhân viên
        if (!empty($receipt['created_by_code'])) {
            $receipt['created_by_full'] = $receipt['created_by'] . ' (' . $receipt['created_by_code'] . ')';
        } else {
            $receipt['created_by_full'] = $receipt['created_by'];
        }
        // Gộp tên người xác nhận và mã nhân viên
        if (!empty($receipt['confirmed_by_name'])) {
            if (!empty($receipt['confirmed_by_code'])) {
                $receipt['confirmed_by_name'] = $receipt['confirmed_by_name'] . ' (' . $receipt['confirmed_by_code'] . ')';
            }
        }
        // Bổ sung mã phiếu (receipt_code)
        $receipt['receipt_code'] = 'RC-' . str_pad($receiptId, 6, '0', STR_PAD_LEFT);
        
        // Lấy danh sách sản phẩm với thông tin chi tiết
        $sql = "SELECT MIN(sri.receipt_item_id) as item_id,
                       sri.receipt_id,
                       sri.variant_id,
                       SUM(sri.quantity) as quantity_expected, 
                       SUM(sri.quantity) as quantity_received,
                       MAX(sri.unit_price) as unit_price,
                       MAX(sri.location_code) as location_code,
                       COALESCE(p.product_id, 0) as product_id,
                       COALESCE(p.name, 'N/A') as product_name, 
                       COALESCE(p.description, '') as description,
                       CONCAT(COALESCE(pv.color, ''), 
                              CASE WHEN pv.color IS NOT NULL AND pv.size IS NOT NULL THEN ' - ' ELSE '' END,
                              COALESCE(pv.size, '')) as variant_name,
                       COALESCE(pv.sku, '') as sku, 
                       COALESCE(pv.color, '') as color, 
                       COALESCE(pv.size, '') as size, 
                       COALESCE(pv.price, 0) as current_price,
                       MAX(sri.location_code) as location_code_name, 
                       CONCAT('Vị trí: ', MAX(sri.location_code)) as location_name
                FROM stock_receipt_items sri
                LEFT JOIN product_variants pv ON sri.variant_id = pv.variant_id
                LEFT JOIN products p ON pv.product_id = p.product_id
                WHERE sri.receipt_id = ?
                GROUP BY sri.receipt_id, sri.variant_id, p.product_id, p.name, p.description, pv.sku, pv.color, pv.size, pv.price
                ORDER BY p.name, pv.color, pv.size";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$receiptId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'success' => true,
            'receipt' => $receipt,
            'items' => $items,
            'can_edit' => $receipt['status'] === 'pending'
        ];
        
    } catch (Exception $e) {
        error_log("Get receipt details error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Lỗi khi tải chi tiết phiếu nhập: ' . $e->getMessage()];
    }
}

/**
 * Xác nhận phiếu nhập (simplified version)
 */
function confirmReceiptSimple($pdo, $data, $userId) {
    try {
        $receiptId = $data['receipt_id'] ?? 0;
        
        if (!$receiptId) {
            return ['success' => false, 'message' => 'Thiếu receipt_id'];
        }
        
        // Kiểm tra phiếu nhập
        $sql = "SELECT * FROM stock_receipts WHERE receipt_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$receiptId]);
        $receipt = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$receipt) {
            return ['success' => false, 'message' => 'Không tìm thấy phiếu nhập'];
        }
        
        if ($receipt['status'] === 'confirmed') {
            return ['success' => false, 'message' => 'Phiếu đã được xác nhận trước đó'];
        }
        
        $pdo->beginTransaction();
        
        // Cập nhật trạng thái phiếu nhập
        $sql = "UPDATE stock_receipts SET 
                status = 'confirmed', 
                confirmed_at = NOW(), 
                confirmed_by = ? 
                WHERE receipt_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId, $receiptId]);
        
        // Lấy danh sách items để cập nhật inventory
        $sql = "SELECT sri.*, pv.product_id, p.name as product_name, pv.sku, pv.color, pv.size
                FROM stock_receipt_items sri
                LEFT JOIN product_variants pv ON sri.variant_id = pv.variant_id
                LEFT JOIN products p ON pv.product_id = p.product_id
                WHERE sri.receipt_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$receiptId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($items as $item) {
            // Cập nhật inventory
            updateInventorySimple($pdo, $item, $receipt['warehouse_id']);

            // Tạo QR code cho item
            try {
                $uuid = SimpleQRGenerator::generateUUID();
                $qrData = json_encode([
                    'type' => 'stock_receipt_item',
                    'receipt_id' => $receiptId,
                    'receipt_code' => 'RC-' . str_pad($receiptId, 6, '0', STR_PAD_LEFT),
                    'item_id' => $item['receipt_item_id'],
                    'variant_id' => $item['variant_id'],
                    'product_name' => $item['product_name'],
                    'sku' => $item['sku'],
                    'color' => $item['color'],
                    'size' => $item['size'],
                    'quantity' => $item['quantity'],
                    'location_code' => $item['location_code'],
                    'confirmed_at' => date('Y-m-d H:i:s'),
                    'uuid' => $uuid
                ], JSON_UNESCAPED_UNICODE);
                
                // Tạo QR code file
                $qrDir = __DIR__ . '/../../public/uploads/qr/';
                if (!is_dir($qrDir)) {
                    mkdir($qrDir, 0777, true);
                }
                
                $qrFilename = 'qr_receipt_' . $receiptId . '_item_' . $item['receipt_item_id'] . '_' . time() . '.png';
                $qrFilePath = $qrDir . $qrFilename;
                $qrRelativePath = 'uploads/qr/' . $qrFilename;
                
                // Tạo QR code file
                if (SimpleQRGenerator::generateQRCode($qrData, $qrFilePath)) {
                    error_log("QR code generated at: $qrFilePath");
                    
                    // Lưu vào database product_qr_codes
                    $insertQrSql = "INSERT INTO product_qr_codes 
                                   (qr_code, variant_id, product_id, warehouse_id, location_id, 
                                    qr_image_path, is_active, created_by, created_at) 
                                   VALUES (?, ?, ?, ?, ?, ?, 1, ?, NOW())";
                    
                    $qrStmt = $pdo->prepare($insertQrSql);
                    $qrStmt->execute([
                        $uuid,  // qr_code is the UUID
                        $item['variant_id'],
                        $item['product_id'],
                        $receipt['warehouse_id'],
                        null,  // location_id - can be NULL
                        $qrRelativePath,
                        $userId
                    ]);
                    
                    error_log("QR code saved to database for variant_id: {$item['variant_id']}");
                } else {
                    error_log("QR code generation failed for item {$item['receipt_item_id']}");
                }
                
            } catch (Exception $qrError) {
                error_log("QR generation error for item " . $item['receipt_item_id'] . ": " . $qrError->getMessage());
                error_log("Stack trace: " . $qrError->getTraceAsString());
                // Continue without QR - không làm fail toàn bộ process
            }
        }
        
        $pdo->commit();
        
        return [
            'success' => true,
            'message' => 'Đã xác nhận phiếu nhập thành công',
            'receipt_code' => 'RC-' . str_pad($receiptId, 6, '0', STR_PAD_LEFT)
        ];
        
    } catch (Exception $e) {
        $pdo->rollback();
        error_log("Confirm receipt error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Lỗi khi xác nhận phiếu: ' . $e->getMessage()];
    }
}

/**
 * Cập nhật tồn kho (simplified)
 */
function updateInventorySimple($pdo, $item, $warehouseId) {
    try {
        error_log("updateInventorySimple: variant_id=" . $item['variant_id'] . ", warehouse_id=" . $warehouseId . ", quantity=" . $item['quantity']);
        
        // Sử dụng INSERT ... ON DUPLICATE KEY UPDATE để tránh lỗi duplicate
        // Nếu đã tồn tại (variant_id, warehouse_id) thì UPDATE, nếu chưa thì INSERT
        $sql = "INSERT INTO inventory (variant_id, warehouse_id, quantity, updated_at) 
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE 
                    quantity = quantity + VALUES(quantity),
                    updated_at = NOW()";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$item['variant_id'], $warehouseId, $item['quantity']]);
        
        if ($stmt->rowCount() > 0) {
            error_log("Inventory updated successfully for variant_id=" . $item['variant_id']);
        } else {
            error_log("No inventory changes for variant_id=" . $item['variant_id']);
        }
    } catch (Exception $e) {
        error_log("Update inventory error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        throw $e; // Throw để hiển thị lỗi cho user
    }
}

/**
 * Tạo QR code cho một item
 */
function generateItemQR($input) {
    try {
        $itemData = [
            'item_id' => $input['item_id'] ?? '',
            'receipt_id' => $input['receipt_id'] ?? '',
            'variant_id' => $input['variant_id'] ?? '',
            'product_name' => $input['product_name'] ?? '',
            'sku' => $input['sku'] ?? '',
            'location' => $input['location_code'] ?? '',
            'uuid' => QRCodeGenerator::generateUUID()
        ];
        
        $qrUrl = QRCodeGenerator::generateProductQR($itemData);
        
        return [
            'success' => true,
            'qr_url' => $qrUrl,
            'qr_data' => $itemData
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Lỗi tạo QR: ' . $e->getMessage()];
    }
}

/**
 * Lấy danh sách QR codes cho một receipt
 */
function getReceiptQRs($pdo, $receiptId) {
    try {
        if (!$receiptId) {
            return ['success' => false, 'message' => 'Thiếu receipt_id'];
        }
        
        // Lấy receipt details
        $receiptDetails = getReceiptDetails($pdo, $receiptId);
        if (!$receiptDetails['success']) {
            return $receiptDetails;
        }
        
        $items = $receiptDetails['items'];
        $warehouseId = isset($receiptDetails['receipt']['warehouse_id']) ? (int)$receiptDetails['receipt']['warehouse_id'] : null;
        $qrItems = [];
        
        foreach ($items as $item) {
            // Lấy QR code từ database - ưu tiên file path local trước
            $stmt = $pdo->prepare("
                SELECT qr_id, qr_code, qr_image_path, warehouse_id
                FROM product_qr_codes 
                WHERE variant_id = ? 
                AND is_active = 1
                ORDER BY 
                    CASE 
                        WHEN qr_image_path LIKE 'uploads/%' THEN 0 
                        ELSE 1 
                    END,
                    created_at DESC 
                LIMIT 1
            ");
            $stmt->execute([$item['variant_id']]);
            $qrData = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Xử lý QR URL
            $qrUrl = null;
            $uuid = null;
            
            if ($qrData) {
                // Có QR code trong database
                $qrUrl = $qrData['qr_image_path'];
                
                // Parse UUID từ qr_code JSON hoặc trực tiếp từ qr_code nếu là UUID
                if (preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $qrData['qr_code'], $matches)) {
                    $uuid = $matches[0];
                } else {
                    // Nếu qr_code là JSON, parse để lấy UUID
                    $qrCodeData = json_decode($qrData['qr_code'], true);
                    $uuid = $qrCodeData['uuid'] ?? 'QR-' . $qrData['qr_id'];
                }
            } else {
                // Chưa có QR code - có thể tạo động hoặc để trống
                $uuid = 'NO-QR-' . $item['variant_id'];
            }
            
            $qrItems[] = [
                'item_id' => $item['item_id'] ?? $item['variant_id'],
                'product_name' => $item['product_name'],
                'variant_name' => $item['variant_name'],
                'sku' => $item['sku'],
                'quantity' => $item['quantity_expected'] ?? $item['quantity'],
                'location_code' => $item['location_code'] ?? '',
                'qr_url_full' => $qrUrl,
                'qr_url_simple' => $qrUrl,
                'qr_url' => $qrUrl,
                'qr_image_path' => $qrUrl,
                'uuid' => $uuid,
                'has_qr' => !empty($qrUrl)
            ];
        }
        
        return [
            'success' => true,
            'receipt' => $receiptDetails['receipt'],
            'qr_items' => $qrItems,
            'total_items' => count($qrItems)
        ];
        
    } catch (Exception $e) {
        error_log("getReceiptQRs error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Lỗi lấy QR codes: ' . $e->getMessage()];
    }
}

/**
 * Cập nhật thông tin item trong phiếu nhập
 */
function updateReceiptItem($pdo, $data, $userId) {
    try {
        $receiptId = $data['receipt_id'] ?? 0;
        $itemId = $data['item_id'] ?? 0;
        $quantity = $data['quantity'] ?? 0;
        $unitPrice = $data['unit_price'] ?? 0;
        $locationCode = $data['location_code'] ?? '';

        if (!$receiptId || !$itemId) {
            return ['success' => false, 'message' => 'Thiếu thông tin receipt_id hoặc item_id'];
        }

        if ($quantity <= 0) {
            return ['success' => false, 'message' => 'Số lượng phải lớn hơn 0'];
        }

        if ($unitPrice < 0) {
            return ['success' => false, 'message' => 'Giá không được âm'];
        }

        // Kiểm tra quyền chỉnh sửa - chỉ cho phép chỉnh sửa phiếu của mình và ở trạng thái pending
        $stmt = $pdo->prepare("
            SELECT sr.status, sr.user_id 
            FROM stock_receipts sr 
            INNER JOIN stock_receipt_items sri ON sr.receipt_id = sri.receipt_id 
            WHERE sri.receipt_item_id = ? AND sr.receipt_id = ?
        ");
        $stmt->execute([$itemId, $receiptId]);
        $receipt = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$receipt) {
            return ['success' => false, 'message' => 'Không tìm thấy item trong phiếu nhập'];
        }

        if ($receipt['user_id'] != $userId) {
            return ['success' => false, 'message' => 'Không có quyền chỉnh sửa phiếu nhập này'];
        }

        if ($receipt['status'] !== 'pending') {
            return ['success' => false, 'message' => 'Chỉ có thể chỉnh sửa phiếu nhập ở trạng thái bản nháp'];
        }

        // Cập nhật thông tin item
        $stmt = $pdo->prepare("
            UPDATE stock_receipt_items 
            SET quantity = ?, unit_price = ?, location_code = ? 
            WHERE receipt_item_id = ?
        ");
        $stmt->execute([$quantity, $unitPrice, $locationCode, $itemId]);

        // rowCount() = 0 means no actual changes were made (same values), but this is still a success
        // Only fail if there was an actual error or the item doesn't exist
        return [
            'success' => true, 
            'message' => 'Đã cập nhật thông tin sản phẩm thành công',
            'item_id' => $itemId,
            'rows_affected' => $stmt->rowCount()
        ];

    } catch (Exception $e) {
        error_log("Update receipt item error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Lỗi khi cập nhật sản phẩm: ' . $e->getMessage()];
    }
}

/**
 * Lấy danh sách nhà cung cấp
 */
function getSuppliers($pdo) {
    try {
        $warehouseId = $_SESSION['warehouse_id'] ?? null;
        
        if ($warehouseId) {
            // Lấy nhà cung cấp theo warehouse
            $stmt = $pdo->prepare("
                SELECT supplier_id, name, contact_person, phone, email, address 
                FROM suppliers 
                WHERE warehouse_id = ? OR warehouse_id IS NULL
                ORDER BY name ASC
            ");
            $stmt->execute([$warehouseId]);
        } else {
            // Lấy tất cả nhà cung cấp
            $stmt = $pdo->prepare("
                SELECT supplier_id, name, contact_person, phone, email, address 
                FROM suppliers 
                ORDER BY name ASC
            ");
            $stmt->execute();
        }
        
        $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'success' => true,
            'suppliers' => $suppliers
        ];
        
    } catch (Exception $e) {
        error_log("Get suppliers error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Lỗi khi lấy danh sách nhà cung cấp: ' . $e->getMessage()];
    }
}
?>
