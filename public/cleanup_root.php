<?php
// TỰ ĐỘNG DỌN DẸP ROOT VÀ SỬA ĐƯỜNG DẪN HÀNG LOẠT
// File này đã được dời vào thư mục public để chạy đè lên .htaccess
$baseDir = __DIR__ . '/..';

// 1. Tạo thư mục storage và chuyển các file linh tinh
if (!is_dir($baseDir . '/storage')) mkdir($baseDir . '/storage', 0777, true);
$storageDirs = ['logs', 'temp', 'cache', 'docs', 'diagrams'];
foreach ($storageDirs as $dir) {
    if (is_dir($baseDir . '/' . $dir)) {
        rename($baseDir . '/' . $dir, $baseDir . '/storage/' . $dir);
    }
}

// 2. Chuyển thư mục giao diện con vào resources/views
$viewDirs = ['includes', 'modals'];
foreach ($viewDirs as $dir) {
    if (is_dir($baseDir . '/' . $dir)) {
        rename($baseDir . '/' . $dir, $baseDir . '/resources/views/' . $dir);
    }
}

// 3. Chuyển thư mục logic vào app/
$appDirs = ['auth' => 'Auth', 'classes' => 'Classes', 'helpers' => 'Helpers'];
foreach ($appDirs as $old => $new) {
    if (is_dir($baseDir . '/' . $old)) {
        rename($baseDir . '/' . $old, $baseDir . '/app/' . $new);
    }
}

// 4. Quét và sửa đường dẫn require_once trong tất cả các file View
$views = glob($baseDir . '/resources/views/*.php');
foreach ($views as $file) {
    $content = file_get_contents($file);
    
    // Sửa includes/ và modals/ -> __DIR__ . '/includes/'
    $content = preg_replace("/require_once\\s+['\"](includes|modals)\\/(.*?)['\"]\\s*;/i", "require_once __DIR__ . '/$1/$2';", $content);
    $content = preg_replace("/include\\s+['\"](includes|modals)\\/(.*?)['\"]\\s*;/i", "include __DIR__ . '/$1/$2';", $content);
    
    // Sửa config/ -> __DIR__ . '/../../config/'
    $content = preg_replace("/require_once\\s+['\"]config\\/(.*?)['\"]\\s*;/i", "require_once __DIR__ . '/../../config/$1';", $content);
    
    // Sửa classes/ -> __DIR__ . '/../../app/Classes/'
    $content = preg_replace("/require_once\\s+['\"]classes\\/(.*?)['\"]\\s*;/i", "require_once __DIR__ . '/../../app/Classes/$1';", $content);
    
    // Sửa helpers/ -> __DIR__ . '/../../app/Helpers/'
    $content = preg_replace("/require_once\\s+['\"]helpers\\/(.*?)['\"]\\s*;/i", "require_once __DIR__ . '/../../app/Helpers/$1';", $content);

    // Sửa auth/ -> __DIR__ . '/../../app/Auth/'
    $content = preg_replace("/require_once\\s+['\"]auth\\/(.*?)['\"]\\s*;/i", "require_once __DIR__ . '/../../app/Auth/$1';", $content);

    file_put_contents($file, $content);
}

// 5. Chuyển tất cả file api_*.php còn sót lại vào thư mục app/LegacyApi/
if (!is_dir($baseDir . '/app/LegacyApi')) mkdir($baseDir . '/app/LegacyApi', 0777, true);
$legacyApis = array_merge(glob($baseDir . '/api_*.php'), glob($baseDir . '/api/api_*.php'));
foreach ($legacyApis as $apiFile) {
    if (file_exists($apiFile)) {
        $filename = basename($apiFile);
        rename($apiFile, $baseDir . '/app/LegacyApi/' . $filename);
        
        // Cập nhật nội dung bên trong file api legacy
        $content = file_get_contents($baseDir . '/app/LegacyApi/' . $filename);
        
        // Xử lý các file có dạng require_once 'config...'
        $content = preg_replace("/require_once\\s+['\"]config\\/(.*?)['\"]\\s*;/i", "require_once __DIR__ . '/../../config/$1';", $content);
        $content = preg_replace("/require_once\\s+['\"]classes\\/(.*?)['\"]\\s*;/i", "require_once __DIR__ . '/../../app/Classes/$1';", $content);
        
        // Xử lý riêng cho file api_quan_ly_phieu_nhap.php (vì đã dùng __DIR__)
        if ($filename === 'api_quan_ly_phieu_nhap.php') {
            $content = str_replace("__DIR__ . '/config", "__DIR__ . '/../../config", $content);
            $content = str_replace("__DIR__ . '/classes", "__DIR__ . '/../../app/Classes", $content);
        }
        
        file_put_contents($baseDir . '/app/LegacyApi/' . $filename, $content);
    }
}

// 6. Cập nhật routes/api.php để trỏ đúng thư mục LegacyApi mới
$apiRouteFile = $baseDir . '/routes/api.php';
if (file_exists($apiRouteFile)) {
    $content = file_get_contents($apiRouteFile);
    $content = str_replace(
        "\$legacyFile = __DIR__ . '/../' . basename(\$route);",
        "\$legacyFile = __DIR__ . '/../app/LegacyApi/' . basename(\$route);",
        $content
    );
    $content = str_replace(
        "require_once __DIR__ . '/../api_quan_ly_phieu_nhap.php';",
        "require_once __DIR__ . '/../app/LegacyApi/api_quan_ly_phieu_nhap.php';",
        $content
    );
    file_put_contents($apiRouteFile, $content);
}

// 7. Chuyển file SCSS vào resources/
if (is_dir($baseDir . '/scss')) {
    rename($baseDir . '/scss', $baseDir . '/resources/scss');
}

echo "<h1>ĐÃ DỌN SẠCH THƯ MỤC GỐC (ROOT) TỚI MỨC TỐI ĐA!</h1>";
echo "<h3>Tất cả các file lộn xộn đã được quy hoạch vào đúng vị trí của MVC. Bây giờ bạn có thể xóa file cleanup_root.php đi nhé!</h3>";
?>
