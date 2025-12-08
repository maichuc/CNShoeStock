<?php
class SimpleQRGenerator {
    
    /**
     * Generate QR code using online service as fallback
     */
    public static function generateQRCodeFallback($data, $filePath, $size = 200) {
        try {
            // Tạo directory if not exists
            $dir = dirname($filePath);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            
            // Use QR Server API
            $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size={$size}x{$size}&data=" . urlencode($data);
            
            // Tải xuống QR code
            $context = stream_context_create([
                'http' => [
                    'timeout' => 10,
                    'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
                ]
            ]);
            
            $qrContent = file_get_contents($qrUrl, false, $context);
            
            if ($qrContent !== false) {
                file_put_contents($filePath, $qrContent);
                return file_exists($filePath);
            }
            
            return false;
            
        } catch (Exception $e) {
            error_log("QR Generation Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Generate QR code with local fallback
     */
    public static function generateQRCode($data, $filePath, $size = 200) {
        // Try online service first
        if (self::generateQRCodeFallback($data, $filePath, $size)) {
            return true;
        }
        
        // If online fails, create a simple text file as backup
        try {
            $dir = dirname($filePath);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            
            // Tạo a simple text representation
            $textQR = "QR Code Data: " . $data . "\n";
            $textQR .= "Generated: " . date('Y-m-d H:i:s') . "\n";
            $textQR .= "Note: Install PHP GD extension for proper QR images\n";
            
            $textPath = str_replace('.png', '.txt', $filePath);
            file_put_contents($textPath, $textQR);
            
            return true;
            
        } catch (Exception $e) {
            error_log("QR Fallback Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Generate UUID for QR codes
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
}
?>