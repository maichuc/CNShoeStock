<?php
class ImageUploadService {
    
    public static function uploadToPostImages($imagePath) {
        // PostImages.org - free image hosting
        $data = [
            'upload' => new CURLFile($imagePath),
            'optsize' => '0',
            'expire' => '0'
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://postimages.org/json/rr',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            throw new Exception('Curl error: ' . $curlError);
        }
        
        if ($httpCode !== 200) {
            throw new Exception('PostImages API error: ' . $httpCode . ' - ' . $response);
        }
        
        $result = json_decode($response, true);
        if (!$result || $result['status'] !== 'OK') {
            throw new Exception('PostImages upload failed: ' . $response);
        }
        
        return $result['url'];
    }
    
    public static function uploadToFreeImage($imagePath) {
        // freeimage.host - another free service
        $data = [
            'source' => base64_encode(file_get_contents($imagePath)),
            'type' => 'file',
            'action' => 'upload'
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://freeimage.host/api/1/upload',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded'
            ]
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            $result = json_decode($response, true);
            if ($result && $result['status_code'] === 200) {
                return $result['image']['url'];
            }
        }
        
        throw new Exception('FreeImage upload failed: ' . $response);
    }
    
    public static function uploadToImgBB($imagePath) {
        // ImgBB - free with API key
        $apiKey = '7d8b2b4d8a4b2d4c8b2d4c8b2d4c8b2d'; // Demo key
        
        $data = [
            'image' => base64_encode(file_get_contents($imagePath))
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://api.imgbb.com/1/upload?key=' . $apiKey,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            $result = json_decode($response, true);
            if ($result && $result['success']) {
                return $result['data']['url'];
            }
        }
        
        throw new Exception('ImgBB upload failed: ' . $response);
    }
    
    public static function uploadToMultipleServices($imagePath) {
        // Try multiple services in order
        $services = [
            'uploadToFreeImage',
            'uploadToImgBB',
            'uploadToPostImages'
        ];
        
        $lastError = '';
        foreach ($services as $service) {
            try {
                return self::$service($imagePath);
            } catch (Exception $e) {
                $lastError = $e->getMessage();
                continue;
            }
        }
        
        throw new Exception('All upload services failed. Last error: ' . $lastError);
    }
    
    public static function uploadToImgur($imagePath) {
        $clientId = 'a35f1e86d67dc01'; // Imgur client ID công khai
        
        // Read image file
        $imageData = file_get_contents($imagePath);
        if (!$imageData) {
            throw new Exception('Could not read image file');
        }
        
        // Chuẩn bị data for Imgur API
        $data = [
            'image' => base64_encode($imageData),
            'type' => 'base64'
        ];
        
        // Gửi to Imgur
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://api.imgur.com/3/image',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_HTTPHEADER => [
                'Authorization: Client-ID ' . $clientId
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            throw new Exception('Curl error: ' . $curlError);
        }
        
        if ($httpCode !== 200) {
            throw new Exception('Imgur API error: ' . $httpCode . ' - ' . $response);
        }
        
        $result = json_decode($response, true);
        if (!$result || !$result['success']) {
            throw new Exception('Imgur upload failed: ' . $response);
        }
        
        return $result['data']['link'];
    }
    
    public static function uploadToCloudinary($imagePath) {
        // Cloudinary alternative (requires API key)
        $cloudName = 'demo'; // Free demo cloud
        $uploadPreset = 'ml_default'; // Default preset
        
        $data = [
            'file' => new CURLFile($imagePath),
            'upload_preset' => $uploadPreset
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => "https://api.cloudinary.com/v1_1/{$cloudName}/image/upload",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            $result = json_decode($response, true);
            return $result['secure_url'] ?? null;
        }
        
        return null;
    }
    
    /**
     * Upload local file to temp directory
     */
    public static function upload($file, $folder = 'temp') {
        try {
            // Kiểm tra file
            if (!isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
                return [
                    'success' => false,
                    'message' => 'File upload error: ' . ($file['error'] ?? 'Unknown error')
                ];
            }
            
            // Kiểm tra file type
            $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
            $fileType = mime_content_type($file['tmp_name']);
            
            if (!in_array($fileType, $allowedTypes)) {
                return [
                    'success' => false,
                    'message' => 'Invalid file type. Only images allowed.'
                ];
            }
            
            // Kiểm tra file size (max 10MB)
            if ($file['size'] > 10 * 1024 * 1024) {
                return [
                    'success' => false,
                    'message' => 'File too large. Max 10MB.'
                ];
            }
            
            // Tạo upload directory
            $uploadDir = __DIR__ . "/../uploads/{$folder}/";
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            // Tạo unique filename
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = uniqid('img_') . '_' . time() . '.' . $extension;
            $targetPath = $uploadDir . $filename;
            
            // Di chuyển uploaded file
            if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
                return [
                    'success' => false,
                    'message' => 'Failed to move uploaded file'
                ];
            }
            
            return [
                'success' => true,
                'path' => $targetPath,
                'filename' => $filename,
                'url' => "/multi_warehouse/uploads/{$folder}/{$filename}",
                'size' => filesize($targetPath)
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
}
