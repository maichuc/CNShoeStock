<?php
// public/index.php - ĐIỂM VÀO DUY NHẤT (Front Controller)
ob_start();
session_start();

// Cấu hình CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

$route = $_GET['route'] ?? '';

// Check if action is provided via GET or POST
$hasAction = isset($_GET['action']) || isset($_POST['action']);

// Check JSON body if action is not set
if (!$hasAction) {
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    if (json_last_error() === JSON_ERROR_NONE && isset($input['action'])) {
        $hasAction = true;
        $_POST['action'] = $input['action']; // Inject into POST for api.php to use
    }
}

// Check if route explicitly points to an API file
$isApiRoute = strpos($route, 'api_') === 0 || strpos($_SERVER['REQUEST_URI'], 'api_') !== false || $route === 'api.php';

$isApiRequest = $isApiRoute;

if ($isApiRequest) {
    // Luồng API (JSON)
    require_once __DIR__ . '/../routes/api.php';
} else {
    // Luồng Web (HTML)
    require_once __DIR__ . '/../routes/web.php';
}
?>