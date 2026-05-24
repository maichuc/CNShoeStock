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

/**
 * HÀM TRÍCH XUẤT TỪ KHÓA
 * 
 * Mục đích: Lấy các từ khóa quan trọng từ văn bản
 * 
 * Quy trình:
 * 1. Chuẩn hóa văn bản (gọi normalizeText)
 * 2. Tách thành từng từ
 * 3. Loại bỏ stop words (từ không mang nghĩa)
 * 4. Loại bỏ từ quá ngắn (< 2 ký tự)
 * 5. Loại bỏ từ trùng lặp
 * 
 * Ví dụ:
 * Input:  "Giày thể thao Nike của nam"
 * Output: ["giay", "thethao", "nike", "nam"]
 *         (đã loại bỏ "của" - stop word)
 * 
 * @param string $text - Văn bản cần trích xuất từ khóa
 * @return array - Mảng các từ khóa unique
 */
if (!function_exists('extractKeywords')) {
    function extractKeywords($text) {
        // BƯỚC 1: Chuẩn hóa văn bản trước
        $normalized = normalizeText($text);
        
        // BƯỚC 2: Tách thành từng từ
        $words = explode(' ', $normalized);
        
        // BƯỚC 3: Danh sách stop words (từ không mang nghĩa)
        // Các từ này sẽ bị loại bỏ vì không giúp ích cho việc so sánh
        $stopWords = ['cua', 'va', 'co', 'duoc', 'la', 'o', 'voi', 'cho', 'den', 'tren', 'duoi', 'trong', 'ngoai', 'giua', 'khi', 'ma', 'nhung', 'vi', 'neu', 'thi', 'se', 'da', 'dang', 'rat', 'qua', 'lam', 'nay', 'do', 'ay', 'nao', 'sao', 'bao', 'the', 'nhu'];
        
        // BƯỚC 4: Lọc và thu thập từ khóa
        $keywords = [];
        foreach ($words as $word) {
            $word = trim($word);
            // Chỉ giữ từ >= 2 ký tự và không phải stop word
            if (strlen($word) >= 2 && !in_array($word, $stopWords)) {
                $keywords[] = $word;
            }
        }
        
        // BƯỚC 5: Loại bỏ từ trùng lặp
        return array_unique($keywords);
    }
}

/**
 * HÀM CÔNG THỨC TÍNH ĐỘ TƯƠNG ĐỒNG VĂN BẢN
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



/**
 * HÀM TÍNH ĐỘ TƯƠNG ĐỒNG NÂNG CAO , CÔNG THỨC CHÍNH
 * 
 * Mục đích: So sánh sản phẩm với độ chính xác cao hơn (dành cho multi-image analysis)
 * 
 * Công thức thống nhất: UNIFIED FORMULA
 * - Thương hiệu: 30% (quan trọng nhất - phân biệt rõ nhất)
 * - Tên sản phẩm: 25%
 * - Màu sắc: 25% 
 * - Loại sản phẩm: 20%
 * 
 * Điểm khác với calculateSimilarity:
 * - Tập trung vào 4 yếu tố chính (bỏ description, material)
 * - Brand có trọng số cao nhất (30%)
 * - Color được nâng lên 25% (quan trọng với giày)
 * - Hỗ trợ enhanced color similarity (nếu có hàm calculateColorSimilarityEnhanced)
 * 
 * @param array $aiData - Dữ liệu sản phẩm từ AI
 * @param array $dbProduct - Dữ liệu sản phẩm từ database
 * @return float - Độ tương đồng (0-100)
 */
if (!function_exists('calculateEnhancedSimilarity')) {
    function calculateEnhancedSimilarity($aiData, $dbProduct) {
        $score = 0;
        $maxScore = 0;
        
        // Ghi log dữ liệu để debug
        error_log("AI Data structure for similarity check: " . json_encode($aiData));
        error_log("DB Product structure: " . json_encode($dbProduct));
        
        // ===== UNIFIED FORMULA: Brand 30%, Name 25%, Color 25%, Type 20% =====
        
        // ===== SO SÁNH THƯƠNG HIỆU (30%) - QUAN TRỌNG NHẤT =====
        $maxScore += 30;
        if (!empty($aiData['brand']) && !empty($dbProduct['brand'])) {
            $brandSimilarity = calculateTextSimilarity($aiData['brand'], $dbProduct['brand']);
            $score += $brandSimilarity * 30 / 100;
            
            // Chuyển array thành string để log
            $aiBrand = is_array($aiData['brand']) ? json_encode($aiData['brand']) : $aiData['brand'];
            error_log("Brand similarity: " . $brandSimilarity . "% ('$aiBrand' vs '{$dbProduct['brand']}')");
        }
        
        // ===== SO SÁNH TÊN SẢN PHẨM (25%) =====
        $maxScore += 25;
        if (!empty($aiData['name']) && !empty($dbProduct['name'])) {
            $nameSimilarity = calculateTextSimilarity($aiData['name'], $dbProduct['name']);
            $score += $nameSimilarity * 25 / 100;
            
            // Chuyển array thành string để log
            $aiName = is_array($aiData['name']) ? json_encode($aiData['name']) : $aiData['name'];
            error_log("Name similarity: " . $nameSimilarity . "% ('$aiName' vs '{$dbProduct['name']}')");
        }
        
        // ===== SO SÁNH MÀU SẮC (25%) =====
  
        $maxScore += 25;
        if (!empty($aiData['colors']) && !empty($dbProduct['color'])) {
            // Chuyển array colors thành string
            $aiColors = is_array($aiData['colors']) ? implode(',', $aiData['colors']) : $aiData['colors'];
            
            // Ưu tiên dùng hàm so sánh màu nâng cao (nếu có)
            if (function_exists('calculateColorSimilarityEnhanced')) {
                $colorSimilarity = calculateColorSimilarityEnhanced($aiColors, $dbProduct['color']);
            } else {
                // Fallback: Dùng text similarity đơn giản
                $colorSimilarity = 0;
                $aiColorArray = is_array($aiData['colors']) ? $aiData['colors'] : [$aiData['colors']];
                
                // Tìm màu có độ tương đồng cao nhất
                foreach ($aiColorArray as $aiColor) {
                    $tempSimilarity = calculateTextSimilarity($aiColor, $dbProduct['color']);
                    $colorSimilarity = max($colorSimilarity, $tempSimilarity);
                }
            }
            
            $score += $colorSimilarity * 25 / 100;
            error_log("Color similarity: " . $colorSimilarity . "% ('" . (is_array($aiData['colors']) ? json_encode($aiData['colors']) : $aiData['colors']) . "' vs '{$dbProduct['color']}')");
        }
        
        // ===== SO SÁNH LOẠI SẢN PHẨM (20%) =====
        $maxScore += 20;
        if (!empty($aiData['type']) && !empty($dbProduct['type'])) {
            $typeSimilarity = calculateTextSimilarity($aiData['type'], $dbProduct['type']);
            $score += $typeSimilarity * 20 / 100;
            
            // Chuyển array thành string để log
            $aiType = is_array($aiData['type']) ? json_encode($aiData['type']) : $aiData['type'];
            error_log("Type similarity: " . $typeSimilarity . "% ('$aiType' vs '{$dbProduct['type']}')");
        }
        
        // ===== TÍNH ĐIỂM CUỐI CÙNG =====
        $finalScore = $maxScore > 0 ? ($score / $maxScore) * 100 : 0;
        error_log("Enhanced similarity final score: " . round($finalScore, 2) . "% (unified formula: Brand 30%, Name 25%, Color 25%, Type 20%)");
        
        return min(100, $finalScore);
    }
}


/**
 * HÀM TÍNH ĐỘ TƯƠNG ĐỒNG MÀU SẮC NÂNG CAO
 * 
 * Mục đích: So sánh màu sắc với logic thống nhất, hỗ trợ nhiều màu
 * 
 * Quy trình:
 * 1. Chuẩn hóa và tách màu (hỗ trợ multi-color: "Đỏ, Xanh")
 * 2. Đếm số màu khớp từ input
 * 3. Đếm số màu khớp từ database
 * 4. Tính % khớp trung bình
 * 5. Áp dụng điểm số dựa trên % khớp
 * 
 * Thang điểm:
 * - 100%: Perfect match (tất cả màu khớp)
 * - 95%: Khớp >= 75% (Good match)
 * - 80%: Khớp >= 50% (Medium match, penalty -20%)
 * - 50%: Khớp < 50% (Low match, penalty -50%)
 * 
 * Ví dụ:
 * Input: "Đỏ, Xanh"
 * DB: "Đỏ, Xanh, Trắng"
 * -> Input: 2/2 khớp (100%), DB: 2/3 khớp (67%)
 * -> Avg: 83% -> Score: 83% (Good match)
 * 
 * @param string $inputColor - Màu từ AI (có thể nhiều màu, phân cách bởi dấu phẩy)
 * @param string $dbColors - Màu từ database (có thể nhiều màu)
 * @return float - Độ tương đồng (0-100)
 */
if (!function_exists('calculateColorSimilarityEnhanced')) {
    function calculateColorSimilarityEnhanced($inputColor, $dbColors) {
        // Kiểm tra input rỗng
        if (empty($inputColor) || empty($dbColors)) {
            return 0;
        }
        
        // BƯỚC 1: Chuẩn hóa và tách màu
        // Tách input colors theo dấu phẩy
        $inputColors = array_map('trim', explode(',', $inputColor));
        // Chuẩn hóa từng màu (VD: "Đỏ" -> "red", "Xanh dương" -> "blue")
        $inputColors = array_map('normalizeColorEnhanced', $inputColors);
        // Loại bỏ màu rỗng
        $inputColors = array_filter($inputColors);
        
        // Tách DB colors theo dấu phẩy
        $dbColorList = array_map('trim', explode(',', $dbColors));
        // Chuẩn hóa từng màu
        $dbColorList = array_map('normalizeColorEnhanced', $dbColorList);
        // Loại bỏ màu rỗng
        $dbColorList = array_filter($dbColorList);
        
        // Nếu sau khi chuẩn hóa không còn màu nào -> 0%
        if (empty($inputColors) || empty($dbColorList)) {
            return 0;
        }
        
        // BƯỚC 2: Đếm số màu khớp từ input
        // VD: Input có ["red", "blue"], DB có ["red", "blue", "white"]
        // -> matchedInputColors = 2 (cả 2 màu input đều khớp)
        $matchedInputColors = 0;
        foreach ($inputColors as $inputColor) {
            foreach ($dbColorList as $dbColor) {
                if ($inputColor === $dbColor) {
                    $matchedInputColors++;
                    break;  // Thoát vòng lặp DB khi tìm thấy match
                }
            }
        }
        
        // BƯỚC 3: Đếm số màu khớp từ database
        // VD: DB có ["red", "blue", "white"], Input có ["red", "blue"]
        // -> matchedDbColors = 2 (2/3 màu DB có trong input)
        $matchedDbColors = 0;
        foreach ($dbColorList as $dbColor) {
            foreach ($inputColors as $inputColor) {
                if ($inputColor === $dbColor) {
                    $matchedDbColors++;
                    break;  // Thoát vòng lặp input khi tìm thấy match
                }
            }
        }
        
        // BƯỚC 4: Tính % khớp
        $totalInputColors = count($inputColors);
        $totalDbColors = count($dbColorList);
        
        // % màu input khớp với DB
        $inputMatchPercentage = ($matchedInputColors / $totalInputColors) * 100;
        
        // % màu DB khớp với input
        $dbMatchPercentage = ($matchedDbColors / $totalDbColors) * 100;
        
        // Trung bình 2 %
        $avgMatchPercentage = ($inputMatchPercentage + $dbMatchPercentage) / 2;
        
        // BƯỚC 5: Tính điểm dựa trên % khớp
        // Case 1: Perfect match (100%)
        if ($matchedInputColors === $totalInputColors && $matchedDbColors === $totalDbColors) {
            return 100;
        }
        
        // Case 2: Good match (>= 75%)
        elseif ($avgMatchPercentage >= 75) {
            return min(95, $avgMatchPercentage);  // Tối đa 95 điểm
        }
        
        // Case 3: Medium match (50-74%)
        elseif ($avgMatchPercentage >= 50) {
            return $avgMatchPercentage * 0.8;  // Penalty -20%
        }
        
        // Case 4: Low match (< 50%)
        else {
            return $avgMatchPercentage * 0.5;  // Penalty -50%
        }
    }
}

/**
 * HÀM CHUẨN HÓA TÊN MÀU
 * 
 * Mục đích: Chuyển đổi tên màu tiếng Việt/Anh về dạng chuẩn
 * 
 * Quy trình:
 * 1. Chuyển về chữ thường và trim
 * 2. Xóa dấu tiếng Việt (à->a, đ->d...)
 * 3. Map về màu chuẩn tiếng Anh
 * 
 * Ví dụ:
 * "Đỏ" -> "do" -> "red"
 * "Xanh dương" -> "xanh duong" -> "blue"
 * "Black" -> "black"
 * "Trắng kem" -> "trang kem" -> "white"
 * 
 * @param string $color - Tên màu (tiếng Việt hoặc Anh)
 * @return string - Tên màu chuẩn (tiếng Anh lowercase)
 */
if (!function_exists('normalizeColorEnhanced')) {
    function normalizeColorEnhanced($color) {
        // BƯỚC 1: Chuyển về chữ thường và loại bỏ khoảng trắng thừa
        $color = strtolower(trim($color));
        
        // BƯỚC 2: Xóa dấu tiếng Việt
        // à, á, ạ, ả, ã -> a
        $color = preg_replace('/[àáạảãâầấậẩẫăằắặẳẵ]/u', 'a', $color);
        // è, é, ẹ, ẻ, ẽ -> e
        $color = preg_replace('/[èéẹẻẽêềếệểễ]/u', 'e', $color);
        // ì, í, ị, ỉ, ĩ -> i
        $color = preg_replace('/[ìíịỉĩ]/u', 'i', $color);
        // ò, ó, ọ, ỏ, õ -> o
        $color = preg_replace('/[òóọỏõôồốộổỗơờớợởỡ]/u', 'o', $color);
        // ù, ú, ụ, ủ, ũ -> u
        $color = preg_replace('/[ùúụủũưừứựửữ]/u', 'u', $color);
        // ỳ, ý, ỵ, ỷ, ỹ -> y
        $color = preg_replace('/[ỳýỵỷỹ]/u', 'y', $color);
        // đ -> d
        $color = preg_replace('/đ/u', 'd', $color);
        
        // BƯỚC 3: Ánh xạ màu (Tiếng Việt -> Tiếng Anh)
        // Bao gồm các variant phổ biến của mỗi màu
        $colorMap = [
            // Đen
            'den' => 'black', 'den nham' => 'black', 'mau den' => 'black', 'black' => 'black',
            
            // Trắng
            'trang' => 'white', 'mau trang' => 'white', 'white' => 'white', 'ivory' => 'white', 'off white' => 'white',
            
            // Xám
            'xam' => 'grey', 'grey' => 'grey', 'gray' => 'grey', 'mau xam' => 'grey',
            
            // Xanh lá
            'xanh la' => 'green', 'xanh la cay' => 'green', 'green' => 'green', 'mau xanh la' => 'green',
            
            // Xanh dương
            'xanh duong' => 'blue', 'xanh da troi' => 'blue', 'blue' => 'blue', 'navy' => 'blue', 'mau xanh duong' => 'blue',
            
            // Đỏ
            'do' => 'red', 'mau do' => 'red', 'red' => 'red',
            
            // Vàng
            'vang' => 'yellow', 'mau vang' => 'yellow', 'yellow' => 'yellow', 'gold' => 'yellow',
            
            // Nâu
            'nau' => 'brown', 'mau nau' => 'brown', 'brown' => 'brown',
            
            // Hồng
            'hong' => 'pink', 'mau hong' => 'pink', 'pink' => 'pink',
            
            // Cam
            'cam' => 'orange', 'mau cam' => 'orange', 'orange' => 'orange',
            
            // Tím
            'tim' => 'purple', 'mau tim' => 'purple', 'purple' => 'purple', 'violet' => 'purple',
        ];
        
        // BƯỚC 4: Tìm exact match
        if (isset($colorMap[$color])) {
            return $colorMap[$color];
        }
        
        // BƯỚC 5: Tìm partial match (chứa từ khóa)
        // VD: "xanh duong nhat" chứa "xanh duong" -> "blue"
        foreach ($colorMap as $key => $standardColor) {
            if (strpos($color, $key) !== false) {
                return $standardColor;
            }
        }
        
        // BƯỚC 6: Không tìm thấy -> Giữ nguyên
        return $color;
    }
}



/**
 * HÀM CHUẨN HÓA THƯƠNG HIỆU
 * 
 * Mục đích: Chuẩn hóa tên thương hiệu về dạng chuẩn
 * 
 * Xử lý:
 * 1. Phát hiện và chuyển các brand không xác định thành "Unknown"
 * 2. Map các variant của brand phổ biến (VD: "nike shoes" -> "Nike")
 * 3. Viết hoa chữ cái đầu
 * 
 * Ví dụ:
 * "nike" -> "Nike"
 * "adidas originals" -> "Adidas"
 * "không xác định" -> "Unknown"
 * "NIKE" -> "Nike"
 * 
 * @param string $brand - Tên thương hiệu
 * @return string - Tên thương hiệu chuẩn
 */
if (!function_exists('standardizeBrand')) {
    function standardizeBrand($brand) {
        // BƯỚC 1: Danh sách các brand không xác định
        // Tất cả sẽ được chuyển thành "Unknown"
        $unknownBrands = [
            'fashion', 
            // Tiếng Việt có dấu
            'không xác định', 'chưa xác định', 'không rõ', 'chưa rõ',
            'không thương hiệu', 'không có thương hiệu', 'chưa có thương hiệu',
            'không nhãn hiệu', 'không có nhãn hiệu', 'không nhãn', 'chưa có nhãn',
            'không brand', 'không có brand',
            // Tiếng Việt không dấu
            'khong xac dinh', 'chua xac dinh', 'khong ro', 'chua ro',
            'khong thuong hieu', 'khong co thuong hieu', 'chua co thuong hieu',
            'khong nhan hieu', 'khong co nhan hieu', 'khong nhan', 'chua co nhan',
            'khong brand', 'khong co brand',
            // Tiếng Anh
            'no brand', 'nobrand', 'no-brand', 'unbranded', 'non-branded', 'non branded',
            'undefined', 'none', 'null', 'n/a', 'na', 'n.a', 'n.a.',
            // Ký tự đặc biệt
            '-', '--', '---', '_', '__', '___', '?', '??', '???',
        ];
        
        // BƯỚC 2: Kiểm tra brand rỗng
        if (empty($brand) || !trim($brand)) {
            return 'Unknown';
        }
        
        // BƯỚC 3: Chuyển về chữ thường để so sánh
        $brandLower = mb_strtolower(trim($brand), 'UTF-8');
        
        // BƯỚC 4: Kiểm tra có phải unknown brand không
        if (in_array($brandLower, $unknownBrands)) {
            return 'Unknown';
        }
        
        // BƯỚC 5: Mapping các variant của brand phổ biến
        // Key = Tên chuẩn, Value = Các variant
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
        
        // Tìm brand trong map
        foreach ($brandMap as $standard => $variants) {
            if (in_array($brandLower, $variants)) {
                return $standard;  // Trả về tên chuẩn
            }
        }
        
        // BƯỚC 6: Không tìm thấy -> Viết hoa chữ cái đầu
        return ucfirst(trim($brand));
    }
}

/**
 * Chuẩn hóa màu sắc - COMPREHENSIVE VERSION
 * Migrated từ normalization-utils.js với 150+ mappings
 */
if (!function_exists('standardizeColor')) {
    function standardizeColor($color) {
        if (empty($color) || !trim($color)) {
            return '';
        }
        
        // Kiểm tra nếu đã là tiếng Việt thì không translate lại
        $vietnameseColors = [
            'đen', 'trắng', 'đỏ', 'xanh', 'vàng', 'cam', 'tím', 'hồng', 'nâu', 'xám',
            'xanh dương', 'xanh lá', 'xanh navy', 'xanh lục', 'xanh bạc hà',
            'trắng kem', 'vàng kim', 'đỏ burgundy', 'bạc', 'đồng', 'beige', 'nude',
            'đậm', 'nhạt', 'tươi', 'pastel', 'kem'
        ];
        
        $colorLower = mb_strtolower(trim($color), 'UTF-8');
        
        // Check if already in Vietnamese
        foreach ($vietnameseColors as $vnColor) {
            if (mb_strpos($colorLower, $vnColor, 0, 'UTF-8') !== false) {
                error_log("Color already in Vietnamese: '$color' - Keeping as is");
                return mb_strtoupper(mb_substr($color, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($color, 1);
            }
        }
        
        $colorMap = [
            // Basic colors
            'black' => 'Đen', 'white' => 'Trắng', 'red' => 'Đỏ',
            'blue' => 'Xanh dương', 'green' => 'Xanh lá', 'yellow' => 'Vàng',
            'orange' => 'Cam', 'purple' => 'Tím', 'pink' => 'Hồng',
            'brown' => 'Nâu', 'gray' => 'Xám', 'grey' => 'Xám',
            
            // Special colors
            'beige' => 'Beige', 'cream' => 'Trắng kem', 'nude' => 'Nude',
            'gold' => 'Vàng kim', 'silver' => 'Bạc', 'bronze' => 'Đồng',
            'copper' => 'Đồng đỏ', 'rose gold' => 'Vàng hồng', 'rosegold' => 'Vàng hồng',
            
            // Vietnamese variations
            'đen' => 'Đen', 'den' => 'Đen', 'trắng' => 'Trắng', 'trang' => 'Trắng',
            'đỏ' => 'Đỏ', 'do' => 'Đỏ', 'xanh' => 'Xanh dương',
            'xanh dương' => 'Xanh dương', 'xanh duong' => 'Xanh dương',
            'xanh lá' => 'Xanh lá', 'xanh la' => 'Xanh lá', 'xanh lá cây' => 'Xanh lá',
            'vàng' => 'Vàng', 'vang' => 'Vàng', 'cam' => 'Cam',
            'tím' => 'Tím', 'tim' => 'Tím', 'hồng' => 'Hồng', 'hong' => 'Hồng',
            'nâu' => 'Nâu', 'nau' => 'Nâu', 'xám' => 'Xám', 'xam' => 'Xám',
            
            // Blue family
            'navy' => 'Xanh navy', 'navy blue' => 'Xanh navy',
            'royal blue' => 'Xanh hoàng gia', 'sky blue' => 'Xanh da trời',
            'baby blue' => 'Xanh baby', 'powder blue' => 'Xanh phấn',
            'turquoise' => 'Xanh ngọc', 'teal' => 'Xanh lục',
            'cyan' => 'Xanh cyan', 'aqua' => 'Xanh nước biển',
            
            // Green family
            'lime' => 'Xanh chanh', 'lime green' => 'Xanh chanh',
            'olive' => 'Xanh ô liu', 'olive green' => 'Xanh ô liu',
            'mint' => 'Xanh bạc hà', 'mint green' => 'Xanh bạc hà',
            'emerald' => 'Xanh lục bảo', 'forest green' => 'Xanh rừng',
            
            // Red family
            'burgundy' => 'Đỏ burgundy', 'wine' => 'Đỏ rượu',
            'wine red' => 'Đỏ rượu', 'maroon' => 'Đỏ nâu',
            'crimson' => 'Đỏ thẫm', 'scarlet' => 'Đỏ tươi',
            'cherry' => 'Đỏ cherry', 'cherry red' => 'Đỏ cherry',
            
            // Pink family
            'hot pink' => 'Hồng cánh sen', 'fuchsia' => 'Hồng fuchsia',
            'magenta' => 'Hồng magenta', 'rose' => 'Hồng rose',
            'blush' => 'Hồng phấn', 'blush pink' => 'Hồng phấn',
            'baby pink' => 'Hồng baby', 'salmon' => 'Hồng cam',
            'coral' => 'San hô', 'peach' => 'Hồng đào',
            
            // Purple family
            'lavender' => 'Tím lavender', 'lilac' => 'Tím lilac',
            'violet' => 'Tím violet', 'plum' => 'Tím mận',
            
            // Brown family
            'tan' => 'Nâu nhạt', 'taupe' => 'Nâu xám',
            'khaki' => 'Kaki', 'camel' => 'Nâu lạc đà',
            'chocolate' => 'Nâu sô cô la', 'coffee' => 'Nâu cà phê',
            
            // Neutrals
            'ivory' => 'Ngà', 'champagne' => 'Champagne',
            'charcoal' => 'Xám đen', 'slate' => 'Xám đá phiến',
            'off white' => 'Trắng ngà',
            
            // Combinations
            'light blue' => 'Xanh dương nhạt', 'dark blue' => 'Xanh dương đậm',
            'light pink' => 'Hồng nhạt', 'dark pink' => 'Hồng đậm',
            'light gray' => 'Xám nhạt', 'dark gray' => 'Xám đậm',
            'light grey' => 'Xám nhạt', 'dark grey' => 'Xám đậm',
            
            // Multicolor
            'multicolor' => 'Nhiều màu', 'multi-color' => 'Nhiều màu',
            'rainbow' => 'Cầu vồng', 'colorful' => 'Nhiều màu',
            
            // Transparent
            'transparent' => 'Trong suốt', 'clear' => 'Trong',
        ];
        
        // Exact match
        if (isset($colorMap[$colorLower])) {
            return $colorMap[$colorLower];
        }
        
        // Multi-word translation
        $words = preg_split('/\s+/', $colorLower);
        if (count($words) > 1) {
            $translatedWords = [];
            foreach ($words as $word) {
                $translatedWords[] = isset($colorMap[$word]) ? $colorMap[$word] : (mb_strtoupper(mb_substr($word, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($word, 1));
            }
            return implode(' ', $translatedWords);
        }
        
        // Capitalize if not found
        return mb_strtoupper(mb_substr($color, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($color, 1);
    }
}

/**
 * Chuẩn hóa loại sản phẩm - COMPREHENSIVE VERSION
 * Migrated từ normalization-utils.js với 360+ mappings
 */
if (!function_exists('standardizeProductType')) {
    function standardizeProductType($type) {
        if (empty($type)) {
            return '';
        }
        
        $typeMapping = [
            // Giày thể thao - Giữ nguyên "Sneaker"
            'sneaker' => 'Sneaker',
            'sneakers' => 'Sneaker',
            'giày thể thao' => 'Sneaker',
            'giay the thao' => 'Sneaker',
            'running' => 'Sneaker',
            'running shoe' => 'Sneaker',
            'running shoes' => 'Sneaker',
            'training' => 'Sneaker',
            'training shoe' => 'Sneaker',
            'training shoes' => 'Sneaker',
            'casual' => 'Sneaker',
            'casual shoe' => 'Sneaker',
            'casual shoes' => 'Sneaker',
            'lifestyle' => 'Sneaker',
            'basketball' => 'Sneaker',
            'basketball shoe' => 'Sneaker',
            'basketball shoes' => 'Sneaker',
            'tennis' => 'Sneaker',
            'tennis shoe' => 'Sneaker',
            'tennis shoes' => 'Sneaker',
            'walking shoe' => 'Sneaker',
            'walking shoes' => 'Sneaker',
            'gym shoe' => 'Sneaker',
            'gym shoes' => 'Sneaker',
            'athletic shoe' => 'Sneaker',
            'athletic shoes' => 'Sneaker',
            'sport shoe' => 'Sneaker',
            'sport shoes' => 'Sneaker',
            'sports shoe' => 'Sneaker',
            'sports shoes' => 'Sneaker',
            'giày chạy bộ' => 'Sneaker',
            'giay chay bo' => 'Sneaker',
            'giày tập gym' => 'Sneaker',
            'giay tap gym' => 'Sneaker',
            'giày tập luyện' => 'Sneaker',
            'giay tap luyen' => 'Sneaker',
            'giày vải' => 'Sneaker',
            'giay vai' => 'Sneaker',
            'giày canvas' => 'Sneaker',
            'giay canvas' => 'Sneaker',
            'converse' => 'Sneaker',
            'vans' => 'Sneaker',
            'giày thời trang' => 'Sneaker',
            'giay thoi trang' => 'Sneaker',
            'giày casual nữ' => 'Sneaker',
            'giay casual nu' => 'Sneaker',
            'giày casual nam' => 'Sneaker',
            'giay casual nam' => 'Sneaker',
            
            // Sandal - Giữ nguyên "Sandal"
            'sandal' => 'Sandal',
            'sandals' => 'Sandal',
            'dép' => 'Sandal',
            'dep' => 'Sandal',
            'slide' => 'Sandal',
            'flip-flop' => 'Sandal',
            'flipflop' => 'Sandal',
            'sandal quai' => 'Sandal',
            'sandal nữ' => 'Sandal',
            'sandal nu' => 'Sandal',
            'sandal nam' => 'Sandal',
            'sandal trẻ em' => 'Sandal',
            'sandal tre em' => 'Sandal',
            'sandal bít mũi' => 'Sandal',
            'sandal bit mui' => 'Sandal',
            'sandal hở mũi' => 'Sandal',
            'sandal ho mui' => 'Sandal',
            
            // Giày cao gót
            'high heels' => 'Giày cao gót',
            'high heel' => 'Giày cao gót',
            'cao gót' => 'Giày cao gót',
            'cao got' => 'Giày cao gót',
            'giày cao gót' => 'Giày cao gót',
            'giay cao got' => 'Giày cao gót',
            'pump' => 'Giày cao gót',
            'pumps' => 'Giày cao gót',
            'stiletto' => 'Giày cao gót',
            'stilettos' => 'Giày cao gót',
            'block heel' => 'Giày cao gót',
            'kitten heels' => 'Giày cao gót',
            'kitten heel' => 'Giày cao gót',
            'slingback' => 'Giày cao gót',
            'slingbacks' => 'Giày cao gót',
            'giày gót nhọn' => 'Giày cao gót',
            'giay got nhon' => 'Giày cao gót',
            'giày gót vuông' => 'Giày cao gót',
            'giay got vuong' => 'Giày cao gót',
            'giày gót thấp' => 'Giày cao gót',
            'giay got thap' => 'Giày cao gót',
            'giày cao cổ' => 'Giày cao gót',
            'giay cao co' => 'Giày cao gót',
            'platform' => 'Giày cao gót',
            'platform shoe' => 'Giày cao gót',
            'platform shoes' => 'Giày cao gót',
            'giày đế cao' => 'Giày cao gót',
            'giay de cao' => 'Giày cao gót',
            
            // Giày đế xuồng (QUAN TRỌNG - phải match trước sandal)
            'wedge' => 'Giày đế xuồng',
            'wedges' => 'Giày đế xuồng',
            'đế xuồng' => 'Giày đế xuồng',
            'de xuong' => 'Giày đế xuồng',
            'giày đế xuồng' => 'Giày đế xuồng',
            'sandal đế xuồng' => 'Giày đế xuồng',
            'sandal de xuong' => 'Giày đế xuồng',
            'sandal bịt mũi đế xuồng' => 'Giày đế xuồng',
            'sandal bit mui de xuong' => 'Giày đế xuồng',
            'wedge sandal' => 'Giày đế xuồng',
            'wedge sandals' => 'Giày đế xuồng',
            'giày sandal đế xuồng' => 'Giày đế xuồng',
            'giay sandal de xuong' => 'Giày đế xuồng',
            'sandal nữ đế xuồng' => 'Giày đế xuồng',
            'sandal nu de xuong' => 'Giày đế xuồng',
            'giày đế bằng xuồng' => 'Giày đế xuồng',
            'giay de bang xuong' => 'Giày đế xuồng',
            'giày nữ đế xuồng' => 'Giày đế xuồng',
            'giay nu de xuong' => 'Giày đế xuồng',
            'giày cao gót đế xuồng' => 'Giày đế xuồng',
            'giay cao got de xuong' => 'Giày đế xuồng',
            'wedge heel' => 'Giày đế xuồng',
            'wedge heel sandal' => 'Giày đế xuồng',
            'wedge heel shoe' => 'Giày đế xuồng',
            'platform wedge' => 'Giày đế xuồng',
            
            // Giày bệt
            'flat' => 'Giày bệt',
            'flats' => 'Giày bệt',
            'giày bệt' => 'Giày bệt',
            'giay bet' => 'Giày bệt',
            'ballet flat' => 'Giày bệt',
            'ballet flats' => 'Giày bệt',
            'giày búp bê' => 'Giày bệt',
            'bệt' => 'Giày bệt',
            'bet' => 'Giày bệt',
            'giày đế bằng' => 'Giày bệt',
            'giay de bang' => 'Giày bệt',
            'giày búp bê nữ' => 'Giày bệt',
            'giay bup be nu' => 'Giày bệt',
            'giày ballerina' => 'Giày bệt',
            'giay ballerina' => 'Giày bệt',
            'giày êm chân' => 'Giày bệt',
            'giay em chan' => 'Giày bệt',
            'mary jane' => 'Giày bệt',
            'mary janes' => 'Giày bệt',
            'giày mary jane' => 'Giày bệt',
            'espadrille' => 'Giày bệt',
            'espadrilles' => 'Giày bệt',
            'giày cói' => 'Giày bệt',
            'giay coi' => 'Giày bệt',
            
            // Giày lười
            'loafer' => 'Giày lười',
            'loafers' => 'Giày lười',
            'giày lười' => 'Giày lười',
            'giay luoi' => 'Giày lười',
            'slip-on' => 'Giày lười',
            'slip on' => 'Giày lười',
            'slipon' => 'Giày lười',
            'moccasin' => 'Giày lười',
            'moccasins' => 'Giày lười',
            'boat shoe' => 'Giày lười',
            'boat shoes' => 'Giày lười',
            'giày không dây' => 'Giày lười',
            'giay khong day' => 'Giày lười',
            'giày slip on' => 'Giày lười',
            'giay slip on' => 'Giày lười',
            'giày lười nam' => 'Giày lười',
            'giay luoi nam' => 'Giày lười',
            'giày lười nữ' => 'Giày lười',
            'giay luoi nu' => 'Giày lười',
            'giày da lười' => 'Giày lười',
            'giay da luoi' => 'Giày lười',
            'penny loafer' => 'Giày lười',
            'penny loafers' => 'Giày lười',
            'driving shoe' => 'Giày lười',
            'driving shoes' => 'Giày lười',
            'giày lái xe' => 'Giày lười',
            'giay lai xe' => 'Giày lười',
            
            // Giày tây
            'oxford' => 'Giày tây',
            'oxfords' => 'Giày tây',
            'giày tây' => 'Giày tây',
            'giay tay' => 'Giày tây',
            'dress shoe' => 'Giày tây',
            'dress shoes' => 'Giày tây',
            'formal shoe' => 'Giày tây',
            'formal shoes' => 'Giày tây',
            'business shoe' => 'Giày tây',
            'derby' => 'Giày tây',
            'brogue' => 'Giày tây',
            'giày da nam' => 'Giày tây',
            'giay da nam' => 'Giày tây',
            'giày công sở' => 'Giày tây',
            'giay cong so' => 'Giày tây',
            'giày công sở nam' => 'Giày tây',
            'giay cong so nam' => 'Giày tây',
            'giày dây' => 'Giày tây',
            'giay day' => 'Giày tây',
            'giày buộc dây' => 'Giày tây',
            'giay buoc day' => 'Giày tây',
            'giày oxford' => 'Giày tây',
            'giay oxford' => 'Giày tây',
            'giày oxford nam' => 'Giày tây',
            'giay oxford nam' => 'Giày tây',
            'monk strap' => 'Giày tây',
            'monk straps' => 'Giày tây',
            'giày monk' => 'Giày tây',
            
            // Boot
            'boot' => 'Boot',
            'boots' => 'Boot',
            'giày boot' => 'Boot',
            'giay boot' => 'Boot',
            'ankle boot' => 'Boot',
            'ankle boots' => 'Boot',
            'chelsea boot' => 'Boot',
            'chelsea boots' => 'Boot',
            'combat boot' => 'Boot',
            'combat boots' => 'Boot',
            'knee boot' => 'Boot',
            'knee boots' => 'Boot',
            'bốt' => 'Boot',
            'bot' => 'Boot',
            'giày bốt' => 'Boot',
            'giay bot' => 'Boot',
            'boot cao cổ' => 'Boot',
            'boot cao co' => 'Boot',
            'boot cổ ngắn' => 'Boot',
            'boot co ngan' => 'Boot',
            'boot da' => 'Boot',
            'boot cao gót' => 'Boot',
            'boot cao got' => 'Boot',
            'martin' => 'Boot',
            'dr martens' => 'Boot',
            'dr. martens' => 'Boot',
            
            // Giày mules
            'mule' => 'Giày mules',
            'mules' => 'Giày mules',
            'giày mules' => 'Giày mules',
            'giày không gót' => 'Giày mules',
            'giay khong got' => 'Giày mules',
            'giày hở gót' => 'Giày mules',
            'giay ho got' => 'Giày mules',
            'giày sục' => 'Giày mules',
            'giay suc' => 'Giày mules',
            
            // Giày quai hậu
            'quai hậu' => 'Giày quai hậu',
            'quai hau' => 'Giày quai hậu',
            'giày quai hậu' => 'Giày quai hậu',
            'giày có quai hậu' => 'Giày quai hậu',
            'giay co quai hau' => 'Giày quai hậu',
            'giày quai sau' => 'Giày quai hậu',
            'giay quai sau' => 'Giày quai hậu',
            'slingback heels' => 'Giày quai hậu',
            'slingback pumps' => 'Giày quai hậu',
            
            // Dép (các biến thể)
            'dép lê' => 'Dép',
            'dep le' => 'Dép',
            'dép đi trong nhà' => 'Dép',
            'dep di trong nha' => 'Dép',
            'dép xỏ ngón' => 'Dép',
            'dep xo ngon' => 'Dép',
            'dép quai ngang' => 'Dép',
            'dep quai ngang' => 'Dép',
            'slipper' => 'Dép',
            'slippers' => 'Dép',
            'house slipper' => 'Dép',
            'indoor slipper' => 'Dép',
            
            // Giày thể thao chuyên dụng
            'football boot' => 'Giày thể thao chuyên dụng',
            'football boots' => 'Giày thể thao chuyên dụng',
            'soccer cleat' => 'Giày thể thao chuyên dụng',
            'golf shoe' => 'Giày thể thao chuyên dụng',
            'golf shoes' => 'Giày thể thao chuyên dụng',
            'giày đá bóng' => 'Giày thể thao chuyên dụng',
            'giay da bong' => 'Giày thể thao chuyên dụng',
        ];
        
        $typeLower = mb_strtolower(trim($type), 'UTF-8');
        
        // Exact match
        if (isset($typeMapping[$typeLower])) {
            return $typeMapping[$typeLower];
        }
        
        // Partial match - sắp xếp keys theo độ dài giảm dần
        $sortedKeys = array_keys($typeMapping);
        usort($sortedKeys, function($a, $b) {
            return mb_strlen($b, 'UTF-8') - mb_strlen($a, 'UTF-8');
        });
        
        foreach ($sortedKeys as $key) {
            if (mb_strpos($typeLower, $key, 0, 'UTF-8') !== false) {
                error_log("Partial match: '$typeLower' contains '$key' → '{$typeMapping[$key]}'");
                return $typeMapping[$key];
            }
        }
        
        return ucfirst($type);
    }
}

?>
