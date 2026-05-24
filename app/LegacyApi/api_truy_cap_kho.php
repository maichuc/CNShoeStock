<?php
// Suppress all PHP errors and warnings for clean JSON output
error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');

/**
 * API để kiểm tra và thực thi phân quyền warehouse
 */

// session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../app/Classes/KiemSoatTruyCapKho.php';

try {
    $database = new Database();
    $pdo = $database->getConnection();
    $warehouseControl = new WarehouseAccessControl($pdo);
    
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';
    
    // Kiểm tra đăng nhập
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Chưa đăng nhập', 401);
    }
    
    $userId = $_SESSION['user_id'];
    $userWarehouseId = $warehouseControl->getUserWarehouseId($userId);
    
    switch ($method) {
        case 'GET':
            handleGetRequest($action, $warehouseControl, $userId, $userWarehouseId);
            break;
            
        case 'POST':
            handlePostRequest($action, $warehouseControl, $userId, $userWarehouseId);
            break;
            
        case 'PUT':
            handlePutRequest($action, $warehouseControl, $userId, $userWarehouseId);
            break;
            
        case 'DELETE':
            handleDeleteRequest($action, $warehouseControl, $userId, $userWarehouseId);
            break;
            
        default:
            throw new Exception('Method không được hỗ trợ', 405);
    }
    
} catch (Exception $e) {
    $statusCode = $e->getCode() ?: 500;
    http_response_code($statusCode);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'code' => $statusCode
    ], JSON_UNESCAPED_UNICODE);
}

function handleGetRequest($action, $warehouseControl, $userId, $userWarehouseId) {
    switch ($action) {
        case 'products':
            $limit = $_GET['limit'] ?? null;
            $offset = $_GET['offset'] ?? 0;
            $products = $warehouseControl->getWarehouseProducts($userWarehouseId, $limit, $offset);
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'warehouse_id' => $userWarehouseId,
                    'products' => $products,
                    'total' => count($products)
                ]
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'product':
            $productId = $_GET['id'] ?? null;
            if (!$productId) {
                throw new Exception('Product ID là bắt buộc', 400);
            }
            
            $product = $warehouseControl->getProductDetails($productId, $userId);
            $variants = $warehouseControl->getProductVariants($productId, $userId);
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'product' => $product,
                    'variants' => $variants
                ]
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'search':
            $searchTerm = $_GET['q'] ?? '';
            $categoryId = $_GET['category'] ?? null;
            
            if (empty($searchTerm)) {
                throw new Exception('Từ khóa tìm kiếm không được để trống', 400);
            }
            
            $results = $warehouseControl->searchProducts($userWarehouseId, $searchTerm, $categoryId);
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'warehouse_id' => $userWarehouseId,
                    'search_term' => $searchTerm,
                    'results' => $results,
                    'count' => count($results)
                ]
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'stats':
            $stats = $warehouseControl->getWarehouseStats($userWarehouseId);
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'warehouse_id' => $userWarehouseId,
                    'statistics' => $stats
                ]
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'check_access':
            $targetWarehouseId = $_GET['warehouse_id'] ?? null;
            if (!$targetWarehouseId) {
                throw new Exception('Warehouse ID là bắt buộc', 400);
            }
            
            $hasAccess = $warehouseControl->checkAccess($userId, $targetWarehouseId);
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'user_id' => $userId,
                    'user_warehouse_id' => $userWarehouseId,
                    'target_warehouse_id' => (int)$targetWarehouseId,
                    'has_access' => $hasAccess
                ]
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'categories':
            $categories = $warehouseControl->getWarehouseCategories($userWarehouseId);
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'warehouse_id' => $userWarehouseId,
                    'categories' => $categories,
                    'total' => count($categories)
                ]
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'suppliers':
            $suppliers = $warehouseControl->getWarehouseSuppliers($userWarehouseId);
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'warehouse_id' => $userWarehouseId,
                    'suppliers' => $suppliers,
                    'total' => count($suppliers)
                ]
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'full_data':
            $fullData = $warehouseControl->getWarehouseFullData($userWarehouseId, $userId);
            
            echo json_encode([
                'success' => true,
                'data' => $fullData
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        default:
            throw new Exception('Action không hợp lệ', 400);
    }
}

function handlePostRequest($action, $warehouseControl, $userId, $userWarehouseId) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    switch ($action) {
        case 'create_product':
            if (!$input) {
                throw new Exception('Dữ liệu sản phẩm là bắt buộc', 400);
            }
            
            $requiredFields = ['name', 'category_id'];
            foreach ($requiredFields as $field) {
                if (empty($input[$field])) {
                    throw new Exception("Trường {$field} là bắt buộc", 400);
                }
            }
            
            $productId = $warehouseControl->createProduct($input, $userId);
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'product_id' => $productId,
                    'warehouse_id' => $userWarehouseId,
                    'message' => 'Tạo sản phẩm thành công'
                ]
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'validate_access':
            $targetWarehouseId = $input['warehouse_id'] ?? null;
            
            try {
                $validatedWarehouseId = $warehouseControl->validateWarehouseAccess($userId, $targetWarehouseId);
                
                echo json_encode([
                    'success' => true,
                    'data' => [
                        'validated_warehouse_id' => $validatedWarehouseId,
                        'message' => 'Quyền truy cập hợp lệ'
                    ]
                ], JSON_UNESCAPED_UNICODE);
            } catch (Exception $e) {
                throw new Exception('Không có quyền truy cập: ' . $e->getMessage(), 403);
            }
            break;
            
        case 'create_category':
            if (!$input) {
                throw new Exception('Dữ liệu category là bắt buộc', 400);
            }
            
            if (empty($input['name'])) {
                throw new Exception('Tên category là bắt buộc', 400);
            }
            
            $categoryId = $warehouseControl->createCategory($input, $userId);
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'category_id' => $categoryId,
                    'warehouse_id' => $userWarehouseId,
                    'message' => 'Tạo category thành công'
                ]
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'create_supplier':
            if (!$input) {
                throw new Exception('Dữ liệu supplier là bắt buộc', 400);
            }
            
            if (empty($input['name'])) {
                throw new Exception('Tên supplier là bắt buộc', 400);
            }
            
            $supplierId = $warehouseControl->createSupplier($input, $userId);
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'supplier_id' => $supplierId,
                    'warehouse_id' => $userWarehouseId,
                    'message' => 'Tạo supplier thành công'
                ]
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        default:
            throw new Exception('Action không hợp lệ', 400);
    }
}

function handlePutRequest($action, $warehouseControl, $userId, $userWarehouseId) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    switch ($action) {
        case 'update_product':
            $productId = $_GET['id'] ?? null;
            if (!$productId) {
                throw new Exception('Product ID là bắt buộc', 400);
            }
            
            if (!$input) {
                throw new Exception('Dữ liệu cập nhật là bắt buộc', 400);
            }
            
            $result = $warehouseControl->updateProduct($productId, $input, $userId);
            
            if ($result) {
                echo json_encode([
                    'success' => true,
                    'data' => [
                        'product_id' => (int)$productId,
                        'warehouse_id' => $userWarehouseId,
                        'message' => 'Cập nhật sản phẩm thành công'
                    ]
                ], JSON_UNESCAPED_UNICODE);
            } else {
                throw new Exception('Cập nhật sản phẩm thất bại', 500);
            }
            break;
            
        case 'update_category':
            $categoryId = $_GET['id'] ?? null;
            if (!$categoryId) {
                throw new Exception('Category ID là bắt buộc', 400);
            }
            
            if (!$input) {
                throw new Exception('Dữ liệu cập nhật là bắt buộc', 400);
            }
            
            $result = $warehouseControl->updateCategory($categoryId, $input, $userId);
            
            if ($result) {
                echo json_encode([
                    'success' => true,
                    'data' => [
                        'category_id' => (int)$categoryId,
                        'warehouse_id' => $userWarehouseId,
                        'message' => 'Cập nhật category thành công'
                    ]
                ], JSON_UNESCAPED_UNICODE);
            } else {
                throw new Exception('Cập nhật category thất bại', 500);
            }
            break;
            
        case 'update_supplier':
            $supplierId = $_GET['id'] ?? null;
            if (!$supplierId) {
                throw new Exception('Supplier ID là bắt buộc', 400);
            }
            
            if (!$input) {
                throw new Exception('Dữ liệu cập nhật là bắt buộc', 400);
            }
            
            $result = $warehouseControl->updateSupplier($supplierId, $input, $userId);
            
            if ($result) {
                echo json_encode([
                    'success' => true,
                    'data' => [
                        'supplier_id' => (int)$supplierId,
                        'warehouse_id' => $userWarehouseId,
                        'message' => 'Cập nhật supplier thành công'
                    ]
                ], JSON_UNESCAPED_UNICODE);
            } else {
                throw new Exception('Cập nhật supplier thất bại', 500);
            }
            break;
            
        default:
            throw new Exception('Action không hợp lệ', 400);
    }
}

function handleDeleteRequest($action, $warehouseControl, $userId, $userWarehouseId) {
    switch ($action) {
        case 'delete_product':
            $productId = $_GET['id'] ?? null;
            if (!$productId) {
                throw new Exception('Product ID là bắt buộc', 400);
            }
            
            $result = $warehouseControl->deleteProduct($productId, $userId);
            
            if ($result) {
                echo json_encode([
                    'success' => true,
                    'data' => [
                        'product_id' => (int)$productId,
                        'warehouse_id' => $userWarehouseId,
                        'message' => 'Xóa sản phẩm thành công'
                    ]
                ], JSON_UNESCAPED_UNICODE);
            } else {
                throw new Exception('Xóa sản phẩm thất bại', 500);
            }
            break;
            
        case 'delete_category':
            $categoryId = $_GET['id'] ?? null;
            if (!$categoryId) {
                throw new Exception('Category ID là bắt buộc', 400);
            }
            
            $result = $warehouseControl->deleteCategory($categoryId, $userId);
            
            if ($result) {
                echo json_encode([
                    'success' => true,
                    'data' => [
                        'category_id' => (int)$categoryId,
                        'warehouse_id' => $userWarehouseId,
                        'message' => 'Xóa category thành công'
                    ]
                ], JSON_UNESCAPED_UNICODE);
            } else {
                throw new Exception('Xóa category thất bại', 500);
            }
            break;
            
        case 'delete_supplier':
            $supplierId = $_GET['id'] ?? null;
            if (!$supplierId) {
                throw new Exception('Supplier ID là bắt buộc', 400);
            }
            
            $result = $warehouseControl->deleteSupplier($supplierId, $userId);
            
            if ($result) {
                echo json_encode([
                    'success' => true,
                    'data' => [
                        'supplier_id' => (int)$supplierId,
                        'warehouse_id' => $userWarehouseId,
                        'message' => 'Xóa supplier thành công'
                    ]
                ], JSON_UNESCAPED_UNICODE);
            } else {
                throw new Exception('Xóa supplier thất bại', 500);
            }
            break;
            
        default:
            throw new Exception('Action không hợp lệ', 400);
    }
}
?>