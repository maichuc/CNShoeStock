<?php
// Suppress all PHP errors and warnings for clean JSON output
error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json');

// session_start();
require_once __DIR__ . '/../../config/database.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$database = new Database();
$pdo = $database->getConnection();

try {
    $supplierId = isset($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : 0;
    
    if (!$supplierId) {
        throw new Exception('ID nhà cung cấp không hợp lệ');
    }
    
    // Lấy thông tin nhà cung cấp trước khi xóa
    $stmt = $pdo->prepare("SELECT name, supplier_code FROM suppliers WHERE supplier_id = ?");
    $stmt->execute([$supplierId]);
    $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$supplier) {
        throw new Exception('Không tìm thấy nhà cung cấp');
    }
    
    // Kiểm tra xem nhà cung cấp có đang được sử dụng không
    $checkTables = [
        'purchase_orders' => 'supplier_id',
        'stock_receipts' => 'supplier_id',
        'products' => 'supplier_id'
    ];
    
    $dependencies = [];
    foreach ($checkTables as $table => $column) {
        try {
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE $column = ?");
            $checkStmt->execute([$supplierId]);
            $count = $checkStmt->fetchColumn();
            
            if ($count > 0) {
                $dependencies[] = "$table ($count bản ghi)";
            }
        } catch (Exception $e) {
            // Bảng có thể không tồn tại, tiếp tục
            continue;
        }
    }
    
    if (!empty($dependencies)) {
        throw new Exception('Không thể xóa nhà cung cấp này vì đang được sử dụng trong: ' . implode(', ', $dependencies));
    }
    
    $pdo->beginTransaction();
    
    // Xóa nhà cung cấp
    $deleteStmt = $pdo->prepare("DELETE FROM suppliers WHERE supplier_id = ?");
    $deleteStmt->execute([$supplierId]);
    
    if ($deleteStmt->rowCount() === 0) {
        throw new Exception('Không thể xóa nhà cung cấp');
    }
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'message' => "Đã xóa nhà cung cấp '{$supplier['name']}' (Mã: {$supplier['supplier_code']}) thành công"
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