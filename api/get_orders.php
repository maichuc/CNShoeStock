<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once '../config/cau_hinh_csdl.php';

try {
    $database = new Database();
    $pdo = $database->getConnection();
    
    // Kiểm tra đăng nhập (simplified for testing)
    $warehouseId = $_SESSION['warehouse_id'] ?? 6;
    
    // Get orders with basic info
    $ordersSql = "
        SELECT 
            o.order_id,
            o.status,
            o.total_price,
            o.created_at,
            o.customer_id,
            o.customer_name,
            o.customer_phone,
            o.customer_address
        FROM orders o
        WHERE o.warehouse_id = :warehouse_id
        ORDER BY o.created_at DESC
        LIMIT 50
    ";
    
    $ordersStmt = $pdo->prepare($ordersSql);
    $ordersStmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
    $ordersStmt->execute();
    
    $orders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Enhance each order with customer and product info
    foreach ($orders as &$order) {
        // Get customer info if customer_id exists
        if (!empty($order['customer_id'])) {
            $customerSql = "SELECT full_name, phone, address FROM customers WHERE customer_id = :customer_id";
            $customerStmt = $pdo->prepare($customerSql);
            $customerStmt->bindParam(':customer_id', $order['customer_id'], PDO::PARAM_INT);
            $customerStmt->execute();
            
            $customerData = $customerStmt->fetch(PDO::FETCH_ASSOC);
            if ($customerData) {
                $order['customer_name'] = $customerData['full_name'];
                $order['customer_phone'] = $customerData['phone'];
                $order['customer_address'] = $customerData['address'];
            }
        }
        
        // Set defaults if no customer info
        if (empty($order['customer_name'])) {
            $order['customer_name'] = 'Unknown Customer';
        }
        if (empty($order['customer_phone'])) {
            $order['customer_phone'] = '';
        }
        if (empty($order['customer_address'])) {
            $order['customer_address'] = '';
        }
        
        // Get first product info for display
        $productSql = "
            SELECT 
                od.quantity,
                od.unit_price,
                p.product_name,
                v.size,
                v.color
            FROM order_details od
            JOIN variants v ON od.variant_id = v.variant_id
            JOIN products p ON v.product_id = p.product_id
            WHERE od.order_id = :order_id
            LIMIT 1
        ";
        
        $productStmt = $pdo->prepare($productSql);
        $productStmt->bindParam(':order_id', $order['order_id'], PDO::PARAM_INT);
        $productStmt->execute();
        
        $productData = $productStmt->fetch(PDO::FETCH_ASSOC);
        if ($productData) {
            $order = array_merge($order, $productData);
        } else {
            // Default values
            $order['quantity'] = 1;
            $order['unit_price'] = $order['total_price'];
            $order['product_name'] = 'Unknown Product';
            $order['size'] = '';
            $order['color'] = '';
        }
    }
    
    echo json_encode([
        'success' => true,
        'data' => $orders,
        'count' => count($orders)
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Lỗi hệ thống: ' . $e->getMessage(),
        'debug' => [
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]
    ]);
}
?>