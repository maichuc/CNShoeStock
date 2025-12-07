<?php
// Tắt hiển thị lỗi để tránh làm hỏng JSON response
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Bắt đầu output buffering để tránh output không mong muốn
ob_start();

session_start();
header('Content-Type: application/json');

try {
    require_once 'config/cau_hinh_csdl.php';
    require_once 'classes/LichSuPhieuNhap.php';
    require_once 'classes/QuanLyMaQR.php';
    require_once 'classes/TaoMaQR.php';
} catch (Exception $e) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Lỗi load class: ' . $e->getMessage()]);
    exit();
}

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    ob_end_clean();
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Chưa đăng nhập']);
    exit();
}

try {
    $database = new Database();
    $pdo = $database->getConnection();
    $historyManager = new StockReceiptHistory($pdo);
    $qrManager = new QRCodeManager($pdo);
} catch (Exception $e) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Lỗi khởi tạo: ' . $e->getMessage()]);
    exit();
}

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? 'employee';

// Lấy dữ liệu POST
$rawInput = file_get_contents('php://input');
error_log("Raw input: " . $rawInput);
$input = json_decode($rawInput, true);
// Nếu không phải JSON, lấy từ $_POST
if (json_last_error() !== JSON_ERROR_NONE) {
    $input = $_POST;
    error_log("Using POST data: " . print_r($_POST, true));
}
$action = $input['action'] ?? '';
error_log("Action received: " . $action);

try {
    switch ($action) {
        case 'get_draft_receipts':
            ob_end_clean();
            echo json_encode(getDraftReceipts($pdo));
            break;
            
        case 'get_receipt_details':
            ob_end_clean();
            echo json_encode(getReceiptDetails($pdo, $input['receipt_id'] ?? 0));
            break;
            
        case 'update_receipt_draft':
        case 'update_draft':
            ob_end_clean();
            echo json_encode(updateReceiptDraft($pdo, $historyManager, $input, $userId));
            break;
            
        case 'confirm_receipt':
            // Chỉ quản lý mới có quyền xác nhận
            if ($userRole !== 'admin' && $userRole !== 'manager') {
                ob_end_clean();
                echo json_encode(['success' => false, 'message' => 'Không có quyền xác nhận phiếu']);
                exit();
            }
            ob_end_clean();
            echo json_encode(confirmReceipt($pdo, $historyManager, $qrManager, $input, $userId));
            break;
            
        case 'delete':
            ob_end_clean();
            echo json_encode(deleteReceipt($pdo, $historyManager, $input['receipt_id'] ?? 0, $userId));
            break;
            
        case 'get_receipt_history':
            ob_end_clean();
            echo json_encode(getReceiptHistory($historyManager, $input['receipt_id'] ?? 0));
            break;
            
        default:
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'Action không hợp lệ']);
    }
} catch (Exception $e) {
    ob_end_clean();
    error_log("API Error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    echo json_encode([
        'success' => false, 
        'message' => 'Có lỗi xảy ra: ' . $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine()
    ]);
}

/**
 * UC_NK_XN - Bước 1: Lấy danh sách phiếu nhập ở trạng thái lưu tạm
 */
function getDraftReceipts($pdo) {
    try {
        $sql = "SELECT sr.receipt_id, sr.receipt_code, sr.created_at, sr.notes,
                       s.name as supplier_name, w.name as warehouse_name,
                       u.full_name as created_by,
                       COUNT(sri.item_id) as total_items,
                       SUM(sri.quantity * sri.unit_price) as total_value
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
        error_log("Get draft receipts error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Lỗi khi tải danh sách phiếu nhập'];
    }
}

/**
 * UC_NK_XN - Bước 2: Hiển thị thông tin chi tiết phiếu nhập
 */
function getReceiptDetails($pdo, $receiptId) {
    try {
        if (!$receiptId) {
            return ['success' => false, 'message' => 'Thiếu receipt_id'];
        }
        
        // Lấy thông tin phiếu nhập
        $sql = "SELECT sr.*, s.name as supplier_name, s.phone as supplier_phone,
                       w.name as warehouse_name, w.address as warehouse_address,
                       u.full_name as created_by
                FROM stock_receipts sr
                LEFT JOIN suppliers s ON sr.supplier_id = s.supplier_id
                LEFT JOIN warehouses w ON sr.warehouse_id = w.warehouse_id
                LEFT JOIN users u ON sr.user_id = u.user_id
                WHERE sr.receipt_id = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$receiptId]);
        $receipt = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$receipt) {
            return ['success' => false, 'message' => 'Không tìm thấy phiếu nhập'];
        }
        
        // Lấy danh sách sản phẩm với thông tin chi tiết
        $sql = "SELECT sri.*, p.product_id, p.name as product_name, p.description,
                       CONCAT(COALESCE(pv.color, ''), 
                              CASE WHEN pv.color IS NOT NULL AND pv.size IS NOT NULL THEN ' - ' ELSE '' END,
                              COALESCE(pv.size, '')) as variant_name,
                       pv.sku, pv.color, pv.size, pv.price as current_price,
                       l.location_code, l.location_name,
                       qr.qr_code, qr.qr_image_path
                FROM stock_receipt_items sri
                LEFT JOIN product_variants pv ON sri.variant_id = pv.variant_id
                LEFT JOIN products p ON pv.product_id = p.product_id
                LEFT JOIN locations l ON sri.location_code = l.location_code
                LEFT JOIN product_qr_codes qr ON (qr.product_id = p.product_id AND qr.variant_id = pv.variant_id AND qr.is_active = 1)
                WHERE sri.receipt_id = ?
                ORDER BY p.name, pv.color, pv.size";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$receiptId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'success' => true,
            'receipt' => $receipt,
            'items' => $items,
            'can_edit' => $receipt['status'] === 'draft'
        ];
        
    } catch (Exception $e) {
        error_log("Get receipt details error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Lỗi khi tải chi tiết phiếu nhập'];
    }
}

/**
 * UC_NK_XN - Bước 3: Cập nhật thông tin phiếu nhập (nếu cần chỉnh sửa)
 */
function updateReceiptDraft($pdo, $historyManager, $data, $userId) {
    try {
        // Log input data
        error_log("updateReceiptDraft called with data: " . print_r($data, true));
        error_log("updateReceiptDraft userId: " . $userId);
        
        $receiptId = $data['receipt_id'] ?? 0;
        
        if (!$receiptId) {
            return ['success' => false, 'message' => 'Thiếu receipt_id'];
        }
        
        // Lấy warehouse_id từ session
        $warehouseId = $_SESSION['warehouse_id'] ?? null;
        error_log("updateReceiptDraft warehouseId: " . $warehouseId);
        
        if (!$warehouseId) {
            return ['success' => false, 'message' => 'Không xác định được kho hàng'];
        }
        
        // Kiểm tra phiếu có thể chỉnh sửa không (chỉ trong warehouse của user)
        $sql = "SELECT * FROM stock_receipts WHERE receipt_id = ? AND status = 'draft' AND warehouse_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$receiptId, $warehouseId]);
        $receipt = $stmt->fetch(PDO::FETCH_ASSOC);
        
        error_log("updateReceiptDraft found receipt: " . print_r($receipt, true));
        
        if (!$receipt) {
            // Kiểm tra xem phiếu có tồn tại không và trạng thái là gì
            $checkSql = "SELECT receipt_id, status, warehouse_id FROM stock_receipts WHERE receipt_id = ?";
            $checkStmt = $pdo->prepare($checkSql);
            $checkStmt->execute([$receiptId]);
            $checkReceipt = $checkStmt->fetch(PDO::FETCH_ASSOC);
            error_log("updateReceiptDraft check receipt: " . print_r($checkReceipt, true));
            
            if (!$checkReceipt) {
                return ['success' => false, 'message' => 'Không tìm thấy phiếu nhập'];
            }
            if ($checkReceipt['warehouse_id'] != $warehouseId) {
                return ['success' => false, 'message' => 'Phiếu nhập không thuộc kho của bạn'];
            }
            if ($checkReceipt['status'] != 'draft') {
                return ['success' => false, 'message' => 'Chỉ có thể chỉnh sửa phiếu ở trạng thái bản nháp. Trạng thái hiện tại: ' . $checkReceipt['status']];
            }
            return ['success' => false, 'message' => 'Không thể chỉnh sửa phiếu này'];
        }
        
        $pdo->beginTransaction();
        
        // Lưu trạng thái cũ
        $oldData = json_encode($receipt);
        
        // Cập nhật thông tin phiếu nhập
        if (isset($data['supplier_id']) || isset($data['notes'])) {
            $sql = "UPDATE stock_receipts SET supplier_id = ?, notes = ?, last_modified_by = ?, last_modified_at = NOW() WHERE receipt_id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $data['supplier_id'] ?? $receipt['supplier_id'],
                $data['notes'] ?? $receipt['notes'],
                $userId,
                $receiptId
            ]);
        }
        
        // Cập nhật items nếu có
        $items = $data['items'] ?? null;
        
        // Nếu items là string, parse nó thành array
        if (is_string($items)) {
            $items = json_decode($items, true);
            error_log("Items parsed from JSON string: " . print_r($items, true));
        }
        
        if ($items && is_array($items) && count($items) > 0) {
            // Xóa items cũ
            $sql = "DELETE FROM stock_receipt_items WHERE receipt_id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$receiptId]);
            error_log("Deleted old items for receipt: " . $receiptId);
            
            // Thêm items mới
            foreach ($items as $item) {
                error_log("Inserting item: " . print_r($item, true));
                $sql = "INSERT INTO stock_receipt_items (receipt_id, variant_id, quantity, unit_price, location_code) 
                        VALUES (?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $receiptId,
                    $item['variant_id'],
                    $item['quantity'],
                    $item['unit_price'],
                    $item['location_code'] ?? null
                ]);
            }
            error_log("Inserted " . count($items) . " items");
        }
        
        // Ghi lịch sử
        $newData = json_encode($data);
        $historyManager->logChange($receiptId, $userId, 'update', 'receipt_details', $oldData, $newData, 'Chỉnh sửa phiếu nhập trước khi xác nhận');
        
        $pdo->commit();
        
        return [
            'success' => true,
            'message' => 'Đã cập nhật phiếu nhập thành công'
        ];
        
    } catch (Exception $e) {
        $pdo->rollback();
        error_log("Update receipt draft error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Lỗi khi cập nhật phiếu nhập'];
    }
}

/**
 * UC_NK_XN - Bước 6-12: Xác nhận phiếu nhập
 */
function confirmReceipt($pdo, $historyManager, $qrManager, $data, $userId) {
    try {
        $receiptId = $data['receipt_id'] ?? 0;
        
        if (!$receiptId) {
            return ['success' => false, 'message' => 'Thiếu receipt_id'];
        }
        
        // Lấy warehouse_id từ session
        $warehouseId = $_SESSION['warehouse_id'] ?? null;
        if (!$warehouseId) {
            return ['success' => false, 'message' => 'Không xác định được kho hàng'];
        }
        
        // Kiểm tra phiếu nhập (chỉ trong warehouse của user)
        $sql = "SELECT * FROM stock_receipts WHERE receipt_id = ? AND warehouse_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$receiptId, $warehouseId]);
        $receipt = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$receipt) {
            return ['success' => false, 'message' => 'Không tìm thấy phiếu nhập'];
        }
        
        if ($receipt['status'] === 'confirmed') {
            return ['success' => false, 'message' => 'Phiếu đã được xác nhận trước đó'];
        }
        
        $pdo->beginTransaction();
        
        // Bước 7: Cập nhật trạng thái phiếu nhập là "Đã xác nhận"
        $sql = "UPDATE stock_receipts SET 
                status = 'confirmed', 
                confirmed_at = NOW(),
                confirmed_by = ?
                WHERE receipt_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId, $receiptId]);
        
        // Lấy danh sách items để xử lý
        $sql = "SELECT sri.*, pv.product_id 
                FROM stock_receipt_items sri
                LEFT JOIN product_variants pv ON sri.variant_id = pv.variant_id
                WHERE sri.receipt_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$receiptId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($items as &$item) {  // ⭐ QUAN TRỌNG: Thêm & để modify $item trực tiếp
            // LUÔN LUÔN lookup location_id từ location_code (kể cả khi đã có location_id)
            if (!empty($item['location_code'])) {
                $lookupStmt = $pdo->prepare("
                    SELECT location_id 
                    FROM locations 
                    WHERE shelf_code = ? AND warehouse_id = ?
                    LIMIT 1
                ");
                $lookupStmt->execute([$item['location_code'], $receipt['warehouse_id']]);
                $location = $lookupStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($location) {
                    $foundLocationId = $location['location_id'];
                    
                    // ⭐ FORCE UPDATE location_id vào stock_receipt_items
                    $updateItemStmt = $pdo->prepare("
                        UPDATE stock_receipt_items 
                        SET location_id = ? 
                        WHERE receipt_item_id = ?
                    ");
                    $updateResult = $updateItemStmt->execute([$foundLocationId, $item['receipt_item_id']]);
                    
                    // Verify update thành công
                    $affectedRows = $updateItemStmt->rowCount();
                    
                    if ($affectedRows > 0) {
                        error_log("✅ SUCCESS: Updated stock_receipt_items - receipt_item_id={$item['receipt_item_id']}, location_id=$foundLocationId, location_code={$item['location_code']}");
                        $item['location_id'] = $foundLocationId;  // Update biến $item
                    } else {
                        error_log("⚠️ WARNING: UPDATE không thay đổi gì - receipt_item_id={$item['receipt_item_id']}, location_id=$foundLocationId");
                    }
                    
                    // Double check bằng SELECT
                    $verifyStmt = $pdo->prepare("SELECT location_id FROM stock_receipt_items WHERE receipt_item_id = ?");
                    $verifyStmt->execute([$item['receipt_item_id']]);
                    $verifyResult = $verifyStmt->fetch(PDO::FETCH_ASSOC);
                    error_log("🔍 VERIFY: receipt_item_id={$item['receipt_item_id']}, location_id in DB = " . ($verifyResult['location_id'] ?? 'NULL'));
                    
                } else {
                    error_log("❌ ERROR: location_code '{$item['location_code']}' NOT FOUND in locations table for warehouse_id={$receipt['warehouse_id']}");
                }
            } else {
                error_log("⚠️ WARNING: receipt_item_id={$item['receipt_item_id']} has NO location_code");
            }
            
            // Bước 9: Cộng số lượng tồn kho
            updateInventoryStock($pdo, $item, $receipt['warehouse_id']);
            
            // Tạo QR code nếu chưa có (kiểm tra theo variant, không theo warehouse)
            try {
                if (!empty($item['product_id']) && !empty($item['variant_id'])) {
                    // Kiểm tra QR code cho variant này
                    $checkQR = $pdo->prepare("
                        SELECT qr_id 
                        FROM product_qr_codes 
                        WHERE variant_id = ? 
                        AND is_active = 1
                        LIMIT 1
                    ");
                    $checkQR->execute([$item['variant_id']]);
                    $existingQR = $checkQR->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$existingQR) {
                        // Chưa có QR cho variant này → Tạo mới
                        error_log("Creating QR for variant_id: {$item['variant_id']}, product_id: {$item['product_id']}");
                        
                        $qrResult = $qrManager->generateQRCode($item['product_id'], $item['variant_id'], $userId);
                        
                        if ($qrResult && isset($qrResult['qr_id'])) {
                            // Cập nhật warehouse_id, location_code và supplier_id cho QR vừa tạo
                            $updateStmt = $pdo->prepare("UPDATE product_qr_codes SET warehouse_id = ?, location_code = ?, supplier_id = ? WHERE qr_id = ?");
                            $updateStmt->execute([
                                $receipt['warehouse_id'], 
                                $item['location_code'] ?? null,
                                $receipt['supplier_id'] ?? null,
                                $qrResult['qr_id']
                            ]);
                            
                            error_log("QR code generated successfully! qr_id: {$qrResult['qr_id']}, variant_id: {$item['variant_id']}, warehouse_id: {$receipt['warehouse_id']}, location: " . ($item['location_code'] ?? 'null'));
                        } else {
                            error_log("QR code generation failed for variant_id: {$item['variant_id']} - Result: " . json_encode($qrResult));
                        }
                    } else {
                        error_log("QR code already exists for variant_id: {$item['variant_id']}, qr_id: {$existingQR['qr_id']}");
                    }
                }
            } catch (Exception $qrError) {
                error_log("QR generation error for variant_id " . ($item['variant_id'] ?? 'unknown') . ": " . $qrError->getMessage());
                error_log("QR error trace: " . $qrError->getTraceAsString());
                // Không throw error, tiếp tục xử lý
            }
        }
        unset($item);  // ⭐ Unset reference sau foreach
        
        // Bước 11: Ghi lại lịch sử hành động
        $historyManager->logChange($receiptId, $userId, 'confirm', null, null, null, 'Xác nhận phiếu nhập kho');
        
        // Tạo receipt_code động (không lưu vào database)
        $receiptCode = 'RC-' . str_pad($receiptId, 6, '0', STR_PAD_LEFT);
        
        // Ghi audit log
        logAuditAction($pdo, $userId, 'RECEIPT_CONFIRMED', "Xác nhận phiếu nhập #{$receiptCode}", $receiptId);
        
        $pdo->commit();
        
        return [
            'success' => true,
            'message' => 'Đã xác nhận phiếu nhập thành công',
            'receipt_code' => $receiptCode
        ];
        
    } catch (Exception $e) {
        $pdo->rollback();
        error_log("Confirm receipt error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Lỗi khi xác nhận phiếu: ' . $e->getMessage()];
    }
}

/**
 * Cập nhật tồn kho
 */
function updateInventoryStock($pdo, $item, $warehouseId) {
    try {
        $locationId = $item['location_id'] ?? null;
        
        error_log("updateInventoryStock: variant_id={$item['variant_id']}, warehouse_id=$warehouseId, quantity={$item['quantity']}, location_id=" . ($locationId ?? 'NULL'));
        
        // Sử dụng INSERT ... ON DUPLICATE KEY UPDATE để tránh lỗi duplicate
        // Unique constraint chỉ có (variant_id, warehouse_id), không có location_id
        // Nên chỉ cập nhật quantity và location_id (nếu có)
        $sql = "INSERT INTO inventory (variant_id, warehouse_id, quantity, location_id, updated_at) 
                VALUES (?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE 
                    quantity = quantity + VALUES(quantity),
                    location_id = VALUES(location_id),
                    updated_at = NOW()";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$item['variant_id'], $warehouseId, $item['quantity'], $locationId]);
        
        $affectedRows = $stmt->rowCount();
        if ($affectedRows == 1) {
            error_log("✅ Inventory inserted: variant_id={$item['variant_id']}, warehouse_id=$warehouseId, qty={$item['quantity']}, location_id=" . ($locationId ?? 'NULL'));
        } elseif ($affectedRows == 2) {
            error_log("✅ Inventory updated: variant_id={$item['variant_id']}, warehouse_id=$warehouseId, added_qty={$item['quantity']}, location_id=" . ($locationId ?? 'NULL'));
        } else {
            error_log("⚠️ No inventory changes: variant_id={$item['variant_id']}, warehouse_id=$warehouseId");
        }

        // Cập nhật stock_levels nếu có bảng này
        updateStockLevels($pdo, $item['variant_id'], $item['quantity']);

    } catch (Exception $e) {
        error_log("Update inventory stock error: " . $e->getMessage());
        throw $e;
    }
}

/**
 * Cập nhật stock_levels
 */
function updateStockLevels($pdo, $variantId, $quantity) {
    try {
        // Kiểm tra bảng stock_levels có tồn tại không
        $sql = "SHOW TABLES LIKE 'stock_levels'";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $tableExists = $stmt->fetch();
        
        if ($tableExists) {
            $sql = "INSERT INTO stock_levels (variant_id, quantity, updated_at) 
                    VALUES (?, ?, NOW())
                    ON DUPLICATE KEY UPDATE 
                    quantity = quantity + VALUES(quantity),
                    updated_at = NOW()";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$variantId, $quantity]);
        }
    } catch (Exception $e) {
        error_log("Update stock levels error: " . $e->getMessage());
    }
}

/**
 * Ghi audit log
 */
function logAuditAction($pdo, $userId, $action, $description, $referenceId = null) {
    try {
        // Kiểm tra bảng audit_logs có tồn tại không
        $sql = "SHOW TABLES LIKE 'audit_logs'";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $tableExists = $stmt->fetch();
        
        if ($tableExists) {
            // Cấu trúc bảng audit_logs: user_id, action, table_name, record_id, details, warehouse_id
            $sql = "INSERT INTO audit_logs (user_id, action, table_name, record_id, details, warehouse_id, created_at) 
                    VALUES (?, ?, 'stock_receipts', ?, ?, ?, NOW())";
            $stmt = $pdo->prepare($sql);
            $warehouseId = $_SESSION['warehouse_id'] ?? 1;
            $stmt->execute([$userId, $action, $referenceId, $description, $warehouseId]);
        }
    } catch (Exception $e) {
        error_log("Audit log error: " . $e->getMessage());
    }
}

/**
 * Lấy lịch sử phiếu nhập
 */
function getReceiptHistory($historyManager, $receiptId) {
    try {
        if (!$receiptId) {
            return ['success' => false, 'message' => 'Thiếu receipt_id'];
        }
        
        $history = $historyManager->getFormattedHistory($receiptId);
        
        return [
            'success' => true,
            'history' => $history
        ];
        
    } catch (Exception $e) {
        error_log("Get receipt history error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Lỗi khi tải lịch sử'];
    }
}

/**
 * Xóa phiếu nhập (chỉ draft)
 */
function deleteReceipt($pdo, $historyManager, $receiptId, $userId) {
    try {
        if (!$receiptId) {
            return ['success' => false, 'message' => 'Thiếu receipt_id'];
        }
        
        // Lấy warehouse_id từ session
        $warehouseId = $_SESSION['warehouse_id'] ?? null;
        if (!$warehouseId) {
            return ['success' => false, 'message' => 'Không xác định được kho hàng'];
        }
        
        // Kiểm tra phiếu nhập có tồn tại và là draft không (chỉ trong warehouse của user)
        $sql = "SELECT * FROM stock_receipts WHERE receipt_id = ? AND warehouse_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$receiptId, $warehouseId]);
        $receipt = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$receipt) {
            return ['success' => false, 'message' => 'Không tìm thấy phiếu nhập'];
        }
        
        if ($receipt['status'] !== 'draft') {
            return ['success' => false, 'message' => 'Chỉ có thể xóa phiếu nhập ở trạng thái bản nháp'];
        }
        
        $pdo->beginTransaction();
        
        // Xóa các items của phiếu nhập
        $sql = "DELETE FROM stock_receipt_items WHERE receipt_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$receiptId]);
        
        // Ghi lịch sử trước khi xóa
        $historyManager->logChange($receiptId, $userId, 'delete', null, json_encode($receipt), null, 'Xóa phiếu nhập bản nháp');
        
        // Xóa phiếu nhập
        $sql = "DELETE FROM stock_receipts WHERE receipt_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$receiptId]);
        
        // Ghi audit log
        $receiptCode = 'RC-' . str_pad($receiptId, 6, '0', STR_PAD_LEFT);
        logAuditAction($pdo, $userId, 'RECEIPT_DELETED', "Xóa phiếu nhập #{$receiptCode}", $receiptId);
        
        $pdo->commit();
        
        return [
            'success' => true,
            'message' => 'Đã xóa phiếu nhập thành công'
        ];
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollback();
        }
        error_log("Delete receipt error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Lỗi khi xóa phiếu nhập: ' . $e->getMessage()];
    }
}
?>
