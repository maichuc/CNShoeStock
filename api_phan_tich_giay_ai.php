<?php
/**
 * API AI Shoe Analysis
 * Endpoint để phân tích giày với AI
 */

session_start();
header('Content-Type: application/json');

require_once 'config/database.php';
require_once 'helpers/TroGiupPhanTichAI.php';
require_once 'helpers/TroGiupDoTuongDong.php'; // Centralized normalization functions
require_once 'classes/GhepSanPhamThongMinh.php';
require_once 'helpers/DichVuTaiAnhLen.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Chưa đăng nhập']);
    exit();
}

// Load environment variables
loadEnvironmentVariables();

$database = new Database();
$pdo = $database->getConnection();
$productMatcher = new SmartProductMatcher($pdo);

// Lấy action
$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'health_check':
            echo json_encode(healthCheck());
            break;
            
        case 'analyze_single':
            echo json_encode(analyzeSingle());
            break;
            
        case 'analyze_batch':
            echo json_encode(analyzeBatch($productMatcher));
            break;
            
        case 'test_api':
            echo json_encode(testAPI());
            break;
            
        default:
            echo json_encode([
                'success' => false,
                'message' => 'Invalid action',
                'available_actions' => ['health_check', 'analyze_single', 'analyze_batch', 'test_api']
            ]);
    }
} catch (Exception $e) {
    error_log("API AI Analysis Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Có lỗi xảy ra: ' . $e->getMessage()
    ]);
}

/**
 * Kiểm tra health của AI services
 */
function healthCheck() {
    $apiKey = getenv('GEMINI_API_KEY');
    
    return [
        'success' => true,
        'services' => [
            'gemini' => [
                'status' => !empty($apiKey) ? 'configured' : 'not_configured',
                'api_key' => !empty($apiKey),
                'service' => 'Gemini 2.5 Flash'
            ]
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ];
}

/**
 * Phân tích 1 ảnh
 */
function analyzeSingle() {
    // Kiểm tra file upload
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        return [
            'success' => false,
            'message' => 'Không có file ảnh hoặc upload lỗi'
        ];
    }
    
    try {
        // Tải lên file
        $uploadService = new ImageUploadService();
        $uploadResult = $uploadService->upload($_FILES['image'], 'temp');
        
        if (!$uploadResult['success']) {
            return [
                'success' => false,
                'message' => 'Upload failed: ' . ($uploadResult['message'] ?? 'Unknown error')
            ];
        }
        
        $imagePath = $uploadResult['path'];
        
        // Phân tích với Gemini AI
        $startTime = microtime(true);
        $result = analyzeImageWithAI($imagePath);
        
        $processingTime = round((microtime(true) - $startTime) * 1000);
        
        if (!$result['success']) {
            return [
                'success' => false,
                'message' => 'AI analysis failed',
                'error' => $result['message'] ?? 'Unknown error',
                'processing_time_ms' => $processingTime
            ];
        }
        
        return [
            'success' => true,
            'result' => $result['data'],
            'image_info' => [
                'filename' => basename($imagePath),
                'path' => $imagePath,
                'size' => filesize($imagePath),
                'url' => str_replace($_SERVER['DOCUMENT_ROOT'], '', $imagePath)
            ],
            'processing_time_ms' => $processingTime,
            'ai_model' => 'gemini-2.5-flash'
        ];
        
    } catch (Exception $e) {
        error_log("Analyze Single Error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Phân tích nhiều ảnh
 */
function analyzeBatch($productMatcher) {
    // Kiểm tra files upload
    if (!isset($_FILES['images']) || empty($_FILES['images']['name'])) {
        return [
            'success' => false,
            'message' => 'Không có file ảnh nào được upload'
        ];
    }
    
    try {
        $uploadService = new ImageUploadService();
        $uploadedPaths = [];
        
        // Upload tất cả files
        $fileCount = count($_FILES['images']['name']);
        
        for ($i = 0; $i < $fileCount; $i++) {
            $file = [
                'name' => $_FILES['images']['name'][$i],
                'type' => $_FILES['images']['type'][$i],
                'tmp_name' => $_FILES['images']['tmp_name'][$i],
                'error' => $_FILES['images']['error'][$i],
                'size' => $_FILES['images']['size'][$i]
            ];
            
            if ($file['error'] !== UPLOAD_ERR_OK) {
                continue;
            }
            
            $uploadResult = $uploadService->upload($file, 'temp');
            
            if ($uploadResult['success']) {
                $uploadedPaths[] = $uploadResult['path'];
            }
        }
        
        if (empty($uploadedPaths)) {
            return [
                'success' => false,
                'message' => 'Không có ảnh nào được upload thành công'
            ];
        }
        
        // Phân tích batch với multi-image analysis
        $startTime = microtime(true);
        $aiResult = analyzeMultipleImagesWithGemini($uploadedPaths);
        $processingTime = round((microtime(true) - $startTime) * 1000);
        
        if (!$aiResult['success']) {
            return [
                'success' => false,
                'message' => 'AI analysis failed: ' . ($aiResult['message'] ?? 'Unknown error')
            ];
        }
        
        // Format kết quả giống analyzeBatch() cũ
        $results = [[
            'success' => true,
            'data' => $aiResult['data'],
            'image' => basename($uploadedPaths[0]),
            'image_path' => $uploadedPaths[0],
            'confidence' => $aiResult['data']['confidence'] ?? 0.8
        ]];
        
        $batchResult = [
            'total_images' => count($uploadedPaths),
            'results' => $results,
            'summary' => [
                'success_rate' => 100,
                'average_confidence' => $aiResult['data']['confidence'] ?? 0.8,
                'total_processing_time_ms' => $processingTime,
                'analyzed_images_count' => $aiResult['data']['analyzed_images_count'] ?? count($uploadedPaths)
            ]
        ];
        
        // **PHÁT HIỆN VÀ NHÓM DUPLICATE**
        $duplicateDetection = $productMatcher->detectAndGroupDuplicates($batchResult['results']);
        
        // Gộp duplicate products
        $mergedProducts = $productMatcher->mergeDuplicates($duplicateDetection['all_results'], 'sum_quantity');
        
        // Tìm sản phẩm tương tự trong database
        foreach ($mergedProducts as &$product) {
            if ($product['success'] ?? false) {
                $similarInDB = $productMatcher->findSimilarInDatabase($product);
                $product['similar_in_database'] = $similarInDB;
                $product['exists_in_database'] = !empty($similarInDB);
            }
        }
        
        return [
            'success' => true,
            'batch_result' => [
                'total_images' => $batchResult['total_images'],
                'results' => $mergedProducts,
                'summary' => array_merge($batchResult['summary'], [
                    'duplicate_detection' => $duplicateDetection['duplicate_summary']
                ])
            ],
            'duplicate_info' => $duplicateDetection['duplicate_summary'],
            'grouped_results' => $duplicateDetection['grouped_results'],
            'images_processed' => count($uploadedPaths)
        ];
        
    } catch (Exception $e) {
        error_log("Analyze Batch Error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Test API connectivity
 */
function testAPI() {
    // Test với sample image (tạo ảnh test đơn giản)
    $testImagePath = __DIR__ . '/uploads/temp/test_shoe.jpg';
    
    // Nếu không có test image, tạo một ảnh trắng đơn giản
    if (!file_exists($testImagePath)) {
        $img = imagecreatetruecolor(200, 200);
        $white = imagecolorallocate($img, 255, 255, 255);
        imagefill($img, 0, 0, $white);
        
        $dir = dirname($testImagePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        imagejpeg($img, $testImagePath);
        imagedestroy($img);
    }
    
    $apiKey = getenv('GEMINI_API_KEY');
    
    $results = [
        'health_check' => [
            'gemini' => [
                'status' => !empty($apiKey) ? 'configured' : 'not_configured',
                'api_key' => !empty($apiKey),
                'service' => 'Gemini 2.5 Flash'
            ]
        ],
        'test_results' => []
    ];
    
    // Kiểm tra Gemini
    $geminiTest = analyzeImageWithGemini($testImagePath);
    $results['test_results']['gemini'] = [
        'success' => $geminiTest['success'] ?? false,
        'response' => $geminiTest
    ];
    
    return [
        'success' => true,
        'test_completed' => true,
        'results' => $results,
        'timestamp' => date('Y-m-d H:i:s')
    ];
}

// ============================================================================
// HELPER FUNCTIONS (Use centralized functions from TroGiupDoTuongDong.php)
// standardizeBrand(), standardizeColor(), standardizeProductType() are now
// imported from helpers/TroGiupDoTuongDong.php to eliminate code duplication
// ============================================================================

/**
 * Chuẩn hóa output từ AI để tăng tính nhất quán
 * Note: Uses standardizeBrand(), standardizeProductType() from helper
 */
function normalizeAIOutput($aiData) {
    // 1. CHUẨN HÓA CATEGORY/TYPE - Dùng hàm từ helper
    if (isset($aiData['category'])) {
        $aiData['category'] = standardizeProductType($aiData['category']);
        $aiData['type'] = $aiData['category'];
    }
    
    if (isset($aiData['type'])) {
        $aiData['type'] = standardizeProductType($aiData['type']);
    }
    
    // 2. Chuẩn hóa brand
    if (isset($aiData['brand']) && !empty($aiData['brand'])) {
        $aiData['brand'] = standardizeBrand($aiData['brand']);
    } else {
        $aiData['brand'] = 'Unknown';
    }
    
    // 3. Chuẩn hóa màu sắc - Dùng hàm từ helper
    if (isset($aiData['color']) && !empty($aiData['color'])) {
        $originalColor = $aiData['color'];
        // Loại bỏ phần trong ngoặc (nếu có)
        $colorString = preg_replace('/\s*\([^)]*\)/u', '', $aiData['color']);
        
        $colors = explode(',', $colorString);
        $normalizedColors = [];
        
        foreach ($colors as $color) {
            $colorTrimmed = trim($color);
            if (empty($colorTrimmed)) continue;
            
            $normalized = standardizeColor($colorTrimmed);
            $normalizedColors[] = $normalized;
        }
        
        $normalizedColors = array_unique($normalizedColors);
        $aiData['color'] = implode(', ', $normalizedColors);
        
        error_log("Color normalized: '$originalColor' → '" . $aiData['color'] . "'");
    }
    
    // 4. Log normalization
    error_log("AI Output normalized:");
    error_log("  - Type: " . ($aiData['type'] ?? 'N/A'));
    error_log("  - Category: " . ($aiData['category'] ?? 'N/A'));
    error_log("  - Color: " . ($aiData['color'] ?? 'N/A'));
    error_log("  - Brand: " . ($aiData['brand'] ?? 'N/A'));
    
    return $aiData;
}
