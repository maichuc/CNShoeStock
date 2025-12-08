<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../config/database.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    $database = new Database();
    $pdo = $database->getConnection();
    
    $orderId = $_GET['order_id'] ?? null;
    
    if (!$orderId) {
        echo json_encode(['success' => false, 'message' => 'Missing order ID']);
        exit;
    }
    
    // Lấy đơn hàng với thông tin khách hàng
    $orderQuery = "
        SELECT 
            o.*,
            c.full_name as customer_name,
            c.phone as customer_phone,
            c.email as customer_email,
            c.address as customer_address,
            c.note as customer_note,
            u.username as created_by_name
        FROM orders o
        LEFT JOIN customers c ON o.customer_id = c.customer_id
        LEFT JOIN users u ON o.created_by = u.user_id
        WHERE o.order_id = :order_id AND o.warehouse_id = :warehouse_id
    ";
    
    $orderStmt = $pdo->prepare($orderQuery);
    $orderStmt->bindParam(':order_id', $orderId);
    $orderStmt->bindParam(':warehouse_id', $_SESSION['warehouse_id']);
    $orderStmt->execute();
    
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        echo json_encode(['success' => false, 'message' => 'Order not found']);
        exit;
    }
    
    // Lấy chi tiết đơn hàng với thông tin sản phẩm
    $detailsQuery = "
        SELECT 
            od.*,
            p.name as product_name,
            pv.sku,
            pv.color,
            pv.size
        FROM order_details od
        LEFT JOIN product_variants pv ON od.variant_id = pv.variant_id
        LEFT JOIN products p ON pv.product_id = p.product_id
        WHERE od.order_id = :order_id
    ";
    
    $detailsStmt = $pdo->prepare($detailsQuery);
    $detailsStmt->bindParam(':order_id', $orderId);
    $detailsStmt->execute();
    
    $details = $detailsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Chuẩn bị response data
    $responseData = [
        'order' => [
            'order_id' => $order['order_id'],
            'status' => $order['status'],
            'discount' => $order['discount'],
            'total_price' => $order['total_price'],
            'created_at' => $order['created_at'],
            'updated_at' => $order['updated_at'],
            'created_by_name' => $order['created_by_name']
        ],
        'customer' => [
            'full_name' => $order['customer_name'],
            'phone' => $order['customer_phone'],
            'email' => $order['customer_email'],
            'address' => $order['customer_address'],
            'note' => $order['customer_note']
        ],
        'details' => $details
    ];
    
    echo json_encode([
        'success' => true,
        'data' => $responseData
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>