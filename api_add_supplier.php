<?php
session_start(); // Thêm session management
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Vui lòng đăng nhập']);
    exit;
}

// Lấy warehouse_id từ session
$userWarehouseId = $_SESSION['warehouse_id'] ?? null;
if (empty($userWarehouseId)) {
    echo json_encode(['success' => false, 'message' => 'Không xác định được warehouse hiện tại']);
    exit;
}

// Database connection
require_once 'config/database.php';

try {
    $database = new Database();
    $pdo = $database->getConnection();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Only POST method allowed']);
    exit;
}

try {
    // Lấy dữ liệu từ POST
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $contact_name = trim($_POST['contact_name'] ?? '');
    $contact_phone = trim($_POST['contact_phone'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    // Validate dữ liệu
    if (empty($name)) {
        echo json_encode(['success' => false, 'message' => 'Tên nhà cung cấp không được để trống']);
        exit;
    }

    // Validate email nếu có
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Email không hợp lệ']);
        exit;
    }

    // Kiểm tra nhà cung cấp đã tồn tại chưa TRONG WAREHOUSE HIỆN TẠI
    $checkStmt = $pdo->prepare("SELECT supplier_id FROM suppliers WHERE name = ? AND warehouse_id = ? LIMIT 1");
    $checkStmt->execute([$name, $userWarehouseId]);
    
    if ($checkStmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Nhà cung cấp này đã tồn tại trong warehouse hiện tại']);
        exit;
    }

    // Thêm nhà cung cấp mới VÀO WAREHOUSE HIỆN TẠI
    $stmt = $pdo->prepare("
        INSERT INTO suppliers (warehouse_id, name, phone, email, address, contact_name, contact_info, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    
    // Tạo contact_info từ contact_name và contact_phone
    $contact_info = [];
    if (!empty($contact_name)) {
        $contact_info[] = "Tên: " . $contact_name;
    }
    if (!empty($contact_phone)) {
        $contact_info[] = "SĐT: " . $contact_phone;
    }
    if (!empty($notes)) {
        $contact_info[] = "Ghi chú: " . $notes;
    }
    $contact_info_text = !empty($contact_info) ? implode("; ", $contact_info) : null;
    
    $stmt->execute([
        $userWarehouseId, // Thêm warehouse_id
        $name,
        $phone ?: null,
        $email ?: null,
        $address ?: null,
        $contact_name ?: null,
        $contact_info_text
    ]);

    $supplier_id = $pdo->lastInsertId();

    echo json_encode([
        'success' => true,
        'message' => 'Thêm nhà cung cấp thành công',
        'supplier_id' => $supplier_id,
        'supplier_name' => $name
    ]);

} catch (PDOException $e) {
    error_log("Database error in api_add_supplier.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Lỗi database: ' . $e->getMessage()]);
} catch (Exception $e) {
    error_log("General error in api_add_supplier.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Lỗi hệ thống: ' . $e->getMessage()]);
}
?>