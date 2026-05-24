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
        throw new Exception('User not logged in');
    }
    
    $currentUser = $_SESSION['user_id'];
    $userWarehouseId = $_SESSION['warehouse_id'] ?? null;

    $database = new Database();
    $pdo = $database->getConnection();
    
    // Lấy warehouse_id từ database nếu chưa có trong session
    if (!$userWarehouseId) {
        $stmt = $pdo->prepare("SELECT warehouse_id FROM users WHERE user_id = ?");
        $stmt->execute([$currentUser]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result) {
            $userWarehouseId = $result['warehouse_id'];
            $_SESSION['warehouse_id'] = $userWarehouseId;
        }
    }
    
    $orderId = $_GET['order_id'] ?? null;
    
    if (!$orderId) {
        throw new Exception('Order ID is required');
    }
    
    // First, get basic order info - chỉ từ warehouse hiện tại
    $orderSql = "SELECT * FROM orders WHERE order_id = :order_id AND warehouse_id = :warehouse_id";
    $orderStmt = $pdo->prepare($orderSql);
    $orderStmt->bindParam(':order_id', $orderId, PDO::PARAM_INT);
    $orderStmt->bindParam(':warehouse_id', $userWarehouseId, PDO::PARAM_INT);
    $orderStmt->execute();
    
    $orderData = $orderStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$orderData) {
        throw new Exception('Order not found or access denied');
    }
    
    // Lấy chi tiết đơn hàng với thông tin sản phẩm - chỉ từ warehouse hiện tại
    $detailsSql = "
        SELECT 
            od.quantity,
            od.unit_price,
            od.total_price,
            p.product_name,
            v.size,
            v.color
        FROM order_details od
        JOIN variants v ON od.variant_id = v.variant_id
        JOIN products p ON v.product_id = p.product_id
        WHERE od.order_id = :order_id AND p.warehouse_id = :warehouse_id
        LIMIT 1
    ";
    
    $detailsStmt = $pdo->prepare($detailsSql);
    $detailsStmt->bindParam(':order_id', $orderId, PDO::PARAM_INT);
    $detailsStmt->bindParam(':warehouse_id', $userWarehouseId, PDO::PARAM_INT);
    $detailsStmt->execute();
    
    $productDetails = $detailsStmt->fetch(PDO::FETCH_ASSOC);
    
    // Lấy thông tin khách hàng nếu có customer_id
    $customerName = 'Unknown Customer';
    $customerPhone = '';
    $customerAddress = '';
    
    if (!empty($orderData['customer_id'])) {
        $customerSql = "SELECT full_name, phone, address FROM customers WHERE customer_id = :customer_id";
        $customerStmt = $pdo->prepare($customerSql);
        $customerStmt->bindParam(':customer_id', $orderData['customer_id'], PDO::PARAM_INT);
        $customerStmt->execute();
        
        $customerData = $customerStmt->fetch(PDO::FETCH_ASSOC);
        if ($customerData) {
            $customerName = $customerData['full_name'];
            $customerPhone = $customerData['phone'];
            $customerAddress = $customerData['address'];
        }
    }
    
    // If no customer_id, check if order has direct customer fields
    if (isset($orderData['customer_name']) && $orderData['customer_name']) {
        $customerName = $orderData['customer_name'];
    }
    if (isset($orderData['customer_phone']) && $orderData['customer_phone']) {
        $customerPhone = $orderData['customer_phone'];
    }
    if (isset($orderData['customer_address']) && $orderData['customer_address']) {
        $customerAddress = $orderData['customer_address'];
    }
    
    // Combine all data
    $result = [
        'order_id' => $orderData['order_id'],
        'customer_name' => $customerName,
        'customer_phone' => $customerPhone,
        'customer_address' => $customerAddress,
        'status' => $orderData['status'],
        'created_at' => $orderData['created_at'],
        'total_price' => $orderData['total_price']
    ];
    
    // Thêm chi tiết sản phẩm nếu tìm thấy
    if ($productDetails) {
        $result = array_merge($result, $productDetails);
    } else {
        // Default values if no product details
        $result['quantity'] = 1;
        $result['unit_price'] = $orderData['total_price'];
        $result['product_name'] = 'Unknown Product';
        $result['size'] = '';
        $result['color'] = '';
    }
    
    echo json_encode([
        'success' => true,
        'data' => $result
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'debug' => [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]
    ]);
}
?>