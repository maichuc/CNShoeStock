<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0); // MUST BE 0 for JSON API
ini_set('log_errors', 1);

// Only set headers if not already sent
if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Accept');
}

// Handle preflight OPTIONS request
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Start session only if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'config/cau_hinh_csdl.php';

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

// Đọc dữ liệu từ request - hỗ trợ cả JSON và POST form
$input = null;
$rawInput = file_get_contents('php://input');
error_log("API Request body: " . $rawInput);

// Thử JSON trước
if (!empty($rawInput)) {
    $input = json_decode($rawInput, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
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

if ($action !== 'update_delivery_status') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Hành động không hợp lệ: ' . $action,
        'error_code' => 'PROCESSING_ERROR',
        'debug_info' => [
            'file' => basename(__FILE__),
            'line' => __LINE__
        ]
    ]);
    exit;
}

// Xử lý cập nhật trạng thái giao hàng
try {
    $orderId = (int)($input['order_id'] ?? 0);
    $newStatus = trim($input['status'] ?? '');
    
    if ($orderId <= 0) {
        throw new Exception('ID đơn hàng không hợp lệ');
    }
    
    if (!in_array($newStatus, ['delivered', 'failed'])) {
        throw new Exception('Trạng thái không hợp lệ: ' . $newStatus);
    }
    
    error_log("updateDeliveryStatus: order_id={$orderId}, status={$newStatus}, user_id={$userId}, warehouse_id={$warehouseId}");
    
    // Kiểm tra đơn hàng có tồn tại và thuộc warehouse này không
    $sql = "
        SELECT 
            o.order_id,
            o.status as current_status,
            o.warehouse_id,
            c.full_name as customer_name
        FROM orders o
        LEFT JOIN customers c ON o.customer_id = c.customer_id
        WHERE o.order_id = :order_id
        AND o.warehouse_id = :warehouse_id
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':order_id', $orderId, PDO::PARAM_INT);
    $stmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
    $stmt->execute();
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        throw new Exception('Đơn hàng không tồn tại hoặc không thuộc kho của bạn');
    }
    
    if ($order['current_status'] !== 'waiting_delivery') {
        throw new Exception('Đơn hàng không ở trạng thái "Chờ giao hàng". Trạng thái hiện tại: ' . $order['current_status']);
    }
    
    error_log("Order info: " . json_encode($order));
    
    // Bắt đầu transaction
    $pdo->beginTransaction();
    
    // XỬ LÝ ĐẶC BIỆT: Giao hàng thất bại
    if ($newStatus === 'failed') {
        error_log("Processing failed delivery - returning goods to inventory");
        
        // 1. Lấy thông tin phiếu xuất
        $getExportSql = "SELECT export_id FROM warehouse_exports WHERE order_id = :order_id AND warehouse_id = :warehouse_id";
        $getExportStmt = $pdo->prepare($getExportSql);
        $getExportStmt->bindParam(':order_id', $orderId, PDO::PARAM_INT);
        $getExportStmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
        $getExportStmt->execute();
        $export = $getExportStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($export) {
            // 2. Lấy chi tiết sản phẩm đã xuất
            $getDetailsSql = "SELECT variant_id, picked_quantity FROM warehouse_export_details WHERE export_id = :export_id AND picked_quantity > 0";
            $getDetailsStmt = $pdo->prepare($getDetailsSql);
            $getDetailsStmt->bindParam(':export_id', $export['export_id'], PDO::PARAM_INT);
            $getDetailsStmt->execute();
            $details = $getDetailsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 3. Hoàn hàng về kho (cộng lại vào inventory)
            foreach ($details as $detail) {
                $restoreInventorySql = "
                    UPDATE inventory 
                    SET quantity = quantity + :quantity,
                        updated_at = NOW()
                    WHERE variant_id = :variant_id 
                    AND warehouse_id = :warehouse_id
                ";
                $restoreStmt = $pdo->prepare($restoreInventorySql);
                $restoreStmt->bindParam(':quantity', $detail['picked_quantity'], PDO::PARAM_INT);
                $restoreStmt->bindParam(':variant_id', $detail['variant_id'], PDO::PARAM_INT);
                $restoreStmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
                $restoreStmt->execute();
                
                error_log("Restored inventory: variant_id={$detail['variant_id']}, quantity={$detail['picked_quantity']}");
            }
            
            // 4. Hủy phiếu xuất
            $cancelExportSql = "UPDATE warehouse_exports SET status = 'cancelled', updated_at = NOW() WHERE export_id = :export_id";
            $cancelExportStmt = $pdo->prepare($cancelExportSql);
            $cancelExportStmt->bindParam(':export_id', $export['export_id'], PDO::PARAM_INT);
            $cancelExportStmt->execute();
            
            error_log("Cancelled export_id: {$export['export_id']}");
        }
        
        // 5. Đơn hàng chuyển sang trạng thái 'canceled' với lý do "Giao hàng thất bại"
        $finalStatus = 'canceled';
        $cancellationReason = 'Giao hàng thất bại';
        $updateSql = "UPDATE orders SET status = :status, cancellation_reason = :reason, updated_at = NOW() WHERE order_id = :order_id";
        $updateStmt = $pdo->prepare($updateSql);
        $updateStmt->bindParam(':status', $finalStatus, PDO::PARAM_STR);
        $updateStmt->bindParam(':reason', $cancellationReason, PDO::PARAM_STR);
        $updateStmt->bindParam(':order_id', $orderId, PDO::PARAM_INT);
        
        if (!$updateStmt->execute()) {
            throw new Exception('Không thể cập nhật trạng thái đơn hàng');
        }
        
        error_log("Updated order {$orderId} status to canceled with reason: {$cancellationReason}");
    } else {
        // Cập nhật trạng thái bình thường (delivered)
        $updateSql = "UPDATE orders SET status = :status, updated_at = NOW() WHERE order_id = :order_id";
        $updateStmt = $pdo->prepare($updateSql);
        $updateStmt->bindParam(':status', $newStatus, PDO::PARAM_STR);
        $updateStmt->bindParam(':order_id', $orderId, PDO::PARAM_INT);
        
        if (!$updateStmt->execute()) {
            throw new Exception('Không thể cập nhật trạng thái đơn hàng');
        }
        
        error_log("Updated order {$orderId} status to {$newStatus}");
    }
    
    // Ghi log audit
    try {
        $auditSql = "
            INSERT INTO audit_logs (user_id, action, table_name, record_id, new_values, warehouse_id, details, created_at)
            VALUES (:user_id, :action, :table_name, :record_id, :new_values, :warehouse_id, :details, NOW())
        ";
        
        $auditStmt = $pdo->prepare($auditSql);
        
        $finalStatusForLog = ($newStatus === 'failed') ? 'canceled' : $newStatus;
        $reason = ($newStatus === 'failed') ? 'Giao hàng thất bại - Đã hoàn hàng về kho và hủy phiếu xuất' : null;
        
        $newValuesJson = json_encode([
            'status' => $finalStatusForLog, 
            'reason' => $reason,
            'updated_at' => date('Y-m-d H:i:s')
        ]);
        
        $detailsJson = json_encode([
            'order_id' => $orderId,
            'old_status' => $order['current_status'],
            'new_status' => $finalStatusForLog,
            'original_action' => $newStatus,
            'customer_name' => $order['customer_name'],
            'action_type' => 'delivery_status_update',
            'inventory_restored' => ($newStatus === 'failed')
        ]);
        $auditAction = 'update_delivery_status';
        $tableName = 'orders';
        
        $auditStmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $auditStmt->bindParam(':action', $auditAction, PDO::PARAM_STR);
        $auditStmt->bindParam(':table_name', $tableName, PDO::PARAM_STR);
        $auditStmt->bindParam(':record_id', $orderId, PDO::PARAM_INT);
        $auditStmt->bindParam(':new_values', $newValuesJson, PDO::PARAM_STR);
        $auditStmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
        $auditStmt->bindParam(':details', $detailsJson, PDO::PARAM_STR);
        $auditStmt->execute();
        
        error_log("Audit log created for action: update_delivery_status, record: {$orderId}");
    } catch (PDOException $e) {
        error_log("Audit log error: " . $e->getMessage());
        // Không ngừng transaction vì audit log lỗi
    }
    
    // Commit transaction
    $pdo->commit();
    
    if ($newStatus === 'failed') {
        $statusMessage = 'Đã xác nhận giao hàng thất bại. Hàng đã được hoàn về kho, phiếu xuất và đơn hàng đã được hủy.';
    } else {
        $statusMessage = "Đã cập nhật trạng thái đơn hàng #" . str_pad($orderId, 6, '0', STR_PAD_LEFT) . " thành 'giao thành công' cho khách hàng {$order['customer_name']}.";
    }
    
    echo json_encode([
        'success' => true,
        'message' => $statusMessage,
        'data' => [
            'order_id' => $orderId,
            'order_code' => str_pad($orderId, 6, '0', STR_PAD_LEFT),
            'old_status' => $order['current_status'],
            'new_status' => $newStatus,
            'customer_name' => $order['customer_name'],
            'status_text' => $statusMessage
        ]
    ]);
    
} catch (Exception $e) {
    // Rollback transaction nếu có lỗi
    if ($pdo->inTransaction()) {
        $pdo->rollback();
    }
    
    error_log("Update delivery status error: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Lỗi cập nhật trạng thái: ' . $e->getMessage(),
        'error_code' => 'UPDATE_ERROR',
        'debug_info' => [
            'file' => basename(__FILE__),
            'line' => __LINE__,
            'order_id' => $orderId ?? 'unknown',
            'status' => $newStatus ?? 'unknown'
        ]
    ]);
}
?>