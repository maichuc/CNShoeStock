<?php
session_start();
require_once 'config/database.php';
require_once 'classes/KiemSoatTruyCapKho.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    header('Location: login.html');
    exit();
}

// Kết nối database
$database = new Database();
$pdo = $database->getConnection();
$warehouseControl = new WarehouseAccessControl($pdo);

$currentUser = $_SESSION['user_id'];
$userWarehouseId = $_SESSION['warehouse_id'] ?? null;

// Nếu không có warehouse_id trong session, lấy từ database
if (!$userWarehouseId) {
    $userWarehouseId = $warehouseControl->getUserWarehouseId($currentUser);
    $_SESSION['warehouse_id'] = $userWarehouseId;
}

// Lấy thông tin warehouse
$stmt = $pdo->prepare("SELECT * FROM warehouses WHERE warehouse_id = ?");
$stmt->execute([$userWarehouseId]);
$warehouse = $stmt->fetch();

// Kiểm tra filter từ URL
$filter = $_GET['filter'] ?? '';

if ($filter === 'low_stock') {
    // Query chi tiết theo variant để hiển thị tồn kho từng size/màu
    $sql = "
        SELECT 
            p.product_id,
            p.name as product_name,
            p.description,
            p.created_at,
            p.brand,
            p.type,
            pi.file_path as image_path,
            w.name as warehouse_name,
            pv.variant_id,
            pv.size,
            pv.color,
            pv.price,
            COALESCE(i.quantity, 0) as variant_stock,
            COALESCE(i.quantity, 0) as total_stock
        FROM products p
        LEFT JOIN product_images pi ON p.product_id = pi.product_id AND pi.is_primary = 1
        LEFT JOIN product_variants pv ON p.product_id = pv.product_id
        LEFT JOIN inventory i ON pv.variant_id = i.variant_id AND i.warehouse_id = p.warehouse_id
        LEFT JOIN warehouses w ON p.warehouse_id = w.warehouse_id
        WHERE p.warehouse_id = ? AND p.status = 'active'
        ORDER BY COALESCE(i.quantity, 0) ASC, p.name ASC, pv.size ASC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userWarehouseId]);
    $rawProducts = $stmt->fetchAll();
    
    // Nhóm lại theo product_id nhưng giữ thông tin variant
    $products = [];
    foreach ($rawProducts as $row) {
        $pid = $row['product_id'];
        if (!isset($products[$pid])) {
            $products[$pid] = [
                'product_id' => $row['product_id'],
                'product_name' => $row['product_name'],
                'description' => $row['description'],
                'created_at' => $row['created_at'],
                'brand' => $row['brand'],
                'type' => $row['type'],
                'image_path' => $row['image_path'],
                'warehouse_name' => $row['warehouse_name'],
                'variants' => [],
                'min_price' => $row['price'],
                'max_price' => $row['price'],
                'total_stock' => 0,
                'variant_count' => 0
            ];
        }
        
        $products[$pid]['variants'][] = [
            'variant_id' => $row['variant_id'],
            'size' => $row['size'],
            'color' => $row['color'],
            'price' => $row['price'],
            'stock' => $row['variant_stock']
        ];
        
        $products[$pid]['total_stock'] += $row['variant_stock'];
        $products[$pid]['variant_count']++;
        $products[$pid]['min_price'] = min($products[$pid]['min_price'], $row['price']);
        $products[$pid]['max_price'] = max($products[$pid]['max_price'], $row['price']);
    }
    
    // Chuyển từ associative array sang indexed array
    $products = array_values($products);
    
    // Sắp xếp products theo tồn kho tăng dần (hết hàng lên đầu)
    usort($products, function($a, $b) {
        // So sánh tồn kho
        if ($a['total_stock'] != $b['total_stock']) {
            return $a['total_stock'] - $b['total_stock'];
        }
        // Nếu tồn kho bằng nhau, sắp xếp theo tên
        return strcmp($a['product_name'], $b['product_name']);
    });
    
} else {
    // Query mặc định - nhóm theo sản phẩm
    $sql = "
        SELECT 
            p.product_id,
            p.name as product_name,
            p.description,
            p.created_at,
            p.brand,
            p.type,
            pi.file_path as image_path,
            w.name as warehouse_name,
            COUNT(pv.variant_id) as variant_count,
            MIN(pv.price) as min_price,
            MAX(pv.price) as max_price,
            COALESCE(SUM(i.quantity), 0) as total_stock
        FROM products p
        LEFT JOIN product_images pi ON p.product_id = pi.product_id AND pi.is_primary = 1
        LEFT JOIN product_variants pv ON p.product_id = pv.product_id
        LEFT JOIN inventory i ON pv.variant_id = i.variant_id AND i.warehouse_id = p.warehouse_id
        LEFT JOIN warehouses w ON p.warehouse_id = w.warehouse_id
        WHERE p.warehouse_id = ? AND p.status = 'active'
        GROUP BY p.product_id, p.name, p.description, p.created_at, p.brand, p.type, pi.file_path, w.name
        ORDER BY p.created_at DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userWarehouseId]);
    $products = $stmt->fetchAll();
}
?>

<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Danh sách sản phẩm - Smart Warehouse System</title>

    <!-- Custom fonts for this template -->
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">

    <!-- Custom styles for this template -->
    <link href="css/sb-admin-2.min.css" rel="stylesheet">

    <!-- Page level plugin CSS-->
    <link href="vendor/datatables/dataTables.bootstrap4.min.css" rel="stylesheet">
    
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        .product-image {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid #e3e6f0;
        }
        
        .product-placeholder {
            width: 60px;
            height: 60px;
            background: #f5f5dc; /* Updated to beige */
            border: 1px solid #d1d5db; /* Neutral gray */
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #000000; /* Black text */
        }
        
        .type-badge {
            background: #224abe; /* Updated to blue */
            color: white;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .variant-count {
            background: #f8f9fc;
            color: #858796;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .badge-pill {
            border-radius: 10rem;
            font-weight: 600;
            letter-spacing: 0.3px;
        }
        
        .price-range {
            font-weight: 600;
            color: #28a745;
            white-space: nowrap;
        }
        
        .btn-action {
            padding: 4px 8px;
            margin: 0 2px;
            border-radius: 4px;
            font-size: 12px;
        }
        
        .stats-card {
            border-left: 4px solid #4e73df !important;
        }
        
        /* Advanced Responsive Table Styling */
        .table-container {
            background: white;
            border-radius: 0.5rem;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            overflow: hidden;
        }
        
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            border-radius: 0.5rem;
            max-width: 100%;
        }
        
        #dataTable {
            margin-bottom: 0;
            border-collapse: collapse;
            width: 100%;
        }
        
        #dataTable_wrapper .dataTables_scroll {
            overflow-x: auto;
        }
        
        #dataTable thead th {
            background: #224abe; /* Updated to blue */
            color: white;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 11px;
            letter-spacing: 0.5px;
            border: none;
            padding: 12px 8px;
            text-align: center;
            position: sticky;
            top: 0;
            z-index: 10;
            border-bottom: 2px solid #1a3a8f; /* Darker blue */
        }
        
        #dataTable tbody td {
            padding: 10px 8px;
            border-bottom: 1px solid #d1d5db; /* Neutral gray */
            border-right: 1px solid #e5e7eb; /* Light gray */
            vertical-align: middle;
            font-size: 13px;
            text-align: center;
        }
        
        #dataTable tbody tr:hover {
            background-color: #f5f5dc; /* Updated to beige */
            transform: scale(1.002);
            transition: all 0.2s ease;
        }
        
        /* Responsive column widths */
        #dataTable th:nth-child(1), #dataTable td:nth-child(1) { width: 60px; min-width: 60px; }
        #dataTable th:nth-child(2), #dataTable td:nth-child(2) { width: 80px; min-width: 80px; }
        #dataTable th:nth-child(3), #dataTable td:nth-child(3) { 
            width: 280px; 
            min-width: 200px; 
            text-align: left;
            white-space: normal;
            word-wrap: break-word;
            line-height: 1.4;
        }
        #dataTable th:nth-child(4), #dataTable td:nth-child(4) { width: 120px; min-width: 100px; }
        #dataTable th:nth-child(5), #dataTable td:nth-child(5) { width: 140px; min-width: 120px; }
        #dataTable th:nth-child(6), #dataTable td:nth-child(6) { width: 90px; min-width: 80px; }
        #dataTable th:nth-child(7), #dataTable td:nth-child(7) { width: 130px; min-width: 110px; }
        #dataTable th:nth-child(8), #dataTable td:nth-child(8) { width: 90px; min-width: 80px; }
        #dataTable th:nth-child(9), #dataTable td:nth-child(9) { width: 100px; min-width: 90px; }
        #dataTable th:nth-child(10), #dataTable td:nth-child(10) { width: 140px; min-width: 120px; }
        
        /* Mobile First Responsive Design */
        @media screen and (max-width: 1400px) {
            #dataTable { min-width: 1000px; }
            #dataTable th, #dataTable td { font-size: 12px; padding: 8px 6px; }
        }
        
        @media screen and (max-width: 1200px) {
            #dataTable { min-width: 900px; }
            #dataTable th, #dataTable td { font-size: 11px; padding: 6px 4px; }
            #dataTable th:nth-child(3), #dataTable td:nth-child(3) { width: 220px; min-width: 180px; }
        }
        
        @media screen and (max-width: 992px) {
            #dataTable { min-width: 800px; }
            .btn-action { padding: 2px 4px; font-size: 10px; }
            #dataTable th:nth-child(3), #dataTable td:nth-child(3) { width: 200px; min-width: 160px; }
        }
        
        @media screen and (max-width: 768px) {
            .table-responsive {
                border-radius: 0.25rem;
                margin: 0 -15px;
            }
            
            #dataTable { 
                min-width: 700px;
                font-size: 10px;
            }
            
            #dataTable th, #dataTable td { 
                padding: 6px 3px;
                font-size: 10px;
            }
            
            #dataTable th:nth-child(3), #dataTable td:nth-child(3) { 
                width: 180px; 
                min-width: 150px;
                font-size: 11px;
            }
            
            .btn-action { 
                padding: 1px 3px; 
                font-size: 9px;
                margin: 1px;
            }
            
            .product-image {
                width: 40px;
                height: 40px;
            }
        }
        
        @media screen and (max-width: 576px) {
            .table-responsive {
                margin: 0 -20px;
                border-radius: 0;
            }
            
            #dataTable { 
                min-width: 600px;
            }
            
            #dataTable th, #dataTable td { 
                padding: 4px 2px;
                font-size: 9px;
            }
            
            #dataTable th:nth-child(3), #dataTable td:nth-child(3) { 
                width: 160px; 
                min-width: 130px;
                font-size: 10px;
            }
            
            .product-image {
                width: 35px;
                height: 35px;
            }
            
            .btn-action {
                padding: 1px 2px;
                font-size: 8px;
            }
        }
        
        /* Clean and professional badges */
        .type-badge {
            background: #f8f9fc;
            color: #5a5c69;
            border: 1px solid #dddfeb;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
            white-space: nowrap;
        }
        
        .variant-count {
            background: #f8f9fc;
            color: #858796;
            border: 1px solid #e3e6f0;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 500;
            white-space: nowrap;
        }
        
        .price-range {
            font-weight: 600;
            color: #1cc88a;
            white-space: nowrap;
        }
        
        /* Clean action buttons */
        .btn-action {
            padding: 5px 8px;
            margin: 0 2px;
            border-radius: 3px;
            font-size: 12px;
            transition: opacity 0.2s ease;
            white-space: nowrap;
        }
        
        .btn-action:hover {
            opacity: 0.8;
        }
        
        /* Loading and empty states */
        .table-loading {
            text-align: center;
            padding: 40px;
            color: #6c757d;
        }
        
        /* Scroll indicators */
        .table-responsive::after {
            content: '← Cuộn để xem thêm →';
            position: absolute;
            bottom: 10px;
            right: 20px;
            font-size: 11px;
            color: #6c757d;
            background: rgba(255,255,255,0.9);
            padding: 2px 6px;
            border-radius: 4px;
            display: none;
        }
        
        @media screen and (max-width: 768px) {
            .table-responsive::after {
                display: block;
            }
        }
    </style>
</head>

<body id="page-top">

    <!-- Page Wrapper -->
    <div id="wrapper">

        <?php include 'includes/thanh_ben.php'; ?>

        <!-- Content Wrapper -->
        <div id="content-wrapper" class="d-flex flex-column">

            <!-- Main Content -->
            <div id="content">

                <?php include 'includes/thanh_tren.php'; ?>

                <!-- Begin Page Content -->
                <div class="container-fluid">

                    <!-- Page Heading -->
                    <div class="d-sm-flex align-items-center justify-content-between mb-4">
                        <h1 class="h3 mb-0 text-gray-800">
                            <i class="fas fa-warehouse text-primary mr-2"></i>
                            Sản phẩm Kho: <?php echo htmlspecialchars($warehouse['name'] ?? 'N/A'); ?>
                        </h1>
                    </div>

                    <!-- Warehouse Isolation Notice -->
                    <div class="alert alert-info border-left-info mb-4">
                        <div class="row align-items-center">
                            <div class="col-md-8">
                                <h6 class="font-weight-bold text-info mb-1">
                                    <i class="fas fa-shield-alt mr-2"></i>Hệ thống Warehouse độc lập
                                </h6>
                                <p class="mb-0 text-gray-700">
                                    Bạn đang xem sản phẩm của <strong><?php echo htmlspecialchars($warehouse['name']); ?></strong>. 
                                    Chỉ sản phẩm thuộc kho hàng này mới được hiển thị và bạn có quyền quản lý.
                                </p>
                            </div>
                            <div class="col-md-4 text-right">
                                <!-- Warehouse ID display removed -->
                            </div>
                        </div>
                    </div>

                    <!-- Alert Messages -->
                    <?php if (isset($_GET['success']) && $_GET['success'] == 'product_deactivated'): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="fas fa-check-circle mr-2"></i>
                            <strong>Thành công!</strong> Đã tạm ngừng sản phẩm "<?php echo htmlspecialchars($_GET['product_name'] ?? ''); ?>" thành công.
                            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                    <?php endif; ?>

                    <?php if (isset($_GET['error'])): ?>
                        <?php if ($_GET['error'] == 'product_not_found'): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="fas fa-exclamation-circle mr-2"></i>
                                <strong>Lỗi!</strong> Không tìm thấy sản phẩm.
                                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                    <span aria-hidden="true">&times;</span>
                                </button>
                            </div>
                        <?php elseif ($_GET['error'] == 'deactivate_failed'): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="fas fa-exclamation-circle mr-2"></i>
                                <strong>Lỗi!</strong> Không thể tạm ngừng sản phẩm. <?php echo htmlspecialchars($_GET['message'] ?? ''); ?>
                                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                    <span aria-hidden="true">&times;</span>
                                </button>
                            </div>
                        <?php elseif ($_GET['error'] == 'invalid_id'): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="fas fa-exclamation-circle mr-2"></i>
                                <strong>Lỗi!</strong> ID sản phẩm không hợp lệ.
                                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                    <span aria-hidden="true">&times;</span>
                                </button>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <!-- Statistics Cards -->
                    <div class="row mb-4">
                        <div class="col-xl-4 col-md-6 mb-4">
                            <div class="card border-left-primary shadow h-100 py-2 stats-card">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Tổng sản phẩm</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?= count($products) ?></div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-box fa-2x text-gray-300"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-xl-4 col-md-6 mb-4">
                            <div class="card border-left-success shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Tổng Variants</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?= array_sum(array_column($products, 'variant_count')) ?></div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-tags fa-2x text-gray-300"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-xl-4 col-md-6 mb-4">
                            <div class="card border-left-info shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Đã phân loại</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?= count(array_filter($products, function($p) { return $p['type']; })) ?></div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-layer-group fa-2x text-gray-300"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- DataTales Example -->
                    <div class="card shadow mb-4">
                        <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                            <div>
                                <h6 class="m-0 font-weight-bold text-primary d-inline-block">Danh sách sản phẩm</h6>
                                <?php if ($filter === 'low_stock'): ?>
                                    <span class="badge badge-warning ml-2">
                                        <i class="fas fa-exclamation-triangle"></i> Sắp hết hàng
                                    </span>
                                    <a href="danh_sach_san_pham.php" class="btn btn-sm btn-outline-secondary ml-2">
                                        <i class="fas fa-times"></i> Xóa bộ lọc
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if ($filter === 'low_stock'): ?>
                                <div class="alert alert-warning alert-dismissible fade show" role="alert">
                                    <i class="fas fa-filter mr-2"></i>
                                    <strong>Bộ lọc đang hoạt động:</strong> Hiển thị sản phẩm sắp hết hàng, sắp xếp theo tồn kho tăng dần (từ ít đến nhiều).
                                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                        <span aria-hidden="true">&times;</span>
                                    </button>
                                </div>
                            <?php endif; ?>
                            <?php if (empty($products)): ?>
                                <div class="text-center py-5">
                                    <i class="fas fa-box-open fa-3x text-gray-300 mb-3"></i>
                                    <h4 class="text-gray-600">Kho hàng chưa có sản phẩm nào</h4>
                                    <p class="text-gray-500 mb-2">
                                        Kho <strong><?php echo htmlspecialchars($warehouse['name']); ?></strong> của bạn chưa có sản phẩm nào.
                                    </p>
                                    <p class="text-gray-500">Hãy thêm sản phẩm đầu tiên cho kho hàng này!</p>
                                </div>
                            <?php else: ?>
                                <div class="table-container">
                                    <div class="table-responsive">
                                        <table class="table table-bordered" id="dataTable" width="100%" cellspacing="0">
                                            <thead>
                                                <tr>
                                                    <th>STT</th>
                                                    <th>Hình ảnh</th>
                                                    <th>Tên sản phẩm</th>
                                                    <th>Thương hiệu</th>
                                                    <th>Loại sản phẩm</th>
                                                    <th>Tồn kho</th>
                                                    <th>Giá bán</th>
                                                    <th>Variants</th>
                                                    <th>Ngày tạo</th>
                                                    <th>Hành động</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                            <?php foreach ($products as $index => $product): ?>
                                                <tr>
                                                    <td class="text-center"><?php echo $index + 1; ?></td>
                                                    <td>
                                                        <?php 
                                                        if ($product['image_path']) {
                                                            // Xử lý đường dẫn hình ảnh
                                                            $imagePath = $product['image_path'];
                                                            
                                                            // Nếu đường dẫn không bắt đầu bằng uploads/, thêm vào
                                                            if (strpos($imagePath, 'uploads/') !== 0 && strpos($imagePath, '/uploads/') !== 0) {
                                                                $imagePath = 'uploads/products/' . basename($imagePath);
                                                            }
                                                            
                                                            // Kiểm tra file có tồn tại không
                                                            $fullPath = __DIR__ . '/' . $imagePath;
                                                            if (file_exists($fullPath)) {
                                                                ?>
                                                                <img src="<?= htmlspecialchars($imagePath) ?>" 
                                                                     class="product-image" 
                                                                     alt="<?= htmlspecialchars($product['product_name']) ?>"
                                                                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                                                <div class="product-placeholder" style="display: none;">
                                                                    <i class="fas fa-image"></i>
                                                                </div>
                                                                <?php
                                                            } else {
                                                                ?>
                                                                <div class="product-placeholder">
                                                                    <i class="fas fa-image"></i>
                                                                </div>
                                                                <?php
                                                            }
                                                        } else {
                                                            ?>
                                                            <div class="product-placeholder">
                                                                <i class="fas fa-image"></i>
                                                            </div>
                                                            <?php
                                                        }
                                                        ?>
                                                    </td>
                                                    <td>
                                                        <div class="font-weight-bold"><?= htmlspecialchars($product['product_name']) ?></div>
                                                        <?php if ($product['description']): ?>
                                                            <small class="text-muted"><?= htmlspecialchars(substr($product['description'], 0, 80)) ?><?= strlen($product['description']) > 80 ? '...' : '' ?></small>
                                                        <?php endif; ?>
                                                        <br><small class="text-info">
                                                            <i class="fas fa-warehouse mr-1"></i><?= htmlspecialchars($product['warehouse_name']) ?>
                                                        </small>
                                                    </td>
                                                    <td>
                                                        <?php if ($product['brand']): ?>
                                                            <span class="badge badge-secondary"><?= htmlspecialchars($product['brand']) ?></span>
                                                        <?php else: ?>
                                                            <span class="text-muted">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($product['type']): ?>
                                                            <span class="type-badge"><?= htmlspecialchars($product['type']) ?></span>
                                                        <?php else: ?>
                                                            <span class="text-muted">Chưa phân loại</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php 
                                                        $stockClass = 'text-danger';
                                                        $stockText = 'Hết hàng';
                                                        if ($product['total_stock'] > 0) {
                                                            $stockClass = $product['total_stock'] > 50 ? 'text-success' : 'text-warning';
                                                            $stockText = number_format($product['total_stock']) . ' sp';
                                                        }
                                                        ?>
                                                        <span class="font-weight-bold <?= $stockClass ?>" data-sort="<?= $product['total_stock'] ?>">
                                                            <?= $stockText ?>
                                                        </span>
                                                        
                                                        <?php if ($filter === 'low_stock' && !empty($product['variants'])): ?>
                                                            <div class="mt-1">
                                                                <small class="text-muted d-block" style="line-height: 1.6;">
                                                                    <?php foreach ($product['variants'] as $variant): ?>
                                                                        <?php 
                                                                        $vStockClass = $variant['stock'] == 0 ? 'text-danger font-weight-bold' : ($variant['stock'] < 10 ? 'text-warning' : 'text-success');
                                                                        ?>
                                                                        <div class="<?= $vStockClass ?>" style="font-size: 11px;">
                                                                            <?= htmlspecialchars($variant['color']) ?>/<?= htmlspecialchars($variant['size']) ?>: 
                                                                            <strong><?= $variant['stock'] ?></strong>
                                                                        </div>
                                                                    <?php endforeach; ?>
                                                                </small>
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($product['min_price'] > 0): ?>
                                                            <div class="price-range" style="font-weight: 600; color: #1cc88a;">
                                                                <?php if ($product['min_price'] == $product['max_price']): ?>
                                                                    <?= number_format($product['min_price'], 0, ',', '.') ?>₫
                                                                <?php else: ?>
                                                                    <span style="color: #4e73df;"><?= number_format($product['min_price'], 0, ',', '.') ?>₫</span> 
                                                                    <span style="color: #858796;">-</span> 
                                                                    <span style="color: #e74a3b;"><?= number_format($product['max_price'], 0, ',', '.') ?>₫</span>
                                                                <?php endif; ?>
                                                            </div>
                                                        <?php else: ?>
                                                            <span class="text-muted">Chưa định giá</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($product['variant_count'] > 1): ?>
                                                            <span class="badge badge-pill badge-warning" style="font-size: 11px; padding: 5px 10px;">
                                                                <?= $product['variant_count'] ?> variants
                                                            </span>
                                                        <?php elseif ($product['variant_count'] == 1): ?>
                                                            <span class="badge badge-pill badge-secondary" style="font-size: 11px; padding: 5px 10px;">
                                                                1 variant
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="text-muted">Chưa có</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td data-sort="<?= strtotime($product['created_at']) ?>">
                                                        <span class="text-muted"><?= date('d/m/Y', strtotime($product['created_at'])) ?></span>
                                                    </td>
                                                    <td>
                                                        <a href="sua_san_pham.php?id=<?= $product['product_id'] ?>" class="btn btn-sm btn-primary btn-action" title="Chỉnh sửa">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        <a href="xem_san_pham.php?id=<?= $product['product_id'] ?>" class="btn btn-sm btn-info btn-action" title="Xem chi tiết">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                        <a href="javascript:void(0)" class="btn btn-sm btn-warning btn-action" title="Tạm ngừng" 
                                                           onclick="confirmDelete(<?= $product['product_id'] ?>, '<?= htmlspecialchars($product['product_name']) ?>')">
                                                            <i class="fas fa-pause"></i>
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>
                <!-- /.container-fluid -->

            </div>
            <!-- End of Main Content -->

            <!-- Footer -->
            <footer class="sticky-footer bg-white">
                <div class="container my-auto">
                    <div class="copyright text-center my-auto">
                        <span>Copyright &copy; Smart Warehouse System 2024</span>
                    </div>
                </div>
            </footer>
            <!-- End of Footer -->

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

    <!-- Page level plugins -->
    <script src="vendor/datatables/jquery.dataTables.min.js"></script>
    <script src="vendor/datatables/dataTables.bootstrap4.min.js"></script>

    <!-- Page level custom scripts -->
    <script>
        $(document).ready(function() {
            // Custom sorting plugin to read data-sort attribute
            $.fn.dataTable.ext.order['dom-data-sort'] = function(settings, col) {
                return this.api().column(col, {order: 'index'}).nodes().map(function(td, i) {
                    var $span = $(td).find('span[data-sort]');
                    if ($span.length > 0) {
                        return parseInt($span.attr('data-sort')) || 0;
                    }
                    return 0;
                });
            };
            
            // Enhanced responsive DataTable
            var table = $('#dataTable').DataTable({
                "language": {
                    "search": "Tìm kiếm:",
                    "lengthMenu": "Hiển thị _MENU_ sản phẩm mỗi trang",
                    "info": "Hiển thị _START_ đến _END_ trong tổng số _TOTAL_ sản phẩm",
                    "infoEmpty": "Không có sản phẩm nào",
                    "infoFiltered": "(được lọc từ _MAX_ sản phẩm)",
                    "paginate": {
                        "first": "Đầu",
                        "last": "Cuối", 
                        "next": "Tiếp",
                        "previous": "Trước"
                    },
                    "emptyTable": "Không có dữ liệu trong bảng",
                    "zeroRecords": "Không tìm thấy kết quả phù hợp"
                },
                <?php if ($filter === 'low_stock'): ?>
                "order": [[ 5, "asc" ]], // Sort by stock ascending when filtering low stock
                <?php else: ?>
                "order": [[ 8, "desc" ]], // Sort by date created
                <?php endif; ?>
                "pageLength": 10,
                "lengthMenu": [[10, 25, 50, -1], [10, 25, 50, "Tất cả"]],
                "columnDefs": [
                    { 
                        orderable: false, 
                        targets: [0, 9], // Disable sorting for STT and Actions columns
                        className: 'text-center' // Center align
                    },
                    { 
                        targets: [5], // Stock column - sort by data-sort attribute
                        type: 'num',
                        orderDataType: 'dom-data-sort'
                    },
                    { 
                        targets: [8], // Date column - use data-sort attribute
                        type: 'num' // Sort by number (timestamp)
                    },
                    {
                        targets: 0, // STT column
                        render: function (data, type, row, meta) {
                            if (type === 'display') {
                                return meta.row + meta.settings._iDisplayStart + 1;
                            }
                            return data;
                        }
                    }
                ],
                "initComplete": function() {
                    // Adjust column widths after table is initialized
                    var api = this.api();
                    api.columns.adjust().draw();
                    
                    // Fix column alignment on window resize/zoom
                    $(window).on('resize', function() {
                        api.columns.adjust();
                    });
                }
            });
            
            // Responsive enhancements
            function handleResponsive() {
                var windowWidth = $(window).width();
                var tableContainer = $('.table-responsive');
                
                // Add scroll indicators for mobile
                if (windowWidth <= 768) {
                    if (!tableContainer.hasClass('mobile-scroll-enabled')) {
                        tableContainer.addClass('mobile-scroll-enabled');
                        
                        // Add touch scroll hint
                        if (!$('.scroll-hint').length) {
                            tableContainer.append('<div class="scroll-hint">← Vuốt để xem thêm →</div>');
                        }
                    }
                } else {
                    tableContainer.removeClass('mobile-scroll-enabled');
                    $('.scroll-hint').remove();
                }
                
                // Adjust DataTable responsive behavior
                if (windowWidth <= 576) {
                    table.page.len(5).draw(); // Show fewer items on mobile
                } else if (windowWidth <= 768) {
                    table.page.len(10).draw();
                } else {
                    table.page.len(10).draw();
                }
            }
            
            // Initial responsive check
            handleResponsive();
            
            // Handle window resize
            $(window).on('resize', function() {
                clearTimeout(window.resizeTimer);
                window.resizeTimer = setTimeout(handleResponsive, 100);
            });
            
            // Smooth scroll for table on mobile
            $('.table-responsive').on('scroll', function() {
                var scrollLeft = $(this).scrollLeft();
                var scrollWidth = $(this)[0].scrollWidth;
                var clientWidth = $(this)[0].clientWidth;
                
                // Hide scroll hint when user starts scrolling
                if (scrollLeft > 10) {
                    $('.scroll-hint').fadeOut();
                }
            });
            
            // Add custom styles for mobile
            if ($(window).width() <= 768) {
                $('body').addClass('mobile-view');
                
                // Add swipe gesture hint CSS
                $('<style>')
                    .prop('type', 'text/css')
                    .html(`
                        .scroll-hint {
                            position: absolute;
                            bottom: 10px;
                            right: 20px;
                            background: rgba(0,0,0,0.7);
                            color: white;
                            padding: 5px 10px;
                            border-radius: 15px;
                            font-size: 11px;
                            z-index: 100;
                            animation: pulse 2s infinite;
                        }
                        
                        @keyframes pulse {
                            0% { opacity: 0.6; }
                            50% { opacity: 1; }
                            100% { opacity: 0.6; }
                        }
                        
                        .mobile-view .table-responsive {
                            position: relative;
                        }
                        
                        .mobile-view .dataTables_length {
                            margin-bottom: 1rem;
                        }
                        
                        .mobile-view .dataTables_filter {
                            margin-bottom: 1rem;
                        }
                        
                        .mobile-view .dataTables_info {
                            font-size: 12px;
                        }
                    `)
                    .appendTo('head');
            }
        });

        function confirmDelete(productId, productName) {
            Swal.fire({
                title: 'Xác nhận tạm ngừng sản phẩm',
                html: `
                    <div class="text-center">
                        <i class="fas fa-pause-circle text-warning" style="font-size: 3rem; margin-bottom: 1rem;"></i>
                        <p class="mb-3">Bạn có chắc chắn muốn tạm ngừng sản phẩm:</p>
                        <p class="font-weight-bold text-warning">"${productName}"</p>
                        <small class="text-muted">Sản phẩm sẽ bị ẩn khỏi danh sách nhưng vẫn được lưu trong hệ thống. Bạn có thể kích hoạt lại sau.</small>
                    </div>
                `,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ffc107',
                cancelButtonColor: '#6c757d',
                confirmButtonText: '<i class="fas fa-pause"></i> Tạm ngừng',
                cancelButtonText: '<i class="fas fa-times"></i> Hủy',
                reverseButtons: true,
                customClass: {
                    confirmButton: 'btn btn-warning mx-2',
                    cancelButton: 'btn btn-secondary mx-2'
                },
                buttonsStyling: false
            }).then((result) => {
                if (result.isConfirmed) {
                    // Show loading
                    Swal.fire({
                        title: 'Đang tạm ngừng...',
                        text: 'Vui lòng chờ',
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        showConfirmButton: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                    
                    // Redirect to deactivate
                    window.location.href = `xoa_san_pham.php?id=${productId}`;
                }
            });
        }
    </script>

    <?php include 'includes/modal_dang_xuat.php'; ?>
</body>
</html>