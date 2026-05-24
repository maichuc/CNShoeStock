<?php
// Suppress all PHP errors and warnings for clean JSON output
error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');

/**
 * API XỬ LÝ XUẤT KHO
 * Xử lý các hành động: cập nhật số lượng đã lấy, hoàn thành phiếu xuất, hủy phiếu xuất
 */

// session_start();
require_once __DIR__ . '/../../config/database.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Kết nối database
$database = new Database();
$pdo = $database->getConnection();

// Lấy thông tin user từ session
$userId = $_SESSION['user_id'];
$warehouseId = $_SESSION['warehouse_id'];

// Đọc dữ liệu JSON từ request body
$rawInput = file_get_contents('php://input');
error_log("API Request body: " . $rawInput);

// Parse JSON
$input = json_decode($rawInput, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON: ' . json_last_error_msg()]);
    exit;
}

// Lấy action từ request
$action = $input['action'] ?? '';
error_log("API Action: " . $action);

// Router: phân phối request đến hàm xử lý tương ứng
try {
    switch ($action) {
        case 'update_picked_quantity': // Cập nhật số lượng đã lấy
            updatePickedQuantity($pdo, $input, $userId, $warehouseId);
            break;
            
        case 'complete_export': // Hoàn thành phiếu xuất
            completeExport($pdo, $input, $userId, $warehouseId);
            break;
            
        case 'cancel_export': // Hủy phiếu xuất
            cancelExport($pdo, $input, $userId, $warehouseId);
            break;
            
        default:
            throw new Exception('Invalid action: ' . $action);
    }
} catch (Exception $e) {
    // Xử lý lỗi thông thường
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
    // Xử lý lỗi database
    error_log("Database Error: " . $e->getMessage());
    error_log("Database Error Trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Database error: ' . $e->getMessage(),
        'code' => $e->getCode()
    ]);
}

/**
 * CẬP NHẬT SỐ LƯỢNG ĐÃ LẤY
 * Gọi khi nhân viên kho quét QR hoặc nhập thủ công số lượng
 */
function updatePickedQuantity($pdo, $input, $userId, $warehouseId) {
    // Lấy tham số từ request
    $exportId = $input['export_id'] ?? null;
    $detailId = $input['detail_id'] ?? null;
    $pickedQuantity = $input['picked_quantity'] ?? null;
    
    // Validate tham số bắt buộc
    if (!$exportId || !$detailId || $pickedQuantity === null) {
        throw new Exception('Missing required parameters');
    }
    
    // Query kiểm tra quyền truy cập và lấy thông tin phiếu xuất
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
    
    // Kiểm tra phiếu xuất có tồn tại và thuộc kho của user
    if (!$exportDetail) {
        throw new Exception('Export not found or access denied');
    }
    
    // Validate số lượng không âm
    if ($pickedQuantity < 0) {
        throw new Exception('Số lượng không hợp lệ');
    }
    
    // Kiểm tra số lượng lấy phải khớp với yêu cầu
    if ($pickedQuantity != $exportDetail['required_quantity']) {
        throw new Exception('Số lượng lấy không hợp lệ. Yêu cầu: ' . $exportDetail['required_quantity'] . ', Đã nhập: ' . $pickedQuantity);
    }
    
    // Bắt đầu transaction để đảm bảo tính toàn vẹn dữ liệu
    $pdo->beginTransaction();
    
    try {
        // Update picked_quantity vào warehouse_export_details
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
        
        // Commit transaction nếu còn active
        if ($pdo->inTransaction()) {
            $pdo->commit();
        } else {
            error_log('WARNING: Transaction was already closed before commit');
        }
        
        // LƯU Ý: KHÔNG TRỪ TỒN KHO Ở ĐÂY
        // Tồn kho đã được trừ khi manager chấp nhận đơn hàng (api/api_xac_nhan_don_hang.php)
        error_log("updatePickedQuantity: Skipping inventory reduction (already reduced when order accepted)");
        
        // Ghi log audit để theo dõi
        logAudit($pdo, $userId, 'update_picked_quantity', 'warehouse_export_details', $detailId, [
            'export_id' => $exportId,
            'detail_id' => $detailId,
            'variant_id' => $exportDetail['variant_id'],
            'picked_quantity' => $pickedQuantity,
            'required_quantity' => $exportDetail['required_quantity'],
            'note' => 'Inventory already reduced when order accepted'
        ]);
        
        // Trả về JSON thành công
        echo json_encode([
            'success' => true,
            'message' => 'Đã cập nhật số lượng lấy thành công'
        ]);
        
    } catch (Exception $e) {
        // Rollback nếu có lỗi và transaction còn active
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

/**
 * HOÀN THÀNH PHIẾU XUẤT
 * Gọi khi nhân viên đã lấy đủ tất cả sản phẩm và đóng gói xong
 */
function completeExport($pdo, $input, $userId, $warehouseId) {
    error_log("completeExport called with export_id: " . ($input['export_id'] ?? 'NULL'));
    
    // Lấy export_id từ request
    $exportId = $input['export_id'] ?? null;
    
    // Validate export_id bắt buộc
    if (!$exportId) {
        throw new Exception('Missing export ID');
    }
    
    // Query lấy thông tin phiếu xuất và đếm số sản phẩm đã hoàn thành
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
    
    // Kiểm tra phiếu xuất có tồn tại và thuộc kho của user
    if (!$exportInfo) {
        throw new Exception('Export not found or access denied');
    }
    
    // Kiểm tra đã lấy đủ tất cả sản phẩm chưa
    if ($exportInfo['completed_items'] < $exportInfo['total_items']) {
        throw new Exception('Chưa lấy đủ tất cả sản phẩm. Đã hoàn thành: ' . $exportInfo['completed_items'] . '/' . $exportInfo['total_items']);
    }
    
    // Bắt đầu transaction
    $pdo->beginTransaction();
    
    try {
        // Kiểm tra các cột completed_at và completed_by có tồn tại không (tương thích database cũ)
        $columnsCheck = $pdo->query("SHOW COLUMNS FROM warehouse_exports LIKE 'completed_%'");
        $existingColumns = $columnsCheck->fetchAll(PDO::FETCH_COLUMN);
        
        error_log("Existing completed columns: " . json_encode($existingColumns));
        
        // Build SET clause động dựa vào cột tồn tại
        $setClause = "status = 'completed', updated_at = NOW()";
        
        if (in_array('completed_at', $existingColumns)) {
            $setClause .= ", completed_at = NOW()";
        }
        if (in_array('completed_by', $existingColumns)) {
            $setClause .= ", completed_by = :user_id";
        }
        
        // Update trạng thái phiếu xuất thành "completed"
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
        
        // Cập nhật trạng thái đơn hàng thành "delivered"
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
        
        // Ghi log audit
        logAudit($pdo, $userId, 'complete_export', 'warehouse_exports', $exportId, [
            'export_id' => $exportId,
            'status' => 'completed',
            'completed_items' => $exportInfo['completed_items'],
            'total_items' => $exportInfo['total_items']
        ]);
        
        // Commit transaction nếu còn active
        if ($pdo->inTransaction()) {
            $pdo->commit();
        } else {
            error_log('WARNING: Transaction was already closed before commit in completeExport');
            throw new Exception('Transaction was closed unexpectedly');
        }
        
        // Trả về JSON thành công
        echo json_encode([
            'success' => true,
            'message' => 'Phiếu xuất đã được hoàn thành và chuyển sang trạng thái "Đã đóng gói"'
        ]);
        
    } catch (Exception $e) {
        // Rollback nếu có lỗi
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * HỦY PHIẾU XUẤT
 * Gọi khi cần hủy phiếu xuất và hoàn trả tồn kho (chỉ áp dụng cho phiếu "pending")
 */
function cancelExport($pdo, $input, $userId, $warehouseId) {
    // Lấy export_id từ request
    $exportId = $input['export_id'] ?? null;
    
    // Validate export_id bắt buộc
    if (!$exportId) {
        throw new Exception('Missing export ID');
    }
    
    // Query kiểm tra quyền truy cập và trạng thái (chỉ pending mới được hủy)
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
    
    // Kiểm tra phiếu xuất có tồn tại, thuộc kho của user, và có thể hủy
    if (!$exportInfo) {
        throw new Exception('Export not found, access denied, or cannot be cancelled');
    }
    
    // Bắt đầu transaction
    $pdo->beginTransaction();
    
    try {
        // Lấy danh sách sản phẩm đã được lấy (picked_quantity > 0) để hoàn trả
        $detailsSql = "
            SELECT variant_id, COALESCE(picked_quantity, 0) as picked_quantity
            FROM warehouse_export_details
            WHERE export_id = :export_id AND COALESCE(picked_quantity, 0) > 0
        ";
        
        $detailsStmt = $pdo->prepare($detailsSql);
        $detailsStmt->bindParam(':export_id', $exportId, PDO::PARAM_INT);
        $detailsStmt->execute();
        $pickedItems = $detailsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Hoàn trả số lượng đã lấy vào inventory
        foreach ($pickedItems as $item) {
            updateInventory($pdo, $item['variant_id'], $item['picked_quantity'], $warehouseId, 'increase');
        }
        
        // Cập nhật trạng thái phiếu xuất thành "cancelled"
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
        
        // Cập nhật trạng thái đơn hàng về "accepted" (hoàn trả trạng thái)
        $orderUpdateSql = "
            UPDATE orders 
            SET status = 'accepted',
                updated_at = NOW()
            WHERE order_id = :order_id
        ";
        
        $orderUpdateStmt = $pdo->prepare($orderUpdateSql);
        $orderUpdateStmt->bindParam(':order_id', $exportInfo['order_id'], PDO::PARAM_INT);
        $orderUpdateStmt->execute();
        
        // Ghi log audit
        logAudit($pdo, $userId, 'cancel_export', 'warehouse_exports', $exportId, [
            'export_id' => $exportId,
            'order_id' => $exportInfo['order_id'],
            'status' => 'cancelled',
            'returned_items' => count($pickedItems)
        ]);
        
        // Commit transaction
        $pdo->commit();
        
        // Trả về JSON thành công
        echo json_encode([
            'success' => true,
            'message' => 'Phiếu xuất đã được hủy thành công'
        ]);
        
    } catch (Exception $e) {
        // Rollback nếu có lỗi
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * CẬP NHẬT TỒN KHO
 * Tăng/giảm số lượng tồn kho với quản lý theo vị trí (location-based inventory)
 * 
 * @param PDO $pdo - Database connection
 * @param int $variantId - ID của product variant
 * @param int $quantity - Số lượng cần tăng/giảm
 * @param int $warehouseId - ID của kho
 * @param string $operation - 'decrease' (trừ tồn) hoặc 'increase' (hoàn trả)
 */
function updateInventory($pdo, $variantId, $quantity, $warehouseId, $operation = 'decrease') {
    try {
        if ($operation == 'decrease') {
            // GIẢM TỒN KHO: áp dụng FIFO (First In First Out)
            // Lấy danh sách vị trí có tồn kho, sắp xếp theo inventory_id tăng dần
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
                
                // Trừ dần từ các vị trí cho đến khi đủ số lượng
                $remainingQty = $quantity;
                foreach ($locations as $location) {
                    if ($remainingQty <= 0) break; // Đã lấy đủ
                    
                    // Tính số lượng trừ ở vị trí này (lấy min để không vượt quá tồn kho)
                    $deductQty = min($remainingQty, $location['quantity']);
                    
                    // Update trừ tồn kho tại vị trí
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
                    
                    // Giảm số lượng còn cần lấy
                    $remainingQty -= $deductQty;
                }
            } else {
                // TĂNG TỒN KHO (HOÀN TRẢ): thêm vào vị trí mặc định (kệ A-1-%)
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
                    // Insert mới hoặc update nếu đã tồn tại
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
        // Ghi log lỗi nhưng không throw exception (không ngắt luồng chính)
        error_log("Inventory update error: " . $e->getMessage());
    }
}

/**
 * GHI LOG AUDIT
 * Ghi lại tất cả hành động quan trọng vào bảng audit_logs để theo dõi
 * 
 * @param PDO $pdo - Database connection
 * @param int $userId - ID người thực hiện
 * @param string $action - Tên hành động (update_picked_quantity, complete_export, cancel_export)
 * @param string $tableName - Tên bảng bị ảnh hưởng
 * @param int $recordId - ID bản ghi bị ảnh hưởng
 * @param array $details - Chi tiết hành động (sẽ được convert sang JSON)
 */
function logAudit($pdo, $userId, $action, $tableName, $recordId, $details) {
    try {
        // Insert log vào bảng audit_logs
        $insertSql = "
            INSERT INTO audit_logs (user_id, action, table_name, record_id, new_values, warehouse_id, created_at, details)
            VALUES (:user_id, :action, :table_name, :record_id, :new_values, :warehouse_id, NOW(), :details)
        ";
        
        // Chuyển array thành JSON string
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
        // Ghi log lỗi nhưng KHÔNG throw exception (để không ảnh hưởng transaction chính)
        error_log("Audit log error: " . $e->getMessage());
    }
}
?>
