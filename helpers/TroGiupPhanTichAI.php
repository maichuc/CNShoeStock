<?php
/**
 * AI Analysis Helper
 * Tập hợp các hàm dùng chung cho phân tích AI sản phẩm
 * Được sử dụng bởi: them_san_pham_ai.php, tao_phieu_nhap_moi.php, 
 *                    api_phan_tich_giay_ai.php, api_luu_phieu_ai.php, api_du_bao_ai.php
 */

// Import TroGiupDoTuongDong.php để sử dụng standardizeColor()
require_once __DIR__ . '/TroGiupDoTuongDong.php';

/**
 * HÀM TẢI ENVIRONMENT VARIABLES
 * 
 * Mục đích: Đọc file .env và load các biến môi trường (API keys)
 * File .env chứa: GEMINI_API_KEY, CLAUDE_API_KEY, GPT_API_KEY...
 */
if (!function_exists('loadEnvironmentVariables')) {
    function loadEnvironmentVariables() {
        // Lấy đường dẫn file .env (ở thư mục gốc project)
        $envFile = __DIR__ . '/../.env';
        
        // Kiểm tra file .env có tồn tại không
        if (file_exists($envFile)) {
            // Đọc file .env, bỏ qua dòng trống
            // FILE_IGNORE_NEW_LINES: Bỏ ký tự xuống dòng ở cuối mỗi dòng
            // FILE_SKIP_EMPTY_LINES: Bỏ qua các dòng trống
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            
            // Duyệt qua từng dòng trong file .env
            foreach ($lines as $line) {
                // Bỏ qua các dòng comment (bắt đầu bằng #)
                if (strpos($line, '#') === 0) continue;
                
                // Chỉ xử lý dòng có dấu = (format: KEY=VALUE)
                if (strpos($line, '=') !== false) {
                    // Tách KEY và VALUE, giới hạn 2 phần (nếu VALUE có dấu =)
                    list($key, $value) = explode('=', $line, 2);
                    
                    // Loại bỏ khoảng trắng thừa
                    $key = trim($key);
                    $value = trim($value);
                    
                    // Chỉ set nếu chưa tồn tại (không ghi đè)
                    if (!getenv($key)) {
                        // Lưu vào environment variables của PHP
                        putenv("$key=$value");
                    }
                }
            }
        }
    }
}

/**
 * HÀM DỊCH MÀU TỪ TIẾNG ANH SANG TIẾNG VIỆT
 * 
 * DEPRECATED: Function này giờ chỉ là wrapper cho standardizeColor()
 * 
 * Mục đích: Chuẩn hóa tên màu sắc (AI thường trả về tiếng Anh)
 * Ví dụ: "Black" -> "Đen", "Light Blue" -> "Xanh dương nhạt"
 * 
 * @param string $color - Tên màu (tiếng Anh hoặc tiếng Việt)
 * @return string - Tên màu tiếng Việt chuẩn
 * 
 * @see standardizeColor() in TroGiupDoTuongDong.php (comprehensive implementation)
 */
if (!function_exists('translateColorToVietnamese')) {
    function translateColorToVietnamese($color) {
        // Forward to standardizeColor() để tránh code trùng lặp
        // standardizeColor() có 150+ color mappings (comprehensive)
        return standardizeColor($color);
    }
}

/**
 * HÀM CHUẨN HÓA DỮ LIỆU TỪ AI
 * 
 * Mục đích: Xử lý và chuẩn hóa dữ liệu sau khi nhận từ AI (Gemini/Claude/GPT)
 * 
 * Các bước xử lý:
 * 1. Chuyển màu sắc từ string sang array
 * 2. Dịch màu từ tiếng Anh sang tiếng Việt
 * 3. Xóa màu trùng lặp
 * 4. Chuẩn hóa loại sản phẩm
 * 5. Chuẩn hóa tags
 * 
 * @param array $aiData - Dữ liệu thô từ AI
 * @return array - Dữ liệu đã chuẩn hóa
 */
if (!function_exists('normalizeAIData')) {
    function normalizeAIData($aiData) {
        /**
         * BƯỚC 1: CHUẨN BỊ MÀU SẮC - CHUYỂN SANG ARRAY
         * 
         * Vấn đề: AI có thể trả về:
         * - 'color': "Black" (số ít)
         * - 'colors': "Black, White" (số nhiều, string)
         * - 'colors': ["Black", "White"] (số nhiều, array)
         * 
         * Giải pháp: Thống nhất tất cả về 'colors' dạng array
         */
        
        // Nếu có 'color' mà không có 'colors' -> Copy sang 'colors'
        if (isset($aiData['color']) && !isset($aiData['colors'])) {
            $aiData['colors'] = $aiData['color'];
        }
        
        // Nếu 'colors' là string -> Tách thành array
        if (isset($aiData['colors'])) {
            if (is_string($aiData['colors'])) {
                // Tách bằng dấu phẩy, loại bỏ khoảng trắng thừa
                // Ví dụ: "Black, White" -> ["Black", "White"]
                $aiData['colors'] = array_map('trim', explode(',', $aiData['colors']));
            }
        }
        
        // Đảm bảo 'colors' luôn là array (phòng trường hợp khác)
        if (isset($aiData['colors']) && !is_array($aiData['colors'])) {
            $aiData['colors'] = [$aiData['colors']];
        }
        
        /**
         * BƯỚC 2: DỊCH MÀU SẮC SANG TIẾNG VIỆT
         * 
         * Quy trình:
         * 1. Kiểm tra màu đã là tiếng Việt chưa (tránh dịch 2 lần)
         * 2. Nếu là tiếng Anh -> Dịch từng từ sang tiếng Việt
         * 3. Xóa từ trùng lặp (Ví dụ: "Xanh dương dương" -> "Xanh dương")
         * 4. Viết hoa chữ cái đầu
         */
        if (isset($aiData['colors']) && is_array($aiData['colors'])) {
            $processedColors = [];
            
            // Danh sách màu tiếng Việt - Dùng để kiểm tra đã dịch chưa
            $vietnameseColors = [
                'đen', 'trắng', 'đỏ', 'xanh', 'vàng', 'cam', 'tím', 'hồng', 'nâu', 'xám',
                'xanh dương', 'xanh lá', 'xanh navy', 'xanh lục', 'xanh bạc hà',
                'trắng kem', 'vàng kim', 'đỏ burgundy', 'bạc', 'đồng', 'beige', 'nude',
                'đậm', 'nhạt', 'tươi', 'pastel'
            ];
            
            // Duyệt qua từng màu
            foreach ($aiData['colors'] as $color) {
                // Loại bỏ nội dung trong ngoặc đơn: "Black (Matte)" -> "Black"
                $color = preg_replace('/\s*\([^)]*\)/u', '', $color);
                $color = trim($color);
                
                // Bỏ qua màu rỗng
                if (empty($color)) continue;
                
                // Kiểm tra xem màu đã là tiếng Việt chưa
                $colorLower = mb_strtolower($color, 'UTF-8');
                $isVietnamese = false;
                
                foreach ($vietnameseColors as $vnColor) {
                    // Nếu chứa bất kỳ từ tiếng Việt nào -> Đánh dấu là tiếng Việt
                    if (strpos($colorLower, $vnColor) !== false) {
                        $isVietnamese = true;
                        break;
                    }
                }
                
                // Nếu CHƯA phải tiếng Việt -> Dịch
                if (!$isVietnamese) {
                    // Tách màu thành các từ riêng biệt
                    // Ví dụ: "Light Blue" -> ["Light", "Blue"]
                    $words = preg_split('/\s+/u', $color);
                    $translatedWords = [];
                    
                    // Danh sách từ màu tiếng Anh cần dịch
                    $englishColors = ['black', 'white', 'red', 'blue', 'green', 'yellow', 
                                     'orange', 'purple', 'pink', 'brown', 'gray', 'grey',
                                     'beige', 'cream', 'nude', 'gold', 'silver', 'burgundy',
                                     'navy', 'maroon', 'olive', 'mint', 'lavender', 'coral',
                                     'dark', 'light', 'bright', 'pale', 'deep'];
                    
                    // Dịch từng từ
                    foreach ($words as $word) {
                        $wordLower = mb_strtolower($word, 'UTF-8');
                        
                        // Nếu là từ màu tiếng Anh -> Dịch
                        if (in_array($wordLower, $englishColors)) {
                            $translatedWords[] = translateColorToVietnamese($word);
                        } else {
                            // Không phải từ màu -> Giữ nguyên
                            $translatedWords[] = $word;
                        }
                    }
                    
                    // Ghép các từ đã dịch lại
                    // Ví dụ: ["nhạt", "Xanh dương"] -> "nhạt Xanh dương"
                    $color = implode(' ', $translatedWords);
                }
                
                /**
                 * XÓA TỪ TRÙNG LẶP
                 * 
                 * Vấn đề: Sau khi dịch có thể bị:
                 * - "Xanh dương dương" (từ đơn trùng)
                 * - "Xanh dương Xanh dương" (cụm từ trùng)
                 * 
                 * Giải pháp: Xóa các từ/cụm từ lặp liên tiếp
                 */
                
                // BƯỚC 2.1: Xóa từ đơn trùng lặp liên tiếp
                // Ví dụ: "Xanh dương dương" -> "Xanh dương"
                $words = preg_split('/\s+/u', $color);  // Tách thành mảng từ
                $deduped = [];  // Mảng kết quả
                $lastWord = '';  // Từ trước đó
                
                foreach ($words as $word) {
                    $wordLower = mb_strtolower($word, 'UTF-8');
                    $lastWordLower = mb_strtolower($lastWord, 'UTF-8');
                    
                    // Chỉ thêm nếu khác từ trước đó
                    if ($wordLower !== $lastWordLower) {
                        $deduped[] = $word;
                        $lastWord = $word;
                    }
                }
                $color = implode(' ', $deduped);  // Ghép lại thành string
                
                // BƯỚC 2.2: Xóa cụm từ trùng lặp liên tiếp
                // Ví dụ: "Xanh dương Xanh dương" -> "Xanh dương"
                // Sử dụng regex để tìm và xóa cụm lặp
                do {
                    $changed = false;  // Flag để kiểm tra có thay đổi không
                    
                    // Thử xóa cụm 3 từ lặp
                    // Pattern: \b(\p{L}+\s+\p{L}+\s+\p{L}+)\s+\1\b
                    // Giải thích: Tìm 3 từ, sau đó là khoảng trắng, rồi lại 3 từ giống y hệt
                    $color = preg_replace_callback('/\b(\p{L}+\s+\p{L}+\s+\p{L}+)\s+\1\b/ui', function($matches) use (&$changed) {
                        $changed = true;
                        return $matches[1];  // Giữ lại 1 cụm, xóa cụm trùng
                    }, $color);
                    
                    // Thử xóa cụm 2 từ lặp
                    $color = preg_replace_callback('/\b(\p{L}+\s+\p{L}+)\s+\1\b/ui', function($matches) use (&$changed) {
                        $changed = true;
                        return $matches[1];
                    }, $color);
                    
                } while ($changed); // Lặp lại cho đến khi không còn thay đổi
                
                // BƯỚC 2.3: Viết hoa chữ cái đầu
                // Ví dụ: "xanh dương" -> "Xanh dương"
                $color = mb_strtoupper(mb_substr($color, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($color, 1, null, 'UTF-8');
                
                // Thêm vào danh sách màu đã xử lý (nếu không rỗng)
                if (!empty($color)) {
                    $processedColors[] = $color;
                }
            }
            
            // Cập nhật lại mảng màu đã xử lý
            $aiData['colors'] = $processedColors;
        }
        
        /**
         * BƯỚC 3: CHUYỂN DANH SÁCH MÀU SANG CHUỖI
         * 
         * Từ: ["Đen", "Trắng"] -> "Đen, Trắng"
         * 
         * Lý do: Form hiển thị màu dưới dạng string, không phải array
         * array_unique: Xóa màu trùng lặp (phòng trường hợp có 2 màu giống nhau)
         */
        if (isset($aiData['colors']) && is_array($aiData['colors'])) {
            $aiData['color'] = implode(', ', array_unique($aiData['colors']));
        }
        
        /**
         * BƯỚC 4: TỰ ĐỘNG SỬA LOẠI SẢN PHẨM
         * 
         * Vấn đề: AI đôi khi phân loại sai
         * - AI nói "Giày quai hậu" nhưng thực tế là "Giày cao gót"
         * 
         * Giải pháp: Kiểm tra description/features có chứa từ khóa "cao gót" không
         * Nếu có -> Tự động sửa thành "Giày cao gót"
         */
        if (isset($aiData['type']) && $aiData['type'] === 'Giày quai hậu') {
            // Ghép tất cả text để tìm kiếm
            $description = strtolower($aiData['name'] ?? '') . ' ' . 
                          strtolower($aiData['description'] ?? '') . ' ' . 
                          strtolower($aiData['features'] ?? '');
            
            // Tìm các từ khóa liên quan đến cao gót
            if (strpos($description, 'cao gót') !== false || 
                strpos($description, 'heel') !== false || 
                strpos($description, 'gót cao') !== false ||
                strpos($description, 'gót nhọn') !== false) {
                
                // Sửa lại loại sản phẩm
                $aiData['type'] = 'Giày cao gót';
                error_log("✅ Auto-corrected type: 'Giày quai hậu' => 'Giày cao gót' based on description");
            }
        }
        
        /**
         * BƯỚC 5: CHUẨN HÓA TAGS
         * 
         * AI có thể trả về tags dưới dạng:
         * - String: "tag1, tag2, tag3"
         * - Array: ["tag1", "tag2", "tag3"]
         * 
         * Chuẩn hóa: Luôn chuyển về array
         */
        if (isset($aiData['tags']) && !is_array($aiData['tags'])) {
            if (is_string($aiData['tags'])) {
                // Tách string thành array, loại bỏ khoảng trắng
                $aiData['tags'] = array_map('trim', explode(',', $aiData['tags']));
            } else {
                // Nếu không phải string cũng không phải array -> Gán mảng rỗng
                $aiData['tags'] = [];
            }
        }
        
        // Log kết quả cuối cùng để debug
        error_log("✅ Normalized AI Data: " . json_encode($aiData, JSON_UNESCAPED_UNICODE));
        
        return $aiData;
    }
}

// Xử lý phân tích AI với Gemini API
if (!function_exists('analyzeImageWithGemini')) {
    function analyzeImageWithGemini($imagePath) {
        $apiKey = getenv('GEMINI_API_KEY');
        
        if (!$apiKey) {
            return ['success' => false, 'message' => 'API AI không được cấu hình. Vui lòng nhập thông tin sản phẩm thủ công.'];
        }
        
        if (!file_exists($imagePath)) {
            return ['success' => false, 'message' => 'Không tìm thấy file ảnh. Vui lòng tải lại ảnh.'];
        }
        
        try {
            $imageData = base64_encode(file_get_contents($imagePath));
            $mimeType = mime_content_type($imagePath);
            
            $prompt = "Phân tích hình ảnh sản phẩm giày này và trả về thông tin chi tiết dưới dạng JSON với các trường sau:
{
  \"name\": \"Tên sản phẩm (ví dụ: Giày sneaker Nike)\",
  \"brand\": \"Thương hiệu (ví dụ: Nike, Adidas, Lecos)\",
  \"type\": \"Loại sản phẩm (ví dụ: Sneaker, Boot, Sandal)\",
  \"colors\": [\"màu sắc chính\", \"màu phụ\"],
  \"description\": \"Mô tả chi tiết sản phẩm\",
  \"category\": \"Danh mục (Giày thể thao, Giày cao gót, Giày boot, etc.)\",
  \"tags\": [\"tag1\", \"tag2\", \"tag3\"],
  \"material\": \"Chất liệu (da, vải, cao su, etc.)\",
  \"features\": \"Tính năng đặc biệt (thoáng khí, chống nước, etc.)\"
}

Chỉ trả về JSON, không thêm text khác.";

            $requestData = [
                'contents' => [
                    [
                        'parts' => [
                            [
                                'text' => $prompt
                            ],
                            [
                                'inline_data' => [
                                    'mime_type' => $mimeType,
                                    'data' => $imageData
                                ]
                            ]
                        ]
                    ]
                ]
            ];

            // Thử lại mechanism
            $maxRetries = 2;
            $retryDelay = 1;
            
            for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => "https://generativelanguage.googleapis.com/v1/models/gemini-2.5-flash:generateContent?key=" . $apiKey,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode($requestData),
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json'
                    ],
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_SSL_VERIFYPEER => false
                ]);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);

                if ($curlError) {
                    error_log("Gemini API curl error on attempt $attempt: " . $curlError);
                    if ($attempt === $maxRetries) {
                        return ['success' => false, 'message' => 'Connection error: ' . $curlError];
                    }
                    continue;
                }

                if ($httpCode === 200) {
                    break;
                }
                
                if ($httpCode === 429 || $httpCode === 403 || $httpCode === 404 || $httpCode === 402) {
                    error_log("API issue ($httpCode) on attempt $attempt - API không khả dụng");
                    if ($attempt === $maxRetries) {
                        return ['success' => false, 'message' => 'API AI hiện không khả dụng (lỗi ' . $httpCode . '). Vui lòng nhập thông tin sản phẩm thủ công.'];
                    }
                }

                if ($attempt === $maxRetries) {
                    error_log("Single image Gemini API failed after $maxRetries attempts: HTTP $httpCode - " . $response);
                    return ['success' => false, 'message' => 'API AI không phản hồi sau ' . $maxRetries . ' lần thử. Vui lòng nhập thông tin sản phẩm thủ công.'];
                }
            }

            $result = json_decode($response, true);
            
            if (!$result || !isset($result['candidates'][0]['content']['parts'][0]['text'])) {
                error_log("Gemini API response error: " . $response);
                return ['success' => false, 'message' => 'Invalid API response'];
            }

            $aiText = $result['candidates'][0]['content']['parts'][0]['text'];
            
            // Xóa markdown formatting
            $aiText = preg_replace('/```json\s*/', '', $aiText);
            $aiText = preg_replace('/```\s*$/', '', $aiText);
            $aiText = trim($aiText);
            
            $aiData = json_decode($aiText, true);
            
            if (!$aiData) {
                error_log("Gemini JSON parse error: " . $aiText);
                return ['success' => false, 'message' => 'Failed to parse AI response'];
            }

            // Chuẩn hóa dữ liệu AI
            $aiData = normalizeAIData($aiData);

            return ['success' => true, 'data' => $aiData];
            
        } catch (Exception $e) {
            error_log("Gemini API exception: " . $e->getMessage());
            return ['success' => false, 'message' => 'Analysis failed: ' . $e->getMessage()];
        }
    }
}

// Hàm chính để phân tích ảnh với Gemini API
if (!function_exists('analyzeImageWithAI')) {
    function analyzeImageWithAI($imagePath) {
        // Chỉ sử dụng Gemini API
        error_log("Analyzing image with Gemini API: " . $imagePath);
        $geminiResult = analyzeImageWithGemini($imagePath);
        
        if ($geminiResult['success']) {
            error_log("Gemini API analysis successful");
            return $geminiResult;
        }
        
        // Nếu Gemini thất bại
        error_log("Gemini API failed: " . $geminiResult['message']);
        return [
            'success' => false, 
            'message' => 'Lỗi phân tích AI: ' . $geminiResult['message']
        ];
    }
}

// Xử lý phân tích AI với TẤT CẢ ảnh sử dụng Gemini API
if (!function_exists('analyzeMultipleImagesWithGemini')) {
    function analyzeMultipleImagesWithGemini($imagePaths) {
        $apiKey = getenv('GEMINI_API_KEY');
        if (!$apiKey) {
            return ['success' => false, 'message' => 'Gemini API key not configured'];
        }
        
        usleep(500000); // 500ms delay
        
        $validImages = [];
        foreach ($imagePaths as $imagePath) {
            if (file_exists($imagePath)) {
                $validImages[] = $imagePath;
            } else {
                error_log("Image file not found: " . $imagePath);
            }
        }
        
        if (empty($validImages)) {
            return ['success' => false, 'message' => 'No valid images found'];
        }
        
        try {
            error_log("Analyzing " . count($validImages) . " images with Gemini");
            
            $parts = [];
            
            $prompt = "Phân tích TẤT CẢ hình ảnh sản phẩm giày này (có thể cùng 1 sản phẩm từ nhiều góc độ) và trả về thông tin chi tiết tổng hợp dưới dạng JSON:

{
  \"name\": \"Tên sản phẩm cụ thể và chi tiết nhất từ tất cả ảnh\",
  \"brand\": \"Thương hiệu (nhận diện từ logo/text trên sản phẩm)\",
  \"type\": \"Loại sản phẩm (Sneaker, Boot, Sandal, Giày cao gót, etc.)\",
  \"colors\": [\"màu sắc 1\", \"màu sắc 2\"],
  \"description\": \"Mô tả tổng hợp chi tiết từ tất cả góc nhìn\",
  \"category\": \"Danh mục (Giày thể thao, Giày cao gót, Giày boot, etc.)\",
  \"tags\": [\"tag từ tất cả ảnh\"],
  \"material\": \"Chất liệu (da, vải, cao su, etc.)\",
  \"features\": \"Tính năng đặc biệt (thoáng khí, chống nước, etc.)\",
  \"confidence\": 0.9,
  \"image_details\": [
    {\"index\": 1, \"description\": \"mô tả ảnh 1\", \"key_features\": [\"đặc điểm\"]},
    {\"index\": 2, \"description\": \"mô tả ảnh 2\", \"key_features\": [\"đặc điểm\"]}
  ]
}

QUY TẮC QUAN TRỌNG:
1. MÀU SẮC:
   - Trả về tên màu tiếng Việt thuần túy: \"Đen\", \"Trắng\", \"Đỏ\", \"Xanh navy\", \"Đỏ burgundy\"
   - KHÔNG ĐƯỢC thêm mô tả vị trí vào màu sắc: ❌ \"Đen (lót)\", ❌ \"Vàng (khóa)\"
   - Chỉ liệt kê MỘT LẦN cho mỗi màu: ❌ \"Đỏ Đỏ Đỏ\", ✅ \"Đỏ\"
   - Màu phụ có thể thêm: \"Đỏ burgundy\", \"Xanh navy\", \"Vàng kim\"
   - Ví dụ: [\"Đen\", \"Vàng kim\", \"Trắng\"]

2. LOẠI SẢN PHẨM:
   - Nếu là giày có gót cao, quai hậu hoặc slingback => sử dụng \"Giày cao gót\"
   - Phân biệt rõ: \"Sneaker\", \"Boot\", \"Sandal\", \"Giày cao gót\", \"Giày quai hậu\", \"Giày bệt\"
   - Ưu tiên \"Giày cao gót\" hơn \"Giày quai hậu\" nếu có gót cao

3. THƯƠNG HIỆU:
   - Nhận diện chính xác từ logo/text trên sản phẩm
   - Nếu không thấy rõ logo => trả về \"Unknown\"

4. TÍNH NHẤT QUÁN:
   - Với cùng 1 sản phẩm, luôn trả về KẾT QUẢ GIỐNG NHAU
   - Confidence cao hơn khi có nhiều ảnh xác nhận

5. FORMAT OUTPUT:
   - Chỉ trả về JSON thuần, không thêm text markdown, code block hay giải thích
   - Kết hợp thông tin từ TẤT CẢ ảnh để có mô tả đầy đủ nhất";

            $parts[] = ['text' => $prompt];
            
            foreach ($validImages as $index => $imagePath) {
                $imageData = base64_encode(file_get_contents($imagePath));
                $mimeType = mime_content_type($imagePath);
                
                $parts[] = [
                    'inline_data' => [
                        'mime_type' => $mimeType,
                        'data' => $imageData
                    ]
                ];
                
                error_log("Added image " . ($index + 1) . " for analysis: " . $imagePath);
            }

            $requestData = [
                'contents' => [
                    [
                        'parts' => $parts
                    ]
                ],
                'generationConfig' => [
                    'temperature' => 0.0,
                    'topK' => 1,
                    'topP' => 1,
                    'maxOutputTokens' => 4096
                ]
            ];

            $maxRetries = 3;
            $retryDelay = 1;
            
            for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
                error_log("Multi-image analysis attempt $attempt/$maxRetries");
                
                $urls = [
                    'https://generativelanguage.googleapis.com/v1/models/gemini-2.5-flash:generateContent?key=' . $apiKey,
                    'https://generativelanguage.googleapis.com/v1/models/gemini-2.5-flash:generateContent?key=' . $apiKey
                ];
                
                $currentUrl = $urls[0];
                
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $currentUrl,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode($requestData),
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',
                    ],
                    CURLOPT_TIMEOUT => 60,
                    CURLOPT_SSL_VERIFYPEER => false
                ]);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);

                error_log("Multi-image Gemini API attempt $attempt - HTTP code: " . $httpCode);

                if ($httpCode === 200 && $response) {
                    break;
                }
                
                if ($httpCode === 429) {
                    error_log("Rate limit hit (429) on attempt $attempt. Waiting {$retryDelay}s before retry...");
                    if ($attempt < $maxRetries) {
                        sleep($retryDelay);
                        $retryDelay *= 2;
                        continue;
                    } else {
                        error_log("Multi-image analysis failed due to rate limiting. Falling back to single image analysis.");
                        return analyzeImageWithAI($validImages[0]);
                    }
                }
                
                if ($httpCode >= 500 && $httpCode < 600) {
                    error_log("Server error ($httpCode) on attempt $attempt. Retrying...");
                    if ($attempt < $maxRetries) {
                        sleep($retryDelay);
                        $retryDelay *= 2;
                        continue;
                    }
                }
                
                if ($httpCode !== 200) {
                    if ($attempt === $maxRetries) {
                        error_log("Multi-image Gemini API failed after $maxRetries attempts: HTTP $httpCode - " . $response);
                        return analyzeImageWithAI($validImages[0]);
                    }
                }
            }

            $result = json_decode($response, true);
            
            if (!$result || !isset($result['candidates'][0]['content']['parts'][0]['text'])) {
                error_log("Multi-image Gemini API response error: " . $response);
                return ['success' => false, 'message' => 'Invalid API response'];
            }

            $aiText = $result['candidates'][0]['content']['parts'][0]['text'];
            
            $aiText = preg_replace('/```json\s*/', '', $aiText);
            $aiText = preg_replace('/```\s*$/', '', $aiText);
            $aiText = trim($aiText);
            
            $aiData = json_decode($aiText, true);
            
            if (!$aiData) {
                error_log("Multi-image Gemini JSON parse error: " . $aiText);
                return ['success' => false, 'message' => 'Failed to parse AI response'];
            }

            // Chuẩn hóa dữ liệu AI
            $aiData = normalizeAIData($aiData);

            $aiData['analyzed_images_count'] = count($validImages);
            $aiData['image_paths'] = $validImages;

            error_log("Multi-image analysis successful for " . count($validImages) . " images");
            return ['success' => true, 'data' => $aiData];
            
        } catch (Exception $e) {
            error_log("Multi-image Gemini API exception: " . $e->getMessage());
            return ['success' => false, 'message' => 'Multi-image analysis failed: ' . $e->getMessage()];
        }
    }
}

// Fallback function khi multi-image analysis thất bại
if (!function_exists('analyzeSingleImageFallback')) {
    function analyzeSingleImageFallback($imagePath) {
        error_log("Using single image fallback for: " . $imagePath);
        
        $result = analyzeImageWithAI($imagePath);
        
        if ($result['success']) {
            $data = $result['data'];
            $data['analyzed_images_count'] = 1;
            $data['image_paths'] = [$imagePath];
            $data['confidence'] = $data['confidence'] ?? 0.7;
            $data['fallback_mode'] = true;
            
            return ['success' => true, 'data' => $data];
        }
        
        return $result;
    }
}

?>
