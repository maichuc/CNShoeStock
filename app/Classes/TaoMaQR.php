<?php
/**
 * QR Code Generator Utility
 * Tạo QR code images từ UUID strings
 */

class QRCodeGenerator {
    
    /**
     * TẠO URL QR CODE SỬ DỤNG GOOGLE CHARTS API
     * 
     * Mục đích: Tạo URL trỏ đến QR code image từ Google Charts API
     * Sử dụng: Khi cần QR code nhanh không cần lưu file local
     * 
     * @param string $data - Dữ liệu cần encode vào QR (UUID, JSON, text...)
     * @param int $size - Kích thước QR code (px), mặc định 200x200
     * @return string - URL đầy đủ của QR code image
     * 
     * Ví dụ:
     * generateQRUrl("ABC123", 300) 
     * => "https://chart.googleapis.com/chart?chs=300x300&cht=qr&chl=ABC123"
     */
    public static function generateQRUrl($data, $size = 200) {
        // Encode dữ liệu để an toàn cho URL (xử lý ký tự đặc biệt)
        $encodedData = urlencode($data);
        
        // Tạo URL Google Charts với format: chs=size, cht=qr (QR type), chl=data
        return "https://chart.googleapis.com/chart?chs={$size}x{$size}&cht=qr&chl={$encodedData}";
    }
    
    /**
     * TẠO QR CODE NÂNG CAO VỚI THÔNG TIN SẢN PHẨM ĐẦY ĐỦ
     * 
     * Mục đích: Tạo QR chứa đầy đủ thông tin sản phẩm dạng JSON
     * Quy trình:
     * 1. Tạo UUID duy nhất cho QR
     * 2. Đóng gói thông tin sản phẩm thành JSON
     * 3. Lưu QR thành file local (ưu tiên)
     * 4. Fallback sang Google Charts nếu lưu local thất bại
     * 
     * @param array $productData - Mảng chứa thông tin sản phẩm:
     *   - variant_id: ID của variant sản phẩm
     *   - sku: Mã SKU
     *   - ... (các trường khác)
     * @return array - Mảng chứa:
     *   - qr_data: Chuỗi JSON chứa dữ liệu QR
     *   - qr_url: Đường dẫn đến file QR (local hoặc URL)
     *   - uuid: UUID duy nhất của QR
     */
    public static function generateEnhancedProductQR($productData) {
        // Bước 1: Tạo UUID duy nhất để định danh QR code này
        $uuid = self::generateUUID();
        
        // Bước 2: Chuẩn bị dữ liệu QR (chỉ lưu thông tin cần thiết để tránh QR quá phức tạp)
        $qrData = [
            'type' => 'product',                              // Loại QR: product
            'variant_id' => $productData['variant_id'] ?? '', // ID variant để tra cứu
            'sku' => $productData['sku'] ?? '',               // Mã SKU để nhận diện
            'uuid' => $uuid                                   // UUID duy nhất
        ];
        
        // Bước 3: Chuyển mảng thành chuỗi JSON (giữ nguyên tiếng Việt không escape)
        $qrDataString = json_encode($qrData, JSON_UNESCAPED_UNICODE);
        
        // Bước 4: Lưu QR thành file local (ưu tiên vì nhanh và không phụ thuộc internet)
        $localPath = self::saveQRToLocal($qrDataString, $uuid);
        
        // Bước 5: Trả về kết quả
        return [
            'qr_data' => $qrDataString,                       // Dữ liệu JSON gốc
            'qr_url' => $localPath ?: self::generateQRUrl($qrDataString, 150), // Đường dẫn file hoặc fallback URL
            'uuid' => $uuid                                   // UUID để tham chiếu
        ];
    }
    
    /**
     * LƯU QR CODE THÀNH FILE LOCAL
     * 
     * Mục đích: Tạo và lưu file QR code vào server (không phụ thuộc API bên ngoài)
     * Phương pháp:
     * 1. Ưu tiên: Dùng phpqrcode library (local, nhanh, chất lượng cao)
     * 2. Fallback 1: api.qrserver.com (nếu GD extension không có)
     * 3. Fallback 2: Google Charts API
     * 
     * @param string $qrData - Dữ liệu cần encode vào QR (JSON string)
     * @param string $uuid - UUID để đặt tên file (đảm bảo unique)
     * @return string|false - Đường dẫn tương đối đến file (uploads/qr/xxx.png) hoặc false nếu thất bại
     */
    public static function saveQRToLocal($qrData, $uuid) {
        try {
            /**
             * BƯỚC 1: CHUẨN BỊ THỦ MỤC LƯU TRỮ
             * 
             * Tạo thư mục uploads/qr/ nếu chưa tồn tại
             * Quyền 0755: owner có full quyền, group và other chỉ đọc/execute
             */
            $uploadDir = __DIR__ . '/../uploads/qr/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true); // true = tạo cả thư mục cha nếu cần
            }
            
            /**
             * BƯỚC 2: TẠO TÊN FILE VÀ ĐƯỜNG DẪN
             * 
             * Format: qr_{uuid}.png
             * Ví dụ: qr_1217f6f0-db9e-4191-a97d-526ddc6cc857.png
             */
            $filename = "qr_" . $uuid . ".png";
            $filePath = $uploadDir . $filename;
            
            /**
             * PHƯƠNG PHÁP 1: DÙNG PHPQRCODE LIBRARY (ƯU TIÊN CAO NHẤT)
             * 
             * Ưu điểm:
             * - Không cần internet
             * - Nhanh (tạo local)
             * - Chất lượng cao, tùy chỉnh được
             * - Không giới hạn số lượng request
             * 
             * Yêu cầu: PHP GD extension phải được bật
             */
            if (extension_loaded('gd')) {
                try {
                    // Load thư viện phpqrcode
                    require_once __DIR__ . '/phpqrcode/qrlib.php';
                    
                    /**
                     * TẠO QR CODE VỚI CẤU HÌNH TỐI ƯU CHO SCANNER
                     * 
                     * Tham số QRcode::png():
                     * - $text: Dữ liệu cần encode (JSON string)
                     * - $file: Đường dẫn lưu file
                     * - $ecc: Error Correction Level (L/M/Q/H)
                     *   + L: 7% - thấp nhất, QR nhỏ nhất, dễ scan với mobile
                     *   + M: 15% - trung bình (ĐÃ CHỌN - cân bằng tốt)
                     *   + Q: 25% - cao
                     *   + H: 30% - cao nhất nhưng QR phức tạp, khó scan
                     * - $size: Kích thước mỗi module (3-10)
                     *   + 3: rất nhỏ
                     *   + 4: nhỏ (~200px) 
                     *   + 6: vừa (~300px) - TỐI ƯU cho mobile scanner (ĐÃ CHỌN)
                     *   + 10: rất lớn (~500px) - khó đọc với một số thiết bị
                     * - $margin: Viền trắng xung quanh (4 = chuẩn, dễ detect)
                     * 
                     * Lý do thay đổi từ (H, 10, 2) sang (M, 6, 4):
                     * - ECC M thay vì H: Giảm độ phức tạp, dễ đọc hơn
                     * - Size 6 thay vì 10: Kích thước vừa phải, tương thích tốt
                     * - Margin 4 thay vì 2: Viền rộng hơn giúp scanner detect boundary
                     */
                    QRcode::png($qrData, $filePath, QR_ECLEVEL_M, 6, 4);
                    
                    /**
                     * XÁC MINH FILE ĐÃ TẠO THÀNH CÔNG
                     * 
                     * Kiểm tra:
                     * 1. File tồn tại
                     * 2. Kích thước > 300 bytes (file nhỏ hơn vì đã giảm size)
                     * 3. File có thể đọc được
                     */
                    if (file_exists($filePath) && filesize($filePath) > 300 && is_readable($filePath)) {
                        $fileSize = filesize($filePath);
                        error_log("✅ QR code created using phpqrcode: $filePath (Size: {$fileSize} bytes)");
                        return "uploads/qr/" . $filename; // Trả về đường dẫn tương đối
                    } else {
                        $errorMsg = "QR file validation failed:";
                        if (!file_exists($filePath)) $errorMsg .= " File not exists.";
                        if (file_exists($filePath) && filesize($filePath) <= 300) $errorMsg .= " File too small (" . filesize($filePath) . " bytes).";
                        if (file_exists($filePath) && !is_readable($filePath)) $errorMsg .= " File not readable.";
                        error_log("❌ " . $errorMsg);
                    }
                } catch (Exception $e) {
                    // phpqrcode thất bại -> ghi log và fallback sang API external
                    error_log("phpqrcode failed: " . $e->getMessage() . ", falling back to external API");
                }
            } else {
                // GD extension không được bật -> không thể dùng phpqrcode
                error_log("GD extension not loaded, using external API for QR generation");
            }
            
            /**
             * PHƯƠNG PHÁP 2: FALLBACK SANG API BÊN NGOÀI
             * 
             * Sử dụng khi:
             * - phpqrcode thất bại
             * - GD extension không có
             * - File tạo từ phpqrcode bị lỗi
             * 
             * Danh sách API (thử lần lượt cho đến khi thành công):
             * 1. api.qrserver.com - Miễn phí, ổn định
             * 2. Google Charts API - Backup
             */
            $qrServices = [
                "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" . urlencode($qrData),
                "https://chart.googleapis.com/chart?chs=300x300&cht=qr&chl=" . urlencode($qrData)
            ];
            
            // Thử từng API cho đến khi có một API thành công
            foreach ($qrServices as $qrUrl) {
                try {
                    /**
                     * DÙNG cURL ĐỂ TẢI QR IMAGE TỪ API
                     * 
                     * Tại sao dùng cURL thay vì file_get_contents()?
                     * - Kiểm soát lỗi tốt hơn (timeout, HTTP code, SSL...)
                     * - Có thể xử lý redirect
                     * - Set custom headers (user agent...)
                     */
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $qrUrl);                    // URL API
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);           // Trả về response thay vì echo
                    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);           // Follow redirect nếu có
                    curl_setopt($ch, CURLOPT_TIMEOUT, 15);                    // Timeout 15 giây
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);          // Bỏ qua verify SSL (cho local dev)
                    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'); // Giả làm browser
                    
                    // Thực thi request
                    $imageData = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);       // Lấy HTTP status code
                    $curlError = curl_error($ch);                             // Lấy error message nếu có
                    curl_close($ch);
                    
                    /**
                     * KIỂM TRA VÀ LƯU KẾT QUẢ
                     * 
                     * Điều kiện thành công:
                     * 1. $imageData không false (có dữ liệu)
                     * 2. HTTP code = 200 (OK)
                     * 3. Kích thước > 100 bytes (không rỗng)
                     */
                    if ($imageData !== false && $httpCode == 200 && strlen($imageData) > 100) {
                        // Lưu image data vào file
                        if (file_put_contents($filePath, $imageData) !== false) {
                            error_log("QR code created using external API: $filePath (service: $qrUrl)");
                            return "uploads/qr/" . $filename;
                        }
                    } else {
                        // API này thất bại -> ghi log và thử API tiếp theo
                        error_log("External QR API failed: HTTP $httpCode, Error: $curlError");
                    }
                } catch (Exception $e) {
                    // Lỗi exception -> ghi log và thử API tiếp theo
                    error_log("External QR service error: " . $e->getMessage());
                    continue; // Bỏ qua API này, thử API tiếp theo
                }
            }
            
            /**
             * TẤT CẢ PHƯƠNG PHÁP ĐỀU THẤT BẠI
             * 
             * Nếu đến đây nghĩa là:
             * - phpqrcode thất bại hoặc không khả dụng
             * - Tất cả external API đều thất bại
             * 
             * Ghi log và trả về false để caller xử lý
             */
            error_log("All QR generation methods failed for UUID: $uuid");
            return false;
            
        } catch (Exception $e) {
            /**
             * XỬ LÝ LỖI EXCEPTION CHUNG
             * 
             * Ghi log chi tiết bao gồm:
             * - Error message
             * - Stack trace để debug
             */
            error_log("Save QR to local error: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            return false;
        }
    }

    /**
     * TẠO QR CODE CHO SẢN PHẨM (HÀM CŨ - BACKWARD COMPATIBILITY)
     * 
     * Mục đích: Giữ tương thích với code cũ đã dùng hàm này
     * Khác với generateEnhancedProductQR():
     * - Chứa nhiều thông tin hơn (receipt_id, item_id, timestamp...)
     * - Chỉ trả về URL, không lưu file local
     * - Dùng Google Charts API trực tiếp
     * 
     * @param array $item - Thông tin item từ stock receipt
     * @return string - URL của QR code từ Google Charts
     */
    public static function generateProductQR($item) {
        /**
         * ĐÓNG GÓI THÔNG TIN ITEM THÀNH JSON
         * 
         * Bao gồm:
         * - type: Loại QR (product)
         * - receipt_id: ID phiếu nhập
         * - item_id: ID item trong phiếu
         * - variant_id: ID variant sản phẩm
         * - product_name: Tên sản phẩm
         * - sku: Mã SKU
         * - location: Vị trí lưu kho
         * - timestamp: Thời gian tạo QR
         */
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
        
        // Tạo và trả về URL QR code kích thước 150x150
        return self::generateQRUrl($qrData, 150);
    }
    
    /**
     * TẠO UUID THEO CHUẨN RFC 4122 VERSION 4
     * 
     * UUID format: xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
     * - x: Số hex ngẫu nhiên (0-f)
     * - 4: Version 4 (UUID random)
     * - y: Variant bit (8, 9, a, hoặc b)
     * 
     * Ví dụ: 1217f6f0-db9e-4191-a97d-526ddc6cc857
     * 
     * @return string - UUID duy nhất
     */
    public static function generateUUID() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            // 8 hex đầu (32 bits)
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            // 4 hex tiếp (16 bits)
            mt_rand(0, 0xffff),
            // 4 hex với version 4 (0x4000 | random)
            mt_rand(0, 0x0fff) | 0x4000,
            // 4 hex với variant (0x8000 | random)
            mt_rand(0, 0x3fff) | 0x8000,
            // 12 hex cuối (48 bits)
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
    
    /**
     * TẠO QR CODE ĐƠN GIẢN TỪ TEXT
     * 
     * Mục đích: Tạo QR nhanh cho text đơn giản (không cần JSON phức tạp)
     * Sử dụng: Tạo QR cho URL, số điện thoại, text ngắn...
     * 
     * @param string $text - Văn bản cần tạo QR
     * @return string - URL của QR code 200x200px
     */
    public static function generateSimpleQR($text) {
        return self::generateQRUrl($text, 200);
    }
    
    /**
     * TẠO HOẶC CẬP NHẬT QR CODE CHO VARIANT SẢN PHẨM
     * 
     * Logic hoạt động:
     * 1. Mỗi variant (product + size) có 1 QR code duy nhất
     * 2. Khi nhập thêm hàng cùng variant:
     *    - Không tạo QR mới
     *    - Cập nhật quantity trong qr_data (JSON)
     *    - Giữ nguyên QR image (không tạo lại file)
     * 3. Khi variant chưa có QR -> tạo mới hoàn toàn
     * 
     * Ưu điểm:
     * - Tránh duplicate QR cho cùng 1 variant
     * - QR image stable (không đổi URL khi nhập hàng)
     * - Quantity luôn được cập nhật trong metadata
     * 
     * @param PDO $pdo - Database connection
     * @param array $productData - Thông tin sản phẩm:
     *   - product_id: ID sản phẩm chính
     *   - variant_id: ID variant (product + size)
     *   - sku: Mã SKU
     *   - quantity: Số lượng nhập thêm
     *   - supplier_name, unit_price: Thông tin bổ sung
     *   - created_by, warehouse_id, location_code, supplier_id: Metadata
     * @return array - Thông tin QR code:
     *   - qr_id: ID trong bảng product_qr_codes
     *   - qr_data: JSON string chứa data
     *   - qr_url: Đường dẫn đến file QR
     *   - uuid: UUID duy nhất
     *   - is_new: true nếu QR mới tạo, false nếu update
     */
    public static function createOrUpdateProductQR($pdo, $productData) {
        try {
            /**
             * BƯỚC 1: KIỂM TRA QR CODE ĐÃ TỒN TẠI CHƯA
             * 
             * Query:
             * - Tìm QR code active của variant này
             * - Tính tổng quantity hiện có trong inventory
             * - COALESCE: trả về 0 nếu không có inventory record
             */
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
            
            /**
             * BƯỚC 2A: CẬP NHẬT QR CODE ĐÃ TỒN TẠI
             * 
             * Kịch bản: Variant đã có QR code rồi (nhập hàng lần 2, 3...)
             * Xử lý:
             * - Giải mã JSON hiện tại
             * - Cập nhật quantity mới = quantity cũ + quantity nhập thêm
             * - Cập nhật metadata (supplier, price, timestamp)
             * - Lưu lại JSON vào database
             * - GIỮ NGUYÊN qr_image_path (không tạo lại file QR)
             */
            if ($existingQR) {
                // Parse JSON data từ QR code cũ
                $qrData = json_decode($existingQR['qr_code'], true);
                if ($qrData) {
                    /**
                     * CẬP NHẬT CÁC TRƯỜNG TRONG JSON
                     * 
                     * - current_quantity: Tổng quantity hiện tại + quantity mới nhập
                     * - last_updated: Timestamp cập nhật gần nhất
                     * - supplier_name: Tên nhà cung cấp (ưu tiên mới, fallback cũ)
                     * - unit_price: Giá nhập (ưu tiên mới, fallback cũ)
                     */
                    $qrData['current_quantity'] = $existingQR['current_total_quantity'] + ($productData['quantity'] ?? 0);
                    $qrData['last_updated'] = date('Y-m-d H:i:s');
                    $qrData['supplier_name'] = $productData['supplier_name'] ?? $qrData['supplier_name'] ?? '';
                    $qrData['unit_price'] = $productData['unit_price'] ?? $qrData['unit_price'] ?? 0;
                    
                    // Encode lại thành JSON (giữ nguyên tiếng Việt)
                    $updatedQRData = json_encode($qrData, JSON_UNESCAPED_UNICODE);
                    
                    // Lưu JSON mới vào database (chỉ update qr_code field, không động qr_image_path)
                    $stmt = $pdo->prepare("UPDATE product_qr_codes SET qr_code = ? WHERE qr_id = ?");
                    $stmt->execute([$updatedQRData, $existingQR['qr_id']]);
                    
                    /**
                     * TRẢ VỀ THÔNG TIN QR CODE (KHÔNG THAY ĐỔI)
                     * 
                     * - qr_id: ID cũ (không đổi)
                     * - qr_url: Đường dẫn file cũ (không tạo lại)
                     * - uuid: UUID cũ (không đổi)
                     * - is_new: false - đây là QR đã tồn tại
                     */
                    return [
                        'qr_id' => $existingQR['qr_id'],
                        'qr_data' => $updatedQRData,
                        'qr_url' => $existingQR['qr_image_path'] ?: self::generateQRUrl($updatedQRData, 150),
                        'uuid' => $qrData['uuid'],
                        'is_new' => false  // Không phải QR mới
                    ];
                }
            }
            
            /**
             * BƯỚC 2B: TẠO QR CODE MỚI
             * 
             * Kịch bản: Variant chưa có QR code (nhập hàng lần đầu)
             * Xử lý:
             * - Tạo QR code hoàn toàn mới (UUID, JSON, file image)
             * - Lưu vào database với đầy đủ metadata
             * - Trả về thông tin QR để caller sử dụng
             */
            
            // Tạo QR code với thông tin đầy đủ (JSON + file image)
            $enhancedData = self::generateEnhancedProductQR($productData);
            
            /**
             * LƯU THÔNG TIN QR VÀO DATABASE
             * 
             * Bảng: product_qr_codes
             * Các trường:
             * - product_id: ID sản phẩm chính
             * - variant_id: ID variant (product + size) - UNIQUE KEY
             * - qr_code: JSON string chứa data (type, variant_id, sku, uuid)
             * - qr_image_path: Đường dẫn đến file QR (uploads/qr/xxx.png)
             * - created_by: ID user tạo QR
             * - warehouse_id: ID kho lưu trữ
             * - location_code: Mã vị trí trong kho (A1, B2...)
             * - supplier_id: ID nhà cung cấp
             * - created_at: Timestamp tạo
             */
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
                $productData['created_by'] ?? 1,           // Default: user ID 1
                $productData['warehouse_id'] ?? 1,         // Default: warehouse ID 1
                $productData['location_code'] ?? null,     // Optional
                $productData['supplier_id'] ?? null        // Optional
            ]);
            
            // Lấy ID của record vừa insert
            $qrId = $pdo->lastInsertId();
            
            /**
             * TRẢ VỀ THÔNG TIN QR CODE MỚI
             * 
             * - qr_id: ID vừa tạo trong database
             * - qr_data: JSON string
             * - qr_url: Đường dẫn file QR
             * - uuid: UUID duy nhất của QR này
             * - is_new: true - đây là QR mới tạo
             */
            return [
                'qr_id' => $qrId,
                'qr_data' => $enhancedData['qr_data'],
                'qr_url' => $enhancedData['qr_url'],
                'uuid' => $enhancedData['uuid'],
                'is_new' => true  // QR mới được tạo
            ];
            
        } catch (Exception $e) {
            /**
             * XỬ LÝ LỖI
             * 
             * Ghi log error và throw exception để caller xử lý
             * Caller có thể rollback transaction nếu cần
             */
            error_log("QR Code generation error: " . $e->getMessage());
            throw $e;  // Ném lại exception cho caller
        }
    }
}
?>
