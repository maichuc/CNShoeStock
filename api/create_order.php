<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../config/cau_hinh_csdl.php';

session_start();

// Thiết lập session mặc định nếu chưa có (cho testing)
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 7;
    $_SESSION['warehouse_id'] = 6;
}

try {
    $database = new Database();
    $pdo = $database->getConnection();
    
    // Lấy dữ liệu JSON
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    
    if (!$input) {
        echo json_encode(['success' => false, 'message' => 'Dữ liệu đầu vào không hợp lệ']);
        exit;
    }
    
    $customerData = $input['customer'] ?? null;
    $orderData = $input['order'] ?? null;
    $userId = $_SESSION['user_id'];
    $warehouseId = $_SESSION['warehouse_id'];
    
    // Kiểm tra warehouse_id
    if (!$warehouseId) {
        echo json_encode(['success' => false, 'message' => 'Không xác định được warehouse']);
        exit;
    }
    
    // Kiểm tra dữ liệu bắt buộc
    if (!$customerData || empty($customerData['name']) || empty($customerData['phone']) || empty($customerData['address'])) {
        echo json_encode(['success' => false, 'message' => 'Thiếu thông tin khách hàng bắt buộc']);
        exit;
    }
    
    if (!$orderData || empty($orderData['items']) || !is_array($orderData['items']) || count($orderData['items']) === 0) {
        echo json_encode(['success' => false, 'message' => 'Thiếu thông tin sản phẩm bắt buộc. Vui lòng thêm ít nhất một sản phẩm']);
        exit;
    }
    
    // Kiểm tra số điện thoại
    if (!preg_match('/^[0-9]{10,11}$/', $customerData['phone'])) {
        echo json_encode(['success' => false, 'message' => 'Số điện thoại không hợp lệ']);
        exit;
    }
    
    // Bắt đầu transaction
    $pdo->beginTransaction();
    
    try {
        // Kiểm tra tồn kho cho tất cả sản phẩm
        foreach ($orderData['items'] as $item) {
            if (empty($item['variant_id']) || empty($item['quantity']) || empty($item['unit_price'])) {
                throw new Exception('Thông tin sản phẩm không đầy đủ');
            }
            
            $checkStockSql = "SELECT quantity FROM inventory WHERE variant_id = ? AND warehouse_id = ?";
            $checkStockStmt = $pdo->prepare($checkStockSql);
            $checkStockStmt->execute([$item['variant_id'], $orderData['warehouse_id']]);
            
            $stockResult = $checkStockStmt->fetch(PDO::FETCH_ASSOC);
            $availableStock = $stockResult ? $stockResult['quantity'] : 0;
            
            if ($availableStock < $item['quantity']) {
                // Get product name for better error message
                $productSql = "SELECT p.product_name, pv.size, pv.color 
                              FROM product_variants pv 
                              JOIN products p ON pv.product_id = p.product_id 
                              WHERE pv.variant_id = ?";
                $productStmt = $pdo->prepare($productSql);
                $productStmt->execute([$item['variant_id']]);
                $productInfo = $productStmt->fetch(PDO::FETCH_ASSOC);
                
                $productName = $productInfo ? $productInfo['product_name'] . ' - ' . $productInfo['color'] . ' - Size ' . $productInfo['size'] : 'Sản phẩm';
                throw new Exception('Tồn kho không đủ cho ' . $productName . '! Chỉ còn ' . $availableStock . ' sản phẩm');
            }
        }
        
        // Kiểm tra khách hàng có tồn tại không (trong cùng warehouse)
        $customerSql = "SELECT customer_id FROM customers WHERE phone = ? AND warehouse_id = ?";
        $customerStmt = $pdo->prepare($customerSql);
        $customerStmt->execute([$customerData['phone'], $warehouseId]);
        
        $existingCustomer = $customerStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existingCustomer) {
            // Cập nhật thông tin khách hàng (chỉ update trong cùng warehouse)
            $customerId = $existingCustomer['customer_id'];
            $updateCustomerSql = "UPDATE customers SET full_name = ?, email = ?, address = ?, note = ? WHERE customer_id = ? AND warehouse_id = ?";
            $updateCustomerStmt = $pdo->prepare($updateCustomerSql);
            $updateCustomerStmt->execute([
                $customerData['name'],
                $customerData['email'] ?? '',
                $customerData['address'],
                $customerData['note'] ?? '',
                $customerId,
                $warehouseId
            ]);
        } else {
            // Tạo khách hàng mới với warehouse_id
            $insertCustomerSql = "INSERT INTO customers (full_name, phone, email, address, note, warehouse_id) VALUES (?, ?, ?, ?, ?, ?)";
            $insertCustomerStmt = $pdo->prepare($insertCustomerSql);
            $insertCustomerStmt->execute([
                $customerData['name'],
                $customerData['phone'],
                $customerData['email'] ?? '',
                $customerData['address'],
                $customerData['note'] ?? '',
                $warehouseId
            ]);
            
            $customerId = $pdo->lastInsertId();
        }
        
        // Tạo đơn hàng
        $insertOrderSql = "INSERT INTO orders (warehouse_id, customer_id, status, discount, total_price, created_by) VALUES (?, ?, 'pending', ?, ?, ?)";
        $insertOrderStmt = $pdo->prepare($insertOrderSql);
        $insertOrderStmt->execute([
            $orderData['warehouse_id'],
            $customerId,
            $orderData['discount'] ?? 0,
            $orderData['total_price'],
            $userId
        ]);
        
        $orderId = $pdo->lastInsertId();
        
        // Tạo chi tiết đơn hàng cho tất cả sản phẩm
        $insertOrderDetailSql = "INSERT INTO order_details (order_id, variant_id, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?)";
        $insertOrderDetailStmt = $pdo->prepare($insertOrderDetailSql);
        
        foreach ($orderData['items'] as $item) {
            $insertOrderDetailStmt->execute([
                $orderId,
                $item['variant_id'],
                $item['quantity'],
                $item['unit_price'],
                $item['total_price']
            ]);
        }
        
        // Commit transaction
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Tạo đơn hàng thành công với ' . count($orderData['items']) . ' sản phẩm',
            'data' => [
                'order_id' => $orderId,
                'customer_id' => $customerId,
                'items_count' => count($orderData['items'])
            ],
            'redirect' => 'quan_ly_don_hang.php?status=pending'
        ]);
        
    } catch (Exception $e) {
        $pdo->rollback();
        throw $e;
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>