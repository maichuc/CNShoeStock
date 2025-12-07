<?php

/**
 * Enhanced Duplicate Detection Functions
 * Handles different wordings for the same product using multiple algorithms
 */

/**
 * Calculate text similarity using Levenshtein distance
 * Only declare if not already defined elsewhere
 */
if (!function_exists('calculateTextSimilarity')) {
    function calculateTextSimilarity($text1, $text2) {
        if (empty($text1) || empty($text2)) {
            return 0;
        }
        
        $text1 = strtolower(trim($text1));
        $text2 = strtolower(trim($text2));
        
        if ($text1 === $text2) {
            return 100;
        }
        
        // Calculate Levenshtein distance
        $maxLen = max(strlen($text1), strlen($text2));
        if ($maxLen == 0) return 100;
        
        $distance = levenshtein($text1, $text2);
        $similarity = (1 - $distance / $maxLen) * 100;
        
        return max(0, $similarity);
    }
}

/**
 * Enhanced similarity calculation for products
 * UNIFIED FORMULA: Uses same weights as Manual system (Brand 30%, Name 25%, Color 25%, Type 20%)
 * Only declare if not already defined elsewhere
 */
if (!function_exists('calculateEnhancedSimilarity')) {
    function calculateEnhancedSimilarity($aiData, $dbProduct) {
        $score = 0;
        $maxScore = 0;
        
        // Brand comparison (30% weight) - Most important
        $maxScore += 30;
        if (!empty($aiData['brand']) && !empty($dbProduct['brand'])) {
            $brandSimilarity = calculateTextSimilarity($aiData['brand'], $dbProduct['brand']);
            $score += $brandSimilarity * 30 / 100;
        }
        
        // Name comparison (25% weight)
        $maxScore += 25;
        if (!empty($aiData['name']) && !empty($dbProduct['name'])) {
            $nameSimilarity = calculateTextSimilarity($aiData['name'], $dbProduct['name']);
            $score += $nameSimilarity * 25 / 100;
        }
        
        // Color comparison (25% weight) - NEW: Added for consistency with Manual system
        $maxScore += 25;
        if (!empty($aiData['colors']) && !empty($dbProduct['color'])) {
            // Convert AI colors array to comma-separated string if needed
            $aiColors = is_array($aiData['colors']) ? implode(',', $aiData['colors']) : $aiData['colors'];
            $colorSimilarity = calculateColorSimilarityEnhanced($aiColors, $dbProduct['color']);
            $score += $colorSimilarity * 25 / 100;
        }
        
        // Type comparison (20% weight)
        $maxScore += 20;
        if (!empty($aiData['type']) && !empty($dbProduct['type'])) {
            $typeSimilarity = calculateTextSimilarity($aiData['type'], $dbProduct['type']);
            $score += $typeSimilarity * 20 / 100;
        }
        
        return $maxScore > 0 ? ($score / $maxScore) * 100 : 0;
    }
}

/**
 * Enhanced duplicate product detection with multiple algorithms
 * Uses NLP-powered analysis and warehouse isolation
 */
function checkDuplicateProductsEnhanced($aiData, $pdo) {
    error_log("🔍 ENHANCED checkDuplicateProductsEnhanced called with data: " . json_encode($aiData));
    
    // Get user's warehouse_id from session
    $userWarehouseId = null;
    if (isset($_SESSION['user_id'])) {
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
        // 🧠 ENHANCED: Sử dụng multiple algorithms để xử lý các mô tả khác nhau của cùng sản phẩm
        
        // Lấy thông tin từ AI analysis
        $productName = $aiData['name'] ?? '';
        $brand = $aiData['brand'] ?? '';
        $colors = isset($aiData['colors']) && is_array($aiData['colors']) ? $aiData['colors'] : [];
        $type = $aiData['type'] ?? '';
        $material = $aiData['material'] ?? '';
        $features = $aiData['features'] ?? '';
        $description = $aiData['description'] ?? '';
        $category = $aiData['category'] ?? '';
        $tags = isset($aiData['tags']) && is_array($aiData['tags']) ? $aiData['tags'] : [];
        $confidence = $aiData['confidence'] ?? 0.5;
        
        error_log("🔍 ENHANCED checkDuplicateProducts called with:");
        error_log("Product Name: '$productName', Brand: '$brand', Type: '$type'");
        error_log("Features: '$features'");
        error_log("Description: '$description'");
        error_log("User Warehouse ID: '$userWarehouseId'");
        error_log("Confidence: $confidence, Images analyzed: " . ($aiData['analyzed_images_count'] ?? 'unknown'));
        
        if (empty($productName) && empty($brand)) {
            error_log("⚠️ No product name or brand provided - skipping duplicate check");
            return [];
        }
        
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
            
            // 5. Price range analysis (if available)
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
    
    // Build comprehensive text from AI data
    $aiParts = [];
    if (!empty($aiData['description'])) $aiParts[] = $aiData['description'];
    if (!empty($aiData['features'])) $aiParts[] = $aiData['features'];
    if (!empty($aiData['name'])) $aiParts[] = $aiData['name'];
    $aiText = implode(' ', $aiParts);
    
    // Build comprehensive text from DB product
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
    
    // Check for specific feature keywords
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

// Note: calculateColorSimilarityEnhanced() and normalizeColorEnhanced() 
// are now defined in helpers/TroGiupDoTuongDong.php (included above)

?>