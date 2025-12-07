<?php
session_start();
require_once 'config/database.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$userWarehouseId = $_SESSION['warehouse_id'] ?? null;

if (!$userWarehouseId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Warehouse ID not found']);
    exit();
}

// Kết nối database
$database = new Database();
$pdo = $database->getConnection();

// Lấy parameters
$productId = $_GET['product_id'] ?? null;
$size = $_GET['size'] ?? null;

if (!$productId || !$size) {
    echo json_encode(['success' => false, 'message' => 'Missing parameters']);
    exit();
}

try {
    // Lấy giá của size từ product_variants theo warehouse_id
    $sql = "SELECT pv.price, pv.variant_id, pv.sku, p.name as product_name, pv.warehouse_id
            FROM product_variants pv
            INNER JOIN products p ON pv.product_id = p.product_id
            WHERE pv.product_id = :product_id 
            AND pv.size = :size 
            AND pv.warehouse_id = :warehouse_id
            ORDER BY pv.created_at DESC
            LIMIT 1";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':product_id' => $productId,
        ':size' => $size,
        ':warehouse_id' => $userWarehouseId
    ]);
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Debug log
    error_log("API GET SIZE PRICE - Product ID: $productId, Size: $size, Warehouse: $userWarehouseId");
    error_log("API GET SIZE PRICE - Result: " . json_encode($result));
    
    if ($result) {
        echo json_encode([
            'success' => true,
            'price' => floatval($result['price']),
            'variant_id' => $result['variant_id'],
            'sku' => $result['sku'],
            'product_name' => $result['product_name'],
            'warehouse_id' => $result['warehouse_id'],
            'debug' => [
                'searched_warehouse_id' => $userWarehouseId,
                'found_warehouse_id' => $result['warehouse_id']
            ]
        ]);
    } else {
        // Không tìm thấy giá cũ, trả về giá 0
        echo json_encode([
            'success' => true,
            'price' => 0,
            'variant_id' => null,
            'sku' => null,
            'product_name' => null,
            'message' => 'No previous price found for this size',
            'debug' => [
                'searched_product_id' => $productId,
                'searched_size' => $size,
                'searched_warehouse_id' => $userWarehouseId
            ]
        ]);
    }
    
} catch (PDOException $e) {
    error_log("Error fetching size price: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error', 'error' => $e->getMessage()]);
}
