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

// Lấy thông tin phiếu xuất
$exportSql = "
    SELECT 
        we.export_id,
        we.export_code,
        we.order_id,
        we.status,
        we.created_at,
        we.updated_at,
        o.customer_id,
        COALESCE(c.full_name, 'Khách lẻ') as customer_name,
        COALESCE(c.phone, '') as customer_phone,
        COALESCE(c.address, '') as customer_address,
        w.name as warehouse_name
    FROM warehouse_exports we
    JOIN orders o ON we.order_id = o.order_id
    LEFT JOIN customers c ON o.customer_id = c.customer_id
    JOIN warehouses w ON we.warehouse_id = w.warehouse_id
    WHERE we.export_id = :export_id AND we.warehouse_id = :warehouse_id
";

$exportStmt = $pdo->prepare($exportSql);
$exportStmt->bindParam(':export_id', $exportId, PDO::PARAM_INT);
$exportStmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
$exportStmt->execute();
$exportInfo = $exportStmt->fetch(PDO::FETCH_ASSOC);

if (!$exportInfo) {
    die('Export not found or access denied');
}

// Kiểm tra trạng thái phiếu xuất (phải là "pending" hoặc "processing")
if (!in_array($exportInfo['status'], ['pending', 'processing'])) {
    die('Phiếu xuất kho này không thể xử lý. Trạng thái hiện tại: ' . $exportInfo['status']);
}

// Lấy danh sách sản phẩm cần lấy
$itemsSql = "
    SELECT 
        wed.detail_id,
        wed.export_id,
        wed.variant_id,
        wed.quantity as required_quantity,
        COALESCE(wed.picked_quantity, 0) as picked_quantity,
        wed.unit_price,
        wed.total_price,
        p.product_id,
        p.name as product_name,
        p.brand,
        v.sku,
        v.size,
        v.color,
        v.price,
        CONCAT('QR_', v.sku) as qr_code,
        -- Lấy thông tin vị trí từ inventory
        GROUP_CONCAT(
            CONCAT(l.shelf_code, ' (', ib.quantity, ')')
            ORDER BY ib.quantity DESC
            SEPARATOR ', '
        ) as location_info,
        SUM(ib.quantity) as available_quantity
    FROM warehouse_export_details wed
    JOIN product_variants v ON wed.variant_id = v.variant_id
    JOIN products p ON v.product_id = p.product_id
    LEFT JOIN inventory ib ON v.variant_id = ib.variant_id AND ib.warehouse_id = :warehouse_id
    LEFT JOIN locations l ON ib.location_id = l.location_id
    WHERE wed.export_id = :export_id
    GROUP BY wed.detail_id, wed.export_id, wed.variant_id, wed.quantity, wed.picked_quantity, 
             wed.unit_price, wed.total_price, p.product_id, p.name, p.brand,
             v.sku, v.size, v.color, v.price
    ORDER BY 
        CASE WHEN COALESCE(wed.picked_quantity, 0) >= wed.quantity THEN 1 ELSE 0 END,
        p.name
";

$itemsStmt = $pdo->prepare($itemsSql);
$itemsStmt->bindParam(':export_id', $exportId, PDO::PARAM_INT);
$itemsStmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
$itemsStmt->execute();
$items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

// Cập nhật trạng thái thành "processing" nếu đang là "pending"
if ($exportInfo['status'] == 'pending') {
    $updateStatusSql = "UPDATE warehouse_exports SET status = 'processing', processed_by = :user_id WHERE export_id = :export_id";
    $updateStatusStmt = $pdo->prepare($updateStatusSql);
    $updateStatusStmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
    $updateStatusStmt->bindParam(':export_id', $exportId, PDO::PARAM_INT);
    $updateStatusStmt->execute();
    $exportInfo['status'] = 'processing';
}

// Tính toán tiến độ
$totalItems = count($items);
$completedItems = 0;
foreach ($items as $item) {
    if ($item['picked_quantity'] >= $item['required_quantity']) {
        $completedItems++;
    }
}
$progressPercentage = $totalItems > 0 ? round(($completedItems / $totalItems) * 100) : 0;
?>

<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    
    <title>Xử lý phiếu xuất - <?php echo htmlspecialchars($exportInfo['export_code']); ?></title>

    <!-- Custom fonts for this template -->
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">

    <!-- Custom styles for this template -->
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style>
        .export-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        
        .progress-section {
            background: #f8f9fc;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            border-left: 4px solid #4e73df;
        }
        
        .item-card {
            border: 2px solid #e3e6f0;
            border-radius: 10px;
            margin-bottom: 15px;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .item-card.completed {
            border-color: #1cc88a;
            background-color: #f8fff9;
        }
        
        .item-card.completed::after {
            content: '✓';
            position: absolute;
            top: 10px;
            right: 15px;
            background: #1cc88a;
            color: white;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 16px;
        }
        
        .item-card:hover {
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        
        .qr-scan-btn {
            background: linear-gradient(45deg, #36D1DC, #5B86E5);
            border: none;
            color: white;
            padding: 10px 20px;
            border-radius: 25px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .qr-scan-btn:hover {
            transform: scale(1.05);
            color: white;
        }
        
        .location-badge {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            display: inline-block;
            margin: 2px;
            box-shadow: 0 2px 4px rgba(102, 126, 234, 0.3);
            transition: all 0.3s ease;
        }
        
        .location-badge:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(102, 126, 234, 0.5);
        }
        
        .location-section {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            padding: 12px 15px;
            border-radius: 8px;
            margin: 10px 0;
            border-left: 4px solid #667eea;
        }
        
        .location-section i {
            color: #667eea;
            font-size: 16px;
        }
        
        .quantity-info {
            background: #f1f3f4;
            padding: 10px;
            border-radius: 8px;
            margin: 10px 0;
        }
        
        .complete-btn {
            background: linear-gradient(45deg, #1cc88a, #17a673);
            border: none;
            color: white;
            padding: 15px 30px;
            border-radius: 30px;
            font-size: 18px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            transition: all 0.3s ease;
        }
        
        /* QR Scanner Styling */
        #qrReader {
            border: 2px dashed #4e73df;
            border-radius: 10px;
            padding: 10px;
            margin-bottom: 15px;
        }
        
        #qrReader img, #qrReader video {
            border-radius: 8px;
            max-width: 100%;
            height: auto;
        }
        
        /* File input button styling */
        #qrReader input[type="file"] {
            margin-top: 10px;
            padding: 10px;
        }
        
        #scanResult {
            min-height: 50px;
            margin-top: 15px;
        }
        
        .manual-input-section {
            margin-top: 20px;
            padding: 20px;
            background: #f8f9fc;
            border-radius: 10px;
        }
        
        .complete-btn:hover {
            transform: scale(1.05);
            color: white;
            box-shadow: 0 6px 12px rgba(28, 200, 138, 0.3);
        }
        
        .complete-btn:disabled {
            background: #6c757d;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        
        /* Custom SweetAlert2 button styles */
        .swal2-popup .btn-confirm-delivery {
            background: linear-gradient(135deg, #17a2b8 0%, #138496 100%) !important;
            border: none !important;
            font-weight: 600 !important;
            padding: 10px 20px !important;
            border-radius: 25px !important;
            transition: all 0.3s ease !important;
        }
        
        .swal2-popup .btn-confirm-delivery:hover {
            transform: translateY(-1px) !important;
            box-shadow: 0 4px 12px rgba(23, 162, 184, 0.3) !important;
        }
        
        .swal2-popup .btn-export-management {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%) !important;
            border: none !important;
            font-weight: 600 !important;
            padding: 10px 20px !important;
            border-radius: 25px !important;
            transition: all 0.3s ease !important;
        }
        
        .swal2-popup .btn-export-management:hover {
            transform: translateY(-1px) !important;
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3) !important;
        }
        
        .qr-modal .modal-content {
            border-radius: 15px;
            overflow: hidden;
        }
        
        .qr-modal .modal-header {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            border: none;
        }
        
        .manual-input-section {
            background: #f8f9fc;
            border: 2px dashed #4e73df;
            border-radius: 10px;
            padding: 20px;
            margin-top: 15px;
            text-align: center;
        }
        
        #qrReader {
            border-radius: 10px;
            overflow: hidden;
        }
        
        .scan-result {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            border-radius: 8px;
            padding: 15px;
            margin-top: 15px;
        }
        
        .scan-error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            border-radius: 8px;
            padding: 15px;
            margin-top: 15px;
        }
    </style>
</head>

<body id="page-top">

    <!-- Page Wrapper -->
    <div id="wrapper">
        <!-- Sidebar (simplified for processing page) -->
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
                                <h2 class="mb-1">
                                    <i class="fas fa-tasks mr-2"></i>
                                    Xử lý phiếu xuất: <?php echo htmlspecialchars($exportInfo['export_code']); ?>
                                </h2>
                                <p class="mb-0">
                                    <i class="fas fa-user mr-1"></i>
                                    Khách hàng: <?php echo htmlspecialchars($exportInfo['customer_name']); ?>
                                    <?php if ($exportInfo['customer_phone']): ?>
                                    | <i class="fas fa-phone mr-1"></i><?php echo htmlspecialchars($exportInfo['customer_phone']); ?>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <div class="col-md-4 text-right">
                                <button type="button" class="btn btn-light btn-lg" data-toggle="modal" data-target="#qrScanModal">
                                    <i class="fas fa-qrcode mr-2"></i>
                                    Quét QR Code
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Progress Section -->
                    <div class="progress-section">
                        <div class="row align-items-center">
                            <div class="col-md-8">
                                <h5 class="mb-2">
                                    <i class="fas fa-chart-line mr-2 text-primary"></i>
                                    Tiến độ xử lý: <?php echo $completedItems; ?>/<?php echo $totalItems; ?> sản phẩm
                                </h5>
                                <div class="progress mb-2" style="height: 20px;">
                                    <div class="progress-bar bg-success" role="progressbar" 
                                         style="width: <?php echo $progressPercentage; ?>%" 
                                         aria-valuenow="<?php echo $progressPercentage; ?>" 
                                         aria-valuemin="0" aria-valuemax="100">
                                        <?php echo $progressPercentage; ?>%
                                    </div>
                                </div>
                                <p class="text-muted mb-0">
                                    <?php if ($completedItems == $totalItems): ?>
                                        <i class="fas fa-check-circle text-success mr-1"></i>
                                        Tất cả sản phẩm đã được lấy đủ!
                                    <?php else: ?>
                                        <i class="fas fa-info-circle text-info mr-1"></i>
                                        Còn <?php echo $totalItems - $completedItems; ?> sản phẩm cần lấy
                                    <?php endif; ?>
                                </p>
                            </div>
                            <div class="col-md-4 text-right">
                                <button id="completeBtn" class="complete-btn" 
                                        onclick="completeExport()" 
                                        <?php echo $completedItems < $totalItems ? 'disabled' : ''; ?>>
                                    <i class="fas fa-check-double mr-2"></i>
                                    Hoàn thành
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Items List -->
                    <div class="row">
                        <?php foreach ($items as $index => $item): ?>
                        <?php $isCompleted = $item['picked_quantity'] >= $item['required_quantity']; ?>
                        <div class="col-lg-6 mb-4">
                            <div class="item-card <?php echo $isCompleted ? 'completed' : ''; ?>" 
                                 data-item-id="<?php echo $item['detail_id']; ?>"
                                 data-variant-id="<?php echo $item['variant_id']; ?>"
                                 data-required="<?php echo $item['required_quantity']; ?>"
                                 data-qr-code="<?php echo htmlspecialchars($item['qr_code']); ?>">
                                
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-8">
                                            <h6 class="card-title font-weight-bold text-primary">
                                                <?php echo htmlspecialchars($item['product_name']); ?>
                                            </h6>
                                            <p class="card-text">
                                                <strong>SKU:</strong> <?php echo htmlspecialchars($item['sku']); ?><br>
                                                <strong>Màu:</strong> <?php echo htmlspecialchars($item['color']); ?> | 
                                                <strong>Size:</strong> <?php echo htmlspecialchars($item['size']); ?><br>
                                                <?php if ($item['brand']): ?>
                                                <strong>Thương hiệu:</strong> <?php echo htmlspecialchars($item['brand']); ?><br>
                                                <?php endif; ?>
                                            </p>
                                            
                                            <?php if ($item['location_info']): ?>
                                            <div class="location-section">
                                                <i class="fas fa-map-marker-alt mr-2"></i>
                                                <strong>Vị trí lấy hàng:</strong><br>
                                                <div class="mt-2">
                                                    <?php 
                                                    // Tách các vị trí nếu có nhiều
                                                    $locations = explode(', ', $item['location_info']);
                                                    foreach ($locations as $location) {
                                                        echo '<span class="location-badge" title="Vị trí kho">' . htmlspecialchars($location) . '</span>';
                                                    }
                                                    ?>
                                                </div>
                                                <small class="text-muted d-block mt-2">
                                                    <i class="fas fa-info-circle"></i> Tổng tồn: <strong class="text-success"><?php echo $item['available_quantity'] ?? 0; ?></strong> sản phẩm
                                                </small>
                                            </div>
                                            <?php else: ?>
                                            <div class="alert alert-warning py-2 px-3 mb-2">
                                                <i class="fas fa-exclamation-triangle mr-1"></i>
                                                <small>Chưa có thông tin vị trí</small>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <div class="quantity-info">
                                                <div class="row">
                                                    <div class="col-6">
                                                        <small class="text-muted">Cần lấy:</small><br>
                                                        <strong class="text-primary"><?php echo $item['required_quantity']; ?></strong>
                                                    </div>
                                                    <div class="col-6">
                                                        <small class="text-muted">Đã lấy:</small><br>
                                                        <strong class="<?php echo $isCompleted ? 'text-success' : 'text-warning'; ?>">
                                                            <?php echo $item['picked_quantity']; ?>
                                                        </strong>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-4 text-center">
                                            <?php if (!$isCompleted): ?>
                                            <button class="qr-scan-btn btn btn-primary btn-sm mb-2" 
                                                    onclick="openQRModal(<?php echo $item['detail_id']; ?>)">
                                                <i class="fas fa-qrcode"></i><br>
                                                Quét QR
                                            </button>
                                            <br>
                                            <button class="btn btn-outline-secondary btn-sm" 
                                                    onclick="manualEntry(<?php echo $item['detail_id']; ?>)">
                                                <i class="fas fa-keyboard"></i><br>
                                                Nhập tay
                                            </button>
                                            <?php else: ?>
                                            <div class="text-success">
                                                <i class="fas fa-check-circle fa-3x"></i><br>
                                                <strong>Hoàn thành</strong>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                </div>
                <!-- /.container-fluid -->
            </div>
            <!-- End of Main Content -->
        </div>
        <!-- End of Content Wrapper -->
    </div>
    <!-- End of Page Wrapper -->

    <!-- QR Scanner Modal -->
    <div class="modal fade qr-modal" id="qrScanModal" tabindex="-1" role="dialog" aria-labelledby="qrScanModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="qrScanModalLabel">
                        <i class="fas fa-qrcode mr-2"></i>
                        Quét mã QR sản phẩm
                    </h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body text-center">
                    <!-- Hướng dẫn sử dụng -->
                    <div class="alert alert-info mb-3" style="font-size: 13px;">
                        <i class="fas fa-info-circle mr-2"></i>
                        <strong>Hướng dẫn quét mã QR:</strong>
                        <ul class="text-left mb-0 mt-2" style="font-size: 12px;">
                            <li><strong>Cách 1:</strong> Sử dụng camera để quét trực tiếp (khuyến nghị)</li>
                            <li><strong>Cách 2:</strong> Upload ảnh QR bằng nút "Choose File" bên dưới camera</li>
                            <li><strong>Lưu ý:</strong> Ảnh cần rõ nét, ánh sáng đủ, mã QR không bị méo</li>
                            <li><strong>Nếu không quét được:</strong> Dùng nút <strong>"Nhập thủ công"</strong> bên dưới</li>
                        </ul>
                    </div>
                    
                    <div id="qrReader"></div>
                    <div id="scanResult"></div>
                    
                    <!-- Manual Input Section -->
                    <div class="manual-input-section" id="manualInputSection" style="display: none;">
                        <h6><i class="fas fa-keyboard mr-2"></i>Nhập thủ công mã sản phẩm</h6>
                        <div class="form-group">
                            <input type="text" class="form-control" id="manualQRCode" 
                                   placeholder="Nhập mã QR hoặc SKU sản phẩm">
                        </div>
                        <button type="button" class="btn btn-primary" onclick="processManualCode()">
                            <i class="fas fa-check mr-1"></i>
                            Xác nhận
                        </button>
                    </div>
                    
                    <button type="button" class="btn btn-secondary mt-3" onclick="toggleManualInput()">
                        <i class="fas fa-keyboard mr-1"></i>
                        Nhập thủ công
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Manual SKU Input Modal -->
    <div class="modal fade" id="manualSkuModal" tabindex="-1" role="dialog" aria-labelledby="manualSkuModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="manualSkuModalLabel">
                        <i class="fas fa-keyboard mr-2"></i>
                        Nhập mã SKU sản phẩm
                    </h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label for="manualSku">Mã SKU:</label>
                        <input type="text" class="form-control" id="manualSku" 
                               placeholder="Nhập mã SKU sản phẩm" autofocus>
                        <small class="form-text text-muted">
                            <i class="fas fa-info-circle mr-1"></i>
                            Vui lòng nhập đúng mã SKU của sản phẩm cần lấy
                        </small>
                    </div>
                    <div id="skuResult"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Hủy</button>
                    <button type="button" class="btn btn-primary" onclick="validateSku()">
                        <i class="fas fa-check mr-1"></i>
                        Xác nhận
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Quantity Input Modal -->
    <div class="modal fade" id="quantityModal" tabindex="-1" role="dialog" aria-labelledby="quantityModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="quantityModalLabel">
                        <i class="fas fa-boxes mr-2"></i>
                        Nhập số lượng đã lấy
                    </h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div id="productInfo"></div>
                    <div class="form-group">
                        <label for="pickedQuantity">Số lượng đã lấy:</label>
                        <input type="number" class="form-control" id="pickedQuantity" min="0" step="1">
                        <small class="form-text text-muted">
                            Số lượng yêu cầu: <span id="requiredQuantity"></span>
                        </small>
                    </div>
                    <div id="quantityResult"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Hủy</button>
                    <button type="button" class="btn btn-success" onclick="saveQuantity()">
                        <i class="fas fa-save mr-1"></i>
                        Lưu số lượng
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="vendor/jquery/jquery.min.js"></script>
    <script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="vendor/jquery-easing/jquery.easing.min.js"></script>
    <script src="js/sb-admin-2.min.js"></script>
    
    <!-- QR Code Scanner -->
    <script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <!-- Fallback QR Reader -->
    <script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js"></script>
    
    <script>
        let html5QrcodeScanner;
        let currentItemId = null;
        let exportId = <?php echo $exportId; ?>;
        let exportCode = '<?php echo htmlspecialchars($exportInfo['export_code']); ?>';
        
        // Khởi tạo tooltip cho location badges
        $(function () {
            $('[data-toggle="tooltip"]').tooltip();
            
            // Thêm tooltip cho tất cả location badges
            $('.location-badge').each(function() {
                const text = $(this).text();
                $(this).attr('data-toggle', 'tooltip');
                $(this).attr('data-placement', 'top');
                $(this).attr('title', 'Click để xem chi tiết vị trí: ' + text);
                $(this).css('cursor', 'pointer');
            });
            
            // Khởi tạo tooltip
            $('.location-badge').tooltip();
            
            // Xử lý click vào location badge
            $('.location-badge').on('click', function() {
                const locationText = $(this).text().trim();
                showLocationDetail(locationText);
            });
            
            console.log('✅ Đã khởi tạo tooltip cho vị trí sản phẩm');
        });
        
        // Hiển thị chi tiết vị trí
        function showLocationDetail(locationText) {
            // Parse location text: "A01-01-01 (10)" -> shelf_code: A01-01-01, quantity: 10
            const match = locationText.match(/^(.+?)\s*\((\d+)\)$/);
            
            if (match) {
                const shelfCode = match[1].trim();
                const quantity = match[2];
                
                Swal.fire({
                    title: '<i class="fas fa-map-marker-alt text-primary"></i> Chi tiết vị trí',
                    html: `
                        <div class="text-left" style="font-size: 14px; line-height: 1.8;">
                            <div class="alert alert-info mb-3">
                                <h5 class="mb-2"><i class="fas fa-warehouse"></i> Vị trí kho</h5>
                                <p class="mb-1"><strong>Mã vị trí:</strong> <span class="badge badge-primary" style="font-size: 14px;">${shelfCode}</span></p>
                                <p class="mb-0"><strong>Số lượng tồn:</strong> <span class="badge badge-success" style="font-size: 14px;">${quantity} sản phẩm</span></p>
                            </div>
                            
                            <div class="alert alert-warning mb-0">
                                <h6><i class="fas fa-info-circle"></i> Hướng dẫn</h6>
                                <ul class="mb-0 pl-3">
                                    <li>Đến khu vực có mã: <strong>${shelfCode}</strong></li>
                                    <li>Quét mã QR trên sản phẩm để xác nhận</li>
                                    <li>Lấy số lượng cần thiết</li>
                                </ul>
                            </div>
                        </div>
                    `,
                    icon: 'info',
                    confirmButtonText: '<i class="fas fa-check"></i> Đã hiểu',
                    confirmButtonColor: '#4e73df',
                    width: '500px'
                });
            } else {
                Swal.fire({
                    title: 'Thông tin vị trí',
                    text: locationText,
                    icon: 'info',
                    confirmButtonText: 'OK'
                });
            }
        }
        
        // Initialize QR Scanner
        function initQRScanner() {
            if (html5QrcodeScanner) {
                html5QrcodeScanner.clear();
            }
            
            html5QrcodeScanner = new Html5QrcodeScanner(
                "qrReader",
                { 
                    fps: 10, 
                    qrbox: { width: 300, height: 300 },
                    // Hỗ trợ nhiều định dạng mã
                    formatsToSupport: [ 
                        Html5QrcodeSupportedFormats.QR_CODE,
                        Html5QrcodeSupportedFormats.DATA_MATRIX,
                        Html5QrcodeSupportedFormats.AZTEC,
                        Html5QrcodeSupportedFormats.PDF_417
                    ],
                    // Bật nhiều thuật toán giải mã
                    experimentalFeatures: {
                        useBarCodeDetectorIfSupported: true
                    },
                    // Tối ưu cho file upload
                    rememberLastUsedCamera: true,
                    aspectRatio: 1.0,
                    showTorchButtonIfSupported: true,
                    // Cải thiện đọc từ ảnh
                    disableFlip: false,
                    // Tăng khả năng đọc
                    videoConstraints: {
                        facingMode: "environment"
                    }
                },
                /* verbose= */ false
            );
            
            html5QrcodeScanner.render(onScanSuccess, onScanFailure);
        }
        
        // QR Scan Success Handler
        function onScanSuccess(decodedText, decodedResult) {
            // Xóa thông báo lỗi trước đó (nếu có)
            document.getElementById('scanResult').innerHTML = '';
            processQRCode(decodedText);
        }
        
        function onScanFailure(error) {
            // Chỉ hiển thị lỗi có ý nghĩa từ file upload
            const errorStr = error ? error.toString() : '';
            
            // Bỏ qua các lỗi scanning thông thường (khi camera đang tìm mã)
            if (errorStr.includes('NotFoundException') || 
                errorStr.includes('QR code parse error')) {
                // Đây là lỗi bình thường khi scan, không cần hiển thị
                return;
            }
            
            // Chỉ hiển thị lỗi khi upload file và không đọc được
            if (errorStr.includes('No MultiFormat Readers')) {
                // Thử dùng jsQR làm fallback
                tryFallbackQRReader();
            }
        }
        
        // Fallback QR Reader using jsQR
        function tryFallbackQRReader() {
            document.getElementById('scanResult').innerHTML = `
                <div class="alert alert-warning mt-3">
                    <i class="fas fa-sync-alt fa-spin mr-2"></i>
                    Đang thử phương thức đọc QR thay thế...
                </div>
            `;
            
            // Tìm file input từ html5-qrcode
            const fileInput = document.querySelector('#qrReader input[type="file"]');
            if (fileInput && fileInput.files && fileInput.files[0]) {
                const file = fileInput.files[0];
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    const img = new Image();
                    img.onload = function() {
                        const canvas = document.createElement('canvas');
                        const context = canvas.getContext('2d');
                        canvas.width = img.width;
                        canvas.height = img.height;
                        context.drawImage(img, 0, 0);
                        
                        const imageData = context.getImageData(0, 0, canvas.width, canvas.height);
                        
                        // Thử với jsQR
                        if (typeof jsQR !== 'undefined') {
                            const code = jsQR(imageData.data, imageData.width, imageData.height);
                            if (code) {
                                document.getElementById('scanResult').innerHTML = `
                                    <div class="alert alert-success mt-3">
                                        <i class="fas fa-check-circle mr-2"></i>
                                        Đọc được mã QR bằng phương thức thay thế!
                                    </div>
                                `;
                                processQRCode(code.data);
                            } else {
                                showQRReadFailure();
                            }
                        } else {
                            showQRReadFailure();
                        }
                    };
                    img.src = e.target.result;
                };
                
                reader.readAsDataURL(file);
            } else {
                showQRReadFailure();
            }
        }
        
        // Show QR read failure message
        function showQRReadFailure() {
            document.getElementById('scanResult').innerHTML = `
                <div class="alert alert-danger mt-3">
                    <i class="fas fa-exclamation-triangle mr-2"></i>
                    <strong>Không đọc được mã QR từ hình ảnh!</strong><br>
                    <small class="mt-2 d-block">Nguyên nhân có thể:</small>
                    <ul class="text-left mb-2 mt-2" style="font-size: 13px;">
                        <li>Hình ảnh bị mờ hoặc chất lượng thấp</li>
                        <li>Mã QR bị che khuất hoặc biến dạng</li>
                        <li>Độ tương phản không đủ</li>
                        <li>Góc chụp không vuông góc với mã QR</li>
                    </ul>
                    <small class="d-block mt-2"><strong>Giải pháp:</strong></small>
                    <ul class="text-left mb-0" style="font-size: 13px;">
                        <li>Chụp lại ảnh QR rõ nét hơn với ánh sáng tốt</li>
                        <li>Đảm bảo mã QR chiếm phần lớn khung hình và thẳng góc</li>
                        <li>Sử dụng camera trực tiếp thay vì upload ảnh (khuyến nghị)</li>
                        <li>Nhấn nút "Nhập thủ công" bên dưới để nhập SKU</li>
                    </ul>
                </div>
            `;
        }
        
        // Process QR Code
        function processQRCode(qrCode) {
            console.log('Processing QR Code:', qrCode);
            
            // Cố gắng parse JSON nếu QR code là JSON object (từ inventory)
            let parsedQR = null;
            let variantIdFromQR = null;
            let skuFromQR = null;
            
            try {
                parsedQR = JSON.parse(qrCode);
                console.log('Parsed QR as JSON:', parsedQR);
                
                // Lấy variant_id và sku từ JSON
                variantIdFromQR = parsedQR.variant_id;
                skuFromQR = parsedQR.sku;
            } catch (e) {
                // Không phải JSON, xử lý như string bình thường
                console.log('QR is plain text, not JSON');
            }
            
            // Find matching item
            const itemCards = document.querySelectorAll('.item-card[data-qr-code]');
            let matchedItem = null;
            
            for (let card of itemCards) {
                const cardQR = card.getAttribute('data-qr-code');
                const cardVariantId = card.getAttribute('data-variant-id');
                const sku = card.querySelector('.card-text').textContent.match(/SKU:\s*([^\s]+)/)?.[1];
                
                console.log('Comparing:', {
                    cardQR: cardQR,
                    cardVariantId: cardVariantId,
                    sku: sku,
                    scannedQR: qrCode,
                    variantIdFromQR: variantIdFromQR,
                    skuFromQR: skuFromQR
                });
                
                // Kiểm tra nhiều điều kiện khác nhau
                let isMatch = false;
                
                // 1. So sánh QR code string đơn giản
                if (cardQR === qrCode || qrCode === `QR_${sku}` || qrCode === sku) {
                    isMatch = true;
                }
                
                // 2. Nếu QR là JSON từ inventory, so sánh variant_id
                if (variantIdFromQR && cardVariantId && variantIdFromQR.toString() === cardVariantId.toString()) {
                    isMatch = true;
                    console.log('Match by variant_id from JSON!');
                }
                
                // 3. Nếu QR có SKU trong JSON, so sánh SKU
                if (skuFromQR && sku && skuFromQR === sku) {
                    isMatch = true;
                    console.log('Match by SKU from JSON!');
                }
                
                if (isMatch) {
                    matchedItem = card;
                    console.log('Match found!');
                    break;
                }
            }
            
            if (matchedItem) {
                const itemId = matchedItem.getAttribute('data-item-id');
                const requiredQty = matchedItem.getAttribute('data-required');
                const productName = matchedItem.querySelector('.card-title').textContent;
                
                // Hide QR modal and show quantity modal
                $('#qrScanModal').modal('hide');
                showQuantityModal(itemId, requiredQty);
                
                // Hiển thị thông tin vị trí nếu có trong QR JSON
                let locationInfo = '';
                if (parsedQR && parsedQR.location_code) {
                    locationInfo = `<br><small><i class="fas fa-map-marker-alt mr-1"></i>Vị trí: <strong>${parsedQR.location_code}</strong></small>`;
                }
                
                document.getElementById('scanResult').innerHTML = `
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle mr-2"></i>
                        <strong>Thành công!</strong> Đã tìm thấy sản phẩm phù hợp.<br>
                        <small>${productName}</small>
                        ${locationInfo}
                    </div>
                `;
            } else {
                // Tạo thông báo lỗi chi tiết
                let errorDetails = '';
                if (parsedQR) {
                    errorDetails = `
                        <small class="d-block mt-2">
                            <strong>Thông tin từ QR:</strong><br>
                            ${parsedQR.variant_id ? `Variant ID: ${parsedQR.variant_id}<br>` : ''}
                            ${parsedQR.sku ? `SKU: ${parsedQR.sku}<br>` : ''}
                            ${parsedQR.location_code ? `Vị trí: ${parsedQR.location_code}<br>` : ''}
                            ${parsedQR.product_name ? `Tên SP: ${parsedQR.product_name}<br>` : ''}
                        </small>
                    `;
                } else {
                    errorDetails = `<small>Mã quét được: <code>${qrCode.substring(0, 50)}${qrCode.length > 50 ? '...' : ''}</code></small>`;
                }
                
                document.getElementById('scanResult').innerHTML = `
                    <div class="alert alert-danger">
                        <i class="fas fa-times-circle mr-2"></i>
                        <strong>Lỗi!</strong> Sản phẩm này không có trong phiếu xuất.<br>
                        ${errorDetails}
                        <div class="mt-2">
                            <small><i class="fas fa-info-circle mr-1"></i>Vui lòng quét sản phẩm khác hoặc nhập thủ công.</small>
                        </div>
                    </div>
                `;
                
                console.error('No match found for QR code:', qrCode);
            }
        }
        
        // Manual Code Processing
        function processManualCode() {
            const manualCode = document.getElementById('manualQRCode').value.trim();
            if (manualCode) {
                processQRCode(manualCode);
                document.getElementById('manualQRCode').value = '';
            }
        }
        
        // Toggle Manual Input
        function toggleManualInput() {
            const section = document.getElementById('manualInputSection');
            section.style.display = section.style.display === 'none' ? 'block' : 'none';
        }
        
        // Open QR Modal for specific item
        function openQRModal(itemId) {
            currentItemId = itemId;
            $('#qrScanModal').modal('show');
        }
        
        // Manual Entry
        function manualEntry(itemId) {
            currentItemId = itemId;
            document.getElementById('manualSku').value = '';
            document.getElementById('skuResult').innerHTML = '';
            $('#manualSkuModal').modal('show');
        }
        
        // Validate SKU
        function validateSku() {
            const inputSku = document.getElementById('manualSku').value.trim().toUpperCase();
            
            if (!inputSku) {
                document.getElementById('skuResult').innerHTML = `
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle mr-2"></i>
                        Vui lòng nhập mã SKU!
                    </div>
                `;
                return;
            }
            
            // Lấy thông tin item hiện tại
            const itemCard = document.querySelector(`[data-item-id="${currentItemId}"]`);
            if (!itemCard) {
                document.getElementById('skuResult').innerHTML = `
                    <div class="alert alert-danger">
                        <i class="fas fa-times-circle mr-2"></i>
                        Không tìm thấy thông tin sản phẩm!
                    </div>
                `;
                return;
            }
            
            const requiredQty = itemCard.getAttribute('data-required');
            const pickedQty = itemCard.getAttribute('data-picked');
            const productName = itemCard.querySelector('.card-title').textContent;
            const skuText = itemCard.querySelector('.card-text').textContent;
            const correctSku = skuText.match(/SKU:\s*([^\s]+)/)?.[1];
            
            // Kiểm tra SKU có khớp không
            if (inputSku !== correctSku.toUpperCase()) {
                document.getElementById('skuResult').innerHTML = `
                    <div class="alert alert-danger">
                        <i class="fas fa-times-circle mr-2"></i>
                        <strong>Sai mã SKU!</strong><br>
                        <small>Bạn đã nhập: <code>${inputSku}</code></small><br>
                        <small>Mã SKU đúng: <code>${correctSku}</code></small>
                    </div>
                `;
                return;
            }
            
            // SKU đúng - chuyển sang modal nhập số lượng
            document.getElementById('skuResult').innerHTML = `
                <div class="alert alert-success">
                    <i class="fas fa-check-circle mr-2"></i>
                    <strong>Mã SKU hợp lệ!</strong>
                </div>
            `;
            
            setTimeout(() => {
                $('#manualSkuModal').modal('hide');
                showQuantityModal(currentItemId, requiredQty);
            }, 500);
        }
        
        // Show Quantity Modal
        function showQuantityModal(itemId, requiredQty) {
            currentItemId = itemId;
            document.getElementById('requiredQuantity').textContent = requiredQty;
            document.getElementById('pickedQuantity').value = requiredQty;
            document.getElementById('pickedQuantity').max = requiredQty;
            
            // Get product info
            const itemCard = document.querySelector(`[data-item-id="${itemId}"]`);
            const productName = itemCard.querySelector('.card-title').textContent;
            document.getElementById('productInfo').innerHTML = `
                <div class="alert alert-info">
                    <strong>Sản phẩm:</strong> ${productName}
                </div>
            `;
            
            $('#quantityModal').modal('show');
        }
        
        // Save Quantity
        function saveQuantity() {
            const pickedQty = parseInt(document.getElementById('pickedQuantity').value);
            const requiredQty = parseInt(document.getElementById('requiredQuantity').textContent);
            
            if (isNaN(pickedQty) || pickedQty <= 0) {
                document.getElementById('quantityResult').innerHTML = `
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        Số lượng không hợp lệ! Vui lòng nhập số lượng lớn hơn 0.
                    </div>
                `;
                return;
            }
            
            if (pickedQty > requiredQty) {
                document.getElementById('quantityResult').innerHTML = `
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        <strong>Số lượng vượt quá yêu cầu!</strong><br>
                        <small>Yêu cầu: <strong>${requiredQty}</strong>, Đã nhập: <strong>${pickedQty}</strong></small>
                    </div>
                `;
                return;
            }
            
            if (pickedQty < requiredQty) {
                document.getElementById('quantityResult').innerHTML = `
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle mr-2"></i>
                        <strong>Số lượng chưa đủ!</strong><br>
                        <small>Yêu cầu: <strong>${requiredQty}</strong>, Đã nhập: <strong>${pickedQty}</strong></small>
                    </div>
                `;
                return;
            }
            
            // Send to server
            fetch('api_xu_ly_xuat.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    action: 'update_picked_quantity',
                    export_id: exportId,
                    detail_id: currentItemId,
                    picked_quantity: pickedQty
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Hiển thị thông báo thành công ngắn gọn
                    document.getElementById('quantityResult').innerHTML = `
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle mr-2"></i>
                            <strong>Thành công!</strong> Đã cập nhật số lượng lấy.
                        </div>
                    `;
                    
                    // Đóng modal sau 1.5 giây và reload trang
                    setTimeout(() => {
                        $('#quantityModal').modal('hide');
                        location.reload(); // Refresh page to update UI
                    }, 1500);
                } else {
                    document.getElementById('quantityResult').innerHTML = `
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle mr-2"></i>
                            <strong>Lỗi:</strong> ${data.message}
                            <br><small>Vui lòng kiểm tra lại và thử lại.</small>
                        </div>
                    `;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                document.getElementById('quantityResult').innerHTML = `
                    <div class="alert alert-danger">
                        <i class="fas fa-wifi mr-2"></i>
                        <strong>Lỗi kết nối!</strong> Không thể lưu dữ liệu.
                        <br><small>Kiểm tra kết nối mạng và thử lại.</small>
                    </div>
                `;
            });
        }
        
        // Complete Export
        async function completeExport() {
            // Sử dụng SweetAlert2 thay vì confirm() để có giao diện đẹp hơn
            const result = await Swal.fire({
                title: '🔔 Xác nhận hoàn thành phiếu xuất',
                html: `
                    <div class="text-left" style="font-size: 14px; line-height: 1.6;">
                        <p><strong>Bạn có chắc chắn hoàn thành phiếu xuất [${exportCode}]?</strong></p>
                        <p><strong>Tất cả sản phẩm đã được lấy đủ số lượng</strong></p>
                        <hr>
                        <p class="text-muted" style="font-size: 12px;">
                            ⚠️ Sau khi xác nhận, phiếu xuất sẽ chuyển sang trạng thái "Đã đóng gói" 
                            và sẵn sàng để xác nhận xuất hàng.
                        </p>
                    </div>
                `,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#28a745',
                cancelButtonColor: '#6c757d',
                confirmButtonText: '✅ Có, hoàn thành',
                cancelButtonText: '❌ Chưa, kiểm tra lại',
                customClass: {
                    popup: 'animated fadeIn'
                }
            });

            if (!result.isConfirmed) {
                return;
            }
            
            console.log('Completing export:', exportId);
            
            try {
                const response = await fetch('api_xu_ly_xuat.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        action: 'complete_export',
                        export_id: exportId
                    })
                });
                
                console.log('Response status:', response.status);
                console.log('Response ok:', response.ok);
                
                // Đọc response body CHỈ MỘT LẦN
                const responseText = await response.text();
                console.log('Response text:', responseText);
                
                // Kiểm tra status code
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${responseText.substring(0, 200)}`);
                }
                
                // Parse JSON
                let data;
                try {
                    data = JSON.parse(responseText);
                } catch (parseErr) {
                    console.error('JSON parse error:', parseErr);
                    throw new Error('Server trả về dữ liệu không hợp lệ (không phải JSON): ' + responseText.substring(0, 200));
                }
                
                console.log('Response data:', data);
                
                if (data.success) {
                    await Swal.fire({
                        title: '🎉 Hoàn thành phiếu xuất!',
                        html: `
                            <div class="text-left" style="font-size: 14px; line-height: 1.6;">
                                <p><strong>✅ Đã đóng gói xong, sẵn sàng giao hàng</strong></p>
                                <p><strong>📊 Tồn kho đã được cập nhật</strong></p>
                                <p><strong>📋 Đã cập nhật trạng thái "Đã đóng gói"</strong></p>
                                <p class="text-info"><em>💡 Vui lòng kiểm tra thông tin tại phần Xác nhận xuất đơn</em></p>
                            </div>
                        `,
                        icon: 'success',
                        confirmButtonColor: '#28a745',
                        confirmButtonText: 'Đóng',
                        allowOutsideClick: false,
                        customClass: {
                            popup: 'animated fadeIn'
                        }
                    });

                    // Chuyển về trang quản lý phiếu xuất
                    window.location.href = 'quan_ly_xuat_kho.php';
                } else {
                    await Swal.fire({
                        title: '❌ Không thể hoàn thành phiếu xuất',
                        html: `
                            <div class="text-left" style="font-size: 14px; line-height: 1.6;">
                                <p><strong>💡 Lý do:</strong> ${data.message || 'Không xác định'}</p>
                                <hr>
                                <p><strong>🔍 Vui lòng kiểm tra:</strong></p>
                                <ul style="text-align: left; margin-left: 20px;">
                                    <li>Tất cả sản phẩm đã được lấy đủ số lượng?</li>
                                    <li>Phiếu xuất đang ở trạng thái hợp lệ?</li>
                                    <li>Kết nối mạng ổn định?</li>
                                </ul>
                                <hr>
                                <p class="text-muted" style="font-size: 12px;">
                                    📞 Nếu vấn đề tiếp tục, vui lòng liên hệ IT hỗ trợ.
                                </p>
                            </div>
                        `,
                        icon: 'error',
                        confirmButtonColor: '#dc3545',
                        confirmButtonText: '❌ Đã hiểu'
                    });
                    console.error('API error:', data);
                }
                
            } catch (error) {
                console.error('Complete export error:', error);
                
                // Hiển thị chi tiết lỗi với hướng dẫn khắc phục
                let errorMessage = '🚨 LỖI NGHIÊM TRỌNG KHI XỬ LÝ PHIẾU XUẤT\n\n';
                
                if (error.message.includes('HTTP 500')) {
                    errorMessage += '💥 Lỗi máy chủ nội bộ\n' +
                                  '🔧 Khắc phục: Vui lòng liên hệ IT ngay lập tức\n' +
                                  '📊 Dữ liệu của bạn đã được bảo toàn an toàn';
                } else if (error.message.includes('Failed to fetch') || error.message.includes('network')) {
                    errorMessage += '🌐 Lỗi kết nối mạng\n' +
                                  '🔧 Khắc phục: Kiểm tra kết nối internet và thử lại\n' +
                                  '⏰ Dữ liệu chưa được lưu, vui lòng thực hiện lại';
                } else {
                    errorMessage += '⚠️ Lỗi không xác định\n' +
                                  '📋 Chi tiết: ' + (error.message || 'Không có thông tin thêm') + '\n' +
                                  '🔧 Vui lòng thử lại hoặc liên hệ hỗ trợ';
                }
                
                // Sử dụng SweetAlert2 cho thông báo lỗi nghiêm trọng
                let alertContent = '';
                let alertTitle = '🚨 Lỗi nghiêm trọng';
                
                if (error.message.includes('HTTP 500')) {
                    alertContent = `
                        <div class="text-left" style="font-size: 14px; line-height: 1.6;">
                            <p><strong>💥 Lỗi máy chủ nội bộ</strong></p>
                            <p><strong>🔧 Khắc phục:</strong> Vui lòng liên hệ IT ngay lập tức</p>
                            <p><strong>📊 Trạng thái:</strong> Dữ liệu của bạn đã được bảo toàn an toàn</p>
                            <hr>
                            <p class="text-muted" style="font-size: 12px;">
                                📞 Hỗ trợ khẩn cấp: Liên hệ IT hoặc quản lý kho
                            </p>
                        </div>
                    `;
                } else if (error.message.includes('Failed to fetch') || error.message.includes('network')) {
                    alertContent = `
                        <div class="text-left" style="font-size: 14px; line-height: 1.6;">
                            <p><strong>🌐 Lỗi kết nối mạng</strong></p>
                            <p><strong>🔧 Khắc phục:</strong> Kiểm tra kết nối internet và thử lại</p>
                            <p><strong>⏰ Trạng thái:</strong> Dữ liệu chưa được lưu, vui lòng thực hiện lại</p>
                            <hr>
                            <p class="text-muted" style="font-size: 12px;">
                                📞 Hỗ trợ khẩn cấp: Liên hệ IT hoặc quản lý kho
                            </p>
                        </div>
                    `;
                } else {
                    alertContent = `
                        <div class="text-left" style="font-size: 14px; line-height: 1.6;">
                            <p><strong>⚠️ Lỗi không xác định</strong></p>
                            <p><strong>📋 Chi tiết:</strong> ${error.message || 'Không có thông tin thêm'}</p>
                            <p><strong>🔧 Khắc phục:</strong> Vui lòng thử lại hoặc liên hệ hỗ trợ</p>
                            <hr>
                            <p class="text-muted" style="font-size: 12px;">
                                📞 Hỗ trợ khẩn cấp: Liên hệ IT hoặc quản lý kho
                            </p>
                        </div>
                    `;
                }
                
                await Swal.fire({
                    title: alertTitle,
                    html: alertContent,
                    icon: 'error',
                    confirmButtonColor: '#dc3545',
                    confirmButtonText: '🆘 Đã hiểu',
                    allowOutsideClick: false
                });
            }
        }
        
        // Modal Events
        $('#qrScanModal').on('shown.bs.modal', function () {
            if (!html5QrcodeScanner) {
                initQRScanner();
            }
        });
        
        $('#qrScanModal').on('hidden.bs.modal', function () {
            if (html5QrcodeScanner) {
                html5QrcodeScanner.clear();
            }
            document.getElementById('scanResult').innerHTML = '';
            document.getElementById('manualInputSection').style.display = 'none';
            document.getElementById('manualQRCode').value = '';
        });
        
        // Enter key for manual input
        document.getElementById('manualQRCode').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                processManualCode();
            }
        });
        
        // Enter key for manual SKU input
        document.getElementById('manualSku').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                validateSku();
            }
        });
        
        // Enter key for quantity input
        document.getElementById('pickedQuantity').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                saveQuantity();
            }
        });
    </script>

</body>
</html>
