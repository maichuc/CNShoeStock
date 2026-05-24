<?php
// Suppress all PHP errors and warnings for clean JSON output
error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json');

/**
 * API KIỂM TRA TRÙNG LẶP SẢN PHẨM (NHẬP THỦ CÔNG)
 * Kiểm tra sản phẩm nhập tay có trùng với sản phẩm trong database hay không
 * Sử dụng thuật toán tính độ tương đồng thống nhất từ TroGiupDoTuongDong.php
 * 
 * File: api_kiem_tra_trung_thu_cong.php
 */

session_start(); // ✅ FIX: Enable session to get warehouse_id from $_SESSION
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../Helpers/TroGiupDoTuongDong.php'; // Import các hàm tính độ tương đồng chung

// Kiểm tra đăng nhập - bắt buộc phải login
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Chưa đăng nhập']);
    exit();
}

// Kết nối database
$database = new Database();
$pdo = $database->getConnection();

// Lấy warehouse_id của user từ session
$userWarehouseId = $_SESSION['warehouse_id'] ?? null;

// Validate warehouse_id phải có
if (empty($userWarehouseId)) {
    echo json_encode(['success' => false, 'message' => 'Không xác định được warehouse']);
    exit();
}

// Chỉ chấp nhận POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Chỉ chấp nhận POST request']);
    exit();
}

// Lấy action từ POST data
$action = $_POST['action'] ?? '';

// ACTION: Kiểm tra trùng lặp sản phẩm nhập thủ công
if ($action === 'check_manual_duplicates') {
    try {
        // Lấy dữ liệu sản phẩm từ POST và trim khoảng trắng
        $productName = trim($_POST['product_name'] ?? '');
        $brand = trim($_POST['brand'] ?? '');
        $type = trim($_POST['type'] ?? '');
        $color = trim($_POST['color'] ?? '');
        
        // Ghi log debug để theo dõi
        error_log("=== DUPLICATE CHECK DEBUG ===");
        error_log("Input: name=$productName, brand=$brand, type=$type, color=$color");
        error_log("Warehouse ID: $userWarehouseId");
        
        // Validation: Tên sản phẩm và thương hiệu là bắt buộc
        if (empty($productName) || empty($brand)) {
            echo json_encode([
                'success' => false, 
                'message' => 'Tên sản phẩm và thương hiệu là bắt buộc'
            ]);
            exit();
        }
        
        // Tìm TẤT CẢ sản phẩm trong warehouse để so sánh
        // Không filter trong query, để PHP tính similarity cho từng sản phẩm
        $searchResults = findSimilarProducts($pdo, $userWarehouseId, [
            'name' => $productName,
            'brand' => $brand,
            'type' => $type,
            'color' => $color
        ]);
        
        error_log("Found ALL " . count($searchResults) . " products from warehouse (no filtering in query)");
        
        // Debug: In log một số sản phẩm mẫu để kiểm tra
        $sampleCount = min(5, count($searchResults));
        error_log("Sample products (first {$sampleCount}):");
        for ($i = 0; $i < $sampleCount; $i++) {
            $product = $searchResults[$i];
            error_log("  #{$i}: ID={$product['product_id']}, Name={$product['name']}, Brand={$product['brand']}, Type={$product['type']}, Colors={$product['colors']}");
        }
        
        // Khởi tạo biến để lưu kết quả
        $duplicates = [];           // Danh sách sản phẩm trùng lặp
        $maxSimilarity = 0;         // Độ tương đồng cao nhất
        $totalCompared = 0;         // Tổng số sản phẩm đã so sánh
        
        error_log("Starting similarity calculation for all " . count($searchResults) . " products...");
        
        // Duyệt qua tất cả sản phẩm và tính độ tương đồng
        foreach ($searchResults as $product) {
            $totalCompared++;
            
            // Tính độ tương đồng giữa input và sản phẩm trong DB
            $similarity = calculateManualSimilarity([
                'name' => $productName,
                'brand' => $brand,
                'type' => $type,
                'color' => $color
            ], $product);
            
            // Log similarity scores để debug (10 sản phẩm đầu hoặc similarity >= 30%)
            if ($totalCompared <= 10 || $similarity >= 30) {
                $statusInfo = isset($product['status']) ? $product['status'] : 'UNDEFINED';
                error_log("Product ID {$product['product_id']}: {$product['name']} (Brand: {$product['brand']}, Type: {$product['type']}, Status: {$statusInfo}) - Similarity: " . round($similarity, 1) . "%");
            }
            
            // ✅ FIX: Increased threshold from 50% to 65% to reduce false positives
            // Nếu độ tương đồng >= 65% => Coi là trùng lặp
            if ($similarity >= 65) {
                $statusInfo = isset($product['status']) ? $product['status'] : 'UNDEFINED';
                error_log("✓ DUPLICATE FOUND: Product ID {$product['product_id']} (Status: {$statusInfo}) with {$similarity}% similarity");
                
                // Lấy thông tin sizes và prices từ bảng product_variants
                $variantsSql = "SELECT size, price FROM product_variants WHERE product_id = ? ORDER BY size ASC";
                $variantsStmt = $pdo->prepare($variantsSql);
                $variantsStmt->execute([$product['product_id']]);
                $variants = $variantsStmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Tách sizes và prices thành 2 mảng riêng
                $sizes = [];
                $prices = [];
                foreach ($variants as $variant) {
                    if (!empty($variant['size'])) {
                        $sizes[] = $variant['size'];
                        $prices[] = $variant['price'];
                    }
                }
                
                // Thêm sản phẩm trùng lặp vào danh sách kết quả
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
                
                // Cập nhật độ tương đồng cao nhất
                $maxSimilarity = max($maxSimilarity, $similarity);
            }
        }
        
        // Log tổng kết kết quả
        error_log("Similarity calculation completed:");
        error_log("  Total products compared: {$totalCompared}");
        error_log("  Duplicates found (>= 50%): " . count($duplicates));
        error_log("  Max similarity: " . round($maxSimilarity, 1) . "%");
        
        // Sắp xếp danh sách trùng lặp theo độ tương đồng từ cao xuống thấp
        usort($duplicates, function($a, $b) {
            return $b['similarity'] <=> $a['similarity'];
        });
        
        // Xác định mức độ cảnh báo dựa trên độ tương đồng cao nhất
        $warningLevel = 'safe';          // Mặc định: An toàn
        if ($maxSimilarity >= 85) {
            $warningLevel = 'high';       // >= 85%: Cảnh báo cao (rất giống)
        } elseif ($maxSimilarity >= 70) {
            $warningLevel = 'medium';     // >= 70%: Cảnh báo trung bình
        }
        
        // Trả về JSON kết quả
        echo json_encode([
            'success' => true,
            'has_duplicates' => !empty($duplicates),    // Có sản phẩm trùng hay không
            'duplicates' => array_slice($duplicates, 0, 5), // Chỉ trả về top 5 sản phẩm trùng nhất
            'max_similarity' => round($maxSimilarity, 1),   // Độ tương đồng cao nhất
            'warning_level' => $warningLevel,           // Mức cảnh báo (safe/medium/high)
            'total_found' => count($duplicates)         // Tổng số sản phẩm trùng tìm được
        ]);
        
    } catch (Exception $e) {
        // Bắt lỗi và trả về thông báo lỗi
        error_log("Manual duplicate check error: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'Lỗi khi kiểm tra trùng lặp: ' . $e->getMessage()
        ]);
    }
} else {
    // Action không hợp lệ
    echo json_encode(['success' => false, 'message' => 'Action không hợp lệ']);
}

/**
 * TÌM SẢN PHẨM TƯƠNG TỰ TRONG DATABASE
 * Lấy TẤT CẢ sản phẩm trong warehouse, không filter trong query
 * Để PHP tính toán độ tương đồng chi tiết cho từng sản phẩm
 * 
 * @param PDO $pdo - Database connection
 * @param int $warehouseId - ID của warehouse
 * @param array $inputData - Dữ liệu sản phẩm cần kiểm tra (name, brand, type, color)
 * @return array - Danh sách tất cả sản phẩm trong warehouse
 */
function findSimilarProducts($pdo, $warehouseId, $inputData) {
    try {
        error_log("=== FIND SIMILAR PRODUCTS ===");
        error_log("Looking for: brand={$inputData['brand']}, type={$inputData['type']}, name={$inputData['name']}");
        error_log("Warehouse ID: {$warehouseId}");
        
        // Query đơn giản: Lấy TẤT CẢ sản phẩm trong warehouse
        // KHÔNG filter theo brand/type/color ở đây
        // Để PHP tính similarity chi tiết cho từng sản phẩm
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
                GROUP_CONCAT(DISTINCT pv.color) as colors,  -- Gộp tất cả màu của sản phẩm
                pi.file_path as image_path                   -- Lấy ảnh chính
            FROM products p
            LEFT JOIN product_variants pv ON p.product_id = pv.product_id
            LEFT JOIN product_images pi ON p.product_id = pi.product_id AND pi.is_primary = 1
            WHERE p.warehouse_id = ?                         -- Chỉ lọc theo warehouse
            GROUP BY p.product_id, p.name, p.brand, p.type, p.description, p.material, p.status, p.created_at, pi.file_path
            ORDER BY p.created_at DESC                       -- Sắp xếp theo ngày tạo mới nhất
        ";
        
        // Thực thi query
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$warehouseId]);
        
        // Lấy tất cả kết quả
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        error_log("Query returned ALL " . count($results) . " products from warehouse {$warehouseId}");
        
        return $results;
        
    } catch (Exception $e) {
        // Bắt lỗi và log chi tiết
        error_log("Error in findSimilarProducts: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        return []; // Trả về mảng rỗng nếu có lỗi
    }
}

/**
 * TÍNH ĐỘ TƯƠNG ĐỒNG CHO NHẬP THỦ CÔNG
 * So sánh sản phẩm input với sản phẩm trong database
 * Sử dụng trọng số (weights) để đánh giá từng thuộc tính
 * 
 * @param array $inputData - Dữ liệu sản phẩm nhập vào (name, brand, type, color)
 * @param array $dbProduct - Sản phẩm từ database
 * @return float - Độ tương đồng (0-100)
 */
function calculateManualSimilarity($inputData, $dbProduct) {
    $totalScore = 0;    // Tổng điểm tương đồng
    $maxScore = 0;      // Tổng điểm tối đa có thể đạt được
    
    // Trọng số cho các thuộc tính (tổng = 100)
    // ✅ FIX: Adjusted weights to prevent color variants being flagged as duplicates
    $weights = [
        'brand' => 35,     // Thương hiệu: 35% (quan trọng nhất - tăng từ 30%)
        'name' => 30,      // Tên sản phẩm: 30% (tăng từ 25%)
        'type' => 20,      // Loại sản phẩm: 20% (không đổi)
        'color' => 15      // Màu sắc: 15% (giảm từ 25% - tránh flag các biến thể màu khác nhau)
    ];
    
    // 1. SO SÁNH THƯƠNG HIỆU (Brand)
    // Tính similarity của brand và cộng điểm theo trọng số
    if (!empty($inputData['brand']) && !empty($dbProduct['brand'])) {
        $brandSim = calculateTextSimilarity($inputData['brand'], $dbProduct['brand']);
        $totalScore += $brandSim * $weights['brand'] / 100;
        $maxScore += $weights['brand'];
    }
    
    // 2. SO SÁNH TÊN SẢN PHẨM (Name)
    // Tính similarity của tên sản phẩm và cộng điểm theo trọng số
    if (!empty($inputData['name']) && !empty($dbProduct['name'])) {
        $nameSim = calculateTextSimilarity($inputData['name'], $dbProduct['name']);
        $totalScore += $nameSim * $weights['name'] / 100;
        $maxScore += $weights['name'];
    }
    
    // 3. SO SÁNH LOẠI SẢN PHẨM (Type)
    // Tính similarity của loại sản phẩm (giày thể thao, giày da, etc.)
    if (!empty($inputData['type']) && !empty($dbProduct['type'])) {
        $typeSim = calculateTextSimilarity($inputData['type'], $dbProduct['type']);
        $totalScore += $typeSim * $weights['type'] / 100;
        $maxScore += $weights['type'];
    }
    
    // 4. SO SÁNH MÀU SẮC (Color)
    // Sử dụng hàm nâng cao từ TroGiupDoTuongDong.php (có normalize màu)
    if (!empty($inputData['color']) && !empty($dbProduct['colors'])) {
        $colorSim = calculateColorSimilarityEnhanced($inputData['color'], $dbProduct['colors']);
        $totalScore += $colorSim * $weights['color'] / 100;
        $maxScore += $weights['color'];
    }
    
    // Tính phần trăm tương đồng: (tổng điểm / tổng điểm tối đa) * 100
    $similarity = $maxScore > 0 ? ($totalScore / $maxScore) * 100 : 0;
    
    // Đảm bảo kết quả nằm trong khoảng 0-100
    return min(100, max(0, $similarity));
}

/**
 * LƯU Ý QUAN TRỌNG:
 * - calculateTextSimilarity() được import từ helpers/TroGiupDoTuongDong.php
 * - calculateColorSimilarityEnhanced() được import từ helpers/TroGiupDoTuongDong.php
 * - normalizeColorEnhanced() được import từ helpers/TroGiupDoTuongDong.php
 * 
 * MỤC ĐÍCH: Đảm bảo tính toán độ tương đồng THỐNG NHẤT giữa:
 *   - Hệ thống AI (them_san_pham_ai.php)
 *   - Nhập thủ công (api_kiem_tra_trung_thu_cong.php)
 * 
 * => Cùng thuật toán, cùng kết quả, không bị sai lệch!
 */
?>
