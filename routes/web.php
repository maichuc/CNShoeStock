<?php
// routes/web.php
require_once __DIR__ . '/../app/Http/Controllers/Web/PageController.php';
use App\Http\Controllers\Web\PageController;

$controller = new PageController();
$uri = isset($_GET['route']) ? $_GET['route'] : 'login.html';

$controller->render($uri);
?>