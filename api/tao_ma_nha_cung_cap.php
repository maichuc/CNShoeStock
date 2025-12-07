<?php
/**
 * API: Tạo mã nhà cung cấp tự động
 * Chức năng: Tạo mã nhà cung cấp theo format NCC001, NCC002, ...
 */

header('Content-Type: application/json');
session_start();

require_once '../config/cau_hinh_csdl.php';

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Chưa đăng nhập');
    }
    
    $database = new Database();
    $pdo = $database->getConnection();
    
    // Get latest supplier code
    $stmt = $pdo->query("SELECT supplier_code FROM suppliers WHERE supplier_code LIKE 'NCC%' ORDER BY supplier_id DESC LIMIT 1");
    $lastCode = $stmt->fetchColumn();
    
    if ($lastCode) {
        // Extract number from NCC001 -> 001
        $number = intval(substr($lastCode, 3));
        $newNumber = $number + 1;
    } else {
        $newNumber = 1;
    }
    
    // Format: NCC001, NCC002, ...
    $newCode = 'NCC' . str_pad($newNumber, 3, '0', STR_PAD_LEFT);
    
    echo json_encode([
        'success' => true,
        'supplier_code' => $newCode
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
