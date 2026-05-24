<?php
// Suppress all PHP errors and warnings for clean JSON output
error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json');

// session_start();
require_once __DIR__ . '/../../config/database.php';

try {
    
    // Kiểm tra đăng nhập
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Chưa đăng nhập. Vui lòng đăng nhập lại.');
    }
    
    // Kiểm tra quyền: chỉ Manager và Admin được phép xác nhận đơn hàng
    $userRole = $_SESSION['role'] ?? 'staff';
    if (!in_array($userRole, ['admin', 'manager'])) {
        throw new Exception('Bạn không có quyền xác nhận đơn hàng. Chỉ Manager và Admin mới có quyền này.');
    }
    
    $database = new Database();
    $pdo = $database->getConnection();
    
    // Read JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Invalid JSON input');
    }
    
    $orderId = $input['order_id'] ?? null;
    $status = $input['status'] ?? null;
    
    if (!$orderId || !$status) {
        throw new Exception('Order ID and status are required');
    }
    
    // Kiểm tra trạng thái
    if (!in_array($status, ['accepted', 'canceled'])) {
        throw new Exception('Invalid status. Must be accepted or canceled');
    }
    
    // Kiểm tra quyền truy cập warehouse
    if (!isset($_SESSION['warehouse_id'])) {
        throw new Exception('Không xác định được kho hàng');
    }
    
    $pdo->beginTransaction();
    
    try {
        // Kiểm tra xem đơn hàng có tồn tại không (chỉ trong warehouse của user)
        $checkSql = "SELECT * FROM orders WHERE order_id = :order_id AND warehouse_id = :warehouse_id";
        $checkStmt = $pdo->prepare($checkSql);
        $checkStmt->bindParam(':order_id', $orderId, PDO::PARAM_INT);
        $checkStmt->bindParam(':warehouse_id', $_SESSION['warehouse_id'], PDO::PARAM_INT);
        $checkStmt->execute();
        
        $orderExists = $checkStmt->fetch(PDO::FETCH_ASSOC);
        if (!$orderExists) {
            throw new Exception('Order not found');
        }
        
        // Cập nhật trạng thái đơn hàng với lý do huỷ nếu bị huỷ
        if ($status === 'canceled') {
            $cancellationReason = 'Do thao tác xử lý';
            $updateSql = "UPDATE orders SET status = :status, cancellation_reason = :reason, updated_at = NOW() WHERE order_id = :order_id";
            $updateStmt = $pdo->prepare($updateSql);
            $updateStmt->bindParam(':status', $status);
            $updateStmt->bindParam(':reason', $cancellationReason);
            $updateStmt->bindParam(':order_id', $orderId, PDO::PARAM_INT);
        } else {
            $updateSql = "UPDATE orders SET status = :status, updated_at = NOW() WHERE order_id = :order_id";
            $updateStmt = $pdo->prepare($updateSql);
            $updateStmt->bindParam(':status', $status);
            $updateStmt->bindParam(':order_id', $orderId, PDO::PARAM_INT);
        }
        
        $updateStmt->execute();
        
        if ($updateStmt->rowCount() === 0) {
            throw new Exception('Order status update failed');
        }
        
        // If accepted, try to create warehouse export slip (optional)
        if ($status === 'accepted') {
            try {
                // Kiểm tra xem bảng warehouse_exports có tồn tại không
                $tableCheckSql = "SHOW TABLES LIKE 'warehouse_exports'";
                $tableCheckStmt = $pdo->query($tableCheckSql);
                
                if ($tableCheckStmt->rowCount() > 0) {
                    // Lấy thông tin đơn hàng và khách hàng
                    $orderInfoSql = "
                        SELECT 
                            o.order_id,
                            o.warehouse_id,
                            COALESCE(c.full_name, 'Khách lẻ') as customer_name
                        FROM orders o
                        LEFT JOIN customers c ON o.customer_id = c.customer_id
                        WHERE o.order_id = :order_id
                    ";
                    
                    $orderInfoStmt = $pdo->prepare($orderInfoSql);
                    $orderInfoStmt->bindParam(':order_id', $orderId, PDO::PARAM_INT);
                    $orderInfoStmt->execute();
                    $orderData = $orderInfoStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($orderData) {
                        // Tạo mã phiếu xuất
                        $exportCode = 'EXP' . date('Ymd') . str_pad($orderId, 4, '0', STR_PAD_LEFT);
                        
                        // Tạo phiếu xuất
                        $exportSql = "
                            INSERT INTO warehouse_exports (
                                order_id, warehouse_id, export_code, customer_name, 
                                status, created_at
                            ) VALUES (
                                :order_id, :warehouse_id, :export_code, :customer_name,
                                'pending', NOW()
                            )
                        ";
                        
                        $exportStmt = $pdo->prepare($exportSql);
                        $exportStmt->bindParam(':order_id', $orderId, PDO::PARAM_INT);
                        $exportStmt->bindParam(':warehouse_id', $orderData['warehouse_id'], PDO::PARAM_INT);
                        $exportStmt->bindParam(':export_code', $exportCode);
                        $exportStmt->bindParam(':customer_name', $orderData['customer_name']);
                        $exportStmt->execute();
                        
                        $exportId = $pdo->lastInsertId();
                        
                        // Lấy tất cả chi tiết đơn hàng
                        $orderDetailsSql = "
                            SELECT variant_id, quantity, unit_price, total_price
                            FROM order_details 
                            WHERE order_id = :order_id
                        ";
                        
                        $orderDetailsStmt = $pdo->prepare($orderDetailsSql);
                        $orderDetailsStmt->bindParam(':order_id', $orderId, PDO::PARAM_INT);
                        $orderDetailsStmt->execute();
                        $orderDetails = $orderDetailsStmt->fetchAll(PDO::FETCH_ASSOC);
                        
                        // Tạo chi tiết phiếu xuất nếu bảng tồn tại
                        $detailTableCheckSql = "SHOW TABLES LIKE 'warehouse_export_details'";
                        $detailTableCheckStmt = $pdo->query($detailTableCheckSql);
                        
                        if ($detailTableCheckStmt->rowCount() > 0 && !empty($orderDetails)) {
                            $detailSql = "
                                INSERT INTO warehouse_export_details (
                                    export_id, variant_id, quantity, unit_price, total_price
                                ) VALUES (
                                    :export_id, :variant_id, :quantity, :unit_price, :total_price
                                )
                            ";
                            
                            $detailStmt = $pdo->prepare($detailSql);
                            
                            foreach ($orderDetails as $detail) {
                                $detailStmt->bindParam(':export_id', $exportId, PDO::PARAM_INT);
                                $detailStmt->bindParam(':variant_id', $detail['variant_id'], PDO::PARAM_INT);
                                $detailStmt->bindParam(':quantity', $detail['quantity'], PDO::PARAM_INT);
                                $detailStmt->bindParam(':unit_price', $detail['unit_price']);
                                $detailStmt->bindParam(':total_price', $detail['total_price']);
                                $detailStmt->execute();
                            }
                        }
                        
                        // === YÊU CẦU MỚI: TRỪ TỒN KHO NGAY KHI CHẤP NHẬN ĐƠN HÀNG ===
                        // Trừ tồn kho cho tất cả sản phẩm trong đơn hàng
                        foreach ($orderDetails as $detail) {
                            reduceInventory($pdo, $detail['variant_id'], $detail['quantity'], $orderData['warehouse_id']);
                        }
                        
                        // Ghi log vào audit_logs
                        $auditSql = "
                            INSERT INTO audit_logs (
                                user_id, action, table_name, record_id, 
                                new_values, warehouse_id, created_at
                            ) VALUES (
                                :user_id, 'accept_order_reduce_inventory', 'warehouse_exports', :export_id,
                                :new_values, :warehouse_id, NOW()
                            )
                        ";
                        
                        $auditStmt = $pdo->prepare($auditSql);
                        $auditStmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
                        $auditStmt->bindParam(':export_id', $exportId, PDO::PARAM_INT);
                        $auditValues = json_encode([
                            'export_id' => $exportId,
                            'export_code' => $exportCode,
                            'order_id' => $orderId,
                            'items_count' => count($orderDetails),
                            'note' => 'Inventory reduced when order accepted (new logic)'
                        ]);
                        $auditStmt->bindParam(':new_values', $auditValues);
                        $auditStmt->bindParam(':warehouse_id', $orderData['warehouse_id'], PDO::PARAM_INT);
                        $auditStmt->execute();
                    }
                }
            } catch (Exception $exportError) {
                // Xuất creation failed, rollback transaction
                throw new Exception('Failed to create export slip or reduce inventory: ' . $exportError->getMessage());
            }
        }
        
        $pdo->commit();
        
        // Trả về thành công
        echo json_encode([
            'success' => true,
            'message' => $status === 'accepted' ? 'Order accepted successfully' : 'Order cancelled successfully',
            'order_id' => $orderId,
            'status' => $status
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'debug' => [
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]
    ]);
}

/**
 * Hàm trừ tồn kho khi chấp nhận đơn hàng
 * Sử dụng FIFO (First In First Out) để trừ từ các vị trí
 */
function reduceInventory($pdo, $variantId, $quantity, $warehouseId) {
    // Lấy danh sách vị trí có tồn kho, sắp xếp theo FIFO
    $locationsSql = "
        SELECT inventory_id, location_id, quantity 
        FROM inventory 
        WHERE variant_id = :variant_id 
        AND warehouse_id = :warehouse_id 
        AND quantity > 0
        ORDER BY inventory_id ASC
    ";
    
    $locationsStmt = $pdo->prepare($locationsSql);
    $locationsStmt->bindParam(':variant_id', $variantId, PDO::PARAM_INT);
    $locationsStmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
    $locationsStmt->execute();
    $locations = $locationsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($locations)) {
        throw new Exception("Không đủ tồn kho cho variant_id: $variantId");
    }
    
    // Tính tổng tồn kho hiện có
    $totalAvailable = array_sum(array_column($locations, 'quantity'));
    if ($totalAvailable < $quantity) {
        throw new Exception("Không đủ tồn kho. Yêu cầu: $quantity, Có sẵn: $totalAvailable (variant_id: $variantId)");
    }
    
    // Trừ tồn kho theo FIFO
    $remainingQty = $quantity;
    foreach ($locations as $location) {
        if ($remainingQty <= 0) break;
        
        $deductQty = min($remainingQty, $location['quantity']);
        
        $updateSql = "
            UPDATE inventory 
            SET quantity = quantity - :deduct_qty,
                updated_at = NOW()
            WHERE inventory_id = :inventory_id
            AND quantity >= :deduct_qty
        ";
        
        $updateStmt = $pdo->prepare($updateSql);
        $updateStmt->bindParam(':deduct_qty', $deductQty, PDO::PARAM_INT);
        $updateStmt->bindParam(':inventory_id', $location['inventory_id'], PDO::PARAM_INT);
        $updateStmt->execute();
        
        if ($updateStmt->rowCount() === 0) {
            throw new Exception("Không thể trừ tồn kho tại inventory_id: {$location['inventory_id']}");
        }
        
        $remainingQty -= $deductQty;
    }
    
    if ($remainingQty > 0) {
        throw new Exception("Trừ tồn kho không hoàn tất. Còn lại: $remainingQty");
    }
}
?>
