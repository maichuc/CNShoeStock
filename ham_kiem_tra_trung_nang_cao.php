<?php

/**
 * FILE: KIỂM TRA TRÙNG LẶP NÂNG CAO
 * 
 * Mục đích: Phát hiện sản phẩm trùng lặp với độ chính xác cao
 * Xử lý: Các cách mô tả khác nhau của cùng 1 sản phẩm
 * 
 * Ví dụ:
 * - "Nike Air Max 90" và "Nike Air Max Sneaker" -> Có thể là cùng sản phẩm
 * - "Giày thể thao Nike" và "Nike Sport Shoes" -> Cùng sản phẩm
 */

/**
 * HÀM TÍNH ĐỘ TƯƠNG ĐỒNG VĂN BẢN (LEVENSHTEIN DISTANCE)
 * 
 * Thuật toán Levenshtein:
 * - Đếm số thao tác tối thiểu để biến text1 thành text2
 * - Thao tác: Thêm, xóa, thay thế 1 ký tự
 * 
 * Công thức: Similarity = (1 - Distance / MaxLength) × 100
 * 
 * Ví dụ:
 * "kitten" vs "sitting"
 * - Distance = 3 (k->s, e->i, thêm g)
 * - MaxLength = 7
 * - Similarity = (1 - 3/7) × 100 = 57.14%
 * 
 * @param string $text1 - Văn bản 1
 * @param string $text2 - Văn bản 2
 * @return float - Độ tương đồng (0-100)
 */
if (!function_exists('calculateTextSimilarity')) {
    function calculateTextSimilarity($text1, $text2) {
        // Nếu 1 trong 2 rỗng -> Không giống (0%)
        if (empty($text1) || empty($text2)) {
            return 0;
        }
        
        // Chuẩn hóa: Chuyển về chữ thường, loại bỏ khoảng trắng thừa
        $text1 = strtolower(trim($text1));
        $text2 = strtolower(trim($text2));
        
        // Nếu giống y hệt sau khi chuẩn hóa -> 100%
        if ($text1 === $text2) {
            return 100;
        }
        
        // Tính độ dài text dài hơn (dùng làm mẫu số)
        $maxLen = max(strlen($text1), strlen($text2));
        
        // Nếu cả 2 đều rỗng -> 100%
        if ($maxLen == 0) return 100;
        
        // Tính Levenshtein Distance (số thao tác cần thiết)
        $distance = levenshtein($text1, $text2);
        
        // Chuyển thành % tương đồng
        // Công thức: (1 - distance/maxLen) × 100
        $similarity = (1 - $distance / $maxLen) * 100;
        
        // Đảm bảo không âm
        return max(0, $similarity);
    }
}

/**
 * HÀM TÍNH ĐỘ TƯƠNG ĐỒNG NÂNG CAO (ENHANCED SIMILARITY)
 * 
 * Mục đích: Tính độ giống nhau giữa sản phẩm mới (AI) và sản phẩm trong DB
 * 
 * Công thức trọng số (Weighted Score):
 * - Thương hiệu (Brand): 30% (quan trọng nhất)
 * - Tên sản phẩm (Name): 25%
 * - Màu sắc (Color): 25%
 * - Loại sản phẩm (Type): 20%
 * 
 * Tổng = 100%
 * 
 * Ví dụ:
 * Sản phẩm mới: "Giày Nike Air Max Đen"
 * Sản phẩm DB: "Nike Air Max 90 Black"
 * 
 * Tính toán:
 * - Brand: "Nike" vs "Nike" = 100% → 100% × 30% = 30 điểm
 * - Name: "Air Max" vs "Air Max 90" = 85% → 85% × 25% = 21.25 điểm
 * - Color: "Đen" vs "Black" = 100% (sau dịch) → 100% × 25% = 25 điểm
 * - Type: "Giày" vs "Giày" = 100% → 100% × 20% = 20 điểm
 * 
 * Tổng = 96.25% → Rất có thể trùng!
 * 
 * @param array $aiData - Dữ liệu từ AI
 * @param array $dbProduct - Dữ liệu từ database
 * @return float - Độ tương đồng (0-100)
 */
if (!function_exists('calculateEnhancedSimilarity')) {
    function calculateEnhancedSimilarity($aiData, $dbProduct) {
        $score = 0;        // Điểm tích lũy
        $maxScore = 0;     // Điểm tối đa có thể đạt được
        
        /**
         * SO SÁNH THƯƠNG HIỆU (30% trọng số) - QUAN TRỌNG NHẤT
         * 
         * Lý do: Cùng brand mới có khả năng là cùng sản phẩm
         * Nike ≠ Adidas -> Chắc chắn khác sản phẩm
         */
        $maxScore += 30;
        if (!empty($aiData['brand']) && !empty($dbProduct['brand'])) {
            $brandSimilarity = calculateTextSimilarity($aiData['brand'], $dbProduct['brand']);
            $score += $brandSimilarity * 30 / 100;  // Quy đổi về 30 điểm tối đa
        }
        
        /**
         * SO SÁNH TÊN SẢN PHẨM (25% trọng số)
         * 
         * Ví dụ:
         * "Air Max 90" vs "Air Max" -> ~85% tương đồng
         * "Air Max" vs "Free Run" -> ~20% tương đồng
         */
        $maxScore += 25;
        if (!empty($aiData['name']) && !empty($dbProduct['name'])) {
            $nameSimilarity = calculateTextSimilarity($aiData['name'], $dbProduct['name']);
            $score += $nameSimilarity * 25 / 100;  // Quy đổi về 25 điểm
        }
        
        /**
         * SO SÁNH MÀU SẮC (25% trọng số)
         * 
         * Xử lý:
         * - Chuyển array colors thành string
         * - Dùng hàm chuyên biệt so sánh màu (xử lý đồng nghĩa)
         * 
         * Ví dụ:
         * "Đen" vs "Black" -> 100% (sau dịch)
         * "Đỏ" vs "Xanh" -> 0%
         */
        $maxScore += 25;
        if (!empty($aiData['colors']) && !empty($dbProduct['color'])) {
            // Chuyển array sang string nếu cần
            $aiColors = is_array($aiData['colors']) ? implode(',', $aiData['colors']) : $aiData['colors'];
            
            // Dùng hàm so sánh màu chuyên biệt
            $colorSimilarity = calculateColorSimilarityEnhanced($aiColors, $dbProduct['color']);
            $score += $colorSimilarity * 25 / 100;  // Quy đổi về 25 điểm
        }
        
        /**
         * SO SÁNH LOẠI SẢN PHẨM (20% trọng số)
         * 
         * Ví dụ:
         * "Giày thể thao" vs "Sneaker" -> ~90% (đồng nghĩa)
         * "Giày cao gót" vs "Dép" -> ~10%
         */
        $maxScore += 20;
        if (!empty($aiData['type']) && !empty($dbProduct['type'])) {
            $typeSimilarity = calculateTextSimilarity($aiData['type'], $dbProduct['type']);
            $score += $typeSimilarity * 20 / 100;  // Quy đổi về 20 điểm
        }
        
        /**
         * TÍNH ĐIỂM CUỐI CÙNG
         * 
         * Công thức: (Tổng điểm / Tổng điểm tối đa) × 100
         * 
         * maxScore có thể < 100 nếu thiếu thông tin
         * Ví dụ: Chỉ có brand và name -> maxScore = 55
         */
        return $maxScore > 0 ? ($score / $maxScore) * 100 : 0;
    }
}

/**
 * HÀM KIỂM TRA TRÙNG LẶP SẢN PHẨM NÂNG CAO
 * 
 * Mục đích: Tìm các sản phẩm tương tự trong database
 * 
 * Quy trình:
 * 1. Lấy warehouse_id của user hiện tại
 * 2. Trích xuất thông tin từ AI (name, brand, color, type...)
 * 3. Query database tìm sản phẩm candidates
 * 4. Tính độ tương đồng cho từng candidate
 * 5. Lọc những sản phẩm có độ tương đồng > 70%
 * 6. Sắp xếp theo độ tương đồng giảm dần
 * 7. Trả về danh sách sản phẩm trùng
 * 
 * @param array $aiData - Dữ liệu sản phẩm từ AI
 * @param PDO $pdo - Kết nối database
 * @return array - Danh sách sản phẩm trùng lặp
 */
function checkDuplicateProductsEnhanced($aiData, $pdo) {
    error_log("🔍 ENHANCED checkDuplicateProductsEnhanced called with data: " . json_encode($aiData));
    
    /**
     * BƯỚC 1: LẤY WAREHOUSE_ID CỦA USER
     * 
     * Lý do: Chỉ kiểm tra trùng trong KHO CỦA USER
     * Không kiểm tra sản phẩm của kho khác
     */
    $userWarehouseId = null;
    
    if (isset($_SESSION['user_id'])) {
        // Query lấy warehouse_id từ bảng users
        $userSql = "SELECT warehouse_id FROM users WHERE user_id = ?";
        $userStmt = $pdo->prepare($userSql);
        $userStmt->execute([$_SESSION['user_id']]);
        $result = $userStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            $userWarehouseId = $result['warehouse_id'];
            error_log("Retrieved warehouse_id from database: " . $userWarehouseId);
        }
    }

    try {
        /**
         * BƯỚC 2: TRÍCH XUẤT THÔNG TIN TỪ AI
         * 
         * Lấy tất cả thông tin có thể để so sánh
         * Sử dụng ?? (null coalescing) để tránh lỗi nếu thiếu field
         */
        $productName = $aiData['name'] ?? '';              // Tên sản phẩm
        $brand = $aiData['brand'] ?? '';                    // Thương hiệu
        $colors = isset($aiData['colors']) && is_array($aiData['colors']) ? $aiData['colors'] : [];  // Màu sắc
        $type = $aiData['type'] ?? '';                      // Loại (Giày thể thao, Dép...)
        $material = $aiData['material'] ?? '';              // Chất liệu
        $features = $aiData['features'] ?? '';              // Tính năng
        $description = $aiData['description'] ?? '';        // Mô tả
        $category = $aiData['category'] ?? '';              // Danh mục
        $tags = isset($aiData['tags']) && is_array($aiData['tags']) ? $aiData['tags'] : [];  // Tags
        $confidence = $aiData['confidence'] ?? 0.5;         // Độ tin cậy AI
        
        // Log để debug
        error_log("🔍 ENHANCED checkDuplicateProducts called with:");
        error_log("Product Name: '$productName', Brand: '$brand', Type: '$type'");
        error_log("Features: '$features'");
        error_log("Description: '$description'");
        error_log("User Warehouse ID: '$userWarehouseId'");
        error_log("Confidence: $confidence, Images analyzed: " . ($aiData['analyzed_images_count'] ?? 'unknown'));
        
        /**
         * VALIDATE DỮ LIỆU ĐẦU VÀO
         * 
         * Điều kiện tối thiểu: Phải có (Tên SẢN HOẶC Thương hiệu)
         * Nếu không có cả 2 -> Không thể kiểm tra trùng
         */
        if (empty($productName) && empty($brand)) {
            error_log("⚠️ No product name or brand provided - skipping duplicate check");
            return [];  // Trả về mảng rỗng (không có trùng)
        }
        
        /**
         * KIỂM TRA WAREHOUSE_ID
         * 
         * Nếu không có warehouse_id -> Không thể xác định kho
         * -> Không thể kiểm tra trùng
         */
        if (empty($userWarehouseId)) {
            error_log("⚠️ No warehouse_id for current user - skipping duplicate check");
            return [];
        }
        
        // 🎯 STEP 1: Broad search để tìm candidates (bao gồm cả sản phẩm tạm ngừng)
        $sql = "
            SELECT DISTINCT
                p.product_id,
                p.name,
                p.brand,
                p.description,
                p.type,
                p.material,
                p.status,
                pv.sku,
                pv.color,
                pv.size,
                pv.price,
                pi.file_path as image_path
            FROM products p
            LEFT JOIN product_variants pv ON p.product_id = pv.product_id
            LEFT JOIN product_images pi ON p.product_id = pi.product_id AND pi.is_primary = 1
            WHERE p.warehouse_id = ?
        ";
        
        $params = [$userWarehouseId];
        $whereConditions = [];
        
        // 🔍 Enhanced search conditions
        
        // Priority 1: Brand matching (most reliable)
        if (!empty($brand) && $brand !== 'Unknown') {
            $whereConditions[] = "p.brand LIKE ?";
            $params[] = '%' . $brand . '%';
        }
        
        // Priority 2: Name matching (với từ khóa ngắn hơn)
        if (!empty($productName)) {
            $nameWords = explode(' ', $productName);
            $nameConditions = [];
            foreach ($nameWords as $word) {
                $cleanWord = trim($word);
                if (strlen($cleanWord) >= 2) { // Giảm từ 3 xuống 2
                    $nameConditions[] = "p.name LIKE ?";
                    $params[] = '%' . $cleanWord . '%';
                }
            }
            if (!empty($nameConditions)) {
                $whereConditions[] = '(' . implode(' OR ', $nameConditions) . ')';
            }
        }
        
        // Priority 3: Type matching
        if (!empty($type)) {
            $whereConditions[] = "p.type LIKE ?";
            $params[] = '%' . $type . '%';
        }
        
        // Priority 4: Feature/Description keywords
        if (!empty($features) || !empty($description)) {
            $searchText = trim($features . ' ' . $description);
            $featureWords = explode(' ', $searchText);
            $featureConditions = [];
            
            foreach ($featureWords as $word) {
                $cleanWord = trim($word);
                // Tìm các từ khóa đặc biệt
                if (strlen($cleanWord) >= 3 && 
                    (stripos($cleanWord, 'logo') !== false || 
                     stripos($cleanWord, $brand) !== false ||
                     stripos($cleanWord, 'đính') !== false ||
                     stripos($cleanWord, 'khóa') !== false ||
                     stripos($cleanWord, 'quai') !== false ||
                     stripos($cleanWord, 'gót') !== false ||
                     stripos($cleanWord, 'jeremy') !== false)) {
                    $featureConditions[] = "(p.description LIKE ? OR p.name LIKE ?)";
                    $params[] = '%' . $cleanWord . '%';
                    $params[] = '%' . $cleanWord . '%';
                }
            }
            
            if (!empty($featureConditions)) {
                $whereConditions[] = '(' . implode(' OR ', $featureConditions) . ')';
            }
        }
        
        if (!empty($whereConditions)) {
            $sql .= " AND (" . implode(' OR ', $whereConditions) . ")";
        }
        
        $sql .= " ORDER BY p.created_at DESC LIMIT 25"; // Increase limit
        
        error_log("Enhanced SQL Query: " . $sql);
        error_log("Enhanced SQL Params: " . json_encode($params));
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $potentialDuplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        error_log("📊 [ENHANCED_DUPLICATE] Found " . count($potentialDuplicates) . " potential products to check against");
        error_log("📊 [ENHANCED_DUPLICATE] AI Data for comparison: brand={$brand}, name={$productName}, type={$type}");
        
        if (count($potentialDuplicates) == 0) {
            // Fallback: search all products if no candidates found
            error_log("📊 [ENHANCED_DUPLICATE] No candidates found, trying broad search...");
            $broadSql = "
                SELECT DISTINCT p.product_id, p.name, p.brand, p.description, p.type, p.material, p.status,
                       pv.sku, pv.color, pv.size, pv.price, 
                       pi.file_path as image_path
                FROM products p
                LEFT JOIN product_variants pv ON p.product_id = pv.product_id
                LEFT JOIN product_images pi ON p.product_id = pi.product_id AND pi.is_primary = 1
                WHERE p.warehouse_id = ?
                ORDER BY p.created_at DESC LIMIT 10
            ";
            $broadStmt = $pdo->prepare($broadSql);
            $broadStmt->execute([$userWarehouseId]);
            $potentialDuplicates = $broadStmt->fetchAll(PDO::FETCH_ASSOC);
            error_log("📊 [ENHANCED_DUPLICATE] Broad search found " . count($potentialDuplicates) . " products");
        }
        
        // 🎯 STEP 2: Enhanced similarity calculation với multiple algorithms
        $duplicates = [];
        
        foreach ($potentialDuplicates as $product) {
            error_log("\n=== Checking product: " . $product['name'] . " (ID: " . $product['product_id'] . ") ===");
            
            // Algorithm 1: Enhanced similarity (existing)
            $enhancedSimilarity = calculateEnhancedSimilarity($aiData, $product);
            
            // Algorithm 2: Semantic text similarity (for descriptions)
            $semanticSimilarity = calculateSemanticDescriptionSimilarity($aiData, $product);
            
            // Algorithm 3: Brand + Key features matching
            $brandFeatureSimilarity = calculateBrandFeatureSimilarity($aiData, $product);
            
            // 🧠 Combine multiple similarities with weights
            $finalSimilarity = ($enhancedSimilarity * 0.4) + 
                              ($semanticSimilarity * 0.4) + 
                              ($brandFeatureSimilarity * 0.2);
            
            error_log("Enhanced similarity: {$enhancedSimilarity}%");
            error_log("Semantic similarity: {$semanticSimilarity}%");
            error_log("Brand+Feature similarity: {$brandFeatureSimilarity}%");
            error_log("Final combined similarity: {$finalSimilarity}%");
            
            // 🎯 SMART DYNAMIC THRESHOLDS - Lowered for better duplicate detection
            $threshold = 15; // Lowered base threshold from 25% to 15% for better detection
            
            // 1. Brand difference analysis
            $brandDifference = false;
            if (!empty($brand) && !empty($product['brand'])) {
                $brandSimilarity = calculateTextSimilarity($brand, $product['brand']);
                if ($brandSimilarity < 30) { // Brands are significantly different
                    $brandDifference = true;
                    $threshold += 10; // Increase threshold for different brands
                    error_log("Different brands detected ({$brandSimilarity}% similarity) - increasing threshold to {$threshold}%");
                }
            }
            
            // 2. Product type conflict analysis (universal)
            $typeConflict = false;
            if (!empty($type) && !empty($product['type'])) {
                $typeSimilarity = calculateTextSimilarity($type, $product['type']);
                
                // Define conflicting product categories automatically
                $productCategories = [
                    'footwear_formal' => ['sandal', 'cao gót', 'high heel', 'platform', 'formal', 'dress shoe'],
                    'footwear_casual' => ['sneaker', 'thể thao', 'sport', 'running', 'tennis', 'casual', 'canvas'],
                    'footwear_boot' => ['boot', 'ankle', 'combat', 'chelsea', 'work boot'],
                    'clothing_top' => ['áo', 'shirt', 'blouse', 'sweater', 'jacket', 'hoodie', 'top', 'tee', 'polo'],
                    'clothing_bottom' => ['quần', 'pants', 'jeans', 'shorts', 'skirt', 'trousers', 'bottom'],
                    'accessories' => ['túi', 'bag', 'belt', 'hat', 'scarf', 'jewelry', 'watch', 'necklace'],
                    'electronics' => ['phone', 'laptop', 'tablet', 'headphone', 'speaker', 'iphone', 'ipad', 'macbook'],
                    'home_furniture' => ['chair', 'table', 'sofa', 'bed', 'desk', 'cabinet'],
                    'beauty' => ['lipstick', 'foundation', 'perfume', 'skincare', 'makeup'],
                    'books_media' => ['book', 'novel', 'magazine', 'cd', 'dvd', 'game']
                ];
                
                $aiCategory = detectProductCategory($type, $productCategories);
                $dbCategory = detectProductCategory($product['type'], $productCategories);
                
                if ($aiCategory && $dbCategory && $aiCategory !== $dbCategory) {
                    $typeConflict = true;
                    $threshold += 15; // Major increase for different product categories
                    error_log("Product category conflict detected: {$aiCategory} vs {$dbCategory} - increasing threshold to {$threshold}%");
                } elseif ($typeSimilarity < 40) {
                    $threshold += 8; // Minor increase for similar categories but different types
                    error_log("Product type difference detected ({$typeSimilarity}% similarity) - increasing threshold to {$threshold}%");
                }
            }
            
            // 3. Color conflict analysis (universal)
            $colorConflict = false;
            if (!empty($colors) && !empty($product['color'])) {
                $aiColors = strtolower(implode(' ', $colors));
                $dbColor = strtolower($product['color']);
                
                // Define color groups for conflict detection
                $colorGroups = [
                    'warm' => ['đỏ', 'red', 'cam', 'orange', 'vàng', 'yellow', 'hồng', 'pink'],
                    'cool' => ['xanh', 'blue', 'lục', 'green', 'tím', 'purple', 'violet'],
                    'neutral' => ['trắng', 'white', 'đen', 'black', 'xám', 'gray', 'grey', 'nâu', 'brown'],
                    'metallic' => ['vàng kim', 'gold', 'bạc', 'silver', 'đồng', 'bronze']
                ];
                
                $aiColorGroup = detectColorGroup($aiColors, $colorGroups);
                $dbColorGroup = detectColorGroup($dbColor, $colorGroups);
                
                if ($aiColorGroup && $dbColorGroup && $aiColorGroup !== $dbColorGroup) {
                    $colorConflict = true;
                    $threshold += 12; // Increase for different color groups
                    error_log("Color group conflict detected: {$aiColorGroup} vs {$dbColorGroup} - increasing threshold to {$threshold}%");
                }
            }
            
            // 4. Material conflict analysis (universal)
            $materialConflict = false;
            if (!empty($material) && !empty($product['material'])) {
                $materialSimilarity = calculateTextSimilarity($material, $product['material']);
                
                // Define material categories
                $materialCategories = [
                    'luxury' => ['da', 'leather', 'satin', 'silk', 'lụa', 'velvet', 'cashmere'],
                    'synthetic' => ['synthetic', 'plastic', 'nylon', 'polyester', 'artificial'],
                    'natural' => ['cotton', 'canvas', 'linen', 'wool', 'bamboo'],
                    'metal' => ['metal', 'steel', 'aluminum', 'gold', 'silver']
                ];
                
                $aiMaterialCat = detectMaterialCategory($material, $materialCategories);
                $dbMaterialCat = detectMaterialCategory($product['material'], $materialCategories);
                
                if ($aiMaterialCat && $dbMaterialCat && $aiMaterialCat !== $dbMaterialCat) {
                    $materialConflict = true;
                    $threshold += 10; // Increase for different material categories
                    error_log("Material category conflict: {$aiMaterialCat} vs {$dbMaterialCat} - increasing threshold to {$threshold}%");
                }
            }
            
            // 5. Price range analysis (nếu có sẵn)
            if (!empty($aiData['price']) && !empty($product['price'])) {
                $aiPrice = floatval($aiData['price']);
                $dbPrice = floatval($product['price']);
                
                if ($aiPrice > 0 && $dbPrice > 0) {
                    $priceRatio = max($aiPrice, $dbPrice) / min($aiPrice, $dbPrice);
                    if ($priceRatio > 3) { // Prices differ by more than 3x
                        $threshold += 8;
                        error_log("Significant price difference detected (ratio: {$priceRatio}) - increasing threshold to {$threshold}%");
                    }
                }
            }
            
            // 6. Confidence-based adjustment (stricter for high confidence)
            if ($confidence >= 0.8) {
                $threshold += 5; // More strict for high confidence AI
                error_log("High AI confidence detected - increasing threshold to {$threshold}% for stricter detection");
            } elseif ($confidence <= 0.3) {
                $threshold -= 3; // More lenient for low confidence AI
                error_log("Low AI confidence detected - decreasing threshold to {$threshold}% for more lenient detection");
            }
            
            // 7. Multiple conflicts compound the threshold
            $conflictCount = ($brandDifference ? 1 : 0) + ($typeConflict ? 1 : 0) + 
                           ($colorConflict ? 1 : 0) + ($materialConflict ? 1 : 0);
            
            if ($conflictCount >= 2) {
                $threshold += ($conflictCount - 1) * 5; // Additional penalty for multiple conflicts
                error_log("Multiple conflicts detected ({$conflictCount}) - final threshold adjustment to {$threshold}%");
            }
            
            error_log("Final similarity: {$finalSimilarity}% vs threshold {$threshold}%");
            
            if ($finalSimilarity >= $threshold) {
                $duplicates[] = [
                    'product_id' => $product['product_id'],
                    'name' => $product['name'],
                    'brand' => $product['brand'],
                    'description' => $product['description'],
                    'type' => $product['type'],
                    'material' => $product['material'],
                    'status' => $product['status'] ?? 'active',
                    'sku' => $product['sku'],
                    'color' => $product['color'],
                    'size' => $product['size'],
                    'price' => $product['price'],
                    'image_path' => $product['image_path'],
                    'category_name' => $product['category_name'],
                    'similarity_percentage' => round($finalSimilarity, 1),
                    'enhanced_similarity' => round($enhancedSimilarity, 1),
                    'semantic_similarity' => round($semanticSimilarity, 1),
                    'brand_feature_similarity' => round($brandFeatureSimilarity, 1),
                    'detection_method' => 'enhanced_hybrid'
                ];
                
                error_log("✅ ENHANCED DUPLICATE DETECTED with {$finalSimilarity}% combined similarity! Status: " . ($product['status'] ?? 'active'));
            } else {
                error_log("❌ Not a duplicate (below threshold)");
            }
        }
        
        error_log("📊 [ENHANCED_DUPLICATE] Final result: " . count($duplicates) . " duplicates found");
        if (!empty($duplicates)) {
            $duplicateNames = array_map(function($d) { return $d['name']; }, $duplicates);
            error_log("📊 [ENHANCED_DUPLICATE] Duplicate products: " . implode(', ', $duplicateNames));
        }
        
        return $duplicates;
        
    } catch (Exception $e) {
        error_log("Error in enhanced duplicate check: " . $e->getMessage());
        return [];
    }
}

// 🧠 Enhanced semantic description similarity 
function calculateSemanticDescriptionSimilarity($aiData, $dbProduct) {
    $aiText = '';
    $dbText = '';
    
    // Xây dựng comprehensive text from AI data
    $aiParts = [];
    if (!empty($aiData['description'])) $aiParts[] = $aiData['description'];
    if (!empty($aiData['features'])) $aiParts[] = $aiData['features'];
    if (!empty($aiData['name'])) $aiParts[] = $aiData['name'];
    $aiText = implode(' ', $aiParts);
    
    // Xây dựng comprehensive text from DB product
    $dbParts = [];
    if (!empty($dbProduct['description'])) $dbParts[] = $dbProduct['description'];
    if (!empty($dbProduct['name'])) $dbParts[] = $dbProduct['name'];
    $dbText = implode(' ', $dbParts);
    
    if (empty($aiText) || empty($dbText)) return 0;
    
    // Use direct text similarity instead of hardcoded concepts
    $conceptSimilarity = calculateTextSimilarity($aiText, $dbText);
    
    // Boost for exact phrase matches
    $phraseBoost = 0;
    if (stripos($aiText, $dbProduct['name']) !== false || stripos($dbText, $aiData['name']) !== false) {
        $phraseBoost = 20;
    }
    
    // Additional boost for brand name matches in text
    $brandBoost = 0;
    if (!empty($aiData['brand']) && stripos($dbText, $aiData['brand']) !== false) {
        $brandBoost = 15;
    }
    
    return min(100, $conceptSimilarity + $phraseBoost + $brandBoost);
}

// 🎯 Brand and key features similarity
function calculateBrandFeatureSimilarity($aiData, $dbProduct) {
    $score = 0;
    $maxScore = 0;
    
    // Brand matching (60% weight)
    $maxScore += 60;
    if (!empty($aiData['brand']) && !empty($dbProduct['brand'])) {
        if (stripos($dbProduct['brand'], $aiData['brand']) !== false || 
            stripos($aiData['brand'], $dbProduct['brand']) !== false) {
            $score += 60; // Exact brand match
        } else {
            $brandSimilarity = calculateTextSimilarity($aiData['brand'], $dbProduct['brand']);
            $score += ($brandSimilarity / 100) * 60;
        }
    } elseif (empty($aiData['brand']) || empty($dbProduct['brand'])) {
        $maxScore -= 60; // Don't penalize if one brand is missing
    }
    
    // Feature matching (40% weight)
    $maxScore += 40;
    $featureScore = 0;
    
    // Kiểm tra các từ khóa đặc điểm cụ thể
    $aiFeatures = strtolower(($aiData['features'] ?? '') . ' ' . ($aiData['description'] ?? ''));
    $dbFeatures = strtolower(($dbProduct['description'] ?? '') . ' ' . ($dbProduct['name'] ?? ''));
    
    $keyFeatures = ['logo', 'đính', 'khóa', 'quai', 'gót', 'platform', 'buckle', 'strap', 'heel'];
    $matchedFeatures = 0;
    $totalFeatures = 0;
    
    foreach ($keyFeatures as $feature) {
        $aiHas = stripos($aiFeatures, $feature) !== false;
        $dbHas = stripos($dbFeatures, $feature) !== false;
        
        if ($aiHas || $dbHas) {
            $totalFeatures++;
            if ($aiHas && $dbHas) {
                $matchedFeatures++;
            }
        }
    }
    
    if ($totalFeatures > 0) {
        $featureScore = ($matchedFeatures / $totalFeatures) * 40;
        $score += $featureScore;
    } else {
        $maxScore -= 40; // No features to compare
    }
    
    return $maxScore > 0 ? min(100, ($score / $maxScore) * 100) : 0;
}

// Helper functions for category detection
function detectProductCategory($productType, $categories) {
    $productType = strtolower($productType);
    
    foreach ($categories as $category => $keywords) {
        foreach ($keywords as $keyword) {
            if (stripos($productType, $keyword) !== false) {
                return $category;
            }
        }
    }
    
    return null;
}

function detectColorGroup($color, $colorGroups) {
    $color = strtolower($color);
    
    foreach ($colorGroups as $group => $colors) {
        foreach ($colors as $colorKeyword) {
            if (stripos($color, $colorKeyword) !== false) {
                return $group;
            }
        }
    }
    
    return null;
}

function detectMaterialCategory($material, $materialCategories) {
    $material = strtolower($material);
    
    foreach ($materialCategories as $category => $materials) {
        foreach ($materials as $materialKeyword) {
            if (stripos($material, $materialKeyword) !== false) {
                return $category;
            }
        }
    }
    
    return null;
}

// Lưu ý: calculateColorSimilarityEnhanced() và normalizeColorEnhanced() 
// giờ được định nghĩa trong helpers/TroGiupDoTuongDong.php (đã include ở trên)

?>