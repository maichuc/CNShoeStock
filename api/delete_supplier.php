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
    $supplierId = isset($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : 0;
    
    if (!$supplierId) {
        throw new Exception('ID nhà cung cấp không hợp lệ');
    }
    
    // Get supplier info before deletion
    $stmt = $pdo->prepare("SELECT name, supplier_code FROM suppliers WHERE supplier_id = ?");
    $stmt->execute([$supplierId]);
    $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$supplier) {
        throw new Exception('Không tìm thấy nhà cung cấp');
    }
    
    // Check if supplier is being used in other tables
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
            // Table might not exist, continue
            continue;
        }
    }
    
    if (!empty($dependencies)) {
        throw new Exception('Không thể xóa nhà cung cấp này vì đang được sử dụng trong: ' . implode(', ', $dependencies));
    }
    
    $pdo->beginTransaction();
    
    // Delete supplier
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