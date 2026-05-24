<?php
// Suppress all PHP errors and warnings for clean JSON output
error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json');

// session_start();

require_once __DIR__ . '/../../config/database.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$database = new Database();
$pdo = $database->getConnection();

try {
    $action = $_POST['action'] ?? '';
    $warehouse_id = $_POST['warehouse_id'] ?? $_SESSION['warehouse_id'];

    if (!$warehouse_id) {
        echo json_encode(['success' => false, 'message' => 'Warehouse ID not found']);
        exit();
    }

    switch ($action) {
        case 'get_sku':
            $type = $_POST['type'] ?? '';
            $brand = $_POST['brand'] ?? '';
            $color = $_POST['color'] ?? '';
            $name = $_POST['name'] ?? '';
            $size = $_POST['size'] ?? '';

            if (!$type || !$brand || !$color || !$name || !$size) {
                echo json_encode(['success' => false, 'message' => 'Missing required product information']);
                exit();
            }

            // Tìm SKU của variant đã tồn tại
            $sql = "SELECT pv.sku, pv.variant_id, pv.price as sale_price, pv.product_id, i.location_id, l.shelf_code as location_name
                    FROM product_variants pv 
                    JOIN products p ON pv.product_id = p.product_id
                    LEFT JOIN inventory i ON pv.variant_id = i.variant_id AND i.warehouse_id = ?
                    LEFT JOIN locations l ON i.location_id = l.location_id
                    WHERE p.warehouse_id = ? 
                    AND p.type = ? AND p.brand = ? AND p.name = ? 
                    AND pv.color = ? AND pv.size = ?
                    LIMIT 1";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([$warehouse_id, $warehouse_id, $type, $brand, $name, $color, $size]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                // Lấy giá nhập gần nhất từ stock_receipt_items
                $sqlPrice = "SELECT sri.unit_price 
                            FROM stock_receipt_items sri
                            WHERE sri.variant_id = ? AND sri.warehouse_id = ?
                            ORDER BY sri.created_at DESC
                            LIMIT 1";
                $stmtPrice = $pdo->prepare($sqlPrice);
                $stmtPrice->execute([$existing['variant_id'], $warehouse_id]);
                $lastReceipt = $stmtPrice->fetch(PDO::FETCH_ASSOC);
                
                echo json_encode([
                    'success' => true,
                    'sku' => $existing['sku'],
                    'variant_id' => $existing['variant_id'],
                    'product_id' => $existing['product_id'],
                    'existing_unit_price' => $lastReceipt ? $lastReceipt['unit_price'] : null,
                    'existing_sale_price' => $existing['sale_price'],
                    'existing_location' => $existing['location_name'],
                    'is_existing' => true
                ]);
            } else {
                // Tạo SKU mới
                $typeCode = strtoupper(substr($type, 0, 3));
                $brandCode = strtoupper(substr($brand, 0, 3));
                $colorCode = strtoupper(substr($color, 0, 1));
                $timestamp = substr(time(), -4);
                
                $newSku = "{$typeCode}-{$brandCode}-{$colorCode}{$size}-{$timestamp}";
                
                echo json_encode([
                    'success' => true,
                    'sku' => $newSku,
                    'variant_id' => null,
                    'product_id' => null,
                    'existing_unit_price' => null,
                    'existing_sale_price' => null,
                    'existing_location' => null,
                    'is_existing' => false
                ]);
            }
            break;

        case 'get_storage_locations':
            $type = $_POST['type'] ?? '';
            $brand = $_POST['brand'] ?? '';

            // Lấy các vị trí lưu trữ có sẵn cho loại sản phẩm và thương hiệu tương tự
            $sql = "SELECT DISTINCT l.shelf_code as location_name
                    FROM inventory i
                    JOIN product_variants pv ON i.variant_id = pv.variant_id
                    JOIN products p ON pv.product_id = p.product_id
                    LEFT JOIN locations l ON i.location_id = l.location_id
                    WHERE i.warehouse_id = ? 
                    AND l.shelf_code IS NOT NULL 
                    AND l.shelf_code != ''";
            
            $params = [$warehouse_id];
            
            if ($type) {
                $sql .= " AND p.type = ?";
                $params[] = $type;
            }
            
            if ($brand) {
                $sql .= " AND p.brand = ?";
                $params[] = $brand;
            }
            
            $sql .= " ORDER BY l.shelf_code";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $locations = $stmt->fetchAll(PDO::FETCH_COLUMN);

            echo json_encode([
                'success' => true,
                'locations' => $locations
            ]);
            break;

        case 'suggest_location':
            $type = $_POST['type'] ?? '';
            $brand = $_POST['brand'] ?? '';
            $size = $_POST['size'] ?? '';

            // Tìm vị trí gần kề dựa trên sản phẩm tương tự
            $sql = "SELECT l.shelf_code as location_name, COUNT(*) as count
                    FROM inventory i
                    JOIN product_variants pv ON i.variant_id = pv.variant_id
                    JOIN products p ON pv.product_id = p.product_id
                    LEFT JOIN locations l ON i.location_id = l.location_id
                    WHERE i.warehouse_id = ? 
                    AND l.shelf_code IS NOT NULL 
                    AND l.shelf_code != ''";
            
            $params = [$warehouse_id];
            
            if ($type) {
                $sql .= " AND p.type = ?";
                $params[] = $type;
            }
            
            if ($brand) {
                $sql .= " AND p.brand = ?";
                $params[] = $brand;
            }
            
            $sql .= " GROUP BY l.shelf_code ORDER BY count DESC LIMIT 5";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $existingLocations = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $suggestions = [];
            
            if (!empty($existingLocations)) {
                // Tạo vị trí gần kề dựa trên vị trí phổ biến nhất
                $baseLocation = $existingLocations[0]['location_name'];
                
                // Phân tích pattern của vị trí (VD: A1-B2, C-03, etc.)
                if (preg_match('/^([A-Z]+)(\d+)-?([A-Z]?)(\d*)$/', $baseLocation, $matches)) {
                    $section = $matches[1];
                    $row = intval($matches[2]);
                    $subsection = $matches[3];
                    $col = $matches[4] ? intval($matches[4]) : 0;
                    
                    // Tạo các vị trí gần kề
                    for ($i = 1; $i <= 3; $i++) {
                        if ($subsection && $col > 0) {
                            $suggestions[] = $section . ($row + $i) . '-' . $subsection . sprintf('%02d', $col + $i);
                            $suggestions[] = $section . $row . '-' . $subsection . sprintf('%02d', $col + $i);
                        } else {
                            $suggestions[] = $section . ($row + $i);
                            $suggestions[] = $section . $row . '-' . chr(ord('A') + $i) . sprintf('%02d', $i);
                        }
                    }
                }
            } else {
                // Tạo vị trí mới dựa trên loại sản phẩm và thương hiệu
                $typeCode = $type ? strtoupper(substr($type, 0, 1)) : 'P';
                $brandCode = $brand ? strtoupper(substr($brand, 0, 2)) : 'XX';
                
                for ($i = 1; $i <= 5; $i++) {
                    $suggestions[] = $typeCode . sprintf('%02d', $i) . '-' . $brandCode;
                    $suggestions[] = $typeCode . '-' . $brandCode . sprintf('%02d', $i);
                }
            }
            
            // Loại bỏ các vị trí đã tồn tại
            $existingLocationsList = array_column($existingLocations, 'location_name');
            $suggestions = array_diff($suggestions, $existingLocationsList);
            $suggestions = array_unique($suggestions);
            $suggestions = array_slice($suggestions, 0, 5);

            echo json_encode([
                'success' => true,
                'suggestions' => array_values($suggestions),
                'existing_locations' => $existingLocationsList
            ]);
            break;

        case 'get_all_sizes':
            $type = $_POST['type'] ?? '';
            $brand = $_POST['brand'] ?? '';
            $color = $_POST['color'] ?? '';
            $name = $_POST['name'] ?? '';

            if (!$type || !$brand || !$color || !$name) {
                echo json_encode(['success' => false, 'message' => 'Missing product information']);
                exit();
            }

            // Lấy tất cả kích thước có sẵn cho sản phẩm này
            $sql = "SELECT DISTINCT pv.size, pv.sku, pv.variant_id, pv.price as sale_price, l.shelf_code as location_name
                    FROM product_variants pv 
                    JOIN products p ON pv.product_id = p.product_id
                    LEFT JOIN inventory i ON pv.variant_id = i.variant_id AND i.warehouse_id = ?
                    LEFT JOIN locations l ON i.location_id = l.location_id
                    WHERE p.warehouse_id = ? 
                    AND p.type = ? AND p.brand = ? AND p.name = ? AND pv.color = ?
                    ORDER BY CAST(pv.size AS UNSIGNED), pv.size";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([$warehouse_id, $warehouse_id, $type, $brand, $name, $color]);
            $sizes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Lấy giá nhập gần nhất cho mỗi size
            foreach ($sizes as &$size) {
                if ($size['variant_id']) {
                    $sqlPrice = "SELECT sri.unit_price 
                                FROM stock_receipt_items sri
                                WHERE sri.variant_id = ? AND sri.warehouse_id = ?
                                ORDER BY sri.created_at DESC
                                LIMIT 1";
                    $stmtPrice = $pdo->prepare($sqlPrice);
                    $stmtPrice->execute([$size['variant_id'], $warehouse_id]);
                    $lastReceipt = $stmtPrice->fetch(PDO::FETCH_ASSOC);
                    $size['unit_price'] = $lastReceipt ? $lastReceipt['unit_price'] : null;
                } else {
                    $size['unit_price'] = null;
                }
            }

            echo json_encode([
                'success' => true,
                'sizes' => $sizes
            ]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            break;
    }

} catch (Exception $e) {
    error_log("Error in product data API: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>