<?php
session_start();
require_once 'config/database.php';
require_once 'helpers/AuditLogger.php';
require_once 'helpers/ImageUploadService.php';
require_once 'helpers/AIAnalysisHelper.php';
require_once 'helpers/SimilarityHelper.php';
require_once 'enhanced_duplicate_functions.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    header('Location: login.html');
    exit();
}

// Kết nối database
$database = new Database();
$pdo = $database->getConnection();

// Load environment variables from helper
loadEnvironmentVariables();

$currentUser = $_SESSION['user_id'];
$userWarehouseId = $_SESSION['warehouse_id'] ?? null;

// Debug và validation warehouse_id
error_log("DEBUG: Session user_id = " . ($currentUser ?? 'NULL'));
error_log("DEBUG: Session warehouse_id = " . ($userWarehouseId ?? 'NULL'));

// Nếu warehouse_id bị thiếu, thử lấy từ database
if (empty($userWarehouseId) && !empty($currentUser)) {
    try {
        $stmt = $pdo->prepare("SELECT warehouse_id FROM users WHERE user_id = ?");
        $stmt->execute([$currentUser]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && !empty($result['warehouse_id'])) {
            $userWarehouseId = $result['warehouse_id'];
            $_SESSION['warehouse_id'] = $userWarehouseId;
            error_log("DEBUG: Retrieved warehouse_id from DB: " . $userWarehouseId);
        } else {
            error_log("ERROR: User $currentUser has no warehouse_id in database");
        }
    } catch (Exception $e) {
        error_log("ERROR: Failed to retrieve warehouse_id: " . $e->getMessage());
    }
}

// Kiểm tra xem có đang thêm sản phẩm vào phiếu đang chỉnh sửa không
$fromReceipt = $_GET['from_receipt'] ?? null;
$addMode = $_GET['mode'] ?? null;
$existingReceipt = null;

if ($fromReceipt && $addMode === 'add') {
    try {
        // Lấy thông tin phiếu nhập đang chỉnh sửa
        $stmt = $pdo->prepare("
            SELECT sr.*, s.name as supplier_name 
            FROM stock_receipts sr
            LEFT JOIN suppliers s ON sr.supplier_id = s.supplier_id
            WHERE sr.receipt_id = ? AND sr.status = 'draft'
        ");
        $stmt->execute([$fromReceipt]);
        $existingReceipt = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$existingReceipt) {
            error_log("ERROR: Receipt #$fromReceipt not found or not in draft status");
        } else {
            error_log("INFO: Adding products to receipt #$fromReceipt");
        }
    } catch(PDOException $e) {
        error_log("ERROR: Failed to get receipt info: " . $e->getMessage());
    }
}

// Lấy danh sách nhà cung cấp và danh mục
$suppliers = [];

try {
    // Lấy danh sách nhà cung cấp thuộc warehouse hiện tại
    $stmt = $pdo->prepare("SELECT supplier_id, name, phone, status FROM suppliers WHERE warehouse_id = ? ORDER BY name");
    $stmt->execute([$userWarehouseId]);
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch(PDOException $e) {
    error_log("Database error: " . $e->getMessage());
}

// ============================================================================
// REFACTORED: All AI analysis and similarity functions moved to helper files
// - helpers/AIAnalysisHelper.php: translateColorToVietnamese(), normalizeAIData(), 
//   analyzeImageWithOpenRouter(), analyzeImageWithGemini(), analyzeImageWithAI(),
//   analyzeMultipleImagesWithGemini(), analyzeSingleImageFallback()
// - helpers/SimilarityHelper.php: normalizeText(), extractKeywords(), 
//   calculateTextSimilarity(), extractProductFeatures(), calculateDescriptionSimilarity(),
//   calculateSimilarity(), calculateEnhancedSimilarity()
// ============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Clean output buffer for AJAX responses
    if (ob_get_level()) {
        ob_clean();
    }
    
    $action = $_POST['action'] ?? '';
    
    // API: Get or allocate location for product variant (reuse old location if exists)
    if ($action === 'get_or_allocate_location') {
        try {
            $warehouseId = $_POST['warehouse_id'] ?? $userWarehouseId;
            $variantId = $_POST['variant_id'] ?? null;
            $sku = $_POST['sku'] ?? '';
            $productName = $_POST['product_name'] ?? '';
            $size = $_POST['size'] ?? '';
            $productType = $_POST['product_type'] ?? ''; // Loại sản phẩm để filter theo type
            
            // Nhận danh sách vị trí bị loại trừ (các vị trí đã dùng trong phiếu hiện tại)
            $excludedLocations = [];
            if (isset($_POST['excluded_locations'])) {
                if (is_array($_POST['excluded_locations'])) {
                    $excludedLocations = $_POST['excluded_locations'];
                } else if (is_string($_POST['excluded_locations'])) {
                    $excludedLocations = json_decode($_POST['excluded_locations'], true) ?: [];
                }
                // Lọc và trim các vị trí
                $excludedLocations = array_filter(array_map('trim', $excludedLocations));
            }
            
            // Log input parameters for debugging
            error_log("get_or_allocate_location called with: warehouse_id=$warehouseId, variant_id=$variantId, sku=$sku, size=$size, type=$productType, excluded=" . json_encode($excludedLocations));
            
            // Validate warehouse ID
            if (!$warehouseId) {
                throw new Exception('Warehouse ID không hợp lệ');
            }
            
            // QUAN TRỌNG: Lấy tất cả vị trí đã được sử dụng trong các phiếu NHÁP
            // để tránh xung đột khi nhiều phiếu nháp cùng dùng 1 vị trí
            $draftLocations = [];
            if ($warehouseId) {
                try {
                    $stmtDraft = $pdo->prepare("
                        SELECT DISTINCT sri.location_code
                        FROM stock_receipt_items sri
                        INNER JOIN stock_receipts sr ON sri.receipt_id = sr.receipt_id
                        WHERE sr.status = 'draft'
                            AND sri.warehouse_id = ?
                            AND sri.location_code IS NOT NULL
                            AND sri.location_code != ''
                    ");
                    $stmtDraft->execute([$warehouseId]);
                    $draftLocations = $stmtDraft->fetchAll(PDO::FETCH_COLUMN);
                    if ($draftLocations === false) {
                        $draftLocations = [];
                    }
                    error_log("🚫 Found " . count($draftLocations) . " locations used in draft receipts: " . json_encode($draftLocations));
                } catch (Exception $e) {
                    error_log("⚠️ Error fetching draft locations: " . $e->getMessage());
                    $draftLocations = [];
                }
            }
            
            // Đảm bảo excludedLocations luôn là array
            if (!is_array($excludedLocations)) {
                $excludedLocations = [];
            }
            
            // Merge excluded_locations với draft locations
            $allExcludedLocations = array_unique(array_merge($excludedLocations, $draftLocations));
            error_log("🚫 Total excluded locations: " . count($allExcludedLocations) . " - " . json_encode($allExcludedLocations));
            
            $locationCode = null;
            $isReused = false;
            
            // BƯỚC 1: Ưu tiên cao nhất - Tìm vị trí CŨ của variant_id này
            // Kiểm tra CẢ inventory VÀ stock_receipt_items
            // Chỉ gợi ý vị trí đang hoạt động (is_active = 1)
            // LOẠI TRỪ các vị trí đã dùng trong phiếu hiện tại (excluded_locations)
            if ($variantId) {
                // Build exclusion condition
                $excludeCondition = '';
                $excludeParams = [];
                if (!empty($allExcludedLocations)) {
                    $placeholders = implode(',', array_fill(0, count($allExcludedLocations), '?'));
                    $excludeCondition = " AND l.shelf_code NOT IN ($placeholders)";
                    $excludeParams = $allExcludedLocations;
                }
                
                // Kiểm tra trong inventory trước
                $sql = "
                    SELECT l.shelf_code as location_code, COUNT(*) as usage_count
                    FROM inventory i
                    INNER JOIN locations l ON i.location_id = l.location_id
                    WHERE i.variant_id = ? 
                        AND i.warehouse_id = ? 
                        AND l.is_active = 1
                        AND i.quantity > 0
                        $excludeCondition
                    GROUP BY l.shelf_code
                    ORDER BY usage_count DESC
                    LIMIT 1
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(array_merge([$variantId, $warehouseId], $excludeParams));
                $existingLocation = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($existingLocation && $existingLocation['location_code']) {
                    $locationCode = $existingLocation['location_code'];
                    $isReused = true;
                    error_log("✅ Reusing existing location from inventory for variant $variantId: $locationCode");
                }
                
                // Nếu không có trong inventory, kiểm tra stock_receipt_items
                if (!$locationCode) {
                    $sql = "
                        SELECT sri.location_code, COUNT(*) as usage_count
                        FROM stock_receipt_items sri
                        INNER JOIN locations l ON sri.location_code = l.shelf_code
                        WHERE sri.variant_id = ? 
                            AND sri.warehouse_id = ?
                            AND l.is_active = 1
                            AND sri.location_code IS NOT NULL
                            $excludeCondition
                        GROUP BY sri.location_code
                        ORDER BY usage_count DESC
                        LIMIT 1
                    ";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute(array_merge([$variantId, $warehouseId], $excludeParams));
                    $receiptLocation = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($receiptLocation && $receiptLocation['location_code']) {
                        $locationCode = $receiptLocation['location_code'];
                        $isReused = true;
                        error_log("✅ Reusing existing location from receipt for variant $variantId: $locationCode");
                    }
                }
            }
            
            // BƯỚC 2: Nếu chưa có vị trí cũ, tìm vị trí TRỐNG cùng kệ với SKU cơ sở
            // Định nghĩa: SNEAKER-K1-T1-P1 (Khu vực SNEAKER, Kệ K1, Tầng T1, Vị trí P1)
            if (!$locationCode && $sku) {
                // Extract base SKU (remove size part)
                $parts = explode('-', $sku);
                if (count($parts) > 1) {
                    array_pop($parts); // Remove size
                    $baseSku = implode('-', $parts);
                    
                    // Tìm các vị trí của size khác cùng SKU cơ sở
                    // Kiểm tra CẢ inventory VÀ stock_receipt_items
                    
                    // Từ inventory
                    $stmt = $pdo->prepare("
                        SELECT DISTINCT l.shelf_code
                        FROM inventory i
                        INNER JOIN locations l ON i.location_id = l.location_id
                        INNER JOIN product_variants pv ON i.variant_id = pv.variant_id
                        WHERE i.warehouse_id = ?
                            AND l.is_active = 1
                            AND pv.sku LIKE ?
                            AND i.quantity > 0
                    ");
                    $stmt->execute([$warehouseId, $baseSku . '%']);
                    $existingShelfCodes = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    // Từ stock_receipt_items
                    $stmt = $pdo->prepare("
                        SELECT DISTINCT sri.location_code AS shelf_code
                        FROM stock_receipt_items sri
                        INNER JOIN product_variants pv ON sri.variant_id = pv.variant_id
                        INNER JOIN locations l ON sri.location_code = l.shelf_code
                        WHERE sri.warehouse_id = ?
                            AND l.is_active = 1
                            AND pv.sku LIKE ?
                            AND sri.location_code IS NOT NULL
                    ");
                    $stmt->execute([$warehouseId, $baseSku . '%']);
                    $receiptShelfCodes = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    // Gộp cả 2 nguồn
                    $existingShelfCodes = array_unique(array_merge($existingShelfCodes, $receiptShelfCodes));
                    
                    // Extract kệ từ mã vị trí (VD: SNEAKER-K1-T1-P1 -> K1-T1)
                    $shelves = [];
                    foreach ($existingShelfCodes as $shelfCode) {
                        if (preg_match('/-(K\d+)-(T\d+)-/i', $shelfCode, $matches)) {
                            $shelf = $matches[1] . '-' . $matches[2];
                            $shelves[] = $shelf;
                        }
                    }
                    $shelves = array_unique($shelves);
                    
                    // Tìm vị trí TRỐNG trên cùng kệ
                    if (!empty($shelves)) {
                        $shelfPatterns = array_map(function($shelf) {
                            return "%-{$shelf}-%";
                        }, $shelves);
                        
                        $shelfConditions = implode(' OR ', array_fill(0, count($shelfPatterns), 'l.shelf_code LIKE ?'));
                        
                        // Build type condition if productType is provided
                        $typeCondition = '';
                        $typeParams = [];
                        if (!empty($productType)) {
                            $typeCondition = " AND (l.type IS NULL OR LOWER(l.type) = LOWER(?))";
                            $typeParams = [$productType];
                        }
                        
                        // Build exclusion condition
                        $excludeCondition = '';
                        $excludeParams = [];
                        if (!empty($allExcludedLocations)) {
                            $placeholders = implode(',', array_fill(0, count($allExcludedLocations), '?'));
                            $excludeCondition = " AND l.shelf_code NOT IN ($placeholders)";
                            $excludeParams = $allExcludedLocations;
                        }
                        
                        $stmt = $pdo->prepare("
                            SELECT l.shelf_code
                            FROM locations l
                            LEFT JOIN inventory i ON l.location_id = i.location_id 
                                AND i.warehouse_id = ? 
                                AND i.quantity > 0
                            WHERE l.warehouse_id = ?
                                AND l.is_active = 1
                                AND i.inventory_id IS NULL
                                AND ({$shelfConditions})
                                {$typeCondition}
                                {$excludeCondition}
                            ORDER BY l.shelf_code ASC
                            LIMIT 1
                        ");
                        
                        $params = array_merge([$warehouseId, $warehouseId], $shelfPatterns, $typeParams, $excludeParams);
                        $stmt->execute($params);
                        $emptyShelfLocation = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($emptyShelfLocation) {
                            $locationCode = $emptyShelfLocation['shelf_code'];
                            error_log("✅ Found empty location on same shelf: $locationCode");
                        }
                    }
                }
            }
            
            // BƯỚC 3: Nếu vẫn chưa có, tìm vị trí TRỐNG bất kỳ (theo type nếu có)
            if (!$locationCode) {
                // Build type condition if productType is provided
                $typeCondition = '';
                $typeParams = [];
                if (!empty($productType)) {
                    $typeCondition = " AND (l.type IS NULL OR LOWER(l.type) = LOWER(?))";
                    $typeParams = [$productType];
                }
                
                // Build exclusion condition
                $excludeCondition = '';
                $excludeParams = [];
                if (!empty($allExcludedLocations)) {
                    $placeholders = implode(',', array_fill(0, count($allExcludedLocations), '?'));
                    $excludeCondition = " AND l.shelf_code NOT IN ($placeholders)";
                    $excludeParams = $allExcludedLocations;
                }
                
                $stmt = $pdo->prepare("
                    SELECT l.shelf_code
                    FROM locations l
                    LEFT JOIN inventory i ON l.location_id = i.location_id 
                        AND i.warehouse_id = ? 
                        AND i.quantity > 0
                    WHERE l.warehouse_id = ?
                        AND l.is_active = 1
                        AND i.inventory_id IS NULL
                        {$typeCondition}
                        {$excludeCondition}
                    ORDER BY l.shelf_code ASC
                    LIMIT 1
                ");
                $stmt->execute(array_merge([$warehouseId, $warehouseId], $typeParams, $excludeParams));
                $availableLocation = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($availableLocation) {
                    $locationCode = $availableLocation['shelf_code'];
                    error_log("✅ Found empty location: $locationCode");
                }
            }
            
            // BƯỚC 4: Nếu không có vị trí trống nào -> Return error (không tự động tạo)
            if (!$locationCode) {
                error_log("❌ No empty location available");
                $response = [
                    'success' => false,
                    'message' => 'Không có vị trí trống. Vui lòng tạo vị trí mới hoặc nhập thủ công.',
                    'location_code' => null
                ];
            } else {
                $response = [
                    'success' => true,
                    'location_code' => $locationCode,
                    'is_reused' => $isReused,
                    'message' => $isReused ? 'Sử dụng lại vị trí cũ' : 'Vị trí trống được gợi ý'
                ];
            }
            
        } catch (Exception $e) {
            error_log("Error getting/allocating location: " . $e->getMessage());
            $response = [
                'success' => false, 
                'message' => $e->getMessage(),
                'location_code' => null
            ];
        }
        
        header('Content-Type: application/json');
        echo json_encode($response);
        exit();
    }
    
    // API: Get available locations for smart allocation
    if ($action === 'get_available_locations') {
        try {
            $warehouseId = $_POST['warehouse_id'] ?? $userWarehouseId;
            
            // Get all locations for this warehouse
            $stmt = $pdo->prepare("
                SELECT location_id, shelf_code, description 
                FROM locations 
                WHERE warehouse_id = ? AND is_active = 1
                ORDER BY shelf_code
            ");
            $stmt->execute([$warehouseId]);
            $allLocations = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get truly empty locations (no inventory at all)
            $stmt = $pdo->prepare("
                SELECT l.location_id, l.shelf_code, l.description
                FROM locations l
                LEFT JOIN inventory i ON l.location_id = i.location_id 
                    AND i.warehouse_id = ? 
                    AND i.quantity > 0
                WHERE l.warehouse_id = ? 
                    AND l.is_active = 1
                    AND i.inventory_id IS NULL
                ORDER BY l.shelf_code
            ");
            $stmt->execute([$warehouseId, $warehouseId]);
            $availableLocations = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $response = [
                'success' => true,
                'all_locations' => $allLocations,
                'available_locations' => $availableLocations,
                'occupied_count' => count($allLocations) - count($availableLocations)
            ];
            
        } catch (Exception $e) {
            error_log("Error getting available locations: " . $e->getMessage());
            $response = ['success' => false, 'message' => $e->getMessage()];
        }
        
        header('Content-Type: application/json');
        echo json_encode($response);
        exit();
    }
    
    // API: Allocate smart location for a product
    if ($action === 'allocate_smart_location') {
        try {
            $warehouseId = $_POST['warehouse_id'] ?? $userWarehouseId;
            $productName = $_POST['product_name'] ?? '';
            $size = $_POST['size'] ?? '';
            
            // Get next available location
            $stmt = $pdo->prepare("
                SELECT l.shelf_code, l.description
                FROM locations l
                LEFT JOIN stock_receipt_items sri ON l.shelf_code = sri.location_code AND l.warehouse_id = sri.warehouse_id
                WHERE l.warehouse_id = ? AND sri.location_code IS NULL
                ORDER BY l.shelf_code
                LIMIT 1
            ");
            $stmt->execute([$warehouseId]);
            $availableLocation = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($availableLocation) {
                $response = [
                    'success' => true,
                    'location_code' => $availableLocation['shelf_code'],
                    'description' => $availableLocation['description']
                ];
            } else {
                // No available location, create new one
                $stmt = $pdo->prepare("
                    SELECT shelf_code FROM locations 
                    WHERE warehouse_id = ? 
                    ORDER BY shelf_code DESC 
                    LIMIT 1
                ");
                $stmt->execute([$warehouseId]);
                $lastLocation = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($lastLocation) {
                    // Generate next location code
                    $lastCode = $lastLocation['shelf_code'];
                    preg_match('/([A-Z]+)-(\d+)-(\d+)/', $lastCode, $matches);
                    if ($matches) {
                        $zone = $matches[1];
                        $row = $matches[2];
                        $col = intval($matches[3]) + 1;
                        $newCode = sprintf("%s-%d-%02d", $zone, $row, $col);
                        
                        // Create new location
                        $stmt = $pdo->prepare("
                            INSERT INTO locations (warehouse_id, shelf_code, description)
                            VALUES (?, ?, ?)
                        ");
                        $stmt->execute([
                            $warehouseId,
                            $newCode,
                            "Auto-generated location for $productName (Size: $size)"
                        ]);
                        
                        $response = [
                            'success' => true,
                            'location_code' => $newCode,
                            'description' => 'Auto-generated',
                            'is_new' => true
                        ];
                    } else {
                        $response = ['success' => false, 'message' => 'Invalid location format'];
                    }
                } else {
                    $response = ['success' => false, 'message' => 'No locations found in warehouse'];
                }
            }
            
        } catch (Exception $e) {
            error_log("Error allocating smart location: " . $e->getMessage());
            $response = ['success' => false, 'message' => $e->getMessage()];
        }
        
        header('Content-Type: application/json');
        echo json_encode($response);
        exit();
    }
    
    if ($action === 'upload_images') {
        // Xử lý upload ảnh
        $uploadDir = 'uploads/products/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        $uploadedFiles = [];
        $errors = [];
        
        if (isset($_FILES['images']) && is_array($_FILES['images']['tmp_name'])) {
            $fileCount = count($_FILES['images']['tmp_name']);
            
            if ($fileCount < 2 || $fileCount > 4) {
                $response = ['success' => false, 'message' => 'Vui lòng tải 2-4 ảnh'];
                header('Content-Type: application/json');
                echo json_encode($response);
                exit();
            }
            
            for ($i = 0; $i < $fileCount; $i++) {
                if ($_FILES['images']['error'][$i] === UPLOAD_ERR_OK) {
                    $tmpName = $_FILES['images']['tmp_name'][$i];
                    $originalName = $_FILES['images']['name'][$i];
                    $fileSize = $_FILES['images']['size'][$i];
                    
                    // Kiểm tra loại file
                    $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
                    $fileType = mime_content_type($tmpName);
                    
                    if (!in_array($fileType, $allowedTypes)) {
                        $errors[] = "File $originalName không đúng định dạng (chỉ chấp nhận JPG, PNG, WebP)";
                        continue;
                    }
                    
                    // Kiểm tra kích thước (max 5MB)
                    if ($fileSize > 5 * 1024 * 1024) {
                        $errors[] = "File $originalName quá lớn (tối đa 5MB)";
                        continue;
                    }
                    
                    // Tạo tên file unique
                    $extension = pathinfo($originalName, PATHINFO_EXTENSION);
                    $fileName = uniqid('product_') . '.' . $extension;
                    $filePath = $uploadDir . $fileName;
                    
                    if (move_uploaded_file($tmpName, $filePath)) {
                        // Tạo URL đơn giản - chỉ cần path tương đối
                        $imageUrl = $filePath;
                        
                        $uploadedFiles[] = [
                            'path' => $filePath,
                            'url' => $imageUrl,
                            'name' => $originalName
                        ];
                    } else {
                        $errors[] = "Không thể lưu file $originalName";
                    }
                } else {
                    $errors[] = "Lỗi upload file " . $_FILES['images']['name'][$i];
                }
            }
        }
        
        if (!empty($errors)) {
            $response = ['success' => false, 'message' => implode(', ', $errors)];
        } else {
            $response = ['success' => true, 'uploaded_files' => $uploadedFiles];
        }
        
        header('Content-Type: application/json');
        echo json_encode($response);
        exit();
    }
    
    if ($action === 'analyze_image') {
        $imagePaths = $_POST['image_paths'] ?? [];
        
        if (empty($imagePaths)) {
            $response = ['success' => false, 'message' => 'Không có ảnh để phân tích'];
        } else {
            // Tối ưu: Giới hạn số ảnh để tránh rate limiting
            $maxImages = 3; // Giới hạn tối đa 3 ảnh để tránh rate limiting
            $imagesToAnalyze = array_slice($imagePaths, 0, $maxImages);
            
            if (count($imagePaths) > $maxImages) {
                error_log("Limited analysis to first $maxImages images out of " . count($imagePaths) . " uploaded images");
            }
            
            // Phân tích các ảnh được chọn với backup logic
            error_log("Analyzing " . count($imagesToAnalyze) . " images: " . json_encode($imagesToAnalyze));
            
            // Thử Gemini trước cho multiple images
            $aiResults = analyzeMultipleImagesWithGemini($imagesToAnalyze);
            
            // Nếu Gemini thất bại với multiple images, thử analyze từng ảnh với backup
            if (!$aiResults['success']) {
                error_log("Multi-image Gemini failed: " . $aiResults['message'] . ". Trying single image analysis with backup...");
                
                $singleResults = [];
                $successCount = 0;
                
                foreach ($imagesToAnalyze as $imagePath) {
                    $singleResult = analyzeImageWithAI($imagePath);
                    if ($singleResult['success']) {
                        $singleResults[] = $singleResult['data'];
                        $successCount++;
                    }
                }
                
                if ($successCount > 0) {
                    // Kết hợp kết quả từ nhiều ảnh
                    $combinedData = $singleResults[0]; // Lấy kết quả đầu tiên làm base
                    $combinedData['analyzed_images_count'] = $successCount;
                    $combinedData['image_paths'] = $imagesToAnalyze;
                    $combinedData['fallback_mode'] = true;
                    $combinedData['confidence'] = 0.8; // Confidence cao hơn với nhiều ảnh
                    
                    $aiResults = ['success' => true, 'data' => $combinedData];
                } else {
                    $aiResults = ['success' => false, 'message' => 'Không thể phân tích bất kỳ ảnh nào với cả Gemini và OpenRouter'];
                }
            }
            
            if ($aiResults['success']) {
                $suggestions = $aiResults['data']; // Gemini trả về trực tiếp dữ liệu cần thiết
                
                // Thêm thông báo nếu đã giới hạn số ảnh
                $limitMessage = '';
                if (count($imagePaths) > $maxImages) {
                    $limitMessage = " (Đã phân tích " . count($imagesToAnalyze) . "/" . count($imagePaths) . " ảnh để tối ưu hiệu suất)";
                }
                
                $response = [
                    'success' => true,
                    'ai_data' => $aiResults['data'],
                    'suggestions' => $suggestions,
                    'limit_message' => $limitMessage,
                    'analyzed_images' => $imagePaths,
                    'total_images' => count($imagePaths)
                ];
            } else {
                $response = $aiResults;
            }
        }
        
        header('Content-Type: application/json');
        echo json_encode($response);
        exit();
    }
    
    if ($action === 'check_duplicates') {
        // Clean any output buffer to prevent PHP warnings from interfering with JSON
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // Start fresh output buffering
        ob_start();
        
        // Set error reporting to log errors but not display them
        error_reporting(E_ALL);
        ini_set('display_errors', 0);
        ini_set('log_errors', 1);
        
        // Set default response
        $response = ['success' => false, 'message' => 'Unknown error occurred'];
        
        try {
            error_log("🔍 [ADD_PRODUCT] Check duplicates action started");
            
            $aiData = $_POST['ai_data'] ?? [];
            $imagePath = $_POST['image_path'] ?? '';
            
            error_log("[ADD_PRODUCT] AI Data received: " . json_encode($aiData));
            error_log("[ADD_PRODUCT] Current session warehouse_id: " . ($_SESSION['warehouse_id'] ?? 'NULL'));
            
            if (empty($aiData)) {
                $response = ['success' => false, 'message' => 'Không có dữ liệu AI để kiểm tra'];
            } else {
                // Kiểm tra sản phẩm trùng lặp - gọi trực tiếp Enhanced Detection
                error_log("[ADD_PRODUCT] Checking duplicates with Enhanced Detection");
                
                // Call duplicate check with additional error handling
                try {
                    $duplicates = checkDuplicateProductsEnhanced($aiData, $pdo);
                    
                    if (is_array($duplicates)) {
                        error_log("[ADD_PRODUCT] Found " . count($duplicates) . " potential duplicates");
                        
                        $response = [
                            'success' => true,
                            'has_duplicates' => !empty($duplicates),
                            'duplicates' => $duplicates,
                            'count' => count($duplicates)
                        ];
                    } else {
                        error_log("[ADD_PRODUCT] checkDuplicateProducts returned non-array: " . gettype($duplicates));
                        $response = ['success' => false, 'message' => 'Lỗi định dạng kết quả kiểm tra trùng lặp'];
                    }
                    
                } catch (Error $e) {
                    error_log("💀 [ADD_PRODUCT] Fatal error in duplicate check: " . $e->getMessage());
                    error_log("💀 [ADD_PRODUCT] Fatal error file: " . $e->getFile() . " line " . $e->getLine());
                    $response = ['success' => false, 'message' => 'Lỗi nghiêm trọng: ' . $e->getMessage()];
                }
            }
            
        } catch (Exception $e) {
            error_log("❌ [ADD_PRODUCT] Exception in check_duplicates: " . $e->getMessage());
            error_log("❌ [ADD_PRODUCT] Exception file: " . $e->getFile() . " line " . $e->getLine());
            error_log("❌ [ADD_PRODUCT] Stack trace: " . $e->getTraceAsString());
            $response = [
                'success' => false, 
                'message' => 'Lỗi khi kiểm tra trùng lặp: ' . $e->getMessage()
            ];
        } catch (Error $e) {
            error_log("💀 [ADD_PRODUCT] Fatal error in check_duplicates action: " . $e->getMessage());
            error_log("💀 [ADD_PRODUCT] Fatal error file: " . $e->getFile() . " line " . $e->getLine());
            $response = [
                'success' => false,
                'message' => 'Lỗi nghiêm trọng hệ thống: ' . $e->getMessage()
            ];
        }
        
        // Clean output buffer before sending JSON
        $bufferContent = ob_get_clean();
        if (!empty($bufferContent)) {
            error_log("⚠️ [ADD_PRODUCT] Unexpected output: " . $bufferContent);
        }
        
        header('Content-Type: application/json');
        echo json_encode($response);
        exit();
    }
    
    if ($action === 'get_product_for_update') {
        // Clean any output buffer
        if (ob_get_level()) {
            ob_clean();
        }
        
        $productId = $_POST['product_id'] ?? '';
        
        if (empty($productId)) {
            $response = ['success' => false, 'message' => 'Product ID không được cung cấp'];
        } else {
            try {
                // Get product basic info
                $stmt = $pdo->prepare("
                    SELECT 
                        p.product_id,
                        p.name,
                        p.brand,
                        p.type,
                        p.description,
                        p.material,
                        p.features,
                        p.tags,
                        p.category_id,
                        c.name as category_name,
                        i.file_path as image_path,
                        MIN(pv.price) as price,
                        GROUP_CONCAT(DISTINCT pv.color) as colors
                    FROM products p

                    LEFT JOIN product_variants pv ON p.product_id = pv.product_id
                    LEFT JOIN product_images i ON p.product_id = i.product_id AND i.is_primary = 1
                    WHERE p.product_id = ?
                    GROUP BY p.product_id
                ");
                
                $stmt->execute([$productId]);
                $product = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Debug: Log fetched product data
                error_log("Fetched product data: " . json_encode($product));
                
                if (!$product) {
                    $response = ['success' => false, 'message' => 'Không tìm thấy sản phẩm'];
                } else {
                    // Get all images for this product
                    $imageStmt = $pdo->prepare("
                        SELECT file_path, is_primary 
                        FROM product_images 
                        WHERE product_id = ? 
                        ORDER BY is_primary DESC, image_id ASC
                    ");
                    $imageStmt->execute([$productId]);
                    $images = $imageStmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Format images for display
                    $formattedImages = [];
                    foreach ($images as $img) {
                        $formattedImages[] = [
                            'url' => $img['file_path'],
                            'is_primary' => $img['is_primary']
                        ];
                    }
                    
                    // Get sizes and quantities
                    $sizeStmt = $pdo->prepare("
                        SELECT DISTINCT
                            pv.size,
                            pv.color,
                            pv.sku,
                            pv.price
                        FROM product_variants pv
                        WHERE pv.product_id = ?
                        ORDER BY pv.size
                    ");
                    
                    $sizeStmt->execute([$productId]);
                    $sizes = $sizeStmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Add default min_quantity for compatibility
                    foreach ($sizes as &$size) {
                        $size['min_quantity'] = 10; // Default value
                    }
                    unset($size); // Break reference
                    
                    // Calculate base SKU from first variant SKU
                    $baseSku = '';
                    if (!empty($sizes) && !empty($sizes[0]['sku'])) {
                        $firstSku = $sizes[0]['sku'];
                        $firstSize = $sizes[0]['size'];
                        
                        // Remove size from SKU to get base SKU
                        // Pattern: BASE-SIZE or BASE-SIZE-TIMESTAMP
                        if (preg_match('/^(.+)-' . preg_quote($firstSize, '/') . '(?:-\d+)?$/', $firstSku, $matches)) {
                            $baseSku = $matches[1];
                        } else {
                            // Fallback: remove last part if it matches size
                            $parts = explode('-', $firstSku);
                            if (end($parts) === $firstSize) {
                                array_pop($parts);
                                $baseSku = implode('-', $parts);
                            } else {
                                $baseSku = $firstSku; // Use as is if pattern doesn't match
                            }
                        }
                    }
                    
                    $product['sizes'] = $sizes;
                    $product['images'] = $formattedImages; // Add images array
                    $product['base_sku'] = $baseSku; // Set calculated base SKU  
                    $product['id'] = $product['product_id']; // Add id field for compatibility
                    $product['color'] = $product['colors']; // Use the grouped colors
                    
                    error_log("Calculated base SKU: $baseSku from first variant: " . ($sizes[0]['sku'] ?? 'none'));
                    
                    $response = [
                        'success' => true,
                        'product' => $product
                    ];
                }
                
            } catch (Exception $e) {
                error_log("Error getting product for update: " . $e->getMessage());
                $response = ['success' => false, 'message' => 'Lỗi khi tải thông tin sản phẩm: ' . $e->getMessage()];
            }
        }
        
        header('Content-Type: application/json');
        echo json_encode($response);
        exit();
    }
    
    if ($action === 'save_product') {
            // Clean any previous output
            ob_clean();
            
            // Validate user warehouse_id
            if (empty($userWarehouseId)) {
                error_log("ERROR: userWarehouseId is empty in save_product action");
                $response = ['success' => false, 'message' => 'Lỗi: Không xác định được warehouse của user. Vui lòng đăng nhập lại.'];
                header('Content-Type: application/json');
                echo json_encode($response);
                exit();
            }
            
            // Validate warehouse_id exists in warehouses table
            try {
                $stmt = $pdo->prepare("SELECT warehouse_id FROM warehouses WHERE warehouse_id = ?");
                $stmt->execute([$userWarehouseId]);
                if (!$stmt->fetch()) {
                    error_log("ERROR: userWarehouseId $userWarehouseId does not exist in warehouses table");
                    $response = ['success' => false, 'message' => "Lỗi: Warehouse ID $userWarehouseId không tồn tại trong hệ thống."];
                    header('Content-Type: application/json');
                    echo json_encode($response);
                    exit();
                }
            } catch (Exception $e) {
                error_log("ERROR: Failed to validate warehouse_id: " . $e->getMessage());
                $response = ['success' => false, 'message' => 'Lỗi khi kiểm tra warehouse: ' . $e->getMessage()];
                header('Content-Type: application/json');
                echo json_encode($response);
                exit();
            }
            
            error_log("Save product action started with userWarehouseId: " . $userWarehouseId);
            
            // Check if this is update mode
            $isUpdateMode = isset($_POST['is_update_mode']) && $_POST['is_update_mode'] === 'true';
            $updateProductId = $_POST['update_product_id'] ?? null;
            
            // Debug: Log received data
            error_log("Save product action started - Update mode: " . ($isUpdateMode ? 'true' : 'false'));
            error_log("DEBUG: is_update_mode POST value: " . ($_POST['is_update_mode'] ?? 'NOT_SET'));
            error_log("DEBUG: update_product_id POST value: " . ($_POST['update_product_id'] ?? 'NOT_SET'));
            if ($isUpdateMode) {
                error_log("Update product ID: " . $updateProductId);
            }
            error_log("POST data keys: " . implode(', ', array_keys($_POST)));
            // Don't log full POST data as it may be large
            error_log("Category ID received: " . ($_POST['category_id'] ?? 'NOT_SET'));
            
            // Xử lý lưu sản phẩm - chỉ tạo danh mục sản phẩm và variants, không có số lượng thực tế
            $productName = trim($_POST['product_name'] ?? '');
            $brand = trim($_POST['brand'] ?? '');
            $type = trim($_POST['type'] ?? '');
            $productDescription = trim($_POST['product_description'] ?? '');
            $material = trim($_POST['material'] ?? '');
            $features = trim($_POST['features'] ?? '');
            $tags = trim($_POST['tags'] ?? '');
            $categoryId = $_POST['category_id'] ?? null;
            $sku = trim($_POST['base_sku'] ?? $_POST['sku'] ?? ''); // Try both base_sku and sku
            $color = trim($_POST['color'] ?? '');
            $price = floatval($_POST['price'] ?? 0);
            $imageUrls = $_POST['image_url'] ?? '';
            
            error_log("Parsed data - Product: $productName, SKU: $sku, Category: $categoryId");
            
            // Initialize validation flag
            $validationError = null;
            
            // Category validation removed - using type field instead
            if (!empty($categoryId)) {
                error_log("Category ID provided but categories table removed: $categoryId");
            } else {
                // Only require category for new products, not for updates
                if (!$isUpdateMode) {
                    error_log("Category ID is empty for new product");
                    $validationError = 'Vui lòng chọn danh mục sản phẩm';
                } else {
                    error_log("Category ID is empty but this is update mode - allowing");
                }
            }
            
            // Lấy danh sách size và ngưỡng tối thiểu
            $sizes = $_POST['sizes'] ?? [];
            $minQuantities = $_POST['min_quantities'] ?? [];
            
            // Lấy existing sizes nếu có (cho update mode)
            $existingSizes = $_POST['existing_sizes'] ?? [];
            $existingMinQuantities = $_POST['existing_min_quantities'] ?? [];
            
            error_log("New sizes data: " . json_encode($sizes));
            error_log("New min quantities data: " . json_encode($minQuantities));
            error_log("Existing sizes data: " . json_encode($existingSizes));
            error_log("Existing min quantities data: " . json_encode($existingMinQuantities));
            
            // Validate required fields
            if ($validationError) {
                error_log("Skipping processing due to category validation error: " . $validationError);
                $response = ['success' => false, 'message' => $validationError];
            } else if (empty($productName) || empty($sku)) {
                // For update mode, if fields are empty, try to get from existing product
                if ($isUpdateMode && $updateProductId) {
                    error_log("Update mode: Missing product name or SKU, will try to use existing data");
                    // Continue processing - we'll use existing data
                } else {
                    error_log("Validation failed: Missing product name or SKU");
                    $response = ['success' => false, 'message' => 'Vui lòng điền đầy đủ thông tin bắt buộc (Tên sản phẩm và SKU cơ sở)'];
                }
            } else if (empty($sizes) && empty($existingSizes)) {
                error_log("Validation failed: No sizes provided");
                $response = ['success' => false, 'message' => 'Vui lòng chọn ít nhất một size cho sản phẩm'];
            } else {
                // Validate sizes và tạo danh sách size hợp lệ (bỏ kiểm tra min_quantities)
                error_log("Processing sizes validation...");
                $validSizes = [];
                $hasValidSize = false;
                
                // Xử lý existing sizes trước (cho update mode)
                for ($i = 0; $i < count($existingSizes); $i++) {
                    $size = trim($existingSizes[$i]);
                    $minQuantity = isset($existingMinQuantities[$i]) ? intval($existingMinQuantities[$i]) : 0;
                    
                    error_log("Processing existing size[$i]: '$size' with min_quantity: $minQuantity");
                    
                    if (!empty($size)) { // Chỉ cần size, không cần min_quantity > 0
                        $validSizes[] = [
                            'size' => $size,
                            'min_quantity' => $minQuantity, // Có thể = 0
                            'is_existing' => true
                        ];
                        $hasValidSize = true;
                        error_log("Valid existing size added: $size with min_quantity: $minQuantity");
                    }
                }
                
                // Xử lý new sizes
                for ($i = 0; $i < count($sizes); $i++) {
                    $size = trim($sizes[$i]);
                    $minQuantity = isset($minQuantities[$i]) ? intval($minQuantities[$i]) : 0;
                    
                    error_log("Processing new size[$i]: '$size' with min_quantity: $minQuantity");
                    
                    if (!empty($size)) { // Chỉ cần size, không cần min_quantity > 0
                        // Kiểm tra trùng lặp size
                        if (in_array($size, array_column($validSizes, 'size'))) {
                            error_log("Duplicate size detected: $size");
                            $response = ['success' => false, 'message' => "Size $size đã được chọn rồi"];
                            break;
                        }
                        
                        $validSizes[] = [
                            'size' => $size,
                            'min_quantity' => $minQuantity, // Có thể = 0
                            'is_existing' => false
                        ];
                        $hasValidSize = true;
                        error_log("Valid new size added: $size with min_quantity: $minQuantity");
                    } else {
                        error_log("Invalid new size: size='$size' (empty)");
                    }
                }
                
                error_log("Size validation complete. hasValidSize: " . ($hasValidSize ? 'true' : 'false') . ", validSizes count: " . count($validSizes));
                
                if (!$hasValidSize && !isset($response)) {
                    error_log("No valid sizes found");
                    $response = ['success' => false, 'message' => 'Vui lòng chọn ít nhất một size hợp lệ với ngưỡng tối thiểu'];
                } else if (!isset($response)) {
                    error_log("Size validation passed, proceeding to database operations");
                    try {
                        error_log("Starting database transaction for product creation");
                        $pdo->beginTransaction();
                        
                        // Thêm sản phẩm chính
                        // CRITICAL: Validate foreign key references before database insertion
                        error_log("Pre-insertion validation - category_id: '$categoryId', userWarehouseId: '$userWarehouseId'");
                        
                        // Validate category_id is not empty/null (required foreign key)
                        if (empty($categoryId) || $categoryId === '') {
                            error_log("VALIDATION FAILED: category_id is empty - cannot insert product");
                            throw new Exception("Vui lòng chọn danh mục sản phẩm. Category ID không được để trống.");
                        }
                        
                        // Validate userWarehouseId is not empty/null (required foreign key)
                        if (empty($userWarehouseId) || $userWarehouseId === '') {
                            error_log("VALIDATION FAILED: userWarehouseId is empty - cannot insert product");
                            throw new Exception("Lỗi phiên đăng nhập: Warehouse ID không hợp lệ. Vui lòng đăng nhập lại.");
                        }
                        
                        // Category validation removed - using type field instead
                        
                        // Validate warehouse exists in database  
                        $warehouseCheckStmt = $pdo->prepare("SELECT warehouse_id FROM warehouses WHERE warehouse_id = ?");
                        $warehouseCheckStmt->execute([$userWarehouseId]);
                        if (!$warehouseCheckStmt->fetch()) {
                            error_log("VALIDATION FAILED: userWarehouseId $userWarehouseId does not exist in warehouses table");
                            throw new Exception("Warehouse không tồn tại (ID: $userWarehouseId). Vui lòng liên hệ quản trị viên.");
                        }
                        
                        error_log("✅ Foreign key validation passed - proceeding with insertion");
                        error_log("Inserting main product with data: " . json_encode([
                            'category_id' => $categoryId,
                            'name' => $productName,
                            'brand' => $brand,
                            'type' => $type
                        ]));
                        
                        if ($isUpdateMode && $updateProductId) {
                            // Update mode: Update existing product (không cần update warehouse_id)
                            $stmt = $pdo->prepare("UPDATE products SET category_id = ?, name = ?, description = ?, brand = ?, type = ?, material = ?, features = ?, tags = ? WHERE product_id = ?");
                            $stmt->execute([$categoryId, $productName, $productDescription, $brand, $type, $material, $features, $tags, $updateProductId]);
                            $productId = $updateProductId;
                            error_log("Product updated successfully with ID: " . $productId);
                        } else {
                            // Add new mode: Insert new product với warehouse_id
                            error_log("Adding new product with warehouse_id: " . $userWarehouseId);
                            $stmt = $pdo->prepare("INSERT INTO products (warehouse_id, category_id, name, description, brand, type, material, features, tags, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                            $stmt->execute([$userWarehouseId, $categoryId, $productName, $productDescription, $brand, $type, $material, $features, $tags]);
                            $productId = $pdo->lastInsertId();
                            error_log("Product inserted successfully with ID: " . $productId);
                        }
                        
                        error_log("Product inserted successfully with ID: " . $productId);
                        
                        $createdSizes = [];
                        
                        // Get existing sizes if in update mode
                        $existingSizes = [];
                        if ($isUpdateMode && $updateProductId) {
                            $existingStmt = $pdo->prepare("SELECT DISTINCT size FROM product_variants WHERE product_id = ?");
                            $existingStmt->execute([$updateProductId]);
                            $existingSizes = $existingStmt->fetchAll(PDO::FETCH_COLUMN);
                            error_log("Existing sizes: " . json_encode($existingSizes));
                        }
                        
                        // Process sizes: skip existing ones, only create new ones
                        foreach ($validSizes as $index => $sizeData) {
                            $currentSize = $sizeData['size'];
                            
                            // Skip if this size already exists in update mode
                            if ($isUpdateMode && in_array($currentSize, $existingSizes)) {
                                error_log("Skipping existing size: " . $currentSize);
                                continue;
                            }
                            $sizeBasedSku = $sku . '-' . $sizeData['size'];
                            
                            // Kiểm tra SKU trùng lặp
                            $stmt = $pdo->prepare("SELECT variant_id FROM product_variants WHERE sku = ?");
                            $stmt->execute([$sizeBasedSku]);
                            if ($stmt->fetch()) {
                                // Tạo SKU unique bằng cách thêm timestamp
                                $sizeBasedSku = $sku . '-' . $sizeData['size'] . '-' . substr(time(), -6);
                            }
                            
                            // Thêm variant cho từng size (quantity = 0 vì chưa nhập kho)
                            $stmt = $pdo->prepare("INSERT INTO product_variants (product_id, sku, color, size, price, warehouse_id, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                            $stmt->execute([$productId, $sizeBasedSku, $color, $sizeData['size'], $price, $userWarehouseId]);
                            $variantId = $pdo->lastInsertId();
                            
                            // Tạo bản ghi trong inventory với quantity = 0 và min_stock_level
                            if ($userWarehouseId) {
                                try {
                                    // Validate warehouse exists before creating inventory
                                    $warehouseCheck = $pdo->prepare("SELECT warehouse_id FROM warehouses WHERE warehouse_id = ?");
                                    $warehouseCheck->execute([$userWarehouseId]);
                                    if (!$warehouseCheck->fetch()) {
                                        error_log("ERROR: Warehouse ID $userWarehouseId not found when creating inventory for variant $variantId");
                                        throw new Exception("Warehouse ID không hợp lệ: $userWarehouseId");
                                    }
                                    
                                    // Validate variant exists before creating inventory
                                    $variantCheck = $pdo->prepare("SELECT variant_id FROM product_variants WHERE variant_id = ?");
                                    $variantCheck->execute([$variantId]);
                                    if (!$variantCheck->fetch()) {
                                        error_log("ERROR: Variant ID $variantId not found when creating inventory");
                                        throw new Exception("Product variant ID không hợp lệ: $variantId");
                                    }
                                    
                                    // Create inventory record with proper foreign key validation
                                    $stmt = $pdo->prepare("INSERT INTO inventory (variant_id, warehouse_id, quantity, location_id) VALUES (?, ?, 0, NULL)");
                                    $stmt->execute([$variantId, $userWarehouseId]);
                                    error_log("Successfully created inventory record for variant $variantId in warehouse $userWarehouseId");
                                    
                                } catch (Exception $e) {
                                    // Log detailed error information for foreign key constraint violations
                                    error_log("INVENTORY CREATION ERROR: " . $e->getMessage());
                                    error_log("Error code: " . $e->getCode());
                                    error_log("Variant ID: $variantId, Warehouse ID: $userWarehouseId");
                                    
                                    // Check if this is a foreign key constraint violation
                                    if (strpos($e->getMessage(), '1452') !== false || strpos($e->getMessage(), 'foreign key constraint fails') !== false) {
                                        error_log("FOREIGN KEY CONSTRAINT VIOLATION detected in inventory creation");
                                        // Don't skip this error - it indicates a serious data integrity issue
                                        throw new Exception("Lỗi ràng buộc dữ liệu: Không thể tạo bản ghi inventory. Vui lòng kiểm tra warehouse và variant ID.");
                                    } else {
                                        // For other errors, log and continue (backward compatibility)
                                        error_log("Inventory creation skipped due to non-critical error: " . $e->getMessage());
                                    }
                                }
                            } else {
                                error_log("ERROR: userWarehouseId is empty when creating inventory for variant $variantId");
                                throw new Exception("Không thể tạo inventory: Warehouse ID không hợp lệ");
                            }
                            
                            $createdSizes[] = [
                                'variant_id' => $variantId,
                                'size' => $sizeData['size'],
                                'min_quantity' => $sizeData['min_quantity'],
                                'current_quantity' => 0, // Sẽ được cập nhật khi nhập kho
                                'sku' => $sizeBasedSku
                            ];
                            
                            // Thêm hình ảnh cho variant đầu tiên
                            if ($index === 0 && !empty($imageUrls)) {
                                $imagePaths = json_decode($imageUrls, true);
                                if (is_array($imagePaths)) {
                                    foreach ($imagePaths as $imgIndex => $imagePath) {
                                        $isPrimary = ($imgIndex === 0) ? 1 : 0;
                                        $stmt = $pdo->prepare("INSERT INTO product_images (product_id, file_path, is_primary, warehouse_id, created_at) VALUES (?, ?, ?, ?, NOW())");
                                        $stmt->execute([$productId, $imagePath, $isPrimary, $userWarehouseId]);
                                    }
                                }
                            }
                        }
                        
                        // Log audit
                        $auditLogger = new AuditLogger($pdo);
                        $auditLogger->log($currentUser, 'create_product_catalog', 'products', $productId, null, [
                            'product_name' => $productName,
                            'brand' => $brand,
                            'type' => $type,
                            'material' => $material,
                            'features' => $features,
                            'tags' => $tags,
                            'base_sku' => $sku,
                            'color' => $color,
                            'sizes_count' => count($createdSizes),
                            'sizes_details' => $createdSizes,
                            'image_count' => is_array(json_decode($imageUrls, true)) ? count(json_decode($imageUrls, true)) : 0,
                            'note' => 'Product catalog created - Stock quantities will be added via stock receipts'
                        ]);
                        
                        $pdo->commit();
                        
                        $actionText = $isUpdateMode ? 'cập nhật' : 'tạo';
                        $newSizesCount = count($createdSizes);
                        $sizeMessage = $newSizesCount > 0 ? " với $newSizesCount size mới" : "";
                        
                        $response = [
                            'success' => true, 
                            'message' => "Danh mục sản phẩm đã được {$actionText} thành công{$sizeMessage}! " . ($newSizesCount > 0 ? "Hãy tạo phiếu nhập kho để thêm số lượng." : ""),
                            'product_id' => $productId,
                            'created_sizes' => $createdSizes,
                            'is_update_mode' => $isUpdateMode,
                            'next_step' => 'create_stock_receipt'
                        ];
                        
                    } catch(PDOException $e) {
                        $pdo->rollBack();
                        error_log("PDO Database error: " . $e->getMessage());
                        error_log("PDO Error code: " . $e->getCode());
                        error_log("PDO Error info: " . json_encode($e->errorInfo ?? []));
                        $response = ['success' => false, 'message' => 'Lỗi database khi lưu sản phẩm: ' . $e->getMessage()];
                    } catch(Exception $e) {
                        if (isset($pdo) && $pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        error_log("General error: " . $e->getMessage());
                        error_log("Stack trace: " . $e->getTraceAsString());
                        $response = ['success' => false, 'message' => 'Lỗi không xác định: ' . $e->getMessage()];
                    }
                }
            }
        
        // Ensure we have a response
        if (!isset($response)) {
            error_log("No response set - this should not happen");
            $response = ['success' => false, 'message' => 'Lỗi không xác định - không có response'];
        }
        
        // Check for empty message in failed response
        if (isset($response['success']) && $response['success'] === false && empty($response['message'])) {
            error_log("Warning: Response has success=false but empty message");
            $response['message'] = 'Đã xảy ra lỗi không xác định trong quá trình xử lý';
        }
        
        // Debug: Log response before sending
        error_log("Final response being sent: " . json_encode($response));
        
        header('Content-Type: application/json');
        echo json_encode($response);
        exit();
    }
    
    // Get available sizes for a product
    if ($action === 'get_available_sizes') {
        $productId = $_POST['product_id'] ?? '';
        
        if (empty($productId)) {
            $response = ['success' => false, 'message' => 'Product ID không được cung cấp'];
        } else {
            try {
                $stmt = $pdo->prepare("
                    SELECT 
                        pv.variant_id,
                        pv.size,
                        pv.sku,
                        pv.color,
                        COALESCE(SUM(i.quantity), 0) as quantity,
                        pv.price as sale_price
                    FROM product_variants pv 
                    LEFT JOIN inventory i ON pv.variant_id = i.variant_id AND i.warehouse_id = ?
                    WHERE pv.product_id = ? 
                    GROUP BY pv.variant_id, pv.size, pv.sku, pv.color, pv.price
                    ORDER BY 
                        CASE 
                            WHEN pv.size REGEXP '^[0-9]+(\\.5)?$' THEN CAST(pv.size AS DECIMAL(4,1))
                            ELSE 9999 
                        END ASC,
                        pv.size ASC
                ");
                
                $stmt->execute([$userWarehouseId, $productId]);
                $variants = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $sizes = [];
                foreach ($variants as $variant) {
                    // Lấy giá nhập gần nhất từ stock_receipt_items
                    $stmtPrice = $pdo->prepare("
                        SELECT unit_price 
                        FROM stock_receipt_items 
                        WHERE variant_id = ? AND warehouse_id = ?
                        ORDER BY created_at DESC 
                        LIMIT 1
                    ");
                    $stmtPrice->execute([$variant['variant_id'], $userWarehouseId]);
                    $lastReceipt = $stmtPrice->fetch(PDO::FETCH_ASSOC);
                    
                    $sizes[] = [
                        'variant_id' => intval($variant['variant_id']),
                        'sku' => $variant['sku'],
                        'size' => $variant['size'],
                        'color' => $variant['color'],
                        'quantity' => intval($variant['quantity']),
                        'unit_price' => $lastReceipt ? floatval($lastReceipt['unit_price']) : 0,
                        'sale_price' => floatval($variant['sale_price'])
                    ];
                }
                
                $response = [
                    'success' => true, 
                    'sizes' => $sizes,
                    'total' => count($sizes)
                ];
                
            } catch (Exception $e) {
                error_log("Error getting available sizes: " . $e->getMessage());
                $response = ['success' => false, 'message' => 'Lỗi khi tải danh sách size: ' . $e->getMessage()];
            }
        }
        
        header('Content-Type: application/json');
        echo json_encode($response);
        exit();
    }
    
    // Get product ID by SKU
    if ($action === 'get_product_id_by_sku') {
        $sku = $_POST['sku'] ?? '';
        
        if (empty($sku)) {
            $response = ['success' => false, 'message' => 'SKU không được cung cấp'];
        } else {
            try {
                // Try to find product by base SKU first
                $stmt = $pdo->prepare("SELECT id as product_id FROM products WHERE base_sku = ? LIMIT 1");
                $stmt->execute([$sku]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$result) {
                    // If not found, try to find by variant SKU
                    $stmt = $pdo->prepare("
                        SELECT p.id as product_id 
                        FROM products p
                        INNER JOIN product_variants pv ON p.id = pv.product_id
                        WHERE pv.sku = ? 
                        LIMIT 1
                    ");
                    $stmt->execute([$sku]);
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                }
                
                if ($result) {
                    $response = [
                        'success' => true,
                        'product_id' => $result['product_id']
                    ];
                } else {
                    $response = ['success' => false, 'message' => 'Không tìm thấy sản phẩm với SKU: ' . $sku];
                }
                
            } catch (Exception $e) {
                error_log("Error getting product ID by SKU: " . $e->getMessage());
                $response = ['success' => false, 'message' => 'Lỗi khi tìm kiếm sản phẩm: ' . $e->getMessage()];
            }
        }
        
        header('Content-Type: application/json');
        echo json_encode($response);
        exit();
    }
    
    // Save product and get variants (for adding to existing receipt)
    if ($action === 'save_product_get_variants') {
        $productData = json_decode($_POST['product_data'] ?? '{}', true);
        $warehouseId = $_POST['warehouse_id'] ?? $userWarehouseId;
        
        error_log("=== SAVE PRODUCT GET VARIANTS DEBUG ===");
        error_log("Product Data: " . json_encode($productData));
        error_log("Products count: " . (isset($productData['products']) ? count($productData['products']) : 0));
        
        try {
            $variants = [];
            
            // Process each product
            if (!empty($productData['products'])) {
                foreach ($productData['products'] as $product) {
                    error_log("Processing product: " . json_encode($product));
                    
                    foreach ($product['sizes'] as $size) {
                        error_log("Processing size: " . json_encode($size));
                        
                        // Get variant_id based on product_id and size
                        $stmtVariant = $pdo->prepare("
                            SELECT variant_id FROM product_variants 
                            WHERE product_id = ? AND size = ? LIMIT 1
                        ");
                        $stmtVariant->execute([$product['id'], $size['size']]);
                        $variant = $stmtVariant->fetch(PDO::FETCH_ASSOC);
                        
                        if ($variant) {
                            $variants[] = [
                                'variant_id' => $variant['variant_id'],
                                'quantity' => intval($size['import_quantity']),
                                'unit_price' => floatval($size['purchase_price']),
                                'sale_price' => floatval($size['sale_price'] ?? 0),
                                'location_code' => $size['storage_location'] ?? null
                            ];
                            error_log("Found variant: " . $variant['variant_id'] . " with sale_price: " . ($size['sale_price'] ?? 0));
                        } else {
                            error_log("WARNING: Variant not found for product_id={$product['id']}, size={$size['size']}");
                        }
                    }
                }
            }
            
            error_log("Total variants found: " . count($variants));
            
            $response = [
                'success' => true,
                'variants' => $variants,
                'message' => 'Đã lấy thông tin ' . count($variants) . ' variant'
            ];
            
        } catch (Exception $e) {
            error_log("Error getting variants: " . $e->getMessage());
            $response = ['success' => false, 'message' => 'Lỗi khi lấy thông tin variant: ' . $e->getMessage()];
        }
        
        header('Content-Type: application/json');
        echo json_encode($response);
        exit();
    }
    
    // Get variant_id by product_id and size (for adding to existing receipt)
    if ($action === 'get_variant_id') {
        $productId = $_POST['product_id'] ?? null;
        $size = $_POST['size'] ?? '';
        $sku = $_POST['sku'] ?? '';
        
        try {
            if (!$productId) {
                throw new Exception('Thiếu product_id');
            }
            
            // Tìm variant theo product_id và size
            $stmt = $pdo->prepare("
                SELECT variant_id FROM product_variants 
                WHERE product_id = ? AND size = ? 
                LIMIT 1
            ");
            $stmt->execute([$productId, $size]);
            $variant = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($variant) {
                $response = [
                    'success' => true,
                    'variant_id' => $variant['variant_id'],
                    'product_id' => $productId,
                    'size' => $size
                ];
                error_log("Found variant_id: {$variant['variant_id']} for product_id=$productId, size=$size");
            } else {
                // Thử tìm theo SKU nếu không tìm thấy theo size
                if ($sku) {
                    $stmt = $pdo->prepare("
                        SELECT variant_id FROM product_variants 
                        WHERE sku = ? 
                        LIMIT 1
                    ");
                    $stmt->execute([$sku]);
                    $variant = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($variant) {
                        $response = [
                            'success' => true,
                            'variant_id' => $variant['variant_id'],
                            'sku' => $sku
                        ];
                        error_log("Found variant_id: {$variant['variant_id']} by SKU=$sku");
                    } else {
                        throw new Exception("Không tìm thấy variant cho product_id=$productId, size=$size, sku=$sku");
                    }
                } else {
                    throw new Exception("Không tìm thấy variant cho product_id=$productId, size=$size");
                }
            }
            
        } catch (Exception $e) {
            error_log("Error getting variant_id: " . $e->getMessage());
            $response = ['success' => false, 'message' => $e->getMessage()];
        }
        
        header('Content-Type: application/json');
        echo json_encode($response);
        exit();
    }
    
    // Save receipt draft (status: draft)
    if ($action === 'save_receipt_draft') {
        $receiptData = json_decode($_POST['receipt_data'] ?? '{}', true);
        $warehouseId = $_POST['warehouse_id'] ?? $userWarehouseId;
        $supplierId = $_POST['supplier_id'] ?? null;
        $receiptNote = $_POST['receipt_note'] ?? '';
        
        // Debug logging
        error_log("=== SAVE RECEIPT DRAFT DEBUG ===");
        error_log("Supplier ID: " . $supplierId);
        error_log("Warehouse ID: " . $warehouseId);
        error_log("Receipt Data: " . json_encode($receiptData));
        error_log("Products count: " . (isset($receiptData['products']) ? count($receiptData['products']) : 0));
        
        try {
            $pdo->beginTransaction();
            
            // Create receipt with status 'draft' (bản nháp)
            // Note: stock_receipts table has 'user_id', 'notes' (not 'note'), no 'receipt_date'
            $stmt = $pdo->prepare("
                INSERT INTO stock_receipts (supplier_id, user_id, warehouse_id, status, notes, created_at) 
                VALUES (?, ?, ?, 'draft', ?, NOW())
            ");
            
            $stmt->execute([
                $supplierId,
                $currentUser,
                $warehouseId,
                $receiptNote
            ]);
            
            $receiptId = $pdo->lastInsertId();
            error_log("Created receipt ID: " . $receiptId);
            
            $itemsInserted = 0;
            
            // Insert receipt items (products with storage location)
            if (!empty($receiptData['products'])) {
                $stmtDetail = $pdo->prepare("
                    INSERT INTO stock_receipt_items 
                    (receipt_id, variant_id, quantity, unit_price, location_code, warehouse_id, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                ");
                
                foreach ($receiptData['products'] as $product) {
                    error_log("Processing product: " . json_encode($product));
                    
                    foreach ($product['sizes'] as $size) {
                        error_log("Processing size: " . json_encode($size));
                        
                        // Get variant_id based on product_id and size
                        $stmtVariant = $pdo->prepare("
                            SELECT variant_id FROM product_variants 
                            WHERE product_id = ? AND size = ? LIMIT 1
                        ");
                        $stmtVariant->execute([$product['id'], $size['size']]);
                        $variant = $stmtVariant->fetch(PDO::FETCH_ASSOC);
                        
                        if ($variant) {
                            $quantity = intval($size['import_quantity']);
                            $unitPrice = floatval($size['purchase_price']);
                            $storageLocation = $size['storage_location'] ?? 'A-1-00'; // Vị trí lưu trữ từ bước 4
                            
                            error_log("Inserting item: variant_id={$variant['variant_id']}, qty=$quantity, price=$unitPrice, location=$storageLocation");
                            
                            $stmtDetail->execute([
                                $receiptId,
                                $variant['variant_id'],
                                $quantity,
                                $unitPrice,
                                $storageLocation,
                                $warehouseId
                            ]);
                            
                            $itemsInserted++;
                        } else {
                            error_log("ERROR: Variant not found for product_id={$product['id']}, size={$size['size']}");
                        }
                    }
                }
            } else {
                error_log("WARNING: No products in receiptData!");
            }
            
            error_log("Total items inserted: $itemsInserted");
            
            $pdo->commit();
            
            $message = $itemsInserted > 0 
                ? "Đã lưu bản nháp phiếu nhập hàng với $itemsInserted sản phẩm. Trạng thái: Bản nháp" 
                : "Đã lưu bản nháp phiếu nhập nhưng không có sản phẩm nào!";
            
            $response = [
                'success' => true,
                'receipt_id' => $receiptId,
                'items_count' => $itemsInserted,
                'message' => $message,
                'status' => 'draft'
            ];
            
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Error saving receipt draft: " . $e->getMessage());
            $response = ['success' => false, 'message' => 'Lỗi khi lưu phiếu nhập: ' . $e->getMessage()];
        }
        
        header('Content-Type: application/json');
        echo json_encode($response);
        exit();
    }
    
    // Finalize receipt
    if ($action === 'finalize_receipt') {
        $draftId = $_POST['draft_id'] ?? '';
        $warehouseId = $_POST['warehouse_id'] ?? $userWarehouseId;
        
        try {
            $pdo->beginTransaction();
            
            // Get draft data
            $stmt = $pdo->prepare("SELECT * FROM stock_receipts WHERE receipt_id = ? AND status = 'draft'");
            $stmt->execute([$draftId]);
            $draft = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$draft) {
                throw new Exception('Không tìm thấy nháp phiếu nhập hàng');
            }
            
            // Extract JSON data from note
            $noteLines = explode("\n", $draft['note']);
            $jsonData = null;
            $capturing = false;
            $jsonString = '';
            
            foreach ($noteLines as $line) {
                if (trim($line) === '[DRAFT DATA]') {
                    $capturing = true;
                    continue;
                }
                if ($capturing) {
                    $jsonString .= $line;
                }
            }
            
            if ($jsonString) {
                $receiptData = json_decode($jsonString, true);
                
                if ($receiptData && isset($receiptData['products'])) {
                    // Update receipt status to confirmed
                    $stmt = $pdo->prepare("
                        UPDATE stock_receipts 
                        SET status = 'confirmed', note = ? 
                        WHERE receipt_id = ?
                    ");
                    
                    $originalNote = preg_replace('/\n\n\[DRAFT DATA\].*$/s', '', $draft['note']);
                    $stmt->execute([$originalNote, $draftId]);
                    
                    $totalProducts = count($receiptData['products']);
                    $totalQuantity = 0;
                    
                    // Process products (this would normally create stock receipt details)
                    foreach ($receiptData['products'] as $product) {
                        foreach ($product['sizes'] as $size) {
                            $totalQuantity += $size['import_quantity'];
                        }
                    }
                    
                    $pdo->commit();
                    
                    $response = [
                        'success' => true,
                        'receipt_id' => $draftId,
                        'total_products' => $totalProducts,
                        'total_quantity' => $totalQuantity,
                        'message' => 'Phiếu nhập hàng đã được xác nhận thành công'
                    ];
                } else {
                    throw new Exception('Dữ liệu nháp không hợp lệ');
                }
            } else {
                throw new Exception('Không tìm thấy dữ liệu nháp');
            }
            
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Error finalizing receipt: " . $e->getMessage());
            $response = ['success' => false, 'message' => 'Lỗi khi hoàn tất phiếu: ' . $e->getMessage()];
        }
        
        header('Content-Type: application/json');
        echo json_encode($response);
        exit();
    }
    
    // ===== NEW ACTION: SUBMIT STOCK RECEIPT FOR EXISTING PRODUCT =====
    if ($action === 'submit_stock_receipt_for_existing') {
        try {
            $pdo->beginTransaction();
            
            // Get input data
            $productId = $_POST['product_id'] ?? '';
            $receiptItems = $_POST['receipt_items'] ?? [];
            $totalQuantity = $_POST['total_quantity'] ?? 0;
            $totalValue = $_POST['total_value'] ?? 0;
            $supplierId = $_POST['supplier_id'] ?? null;
            $receiptNotes = $_POST['receipt_notes'] ?? '';
            
            if (!$productId || empty($receiptItems)) {
                throw new Exception('Thiếu thông tin sản phẩm hoặc items');
            }
            
            // Create stock receipt record
            $receiptNumber = 'SR-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
            
            $stmt = $pdo->prepare("
                INSERT INTO stock_receipts (receipt_number, supplier_id, total_items, total_value, status, notes, created_by, warehouse_id) 
                VALUES (?, ?, ?, ?, 'confirmed', ?, ?, ?)
            ");
            $stmt->execute([$receiptNumber, $supplierId, $totalQuantity, $totalValue, $receiptNotes, $currentUser, $userWarehouseId]);
            $receiptId = $pdo->lastInsertId();
            
            // Process each receipt item
            foreach ($receiptItems as $item) {
                $variantId = $item['variant_id'] ?? null;
                $quantity = intval($item['quantity']);
                $price = floatval($item['price']);
                
                if ($quantity <= 0 || $price <= 0) continue;
                
                // Insert stock receipt item
                $stmt = $pdo->prepare("
                    INSERT INTO stock_receipt_items (receipt_id, product_id, variant_id, quantity, unit_price, total_price) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$receiptId, $productId, $variantId, $quantity, $price, ($quantity * $price)]);
                
                // Update inventory
                if ($variantId) {
                    // Update variant quantity
                    $stmt = $pdo->prepare("
                        UPDATE product_variants 
                        SET current_quantity = current_quantity + ?, updated_at = NOW() 
                        WHERE variant_id = ?
                    ");
                    $stmt->execute([$quantity, $variantId]);
                } else {
                    // Update product quantity (if no variants)
                    $stmt = $pdo->prepare("
                        UPDATE products 
                        SET stock_quantity = COALESCE(stock_quantity, 0) + ?, updated_at = NOW() 
                        WHERE product_id = ?
                    ");
                    $stmt->execute([$quantity, $productId]);
                }
                
                // Create inventory transaction record
                $stmt = $pdo->prepare("
                    INSERT INTO inventory_transactions (product_id, variant_id, transaction_type, quantity, reference_type, reference_id, notes, created_by) 
                    VALUES (?, ?, 'stock_in', ?, 'stock_receipt', ?, ?, ?)
                ");
                $stmt->execute([$productId, $variantId, $quantity, $receiptId, "Stock receipt: {$receiptNumber}", $currentUser]);
            }
            
            // Update receipt status
            $stmt = $pdo->prepare("UPDATE stock_receipts SET status = 'completed', completed_at = NOW() WHERE receipt_id = ?");
            $stmt->execute([$receiptId]);
            
            $pdo->commit();
            
            $response = [
                'success' => true,
                'message' => 'Đã tạo phiếu nhập kho thành công',
                'receipt_id' => $receiptId,
                'receipt_number' => $receiptNumber,
                'total_quantity' => $totalQuantity,
                'total_value' => $totalValue
            ];
            
            // Log successful action
            error_log("✅ Stock receipt created successfully for existing product - ID: {$receiptId}, Number: {$receiptNumber}");
            
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("❌ Error creating stock receipt for existing product: " . $e->getMessage());
            $response = [
                'success' => false,
                'message' => 'Lỗi khi tạo phiếu nhập kho: ' . $e->getMessage()
            ];
        }
        
        header('Content-Type: application/json');
        echo json_encode($response);
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Tạo phiếu nhập kho - Smart Warehouse</title>
    
    <!-- Custom fonts -->
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">
    
    <!-- Custom styles -->
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    
    <style>
        /* ===== COLOR VARIABLES ===== */
        :root {
            --primary-blue: #2563eb;
            --dark-blue: #1e40af;
            --light-blue: #3b82f6;
            --dark-bg: #1f2937;
            --darker-bg: #111827;
            --beige: #f5f5dc;
            --light-beige: #faf9f6;
            --white: #ffffff;
            --gray-light: #f3f4f6;
            --gray-border: #e5e7eb;
            --text-dark: #111827;
            --text-gray: #6b7280;
            --yellow: #fbbf24;
            --yellow-light: #fef3c7;
        }

        body {
            background-color: var(--light-beige);
        }

        /* ===== CARD & HEADERS STYLING ===== */
        .card {
            border: none;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            border-radius: 12px;
        }

        .card-header {
            background: linear-gradient(135deg, var(--dark-bg) 0%, var(--darker-bg) 100%);
            color: var(--yellow-light);
            border-radius: 12px 12px 0 0 !important;
            padding: 20px;
            border: none;
        }

        .card-header h6 {
            margin: 0;
            font-weight: 600;
            letter-spacing: 0.5px;
            color: var(--yellow-light);
        }

        .card-header i {
            color: var(--yellow);
        }

        /* ===== BUTTONS STYLING ===== */
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-blue) 0%, var(--dark-blue) 100%);
            border: none;
            border-radius: 8px;
            padding: 10px 24px;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 2px 4px rgba(37, 99, 235, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(37, 99, 235, 0.4);
        }

        .btn-success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            border: none;
            border-radius: 8px;
            padding: 10px 24px;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 2px 4px rgba(16, 185, 129, 0.3);
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(16, 185, 129, 0.4);
        }

        .btn-secondary {
            background: var(--gray-light);
            color: var(--text-dark);
            border: none;
            border-radius: 8px;
            padding: 10px 24px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-secondary:hover {
            background: var(--gray-border);
            color: var(--text-dark);
        }

        .btn-danger {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            border: none;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(239, 68, 68, 0.4);
        }

        .btn-ai {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            color: white;
            border-radius: 8px;
            padding: 10px 24px;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 2px 4px rgba(102, 126, 234, 0.3);
        }
        
        .btn-ai:hover {
            background: linear-gradient(135deg, #5a6fd8 0%, #6a4190 100%);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(102, 126, 234, 0.4);
        }

        /* ===== FORM CONTROLS ===== */
        .form-control, .form-control:focus {
            box-sizing: border-box;
            min-height: 44px;
            line-height: 1.3;
            border-radius: 8px;
            border: 2px solid var(--gray-border);
            padding: 10px 15px;
            transition: all 0.3s ease;
            overflow: visible;
        }

        .form-control:focus {
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .form-group label {
            font-weight: 600;
            color: #111827; /* Dark color for high contrast on white inputs */
            margin-bottom: 8px;
        }
        
        .form-group label i {
            color: var(--primary-blue);
        }

        /* ===== STEP INDICATOR ===== */
        .step-indicator {
            display: flex;
            justify-content: center;
            margin-bottom: 30px;
            background: linear-gradient(135deg, var(--primary-blue) 0%, var(--dark-blue) 100%);
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .step {
            display: flex;
            align-items: center;
            margin: 0 14px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .step:hover .step-number {
            transform: scale(1.08);
        }

        .step.completed {
            cursor: pointer;
        }

        .step.completed:hover .step-number {
            background: #059669 !important;
        }

        .step-number {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.18);
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            margin-right: 12px;
            border: 2px solid rgba(255, 255, 255, 0.28);
            transition: all 0.25s ease;
        }

        .step.active .step-number {
            background: var(--white);
            color: var(--primary-blue);
            border-color: var(--white);
            box-shadow: 0 0 0 4px rgba(255, 255, 255, 0.28);
        }

        .step.completed .step-number {
            background: var(--white);
            color: #10b981;
            border-color: var(--white);
        }

        /* Ensure step labels are high-contrast on dark gradient */
        .step span,
        .step-title {
            color: var(--white);
            font-size: 0.95rem;
            font-weight: 600;
            opacity: 0.95;
        }

        .step.active span {
            color: var(--yellow-light);
            font-weight: 700;
            opacity: 1;
        }

        .step.completed span {
            color: var(--success);
            font-weight: 700;
        }

        .step-title {
            color: var(--white);
            font-size: 0.9em;
            font-weight: 500;
        }

        /* ===== UPLOAD AREA ===== */
        .upload-area {
            border: 2px dashed var(--primary-blue);
            border-radius: 12px;
            padding: 40px;
            text-align: center;
            background: var(--gray-light);
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .upload-area:hover {
            border-color: var(--dark-blue);
            background: #e0f2fe;
        }
        
        .upload-area.drag-over {
            border-color: #10b981;
            background: #f0fff4;
        }
        
        .upload-icon {
            font-size: 48px;
            color: var(--primary-blue);
            margin-bottom: 20px;
        }
        
        /* ===== AI ANALYSIS RESULT ===== */
        .ai-analysis-result {
            background: linear-gradient(135deg, #e0f2fe 0%, #dbeafe 100%);
            border: 2px solid var(--primary-blue);
            border-radius: 12px;
            padding: 20px;
            margin: 20px 0;
            box-shadow: 0 4px 8px rgba(37, 99, 235, 0.1);
        }
        
        /* AI Auto-Filled Info Styles */
        #aiAutoFilledInfo {
            background: linear-gradient(135deg, #d4edda 0%, #f0fff4 100%);
            border: 2px solid #10b981 !important;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.15);
            animation: slideDown 0.5s ease-out;
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        #aiAutoFilledInfo h6 {
            color: #065f46;
            font-weight: 600;
        }
        
        #aiAutoFilledInfo .table td {
            padding: 0.5rem 0.25rem;
            vertical-align: middle;
        }
        
        #aiAutoFilledInfo .table td:first-child {
            color: #065f46;
            font-weight: 600;
        }
        
        #aiAutoFilledInfo .table td:last-child {
            color: var(--text-dark);
            font-weight: 500;
        }
        
        #aiAutoFilledInfo i.fas {
            width: 20px;
            text-align: center;
            color: #10b981;
        }
        
        #aiAutoFilledInfo hr {
            border-top: 1px solid #a7f3d0;
        }
        
        #aiAutoFilledInfo .alert-info {
            background-color: #dbeafe;
            border-color: #bfdbfe;
            color: #1e40af;
        }
        
        /* ===== TAGS & COLORS ===== */
        .tag-item {
            display: inline-block;
            background: linear-gradient(135deg, var(--primary-blue) 0%, var(--dark-blue) 100%);
            color: white;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            margin: 2px;
            font-weight: 600;
            box-shadow: 0 2px 4px rgba(37, 99, 235, 0.2);
        }
        
        .color-item {
            display: inline-block;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            margin: 2px;
            border: 2px solid var(--gray-border);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        
        /* ===== SUGGESTION CARD ===== */
        .suggestion-card {
            background: linear-gradient(135deg, #fef3c7 0%, #fef9e5 100%);
            border: 2px solid var(--yellow);
            border-radius: 12px;
            padding: 15px;
            margin: 10px 0;
            box-shadow: 0 2px 6px rgba(251, 191, 36, 0.2);
        }
        
        .suggestion-card h6 {
            color: #92400e;
            font-weight: 600;
        }
        
        /* ===== LOADING & IMAGE PREVIEW ===== */
        .loading-spinner {
            display: none;
            text-align: center;
            padding: 20px;
        }
        
        .image-preview {
            max-width: 100%;
            max-height: 300px;
            border-radius: 8px;
            margin: 10px 0;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        
        .uploaded-image {
            width: 100%;
            height: 150px;
            object-fit: cover;
            border-radius: 8px;
            border: 2px solid var(--gray-border);
            transition: all 0.3s ease;
        }
        
        .uploaded-image:hover {
            transform: scale(1.05);
            border-color: var(--primary-blue);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2);
        }
        
        .image-card {
            transition: all 0.3s ease;
            border-radius: 12px;
        }
        
        .image-card:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            transform: translateY(-2px);
        }
        
        .analyzing-image {
            border: 2px solid var(--yellow);
            animation: pulse-yellow 2s infinite;
        }
        
        @keyframes pulse-yellow {
            0% { box-shadow: 0 0 0 0 rgba(251, 191, 36, 0.7); }
            70% { box-shadow: 0 0 0 10px rgba(251, 191, 36, 0); }
            100% { box-shadow: 0 0 0 0 rgba(251, 191, 36, 0); }
        }
        
        .border-primary {
            border: 2px solid var(--primary-blue) !important;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.2);
        }
        
        .primary-image-badge {
            background: linear-gradient(135deg, var(--primary-blue), var(--dark-blue));
            animation: glow 2s infinite alternate;
        }
        
        @keyframes glow {
            from { box-shadow: 0 0 5px var(--primary-blue); }
            to { box-shadow: 0 0 15px var(--primary-blue); }
        }
        
        /* ===== UPDATE SOURCE & MODAL STYLES ===== */
        .update-source-option {
            cursor: pointer;
            transition: all 0.3s ease;
            border: 2px solid transparent;
            border-radius: 12px;
        }
        
        .update-source-option:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            border-color: var(--primary-blue);
        }
        
        .update-source-option.selected {
            border-color: var(--primary-blue);
            transform: translateY(-5px);
            box-shadow: 0 4px 15px rgba(37, 99, 235, 0.3);
            background: rgba(37, 99, 235, 0.05);
        }
        
        .update-source-option .card-body {
            transition: all 0.3s ease;
        }
        
        .update-source-option.selected .card-body {
            background: rgba(37, 99, 235, 0.05);
        }
        
        /* ===== DUPLICATE CHECK MODAL ===== */
        .duplicate-product-card {
            cursor: pointer;
            transition: all 0.3s ease;
            border: 2px solid var(--gray-border);
            border-radius: 12px;
        }
        
        .duplicate-product-card:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            transform: translateY(-2px);
            border-color: var(--primary-blue);
        }
        
        .duplicate-product-card.border-primary {
            border-color: var(--primary-blue) !important;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.2);
            background: linear-gradient(135deg, #e7f3ff 0%, #f0f8ff 100%);
        }
        
        .modal-xl {
            max-width: 90%;
        }
        
        @media (max-width: 768px) {
            .modal-xl {
                max-width: 95%;
                margin: 1rem;
            }
        }
        
        .modal-header {
            background: linear-gradient(135deg, var(--dark-bg) 0%, var(--darker-bg) 100%);
            color: var(--yellow-light);
            border-radius: 12px 12px 0 0;
        }
        
        .modal-title {
            color: var(--yellow-light);
            font-weight: 600;
        }
        
        .modal-header .close {
            color: var(--white);
            opacity: 0.8;
        }
        
        .modal-header .close:hover {
            opacity: 1;
        }
        
        /* ===== SIZE MANAGEMENT ===== */
        .size-row {
            border: 2px solid var(--gray-border);
            border-radius: 8px;
            padding: 12px;
            background: var(--white);
            transition: all 0.3s ease;
            margin-bottom: 10px;
        }
        
        .size-row:hover {
            box-shadow: 0 2px 8px rgba(37, 99, 235, 0.1);
            transform: translateY(-1px);
            border-color: var(--primary-blue);
        }
        
        .size-row:last-child {
            margin-bottom: 0 !important;
        }
        
        #sizeContainer {
            max-height: 400px;
            overflow-y: auto;
        }
        
        .size-row .form-control {
            border-radius: 6px;
            border: 2px solid var(--gray-border);
        }
        
        .size-row select.form-control:focus,
        .size-row input.form-control:focus {
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 0.2rem rgba(37, 99, 235, 0.15);
        }
        
        .remove-size-btn {
            border-radius: 6px;
            transition: all 0.3s ease;
        }
        
        .remove-size-btn:hover {
            transform: scale(1.1);
            box-shadow: 0 2px 6px rgba(239, 68, 68, 0.3);
        }
        
        #addSizeBtn {
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        #addSizeBtn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(16, 185, 129, 0.3);
        }
        
        /* Size selection highlight */
        .size-row.has-duplicate select {
            border-color: #ef4444;
            box-shadow: 0 0 0 0.2rem rgba(239, 68, 68, 0.15);
        }
        
        .size-option {
            transition: all 0.3s ease;
            cursor: pointer;
            border: 2px solid var(--gray-border) !important;
            border-radius: 8px;
        }
        
        .size-option:hover {
            border-color: var(--primary-blue) !important;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(37, 99, 235, 0.2) !important;
        }
        
        .size-option.selected {
            border-color: #10b981 !important;
            background-color: #f0fff4 !important;
            box-shadow: 0 0 15px rgba(16, 185, 129, 0.3) !important;
        }
        
        .size-option.selected .size-number {
            color: #10b981 !important;
            font-weight: 700;
        }


        /* ===== STOCK RECEIPT DUPLICATE CARDS STYLING ===== */
        .stock-receipt-duplicate-card {
            background: var(--white);
            transition: all 0.3s ease;
            cursor: pointer;
            border: 2px solid var(--gray-border);
            border-radius: 10px;
        }
        
        .stock-receipt-duplicate-card:hover {
            background: var(--beige-bg);
            border-color: var(--primary-blue) !important;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.15);
            transform: translateY(-2px);
        }
        
        .stock-receipt-duplicate-card.border-primary {
            background: linear-gradient(135deg, #e7f3ff 0%, #f0f8ff 100%);
            border-color: var(--primary-blue) !important;
            border-width: 3px !important;
            box-shadow: 0 0 20px rgba(37, 99, 235, 0.25);
        }
        
        .stock-receipt-duplicate-card .selected-indicator {
            position: absolute;
            top: 10px;
            right: 15px;
            font-size: 1.3rem;
            color: #10b981;
        }
        
        .similar-products-list {
            position: relative;
        }
        
        #stockReceiptDuplicateResults .badge-lg {
            font-size: 0.95rem;
            padding: 0.5rem 0.85rem;
            font-weight: 600;
        }
        
        #stockReceiptDuplicateResults .similar-product-item {
            position: relative;
            margin-bottom: 15px;
        }
        
        /* Stock Receipt Selection Buttons */
        .stock-receipt-duplicate-card .btn-success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            border: none;
            box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
            transition: all 0.3s ease;
            font-weight: 600;
            border-radius: 8px;
        }
        
        .stock-receipt-duplicate-card .btn-success:hover {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4);
            transform: translateY(-2px);
        }
        
        .stock-receipt-duplicate-card .d-flex.flex-column button {
            font-size: 0.875rem;
            font-weight: 600;
            white-space: nowrap;
        }
        
        #stockReceiptDuplicateResults .similar-product-item:last-child {
            margin-bottom: 0;
        }
        
        /* ===== STOCK RECEIPT VARIANT ROWS STYLING ===== */
        .variant-row {
            background: var(--white);
            transition: all 0.3s ease;
            border: 2px solid var(--gray-border);
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 10px;
        }
        
        .variant-row:hover {
            background: var(--beige-bg);
            border-color: var(--primary-blue);
            box-shadow: 0 2px 8px rgba(37, 99, 235, 0.15);
            transform: translateX(3px);
        }
        
        .variant-quantity, .variant-price {
            transition: all 0.3s ease;
            border: 2px solid var(--gray-border);
            border-radius: 6px;
        }
        
        .variant-quantity:focus, .variant-price:focus {
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 0.2rem rgba(37, 99, 235, 0.15);
        }
        
        #stockReceiptVariantsCard .card-header,
        #stockReceiptSimpleCard .card-header {
            background: linear-gradient(135deg, var(--dark-bg) 0%, var(--darker-bg) 100%);
            color: var(--yellow-light);
            font-weight: 700;
            border-radius: 12px 12px 0 0;
        }
        
        #receiptSummary {
            background: linear-gradient(135deg, #eef4ff 0%, #e0f2fe 100%);
            border-left: 5px solid var(--primary-blue);
            border-radius: 8px;
            padding: 15px;
        }
        
        .badge.badge-info {
            font-size: 0.95rem;
            padding: 0.45rem 0.85rem;
            font-weight: 600;
        }
        
        /* Size option styling (duplicate cleanup - keeping only one set) */
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            .stock-receipt-duplicate-card .col-md-2,
            .stock-receipt-duplicate-card .col-md-7,
            .stock-receipt-duplicate-card .col-md-3 {
                flex: 0 0 100%;
                max-width: 100%;
                margin-bottom: 12px;
            }
            
            .stock-receipt-duplicate-card .text-right {
                text-align: left !important;
            }
            
            .variant-row .col-md-3 {
                flex: 0 0 100%;
                max-width: 100%;
                margin-bottom: 15px;
            }
            
            .step-indicator {
                flex-direction: column;
            }
            
            .step {
                width: 100%;
                margin-bottom: 15px;
            }
            
            .step::after {
                display: none;
            }
        }
        
        /* ===== TOAST NOTIFICATIONS ===== */
        .toast-notification {
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            animation: slideInRight 0.3s ease-out;
            border: 2px solid;
        }
        
        .toast-notification.success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            border-color: #047857;
            color: var(--white);
        }
        
        .toast-notification.error {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            border-color: #b91c1c;
            color: var(--white);
        }
        
        .toast-notification.info {
            background: linear-gradient(135deg, var(--primary-blue) 0%, #1d4ed8 100%);
            border-color: #1e40af;
            color: var(--white);
        }
        
        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        /* ===== LOGOUT MODAL STYLING ===== */
        #logoutModal .modal-header {
            background: var(--white);
            color: var(--text-dark);
            border-radius: 12px 12px 0 0;
            border-bottom: 2px solid var(--gray-border);
        }
        
        #logoutModal .modal-title {
            color: var(--text-dark);
            font-weight: 600;
        }
        
        #logoutModal .modal-body {
            padding: 24px;
            font-size: 15px;
            color: var(--text-dark);
        }
        
        #logoutModal .modal-footer {
            border-top: 1px solid var(--gray-border);
            padding: 16px 24px;
        }
        
        #logoutModal .btn-secondary {
            background: var(--gray-light);
            color: var(--text-dark);
            border: none;
        }
        
        #logoutModal .btn-secondary:hover {
            background: var(--gray-border);
        }
        
        #logoutModal .btn-primary {
            background: linear-gradient(135deg, var(--primary-blue) 0%, var(--dark-blue) 100%);
            border: none;
        }
        
        #logoutModal .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(37, 99, 235, 0.3);
        }
    </style>
</head>

<body id="page-top">
    <div id="wrapper">
        <!-- Sidebar -->
        <?php include 'includes/sidebar.php'; ?>

        <!-- Content Wrapper -->
        <div id="content-wrapper" class="d-flex flex-column">
            <div id="content">
                <!-- Topbar -->
                <nav class="navbar navbar-expand navbar-light bg-white topbar mb-4 static-top shadow">

                    <!-- Sidebar Toggle (Topbar) -->
                    <button id="sidebarToggleTop" class="btn btn-link d-md-none rounded-circle mr-3">
                        <i class="fa fa-bars"></i>
                    </button>

                    <!-- Topbar Search -->
                    <form
                        class="d-none d-sm-inline-block form-inline mr-auto ml-md-3 my-2 my-md-0 mw-100 navbar-search">
                        <div class="input-group">
                            <input type="text" class="form-control bg-light border-0 small" placeholder="Tìm kiếm sản phẩm..."
                                aria-label="Search" aria-describedby="basic-addon2">
                            <div class="input-group-append">
                                <button class="btn btn-primary" type="button">
                                    <i class="fas fa-search fa-sm"></i>
                                </button>
                            </div>
                        </div>
                    </form>

                    <!-- Topbar Navbar -->
                    <ul class="navbar-nav ml-auto">

                        <!-- Nav Item - Search Dropdown (Visible Only XS) -->
                        <li class="nav-item dropdown no-arrow d-sm-none">
                            <a class="nav-link dropdown-toggle" href="#" id="searchDropdown" role="button"
                                data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <i class="fas fa-search fa-fw"></i>
                            </a>
                            <!-- Dropdown - Messages -->
                            <div class="dropdown-menu dropdown-menu-right p-3 shadow animated--grow-in"
                                aria-labelledby="searchDropdown">
                                <form class="form-inline mr-auto w-100 navbar-search">
                                    <div class="input-group">
                                        <input type="text" class="form-control bg-light border-0 small"
                                            placeholder="Tìm kiếm..." aria-label="Search"
                                            aria-describedby="basic-addon2">
                                        <div class="input-group-append">
                                            <button class="btn btn-primary" type="button">
                                                <i class="fas fa-search fa-sm"></i>
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </li>

                        <!-- Nav Item - Alerts -->
                        <li class="nav-item dropdown no-arrow mx-1">
                            <a class="nav-link dropdown-toggle" href="#" id="alertsDropdown" role="button"
                                data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <i class="fas fa-bell fa-fw"></i>
                                <!-- Counter - Alerts -->
                                <span class="badge badge-danger badge-counter">3+</span>
                            </a>
                            <!-- Dropdown - Alerts -->
                            <div class="dropdown-list dropdown-menu dropdown-menu-right shadow animated--grow-in"
                                aria-labelledby="alertsDropdown">
                                <h6 class="dropdown-header">
                                    Thông báo
                                </h6>
                                <a class="dropdown-item d-flex align-items-center" href="#">
                                    <div class="mr-3">
                                        <div class="icon-circle bg-primary">
                                            <i class="fas fa-file-alt text-white"></i>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="small text-gray-500">December 12, 2019</div>
                                        <span class="font-weight-bold">Sản phẩm mới đã được thêm!</span>
                                    </div>
                                </a>
                                <a class="dropdown-item d-flex align-items-center" href="#">
                                    <div class="mr-3">
                                        <div class="icon-circle bg-success">
                                            <i class="fas fa-donate text-white"></i>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="small text-gray-500">December 7, 2019</div>
                                        AI đã phân tích thành công 290 sản phẩm!
                                    </div>
                                </a>
                                <a class="dropdown-item text-center small text-gray-500" href="#">Xem tất cả thông báo</a>
                            </div>
                        </li>

                        <!-- Nav Item - Messages -->
                        <li class="nav-item dropdown no-arrow mx-1">
                            <a class="nav-link dropdown-toggle" href="#" id="messagesDropdown" role="button"
                                data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <i class="fas fa-envelope fa-fw"></i>
                                <!-- Counter - Messages -->
                                <span class="badge badge-danger badge-counter">7</span>
                            </a>
                            <!-- Dropdown - Messages -->
                            <div class="dropdown-list dropdown-menu dropdown-menu-right shadow animated--grow-in"
                                aria-labelledby="messagesDropdown">
                                <h6 class="dropdown-header">
                                    Tin nhắn
                                </h6>
                                <a class="dropdown-item d-flex align-items-center" href="#">
                                    <div class="dropdown-list-image mr-3">
                                        <img class="rounded-circle" src="img/undraw_profile_1.svg"
                                            alt="...">
                                        <div class="status-indicator bg-success"></div>
                                    </div>
                                    <div class="font-weight-bold">
                                        <div class="text-truncate">Cần kiểm tra sản phẩm trùng lặp.</div>
                                        <div class="small text-gray-500">Emily Fowler · 58m</div>
                                    </div>
                                </a>
                                <a class="dropdown-item text-center small text-gray-500" href="#">Đọc thêm tin nhắn</a>
                            </div>
                        </li>

                        <div class="topbar-divider d-none d-sm-block"></div>

                        <!-- Nav Item - User Information -->
                        <li class="nav-item dropdown no-arrow">
                            <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button"
                                data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <span class="mr-2 d-none d-lg-inline text-gray-600 small"><?php echo $_SESSION['username'] ?? 'User'; ?></span>
                                <img class="img-profile rounded-circle"
                                    src="img/undraw_profile.svg">
                            </a>
                            <!-- Dropdown - User Information -->
                            <div class="dropdown-menu dropdown-menu-right shadow animated--grow-in"
                                aria-labelledby="userDropdown">
                                <h6 class="dropdown-header">
                                    <i class="fas fa-cogs fa-sm fa-fw mr-2"></i>Cài Đặt
                                </h6>
                                <a class="dropdown-item" href="profile_settings.php">
                                    <i class="fas fa-user fa-sm fa-fw mr-2 text-gray-400"></i>
                                    Hồ Sơ
                                </a>
                                <a class="dropdown-item" href="change_password.php">
                                    <i class="fas fa-key fa-sm fa-fw mr-2 text-gray-400"></i>
                                    Đổi Mật Khẩu
                                </a>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item" href="#" data-toggle="modal" data-target="#logoutModal">
                                    <i class="fas fa-sign-out-alt fa-sm fa-fw mr-2 text-gray-400"></i>
                                    Đăng Xuất
                                </a>
                            </div>
                        </li>

                    </ul>

                </nav>

                <!-- Main Content -->
                <div class="container-fluid">
                    <div class="d-sm-flex align-items-center justify-content-between mb-4">
                        <h1 class="h3 mb-0 text-gray-800" id="pageTitle">
                            <?php if ($existingReceipt): ?>
                                Thêm sản phẩm vào phiếu #<?php echo str_pad($fromReceipt, 6, '0', STR_PAD_LEFT); ?>
                            <?php else: ?>
                                Tạo phiếu nhập kho thông minh
                            <?php endif; ?>
                        </h1>
                    </div>
                    
                    <?php if ($existingReceipt): ?>
                    <div class="alert alert-info alert-dismissible fade show" role="alert">
                        <i class="fas fa-info-circle mr-2"></i>
                        <strong>Chế độ thêm sản phẩm:</strong> Bạn đang thêm sản phẩm vào phiếu nhập <strong>#<?php echo str_pad($fromReceipt, 6, '0', STR_PAD_LEFT); ?></strong>
                        (Nhà cung cấp: <?php echo htmlspecialchars($existingReceipt['supplier_name']); ?>)
                        <button type="button" class="close" data-dismiss="alert">
                            <span>&times;</span>
                        </button>
                    </div>
                    <?php endif; ?>

                    <!-- Step Indicator -->
                    <div class="step-indicator">
                        <div class="step active" id="step1">
                            <div class="step-number">1</div>
                            <span>Thông tin cơ bản</span>
                        </div>
                        <div class="step" id="step2">
                            <div class="step-number">2</div>
                            <span>Tải ảnh sản phẩm</span>
                        </div>
                        <div class="step" id="step3">
                            <div class="step-number">3</div>
                            <span>AI phân tích</span>
                        </div>
                        <div class="step" id="step4">
                            <div class="step-number">4</div>
                            <span>Chỉnh sửa thông tin</span>
                        </div>
                        <div class="step" id="step5">
                            <div class="step-number">5</div>
                            <span>Hoàn thành</span>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-lg-12">
                            <!-- Step 1: Basic Info -->
                            <div class="card shadow mb-4" id="step1-content">
                                <div class="card-header py-3 bg-primary text-white">
                                    <h6 class="m-0 font-weight-bold">
                                        <i class="fas fa-info-circle mr-2"></i>Thông tin cơ bản
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <!-- Cột trái -->
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="receiptId" class="font-weight-bold">Mã phiếu nhập <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control" id="receiptId" 
                                                       value="Tự động tạo" readonly 
                                                       style="background-color: #f8f9fc; border: 1px solid #d1d3e2;">
                                            </div>
                                            
                                            <div class="form-group">
                                                <label for="receiptDate" class="font-weight-bold">Ngày nhập <span class="text-danger">*</span></label>
                                                <input type="date" class="form-control" id="receiptDate" 
                                                       value="<?php echo date('Y-m-d'); ?>"
                                                       style="border: 1px solid #d1d3e2;">
                                            </div>
                                            
                                            <div class="form-group">
                                                <label for="supplierSelect" class="font-weight-bold">Nhà cung cấp <span class="text-danger">*</span></label>
                                                <div class="d-flex">
                                                    <select class="form-control mr-2" id="supplierSelect" required 
                                                            style="border: 1px solid #d1d3e2;">
                                                        <option value="">-- Chọn nhà cung cấp --</option>
                                                        <?php foreach ($suppliers as $supplier): ?>
                                                            <?php 
                                                                $isInactive = isset($supplier['status']) && $supplier['status'] === 'inactive';
                                                                $disabledAttr = $isInactive ? 'disabled' : '';
                                                                $inactiveLabel = $isInactive ? ' [TẠM NGƯNG - Cần kích hoạt tại Quản lý nhà cung cấp]' : '';
                                                            ?>
                                                            <option value="<?php echo $supplier['supplier_id']; ?>" 
                                                                    <?php echo $disabledAttr; ?>
                                                                    data-phone="<?php echo htmlspecialchars($supplier['phone'] ?? 'Chưa có số điện thoại'); ?>"
                                                                    data-status="<?php echo $supplier['status'] ?? 'active'; ?>">
                                                                <?php echo htmlspecialchars($supplier['name']); ?>
                                                                <?php echo $inactiveLabel; ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <button type="button" class="btn btn-success" id="addSupplierBtn" title="Thêm nhà cung cấp mới">
                                                        <i class="fas fa-plus"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Cột phải -->
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="warehouseInfo" class="font-weight-bold">Kho nhập</label>
                                                <input type="text" class="form-control" id="warehouseInfo" 
                                                       value="<?php echo htmlspecialchars($_SESSION['warehouse_name'] ?? 'Chưa xác định'); ?>" 
                                                       readonly style="background-color: #f8f9fc; border: 1px solid #d1d3e2;">
                                            </div>
                                            
                                            <div class="form-group">
                                                <label for="receiptNotes" class="font-weight-bold">Ghi chú</label>
                                                <textarea class="form-control" id="receiptNotes" rows="4" 
                                                          style="border: 1px solid #d1d3e2; resize: vertical;"
                                                          placeholder="Nhập ghi chú về phiếu nhập này..."></textarea>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Thông báo hướng dẫn -->
                                    <div class="alert alert-info mt-3 mb-0">
                                        <i class="fas fa-info-circle mr-2"></i>
                                        <strong>Hướng dẫn:</strong> Vui lòng điền đầy đủ thông tin cơ bản trước khi tiếp tục đến bước upload ảnh và phân tích sản phẩm.
                                    </div>
                                </div>
                                
                                <!-- Buttons for normal mode -->
                                <div class="text-center mt-4">
                                    <button type="button" class="btn btn-primary btn-lg" id="nextToUpload" disabled 
                                            style="border-radius: 10px; padding: 15px 40px; font-weight: 600;">
                                        Tiếp theo: Upload ảnh <i class="fas fa-arrow-right ml-2"></i>
                                    </button>
                                </div>
                            </div>

                            <!-- Step 2: Upload Image -->
                            <div class="card shadow mb-4" id="step2-content" style="display: none;">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">
                                        <i class="fas fa-camera"></i> Bước 2: Tải ảnh sản phẩm
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <!-- Hướng dẫn chụp ảnh chi tiết -->
                                    <div class="alert alert-info mb-4">
                                        <h6 class="font-weight-bold mb-3">
                                            <i class="fas fa-camera-retro"></i> Hướng dẫn chụp ảnh để AI phân tích chính xác:
                                        </h6>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <h6 class="text-primary"><i class="fas fa-check-circle"></i> Góc chụp cần có:</h6>
                                                <ul class="mb-3">
                                                    <li><strong>Ảnh 1 - Góc nghiêng (3/4):</strong> Chụp từ phía trước bên cạnh để thấy rõ thương hiệu/logo</li>
                                                    <li><strong>Ảnh 2 - Mặt bên:</strong> Chụp toàn cảnh mặt bên phải hoặc trái</li>
                                                    <li><strong>Ảnh 3 - Phía sau:</strong> Chụp phần gót/đế sau để thấy chi tiết</li>
                                                    
                                                </ul>
                                                
                                                <h6 class="text-success"><i class="fas fa-lightbulb"></i> Điểm chú ý:</h6>
                                                <ul class="mb-0">
                                                    <li><strong>Logo/Thương hiệu:</strong> Phải rõ ràng, không bị mờ hoặc che khuất</li>
                                                    <li><strong>Màu sắc:</strong> Ánh sáng tự nhiên, không quá tối hoặc quá sáng</li>
                                                    <li><strong>Nền:</strong> Trắng hoặc tối màu đơn giản, không có vật dụng khác</li>
                                                    <li><strong>Toàn bộ sản phẩm:</strong> Chụp trọn vẹn, không bị cắt mất phần nào</li>
                                                </ul>
                                            </div>
                                            <div class="col-md-6">
                                                <h6 class="text-danger"><i class="fas fa-times-circle"></i> Tránh:</h6>
                                                <ul class="mb-3">
                                                    <li>❌ Ảnh bóng mờ, không rõ nét</li>
                                                    <li>❌ Góc chụp từ trên xuống (top-down) quá nhiều</li>
                                                    <li>❌ Che logo/thương hiệu bằng tay hoặc vật khác</li>
                                                    <li>❌ Nền lộn xộn có nhiều vật dụng</li>
                                                    <li>❌ Ảnh quá tối hoặc bị ngược sáng</li>
                                                    <li>❌ Chụp nhiều sản phẩm trong 1 ảnh</li>
                                                </ul>
                                                
                                                <div class="bg-light p-3 rounded">
                                                    <h6 class="text-info mb-2"><i class="fas fa-star"></i> Mẹo chụp ảnh tốt:</h6>
                                                    <ul class="small mb-0">
                                                        <li>📱 Dùng điện thoại với camera tốt (≥12MP)</li>
                                                        <li>💡 Chụp ở nơi có ánh sáng tự nhiên</li>
                                                        <li>📐 Đặt giày ngang tầm mắt máy ảnh</li>
                                                        <li>🎯 Zoom để logo thương hiệu chiếm ~30% ảnh</li>
                                                        <li>✨ Lau sạch sản phẩm trước khi chụp</li>
                                                    </ul>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="upload-area" id="uploadArea">
                                        <div class="upload-icon">
                                            <i class="fas fa-cloud-upload-alt"></i>
                                        </div>
                                        <h5>Chọn ảnh sản phẩm</h5>
                                        <p class="text-muted">Kéo thả hoặc nhấn để chọn 2-4 ảnh</p>
                                        <p class="text-muted small">
                                            <strong>Lưu ý:</strong> Hệ thống sẽ phân tích tối đa 3 ảnh đầu tiên để tối ưu hiệu suất
                                        </p>
                                        <p class="text-muted small">
                                            <strong>Yêu cầu kỹ thuật:</strong> JPG, PNG, WebP | ≤5MB | ≥800×800px | Tỷ lệ: 1:1 hoặc 4:5
                                        </p>
                                        <input type="file" id="fileInput" accept="image/*" multiple style="display: none;">
                                        <button type="button" class="btn btn-primary mt-3" onclick="document.getElementById('fileInput').click()">
                                            <i class="fas fa-images mr-2"></i>Chọn ảnh sản phẩm
                                        </button>
                                    </div>
                                    
                                    <div id="imagePreview"></div>
                                    
                                    <div class="text-center mt-4" id="analyzeSection" style="display: none;">
                                        <button class="btn btn-ai btn-lg" type="button" id="analyzeBtn">
                                            <i class="fas fa-magic"></i> AI Phân tích
                                        </button>
                                        <button class="btn btn-outline-secondary btn-lg ml-2" type="button" id="resetBtn">
                                            <i class="fas fa-redo"></i> Bắt đầu lại
                                        </button>
                                        <p class="text-muted mt-2">Nhấn để AI phân tích và đưa ra gợi ý thông tin sản phẩm</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Step 3: AI Analysis -->
                            <div class="card shadow mb-4" id="step3-content" style="display: none;">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">
                                        <i class="fas fa-brain"></i> Bước 3: AI phân tích hình ảnh
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <!-- Hiển thị ảnh đang được phân tích -->
                                    <div id="analyzingImages" style="display: none;">
                                        <h6><i class="fas fa-images"></i> Hình ảnh đang được phân tích:</h6>
                                        <div id="analyzingImagePreview" class="mb-3"></div>
                                    </div>
                                    
                                    <div class="loading-spinner" id="loadingSpinner">
                                        <div class="spinner-border text-primary" role="status">
                                            <span class="sr-only">Đang phân tích...</span>
                                        </div>
                                        <p class="mt-2">AI đang phân tích hình ảnh...</p>
                                    </div>
                                    
                                    <div id="aiResults" style="display: none;">
                                        <!-- Thông tin AI đã tự động điền -->
                                        <div class="alert alert-success mb-3" id="aiAutoFilledInfo" style="display: none;">
                                            <h6 class="mb-3">
                                                <i class="fas fa-robot"></i> 
                                                <strong>AI đã tự động điền thông tin sản phẩm:</strong>
                                            </h6>
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <table class="table table-sm table-borderless mb-0">
                                                        <tbody id="aiAutoFilledTable1"></tbody>
                                                    </table>
                                                </div>
                                                <div class="col-md-6">
                                                    <table class="table table-sm table-borderless mb-0">
                                                        <tbody id="aiAutoFilledTable2"></tbody>
                                                    </table>
                                                </div>
                                            </div>
                                            <hr class="my-2">
                                            <div class="alert alert-info mb-0 mt-2">
                                                <i class="fas fa-info-circle"></i> 
                                                <strong>Lưu ý:</strong> Hệ thống đang tự động <strong>kiểm tra trùng lặp</strong> dựa trên thông tin này.
                                            </div>
                                        </div>
                                        
                                        <!-- Kết quả kiểm tra trùng lặp tự động (giống add_product_ai.php) -->
                                        <div id="aiDuplicateCheckResult" class="mb-3" style="display: none;"></div>
                                        
                                        <!-- Kết quả kiểm tra trùng lặp cho stock receipt (old style - giữ lại cho tính năng nhập kho) -->
                                        <div id="stockReceiptDuplicateResults" style="display: none;">
                                            <!-- Duplicate results will be displayed here -->
                                        </div>
                                        
                                        <div class="ai-analysis-result">
                                            <h6><i class="fas fa-tags"></i> Phân tích đặc điểm sản phẩm:</h6>
                                            <div id="productTags"></div>
                                        </div>
                                        
                                        <div class="ai-analysis-result">
                                            <h6><i class="fas fa-palette"></i> Màu sắc chủ đạo:</h6>
                                            <div id="dominantColors"></div>
                                        </div>
                                        
                                        <div class="text-center">
                                            <button type="button" class="btn btn-secondary mr-2" id="backToUpload">
                                                <i class="fas fa-arrow-left"></i> Quay lại
                                            </button>
                                            <button type="button" class="btn btn-warning mr-2" id="testDuplicate" 
                                                    title="Kiểm tra trùng lặp dựa trên tên (30%), thương hiệu (25%), loại (25%), mô tả (10%), chất liệu (5%) và màu sắc (5%)">
                                                <i class="fas fa-search"></i> Test Kiểm tra trùng lặp
                                            </button>
                                            <button type="button" class="btn btn-success" id="proceedToEdit">
                                                <i class="fas fa-arrow-right"></i> Tiếp tục chỉnh sửa thông tin
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Step 4: Edit Product Info -->
                            <div class="card shadow mb-4" id="step4-content" style="display: none;">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold" style="color: #fef3c7;">
                                        <i class="fas fa-edit"></i> Bước 4: Chỉnh sửa thông tin sản phẩm
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <!-- Suggestion Cards Row -->
                                    <div class="row" id="suggestionCards" style="display: none;">
                                        <!-- Suggestion cards will be added here dynamically -->
                                    </div>

                                    <!-- Product Form -->
                                    <form id="productForm">
                                        <!-- Basic Information Card -->
                                        <div class="card shadow mb-4">
                                            <div class="card-header bg-gradient-info text-white">
                                                <h6 class="m-0 font-weight-bold">
                                                    <i class="fas fa-info-circle mr-2"></i>Thông tin cơ bản
                                                </h6>
                                            </div>
                                            <div class="card-body">
                                                <div class="row">
                                                    <div class="col-lg-6 col-md-12">
                                                        <div class="form-group">
                                                            <label for="productName" class="font-weight-bold">
                                                                <i class="fas fa-tag text-primary"></i> 
                                                                Tên sản phẩm <span class="text-danger">*</span>
                                                            </label>
                                                            <input type="text" class="form-control form-control-lg" id="productName" name="product_name" required
                                                                   placeholder="Nhập tên sản phẩm">
                                                        </div>

                                                        <div class="form-group">
                                                            <label for="brandName" class="font-weight-bold">
                                                                <i class="fas fa-copyright text-warning"></i> Thương hiệu
                                                            </label>
                                                            <div class="input-group">
                                                                <div class="input-group-prepend">
                                                                    <span class="input-group-text">
                                                                        <i class="fas fa-database text-info"></i>
                                                                    </span>
                                                                </div>
                                                                <input type="text" class="form-control" id="brandName" name="brand_name" 
                                                                       readonly style="background-color: #f8f9fc;">
                                                            </div>
                                                            <small class="text-muted">
                                                                <i class="fas fa-info-circle"></i> Thông tin từ database
                                                            </small>
                                                        </div>

                                                        <div class="form-group">
                                                            <label for="productType" class="font-weight-bold">
                                                                <i class="fas fa-list text-success"></i> Loại sản phẩm
                                                            </label>
                                                            <div class="input-group">
                                                                <div class="input-group-prepend">
                                                                    <span class="input-group-text">
                                                                        <i class="fas fa-database text-info"></i>
                                                                    </span>
                                                                </div>
                                                                <input type="text" class="form-control" id="productTypeText" name="product_type_text" 
                                                                       readonly style="background-color: #f8f9fc;">
                                                                <input type="hidden" id="productType" name="product_type">
                                                            </div>
                                                            <small class="text-muted">
                                                                <i class="fas fa-info-circle"></i> Thông tin từ database
                                                            </small>
                                                        </div>
                                                    </div>

                                                    <div class="col-lg-6 col-md-12">
                                                        <div class="form-group">
                                                            <label for="baseSku" class="font-weight-bold">
                                                                <i class="fas fa-barcode text-secondary"></i> 
                                                                SKU cơ bản <span class="text-danger">*</span>
                                                            </label>
                                                            <div class="input-group">
                                                                <input type="text" class="form-control" id="baseSku" name="base_sku" required
                                                                       placeholder="Ví dụ: CHAR-SLIN-028">
                                                                <div class="input-group-append">
                                                                    <button type="button" class="btn btn-outline-primary" id="generateSkuBtn" title="Tạo SKU tự động">
                                                                        <i class="fas fa-magic"></i>
                                                                    </button>
                                                                </div>
                                                            </div>
                                                            <small class="text-muted">
                                                                <i class="fas fa-lightbulb"></i> 
                                                                SKU sẽ được tự động thêm hậu tố theo size (ví dụ: CHAR-SLIN-028-38)
                                                            </small>
                                                        </div>

                                                        <!-- Color and Material Row -->
                                                        <div class="row">
                                                            <div class="col-md-6">
                                                                <div class="form-group">
                                                                    <label for="color" class="font-weight-bold">
                                                                        <i class="fas fa-palette text-danger"></i> Màu sắc
                                                                    </label>
                                                                    <input type="text" class="form-control" id="color" name="color" 
                                                                           placeholder="Ví dụ: Đỏ, Xanh">
                                                                </div>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <div class="form-group">
                                                                    <label for="material" class="font-weight-bold">
                                                                        <i class="fas fa-industry text-dark"></i> Chất liệu
                                                                    </label>
                                                                    <input type="text" class="form-control" id="material" name="material" 
                                                                           placeholder="Ví dụ: Da thật, Vải">
                                                                </div>
                                                            </div>
                                                        </div>

                                                        <div class="alert alert-light border-left-primary">
                                                            <div class="d-flex align-items-center">
                                                                <i class="fas fa-money-bill-wave text-primary mr-2"></i>
                                                                <div>
                                                                    <strong>Thông tin giá bán</strong>
                                                                    <br>
                                                                    <small class="text-muted">
                                                                        Giá được thiết lập riêng cho từng size ở bảng bên dưới
                                                                    </small>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Sizes and Quantities -->
                                        <div class="form-group">
                                            <div class="card shadow">
                                                <div class="card-header bg-gradient-primary text-white">
                                                    <h6 class="m-0 font-weight-bold">
                                                        <i class="fas fa-table mr-2"></i>
                                                        Thông tin Size, Số lượng nhập và Giá bán
                                                        <span class="text-warning">*</span>
                                                    </h6>
                                                </div>
                                                <div class="card-body">
                                                    <div class="mb-3">
                                                        <div class="alert alert-light border-left-primary">
                                                            <div class="d-flex align-items-center">
                                                                <i class="fas fa-hand-pointer text-primary mr-2"></i>
                                                                <div>
                                                                    <strong>Chọn size cần nhập kho</strong>
                                                                    <br>
                                                                    <small class="text-muted">
                                                                        <i class="fas fa-info-circle"></i>
                                                                        Click nút bên dưới để chọn size từ danh sách có sẵn của sản phẩm này
                                                                    </small>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    
                                                    <!-- Size Selection Area -->
                                                    <div class="mb-4" id="sizeSelectionArea">
                                                        <div class="card border-dashed" style="border-style: dashed !important; border-color: #007bff !important;">
                                                            <div class="card-body text-center py-4">
                                                                <i class="fas fa-mouse-pointer fa-3x text-primary mb-3"></i>
                                                                <h5 class="text-primary mb-2">Chọn Size để nhập kho</h5>
                                                                <p class="text-muted mb-3">
                                                                    Hệ thống sẽ tải danh sách size có sẵn của sản phẩm này
                                                                </p>
                                                                <button type="button" class="btn btn-primary btn-lg" id="selectSizesBtn">
                                                                    <i class="fas fa-list-ul mr-2"></i>
                                                                    Chọn Size từ danh sách
                                                                </button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    
                                                    <!-- Header for responsive table -->
                                                    <div class="d-none d-md-block mb-3">
                                                        <div class="row text-center font-weight-bold text-secondary" style="padding: 0 15px;">
                                                            <div class="col-md-2">
                                                                <i class="fas fa-tag"></i> SIZE
                                                            </div>
                                                            <div class="col-md-2">
                                                                <i class="fas fa-warehouse"></i> TỒN KHO
                                                            </div>
                                                            <div class="col-md-2">
                                                                <i class="fas fa-plus-circle text-danger"></i> SL NHẬP
                                                            </div>
                                                            <div class="col-md-2">
                                                                <i class="fas fa-money-bill-wave text-warning"></i> GIÁ NHẬP
                                                            </div>
                                                            <div class="col-md-2">
                                                                <i class="fas fa-hand-holding-usd text-success"></i> GIÁ BÁN
                                                            </div>
                                                            <div class="col-md-1">
                                                                <i class="fas fa-barcode"></i> SKU
                                                            </div>
                                                            <div class="col-md-1">
                                                                <i class="fas fa-map-marker-alt text-purple"></i> VỊ TRÍ
                                                            </div>
                                                        </div>
                                                        <hr class="my-2">
                                                    </div>
                                                    
                                                    <div id="sizesContainer">
                                                        <!-- Existing sizes will be displayed here -->
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                        <!-- Hidden fields -->
                        <input type="hidden" id="productId" name="product_id">
                        <input type="hidden" id="hiddenCategoryId" name="category_id">
                        <input type="hidden" id="images" name="images">
                        <input type="hidden" id="aiAnalysis" name="ai_analysis">                                        <!-- Enhanced Form Actions -->
                                        <div class="card shadow mt-4">
                                            <div class="card-body">
                                                <div class="row align-items-center">
                                                    <div class="col-md-6 mb-3 mb-md-0">
                                                        <div class="d-flex align-items-center">
                                                            <i class="fas fa-info-circle text-info mr-2"></i>
                                                            <div>
                                                                <small class="text-muted">
                                                                    <strong>Lưu ý:</strong> Vui lòng kiểm tra số lượng nhập cho từng size
                                                                </small>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <div class="d-flex justify-content-end">
                                                            <button type="button" class="btn btn-outline-secondary btn-lg mr-3" onclick="window.moveToStep(2)">
                                                                <i class="fas fa-arrow-left mr-2"></i>
                                                                <span class="d-none d-sm-inline">Quay lại</span>
                                                            </button>
                                                            <!-- Nút submit cũ - sẽ bị ẩn khi dùng flow database với bảng chọn size -->
                                                            <button type="submit" class="btn btn-success btn-lg" id="saveProductBtn" style="display: none;">
                                                                <i class="fas fa-plus-circle mr-2"></i>
                                                                <span class="d-none d-sm-inline">Thêm vào phiếu nhập</span>
                                                                <span class="d-inline d-sm-none">Thêm</span>
                                                            </button>
                                                            <!-- Nút xem phiếu nhập khi đã có sản phẩm -->
                                                            <button type="button" class="btn btn-info btn-lg ml-3" id="continueToStep5" style="display: none;" onclick="window.moveToStep(5)">
                                                                <span class="d-none d-sm-inline">Xem phiếu nhập</span>
                                                                <span class="d-inline d-sm-none">Xem phiếu</span>
                                                                <span class="badge badge-light ml-2" id="receiptItemsCountBadge">0</span>
                                                                <i class="fas fa-arrow-right ml-2"></i>
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            <!-- Step 5: Complete -->
                            <!-- Step 5: Hoàn thành phiếu nhập -->
                            <div class="card shadow mb-4" id="step5-content" style="display: none;">
                                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                                    <h6 class="m-0 font-weight-bold text-primary">Tóm tắt phiếu nhập</h6>
                                    <div>
                                        <button type="button" class="btn btn-sm btn-outline-secondary mr-2" onclick="toggleDebugPanel()" title="Debug Panel">
                                            <i class="fas fa-bug"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-primary" id="refreshSummaryBtn" title="Làm mới tóm tắt">
                                            <i class="fas fa-sync-alt"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div class="receipt-summary mb-4">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="card border-primary">
                                                    <div class="card-header bg-primary text-white">
                                                        <h6 class="mb-0"><i class="fas fa-truck"></i> Thông tin nhà cung cấp</h6>
                                                    </div>
                                                    <div class="card-body">
                                                        <p id="supplierInfo" class="mb-0"><i class="fas fa-spinner fa-spin"></i> Đang tải...</p>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="card border-info">
                                                    <div class="card-header bg-info text-white">
                                                        <h6 class="mb-0"><i class="fas fa-file-invoice"></i> Thông tin phiếu nhập</h6>
                                                    </div>
                                                    <div class="card-body">
                                                        <p class="mb-1"><strong><i class="fas fa-user"></i> Người tạo:</strong> <?php echo htmlspecialchars($_SESSION['username'] ?? 'User'); ?></p>
                                                        <p class="mb-1"><strong><i class="fas fa-calendar"></i> Ngày tạo:</strong> <span id="currentDate"></span></p>
                                                        <p class="mb-0"><strong><i class="fas fa-comment"></i> Ghi chú:</strong> <span id="receiptNotesDisplay"></span></p>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <h5 class="mb-3"><i class="fas fa-box"></i> Danh sách sản phẩm nhập kho:</h5>
                                    <div id="receiptItemsList"></div>
                                    
                                    <!-- Debug Panel -->
                                    <div class="mt-3 p-3 bg-light border rounded" id="debugPanel" style="display: none;">
                                        <h6 class="text-primary">🔧 Debug Panel</h6>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <button class="btn btn-sm btn-info" onclick="debugReceiptState()">Check State</button>
                                                <button class="btn btn-sm btn-success" onclick="addTestData()">Add Test Data</button>
                                                <button class="btn btn-sm btn-warning" onclick="clearReceiptData()">Clear Data</button>
                                            </div>
                                            <div class="col-md-6">
                                                <button class="btn btn-sm btn-primary" onclick="updateReceiptSummary()">Force Update</button>
                                                <button class="btn btn-sm btn-secondary" onclick="toggleDebugPanel()">Hide Debug</button>
                                            </div>
                                        </div>
                                        <div class="mt-2">
                                            <small><strong>Items Count:</strong> <span id="debugItemCount">0</span></small><br>
                                            <small><strong>Supplier:</strong> <span id="debugSupplier">None</span></small>
                                        </div>
                                    </div>
                                    
                                    <div class="mt-4 text-right">
                                        <p class="h5"><strong>Tổng tiền: <span id="totalAmount" class="text-success">0 VNĐ</span></strong></p>
                                    </div>

                                    <div class="mt-4">
                                        <button type="button" class="btn btn-secondary" id="backToDetails">
                                            <i class="fas fa-arrow-left mr-2"></i>Quay lại
                                        </button>
                                        <button type="button" class="btn btn-warning ml-2" id="saveDraftBtn">
                                            <i class="fas fa-save mr-2"></i>Lưu bản nháp
                                        </button>
                                        <button type="button" class="btn btn-success ml-2" id="updateReceiptBtn" style="display:none;">
                                            <i class="fas fa-check-circle mr-2"></i>Cập nhật phiếu nhập
                                        </button>
                                        <button type="button" class="btn btn-primary ml-2" id="addMoreItems">
                                            <i class="fas fa-plus mr-2"></i>Thêm sản phẩm khác
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <?php include 'includes/footer.php'; ?>
        </div>
    </div>

    <!-- Modal kiểm tra sản phẩm trùng lặp -->
    <div class="modal fade" id="duplicateCheckModal" tabindex="-1" role="dialog" aria-labelledby="duplicateCheckModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="duplicateCheckModalLabel">
                        <i class="fas fa-exclamation-triangle"></i>
                        Sản phẩm trùng mẫu
                    </h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <h6><i class="fas fa-exclamation-triangle"></i> Phát hiện sản phẩm có thể trùng lặp:</h6>
                        <p class="mb-2">Hệ thống phát hiện có <span id="duplicateCount">0</span> sản phẩm có độ tương đồng cao với sản phẩm bạn vừa upload.</p>
                        <div class="small text-info mb-2">
                            <i class="fas fa-info-circle"></i> 
                            <strong>Tiêu chí so sánh:</strong> Tên sản phẩm (30%), Thương hiệu (25%), Loại (25%), Mô tả (10%), Chất liệu (5%), Màu sắc (5%)
                        </div>
                        <p class="mb-0"><strong>Vui lòng so sánh và chọn một trong các tùy chọn bên dưới:</strong></p>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header bg-primary text-white">
                                    <h6 class="mb-0"><i class="fas fa-upload"></i> Sản phẩm mới vừa upload</h6>
                                </div>
                                <div class="card-body">
                                    <div id="newProductPreview">
                                        <!-- Sẽ được điền bởi JavaScript -->
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header bg-info text-white">
                                    <h6 class="mb-0"><i class="fas fa-database"></i> Sản phẩm nghi ngờ trùng trong hệ thống</h6>
                                </div>
                                <div class="card-body" style="max-height: 400px; overflow-y: auto;">
                                    <div id="duplicateProductsList">
                                        <!-- Sẽ được điền bởi JavaScript -->
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <div class="row w-100">
                        <div class="col-md-12 text-center">
                            <button type="button" class="btn btn-warning btn-lg mr-3" id="updateExistingProduct">
                                <i class="fas fa-warehouse"></i> Tiếp tục nhập kho
                            </button>
                            <button type="button" class="btn btn-success btn-lg mr-3" id="continueAddNew">
                                <i class="fas fa-plus"></i> Tiếp tục thêm mới
                            </button>
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">
                                <i class="fas fa-times"></i> Hủy
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Update Source Selection Modal -->
    <div class="modal fade" id="updateSourceModal" tabindex="-1" role="dialog" aria-labelledby="updateSourceModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="updateSourceModalLabel">
                        <i class="fas fa-warehouse"></i> Chọn nguồn thông tin nhập kho
                    </h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-12 mb-3">
                            <div class="alert alert-info">
                                <i class="fas fa-warehouse"></i> 
                                <strong>Chọn nguồn dữ liệu:</strong> Bạn có thể sử dụng thông tin sản phẩm hiện có hoặc thông tin được AI phân tích từ ảnh mới.
                                <br><br>
                                <i class="fas fa-info-circle text-primary"></i> <strong>Lưu ý:</strong> 
                                Thông tin sản phẩm sẽ được load vào form, bạn có thể chỉnh sửa trước khi tạo phiếu nhập kho.
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-12">
                            <div class="card h-100 update-source-option selected" data-source="existing">
                                <div class="card-header bg-primary text-white text-center">
                                    <h6 class="mb-0"><i class="fas fa-database"></i> Từ sản phẩm hiện tại</h6>
                                </div>
                                <div class="card-body text-center">
                                    <i class="fas fa-archive fa-3x text-primary mb-3"></i>
                                    <h6>Sử dụng thông tin sản phẩm hiện có</h6>
                                    <p class="text-muted">
                                        Lấy tất cả thông tin từ sản phẩm hiện có trong database.
                                        Phù hợp cho việc nhập kho sản phẩm đã tồn tại.
                                    </p>
                                    <ul class="text-left small">
                                        <li>Thông tin đầy đủ từ database</li>
                                        <li>Bao gồm variants và sizes</li>
                                        <li>Giá và chi tiết chính xác</li>
                                        <li>Tồn kho hiện tại</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">
                        <i class="fas fa-times"></i> Hủy
                    </button>
                    <button type="button" class="btn btn-success" id="confirmUpdateSource" disabled>
                        <i class="fas fa-warehouse"></i> Tiếp tục nhập kho
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Size Selection Modal -->
    <div class="modal fade" id="sizeSelectionModal" tabindex="-1" role="dialog" aria-labelledby="sizeSelectionModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="sizeSelectionModalLabel">
                        <i class="fas fa-check-square mr-2"></i>
                        Chọn Size cần nhập (Multiple)
                    </h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <!-- Debug Test Section -->
                    <div class="alert alert-info">
                        <strong>🔧 Debug Mode:</strong>
                        <button type="button" class="btn btn-sm btn-primary ml-2" onclick="testDirectSizeLoad()">
                            Test Load Sizes với Product ID 24
                        </button>
                        <button type="button" class="btn btn-sm btn-success ml-2" onclick="testProductDetailsAPI()">
                            Test API Product Details ID 24
                        </button>
                        <button type="button" class="btn btn-sm btn-warning ml-2" onclick="testSelectProduct()">
                            Test Select Product ID 24
                        </button>
                        <button type="button" class="btn btn-sm btn-danger ml-2" onclick="testUpdateFromExistingData()">
                            Test Direct DB Load ID 24
                        </button>
                        <br><br>
                        <button type="button" class="btn btn-sm btn-info" onclick="testDatabaseOnlyFill()">
                            Test DB Fill Only (No Step Movement)
                        </button>
                        <button type="button" class="btn btn-sm btn-success" onclick="testCompleteDatabaseWorkflow()" style="font-weight: bold;">
                            🆕 TEST TRUNG_LAP_TC WORKFLOW
                        </button>
                        <div id="debugInfo" style="margin-top: 10px; font-family: monospace; font-size: 12px; background: #f8f9fa; padding: 8px; border-radius: 4px;"></div>
                    </div>
                    
                    <!-- Loading state -->
                    <div id="sizesLoadingState" class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="sr-only">Đang tải...</span>
                        </div>
                        <p class="text-muted mt-2">Đang tải danh sách size...</p>
                    </div>
                    
                    <!-- Available sizes list -->
                    <div id="availableSizesList" style="display: none;">
                        <div id="sizesCheckboxContainer">
                            <!-- Size dropdown will be populated here by JavaScript -->
                        </div>
                    </div>
                    
                    <!-- No sizes found -->
                    <div id="noSizesFound" style="display: none;" class="text-center py-4">
                        <i class="fas fa-exclamation-triangle fa-3x text-warning mb-3"></i>
                        <h5 class="text-warning">Không tìm thấy size nào</h5>
                        <p class="text-muted">Sản phẩm này chưa có size nào trong hệ thống.</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <div class="text-center w-100">
                        <button type="button" class="btn btn-secondary mr-3" data-dismiss="modal">
                            <i class="fas fa-times mr-1"></i> Đóng
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal chọn nội dung sản phẩm -->
    <div class="modal fade" id="chooseContentModal" tabindex="-1" role="dialog" aria-labelledby="chooseContentModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="chooseContentModalLabel">
                        <i class="fas fa-question-circle"></i>
                        Chọn nội dung sản phẩm
                    </h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <h6><i class="fas fa-info-circle"></i> Lưu ý:</h6>
                        <p class="mb-0">Bạy có thể chọn cập nhật mục sản phẩm hiện có. Thông tin nào AI có đưa ra gợi ý cao nhất?</p>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="card h-100 content-option" data-option="existing" style="cursor: pointer;">
                                <div class="card-header bg-primary text-white text-center">
                                    <h6 class="mb-0"><i class="fas fa-edit"></i> Chỉnh sửa thông tin sản phẩm</h6>
                                </div>
                                <div class="card-body text-center">
                                    <i class="fas fa-database fa-3x text-primary mb-3"></i>
                                    <h6>Sử dụng thông tin sản phẩm hiện có</h6>
                                    <p class="text-muted small">
                                        Lấy tất cả thông tin từ sản phẩm hiện có trong hệ thống. 
                                        Bạn có thể chỉnh sửa và thêm thông tin mới.
                                    </p>
                                    <ul class="text-left small text-muted">
                                        <li>Giữ nguyên tên và mô tả đã có</li>
                                        <li>Giữ nguyên thương hiệu</li>
                                        <li>Giữ nguyên màu sắc và giá cả</li>
                                        <li>Có thể chỉnh sửa và cập nhật</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card h-100 content-option" data-option="ai" style="cursor: pointer;">
                                <div class="card-header bg-success text-white text-center">
                                    <h6 class="mb-0"><i class="fas fa-brain"></i> Chỉnh sửa với thông tin sản phẩm</h6>
                                </div>
                                <div class="card-body text-center">
                                    <i class="fas fa-robot fa-3x text-success mb-3"></i>
                                    <h6>Sử dụng gợi ý từ AI phân tích</h6>
                                    <p class="text-muted small">
                                        Sử dụng thông tin được AI phân tích từ ảnh mới upload. 
                                        Thông tin có thể chính xác hơn với ảnh hiện tại.
                                    </p>
                                    <ul class="text-left small text-muted">
                                        <li>Tên và mô tả từ AI phân tích</li>
                                        <li>Thương hiệu được AI nhận diện</li>
                                        <li>Màu sắc từ phân tích ảnh mới</li>
                                        <li>Kết hợp với dữ liệu hiện có</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">
                        <i class="fas fa-times"></i> Hủy
                    </button>
                    <button type="button" class="btn btn-primary" id="confirmContentChoice" disabled>
                        <i class="fas fa-check"></i> Xác nhận
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap core JavaScript-->
    <script src="vendor/jquery/jquery.min.js"></script>
    <script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script>

    <!-- Core plugin JavaScript-->
    <script src="vendor/jquery-easing/jquery.easing.min.js"></script>

    <!-- Custom scripts for all pages-->
    <script src="js/sb-admin-2.min.js"></script>

    <!-- SweetAlert2 JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.28/dist/sweetalert2.all.min.js"></script>

    <script>
        // Global variables để có thể truy cập từ mọi nơi
        let currentStep = 1;
        let aiData = null;
        let suggestions = null;
        let uploadedImages = [];
        let primaryImageIndex = 0;
        let isUpdateMode = false;
        let updateProductId = null;
        let useExistingDataOnly = false;
        let receiptItems = []; // Array lưu trữ danh sách sản phẩm trong phiếu nhập
        let selectedSupplier = null; // Thông tin nhà cung cấp được chọn
        const userWarehouseId = <?php echo $userWarehouseId ?? 'null'; ?>; // Warehouse ID từ PHP session
        
        // Lấy danh sách vị trí bị loại trừ từ localStorage (khi thêm sản phẩm vào phiếu hiện có)
        let excludedLocations = [];
        try {
            const excludedLocationsStr = localStorage.getItem('excluded_locations');
            if (excludedLocationsStr) {
                excludedLocations = JSON.parse(excludedLocationsStr);
                console.log('🚫 Excluded locations from current receipt:', excludedLocations);
            }
        } catch (e) {
            console.error('Error parsing excluded_locations:', e);
        }
        
        // ===== GLOBAL FUNCTIONS FOR STOCK RECEIPT DUPLICATE HANDLING =====
        // These functions must be defined early so onclick handlers can access them
        
        window.selectedStockReceiptDuplicate = null;
        
        // Function to select duplicate for update
        window.selectStockReceiptDuplicateForUpdate = function(element) {
            // Remove previous selections
            $('.stock-receipt-duplicate-card').removeClass('border-primary bg-light');
            $('.selected-indicator').hide();
            
            // Highlight selected card
            $(element).addClass('border-primary bg-light');
            $(element).find('.selected-indicator').show();
            
            // Store selected product data
            window.selectedStockReceiptDuplicate = {
                product_id: $(element).data('product-id'),
                product_name: $(element).data('product-name'),
                product_brand: $(element).data('product-brand')
            };
            
            // Enable update button (if exists)
            $('#updateSelectedStockReceiptDuplicateBtn').prop('disabled', false);
            
            console.log('✅ Selected duplicate for update:', window.selectedStockReceiptDuplicate);
        };
        
        // Function to view product details
        window.viewStockReceiptProductDetails = function(productId) {
            console.log('👁️ Viewing stock receipt product details:', productId);
            alert('Xem chi tiết sản phẩm ID: ' + productId + '\n(Tính năng này sẽ được cập nhật)');
        };
        
        // Function to continue with new product creation using AI data
        window.continueWithNewStockReceiptProduct = function(productData = null, mode = 'create_new') {
            console.log('➕ Continuing with new stock receipt product creation');
            console.log('🔧 Mode:', mode);
            console.log('📋 Product data:', productData);
            console.log('🤖 Available AI data:', window.aiData);
            
            // Reset flag for AI mode or create new mode
            if (mode !== 'existing') {
                window.useExistingDataOnly = false;
                useExistingDataOnly = false;
                console.log('🔄 Reset useExistingDataOnly for mode:', mode);
                
                // 🔓 ENABLE lại các trường để có thể chỉnh sửa
                console.log('🔓 Enabling form fields for editing...');
                $('#productName, #brandName, #productType, #productTypeText, #color, #baseSku, #material').prop('readonly', false).css({
                    'background-color': '',
                    'cursor': ''
                });
                
                // Enable lại nút "Tạo SKU tự động"
                $('#generateSkuBtn').prop('disabled', false).attr('title', 'Tạo SKU tự động');
                console.log('🔓 Enabled SKU generation button');
                
                // Xóa badge thông báo database nếu có
                $('#databaseInfoBadge').remove();
                console.log('✅ Form fields enabled for editing');
            }
            
            // Hide duplicate results
            $('#stockReceiptDuplicateResults').slideUp();
            
            // Move to step 4 first
            moveToStep(4);
            
            if (mode === 'ai' && productData) {
                // AI mode: Use selected product with AI enhancements
                console.log('🤖 Using AI mode with selected product');
                
                // Store the selected product
                window.selectedProductForReceipt = productData;
                window.isUpdateMode = true;
                window.updateProductId = productData.product_id;
                
                // Pre-populate form with AI data if available
                if (window.aiData && typeof window.aiData === 'object') {
                    populateFormWithAIData(window.aiData);
                    
                    // Show AI success message
                    const aiSuccessMsg = `
                        <div class="alert alert-info border-0 shadow-sm" id="aiDataLoadedSuccess">
                            <div class="d-flex align-items-center">
                                <div class="mr-3">
                                    <i class="fas fa-robot fa-2x text-info"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <h5 class="mb-1 text-info">🤖 Sử dụng thông tin AI cho sản phẩm "${productData.name}"</h5>
                                    <p class="mb-1">Hệ thống đã điền thông tin từ AI phân tích kết hợp với sản phẩm đã chọn.</p>
                                    <p class="mb-0"><strong>Bạn có thể chỉnh sửa trước khi tạo phiếu nhập kho.</strong></p>
                                </div>
                                <button type="button" class="close" onclick="$('#aiDataLoadedSuccess').fadeOut()">
                                    <span>&times;</span>
                                </button>
                            </div>
                        </div>
                    `;
                    $('#step4-content .card-body').prepend(aiSuccessMsg);
                }
            } else {
                // Create new mode: Use pure AI data
                console.log('➕ Using create new mode with AI data');
                
                // Pre-populate form with AI data if available
                if (window.aiData && typeof window.aiData === 'object') {
                    populateFormWithAIData(window.aiData);
                    
                    // Show success message about AI data usage
                    const aiSuccessMsg = `
                        <div class="alert alert-success border-0 shadow-sm" id="aiDataLoadedSuccess">
                            <div class="d-flex align-items-center">
                                <div class="mr-3">
                                    <i class="fas fa-robot fa-2x text-success"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <h5 class="mb-1 text-success">🤖 Đã điền thông tin từ AI</h5>
                                    <p class="mb-1">Hệ thống đã tự động điền các thông tin sản phẩm từ phân tích hình ảnh AI.</p>
                                    <p class="mb-0"><strong>Bạn có thể chỉnh sửa các thông tin trước khi lưu.</strong></p>
                                </div>
                                <button type="button" class="close" onclick="$('#aiDataLoadedSuccess').fadeOut()">
                                    <span>&times;</span>
                                </button>
                            </div>
                        </div>
                    `;
                    $('#step4-content .card-body').prepend(aiSuccessMsg);
                    
                    // Auto hide success message after 7 seconds
                    setTimeout(() => {
                        $('#aiDataLoadedSuccess').fadeOut();
                    }, 7000);
                } else {
                    console.log('⚠️ No AI data available - form will be empty');
                }
            }
        };
        
        // Function to select existing product for stock receipt (enhanced from Trung_lap_TC)
        window.selectProductForStockReceipt = function(productId, productName) {
            console.log('🎯 Selecting existing product for stock receipt:', productId, productName);
            
            // Store selected product info globally
            window.selectedProductForReceipt = {
                product_id: productId,
                name: productName
            };
            
            console.log('💾 Stored selected product for receipt:', window.selectedProductForReceipt);
            
            // Hide inline duplicate results
            $('#stockReceiptDuplicateResults').slideUp();
            $('#aiDuplicateCheckResult').slideUp();
            
            // Show update source modal to choose between existing data or AI analysis
            $('#updateSourceModal').modal('show');
        };
        
        // Function to continue creating new product (ignore duplicates)
        window.continueCreateNewProduct = function() {
            console.log('✅ User chose to create NEW product despite duplicates');
            
            // Hide duplicate check result
            $('#aiDuplicateCheckResult').slideUp();
            
            // Move to step 4 (edit product info)
            moveToStep(4);
            
            // If we have AI data, populate the form
            if (window.aiData) {
                console.log('📝 Populating form with AI data for new product');
                displaySuggestions();
            }
            
            showToast('Tiếp tục tạo sản phẩm mới', 'info');
        };
        
        // Function to view product details (for duplicate check)
        window.viewProductDetails = function(productId) {
            console.log('👁️ Viewing product details for ID:', productId);
            
            // Open product detail page in new tab
            window.open(`product_details.php?id=${productId}`, '_blank');
        };
        
        // Function to show warning when trying to select inactive product
        window.showInactiveWarning = function() {
            Swal.fire({
                icon: 'warning',
                title: 'Sản phẩm đã tạm ngừng',
                html: `
                    <p>Sản phẩm này đang ở trạng thái <strong>Tạm ngừng</strong>.</p>
                    <p>Vui lòng liên hệ <strong>Quản lý</strong> hoặc <strong>Admin</strong> để kích hoạt lại sản phẩm trước khi tạo phiếu nhập kho.</p>
                `,
                confirmButtonText: 'Đã hiểu',
                confirmButtonColor: '#ffc107'
            });
        };
        
        // Function to reactivate inactive product
        window.reactivateProduct = function(productId, productName) {
            Swal.fire({
                title: 'Kích hoạt lại sản phẩm',
                html: `
                    <div class="text-center">
                        <i class="fas fa-play-circle text-success" style="font-size: 3rem; margin-bottom: 1rem;"></i>
                        <p class="mb-3">Bạn có muốn kích hoạt lại sản phẩm:</p>
                        <p class="font-weight-bold text-success">"${productName}"</p>
                        <small class="text-muted">Sản phẩm sẽ xuất hiện trở lại trong danh sách sản phẩm.</small>
                    </div>
                `,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#28a745',
                cancelButtonColor: '#6c757d',
                confirmButtonText: '<i class="fas fa-check"></i> Kích hoạt',
                cancelButtonText: '<i class="fas fa-times"></i> Hủy',
                reverseButtons: true
            }).then((result) => {
                if (result.isConfirmed) {
                    // Show loading
                    Swal.fire({
                        title: 'Đang kích hoạt...',
                        text: 'Vui lòng chờ',
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        showConfirmButton: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                    
                    // Send AJAX request to reactivate
                    $.ajax({
                        url: 'reactivate_product.php',
                        method: 'POST',
                        data: { product_id: productId },
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Thành công!',
                                    text: 'Sản phẩm đã được kích hoạt lại',
                                    timer: 1500,
                                    showConfirmButton: false
                                });
                                
                                // Update UI without reloading - find all cards with this product ID
                                const cardSelectors = [
                                    `.duplicate-product-card[data-product-id="${productId}"]`,
                                    `.stock-receipt-duplicate-card[data-product-id="${productId}"]`
                                ];
                                
                                cardSelectors.forEach(selector => {
                                    $(selector).each(function() {
                                        const $card = $(this);
                                        
                                        // Remove inactive styling
                                        $card.removeClass('border-warning')
                                             .css({
                                                 'opacity': '1',
                                                 'background': '#fff',
                                                 'cursor': 'pointer'
                                             })
                                             .attr('data-product-status', 'active');
                                        
                                        // Remove inactive badge (⏸ Tạm ngừng)
                                        $card.find('.badge-warning:contains("Tạm ngừng")').remove();
                                        
                                        // Find the button container (parent div of reactivate button)
                                        const $btnReactivate = $card.find('.btn-reactivate-product');
                                        if ($btnReactivate.length > 0) {
                                            // Get product name for the button
                                            const productNameEscaped = productName.replace(/`/g, '\\`').replace(/'/g, "\\'");
                                            
                                            // Replace reactivate button with "Tiếp tục nhập kho" button
                                            $btnReactivate.replaceWith(`
                                                <button class="btn btn-success btn-sm mb-2 px-3 font-weight-bold" 
                                                        onclick="event.stopPropagation(); console.log('🔧 User clicked TIEP TUC NHAP KHO button for product:', ${productId}); selectProductForStockReceipt(${productId}, \`${productNameEscaped}\`)" 
                                                        title="Tiếp tục nhập kho với sản phẩm này" 
                                                        style="min-width: 140px;">
                                                    <i class="fas fa-warehouse mr-1"></i> Tiếp tục nhập kho
                                                </button>
                                            `);
                                        }
                                        
                                        // Remove warning alert
                                        $card.find('.alert-warning').remove();
                                        
                                        // Update card images opacity
                                        $card.find('img').css('opacity', '1');
                                        
                                        // Update onclick attribute if exists
                                        const onclickAttr = $card.attr('onclick');
                                        if (onclickAttr && onclickAttr.includes('showInactiveWarning')) {
                                            $card.removeAttr('onclick');
                                        }
                                    });
                                });
                            } else {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Lỗi!',
                                    text: response.message || 'Không thể kích hoạt sản phẩm'
                                });
                            }
                        },
                        error: function() {
                            Swal.fire({
                                icon: 'error',
                                title: 'Lỗi!',
                                text: 'Không thể kết nối đến máy chủ'
                            });
                        }
                    });
                }
            });
        };
        
        // Event delegation for reactivate buttons
        $(document).on('click', '.btn-reactivate-product', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const productId = $(this).data('product-id');
            const productName = $(this).data('product-name');
            console.log('Reactivate button clicked:', productId, productName);
            window.reactivateProduct(productId, productName);
            return false;
        });
        
        // Function to cancel stock receipt process
        window.cancelStockReceiptProcess = function() {
            if (confirm('Bạn có chắc muốn hủy quá trình nhập kho này?\n\nDữ liệu đã nhập sẽ bị mất.')) {
                $('#stockReceiptDuplicateResults').slideDown();
                moveToStep(3);
                // Clear step 4 content
                $('#step4-content .card-body').empty();
                delete window.selectedProductForReceipt;
                delete window.stockReceiptMode;
            }
        };
        
        // Function to populate form with AI data
        window.populateFormWithAIData = function(aiData) {
            console.log('🤖 POPULATING FORM WITH AI DATA (OVERRIDING DATABASE DATA!):', aiData);
            console.log('⚠️ This function should NOT be called when using existing database data!');
            
            try {
                // Clear any existing content first
                $('#step4-content .card-body').children().not('#aiDataLoadedSuccess').remove();
                
                // Fill basic product information
                if (aiData.product_name) {
                    $('#productName').val(aiData.product_name);
                }
                
                if (aiData.brand) {
                    $('#brandName').val(aiData.brand);
                }
                
                if (aiData.description) {
                    $('#productDescription').val(aiData.description);
                }
                
                if (aiData.type) {
                    $('#productType').val(aiData.type);
                    $('#productTypeText').val(aiData.type);
                }
                
                if (aiData.material) {
                    $('#material').val(aiData.material);
                }
                
                if (aiData.color) {
                    $('#color').val(aiData.color);
                }
                
                if (aiData.features) {
                    $('#features').val(aiData.features);
                }
                
                if (aiData.tags) {
                    $('#tags').val(aiData.tags);
                }
                
                // Generate SKU if not provided
                let baseSku = aiData.sku || generateSkuFromAI(aiData);
                $('#baseSku').val(baseSku);
                
                // Set default values if not provided by AI
                $('#productPrice').val(aiData.price || '');
                $('#minQuantity').val(aiData.min_quantity || 10);
                
                // Show AI-populated form
                showAIPopulatedForm();
                
                console.log('✅ Form populated successfully with AI data');
                
            } catch (error) {
                console.error('❌ Error populating form with AI data:', error);
                alert('Lỗi khi điền dữ liệu AI vào form: ' + error.message);
            }
        };
        
        // Function to generate SKU from AI data
        window.generateSkuFromAI = function(aiData) {
            const brand = aiData.brand ? aiData.brand.substring(0, 4).toUpperCase() : 'UNKN';
            const type = aiData.type ? aiData.type.substring(0, 3).toUpperCase() : 'PRD';
            const randomNum = Math.floor(Math.random() * 1000);
            return `${brand}-${type}-${randomNum}`;
        };
        
        // Function to show AI populated form
        window.showAIPopulatedForm = function() {
            console.log('📋 Showing AI populated form...');
            
            // Make sure step 4 is visible
            $('#step4-content').show();
            
            // Check if form fields exist
            if ($('#productName').length === 0) {
                console.log('📋 Form fields not found, need to generate form first');
                // Form hasn't been generated yet, we need to trigger the normal flow
                // But first, store the AI data so it can be used after form generation
                window.pendingAIData = window.aiData || aiData;
                
                // Trigger the normal proceed to edit which will generate the form
                setTimeout(() => {
                    $('#proceedToEdit').trigger('click');
                }, 100);
            } else {
                console.log('✅ Form fields found, populating directly');
            }
        };
        
        // ===== STEP 4 LOADING HELPERS (from Trung_lap_TC) =====
        
        // Define Step 4 loading helpers EARLY (before $(document).ready)
        window.showStep4Loading = function(message = 'Đang tải dữ liệu...') {
            console.log('⏳ Showing step 4 loading:', message);
            
            // First, remove any existing loading indicator
            $('#step4-loading').remove();
            
            // Find container (prefer card-body, fallback to step4-content)
            const body = $('#step4-content .card-body').first();
            const container = body.length ? body : $('#step4-content');
            
            if (container.length === 0) {
                console.warn('⚠️ No step 4 container found');
                return;
            }
            
            // Create new loading indicator
            const html = `
                <div id="step4-loading" class="alert alert-info d-flex align-items-center" role="alert" style="margin-bottom: 1rem;">
                    <i class="fas fa-spinner fa-spin mr-2"></i>
                    <span>${message}</span>
                </div>
            `;
            
            container.prepend(html);
        };
        
        window.removeStep4Loading = function() {
            const el = $('#step4-loading');
            if (el.length) {
                console.log('🗑️ Removing step 4 loading indicator (found:', el.length, 'elements)');
                el.fadeOut(300, function() {
                    $(this).remove();
                });
            } else {
                console.log('ℹ️ No step 4 loading indicator to remove');
            }
        };
        
        // Function to clean up step 4 content
        window.cleanupStep4Content = function() {
            console.log('🧹 Cleaning up step 4 content...');
            
            // Remove all duplicate sections
            $('#step4-content .existing-sizes-section').remove();
            $('#step4-content .sizes-section').remove();
            $('#step4-loading').remove();
            
            // Reset sizeSelectionArea to original state if needed
            const sizeArea = $('#sizeSelectionArea');
            if (sizeArea.length && sizeArea.find('.existing-sizes-section').length > 0) {
                console.log('🔄 Resetting sizeSelectionArea to placeholder');
                sizeArea.html(`
                    <div class="card border-dashed" style="border-style: dashed !important; border-color: #007bff !important;">
                        <div class="card-body text-center py-4">
                            <i class="fas fa-mouse-pointer fa-3x text-primary mb-3"></i>
                            <h5 class="text-primary mb-2">Chọn Size để nhập kho</h5>
                            <p class="text-muted mb-3">
                                Hệ thống sẽ tải danh sách size có sẵn của sản phẩm này
                            </p>
                            <button type="button" class="btn btn-primary btn-lg" id="selectSizesBtn">
                                <i class="fas fa-list-ul mr-2"></i>
                                Chọn Size từ danh sách
                            </button>
                        </div>
                    </div>
                `);
            }
            
            console.log('✅ Step 4 content cleaned up');
        };

        // ===== DATABASE INTEGRATION FUNCTIONS (from Trung_lap_TC) =====
        
        // Function to load product data from database for update
        window.updateFromExistingData = function() {
            console.log('📋 Updating from existing product data...');
            
            const productInfo = window.selectedProductForReceipt || window.selectedUpdateProduct;
            if (!productInfo || !productInfo.product_id) {
                console.error('❌ No product info available');
                alert('Lỗi: Không tìm thấy thông tin sản phẩm');
                return;
            }
            
            console.log('🔍 Loading product data for ID:', productInfo.product_id);
            
            // Set flag BEFORE moving to step 4 to prevent AI suggestions
            window.useExistingDataOnly = true;
            useExistingDataOnly = true; // Also set local variable
            console.log('🔧 Set useExistingDataOnly =', window.useExistingDataOnly, 'local =', useExistingDataOnly);
            
            // Move to step 4 and show loading
            moveToStep(4);
            showStep4Loading('Đang tải thông tin sản phẩm từ database...');
            
            // Get product data from database
            $.ajax({
                url: 'api_get_product_details.php',
                method: 'POST',
                data: {
                    product_id: productInfo.product_id
                },
                dataType: 'json',
                timeout: 10000, // 10 seconds timeout
                success: function(response) {
                    console.log('✅ Product data received:', response);
                    console.log('🔍 Variants in response:', response.product?.variants);
                    if (response.product?.variants) {
                        console.log('💰 Prices in variants:', response.product.variants.map(v => ({
                            size: v.size,
                            unit_price: v.unit_price,
                            sale_price: v.sale_price
                        })));
                    }
                    
                    // Always remove loading first
                    removeStep4Loading();
                    
                    if (response.success) {
                        // Fill form with existing product data
                        fillFormWithProductData(response.product);
                        
                        // Set update mode
                        window.updateProductId = productInfo.product_id;
                        window.isUpdateMode = true;
                        
                        showToast('✅ Đã tải thông tin sản phẩm từ database', 'success');
                    } else {
                        console.error('❌ Failed to load product:', response.message);
                        showToast('Lỗi: ' + (response.message || 'Không thể tải thông tin sản phẩm'), 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('❌ AJAX error loading product:', {
                        status: status,
                        error: error,
                        xhr: xhr.responseText
                    });
                    
                    // Always remove loading on error
                    removeStep4Loading();
                    
                    if (status === 'timeout') {
                        showToast('Lỗi: Hết thời gian chờ khi tải dữ liệu', 'error');
                    } else {
                        showToast('Lỗi kết nối khi tải thông tin sản phẩm', 'error');
                    }
                }
            });
        };
        
        // Function to fill form with product data from database (FROM Trung_lap_TC)
        window.fillFormWithProductData = function(product) {
            console.log('📝 Filling form with product data:', product);
            
            // Add guard to prevent multiple calls
            if (window._fillingForm) {
                console.warn('⚠️ Already filling form, skipping duplicate call');
                return;
            }
            window._fillingForm = true;
            
            try {
                // Fill basic product info
                $('#productName').val(product.name || '');
                $('#brandName').val(product.brand || '');
                $('#productType').val(product.type || '');
                $('#productTypeText').val(product.type || '');
                $('#productDescription').val(product.description || '');
                $('#material').val(product.material || '');
                $('#features').val(product.features || '');
                $('#tags').val(product.tags || '');
                $('#baseSku').val(product.base_sku || '');
                
                // Set category if available from database
                if (product.category_id) {
                    $('#hiddenCategoryId').val(product.category_id);
                    console.log('📂 Set category from database:', product.category_id);
                } else {
                    console.log('📂 No category in database, leaving empty');
                }
                
                // Set product ID for update mode
                $('#product_id').val(product.product_id || product.id);
                $('#productId').val(product.product_id || product.id); // Also set the hidden field
                
                // Fill color info if available
                if (product.colors && product.colors.length > 0) {
                    $('#color').val(product.colors[0]);
                } else if (product.colors_string) {
                    $('#color').val(product.colors_string.split(',')[0]);
                }
                
                // 🔒 SET READONLY cho các trường thông tin cơ bản từ database
                console.log('🔒 Setting readonly for basic product info from database...');
                $('#productName').prop('readonly', true).css({
                    'background-color': '#e9ecef',
                    'cursor': 'not-allowed'
                });
                $('#brandName').prop('readonly', true).css({
                    'background-color': '#e9ecef',
                    'cursor': 'not-allowed'
                });
                $('#productType').prop('readonly', true).css({
                    'background-color': '#e9ecef',
                    'cursor': 'not-allowed'
                });
                $('#productTypeText').prop('readonly', true).css({
                    'background-color': '#e9ecef',
                    'cursor': 'not-allowed'
                });
                $('#color').prop('readonly', true).css({
                    'background-color': '#e9ecef',
                    'cursor': 'not-allowed'
                });
                $('#baseSku').prop('readonly', true).css({
                    'background-color': '#e9ecef',
                    'cursor': 'not-allowed'
                });
                $('#material').prop('readonly', true).css({
                    'background-color': '#e9ecef',
                    'cursor': 'not-allowed'
                });
                
                // Thêm badge thông báo
                if (!$('#databaseInfoBadge').length) {
                    $('#productName').closest('.form-group').prepend(`
                        <div id="databaseInfoBadge" class="alert alert-info py-2 mb-2">
                            <i class="fas fa-database"></i> <strong>Thông tin từ database</strong> - Các trường cơ bản chỉ hiển thị (readonly)
                        </div>
                    `);
                }
                
                // Disable nút "Tạo SKU tự động" vì SKU từ database không được thay đổi
                $('#generateSkuBtn').prop('disabled', true).attr('title', 'SKU từ database không thể thay đổi');
                console.log('🔒 Disabled SKU generation button for database product');
                
                console.log('✅ All basic fields set to readonly');
                
                // Display uploaded images if any
                if (product.images && product.images.length > 0) {
                    displayExistingImages(product.images);
                }
                
                // If there are variants, show them for selection  
                if (product.variants && product.variants.length > 0) {
                    console.log('📏 Product has variants, preparing variant selection');
                    displayProductSizesForSelection(product.variants);
                }
                
                // Set flags
                window.hasExistingData = true;
                window.productFromDB = true;
                
                console.log('✅ Form filled successfully');
                
            } catch (error) {
                console.error('❌ Error filling form:', error);
                showToast('Lỗi khi điền thông tin vào form', 'error');
            } finally {
                // Reset flag after form filling is complete
                setTimeout(() => {
                    window._fillingForm = false;
                    console.log('✅ Form filling flag reset');
                }, 500);
            }
        };
        
        // Function to display variants for stock receipt (from Trung_lap_TC)
        window.displayVariantsForStockReceipt = function(variants) {
            console.log('👟 Displaying variants for stock receipt:', variants);
            
            const variantContainer = $('#variantContainer');
            if (!variantContainer.length) {
                console.warn('⚠️ Variant container not found');
                return;
            }
            
            // Clear previous variants
            variantContainer.empty();
            
            if (!variants || variants.length === 0) {
                console.log('ℹ️ No variants to display');
                return;
            }
            
            // Group variants by size
            const variantsBySize = {};
            variants.forEach(variant => {
                const size = variant.size || 'N/A';
                if (!variantsBySize[size]) {
                    variantsBySize[size] = [];
                }
                variantsBySize[size].push(variant);
            });
            
            // Create variant selection UI
            let html = '<div class="variant-selection mt-3">';
            html += '<h6>Chọn Size và Variant:</h6>';
            html += '<div class="size-buttons mb-3">';
            
            Object.keys(variantsBySize).forEach(size => {
                html += `<button type="button" class="btn btn-outline-primary size-btn mr-2 mb-2" data-size="${size}">${size}</button>`;
            });
            
            html += '</div>';
            html += '<div class="variant-details" style="display: none;">';
            html += '<div class="form-row">';
            html += '<div class="col-md-6">';
            html += '<label>SKU:</label>';
            html += '<input type="text" class="form-control" id="selectedVariantSku" readonly>';
            html += '</div>';
            html += '<div class="col-md-6">';
            html += '<label>Tồn kho hiện tại:</label>';
            html += '<input type="text" class="form-control" id="selectedVariantStock" readonly>';
            html += '</div>';
            html += '</div>';
            html += '<input type="hidden" id="selectedVariantId" value="">';
            html += '</div>';
            html += '</div>';
            
            variantContainer.html(html);
            
            // Handle size button clicks
            $('.size-btn').on('click', function() {
                const selectedSize = $(this).data('size');
                const sizeVariants = variantsBySize[selectedSize];
                
                // Update button states
                $('.size-btn').removeClass('btn-primary').addClass('btn-outline-primary');
                $(this).removeClass('btn-outline-primary').addClass('btn-primary');
                
                if (sizeVariants.length > 0) {
                    const variant = sizeVariants[0]; // Take first variant for this size
                    
                    // Fill variant details
                    $('#selectedVariantSku').val(variant.sku || '');
                    $('#selectedVariantStock').val(variant.current_stock || '0');
                    $('#selectedVariantId').val(variant.id || '');
                    
                    // Show variant details
                    $('.variant-details').slideDown();
                    
                    console.log('✅ Selected variant:', variant);
                }
            });
            
            console.log('✅ Variants displayed successfully');
        };
        
        // Function to display existing images from database (FROM Trung_lap_TC)
        window.displayExistingImages = function(images) {
            console.log('🖼️ Displaying existing images:', images);
            
            const container = $('#imagePreview');
            container.empty();
            
            if (images && images.length > 0) {
                const imagesHtml = images.map((image, index) => `
                    <div class="col-md-3 mb-3">
                        <div class="card image-card border-success">
                            <img src="${image.file_path}" class="card-img-top" alt="Product Image ${index + 1}" style="height: 200px; object-fit: cover;">
                            <div class="card-body p-2">
                                <small class="text-success">
                                    <i class="fas fa-database mr-1"></i>
                                    Ảnh từ database
                                    ${image.is_primary == 1 ? '<span class="badge badge-primary ml-1">Chính</span>' : ''}
                                </small>
                            </div>
                        </div>
                    </div>
                `).join('');
                
                container.html(`<div class="row">${imagesHtml}</div>`);
                
                // Show container
                container.show();
                $('.images-step').addClass('completed');
                if ($('#imageUploadBtn').length) {
                    $('#imageUploadBtn').text('Chỉnh sửa ảnh');
                }
                
                console.log('✅ Images displayed successfully');
            }
        };
        
        // Function to display product sizes for selection (FROM Trung_lap_TC) 
        window.displayProductSizesForSelection = function(variants) {
            console.log('📏 Displaying product variants for selection:', variants);
            console.log('📊 Checking prices in variants:', variants.map(v => ({
                size: v.size,
                unit_price: v.unit_price,
                sale_price: v.sale_price
            })));
            
            // Add guard to prevent multiple calls
            if (window._displayingSizes) {
                console.warn('⚠️ Already displaying sizes, skipping duplicate call');
                return;
            }
            window._displayingSizes = true;
            
            // Create size selection table from variants
            const sizeRows = variants.map(variant => `
                <tr data-size="${variant.size}" 
                    data-sku="${variant.sku}" 
                    data-color="${variant.color}"
                    data-unit-price="${variant.unit_price || 0}"
                    data-sale-price="${variant.sale_price || 0}"
                    data-variant-id="${variant.variant_id || ''}">
                    <td><strong>${variant.size}</strong></td>
                    <td>${variant.sku}</td>
                    <td>
                        ${variant.unit_price > 0 ? '<strong class="text-primary"><i class="fas fa-shopping-cart"></i> ' + new Intl.NumberFormat('vi-VN').format(variant.unit_price) + ' ₫</strong>' : '<span class="text-muted"><i class="fas fa-minus-circle"></i> Chưa có</span>'}
                    </td>
                    <td>
                        ${variant.sale_price > 0 ? '<strong class="text-success"><i class="fas fa-tag"></i> ' + new Intl.NumberFormat('vi-VN').format(variant.sale_price) + ' ₫</strong>' : '<span class="text-muted"><i class="fas fa-minus-circle"></i> Chưa có</span>'}
                    </td>
                    <td>${variant.color || 'N/A'}</td>
                    <td>
                        <button type="button" class="btn btn-sm btn-primary select-size-btn">
                            <i class="fas fa-plus"></i> Chọn
                        </button>
                    </td>
                </tr>
            `).join('');
            
            const tableHtml = `
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> 
                    Chọn các size bạn muốn nhập hàng từ danh sách có sẵn:
                </div>
                <table class="table table-hover">
                    <thead class="thead-light">
                        <tr>
                            <th>Size</th>
                            <th>SKU</th>
                            <th>Giá nhập cũ</th>
                            <th>Giá bán cũ</th>
                            <th>Màu sắc</th>
                            <th>Hành động</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${sizeRows}
                    </tbody>
                </table>
            `;
            
            // Show in step 4 - Target the correct container
            console.log('🔧 Looking for size selection container...');
            
            // First, try to find sizeSelectionArea
            let targetContainer = $('#sizeSelectionArea');
            
            if (targetContainer.length) {
                console.log('✅ Found #sizeSelectionArea, replacing content');
                
                // Replace the placeholder content with size selection table
                targetContainer.html(`
                    <div class="existing-sizes-section">
                        <h5><i class="fas fa-archive text-primary"></i> Size có sẵn từ database</h5>
                        ${tableHtml}
                    </div>
                `);
            } else {
                // Fallback: prepend to step4 content body (old behavior)
                console.log('⚠️ #sizeSelectionArea not found, using fallback');
                const step4Content = $('#step4-content .card-body');
                
                if (step4Content.length) {
                    console.log('🔧 Removing existing sizes sections...');
                    
                    // Remove ALL existing sizes sections (not just one)
                    step4Content.find('.existing-sizes-section').each(function() {
                        console.log('🗑️ Removing existing-sizes-section');
                        $(this).remove();
                    });
                    
                    console.log('➕ Adding new sizes section');
                    step4Content.prepend(`
                        <div class="existing-sizes-section mb-4">
                            <h5><i class="fas fa-archive text-primary"></i> Size có sẵn từ database</h5>
                            ${tableHtml}
                        </div>
                    `);
                } else {
                    console.error('❌ Step 4 content container not found');
                }
            }
            
            console.log('✅ Size selection table added to step 4');
            
            // Unbind previous handlers to prevent duplicates
            $(document).off('click', '.select-size-btn');
            
            // Add click handlers
            $(document).off('click', '.select-size-btn').on('click', '.select-size-btn', function() {
                const row = $(this).closest('tr');
                const size = row.data('size');
                const sku = row.data('sku');
                const color = row.data('color');
                const unitPrice = parseFloat(row.data('unit-price')) || 0;
                const salePrice = parseFloat(row.data('sale-price')) || 0;
                const variantId = row.data('variant-id') || '';
                
                console.log('👆 Selecting size:', {size, sku, color, unitPrice, salePrice, variantId});
                console.log('🔍 Raw data attributes:', {
                    'data-unit-price': row.attr('data-unit-price'),
                    'data-sale-price': row.attr('data-sale-price')
                });
                
                // Hide the row instead of just disabling the button
                row.fadeOut('fast');
                
                // Add size row to the sizes table with both prices
                addSizeRowToStockReceipt(size, sku, color, unitPrice, salePrice, variantId);
                
                showToast(`Đã thêm size ${size} vào danh sách nhập hàng`, 'success');
            });
            
            // Reset flag after setup is complete
            setTimeout(() => {
                window._displayingSizes = false;
                console.log('✅ Size display flag reset');
            }, 100);
        };
        
        // Function to add size row to stock receipt table
        window.addSizeRowToStockReceipt = function(size, sku, color, unitPrice = 0, salePrice = 0, variantId = '') {
            console.log('➕ Adding size row to stock receipt:', {size, sku, color, unitPrice, salePrice, variantId});
            
            // Find or create sizes table in step 4
            let sizesTable = $('#step4-content .sizes-table tbody');
            
            if (!sizesTable.length) {
                // Create sizes table if it doesn't exist
                const tableHtml = `
                    <div class="sizes-section mt-4 mb-4">
                        <h5><i class="fas fa-ruler text-info"></i> Size đã chọn để nhập kho</h5>
                        <table class="table table-bordered sizes-table">
                            <thead class="thead-dark">
                                <tr>
                                    <th>Size</th>
                                    <th>SKU</th>
                                    <th>Màu sắc</th>
                                    <th>Số lượng nhập</th>
                                    <th>Giá nhập (VNĐ)</th>
                                    <th>Giá bán (VNĐ)</th>
                                    <th>Vị trí lưu</th>
                                    <th>Hành động</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                        <div class="text-right mt-3">
                            <button type="button" class="btn btn-success btn-lg" id="addToReceiptBtn" onclick="addToStockReceipt()">
                                <i class="fas fa-check-circle mr-2"></i>
                                Thêm vào phiếu nhập
                            </button>
                        </div>
                    </div>
                `;
                
                // Find existing-sizes-section and insert after it
                const existingSizesSection = $('#step4-content .existing-sizes-section');
                if (existingSizesSection.length) {
                    existingSizesSection.after(tableHtml);
                } else {
                    // Otherwise append to step4 content
                    $('#step4-content .card-body').append(tableHtml);
                }
                
                sizesTable = $('#step4-content .sizes-table tbody');
            }
            
            // Check if size already exists
            const existingRow = sizesTable.find(`tr[data-size="${size}"]`);
            if (existingRow.length) {
                showToast(`Size ${size} đã có trong danh sách`, 'warning');
                return;
            }
            
            // Add new row with price info from database
            const rowHtml = `
                <tr class="size-row" data-size="${size}" data-sku="${sku}" data-variant-id="${variantId}">
                    <td><strong>${size}</strong></td>
                    <td>${sku}</td>
                    <td>${color || 'N/A'}</td>
                    <td>
                        <input type="number" class="form-control" name="import_quantities[]" min="1" value="1" required style="width: 100px;">
                        <input type="hidden" name="sizes[]" value="${size}">
                        <input type="hidden" name="variant_ids[]" value="${variantId}">
                    </td>
                    <td>
                        <input type="text" class="form-control import-price-input price-format-input" name="original_prices[]" 
                               placeholder="Nhập giá nhập" value="${unitPrice > 0 ? new Intl.NumberFormat('vi-VN').format(unitPrice) : ''}" 
                               data-raw-value="${unitPrice || 0}" required>
                        <input type="hidden" name="skus[]" value="${sku}">
                        <small class="text-muted d-block mt-1">
                            ${unitPrice > 0 ? '<i class="fas fa-database text-primary"></i> Giá cũ: <strong class="text-primary">' + new Intl.NumberFormat('vi-VN').format(unitPrice) + ' ₫</strong>' : '<i class="fas fa-edit"></i> Nhập giá nhập mới'}
                        </small>
                    </td>
                    <td>
                        <input type="text" class="form-control sale-price-input price-format-input" name="sale_prices[]" 
                               placeholder="Giá bán" 
                               value="${salePrice > 0 ? new Intl.NumberFormat('vi-VN').format(salePrice) : ''}" 
                               data-raw-value="${salePrice || 0}" required>
                        <small class="text-muted d-block mt-1">
                            ${salePrice > 0 ? '<i class="fas fa-database text-success"></i> Giá cũ: <strong class="text-success">' + new Intl.NumberFormat('vi-VN').format(salePrice) + ' ₫</strong>' : '<i class="fas fa-edit"></i> Nhập giá bán mới'}
                        </small>
                    </td>
                    <td>
                        <div class="input-group">
                            <input type="text" class="form-control size-location" name="storage_locations[]" 
                                   value="" placeholder="Nhập vị trí...">
                            <div class="input-group-append">
                                <button type="button" class="btn btn-info btn-sm suggestion-btn-for-size" 
                                        data-size="${size}" 
                                        title="Gợi ý vị trí thông minh cho size ${size}">
                                    <i class="fas fa-lightbulb"></i>
                                </button>
                            </div>
                        </div>
                    </td>
                    <td>
                        <button type="button" class="btn btn-danger btn-sm remove-size-btn" onclick="removeSizeRow(this)">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                </tr>
            `;
            
            sizesTable.append(rowHtml);
            console.log('✅ Size row added successfully with price info');
        };
        
        // Function to remove size row
        window.removeSizeRow = function(btn) {
            const row = $(btn).closest('tr');
            const size = row.data('size');
            
            if (confirm(`Bạn có chắc muốn xóa size ${size}?`)) {
                row.remove();
                showToast(`Đã xóa size ${size}`, 'success');
                
                // Show the hidden row in selection table
                $('table tr[data-size="' + size + '"]').fadeIn('fast');
                
                // Also re-enable the select button if it exists
                $(`.select-size-btn`).each(function() {
                    const btnRow = $(this).closest('tr');
                    if (btnRow.data('size') === size) {
                        $(this).removeClass('btn-success').addClass('btn-primary')
                               .html('<i class="fas fa-plus"></i> Chọn')
                               .prop('disabled', false);
                    }
                });
            }
        };
        
        // Function to add selected sizes to stock receipt and move to step 5
        window.addToStockReceipt = function() {
            console.log('📦 Adding selected sizes to stock receipt...');
            
            // ===== THU THẬP THÔNG TIN NHÀ CUNG CẤP =====
            // Kiểm tra và lấy thông tin nhà cung cấp từ bước 1
            if (!selectedSupplier || !selectedSupplier.id) {
                showToast('Vui lòng chọn nhà cung cấp ở bước 1', 'error');
                moveToStep(1);
                return;
            }
            
            console.log('👤 Supplier info:', selectedSupplier);
            
            // ===== THU THẬP THÔNG TIN SẢN PHẨM TỪ FORM =====
            const productInfo = {
                product_id: $('#productId').val() || $('#product_id').val(),
                product_name: $('#productName').val() || '',
                brand: $('#brandName').val() || $('#brand').val() || '',
                product_type: $('#productType').val() || $('#type').val() || '',
                category_id: $('#category').val() || '',
                color: $('#color').val() || '',
                description: $('#description').val() || '',
                material: $('#material').val() || '',
                features: $('#features').val() || '',
                tags: $('#tags').val() || ''
            };
            
            console.log('📦 Product info from form:', productInfo);
            
            // Validate thông tin sản phẩm cơ bản
            if (!productInfo.product_name || productInfo.product_name.trim() === '') {
                showToast('Vui lòng nhập tên sản phẩm', 'error');
                return;
            }
            
            // ===== THU THẬP THÔNG TIN SIZES ĐÃ CHỌN =====
            const sizeRows = $('#step4-content .sizes-table tbody .size-row');
            if (sizeRows.length === 0) {
                showToast('Vui lòng chọn ít nhất một size để nhập kho', 'warning');
                return;
            }
            
            // Validate all required fields
            let isValid = true;
            const items = [];
            
            sizeRows.each(function() {
                const row = $(this);
                const size = row.data('size');
                const sku = row.data('sku');
                const variantId = row.data('variant-id');
                
                const quantity = row.find('input[name="import_quantities[]"]').val();
                // Get raw values from data attributes (unformatted numbers)
                const importPriceInput = row.find('input[name="original_prices[]"]');
                const salePriceInput = row.find('input[name="sale_prices[]"]');
                const importPrice = importPriceInput.data('raw-value') || importPriceInput.val().replace(/[^\d]/g, '');
                const salePrice = salePriceInput.data('raw-value') || salePriceInput.val().replace(/[^\d]/g, '');
                const location = row.find('input[name="storage_locations[]"]').val();
                
                // Validate
                if (!quantity || parseFloat(quantity) <= 0) {
                    showToast(`Size ${size}: Số lượng nhập phải lớn hơn 0`, 'error');
                    isValid = false;
                    return false;
                }
                
                if (!importPrice || parseFloat(importPrice) < 0) {
                    showToast(`Size ${size}: Vui lòng nhập giá nhập`, 'error');
                    isValid = false;
                    return false;
                }
                
                if (!salePrice || parseFloat(salePrice) < 0) {
                    showToast(`Size ${size}: Vui lòng nhập giá bán`, 'error');
                    isValid = false;
                    return false;
                }
                
                if (!location || location.trim() === '') {
                    showToast(`Size ${size}: Vui lòng nhập vị trí lưu trữ`, 'error');
                    isValid = false;
                    return false;
                }
                
                // ===== KẾT HỢP ĐẦY ĐỦ THÔNG TIN =====
                items.push({
                    // Thông tin sản phẩm
                    product_id: productInfo.product_id,
                    product_name: productInfo.product_name,
                    brand: productInfo.brand,
                    product_type: productInfo.product_type,
                    category_id: productInfo.category_id,
                    color: productInfo.color,
                    description: productInfo.description,
                    material: productInfo.material,
                    features: productInfo.features,
                    tags: productInfo.tags,
                    
                    // Thông tin variant
                    variant_id: variantId,
                    size: size,
                    sku: sku,
                    
                    // Thông tin nhập kho
                    quantity: parseFloat(quantity),
                    price: parseFloat(importPrice),  // ⭐ ĐỔI TÊN: import_price → price để match với #updateReceiptBtn
                    sale_price: parseFloat(salePrice),
                    storage_location: location || 'KHO-CHINH',
                    
                    // Thông tin nhà cung cấp
                    supplier_id: selectedSupplier.id,
                    supplier_name: selectedSupplier.name,
                    supplier_phone: selectedSupplier.phone
                });
            });
            
            if (!isValid) {
                return;
            }
            
            console.log('✅ Collected items with full info:', items);
            console.log('📊 Total items:', items.length);
            
            // Debug: Log prices
            items.forEach((item, idx) => {
                console.log(`  Item #${idx}: ${item.product_name} - Size ${item.size}`);
                console.log(`    → price: ${item.price}, sale_price: ${item.sale_price}`);
            });
            
            // Add items to receiptItems array
            if (typeof receiptItems === 'undefined') {
                window.receiptItems = [];
            }
            
            items.forEach(item => {
                receiptItems.push(item);
            });
            
            console.log('📋 Updated receiptItems:', receiptItems);
            console.log('👤 Selected supplier:', selectedSupplier);
            
            // Save to localStorage
            saveDataToLocalStorage();
            
            // Update continue button visibility
            updateContinueToStep5Button();
            
            // Show success message
            showToast(`Đã thêm ${items.length} size vào phiếu nhập kho`, 'success');
            
            // Move to step 5
            setTimeout(() => {
                moveToStep(5);
            }, 500);
        };
        
        // ===== LOCATION SUGGESTION TRACKING =====
        
        // Track các vị trí đã được gợi ý để tránh trùng lặp
        window.suggestedLocations = window.suggestedLocations || [];
        
        // Helper: Reset tracking khi cần (ví dụ: khi bắt đầu nhập sản phẩm mới)
        window.resetLocationTracking = function() {
            window.suggestedLocations = [];
            console.log('🔄 Location tracking reset');
        };
        
        // Helper: Log current tracking status
        window.logLocationTracking = function() {
            console.log('📍 Currently tracked locations:', window.suggestedLocations);
        };
        
        // ===== CONTINUE BUTTON MANAGEMENT =====
        
        /**
         * Update visibility of Continue to Step 5 button based on receiptItems
         */
        window.updateContinueToStep5Button = function() {
            const continueBtn = $('#continueToStep5');
            const badge = $('#receiptItemsCountBadge');
            
            if (receiptItems && receiptItems.length > 0) {
                continueBtn.show();
                badge.text(receiptItems.length);
                console.log('✅ Show continue button with', receiptItems.length, 'items');
            } else {
                continueBtn.hide();
                console.log('❌ Hide continue button - no items');
            }
        };
        
        // ===== TEST FUNCTIONS =====
        
        // 🆕 NEW TEST: Complete database workflow from Trung_lap_TC
        window.testCompleteDatabaseWorkflow = function() {
            console.log('🔧 Testing complete database workflow (FROM TRUNG_LAP_TC)...');
            $('#debugInfo').html('Testing complete Trung_lap_TC workflow...');
            
            // Set up product selection
            window.selectedProductForReceipt = {
                product_id: 24,
                name: 'Giày cao gót Charles & Keith'  
            };
            
            console.log('1️⃣ Set selectedProductForReceipt:', window.selectedProductForReceipt);
            $('#debugInfo').append('<br>1️⃣ Product selected: ID 24');
            
            // Call updateFromExistingData (this should load from database)
            updateFromExistingData();
            $('#debugInfo').append('<br>2️⃣ Called updateFromExistingData()');
        };
        
        // Test function for API Product Details
        window.testProductDetailsAPI = function() {
            console.log('🔧 Testing API Product Details for ID 24...');
            $('#debugInfo').html('Testing API...');
            
            $.ajax({
                url: 'api_get_product_details.php',
                method: 'POST',
                data: { product_id: 24 },
                dataType: 'json',
                success: function(response) {
                    console.log('✅ API Response:', response);
                    $('#debugInfo').html(`<strong>API Success:</strong><br><pre>${JSON.stringify(response, null, 2)}</pre>`);
                    
                    // Test fillFormWithProductData if API succeeds
                    if (response.success && response.product) {
                        console.log('🔧 Testing fillFormWithProductData...');
                        $('#debugInfo').append(`<br><strong>Testing form fill...</strong>`);
                        fillFormWithProductData(response.product);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('❌ API Error:', {xhr, status, error});
                    $('#debugInfo').html(`<strong>API Error:</strong><br>Status: ${status}<br>Error: ${error}<br>Response: ${xhr.responseText}`);
                }
            });
        };
        
        // Test function to simulate selecting a product
        window.testSelectProduct = function() {
            console.log('🔧 Testing Select Product workflow...');
            $('#debugInfo').html('Simulating product selection...');
            
            // Close any open modals
            $('.modal').modal('hide');
            
            // Move to step 4 first for testing
            moveToStep(4);
            
            // Simulate selecting Charles & Keith product
            const testProduct = {
                product_id: 24,
                name: 'Giày cao gót Charles & Keith'
            };
            
            console.log('📋 Simulating product selection:', testProduct);
            $('#debugInfo').html(`<strong>Simulating selection:</strong><br>${JSON.stringify(testProduct, null, 2)}`);
            
            // Call the function directly
            selectProductForStockReceipt(testProduct.product_id, testProduct.name);
        };
        
        // Test function for updateFromExistingData directly
        window.testUpdateFromExistingData = function() {
            console.log('🔧 Testing updateFromExistingData directly...');
            $('#debugInfo').html('Testing updateFromExistingData...');
            
            // Set up test data
            window.selectedProductForReceipt = {
                product_id: 24,
                name: 'Giày cao gót Charles & Keith'
            };
            
            // Move to step 4
            moveToStep(4);
            
            // Call the function
            updateFromExistingData();
        };
        
        // Test function for database fill only (without step movement)
        window.testDatabaseOnlyFill = function() {
            console.log('🔧 Testing database fill only...');
            $('#debugInfo').html('Testing database fill only...');
            
            // Move to step 4 first
            moveToStep(4);
            
            // Set flags to prevent AI interference
            window.useExistingDataOnly = true;
            useExistingDataOnly = true;
            
            // Make direct API call
            $.ajax({
                url: 'api_get_product_details.php',
                method: 'POST',
                data: { product_id: 24 },
                dataType: 'json',
                success: function(response) {
                    console.log('✅ API Response:', response);
                    $('#debugInfo').append(`<br><strong>API Success</strong><br>`);
                    
                    if (response.success && response.product) {
                        console.log('🔧 Calling fillFormWithProductData...');
                        $('#debugInfo').append(`<strong>Filling form...</strong><br>`);
                        fillFormWithProductData(response.product);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('❌ API Error:', {xhr, status, error});
                    $('#debugInfo').append(`<br><strong>API Error:</strong> ${status} - ${error}`);
                }
            });
        };
        
        // ===== END OF DATABASE INTEGRATION =====
        
        // Function to update receipt summary in step 5 - Defined globally
        function updateReceiptSummary() {
            try {
                console.log('🔄 updateReceiptSummary called');
                console.log('👤 selectedSupplier:', selectedSupplier);
                console.log('📦 receiptItems length:', receiptItems ? receiptItems.length : 'undefined');
                console.log('📦 receiptItems:', receiptItems);
                
                // Kiểm tra xem element có tồn tại không
                const supplierInfoElement = $('#supplierInfo');
                const itemsListElement = $('#receiptItemsList');
                const totalAmountElement = $('#totalAmount');
            
            console.log('🔍 Elements check:', {
                supplierInfo: supplierInfoElement.length,
                itemsList: itemsListElement.length,
                totalAmount: totalAmountElement.length
            });
            
            // If elements are not found, it means step 5 is not visible yet
            if (supplierInfoElement.length === 0 || itemsListElement.length === 0 || totalAmountElement.length === 0) {
                console.warn('⚠️ Step 5 elements not ready yet, skipping update');
                return;
            }
            
            // Update supplier info
            if (supplierInfoElement.length > 0) {
                let supplierHtml = '';
                if (selectedSupplier && selectedSupplier.name) {
                    supplierHtml = `
                        <p class="mb-1"><strong><i class="fas fa-building"></i> Tên:</strong> ${selectedSupplier.name}</p>
                        <p class="mb-1"><strong><i class="fas fa-phone"></i> SĐT:</strong> ${selectedSupplier.phone || 'N/A'}</p>
                        ${selectedSupplier.email ? '<p class="mb-0"><strong><i class="fas fa-envelope"></i> Email:</strong> ' + selectedSupplier.email + '</p>' : ''}
                    `;
                } else {
                    supplierHtml = '<p class="text-danger mb-0"><i class="fas fa-exclamation-triangle"></i> Chưa chọn nhà cung cấp</p>';
                }
                supplierInfoElement.html(supplierHtml);
                console.log('✅ Updated supplier info');
            } else {
                console.error('❌ #supplierInfo element not found');
            }
            
            // Update current date
            const currentDate = new Date().toLocaleDateString('vi-VN');
            $('#currentDate').text(currentDate);
            
            // Update receipt notes
            const receiptNotes = $('#receiptNotes').val() || 'Không có ghi chú';
            $('#receiptNotesDisplay').text(receiptNotes);
            
            // Initialize total amount
            let totalAmount = 0;
            
            // Update items list
            if (itemsListElement.length > 0) {
                itemsListElement.empty();
                
                let itemsHtml = '';
                
                console.log('📊 Processing receiptItems for display...');
                
                if (receiptItems && receiptItems.length > 0) {
                    console.log('📝 Found', receiptItems.length, 'items to display');
                    
                    itemsHtml = `
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover">
                                <thead class="thead-light">
                                    <tr>
                                        <th style="min-width: 200px;">Sản phẩm</th>
                                        <th>Thương hiệu</th>
                                        <th>Loại</th>
                                        <th>Màu</th>
                                        <th>Size</th>
                                        <th>SKU</th>
                                        <th class="text-center">SL</th>
                                        <th class="text-right">Giá nhập</th>
                                        <th class="text-right">Giá bán</th>
                                        <th class="text-right">Thành tiền</th>
                                        <th>Vị trí</th>
                                    </tr>
                                </thead>
                                <tbody>
                    `;
                    
                    receiptItems.forEach((item, index) => {
                        // Sử dụng import_price để tính thành tiền (tổng tiền nhập)
                        const importPrice = item.import_price || item.price || 0;
                        const salePrice = item.sale_price || 0;
                        const quantity = item.quantity || 0;
                        const subtotal = quantity * importPrice;
                        totalAmount += subtotal;
                        
                        console.log(`📋 Item ${index + 1}:`, {
                            name: item.product_name,
                            brand: item.brand,
                            type: item.product_type,
                            color: item.color,
                            size: item.size,
                            sku: item.sku,
                            quantity: quantity,
                            import_price: importPrice,
                            sale_price: salePrice,
                            subtotal: subtotal,
                            storage_location: item.storage_location
                        });
                        
                        itemsHtml += `
                            <tr>
                                <td>
                                    <strong>${item.product_name || 'N/A'}</strong>
                                    ${item.description ? '<br><small class="text-muted">' + item.description.substring(0, 50) + '...</small>' : ''}
                                </td>
                                <td><span class="badge badge-info">${item.brand || 'N/A'}</span></td>
                                <td>${item.product_type || 'N/A'}</td>
                                <td>${item.color || 'N/A'}</td>
                                <td class="text-center"><strong>${item.size || 'N/A'}</strong></td>
                                <td><code>${item.sku || 'N/A'}</code></td>
                                <td class="text-center"><strong>${quantity}</strong></td>
                                <td class="text-right">${formatCurrency(importPrice)}</td>
                                <td class="text-right text-success"><strong>${formatCurrency(salePrice)}</strong></td>
                                <td class="text-right text-primary"><strong>${formatCurrency(subtotal)}</strong></td>
                                <td><small>${item.storage_location || 'KHO-CHINH'}</small></td>
                            </tr>
                        `;
                    });
                    
                    itemsHtml += `
                                </tbody>
                            </table>
                        </div>
                    `;
                    
                    console.log('💰 Calculated total amount:', totalAmount);
                } else {
                    console.log('📭 No items found, showing empty message');
                    itemsHtml = '<p class="text-muted">Chưa có sản phẩm nào được thêm vào phiếu nhập.</p>';
                }
                
                itemsListElement.html(itemsHtml);
                console.log('✅ Updated items list HTML');
            } else {
                console.error('❌ #receiptItemsList element not found');
            }
            
            // Update total amount
            if (totalAmountElement.length > 0) {
                const formattedTotal = formatCurrency(totalAmount || 0);
                totalAmountElement.text(formattedTotal);
                console.log('✅ Updated total amount:', formattedTotal);
            } else {
                console.error('❌ #totalAmount element not found');
            }
            
            // Update debug info
            updateDebugInfo();
            
            console.log('🎯 updateReceiptSummary completed');
            
            } catch (error) {
                console.error('❌ Error in updateReceiptSummary:', error);
                console.error('Error stack:', error.stack);
            }
        }

        // Helper function to format currency - Defined globally
        function formatCurrency(amount) {
            // Fix NaN issue - ensure amount is a valid number
            const numAmount = parseFloat(amount) || 0;
            return new Intl.NumberFormat('vi-VN', {
                style: 'currency',
                currency: 'VND'
            }).format(numAmount);
        }
        
        // Debug function để kiểm tra trạng thái - Defined globally
        window.debugReceiptState = function() {
            console.log('=== DEBUG RECEIPT STATE ===');
            console.log('currentStep:', currentStep);
            console.log('selectedSupplier:', selectedSupplier);
            console.log('receiptItems:', receiptItems);
            console.log('receiptDate:', $('#receiptDate').val());
            console.log('receiptNotes:', $('#receiptNotes').val());
            console.log('===========================');
        };
        
        // Test function để thêm dữ liệu mẫu
        window.addTestData = function() {
            console.log('🧪 Adding test data...');
            
            // Test supplier
            selectedSupplier = {
                id: 1,
                name: 'Công ty test',
                phone: '0912345656'
            };
            
            // Test receipt items - với cấu trúc đầy đủ import_price và sale_price
            receiptItems = [
                {
                    product_id: 1,
                    variant_id: 10,
                    product_name: 'Giày Thể Thao Nike Air Max',
                    size: '38',
                    sku: 'NIKE-AIR-001-38',
                    quantity: 10,
                    import_price: 800000,
                    sale_price: 1200000,
                    storage_location: 'KHO-A-01'
                },
                {
                    product_id: 1,
                    variant_id: 11,
                    product_name: 'Giày Thể Thao Nike Air Max',
                    size: '39',
                    sku: 'NIKE-AIR-001-39',
                    quantity: 15,
                    import_price: 800000,
                    sale_price: 1200000,
                    storage_location: 'KHO-A-02'
                },
                {
                    product_id: 1,
                    variant_id: 12,
                    product_name: 'Giày Thể Thao Nike Air Max',
                    size: '40',
                    sku: 'NIKE-AIR-001-40',
                    quantity: 8,
                    import_price: 800000,
                    sale_price: 1200000,
                    storage_location: 'KHO-A-03'
                }
            ];
            
            // Save to localStorage as backup
            saveDataToLocalStorage();
            
            console.log('✅ Test data added');
            console.log('👤 selectedSupplier:', selectedSupplier);
            console.log('📦 receiptItems:', receiptItems);
            
            // Update summary
            updateReceiptSummary();
        };
        
        // Function to save data to localStorage
        window.saveDataToLocalStorage = function() {
            try {
                localStorage.setItem('receiptItems', JSON.stringify(receiptItems || []));
                localStorage.setItem('selectedSupplier', JSON.stringify(selectedSupplier || null));
                localStorage.setItem('currentStep', currentStep || 1);
                
                // Save form data if exists
                const receiptDate = $('#receiptDate').val();
                const receiptNotes = $('#receiptNotes').val();
                if (receiptDate) {
                    localStorage.setItem('receiptDate', receiptDate);
                }
                if (receiptNotes) {
                    localStorage.setItem('receiptNotes', receiptNotes);
                }
                
                console.log('💾 Data saved to localStorage:', {
                    receiptItems: receiptItems?.length || 0,
                    selectedSupplier: selectedSupplier?.name || 'None',
                    currentStep: currentStep,
                    receiptDate: receiptDate,
                    receiptNotes: receiptNotes
                });
            } catch (e) {
                console.error('❌ Failed to save to localStorage:', e);
            }
        };
        
        // Function to load data from localStorage
        window.loadDataFromLocalStorage = function() {
            try {
                const savedItems = localStorage.getItem('receiptItems');
                const savedSupplier = localStorage.getItem('selectedSupplier');
                const savedStep = localStorage.getItem('currentStep');
                const savedDate = localStorage.getItem('receiptDate');
                const savedNotes = localStorage.getItem('receiptNotes');
                
                if (savedItems) {
                    const items = JSON.parse(savedItems) || [];
                    if (items.length > 0) {
                        receiptItems = items;
                        console.log('💾 Loaded receiptItems from localStorage:', receiptItems.length, 'items');
                        // Update continue button after loading items
                        if (typeof updateContinueToStep5Button === 'function') {
                            updateContinueToStep5Button();
                        }
                    }
                }
                
                if (savedSupplier && savedSupplier !== 'null') {
                    const supplier = JSON.parse(savedSupplier);
                    if (supplier && supplier.id) {
                        selectedSupplier = supplier;
                        console.log('💾 Loaded selectedSupplier from localStorage:', selectedSupplier.name);
                        
                        // Restore supplier selection in form
                        if ($('#supplierSelect').length > 0) {
                            $('#supplierSelect').val(selectedSupplier.id);
                        }
                    }
                }
                
                if (savedDate) {
                    $('#receiptDate').val(savedDate);
                    console.log('💾 Loaded receiptDate from localStorage:', savedDate);
                }
                
                if (savedNotes) {
                    $('#receiptNotes').val(savedNotes);
                    console.log('💾 Loaded receiptNotes from localStorage:', savedNotes);
                }
                
                // Restore step if we have items or supplier
                if (savedStep && (receiptItems.length > 0 || selectedSupplier)) {
                    const stepToRestore = parseInt(savedStep) || 1;
                    if (stepToRestore > 1) {
                        console.log('💾 Restoring to step:', stepToRestore);
                        setTimeout(() => {
                            moveToStep(stepToRestore);
                            // moveToStep will handle updateReceiptSummary() for step 5
                        }, 500); // Small delay to ensure DOM is ready
                    }
                } else if (receiptItems.length > 0 || selectedSupplier) {
                    // If we have data but no saved step, stay at step 1 but prepare the button
                    console.log('💾 Data loaded but staying at step 1');
                    if ($('#nextToUpload').length > 0) {
                        $('#nextToUpload').prop('disabled', false).removeClass('btn-secondary').addClass('btn-primary');
                    }
                }
                
                console.log('💾 localStorage restoration complete');
            } catch (e) {
                console.error('❌ Failed to load from localStorage:', e);
            }
        };
        
        // Function to clear localStorage
        window.clearLocalStorage = function() {
            try {
                localStorage.removeItem('receiptItems');
                localStorage.removeItem('selectedSupplier');
                localStorage.removeItem('currentStep');
                localStorage.removeItem('receiptDate');
                localStorage.removeItem('receiptNotes');
                console.log('🗑️ Cleared all localStorage data');
            } catch (e) {
                console.error('❌ Failed to clear localStorage:', e);
            }
        };
        
        // Function to check for saved data and show notification
        window.checkForSavedData = function() {
            try {
                const savedItems = localStorage.getItem('receiptItems');
                const savedSupplier = localStorage.getItem('selectedSupplier');
                
                let hasData = false;
                let dataInfo = '';
                
                if (savedItems) {
                    const items = JSON.parse(savedItems);
                    if (items && items.length > 0) {
                        hasData = true;
                        dataInfo += `${items.length} sản phẩm`;
                    }
                }
                
                if (savedSupplier && savedSupplier !== 'null') {
                    const supplier = JSON.parse(savedSupplier);
                    if (supplier && supplier.name) {
                        hasData = true;
                        if (dataInfo) dataInfo += ', ';
                        dataInfo += `NCC: ${supplier.name}`;
                    }
                }
                
                if (hasData) {
                    console.log('📋 Found saved data:', dataInfo);
                    
                    // Show notification with option to restore
                    const notification = `
                        <div class="alert alert-info alert-dismissible fade show saved-data-notification" 
                             style="position: fixed; top: 80px; right: 20px; z-index: 9999; max-width: 400px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                            <h6><i class="fas fa-info-circle"></i> Phiếu nhập đang làm dở</h6>
                            <p class="mb-2">Bạn có phiếu nhập chưa hoàn thành: <strong>${dataInfo}</strong></p>
                            <div class="mt-2">
                                <button class="btn btn-sm btn-primary mr-2" id="btnRestoreSavedData">
                                    <i class="fas fa-redo"></i> Tiếp tục
                                </button>
                                <button class="btn btn-sm btn-outline-danger" id="btnDiscardSavedData">
                                    <i class="fas fa-trash"></i> Xóa và bắt đầu mới
                                </button>
                            </div>
                        </div>
                    `;
                    $('body').append(notification);
                    
                    // Attach event handlers AFTER notification is added to DOM
                    $('#btnRestoreSavedData').on('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        console.log('✅ User clicked Restore button');
                        restoreSavedData();
                    });
                    
                    $('#btnDiscardSavedData').on('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        console.log('🗑️ User clicked Discard button');
                        if (confirm('Bạn có chắc muốn xóa dữ liệu phiếu nhập đang làm dở?')) {
                            clearLocalStorage();
                            $('.saved-data-notification').remove();
                            showToast('Đã xóa dữ liệu cũ', 'success');
                        }
                    });
                } else {
                    console.log('📭 No saved data found');
                }
            } catch (e) {
                console.error('❌ Failed to check saved data:', e);
            }
        };
        
        // Function to restore saved data (called when user clicks "Continue")
        window.restoreSavedData = function() {
            console.log('🔄 Restoring saved data...');
            loadDataFromLocalStorage();
            $('.alert').remove(); // Remove notification
            showToast('Đã khôi phục dữ liệu phiếu nhập', 'success');
        };
        
        // Function để clear dữ liệu
        window.clearReceiptData = function() {
            receiptItems = [];
            selectedSupplier = null;
            clearLocalStorage();
            console.log('🗑️ Receipt data cleared');
            
            // Reset form về step 1
            currentStep = 1;
            $('.step').removeClass('active completed');
            $('#step1').addClass('active');
            $('[id^="step"][id$="-content"]').hide();
            $('#step1-content').show();
            
            // Clear form fields
            $('#receiptDate').val('');
            $('#supplierSelect').val('');
            $('#receiptNotes').val('');
            
            updateReceiptSummary();
            updateDebugInfo();
            showToast('Đã xóa tất cả dữ liệu và bắt đầu mới', 'success');
        };
        
        // Function để toggle debug panel
        window.toggleDebugPanel = function() {
            const panel = $('#debugPanel');
            if (panel.is(':visible')) {
                panel.hide();
            } else {
                panel.show();
                updateDebugInfo();
            }
        };
        
        // Function để update debug info
        window.updateDebugInfo = function() {
            $('#debugItemCount').text(receiptItems ? receiptItems.length : 0);
            $('#debugSupplier').text(selectedSupplier ? selectedSupplier.name : 'None');
            
            // Update quick debug info too
            $('#quickDebugItems').text(receiptItems ? receiptItems.length : 0);
            $('#quickDebugSupplier').text(selectedSupplier ? selectedSupplier.name : 'None');
        };
        
        // Quick debug function
        window.quickDebug = function() {
            console.log('🚀 QUICK DEBUG STARTED');
            console.log('==========================================');
            console.log('📊 receiptItems:', receiptItems);
            console.log('📊 receiptItems length:', receiptItems ? receiptItems.length : 'undefined');
            console.log('👤 selectedSupplier:', selectedSupplier);
            console.log('🔗 window.receiptItems:', window.receiptItems);
            console.log('🔗 window.selectedSupplier:', window.selectedSupplier);
            console.log('💾 localStorage receiptItems:', localStorage.getItem('receiptItems'));
            console.log('💾 localStorage selectedSupplier:', localStorage.getItem('selectedSupplier'));
            
            // Check DOM elements
            console.log('🏗️ DOM Elements:');
            console.log('- #supplierInfo:', $('#supplierInfo').length);
            console.log('- #receiptItemsList:', $('#receiptItemsList').length);
            console.log('- #totalAmount:', $('#totalAmount').length);
            
            // Check current step
            console.log('📍 Current Step:', currentStep);
            console.log('📍 Active step element:', $('.step.active').attr('id'));
            
            // Check form data for debug
            console.log('📝 Current Form Data:');
            console.log('- Product Name:', $('#productName').val());
            console.log('- Price:', $('#price').val());
            console.log('- Size inputs count:', $('select[name="sizes[]"]').length);
            console.log('- Quantity inputs count:', $('input[name="min_quantities[]"]').length);
            
            console.log('==========================================');
            
            // Update debug info
            updateDebugInfo();
            
            // Try force update
            console.log('🔄 Forcing updateReceiptSummary...');
            updateReceiptSummary();
        };
        
        // Enhanced test data with current form values
        window.addTestDataFromForm = function() {
            console.log('🧪 Adding test data from current form...');
            
            // Get current form values
            const productName = $('#productName').val() || 'Test Product';
            const price = parseFloat($('#price').val()) || 500000;
            
            // Get sizes from form
            const formSizes = [];
            $('select[name="sizes[]"]').each(function(index) {
                const size = $(this).val();
                const quantity = parseInt($($('input[name="min_quantities[]"]')[index]).val()) || 0;
                if (size) {
                    formSizes.push({
                        size: size,
                        quantity: quantity,
                        sku: `TEST-${productName.replace(/\s+/g, '').toUpperCase()}-${size}`
                    });
                }
            });
            
            // If no sizes from form, use default
            if (formSizes.length === 0) {
                formSizes.push({
                    size: '40',
                    quantity: 10,
                    sku: 'TEST-PRODUCT-40'
                });
            }
            
            // Set supplier if not already set
            if (!selectedSupplier) {
                selectedSupplier = {
                    id: 1,
                    name: 'Công ty 123',
                    phone: '0912345656'
                };
            }
            
            // Create receipt items from form data
            receiptItems = [];
            formSizes.forEach(sizeInfo => {
                receiptItems.push({
                    product_name: productName,
                    size: sizeInfo.size,
                    sku: sizeInfo.sku,
                    quantity: sizeInfo.quantity,
                    price: price
                });
            });
            
            // Save to localStorage
            saveDataToLocalStorage();
            
            console.log('✅ Test data added from form');
            console.log('👤 selectedSupplier:', selectedSupplier);
            console.log('📦 receiptItems:', receiptItems);
            
            // Update summary
            updateReceiptSummary();
        };
        
        // Function để verify variables và fix scope issues
        window.verifyAndFixVariables = function() {
            console.log('🔍 Verifying variables...');
            
            // Check if variables exist and are accessible
            if (typeof receiptItems === 'undefined') {
                console.error('❌ receiptItems is undefined! Creating new array...');
                window.receiptItems = [];
                receiptItems = [];
            }
            
            if (typeof selectedSupplier === 'undefined') {
                console.error('❌ selectedSupplier is undefined! Creating null...');
                window.selectedSupplier = null;
                selectedSupplier = null;
            }
            
            console.log('✅ Variables verified:');
            console.log('- receiptItems:', receiptItems);
            console.log('- selectedSupplier:', selectedSupplier);
            console.log('- window.receiptItems:', window.receiptItems);
            console.log('- window.selectedSupplier:', window.selectedSupplier);
        };
        
        // Define Step 4 loading helpers EARLY (before $(document).ready)
        window.showStep4Loading = function(message = 'Đang tải dữ liệu...') {
            console.log('⏳ Showing step 4 loading:', message);
            
            // First, remove any existing loading indicator
            $('#step4-loading').remove();
            
            // Find container (prefer card-body, fallback to step4-content)
            const body = $('#step4-content .card-body').first();
            const container = body.length ? body : $('#step4-content');
            
            if (container.length === 0) {
                console.warn('⚠️ Step 4 container not found, cannot show loading');
                return;
            }
            
            // Create new loading indicator
            const html = `
                <div id="step4-loading" class="alert alert-info d-flex align-items-center" role="alert" style="margin-bottom: 1rem;">
                    <i class="fas fa-spinner fa-spin mr-2"></i>
                    <span>${message}</span>
                </div>
            `;
            
            container.prepend(html);
            console.log('✅ Step 4 loading indicator shown');
        };

        $(document).ready(function() {
            console.log('🚀 Page loaded - Starting fresh at Step 1');
            console.log('📋 Checking for previously saved data...');
            
            // Initialize price formatting
            setupPriceFormatting();
            
            // Function để hiển thị thông báo toast (define FIRST before using)
            function showToast(message, type = 'info') {
                const iconClass = type === 'success' ? 'fas fa-check' : 
                                 type === 'error' ? 'fas fa-exclamation-triangle' : 
                                 'fas fa-info-circle';
                                 
                const bgClass = type === 'success' ? 'alert-success' : 
                               type === 'error' ? 'alert-danger' : 
                               'alert-info';
                               
                const toast = `
                    <div class="alert ${bgClass} alert-dismissible fade show toast-notification" 
                         style="position: fixed; top: 150px; right: 20px; z-index: 9998; max-width: 350px;">
                        <i class="${iconClass}"></i> ${message}
                        <button type="button" class="close" data-dismiss="alert">
                            <span>&times;</span>
                        </button>
                    </div>
                `;
                $('body').append(toast);
                
                // Auto remove sau 3 giây
                setTimeout(() => {
                    $('.toast-notification').fadeOut();
                }, 3000);
            }
            // Expose globally so other scopes can call it
            window.showToast = showToast;
            
            // NOW we can verify variables and check for saved data (after showToast is defined)
            verifyAndFixVariables();
            
            // KHÔNG tự động load dữ liệu cũ - để trang luôn bắt đầu sạch
            // Người dùng phải chủ động chọn "Tiếp tục" nếu muốn restore dữ liệu
            // loadDataFromLocalStorage();
            
            // Kiểm tra xem có dữ liệu cũ trong localStorage không và hiển thị thông báo
            // checkForSavedData(); // Tắt thông báo phiếu nhập đang làm dở
            
            // Clean up any stray loading indicators from previous sessions
            $('#step4-loading').remove();
            console.log('🧹 Cleaned up any existing loading indicators');
            
            // Test if saveDraftBtn exists
            setTimeout(() => {
                const btnExists = $('#saveDraftBtn').length > 0;
                console.log('🔍 Save Draft button exists in DOM:', btnExists);
                if (btnExists) {
                    console.log('✅ Button found:', $('#saveDraftBtn'));
                } else {
                    console.warn('⚠️ Save Draft button NOT found in DOM yet');
                }
            }, 1000);
            
            // Initialize size management
            updateRemoveButtons();

            // Step 1 validation and navigation
            function validateStep1() {
                const receiptDate = $('#receiptDate').val();
                const supplierSelect = $('#supplierSelect').val();
                
                const isValid = receiptDate && supplierSelect;
                $('#nextToUpload').prop('disabled', !isValid);
                
                if (isValid) {
                    $('#nextToUpload').removeClass('btn-secondary').addClass('btn-primary');
                } else {
                    $('#nextToUpload').removeClass('btn-primary').addClass('btn-secondary');
                }
                
                return isValid;
            }

            // Handle step 1 field changes
            $('#receiptDate, #supplierSelect').on('change', function() {
                // Kiểm tra nếu chọn supplier bị tạm ngưng
                if (this.id === 'supplierSelect') {
                    const selectedOption = $(this).find('option:selected');
                    const supplierStatus = selectedOption.data('status');
                    
                    // Nếu chọn supplier tạm ngưng (không nên xảy ra vì đã disabled)
                    if (supplierStatus === 'inactive') {
                        Swal.fire({
                            icon: 'warning',
                            title: '⚠️ Nhà cung cấp tạm ngưng',
                            html: '<p>Nhà cung cấp này đang bị <strong>tạm ngưng</strong>.</p>' +
                                  '<p class="text-muted mt-2">Vui lòng liên hệ <strong>Admin</strong> hoặc <strong>Quản lý</strong> để kích hoạt lại nhà cung cấp tại trang <a href="suppliers_management.php" target="_blank">Quản lý nhà cung cấp</a>.</p>',
                            confirmButtonText: 'Đã hiểu',
                            confirmButtonColor: '#6c757d'
                        });
                        $(this).val('');
                        selectedSupplier = null;
                        validateStep1();
                        saveDataToLocalStorage();
                        return;
                    }
                }
                
                validateStep1();
                
                // Lưu thông tin nhà cung cấp được chọn
                if (this.id === 'supplierSelect') {
                    const selectedSupplierId = $(this).val();
                    if (selectedSupplierId) {
                        // Tìm thông tin nhà cung cấp từ danh sách
                        const supplierOption = $(this).find('option:selected');
                        selectedSupplier = {
                            id: selectedSupplierId,
                            name: supplierOption.text(),
                            phone: supplierOption.data('phone') || 'Chưa có số điện thoại'
                        };
                        console.log('✅ Selected supplier:', selectedSupplier);
                    } else {
                        selectedSupplier = null;
                    }
                }
                
                // Always save to localStorage when any field changes
                saveDataToLocalStorage();
            });
            
            // Handle receipt notes changes
            $('#receiptNotes').on('change blur', function() {
                console.log('📝 Receipt notes changed');
                saveDataToLocalStorage();
            });

            // Handle next to upload button
            $('#nextToUpload').click(function() {
                if (validateStep1()) {
                    moveToStep(2);
                } else {
                    alert('Vui lòng chọn ngày nhập và nhà cung cấp');
                }
            });

            // Handle add supplier button - chuyển sang trang thêm nhà cung cấp
            $('#addSupplierBtn').click(function() {
                window.location.href = 'add_supplier.php';
            });

            // Initial validation
            validateStep1();

            // Handle step indicator clicks
            $('.step').click(function() {
                const stepNum = parseInt($(this).attr('id').replace('step', ''));
                
                // Only allow going back to completed steps or current step
                if ($(this).hasClass('completed') || $(this).hasClass('active')) {
                    moveToStep(stepNum);
                }
            });

            // Handle file upload
            $('#fileInput').change(function(e) {
                const files = Array.from(e.target.files);
                if (files.length < 2 || files.length > 4) {
                    alert('Vui lòng chọn 2-4 ảnh');
                    return;
                }
                handleFileUpload(files);
            });

            // Handle drag and drop
            $('#uploadArea').on('dragover', function(e) {
                e.preventDefault();
                $(this).addClass('drag-over');
            });

            $('#uploadArea').on('dragleave', function(e) {
                e.preventDefault();
                $(this).removeClass('drag-over');
            });

            $('#uploadArea').on('drop', function(e) {
                e.preventDefault();
                $(this).removeClass('drag-over');
                const files = Array.from(e.originalEvent.dataTransfer.files);
                if (files.length < 2 || files.length > 4) {
                    alert('Vui lòng chọn 2-4 ảnh');
                    return;
                }
                handleFileUpload(files);
            });

            function handleFileUpload(files) {
                const formData = new FormData();
                
                for (let i = 0; i < files.length; i++) {
                    formData.append('images[]', files[i]);
                }
                formData.append('action', 'upload_images');

                // Show loading
                $('#uploadArea').html(`
                    <div class="text-center">
                        <div class="spinner-border text-primary" role="status">
                            <span class="sr-only">Đang tải...</span>
                        </div>
                        <p class="mt-2">Đang tải ảnh lên...</p>
                    </div>
                `);

                $.ajax({
                    url: '',
                    method: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            uploadedImages = response.uploaded_files;
                            displayUploadedImages();
                            $('#analyzeSection').show();
                        } else {
                            alert('Lỗi tải ảnh: ' + response.message);
                            resetUploadArea();
                        }
                    },
                    error: function() {
                        alert('Có lỗi xảy ra khi tải ảnh');
                        resetUploadArea();
                    }
                });
            }

            function displayUploadedImages() {
                let html = `
                    <div class="alert alert-success">
                        <i class="fas fa-check"></i> Đã tải ${uploadedImages.length} ảnh thành công!
                    </div>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> <strong>Hướng dẫn:</strong> Click nút "Chọn làm ảnh chính" để đặt ảnh làm ảnh đại diện cho sản phẩm.
                    </div>
                    <div class="row">
                `;
                
                uploadedImages.forEach((file, index) => {
                    const displayUrl = file.url || file.path;
                    const isPrimary = index === primaryImageIndex;
                    
                    html += `
                        <div class="col-md-3 mb-3">
                            <div class="card image-card ${isPrimary ? 'border-primary' : ''}">
                                <img src="${displayUrl}" 
                                     class="card-img-top uploaded-image" 
                                     alt="Image ${index + 1}"
                                     onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwIiBoZWlnaHQ9IjEwMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cmVjdCB3aWR0aD0iMTAwIiBoZWlnaHQ9IjEwMCIgZmlsbD0iI2VlZSIvPjx0ZXh0IHg9IjUwIiB5PSI1MCIgZm9udC1zaXplPSIxMiIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZHk9Ii4zZW0iPkxvaSBoaW5oPC90ZXh0Pjwvc3ZnPg=='; this.style.border='2px dashed #dc3545';">
                                <div class="card-body p-2">
                                    <small class="text-muted d-block" style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="${file.name}">${file.name}</small>
                                    <small class="text-info d-block" style="font-size: 10px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="${displayUrl}">URL: ${displayUrl}</small>
                                    
                                    <div class="mt-2">
                                        <span class="badge badge-success">Ready ✓</span>
                                        ${isPrimary ? 
                                            '<span class="badge badge-primary ml-1"><i class="fas fa-star"></i> Ảnh chính</span>' : 
                                            `<button class="btn btn-sm btn-outline-primary ml-1" onclick="setPrimaryImage(${index})">
                                                <i class="fas fa-star"></i> Chọn làm ảnh chính
                                            </button>`
                                        }
                                    </div>
                                    
                                    <button class="btn btn-sm btn-outline-info mt-1" onclick="window.open('${displayUrl}', '_blank')">
                                        <i class="fas fa-eye"></i> Xem
                                    </button>
                                </div>
                            </div>
                        </div>
                    `;
                });
                
                html += '</div>';
                $('#imagePreview').html(html);
                
                // Add debug info
                console.log('Uploaded images:', uploadedImages);
                console.log('Primary image index:', primaryImageIndex);
                uploadedImages.forEach((file, index) => {
                    console.log(`Image ${index + 1}: ${file.url}`);
                });
                
                // Reset upload area
                resetUploadArea();
            }

            function resetUploadArea() {
                $('#uploadArea').html(`
                    <div class="upload-icon">
                        <i class="fas fa-cloud-upload-alt"></i>
                    </div>
                    <h5>Chọn ảnh sản phẩm</h5>
                    <p class="text-muted">Kéo thả hoặc nhấn để chọn 2-4 ảnh</p>
                    <p class="text-muted small">
                        Định dạng: JPG, PNG, WebP | Dung lượng: ≤5MB | Kích thước: ≥800×800px | Tỷ lệ: 1:1 hoặc 4:5
                    </p>
                    <input type="file" id="fileInput" accept="image/*" multiple style="display: none;">
                    <button type="button" class="btn btn-primary mt-3" onclick="document.getElementById('fileInput').click()">
                        Chọn file khác
                    </button>
                `);
                
                // Re-bind event
                $('#fileInput').change(function(e) {
                    const files = Array.from(e.target.files);
                    if (files.length < 2 || files.length > 4) {
                        alert('Vui lòng chọn 2-4 ảnh');
                        return;
                    }
                    handleFileUpload(files);
                });
            }

            // Analyze images with AI
            $('#analyzeBtn').click(function() {
                if (uploadedImages.length === 0) {
                    alert('Vui lòng tải ảnh trước khi phân tích');
                    return;
                }

                console.log('🤖 Starting AI analysis - Clearing old results first');
                
                // ✅ CLEAR tất cả kết quả AI cũ trước khi phân tích lại
                aiData = null;
                suggestions = null;
                window.aiData = null;
                window.suggestions = null;
                $('#aiResults').show(); // Show first to ensure selectors work
                $('#aiResults .ai-analysis-alert').remove(); // Remove old analysis info
                $('#aiResults').hide(); // Then hide again
                $('#analyzedImages').empty();
                $('#suggestionCard').hide();
                $('#aiSuggestions').empty();
                $('#stockReceiptDuplicateResults').empty().hide();
                $('#aiAutoFilledInfo').hide();
                $('#aiAutoFilledTable1').empty();
                $('#aiAutoFilledTable2').empty();
                $('#productTags').empty();
                $('#dominantColors').empty();
                $('#aiDuplicateCheckResult').hide().empty();
                $('#aiAutoFilledInfo').hide();
                $('#aiAutoFilledTable1').empty();
                $('#aiAutoFilledTable2').empty();
                
                // Move to step 3
                moveToStep(3);
                
                // Show images being analyzed
                displayImagesInAnalysisStep();
                $('#analyzingImages').show();
                $('#loadingSpinner').show();

                // Prepare image paths for analysis - use primary image first
                const imagePaths = [];
                // Add primary image first
                imagePaths.push(uploadedImages[primaryImageIndex].path);
                // Add other images
                uploadedImages.forEach((img, index) => {
                    if (index !== primaryImageIndex) {
                        imagePaths.push(img.path);
                    }
                });

                // Call AI API
                $.ajax({
                    url: '',
                    method: 'POST',
                    data: {
                        action: 'analyze_image',
                        image_paths: imagePaths
                    },
                    dataType: 'json',
                    success: function(response) {
                        $('#loadingSpinner').hide();
                        if (response.success) {
                            aiData = response.ai_data;
                            suggestions = response.suggestions;
                            
                            // Store to window for global access
                            window.aiData = aiData;
                            window.suggestions = suggestions;
                            
                            console.log('💾 Stored AI data globally:', window.aiData);
                            
                            // Hiển thị thông báo giới hạn ảnh nếu có
                            if (response.limit_message) {
                                $('#aiResults').prepend('<div class="alert alert-info mb-2 ai-analysis-alert"><i class="fas fa-info-circle"></i> ' + response.limit_message + '</div>');
                            }
                            
                            displayAIResults(aiData);
                            // Store image paths with primary image info
                            const orderedImagePaths = [];
                            // Add primary image first
                            orderedImagePaths.push(uploadedImages[primaryImageIndex].path);
                            // Add other images
                            uploadedImages.forEach((img, index) => {
                                if (index !== primaryImageIndex) {
                                    orderedImagePaths.push(img.path);
                                }
                            });
                            $('#finalImageUrl').val(JSON.stringify(orderedImagePaths));
                        } else {
                            alert('Lỗi phân tích AI: ' + response.message);
                            moveToStep(1);
                        }
                    },
                    error: function() {
                        $('#loadingSpinner').hide();
                        alert('Có lỗi xảy ra khi gọi API AI');
                        moveToStep(1);
                    }
                });
            });

            // Set primary image
            window.setPrimaryImage = function(index) {
                if (index >= 0 && index < uploadedImages.length) {
                    primaryImageIndex = index;
                    console.log('Primary image set to index:', index);
                    
                    // Re-display images with updated primary
                    displayUploadedImages();
                    
                    // Show success message
                    const fileName = uploadedImages[index].name;
                    const toast = `
                        <div class="alert alert-success alert-dismissible fade show" role="alert" style="position: fixed; top: 20px; right: 20px; z-index: 9999; max-width: 300px;">
                            <i class="fas fa-check"></i> Đã đặt <strong>${fileName}</strong> làm ảnh chính!
                            <button type="button" class="close" data-dismiss="alert">
                                <span>&times;</span>
                            </button>
                        </div>
                    `;
                    $('body').append(toast);
                    
                    // Auto remove toast after 3 seconds
                    setTimeout(() => {
                        $('.alert-dismissible').fadeOut();
                    }, 3000);
                }
            };

            function displayImagesInAnalysisStep() {
                let html = '<div class="row">';
                
                uploadedImages.forEach((file, index) => {
                    const displayUrl = file.url || file.path;
                    const isPrimary = index === primaryImageIndex;
                    
                    html += `
                        <div class="col-md-3 mb-3">
                            <div class="card ${isPrimary ? 'analyzing-image border-primary' : ''}">
                                <img src="${displayUrl}" 
                                     class="card-img-top" 
                                     style="height: 120px; object-fit: cover;" 
                                     alt="Image ${index + 1}">
                                <div class="card-body p-2 text-center">
                                    ${isPrimary ? 
                                        '<span class="badge badge-warning"><i class="fas fa-brain"></i> Đang phân tích</span><br><small class="text-primary"><i class="fas fa-star"></i> Ảnh chính</small>' : 
                                        '<span class="badge badge-secondary">Chờ</span>'
                                    }
                                </div>
                            </div>
                        </div>
                    `;
                });
                
                html += '</div>';
                $('#analyzingImagePreview').html(html);
            }

            // Định dạng lại tên sản phẩm theo chuẩn giống add_product_ai.php
            function formatProductName(data) {
                let productName = '';
                
                // 1. Loại sản phẩm là bắt buộc
                if (data.type) {
                    productName += data.type;
                }
                
                // 2. Thương hiệu (bỏ qua Unknown)
                if (data.brand && data.brand.toLowerCase() !== 'unknown') {
                    productName += (productName ? ' ' : '') + data.brand;
                }
                
                // 3. Tính năng hoặc phong cách
                if (data.features) {
                    productName += (productName ? ' ' : '') + data.features;
                } else if (data.style) {
                    productName += (productName ? ' ' : '') + data.style;
                }
                
                // 4. Màu sắc (Color)
                if (data.color) {
                    productName += (productName ? ' ' : '') + data.color;
                }
                
                // Trường hợp tất cả rỗng thì fallback về tên ban đầu nếu có
                if (!productName && data.name) {
                    productName = data.name;
                }
                
                console.log('📝 Formatted product name:', productName, 'from data:', data);
                return productName || 'Sản phẩm chưa có tên';
            }

            function displayAIResults(data) {
                console.log('🎯 [STOCK_RECEIPT] AI Results from multiple images:', data);
                
                // FORMAT TÊN SẢN PHẨM THEO QUY TẮC (giống add_product_ai.php)
                const formattedName = formatProductName(data);
                data.name = formattedName;
                
                // Display analyzed images info
                const imageCount = data.analyzed_images_count || uploadedImages.length;
                const confidence = data.confidence || 0.5;
                
                // Add multi-image analysis info
                let analysisInfo = `
                    <div class="alert alert-info mb-3 ai-analysis-alert">
                        <i class="fas fa-images mr-2"></i>
                        <strong>Đã phân tích ${imageCount} ảnh</strong> với độ tin cậy: 
                        <span class="badge badge-${confidence >= 0.8 ? 'success' : confidence >= 0.6 ? 'warning' : 'secondary'}">
                            ${Math.round(confidence * 100)}%
                        </span>
                    </div>
                `;
                
                // Display product information from Gemini
                if (data.tags && Array.isArray(data.tags)) {
                    let tagsHtml = '';
                    data.tags.forEach(tag => {
                        tagsHtml += `<span class="tag-item">${tag}</span>`;
                    });
                    $('#productTags').html(tagsHtml);
                }

                // Display colors
                if (data.colors && Array.isArray(data.colors)) {
                    let colorsHtml = '';
                    data.colors.forEach(color => {
                        colorsHtml += `<span class="badge badge-info mr-1">${color}</span>`;
                    });
                    $('#dominantColors').html(colorsHtml);
                }
                
                // Display image-specific details if available
                if (data.image_details && Array.isArray(data.image_details)) {
                    let imageDetailsHtml = '<div class="mt-3 ai-analysis-alert"><h6>Chi tiết từng ảnh:</h6><div class="row">';
                    data.image_details.forEach((detail, index) => {
                        imageDetailsHtml += `
                            <div class="col-md-6 mb-2">
                                <div class="card">
                                    <div class="card-body p-2">
                                        <h6 class="card-title">Ảnh ${detail.index || (index + 1)}</h6>
                                        <p class="card-text small">${detail.description || 'Không có mô tả'}</p>
                                        ${detail.key_features ? '<div class="small text-muted">Đặc điểm: ' + detail.key_features.join(', ') + '</div>' : ''}
                                    </div>
                                </div>
                            </div>
                        `;
                    });
                    imageDetailsHtml += '</div></div>';
                    analysisInfo += imageDetailsHtml;
                }
                
                // Remove old analysis alert before adding new one
                $('#aiResults .ai-analysis-alert').remove();
                
                // Prepend analysis info to AI results (keep static HTML)
                $('#aiResults').prepend(analysisInfo).show();
                
                // Hiển thị thông tin AI đã tự động điền
                displayAIAutoFilledInfo(data);
                
                // Tự động kiểm tra trùng lặp sau khi hiển thị kết quả AI
                console.log('🔍 Starting duplicate check with AI data:', data);
                setTimeout(() => {
                    checkForDuplicates(data);
                }, 1000);
            }
            
            // Hàm chuẩn hóa tên loại sản phẩm (copy from add_product_ai.php)
            function standardizeProductType(type) {
                if (!type) return '';
                
                const typeMapping = {
                    // Giày thể thao - Giữ nguyên "Sneaker"
                    'sneaker': 'Sneaker',
                    'sneakers': 'Sneaker',
                    'giày thể thao': 'Sneaker',
                    'giay the thao': 'Sneaker',
                    'running': 'Sneaker',
                    'running shoe': 'Sneaker',
                    'running shoes': 'Sneaker',
                    'training': 'Sneaker',
                    'training shoe': 'Sneaker',
                    'training shoes': 'Sneaker',
                    'casual': 'Sneaker',
                    'casual shoe': 'Sneaker',
                    'casual shoes': 'Sneaker',
                    'lifestyle': 'Sneaker',
                    'basketball': 'Sneaker',
                    'basketball shoe': 'Sneaker',
                    'basketball shoes': 'Sneaker',
                    'tennis': 'Sneaker',
                    'tennis shoe': 'Sneaker',
                    'tennis shoes': 'Sneaker',
                    'walking shoe': 'Sneaker',
                    'walking shoes': 'Sneaker',
                    'gym shoe': 'Sneaker',
                    'gym shoes': 'Sneaker',
                    'athletic shoe': 'Sneaker',
                    'athletic shoes': 'Sneaker',
                    'sport shoe': 'Sneaker',
                    'sport shoes': 'Sneaker',
                    'sports shoe': 'Sneaker',
                    'sports shoes': 'Sneaker',
                    
                    // Sandal - Giữ nguyên "Sandal"
                    'sandal': 'Sandal',
                    'sandals': 'Sandal',
                    'dép': 'Sandal',
                    'dep': 'Sandal',
                    'slide': 'Sandal',
                    'flip-flop': 'Sandal',
                    'flipflop': 'Sandal',
                    
                    // Giày cao gót
                    'high heels': 'Giày cao gót',
                    'high heel': 'Giày cao gót',
                    'cao gót': 'Giày cao gót',
                    'cao got': 'Giày cao gót',
                    'giày cao gót': 'Giày cao gót',
                    'giay cao got': 'Giày cao gót',
                    'pump': 'Giày cao gót',
                    'pumps': 'Giày cao gót',
                    'stiletto': 'Giày cao gót',
                    'stilettos': 'Giày cao gót',
                    'block heel': 'Giày cao gót',
                    'kitten heels': 'Giày cao gót',
                    'kitten heel': 'Giày cao gót',
                    'slingback': 'Giày cao gót',
                    'slingbacks': 'Giày cao gót',
                    
                    // Giày đế xuồng
                    'wedge': 'Giày đế xuồng',
                    'wedges': 'Giày đế xuồng',
                    'đế xuồng': 'Giày đế xuồng',
                    'de xuong': 'Giày đế xuồng',
                    'giày đế xuồng': 'Giày đế xuồng',
                    'sandal đế xuồng': 'Giày đế xuồng',  // Ưu tiên đế xuồng hơn sandal
                    'sandal de xuong': 'Giày đế xuồng',
                    'sandal bịt mũi đế xuồng': 'Giày đế xuồng',
                    'sandal bit mui de xuong': 'Giày đế xuồng',
                    'wedge sandal': 'Giày đế xuồng',
                    'wedge sandals': 'Giày đế xuồng',
                    
                    // Giày bệt
                    'flat': 'Giày bệt',
                    'flats': 'Giày bệt',
                    'giày bệt': 'Giày bệt',
                    'giay bet': 'Giày bệt',
                    'ballet flat': 'Giày bệt',
                    'ballet flats': 'Giày bệt',
                    'giày búp bê': 'Giày bệt',
                    'bệt': 'Giày bệt',
                    'bet': 'Giày bệt',
                    
                    // Giày lười
                    'loafer': 'Giày lười',
                    'loafers': 'Giày lười',
                    'giày lười': 'Giày lười',
                    'giay luoi': 'Giày lười',
                    'slip-on': 'Giày lười',
                    'slip on': 'Giày lười',
                    'slipon': 'Giày lười',
                    'moccasin': 'Giày lười',
                    'moccasins': 'Giày lười',
                    'boat shoe': 'Giày lười',
                    'boat shoes': 'Giày lười',
                    
                    // Giày tây
                    'oxford': 'Giày tây',
                    'oxfords': 'Giày tây',
                    'giày tây': 'Giày tây',
                    'giay tay': 'Giày tây',
                    'dress shoe': 'Giày tây',
                    'dress shoes': 'Giày tây',
                    'formal shoe': 'Giày tây',
                    'formal shoes': 'Giày tây',
                    'business shoe': 'Giày tây',
                    'derby': 'Giày tây',
                    'brogue': 'Giày tây',
                    
                    // Boot
                    'boot': 'Boot',
                    'boots': 'Boot',
                    'giày boot': 'Boot',
                    'giay boot': 'Boot',
                    'ankle boot': 'Boot',
                    'ankle boots': 'Boot',
                    'chelsea boot': 'Boot',
                    'chelsea boots': 'Boot',
                    'combat boot': 'Boot',
                    'combat boots': 'Boot',
                    'knee boot': 'Boot',
                    'knee boots': 'Boot',
                    
                    // Giày mules
                    'mule': 'Giày mules',
                    'mules': 'Giày mules',
                    'giày mules': 'Giày mules',
                    
                    // Giày quai hậu
                    'quai hậu': 'Giày quai hậu',
                    'quai hau': 'Giày quai hậu',
                    'giày quai hậu': 'Giày quai hậu',
                    
                    // Giày thể thao chuyên dụng
                    'football boot': 'Giày thể thao chuyên dụng',
                    'football boots': 'Giày thể thao chuyên dụng',
                    'soccer cleat': 'Giày thể thao chuyên dụng',
                    'golf shoe': 'Giày thể thao chuyên dụng',
                    'golf shoes': 'Giày thể thao chuyên dụng',
                    
                    // ============ THÊM CÁC LOẠI PHỔ BIẾN TẠI VIỆT NAM ============
                    
                    // Dép (các biến thể)
                    'dép lê': 'Dép',
                    'dep le': 'Dép',
                    'dép đi trong nhà': 'Dép',
                    'dep di trong nha': 'Dép',
                    'dép xỏ ngón': 'Dép',
                    'dep xo ngon': 'Dép',
                    'dép quai ngang': 'Dép',
                    'dep quai ngang': 'Dép',
                    'slipper': 'Dép',
                    'slippers': 'Dép',
                    'house slipper': 'Dép',
                    'indoor slipper': 'Dép',
                    
                    // Giày thể thao (các biến thể tiếng Việt)
                    'giày chạy bộ': 'Sneaker',
                    'giay chay bo': 'Sneaker',
                    'giày tập gym': 'Sneaker',
                    'giay tap gym': 'Sneaker',
                    'giày tập luyện': 'Sneaker',
                    'giay tap luyen': 'Sneaker',
                    'giày đá bóng': 'Giày thể thao chuyên dụng',
                    'giay da bong': 'Giày thể thao chuyên dụng',
                    
                    // Giày cao gót (các biến thể)
                    'giày gót nhọn': 'Giày cao gót',
                    'giay got nhon': 'Giày cao gót',
                    'giày gót vuông': 'Giày cao gót',
                    'giay got vuong': 'Giày cao gót',
                    'giày gót thấp': 'Giày cao gót',
                    'giay got thap': 'Giày cao gót',
                    'giày cao cổ': 'Giày cao gót',
                    'giay cao co': 'Giày cao gót',
                    
                    // Boot (các biến thể)
                    'bốt': 'Boot',
                    'bot': 'Boot',
                    'giày bốt': 'Boot',
                    'giay bot': 'Boot',
                    'boot cao cổ': 'Boot',
                    'boot cao co': 'Boot',
                    'boot cổ ngắn': 'Boot',
                    'boot co ngan': 'Boot',
                    'boot da': 'Boot',
                    'boot cao gót': 'Boot',
                    'boot cao got': 'Boot',
                    'martin': 'Boot',
                    'dr martens': 'Boot',
                    'dr. martens': 'Boot',
                    
                    // Sandal (các biến thể - NHƯNG KHÔNG BAO GỒM ĐẾ XUỒNG)
                    'sandal quai': 'Sandal',
                    'sandal nữ': 'Sandal',
                    'sandal nu': 'Sandal',
                    'sandal nam': 'Sandal',
                    'sandal trẻ em': 'Sandal',
                    'sandal tre em': 'Sandal',
                    'sandal bít mũi': 'Sandal',  // Trừ khi có "đế xuồng" thì sẽ bị override
                    'sandal bit mui': 'Sandal',
                    'sandal hở mũi': 'Sandal',
                    'sandal ho mui': 'Sandal',
                    
                    // Giày lười (các biến thể)
                    'giày không dây': 'Giày lười',
                    'giay khong day': 'Giày lười',
                    'giày slip on': 'Giày lười',
                    'giay slip on': 'Giày lười',
                    'giày lười nam': 'Giày lười',
                    'giay luoi nam': 'Giày lười',
                    'giày lười nữ': 'Giày lười',
                    'giay luoi nu': 'Giày lười',
                    'giày da lười': 'Giày lười',
                    'giay da luoi': 'Giày lười',
                    
                    // Giày bệt (các biến thể)
                    'giày đế bằng': 'Giày bệt',
                    'giay de bang': 'Giày bệt',
                    'giày búp bê nữ': 'Giày bệt',
                    'giay bup be nu': 'Giày bệt',
                    'giày ballerina': 'Giày bệt',
                    'giay ballerina': 'Giày bệt',
                    'giày êm chân': 'Giày bệt',
                    'giay em chan': 'Giày bệt',
                    
                    // Giày tây (các biến thể)
                    'giày da nam': 'Giày tây',
                    'giay da nam': 'Giày tây',
                    'giày công sở': 'Giày tây',
                    'giay cong so': 'Giày tây',
                    'giày công sở nam': 'Giày tây',
                    'giay cong so nam': 'Giày tây',
                    'giày dây': 'Giày tây',
                    'giay day': 'Giày tây',
                    'giày buộc dây': 'Giày tây',
                    'giay buoc day': 'Giày tây',
                    
                    // Giày đế xuồng (CỰC KỲ QUAN TRỌNG - các biến thể)
                    'giày sandal đế xuồng': 'Giày đế xuồng',
                    'giay sandal de xuong': 'Giày đế xuồng',
                    'sandal nữ đế xuồng': 'Giày đế xuồng',
                    'sandal nu de xuong': 'Giày đế xuồng',
                    'giày đế bằng xuồng': 'Giày đế xuồng',
                    'giay de bang xuong': 'Giày đế xuồng',
                    'giày nữ đế xuồng': 'Giày đế xuồng',
                    'giay nu de xuong': 'Giày đế xuồng',
                    'giày cao gót đế xuồng': 'Giày đế xuồng',
                    'giay cao got de xuong': 'Giày đế xuồng',
                    'wedge heel': 'Giày đế xuồng',
                    'wedge heel sandal': 'Giày đế xuồng',
                    'wedge heel shoe': 'Giày đế xuồng',
                    'platform wedge': 'Giày đế xuồng',
                    
                    // Giày mules (các biến thể)
                    'giày không gót': 'Giày mules',
                    'giay khong got': 'Giày mules',
                    'giày hở gót': 'Giày mules',
                    'giay ho got': 'Giày mules',
                    'giày sục': 'Giày mules',
                    'giay suc': 'Giày mules',
                    
                    // Giày quai hậu (các biến thể)
                    'giày có quai hậu': 'Giày quai hậu',
                    'giay co quai hau': 'Giày quai hậu',
                    'giày quai sau': 'Giày quai hậu',
                    'giay quai sau': 'Giày quai hậu',
                    'slingback heels': 'Giày quai hậu',
                    'slingback pumps': 'Giày quai hậu',
                    
                    // Các loại đặc biệt khác
                    'giày vải': 'Sneaker',  // Thường là giày thể thao
                    'giay vai': 'Sneaker',
                    'giày canvas': 'Sneaker',
                    'giay canvas': 'Sneaker',
                    'converse': 'Sneaker',
                    'vans': 'Sneaker',
                    
                    // Giày thời trang (tùy context)
                    'giày thời trang': 'Sneaker',  // Mặc định về Sneaker
                    'giay thoi trang': 'Sneaker',
                    'giày casual nữ': 'Sneaker',
                    'giay casual nu': 'Sneaker',
                    'giày casual nam': 'Sneaker',
                    'giay casual nam': 'Sneaker',
                    
                    // Giày oxford (dạng tây)
                    'giày oxford': 'Giày tây',
                    'giay oxford': 'Giày tây',
                    'giày oxford nam': 'Giày tây',
                    'giay oxford nam': 'Giày tây',
                    
                    // Espadrilles (giày vải cói - thường là bệt)
                    'espadrille': 'Giày bệt',
                    'espadrilles': 'Giày bệt',
                    'giày cói': 'Giày bệt',
                    'giay coi': 'Giày bệt',
                    
                    // Platform (giày đế cao nhưng không phải xuồng)
                    'platform': 'Giày cao gót',
                    'platform shoe': 'Giày cao gót',
                    'platform shoes': 'Giày cao gót',
                    'giày đế cao': 'Giày cao gót',
                    'giay de cao': 'Giày cao gót',
                    
                    // Giày Mary Jane
                    'mary jane': 'Giày bệt',
                    'mary janes': 'Giày bệt',
                    'giày mary jane': 'Giày bệt',
                    
                    // Giày monk strap
                    'monk strap': 'Giày tây',
                    'monk straps': 'Giày tây',
                    'giày monk': 'Giày tây',
                    
                    // Giày penny loafer
                    'penny loafer': 'Giày lười',
                    'penny loafers': 'Giày lười',
                    
                    // Giày driving (lái xe)
                    'driving shoe': 'Giày lười',
                    'driving shoes': 'Giày lười',
                    'giày lái xe': 'Giày lười',
                    'giay lai xe': 'Giày lười',
                };
                
                const typeLower = type.trim().toLowerCase();
                
                // Sắp xếp keys theo độ dài giảm dần để kiểm tra cụm từ dài trước
                const sortedKeys = Object.keys(typeMapping).sort((a, b) => b.length - a.length);
                
                // Kiểm tra exact match trước
                if (typeMapping[typeLower]) {
                    return typeMapping[typeLower];
                }
                
                // Nếu không có exact match, tìm partial match với cụm từ dài nhất
                for (const key of sortedKeys) {
                    if (typeLower.includes(key)) {
                        console.log(`🔍 Partial match: '${typeLower}' contains '${key}' → '${typeMapping[key]}'`);
                        return typeMapping[key];
                    }
                }
                
                // Nếu không tìm thấy, giữ nguyên
                return type;
            }
            
            // Hàm chuẩn hóa màu sắc để đảm bảo nhất quán
            function standardizeColor(color) {
                if (!color) return '';
                
                const colorMapping = {
                    // ============ BASIC COLORS (Màu cơ bản) ============
                    'black': 'Đen',
                    'white': 'Trắng',
                    'red': 'Đỏ',
                    'blue': 'Xanh dương',
                    'green': 'Xanh lá',
                    'yellow': 'Vàng',
                    'orange': 'Cam',
                    'purple': 'Tím',
                    'pink': 'Hồng',
                    'brown': 'Nâu',
                    'gray': 'Xám',
                    'grey': 'Xám',
                    
                    // ============ SPECIAL COLORS (Màu đặc biệt) ============
                    'beige': 'Beige',
                    'cream': 'Trắng kem',
                    'nude': 'Nude',
                    'gold': 'Vàng kim',
                    'silver': 'Bạc',
                    'bronze': 'Đồng',
                    'copper': 'Đồng đỏ',
                    'rose gold': 'Vàng hồng',
                    'rosegold': 'Vàng hồng',
                    
                    // ============ VIETNAMESE VARIATIONS (Biến thể tiếng Việt) ============
                    'đen': 'Đen',
                    'den': 'Đen',
                    'trắng': 'Trắng',
                    'trang': 'Trắng',
                    'đỏ': 'Đỏ',
                    'do': 'Đỏ',
                    'xanh': 'Xanh dương',
                    'xanh dương': 'Xanh dương',
                    'xanh duong': 'Xanh dương',
                    'xanh lá': 'Xanh lá',
                    'xanh la': 'Xanh lá',
                    'xanh lá cây': 'Xanh lá',
                    'vàng': 'Vàng',
                    'vang': 'Vàng',
                    'cam': 'Cam',
                    'tím': 'Tím',
                    'tim': 'Tím',
                    'hồng': 'Hồng',
                    'hong': 'Hồng',
                    'nâu': 'Nâu',
                    'nau': 'Nâu',
                    'xám': 'Xám',
                    'xam': 'Xám',
                    'be': 'Beige',
                    'kem': 'Trắng kem',
                    'trắng be': 'Beige',
                    'trang be': 'Beige',
                    'màu be': 'Beige',
                    'mau be': 'Beige',
                    'màu kem': 'Trắng kem',
                    'mau kem': 'Trắng kem',
                    'màu nude': 'Nude',
                    'mau nude': 'Nude',
                    'vàng kim': 'Vàng kim',
                    'vang kim': 'Vàng kim',
                    'bạc': 'Bạc',
                    'bac': 'Bạc',
                    
                    // ============ SHADES & MODIFIERS (Sắc độ & Bổ nghĩa) ============
                    'dark': 'đậm',
                    'light': 'nhạt',
                    'bright': 'tươi',
                    'pale': 'nhạt',
                    'deep': 'đậm',
                    'vivid': 'rực rỡ',
                    'pastel': 'pastel',
                    'neon': 'neon',
                    'matte': 'mờ',
                    'metallic': 'kim loại',
                    'fluorescent': 'huỳnh quang',
                    
                    // ============ BLUE FAMILY (Họ màu xanh dương) ============
                    'navy': 'Xanh navy',
                    'navy blue': 'Xanh navy',
                    'royal blue': 'Xanh hoàng gia',
                    'sky blue': 'Xanh da trời',
                    'baby blue': 'Xanh baby',
                    'powder blue': 'Xanh phấn',
                    'turquoise': 'Xanh ngọc',
                    'teal': 'Xanh lục',
                    'cyan': 'Xanh cyan',
                    'aqua': 'Xanh nước biển',
                    'cobalt': 'Xanh cobalt',
                    'indigo': 'Xanh chàm',
                    'sapphire': 'Xanh sapphire',
                    'azure': 'Xanh thiên thanh',
                    'cerulean': 'Xanh cerulean',
                    
                    // ============ GREEN FAMILY (Họ màu xanh lá) ============
                    'lime': 'Xanh chanh',
                    'lime green': 'Xanh chanh',
                    'olive': 'Xanh ô liu',
                    'olive green': 'Xanh ô liu',
                    'mint': 'Xanh bạc hà',
                    'mint green': 'Xanh bạc hà',
                    'emerald': 'Xanh lục bảo',
                    'forest green': 'Xanh rừng',
                    'sage': 'Xanh sage',
                    'sage green': 'Xanh sage',
                    'seafoam': 'Xanh biển',
                    'pistachio': 'Xanh hạt dẻ',
                    'jade': 'Xanh ngọc bích',
                    'hunter green': 'Xanh thợ săn',
                    'kelly green': 'Xanh kelly',
                    
                    // ============ RED FAMILY (Họ màu đỏ) ============
                    'burgundy': 'Đỏ burgundy',
                    'wine': 'Đỏ rượu',
                    'wine red': 'Đỏ rượu',
                    'maroon': 'Đỏ nâu',
                    'crimson': 'Đỏ thẫm',
                    'scarlet': 'Đỏ tươi',
                    'cherry': 'Đỏ cherry',
                    'cherry red': 'Đỏ cherry',
                    'ruby': 'Đỏ ruby',
                    'brick': 'Đỏ gạch',
                    'brick red': 'Đỏ gạch',
                    'tomato': 'Đỏ cà chua',
                    'rust': 'Đỏ rỉ sét',
                    'terracotta': 'Đỏ đất nung',
                    'cardinal': 'Đỏ hồng y',
                    
                    // ============ PINK FAMILY (Họ màu hồng) ============
                    'hot pink': 'Hồng cánh sen',
                    'fuchsia': 'Hồng fuchsia',
                    'magenta': 'Hồng magenta',
                    'rose': 'Hồng rose',
                    'blush': 'Hồng phấn',
                    'blush pink': 'Hồng phấn',
                    'baby pink': 'Hồng baby',
                    'salmon': 'Hồng cam',
                    'coral': 'San hô',
                    'peach': 'Hồng đào',
                    'mauve': 'Hồng tím',
                    'dusty rose': 'Hồng cánh hoa khô',
                    'dusty pink': 'Hồng cánh hoa khô',
                    
                    // ============ PURPLE/VIOLET FAMILY (Họ màu tím) ============
                    'lavender': 'Tím lavender',
                    'lilac': 'Tím lilac',
                    'violet': 'Tím violet',
                    'plum': 'Tím mận',
                    'eggplant': 'Tím cà',
                    'orchid': 'Tím phong lan',
                    'amethyst': 'Tím thạch anh',
                    'periwinkle': 'Tím periwinkle',
                    
                    // ============ BROWN FAMILY (Họ màu nâu) ============
                    'tan': 'Nâu nhạt',
                    'taupe': 'Nâu xám',
                    'khaki': 'Kaki',
                    'camel': 'Nâu lạc đà',
                    'chocolate': 'Nâu sô cô la',
                    'coffee': 'Nâu cà phê',
                    'mocha': 'Nâu mocha',
                    'chestnut': 'Nâu hạt dẻ',
                    'cognac': 'Nâu cognac',
                    'mahogany': 'Nâu gỗ',
                    'espresso': 'Nâu espresso',
                    'sienna': 'Nâu sienna',
                    'umber': 'Nâu umber',
                    'sepia': 'Nâu sepia',
                    
                    // ============ NEUTRAL FAMILY (Họ màu trung tính) ============
                    'ivory': 'Ngà',
                    'ecru': 'Ngà sữa',
                    'linen': 'Vải lanh',
                    'champagne': 'Champagne',
                    'sand': 'Cát',
                    'stone': 'Đá',
                    'charcoal': 'Xám đen',
                    'slate': 'Xám đá phiến',
                    'slate gray': 'Xám đá phiến',
                    'ash': 'Xám tro',
                    'ash gray': 'Xám tro',
                    'dove gray': 'Xám bồ câu',
                    'smoke': 'Xám khói',
                    'pewter': 'Xám thiếc',
                    'graphite': 'Xám graphite',
                    
                    // ============ YELLOW/ORANGE FAMILY (Họ màu vàng/cam) ============
                    'mustard': 'Vàng mù tạt',
                    'goldenrod': 'Vàng goldenrod',
                    'canary': 'Vàng canary',
                    'lemon': 'Vàng chanh',
                    'amber': 'Hổ phách',
                    'apricot': 'Cam mơ',
                    'tangerine': 'Cam quýt',
                    'pumpkin': 'Cam bí ngô',
                    'burnt orange': 'Cam cháy',
                    'rust orange': 'Cam rỉ sét',
                    
                    // ============ WHITE VARIATIONS (Biến thể màu trắng) ============
                    'off-white': 'Trắng ngà',
                    'off white': 'Trắng ngà',
                    'snow white': 'Trắng tuyết',
                    'pearl': 'Trắng ngọc trai',
                    'pearl white': 'Trắng ngọc trai',
                    'milk white': 'Trắng sữa',
                    'eggshell': 'Trắng vỏ trứng',
                    
                    // ============ BLACK VARIATIONS (Biến thể màu đen) ============
                    'jet black': 'Đen tuyền',
                    'ebony': 'Đen mun',
                    'onyx': 'Đen onyx',
                    'coal': 'Đen than',
                    'midnight': 'Đen nửa đêm',
                    'raven': 'Đen quạ',
                    
                    // ============ METALLIC (Kim loại) ============
                    'platinum': 'Bạch kim',
                    'gunmetal': 'Xám kim loại',
                    'chrome': 'Chrome',
                    'titanium': 'Titan',
                    
                    // ============ COMBINATIONS (Tổ hợp màu thông dụng) ============
                    'light blue': 'Xanh dương nhạt',
                    'dark blue': 'Xanh dương đậm',
                    'light green': 'Xanh lá nhạt',
                    'dark green': 'Xanh lá đậm',
                    'bright red': 'Đỏ tươi',
                    'dark red': 'Đỏ đậm',
                    'light pink': 'Hồng nhạt',
                    'dark pink': 'Hồng đậm',
                    'light purple': 'Tím nhạt',
                    'dark purple': 'Tím đậm',
                    'light brown': 'Nâu nhạt',
                    'dark brown': 'Nâu đậm',
                    'light gray': 'Xám nhạt',
                    'dark gray': 'Xám đậm',
                    'light grey': 'Xám nhạt',
                    'dark grey': 'Xám đậm',
                    'bright yellow': 'Vàng tươi',
                    'bright orange': 'Cam tươi',
                    'pale pink': 'Hồng nhạt',
                    'pale blue': 'Xanh nhạt',
                    'pale green': 'Xanh nhạt',
                    'pale yellow': 'Vàng nhạt',
                    
                    // ============ MULTICOLOR (Nhiều màu) ============
                    'multicolor': 'Nhiều màu',
                    'multi-color': 'Nhiều màu',
                    'rainbow': 'Cầu vồng',
                    'colorful': 'Nhiều màu',
                    'mixed': 'Pha trộn',
                    'tie dye': 'Tie dye',
                    'tie-dye': 'Tie dye',
                    'camouflage': 'Rằn ri',
                    'camo': 'Rằn ri',
                    'leopard': 'Da báo',
                    'zebra': 'Ngựa vằn',
                    'animal print': 'Họa tiết động vật',
                    
                    // ============ TRANSPARENT/CLEAR (Trong suốt) ============
                    'transparent': 'Trong suốt',
                    'clear': 'Trong',
                    'translucent': 'Mờ đục',
                };
                
                const colorLower = color.trim().toLowerCase();
                
                // Check exact match first
                if (colorMapping[colorLower]) {
                    return colorMapping[colorLower];
                }
                
                // Try to translate parts if it contains multiple words
                const words = colorLower.split(/\s+/);
                if (words.length > 1) {
                    const translatedWords = [];
                    for (const word of words) {
                        if (colorMapping[word]) {
                            translatedWords.push(colorMapping[word]);
                        } else {
                            // Capitalize first letter if not found
                            translatedWords.push(word.charAt(0).toUpperCase() + word.slice(1));
                        }
                    }
                    return translatedWords.join(' ');
                }
                
                // If not found, capitalize first letter
                return color.charAt(0).toUpperCase() + color.slice(1);
            }
            
            // Hàm chuẩn hóa danh sách màu sắc
            function normalizeColors(colors) {
                if (!colors) return [];
                
                // Nếu là mảng, chuẩn hóa từng phần tử
                if (Array.isArray(colors)) {
                    return colors.map(color => standardizeColor(color));
                }
                
                // Nếu là chuỗi, tách và chuẩn hóa
                if (typeof colors === 'string') {
                    return colors.split(',').map(color => standardizeColor(color.trim())).filter(c => c);
                }
                
                return [];
            }
            
            // Hàm hiển thị thông tin AI đã tự động điền
            function displayAIAutoFilledInfo(data) {
                console.log('📝 Displaying AI auto-filled information:', data);
                
                // Chuyển "Fashion" thành "Unknown" cho thương hiệu
                if (data.brand && data.brand.trim().toLowerCase() === 'fashion') {
                    console.log('⚠️ Converting brand "Fashion" to "Unknown"');
                    data.brand = 'Unknown';
                }
                
                // 🎨 CHUẨN HÓA MÀU SẮC - Tránh lỗi nhận diện trùng lặp
                if (data.colors) {
                    const originalColors = JSON.stringify(data.colors);
                    data.colors = normalizeColors(data.colors);
                    console.log(`🎨 Normalized colors: ${originalColors} -> ${JSON.stringify(data.colors)}`);
                }
                
                // Chuẩn hóa tên loại sản phẩm
                if (data.type) {
                    const originalType = data.type;
                    data.type = standardizeProductType(data.type);
                    if (originalType !== data.type) {
                        console.log(`📝 Standardized product type: '${originalType}' -> '${data.type}'`);
                    }
                }
                if (data.category) {
                    const originalCategory = data.category;
                    data.category = standardizeProductType(data.category);
                    if (originalCategory !== data.category) {
                        console.log(`📝 Standardized category: '${originalCategory}' -> '${data.category}'`);
                    }
                }
                
                // 🔴 BẮT BUỘC: Kiểm tra đặc biệt cho Sandal đế xuồng (Lớp 3: Forced Correction)
                if (data.type && data.type.toLowerCase() === 'sandal') {
                    const checkFields = [
                        data.name || '',
                        data.description || '',
                        Array.isArray(data.tags) ? data.tags.join(' ') : (data.tags || ''),
                        data.features || ''
                    ];
                    
                    const combinedText = checkFields.join(' ').toLowerCase();
                    
                    // Nếu có từ khóa "đế xuồng" hoặc "wedge" → BUỘC chuyển thành "Giày đế xuồng"
                    if (combinedText.includes('đế xuồng') || 
                        combinedText.includes('de xuong') ||
                        combinedText.includes('wedge')) {
                        console.log('🔴 FORCED CORRECTION: Sandal with wedge heel detected → Changing type from "Sandal" to "Giày đế xuồng"');
                        data.type = 'Giày đế xuồng';
                    }
                }
                
                // Build table 1 (left column)
                let table1Html = '';
                if (data.name) {
                    table1Html += `
                        <tr>
                            <td class="font-weight-bold" style="width: 40%;">
                                <i class="fas fa-tag text-primary"></i> Tên sản phẩm:
                            </td>
                            <td>${data.name}</td>
                        </tr>
                    `;
                }
                if (data.brand) {
                    table1Html += `
                        <tr>
                            <td class="font-weight-bold">
                                <i class="fas fa-copyright text-info"></i> Thương hiệu:
                            </td>
                            <td>${data.brand}</td>
                        </tr>
                    `;
                }
                if (data.type) {
                    table1Html += `
                        <tr>
                            <td class="font-weight-bold">
                                <i class="fas fa-shoe-prints text-success"></i> Loại sản phẩm:
                            </td>
                            <td>${data.type}</td>
                        </tr>
                    `;
                }
                // Bỏ trường Danh mục theo yêu cầu
                if (data.description) {
                    const descShort = data.description.length > 60 ? data.description.substring(0, 60) + '...' : data.description;
                    table1Html += `
                        <tr>
                            <td class="font-weight-bold">
                                <i class="fas fa-align-left text-secondary"></i> Mô tả:
                            </td>
                            <td><small class="text-muted">${descShort}</small></td>
                        </tr>
                    `;
                }
                
                // Build table 2 (right column)
                let table2Html = '';
                if (data.colors) {
                    const colorDisplay = Array.isArray(data.colors) ? data.colors.join(', ') : data.colors;
                    table2Html += `
                        <tr>
                            <td class="font-weight-bold" style="width: 40%;">
                                <i class="fas fa-palette text-danger"></i> Màu sắc:
                            </td>
                            <td>${colorDisplay}</td>
                        </tr>
                    `;
                }
                if (data.material) {
                    table2Html += `
                        <tr>
                            <td class="font-weight-bold">
                                <i class="fas fa-layer-group text-secondary"></i> Chất liệu:
                            </td>
                            <td>${data.material}</td>
                        </tr>
                    `;
                }
                if (data.features) {
                    const featuresShort = data.features.length > 50 ? data.features.substring(0, 50) + '...' : data.features;
                    table2Html += `
                        <tr>
                            <td class="font-weight-bold">
                                <i class="fas fa-star text-warning"></i> Tính năng:
                            </td>
                            <td>${featuresShort}</td>
                        </tr>
                    `;
                }
                if (data.tags && Array.isArray(data.tags) && data.tags.length > 0) {
                    table2Html += `
                        <tr>
                            <td class="font-weight-bold">
                                <i class="fas fa-hashtag text-info"></i> Tags:
                            </td>
                            <td>${data.tags.slice(0, 3).join(', ')}${data.tags.length > 3 ? '...' : ''}</td>
                        </tr>
                    `;
                }
                
                // Populate tables
                $('#aiAutoFilledTable1').html(table1Html);
                $('#aiAutoFilledTable2').html(table2Html);
                
                // Show the auto-filled info section
                $('#aiAutoFilledInfo').slideDown();
                
                console.log('✅ AI auto-filled info displayed');
            }
            
            // Function to load existing product data for update
            function loadExistingProductData(productId, productName) {
                console.log('🔄 Loading existing product data for ID:', productId);
                
                // Show loading
                $('#aiResults').prepend('<div class="alert alert-info ai-analysis-alert" id="loadingProductData"><i class="fas fa-spinner fa-spin"></i> Đang tải thông tin sản phẩm hiện tại...</div>');
                
                $.ajax({
                    url: '',
                    method: 'POST',
                    data: {
                        action: 'get_product_for_update',
                        product_id: productId
                    },
                    dataType: 'json',
                    success: function(response) {
                        $('#loadingProductData').remove();
                        
                        if (response.success) {
                            // Set update mode
                            isUpdateMode = true;
                            updateProductId = productId;
                            
                            // Move to step 4
                            moveToStep(4);
                            
                            // Populate form with existing data
                            populateFormWithExistingData(response.product);
                            
                            // Show update mode alert
                            $('#productForm').prepend(`
                                <div class="alert alert-info" id="updateModeAlert">
                                    <i class="fas fa-edit"></i> <strong>Chế độ cập nhật sản phẩm:</strong> 
                                    Bạn đang cập nhật sản phẩm "${productName}" (ID: ${productId})
                                    <br><small>Lưu ý: Mã sản phẩm và SKU cũ sẽ được giữ nguyên. Size mới sẽ tạo SKU mới.</small>
                                    <button type="button" class="close" onclick="$('#updateModeAlert').remove();">
                                        <span>&times;</span>
                                    </button>
                                </div>
                            `);
                        } else {
                            alert('Lỗi khi tải thông tin sản phẩm: ' + response.message);
                        }
                    },
                    error: function() {
                        $('#loadingProductData').remove();
                        alert('Lỗi khi tải thông tin sản phẩm');
                    }
                });
            }
            
            // Function to load existing product data with AI suggestions
            function loadExistingProductDataWithAI(productId, productName) {
                console.log('🤖 Loading existing product data with AI suggestions for ID:', productId);
                
                // Show loading
                $('#aiResults').prepend('<div class="alert alert-info ai-analysis-alert" id="loadingProductData"><i class="fas fa-spinner fa-spin"></i> Đang tải thông tin sản phẩm và áp dụng gợi ý AI...</div>');
                
                $.ajax({
                    url: '',
                    method: 'POST',
                    data: {
                        action: 'get_product_for_update',
                        product_id: productId
                    },
                    dataType: 'json',
                    success: function(response) {
                        $('#loadingProductData').remove();
                        
                        if (response.success) {
                            // Set update mode
                            isUpdateMode = true;
                            updateProductId = productId;
                            
                            // Move to step 4
                            moveToStep(4);
                            
                            // Populate form with mixed data (AI suggestions + existing data)
                            populateFormWithMixedData(response.product, aiData);
                            
                            // Show update mode alert with AI note
                            $('#productForm').prepend(`
                                <div class="alert alert-success" id="updateModeAlert">
                                    <i class="fas fa-robot"></i> <strong>Chế độ cập nhật với AI:</strong> 
                                    Bạn đang cập nhật sản phẩm "${productName}" với gợi ý từ AI (ID: ${productId})
                                    <br><small>Lưu ý: Tên, mô tả, thương hiệu từ AI. Mã SKU cũ sẽ được giữ nguyên. Size mới sẽ tạo SKU mới.</small>
                                    <button type="button" class="close" onclick="$('#updateModeAlert').remove();">
                                        <span>&times;</span>
                                    </button>
                                </div>
                            `);
                        } else {
                            alert('Lỗi khi tải thông tin sản phẩm: ' + response.message);
                        }
                    },
                    error: function() {
                        $('#loadingProductData').remove();
                        alert('Lỗi khi tải thông tin sản phẩm');
                    }
                });
            }
            
            // Function to populate form with existing product data
            function populateFormWithExistingData(product) {
                console.log('📝 Populating form with existing product data:', product);
                console.log('🚨 useExistingDataOnly flag:', useExistingDataOnly);
                
                // Clear any AI data first if we're using existing data only
                if (useExistingDataOnly) {
                    console.log('🔒 Using existing data only - blocking AI override');
                }
                
                // Populate basic fields with existing data (user can edit these)
                $('#productName').val(product.name || ''); // Correct ID
                $('#brand').val(product.brand || '');
                $('#type').val(product.type || '');
                $('#productDescription').val(product.description || ''); // Correct ID
                $('#material').val(product.material || '');
                $('#features').val(product.features || '');
                $('#tags').val(product.tags || '');
                $('#color').val(product.color || '');
                $('#price').val(product.price || '');
                
                // Set category if available
                if (product.category_id) {
                    $('#categoryId').val(product.category_id); // Correct ID
                }
                
                // For SKU and product code - show existing but don't allow edit
                $('#baseSku').val(product.sku || '').prop('readonly', true); // Correct ID
                
                console.log('✅ Form populated with existing data - SKU:', product.sku);
                console.log('📄 Current form values after populate:', {
                    name: $('#productName').val(),
                    brand: $('#brand').val(),
                    sku: $('#baseSku').val(),
                    color: $('#color').val(),
                    description: $('#productDescription').val(),
                    category: $('#categoryId').val()
                });
                
                // Add note about SKU
                if (!$('#skuNote').length) {
                    $('#baseSku').after('<small id="skuNote" class="form-text text-muted"><i class="fas fa-lock"></i> SKU hiện tại được giữ nguyên. Size mới sẽ tạo SKU mới.</small>');
                }
                
                // Load existing sizes
                if (product.sizes && product.sizes.length > 0) {
                    loadExistingSizes(product.sizes);
                }
            }
            
            // Function to populate form with mixed data (AI + existing)
            function populateFormWithMixedData(existingProduct, aiData) {
                console.log('🔀 Populating form with mixed data:', { existingProduct, aiData });
                
                // Use AI suggestions for these fields (if available), otherwise use existing
                $('#productName').val(aiData?.suggested_name || existingProduct.name || ''); // Correct ID
                $('#brand').val(aiData?.brand || existingProduct.brand || '');
                $('#type').val(aiData?.type || existingProduct.type || '');
                $('#productDescription').val(aiData?.description || existingProduct.description || ''); // Correct ID
                $('#material').val(aiData?.material || existingProduct.material || '');
                $('#features').val(aiData?.features || existingProduct.features || '');
                
                // For tags, combine AI and existing tags
                let combinedTags = '';
                if (aiData?.tags && Array.isArray(aiData.tags)) {
                    combinedTags = aiData.tags.join(', ');
                } else if (existingProduct.tags) {
                    combinedTags = existingProduct.tags;
                }
                $('#tags').val(combinedTags);
                
                // For color, prefer AI analysis if available
                const aiColors = aiData?.colors;
                if (aiColors && Array.isArray(aiColors) && aiColors.length > 0) {
                    $('#color').val(aiColors.join(', '));
                } else {
                    $('#color').val(existingProduct.color || '');
                }
                
                // Always keep existing price and category
                $('#price').val(existingProduct.price || '');
                if (existingProduct.category_id) {
                    $('#categoryId').val(existingProduct.category_id); // Correct ID
                }
                
                // Keep existing SKU (read-only)
                $('#baseSku').val(existingProduct.sku || '').prop('readonly', true); // Correct ID
                
                // Add note about SKU and AI usage
                if (!$('#skuNote').length) {
                    $('#baseSku').after(`
                        <small id="skuNote" class="form-text text-muted">
                            <i class="fas fa-lock"></i> SKU hiện tại được giữ nguyên. Size mới sẽ tạo SKU mới.<br>
                            <i class="fas fa-robot text-success"></i> Một số thông tin đã được AI cập nhật dựa trên ảnh mới.
                        </small>
                    `);
                }
                
                // Load existing sizes
                if (existingProduct.sizes && existingProduct.sizes.length > 0) {
                    loadExistingSizes(existingProduct.sizes);
                }
            }
            
            // Function to add a size row (for both existing and new)
            function addSizeRow(size = '', minQuantity = '', isExisting = false) {
                const sizeIndex = $('#sizeContainer .size-row').length;
                const newSizeRow = `
                    <div class="size-row mb-2" data-size-index="${sizeIndex}" ${isExisting ? 'data-existing="true"' : ''}>
                        <div class="card ${isExisting ? 'border-left-info' : 'border-left-success'}">
                            <div class="card-body p-3">
                                <div class="row align-items-center">
                                    <div class="col-md-4">
                                        <label class="form-label mb-1"><strong>Size ${isExisting ? '(Hiện có)' : '(Mới)'}</strong></label>
                                        ${isExisting ? `
                                            <select class="form-control size-select" disabled>
                                                <option value="${size}" selected>${size}</option>
                                            </select>
                                            <input type="hidden" name="existing_sizes[]" value="${size}">
                                        ` : `
                                            <select name="sizes[]" class="form-control size-select" required>
                                                <option value="">Chọn size</option>
                                                <option value="35" ${size === '35' ? 'selected' : ''}>35</option>
                                                <option value="36" ${size === '36' ? 'selected' : ''}>36</option>
                                                <option value="37" ${size === '37' ? 'selected' : ''}>37</option>
                                                <option value="38" ${size === '38' ? 'selected' : ''}>38</option>
                                                <option value="39" ${size === '39' ? 'selected' : ''}>39</option>
                                                <option value="40" ${size === '40' ? 'selected' : ''}>40</option>
                                                <option value="41" ${size === '41' ? 'selected' : ''}>41</option>
                                                <option value="42" ${size === '42' ? 'selected' : ''}>42</option>
                                                <option value="43" ${size === '43' ? 'selected' : ''}>43</option>
                                                <option value="44" ${size === '44' ? 'selected' : ''}>44</option>
                                            </select>
                                        `}
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label mb-1"><strong>Ngưỡng tối thiểu</strong></label>
                                        ${isExisting ? `
                                            <input type="number" class="form-control" value="${minQuantity}" readonly>
                                            <input type="hidden" name="existing_min_quantities[]" value="${minQuantity}">
                                            <small class="text-muted">Giá trị hiện tại</small>
                                        ` : `
                                            <input type="number" name="min_quantities[]" class="form-control" 
                                                   placeholder="Số lượng tối thiểu" min="1" value="${minQuantity}" required>
                                        `}
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label mb-1"><strong>Trạng thái</strong></label>
                                        <div class="text-center">
                                            ${isExisting ? 
                                                '<span class="badge badge-info"><i class="fas fa-database"></i> Hiện có</span>' : 
                                                '<span class="badge badge-success"><i class="fas fa-plus"></i> Thêm mới</span>'
                                            }
                                        </div>
                                    </div>
                                    <div class="col-md-1">
                                        ${isExisting ? '' : `
                                            <button type="button" class="btn btn-danger btn-sm remove-size-btn" title="Xóa size này">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        `}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
                
                $('#sizeContainer').append(newSizeRow);
                return sizeIndex;
            }
            
            // Function to load existing sizes
            function loadExistingSizes(existingSizes) {
                console.log('👟 Loading existing sizes:', existingSizes);
                
                // Clear current sizes
                $('#sizeContainer').empty();
                
                // Add existing sizes (read-only)
                existingSizes.forEach(sizeData => {
                    addSizeRow(sizeData.size, sizeData.min_quantity, true);
                });
                
                // Ensure at least one empty row for new sizes
                addSizeRow('', '', false);
                
                updateRemoveButtons();
            }
            
            // Function to update UI based on mode (add new vs update)
            function updateUIForMode() {
                // Luôn hiển thị "Thêm vào phiếu nhập" vì đây là flow tạo phiếu nhập
                // Dù có dùng dữ liệu từ sản phẩm có sẵn hay từ AI, vẫn là THÊM vào phiếu nhập
                $('button[type="submit"]').html('<i class="fas fa-plus-circle mr-2"></i> <span class="d-none d-sm-inline">Thêm vào phiếu nhập</span><span class="d-inline d-sm-none">Thêm</span>');
                
                if (isUpdateMode) {
                    // Update form title/header to indicate using existing product
                    if ($('.card-header h6').length) {
                        $('.card-header h6').html('<i class="fas fa-box-open"></i> Bước 4: Chỉnh sửa thông tin sản phẩm');
                    }
                    
                    // Update note text
                    const noteElement = $('.alert-info:contains("Lưu ý:")');
                    if (noteElement.length) {
                        noteElement.html(`
                            <strong>Lưu ý:</strong> Bạn đang sử dụng thông tin từ sản phẩm có sẵn. 
                            Điều chỉnh số lượng và size cần nhập vào kho.
                        `);
                    }
                }
            }
            
            // Function to check for duplicate products using all images
            function checkForDuplicates(aiData) {
                console.log('🔍 [STOCK_RECEIPT] Checking duplicates with multi-image AI data:', aiData);
                console.log('🔍 [STOCK_RECEIPT] Current warehouse context from session');
                
                // Show loading indicator inline (giống add_product_ai.php)
                $('#aiDuplicateCheckResult').html('<div class="alert alert-info"><i class="fas fa-spinner fa-spin"></i> Đang kiểm tra trùng lặp sản phẩm...</div>').show();
                
                // ===== SỬ DỤNG TRỰC TIẾP DỮ LIỆU TỪ aiData (ĐÃ ĐƯỢC CHUẨN HÓA) =====
                // Ưu tiên dữ liệu từ aiData thay vì lấy từ DOM (giống add_product_ai.php)
                const productType = aiData.type || aiData.category || '';
                const brand = aiData.brand || '';
                const productName = aiData.name || '';
                // Xử lý colors - có thể là array hoặc string
                const colors = Array.isArray(aiData.colors) ? aiData.colors.join(', ') : (aiData.colors || aiData.color || '');
                
                console.log('📋 [STOCK_RECEIPT] Using AI data directly (after standardization):');
                console.log('  🏷️ Tên sản phẩm:', productName);
                console.log('  🏢 Thương hiệu:', brand);
                console.log('  📦 Loại sản phẩm:', productType);
                console.log('  🎨 Màu sắc:', colors);
                
                // Validate data
                if (!productName || !brand) {
                    console.warn('⚠️ Missing required data for duplicate check');
                    $('#aiDuplicateCheckResult').html(`
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i> Thiếu thông tin để kiểm tra trùng lặp (cần tên sản phẩm và thương hiệu)
                        </div>
                    `).show();
                    return;
                }
                
                // Normalize brand
                const normalizedBrand = brand === 'Không xác định' ? 'Unknown' : brand;
                
                console.log('📤 [STOCK_RECEIPT] Data being sent to API:');
                console.log('  product_name:', productName);
                console.log('  brand:', normalizedBrand);
                console.log('  type:', productType);
                console.log('  color:', colors);
                
                // ===== GỬI REQUEST GIỐNG HỆT ADD_PRODUCT_AI.PHP =====
                // Sử dụng cùng API: api_check_duplicates_manual.php
                // Cùng action: check_manual_duplicates
                // Cùng tham số: product_name, brand, type, color
                $.ajax({
                    url: 'api_check_duplicates_manual.php',
                    method: 'POST',
                    data: {
                        action: 'check_manual_duplicates',
                        product_name: productName,
                        brand: normalizedBrand,
                        type: productType,
                        color: colors
                    },
                    dataType: 'json',
                    success: function(response) {
                        console.log('🔍 [STOCK_RECEIPT] Duplicate check response (from manual API):', response);
                        
                        // Hiển thị kết quả GIỐNG HỆT add_product_ai.php
                        displayStockReceiptDuplicateResult(response, aiData);
                    },
                    error: function(xhr, status, error) {
                        console.log('❌ [STOCK_RECEIPT] Lỗi khi kiểm tra trùng lặp:', error);
                        console.log('[STOCK_RECEIPT] Status:', status);
                        console.log('[STOCK_RECEIPT] Response:', xhr.responseText);
                        
                        $('#aiDuplicateCheckResult').html(`
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle"></i> 
                                Có lỗi khi kiểm tra trùng lặp: ${error}
                            </div>
                        `).show();
                    }
                });
            }
            
            // Function to show duplicate check modal
            function showDuplicateModal(aiData, duplicates) {
                $('#duplicateCount').text(duplicates.length);
                
                // Hiển thị thông tin sản phẩm mới với tất cả ảnh uploaded
                let imagesHtml = '';
                uploadedImages.forEach((img, index) => {
                    imagesHtml += `
                        <img src="${img.url || img.path}" 
                             class="img-fluid mb-2 mr-2" 
                             style="max-height: 120px; max-width: 120px; border-radius: 6px; border: 2px solid ${index === primaryImageIndex ? '#007bff' : '#ddd'};"
                             title="${index === primaryImageIndex ? 'Ảnh chính' : 'Ảnh phụ'}">
                    `;
                });
                
                const newProductHtml = `
                    <div class="text-center">
                        <div class="mb-3">
                            ${imagesHtml}
                        </div>
                        <h6 class="text-primary">${aiData.name || 'Tên sản phẩm từ AI'}</h6>
                        <p class="small mb-1"><strong>Thương hiệu:</strong> ${aiData.brand || 'Chưa xác định'}</p>
                        <p class="small mb-1"><strong>Loại:</strong> ${aiData.type || 'Chưa xác định'}</p>
                        <p class="small mb-1"><strong>Màu sắc:</strong> ${Array.isArray(aiData.colors) ? aiData.colors.join(', ') : (aiData.colors || 'Chưa xác định')}</p>
                        <p class="small mb-1"><strong>Chất liệu:</strong> ${aiData.material || 'Chưa xác định'}</p>
                        ${aiData.description ? `<p class="small mb-1"><strong>Mô tả:</strong> <span class="text-muted">${aiData.description.substring(0, 100)}${aiData.description.length > 100 ? '...' : ''}</span></p>` : ''}
                        <p class="small mb-0 text-info"><i class="fas fa-images"></i> Đã phân tích ${uploadedImages.length} ảnh</p>
                    </div>
                `;
                $('#newProductPreview').html(newProductHtml);
                
                // Hiển thị danh sách sản phẩm trùng lặp
                console.log('📋 Displaying', duplicates.length, 'duplicate products');
                let duplicatesHtml = '';
                duplicates.forEach((product, index) => {
                    console.log('🔍 Product', product.product_id, '- Status:', product.status, '- Name:', product.name.substring(0, 30));
                    const isInactive = product.status === 'inactive';
                    const cardClass = isInactive ? 'border-warning' : '';
                    const statusBadge = isInactive ? '<span class="badge badge-warning ml-2" style="font-size: 0.85rem;"><i class="fas fa-pause-circle"></i> Tạm ngừng</span>' : '';
                    const cardStyle = isInactive ? 'cursor: not-allowed; opacity: 0.7; background-color: #fff3cd;' : 'cursor: pointer;';
                    if (isInactive) {
                        console.log('⚠️ Product', product.product_id, 'is INACTIVE - disabled for selection');
                    }
                    
                    duplicatesHtml += `
                        <div class="card mb-3 duplicate-product-card ${cardClass}" 
                             data-product-id="${product.product_id}"
                             data-product-status="${product.status || 'active'}"
                             style="${cardStyle}">
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-4">
                                        ${product.image_path ? 
                                            `<img src="${product.image_path}" class="img-fluid" style="max-height: 100px; border-radius: 4px; ${isInactive ? 'opacity: 0.6;' : ''}">` :
                                            '<div class="bg-light d-flex align-items-center justify-content-center" style="height: 100px; border-radius: 4px;"><i class="fas fa-image text-muted"></i></div>'
                                        }
                                    </div>
                                    <div class="col-md-8">
                                        <h6 class="card-title mb-1">
                                            ${product.name}
                                            ${statusBadge}
                                        </h6>
                                        <p class="small mb-1"><strong>Thương hiệu:</strong> ${product.brand || 'Chưa xác định'}</p>
                                        <p class="small mb-1"><strong>SKU:</strong> ${product.sku || 'Chưa có'}</p>
                                        <p class="small mb-1"><strong>Màu sắc:</strong> ${product.color || 'Chưa xác định'}</p>
                                        <p class="small mb-1"><strong>Size:</strong> ${product.size || 'Chưa xác định'}</p>
                                        ${product.description ? `<p class="small mb-1"><strong>Mô tả:</strong> <span class="text-muted">${product.description.substring(0, 80)}${product.description.length > 80 ? '...' : ''}</span></p>` : ''}
                                        <p class="small mb-2"><strong>Giá:</strong> ${product.price ? product.price.toLocaleString() + ' VNĐ' : 'Chưa có giá'}</p>
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div>
                                                <span class="badge badge-warning">
                                                    <i class="fas fa-percentage"></i> Độ tương đồng: ${product.similarity_percentage}%
                                                </span>
                                                ${isInactive ? `
                                                <button class="btn btn-sm btn-success ml-2 btn-reactivate-product" 
                                                        data-product-id="${product.product_id}" 
                                                        data-product-name="${product.name.replace(/'/g, "\\'")}"
                                                        type="button">
                                                    <i class="fas fa-play"></i> Kích hoạt lại
                                                </button>
                                                ` : ''}
                                            </div>
                                        </div>
                                        ${isInactive ? `
                                        <div class="alert alert-warning mb-0 mt-2 py-2">
                                            <small><i class="fas fa-lock"></i> Sản phẩm đã tạm ngừng - Không thể tạo phiếu nhập. Vui lòng kích hoạt lại trước.</small>
                                        </div>
                                        ` : ''}
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                });
                $('#duplicateProductsList').html(duplicatesHtml);
                
                // Hiển thị modal
                $('#duplicateCheckModal').modal({
                    backdrop: 'static',
                    keyboard: false
                });
            }

            // ===== NEW INLINE DUPLICATE DISPLAY FUNCTIONS =====
            
            // Function to display "no duplicates" message inline
            function displayStockReceiptNoDuplicates() {
                const html = `
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <strong>Tuyệt vời!</strong> Không tìm thấy sản phẩm trùng lặp trong kho.
                    </div>
                `;
                $('#stockReceiptDuplicateResults').html(html).slideDown();
                
                // Auto hide after 3 seconds
                setTimeout(() => {
                    $('#stockReceiptDuplicateResults').slideUp();
                }, 3000);
            }
            
            // Function to display duplicate results inline (similar to add_product_ai.php)
            function displayStockReceiptDuplicateResults(aiData, duplicates, response = null) {
                console.log('🎯 Displaying inline duplicate results for stock receipt');
                
                // 🔥 IMPORTANT: Save AI data to window for later use
                window.aiData = aiData;
                console.log('💾 Saved AI data to window.aiData:', window.aiData);
                
                // Use response data if available, otherwise calculate
                let maxSimilarity = response ? response.max_similarity : 0;
                let warningLevel = response ? response.warning_level : 'low';
                
                if (!response) {
                    // Fallback calculation if no response data
                    duplicates.forEach(product => {
                        if (product.similarity > maxSimilarity) {
                            maxSimilarity = product.similarity;
                        }
                    });
                    
                    if (maxSimilarity >= 85) {
                        warningLevel = 'high';
                    } else if (maxSimilarity >= 70) {
                        warningLevel = 'medium';
                    }
                }
                
                // Determine alert styling based on warning level
                let alertClass = '';
                let icon = '';
                let message = '';
                
                if (warningLevel === 'high') {
                    alertClass = 'alert-danger';
                    icon = 'fas fa-exclamation-triangle';
                    message = 'Cảnh báo: Phát hiện sản phẩm rất tương tự!';
                } else if (warningLevel === 'medium') {
                    alertClass = 'alert-warning';
                    icon = 'fas fa-exclamation-circle';
                    message = 'Cảnh báo: Phát hiện sản phẩm tương tự.';
                } else {
                    alertClass = 'alert-info';
                    icon = 'fas fa-info-circle';
                    message = 'Thông báo: Có một số sản phẩm tương tự.';
                }
                
                let html = `
                    <div class="alert ${alertClass}">
                        <h6><i class="${icon}"></i> ${message}</h6>
                        <p class="mb-3">Tìm thấy <strong>${duplicates.length}</strong> sản phẩm tương tự với độ tương đồng cao nhất: <strong>${maxSimilarity}%</strong></p>
                        
                        <div class="similar-products-list">
                `;
                
                duplicates.forEach(function(product) {
                    const badgeClass = product.similarity >= 85 ? 'badge-danger' : 
                                     product.similarity >= 70 ? 'badge-warning' : 'badge-info';
                    
                    const isInactive = product.status === 'inactive';
                    const cardBorderClass = isInactive ? 'border-warning' : '';
                    const cardStyle = isInactive ? 'cursor: not-allowed; opacity: 0.7; background-color: #fff3cd;' : 'cursor: pointer;';
                    const clickHandler = isInactive ? 'onclick="showInactiveWarning()"' : 'onclick="selectStockReceiptDuplicateForUpdate(this)"';
                    const statusBadge = isInactive ? '<span class="badge badge-warning ml-2" style="font-size: 0.85rem;"><i class="fas fa-pause-circle"></i> Tạm ngừng</span>' : '';
                    
                    html += `
                        <div class="similar-product-item stock-receipt-duplicate-card border rounded p-3 mb-2 ${cardBorderClass}" 
                             data-product-id="${product.product_id}"
                             data-product-name="${product.name}"
                             data-product-brand="${product.brand}"
                             data-product-status="${product.status || 'active'}"
                             style="${cardStyle} transition: all 0.3s;"
                             ${clickHandler}>
                            <div class="row align-items-center">
                                <div class="col-md-2">
                                    ${product.image_url ? 
                                        `<img src="${product.image_url}" class="img-thumbnail" style="max-width: 80px;">` :
                                        `<div class="bg-light border rounded d-flex align-items-center justify-content-center" style="width: 80px; height: 80px;">
                                            <i class="fas fa-image text-muted"></i>
                                        </div>`
                                    }
                                </div>
                                <div class="col-md-7">
                                    <h6 class="mb-1">
                                        ${product.name}
                                        ${statusBadge}
                                    </h6>
                                    <p class="text-muted mb-1">${product.brand} • ${product.type || ''} • ${product.color || ''}</p>
                                    <small class="text-muted">ID: ${product.product_id} • Tạo: ${product.created_at}</small>
                                </div>
                                <div class="col-md-3 text-right">
                                    <div class="d-flex flex-column align-items-end">
                                        <span class="badge ${badgeClass} badge-lg mb-2">
                                            ${product.similarity}% tương đồng
                                        </span>
                                        ${isInactive ? `
                                        <button class="btn btn-success btn-sm mb-2 px-3 font-weight-bold btn-reactivate-product" 
                                                data-product-id="${product.product_id}" 
                                                data-product-name="${product.name.replace(/'/g, "\\'")}"
                                                type="button"
                                                style="min-width: 140px;">
                                            <i class="fas fa-play mr-1"></i> Kích hoạt lại
                                        </button>
                                        ` : `
                                        <button class="btn btn-success btn-sm mb-2 px-3 font-weight-bold" onclick="event.stopPropagation(); console.log('🔧 User clicked TIEP TUC NHAP KHO button for product:', ${product.product_id}); selectProductForStockReceipt(${product.product_id}, \`${product.name.replace(/`/g, '\\`')}\`)" title="Tiếp tục nhập kho với sản phẩm này" style="min-width: 140px;">
                                            <i class="fas fa-warehouse mr-1"></i> Tiếp tục nhập kho
                                        </button>
                                        `}
                                        <button class="btn btn-outline-secondary btn-sm" onclick="event.stopPropagation(); viewStockReceiptProductDetails(${product.product_id})" style="min-width: 140px;">
                                            <i class="fas fa-eye mr-1"></i> Xem chi tiết
                                        </button>
                                    </div>
                                </div>
                            </div>
                            ${isInactive ? `
                            <div class="alert alert-warning mb-0 mt-2 py-2">
                                <small><i class="fas fa-lock"></i> Sản phẩm đã tạm ngừng - Không thể tạo phiếu nhập. Vui lòng kích hoạt lại trước.</small>
                            </div>
                            ` : ''}
                            <div class="selected-indicator" style="display: none;">
                                <i class="fas fa-check-circle text-success"></i> Đã chọn để cập nhật
                            </div>
                        </div>
                    `;
                });
                
                html += `
                        </div>
                        <div class="mt-3 mb-2">
                            <div class="alert alert-info">
                                <h6><i class="fas fa-info-circle"></i> Hướng dẫn nhập kho:</h6>
                                <ul class="mb-0">
                                    <li><strong>Nhập kho sản phẩm có sẵn:</strong> Click nút <span class="badge badge-success">Tiếp tục nhập kho</span> bên trong card sản phẩm để thêm số lượng vào sản phẩm đã tồn tại</li>
                                    <li><strong>Tạo sản phẩm mới:</strong> Nếu không có sản phẩm phù hợp, bấm nút <span class="badge badge-success">Tạo sản phẩm mới</span> để tạo sản phẩm mới</li>
                                </ul>
                            </div>
                        </div>
                        <div class="mt-3">
                            <button class="btn btn-success btn-lg mr-2" onclick="console.log('🔧 User clicked CREATE NEW PRODUCT button'); continueWithNewStockReceiptProduct(null, 'create_new')" style="min-width: 180px;">
                                <i class="fas fa-plus mr-1"></i> Tạo sản phẩm mới
                            </button>
                            <button class="btn btn-secondary" onclick="$('#stockReceiptDuplicateResults').slideUp(); moveToStep(2)">
                                <i class="fas fa-arrow-left"></i> Quay lại nhập liệu
                            </button>
                        </div>
                    </div>
                `;
                
                $('#stockReceiptDuplicateResults').html(html).slideDown();
            }
            
            // ===== MAIN DUPLICATE RESULT HANDLER (FROM MANUAL API) =====
            // Copy 100% từ add_product_ai.php với thay đổi nhỏ cho stock receipt context
            
            // Function to handle duplicate result from manual API (similar to add_product_ai.php)
            function displayStockReceiptDuplicateResult(response, aiData) {
                if (!response.success) {
                    $('#aiDuplicateCheckResult').html(`
                        <div class="alert alert-danger">
                            <i class="fas fa-times-circle"></i>
                            <strong>Lỗi:</strong> ${response.message || 'Không thể kiểm tra trùng lặp'}
                        </div>
                    `).show();
                    return;
                }
                
                let html = '';
                
                if (!response.has_duplicates) {
                    html = `
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i>
                            <strong>Tuyệt vời!</strong> Không tìm thấy sản phẩm trùng lặp trong kho.
                        </div>
                    `;
                } else {
                    // Xác định mức cảnh báo GIỐNG HỆT add_product_ai.php
                    let alertClass = '';
                    let icon = '';
                    let message = '';
                    
                    if (response.warning_level === 'high') {
                        alertClass = 'alert-danger';
                        icon = 'fas fa-exclamation-triangle';
                        message = 'Cảnh báo: Phát hiện sản phẩm rất tương tự!';
                    } else if (response.warning_level === 'medium') {
                        alertClass = 'alert-warning';
                        icon = 'fas fa-exclamation-circle';
                        message = 'Cảnh báo: Phát hiện sản phẩm tương tự.';
                    } else {
                        alertClass = 'alert-info';
                        icon = 'fas fa-info-circle';
                        message = 'Thông báo: Có một số sản phẩm tương tự.';
                    }
                    
                    html = `
                        <div class="alert ${alertClass}">
                            <h6><i class="${icon}"></i> ${message}</h6>
                            <p class="mb-3">Tìm thấy <strong>${response.duplicates.length}</strong> sản phẩm tương tự với độ tương đồng cao nhất: <strong>${response.max_similarity}%</strong></p>
                            
                            <div class="similar-products-list">
                    `;
                    
                    response.duplicates.forEach(function(product) {
                        const badgeClass = product.similarity >= 85 ? 'badge-danger' : 
                                         product.similarity >= 70 ? 'badge-warning' : 'badge-info';
                        
                        const isInactive = product.status === 'inactive';
                        const cardBorderClass = isInactive ? 'border-warning' : '';
                        const cardStyle = isInactive ? 'cursor: not-allowed; opacity: 0.7; background-color: #fff3cd;' : 'cursor: pointer;';
                        const statusBadge = isInactive ? '<span class="badge badge-warning ml-2" style="font-size: 0.85rem;"><i class="fas fa-pause-circle"></i> Tạm ngừng</span>' : '';
                        
                        html += `
                            <div class="similar-product-item stock-receipt-duplicate-card border rounded p-3 mb-2 ${cardBorderClass}" 
                                 data-product-id="${product.product_id}"
                                 data-product-name="${product.name}"
                                 data-product-brand="${product.brand}"
                                 data-product-status="${product.status || 'active'}"
                                 style="${cardStyle} transition: all 0.3s;">
                                <div class="row align-items-center">
                                    <div class="col-md-2">
                                        ${product.image_url ? 
                                            `<img src="${product.image_url}" class="img-thumbnail" style="max-width: 80px;">` :
                                            `<div class="bg-light border rounded d-flex align-items-center justify-content-center" style="width: 80px; height: 80px;">
                                                <i class="fas fa-image text-muted"></i>
                                            </div>`
                                        }
                                    </div>
                                    <div class="col-md-7">
                                        <h6 class="mb-1">
                                            ${product.name}
                                            ${statusBadge}
                                        </h6>
                                        <p class="text-muted mb-1">${product.brand} • ${product.type || ''} • ${product.color || ''}</p>
                                        <small class="text-muted">ID: ${product.product_id} • Tạo: ${product.created_at}</small>
                                    </div>
                                    <div class="col-md-3 text-right">
                                        <div class="d-flex flex-column align-items-end">
                                            <span class="badge ${badgeClass} badge-lg mb-2">
                                                ${product.similarity}% tương đồng
                                            </span>
                                            ${isInactive ? `
                                            <button class="btn btn-success btn-sm mb-2 px-3 font-weight-bold btn-reactivate-product" 
                                                    data-product-id="${product.product_id}" 
                                                    data-product-name="${product.name.replace(/'/g, "\\'")}"
                                                    type="button">
                                                <i class="fas fa-play mr-1"></i> Kích hoạt lại
                                            </button>
                                            ` : `
                                            <button class="btn btn-success btn-sm mb-2 px-3 font-weight-bold" onclick="event.stopPropagation(); selectProductForStockReceipt(${product.product_id}, '${product.name.replace(/'/g, "\\'")}')">
                                                <i class="fas fa-warehouse mr-1"></i> Tiếp tục nhập kho
                                            </button>
                                            `}
                                            <button class="btn btn-outline-secondary btn-sm" onclick="event.stopPropagation(); viewProductDetails(${product.product_id})">
                                                <i class="fas fa-eye mr-1"></i> Xem chi tiết
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                ${isInactive ? `
                                <div class="alert alert-warning mb-0 mt-2 py-2">
                                    <small><i class="fas fa-lock"></i> Sản phẩm đã tạm ngừng - Không thể tạo phiếu nhập. Vui lòng kích hoạt lại trước.</small>
                                </div>
                                ` : ''}
                            </div>
                        `;
                    });
                    
                    html += `
                            </div>
                            <div class="mt-3 mb-2">
                                <div class="alert alert-info">
                                    <h6><i class="fas fa-info-circle"></i> Hướng dẫn:</h6>
                                    <ul class="mb-0">
                                        <li><strong>Nhập kho sản phẩm có sẵn:</strong> Click nút <span class="badge badge-success">Tiếp tục nhập kho</span> để thêm số lượng vào sản phẩm đã tồn tại</li>
                                        <li><strong>Tạo sản phẩm mới:</strong> Nếu đây thực sự là sản phẩm mới khác, bấm nút <span class="badge badge-success">Tạo sản phẩm mới</span> bên dưới</li>
                                    </ul>
                                </div>
                            </div>
                            <div class="mt-3">
                                <button class="btn btn-success btn-lg mr-2" onclick="continueCreateNewProduct()">
                                    <i class="fas fa-plus mr-1"></i> Tạo sản phẩm mới
                                </button>
                                <button class="btn btn-secondary" onclick="$('#aiDuplicateCheckResult').slideUp(); moveToStep(2)">
                                    <i class="fas fa-arrow-left"></i> Quay lại
                                </button>
                            </div>
                        </div>
                    `;
                }
                
                $('#aiDuplicateCheckResult').html(html).slideDown();
            }
            
            // ===== HELPER FUNCTIONS FOR DUPLICATE SELECTION =====
            // Note: Main selection functions (selectStockReceiptDuplicateForUpdate, 
            // selectProductForStockReceipt, etc.) are defined globally at the top of the script
            
            // Function to update selected duplicate product
            function updateSelectedStockReceiptDuplicate() {
                if (!window.selectedStockReceiptDuplicate) {
                    alert('Vui lòng chọn sản phẩm để cập nhật');
                    return;
                }
                
                const productId = window.selectedStockReceiptDuplicate.product_id;
                console.log('📝 Updating stock receipt duplicate product:', productId);
                
                // Hide duplicate results
                $('#stockReceiptDuplicateResults').slideUp();
                
                // Load product data and move to edit step
                loadStockReceiptProductDataForUpdate(productId);
            }
            
            // Function to load product data for update
            function loadStockReceiptProductDataForUpdate(productId) {
                console.log('📊 Loading product data for stock receipt update:', productId);
                
                // Show loading
                const loadingHtml = `
                    <div class="alert alert-info" id="loadingProductUpdate">
                        <i class="fas fa-spinner fa-spin"></i> Đang tải thông tin sản phẩm...
                    </div>
                `;
                $('#step4-content .card-body').prepend(loadingHtml);
                
                // Load product details via API
                $.ajax({
                    url: 'api_get_product_details.php',
                    method: 'POST',
                    data: { product_id: productId },
                    dataType: 'json',
                    success: function(response) {
                        $('#loadingProductUpdate').remove();
                        
                        if (response.success) {
                            console.log('✅ Product data loaded for stock receipt:', response);
                            fillStockReceiptFormWithProductData(response.data);
                            moveToStep(4);
                        } else {
                            console.error('❌ Failed to load product data:', response);
                            alert('Không thể tải dữ liệu sản phẩm: ' + (response.message || 'Unknown error'));
                        }
                    },
                    error: function(xhr, status, error) {
                        $('#loadingProductUpdate').remove();
                        console.error('❌ AJAX error loading product:', error);
                        alert('Lỗi khi tải dữ liệu sản phẩm: ' + error);
                    }
                });
            }
            
            // Function to fill form with product data for stock receipt
            function fillStockReceiptFormWithProductData(productData) {
                console.log('📝 Filling stock receipt form with product data:', productData);
                
                // Fill basic info
                $('#productName').val(productData.name);
                $('#brandName').val(productData.brand);
                $('#productType').val(productData.type || '');
                $('#productTypeText').val(productData.type || '');
                $('#productDescription').val(productData.description);
                $('#productPrice').val(productData.price || '');
                $('#minQuantity').val(productData.min_quantity || '');
                
                // Fill other fields if available
                if (productData.material) $('#material').val(productData.material);
                if (productData.features) $('#features').val(productData.features);
                if (productData.tags) $('#tags').val(productData.tags);
                if (productData.color) $('#color').val(productData.color);
                if (productData.category_id) $('#category').val(productData.category_id);
                
                // Display existing variants if any
                if (productData.variants && productData.variants.length > 0) {
                    displayExistingStockReceiptVariants(productData.variants);
                }
                
                // Show success message
                const successMsg = `
                    <div class="alert alert-success" id="productLoadedSuccess">
                        <i class="fas fa-check-circle"></i>
                        <strong>Đã tải thông tin sản phẩm:</strong> ${productData.name}
                        <button type="button" class="close" onclick="$('#productLoadedSuccess').fadeOut()">
                            <span>&times;</span>
                        </button>
                    </div>
                `;
                $('#step4-content .card-body').prepend(successMsg);
                
                // Auto hide success message
                setTimeout(() => {
                    $('#productLoadedSuccess').fadeOut();
                }, 5000);
            }
            
            // Function to display existing variants for stock receipt
            function displayExistingStockReceiptVariants(variants) {
                console.log('👟 Displaying existing variants for stock receipt:', variants);
                // This can be implemented similar to add_product_ai.php if needed
                // For now, just log the variants
            }
            
            // ===== FUNCTION FOR SELECTING EXISTING PRODUCT FOR STOCK RECEIPT =====
            // Note: selectProductForStockReceipt is defined globally at the top of the script
            
            // Function to load product data and proceed to stock receipt entry
            function loadProductForStockReceiptEntry(productId, productName) {
                console.log('📦 Loading product for stock receipt entry:', productId);
                
                // First, move to step 4 to show the loading state
                moveToStep(4);
                
                // Show loading message in step 4
                const loadingHtml = `
                    <div class="alert alert-info text-center" id="loadingProductForReceipt">
                        <div class="mb-3">
                            <i class="fas fa-spinner fa-spin fa-2x text-primary"></i>
                        </div>
                        <h5>Đang tải thông tin sản phẩm</h5>
                        <p class="mb-0">Chuẩn bị form nhập kho cho: <strong>${productName}</strong></p>
                    </div>
                `;
                $('#step4-content .card-body').html(loadingHtml);
                
                // Load product details
                $.ajax({
                    url: 'api_get_product_details.php',
                    method: 'POST',
                    data: { product_id: productId },
                    dataType: 'json',
                    success: function(response) {
                        $('#loadingProductForReceipt').remove();
                        
                        if (response.success) {
                            console.log('✅ Product loaded for stock receipt:', response);
                            
                            // Store selected product data globally
                            window.selectedProductForReceipt = {
                                product_id: productId,
                                product_data: response.data
                            };
                            
                            // Mark this as stock receipt mode
                            window.stockReceiptMode = true;
                            
                            // Display stock receipt form for this product
                            displayStockReceiptForm(response.data);
                            
                            // Update step indicator to show we're in step 4 (edit/input)
                            $('.step').removeClass('active completed');
                            $('#step1, #step2, #step3').addClass('completed');
                            $('#step4').addClass('active');
                            
                            // Smooth scroll to top of step 4
                            $('html, body').animate({
                                scrollTop: $('#step4-content').offset().top - 100
                            }, 500);
                            
                        } else {
                            console.error('❌ Failed to load product:', response);
                            const errorHtml = `
                                <div class="alert alert-danger text-center">
                                    <i class="fas fa-exclamation-triangle fa-2x mb-2"></i>
                                    <h5>Không thể tải dữ liệu sản phẩm</h5>
                                    <p>${response.message || 'Lỗi không xác định'}</p>
                                    <button class="btn btn-primary" onclick="$('#stockReceiptDuplicateResults').slideDown(); $('#step4-content').hide(); $('#step3-content').show();">
                                        <i class="fas fa-arrow-left mr-2"></i>Quay lại
                                    </button>
                                </div>
                            `;
                            $('#step4-content .card-body').html(errorHtml);
                        }
                    },
                    error: function(xhr, status, error) {
                        $('#loadingProductForReceipt').remove();
                        console.error('❌ AJAX error loading product:', error);
                        alert('Lỗi khi tải dữ liệu sản phẩm: ' + error);
                    }
                });
            }
            
            // Function to display stock receipt form for selected product
            function displayStockReceiptForm(productData) {
                console.log('📋 Displaying stock receipt form for product:', productData);
                
                // Clear any existing content in step 4
                $('#step4-content .card-body').empty();
                
                // Show success message about selected product
                const successMsg = `
                    <div class="alert alert-success border-0 shadow-sm" id="productSelectedSuccess">
                        <div class="d-flex align-items-center">
                            <div class="mr-3">
                                <i class="fas fa-check-circle fa-2x text-success"></i>
                            </div>
                            <div class="flex-grow-1">
                                <h5 class="mb-1 text-success">✅ Đã chọn sản phẩm để nhập kho</h5>
                                <div class="row">
                                    <div class="col-md-6">
                                        <p class="mb-1"><strong>Tên sản phẩm:</strong> ${productData.name}</p>
                                        <p class="mb-1"><strong>Thương hiệu:</strong> ${productData.brand || 'N/A'}</p>
                                    </div>
                                    <div class="col-md-6">
                                        <p class="mb-1"><strong>ID:</strong> ${productData.product_id}</p>
                                        <p class="mb-1"><strong>Loại:</strong> ${productData.type || 'N/A'}</p>
                                    </div>
                                </div>
                            </div>
                            <button type="button" class="close" onclick="$('#productSelectedSuccess').fadeOut()">
                                <span>&times;</span>
                            </button>
                        </div>
                    </div>
                `;
                $('#step4-content .card-body').append(successMsg);
                
                // Create product info display (readonly)
                const productInfoHtml = `
                    <div class="card shadow mb-4" id="selectedProductInfo">
                        <div class="card-header bg-gradient-info text-white">
                            <h6 class="m-0 font-weight-bold">
                                <i class="fas fa-info-circle mr-2"></i>Thông tin sản phẩm đã chọn
                            </h6>
                        </div>
                        <div class="card-body bg-light">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="small font-weight-bold text-muted">Tên sản phẩm:</label>
                                        <input type="text" class="form-control form-control-sm" value="${productData.name}" readonly>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="small font-weight-bold text-muted">Thương hiệu:</label>
                                        <input type="text" class="form-control form-control-sm" value="${productData.brand || 'N/A'}" readonly>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="small font-weight-bold text-muted">Loại sản phẩm:</label>
                                        <input type="text" class="form-control form-control-sm" value="${productData.type || 'N/A'}" readonly>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="small font-weight-bold text-muted">Màu sắc:</label>
                                        <input type="text" class="form-control form-control-sm" value="${productData.color || 'N/A'}" readonly>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div class="form-group">
                                        <label class="small font-weight-bold text-muted">Mô tả:</label>
                                        <textarea class="form-control form-control-sm" rows="2" readonly>${productData.description || 'Không có mô tả'}</textarea>
                                    </div>
                                </div>
                            </div>
                            <div class="alert alert-info border-0 mb-0">
                                <i class="fas fa-lock mr-2"></i>
                                <strong>Lưu ý:</strong> Thông tin sản phẩm đã được khóa vì đây là sản phẩm có sẵn. 
                                Bạn chỉ cần nhập số lượng và giá nhập cho từng size/variant.
                            </div>
                        </div>
                    </div>
                `;
                $('#step4-content .card-body').append(productInfoHtml);
                
                // Show existing variants for quantity selection
                if (productData.variants && productData.variants.length > 0) {
                    displayVariantsForStockReceipt(productData.variants);
                } else {
                    // No variants, show simple quantity input
                    displaySimpleQuantityInput(productData);
                }
                
                // Add submit button for stock receipt
                const submitButtonHtml = `
                    <div class="card shadow mt-4" id="stockReceiptSubmitCard">
                        <div class="card-header bg-gradient-success text-white">
                            <h6 class="m-0 font-weight-bold">
                                <i class="fas fa-check mr-2"></i>Hoàn tất nhập kho
                            </h6>
                        </div>
                        <div class="card-body">
                            <div class="text-center mb-3">
                                <p class="text-muted mb-3">
                                    <i class="fas fa-info-circle mr-2"></i>
                                    Vui lòng kiểm tra lại thông tin và số lượng trước khi hoàn tất nhập kho.
                                </p>
                            </div>
                            <div class="d-flex justify-content-center">
                                <button type="button" class="btn btn-success btn-lg mr-3 px-4" onclick="submitStockReceiptForExistingProduct()" id="submitStockReceiptBtn">
                                    <i class="fas fa-warehouse mr-2"></i>Hoàn tất nhập kho
                                </button>
                                <button type="button" class="btn btn-outline-secondary btn-lg px-4" onclick="cancelStockReceiptProcess()">
                                    <i class="fas fa-times mr-2"></i>Hủy bỏ
                                </button>
                            </div>
                            <p class="text-muted mt-3 mb-0">
                                <i class="fas fa-info-circle"></i>
                                Nhấn "Hoàn tất nhập kho" để lưu số lượng vào kho và tạo phiếu nhập
                            </p>
                        </div>
                    </div>
                `;
                
                // Insert after the last element in the form area
                $('#step4-content .card-body').append(submitButtonHtml);
                
                // Auto hide success message
                setTimeout(() => {
                    $('#productSelectedSuccess').fadeOut();
                }, 5000);
            }
            
            // Function to display variants for stock receipt quantity input
            function displayVariantsForStockReceipt(variants) {
                console.log('👟 Displaying variants for stock receipt:', variants);
                
                // Create variants section
                const variantsHtml = `
                    <div class="card shadow mb-4" id="stockReceiptVariantsCard">
                        <div class="card-header bg-gradient-primary text-white">
                            <h6 class="m-0 font-weight-bold">
                                <i class="fas fa-boxes mr-2"></i>Nhập số lượng và giá theo Size/Variant
                            </h6>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-info border-0 mb-3">
                                <i class="fas fa-info-circle mr-2"></i> 
                                <strong>Hướng dẫn:</strong> Nhập số lượng và giá nhập cho từng size muốn nhập kho. 
                                Để trống nếu không nhập size đó.
                            </div>
                            <div id="variantsContainer">
                                <!-- Variants will be populated here -->
                            </div>
                        </div>
                    </div>
                `;
                
                // Append to step 4 content
                $('#step4-content .card-body').append(variantsHtml);
                
                // Populate variants
                let variantsListHtml = '';
                variants.forEach((variant, index) => {
                    const currentStock = variant.current_quantity || 0;
                    const currentPrice = variant.price || 0;
                    const badgeColor = currentStock > 0 ? 'success' : 'warning';
                    
                    variantsListHtml += `
                        <div class="variant-row bg-white border rounded shadow-sm p-3 mb-3" data-variant-id="${variant.variant_id}">
                            <div class="row align-items-center">
                                <div class="col-md-3 col-sm-6">
                                    <label class="form-label small font-weight-bold text-primary">Size/Variant:</label>
                                    <div class="d-flex flex-column">
                                        <span class="badge badge-info badge-pill p-2 mb-1" style="font-size: 0.9rem;">
                                            ${variant.size || variant.color || 'Mặc định'}
                                        </span>
                                        <small class="text-muted">SKU: ${variant.sku || 'N/A'}</small>
                                    </div>
                                </div>
                                <div class="col-md-3 col-sm-6">
                                    <label class="form-label small font-weight-bold text-primary">Tồn kho hiện tại:</label>
                                    <div class="d-flex align-items-center">
                                        <span class="badge badge-${badgeColor} badge-pill p-2" style="font-size: 0.9rem;">
                                            ${currentStock} sản phẩm
                                        </span>
                                    </div>
                                </div>
                                <div class="col-md-3 col-sm-6">
                                    <label for="quantity_${variant.variant_id}" class="form-label small font-weight-bold text-primary">
                                        <i class="fas fa-plus-circle mr-1"></i>Số lượng nhập:
                                    </label>
                                    <input type="number" 
                                           class="form-control variant-quantity" 
                                           id="quantity_${variant.variant_id}"
                                           name="receipt_quantities[${variant.variant_id}]"
                                           min="1" 
                                           placeholder="0"
                                           data-variant-id="${variant.variant_id}"
                                           style="border: 2px solid #e3f2fd;">
                                </div>
                                <div class="col-md-3 col-sm-6">
                                    <label for="price_${variant.variant_id}" class="form-label small font-weight-bold text-primary">
                                        <i class="fas fa-dollar-sign mr-1"></i>Giá nhập (VNĐ):
                                    </label>
                                    <input type="number" 
                                           class="form-control variant-price" 
                                           id="price_${variant.variant_id}"
                                           name="receipt_prices[${variant.variant_id}]"
                                           min="1000" 
                                           step="1000"
                                           placeholder="0"
                                           data-variant-id="${variant.variant_id}"
                                           style="border: 2px solid #e8f5e8;">
                                </div>
                            </div>
                            <div class="variant-summary mt-2" id="summary_${variant.variant_id}" style="display: none;">
                                <div class="alert alert-light border-0 mb-0 py-2">
                                    <small>
                                        <i class="fas fa-calculator mr-1"></i>
                                        <strong>Tạm tính:</strong> <span class="text-primary" id="subtotal_${variant.variant_id}">0 VNĐ</span>
                                    </small>
                                </div>
                            </div>
                        </div>
                    `;
                });
                
                $('#variantsContainer').html(variantsListHtml);
                
                // Add summary section
                const summaryHtml = `
                    <div class="card border-success mt-4" id="receiptSummaryCard" style="display: none;">
                        <div class="card-header bg-light border-success">
                            <h6 class="mb-0 text-success font-weight-bold">
                                <i class="fas fa-chart-line mr-2"></i>Tổng quan nhập kho
                            </h6>
                        </div>
                        <div class="card-body" id="receiptSummary">
                            <p class="text-muted text-center">Chưa có sản phẩm nào được chọn</p>
                        </div>
                    </div>
                `;
                $('#variantsContainer').after(summaryHtml);
                
                // Add event listeners for quantity/price changes
                $('.variant-quantity, .variant-price').on('input', function() {
                    const variantId = $(this).data('variant-id');
                    updateVariantSubtotal(variantId);
                    updateVariantSelectionSummary(); // OLD FLOW - for variant selection
                });
            }
            
            // Function to update subtotal for a specific variant
            function updateVariantSubtotal(variantId) {
                const quantity = parseInt($(`#quantity_${variantId}`).val()) || 0;
                const price = parseInt($(`#price_${variantId}`).val()) || 0;
                const subtotal = quantity * price;
                
                if (quantity > 0 && price > 0) {
                    $(`#subtotal_${variantId}`).text(subtotal.toLocaleString('vi-VN') + ' VNĐ');
                    $(`#summary_${variantId}`).slideDown();
                } else {
                    $(`#summary_${variantId}`).slideUp();
                }
            }
            
            // Function to update overall receipt summary (for variant selection - OLD FLOW)
            function updateVariantSelectionSummary() {
                let totalItems = 0;
                let totalValue = 0;
                let selectedVariants = [];
                
                $('.variant-quantity').each(function() {
                    const variantId = $(this).data('variant-id');
                    const quantity = parseInt($(this).val()) || 0;
                    const price = parseInt($(`#price_${variantId}`).val()) || 0;
                    
                    if (quantity > 0 && price > 0) {
                        totalItems += quantity;
                        totalValue += (quantity * price);
                        
                        // Get variant info
                        const variantCard = $(this).closest('.variant-row');
                        const sizeBadge = variantCard.find('.badge-info').text();
                        
                        selectedVariants.push({
                            variantId: variantId,
                            size: sizeBadge,
                            quantity: quantity,
                            price: price,
                            subtotal: quantity * price
                        });
                    }
                });
                
                if (selectedVariants.length > 0) {
                    let summaryHtml = `
                        <div class="row">
                            <div class="col-md-8">
                                <h6 class="text-success mb-2">
                                    <i class="fas fa-check-circle mr-2"></i>Đã chọn ${selectedVariants.length} variant
                                </h6>
                                <div class="table-responsive">
                                    <table class="table table-sm table-borderless mb-0">
                    `;
                    
                    selectedVariants.forEach(variant => {
                        summaryHtml += `
                            <tr>
                                <td><span class="badge badge-info">${variant.size}</span></td>
                                <td class="text-right">${variant.quantity} × ${variant.price.toLocaleString('vi-VN')}đ</td>
                                <td class="text-right font-weight-bold">${variant.subtotal.toLocaleString('vi-VN')}đ</td>
                            </tr>
                        `;
                    });
                    
                    summaryHtml += `
                                    </table>
                                </div>
                            </div>
                            <div class="col-md-4 text-right">
                                <div class="bg-success text-white rounded p-3">
                                    <div class="small">Tổng số lượng:</div>
                                    <div class="h5 mb-1">${totalItems} sản phẩm</div>
                                    <div class="small">Tổng giá trị:</div>
                                    <div class="h4 mb-0">${totalValue.toLocaleString('vi-VN')} VNĐ</div>
                                </div>
                            </div>
                        </div>
                    `;
                    
                    $('#receiptSummary').html(summaryHtml);
                    $('#receiptSummaryCard').slideDown();
                    
                    // Enable submit button
                    $('#submitStockReceiptBtn').prop('disabled', false);
                } else {
                    $('#receiptSummaryCard').slideUp();
                    $('#submitStockReceiptBtn').prop('disabled', true);
                }
            }
            
            // Function to display simple quantity input (when no variants)
            function displaySimpleQuantityInput(productData) {
                console.log('📦 Displaying simple quantity input for:', productData);
                
                const simpleInputHtml = `
                    <div class="card shadow mb-4" id="stockReceiptSimpleCard">
                        <div class="card-header bg-gradient-primary text-white">
                            <h6 class="m-0 font-weight-bold">
                                <i class="fas fa-box mr-2"></i>Nhập số lượng sản phẩm
                            </h6>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="simpleQuantity" class="form-label font-weight-bold">
                                            <i class="fas fa-cubes"></i> Số lượng nhập:
                                        </label>
                                        <input type="number" 
                                               class="form-control form-control-lg" 
                                               id="simpleQuantity"
                                               name="simple_quantity"
                                               min="1" 
                                               placeholder="Nhập số lượng..."
                                               required>
                                        <small class="text-muted">Số lượng sản phẩm cần nhập vào kho</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="simplePrice" class="form-label font-weight-bold">
                                            <i class="fas fa-tag"></i> Giá nhập (VNĐ):
                                        </label>
                                        <input type="number" 
                                               class="form-control form-control-lg" 
                                               id="simplePrice"
                                               name="simple_price"
                                               min="0" 
                                               step="1000"
                                               placeholder="Nhập giá..."
                                               required>
                                        <small class="text-muted">Giá nhập từ nhà cung cấp</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
                
                $('#productForm').after(simpleInputHtml);
            }
            
            // Function to update receipt summary (for simple input - OLD FLOW)
            function updateSimpleReceiptSummary() {
                let totalQuantity = 0;
                let totalValue = 0;
                let variantsWithQuantity = 0;
                let summaryText = '';
                
                $('.variant-quantity').each(function() {
                    const quantity = parseInt($(this).val()) || 0;
                    const variantId = $(this).data('variant-id');
                    const price = parseInt($(`#price_${variantId}`).val()) || 0;
                    
                    if (quantity > 0) {
                        totalQuantity += quantity;
                        totalValue += (quantity * price);
                        variantsWithQuantity++;
                    }
                });
                
                if (variantsWithQuantity > 0) {
                    summaryText = `
                        <div class="row">
                            <div class="col-md-4">
                                <strong>Variants có nhập:</strong> ${variantsWithQuantity}
                            </div>
                            <div class="col-md-4">
                                <strong>Tổng số lượng:</strong> ${totalQuantity} sản phẩm
                            </div>
                            <div class="col-md-4">
                                <strong>Tổng giá trị:</strong> ${totalValue.toLocaleString('vi-VN')} VNĐ
                            </div>
                        </div>
                    `;
                } else {
                    summaryText = '<p class="text-muted">Chưa có variant nào được nhập</p>';
                }
                
                $('#receiptSummary').html(summaryText);
            }
            
            // ===== STOCK RECEIPT SUBMISSION FUNCTIONS =====
            
            // Function to submit stock receipt for existing product
            function submitStockReceiptForExistingProduct() {
                console.log('📋 Submitting stock receipt for existing product');
                
                if (!window.selectedProductForReceipt) {
                    alert('Không tìm thấy thông tin sản phẩm đã chọn');
                    return;
                }
                
                // Validate input
                const receiptData = collectStockReceiptData();
                if (!receiptData) {
                    return; // Validation failed
                }
                
                // Confirm submission
                const confirmMessage = `Xác nhận nhập kho:\n\n` +
                    `Sản phẩm: ${window.selectedProductForReceipt.product_data.name}\n` +
                    `Tổng số lượng: ${receiptData.totalQuantity}\n` +
                    `Tổng giá trị: ${receiptData.totalValue.toLocaleString('vi-VN')} VNĐ\n\n` +
                    `Bạn có chắc muốn hoàn tất nhập kho?`;
                
                if (confirm(confirmMessage)) {
                    processStockReceiptSubmission(receiptData);
                }
            }
            
            // Function to collect stock receipt data
            function collectStockReceiptData() {
                const productData = window.selectedProductForReceipt.product_data;
                let receiptItems = [];
                let totalQuantity = 0;
                let totalValue = 0;
                let hasValidItems = false;
                
                // Check if this is simple input or variants
                const simpleQuantity = $('#simpleQuantity').val();
                const simplePrice = $('#simplePrice').val();
                
                if (simpleQuantity) {
                    // Simple product without variants
                    const quantity = parseInt(simpleQuantity);
                    const price = parseInt(simplePrice);
                    
                    if (!quantity || quantity <= 0) {
                        alert('Vui lòng nhập số lượng hợp lệ');
                        return null;
                    }
                    
                    if (!price || price <= 0) {
                        alert('Vui lòng nhập giá nhập hợp lệ');
                        return null;
                    }
                    
                    receiptItems.push({
                        product_id: productData.product_id,
                        variant_id: null,
                        quantity: quantity,
                        price: price
                    });
                    
                    totalQuantity = quantity;
                    totalValue = quantity * price;
                    hasValidItems = true;
                } else {
                    // Product with variants
                    $('.variant-quantity').each(function() {
                        const quantity = parseInt($(this).val()) || 0;
                        const variantId = $(this).data('variant-id');
                        const price = parseInt($(`#price_${variantId}`).val()) || 0;
                        
                        if (quantity > 0) {
                            if (price <= 0) {
                                alert(`Vui lòng nhập giá nhập cho variant ID ${variantId}`);
                                return null;
                            }
                            
                            receiptItems.push({
                                product_id: productData.product_id,
                                variant_id: variantId,
                                quantity: quantity,
                                price: price
                            });
                            
                            totalQuantity += quantity;
                            totalValue += (quantity * price);
                            hasValidItems = true;
                        }
                    });
                }
                
                if (!hasValidItems) {
                    alert('Vui lòng nhập ít nhất một variant với số lượng và giá hợp lệ');
                    return null;
                }
                
                return {
                    productData: productData,
                    items: receiptItems,
                    totalQuantity: totalQuantity,
                    totalValue: totalValue
                };
            }
            
            // Function to process stock receipt submission
            function processStockReceiptSubmission(receiptData) {
                console.log('⚡ Processing stock receipt submission:', receiptData);
                
                // Disable submit button and show loading
                $('#submitStockReceiptBtn').prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Đang xử lý...');
                
                // Prepare data for API
                const apiData = {
                    action: 'submit_stock_receipt_for_existing',
                    product_id: receiptData.productData.product_id,
                    receipt_items: receiptData.items,
                    total_quantity: receiptData.totalQuantity,
                    total_value: receiptData.totalValue,
                    supplier_id: selectedSupplier ? selectedSupplier.supplier_id : null,
                    receipt_notes: $('#receiptNotes').val() || 'Nhập kho từ sản phẩm có sẵn'
                };
                
                // Submit via AJAX
                $.ajax({
                    url: '', // Same page
                    method: 'POST',
                    data: apiData,
                    dataType: 'json',
                    success: function(response) {
                        $('#submitStockReceiptBtn').prop('disabled', false).html('<i class="fas fa-warehouse mr-2"></i>Hoàn tất nhập kho');
                        
                        if (response.success) {
                            console.log('✅ Stock receipt submitted successfully:', response);
                            
                            // Show success message
                            showStockReceiptSuccessModal(response, receiptData);
                        } else {
                            console.error('❌ Failed to submit stock receipt:', response);
                            alert('Lỗi khi tạo phiếu nhập kho: ' + (response.message || 'Unknown error'));
                        }
                    },
                    error: function(xhr, status, error) {
                        $('#submitStockReceiptBtn').prop('disabled', false).html('<i class="fas fa-warehouse mr-2"></i>Hoàn tất nhập kho');
                        console.error('❌ AJAX error:', error);
                        alert('Lỗi kết nối khi tạo phiếu nhập kho: ' + error);
                    }
                });
            }
            
            // Function to show success modal
            function showStockReceiptSuccessModal(response, receiptData) {
                const modalHtml = `
                    <div class="modal fade" id="stockReceiptSuccessModal" tabindex="-1">
                        <div class="modal-dialog modal-lg">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title">
                                        <i class="fas fa-check-circle"></i> Nhập kho thành công
                                    </h5>
                                </div>
                                <div class="modal-body">
                                    <div class="alert alert-success">
                                        <h6><i class="fas fa-warehouse"></i> Đã tạo phiếu nhập kho thành công!</h6>
                                        <p class="mb-1"><strong>Mã phiếu:</strong> ${response.receipt_id || 'N/A'}</p>
                                        <p class="mb-1"><strong>Sản phẩm:</strong> ${receiptData.productData.name}</p>
                                        <p class="mb-1"><strong>Tổng số lượng:</strong> ${receiptData.totalQuantity}</p>
                                        <p class="mb-0"><strong>Tổng giá trị:</strong> ${receiptData.totalValue.toLocaleString('vi-VN')} VNĐ</p>
                                    </div>
                                    
                                    <h6>Các variant đã nhập:</h6>
                                    <div class="table-responsive">
                                        <table class="table table-sm">
                                            <thead>
                                                <tr>
                                                    <th>Variant</th>
                                                    <th>Số lượng</th>
                                                    <th>Giá nhập</th>
                                                    <th>Thành tiền</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                ${receiptData.items.map(item => `
                                                    <tr>
                                                        <td>${item.variant_id || 'Default'}</td>
                                                        <td>${item.quantity}</td>
                                                        <td>${item.price.toLocaleString('vi-VN')} VNĐ</td>
                                                        <td>${(item.quantity * item.price).toLocaleString('vi-VN')} VNĐ</td>
                                                    </tr>
                                                `).join('')}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-primary" onclick="resetToNewReceipt()">
                                        <i class="fas fa-plus"></i> Tạo phiếu nhập mới
                                    </button>
                                    <button type="button" class="btn btn-secondary" onclick="window.location.href='stock_receipts.php'">
                                        <i class="fas fa-list"></i> Xem danh sách phiếu nhập
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
                
                $('body').append(modalHtml);
                $('#stockReceiptSuccessModal').modal('show');
                
                // Clean up modal when hidden
                $('#stockReceiptSuccessModal').on('hidden.bs.modal', function() {
                    $(this).remove();
                });
            }
            
            // Function to cancel stock receipt process
            function cancelStockReceiptProcess() {
                if (confirm('Bạn có chắc muốn hủy bỏ quá trình nhập kho? Tất cả dữ liệu đã nhập sẽ bị mất.')) {
                    // Reset everything
                    window.selectedProductForReceipt = null;
                    $('#stockReceiptDuplicateResults').slideUp();
                    moveToStep(1);
                    console.log('❌ Stock receipt process cancelled');
                }
            }
            
            // Function to reset to new receipt
            function resetToNewReceipt() {
                $('#stockReceiptSuccessModal').modal('hide');
                window.selectedProductForReceipt = null;
                moveToStep(1);
                // Clear all form data
                $('#productForm')[0].reset();
                $('#stockReceiptVariantsCard, #stockReceiptSimpleCard, #stockReceiptSubmitCard').remove();
                console.log('🔄 Reset to new receipt');
            }

            // Test duplicate check manually
            $('#testDuplicate').click(function() {
                if (aiData && Object.keys(aiData).length > 0) {
                    console.log('🧪 Manual duplicate check triggered');
                    
                    // Get current form data for enhanced duplicate checking
                    const currentFormData = {
                        name: $('#productName').val() || aiData.name,
                        brand: $('#brand').val() || aiData.brand,
                        type: $('#type').val() || aiData.type,
                        description: $('#productDescription').val() || aiData.description,
                        material: $('#material').val() || aiData.material,
                        features: $('#features').val() || aiData.features,
                        colors: $('#color').val() || aiData.colors,
                        tags: $('#tags').val() || aiData.tags
                    };
                    
                    // Merge with original AI data
                    const enhancedAiData = {
                        ...aiData,
                        ...currentFormData
                    };
                    
                    console.log('🧪 Enhanced data for duplicate check:', enhancedAiData);
                    checkForDuplicates(enhancedAiData);
                } else {
                    alert('Vui lòng chạy AI analysis trước khi test duplicate check');
                }
            });
            
            // Proceed to edit step
            $('#proceedToEdit').click(function() {
                console.log('📝 Proceeding to edit step...');
                moveToStep(4);
                displaySuggestions();
                
                // Check if there's pending AI data to populate
                if (window.pendingAIData) {
                    console.log('🤖 Found pending AI data, populating form...');
                    setTimeout(() => {
                        populateFormWithAIData(window.pendingAIData);
                        delete window.pendingAIData; // Clear after use
                    }, 500); // Wait a bit for form to be rendered
                }
            });

            // Reset the entire process
            $('#resetBtn').click(function() {
                if (confirm('Bạn có chắc muốn bắt đầu lại? Tất cả dữ liệu sẽ bị xóa.')) {
                    // Reset all variables
                    uploadedImages = [];
                    primaryImageIndex = 0;
                    aiData = null;
                    suggestions = null;
                    
                    // Clear all form fields
                    $('#productForm')[0].reset();
                    
                    // Reset UI
                    $('#imagePreview').empty();
                    $('#analyzeSection').hide();
                    $('#aiResults').hide();
                    $('#suggestionCard').hide();
                    
                    // Reset upload area
                    resetUploadArea();
                    
                    // Go back to step 2 (upload)
                    moveToStep(2);
                }
            });

            // Back to upload step
            $('#backToUpload').click(function() {
                moveToStep(2);
            });

            // Back to analysis step
            $('#backToAnalysis').click(function() {
                console.log('🔄 User clicked "Quay lại phân tích" - Resetting all data');
                
                // Reset all AI-related variables
                uploadedImages = [];
                aiData = null;
                suggestions = null;
                primaryImageIndex = 0;
                
                // Reset update mode flags
                isUpdateMode = false;
                updateProductId = null;
                useExistingDataOnly = false;
                window.useExistingDataOnly = false;
                
                // Clear all DOM elements
                $('#imagePreview').empty();
                $('#analyzedImages').empty();
                $('#aiResults .ai-analysis-alert').remove();
                $('#aiResults').hide();
                $('#aiSuggestions').empty();
                $('#suggestionCard').hide();
                $('#analyzingImages').hide();
                $('#loadingSpinner').hide();
                $('#stockReceiptDuplicateResults').hide().empty();
                $('#productTags').empty();
                $('#dominantColors').empty();
                $('#aiDuplicateCheckResult').hide().empty();
                
                // Reset upload area to initial state
                resetUploadArea();
                
                // Hide analyze button section
                $('#analyzeSection').hide();
                
                console.log('✅ Reset complete - Ready for new image upload');
                
                // Move back to step 2 (upload step)
                moveToStep(2);
            });

            function displaySuggestions() {
                console.log('🎯 displaySuggestions called with useExistingDataOnly =', useExistingDataOnly, 'window.useExistingDataOnly =', window.useExistingDataOnly);
                
                // Update UI for update mode
                updateUIForMode();
                
                // Chặn display suggestions nếu đang dùng existing data only
                const shouldBlockSuggestions = useExistingDataOnly || window.useExistingDataOnly;
                if (shouldBlockSuggestions) {
                    console.log('🚫 Blocking display suggestions - using existing data only. shouldBlockSuggestions =', shouldBlockSuggestions);
                    return;
                }
                
                if (suggestions) {
                    // Tự động áp dụng gợi ý AI ngay khi hiển thị
                    autoApplySuggestions();
                    
                    // Hiển thị thông tin để người dùng xem
                    let suggestionsHtml = `
                        <p><strong>Tên sản phẩm:</strong> ${suggestions.name || 'Chưa xác định'}</p>
                        <p><strong>Thương hiệu:</strong> ${suggestions.brand || 'Chưa xác định'}</p>
                        <p><strong>Loại sản phẩm:</strong> ${suggestions.type || 'Chưa xác định'}</p>
                        <p><strong>Màu sắc:</strong> ${Array.isArray(suggestions.colors) ? suggestions.colors.join(', ') : suggestions.colors || 'Chưa xác định'}</p>
                        <p><strong>Mô tả:</strong> ${suggestions.description || 'Chưa có mô tả'}</p>
                        <p><strong>Danh mục:</strong> <span class="text-success font-weight-bold">${suggestions.category || 'Chưa xác định'}</span></p>
                        <p><strong>Tags:</strong> ${Array.isArray(suggestions.tags) ? suggestions.tags.join(', ') : suggestions.tags || 'Chưa có tags'}</p>
                        <p><strong>Chất liệu:</strong> ${suggestions.material || 'Chưa xác định'}</p>
                        <p><strong>Tính năng:</strong> ${suggestions.features || 'Chưa có tính năng đặc biệt'}</p>
                    `;
                    $('#aiSuggestions').html(suggestionsHtml);
                    $('#suggestionCard').show();
                    
                    // Hiển thị thông báo chi tiết với delay để đảm bảo DOM đã cập nhật
                    setTimeout(() => {
                        const categorySelected = $('#categoryId option:selected').text();
                        const categoryId = $('#categoryId').val();
                        const skuGenerated = $('#baseSku').val();
                        
                        console.log('📊 Form state check:', {
                            categorySelected,
                            categoryId,
                            skuGenerated,
                            productName: $('#productName').val(),
                            brand: $('#brand').val()
                        });
                        
                        let message = '🤖 <strong>AI hoàn tất tự động điền:</strong><br>';
                        message += `📝 Tên sản phẩm: <code>${$('#productName').val()}</code><br>`;
                        message += `🏢 Thương hiệu: <code>${$('#brand').val()}</code><br>`;
                        message += `📂 Danh mục: <strong class="text-success">${categorySelected || 'Chưa chọn'}</strong> (ID: ${categoryId})<br>`;
                        message += `🏷️ Mã SKU: <code>${skuGenerated}</code><br>`;
                        message += `<small class="text-muted">✅ Vui lòng kiểm tra và điều chỉnh nếu cần</small>`;
                        
                        showToast(message, 'success');
                    }, 200);
                }
            }

            // Tự động áp dụng gợi ý AI
            function autoApplySuggestions() {
                // Chặn auto-apply nếu đang trong update mode với existing data only
                const shouldBlockAutoApply = useExistingDataOnly || window.useExistingDataOnly;
                if (shouldBlockAutoApply) {
                    console.log('🚫 Blocking AI suggestions - using existing data only. shouldBlockAutoApply =', shouldBlockAutoApply);
                    return;
                }
                
                if (suggestions) {
                    // Chuyển "Fashion" thành "Unknown" nếu thương hiệu là Fashion
                    if (suggestions.brand && suggestions.brand.trim().toLowerCase() === 'fashion') {
                        console.log('⚠️ Converting brand "Fashion" to "Unknown"');
                        suggestions.brand = 'Unknown';
                    }
                    
                    // 🎨 CHUẨN HÓA MÀU SẮC - Tránh lỗi nhận diện trùng lặp
                    if (suggestions.colors) {
                        const originalColors = JSON.stringify(suggestions.colors);
                        suggestions.colors = normalizeColors(suggestions.colors);
                        console.log(`🎨 Normalized colors in suggestions: ${originalColors} -> ${JSON.stringify(suggestions.colors)}`);
                    }
                    
                    // Chuẩn hóa tên loại sản phẩm
                    if (suggestions.type) {
                        const originalType = suggestions.type;
                        suggestions.type = standardizeProductType(suggestions.type);
                        if (originalType !== suggestions.type) {
                            console.log(`📝 Standardized product type: '${originalType}' -> '${suggestions.type}'`);
                        }
                    }
                    if (suggestions.category) {
                        const originalCategory = suggestions.category;
                        suggestions.category = standardizeProductType(suggestions.category);
                        if (originalCategory !== suggestions.category) {
                            console.log(`📝 Standardized category: '${originalCategory}' -> '${suggestions.category}'`);
                        }
                    }
                    
                    // 🔴 BẮT BUỘC: Kiểm tra đặc biệt cho Sandal đế xuồng trong suggestions
                    if (suggestions.type && suggestions.type.toLowerCase() === 'sandal') {
                        const checkFields = [
                            suggestions.name || '',
                            suggestions.description || '',
                            Array.isArray(suggestions.tags) ? suggestions.tags.join(' ') : (suggestions.tags || ''),
                            suggestions.features || ''
                        ];
                        
                        const combinedText = checkFields.join(' ').toLowerCase();
                        
                        if (combinedText.includes('đế xuồng') || 
                            combinedText.includes('de xuong') ||
                            combinedText.includes('wedge')) {
                            console.log('🔴 FORCED CORRECTION (Suggestions): Sandal → Giày đế xuồng');
                            suggestions.type = 'Giày đế xuồng';
                        }
                    }
                    
                    // Điền thông tin cơ bản
                    $('#productName').val(suggestions.name || '');
                    $('#brand').val(suggestions.brand || '');
                    $('#type').val(suggestions.type || '');
                    $('#productDescription').val(suggestions.description || '');
                    $('#material').val(suggestions.material || '');
                    $('#features').val(suggestions.features || '');
                    $('#tags').val(Array.isArray(suggestions.tags) ? suggestions.tags.join(', ') : suggestions.tags || '');
                    
                    // Điền màu sắc
                    if (Array.isArray(suggestions.colors) && suggestions.colors.length > 0) {
                        $('#color').val(suggestions.colors.join(', '));
                    } else if (suggestions.colors) {
                        $('#color').val(suggestions.colors);
                    }
                    
                    // Tự động chọn danh mục phù hợp - Logic cải tiến
                    if (suggestions.category) {
                        let categoryFound = false;
                        const suggestionCategory = suggestions.category.toLowerCase().trim();
                        console.log('🔍 Đang tìm danh mục cho:', suggestionCategory);
                        
                        // Mapping thông minh từ AI suggestion đến danh mục thực tế (chuẩn hóa)
                        const categoryMapping = {
                            // Sneaker (casual, lifestyle)
                            'sneaker': 'giày sneaker',
                            'sneakers': 'giày sneaker',
                            'casual shoes': 'giày sneaker',
                            'lifestyle': 'giày sneaker',
                            
                            // Thể thao (sport, athletic, performance)
                            'thể thao': 'giày thể thao',
                            'sport': 'giày thể thao',
                            'sports': 'giày thể thao',
                            'running': 'giày thể thao',
                            'training': 'giày thể thao',
                            'athletic': 'giày thể thao',
                            'performance': 'giày thể thao',
                            
                            // Boot
                            'boot': 'giày boot',
                            'boots': 'giày boot',
                            'bốt': 'giày boot',
                            'ankle boot': 'giày boot',
                            'high boot': 'giày boot',
                            
                            // Cao gót
                            'cao gót': 'giày cao gót',
                            'high heel': 'giày cao gót',
                            'heel': 'giày cao gót',
                            'heels': 'giày cao gót',
                            
                            // Sandal
                            'sandal': 'sandal',
                            'sandals': 'sandal',
                            'xăng đan': 'sandal',
                            
                            // Dép
                            'dép': 'dép',
                            'slipper': 'dép',
                            'slippers': 'dép',
                            'flip flop': 'dép',
                            'flip-flop': 'dép',
                            
                            // Giày tây (formal, dress)
                            'giày tây': 'giày tây',
                            'dress shoe': 'giày tây',
                            'dress shoes': 'giày tây',
                            'formal': 'giày tây',
                            'oxford': 'giày tây',
                            
                            // Giày lười (loafer, slip-on)
                            'lười': 'giày lười',
                            'loafer': 'giày lười',
                            'loafers': 'giày lười',
                            'slip-on': 'giày lười',
                            'slip on': 'giày lười'
                        };
                        
                        // Tìm match trực tiếp trong mapping
                        let targetCategory = null;
                        for (const [key, value] of Object.entries(categoryMapping)) {
                            if (suggestionCategory.includes(key) || key.includes(suggestionCategory)) {
                                targetCategory = value;
                                console.log(`📋 Mapping found: "${suggestionCategory}" → "${targetCategory}"`);
                                break;
                            }
                        }
                        
                        // Nếu không tìm thấy mapping, thử tìm trực tiếp
                        if (!targetCategory) {
                            targetCategory = suggestionCategory;
                        }
                        
                        // Tìm trong dropdown options
                        $('#categoryId option').each(function() {
                            const optionText = $(this).text().toLowerCase().trim();
                            const optionValue = $(this).val();
                            
                            if (optionValue === '') return true; // Skip empty option
                            
                            console.log(`🔎 Checking: "${optionText}" vs "${targetCategory}"`);
                            
                            // Logic matching linh hoạt
                            const isMatch = 
                                optionText === targetCategory ||                           // Exact match
                                optionText.includes(targetCategory) ||                    // Contains
                                targetCategory.includes(optionText) ||                   // Reverse contains
                                (optionText.replace(/\s+/g, '') === targetCategory.replace(/\s+/g, '')); // No spaces
                            
                            if (isMatch) {
                                $(this).prop('selected', true);
                                categoryFound = true;
                                $('#categoryId').addClass('border-success').removeClass('border-warning');
                                console.log(`✅ Category matched: "${suggestionCategory}" → "${optionText}"`);
                                return false; // Break loop
                            }
                        });
                        
                        if (!categoryFound) {
                            $('#categoryId').addClass('border-warning').removeClass('border-success');
                            console.log(`⚠️ No match found for: "${suggestionCategory}"`);
                        }
                    }
                    
                    // Tự động tạo SKU dựa trên thương hiệu và loại sản phẩm
                    generateSmartSKU();
                }
            }

            // Removed apply suggestions button - auto-apply is now default behavior
            
            // Auto-update SKU when product info changes
            $('#productName, #brand, #type').on('input blur', function() {
                setTimeout(() => {
                    if ($('#baseSku').val().trim() === '') {
                        generateSmartSKU();
                    } else {
                        updateSKUExample();
                    }
                }, 300);
            });
            
            // Manual SKU update
            $('#baseSku').on('input', function() {
                updateSKUExample();
            });
            
            // Generate SKU button
            $('#generateSkuBtn').click(function() {
                generateSmartSKU();
            });
            
            // Update SKU example when sizes change
            $(document).on('change', '.size-select', function() {
                updateSKUExample();
            });
            
            // Update SKU example when adding/removing sizes
            $(document).on('click', '#addSizeBtn, .remove-size-btn', function() {
                setTimeout(() => {
                    updateSKUExample();
                }, 100);
            });

            // Duplicate check modal handlers
            $('#continueAddNew').click(function() {
                console.log('🆕 Continue Add New button clicked');
                // Đóng modal và tiếp tục thêm sản phẩm mới
                $('#duplicateCheckModal').modal('hide');
                // Chuyển đến bước 4 để nhập thông tin
                console.log('🚀 Moving to step 4 from duplicate modal');
                moveToStep(4);
                displaySuggestions();
            });
            
            $('#updateExistingProduct').click(function() {
                console.log('🔄 Update Existing Product button clicked');
                
                // This is for stock receipt context - get selected product from inline display
                const productData = window.selectedProductForReceipt;
                if (!productData) {
                    alert('Lỗi: Không tìm thấy thông tin sản phẩm đã chọn');
                    return;
                }
                
                // Store selected product in global variable for database loading
                window.selectedUpdateProduct = productData;
                
                console.log('� Loading existing product from database:', productData);
                
                // Call database update function instead of AI-only approach
                updateFromExistingData();
            });
            
            // Click trên card sản phẩm trùng lặp để chọn
            $(document).on('click', '.duplicate-product-card', function(e) {
                // Don't handle if clicking on a button
                if ($(e.target).is('button') || $(e.target).closest('button').length) {
                    return;
                }
                
                console.log('🖱️ Clicked on duplicate product card');
                
                // Check if product is inactive
                const status = $(this).data('product-status');
                if (status === 'inactive') {
                    window.showInactiveWarning();
                    return;
                }
                
                $('.duplicate-product-card').removeClass('border-primary');
                $(this).addClass('border-primary');
                
                const productId = $(this).data('product-id');
                const productName = $(this).find('.card-title').text();
                console.log('✅ Selected product:', { productId, productName });
                
                // Store selected product for receipt
                window.selectedProductForReceipt = {
                    product_id: productId,
                    name: productName
                };
            });
            
            // Function to generate SKU - Legacy version (keeping for compatibility)
            function generateSKU() {
                generateSmartSKU();
            }
            
            // Tạo SKU thông minh dựa trên thông tin sản phẩm
            function generateSmartSKU() {
                // Không generate SKU mới trong update mode
                if (isUpdateMode) {
                    console.log('🚫 Skipping SKU generation - in update mode');
                    showToast('⚠️ Không thể tạo SKU mới khi cập nhật sản phẩm từ database', 'warning');
                    return;
                }
                
                // Kiểm tra nếu SKU đã tồn tại và là readonly (từ database)
                const currentSku = $('#baseSku').val().trim();
                const isReadonly = $('#baseSku').prop('readonly');
                
                if (currentSku && isReadonly) {
                    console.log('🚫 Skipping SKU generation - SKU from database is readonly');
                    showToast('⚠️ SKU từ database không thể thay đổi', 'warning');
                    return;
                }
                
                // Kiểm tra nếu có product_id (đã chọn sản phẩm từ database)
                const productId = $('#product_id').val() || $('#productId').val();
                if (productId) {
                    console.log('🚫 Skipping SKU generation - Product from database (ID: ' + productId + ')');
                    showToast('⚠️ Không thể tạo SKU mới cho sản phẩm từ database', 'warning');
                    return;
                }
                
                const brand = ($('#brand').val() || '').trim();
                const type = ($('#type').val() || '').trim();
                const name = ($('#productName').val() || '').trim();
                
                console.log('Generating SKU with:', { brand, type, name }); // Debug
                
                let skuBase = '';
                
                if (brand && type) {
                    // Lấy 3-4 ký tự đầu của thương hiệu
                    let brandCode = brand.toUpperCase().substring(0, 4).replace(/[^A-Z]/g, '');
                    
                    // Lấy từ khóa chính từ loại sản phẩm
                    let typeCode = '';
                    const typeLower = type.toLowerCase();
                    
                    if (typeLower.includes('sneaker') || typeLower.includes('thể thao') || typeLower.includes('sport')) {
                        typeCode = 'SNK';
                    } else if (typeLower.includes('boot') || typeLower.includes('bốt')) {
                        typeCode = 'BOOT';
                    } else if (typeLower.includes('cao gót') || typeLower.includes('high heel')) {
                        typeCode = 'HIGH';
                    } else if (typeLower.includes('sandal') || typeLower.includes('xăng đan')) {
                        typeCode = 'SDL';
                    } else if (typeLower.includes('dép') || typeLower.includes('slipper')) {
                        typeCode = 'SLP';
                    } else if (typeLower.includes('giày')) {
                        typeCode = 'SHOE';
                    } else {
                        typeCode = type.toUpperCase().substring(0, 4).replace(/[^A-Z]/g, '') || 'ITEM';
                    }
                    
                    skuBase = brandCode + '-' + typeCode;
                } else if (name) {
                    // Nếu không có brand/type, dùng tên sản phẩm
                    skuBase = name.toUpperCase().substring(0, 8).replace(/[^A-Z0-9]/g, '');
                }
                
                // Thêm số ngẫu nhiên để đảm bảo unique
                if (skuBase && skuBase.length > 0) {
                    const randomNum = Math.floor(Math.random() * 1000).toString().padStart(3, '0');
                    skuBase = skuBase + '-' + randomNum;
                } else {
                    // Fallback nếu không có thông tin
                    const timestamp = Date.now().toString().slice(-6);
                    skuBase = 'PRODUCT-' + timestamp;
                }
                
                console.log('Generated SKU:', skuBase); // Debug
                $('#baseSku').val(skuBase);
                $('#baseSku').addClass('border-success'); // Visual feedback
                
                // Cập nhật ví dụ SKU
                updateSKUExample();
                
                // Hiển thị thông báo
                showToast('Đã tự động tạo mã SKU: ' + skuBase, 'success');
            }
            
            // Cập nhật ví dụ SKU cho từng size
            function updateSKUExample() {
                const baseSku = $('#baseSku').val().trim();
                if (baseSku) {
                    // Lấy các size đã chọn hoặc dùng ví dụ mặc định
                    const selectedSizes = [];
                    $('.size-select').each(function() {
                        const size = $(this).val();
                        if (size) {
                            selectedSizes.push(size);
                        }
                    });
                    
                    // Nếu chưa chọn size nào, dùng ví dụ mặc định
                    const sizesToShow = selectedSizes.length > 0 ? selectedSizes : ['38', '39', '40', '41', '42'];
                    const examples = sizesToShow.map(size => `${baseSku}-${size}`);
                    
                    // Tạo HTML hiển thị ví dụ
                    let exampleHtml = '';
                    if (examples.length <= 3) {
                        exampleHtml = examples.join(', ');
                    } else {
                        exampleHtml = examples.slice(0, 3).join(', ') + ` <span class="text-muted">... (+${examples.length - 3} size khác)</span>`;
                    }
                    
                    // Cập nhật phần giải thích SKU
                    $('#skuExample').html(`
                        <i class="fas fa-info-circle text-info"></i> 
                        <strong>Mã SKU cơ sở sẽ được sử dụng để tạo SKU riêng cho từng size.</strong><br>
                        <i class="fas fa-tag text-success"></i> ${selectedSizes.length > 0 ? 'SKU cho các size đã chọn' : 'Ví dụ'}: 
                        <code class="bg-light p-1 rounded">${exampleHtml}</code>
                        ${selectedSizes.length > 3 ? '<br><small class="text-info">💡 Tất cả size đã chọn sẽ có SKU riêng biệt</small>' : ''}
                    `);
                }
            }

            // Save product
            $('#productForm').submit(function(e) {
                e.preventDefault();
                
                // Validate sizes và ngưỡng tối thiểu
                const sizeSelects = $('.size-select');
                const minQuantityInputs = $('input[name="min_quantities[]"]');
                let hasValidSize = false;
                let sizesData = [];
                
                // Kiểm tra trùng lặp
                if (!checkDuplicateSizes()) {
                    showToast('Vui lòng không chọn trùng size!', 'error');
                    return;
                }
                
                // Collect sizes data (allow empty min_quantity)
                for (let i = 0; i < sizeSelects.length; i++) {
                    const size = $(sizeSelects[i]).val();
                    const minQuantity = parseInt($(minQuantityInputs[i]).val()) || 0;
                    
                    if (size) { // Chỉ cần có size, không bắt buộc min_quantity > 0
                        hasValidSize = true;
                        sizesData.push({ 
                            size: size, 
                            min_quantity: minQuantity 
                        });
                    }
                }
                
                // Bỏ kiểm tra ngưỡng tối thiểu - cho phép không có size hoặc min_quantity = 0
                
                // ⚠️ CRITICAL: Capture form data BEFORE any DOM changes
                // Lấy dữ liệu từ form TRƯỚC KHI submit để tránh mất dữ liệu
                console.log('🚀 Form submit triggered - Starting data capture...');
                console.log('🔍 Checking DOM elements:');
                console.log('  .size-row count:', $('.size-row').length);
                console.log('  #sizeTableBody tr count:', $('#sizeTableBody tr').length);
                console.log('  #sizeTableBody tr:not(.d-none) count:', $('#sizeTableBody tr:not(.d-none)').length);
                
                const capturedFormData = {
                    productId: $('#product_id').val() || null,  // Product ID từ hidden field
                    productName: $('#productName').val() || 'Sản phẩm',
                    sizes: [],
                    quantities: [],
                    skus: [],
                    prices: [],  // Giá nhập
                    salePrices: [],  // Giá bán
                    storageLocations: []  // Vị trí lưu trữ
                };
                
                // Lấy tất cả size rows - hỗ trợ CẢ 2 layout:
                // 1. New product: table rows in #sizeTableBody
                // 2. Update existing: div.size-row in #sizesContainer
                
                // Try update existing product layout first (div.size-row)
                if ($('.size-row').length > 0) {
                    console.log('📋 Detecting UPDATE mode layout (div.size-row)');
                    $('.size-row').each(function(index) {
                        const $row = $(this);
                        const size = $row.find('input[name="sizes[]"]').val();
                        const quantity = $row.find('input[name="import_quantities[]"]').val();
                        const sku = $row.find('input[name="skus[]"]').val();
                        // Get raw values from formatted inputs
                        const priceInput = $row.find('input[name="original_prices[]"]');
                        const salePriceInput = $row.find('input[name="sale_prices[]"]');
                        
                        // Debug log
                        console.log(`🔍 Row #${index} - Size: ${size}`);
                        console.log('  priceInput.data("raw-value"):', priceInput.data('raw-value'));
                        console.log('  priceInput.val():', priceInput.val());
                        console.log('  salePriceInput.data("raw-value"):', salePriceInput.data('raw-value'));
                        console.log('  salePriceInput.val():', salePriceInput.val());
                        
                        const price = priceInput.data('raw-value') || priceInput.val().replace(/[^\d]/g, '');  // Giá nhập
                        const salePrice = salePriceInput.data('raw-value') || salePriceInput.val().replace(/[^\d]/g, '');  // Giá bán
                        const storageLocation = $row.find('input[name="storage_locations[]"]').val();  // Vị trí kho
                        
                        console.log(`  → Final price: ${price}, salePrice: ${salePrice}`);
                        
                        if (size) {
                            capturedFormData.sizes.push(size);
                            capturedFormData.quantities.push(parseInt(quantity) || 0);
                            capturedFormData.skus.push(sku || 'N/A');
                            capturedFormData.prices.push(parseFloat(price) || 0);
                            capturedFormData.salePrices.push(parseFloat(salePrice) || 0);
                            capturedFormData.storageLocations.push(storageLocation || 'A-1-00');
                        }
                    });
                } else {
                    // Fallback to new product layout (table rows)
                    console.log('📋 Detecting NEW product layout (#sizeTableBody tr)');
                    $('#sizeTableBody tr').each(function(index) {
                        const $row = $(this);
                        const size = $row.find('select[name="sizes[]"]').val();
                        const quantity = $row.find('input[name="min_quantities[]"]').val();
                        const sku = $row.find('.sku-display').text().trim();
                        
                        // Try to find price inputs with different names
                        const priceInput = $row.find('input[name="prices[]"], input[name="original_prices[]"]');
                        const salePriceInput = $row.find('input[name="sale_prices[]"]');
                        
                        // Debug log
                        console.log(`🔍 Row #${index} - Size: ${size}`);
                        console.log('  priceInput.data("raw-value"):', priceInput.data('raw-value'));
                        console.log('  priceInput.val():', priceInput.val());
                        console.log('  salePriceInput.data("raw-value"):', salePriceInput.data('raw-value'));
                        console.log('  salePriceInput.val():', salePriceInput.val());
                        
                        const price = priceInput.data('raw-value') || priceInput.val().replace(/[^\d]/g, '') || 0;
                        const salePrice = salePriceInput.data('raw-value') || salePriceInput.val().replace(/[^\d]/g, '') || 0;
                        const storageLocation = $row.find('input[name="storage_locations[]"]').val() || 'A-1-00';
                        
                        console.log(`  → Final price: ${price}, salePrice: ${salePrice}`);
                        
                        if (size) {
                            capturedFormData.sizes.push(size);
                            capturedFormData.quantities.push(parseInt(quantity) || 0);
                            capturedFormData.skus.push(sku || 'N/A');
                            capturedFormData.prices.push(parseFloat(price) || 0);
                            capturedFormData.salePrices.push(parseFloat(salePrice) || 0);  // ĐÃ SỬA: Dùng giá bán từ input
                            capturedFormData.storageLocations.push(storageLocation);
                        }
                    });
                }
                
                console.log('📦 Captured form data BEFORE submit:', capturedFormData);
                
                // Show loading
                const submitBtn = $(this).find('button[type="submit"]');
                const originalText = submitBtn.html();
                // Luôn hiển thị "Đang thêm vào phiếu..." vì đây là flow tạo phiếu nhập
                const loadingText = '<i class="fas fa-spinner fa-spin"></i> Đang thêm vào phiếu...';
                submitBtn.html(loadingText).prop('disabled', true);
                
                // Add update mode info to form data
                let formData = $(this).serialize();
                
                // CRITICAL FIX: Ensure category_id is always provided to prevent foreign key constraint violation
                // Check if category_id is missing or empty in the form data
                if (!formData.includes('category_id=') || formData.includes('category_id=&') || formData.includes('category_id=""')) {
                    console.warn('⚠️ category_id missing or empty - adding default category');
                    // Add default category (Giày sneaker = ID 1)
                    formData += '&category_id=1';
                    
                    // Show warning to user
                    showToast('Danh mục sản phẩm chưa được chọn, đã tự động chọn "Giày sneaker"', 'warning');
                } else {
                    console.log('✅ category_id found in form data');
                }
                
                // Add action parameter
                formData += '&action=save_product';
                
                // Check for update mode from global variables
                const updateMode = window.isUpdateMode || isUpdateMode;
                const prodId = window.updateProductId || updateProductId;
                
                console.log('🔍 Update mode check:', {
                    windowIsUpdateMode: window.isUpdateMode,
                    localIsUpdateMode: isUpdateMode,
                    finalUpdateMode: updateMode,
                    windowUpdateProductId: window.updateProductId,
                    localUpdateProductId: updateProductId,
                    finalProdId: prodId
                });
                
                if (updateMode && prodId) {
                    formData += '&is_update_mode=true&update_product_id=' + prodId;
                    console.log('✅ Added update mode data to form');
                } else {
                    console.log('ℹ️ Not in update mode or missing product ID');
                }
                
                // Debug: Log form data before sending
                console.log('📤 Form data being sent:', formData);
                
                $.ajax({
                    url: '',
                    method: 'POST',
                    data: formData,
                    dataType: 'json',
                    success: function(response) {
                        console.log('✅ AJAX Success Response:', response);
                        if (response.success) {
                            console.log('🎉 Server confirmed successful product save');
                            
                            // ⚡ LUÔN SỬ DỤNG capturedFormData để có đầy đủ giá nhập và giá bán
                            // response.created_sizes chỉ có SKU nhưng KHÔNG CÓ GIÁ
                            console.log('📦 Adding products to receiptItems using capturedFormData');
                            console.log('📦 receiptItems before adding:', [...receiptItems]);
                            console.log('📦 Captured data:', capturedFormData);
                            
                            const productId = response.product_id || capturedFormData.productId; // ⭐ LẤY PRODUCT_ID TỪ RESPONSE
                            console.log('🆔 Product ID from response:', productId);
                            
                            // Merge SKU từ response với giá từ capturedFormData
                            const createdSizes = response.created_sizes || [];
                            
                            capturedFormData.sizes.forEach((size, index) => {
                                // Lấy SKU từ response nếu có, nếu không thì dùng SKU từ captured data
                                const sku = (createdSizes[index] && createdSizes[index].sku) || capturedFormData.skus[index] || 'N/A';
                                
                                const newItem = {
                                    product_id: productId,  // ⭐ SỬ DỤNG PRODUCT_ID TỪ RESPONSE
                                    product_name: capturedFormData.productName,
                                    size: size,
                                    sku: sku,
                                    quantity: capturedFormData.quantities[index] || 0,
                                    price: capturedFormData.prices[index] || 0,  // ⭐ Giá nhập từ form
                                    sale_price: capturedFormData.salePrices[index] || 0,  // ⭐ Giá bán từ form
                                    base_sku: sku.split('-').slice(0, -1).join('-') || '',  // Base SKU (remove size)
                                    storage_location: capturedFormData.storageLocations[index] || 'A-1-00'
                                };
                                receiptItems.push(newItem);
                                console.log(`➕ Added item ${index + 1} to receiptItems:`, newItem);
                                console.log(`   → price: ${newItem.price}, sale_price: ${newItem.sale_price}`);
                            });
                            
                            console.log('📋 receiptItems after adding:', [...receiptItems]);
                            console.log('📊 Total items count:', receiptItems.length);
                            
                            // Debug: Kiểm tra trước khi chuyển step
                            console.log('🔄 About to move to step 5 and update summary');
                            console.log('📦 Current receiptItems before step change:', receiptItems);
                            
                            // Chuyển sang bước 5 - moveToStep sẽ tự động save state với currentStep = 5
                            moveToStep(5);
                            
                            // Delay một chút để đảm bảo DOM đã được cập nhật
                            setTimeout(() => {
                                console.log('⏰ Calling updateReceiptSummary after 100ms delay');
                                updateReceiptSummary();
                            }, 100);
                        } else {
                            // Response không thành công
                            console.error('❌ Server returned error:', response.message);
                            showToast('Lỗi: ' + response.message, 'error');
                            submitBtn.html(originalText).prop('disabled', false);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('❌ AJAX Error Details:', {
                            status: status,
                            error: error,
                            responseText: xhr.responseText,
                            statusText: xhr.statusText,
                            readyState: xhr.readyState
                        });
                        
                        let errorMessage = 'Có lỗi xảy ra: ';
                        if (xhr.responseText) {
                            try {
                                const errorResponse = JSON.parse(xhr.responseText);
                                errorMessage += errorResponse.message || xhr.responseText;
                            } catch (e) {
                                errorMessage += xhr.responseText.substring(0, 200);
                            }
                        } else {
                            errorMessage += error;
                        }
                        
                        showToast(errorMessage, 'error');
                        submitBtn.html(originalText).prop('disabled', false);
                    }
                });
            });

            // Xử lý thêm size mới
            $('#addSizeBtn').click(function() {
                const sizeIndex = $('#sizeContainer .size-row').length;
                const newSizeRow = `
                    <div class="size-row mb-2" data-size-index="${sizeIndex}">
                        <div class="card border-left-success">
                            <div class="card-body p-3">
                                <div class="row align-items-center">
                                    <div class="col-md-4">
                                        <label class="form-label mb-1"><strong>Size</strong></label>
                                        <select name="sizes[]" class="form-control size-select" required>
                                            <option value="">Chọn size</option>
                                            <option value="35">Size 35</option>
                                            <option value="36">Size 36</option>
                                            <option value="37">Size 37</option>
                                            <option value="38">Size 38</option>
                                            <option value="39">Size 39</option>
                                            <option value="40">Size 40</option>
                                            <option value="41">Size 41</option>
                                            <option value="42">Size 42</option>
                                            <option value="43">Size 43</option>
                                            <option value="44">Size 44</option>
                                            <option value="45">Size 45</option>
                                            <option value="46">Size 46</option>
                                        </select>
                                    </div>
                                    <div class="col-md-5">
                                        <label class="form-label mb-1"><strong>Ngưỡng tối thiểu</strong></label>
                                        <div class="input-group">
                                            <input type="number" name="min_quantities[]" class="form-control" 
                                                   placeholder="VD: 5" min="1" value="5" required>
                                            <div class="input-group-append">
                                                <span class="input-group-text">đôi</span>
                                            </div>
                                        </div>
                                        <small class="text-muted">Cảnh báo khi tồn kho dưới ngưỡng này</small>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label mb-1">&nbsp;</label>
                                        <div>
                                            <button type="button" class="btn btn-outline-danger btn-sm remove-size-btn">
                                                <i class="fas fa-trash"></i> Xóa
                                            </button>
                                        </div>
                                    </div>
                                    <div class="col-md-1">
                                        <div class="text-center">
                                            <i class="fas fa-shoe-prints text-success" style="font-size: 1.5em;"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
                
                $('#sizeContainer').append(newSizeRow);
                updateRemoveButtons();
                checkDuplicateSizes();
                
                // Hiển thị thông báo
                showToast('Đã thêm size mới', 'success');
            });

            // Xử lý xóa size (legacy button)
            $(document).on('click', '.remove-size-btn', function() {
                const $row = $(this).closest('.size-row');
                const size = $row.data('size');
                
                // Show the checkbox back in selection area
                if (size) {
                    $(`.size-option[data-size="${size}"]`).fadeIn('fast');
                    updateSelectedSizesCount();
                }
                
                $row.remove();
                updateRemoveButtons();
                checkDuplicateSizes();
                showToast('Đã xóa size', 'info');
            });
            
            // Xử lý xóa size row (new button in advanced layout)
            $(document).on('click', '.remove-size-row-btn', function() {
                const $row = $(this).closest('.size-row');
                const size = $row.data('size');
                
                // Show the checkbox back in selection area
                if (size) {
                    $(`.size-option[data-size="${size}"]`).fadeIn('fast');
                    updateSelectedSizesCount();
                    
                    // Also show the table row back in selection table
                    $('table tr[data-size="' + size + '"]').fadeIn('fast');
                }
                
                $row.fadeOut('fast', function() {
                    $(this).remove();
                });
                
                showToast('Đã xóa size ' + size, 'info');
            });
            
            // Function to show all hidden sizes (useful for reset)
            window.showAllSizes = function() {
                // Show all table rows
                $('table tr[data-size]').fadeIn('fast');
                
                // Show all checkbox options  
                $('.size-option').fadeIn('fast');
                
                // Reset all select buttons
                $('.select-size-btn').removeClass('btn-success').addClass('btn-primary')
                                     .html('<i class="fas fa-plus"></i> Chọn')
                                     .prop('disabled', false);
                
                // Uncheck all checkboxes
                $('.size-checkbox').prop('checked', false);
                
                // Update counts
                updateSelectedSizesCount();
            };

            // Cập nhật hiển thị nút xóa (chỉ hiện khi có nhiều hơn 1 size)
            function updateRemoveButtons() {
                const sizeRows = $('#sizeContainer .size-row');
                if (sizeRows.length <= 1) {
                    $('.remove-size-btn').hide();
                } else {
                    $('.remove-size-btn').show();
                }
            }

            // Kiểm tra trùng lặp size
            function checkDuplicateSizes() {
                const sizes = [];
                let hasDuplicate = false;
                
                $('.size-select').each(function() {
                    const size = $(this).val();
                    const currentRow = $(this).closest('.size-row');
                    
                    // Reset style
                    currentRow.find('.card').removeClass('border-left-danger').addClass('border-left-primary');
                    
                    if (size && sizes.includes(size)) {
                        currentRow.find('.card').removeClass('border-left-primary border-left-success').addClass('border-left-danger');
                        hasDuplicate = true;
                    } else if (size) {
                        sizes.push(size);
                    }
                });
                
                return !hasDuplicate;
            }

            // Kiểm tra trùng lặp size khi chọn
            $(document).on('change', '.size-select', function() {
                const selectedSize = $(this).val();
                const currentRow = $(this).closest('.size-row');
                
                if (selectedSize) {
                    let isDuplicate = false;
                    const currentSelect = this;
                    
                    $('.size-select').each(function() {
                        if (this !== currentSelect && $(this).val() === selectedSize) {
                            isDuplicate = true;
                            return false;
                        }
                    });
                    
                    if (isDuplicate) {
                        currentRow.find('.card').removeClass('border-left-primary border-left-success').addClass('border-left-danger');
                        showToast(`Size ${selectedSize} đã được chọn rồi! Vui lòng chọn size khác.`, 'error');
                        $(this).val('');
                        $(this).focus();
                    } else {
                        currentRow.find('.card').removeClass('border-left-danger').addClass('border-left-success');
                        
                        // Gợi ý ngưỡng tối thiểu dựa trên size (size phổ biến cần ngưỡng cao hơn)
                        const minQuantityInput = currentRow.find('input[name="min_quantities[]"]');
                        const sizeNum = parseInt(selectedSize);
                        
                        if (sizeNum >= 38 && sizeNum <= 42) {
                            // Size phổ biến, nên có ngưỡng cao
                            minQuantityInput.val(10);
                            minQuantityInput.attr('placeholder', 'Size phổ biến: 10-20');
                        } else if (sizeNum >= 43 || sizeNum <= 36) {
                            // Size ít phổ biến, ngưỡng thấp hơn
                            minQuantityInput.val(3);
                            minQuantityInput.attr('placeholder', 'Size ít phổ biến: 3-8');
                        } else {
                            minQuantityInput.val(5);
                            minQuantityInput.attr('placeholder', 'VD: 5');
                        }
                    }
                } else {
                    currentRow.find('.card').removeClass('border-left-success border-left-danger').addClass('border-left-primary');
                }
                
                checkDuplicateSizes();
            });

            function moveToStep(step) {
                console.log('[moveToStep] Switching to step:', step);
                console.log('[moveToStep] Current receiptItems:', receiptItems);
                console.log('[moveToStep] Current selectedSupplier:', selectedSupplier);
                
                // Update step indicator
                $('.step').removeClass('active completed');
                for (let i = 1; i < step; i++) {
                    $(`#step${i}`).addClass('completed');
                }
                $(`#step${step}`).addClass('active');

                // Show/hide content
                $('[id^="step"][id$="-content"]').hide();
                $(`#step${step}-content`).show();

                // Special handling for going back to previous steps
                if (step === 1) {
                    // Step 1: Basic Info - no special handling needed
                } else if (step === 2) {
                    // Step 2: Upload images
                    // Only show analyze section if images exist (but they should be cleared by backToAnalysis)
                    $('#analyzeSection').toggle(uploadedImages.length > 0);
                } else if (step === 3) {
                    // Step 3: AI Analysis
                    // Only show results if data exists (normal forward flow)
                    // Note: backToAnalysis button already clears all data, so this is for forward navigation only
                    if (uploadedImages.length > 0) {
                        displayImagesInAnalysisStep();
                        $('#analyzingImages').show();
                    }
                    if (aiData) {
                        $('#loadingSpinner').hide();
                        $('#aiResults').show();
                    }
                } else if (step === 4) {
                    // Step 4: Edit Info - Show suggestions if going back
                    // Clean up any duplicate content first
                    if (typeof cleanupStep4Content === 'function') {
                        cleanupStep4Content();
                    }
                    
                    // Reset location tracking khi bắt đầu nhập sản phẩm mới
                    if (typeof window.resetLocationTracking === 'function') {
                        window.resetLocationTracking();
                        console.log('🔄 Reset location tracking for new product entry');
                    }
                    
                    // Reset duplicate prevention flags
                    window._fillingForm = false;
                    window._displayingSizes = false;
                    console.log('🔄 Reset duplicate prevention flags for step 4');
                    
                    // Remove any loading indicator if exists
                    if (typeof removeStep4Loading === 'function') {
                        removeStep4Loading();
                    }
                    
                    console.log('🔍 Step 4 check: suggestions =', !!suggestions, 'useExistingDataOnly =', useExistingDataOnly, 'window.useExistingDataOnly =', window.useExistingDataOnly);
                    
                    // Check both local and global useExistingDataOnly flags
                    const shouldSkipSuggestions = useExistingDataOnly || window.useExistingDataOnly;
                    
                    if (suggestions && !shouldSkipSuggestions) {
                        console.log('🎯 Calling displaySuggestions because useExistingDataOnly is false');
                        displaySuggestions();
                    } else {
                        console.log('✅ Skipping displaySuggestions - using existing data or no suggestions. shouldSkipSuggestions =', shouldSkipSuggestions);
                    }
                    
                    // Update continue to step 5 button visibility
                    if (typeof updateContinueToStep5Button === 'function') {
                        updateContinueToStep5Button();
                    }
                } else if (step === 5) {
                    // Step 5: Complete - Load data and update summary
                    console.log('📋 Entering Step 5 - Receipt Summary');
                    
                    // KHÔNG load từ localStorage ở đây vì nó có thể trigger moveToStep() lại
                    // Data đã được load/add trước khi moveToStep(5) được gọi
                    
                    // Verify data
                    console.log('📊 Data check before summary:');
                    console.log('- receiptItems:', receiptItems);
                    console.log('- selectedSupplier:', selectedSupplier);
                    
                    // Update debug info
                    updateDebugInfo();
                    
                    // Force update summary after a short delay to ensure DOM is ready
                    setTimeout(() => {
                        console.log('⏰ Delayed update of receipt summary');
                        console.log('🔍 About to call updateReceiptSummary()');
                        console.log('📦 receiptItems before call:', receiptItems);
                        console.log('👤 selectedSupplier before call:', selectedSupplier);
                        updateReceiptSummary();
                        console.log('✅ updateReceiptSummary() called');
                    }, 200);
                }

                currentStep = step;
                
                // Save current state to localStorage
                saveDataToLocalStorage();
            }
            // Expose globally so external handlers can call it
            window.moveToStep = moveToStep;
        });

        // Function to reset form for new product
        function resetFormForNewProduct() {
            // Confirm before reset
            if (confirm('Bạn có chắc muốn reset form để thêm sản phẩm mới? Tất cả dữ liệu hiện tại sẽ bị mất.')) {
                // Reset all form fields
                $('#productForm')[0].reset();
                
                // Reset uploaded images
                uploadedImages = [];
                aiData = null;
                currentStep = 1;
                
                // Clear image displays
                $('#imagePreview').empty();
                $('#analyzedImages').empty();
                
                // Hide all sections
                $('#analyzeSection').hide();
                $('#aiResults').hide();
                $('#successModal').modal('hide');
                
                // Reset steps
                $('.step').removeClass('active completed');
                $('#step1').addClass('active');
                $('[id^="step"][id$="-content"]').hide();
                $('#step1-content').show();
                
                // Reset size container
                $('#sizeContainer').html(`
                    <div class="size-row mb-3 p-3 border rounded">
                        <div class="row">
                            <div class="col-md-6">
                                <label class="form-label">Size</label>
                                <select class="form-control" name="sizes[]" required>
                                    <option value="">Chọn size</option>
                                    <option value="35">35</option>
                                    <option value="36">36</option>
                                    <option value="37">37</option>
                                    <option value="38">38</option>
                                    <option value="39">39</option>
                                    <option value="40">40</option>
                                    <option value="41">41</option>
                                    <option value="42">42</option>
                                    <option value="43">43</option>
                                    <option value="44">44</option>
                                    <option value="45">45</option>
                                </select>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label">Ngưỡng tối thiểu</label>
                                <input type="number" class="form-control" name="min_quantities[]" min="1" placeholder="VD: 10" required>
                            </div>
                            <div class="col-md-1 d-flex align-items-end">
                                <button type="button" class="btn btn-danger btn-sm remove-size-btn" onclick="removeSize(this)">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                `);
                
                // Show toast
                showToast('Form đã được reset để thêm sản phẩm mới', 'success');
            }
        }

        // Enhanced Size Selection Functions for step 4
        // Handle size selection
        $('#selectSizesBtn').click(function() {
            $('#sizeSelectionModal').modal('show');
        });

        // Handle size selection modal events
        $('#sizeSelectionModal').on('shown.bs.modal', function() {
            loadAvailableSizes();
        });
        
        // Initialize button state when modal is fully loaded
        $('#sizeSelectionModal').on('shown.bs.modal', function() {
            setTimeout(() => {
                toggleConfirmButton();
            }, 500);
        });

        // Handle size checkbox changes
        $(document).on('change', '.size-checkbox', function() {
            updateSelectedSizesCount();
            toggleConfirmButton();
            
            // Add visual feedback
            const $card = $(this).closest('.size-option');
            if ($(this).is(':checked')) {
                $card.addClass('selected');
            } else {
                $card.removeClass('selected');
            }
        });
        
        // Handle confirm multiple sizes selection
        $(document).on('click', '#confirmMultipleSizes', function(e) {
            console.log('🎯 Confirm button clicked!');
            e.preventDefault();
            
            const selectedSizes = getSelectedSizes();
            console.log('📋 Selected sizes:', selectedSizes);
            
            if (selectedSizes.length > 0) {
                console.log('✅ Processing selected multiple sizes:', selectedSizes);
                displaySelectedSizes(selectedSizes);
                $('#sizeSelectionModal').modal('hide');
            } else {
                console.log('❌ No sizes selected');
                alert('Vui lòng chọn ít nhất một size');
            }
        });
        
        // Size Selection Functions
        function loadAvailableSizes() {
            console.log('📋 Loading available sizes for product...');
            
            // Show loading state
            $('#sizesLoadingState').show();
            $('#availableSizesList').hide();
            $('#noSizesFound').hide();
            
            // Get product ID - try multiple sources
            let productId = updateProductId;
            
            // If not in update mode, try to get from hidden input or other sources
            if (!productId) {
                productId = $('#product_id').val() || $('#hidden_product_id').val();
            }
            
            // Try to get from aiData if available
            if (!productId && typeof aiData !== 'undefined' && aiData.product_id) {
                productId = aiData.product_id;
            }
            
            // Try to get from base SKU
            if (!productId) {
                const baseSku = $('#baseSku').val();
                if (baseSku) {
                    console.log('🔍 Attempting to find product ID from SKU:', baseSku);
                    // Try to get product ID from existing product by SKU
                    $.ajax({
                        url: '',
                        method: 'POST',
                        data: {
                            action: 'get_product_id_by_sku',
                            sku: baseSku
                        },
                        dataType: 'json',
                        async: false,
                        success: function(response) {
                            if (response.success && response.product_id) {
                                productId = response.product_id;
                                console.log('✅ Found product ID from SKU:', productId);
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error('❌ Error getting product ID from SKU:', error);
                        }
                    });
                }
            }
            
            console.log('🔍 Product ID for loading sizes:', productId);
            
            if (!productId) {
                console.error('❌ No product ID available for loading sizes');
                $('#sizesLoadingState').hide();
                
                // Show error with more details
                const errorHtml = `
                    <div class="alert alert-warning text-center">
                        <i class="fas fa-exclamation-triangle fa-2x mb-2"></i>
                        <h6>Không tìm thấy Product ID</h6>
                        <p class="mb-0 small">Vui lòng chọn sản phẩm từ danh sách hoặc tạo sản phẩm mới.</p>
                        <hr>
                        <small class="text-muted">
                            Debug info:<br>
                            updateProductId: ${updateProductId || 'undefined'}<br>
                            #product_id: ${$('#product_id').val() || 'empty'}<br>
                            #baseSku: ${$('#baseSku').val() || 'empty'}<br>
                            aiData: ${typeof aiData !== 'undefined' ? (aiData.product_id || 'no product_id') : 'undefined'}
                        </small>
                    </div>
                `;
                $('#sizesCheckboxContainer').html(errorHtml);
                $('#availableSizesList').show();
                return;
            }
            
            $.ajax({
                url: '',
                method: 'POST',
                data: {
                    action: 'get_available_sizes',
                    product_id: productId
                },
                dataType: 'json',
                success: function(response) {
                    console.log('✅ Sizes loaded:', response);
                    $('#sizesLoadingState').hide();
                    
                    if (response.success && response.sizes && response.sizes.length > 0) {
                        displayAvailableSizes(response.sizes);
                    } else {
                        console.log('ℹ️ No sizes found or empty response');
                        const noSizesHtml = `
                            <div class="alert alert-info text-center">
                                <i class="fas fa-info-circle fa-2x mb-2"></i>
                                <h6>Chưa có size nào</h6>
                                <p class="mb-0 small">Sản phẩm này chưa có size variants. Bạn có thể thêm size sau khi tạo sản phẩm.</p>
                            </div>
                        `;
                        $('#sizesCheckboxContainer').html(noSizesHtml);
                        $('#availableSizesList').show();
                    }
                },
                error: function(xhr, status, error) {
                    $('#sizesLoadingState').hide();
                    console.error('❌ Failed to load available sizes:', error);
                    console.error('Response:', xhr.responseText);
                    
                    let errorMessage = 'Lỗi kết nối';
                    try {
                        const errorResponse = JSON.parse(xhr.responseText);
                        errorMessage = errorResponse.message || errorMessage;
                    } catch (e) {
                        if (xhr.responseText.includes('Fatal error') || xhr.responseText.includes('Parse error')) {
                            const errorMatch = xhr.responseText.match(/(Fatal error|Parse error)[^<]*/);
                            if (errorMatch) {
                                errorMessage = errorMatch[0];
                            }
                        }
                    }
                    
                    const errorHtml = `
                        <div class="alert alert-danger text-center">
                            <i class="fas fa-exclamation-triangle fa-2x mb-2"></i>
                            <h6>Lỗi tải danh sách size</h6>
                            <p class="mb-0 small">${errorMessage}</p>
                            <hr>
                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="loadAvailableSizes()">
                                <i class="fas fa-sync mr-1"></i> Thử lại
                            </button>
                        </div>
                    `;
                    $('#sizesCheckboxContainer').html(errorHtml);
                    $('#availableSizesList').show();
                }
            });
        }
        
        function displayAvailableSizes(sizes) {
            console.log('📋 Displaying available sizes for multiple selection:', sizes);
            
            const container = $('#sizesCheckboxContainer');
            container.empty();
            
            // Create checkbox grid for multiple selection
            const checkboxHtml = `
                <div class="col-12">
                    <div class="card border-primary shadow-sm">
                        <div class="card-header bg-gradient-primary text-white">
                            <h5 class="mb-0">
                                <i class="fas fa-shoe-prints mr-2"></i> Chọn Size Cần Nhập
                            </h5>
                        </div>
                        <div class="card-body py-4">
                            <div class="form-group mb-4">
                                <label class="font-weight-bold text-primary mb-3" style="font-size: 1.1rem;">
                                    <i class="fas fa-check-square mr-2"></i> Kích thước có sẵn (chọn nhiều):
                                </label>
                                
                                <div class="row" id="sizeCheckboxGrid">
                                    ${sizes.map(sizeData => `
                                        <div class="col-6 col-md-4 col-lg-3 mb-3">
                                            <div class="card size-option h-100" data-size="${sizeData.size}">
                                                <div class="card-body p-3 text-center">
                                                    <div class="custom-control custom-checkbox">
                                                        <input type="checkbox" class="custom-control-input size-checkbox" 
                                                               id="size_${sizeData.size}" value="${sizeData.size}"
                                                               data-variant-id="${sizeData.variant_id || ''}"
                                                               data-sku="${sizeData.sku || ''}"
                                                               data-quantity="${sizeData.quantity || 0}"
                                                               data-unit-price="${sizeData.unit_price || 0}"
                                                               data-sale-price="${sizeData.sale_price || 0}">
                                                        <label class="custom-control-label w-100" for="size_${sizeData.size}">
                                                            <div class="size-info">
                                                                <div class="size-number font-weight-bold text-primary mb-2" style="font-size: 1.5rem;">
                                                                    ${sizeData.size}
                                                                </div>
                                                                <div class="size-details">
                                                                    <small class="text-muted d-block mb-1">
                                                                        <i class="fas fa-warehouse"></i> Tồn: <strong>${sizeData.quantity || 0}</strong>
                                                                    </small>
                                                                    <small class="text-primary d-block mb-1">
                                                                        <i class="fas fa-shopping-cart"></i> Nhập: <strong>${sizeData.unit_price > 0 ? formatPrice(sizeData.unit_price) + ' ₫' : 'Chưa có'}</strong>
                                                                    </small>
                                                                    <small class="text-success d-block">
                                                                        <i class="fas fa-tag"></i> Bán: <strong>${sizeData.sale_price > 0 ? formatPrice(sizeData.sale_price) + ' ₫' : 'Chưa có'}</strong>
                                                                    </small>
                                                                </div>
                                                            </div>
                                                        </label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    `).join('')}
                                </div>
                            </div>
                            
                            <div class="row align-items-center mt-4">
                                <div class="col-md-6">
                                    <div class="selection-summary">
                                        <span class="badge badge-info badge-pill" style="font-size: 1rem; padding: 8px 15px;">
                                            <i class="fas fa-check-square mr-2"></i>
                                            <span id="selectedSizesCount">0</span> size đã chọn
                                        </span>
                                    </div>
                                </div>
                                <div class="col-md-6 text-md-right mt-3 mt-md-0">
                                    <button type="button" class="btn btn-success btn-lg px-4 py-2" id="confirmMultipleSizes" disabled>
                                        <i class="fas fa-check-circle mr-2"></i> Xác nhận các size đã chọn
                                    </button>
                                </div>
                            </div>
                            
                            <div class="alert alert-info mt-4 mb-0">
                                <i class="fas fa-info-circle mr-2"></i>
                                <strong>Hướng dẫn:</strong> Chọn 1 hoặc nhiều size, sau đó các thông tin chi tiết sẽ hiện ra để bạn nhập từng size.
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            container.html(checkboxHtml);
            $('#availableSizesList').show();
            
            // Initialize counters and button state
            setTimeout(() => {
                updateSelectedSizesCount();
                toggleConfirmButton();
                console.log('🔧 Button state initialized');
            }, 100);
        }
        
        function updateSelectedSizesCount() {
            const selectedCount = $('.size-checkbox:checked').length;
            $('#selectedSizesCount').text(selectedCount);
        }
        
        function toggleConfirmButton() {
            const selectedCount = $('.size-checkbox:checked').length;
            const $button = $('#confirmMultipleSizes');
            
            console.log('🔧 toggleConfirmButton - Selected count:', selectedCount);
            console.log('🔧 Button found:', $button.length > 0);
            
            if ($button.length > 0) {
                $button.prop('disabled', selectedCount === 0);
                console.log('🔧 Button disabled state:', selectedCount === 0);
            } else {
                console.error('❌ Button #confirmMultipleSizes not found!');
            }
        }
        
        function getSelectedSizes() {
            const selectedSizes = [];
            $('.size-checkbox:checked').each(function() {
                const $checkbox = $(this);
                selectedSizes.push({
                    variant_id: parseInt($checkbox.data('variant-id')) || null,
                    sku: $checkbox.data('sku') || '',
                    size: $checkbox.val(),
                    quantity: parseInt($checkbox.data('quantity')) || 0,
                    unit_price: parseFloat($checkbox.data('unit-price')) || 0,
                    sale_price: parseFloat($checkbox.data('sale-price')) || 0
                });
            });
            return selectedSizes;
        }
        
        // Display selected sizes with advanced layout
        function displaySelectedSizes(selectedSizes) {
            console.log('🎯 Displaying selected sizes with smart locations and pricing:', selectedSizes);
            
            // Hide size selection area
            $('#sizeSelectionArea').hide();
            
            // Clear existing sizes
            $('#sizesContainer').empty();
            
            // Show header
            $('.d-none.d-md-block').show();
            
            // Add selected sizes to the form with smart logic
            selectedSizes.forEach(sizeData => {
                // Calculate sale price with 30% minimum markup
                const autoSalePrice = calculateSalePrice(sizeData.unit_price, sizeData.sale_price);
                
                addSizeRowAdvanced(
                    sizeData.size,
                    sizeData.quantity,
                    sizeData.unit_price,   // unit price (giá nhập) from database
                    autoSalePrice,         // auto-calculated sale price
                    false,                 // allow editing
                    0,                     // default import quantity
                    sizeData.variant_id,   // variant ID for location lookup
                    sizeData.sku           // SKU for reference
                );
            });
            
            // Show success message with smart features info
            const successHtml = `
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle mr-2"></i>
                    <strong>Đã chọn ${selectedSizes.length} size:</strong> 
                    ${selectedSizes.map(s => s.size).join(', ')}
                    <button type="button" class="close" data-dismiss="alert">
                        <span>&times;</span>
                    </button>
                    <div class="mt-2 row">
                        <div class="col-md-6">
                            <button type="button" class="btn btn-sm btn-outline-primary" id="reselectSizes">
                                <i class="fas fa-edit mr-1"></i> Chọn lại size
                            </button>
                        </div>
                        <div class="col-md-6">
                            <small class="text-muted d-block">
                                <i class="fas fa-magic text-primary"></i> Vị trí lưu trữ & giá bán tự động
                                <br><i class="fas fa-calculator text-success"></i> Giá bán >= Giá nhập + 30%
                            </small>
                        </div>
                    </div>
                </div>
            `;
            $('#sizesContainer').before(successHtml);
            
            // Add smart location regeneration button
            const toolsHtml = `
                <div class="card mb-3 border-info">
                    <div class="card-body p-2">
                        <div class="row align-items-center">
                            <div class="col-md-8">
                                <small class="text-info">
                                    <i class="fas fa-lightbulb"></i> 
                                    <strong>Hệ thống thông minh:</strong> Tự động tạo vị trí lưu trữ cho từng SKU và tính giá bán
                                </small>
                            </div>
                            <div class="col-md-4 text-right">
                                <button type="button" class="btn btn-sm btn-outline-info" id="regenerateLocations">
                                    <i class="fas fa-sync mr-1"></i> Tạo lại vị trí
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            $('#sizesContainer').before(toolsHtml);
        }
        
        // Function to add a size row with individual pricing and import quantity (advanced layout)
        function addSizeRowAdvanced(size, quantity, originalPrice = 0, salePrice = 0, isReadOnly = false, importQuantity = 0, variantId = null, sku = '') {
            const baseSku = $('#baseSku').val();
            const fullSku = sku || (baseSku ? `${baseSku}-${size}` : '');
            
            // Hide the checkbox for this size in the selection area
            $(`.size-option[data-size="${size}"]`).hide();
            $(`#size_${size}`).prop('checked', false);
            
            const sizeRowHtml = `
                <div class="size-row mb-3" data-size="${size}" data-variant-id="${variantId || ''}" data-sku="${fullSku}">
                    <div class="card shadow-sm">
                        <div class="card-body p-3">
                            <div class="row">
                                <!-- Size Column -->
                                <div class="col-6 col-md-2 mb-2 mb-md-0">
                                    <label class="form-label font-weight-bold text-primary mb-1 d-block">
                                        <i class="fas fa-tag"></i> Size
                                    </label>
                                    <input type="text" class="form-control size-input text-center font-weight-bold" 
                                           name="sizes[]" value="${size}" readonly 
                                           style="background: #f8f9fc; font-size: 1.1rem; height: 38px;">
                                </div>
                                
                                <!-- Stock Column -->
                                <div class="col-6 col-md-2 mb-2 mb-md-0">
                                    <label class="form-label font-weight-bold text-info mb-1 d-block">
                                        <i class="fas fa-warehouse"></i> Tồn kho
                                    </label>
                                    <input type="number" class="form-control quantity-input text-center" 
                                           value="${quantity}" readonly 
                                           style="background: #e3f2fd; height: 38px;">
                                    <small class="text-muted d-block text-center">hiện tại</small>
                                </div>
                                
                                <!-- Import Quantity Column -->
                                <div class="col-6 col-md-2 mb-2 mb-md-0">
                                    <label class="form-label font-weight-bold text-danger mb-1 d-block">
                                        <i class="fas fa-plus-circle"></i> Nhập <span class="text-danger">*</span>
                                    </label>
                                    <div class="input-group">
                                        <input type="number" class="form-control import-quantity-input text-center font-weight-bold" 
                                               name="import_quantities[]" value="${importQuantity}" min="0" placeholder="0" required
                                               style="border-color: #dc3545; height: 38px;">
                                        <div class="input-group-append">
                                            <span class="input-group-text bg-danger text-white" style="height: 38px;">
                                                <i class="fas fa-boxes"></i>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Original Price Column -->
                                <div class="col-6 col-md-2 mb-2 mb-md-0">
                                    <label class="form-label font-weight-bold text-warning mb-1 d-block">
                                        <i class="fas fa-money-bill-wave"></i> Giá nhập
                                    </label>
                                    <div class="input-group">
                                        <input type="text" class="form-control original-price-input price-format-input text-right" 
                                               name="original_prices[]" value="${originalPrice > 0 ? new Intl.NumberFormat('vi-VN').format(originalPrice) : ''}" 
                                               data-raw-value="${originalPrice || 0}" placeholder="0" style="height: 38px;">
                                        <div class="input-group-append">
                                            <span class="input-group-text" style="height: 38px;">₫</span>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Sale Price Column -->
                                <div class="col-6 col-md-2 mb-2 mb-md-0">
                                    <label class="form-label font-weight-bold text-success mb-1 d-block">
                                        <i class="fas fa-hand-holding-usd"></i> Giá bán
                                    </label>
                                    <div class="input-group">
                                        <input type="text" class="form-control sale-price-input price-format-input text-right" 
                                               name="sale_prices[]" value="${salePrice > 0 ? new Intl.NumberFormat('vi-VN').format(salePrice) : ''}" 
                                               data-raw-value="${salePrice || 0}" placeholder="0" style="height: 38px;">
                                        <div class="input-group-append">
                                            <span class="input-group-text" style="height: 38px;">₫</span>
                                        </div>
                                    </div>
                                    <small class="text-muted d-block text-center">
                                        <i class="fas fa-calculator"></i> +30%
                                    </small>
                                </div>
                                
                                <!-- SKU Column -->
                                <div class="col-6 col-md-1 mb-2 mb-md-0">
                                    <label class="form-label font-weight-bold text-secondary mb-1 d-block">
                                        <i class="fas fa-barcode"></i> SKU
                                    </label>
                                    <div class="input-group">
                                        <input type="text" class="form-control sku-input text-center" name="skus[]" 
                                               value="${fullSku}" readonly 
                                               style="background: #f8f9fc; font-family: monospace; font-size: 0.85rem; height: 38px;">
                                    </div>
                                </div>
                                
                                <!-- Storage Location Column -->
                                <div class="col-6 col-md-1">
                                    <label class="form-label font-weight-bold text-purple mb-1 d-block">
                                        <i class="fas fa-map-marker-alt"></i> Vị trí
                                    </label>
                                    <div class="input-group">
                                        <input type="text" class="form-control storage-location-input text-center" 
                                               name="storage_locations[]" value="" 
                                               style="background: #f3e5f5; font-family: monospace; font-size: 0.85rem; height: 38px;"
                                               title="Vị trí lưu trữ thông minh" required>
                                        <div class="input-group-append">
                                            <button type="button" class="btn btn-info btn-sm suggestion-btn-for-size" 
                                                    data-size="${size}" 
                                                    title="Gợi ý vị trí thông minh cho size ${size}" 
                                                    style="height: 38px;">
                                                <i class="fas fa-lightbulb"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Delete Button Column -->
                                <div class="col-12 col-md-12 text-right mt-2">
                                    <button type="button" class="btn btn-danger btn-sm remove-size-row-btn" title="Xóa size này">
                                        <i class="fas fa-trash-alt"></i> Xóa
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            const $sizeRow = $(sizeRowHtml);
            $('#sizesContainer').append($sizeRow);
            
            // Set default location first (will be replaced by smart location)
            const $locationInput = $sizeRow.find('.storage-location-input');
            $locationInput.val('⏳ Đang lấy vị trí...').prop('disabled', true);
            
            // Generate smart storage location for this size (async)
            const productName = $('#productName').val() || 'Product';
            
            console.log('🏢 Calling generateSmartStorageLocation with:', {
                productName,
                size,
                variantId,
                fullSku
            });
            
            generateSmartStorageLocation(productName, size, variantId, fullSku)
                .then(result => {
                    console.log('✅ Location result:', result);
                    
                    $locationInput.val(result.location_code).prop('disabled', false);
                    
                    // Add visual indicator for reused locations
                    if (result.is_reused) {
                        $locationInput
                            .css('background-color', '#e8f5e9')
                            .attr('title', '♻️ ' + result.message);
                        $sizeRow.find('.input-group-text i')
                            .removeClass('fa-magic')
                            .addClass('fa-recycle')
                            .attr('title', 'Sử dụng lại vị trí cũ');
                    } else {
                        $locationInput
                            .attr('title', '✨ ' + result.message);
                    }
                    
                    console.log('✅ Location assigned:', result.location_code, result.message);
                })
                .catch(error => {
                    console.error('❌ Error getting location:', error);
                    // Fallback to A-1-00 if error
                    $locationInput.val('A-1-00').prop('disabled', false);
                });
            
            // Auto-calculate sale price
            const autoSalePrice = calculateSalePrice(originalPrice, salePrice);
            $sizeRow.find('.sale-price-input').val(autoSalePrice);
            
            // Add pricing validation styles
            if (originalPrice > 0) {
                if (validateSalePrice(originalPrice, autoSalePrice)) {
                    $sizeRow.find('.sale-price-input').addClass('border-success');
                } else {
                    $sizeRow.find('.sale-price-input').addClass('border-warning');
                }
            }
        }
        
        // Handle reselect sizes
        $(document).on('click', '#reselectSizes', function() {
            // Use the general function to show all sizes
            showAllSizes();
            
            // Show selection area
            $('#sizeSelectionArea').show();
            
            // Clear size rows
            $('#sizesContainer').empty();
            
            // Hide header
            $('.d-none.d-md-block').hide();
            
            // Remove alert
            $(this).closest('.alert').remove();
            $(this).closest('.card').remove(); // Remove tools card too
        });
        
        // Handle regenerate storage locations
        $(document).on('click', '#regenerateLocations', async function() {
            const productName = $('#productName').val() || 'Product';
            
            // Process each row sequentially to avoid race conditions
            const rows = $('.size-row').toArray();
            for (const row of rows) {
                const $row = $(row);
                const size = $row.find('.size-input').val();
                const variantId = $row.data('variant-id') || null;
                const sku = $row.data('sku') || '';
                
                try {
                    const result = await generateSmartStorageLocation(productName, size, variantId, sku);
                    
                    const $locationInput = $row.find('.storage-location-input');
                    $locationInput.val(result.location_code);
                    
                    // Add visual feedback - different colors for reused vs new
                    const bgColor = result.is_reused ? '#e8f5e9' : '#fff3e0';
                    $locationInput.css('background-color', bgColor);
                    $locationInput.attr('title', result.message);
                    
                    // Update icon
                    if (result.is_reused) {
                        $row.find('.input-group-text i')
                            .removeClass('fa-magic')
                            .addClass('fa-recycle')
                            .attr('title', 'Sử dụng lại vị trí cũ');
                    }
                    
                    setTimeout(() => {
                        $locationInput.css('background-color', '');
                    }, 2000);
                } catch (error) {
                    console.error('Failed to allocate location for size', size, error);
                }
            }
            
            // Show feedback message
            const $button = $(this);
            const originalText = $button.html();
            $button.html('<i class="fas fa-check mr-1"></i> Đã cập nhật!').prop('disabled', true);
            
            setTimeout(() => {
                $button.html(originalText).prop('disabled', false);
            }, 2000);
        });
        
        // Smart Storage Location Generation - Uses Real Database Locations
        async function generateSmartStorageLocation(productName, size, variantId = null, sku = '') {
            console.log('🏢 Getting location for:', productName, 'Size:', size, 'Variant ID:', variantId);
            
            try {
                // Lấy product_type từ form
                const productType = $('#productType').val() || $('#productTypeText').val() || '';
                
                const response = await $.ajax({
                    url: 'create_new_stock_receipt.php',
                    method: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'get_or_allocate_location',
                        warehouse_id: userWarehouseId,
                        variant_id: variantId,
                        sku: sku,
                        product_name: productName,
                        size: size,
                        product_type: productType,
                        excluded_locations: excludedLocations // Loại trừ vị trí đã dùng trong phiếu hiện tại
                    }
                });
                
                if (response.success && response.location_code) {
                    if (response.is_reused) {
                        console.log('♻️ Reusing existing location:', response.location_code, '(variant_id:', variantId + ')');
                    } else {
                        console.log('✨ Allocated new location:', response.location_code);
                    }
                    return {
                        location_code: response.location_code,
                        is_reused: response.is_reused,
                        message: response.message
                    };
                } else {
                    console.warn('⚠️ Failed to get location:', response.message);
                    return {
                        location_code: 'DEFAULT',
                        is_reused: false,
                        message: 'Fallback to default'
                    };
                }
            } catch (error) {
                console.error('❌ Error getting location:', error);
                return {
                    location_code: 'DEFAULT',
                    is_reused: false,
                    message: 'Error occurred'
                };
            }
        }

        // Auto-calculate sale price with minimum 30% markup
        function calculateSalePrice(purchasePrice, currentSalePrice = 0) {
            const price = parseFloat(purchasePrice) || 0;
            const minSalePrice = price * 1.3; // 30% markup minimum
            
            // If no current sale price or it's below minimum, use minimum
            if (!currentSalePrice || currentSalePrice < minSalePrice) {
                return Math.round(minSalePrice);
            }
            
            return currentSalePrice;
        }

        // Validate sale price meets minimum requirement
        function validateSalePrice(purchasePrice, salePrice) {
            const price = parseFloat(purchasePrice) || 0;
            const sale = parseFloat(salePrice) || 0;
            const minRequired = price * 1.3;
            
            return sale >= minRequired;
        }
        
        function formatPrice(price) {
            return new Intl.NumberFormat('vi-VN').format(price);
        }

        // Enhanced modal handlers
        // Choose Content Modal Handlers
        $(document).on('click', '.content-option', function() {
            $('.content-option').removeClass('border-primary').removeClass('selected');
            $(this).addClass('border-primary').addClass('selected');
            $('#confirmContentChoice').prop('disabled', false);
        });

        $('#confirmContentChoice').click(function() {
            const selectedOption = $('.content-option.selected').data('option');
            const productInfo = window.selectedUpdateProduct;
            
            if (!selectedOption || !productInfo) {
                alert('Vui lòng chọn một tùy chọn');
                return;
            }
            
            // Đóng modal
            $('#chooseContentModal').modal('hide');
            
            if (selectedOption === 'existing') {
                // Sử dụng thông tin từ sản phẩm hiện có
                useExistingDataOnly = true;
                loadExistingProductData(productInfo.id, productInfo.name);
            } else if (selectedOption === 'ai') {
                // Sử dụng thông tin từ AI phân tích
                useExistingDataOnly = false;
                loadExistingProductDataWithAI(productInfo.id, productInfo.name);
            }
        });

        // Reset choose content modal when closed
        $('#chooseContentModal').on('hidden.bs.modal', function() {
            $('.content-option').removeClass('border-primary').removeClass('selected');
            $('#confirmContentChoice').prop('disabled', true);
            useExistingDataOnly = false;
        });
        
        // Enhanced Receipt Summary Functions for step 5
        // NOTE: saveReceiptDraft() is now handled by event handler in document.ready
        // This old function is kept for reference but not used
        /*
        function saveReceiptDraft() {
            console.log('💾 Saving receipt draft...');
            
            const receiptData = collectReceiptData();
            if (!receiptData.products || receiptData.products.length === 0) {
                alert('Không có sản phẩm nào để lưu');
                return;
            }
            
            $.ajax({
                url: '',
                method: 'POST',
                data: {
                    action: 'save_receipt_draft',
                    receipt_data: JSON.stringify(receiptData),
                    warehouse_id: currentWarehouseId
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        showToast('Đã lưu nháp phiếu nhập hàng thành công', 'success');
                        
                        // Store draft ID for later use
                        window.currentDraftId = response.draft_id;
                        
                        // Enable finalize button
                        $('#finalizeReceiptBtn').prop('disabled', false);
                        
                        // Update UI to show draft saved
                        $('#draftStatus').html(`
                            <div class="alert alert-info">
                                <i class="fas fa-save mr-2"></i>
                                Nháp đã được lưu (ID: ${response.draft_id})
                            </div>
                        `);
                    } else {
                        showToast('Lỗi khi lưu nháp: ' + (response.message || 'Unknown error'), 'error');
                    }
                },
                error: function() {
                    showToast('Lỗi kết nối khi lưu nháp', 'error');
                }
            });
        }
        */
        
        // Collect all receipt data from forms
        function collectReceiptData() {
            const products = [];
            
            $('.product-summary-card').each(function() {
                const $card = $(this);
                const productData = {
                    id: $card.data('product-id'),
                    name: $card.find('.product-name').text(),
                    base_sku: $card.data('base-sku'),
                    sizes: []
                };
                
                // Collect size data
                $card.find('.size-detail-row').each(function() {
                    const $row = $(this);
                    const sizeData = {
                        size: $row.find('.size-cell').text(),
                        import_quantity: parseInt($row.find('.import-quantity-cell').text()) || 0,
                        purchase_price: parseFloat($row.find('.purchase-price-cell').text().replace(/[₫,]/g, '')) || 0,
                        sale_price: parseFloat($row.find('.sale-price-cell').text().replace(/[₫,]/g, '')) || 0,
                        sku: $row.find('.sku-cell').text(),
                        storage_location: $row.find('.location-cell').text()
                    };
                    
                    if (sizeData.import_quantity > 0) {
                        productData.sizes.push(sizeData);
                    }
                });
                
                if (productData.sizes.length > 0) {
                    products.push(productData);
                }
            });
            
            return {
                supplier_id: $('#supplierId').val(),
                note: $('#receiptNote').val(),
                products: products,
                created_at: new Date().toISOString()
            };
        }
        
        // Finalize receipt - convert draft to actual receipt
        function finalizeReceipt() {
            console.log('✅ Finalizing receipt...');
            
            if (!window.currentDraftId) {
                alert('Vui lòng lưu nháp trước khi hoàn tất');
                return;
            }
            
            // Show confirmation dialog
            if (!confirm('Bạn có chắc chắn muốn hoàn tất phiếu nhập hàng này? Sau khi hoàn tất sẽ không thể chỉnh sửa.')) {
                return;
            }
            
            // Show loading state
            $('#finalizeReceiptBtn').prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-2"></i>Đang xử lý...');
            
            $.ajax({
                url: '',
                method: 'POST',
                data: {
                    action: 'finalize_receipt',
                    draft_id: window.currentDraftId,
                    warehouse_id: currentWarehouseId
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        showToast('Phiếu nhập hàng đã được hoàn tất thành công!', 'success');
                        
                        // Show success message with receipt details
                        const successHtml = `
                            <div class="alert alert-success">
                                <h5><i class="fas fa-check-circle mr-2"></i>Phiếu nhập hàng hoàn tất!</h5>
                                <p class="mb-2">Mã phiếu: <strong>${response.receipt_id}</strong></p>
                                <p class="mb-2">Tổng sản phẩm: <strong>${response.total_products}</strong></p>
                                <p class="mb-0">Tổng số lượng: <strong>${response.total_quantity}</strong></p>
                                <hr>
                                <div class="row">
                                    <div class="col-md-6">
                                        <button type="button" class="btn btn-primary" onclick="viewReceipt('${response.receipt_id}')">
                                            <i class="fas fa-eye mr-2"></i>Xem phiếu nhập
                                        </button>
                                    </div>
                                    <div class="col-md-6 text-right">
                                        <button type="button" class="btn btn-success" onclick="createNewReceipt()">
                                            <i class="fas fa-plus mr-2"></i>Tạo phiếu mới
                                        </button>
                                    </div>
                                </div>
                            </div>
                        `;
                        
                        $('#receiptSummaryContainer').html(successHtml);
                        
                        // Hide form steps
                        $('.step-container').hide();
                        $('#navigationButtons').hide();
                        
                    } else {
                        showToast('Lỗi khi hoàn tất phiếu: ' + (response.message || 'Unknown error'), 'error');
                        $('#finalizeReceiptBtn').prop('disabled', false).html('<i class="fas fa-check-circle mr-2"></i>Hoàn tất phiếu nhập');
                    }
                },
                error: function() {
                    showToast('Lỗi kết nối khi hoàn tất phiếu', 'error');
                    $('#finalizeReceiptBtn').prop('disabled', false).html('<i class="fas fa-check-circle mr-2"></i>Hoàn tất phiếu nhập');
                }
            });
        }
        
        // View receipt details
        function viewReceipt(receiptId) {
            window.open(`view_receipt.php?id=${receiptId}`, '_blank');
        }
        
        // Create new receipt
        function createNewReceipt() {
            if (confirm('Bạn có muốn tạo phiếu nhập hàng mới? Trang sẽ được tải lại.')) {
                window.location.reload();
            }
        }
        
        // Enhanced pricing calculations
        $(document).on('input', '.original-price-input', function() {
            const $row = $(this).closest('.size-row');
            const originalPrice = parseFloat($(this).data('raw-value') || $(this).val().replace(/[^\d]/g, '')) || 0;
            const $saleInput = $row.find('.sale-price-input');
            
            if (originalPrice > 0) {
                const autoSalePrice = calculateSalePrice(originalPrice, 0);
                $saleInput.val(autoSalePrice);
                
                // Validate pricing
                if (validateSalePrice(originalPrice, autoSalePrice)) {
                    $saleInput.removeClass('border-warning').addClass('border-success');
                } else {
                    $saleInput.removeClass('border-success').addClass('border-warning');
                }
            }
        });
        
        $(document).on('input', '.sale-price-input', function() {
            const $row = $(this).closest('.size-row');
            const originalPriceInput = $row.find('.original-price-input');
            const originalPrice = parseFloat(originalPriceInput.data('raw-value') || originalPriceInput.val().replace(/[^\d]/g, '')) || 0;
            const salePrice = parseFloat($(this).data('raw-value') || $(this).val().replace(/[^\d]/g, '')) || 0;
            
            if (originalPrice > 0 && salePrice > 0) {
                if (validateSalePrice(originalPrice, salePrice)) {
                    $(this).removeClass('border-warning').addClass('border-success');
                } else {
                    $(this).removeClass('border-success').addClass('border-warning');
                }
            }
        });
        
        // Update Source Modal handlers
        $('#updateSourceModal').on('show.bs.modal', function() {
            // Reset card selection
            $('.update-source-option').removeClass('selected border-primary');
            $('#confirmUpdateSource').prop('disabled', true);
        });
        
        // Handle update source option clicks
        $(document).on('click', '.update-source-option', function() {
            console.log('🎯 Update source card clicked');
            
            // Remove selection from all cards
            $('.update-source-option').removeClass('selected border-primary');
            
            // Add selection to clicked card
            $(this).addClass('selected border-primary');
            
            // Get selected source
            const selectedSource = $(this).data('source');
            console.log('✅ Selected source:', selectedSource);
            
            // Enable confirm button
            $('#confirmUpdateSource').prop('disabled', false);
        });
        
        $('#confirmUpdateSource').click(function() {
            console.log('🎯 Confirm update source clicked');
            
            const selectedCard = $('.update-source-option.selected');
            console.log('📋 Selected cards found:', selectedCard.length);
            
            if (selectedCard.length === 0) {
                console.error('❌ No card selected');
                alert('Vui lòng chọn nguồn cập nhật');
                return;
            }
            
            const selectedSource = selectedCard.data('source');
            console.log('✅ Selected source:', selectedSource);
            
            // Remove focus to avoid aria-hidden warning then hide modal
            $(this).blur();
            $('#updateSourceModal').modal('hide');
            
            // Set update flags early if product is known
            const productInfo = window.selectedProductForReceipt || window.selectedUpdateProduct;
            if (productInfo && (productInfo.product_id || productInfo.id)) {
                window.isUpdateMode = true;
                window.updateProductId = productInfo.product_id || productInfo.id;
                
                // Ensure both variables are set for compatibility
                window.selectedUpdateProduct = {
                    id: productInfo.product_id || productInfo.id,
                    name: productInfo.name
                };
            }

            if (selectedSource === 'existing') {
                console.log('🔄 Processing existing data source');
                updateFromExistingData();
            } else if (selectedSource === 'ai') {
                console.log('🤖 Processing AI data source');
                updateFromAIData();
            } else {
                console.error('❌ Unknown source type:', selectedSource);
                showToast('Lỗi: Nguồn dữ liệu không hợp lệ', 'error');
            }
        });
        
        // Reset modal when closed
        $('#updateSourceModal').on('hidden.bs.modal', function() {
            $('.update-source-option').removeClass('selected border-primary');
            $('#confirmUpdateSource').prop('disabled', true);
        });
        
        // Note: showStep4Loading and removeStep4Loading are defined globally above
        
        // Define functions before they are called
        function updateFromAIData() {
            console.log('🤖 Updating from AI data...');
            
            // Use existing continueWithNewStockReceiptProduct for AI data
            const productData = window.selectedProductForReceipt || window.selectedUpdateProduct;
            if (productData) {
                console.log('📋 Using AI mode with product:', productData);
                continueWithNewStockReceiptProduct(productData, 'ai');
            } else {
                alert('Lỗi: Không tìm thấy thông tin sản phẩm');
            }
        }
        
        // Legacy function - now just calls the global version
        function updateFromExistingData() {
            console.log('📋 Calling global updateFromExistingData function...');
            
            // Call the global function
            window.updateFromExistingData();
        }
        
        function updateFromAIData() {
            console.log('🤖 Updating from AI data...');
            
            const productInfo = window.selectedUpdateProduct;
            if (!productInfo) {
                console.error('❌ No product info available');
                showToast('Lỗi: Không tìm thấy thông tin sản phẩm', 'error');
                return;
            }
            
            // Move to step 4 and show loading
            moveToStep(4);
            showStep4Loading('Đang chuẩn bị dữ liệu từ AI...');
            
            // Use AI data to fill form (simulate async operation)
            setTimeout(function() {
                removeStep4Loading();
                
                if (window.aiProductData) {
                    fillFormWithProductData(window.aiProductData);
                    showToast('✅ Đã tải thông tin từ AI', 'success');
                } else {
                    showToast('Lỗi: Không tìm thấy dữ liệu AI', 'error');
                }
            }, 500); // Small delay to show loading state
        }

        // Event handlers for step 5 buttons
        $(document).on('click', '#addMoreItems', function() {
            // Reset form to add more items
            resetFormToStep1();
            showToast('Sẵn sàng thêm sản phẩm mới vào phiếu nhập', 'info');
        });

        $(document).on('click', '#backToDetails', function() {
            // Go back to step 4 for editing
            moveToStep(4);
        });
        
        // Register event handler for "Lưu bản nháp" button
        console.log('📝 Registering event handler for #saveDraftBtn');
        $(document).on('click', '#saveDraftBtn', function(e) {
            e.preventDefault(); // Prevent any default action
            console.log('💾💾💾 Save Draft button clicked!!! 💾💾💾');
            console.log('🔍 Event object:', e);
            console.log('🔍 Button element:', this);
            console.log('🔍 Current selectedSupplier:', selectedSupplier);
            console.log('🔍 Current receiptItems:', receiptItems);
            console.log('🔍 receiptItems length:', receiptItems ? receiptItems.length : 'undefined');
            
            // If receiptItems is empty, try to get from form data in step 4
            if (!receiptItems || receiptItems.length === 0) {
                console.warn('⚠️ receiptItems is empty, trying to collect from form...');
                receiptItems = [];
                
                // Collect data from size rows in step 4
                $('.size-row').each(function() {
                    const $row = $(this);
                    const size = $row.find('input[name="sizes[]"]').val();
                    const quantity = $row.find('input[name="import_quantities[]"]').val();
                    const sku = $row.find('input[name="skus[]"]').val();
                    // Get raw values from formatted inputs
                    const priceInput = $row.find('input[name="original_prices[]"]');
                    const salePriceInput = $row.find('input[name="sale_prices[]"]');
                    const price = priceInput.data('raw-value') || priceInput.val().replace(/[^\d]/g, '');
                    const salePrice = salePriceInput.data('raw-value') || salePriceInput.val().replace(/[^\d]/g, '');
                    const storageLocation = $row.find('input[name="storage_locations[]"]').val();
                    const productName = $('#productName').val() || 'Sản phẩm';
                    const productId = $('#product_id').val() || null;
                    
                    if (size && quantity && parseFloat(quantity) > 0) {
                        receiptItems.push({
                            product_id: productId,
                            product_name: productName,
                            size: size,
                            sku: sku || 'N/A',
                            quantity: parseFloat(quantity) || 0,
                            price: parseFloat(price) || 0,
                            sale_price: parseFloat(salePrice) || 0,
                            storage_location: storageLocation || 'DEFAULT',
                            base_sku: sku ? sku.split('-').slice(0, -1).join('-') : ''
                        });
                    }
                });
                
                console.log('✅ Collected receiptItems from form:', receiptItems);
            }
            
            // Validation
            if (!selectedSupplier || !selectedSupplier.id) {
                console.error('❌ No supplier selected');
                alert('⚠️ Vui lòng chọn nhà cung cấp ở bước 1!');
                showToast('Vui lòng chọn nhà cung cấp!', 'error');
                return;
            }
            
            if (!receiptItems || receiptItems.length === 0) {
                console.error('❌ No receipt items found');
                alert('⚠️ Không có sản phẩm nào để lưu! Vui lòng thêm sản phẩm ở bước 4.');
                showToast('Không có sản phẩm nào để lưu!', 'error');
                return;
            }
            
            console.log('✅ Validation passed, proceeding with save...');
            
            // Show loading
            const $btn = $(this);
            const originalText = $btn.html();
            $btn.html('<i class="fas fa-spinner fa-spin mr-2"></i>Đang lưu...').prop('disabled', true);
            
            // Prepare receipt data
            const receiptData = {
                supplier_id: selectedSupplier.id,
                receipt_date: $('#receiptDate').val() || '<?php echo date('Y-m-d'); ?>',
                receipt_note: $('#receiptNotes').val() || '',
                products: []
            };
            
            // Group items by product
            const productMap = {};
            receiptItems.forEach(item => {
                console.log('🔍 Processing item:', item); // Debug log
                
                if (!productMap[item.product_name]) {
                    productMap[item.product_name] = {
                        id: item.product_id || null,
                        name: item.product_name,
                        base_sku: item.base_sku || '',
                        sizes: []
                    };
                }
                
                // Log giá trị trước khi push
                console.log('💰 Price values:', {
                    import_price: item.import_price,
                    price: item.price,
                    sale_price: item.sale_price
                });
                
                productMap[item.product_name].sizes.push({
                    size: item.size,
                    import_quantity: item.quantity,
                    purchase_price: item.import_price || item.price || 0, // Sử dụng import_price
                    sale_price: item.sale_price || 0,
                    sku: item.sku,
                    storage_location: item.storage_location || ''
                });
            });
            
            receiptData.products = Object.values(productMap);
            
            console.log('📦 Sending receipt data:', receiptData);
            console.log('📦 Total products to save:', receiptData.products.length);
            console.log('📦 Warehouse ID:', <?php echo $userWarehouseId ?? 'null'; ?>);
            
            // Send AJAX request
            console.log('🚀 Starting AJAX request...');
            $.ajax({
                url: '',
                method: 'POST',
                data: {
                    action: 'save_receipt_draft',
                    receipt_data: JSON.stringify(receiptData),
                    supplier_id: selectedSupplier.id,
                    warehouse_id: <?php echo $userWarehouseId ?? 'null'; ?>,
                    receipt_date: receiptData.receipt_date,
                    receipt_note: receiptData.receipt_note
                },
                dataType: 'json',
                beforeSend: function() {
                    console.log('📡 AJAX request sending...');
                },
                success: function(response) {
                    $btn.html(originalText).prop('disabled', false);
                    
                    if (response.success) {
                        console.log('✅ Receipt saved:', response);
                        showToast(response.message, 'success');
                        
                        // Store receipt ID
                        window.currentReceiptId = response.receipt_id;
                        
                        // Show success notification with receipt ID
                        const successHtml = `
                            <div class="alert alert-success alert-dismissible fade show mt-3" role="alert">
                                <h5><i class="fas fa-check-circle mr-2"></i>Đã lưu thành công!</h5>
                                <p class="mb-0">
                                    <strong>Mã phiếu nhập:</strong> PN${String(response.receipt_id).padStart(6, '0')}<br>
                                    <strong>Trạng thái:</strong> <span class="badge badge-warning">Bản nháp</span><br>
                                    <strong>Số sản phẩm:</strong> ${receiptItems.length} items
                                </p>
                                <button type="button" class="close" data-dismiss="alert">
                                    <span>&times;</span>
                                </button>
                            </div>
                        `;
                        $('#step5-content .card-body').prepend(successHtml);
                        
                        // Optionally redirect to receipt list
                        setTimeout(() => {
                            if (confirm('Phiếu nhập đã được lưu. Bạn có muốn xem danh sách phiếu nhập không?')) {
                                window.location.href = 'stock_receipts_management.php';
                            }
                        }, 2000);
                    } else {
                        console.error('❌ Error saving receipt:', response.message);
                        showToast('Lỗi: ' + response.message, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    $btn.html(originalText).prop('disabled', false);
                    console.error('❌ AJAX Error Details:', {
                        status: status,
                        error: error,
                        responseText: xhr.responseText,
                        statusCode: xhr.status
                    });
                    
                    // Log full response text for debugging
                    console.error('📄 Full Response Text:');
                    console.error(xhr.responseText);
                    
                    let errorMsg = 'Lỗi kết nối khi lưu phiếu nhập';
                    if (xhr.responseText) {
                        try {
                            const errorData = JSON.parse(xhr.responseText);
                            errorMsg = errorData.message || errorMsg;
                        } catch (e) {
                            errorMsg += ': ' + xhr.responseText.substring(0, 100);
                        }
                    }
                    showToast(errorMsg, 'error');
                }
            });
        });
        
        $(document).on('click', '#refreshSummaryBtn', function() {
            // Refresh the summary
            console.log('🔄 Refreshing receipt summary...');
            updateReceiptSummary();
            showToast('Đã làm mới tóm tắt phiếu nhập', 'success');
        });

        // Function to reset form to step 1 for adding more items
        function resetFormToStep1() {
            console.log('🔄 Resetting form to step 1 for adding more items');
            
            // Reset form fields but keep supplier and receipt info
            $('#productName').val('');
            $('#category').val('');
            $('#description').val('');
            $('#price').val('');
            $('#tags').val('');
            $('#material').val('');
            $('#brand').val('');
            $('#features').val('');
            $('#color').val('');
            
            // Clear images
            uploadedImages = [];
            $('#imagePreview').empty();
            $('.upload-area').show();
            $('.uploaded-images-area').hide();
            
            // Clear size container
            $('#sizeContainer').empty();
            
            // Reset step indicators (nhưng không reset supplier và receipt info)
            moveToStep(2); // Về bước upload ảnh, không cần chọn lại supplier
            
            // Clear AI data
            aiData = null;
            suggestions = null;
            isUpdateMode = false;
            updateProductId = null;
            useExistingDataOnly = false;
            
            console.log('✅ Form reset completed, keeping supplier and receipt info');
        }
        
        // ===== TEST FUNCTION FOR STOCK RECEIPT SELECTION =====
        function testStockReceiptSelection() {
            console.log('🧪 Testing stock receipt selection functionality');
            
            // Create demo duplicate results
            const testDuplicates = [
                {
                    product_id: 123,
                    name: 'Giày cao gót CHARLES & KEITH quai hậu và quai ngang bóng màu Đỏ Burgundy',
                    brand: 'CHARLES & KEITH',
                    type: 'Kitten Heels',
                    color: 'Đỏ Burgundy bóng',
                    similarity: 87,
                    image_url: null,
                    created_at: '2023-10-30 11:09:35'
                },
                {
                    product_id: 456,
                    name: 'Giày cao gót nữ màu đen thương hiệu ABC',
                    brand: 'ABC Fashion',
                    type: 'High Heels',
                    color: 'Đen',
                    similarity: 72,
                    image_url: null,
                    created_at: '2023-10-25 09:15:22'
                }
            ];
            
            // Display test duplicates
            displayStockReceiptDuplicateResults(testDuplicates);
            
            alert('✅ Test hoàn thành!\n\nDanh sách sản phẩm trùng lặp demo đã được hiển thị.\nBạn có thể click nút "Chọn nhập kho" để test chức năng.');
        }

        // Function to setup price formatting for input fields
        function setupPriceFormatting() {
            // Format price on blur only (avoid cursor jumping during typing)
            $(document).on('blur', '.price-format-input', function() {
                let input = $(this);
                let value = input.val().replace(/[^\d]/g, ''); // Remove non-digits
                
                if (value === '' || value === '0') {
                    input.val('');
                    input.data('raw-value', '0');
                } else {
                    let formatted = new Intl.NumberFormat('vi-VN').format(parseInt(value));
                    input.val(formatted);
                    input.data('raw-value', value);
                }
            });
            
            // On focus, show unformatted value for easy editing
            $(document).on('focus', '.price-format-input', function() {
                let input = $(this);
                let rawValue = input.data('raw-value');
                if (rawValue && rawValue !== '0') {
                    input.val(rawValue);
                }
                // Select all after a short delay
                setTimeout(function() {
                    input.select();
                }, 50);
            });
            
            // Allow only digits during typing
            $(document).on('keypress', '.price-format-input', function(e) {
                // Allow: backspace, delete, tab, escape, enter
                if ($.inArray(e.keyCode, [46, 8, 9, 27, 13]) !== -1 ||
                    // Allow: Ctrl+A, Ctrl+C, Ctrl+V, Ctrl+X
                    (e.keyCode === 65 && e.ctrlKey === true) ||
                    (e.keyCode === 67 && e.ctrlKey === true) ||
                    (e.keyCode === 86 && e.ctrlKey === true) ||
                    (e.keyCode === 88 && e.ctrlKey === true)) {
                    return;
                }
                // Ensure that it is a number and stop the keypress
                if ((e.shiftKey || (e.keyCode < 48 || e.keyCode > 57)) && (e.keyCode < 96 || e.keyCode > 105)) {
                    e.preventDefault();
                }
            });
            
            // Update raw value on input
            $(document).on('input', '.price-format-input', function() {
                let input = $(this);
                let value = input.val().replace(/[^\d]/g, '');
                input.data('raw-value', value || '0');
            });
        }

        // Storage location suggestion functionality
        $(document).on('click', '.suggestion-btn-for-size', function() {
            const $button = $(this);
            const size = $button.data('size');
            const $row = $button.closest('.size-row, tr');
            
            console.log('🔍 Storage suggestion clicked for size:', size);
            
            suggestLocationForSize(size, $row);
        });
        
        function suggestLocationForSize(size, $targetRow) {
            console.log('=== suggestLocationForSize called ===');
            console.log('Size received:', size);
            
            // Get product information from various sources
            const productType = $('#productType').val() || $('#productTypeText').val() || '';
            const productBrand = $('#productBrand').val() || $('#brandName').val() || '';
            const productName = $('#productName').val() || '';
            
            // Get variant_id and SKU from row data
            const variantId = $targetRow.data('variant-id') || $targetRow.attr('data-variant-id') || null;
            const sku = $targetRow.data('sku') || $targetRow.attr('data-sku') || null;
            
            console.log('Variant ID:', variantId);
            console.log('SKU:', sku);
            
            if (!productType || !productBrand || !productName) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Thiếu thông tin sản phẩm',
                    text: 'Vui lòng đảm bảo có đầy đủ thông tin loại sản phẩm, thương hiệu và tên sản phẩm!',
                    confirmButtonText: 'Đã hiểu'
                });
                return;
            }
            
            // Call API with size information and variant_id
            callStorageSuggestionAPIForSize(productType, productBrand, productName, size, variantId, sku, $targetRow);
        }
        
        function callStorageSuggestionAPIForSize(productType, productBrand, productName, size, variantId, sku, $targetRow) {
            // Thu thập danh sách vị trí ĐÃ ĐƯỢC ĐIỀN vào input (các size khác)
            // KHÔNG exclude vị trí đã gợi ý nhưng chưa chọn
            const usedLocations = [];
            
            // Lấy từ tất cả input location trong bảng sizes (chỉ những vị trí ĐÃ ĐIỀN)
            $('input[name="storage_locations[]"], .size-location, .storage-location-input').each(function() {
                const locationValue = $(this).val();
                if (locationValue && locationValue.trim() !== '') {
                    // Lấy row chứa input này
                    const $thisRow = $(this).closest('tr');
                    const thisRowSize = $thisRow.data('size') || $thisRow.find('td:first').text().trim();
                    const targetRowSize = $targetRow.data('size') || $targetRow.find('td:first').text().trim();
                    
                    // Chỉ thêm nếu không phải row hiện tại
                    if (thisRowSize !== targetRowSize) {
                        const trimmedLocation = locationValue.trim();
                        // Tránh duplicate
                        if (!usedLocations.includes(trimmedLocation)) {
                            usedLocations.push(trimmedLocation);
                        }
                    }
                }
            });
            
            console.log('🚫 Excluding already used locations:', usedLocations);
            console.log('📍 Current row - Size:', size, 'SKU:', sku, 'Variant:', variantId);
            
            const requestData = {
                warehouse_id: userWarehouseId,
                product_type: productType,
                product_brand: productBrand,
                product_name: productName,
                product_size: size,
                variant_id: variantId,
                sku: sku,
                exclude_locations: usedLocations  // Loại trừ vị trí đã chọn
            };
            
            console.log('=== callStorageSuggestionAPIForSize ===');
            console.log('Request data:', requestData);
            
            // Show loading on button
            const $button = $targetRow.find('.suggestion-btn-for-size');
            const originalHtml = $button.html();
            $button.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>');
            
            $.ajax({
                url: 'api_storage_suggestions.php',
                method: 'POST',
                data: JSON.stringify(requestData),
                contentType: 'application/json',
                success: function(response) {
                    console.log('=== API Response ===');
                    console.log('Full response:', response);
                    
                    if (response.success && response.suggestions && response.suggestions.length > 0) {
                        showStorageSuggestionModalForSize(response, $targetRow);
                    } else {
                        // Show message when no suggestions found
                        const message = response.message || 'Không tìm thấy vị trí phù hợp';
                        const product = response.product || {};
                        
                        Swal.fire({
                            icon: 'warning',
                            title: 'Chưa có vị trí kho',
                            html: `
                                <div class="text-center">
                                    <p>${message}</p>
                                    ${product.type ? '<div class="alert alert-light"><strong>Sản phẩm:</strong> ' + product.type + ' - ' + product.brand + (product.size ? ' (Size: ' + product.size + ')' : '') + '</div>' : ''}
                                    <div class="alert alert-warning">
                                        <i class="fas fa-hand-point-right mr-2"></i>
                                        <strong>Tùy chọn:</strong> Bạn có thể nhập vị trí thủ công hoặc tạo vị trí mới trước.
                                    </div>
                                </div>
                            `,
                            confirmButtonText: '<i class="fas fa-edit mr-1"></i>Nhập thủ công',
                            width: '600px'
                        });
                    }
                },
                error: function(xhr, status, error) {
                    console.error('❌ Storage suggestion API error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Lỗi hệ thống',
                        text: 'Không thể kết nối đến hệ thống gợi ý vị trí. Vui lòng thử lại sau.',
                        confirmButtonText: 'Đã hiểu'
                    });
                },
                complete: function() {
                    // Restore button
                    $button.prop('disabled', false).html(originalHtml);
                }
            });
        }
        
        function showStorageSuggestionModalForSize(apiResponse, $targetRow) {
            const suggestions = apiResponse.suggestions || [];
            const productInfo = apiResponse.product || {};
            const strategy = apiResponse.strategy || {};
            
            if (suggestions.length === 0) {
                return;
            }
            
            // ✅ KHÔNG track vị trí khi chỉ hiển thị modal
            // Chỉ track khi người dùng THỰC SỰ CHỌN vị trí
            // → Kết quả: Ấn nút gợi ý nhiều lần sẽ luôn ra kết quả GIỐNG NHAU
            
            const productDisplay = productInfo.type && productInfo.brand ? 
                `${productInfo.type} - ${productInfo.brand}${productInfo.size ? ' (Size: ' + productInfo.size + ')' : ''}` :
                'Sản phẩm';
            
            // Hiển thị strategy/phase information nếu có
            let strategyInfo = '';
            if (strategy.phase) {
                // Kiểm tra nếu có conflict (vị trí cố định bị trùng)
                const hasConflict = strategy.phase.includes('conflict') || apiResponse.warning;
                const phaseIcon = hasConflict ? '⚠️' :
                                 strategy.phase.includes('tồn tại') || strategy.phase.includes('cố định') ? '🎯' : 
                                 strategy.phase.includes('cùng kệ') ? '⭐' : 
                                 strategy.phase.includes('phù hợp') ? '✅' : '📍';
                const alertClass = hasConflict ? 'alert-warning' : 'alert-info';
                
                strategyInfo = `
                    <div class="alert ${alertClass} mb-3">
                        <strong>${phaseIcon} ${strategy.phase}</strong>
                        ${strategy.description ? '<br><small>' + strategy.description + '</small>' : ''}
                    </div>
                `;
            }
            
            // Hiển thị cảnh báo conflict nếu có
            let conflictWarning = '';
            if (apiResponse.warning) {
                conflictWarning = `
                    <div class="alert alert-danger border-danger mb-3">
                        <h6 class="mb-2">
                            <i class="fas fa-exclamation-triangle mr-2"></i>
                            <strong>CẢNH BÁO TRÙNG LẶP VỊ TRÍ</strong>
                        </h6>
                        <p class="mb-0">${apiResponse.warning}</p>
                        <hr class="my-2">
                        <small>
                            <i class="fas fa-info-circle mr-1"></i>
                            Mỗi size chỉ nên có 1 vị trí cố định. Vui lòng kiểm tra lại việc chọn size trong phiếu nhập này!
                        </small>
                    </div>
                `;
            }
            
            const suggestionsHtml = suggestions.map((suggestion, index) => {
                // API trả về priority: Highest, High, Medium, Low, Critical
                const priority = suggestion.priority || 'High';
                const isHighPriority = priority === 'High' || priority === 'Highest';
                const isCritical = priority === 'Critical';
                const hasConflict = suggestion.has_conflict === true;
                
                // Màu sắc theo priority và conflict
                const priorityColor = hasConflict ? 'warning' : (isCritical ? 'danger' : 'success');
                
                // Icon theo priority
                const priorityIcon = hasConflict ? '⚠️' : (isCritical ? '❗' : '⭐');
                
                // Border highlight
                const borderClass = hasConflict ? 'border-warning border-2' : (isHighPriority ? 'border-success' : '');
                
                // Label
                const priorityLabel = hasConflict ? 'Có conflict' : (isCritical ? 'Quan trọng' : (isHighPriority ? 'Khuyến nghị' : 'Phù hợp'));
                
                // Lấy reasoning array hoặc description
                const reasoning = suggestion.reasoning ? suggestion.reasoning.join('<br>') : 
                                 (suggestion.description || 'Vị trí phù hợp cho sản phẩm này');
                
                return `
                    <div class="card mb-3 suggestion-card ${borderClass}" 
                         data-location-code="${suggestion.location_code}" 
                         data-has-conflict="${hasConflict}"
                         style="cursor: pointer; transition: all 0.2s;">
                        <div class="card-body p-3 ${hasConflict ? 'bg-light-warning' : ''}">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="card-title mb-0">
                                    <i class="fas fa-map-marker-alt text-primary mr-2"></i>
                                    <strong>${suggestion.location_code}</strong>
                                    ${isHighPriority || hasConflict ? '<span class="badge badge-' + priorityColor + ' ml-2">' + priorityIcon + ' ' + priorityLabel + '</span>' : ''}
                                </h6>
                                <span class="badge badge-${priorityColor}">${priorityIcon} ${hasConflict ? 'Vị trí cố định' : 'Gợi ý'}</span>
                            </div>
                            <div class="card-text small ${hasConflict ? 'text-danger' : 'text-muted'} mb-2">
                                ${reasoning}
                            </div>
                            ${suggestion.location_id ? `<small class="text-info">ID: ${suggestion.location_id}</small>` : ''}
                        </div>
                    </div>
                `;
            }).join('');
            
            const modalHtml = `
                <div class="modal fade" id="storageSuggestionModal" tabindex="-1" role="dialog">
                    <div class="modal-dialog modal-lg" role="document">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">
                                    <i class="fas fa-warehouse mr-2"></i>Gợi ý vị trí lưu trữ
                                </h5>
                                <button type="button" class="close" data-dismiss="modal">
                                    <span>&times;</span>
                                </button>
                            </div>
                            <div class="modal-body">
                                <div class="alert alert-light border">
                                    <strong>📦 Sản phẩm:</strong> ${productDisplay}
                                </div>
                                ${conflictWarning}
                                ${strategyInfo}
                                <h6 class="mb-3">
                                    <i class="fas fa-list mr-2"></i>Các vị trí được đề xuất 
                                    <small class="text-muted">(${suggestions.length} vị trí)</small>
                                </h6>
                                <div class="suggestions-list" style="max-height: 400px; overflow-y: auto;">
                                    ${suggestionsHtml}
                                </div>
                                <div class="text-center mt-3">
                                    <small class="text-muted">
                                        <i class="fas fa-info-circle mr-1"></i>
                                        Click vào vị trí để chọn
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            // Remove existing modal and add new one
            $('#storageSuggestionModal').remove();
            $('body').append(modalHtml);
            
            // Set up click handlers for suggestion cards
            $('.suggestion-card').on('click', function() {
                const locationCode = $(this).data('location-code');
                const hasConflict = $(this).data('has-conflict') === true || $(this).data('has-conflict') === 'true';
                
                if (locationCode) {
                    // Nếu có conflict, hiển thị cảnh báo trước khi áp dụng
                    if (hasConflict) {
                        Swal.fire({
                            icon: 'warning',
                            title: 'Cảnh báo trùng lặp vị trí',
                            html: `
                                <div class="text-left">
                                    <p><strong>Vị trí "${locationCode}"</strong> là vị trí cố định của size này nhưng đang bị trùng với size khác trong phiếu hiện tại.</p>
                                    <div class="alert alert-warning mt-3">
                                        <i class="fas fa-exclamation-triangle mr-2"></i>
                                        <strong>Lưu ý:</strong> Mỗi size chỉ nên có 1 vị trí cố định. Vui lòng kiểm tra lại:
                                        <ul class="mb-0 mt-2">
                                            <li>Có phải bạn đã chọn size này ở hàng khác?</li>
                                            <li>Hoặc size khác đang dùng nhầm vị trí này?</li>
                                        </ul>
                                    </div>
                                    <p class="mb-0 mt-2">Bạn có muốn tiếp tục chọn vị trí này không?</p>
                                </div>
                            `,
                            showCancelButton: true,
                            confirmButtonText: 'Vẫn chọn vị trí này',
                            cancelButtonText: 'Hủy',
                            confirmButtonColor: '#f39c12',
                            cancelButtonColor: '#6c757d'
                        }).then((result) => {
                            if (result.isConfirmed) {
                                applyLocationToRow(locationCode, $targetRow);
                            }
                        });
                    } else {
                        // Không có conflict, áp dụng trực tiếp
                        applyLocationToRow(locationCode, $targetRow);
                    }
                }
            });
            
            // Function để áp dụng vị trí vào row
            function applyLocationToRow(locationCode, $row) {
                // Find and update the location input in the target row
                const $locationInput = $row.find('input[name="storage_locations[]"], .storage-location-input, .size-location');
                $locationInput.val(locationCode);
                
                // ✅ CHỈ track vị trí khi người dùng CHỌN (không track khi chỉ xem)
                // Vị trí đã chọn sẽ được exclude cho các size KHÁC
                if (!window.suggestedLocations) {
                    window.suggestedLocations = [];
                }
                if (!window.suggestedLocations.includes(locationCode)) {
                    window.suggestedLocations.push(locationCode);
                    console.log('✅ Tracked selected location:', locationCode);
                }
                
                $('#storageSuggestionModal').modal('hide');
                
                Swal.fire({
                    icon: 'success',
                    title: 'Đã chọn vị trí',
                    text: `Vị trí "${locationCode}" đã được áp dụng`,
                    timer: 2000,
                    showConfirmButton: false
                });
            }
            
            // Hover effect
            $('.suggestion-card').hover(
                function() {
                    $(this).addClass('shadow-sm').css('transform', 'scale(1.02)');
                },
                function() {
                    $(this).removeClass('shadow-sm').css('transform', 'scale(1)');
                }
            );
            
            // Show modal
            $('#storageSuggestionModal').modal('show');
        }

        
        // ============================================
        // XỬ LÝ CHẾ ĐỘ THÊM SẢN PHẨM VÀO PHIẾU HIỆN CÓ
        // ============================================
        <?php if ($fromReceipt && $addMode === 'add'): ?>
        const FROM_RECEIPT_ID = <?php echo $fromReceipt; ?>;
        const ADD_TO_EXISTING_MODE = true;
        
        console.log('📝 Chế độ thêm sản phẩm vào phiếu #' + FROM_RECEIPT_ID);
        
        // ĐÃ BỎ EVENT productCreated - Dùng nút "Cập nhật phiếu nhập" để xử lý
        
        // Hiển thị nút "Lưu và thêm vào phiếu" thay vì các nút thông thường
        $(document).ready(function() {
            console.log('🔧 Configuring UI for ADD_TO_EXISTING_MODE');
            
            // Ẩn nút "Lưu bản nháp" vì đang ở chế độ thêm vào phiếu hiện có
            $('#saveDraftBtn').hide();
            console.log('👁️ Hidden #saveDraftBtn');
            
            // Chặn event handler của nút Lưu bản nháp (phòng trường hợp người dùng vẫn trigger được)
            $(document).off('click', '#saveDraftBtn');
            $(document).on('click', '#saveDraftBtn', function(e) {
                e.preventDefault();
                e.stopImmediatePropagation();
                console.warn('⚠️ Lưu bản nháp bị chặn vì đang ở chế độ thêm sản phẩm vào phiếu');
                Swal.fire({
                    icon: 'info',
                    title: 'Chế độ thêm sản phẩm',
                    text: 'Bạn đang thêm sản phẩm vào phiếu #<?php echo str_pad($fromReceipt, 6, "0", STR_PAD_LEFT); ?>. Vui lòng hoàn tất việc tạo sản phẩm để tự động thêm vào phiếu.',
                    confirmButtonText: 'Đã hiểu'
                });
                return false;
            });
            
            // Hiển thị nút "Cập nhật phiếu nhập" và ẩn nút "Thêm sản phẩm khác"
            $('#updateReceiptBtn').show();
            $('#addMoreItems').hide();
            
            // Xử lý sự kiện click nút "Cập nhật phiếu nhập"
            $(document).on('click', '#updateReceiptBtn', function(e) {
                e.preventDefault();
                console.log('🔄 Updating receipt #' + FROM_RECEIPT_ID);
                console.log('📦 receiptItems:', receiptItems);
                
                // Debug giá trong receiptItems
                receiptItems.forEach(function(item, idx) {
                    console.log(`  Item #${idx}: ${item.product_name} - Size ${item.size}`);
                    console.log(`    → price: ${item.price}, sale_price: ${item.sale_price}`);
                });
                
                // Kiểm tra có sản phẩm không
                if (!receiptItems || receiptItems.length === 0) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Chưa có sản phẩm',
                        text: 'Vui lòng tạo sản phẩm trước khi cập nhật phiếu nhập.',
                        confirmButtonText: 'Đã hiểu'
                    });
                    return;
                }
                
                // Show loading
                const $btn = $(this);
                const originalHtml = $btn.html();
                $btn.html('<i class="fas fa-spinner fa-spin mr-2"></i>Đang thêm vào phiếu...').prop('disabled', true);
                
                // LOGIC MỚI: Trực tiếp thêm vào phiếu từ receiptItems (đã có product_id và variant_id)
                console.log('📝 Đang thêm ' + receiptItems.length + ' sản phẩm vào phiếu...');
                
                let addedCount = 0;
                let errorCount = 0;
                const totalItems = receiptItems.length;
                
                // Lấy variant_id cho mỗi item
                const itemsToAdd = [];
                let pendingCount = receiptItems.length;
                
                receiptItems.forEach(function(item, index) {
                    // Tìm variant_id dựa trên product_id và size
                    $.ajax({
                        url: '',
                        method: 'POST',
                        data: {
                            action: 'get_variant_id',
                            product_id: item.product_id,
                            size: item.size,
                            sku: item.sku
                        },
                        dataType: 'json',
                        success: function(response) {
                            if (response.success && response.variant_id) {
                                itemsToAdd.push({
                                    variant_id: response.variant_id,
                                    quantity: parseInt(item.quantity) || 1,
                                    unit_price: parseFloat(item.price) || 0,
                                    sale_price: parseFloat(item.sale_price) || 0,
                                    location_code: item.storage_location || null,
                                    item_index: index
                                });
                                
                                console.log('✅ Found variant_id for item #' + index, {
                                    sku: item.sku,
                                    variant_id: response.variant_id,
                                    unit_price: parseFloat(item.price),
                                    sale_price: parseFloat(item.sale_price)
                                });
                            } else {
                                console.error('❌ Không tìm thấy variant_id cho item:', item);
                                errorCount++;
                            }
                            
                            pendingCount--;
                            if (pendingCount === 0) {
                                // Tất cả variant_id đã được tìm, bắt đầu thêm vào phiếu
                                addItemsToReceipt();
                            }
                        },
                        error: function() {
                            console.error('❌ Lỗi khi tìm variant_id cho item:', item);
                            errorCount++;
                            pendingCount--;
                            if (pendingCount === 0) {
                                addItemsToReceipt();
                            }
                        }
                    });
                });
                
                function addItemsToReceipt() {
                    console.log('📝 Thêm ' + itemsToAdd.length + ' items vào phiếu...');
                    
                    if (itemsToAdd.length === 0) {
                        $btn.html(originalHtml).prop('disabled', false);
                        Swal.fire({
                            icon: 'error',
                            title: 'Lỗi',
                            text: 'Không tìm thấy variant nào để thêm vào phiếu.',
                            confirmButtonText: 'Đã hiểu'
                        });
                        return;
                    }
                    
                    itemsToAdd.forEach(function(item) {
                        $.ajax({
                            url: 'api_add_product_to_receipt.php',
                            method: 'POST',
                            data: {
                                receipt_id: FROM_RECEIPT_ID,
                                variant_id: item.variant_id,
                                quantity: item.quantity,
                                unit_price: item.unit_price,
                                sale_price: item.sale_price,
                                location_code: item.location_code
                            },
                            success: function(addResponse) {
                                console.log('✅ Đã thêm item #' + item.item_index + ' vào phiếu:', addResponse);
                                addedCount++;
                                checkComplete();
                            },
                            error: function(xhr, status, error) {
                                console.error('❌ Lỗi khi thêm item #' + item.item_index + ':', error);
                                errorCount++;
                                checkComplete();
                            }
                        });
                    });
                }
                            
                function checkComplete() {
                    const total = itemsToAdd.length;
                    if (addedCount + errorCount === total) {
                        $btn.html(originalHtml).prop('disabled', false);
                        
                        if (errorCount > 0) {
                            Swal.fire({
                                icon: 'warning',
                                title: 'Hoàn tất với lỗi',
                                text: `Đã thêm ${addedCount}/${total} sản phẩm. ${errorCount} sản phẩm bị lỗi.`,
                                confirmButtonText: 'Quay về trang quản lý'
                            }).then(() => {
                                window.location.href = 'stock_receipts_management.php';
                            });
                        } else {
                            Swal.fire({
                                icon: 'success',
                                title: 'Thành công!',
                                text: `Đã thêm ${addedCount} sản phẩm vào phiếu nhập #<?php echo str_pad($fromReceipt, 6, "0", STR_PAD_LEFT); ?>`,
                                timer: 2000,
                                showConfirmButton: false
                            }).then(() => {
                                // Xóa localStorage và quay về
                                localStorage.removeItem('excluded_locations');
                                localStorage.removeItem('edit_receipt_id');
                                localStorage.removeItem('return_to_edit');
                                window.location.href = 'stock_receipts_management.php';
                            });
                        }
                    }
                }
            });
            
            // Hiển thị thông báo ở đầu trang
            const alertHtml = `
                <div class="alert alert-info alert-dismissible fade show" role="alert" style="margin: 20px;">
                    <i class="fas fa-info-circle mr-2"></i>
                    <strong>Chế độ thêm sản phẩm:</strong> Bạn đang thêm sản phẩm vào phiếu nhập #<?php echo str_pad($fromReceipt, 6, "0", STR_PAD_LEFT); ?>. 
                    Tạo sản phẩm như bình thường và nhấn nút <strong>"Cập nhật phiếu nhập"</strong> để thêm vào phiếu.
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
            `;
            $('.container-fluid').prepend(alertHtml);
        });
        <?php endif; ?>

    </script>

<?php include 'includes/footer.php'; ?>
