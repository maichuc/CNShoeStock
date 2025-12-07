<?php
/**
 * API: Kiểm tra nhà cung cấp trùng
 * Chức năng: Kiểm tra xem nhà cung cấp đã tồn tại chưa (theo tên, email, phone)
 */

header('Content-Type: application/json');
session_start();

require_once '../config/database.php';

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Chưa đăng nhập');
    }
    
    $database = new Database();
    $pdo = $database->getConnection();
    
    $name = $_POST['name'] ?? '';
    $email = $_POST['email'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $exclude_id = $_POST['exclude_id'] ?? null;
    
    $duplicates = [];
    
    // Kiểm tra theo tên
    if ($name) {
        $sql = "SELECT supplier_id, name FROM suppliers WHERE name = ? AND is_deleted = 0";
        if ($exclude_id) $sql .= " AND supplier_id != ?";
        
        $stmt = $pdo->prepare($sql);
        $exclude_id ? $stmt->execute([$name, $exclude_id]) : $stmt->execute([$name]);
        
        if ($result = $stmt->fetch()) {
            $duplicates['name'] = $result;
        }
    }
    
    // Kiểm tra theo email
    if ($email) {
        $sql = "SELECT supplier_id, name, email FROM suppliers WHERE email = ? AND is_deleted = 0";
        if ($exclude_id) $sql .= " AND supplier_id != ?";
        
        $stmt = $pdo->prepare($sql);
        $exclude_id ? $stmt->execute([$email, $exclude_id]) : $stmt->execute([$email]);
        
        if ($result = $stmt->fetch()) {
            $duplicates['email'] = $result;
        }
    }
    
    // Kiểm tra theo số điện thoại
    if ($phone) {
        $sql = "SELECT supplier_id, name, phone FROM suppliers WHERE phone = ? AND is_deleted = 0";
        if ($exclude_id) $sql .= " AND supplier_id != ?";
        
        $stmt = $pdo->prepare($sql);
        $exclude_id ? $stmt->execute([$phone, $exclude_id]) : $stmt->execute([$phone]);
        
        if ($result = $stmt->fetch()) {
            $duplicates['phone'] = $result;
        }
    }
    
    echo json_encode([
        'success' => true,
        'has_duplicates' => !empty($duplicates),
        'duplicates' => $duplicates
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
