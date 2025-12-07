<?php
/**
 * API Kiểm tra trùng lặp sản phẩm cho manual entry
 * File: api_kiem_tra_trung_thu_cong.php
 */

session_start();
require_once 'config/cau_hinh_csdl.php';
require_once 'helpers/TroGiupDoTuongDong.php'; // UNIFIED: Use shared similarity functions

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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Chỉ chấp nhận POST request']);
    exit();
}

$action = $_POST['action'] ?? '';

if ($action === 'check_manual_duplicates') {
    try {
        $productName = trim($_POST['product_name'] ?? '');
        $brand = trim($_POST['brand'] ?? '');
        $type = trim($_POST['type'] ?? '');
        $color = trim($_POST['color'] ?? '');
        
        // Debug log đầu vào
        error_log("=== DUPLICATE CHECK DEBUG ===");
        error_log("Input: name=$productName, brand=$brand, type=$type, color=$color");
        error_log("Warehouse ID: $userWarehouseId");
        
        // Validation cơ bản
        if (empty($productName) || empty($brand)) {
            echo json_encode([
                'success' => false, 
                'message' => 'Tên sản phẩm và thương hiệu là bắt buộc'
            ]);
            exit();
        }
        
        // Tìm sản phẩm tương tự trong cùng warehouse
        $searchResults = findSimilarProducts($pdo, $userWarehouseId, [
            'name' => $productName,
            'brand' => $brand,
            'type' => $type,
            'color' => $color
        ]);
        
        error_log("Found ALL " . count($searchResults) . " products from warehouse (no filtering in query)");
        
        // Debug: In ra một vài sản phẩm đầu tiên
        $sampleCount = min(5, count($searchResults));
        error_log("Sample products (first {$sampleCount}):");
        for ($i = 0; $i < $sampleCount; $i++) {
            $product = $searchResults[$i];
            error_log("  #{$i}: ID={$product['product_id']}, Name={$product['name']}, Brand={$product['brand']}, Type={$product['type']}, Colors={$product['colors']}");
        }
        
        $duplicates = [];
        $maxSimilarity = 0;
        $totalCompared = 0;
        
        error_log("Starting similarity calculation for all " . count($searchResults) . " products...");
        
        foreach ($searchResults as $product) {
            $totalCompared++;
            
            $similarity = calculateManualSimilarity([
                'name' => $productName,
                'brand' => $brand,
                'type' => $type,
                'color' => $color
            ], $product);
            
            // Log tất cả similarity scores (không chỉ những cái >= 50)
            if ($totalCompared <= 10 || $similarity >= 30) {
                $statusInfo = isset($product['status']) ? $product['status'] : 'UNDEFINED';
                error_log("Product ID {$product['product_id']}: {$product['name']} (Brand: {$product['brand']}, Type: {$product['type']}, Status: {$statusInfo}) - Similarity: " . round($similarity, 1) . "%");
            }
            
            if ($similarity >= 50) { // Threshold giảm từ 60 xuống 50 để dễ phát hiện hơn
                $statusInfo = isset($product['status']) ? $product['status'] : 'UNDEFINED';
                error_log("✓ DUPLICATE FOUND: Product ID {$product['product_id']} (Status: {$statusInfo}) with {$similarity}% similarity");
                
                // Lấy thông tin sizes và prices từ variants
                $variantsSql = "SELECT size, price FROM product_variants WHERE product_id = ? ORDER BY size ASC";
                $variantsStmt = $pdo->prepare($variantsSql);
                $variantsStmt->execute([$product['product_id']]);
                $variants = $variantsStmt->fetchAll(PDO::FETCH_ASSOC);
                
                $sizes = [];
                $prices = [];
                foreach ($variants as $variant) {
                    if (!empty($variant['size'])) {
                        $sizes[] = $variant['size'];
                        $prices[] = $variant['price'];
                    }
                }
                
                $duplicates[] = [
                    'product_id' => $product['product_id'],
                    'name' => $product['name'],
                    'brand' => $product['brand'],
                    'type' => $product['type'],
                    'color' => $product['colors'],
                    'status' => $product['status'] ?? 'active',
                    'sizes' => $sizes,
                    'prices' => $prices,
                    'size_price_text' => !empty($sizes) ? implode(', ', array_map(function($size, $price) {
                        return "Size $size: " . number_format($price, 0, ',', '.') . "đ";
                    }, $sizes, $prices)) : 'Chưa có size',
                    'similarity' => round($similarity, 1),
                    'image_url' => $product['image_path'] ?? '',
                    'created_at' => $product['created_at']
                ];
                
                $maxSimilarity = max($maxSimilarity, $similarity);
            }
        }
        
        error_log("Similarity calculation completed:");
        error_log("  Total products compared: {$totalCompared}");
        error_log("  Duplicates found (>= 50%): " . count($duplicates));
        error_log("  Max similarity: " . round($maxSimilarity, 1) . "%");
        
        // Sắp xếp theo độ tương đồng giảm dần
        usort($duplicates, function($a, $b) {
            return $b['similarity'] <=> $a['similarity'];
        });
        
        // Xác định mức cảnh báo
        $warningLevel = 'safe';
        if ($maxSimilarity >= 85) {
            $warningLevel = 'high';
        } elseif ($maxSimilarity >= 70) {
            $warningLevel = 'medium';
        }
        
        echo json_encode([
            'success' => true,
            'has_duplicates' => !empty($duplicates),
            'duplicates' => array_slice($duplicates, 0, 5), // Chỉ hiển thị top 5
            'max_similarity' => round($maxSimilarity, 1),
            'warning_level' => $warningLevel,
            'total_found' => count($duplicates)
        ]);
        
    } catch (Exception $e) {
        error_log("Manual duplicate check error: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'Lỗi khi kiểm tra trùng lặp: ' . $e->getMessage()
        ]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Action không hợp lệ']);
}

/**
 * Tìm sản phẩm tương tự trong database
 * LẤY TẤT CẢ sản phẩm trong warehouse rồi tính similarity
 */
function findSimilarProducts($pdo, $warehouseId, $inputData) {
    try {
        error_log("=== FIND SIMILAR PRODUCTS ===");
        error_log("Looking for: brand={$inputData['brand']}, type={$inputData['type']}, name={$inputData['name']}");
        error_log("Warehouse ID: {$warehouseId}");
        
        // Query ĐƠN GIẢN - LẤY TẤT CẢ sản phẩm trong warehouse
        // Không lọc gì cả, để PHP tính similarity cho tất cả
        $sql = "
            SELECT DISTINCT
                p.product_id,
                p.name,
                p.brand,
                p.type,
                p.description,
                p.material,
                p.status,
                p.created_at,
                GROUP_CONCAT(DISTINCT pv.color) as colors,
                pi.file_path as image_path
            FROM products p
            LEFT JOIN product_variants pv ON p.product_id = pv.product_id
            LEFT JOIN product_images pi ON p.product_id = pi.product_id AND pi.is_primary = 1
            WHERE p.warehouse_id = ?
            GROUP BY p.product_id, p.name, p.brand, p.type, p.description, p.material, p.status, p.created_at, pi.file_path
            ORDER BY p.created_at DESC
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$warehouseId]);
        
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        error_log("Query returned ALL " . count($results) . " products from warehouse {$warehouseId}");
        
        return $results;
        
    } catch (Exception $e) {
        error_log("Error in findSimilarProducts: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        return [];
    }
}

/**
 * Tính độ tương đồng cho manual entry
 */
function calculateManualSimilarity($inputData, $dbProduct) {
    $totalScore = 0;
    $maxScore = 0;
    
    // Weights cho các trường (tổng = 100)
    $weights = [
        'brand' => 30,     // Thương hiệu quan trọng
        'name' => 25,      // Tên sản phẩm quan trọng
        'type' => 20,      // Loại sản phẩm
        'color' => 25      // Màu sắc QUAN TRỌNG - tăng từ 10 lên 25
    ];
    
    // So sánh thương hiệu
    if (!empty($inputData['brand']) && !empty($dbProduct['brand'])) {
        $brandSim = calculateTextSimilarity($inputData['brand'], $dbProduct['brand']);
        $totalScore += $brandSim * $weights['brand'] / 100;
        $maxScore += $weights['brand'];
    }
    
    // So sánh tên sản phẩm
    if (!empty($inputData['name']) && !empty($dbProduct['name'])) {
        $nameSim = calculateTextSimilarity($inputData['name'], $dbProduct['name']);
        $totalScore += $nameSim * $weights['name'] / 100;
        $maxScore += $weights['name'];
    }
    
    // So sánh loại sản phẩm
    if (!empty($inputData['type']) && !empty($dbProduct['type'])) {
        $typeSim = calculateTextSimilarity($inputData['type'], $dbProduct['type']);
        $totalScore += $typeSim * $weights['type'] / 100;
        $maxScore += $weights['type'];
    }
    
    // So sánh màu sắc - UNIFIED: Use shared function from TroGiupDoTuongDong.php
    if (!empty($inputData['color']) && !empty($dbProduct['colors'])) {
        $colorSim = calculateColorSimilarityEnhanced($inputData['color'], $dbProduct['colors']);
        $totalScore += $colorSim * $weights['color'] / 100;
        $maxScore += $weights['color'];
    }
    
    // Tính phần trăm tương đồng
    $similarity = $maxScore > 0 ? ($totalScore / $maxScore) * 100 : 0;
    
    return min(100, max(0, $similarity));
}

// Note: calculateTextSimilarity() is now imported from helpers/TroGiupDoTuongDong.php
// Note: calculateColorSimilarityEnhanced() and normalizeColorEnhanced() are in TroGiupDoTuongDong.php
// This ensures UNIFIED similarity calculation for both AI and Manual systems
?>
