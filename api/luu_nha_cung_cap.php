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
    $supplierId = isset($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null;
    $supplierCode = trim($_POST['supplier_code']);
    $name = trim($_POST['name']);
    $shortName = trim($_POST['short_name']);
    $taxCode = trim($_POST['tax_code']);
    $type = trim($_POST['type']);
    $address = trim($_POST['address']);
    $province = trim($_POST['province']);
    $district = trim($_POST['district']);
    $phone = preg_replace('/[^\d]/', '', $_POST['phone']); // Remove formatting
    $email = trim($_POST['email']);
    $website = trim($_POST['website']);
    $contactPerson = trim($_POST['contact_person']);
    $contactPosition = trim($_POST['contact_position']);
    $notes = trim($_POST['notes']);
    $status = $_POST['status'];
    $userId = $_SESSION['user_id'];
    $warehouseId = $_SESSION['warehouse_id'] ?? null;
    
    if (!$warehouseId) {
        throw new Exception('Không thể xác định warehouse');
    }

    // Validation
    if (empty($name) || strlen($name) < 3) {
        throw new Exception('Tên nhà cung cấp phải có ít nhất 3 ký tự');
    }

    if (empty($taxCode) || strlen($taxCode) < 10 || strlen($taxCode) > 13) {
        throw new Exception('Mã số thuế phải có 10-13 chữ số');
    }

    if (!preg_match('/^\d+$/', $taxCode)) {
        throw new Exception('Mã số thuế chỉ được chứa số');
    }

    if (empty($phone) || !preg_match('/^(84|0)[3-9]\d{8}$/', $phone)) {
        throw new Exception('Số điện thoại không đúng định dạng Việt Nam');
    }

    if (empty($address) || strlen($address) < 10) {
        throw new Exception('Địa chỉ phải có ít nhất 10 ký tự');
    }

    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Địa chỉ email không hợp lệ');
    }

    // Kiểm tra mã số thuế trùng lặp
    $checkSql = "SELECT supplier_id, name FROM suppliers WHERE tax_code = ?";
    $params = [$taxCode];
    
    if ($supplierId) {
        $checkSql .= " AND supplier_id != ?";
        $params[] = $supplierId;
    }
    
    $checkStmt = $pdo->prepare($checkSql);
    $checkStmt->execute($params);
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) {
        throw new Exception('Mã số thuế đã tồn tại cho nhà cung cấp: ' . $existing['name']);
    }

    $pdo->beginTransaction();

    if ($supplierId) {
        // Cập nhật nhà cung cấp hiện có
        $sql = "UPDATE suppliers SET 
                name = ?, short_name = ?, tax_code = ?, type = ?, 
                address = ?, province = ?, district = ?, phone = ?, 
                email = ?, website = ?, contact_person = ?, contact_position = ?, 
                notes = ?, status = ?, updated_at = CURRENT_TIMESTAMP, updated_by = ?
                WHERE supplier_id = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $name, $shortName, $taxCode, $type,
            $address, $province, $district, $phone,
            $email, $website, $contactPerson, $contactPosition,
            $notes, $status, $userId, $supplierId
        ]);
        
        $message = 'Cập nhật nhà cung cấp thành công';
        $resultId = $supplierId;
    } else {
        // Thêm nhà cung cấp mới
        $sql = "INSERT INTO suppliers (
                supplier_code, name, short_name, tax_code, type,
                address, province, district, phone, email, website,
                contact_person, contact_position, notes, status,
                warehouse_id, created_by, updated_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $supplierCode, $name, $shortName, $taxCode, $type,
            $address, $province, $district, $phone, $email, $website,
            $contactPerson, $contactPosition, $notes, $status,
            $warehouseId, $userId, $userId
        ]);
        
        $resultId = $pdo->lastInsertId();
        $message = 'Thêm nhà cung cấp thành công';
    }

    // Lấy dữ liệu đã lưu
    $getStmt = $pdo->prepare("SELECT * FROM suppliers WHERE supplier_id = ?");
    $getStmt->execute([$resultId]);
    $savedData = $getStmt->fetch(PDO::FETCH_ASSOC);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => $message,
        'data' => [
            'supplier_id' => $savedData['supplier_id'],
            'supplier_code' => $savedData['supplier_code'],
            'name' => $savedData['name'],
            'phone' => $savedData['phone'],
            'email' => $savedData['email'],
            'status' => $savedData['status']
        ]
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollback();
    }
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>