<?php
// Suppress all PHP errors and warnings for clean JSON output
error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');

/**
 * API Chuẩn Hóa Dữ Liệu
 * 
 * Endpoint để frontend gọi chuẩn hóa dữ liệu sản phẩm
 * Thay thế cho normalization-utils.js
 * 
 * Sử dụng: them_san_pham_ai.php, tao_phieu_nhap_moi.php
 * 
 * @version 1.0.0
 * @date 2025-12-08
 */

// session_start();

// Cho phép CORS - Frontend có thể gọi API từ domain khác
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// BƯỚC 2: Xử lý preflight requests
// Browser gửi OPTIONS request trước khi gửi POST/GET thực sự
// Trả về 200 OK để cho phép request tiếp theo
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// BƯỚC 3: Import helper functions
// TroGiupDoTuongDong.php chứa các hàm chuẩn hóa:
// - standardizeProductType(): Chuẩn hóa loại sản phẩm (giày thể thao, giày cao gót, dép...)
// - standardizeColor(): Chuẩn hóa màu sắc (đỏ, xanh, đen trắng...)
// - standardizeBrand(): Chuẩn hóa thương hiệu (Nike, Adidas, Unknown...)
require_once __DIR__ . '/../Helpers/TroGiupDoTuongDong.php';

/**
 * HÀM CHUẨN HÓA DANH SÁCH MÀU SẮC
 * 
 * Mục đích: Chuẩn hóa nhiều màu cùng lúc và loại bỏ từ trùng lặp
 * Tương đương normalizeColors() trong normalization-utils.js (đã bỏ)
 * 
 * Input: 
 * - Array: ["Đỏ", "Xanh Dương"] → Chuẩn hóa từng màu
 * - String: "Đỏ, Xanh Dương" → Tách bằng dấu phẩy rồi chuẩn hóa
 * 
 * Output: Array đã chuẩn hóa, ví dụ: ["Đỏ", "Xanh Dương"]
 * 
 * Xử lý duplicate: "Đen Đen" → "Đen", "Trắng Trắng Kem" → "Trắng Kem"
 */
function normalizeColors($colors) {
    // Kiểm tra input rỗng
    if (empty($colors)) {
        return [];
    }
    
    $colorArray = [];
    
    // Xử lý input dạng array
    if (is_array($colors)) {
        // Chuẩn hóa từng màu trong array
        foreach ($colors as $color) {
            $colorArray[] = standardizeColor($color);
        }
    } elseif (is_string($colors)) {
        // Xử lý input dạng string: "Đỏ, Xanh, Đen"
        // Tách bằng dấu phẩy và loại bỏ khoảng trắng thừa
        $parts = array_map('trim', explode(',', $colors));
        foreach ($parts as $color) {
            if (!empty($color)) {
                $colorArray[] = standardizeColor($color);
            }
        }
    }
    
    // BƯỚC 2: Xóa duplicate words trong mỗi màu
    // Ví dụ: "Đen Đen" → "Đen", "Trắng Trắng Kem" → "Trắng Kem"
    $deduplicatedColors = [];
    
    foreach ($colorArray as $color) {
        // Tách màu thành các từ riêng lẻ bằng khoảng trắng
        $words = preg_split('/\s+/', $color);
        $deduped = [];
        $lastWord = ''; // Lưu từ trước đó để so sánh
        
        // Duyệt qua từng từ
        foreach ($words as $word) {
            // So sánh không phân biệt hoa thường (case-insensitive)
            if (mb_strtolower($word, 'UTF-8') !== mb_strtolower($lastWord, 'UTF-8')) {
                $deduped[] = $word; // Thêm từ nếu khác từ trước
                $lastWord = $word;
            }
            // Bỏ qua nếu từ giống từ trước (duplicate)
        }
        
        // Ghép lại thành chuỗi màu đã loại bỏ duplicate
        $result = implode(' ', $deduped);
        if (!empty($result)) {
            $deduplicatedColors[] = $result;
        }
    }
    
    // Log kết quả để debug
    error_log('Normalized colors: ' . json_encode($colorArray) . ' → ' . json_encode($deduplicatedColors));
    
    return $deduplicatedColors;
}

// ============================================================
// MAIN API HANDLER - XỬ LÝ REQUEST TỪ FRONTEND
// ============================================================
try {
    // BƯỚC 4: Đọc request data từ frontend
    // Frontend gửi JSON qua POST request body
    $input = file_get_contents('php://input');
    $data = json_decode($input, true); // true = convert to associative array
    
    // Kiểm tra JSON có hợp lệ không
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON: ' . json_last_error_msg());
    }
    
    // BƯỚC 5: Lấy action và value từ request
    // action: loại chuẩn hóa cần thực hiện (standardizeProductType, standardizeColor...)
    // value: giá trị cần chuẩn hóa
    $action = $data['action'] ?? '';
    $value = $data['value'] ?? null;
    
    // BƯỚC 6: Khởi tạo cấu trúc response
    // Response sẽ trả về frontend với format chuẩn:
    // {
    //   "success": true,
    //   "action": "standardizeColor",
    //   "original": "do",
    //   "normalized": "Đỏ"
    // }
    $result = [
        'success' => true,
        'action' => $action,
        'original' => $value,
        'normalized' => null // Sẽ được gán giá trị trong switch case
    ];
    
    // BƯỚC 7: Xử lý từng loại action
    switch ($action) {
        // ACTION 1: Chuẩn hóa loại sản phẩm
        // Input: "giay the thao", "cao got" → Output: "Giày Thể Thao", "Giày Cao Gót"
        case 'standardizeProductType':
            if (empty($value)) {
                $result['normalized'] = '';
            } else {
                $result['normalized'] = standardizeProductType($value);
            }
            break;
            
        // ACTION 2: Chuẩn hóa màu sắc đơn
        // Input: "do", "xanh duong" → Output: "Đỏ", "Xanh Dương"
        case 'standardizeColor':
            if (empty($value)) {
                $result['normalized'] = '';
            } else {
                $result['normalized'] = standardizeColor($value);
            }
            break;
            
        // ACTION 3: Chuẩn hóa thương hiệu
        // Input: "nike", "adidas", "" → Output: "Nike", "Adidas", "Unknown"
        case 'standardizeBrand':
            if (empty($value)) {
                $result['normalized'] = 'Unknown'; // Default brand nếu để trống
            } else {
                $result['normalized'] = standardizeBrand($value);
            }
            break;
            
        // ACTION 4: Chuẩn hóa nhiều màu cùng lúc
        // Input: ["do", "xanh"] hoặc "do, xanh" → Output: ["Đỏ", "Xanh"]
        case 'normalizeColors':
            $result['normalized'] = normalizeColors($value);
            break;
            
        // ACTION 5: Batch processing - Chuẩn hóa nhiều field cùng lúc
        // Tiết kiệm số lần gọi API (1 request thay vì 3-4 requests)
        // Input: {"type": "giay the thao", "brand": "nike", "colors": ["do", "xanh"]}
        // Output: {"type": "Giày Thể Thao", "brand": "Nike", "colors": ["Đỏ", "Xanh"]}
        case 'batch':
            $batchData = $data['data'] ?? []; // Lấy data object từ request
            $normalized = [];
            
            // Chuẩn hóa loại sản phẩm nếu có
            if (!empty($batchData['type'])) {
                $normalized['type'] = standardizeProductType($batchData['type']);
            }
            
            // Chuẩn hóa thương hiệu nếu có
            if (!empty($batchData['brand'])) {
                $normalized['brand'] = standardizeBrand($batchData['brand']);
            }
            
            // Chuẩn hóa danh sách màu (array) nếu có
            if (!empty($batchData['colors'])) {
                $normalized['colors'] = normalizeColors($batchData['colors']);
            }
            
            // Chuẩn hóa màu đơn nếu có
            if (!empty($batchData['color'])) {
                $normalized['color'] = standardizeColor($batchData['color']);
            }
            
            $result['normalized'] = $normalized;
            break;
            
        // ACTION không hợp lệ - throw error
        default:
            throw new Exception("Unknown action: $action");
    }
    
    // BƯỚC 8: Trả về response JSON cho frontend
    // JSON_UNESCAPED_UNICODE: Không escape ký tự tiếng Việt (Đỏ thay vì \u0110\u1ecf)
    // JSON_PRETTY_PRINT: Format JSON dễ đọc (có indent và xuống dòng)
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    // BƯỚC 9: Xử lý lỗi nếu có exception
    // Trả về HTTP 400 Bad Request
    http_response_code(400);
    
    // Response lỗi cho frontend
    // Frontend sẽ kiểm tra success === false và hiển thị error message
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    
    // Log lỗi vào error_log để developer debug
    // Log file location: xampp/apache/logs/error.log
    error_log('API Normalization Error: ' . $e->getMessage());
}
?>
