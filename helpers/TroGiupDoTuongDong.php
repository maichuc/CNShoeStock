<?php
/**
 * Similarity Calculation Helper
 * Tập hợp các hàm tính toán độ tương đồng và phát hiện trùng lặp
 * Được sử dụng bởi: them_san_pham_ai.php, tao_phieu_nhap_moi.php
 */

// Hàm normalize text để so sánh tốt hơn
if (!function_exists('normalizeText')) {
    function normalizeText($text) {
        if (is_array($text)) {
            $text = implode(' ', $text);
        }
        
        $text = strtolower(trim((string)$text));
        
        // Loại bỏ dấu câu
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
        
        // Normalize spaces
        $text = preg_replace('/\s+/', ' ', $text);
        
        // Enhanced synonyms dictionary for Vietnamese shoe terms
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
        
        foreach ($synonyms as $normalized => $variants) {
            $pattern = '/\b(' . $variants . ')\b/u';
            $text = preg_replace($pattern, str_replace(' ', '', $normalized), $text);
        }
        
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

// Hàm tính toán độ tương đồng văn bản với semantic matching
if (!function_exists('calculateTextSimilarity')) {
    function calculateTextSimilarity($text1, $text2) {
        if (is_array($text1)) {
            $text1 = implode(' ', $text1);
        }
        if (is_array($text2)) {
            $text2 = implode(' ', $text2);
        }
        
        $text1 = trim((string)$text1);
        $text2 = trim((string)$text2);
        
        if (empty($text1) || empty($text2)) {
            return 0;
        }
        
        $normalized1 = normalizeText($text1);
        $normalized2 = normalizeText($text2);
        
        if ($normalized1 === $normalized2) {
            return 100;
        }
        
        $keywords1 = extractKeywords($text1);
        $keywords2 = extractKeywords($text2);
        
        if (empty($keywords1) || empty($keywords2)) {
            return 0;
        }
        
        $intersection = array_intersect($keywords1, $keywords2);
        $union = array_unique(array_merge($keywords1, $keywords2));
        
        $keywordSimilarity = count($union) > 0 ? (count($intersection) / count($union)) * 100 : 0;
        
        $maxLen = max(strlen($normalized1), strlen($normalized2));
        if ($maxLen > 0 && $maxLen <= 255) {
            $distance = levenshtein($normalized1, $normalized2);
            $levenshteinSimilarity = (1 - ($distance / $maxLen)) * 100;
        } else {
            $levenshteinSimilarity = 0;
        }
        
        $substringBonus = 0;
        if (strpos($normalized1, $normalized2) !== false || strpos($normalized2, $normalized1) !== false) {
            $substringBonus = 20;
        }
        
        $finalScore = ($keywordSimilarity * 0.6) + ($levenshteinSimilarity * 0.3) + ($substringBonus * 0.1);
        
        error_log("Text similarity: '$text1' vs '$text2' = {$finalScore}% (keyword: {$keywordSimilarity}%, levenshtein: {$levenshteinSimilarity}%, substring: {$substringBonus})");
        
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
        
        // Count matched colors from input
        $matchedInputColors = 0;
        foreach ($inputColors as $inputColor) {
            foreach ($dbColorList as $dbColor) {
                if ($inputColor === $dbColor) {
                    $matchedInputColors++;
                    break;
                }
            }
        }
        
        // Count matched colors from database
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
        
        // Calculate match percentage
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
        
        // Remove Vietnamese accents
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
        
        // Check contains
        foreach ($colorMap as $key => $standardColor) {
            if (strpos($color, $key) !== false) {
                return $standardColor;
            }
        }
        
        return $color;
    }
}

?>
