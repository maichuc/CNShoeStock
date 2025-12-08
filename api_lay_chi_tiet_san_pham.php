<?php
/**
 * API để lấy chi tiết sản phẩm từ database
 * File: api_lay_chi_tiet_san_pham.php
 */

// Bắt đầu output buffering to prevent any output before JSON
ob_start();

// Tắt PHP notices để không làm hỏng JSON output
error_reporting(E_ERROR | E_WARNING | E_PARSE);

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once 'config/database.php';

// Xóa any output that might have been generated
ob_end_clean();

header('Content-Type: application/json');

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Chưa đăng nhập']);
    exit();
}

$database = new Database();
$pdo = $database->getConnection();

$userWarehouseId = $_SESSION['warehouse_id'] ?? null;

if (empty($userWarehouseId)) {
    echo json_encode(['success' => false, 'message' => 'Không xác định được warehouse']);
    exit();
}

// Chấp nhận cả GET và POST request
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Lấy product_id từ GET hoặc POST
$productId = '';
if ($requestMethod === 'GET') {
    $productId = $_GET['product_id'] ?? '';
} elseif ($requestMethod === 'POST') {
    $productId = $_POST['product_id'] ?? '';
} else {
    echo json_encode(['success' => false, 'message' => 'Chỉ chấp nhận GET hoặc POST request']);
    exit();
}

if (empty($productId) || !is_numeric($productId)) {
    echo json_encode(['success' => false, 'message' => 'ID sản phẩm không hợp lệ']);
    exit();
}

try {
    // Lấy thông tin chi tiết sản phẩm (theo cấu trúc bảng thực tế)
    $sql = "
        SELECT 
            p.product_id,
            p.warehouse_id,
            p.name,
            p.description,
            p.created_at,
            p.ai_analyzed,
            p.brand,
            p.type,
            p.material,
            p.features,
            p.tags,
            p.status,
            -- Lấy màu sắc từ variants
            GROUP_CONCAT(DISTINCT pv.color ORDER BY pv.variant_id ASC) as colors,
            -- Lấy sizes từ variants
            GROUP_CONCAT(DISTINCT pv.size ORDER BY pv.variant_id ASC) as sizes,
            -- Lấy giá từ variants
            GROUP_CONCAT(DISTINCT pv.price ORDER BY pv.variant_id ASC) as prices,
            -- Lấy ảnh chính
            pi_main.file_path as main_image,
            -- Đếm số ảnh
            COUNT(DISTINCT pi_all.image_id) as total_images
        FROM products p
        LEFT JOIN product_variants pv ON p.product_id = pv.product_id
        LEFT JOIN product_images pi_main ON p.product_id = pi_main.product_id AND pi_main.is_primary = 1
        LEFT JOIN product_images pi_all ON p.product_id = pi_all.product_id
        WHERE p.product_id = ? 
        AND p.warehouse_id = ?
        GROUP BY p.product_id, p.warehouse_id, p.name, p.description, 
                 p.created_at, p.ai_analyzed, p.brand, p.type, p.material, p.features, p.tags, 
                 p.status, pi_main.file_path
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$productId, $userWarehouseId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$product) {
        echo json_encode([
            'success' => false, 
            'message' => 'Không tìm thấy sản phẩm hoặc sản phẩm không thuộc warehouse của bạn'
        ]);
        exit();
    }
    
    // Lấy tất cả ảnh của sản phẩm
    $imagesSql = "
        SELECT 
            image_id,
            file_path,
            is_primary,
            created_at
        FROM product_images 
        WHERE product_id = ?
        ORDER BY is_primary DESC, created_at ASC
    ";
    $imagesStmt = $pdo->prepare($imagesSql);
    $imagesStmt->execute([$productId]);
    $images = $imagesStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Lấy chi tiết variants (sizes + prices + unit_price từ stock_receipt_items) - LỌC THEO WAREHOUSE
    $variantsSql = "
        SELECT 
            pv.variant_id,
            pv.sku,
            pv.size,
            pv.color,
            pv.price as sale_price,
            pv.created_at,
            pv.updated_at,
            pv.warehouse_id,
            (SELECT sri.unit_price 
             FROM stock_receipt_items sri 
             WHERE sri.variant_id = pv.variant_id 
             AND sri.warehouse_id = pv.warehouse_id 
             ORDER BY sri.created_at DESC 
             LIMIT 1) as unit_price
        FROM product_variants pv
        WHERE pv.product_id = ?
        AND pv.warehouse_id = ?
        ORDER BY pv.size ASC, pv.color ASC
    ";
    $variantsStmt = $pdo->prepare($variantsSql);
    $variantsStmt->execute([$productId, $userWarehouseId]);
    $variants = $variantsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Thêm key 'price' cho tương thích với JavaScript (key sale_price vẫn giữ)
    foreach ($variants as &$variant) {
        $variant['price'] = $variant['sale_price']; // Thêm alias
    }
    unset($variant); // Phá vỡ reference
    
    // Gỡ lỗi log
    error_log("API GET PRODUCT DETAILS - Product ID: $productId, Warehouse: $userWarehouseId");
    error_log("API GET PRODUCT DETAILS - Found " . count($variants) . " variants");
    if (!empty($variants)) {
        error_log("API GET PRODUCT DETAILS - First variant price: " . $variants[0]['sale_price']);
    }
    
    // Tính base_sku từ variants (loại bỏ phần size cuối)
    $base_sku = '';
    if (!empty($variants)) {
        $firstSku = $variants[0]['sku'];
        if (preg_match('/^(.+)-\d+$/', $firstSku, $matches)) {
            $base_sku = $matches[1]; // Lấy phần trước dấu "-số"
        } else {
            // Nếu không match pattern, cố gắng tách phần cuối
            $parts = explode('-', $firstSku);
            if (count($parts) > 1) {
                array_pop($parts); // Bỏ phần cuối (size)
                $base_sku = implode('-', $parts);
            } else {
                $base_sku = $firstSku; // Nếu không tách được thì dùng nguyên
            }
        }
    }

    // Format dữ liệu trả về
    $response = [
        'success' => true,
        'product' => [
            'product_id' => $product['product_id'],
            'warehouse_id' => $product['warehouse_id'],
            'name' => $product['name'],
            'brand' => $product['brand'],
            'type' => $product['type'],
            'description' => $product['description'],
            'material' => $product['material'],
            'features' => $product['features'],
            'tags' => $product['tags'],
            'status' => $product['status'],
            'created_at' => $product['created_at'],
            // Base SKU calculated from variants
            'base_sku' => $base_sku,
            // AI related field
            'ai_analyzed' => $product['ai_analyzed'],
            // Xử lý colors array
            'colors' => $product['colors'] ? explode(',', $product['colors']) : [],
            'colors_string' => $product['colors'],
            // Xử lý sizes array
            'sizes' => $product['sizes'] ? explode(',', $product['sizes']) : [],
            'sizes_string' => $product['sizes'],
            // Xử lý prices array
            'prices' => $product['prices'] ? explode(',', $product['prices']) : [],
            'prices_string' => $product['prices'],
            // Images
            'main_image' => $product['main_image'],
            'total_images' => (int)$product['total_images'],
            'images' => $images,
            // Variants detail
            'variants' => $variants,
            'total_variants' => count($variants)
        ]
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    error_log("Error in get_product_details: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Lỗi khi lấy thông tin sản phẩm: ' . $e->getMessage()
    ]);
}
?>
