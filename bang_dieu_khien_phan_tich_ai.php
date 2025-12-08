<?php
/**
 * Advanced AI Analytics Dashboard
 * Implements 7 Smart Analysis Modules for Shoe Warehouse System
 */

session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.html');
    exit();
}

$database = new Database();
$pdo = $database->getConnection();
$warehouseId = $_SESSION['warehouse_id'];

// Lấy warehouse info
$stmt = $pdo->prepare("SELECT name FROM warehouses WHERE warehouse_id = ?");
$stmt->execute([$warehouseId]);
$warehouse = $stmt->fetch(PDO::FETCH_ASSOC);
$warehouseName = $warehouse ? $warehouse['name'] : 'Smart Warehouse';
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>AI Analytics Dashboard - <?php echo $warehouseName; ?></title>

    <!-- Custom fonts -->
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">

    <!-- Custom styles -->
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <link href="vendor/datatables/dataTables.bootstrap4.min.css" rel="stylesheet">
    
    <style>
        .analytics-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            transition: all 0.3s ease;
        }
        .analytics-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(0,0,0,0.2);
        }
        .module-card {
            border-left: 4px solid;
            transition: all 0.3s ease;
        }
        .module-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        .ai-insight-box {
            background: linear-gradient(45deg, #f093fb 0%, #f5576c 100%);
            color: white;
            border-radius: 15px;
            padding: 20px;
            margin: 15px 0;
        }
        .metric-card {
            background: #fff;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            margin: 10px 0;
        }
        .chart-container {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin: 10px 0;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }
        .status-indicator {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 8px;
        }
        .status-good { background: #28a745; }
        .status-warning { background: #ffc107; }
        .status-danger { background: #dc3545; }
        
        .nav-pills .nav-link.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
    </style>
</head>

<body id="page-top">
    <div id="wrapper">
        <?php include 'includes/thanh_ben.php'; ?>
        
        <div id="content-wrapper" class="d-flex flex-column">
            <div id="content">
                <?php include 'includes/thanh_tren.php'; ?>
                
                <div class="container-fluid">
                    <!-- Page Header -->
                    <div class="d-sm-flex align-items-center justify-content-between mb-4">
                        <h1 class="h3 mb-0 text-gray-800">
                            <i class="fas fa-brain mr-2 text-primary"></i>
                            AI Analytics Dashboard
                            <span class="badge badge-primary ml-2">
                                <i class="fas fa-robot mr-1"></i>
                                7 Smart Modules
                            </span>
                        </h1>
                        <div>
                            <button class="btn btn-primary shadow-sm" id="refreshAll">
                                <i class="fas fa-sync-alt mr-2"></i>Refresh All Data
                            </button>
                        </div>
                    </div>

                    <!-- AI Status Overview -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="card analytics-card shadow">
                                <div class="card-body">
                                    <div class="row align-items-center">
                                        <div class="col-md-8">
                                            <h4 class="mb-3">
                                                <i class="fas fa-microscope mr-2"></i>
                                                AI Analysis Status
                                            </h4>
                                            <p class="mb-0">Real-time intelligent insights from your shoe warehouse data</p>
                                        </div>
                                        <div class="col-md-4 text-center">
                                            <div class="ai-status-grid">
                                                <div class="d-flex justify-content-between">
                                                    <div class="status-item">
                                                        <div class="status-indicator status-good"></div>
                                                        <small>Data Quality</small>
                                                    </div>
                                                    <div class="status-item">
                                                        <div class="status-indicator status-good"></div>
                                                        <small>AI Models</small>
                                                    </div>
                                                    <div class="status-item">
                                                        <div class="status-indicator status-warning"></div>
                                                        <small>Predictions</small>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 7 Analytics Modules Navigation -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="card shadow">
                                <div class="card-header">
                                    <h6 class="m-0 font-weight-bold text-primary">
                                        <i class="fas fa-th-large mr-2"></i>
                                        Smart Analytics Modules
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <ul class="nav nav-pills justify-content-center" id="analyticsModules" role="tablist">
                                        <li class="nav-item">
                                            <a class="nav-link active" id="trend-tab" data-toggle="pill" href="#trend-module" role="tab">
                                                <i class="fas fa-chart-line mr-1"></i>
                                                <small>Xu hướng</small>
                                            </a>
                                        </li>
                                        <li class="nav-item">
                                            <a class="nav-link" id="inventory-tab" data-toggle="pill" href="#inventory-module" role="tab">
                                                <i class="fas fa-boxes mr-1"></i>
                                                <small>Vòng quay</small>
                                            </a>
                                        </li>
                                        <li class="nav-item">
                                            <a class="nav-link" id="supply-tab" data-toggle="pill" href="#supply-module" role="tab">
                                                <i class="fas fa-truck mr-1"></i>
                                                <small>Chuỗi cung ứng</small>
                                            </a>
                                        </li>
                                        <li class="nav-item">
                                            <a class="nav-link" id="profit-tab" data-toggle="pill" href="#profit-module" role="tab">
                                                <i class="fas fa-dollar-sign mr-1"></i>
                                                <small>Lợi nhuận</small>
                                            </a>
                                        </li>
                                        <li class="nav-item">
                                            <a class="nav-link" id="behavior-tab" data-toggle="pill" href="#behavior-module" role="tab">
                                                <i class="fas fa-users mr-1"></i>
                                                <small>Hành vi</small>
                                            </a>
                                        </li>
                                        <li class="nav-item">
                                            <a class="nav-link" id="quality-tab" data-toggle="pill" href="#quality-module" role="tab">
                                                <i class="fas fa-shield-alt mr-1"></i>
                                                <small>Chất lượng</small>
                                            </a>
                                        </li>
                                        <li class="nav-item">
                                            <a class="nav-link" id="market-tab" data-toggle="pill" href="#market-module" role="tab">
                                                <i class="fas fa-globe mr-1"></i>
                                                <small>Thị trường</small>
                                            </a>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Module Content -->
                    <div class="tab-content" id="analyticsContent">
                        
                        <!-- Module 1: Trend & Seasonality Analysis -->
                        <div class="tab-pane fade show active" id="trend-module" role="tabpanel">
                            <div class="row">
                                <div class="col-xl-8">
                                    <div class="card module-card shadow" style="border-left-color: #4e73df;">
                                        <div class="card-header bg-primary text-white">
                                            <h6 class="m-0 font-weight-bold">
                                                <i class="fas fa-chart-line mr-2"></i>
                                                Phân tích xu hướng tiêu thụ (Trend & Seasonality)
                                            </h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="chart-container">
                                                <canvas id="trendSeasonalChart"></canvas>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-xl-4">
                                    <div class="ai-insight-box">
                                        <h6><i class="fas fa-brain mr-2"></i>AI Insights</h6>
                                        <div id="trendInsights">
                                            <div class="insight-item mb-2">
                                                <i class="fas fa-arrow-up mr-2"></i>
                                                <small>Boots tăng 35% mùa lạnh</small>
                                            </div>
                                            <div class="insight-item mb-2">
                                                <i class="fas fa-arrow-down mr-2"></i>
                                                <small>Sandals giảm 40% tháng này</small>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="metric-card">
                                        <h6 class="text-primary">Top Trending Categories</h6>
                                        <div id="trendingCategories">
                                            <!-- Dynamic content -->
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Module 2: Inventory Turnover & Aging -->
                        <div class="tab-pane fade" id="inventory-module" role="tabpanel">
                            <div class="row">
                                <div class="col-xl-6">
                                    <div class="card module-card shadow" style="border-left-color: #1cc88a;">
                                        <div class="card-header bg-success text-white">
                                            <h6 class="m-0 font-weight-bold">
                                                <i class="fas fa-sync-alt mr-2"></i>
                                                Vòng quay tồn kho (Inventory Turnover)
                                            </h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="chart-container">
                                                <canvas id="turnoverChart"></canvas>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-xl-6">
                                    <div class="card module-card shadow" style="border-left-color: #f6c23e;">
                                        <div class="card-header bg-warning text-white">
                                            <h6 class="m-0 font-weight-bold">
                                                <i class="fas fa-clock mr-2"></i>
                                                Phân tích độ tuổi hàng (Aging Analysis)
                                            </h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="chart-container">
                                                <canvas id="agingChart"></canvas>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row mt-4">
                                <div class="col-12">
                                    <div class="card shadow">
                                        <div class="card-header">
                                            <h6 class="m-0 font-weight-bold text-primary">
                                                <i class="fas fa-table mr-2"></i>
                                                Chi tiết vòng quay theo sản phẩm
                                            </h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="table-responsive">
                                                <table class="table table-bordered" id="turnoverTable">
                                                    <thead>
                                                        <tr>
                                                            <th>SKU</th>
                                                            <th>Sản phẩm</th>
                                                            <th>Days in Inventory</th>
                                                            <th>Turnover Rate</th>
                                                            <th>Trạng thái</th>
                                                            <th>AI Recommendation</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody id="turnoverTableBody">
                                                        <!-- Dynamic content -->
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Module 3: Supply Chain Efficiency -->
                        <div class="tab-pane fade" id="supply-module" role="tabpanel">
                            <div class="row">
                                <div class="col-xl-8">
                                    <div class="card module-card shadow" style="border-left-color: #36b9cc;">
                                        <div class="card-header bg-info text-white">
                                            <h6 class="m-0 font-weight-bold">
                                                <i class="fas fa-truck mr-2"></i>
                                                Hiệu suất chuỗi cung ứng (Supply Chain Efficiency)
                                            </h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="chart-container">
                                                        <canvas id="supplierPerformanceChart"></canvas>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="chart-container">
                                                        <canvas id="deliveryTimeChart"></canvas>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-xl-4">
                                    <div class="ai-insight-box">
                                        <h6><i class="fas fa-truck mr-2"></i>Supply Chain AI</h6>
                                        <div id="supplyInsights">
                                            <!-- Dynamic insights -->
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Module 4: Profitability & Margin Analysis -->
                        <div class="tab-pane fade" id="profit-module" role="tabpanel">
                            <div class="row">
                                <div class="col-xl-6">
                                    <div class="card module-card shadow" style="border-left-color: #e74a3b;">
                                        <div class="card-header bg-danger text-white">
                                            <h6 class="m-0 font-weight-bold">
                                                <i class="fas fa-chart-pie mr-2"></i>
                                                Phân tích lợi nhuận (Profitability Analysis)
                                            </h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="chart-container">
                                                <canvas id="profitChart"></canvas>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-xl-6">
                                    <div class="card module-card shadow" style="border-left-color: #5a5c69;">
                                        <div class="card-header bg-secondary text-white">
                                            <h6 class="m-0 font-weight-bold">
                                                <i class="fas fa-percentage mr-2"></i>
                                                Phân tích biên lợi nhuận (Margin Analysis)
                                            </h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="chart-container">
                                                <canvas id="marginChart"></canvas>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Module 5: Consumer Behavior Analysis -->
                        <div class="tab-pane fade" id="behavior-module" role="tabpanel">
                            <div class="row">
                                <div class="col-12">
                                    <div class="card module-card shadow" style="border-left-color: #f093fb;">
                                        <div class="card-header" style="background: linear-gradient(45deg, #f093fb 0%, #f5576c 100%); color: white;">
                                            <h6 class="m-0 font-weight-bold">
                                                <i class="fas fa-users mr-2"></i>
                                                Phân tích hành vi tiêu dùng (Consumer Behavior AI)
                                            </h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="row">
                                                <div class="col-md-4">
                                                    <div class="chart-container">
                                                        <canvas id="customerSegmentChart"></canvas>
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="chart-container">
                                                        <canvas id="purchasePatternChart"></canvas>
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="ai-insight-box" style="height: 300px; overflow-y: auto;">
                                                        <h6><i class="fas fa-brain mr-2"></i>Behavior Insights</h6>
                                                        <div id="behaviorInsights">
                                                            <!-- Dynamic insights -->
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Module 6: Data Quality & Risk Analysis -->
                        <div class="tab-pane fade" id="quality-module" role="tabpanel">
                            <div class="row">
                                <div class="col-xl-8">
                                    <div class="card module-card shadow" style="border-left-color: #858796;">
                                        <div class="card-header bg-secondary text-white">
                                            <h6 class="m-0 font-weight-bold">
                                                <i class="fas fa-shield-alt mr-2"></i>
                                                Chất lượng dữ liệu & Dự báo rủi ro
                                            </h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <h6 class="text-primary">Data Quality Score</h6>
                                                    <div class="progress mb-4">
                                                        <div class="progress-bar bg-success" role="progressbar" style="width: 87%" id="dataQualityBar">87%</div>
                                                    </div>
                                                    
                                                    <div id="dataQualityIssues">
                                                        <!-- Dynamic quality issues -->
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="chart-container">
                                                        <canvas id="riskChart"></canvas>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-xl-4">
                                    <div class="ai-insight-box">
                                        <h6><i class="fas fa-exclamation-triangle mr-2"></i>Risk Alerts</h6>
                                        <div id="riskAlerts">
                                            <!-- Dynamic risk alerts -->
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Module 7: Market Trends & Social Media -->
                        <div class="tab-pane fade" id="market-module" role="tabpanel">
                            <div class="row">
                                <div class="col-12">
                                    <div class="card module-card shadow" style="border-left-color: #667eea;">
                                        <div class="card-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                                            <h6 class="m-0 font-weight-bold">
                                                <i class="fas fa-globe mr-2"></i>
                                                Xu hướng thị trường & Mạng xã hội
                                            </h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="chart-container">
                                                        <canvas id="marketTrendChart"></canvas>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="chart-container">
                                                        <canvas id="socialTrendChart"></canvas>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="mt-4">
                                                <h6 class="text-primary">
                                                    <i class="fas fa-hashtag mr-2"></i>
                                                    Trending Keywords & Social Signals
                                                </h6>
                                                <div id="trendingKeywords" class="d-flex flex-wrap">
                                                    <!-- Dynamic trending tags -->
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                    </div>

                    <!-- Action Center -->
                    <div class="row mt-4">
                        <div class="col-12">
                            <div class="card shadow">
                                <div class="card-header">
                                    <h6 class="m-0 font-weight-bold text-primary">
                                        <i class="fas fa-cogs mr-2"></i>
                                        AI Action Center
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-3">
                                            <button class="btn btn-primary btn-block" id="generateReport">
                                                <i class="fas fa-file-alt mr-2"></i>
                                                Generate Full Report
                                            </button>
                                        </div>
                                        <div class="col-md-3">
                                            <button class="btn btn-success btn-block" id="exportInsights">
                                                <i class="fas fa-download mr-2"></i>
                                                Export AI Insights
                                            </button>
                                        </div>
                                        <div class="col-md-3">
                                            <button class="btn btn-info btn-block" id="scheduleAnalysis">
                                                <i class="fas fa-calendar mr-2"></i>
                                                Schedule Analysis
                                            </button>
                                        </div>
                                        <div class="col-md-3">
                                            <button class="btn btn-warning btn-block" id="aiSettings">
                                                <i class="fas fa-robot mr-2"></i>
                                                AI Settings
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
            <!-- End of Main Content -->
            
            <?php include 'includes/chan_trang.php'; ?>

    <!-- JavaScript -->
    <script src="vendor/jquery/jquery.min.js"></script>
    <script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="vendor/jquery-easing/jquery.easing.min.js"></script>
    <script src="js/sb-admin-2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="js/ai-analytics-dashboard.js"></script>

</body>
</html>