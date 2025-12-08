<?php
header('Content-Type: application/json');
require_once '../config/database.php';
require_once '../helpers/GhiNhatKyKiemToan.php';
session_start();

try {
    $database = new Database();
    $pdo = $database->getConnection();
    
    $response = ['success' => false, 'message' => ''];
    
    // Kiểm tra đăng nhập
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Chưa đăng nhập');
    }
    
    $userId = $_SESSION['user_id'];
    $warehouseId = $_SESSION['warehouse_id'] ?? null;
    
    if (!$warehouseId) {
        throw new Exception('Không thể xác định warehouse');
    }
    
    // Lấy dữ liệu từ POST
    $name = trim($_POST['name'] ?? '');
    $contactName = trim($_POST['contact_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    
    // Kiểm tra
    if (empty($name)) {
        throw new Exception('Tên nhà cung cấp không được để trống');
    }
    
    if (strlen($name) > 200) {
        throw new Exception('Tên nhà cung cấp không được quá 200 ký tự');
    }
    
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Email không hợp lệ');
    }
    
    // Kiểm tra trùng lặp trong warehouse hiện tại
    $stmt = $pdo->prepare("SELECT supplier_id FROM suppliers WHERE name = ? AND warehouse_id = ?");
    $stmt->execute([$name, $warehouseId]);
    if ($stmt->fetch()) {
        throw new Exception('Nhà cung cấp với tên này đã tồn tại');
    }
    
    if ($phone) {
        $stmt = $pdo->prepare("SELECT supplier_id FROM suppliers WHERE phone = ? AND warehouse_id = ?");
        $stmt->execute([$phone, $warehouseId]);
        if ($stmt->fetch()) {
            throw new Exception('Số điện thoại này đã được sử dụng');
        }
    }
    
    if ($email) {
        $stmt = $pdo->prepare("SELECT supplier_id FROM suppliers WHERE email = ? AND warehouse_id = ?");
        $stmt->execute([$email, $warehouseId]);
        if ($stmt->fetch()) {
            throw new Exception('Email này đã được sử dụng');
        }
    }
    
    // Thêm nhà cung cấp mới với warehouse_id
    $stmt = $pdo->prepare("
        INSERT INTO suppliers (name, contact_name, phone, email, address, warehouse_id) 
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $name,
        $contactName ?: null,
        $phone ?: null,
        $email ?: null,
        $address ?: null,
        $warehouseId
    ]);
    
    $supplierId = $pdo->lastInsertId();
    
    // Ghi nhật ký audit
    $auditLogger = new AuditLogger($pdo);
    $auditLogger->log(
        $userId,
        'create_supplier',
        'suppliers',
        $supplierId,
        null,
        [
            'name' => $name,
            'contact_name' => $contactName,
            'phone' => $phone,
            'email' => $email
        ]
    );
    
    $response['success'] = true;
    $response['supplier_id'] = $supplierId;
    $response['name'] = $name;
    $response['message'] = 'Thêm nhà cung cấp thành công';
    
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
?>
