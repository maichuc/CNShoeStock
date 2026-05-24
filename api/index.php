<?php
// Bật hiển thị lỗi để debug
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Lấy tham số 'action' từ URL (VD: api/index.php?action=get_supplier)
$action = isset($_GET['action']) ? $_GET['action'] : '';

switch ($action) {
    case 'get_supplier':
        require_once 'controllers/SupplierController.php';
        $controller = new SupplierController();
        $controller->getSupplier();
        break;

    case 'get_product_details':
        require_once 'controllers/ProductController.php';
        $controller = new ProductController();
        $controller->getProductDetails();
        break;

    case 'delete_product':
        require_once 'controllers/ProductController.php';
        $controller = new ProductController();
        $controller->deleteProduct();
        break;

    case 'create_order':
        require_once 'controllers/OrderController.php';
        $controller = new OrderController();
        $controller->createOrder();
        break;

    case 'get_draft_receipts':
        require_once 'controllers/StockReceiptController.php';
        $controller = new StockReceiptController();
        $controller->getDraftReceipts();
        break;

    case 'get_receipt_details':
        require_once 'controllers/StockReceiptController.php';
        $controller = new StockReceiptController();
        $controller->getReceiptDetails();
        break;

    // Các route khác sẽ thêm vào đây trong tương lai
    
    default:
        header('Access-Control-Allow-Origin: *');
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(400);
        echo json_encode([
            "success" => false, 
            "message" => "Đường dẫn API không hợp lệ hoặc thiếu tham số action."
        ]);
        break;
}
?>
