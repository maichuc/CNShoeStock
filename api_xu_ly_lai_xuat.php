<?php
// Tắt hiển thị lỗi để tránh làm hỏng JSON response
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Bắt đầu output buffering để tránh output không mong muốn
ob_start();

session_start();
require_once 'config/cau_hinh_csdl.php';

// Clear any output buffer trước khi set header
ob_end_clean();

header('Content-Type: application/json');

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Vui lòng đăng nhập để tiếp tục'
    ]);
    exit;
}

$database = new Database();
$pdo = $database->getConnection();

$userId = $_SESSION['user_id'];
$warehouseId = $_SESSION['warehouse_id'];

try {
    // Nhận dữ liệu
    $data = json_decode(file_get_contents('php://input'), true);
    $exportId = $data['export_id'] ?? null;

    if (!$exportId) {
        throw new Exception('Thiếu thông tin mã phiếu xuất');
    }

    // Bắt đầu transaction
    $pdo->beginTransaction();

    // Kiểm tra phiếu xuất có tồn tại và thuộc warehouse không
    $checkSql = "
        SELECT 
            we.export_id,
            we.export_code,
            we.status,
            we.order_id,
            o.status as order_status
        FROM warehouse_exports we
        JOIN orders o ON we.order_id = o.order_id
        WHERE we.export_id = :export_id 
        AND we.warehouse_id = :warehouse_id
    ";
    
    $checkStmt = $pdo->prepare($checkSql);
    $checkStmt->bindParam(':export_id', $exportId, PDO::PARAM_INT);
    $checkStmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
    $checkStmt->execute();
    $export = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$export) {
        throw new Exception('Không tìm thấy phiếu xuất hoặc bạn không có quyền truy cập');
    }

    // Kiểm tra trạng thái phải là "cancelled"
    if ($export['status'] !== 'cancelled') {
        throw new Exception('Chỉ có thể xử lý lại đơn hàng ở trạng thái "Đã hủy"');
    }

    // Bước 1: Reset picked_quantity về 0 cho tất cả items (vì đã hoàn trả hàng về kho)
    $resetPickedSql = "
        UPDATE warehouse_export_details 
        SET 
            picked_quantity = 0,
            picked_at = NULL,
            picked_by = NULL
        WHERE export_id = :export_id
    ";
    
    $resetStmt = $pdo->prepare($resetPickedSql);
    $resetStmt->bindParam(':export_id', $exportId, PDO::PARAM_INT);
    $resetStmt->execute();
    
    $resetCount = $resetStmt->rowCount();
    error_log("Reset picked_quantity for $resetCount items in export_id: $exportId");
    
    // Bước 2: TRỪ LẠI TỒN KHO (vì đã được hoàn trả khi hủy đơn)
    // Lấy danh sách sản phẩm cần trừ tồn kho
    $getItemsSql = "
        SELECT variant_id, quantity
        FROM warehouse_export_details
        WHERE export_id = :export_id
    ";
    
    $getItemsStmt = $pdo->prepare($getItemsSql);
    $getItemsStmt->bindParam(':export_id', $exportId, PDO::PARAM_INT);
    $getItemsStmt->execute();
    $items = $getItemsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Trừ tồn kho cho từng sản phẩm
    foreach ($items as $item) {
        reduceInventoryForReprocess($pdo, $item['variant_id'], $item['quantity'], $warehouseId);
    }
    
    error_log("Reduced inventory for " . count($items) . " items when reprocessing export_id: $exportId");
    
    // Bước 3: Cập nhật trạng thái phiếu xuất từ "cancelled" sang "processing"
    $updateExportSql = "
        UPDATE warehouse_exports 
        SET 
            status = 'processing',
            processed_by = :user_id,
            updated_at = NOW()
        WHERE export_id = :export_id
    ";
    
    $updateExportStmt = $pdo->prepare($updateExportSql);
    $updateExportStmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
    $updateExportStmt->bindParam(':export_id', $exportId, PDO::PARAM_INT);
    $updateExportStmt->execute();

    // Bước 4: Cập nhật trạng thái đơn hàng về "processing" nếu đang ở trạng thái "cancelled" hoặc "processing"
    if (in_array($export['order_status'], ['cancelled', 'processing'])) {
        $updateOrderSql = "
            UPDATE orders 
            SET status = 'processing',
                updated_at = NOW()
            WHERE order_id = :order_id
        ";
        
        $updateOrderStmt = $pdo->prepare($updateOrderSql);
        $updateOrderStmt->bindParam(':order_id', $export['order_id'], PDO::PARAM_INT);
        $updateOrderStmt->execute();
    }

    // Ghi log audit
    $logSql = "
        INSERT INTO audit_logs 
        (user_id, action, table_name, record_id, new_values, created_at)
        VALUES 
        (:user_id, 'reprocess_export', 'warehouse_exports', :export_id, :new_values, NOW())
    ";
    
    $newValues = json_encode([
        'export_id' => $exportId,
        'export_code' => $export['export_code'],
        'status' => 'processing',
        'previous_status' => 'cancelled',
        'reprocessed_by' => $userId,
        'items_reset' => $resetCount,
        'items_inventory_reduced' => count($items),
        'note' => 'Xử lý lại đơn hàng đã hủy - Reset picked_quantity về 0 - Đã trừ lại tồn kho cho các sản phẩm'
    ]);
    
    $logStmt = $pdo->prepare($logSql);
    $logStmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
    $logStmt->bindParam(':export_id', $exportId, PDO::PARAM_INT);
    $logStmt->bindParam(':new_values', $newValues);
    $logStmt->execute();

    // Commit transaction
    $pdo->commit();
    echo json_encode([
        'success' => true,
        'message' => sprintf(
            'Đã chuyển đơn hàng sang trạng thái "Đang xử lý". Reset %d sản phẩm về trạng thái chưa lấy. Đã trừ lại tồn kho cho %d sản phẩm.',
            $resetCount,
            count($items)
        ),
        'export_code' => $export['export_code'],
        'new_status' => 'processing',
        'items_reset' => $resetCount,
        'items_inventory_reduced' => count($items)
    ]);

} catch (Exception $e) {
    // Rollback nếu có lỗi
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log("Reprocess export error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error_details' => [
            'file' => basename($e->getFile()),
            'line' => $e->getLine()
        ]
    ]);
} catch (PDOException $e) {
    // Rollback database error
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log("Database error in reprocess: " . $e->getMessage());
    
    echo json_encode([
        'error_code' => 'DATABASE_ERROR'
    ]);
}

/**
 * Hàm trừ tồn kho khi xử lý lại đơn hàng đã hủy
 * Sử dụng FIFO (First In First Out) để trừ từ các vị trí
 */
function reduceInventoryForReprocess($pdo, $variantId, $quantity, $warehouseId) {
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
        throw new Exception("Không đủ tồn kho cho variant_id: $variantId khi xử lý lại đơn hàng");
    }
    
    // Tính tổng tồn kho hiện có
    $totalAvailable = array_sum(array_column($locations, 'quantity'));
    if ($totalAvailable < $quantity) {
        throw new Exception("Không đủ tồn kho để xử lý lại. Yêu cầu: $quantity, Có sẵn: $totalAvailable (variant_id: $variantId)");
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
            throw new Exception("Không thể trừ tồn kho tại inventory_id: {$location['inventory_id']} khi xử lý lại đơn hàng");
        }
        
        $remainingQty -= $deductQty;
    }
    
    if ($remainingQty > 0) {
        throw new Exception("Trừ tồn kho không hoàn tất khi xử lý lại. Còn lại: $remainingQty");
    }
}
?>
