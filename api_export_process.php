<?php
// Enable error reporting for debugging - log only, don't display
error_reporting(E_ALL);
ini_set('display_errors', 0); // MUST BE 0 for JSON API
ini_set('log_errors', 1);

// Set content type FIRST before any output
header('Content-Type: application/json; charset=utf-8');

session_start();
require_once 'config/database.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$database = new Database();
$pdo = $database->getConnection();

$userId = $_SESSION['user_id'];
$warehouseId = $_SESSION['warehouse_id'];

// Đọc dữ liệu JSON từ request
$rawInput = file_get_contents('php://input');
error_log("API Request body: " . $rawInput);

$input = json_decode($rawInput, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON: ' . json_last_error_msg()]);
    exit;
}

$action = $input['action'] ?? '';
error_log("API Action: " . $action);

try {
    switch ($action) {
        case 'update_picked_quantity':
            updatePickedQuantity($pdo, $input, $userId, $warehouseId);
            break;
            
        case 'complete_export':
            completeExport($pdo, $input, $userId, $warehouseId);
            break;
            
        case 'cancel_export':
            cancelExport($pdo, $input, $userId, $warehouseId);
            break;
            
        default:
            throw new Exception('Invalid action: ' . $action);
    }
} catch (Exception $e) {
    error_log("API Error: " . $e->getMessage());
    error_log("API Error Trace: " . $e->getTraceAsString());
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'message' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine(),
        'trace' => explode("\n", $e->getTraceAsString())
    ]);
} catch (PDOException $e) {
    error_log("Database Error: " . $e->getMessage());
    error_log("Database Error Trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Database error: ' . $e->getMessage(),
        'code' => $e->getCode()
    ]);
}

// Hàm cập nhật số lượng đã lấy
function updatePickedQuantity($pdo, $input, $userId, $warehouseId) {
    $exportId = $input['export_id'] ?? null;
    $detailId = $input['detail_id'] ?? null;
    $pickedQuantity = $input['picked_quantity'] ?? null;
    
    if (!$exportId || !$detailId || $pickedQuantity === null) {
        throw new Exception('Missing required parameters');
    }
    
    // Kiểm tra quyền truy cập phiếu xuất
    $checkSql = "
        SELECT we.export_id, we.status, wed.quantity as required_quantity, wed.variant_id
        FROM warehouse_exports we
        JOIN warehouse_export_details wed ON we.export_id = wed.export_id
        WHERE we.export_id = :export_id 
        AND wed.detail_id = :detail_id 
        AND we.warehouse_id = :warehouse_id
        AND we.status IN ('pending', 'processing')
    ";
    
    $checkStmt = $pdo->prepare($checkSql);
    $checkStmt->bindParam(':export_id', $exportId, PDO::PARAM_INT);
    $checkStmt->bindParam(':detail_id', $detailId, PDO::PARAM_INT);
    $checkStmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
    $checkStmt->execute();
    $exportDetail = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$exportDetail) {
        throw new Exception('Export not found or access denied');
    }
    
    // Kiểm tra số lượng hợp lệ
    if ($pickedQuantity < 0) {
        throw new Exception('Số lượng không hợp lệ');
    }
    
    if ($pickedQuantity != $exportDetail['required_quantity']) {
        throw new Exception('Số lượng lấy không hợp lệ. Yêu cầu: ' . $exportDetail['required_quantity'] . ', Đã nhập: ' . $pickedQuantity);
    }
    
    // Bắt đầu transaction
    $pdo->beginTransaction();
    
    try {
        // Cập nhật số lượng đã lấy
        $updateSql = "
            UPDATE warehouse_export_details 
            SET picked_quantity = :picked_quantity,
                picked_at = NOW(),
                picked_by = :user_id
            WHERE detail_id = :detail_id
        ";
        
        $updateStmt = $pdo->prepare($updateSql);
        $updateStmt->bindParam(':picked_quantity', $pickedQuantity, PDO::PARAM_INT);
        $updateStmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $updateStmt->bindParam(':detail_id', $detailId, PDO::PARAM_INT);
        $updateStmt->execute();
        
        // Chỉ commit nếu transaction còn active
        if ($pdo->inTransaction()) {
            $pdo->commit();
        } else {
            error_log('WARNING: Transaction was already closed before commit');
        }
        
        // ⚠️ KHÔNG TRỪ TỒN KHO Ở ĐÂY - ĐÃ TRỪ KHI CHẤP NHẬN ĐƠN HÀNG
        // Inventory was already reduced when manager accepted the order
        // See: api/confirm_order.php - reduceInventory() function
        error_log("updatePickedQuantity: Skipping inventory reduction (already reduced when order accepted)");
        
        // Log audit
        logAudit($pdo, $userId, 'update_picked_quantity', 'warehouse_export_details', $detailId, [
            'export_id' => $exportId,
            'detail_id' => $detailId,
            'variant_id' => $exportDetail['variant_id'],
            'picked_quantity' => $pickedQuantity,
            'required_quantity' => $exportDetail['required_quantity'],
            'note' => 'Inventory already reduced when order accepted'
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Đã cập nhật số lượng lấy thành công'
        ]);
        
    } catch (Exception $e) {
        // Chỉ rollback nếu transaction còn active
        try {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (Exception $ex) {
            error_log('Rollback error: ' . $ex->getMessage());
        }
        throw $e;
    }
}

// Hàm hoàn thành phiếu xuất
function completeExport($pdo, $input, $userId, $warehouseId) {
    error_log("completeExport called with export_id: " . ($input['export_id'] ?? 'NULL'));
    
    $exportId = $input['export_id'] ?? null;
    
    if (!$exportId) {
        throw new Exception('Missing export ID');
    }
    
    // Kiểm tra quyền truy cập và trạng thái
    $checkSql = "
        SELECT export_id, status, 
               (SELECT COUNT(*) FROM warehouse_export_details WHERE export_id = :export_id) as total_items,
               (SELECT COUNT(*) FROM warehouse_export_details 
                WHERE export_id = :export_id AND COALESCE(picked_quantity, 0) >= quantity) as completed_items
        FROM warehouse_exports 
        WHERE export_id = :export_id 
        AND warehouse_id = :warehouse_id
        AND status IN ('pending', 'processing')
    ";
    
    try {
        $checkStmt = $pdo->prepare($checkSql);
        $checkStmt->bindParam(':export_id', $exportId, PDO::PARAM_INT);
        $checkStmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
        $checkStmt->execute();
        $exportInfo = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        error_log("Export info: " . json_encode($exportInfo));
    } catch (PDOException $e) {
        error_log("Database error in check query: " . $e->getMessage());
        throw new Exception('Database error: ' . $e->getMessage());
    }
    
    if (!$exportInfo) {
        throw new Exception('Export not found or access denied');
    }
    
    // Kiểm tra tất cả sản phẩm đã được lấy đủ
    if ($exportInfo['completed_items'] < $exportInfo['total_items']) {
        throw new Exception('Chưa lấy đủ tất cả sản phẩm. Đã hoàn thành: ' . $exportInfo['completed_items'] . '/' . $exportInfo['total_items']);
    }
    
    // Bắt đầu transaction
    $pdo->beginTransaction();
    
    try {
        // Kiểm tra xem các cột completed_at và completed_by có tồn tại không
        $columnsCheck = $pdo->query("SHOW COLUMNS FROM warehouse_exports LIKE 'completed_%'");
        $existingColumns = $columnsCheck->fetchAll(PDO::FETCH_COLUMN);
        
        error_log("Existing completed columns: " . json_encode($existingColumns));
        
        // Cập nhật trạng thái phiếu xuất thành "completed" (Đã đóng gói)
        // Chỉ update các cột tồn tại
        $setClause = "status = 'completed', updated_at = NOW()";
        
        if (in_array('completed_at', $existingColumns)) {
            $setClause .= ", completed_at = NOW()";
        }
        if (in_array('completed_by', $existingColumns)) {
            $setClause .= ", completed_by = :user_id";
        }
        
        $updateSql = "
            UPDATE warehouse_exports 
            SET {$setClause}
            WHERE export_id = :export_id
        ";
        
        error_log("Update SQL: " . $updateSql);
        
        $updateStmt = $pdo->prepare($updateSql);
        if (in_array('completed_by', $existingColumns)) {
            $updateStmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        }
        $updateStmt->bindParam(':export_id', $exportId, PDO::PARAM_INT);
        $updateStmt->execute();
        
        // Cập nhật trạng thái đơn hàng thành "delivered" nếu cần
        $orderUpdateSql = "
            UPDATE orders o
            JOIN warehouse_exports we ON o.order_id = we.order_id
            SET o.status = 'delivered',
                o.updated_at = NOW()
            WHERE we.export_id = :export_id
            AND o.status = 'accepted'
        ";
        
        $orderUpdateStmt = $pdo->prepare($orderUpdateSql);
        $orderUpdateStmt->bindParam(':export_id', $exportId, PDO::PARAM_INT);
        $orderUpdateStmt->execute();
        
        // Log audit
        logAudit($pdo, $userId, 'complete_export', 'warehouse_exports', $exportId, [
            'export_id' => $exportId,
            'status' => 'completed',
            'completed_items' => $exportInfo['completed_items'],
            'total_items' => $exportInfo['total_items']
        ]);
        
        // Chỉ commit nếu transaction còn active
        if ($pdo->inTransaction()) {
            $pdo->commit();
        } else {
            error_log('WARNING: Transaction was already closed before commit in completeExport');
            throw new Exception('Transaction was closed unexpectedly');
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Phiếu xuất đã được hoàn thành và chuyển sang trạng thái "Đã đóng gói"'
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// Hàm hủy phiếu xuất
function cancelExport($pdo, $input, $userId, $warehouseId) {
    $exportId = $input['export_id'] ?? null;
    
    if (!$exportId) {
        throw new Exception('Missing export ID');
    }
    
    // Kiểm tra quyền truy cập và trạng thái
    $checkSql = "
        SELECT export_id, status, order_id
        FROM warehouse_exports 
        WHERE export_id = :export_id 
        AND warehouse_id = :warehouse_id
        AND status = 'pending'
    ";
    
    $checkStmt = $pdo->prepare($checkSql);
    $checkStmt->bindParam(':export_id', $exportId, PDO::PARAM_INT);
    $checkStmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
    $checkStmt->execute();
    $exportInfo = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$exportInfo) {
        throw new Exception('Export not found, access denied, or cannot be cancelled');
    }
    
    // Bắt đầu transaction
    $pdo->beginTransaction();
    
    try {
        // Lấy danh sách sản phẩm đã được lấy để hoàn trả inventory
        $detailsSql = "
            SELECT variant_id, COALESCE(picked_quantity, 0) as picked_quantity
            FROM warehouse_export_details
            WHERE export_id = :export_id AND COALESCE(picked_quantity, 0) > 0
        ";
        
        $detailsStmt = $pdo->prepare($detailsSql);
        $detailsStmt->bindParam(':export_id', $exportId, PDO::PARAM_INT);
        $detailsStmt->execute();
        $pickedItems = $detailsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Hoàn trả số lượng vào inventory
        foreach ($pickedItems as $item) {
            updateInventory($pdo, $item['variant_id'], $item['picked_quantity'], $warehouseId, 'increase');
        }
        
        // Cập nhật trạng thái phiếu xuất
        $updateSql = "
            UPDATE warehouse_exports 
            SET status = 'cancelled',
                cancelled_at = NOW(),
                cancelled_by = :user_id,
                updated_at = NOW()
            WHERE export_id = :export_id
        ";
        
        $updateStmt = $pdo->prepare($updateSql);
        $updateStmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $updateStmt->bindParam(':export_id', $exportId, PDO::PARAM_INT);
        $updateStmt->execute();
        
        // Cập nhật trạng thái đơn hàng về "accepted" nếu cần
        $orderUpdateSql = "
            UPDATE orders 
            SET status = 'accepted',
                updated_at = NOW()
            WHERE order_id = :order_id
        ";
        
        $orderUpdateStmt = $pdo->prepare($orderUpdateSql);
        $orderUpdateStmt->bindParam(':order_id', $exportInfo['order_id'], PDO::PARAM_INT);
        $orderUpdateStmt->execute();
        
        // Log audit
        logAudit($pdo, $userId, 'cancel_export', 'warehouse_exports', $exportId, [
            'export_id' => $exportId,
            'order_id' => $exportInfo['order_id'],
            'status' => 'cancelled',
            'returned_items' => count($pickedItems)
        ]);
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Phiếu xuất đã được hủy thành công'
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// Hàm cập nhật inventory
function updateInventory($pdo, $variantId, $quantity, $warehouseId, $operation = 'decrease') {
    try {
        // Sử dụng bảng inventory với location
        if ($operation == 'decrease') {
            // Giảm tồn kho từ các vị trí (FIFO - First In First Out)
            $locationsSql = "
                SELECT location_id, quantity 
                FROM inventory 
                    WHERE variant_id = :variant_id AND warehouse_id = :warehouse_id AND quantity > 0
                    ORDER BY inventory_id ASC
                ";
                
                $locationsStmt = $pdo->prepare($locationsSql);
                $locationsStmt->bindParam(':variant_id', $variantId, PDO::PARAM_INT);
                $locationsStmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
                $locationsStmt->execute();
                $locations = $locationsStmt->fetchAll(PDO::FETCH_ASSOC);
                
                $remainingQty = $quantity;
                foreach ($locations as $location) {
                    if ($remainingQty <= 0) break;
                    
                    $deductQty = min($remainingQty, $location['quantity']);
                    
                    $updateSql = "
                        UPDATE inventory 
                        SET quantity = quantity - :deduct_qty,
                            updated_at = NOW()
                        WHERE variant_id = :variant_id 
                        AND warehouse_id = :warehouse_id 
                        AND location_id = :location_id
                    ";
                    
                    $updateStmt = $pdo->prepare($updateSql);
                    $updateStmt->bindParam(':deduct_qty', $deductQty, PDO::PARAM_INT);
                    $updateStmt->bindParam(':variant_id', $variantId, PDO::PARAM_INT);
                    $updateStmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
                    $updateStmt->bindParam(':location_id', $location['location_id'], PDO::PARAM_INT);
                    $updateStmt->execute();
                    
                    $remainingQty -= $deductQty;
                }
            } else {
                // Tăng tồn kho (hoàn trả) - thêm vào vị trí mặc định
                $defaultLocationSql = "
                    SELECT location_id FROM locations 
                    WHERE warehouse_id = :warehouse_id AND shelf_code LIKE 'A-1-%'
                    ORDER BY location_id ASC LIMIT 1
                ";
                
                $defaultLocationStmt = $pdo->prepare($defaultLocationSql);
                $defaultLocationStmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
                $defaultLocationStmt->execute();
                $defaultLocation = $defaultLocationStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($defaultLocation) {
                    $insertOrUpdateSql = "
                        INSERT INTO inventory (variant_id, warehouse_id, location_id, quantity, updated_at)
                        VALUES (:variant_id, :warehouse_id, :location_id, :quantity, NOW())
                        ON DUPLICATE KEY UPDATE 
                        quantity = quantity + :quantity,
                        updated_at = NOW()
                    ";
                    
                    $insertOrUpdateStmt = $pdo->prepare($insertOrUpdateSql);
                    $insertOrUpdateStmt->bindParam(':variant_id', $variantId, PDO::PARAM_INT);
                    $insertOrUpdateStmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
                    $insertOrUpdateStmt->bindParam(':location_id', $defaultLocation['location_id'], PDO::PARAM_INT);
                    $insertOrUpdateStmt->bindParam(':quantity', $quantity, PDO::PARAM_INT);
                    $insertOrUpdateStmt->execute();
                }
            }
        
    } catch (Exception $e) {
        // Ghi log lỗi nhưng không dừng process
        error_log("Inventory update error: " . $e->getMessage());
    }
}

// Hàm ghi log audit
function logAudit($pdo, $userId, $action, $tableName, $recordId, $details) {
    try {
        $insertSql = "
            INSERT INTO audit_logs (user_id, action, table_name, record_id, new_values, warehouse_id, created_at, details)
            VALUES (:user_id, :action, :table_name, :record_id, :new_values, :warehouse_id, NOW(), :details)
        ";
        $detailsJson = json_encode($details);
        $warehouseId = $_SESSION['warehouse_id'] ?? 1;
        $insertStmt = $pdo->prepare($insertSql);
        $insertStmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $insertStmt->bindParam(':action', $action);
        $insertStmt->bindParam(':table_name', $tableName);
        $insertStmt->bindParam(':record_id', $recordId, PDO::PARAM_INT);
        $insertStmt->bindParam(':new_values', $detailsJson);
        $insertStmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
        $insertStmt->bindParam(':details', $detailsJson);
        $insertStmt->execute();
    } catch (Exception $e) {
        // Ghi log lỗi nhưng KHÔNG throw, không ảnh hưởng transaction chính
        error_log("Audit log error: " . $e->getMessage());
    }
}
?>
