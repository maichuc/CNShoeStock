<?php
/**
 * QR Code Generator Utility
 * Tạo QR code images từ UUID strings
 */

class QRCodeGenerator {
    
    /**
     * Tạo QR code URL sử dụng Google Charts API
     * @param string $data Dữ liệu cần encode (UUID, text, etc.)
     * @param int $size Kích thước QR code (mặc định 200x200)
     * @return string URL của QR code image
     */
    public static function generateQRUrl($data, $size = 200) {
        $encodedData = urlencode($data);
        return "https://chart.googleapis.com/chart?chs={$size}x{$size}&cht=qr&chl={$encodedData}";
    }
    
    /**
     * Tạo QR code với thông tin sản phẩm đầy đủ
     * @param array $productData Thông tin sản phẩm đầy đủ
     * @return array ['qr_data' => string, 'qr_url' => string]
     */
    public static function generateEnhancedProductQR($productData) {
        // Tạo dữ liệu QR đơn giản để tránh 404 error
        $uuid = self::generateUUID();
        $qrData = [
            'type' => 'product',
            'variant_id' => $productData['variant_id'] ?? '',
            'sku' => $productData['sku'] ?? '',
            'uuid' => $uuid
        ];
        
        $qrDataString = json_encode($qrData, JSON_UNESCAPED_UNICODE);
        
        // Tạo QR file local thay vì Google Charts URL
        $localPath = self::saveQRToLocal($qrDataString, $uuid);
        
        return [
            'qr_data' => $qrDataString,
            'qr_url' => $localPath ?: self::generateQRUrl($qrDataString, 150), // fallback to Google Charts
            'uuid' => $uuid
        ];
    }
    
    /**
     * Save QR code to local file using phpqrcode library or fallback to external API
     * @param string $qrData QR data to encode
     * @param string $uuid UUID for filename
     * @return string|false Local file path or false on failure
     */
    public static function saveQRToLocal($qrData, $uuid) {
        try {
            // Create uploads/qr directory if not exists
            $uploadDir = __DIR__ . '/../uploads/qr/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            // Generate filename
            $filename = "qr_" . $uuid . ".png";
            $filePath = $uploadDir . $filename;
            
            // Method 1: Try using phpqrcode library (requires GD extension)
            if (extension_loaded('gd')) {
                try {
                    require_once __DIR__ . '/phpqrcode/qrlib.php';
                    
                    // Generate QR code using phpqrcode library
                    // QRcode::png($text, $file, $ecc, $size, $margin, $saveandprint)
                    QRcode::png($qrData, $filePath, QR_ECLEVEL_L, 4, 2);
                    
                    // Verify file was created and has content
                    if (file_exists($filePath) && filesize($filePath) > 100) {
                        error_log("QR code created using phpqrcode: $filePath");
                        return "uploads/qr/" . $filename;
                    }
                } catch (Exception $e) {
                    error_log("phpqrcode failed: " . $e->getMessage() . ", falling back to external API");
                }
            } else {
                error_log("GD extension not loaded, using external API for QR generation");
            }
            
            // Method 2: Fallback to external API if phpqrcode fails or GD not available
            $qrServices = [
                "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" . urlencode($qrData),
                "https://chart.googleapis.com/chart?chs=300x300&cht=qr&chl=" . urlencode($qrData)
            ];
            
            foreach ($qrServices as $qrUrl) {
                try {
                    // Use cURL for better error handling
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $qrUrl);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
                    
                    $imageData = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $curlError = curl_error($ch);
                    curl_close($ch);
                    
                    if ($imageData !== false && $httpCode == 200 && strlen($imageData) > 100) {
                        // Save to local file
                        if (file_put_contents($filePath, $imageData) !== false) {
                            error_log("QR code created using external API: $filePath (service: $qrUrl)");
                            return "uploads/qr/" . $filename;
                        }
                    } else {
                        error_log("External QR API failed: HTTP $httpCode, Error: $curlError");
                    }
                } catch (Exception $e) {
                    error_log("External QR service error: " . $e->getMessage());
                    continue;
                }
            }
            
            error_log("All QR generation methods failed for UUID: $uuid");
            return false;
            
        } catch (Exception $e) {
            error_log("Save QR to local error: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            return false;
        }
    }

    /**
     * Tạo QR code với thông tin sản phẩm (backward compatibility)
     * @param array $item Thông tin item từ stock receipt
     * @return string URL của QR code
     */
    public static function generateProductQR($item) {
        // Tạo UUID hoặc chuỗi định danh duy nhất
        $qrData = json_encode([
            'type' => 'product',
            'receipt_id' => $item['receipt_id'] ?? '',
            'item_id' => $item['item_id'] ?? '',
            'variant_id' => $item['variant_id'] ?? '',
            'product_name' => $item['product_name'] ?? '',
            'sku' => $item['sku'] ?? '',
            'location' => $item['location_code'] ?? '',
            'timestamp' => time()
        ]);
        
        return self::generateQRUrl($qrData, 150);
    }
    
    /**
     * Tạo UUID đơn giản
     * @return string UUID
     */
    public static function generateUUID() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
    
    /**
     * Tạo QR code đơn giản từ text
     * @param string $text
     * @return string URL
     */
    public static function generateSimpleQR($text) {
        return self::generateQRUrl($text, 200);
    }
    
    /**
     * Tạo hoặc cập nhật QR code cho sản phẩm variant
     * Mỗi size của một sản phẩm sẽ có mã QR duy nhất
     * Quantity sẽ được cộng dồn nhưng QR image giữ nguyên
     * 
     * @param PDO $pdo Database connection
     * @param array $productData Thông tin sản phẩm
     * @return array QR code information
     */
    public static function createOrUpdateProductQR($pdo, $productData) {
        try {
            // Kiểm tra xem variant này đã có QR code chưa
            $stmt = $pdo->prepare("
                SELECT pqr.*, 
                       COALESCE(SUM(i.quantity), 0) as current_total_quantity
                FROM product_qr_codes pqr
                LEFT JOIN inventory i ON pqr.variant_id = i.variant_id 
                WHERE pqr.variant_id = ? AND pqr.is_active = 1
                GROUP BY pqr.qr_id
                LIMIT 1
            ");
            $stmt->execute([$productData['variant_id']]);
            $existingQR = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existingQR) {
                // QR code đã tồn tại - chỉ cập nhật thông tin quantity trong qr_data
                $qrData = json_decode($existingQR['qr_code'], true);
                if ($qrData) {
                    // Cập nhật quantity và thông tin mới nhất
                    $qrData['current_quantity'] = $existingQR['current_total_quantity'] + ($productData['quantity'] ?? 0);
                    $qrData['last_updated'] = date('Y-m-d H:i:s');
                    $qrData['supplier_name'] = $productData['supplier_name'] ?? $qrData['supplier_name'] ?? '';
                    $qrData['unit_price'] = $productData['unit_price'] ?? $qrData['unit_price'] ?? 0;
                    
                    $updatedQRData = json_encode($qrData, JSON_UNESCAPED_UNICODE);
                    
                    // Cập nhật database
                    $stmt = $pdo->prepare("UPDATE product_qr_codes SET qr_code = ? WHERE qr_id = ?");
                    $stmt->execute([$updatedQRData, $existingQR['qr_id']]);
                    
                    return [
                        'qr_id' => $existingQR['qr_id'],
                        'qr_data' => $updatedQRData,
                        'qr_url' => $existingQR['qr_image_path'] ?: self::generateQRUrl($updatedQRData, 150),
                        'uuid' => $qrData['uuid'],
                        'is_new' => false
                    ];
                }
            }
            
            // Tạo QR code mới
            $enhancedData = self::generateEnhancedProductQR($productData);
            
            // Lưu vào database
            $stmt = $pdo->prepare("
                INSERT INTO product_qr_codes 
                (product_id, variant_id, qr_code, qr_image_path, created_by, warehouse_id, location_code, supplier_id, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $productData['product_id'],
                $productData['variant_id'],
                $enhancedData['qr_data'],
                $enhancedData['qr_url'],
                $productData['created_by'] ?? 1,
                $productData['warehouse_id'] ?? 1,
                $productData['location_code'] ?? null,
                $productData['supplier_id'] ?? null
            ]);
            
            $qrId = $pdo->lastInsertId();
            
            return [
                'qr_id' => $qrId,
                'qr_data' => $enhancedData['qr_data'],
                'qr_url' => $enhancedData['qr_url'],
                'uuid' => $enhancedData['uuid'],
                'is_new' => true
            ];
            
        } catch (Exception $e) {
            error_log("QR Code generation error: " . $e->getMessage());
            throw $e;
        }
    }
}
?>
