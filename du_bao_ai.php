<?php
// Start session and check authentication
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Check role permission (only admin and manager can access AI features)
$userRole = $_SESSION['role'] ?? 'staff';
if (!in_array($userRole, ['admin', 'manager'])) {
    header('Location: trang_chu.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Dự Báo Thông Minh - Smart Warehouse System</title>

    <!-- Custom fonts -->
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">
    
    <!-- Custom styles -->
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    
    <style>
        .forecast-card {
            transition: all 0.3s ease;
            border-left: 4px solid #224abe;
        }
        .forecast-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.15);
        }
        .trend-up {
            color: #1cc88a;
        }
        .trend-down {
            color: #e74a3b;
        }
        .trend-stable {
            color: #f6c23e;
        }
        .impact-badge {
            font-size: 0.7rem;
            padding: 0.25rem 0.5rem;
        }
        .recommendation-box {
            background: #f8f9fc;
            border-left: 4px solid #224abe;
            padding: 1rem;
            margin-top: 1rem;
            border-radius: 0.35rem;
        }
        .climate-info {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1.5rem;
            border-radius: 0.5rem;
            margin-bottom: 1.5rem;
        }
        .trend-card {
            border: 2px solid #e3e6f0;
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 1rem;
            transition: all 0.3s ease;
        }
        .trend-card:hover {
            border-color: #224abe;
            box-shadow: 0 0 20px rgba(78, 115, 223, 0.3);
        }
        .trend-score {
            font-size: 2rem;
            font-weight: bold;
        }
        .abc-section {
            padding: 1.5rem;
            border-radius: 0.5rem;
            margin-bottom: 1rem;
        }
        .abc-a {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            color: white;
        }
        .abc-b {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
        }
        .abc-c {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: white;
        }
        .event-timeline {
            position: relative;
            padding-left: 2rem;
        }
        .event-timeline::before {
            content: '';
            position: absolute;
            left: 0.5rem;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #224abe;
        }
        .event-item {
            position: relative;
            margin-bottom: 1.5rem;
            padding-left: 1.5rem;
        }
        .event-item::before {
            content: '';
            position: absolute;
            left: -1rem;
            top: 0.5rem;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #224abe;
            border: 2px solid white;
        }
        .loading-spinner {
            display: inline-block;
            width: 2rem;
            height: 2rem;
            border: 0.25rem solid #f3f3f3;
            border-top: 0.25rem solid #224abe;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .analysis-comment {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 1rem;
            margin-top: 1rem;
            border-radius: 0.35rem;
        }
        .analysis-comment i {
            color: #ffc107;
            margin-right: 0.5rem;
        }
        
        /* Prevent content flashing */
        .content-loading {
            min-height: 200px;
            opacity: 0.8;
        }
        
        .tab-content {
            min-height: 400px;
        }
        
        .card-body {
            min-height: 100px;
        }
        
        /* Smooth transitions - disabled to reduce jitter */
        .fade-in {
            /* animation: fadeIn 0.5s ease-in; */
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Loading state improvements */
        .loading-overlay {
            position: relative;
        }
        
        .loading-overlay::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255,255,255,0.8);
            z-index: 10;
            border-radius: 0.35rem;
        }
        
        /* Climate Cards - Equal Height & Alignment */
        #climateAnalysis {
            display: flex;
            flex-wrap: wrap;
        }
        
        #climateAnalysis .col-lg-4 {
            display: flex;
        }
        
        #climateAnalysis .card {
            display: flex;
            flex-direction: column;
            width: 100%;
        }
        
        #climateAnalysis .card-body {
            flex: 1;
            display: flex;
            flex-direction: column;
        }
        
        /* Đảm bảo các phần tử con trong card có chiều cao đồng đều */
        #climateAnalysis .card-body > div {
            flex-shrink: 0;
        }
        
        #climateAnalysis .card-body .card.bg-light {
            min-height: 120px; /* Chiều cao tối thiểu cho phần thông tin mùa */
        }
        
        #climateAnalysis .card-body .alert {
            margin-bottom: 0.75rem;
        }
        
        #climateAnalysis .card-body .list-unstyled {
            min-height: 80px; /* Chiều cao tối thiểu cho phần Giày HOT */
        }
        
        /* Restock Suggestions - Vertical Scroll CHUNG cho cả 3 miền */
        .restock-wrapper {
            max-height: 600px;
            overflow-y: auto;
            overflow-x: hidden;
            padding-right: 5px;
        }
        
        /* Custom scrollbar styling cho wrapper chung */
        .restock-wrapper::-webkit-scrollbar {
            width: 10px;
        }
        
        .restock-wrapper::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        
        .restock-wrapper::-webkit-scrollbar-thumb {
            background: #224abe;
            border-radius: 10px;
        }
        
        .restock-wrapper::-webkit-scrollbar-thumb:hover {
            background: #2e59d9;
        }
        
        /* Firefox scrollbar */
        .restock-wrapper {
            scrollbar-width: thin;
            scrollbar-color: #224abe #f1f1f1;
        }
        
        /* Individual container - không có scroll riêng */
        .restock-container-individual {
            overflow: visible;
        }
        
        /* Badge đếm số sản phẩm */
        .badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
            vertical-align: middle;
            transition: all 0.3s ease;
        }
        
        /* Animation khi update count */
        .badge.pulse {
            animation: pulseEffect 0.5s ease;
        }
        
        @keyframes pulseEffect {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.2); }
        }
        
        /* Responsive adjustments */
        @media (max-width: 991px) {
            #climateAnalysis .col-lg-4 {
                margin-bottom: 1rem;
            }
        }
        

        
        /* ============================================
           BEAUTIFUL KPI CARDS
           ============================================ */
        
        .kpi-card {
            display: flex;
            align-items: center;
            padding: 1.25rem;
            border-radius: 0.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .kpi-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 15px rgba(0, 0, 0, 0.2);
        }
        
        /* Decorative pattern */
        .kpi-card::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            pointer-events: none;
        }
        
        .kpi-icon {
            font-size: 2.5rem;
            margin-right: 1.25rem;
            opacity: 0.9;
            flex-shrink: 0;
        }
        
        .kpi-content {
            flex: 1;
        }
        
        .kpi-label {
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            opacity: 0.9;
            margin-bottom: 0.25rem;
            font-weight: 600;
        }
        
        .kpi-value {
            font-size: 2rem;
            font-weight: 700;
            line-height: 1.2;
            margin-bottom: 0.25rem;
        }
        
        .kpi-subtext {
            font-size: 0.75rem;
            opacity: 0.85;
        }
        
        /* Gradient backgrounds */
        .bg-gradient-primary {
            background: linear-gradient(135deg, #224abe 0%, #224abe 100%);
        }
        
        .bg-gradient-success {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
        }
        
        .bg-gradient-warning {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }
        
        .bg-gradient-info {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        }
        
        .bg-gradient-secondary {
            background: linear-gradient(135deg, #868f96 0%, #596164 100%);
        }
        
        .bg-gradient-danger {
            background: linear-gradient(135deg, #eb3349 0%, #f45c43 100%);
        }
        
        /* ============================================
           BEAUTIFUL RECOMMENDATIONS
           ============================================ */
        
        .recommendations-list {
            padding: 0.5rem;
        }
        
        .recommendation-item {
            display: flex;
            align-items: flex-start;
            padding: 1rem;
            margin-bottom: 0.75rem;
            border-radius: 0.5rem;
            border-left: 4px solid;
            background: #fff;
            box-shadow: 0 2px 4px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
        }
        
        .recommendation-item:hover {
            transform: translateX(5px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        
        .recommendation-item:last-child {
            margin-bottom: 0;
        }
        
        /* Color variants */
        .recommendation-warning {
            border-left-color: #f6c23e;
            background: linear-gradient(to right, #fff9e6 0%, #ffffff 100%);
        }
        
        .recommendation-danger {
            border-left-color: #e74a3b;
            background: linear-gradient(to right, #ffe6e6 0%, #ffffff 100%);
        }
        
        .recommendation-success {
            border-left-color: #1cc88a;
            background: linear-gradient(to right, #e6f9f0 0%, #ffffff 100%);
        }
        
        .recommendation-info {
            border-left-color: #36b9cc;
            background: linear-gradient(to right, #e6f7f9 0%, #ffffff 100%);
        }
        
        .recommendation-icon {
            font-size: 1.5rem;
            margin-right: 1rem;
            flex-shrink: 0;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }
        
        .recommendation-warning .recommendation-icon {
            color: #f6c23e;
            background: rgba(246, 194, 62, 0.1);
        }
        
        .recommendation-danger .recommendation-icon {
            color: #e74a3b;
            background: rgba(231, 74, 59, 0.1);
        }
        
        .recommendation-success .recommendation-icon {
            color: #1cc88a;
            background: rgba(28, 200, 138, 0.1);
        }
        
        .recommendation-info .recommendation-icon {
            color: #36b9cc;
            background: rgba(54, 185, 204, 0.1);
        }
        
        .recommendation-content {
            flex: 1;
        }
        
        .recommendation-title {
            font-weight: 700;
            font-size: 0.95rem;
            margin-bottom: 0.25rem;
            color: #2d3436;
        }
        
        .recommendation-message {
            font-size: 0.85rem;
            color: #636e72;
            line-height: 1.5;
        }
    </style>
</head>

<body id="page-top">
    <!-- Page Wrapper -->
    <div id="wrapper">
        
        <!-- Sidebar -->
        <?php include 'includes/thanh_ben.php'; ?>
        
        <!-- Content Wrapper -->
        <div id="content-wrapper" class="d-flex flex-column">
            
            <!-- Main Content -->
            <div id="content">
                
                <!-- Topbar -->
                <?php include 'includes/thanh_tren.php'; ?>
                
                <!-- Begin Page Content -->
                <div class="container-fluid">
                    
                    <!-- Page Heading -->
                    <div class="d-sm-flex align-items-center justify-content-between mb-4">
                        <h1 class="h3 mb-0 text-gray-800">
                            <i class="fas fa-chart-line text-primary"></i> Dự Báo Thông Minh
                        </h1>
                        <button class="btn btn-primary btn-sm" onclick="refreshAllData()">
                            <i class="fas fa-sync-alt"></i> Làm mới dữ liệu
                        </button>
                    </div>

                    <!-- Tổng quan nhanh -->
                    <div class="row mb-4">
                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="card border-left-primary shadow h-100 py-2"
                                 data-toggle="tooltip"
                                 data-placement="top"
                                 data-html="true"
                                 title="<strong>Số Sản Phẩm Trong Kho</strong><br>Tổng số sản phẩm (product) khác nhau có trong kho<br><br><strong>Lưu ý:</strong><br>• Đếm theo product_id (không phải variant)<br>• Chỉ tính sản phẩm có status = 'active'<br>• 1 sản phẩm có thể có nhiều size/màu">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                                Sản phẩm trong kho
                                                <i class="fas fa-info-circle ml-1" style="font-size: 10px;"></i>
                                            </div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800" id="totalProducts">-</div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-boxes fa-2x text-gray-300"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="card border-left-success shadow h-100 py-2"
                                 data-toggle="tooltip"
                                 data-placement="top"
                                 data-html="true"
                                 title="<strong>Đơn Hàng Đã Giao (30 Ngày)</strong><br>Số đơn hàng có trạng thái 'delivered' trong 30 ngày qua<br><br><strong>Công thức:</strong><br>COUNT(*) FROM orders<br>WHERE status = 'delivered'<br>AND created_at >= NOW() - 30 days">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                                Đơn hàng (30 ngày)
                                                <i class="fas fa-info-circle ml-1" style="font-size: 10px;"></i>
                                            </div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800" id="totalOrders">-</div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-shopping-cart fa-2x text-gray-300"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="card border-left-info shadow h-100 py-2"
                                 data-toggle="tooltip"
                                 data-placement="top"
                                 data-html="true"
                                 title="<strong>Tổng Tồn Kho</strong><br>Tổng số lượng tất cả sản phẩm đang tồn trong kho<br><br><strong>Công thức:</strong><br>SUM(quantity) FROM inventory<br>WHERE warehouse_id = ?<br><br><strong>Bao gồm:</strong><br>Tất cả variants (size, màu) của tất cả sản phẩm">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                                Tổng tồn kho
                                                <i class="fas fa-info-circle ml-1" style="font-size: 10px;"></i>
                                            </div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800" id="totalStock">-</div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-warehouse fa-2x text-gray-300"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="card border-left-warning shadow h-100 py-2"
                                 data-toggle="tooltip"
                                 data-placement="top"
                                 data-html="true"
                                 title="<strong>Doanh Thu (30 Ngày)</strong><br>Tổng doanh thu từ các đơn hàng đã giao trong 30 ngày qua<br><br><strong>Công thức:</strong><br>SUM(total_price) FROM order_details<br>JOIN orders (status='delivered')<br>WHERE created_at >= NOW() - 30 days">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                                Doanh thu (30 ngày)
                                                <i class="fas fa-info-circle ml-1" style="font-size: 10px;"></i>
                                            </div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800" id="totalRevenue">-</div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-dollar-sign fa-2x text-gray-300"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Navigation Tabs -->
                    <ul class="nav nav-tabs mb-4" role="tablist">
                        <li class="nav-item">
                            <a class="nav-link active" id="market-trends-tab" data-toggle="tab" href="#market-trends" role="tab">
                                <i class="fab fa-tiktok"></i> Thị trường & Trends
                            </a>
                        </li>
                    </ul>

                    <!-- Tab Content -->
                    <div class="tab-content">
                        
                        <!-- Tab 1: Thị trường & Trends -->
                        <div class="tab-pane fade show active" id="market-trends" role="tabpanel">
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <div class="form-row align-items-center">
                                        <div class="col-auto">
                                            <label class="font-weight-bold">Tháng phân tích:</label>
                                        </div>
                                        <div class="col-auto">
                                            <select class="form-control" id="climateMonthSelect" onchange="loadVietnamClimate()">
                                                <option value="1">Tháng 1</option>
                                                <option value="2">Tháng 2</option>
                                                <option value="3">Tháng 3</option>
                                                <option value="4">Tháng 4</option>
                                                <option value="5">Tháng 5</option>
                                                <option value="6">Tháng 6</option>
                                                <option value="7">Tháng 7</option>
                                                <option value="8">Tháng 8</option>
                                                <option value="9">Tháng 9</option>
                                                <option value="10">Tháng 10</option>
                                                <option value="11">Tháng 11</option>
                                                <option value="12">Tháng 12</option>
                                            </select>
                                        </div>
                                        <div class="col-auto ml-auto">
                                            <button class="btn btn-success btn-icon-split" onclick="exportForecastToExcel()">
                                                <span class="icon text-white-50">
                                                    <i class="fas fa-file-excel"></i>
                                                </span>
                                                <span class="text">Xuất Excel Gợi Ý Nhập Hàng</span>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row" id="climateAnalysis">
                                <div class="col-lg-4 mb-4">
                                    <div class="card border-left-primary shadow h-100">
                                        <div class="card-header bg-primary text-white">
                                            <h6 class="m-0 font-weight-bold">Miền Bắc</h6>
                                        </div>
                                        <div class="card-body" id="northClimate">
                                            <div class="text-center">
                                                <div class="loading-spinner"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-lg-4 mb-4">
                                    <div class="card border-left-warning shadow h-100">
                                        <div class="card-header bg-warning text-white">
                                            <h6 class="m-0 font-weight-bold">Miền Trung</h6>
                                        </div>
                                        <div class="card-body" id="centralClimate">
                                            <div class="text-center">
                                                <div class="loading-spinner"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-lg-4 mb-4">
                                    <div class="card border-left-success shadow h-100">
                                        <div class="card-header bg-success text-white">
                                            <h6 class="m-0 font-weight-bold">Miền Nam</h6>
                                        </div>
                                        <div class="card-body" id="southClimate">
                                            <div class="text-center">
                                                <div class="loading-spinner"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- 1. Gợi ý nhập hàng dựa trên KHÍ HẬU & SỰ KIỆN -->
                            <div class="row mt-4">
                                <div class="col-md-12">
                                    <div class="card shadow border-left-primary">
                                        <div class="card-header py-3 bg-gradient-primary text-white">
                                            <h6 class="m-0 font-weight-bold">
                                                <i class="fas fa-cloud-sun"></i> Gợi Ý Nhập Hàng Dựa Trên Khí Hậu & Sự Kiện
                                                <small class="ml-2">(Dự đoán nhu cầu tăng)</small>
                                            </h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="alert alert-info mb-3">
                                                <i class="fas fa-info-circle"></i> 
                                                <strong>Gợi ý thông minh:</strong> Dựa trên khí hậu mùa, lễ hội/sự kiện để dự đoán sản phẩm sẽ bán chạy.
                                            </div>
                                            <!-- Thanh cuộn chung cho cả 3 miền -->
                                            <div class="restock-wrapper">
                                                <div class="row">
                                                    <div class="col-lg-4 mb-3">
                                                        <h6 class="text-primary border-bottom pb-2">
                                                            <i class="fas fa-map-marker-alt"></i> Miền Bắc 
                                                            <span class="badge badge-primary" id="countClimateNorth">0</span>
                                                        </h6>
                                                        <div id="restockClimateNorth" class="restock-container-individual">
                                                            <div class="text-center">
                                                                <div class="loading-spinner"></div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="col-lg-4 mb-3">
                                                        <h6 class="text-warning border-bottom pb-2">
                                                            <i class="fas fa-map-marker-alt"></i> Miền Trung 
                                                            <span class="badge badge-warning" id="countClimateCentral">0</span>
                                                        </h6>
                                                        <div id="restockClimateCentral" class="restock-container-individual">
                                                            <div class="text-center">
                                                                <div class="loading-spinner"></div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="col-lg-4 mb-3">
                                                        <h6 class="text-success border-bottom pb-2">
                                                            <i class="fas fa-map-marker-alt"></i> Miền Nam 
                                                            <span class="badge badge-success" id="countClimateSouth">0</span>
                                                        </h6>
                                                        <div id="restockClimateSouth" class="restock-container-individual">
                                                            <div class="text-center">
                                                                <div class="loading-spinner"></div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- 2. Gợi ý nhập hàng dựa trên TỒN KHO -->
                            <div class="row mt-4">
                                <div class="col-md-12">
                                    <div class="card shadow border-left-danger">
                                        <div class="card-header py-3 bg-gradient-danger text-white">
                                            <h6 class="m-0 font-weight-bold">
                                                <i class="fas fa-exclamation-triangle"></i> Gợi Ý Nhập Hàng Dựa Trên Tồn Kho
                                                <small class="ml-2">(Sản phẩm hết hoặc sắp hết)</small>
                                            </h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="alert alert-danger mb-3">
                                                <i class="fas fa-exclamation-triangle"></i> 
                                                <strong>Cảnh báo thiếu hàng:</strong> Sản phẩm có tồn kho thấp hoặc bán chạy cần nhập gấp.
                                            </div>
                                            <!-- Thanh cuộn cho phần low stock -->
                                            <div class="restock-wrapper">
                                                <div class="row">
                                                    <div class="col-lg-4 mb-3">
                                                        <h6 class="text-danger border-bottom pb-2">
                                                            <i class="fas fa-battery-quarter"></i> CRITICAL 
                                                            <span class="badge badge-danger" id="countCritical">0</span>
                                                        </h6>
                                                        <div id="restockCritical" class="restock-container-individual">
                                                            <div class="text-center">
                                                                <div class="loading-spinner"></div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="col-lg-4 mb-3">
                                                        <h6 class="text-warning border-bottom pb-2">
                                                            <i class="fas fa-battery-half"></i> HIGH 
                                                            <span class="badge badge-warning" id="countHigh">0</span>
                                                        </h6>
                                                        <div id="restockHigh" class="restock-container-individual">
                                                            <div class="text-center">
                                                                <div class="loading-spinner"></div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="col-lg-4 mb-3">
                                                        <h6 class="text-info border-bottom pb-2">
                                                            <i class="fas fa-battery-three-quarters"></i> MEDIUM 
                                                            <span class="badge badge-info" id="countMedium">0</span>
                                                        </h6>
                                                        <div id="restockMedium" class="restock-container-individual">
                                                            <div class="text-center">
                                                                <div class="loading-spinner"></div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row mt-4">
                                <div class="col-md-12">
                                    <div class="card shadow">
                                        <div class="card-header py-3">
                                            <h6 class="m-0 font-weight-bold text-primary">
                                                Lễ hội & Sự kiện trong tháng
                                                <small class="text-muted ml-2">(Hiển thị tháng đó và 2 tháng tiếp theo)</small>
                                            </h6>
                                        </div>
                                        <div class="card-body" id="festivalAnalysis">
                                            <div class="text-center">
                                                <div class="loading-spinner"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Tab 6: Hot Trends MXH -->
                        <div class="tab-pane fade" id="social-trends" role="tabpanel">
                            <div class="row">
                                <div class="col-lg-8">
                                    <div class="card shadow mb-4">
                                        <div class="card-header py-3">
                                            <h6 class="m-0 font-weight-bold text-primary">
                                                <i class="fab fa-tiktok"></i> TikTok Trends
                                            </h6>
                                        </div>
                                        <div class="card-body" id="tiktokTrends">
                                            <div class="text-center">
                                                <div class="loading-spinner"></div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="card shadow mb-4">
                                        <div class="card-header py-3">
                                            <h6 class="m-0 font-weight-bold text-primary">
                                                <i class="fab fa-facebook"></i> Facebook Groups
                                            </h6>
                                        </div>
                                        <div class="card-body" id="facebookTrends">
                                            <div class="text-center">
                                                <div class="loading-spinner"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-lg-4">
                                    <div class="card shadow mb-4">
                                        <div class="card-header py-3">
                                            <h6 class="m-0 font-weight-bold text-success">Khuyến nghị Stock</h6>
                                        </div>
                                        <div class="card-body" id="stockRecommendations">
                                            <div class="text-center">
                                                <div class="loading-spinner"></div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="card shadow">
                                        <div class="card-header py-3">
                                            <h6 class="m-0 font-weight-bold text-info">Pricing Insights</h6>
                                        </div>
                                        <div class="card-body" id="pricingInsights">
                                            <div class="text-center">
                                                <div class="loading-spinner"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>

                </div>
                <!-- /.container-fluid -->

            </div>
            <!-- End of Main Content -->

            <!-- Footer -->
            <?php include 'includes/chan_trang.php'; ?>

        </div>
        <!-- End of Content Wrapper -->

    </div>
    <!-- End of Page Wrapper -->

    <!-- Scroll to Top Button-->
    <a class="scroll-to-top rounded" href="#page-top">
        <i class="fas fa-angle-up"></i>
    </a>

    <!-- Logout Modal-->
    <?php include 'includes/modal_dang_xuat.php'; ?>

    <!-- Bootstrap core JavaScript-->
    <script src="vendor/jquery/jquery.min.js"></script>
    <script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script>

    <!-- Core plugin JavaScript-->
    <script src="vendor/jquery-easing/jquery.easing.min.js"></script>

    <!-- Custom scripts for all pages-->
    <script src="js/sb-admin-2.min.js"></script>

    <script>
        let loadingStates = {
            dashboard: false,
            trend: false,
            abc: false,
            inventory: false,
            events: false
        };
        let dataLoadedOnce = {
            dashboard: false,
            trend: false,
            abc: false,
            inventory: false,
            events: false
        };

        $(document).ready(function() {
            // Initialize Bootstrap tooltips
            $('[data-toggle="tooltip"]').tooltip({
                trigger: 'hover',
                html: true,
                boundary: 'window',
                template: '<div class="tooltip" role="tooltip"><div class="arrow"></div><div class="tooltip-inner" style="max-width: 350px; text-align: left;"></div></div>'
            });
            
            // Set current month
            const currentMonth = new Date().getMonth() + 1;
            $('#monthSelect').val(currentMonth);
            
            // Show initial loading
            showNotification('🔄 Đang khởi tạo dữ liệu AI...', 'info');
            
            // Only load dashboard data on page load - other tabs load when clicked
            setTimeout(() => {
                loadDashboardSummary();
            }, 500);
            
            // Load data when tabs are clicked - unbind first to prevent duplicates
            $('#trend-tab').off('shown.bs.tab').on('shown.bs.tab', function() {
                if ($('#trendContent').find('.loading-spinner').length > 0) {
                    showNotification('📊 Đang tải xu hướng...', 'info');
                    loadTrendPredictions();
                }
            });
            
            $('#event-tab').off('shown.bs.tab').on('shown.bs.tab', function() {
                if ($('#eventForecast').find('.loading-spinner').length > 0) {
                    showNotification('📅 Đang tải sự kiện...', 'info');
                    loadEventForecast();
                }
            });
        });

        function refreshAllData() {
            loadDashboardSummary();
            const activeTab = $('.nav-tabs .active').attr('id');
            
            if (activeTab === 'trend-tab') {
                loadTrendPredictions();
            } else if (activeTab === 'inventory-tab') {
                loadABCAnalysis();
                loadInventoryInsights();
            } else if (activeTab === 'event-tab') {
                loadEventForecast();
            }
            
            showNotification('Đã làm mới dữ liệu!', 'success');
        }

        function loadDashboardSummary() {
            // Show loading state - simplified to reduce jitter
            $('#totalProducts, #totalOrders, #totalStock, #totalRevenue').html('<i class="fas fa-spinner fa-spin"></i>');
            
            $.ajax({
                url: 'api_du_bao_ai.php',
                method: 'GET',
                data: { action: 'get_dashboard_summary' },
                dataType: 'json',
                timeout: 10000,
                success: function(response) {
                    console.log('Dashboard Summary Response:', response);
                    try {
                        if (response && response.no_warehouse) {
                            // User chưa được phân công kho
                            $('#totalProducts, #totalOrders, #totalStock, #totalRevenue').text('0');
                            showNotification('ℹ️ ' + response.message, 'warning');
                            
                            // Hiển thị thông báo trên dashboard
                            $('#businessInsightsContainer').html(`
                                <div class="alert alert-info">
                                    <h5><i class="fas fa-info-circle"></i> Chưa có dữ liệu</h5>
                                    <p class="mb-0">${response.message}</p>
                                </div>
                            `);
                        } else if (response && response.success) {
                            showNotification('✅ Dashboard tải thành công!', 'success');
                            $('#totalProducts').text(response.data.total_products || 0);
                            $('#totalOrders').text(response.data.orders_last_month || 0);
                            $('#totalStock').text(formatNumber(response.data.total_stock || 0));
                            $('#totalRevenue').text(formatCurrency(response.data.revenue_last_month || 0));
                            
                            // Re-initialize tooltips after content update
                            $('[data-toggle="tooltip"]').tooltip('dispose');
                            $('[data-toggle="tooltip"]').tooltip({
                                trigger: 'hover',
                                html: true,
                                boundary: 'window'
                            });
                            
                            // Load real AI insights from inventory data
                            loadRealAIInsights();
                        } else {
                            console.error('Dashboard Summary Error:', response ? response.message : 'No response');
                            $('#totalProducts, #totalOrders, #totalStock, #totalRevenue').text('-');
                            showNotification('⚠️ Lỗi dashboard: ' + (response ? response.message : 'No response'), 'error');
                        }
                    } catch (parseError) {
                        console.error('Dashboard parsing error:', parseError);
                        console.error('Raw response:', response);
                        showNotification('❌ Lỗi xử lý dữ liệu dashboard', 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Dashboard Summary AJAX Error:', error);
                    console.error('XHR Response:', xhr.responseText);
                    console.error('Status:', status);
                    $('#totalProducts, #totalOrders, #totalStock, #totalRevenue').text('-');
                    showNotification('❌ Lỗi kết nối dashboard', 'error');
                }
            });
        }

        function loadTrendPredictions() {
            $('#trendContent').html('<div class="text-center"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Đang phân tích xu hướng...</p></div>');
            
            console.log('Loading Trend Predictions...');
            $.ajax({
                url: 'api_du_bao_ai.php',
                method: 'GET',
                data: { action: 'get_trend_predictions' },
                dataType: 'json',
                timeout: 10000,
                success: function(response) {
                    console.log('Trend Predictions Response:', response);
                    if (response.success) {
                        showNotification('📊 Xu hướng tải thành công!', 'success');
                        displayTrendPredictions(response.data);
                    } else {
                        console.error('Trend Predictions Error:', response.message);
                        $('#trendContent').html('<div class="alert alert-danger">Lỗi: ' + response.message + '</div>');
                        showNotification('❌ Lỗi xu hướng: ' + response.message, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Trend Predictions AJAX Error:', error);
                    $('#trendContent').html('<div class="alert alert-danger">Lỗi kết nối: ' + error + '</div>');
                    showNotification('❌ Lỗi kết nối xu hướng', 'error');
                }
            });
        }

        function displayTrendPredictions(data) {
            try {
                console.log('Displaying Trend Predictions:', data);
                
                // Safely update time
                if (data.analysis_date) {
                    $('#trendUpdateTime').text(new Date(data.analysis_date).toLocaleString('vi-VN'));
                }
                
                let html = '<div class="row fade-in">';
                
                if (data.trending_products && data.trending_products.length > 0) {
                    data.trending_products.forEach(product => {
                        const scoreColor = product.trend_score >= 80 ? 'success' : 
                                           (product.trend_score >= 60 ? 'warning' : 'secondary');
                        const riskBadge = product.risk_level === 'low' ? 'badge-success' : 'badge-warning';
                        
                        html += `
                            <div class="col-lg-6 mb-4">
                                <div class="trend-card">
                                    <div class="d-flex justify-content-between align-items-start mb-3">
                                        <h5 class="font-weight-bold">${product.product_name || 'N/A'}</h5>
                                        <div class="trend-score text-${scoreColor}">${product.trend_score || 0}</div>
                                    </div>
                                    <div class="mb-2">
                                        <span class="badge badge-primary">Tăng ${product.growth_rate || 0}%</span>
                                        <span class="badge ${riskBadge}">Rủi ro: ${product.risk_level || 'N/A'}</span>
                                    </div>
                                    <div class="small text-muted mb-2">
                                        <i class="fas fa-comments"></i> ${formatNumber(product.social_mentions || 0)} lượt thảo luận
                                    </div>
                                    <div class="small mb-2">
                                        <strong>Nền tảng:</strong> ${(product.platforms || []).join(', ')}
                                    </div>
                                    <div class="small mb-2">
                                        <strong>Đối tượng:</strong> ${product.target_audience || 'N/A'}
                                    </div>
                                    <div class="recommendation-box small">
                                        <i class="fas fa-lightbulb text-warning"></i> ${product.recommendation || 'Chưa có khuyến nghị'}
                                    </div>
                                </div>
                            </div>
                        `;
                    });
                } else {
                    html += '<div class="col-12"><div class="alert alert-info">Chưa có dữ liệu xu hướng</div></div>';
                }
                
                html += '</div>';
                
                $('#trendContent').html(html);
                console.log('Trend predictions displayed successfully');
                
            } catch (error) {
                console.error('Error displaying trend predictions:', error);
                $('#trendContent').html('<div class="alert alert-danger">Lỗi hiển thị xu hướng: ' + error.message + '</div>');
            }
        }

        function loadInventoryInsights() {
            $('#inventoryMovement').html('<div class="text-center"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Đang phân tích tồn kho...</p></div>');
            
            return loadInventoryInsightsAsync();
        }
        
        function loadInventoryInsightsAsync() {
            return new Promise((resolve, reject) => {
                $.ajax({
                    url: 'api_du_bao_ai.php',
                    method: 'GET',
                    data: { action: 'get_inventory_insights' },
                    dataType: 'json',
                    timeout: 10000,
                    success: function(response) {
                        console.log('Inventory Insights Response:', response);
                        if (response.success) {
                            displayInventoryInsights(response.data);
                            resolve(response.data);
                        } else {
                            $('#inventoryMovement').html('<div class="alert alert-danger">Lỗi phân tích tồn kho: ' + response.message + '</div>');
                            reject(new Error(response.message));
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Inventory Insights Error:', error);
                        $('#inventoryMovement').html('<div class="alert alert-danger">Lỗi kết nối tồn kho: ' + error + '</div>');
                        reject(new Error(error));
                    }
                });
            });
        }

        function displayInventoryInsights(data) {
            try {
                console.log('Displaying Inventory Insights:', data);
                
                // Ensure data structure exists
                if (!data) {
                    $('#inventoryMovement').html('<div class="alert alert-warning fade-in">Không có dữ liệu tồn kho</div>');
                    return;
                }
                
                // Initialize default summary if not exists
                const summary = data.summary || {
                    fast_moving_count: 0,
                    normal_count: 0,
                    slow_moving_count: 0
                };
                
                let html = `
                    <div class="row mb-3 fade-in">
                        <div class="col-md-4">
                            <div class="card bg-success text-white">
                                <div class="card-body text-center">
                                    <h3>${summary.fast_moving_count || 0}</h3>
                                    <p class="mb-0">Bán chạy</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card bg-info text-white">
                                <div class="card-body text-center">
                                    <h3>${summary.normal_count || 0}</h3>
                                    <p class="mb-0">Bình thường</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card bg-warning text-white">
                                <div class="card-body text-center">
                                    <h3>${summary.slow_moving_count || 0}</h3>
                                    <p class="mb-0">Bán chậm</p>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
                
                // Check if we have any detailed data
                const hasFastMoving = data.fast_moving && Array.isArray(data.fast_moving) && data.fast_moving.length > 0;
                const hasSlowMoving = data.slow_moving && Array.isArray(data.slow_moving) && data.slow_moving.length > 0;
                
                if (hasFastMoving) {
                    html += '<h6 class="text-success font-weight-bold mb-3">🚀 Top Sản phẩm Bán chạy</h6>';
                    html += '<div class="table-responsive"><table class="table table-sm table-hover">';
                    html += '<thead class="thead-light"><tr><th>Sản phẩm</th><th>Tồn kho</th><th>Bán/ngày</th><th>Số ngày hết hàng</th><th>Đề xuất</th></tr></thead><tbody>';
                    
                    data.fast_moving.slice(0, 5).forEach((item, index) => {
                        const daysStock = item.days_of_stock ? `${item.days_of_stock} ngày` : 'N/A';
                        const badgeClass = item.days_of_stock && item.days_of_stock < 10 ? 'badge-danger' : 'badge-warning';
                        
                        html += `
                            <tr>
                                <td><strong>${item.product_name || 'N/A'}</strong><br><small class="text-muted">${item.size || 'N/A'} - ${item.color || 'N/A'}</small></td>
                                <td><span class="badge badge-primary">${item.current_stock || 0}</span></td>
                                <td>${parseFloat(item.daily_velocity || 0).toFixed(1)}</td>
                                <td><span class="badge ${badgeClass}">${daysStock}</span></td>
                                <td><small class="text-success">${item.recommendation || 'Chưa có khuyến nghị'}</small></td>
                            </tr>
                        `;
                    });
                    html += '</tbody></table></div>';
                }
                
                if (hasSlowMoving) {
                    html += '<h6 class="text-warning font-weight-bold mb-3 mt-4">🐌 Sản phẩm Bán chậm</h6>';
                    html += '<div class="table-responsive"><table class="table table-sm table-hover">';
                    html += '<thead class="thead-light"><tr><th>Sản phẩm</th><th>Tồn kho</th><th>Bán/ngày</th><th>Đề xuất</th></tr></thead><tbody>';
                    
                    data.slow_moving.slice(0, 5).forEach((item, index) => {
                        html += `
                            <tr>
                                <td><strong>${item.product_name || 'N/A'}</strong><br><small class="text-muted">${item.size || 'N/A'} - ${item.color || 'N/A'}</small></td>
                                <td><span class="badge badge-secondary">${item.current_stock || 0}</span></td>
                                <td>${parseFloat(item.daily_velocity || 0).toFixed(1)}</td>
                                <td><small class="text-warning">${item.recommendation || 'Chưa có khuyến nghị'}</small></td>
                            </tr>
                        `;
                    });
                    html += '</tbody></table></div>';
                }
                
                if (!hasFastMoving && !hasSlowMoving) {
                    html += `
                        <div class="alert alert-info mt-3 fade-in">
                            <i class="fas fa-info-circle"></i> 
                            <strong>Thông tin:</strong> Hiện tại hệ thống đang sử dụng dữ liệu mẫu. 
                            Khi có giao dịch thực tế, phân tích sẽ được cập nhật tự động.
                        </div>
                    `;
                }
                
                $('#inventoryMovement').html(html);
                console.log('Inventory insights displayed successfully');
                
            } catch (error) {
                console.error('Error displaying inventory insights:', error);
                $('#inventoryMovement').html(`
                    <div class="alert alert-danger fade-in">
                        <i class="fas fa-exclamation-triangle"></i> 
                        <strong>Lỗi hiển thị:</strong> ${error.message}
                        <br><small>Vui lòng thử refresh trang hoặc liên hệ admin.</small>
                    </div>
                `);
            }
        }

        function loadEventForecast(month) {
            // Nếu không truyền tháng, dùng tháng hiện tại
            if (!month) {
                month = new Date().getMonth() + 1;
            }
            
            $('#festivalAnalysis').html('<div class="text-center"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Đang tải lịch sự kiện...</p></div>');
            
            $.ajax({
                url: 'api_du_bao_ai.php',
                method: 'GET',
                data: { 
                    action: 'get_event_forecast',
                    month: month
                },
                dataType: 'json',
                timeout: 10000,
                success: function(response) {
                    if (response.success) {
                        displayEventForecast(response.data);
                    } else {
                        $('#festivalAnalysis').html('<div class="alert alert-warning">Không thể tải dữ liệu sự kiện</div>');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Event Forecast Error:', error);
                    $('#festivalAnalysis').html('<div class="alert alert-warning">Đang cập nhật dữ liệu sự kiện...</div>');
                }
            });
        }

        function displayEventForecast(data) {
            try {
                console.log('Displaying Event Forecast:', data);
                
                if (!data || !data.upcoming_events || data.upcoming_events.length === 0) {
                    $('#festivalAnalysis').html('<div class="alert alert-info">Không có sự kiện nào trong 3 tháng tới</div>');
                    return;
                }
                
                let html = '<div class="event-timeline fade-in">';
                
                data.upcoming_events.forEach(event => {
                    const impactBadge = event.impact === 'very_high' ? 'badge-danger' : 
                                        (event.impact === 'high' ? 'badge-warning' : 'badge-info');
                    const impactText = event.impact === 'very_high' ? 'Rất cao' :
                                       (event.impact === 'high' ? 'Cao' : 'Trung bình');
                    
                    const categories = (event.categories || []).join(', ');
                    
                    html += `
                        <div class="event-item mb-3">
                            <div class="card border-left-primary shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start mb-3">
                                        <h5 class="font-weight-bold text-primary mb-0">
                                            <i class="fas fa-calendar-star"></i> ${event.name || 'Sự kiện'}
                                        </h5>
                                        <span class="badge ${impactBadge}">Ảnh hưởng: ${impactText}</span>
                                    </div>
                                    
                    <div class="mb-2">
                        <i class="fas fa-clock text-muted"></i>
                        <strong>Thời gian:</strong> 
                        <span class="text-muted">${event.forecast_date || event.forecast_month + '/' + event.forecast_year}</span>
                        ${event.forecast_year > new Date().getFullYear() ? '<span class="badge badge-info ml-2">Năm ' + event.forecast_year + '</span>' : ''}
                    </div>                                    <div class="mb-3">
                                        <i class="fas fa-box-open text-info"></i>
                                        <strong>Dòng sản phẩm:</strong> 
                                        <span class="text-info">${categories}</span>
                                    </div>
                                    
                                    <div class="alert alert-light border-left mb-0" style="border-left: 4px solid #224abe; background-color: #f8f9fc;">
                                        <div class="mb-1">
                                            <i class="fas fa-bullhorn text-primary"></i>
                                            <strong class="text-primary">Khuyến nghị:</strong>
                                        </div>
                                        <p class="mb-0" style="line-height: 1.6; font-size: 14px;">
                                            ${event.recommendation || 'Chưa có khuyến nghị'}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                });
                
                html += '</div>';
                $('#festivalAnalysis').html(html);
                console.log('Event forecast displayed successfully');
                
            } catch (error) {
                console.error('Error displaying event forecast:', error);
                $('#festivalAnalysis').html('<div class="alert alert-danger">Lỗi hiển thị sự kiện: ' + error.message + '</div>');
            }
        }

        // Helper function to format numbers safely
        function formatNumber(num) {
            try {
                return new Intl.NumberFormat('vi-VN').format(num || 0);
            } catch (e) {
                return (num || 0).toString();
            }
        }

        function formatCurrency(num) {
            try {
                return new Intl.NumberFormat('vi-VN', {
                    style: 'currency',
                    currency: 'VND'
                }).format(num || 0);
            } catch (e) {
                return (num || 0).toLocaleString() + ' đ';
            }
        }

        function showNotification(message, type = 'success') {
            const alertClass = type === 'success' ? 'alert-success' : 
                               (type === 'info' ? 'alert-info' : 'alert-danger');
            const icon = type === 'success' ? 'fas fa-check-circle' :
                        (type === 'info' ? 'fas fa-info-circle' : 'fas fa-exclamation-triangle');
            
            const notification = $(`
                <div class="alert ${alertClass} alert-dismissible fade show" role="alert" style="position: fixed; top: 80px; right: 20px; z-index: 9999; min-width: 300px;">
                    <i class="${icon}"></i> ${message}
                    <button type="button" class="close" data-dismiss="alert">
                        <span>&times;</span>
                    </button>
                </div>
            `);
            
            $('body').append(notification);
            
            // Auto dismiss based on type
            const timeout = type === 'error' ? 5000 : 3000;
            setTimeout(() => notification.alert('close'), timeout);
        }
        
        // Helper function to clear loading states
        function clearLoadingState(elementId) {
            $(`#${elementId}`).find('.fa-spinner').closest('div').remove();
            $(`#${elementId}`).removeClass('loading-overlay content-loading');
        }
        
        // Global error handler for JavaScript errors
        window.onerror = function(msg, url, lineNo, columnNo, error) {
            console.error('JavaScript Error:', {
                message: msg,
                source: url,
                line: lineNo,
                column: columnNo,
                error: error
            });
            
            showNotification('⚠️ Đã xảy ra lỗi JavaScript. Vui lòng refresh trang.', 'error');
            return false;
        };
        
        // Handle unhandled promise rejections
        window.addEventListener('unhandledrejection', function(event) {
            console.error('Unhandled promise rejection:', event.reason);
            showNotification('⚠️ Lỗi bất đồng bộ. Vui lòng thử lại.', 'error');
        });

        // ========== NEW VIETNAM FUNCTIONS ==========
        
        function loadVietnamClimate() {
            const month = $('#climateMonthSelect').val() || new Date().getMonth() + 1;
            
            $('#northClimate, #centralClimate, #southClimate, #festivalAnalysis, #restockNorth, #restockCentral, #restockSouth').html(
                '<div class="text-center"><div class="loading-spinner"></div></div>'
            );
            
            // Load climate data
            $.ajax({
                url: 'api_du_bao_ai.php',
                method: 'GET',
                data: { action: 'get_vietnam_climate', month: month },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        displayClimateAnalysis(response.data, month);
                        showNotification('🌤️ Dữ liệu khí hậu VN tải thành công!', 'success');
                    } else {
                        $('#climateAnalysis').html('<div class="alert alert-danger">Lỗi tải khí hậu</div>');
                    }
                },
                error: function() {
                    $('#climateAnalysis').html('<div class="alert alert-danger">Lỗi kết nối khí hậu</div>');
                }
            });
            
            // Load event forecast - Gọi hàm đúng để hiển thị chi tiết
            loadEventForecast(month);
            
            // Load restock suggestions - KHÍ HẬU (3 miền)
            loadClimateRestockSuggestions(month, 'north', '#restockClimateNorth', '#countClimateNorth');
            loadClimateRestockSuggestions(month, 'central', '#restockClimateCentral', '#countClimateCentral');
            loadClimateRestockSuggestions(month, 'south', '#restockClimateSouth', '#countClimateSouth');
            
            // Load restock suggestions - TỒN KHO (theo priority)
            loadLowStockSuggestions('#restockCritical', '#countCritical', 'CRITICAL');
            loadLowStockSuggestions('#restockHigh', '#countHigh', 'HIGH');
            loadLowStockSuggestions('#restockMedium', '#countMedium', 'MEDIUM');
        }
        
        // Function 1: Load gợi ý dựa trên KHÍ HẬU & SỰ KIỆN
        let climateLoadingFlags = {};
        function loadClimateRestockSuggestions(month, region, targetElement, badgeElement) {
            const key = month + '_' + region;
            
            // Prevent duplicate requests
            if (climateLoadingFlags[key]) {
                return;
            }
            
            climateLoadingFlags[key] = true;
            $(targetElement).html('<div class="text-center py-3"><i class="fas fa-spinner fa-spin"></i></div>');
            
            $.ajax({
                url: 'api_du_bao_ai.php',
                method: 'GET',
                data: { 
                    action: 'get_restock_suggestions',
                    month: month,
                    region: region,
                    type: 'climate' // ← Filter theo khí hậu
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        displayRestockSuggestions(response.data, targetElement, badgeElement);
                    } else {
                        $(targetElement).html('<div class="alert alert-danger small">' + response.message + '</div>');
                        $(badgeElement).text(0);
                    }
                },
                error: function() {
                    $(targetElement).html('<div class="alert alert-danger small">Lỗi tải dữ liệu</div>');
                    $(badgeElement).text(0);
                },
                complete: function() {
                    climateLoadingFlags[key] = false;
                }
            });
        }
        
        // Function 2: Load gợi ý dựa trên TỒN KHO THẤP
        let lowStockLoadingFlags = {};
        function loadLowStockSuggestions(targetElement, badgeElement, priority) {
            // Prevent duplicate requests
            if (lowStockLoadingFlags[priority]) {
                return;
            }
            
            lowStockLoadingFlags[priority] = true;
            $(targetElement).html('<div class="text-center py-3"><i class="fas fa-spinner fa-spin"></i></div>');
            
            $.ajax({
                url: 'api_du_bao_ai.php',
                method: 'GET',
                data: { 
                    action: 'get_low_stock_suggestions',
                    priority: priority // CRITICAL, HIGH, MEDIUM
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        displayLowStockSuggestions(response.data, targetElement, badgeElement);
                    } else {
                        $(targetElement).html('<div class="alert alert-info small">Không có sản phẩm ' + priority + '</div>');
                        $(badgeElement).text(0);
                    }
                },
                error: function() {
                    $(targetElement).html('<div class="alert alert-danger small">Lỗi tải dữ liệu</div>');
                    $(badgeElement).text(0);
                },
                complete: function() {
                    lowStockLoadingFlags[priority] = false;
                }
            });
        }
        
        function displayRestockSuggestions(data, targetElement, badgeElement) {
            if (!data.suggestions || data.suggestions.length === 0) {
                $(targetElement).html('<div class="alert alert-info small">Không có gợi ý</div>');
                if (badgeElement) $(badgeElement).text(0);
                return;
            }
            
            // Update count badge - without pulse to reduce jitter
            if (badgeElement) {
                $(badgeElement).text(data.suggestions.length);
            }
            
            let html = `
                <div class="alert alert-light p-2 mb-3">
                    <small>
                        <strong>${data.season}</strong> (${data.temperature})<br>
                        <strong>HOT:</strong> ${data.hot_shoe_types.slice(0, 3).join(', ')}
                    </small>
                </div>`;
            
            // Hiển thị tất cả sản phẩm (không giới hạn 3)
            data.suggestions.forEach(product => {
                const priorityClass = {
                    'CRITICAL': 'danger',
                    'HIGH': 'warning',
                    'MEDIUM': 'info'
                }[product.priority] || 'secondary';
                
                const priorityIcon = {
                    'CRITICAL': 'fa-exclamation-triangle',
                    'HIGH': 'fa-exclamation-circle',
                    'MEDIUM': 'fa-info-circle'
                }[product.priority] || 'fa-info';
                
                html += `
                    <div class="card mb-3 shadow-sm border-${priorityClass}">
                        <div class="row no-gutters">
                            <div class="col-5">
                                <div class="position-relative">
                                    <img src="${product.image_url}" class="card-img" alt="${product.product_name}" 
                                         style="height: 150px; object-fit: cover;"
                                         onerror="this.src='default-shoe.jpg'">
                                    <span class="badge badge-${priorityClass} position-absolute" style="top: 5px; left: 5px; font-size: 0.7rem;">
                                        <i class="fas ${priorityIcon}"></i>
                                    </span>
                                </div>
                            </div>
                            <div class="col-7">
                                <div class="card-body p-2">
                                    <h6 class="card-title mb-1" style="font-size: 0.85rem;" title="${product.product_name}">
                                        ${product.product_name.length > 25 ? product.product_name.substring(0, 25) + '...' : product.product_name}
                                    </h6>
                                    <p class="mb-1">
                                        <span class="badge badge-secondary badge-sm" style="font-size: 0.65rem;">${product.category}</span>
                                    </p>
                                    
                                    <div class="mb-1">
                                        <small class="text-muted" style="font-size: 0.7rem;">Tồn:</small>
                                        <strong class="text-${product.current_stock < 50 ? 'danger' : 'success'}" style="font-size: 0.8rem;">
                                            ${product.current_stock}
                                        </strong>
                                        <small class="text-muted" style="font-size: 0.65rem;">
                                            (${product.days_of_stock_remaining}d)
                                        </small>
                                    </div>
                                    
                                    <div class="mb-1">
                                        <small class="text-muted" style="font-size: 0.7rem;">Bán 30d:</small>
                                        <strong style="font-size: 0.8rem;">${product.total_sold_30days}</strong>
                                    </div>
                                    
                                    <div class="alert alert-${priorityClass} p-1 mb-1" style="font-size: 0.7rem;">
                                        <strong>Nên nhập: ${product.suggested_quantity}</strong>
                                        ${product.event_boost && product.event_boost > 1.0 ? 
                                            '<br><span class="badge badge-success" style="font-size: 0.65rem;">🎉 x' + product.event_boost + '</span>' : 
                                            ''}
                                    </div>
                                    
                                    <div class="mb-1" style="font-size: 0.65rem; line-height: 1.2;">
                                        <em class="text-muted">${product.reason}</em>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>`;
                                
            });
            
            $(targetElement).html(html);
        }
        
        // Display LOW STOCK suggestions (không có thông tin khí hậu)
        function displayLowStockSuggestions(data, targetElement, badgeElement) {
            if (!data.suggestions || data.suggestions.length === 0) {
                $(targetElement).html('<div class="alert alert-info small">Tồn kho ổn định</div>');
                $(badgeElement).text(0);
                return;
            }
            
            // Update count - without pulse to reduce jitter
            $(badgeElement).text(data.suggestions.length);
            
            let html = '';
            
            data.suggestions.forEach(product => {
                const priorityClass = {
                    'CRITICAL': 'danger',
                    'HIGH': 'warning',
                    'MEDIUM': 'info'
                }[product.priority] || 'secondary';
                
                const priorityIcon = {
                    'CRITICAL': 'fa-exclamation-triangle',
                    'HIGH': 'fa-exclamation-circle',
                    'MEDIUM': 'fa-info-circle'
                }[product.priority] || 'fa-info';
                
                html += `
                    <div class="card mb-3 shadow-sm border-${priorityClass}">
                        <div class="row no-gutters">
                            <div class="col-5">
                                <div class="position-relative">
                                    <img src="${product.image_url}" class="card-img" alt="${product.product_name}" 
                                         style="height: 150px; object-fit: cover;"
                                         onerror="this.src='default-shoe.jpg'">
                                    <span class="badge badge-${priorityClass} position-absolute" style="top: 5px; left: 5px; font-size: 0.7rem;">
                                        <i class="fas ${priorityIcon}"></i> ${product.priority}
                                    </span>
                                </div>
                            </div>
                            <div class="col-7">
                                <div class="card-body p-2">
                                    <h6 class="card-title mb-1" style="font-size: 0.85rem;" title="${product.product_name}">
                                        ${product.product_name.length > 25 ? product.product_name.substring(0, 25) + '...' : product.product_name}
                                    </h6>
                                    <p class="mb-1">
                                        <span class="badge badge-secondary badge-sm" style="font-size: 0.65rem;">${product.category}</span>
                                    </p>
                                    
                                    <div class="mb-1">
                                        <small class="text-danger" style="font-size: 0.7rem;"><strong>⚠️ Tồn: ${product.current_stock}</strong></small>
                                        <small class="text-muted" style="font-size: 0.65rem;"> (${product.days_of_stock_remaining}d)</small>
                                    </div>
                                    
                                    <div class="mb-1">
                                        <small class="text-muted" style="font-size: 0.7rem;">Bán 30d:</small>
                                        <strong class="text-success" style="font-size: 0.8rem;">${product.total_sold_30days}</strong>
                                    </div>
                                    
                                    <div class="alert alert-${priorityClass} p-1 mb-1" style="font-size: 0.7rem;">
                                        <strong>Nên nhập NGAY: ${product.suggested_quantity}</strong>
                                    </div>
                                    
                                    <div class="mb-1" style="font-size: 0.65rem; line-height: 1.2;">
                                        <em class="text-danger">⏰ ${product.reason || 'Tồn kho thấp, cần nhập gấp'}</em>
                                    </div>
                                    
                                    <button class="btn btn-sm btn-block btn-${priorityClass}" style="font-size: 0.7rem; padding: 0.25rem;" 
                                            onclick="createRestockOrder(${product.product_id}, ${product.suggested_quantity})">
                                        <i class="fas fa-cart-plus"></i> Nhập gấp
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>`;
            });
            
            $(targetElement).html(html);
        }
        
        function createRestockOrder(productId, quantity) {
            showNotification(`Đang tạo đơn nhập ${quantity} sản phẩm ID: ${productId}...`, 'info');
            // TODO: Implement create restock order functionality
            // Redirect to stock receipt page with pre-filled data
            window.location.href = `api_phieu_nhap_don_gian.php?product_id=${productId}&quantity=${quantity}`;
        }

        function displayClimateAnalysis(data, selectedMonth) {
            const regions = ['north', 'central', 'south'];
            
            // Thông tin mùa cố định cho mỗi miền
            const seasonalInfo = {
                north: {
                    title: '📅 4 Mùa Rõ Rệt',
                    seasons: [
                        { name: 'Đông (T12-T2)', temp: '13-22°C', note: 'Boots, giày đóng' },
                        { name: 'Xuân (T2-T4)', temp: '15-30°C', note: 'Mưa phùn, giày chống nước nhẹ' },
                        { name: 'Hạ (T5-T8)', temp: '25-37°C', note: 'Sandal, giày thoáng' },
                        { name: 'Thu (T9-T11)', temp: '18-32°C', note: 'Thời tiết đẹp, đa dạng giày' }
                    ]
                },
                central: {
                    title: '🌡️ Khí Hậu Khắc Nghiệt',
                    seasons: [
                        { name: 'Mùa khô (T1-T8)', temp: '18-39°C', note: '<span class="text-danger font-weight-bold">NÓNG NHẤT VN</span> (T5-T6: 38-39°C)' },
                        { name: 'Mùa bão (T9-T11)', temp: '21-30°C', note: '<span class="text-danger">Bão lũ nghiêm trọng</span>, giày chống nước bắt buộc' },
                        { name: 'T12', temp: '19-26°C', note: 'Khô mát trở lại' }
                    ]
                },
                south: {
                    title: '🌴 Nóng Quanh Năm',
                    seasons: [
                        { name: 'Mùa khô (T11-T4)', temp: '22-36°C', note: 'Sandal, giày thời trang' },
                        { name: 'Mùa mưa (T5-T10)', temp: '25-34°C', note: 'Mưa chiều đều đặn, giày chống nước' }
                    ]
                }
            };
            
            regions.forEach((region) => {
                const regionData = data[region];
                // Get data for selected month, or fallback to first available month
                const monthData = regionData.monthly_analysis[selectedMonth] || Object.values(regionData.monthly_analysis)[0];
                const seasonInfo = seasonalInfo[region];
                
                let html = `
                    <h6 class="font-weight-bold text-primary">${regionData.region_name}</h6>
                    <div class="mb-3">
                        <small class="text-muted">${regionData.climate_type}</small>
                    </div>
                    
                    <!-- Thông tin mùa cố định -->
                    <div class="card bg-light mb-3">
                        <div class="card-body p-2">
                            <h6 class="mb-2" style="font-size: 0.9rem;">${seasonInfo.title}</h6>
                            <small>`;
                
                seasonInfo.seasons.forEach((season, index) => {
                    if (index > 0) html += '<br>';
                    html += `<strong>${season.name}:</strong> ${season.temp} - ${season.note}`;
                });
                
                html += `</small>
                        </div>
                    </div>
                    
                    <!-- Thông tin tháng hiện tại -->
                    <div class="alert alert-warning mb-3" style="padding: 0.5rem;">
                        <strong>📌 Tháng ${selectedMonth}:</strong> ${monthData.season}<br>
                        <strong>🌡️ Nhiệt độ:</strong> ${monthData.temp_range}<br>
                        <strong>💧 Độ ẩm:</strong> ${monthData.humidity}<br>
                        <strong>🌧️ Mưa:</strong> ${monthData.rainfall}
                    </div>
                    
                    <div class="mb-3">
                        <h6 class="text-success">Giày HOT:</h6>
                        <ul class="list-unstyled">`;
                
                monthData.shoe_demand.high.forEach(shoe => {
                    html += `<li><i class="fas fa-fire text-danger"></i> ${shoe}</li>`;
                });
                
                html += `</ul></div>
                    
                    <div class="alert alert-info">
                        <strong>💡 Khuyến nghị:</strong><br>
                        ${monthData.business_advice}
                    </div>`;
                
                $(`#${region}Climate`).html(html);
            });
        }

        function displayFestivalAnalysis(data, month) {
            if (!data.major_events || Object.keys(data.major_events).length === 0) {
                $('#festivalAnalysis').html('<div class="alert alert-info">Không có sự kiện đặc biệt trong tháng này</div>');
                return;
            }
            
            let html = '<div class="row">';
            
            Object.entries(data.major_events).forEach(([eventName, eventData]) => {
                const impactColor = eventData.impact_level === 'CRITICAL' ? 'danger' : 
                                    (eventData.impact_level === 'HIGH' ? 'warning' : 'info');
                
                html += `
                    <div class="col-md-6 mb-3">
                        <div class="card border-left-${impactColor}">
                            <div class="card-body">
                                <h6 class="font-weight-bold text-${impactColor}">${eventName}</h6>
                                <div class="mb-2">
                                    <span class="badge badge-${impactColor}">${eventData.impact_level}</span>
                                </div>`;
                
                if (eventData.business_strategy) {
                    html += `<div class="alert alert-light"><strong>Chiến lược:</strong> ${eventData.business_strategy}</div>`;
                }
                
                html += '</div></div></div>';
            });
            
            html += '</div>';
            $('#festivalAnalysis').html(html);
        }

        function loadSocialTrends() {
            $('#socialTrendsContent').html(
                '<div class="text-center"><div class="loading-spinner"></div></div>'
            );
            
            $.ajax({
                url: 'api_du_bao_ai.php',
                method: 'GET',
                data: { action: 'get_social_trends' },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        displaySocialTrends(response.data);
                        showNotification('📱 Social trends tải thành công!', 'success');
                    }
                },
                error: function() {
                    $('#socialTrendsContent').html('<div class="alert alert-danger">Lỗi tải social trends</div>');
                }
            });
        }

        function displaySocialTrends(data) {
            // TikTok Trends
            let tiktokHtml = '';
            data.trending_products.tiktok_trends.forEach(trend => {
                tiktokHtml += `
                    <div class="card mb-3 border-left-success">
                        <div class="card-body">
                            <h6 class="font-weight-bold text-success">#${trend.keyword}</h6>
                            <p><i class="fas fa-eye"></i> <strong>${trend.mentions.toLocaleString()}</strong> mentions</p>
                            <p><i class="fas fa-users"></i> Target: <strong>${trend.target_age}</strong></p>
                            <div class="alert alert-success">
                                <strong>💡 Khuyến nghị:</strong> ${trend.recommendation}
                            </div>
                        </div>
                    </div>`;
            });
            let html = '<div class="row">';
            
            // TikTok Trends
            if (data.trending_products && data.trending_products.tiktok_trends) {
                html += '<div class="col-md-6"><h6 class="text-primary">🎵 TikTok Trends</h6>';
                data.trending_products.tiktok_trends.forEach(trend => {
                    html += `
                        <div class="card mb-2 border-left-success">
                            <div class="card-body p-2">
                                <h6 class="font-weight-bold text-success small">#${trend.keyword}</h6>
                                <p class="small mb-1"><i class="fas fa-eye"></i> ${trend.mentions.toLocaleString()} mentions</p>
                                <p class="small mb-1"><i class="fas fa-users"></i> Target: ${trend.target_age}</p>
                                <div class="alert alert-success p-2 mb-0 small">
                                    <strong>💡:</strong> ${trend.recommendation}
                                </div>
                            </div>
                        </div>`;
                });
                html += '</div>';
            }
            
            // Stock Recommendations
            if (data.business_insights && data.business_insights.stock_recommendations) {
                html += '<div class="col-md-6"><h6 class="text-info">📦 Khuyến nghị Tồn kho</h6>';
                data.business_insights.stock_recommendations.forEach(rec => {
                    const priorityColor = rec.priority === 'HIGH' ? 'danger' : 'warning';
                    html += `
                        <div class="card mb-2 border-left-${priorityColor}">
                            <div class="card-body p-2">
                                <h6 class="text-${priorityColor} small">${rec.category}</h6>
                                <p class="small mb-1"><strong>Số lượng:</strong> ${rec.recommended_qty}</p>
                                <p class="small mb-1"><strong>Thời gian:</strong> ${rec.timeline}</p>
                                <div class="text-muted small">${rec.reasoning || rec.reason}</div>
                            </div>
                        </div>`;
                });
                html += '</div>';
            }
            
            html += '</div>';
            $('#socialTrendsContent').html(html);
        }

        // Accounting functions removed - displayInventoryAccounting, generateRecommendations, chart functions

        // Tab change handlers
        $('a[data-toggle="tab"]').on('shown.bs.tab', function (e) {
            const target = $(e.target).attr("href");
            
            switch(target) {
                case '#market-trends':
                    loadMarketTrends();
                    break;
            }
        });
        
        // Combined Market Trends Function
        function loadMarketTrends() {
            // Load all market trend components
            loadSocialTrends();
            loadVietnamClimate();
            // Load event với tháng hiện tại khi lần đầu vào tab
            const currentMonth = new Date().getMonth() + 1;
            loadEventForecast(currentMonth);
        }

        // Load Real AI Insights from inventory data (same as trang_chu.php)
        function loadRealAIInsights() {
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
                    // Tách các JSON objects
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
                        return;
                    }
                    
                    const data = response.data;
                    
                    // Display business insights
                    let insightsHtml = '';
                    
                    // Tạo insights từ fast_moving products
                    if (data.fast_moving && data.fast_moving.length > 0) {
                        const topFast = data.fast_moving.slice(0, 3);
                        insightsHtml += `
                            <div class="card mb-3 border-left-success">
                                <div class="card-body">
                                    <h6 class="font-weight-bold text-success">💡 Doanh thu tăng trưởng mạnh</h6>
                                    <p class="mb-2">Có ${data.summary.fast_moving_count} sản phẩm bán chạy. Top ${topFast.length}: ${topFast.map(p => p.product_name).join(', ')}.</p>
                                    <small class="text-muted"><i class="fas fa-chart-line"></i> Tác động: Tích cực cao</small>
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
                    }
                    
                    // Display next month forecast
                    let totalDailyRevenue = 0;
                    if (data.fast_moving && data.fast_moving.length > 0) {
                        data.fast_moving.forEach(item => {
                            totalDailyRevenue += (parseFloat(item.daily_velocity) || 0) * 500000;
                        });
                    }
                    
                    const expectedMonthlySales = Math.round(totalDailyRevenue * 30);
                    
                    if (expectedMonthlySales > 0) {
                        $('#nextMonthSales').text(expectedMonthlySales.toLocaleString('vi-VN'));
                    }
                    
                    // Inventory needs
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
                    }
                    
                    // Seasonal factors
                    const currentMonth = new Date().getMonth() + 1;
                    let factorsHtml = '';
                    
                    if (currentMonth === 12) {
                        factorsHtml = `
                            <div class="alert alert-info mb-2">
                                <i class="fas fa-calendar-alt"></i>
                                <strong>Yếu tố mùa vụ tháng 12:</strong> Ảnh hưởng tích cực
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
                    }
                    
                    $('#seasonalFactorsContainer').html(factorsHtml);
                },
                error: function(xhr, status, error) {
                    console.error('AI Insights Error:', error);
                }
            });
        }

        // AI Insights Display Function
        function displayAIInsights(insights) {
            if (!insights) {
                console.log('No AI insights data available');
                return;
            }
            
            console.log('Displaying AI insights:', insights);
            
            // Update warehouse health score
            if (insights.warehouse_health_score) {
                const score = insights.warehouse_health_score;
                const scoreElement = $('#warehouseHealthScore');
                const progressElement = $('#healthScoreProgress');
                
                if (scoreElement.length && progressElement.length) {
                    scoreElement.text(score + '%');
                    progressElement.css('width', score + '%');
                    
                    // Color coding based on score
                    progressElement.removeClass('bg-success bg-warning bg-danger');
                    if (score >= 80) {
                        progressElement.addClass('bg-success');
                    } else if (score >= 60) {
                        progressElement.addClass('bg-warning');
                    } else {
                        progressElement.addClass('bg-danger');
                    }
                }
            }
            
            // Display risk alerts
            if (insights.risk_alerts && insights.risk_alerts.length > 0) {
                let alertsHtml = '';
                insights.risk_alerts.forEach(alert => {
                    const alertClass = alert.level === 'high' ? 'alert-danger' : 
                                     alert.level === 'medium' ? 'alert-warning' : 'alert-info';
                    
                    alertsHtml += `
                        <div class="alert ${alertClass} alert-dismissible fade show" role="alert">
                            <i class="fas fa-exclamation-triangle"></i>
                            <strong>${alert.title}</strong><br>
                            ${alert.description}
                            <button type="button" class="close" data-dismiss="alert">
                                <span>&times;</span>
                            </button>
                        </div>
                    `;
                });
                $('#riskAlertsContainer').html(alertsHtml);
            }
            
            // Display business insights
            if (insights.business_insights && insights.business_insights.length > 0) {
                let insightsHtml = '';
                insights.business_insights.forEach(insight => {
                    insightsHtml += `
                        <div class="card mb-3 border-left-primary">
                            <div class="card-body">
                                <h6 class="font-weight-bold text-primary">💡 ${insight.title}</h6>
                                <p class="mb-2">${insight.description}</p>
                                ${insight.impact ? `<small class="text-muted"><i class="fas fa-chart-line"></i> Tác động: ${insight.impact}</small>` : ''}
                            </div>
                        </div>
                    `;
                });
                $('#businessInsightsContainer').html(insightsHtml);
            }
            
            // Display next month forecast
            if (insights.next_month_forecast) {
                const forecast = insights.next_month_forecast;
                
                if (forecast.expected_sales) {
                    $('#nextMonthSales').text(Number(forecast.expected_sales).toLocaleString('vi-VN'));
                }
                
                if (forecast.inventory_needs && forecast.inventory_needs.length > 0) {
                    let needsHtml = '';
                    forecast.inventory_needs.forEach(need => {
                        needsHtml += `
                            <div class="mb-2">
                                <strong>${need.product}</strong>: 
                                <span class="text-primary">${need.quantity}</span> đơn vị
                                ${need.reason ? `<small class="text-muted d-block">${need.reason}</small>` : ''}
                            </div>
                        `;
                    });
                    $('#inventoryNeedsContainer').html(needsHtml);
                }
                
                if (forecast.seasonal_factors && forecast.seasonal_factors.length > 0) {
                    let factorsHtml = '';
                    forecast.seasonal_factors.forEach(factor => {
                        factorsHtml += `
                            <div class="alert alert-info mb-2">
                                <i class="fas fa-calendar-alt"></i>
                                <strong>${factor.event}:</strong> ${factor.impact}
                            </div>
                        `;
                    });
                    $('#seasonalFactorsContainer').html(factorsHtml);
                }
            }
            
            // Display recommendations
            if (insights.recommendations && insights.recommendations.length > 0) {
                let recommendationsHtml = '';
                insights.recommendations.forEach((rec, index) => {
                    const priority = rec.priority || 'medium';
                    const priorityClass = priority === 'high' ? 'badge-danger' : 
                                        priority === 'medium' ? 'badge-warning' : 'badge-info';
                    
                    recommendationsHtml += `
                        <div class="card mb-3 border-left-success">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <h6 class="font-weight-bold text-success mb-0">${rec.title}</h6>
                                    <span class="badge ${priorityClass}">${priority}</span>
                                </div>
                                <p class="mb-2">${rec.description}</p>
                                ${rec.expected_impact ? `
                                    <div class="alert alert-light mb-0">
                                        <small><i class="fas fa-chart-line"></i> 
                                        Tác động dự kiến: ${rec.expected_impact}</small>
                                    </div>
                                ` : ''}
                            </div>
                        </div>
                    `;
                });
                $('#recommendationsContainer').html(recommendationsHtml);
            }
            
            // Show success notification
            showNotification('🤖 AI insights đã được cập nhật!', 'success');
        }

        // ============================================
        // XUẤT EXCEL GỢI Ý NHẬP HÀNG
        // ============================================
        function exportForecastToExcel() {
            const month = $('#climateMonthSelect').val() || new Date().getMonth() + 1;
            
            // Show loading
            showNotification('📊 Đang tạo file Excel...', 'info');
            
            // Create form and submit
            const form = document.createElement('form');
            form.method = 'GET';
            form.action = 'api_du_bao_xuat.php';
            form.target = '_blank';
            
            const monthInput = document.createElement('input');
            monthInput.type = 'hidden';
            monthInput.name = 'month';
            monthInput.value = month;
            
            form.appendChild(monthInput);
            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
            
            // Show success after delay
            setTimeout(() => {
                showNotification('✅ File Excel đã được tạo! Vui lòng kiểm tra download.', 'success');
            }, 1000);
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

        // Initialize
        $(document).ready(function() {
            $('#climateMonthSelect').val(new Date().getMonth() + 1);
            
            // Load default tab content (Market Trends)
            loadMarketTrends();
        });
        
    </script>

<?php include 'includes/chan_trang.php'; ?>
</html>
