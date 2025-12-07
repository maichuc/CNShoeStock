<?php
session_start();
require_once 'config/database.php';
require_once 'helpers/GhiNhatKyKiemToan.php';
require_once 'helpers/DichVuTaiAnhLen.php';
require_once 'helpers/TroGiupPhanTichAI.php';
require_once 'helpers/TroGiupDoTuongDong.php';
require_once 'ham_kiem_tra_trung_nang_cao.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    header('Location: login.html');
    exit();
}

// Kết nối database
$database = new Database();
$pdo = $database->getConnection();

// Load environment variables từ helper
loadEnvironmentVariables();

$currentUser = $_SESSION['user_id'];
$userWarehouseId = $_SESSION['warehouse_id'] ?? null;
$userRole = $_SESSION['role'] ?? 'employee'; // Lấy role của user

// Debug và validation warehouse_id
error_log("DEBUG: Session user_id = " . ($currentUser ?? 'NULL'));
error_log("DEBUG: Session warehouse_id = " . ($userWarehouseId ?? 'NULL'));
error_log("DEBUG: Session role = " . $userRole);

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

// Lấy danh sách nhà cung cấp và danh mục
$suppliers = [];

try {
    $stmt = $pdo->query("SELECT supplier_id, name FROM suppliers ORDER BY name");
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    error_log("Database error: " . $e->getMessage());
}

// ===== TẤT CẢ CÁC HÀM AI ĐÃ ĐƯỢC CHUYỂN VÀO helpers/TroGiupPhanTichAI.php =====
// ===== TẤT CẢ CÁC HÀM SIMILARITY ĐÃ ĐƯỢC CHUYỂN VÀO helpers/TroGiupDoTuongDong.php =====
// ===== HÀM DUPLICATE DETECTION ĐÃ ĐƯỢC CHUYỂN VÀO ham_kiem_tra_trung_nang_cao.php =====

// ===== PHẦN XỬ LÝ POST REQUEST =====

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
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
            
            if ($fileCount < 2 || $fileCount > 3) {
                $response = ['success' => false, 'message' => 'Vui lòng tải 2-3 ảnh'];
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
                    $aiResults = ['success' => false, 'message' => 'Không thể phân tích ảnh với Gemini AI'];
                }
            }
            
            if ($aiResults['success']) {
                $suggestions = $aiResults['data']; // Gemini trả về trực tiếp dữ liệu cần thiết
                
                // FORMAT TÊN SẢN PHẨM THEO QUY TẮC: [Loại] [Thương hiệu] [Tính năng] [Màu]
                $formattedName = '';
                
                // 1. Loại sản phẩm (Type) - BẮT BUỘC
                if (!empty($suggestions['type'])) {
                    $formattedName .= $suggestions['type'];
                }
                
                // 2. Thương hiệu (Brand) - bỏ qua nếu là "Unknown"
                if (!empty($suggestions['brand']) && strtolower($suggestions['brand']) !== 'unknown') {
                    $formattedName .= ($formattedName ? ' ' : '') . $suggestions['brand'];
                }
                
                // 3. Tính năng (Features/Style)
                if (!empty($suggestions['features'])) {
                    $formattedName .= ($formattedName ? ' ' : '') . $suggestions['features'];
                } else if (!empty($suggestions['style'])) {
                    $formattedName .= ($formattedName ? ' ' : '') . $suggestions['style'];
                }
                
                // 4. Màu sắc (Color)
                if (!empty($suggestions['color'])) {
                    $formattedName .= ($formattedName ? ' ' : '') . $suggestions['color'];
                }
                
                // Nếu format thành công, cập nhật name, nếu không giữ nguyên
                if ($formattedName) {
                    $suggestions['name'] = $formattedName;
                    $aiResults['data']['name'] = $formattedName;
                    error_log("📝 Formatted product name: " . $formattedName);
                }
                
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
                    
                    // min_quantity removed from UI; keep sizes as returned from DB
                    
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
                    $product['sku'] = $baseSku; // Set calculated base SKU
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
            
            // Detect if this is manual entry vs AI entry
            $isManualEntry = isset($_POST['manual_product_name']) || isset($_POST['manual_brand']);
            
            // Check if this is update mode
            $isUpdateMode = isset($_POST['is_update_mode']) && $_POST['is_update_mode'] === 'true';
            $updateProductId = $_POST['update_product_id'] ?? null;
            
            // Debug: Log received data
            error_log("Save product action - Manual entry: " . ($isManualEntry ? 'true' : 'false'));
            error_log("Save product action - Update mode: " . ($isUpdateMode ? 'true' : 'false'));
            if ($isUpdateMode) {
                error_log("Update product ID: " . $updateProductId);
            }
            if ($isManualEntry) {
                error_log("Manual entry POST data: " . json_encode($_POST));
            }
            
            // Xử lý lưu sản phẩm - chỉ tạo danh mục sản phẩm và variants, không có số lượng thực tế
            if ($isManualEntry) {
                // Manual entry data
                $productName = trim($_POST['manual_product_name'] ?? $_POST['product_name'] ?? '');
                $brand = trim($_POST['manual_brand'] ?? $_POST['brand'] ?? '');
                $type = trim($_POST['manual_type'] ?? $_POST['type'] ?? '');
                $productDescription = trim($_POST['manual_description'] ?? $_POST['product_description'] ?? '');
                $material = trim($_POST['manual_material'] ?? $_POST['material'] ?? '');
                $features = trim($_POST['manual_features'] ?? $_POST['features'] ?? '');
                $tags = trim($_POST['manual_tags'] ?? $_POST['tags'] ?? '');
                error_log("Processing manual entry with name: $productName, brand: $brand");
            } else {
                // AI entry data (existing)
                $productName = trim($_POST['product_name'] ?? '');
                $brand = trim($_POST['brand'] ?? '');
                $type = trim($_POST['type'] ?? '');
                $productDescription = trim($_POST['product_description'] ?? '');
                $material = trim($_POST['material'] ?? '');
                $features = trim($_POST['features'] ?? '');
                $tags = trim($_POST['tags'] ?? '');
            }
            $categoryId = null; // Đã xóa phần chọn danh mục
            
            if ($isManualEntry) {
                // Manual entry specific fields
                $sku = trim($_POST['manual_sku'] ?? $_POST['sku'] ?? '');
                $color = trim($_POST['manual_color'] ?? $_POST['color'] ?? '');
                $price = floatval($_POST['price'] ?? 0);
                
                // Handle image URLs - manual entry sends array, AI sends JSON string
                $imageUrlArray = $_POST['image_url'] ?? [];
                if (is_array($imageUrlArray)) {
                    $imageUrls = json_encode($imageUrlArray);
                } else {
                    $imageUrls = $imageUrlArray; // Already JSON string
                }
                
                // Manual sizes and prices
                $sizes = $_POST['manual_sizes'] ?? [];
                $sizePrices = $_POST['manual_size_prices'] ?? [];
                
                error_log("Manual entry - Product: $productName, SKU: $sku, Color: $color");
                error_log("Manual sizes: " . json_encode($sizes));
                error_log("Manual size prices: " . json_encode($sizePrices));
                error_log("Manual entry - Raw imageUrlArray: " . print_r($_POST['manual_image_url'] ?? 'not set', true));
                error_log("Manual entry - Final imageUrls: " . $imageUrls);
            } else {
                // AI entry fields (existing)
                $sku = trim($_POST['sku'] ?? '');
                $color = trim($_POST['color'] ?? '');
                $price = floatval($_POST['price'] ?? 0);
                $imageUrls = $_POST['image_url'] ?? '';
                error_log("🖼️ SAVE DEBUG - imageUrls from POST: '" . $imageUrls . "'");
                error_log("🖼️ SAVE DEBUG - imageUrls empty? " . (empty($imageUrls) ? 'YES' : 'NO'));
                
                // AI sizes and prices
                $sizes = $_POST['sizes'] ?? [];
                $sizePrices = $_POST['size_prices'] ?? [];
                
                error_log("AI entry - Product: $productName, SKU: $sku, Category: $categoryId");
                error_log("AI entry - Raw image_url: " . print_r($_POST['image_url'] ?? 'not set', true));
                error_log("AI entry - Final imageUrls: " . $imageUrls);
            }
            
            // Initialize validation flag
            $validationError = null;
            
            // Basic validation 
            if (empty($productName) || empty($sku)) {
                $validationError = 'Vui lòng điền đầy đủ thông tin bắt buộc (Tên sản phẩm và SKU cơ sở)';
            }
            
            // Additional validation for manual entry
            if ($isManualEntry) {
                if (empty($brand) || empty($type) || empty($color)) {
                    $validationError = 'Vui lòng điền đầy đủ thông tin bắt buộc (Tên, thương hiệu, loại, màu sắc, SKU)';
                }
            }
            
            // Lấy existing sizes nếu có (cho update mode)
            $existingSizes = $_POST['existing_sizes'] ?? [];
            $existingMinQuantities = $_POST['existing_min_quantities'] ?? []; // ignored (backward compat)
            // Nhận cả 2 tên để tương thích
            $existingSizePrices = $_POST['existing_prices'] ?? $_POST['existing_size_prices'] ?? [];
            $existingVariantIds = $_POST['existing_variant_ids'] ?? [];
            
            // Lấy new sizes khi edit (size mới thêm vào khi sửa sản phẩm)
            $newSizes = $_POST['new_sizes'] ?? [];
            $newSizePrices = $_POST['new_size_prices'] ?? [];
            
            error_log("Manual sizes data (for new product): " . json_encode($sizes));
            error_log("Manual size prices data (for new product): " . json_encode($sizePrices));
            error_log("Existing sizes data (from DB): " . json_encode($existingSizes));
            error_log("Existing size prices data (from DB): " . json_encode($existingSizePrices));
            error_log("Existing variant IDs (from DB): " . json_encode($existingVariantIds));
            error_log("New sizes data (added when edit): " . json_encode($newSizes));
            error_log("New size prices data (added when edit): " . json_encode($newSizePrices));
            
            // Validate required fields
            if ($validationError) {
                error_log("Skipping processing due to category validation error: " . $validationError);
                $response = ['success' => false, 'message' => $validationError];
            } else if (empty($productName) || empty($sku)) {
                error_log("Validation failed: Missing product name or SKU");
                $response = ['success' => false, 'message' => 'Vui lòng điền đầy đủ thông tin bắt buộc (Tên sản phẩm và SKU cơ sở)'];
            } else if (empty($sizes) && empty($existingSizes)) {
                error_log("Validation failed: No sizes provided");
                $response = ['success' => false, 'message' => 'Vui lòng chọn ít nhất một size cho sản phẩm'];
            } else if (count($sizes) !== count($sizePrices)) {
                error_log("Validation failed: New size and size_prices count mismatch");
                $response = ['success' => false, 'message' => 'Dữ liệu size mới và giá bán không khớp'];
            } else if (count($existingSizes) !== count($existingSizePrices)) {
                error_log("Validation failed: Existing size and size_prices count mismatch");
                $response = ['success' => false, 'message' => 'Dữ liệu size hiện tại và giá bán không khớp'];
            } else {
                // Validate sizes và tạo danh sách size hợp lệ
                error_log("Processing sizes validation...");
                $validSizes = [];
                $hasValidSize = false;
                
                // Xử lý existing sizes trước (cho update mode)
                for ($i = 0; $i < count($existingSizes); $i++) {
                    $size = trim($existingSizes[$i]);
                    $sizePrice = floatval($existingSizePrices[$i] ?? 0);
                    $variantId = !empty($existingVariantIds[$i]) ? intval($existingVariantIds[$i]) : null;

                    error_log("Processing existing size[$i]: '$size' with price: $sizePrice, variant_id: $variantId");

                    if (!empty($size)) {
                        $validSizes[] = [
                            'size' => $size,
                            'price' => $sizePrice,
                            'is_existing' => true,
                            'variant_id' => $variantId  // Thêm variant_id để UPDATE thay vì INSERT
                        ];
                        $hasValidSize = true;
                        error_log("Valid existing size added: $size with price: $sizePrice, variant_id: $variantId");
                    }
                }
                
                // Xử lý manual sizes (cho thêm mới sản phẩm)
                for ($i = 0; $i < count($sizes); $i++) {
                    $size = trim($sizes[$i]);
                    $sizePrice = floatval($sizePrices[$i] ?? 0);

                    error_log("Processing manual size[$i]: '$size' with price: $sizePrice");

                    if (!empty($size)) {
                        // Kiểm tra trùng lặp size
                        if (in_array($size, array_column($validSizes, 'size'))) {
                            error_log("Duplicate size detected: $size");
                            $response = ['success' => false, 'message' => "Size $size đã được chọn rồi"];
                            break;
                        }
                        
                        $validSizes[] = [
                            'size' => $size,
                            'price' => $sizePrice,
                            'is_existing' => false
                        ];
                        $hasValidSize = true;
                        error_log("Valid manual size added: $size with price: $sizePrice");
                    } else {
                        error_log("Invalid manual size: size='$size'");
                    }
                }
                
                // Xử lý new sizes (size mới thêm khi edit sản phẩm)
                for ($i = 0; $i < count($newSizes); $i++) {
                    $size = trim($newSizes[$i]);
                    $sizePrice = floatval($newSizePrices[$i] ?? 0);

                    error_log("Processing new size (from edit)[$i]: '$size' with price: $sizePrice");

                    if (!empty($size)) {
                        // Kiểm tra trùng lặp size
                        if (in_array($size, array_column($validSizes, 'size'))) {
                            error_log("Duplicate new size detected: $size");
                            $response = ['success' => false, 'message' => "Size $size đã được chọn rồi"];
                            break;
                        }
                        
                        $validSizes[] = [
                            'size' => $size,
                            'price' => $sizePrice,
                            'is_existing' => false
                        ];
                        $hasValidSize = true;
                        error_log("Valid new size (from edit) added: $size with price: $sizePrice");
                    } else {
                        error_log("Invalid new size: size='$size'");
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
                        error_log("Inserting main product with data: " . json_encode([
                            'name' => $productName,
                            'brand' => $brand,
                            'type' => $type
                        ]));
                        
                        if ($isUpdateMode && $updateProductId) {
                            // Update mode: Update existing product (không cần update warehouse_id)
                            $stmt = $pdo->prepare("UPDATE products SET name = ?, description = ?, brand = ?, type = ?, material = ?, features = ?, tags = ? WHERE product_id = ?");
                            $stmt->execute([$productName, $productDescription, $brand, $type, $material, $features, $tags, $updateProductId]);
                            $productId = $updateProductId;
                            error_log("Product updated successfully with ID: " . $productId);
                        } else {
                            // Add new mode: Insert new product với warehouse_id và ai_analyzed flag
                            $aiAnalyzed = $isManualEntry ? 0 : 1; // 0 for manual, 1 for AI
                            error_log("Adding new product with warehouse_id: " . $userWarehouseId . ", ai_analyzed: " . $aiAnalyzed);
                            $stmt = $pdo->prepare("INSERT INTO products (warehouse_id, name, description, brand, type, material, features, tags, ai_analyzed, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                            $stmt->execute([$userWarehouseId, $productName, $productDescription, $brand, $type, $material, $features, $tags, $aiAnalyzed]);
                            $productId = $pdo->lastInsertId();
                            error_log("Product inserted successfully with ID: " . $productId . " (Manual: " . ($isManualEntry ? 'Yes' : 'No') . ")");
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
                        
                        // Process sizes: update existing ones or create new ones
                        foreach ($validSizes as $index => $sizeData) {
                            $currentSize = $sizeData['size'];
                            $finalPrice = $sizeData['price'] > 0 ? $sizeData['price'] : $price; // Ưu tiên giá theo size
                            
                            // Kiểm tra xem có variant_id không (size cũ từ database)
                            if (!empty($sizeData['variant_id'])) {
                                // UPDATE existing variant
                                $variantId = $sizeData['variant_id'];
                                error_log("Updating existing variant: variant_id=$variantId, size=$currentSize, price=$finalPrice");
                                
                                $stmt = $pdo->prepare("UPDATE product_variants SET price = ?, updated_at = NOW() WHERE variant_id = ?");
                                $stmt->execute([$finalPrice, $variantId]);
                                
                                // Get SKU for this variant
                                $stmt = $pdo->prepare("SELECT sku FROM product_variants WHERE variant_id = ?");
                                $stmt->execute([$variantId]);
                                $sizeBasedSku = $stmt->fetchColumn();
                            } else {
                                // INSERT new variant
                                error_log("Creating new variant: size=$currentSize, price=$finalPrice");
                                
                                $sizeBasedSku = $sku . '-' . $sizeData['size'];
                                
                                // Kiểm tra SKU trùng lặp
                                $stmt = $pdo->prepare("SELECT variant_id FROM product_variants WHERE sku = ?");
                                $stmt->execute([$sizeBasedSku]);
                                if ($stmt->fetch()) {
                                    // Tạo SKU unique bằng cách thêm timestamp
                                    $sizeBasedSku = $sku . '-' . $sizeData['size'] . '-' . substr(time(), -6);
                                }
                                
                                // Thêm variant cho từng size với giá riêng (quantity = 0 vì chưa nhập kho)
                                $stmt = $pdo->prepare("INSERT INTO product_variants (product_id, sku, color, size, price, warehouse_id, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                                $stmt->execute([$productId, $sizeBasedSku, $color, $sizeData['size'], $finalPrice, $userWarehouseId]);
                                $variantId = $pdo->lastInsertId();
                            }
                            
                            // Tạo bản ghi trong inventory với quantity = 0 và min_stock_level (chỉ cho variant mới)
                            if ($userWarehouseId && empty($sizeData['variant_id'])) {
                                try {
                                    // Kiểm tra xem có bảng nào để lưu min_stock_level không, nếu không thì skip
                                    $stmt = $pdo->prepare("INSERT INTO inventory (variant_id, warehouse_id, quantity, location_id) VALUES (?, ?, 0, NULL)");
                                    $stmt->execute([$variantId, $userWarehouseId]);
                                } catch (Exception $e) {
                                    // Bỏ qua lỗi này nếu chưa có cấu trúc inventory phù hợp
                                    error_log("Inventory creation skipped: " . $e->getMessage());
                                }
                            }
                            
                            // Lấy số lượng hiện tại cho existing variant, hoặc 0 cho variant mới
                            $currentQuantity = 0;
                            if (!empty($sizeData['variant_id'])) {
                                $stmt = $pdo->prepare("SELECT COALESCE(SUM(quantity), 0) FROM inventory WHERE variant_id = ?");
                                $stmt->execute([$variantId]);
                                $currentQuantity = $stmt->fetchColumn();
                            }
                            
                            $createdSizes[] = [
                                'variant_id' => $variantId,
                                'size' => $sizeData['size'],
                                'price' => $finalPrice,
                                'current_quantity' => $currentQuantity,
                                'sku' => $sizeBasedSku
                            ];
                            
                            // Thêm hình ảnh cho variant đầu tiên
                            if ($index === 0 && !empty($imageUrls)) {
                                error_log("🖼️ Processing images for variant $variantId - imageUrls: " . $imageUrls);
                                $imagePaths = json_decode($imageUrls, true);
                                error_log("🖼️ Decoded imagePaths: " . print_r($imagePaths, true));
                                
                                if (is_array($imagePaths)) {
                                    foreach ($imagePaths as $imgIndex => $imagePath) {
                                        $isPrimary = ($imgIndex === 0) ? 1 : 0;
                                        error_log("🖼️ Inserting image $imgIndex: $imagePath (primary: $isPrimary) for product $productId, variant $variantId");
                                        $stmt = $pdo->prepare("INSERT INTO product_images (product_id, file_path, is_primary, created_at) VALUES (?, ?, ?, NOW())");
                                        $stmt->execute([$productId, $imagePath, $isPrimary]);
                                        error_log("🖼️ Successfully inserted image: " . $imagePath);
                                    }
                                    error_log("🖼️ Total images inserted: " . count($imagePaths));
                                } else {
                                    error_log("❌ imagePaths is not an array or json_decode failed");
                                }
                            } else {
                                error_log("⚠️ Skipping image processing - index: $index, empty imageUrls: " . (empty($imageUrls) ? 'yes' : 'no'));
                            }
                        }
                        
                        // **FIX: Xử lý ảnh cho UPDATE MODE**
                        if ($isUpdateMode && !empty($imageUrls)) {
                            error_log("🖼️ UPDATE MODE: Processing images - imageUrls: " . $imageUrls);
                            $imagePaths = json_decode($imageUrls, true);
                            error_log("🖼️ UPDATE MODE: Decoded imagePaths: " . print_r($imagePaths, true));
                            
                            if (is_array($imagePaths) && count($imagePaths) > 0) {
                                // Xóa tất cả ảnh cũ của product này
                                $stmt = $pdo->prepare("DELETE FROM product_images WHERE product_id = ?");
                                $stmt->execute([$productId]);
                                error_log("🗑️ UPDATE MODE: Deleted old images for product $productId");
                                
                                // Insert ảnh mới (không cần variant_id vì là ảnh của product)
                                foreach ($imagePaths as $imgIndex => $imagePath) {
                                    $isPrimary = ($imgIndex === 0) ? 1 : 0;
                                    error_log("🖼️ UPDATE MODE: Inserting image $imgIndex: $imagePath (primary: $isPrimary) for product $productId");
                                    $stmt = $pdo->prepare("INSERT INTO product_images (product_id, file_path, is_primary, warehouse_id, created_at) VALUES (?, ?, ?, ?, NOW())");
                                    $stmt->execute([$productId, $imagePath, $isPrimary, $userWarehouseId]);
                                    error_log("🖼️ UPDATE MODE: Successfully inserted image: " . $imagePath);
                                }
                                error_log("✅ UPDATE MODE: Total images inserted: " . count($imagePaths));
                            } else {
                                error_log("❌ UPDATE MODE: imagePaths is not an array or empty");
                            }
                        }
                        
                        // Log audit
                        $auditLogger = new AuditLogger($pdo);
                        // Audit logging with different action for manual vs AI entry
                        $auditAction = $isManualEntry ? 'create_product_manual' : 'create_product_ai';
                        $auditNote = $isManualEntry ? 'Product created via manual entry' : 'Product created via AI analysis';
                        
                        $auditLogger->log($currentUser, $auditAction, 'products', $productId, null, [
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
                            'entry_method' => $isManualEntry ? 'manual' : 'ai',
                            'note' => $auditNote . ' - Stock quantities will be added via stock receipts'
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
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Thêm sản phẩm mới - Smart Warehouse</title>
    
    <!-- Custom fonts -->
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">
    
    <!-- Custom styles -->
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    
    <style>
        .upload-area {
            border: 2px dashed #224abe;
            border-radius: 15px;
            padding: 60px 40px;
            text-align: center;
            background: #f9fafb;
            transition: all 0.3s ease;
            cursor: pointer;
            min-height: 300px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
        }
        
        .upload-area:hover {
            border-color: #1a3a8f;
            background: #f0f4ff;
        }
        
        .upload-area.drag-over {
            border-color: #10b981;
            background: #f0fdf4;
        }
        
        .upload-icon {
            font-size: 72px;
            color: #224abe;
            margin-bottom: 24px;
        }
        
        .step-indicator {
            display: flex;
            justify-content: center;
            margin-bottom: 40px;
            padding: 20px;
            background: #fafafa;
            border-radius: 15px;
            box-shadow: 0 2px 15px rgba(0,0,0,0.05);
            border: 1px solid #e5e7eb;
        }
        
        .step {
            display: flex;
            align-items: center;
            margin: 0 15px;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .step:hover .step-number {
            transform: scale(1.15);
            box-shadow: 0 4px 15px rgba(78, 115, 223, 0.3);
        }
        
        .step.completed {
            cursor: pointer;
        }
        
        .step.completed:hover .step-number {
            background: #1e7e34 !important;
        }
        
        .step-number {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: #e5e7eb;
            color: #6b7280;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1.1rem;
            margin-right: 12px;
            border: 3px solid transparent;
            transition: all 0.3s ease;
        }
        
        .step.active .step-number {
            background: #224abe;
            color: white;
            border-color: #224abe;
            box-shadow: 0 4px 15px rgba(34, 74, 190, 0.3);
        }
        
        .step.completed .step-number {
            background: #10b981;
            color: white;
            border-color: #10b981;
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
        }
        
        .ai-analysis-result {
            background: #f0f4ff;
            border: 1px solid #224abe;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
        }
        
        /* AI Auto-Filled Info Styles */
        #aiAutoFilledInfo {
            background: linear-gradient(135deg, #d4edda 0%, #f0fff4 100%);
            border: 2px solid #28a745 !important;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.15);
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
            color: #155724;
            font-weight: 600;
        }
        
        #aiAutoFilledInfo .table td {
            padding: 0.5rem 0.25rem;
            vertical-align: middle;
        }
        
        #aiAutoFilledInfo .table td:first-child {
            color: #155724;
        }
        
        #aiAutoFilledInfo .table td:last-child {
            color: #383d41;
            font-weight: 500;
        }
        
        #aiAutoFilledInfo i.fas {
            width: 20px;
            text-align: center;
        }
        
        #aiAutoFilledInfo hr {
            border-top: 1px solid #c3e6cb;
        }
        
        #aiAutoFilledInfo .alert-info {
            background-color: #d1ecf1;
            border-color: #bee5eb;
            color: #0c5460;
        }
        
        .tag-item {
            display: inline-block;
            background: #224abe;
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            margin: 2px;
        }
        
        .color-item {
            display: inline-block;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            margin: 2px;
            border: 2px solid #e5e7eb;
        }
        
        .suggestion-card {
            background: #fafafa;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 15px;
            margin: 10px 0;
        }
        
        .btn-ai {
            background: #224abe;
            border: none;
            color: white;
        }
        
        .btn-ai:hover {
            background: #1a3a8f;
            color: white;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(34, 74, 190, 0.25);
        }
        
        .loading-spinner {
            display: none;
            text-align: center;
            padding: 20px;
        }
        
        .image-preview {
            max-width: 100%;
            max-height: 400px;
            border-radius: 12px;
            margin: 15px 0;
        }
        
        .uploaded-image {
            width: 100%;
            height: 200px;
            object-fit: cover;
            border-radius: 8px;
            border: 2px solid #e3e6f0;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .uploaded-image:hover {
            transform: scale(1.05);
            transition: transform 0.3s ease;
        }
        
        .image-card {
            transition: all 0.3s ease;
        }
        
        .image-card:hover {
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .analyzing-image {
            border: 2px solid #ffc107;
            animation: pulse-yellow 2s infinite;
        }
        
        @keyframes pulse-yellow {
            0% { box-shadow: 0 0 0 0 rgba(255, 193, 7, 0.7); }
            70% { box-shadow: 0 0 0 10px rgba(255, 193, 7, 0); }
            100% { box-shadow: 0 0 0 0 rgba(255, 193, 7, 0); }
        }
        
        .border-primary {
            border: 2px solid #007bff !important;
            box-shadow: 0 0 0 2px rgba(0, 123, 255, 0.25);
        }
        
        .primary-image-badge {
            background: linear-gradient(45deg, #007bff, #0056b3);
            animation: glow 2s infinite alternate;
        }
        
        @keyframes glow {
            from { box-shadow: 0 0 5px #007bff; }
            to { box-shadow: 0 0 15px #007bff; }
        }
        
        /* Update Source Selection Modal Styles */
        .update-source-option {
            cursor: pointer;
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }
        
        .update-source-option:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .update-source-option.selected {
            border-color: #007bff;
            transform: translateY(-5px);
            box-shadow: 0 4px 15px rgba(0,123,255,0.3);
        }
        
        .update-source-option .card-body {
            transition: all 0.3s ease;
        }
        
        .update-source-option.selected .card-body {
            background: rgba(0,123,255,0.05);
        }
        
        /* Duplicate check modal styles */
        .duplicate-product-card {
            cursor: pointer;
            transition: all 0.3s ease;
            border: 2px solid #dee2e6;
            position: relative;
        }
        
        .duplicate-product-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            transform: translateY(-3px);
            border-color: #adb5bd;
        }
        
        .duplicate-product-card.border-primary {
            border: 3px solid #007bff !important;
            box-shadow: 0 0 20px rgba(0, 123, 255, 0.4) !important;
            background-color: #f8f9ff !important;
        }
        
        .duplicate-product-card.border-primary::before {
            content: '✓ Đã chọn';
            position: absolute;
            top: 10px;
            right: 10px;
            background: #007bff;
            color: white;
            padding: 4px 12px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: bold;
            z-index: 10;
            pointer-events: none; /* Không chặn click event */
        }
        
        /* Manual duplicate card styles */
        .manual-duplicate-card {
            position: relative;
            transition: all 0.3s ease;
        }
        
        .manual-duplicate-card:hover {
            background-color: #f8f9fc;
            border-color: #4e73df !important;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(78, 115, 223, 0.2);
        }
        
        .manual-duplicate-card.selected {
            background-color: #e3f2fd;
            border-color: #2196f3 !important;
            border-width: 2px !important;
            box-shadow: 0 4px 15px rgba(33, 150, 243, 0.3);
        }
        
        .manual-duplicate-card.selected .select-indicator {
            display: block !important;
        }
        
        .modal-xl {
            max-width: 90%;
        }
        
        @media (max-width: 768px) {
            .modal-xl {
                max-width: 95%;
                margin: 1rem;
            }
            
            .step-indicator {
                flex-direction: column;
                gap: 15px;
                padding: 15px;
            }
            
            .step {
                margin: 0;
                justify-content: center;
            }
            
            .step-number {
                width: 45px;
                height: 45px;
                font-size: 1rem;
            }
            
            .upload-area {
                padding: 40px 20px;
                min-height: 250px;
            }
            
            .upload-icon {
                font-size: 60px;
            }
            
            .container-fluid {
                padding-left: 15px;
                padding-right: 15px;
            }
            
            h1.h2 {
                font-size: 1.5rem;
            }
        }
        
        /* Size management styles - Thiết kế đồng nhất */
        .size-row {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 12px;
            background: #ffffff;
            margin-bottom: 8px;
            transition: all 0.3s ease;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            color: #000000; /* Updated text to black for sharp contrast */
        }
        
        .size-row:hover {
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            border-color: #224abe;
            transform: translateY(-1px);
        }
        
        .size-row:last-child {
            margin-bottom: 0 !important;
        }
        
        #sizeContainer {
            max-height: 400px;
            overflow-y: auto;
            padding-right: 4px;
        }
        
        .size-row .form-control-sm {
            border-radius: 6px;
            border: 1px solid #d1d3e2;
            font-size: 0.875rem;
            height: calc(1.8125rem + 2px);
        }
        
        .size-row .form-control-sm:focus {
            border-color: #224abe;
            box-shadow: 0 0 0 0.15rem rgba(34, 74, 190, 0.15);
        }
        
        .size-row .form-label {
            margin-bottom: 4px;
            font-weight: 500;
            color: #5a5c69;
            font-size: 0.8rem;
        }
        
        .size-row .btn-danger {
            background: #e74a3b;
            border-color: #e74a3b;
            font-size: 0.8rem;
            padding: 4px 8px;
            border-radius: 4px;
        }
        
        .size-row .btn-danger:hover {
            background: #c9302c;
            border-color: #ac2925;
        }
        
        .size-row .text-muted {
            font-size: 0.75rem;
        }
        
        .size-row input[readonly] {
            background-color: #f8f9fa !important;
            border: 1px solid #dee2e6 !important;
            color: #6c757d;
        }
        
        /* New category notification styles */
        .new-category-info {
            border-left: 4px solid #28a745 !important;
            background: linear-gradient(135deg, #d4edda 0%, #f0fff4 100%) !important;
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.15);
        }
        
        .new-category-info h6 {
            color: #155724;
            font-weight: 600;
        }
        
        .new-category-info .fas.fa-magic {
            animation: pulse-magic 2s infinite;
        }
        
        @keyframes pulse-magic {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }
        
        .remove-size-btn {
            border-radius: 4px;
            transition: all 0.3s ease;
        }
        
        /* Price fields styling */
        input[name="size_prices[]"] {
            font-weight: 600;
            color: #28a745;
        }
        
        input[name="size_prices[]"]:focus {
            border-color: #28a745;
            box-shadow: 0 0 0 0.2rem rgba(40, 167, 69, 0.25);
        }
        
        .input-group-text {
            background-color: #f8f9fa;
            border-color: #d1d3e2;
            font-weight: 600;
        }
        
        #applyAllPriceBtn {
            font-size: 0.85rem;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            transition: all 0.3s ease;
        }
        
        #applyAllPriceBtn:hover {
            background-color: #224abe;
            border-color: #224abe;
            color: white;
            transform: translateY(-1px);
        }
        
        .remove-size-btn:hover {
            transform: scale(1.1);
            box-shadow: 0 2px 4px rgba(220, 53, 69, 0.3);
        }
        
        #addSizeBtn {
            border-radius: 6px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        #addSizeBtn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(40, 167, 69, 0.3);
        }
        
        /* Toast notifications */
        .toast-notification {
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            animation: slideInRight 0.3s ease-out;
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
        
        /* Size selection highlight */
        .size-row.has-duplicate select {
            border-color: #dc3545;
            box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25);
        }
        
        /* Manual Entry Styles */
        .method-selector-wrapper {
            animation: fadeIn 0.5s ease-in;
        }
        
        .method-card {
            border: 2px solid #e5e7eb;
            border-radius: 15px;
            padding: 30px;
            text-align: center;
            transition: all 0.3s ease;
            cursor: pointer;
            position: relative;
            height: 100%;
            background: #ffffff;
        }
        
        .method-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        
        .method-card.ai-method:hover {
            border-color: #224abe;
            box-shadow: 0 10px 25px rgba(34, 74, 190, 0.2);
        }
        
        .method-card.manual-method:hover {
            border-color: #059669;
            box-shadow: 0 10px 25px rgba(5, 150, 105, 0.25);
        }
        
        .method-icon {
            margin-bottom: 20px;
        }
        
        .method-title {
            font-weight: 600;
            margin-bottom: 15px;
            color: #5a5c69;
        }
        
        .method-description {
            color: #858796;
            margin-bottom: 20px;
            font-size: 14px;
            line-height: 1.5;
        }
        
        .method-features {
            margin-bottom: 20px;
        }
        
        .feature-tag {
            display: inline-block;
            background: #f8f9fc;
            border: 1px solid #e3e6f0;
            border-radius: 20px;
            padding: 5px 12px;
            margin: 3px;
            font-size: 12px;
            color: #5a5c69;
        }
        
        .ai-method .feature-tag {
            background: #eef4ff;
            border-color: #4e73df;
            color: #4e73df;
        }
        
        .manual-method .feature-tag {
            background: #d1fae5;
            border-color: #059669;
            color: #065f46;
            font-weight: 600;
        }
        
        .method-recommendation {
            position: absolute;
            top: 15px;
            right: 15px;
        }
        
        .upload-area-manual {
            border: 2px dashed #1cc88a;
            border-radius: 10px;
            padding: 30px;
            text-align: center;
            background: #f0fff4;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .upload-area-manual:hover {
            border-color: #17a2b8;
            background: #e6f7ff;
        }
        
        .similar-products-list {
            max-height: 400px;
            overflow-y: auto;
        }
        
        .similar-product-item {
            background: #f8f9fc;
            transition: all 0.3s ease;
        }
        
        .similar-product-item:hover {
            background: #ffffff;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        /* AI Duplicate Card Styles */
        .ai-duplicate-card {
            position: relative;
        }
        
        .ai-duplicate-card.border-primary {
            border-width: 3px !important;
            box-shadow: 0 4px 12px rgba(78, 115, 223, 0.3);
        }
        
        .ai-duplicate-card .selected-indicator {
            position: absolute;
            top: 10px;
            right: 10px;
            background: white;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            box-shadow: 0 2px 6px rgba(0,0,0,0.1);
        }
        
        .badge-lg {
            font-size: 14px;
            padding: 8px 12px;
        }
        
        #manualSkuExample {
            background: #f8f9fc;
            border-left: 4px solid #4e73df;
            padding: 8px 12px;
            border-radius: 4px;
        }
        
        /* SKU field visual feedback */
        .border-success {
            border-color: #1cc88a !important;
            box-shadow: 0 0 0 0.2rem rgba(28, 200, 138, 0.25) !important;
        }
        
        .border-info {
            border-color: #36b9cc !important;
            box-shadow: 0 0 0 0.2rem rgba(54, 185, 204, 0.25) !important;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @keyframes slideInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .manual-workflow {
            animation: slideInUp 0.5s ease-out;
        }
        
        .duplicate-check-result {
            animation: slideInUp 0.3s ease-out;
        }
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            .method-card {
                padding: 20px;
                margin-bottom: 20px;
            }
            
            .method-icon i {
                font-size: 2rem;
            }
            
            .method-title {
                font-size: 1.2rem;
            }
        }

        /* Manual Entry Enhanced Styles */
        .manual-step-card {
            transition: all 0.3s ease;
            border-left: 4px solid #e3e6f0;
        }
        
        .manual-step-card:hover {
            border-left-color: #4e73df;
            box-shadow: 0 0.25rem 0.5rem rgba(0, 0, 0, 0.1);
        }
        
        .manual-step-card.completed {
            border-left-color: #1cc88a;
            background-color: #f8fff9;
        }
        
        .badge-step {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.875rem;
            font-weight: bold;
        }
        
        .feature-suggestion-btn {
            transition: all 0.2s ease;
        }
        
        .feature-suggestion-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .color-suggestion-btn {
            min-width: 60px;
            transition: all 0.2s ease;
        }
        
        .color-suggestion-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .product-name-readonly {
            background-color: #e9ecef;
            border: 2px solid #1cc88a;
        }
        
        .auto-generated-name {
            position: relative;
        }
        
        .auto-generated-name::before {
            content: '🪄';
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 1.2rem;
        }
        
        .auto-generated-name input {
            padding-left: 40px;
            background: #f3f4f6;
            border: 2px solid #059669;
        }
        
        .upload-area-manual {
            border: 2px dashed #9ca3af;
            border-radius: 10px;
            padding: 30px;
            text-align: center;
            background: #fafafa;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .upload-area-manual:hover {
            border-color: #224abe;
            background: #f0f4ff;
        }
        
        .upload-area-manual.drag-over {
            border-color: #059669;
            background: #d1fae5;
        }
        
        .step-progress {
            background: #059669;
            height: 4px;
            border-radius: 2px;
            margin-bottom: 20px;
        }
        
        /* Enhanced card styling cho Hướng dẫn mới */
        .guide-card-enhanced {
            border: none;
            box-shadow: 0 0.25rem 2rem 0 rgba(58, 59, 69, 0.2);
            transition: all 0.3s ease;
            border-radius: 1rem;
            overflow: hidden;
        }
        
        .guide-card-enhanced:hover {
            box-shadow: 0 0.5rem 3rem 0 rgba(58, 59, 69, 0.3);
            transform: translateY(-5px);
        }
        
        .guide-card-enhanced .card-header {
            background: #224abe;
            border-bottom: none;
            position: relative;
        }
        
        .guide-card-enhanced .card-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: transparent;
        }
        
        .guide-card-enhanced .card-body {
            background: #f8f9fc;
        }
        
        .guide-card-enhanced h6 {
            font-weight: 700;
            letter-spacing: 0.5px;
        }
        
        .guide-card-enhanced ol {
            counter-reset: step-counter;
            list-style: none;
            padding-left: 0;
        }
        
        .guide-card-enhanced ol li {
            counter-increment: step-counter;
            position: relative;
            padding-left: 2.5rem;
            margin-bottom: 0.75rem;
            font-weight: 500;
        }
        
        .guide-card-enhanced ol li::before {
            content: counter(step-counter);
            position: absolute;
            left: 0;
            top: 0;
            background: #224abe;
            color: white;
            width: 1.5rem;
            height: 1.5rem;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: 700;
        }
        
        /* Responsive cho guide card enhanced */
        @media (max-width: 768px) {
            .guide-card-enhanced .card-body {
                padding: 1.5rem !important;
            }
            
            .guide-card-enhanced .row {
                flex-direction: column;
            }
            
            .guide-card-enhanced .col-md-6:first-child {
                margin-bottom: 2rem;
            }
        }
        
        .guide-card .card-header h6 {
            color: white;
        }
        
        /* Manual and AI Price Input Styles */
        .manual-price-input.border-info,
        .ai-price-input.border-info {
            border-width: 2px !important;
            background-color: #e7f5ff;
        }
        
        .price-source {
            font-size: 11px;
            font-style: italic;
        }
    </style>
</head>

<body id="page-top">
    <div id="wrapper">
        <!-- Sidebar -->
        <?php include 'includes/thanh_ben.php'; ?>

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
                                <a class="dropdown-item" href="cai_dat_ho_so.php">
                                    <i class="fas fa-user fa-sm fa-fw mr-2 text-gray-400"></i>
                                    Hồ Sơ
                                </a>
                                <a class="dropdown-item" href="doi_mat_khau.php">
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
                <div class="container-fluid px-4">
                    <div class="d-sm-flex align-items-center justify-content-between mb-5">
                        <h1 class="h2 mb-0 text-gray-800 font-weight-bold">
                            <i class="fas fa-plus-circle text-primary mr-2"></i>Thêm sản phẩm mới
                        </h1>
                    </div>
                    
                    <!-- Hướng dẫn nổi bật -->
                    <div class="mb-4">
                        <div class="card shadow-lg guide-card-enhanced">
                            <div class="card-header py-4 text-center">
                                <div class="mb-2">
                                    <i class="fas fa-info-circle fa-2x text-white"></i>
                                </div>
                                <h5 class="m-0 font-weight-bold text-white">
                                    Hướng dẫn thêm sản phẩm
                                </h5>
                            </div>
                            <div class="card-body p-4">
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6 class="text-primary mb-3">
                                            <i class="fas fa-list-ol mr-2"></i>Quy trình
                                        </h6>
                                        <ol class="mb-4">
                                            <li>Hệ thống kiểm tra sản phẩm trùng lặp</li>
                                            <li>Chỉnh sửa thông tin theo ý muốn</li>
                                            <li>Lưu sản phẩm vào hệ thống</li>
                                        </ol>
                                    </div>
                                    <div class="col-md-6">
                                        <h6 class="text-warning mb-3">
                                            <i class="fas fa-exclamation-triangle mr-2"></i>Lưu ý quan trọng
                                        </h6>
                                        <ul class="mb-0 small list-unstyled">
                                            <li class="mb-2"><i class="fas fa-check text-success mr-2"></i>Ảnh nên có độ phân giải cao</li>
                                            <li class="mb-2"><i class="fas fa-check text-success mr-2"></i>Nền ảnh sạch, sản phẩm rõ nét</li>
                                            <li class="mb-2"><i class="fas fa-check text-success mr-2"></i>Hệ thống sẽ cảnh báo nếu phát hiện sản phẩm trùng (ngưỡng thông minh từ 15%)</li>
                                            <li><i class="fas fa-check text-success mr-2"></i>Kiểm tra lại thông tin AI gợi ý</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Method Selector -->
                    <div class="method-selector-wrapper mb-4" id="methodSelector">
                        <div class="card shadow">
                            <div class="card-header py-3">
                                <h6 class="m-0 font-weight-bold text-primary">
                                    <i class="fas fa-route"></i> Chọn phương thức thêm sản phẩm
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="method-card ai-method h-100" id="aiMethodCard">
                                            <div class="method-icon">
                                                <i class="fas fa-robot fa-3x text-primary"></i>
                                            </div>
                                            <h4 class="method-title">Phân tích AI</h4>
                                            <p class="method-description">Upload 2-3 ảnh để AI tự động nhận diện và điền thông tin sản phẩm</p>
                                            <div class="method-features">
                                                <span class="feature-tag">✨ Nhanh chóng</span>
                                                <span class="feature-tag">🤖 Tự động điền</span>
                                                <span class="feature-tag">📸 Nhiều góc nhìn</span>
                                            </div>
                                            <div class="method-recommendation">
                                                <span class="badge badge-success">Khuyến nghị</span>
                                            </div>
                                            <button type="button" class="btn btn-primary btn-lg btn-block method-btn" data-method="ai">
                                                <i class="fas fa-play"></i> Bắt đầu với AI
                                            </button>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="method-card manual-method h-100" id="manualMethodCard">
                                            <div class="method-icon">
                                                <i class="fas fa-keyboard fa-3x text-success"></i>
                                            </div>
                                            <h4 class="method-title">Nhập thủ công</h4>
                                            <p class="method-description">Tự nhập chi tiết thông tin sản phẩm với kiểm tra trùng lặp thông minh</p>
                                            <div class="method-features">
                                                <span class="feature-tag">🎯 Kiểm soát hoàn toàn</span>
                                                <span class="feature-tag">✅ Chính xác cao</span>
                                                <span class="feature-tag">🔍 Kiểm tra trùng lặp</span>
                                            </div>
                                            <div class="method-recommendation">
                                                <span class="badge badge-secondary">Thay thế</span>
                                            </div>
                                            <button type="button" class="btn btn-success btn-lg btn-block method-btn" data-method="manual">
                                                <i class="fas fa-edit"></i> Nhập thủ công
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- AI Workflow (existing) -->
                    <div id="aiWorkflow" style="display: none;">
                        <!-- Step Indicator -->
                        <div class="step-indicator">
                            <div class="step active" id="step1">
                                <div class="step-number">1</div>
                                <span>Tải ảnh sản phẩm</span>
                            </div>
                        <div class="step" id="step2">
                            <div class="step-number">2</div>
                            <span>AI phân tích</span>
                        </div>
                        <div class="step" id="step3">
                            <div class="step-number">3</div>
                            <span>Chỉnh sửa thông tin</span>
                        </div>
                        <div class="step" id="step4">
                            <div class="step-number">4</div>
                            <span>Hoàn thành</span>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-lg-12">
                            <!-- Step 1: Upload Image -->
                            <div class="card shadow mb-4" id="step1-content">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">
                                        <i class="fas fa-camera"></i> Bước 1: Tải ảnh sản phẩm
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
                                        <h4 class="mb-3">Chọn ảnh sản phẩm</h4>
                                        <p class="text-muted mb-3" style="font-size: 1.1rem;">Kéo thả hoặc nhấn để chọn 2-3 ảnh</p>
                                        <div class="alert alert-success mb-3" style="max-width: 600px; margin: 0 auto;">
                                            <strong><i class="fas fa-info-circle"></i> Lưu ý:</strong> Hệ thống sẽ phân tích tối đa 3 ảnh đầu tiên để tối ưu hiệu suất
                                        </div>
                                        <p class="text-muted small mb-3">
                                            <strong>Yêu cầu kỹ thuật:</strong> JPG, PNG, WebP | ≤5MB | ≥800×800px | Tỷ lệ 1:1 hoặc 4:5
                                        </p>
                                        <input type="file" id="fileInput" accept="image/*" multiple style="display: none;">
                                        <button type="button" class="btn btn-primary btn-lg px-5 py-3" onclick="document.getElementById('fileInput').click()">
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

                            <!-- Step 2: AI Analysis -->
                            <div class="card shadow mb-4" id="step2-content" style="display: none;">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">
                                        <i class="fas fa-brain"></i> Bước 2: AI phân tích hình ảnh
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
                                        
                                        <!-- Kết quả kiểm tra trùng lặp tự động (giống manual) -->
                                        <div id="aiDuplicateCheckResult" class="mb-3" style="display: none;"></div>
                                        
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

                            <!-- Step 3: Edit Product Info -->
                            <div class="card shadow mb-4" id="step3-content" style="display: none;">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">
                                        <i class="fas fa-edit"></i> Bước 3: Chỉnh sửa thông tin sản phẩm
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <form id="productForm">
                                        <div class="suggestion-card" id="suggestionCard" style="display: none;">
                                            <h6><i class="fas fa-robot text-primary"></i> AI đã tự động điền thông tin sản phẩm:</h6>
                                            <div id="aiSuggestions"></div>
                                            <div class="alert alert-success alert-sm mt-2 mb-0">
                                                <i class="fas fa-check-circle"></i> 
                                                <strong>Hoàn tất!</strong> Vui lòng kiểm tra và chỉnh sửa thông tin nếu cần.
                                            </div>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label for="productName">Tên sản phẩm *</label>
                                            <input type="text" class="form-control" id="productName" name="product_name" required>
                                        </div>
                                        
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label for="brand">Thương hiệu</label>
                                                    <input type="text" class="form-control" id="brand" name="brand">
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label for="type">Loại sản phẩm</label>
                                                    <input type="text" class="form-control" id="type" name="type" list="typeOptions" placeholder="Chọn hoặc nhập loại sản phẩm">
                                                    <datalist id="typeOptions">
                                                        <option value="Giày Sneaker"></option>
                                                        <option value="Giày Boot"></option>
                                                        <option value="Giày Sandal"></option>
                                                        <option value="Giày cao gót"></option>
                                                        <option value="Giày tây"></option>
                                                        <option value="Giày lười"></option>
                                                        <option value="Dép"></option>
                                                        <option value="Giày thể thao"></option>
                                                        <option value="Giày vải"></option>
                                                        <option value="Giày da"></option>
                                                    </datalist>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label for="productDescription">Mô tả sản phẩm</label>
                                            <textarea class="form-control" id="productDescription" name="product_description" rows="3"></textarea>
                                        </div>
                                        
                                        <div class="row">
                                            <!-- Inline style to ensure labels on white inputs are high-contrast -->
                                            <style>
                                                /* Labels: dark color for high contrast on white inputs */
                                                .form-group label { color: #111827 !important; font-weight: 600; }
                                            </style>
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label for="material">Chất liệu</label>
                                                    <input type="text" class="form-control" id="material" name="material">
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label for="features">Tính năng</label>
                                                    <input type="text" class="form-control" id="features" name="features">
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label for="tags">Tags</label>
                                            <input type="text" class="form-control" id="tags" name="tags" placeholder="Phân cách bằng dấu phẩy">
                                        </div>
                                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="color">Màu sắc</label>
                                    <input type="text" class="form-control" id="color" name="color">
                                    <small class="form-text text-muted">AI đã phân tích màu sắc</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="price">Giá bán chung (VNĐ)</label>
                                    <input type="number" class="form-control" id="price" name="price" placeholder="Nhập giá bán chung" min="0">
                                    <small class="form-text text-muted">
                                        <i class="fas fa-info-circle"></i> Giá này sẽ tự động áp dụng cho các size chưa có giá riêng<br>
                                        <i class="fas fa-tags text-success"></i> Có thể để trống nếu muốn đặt giá riêng cho từng size
                                    </small>
                                </div>
                            </div>
                        </div>                                        <div class="row">
                                            <div class="col-md-12">
                                                <div class="form-group">
                                                    <label for="baseSku">Mã SKU cơ sở <span class="text-danger">*</span></label>
                                                    <div class="input-group">
                                                        <input type="text" class="form-control" id="baseSku" name="sku" required placeholder="VD: NIKE-AIR-123">
                                                        <div class="input-group-append">
                                                            <button type="button" class="btn btn-outline-secondary" id="generateSkuBtn" title="Tự động tạo SKU">
                                                                <i class="fas fa-magic"></i> Tạo SKU
                                                            </button>
                                                        </div>
                                                    </div>
                                                    <small class="form-text text-info" id="skuExample">
                                                        <i class="fas fa-info-circle"></i> 
                                                        <strong>Mã SKU cơ sở sẽ được sử dụng để tạo SKU riêng cho từng size.</strong><br>
                                                        <i class="fas fa-tag text-success"></i> Ví dụ: <code class="bg-light p-1 rounded">NIKE-AIR-123-38, NIKE-AIR-123-39, NIKE-AIR-123-40</code>
                                                    </small>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Phần quản lý Size và Ngưỡng tối thiểu -->
                                        <div class="form-group">
                                            <label><i class="fas fa-ruler-combined"></i> Quản lý Size & Ngưỡng tồn kho <span class="text-danger">*</span></label>
                                            <div class="alert alert-info">
                                                <i class="fas fa-info-circle"></i> 
                                                <strong>Lưu ý:</strong> Đây là bước tạo danh mục size cho sản phẩm. Số lượng thực tế sẽ được thêm qua chức năng "Nhập kho".
                                            </div>
                                            
                                            <div id="sizeContainer">
                                                <div class="size-row mb-2" data-size-index="0">
                                                    <div class="card border-left-primary">
                                                        <div class="card-body p-3">
                                                            <div class="row align-items-center">
                                                                <div class="col-md-3">
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
                                                                <div class="col-md-3">
                                                                    <label class="form-label mb-1"><strong>Giá bán (VNĐ)</strong> <small class="text-muted price-source"></small></label>
                                                                    <div class="input-group">
                                                                        <input type="number" name="size_prices[]" class="form-control ai-price-input" 
                                                                               placeholder="VD: 500000" min="0" value="">
                                                                        <div class="input-group-append">
                                                                            <span class="input-group-text">₫</span>
                                                                        </div>
                                                                    </div>
                                                                    <small class="text-muted">Giá bán riêng cho size này</small>
                                                                </div>
                                                                <!-- Ngưỡng tối thiểu đã bị xóa theo yêu cầu -->
                                                                <div class="col-md-1">
                                                                    <div class="text-center">
                                                                        <i class="fas fa-shoe-prints text-primary" style="font-size: 1.5em;"></i>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            
                            <button type="button" id="addSizeBtn" class="btn btn-success btn-sm mt-2">
                                <i class="fas fa-plus"></i> Thêm size khác
                            </button>
                        </div>
                                        
                        <input type="hidden" id="finalImageUrl" name="image_url">
                        <input type="hidden" name="action" value="save_product">
                        <input type="hidden" id="isUpdateMode" name="is_update_mode" value="false">
                        <input type="hidden" id="updateProductId" name="update_product_id" value="">
                        
                        <div class="text-center">
                            <button type="button" class="btn btn-secondary mr-2" id="backToAnalysis">
                                <i class="fas fa-arrow-left"></i> Quay lại phân tích
                            </button>
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-save"></i> Lưu sản phẩm
                            </button>
                        </div>
                                    </form>
                                </div>
                            </div>

                            <!-- Step 4: Complete -->
                            <div class="card shadow mb-4" id="step4-content" style="display: none;">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-success">
                                        <i class="fas fa-check-circle"></i> Bước 4: Hoàn thành
                                    </h6>
                                </div>
                                <div class="card-body text-center">
                                    <div class="mb-4">
                                        <i class="fas fa-check-circle text-success" style="font-size: 4rem;"></i>
                                    </div>
                                    <h4 class="text-success mb-3">Sản phẩm đã được tạo thành công!</h4>
                                    <p class="text-muted mb-4">Sản phẩm của bạn đã được thêm vào hệ thống với sự hỗ trợ của AI.</p>
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <a href="danh_sach_san_pham.php" class="btn btn-primary btn-block">
                                                <i class="fas fa-list"></i> Xem danh sách sản phẩm
                                            </a>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <button type="button" class="btn btn-success btn-block" onclick="window.location.reload()">
                                                <i class="fas fa-plus"></i> Thêm sản phẩm khác
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    </div>

                    <!-- Manual Workflow -->
                    <div id="manualWorkflow" style="display: none;">
                        <div class="row">
                            <div class="col-lg-12">
                                <!-- Manual Entry Form -->
                                <div class="card shadow mb-4">
                                    <div class="card-header py-3">
                                        <h6 class="m-0 font-weight-bold text-success">
                                            <i class="fas fa-keyboard"></i> Nhập thông tin sản phẩm thủ công
                                        </h6>
                                    </div>
                                    <div class="card-body">
                                        <form id="manualProductForm">
                                            <!-- Hidden field để lưu product_id khi edit -->
                                            <input type="hidden" id="manualProductId" name="manual_product_id" value="">
                                            
                                            <!-- 0. Hình ảnh sản phẩm -->
                                            <div class="card mb-4 manual-step-card">
                                                <div class="card-header">
                                                    <h6 class="m-0 text-primary">
                                                        <span class="badge badge-primary badge-step mr-2">0</span>
                                                        <i class="fas fa-images"></i> Hình ảnh sản phẩm (Tùy chọn)
                                                    </h6>
                                                </div>
                                                <div class="card-body">
                                                    <div class="upload-area-manual" id="manualUploadArea">
                                                        <div class="upload-icon">
                                                            <i class="fas fa-cloud-upload-alt"></i>
                                                        </div>
                                                        <h6>Chọn ảnh sản phẩm</h6>
                                                        <p class="text-muted small">Kéo thả hoặc nhấn để chọn 1-3 ảnh (Tùy chọn)</p>
                                                        <p class="text-muted small">
                                                            Định dạng: JPG, PNG, WebP | Dung lượng: ≤5MB
                                                        </p>
                                                        <input type="file" id="manualFileInput" accept="image/*" multiple style="display: none;">
                                                        <button type="button" class="btn btn-outline-primary" onclick="document.getElementById('manualFileInput').click()">
                                                            Chọn file
                                                        </button>
                                                    </div>
                                                    <div id="manualImagePreview"></div>
                                                </div>
                                            </div>

                                            <!-- 1. Loại sản phẩm -->
                                            <div class="card mb-4 manual-step-card">
                                                <div class="card-header">
                                                    <h6 class="m-0 text-primary">
                                                        <span class="badge badge-primary badge-step mr-2">1</span>
                                                        <i class="fas fa-tags"></i> Loại sản phẩm <span class="text-danger">*</span>
                                                    </h6>
                                                </div>
                                                <div class="card-body">
                                                    <div class="form-group">
                                                        <select class="form-control form-control-lg" id="manualType" name="manual_type" required>
                                                            <option value="">-- Chọn loại sản phẩm --</option>
                                                            <option value="Giày Sneaker">Giày Sneaker</option>
                                                            <option value="Giày Boot">Giày Boot</option>
                                                            <option value="Giày Sandal">Giày Sandal</option>
                                                            <option value="Giày cao gót">Giày cao gót</option>
                                                            <option value="Giày tây">Giày tây</option>
                                                            <option value="Giày lười">Giày lười</option>
                                                            <option value="Dép">Dép</option>
                                                            <option value="Giày thể thao">Giày thể thao</option>
                                                            <option value="Giày vải">Giày vải</option>
                                                            <option value="Giày da">Giày da</option>
                                                            <option value="other">🖊️ Nhập loại khác...</option>
                                                        </select>
                                                        <input type="text" class="form-control form-control-lg mt-2" id="manualTypeCustom" name="manual_type_custom" placeholder="Nhập loại sản phẩm tùy chỉnh" style="display: none;">
                                                        <small class="text-muted">Chọn từ danh sách hoặc nhập loại sản phẩm mới</small>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- 2. Thương hiệu -->
                                            <div class="card mb-4 manual-step-card">
                                                <div class="card-header">
                                                    <h6 class="m-0 text-primary">
                                                        <span class="badge badge-primary badge-step mr-2">2</span>
                                                        <i class="fas fa-certificate"></i> Thương hiệu <span class="text-danger">*</span>
                                                    </h6>
                                                </div>
                                                <div class="card-body">
                                                    <div class="form-group">
                                                        <input type="text" class="form-control form-control-lg" id="manualBrand" name="manual_brand" required>
                                                        <small class="text-muted">Ví dụ: Nike, Adidas, Lecos. Nhập "Unknown" nếu không có thương hiệu.</small>
                                                    </div>
                                                    <div class="mt-2">
                                                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="$('#manualBrand').val('Unknown'); generateProductName();">
                                                            <i class="fas fa-tag"></i> Unknown
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- 3. Đặc điểm -->
                                            <div class="card mb-4 manual-step-card">
                                                <div class="card-header">
                                                    <h6 class="m-0 text-primary">
                                                        <span class="badge badge-primary badge-step mr-2">3</span>
                                                        <i class="fas fa-star"></i> Đặc điểm
                                                    </h6>
                                                </div>
                                                <div class="card-body">
                                                    <div class="form-group">
                                                        <input type="text" class="form-control form-control-lg" id="manualFeatures" name="manual_features" placeholder="Ví dụ: quai ngang, cổ thấp, đính đá, thoáng khí...">
                                                        <small class="text-muted">Các đặc điểm nổi bật của sản phẩm (quai ngang, đính đá, cổ thấp, v.v.)</small>
                                                    </div>
                                                    <div class="mt-2">
                                                        <small class="text-info"><i class="fas fa-lightbulb"></i> <strong>Gợi ý:</strong></small><br>
                                                        <button type="button" class="btn btn-outline-info btn-sm mr-1 mb-1 feature-suggestion-btn" onclick="addFeature('quai ngang')">quai ngang</button>
                                                        <button type="button" class="btn btn-outline-info btn-sm mr-1 mb-1 feature-suggestion-btn" onclick="addFeature('quai chéo')">quai chéo</button>
                                                        <button type="button" class="btn btn-outline-info btn-sm mr-1 mb-1 feature-suggestion-btn" onclick="addFeature('cổ thấp')">cổ thấp</button>
                                                        <button type="button" class="btn btn-outline-info btn-sm mr-1 mb-1 feature-suggestion-btn" onclick="addFeature('cổ cao')">cổ cao</button>
                                                        <button type="button" class="btn btn-outline-info btn-sm mr-1 mb-1 feature-suggestion-btn" onclick="addFeature('đính đá')">đính đá</button>
                                                        <button type="button" class="btn btn-outline-info btn-sm mr-1 mb-1 feature-suggestion-btn" onclick="addFeature('thoáng khí')">thoáng khí</button>
                                                        <button type="button" class="btn btn-outline-info btn-sm mr-1 mb-1 feature-suggestion-btn" onclick="addFeature('chống nước')">chống nước</button>
                                                        <button type="button" class="btn btn-outline-info btn-sm mr-1 mb-1 feature-suggestion-btn" onclick="addFeature('đế cao su')">đế cao su</button>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- 4. Màu sắc -->
                                            <div class="card mb-4 manual-step-card">
                                                <div class="card-header">
                                                    <h6 class="m-0 text-primary">
                                                        <span class="badge badge-primary badge-step mr-2">4</span>
                                                        <i class="fas fa-palette"></i> Màu sắc <span class="text-danger">*</span>
                                                    </h6>
                                                </div>
                                                <div class="card-body">
                                                    <div class="form-group">
                                                        <input type="text" class="form-control form-control-lg" id="manualColor" name="manual_color" required>
                                                        <small class="text-muted">Ví dụ: Trắng, Đen, Nâu</small>
                                                    </div>
                                                    <div class="mt-2">
                                                        <small class="text-info"><i class="fas fa-palette"></i> <strong>Màu phổ biến:</strong></small><br>
                                                        <button type="button" class="btn btn-outline-dark btn-sm mr-1 mb-1 color-suggestion-btn" onclick="$('#manualColor').val('Đen'); generateProductName();">Đen</button>
                                                        <button type="button" class="btn btn-outline-secondary btn-sm mr-1 mb-1 color-suggestion-btn" onclick="$('#manualColor').val('Trắng'); generateProductName();">Trắng</button>
                                                        <button type="button" class="btn btn-outline-danger btn-sm mr-1 mb-1 color-suggestion-btn" onclick="$('#manualColor').val('Đỏ'); generateProductName();">Đỏ</button>
                                                        <button type="button" class="btn btn-outline-primary btn-sm mr-1 mb-1 color-suggestion-btn" onclick="$('#manualColor').val('Xanh'); generateProductName();">Xanh</button>
                                                        <button type="button" class="btn btn-outline-warning btn-sm mr-1 mb-1 color-suggestion-btn" onclick="$('#manualColor').val('Vàng'); generateProductName();">Vàng</button>
                                                        <button type="button" class="btn btn-outline-success btn-sm mr-1 mb-1 color-suggestion-btn" onclick="$('#manualColor').val('Xanh lá'); generateProductName();">Xanh lá</button>
                                                        <button type="button" class="btn btn-outline-info btn-sm mr-1 mb-1 color-suggestion-btn" onclick="$('#manualColor').val('Nâu'); generateProductName();">Nâu</button>
                                                        <button type="button" class="btn btn-outline-light btn-sm mr-1 mb-1 color-suggestion-btn" onclick="$('#manualColor').val('Xám'); generateProductName();">Xám</button>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- 5. Tên sản phẩm (Auto-generated) -->
                                            <div class="card mb-4 manual-step-card auto-generated-name">
                                                <div class="card-header">
                                                    <h6 class="m-0 text-success">
                                                        <span class="badge badge-success badge-step mr-2">5</span>
                                                        <i class="fas fa-magic"></i> Tên sản phẩm (Tự động tạo) <span class="text-danger">*</span>
                                                    </h6>
                                                </div>
                                                <div class="card-body">
                                                    <div class="form-group">
                                                        <input type="text" class="form-control form-control-lg" id="manualProductName" name="manual_product_name" required readonly>
                                                        <small class="text-success">
                                                            <i class="fas fa-info-circle"></i> Tên sản phẩm được tự động tạo theo công thức: 
                                                            <strong>Loại + Thương hiệu + Đặc điểm + Màu sắc</strong>
                                                        </small>
                                                    </div>
                                                    <div class="mt-2">
                                                        <button type="button" class="btn btn-outline-warning btn-sm" id="editProductNameBtn">
                                                            <i class="fas fa-edit"></i> Chỉnh sửa tên
                                                        </button>
                                                        <button type="button" class="btn btn-outline-success btn-sm" id="regenerateNameBtn">
                                                            <i class="fas fa-sync"></i> Tạo lại tên
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Duplicate Check Result Area -->
                                            <div id="manualDuplicateCheckResult" style="display: none;"></div>

                                            <!-- Thông tin bổ sung -->
                                            <div class="card mb-4">
                                                <div class="card-header">
                                                    <h6 class="m-0 text-secondary">
                                                        <i class="fas fa-info-circle"></i> Thông tin bổ sung
                                                    </h6>
                                                </div>
                                                <div class="card-body">
                                                    <div class="row">
                                                        <div class="col-md-12">
                                                            <div class="form-group">
                                                                <label for="manualDescription">Mô tả sản phẩm</label>
                                                                <textarea class="form-control" id="manualDescription" name="manual_description" rows="3" placeholder="Mô tả chi tiết về sản phẩm..."></textarea>
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <div class="row">
                                                        <div class="col-md-6">
                                                            <div class="form-group">
                                                                <label for="manualMaterial">Chất liệu</label>
                                                                <input type="text" class="form-control" id="manualMaterial" name="manual_material" placeholder="Ví dụ: Da thật, vải canvas, cao su">
                                                            </div>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <div class="form-group">
                                                                <label for="manualTags">Tags</label>
                                                                <input type="text" class="form-control" id="manualTags" name="manual_tags" placeholder="Các từ khóa, cách nhau bởi dấu phẩy">
                                                                <small class="text-muted">Ví dụ: thể thao, casual, nam, nữ</small>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- SKU và Giá -->
                                            <div class="card mb-4">
                                                <div class="card-header">
                                                    <h6 class="m-0 text-warning">
                                                        <i class="fas fa-barcode"></i> SKU và Giá bán
                                                    </h6>
                                                </div>
                                                <div class="card-body">
                                                    <div class="row">
                                                        <div class="col-md-6">
                                                            <div class="form-group">
                                                                <label for="manualBaseSku">SKU cơ sở <span class="text-danger">*</span></label>
                                                                <div class="input-group">
                                                                    <input type="text" class="form-control" id="manualBaseSku" name="manual_sku" required placeholder="VD: NIKE-AIR-123">
                                                                    <div class="input-group-append">
                                                                        <button type="button" class="btn btn-outline-primary" id="generateManualSkuBtn" title="Tự động tạo SKU">
                                                                            <i class="fas fa-magic"></i> Tạo SKU
                                                                        </button>
                                                                    </div>
                                                                </div>
                                                                <small class="form-text text-muted mb-2">
                                                                    <i class="fas fa-magic text-primary"></i> 
                                                                    <strong>Tự động cập nhật:</strong> SKU sẽ tự động tạo/cập nhật khi bạn thay đổi Loại sản phẩm hoặc Thương hiệu
                                                                </small>
                                                                <small class="form-text text-info" id="manualSkuExample">
                                                                    <i class="fas fa-info-circle"></i> 
                                                                    <strong>Mã SKU cơ sở sẽ được sử dụng để tạo SKU riêng cho từng size.</strong><br>
                                                                    <i class="fas fa-tag text-success"></i> Ví dụ: <code class="bg-light p-1 rounded">NIKE-AIR-123-38, NIKE-AIR-123-39, NIKE-AIR-123-40</code>
                                                                </small>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Size Management -->
                                            <div class="card mb-4">
                                                <div class="card-header">
                                                    <h6 class="m-0 text-primary">
                                                        <i class="fas fa-ruler"></i> Quản lý Size và Giá bán
                                                    </h6>
                                                </div>
                                                <div class="card-body">
                                                    <div id="manualSizeContainer">
                                                        <div class="size-row mb-3 p-3 border rounded">
                                                            <div class="row">
                                                                <div class="col-md-4">
                                                                    <label class="form-label">Size</label>
                                                                    <select class="form-control size-select" name="manual_sizes[]" required>
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
                                                                <div class="col-md-4">
                                                                    <label class="form-label">Giá bán (VNĐ) <small class="text-muted price-source"></small></label>
                                                                    <input type="number" class="form-control manual-price-input" name="manual_size_prices[]" placeholder="0" min="0" required>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Action Buttons -->
                                            <div class="text-center mt-4">
                                                <button type="button" class="btn btn-secondary mr-2" onclick="backToMethodSelector()">
                                                    <i class="fas fa-arrow-left"></i> Quay lại
                                                </button>
                                                <button type="button" class="btn btn-warning mr-2" id="checkManualDuplicatesBtn">
                                                    <i class="fas fa-search"></i> Kiểm tra trùng lặp
                                                </button>
                                                <button type="submit" class="btn btn-success" id="saveManualProductBtn">
                                                    <i class="fas fa-save"></i> Lưu sản phẩm
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Manual Right Sidebar -->
                            <div class="col-lg-4">
                                <div class="card shadow mb-4">
                                    <div class="card-header py-3 bg-gradient-success">
                                        <h6 class="m-0 font-weight-bold text-white">
                                            <i class="fas fa-lightbulb"></i> Hướng dẫn nhanh
                                        </h6>
                                    </div>
                                    <div class="card-body">
                                        <!-- Quy trình 6 bước -->
                                        <div class="mb-3">
                                            <div class="d-flex align-items-center mb-2">
                                                <span class="badge badge-primary mr-2" style="width: 24px; height: 24px; display: flex; align-items: center; justify-content: center;">1</span>
                                                <span class="small">📷 Upload ảnh (không bắt buộc)</span>
                                            </div>
                                            <div class="d-flex align-items-center mb-2">
                                                <span class="badge badge-primary mr-2" style="width: 24px; height: 24px; display: flex; align-items: center; justify-content: center;">2</span>
                                                <span class="small">�️ Chọn loại sản phẩm</span>
                                            </div>
                                            <div class="d-flex align-items-center mb-2">
                                                <span class="badge badge-primary mr-2" style="width: 24px; height: 24px; display: flex; align-items: center; justify-content: center;">3</span>
                                                <span class="small">🏆 Nhập thương hiệu</span>
                                            </div>
                                            <div class="d-flex align-items-center mb-2">
                                                <span class="badge badge-primary mr-2" style="width: 24px; height: 24px; display: flex; align-items: center; justify-content: center;">4</span>
                                                <span class="small">⭐ Nhập đặc điểm</span>
                                            </div>
                                            <div class="d-flex align-items-center mb-2">
                                                <span class="badge badge-primary mr-2" style="width: 24px; height: 24px; display: flex; align-items: center; justify-content: center;">5</span>
                                                <span class="small">🎨 Chọn màu sắc</span>
                                            </div>
                                            <div class="d-flex align-items-center mb-2">
                                                <span class="badge badge-success mr-2" style="width: 24px; height: 24px; display: flex; align-items: center; justify-content: center;">✓</span>
                                                <span class="small font-weight-bold text-success">Tên tự động tạo!</span>
                                            </div>
                                        </div>
                                        
                                        <hr>
                                        
                                        <!-- Tính năng nổi bật -->
                                        <div class="alert alert-info p-2 mb-2">
                                            <small><i class="fas fa-magic text-primary"></i> <strong>Tên tự động:</strong> Loại + Brand + Đặc điểm + Màu</small>
                                        </div>
                                        
                                        <div class="alert alert-warning p-2 mb-2">
                                            <small><i class="fas fa-shield-alt text-warning"></i> <strong>Kiểm tra trùng:</strong> Tự động so sánh với kho</small>
                                        </div>
                                        
                                        <div class="alert alert-success p-2 mb-3">
                                            <small><i class="fas fa-edit text-success"></i> <strong>Sửa trùng lặp:</strong> Chọn SP → Sửa thông tin</small>
                                        </div>
                                        
                                        <!-- Mẹo nhanh -->
                                        <div class="bg-light p-2 rounded">
                                            <small class="text-muted">
                                                <i class="fas fa-lightbulb text-warning"></i> <strong>Mẹo:</strong><br>
                                                • Dùng nút gợi ý để nhập nhanh<br>
                                                • SKU tự tạo khi chọn loại + brand<br>
                                                • Thêm tags giúp tìm kiếm dễ hơn
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- AI Workflow Right Sidebar - Hidden for better space utilization -->
                    </div>

                    <!-- Manual Success Section -->
                    <div id="manualSuccessSection" style="display: none;">
                        <div class="row">
                            <div class="col-lg-10 mx-auto">
                                <div class="card shadow mb-4">
                                    <div class="card-header py-3">
                                        <h6 class="m-0 font-weight-bold text-success">
                                            <i class="fas fa-check-circle"></i> Hoàn thành
                                        </h6>
                                    </div>
                                    <div class="card-body text-center">
                                        <div class="mb-4">
                                            <i class="fas fa-check-circle text-success" style="font-size: 4rem;"></i>
                                        </div>
                                        <h4 class="text-success mb-3">Sản phẩm đã được tạo thành công!</h4>
                                        <p class="text-muted mb-4">Sản phẩm của bạn đã được thêm vào hệ thống bằng nhập liệu thủ công.</p>
                                        
                                        <!-- Chi tiết sản phẩm vừa tạo -->
                                        <div id="manualSuccessDetails" class="text-left bg-light p-3 rounded mb-4">
                                            <!-- Sẽ được điền bởi JavaScript -->
                                        </div>
                                        
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <a href="danh_sach_san_pham.php" class="btn btn-primary btn-block">
                                                    <i class="fas fa-list"></i> Xem danh sách sản phẩm
                                                </a>
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <button type="button" class="btn btn-success btn-block" id="addAnotherManualProductBtn">
                                                    <i class="fas fa-plus"></i> Thêm sản phẩm khác
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <?php include 'includes/chan_trang.php'; ?>
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
                            <strong>Tiêu chí so sánh:</strong> Tên sản phẩm (25%), Thương hiệu (20%), Mô tả (20%), Loại (15%), Chất liệu (10%), Màu sắc (10%)
                        </div>
                        <p class="mb-0">
                            <strong>Vui lòng chọn một sản phẩm bên dưới (click vào card) và quyết định:</strong>
                        </p>
                        <ul class="mt-2 mb-0" style="padding-left: 20px;">
                            <li><strong>Sửa thông tin:</strong> Nếu đây là sản phẩm giống nhau và bạn muốn chỉnh sửa/bổ sung thông tin</li>
                            <li><strong>Tiếp tục thêm mới:</strong> Nếu đây là sản phẩm khác biệt và bạn muốn tạo mới hoàn toàn</li>
                        </ul>
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
                        <div class="col-md-12">
                            <div class="alert alert-info mb-3">
                                <i class="fas fa-info-circle"></i> 
                                <strong>Hướng dẫn:</strong>
                                <ul class="mb-0 mt-2" style="padding-left: 20px;">
                                    <li><strong>"Sửa thông tin":</strong> Chọn 1 sản phẩm trùng → Load thông tin vào form → Chỉnh sửa thủ công</li>
                                    <li><strong>"Tiếp tục thêm mới":</strong> Bỏ qua cảnh báo trùng lặp và tạo sản phẩm mới hoàn toàn</li>
                                </ul>
                            </div>
                        </div>
                        <div class="col-md-12 text-center">
                            <button type="button" class="btn btn-warning btn-lg mr-3" id="editProductManually">
                                <i class="fas fa-edit"></i> Sửa thông tin
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
                        <i class="fas fa-edit"></i> Chọn nguồn thông tin cập nhật
                    </h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-12 mb-3">
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> 
                                <strong>Lưu ý:</strong> Mã SKU cơ sở sẽ được giữ nguyên. 
                                Khi thêm size mới, hệ thống sẽ tự động tạo SKU riêng cho từng size theo định dạng: 
                                <code>MÃ-CƠ-SỞ-SIZE</code>
                                <br><br>
                                <i class="fas fa-tag text-success"></i> <strong>Ví dụ:</strong> 
                                Nếu sản phẩm có SKU cơ sở <code class="bg-light p-1 rounded">CHAR-SLIN-028</code> và bạn thêm 3 size (38, 39, 40),
                                hệ thống sẽ tạo: <code class="bg-success text-white p-1 rounded">CHAR-SLIN-028-38</code>, 
                                <code class="bg-success text-white p-1 rounded">CHAR-SLIN-028-39</code>, 
                                <code class="bg-success text-white p-1 rounded">CHAR-SLIN-028-40</code>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="card h-100 update-source-option" data-source="existing">
                                <div class="card-header bg-primary text-white text-center">
                                    <h6 class="mb-0"><i class="fas fa-database"></i> Từ sản phẩm hiện tại</h6>
                                </div>
                                <div class="card-body text-center">
                                    <i class="fas fa-archive fa-3x text-primary mb-3"></i>
                                    <h6>Sử dụng thông tin sản phẩm cũ</h6>
                                    <p class="text-muted">
                                        Lấy tất cả thông tin từ sản phẩm hiện có trong hệ thống. 
                                        Bạn có thể chỉnh sửa và thêm size mới.
                                    </p>
                                    <ul class="text-left small">
                                        <li>Giữ nguyên tên, mô tả, thương hiệu</li>
                                        <li>Giữ nguyên màu sắc, giá cả</li>
                                        <li>Giữ nguyên size hiện có</li>
                                        <li>Có thể thêm size mới</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card h-100 update-source-option" data-source="ai">
                                <div class="card-header bg-success text-white text-center">
                                    <h6 class="mb-0"><i class="fas fa-robot"></i> Từ AI phân tích</h6>
                                </div>
                                <div class="card-body text-center">
                                    <i class="fas fa-brain fa-3x text-success mb-3"></i>
                                    <h6>Sử dụng gợi ý từ AI</h6>
                                    <p class="text-muted">
                                        Sử dụng thông tin được AI phân tích từ ảnh mới upload. 
                                        Thông tin có thể chính xác hơn với ảnh hiện tại.
                                    </p>
                                    <ul class="text-left small">
                                        <li>Tên và mô tả từ AI</li>
                                        <li>Thương hiệu được AI nhận diện</li>
                                        <li>Màu sắc từ phân tích ảnh</li>
                                        <li>Giữ nguyên size và giá cũ</li>
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
                    <button type="button" class="btn btn-primary" id="confirmUpdateSource" disabled>
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

    <!-- SweetAlert2 for beautiful alerts -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        // Global function để hiển thị thông báo
        function showToast(message, type = 'info') {
            const iconClass = type === 'success' ? 'fas fa-check' : 
                             type === 'error' ? 'fas fa-exclamation-triangle' : 
                             'fas fa-info-circle';
                             
            const bgClass = type === 'success' ? 'alert-success' : 
                           type === 'error' ? 'alert-danger' : 
                           'alert-info';
                           
            const toast = `
                <div class="alert ${bgClass} alert-dismissible fade show toast-notification" 
                     style="position: fixed; top: 80px; right: 20px; z-index: 9999; max-width: 350px;">
                    <i class="${iconClass}"></i> ${message}
                    <button type="button" class="close" data-dismiss="alert">
                        <span>&times;</span>
                    </button>
                </div>
            `;
            $('body').append(toast);
            
            // Tự động ẩn sau 5 giây
            setTimeout(function() {
                $('.toast-notification').last().fadeOut(function() {
                    $(this).remove();
                });
            }, 5000);
        }

        $(document).ready(function() {
            let currentStep = 1;
            let aiData = null;
            let suggestions = null;
            let uploadedImages = [];
            let primaryImageIndex = 0; // Index của ảnh chính
            let isUpdateMode = false; // Chế độ cập nhật sản phẩm
            let updateProductId = null; // ID sản phẩm cần cập nhật
            let useExistingDataOnly = false; // Flag để chỉ sử dụng dữ liệu cũ, không cho AI ghi đè
            
            // User role from PHP session (global scope for access in all functions)
            window.userRole = '<?php echo $userRole; ?>';
            window.canReactivateProducts = ['admin', 'manager'].includes(window.userRole);
            console.log('User role:', window.userRole, '| Can reactivate:', window.canReactivateProducts);
            
            // Create local aliases for convenience (but window. versions are accessible everywhere)
            const userRole = window.userRole;
            const canReactivateProducts = window.canReactivateProducts;
            
            // Initialize size management
            updateRemoveButtons();

            // Manual entry variables (global scope)
            var manualUploadedImages = [];
            var duplicateCheckTimeout = null;

            // Method selector handling
            $('.method-btn').click(function() {
                const method = $(this).data('method');
                if (method === 'ai') {
                    $('#methodSelector').hide();
                    $('#aiWorkflow').show();
                } else if (method === 'manual') {
                    $('#methodSelector').hide();
                    $('#manualWorkflow').show();
                }
            });

            // Manual entry event handlers
            $('#manualType, #manualBrand, #manualFeatures, #manualColor').on('input change', function() {
                console.log('Manual form field changed:', $(this).attr('id'), '=', $(this).val()); // Debug
                
                generateProductName();
                
                clearTimeout(duplicateCheckTimeout);
                $('#manualDuplicateCheckResult').hide();
                
                // Auto check duplicates after 2 seconds of no input
                duplicateCheckTimeout = setTimeout(() => {
                    checkManualDuplicatesAuto();
                }, 2000);
            });

            // Handle manual type selection with custom input
            $('#manualType').on('change', function() {
                const selectedValue = $(this).val();
                const customInput = $('#manualTypeCustom');
                
                if (selectedValue === 'other') {
                    customInput.show().attr('required', true);
                    // Clear the main select value so the custom input becomes the source
                    $(this).removeAttr('required');
                } else {
                    customInput.hide().removeAttr('required').val('');
                    $(this).attr('required', true);
                }
            });

            // When custom type is entered, trigger the same events as main type field
            $('#manualTypeCustom').on('input', function() {
                console.log('Custom type changed:', $(this).val()); // Debug
                
                generateProductName();
                
                clearTimeout(duplicateCheckTimeout);
                $('#manualDuplicateCheckResult').hide();
                
                // Auto check duplicates after 2 seconds of no input
                duplicateCheckTimeout = setTimeout(() => {
                    checkManualDuplicatesAuto();
                }, 2000);
            });

            // Update SKU example when base SKU or sizes change
            $('#manualBaseSku').on('input change', function() {
                updateManualSkuExample();
            });

            // Update SKU example when sizes change and load price from database
            $(document).on('change', 'select[name="manual_sizes[]"]', function() {
                updateManualSkuExample();
                loadSizePrice($(this));
            });
            
            // Load price for AI form sizes
            $(document).on('change', 'select[name="sizes[]"]', function() {
                loadAISizePrice($(this));
            });

            // Function to add feature suggestions
            window.addFeature = function(feature) {
                const currentFeatures = $('#manualFeatures').val();
                if (currentFeatures) {
                    if (!currentFeatures.includes(feature)) {
                        $('#manualFeatures').val(currentFeatures + ', ' + feature);
                    }
                } else {
                    $('#manualFeatures').val(feature);
                }
                generateProductName();
            };

            // Function to generate product name automatically
            // Expose to window scope for onclick handlers
            window.generateProductName = function() {
                let type = $('#manualType').val().trim();
                
                // If "other" is selected, use custom input instead
                if (type === 'other') {
                    type = $('#manualTypeCustom').val().trim();
                }
                
                const brand = $('#manualBrand').val().trim();
                const features = $('#manualFeatures').val().trim();
                const color = $('#manualColor').val().trim();
                
                let productName = '';
                
                if (type) productName += type;
                if (brand && brand.toLowerCase() !== 'unknown') {
                    productName += (productName ? ' ' : '') + brand;
                }
                if (features) {
                    productName += (productName ? ' ' : '') + features;
                }
                if (color) {
                    productName += (productName ? ' ' : '') + color;
                }
                
                if (productName) {
                    $('#manualProductName').val(productName);
                }
            }

            // Edit product name functionality
            $('#editProductNameBtn').click(function() {
                const $input = $('#manualProductName');
                if ($input.prop('readonly')) {
                    $input.prop('readonly', false).focus();
                    $(this).html('<i class="fas fa-save"></i> Lưu tên').removeClass('btn-outline-warning').addClass('btn-warning');
                } else {
                    $input.prop('readonly', true);
                    $(this).html('<i class="fas fa-edit"></i> Chỉnh sửa tên').removeClass('btn-warning').addClass('btn-outline-warning');
                    showToast('Đã lưu tên sản phẩm tùy chỉnh', 'success');
                }
            });

            // Regenerate product name
            $('#regenerateNameBtn').click(function() {
                $('#manualProductName').prop('readonly', true);
                $('#editProductNameBtn').html('<i class="fas fa-edit"></i> Chỉnh sửa tên').removeClass('btn-warning').addClass('btn-outline-warning');
                generateProductName();
                showToast('Đã tạo lại tên sản phẩm tự động', 'success');
            });

            $('#checkManualDuplicatesBtn').click(function() {
                checkManualDuplicatesManual();
            });

            $('#generateManualSkuBtn').click(function() {
                generateManualSku();
            });

            // Auto-update SKU when product info changes for manual entry
            let manualSkuUpdateTimeout;
            $('#manualProductName, #manualBrand, #manualType').on('input blur', function() {
                console.log('🔄 Manual product field changed:', $(this).attr('id'), '=', $(this).val());
                clearTimeout(manualSkuUpdateTimeout);
                manualSkuUpdateTimeout = setTimeout(() => {
                    console.log('⏰ Triggering SKU auto-update after timeout');
                    // Always call autoGenerateManualSku to check if SKU should be updated
                    autoGenerateManualSku();
                    updateManualSkuExample();
                }, 300);
            });

            $('#manualBaseSku').on('input', function() {
                updateManualSkuExample();
            });

            // Manual file upload
            $('#manualFileInput').change(function(e) {
                handleManualFileUpload(Array.from(e.target.files));
            });

            // Manual form submission
            $('#manualProductForm').submit(function(e) {
                e.preventDefault();
                saveManualProduct();
            });

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
                if (files.length < 2 || files.length > 3) {
                    alert('Vui lòng chọn 2-3 ảnh');
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
                if (files.length < 2 || files.length > 3) {
                    alert('Vui lòng chọn 2-3 ảnh');
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
                            window.uploadedImages = uploadedImages; // Make it globally accessible
                            
                            // **NEW: Backup to localStorage for recovery**
                            try {
                                localStorage.setItem('uploadedImages', JSON.stringify(uploadedImages));
                                console.log('📦 Backed up uploaded images to localStorage');
                            } catch (e) {
                                console.warn('Failed to backup to localStorage:', e);
                            }
                            
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
                    <p class="text-muted">Kéo thả hoặc nhấn để chọn 2-3 ảnh</p>
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
                    if (files.length < 2 || files.length > 3) {
                        alert('Vui lòng chọn 2-3 ảnh');
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
                $('#aiResults').show(); // Show first to ensure selectors work
                $('#aiResults .ai-analysis-alert').remove(); // Remove old analysis info
                $('#aiResults').hide(); // Then hide again
                $('#analyzedImages').empty();
                $('#suggestionCard').hide();
                $('#aiSuggestions').empty();
                $('#aiAutoFilledInfo').hide();
                $('#aiAutoFilledTable1').empty();
                $('#aiAutoFilledTable2').empty();
                $('#productTags').empty();
                $('#dominantColors').empty();
                $('#aiDuplicateCheckResult').hide().empty();
                $('#aiAutoFilledInfo').hide();
                $('#aiAutoFilledTable1').empty();
                $('#aiAutoFilledTable2').empty();
                
                // Move to step 2
                moveToStep(2);
                
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
                            
                            // Store suggestions globally for later use
                            window.lastAiSuggestions = suggestions;
                            console.log('🤖 AI Suggestions stored globally:', window.lastAiSuggestions);
                            
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

            // Function to format product name according to naming convention
            // Format: [Loại sản phẩm] [Thương hiệu] [Tính năng] [Màu sắc]
            function formatProductName(data) {
                let productName = '';
                
                // 1. Loại sản phẩm (Type) - BẮT BUỘC
                if (data.type) {
                    productName += data.type;
                }
                
                // 2. Thương hiệu (Brand) - bỏ qua nếu là "Unknown"
                if (data.brand && data.brand.toLowerCase() !== 'unknown') {
                    productName += (productName ? ' ' : '') + data.brand;
                }
                
                // 3. Tính năng (Features/Style) 
                if (data.features) {
                    productName += (productName ? ' ' : '') + data.features;
                } else if (data.style) {
                    productName += (productName ? ' ' : '') + data.style;
                }
                
                // 4. Màu sắc (Color)
                if (data.color) {
                    productName += (productName ? ' ' : '') + data.color;
                }
                
                // Fallback to original name if formatting fails
                if (!productName && data.name) {
                    productName = data.name;
                }
                
                console.log('📝 Formatted product name:', productName, 'from data:', data);
                return productName || 'Sản phẩm chưa có tên';
            }

            function displayAIResults(data) {
                console.log('🎯 AI Results from multiple images:', data);
                
                // FORMAT TÊN SẢN PHẨM THEO QUY TẮC
                const formattedName = formatProductName(data);
                data.name = formattedName; // Update data.name with formatted version
                
                // Display analyzed images info
                const imageCount = data.analyzed_images_count || uploadedImages.length;
                const confidence = data.confidence || 0.5;
                
                // Add multi-image analysis info
                let analysisInfo = `
                    <div class="alert alert-info mb-3 ai-analysis-alert">
                        <i class="fas fa-images mr-2"></i>
                        <strong>Đã phân tích ${imageCount} ảnh</strong>
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
            
            // Hàm chuẩn hóa tên loại sản phẩm
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
                    'de xuong': 'Giày đế xuồng',
                    'giày đế xuồng': 'Giày đế xuồng',
                    
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
                
                // 🔥 KIỂM TRA NẾU ĐÃ LÀ TIẾNG VIỆT THÌ KHÔNG TRANSLATE LẠI
                // Các màu tiếng Việt phổ biến
                const vietnameseColors = [
                    'đen', 'trắng', 'đỏ', 'xanh', 'vàng', 'cam', 'tím', 'hồng', 'nâu', 'xám',
                    'xanh dương', 'xanh lá', 'xanh navy', 'xanh lục', 'xanh bạc hà',
                    'trắng kem', 'vàng kim', 'đỏ burgundy', 'bạc', 'đồng', 'beige', 'nude',
                    'đậm', 'nhạt', 'tươi', 'pastel', 'kem'
                ];
                
                const colorLowerCheck = color.trim().toLowerCase();
                
                // Kiểm tra xem có chứa từ tiếng Việt không
                const hasVietnamese = vietnameseColors.some(vnColor => colorLowerCheck.includes(vnColor));
                
                if (hasVietnamese) {
                    // Nếu đã là tiếng Việt, chỉ capitalize và return, KHÔNG translate lại
                    console.log(`✅ Color already in Vietnamese: "${color}" - Keeping as is`);
                    return color.charAt(0).toUpperCase() + color.slice(1);
                }
                
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
            
            /**
             * Hàm chuẩn hóa thương hiệu sản phẩm
             * Tất cả các thương hiệu không xác định sẽ được chuyển về "Unknown"
             */
            window.standardizeBrand = function(brand) {
                // Danh sách các giá trị brand cần chuyển thành "Unknown"
                const unknownBrands = [
                    // Fashion (thường bị AI nhận diện sai)
                    'fashion',
                    
                    // Không xác định (tiếng Việt)
                    'không xác định', 'khong xac dinh', 'chưa xác định', 'chua xac dinh',
                    'không rõ', 'khong ro', 'chưa rõ', 'chua ro',
                    
                    // Không thương hiệu
                    'không thương hiệu', 'khong thuong hieu', 
                    'không có thương hiệu', 'khong co thuong hieu',
                    'chưa có thương hiệu', 'chua co thuong hieu',
                    
                    // Không nhãn hiệu
                    'không nhãn hiệu', 'khong nhan hieu', 
                    'không có nhãn hiệu', 'khong co nhan hieu',
                    'không nhãn', 'khong nhan', 'chưa có nhãn', 'chua co nhan',
                    
                    // Không brand
                    'không brand', 'khong brand', 
                    'không có brand', 'khong co brand',
                    
                    // English variants
                    'no brand', 'nobrand', 'no-brand',
                    'unbranded', 'non-branded', 'non branded',
                    'undefined', 'none', 'null',
                    
                    // N/A variants
                    'n/a', 'na', 'n.a', 'n.a.',
                    
                    // Ký tự đặc biệt
                    '-', '--', '---', '_', '__', '___',
                    '?', '??', '???',
                    
                    // Empty
                    ''
                ];
                
                // Nếu brand rỗng hoặc chỉ có khoảng trắng
                if (!brand || !brand.trim()) {
                    return 'Unknown';
                }
                
                // Chuẩn hóa để so sánh
                const brandLower = brand.trim().toLowerCase();
                
                // Kiểm tra trong danh sách unknown brands
                if (unknownBrands.includes(brandLower)) {
                    console.log(`⚠️ Standardizing brand "${brand}" to "Unknown"`);
                    return 'Unknown';
                }
                
                // Giữ nguyên brand hợp lệ (capitalize first letter)
                return brand.trim().charAt(0).toUpperCase() + brand.trim().slice(1);
            };
            
            // Hàm chuẩn hóa danh sách màu sắc
            function normalizeColors(colors) {
                if (!colors) return [];
                
                let colorArray = [];
                
                // Nếu là mảng, chuẩn hóa từng phần tử
                if (Array.isArray(colors)) {
                    colorArray = colors.map(color => standardizeColor(color));
                }
                // Nếu là chuỗi, tách và chuẩn hóa
                else if (typeof colors === 'string') {
                    colorArray = colors.split(',').map(color => standardizeColor(color.trim())).filter(c => c);
                }
                
                // 🔥 XÓA DUPLICATE TRONG MỖI MÀU
                const deduplicatedColors = colorArray.map(color => {
                    // Tách từng từ và xóa duplicate liên tiếp
                    const words = color.split(/\s+/);
                    const deduped = [];
                    let lastWord = '';
                    
                    for (const word of words) {
                        if (word.toLowerCase() !== lastWord.toLowerCase()) {
                            deduped.push(word);
                            lastWord = word;
                        }
                    }
                    
                    return deduped.join(' ');
                }).filter(c => c);
                
                console.log('🎨 Normalized colors:', colorArray, '→', deduplicatedColors);
                
                return deduplicatedColors;
            }
            
            // Hàm hiển thị thông tin AI đã tự động điền
            function displayAIAutoFilledInfo(data) {
                console.log('📝 Displaying AI auto-filled information:', data);
                
                // Chuẩn hóa thương hiệu - Chuyển các giá trị không xác định thành "Unknown"
                const originalBrand = data.brand;
                data.brand = window.standardizeBrand(data.brand);
                if (originalBrand !== data.brand) {
                    console.log(`🏷️ Standardized brand: "${originalBrand}" -> "${data.brand}"`);
                } else {
                    console.log(`✅ Keeping valid brand: "${data.brand}"`);
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
                            
                            // Move to step 3
                            moveToStep(3);
                            
                            // Populate form with existing data
                            populateFormWithExistingData(response.product);
                            
                            // Update UI for update mode
                            updateUIForMode();
                            
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
                            
                            // Move to step 3
                            moveToStep(3);
                            
                            // Populate form with mixed data (AI suggestions + existing data)
                            populateFormWithMixedData(response.product, aiData);
                            
                            // Update UI for update mode
                            updateUIForMode();
                            
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
            
            // Function to load product data for manual editing (Sửa thông tin)
            // Lấy tất cả thông tin từ database và điền vào form để người dùng chỉnh sửa thủ công
            function loadProductDataForManualEdit(productId, productName) {
                console.log('✏️ Loading product data for manual edit - ID:', productId);
                
                // Show loading
                $('#aiResults').prepend('<div class="alert alert-info ai-analysis-alert" id="loadingProductData"><i class="fas fa-spinner fa-spin"></i> Đang tải đầy đủ thông tin sản phẩm...</div>');
                
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
                            const product = response.product;
                            
                            // KHÔNG set update mode - đây là chế độ thêm mới dựa trên thông tin cũ
                            isUpdateMode = false;
                            updateProductId = null;
                            
                            // Move to step 3 - form nhập liệu thủ công
                            moveToStep(3);
                            
                            // Điền đầy đủ thông tin vào form
                            $('#productName').val(product.name || '');
                            $('#brand').val(product.brand || '');
                            $('#type').val(product.type || '');
                            $('#productDescription').val(product.description || '');
                            $('#material').val(product.material || '');
                            $('#features').val(product.features || '');
                            $('#tags').val(product.tags || '');
                            $('#color').val(product.color || '');
                            $('#price').val(product.price || '');
                            
                            // SKU - tạo SKU mới dựa trên SKU cũ
                            // Người dùng có thể chỉnh sửa nếu muốn
                            if (product.sku) {
                                $('#baseSku').val(product.sku).prop('readonly', false);
                            }
                            
                            // Load sizes nếu có - KHÔNG khóa, cho phép chỉnh sửa
                            if (product.sizes && product.sizes.length > 0) {
                                $('#sizeContainer').empty();
                                product.sizes.forEach(sizeData => {
                                    // Thêm như size mới, KHÔNG phải existing
                                    addSizeRow(sizeData.size, sizeData.price, false);
                                });
                            }
                            
                            // Hiển thị thông báo chế độ sửa thủ công
                            $('#productForm').prepend(`
                                <div class="alert alert-success alert-dismissible fade show" id="manualEditAlert">
                                    <button type="button" class="close" data-dismiss="alert">
                                        <span>&times;</span>
                                    </button>
                                    <i class="fas fa-edit"></i> <strong>Chế độ sửa thông tin thủ công:</strong> 
                                    Đã load thông tin từ sản phẩm "${productName}" (ID: ${productId})
                                    <br><small class="mt-2 d-block">
                                        <i class="fas fa-info-circle"></i> 
                                        Bạn có thể chỉnh sửa mọi thông tin bên dưới. 
                                        Khi lưu, hệ thống sẽ tạo một biến thể mới hoặc cập nhật sản phẩm hiện tại với thông tin bạn đã sửa.
                                    </small>
                                </div>
                            `);
                            
                            // Scroll to form
                            $('html, body').animate({
                                scrollTop: $('#productForm').offset().top - 100
                            }, 500);
                            
                            console.log('✅ Product data loaded for manual editing');
                            console.log('📝 Form values:', {
                                name: $('#productName').val(),
                                brand: $('#brand').val(),
                                sku: $('#baseSku').val(),
                                price: $('#price').val()
                            });
                        } else {
                            alert('Lỗi khi tải thông tin sản phẩm: ' + response.message);
                        }
                    },
                    error: function(xhr, status, error) {
                        $('#loadingProductData').remove();
                        console.error('❌ Error loading product:', error);
                        alert('Lỗi khi tải thông tin sản phẩm. Vui lòng thử lại.');
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
                
                // For SKU and product code - show existing but don't allow edit
                $('#baseSku').val(product.sku || '').prop('readonly', true); // Correct ID
                
                console.log('✅ Form populated with existing data - SKU:', product.sku);
                console.log('📄 Current form values after populate:', {
                    name: $('#productName').val(),
                    brand: $('#brand').val(),
                    sku: $('#baseSku').val(),
                    color: $('#color').val(),
                    description: $('#productDescription').val()
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
                
                // Always keep existing price
                $('#price').val(existingProduct.price || '');
                
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
            
            // Function to add a size row (for both existing and new). Now accepts price instead of minQuantity
            function addSizeRow(size = '', price = '', isExisting = false) {
                const sizeIndex = $('#sizeContainer .size-row').length;
                
                // Generate all size options với thiết kế đồng nhất
                const sizeOptions = ['35', '36', '37', '38', '39', '40', '41', '42', '43', '44', '45', '46', '47', '48']
                    .map(sizeOption => `<option value="${sizeOption}" ${size == sizeOption ? 'selected' : ''}>${sizeOption}</option>`)
                    .join('');
                
                const newSizeRow = `
                    <div class="size-row mb-2" data-size-index="${sizeIndex}" ${isExisting ? 'data-existing="true"' : ''}>
                        <div class="row align-items-end">
                            <div class="col-md-3">
                                <label class="form-label small">Size:</label>
                                ${isExisting ? `
                                    <select class="form-control form-control-sm size-select" disabled>
                                        <option value="${size}" selected>${size}</option>
                                    </select>
                                    <input type="hidden" name="existing_sizes[]" value="${size}">
                                ` : `
                                    <select name="sizes[]" class="form-control form-control-sm size-select" required onchange="checkDuplicateSizes()">
                                        <option value="">Chọn size...</option>
                                        ${sizeOptions}
                                    </select>
                                `}
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small">Giá bán (đ): <small class="text-muted price-source"></small></label>
                                ${isExisting ? `
                                    <input type="text" class="form-control form-control-sm" 
                                           value="${price ? parseFloat(price).toLocaleString('vi-VN') : '0'}" 
                                           readonly style="text-align: right; background-color: #f8f9fa; border: 1px solid #dee2e6;">
                                    <input type="hidden" name="existing_size_prices[]" value="${price}">
                                ` : `
                                    <input type="number" name="size_prices[]" class="form-control form-control-sm ai-price-input" 
                                           placeholder="Nhập giá cho size này" min="0" value="${price}"
                                           style="text-align: right;">
                                `}
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small">Trạng thái:</label>
                                ${isExisting ? `
                                    <div class="badge badge-info w-100 py-2">
                                        <i class="fas fa-database"></i> Hiện có
                                    </div>
                                ` : `
                                    <div class="badge badge-success w-100 py-2">
                                        <i class="fas fa-plus"></i> Thêm mới  
                                    </div>
                                `}
                            </div>
                        </div>
                        ${isExisting ? `
                        <div class="row mt-2">
                            <div class="col-12">
                                <div class="alert alert-info py-2 mb-0" style="font-size: 0.85rem;">
                                    <i class="fas fa-info-circle me-2"></i>
                                    <strong>Thông báo:</strong> Size này đã tồn tại trong database - không thể chỉnh sửa
                                </div>
                            </div>
                        </div>
                        ` : ''}
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
                    addSizeRow(sizeData.size, sizeData.price, true);
                });
                
                // Ensure at least one empty row for new sizes
                addSizeRow('', '', false);
                
                updateRemoveButtons();
            }
            
            // Function to update UI based on mode (add new vs update)
            function updateUIForMode() {
                if (isUpdateMode) {
                    // **NEW: Update hidden form fields for update mode**
                    $('#isUpdateMode').val('true');
                    $('#updateProductId').val(updateProductId);
                    console.log('🔧 Updated hidden fields: is_update_mode=true, update_product_id=' + updateProductId);
                    
                    // Update button text
                    $('button[type="submit"]').html('<i class="fas fa-edit"></i> Cập nhật sản phẩm');
                    
                    // Update "Add Size" button text and style for update mode
                    $('#addSizeBtn').html('<i class="fas fa-plus-circle"></i> Thêm size mới (khác size đã có)')
                                    .removeClass('btn-success')
                                    .addClass('btn-primary');
                    
                    // Update note text
                    const noteElement = $('.alert-info:contains("Lưu ý:")');
                    if (noteElement.length) {
                        noteElement.html(`
                            <strong>Lưu ý:</strong> Đây là bước cập nhật danh mục size cho sản phẩm. 
                            Thông tin mới từ AI sẽ được cập nhật vào sản phẩm hiện có.
                        `);
                    }
                    
                    // Disable SKU inputs trong chế độ cập nhật
                    $('#baseSku').prop('readonly', true);
                    $('#manualBaseSku').prop('readonly', true);
                    
                    // Thêm ghi chú về SKU bị khóa nếu chưa có
                    if (!$('#skuUpdateNote').length) {
                        $('#baseSku').after('<small id="skuUpdateNote" class="form-text text-danger"><i class="fas fa-lock"></i> Không thể chỉnh sửa SKU trong chế độ cập nhật sản phẩm</small>');
                        $('#manualBaseSku').after('<small id="manualSkuUpdateNote" class="form-text text-danger"><i class="fas fa-lock"></i> Không thể chỉnh sửa SKU trong chế độ cập nhật sản phẩm</small>');
                    }
                    
                    // **NEW: Set readonly cho loại sản phẩm, màu sắc, thương hiệu trong chế độ cập nhật**
                    $('#type').prop('readonly', true).css('background-color', '#e9ecef');
                    $('#color').prop('readonly', true).css('background-color', '#e9ecef');
                    $('#brand').prop('readonly', true).css('background-color', '#e9ecef');
                    
                    // Thêm ghi chú về các trường bị khóa nếu chưa có
                    if (!$('#typeUpdateNote').length) {
                        $('#type').after('<small id="typeUpdateNote" class="form-text text-muted"><i class="fas fa-lock"></i> Loại sản phẩm không thể thay đổi trong chế độ cập nhật</small>');
                    }
                    if (!$('#colorUpdateNote').length) {
                        $('#color').after('<small id="colorUpdateNote" class="form-text text-muted"><i class="fas fa-lock"></i> Màu sắc không thể thay đổi trong chế độ cập nhật</small>');
                    }
                    if (!$('#brandUpdateNote').length) {
                        $('#brand').after('<small id="brandUpdateNote" class="form-text text-muted"><i class="fas fa-lock"></i> Thương hiệu không thể thay đổi trong chế độ cập nhật</small>');
                    }
                    
                } else {
                    // Ensure original text for add new mode
                    $('button[type="submit"]').html('<i class="fas fa-save"></i> Lưu sản phẩm');
                    
                    // Reset "Add Size" button for normal mode
                    $('#addSizeBtn').html('<i class="fas fa-plus"></i> Thêm size khác')
                                    .removeClass('btn-primary')
                                    .addClass('btn-success');
                    
                    // Enable lại SKU inputs khi không trong chế độ cập nhật
                    $('#baseSku').prop('readonly', false);
                    $('#manualBaseSku').prop('readonly', false);
                    $('#skuUpdateNote').remove();
                    $('#manualSkuUpdateNote').remove();
                    
                    // **NEW: Enable lại các trường type, color, brand khi không trong chế độ cập nhật**
                    $('#type').prop('readonly', false).css('background-color', '');
                    $('#color').prop('readonly', false).css('background-color', '');
                    $('#brand').prop('readonly', false).css('background-color', '');
                    $('#typeUpdateNote').remove();
                    $('#colorUpdateNote').remove();
                    $('#brandUpdateNote').remove();
                }
            }
            
            // Function to check for duplicate products using all images
            function checkForDuplicates(aiData) {
                console.log('🔍 [ADD_PRODUCT] Checking duplicates with multi-image AI data:', aiData);
                console.log('🔍 [ADD_PRODUCT] Current warehouse context from session');
                
                // Show loading indicator inline (giống manual)
                $('#aiDuplicateCheckResult').html('<div class="alert alert-info"><i class="fas fa-spinner fa-spin"></i> Đang kiểm tra trùng lặp sản phẩm...</div>').show();
                
                // ===== SỬ DỤNG TRỰC TIẾP DỮ LIỆU TỪ aiData (ĐÃ ĐƯỢC CHUẨN HÓA) =====
                // Ưu tiên dữ liệu từ aiData thay vì lấy từ DOM
                const productType = aiData.type || aiData.category || '';
                const brand = aiData.brand || '';
                const productName = aiData.name || '';
                // Xử lý colors - có thể là array hoặc string
                const colors = Array.isArray(aiData.colors) ? aiData.colors.join(', ') : (aiData.colors || aiData.color || '');
                
                console.log('📋 [FILTER] Using AI data directly (after standardization):');
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
                const normalizedBrand = window.standardizeBrand(brand);
                
                // ===== GỬI REQUEST GIỐNG HỆT BÊN THỦ CÔNG =====
                // Sử dụng cùng API: api_kiem_tra_trung_thu_cong.php
                // Cùng action: check_manual_duplicates
                // Cùng tham số: product_name, brand, type, color
                checkDuplicatesWithAutoTranslate(productName, normalizedBrand, productType, colors);
            }
            
            // ===== HÀM KIỂM TRA TRÙNG LẶP VỚI TỰ ĐỘNG DỊCH =====
            function checkDuplicatesWithAutoTranslate(productName, brand, productType, colors, isRetry = false) {
                console.log(`🔍 [DUPLICATE CHECK] ${isRetry ? 'RETRY with Vietnamese' : 'INITIAL check'}:`, {
                    productName, brand, productType, colors
                });
                
                $.ajax({
                    url: 'api_kiem_tra_trung_thu_cong.php',
                    method: 'POST',
                    data: {
                        action: 'check_manual_duplicates',
                        product_name: productName,
                        brand: brand,
                        type: productType,
                        color: colors
                    },
                    dataType: 'json',
                    success: function(response) {
                        console.log('🔍 [DUPLICATE CHECK] Response:', response);
                        
                        // Nếu không tìm thấy trùng lặp VÀ chưa retry VÀ có text tiếng Anh
                        if (!response.has_duplicates && !isRetry && hasEnglishText(productType, colors)) {
                            console.log('⚠️ [DUPLICATE CHECK] No duplicates found with English text. Auto-translating to Vietnamese...');
                            
                            // Dịch sang tiếng Việt và thử lại
                            translateToVietnamese(productType, colors, function(translatedType, translatedColors) {
                                console.log('🌐 [TRANSLATION] Translated:', {
                                    type: `${productType} → ${translatedType}`,
                                    colors: `${colors} → ${translatedColors}`
                                });
                                
                                // Retry với bản dịch tiếng Việt
                                checkDuplicatesWithAutoTranslate(
                                    productName, 
                                    brand, 
                                    translatedType, 
                                    translatedColors, 
                                    true // Đánh dấu là retry
                                );
                            });
                        } else {
                            // Hiển thị kết quả
                            displayAIDuplicateResult(response, isRetry);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.log('❌ [DUPLICATE CHECK] Error:', error);
                        console.log('[DUPLICATE CHECK] Status:', status);
                        console.log('[DUPLICATE CHECK] Response:', xhr.responseText);
                        
                        $('#aiDuplicateCheckResult').html(`
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle"></i> 
                                Có lỗi khi kiểm tra trùng lặp: ${error}
                            </div>
                        `).show();
                    }
                });
            }
            
            // ===== HÀM KIỂM TRA CÓ TEXT TIẾNG ANH KHÔNG =====
            function hasEnglishText(productType, colors) {
                const text = (productType + ' ' + colors).toLowerCase();
                
                // Danh sách từ tiếng Anh phổ biến cho giày và màu sắc
                const englishKeywords = [
                    // Loại giày
                    'sneaker', 'shoe', 'boot', 'sandal', 'slipper', 'loafer', 'oxford', 
                    'running', 'training', 'basketball', 'football', 'tennis',
                    // Màu sắc
                    'black', 'white', 'red', 'blue', 'green', 'yellow', 'orange', 'purple',
                    'pink', 'brown', 'gray', 'grey', 'beige', 'navy', 'maroon',
                    'silver', 'gold', 'bronze', 'cream', 'tan', 'khaki',
                    // Từ mô tả
                    'dark', 'light', 'bright', 'pale', 'deep', 'neon'
                ];
                
                for (const keyword of englishKeywords) {
                    if (text.includes(keyword)) {
                        console.log(`✓ [ENGLISH CHECK] Found English keyword: "${keyword}" in "${text}"`);
                        return true;
                    }
                }
                
                return false;
            }
            
            // ===== HÀM DỊCH SANG TIẾNG VIỆT =====
            function translateToVietnamese(productType, colors, callback) {
                // Dictionary để dịch nhanh
                const translations = {
                    // Loại giày
                    'sneaker': 'Giày sneaker',
                    'running shoe': 'Giày chạy bộ',
                    'running shoes': 'Giày chạy bộ',
                    'shoe': 'Giày',
                    'shoes': 'Giày',
                    'boot': 'Giày boot',
                    'boots': 'Giày boot',
                    'sandal': 'Sandal',
                    'sandals': 'Sandal',
                    'slipper': 'Dép',
                    'slippers': 'Dép',
                    'loafer': 'Giày lười',
                    'loafers': 'Giày lười',
                    'oxford': 'Giày tây',
                    'training shoe': 'Giày tập luyện',
                    'basketball shoe': 'Giày bóng rổ',
                    'football shoe': 'Giày bóng đá',
                    'tennis shoe': 'Giày tennis',
                    
                    // Màu sắc
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
                    'beige': 'Be',
                    'navy': 'Xanh navy',
                    'maroon': 'Đỏ sẫm',
                    'silver': 'Bạc',
                    'gold': 'Vàng kim',
                    'bronze': 'Đồng',
                    'cream': 'Kem',
                    'tan': 'Nâu nhạt',
                    'khaki': 'Kaki',
                    
                    // Từ mô tả
                    'dark': 'Tối',
                    'light': 'Nhạt',
                    'bright': 'Sáng',
                    'pale': 'Nhạt',
                    'deep': 'Đậm',
                    'neon': 'Neon'
                };
                
                // Dịch loại sản phẩm
                let translatedType = productType;
                const typeLower = productType.toLowerCase();
                
                for (const [eng, vie] of Object.entries(translations)) {
                    const regex = new RegExp('\\b' + eng + '\\b', 'gi');
                    translatedType = translatedType.replace(regex, vie);
                }
                
                // Dịch màu sắc (có thể có nhiều màu, cách nhau bởi dấu phẩy)
                let translatedColors = colors;
                const colorList = colors.split(',').map(c => c.trim());
                const translatedColorList = [];
                
                for (const color of colorList) {
                    let translatedColor = color;
                    const colorLower = color.toLowerCase();
                    
                    // Kiểm tra exact match trước
                    if (translations[colorLower]) {
                        translatedColor = translations[colorLower];
                    } else {
                        // Dịch từng từ trong màu (ví dụ: "Dark Blue" -> "Xanh dương Tối")
                        for (const [eng, vie] of Object.entries(translations)) {
                            const regex = new RegExp('\\b' + eng + '\\b', 'gi');
                            translatedColor = translatedColor.replace(regex, vie);
                        }
                    }
                    
                    translatedColorList.push(translatedColor);
                }
                
                translatedColors = translatedColorList.join(', ');
                
                console.log('🌐 [TRANSLATION] Results:', {
                    original_type: productType,
                    translated_type: translatedType,
                    original_colors: colors,
                    translated_colors: translatedColors
                });
                
                // Callback với kết quả dịch
                callback(translatedType, translatedColors);
            }
            
            // ===== FUNCTION HIỂN THỊ KẾT QUẢ GIỐNG HỆT BÊN THỦ CÔNG =====
            // Copy 100% từ displayManualDuplicateResult()
            function displayAIDuplicateResult(response, isRetry = false) {
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
                    // Thêm badge nếu là kết quả sau khi dịch
                    const retryBadge = isRetry ? '<span class="badge badge-info ml-2"><i class="fas fa-language"></i> Đã dịch sang tiếng Việt</span>' : '';
                    
                    html = `
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i>
                            <strong>Tuyệt vời!</strong> Không tìm thấy sản phẩm trùng lặp trong kho.
                            ${retryBadge}
                        </div>
                    `;
                } else {
                    // Xác định mức cảnh báo GIỐNG HỆT manual
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
                    
                    // Thêm badge nếu là kết quả sau khi dịch
                    const retryBadge = isRetry ? '<span class="badge badge-info ml-2"><i class="fas fa-language"></i> Tìm thấy sau khi dịch sang tiếng Việt</span>' : '';
                    
                    html = `
                        <div class="alert ${alertClass}">
                            <h6><i class="${icon}"></i> ${message} ${retryBadge}</h6>
                            <p class="mb-3">Tìm thấy <strong>${response.duplicates.length}</strong> sản phẩm tương tự với độ tương đồng cao nhất: <strong>${response.max_similarity}%</strong></p>
                            
                            <div class="similar-products-list">
                    `;
                    
                    response.duplicates.forEach(function(product) {
                        const badgeClass = product.similarity >= 85 ? 'badge-danger' : 
                                         product.similarity >= 70 ? 'badge-warning' : 'badge-info';
                        
                        const isInactive = product.status === 'inactive';
                        const cardBorderClass = isInactive ? 'border-warning' : '';
                        const statusBadge = isInactive ? '<span class="badge badge-warning ml-2" style="font-size: 0.85rem;"><i class="fas fa-pause-circle"></i> Tạm ngừng</span>' : '';
                        const imageOpacity = isInactive ? 'style="max-width: 80px; opacity: 0.6;"' : 'style="max-width: 80px;"';
                        
                        // Xử lý theo role: admin/manager có thể click và kích hoạt, employee chỉ xem
                        let cursorStyle, clickHandler;
                        if (isInactive) {
                            if (canReactivateProducts) {
                                // Admin/Manager: có thể click để xem chi tiết nhưng không chọn để update
                                cursorStyle = 'cursor: pointer; opacity: 0.7;';
                                clickHandler = 'onclick="showInactiveWarningForAdmin()"';
                            } else {
                                // Employee: không thể click
                                cursorStyle = 'cursor: not-allowed; opacity: 0.7;';
                                clickHandler = 'onclick="showInactiveWarningForEmployee()"';
                            }
                        } else {
                            cursorStyle = 'cursor: pointer;';
                            clickHandler = 'onclick="selectAIDuplicateForUpdate(this)"';
                        }
                        
                        html += `
                            <div class="similar-product-item ai-duplicate-card border rounded p-3 mb-2 ${cardBorderClass}" 
                                 data-product-id="${product.product_id}"
                                 data-product-name="${product.name}"
                                 data-product-brand="${product.brand}"
                                 data-product-status="${product.status || 'active'}"
                                 style="${cursorStyle} transition: all 0.3s;"
                                 ${clickHandler}>
                                <div class="row align-items-center">
                                    <div class="col-md-2">
                                        ${product.image_url ? 
                                            `<img src="${product.image_url}" class="img-thumbnail" ${imageOpacity}>` :
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
                                        <span class="badge ${badgeClass} badge-lg">
                                            ${product.similarity}% tương đồng
                                        </span>
                                        <br>
                                        <button class="btn btn-sm btn-outline-primary mt-1 btn-view-product-details" 
                                                data-product-id="${product.product_id}"
                                                type="button">
                                            <i class="fas fa-eye"></i> Xem chi tiết
                                        </button>
                                        ${isInactive && canReactivateProducts ? `
                                        <br>
                                        <button class="btn btn-sm btn-success mt-1 btn-reactivate-product" 
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
                                    <small><i class="fas fa-info-circle"></i> ${canReactivateProducts ? 
                                        'Sản phẩm này đã tạm ngừng. Bạn có thể kích hoạt lại để sử dụng.' : 
                                        'Sản phẩm này đã tạm ngừng. Vui lòng liên hệ Admin/Quản lý để kích hoạt lại.'
                                    }</small>
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
                                <p class="text-info mb-2">
                                    <i class="fas fa-info-circle"></i> 
                                    <strong>Click vào sản phẩm bên trên để chọn cập nhật thông tin</strong>
                                </p>
                            </div>
                            <div class="mt-3">
                                <button class="btn btn-primary" id="updateSelectedDuplicateBtn" onclick="updateSelectedDuplicate()" disabled>
                                    <i class="fas fa-edit"></i> Cập nhật sản phẩm đã chọn
                                </button>
                            </div>
                        </div>
                    `;
                }
                
                $('#aiDuplicateCheckResult').html(html).slideDown();
            }
            
            // Global variable to track selected duplicate
            let selectedDuplicateProductId = null;
            window.selectedDuplicateProductId = null; // Make it globally accessible
            
            // Function to select duplicate product for update
            window.selectAIDuplicateForUpdate = function(element) {
                // Remove selection from all cards
                $('.ai-duplicate-card').removeClass('border-primary').css('background-color', '');
                $('.ai-duplicate-card .selected-indicator').hide();
                
                // Add selection to clicked card
                $(element).addClass('border-primary').css('background-color', '#f0f8ff');
                $(element).find('.selected-indicator').show();
                
                // Store selected product ID
                selectedDuplicateProductId = $(element).data('product-id');
                window.selectedDuplicateProductId = selectedDuplicateProductId; // Set global variable
                
                // Enable update button
                $('#updateSelectedDuplicateBtn').prop('disabled', false);
                
                console.log('✅ Đã chọn sản phẩm ID:', selectedDuplicateProductId);
            };
            
            // Function to update selected duplicate product
            window.updateSelectedDuplicate = function() {
                if (!selectedDuplicateProductId) {
                    showToast('Vui lòng chọn sản phẩm cần cập nhật', 'warning');
                    return;
                }
                
                const selectedCard = $(`.ai-duplicate-card[data-product-id="${selectedDuplicateProductId}"]`);
                const productName = selectedCard.data('product-name');
                const productBrand = selectedCard.data('product-brand');
                
                if (confirm(`Bạn có chắc muốn cập nhật sản phẩm "${productName}" (${productBrand})?`)) {
                    console.log('🔄 Chuyển sang chế độ cập nhật sản phẩm ID:', selectedDuplicateProductId);
                    
                    // Show loading while fetching product data
                    showToast('Đang tải thông tin sản phẩm từ database...', 'info');
                    
                    // Load existing product data FIRST, then setup update mode
                    loadProductDataForUpdate(selectedDuplicateProductId, function(productData) {
                        // Success callback - setup update mode with loaded data
                        window.aiUpdateMode = {
                            productId: selectedDuplicateProductId,
                            productName: productData.name,
                            productBrand: productData.brand,
                            loadedData: productData
                        };
                        
                        // Hide duplicate check result
                        $('#aiDuplicateCheckResult').slideUp();
                        
                        // Move to step 3 (edit) in update mode
                        moveToStep(3);
                        
                        // Wait for DOM to be ready, then fill form and show banner
                        setTimeout(function() {
                            // Set update mode flags
                            isUpdateMode = true;
                            updateProductId = selectedDuplicateProductId;
                            
                            // Fill form with database data
                            fillFormWithDatabaseData(productData);
                            
                            // Update UI for update mode (disable SKU inputs)
                            updateUIForMode();
                        }, 100);
                        
                        showToast(`✅ Đã tải thông tin sản phẩm "${productData.name}"`, 'success');
                    });
                }
            };
            
            // Function to show update mode banner

            // Function to cancel update mode
            window.cancelUpdateMode = function() {
                if (confirm('Bạn có chắc muốn hủy cập nhật và quay về chế độ thêm mới?')) {
                    // Reset update mode variables
                    window.aiUpdateMode = null;
                    selectedDuplicateProductId = null;
                    isUpdateMode = false;
                    updateProductId = null;
                    
                    // Remove update banners
                    $('#updateModeBanner').remove();
                    $('#updateModeAlert').remove();
                    
                    // Enable lại SKU input khi hủy chế độ cập nhật
                    $('#baseSku').prop('readonly', false);
                    $('#manualBaseSku').prop('readonly', false);
                    
                    // Xóa tất cả ghi chú về SKU bị khóa
                    $('#skuNote').remove();
                    $('#skuUpdateNote').remove();
                    $('#manualSkuUpdateNote').remove();
                    
                    // Reset form về trạng thái thêm mới
                    updateUIForMode();
                    
                    showToast('Đã hủy chế độ cập nhật', 'info');
                }
            };
            
            // Function to load product data for update
            function loadProductDataForUpdate(productId, successCallback) {
                console.log('📥 Đang tải dữ liệu sản phẩm ID:', productId);
                
                $.ajax({
                    url: 'api_lay_chi_tiet_san_pham.php',
                    method: 'GET',
                    data: { product_id: productId },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success && response.product) {
                            console.log('✅ Đã tải đầy đủ dữ liệu sản phẩm từ DB:', response.product);
                            
                            // Store complete product data
                            window.existingProductData = response.product;
                            
                            // Execute success callback with product data
                            if (typeof successCallback === 'function') {
                                successCallback(response.product);
                            }
                            
                        } else {
                            console.error('❌ API Error:', response.message);
                            showToast('Không thể tải thông tin sản phẩm: ' + (response.message || 'Lỗi không xác định'), 'error');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('❌ AJAX Error khi tải dữ liệu sản phẩm:', error);
                        console.error('Response:', xhr.responseText);
                        showToast('Lỗi khi tải thông tin sản phẩm: ' + error, 'error');
                    }
                });
            }
            
            // Function to fill form with database data
            function fillFormWithDatabaseData(productData) {
                console.log('📝 Điền form với TOÀN BỘ dữ liệu từ database:', productData);
                
                try {
                    // Debug form fields existence
                    console.log('🔍 Form fields check:');
                    console.log('  productName element exists:', $('#productName').length > 0);
                    console.log('  brand element exists:', $('#brand').length > 0);
                    console.log('  type element exists:', $('#type').length > 0);
                    console.log('  description element exists:', $('#description').length > 0);
                    console.log('  material element exists:', $('#material').length > 0);
                    console.log('  features element exists:', $('#features').length > 0);
                    console.log('  tags element exists:', $('#tags').length > 0);
                    console.log('  color element exists:', $('#color').length > 0);
                    
                    // 1. THÔNG TIN CƠ BẢN - Basic product info
                    console.log('🔸 Setting productName:', productData.name);
                    $('#productName').val(productData.name || '').trigger('change');
                    
                    console.log('🔸 Setting brand:', productData.brand);
                    $('#brand').val(productData.brand || '').trigger('change');
                    
                    console.log('🔸 Setting type:', productData.type);
                    $('#type').val(productData.type || '').trigger('change');
                    
                    console.log('🔸 Setting description (length: ' + (productData.description || '').length + '):', 
                        (productData.description || '').substring(0, 100) + '...');
                    
                    // Kiểm tra field mô tả - có thể là #description hoặc #productDescription
                    if ($('#description').length > 0) {
                        $('#description').val(productData.description || '').trigger('change');
                    } else if ($('#productDescription').length > 0) {
                        $('#productDescription').val(productData.description || '').trigger('change');
                    } else {
                        console.warn('⚠️ Không tìm thấy field description');
                    }
                    
                    console.log('🔸 Setting material:', productData.material);
                    $('#material').val(productData.material || '').trigger('change');
                    
                    console.log('🔸 Setting features:', productData.features);
                    $('#features').val(productData.features || '').trigger('change');
                    
                    console.log('🔸 Setting tags:', productData.tags);
                    $('#tags').val(productData.tags || '').trigger('change');
                    
                    // 2. SKU - Handle base_sku (tính từ variants)
                    if ($('#baseSku').length) {
                        const baseSku = productData.base_sku || '';
                        $('#baseSku').val(baseSku).trigger('change');
                        console.log('🔸 Setting baseSku (calculated from variants):', baseSku);
                        if (baseSku) {
                            $('#baseSku').prop('readonly', true);
                        }
                    }
                    
                    // 3. COLORS - CHỈ hiển thị dữ liệu từ database, KHÔNG merge AI tự động
                    const dbColors = productData.colors_string || '';
                    $('#color').val(dbColors).trigger('change');
                    console.log('🎨 DB colors set:', dbColors);
                    
                    // Lưu thông tin AI để sử dụng sau nếu user muốn merge
                    const availableAI = window.suggestions || window.lastAiSuggestions;
                    if (availableAI && availableAI.colors) {
                        window.aiColorsAvailable = Array.isArray(availableAI.colors) ? 
                            availableAI.colors.join(', ') : availableAI.colors;
                        console.log('🤖 AI colors available for merge:', window.aiColorsAvailable);
                    }
                    
                    // Ensure AI suggestions are preserved
                    if (!window.suggestions && window.lastAiSuggestions) {
                        window.suggestions = window.lastAiSuggestions;
                        console.log('🔄 Using lastAiSuggestions as current suggestions');
                    }
                    
                    // 4. CATEGORY - Category field removed - using type field instead
                    
                    // 5. VARIANTS - Load and display existing variants (sizes + prices)
                    if (productData.variants && productData.variants.length > 0) {
                        console.log('👕 Loading ' + productData.variants.length + ' variants from database');
                        displayExistingVariants(productData.variants);
                    } else {
                        console.log('👕 No variants found in database');
                    }
                    
                    // 6. IMAGES INFO - Log existing images for reference
                    if (productData.images && productData.images.length > 0) {
                        console.log('🖼️ Product has ' + productData.images.length + ' existing images:');
                        productData.images.forEach((img, index) => {
                            console.log('  Image ' + (index + 1) + ': ' + img.file_path + ' (Primary: ' + (img.is_primary ? 'Yes' : 'No') + ')');
                        });
                    }
                    
                    // 7. AI ANALYSIS INFO - Log AI analysis status
                    if (productData.ai_analyzed) {
                        console.log('🤖 Product was analyzed by AI on:', productData.created_at);
                    }
                    
                    // 8. UPDATE UI - Update AI suggestions display to show merge info
                    if (suggestions) {
                        updateAISuggestionsForUpdate(productData);
                    }
                    
                    // 9. FINAL VALIDATION - Validate all fields were filled
                    console.log('✅ VALIDATION - Kiểm tra form sau khi điền:');
                    console.log('  productName:', $('#productName').val());
                    console.log('  brand:', $('#brand').val());
                    console.log('  type:', $('#type').val());
                    console.log('  description field #description:', $('#description').val()?.length || 0, 'chars');
                    console.log('  description field #productDescription:', $('#productDescription').val()?.length || 0, 'chars');
                    console.log('  material:', $('#material').val());
                    console.log('  features:', $('#features').val());
                    console.log('  tags:', $('#tags').val());
                    console.log('  color:', $('#color').val());
                    console.log('  baseSku:', $('#baseSku').val());
                    console.log('  categoryId:', $('#categoryId').val());
                    console.log('  variants count:', $('#sizeContainer .size-row').length);
                    
                    // Kiểm tra fields bị thiếu
                    const missingFields = [];
                    if (!$('#productName').val()) missingFields.push('productName');
                    if (!$('#baseSku').val()) missingFields.push('baseSku'); 
                    if (!$('#description').val() && !$('#productDescription').val()) missingFields.push('description');
                    
                    if (missingFields.length > 0) {
                        console.warn('⚠️ CÁC FIELD BỊ THIẾU:', missingFields);
                        showToast('Một số thông tin chưa được tải: ' + missingFields.join(', '), 'warning');
                    } else {
                        console.log('✅ Tất cả thông tin chính đã được tải đầy đủ');
                    }
                    
                    console.log('✅ Form đã được điền với TOÀN BỘ dữ liệu database');
                    
                    // Show comprehensive summary
                    displayDataLoadSummary(productData);
                    
                    showToast('Đã tải đầy đủ thông tin sản phẩm: ' + productData.name, 'success');
                    
                } catch (error) {
                    console.error('❌ Lỗi khi điền form:', error);
                    showToast('Có lỗi khi điền form với dữ liệu sản phẩm: ' + error.message, 'error');
                }
            }
            
            // Function to display existing variants in form với thiết kế đồng nhất
            function displayExistingVariants(variants) {
                console.log('👕 Hiển thị ' + variants.length + ' variants hiện có:', variants);
                
                // Clear existing size rows
                $('#sizeContainer').empty();
                
                // Store existing sizes globally for validation
                window.existingVariantSizes = variants.map(v => v.size);
                console.log('📌 Đã lưu các size hiện có:', window.existingVariantSizes);
                
                // Add header for existing variants
                if (variants.length > 0) {
                    $('#sizeContainer').append(`
                        <div class="alert alert-warning mb-3">
                            <i class="fas fa-info-circle"></i> 
                            <strong>VARIANTS HIỆN CÓ:</strong> Tìm thấy ${variants.length} size trong database. 
                            <span class="text-danger">Size cũ chỉ hiển thị và chỉ được cập nhật giá bán.</span> 
                            Bạn có thể thêm size mới bên dưới.
                        </div>
                    `);
                }
                
                // Add each variant as a READONLY size row (except price field)
                variants.forEach(function(variant, index) {
                    console.log('  Processing variant ' + (index + 1) + ':', variant);
                    
                    const formattedPrice = variant.price ? parseFloat(variant.price).toLocaleString('vi-VN') : '0';
                    
                    const sizeRow = `
                        <div class="size-row mb-3 existing-variant" data-variant-id="${variant.variant_id}" data-existing-size="${variant.size}">
                            <div class="card border-info">
                                <div class="card-header bg-info text-white py-2">
                                    <small><i class="fas fa-lock"></i> Variant hiện có - Chỉ được cập nhật giá</small>
                                </div>
                                <div class="card-body">
                                    <div class="row align-items-end">
                                        <div class="col-md-3">
                                            <label class="form-label small">Size:</label>
                                            <input type="text" class="form-control form-control-sm bg-light" 
                                                   value="${variant.size}" 
                                                   readonly
                                                   style="background-color: #e9ecef !important; cursor: not-allowed; font-weight: bold;">
                                            <input type="hidden" name="existing_sizes[]" value="${variant.size}">
                                            <input type="hidden" name="existing_variant_ids[]" value="${variant.variant_id}">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label small">Giá bán (đ): <span class="text-success">✓ Có thể sửa</span></label>
                                            <input type="number" class="form-control form-control-sm border-success" 
                                                   name="existing_prices[]" 
                                                   placeholder="Nhập giá mới" min="0" 
                                                   value="${variant.price || ''}"
                                                   style="text-align: right; font-weight: bold;">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label small">Màu sắc:</label>
                                            <input type="text" class="form-control form-control-sm bg-light" 
                                                   value="${variant.color || 'Không có'}" 
                                                   readonly 
                                                   style="background-color: #e9ecef !important; cursor: not-allowed;">
                                        </div>
                                    </div>
                                    <div class="row mt-3">
                                        <div class="col-12">
                                            <div class="card" style="background: linear-gradient(135deg, #d1ecf1 0%, #bee5eb 100%); border: 1px solid #17a2b8; border-radius: 8px;">
                                                <div class="card-body py-2 px-3">
                                                    <div class="row g-0 align-items-center text-center">
                                                        <div class="col-md-4 col-sm-12 mb-1 mb-md-0">
                                                            <div class="d-flex align-items-center justify-content-center justify-content-md-start">
                                                                <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 24px; height: 24px; font-size: 10px;">
                                                                    <i class="fas fa-tag"></i>
                                                                </div>
                                                                <div>
                                                                    <small class="text-muted d-block" style="font-size: 0.7rem; line-height: 1;">Variant ID</small>
                                                                    <strong class="text-primary" style="font-size: 0.85rem;">#${variant.variant_id}</strong>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-5 col-sm-12 mb-1 mb-md-0">
                                                            <div class="d-flex align-items-center justify-content-center">
                                                                <div class="bg-secondary text-white rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 24px; height: 24px; font-size: 10px;">
                                                                    <i class="fas fa-barcode"></i>
                                                                </div>
                                                                <div>
                                                                    <small class="text-muted d-block" style="font-size: 0.7rem; line-height: 1;">SKU Code</small>
                                                                    <strong class="text-dark" style="font-size: 0.85rem;">${variant.sku || 'N/A'}</strong>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-3 col-sm-12">
                                                            <div class="d-flex align-items-center justify-content-center justify-content-md-end">
                                                                <div class="bg-success text-white rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 24px; height: 24px; font-size: 10px;">
                                                                    <i class="fas fa-calendar-alt"></i>
                                                                </div>
                                                                <div>
                                                                    <small class="text-muted d-block" style="font-size: 0.7rem; line-height: 1;">Ngày tạo</small>
                                                                    <strong class="text-success" style="font-size: 0.85rem;">${variant.created_at ? new Date(variant.created_at).toLocaleDateString('vi-VN') : 'N/A'}</strong>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                    $('#sizeContainer').append(sizeRow);
                });
                
                // Add separator and section for NEW variants
                if (variants.length > 0) {
                    $('#sizeContainer').append(`
                        <hr class="my-4">
                        <div class="alert alert-success mb-3">
                            <i class="fas fa-plus-circle"></i> 
                            <strong>THÊM SIZE MỚI:</strong> Thêm các size khác với size đã có bên trên (${window.existingVariantSizes.join(', ')})
                        </div>
                    `);
                }
                
                // Update size counter
                updateSizeCounter();
                
                console.log('✅ Đã hiển thị ' + variants.length + ' variants READONLY trong form');
                showToast(`Đã tải ${variants.length} variants từ database. Chỉ cho phép cập nhật giá và thêm size mới!`, 'info');
            }
            
            // Function to update size counter
            function updateSizeCounter() {
                const sizeCount = $('#sizeContainer .size-row').length;
                console.log('📏 Size counter updated:', sizeCount);
            }
            
            // Function to display comprehensive data load summary
            function displayDataLoadSummary(productData) {
                console.log('\n🎯 ===== TỔNG KẾT DỮ LIỆU ĐÃ TẢI =====');
                console.log('📦 Sản phẩm:', productData.name);
                console.log('🏷️ ID:', productData.product_id, '| Warehouse:', productData.warehouse_id);
                console.log('📊 Thương hiệu:', productData.brand || 'Không có');
                console.log('📂 Loại:', productData.type || 'Không có');
                console.log('🎨 Màu sắc:', productData.colors_string || 'Không có');
                console.log('🧵 Chất liệu:', productData.material || 'Không có');
                console.log('⚙️ Tính năng:', productData.features || 'Không có');
                console.log('🏷️ Tags:', productData.tags || 'Không có');
                console.log('📝 Mô tả (length):', (productData.description || '').length, 'ký tự');
                console.log('📍 Loại sản phẩm:', productData.type || 'Chưa xác định');
                
                console.log('\n👕 VARIANTS (' + (productData.total_variants || 0) + ' variants):');
                if (productData.variants && productData.variants.length > 0) {
                    productData.variants.forEach((variant, index) => {
                        console.log('  ' + (index + 1) + '. Size', variant.size, '- Giá:', 
                            parseFloat(variant.price || 0).toLocaleString('vi-VN') + '₫', 
                            '- SKU:', variant.sku, '- Màu:', variant.color || 'N/A');
                    });
                } else {
                    console.log('  Không có variants');
                }
                
                console.log('\n🖼️ IMAGES (' + (productData.total_images || 0) + ' ảnh):');
                if (productData.images && productData.images.length > 0) {
                    productData.images.forEach((img, index) => {
                        console.log('  ' + (index + 1) + '.', img.file_path, 
                            '(' + (img.is_primary ? 'Ảnh chính' : 'Ảnh phụ') + ')');
                    });
                } else {
                    console.log('  Không có ảnh');
                }
                
                console.log('\n🤖 AI ANALYSIS:');
                console.log('  Đã phân tích AI:', productData.ai_analyzed ? 'Có' : 'Không');
                
                console.log('\n📅 TIMESTAMPS:');
                console.log('  Tạo lúc:', productData.created_at || 'N/A');
                console.log('  Trạng thái:', productData.status || 'N/A');
                
                console.log('🎯 ==========================================\n');
                
                // Update update mode banner with clean professional design
                const updateBanner = `
                    <div class="alert" style="background: #fff; border-left: 4px solid #4e73df; box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075);">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <h6 class="mb-1" style="color: #4e73df; font-weight: 600;">
                                    <i class="fas fa-edit"></i> Chế độ cập nhật sản phẩm
                                </h6>
                                <p class="mb-1" style="color: #5a5c69; font-size: 14px;">
                                    <strong>${productData.name}</strong> <span class="text-muted">(ID: ${productData.product_id})</span>
                                </p>
                                <div style="font-size: 12px; color: #858796;">
                                    <span class="mr-3"><i class="fas fa-tag"></i> ${productData.brand || 'N/A'}</span>
                                    <span class="mr-3"><i class="fas fa-box"></i> ${productData.type || 'N/A'}</span>
                                    <span class="mr-3"><i class="fas fa-layer-group"></i> ${productData.total_variants || 0} variants</span>
                                    <span><i class="fas fa-images"></i> ${productData.total_images || 0} ảnh</span>
                                </div>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="cancelUpdate()" style="border-radius: 4px;">
                                <i class="fas fa-times mr-1"></i> Hủy
                            </button>
                        </div>
                    </div>
                `;
                
                // Insert banner inside step 3 content area, after the title
                if ($('#updateModeBanner').length === 0) {
                    $('<div id="updateModeBanner" class="mb-4">' + updateBanner + '</div>').insertAfter('#step3Content > h4:first');
                } else {
                    $('#updateModeBanner').html(updateBanner);
                }
            }
            
            // Function to update AI suggestions display for update mode
            function updateAISuggestionsForUpdate(productData) {
                // Get AI suggestions from global variables
                const aiSuggestions = window.suggestions || window.lastAiSuggestions;
                
                let suggestionsHtml = `
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <div class="card border-0 shadow-sm mb-3">
                                <div class="card-header" style="background: #f8f9fc; border-bottom: 2px solid #4e73df;">
                                    <h6 class="mb-0" style="color: #4e73df; font-weight: 600;">
                                        <i class="fas fa-database mr-2"></i>Thông tin từ Database
                                    </h6>
                                </div>
                                <div class="card-body" style="font-size: 13px;">
                                    <div class="mb-2"><span class="text-muted">Tên:</span> <strong>${productData.name || '-'}</strong></div>
                                    <div class="mb-2"><span class="text-muted">Thương hiệu:</span> <strong>${productData.brand || '-'}</strong></div>
                                    <div class="mb-2"><span class="text-muted">Loại:</span> <strong>${productData.type || '-'}</strong></div>
                                    <div class="mb-2"><span class="text-muted">Màu sắc:</span> ${productData.colors_string || '-'}</div>
                                    <div class="mb-2"><span class="text-muted">Chất liệu:</span> ${productData.material || '-'}</div>
                                    <div class="mb-2">
                                        <span class="badge badge-secondary">${productData.total_variants || 0} variants</span>
                                        <span class="badge badge-secondary ml-1">${productData.total_images || 0} ảnh</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card border-0 shadow-sm mb-3">
                                <div class="card-header" style="background: #f8f9fc; border-bottom: 2px solid #1cc88a;">
                                    <h6 class="mb-0" style="color: #1cc88a; font-weight: 600;">
                                        <i class="fas fa-robot mr-2"></i>Thông tin AI từ ảnh mới
                                    </h6>
                                </div>
                                <div class="card-body" style="font-size: 13px;">`;
                            
                if (aiSuggestions) {
                    suggestionsHtml += `
                                    <div class="mb-2"><span class="text-muted">Tên:</span> <strong>${aiSuggestions.name || '-'}</strong></div>
                                    <div class="mb-2"><span class="text-muted">Thương hiệu:</span> <strong>${aiSuggestions.brand || '-'}</strong></div>
                                    <div class="mb-2"><span class="text-muted">Loại:</span> <strong>${aiSuggestions.type || '-'}</strong></div>
                                    <div class="mb-2"><span class="text-muted">Màu sắc:</span> ${Array.isArray(aiSuggestions.colors) ? aiSuggestions.colors.join(', ') : (aiSuggestions.colors || '-')}</div>
                                    <div class="mb-2"><span class="text-muted">Chất liệu:</span> ${aiSuggestions.material || '-'}</div>`;
                } else {
                    suggestionsHtml += `
                                    <div class="text-center py-3">
                                        <i class="fas fa-robot fa-2x text-muted mb-2"></i>
                                        <p class="text-muted mb-0">Chưa có dữ liệu AI</p>
                                    </div>`;
                }
                
                suggestionsHtml += `
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="text-center mt-4 mb-3">
                        <button type="button" class="btn btn-success btn-lg" onclick="window.mergeAIDataToForm()" 
                                style="padding: 12px 35px; border-radius: 6px; font-weight: 600; box-shadow: 0 2px 8px rgba(28, 200, 138, 0.3);">
                            <i class="fas fa-robot mr-2"></i>Cập nhật thông tin AI
                        </button>
                        <p class="mt-2 mb-0 text-muted" style="font-size: 13px;">
                            <i class="fas fa-info-circle mr-1"></i>
                            Áp dụng dữ liệu AI phát hiện từ ảnh mới vào form
                        </p>
                    </div>
                `;
                $('#aiSuggestions').html(suggestionsHtml);
                $('#suggestionCard').show();
                console.log('📋 Updated AI suggestions display for update mode');
            }
            
            // Function to merge AI data into form when user clicks button (GLOBAL SCOPE)
            window.mergeAIDataToForm = function() {
                console.log('🔍 Checking AI data availability...');
                console.log('  window.suggestions exists:', !!window.suggestions);
                console.log('  window.lastAiSuggestions exists:', !!window.lastAiSuggestions);
                console.log('  window.aiColorsAvailable exists:', !!window.aiColorsAvailable);
                
                // Use either window.suggestions or window.lastAiSuggestions
                const aiSuggestions = window.suggestions || window.lastAiSuggestions;
                
                if (!aiSuggestions) {
                    showToast('Không có dữ liệu AI để merge. Hãy đảm bảo đã phân tích ảnh trước.', 'warning');
                    console.warn('⚠️ Không có AI suggestions để merge');
                    console.warn('  window.suggestions:', window.suggestions);
                    console.warn('  window.lastAiSuggestions:', window.lastAiSuggestions);
                    return;
                }
                
                console.log('🤖 Bắt đầu merge AI data vào form...', aiSuggestions);
                
                // Confirm with user
                const confirmMessage = `Bạn có chắc chắn muốn cập nhật thông tin sản phẩm với dữ liệu AI?
                
Dữ liệu AI sẽ được merge:
- Tên: ${aiSuggestions.name || 'Không có'}
- Chất liệu: ${aiSuggestions.material || 'Không có'}
- Tính năng: ${aiSuggestions.features || 'Không có'}
- Tags: ${Array.isArray(aiSuggestions.tags) ? aiSuggestions.tags.join(', ') : (aiSuggestions.tags || 'Không có')}

⚠️ LƯU Ý: Màu sắc, Thương hiệu, Loại sản phẩm sẽ KHÔNG được cập nhật và sẽ trở thành readonly (chỉ hiển thị).`;

                if (!confirm(confirmMessage)) {
                    return;
                }
                
                try {
                    // 1. Colors - KHÔNG CẬP NHẬT, CHỈ SET READONLY
                    console.log('🎨 Màu sắc - Không cập nhật, giữ nguyên giá trị từ database');
                    $('#color').prop('readonly', true).css({
                        'background-color': '#e9ecef',
                        'cursor': 'not-allowed'
                    });
                    console.log('🔒 Màu sắc đã được set readonly');
                    
                    // 2. Material - Update if empty or different
                    if (aiSuggestions.material) {
                        const currentMaterial = $('#material').val().trim();
                        if (!currentMaterial || confirm('Cập nhật chất liệu từ "' + currentMaterial + '" thành "' + aiSuggestions.material + '"?')) {
                            $('#material').val(aiSuggestions.material).trigger('change');
                            console.log('🧵 Updated material:', aiSuggestions.material);
                        }
                    }
                    
                    // 3. Features - Merge or update
                    if (aiSuggestions.features) {
                        const currentFeatures = $('#features').val().trim();
                        if (!currentFeatures) {
                            $('#features').val(aiSuggestions.features).trigger('change');
                        } else if (confirm('Thêm tính năng AI vào tính năng hiện có?')) {
                            const merged = currentFeatures + '. ' + aiSuggestions.features;
                            $('#features').val(merged).trigger('change');
                        }
                        console.log('⚙️ Updated features');
                    }
                    
                    // 4. Tags - Merge AI tags
                    if (aiSuggestions.tags) {
                        const currentTags = $('#tags').val().trim();
                        const aiTags = Array.isArray(aiSuggestions.tags) ? aiSuggestions.tags.join(', ') : aiSuggestions.tags;
                        
                        let mergedTags = currentTags;
                        if (currentTags) {
                            const existingTagsArray = currentTags.split(',').map(t => t.trim().toLowerCase());
                            const aiTagsArray = aiTags.split(',').map(t => t.trim());
                            const newTags = aiTagsArray.filter(tag => 
                                !existingTagsArray.includes(tag.toLowerCase())
                            );
                            if (newTags.length > 0) {
                                mergedTags = currentTags + ', ' + newTags.join(', ');
                            }
                        } else {
                            mergedTags = aiTags;
                        }
                        
                        $('#tags').val(mergedTags).trigger('change');
                        console.log('🏷️ Merged tags:', mergedTags);
                    }
                    
                    // 5. Name - Update if different (with confirmation)
                    if (aiSuggestions.name && aiSuggestions.name !== $('#productName').val().trim()) {
                        if (confirm('Cập nhật tên sản phẩm thành: "' + aiSuggestions.name + '"?')) {
                            $('#productName').val(aiSuggestions.name).trigger('change');
                            console.log('📝 Updated product name:', aiSuggestions.name);
                        }
                    }
                    
                    // 6. Brand - KHÔNG CẬP NHẬT, CHỈ SET READONLY
                    console.log('🏢 Thương hiệu - Không cập nhật, giữ nguyên giá trị từ database');
                    $('#brand').prop('readonly', true).css({
                        'background-color': '#e9ecef',
                        'cursor': 'not-allowed'
                    });
                    console.log('🔒 Thương hiệu đã được set readonly');
                    
                    // 7. Type - KHÔNG CẬP NHẬT, CHỈ SET READONLY
                    console.log('📦 Loại sản phẩm - Không cập nhật, giữ nguyên giá trị từ database');
                    $('#type').prop('readonly', true).css({
                        'background-color': '#e9ecef',
                        'cursor': 'not-allowed'
                    });
                    console.log('� Loại sản phẩm đã được set readonly');
                    
                    // 8. Update Product Images - Refresh images from step 1 upload
                    console.log('🔍 Checking for uploaded images...');
                    console.log('  window.uploadedImages exists:', !!window.uploadedImages);
                    console.log('  uploadedImages exists:', typeof uploadedImages !== 'undefined' ? uploadedImages : 'undefined');
                    
                    // Try multiple sources for uploaded images
                    let availableImages = window.uploadedImages || (typeof uploadedImages !== 'undefined' ? uploadedImages : null);
                    
                    // **NEW FIX:** If no uploaded images found, try to restore from localStorage or existing product data
                    if (!availableImages || availableImages.length === 0) {
                        console.log('🔄 No uploadedImages found, trying to restore from alternative sources...');
                        
                        // Try localStorage backup
                        try {
                            const backupImages = localStorage.getItem('uploadedImages');
                            if (backupImages) {
                                availableImages = JSON.parse(backupImages);
                                console.log('📦 Restored images from localStorage:', availableImages);
                            }
                        } catch (e) {
                            console.warn('Failed to restore from localStorage:', e);
                        }
                        
                        // If still no images and we're in update mode, try to use existing product images
                        if ((!availableImages || availableImages.length === 0) && window.currentProductData && window.currentProductData.images) {
                            console.log('🔄 Trying to use existing product images...');
                            availableImages = window.currentProductData.images.map(img => ({
                                name: img.file_path.split('/').pop() || 'existing_image.jpg',
                                url: img.file_path,
                                size: 0,
                                isExisting: true
                            }));
                            console.log('📸 Using existing product images:', availableImages);
                        }
                    }
                    
                    if (availableImages && availableImages.length > 0) {
                        console.log('🖼️ Found uploaded images, updating...', availableImages);
                        
                        // Update final image URL with all images (JSON format like the original system)
                        const imagePaths = [];
                        availableImages.forEach((img, index) => {
                            // **FIXED: Use consistent logic - prefer url over path for web display**
                            const imageUrl = img.url || img.path;
                            if (imageUrl) {
                                imagePaths.push(imageUrl);
                            }
                        });
                        
                        if (imagePaths.length > 0) {
                            $('#finalImageUrl').val(JSON.stringify(imagePaths));
                            console.log('📷 mergeAIDataToForm: Updated finalImageUrl with ' + imagePaths.length + ' images:', imagePaths);
                            console.log('📷 mergeAIDataToForm: Field value after update:', $('#finalImageUrl').val());
                        }
                        
                        // Store images globally for the display function
                        window.uploadedImages = availableImages;
                        
                        // Re-display uploaded images in step 3 (skip field update since we just did it)
                        console.log('🔄 mergeAIDataToForm: Calling updateStep3ImageDisplay...');
                        updateStep3ImageDisplay(true); // Pass flag to skip field update
                        
                        // Show confirmation dialog
                        if (confirm('✅ Đã tìm thấy ' + availableImages.length + ' ảnh từ bước upload!\n\nBạn có muốn cập nhật ảnh sản phẩm không?')) {
                            showToast('✅ Đã cập nhật ảnh sản phẩm từ bước upload!', 'success');
                        }
                    } else {
                        console.warn('⚠️ No uploaded images found from any source');
                        console.warn('  Checked window.uploadedImages:', window.uploadedImages);
                        console.warn('  Checked uploadedImages:', typeof uploadedImages !== 'undefined' ? uploadedImages : 'undefined');
                        console.warn('  Checked localStorage:', localStorage.getItem('uploadedImages'));
                        console.warn('  Checked currentProductData:', window.currentProductData);
                        
                        if (confirm('⚠️ Không tìm thấy ảnh từ bước upload.\n\nCó thể ảnh chưa được tải lên hoặc đã bị xóa.\nBạn có muốn quay lại bước 1 để tải ảnh mới không?')) {
                            moveToStep(1);
                        }
                    }
                    
                    // 9. Update suggestions display to show merge completed
                    $('#aiSuggestions').prepend(`
                        <div class="alert alert-success alert-dismissible fade show">
                            <i class="fas fa-check-circle"></i>
                            <strong>✅ ĐÃ CẬP NHẬT THÔNG TIN AI!</strong><br>
                            Đã merge thông tin AI vào form. Bạn có thể chỉnh sửa thêm trước khi lưu.<br>
                            <small class="text-muted">
                                <i class="fas fa-lock"></i> <strong>Màu sắc, Thương hiệu, Loại sản phẩm</strong> đã được khóa (readonly) - không thể chỉnh sửa.
                            </small>
                            <button type="button" class="close" data-dismiss="alert">
                                <span>&times;</span>
                            </button>
                        </div>
                    `);
                    
                    showToast('✅ Đã cập nhật thông tin AI! Màu sắc, Thương hiệu, Loại sản phẩm đã được khóa.', 'success');
                    console.log('✅ AI data merge completed');
                    
                } catch (error) {
                    console.error('❌ Error merging AI data:', error);
                    showToast('Lỗi khi merge dữ liệu AI: ' + error.message, 'error');
                }
            };
            
            // Function to update image display in step 3
            function updateStep3ImageDisplay(skipFieldUpdate = false) {
                console.log('🎯 updateStep3ImageDisplay called, skipFieldUpdate:', skipFieldUpdate);
                console.log('  window.uploadedImages:', window.uploadedImages);
                
                if (!window.uploadedImages || window.uploadedImages.length === 0) {
                    console.warn('No uploaded images to display in step 3');
                    alert('Không tìm thấy ảnh để hiển thị. Hãy kiểm tra lại bước upload.');
                    return;
                }
                
                console.log('🖼️ Updating step 3 image display with uploaded images:', window.uploadedImages);
                
                // Create or update image preview section in step 3
                let imageSection = $('#step3ImagePreview');
                if (imageSection.length === 0) {
                    // Create image preview section if it doesn't exist
                    $('#productForm').prepend(`
                        <div id="step3ImagePreview" class="mb-4">
                            <h6 class="mb-3">
                                <i class="fas fa-images text-primary"></i> Ảnh sản phẩm đã tải lên
                            </h6>
                            <div id="step3ImageContainer" class="row"></div>
                        </div>
                    `);
                }
                
                // Generate HTML for uploaded images
                let imagesHtml = '';
                window.uploadedImages.forEach((file, index) => {
                    const isPrimary = index === 0;
                    imagesHtml += `
                        <div class="col-md-3 col-sm-6 mb-3">
                            <div class="card image-preview-card ${isPrimary ? 'border-primary' : ''}">
                                <div class="card-body p-2">
                                    <div class="position-relative">
                                        <img src="${file.url}" alt="Product Image ${index + 1}" 
                                             class="img-fluid rounded" style="width: 100%; height: 150px; object-fit: cover;">
                                        ${isPrimary ? `
                                            <span class="badge badge-primary position-absolute" style="top: 5px; right: 5px;">
                                                <i class="fas fa-star"></i> Chính
                                            </span>
                                        ` : ''}
                                    </div>
                                    <small class="text-muted d-block mt-2 text-center">
                                        <i class="fas fa-file"></i> ${file.name}
                                        <br>
                                        <i class="fas fa-weight"></i> ${(file.size / 1024).toFixed(1)} KB
                                    </small>
                                </div>
                            </div>
                        </div>
                    `;
                });
                
                $('#step3ImageContainer').html(imagesHtml);
                
                // **CRITICAL FIX: Update hidden form field with image URLs for saving to database**
                if (!skipFieldUpdate) {
                    const imageUrls = window.uploadedImages.map(file => file.url || file.path).filter(Boolean);
                    const imageUrlsJson = JSON.stringify(imageUrls);
                    $('#finalImageUrl').val(imageUrlsJson);
                    console.log('🔧 updateStep3ImageDisplay: Updated finalImageUrl field with:', imageUrlsJson);
                    console.log('🔧 updateStep3ImageDisplay: Field value check:', $('#finalImageUrl').val());
                    console.log('🔧 updateStep3ImageDisplay: Raw window.uploadedImages:', window.uploadedImages);
                } else {
                    console.log('🔧 updateStep3ImageDisplay: Skipped field update as requested');
                }
                
                // Remove any existing notice first
                $('#imageUpdateNotice').remove();
                
                // Add refresh notice with action buttons
                $('#step3ImagePreview').append(`
                    <div class="alert alert-success alert-dismissible mt-3" id="imageUpdateNotice">
                        <div class="row align-items-center">
                            <div class="col-md-8">
                                <h6 class="alert-heading mb-1">
                                    <i class="fas fa-check-circle"></i> 
                                    <strong>✅ ĐÃ CẬP NHẬT ẢNH THÀNH CÔNG!</strong>
                                </h6>
                                <p class="mb-1">
                                    Đã lấy ${window.uploadedImages.length} ảnh từ bước upload ban đầu.
                                    ${window.uploadedImages.length > 1 ? 
                                        `<br><small><i class="fas fa-star text-warning"></i> Ảnh đầu tiên sẽ là ảnh chính của sản phẩm.</small>` : 
                                        ''
                                    }
                                </p>
                            </div>
                            <div class="col-md-4 text-right">
                                <button type="button" class="btn btn-sm btn-primary mr-2" onclick="alert('Ảnh sẽ được lưu khi bạn nhấn \\'Lưu sản phẩm\\' bên dưới!')">
                                    <i class="fas fa-info-circle"></i> Thông tin
                                </button>
                                <button type="button" class="close" onclick="$('#imageUpdateNotice').fadeOut()">
                                    <span aria-hidden="true">&times;</span>
                                </button>
                            </div>
                        </div>
                    </div>
                `);
                
                // Show a more prominent alert
                alert('🎉 ĐÃ CẬP NHẬT ' + window.uploadedImages.length + ' ẢNH THÀNH CÔNG!\n\n' +
                      '✅ Ảnh đã được hiển thị trong form bên dưới\n' +
                      '✅ Ảnh sẽ được lưu khi bạn nhấn "Lưu sản phẩm"\n' +
                      '✅ Field finalImageUrl đã được cập nhật: ' + $('#finalImageUrl').val().substring(0,50) + '...\n\n' + 
                      'Hãy kiểm tra phần "Ảnh sản phẩm đã tải lên" bên dưới.');
                
                // Auto-remove notice after 10 seconds
                setTimeout(() => {
                    $('#imageUpdateNotice').fadeOut();
                }, 10000);
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
                    
                    // Xử lý theo role
                    let cardStyle;
                    if (isInactive) {
                        cardStyle = canReactivateProducts ? 
                            'cursor: pointer; opacity: 0.7; background-color: #fff3cd;' : 
                            'cursor: not-allowed; opacity: 0.7; background-color: #fff3cd;';
                        console.log('⚠️ Product', product.product_id, 'is INACTIVE - user can reactivate:', canReactivateProducts);
                    } else {
                        cardStyle = 'cursor: pointer;';
                    }
                    
                    duplicatesHtml += `
                        <div class="card mb-3 duplicate-product-card ${cardClass}" 
                             data-product-id="${product.product_id}"
                             data-product-name="${product.name.replace(/"/g, '&quot;')}"
                             data-product-status="${product.status || 'active'}"
                             style="${cardStyle} transition: all 0.3s;">
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-4">
                                        ${product.image_path ? 
                                            `<img src="${product.image_path}" class="img-fluid" style="max-height: 100px; border-radius: 4px; pointer-events: none; ${isInactive ? 'opacity: 0.6;' : ''}">` :
                                            '<div class="bg-light d-flex align-items-center justify-content-center" style="height: 100px; border-radius: 4px; pointer-events: none;"><i class="fas fa-image text-muted"></i></div>'
                                        }
                                    </div>
                                    <div class="col-md-8">
                                        <h6 class="card-title mb-1">
                                            ${product.name}
                                            <span class="badge badge-info ml-2" style="font-size: 0.75rem;">ID: ${product.product_id}</span>
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
                                                ${isInactive && canReactivateProducts ? `
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
                                            <small><i class="fas fa-lock"></i> ${canReactivateProducts ? 
                                                'Sản phẩm đã tạm ngừng - Bạn có thể kích hoạt lại để sử dụng.' :
                                                'Sản phẩm đã tạm ngừng - Không thể chọn. Vui lòng liên hệ Admin/Quản lý.'
                                            }</small>
                                        </div>
                                        ` : ''}
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                });
                $('#duplicateProductsList').html(duplicatesHtml);
                
                // Add click handler for duplicate cards
                $('.duplicate-product-card').off('click').on('click', function(e) {
                    // Don't handle if clicking on a button
                    if ($(e.target).is('button') || $(e.target).closest('button').length) {
                        return;
                    }
                    
                    const status = $(this).data('product-status');
                    if (status === 'inactive') {
                        if (canReactivateProducts) {
                            window.showInactiveWarningForAdmin();
                        } else {
                            window.showInactiveWarningForEmployee();
                        }
                    } else {
                        window.selectAIDuplicateForUpdate(this);
                    }
                });
                
                // Tự động chọn sản phẩm đầu tiên để người dùng dễ thấy
                setTimeout(function() {
                    const firstCard = $('.duplicate-product-card').first();
                    if (firstCard.length) {
                        firstCard.addClass('border-primary');
                        console.log('✅ Đã tự động chọn sản phẩm đầu tiên:', firstCard.data('product-id'));
                        console.log('📊 Total cards:', $('.duplicate-product-card').length);
                    } else {
                        console.error('❌ Không tìm thấy card nào!');
                    }
                }, 300);
                
                // Hiển thị modal
                $('#duplicateCheckModal').modal({
                    backdrop: 'static',
                    keyboard: false
                });
                
                // Debug: Log sau khi modal được show
                $('#duplicateCheckModal').on('shown.bs.modal', function() {
                    console.log('🔍 Modal shown. Testing click events...');
                    console.log('🔍 Number of duplicate cards:', $('.duplicate-product-card').length);
                    $('.duplicate-product-card').each(function(i) {
                        console.log(`Card ${i}:`, {
                            id: $(this).data('product-id'),
                            name: $(this).data('product-name'),
                            hasClass: $(this).hasClass('duplicate-product-card')
                        });
                    });
                });
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
                moveToStep(3);
                displaySuggestions();
            });
            
            // Function to view product details (for duplicate check results)
            window.viewProductDetails = function(productId) {
                // Open product detail page in new tab
                window.open(`xem_san_pham.php?id=${productId}`, '_blank');
            };

            // Function to show warning when employee tries to select inactive product
            window.showInactiveWarningForEmployee = function() {
                Swal.fire({
                    icon: 'warning',
                    title: 'Sản phẩm đã tạm ngừng',
                    html: `
                        <p>Sản phẩm này đang ở trạng thái <strong>Tạm ngừng</strong>.</p>
                        <p>Bạn không có quyền kích hoạt lại sản phẩm này.</p>
                        <p>Vui lòng liên hệ <strong>Quản lý</strong> hoặc <strong>Admin</strong> để được hỗ trợ.</p>
                    `,
                    confirmButtonText: 'Đã hiểu',
                    confirmButtonColor: '#dc3545'
                });
            };
            
            // Function to show warning when admin/manager tries to select inactive product
            window.showInactiveWarningForAdmin = function() {
                Swal.fire({
                    icon: 'info',
                    title: 'Sản phẩm đã tạm ngừng',
                    html: `
                        <p>Sản phẩm này đang ở trạng thái <strong>Tạm ngừng</strong>.</p>
                        <p>Bạn có thể:</p>
                        <ul class="text-left">
                            <li>Nhấn nút <strong>"Kích hoạt lại"</strong> để sử dụng sản phẩm này</li>
                            <li>Hoặc chọn sản phẩm khác đang hoạt động</li>
                        </ul>
                    `,
                    confirmButtonText: 'Đã hiểu',
                    confirmButtonColor: '#ffc107'
                });
            };
            
            // Legacy function for compatibility
            window.showInactiveWarning = function() {
                if (canReactivateProducts) {
                    showInactiveWarningForAdmin();
                } else {
                    showInactiveWarningForEmployee();
                }
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
                            url: 'kich_hoat_lai_san_pham.php',
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
                                        `.ai-duplicate-card[data-product-id="${productId}"]`,
                                        `.manual-duplicate-card[data-product-id="${productId}"]`,
                                        `.similar-product-item[data-product-id="${productId}"]`
                                    ];
                                    
                                    console.log('🔍 Looking for cards with product ID:', productId);
                                    let cardsFound = 0;
                                    
                                    cardSelectors.forEach(selector => {
                                        const cards = $(selector);
                                        console.log(`  - Selector "${selector}": found ${cards.length} card(s)`);
                                        
                                        cards.each(function() {
                                            cardsFound++;
                                            const $card = $(this);
                                            
                                            console.log('  ✅ Updating card:', $card.attr('class'));
                                            
                                            // Remove inactive styling
                                            $card.removeClass('border-warning')
                                                 .css({
                                                     'opacity': '1',
                                                     'background': '#fff',
                                                     'cursor': 'pointer'
                                                 })
                                                 .attr('data-product-status', 'active');
                                            
                                            console.log('  ✅ Set data-product-status to active');
                                            
                                            // Remove inactive badge (⏸ Tạm ngừng)
                                            $card.find('.badge-warning:contains("Tạm ngừng")').remove();
                                            
                                            // Find and replace the reactivate button
                                            const $btnReactivate = $card.find('.btn-reactivate-product');
                                            if ($btnReactivate.length > 0) {
                                                console.log('  🗑️ Removing reactivate button');
                                                $btnReactivate.remove();
                                            }
                                            
                                            // Remove warning alert
                                            $card.find('.alert-warning').remove();
                                            
                                            // Update card images opacity
                                            $card.find('img').css('opacity', '1');
                                            
                                            // Remove onclick warning and restore normal click behavior
                                            const onclickAttr = $card.attr('onclick');
                                            console.log('  📌 Current onclick:', onclickAttr);
                                            
                                            if (onclickAttr && onclickAttr.includes('showInactiveWarning')) {
                                                console.log('  🔄 Restoring normal click behavior');
                                                // Remove the warning onclick
                                                $card.removeAttr('onclick');
                                                
                                                // Restore normal card click behavior based on card type
                                                if ($card.hasClass('manual-duplicate-card')) {
                                                    $card.attr('onclick', 'selectManualDuplicate(this)');
                                                    console.log('  ✅ Set onclick="selectManualDuplicate(this)"');
                                                } else if ($card.hasClass('ai-duplicate-card')) {
                                                    // For AI cards, use onclick attribute instead of jQuery event
                                                    $card.attr('onclick', 'selectAIDuplicateForUpdate(this)');
                                                    console.log('  ✅ Set onclick="selectAIDuplicateForUpdate(this)"');
                                                }
                                            } else if ($card.hasClass('ai-duplicate-card') && !onclickAttr) {
                                                // If AI card has no onclick (was updated already), restore it
                                                $card.attr('onclick', 'selectAIDuplicateForUpdate(this)');
                                                console.log('  ✅ Restored onclick="selectAIDuplicateForUpdate(this)" for AI card');
                                            }
                                        });
                                    });
                                    
                                    if (cardsFound === 0) {
                                        console.error('❌ No cards found for product ID:', productId);
                                        console.log('💡 Available cards in DOM:');
                                        $('.manual-duplicate-card, .ai-duplicate-card').each(function() {
                                            console.log('  - Card:', $(this).attr('class'), 'Product ID:', $(this).data('product-id'));
                                        });
                                    } else {
                                        console.log(`✅ Successfully updated ${cardsFound} card(s)`);
                                    }
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
                console.log('🔘 Reactivate button click event triggered');
                e.preventDefault();
                e.stopPropagation();
                
                // Check permission
                if (!window.canReactivateProducts) {
                    console.log('❌ Permission denied - canReactivateProducts:', window.canReactivateProducts);
                    Swal.fire({
                        icon: 'error',
                        title: 'Không có quyền',
                        text: 'Bạn không có quyền kích hoạt lại sản phẩm. Chỉ Admin và Quản lý mới có quyền này.',
                        confirmButtonColor: '#dc3545'
                    });
                    return false;
                }
                
                const productId = $(this).data('product-id');
                const productName = $(this).data('product-name');
                console.log('✅ Reactivate button clicked - ID:', productId, 'Name:', productName);
                window.reactivateProduct(productId, productName);
                return false;
            });

            // Event delegation for view product details buttons
            $(document).on('click', '.btn-view-product-details', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const productId = $(this).data('product-id');
                viewProductDetails(productId);
                return false;
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
                    
                    // Go back to step 1
                    moveToStep(1);
                }
            });

            // Back to upload step
            $('#backToUpload').click(function() {
                moveToStep(1);
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
                
                // Clear all DOM elements
                $('#imagePreview').empty();
                $('#analyzedImages').empty();
                $('#aiResults .ai-analysis-alert').remove();
                $('#aiResults').hide();
                $('#aiSuggestions').empty();
                $('#suggestionCard').hide();
                $('#analyzingImages').hide();
                $('#loadingSpinner').hide();
                $('#productTags').empty();
                $('#dominantColors').empty();
                $('#aiDuplicateCheckResult').hide().empty();
                
                // Reset upload area to initial state
                resetUploadArea();
                
                // Hide analyze button section
                $('#analyzeSection').hide();
                
                console.log('✅ Reset complete - Ready for new image upload');
                
                // Move back to step 1 (upload step)
                moveToStep(1);
            });

            function displaySuggestions() {
                // Update UI for update mode
                updateUIForMode();
                
                // Chặn display suggestions nếu đang dùng existing data only
                if (useExistingDataOnly) {
                    console.log('🚫 Blocking display suggestions - using existing data only');
                    return;
                }
                
                if (suggestions) {
                    // Check if we're in AI update mode
                    const isAIUpdateMode = window.aiUpdateMode && window.aiUpdateMode.productId;
                    
                    if (isAIUpdateMode && window.existingProductData) {
                        console.log('🔄 AI Update Mode: Merging AI suggestions with existing product data');
                        
                        // Merge AI suggestions with existing product data
                        // Priority: Keep existing data, only add new info from AI if field is empty
                        const mergedData = {
                            name: window.existingProductData.name || suggestions.name,
                            brand: window.existingProductData.brand || suggestions.brand,
                            type: window.existingProductData.type || suggestions.type,
                            colors: suggestions.colors || window.existingProductData.colors, // Use new colors from AI
                            description: window.existingProductData.description || suggestions.description,
                            category: window.existingProductData.category || suggestions.category,
                            material: suggestions.material || window.existingProductData.material, // Prefer AI material
                            features: suggestions.features || window.existingProductData.features,
                            tags: suggestions.tags || window.existingProductData.tags
                        };
                        
                        // Apply merged data
                        applyMergedSuggestions(mergedData);
                        
                        // Show update mode suggestions
                        // Chuẩn hóa brand thành "Unknown" nếu trống hoặc không xác định
                        const displayBrand = window.standardizeBrand(mergedData.brand);
                        
                        let suggestionsHtml = `
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i>
                                <strong>Chế độ cập nhật:</strong> Đang cập nhật sản phẩm <strong>${window.aiUpdateMode.productName}</strong>
                            </div>
                            <p><strong>Tên sản phẩm:</strong> ${mergedData.name || 'Chưa xác định'}</p>
                            <p><strong>Thương hiệu:</strong> ${displayBrand}</p>
                            <p><strong>Loại sản phẩm:</strong> ${mergedData.type || 'Chưa xác định'}</p>
                            <p><strong>Màu sắc:</strong> <span class="text-primary">${Array.isArray(mergedData.colors) ? mergedData.colors.join(', ') : mergedData.colors || 'Chưa xác định'}</span> <small>(từ AI mới)</small></p>
                            <p><strong>Chất liệu:</strong> <span class="text-primary">${mergedData.material || 'Chưa xác định'}</span> <small>(từ AI mới)</small></p>
                            <p><strong>Mô tả:</strong> ${mergedData.description || 'Chưa có mô tả'}</p>
                        `;
                        $('#aiSuggestions').html(suggestionsHtml);
                        $('#suggestionCard').show();
                        
                    } else {
                        // Normal mode: Tự động áp dụng gợi ý AI ngay khi hiển thị (KHÔNG gọi khi update)
                        if (!isAIUpdateMode) {
                            autoApplySuggestions();
                        }
                        
                        // Chuẩn hóa brand thành "Unknown" nếu trống hoặc không xác định
                        const displayBrand = window.standardizeBrand(suggestions.brand);
                        
                        // Hiển thị thông tin để người dùng xem
                        let suggestionsHtml = `
                            <p><strong>Tên sản phẩm:</strong> ${suggestions.name || 'Chưa xác định'}</p>
                            <p><strong>Thương hiệu:</strong> ${displayBrand}</p>
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
                    }
                    
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
                        
                        let message = isAIUpdateMode ? 
                            '🔄 <strong>Đang cập nhật sản phẩm với thông tin AI mới:</strong><br>' :
                            '🤖 <strong>AI hoàn tất tự động điền:</strong><br>';
                        message += `📝 Tên sản phẩm: <code>${$('#productName').val()}</code><br>`;
                        message += `🏢 Thương hiệu: <code>${$('#brand').val()}</code><br>`;
                        message += `📂 Danh mục: <strong class="text-success">${categorySelected || 'Chưa chọn'}</strong> (ID: ${categoryId})<br>`;
                        message += `🏷️ Mã SKU: <code>${skuGenerated}</code><br>`;
                        message += `<small class="text-muted">✅ Vui lòng kiểm tra và điều chỉnh nếu cần</small>`;
                        
                        showToast(message, 'success');
                    }, 200);
                }
            }
            
            // Function to apply merged suggestions (for update mode)
            function applyMergedSuggestions(mergedData) {
                // Chuyển "Fashion" thành "Unknown" nếu cần
                if (mergedData.brand && mergedData.brand.trim().toLowerCase() === 'fashion') {
                    console.log('⚠️ Converting brand "Fashion" to "Unknown"');
                    mergedData.brand = 'Unknown';
                }
                
                // Apply to form fields
                if (mergedData.name) $('#productName').val(mergedData.name);
                if (mergedData.brand) $('#brand').val(mergedData.brand);
                if (mergedData.type) $('#type').val(mergedData.type);
                if (mergedData.description) $('#description').val(mergedData.description);
                if (mergedData.material) $('#material').val(mergedData.material);
                
                // Handle colors
                if (mergedData.colors) {
                    const colorsArray = Array.isArray(mergedData.colors) ? mergedData.colors : [mergedData.colors];
                    $('#color').val(colorsArray.join(', '));
                }
                
                // Handle features
                if (mergedData.features) $('#features').val(mergedData.features);
                
                // Handle tags
                if (mergedData.tags) {
                    const tagsArray = Array.isArray(mergedData.tags) ? mergedData.tags : [mergedData.tags];
                    $('#tags').val(tagsArray.join(', '));
                }
            }

            // Tự động áp dụng gợi ý AI
            function autoApplySuggestions() {
                console.log('🤖 AutoApplySuggestions called with suggestions:', suggestions);
                
                // Chặn auto-apply nếu đang trong update mode với existing data only
                if (useExistingDataOnly) {
                    console.log('🚫 Blocking AI suggestions - using existing data only');
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
                        console.log('🔍 Suggestions object:', suggestions);
                        
                        // Enhanced mapping với nhiều từ khóa theo quy tắc chuẩn hóa mới
                        const categoryMapping = {
                            // === SNEAKER (Tất cả giày thể thao/casual) ===
                            'sneaker': 'Sneaker',
                            'sneakers': 'Sneaker',
                            'running shoe': 'Sneaker',
                            'running shoes': 'Sneaker',
                            'training shoe': 'Sneaker',
                            'training shoes': 'Sneaker',
                            'gym shoe': 'Sneaker',
                            'gym shoes': 'Sneaker',
                            'sport shoes': 'Sneaker',
                            'sports shoes': 'Giày thể thao chuyên dụng',
                            'athletic shoes': 'Sneaker',
                            'basketball shoes': 'Sneaker',
                            'tennis shoes': 'Sneaker',
                            'casual shoes': 'Sneaker',
                            'lifestyle shoes': 'Sneaker',
                            'walking shoes': 'Sneaker',
                            'giày thể thao': 'Sneaker',
                            'giày chạy bộ': 'Sneaker',
                            'giày casual': 'Sneaker',
                            'giày lifestyle': 'Sneaker',
                            'giày tập gym': 'Sneaker',
                            'giày tennis': 'Sneaker',
                            'giày bóng rổ': 'Sneaker',
                            'giày training': 'Sneaker',
                            
                            // === GIÀY CAO GÓT ===
                            'high heel': 'Giày cao gót',
                            'high heels': 'Giày cao gót',
                            'pump': 'Giày cao gót',
                            'pumps': 'Giày cao gót',
                            'stiletto': 'Giày cao gót',
                            'stilettos': 'Giày cao gót',
                            'block heel': 'Giày cao gót',
                            'block heels': 'Giày cao gót',
                            'giày cao gót': 'Giày cao gót',
                            'cao gót': 'Giày cao gót',
                            'heel': 'Giày cao gót',
                            'heels': 'Giày cao gót',
                            
                            // === GIÀY ĐẾ XUỒNG ===
                            'wedge': 'Giày đế xuồng',
                            'wedges': 'Giày đế xuồng',
                            'wedge heel': 'Giày đế xuồng',
                            'wedge heels': 'Giày đế xuồng',
                            'giày đế xuồng': 'Giày đế xuồng',
                            
                            // === GIÀY BOOT ===
                            'boot': 'Giày boot',
                            'boots': 'Giày boot',
                            'ankle boot': 'Giày boot',
                            'ankle boots': 'Giày boot',
                            'knee boot': 'Giày boot',
                            'knee boots': 'Giày boot',
                            'combat boot': 'Giày boot',
                            'combat boots': 'Giày boot',
                            'chelsea boot': 'Giày boot',
                            'chelsea boots': 'Giày boot',
                            'giày boot': 'Giày boot',
                            'bốt': 'Giày boot',
                            'giày cổ cao': 'Giày boot',
                            
                            // === GIÀY TÂY ===
                            'oxford': 'Giày tây',
                            'oxfords': 'Giày tây',
                            'dress shoe': 'Giày tây',
                            'dress shoes': 'Giày tây',
                            'formal shoe': 'Giày tây',
                            'formal shoes': 'Giày tây',
                            'business shoe': 'Giày tây',
                            'business shoes': 'Giày tây',
                            'giày tây': 'Giày tây',
                            'giày công sở': 'Giày tây',
                            'derby': 'Giày tây',
                            'brogue': 'Giày tây',
                            
                            // === GIÀY LƯỜI ===
                            'loafer': 'Giày lười',
                            'loafers': 'Giày lười',
                            'slip-on': 'Giày lười',
                            'slip on': 'Giày lười',
                            'slip-ons': 'Giày lười',
                            'moccasin': 'Giày lười',
                            'moccasins': 'Giày lười',
                            'boat shoe': 'Giày lười',
                            'boat shoes': 'Giày lười',
                            'giày lười': 'Giày lười',
                            'lười': 'Giày lười',
                            
                            // === GIÀY BỆT ===
                            'flat': 'Giày bệt',
                            'flats': 'Giày bệt',
                            'ballet flat': 'Giày bệt',
                            'ballet flats': 'Giày bệt',
                            'giày bệt': 'Giày bệt',
                            'bệt': 'Giày bệt',
                            'giày búp bê': 'Giày bệt',
                            'mary jane': 'Giày bệt',
                            'mary janes': 'Giày bệt',
                            
                            // === SANDAL ===
                            'sandal': 'Sandal',
                            'sandals': 'Sandal',
                            'slide': 'Sandal',
                            'slides': 'Sandal',
                            'flip flop': 'Sandal',
                            'flip-flop': 'Sandal',
                            'flip flops': 'Sandal',
                            'xăng đan': 'Sandal',
                            'dép xỏ ngón': 'Sandal',
                            
                            // === DÉP ===
                            'slipper': 'Dép',
                            'slippers': 'Dép',
                            'house shoe': 'Dép',
                            'house shoes': 'Dép',
                            'bedroom slipper': 'Dép',
                            'dép': 'Dép',
                            
                            // === GIÀY MULES ===
                            'mule': 'Giày mules',
                            'mules': 'Giày mules',
                            'giày mule': 'Giày mules',
                            'backless shoe': 'Giày mules',
                            
                            // === GIÀY QUAI HẬU ===
                            'slingback': 'Giày quai hậu',
                            'slingbacks': 'Giày quai hậu',
                            'giày quai hậu': 'Giày quai hậu',
                            
                            // === GIÀY THỂ THAO CHUYÊN DỤNG ===
                            'football boot': 'Giày thể thao chuyên dụng',
                            'football boots': 'Giày thể thao chuyên dụng',
                            'soccer cleat': 'Giày thể thao chuyên dụng',
                            'soccer cleats': 'Giày thể thao chuyên dụng',
                            'golf shoe': 'Giày thể thao chuyên dụng',
                            'golf shoes': 'Giày thể thao chuyên dụng',
                            'giày đá bóng': 'Giày thể thao chuyên dụng',
                            'giày golf': 'Giày thể thao chuyên dụng',
                            
                            // === GIÀY CÓI & GỖ ===
                            'espadrille': 'Giày cói',
                            'espadrilles': 'Giày cói',
                            'giày cói': 'Giày cói',
                            'clog': 'Giày gỗ',
                            'clogs': 'Giày gỗ',
                            'giày gỗ': 'Giày gỗ'
                        };
                        
                        // Log available categories in dropdown
                        console.log('📝 Available categories in dropdown:');
                        $('#categoryId option').each(function() {
                            if ($(this).val() !== '') {
                                console.log(`   - ID: ${$(this).val()}, Text: "${$(this).text()}"`);
                            }
                        });
                        
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
                            console.log(`📋 No mapping found, using direct: "${targetCategory}"`);
                        }
                        
                        // Tìm trong dropdown options với logic mở rộng
                        $('#categoryId option').each(function() {
                            const optionText = $(this).text().toLowerCase().trim();
                            const optionValue = $(this).val();
                            
                            if (optionValue === '') return true; // Skip empty option
                            
                            console.log(`🔎 Checking: "${optionText}" vs "${targetCategory}"`);
                            
                            // Logic matching linh hoạt và mở rộng
                            const isMatch = 
                                optionText === targetCategory ||                           // Exact match
                                optionText.includes(targetCategory) ||                    // Contains
                                targetCategory.includes(optionText) ||                   // Reverse contains
                                (optionText.replace(/\s+/g, '') === targetCategory.replace(/\s+/g, '')) || // No spaces
                                // Additional fuzzy matching
                                (optionText.indexOf(targetCategory.split(' ')[0]) >= 0) || // First word match
                                (targetCategory.indexOf(optionText.split(' ')[0]) >= 0);   // Reverse first word match
                            
                            if (isMatch) {
                                $(this).prop('selected', true);
                                categoryFound = true;
                                $('#categoryId').addClass('border-success').removeClass('border-warning');
                                console.log(`✅ Category matched: "${suggestionCategory}" → "${optionText}" (ID: ${optionValue})`);
                                
                                // Clear AI category info since we found a match
                                $('#categoryAutoCreateInfo').hide();
                                $('#aiCategoryName').val('');
                                $('#aiCategoryDescription').val('');
                                
                                // Trigger change event to update UI
                                $('#categoryId').trigger('change');
                                
                                return false; // Break loop
                            }
                        });
                        
                        if (!categoryFound) {
                            $('#categoryId').addClass('border-warning').removeClass('border-success');
                            console.log(`⚠️ No match found for: "${suggestionCategory}"`);
                            
                            // Lưu thông tin để tự động tạo danh mục mới
                            $('#aiCategoryName').val(suggestions.category);
                            if (suggestions.type) {
                                $('#aiCategoryDescription').val('Danh mục ' + suggestions.category + ' cho ' + suggestions.type);
                            } else {
                                $('#aiCategoryDescription').val('Danh mục ' + suggestions.category + ' được AI tự động tạo');
                            }
                            
                            // Hiển thị thông báo tạo danh mục mới
                            $('#newCategoryName').text(suggestions.category);
                            $('#categoryAutoCreateInfo').show();
                            
                            console.log('💾 Saved AI category info for auto-creation:', {
                                name: suggestions.category,
                                description: $('#aiCategoryDescription').val()
                            });
                            
                            // Log các options hiện có để debug
                            console.log('📝 Available categories:');
                            $('#categoryId option').each(function() {
                                if ($(this).val() !== '') {
                                    console.log(`   - "${$(this).text()}"`);
                                }
                            });
                        } else {
                            // Ẩn thông báo tạo danh mục mới nếu đã tìm thấy
                            $('#categoryAutoCreateInfo').hide();
                        }
                    }
                    
                    // Tự động tạo SKU dựa trên thương hiệu và loại sản phẩm
                    generateSmartSKU();
                    
                    // Debug: Force trigger category selection after a delay
                    setTimeout(() => {
                        console.log('🔄 Post-apply check - Category selected:', $('#categoryId').val());
                        console.log('🔄 Post-apply check - Category text:', $('#categoryId option:selected').text());
                        console.log('🔄 Post-apply check - AI category name stored:', $('#aiCategoryName').val());
                        console.log('🔄 Post-apply check - Category auto-create visible:', $('#categoryAutoCreateInfo').is(':visible'));
                    }, 500);
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
                // Đóng modal và tiếp tục thêm sản phẩm mới
                $('#duplicateCheckModal').modal('hide');
                // Chuyển đến bước 3 để nhập thông tin
                moveToStep(3);
                displaySuggestions();
            });
            
            // Handler cho nút "Sửa thông tin" - Load thông tin sản phẩm để chỉnh sửa thủ công
            $('#editProductManually').click(function() {
                console.log('🔧 "Sửa thông tin" button clicked');
                
                // Lấy sản phẩm được chọn
                const selectedProduct = $('.duplicate-product-card.border-primary').first();
                let productId = selectedProduct.data('product-id');
                let productName = selectedProduct.find('.card-title').first().text().trim();
                
                console.log('📝 Selected product ID:', productId);
                console.log('📝 Selected product name:', productName);
                
                // Nếu không có sản phẩm nào được chọn, lấy sản phẩm đầu tiên
                if (!productId) {
                    console.log('⚠️ No product selected, auto-selecting first product');
                    const firstProduct = $('.duplicate-product-card').first();
                    productId = firstProduct.data('product-id');
                    productName = firstProduct.find('.card-title').first().text().trim();
                    
                    // Hiển thị cảnh báo nhẹ
                    if (!productId) {
                        alert('Không tìm thấy sản phẩm nào để chỉnh sửa.');
                        return;
                    }
                    
                    // Tự động chọn sản phẩm đầu tiên
                    firstProduct.addClass('border-primary');
                    
                    // Thông báo đã tự động chọn
                    console.log('⚠️ Đã tự động chọn sản phẩm đầu tiên. ID:', productId);
                }
                
                if (productId) {
                    console.log('✅ Proceeding to load product data for ID:', productId);
                    
                    // Đóng modal duplicate
                    $('#duplicateCheckModal').modal('hide');
                    
                    // Load thông tin sản phẩm và điền vào form để chỉnh sửa thủ công
                    loadProductDataForManualEdit(productId, productName);
                } else {
                    alert('Vui lòng chọn sản phẩm cần sửa bằng cách click vào card sản phẩm');
                }
            });
            
            $('#updateExistingProduct').click(function() {
                // Lấy sản phẩm được chọn
                const selectedProduct = $('.duplicate-product-card.border-primary').first();
                let productId = selectedProduct.data('product-id');
                
                // Nếu không có sản phẩm nào được chọn, lấy sản phẩm đầu tiên
                if (!productId) {
                    const firstProduct = $('.duplicate-product-card').first();
                    productId = firstProduct.data('product-id');
                    firstProduct.addClass('border-primary');
                }
                
                if (productId) {
                    const productName = selectedProduct.find('.card-title').text() || $('.duplicate-product-card').first().find('.card-title').text();
                    
                    // Store selected product info for later use
                    window.selectedUpdateProduct = {
                        id: productId,
                        name: productName
                    };
                    
                    // Đóng modal duplicate và mở modal chọn nguồn dữ liệu
                    $('#duplicateCheckModal').modal('hide');
                    $('#updateSourceModal').modal('show');
                } else {
                    alert('Vui lòng chọn sản phẩm cần cập nhật bằng cách click vào card sản phẩm');
                }
            });
            
            // Click trên card sản phẩm trùng lặp để chọn
            // Sử dụng delegation từ modal container để đảm bảo event luôn hoạt động
            $(document).on('click', '#duplicateProductsList .duplicate-product-card', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                console.log('🖱️ Click detected on duplicate card');
                
                // Check if product is inactive
                const productStatus = $(this).data('product-status');
                if (productStatus === 'inactive') {
                    console.log('⚠️ Cannot select inactive product');
                    showInactiveWarning();
                    return false;
                }
                
                // Remove selection from all cards
                $('#duplicateProductsList .duplicate-product-card').removeClass('border-primary').removeAttr('style');
                
                // Add selection to clicked card  
                $(this).addClass('border-primary');
                
                // Show visual feedback
                const productName = $(this).find('.card-title').first().text().trim();
                const productId = $(this).data('product-id');
                console.log('✅ Đã chọn sản phẩm:', productName, 'ID:', productId);
            });
            
            // Update Source Selection Modal Handlers
            $(document).on('click', '.update-source-option', function() {
                $('.update-source-option').removeClass('selected');
                $(this).addClass('selected');
                $('#confirmUpdateSource').prop('disabled', false);
            });
            
            $('#confirmUpdateSource').click(function() {
                const selectedSource = $('.update-source-option.selected').data('source');
                const productInfo = window.selectedUpdateProduct;
                
                if (!selectedSource || !productInfo) {
                    alert('Vui lòng chọn một tùy chọn');
                    return;
                }
                
                // Đóng modal lựa chọn
                $('#updateSourceModal').modal('hide');
                
                if (selectedSource === 'existing') {
                    // Sử dụng thông tin từ sản phẩm cũ - set flag để ngăn AI override
                    useExistingDataOnly = true;
                    loadExistingProductData(productInfo.id, productInfo.name);
                } else if (selectedSource === 'ai') {
                    // Sử dụng thông tin từ AI nhưng giữ một số thông tin cũ
                    useExistingDataOnly = false;
                    loadExistingProductDataWithAI(productInfo.id, productInfo.name);
                }
            });
            
            // Reset modal when closed
            $('#updateSourceModal').on('hidden.bs.modal', function() {
                $('.update-source-option').removeClass('selected');
                $('#confirmUpdateSource').prop('disabled', true);
                // Reset flag khi đóng modal
                useExistingDataOnly = false;
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
                    return;
                }
                
                const brand = $('#brand').val().trim();
                const type = $('#type').val().trim();
                const name = $('#productName').val().trim();
                
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
                
                // Validate sizes - Kiểm tra CẢ variants cũ (existing) VÀ variants mới
                const sizeSelects = $('.size-select'); // Variants mới (dropdown)
                const existingVariants = $('.existing-variant'); // Variants cũ (readonly)
                
                let hasValidSize = false;
                let sizesData = [];
                
                // Kiểm tra trùng lặp cho variants mới
                if (sizeSelects.length > 0 && !checkDuplicateSizes()) {
                    showToast('Vui lòng không chọn trùng size!', 'error');
                    return;
                }
                
                // Đếm số variants cũ (existing) có giá hợp lệ
                let existingVariantCount = 0;
                existingVariants.each(function() {
                    const priceInput = $(this).find('input[name="existing_prices[]"]');
                    const price = parseFloat(priceInput.val()) || 0;
                    if (price >= 0) { // Cho phép giá = 0
                        existingVariantCount++;
                    }
                });
                
                // Đếm số variants mới (new) có size hợp lệ
                let newVariantCount = 0;
                for (let i = 0; i < sizeSelects.length; i++) {
                    const size = $(sizeSelects[i]).val();
                    const priceInput = $(sizeSelects[i]).closest('.size-row').find('input[name="size_prices[]"]');
                    const sizePrice = parseFloat(priceInput.val()) || 0;

                    if (size) {
                        newVariantCount++;
                        sizesData.push({ 
                            size: size,
                            price: sizePrice
                        });
                    }
                }
                
                // Tổng số variants = variants cũ + variants mới
                const totalVariants = existingVariantCount + newVariantCount;
                
                if (totalVariants === 0) {
                    showToast('Vui lòng có ít nhất một size! (Variants cũ hoặc thêm size mới)', 'error');
                    return;
                }
                
                // Có ít nhất 1 variant (cũ hoặc mới) → OK
                hasValidSize = true;
                console.log(`✅ Validation passed: ${existingVariantCount} existing variants + ${newVariantCount} new variants = ${totalVariants} total`);
                
                // Show loading
                const submitBtn = $(this).find('button[type="submit"]');
                const originalText = submitBtn.html();
                
                // Check if we're in AI update mode
                const isAIUpdateMode = window.aiUpdateMode && window.aiUpdateMode.productId;
                
                const loadingText = (isUpdateMode || isAIUpdateMode) ? 
                    '<i class="fas fa-spinner fa-spin"></i> Đang cập nhật...' : 
                    '<i class="fas fa-spinner fa-spin"></i> Đang tạo danh mục...';
                submitBtn.html(loadingText).prop('disabled', true);
                
                // Add update mode info to form data
                let formData = $(this).serialize();
                
                // Handle AI update mode
                if (isAIUpdateMode) {
                    formData += '&is_update_mode=true&update_product_id=' + window.aiUpdateMode.productId;
                    console.log('🔄 AI Update Mode: Cập nhật sản phẩm ID', window.aiUpdateMode.productId);
                } else if (isUpdateMode && updateProductId) {
                    formData += '&is_update_mode=true&update_product_id=' + updateProductId;
                }
                
                // Category field removed - using type field instead
                
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
                            // **NEW: Clear localStorage backup since product was saved successfully**
                            try {
                                localStorage.removeItem('uploadedImages');
                                console.log('🧹 Cleared uploadedImages from localStorage after successful save');
                            } catch (e) {
                                console.warn('Failed to clear localStorage:', e);
                            }
                            
                            // Display success message with details
                            let successMessage = response.message;
                            
                            if (response.created_sizes) {
                                let sizesList = response.created_sizes.map(s => 
                                    `Size ${s.size} (Giá: ${s.price ? s.price.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",") + '₫' : 'Chưa đặt giá'})`
                                ).join(', ');
                                const actionText = isUpdateMode ? 'cập nhật' : 'tạo';
                                showToast(`Danh mục sản phẩm đã được ${actionText} thành công! (${sizesList})`, 'success');
                            } else {
                                showToast(successMessage, 'success');
                            }
                            
                            moveToStep(4);
                            
                            // Update step 4 content with detailed info
                            if (response.created_sizes) {
                                let detailsHtml = `
                                    <div class="alert alert-info mt-3">
                                        <h6><i class="fas fa-info-circle"></i> Chi tiết danh mục sản phẩm vừa tạo:</h6>
                                        <ul class="mb-0">
                                            <li><strong>Tổng số size:</strong> ${response.created_sizes.length}</li>
                                            <li><strong>Trạng thái tồn kho:</strong> Chưa có hàng (0 đôi)</li>
                                            <li><strong>Chi tiết:</strong><br>
                                                ${response.created_sizes.map(s => 
                                                    `&nbsp;&nbsp;• Size ${s.size}: Giá ${s.price ? s.price.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",") + '₫' : 'Chưa đặt giá'} (SKU: ${s.sku})`
                                                ).join('<br>')}
                                            </li>
                                        </ul>
                                    </div>
                                    <div class="alert alert-warning mt-3">
                                        <h6><i class="fas fa-exclamation-triangle"></i> Bước tiếp theo:</h6>
                                        <p class="mb-2">Danh mục sản phẩm đã được tạo thành công! Để có số lượng tồn kho thực tế, bạn cần:</p>
                                        <ol class="mb-0">
                                            <li>Tạo phiếu nhập kho</li>
                                            <li>Chọn sản phẩm và size vừa tạo</li>
                                            <li>Nhập số lượng thực tế</li>
                                        </ol>
                                    </div>
                                `;
                                $('#step4-content .card-body').append(detailsHtml);
                                
                                // Thêm nút chuyển đến nhập kho
                                $('#step4-content .row').prepend(`
                                    <div class="col-md-6 mb-3">
                                        <a href="tao_phieu_nhap_moi.php?product_id=${response.product_id}" class="btn btn-warning btn-block">
                                            <i class="fas fa-warehouse"></i> Tạo phiếu nhập kho ngay
                                        </a>
                                    </div>
                                `);
                            }
                            
                            // Không auto redirect - để người dùng tự chọn
                            // Thêm nút để xem danh sách sản phẩm
                            setTimeout(() => {
                                $('#successActions').append(`
                                    <div class="col-md-6 mb-3">
                                        <a href="danh_sach_san_pham.php" class="btn btn-success btn-block">
                                            <i class="fas fa-list"></i> Xem danh sách sản phẩm
                                        </a>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <button type="button" class="btn btn-info btn-block" onclick="resetFormForNewProduct()">
                                            <i class="fas fa-plus"></i> Thêm sản phẩm mới
                                        </button>
                                    </div>
                                `);
                            }, 1000);
                        } else {
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
                                    <div class="col-md-3">
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
                                    <div class="col-md-3">
                                        <label class="form-label mb-1"><strong>Giá bán (VNĐ)</strong></label>
                                        <div class="input-group">
                                            <input type="number" name="size_prices[]" class="form-control" 
                                                   placeholder="VD: 500000" min="0" value="">
                                            <div class="input-group-append">
                                                <span class="input-group-text">₫</span>
                                            </div>
                                        </div>
                                        <small class="text-muted">Giá bán riêng cho size này</small>
                                    </div>
                                    <!-- Ngưỡng tối thiểu đã bị xóa theo yêu cầu -->
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

            // Xử lý xóa size
            $(document).on('click', '.remove-size-btn', function() {
                $(this).closest('.size-row').remove();
                updateRemoveButtons();
                checkDuplicateSizes();
                showToast('Đã xóa size', 'info');
            });

            // Cập nhật hiển thị nút xóa (chỉ hiện khi có nhiều hơn 1 size)
            function updateRemoveButtons() {
                const sizeRows = $('#sizeContainer .size-row');
                if (sizeRows.length <= 1) {
                    $('.remove-size-btn').hide();
                } else {
                    $('.remove-size-btn').show();
                }
            }

            // Xóa size row
            function removeSizeRow(element) {
                const $sizeRow = $(element).closest('.size-row');
                const sizeValue = $sizeRow.find('.size-select').val();
                
                if (confirm(`Bạn có chắc muốn xóa size ${sizeValue}?`)) {
                    $sizeRow.fadeOut(300, function() {
                        $(this).remove();
                        
                        // Cập nhật lại index cho các size row còn lại
                        $('#sizeContainer .size-row').each(function(index) {
                            $(this).attr('data-size-index', index);
                        });
                        
                        // Kiểm tra lại duplicate sau khi xóa
                        checkDuplicateSizes();
                        
                        // Hiển thị thông báo
                        showToast(`Đã xóa size ${sizeValue}`, 'success');
                        
                        // Hiển thị lại nút thêm size nếu cần
                        updateAddSizeButton();
                    });
                }
            }

            // Cập nhật trạng thái nút thêm size
            function updateAddSizeButton() {
                const totalSizes = $('#sizeContainer .size-row').length;
                const maxSizes = 14; // Tổng số size có thể (35-48)
                
                if (totalSizes >= maxSizes) {
                    $('#addSizeBtn').hide();
                } else {
                    $('#addSizeBtn').show();
                }
                
                // Ẩn/hiện nút xóa dựa trên số lượng size
                if (totalSizes <= 1) {
                    $('.remove-size-btn').hide();
                } else {
                    $('.remove-size-btn').show();
                }
            }

            // Kiểm tra trùng lặp size
            function checkDuplicateSizes() {
                const sizes = [];
                let hasDuplicate = false;
                
                // Get existing sizes from database (if in update mode)
                const existingSizes = window.existingVariantSizes || [];
                
                $('.size-select').each(function() {
                    const size = $(this).val();
                    const currentRow = $(this).closest('.size-row');
                    
                    // Skip if this is an existing variant (readonly)
                    if (currentRow.hasClass('existing-variant')) {
                        return; // Continue to next iteration
                    }
                    
                    // Reset style
                    currentRow.find('.card').removeClass('border-left-danger').addClass('border-left-primary');
                    
                    if (size) {
                        // Check if size conflicts with existing database variants
                        if (existingSizes.includes(size)) {
                            currentRow.find('.card').removeClass('border-left-primary border-left-success').addClass('border-left-danger');
                            hasDuplicate = true;
                        } 
                        // Check if size conflicts with other NEW variants being added
                        else if (sizes.includes(size)) {
                            currentRow.find('.card').removeClass('border-left-primary border-left-success').addClass('border-left-danger');
                            hasDuplicate = true;
                        } else {
                            sizes.push(size);
                        }
                    }
                });
                
                return !hasDuplicate;
            }

            // Kiểm tra trùng lặp size khi chọn
            $(document).on('change', '.size-select', function() {
                const selectedSize = $(this).val();
                const currentRow = $(this).closest('.size-row');
                
                // Skip check if this is an existing variant (readonly)
                if (currentRow.hasClass('existing-variant')) {
                    return;
                }
                
                if (selectedSize) {
                    let isDuplicate = false;
                    const currentSelect = this;
                    
                    // Get existing sizes from database (if in update mode)
                    const existingSizes = window.existingVariantSizes || [];
                    
                    // Check against existing database sizes
                    if (existingSizes.includes(selectedSize)) {
                        isDuplicate = true;
                        currentRow.find('.card').removeClass('border-left-primary border-left-success').addClass('border-left-danger');
                        showToast(`Size ${selectedSize} đã tồn tại trong database! Vui lòng chọn size khác hoặc cập nhật giá ở phần "Variants hiện có" bên trên.`, 'error');
                        $(this).val('');
                        $(this).focus();
                        return;
                    }
                    
                    // Check against other NEW size selects
                    $('.size-select').each(function() {
                        // Skip existing variants and self
                        if ($(this).closest('.size-row').hasClass('existing-variant')) {
                            return; // Continue
                        }
                        if (this !== currentSelect && $(this).val() === selectedSize) {
                            isDuplicate = true;
                            return false; // Break
                        }
                    });
                    
                    if (isDuplicate) {
                        currentRow.find('.card').removeClass('border-left-primary border-left-success').addClass('border-left-danger');
                        showToast(`Size ${selectedSize} đã được chọn trong danh sách size mới! Vui lòng chọn size khác.`, 'error');
                        $(this).val('');
                        $(this).focus();
                    } else {
                        currentRow.find('.card').removeClass('border-left-danger').addClass('border-left-success');
                        
                        // Ngưỡng tối thiểu đã bị xóa; không còn gợi ý tự động cho ngưỡng
                    }
                } else {
                    currentRow.find('.card').removeClass('border-left-success border-left-danger').addClass('border-left-primary');
                }
                
                checkDuplicateSizes();
            });

            // Xử lý tự động điền giá từ trường giá chung vào các trường giá theo size
            $('#price').on('input change', function() {
                const commonPrice = $(this).val();
                if (commonPrice && commonPrice > 0) {
                    // Tự động điền vào tất cả trường giá size đang trống
                    $('input[name="size_prices[]"]').each(function() {
                        if (!$(this).val() || $(this).val() == 0) {
                            $(this).val(commonPrice);
                        }
                    });
                    showToast('Đã tự động điền giá cho các size', 'info');
                }
            });

            // Thêm nút để áp dụng giá chung cho tất cả size
            function addApplyAllPriceButton() {
                if (!$('#applyAllPriceBtn').length) {
                    const applyBtn = `
                        <button type="button" id="applyAllPriceBtn" class="btn btn-outline-primary btn-sm mt-1" title="Áp dụng giá này cho tất cả size">
                            <i class="fas fa-copy"></i> Áp dụng cho tất cả size
                        </button>
                    `;
                    $('#price').parent().append(applyBtn);
                }
            }

            // Xử lý nút áp dụng giá cho tất cả
            $(document).on('click', '#applyAllPriceBtn', function() {
                const commonPrice = $('#price').val();
                if (commonPrice && commonPrice > 0) {
                    $('input[name="size_prices[]"]').val(commonPrice);
                    showToast(`Đã áp dụng giá ${parseInt(commonPrice).toLocaleString('vi-VN')}₫ cho tất cả size`, 'success');
                } else {
                    showToast('Vui lòng nhập giá chung trước', 'error');
                    $('#price').focus();
                }
            });

            // Hiển thị nút áp dụng giá khi có giá chung
            $('#price').on('focus', function() {
                addApplyAllPriceButton();
            });

            function moveToStep(step) {
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
                    // Step 1: Upload images
                    // Only show analyze section if images exist (but they should be cleared by backToAnalysis)
                    $('#analyzeSection').toggle(uploadedImages.length > 0);
                } else if (step === 2) {
                    // Step 2: AI Analysis
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
                } else if (step === 3) {
                    // Step 3: Edit product info
                    // Show suggestions if going back to step 3 (KHÔNG gọi khi update mode)
                    const isAIUpdateMode = window.aiUpdateMode && window.aiUpdateMode.productId;
                    if (suggestions && !isAIUpdateMode) {
                        displaySuggestions();
                    }
                    // In update mode, data will be filled by fillFormWithDatabaseData()
                }

                currentStep = step;
            }
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
                            <!-- Ngưỡng tối thiểu đã bị xóa theo yêu cầu -->
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

        // Manual Entry Functions
        function backToMethodSelector() {
            $('#manualWorkflow').hide();
            $('#aiWorkflow').hide();
            $('#methodSelector').show();
            resetManualForm();
        }

        // Hàm reset trạng thái SKU cơ sở về chế độ có thể chỉnh sửa
        function resetSkuFieldState() {
            $('#manualBaseSku').prop('readonly', false);
            $('#generateManualSkuBtn').prop('disabled', false).attr('title', 'Tự động tạo SKU');
            $('#manualSkuNote').remove();
            $('#manualProductId').val('');
        }

        function resetManualForm() {
            $('#manualProductForm')[0].reset();
            $('#manualDuplicateCheckResult').hide();
            $('#manualSkuExample').empty();
            manualUploadedImages = [];
            $('#manualImagePreview').empty();
            
            // Reset trạng thái SKU cơ sở
            resetSkuFieldState();
            
            // Xóa các phần tử thêm size mới và thông báo (nếu có)
            $('#addNewSizeBtn').remove();
            $('#newSizesContainer').remove();
            $('.alert-info').remove();
            
            // Reset size container to one row
            $('#manualSizeContainer').html(`
                <div class="size-row mb-3 p-3 border rounded">
                    <div class="row">
                        <div class="col-md-4">
                            <label class="form-label">Size</label>
                            <select class="form-control size-select" name="manual_sizes[]" required>
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
                        <div class="col-md-4">
                            <label class="form-label">Giá bán (VNĐ) <small class="text-muted price-source"></small></label>
                            <input type="number" class="form-control manual-price-input" name="manual_size_prices[]" placeholder="0" min="0" required>
                        </div>
                    </div>
                </div>
            `);
        }

        function addManualSize() {
            const newSizeRow = `
                <div class="size-row mb-3 p-3 border rounded">
                    <div class="row">
                        <div class="col-md-4">
                            <label class="form-label">Size</label>
                            <select class="form-control size-select" name="manual_sizes[]" required>
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
                        <div class="col-md-4">
                            <label class="form-label">Giá bán (VNĐ) <small class="text-muted price-source"></small></label>
                            <input type="number" class="form-control manual-price-input" name="manual_size_prices[]" placeholder="0" min="0" required>
                        </div>
                    </div>
                </div>
            `;
            $('#manualSizeContainer').append(newSizeRow);
            updateManualSkuExample();
        }

        function removeManualSize(btn) {
            if ($('#manualSizeContainer .size-row').length > 1) {
                $(btn).closest('.size-row').remove();
                updateManualSkuExample();
            } else {
                showToast('Cần ít nhất một size cho sản phẩm', 'warning');
            }
        }

        function checkManualDuplicatesAuto() {
            const productName = $('#manualProductName').val().trim();
            const brand = $('#manualBrand').val().trim();
            
            if (productName && brand) {
                checkManualDuplicates(false); // Auto check, không hiện loading button
            }
        }

        function checkManualDuplicatesManual() {
            checkManualDuplicates(true); // Manual check, hiện loading button
        }

        function checkManualDuplicates(showButton = true) {
            const productName = $('#manualProductName').val().trim();
            const brand = $('#manualBrand').val().trim();
            let type = $('#manualType').val().trim();
            
            // If "other" is selected, use custom input instead
            if (type === 'other') {
                type = $('#manualTypeCustom').val().trim();
            }
            
            const color = $('#manualColor').val().trim();
            
            if (!productName || !brand) {
                showToast('Vui lòng nhập tên sản phẩm và thương hiệu để kiểm tra trùng lặp', 'warning');
                return;
            }
            
            // Show loading state
            if (showButton) {
                $('#checkManualDuplicatesBtn').html('<i class="fas fa-spinner fa-spin"></i> Đang kiểm tra...').prop('disabled', true);
            }
            
            $.ajax({
                url: 'api_kiem_tra_trung_thu_cong.php',
                method: 'POST',
                data: {
                    action: 'check_manual_duplicates',
                    product_name: productName,
                    brand: brand,
                    type: type,
                    color: color
                },
                dataType: 'json',
                success: function(response) {
                    console.log('Manual duplicate check response:', response);
                    if (response.duplicates && response.duplicates.length > 0) {
                        console.log('First duplicate status:', response.duplicates[0].status);
                    }
                    displayManualDuplicateResult(response);
                },
                error: function() {
                    showToast('Có lỗi khi kiểm tra trùng lặp', 'error');
                },
                complete: function() {
                    if (showButton) {
                        $('#checkManualDuplicatesBtn').html('<i class="fas fa-search"></i> Kiểm tra trùng lặp').prop('disabled', false);
                    }
                }
            });
        }

        function displayManualDuplicateResult(response) {
            if (!response.success) {
                showToast('Lỗi kiểm tra trùng lặp: ' + response.message, 'error');
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
                    console.log('Processing manual duplicate:', {
                        id: product.product_id,
                        name: product.name,
                        status: product.status,
                        similarity: product.similarity
                    });
                    
                    const badgeClass = product.similarity >= 85 ? 'badge-danger' : 
                                     product.similarity >= 70 ? 'badge-warning' : 'badge-info';
                    
                    // Check if product is inactive
                    const isInactive = product.status === 'inactive';
                    const statusBadge = isInactive ? '<span class="badge badge-warning ml-2"><i class="fas fa-pause-circle"></i> Tạm ngưng</span>' : '';
                    const cardBorderClass = isInactive ? 'border-warning' : '';
                    const imageOpacity = isInactive ? 'opacity: 0.6;' : '';
                    
                    // Xử lý theo role
                    let cursorStyle, clickHandler;
                    if (isInactive) {
                        if (window.canReactivateProducts) {
                            cursorStyle = 'cursor: pointer; opacity: 0.7; background-color: #fff3cd;';
                            clickHandler = 'onclick="showInactiveWarningForAdmin()"';
                        } else {
                            cursorStyle = 'cursor: not-allowed; opacity: 0.7; background-color: #fff3cd;';
                            clickHandler = 'onclick="showInactiveWarningForEmployee()"';
                        }
                    } else {
                        cursorStyle = 'cursor: pointer;';
                        clickHandler = 'onclick="selectManualDuplicate(this)"';
                    }
                    
                    html += `
                        <div class="similar-product-item manual-duplicate-card border rounded p-3 mb-2 ${cardBorderClass}" 
                             ${clickHandler}
                             data-product-id="${product.product_id}"
                             data-product-name="${product.name}"
                             data-product-status="${product.status || 'active'}"
                             style="${cursorStyle} transition: all 0.3s ease;">
                            <div class="row align-items-center">
                                <div class="col-md-2">
                                    ${product.image_url ? 
                                        `<img src="${product.image_url}" class="img-thumbnail" style="max-width: 80px; ${imageOpacity}">` :
                                        `<div class="bg-light border rounded d-flex align-items-center justify-content-center" style="width: 80px; height: 80px; ${imageOpacity}">
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
                                    ${product.size_price_text ? `<p class="small text-info mb-1"><i class="fas fa-ruler"></i> ${product.size_price_text}</p>` : ''}
                                    <small class="text-muted">ID: ${product.product_id} • Tạo: ${product.created_at}</small>
                                    ${isInactive && window.canReactivateProducts ? `
                                    <br>
                                    <button class="btn btn-sm btn-success mt-2 btn-reactivate-product" 
                                            data-product-id="${product.product_id}" 
                                            data-product-name="${product.name.replace(/'/g, "\\'")}"
                                            type="button">
                                        <i class="fas fa-play"></i> Kích hoạt lại
                                    </button>
                                    ` : ''}
                                </div>
                                <div class="col-md-3 text-right">
                                    <span class="badge ${badgeClass} badge-lg">
                                        ${product.similarity}% tương đồng
                                    </span>
                                    <br>
                                    ${!isInactive ? `
                                    <div class="select-indicator mt-1" style="display: none;">
                                        <i class="fas fa-check-circle text-success"></i> Đã chọn
                                    </div>
                                    ` : `
                                    <small class="text-warning mt-2 d-block">
                                        <i class="fas fa-lock"></i> ${window.canReactivateProducts ? 
                                            'Cần kích hoạt lại' : 
                                            'Liên hệ Admin'
                                        }
                                    </small>
                                    `}
                                </div>
                            </div>
                        </div>
                    `;
                });
                
                html += `
                        </div>
                        
                        <div class="mt-3">
                            ${response.warning_level === 'high' ? 
                                '<p class="mb-3"><strong>Khuyến nghị:</strong> Nên xem lại thông tin sản phẩm để tránh trùng lặp.</p>' : ''}
                            <button class="btn btn-primary mr-2" id="loadProductForEditBtn" onclick="loadProductDataForEdit()" disabled>
                                <i class="fas fa-edit"></i> Sửa thông tin sản phẩm đã chọn
                            </button>
                            <button class="btn btn-success mr-2" onclick="continueAddingManualProduct()">
                                <i class="fas fa-plus"></i> Vẫn tiếp tục thêm mới
                            </button>
                            <button class="btn btn-secondary" onclick="$('#manualDuplicateCheckResult').slideUp()">
                                <i class="fas fa-times"></i> Đóng
                            </button>
                        </div>
                    </div>
                `;
            }
            
            $('#manualDuplicateCheckResult').html(html).slideDown();
        }

        let selectedManualProductId = null;

        function selectManualDuplicate(element) {
            const $card = $(element);
            const status = $card.data('product-status');
            
            // Check if product is inactive
            if (status === 'inactive') {
                if (window.canReactivateProducts) {
                    showInactiveWarningForAdmin();
                } else {
                    showInactiveWarningForEmployee();
                }
                return;
            }
            
            // Bỏ chọn tất cả các card khác
            $('.manual-duplicate-card').removeClass('selected');
            $('.manual-duplicate-card .select-indicator').hide();
            
            // Chọn card hiện tại
            $card.addClass('selected');
            $card.find('.select-indicator').show();
            
            // Lưu product_id
            selectedManualProductId = $card.data('product-id');
            
            // Enable nút "Sửa thông tin"
            $('#loadProductForEditBtn').prop('disabled', false);
            
            console.log('Selected manual product ID:', selectedManualProductId);
        }

        function loadProductDataForEdit() {
            if (!selectedManualProductId) {
                showToast('Vui lòng chọn một sản phẩm để sửa thông tin', 'warning');
                return;
            }

            // Hiển thị loading
            const loadingHtml = `
                <div class="text-center p-4">
                    <i class="fas fa-spinner fa-spin fa-3x text-primary"></i>
                    <p class="mt-3">Đang tải dữ liệu sản phẩm...</p>
                </div>
            `;
            $('#manualDuplicateCheckResult').html(loadingHtml);

            // Gọi API lấy thông tin chi tiết sản phẩm
            $.ajax({
                url: 'api_lay_chi_tiet_san_pham.php',
                method: 'POST',
                data: {
                    product_id: selectedManualProductId
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        populateFormWithProductData(response.product);
                        $('#manualDuplicateCheckResult').slideUp();
                        showToast('Đã tải thông tin sản phẩm. Bạn có thể chỉnh sửa và lưu lại.', 'success');
                    } else {
                        showToast('Lỗi: ' + response.message, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error loading product data:', error);
                    showToast('Có lỗi khi tải dữ liệu sản phẩm', 'error');
                }
            });
        }

        function populateFormWithProductData(product) {
            console.log('Populating form with product data:', product);
            console.log('Product variants:', product.variants);
            console.log('Variants count:', product.variants ? product.variants.length : 0);

            // Lưu product_id vào hidden field để biết đây là update
            $('#manualProductId').val(product.product_id);

            // Điền thông tin cơ bản
            // Loại sản phẩm - kiểm tra xem có trong dropdown không, nếu không thì thêm vào
            const typeValue = product.type || '';
            if (typeValue) {
                // Kiểm tra xem option đã tồn tại chưa
                if ($('#manualType option[value="' + typeValue + '"]').length === 0) {
                    // Nếu chưa có, thêm option mới
                    $('#manualType').append(new Option(typeValue, typeValue, true, true));
                } else {
                    // Nếu đã có, chỉ cần select
                    $('#manualType').val(typeValue);
                }
            }
            
            $('#manualBrand').val(product.brand || '');
            
            // Màu sắc - lấy từ colors_string (chuỗi màu phân cách bởi dấu phẩy)
            // hoặc lấy màu đầu tiên từ colors array
            let colorValue = '';
            if (product.colors_string) {
                colorValue = product.colors_string;
            } else if (product.colors && product.colors.length > 0) {
                colorValue = product.colors.join(', ');
            }
            $('#manualColor').val(colorValue);
            
            $('#manualFeatures').val(product.features || '');
            $('#manualDescription').val(product.description || '');
            $('#manualMaterial').val(product.material || '');
            $('#manualTags').val(product.tags || '');
            
            // SKU cơ sở - chỉ hiển thị, không cho phép chỉnh sửa khi đang sửa sản phẩm
            $('#manualBaseSku').val(product.base_sku || '').prop('readonly', true);
            
            // Vô hiệu hóa nút "Tạo SKU"
            $('#generateManualSkuBtn').prop('disabled', true).attr('title', 'SKU không thể thay đổi khi sửa sản phẩm');
            
            // Thêm ghi chú về SKU nếu chưa có
            if (!$('#manualSkuNote').length) {
                $('#manualBaseSku').closest('.form-group').find('.form-text').last().after(`
                    <small id="manualSkuNote" class="form-text text-warning mt-1">
                        <i class="fas fa-lock"></i> <strong>SKU cơ sở không thể thay đổi khi sửa sản phẩm.</strong> 
                        Size mới sẽ tự động tạo SKU riêng.
                    </small>
                `);
            }

            // Tạo tên sản phẩm
            $('#manualProductName').val(product.name || '');

            // Xử lý sizes và prices
            if (product.variants && product.variants.length > 0) {
                console.log('Loading variants from database:', product.variants);
                
                // Xóa tất cả size hiện tại
                $('#manualSizeContainer').empty();

                // Thêm lại các size từ database
                product.variants.forEach(function(variant, index) {
                    console.log(`Processing variant ${index + 1}:`, {
                        variant_id: variant.variant_id,
                        size: variant.size,
                        price: variant.price,
                        color: variant.color,
                        sku: variant.sku
                    });
                    
                    // Size từ database - chỉ hiển thị (readonly), Giá có thể chỉnh sửa
                    const sizeHtml = `
                        <div class="size-row mb-3 p-3 border rounded" data-variant-id="${variant.variant_id || ''}" data-existing="true">
                            <div class="row">
                                <div class="col-md-4">
                                    <label class="form-label">Size</label>
                                    <input type="text" class="form-control bg-light" value="${variant.size}" readonly>
                                    <input type="hidden" name="existing_sizes[]" value="${variant.size}">
                                    <input type="hidden" name="existing_variant_ids[]" value="${variant.variant_id || ''}">
                                    <small class="text-muted"><i class="fas fa-lock"></i> Size từ database (không thể thay đổi)</small>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Giá bán (VNĐ)</label>
                                    <input type="number" class="form-control" name="existing_prices[]" 
                                           value="${parseFloat(variant.price || 0)}" 
                                           placeholder="0" min="0" required>
                                    <small class="text-muted"><i class="fas fa-edit text-success"></i> Có thể chỉnh sửa giá</small>
                                </div>
                                <div class="col-md-4 d-flex align-items-center">
                                    <span class="badge badge-primary">
                                        <i class="fas fa-database"></i> Size từ kho
                                    </span>
                                </div>
                            </div>
                        </div>
                    `;
                    $('#manualSizeContainer').append(sizeHtml);
                });
                
                console.log(`Loaded ${product.variants.length} size variants from database`);
                
                // Thêm thông báo và nút thêm size mới
                const addNewSizeHtml = `
                    <div class="alert alert-info mt-3">
                        <i class="fas fa-info-circle"></i> 
                        <strong>Lưu ý:</strong> 
                        <ul class="mb-0 mt-2">
                            <li><strong>Size từ database:</strong> Không thể thay đổi (đã có dữ liệu tồn kho)</li>
                            <li><strong>Giá bán:</strong> Có thể chỉnh sửa trực tiếp</li>
                            <li><strong>Thêm size mới:</strong> Sử dụng nút bên dưới nếu cần thêm size khác</li>
                        </ul>
                    </div>
                    <button type="button" class="btn btn-success mb-3" id="addNewSizeBtn">
                        <i class="fas fa-plus"></i> Thêm size mới
                    </button>
                    <div id="newSizesContainer"></div>
                `;
                $('#manualSizeContainer').after(addNewSizeHtml);
                
                // Xử lý sự kiện thêm size mới
                $('#addNewSizeBtn').off('click').on('click', function() {
                    addNewManualSize();
                });
            } else {
                console.log('No variants found in database');
            }

            // Xử lý hình ảnh
            if (product.images && product.images.length > 0) {
                // Reset mảng ảnh và thêm ảnh từ database vào
                manualUploadedImages = [];
                
                let imagePreviewHtml = '<div class="row mt-3">';
                product.images.forEach(function(image) {
                    // Thêm ảnh vào mảng để submit
                    manualUploadedImages.push({
                        url: image.file_path,
                        is_primary: image.is_primary
                    });
                    
                    imagePreviewHtml += `
                        <div class="col-md-4 mb-3">
                            <div class="position-relative">
                                <img src="${image.file_path}" class="img-thumbnail" style="width: 100%; height: 150px; object-fit: cover;">
                                ${image.is_primary == 1 ? '<span class="badge badge-success position-absolute" style="top: 5px; right: 5px;">Ảnh chính</span>' : ''}
                            </div>
                        </div>
                    `;
                });
                imagePreviewHtml += '</div>';
                $('#manualImagePreview').html(imagePreviewHtml);
                
                console.log('Loaded images into manualUploadedImages:', manualUploadedImages);
            } else {
                // Không có ảnh thì reset về mảng rỗng
                manualUploadedImages = [];
            }

            // Scroll to top of form
            $('html, body').animate({
                scrollTop: $('#manualProductForm').offset().top - 100
            }, 500);
        }

        // Hàm thêm size mới khi đang sửa sản phẩm
        function addNewManualSize() {
            const sizeIndex = $('#newSizesContainer .new-size-row').length;
            
            // Lấy danh sách size đã có (từ database)
            const existingSizes = [];
            $('[name="existing_sizes[]"]').each(function() {
                existingSizes.push($(this).val());
            });
            
            // Lấy danh sách size mới đã thêm
            $('[name="new_sizes[]"]').each(function() {
                const val = $(this).val();
                if (val) existingSizes.push(val);
            });
            
            console.log('Existing sizes:', existingSizes);
            
            // Tạo danh sách size từ 35-48, loại trừ size đã có
            let sizeOptions = '<option value="">Chọn size mới</option>';
            let hasAvailableSize = false;
            for (let size = 35; size <= 48; size++) {
                if (!existingSizes.includes(size.toString())) {
                    sizeOptions += `<option value="${size}">${size}</option>`;
                    hasAvailableSize = true;
                }
            }
            
            // Kiểm tra nếu không còn size nào để thêm
            if (!hasAvailableSize) {
                showToast('Đã thêm đủ tất cả các size từ 35-48. Không còn size nào để thêm!', 'warning');
                return;
            }
            
            const newSizeHtml = `
                <div class="new-size-row mb-3 p-3 border rounded border-success">
                    <div class="row">
                        <div class="col-md-4">
                            <label class="form-label">Size mới</label>
                            <select class="form-control new-size-select" name="new_sizes[]" required onchange="validateNewSize(this)">
                                ${sizeOptions}
                            </select>
                            <small class="text-success"><i class="fas fa-plus-circle"></i> Size mới (chưa có trong kho)</small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Giá bán (VNĐ)</label>
                            <input type="number" class="form-control" name="new_size_prices[]" 
                                   placeholder="Nhập giá bán" min="0" required>
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <button type="button" class="btn btn-danger btn-sm" onclick="removeNewManualSize(this)">
                                <i class="fas fa-times"></i> Xóa
                            </button>
                        </div>
                    </div>
                </div>
            `;
            
            $('#newSizesContainer').append(newSizeHtml);
            showToast('Đã thêm trường size mới', 'success');
        }
        
        // Validate khi chọn size mới để đảm bảo không trùng
        function validateNewSize(selectElement) {
            const selectedSize = $(selectElement).val();
            
            // Lấy danh sách size đã có
            const existingSizes = [];
            $('[name="existing_sizes[]"]').each(function() {
                existingSizes.push($(this).val());
            });
            
            // Kiểm tra các size mới khác đã chọn
            $('[name="new_sizes[]"]').not(selectElement).each(function() {
                const val = $(this).val();
                if (val) existingSizes.push(val);
            });
            
            // Nếu size đã tồn tại
            if (existingSizes.includes(selectedSize)) {
                showToast(`Size ${selectedSize} đã tồn tại! Vui lòng chọn size khác.`, 'error');
                $(selectElement).val(''); // Reset về rỗng
                return false;
            }
            
            return true;
        }

        // Hàm xóa size mới
        function removeNewManualSize(button) {
            const sizeRow = $(button).closest('.new-size-row');
            const removedSize = sizeRow.find('[name="new_sizes[]"]').val();
            
            sizeRow.remove();
            showToast('Đã xóa size mới', 'info');
            
            // Cập nhật lại các dropdown size còn lại để thêm size vừa xóa vào
            if (removedSize) {
                updateNewSizeDropdowns();
            }
        }
        
        // Hàm cập nhật lại các dropdown size mới
        function updateNewSizeDropdowns() {
            // Lấy danh sách size đã có (từ database)
            const existingSizes = [];
            $('[name="existing_sizes[]"]').each(function() {
                existingSizes.push($(this).val());
            });
            
            // Cập nhật từng dropdown
            $('.new-size-select').each(function() {
                const currentSelect = $(this);
                const currentValue = currentSelect.val();
                
                // Lấy danh sách size mới đã chọn (trừ dropdown hiện tại)
                const otherSelectedSizes = [];
                $('.new-size-select').not(currentSelect).each(function() {
                    const val = $(this).val();
                    if (val) otherSelectedSizes.push(val);
                });
                
                // Tạo lại options
                let sizeOptions = '<option value="">Chọn size mới</option>';
                for (let size = 35; size <= 48; size++) {
                    const sizeStr = size.toString();
                    if (!existingSizes.includes(sizeStr) && !otherSelectedSizes.includes(sizeStr)) {
                        const selected = currentValue === sizeStr ? 'selected' : '';
                        sizeOptions += `<option value="${size}" ${selected}>${size}</option>`;
                    }
                }
                
                // Nếu giá trị hiện tại vẫn valid, giữ lại
                if (currentValue && !existingSizes.includes(currentValue)) {
                    currentSelect.html(sizeOptions);
                    if (currentValue) {
                        currentSelect.val(currentValue);
                    }
                } else {
                    currentSelect.html(sizeOptions);
                }
            });
        }

        function continueAddingManualProduct() {
            $('#manualDuplicateCheckResult').slideUp();
            
            // Reset lại trạng thái SKU cơ sở
            resetSkuFieldState();
            
            // Xóa các phần tử thêm size mới và thông báo
            $('#addNewSizeBtn').remove();
            $('#newSizesContainer').remove();
            $('.alert-info').remove();
            
            showToast('Tiếp tục thêm sản phẩm. Vui lòng hoàn tất thông tin còn lại.', 'info');
        }

        function handleManualFileUpload(files) {
            if (files.length > 3) {
                showToast('Chỉ có thể tải tối đa 3 ảnh', 'warning');
                return;
            }
            
            const formData = new FormData();
            files.forEach(function(file) {
                formData.append('images[]', file);
            });
            formData.append('action', 'upload_images');
            
            $('#manualUploadArea').html(`
                <div class="text-center">
                    <div class="spinner-border text-success" role="status"></div>
                    <p class="mt-2">Đang tải ảnh...</p>
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
                        manualUploadedImages = response.uploaded_files;
                        displayManualUploadedImages();
                    } else {
                        showToast('Lỗi tải ảnh: ' + response.message, 'error');
                    }
                    resetManualUploadArea();
                },
                error: function() {
                    showToast('Có lỗi khi tải ảnh', 'error');
                    resetManualUploadArea();
                }
            });
        }

        function displayManualUploadedImages() {
            let html = `<div class="alert alert-success mb-3">
                <i class="fas fa-check"></i> Đã tải ${manualUploadedImages.length} ảnh thành công!
            </div><div class="row">`;
            
            manualUploadedImages.forEach(function(file, index) {
                html += `
                    <div class="col-md-4 mb-3">
                        <div class="card">
                            <img src="${file.url}" class="card-img-top" style="height: 150px; object-fit: cover;">
                            <div class="card-body p-2">
                                <small class="text-muted">${file.name}</small>
                                <button class="btn btn-sm btn-outline-danger float-right" onclick="removeManualImage(${index})">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                `;
            });
            
            html += '</div>';
            $('#manualImagePreview').html(html);
        }

        function removeManualImage(index) {
            manualUploadedImages.splice(index, 1);
            if (manualUploadedImages.length > 0) {
                displayManualUploadedImages();
            } else {
                $('#manualImagePreview').empty();
            }
        }

        function resetManualUploadArea() {
            $('#manualUploadArea').html(`
                <div class="upload-icon">
                    <i class="fas fa-cloud-upload-alt"></i>
                </div>
                <h6>Chọn ảnh sản phẩm</h6>
                <p class="text-muted small">Kéo thả hoặc nhấn để chọn 1-3 ảnh (Tùy chọn)</p>
                <p class="text-muted small">Định dạng: JPG, PNG, WebP | Dung lượng: ≤5MB</p>
                <input type="file" id="manualFileInput" accept="image/*" multiple style="display: none;">
                <button type="button" class="btn btn-outline-primary" onclick="document.getElementById('manualFileInput').click()">
                    Chọn file
                </button>
            `);
        }

        function saveManualProduct() {
            // Validate form
            const productName = $('#manualProductName').val().trim();
            const brand = $('#manualBrand').val().trim();
            let type = $('#manualType').val().trim();
            
            // If "other" is selected, use custom input instead
            if (type === 'other') {
                type = $('#manualTypeCustom').val().trim();
                if (!type) {
                    showToast('Vui lòng nhập loại sản phẩm tùy chỉnh', 'warning');
                    return;
                }
            }
            
            const color = $('#manualColor').val().trim();
            const baseSku = $('#manualBaseSku').val().trim();
            const productId = $('#manualProductId').val(); // Kiểm tra xem có phải edit không
            
            if (!productName || !brand || !type || !color || !baseSku) {
                showToast('Vui lòng điền đầy đủ các thông tin bắt buộc (*)', 'warning');
                return;
            }
            
            // Validate sizes
            const sizes = [];
            const sizePrices = [];
            let hasValidSize = false;
            
            // Kiểm tra size từ database (khi edit sản phẩm)
            $('input[name="existing_sizes[]"]').each(function(index) {
                const size = $(this).val().trim();
                const price = parseFloat($('input[name="existing_prices[]"]').eq(index).val()) || 0;
                
                if (size && price > 0) {
                    hasValidSize = true;
                } else if (size && price <= 0) {
                    showToast(`Size ${size} từ database phải có giá bán lớn hơn 0`, 'warning');
                    return false;
                }
            });
            
            // Kiểm tra size mới (khi thêm sản phẩm hoặc thêm size mới khi edit)
            $('select[name="manual_sizes[]"]').each(function(index) {
                const size = $(this).val().trim();
                const price = parseFloat($('input[name="manual_size_prices[]"]').eq(index).val()) || 0;
                
                if (size && price > 0) {
                    sizes.push(size);
                    sizePrices.push(price);
                    hasValidSize = true;
                } else if (size || price > 0) {
                    showToast('Vui lòng điền đầy đủ size và giá bán cho tất cả dòng', 'warning');
                    return false;
                }
            });
            
            // Kiểm tra size mới khi edit sản phẩm (new_sizes[])
            $('select[name="new_sizes[]"]').each(function(index) {
                const size = $(this).val().trim();
                const price = parseFloat($('input[name="new_size_prices[]"]').eq(index).val()) || 0;
                
                if (size && price > 0) {
                    hasValidSize = true;
                } else if (size || price > 0) {
                    showToast('Vui lòng điền đầy đủ size và giá bán cho size mới', 'warning');
                    return false;
                }
            });
            
            if (!hasValidSize) {
                showToast('Vui lòng chọn ít nhất một size với giá bán hợp lệ', 'warning');
                return;
            }
            
            // Show loading
            const saveButtonText = productId ? 'Đang cập nhật...' : 'Đang lưu...';
            $('#saveManualProductBtn').html(`<i class="fas fa-spinner fa-spin"></i> ${saveButtonText}`).prop('disabled', true);
            
            // Debug logging
            console.log('=== Saving manual product with data ===');
            console.log('Product ID (edit mode):', productId);
            console.log('Product Name:', productName);
            console.log('Brand:', brand);
            console.log('Type:', type);
            console.log('Color:', color);
            console.log('Base SKU:', baseSku);
            console.log('Sizes (manual_sizes):', sizes);
            console.log('Size Prices (manual_size_prices):', sizePrices);
            
            // Log existing sizes (khi edit)
            const existingSizesDebug = [];
            const existingPricesDebug = [];
            $('input[name="existing_sizes[]"]').each(function(index) {
                existingSizesDebug.push($(this).val());
                existingPricesDebug.push($('input[name="existing_prices[]"]').eq(index).val());
            });
            console.log('Existing Sizes:', existingSizesDebug);
            console.log('Existing Prices:', existingPricesDebug);
            
            // Log new sizes (khi edit)
            const newSizesDebug = [];
            const newPricesDebug = [];
            $('select[name="new_sizes[]"]').each(function(index) {
                newSizesDebug.push($(this).val());
                newPricesDebug.push($('input[name="new_size_prices[]"]').eq(index).val());
            });
            console.log('New Sizes:', newSizesDebug);
            console.log('New Prices:', newPricesDebug);
            console.log('Has Valid Size:', hasValidSize);
            
            // Prepare form data
            const formData = new FormData();
            formData.append('action', 'save_product');
            formData.append('manual_product_name', productName);
            formData.append('manual_brand', brand);
            formData.append('manual_type', type);
            formData.append('manual_description', $('#manualDescription').val());
            formData.append('manual_material', $('#manualMaterial').val());
            formData.append('manual_features', $('#manualFeatures').val());
            formData.append('manual_tags', $('#manualTags').val());
            formData.append('manual_sku', baseSku);
            formData.append('manual_color', color);
            
            // Nếu là edit mode, thêm thông tin update
            if (productId) {
                formData.append('is_update_mode', 'true');
                formData.append('update_product_id', productId);
                
                console.log('🔧 Edit mode enabled: is_update_mode=true, update_product_id=' + productId);
                
                // Thêm existing sizes và prices (từ database)
                $('input[name="existing_sizes[]"]').each(function(index) {
                    const size = $(this).val();
                    const price = $('input[name="existing_prices[]"]').eq(index).val();
                    const variantId = $('input[name="existing_variant_ids[]"]').eq(index).val();
                    
                    if (size && price) {
                        formData.append('existing_sizes[]', size);
                        formData.append('existing_prices[]', price);
                        if (variantId) {
                            formData.append('existing_variant_ids[]', variantId);
                        }
                    }
                });
                
                // Thêm new sizes (size mới khi edit)
                $('select[name="new_sizes[]"]').each(function(index) {
                    const size = $(this).val();
                    const price = $('input[name="new_size_prices[]"]').eq(index).val();
                    
                    if (size && price) {
                        formData.append('new_sizes[]', size);
                        formData.append('new_size_prices[]', price);
                    }
                });
            }
            
            // Safe minimum price calculation - tính từ tất cả giá
            const allPrices = [];
            
            // Prices từ existing
            $('input[name="existing_prices[]"]').each(function() {
                const price = parseFloat($(this).val());
                if (price > 0) allPrices.push(price);
            });
            
            // Prices từ manual
            if (sizePrices.length > 0) {
                allPrices.push(...sizePrices);
            }
            
            // Prices từ new sizes
            $('input[name="new_size_prices[]"]').each(function() {
                const price = parseFloat($(this).val());
                if (price > 0) allPrices.push(price);
            });
            
            if (allPrices.length > 0) {
                formData.append('price', Math.min(...allPrices));
            } else {
                formData.append('price', 0);
            }
            
            // Add sizes and prices (cho trường hợp thêm mới sản phẩm)
            sizes.forEach(function(size, index) {
                formData.append('manual_sizes[]', size);
                formData.append('manual_size_prices[]', sizePrices[index]);
            });
            
            // Add image URLs (với kiểm tra an toàn)
            if (typeof manualUploadedImages !== 'undefined' && manualUploadedImages && manualUploadedImages.length > 0) {
                manualUploadedImages.forEach(function(image) {
                    if (image && image.url) {
                        formData.append('image_url[]', image.url);
                    }
                });
                console.log('Added images to form data:', manualUploadedImages.length);
            } else {
                console.log('No images to add to form data');
            }
            
            $.ajax({
                url: '',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    console.log('Save response:', response);
                    
                    if (response && response.success) {
                        const successMessage = productId ? 
                            'Sản phẩm đã được cập nhật thành công!' : 
                            'Sản phẩm đã được lưu thành công!';
                        showToast(successMessage, 'success');
                        
                        // Collect ALL sizes and prices for display (existing + manual + new)
                        const allSizesForDisplay = [];
                        
                        // 1. Existing sizes (từ database khi edit)
                        $('input[name="existing_sizes[]"]').each(function(index) {
                            const size = $(this).val().trim();
                            const price = parseFloat($('input[name="existing_prices[]"]').eq(index).val()) || 0;
                            if (size && price > 0) {
                                allSizesForDisplay.push({ size: size, price: price });
                            }
                        });
                        
                        // 2. Manual sizes (khi thêm mới hoặc edit)
                        $('select[name="manual_sizes[]"]').each(function(index) {
                            const size = $(this).val().trim();
                            const price = parseFloat($('input[name="manual_size_prices[]"]').eq(index).val()) || 0;
                            if (size && price > 0) {
                                allSizesForDisplay.push({ size: size, price: price });
                            }
                        });
                        
                        // 3. New sizes (khi edit và thêm size mới)
                        $('select[name="new_sizes[]"]').each(function(index) {
                            const size = $(this).val().trim();
                            const price = parseFloat($('input[name="new_size_prices[]"]').eq(index).val()) || 0;
                            if (size && price > 0) {
                                allSizesForDisplay.push({ size: size, price: price });
                            }
                        });
                        
                        // If response has created_sizes, use that instead
                        if (response.created_sizes && response.created_sizes.length > 0) {
                            allSizesForDisplay.length = 0; // Clear array
                            response.created_sizes.forEach(item => {
                                allSizesForDisplay.push({ 
                                    size: item.size, 
                                    price: parseFloat(item.price) || 0 
                                });
                            });
                        }
                        
                        console.log('📊 All sizes for display:', allSizesForDisplay);
                        
                        // Prepare success details
                        const successDetails = `
                            <div class="row">
                                <div class="col-md-6">
                                    <h6><i class="fas fa-info-circle text-primary"></i> Thông tin cơ bản</h6>
                                    <ul class="list-unstyled">
                                        <li><strong>Tên sản phẩm:</strong> ${productName}</li>
                                        <li><strong>Thương hiệu:</strong> ${brand}</li>
                                        <li><strong>Loại sản phẩm:</strong> ${type}</li>
                                        <li><strong>Màu sắc:</strong> ${color}</li>
                                        <li><strong>SKU cơ sở:</strong> ${baseSku}</li>
                                    </ul>
                                </div>
                                <div class="col-md-6">
                                    <h6><i class="fas fa-ruler text-warning"></i> Sizes & Giá bán</h6>
                                    <ul class="list-unstyled">
                                        ${allSizesForDisplay.length > 0 ? 
                                            allSizesForDisplay.map(item => 
                                                `<li><strong>Size ${item.size}:</strong> ${parseInt(item.price).toLocaleString('vi-VN')} VNĐ</li>`
                                            ).join('') 
                                            : '<li class="text-muted">Chưa có thông tin size</li>'
                                        }
                                    </ul>
                                    ${$('#manualDescription').val() ? `
                                    <h6><i class="fas fa-file-alt text-info"></i> Mô tả</h6>
                                    <p class="small text-muted">${$('#manualDescription').val()}</p>
                                    ` : ''}
                                </div>
                            </div>
                        `;
                        
                        // Show success section
                        $('#manualSuccessDetails').html(successDetails);
                        $('#manualWorkflow > .row').first().hide(); // Hide form
                        $('#manualSuccessSection').show();
                        
                        // Scroll to top
                        $('html, body').animate({
                            scrollTop: $('#manualSuccessSection').offset().top - 100
                        }, 500);
                        
                    } else {
                        const errorMsg = response && response.message ? response.message : 'Lỗi không xác định';
                        showToast('Lỗi lưu sản phẩm: ' + errorMsg, 'error');
                        console.error('Save failed:', response);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Ajax error:', xhr.status, xhr.responseText, error);
                    showToast('Có lỗi khi lưu sản phẩm: ' + error, 'error');
                },
                complete: function() {
                    $('#saveManualProductBtn').html('<i class="fas fa-save"></i> Lưu sản phẩm').prop('disabled', false);
                }
            });
        }

        // Manual Success Section Event Handlers
        $(document).on('click', '#addAnotherManualProductBtn', function() {
            // Reset manual form and show it again
            resetManualForm();
            $('#manualSuccessSection').hide();
            $('#manualWorkflow > .row').first().show();
            
            // Scroll to top of form
            $('html, body').animate({
                scrollTop: $('#manualWorkflow').offset().top - 100
            }, 500);
        });

        // Function to load price from database based on selected size (Manual form)
        function loadSizePrice($sizeSelect) {
            const size = $sizeSelect.val();
            const $row = $sizeSelect.closest('.size-row');
            const $priceInput = $row.find('.manual-price-input');
            const $priceSource = $row.find('.price-source');
            
            console.log('🔍 loadSizePrice called with size:', size);
            
            if (!size) {
                $priceSource.text('');
                return;
            }
            
            // Lấy product_id từ duplicate check hoặc để trống nếu là sản phẩm mới
            const productId = window.selectedDuplicateProductId || null;
            
            console.log('🔍 Product ID:', productId);
            
            if (!productId) {
                $priceSource.text('(giá mới)');
                console.log('⚠️ No product ID found, marking as new price');
                return;
            }
            
            console.log('📡 Calling API to get price for product:', productId, 'size:', size);
            
            // Gọi API lấy giá
            $.ajax({
                url: 'api_lay_gia_theo_size.php',
                type: 'GET',
                data: {
                    product_id: productId,
                    size: size
                },
                success: function(response) {
                    console.log('✅ API Response:', response);
                    if (response.success && response.price > 0) {
                        $priceInput.val(response.price);
                        $priceSource.text('(từ database)');
                        $priceInput.addClass('border-info');
                        showToast(`Đã load giá ${formatCurrency(response.price)} cho size ${size}`, 'info');
                        console.log('✅ Price loaded:', response.price);
                    } else {
                        $priceSource.text('(giá mới)');
                        $priceInput.removeClass('border-info');
                        console.log('⚠️ No price found or price is 0');
                    }
                },
                error: function(xhr, status, error) {
                    $priceSource.text('(lỗi load giá)');
                    console.error('❌ Failed to load price from database:', error);
                    console.error('Status:', status);
                    console.error('Response:', xhr.responseText);
                }
            });
        }
        
        // Function to load price from database for AI form
        function loadAISizePrice($sizeSelect) {
            const size = $sizeSelect.val();
            const $row = $sizeSelect.closest('.size-row');
            const $priceInput = $row.find('.ai-price-input');
            const $priceSource = $row.find('.price-source');
            
            console.log('🔍 loadAISizePrice called with size:', size);
            
            if (!size) {
                $priceSource.text('');
                return;
            }
            
            // Lấy product_id từ duplicate check hoặc update mode
            const productId = window.selectedDuplicateProductId || $('#updateProductId').val() || null;
            
            console.log('🔍 AI Product ID:', productId);
            
            if (!productId) {
                $priceSource.text('(giá mới)');
                console.log('⚠️ No product ID found, marking as new price');
                return;
            }
            
            console.log('📡 Calling API to get price for AI product:', productId, 'size:', size);
            
            // Gọi API lấy giá
            $.ajax({
                url: 'api_lay_gia_theo_size.php',
                type: 'GET',
                data: {
                    product_id: productId,
                    size: size
                },
                success: function(response) {
                    console.log('✅ API Response (AI):', response);
                    if (response.success && response.price > 0) {
                        $priceInput.val(response.price);
                        $priceSource.text('(từ database)');
                        $priceInput.addClass('border-info');
                        showToast(`Đã load giá ${formatCurrency(response.price)} cho size ${size}`, 'info');
                        console.log('✅ Price loaded for AI form:', response.price);
                    } else {
                        $priceSource.text('(giá mới)');
                        $priceInput.removeClass('border-info');
                        console.log('⚠️ No price found or price is 0 for AI form');
                    }
                },
                error: function(xhr, status, error) {
                    $priceSource.text('(lỗi load giá)');
                    console.error('❌ Failed to load price from database (AI):', error);
                    console.error('Status:', status);
                    console.error('Response:', xhr.responseText);
                }
            });
        }
        
        // Helper function to format currency
        function formatCurrency(amount) {
            return new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(amount);
        }

        // Function to reset manual form
        function resetManualForm() {
            // Clear all input fields
            $('#manualProductForm')[0].reset();
            
            // Clear uploaded images
            manualUploadedImages = [];
            $('#manualImagePreview').empty();
            
            // Reset size/price section to default
            $('#manualSizePriceContainer').html(`
                <div class="size-price-row mb-2">
                    <div class="row">
                        <div class="col-md-4">
                            <select class="form-control" name="manual_sizes[]" required>
                                <option value="">-- Chọn size --</option>
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
                        <div class="col-md-7">
                            <input type="number" class="form-control" name="manual_size_prices[]" placeholder="Giá bán (VNĐ)" min="0" required>
                        </div>
                        <div class="col-md-1 d-flex align-items-end">
                            <button type="button" class="btn btn-danger btn-sm remove-size-btn" onclick="removeSize(this)">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                </div>
            `);
            
            // Clear duplicate check results
            $('#manualDuplicateResults').hide().empty();
            
            // Reset selected duplicate product ID
            window.selectedDuplicateProductId = null;
            
            // Clear auto-generated SKU
            $('#manualSkuExample').empty();
            
            // Reset trạng thái SKU cơ sở
            resetSkuFieldState();
            
            showToast('Form đã được reset để thêm sản phẩm mới', 'success');
        }

        // Auto generate SKU when form fields change
        function autoGenerateManualSku() {
            console.log('🔧 autoGenerateManualSku() called'); // Debug
            
            const brand = $('#manualBrand').val().trim();
            const type = $('#manualType').val().trim();
            
            console.log('Current values - brand:', brand, 'type:', type); // Debug
            
            // Only auto-generate if both brand and type are available
            if (brand && type) {
                console.log('✅ Both brand and type available, proceeding...'); // Debug
                
                // Check if current SKU field is empty or was auto-generated previously
                const currentSku = $('#manualBaseSku').val().trim();
                const isSkuEmpty = !currentSku;
                
                // Check if SKU looks like it was auto-generated (contains our pattern)
                const isAutoGeneratedSku = currentSku.match(/^[A-Z]+-[A-Z0-9]+-\d{3}$/);
                
                // Check if SKU was previously generated from same brand/type
                const expectedBrandCode = brand.toUpperCase().substring(0, 4).replace(/[^A-Z]/g, '');
                const currentStartsWithBrand = currentSku.startsWith(expectedBrandCode + '-');
                
                console.log('Current SKU:', currentSku, 'isEmpty:', isSkuEmpty, 'isAutoGenerated:', isAutoGeneratedSku, 'startsWithBrand:', currentStartsWithBrand); // Debug
                
                // Auto-generate if field is empty, contains auto-generated SKU, or starts with different brand
                if (isSkuEmpty || isAutoGeneratedSku || (currentSku.includes('-') && !currentStartsWithBrand)) {
                    console.log('🚀 Generating new SKU...'); // Debug
                    const name = $('#manualProductName').val().trim();
                    
                    let skuBase = '';
                    
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
                    
                    // Thêm số ngẫu nhiên để đảm bảo unique
                    if (skuBase && skuBase.length > 0) {
                        const randomNum = Math.floor(Math.random() * 1000).toString().padStart(3, '0');
                        skuBase = skuBase + '-' + randomNum;
                    }
                    
                    console.log('Auto-generated Manual SKU:', skuBase); // Debug
                    $('#manualBaseSku').val(skuBase);
                    $('#manualBaseSku').removeClass('border-success border-warning').addClass('border-info'); // Visual feedback for auto-generation
                    
                    // Show temporary tooltip to indicate auto-update
                    const skuInput = $('#manualBaseSku');
                    const originalPlaceholder = skuInput.attr('placeholder');
                    skuInput.attr('placeholder', '✨ SKU đã được tự động cập nhật!').addClass('text-info');
                    
                    setTimeout(() => {
                        skuInput.attr('placeholder', originalPlaceholder).removeClass('text-info');
                    }, 2000);
                    
                    // Cập nhật ví dụ SKU
                    updateManualSkuExample();
                } else {
                    console.log('⏭️ SKU field contains user input, skipping auto-generation'); // Debug
                }
            } else {
                console.log('❌ Missing brand or type, skipping auto-generation'); // Debug
            }
        }

        // Manual SKU generation function (when button is clicked)
        function generateManualSku() {
            const brand = $('#manualBrand').val().trim();
            const type = $('#manualType').val().trim();
            const name = $('#manualProductName').val().trim();
            
            console.log('Generating Manual SKU with:', { brand, type, name }); // Debug
            
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
            
            console.log('Generated Manual SKU:', skuBase); // Debug
            $('#manualBaseSku').val(skuBase);
            $('#manualBaseSku').addClass('border-success'); // Visual feedback
            
            // Cập nhật ví dụ SKU
            updateManualSkuExample();
            
            // Hiển thị thông báo
            showToast('Đã tự động tạo mã SKU: ' + skuBase, 'success');
        }

        // Update Manual SKU example display
        function updateManualSkuExample() {
            const baseSku = $('#manualBaseSku').val().trim();
            if (baseSku) {
                // Lấy các size đã chọn hoặc dùng ví dụ mặc định
                const selectedSizes = [];
                $('select[name="manual_sizes[]"]').each(function() {
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
                    exampleHtml = examples.slice(0, 3).join(', ') + ', ...';
                }
                
                // Cập nhật hiển thị
                $('#manualSkuExample').html(`
                    <i class="fas fa-info-circle"></i> 
                    <strong>Mã SKU cơ sở sẽ được sử dụng để tạo SKU riêng cho từng size.</strong><br>
                    <i class="fas fa-tag text-success"></i> Ví dụ: <code class="bg-light p-1 rounded">${exampleHtml}</code>
                `);
            } else {
                $('#manualSkuExample').html(`
                    <i class="fas fa-info-circle"></i> 
                    <strong>Mã SKU cơ sở sẽ được sử dụng để tạo SKU riêng cho từng size.</strong><br>
                    <i class="fas fa-tag text-success"></i> Ví dụ: <code class="bg-light p-1 rounded">NIKE-AIR-123-38, NIKE-AIR-123-39, NIKE-AIR-123-40</code>
                `);
            }
        }
    </script>

<?php include 'includes/chan_trang.php'; ?>
