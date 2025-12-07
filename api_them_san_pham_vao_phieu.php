<?php
session_start();
header('Content-Type: application/json');
require_once 'config/database.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Chưa đăng nhập']);
    exit();
}

$database = new Database();
$pdo = $database->getConnection();
$userId = $_SESSION['user_id'];

try {
    // Lấy dữ liệu từ POST
    $receiptId = $_POST['receipt_id'] ?? 0;
    $variantId = $_POST['variant_id'] ?? 0;
    $quantity = intval($_POST['quantity'] ?? 1);
    $unitPrice = floatval($_POST['unit_price'] ?? 0);
    $salePrice = floatval($_POST['sale_price'] ?? 0);
    $locationCode = $_POST['location_code'] ?? null;
    
    error_log("Add product to receipt - receipt_id: $receiptId, variant_id: $variantId, quantity: $quantity, unit_price: $unitPrice, sale_price: $salePrice");
    
    if (!$receiptId || !$variantId) {
        throw new Exception('Thiếu thông tin receipt_id hoặc variant_id');
    }
    
    // Kiểm tra phiếu có tồn tại và là draft không
    $stmt = $pdo->prepare("
        SELECT receipt_id, status, warehouse_id 
        FROM stock_receipts 
        WHERE receipt_id = ? AND status = 'draft'
    ");
    $stmt->execute([$receiptId]);
    $receipt = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$receipt) {
        throw new Exception('Không tìm thấy phiếu nhập hoặc phiếu không ở trạng thái bản nháp');
    }
    
    // Kiểm tra variant có tồn tại không
    $stmt = $pdo->prepare("SELECT variant_id FROM product_variants WHERE variant_id = ?");
    $stmt->execute([$variantId]);
    if (!$stmt->fetch()) {
        throw new Exception('Không tìm thấy variant sản phẩm');
    }
    
    // Cập nhật giá bán vào product_variants nếu có
    if ($salePrice > 0) {
        $stmt = $pdo->prepare("
            UPDATE product_variants 
            SET price = ? 
            WHERE variant_id = ?
        ");
        $stmt->execute([$salePrice, $variantId]);
        error_log("Updated sale price for variant $variantId: $salePrice");
    }
    
    // Kiểm tra sản phẩm đã tồn tại trong phiếu chưa
    $stmt = $pdo->prepare("
        SELECT receipt_item_id, quantity, unit_price
        FROM stock_receipt_items 
        WHERE receipt_id = ? AND variant_id = ?
    ");
    $stmt->execute([$receiptId, $variantId]);
    $existingItem = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Lookup location_id từ location_code nếu có
    $locationId = null;
    if ($locationCode) {
        $stmt = $pdo->prepare("
            SELECT location_id 
            FROM locations 
            WHERE shelf_code = ? AND warehouse_id = ?
        ");
        $stmt->execute([$locationCode, $receipt['warehouse_id']]);
        $location = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($location) {
            $locationId = $location['location_id'];
            error_log("Found location_id: $locationId for location_code: $locationCode");
        } else {
            error_log("Warning: location_code '$locationCode' not found in locations table");
        }
    }
    
    if ($existingItem) {
        // Cập nhật số lượng (cộng thêm)
        $newQuantity = $existingItem['quantity'] + $quantity;
        $stmt = $pdo->prepare("
            UPDATE stock_receipt_items 
            SET quantity = ?, 
                unit_price = ?,
                location_code = COALESCE(?, location_code),
                location_id = COALESCE(?, location_id)
            WHERE receipt_item_id = ?
        ");
        $stmt->execute([$newQuantity, $unitPrice, $locationCode, $locationId, $existingItem['receipt_item_id']]);
        
        $message = 'Đã cập nhật số lượng sản phẩm trong phiếu (từ ' . $existingItem['quantity'] . ' lên ' . $newQuantity . ')';
        error_log($message);
    } else {
        // Thêm mới
        $stmt = $pdo->prepare("
            INSERT INTO stock_receipt_items 
            (receipt_id, variant_id, quantity, unit_price, location_code, location_id, warehouse_id) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$receiptId, $variantId, $quantity, $unitPrice, $locationCode, $locationId, $receipt['warehouse_id']]);
        
        $message = 'Đã thêm sản phẩm mới vào phiếu';
        error_log($message . " - location_id: $locationId");
    }
    
    // Cập nhật last_modified cho phiếu
    $stmt = $pdo->prepare("
        UPDATE stock_receipts 
        SET last_modified_by = ?, last_modified_at = NOW()
        WHERE receipt_id = ?
    ");
    $stmt->execute([$userId, $receiptId]);
    
    echo json_encode([
        'success' => true, 
        'message' => $message,
        'receipt_id' => $receiptId,
        'variant_id' => $variantId
    ]);
    
} catch (Exception $e) {
    error_log("Error adding product to receipt: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Lỗi: ' . $e->getMessage()
    ]);
}
