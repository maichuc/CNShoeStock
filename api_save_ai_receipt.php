<?php
/**
 * API Save AI Stock Receipt
 * Lưu phiếu nhập kho đã được phân tích bởi AI
 */

session_start();
header('Content-Type: application/json');

require_once 'config/database.php';
require_once 'classes/StockReceiptHistory.php';
require_once 'classes/QRCodeManager.php';

// Import standardizeBrand function
if (file_exists(__DIR__ . '/api_ai_analyze.php')) {
    require_once __DIR__ . '/api_ai_analyze.php';
}

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Chưa đăng nhập']);
    exit();
}

$database = new Database();
$pdo = $database->getConnection();
$historyManager = new StockReceiptHistory($pdo);
$qrManager = new QRCodeManager($pdo);

$userId = $_SESSION['user_id'];
$userWarehouseId = $_SESSION['warehouse_id'] ?? null;

// Lấy warehouse_id từ database nếu chưa có trong session
if (!$userWarehouseId) {
    $stmt = $pdo->prepare("SELECT warehouse_id FROM users WHERE user_id = ?");
    $stmt->execute([$userId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result) {
        $userWarehouseId = $result['warehouse_id'];
        $_SESSION['warehouse_id'] = $userWarehouseId;
    }
}

$input = json_decode(file_get_contents('php://input'), true);

try {
    $pdo->beginTransaction();
    
    // Validate input
    if (!isset($input['supplier_id']) || !isset($input['products']) || empty($input['products'])) {
        throw new Exception('Thiếu thông tin bắt buộc');
    }
    
    // Generate receipt code
    $receiptCode = 'SR' . date('Ymd') . rand(1000, 9999);
    
    // Calculate total
    $totalAmount = 0;
    foreach ($input['products'] as $product) {
        $totalAmount += ($product['quantity'] ?? 0) * ($product['price'] ?? 0);
    }
    
    // Create main receipt
    $stmt = $pdo->prepare("
        INSERT INTO stock_receipts (
            receipt_code, supplier_id, warehouse_id, user_id, 
            status, total_amount, ai_analysis_summary, notes, created_at
        ) VALUES (?, ?, ?, ?, 'pending', ?, ?, ?, NOW())
    ");
    
    $stmt->execute([
        $receiptCode,
        $input['supplier_id'],
        $input['warehouse_id'] ?? 1,
        $userId,
        $totalAmount,
        json_encode($input['ai_summary'] ?? []),
        $input['notes'] ?? ''
    ]);
    
    $receiptId = $pdo->lastInsertId();
    
    // Create products and variants
    foreach ($input['products'] as $product) {
        // Chuẩn hóa thương hiệu thành "Unknown" nếu không xác định
        if (isset($product['brand'])) {
            $originalBrand = $product['brand'];
            $product['brand'] = function_exists('standardizeBrand') 
                ? standardizeBrand($originalBrand) 
                : ($originalBrand ?: 'Unknown');
            if ($originalBrand !== $product['brand']) {
                error_log("🏷️ Standardized brand in receipt: '{$originalBrand}' -> '{$product['brand']}'");
            }
        } else {
            $product['brand'] = 'Unknown';
        }
        
        // Check if product exists
        $stmt = $pdo->prepare("
            SELECT product_id FROM products 
            WHERE LOWER(name) = LOWER(?) 
            LIMIT 1
        ");
        $productName = trim($product['brand'] . ' ' . $product['model']);
        $stmt->execute([$productName]);
        $existingProduct = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existingProduct) {
            $productId = $existingProduct['product_id'];
        } else {
            // Create new product với warehouse_id
            $stmt = $pdo->prepare("
                INSERT INTO products (name, description, category_id, warehouse_id, created_at)
                VALUES (?, ?, 1, ?, NOW())
            ");
            $stmt->execute([
                $productName,
                'AI imported - ' . ($product['brand'] ?? '') . ' ' . ($product['model'] ?? ''),
                $userWarehouseId
            ]);
            $productId = $pdo->lastInsertId();
        }
        
        // Check if variant exists
        $stmt = $pdo->prepare("
            SELECT variant_id FROM product_variants 
            WHERE product_id = ? AND color = ? AND size = ?
            LIMIT 1
        ");
        $stmt->execute([
            $productId,
            $product['color'] ?? '',
            $product['size'] ?? ''
        ]);
        $existingVariant = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existingVariant) {
            $variantId = $existingVariant['variant_id'];
        } else {
            // Create new variant
            $sku = strtoupper(substr($product['brand'] ?? 'UNK', 0, 3)) . '-' . 
                   strtoupper(substr($product['model'] ?? 'UNK', 0, 3)) . '-' .
                   strtoupper(substr($product['color'] ?? 'UNK', 0, 3)) . '-' .
                   ($product['size'] ?? '00');
            
            $stmt = $pdo->prepare("
                INSERT INTO product_variants (product_id, sku, color, size, price, warehouse_id, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            // AI phân tích được giá nhập, tạm tính giá bán = giá nhập * 1.3 (có thể điều chỉnh sau)
            $unitPrice = $product['price'] ?? 0;
            $estimatedSalePrice = $unitPrice > 0 ? round($unitPrice * 1.3) : 0;
            
            $stmt->execute([
                $productId,
                $sku,
                $product['color'] ?? '',
                $product['size'] ?? '',
                $estimatedSalePrice, // price = giá bán dự kiến
                $userWarehouseId
            ]);
            $variantId = $pdo->lastInsertId();
        }
        
        // Create receipt item
        $stmt = $pdo->prepare("
            INSERT INTO stock_receipt_items (
                receipt_id, variant_id, quantity, unit_price, 
                ai_confidence, ai_analysis, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $receiptId,
            $variantId,
            $product['quantity'] ?? 1,
            $product['price'] ?? 0,
            $product['confidence'] ?? 0,
            json_encode([
                'brand' => $product['brand'] ?? '',
                'model' => $product['model'] ?? '',
                'color' => $product['color'] ?? '',
                'size' => $product['size'] ?? ''
            ])
        ]);
        
        // Generate QR code for variant if not exists
        $existingQR = $qrManager->getQRCode($productId, $variantId);
        if (!$existingQR) {
            $qrManager->generateQRCode($productId, $variantId, $userId);
        }
    }
    
    // Log history
    $historyManager->logChange(
        $receiptId, 
        $userId, 
        'create',
        null,
        json_encode($input),
        'Tạo phiếu nhập với AI analysis - Success rate: ' . ($input['ai_summary']['success_rate'] ?? 'N/A')
    );
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Đã tạo phiếu nhập kho thành công',
        'receipt_id' => $receiptId,
        'receipt_code' => $receiptCode,
        'total_amount' => $totalAmount,
        'total_items' => count($input['products'])
    ]);
    
} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Save AI Receipt Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Có lỗi xảy ra: ' . $e->getMessage()
    ]);
}
