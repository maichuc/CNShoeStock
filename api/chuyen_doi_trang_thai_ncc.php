<?php
header('Content-Type: application/json');
session_start();
require_once '../config/cau_hinh_csdl.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$database = new Database();
$pdo = $database->getConnection();

try {
    $supplierId = isset($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : 0;
    $newStatus = isset($_POST['status']) ? $_POST['status'] : '';
    $userId = $_SESSION['user_id'];
    $userRole = $_SESSION['role'] ?? 'employee';
    
    if (!$supplierId || !in_array($newStatus, ['active', 'inactive'])) {
        throw new Exception('Dữ liệu không hợp lệ');
    }
    
    // Kiểm tra quyền: chỉ admin/manager mới có thể kích hoạt lại
    if ($newStatus === 'active' && !in_array($userRole, ['admin', 'manager'])) {
        throw new Exception('Chỉ Admin và Quản lý mới có thể kích hoạt nhà cung cấp');
    }
    
    // Get current supplier info
    $stmt = $pdo->prepare("SELECT name, status FROM suppliers WHERE supplier_id = ?");
    $stmt->execute([$supplierId]);
    $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$supplier) {
        throw new Exception('Không tìm thấy nhà cung cấp');
    }
    
    if ($supplier['status'] == $newStatus) {
        throw new Exception('Trạng thái đã được cập nhật');
    }
    
    // Update status
    $updateStmt = $pdo->prepare("
        UPDATE suppliers 
        SET status = ?, updated_at = CURRENT_TIMESTAMP, updated_by = ?
        WHERE supplier_id = ?
    ");
    
    $updateStmt->execute([$newStatus, $userId, $supplierId]);
    
    $statusText = $newStatus == 'active' ? 'Hoạt động' : 'Tạm ngưng';
    
    echo json_encode([
        'success' => true,
        'message' => "Đã cập nhật trạng thái nhà cung cấp '{$supplier['name']}' thành '{$statusText}'",
        'data' => [
            'supplier_id' => $supplierId,
            'status' => $newStatus,
            'status_text' => $statusText
        ]
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>