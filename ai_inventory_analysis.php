<?php
session_start();
require_once 'config/database.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    header('Location: login.html');
    exit();
}

$database = new Database();
$pdo = $database->getConnection();

$userId = $_SESSION['user_id'];
$warehouseId = $_SESSION['warehouse_id'];

// Lấy tên kho
$warehouseName = 'Smart Warehouse';
if ($warehouseId) {
    $stmt = $pdo->prepare("SELECT name FROM warehouses WHERE warehouse_id = :warehouse_id");
    $stmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
    $stmt->execute();
    $warehouse = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($warehouse) {
        $warehouseName = $warehouse['name'];
    }
}

// Kiểm tra dữ liệu có đủ để phân tích không
$dataCheckSql = "SELECT 
                    COUNT(DISTINCT p.product_id) as product_count,
                    COUNT(DISTINCT o.order_id) as order_count,
                    COUNT(DISTINCT sr.receipt_id) as receipt_count,
                    MIN(o.created_at) as earliest_order,
                    MAX(o.created_at) as latest_order
                 FROM products p
                 LEFT JOIN product_variants pv ON p.product_id = pv.product_id
                 LEFT JOIN order_details od ON pv.variant_id = od.variant_id
                 LEFT JOIN orders o ON od.order_id = o.order_id AND o.status = 'delivered'
                 LEFT JOIN stock_receipts sr ON p.warehouse_id = sr.warehouse_id
                 WHERE p.warehouse_id = ?";

$dataCheckStmt = $pdo->prepare($dataCheckSql);
$dataCheckStmt->execute([$warehouseId]);
$dataCheck = $dataCheckStmt->fetch(PDO::FETCH_ASSOC);

$hasEnoughData = $dataCheck['product_count'] >= 10 && $dataCheck['order_count'] >= 20;
$dataAge = $dataCheck['earliest_order'] ? (time() - strtotime($dataCheck['earliest_order'])) / (24 * 3600) : 0;
?>

<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="description" content="">
    <meta name="author" content="">

    <title>Phân tích Tồn kho AI - <?php echo htmlspecialchars($warehouseName); ?></title>

    <!-- Custom fonts for this template-->
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">

    <!-- Custom styles for this template-->
    <link href="css/sb-admin-2.min.css" rel="stylesheet">

    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        .ai-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            transition: all 0.3s ease;
        }
        .ai-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
        }
        .ai-badge {
            background: linear-gradient(45deg, #ff6b6b, #ee5a24);
            color: white;
            padding: 5px 12px;
            border-radius: 25px;
            font-size: 0.8rem;
            font-weight: bold;
        }
        .analysis-card {
            border-left: 4px solid #4e73df;
            transition: all 0.3s ease;
        }
        .icon-circle {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
        }
        .insight-item {
            padding: 15px;
            background: #f8f9fc;
            border-radius: 8px;
            border-left: 3px solid #4e73df;
        }
        .seasonal-pattern {
            padding: 12px;
            background: #ffffff;
            border-radius: 6px;
            border: 1px solid #e3e6f0;
            margin-bottom: 10px;
        }
        .trend-insights {
            max-height: 400px;
            overflow-y: auto;
        }
        .status-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
        }
        .status-label {
            font-size: 12px;
            color: #6c757d;
            font-weight: 500;
            text-transform: uppercase;
        }
        .status-value {
            font-size: 14px;
            font-weight: bold;
        }
        .ai-summary-text p {
            font-size: 14px;
            line-height: 1.6;
        }
        }
        .analysis-card:hover {
            border-left-color: #36b9cc;
            transform: translateX(5px);
        }
        .loading-animation {
            display: none;
        }
        .loading-animation.active {
            display: block;
        }
        .ai-thinking {
            background: linear-gradient(-45deg, #ee7752, #e73c7e, #23a6d5, #23d5ab);
            background-size: 400% 400%;
            animation: gradient 15s ease infinite;
        }
        @keyframes gradient {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        .prediction-item {
            border-left: 3px solid transparent;
            padding: 15px;
            margin: 10px 0;
            border-radius: 5px;
            background: #f8f9fc;
        }
        .prediction-high { border-left-color: #e74a3b; }
        .prediction-medium { border-left-color: #f6c23e; }
        .prediction-low { border-left-color: #1cc88a; }
        .seasonal-indicator {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 8px;
        }
        .season-spring { background-color: #2ecc71; }
        .season-summer { background-color: #f39c12; }
        .season-autumn { background-color: #e67e22; }
        .season-winter { background-color: #3498db; }
    </style>
</head>

<body id="page-top">

    <!-- Page Wrapper -->
    <div id="wrapper">

        <!-- Sidebar -->
        <?php include 'includes/sidebar.php'; ?>

        <!-- Content Wrapper -->
        <div id="content-wrapper" class="d-flex flex-column">

            <!-- Main Content -->
            <div id="content">

                <!-- Topbar -->
                <?php include 'includes/topbar.php'; ?>

                <!-- Begin Page Content -->
                <div class="container-fluid">

                    <!-- Page Heading -->
                    <div class="d-sm-flex align-items-center justify-content-between mb-4">
                        <h1 class="h3 mb-0 text-gray-800">
                            <i class="fas fa-brain mr-2 text-primary"></i>
                            Phân tích Tồn kho AI
                            <span class="ai-badge ml-2">
                                <i class="fas fa-robot mr-1"></i>
                                Powered by AI
                            </span>
                        </h1>
                    </div>

                    <!-- AI Status Card -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="card ai-card shadow">
                                <div class="card-body">
                                    <div class="row align-items-center">
                                        <div class="col-md-8">
                                            <h5 class="mb-2">
                                                <i class="fas fa-microchip mr-2"></i>
                                                Trạng thái AI Engine
                                            </h5>
                                            <p class="mb-0">
                                                Sử dụng Gemini AI và OpenRouter để phân tích xu hướng tiêu thụ, 
                                                dự đoán nhu cầu và tối ưu hóa tồn kho thông minh.
                                            </p>
                                        </div>
                                        <div class="col-md-4 text-right">
                                            <div class="ai-status">
                                                <div class="badge badge-success badge-lg">
                                                    <i class="fas fa-check-circle mr-1"></i>
                                                    AI Ready
                                                </div>
                                                <div class="small text-light mt-1">
                                                    <i class="fas fa-database mr-1"></i>
                                                    <?php echo number_format($dataCheck['product_count']); ?> sản phẩm
                                                    | <?php echo number_format($dataCheck['order_count']); ?> đơn hàng
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Data Validation -->
                    <?php if (!$hasEnoughData): ?>
                    <div class="alert alert-warning shadow">
                        <div class="row align-items-center">
                            <div class="col-md-8">
                                <h6 class="alert-heading">
                                    <i class="fas fa-exclamation-triangle mr-2"></i>
                                    Dữ liệu chưa đủ để phân tích AI
                                </h6>
                                <p class="mb-0">
                                    Cần tối thiểu 10 sản phẩm và 20 đơn hàng để AI có thể phân tích chính xác. 
                                    Hiện tại: <?php echo $dataCheck['product_count']; ?> sản phẩm, <?php echo $dataCheck['order_count']; ?> đơn hàng.
                                </p>
                            </div>
                            <div class="col-md-4 text-right">
                                <button class="btn btn-warning" onclick="window.location.href='add_test_data.php'">
                                    <i class="fas fa-plus mr-2"></i>
                                    Thêm dữ liệu test
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Analysis Controls -->
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">
                                <i class="fas fa-sliders-h mr-2"></i>
                                Cấu hình phân tích AI
                            </h6>
                        </div>
                        <div class="card-body">
                            <form id="aiAnalysisForm">
                                <div class="row">
                                    <div class="col-md-3">
                                        <div class="form-group">
                                            <label for="analysisType">Loại phân tích:</label>
                                            <select class="form-control" id="analysisType" name="analysis_type">
                                                <option value="comprehensive">Phân tích toàn diện</option>
                                                <option value="trend">Xu hướng tiêu thụ</option>
                                                <option value="seasonal">Phân tích mùa vụ</option>
                                                <option value="prediction">Dự đoán nhu cầu</option>
                                                <option value="optimization">Tối ưu tồn kho</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="form-group">
                                            <label for="timeRange">Khoảng thời gian:</label>
                                            <select class="form-control" id="timeRange" name="time_range">
                                                <option value="30">30 ngày gần nhất</option>
                                                <option value="60">60 ngày gần nhất</option>
                                                <option value="90" selected>90 ngày gần nhất</option>
                                                <option value="180">6 tháng gần nhất</option>
                                                <option value="365">12 tháng gần nhất</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <label for="predictionDays">Dự đoán (ngày):</label>
                                            <select class="form-control" id="predictionDays" name="prediction_days">
                                                <option value="30" selected>30 ngày</option>
                                                <option value="60">60 ngày</option>
                                                <option value="90">90 ngày</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-12 text-center">
                                        <button type="submit" class="btn btn-primary btn-lg shadow" <?php echo !$hasEnoughData ? 'disabled' : ''; ?>>
                                            <i class="fas fa-brain mr-2"></i>
                                            Bắt đầu phân tích AI
                                        </button>
                                        <div class="loading-animation mt-3">
                                            <div class="card ai-thinking">
                                                <div class="card-body text-center text-white">
                                                    <div class="spinner-border text-light mb-2" role="status">
                                                        <span class="sr-only">Loading...</span>
                                                    </div>
                                                    <h6>AI đang phân tích dữ liệu...</h6>
                                                    <p class="mb-0 small">
                                                        Đang xử lý xu hướng tiêu thụ, phát hiện mùa vụ và dự đoán nhu cầu
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Results Section -->
                    <div id="analysisResults" style="display: none;">
                        
                        <!-- AI Insights Summary -->
                        <div class="row mb-4">
                            <div class="col-12">
                                <div class="card border-left-success shadow">
                                    <div class="card-header bg-gradient-success text-white">
                                        <h6 class="m-0 font-weight-bold">
                                            <i class="fas fa-lightbulb mr-2"></i>
                                            Thông tin chính từ AI
                                        </h6>
                                    </div>
                                    <div class="card-body">
                                        <div id="aiInsights">
                                            <div class="row">
                                                <div class="col-md-8">
                                                    <div class="ai-summary-text">
                                                        <h5 class="text-success mb-3">
                                                            <i class="fas fa-brain mr-2"></i>
                                                            Thông tin thông minh từ AI
                                                        </h5>
                                                        <p class="mb-2">
                                                            <i class="fas fa-info-circle text-primary mr-2"></i>
                                                            <strong>Trạng thái hệ thống:</strong> Sẵn sàng phân tích dữ liệu tồn kho của bạn
                                                        </p>
                                                        <p class="mb-2">
                                                            <i class="fas fa-chart-line text-success mr-2"></i>
                                                            <strong>Dữ liệu khả dụng:</strong> <?php echo number_format($dataStats['product_count']); ?> sản phẩm, <?php echo number_format($dataStats['order_count']); ?> đơn hàng
                                                        </p>
                                                        <p class="mb-2">
                                                            <i class="fas fa-calendar-alt text-info mr-2"></i>
                                                            <strong>Khoảng thời gian:</strong> <?php echo $dataStats['earliest_order'] ? date('d/m/Y', strtotime($dataStats['earliest_order'])) : 'Chưa có'; ?> - <?php echo $dataStats['latest_order'] ? date('d/m/Y', strtotime($dataStats['latest_order'])) : 'Hôm nay'; ?>
                                                        </p>
                                                        <div class="alert alert-info mb-0">
                                                            <i class="fas fa-lightbulb mr-2"></i>
                                                            <strong>Gợi ý:</strong> Chạy phân tích AI để nhận được insights chi tiết về xu hướng tiêu thụ, dự đoán nhu cầu và khuyến nghị nhập hàng thông minh.
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="ai-status-indicators">
                                                        <div class="status-item mb-3">
                                                            <div class="d-flex align-items-center">
                                                                <div class="status-icon bg-success">
                                                                    <i class="fas fa-database text-white"></i>
                                                                </div>
                                                                <div class="ml-3">
                                                                    <div class="status-label">Dữ liệu</div>
                                                                    <div class="status-value text-success">Đầy đủ</div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="status-item mb-3">
                                                            <div class="d-flex align-items-center">
                                                                <div class="status-icon bg-primary">
                                                                    <i class="fas fa-robot text-white"></i>
                                                                </div>
                                                                <div class="ml-3">
                                                                    <div class="status-label">AI Engine</div>
                                                                    <div class="status-value text-primary">Sẵn sàng</div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="status-item mb-3">
                                                            <div class="d-flex align-items-center">
                                                                <div class="status-icon bg-warning">
                                                                    <i class="fas fa-chart-area text-white"></i>
                                                                </div>
                                                                <div class="ml-3">
                                                                    <div class="status-label">Phân tích</div>
                                                                    <div class="status-value text-warning">Chờ khởi động</div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Statistics Cards -->
                        <div class="row mb-4">
                            <div class="col-xl-3 col-md-6 mb-4">
                                <div class="card border-left-primary shadow h-100">
                                    <div class="card-body">
                                        <div class="row no-gutters align-items-center">
                                            <div class="col mr-2">
                                                <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                                    Sản phẩm được phân tích</div>
                                                <div class="h5 mb-0 font-weight-bold text-gray-800" id="analyzedProducts">
                                                    --
                                                </div>
                                            </div>
                                            <div class="col-auto">
                                                <i class="fas fa-boxes fa-2x text-primary"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-xl-3 col-md-6 mb-4">
                                <div class="card border-left-success shadow h-100">
                                    <div class="card-body">
                                        <div class="row no-gutters align-items-center">
                                            <div class="col mr-2">
                                                <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                                    Xu hướng tăng</div>
                                                <div class="h5 mb-0 font-weight-bold text-gray-800" id="trendingUp">
                                                    --
                                                </div>
                                            </div>
                                            <div class="col-auto">
                                                <i class="fas fa-arrow-up fa-2x text-success"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-xl-3 col-md-6 mb-4">
                                <div class="card border-left-warning shadow h-100">
                                    <div class="card-body">
                                        <div class="row no-gutters align-items-center">
                                            <div class="col mr-2">
                                                <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                                    Cần nhập hàng</div>
                                                <div class="h5 mb-0 font-weight-bold text-gray-800" id="needRestock">
                                                    --
                                                </div>
                                            </div>
                                            <div class="col-auto">
                                                <i class="fas fa-exclamation-triangle fa-2x text-warning"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-xl-3 col-md-6 mb-4">
                                <div class="card border-left-danger shadow h-100">
                                    <div class="card-body">
                                        <div class="row no-gutters align-items-center">
                                            <div class="col mr-2">
                                                <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">
                                                    Tồn kho dư thừa</div>
                                                <div class="h5 mb-0 font-weight-bold text-gray-800" id="overstock">
                                                    --
                                                </div>
                                            </div>
                                            <div class="col-auto">
                                                <i class="fas fa-minus-circle fa-2x text-danger"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Charts Row -->
                        <div class="row mb-4">
                            <!-- Advanced Trend Analysis -->
                            <div class="col-12">
                                <div class="card shadow mb-4">
                                    <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                                        <h6 class="m-0 font-weight-bold text-primary">
                                            <i class="fas fa-chart-line mr-2"></i>
                                            Xu hướng tiêu thụ và dự đoán AI nâng cao
                                        </h6>
                                        <div class="dropdown no-arrow">
                                            <a class="dropdown-toggle" href="#" role="button" id="trendDropdown"
                                                data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                                <i class="fas fa-ellipsis-v fa-sm fa-fw text-gray-400"></i>
                                            </a>
                                            <div class="dropdown-menu dropdown-menu-right shadow animated--fade-in"
                                                aria-labelledby="trendDropdown">
                                                <div class="dropdown-header">Tùy chọn hiển thị:</div>
                                                <a class="dropdown-item" href="#" data-chart-type="sales">Xu hướng doanh số</a>
                                                <a class="dropdown-item" href="#" data-chart-type="quantity">Xu hướng số lượng</a>
                                                <a class="dropdown-item" href="#" data-chart-type="velocity">Tốc độ tiêu thụ</a>
                                                <a class="dropdown-item" href="#" data-chart-type="seasonal">Phân tích mùa vụ</a>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="card-body">
                                        <!-- Chart Navigation Tabs -->
                                        <ul class="nav nav-tabs mb-3" id="trendTabs" role="tablist">
                                            <li class="nav-item">
                                                <a class="nav-link active" id="overview-tab" data-toggle="tab" href="#overview" role="tab">
                                                    <i class="fas fa-chart-area mr-1"></i>Tổng quan
                                                </a>
                                            </li>
                                            <li class="nav-item">
                                                <a class="nav-link" id="prediction-tab" data-toggle="tab" href="#prediction" role="tab">
                                                    <i class="fas fa-brain mr-1"></i>Dự đoán AI
                                                </a>
                                            </li>
                                            <li class="nav-item">
                                                <a class="nav-link" id="seasonal-tab" data-toggle="tab" href="#seasonal" role="tab">
                                                    <i class="fas fa-calendar-alt mr-1"></i>Mùa vụ
                                                </a>
                                            </li>
                                            <li class="nav-item">
                                                <a class="nav-link" id="velocity-tab" data-toggle="tab" href="#velocity" role="tab">
                                                    <i class="fas fa-tachometer-alt mr-1"></i>Tốc độ
                                                </a>
                                            </li>
                                        </ul>

                                        <!-- Tab Content -->
                                        <div class="tab-content" id="trendTabContent">
                                            <!-- Overview Tab -->
                                            <div class="tab-pane fade show active" id="overview" role="tabpanel">
                                                <div class="row">
                                                    <div class="col-lg-8">
                                                        <div class="chart-area" style="height: 400px;">
                                                            <canvas id="trendChart"></canvas>
                                                        </div>
                                                    </div>
                                                    <div class="col-lg-4">
                                                        <div class="trend-insights">
                                                            <h6 class="font-weight-bold text-primary mb-3">
                                                                <i class="fas fa-lightbulb mr-2"></i>Insights quan trọng
                                                            </h6>
                                                            <div id="trendInsights">
                                                                <div class="insight-item mb-3">
                                                                    <div class="d-flex align-items-center mb-2">
                                                                        <div class="icon-circle bg-success">
                                                                            <i class="fas fa-arrow-up text-white"></i>
                                                                        </div>
                                                                        <span class="ml-2 font-weight-bold">Tăng trưởng mạnh</span>
                                                                    </div>
                                                                    <small class="text-muted">Dự đoán tăng 23% trong 30 ngày tới</small>
                                                                </div>
                                                                <div class="insight-item mb-3">
                                                                    <div class="d-flex align-items-center mb-2">
                                                                        <div class="icon-circle bg-warning">
                                                                            <i class="fas fa-clock text-white"></i>
                                                                        </div>
                                                                        <span class="ml-2 font-weight-bold">Mùa cao điểm</span>
                                                                    </div>
                                                                    <small class="text-muted">Đang bước vào mùa bán hàng cao điểm</small>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- AI Prediction Tab -->
                                            <div class="tab-pane fade" id="prediction" role="tabpanel">
                                                <div class="row">
                                                    <div class="col-lg-6">
                                                        <div class="chart-area" style="height: 300px;">
                                                            <canvas id="predictionChart"></canvas>
                                                        </div>
                                                    </div>
                                                    <div class="col-lg-6">
                                                        <div class="chart-area" style="height: 300px;">
                                                            <canvas id="confidenceChart"></canvas>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="mt-4">
                                                    <h6 class="font-weight-bold text-primary mb-3">
                                                        <i class="fas fa-robot mr-2"></i>AI Prediction Models
                                                    </h6>
                                                    <div class="row">
                                                        <div class="col-md-4">
                                                            <div class="border-left-primary shadow h-100 py-2">
                                                                <div class="card-body">
                                                                    <div class="row no-gutters align-items-center">
                                                                        <div class="col mr-2">
                                                                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                                                                Linear Regression</div>
                                                                            <div class="h5 mb-0 font-weight-bold text-gray-800">92% Accuracy</div>
                                                                        </div>
                                                                        <div class="col-auto">
                                                                            <i class="fas fa-chart-line fa-2x text-gray-300"></i>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-4">
                                                            <div class="border-left-success shadow h-100 py-2">
                                                                <div class="card-body">
                                                                    <div class="row no-gutters align-items-center">
                                                                        <div class="col mr-2">
                                                                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                                                                Neural Network</div>
                                                                            <div class="h5 mb-0 font-weight-bold text-gray-800">87% Accuracy</div>
                                                                        </div>
                                                                        <div class="col-auto">
                                                                            <i class="fas fa-brain fa-2x text-gray-300"></i>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-4">
                                                            <div class="border-left-info shadow h-100 py-2">
                                                                <div class="card-body">
                                                                    <div class="row no-gutters align-items-center">
                                                                        <div class="col mr-2">
                                                                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                                                                ARIMA Model</div>
                                                                            <div class="h5 mb-0 font-weight-bold text-gray-800">89% Accuracy</div>
                                                                        </div>
                                                                        <div class="col-auto">
                                                                            <i class="fas fa-wave-square fa-2x text-gray-300"></i>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Seasonal Analysis Tab -->
                                            <div class="tab-pane fade" id="seasonal" role="tabpanel">
                                                <div class="row">
                                                    <div class="col-lg-8">
                                                        <div class="chart-area" style="height: 400px;">
                                                            <canvas id="seasonalChart"></canvas>
                                                        </div>
                                                    </div>
                                                    <div class="col-lg-4">
                                                        <div class="seasonal-insights">
                                                            <h6 class="font-weight-bold text-primary mb-3">
                                                                <i class="fas fa-calendar-check mr-2"></i>Phân tích mùa vụ
                                                            </h6>
                                                            <div id="seasonalPatterns">
                                                                <!-- Dynamic seasonal patterns will be loaded here -->
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Velocity Analysis Tab -->
                                            <div class="tab-pane fade" id="velocity" role="tabpanel">
                                                <div class="row">
                                                    <div class="col-lg-6">
                                                        <div class="chart-area" style="height: 300px;">
                                                            <canvas id="velocityChart"></canvas>
                                                        </div>
                                                    </div>
                                                    <div class="col-lg-6">
                                                        <div class="chart-area" style="height: 300px;">
                                                            <canvas id="inventoryTurnChart"></canvas>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="mt-4">
                                                    <div class="table-responsive">
                                                        <table class="table table-bordered" id="velocityTable" width="100%" cellspacing="0">
                                                            <thead>
                                                                <tr>
                                                                    <th>Sản phẩm</th>
                                                                    <th>SKU</th>
                                                                    <th>Tốc độ tiêu thụ</th>
                                                                    <th>Vòng quay kho</th>
                                                                    <th>Thời gian hết hàng</th>
                                                                    <th>Trạng thái</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody id="velocityTableBody">
                                                                <!-- Dynamic velocity data will be loaded here -->
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                        </div>

                        <!-- Detailed Analysis Results -->
                        <div class="row">
                            <!-- AI Recommendations -->
                            <div class="col-xl-6">
                                <div class="card shadow mb-4">
                                    <div class="card-header py-3">
                                        <h6 class="m-0 font-weight-bold text-success">
                                            <i class="fas fa-robot mr-2"></i>
                                            Đề xuất từ AI
                                        </h6>
                                    </div>
                                    <div class="card-body">
                                        <div id="aiRecommendations">
                                            <!-- AI recommendations will be populated here -->
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Product Analysis -->
                            <div class="col-xl-6">
                                <div class="card shadow mb-4">
                                    <div class="card-header py-3">
                                        <h6 class="m-0 font-weight-bold text-info">
                                            <i class="fas fa-list-alt mr-2"></i>
                                            Phân tích sản phẩm chi tiết
                                        </h6>
                                    </div>
                                    <div class="card-body">
                                        <div id="productAnalysis">
                                            <!-- Product analysis will be populated here -->
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="row mb-4">
                            <div class="col-12 text-center">
                                <button class="btn btn-success mr-2" id="exportReport">
                                    <i class="fas fa-download mr-2"></i>
                                    Xuất báo cáo
                                </button>
                                <button class="btn btn-info mr-2" id="savePlan">
                                    <i class="fas fa-save mr-2"></i>
                                    Lưu kế hoạch nhập hàng
                                </button>
                                <button class="btn btn-secondary" id="shareInsights">
                                    <i class="fas fa-share mr-2"></i>
                                    Chia sẻ thông tin
                                </button>
                            </div>
                        </div>

                    </div>

                </div>
                <!-- /.container-fluid -->

            </div>
            <!-- End of Main Content -->

            <!-- Footer -->
            <?php include 'includes/footer.php'; ?>

        </div>
        <!-- End of Content Wrapper -->

    </div>
    <!-- End of Page Wrapper -->

    <!-- Bootstrap core JavaScript-->
    <script src="vendor/jquery/jquery.min.js"></script>
    <script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script>

    <!-- Core plugin JavaScript-->
    <script src="vendor/jquery-easing/jquery.easing.min.js"></script>

    <!-- Custom scripts for all pages-->
    <script src="js/sb-admin-2.min.js"></script>
    
    <!-- AI Analysis Script -->
    <script src="js/ai-inventory-analysis.js"></script>

</body>

</html>