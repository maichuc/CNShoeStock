<?php
session_start();
header('Content-Type: application/json');

require_once 'config/database.php';
require_once 'helpers/GhiNhatKyKiemToan.php';
require_once 'classes/QuanLyMaQR.php';
require_once 'classes/TaoMaQR.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    error_log('API Error: No user_id in session. Session data: ' . print_r($_SESSION, true));
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

error_log('API: User authenticated. User ID: ' . $_SESSION['user_id'] . ', Warehouse ID: ' . ($_SESSION['warehouse_id'] ?? 'not set'));

$database = new Database();
$pdo = $database->getConnection();

try {
    // Lấy dữ liệu JSON từ request
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    // Gỡ lỗi logging
    error_log('API Input: ' . $input);
    error_log('Decoded data: ' . print_r($data, true));
    error_log('JSON last error: ' . json_last_error_msg());

    if (!$data) {
        $error_msg = 'Invalid JSON data. Input: ' . substr($input, 0, 200) . '... JSON Error: ' . json_last_error_msg();
        error_log('API Error: ' . $error_msg);
        echo json_encode(['success' => false, 'message' => $error_msg]);
        exit();
    }

    $user_id = $_SESSION['user_id'];
    $warehouse_id = $_SESSION['warehouse_id'];
    $supplier_id = $data['supplier_id'];
    $receipt_date = $data['receipt_date'] ?? date('Y-m-d'); // Use today if not provided
    $notes = $data['notes'] ?? '';
    $items = $data['items'] ?? [];
    $status = $data['status'] ?? 'draft'; // draft, completed, or pending
    
    // Ánh xạ frontend status vào database ENUM values
    // Frontend sends: 'draft' or 'completed'
    // Database expects: 'draft', 'pending', 'confirmed', 'completed', 'rejected'
    // For manual receipt:
    // - 'draft' -> stay 'draft' (work in progress)
    // - 'completed' -> change to 'confirmed' (finished and inventory updated)
    $original_status = $status;
    if ($status === 'completed') {
        $status = 'confirmed'; // Map 'completed' to 'confirmed' for database
        error_log("API: Mapped status '$original_status' to '$status'");
    }
    
    $manual_entry = $data['manual_entry'] ?? false;

    // Kiểm tra required fields
    if (!$supplier_id || !$receipt_date || empty($items)) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit();
    }

    $pdo->beginTransaction();

    // KIỂM TRA DUPLICATE: Tránh tạo phiếu nhập trùng lặp trong vòng 5 giây
    $stmt = $pdo->prepare("
        SELECT receipt_id, created_at 
        FROM stock_receipts 
        WHERE user_id = ? 
        AND warehouse_id = ? 
        AND supplier_id = ? 
        AND status = ?
        AND created_at >= DATE_SUB(NOW(), INTERVAL 5 SECOND)
        ORDER BY created_at DESC 
        LIMIT 1
    ");
    $stmt->execute([$user_id, $warehouse_id, $supplier_id, $status]);
    $recentReceipt = $stmt->fetch();
    
    if ($recentReceipt) {
        // Phát hiện duplicate request trong vòng 5 giây
        error_log("API WARNING: Duplicate receipt detected within 5 seconds. Returning existing receipt_id={$recentReceipt['receipt_id']}");
        
        // Trả về phiếu nhập đã tạo thay vì tạo mới
        $pdo->commit();
        echo json_encode([
            'success' => true,
            'message' => 'Stock receipt already created (duplicate prevented)',
            'receipt_id' => $recentReceipt['receipt_id'],
            'status' => $status,
            'duplicate_prevented' => true
        ]);
        exit();
    }

    error_log("API: About to INSERT stock_receipt. user_id=$user_id, warehouse_id=$warehouse_id, supplier_id=$supplier_id, status=$status");

    // 1. Tạo stock receipt chính
    $stmt = $pdo->prepare("
        INSERT INTO stock_receipts (user_id, warehouse_id, supplier_id, status, notes, last_modified_by) 
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $user_id, 
        $warehouse_id, 
        $supplier_id, 
        $status, 
        $notes,
        $user_id  // last_modified_by
    ]);
    
    $receipt_id = $pdo->lastInsertId();
    error_log("API: Created receipt_id=$receipt_id");

    // 2. Xử lý từng item
    foreach ($items as $item) {
        $product_id = null;
        $variant_id = null;
        $location_id = null;
        
        // Lấy location_id from storageLocation (shelf_code)
        if (!empty($item['storageLocation'])) {
            $stmt = $pdo->prepare("
                SELECT location_id 
                FROM locations 
                WHERE warehouse_id = ? AND shelf_code = ?
            ");
            $stmt->execute([$warehouse_id, $item['storageLocation']]);
            $location_row = $stmt->fetch();
            if ($location_row) {
                $location_id = $location_row['location_id'];
                error_log("API: Found location_id=$location_id for shelf_code={$item['storageLocation']}");
            } else {
                error_log("API: Warning - No location_id found for shelf_code={$item['storageLocation']}");
            }
        }

        // ✅ QUY ĐỊNH MỚI: KIỂM TRA 1 SIZE CHỈ ĐƯỢC Ở 1 VỊ TRÍ
        // Kiểm tra xem size này đã tồn tại ở vị trí khác chưa
        if (!empty($item['size']) && $location_id) {
            // Kiểm tra trong inventory
            $stmt = $pdo->prepare("
                SELECT i.location_id, l.shelf_code, pv.sku, p.name
                FROM inventory i
                INNER JOIN product_variants pv ON i.variant_id = pv.variant_id
                INNER JOIN products p ON pv.product_id = p.product_id
                INNER JOIN locations l ON i.location_id = l.location_id
                WHERE p.warehouse_id = ? 
                AND p.name = ? 
                AND p.brand = ? 
                AND pv.size = ? 
                AND i.location_id != ?
                LIMIT 1
            ");
            $stmt->execute([
                $warehouse_id,
                $item['name'],
                $item['brand'],
                $item['size'],
                $location_id
            ]);
            $existingInventory = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existingInventory) {
                $pdo->rollBack();
                error_log("API: VALIDATION ERROR - Size '{$item['size']}' đã tồn tại ở vị trí {$existingInventory['shelf_code']} (SKU: {$existingInventory['sku']})");
                echo json_encode([
                    'success' => false,
                    'message' => "Lỗi: Size '{$item['size']}' của sản phẩm '{$item['name']}' đã tồn tại ở vị trí {$existingInventory['shelf_code']}. Quy định: 1 size chỉ được lưu ở 1 vị trí. Vui lòng chọn vị trí {$existingInventory['shelf_code']} hoặc di chuyển tồn kho hiện tại.",
                    'error_code' => 'DUPLICATE_SIZE_LOCATION',
                    'existing_location' => $existingInventory['shelf_code'],
                    'item' => [
                        'name' => $item['name'],
                        'size' => $item['size'],
                        'attempted_location' => $item['storageLocation']
                    ]
                ]);
                exit();
            }
            
            // Kiểm tra trong stock_receipt_items (phiếu nhập khác)
            $stmt = $pdo->prepare("
                SELECT sri.location_id, l.shelf_code, pv.sku, p.name
                FROM stock_receipt_items sri
                INNER JOIN product_variants pv ON sri.variant_id = pv.variant_id
                INNER JOIN products p ON pv.product_id = p.product_id
                INNER JOIN locations l ON sri.location_id = l.location_id
                WHERE sri.warehouse_id = ? 
                AND p.name = ? 
                AND p.brand = ? 
                AND pv.size = ? 
                AND sri.location_id != ?
                LIMIT 1
            ");
            $stmt->execute([
                $warehouse_id,
                $item['name'],
                $item['brand'],
                $item['size'],
                $location_id
            ]);
            $existingReceipt = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existingReceipt) {
                $pdo->rollBack();
                error_log("API: VALIDATION ERROR - Size '{$item['size']}' đã được nhập vào vị trí {$existingReceipt['shelf_code']} trong phiếu nhập khác");
                echo json_encode([
                    'success' => false,
                    'message' => "Lỗi: Size '{$item['size']}' của sản phẩm '{$item['name']}' đã được nhập vào vị trí {$existingReceipt['shelf_code']}. Quy định: 1 size chỉ được lưu ở 1 vị trí. Vui lòng chọn vị trí {$existingReceipt['shelf_code']}.",
                    'error_code' => 'DUPLICATE_SIZE_LOCATION',
                    'existing_location' => $existingReceipt['shelf_code'],
                    'item' => [
                        'name' => $item['name'],
                        'size' => $item['size'],
                        'attempted_location' => $item['storageLocation']
                    ]
                ]);
                exit();
            }
            
            error_log("API: ✅ Validation passed - Size '{$item['size']}' chưa tồn tại ở vị trí khác");
        }

        // BƯỚC 1: Kiểm tra xem SKU đã tồn tại chưa (check trước để tránh tạo duplicate product)
        $stmt = $pdo->prepare("
            SELECT pv.variant_id, pv.product_id, p.warehouse_id
            FROM product_variants pv
            JOIN products p ON pv.product_id = p.product_id
            WHERE pv.sku = ?
            LIMIT 1
        ");
        $stmt->execute([$item['sku']]);
        $existing_variant_by_sku = $stmt->fetch();

        if ($existing_variant_by_sku) {
            // SKU đã tồn tại
            if ($existing_variant_by_sku['warehouse_id'] == $warehouse_id) {
                // Cùng warehouse - SỬ DỤNG product đã có
                $product_id = $existing_variant_by_sku['product_id'];
                $variant_id = $existing_variant_by_sku['variant_id'];
                
                // Update variant với thông tin mới
                $stmt = $pdo->prepare("
                    UPDATE product_variants 
                    SET price = ?, color = ?, size = ?, updated_at = NOW()
                    WHERE variant_id = ?
                ");
                $stmt->execute([
                    $item['salePrice'] ?? 0, // price = giá bán
                    $item['color'],
                    $item['size'],
                    $variant_id
                ]);
                
                error_log("SKU exists in same warehouse - Updated variant: SKU={$item['sku']}, variant_id=$variant_id, product_id=$product_id");
            } else {
                // Khác warehouse - Cho phép tạo product mới với cùng SKU
                error_log("SKU exists in different warehouse (WH {$existing_variant_by_sku['warehouse_id']}), will create new product");
            }
        }

        // BƯỚC 2: Nếu chưa có product_id, tìm hoặc tạo product
        if (!$product_id) {
            // Kiểm tra xem sản phẩm đã tồn tại chưa
            $stmt = $pdo->prepare("
                SELECT p.product_id 
                FROM products p 
                WHERE p.warehouse_id = ? AND p.name = ? AND p.brand = ? AND p.type = ?
                LIMIT 1
            ");
            $stmt->execute([$warehouse_id, $item['name'], $item['brand'], $item['type']]);
            $existing_product = $stmt->fetch();

            if ($existing_product) {
                $product_id = $existing_product['product_id'];
                error_log("Found existing product: product_id=$product_id");
            } else {
                // Tạo sản phẩm mới
                $stmt = $pdo->prepare("
                    INSERT INTO products (warehouse_id, name, brand, type, description, created_at, updated_at) 
                    VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                ");
                $stmt->execute([
                    $warehouse_id, 
                    $item['name'], 
                    $item['brand'], 
                    $item['type'], 
                    $item['description'] ?? ''
                ]);
                $product_id = $pdo->lastInsertId();
                error_log("Created new product: product_id=$product_id");
            }
        }

        // BƯỚC 3: Nếu chưa có variant_id, tìm hoặc tạo variant
        // BƯỚC 3: Nếu chưa có variant_id, tìm hoặc tạo variant
        if (!$variant_id) {
            // Kiểm tra theo product_id + color + size
            $stmt = $pdo->prepare("
                SELECT variant_id 
                FROM product_variants 
                WHERE product_id = ? AND color = ? AND size = ?
                LIMIT 1
            ");
            $stmt->execute([$product_id, $item['color'], $item['size']]);
            $existing_variant_by_attrs = $stmt->fetch();
            
            if ($existing_variant_by_attrs) {
                // Variant đã tồn tại nhưng có thể SKU khác -> update SKU + price
                $variant_id = $existing_variant_by_attrs['variant_id'];
                $stmt = $pdo->prepare("
                    UPDATE product_variants 
                    SET sku = ?, price = ?, updated_at = NOW()
                    WHERE variant_id = ?
                ");
                $stmt->execute([
                    $item['sku'],
                    $item['salePrice'] ?? 0, // price = giá bán
                    $variant_id
                ]);
                error_log("Updated variant with SKU: variant_id=$variant_id, sku={$item['sku']}");
            } else {
                // Tạo variant mới hoàn toàn
                $stmt = $pdo->prepare("
                    INSERT INTO product_variants (product_id, sku, color, size, price, warehouse_id, created_at, updated_at) 
                    VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
                ");
                $stmt->execute([
                    $product_id, 
                    $item['sku'], 
                    $item['color'], 
                    $item['size'], 
                    $item['salePrice'] ?? 0, // price = giá bán
                    $userWarehouseId
                ]);
                $variant_id = $pdo->lastInsertId();
                error_log("Created new variant: SKU={$item['sku']}, variant_id=$variant_id, product_id=$product_id");
            }
        }

        // 3. Tạo stock receipt item
        $stmt = $pdo->prepare("
            INSERT INTO stock_receipt_items (receipt_id, variant_id, quantity, unit_price, location_code, location_id, warehouse_id, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $receipt_id,
            $variant_id,
            $item['quantity'],
            $item['unitPrice'],
            $item['storageLocation'] ?? '',
            $location_id,
            $warehouse_id
        ]);

        // 4. Cập nhật inventory nếu status là confirmed (đã hoàn thành)
        if ($status === 'confirmed') {
            // Kiểm tra xem đã có inventory record chưa
            $stmt = $pdo->prepare("
                SELECT inventory_id, quantity 
                FROM inventory 
                WHERE warehouse_id = ? AND variant_id = ?
            ");
            $stmt->execute([$warehouse_id, $variant_id]);
            $inventory = $stmt->fetch();

            if ($inventory) {
                // Cập nhật số lượng và location_id
                $new_quantity = $inventory['quantity'] + $item['quantity'];
                $stmt = $pdo->prepare("
                    UPDATE inventory 
                    SET quantity = ?, location_id = ?, updated_at = NOW() 
                    WHERE inventory_id = ?
                ");
                $stmt->execute([$new_quantity, $location_id, $inventory['inventory_id']]);
                error_log("API: Updated inventory for variant_id=$variant_id, old_qty={$inventory['quantity']}, added={$item['quantity']}, new_qty=$new_quantity, location_id=$location_id");
            } else {
                // Tạo mới inventory record
                $stmt = $pdo->prepare("
                    INSERT INTO inventory (warehouse_id, variant_id, quantity, location_id, updated_at) 
                    VALUES (?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $warehouse_id, 
                    $variant_id, 
                    $item['quantity'],
                    $location_id
                ]);
                error_log("API: Created new inventory for variant_id=$variant_id, qty={$item['quantity']}, location_id=$location_id");
            }
        } else {
            error_log("API: Skipped inventory update for status='$status' (only updates when status='confirmed')");
        }

        // 5. Tạo QR code cho variant
        try {
            // Lấy product_id from variant
            $stmt = $pdo->prepare("SELECT product_id FROM product_variants WHERE variant_id = ?");
            $stmt->execute([$variant_id]);
            $variant_data = $stmt->fetch();
            
            if ($variant_data && $variant_data['product_id']) {
                $product_id = $variant_data['product_id'];
                
                $qrManager = new QRCodeManager($pdo);
                
                // Kiểm tra if QR already exists
                $existing_qr = $qrManager->getQRCode($product_id, $variant_id);
                
                if (!$existing_qr) {
                    // Tạo new QR code
                    error_log("Generating QR code for variant_id: $variant_id, product_id: $product_id");
                    
                    $qr_result = $qrManager->generateQRCode($product_id, $variant_id, $user_id);
                    
                    if ($qr_result) {
                        // Cập nhật location_code và supplier_id cho QR vừa tạo
                        $updateQRStmt = $pdo->prepare("UPDATE product_qr_codes SET location_code = ?, supplier_id = ? WHERE qr_id = ?");
                        $updateQRStmt->execute([
                            $item['location_code'] ?? null,
                            $supplier_id,
                            $qr_result['qr_id']
                        ]);
                        error_log("QR code generated successfully for variant_id: $variant_id with location: {$item['location_code']}");
                    } else {
                        error_log("Failed to generate QR code for variant_id: $variant_id");
                    }
                } else {
                    error_log("QR code already exists for variant_id: $variant_id");
                }
            } else {
                error_log("Could not find product_id for variant_id: $variant_id");
            }
            
        } catch (Exception $e) {
            // QR code generation error - log but don't fail the transaction
            error_log("QR Code generation error for variant {$variant_id}: " . $e->getMessage());
        }
    }

    // 6. Log hoạt động
    if (class_exists('AuditLogger')) {
        try {
            $auditLogger = new AuditLogger($pdo);
            $auditLogger->log(
                $user_id,
                'stock_receipt_created',
                'stock_receipts',
                $receipt_id,
                null,
                [
                    'receipt_id' => $receipt_id,
                    'supplier_id' => $supplier_id,
                    'status' => $status,
                    'manual_entry' => $manual_entry,
                    'total_items' => count($items)
                ]
            );
        } catch (Exception $e) {
            error_log("Audit logging error: " . $e->getMessage());
            // Don't fail the whole operation if audit logging fails
        }
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Stock receipt saved successfully',
        'receipt_id' => $receipt_id,
        'status' => $status
    ]);

} catch (Exception $e) {
    $pdo->rollback();
    error_log("Error saving manual stock receipt: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>