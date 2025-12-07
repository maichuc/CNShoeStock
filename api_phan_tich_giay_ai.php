<?php
/**
 * API AI Shoe Analysis
 * Endpoint để phân tích giày với AI
 */

session_start();
header('Content-Type: application/json');

require_once 'config/cau_hinh_csdl.php';
require_once 'classes/DichVuAI.php';
require_once 'classes/GhepSanPhamThongMinh.php';
require_once 'helpers/DichVuTaiAnhLen.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Chưa đăng nhập']);
    exit();
}

$database = new Database();
$pdo = $database->getConnection();
$aiService = new AIService();
$productMatcher = new SmartProductMatcher($pdo);

// Lấy action
$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'health_check':
            echo json_encode(healthCheck($aiService));
            break;
            
        case 'analyze_single':
            echo json_encode(analyzeSingle($aiService));
            break;
            
        case 'analyze_batch':
            echo json_encode(analyzeBatch($aiService, $productMatcher));
            break;
            
        case 'test_api':
            echo json_encode(testAPI($aiService));
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
function healthCheck($aiService) {
    $health = $aiService->healthCheck();
    
    return [
        'success' => true,
        'services' => $health,
        'timestamp' => date('Y-m-d H:i:s')
    ];
}

/**
 * Phân tích 1 ảnh
 */
function analyzeSingle($aiService) {
    // Kiểm tra file upload
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        return [
            'success' => false,
            'message' => 'Không có file ảnh hoặc upload lỗi'
        ];
    }
    
    try {
        // Upload file
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
        $result = $aiService->analyze($imagePath);
        
        $processingTime = round((microtime(true) - $startTime) * 1000);
        
        if (!isset($result['success']) || !$result['success']) {
            return [
                'success' => false,
                'message' => 'AI analysis failed',
                'error' => $result['error'] ?? 'Unknown error',
                'processing_time_ms' => $processingTime
            ];
        }
        
        return [
            'success' => true,
            'result' => $result,
            'image_info' => [
                'filename' => basename($imagePath),
                'path' => $imagePath,
                'size' => filesize($imagePath),
                'url' => str_replace($_SERVER['DOCUMENT_ROOT'], '', $imagePath)
            ],
            'processing_time_ms' => $processingTime,
            'ai_model' => 'gemini-1.5-flash'
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
function analyzeBatch($aiService, $productMatcher) {
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
        
        // Lấy mode phân tích
        $mode = $_POST['mode'] ?? 'hybrid';
        
        // Phân tích batch
        $batchResult = $aiService->analyzeBatch($uploadedPaths, $mode);
        
        // **PHÁT HIỆN VÀ NHÓM DUPLICATE**
        $duplicateDetection = $productMatcher->detectAndGroupDuplicates($batchResult['results']);
        
        // Merge duplicate products
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
            'mode' => $mode,
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
function testAPI($aiService) {
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
    
    $results = [
        'health_check' => $aiService->healthCheck(),
        'test_results' => []
    ];
    
    // Test Gemini
    $geminiTest = $aiService->analyzeWithGemini($testImagePath);
    $results['test_results']['gemini'] = [
        'success' => isset($geminiTest['success']) && $geminiTest['success'],
        'response' => $geminiTest
    ];
    
    return [
        'success' => true,
        'test_completed' => true,
        'results' => $results,
        'timestamp' => date('Y-m-d H:i:s')
    ];
}
