<?php
// Suppress all errors/warnings to prevent HTML output before JSON
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once 'config/database.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Read and parse JSON input
$raw_input = file_get_contents('php://input');
if (empty($raw_input)) {
    error_log("❌ Empty request body");
    http_response_code(400);
    echo json_encode(['error' => 'Empty request body']);
    exit;
}

$input = json_decode($raw_input, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    error_log("❌ Invalid JSON: " . json_last_error_msg());
    error_log("Raw input: " . substr($raw_input, 0, 500));
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON: ' . json_last_error_msg()]);
    exit;
}
$product_id = $input['product_id'] ?? null;
$warehouse_id = $input['warehouse_id'] ?? null;
$product_type = $input['product_type'] ?? null;
$product_brand = $input['product_brand'] ?? null;
$product_name = $input['product_name'] ?? null;
$product_size = $input['product_size'] ?? null;
$variant_id = $input['variant_id'] ?? null; // variant_id để kiểm tra vị trí cũ
$sku = $input['sku'] ?? null; // SKU để tìm variant_id và xác định SKU cơ sở
$exclude_locations = $input['exclude_locations'] ?? []; // Danh sách vị trí đã được chọn trong session

// Khởi tạo database connection
try {
    $database = new Database();
    $pdo = $database->getConnection();
} catch (Exception $e) {
    error_log("❌ Database connection error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// QUAN TRỌNG: Lấy tất cả vị trí đã được sử dụng trong các phiếu NHÁP
// để tránh xung đột khi nhiều phiếu nháp cùng dùng 1 vị trí
$draft_locations = [];
if ($warehouse_id) {
    try {
        $stmt_draft = $pdo->prepare("
            SELECT DISTINCT sri.location_code
            FROM stock_receipt_items sri
            INNER JOIN stock_receipts sr ON sri.receipt_id = sr.receipt_id
            WHERE sr.status = 'draft'
                AND sri.warehouse_id = ?
                AND sri.location_code IS NOT NULL
                AND sri.location_code != ''
        ");
        $stmt_draft->execute([$warehouse_id]);
        $draft_locations = $stmt_draft->fetchAll(PDO::FETCH_COLUMN);
        if ($draft_locations === false) {
            $draft_locations = [];
        }
        error_log("🚫 Found " . count($draft_locations) . " locations used in draft receipts");
    } catch (Exception $e) {
        error_log("⚠️ Error fetching draft locations: " . $e->getMessage());
        $draft_locations = [];
    }
}

// Đảm bảo exclude_locations luôn là array
if (!is_array($exclude_locations)) {
    $exclude_locations = [];
}

// Merge exclude_locations với draft locations
$all_excluded_locations = array_unique(array_merge($exclude_locations, $draft_locations));

// Gỡ lỗi log
error_log("=== Storage Suggestion API ===");
error_log("Product ID: {$product_id}");
error_log("Product Type: '{$product_type}' - Brand: '{$product_brand}'");
error_log("Product Name: {$product_name} - Size: {$product_size}");
error_log("SKU: {$sku} - Variant ID: {$variant_id}");
error_log("Exclude locations (input): " . json_encode($exclude_locations));
error_log("Draft locations: " . json_encode($draft_locations));
error_log("🚫 Total excluded locations: " . count($all_excluded_locations) . " - " . json_encode($all_excluded_locations));

// Debug: Log chi tiết về product type
if (empty($product_type)) {
    error_log("⚠️ WARNING: product_type is EMPTY!");
} else {
    error_log("✅ product_type has value: '{$product_type}'");
}

if (!$warehouse_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing warehouse_id']);
    exit;
}

if (!$product_id && (!$product_type || !$product_brand || !$product_name)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing product_id or complete product information (type, brand, name)']);
    exit;
}

try {
    // ============================================================
    // BƯỚC 0: Tìm variant_id và xác định SKU cơ sở
    // ============================================================
    if (!$variant_id && $sku) {
        $variantStmt = $pdo->prepare("SELECT variant_id FROM product_variants WHERE sku = ? LIMIT 1");
        $variantStmt->execute([$sku]);
        $variantRow = $variantStmt->fetch(PDO::FETCH_ASSOC);
        if ($variantRow) {
            $variant_id = $variantRow['variant_id'];
        }
    }
    
    // Xác định SKU cơ sở (base SKU) - Bỏ phần size cuối
    // VD: CHAR-HIGH-935-40 -> CHAR-HIGH-935
    $base_sku = null;
    if ($sku) {
        $parts = explode('-', $sku);
        if (count($parts) > 1) {
            // Bỏ phần cuối (size)
            array_pop($parts);
            $base_sku = implode('-', $parts);
        }
    }
    
    // ============================================================
    // BƯỚC 1: KIỂM TRA VỊ TRÍ CŨ (ƯU TIÊN CAO NHẤT)
    // Nếu variant_id này đã có vị trí trong kho → Gợi ý lại vị trí cũ
    // Kiểm tra cả inventory VÀ stock_receipt_items (BAO GỒM CẢ DRAFT)
    // QUY TẮC: 1 SIZE = 1 VỊ TRÍ CỐ ĐỊNH
    // ============================================================
    if ($variant_id) {
        error_log("🔍 BƯỚC 1: Tìm vị trí cũ cho variant_id = {$variant_id}");
        
        // BƯỚC 1.1: Kiểm tra trong inventory (vị trí thực tế đang lưu trữ) - ƯU TIÊN CAO NHẤT
        $existingLocationStmt = $pdo->prepare("
            SELECT 
                i.location_id,
                l.shelf_code AS location_code,
                l.description,
                i.quantity AS current_quantity,
                pv.sku,
                pv.size,
                p.name AS product_name,
                'inventory' as source,
                1 as priority
            FROM inventory i
            INNER JOIN locations l ON i.location_id = l.location_id
            INNER JOIN product_variants pv ON i.variant_id = pv.variant_id
            INNER JOIN products p ON pv.product_id = p.product_id
            WHERE i.variant_id = ? 
                AND i.warehouse_id = ?
                AND l.is_active = 1
                AND i.quantity > 0
            ORDER BY i.quantity DESC
            LIMIT 1
        ");
        $existingLocationStmt->execute([$variant_id, $warehouse_id]);
        $existingLocation = $existingLocationStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existingLocation) {
            error_log("✅ Tìm thấy vị trí từ INVENTORY: {$existingLocation['location_code']} (qty: {$existingLocation['current_quantity']})");
        }
        
        // BƯỚC 1.2: Nếu không có trong inventory, kiểm tra trong stock_receipt_items 
        // (BAO GỒM TẤT CẢ phiếu: draft, confirmed - để đảm bảo consistency)
        if (!$existingLocation) {
            $receiptLocationStmt = $pdo->prepare("
                SELECT 
                    l.location_id,
                    l.shelf_code AS location_code,
                    l.description,
                    0 AS current_quantity,
                    pv.sku,
                    pv.size,
                    p.name AS product_name,
                    sr.status as receipt_status,
                    CASE 
                        WHEN sr.status = 'confirmed' THEN 'receipt_confirmed'
                        WHEN sr.status = 'draft' THEN 'receipt_draft'
                        ELSE 'receipt_other'
                    END as source,
                    CASE 
                        WHEN sr.status = 'confirmed' THEN 2
                        WHEN sr.status = 'draft' THEN 3
                        ELSE 4
                    END as priority
                FROM stock_receipt_items sri
                INNER JOIN stock_receipts sr ON sri.receipt_id = sr.receipt_id
                INNER JOIN locations l ON sri.location_code = l.shelf_code
                INNER JOIN product_variants pv ON sri.variant_id = pv.variant_id
                INNER JOIN products p ON pv.product_id = p.product_id
                WHERE sri.variant_id = ? 
                    AND sri.warehouse_id = ?
                    AND sri.location_code IS NOT NULL
                    AND sri.location_code != ''
                    AND l.is_active = 1
                ORDER BY 
                    CASE WHEN sr.status = 'confirmed' THEN 1 ELSE 2 END ASC,
                    sri.created_at DESC
                LIMIT 1
            ");
            $receiptLocationStmt->execute([$variant_id, $warehouse_id]);
            $existingLocation = $receiptLocationStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existingLocation) {
                error_log("✅ Tìm thấy vị trí từ STOCK_RECEIPT_ITEMS ({$existingLocation['source']}): {$existingLocation['location_code']}");
            }
        }
        
        // BƯỚC 1.3: Nếu tìm thấy vị trí cũ, kiểm tra có bị exclude không
        if ($existingLocation) {
            $existingLocationCode = $existingLocation['location_code'];
            
            error_log("🎯 Vị trí cũ tìm thấy: {$existingLocationCode} (source: {$existingLocation['source']})");
            error_log("📋 Excluded locations: " . json_encode($all_excluded_locations));
            
            // KIỂM TRA: Vị trí cũ có bị loại trừ không?
            if (in_array($existingLocationCode, $all_excluded_locations)) {
                error_log("⚠️ VỊ TRÍ CŨ {$existingLocationCode} ĐÃ BỊ LOẠI TRỪ (đang được size khác sử dụng trong phiếu hiện tại)");
                error_log("⚠️ CẢNH BÁO: Vi phạm quy tắc '1 size = 1 vị trí'. Size này đã có vị trí cố định!");
                error_log("⚠️ KHÔNG TÌM VỊ TRÍ MỚI - Vẫn gợi ý vị trí cũ với cảnh báo conflict");
                
                // TRẢ VỀ VỊ TRÍ CŨ với cảnh báo conflict
                $sourceText = '';
                switch($existingLocation['source']) {
                    case 'inventory':
                        $sourceText = 'đang lưu trữ trong kho';
                        break;
                    case 'receipt_confirmed':
                        $sourceText = 'đã được gán trong phiếu nhập đã xác nhận';
                        break;
                    case 'receipt_draft':
                        $sourceText = 'đã được gán trong phiếu nhập nháp';
                        break;
                    default:
                        $sourceText = 'đã được gán trước đó';
                }
                
                echo json_encode([
                    'success' => true,
                    'product' => [
                        'type' => $product_type,
                        'brand' => $product_brand,
                        'name' => $existingLocation['product_name'],
                        'size' => $existingLocation['size'],
                        'sku' => $existingLocation['sku'],
                        'id' => $product_id,
                        'variant_id' => $variant_id
                    ],
                    'strategy' => [
                        'phase' => 'Vị trí cố định (có conflict)',
                        'name' => 'Existing Location with Conflict',
                        'description' => "⚠️ Vị trí cố định của size này đang bị trùng với size khác trong phiếu hiện tại"
                    ],
                    'suggestions' => [[
                        'location_id' => $existingLocation['location_id'],
                        'location_code' => $existingLocation['location_code'],
                        'description' => $existingLocation['description'],
                        'priority' => 'Critical',
                        'current_quantity' => $existingLocation['current_quantity'],
                        'has_conflict' => true,
                        'reasoning' => [
                            "🎯 Đây là vị trí CỐ ĐỊNH của size {$existingLocation['size']} ({$sourceText})",
                            "⚠️ CẢNH BÁO: Vị trí này đang được size khác sử dụng trong phiếu hiện tại!",
                            "📦 SKU: {$existingLocation['sku']} - Size: {$existingLocation['size']}",
                            $existingLocation['current_quantity'] > 0 ? "📊 Số lượng hiện tại: {$existingLocation['current_quantity']} đôi" : "📍 Vị trí đã được thiết lập",
                            "❗ Quy tắc: 1 size = 1 vị trí cố định. Vui lòng kiểm tra lại việc chọn size!"
                        ]
                    ]],
                    'message' => 'Vị trí cố định của size này (có cảnh báo conflict)',
                    'warning' => 'Vị trí này đang được size khác sử dụng trong phiếu hiện tại. Vui lòng kiểm tra lại!'
                ]);
                exit;
            } else {
                // ✅ Vị trí cũ không bị exclude → Gợi ý lại bình thường
                error_log("✅ Vị trí cũ {$existingLocationCode} chưa bị loại trừ - Gợi ý lại");
                
                $sourceText = '';
                switch($existingLocation['source']) {
                    case 'inventory':
                        $sourceText = 'đang lưu trữ trong kho';
                        break;
                    case 'receipt_confirmed':
                        $sourceText = 'đã được gán trong phiếu nhập đã xác nhận';
                        break;
                    case 'receipt_draft':
                        $sourceText = 'đã được gán trong phiếu nhập nháp';
                        break;
                    default:
                        $sourceText = 'đã được gán trước đó';
                }
                
                echo json_encode([
                    'success' => true,
                    'product' => [
                        'type' => $product_type,
                        'brand' => $product_brand,
                        'name' => $existingLocation['product_name'],
                        'size' => $existingLocation['size'],
                        'sku' => $existingLocation['sku'],
                        'id' => $product_id,
                        'variant_id' => $variant_id
                    ],
                    'strategy' => [
                        'phase' => 'Vị trí cố định đã tồn tại',
                        'name' => 'Existing Location Reuse',
                        'description' => "Size này đã có vị trí lưu trữ cố định ({$sourceText})"
                    ],
                    'suggestions' => [[
                        'location_id' => $existingLocation['location_id'],
                        'location_code' => $existingLocation['location_code'],
                        'description' => $existingLocation['description'],
                        'priority' => 'Highest',
                        'current_quantity' => $existingLocation['current_quantity'],
                        'has_conflict' => false,
                        'reasoning' => [
                            "🎯 Vị trí CỐ ĐỊNH của size này ({$sourceText})",
                            "📦 SKU: {$existingLocation['sku']} - Size: {$existingLocation['size']}",
                            $existingLocation['current_quantity'] > 0 ? "📊 Số lượng hiện tại: {$existingLocation['current_quantity']} đôi" : "📍 Vị trí đã được thiết lập",
                            "✅ Nhập thêm vào vị trí này (quy tắc: 1 size = 1 vị trí cố định)"
                        ]
                    ]],
                    'message' => 'Size này đã có vị trí lưu trữ cố định'
                ]);
                exit;
            }
        } else {
            error_log("ℹ️ Không tìm thấy vị trí cũ cho variant_id {$variant_id}. Sẽ gợi ý vị trí mới.");
        }
    } else {
        error_log("ℹ️ Không có variant_id. Sẽ gợi ý vị trí mới dựa trên thông tin sản phẩm.");
    }
    
    // Lấy product details for category and brand
    if ($product_id) {
        $stmt = $pdo->prepare("
            SELECT p.type, p.brand, p.name, pv.sku 
            FROM products p 
            LEFT JOIN product_variants pv ON p.product_id = pv.product_id 
            WHERE p.product_id = ? 
            LIMIT 1
        ");
        $stmt->execute([$product_id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$product) {
            http_response_code(404);
            echo json_encode(['error' => 'Product not found']);
            exit;
        }
    } else {
        // Use provided product information
        $product = [
            'type' => $product_type,
            'brand' => $product_brand,
            'name' => $product_name,
            'sku' => $sku
        ];
    }
    
    // Gán các biến để sử dụng trong logic sau
    $productType = $product['type'] ?? 'Unknown';
    $productBrand = $product['brand'] ?? 'Unknown Brand';
    $productName = $product['name'] ?? 'Unknown Product';
    
    // ============================================================
    // GIAI ĐOẠN 2: TÌM VỊ TRÍ PHÙ HỢP (ƯU TIÊN CÙNG KỆ VỚI SKU CƠ SỞ)
    // Logic: 
    // 1. Ưu tiên: Cùng kệ với các size khác của cùng SKU cơ sở
    // 2. Sau đó: Vị trí trống theo type
    // 3. Chỉ gợi ý vị trí ĐANG HOẠT ĐỘNG (is_active = 1)
    // ============================================================
    
    $suggestions = [];
    
    // ============================================================
    // BƯỚC 1: Ưu tiên cao nhất - Tìm vị trí trống CÙNG KỆ với SKU cơ sở
    // Định nghĩa mã vị trí: SNEAKER-K1-T1-P1
    //   - Khu vực: SNEAKER
    //   - Kệ: K1
    //   - Tầng: T1
    //   - Vị trí: P1
    // Ưu tiên: Các size cùng SKU cơ sở nên ở cùng kệ (K-T)
    // ============================================================
    $sameShelfLocations = [];
    if ($base_sku) {
        // Bước 1.1: Tìm các vị trí của size khác cùng SKU cơ sở
        // Kiểm tra CÁ 2 bảng: inventory VÀ stock_receipt_items
        
        // Từ inventory (vị trí đang lưu trữ)
        $existingLocationsStmt = $pdo->prepare("
            SELECT DISTINCT l.shelf_code
            FROM inventory i
            INNER JOIN locations l ON i.location_id = l.location_id
            INNER JOIN product_variants pv ON i.variant_id = pv.variant_id
            WHERE i.warehouse_id = ?
                AND l.is_active = 1
                AND pv.sku LIKE ?
                AND i.quantity > 0
        ");
        $existingLocationsStmt->execute([$warehouse_id, $base_sku . '%']);
        $existingShelfCodes = $existingLocationsStmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Từ stock_receipt_items (các phiếu nhập trước đó)
        $receiptLocationsStmt = $pdo->prepare("
            SELECT DISTINCT sri.location_code AS shelf_code
            FROM stock_receipt_items sri
            INNER JOIN product_variants pv ON sri.variant_id = pv.variant_id
            INNER JOIN locations l ON sri.location_code = l.shelf_code
            WHERE sri.warehouse_id = ?
                AND l.is_active = 1
                AND pv.sku LIKE ?
                AND sri.location_code IS NOT NULL
        ");
        $receiptLocationsStmt->execute([$warehouse_id, $base_sku . '%']);
        $receiptShelfCodes = $receiptLocationsStmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Gộp cả 2 nguồn
        $existingShelfCodes = array_unique(array_merge($existingShelfCodes, $receiptShelfCodes));
        
        // Bước 1.2: Extract kệ từ mã vị trí
        // Định dạng: AREA-K#-T#-P# (VD: SNEAKER-K1-T1-P1)
        // Lấy phần K#-T# để xác định kệ
        $shelves = [];
        foreach ($existingShelfCodes as $shelfCode) {
            // Pattern: AREA-K#-T#-P# 
            // Extract phần K#-T# (kệ và tầng)
            if (preg_match('/-(K\d+)-(T\d+)-/i', $shelfCode, $matches)) {
                $shelf = $matches[1] . '-' . $matches[2]; // Ví dụ: K1-T1
                $shelves[] = $shelf;
            }
        }
        $shelves = array_unique($shelves);
        
        // Bước 1.3: Tìm vị trí TRỐNG trên cùng kệ
        if (!empty($shelves)) {
            $shelfPatterns = array_map(function($shelf) {
                return "%-{$shelf}-%"; // Pattern: %-K1-T1-%
            }, $shelves);
            
            // Xây dựng dynamic WHERE clause for multiple shelves
            $shelfConditions = implode(' OR ', array_fill(0, count($shelfPatterns), 'l.shelf_code LIKE ?'));
            
            // Xây dựng exclude condition
            $excludeCondition = '';
            if (!empty($all_excluded_locations)) {
                $excludePlaceholders = implode(',', array_fill(0, count($all_excluded_locations), '?'));
                $excludeCondition = " AND l.shelf_code NOT IN ({$excludePlaceholders})";
            }
            
            $emptyShelfLocationsStmt = $pdo->prepare("
                SELECT 
                    l.location_id,
                    l.shelf_code AS location_code,
                    l.description,
                    l.type
                FROM locations l
                LEFT JOIN inventory i ON l.location_id = i.location_id 
                    AND i.warehouse_id = ? 
                    AND i.quantity > 0
                WHERE l.warehouse_id = ?
                    AND l.is_active = 1
                    AND l.type IS NOT NULL
                    AND LOWER(l.type) = LOWER(?)
                    AND i.inventory_id IS NULL
                    AND ({$shelfConditions})
                    {$excludeCondition}
                ORDER BY l.shelf_code ASC
                LIMIT 5
            ");
            
            $params = array_merge([$warehouse_id, $warehouse_id, $productType], $shelfPatterns, $all_excluded_locations);
            $emptyShelfLocationsStmt->execute($params);
            $sameShelfLocations = $emptyShelfLocationsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            error_log("🔍 STEP 1: Found " . count($sameShelfLocations) . " empty locations on same shelf with matching type '{$productType}'");
            if (count($sameShelfLocations) > 0) {
                foreach ($sameShelfLocations as $loc) {
                    error_log("  - {$loc['location_code']} (type: {$loc['type']})");
                }
            } else {
                error_log("⚠️ STEP 1: No locations found - might be type mismatch or all occupied");
            }
        }
    }
    
    // Nếu tìm thấy vị trí trống cùng kệ với SKU cơ sở
    if (!empty($sameShelfLocations)) {
        foreach ($sameShelfLocations as $location) {
            $suggestions[] = [
                'location_id' => $location['location_id'],
                'location_code' => $location['location_code'],
                'description' => $location['description'],
                'priority' => 'Highest',
                'reasoning' => [
                    "⭐ Vị trí trống cùng kệ với các size khác của sản phẩm này",
                    "📦 Mã vị trí: {$location['location_code']}",
                    "🆓 Vị trí hoàn toàn trống (chưa có sản phẩm nào)",
                    "🎯 Ưu tiên cao nhất: Các size cùng sản phẩm nên ở cùng kệ"
                ]
            ];
        }
        
        // Return ngay khi tìm thấy vị trí trống cùng kệ
        echo json_encode([
            'success' => true,
            'product' => [
                'type' => $productType,
                'brand' => $productBrand,
                'name' => $productName,
                'size' => $product_size,
                'id' => $product_id,
                'variant_id' => $variant_id
            ],
            'strategy' => [
                'phase' => 'Vị trí trống cùng kệ với SKU cơ sở',
                'name' => 'Empty Same Shelf Location',
                'description' => 'Các size cùng sản phẩm nên để cùng kệ'
            ],
            'suggestions' => $suggestions,
            'message' => 'Tìm thấy ' . count($suggestions) . ' vị trí trống cùng kệ với sản phẩm này'
        ]);
        exit;
    }
    
    // Bước 1: Định nghĩa ánh xạ khu vực dựa trên loại sản phẩm
    $zoneMapping = [
        'Sneaker' => 'A',
        'Boot' => 'A',
        'Giày Cao Gót' => 'B',
        'Giày cao gót (Kitten Heels)' => 'B',
        'High Heels' => 'B',
        'Sandal' => 'B',
        'Slingback' => 'B',
        'Mule' => 'C',
        'Giày tây' => 'C',
        'Oxford' => 'C',
        'Loafer' => 'C',
        'Slipper' => 'C',
        'Bệt' => 'D'
    ];
    
    $preferredZone = $zoneMapping[$productType] ?? 'D'; // Default to zone D for unknown types
    
    // Bước 2: Lấy thông tin kích cỡ nếu có (cho logic kệ theo size)
    // Priority: 1. From request input, 2. từ database
    if (!$product_size && $product_id) {
        // Fallback: Try to get từ database if not provided in request
        $sizeStmt = $pdo->prepare("SELECT size FROM product_variants WHERE product_id = ? LIMIT 1");
        $sizeStmt->execute([$product_id]);
        $sizeRow = $sizeStmt->fetch(PDO::FETCH_ASSOC);
        $product_size = $sizeRow ? $sizeRow['size'] : null;
    }
    
    // Bước 3: Tìm vị trí phù hợp (theo type và chưa có size này)
    // Convert product type sang không dấu để match với shelf_code
    function removeVietnameseTones($str) {
        $vietnameseMap = array(
            'à'=>'a','á'=>'a','ạ'=>'a','ả'=>'a','ã'=>'a','â'=>'a','ầ'=>'a','ấ'=>'a','ậ'=>'a','ẩ'=>'a','ẫ'=>'a','ă'=>'a','ằ'=>'a','ắ'=>'a','ặ'=>'a','ẳ'=>'a','ẵ'=>'a',
            'è'=>'e','é'=>'e','ẹ'=>'e','ẻ'=>'e','ẽ'=>'e','ê'=>'e','ề'=>'e','ế'=>'e','ệ'=>'e','ể'=>'e','ễ'=>'e',
            'ì'=>'i','í'=>'i','ị'=>'i','ỉ'=>'i','ĩ'=>'i',
            'ò'=>'o','ó'=>'o','ọ'=>'o','ỏ'=>'o','õ'=>'o','ô'=>'o','ồ'=>'o','ố'=>'o','ộ'=>'o','ổ'=>'o','ỗ'=>'o','ơ'=>'o','ờ'=>'o','ớ'=>'o','ợ'=>'o','ở'=>'o','ỡ'=>'o',
            'ù'=>'u','ú'=>'u','ụ'=>'u','ủ'=>'u','ũ'=>'u','ư'=>'u','ừ'=>'u','ứ'=>'u','ự'=>'u','ử'=>'u','ữ'=>'u',
            'ỳ'=>'y','ý'=>'y','ỵ'=>'y','ỷ'=>'y','ỹ'=>'y',
            'đ'=>'d',
            'À'=>'A','Á'=>'A','Ạ'=>'A','Ả'=>'A','Ã'=>'A','Â'=>'A','Ầ'=>'A','Ấ'=>'A','Ậ'=>'A','Ẩ'=>'A','Ẫ'=>'A','Ă'=>'A','Ằ'=>'A','Ắ'=>'A','Ặ'=>'A','Ẳ'=>'A','Ẵ'=>'A',
            'È'=>'E','É'=>'E','Ẹ'=>'E','Ẻ'=>'E','Ẽ'=>'E','Ê'=>'E','Ề'=>'E','Ế'=>'E','Ệ'=>'E','Ể'=>'E','Ễ'=>'E',
            'Ì'=>'I','Í'=>'I','Ị'=>'I','Ỉ'=>'I','Ĩ'=>'I',
            'Ò'=>'O','Ó'=>'O','Ọ'=>'O','Ỏ'=>'O','Õ'=>'O','Ô'=>'O','Ồ'=>'O','Ố'=>'O','Ộ'=>'O','Ổ'=>'O','Ỗ'=>'O','Ơ'=>'O','Ờ'=>'O','Ớ'=>'O','Ợ'=>'O','Ở'=>'O','Ỡ'=>'O',
            'Ù'=>'U','Ú'=>'U','Ụ'=>'U','Ủ'=>'U','Ũ'=>'U','Ư'=>'U','Ừ'=>'U','Ứ'=>'U','Ự'=>'U','Ử'=>'U','Ữ'=>'U',
            'Ỳ'=>'Y','Ý'=>'Y','Ỵ'=>'Y','Ỷ'=>'Y','Ỹ'=>'Y',
            'Đ'=>'D'
        );
        return strtr($str, $vietnameseMap);
    }
    
    $productTypeNormalized = removeVietnameseTones($productType);
    $productTypeNormalized = strtoupper($productTypeNormalized);
    $productTypeNormalized = str_replace(' ', '-', $productTypeNormalized);
    
    $availableLocations = [];
    
    // BƯỚC 2: Tìm vị trí TRỐNG theo loại sản phẩm (type)
    // CHỈ tìm vị trí HOÀN TOÀN TRỐNG (chưa có sản phẩm nào)
    if (empty($suggestions) && !empty($productType)) {
        error_log("🔍 STEP 2: Searching by product type: '{$productType}'");
        
        // Xây dựng exclude condition
        $excludeCondition = '';
        $excludeParams = [];
        if (!empty($all_excluded_locations)) {
            $excludePlaceholders = implode(',', array_fill(0, count($all_excluded_locations), '?'));
            $excludeCondition = " AND l.shelf_code NOT IN ($excludePlaceholders)";
            $excludeParams = $all_excluded_locations;
            error_log("🔍 STEP 2: Excluding " . count($all_excluded_locations) . " locations");
        }
        
        $availableByTypeStmt = $pdo->prepare("
            SELECT 
                l.location_id, 
                l.shelf_code AS location_code, 
                l.description
            FROM locations l
            LEFT JOIN inventory i ON l.location_id = i.location_id 
                AND i.warehouse_id = ? 
                AND i.quantity > 0
            WHERE l.warehouse_id = ?
                AND l.is_active = 1
                AND l.type IS NOT NULL
                AND LOWER(l.type) = LOWER(?)
                AND i.inventory_id IS NULL
                {$excludeCondition}
            ORDER BY l.shelf_code ASC
            LIMIT 5
        ");
        $availableByTypeStmt->execute(array_merge([$warehouse_id, $warehouse_id, $productType], $excludeParams));
        $availableLocations = $availableByTypeStmt->fetchAll(PDO::FETCH_ASSOC);
        
        error_log("✅ STEP 2: Found " . count($availableLocations) . " locations for type '{$productType}'");
        if (count($availableLocations) > 0) {
            foreach ($availableLocations as $loc) {
                error_log("  - {$loc['location_code']}");
            }
        }
    } else {
        if (!empty($suggestions)) {
            error_log("⏭️ STEP 2: Skipped - already have suggestions from STEP 1");
        } else {
            error_log("⚠️ STEP 2: Skipped - productType is EMPTY: '{$productType}'");
        }
    }
    
    // BƯỚC 3: Fallback - Tìm vị trí TRỐNG theo mã vị trí (legacy)
    // CHỈ tìm vị trí HOÀN TOÀN TRỐNG
    if (empty($suggestions) && empty($availableLocations)) {
        // Xây dựng exclude condition
        $excludeCondition = '';
        $excludeParams = [];
        if (!empty($all_excluded_locations)) {
            $excludePlaceholders = implode(',', array_fill(0, count($all_excluded_locations), '?'));
            $excludeCondition = " AND l.shelf_code NOT IN ({$excludePlaceholders})";
            $excludeParams = $all_excluded_locations;
        }
        
        $availableLocationsStmt = $pdo->prepare("
            SELECT 
                l.location_id, 
                l.shelf_code AS location_code, 
                l.description
            FROM locations l
            LEFT JOIN inventory i ON l.location_id = i.location_id 
                AND i.warehouse_id = ? 
                AND i.quantity > 0
            WHERE l.warehouse_id = ?
                AND l.is_active = 1
                AND l.shelf_code LIKE ?
                AND i.inventory_id IS NULL
                {$excludeCondition}
            ORDER BY l.shelf_code ASC
            LIMIT 5
        ");
        $availableLocationsStmt->execute(array_merge([$warehouse_id, $warehouse_id, "%$productTypeNormalized%"], $excludeParams));
        $availableLocations = $availableLocationsStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Thêm vị trí trống vào suggestions
    if (!empty($availableLocations)) {
        foreach ($availableLocations as $location) {
            $suggestions[] = [
                'location_id' => $location['location_id'],
                'location_code' => $location['location_code'],
                'description' => $location['description'],
                'priority' => 'High',
                'reasoning' => [
                    "✅ Vị trí trống cho loại $productType",
                    "📦 Mã vị trí: {$location['location_code']}",
                    "🆓 Vị trí hoàn toàn trống (chưa có sản phẩm nào)",
                    "🎯 Đây sẽ là vị trí cố định cho size này"
                ]
            ];
        }
    }
    
    // Return suggestions nếu có
    if (!empty($suggestions)) {
        echo json_encode([
            'success' => true,
            'product' => [
                'type' => $productType,
                'brand' => $productBrand,
                'name' => $productName,
                'size' => $product_size,
                'id' => $product_id,
                'variant_id' => $variant_id
            ],
            'strategy' => [
                'phase' => 'Tìm vị trí phù hợp',
                'name' => 'Location Assignment',
                'description' => 'Gợi ý vị trí (ưu tiên cùng kệ với SKU cơ sở)'
            ],
            'suggestions' => $suggestions,
            'message' => 'Tìm thấy ' . count($suggestions) . ' vị trí phù hợp'
        ]);
        exit;
    }

    // Nếu KHÔNG CÓ vị trí nào → Hiển thị thông báo
    if (empty($suggestions)) {
        echo json_encode([
            'success' => false,
            'suggestions' => [],
            'product' => [
                'type' => $productType,
                'brand' => $productBrand,
                'name' => $productName,
                'size' => $product_size,
                'id' => $product_id,
                'variant_id' => $variant_id
            ],
            'strategy' => [
                'phase' => 'Không có vị trí trống',
                'name' => 'No Empty Location Available',
                'description' => 'Không tìm thấy vị trí trống phù hợp'
            ],
            'message' => "⚠️ Không có vị trí trống cho loại sản phẩm '$productType'",
            'action_required' => [
                'title' => 'Cần tạo vị trí kho mới',
                'description' => "Tất cả vị trí cho loại sản phẩm này đã có sản phẩm. Vui lòng tạo thêm vị trí mới hoặc nhập thủ công.",
                'link' => 'vi_tri_kho.php',
                'link_text' => 'Đi tới trang Vị trí Kho'
            ]
        ]);
    }
    
} catch (Exception $e) {
    error_log("Storage Suggestion API Error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'error' => 'Database error: ' . $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}
?>