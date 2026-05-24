<?php
namespace App\Http\Controllers\Web;

class PageController {
    public function render($view) {
        // Bảo vệ chống directory traversal
        $view = basename($view);
        $viewPath = __DIR__ . '/../../../../resources/views/' . $view;
        
        error_log("PageController rendering view: $view (Path: $viewPath)");
        if (file_exists($viewPath)) {
            require_once $viewPath;
        } else {
            http_response_code(404);
            echo '<h1>404 - Page Not Found</h1>';
        }
    }
}
?>