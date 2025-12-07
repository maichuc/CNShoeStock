<?php
/**
 * API endpoint cho quản lý customers theo warehouse
 * Hỗ trợ CRUD operations với warehouse isolation
 */

session_start();
require_once 'config/cau_hinh_csdl.php';
require_once 'classes/KiemSoatTruyCapKho.php';

header('Content-Type: application/json');

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Không có quyền truy cập']);
    exit();
}

try {
    $database = new Database();
    $pdo = $database->getConnection();
    $warehouseControl = new WarehouseAccessControl($pdo);
    
    $currentUser = $_SESSION['user_id'];
    $userWarehouseId = $warehouseControl->getUserWarehouseId($currentUser);
    
    if (!$userWarehouseId) {
        http_response_code(403);
        echo json_encode(['error' => 'User không thuộc warehouse nào']);
        exit();
    }
    
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';
    
    switch ($method) {
        case 'GET':
            handleGetRequest($warehouseControl, $userWarehouseId, $action);
            break;
            
        case 'POST':
            handlePostRequest($warehouseControl, $userWarehouseId, $currentUser, $action);
            break;
            
        case 'PUT':
            handlePutRequest($warehouseControl, $currentUser, $action);
            break;
            
        case 'DELETE':
            handleDeleteRequest($warehouseControl, $currentUser, $action);
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method không được hỗ trợ']);
            break;
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

function handleGetRequest($warehouseControl, $userWarehouseId, $action) {
    switch ($action) {
        case 'list':
            $limit = $_GET['limit'] ?? null;
            $offset = $_GET['offset'] ?? 0;
            $customers = $warehouseControl->getWarehouseCustomers($userWarehouseId, $limit, $offset);
            echo json_encode(['success' => true, 'data' => $customers]);
            break;
            
        case 'search':
            $keyword = $_GET['keyword'] ?? '';
            if (empty($keyword)) {
                echo json_encode(['error' => 'Keyword không được để trống']);
                return;
            }
            $customers = $warehouseControl->searchWarehouseCustomers($userWarehouseId, $keyword);
            echo json_encode(['success' => true, 'data' => $customers]);
            break;
            
        case 'get':
            $customerId = $_GET['customer_id'] ?? '';
            if (!$customerId) {
                echo json_encode(['error' => 'Customer ID không được để trống']);
                return;
            }
            
            // Lấy thông tin customer (chỉ trong warehouse của user)
            $customers = $warehouseControl->getWarehouseCustomers($userWarehouseId);
            $customer = null;
            foreach ($customers as $c) {
                if ($c['customer_id'] == $customerId) {
                    $customer = $c;
                    break;
                }
            }
            
            if ($customer) {
                echo json_encode(['success' => true, 'data' => $customer]);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Không tìm thấy customer hoặc không có quyền truy cập']);
            }
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Action không hợp lệ']);
            break;
    }
}

function handlePostRequest($warehouseControl, $userWarehouseId, $currentUser, $action) {
    if ($action !== 'create') {
        http_response_code(400);
        echo json_encode(['error' => 'Action không hợp lệ cho POST']);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || empty($input['full_name'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Dữ liệu không hợp lệ - thiếu tên khách hàng']);
        return;
    }
    
    $data = [
        'user_id' => $currentUser,
        'full_name' => $input['full_name'],
        'phone' => $input['phone'] ?? null,
        'email' => $input['email'] ?? null,
        'address' => $input['address'] ?? null,
        'note' => $input['note'] ?? null
    ];
    
    $result = $warehouseControl->createCustomer($userWarehouseId, $data);
    
    if ($result) {
        echo json_encode(['success' => true, 'message' => 'Tạo customer thành công']);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Không thể tạo customer']);
    }
}

function handlePutRequest($warehouseControl, $currentUser, $action) {
    if ($action !== 'update') {
        http_response_code(400);
        echo json_encode(['error' => 'Action không hợp lệ cho PUT']);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || empty($input['customer_id']) || empty($input['full_name'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Dữ liệu không hợp lệ']);
        return;
    }
    
    $customerId = $input['customer_id'];
    $data = [
        'full_name' => $input['full_name'],
        'phone' => $input['phone'] ?? null,
        'email' => $input['email'] ?? null,
        'address' => $input['address'] ?? null,
        'note' => $input['note'] ?? null
    ];
    
    $result = $warehouseControl->updateCustomer($customerId, $currentUser, $data);
    
    if ($result) {
        echo json_encode(['success' => true, 'message' => 'Cập nhật customer thành công']);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Không thể cập nhật customer']);
    }
}

function handleDeleteRequest($warehouseControl, $currentUser, $action) {
    if ($action !== 'delete') {
        http_response_code(400);
        echo json_encode(['error' => 'Action không hợp lệ cho DELETE']);
        return;
    }
    
    $customerId = $_GET['customer_id'] ?? '';
    
    if (!$customerId) {
        http_response_code(400);
        echo json_encode(['error' => 'Customer ID không được để trống']);
        return;
    }
    
    $result = $warehouseControl->deleteCustomer($customerId, $currentUser);
    
    if ($result) {
        echo json_encode(['success' => true, 'message' => 'Xóa customer thành công']);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Không thể xóa customer']);
    }
}
?>