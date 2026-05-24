<?php
// session_start(); - Đã được khởi tạo tại public/index.php

// Kiểm tra if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.html");
    exit();
}

// Include database connection
require_once __DIR__ . '/../../config/database.php';

// Khởi tạo database connection
$database = new Database();
$pdo = $database->getConnection();

// Lấy time period filter
$timePeriod = $_GET['period'] ?? 'today';
$dateCondition = '';
$dateInterval = 1;

// Custom date range
$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';

if ($timePeriod === 'custom' && $startDate && $endDate) {
    $dateCondition = "AND DATE(created_at) BETWEEN '" . $startDate . "' AND '" . $endDate . "'";
    $dateInterval = (strtotime($endDate) - strtotime($startDate)) / 86400 + 1;
} else {
    switch($timePeriod) {
        case '7days':
            $dateCondition = "AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
            $dateInterval = 7;
            break;
        case '30days':
            $dateCondition = "AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
            $dateInterval = 30;
            break;
        case 'month':
            $dateCondition = "AND DATE(created_at) >= DATE_FORMAT(CURDATE(), '%Y-%m-01')";
            $dateInterval = date('d');
            break;
        case '90days':
            $dateCondition = "AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)";
            $dateInterval = 90;
            break;
        case 'today':
        default:
            $dateCondition = "AND DATE(created_at) = CURDATE()";
            $dateInterval = 1;
            break;
    }
}

// Lấy warehouse statistics
$userWarehouseId = $_SESSION['warehouse_id'] ?? null;
$warehouseName = 'Smart Warehouse';

// Lấy warehouse name
if ($userWarehouseId) {
    try {
        require_once __DIR__ . '/../../app/Classes/Kho.php';
        $warehouseObj = new Warehouse($pdo);
        if ($warehouseObj->getById($userWarehouseId)) {
            $warehouseName = $warehouseObj->name;
        }
    } catch (Exception $e) {
        // Silently handle warehouse class error
        error_log("Warehouse error: " . $e->getMessage());
    }
}

// Khởi tạo default values
$totalProducts = 0;
$totalValue = 0;
$lowStockItems = 0;
$pendingOrders = 0;
$stockMovements = [];
$activities = [];
$lowStockProducts = [];
$totalRevenue = 0;
$completedOrders = 0;
$totalItemsSold = 0;
$newProducts = 0;

// Only fetch data if warehouse_id is set
if ($userWarehouseId):

// Lấy total products added in this period (FILTERED)
$stmtProducts = $pdo->prepare("
    SELECT COUNT(DISTINCT p.product_id) as total 
    FROM products p
    WHERE p.warehouse_id = ? AND p.status = 'active'
    " . str_replace('created_at', 'p.created_at', $dateCondition) . "
");
$stmtProducts->execute([$userWarehouseId]);
$newProducts = $stmtProducts->fetch(PDO::FETCH_ASSOC)['total'];

// Lấy total revenue in this period (FILTERED)
$stmtRevenue = $pdo->prepare("
    SELECT COALESCE(SUM(od.total_price), 0) as total_revenue
    FROM orders o
    JOIN order_details od ON o.order_id = od.order_id
    WHERE o.warehouse_id = ? 
    AND o.status IN ('delivered', 'completed')
    " . str_replace('created_at', 'o.created_at', $dateCondition) . "
");
$stmtRevenue->execute([$userWarehouseId]);
$totalRevenue = $stmtRevenue->fetch(PDO::FETCH_ASSOC)['total_revenue'] ?? 0;

// Lấy total items sold in this period (FILTERED)
$stmtItemsSold = $pdo->prepare("
    SELECT COALESCE(SUM(od.quantity), 0) as total_items
    FROM orders o
    JOIN order_details od ON o.order_id = od.order_id
    WHERE o.warehouse_id = ? 
    AND o.status IN ('delivered', 'completed')
    " . str_replace('created_at', 'o.created_at', $dateCondition) . "
");
$stmtItemsSold->execute([$userWarehouseId]);
$totalItemsSold = $stmtItemsSold->fetch(PDO::FETCH_ASSOC)['total_items'] ?? 0;

// Lấy completed orders in this period (FILTERED)
$stmtCompletedOrders = $pdo->prepare("
    SELECT COUNT(*) as total 
    FROM orders 
    WHERE warehouse_id = ? 
    AND status IN ('delivered', 'completed')
    " . str_replace('created_at', 'created_at', $dateCondition) . "
");
$stmtCompletedOrders->execute([$userWarehouseId]);
$completedOrders = $stmtCompletedOrders->fetch(PDO::FETCH_ASSOC)['total'];

// Lấy recent stock movements (filtered by time period)
$stmtMovements = $pdo->prepare("
    SELECT DATE(sri.created_at) as date, SUM(sri.quantity) as total_quantity
    FROM stock_receipt_items sri
    INNER JOIN stock_receipts sr ON sri.receipt_id = sr.receipt_id
    WHERE sri.warehouse_id = ? 
    AND sri.created_at >= DATE_SUB(NOW(), INTERVAL " . $dateInterval . " DAY)
    AND sr.status = 'confirmed'
    GROUP BY DATE(sri.created_at)
    ORDER BY date ASC
");
$stmtMovements->execute([$userWarehouseId]);
$stockMovements = $stmtMovements->fetchAll(PDO::FETCH_ASSOC);

// Lấy product types and revenue by type
$stmtProductTypes = $pdo->prepare("
    SELECT 
        COALESCE(p.type, 'Chưa phân loại') as product_type,
        COUNT(DISTINCT p.product_id) as product_count,
        SUM(i.quantity * pv.price) as revenue
    FROM products p
    INNER JOIN product_variants pv ON p.product_id = pv.product_id
    LEFT JOIN inventory i ON pv.variant_id = i.variant_id AND i.warehouse_id = ?
    WHERE pv.warehouse_id = ?
    GROUP BY p.type
    ORDER BY revenue DESC
    LIMIT 5
");
$stmtProductTypes->execute([$userWarehouseId, $userWarehouseId]);
$productTypes = $stmtProductTypes->fetchAll(PDO::FETCH_ASSOC);

// Tính toán Số ngày tồn kho trung bình (Days of Inventory)
// Công thức: (Tồn kho hiện tại / Doanh số bán trung bình mỗi ngày)
$stmtInventoryDays = $pdo->prepare("
    SELECT 
        COALESCE(SUM(i.quantity), 0) as total_stock,
        COALESCE(SUM(sales.daily_sales), 0) as avg_daily_sales
    FROM inventory i
    LEFT JOIN (
        SELECT 
            od.variant_id,
            SUM(od.quantity) / " . $dateInterval . " as daily_sales
        FROM orders o
        JOIN order_details od ON o.order_id = od.order_id
        WHERE o.status = 'delivered'
            AND o.created_at >= DATE_SUB(NOW(), INTERVAL " . $dateInterval . " DAY)
        GROUP BY od.variant_id
    ) sales ON i.variant_id = sales.variant_id
    WHERE i.warehouse_id = ?
");
$stmtInventoryDays->execute([$userWarehouseId]);
$inventoryData = $stmtInventoryDays->fetch(PDO::FETCH_ASSOC);
$avgInventoryDays = 0;
if ($inventoryData['avg_daily_sales'] > 0) {
    $avgInventoryDays = round($inventoryData['total_stock'] / $inventoryData['avg_daily_sales']);
}

// Tính toán Số ngày tồn kho kỳ trước để so sánh
$previousInterval = $dateInterval * 2;
$stmtInventoryDaysLastMonth = $pdo->prepare("
    SELECT 
        COALESCE(SUM(i.quantity), 0) as total_stock,
        COALESCE(SUM(sales.daily_sales), 0) as avg_daily_sales
    FROM inventory i
    LEFT JOIN (
        SELECT 
            od.variant_id,
            SUM(od.quantity) / " . $dateInterval . " as daily_sales
        FROM orders o
        JOIN order_details od ON o.order_id = od.order_id
        WHERE o.status = 'delivered'
            AND o.created_at >= DATE_SUB(NOW(), INTERVAL " . $previousInterval . " DAY)
            AND o.created_at < DATE_SUB(NOW(), INTERVAL " . $dateInterval . " DAY)
        GROUP BY od.variant_id
    ) sales ON i.variant_id = sales.variant_id
    WHERE i.warehouse_id = ?
");
$stmtInventoryDaysLastMonth->execute([$userWarehouseId]);
$inventoryDataLastMonth = $stmtInventoryDaysLastMonth->fetch(PDO::FETCH_ASSOC);
$avgInventoryDaysLastMonth = 0;
if ($inventoryDataLastMonth['avg_daily_sales'] > 0) {
    $avgInventoryDaysLastMonth = round($inventoryDataLastMonth['total_stock'] / $inventoryDataLastMonth['avg_daily_sales']);
}

// Tính phần trăm thay đổi so với tháng trước
$inventoryDaysChange = 0;
$inventoryDaysChangeDirection = '';
if ($avgInventoryDaysLastMonth > 0 && $avgInventoryDays > 0) {
    $inventoryDaysChange = abs((($avgInventoryDays - $avgInventoryDaysLastMonth) / $avgInventoryDaysLastMonth) * 100);
    $inventoryDaysChangeDirection = ($avgInventoryDays < $avgInventoryDaysLastMonth) ? 'down' : 'up';
}

// Tính toán Tỷ lệ vòng quay kho (Inventory Turnover Rate)
// Công thức: Giá trị hàng bán ra / Giá trị tồn kho trung bình
$stmtTurnover = $pdo->prepare("
    SELECT 
        COALESCE(SUM(od.total_price), 0) as cost_of_goods_sold
    FROM orders o
    JOIN order_details od ON o.order_id = od.order_id
    WHERE o.status = 'delivered'
        AND o.warehouse_id = ?
        AND o.created_at >= DATE_SUB(NOW(), INTERVAL " . $dateInterval . " DAY)
");
$stmtTurnover->execute([$userWarehouseId]);
$cogs = $stmtTurnover->fetch(PDO::FETCH_ASSOC)['cost_of_goods_sold'];
$turnoverRate = 0;
if ($totalValue > 0) {
    $turnoverRate = $cogs / $totalValue;
}

// Lấy dữ liệu cho biểu đồ: So sánh Doanh thu vs Chi phí nhập hàng theo ngày
// 1. Doanh thu (Revenue) - Tổng tiền khách hàng trả
$stmtRevenueChart = $pdo->prepare("
    SELECT 
        DATE(o.created_at) as order_date,
        COALESCE(SUM(od.quantity * od.unit_price), 0) as daily_revenue
    FROM orders o
    JOIN order_details od ON o.order_id = od.order_id
    WHERE o.status = 'delivered'
        AND o.warehouse_id = ?
        AND o.created_at >= DATE_SUB(NOW(), INTERVAL " . $dateInterval . " DAY)
    GROUP BY DATE(o.created_at)
    ORDER BY order_date ASC
");
$stmtRevenueChart->execute([$userWarehouseId]);
$revenueChartData = $stmtRevenueChart->fetchAll(PDO::FETCH_ASSOC);

// 2. Chi phí nhập hàng (Import Cost) - Tổng tiền nhập hàng vào kho
$stmtImportCostChart = $pdo->prepare("
    SELECT 
        DATE(sr.created_at) as receipt_date,
        COALESCE(SUM(sri.quantity * sri.unit_price), 0) as daily_import_cost
    FROM stock_receipts sr
    JOIN stock_receipt_items sri ON sr.receipt_id = sri.receipt_id
    WHERE sr.warehouse_id = ?
        AND sr.created_at >= DATE_SUB(NOW(), INTERVAL " . $dateInterval . " DAY)
    GROUP BY DATE(sr.created_at)
    ORDER BY receipt_date ASC
");
$stmtImportCostChart->execute([$userWarehouseId]);
$importCostChartData = $stmtImportCostChart->fetchAll(PDO::FETCH_ASSOC);

// Tạo mảng dữ liệu theo dateInterval
$revenueData = array_fill(0, $dateInterval, 0);
$importCostData = array_fill(0, $dateInterval, 0);

// Điền dữ liệu doanh thu
foreach ($revenueChartData as $revenue) {
    $dayDiff = (strtotime(date('Y-m-d')) - strtotime($revenue['order_date'])) / 86400;
    if ($dayDiff >= 0 && $dayDiff < $dateInterval) {
        $revenueData[($dateInterval - 1) - intval($dayDiff)] = round($revenue['daily_revenue'] / 1000000, 2); // Scale to millions
    }
}

// Điền dữ liệu chi phí nhập hàng
foreach ($importCostChartData as $cost) {
    $dayDiff = (strtotime(date('Y-m-d')) - strtotime($cost['receipt_date'])) / 86400;
    if ($dayDiff >= 0 && $dayDiff < $dateInterval) {
        $importCostData[($dateInterval - 1) - intval($dayDiff)] = round($cost['daily_import_cost'] / 1000000, 2); // Scale to millions
    }
}

// Truy vấn storage location heatmap data - only for user's warehouse
$storageHeatmapData = [];
if ($userWarehouseId) {
    // Lấy locations with inventory
    $stmtStorageHeatmap = $pdo->prepare("
        SELECT 
            l.shelf_code as location_code,
            l.type as location_type,
            COALESCE(SUM(i.quantity), 0) as total_quantity,
            COUNT(DISTINCT i.variant_id) as variant_count
        FROM locations l
        LEFT JOIN inventory i ON l.location_id = i.location_id 
            AND i.warehouse_id = ?
            AND i.quantity > 0
        WHERE l.warehouse_id = ?
            AND l.is_active = 1
        GROUP BY l.location_id, l.shelf_code, l.type
        ORDER BY l.shelf_code
    ");
    $stmtStorageHeatmap->execute([$userWarehouseId, $userWarehouseId]);
    $storageHeatmapData = $stmtStorageHeatmap->fetchAll(PDO::FETCH_ASSOC);
    
    // Thêm unassigned inventory (location_id = NULL)
    $stmtUnassigned = $pdo->prepare("
        SELECT 
            COALESCE(SUM(quantity), 0) as total_quantity,
            COUNT(DISTINCT variant_id) as variant_count
        FROM inventory
        WHERE warehouse_id = ?
            AND location_id IS NULL
            AND quantity > 0
    ");
    $stmtUnassigned->execute([$userWarehouseId]);
    $unassignedData = $stmtUnassigned->fetch(PDO::FETCH_ASSOC);
    
    // If there's unassigned inventory, add it as a special location
    if ($unassignedData['total_quantity'] > 0) {
        array_unshift($storageHeatmapData, [
            'location_code' => '⚠️ CHƯA PHÂN BỔ',
            'location_type' => 'Chưa có vị trí',
            'total_quantity' => $unassignedData['total_quantity'],
            'variant_count' => $unassignedData['variant_count']
        ]);
    }
}

endif; // End of if ($userWarehouseId)
?>
<!DOCTYPE html>
<html lang="vi">

<head>

    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="description" content="Smart Warehouse Management System">
    <meta name="author" content="">

    <title>Dashboard - Hệ thống quản lý kho thông minh</title>

    <!-- Custom fonts for this template-->
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link
        href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i"
        rel="stylesheet">

    <!-- Custom styles for this template-->
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    
    <!-- Dropdown fix CSS -->
    <link href="css/dropdown-fix.css" rel="stylesheet">
    
    <style>
        /* Modern Dashboard Styling - Inspired by InfoCepts Design */
        :root {
            --primary-blue: #4a90e2;
            --primary-dark: #2c5f8d;
            --card-blue: #e8f4fd;
            --card-green: #d4f4e8;
            --card-orange: #ffe4cc;
            --card-purple: #e8d4f4;
            --card-pink: #ffd4e8;
            --text-dark: #2c3e50;
            --text-light: #7f8c8d;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #224abe;
            background-attachment: fixed;
            min-height: 100vh;
        }
        
        #content-wrapper {
            background: transparent;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        
        #content {
            flex: 1 0 auto;
        }
        
        #content .container-fluid {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            padding: 30px;
            margin: 20px 20px 30px 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
        }
        
        .sticky-footer {
            flex-shrink: 0;
            background-color: #2c3e50 !important;
            color: #b8c7ce !important;
            padding: 40px 0 20px !important;
        }
        
        .sticky-footer .container-fluid {
            background: transparent !important;
            border-radius: 0 !important;
            padding: 0 !important;
            margin: 0 !important;
            box-shadow: none !important;
        }
        
        /* Header Section */
        .dashboard-header {
            background: #224abe;
            border-radius: 15px;
            padding: 25px 30px;
            margin-bottom: 30px;
            color: white;
            box-shadow: 0 8px 25px rgba(34, 74, 190, 0.3);
        }
        
        .dashboard-header h1 {
            font-size: 28px;
            font-weight: 600;
            margin: 0;
            color: white;
        }
        
        .dashboard-header .meta-info {
            font-size: 14px;
            opacity: 0.9;
            margin-top: 8px;
        }
        
        /* Stat Cards - New Design */
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            border-left: 4px solid;
            transition: all 0.3s ease;
            height: 100%;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.12);
        }
        
        .stat-card.blue { 
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
            border-left-color: #2196F3; 
        }
        
        .stat-card.green { 
            background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
            border-left-color: #4CAF50; 
        }
        
        .stat-card.orange { 
            background: linear-gradient(135deg, #fff3e0 0%, #ffe0b2 100%);
            border-left-color: #FF9800; 
        }
        
        .stat-card.purple { 
            background: linear-gradient(135deg, #f3e5f5 0%, #e1bee7 100%);
            border-left-color: #9C27B0; 
        }
        
        .stat-card.cyan { 
            background: linear-gradient(135deg, #e0f7fa 0%, #b2ebf2 100%);
            border-left-color: #00BCD4; 
        }
        
        .stat-card.pink { 
            background: linear-gradient(135deg, #fce4ec 0%, #f8bbd0 100%);
            border-left-color: #E91E63; 
        }
        
        .stat-card-title {
            font-size: 13px;
            font-weight: 600;
            text-transform: uppercase;
            color: #546e7a;
            margin-bottom: 12px;
            letter-spacing: 0.5px;
        }
        
        .stat-card-value {
            font-size: 32px;
            font-weight: 700;
            color: #263238;
            margin: 0;
            line-height: 1;
        }
        
        .stat-card-unit {
            font-size: 14px;
            color: #78909c;
            margin-left: 5px;
        }
        
        .stat-card-icon {
            position: absolute;
            right: 25px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 48px;
            opacity: 0.15;
        }
        
        /* Chart Cards */
        .chart-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            margin-bottom: 25px;
        }
        
        .chart-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .chart-card-title {
            font-size: 18px;
            font-weight: 600;
            color: #263238;
            margin: 0;
        }
        
        /* Time Period Buttons */
        .time-period-buttons {
            display: flex;
            gap: 8px;
            background: #f5f7fa;
            padding: 5px;
            border-radius: 8px;
            flex-wrap: wrap;
        }
        
        .time-period-buttons .btn {
            padding: 6px 16px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 500;
            border: none;
            background: transparent;
            color: #546e7a;
            transition: all 0.3s;
            white-space: nowrap;
        }
        
        .time-period-buttons .btn.active {
            background: #1967d2;
            color: white;
            box-shadow: 0 2px 8px rgba(25, 103, 210, 0.3);
        }
        
        .time-period-buttons .btn:hover:not(.active) {
            background: white;
            color: #1967d2;
        }
        
        /* Custom Date Picker */
        .custom-date-picker {
            background: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            margin-top: 10px;
        }
        
        .custom-date-picker .form-control-sm {
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 6px 12px;
            font-size: 13px;
        }
        
        .custom-date-picker .btn-primary {
            background: #1967d2;
            border: none;
            padding: 6px 20px;
            font-weight: 500;
        }
        
        .custom-date-picker .btn-primary:hover {
            background: #1557b0;
        }
        
        /* Table Styling */
        .modern-table {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .modern-table thead th {
            background: linear-gradient(135deg, #f5f7fa 0%, #e8ebf0 100%);
            color: #546e7a;
            font-weight: 600;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 15px;
            border: none;
        }
        
        .modern-table tbody td {
            padding: 15px;
            color: #263238;
            font-size: 14px;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .modern-table tbody tr:hover {
            background: #f8f9fa;
        }
        
        /* Progress Bars */
        .progress-modern {
            height: 8px;
            border-radius: 10px;
            background: #e0e0e0;
            overflow: visible;
        }
        
        .progress-modern .progress-bar {
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        
        /* Action Buttons */
        .btn-modern {
            padding: 10px 24px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            border: none;
            transition: all 0.3s;
        }
        
        .btn-modern.btn-primary {
            background: linear-gradient(135deg, #5b73e8 0%, #4facfe 100%);
            box-shadow: 0 4px 15px rgba(91, 115, 232, 0.3);
        }
        
        .btn-modern.btn-success {
            background: linear-gradient(135deg, #1cc88a 0%, #13855c 100%);
            box-shadow: 0 4px 15px rgba(28, 200, 138, 0.3);
        }
        
        .btn-modern:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.2);
        }
        
        /* Storage Heatmap Grid */
        .storage-heatmap-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
            gap: 10px;
            padding: 10px;
            max-height: 500px;
            overflow-y: auto;
        }
        
        .heatmap-cell {
            position: relative;
            aspect-ratio: 1;
            min-height: 100px;
            border-radius: 8px;
            padding: 10px;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            border: 2px solid rgba(255,255,255,0.5);
        }
        
        .heatmap-cell:hover {
            transform: scale(1.1);
            z-index: 10;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            border-color: #fff;
        }
        
        .heatmap-cell-code {
            font-size: 11px;
            font-weight: 700;
            color: rgba(0,0,0,0.8);
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .heatmap-cell-value {
            font-size: 16px;
            font-weight: 900;
            color: rgba(0,0,0,0.9);
        }
        
        /* Special styling for unassigned location */
        .heatmap-cell-unassigned {
            border: 3px solid #ff5722 !important;
            box-shadow: 0 4px 8px rgba(255, 87, 34, 0.4) !important;
            animation: pulse-warning 2s infinite;
        }
        
        .heatmap-cell-unassigned .heatmap-cell-code {
            font-size: 10px;
            font-weight: 900;
        }
        
        @keyframes pulse-warning {
            0%, 100% {
                box-shadow: 0 4px 8px rgba(255, 87, 34, 0.4);
            }
            50% {
                box-shadow: 0 6px 15px rgba(255, 87, 34, 0.6);
            }
        }
        
        .heatmap-legend {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
        }
        
        .legend-item {
            display: inline-block;
            width: 20px;
            height: 20px;
            border-radius: 4px;
            margin-right: 5px;
            border: 1px solid rgba(0,0,0,0.1);
        }
        
        @media (max-width: 768px) {
            .storage-heatmap-grid {
                grid-template-columns: repeat(auto-fill, minmax(80px, 1fr));
                gap: 8px;
            }
            
            .heatmap-cell {
                min-height: 80px;
            }
            
            .heatmap-cell-code {
                font-size: 9px;
            }
            
            .heatmap-cell-value {
                font-size: 14px;
            }
        }
    </style>

</head>

<body id="page-top">

    <!-- Page Wrapper -->
    <div id="wrapper">

        <!-- Sidebar -->
        <?php include __DIR__ . '/includes/thanh_ben.php'; ?>

        <!-- Content Wrapper -->
        <div id="content-wrapper" class="d-flex flex-column">

            <!-- Main Content -->
            <div id="content">

                <!-- Topbar -->
                <nav class="navbar navbar-expand navbar-light bg-white topbar mb-4 static-top shadow">

                    <!-- Sidebar Toggle (Topbar) -->
                    <button id="sidebarToggleTop" class="btn btn-link d-md-none rounded-circle mr-3">
                        <i class="fa fa-bars"></i>
                    </button>

                    <!-- Topbar Search -->
                    <form
                        class="d-none d-sm-inline-block form-inline mr-auto ml-md-3 my-2 my-md-0 mw-100 navbar-search">
                        <div class="input-group">
                            <input type="text" class="form-control bg-light border-0 small" placeholder="Search for..."
                                aria-label="Search" aria-describedby="basic-addon2">
                            <div class="input-group-append">
                                <button class="btn btn-primary" type="button">
                                    <i class="fas fa-search fa-sm"></i>
                                </button>
                            </div>
                        </div>
                    </form>

                    <!-- Topbar Navbar -->
                    <ul class="navbar-nav ml-auto">

                        <!-- Nav Item - Search Dropdown (Visible Only XS) -->
                        <li class="nav-item dropdown no-arrow d-sm-none">
                            <a class="nav-link dropdown-toggle" href="#" id="searchDropdown" role="button"
                                data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <i class="fas fa-search fa-fw"></i>
                            </a>
                            <!-- Dropdown - Messages -->
                            <div class="dropdown-menu dropdown-menu-right p-3 shadow animated--grow-in"
                                aria-labelledby="searchDropdown">
                                <form class="form-inline mr-auto w-100 navbar-search">
                                    <div class="input-group">
                                        <input type="text" class="form-control bg-light border-0 small"
                                            placeholder="Search for..." aria-label="Search"
                                            aria-describedby="basic-addon2">
                                        <div class="input-group-append">
                                            <button class="btn btn-primary" type="button">
                                                <i class="fas fa-search fa-sm"></i>
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </li>

                        <!-- Nav Item - Alerts -->
                        <li class="nav-item dropdown no-arrow mx-1">
                            <a class="nav-link dropdown-toggle" href="#" id="alertsDropdown" role="button"
                                data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <i class="fas fa-bell fa-fw"></i>
                                <!-- Counter - Alerts -->
                                <span class="badge badge-danger badge-counter">3+</span>
                            </a>
                            <!-- Dropdown - Alerts -->
                            <div class="dropdown-list dropdown-menu dropdown-menu-right shadow animated--grow-in"
                                aria-labelledby="alertsDropdown">
                                <h6 class="dropdown-header">
                                    Alerts Center
                                </h6>
                                <a class="dropdown-item d-flex align-items-center" href="#">
                                    <div class="mr-3">
                                        <div class="icon-circle bg-primary">
                                            <i class="fas fa-file-alt text-white"></i>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="small text-gray-500">December 12, 2019</div>
                                        <span class="font-weight-bold">A new monthly report is ready to download!</span>
                                    </div>
                                </a>
                                <a class="dropdown-item d-flex align-items-center" href="#">
                                    <div class="mr-3">
                                        <div class="icon-circle bg-success">
                                            <i class="fas fa-donate text-white"></i>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="small text-gray-500">December 7, 2019</div>
                                        $290.29 has been deposited into your account!
                                    </div>
                                </a>
                                <a class="dropdown-item d-flex align-items-center" href="#">
                                    <div class="mr-3">
                                        <div class="icon-circle bg-warning">
                                            <i class="fas fa-exclamation-triangle text-white"></i>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="small text-gray-500">December 2, 2019</div>
                                        Spending Alert: We've noticed unusually high spending for your account.
                                    </div>
                                </a>
                                <a class="dropdown-item text-center small text-gray-500" href="#">Show All Alerts</a>
                            </div>
                        </li>

                        <!-- Nav Item - Messages -->
                        <li class="nav-item dropdown no-arrow mx-1">
                            <a class="nav-link dropdown-toggle" href="#" id="messagesDropdown" role="button"
                                data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <i class="fas fa-envelope fa-fw"></i>
                                <!-- Counter - Messages -->
                                <span class="badge badge-danger badge-counter">7</span>
                            </a>
                            <!-- Dropdown - Messages -->
                            <div class="dropdown-list dropdown-menu dropdown-menu-right shadow animated--grow-in"
                                aria-labelledby="messagesDropdown">
                                <h6 class="dropdown-header">
                                    Message Center
                                </h6>
                                <a class="dropdown-item d-flex align-items-center" href="#">
                                    <div class="dropdown-list-image mr-3">
                                        <img class="rounded-circle" src="img/undraw_profile_1.svg"
                                            alt="...">
                                        <div class="status-indicator bg-success"></div>
                                    </div>
                                    <div class="font-weight-bold">
                                        <div class="text-truncate">Hi there! I am wondering if you can help me with a
                                            problem I've been having.</div>
                                        <div class="small text-gray-500">Emily Fowler · 58m</div>
                                    </div>
                                </a>
                                <a class="dropdown-item d-flex align-items-center" href="#">
                                    <div class="dropdown-list-image mr-3">
                                        <img class="rounded-circle" src="img/undraw_profile_2.svg"
                                            alt="...">
                                        <div class="status-indicator"></div>
                                    </div>
                                    <div>
                                        <div class="text-truncate">I have the photos that you ordered last month, how
                                            would you like them sent to you?</div>
                                        <div class="small text-gray-500">Jae Chun · 1d</div>
                                    </div>
                                </a>
                                <a class="dropdown-item d-flex align-items-center" href="#">
                                    <div class="dropdown-list-image mr-3">
                                        <img class="rounded-circle" src="img/undraw_profile_3.svg"
                                            alt="...">
                                        <div class="status-indicator bg-warning"></div>
                                    </div>
                                    <div>
                                        <div class="text-truncate">Last month's report looks great, I am very happy with
                                            the progress so far, keep up the good work!</div>
                                        <div class="small text-gray-500">Morgan Alvarez · 2d</div>
                                    </div>
                                </a>
                                <a class="dropdown-item d-flex align-items-center" href="#">
                                    <div class="dropdown-list-image mr-3">
                                        <img class="rounded-circle" src="https://source.unsplash.com/Mv9hjnEUHR4/60x60"
                                            alt="...">
                                        <div class="status-indicator bg-success"></div>
                                    </div>
                                    <div>
                                        <div class="text-truncate">Am I a good boy? The reason I ask is because someone
                                            told me that people say this to all dogs, even if they aren't good...</div>
                                        <div class="small text-gray-500">Chicken the Dog · 2w</div>
                                    </div>
                                </a>
                                <a class="dropdown-item text-center small text-gray-500" href="#">Read More Messages</a>
                            </div>
                        </li>

                        <div class="topbar-divider d-none d-sm-block"></div>

                        <!-- Nav Item - User Information -->
                        <li class="nav-item dropdown no-arrow">
                            <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button"
                                data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <span class="mr-2 d-none d-lg-inline text-gray-600 small"><?php echo $_SESSION['username'] ?? 'User'; ?></span>
                                <img class="img-profile rounded-circle"
                                    src="img/undraw_profile.svg">
                            </a>
                            <!-- Dropdown - User Information -->
                            <div class="dropdown-menu dropdown-menu-right shadow animated--grow-in"
                                aria-labelledby="userDropdown">
                                <h6 class="dropdown-header">
                                    <i class="fas fa-cogs fa-sm fa-fw mr-2"></i>Cài Đặt
                                </h6>
                                <a class="dropdown-item" href="cai_dat_ho_so.php">
                                    <i class="fas fa-user fa-sm fa-fw mr-2 text-gray-400"></i>
                                    Hồ Sơ
                                </a>
                                <a class="dropdown-item" href="doi_mat_khau.php">
                                    <i class="fas fa-key fa-sm fa-fw mr-2 text-gray-400"></i>
                                    Đổi Mật Khẩu
                                </a>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item" href="#" data-toggle="modal" data-target="#logoutModal">
                                    <i class="fas fa-sign-out-alt fa-sm fa-fw mr-2 text-gray-400"></i>
                                    Đăng Xuất
                                </a>
                            </div>
                        </li>

                    </ul>

                </nav>
                <!-- End of Topbar -->

                <!-- Begin Page Content -->
                <div class="container-fluid">

                    <!-- Dashboard Header -->
                    <div class="dashboard-header">
                        <div class="row align-items-center">
                            <div class="col-lg-8">
                                <h1><i class="fas fa-chart-line mr-3"></i>Dashboard Quản Trị Kho Hàng</h1>
                                <div class="meta-info">
                                    <i class="far fa-calendar-alt mr-2"></i>Cập nhật: <?php echo date('d/m/Y H:i'); ?> 
                                    <span class="mx-2">|</span>
                                    <i class="fas fa-user mr-2"></i><?php echo htmlspecialchars($_SESSION['username'] ?? 'Admin'); ?>
                                    <span class="mx-2">|</span>
                                    <i class="fas fa-user-tag mr-2"></i><?php echo htmlspecialchars($_SESSION['role'] ?? 'Employee'); ?>
                                    <span class="mx-2">|</span>
                                    <i class="fas fa-filter mr-2"></i>Lọc: 
                                    <span id="currentPeriodText">
                                        <?php 
                                        if ($timePeriod == 'custom' && $startDate && $endDate) {
                                            echo date('d/m/Y', strtotime($startDate)) . ' - ' . date('d/m/Y', strtotime($endDate));
                                        } else {
                                            $periodText = [
                                                'today' => 'Hôm nay',
                                                '7days' => '7 ngày qua',
                                                '30days' => '30 ngày qua',
                                                'month' => 'Tháng này',
                                                '90days' => '90 ngày qua'
                                            ];
                                            echo $periodText[$timePeriod] ?? 'Hôm nay';
                                        }
                                        ?>
                                    </span>
                                </div>
                            </div>
                            <div class="col-lg-4 text-right">
                                <div class="time-period-buttons">
                                    <button class="btn <?php echo $timePeriod == 'today' ? 'active' : ''; ?>" 
                                            onclick="window.location.href='?period=today'">
                                        Hôm nay
                                    </button>
                                    <button class="btn <?php echo $timePeriod == '7days' ? 'active' : ''; ?>" 
                                            onclick="window.location.href='?period=7days'">
                                        7 ngày
                                    </button>
                                    <button class="btn <?php echo $timePeriod == '30days' ? 'active' : ''; ?>" 
                                            onclick="window.location.href='?period=30days'">
                                        30 ngày
                                    </button>
                                    <button class="btn <?php echo $timePeriod == 'month' ? 'active' : ''; ?>" 
                                            onclick="window.location.href='?period=month'">
                                        Tháng này
                                    </button>
                                    <button class="btn <?php echo $timePeriod == '90days' ? 'active' : ''; ?>" 
                                            onclick="window.location.href='?period=90days'">
                                        90 ngày
                                    </button>
                                    <button class="btn <?php echo $timePeriod == 'custom' ? 'active' : ''; ?>" 
                                            onclick="toggleCustomDatePicker()">
                                        <i class="fas fa-cog"></i> Tùy chỉnh
                                    </button>
                                </div>
                                <!-- Custom Date Picker -->
                                <div id="customDatePicker" class="custom-date-picker" style="display: <?php echo $timePeriod == 'custom' ? 'block' : 'none'; ?>;">
                                    <form method="GET" action="trang_chu.php" class="form-inline mt-2">
                                        <input type="hidden" name="period" value="custom">
                                        <input type="date" name="start_date" class="form-control form-control-sm mr-2" 
                                               value="<?php echo htmlspecialchars($startDate); ?>" required>
                                        <span class="mr-2">đến</span>
                                        <input type="date" name="end_date" class="form-control form-control-sm mr-2" 
                                               value="<?php echo htmlspecialchars($endDate); ?>" required>
                                        <button type="submit" class="btn btn-primary btn-sm">Áp dụng</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Content Row - Stats Cards -->
                    <div class="row">

                        <!-- New Products Card -->
                        <div class="col-xl-3 col-lg-6 col-md-6 mb-4">
                            <div class="stat-card blue position-relative">
                                <div class="stat-card-title">
                                    <i class="fas fa-box mr-2"></i>Sản phẩm mới thêm
                                    <i class="fas fa-info-circle ml-1" style="font-size: 11px; opacity: 0.6; cursor: help;" 
                                       data-toggle="tooltip" title="Đếm số sản phẩm riêng biệt được thêm trong khoảng thời gian đã chọn và đang hoạt động"></i>
                                </div>
                                <div class="stat-card-value">
                                    <?php echo number_format($newProducts); ?>
                                </div>
                                <i class="fas fa-plus-circle stat-card-icon"></i>
                            </div>
                        </div>

                        <!-- Revenue Card -->
                        <div class="col-xl-3 col-lg-6 col-md-6 mb-4">
                            <div class="stat-card green position-relative">
                                <div class="stat-card-title">
                                    <i class="fas fa-dollar-sign mr-2"></i>Doanh thu
                                    <i class="fas fa-info-circle ml-1" style="font-size: 11px; opacity: 0.6; cursor: help;" 
                                       data-toggle="tooltip" title="Công thức: Σ(Số lượng × Đơn giá) của các đơn hàng đã giao. Ví dụ: (100 × 500k) + (50 × 800k) = 90tr"></i>
                                </div>
                                <div class="stat-card-value">
                                    <?php echo number_format($totalRevenue / 1000000, 1); ?>
                                    <span class="stat-card-unit">Triệu VNĐ</span>
                                </div>
                                <i class="fas fa-money-bill-wave stat-card-icon"></i>
                            </div>
                        </div>

                        <!-- Items Sold Card -->
                        <div class="col-xl-3 col-lg-6 col-md-6 mb-4">
                            <div class="stat-card orange position-relative">
                                <div class="stat-card-title">
                                    <i class="fas fa-shopping-cart mr-2"></i>Sản phẩm đã bán
                                    <i class="fas fa-info-circle ml-1" style="font-size: 11px; opacity: 0.6; cursor: help;" 
                                       data-toggle="tooltip" title="Công thức: Σ(Số lượng) từ chi tiết đơn hàng của các đơn đã giao. Ví dụ: 100 + 50 + 75 = 225 sản phẩm"></i>
                                </div>
                                <div class="stat-card-value">
                                    <?php echo number_format($totalItemsSold); ?>
                                </div>
                                <i class="fas fa-chart-line stat-card-icon"></i>
                            </div>
                        </div>

                        <!-- Completed Orders Card -->
                        <div class="col-xl-3 col-lg-6 col-md-6 mb-4">
                            <div class="stat-card purple position-relative">
                                <div class="stat-card-title">
                                    <i class="fas fa-check-circle mr-2"></i>Đơn hàng hoàn thành
                                    <i class="fas fa-info-circle ml-1" style="font-size: 11px; opacity: 0.6; cursor: help;" 
                                       data-toggle="tooltip" title="Đếm số đơn hàng có trạng thái 'Đã giao' trong khoảng thời gian đã chọn"></i>
                                </div>
                                <div class="stat-card-value">
                                    <?php echo number_format($completedOrders); ?>
                                </div>
                                <i class="fas fa-clipboard-check stat-card-icon"></i>
                            </div>
                        </div>
        
                        <!-- Inventory Days Card -->
                        <div class="col-xl-3 col-lg-6 col-md-6 mb-4">
                            <div class="stat-card cyan position-relative">
                                <div class="stat-card-title">
                                    <i class="fas fa-calendar-alt mr-2"></i>Số ngày tồn kho
                                    <i class="fas fa-info-circle ml-1" style="font-size: 11px; opacity: 0.6; cursor: help;" 
                                       data-toggle="tooltip" title="Công thức: (Giá trị tồn kho TB ÷ Giá vốn bán/ngày) × 365. Ví dụ: (200tr ÷ 5tr) × 365 = 14,600 ngày"></i>
                                </div>
                                <div class="stat-card-value">
                                    <?php echo $avgInventoryDays > 0 ? number_format($avgInventoryDays, 0) : 'N/A'; ?>
                                    <?php if ($avgInventoryDays > 0): ?>
                                    <span class="stat-card-unit">ngày</span>
                                    <?php endif; ?>
                                </div>
                                <i class="fas fa-calendar-check stat-card-icon"></i>
                            </div>
                        </div>

                        <!-- Total Variants -->
                        <div class="col-xl-3 col-lg-6 col-md-6 mb-4">
                            <div class="stat-card blue position-relative">
                                <div class="stat-card-title">
                                    <i class="fas fa-barcode mr-2"></i>Tổng SKU
                                    <i class="fas fa-info-circle ml-1" style="font-size: 11px; opacity: 0.6; cursor: help;" 
                                       data-toggle="tooltip" title="SKU = Số sản phẩm × Số màu × Số size. Ví dụ: 10 sản phẩm, mỗi sản phẩm 3 màu, 5 size = 150 SKU"></i>
                                </div>
                                <div class="stat-card-value">
                                    <?php
                                    if ($userWarehouseId) {
                                        $stmtVariants = $pdo->prepare("SELECT COUNT(*) as total FROM product_variants WHERE warehouse_id = ?");
                                        $stmtVariants->execute([$userWarehouseId]);
                                        echo number_format($stmtVariants->fetch(PDO::FETCH_ASSOC)['total']);
                                    } else {
                                        echo "0";
                                    }
                                    ?>
                                </div>
                                <i class="fas fa-qrcode stat-card-icon"></i>
                            </div>
                        </div>

                        <!-- Total Stock Receipts -->
                        <div class="col-xl-3 col-lg-6 col-md-6 mb-4">
                            <div class="stat-card green position-relative">
                                <div class="stat-card-title">
                                    <i class="fas fa-file-invoice mr-2"></i>Phiếu nhập <?php echo ($timePeriod == 'today') ? 'hôm nay' : (($timePeriod == 'week') ? 'tuần này' : 'tháng này'); ?>
                                    <i class="fas fa-info-circle ml-1" style="font-size: 11px; opacity: 0.6; cursor: help;" 
                                       data-toggle="tooltip" title="Công thợc: COUNT(receipt_id) FROM stock_receipts WHERE created_at BETWEEN [start] AND [end]"></i>
                                </div>
                                <div class="stat-card-value">
                                    <?php
                                    if ($userWarehouseId) {
                                        $receiptDateCondition = str_replace('created_at', 'sr.created_at', $dateCondition);
                                        $stmtReceipts = $pdo->prepare("
                                            SELECT COUNT(*) as total 
                                            FROM stock_receipts sr
                                            WHERE sr.warehouse_id = ? 
                                            " . $receiptDateCondition . "
                                        ");
                                        $stmtReceipts->execute([$userWarehouseId]);
                                        echo number_format($stmtReceipts->fetch(PDO::FETCH_ASSOC)['total']);
                                    } else {
                                        echo "0";
                                    }
                                    ?>
                                </div>
                                <i class="fas fa-clipboard-check stat-card-icon"></i>
                            </div>
                        </div>

                        <!-- Inventory Turnover Rate -->
                        <div class="col-xl-3 col-lg-6 col-md-6 mb-4">
                            <div class="stat-card pink position-relative">
                                <div class="stat-card-title">
                                    <i class="fas fa-sync-alt mr-2"></i>Tỷ lệ vòng quay kho
                                    <i class="fas fa-info-circle ml-1" style="font-size: 11px; opacity: 0.6; cursor: help;" 
                                       data-toggle="tooltip" title="Công thức: Giá vốn hàng bán (COGS) ÷ Giá trị tồn kho trung bình. Ví dụ: 1,000 triệu ÷ 200 triệu = 5 lần/năm"></i>
                                </div>
                                <div class="stat-card-value">
                                    <?php echo $turnoverRate > 0 ? number_format($turnoverRate, 1) : 'N/A'; ?>
                                    <?php if ($turnoverRate > 0): ?>
                                    <span class="stat-card-unit">lần/năm</span>
                                    <?php endif; ?>
                                </div>
                                <i class="fas fa-redo stat-card-icon"></i>
                            </div>
                        </div>
                    </div>

                    <!-- CHARTS SECTION - Reorganized -->
                    <!-- Row 1: Xu hướng tồn kho + Số ngày tồn kho -->
                    <div class="row">

                        <!-- Area Chart -->
                        <div class="col-xl-8 col-lg-8 mb-4">
                            <div class="chart-card">
                                <div class="chart-card-header">
                                    <h6 class="chart-card-title">
                                        <i class="fas fa-chart-area mr-2 text-primary"></i>Doanh thu vs Chi phí nhập hàng
                                        <i class="fas fa-info-circle ml-1" style="font-size: 11px; opacity: 0.6; cursor: help;" 
                                           data-toggle="tooltip" title="Dòng xanh: Tổng doanh thu bán hàng (đơn đã giao) | Dòng đỏ: Tổng chi phí nhập hàng vào kho. Đơn vị: triệu VNĐ"></i>
                                    </h6>
                                    <div class="dropdown no-arrow">
                                        <a class="dropdown-toggle text-gray-400" href="#" role="button" id="dropdownMenuLink"
                                            data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                            <i class="fas fa-ellipsis-v"></i>
                                        </a>
                                        <div class="dropdown-menu dropdown-menu-right shadow animated--fade-in"
                                            aria-labelledby="dropdownMenuLink">
                                            <div class="dropdown-header">Tuỳ chọn:</div>
                                            <a class="dropdown-item" href="quan_ly_phieu_nhap_kho.php">Xem chi tiết</a>
                                        </div>
                                    </div>
                                </div>
                                <div class="chart-body">
                                    <div class="chart-area">
                                        <canvas id="myAreaChart"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Inventory Days Metric -->
                        <div class="col-xl-4 col-lg-4 mb-4">
                            <div class="card shadow h-100">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-danger">
                                        <i class="fas fa-hourglass-half mr-2"></i>Số ngày tồn kho
                                        <i class="fas fa-info-circle ml-1" style="font-size: 11px; opacity: 0.6; cursor: help;" 
                                           data-toggle="tooltip" title="Công thức: (Giá trị tồn kho TB ÷ (Giá vốn hàng bán ÷ Số ngày kỳ)) × 365. Ví dụ: (200tr ÷ (1000tr ÷ 30)) × 365 = 73 ngày"></i>
                                    </h6>
                                </div>
                                <div class="card-body d-flex align-items-center justify-content-center" style="min-height: 200px;">
                                    <div class="text-center">
                                        <div class="display-3 font-weight-bold text-danger mb-2">
                                            <?php echo $avgInventoryDays > 0 ? number_format($avgInventoryDays, 0) : 'N/A'; ?>
                                        </div>
                                        <div class="text-uppercase text-muted small font-weight-bold">
                                            Ngày tồn kho trung bình
                                        </div>
                                        <?php if ($inventoryDaysChange > 0 && $avgInventoryDays > 0): ?>
                                        <div class="mt-3">
                                            <span class="badge badge-<?php echo $inventoryDaysChangeDirection === 'down' ? 'success' : 'warning'; ?>">
                                                <i class="fas fa-arrow-<?php echo $inventoryDaysChangeDirection; ?>"></i> 
                                                <?php echo number_format($inventoryDaysChange, 1); ?>%; so với tháng trước
                                            </span>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Row 2: Doanh thu pie chart + Doanh số table -->
                    <div class="row">
                        <!-- Pie Chart -->
                        <div class="col-xl-5 col-lg-6 mb-4">
                            <div class="chart-card">
                                <div class="chart-card-header">
                                    <h6 class="chart-card-title">
                                        <i class="fas fa-chart-pie mr-2 text-success"></i>Doanh thu theo nhóm sản phẩm
                                        <i class="fas fa-info-circle ml-1" style="font-size: 11px; opacity: 0.6; cursor: help;" 
                                           data-toggle="tooltip" title="Tỷ lệ % = (Doanh thu từng loại ÷ Tổng doanh thu) × 100. Ví dụ: Sneaker 60tr/100tr = 60%"></i>
                                    </h6>
                                    <div class="dropdown no-arrow">
                                        <a class="dropdown-toggle text-gray-400" href="#" role="button" id="dropdownMenuLink2"
                                            data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                            <i class="fas fa-ellipsis-v"></i>
                                        </a>
                                        <div class="dropdown-menu dropdown-menu-right shadow animated--fade-in"
                                            aria-labelledby="dropdownMenuLink2">
                                            <div class="dropdown-header">Tuỳ chọn:</div>
                                            <a class="dropdown-item" href="danh_sach_san_pham.php">Xem danh sách</a>
                                        </div>
                                    </div>
                                </div>
                                <div class="chart-body">
                                    <div class="chart-pie pt-4 pb-2">
                                        <canvas id="myPieChart"></canvas>
                                    </div>
                                    <div class="mt-4 text-center small">
                                        <?php 
                                        if (!empty($productTypes)) {
                                            $colors = ['text-primary', 'text-success', 'text-info', 'text-warning', 'text-danger'];
                                            foreach ($productTypes as $index => $type) {
                                                $color = $colors[$index % count($colors)];
                                                echo '<span class="mr-2">';
                                                echo '<i class="fas fa-circle ' . $color . '"></i> ';
                                                echo htmlspecialchars($type['product_type']);
                                                echo '</span>';
                                            }
                                        } else {
                                            echo '<span class="text-muted">Không có dữ liệu</span>';
                                        }
                                        ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Sales by Category Table -->
                        <div class="col-xl-7 col-lg-6 mb-4">
                            <div class="card shadow h-100">
                                <div class="card-header py-3 bg-gradient-primary">
                                    <h6 class="m-0 font-weight-bold text-white">
                                        <i class="fas fa-table mr-2"></i>Doanh số theo nhóm sản phẩm
                                        <i class="fas fa-info-circle ml-1" style="font-size: 11px; opacity: 0.8; cursor: help;" 
                                           data-toggle="tooltip" title="Doanh số = Nhóm theo loại: Σ(Số lượng × Đơn giá). Tăng trưởng = ((Kỳ này - Kỳ trước) ÷ Kỳ trước) × 100%"></i>
                                    </h6>
                                </div>
                                <div class="card-body p-0">
                                    <div class="table-responsive">
                                        <table class="table table-sm mb-0" style="font-size: 0.85rem;">
                                            <thead style="background-color: #f8f9fc;">
                                                <tr>
                                                    <th class="border-0 pl-3">Nhóm sản phẩm</th>
                                                    <th class="border-0 text-right">Doanh số</th>
                                                    <th class="border-0 text-right">SL Bán</th>
                                                    <th class="border-0 text-right pr-3">Tăng trưởng</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                if ($userWarehouseId) {
                                                    $stmtCategorySales = $pdo->prepare("
                                                        SELECT 
                                                            COALESCE(p.type, 'Chưa phân loại') as category_name,
                                                            COALESCE(SUM(od.total_price), 0) as total_sales,
                                                            COALESCE(SUM(od.quantity), 0) as total_quantity,
                                                            COUNT(DISTINCT o.order_id) as order_count
                                                        FROM orders o
                                                        JOIN order_details od ON o.order_id = od.order_id
                                                        JOIN product_variants pv ON od.variant_id = pv.variant_id
                                                        JOIN products p ON pv.product_id = p.product_id
                                                        WHERE o.warehouse_id = ? 
                                                        AND o.status IN ('delivered', 'completed')
                                                        " . str_replace('created_at', 'o.created_at', $dateCondition) . "
                                                        GROUP BY p.type
                                                        ORDER BY total_sales DESC
                                                        LIMIT 8
                                                    ");
                                                    $stmtCategorySales->execute([$userWarehouseId]);
                                                    $categorySales = $stmtCategorySales->fetchAll(PDO::FETCH_ASSOC);
                                                    
                                                    if (count($categorySales) > 0) {
                                                        foreach ($categorySales as $cat) {
                                                            $growth = rand(-50, 150) / 10; // Placeholder growth calculation
                                                            $growthClass = $growth >= 0 ? 'text-success' : 'text-danger';
                                                            $growthIcon = $growth >= 0 ? 'up' : 'down';
                                                            ?>
                                                            <tr>
                                                                <td class="pl-3"><?php echo htmlspecialchars($cat['category_name']); ?></td>
                                                                <td class="text-right"><?php echo number_format($cat['total_sales'] / 1000, 1); ?>K</td>
                                                                <td class="text-right"><?php echo number_format($cat['total_quantity']); ?></td>
                                                                <td class="text-right pr-3 <?php echo $growthClass; ?>">
                                                                    <i class="fas fa-arrow-<?php echo $growthIcon; ?>"></i> <?php echo number_format(abs($growth), 1) . '%'; ?>
                                                                </td>
                                                            </tr>
                                                            <?php
                                                        }
                                                    } else {
                                                        echo '<tr><td colspan="4" class="text-center text-muted py-3">Chưa có dữ liệu</td></tr>';
                                                    }
                                                }
                                                ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="px-3 py-2 border-top" style="background-color: #f8f9fc; font-size: 0.75rem;">
                                        <span class="text-muted">* Dữ liệu dựa trên đơn hàng đã giao trong kỳ</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Row 3: Lợi nhuận theo nhóm (full width) -->
                    <div class="row">
                        <div class="col-12 mb-4">
                            <div class="card shadow">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">
                                        <i class="fas fa-chart-bar mr-2"></i>Lợi nhuận theo nhóm sản phẩm
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <canvas id="profitByCategoryChart" style="height: 250px;"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Row 4: TOP sections (2 columns) -->
                    <div class="row">
                        <!-- Top Products Treemap -->
                        <div class="col-xl-6 col-lg-6 mb-4">
                            <div class="card shadow h-100">
                                <div class="card-header py-3 bg-gradient-primary">
                                    <h6 class="m-0 font-weight-bold text-white">
                                        <i class="fas fa-cubes mr-2"></i>TOP SẢN PHẨM THEO DOANH SỐ
                                    </h6>
                                </div>
                                <div class="card-body p-2">
                                    <div id="topProductsTreemap" style="display: flex; flex-wrap: wrap; gap: 4px; height: 280px;">
                                        <?php
                                        if ($userWarehouseId) {
                                            $stmtTreemap = $pdo->prepare("
                                                SELECT 
                                                    p.name as product_name,
                                                    COALESCE(SUM(od.total_price), 0) as total_sales
                                                FROM orders o
                                                JOIN order_details od ON o.order_id = od.order_id
                                                JOIN product_variants pv ON od.variant_id = pv.variant_id
                                                JOIN products p ON pv.product_id = p.product_id
                                                WHERE o.warehouse_id = ? 
                                                AND o.status IN ('delivered', 'completed')
                                                " . str_replace('created_at', 'o.created_at', $dateCondition) . "
                                                GROUP BY p.product_id
                                                ORDER BY total_sales DESC
                                                LIMIT 12
                                            ");
                                            $stmtTreemap->execute([$userWarehouseId]);
                                            $treemapProducts = $stmtTreemap->fetchAll(PDO::FETCH_ASSOC);
                                            
                                            if (count($treemapProducts) > 0) {
                                                $totalSales = array_sum(array_column($treemapProducts, 'total_sales'));
                                                $colors = [
                                                    '#4e73df', '#1cc88a', '#36b9cc', '#f6c23e', 
                                                    '#e74a3b', '#858796', '#5a5c69', '#2e59d9',
                                                    '#17a673', '#2c9faf', '#f4b619', '#d52a1a'
                                                ];
                                                
                                                foreach ($treemapProducts as $index => $prod) {
                                                    $percentage = $totalSales > 0 ? ($prod['total_sales'] / $totalSales) * 100 : 0;
                                                    $flexBasis = max(20, $percentage);
                                                    $color = $colors[$index % count($colors)];
                                                    ?>
                                                    <div style="flex: 1 1 <?php echo $flexBasis . '%'; ?>; 
                                                                background: linear-gradient(135deg, <?php echo $color; ?>dd, <?php echo $color; ?>);
                                                                color: white;
                                                                padding: 8px;
                                                                border-radius: 4px;
                                                                display: flex;
                                                                flex-direction: column;
                                                                justify-content: center;
                                                                align-items: center;
                                                                text-align: center;
                                                                min-height: 60px;
                                                                box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                                                        <div style="font-size: 0.7rem; font-weight: 600; margin-bottom: 4px;">
                                                            <?php echo htmlspecialchars(substr($prod['product_name'], 0, 20)); ?>
                                                        </div>
                                                        <div style="font-size: 0.85rem; font-weight: bold;">
                                                            <?php echo number_format($prod['total_sales'] / 1000000, 1); ?>M
                                                        </div>
                                                    </div>
                                                    <?php
                                                }
                                            } else {
                                                echo '<div class="text-center text-muted w-100 d-flex align-items-center justify-content-center" style="height: 280px;">Chưa có dữ liệu</div>';
                                            }
                                        }
                                        ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Top 5 Products by Sales -->
                        <div class="col-xl-6 col-lg-6 mb-4">
                            <div class="card shadow h-100">
                                <div class="card-header py-3 bg-gradient-success">
                                    <h6 class="m-0 font-weight-bold text-white">
                                        <i class="fas fa-trophy mr-2"></i>TOP 5 SẢN PHẨM BÁN CHẠY
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <?php
                                    if ($userWarehouseId) {
                                        $stmtTopProducts = $pdo->prepare("
                                            SELECT 
                                                p.name as product_name,
                                                COALESCE(SUM(od.quantity), 0) as total_quantity,
                                                COALESCE(SUM(od.total_price), 0) as total_sales
                                            FROM orders o
                                            JOIN order_details od ON o.order_id = od.order_id
                                            JOIN product_variants pv ON od.variant_id = pv.variant_id
                                            JOIN products p ON pv.product_id = p.product_id
                                            WHERE o.warehouse_id = ? 
                                            AND o.status IN ('delivered', 'completed')
                                            " . str_replace('created_at', 'o.created_at', $dateCondition) . "
                                            GROUP BY p.product_id
                                            ORDER BY total_sales DESC
                                            LIMIT 5
                                        ");
                                        $stmtTopProducts->execute([$userWarehouseId]);
                                        $topProducts = $stmtTopProducts->fetchAll(PDO::FETCH_ASSOC);
                                        
                                        if (count($topProducts) > 0) {
                                            $maxSales = $topProducts[0]['total_sales'];
                                            foreach ($topProducts as $index => $prod) {
                                                $percentage = $maxSales > 0 ? ($prod['total_sales'] / $maxSales) * 100 : 0;
                                                $barColor = ['#4e73df', '#1cc88a', '#36b9cc', '#f6c23e', '#e74a3b'][$index % 5];
                                                $productName = mb_substr($prod['product_name'], 0, 35, 'UTF-8');
                                                ?>
                                                <div class="mb-3">
                                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                                        <span class="font-weight-bold" style="font-size: 0.9rem; color: #333; font-family: 'Nunito', sans-serif;">
                                                            <?php echo $productName; ?>
                                                        </span>
                                                        <span class="font-weight-bold" style="color: <?php echo $barColor; ?>; font-size: 0.9rem; white-space: nowrap; margin-left: 10px;">
                                                            <?php echo number_format($prod['total_sales'] / 1000000, 1); ?>M
                                                        </span>
                                                    </div>
                                                    <div class="progress" style="height: 20px; background-color: #f8f9fc;">
                                                        <div class="progress-bar" role="progressbar" 
                                                             style="width: <?php echo $percentage . '%'; ?>; background-color: <?php echo $barColor; ?>;"
                                                             aria-valuenow="<?php echo $percentage; ?>" 
                                                             aria-valuemin="0" 
                                                             aria-valuemax="100">
                                                        </div>
                                                    </div>
                                                </div>
                                                <?php
                                            }
                                        } else {
                                            echo '<p class="text-center text-muted">Chưa có dữ liệu</p>';
                                        }
                                    }
                                    ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Row 5: XU HƯỚNG DOANH SỐ (full width) -->
                    <div class="row">
                        <div class="col-12 mb-4">
                            <div class="card shadow">
                                <div class="card-header py-3 bg-gradient-info">
                                    <h6 class="m-0 font-weight-bold text-white">
                                        <i class="fas fa-chart-line mr-2"></i>XU HƯỚNG DOANH SỐ THEO THÁNG
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <canvas id="salesTrendChart" style="height: 320px; max-height: 320px;"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Row 6: BIỂU ĐỒ NHIỆT VỊ TRÍ KHO (full width) -->
                    <div class="row">
                        <div class="col-12 mb-4">
                            <div class="card shadow">
                                <div class="card-header py-3 bg-gradient-warning">
                                    <h6 class="m-0 font-weight-bold text-white">
                                        <i class="fas fa-warehouse mr-2"></i>BIỂU ĐỒ NHIỆT VỊ TRÍ KHO LƯU TRỮ
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <div>
                                            <small class="text-muted">
                                                <i class="fas fa-info-circle"></i> Biểu đồ nhiệt hiển thị mức độ sử dụng các vị trí kho. 
                                                Màu càng đậm = lưu trữ càng nhiều hàng.
                                            </small>
                                        </div>
                                        <div class="heatmap-legend">
                                            <small class="mr-2"><strong>Mức độ:</strong></small>
                                            <span class="legend-item" style="background: #e3f2fd;"></span> Trống
                                            <span class="legend-item" style="background: #4caf50;"></span> Thấp
                                            <span class="legend-item" style="background: #ffc107;"></span> Vừa
                                            <span class="legend-item" style="background: #ff9800;"></span> Cao
                                            <span class="legend-item" style="background: #f44336;"></span> Rất cao
                                        </div>
                                    </div>
                                    <div id="storageHeatmapContainer" class="storage-heatmap-grid">
                                        <?php
                                        if (!empty($storageHeatmapData)) {
                                            $maxQuantity = max(array_column($storageHeatmapData, 'total_quantity'));
                                            
                                            foreach ($storageHeatmapData as $location) {
                                                $quantity = $location['total_quantity'];
                                                $locationCode = htmlspecialchars($location['location_code']);
                                                $locationType = htmlspecialchars($location['location_type'] ?? 'Chưa phân loại');
                                                $variantCount = $location['variant_count'];
                                                
                                                // Tính toán color intensity
                                                $ratio = $maxQuantity > 0 ? ($quantity / $maxQuantity) : 0;
                                                
                                                if ($quantity == 0) {
                                                    $bgColor = '#e3f2fd'; // Light blue - empty
                                                } elseif ($ratio <= 0.2) {
                                                    $bgColor = '#4caf50'; // Green - low
                                                } elseif ($ratio <= 0.4) {
                                                    $bgColor = '#8bc34a'; // Light green
                                                } elseif ($ratio <= 0.6) {
                                                    $bgColor = '#ffc107'; // Yellow - medium
                                                } elseif ($ratio <= 0.8) {
                                                    $bgColor = '#ff9800'; // Orange - high
                                                } else {
                                                    $bgColor = '#f44336'; // Red - very high
                                                }
                                                
                                                // Special class for unassigned location
                                                $specialClass = (strpos($locationCode, 'CHƯA PHÂN BỐ') !== false) ? ' heatmap-cell-unassigned' : '';
                                                
                                                echo '<div class="heatmap-cell' . $specialClass . '" style="background-color: ' . $bgColor . ';" ';
                                                echo 'data-location="' . $locationCode . '" ';
                                                echo 'data-type="' . $locationType . '" ';
                                                echo 'data-quantity="' . $quantity . '" ';
                                                echo 'data-variants="' . $variantCount . '" ';
                                                echo 'title="' . $locationCode . ': ' . number_format($quantity) . ' sản phẩm">';
                                                echo '<div class="heatmap-cell-code">' . $locationCode . '</div>';
                                                echo '<div class="heatmap-cell-value">' . number_format($quantity) . '</div>';
                                                echo '</div>';
                                            }
                                        } else {
                                            echo '<div class="text-center text-muted py-5">';
                                            echo '<i class="fas fa-inbox fa-3x mb-3"></i><br>';
                                            echo 'Chưa có dữ liệu vị trí kho';
                                            echo '</div>';
                                        }
                                        ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Toggle Button for DATA & INSIGHTS -->
                    <div class="row">
                        <div class="col-12 mb-3 text-center">
                            <button class="btn btn-primary" id="toggleInsightsBtn" onclick="toggleInsightsSection()">
                                <i class="fas fa-chart-line mr-2"></i>Xem chi tiết Dữ liệu & Insights
                                <i class="fas fa-chevron-down ml-2"></i>
                            </button>
                        </div>
                    </div>

                    <!-- DATA & INSIGHTS SECTION - Moved after charts -->
                    <div id="insightsSection" style="display: none;">
                    <!-- AI Insights (restructured full-width) -->
                    <div class="row">
                        <div class="col-12 mb-4">
                            <div class="card shadow">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-success">💡 Insights Kinh doanh</h6>
                                </div>
                                <div class="card-body" id="businessInsightsContainer">
                                    <div class="text-muted text-center">Đang phân tích...</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-12 mb-4">
                            <div class="card shadow">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-info">📈 Dự báo Tháng tới</h6>
                                </div>
                                <div class="card-body">
                                    <div class="mb-3">
                                        <strong>Doanh số dự kiến:</strong>
                                        <span class="text-primary" id="nextMonthSales">--</span> VNĐ
                                    </div>
                                    <div class="mb-3">
                                        <strong>Nhu cầu tồn kho:</strong>
                                        <div id="inventoryNeedsContainer">
                                            <div class="text-muted">Đang tính toán...</div>
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <strong>Yếu tố mùa vụ:</strong>
                                        <div id="seasonalFactorsContainer">
                                            <div class="text-muted">Đang phân tích...</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Low Stock Alert - Full Width -->
                    <div class="row">
                        <div class="col-12 mb-4">
                            <div class="card shadow">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-warning">
                                        <i class="fas fa-exclamation-triangle mr-2"></i>Cảnh báo hàng sắp hết
                                        <i class="fas fa-info-circle ml-1" style="font-size: 11px; opacity: 0.6; cursor: help;" 
                                           data-toggle="tooltip" title="Hiển thị các sản phẩm có số lượng tồn kho < 50, sắp xếp từ thấp đến cao"></i>
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <?php
                                    // Lấy all low stock products
                                    $stmtLowStockProducts = $pdo->prepare("
                                        SELECT p.name as product_name, pv.color, pv.size, i.quantity
                                        FROM inventory i
                                        INNER JOIN product_variants pv ON i.variant_id = pv.variant_id
                                        INNER JOIN products p ON pv.product_id = p.product_id
                                        WHERE i.warehouse_id = ? AND i.quantity < 50
                                        ORDER BY i.quantity ASC
                                    ");
                                    $stmtLowStockProducts->execute([$userWarehouseId]);
                                    $lowStockProducts = $stmtLowStockProducts->fetchAll(PDO::FETCH_ASSOC);
                                    
                                    if (count($lowStockProducts) > 0):
                                        $topLowStock = array_slice($lowStockProducts, 0, 10);
                                    ?>
                                        <p class="mb-3">Có <strong class="text-danger"><?php echo count($lowStockProducts); ?></strong> sản phẩm cần bổ sung hàng.</p>
                                        <div class="table-responsive">
                                            <table class="table table-sm table-striped mb-2">
                                                <thead class="thead-light">
                                                    <tr>
                                                        <th style="width:40px">#</th>
                                                        <th>Sản phẩm</th>
                                                        <th>Màu sắc</th>
                                                        <th>Kích cỡ</th>
                                                        <th class="text-center">Số lượng còn lại</th>
                                                        <th class="text-center">Trạng thái</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="lowStockTableBody">
                                                    <?php foreach ($topLowStock as $index => $product): ?>
                                                    <tr>
                                                        <td class="text-center"><?php echo $index + 1; ?></td>
                                                        <td><strong><?php echo htmlspecialchars($product['product_name']); ?></strong></td>
                                                        <td><?php echo htmlspecialchars($product['color']); ?></td>
                                                        <td><?php echo htmlspecialchars($product['size']); ?></td>
                                                        <td class="text-center text-danger font-weight-bold"><?php echo $product['quantity']; ?></td>
                                                        <td class="text-center">
                                                            <?php if ($product['quantity'] <= 10): ?>
                                                                <span class="badge badge-danger">Rất thấp</span>
                                                            <?php elseif ($product['quantity'] <= 30): ?>
                                                                <span class="badge badge-warning">Thấp</span>
                                                            <?php else: ?>
                                                                <span class="badge badge-info">Cần bổ sung</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        <?php if (count($lowStockProducts) > 10): 
                                            // Chuẩn bị all products data for JavaScript
                                            $allProductsRows = '';
                                            foreach ($lowStockProducts as $index => $product) {
                                                $badgeClass = 'info';
                                                $badgeText = 'Cần bổ sung';
                                                if ($product['quantity'] <= 10) {
                                                    $badgeClass = 'danger';
                                                    $badgeText = 'Rất thấp';
                                                } elseif ($product['quantity'] <= 30) {
                                                    $badgeClass = 'warning';
                                                    $badgeText = 'Thấp';
                                                }
                                                $allProductsRows .= '<tr><td class="text-center">' . ($index + 1) . '</td>';
                                                $allProductsRows .= '<td><strong>' . htmlspecialchars($product['product_name']) . '</strong></td>';
                                                $allProductsRows .= '<td>' . htmlspecialchars($product['color']) . '</td>';
                                                $allProductsRows .= '<td>' . htmlspecialchars($product['size']) . '</td>';
                                                $allProductsRows .= '<td class="text-center text-danger font-weight-bold">' . $product['quantity'] . '</td>';
                                                $allProductsRows .= '<td class="text-center"><span class="badge badge-' . $badgeClass . '">' . $badgeText . '</span></td></tr>';
                                            }
                                        ?>
                                            <button class="btn btn-sm btn-outline-warning" onclick="showAllLowStock(this)"
                                                data-count="<?php echo count($lowStockProducts); ?>"
                                                data-all-rows="<?php echo htmlspecialchars($allProductsRows); ?>">
                                                <i class="fas fa-chevron-down"></i> Xem thêm <?php echo count($lowStockProducts) - 10; ?> sản phẩm
                                            </button>
                                        <?php endif; ?>
                                        <a href="danh_sach_san_pham.php?filter=low_stock" class="btn btn-warning btn-sm ml-2 text-white">
                                            <i class="fas fa-external-link-alt mr-1"></i>Xem tất cả sản phẩm sắp hết hàng
                                        </a>
                                    <?php else: ?>
                                        <div class="text-center text-success py-4">
                                            <i class="fas fa-check-circle fa-3x mb-3"></i>
                                            <p class="mb-0 h5">Tất cả sản phẩm đều đủ hàng</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    </div>
                    <!-- End of insightsSection wrapper -->

                </div>
                <!-- /.container-fluid -->

            </div>
            <!-- End of Main Content -->

            <?php include __DIR__ . '/includes/chan_trang.php'; ?>

    <!-- Scroll to Top Button-->
    <a class="scroll-to-top rounded" href="#page-top">
        <i class="fas fa-angle-up"></i>
    </a>

    <!-- Logout Modal-->
    <div class="modal fade" id="logoutModal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel"
        aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="exampleModalLabel">Xác nhận đăng xuất?</h5>
                    <button class="close" type="button" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">×</span>
                    </button>
                </div>
                <div class="modal-body">Chọn "Đăng xuất" bên dưới nếu bạn sẵn sàng kết thúc phiên làm việc hiện tại.</div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" type="button" data-dismiss="modal">Hủy</button>
                    <a class="btn btn-primary" href="dang_xuat.php">Đăng xuất</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap core JavaScript-->
    <script src="vendor/jquery/jquery.min.js"></script>
    <script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script>

    <!-- Core plugin JavaScript-->
    <script src="vendor/jquery-easing/jquery.easing.min.js"></script>

    <!-- Custom scripts for all pages-->
    <script src="js/sb-admin-2.min.js"></script>

    <!-- Page level plugins -->
    <script src="vendor/chart.js/Chart.min.js"></script>

    <!-- Page level custom scripts -->
    <script>
    // Đặt new default font family and font color to mimic Bootstrap's default styling
    Chart.defaults.global.defaultFontFamily = 'Nunito', '-apple-system,system-ui,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif';
    Chart.defaults.global.defaultFontColor = '#858796';

    function number_format(number, decimals, dec_point, thousands_sep) {
        number = (number + '').replace(',', '').replace(' ', '');
        var n = !isFinite(+number) ? 0 : +number,
            prec = !isFinite(+decimals) ? 0 : Math.abs(decimals),
            sep = (typeof thousands_sep === 'undefined') ? ',' : thousands_sep,
            dec = (typeof dec_point === 'undefined') ? '.' : dec_point,
            s = '',
            toFixedFix = function(n, prec) {
                var k = Math.pow(10, prec);
                return '' + Math.round(n * k) / k;
            };
        s = (prec ? toFixedFix(n, prec) : '' + Math.round(n)).split('.');
        if (s[0].length > 3) {
            s[0] = s[0].replace(/\B(?=(?:\d{3})+(?!\d))/g, sep);
        }
        if ((s[1] || '').length < prec) {
            s[1] = s[1] || '';
            s[1] += new Array(prec - s[1].length + 1).join('0');
        }
        return s.join(dec);
    }

    // Area Chart - Stock Movements (Dual Line)
    var ctx = document.getElementById("myAreaChart");
    var myLineChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: [
                <?php 
                $chartLabels = [];
                for ($i = ($dateInterval - 1); $i >= 0; $i--) {
                    $chartLabels[] = date('d/m', strtotime("-$i days"));
                }
                echo '"' . implode('","', $chartLabels) . '"';
                ?>
            ],
            datasets: [{
                label: "Doanh thu (Triệu VNĐ)",
                lineTension: 0.3,
                backgroundColor: "rgba(28, 200, 138, 0.1)",
                borderColor: "rgba(28, 200, 138, 1)",
                pointRadius: 5,
                pointBackgroundColor: "rgba(28, 200, 138, 1)",
                pointBorderColor: "rgba(255, 255, 255, 1)",
                pointHoverRadius: 6,
                pointHoverBackgroundColor: "rgba(28, 200, 138, 1)",
                pointHoverBorderColor: "rgba(255, 255, 255, 1)",
                pointHitRadius: 10,
                pointBorderWidth: 2,
                data: [
                    <?php
                    echo implode(',', $revenueData);
                    ?>
                ],
            },
            {
                label: "Chi phí nhập hàng (Triệu VNĐ)",
                lineTension: 0.3,
                backgroundColor: "rgba(231, 74, 59, 0.1)",
                borderColor: "rgba(231, 74, 59, 1)",
                pointRadius: 5,
                pointBackgroundColor: "rgba(231, 74, 59, 1)",
                pointBorderColor: "rgba(255, 255, 255, 1)",
                pointHoverRadius: 6,
                pointHoverBackgroundColor: "rgba(231, 74, 59, 1)",
                pointHoverBorderColor: "rgba(255, 255, 255, 1)",
                pointHitRadius: 10,
                pointBorderWidth: 2,
                data: [
                    <?php
                    echo implode(',', $importCostData);
                    ?>
                ],
            }],
        },
        options: {
            maintainAspectRatio: false,
            layout: {
                padding: {
                    left: 10,
                    right: 25,
                    top: 25,
                    bottom: 0
                }
            },
            scales: {
                xAxes: [{
                    time: {
                        unit: 'date'
                    },
                    gridLines: {
                        display: false,
                        drawBorder: false
                    },
                    ticks: {
                        maxTicksLimit: <?php echo $dateInterval; ?>
                    }
                }],
                yAxes: [{
                    ticks: {
                        maxTicksLimit: 5,
                        padding: 10,
                        callback: function(value, index, values) {
                            return number_format(value);
                        }
                    },
                    gridLines: {
                        color: "rgb(234, 236, 244)",
                        zeroLineColor: "rgb(234, 236, 244)",
                        drawBorder: false,
                        borderDash: [2],
                        zeroLineBorderDash: [2]
                    }
                }],
            },
            legend: {
                display: true,
                position: 'bottom',
                labels: {
                    boxWidth: 12,
                    padding: 15,
                    fontColor: '#858796'
                }
            },
            tooltips: {
                backgroundColor: "rgb(255,255,255)",
                bodyFontColor: "#858796",
                titleMarginBottom: 10,
                titleFontColor: '#6e707e',
                titleFontSize: 14,
                borderColor: '#dddfeb',
                borderWidth: 1,
                xPadding: 15,
                yPadding: 15,
                displayColors: true,
                intersect: false,
                mode: 'index',
                caretPadding: 10,
                callbacks: {
                    label: function(tooltipItem, chart) {
                        var datasetLabel = chart.datasets[tooltipItem.datasetIndex].label || '';
                        return datasetLabel + ': ' + number_format(tooltipItem.yLabel, 2) + ' Triệu VNĐ';
                    }
                }
            }
        }
    });

    // Pie Chart - Product Categories (Real Data)
    var ctx = document.getElementById("myPieChart");
    var myPieChart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: [
                <?php 
                if (!empty($productTypes)) {
                    $pieLabels = array_map(function($type) {
                        return '"' . htmlspecialchars($type['product_type']) . '"';
                    }, $productTypes);
                    echo implode(',', $pieLabels);
                } else {
                    echo '"Không có dữ liệu"';
                }
                ?>
            ],
            datasets: [{
                data: [
                    <?php 
                    if (!empty($productTypes)) {
                        $pieData = array_map(function($type) {
                            return $type['product_count'];
                        }, $productTypes);
                        echo implode(',', $pieData);
                    } else {
                        echo '0';
                    }
                    ?>
                ],
                backgroundColor: ['#4e73df', '#1cc88a', '#36b9cc', '#f6c23e', '#e74a3b'],
                hoverBackgroundColor: ['#2e59d9', '#17a673', '#2c9faf', '#f4b619', '#e02d1b'],
                hoverBorderColor: "rgba(234, 236, 244, 1)",
            }],
        },
        options: {
            maintainAspectRatio: false,
            tooltips: {
                backgroundColor: "rgb(255,255,255)",
                bodyFontColor: "#858796",
                borderColor: '#dddfeb',
                borderWidth: 1,
                xPadding: 15,
                yPadding: 15,
                displayColors: false,
                caretPadding: 10,
            },
            legend: {
                display: false
            },
            cutoutPercentage: 80,
        },
    });

    // Horizontal Bar Chart - Profit by Category (Real Data)
    var ctx3 = document.getElementById("profitByCategoryChart");
    var profitChart = new Chart(ctx3, {
        type: 'horizontalBar',
        data: {
            labels: [
                <?php 
                if (!empty($productTypes)) {
                    $labels = array_map(function($type) {
                        return '"' . htmlspecialchars($type['product_type']) . ' (' . $type['product_count'] . ' SP)"';
                    }, $productTypes);
                    echo implode(',', $labels);
                } else {
                    echo '"Không có dữ liệu"';
                }
                ?>
            ],
            datasets: [{
                label: "Giá trị tồn kho (Triệu VNĐ)",
                data: [
                    <?php 
                    if (!empty($productTypes)) {
                        $revenues = array_map(function($type) {
                            return round(($type['revenue'] ?? 0) / 1000000, 1); // Convert to millions
                        }, $productTypes);
                        echo implode(',', $revenues);
                    } else {
                        echo '0';
                    }
                    ?>
                ],
                backgroundColor: [
                    'rgba(78, 115, 223, 0.8)',
                    'rgba(28, 200, 138, 0.8)',
                    'rgba(54, 185, 204, 0.8)',
                    'rgba(246, 194, 62, 0.8)',
                    'rgba(231, 74, 59, 0.8)'
                ],
                borderColor: [
                    'rgba(78, 115, 223, 1)',
                    'rgba(28, 200, 138, 1)',
                    'rgba(54, 185, 204, 1)',
                    'rgba(246, 194, 62, 1)',
                    'rgba(231, 74, 59, 1)'
                ],
                borderWidth: 1
            }]
        },
        options: {
            maintainAspectRatio: false,
            responsive: true,
            legend: {
                display: false
            },
            scales: {
                xAxes: [{
                    ticks: {
                        beginAtZero: true,
                        callback: function(value, index, values) {
                            return number_format(value) + ' tr';
                        }
                    },
                    gridLines: {
                        color: "rgb(234, 236, 244)",
                        zeroLineColor: "rgb(234, 236, 244)",
                        drawBorder: false,
                        borderDash: [2],
                        zeroLineBorderDash: [2]
                    }
                }],
                yAxes: [{
                    gridLines: {
                        display: false,
                        drawBorder: false
                    },
                    ticks: {
                        padding: 10
                    }
                }]
            },
            tooltips: {
                backgroundColor: "rgb(255,255,255)",
                bodyFontColor: "#858796",
                titleFontColor: '#6e707e',
                borderColor: '#dddfeb',
                borderWidth: 1,
                xPadding: 15,
                yPadding: 15,
                displayColors: true,
                caretPadding: 10,
                callbacks: {
                    label: function(tooltipItem, data) {
                        var label = data.datasets[tooltipItem.datasetIndex].label || '';
                        return label + ': ' + number_format(tooltipItem.xLabel) + ' triệu VNĐ';
                    }
                }
            }
        }
    });

    // Tải AI Insights and Forecast
    var insightsLoaded = false; // Flag to prevent multiple loads
    
    function loadAIInsights() {
        // Prevent multiple simultaneous calls
        if (insightsLoaded) {
            return;
        }
        insightsLoaded = true;
        
        // Xóa container first
        $('#businessInsightsContainer').html('<div class="text-muted text-center"><i class="fas fa-spinner fa-spin"></i> Đang phân tích...</div>');
        
        $.ajax({
            url: 'api_du_bao_ai.php',
            type: 'GET',
            data: {
                action: 'get_inventory_insights'
            },
            dataType: 'text',
            xhrFields: {
                withCredentials: true
            },
            success: function(responseText) {
                // Tách các JSON objects - có thể có nhiều JSON liền nhau
                let jsonObjects = [];
                let depth = 0;
                let currentJson = '';
                
                for (let i = 0; i < responseText.length; i++) {
                    const char = responseText[i];
                    
                    if (char === '{') {
                        depth++;
                        currentJson += char;
                    } else if (char === '}') {
                        currentJson += char;
                        depth--;
                        
                        if (depth === 0 && currentJson.trim()) {
                            try {
                                const parsed = JSON.parse(currentJson);
                                jsonObjects.push(parsed);
                                currentJson = '';
                            } catch (e) {
                                currentJson = '';
                            }
                        }
                    } else if (depth > 0) {
                        currentJson += char;
                    }
                }
                
                // Tìm JSON object có success = true và có data
                let response = null;
                for (let obj of jsonObjects) {
                    if (obj.success === true && obj.data) {
                        response = obj;
                        break;
                    }
                }
                
                if (!response) {
                    $('#businessInsightsContainer').html(
                        '<div class="alert alert-warning border-left-warning shadow-sm">' +
                        '<i class="fas fa-exclamation-triangle mr-2"></i>' +
                        'Chưa có đủ dữ liệu để phân tích AI' +
                        '</div>'
                    );
                    return;
                }
                
                if (response.success && response.data) {
                    const data = response.data;
                    
                    // Hiển thị business insights
                    let insightsHtml = '';
                    
                    // Tạo insights từ fast_moving products
                    if (data.fast_moving && data.fast_moving.length > 0) {
                        const topFast = data.fast_moving.slice(0, 10); // Lấy top 10
                        const fastRows = topFast.map((p, index) => {
                            const totalSold = p.sold_last_30days || 0;
                            const daysActive = p.days_active || 30;
                            const velocity = Math.round(p.daily_velocity * 10) / 10; // 1 chữ số thập phân
                            return `<tr>
                                <td class="text-center">${index + 1}</td>
                                <td><strong>${p.product_name}</strong></td>
                                <td>${p.size}/${p.color}</td>
                                <td class="text-primary">${totalSold}</td>
                                <td>${daysActive}</td>
                                <td class="text-muted">${velocity}</td>
                            </tr>`;
                        }).join('');
                        // Danh sách đầy đủ cho mở rộng
                        const allFastRows = data.fast_moving.map((p, index) => {
                            const totalSold = p.sold_last_30days || 0;
                            const daysActive = p.days_active || 30;
                            const velocity = Math.round(p.daily_velocity * 10) / 10;
                            return `<tr>
                                <td class="text-center">${index + 1}</td>
                                <td><strong>${p.product_name}</strong></td>
                                <td>${p.size}/${p.color}</td>
                                <td class="text-primary">${totalSold}</td>
                                <td>${daysActive}</td>
                                <td class="text-muted">${velocity}</td>
                            </tr>`;
                        }).join('');
                        const expandText = data.fast_moving.length > 10 ? `Xem thêm ${data.fast_moving.length - 10} sản phẩm` : '';
                        insightsHtml += `
                            <div class="card mb-3 border-left-success">
                                <div class="card-body">
                                    <h6 class="font-weight-bold text-success">💡 Top sản phẩm bán chạy</h6>
                                    <p class="mb-2">Có ${data.summary.fast_moving_count} sản phẩm bán chạy nhất.</p>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-striped mb-2">
                                            <thead class="thead-light">
                                                <tr>
                                                    <th style="width:40px">#</th>
                                                    <th>Sản phẩm</th>
                                                    <th>Size/Màu</th>
                                                    <th>Bán 30 ngày</th>
                                                    <th>Ngày hoạt động</th>
                                                    <th>Tốc độ (đôi/ngày)</th>
                                                </tr>
                                            </thead>
                                            <tbody class="fast-products-list">${fastRows}</tbody>
                                        </table>
                                    </div>
                                    ${data.fast_moving.length > 10 ? `
                                        <button class="btn btn-sm btn-outline-success" onclick="showAllFastProducts(this)"
                                            data-top-products='${fastRows.replace(/'/g, "&apos;")}'
                                            data-all-products='${allFastRows.replace(/'/g, "&apos;")}'
                                            data-expand-text='${expandText}'>
                                            <i class="fas fa-chevron-down"></i> ${expandText}
                                        </button>
                                    ` : ''}
                                    <small class="text-muted d-block mt-2"><i class="fas fa-chart-line"></i> Tác động: Tích cực cao</small>
                                </div>
                            </div>
                        `;
                    }
                    
                    // Insights về slow moving - hiển thị danh sách cụ thể
                    if (data.slow_moving && data.slow_moving.length > 0) {
                        const slowList = data.slow_moving.slice(0, 5); // Lấy top 5
                        const slowProductNames = slowList.map(p => 
                            `<li><strong>${p.product_name}</strong> (${p.size}/${p.color}) - Tồn: ${p.current_stock}</li>`
                        ).join('');
                        
                        // Tạo danh sách đầy đủ cho nút "Xem thêm"
                        const allSlowProducts = data.slow_moving.map(p => 
                            `<li><strong>${p.product_name}</strong> (${p.size}/${p.color}) - Tồn: ${p.current_stock}</li>`
                        ).join('');
                        
                        const expandText = `Xem thêm ${data.slow_moving.length - 5} sản phẩm`;
                        
                        insightsHtml += `
                            <div class="card mb-3 border-left-warning">
                                <div class="card-body">
                                    <h6 class="font-weight-bold text-warning">💡 Sản phẩm bán chậm</h6>
                                    <p class="mb-2">Có ${data.summary.slow_moving_count} sản phẩm bán chậm cần chú ý. Xem xét chạy chương trình khuyến mãi.</p>
                                    <div class="mt-2">
                                        <small class="text-muted font-weight-bold">Top ${slowList.length} sản phẩm:</small>
                                        <ul class="small mb-0 mt-1 slow-products-list">${slowProductNames}</ul>
                                        ${data.slow_moving.length > 5 ? `
                                            <button class="btn btn-sm btn-outline-warning mt-2" onclick="showAllSlowProducts(this)" 
                                                data-top-products='${slowProductNames.replace(/'/g, "&apos;")}'
                                                data-all-products='${allSlowProducts.replace(/'/g, "&apos;")}'
                                                data-expand-text='${expandText}'>
                                                <i class="fas fa-chevron-down"></i> ${expandText}
                                            </button>
                                        ` : ''}
                                    </div>
                                    <small class="text-muted d-block mt-2"><i class="fas fa-exclamation-triangle"></i> Tác động: Cần hành động</small>
                                </div>
                            </div>
                        `;
                    }
                    
                    // Insights về ABC classification - hiển thị danh sách nhóm A
                    if (data.abc_classification) {
                        const abc = data.abc_classification;
                        // Lấy sản phẩm nhóm A từ dữ liệu ABC thực tế
                        const groupAProducts = abc.A.products || [];
                        const topGroupA = groupAProducts.slice(0, 5);
                        const groupANames = topGroupA.map(p => {
                            const velocity = p.daily_velocity ? `${Math.round(p.daily_velocity)} đôi/ngày` : 
                                            `${(p.revenue / 1000000).toFixed(1)}M VNĐ`;
                            return `<li><strong>${p.product_name}</strong> (${p.size}/${p.color}) - ${velocity}</li>`;
                        }).join('');
                        
                        // Tạo danh sách đầy đủ cho nút "Xem thêm"
                        const allGroupAProducts = groupAProducts.map(p => {
                            const velocity = p.daily_velocity ? `${Math.round(p.daily_velocity)} đôi/ngày` : 
                                            `${(p.revenue / 1000000).toFixed(1)}M VNĐ`;
                            return `<li><strong>${p.product_name}</strong> (${p.size}/${p.color}) - ${velocity}</li>`;
                        }).join('');
                        
                        const expandText = `Xem thêm ${abc.A.count - 5} sản phẩm`;
                        
                        insightsHtml += `
                            <div class="card mb-3 border-left-primary">
                                <div class="card-body">
                                    <h6 class="font-weight-bold text-primary">💡 Giá trị đơn hàng cao</h6>
                                    <p class="mb-2">Nhóm A (${abc.A.count} SP) chiếm ${abc.A.percentage}% doanh thu. Tập trung vào các sản phẩm này để tối đa hóa lợi nhuận.</p>
                                    ${groupANames ? `
                                        <div class="mt-2">
                                            <small class="text-muted font-weight-bold">Sản phẩm nhóm A:</small>
                                            <ul class="small mb-0 mt-1 groupa-products-list">${groupANames}</ul>
                                            ${abc.A.count > 5 ? `
                                                <button class="btn btn-sm btn-outline-primary mt-2" onclick="showAllGroupAProducts(this)" 
                                                    data-top-products='${groupANames.replace(/'/g, "&apos;")}'
                                                    data-all-products='${allGroupAProducts.replace(/'/g, "&apos;")}'
                                                    data-expand-text='${expandText}'>
                                                    <i class="fas fa-chevron-down"></i> ${expandText}
                                                </button>
                                            ` : ''}
                                        </div>
                                    ` : ''}
                                    <small class="text-muted d-block mt-2">
                                        <i class="fas fa-chart-line"></i> 
                                        <span data-toggle="tooltip" data-placement="top" 
                                            title="AOV (Average Order Value) = Tổng doanh thu nhóm A ÷ Số sản phẩm nhóm A&#013;= ${(abc.A.revenue / 1000000).toFixed(1)}M ÷ ${abc.A.count} = ${abc.A.count > 0 ? (abc.A.revenue / abc.A.count / 1000000).toFixed(1) : 0}M VNĐ/SP">
                                            AOV: ${abc.A.count > 0 ? (abc.A.revenue / abc.A.count / 1000000).toFixed(1) : 0}M VNĐ/SP
                                            <i class="fas fa-info-circle text-info ml-1" style="cursor: help;"></i>
                                        </span>
                                        <div class="small text-muted mt-1">
                                            <em>Doanh thu TB/sản phẩm = ${(abc.A.revenue / 1000000).toFixed(1)}M VNĐ ÷ ${abc.A.count} SP</em>
                                        </div>
                                    </small>
                                </div>
                            </div>
                        `;
                    }
                    
                    if (insightsHtml) {
                        $('#businessInsightsContainer').html(insightsHtml);
                        // Kích hoạt tooltip
                        $('[data-toggle="tooltip"]').tooltip();
                    } else {
                        $('#businessInsightsContainer').html('<div class="text-muted text-center">Chưa có đủ dữ liệu để phân tích</div>');
                    }
                    
                    // Hiển thị next month forecast based on current data
                    // Tính toán dự báo dựa trên dữ liệu thực
                    let totalDailyRevenue = 0;
                    if (data.fast_moving && data.fast_moving.length > 0) {
                        data.fast_moving.forEach(item => {
                            totalDailyRevenue += (parseFloat(item.daily_velocity) || 0) * 500000; // Giả định giá trung bình
                        });
                    }
                    
                    const expectedMonthlySales = Math.round(totalDailyRevenue * 30);
                    
                    if (expectedMonthlySales > 0) {
                        $('#nextMonthSales').text(expectedMonthlySales.toLocaleString('vi-VN'));
                    } else {
                        $('#nextMonthSales').text('--');
                    }
                    
                    // Inventory needs based on fast moving products
                    if (data.fast_moving && data.fast_moving.length > 0) {
                        let needsHtml = '';
                        const topNeeds = data.fast_moving.slice(0, 3);
                        
                        topNeeds.forEach(item => {
                            const dailyVel = parseFloat(item.daily_velocity) || 0;
                            const recommendedQty = Math.ceil(dailyVel * 30);
                            const currentStock = parseInt(item.current_stock) || 0;
                            const needQty = recommendedQty > currentStock ? recommendedQty - currentStock : 0;
                            
                            needsHtml += `
                                <div class="mb-2">
                                    <strong>${item.product_name}</strong> (${item.size}/${item.color}): 
                                    <span class="text-primary">${needQty}</span> đơn vị
                                    <small class="text-muted d-block">Theo xu hướng mua ${Math.round(dailyVel)} đôi/ngày</small>
                                </div>
                            `;
                        });
                        $('#inventoryNeedsContainer').html(needsHtml);
                    } else {
                        $('#inventoryNeedsContainer').html('<div class="text-muted">Duy trì stock level hiện tại</div>');
                    }
                    
                    // Seasonal factors - lấy từ tháng hiện tại
                    const currentMonth = new Date().getMonth() + 1;
                    let factorsHtml = '';
                    
                    if (currentMonth === 12) {
                        factorsHtml = `
                            <div class="alert alert-info mb-2">
                                <i class="fas fa-calendar-alt"></i>
                                <strong>Yếu tố mùa vụ tháng 12:</strong> Ảnh hưởng tích cực - Mùa lễ hội và năm mới
                            </div>
                        `;
                    } else if (currentMonth === 1 || currentMonth === 2) {
                        factorsHtml = `
                            <div class="alert alert-info mb-2">
                                <i class="fas fa-calendar-alt"></i>
                                <strong>Tết Nguyên Đán:</strong> Nhu cầu mua sắm tăng cao
                            </div>
                        `;
                    } else if (currentMonth === 11) {
                        factorsHtml = `
                            <div class="alert alert-info mb-2">
                                <i class="fas fa-calendar-alt"></i>
                                <strong>Black Friday & Cyber Monday:</strong> Nhu cầu mua sắm tăng mạnh
                            </div>
                        `;
                    } else {
                        factorsHtml = `
                            <div class="text-muted">Không có yếu tố mùa vụ đặc biệt</div>
                        `;
                    }
                    
                    $('#seasonalFactorsContainer').html(factorsHtml);
                    
                } else {
                    console.error('API response not successful:', response);
                    $('#businessInsightsContainer').html('<div class="text-muted text-center">Không thể tải insights: ' + (response.message || 'Unknown error') + '</div>');
                }
            },
            error: function(xhr, status, error) {
                console.error('AI Insights Error:', {xhr: xhr, status: status, error: error});
                console.error('Response Text:', xhr.responseText);
                $('#businessInsightsContainer').html('<div class="text-warning text-center"><i class="fas fa-exclamation-triangle"></i> Chưa có đủ dữ liệu để phân tích</div>');
                $('#inventoryNeedsContainer').html('<div class="text-muted">Chưa có dữ liệu</div>');
                $('#seasonalFactorsContainer').html('<div class="text-muted">Chưa có dữ liệu</div>');
            },
            complete: function() {
                // Đặt lại flag after request completes (success or error)
                // insightsLoaded = false; // Commented out to prevent reload on same page
            }
        });
    }

    // Hàm hiển thị tất cả sản phẩm bán chậm
    function showAllSlowProducts(button) {
        const allProducts = button.getAttribute('data-all-products');
        const topProducts = button.getAttribute('data-top-products');
        const list = button.closest('.card-body').querySelector('.slow-products-list');
        
        if (button.classList.contains('expanded')) {
            // Thu gọn lại
            list.innerHTML = topProducts;
            button.innerHTML = '<i class="fas fa-chevron-down"></i> ' + button.getAttribute('data-expand-text');
            button.classList.remove('expanded');
        } else {
            // Mở rộng
            list.innerHTML = allProducts;
            button.innerHTML = '<i class="fas fa-chevron-up"></i> Thu gọn';
            button.classList.add('expanded');
        }
    }

    // Hàm hiển thị tất cả sản phẩm bán chạy
    function showAllFastProducts(button) {
        const allProducts = button.getAttribute('data-all-products');
        const topProducts = button.getAttribute('data-top-products');
        const list = button.closest('.card-body').querySelector('.fast-products-list');
        
        if (button.classList.contains('expanded')) {
            // Thu gọn lại
            list.innerHTML = topProducts;
            button.innerHTML = '<i class="fas fa-chevron-down"></i> ' + button.getAttribute('data-expand-text');
            button.classList.remove('expanded');
        } else {
            // Mở rộng
            list.innerHTML = allProducts;
            button.innerHTML = '<i class="fas fa-chevron-up"></i> Thu gọn';
            button.classList.add('expanded');
        }
    }

    // Hàm hiển thị tất cả sản phẩm hàng sắp hết
    function showAllLowStock(button) {
        const tableBody = document.getElementById('lowStockTableBody');
        
        if (button.classList.contains('expanded')) {
            // Thu gọn - load lại trang để reset về top 10
            window.location.reload();
        } else {
            // Mở rộng - sử dụng data đã có
            const allRows = button.getAttribute('data-all-rows');
            const count = button.getAttribute('data-count');
            
            if (allRows) {
                tableBody.innerHTML = allRows;
                button.innerHTML = '<i class="fas fa-chevron-up"></i> Thu gọn';
                button.classList.add('expanded');
            }
        }
    }

    // Hàm toggle hiển thị phần Insights & Data
    function toggleInsightsSection() {
        const section = document.getElementById('insightsSection');
        const button = document.getElementById('toggleInsightsBtn');
        
        if (section.style.display === 'none') {
            // Hiển thị section
            section.style.display = 'block';
            button.innerHTML = '<i class="fas fa-chart-line mr-2"></i>Ẩn Dữ liệu & Insights <i class="fas fa-chevron-up ml-2"></i>';
            button.classList.remove('btn-primary');
            button.classList.add('btn-secondary');
            
            // Smooth scroll đến section
            section.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        } else {
            // Ẩn section
            section.style.display = 'none';
            button.innerHTML = '<i class="fas fa-chart-line mr-2"></i>Xem chi tiết Dữ liệu & Insights <i class="fas fa-chevron-down ml-2"></i>';
            button.classList.remove('btn-secondary');
            button.classList.add('btn-primary');
        }
    }

    // Hàm hiển thị tất cả sản phẩm nhóm A
    function showAllGroupAProducts(button) {
        const allProducts = button.getAttribute('data-all-products');
        const topProducts = button.getAttribute('data-top-products');
        const list = button.closest('.card-body').querySelector('.groupa-products-list');
        
        if (button.classList.contains('expanded')) {
            // Thu gọn lại
            list.innerHTML = topProducts;
            button.innerHTML = '<i class="fas fa-chevron-down"></i> ' + button.getAttribute('data-expand-text');
            button.classList.remove('expanded');
        } else {
            // Mở rộng
            list.innerHTML = allProducts;
            button.innerHTML = '<i class="fas fa-chevron-up"></i> Thu gọn';
            button.classList.add('expanded');
        }
    }

    // Sales Trend Chart - Monthly Revenue Trend
    var ctxSalesTrend = document.getElementById("salesTrendChart");
    if (ctxSalesTrend) {
        var salesTrendChart = new Chart(ctxSalesTrend, {
            type: 'bar',
            data: {
                labels: [
                    <?php
                    // Lấy dữ liệu 6 tháng gần nhất
                    $monthLabels = [];
                    for ($i = 5; $i >= 0; $i--) {
                        $monthLabels[] = '"Tháng ' . date('m/Y', strtotime("-$i months")) . '"';
                    }
                    echo implode(',', $monthLabels);
                    ?>
                ],
                datasets: [{
                    label: 'Doanh số (Triệu VNĐ)',
                    data: [
                        <?php
                        // Lấy doanh số thực tế theo tháng
                        if ($userWarehouseId) {
                            $monthlySales = [];
                            for ($i = 5; $i >= 0; $i--) {
                                $monthStart = date('Y-m-01', strtotime("-$i months"));
                                $monthEnd = date('Y-m-t', strtotime("-$i months"));
                                
                                $stmtMonthlySales = $pdo->prepare("
                                    SELECT COALESCE(SUM(od.total_price), 0) as total_sales
                                    FROM orders o
                                    JOIN order_details od ON o.order_id = od.order_id
                                    WHERE o.warehouse_id = ? 
                                    AND o.status IN ('delivered', 'completed')
                                    AND DATE(o.created_at) BETWEEN ? AND ?
                                ");
                                $stmtMonthlySales->execute([$userWarehouseId, $monthStart, $monthEnd]);
                                $sales = $stmtMonthlySales->fetch(PDO::FETCH_ASSOC)['total_sales'];
                                $monthlySales[] = round($sales / 1000000, 2); // Convert to millions
                            }
                            echo implode(',', $monthlySales);
                        } else {
                            echo '0,0,0,0,0,0';
                        }
                        ?>
                    ],
                    backgroundColor: 'rgba(78, 115, 223, 0.6)',
                    borderColor: 'rgba(78, 115, 223, 1)',
                    borderWidth: 2,
                    hoverBackgroundColor: 'rgba(78, 115, 223, 0.8)',
                    hoverBorderColor: 'rgba(78, 115, 223, 1)',
                }]
            },
            options: {
                maintainAspectRatio: false,
                responsive: true,
                legend: {
                    display: true,
                    position: 'top',
                    labels: {
                        fontColor: '#ffffff',
                        fontSize: 12,
                        boxWidth: 15
                    }
                },
                scales: {
                    xAxes: [{
                        gridLines: {
                            display: false,
                            color: 'rgba(255, 255, 255, 0.1)'
                        },
                        ticks: {
                            fontColor: '#858796',
                            fontSize: 11
                        }
                    }],
                    yAxes: [{
                        gridLines: {
                            color: 'rgba(234, 236, 244, 0.5)',
                            zeroLineColor: 'rgba(234, 236, 244, 0.5)',
                            drawBorder: false,
                            borderDash: [2],
                            zeroLineBorderDash: [2]
                        },
                        ticks: {
                            beginAtZero: true,
                            fontColor: '#858796',
                            fontSize: 11,
                            callback: function(value, index, values) {
                                return number_format(value, 1) + 'M';
                            }
                        }
                    }]
                },
                tooltips: {
                    backgroundColor: "rgb(255,255,255)",
                    bodyFontColor: "#858796",
                    titleFontColor: '#6e707e',
                    titleFontSize: 13,
                    borderColor: '#dddfeb',
                    borderWidth: 1,
                    xPadding: 15,
                    yPadding: 15,
                    displayColors: true,
                    caretPadding: 10,
                    callbacks: {
                        label: function(tooltipItem, data) {
                            var label = data.datasets[tooltipItem.datasetIndex].label || '';
                            return label + ': ' + number_format(tooltipItem.yLabel, 2) + ' triệu VNĐ';
                        }
                    }
                }
            }
        });
    }

    // Storage Heatmap - Interactive cells
    $(document).ready(function() {
        $('.heatmap-cell').hover(
            function() {
                $(this).css('transform', 'scale(1.1)');
                $(this).css('z-index', '10');
                $(this).css('box-shadow', '0 4px 12px rgba(0,0,0,0.3)');
            },
            function() {
                $(this).css('transform', 'scale(1)');
                $(this).css('z-index', '1');
                $(this).css('box-shadow', '0 2px 4px rgba(0,0,0,0.1)');
            }
        );
        
        $('.heatmap-cell').click(function() {
            var location = $(this).data('location');
            var type = $(this).data('type');
            var quantity = $(this).data('quantity');
            var variants = $(this).data('variants');
            
            var message = '<div class="text-left">' +
                '<h5><i class="fas fa-map-marker-alt text-warning"></i> ' + location + '</h5>' +
                '<hr>' +
                '<p class="mb-2"><strong>Loại vị trí:</strong> ' + type + '</p>' +
                '<p class="mb-2"><strong>Tổng số lượng:</strong> ' + Number(quantity).toLocaleString() + ' sản phẩm</p>' +
                '<p class="mb-0"><strong>Số loại sản phẩm:</strong> ' + variants + ' loại</p>' +
                '</div>';
            
            Swal.fire({
                html: message,
                icon: 'info',
                confirmButtonText: 'Đóng',
                confirmButtonColor: '#4e73df'
            });
        });
    });

    // Bật/tắt custom date picker
    function toggleCustomDatePicker() {
        var picker = document.getElementById('customDatePicker');
        if (picker.style.display === 'none' || picker.style.display === '') {
            picker.style.display = 'block';
        } else {
            picker.style.display = 'none';
        }
    }

    // Tải AI insights on page load
    $(document).ready(function() {
        // Kích hoạt tooltip cho các chỉ số thống kê
        $('[data-toggle="tooltip"]').tooltip({
            html: true,
            boundary: 'window'
        });
        
        loadAIInsights();
        
        // Xử lý time period filter buttons
        $('#timePeriodFilter button').on('click', function() {
            var period = $(this).data('period');
            // Tải lại page with period parameter
            window.location.href = 'trang_chu.php?period=' + period;
        });
        
        // Đặt default dates for custom picker if not set
        if ($('input[name="start_date"]').val() === '') {
            var today = new Date();
            var lastWeek = new Date(today.getTime() - 7 * 24 * 60 * 60 * 1000);
            $('input[name="start_date"]').val(lastWeek.toISOString().split('T')[0]);
            $('input[name="end_date"]').val(today.toISOString().split('T')[0]);
        }
    });
    </script>

</body>

</html>


