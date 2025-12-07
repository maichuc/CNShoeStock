<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../config/cau_hinh_csdl.php';
session_start();

try {
    $database = new Database();
    $pdo = $database->getConnection();
    
    $query = $_GET['query'] ?? '';
    $warehouseId = $_SESSION['warehouse_id'] ?? null;
    
    if (strlen($query) < 2) {
        echo json_encode(['success' => false, 'message' => 'Query too short']);
        exit;
    }
    
    if (!$warehouseId) {
        echo json_encode(['success' => false, 'message' => 'Warehouse not found']);
        exit;
    }
    
    // Search customers by name or phone (only in current warehouse)
    $sql = "SELECT customer_id, full_name, phone, email, address, note 
            FROM customers 
            WHERE (full_name LIKE :query OR phone LIKE :query) AND warehouse_id = :warehouse_id
            ORDER BY full_name 
            LIMIT 10";
    
    $stmt = $pdo->prepare($sql);
    $searchQuery = '%' . $query . '%';
    $stmt->bindParam(':query', $searchQuery);
    $stmt->bindParam(':warehouse_id', $warehouseId);
    $stmt->execute();
    
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $customers
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>