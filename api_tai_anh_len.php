<?php
header('Content-Type: application/json');
session_start();

try {
    $response = ['success' => false, 'message' => ''];
    
    // Kiểm tra đăng nhập
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Chưa đăng nhập');
    }
    
    // Kiểm tra có files upload không
    if (!isset($_FILES['images']) || empty($_FILES['images']['name'][0])) {
        throw new Exception('Không có file nào được upload');
    }
    
    $uploadedImages = [];
    
    // Tạo thư mục upload nếu chưa có
    $uploadDir = 'uploads/products/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    // Xử lý từng file
    $files = $_FILES['images'];
    $fileCount = count($files['name']);
    
    for ($i = 0; $i < $fileCount; $i++) {
        if ($files['error'][$i] === UPLOAD_ERR_OK) {
            $tmpName = $files['tmp_name'][$i];
            $originalName = $files['name'][$i];
            $fileSize = $files['size'][$i];
            $fileType = $files['type'][$i];
            
            // Kiểm tra file type
            $allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
            if (!in_array($fileType, $allowedTypes)) {
                throw new Exception('File ' . $originalName . ' không phải là ảnh hợp lệ');
            }
            
            // Kiểm tra file size (5MB max)
            if ($fileSize > 5 * 1024 * 1024) {
                throw new Exception('File ' . $originalName . ' quá lớn (tối đa 5MB)');
            }
            
            // Tạo unique filename
            $extension = pathinfo($originalName, PATHINFO_EXTENSION);
            $filename = 'product_' . uniqid() . '.' . $extension;
            $filepath = $uploadDir . $filename;
            
            // Di chuyển uploaded file
            if (move_uploaded_file($tmpName, $filepath)) {
                // Resize and optimize image
                $optimizedPath = optimizeImage($filepath);
                $uploadedImages[] = $optimizedPath ?: $filepath;
            } else {
                throw new Exception('Lỗi lưu file ' . $originalName);
            }
        } else {
            // Detailed error messages
            $errorMessages = [
                UPLOAD_ERR_INI_SIZE => 'File vượt quá giới hạn upload_max_filesize trong php.ini',
                UPLOAD_ERR_FORM_SIZE => 'File vượt quá giới hạn MAX_FILE_SIZE trong form',
                UPLOAD_ERR_PARTIAL => 'File chỉ được upload một phần',
                UPLOAD_ERR_NO_FILE => 'Không có file nào được upload',
                UPLOAD_ERR_NO_TMP_DIR => 'Thiếu thư mục tạm thời',
                UPLOAD_ERR_CANT_WRITE => 'Không thể ghi file vào ổ đĩa',
                UPLOAD_ERR_EXTENSION => 'PHP extension đã chặn upload file'
            ];
            $errorCode = $files['error'][$i];
            $errorMsg = isset($errorMessages[$errorCode]) ? $errorMessages[$errorCode] : 'Lỗi không xác định (code: ' . $errorCode . ')';
            throw new Exception('Lỗi upload file "' . $files['name'][$i] . '": ' . $errorMsg);
        }
    }
    
    if (empty($uploadedImages)) {
        throw new Exception('Không có file nào được upload thành công');
    }
    
    $response['success'] = true;
    $response['image_paths'] = $uploadedImages;
    $response['message'] = 'Upload thành công ' . count($uploadedImages) . ' file';
    
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
}

echo json_encode($response);

function optimizeImage($filepath) {
    try {
        $imageInfo = getimagesize($filepath);
        if (!$imageInfo) {
            return false;
        }
        
        $width = $imageInfo[0];
        $height = $imageInfo[1];
        $type = $imageInfo[2];
        
        // Tạo image resource
        switch ($type) {
            case IMAGETYPE_JPEG:
                $source = imagecreatefromjpeg($filepath);
                break;
            case IMAGETYPE_PNG:
                $source = imagecreatefrompng($filepath);
                break;
            case IMAGETYPE_WEBP:
                $source = imagecreatefromwebp($filepath);
                break;
            case IMAGETYPE_GIF:
                $source = imagecreatefromgif($filepath);
                break;
            default:
                return false;
        }
        
        if (!$source) {
            return false;
        }
        
        // Resize nếu quá lớn
        $maxWidth = 800;
        $maxHeight = 600;
        
        if ($width > $maxWidth || $height > $maxHeight) {
            $ratio = min($maxWidth / $width, $maxHeight / $height);
            $newWidth = round($width * $ratio);
            $newHeight = round($height * $ratio);
            
            $dest = imagecreatetruecolor($newWidth, $newHeight);
            
            // Preserve transparency for PNG and GIF
            if ($type == IMAGETYPE_PNG || $type == IMAGETYPE_GIF) {
                imagealphablending($dest, false);
                imagesavealpha($dest, true);
                $transparent = imagecolorallocatealpha($dest, 255, 255, 255, 127);
                imagefilledrectangle($dest, 0, 0, $newWidth, $newHeight, $transparent);
            }
            
            imagecopyresampled($dest, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            
            // Lưu ảnh đã resize
            switch ($type) {
                case IMAGETYPE_JPEG:
                    imagejpeg($dest, $filepath, 85);
                    break;
                case IMAGETYPE_PNG:
                    imagepng($dest, $filepath, 8);
                    break;
                case IMAGETYPE_WEBP:
                    imagewebp($dest, $filepath, 85);
                    break;
                case IMAGETYPE_GIF:
                    imagegif($dest, $filepath);
                    break;
            }
            
            imagedestroy($dest);
        }
        
        imagedestroy($source);
        
        // Xóa EXIF data (cho JPEG)
        if ($type == IMAGETYPE_JPEG) {
            removeExifData($filepath);
        }
        
        return $filepath;
        
    } catch (Exception $e) {
        return false;
    }
}

function removeExifData($filepath) {
    try {
        if (function_exists('exif_read_data')) {
            $image = imagecreatefromjpeg($filepath);
            if ($image) {
                imagejpeg($image, $filepath, 85);
                imagedestroy($image);
            }
        }
    } catch (Exception $e) {
        // Ignore errors
    }
}
?>
