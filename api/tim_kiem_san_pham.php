<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../config/database.php';

try {
    $database = new Database();
    $pdo = $database->getConnection();
    
    $query = $_GET['query'] ?? '';
    $categoryId = $_GET['category_id'] ?? null;
    $productType = $_GET['product_type'] ?? null;
    $warehouseId = $_GET['warehouse_id'] ?? null;
    
    // Gỡ lỗi log ((commented out for production))
    // error_log("DEBUG search_products.php - Truy vấn: $query, Category: $categoryId, Type: $productType, Warehouse: $warehouseId");
    
    // Xây dựng truy vấn SQL - CHỈ hiển thị products có trong warehouse hiện tại
    $sql = "SELECT 
                p.product_id,
                p.name as product_name,
                pv.variant_id,
                pv.sku,
                pv.color,
                pv.size,
                pv.price,
                i.quantity as stock
            FROM products p
            INNER JOIN product_variants pv ON p.product_id = pv.product_id
            INNER JOIN inventory i ON pv.variant_id = i.variant_id
            WHERE i.warehouse_id = :warehouse_id";
    
    $params = [':warehouse_id' => $warehouseId];
    
    // Thêm tìm kiếm theo tên sản phẩm nếu có truy vấn
    if (!empty($query)) {
        $sql .= " AND p.name LIKE :query";
        $params[':query'] = '%' . $query . '%';
    }
    
    // Thêm category filter nếu có
    if ($categoryId) {
        $sql .= " AND p.category_id = :category_id";
        $params[':category_id'] = $categoryId;
    }
    
    // Thêm product type filter nếu có
    if ($productType) {
        $sql .= " AND p.type = :product_type";
        $params[':product_type'] = $productType;
    }
    
    // Hiển thị all products (comment out stock filter for now)
    // $sql .= " AND COALESCE(i.quantity, 0) > 0";
    
    $sql .= " ORDER BY p.name, pv.size LIMIT 20";
    
    $stmt = $pdo->prepare($sql);
    
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Gỡ lỗi log ((commented out for production))
    // error_log("DEBUG search_products.php - Found " . count($products) . " products");
    
    echo json_encode([
        'success' => true,
        'data' => $products
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>