<?php
/**
 * API quản lý vị trí kho (warehouse locations)
 * CRUD operations cho locations table (merged from warehouse_locations)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

session_start();
require_once 'config/cau_hinh_csdl.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Chưa đăng nhập']);
    exit();
}

/**
 * Convert tiếng Việt có dấu sang không dấu
 */
function removeVietnameseTones($str) {
    $vietnameseMap = array(
        'à'=>'a','á'=>'a','ạ'=>'a','ả'=>'a','ã'=>'a','â'=>'a','ầ'=>'a','ấ'=>'a','ậ'=>'a','ẩ'=>'a','ẫ'=>'a','ă'=>'a','ằ'=>'a','ắ'=>'a','ặ'=>'a','ẳ'=>'a','ẵ'=>'a',
        'è'=>'e','é'=>'e','ẹ'=>'e','ẻ'=>'e','ẽ'=>'e','ê'=>'e','ề'=>'e','ế'=>'e','ệ'=>'e','ể'=>'e','ễ'=>'e',
        'ì'=>'i','í'=>'i','ọ'=>'o','ỏ'=>'o','õ'=>'o','ô'=>'o','ồ'=>'o','ố'=>'o','ộ'=>'o','ổ'=>'o','ỗ'=>'o','ơ'=>'o','ờ'=>'o','ớ'=>'o','ợ'=>'o','ở'=>'o','ỡ'=>'o',
        'ù'=>'u','ú'=>'u','ụ'=>'u','ủ'=>'u','ũ'=>'u','ư'=>'u','ừ'=>'u','ứ'=>'u','ự'=>'u','ử'=>'u','ữ'=>'u',
        'ỳ'=>'y','ý'=>'y','ỵ'=>'y','ỷ'=>'y','ỹ'=>'y',
        'đ'=>'d',
        'À'=>'A','Á'=>'A','Ạ'=>'A','Ả'=>'A','Ã'=>'A','Â'=>'A','Ầ'=>'A','Ấ'=>'A','Ậ'=>'A','Ẩ'=>'A','Ẫ'=>'A','Ă'=>'A','Ằ'=>'A','Ắ'=>'A','Ặ'=>'A','Ẳ'=>'A','Ẵ'=>'A',
        'È'=>'E','É'=>'E','Ẹ'=>'E','Ẻ'=>'E','Ẽ'=>'E','Ê'=>'E','Ề'=>'E','Ế'=>'E','Ệ'=>'E','Ể'=>'E','Ễ'=>'E',
        'Ì'=>'I','Í'=>'I','Ị'=>'I','Ỉ'=>'I','Ĩ'=>'I',
        'Ò'=>'O','Ó'=>'O','Ọ'=>'O','Ỏ'=>'O','Õ'=>'O','Ô'=>'O','Ồ'=>'O','Ố'=>'O','Ộ'=>'O','Ổ'=>'O','Ỗ'=>'O','Ơ'=>'O','Ờ'=>'O','Ớ'=>'O','Ợ'=>'O','Ở'=>'O','Ỡ'=>'O',
        'Ù'=>'U','Ú'=>'U','Ụ'=>'U','Ủ'=>'U','Ũ'=>'U','Ư'=>'U','Ừ'=>'U','Ứ'=>'U','Ự'=>'U','Ử'=>'U','Ữ'=>'U',
        'Ỳ'=>'Y','Ý'=>'Y','Ỵ'=>'Y','Ỷ'=>'Y','Ỹ'=>'Y',
        'Đ'=>'D'
    );
    return strtr($str, $vietnameseMap);
}

try {
    $database = new Database();
    $pdo = $database->getConnection();
    
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';
    
    switch ($method) {
        case 'GET':
            handleGet($pdo, $action);
            break;
            
        case 'POST':
            handlePost($pdo, $action);
            break;
            
        case 'PUT':
            handlePut($pdo, $action);
            break;
            
        case 'DELETE':
            handleDelete($pdo, $action);
            break;
            
        default:
            throw new Exception('Method không được hỗ trợ');
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

/**
 * Xử lý GET requests
 */
function handleGet($pdo, $action) {
    switch ($action) {
        case 'list':
            getLocationsList($pdo);
            break;
            
        case 'by_warehouse':
            getLocationsByWarehouse($pdo);
            break;
            
        case 'detail':
            getLocationDetail($pdo);
            break;
            
        case 'warehouses':
            getWarehouses($pdo);
            break;
            
        case 'product_types':
            getProductTypes($pdo);
            break;
            
        default:
            throw new Exception('Action không hợp lệ');
    }
}

/**
 * Lấy danh sách tất cả vị trí kho
 */
function getLocationsList($pdo) {
    $warehouseId = $_GET['warehouse_id'] ?? null;
    $search = $_GET['search'] ?? '';
    $isActive = $_GET['is_active'] ?? null;
    $productType = $_GET['product_type'] ?? null;
    
    // Kiểm tra quyền: Manager chỉ xem được kho của mình
    $userRole = $_SESSION['role'] ?? '';
    $userWarehouseId = $_SESSION['warehouse_id'] ?? null;
    
    $sql = "SELECT l.location_id, l.warehouse_id, l.shelf_code as location_code, 
                   l.description, l.is_active, l.created_at,
                   w.name as warehouse_name, w.address as warehouse_address
            FROM locations l
            LEFT JOIN warehouses w ON l.warehouse_id = w.warehouse_id
            WHERE 1=1";
    
    $params = [];
    
    // Nếu là manager, chỉ lấy vị trí của kho được phân công
    if ($userRole === 'manager' && $userWarehouseId) {
        $sql .= " AND l.warehouse_id = :user_warehouse_id";
        $params[':user_warehouse_id'] = $userWarehouseId;
    } elseif ($warehouseId) {
        $sql .= " AND l.warehouse_id = :warehouse_id";
        $params[':warehouse_id'] = $warehouseId;
    }
    
    // Lọc theo loại sản phẩm (dựa trên mã vị trí chứa tên loại)
    if ($productType) {
        // Convert product type sang không dấu và uppercase để match với shelf_code
        $productTypeNormalized = removeVietnameseTones($productType);
        $productTypeNormalized = strtoupper($productTypeNormalized);
        $productTypeNormalized = str_replace(' ', '-', $productTypeNormalized);
        
        $sql .= " AND l.shelf_code LIKE :product_type";
        $params[':product_type'] = "%$productTypeNormalized%";
    }
    
    if ($search) {
        $sql .= " AND (l.shelf_code LIKE :search OR l.description LIKE :search)";
        $params[':search'] = "%$search%";
    }
    
    if ($isActive !== null && $isActive !== '') {
        $sql .= " AND l.is_active = :is_active";
        $params[':is_active'] = $isActive;
    }
    
    $sql .= " ORDER BY w.name, l.shelf_code";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $locations
    ]);
}

/**
 * Lấy vị trí theo warehouse
 */
function getLocationsByWarehouse($pdo) {
    $warehouseId = $_GET['warehouse_id'] ?? null;
    
    if (!$warehouseId) {
        throw new Exception('Thiếu warehouse_id');
    }
    
    $sql = "SELECT location_id, warehouse_id, shelf_code as location_code, 
                   description, is_active, created_at
            FROM locations 
            WHERE warehouse_id = :warehouse_id AND is_active = 1
            ORDER BY shelf_code";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':warehouse_id' => $warehouseId]);
    $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $locations
    ]);
}

/**
 * Lấy chi tiết vị trí
 */
function getLocationDetail($pdo) {
    $locationId = $_GET['location_id'] ?? null;
    
    if (!$locationId) {
        throw new Exception('Thiếu location_id');
    }
    
    $sql = "SELECT l.location_id, l.warehouse_id, l.shelf_code as location_code,
                   l.description, l.is_active, l.created_at,
                   w.name as warehouse_name
            FROM locations l
            LEFT JOIN warehouses w ON l.warehouse_id = w.warehouse_id
            WHERE l.location_id = :location_id";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':location_id' => $locationId]);
    $location = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$location) {
        throw new Exception('Không tìm thấy vị trí');
    }
    
    echo json_encode([
        'success' => true,
        'data' => $location
    ]);
}

/**
 * Lấy danh sách warehouses
 */
function getWarehouses($pdo) {
    $sql = "SELECT warehouse_id, name, address, status 
            FROM warehouses 
            WHERE status = 'active'
            ORDER BY name";
    
    $stmt = $pdo->query($sql);
    $warehouses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $warehouses
    ]);
}

/**
 * Lấy danh sách loại sản phẩm (type) đã có trong warehouse
 */
function getProductTypes($pdo) {
    $warehouseId = $_GET['warehouse_id'] ?? null;
    
    if (!$warehouseId) {
        throw new Exception('Thiếu warehouse_id');
    }
    
    $sql = "SELECT DISTINCT type
            FROM (
                SELECT p.type
                FROM products p
                INNER JOIN product_variants pv ON p.product_id = pv.product_id
                INNER JOIN inventory i ON pv.variant_id = i.variant_id
                WHERE i.warehouse_id = :warehouse_id 
                    AND p.type IS NOT NULL 
                    AND p.type != ''
                UNION
                SELECT l.type
                FROM locations l
                WHERE l.warehouse_id = :warehouse_id
                    AND l.type IS NOT NULL
                    AND l.type != ''
            ) t
            ORDER BY type";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['warehouse_id' => $warehouseId]);
    $types = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $types
    ]);
}

/**
 * Xử lý POST requests - Thêm mới
 */
function handlePost($pdo, $action) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data) {
        throw new Exception('Dữ liệu không hợp lệ');
    }
    
    switch ($action) {
        case 'create':
            createLocation($pdo, $data);
            break;
            
        case 'bulk_create':
            bulkCreateLocations($pdo, $data);
            break;
            
        default:
            throw new Exception('Action không hợp lệ');
    }
}

/**
 * Tạo vị trí mới
 */
function createLocation($pdo, $data) {
    $userRole = $_SESSION['role'] ?? '';
    $userWarehouseId = $_SESSION['warehouse_id'] ?? null;
    
    // Validate
    if (empty($data['warehouse_id']) || empty($data['location_code'])) {
        throw new Exception('Thiếu thông tin bắt buộc');
    }
    
    // Nếu là manager, chỉ được tạo vị trí cho kho của mình
    if ($userRole === 'manager' && $userWarehouseId) {
        if ($data['warehouse_id'] != $userWarehouseId) {
            throw new Exception('Bạn không có quyền tạo vị trí cho kho khác');
        }
    }
    
    // Kiểm tra trùng mã vị trí trong cùng warehouse
    $checkSql = "SELECT location_id FROM locations 
                 WHERE warehouse_id = :warehouse_id AND shelf_code = :location_code";
    $stmt = $pdo->prepare($checkSql);
    $stmt->execute([
        ':warehouse_id' => $data['warehouse_id'],
        ':location_code' => $data['location_code']
    ]);
    
    if ($stmt->fetch()) {
        throw new Exception('Mã vị trí đã tồn tại trong kho này');
    }
    
    // Insert
    $sql = "INSERT INTO locations (warehouse_id, shelf_code, type, description, is_active)
            VALUES (:warehouse_id, :location_code, :type, :description, :is_active)";
    
    $stmt = $pdo->prepare($sql);
    $result = $stmt->execute([
        ':warehouse_id' => $data['warehouse_id'],
        ':location_code' => $data['location_code'],
        ':type' => $data['type'] ?? null,
        ':description' => $data['description'] ?? '',
        ':is_active' => $data['is_active'] ?? 1
    ]);
    
    if ($result) {
        $locationId = $pdo->lastInsertId();
        
        echo json_encode([
            'success' => true,
            'message' => 'Thêm vị trí thành công',
            'location_id' => $locationId
        ]);
    } else {
        throw new Exception('Không thể thêm vị trí');
    }
}

/**
 * Tạo nhiều vị trí cùng lúc
 */
function bulkCreateLocations($pdo, $data) {
    $userRole = $_SESSION['role'] ?? '';
    $userWarehouseId = $_SESSION['warehouse_id'] ?? null;
    
    if (empty($data['warehouse_id']) || empty($data['locations']) || !is_array($data['locations'])) {
        throw new Exception('Dữ liệu không hợp lệ');
    }
    
    // Nếu là manager, chỉ được tạo vị trí cho kho của mình
    if ($userRole === 'manager' && $userWarehouseId) {
        if ($data['warehouse_id'] != $userWarehouseId) {
            throw new Exception('Bạn không có quyền tạo vị trí cho kho khác');
        }
    }
    
    $pdo->beginTransaction();
    
    try {
        $success = 0;
        $failed = 0;
        $errors = [];
        
        foreach ($data['locations'] as $location) {
            try {
                // Validate
                if (empty($location['location_code'])) {
                    $failed++;
                    $errors[] = "Thiếu thông tin: " . ($location['location_code'] ?? 'N/A');
                    continue;
                }
                
                // Sử dụng warehouse_id từ data gốc
                $warehouseId = $data['warehouse_id'];
                
                // Kiểm tra trùng
                $checkSql = "SELECT location_id FROM locations 
                            WHERE warehouse_id = :warehouse_id AND shelf_code = :location_code";
                $stmt = $pdo->prepare($checkSql);
                $stmt->execute([
                    ':warehouse_id' => $warehouseId,
                    ':location_code' => $location['location_code']
                ]);
                
                if ($stmt->fetch()) {
                    $failed++;
                    $errors[] = "Trùng mã: " . $location['location_code'];
                    continue;
                }
                
                // Insert - sử dụng warehouse_id từ data gốc
                $sql = "INSERT INTO locations (warehouse_id, shelf_code, type, description, is_active)
                        VALUES (:warehouse_id, :location_code, :type, :description, :is_active)";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':warehouse_id' => $warehouseId,
                    ':location_code' => $location['location_code'],
                    ':type' => $location['type'] ?? ($data['type'] ?? null),
                    ':description' => $location['description'] ?? '',
                    ':is_active' => $location['is_active'] ?? 1
                ]);
                
                $success++;
                
            } catch (Exception $e) {
                $failed++;
                $errors[] = $location['location_code'] . ': ' . $e->getMessage();
            }
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => "Thành công: $success, Thất bại: $failed",
            'stats' => [
                'success' => $success,
                'failed' => $failed,
                'errors' => $errors
            ]
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Xử lý PUT requests - Cập nhật
 */
function handlePut($pdo, $action) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data) {
        throw new Exception('Dữ liệu không hợp lệ');
    }
    
    switch ($action) {
        case 'update':
            updateLocation($pdo, $data);
            break;
            
        case 'toggle_status':
            toggleLocationStatus($pdo, $data);
            break;
            
        default:
            throw new Exception('Action không hợp lệ');
    }
}

/**
 * Cập nhật thông tin vị trí
 */
function updateLocation($pdo, $data) {
    $userRole = $_SESSION['role'] ?? '';
    $userWarehouseId = $_SESSION['warehouse_id'] ?? null;
    
    if (empty($data['location_id'])) {
        throw new Exception('Thiếu location_id');
    }
    
    // Lấy thông tin vị trí hiện tại
    $checkLocationSql = "SELECT warehouse_id FROM locations WHERE location_id = :location_id";
    $stmt = $pdo->prepare($checkLocationSql);
    $stmt->execute([':location_id' => $data['location_id']]);
    $currentLocation = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$currentLocation) {
        throw new Exception('Không tìm thấy vị trí kho');
    }
    
    // Nếu là manager, chỉ được sửa vị trí của kho mình
    if ($userRole === 'manager' && $userWarehouseId) {
        if ($currentLocation['warehouse_id'] != $userWarehouseId) {
            throw new Exception('Bạn không có quyền sửa vị trí của kho khác');
        }
    }
    
    // Kiểm tra trùng mã vị trí (trừ bản thân)
    if (!empty($data['location_code'])) {
        $checkSql = "SELECT location_id FROM locations 
                     WHERE warehouse_id = :warehouse_id 
                     AND shelf_code = :location_code 
                     AND location_id != :location_id";
        $stmt = $pdo->prepare($checkSql);
        $stmt->execute([
            ':warehouse_id' => $data['warehouse_id'],
            ':location_code' => $data['location_code'],
            ':location_id' => $data['location_id']
        ]);
        
        if ($stmt->fetch()) {
            throw new Exception('Mã vị trí đã tồn tại trong kho này');
        }
    }
    
    $sql = "UPDATE locations 
            SET shelf_code = :location_code,
                description = :description,
                is_active = :is_active
            WHERE location_id = :location_id";
    
    $stmt = $pdo->prepare($sql);
    $result = $stmt->execute([
        ':location_code' => $data['location_code'],
        ':description' => $data['description'] ?? '',
        ':is_active' => $data['is_active'] ?? 1,
        ':location_id' => $data['location_id']
    ]);
    
    if ($result) {
        echo json_encode([
            'success' => true,
            'message' => 'Cập nhật vị trí thành công'
        ]);
    } else {
        throw new Exception('Không thể cập nhật vị trí');
    }
}

/**
 * Bật/tắt trạng thái vị trí (Kích hoạt/Tạm ngưng)
 * Ràng buộc: Vị trí is_active = 0 sẽ không được gợi ý khi nhập kho sản phẩm mới
 */
function toggleLocationStatus($pdo, $data) {
    $userRole = $_SESSION['role'] ?? '';
    $userWarehouseId = $_SESSION['warehouse_id'] ?? null;
    
    if (empty($data['location_id'])) {
        throw new Exception('Thiếu location_id');
    }
    
    // Kiểm tra quyền: Manager chỉ được thao tác vị trí của kho mình
    if ($userRole === 'manager' && $userWarehouseId) {
        $checkSql = "SELECT warehouse_id FROM locations WHERE location_id = :location_id";
        $stmt = $pdo->prepare($checkSql);
        $stmt->execute([':location_id' => $data['location_id']]);
        $location = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$location) {
            throw new Exception('Không tìm thấy vị trí kho');
        }
        
        if ($location['warehouse_id'] != $userWarehouseId) {
            throw new Exception('Bạn không có quyền thao tác vị trí của kho khác');
        }
    }
    
    $isActive = $data['is_active'] ? 1 : 0;
    
    $sql = "UPDATE locations 
            SET is_active = :is_active 
            WHERE location_id = :location_id";
    
    $stmt = $pdo->prepare($sql);
    $result = $stmt->execute([
        ':is_active' => $isActive,
        ':location_id' => $data['location_id']
    ]);
    
    if ($result) {
        $message = $isActive 
            ? 'Vị trí đã được kích hoạt lại.' 
            : 'Vị trí đã tạm ngưng. Sẽ không hiển thị trong gợi ý nhập kho.';
        
        echo json_encode([
            'success' => true,
            'message' => $message
        ]);
    } else {
        throw new Exception('Không thể cập nhật trạng thái');
    }
}

/**
 * Xử lý DELETE requests
 */
function handleDelete($pdo, $action) {
    switch ($action) {
        case 'delete':
            deleteLocation($pdo);
            break;
            
        default:
            throw new Exception('Action không hợp lệ');
    }
}

/**
 * Xóa vị trí
 */
function deleteLocation($pdo) {
    $locationId = $_GET['location_id'] ?? null;
    
    if (!$locationId) {
        throw new Exception('Thiếu location_id');
    }
    
    // Lấy shelf_code để hiển thị thông báo
    $getLocationSql = "SELECT shelf_code FROM locations WHERE location_id = :location_id";
    $stmt = $pdo->prepare($getLocationSql);
    $stmt->execute([':location_id' => $locationId]);
    $location = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$location) {
        throw new Exception('Không tìm thấy vị trí kho');
    }
    
    // Kiểm tra xem vị trí có đang chứa sản phẩm không (bảng inventory)
    $checkInventorySql = "SELECT COUNT(*) as count, SUM(quantity) as total_quantity 
                          FROM inventory 
                          WHERE location_id = :location_id AND quantity > 0";
    $stmt = $pdo->prepare($checkInventorySql);
    $stmt->execute([':location_id' => $locationId]);
    $inventoryResult = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($inventoryResult['count'] > 0 && $inventoryResult['total_quantity'] > 0) {
        throw new Exception('Không thể xóa! Vị trí "' . $location['shelf_code'] . '" đang chứa ' . $inventoryResult['total_quantity'] . ' sản phẩm. Vui lòng chuyển hết sản phẩm ra khỏi vị trí này trước khi xóa.');
    }
    
    // Kiểm tra xem vị trí có đang được sử dụng trong phiếu nhập kho không (nếu có bảng này)
    try {
        $checkReceiptSql = "SELECT COUNT(*) as count FROM stock_receipt_items 
                            WHERE location_code = :shelf_code";
        $stmt = $pdo->prepare($checkReceiptSql);
        $stmt->execute([':shelf_code' => $location['shelf_code']]);
        $receiptResult = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($receiptResult['count'] > 0) {
            throw new Exception('Không thể xóa! Vị trí "' . $location['shelf_code'] . '" đang được sử dụng trong phiếu nhập kho.');
        }
    } catch (PDOException $e) {
        // Bỏ qua nếu bảng không tồn tại
    }
    
    // Xóa
    $sql = "DELETE FROM locations WHERE location_id = :location_id";
    $stmt = $pdo->prepare($sql);
    $result = $stmt->execute([':location_id' => $locationId]);
    
    if ($result) {
        echo json_encode([
            'success' => true,
            'message' => 'Xóa vị trí "' . $location['shelf_code'] . '" thành công'
        ]);
    } else {
        throw new Exception('Không thể xóa vị trí');
    }
}
