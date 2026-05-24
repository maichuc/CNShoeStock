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
 * Sử dụng EnvLoader tập trung của project
 */
require_once __DIR__ . '/../../env_loader.php';

if (!function_exists('loadEnvironmentVariables')) {
    function loadEnvironmentVariables() {
        // Wrapper for backward compatibility if needed
        // EnvLoader::load() is already called in env_loader.php
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
        return standardizeColor($color);
    }
}

/**
 * HÀM CHUẨN HÓA DỮ LIỆU TỪ AI
 * 
 * Mục đích: Xử lý và chuẩn hóa dữ liệu sau khi nhận từ AI (Gemini)
 * 
 * @param array $aiData - Dữ liệu thô từ AI
 * @return array - Dữ liệu đã chuẩn hóa
 */
if (!function_exists('normalizeAIData')) {
    function normalizeAIData($aiData) {
        // 1. Chuẩn hóa Brand
        if (isset($aiData['brand'])) {
            $aiData['brand'] = standardizeBrand($aiData['brand']);
        }
        
        // 2. Chuẩn hóa Type/Category
        if (isset($aiData['type'])) {
            $aiData['type'] = standardizeProductType($aiData['type']);
        }
        if (isset($aiData['category'])) {
            $aiData['category'] = standardizeProductType($aiData['category']);
        }

        // 3. Chuẩn hóa màu sắc
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

/**
 * HÀM PHÂN TÍCH ẢNH ĐƠN VỚI GEMINI API
 * 
 * Mục đích: Gửi 1 ảnh lên Gemini AI để nhận diện thông tin sản phẩm
 * 
 * Quy trình:
 * 1. Kiểm tra API key có tồn tại không
 * 2. Đọc file ảnh và chuyển sang base64
 * 3. Tạo prompt yêu cầu AI phân tích
 * 4. Gửi request lên Gemini API
 * 5. Xử lý response và parse JSON
 * 6. Chuẩn hóa dữ liệu
 * 
 * @param string $imagePath - Đường dẫn đến file ảnh
 * @return array - ['success' => bool, 'data' => array, 'message' => string]
 */
if (!function_exists('analyzeImageWithGemini')) {
    function analyzeImageWithGemini($imagePath) {
        // BƯỚC 1: KIỂM TRA API KEY VÀ MODEL
        $apiKey = env('GEMINI_API_KEY');
        $model = env('GEMINI_MODEL', 'gemini-2.5-flash'); // Default to 2.5-flash
        
        // Nếu không có API key → Không thể gọi AI
        if (!$apiKey) {
            return ['success' => false, 'message' => 'API AI không được cấu hình. Vui lòng nhập thông tin sản phẩm thủ công.'];
        }
        
        // BƯỚC 2: KIỂM TRA FILE ẢNH TỒN TẠI
        if (!file_exists($imagePath)) {
            return ['success' => false, 'message' => 'Không tìm thấy file ảnh. Vui lòng tải lại ảnh.'];
        }
        
        try {
            // BƯỚC 3: CHUẨN BỊ DỮ LIỆU ẢNH
            // - Đọc file ảnh thành binary
            // - Encode sang base64 để gửi qua API
            $imageData = base64_encode(file_get_contents($imagePath));
            
            // Lấy mime type (image/jpeg, image/png, image/webp...)
            $mimeType = mime_content_type($imagePath);
            
            // BƯỚC 4: TẠO PROMPT CHO AI
            // Hướng dẫn AI phân tích ảnh và trả về JSON với format cụ thể
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
  \"features\": \"Tính năng đặc biệt (thoáng khí, chống nước, etc.)\",
  \"style\": \"Phong cách (Cổ điển, Hiện đại, Thể thao, etc.)\",
  \"confidence\": 0.9
}

Chỉ trả về JSON, không thêm text khác.";

            // BƯỚC 5: TẠO REQUEST DATA
            // Cấu trúc theo format của Gemini API:
            // - contents: Nội dung gửi lên AI
            //   - parts: Các phần (text prompt + image data)
            $requestData = [
                'contents' => [
                    [
                        'parts' => [
                            [
                                'text' => $prompt  // Phần 1: Text prompt
                            ],
                            [
                                'inline_data' => [  // Phần 2: Image data
                                    'mime_type' => $mimeType,
                                    'data' => $imageData  // Base64 encoded
                                ]
                            ]
                        ]
                    ]
                ]
            ];

            // BƯỚC 6: RETRY MECHANISM (Cơ chế thử lại)
            // Nếu API lỗi → Thử lại tối đa 2 lần
            // Tránh lỗi tạm thời (network issues, rate limit...)
            $maxRetries = 2;       // Số lần thử lại tối đa
            $retryDelay = 1;       // Delay giữa các lần thử (giây)
            
            for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
                // Khởi tạo cURL session
                $ch = curl_init();
                
                // Cấu hình cURL options
                curl_setopt_array($ch, [
                    // URL: Gemini API endpoint
                    CURLOPT_URL => "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . $apiKey,
                    CURLOPT_RETURNTRANSFER => true,           // Trả về response thay vì print
                    CURLOPT_POST => true,                     // Sử dụng POST method
                    CURLOPT_POSTFIELDS => json_encode($requestData),  // Body data (JSON)
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json'      // Header: JSON content
                    ],
                    CURLOPT_TIMEOUT => 30,                    // Timeout 30s
                    CURLOPT_SSL_VERIFYPEER => false          // Bỏ qua SSL verify (dev environment)
                ]);

                // Thực thi request
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);  // Lấy HTTP status code
                $curlError = curl_error($ch);                        // Lấy error message nếu có
                curl_close($ch);  // Đóng connection

                // XỬ LÝ LỖI CURL (Connection errors)
                if ($curlError) {
                    error_log("Gemini API curl error on attempt $attempt: " . $curlError);
                    // Nếu đã thử max lần → Trả về lỗi
                    if ($attempt === $maxRetries) {
                        return ['success' => false, 'message' => 'Connection error: ' . $curlError];
                    }
                    // Chưa max → Thử lại
                    continue;
                }

                // HTTP 200 = Success → Thoát khỏi retry loop
                if ($httpCode === 200) {
                    break;
                }
                
                // XỬ LÝ CÁC LỖI API THƯỜNG GẶP
                // - 429: Rate limit (gọi quá nhiều request)
                // - 403: Forbidden (API key không hợp lệ)
                // - 404: Not found (endpoint sai)
                // - 402: Payment required (hết quota)
                if ($httpCode === 429) {
                    $errorMsg = 'API AI hết hạn mức (Rate limit). Vui lòng đổi API Key trong file .env hoặc thử lại sau.';
                } elseif ($httpCode === 403) {
                    $errorMsg = 'API Key không hợp lệ hoặc không có quyền truy cập. Vui lòng kiểm tra lại cấu hình .env.';
                } elseif ($httpCode === 402) {
                    $errorMsg = 'Tài khoản AI hết số dư hoặc quota. Vui lòng kiểm tra tài khoản Google Cloud.';
                } else {
                    $errorMsg = 'API AI hiện không khả dụng (Lỗi HTTP ' . $httpCode . ').';
                }

                if ($httpCode === 429 || $httpCode === 403 || $httpCode === 404 || $httpCode === 402) {
                    error_log("API issue ($httpCode) on attempt $attempt - $errorMsg");
                    if ($attempt === $maxRetries) {
                        return ['success' => false, 'message' => $errorMsg . ' Vui lòng nhập thông tin sản phẩm thủ công.'];
                    }
                }

                if ($attempt === $maxRetries) {
                    error_log("Single image Gemini API failed after $maxRetries attempts: HTTP $httpCode - " . $response);
                    return ['success' => false, 'message' => 'API AI không phản hồi sau ' . $maxRetries . ' lần thử. Vui lòng nhập thông tin sản phẩm thủ công.'];
                }
            }

            // BƯỚC 7: PARSE RESPONSE
            // Decode JSON response từ Gemini
            $result = json_decode($response, true);
            
            // Kiểm tra cấu trúc response có hợp lệ không
            // Format: candidates[0].content.parts[0].text
            if (!$result || !isset($result['candidates'][0]['content']['parts'][0]['text'])) {
                error_log("Gemini API response error: " . $response);
                return ['success' => false, 'message' => 'Invalid API response'];
            }

            // Lấy text response từ AI (chứa JSON data)
            $aiText = $result['candidates'][0]['content']['parts'][0]['text'];
            
            // BƯỚC 8: CLEAN UP RESPONSE
            // AI đôi khi trả về JSON wrapped trong markdown code block
            // Ví dụ: ```json\n{...}\n```
            // → Xóa ```json và ``` để lấy JSON thuần
            $aiText = preg_replace('/```json\s*/', '', $aiText);  // Xóa ```json ở đầu
            $aiText = preg_replace('/```\s*$/', '', $aiText);      // Xóa ``` ở cuối
            $aiText = trim($aiText);  // Loại bỏ khoảng trắng thừa
            
            // BƯỚC 9: PARSE JSON DATA
            // Chuyển text JSON thành array PHP
            $aiData = json_decode($aiText, true);
            
            // Kiểm tra parse có thành công không
            if (!$aiData) {
                error_log("Gemini JSON parse error: " . $aiText);
                return ['success' => false, 'message' => 'Failed to parse AI response'];
            }

            // BƯỚC 10: CHUẨN HÓA DỮ LIỆU
            // Gọi function normalizeAIData() để:
            // - Dịch màu sang tiếng Việt
            // - Xóa dữ liệu trùng lặp
            // - Sửa lỗi phân loại
            $aiData = normalizeAIData($aiData);

            // Trả về kết quả thành công
            return ['success' => true, 'data' => $aiData];
            
        } catch (Exception $e) {
            error_log("Gemini API exception: " . $e->getMessage());
            return ['success' => false, 'message' => 'Analysis failed: ' . $e->getMessage()];
        }
    }
}

/**
 * HÀM PHÂN TÍCH ẢNH CHÍNH (WRAPPER)
 * 
 * Mục đích: Function wrapper để gọi Gemini API
 * 
 * @param string $imagePath - Đường dẫn file ảnh
 * @return array - Kết quả phân tích
 */
if (!function_exists('analyzeImageWithAI')) {
    function analyzeImageWithAI($imagePath) {
        // Kiểm tra demo mode trước
        if (env('AI_DEMO_MODE') === 'true') {
            error_log("AI Demo Mode is ON. Returning mock data.");
            return ['success' => true, 'data' => getAIDemoData(), 'is_demo' => true];
        }

        // Gọi Gemini API để phân tích ảnh
        error_log("Analyzing image with Gemini API: " . $imagePath);
        $geminiResult = analyzeImageWithGemini($imagePath);
        
        if ($geminiResult['success']) {
            error_log("Gemini API analysis successful");
            return $geminiResult;
        }
        
        // Nếu Gemini thất bại
        error_log("Gemini API failed: " . $geminiResult['message']);
        
        // Nếu API lỗi mà demo mode đang OFF, nhưng user muốn có fallback an toàn? 
        // Thường thì chúng ta trả về lỗi để user biết, nhưng nếu cần demo thì trả về demo.
        return [
            'success' => false, 
            'message' => 'Lỗi phân tích AI: ' . $geminiResult['message']
        ];
    }
}

/**
 * HÀM PHÂN TÍCH NHIỀU ẢNH CÙNG LÚC VỚI GEMINI API
 * 
 * Mục đích: Gửi 2-3 ảnh cùng lúc để AI phân tích tổng hợp
 * Ưu điểm: AI thấy nhiều góc độ → Kết quả chính xác hơn
 * 
 * Quy trình:
 * 1. Kiểm tra API key
 * 2. Validate các file ảnh
 * 3. Tạo prompt phân tích nhiều ảnh
 * 4. Encode tất cả ảnh sang base64
 * 5. Gửi 1 request với nhiều ảnh
 * 6. Xử lý response tổng hợp
 * 7. Chuẩn hóa dữ liệu
 * 
 * @param array $imagePaths - Mảng đường dẫn các file ảnh
 * @return array - Kết quả phân tích tổng hợp
 */
if (!function_exists('analyzeMultipleImagesWithGemini')) {
    function analyzeMultipleImagesWithGemini($imagePaths) {
        // Kiểm tra demo mode trước
        if (env('AI_DEMO_MODE') === 'true') {
            error_log("AI Demo Mode (Multi) is ON. Returning mock data.");
            $demoData = getAIDemoData();
            $demoData['analyzed_images_count'] = count($imagePaths);
            $demoData['image_paths'] = $imagePaths;
            return ['success' => true, 'data' => $demoData, 'is_demo' => true];
        }

        // BƯỚC 1: KIỂM TRA API KEY VÀ MODEL
        $apiKey = env('GEMINI_API_KEY');
        $model = env('GEMINI_MODEL', 'gemini-2.5-flash');
        if (!$apiKey) {
            return ['success' => false, 'message' => 'Gemini API key not configured'];
        }
        
        // Delay 500ms để tránh rate limit (gọi quá nhanh)
        usleep(500000);
        
        // BƯỚC 2: VALIDATE CÁC FILE ẢNH
        // Kiểm tra từng file có tồn tại không
        // Bỏ qua file không tồn tại
        $validImages = [];
        foreach ($imagePaths as $imagePath) {
            if (file_exists($imagePath)) {
                $validImages[] = $imagePath;  // Thêm vào danh sách hợp lệ
            } else {
                // Log warning nhưng vẫn tiếp tục với các ảnh khác
                error_log("Image file not found: " . $imagePath);
            }
        }
        
        // Nếu không có ảnh nào hợp lệ → Trả về lỗi
        if (empty($validImages)) {
            return ['success' => false, 'message' => 'No valid images found'];
        }
        
        try {
            error_log("Analyzing " . count($validImages) . " images with Gemini");
            
            // BƯỚC 3: TẠO REQUEST PARTS
            // Mảng chứa: prompt text + nhiều ảnh
            $parts = [];           
            // BƯỚC 4: TẠO PROMPT CHO PHÂN TÍCH NHIỀU ẢNH
            // - Trả về JSON tổng hợp
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

            // Thêm prompt text vào parts (phần đầu)
            $parts[] = ['text' => $prompt];
            
            // BƯỚC 5: THÊM TẤT CẢ ẢNH VÀO PARTS
            // Lặp qua từng ảnh và encode
            foreach ($validImages as $index => $imagePath) {
                // Đọc file và encode sang base64
                $imageData = base64_encode(file_get_contents($imagePath));
                $mimeType = mime_content_type($imagePath);
                
                // Thêm ảnh vào parts\\Cấu trúc gửi
                $parts[] = [
                    'inline_data' => [
                        'mime_type' => $mimeType,  // image/jpeg, image/png...
                        'data' => $imageData        // Base64 string
                    ]
                ];
                
                error_log("Added image " . ($index + 1) . " for analysis: " . $imagePath);
            }

            // BƯỚC 6: TẠO REQUEST DATA 
            $requestData = [
                'contents' => [
                    [
                        'parts' => $parts  // Gồm: 1 text prompt + nhiều ảnh
                    ]
                ],
                // CẤU HÌNH GENERATION (Tham số AI)
                'generationConfig' => [
                    'temperature' => 0.0,        // 0 = Kết quả nhất quán (không random)
                    'topK' => 1,                 // Chỉ chọn token có xác suất cao nhất
                    'topP' => 1,                 // Nucleus sampling = 100%
                    'maxOutputTokens' => 4096    // Giới hạn độ dài response
                ]
            ];

            // BƯỚC 7: RETRY MECHANISM (Cơ chế thử lại)
            // Multi-image analysis cần retry nhiều hơn vì:
            // - Request lớn hơn (nhiều ảnh)
            // - Xử lý lâu hơn
            // - Dễ bị rate limit hơn
            $maxRetries = 3;       // Thử tối đa 3 lần
            $retryDelay = 1;       // Delay ban đầu 1 giây
            
            for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
                error_log("Multi-image analysis attempt $attempt/$maxRetries");
                
                // Danh sách URLs dự phòng
                $urls = [
                    "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . $apiKey,
                    "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . $apiKey
                ];
                
                // Chọn URL đầu tiên
                $currentUrl = $urls[0];
                
                // Khởi tạo cURL session
                $ch = curl_init();
                
                // Cấu hình cURL cho multi-image request
                curl_setopt_array($ch, [
                    CURLOPT_URL => $currentUrl,                              // URL Gemini API
                    CURLOPT_RETURNTRANSFER => true,                          // Trả về response
                    CURLOPT_POST => true,                                    // POST method
                    CURLOPT_POSTFIELDS => json_encode($requestData),         // Body JSON (chứa nhiều ảnh)
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',                    // Header JSON
                    ],
                    CURLOPT_TIMEOUT => 60,                                   // Timeout 60s (lâu hơn single image vì nhiều ảnh)
                    CURLOPT_SSL_VERIFYPEER => false                          // Bỏ qua SSL verify
                ]);

                // Thực thi request
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);  // Lấy HTTP status code
                $curlError = curl_error($ch);                        // Lấy error message
                curl_close($ch);  // Đóng connection

                error_log("Multi-image Gemini API attempt $attempt - HTTP code: " . $httpCode);

                // XỬ LÝ SUCCESS CASE
                // HTTP 200 và có response → Thành công, thoát retry loop
                if ($httpCode === 200 && $response) {
                    break;  // Thoát vòng lặp, tiếp tục xử lý response
                }
                
                // XỬ LÝ RATE LIMIT (429 - Too Many Requests)
                // Xảy ra khi gọi API quá nhanh/nhiều
                if ($httpCode === 429) {
                    error_log("Rate limit hit (429) on attempt $attempt. Waiting {$retryDelay}s before retry...");
                    
                    if ($attempt < $maxRetries) {
                        // Chưa hết lần thử → Chờ và thử lại
                        sleep($retryDelay);        // Ngủ delay giây
                        $retryDelay *= 2;          // Tăng gấp đôi delay (exponential backoff: 1s → 2s → 4s)
                        continue;                  // Tiếp tục vòng lặp
                    } else {
                        // Đã hết lần thử → Fallback về single image
                        error_log("Multi-image analysis failed due to rate limiting. Falling back to single image analysis.");
                        return analyzeImageWithAI($validImages[0]);  // Phân tích chỉ ảnh đầu tiên
                    }
                }
                
                // XỬ LÝ SERVER ERROR (500-599)
                // Lỗi từ phía Gemini server
                if ($httpCode >= 500 && $httpCode < 600) {
                    error_log("Server error ($httpCode) on attempt $attempt. Retrying...");
                    
                    if ($attempt < $maxRetries) {
                        // Chưa hết lần thử → Chờ và thử lại
                        sleep($retryDelay);
                        $retryDelay *= 2;          // Exponential backoff
                        continue;
                    }
                    // Nếu hết lần thử → Tiếp tục xuống dưới để fallback
                }
                
                // XỬ LÝ CÁC LỖI KHÁC (không phải 200)
                if ($httpCode !== 200) {
                    if ($attempt === $maxRetries) {
                        // Đã thử max lần mà vẫn lỗi → Fallback về single image
                        error_log("Multi-image Gemini API failed after $maxRetries attempts: HTTP $httpCode - " . $response);
                        return analyzeImageWithAI($validImages[0]);  // Phân tích ảnh đầu tiên
                    }
                    // Chưa hết lần thử → Tiếp tục loop
                }
            }

            // BƯỚC 8: PARSE RESPONSE JSON
            // Chuyển response string thành array PHP
            $result = json_decode($response, true);
            
            // Kiểm tra cấu trúc response hợp lệ
            // Format: candidates[0].content.parts[0].text
            if (!$result || !isset($result['candidates'][0]['content']['parts'][0]['text'])) {
                error_log("Multi-image Gemini API response error: " . $response);
                return ['success' => false, 'message' => 'Invalid API response'];
            }

            // Lấy text chứa JSON data từ AI
            $aiText = $result['candidates'][0]['content']['parts'][0]['text'];
            
            // BƯỚC 9: CLEAN UP MARKDOWN CODE BLOCK
            // AI có thể wrap JSON trong ```json ... ```
            // → Xóa các ký tự markdown này
            $aiText = preg_replace('/```json\s*/', '', $aiText);  // Xóa ```json ở đầu
            $aiText = preg_replace('/```\s*$/', '', $aiText);      // Xóa ``` ở cuối
            $aiText = trim($aiText);  // Loại bỏ khoảng trắng thừa
            
            // BƯỚC 10: PARSE JSON DATA
            // Chuyển text JSON thành array PHP
            $aiData = json_decode($aiText, true);
            
            // Kiểm tra parse thành công chưa
            if (!$aiData) {
                error_log("Multi-image Gemini JSON parse error: " . $aiText);
                return ['success' => false, 'message' => 'Failed to parse AI response'];
            }

            // BƯỚC 11: CHUẨN HÓA DỮ LIỆU
            // Gọi normalizeAIData() để:
            // - Dịch màu sang tiếng Việt
            // - Xóa dữ liệu trùng lặp
            // - Sửa lỗi phân loại
            // - Format array/string
            $aiData = normalizeAIData($aiData);

            // BƯỚC 12: THÊM METADATA
            // Thêm thông tin về số ảnh đã phân tích
            $aiData['analyzed_images_count'] = count($validImages);  // Số ảnh đã dùng
            $aiData['image_paths'] = $validImages;                   // Danh sách đường dẫn ảnh

            // Log thành công
            error_log("Multi-image analysis successful for " . count($validImages) . " images");
            
            // Trả về kết quả thành công
            return ['success' => true, 'data' => $aiData];
            
        } catch (Exception $e) {
            // BƯỚC 13: XỬ LÝ EXCEPTION
            // Nếu có lỗi ngoại lệ (exception) trong quá trình xử lý
            error_log("Multi-image Gemini API exception: " . $e->getMessage());
            return ['success' => false, 'message' => 'Multi-image analysis failed: ' . $e->getMessage()];
        }
    }
}
/**
 * HÀM CUNG CẤP DỮ LIỆU DEMO (MOCK DATA)
 * 
 * Mục đích: Trả về dữ liệu mẫu khi không có API key hoặc API lỗi
 */
if (!function_exists('getAIDemoData')) {
    function getAIDemoData() {
        $demoProducts = [
            [
                'name' => 'Giày Sneaker Lecos Heritage White',
                'brand' => 'Lecos',
                'type' => 'Sneaker',
                'color' => 'Trắng, Xám',
                'description' => 'Mẫu sneaker phong cách cổ điển với chất liệu da cao cấp, phù hợp cho mọi hoạt động hàng ngày.',
                'material' => 'Da bò thật, Đế cao su',
                'features' => 'Thoáng khí, Đệm êm ái',
                'tags' => 'sneaker, lecos, heritage, classic',
                'confidence' => 0.95
            ],
            [
                'name' => 'Giày Cao Gót Mũi Nhọn Elegant Black',
                'brand' => 'Lecos',
                'type' => 'Giày cao gót',
                'color' => 'Đen',
                'description' => 'Giày cao gót 7cm thiết kế sang trọng, tôn dáng, chất liệu da bóng cao cấp.',
                'material' => 'Da bóng (Patent Leather)',
                'features' => 'Đế chống trượt, Gót chắc chắn',
                'tags' => 'cao gót, office, party, elegant',
                'confidence' => 0.92
            ]
        ];
        
        // Trả về ngẫu nhiên 1 trong các mẫu demo
        return $demoProducts[array_rand($demoProducts)];
    }
}

?>
