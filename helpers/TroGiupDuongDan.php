<?php
/**
 * PathHelper - Helper class for handling file paths and URLs
 * Converts absolute file paths to web-accessible URLs
 */
class PathHelper {
    /**
     * Convert absolute file path to web URL
     * @param string $filePath Absolute file path
     * @return string Web-accessible URL
     */
    public static function getImageUrl($filePath) {
        if (empty($filePath)) {
            return 'img/no-image.png';
        }
        
        // If already a URL (starts with http:// or https://), return as is
        if (preg_match('/^https?:\/\//i', $filePath)) {
            return $filePath;
        }
        
        // If relative path (doesn't start with C:\ or /), return as is
        if (!preg_match('/^[A-Z]:\\\\/i', $filePath) && !preg_match('/^\//', $filePath)) {
            return $filePath;
        }
        
        // Chuyển đổi absolute Windows path to relative web path
        // Example: C:\xampp\htdocs\cmix_warehouseTOI\uploads\products\image.jpg
        //       -> uploads/products/image.jpg
        
        // Normalize path separators to forward slashes first
        $filePath = str_replace('\\', '/', $filePath);
        
        // Try to extract relative path from common patterns
        // Pattern 1: Trích xuất everything after project folder name
        if (preg_match('/cmix_warehouseTOI\/(.+)$/', $filePath, $matches)) {
            return $matches[1];
        }
        
        // Pattern 2: Trích xuất uploads/* or img/* pattern
        if (preg_match('/(uploads\/.+|img\/.+)/', $filePath, $matches)) {
            return $matches[1];
        }
        
        // Pattern 3: Try using DOCUMENT_ROOT
        $documentRoot = $_SERVER['DOCUMENT_ROOT'];
        if (!empty($documentRoot)) {
            $documentRoot = str_replace('\\', '/', $documentRoot);
            
            // Xóa document root từ file path to get relative path
            if (strpos($filePath, $documentRoot) === 0) {
                $relativePath = substr($filePath, strlen($documentRoot));
                // Xóa leading slash nếu tồn tại
                $relativePath = ltrim($relativePath, '/');
                return $relativePath;
            }
        }
        
        // Last resort: return original path (might not work but better than nothing)
        error_log("PathHelper WARNING: Could not convert path: $filePath");
        return $filePath;
    }
    
    /**
     * Get full URL with domain
     * @param string $filePath File path
     * @return string Full URL
     */
    public static function getFullImageUrl($filePath) {
        $relativeUrl = self::getImageUrl($filePath);
        
        // If already full URL, return as is
        if (preg_match('/^https?:\/\//i', $relativeUrl)) {
            return $relativeUrl;
        }
        
        // Xây dựng full URL
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        $baseUrl = $protocol . '://' . $host;
        
        // Lấy base path from REQUEST_URI
        $scriptName = dirname($_SERVER['SCRIPT_NAME']);
        if ($scriptName !== '/') {
            $baseUrl .= $scriptName;
        }
        
        return $baseUrl . '/' . ltrim($relativeUrl, '/');
    }
}
?>
