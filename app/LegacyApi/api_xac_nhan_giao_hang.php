<?php
// Bật error reporting for debugging - log only, don't display
error_reporting(E_ALL);
ini_set('display_errors', 0); // MUST BE 0 for JSON API
ini_set('log_errors', 1);

// Only set headers if not already sent (when called via HTTP, not include)
if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Accept');
}

// Xử lý preflight OPTIONS request (only for HTTP calls)
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Bắt đầu session only nếu chưa được khởi động
// if (session_status() === PHP_SESSION_NONE) {
//     session_start();
// }
require_once __DIR__ . '/../../config/database.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false, 
        'message' => 'Chưa đăng nhập. Vui lòng đăng nhập lại.',
        'error_code' => 'UNAUTHORIZED'
    ]);
    exit;
}

try {
    $database = new Database();
    $pdo = $database->getConnection();
} catch (Exception $e) {
    error_log("Database connection error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Lỗi kết nối cơ sở dữ liệu. Vui lòng thử lại.',
        'error_code' => 'DATABASE_CONNECTION_ERROR'
    ]);
    exit;
}

$userId = $_SESSION['user_id'];
$warehouseId = $_SESSION['warehouse_id'];

// Kiểm tra quyền: chỉ Manager và Admin được phép xác nhận xuất hàng
$userRole = $_SESSION['role'] ?? 'staff';
if (!in_array($userRole, ['admin', 'manager'])) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Bạn không có quyền xác nhận xuất hàng. Chỉ Manager và Admin mới có quyền này.',
        'error_code' => 'FORBIDDEN'
    ]);
    exit;
}

// Đọc dữ liệu từ request - hỗ trợ cả JSON và POST form
$input = null;
$rawInput = file_get_contents('php://input');
error_log("API Request body: " . $rawInput);

// Thử JSON trước
if (!empty($rawInput)) {
    $input = json_decode($rawInput, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        // Nếu không phải JSON hợp lệ, reset để thử POST
        $input = null;
    }
}

// Nếu không có JSON, thử POST form data
if ($input === null && !empty($_POST)) {
    $input = $_POST;
    error_log("Using POST data: " . print_r($_POST, true));
}

// Kiểm tra có dữ liệu không
if (empty($input)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Không nhận được dữ liệu request.',
        'error_code' => 'EMPTY_REQUEST'
    ]);
    exit;
}

$action = $input['action'] ?? '';
error_log("API Action: " . $action);

try {
    switch ($action) {
        case 'confirm_delivery':
            confirmDelivery($pdo, $input, $userId, $warehouseId);
            break;
            
        case 'cancel_export':
            cancelExport($pdo, $input, $userId, $warehouseId);
            break;
            
        default:
            throw new Exception('Hành động không hợp lệ: ' . $action);
    }
} catch (Exception $e) {
    error_log("API Error: " . $e->getMessage());
    error_log("API Error Trace: " . $e->getTraceAsString());
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'message' => $e->getMessage(),
        'error_code' => 'PROCESSING_ERROR',
        'debug_info' => [
            'file' => basename($e->getFile()),
            'line' => $e->getLine()
        ]
    ]);
} catch (PDOException $e) {
    error_log("Database Error: " . $e->getMessage());
    error_log("Database Error Trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Lỗi cơ sở dữ liệu. Vui lòng liên hệ quản trị viên.',
        'error_code' => 'DATABASE_ERROR',
        'debug_info' => [
            'code' => $e->getCode()
        ]
    ]);
}

// Hàm xác nhận xuất hàng
function confirmDelivery($pdo, $input, $userId, $warehouseId) {
    $exportId = $input['export_id'] ?? null;
    
    if (!$exportId) {
        throw new Exception('Thiếu export_id');
    }
    
    error_log("confirmDelivery: export_id=$exportId, user_id=$userId, warehouse_id=$warehouseId");
    
    // Kiểm tra phiếu xuất có tồn tại và ở trạng thái "completed" (Đã đóng gói)
    $checkSql = "
        SELECT 
            we.export_id,
            we.order_id,
            we.status,
            we.export_code,
            we.warehouse_id as export_warehouse_id,
            we.confirmed_delivery_at,
            we.confirmed_delivery_by,
            o.status as order_status,
            o.customer_id,
            COALESCE(c.full_name, 'Khách lẻ') as customer_name,
            (
                SELECT COUNT(*)
                FROM warehouse_export_details wed
                WHERE wed.export_id = we.export_id
            ) as total_items,
            (
                SELECT SUM(wed.quantity)
                FROM warehouse_export_details wed
                WHERE wed.export_id = we.export_id
            ) as total_quantity
        FROM warehouse_exports we
        JOIN orders o ON we.order_id = o.order_id
        LEFT JOIN customers c ON o.customer_id = c.customer_id
        WHERE we.export_id = :export_id
        AND we.warehouse_id = :warehouse_id
    ";
    
    $checkStmt = $pdo->prepare($checkSql);
    $checkStmt->bindParam(':export_id', $exportId, PDO::PARAM_INT);
    $checkStmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
    $checkStmt->execute();
    $exportInfo = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    error_log("Export info query result: " . json_encode($exportInfo));
    error_log("Search params: export_id=$exportId, warehouse_id=$warehouseId");
    
    if (!$exportInfo) {
        // Kiểm tra xem export có tồn tại không (bỏ qua warehouse_id)
        $debugSql = "SELECT export_id, warehouse_id, status FROM warehouse_exports WHERE export_id = :export_id";
        $debugStmt = $pdo->prepare($debugSql);
        $debugStmt->bindParam(':export_id', $exportId, PDO::PARAM_INT);
        $debugStmt->execute();
        $debugInfo = $debugStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($debugInfo) {
            throw new Exception(sprintf(
                "Phiếu xuất #%d tồn tại nhưng thuộc kho %d (bạn đang ở kho %d) hoặc không đủ điều kiện xác nhận",
                $exportId,
                $debugInfo['warehouse_id'],
                $warehouseId
            ));
        } else {
            throw new Exception("Không tìm thấy phiếu xuất có ID: $exportId");
        }
    }
    
    // Kiểm tra warehouse mismatch
    if ($exportInfo['export_warehouse_id'] != $warehouseId) {
        throw new Exception(sprintf(
            'Phiếu xuất thuộc kho %d nhưng bạn đang đăng nhập vào kho %d. Vui lòng đăng nhập đúng tài khoản kho.',
            $exportInfo['export_warehouse_id'],
            $warehouseId
        ));
    }
    
    // Kiểm tra trạng thái
    if ($exportInfo['status'] !== 'completed') {
        throw new Exception(sprintf(
            'Phiếu xuất %s chưa ở trạng thái "Đã đóng gói" (hiện tại: %s). Vui lòng hoàn thành đóng gói trước.',
            $exportInfo['export_code'],
            $exportInfo['status']
        ));
    }
    
    // ⚠️ KIỂM TRA QUAN TRỌNG: Đã xác nhận xuất hàng chưa?
    if (!empty($exportInfo['confirmed_delivery_at']) || !empty($exportInfo['confirmed_delivery_by'])) {
        throw new Exception(sprintf(
            'Phiếu xuất %s ĐÃ ĐƯỢC XÁC NHẬN xuất hàng trước đó (vào lúc %s). Không thể xác nhận lại!',
            $exportInfo['export_code'],
            $exportInfo['confirmed_delivery_at'] ?? 'N/A'
        ));
    }
    
    // ⚠️ QUAN TRỌNG: KHÔNG KIỂM TRA VÀ TRỪ TỒN KHO Ở ĐÂY
    // Tồn kho đã được trừ khi xử lý đơn xuất (xu_ly_xuat_kho.php)
    // Bước xác nhận này CHỈ:
    // 1. Đánh dấu phiếu xuất đã được xác nhận xuất hàng
    // 2. Chuyển đơn hàng sang trạng thái "Chờ giao hàng"
    // 3. Ghi log hành động
    
    error_log("confirmDelivery: Skipping inventory reduction (already done during export processing)");
    
    // Bắt đầu transaction
    $pdo->beginTransaction();
    
    try {
        // Bước 1: Cập nhật thông tin xác nhận xuất hàng
        // Thêm cột confirmed_delivery nếu chưa có
        try {
            $columnCheck = $pdo->query("SHOW COLUMNS FROM warehouse_exports LIKE 'confirmed_delivery_at'");
            if ($columnCheck->rowCount() === 0) {
                $pdo->exec("ALTER TABLE warehouse_exports ADD COLUMN confirmed_delivery_at TIMESTAMP NULL DEFAULT NULL");
                error_log("Added confirmed_delivery_at column");
            }
            
            $columnCheck2 = $pdo->query("SHOW COLUMNS FROM warehouse_exports LIKE 'confirmed_delivery_by'");
            if ($columnCheck2->rowCount() === 0) {
                $pdo->exec("ALTER TABLE warehouse_exports ADD COLUMN confirmed_delivery_by INT(11) NULL DEFAULT NULL");
                error_log("Added confirmed_delivery_by column");
            }
        } catch (PDOException $e) {
            error_log("Warning: Could not add columns: " . $e->getMessage());
        }
        
        // Cập nhật phiếu xuất
        $updateExportSql = "
            UPDATE warehouse_exports 
            SET confirmed_delivery_at = NOW(),
                confirmed_delivery_by = :user_id,
                updated_at = NOW()
            WHERE export_id = :export_id
            AND status = 'completed'
        ";
        
        $updateExportStmt = $pdo->prepare($updateExportSql);
        $updateExportStmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $updateExportStmt->bindParam(':export_id', $exportId, PDO::PARAM_INT);
        $updateExportStmt->execute();
        
        if ($updateExportStmt->rowCount() === 0) {
            throw new Exception('Không thể cập nhật phiếu xuất. Phiếu có thể đã được xác nhận hoặc không còn ở trạng thái completed.');
        }
        
        // Thử update status sang 'confirmed'
        try {
            $updateStatusSql = "UPDATE warehouse_exports SET status = 'confirmed' WHERE export_id = :export_id";
            $updateStatusStmt = $pdo->prepare($updateStatusSql);
            $updateStatusStmt->bindParam(':export_id', $exportId, PDO::PARAM_INT);
            $updateStatusStmt->execute();
            error_log("Updated export status to 'confirmed'");
        } catch (PDOException $e) {
            error_log("WARNING: Could not update status to 'confirmed': " . $e->getMessage());
        }
        
        // Bước 3: Cập nhật trạng thái đơn hàng sang "Chờ giao hàng" (waiting_delivery)
        // Bỏ điều kiện status = 'delivered' vì đơn hàng có thể ở nhiều trạng thái khác nhau
        $updateOrderSql = "
            UPDATE orders 
            SET status = 'waiting_delivery',
                updated_at = NOW()
            WHERE order_id = :order_id
        ";
        
        $updateOrderStmt = $pdo->prepare($updateOrderSql);
        $updateOrderStmt->bindParam(':order_id', $exportInfo['order_id'], PDO::PARAM_INT);
        $updateOrderStmt->execute();
        
        if ($updateOrderStmt->rowCount() > 0) {
            error_log("Updated order #{$exportInfo['order_id']} status to 'waiting_delivery' (previous status: {$exportInfo['order_status']})");
        } else {
            error_log("WARNING: Could not update order status (may already be updated)");
        }
        
        // Bước 4: Ghi log audit
        logAudit($pdo, $userId, 'confirm_delivery', 'warehouse_exports', $exportId, [
            'export_id' => $exportId,
            'export_code' => $exportInfo['export_code'],
            'order_id' => $exportInfo['order_id'],
            'customer_name' => $exportInfo['customer_name'],
            'total_items' => $exportInfo['total_items'],
            'total_quantity' => $exportInfo['total_quantity'],
            'order_status_updated' => 'waiting_delivery',
            'note' => 'Inventory was already reduced during export processing'
        ]);
        
        // Commit transaction
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => sprintf(
                'Đã xác nhận xuất hàng thành công!\n\nPhiếu xuất: %s\nĐơn hàng: #%s\nKhách hàng: %s\nSố lượng: %d sản phẩm\n\nĐơn hàng chuyển sang trạng thái "Chờ giao hàng".',
                $exportInfo['export_code'],
                str_pad($exportInfo['order_id'], 6, '0', STR_PAD_LEFT),
                $exportInfo['customer_name'],
                $exportInfo['total_quantity']
            ),
            'data' => [
                'export_id' => $exportId,
                'export_code' => $exportInfo['export_code'],
                'order_id' => $exportInfo['order_id'],
                'customer_name' => $exportInfo['customer_name'],
                'total_quantity' => $exportInfo['total_quantity'],
                'confirmed_at' => date('Y-m-d H:i:s'),
                'order_status' => 'waiting_delivery'
            ]
        ]);
        
        error_log("Successfully confirmed delivery for export_id: $exportId (inventory already reduced during processing)");
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Transaction rolled back: " . $e->getMessage());
        throw $e;
    }
}

// Hàm hủy phiếu xuất
function cancelExport($pdo, $input, $userId, $warehouseId) {
    $exportId = $input['export_id'] ?? null;
    $cancelReason = $input['cancel_reason'] ?? '';
    
    if (!$exportId) {
        throw new Exception('Thiếu export_id');
    }
    
    if (!$cancelReason) {
        throw new Exception('Vui lòng nhập lý do hủy');
    }
    
    error_log("cancelExport: export_id=$exportId, user_id=$userId, warehouse_id=$warehouseId, reason=$cancelReason");
    
    // Kiểm tra phiếu xuất có tồn tại và ở trạng thái "completed" (Đã đóng gói)
    $checkSql = "
        SELECT 
            we.export_id,
            we.order_id,
            we.status,
            we.export_code,
            we.warehouse_id as export_warehouse_id,
            o.status as order_status
        FROM warehouse_exports we
        JOIN orders o ON we.order_id = o.order_id
        WHERE we.export_id = :export_id
    ";
    
    $checkStmt = $pdo->prepare($checkSql);
    $checkStmt->bindParam(':export_id', $exportId, PDO::PARAM_INT);
    $checkStmt->execute();
    $exportInfo = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    error_log("Export info for cancel: " . json_encode($exportInfo));
    
    if (!$exportInfo) {
        throw new Exception("Không tìm thấy phiếu xuất có ID: $exportId");
    }
    
    // Kiểm tra warehouse
    if ($exportInfo['export_warehouse_id'] != $warehouseId) {
        throw new Exception(sprintf(
            'Phiếu xuất thuộc kho %d nhưng bạn đang đăng nhập vào kho %d.',
            $exportInfo['export_warehouse_id'],
            $warehouseId
        ));
    }
    
    // Kiểm tra trạng thái
    if ($exportInfo['status'] !== 'completed') {
        throw new Exception(sprintf(
            'Chỉ có thể hủy phiếu xuất ở trạng thái "Đã đóng gói". Trạng thái hiện tại: %s',
            $exportInfo['status']
        ));
    }
    
    // Bắt đầu transaction
    $pdo->beginTransaction();
    
    try {
        // Thêm cột cancel_reason nếu chưa có
        try {
            $columnCheck = $pdo->query("SHOW COLUMNS FROM warehouse_exports LIKE 'cancel_reason'");
            if ($columnCheck->rowCount() === 0) {
                $pdo->exec("ALTER TABLE warehouse_exports ADD COLUMN cancel_reason TEXT NULL DEFAULT NULL");
                error_log("Added cancel_reason column to warehouse_exports table");
            }
            
            $columnCheck2 = $pdo->query("SHOW COLUMNS FROM warehouse_exports LIKE 'cancelled_at'");
            if ($columnCheck2->rowCount() === 0) {
                $pdo->exec("ALTER TABLE warehouse_exports ADD COLUMN cancelled_at TIMESTAMP NULL DEFAULT NULL");
                error_log("Added cancelled_at column to warehouse_exports table");
            }
            
            $columnCheck3 = $pdo->query("SHOW COLUMNS FROM warehouse_exports LIKE 'cancelled_by'");
            if ($columnCheck3->rowCount() === 0) {
                $pdo->exec("ALTER TABLE warehouse_exports ADD COLUMN cancelled_by INT(11) NULL DEFAULT NULL");
                error_log("Added cancelled_by column to warehouse_exports table");
            }
        } catch (PDOException $e) {
            error_log("Warning: Could not add columns: " . $e->getMessage());
        }
        
        // Bước 1: Cập nhật trạng thái phiếu xuất thành 'cancelled'
        $updateExportSql = "
            UPDATE warehouse_exports 
            SET status = 'cancelled',
                cancel_reason = :cancel_reason,
                cancelled_at = NOW(),
                cancelled_by = :user_id,
                updated_at = NOW()
            WHERE export_id = :export_id
        ";
        
        $updateExportStmt = $pdo->prepare($updateExportSql);
        $updateExportStmt->bindParam(':cancel_reason', $cancelReason, PDO::PARAM_STR);
        $updateExportStmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $updateExportStmt->bindParam(':export_id', $exportId, PDO::PARAM_INT);
        $updateExportStmt->execute();
        
        if ($updateExportStmt->rowCount() === 0) {
            throw new Exception('Không thể cập nhật trạng thái phiếu xuất');
        }
        
        error_log("Updated export $exportId status to cancelled");
        
        // Bước 2: Lấy danh sách sản phẩm đã xuất để hoàn trả về kho
        $getExportDetailsSql = "
            SELECT variant_id, quantity, picked_quantity
            FROM warehouse_export_details
            WHERE export_id = :export_id
        ";
        
        $getDetailsStmt = $pdo->prepare($getExportDetailsSql);
        $getDetailsStmt->bindParam(':export_id', $exportId, PDO::PARAM_INT);
        $getDetailsStmt->execute();
        $exportDetails = $getDetailsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        $restoredCount = 0;
        
        // Hoàn trả số lượng đã xuất về inventory
        foreach ($exportDetails as $detail) {
            // Ưu tiên picked_quantity (số lượng thực tế đã lấy), nếu không có thì dùng quantity
            $quantityToRestore = $detail['picked_quantity'] > 0 ? $detail['picked_quantity'] : $detail['quantity'];
            
            if ($quantityToRestore > 0) {
                // Cộng lại số lượng vào inventory
                $restoreInventorySql = "
                    UPDATE inventory 
                    SET quantity = quantity + :quantity,
                        updated_at = NOW()
                    WHERE variant_id = :variant_id 
                    AND warehouse_id = :warehouse_id
                ";
                
                $restoreStmt = $pdo->prepare($restoreInventorySql);
                $restoreStmt->bindParam(':quantity', $quantityToRestore, PDO::PARAM_INT);
                $restoreStmt->bindParam(':variant_id', $detail['variant_id'], PDO::PARAM_INT);
                $restoreStmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
                $restoreStmt->execute();
                
                $restoredCount++;
                error_log("Restored inventory: variant_id={$detail['variant_id']}, quantity={$quantityToRestore}, warehouse_id={$warehouseId}");
            }
        }
        
        error_log("Total items restored to inventory: $restoredCount");
        
        // Bước 3: Cập nhật trạng thái đơn hàng về 'processing'
        $updateOrderSql = "
            UPDATE orders 
            SET status = 'processing',
                updated_at = NOW()
            WHERE order_id = :order_id
        ";
        
        $updateOrderStmt = $pdo->prepare($updateOrderSql);
        $updateOrderStmt->bindParam(':order_id', $exportInfo['order_id'], PDO::PARAM_INT);
        $updateOrderStmt->execute();
        
        error_log("Updated order {$exportInfo['order_id']} status back to processing");
        
        // Bước 4: Ghi log audit
        logAudit($pdo, $userId, 'cancel_export', 'warehouse_exports', $exportId, [
            'export_id' => $exportId,
            'export_code' => $exportInfo['export_code'],
            'order_id' => $exportInfo['order_id'],
            'cancel_reason' => $cancelReason,
            'old_status' => 'completed',
            'new_status' => 'cancelled',
            'items_restored' => $restoredCount,
            'warehouse_id' => $warehouseId
        ]);
        
        // Commit transaction
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => sprintf(
                'Đã hủy phiếu xuất %s thành công. Hoàn trả %d sản phẩm về kho. Đơn hàng #%s đã quay về trạng thái "Đang xử lý".',
                $exportInfo['export_code'],
                $restoredCount,
                str_pad($exportInfo['order_id'], 6, '0', STR_PAD_LEFT)
            ),
            'data' => [
                'export_id' => $exportId,
                'export_code' => $exportInfo['export_code'],
                'order_id' => $exportInfo['order_id'],
                'items_restored' => $restoredCount
            ]
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Transaction rolled back due to error: " . $e->getMessage());
        throw $e;
    }
}

// Hàm ghi log audit
function logAudit($pdo, $userId, $action, $tableName, $recordId, $details) {
    try {
        $auditSql = "
            INSERT INTO audit_logs (user_id, action, table_name, record_id, new_values, warehouse_id, details) 
            VALUES (:user_id, :action, :table_name, :record_id, :new_values, :warehouse_id, :details)
        ";
        
        $auditStmt = $pdo->prepare($auditSql);
        $newValuesJson = json_encode($details);
        $detailsJson = json_encode($details);
        $warehouseId = $_SESSION['warehouse_id'] ?? 1;
        
        $auditStmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $auditStmt->bindParam(':action', $action, PDO::PARAM_STR);
        $auditStmt->bindParam(':table_name', $tableName, PDO::PARAM_STR);
        $auditStmt->bindParam(':record_id', $recordId, PDO::PARAM_INT);
        $auditStmt->bindParam(':new_values', $newValuesJson, PDO::PARAM_STR);
        $auditStmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
        $auditStmt->bindParam(':details', $detailsJson, PDO::PARAM_STR);
        $auditStmt->execute();
        
        error_log("Audit log created for action: $action, record: $recordId");
    } catch (PDOException $e) {
        error_log("Failed to create audit log: " . $e->getMessage());
        // Không ném lỗi để không làm gián đoạn flow chính
    }
}
?>