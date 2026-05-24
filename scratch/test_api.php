<?php
// scratch/test_api.php
ob_start();
session_start();

// Mock user session
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';
$_SESSION['warehouse_id'] = 1;

// Mock the request
$_GET['action'] = 'list';
$_GET['route'] = 'api_quan_ly_nhan_vien.php';

// Include the API file
// Note: We need to set up the environment similar to public/index.php
// But we can just require the file directly if we handle the dependencies.

require_once __DIR__ . '/../app/LegacyApi/api_quan_ly_nhan_vien.php';

$output = ob_get_clean();
echo "OUTPUT:\n";
echo $output;
echo "\nEND OUTPUT\n";
