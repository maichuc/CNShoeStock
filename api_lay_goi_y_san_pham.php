<?php
session_start();
header('Content-Type: application/json');

require_once 'config/cau_hinh_csdl.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$database = new Database();
$pdo = $database->getConnection();

try {
    $type = $_POST['type'] ?? '';
    $brand = $_POST['brand'] ?? '';
    $color = $_POST['color'] ?? '';
    $warehouse_id = $_POST['warehouse_id'] ?? $_SESSION['warehouse_id'];

    if (!$warehouse_id) {
        echo json_encode(['success' => false, 'message' => 'Warehouse ID not found']);
        exit();
    }

    $suggestions = [];
    
    // Build query based on available filters
    $whereConditions = ['p.warehouse_id = ?'];
    $params = [$warehouse_id];
    
    if (!empty($type)) {
        $whereConditions[] = 'p.type LIKE ?';
        $params[] = '%' . $type . '%';
    }
    
    if (!empty($brand)) {
        $whereConditions[] = 'p.brand LIKE ?';
        $params[] = '%' . $brand . '%';
    }
    
    if (!empty($color)) {
        $whereConditions[] = 'pv.color LIKE ?';
        $params[] = '%' . $color . '%';
    }
    
    $sql = "
        SELECT DISTINCT p.name 
        FROM products p 
        LEFT JOIN product_variants pv ON p.product_id = pv.product_id 
        WHERE " . implode(' AND ', $whereConditions) . "
        AND p.name IS NOT NULL 
        ORDER BY p.name ASC 
        LIMIT 10
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo json_encode([
        'success' => true,
        'suggestions' => $results
    ]);

} catch (Exception $e) {
    error_log("Error getting product suggestions: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>