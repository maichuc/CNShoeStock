<?php
/**
 * Smart Product Matcher
 * Phát hiện sản phẩm trùng lặp và nhóm variants
 */

class SmartProductMatcher {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Phát hiện và nhóm sản phẩm trùng lặp từ AI results
     */
    public function detectAndGroupDuplicates($aiResults) {
        $grouped = [];
        $duplicateMap = [];
        
        foreach ($aiResults as $index => $result) {
            if (!$result['success']) {
                // Sản phẩm lỗi - không xử lý
                $result['is_duplicate'] = false;
                $result['group_id'] = null;
                $result['duplicate_count'] = 0;
                $grouped[] = $result;
                continue;
            }
            
            // Tạo key để so sánh
            $productKey = $this->generateProductKey($result);
            
            // Kiểm tra đã tồn tại trong grouped chưa
            if (isset($duplicateMap[$productKey])) {
                // Tìm thấy trùng lặp
                $originalIndex = $duplicateMap[$productKey];
                
                // Tăng số lượng của sản phẩm gốc
                $grouped[$originalIndex]['quantity'] = ($grouped[$originalIndex]['quantity'] ?? 1) + 1;
                $grouped[$originalIndex]['duplicate_count']++;
                $grouped[$originalIndex]['duplicate_images'][] = [
                    'image' => $result['image'] ?? '',
                    'confidence' => $result['confidence'] ?? 0,
                    'ai_source' => $result['ai_source'] ?? 'unknown'
                ];
                
                // Đánh dấu item hiện tại là duplicate
                $result['is_duplicate'] = true;
                $result['group_id'] = $originalIndex;
                $result['merged_into'] = $originalIndex;
                
            } else {
                // Sản phẩm mới - thêm vào grouped
                $result['is_duplicate'] = false;
                $result['group_id'] = count($grouped);
                $result['quantity'] = 1;
                $result['duplicate_count'] = 0;
                $result['duplicate_images'] = [];
                
                // Lưu index để check duplicate sau
                $duplicateMap[$productKey] = count($grouped);
                
                $grouped[] = $result;
            }
        }
        
        return [
            'grouped_results' => array_values(array_filter($grouped, function($item) {
                return !($item['is_duplicate'] ?? false);
            })),
            'all_results' => $grouped,
            'duplicate_summary' => [
                'total_images' => count($aiResults),
                'unique_products' => count($duplicateMap),
                'duplicates_found' => count($aiResults) - count($duplicateMap),
                'duplicate_rate' => count($aiResults) > 0 ? round((count($aiResults) - count($duplicateMap)) / count($aiResults) * 100, 1) : 0
            ]
        ];
    }
    
    /**
     * Tạo key để so sánh sản phẩm
     */
    private function generateProductKey($result) {
        // Chuẩn hóa các giá trị
        $brand = strtolower(trim($result['brand'] ?? ''));
        $model = strtolower(trim($result['model'] ?? ''));
        $color = strtolower(trim($result['color'] ?? ''));
        $size = trim($result['size'] ?? '');
        
        // Xử lý các tên tương đương
        $brand = $this->normalizeBrand($brand);
        $color = $this->normalizeColor($color);
        
        // Tạo key unique
        return "{$brand}|{$model}|{$color}|{$size}";
    }
    
    /**
     * Chuẩn hóa tên brand
     */
    private function normalizeBrand($brand) {
        $brandMap = [
            'nike' => ['nike', 'nike shoes', 'nike sportswear'],
            'adidas' => ['adidas', 'adidas originals', 'adidas performance'],
            'puma' => ['puma', 'puma shoes'],
            'converse' => ['converse', 'converse all star'],
            'vans' => ['vans', 'vans shoes'],
            'new balance' => ['new balance', 'nb', 'newbalance']
        ];
        
        foreach ($brandMap as $standard => $variants) {
            if (in_array($brand, $variants)) {
                return $standard;
            }
        }
        
        return $brand;
    }
    
    /**
     * Chuẩn hóa tên màu
     */
    private function normalizeColor($color) {
        $colorMap = [
            'đen' => ['đen', 'black', 'den', 'đen nhám', 'đen bóng'],
            'trắng' => ['trắng', 'white', 'trang', 'trắng tinh', 'trắng sữa'],
            'xanh navy' => ['xanh navy', 'navy', 'navy blue', 'xanh đậm'],
            'xanh dương' => ['xanh dương', 'blue', 'xanh', 'xanh da trời'],
            'đỏ' => ['đỏ', 'red', 'do', 'đỏ tươi'],
            'vàng' => ['vàng', 'yellow', 'vang'],
            'xanh lá' => ['xanh lá', 'green', 'xanh lá cây'],
            'nâu' => ['nâu', 'brown', 'nau', 'nâu đất'],
            'xám' => ['xám', 'gray', 'grey', 'xam', 'xám nhạt']
        ];
        
        foreach ($colorMap as $standard => $variants) {
            if (in_array($color, $variants)) {
                return $standard;
            }
        }
        
        return $color;
    }
    
    /**
     * So sánh similarity giữa 2 sản phẩm
     */
    public function calculateSimilarity($product1, $product2) {
        $score = 0;
        $maxScore = 4;
        
        // Brand similarity (weight: 25%)
        if ($this->normalizeBrand(strtolower($product1['brand'] ?? '')) === 
            $this->normalizeBrand(strtolower($product2['brand'] ?? ''))) {
            $score += 1;
        }
        
        // Model similarity (weight: 25%)
        $modelSimilarity = $this->stringSimilarity(
            strtolower($product1['model'] ?? ''),
            strtolower($product2['model'] ?? '')
        );
        if ($modelSimilarity > 0.8) {
            $score += 1;
        } elseif ($modelSimilarity > 0.6) {
            $score += 0.5;
        }
        
        // Color similarity (weight: 25%)
        if ($this->normalizeColor(strtolower($product1['color'] ?? '')) === 
            $this->normalizeColor(strtolower($product2['color'] ?? ''))) {
            $score += 1;
        }
        
        // Size similarity (weight: 25%)
        if (trim($product1['size'] ?? '') === trim($product2['size'] ?? '')) {
            $score += 1;
        }
        
        return round(($score / $maxScore) * 100, 1);
    }
    
    /**
     * Tính độ tương đồng giữa 2 chuỗi
     */
    private function stringSimilarity($str1, $str2) {
        if (empty($str1) || empty($str2)) {
            return 0;
        }
        
        // Levenshtein distance
        $maxLen = max(strlen($str1), strlen($str2));
        if ($maxLen === 0) {
            return 1.0;
        }
        
        $distance = levenshtein($str1, $str2);
        return 1 - ($distance / $maxLen);
    }
    
    /**
     * Tìm sản phẩm tương tự trong database
     */
    public function findSimilarInDatabase($product) {
        try {
            // Search by brand and model
            $sql = "SELECT p.product_id, p.name, p.description,
                           pv.variant_id, pv.sku, pv.color, pv.size, pv.price
                    FROM products p
                    LEFT JOIN product_variants pv ON p.product_id = pv.product_id
                    WHERE (LOWER(p.name) LIKE ? OR LOWER(p.description) LIKE ?)
                    LIMIT 10";
            
            $searchTerm = '%' . strtolower($product['brand'] . ' ' . $product['model']) . '%';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$searchTerm, $searchTerm]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $matches = [];
            foreach ($results as $dbProduct) {
                $similarity = $this->calculateSimilarity($product, [
                    'brand' => $dbProduct['name'],
                    'model' => $dbProduct['description'],
                    'color' => $dbProduct['color'],
                    'size' => $dbProduct['size']
                ]);
                
                if ($similarity >= 70) {
                    $matches[] = [
                        'product_id' => $dbProduct['product_id'],
                        'variant_id' => $dbProduct['variant_id'],
                        'name' => $dbProduct['name'],
                        'sku' => $dbProduct['sku'],
                        'color' => $dbProduct['color'],
                        'size' => $dbProduct['size'],
                        'price' => $dbProduct['price'],
                        'similarity' => $similarity
                    ];
                }
            }
            
            // Sort by similarity
            usort($matches, function($a, $b) {
                return $b['similarity'] <=> $a['similarity'];
            });
            
            return $matches;
            
        } catch (Exception $e) {
            error_log("Find similar error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Merge duplicates với strategy khác nhau
     */
    public function mergeDuplicates($products, $strategy = 'sum_quantity') {
        $merged = [];
        
        foreach ($products as $product) {
            if ($product['is_duplicate'] ?? false) {
                continue; // Skip duplicates
            }
            
            // Tính toán quantity dựa trên strategy
            switch ($strategy) {
                case 'sum_quantity':
                    // Cộng tất cả số lượng duplicate
                    $product['final_quantity'] = $product['quantity'] ?? 1;
                    break;
                    
                case 'average_confidence':
                    // Lấy average confidence từ tất cả duplicates
                    $confidences = [$product['confidence'] ?? 0];
                    foreach ($product['duplicate_images'] ?? [] as $dup) {
                        $confidences[] = $dup['confidence'];
                    }
                    $product['average_confidence'] = array_sum($confidences) / count($confidences);
                    break;
                    
                case 'best_confidence':
                    // Lấy confidence cao nhất
                    $bestConfidence = $product['confidence'] ?? 0;
                    foreach ($product['duplicate_images'] ?? [] as $dup) {
                        if ($dup['confidence'] > $bestConfidence) {
                            $bestConfidence = $dup['confidence'];
                        }
                    }
                    $product['best_confidence'] = $bestConfidence;
                    break;
            }
            
            $merged[] = $product;
        }
        
        return $merged;
    }
}
