<?php
header('Content-Type: application/json');
require_once 'config/cau_hinh_csdl.php';
require_once 'helpers/GhiNhatKyKiemToan.php';
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
    $userWarehouseId = $_SESSION['warehouse_id'] ?? null;

    // Lấy warehouse_id từ database nếu chưa có trong session
    if (!$userWarehouseId) {
        $stmt = $pdo->prepare("SELECT warehouse_id FROM users WHERE user_id = ?");
        $stmt->execute([$userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result) {
            $userWarehouseId = $result['warehouse_id'];
            $_SESSION['warehouse_id'] = $userWarehouseId;
        }
    }
    
    // Lấy dữ liệu từ POST
    $productName = trim($_POST['product_name'] ?? '');
    $categoryId = $_POST['category_id'] ?? null;
    $brand = trim($_POST['brand'] ?? '');
    $type = trim($_POST['type'] ?? '');
    $material = trim($_POST['material'] ?? '');
    $features = trim($_POST['features'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $tags = trim($_POST['tags'] ?? '');
    
    // Thông tin variant
    $color = trim($_POST['color'] ?? '');
    $size = trim($_POST['size'] ?? '');
    
    // Validate dữ liệu bắt buộc
    if (empty($productName)) {
        throw new Exception('Tên sản phẩm không được để trống');
    }
    
    if (!$categoryId) {
        throw new Exception('Vui lòng chọn danh mục sản phẩm');
    }
    
    if (empty($color)) {
        throw new Exception('Màu sắc không được để trống');
    }
    
    // Kiểm tra category tồn tại
    $stmt = $pdo->prepare("SELECT category_id FROM categories WHERE category_id = ?");
    $stmt->execute([$categoryId]);
    if (!$stmt->fetch()) {
        throw new Exception('Danh mục không tồn tại');
    }
    
    $pdo->beginTransaction();
    
    try {
        // Tạo sản phẩm mới với warehouse_id
        $stmt = $pdo->prepare("
            INSERT INTO products (name, category_id, description, brand, type, material, features, tags, warehouse_id) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $productName,
            $categoryId,
            $description ?: 'Sản phẩm được tạo thủ công',
            $brand ?: null,
            $type ?: null,
            $material ?: null,
            $features ?: null,
            $tags ?: null,
            $userWarehouseId
        ]);
        
        $productId = $pdo->lastInsertId();
        
        // Tạo SKU tự động
        $sku = generateSKU($productName, $color, $size);
        
        // Kiểm tra SKU trùng lặp
        $attempts = 0;
        while ($attempts < 5) {
            $stmt = $pdo->prepare("SELECT variant_id FROM product_variants WHERE sku = ?");
            $stmt->execute([$sku]);
            if (!$stmt->fetch()) {
                break; // SKU không trùng
            }
            $sku = generateSKU($productName, $color, $size, ++$attempts);
        }
        
        // Tạo product variant với warehouse_id
        $stmt = $pdo->prepare("
            INSERT INTO product_variants (product_id, sku, color, size, price, warehouse_id) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $productId,
            $sku,
            $color,
            $size ?: null,
            0, // Giá sẽ được cập nhật khi nhập kho
            $userWarehouseId
        ]);
        
        $variantId = $pdo->lastInsertId();
        
        // Log audit
        $auditLogger = new AuditLogger($pdo);
        $auditLogger->log(
            $userId,
            'create_manual_product',
            'products',
            $productId,
            null,
            [
                'product_id' => $productId,
                'variant_id' => $variantId,
                'product_name' => $productName,
                'sku' => $sku,
                'color' => $color,
                'size' => $size,
                'brand' => $brand,
                'type' => $type
            ]
        );
        
        $pdo->commit();
        
        $response['success'] = true;
        $response['product_id'] = $productId;
        $response['variant_id'] = $variantId;
        $response['sku'] = $sku;
        $response['message'] = 'Tạo sản phẩm mới thành công';
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
}

echo json_encode($response);

function generateSKU($productName, $color, $size = null, $attempt = 0) {
    // Tạo SKU từ tên sản phẩm và màu sắc
    $productCode = substr(preg_replace('/[^A-Za-z0-9]/', '', $productName), 0, 4);
    $colorCode = substr(preg_replace('/[^A-Za-z0-9]/', '', $color), 0, 2);
    
    if (empty($productCode)) {
        $productCode = 'PROD';
    }
    
    if (empty($colorCode)) {
        $colorCode = 'XX';
    }
    
    $productCode = strtoupper($productCode);
    $colorCode = strtoupper($colorCode);
    
    // Thêm size nếu có
    $sizeCode = '';
    if ($size) {
        $sizeCode = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $size), 0, 2));
    }
    
    // Thêm timestamp để tránh trùng lặp
    $timeCode = substr(time(), -4);
    
    // Thêm attempt nếu có
    $attemptCode = $attempt > 0 ? $attempt : '';
    
    return 'SKU' . $productCode . $colorCode . $sizeCode . $timeCode . $attemptCode;
}
?>
