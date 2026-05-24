<?php
// routes/api.php
$action = isset($_GET['action']) ? $_GET['action'] : '';

// Nếu không có action trong GET, thử lấy từ POST
if (empty($action)) {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = isset($input['action']) ? $input['action'] : (isset($_POST['action']) ? $_POST['action'] : '');
}

switch ($action) {
    // --- AUTH ---
    case 'login':
        require_once __DIR__ . '/../app/Http/Controllers/Api/AuthController.php';
        (new AuthController())->login();
        break;
    case 'logout':
        require_once __DIR__ . '/../app/Http/Controllers/Api/AuthController.php';
        (new AuthController())->logout();
        break;
    // --- SUPPLIER ---
    case 'get_suppliers':
    case 'get_supplier':
        require_once __DIR__ . '/../app/Http/Controllers/Api/SupplierController.php';
        (new SupplierController())->getSuppliers();
        break;
    case 'save_supplier':
        require_once __DIR__ . '/../app/Http/Controllers/Api/SupplierController.php';
        (new SupplierController())->saveSupplier();
        break;
    case 'delete_supplier':
        require_once __DIR__ . '/../app/Http/Controllers/Api/SupplierController.php';
        (new SupplierController())->deleteSupplier();
        break;

    // --- PRODUCT ---
    case 'get_product_data':
    case 'get_product_details':
    case 'get_sku':
    case 'get_storage_locations':
    case 'get_all_sizes':
        require_once __DIR__ . '/../app/Http/Controllers/Api/ProductController.php';
        (new ProductController())->getProductData();
        break;

    // --- STOCK RECEIPT ---
    case 'get_draft_receipts':
        require_once __DIR__ . '/../app/Http/Controllers/Api/StockReceiptController.php';
        (new StockReceiptController())->getDraftReceipts();
        break;
    case 'get_receipt_details':
        require_once __DIR__ . '/../app/Http/Controllers/Api/StockReceiptController.php';
        (new StockReceiptController())->getReceiptDetails();
        break;
    case 'confirm_receipt':
        require_once __DIR__ . '/../app/Http/Controllers/Api/StockReceiptController.php';
        (new StockReceiptController())->confirmReceipt();
        break;
    case 'update_draft':
    case 'update_receipt_draft':
        require_once __DIR__ . '/../app/Http/Controllers/Api/StockReceiptController.php';
        (new StockReceiptController())->updateDraft();
        break;
    case 'delete':
    case 'delete_receipt':
        require_once __DIR__ . '/../app/Http/Controllers/Api/StockReceiptController.php';
        (new StockReceiptController())->delete();
        break;
    case 'get_receipt_history':
        require_once __DIR__ . '/../app/Http/Controllers/Api/StockReceiptController.php';
        (new StockReceiptController())->getReceiptHistory();
        break;

    // --- ORDER ---
    case 'get_orders':
        require_once __DIR__ . '/../app/Http/Controllers/Api/OrderController.php';
        (new OrderController())->getOrders();
        break;
    case 'create_order':
        require_once __DIR__ . '/../app/Http/Controllers/Api/OrderController.php';
        (new OrderController())->createOrder();
        break;
    case 'get_order_detail':
        require_once __DIR__ . '/../app/Http/Controllers/Api/OrderController.php';
        (new OrderController())->getOrderDetail();
        break;
    case 'update_order_status':
        require_once __DIR__ . '/../app/Http/Controllers/Api/OrderController.php';
        (new OrderController())->updateStatus();
        break;

    // --- STAFF ---
    case 'get_staff_list':
    case 'list_employees':
        require_once __DIR__ . '/../app/Http/Controllers/Api/StaffController.php';
        (new StaffController())->getList();
        break;
    case 'get_employee_detail':
    case 'employee_detail':
        require_once __DIR__ . '/../app/Http/Controllers/Api/StaffController.php';
        (new StaffController())->getDetail();
        break;
    case 'update_employee':
        require_once __DIR__ . '/../app/Http/Controllers/Api/StaffController.php';
        (new StaffController())->update();
        break;
    case 'change_password':
        require_once __DIR__ . '/../app/Http/Controllers/Api/StaffController.php';
        (new StaffController())->changePassword();
        break;
    
    // --- UTILITIES ---
    case 'chuan_hoa_du_lieu':
        require_once __DIR__ . '/../app/LegacyApi/api_chuan_hoa_du_lieu.php';
        break;

    default:
        // Fallback cho các API di sản chưa được refactor
        $route = isset($_GET['route']) ? $_GET['route'] : '';
        if ($route && strpos($route, 'api_') === 0) {
            $legacyFile = __DIR__ . '/../app/LegacyApi/' . basename($route);
            if (file_exists($legacyFile)) {
                require_once $legacyFile;
                break;
            }
        }
        
        // Nếu vẫn không tìm thấy, thử tìm trong LegacyApi dựa trên action
        $legacyActionFile = __DIR__ . '/../app/LegacyApi/api_' . $action . '.php';
        if (file_exists($legacyActionFile)) {
            require_once $legacyActionFile;
            break;
        }

        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Endpoint không tồn tại: ' . $action]);
}
?>