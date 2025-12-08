<?php
/**
 * API Chuẩn Hóa Dữ Liệu
 * 
 * Endpoint để frontend gọi chuẩn hóa dữ liệu sản phẩm
 * Thay thế cho normalization-utils.js
 * 
 * Sử dụng: them_san_pham_ai.php, tao_phieu_nhap_moi.php
 * 
 * @version 1.0.0
 * @date 2024-12-08
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Import helper functions
require_once __DIR__ . '/helpers/TroGiupDoTuongDong.php';

/**
 * Chuẩn hóa danh sách màu sắc
 * Tương đương normalizeColors() trong JS
 */
function normalizeColors($colors) {
    if (empty($colors)) {
        return [];
    }
    
    $colorArray = [];
    
    if (is_array($colors)) {
        foreach ($colors as $color) {
            $colorArray[] = standardizeColor($color);
        }
    } elseif (is_string($colors)) {
        $parts = array_map('trim', explode(',', $colors));
        foreach ($parts as $color) {
            if (!empty($color)) {
                $colorArray[] = standardizeColor($color);
            }
        }
    }
    
    // Xóa duplicate words trong mỗi màu
    $deduplicatedColors = [];
    foreach ($colorArray as $color) {
        $words = preg_split('/\s+/', $color);
        $deduped = [];
        $lastWord = '';
        
        foreach ($words as $word) {
            if (mb_strtolower($word, 'UTF-8') !== mb_strtolower($lastWord, 'UTF-8')) {
                $deduped[] = $word;
                $lastWord = $word;
            }
        }
        
        $result = implode(' ', $deduped);
        if (!empty($result)) {
            $deduplicatedColors[] = $result;
        }
    }
    
    error_log('Normalized colors: ' . json_encode($colorArray) . ' → ' . json_encode($deduplicatedColors));
    
    return $deduplicatedColors;
}

// Main API handler
try {
    // Get request data
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON: ' . json_last_error_msg());
    }
    
    $action = $data['action'] ?? '';
    $value = $data['value'] ?? null;
    
    $result = [
        'success' => true,
        'action' => $action,
        'original' => $value,
        'normalized' => null
    ];
    
    switch ($action) {
        case 'standardizeProductType':
            if (empty($value)) {
                $result['normalized'] = '';
            } else {
                $result['normalized'] = standardizeProductType($value);
            }
            break;
            
        case 'standardizeColor':
            if (empty($value)) {
                $result['normalized'] = '';
            } else {
                $result['normalized'] = standardizeColor($value);
            }
            break;
            
        case 'standardizeBrand':
            if (empty($value)) {
                $result['normalized'] = 'Unknown';
            } else {
                $result['normalized'] = standardizeBrand($value);
            }
            break;
            
        case 'normalizeColors':
            $result['normalized'] = normalizeColors($value);
            break;
            
        case 'batch':
            // Batch processing - normalize multiple fields at once
            $batchData = $data['data'] ?? [];
            $normalized = [];
            
            if (!empty($batchData['type'])) {
                $normalized['type'] = standardizeProductType($batchData['type']);
            }
            
            if (!empty($batchData['brand'])) {
                $normalized['brand'] = standardizeBrand($batchData['brand']);
            }
            
            if (!empty($batchData['colors'])) {
                $normalized['colors'] = normalizeColors($batchData['colors']);
            }
            
            if (!empty($batchData['color'])) {
                $normalized['color'] = standardizeColor($batchData['color']);
            }
            
            $result['normalized'] = $normalized;
            break;
            
        default:
            throw new Exception("Unknown action: $action");
    }
    
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    error_log('API Normalization Error: ' . $e->getMessage());
}
?>
