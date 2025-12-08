<?php
/**
 * Similarity Calculation Helper
 * Tập hợp các hàm tính toán độ tương đồng và phát hiện trùng lặp
 * Được sử dụng bởi: them_san_pham_ai.php, tao_phieu_nhap_moi.php
 */

/**
 * HÀM CHUẨN HÓA VĂN BẢN
 * 
 * Mục đích: Chuẩn hóa văn bản trước khi so sánh độ tương đồng
 * 
 * Các bước xử lý:
 * 1. Chuyển về chữ thường
 * 2. Loại bỏ dấu câu
 * 3. Chuẩn hóa khoảng trắng
 * 4. Thay thế các từ đồng nghĩa
 * 
 * Ví dụ:
 * Input:  "Giày Thể Thao Nike!!!   Sneaker"
 * Output: "giay thethao nike giay thethao"
 * 
 * @param string|array $text - Văn bản cần chuẩn hóa
 * @return string - Văn bản đã chuẩn hóa
 */
if (!function_exists('normalizeText')) {
    function normalizeText($text) {
        // BƯỚC 1: Nếu là array, ghép thành string
        if (is_array($text)) {
            $text = implode(' ', $text);
        }
        
        // BƯỚC 2: Chuyển về chữ thường và loại khoảng trắng thừa
        $text = strtolower(trim((string)$text));
        
        // BƯỚC 3: Loại bỏ dấu câu và ký tự đặc biệt
        // Giữ lại: chữ cái (\p{L}), số (\p{N}), khoảng trắng (\s)
        // Thay thế tất cả các ký tự khác bằng khoảng trắng
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
        
        // BƯỚC 4: Chuẩn hóa nhiều khoảng trắng thành 1 khoảng trắng
        // Ví dụ: "giày    thể   thao" -> "giày thể thao"
        $text = preg_replace('/\s+/', ' ', $text);
        
        /**
         * BƯỚC 5: TỪ ĐIỂN ĐỒNG NGHĨA
         * 
         * Mục đích: Thống nhất các từ có nghĩa tương tự
         * 
         * Ví dụ:
         * - "sneaker", "shoe", "boot" -> Tất cả đều chuyển thành "giay"
         * - "cao gót", "high heel", "gót cao" -> "đều thành "caogot"
         * - "thể thao", "sport", "running" -> Tất cả thành "thethao"
         * 
         * Lợi ích: Tăng độ chính xác khi so sánh
         * Ví dụ: "Giày thể thao" và "Sneaker" sẽ được coi là giống nhau
         */
        $synonyms = [
            'giay' => 'giay|sneaker|shoe|boot|sandal|dep',
            'sandal' => 'sandal|dep|giay mo|open toe|giay sandal',
            'cao got' => 'cao got|high heel|got cao|got nhon|heel|caogot',
            'the thao' => 'the thao|sneaker|sport|running|tennis|thethao',
            'dep' => 'dep|sandal|slipper|flip flop|giay mo',
            'dinh da' => 'dinh da|da lap lanh|da trang tri|pha le|sequin|dat da|kim cuong|crystal|dinh kim cuong',
            'lap lanh' => 'lap lanh|lung linh|long lanh|bong|kim tuyen|long lay|lấp lánh',
            'lua' => 'lua|satin|silk|vai lua|lụa',
            'da' => 'da|leather|da that|genuine leather|da tong hop',
            'trang' => 'trang|white|mau trang|classic white|trắng',
            'do' => 'do|red|mau do|cherry|đỏ',
            'den' => 'den|black|mau den|đen',
            'quai' => 'day|quai|day deo|quai deo|dai|strap',
            'khoa keo' => 'khoa keo|zip|zipper|day keo|khóa kéo',
            'sau got' => 'sau got|phia sau|got chan|got giay|sau gót',
            'mui giay' => 'mui giay|dau giay|phan mui',
            'lot giay' => 'lot giay|insole|long giay|dem lot',
            'day deo' => 'day deo|quai deo|day buoc|quai',
            'nike' => 'nike|swoosh',
            'air force' => 'air force|airforce|air force 1|af1',
            'nu' => 'nu|women|lady|nữ|ladies',
            'nam' => 'nam|men|male|gentleman',
            'thuong hieu' => 'thuong hieu|logo|nhan hieu|brand|hang',
            'chat lieu' => 'chat lieu|material|vat lieu|lieu',
            'anh chup' => 'anh chup|hinh anh|anh|hinh|buc anh',
            'tu tren xuong' => 'tu tren xuong|goc tren|goc nhin tren|top view|bird view',
            'tap trung' => 'tap trung|focus|noi bat|the hien ro|ro rang|ro ret',
            'dac diem' => 'dac diem|features|tinh nang|chi tiet|diem noi bat',
            'cau truc' => 'cau truc|structure|ket cau|thiet ke|kieu dang',
            'phuc tap' => 'phuc tap|complex|cau ky|tinh te|chi tiet',
            'ro rang' => 'ro rang|ro ret|clear|evidently|de thay',
            'in' => 'in|khac|logo in|chu in|duoc in',
        ];
        
        // Duyệt qua từng cặp đồng nghĩa và thay thế
        foreach ($synonyms as $normalized => $variants) {
            // Tạo pattern regex để tìm các từ đồng nghĩa
            // \b = word boundary (đảm bảo khớp đúng từ, không khớp 1 phần)
            // Ví dụ: Pattern cho 'giay': /\b(giay|sneaker|shoe|boot|sandal|dep)\b/
            $pattern = '/\b(' . $variants . ')\b/u';
            
            // Thay thế tất cả các biến thể bằng từ chuẩn (bỏ khoảng trắng)
            // Ví dụ: "sneaker" -> "giay", "high heel" -> "caogot"
            $text = preg_replace($pattern, str_replace(' ', '', $normalized), $text);
        }
        
        // Trả về văn bản đã chuẩn hóa
        return trim($text);
    }
}

// Hàm extract keywords từ text
if (!function_exists('extractKeywords')) {
    function extractKeywords($text) {
        $normalized = normalizeText($text);
        $words = explode(' ', $normalized);
        
        $stopWords = ['cua', 'va', 'co', 'duoc', 'la', 'o', 'voi', 'cho', 'den', 'tren', 'duoi', 'trong', 'ngoai', 'giua', 'khi', 'ma', 'nhung', 'vi', 'neu', 'thi', 'se', 'da', 'dang', 'rat', 'qua', 'lam', 'nay', 'do', 'ay', 'nao', 'sao', 'bao', 'the', 'nhu'];
        
        $keywords = [];
        foreach ($words as $word) {
            $word = trim($word);
            if (strlen($word) >= 2 && !in_array($word, $stopWords)) {
                $keywords[] = $word;
            }
        }
        
        return array_unique($keywords);
    }
}

/**
 * HÀM TÍNH ĐỘ TƯƠNG ĐỒNG VĂN BẢN
 * 
 * Mục đích: Tính độ giống nhau giữa 2 đoạn văn bản (0-100%)
 * 
 * Thuật toán kết hợp 3 phương pháp:
 * 1. Keyword Matching (60% trọng số) - So sánh từ khóa chung
 * 2. Levenshtein Distance (30%) - Tính khoảng cách chỉnh sửa
 * 3. Substring Matching (10%) - Kiểm tra chuỗi con
 * 
 * Ví dụ:
 * "Giày thể thao Nike Air Max" vs "Nike Air Max Sneaker"
 * -> Similarity: ~85% (cùng brand, cùng loại, có từ khóa chung)
 * 
 * @param string $text1 - Văn bản 1
 * @param string $text2 - Văn bản 2
 * @return float - Độ tương đồng (0-100)
 */
if (!function_exists('calculateTextSimilarity')) {
    function calculateTextSimilarity($text1, $text2) {
        // Xử lý nếu input là array
        if (is_array($text1)) {
            $text1 = implode(' ', $text1);
        }
        if (is_array($text2)) {
            $text2 = implode(' ', $text2);
        }
        
        // Chuyển sang string và loại bỏ khoảng trắng thừa
        $text1 = trim((string)$text1);
        $text2 = trim((string)$text2);
        
        // Nếu 1 trong 2 rỗng -> Không giống (0%)
        if (empty($text1) || empty($text2)) {
            return 0;
        }
        
        // BƯỚC 1: Chuẩn hóa văn bản
        $normalized1 = normalizeText($text1);
        $normalized2 = normalizeText($text2);
        
        // Nếu sau khi chuẩn hóa giống y hệt -> 100%
        if ($normalized1 === $normalized2) {
            return 100;
        }
        
        // BƯỚC 2: Trích xuất từ khóa
        $keywords1 = extractKeywords($text1);
        $keywords2 = extractKeywords($text2);
        
        // Nếu không có từ khóa nào -> Không tính được (0%)
        if (empty($keywords1) || empty($keywords2)) {
            return 0;
        }
        
        /**
         * BƯỚC 3: TÍNH KEYWORD SIMILARITY (Jaccard Index)
         * 
         * Công thức: (Số từ khóa chung) / (Tổng số từ khóa unique)
         * 
         * Ví dụ:
         * Text1 keywords: [giay, nike, air, max]
         * Text2 keywords: [nike, air, max, sneaker]
         * 
         * Intersection (chung): [nike, air, max] = 3 từ
         * Union (tổng unique): [giay, nike, air, max, sneaker] = 5 từ
         * Similarity = 3/5 = 60%
         */
        $intersection = array_intersect($keywords1, $keywords2);  // Từ khóa chung
        $union = array_unique(array_merge($keywords1, $keywords2));  // Tất cả từ khóa unique
        
        // Tính % tương đồng dựa trên từ khóa
        $keywordSimilarity = count($union) > 0 ? (count($intersection) / count($union)) * 100 : 0;
        
        /**
         * BƯỚC 4: TÍNH LEVENSHTEIN DISTANCE
         * 
         * Thuật toán: Đếm số thao tác tối thiểu để biến text1 thành text2
         * Thao tác: Thêm, xóa, thay thế 1 ký tự
         * 
         * Giới hạn: Chỉ áp dụng với text <= 255 ký tự (giới hạn của hàm levenshtein)
         * 
         * Ví dụ:
         * "kitten" -> "sitting"
         * Thao tác: k->s, e->i, thêm g = 3 thao tác
         * Distance = 3
         * Similarity = (1 - 3/7) * 100 = 57.14%
         */
        $maxLen = max(strlen($normalized1), strlen($normalized2));
        
        if ($maxLen > 0 && $maxLen <= 255) {
            $distance = levenshtein($normalized1, $normalized2);  // Số thao tác cần thiết
            $levenshteinSimilarity = (1 - ($distance / $maxLen)) * 100;  // Chuyển thành %
        } else {
            // Text quá dài -> Bỏ qua Levenshtein
            $levenshteinSimilarity = 0;
        }
        
        /**
         * BƯỚC 5: SUBSTRING MATCHING BONUS
         * 
         * Kiểm tra xem text này có phải là chuỗi con của text kia không
         * Nếu có -> Tặng thêm 20 điểm bonus
         * 
         * Ví dụ:
         * "Nike Air" là substring của "Nike Air Max" -> +20 điểm
         */
        $substringBonus = 0;
        if (strpos($normalized1, $normalized2) !== false || strpos($normalized2, $normalized1) !== false) {
            $substringBonus = 20;
        }
        
        /**
         * BƯỚC 6: TÍNH ĐIỂM CUỐI CÙNG (Weighted Average)
         * 
         * Công thức: 
         * Final = (Keyword × 60%) + (Levenshtein × 30%) + (Substring × 10%)
         * 
         * Giải thích trọng số:
         * - Keyword: 60% (quan trọng nhất - nội dung ngữ nghĩa)
         * - Levenshtein: 30% (cấu trúc văn bản)
         * - Substring: 10% (bonus nếu text1 chứa text2)
         */
        $finalScore = ($keywordSimilarity * 0.6) + ($levenshteinSimilarity * 0.3) + ($substringBonus * 0.1);
        
        // Ghi log để debug
        error_log("Text similarity: '$text1' vs '$text2' = {$finalScore}% (keyword: {$keywordSimilarity}%, levenshtein: {$levenshteinSimilarity}%, substring: {$substringBonus})");
        
        // Đảm bảo kết quả trong khoảng 0-100
        return max(0, min(100, $finalScore));
    }
}

// Extract specific product features từ description
if (!function_exists('extractProductFeatures')) {
    function extractProductFeatures($text) {
        $text = strtolower($text);
        $features = [
            'logo' => [],
            'materials' => [],
            'colors' => [],
            'closures' => [],
            'decorations' => []
        ];
        
        if (preg_match_all('/\b([A-Z][A-Z]+|[A-Z][a-z]+)\b/', $text, $matches)) {
            $features['logo'] = array_unique($matches[1]);
        }
        
        $materialKeywords = ['satin', 'da', 'vai', 'lua', 'synthetic', 'leather', 'canvas', 'suede', 'mesh'];
        foreach ($materialKeywords as $material) {
            if (strpos($text, $material) !== false) {
                $features['materials'][] = $material;
            }
        }
        
        $colorKeywords = ['do', 'red', 'xanh', 'blue', 'den', 'black', 'trang', 'white', 'vang', 'gold', 'bac', 'silver', 'hong', 'pink', 'nau', 'brown'];
        foreach ($colorKeywords as $color) {
            if (strpos($text, $color) !== false) {
                $features['colors'][] = $color;
            }
        }
        
        $closureKeywords = ['khoa keo', 'zip', 'zipper', 'nut bam', 'velcro', 'day buoc', 'lace'];
        foreach ($closureKeywords as $closure) {
            if (strpos($text, $closure) !== false) {
                $features['closures'][] = $closure;
            }
        }
        
        $decorationKeywords = ['dinh da', 'sequin', 'pha le', 'studs', 'embroidery', 'theu', 'print', 'in'];
        foreach ($decorationKeywords as $decoration) {
            if (strpos($text, $decoration) !== false) {
                $features['decorations'][] = $decoration;
            }
        }
        
        return $features;
    }
}

// Hàm đặc biệt để so sánh description/features
if (!function_exists('calculateDescriptionSimilarity')) {
    function calculateDescriptionSimilarity($desc1, $desc2) {
        if (empty($desc1) || empty($desc2)) {
            return 0;
        }
        
        if (is_array($desc1)) $desc1 = implode(' ', $desc1);
        if (is_array($desc2)) $desc2 = implode(' ', $desc2);
        
        $keywords1 = extractKeywords($desc1);
        $keywords2 = extractKeywords($desc2);
        
        if (empty($keywords1) || empty($keywords2)) {
            return 0;
        }
        
        $intersection = array_intersect($keywords1, $keywords2);
        $union = array_unique(array_merge($keywords1, $keywords2));
        
        $jaccardScore = count($union) > 0 ? (count($intersection) / count($union)) * 100 : 0;
        
        $features1 = extractProductFeatures($desc1);
        $features2 = extractProductFeatures($desc2);
        
        $featureMatches = 0;
        $totalFeatures = 0;
        
        foreach ($features1 as $type => $values1) {
            if (!empty($values1) && !empty($features2[$type])) {
                $totalFeatures++;
                $values2 = $features2[$type];
                foreach ($values1 as $val1) {
                    foreach ($values2 as $val2) {
                        $similarity = calculateTextSimilarity($val1, $val2);
                        if ($similarity >= 60) {
                            $featureMatches++;
                            break 2;
                        }
                    }
                }
            }
        }
        
        $featureScore = $totalFeatures > 0 ? ($featureMatches / $totalFeatures) * 100 : 0;
        
        $finalScore = ($jaccardScore * 0.6) + ($featureScore * 0.4);
        
        error_log("Description comparison: jaccard={$jaccardScore}%, features={$featureScore}%, final={$finalScore}%");
        error_log("  Keywords1: " . json_encode($keywords1));
        error_log("  Keywords2: " . json_encode($keywords2));
        error_log("  Common keywords: " . json_encode($intersection));
        
        return $finalScore;
    }
}

// Hàm tính toán độ tương đồng cơ bản giữa sản phẩm mới và sản phẩm trong DB
if (!function_exists('calculateSimilarity')) {
    function calculateSimilarity($aiData, $dbProduct) {
        $score = 0;
        $weights = [
            'name' => 30,
            'brand' => 25,
            'type' => 25,
            'description' => 10,
            'material' => 5,
            'colors' => 5
        ];
        
        $actualScore = 0;
        $actualMaxScore = 0;
        
        if (!empty($aiData['name']) && !empty($dbProduct['name'])) {
            $nameSimilarity = calculateTextSimilarity($aiData['name'], $dbProduct['name']);
            $actualScore += $nameSimilarity * $weights['name'] / 100;
            $actualMaxScore += $weights['name'];
            error_log("Name similarity: {$nameSimilarity}% ('{$aiData['name']}' vs '{$dbProduct['name']}')");
        }
        
        if (!empty($aiData['brand']) && !empty($dbProduct['brand'])) {
            $brandSimilarity = calculateTextSimilarity($aiData['brand'], $dbProduct['brand']);
            $actualScore += $brandSimilarity * $weights['brand'] / 100;
            $actualMaxScore += $weights['brand'];
            error_log("Brand similarity: {$brandSimilarity}% ('{$aiData['brand']}' vs '{$dbProduct['brand']}')");
        }
        
        if (!empty($aiData['type']) && !empty($dbProduct['type'])) {
            $typeSimilarity = calculateTextSimilarity($aiData['type'], $dbProduct['type']);
            $actualScore += $typeSimilarity * $weights['type'] / 100;
            $actualMaxScore += $weights['type'];
            error_log("Type similarity: {$typeSimilarity}% ('{$aiData['type']}' vs '{$dbProduct['type']}')");
        }
        
        if (!empty($aiData['description']) && !empty($dbProduct['description'])) {
            $descriptionSimilarity = calculateTextSimilarity($aiData['description'], $dbProduct['description']);
            $actualScore += $descriptionSimilarity * $weights['description'] / 100;
            $actualMaxScore += $weights['description'];
            error_log("Description similarity: {$descriptionSimilarity}% (AI: '" . substr($aiData['description'], 0, 30) . "...' vs DB: '" . substr($dbProduct['description'], 0, 30) . "...')");
        }
        
        if (!empty($aiData['material']) && !empty($dbProduct['material'])) {
            $materialSimilarity = calculateTextSimilarity($aiData['material'], $dbProduct['material']);
            $actualScore += $materialSimilarity * $weights['material'] / 100;
            $actualMaxScore += $weights['material'];
            error_log("Material similarity: {$materialSimilarity}% ('{$aiData['material']}' vs '{$dbProduct['material']}')");
        }
        
        if (!empty($aiData['colors']) && !empty($dbProduct['color'])) {
            $colorSimilarity = 0;
            $aiColors = is_array($aiData['colors']) ? $aiData['colors'] : [$aiData['colors']];
            foreach ($aiColors as $aiColor) {
                $tempSimilarity = calculateTextSimilarity($aiColor, $dbProduct['color']);
                $colorSimilarity = max($colorSimilarity, $tempSimilarity);
            }
            $actualScore += $colorSimilarity * $weights['colors'] / 100;
            $actualMaxScore += $weights['colors'];
            error_log("Color similarity: {$colorSimilarity}% ('" . implode(', ', $aiColors) . "' vs '{$dbProduct['color']}')");
        }
        
        $finalScore = 0;
        if ($actualMaxScore > 0) {
            $finalScore = ($actualScore / $actualMaxScore) * 100;
            
            $dataCompleteness = $actualMaxScore / 100;
            if ($dataCompleteness < 0.6) {
                $finalScore *= (0.8 + $dataCompleteness * 0.2);
            }
        }
        
        error_log("Final similarity for '{$dbProduct['name']}': {$finalScore}% (actual score: {$actualScore}, max possible: {$actualMaxScore}, completeness: " . ($actualMaxScore/100) . ")");
        
        return min(100, max(0, $finalScore));
    }
}

// Hàm tính toán độ tương đồng nâng cao với thông tin từ nhiều ảnh
if (!function_exists('calculateEnhancedSimilarity')) {
    function calculateEnhancedSimilarity($aiData, $dbProduct) {
        $score = 0;
        $maxScore = 0;
        
        error_log("AI Data structure for similarity check: " . json_encode($aiData));
        error_log("DB Product structure: " . json_encode($dbProduct));
        
        // UNIFIED FORMULA: Brand 30%, Name 25%, Color 25%, Type 20%
        
        // Brand comparison (30% weight) - Most important
        $maxScore += 30;
        if (!empty($aiData['brand']) && !empty($dbProduct['brand'])) {
            $brandSimilarity = calculateTextSimilarity($aiData['brand'], $dbProduct['brand']);
            $score += $brandSimilarity * 30 / 100;
            $aiBrand = is_array($aiData['brand']) ? json_encode($aiData['brand']) : $aiData['brand'];
            error_log("Brand similarity: " . $brandSimilarity . "% ('$aiBrand' vs '{$dbProduct['brand']}')");
        }
        
        // Name comparison (25% weight)
        $maxScore += 25;
        if (!empty($aiData['name']) && !empty($dbProduct['name'])) {
            $nameSimilarity = calculateTextSimilarity($aiData['name'], $dbProduct['name']);
            $score += $nameSimilarity * 25 / 100;
            $aiName = is_array($aiData['name']) ? json_encode($aiData['name']) : $aiData['name'];
            error_log("Name similarity: " . $nameSimilarity . "% ('$aiName' vs '{$dbProduct['name']}')");
        }
        
        // Color comparison (25% weight) - NEW: Increased from 15% for consistency
        $maxScore += 25;
        if (!empty($aiData['colors']) && !empty($dbProduct['color'])) {
            $aiColors = is_array($aiData['colors']) ? implode(',', $aiData['colors']) : $aiData['colors'];
            
            // Use enhanced color similarity if function exists
            if (function_exists('calculateColorSimilarityEnhanced')) {
                $colorSimilarity = calculateColorSimilarityEnhanced($aiColors, $dbProduct['color']);
            } else {
                // Fallback to simple text similarity
                $colorSimilarity = 0;
                $aiColorArray = is_array($aiData['colors']) ? $aiData['colors'] : [$aiData['colors']];
                foreach ($aiColorArray as $aiColor) {
                    $tempSimilarity = calculateTextSimilarity($aiColor, $dbProduct['color']);
                    $colorSimilarity = max($colorSimilarity, $tempSimilarity);
                }
            }
            $score += $colorSimilarity * 25 / 100;
            error_log("Color similarity: " . $colorSimilarity . "% ('" . (is_array($aiData['colors']) ? json_encode($aiData['colors']) : $aiData['colors']) . "' vs '{$dbProduct['color']}')");
        }
        
        // Type comparison (20% weight)
        $maxScore += 20;
        if (!empty($aiData['type']) && !empty($dbProduct['type'])) {
            $typeSimilarity = calculateTextSimilarity($aiData['type'], $dbProduct['type']);
            $score += $typeSimilarity * 20 / 100;
            $aiType = is_array($aiData['type']) ? json_encode($aiData['type']) : $aiData['type'];
            error_log("Type similarity: " . $typeSimilarity . "% ('$aiType' vs '{$dbProduct['type']}')");
        }
        
        $finalScore = $maxScore > 0 ? ($score / $maxScore) * 100 : 0;
        error_log("Enhanced similarity final score: " . round($finalScore, 2) . "% (unified formula: Brand 30%, Name 25%, Color 25%, Type 20%)");
        
        return min(100, $finalScore);
    }
}


/**
 * Calculate color similarity using unified logic
 * Supports multi-color comparison with normalization
 */
if (!function_exists('calculateColorSimilarityEnhanced')) {
    function calculateColorSimilarityEnhanced($inputColor, $dbColors) {
        if (empty($inputColor) || empty($dbColors)) {
            return 0;
        }
        
        // Normalize and split colors
        $inputColors = array_map('trim', explode(',', $inputColor));
        $inputColors = array_map('normalizeColorEnhanced', $inputColors);
        $inputColors = array_filter($inputColors);
        
        $dbColorList = array_map('trim', explode(',', $dbColors));
        $dbColorList = array_map('normalizeColorEnhanced', $dbColorList);
        $dbColorList = array_filter($dbColorList);
        
        if (empty($inputColors) || empty($dbColorList)) {
            return 0;
        }
        
        // Đếm matched colors from input
        $matchedInputColors = 0;
        foreach ($inputColors as $inputColor) {
            foreach ($dbColorList as $dbColor) {
                if ($inputColor === $dbColor) {
                    $matchedInputColors++;
                    break;
                }
            }
        }
        
        // Đếm matched colors từ database
        $matchedDbColors = 0;
        foreach ($dbColorList as $dbColor) {
            foreach ($inputColors as $inputColor) {
                if ($inputColor === $dbColor) {
                    $matchedDbColors++;
                    break;
                }
            }
        }
        
        $totalInputColors = count($inputColors);
        $totalDbColors = count($dbColorList);
        
        // Tính toán match percentage
        $inputMatchPercentage = ($matchedInputColors / $totalInputColors) * 100;
        $dbMatchPercentage = ($matchedDbColors / $totalDbColors) * 100;
        $avgMatchPercentage = ($inputMatchPercentage + $dbMatchPercentage) / 2;
        
        // Scoring based on match percentage
        if ($matchedInputColors === $totalInputColors && $matchedDbColors === $totalDbColors) {
            return 100; // Perfect match
        } elseif ($avgMatchPercentage >= 75) {
            return min(95, $avgMatchPercentage); // Good match
        } elseif ($avgMatchPercentage >= 50) {
            return $avgMatchPercentage * 0.8; // Medium match (penalty -20%)
        } else {
            return $avgMatchPercentage * 0.5; // Low match (penalty -50%)
        }
    }
}

/**
 * Normalize color name (Vietnamese & English)
 */
if (!function_exists('normalizeColorEnhanced')) {
    function normalizeColorEnhanced($color) {
        $color = strtolower(trim($color));
        
        // Xóa Vietnamese accents
        $color = preg_replace('/[àáạảãâầấậẩẫăằắặẳẵ]/u', 'a', $color);
        $color = preg_replace('/[èéẹẻẽêềếệểễ]/u', 'e', $color);
        $color = preg_replace('/[ìíịỉĩ]/u', 'i', $color);
        $color = preg_replace('/[òóọỏõôồốộổỗơờớợởỡ]/u', 'o', $color);
        $color = preg_replace('/[ùúụủũưừứựửữ]/u', 'u', $color);
        $color = preg_replace('/[ỳýỵỷỹ]/u', 'y', $color);
        $color = preg_replace('/đ/u', 'd', $color);
        
        // Color mapping (Vietnamese - English)
        $colorMap = [
            'den' => 'black', 'den nham' => 'black', 'mau den' => 'black', 'black' => 'black',
            'trang' => 'white', 'mau trang' => 'white', 'white' => 'white', 'ivory' => 'white', 'off white' => 'white',
            'xam' => 'grey', 'grey' => 'grey', 'gray' => 'grey', 'mau xam' => 'grey',
            'xanh la' => 'green', 'xanh la cay' => 'green', 'green' => 'green', 'mau xanh la' => 'green',
            'xanh duong' => 'blue', 'xanh da troi' => 'blue', 'blue' => 'blue', 'navy' => 'blue', 'mau xanh duong' => 'blue',
            'do' => 'red', 'mau do' => 'red', 'red' => 'red',
            'vang' => 'yellow', 'mau vang' => 'yellow', 'yellow' => 'yellow', 'gold' => 'yellow',
            'nau' => 'brown', 'mau nau' => 'brown', 'brown' => 'brown',
            'hong' => 'pink', 'mau hong' => 'pink', 'pink' => 'pink',
            'cam' => 'orange', 'mau cam' => 'orange', 'orange' => 'orange',
            'tim' => 'purple', 'mau tim' => 'purple', 'purple' => 'purple', 'violet' => 'purple',
        ];
        
        if (isset($colorMap[$color])) {
            return $colorMap[$color];
        }
        
        // Kiểm tra contains
        foreach ($colorMap as $key => $standardColor) {
            if (strpos($color, $key) !== false) {
                return $standardColor;
            }
        }
        
        return $color;
    }
}

/**
 * ============================================================================
 * CENTRALIZED NORMALIZATION FUNCTIONS
 * Tập trung hóa tất cả logic chuẩn hóa để tránh code trùng lặp
 * ============================================================================
 */

/**
 * Chuẩn hóa thương hiệu - Version tổng hợp
 * Gộp logic từ: api_phan_tich_giay_ai.php, GhepSanPhamThongMinh.php
 */
if (!function_exists('standardizeBrand')) {
    function standardizeBrand($brand) {
        // Danh sách unknown brands
        $unknownBrands = [
            'fashion', 'không xác định', 'khong xac dinh', 'chưa xác định', 
            'chua xac dinh', 'không rõ', 'khong ro', 'chưa rõ', 'chua ro',
            'không thương hiệu', 'khong thuong hieu', 'không có thương hiệu', 
            'khong co thuong hieu', 'chưa có thương hiệu', 'chua co thuong hieu',
            'không nhãn hiệu', 'khong nhan hieu', 'không có nhãn hiệu', 
            'khong co nhan hieu', 'không nhãn', 'khong nhan', 'chưa có nhãn', 
            'chua co nhan', 'không brand', 'khong brand', 'không có brand', 
            'khong co brand', 'no brand', 'nobrand', 'no-brand', 'unbranded', 
            'non-branded', 'non branded', 'undefined', 'none', 'null', 'n/a', 
            'na', 'n.a', 'n.a.', '-', '--', '---', '_', '__', '___', '?', '??', '???',
        ];
        
        if (empty($brand) || !trim($brand)) {
            return 'Unknown';
        }
        
        $brandLower = mb_strtolower(trim($brand), 'UTF-8');
        
        if (in_array($brandLower, $unknownBrands)) {
            return 'Unknown';
        }
        
        // Mapping các variant của brand phổ biến
        $brandMap = [
            'Nike' => ['nike', 'nike shoes', 'nike sportswear'],
            'Adidas' => ['adidas', 'adidas originals', 'adidas performance'],
            'Puma' => ['puma', 'puma shoes'],
            'Converse' => ['converse', 'converse all star'],
            'Vans' => ['vans', 'vans shoes'],
            'New Balance' => ['new balance', 'nb', 'newbalance'],
            'Reebok' => ['reebok'],
            'Asics' => ['asics'],
            'Under Armour' => ['under armour'],
            'MLB' => ['mlb'],
            'Charles & Keith' => ['charles & keith', 'charles keith'],
            'Jeremy' => ['jeremy'],
            'Gucci' => ['gucci'],
            'Balenciaga' => ['balenciaga'],
            'Versace' => ['versace'],
            'Lecos' => ['lecos'],
            'Boston' => ['boston']
        ];
        
        foreach ($brandMap as $standard => $variants) {
            if (in_array($brandLower, $variants)) {
                return $standard;
            }
        }
        
        return ucfirst(trim($brand));
    }
}

/**
 * Chuẩn hóa màu sắc - Version tổng hợp
 * Gộp logic từ nhiều file
 */
if (!function_exists('standardizeColor')) {
    function standardizeColor($color) {
        if (empty($color) || !trim($color)) {
            return '';
        }
        
        $colorMap = [
            'black' => 'Đen', 'den' => 'Đen',
            'white' => 'Trắng', 'trang' => 'Trắng', 'off white' => 'Trắng ngà',
            'red' => 'Đỏ', 'do' => 'Đỏ',
            'blue' => 'Xanh dương', 'xanh duong' => 'Xanh dương',
            'green' => 'Xanh lá', 'xanh la' => 'Xanh lá',
            'yellow' => 'Vàng', 'vang' => 'Vàng',
            'orange' => 'Cam', 'cam' => 'Cam',
            'purple' => 'Tím', 'tim' => 'Tím',
            'pink' => 'Hồng', 'hong' => 'Hồng',
            'brown' => 'Nâu', 'nau' => 'Nâu',
            'gray' => 'Xám', 'grey' => 'Xám', 'xam' => 'Xám',
            'beige' => 'Be',
            'navy' => 'Xanh navy', 'navy blue' => 'Xanh navy',
            'burgundy' => 'Đỏ burgundy',
            'gold' => 'Vàng kim', 'vang kim' => 'Vàng kim',
            'silver' => 'Bạc', 'bac' => 'Bạc',
            'bronze' => 'Đồng', 'dong' => 'Vàng kim',
            'rose gold' => 'Vàng hồng',
            'light blue' => 'Xanh dương nhạt',
            'dark blue' => 'Xanh dương đậm',
            'mint' => 'Xanh bạc hà',
            'coral' => 'San hô',
            'turquoise' => 'Xanh lam',
        ];
        
        $colorLower = mb_strtolower(trim($color), 'UTF-8');
        
        // Exact match
        if (isset($colorMap[$colorLower])) {
            return $colorMap[$colorLower];
        }
        
        // Multi-word color
        $words = explode(' ', $colorLower);
        if (count($words) > 1) {
            $translatedWords = [];
            foreach ($words as $word) {
                $translatedWords[] = $colorMap[$word] ?? $word;
            }
            $result = implode(' ', $translatedWords);
            return mb_strtoupper(mb_substr($result, 0, 1, 'UTF-8'), 'UTF-8') . 
                   mb_substr($result, 1, null, 'UTF-8');
        }
        
        return ucfirst($color);
    }
}

/**
 * Chuẩn hóa loại sản phẩm
 * Từ api_phan_tich_giay_ai.php
 */
if (!function_exists('standardizeProductType')) {
    function standardizeProductType($type) {
        if (empty($type)) {
            return '';
        }
        
        $typeMapping = [
            'sneaker' => 'Sneaker', 'sneakers' => 'Sneaker',
            'running shoe' => 'Sneaker', 'sport shoe' => 'Sneaker',
            'high heel' => 'Giày cao gót', 'high heels' => 'Giày cao gót',
            'pump' => 'Giày cao gót', 'pumps' => 'Giày cao gót',
            'wedge' => 'Giày đế xuồng', 'wedges' => 'Giày đế xuồng',
            'sandal' => 'Sandal', 'sandals' => 'Sandal',
            'boot' => 'Giày boot', 'boots' => 'Giày boot',
            'oxford' => 'Giày tây', 'dress shoe' => 'Giày tây',
            'loafer' => 'Giày lười', 'slip-on' => 'Giày lười',
            'flat' => 'Giày bệt', 'flats' => 'Giày bệt',
            'mule' => 'Giày mules', 'mules' => 'Giày mules',
        ];
        
        $typeLower = strtolower(trim($type));
        
        return $typeMapping[$typeLower] ?? ucfirst($type);
    }
}

?>
