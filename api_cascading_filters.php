<?php
session_start();
header('Content-Type: application/json');

require_once 'config/database.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$database = new Database();
$pdo = $database->getConnection();

try {
    $field = $_POST['field'] ?? '';
    $warehouse_id = $_POST['warehouse_id'] ?? $_SESSION['warehouse_id'];
    $filters = $_POST['filters'] ?? [];

    if (!$warehouse_id) {
        echo json_encode(['success' => false, 'message' => 'Warehouse ID not found']);
        exit();
    }

    $results = [];

    switch ($field) {
        case 'type':
            // Lấy tất cả loại sản phẩm trong warehouse (chỉ active)
            $sql = "SELECT DISTINCT type FROM products WHERE warehouse_id = ? AND type IS NOT NULL AND status = 'active' ORDER BY type";
            $params = [$warehouse_id];
            break;

        case 'brand':
            // Lọc thương hiệu theo loại sản phẩm đã chọn (chỉ active)
            $sql = "SELECT DISTINCT brand FROM products WHERE warehouse_id = ? AND brand IS NOT NULL AND status = 'active'";
            $params = [$warehouse_id];
            
            if (!empty($filters['type'])) {
                $sql .= " AND type = ?";
                $params[] = $filters['type'];
            }
            
            $sql .= " ORDER BY brand";
            break;

        case 'color':
            // Lọc màu sắc theo loại sản phẩm và thương hiệu đã chọn (chỉ active)
            $sql = "SELECT DISTINCT pv.color 
                    FROM product_variants pv 
                    JOIN products p ON pv.product_id = p.product_id 
                    WHERE p.warehouse_id = ? AND pv.color IS NOT NULL AND p.status = 'active'";
            $params = [$warehouse_id];
            
            if (!empty($filters['type'])) {
                $sql .= " AND p.type = ?";
                $params[] = $filters['type'];
            }
            
            if (!empty($filters['brand'])) {
                $sql .= " AND p.brand = ?";
                $params[] = $filters['brand'];
            }
            
            $sql .= " ORDER BY pv.color";
            break;

        case 'name':
            // Lọc tên sản phẩm theo tất cả filter trước đó (chỉ active)
            $sql = "SELECT DISTINCT p.name 
                    FROM products p 
                    LEFT JOIN product_variants pv ON p.product_id = pv.product_id 
                    WHERE p.warehouse_id = ? AND p.name IS NOT NULL AND p.status = 'active'";
            $params = [$warehouse_id];
            
            if (!empty($filters['type'])) {
                $sql .= " AND p.type = ?";
                $params[] = $filters['type'];
            }
            
            if (!empty($filters['brand'])) {
                $sql .= " AND p.brand = ?";
                $params[] = $filters['brand'];
            }
            
            if (!empty($filters['color'])) {
                $sql .= " AND pv.color = ?";
                $params[] = $filters['color'];
            }
            
            $sql .= " ORDER BY p.name";
            break;

        case 'size':
            // Lọc kích thước theo tất cả filter trước đó (chỉ active)
            $sql = "SELECT DISTINCT pv.size 
                    FROM product_variants pv 
                    JOIN products p ON pv.product_id = p.product_id 
                    WHERE p.warehouse_id = ? AND pv.size IS NOT NULL AND p.status = 'active'";
            $params = [$warehouse_id];
            
            if (!empty($filters['type'])) {
                $sql .= " AND p.type = ?";
                $params[] = $filters['type'];
            }
            
            if (!empty($filters['brand'])) {
                $sql .= " AND p.brand = ?";
                $params[] = $filters['brand'];
            }
            
            if (!empty($filters['color'])) {
                $sql .= " AND pv.color = ?";
                $params[] = $filters['color'];
            }
            
            if (!empty($filters['name'])) {
                $sql .= " AND p.name = ?";
                $params[] = $filters['name'];
            }
            
            $sql .= " ORDER BY CAST(pv.size AS UNSIGNED), pv.size";
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Invalid field']);
            exit();
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode([
        'success' => true,
        'data' => $results,
        'field' => $field,
        'filters_applied' => $filters
    ]);

} catch (Exception $e) {
    error_log("Error getting cascading filter data: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>