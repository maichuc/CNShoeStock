<?php
// Suppress all PHP errors and warnings for clean JSON output
error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');

// session_start();
require_once __DIR__ . '/../../config/database.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    $database = new Database();
    $pdo = $database->getConnection();
    
    $orderId = $_POST['order_id'] ?? null;
    $newStatus = $_POST['status'] ?? null;
    $userId = $_SESSION['user_id'];
    
    // Nhật ký gỡ lỗi
    error_log("Update order status - Order ID: $orderId, New Status: $newStatus, User ID: $userId");
    
    if (!$orderId || !$newStatus) {
        echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
        exit;
    }
    
    // Kiểm tra trạng thái
    $validStatuses = ['pending', 'accepted', 'canceled', 'waiting_delivery', 'delivered', 'failed'];
    if (!in_array($newStatus, $validStatuses)) {
        echo json_encode(['success' => false, 'message' => 'Invalid status']);
        exit;
    }
    
    // Lấy thông tin đơn hàng hiện tại
    $getCurrentOrderQuery = "SELECT * FROM orders WHERE order_id = :order_id AND warehouse_id = :warehouse_id";
    $getCurrentOrderStmt = $pdo->prepare($getCurrentOrderQuery);
    $getCurrentOrderStmt->bindParam(':order_id', $orderId);
    $getCurrentOrderStmt->bindParam(':warehouse_id', $_SESSION['warehouse_id']);
    $getCurrentOrderStmt->execute();
    
    $currentOrder = $getCurrentOrderStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$currentOrder) {
        echo json_encode(['success' => false, 'message' => 'Order not found']);
        exit;
    }
    
    // Kiểm tra xem chuyển trạng thái có hợp lệ không
    $currentStatus = $currentOrder['status'];
    
    // Nhật ký gỡ lỗi
    error_log("Current status from DB: $currentStatus");
    
    $validTransitions = [
        'pending' => ['accepted', 'canceled'],
        'accepted' => ['waiting_delivery', 'canceled'],
        'waiting_delivery' => ['delivered', 'failed'],  // failed sẽ tự động chuyển thành canceled
        'delivered' => [],
        'canceled' => []
    ];
    
    if (!isset($validTransitions[$currentStatus])) {
        error_log("Unknown status: $currentStatus");
        echo json_encode(['success' => false, 'message' => "Unknown current status: $currentStatus"]);
        exit;
    }
    
    if (!in_array($newStatus, $validTransitions[$currentStatus])) {
        error_log("Invalid transition from $currentStatus to $newStatus");
        echo json_encode([
            'success' => false, 
            'message' => "Không thể chuyển từ trạng thái '$currentStatus' sang '$newStatus'. Trạng thái hợp lệ: " . implode(', ', $validTransitions[$currentStatus])
        ]);
        exit;
    }
    
    // Bắt đầu giao dịch
    $pdo->beginTransaction();
    
    try {
        // XỬ LÝ ĐẶC BIỆT: Giao hàng thất bại
        if ($newStatus === 'failed') {
            // 1. Hoàn hàng về kho (cộng lại inventory)
            $getExportSql = "SELECT export_id FROM warehouse_exports WHERE order_id = :order_id AND warehouse_id = :warehouse_id";
            $getExportStmt = $pdo->prepare($getExportSql);
            $getExportStmt->bindParam(':order_id', $orderId);
            $getExportStmt->bindParam(':warehouse_id', $_SESSION['warehouse_id']);
            $getExportStmt->execute();
            $export = $getExportStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($export) {
                // Lấy chi tiết sản phẩm đã xuất
                $getDetailsSql = "SELECT variant_id, picked_quantity FROM warehouse_export_details WHERE export_id = :export_id";
                $getDetailsStmt = $pdo->prepare($getDetailsSql);
                $getDetailsStmt->bindParam(':export_id', $export['export_id']);
                $getDetailsStmt->execute();
                $details = $getDetailsStmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Hoàn hàng về kho (cộng lại vào inventory)
                foreach ($details as $detail) {
                    if ($detail['picked_quantity'] > 0) {
                        $restoreInventorySql = "
                            UPDATE inventory 
                            SET quantity = quantity + :quantity,
                                updated_at = NOW()
                            WHERE variant_id = :variant_id 
                            AND warehouse_id = :warehouse_id
                        ";
                        $restoreStmt = $pdo->prepare($restoreInventorySql);
                        $restoreStmt->bindParam(':quantity', $detail['picked_quantity']);
                        $restoreStmt->bindParam(':variant_id', $detail['variant_id']);
                        $restoreStmt->bindParam(':warehouse_id', $_SESSION['warehouse_id']);
                        $restoreStmt->execute();
                        
                        error_log("Restored inventory: variant_id={$detail['variant_id']}, quantity={$detail['picked_quantity']}");
                    }
                }
                
                // 2. Hủy phiếu xuất (cập nhật trạng thái phiếu xuất thành 'cancelled')
                $cancelExportSql = "UPDATE warehouse_exports SET status = 'cancelled', updated_at = NOW() WHERE export_id = :export_id";
                $cancelExportStmt = $pdo->prepare($cancelExportSql);
                $cancelExportStmt->bindParam(':export_id', $export['export_id']);
                $cancelExportStmt->execute();
                
                error_log("Cancelled export_id: {$export['export_id']}");
            }
            
            // 3. Hủy đơn hàng với lý do "Giao thất bại"
            $finalStatus = 'canceled';
            $cancellationReason = 'Giao hàng thất bại';
            $updateStatusQuery = "UPDATE orders SET status = :status, cancellation_reason = :reason, updated_at = CURRENT_TIMESTAMP WHERE order_id = :order_id";
            $updateStatusStmt = $pdo->prepare($updateStatusQuery);
            $updateStatusStmt->bindParam(':status', $finalStatus);
            $updateStatusStmt->bindParam(':reason', $cancellationReason);
            $updateStatusStmt->bindParam(':order_id', $orderId);
            $updateStatusStmt->execute();
            
        } else {
            // Cập nhật trạng thái bình thường
            if ($newStatus === 'canceled') {
                // Hủy thủ công - lý do: "Do thao tác xử lý"
                $cancellationReason = 'Do thao tác xử lý';
                $updateStatusQuery = "UPDATE orders SET status = :status, cancellation_reason = :reason, updated_at = CURRENT_TIMESTAMP WHERE order_id = :order_id";
                $updateStatusStmt = $pdo->prepare($updateStatusQuery);
                $updateStatusStmt->bindParam(':status', $newStatus);
                $updateStatusStmt->bindParam(':reason', $cancellationReason);
                $updateStatusStmt->bindParam(':order_id', $orderId);
                $updateStatusStmt->execute();
            } else {
                // Các trạng thái khác không cần lý do hủy
                $updateStatusQuery = "UPDATE orders SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE order_id = :order_id";
                $updateStatusStmt = $pdo->prepare($updateStatusQuery);
                $updateStatusStmt->bindParam(':status', $newStatus);
                $updateStatusStmt->bindParam(':order_id', $orderId);
                $updateStatusStmt->execute();
            }
        }
        
        // Ghi nhật ký audit trail
        $logSql = "INSERT INTO audit_logs (user_id, action, table_name, record_id, old_values, new_values, warehouse_id) 
                  VALUES (:user_id, 'update_order_status', 'orders', :record_id, :old_values, :new_values, :warehouse_id)";
        $logStmt = $pdo->prepare($logSql);
        
        $logData = [
            'status' => $newStatus === 'failed' ? 'canceled' : $newStatus,
            'reason' => $newStatus === 'failed' ? 'Giao hàng thất bại - Đã hoàn hàng về kho và hủy phiếu xuất' : null
        ];
        
        $oldValues = json_encode(['status' => $currentStatus]);
        $newValues = json_encode($logData);
        $warehouseId = $_SESSION['warehouse_id'];
        
        $logStmt->bindParam(':user_id', $userId);
        $logStmt->bindParam(':record_id', $orderId);
        $logStmt->bindParam(':old_values', $oldValues);
        $logStmt->bindParam(':new_values', $newValues);
        $logStmt->bindParam(':warehouse_id', $warehouseId);
        $logStmt->execute();
        
        // Commit transaction
        $pdo->commit();
        
        $message = $newStatus === 'failed' 
            ? 'Đã xác nhận giao hàng thất bại. Hàng đã được hoàn về kho, phiếu xuất và đơn hàng đã được hủy.' 
            : 'Cập nhật trạng thái đơn hàng thành công';
        
        echo json_encode([
            'success' => true,
            'message' => $message
        ]);
        
    } catch (Exception $e) {
        // Hoàn tác giao dịch
        $pdo->rollback();
        throw $e;
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>