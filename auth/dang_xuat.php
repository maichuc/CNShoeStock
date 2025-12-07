<?php
require_once __DIR__ . '/auth_middleware.php';

$auth = new AuthMiddleware();
$auth->logout();
?>