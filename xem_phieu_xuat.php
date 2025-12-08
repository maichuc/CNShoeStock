<?php
session_start();
require_once 'config/database.php';
require_once 'classes/Kho.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    header('Location: login.html');
    exit;
}

$database = new Database();
$pdo = $database->getConnection();

$userId = $_SESSION['user_id'];
$warehouseId = $_SESSION['warehouse_id'];
$exportId = $_GET['export_id'] ?? null;

if (!$exportId) {
    die('Export ID is required');
}

// Lấy thông tin warehouse
$warehouseName = 'Smart Warehouse';
if ($warehouseId) {
    $warehouseObj = new Warehouse($pdo);
    if ($warehouseObj->getById($warehouseId)) {
        $warehouseName = $warehouseObj->name;
    }
}

// Lấy thông tin chi tiết phiếu xuất
$exportSql = "
    SELECT 
        we.export_id,
        we.export_code,
        we.order_id,
        we.status,
        we.created_at,
        we.updated_at,
        o.customer_id,
        o.discount,
        o.total_price as order_total,
        COALESCE(c.full_name, 'Khách lẻ') as customer_name,
        COALESCE(c.phone, '') as customer_phone,
        COALESCE(c.address, '') as customer_address,
        w.name as warehouse_name,
        pu.username as processed_by_name,
        cu.username as completed_by_name,
        we.completed_at
    FROM warehouse_exports we
    JOIN orders o ON we.order_id = o.order_id
    LEFT JOIN customers c ON o.customer_id = c.customer_id
    JOIN warehouses w ON we.warehouse_id = w.warehouse_id
    LEFT JOIN users pu ON we.processed_by = pu.user_id
    LEFT JOIN users cu ON we.completed_by = cu.user_id
    WHERE we.export_id = :export_id AND we.warehouse_id = :warehouse_id
";

$exportStmt = $pdo->prepare($exportSql);
$exportStmt->bindParam(':export_id', $exportId, PDO::PARAM_INT);
$exportStmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
$exportStmt->execute();
$export = $exportStmt->fetch(PDO::FETCH_ASSOC);

if (!$export) {
    die('Export not found or access denied');
}

// Lấy chi tiết sản phẩm
$detailsSql = "
    SELECT 
        wed.detail_id,
        wed.quantity as required_quantity,
        COALESCE(wed.picked_quantity, 0) as picked_quantity,
        wed.unit_price,
        wed.total_price,
        wed.picked_at,
        p.product_id,
        p.name as product_name,
        p.brand,
        v.sku,
        v.size,
        v.color,
        v.price,
        CONCAT('QR_', v.sku) as qr_code,
        pu.username as picked_by_name,
        -- Lấy thông tin vị trí từ inventory
        GROUP_CONCAT(
            CONCAT(l.shelf_code, ' (', ib.quantity, ')')
            ORDER BY ib.quantity DESC
            SEPARATOR ', '
        ) as location_info
    FROM warehouse_export_details wed
    JOIN product_variants v ON wed.variant_id = v.variant_id
    JOIN products p ON v.product_id = p.product_id
    LEFT JOIN users pu ON wed.picked_by = pu.user_id
    LEFT JOIN inventory ib ON v.variant_id = ib.variant_id AND ib.warehouse_id = :warehouse_id
    LEFT JOIN locations l ON ib.location_id = l.location_id
    WHERE wed.export_id = :export_id
    GROUP BY wed.detail_id, wed.quantity, wed.picked_quantity, wed.unit_price, wed.total_price, 
             wed.picked_at, p.product_id, p.name, p.brand, v.sku, v.size, v.color, 
             v.price, pu.username
    ORDER BY p.name
";

$detailsStmt = $pdo->prepare($detailsSql);
$detailsStmt->bindParam(':export_id', $exportId, PDO::PARAM_INT);
$detailsStmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
$detailsStmt->execute();
$details = $detailsStmt->fetchAll(PDO::FETCH_ASSOC);

// Tính toán thống kê
$totalItems = count($details);
$completedItems = 0;
$totalValue = 0;

foreach ($details as $detail) {
    if ($detail['picked_quantity'] >= $detail['required_quantity']) {
        $completedItems++;
    }
    $totalValue += $detail['total_price'];
}

// Tính thành tiền sau giảm giá
$discountAmount = 0;
$finalTotal = $totalValue;
if ($export['discount'] > 0) {
    $discountAmount = $totalValue * ($export['discount'] / 100);
    $finalTotal = $totalValue - $discountAmount;
}

$progressPercentage = $totalItems > 0 ? round(($completedItems / $totalItems) * 100) : 0;

// Trạng thái
$statusLabels = [
    'pending' => ['label' => 'Chờ lấy hàng', 'class' => 'warning', 'icon' => 'clock'],
    'processing' => ['label' => 'Đang xử lý', 'class' => 'info', 'icon' => 'tasks'],
    'completed' => ['label' => 'Đã đóng gói', 'class' => 'success', 'icon' => 'check-circle'],
    'cancelled' => ['label' => 'Đã hủy', 'class' => 'danger', 'icon' => 'times-circle']
];

$currentStatus = $statusLabels[$export['status']] ?? ['label' => $export['status'], 'class' => 'secondary', 'icon' => 'question'];
?>

<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    
    <title>Chi tiết phiếu xuất - <?php echo htmlspecialchars($export['export_code']); ?></title>

    <!-- Custom fonts for this template -->
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">

    <!-- Custom styles for this template -->
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    
    <style>
        .export-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        
        .info-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            margin-bottom: 20px;
            border-left: 4px solid #4e73df;
        }
        
        .status-badge {
            font-size: 14px;
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .progress-card {
            background: linear-gradient(135deg, #36d1dc 0%, #5b86e5 100%);
            color: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
        }
        
        .progress {
            height: 12px;
            background-color: rgba(255,255,255,0.3);
            border-radius: 6px;
            margin: 15px 0;
        }
        
        .progress-bar {
            background-color: white;
            border-radius: 6px;
        }
        
        .detail-item {
            background: white;
            border: 2px solid #e3e6f0;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 15px;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .detail-item.completed {
            border-color: #1cc88a;
            background: linear-gradient(135deg, #f8fff9 0%, #f0fff4 100%);
        }
        
        .detail-item.completed::after {
            content: '✓';
            position: absolute;
            top: 15px;
            right: 20px;
            background: #1cc88a;
            color: white;
            width: 35px;
            height: 35px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 18px;
            box-shadow: 0 3px 10px rgba(28, 200, 138, 0.3);
        }
        
        .detail-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }
        
        .product-image {
            width: 80px;
            height: 80px;
            object-fit: cover;
            border-radius: 10px;
            border: 2px solid #e3e6f0;
        }
        
        .quantity-badge {
            background: #e9ecef;
            color: #495057;
            padding: 5px 12px;
            border-radius: 15px;
            font-weight: 600;
            font-size: 12px;
        }
        
        .quantity-badge.completed {
            background: #d4edda;
            color: #155724;
        }
        
        .location-badge {
            background: #dc3545;
            color: white;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .action-buttons .btn {
            margin-right: 10px;
            margin-bottom: 10px;
            border-radius: 25px;
            font-weight: 600;
            padding: 10px 20px;
        }
        
        .print-section {
            background: #f8f9fc;
            padding: 20px;
            border-radius: 15px;
            border-left: 4px solid #36b9cc;
            margin-bottom: 20px;
        }
        
        @media print {
            .no-print { display: none !important; }
            .export-header { background: #4e73df !important; }
            .progress-card { background: #36d1dc !important; }
        }
        
        .qr-code-display {
            font-family: 'Courier New', monospace;
            background: #f8f9fa;
            padding: 5px 8px;
            border-radius: 4px;
            border: 1px solid #dee2e6;
            font-size: 12px;
        }
        
        .timeline {
            padding-left: 30px;
            border-left: 3px solid #e3e6f0;
            margin-left: 15px;
        }
        
        .timeline-item {
            position: relative;
            padding-bottom: 20px;
        }
        
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -36px;
            top: 5px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #4e73df;
        }
        
        .timeline-item.completed::before {
            background: #1cc88a;
        }
    </style>
</head>

<body id="page-top">

    <!-- Page Wrapper -->
    <div id="wrapper">

        <!-- Sidebar (simplified) -->
        <ul class="navbar-nav bg-gradient-primary sidebar sidebar-dark accordion" id="accordionSidebar">
            <a class="sidebar-brand d-flex align-items-center justify-content-center" href="trang_chu.php">
                <div class="sidebar-brand-icon rotate-n-15">
                    <i class="fas fa-warehouse"></i>
                </div>
                <div class="sidebar-brand-text mx-3"><?php echo htmlspecialchars($warehouseName); ?></div>
            </a>
            <hr class="sidebar-divider my-0">
            <li class="nav-item">
                <a class="nav-link" href="quan_ly_xuat_kho.php">
                    <i class="fas fa-arrow-left"></i>
                    <span>Quay lại danh sách</span>
                </a>
            </li>
            <?php if (in_array($export['status'], ['pending', 'processing'])): ?>
            <li class="nav-item">
                <a class="nav-link" href="xu_ly_xuat_kho.php?export_id=<?php echo $exportId; ?>">
                    <i class="fas fa-tasks"></i>
                    <span>Xử lý phiếu</span>
                </a>
            </li>
            <?php endif; ?>
        </ul>

        <!-- Content Wrapper -->
        <div id="content-wrapper" class="d-flex flex-column">

            <!-- Main Content -->
            <div id="content">

                <!-- Topbar -->
                <nav class="navbar navbar-expand navbar-light bg-white topbar mb-4 static-top shadow">
                    <button id="sidebarToggleTop" class="btn btn-link d-md-none rounded-circle mr-3">
                        <i class="fa fa-bars"></i>
                    </button>
                    <ul class="navbar-nav ml-auto">
                        <li class="nav-item dropdown no-arrow">
                            <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button"
                                data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <span class="mr-2 d-none d-lg-inline text-gray-600 small"><?php echo htmlspecialchars($_SESSION['username'] ?? 'User'); ?></span>
                                <img class="img-profile rounded-circle" src="img/undraw_profile.svg">
                            </a>
                        </li>
                    </ul>
                </nav>

                <!-- Begin Page Content -->
                <div class="container-fluid">

                    <!-- Export Header -->
                    <div class="export-header">
                        <div class="row align-items-center">
                            <div class="col-md-8">
                                <h1 class="mb-2">
                                    <i class="fas fa-file-export mr-3"></i>
                                    <?php echo htmlspecialchars($export['export_code']); ?>
                                </h1>
                                <p class="h5 mb-1">
                                    <i class="fas fa-user mr-2"></i>
                                    Khách hàng: <?php echo htmlspecialchars($export['customer_name']); ?>
                                </p>
                                <?php if ($export['customer_phone']): ?>
                                <p class="mb-1">
                                    <i class="fas fa-phone mr-2"></i>
                                    SĐT: <?php echo htmlspecialchars($export['customer_phone']); ?>
                                </p>
                                <?php endif; ?>
                                <?php if ($export['customer_address']): ?>
                                <p class="mb-0">
                                    <i class="fas fa-map-marker-alt mr-2"></i>
                                    Địa chỉ: <?php echo htmlspecialchars($export['customer_address']); ?>
                                </p>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-4 text-right">
                                <span class="status-badge bg-<?php echo $currentStatus['class']; ?>">
                                    <i class="fas fa-<?php echo $currentStatus['icon']; ?>"></i>
                                    <?php echo $currentStatus['label']; ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="print-section no-print">
                        <div class="row">
                            <div class="col-md-8">
                                <h5 class="mb-3">
                                    <i class="fas fa-tools mr-2 text-info"></i>
                                    Thao tác nhanh
                                </h5>
                            </div>
                            <div class="col-md-4 text-right">
                                <div class="action-buttons">
                                    <?php if (in_array($export['status'], ['pending', 'processing'])): ?>
                                    <a href="xu_ly_xuat_kho.php?export_id=<?php echo $exportId; ?>" class="btn btn-primary">
                                        <i class="fas fa-tasks mr-1"></i> Xử lý phiếu
                                    </a>
                                    <?php endif; ?>
                                    
                                    <?php if ($export['status'] === 'cancelled'): ?>
                                    <button class="btn btn-warning" onclick="reprocessExport(<?php echo $exportId; ?>)">
                                        <i class="fas fa-redo mr-1"></i> Xử lý lại đơn hàng
                                    </button>
                                    <?php endif; ?>
                                    
                                    <a href="phieu_xuat_kho.php?order_id=<?php echo $export['order_id']; ?>" class="btn btn-success" target="_blank">
                                        <i class="fas fa-print mr-1"></i> In phiếu
                                    </a>
                                    
                                    <button class="btn btn-info" onclick="window.print()">
                                        <i class="fas fa-file-pdf mr-1"></i> In PDF
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Progress Card -->
                    <div class="progress-card">
                        <div class="row align-items-center">
                            <div class="col-md-8">
                                <h4 class="mb-2">
                                    <i class="fas fa-chart-pie mr-2"></i>
                                    Tiến độ xử lý phiếu xuất
                                </h4>
                                <div class="progress">
                                    <div class="progress-bar" role="progressbar" 
                                         style="width: <?php echo $progressPercentage; ?>%" 
                                         aria-valuenow="<?php echo $progressPercentage; ?>" 
                                         aria-valuemin="0" aria-valuemax="100">
                                    </div>
                                </div>
                                <p class="mb-0">
                                    Đã hoàn thành <?php echo $completedItems; ?>/<?php echo $totalItems; ?> sản phẩm 
                                    (<?php echo $progressPercentage; ?>%)
                                </p>
                            </div>
                            <div class="col-md-4 text-right">
                                <div class="h2 mb-0"><?php echo $progressPercentage; ?>%</div>
                                <small>Hoàn thành</small>
                            </div>
                        </div>
                    </div>

                    <!-- Export Information -->
                    <div class="row">
                        <div class="col-lg-8">
                            <!-- Product Details -->
                            <div class="card shadow mb-4">
                                <div class="card-header py-3 bg-primary text-white">
                                    <h6 class="m-0 font-weight-bold">
                                        <i class="fas fa-boxes mr-2"></i>
                                        Chi tiết sản phẩm cần xuất (<?php echo $totalItems; ?> sản phẩm)
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <?php foreach ($details as $detail): ?>
                                    <?php $isCompleted = $detail['picked_quantity'] >= $detail['required_quantity']; ?>
                                    <div class="detail-item <?php echo $isCompleted ? 'completed' : ''; ?>">
                                        <div class="row align-items-center">
                                            <div class="col-md-7">
                                                <h6 class="font-weight-bold text-primary mb-2">
                                                    <?php echo htmlspecialchars($detail['product_name']); ?>
                                                </h6>
                                                <div class="row">
                                                    <div class="col-6">
                                                        <small class="text-muted">SKU:</small>
                                                        <div class="qr-code-display"><?php echo htmlspecialchars($detail['sku']); ?></div>
                                                    </div>
                                                    <div class="col-6">
                                                        <small class="text-muted">QR Code:</small>
                                                        <div class="qr-code-display"><?php echo htmlspecialchars($detail['qr_code']); ?></div>
                                                    </div>
                                                </div>
                                                <div class="mt-2">
                                                    <strong>Màu:</strong> <?php echo htmlspecialchars($detail['color']); ?> |
                                                    <strong>Size:</strong> <?php echo htmlspecialchars($detail['size']); ?>
                                                    <?php if ($detail['brand']): ?>
                                                    | <strong>Thương hiệu:</strong> <?php echo htmlspecialchars($detail['brand']); ?>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if ($detail['location_info']): ?>
                                                <div class="mt-2">
                                                    <strong>Vị trí:</strong> 
                                                    <span class="location-badge"><?php echo htmlspecialchars($detail['location_info']); ?></span>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <div class="col-md-3 text-center">
                                                <div class="mb-2">
                                                    <small class="text-muted">Cần lấy</small>
                                                    <div class="h5 text-primary"><?php echo $detail['required_quantity']; ?></div>
                                                </div>
                                                <div class="mb-2">
                                                    <small class="text-muted">Đã lấy</small>
                                                    <div class="h5 <?php echo $isCompleted ? 'text-success' : 'text-warning'; ?>">
                                                        <?php echo $detail['picked_quantity']; ?>
                                                    </div>
                                                </div>
                                                <span class="quantity-badge <?php echo $isCompleted ? 'completed' : ''; ?>">
                                                    <?php echo $isCompleted ? 'Hoàn thành' : 'Chưa lấy'; ?>
                                                </span>
                                            </div>
                                            
                                            <div class="col-md-2 text-right">
                                                <div class="mb-2">
                                                    <strong><?php echo number_format($detail['unit_price'], 0, ',', '.'); ?>đ</strong>
                                                    <small class="text-muted d-block">Đơn giá</small>
                                                </div>
                                                <div>
                                                    <strong class="text-primary"><?php echo number_format($detail['total_price'], 0, ',', '.'); ?>đ</strong>
                                                    <small class="text-muted d-block">Thành tiền</small>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <?php if ($detail['picked_at']): ?>
                                        <div class="row mt-3">
                                            <div class="col-12">
                                                <div class="alert alert-success py-2 mb-0">
                                                    <i class="fas fa-check-circle mr-2"></i>
                                                    <strong>Đã lấy:</strong> <?php echo date('d/m/Y H:i', strtotime($detail['picked_at'])); ?>
                                                    <?php if ($detail['picked_by_name']): ?>
                                                    - Người lấy: <?php echo htmlspecialchars($detail['picked_by_name']); ?>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-4">
                            <!-- Summary Information -->
                            <div class="info-card">
                                <h5 class="mb-3">
                                    <i class="fas fa-info-circle mr-2 text-primary"></i>
                                    Thông tin phiếu xuất
                                </h5>
                                <table class="table table-borderless">
                                    <tr>
                                        <td><strong>Mã phiếu:</strong></td>
                                        <td><?php echo htmlspecialchars($export['export_code']); ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Mã đơn hàng:</strong></td>
                                        <td>#<?php echo str_pad($export['order_id'], 6, '0', STR_PAD_LEFT); ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Trạng thái:</strong></td>
                                        <td>
                                            <span class="badge badge-<?php echo $currentStatus['class']; ?>">
                                                <i class="fas fa-<?php echo $currentStatus['icon']; ?> mr-1"></i>
                                                <?php echo $currentStatus['label']; ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td><strong>Ngày tạo:</strong></td>
                                        <td><?php echo date('d/m/Y H:i', strtotime($export['created_at'])); ?></td>
                                    </tr>
                                    <?php if ($export['completed_at']): ?>
                                    <tr>
                                        <td><strong>Hoàn thành:</strong></td>
                                        <td><?php echo date('d/m/Y H:i', strtotime($export['completed_at'])); ?></td>
                                    </tr>
                                    <?php endif; ?>
                                    <tr>
                                        <td><strong>Tổng số sản phẩm:</strong></td>
                                        <td><?php echo $totalItems; ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Đã hoàn thành:</strong></td>
                                        <td class="text-success"><?php echo $completedItems; ?></td>
                                    </tr>
                                    <tr class="border-top">
                                        <td><strong>Tổng tiền hàng:</strong></td>
                                        <td><strong class="text-primary"><?php echo number_format($totalValue, 0, ',', '.'); ?>đ</strong></td>
                                    </tr>
                                    <?php if ($export['discount'] > 0): ?>
                                    <tr>
                                        <td><strong>Giảm giá:</strong></td>
                                        <td class="text-danger">-<?php echo number_format($discountAmount, 0, ',', '.'); ?>đ (<?php echo $export['discount']; ?>%)</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Thành tiền:</strong></td>
                                        <td><strong class="text-success"><?php echo number_format($finalTotal, 0, ',', '.'); ?>đ</strong></td>
                                    </tr>
                                    <?php endif; ?>
                                </table>
                            </div>

                            <!-- Timeline -->
                            <div class="info-card">
                                <h5 class="mb-3">
                                    <i class="fas fa-history mr-2 text-primary"></i>
                                    Lịch sử xử lý
                                </h5>
                                <div class="timeline">
                                    <div class="timeline-item completed">
                                        <strong>Tạo phiếu xuất</strong>
                                        <div class="text-muted">
                                            <?php echo date('d/m/Y H:i', strtotime($export['created_at'])); ?>
                                        </div>
                                    </div>
                                    
                                    <?php if (in_array($export['status'], ['processing', 'completed'])): ?>
                                    <div class="timeline-item completed">
                                        <strong>Bắt đầu xử lý</strong>
                                        <div class="text-muted">
                                            <?php echo date('d/m/Y H:i', strtotime($export['updated_at'])); ?>
                                            <?php if ($export['processed_by_name']): ?>
                                            <br>Bởi: <?php echo htmlspecialchars($export['processed_by_name']); ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($export['status'] == 'completed' && $export['completed_at']): ?>
                                    <div class="timeline-item completed">
                                        <strong>Hoàn thành đóng gói</strong>
                                        <div class="text-muted">
                                            <?php echo date('d/m/Y H:i', strtotime($export['completed_at'])); ?>
                                            <?php if ($export['completed_by_name']): ?>
                                            <br>Bởi: <?php echo htmlspecialchars($export['completed_by_name']); ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
                <!-- /.container-fluid -->

            </div>
            <!-- End of Main Content -->

            <!-- Footer -->
            <footer class="sticky-footer bg-white no-print">
                <div class="container my-auto">
                    <div class="copyright text-center my-auto">
                        <span>Copyright &copy; Smart Warehouse System 2025</span>
                    </div>
                </div>
            </footer>

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

    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
    function reprocessExport(exportId) {
        Swal.fire({
            title: 'Xác nhận xử lý lại đơn hàng',
            text: 'Đơn hàng sẽ được chuyển sang trạng thái "Đang xử lý" và bạn có thể tiếp tục xử lý như bình thường. Bạn có chắc chắn muốn tiếp tục?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Đồng ý, xử lý lại',
            cancelButtonText: 'Hủy bỏ'
        }).then((result) => {
            if (result.isConfirmed) {
                // Hiển thị loading
                Swal.fire({
                    title: 'Đang xử lý...',
                    allowOutsideClick: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });

                // Gửi request
                fetch('api_xu_ly_lai_xuat.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        export_id: exportId
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Thành công!',
                            text: data.message,
                            confirmButtonColor: '#3085d6',
                            confirmButtonText: 'Đồng ý'
                        }).then(() => {
                            // Reload trang để cập nhật trạng thái
                            window.location.reload();
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Lỗi!',
                            text: data.message,
                            confirmButtonColor: '#d33',
                            confirmButtonText: 'Đóng'
                        });
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Lỗi!',
                        text: 'Có lỗi xảy ra khi xử lý yêu cầu. Vui lòng thử lại sau.',
                        confirmButtonColor: '#d33',
                        confirmButtonText: 'Đóng'
                    });
                });
            }
        });
    }
    </script>

</body>
</html>

