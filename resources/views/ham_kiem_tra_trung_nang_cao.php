<?php

/**
 * FILE: KIỂM TRA TRÙNG LẶP NÂNG CAO
 * 
 * Mục đích: Phát hiện sản phẩm trùng lặp với độ chính xác cao
 * Xử lý: Các cách mô tả khác nhau của cùng 1 sản phẩm
 * 

 */

// Import các hàm similarity từ TroGiupDoTuongDong.php
require_once __DIR__ . '/../../app/Helpers/TroGiupDoTuongDong.php';


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
    
    if (isset($_SESSION['warehouse_id'])) {
        $userWarehouseId = $_SESSION['warehouse_id'];
        error_log("Retrieved warehouse_id from session: " . $userWarehouseId);
    } elseif (isset($_SESSION['user_id'])) {
        // Query lấy warehouse_id từ bảng users
        $userSql = "SELECT warehouse_id FROM users WHERE user_id = ?";
        $userStmt = $pdo->prepare($userSql);
        $userStmt->execute([$_SESSION['user_id']]);
        $result = $userStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            $userWarehouseId = $result['warehouse_id'];
            $_SESSION['warehouse_id'] = $userWarehouseId; // Cache it
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
        
        // Priority 5: Color matching (if provided)
        if (!empty($colors)) {
            $colorConditions = [];
            foreach ($colors as $color) {
                if (strlen($color) >= 2) {
                    $colorConditions[] = "pv.color LIKE ?";
                    $params[] = '%' . $color . '%';
                }
            }
            if (!empty($colorConditions)) {
                $whereConditions[] = '(' . implode(' OR ', $colorConditions) . ')';
            }
        }
        
        if (!empty($whereConditions)) {
            $sql .= " AND (" . implode(' OR ', $whereConditions) . ")";
        }
        
        $sql .= " GROUP BY p.product_id ORDER BY p.created_at DESC LIMIT 50"; // Increase limit and add GROUP BY
        
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
        
        // 🎯 STEP 2: Enhanced similarity calculation using unified formula
        $duplicates = [];
        
        foreach ($potentialDuplicates as $product) {
            error_log("\n=== Checking product: " . $product['name'] . " (ID: " . $product['product_id'] . ") ===");
            
            // Calculate similarity using unified formula
            $finalSimilarity = calculateEnhancedSimilarity($aiData, $product);
            
            error_log("Similarity score: {$finalSimilarity}%");
            
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
                    'detection_method' => 'enhanced_unified'
                ];
                
                error_log("✅ DUPLICATE DETECTED with {$finalSimilarity}% similarity! Status: " . ($product['status'] ?? 'active'));
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

/**
 * PHÁT HIỆN DANH MỤC SẢN PHẨM
 * 
 * Mục đích: Xác định sản phẩm thuộc danh mục nào dựa trên loại sản phẩm
 * Phương pháp: Khớp từ khóa trong productType với danh sách từ khóa của từng danh mục
 * 
 * @param string $productType - Loại sản phẩm (ví dụ: "Giày thể thao", "Sneaker")
 * @param array $categories - Mảng danh mục và từ khóa [category => [keywords...]]
 * @return string|null - Tên danh mục hoặc null nếu không khớp
 * 
 * Ví dụ:
 * productType = "Giày sneaker thể thao"
 * categories = ['footwear_casual' => ['sneaker', 'thể thao', 'sport']]
 * => Trả về: 'footwear_casual'
 */
function detectProductCategory($productType, $categories) {
    // Chuyển về chữ thường để so sánh không phân biệt hoa thường
    $productType = strtolower($productType);
    
    // Duyệt qua từng danh mục
    foreach ($categories as $category => $keywords) {
        // Duyệt qua từng từ khóa của danh mục
        foreach ($keywords as $keyword) {
            // Nếu tìm thấy từ khóa trong productType -> trả về danh mục này
            if (stripos($productType, $keyword) !== false) {
                return $category;
            }
        }
    }
    
    // Không tìm thấy danh mục phù hợp
    return null;
}

/**
 * PHÁT HIỆN NHÓM MÀU SẮC
 * 
 * Mục đích: Xác định màu sắc thuộc nhóm nào (ấm, lạnh, trung tính, kim loại)
 * Lý do: Để phát hiện xung đột màu (ví dụ: đỏ vs xanh dương là 2 nhóm khác nhau)
 * 
 * @param string $color - Màu sắc cần kiểm tra (ví dụ: "đỏ", "red", "xanh lam")
 * @param array $colorGroups - Mảng nhóm màu [group => [color keywords...]]
 * @return string|null - Tên nhóm màu hoặc null
 * 
 * Ví dụ:
 * color = "đỏ cam"
 * colorGroups = ['warm' => ['đỏ', 'red', 'cam', 'orange'], 'cool' => ['xanh', 'blue']]
 * => Trả về: 'warm'
 */
function detectColorGroup($color, $colorGroups) {
    // Chuyển về chữ thường
    $color = strtolower($color);
    
    // Duyệt qua từng nhóm màu
    foreach ($colorGroups as $group => $colors) {
        // Duyệt qua từng từ khóa màu trong nhóm
        foreach ($colors as $colorKeyword) {
            // Nếu tìm thấy từ khóa màu trong chuỗi màu đầu vào
            if (stripos($color, $colorKeyword) !== false) {
                return $group; // Trả về tên nhóm màu
            }
        }
    }
    
    // Không thuộc nhóm màu nào
    return null;
}

/**
 * PHÁT HIỆN DANH MỤC CHẤT LIỆU
 * 
 * Mục đích: Xác định chất liệu thuộc loại nào (cao cấp, tổng hợp, tự nhiên, kim loại)
 * Lý do: Để phát hiện xung đột chất liệu (da thật vs nhựa tổng hợp là 2 loại khác nhau)
 * 
 * @param string $material - Chất liệu cần kiểm tra (ví dụ: "da bò", "leather", "synthetic")
 * @param array $materialCategories - Mảng danh mục chất liệu [category => [keywords...]]
 * @return string|null - Tên danh mục chất liệu hoặc null
 * 
 * Ví dụ:
 * material = "da thật cao cấp"
 * materialCategories = ['luxury' => ['da', 'leather', 'satin'], 'synthetic' => ['synthetic', 'plastic']]
 * => Trả về: 'luxury'
 */
function detectMaterialCategory($material, $materialCategories) {
    // Chuyển về chữ thường
    $material = strtolower($material);
    
    // Duyệt qua từng danh mục chất liệu
    foreach ($materialCategories as $category => $materials) {
        // Duyệt qua từng từ khóa chất liệu trong danh mục
        foreach ($materials as $materialKeyword) {
            // Nếu tìm thấy từ khóa trong chuỗi chất liệu đầu vào
            if (stripos($material, $materialKeyword) !== false) {
                return $category; // Trả về tên danh mục
            }
        }
    }
    
    // Không thuộc danh mục chất liệu nào
    return null;
}

/**
 * LƯU Ý QUAN TRỌNG:
 * 
 * Các hàm sau được định nghĩa trong file helpers/TroGiupDoTuongDong.php:
 * - calculateColorSimilarityEnhanced(): Tính độ tương đồng màu sắc nâng cao
 * - normalizeColorEnhanced(): Chuẩn hóa tên màu (ví dụ: "red", "đỏ" -> cùng 1 mã)
 * - calculateTextSimilarity(): Tính độ tương đồng văn bản
 * - calculateEnhancedSimilarity(): Tính độ tương đồng tổng hợp nâng cao
 * 
 * File đã được include ở đầu file này bằng: require_once __DIR__ . '/../../app/Helpers/TroGiupDoTuongDong.php';
 */

?>