<?php
/**
 * API: Xác nhận phiếu nhập
 * Chức năng: Xác nhận phiếu nhập kho đã nhận đầy đủ hàng
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
    
    $receipt_id = $_POST['receipt_id'] ?? null;
    
    if (!$receipt_id) {
        throw new Exception('Thiếu mã phiếu nhập');
    }
    
    // Cập nhật trạng thái phiếu nhập
    $stmt = $pdo->prepare("UPDATE stock_receipts SET status = 'confirmed', confirmed_at = NOW(), confirmed_by = ? WHERE receipt_id = ?");
    $stmt->execute([$_SESSION['user_id'], $receipt_id]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Xác nhận phiếu nhập thành công'
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
