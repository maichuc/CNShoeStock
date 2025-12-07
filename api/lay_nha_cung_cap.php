<?php
header('Content-Type: application/json');
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$database = new Database();
$pdo = $database->getConnection();

try {
    $supplierId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    
    if (!$supplierId) {
        throw new Exception('ID nhà cung cấp không hợp lệ');
    }
    
    $stmt = $pdo->prepare("
        SELECT s.*, 
               u1.full_name as created_by_name,
               u2.full_name as updated_by_name
        FROM suppliers s
        LEFT JOIN users u1 ON s.created_by = u1.user_id
        LEFT JOIN users u2 ON s.updated_by = u2.user_id
        WHERE s.supplier_id = ?
    ");
    
    $stmt->execute([$supplierId]);
    $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$supplier) {
        throw new Exception('Không tìm thấy nhà cung cấp');
    }
    
    // Format phone number for display
    $phone = $supplier['phone'];
    if (strlen($phone) == 10) {
        $phone = substr($phone, 0, 4) . '.' . substr($phone, 4, 3) . '.' . substr($phone, 7);
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'supplier_id' => $supplier['supplier_id'],
            'supplier_code' => $supplier['supplier_code'],
            'name' => $supplier['name'],
            'short_name' => $supplier['short_name'],
            'tax_code' => $supplier['tax_code'],
            'type' => $supplier['type'],
            'address' => $supplier['address'],
            'province' => $supplier['province'],
            'district' => $supplier['district'],
            'phone' => $phone,
            'phone_raw' => $supplier['phone'],
            'email' => $supplier['email'],
            'website' => $supplier['website'],
            'contact_person' => $supplier['contact_person'],
            'contact_position' => $supplier['contact_position'],
            'notes' => $supplier['notes'],
            'status' => $supplier['status'],
            'created_at' => $supplier['created_at'],
            'updated_at' => $supplier['updated_at'],
            'created_by_name' => $supplier['created_by_name'],
            'updated_by_name' => $supplier['updated_by_name']
        ]
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>