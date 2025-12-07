<?php
header('Content-Type: application/json');
require_once '../config/cau_hinh_csdl.php';
session_start();

try {
    $database = new Database();
    $pdo = $database->getConnection();
    
    $response = ['success' => false, 'message' => ''];
    
    // Kiểm tra đăng nhập
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Chưa đăng nhập');
    }
    
    $productName = $_POST['product_name'] ?? '';
    $color = $_POST['color'] ?? '';
    $warehouseId = $_POST['warehouse_id'] ?? $_SESSION['warehouse_id'];
    
    if (empty($productName)) {
        throw new Exception('Thiếu tên sản phẩm');
    }
    
    // Tìm vị trí gợi ý dựa trên:
    // 1. Sản phẩm tương tự đã có
    // 2. Loại sản phẩm
    // 3. Màu sắc
    // 4. Vị trí còn trống
    
    $suggestions = [];
    
    // 1. Tìm vị trí của sản phẩm tương tự
    $stmt = $pdo->prepare("
        SELECT DISTINCT l.location_id, l.shelf_code, l.description,
               COUNT(i.inventory_id) as product_count
        FROM locations l
        LEFT JOIN inventory i ON l.location_id = i.location_id
        LEFT JOIN product_variants pv ON i.variant_id = pv.variant_id
        LEFT JOIN products p ON pv.product_id = p.product_id
        WHERE l.warehouse_id = ?
        AND (
            LOWER(p.name) LIKE LOWER(?) 
            OR LOWER(pv.color) LIKE LOWER(?)
            OR SOUNDEX(p.name) = SOUNDEX(?)
        )
        GROUP BY l.location_id, l.shelf_code, l.description
        ORDER BY product_count DESC
        LIMIT 3
    ");
    
    $productNameLike = '%' . $productName . '%';
    $colorLike = '%' . $color . '%';
    
    $stmt->execute([$warehouseId, $productNameLike, $colorLike, $productName]);
    $similarProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($similarProducts as $location) {
        $suggestions[] = [
            'location_id' => $location['location_id'],
            'shelf_code' => $location['shelf_code'],
            'description' => $location['description'],
            'reason' => 'Vị trí có sản phẩm tương tự',
            'priority' => 1
        ];
    }
    
    // 2. Tìm vị trí theo loại sản phẩm (dựa vào keywords)
    $productKeywords = extractKeywords($productName);
    if (!empty($productKeywords)) {
        $placeholders = str_repeat('?,', count($productKeywords) - 1) . '?';
        $stmt = $pdo->prepare("
            SELECT DISTINCT l.location_id, l.shelf_code, l.description
            FROM locations l
            LEFT JOIN inventory i ON l.location_id = i.location_id
            LEFT JOIN product_variants pv ON i.variant_id = pv.variant_id
            LEFT JOIN products p ON pv.product_id = p.product_id
            WHERE l.warehouse_id = ?
            AND l.location_id NOT IN (
                SELECT location_id FROM inventory WHERE location_id IS NOT NULL
                GROUP BY location_id HAVING SUM(quantity) > 100
            )
            AND (
                " . implode(' OR ', array_fill(0, count($productKeywords), 'LOWER(p.name) LIKE LOWER(?)')) . "
            )
            LIMIT 2
        ");
        
        $params = [$warehouseId];
        foreach ($productKeywords as $keyword) {
            $params[] = '%' . $keyword . '%';
        }
        
        $stmt->execute($params);
        $categoryLocations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($categoryLocations as $location) {
            // Kiểm tra xem đã có trong suggestions chưa
            $exists = false;
            foreach ($suggestions as $existing) {
                if ($existing['location_id'] == $location['location_id']) {
                    $exists = true;
                    break;
                }
            }
            
            if (!$exists) {
                $suggestions[] = [
                    'location_id' => $location['location_id'],
                    'shelf_code' => $location['shelf_code'],
                    'description' => $location['description'],
                    'reason' => 'Vị trí phù hợp với loại sản phẩm',
                    'priority' => 2
                ];
            }
        }
    }
    
    // 3. Tìm vị trí còn trống hoặc ít sản phẩm
    $stmt = $pdo->prepare("
        SELECT l.location_id, l.shelf_code, l.description,
               COALESCE(SUM(i.quantity), 0) as total_quantity
        FROM locations l
        LEFT JOIN inventory i ON l.location_id = i.location_id
        WHERE l.warehouse_id = ?
        GROUP BY l.location_id, l.shelf_code, l.description
        HAVING total_quantity < 50
        ORDER BY total_quantity ASC
        LIMIT 3
    ");
    
    $stmt->execute([$warehouseId]);
    $emptyLocations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($emptyLocations as $location) {
        // Kiểm tra xem đã có trong suggestions chưa
        $exists = false;
        foreach ($suggestions as $existing) {
            if ($existing['location_id'] == $location['location_id']) {
                $exists = true;
                break;
            }
        }
        
        if (!$exists) {
            $suggestions[] = [
                'location_id' => $location['location_id'],
                'shelf_code' => $location['shelf_code'],
                'description' => $location['description'],
                'reason' => 'Vị trí còn trống (Số lượng: ' . $location['total_quantity'] . ')',
                'priority' => 3
            ];
        }
    }
    
    // 4. Nếu không có vị trí nào, gợi ý tạo vị trí mới
    if (empty($suggestions)) {
        $nextShelfCode = generateNextShelfCode($pdo, $warehouseId);
        $suggestions[] = [
            'location_id' => null,
            'shelf_code' => $nextShelfCode,
            'description' => 'Vị trí mới được đề xuất',
            'reason' => 'Tạo vị trí mới',
            'priority' => 4
        ];
    }
    
    // Sắp xếp theo priority và giới hạn kết quả
    usort($suggestions, function($a, $b) {
        return $a['priority'] - $b['priority'];
    });
    
    $suggestions = array_slice($suggestions, 0, 5);
    
    $response['success'] = true;
    $response['suggestions'] = $suggestions;
    
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
}

echo json_encode($response);

function extractKeywords($productName) {
    $keywords = [];
    $productName = strtolower($productName);
    
    // Danh sách từ khóa phổ biến cho giày dép
    $shoeKeywords = [
        'giày' => ['giày', 'shoe', 'shoes'],
        'sneaker' => ['sneaker', 'sneakers', 'thể thao'],
        'boot' => ['boot', 'boots', 'bốt'],
        'sandal' => ['sandal', 'sandals', 'dép'],
        'cao gót' => ['cao gót', 'high heel', 'heel'],
        'bệt' => ['bệt', 'flat', 'flats'],
        'tây' => ['tây', 'dress', 'formal'],
        'nike' => ['nike'],
        'adidas' => ['adidas'],
        'converse' => ['converse'],
        'vans' => ['vans']
    ];
    
    foreach ($shoeKeywords as $category => $terms) {
        foreach ($terms as $term) {
            if (strpos($productName, $term) !== false) {
                $keywords[] = $category;
                break;
            }
        }
    }
    
    return array_unique($keywords);
}

function generateNextShelfCode($pdo, $warehouseId) {
    // Lấy shelf code cao nhất
    $stmt = $pdo->prepare("
        SELECT shelf_code 
        FROM locations 
        WHERE warehouse_id = ? 
        ORDER BY shelf_code DESC 
        LIMIT 1
    ");
    $stmt->execute([$warehouseId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$result) {
        return 'A-001';
    }
    
    $lastCode = $result['shelf_code'];
    
    // Phân tích mã code (A-001, B-023, etc.)
    if (preg_match('/^([A-Z])-(\d+)$/', $lastCode, $matches)) {
        $letter = $matches[1];
        $number = intval($matches[2]);
        
        $number++;
        
        // Nếu số > 999, chuyển sang chữ cái tiếp theo
        if ($number > 999) {
            $letter = chr(ord($letter) + 1);
            $number = 1;
        }
        
        return $letter . '-' . str_pad($number, 3, '0', STR_PAD_LEFT);
    }
    
    // Nếu không parse được, tạo mã mới
    return 'A-' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
}
?>
