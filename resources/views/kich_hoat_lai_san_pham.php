<?php
// session_start();
require_once __DIR__ . '/../../config/database.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Chưa đăng nhập']);
    exit();
}

// Kiểm tra product_id
if (!isset($_POST['product_id']) || !is_numeric($_POST['product_id'])) {
    echo json_encode(['success' => false, 'message' => 'ID sản phẩm không hợp lệ']);
    exit();
}

$product_id = (int)$_POST['product_id'];
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
        echo json_encode(['success' => false, 'message' => 'Không tìm thấy sản phẩm hoặc bạn không có quyền truy cập']);
        exit();
    }
    
    // Kiểm tra trạng thái hiện tại
    if ($product['status'] === 'active') {
        echo json_encode(['success' => false, 'message' => 'Sản phẩm đã ở trạng thái hoạt động']);
        exit();
    }
    
    // Kích hoạt lại sản phẩm bằng cách chuyển status thành 'active'
    $sql_reactivate = "UPDATE products SET status = 'active' WHERE product_id = :product_id AND warehouse_id = :warehouse_id";
    $stmt_reactivate = $pdo->prepare($sql_reactivate);
    $stmt_reactivate->bindParam(':product_id', $product_id, PDO::PARAM_INT);
    $stmt_reactivate->bindParam(':warehouse_id', $userWarehouseId, PDO::PARAM_INT);
    $stmt_reactivate->execute();
    
    echo json_encode([
        'success' => true, 
        'message' => 'Sản phẩm "' . $product['name'] . '" đã được kích hoạt lại thành công'
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Lỗi: ' . $e->getMessage()]);
}
?>
