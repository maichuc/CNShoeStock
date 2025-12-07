<?php
session_start();
require_once 'config/cau_hinh_csdl.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    header('Location: login.html');
    exit();
}

// Kiểm tra ID sản phẩm
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: danh_sach_san_pham.php?error=invalid_id');
    exit();
}

$product_id = (int)$_GET['id'];
$currentUser = $_SESSION['user_id'];
$userWarehouseId = $_SESSION['warehouse_id'] ?? null;

// Kết nối database
$database = new Database();
$pdo = $database->getConnection();

// Lấy warehouse_id từ database nếu chưa có trong session
if (!$userWarehouseId) {
    $stmt = $pdo->prepare("SELECT warehouse_id FROM users WHERE user_id = ?");
    $stmt->execute([$currentUser]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result) {
        $userWarehouseId = $result['warehouse_id'];
        $_SESSION['warehouse_id'] = $userWarehouseId;
    }
}

try {
    // Kiểm tra sản phẩm có tồn tại và thuộc về warehouse hiện tại không
    $sql_check = "SELECT name, status FROM products WHERE product_id = :product_id AND warehouse_id = :warehouse_id";
    $stmt_check = $pdo->prepare($sql_check);
    $stmt_check->bindParam(':product_id', $product_id, PDO::PARAM_INT);
    $stmt_check->bindParam(':warehouse_id', $userWarehouseId, PDO::PARAM_INT);
    $stmt_check->execute();
    $product = $stmt_check->fetch(PDO::FETCH_ASSOC);
    
    if (!$product) {
        header('Location: danh_sach_san_pham.php?error=product_not_found_or_access_denied');
        exit();
    }
    
    // Chuyển trạng thái sản phẩm thành 'inactive' (tạm ngừng) thay vì xóa
    $sql_deactivate = "UPDATE products SET status = 'inactive' WHERE product_id = :product_id AND warehouse_id = :warehouse_id";
    $stmt_deactivate = $pdo->prepare($sql_deactivate);
    $stmt_deactivate->bindParam(':product_id', $product_id, PDO::PARAM_INT);
    $stmt_deactivate->bindParam(':warehouse_id', $userWarehouseId, PDO::PARAM_INT);
    $stmt_deactivate->execute();
    
    header('Location: danh_sach_san_pham.php?success=product_deactivated&product_name=' . urlencode($product['name']));
    exit();
    
} catch (Exception $e) {
    header('Location: danh_sach_san_pham.php?error=deactivate_failed&message=' . urlencode($e->getMessage()));
    exit();
}
?>
