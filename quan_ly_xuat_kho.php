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

// Lấy thông tin warehouse
$warehouseName = 'Smart Warehouse';
if ($warehouseId) {
    $warehouseObj = new Warehouse($pdo);
    if ($warehouseObj->getById($warehouseId)) {
        $warehouseName = $warehouseObj->name;
    }
}

// Lấy danh sách phiếu xuất kho
$sql = "
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
        w.name as warehouse_name,
        pu.username as processed_by_name,
        (
            SELECT COUNT(*)
            FROM warehouse_export_details wed
            WHERE wed.export_id = we.export_id
        ) as total_items,
        (
            SELECT COUNT(*)
            FROM warehouse_export_details wed
            WHERE wed.export_id = we.export_id AND COALESCE(wed.picked_quantity, 0) >= wed.quantity
        ) as picked_items,
        (
            SELECT SUM(wed.total_price)
            FROM warehouse_export_details wed
            WHERE wed.export_id = we.export_id
        ) as total_amount
    FROM warehouse_exports we
    JOIN orders o ON we.order_id = o.order_id
    LEFT JOIN customers c ON o.customer_id = c.customer_id
    JOIN warehouses w ON we.warehouse_id = w.warehouse_id
    LEFT JOIN users pu ON we.processed_by = pu.user_id
    WHERE we.warehouse_id = :warehouse_id
    AND we.status != 'completed'
    AND (we.status != 'confirmed' AND (we.confirmed_delivery_at IS NULL OR we.confirmed_delivery_by IS NULL))
    ORDER BY 
        CASE we.status
            WHEN 'pending' THEN 1
            WHEN 'processing' THEN 2
            WHEN 'cancelled' THEN 3
        END,
        we.created_at DESC
";

$stmt = $pdo->prepare($sql);
$stmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
$stmt->execute();
$exports = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Thống kê (không bao gồm phiếu đã đóng gói)
$stats = [
    'pending' => 0,
    'processing' => 0,
    'cancelled' => 0,
    'total' => count($exports)
];

foreach ($exports as $export) {
    $status = $export['status'] ?? '';
    if ($status && isset($stats[$status])) {
        $stats[$status]++;
    }
}

// Thống kê riêng cho phiếu đã đóng gói (để hiển thị thông báo)
$completedCountSql = "
    SELECT COUNT(*) as completed_count
    FROM warehouse_exports we
    JOIN orders o ON we.order_id = o.order_id
    WHERE we.warehouse_id = :warehouse_id
    AND we.status = 'completed'
    AND o.status = 'delivered'
";
$completedStmt = $pdo->prepare($completedCountSql);
$completedStmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
$completedStmt->execute();
$completedCount = $completedStmt->fetch(PDO::FETCH_ASSOC)['completed_count'];
?>

<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    
    <title>Quản lý phiếu xuất kho - <?php echo htmlspecialchars($warehouseName); ?></title>

    <!-- Custom fonts for this template -->
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">

    <!-- Custom styles for this template -->
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    
    <!-- Page level plugin CSS-->
    <link href="vendor/datatables/dataTables.bootstrap4.min.css" rel="stylesheet">
    
    <style>
        .status-badge {
            font-size: 12px;
            padding: 6px 12px;
            border-radius: 15px;
            font-weight: 600;
            display: inline-block;
            white-space: nowrap;
            text-align: center;
            min-width: 100px;
        }
        .status-pending { 
            background-color: #ffeaa7 !important; 
            color: #d63031 !important; 
            border: 1px solid #fdcb6e;
        }
        .status-processing { 
            background-color: #74b9ff !important; 
            color: #ffffff !important; 
            border: 1px solid #5a9cff;
        }
        .status-completed { 
            background-color: #00b894 !important; 
            color: #ffffff !important; 
            border: 1px solid #00a085;
        }
        .status-cancelled { 
            background-color: #fd79a8 !important; 
            color: #ffffff !important; 
            border: 1px solid #e84393;
        }
        
        .progress {
            height: 8px;
            background-color: #e9ecef;
            border-radius: 4px;
        }
        
        .progress-bar {
            height: 100%;
            background: linear-gradient(90deg, #28a745 0%, #20c997 100%);
            border-radius: 4px;
        }
        
        .progress-info {
            font-size: 11px;
            color: #6c757d;
            margin-top: 2px;
        }
        
        .action-buttons .btn {
            margin-right: 5px;
            margin-bottom: 5px;
        }
        
        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .stats-card {
            border-left: 4px solid;
            border-radius: 0.35rem;
            transition: all 0.3s ease;
        }
        
        .stats-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .stats-pending { border-color: #f39c12; }
        .stats-processing { border-color: #3498db; }
        .stats-completed { border-color: #27ae60; }
        .stats-cancelled { border-color: #e74c3c; }
        
        /* Highlighted card for ready to ship */
        .stats-card.ready-to-ship {
            border: 2px solid #28a745;
            box-shadow: 0 0 20px rgba(40, 167, 69, 0.3);
            animation: pulse-green 2s infinite;
        }
        
        @keyframes pulse-green {
            0% { box-shadow: 0 0 20px rgba(40, 167, 69, 0.3); }
            50% { box-shadow: 0 0 30px rgba(40, 167, 69, 0.6); }
            100% { box-shadow: 0 0 20px rgba(40, 167, 69, 0.3); }
        }
        
        .ready-to-ship:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 25px rgba(40, 167, 69, 0.4);
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
                            <i class="fas fa-truck-loading text-primary mr-2"></i>
                            Quản lý phiếu xuất kho
                        </h1>
                        <button onclick="refreshPage()" class="d-none d-sm-inline-block btn btn-sm btn-primary shadow-sm">
                            <i class="fas fa-sync-alt fa-sm text-white-50"></i> Làm mới
                        </button>
                    </div>

                    <!-- Content Row - Statistics -->
                    <div class="row">
                        <!-- Pending Card -->
                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="card stats-card stats-pending shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                                Chờ lấy hàng</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $stats['pending']; ?></div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-clock fa-2x text-warning"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Processing Card -->
                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="card stats-card stats-processing shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                                Đang xử lý</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $stats['processing']; ?></div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-tasks fa-2x text-info"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Completed Card - Link to Confirm Delivery -->
                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="card stats-card stats-completed shadow h-100 py-2 <?php echo $completedCount > 0 ? 'ready-to-ship' : ''; ?>" style="cursor: pointer;" onclick="window.location.href='xac_nhan_giao_hang.php'">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                                Sẵn sàng xuất hàng</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                                <?php echo $completedCount; ?>
                                                <?php if ($completedCount > 0): ?>
                                                <small class="text-success ml-2">
                                                    <i class="fas fa-arrow-right"></i> Xác nhận xuất
                                                </small>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-shipping-fast fa-2x text-success"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Total Card -->
                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="card border-left-primary shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                                Tổng phiếu xuất</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $stats['total']; ?></div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-file-alt fa-2x text-primary"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Info Alert about Completed Exports -->
                    <?php if ($completedCount > 0): ?>
                    <div class="alert alert-info alert-dismissible fade show mb-4" role="alert">
                        <i class="fas fa-info-circle mr-2"></i>
                        <strong>Thông báo:</strong> Có <strong><?php echo $completedCount; ?> phiếu xuất</strong> đã hoàn thành đóng gói và 
                        <strong>sẵn sàng xuất hàng</strong>. Các phiếu này đã được chuyển sang trang 
                        <a href="xac_nhan_giao_hang.php" class="alert-link">
                            <i class="fas fa-shipping-fast mr-1"></i>Xác nhận xuất hàng
                        </a> 
                        để thực hiện bước cuối cùng.
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <?php endif; ?>

                    <!-- DataTales Example -->
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-white">
                                <i class="fas fa-list-alt mr-2"></i>
                                Danh sách phiếu xuất kho
                            </h6>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered" id="exportTable" width="100%" cellspacing="0">
                                    <thead>
                                        <tr>
                                            <th>STT</th>
                                            <th>Mã phiếu</th>
                                            <th>Khách hàng</th>
                                            <th>Trạng thái</th>
                                            <th>Tiến độ</th>
                                            <th>Tổng tiền</th>
                                            <th>Ngày tạo</th>
                                            <th>Người xử lý</th>
                                            <th>Thao tác</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($exports as $index => $export): ?>
                                        <tr>
                                            <td class="text-center"><?php echo $index + 1; ?></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($export['export_code']); ?></strong>
                                                <br>
                                                <small class="text-muted">#<?php echo str_pad($export['order_id'], 6, '0', STR_PAD_LEFT); ?></small>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($export['customer_name']); ?>
                                                <?php if ($export['customer_phone']): ?>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($export['customer_phone']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <?php 
                                                $statusClass = 'status-' . $export['status'];
                                                $statusText = [
                                                    'pending' => 'Chờ lấy hàng',
                                                    'processing' => 'Đang xử lý', 
                                                    'completed' => 'Đã đóng gói',
                                                    'cancelled' => 'Đã hủy'
                                                ];
                                                ?>
                                                <span class="status-badge <?php echo $statusClass; ?>">
                                                    <?php echo $statusText[$export['status']] ?? $export['status']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php 
                                                $total = $export['total_items'];
                                                $picked = $export['picked_items'];
                                                $percentage = $total > 0 ? round(($picked / $total) * 100) : 0;
                                                ?>
                                                <div class="progress-container">
                                                    <div class="progress">
                                                        <div class="progress-bar" style="width: <?php echo $percentage; ?>%"></div>
                                                    </div>
                                                    <div class="progress-info">
                                                        <?php echo $picked; ?>/<?php echo $total; ?> - <?php echo $percentage; ?>%
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <strong><?php echo number_format($export['total_amount'] ?? 0, 0, ',', '.'); ?>đ</strong>
                                            </td>
                                            <td>
                                                <?php echo date('d/m/Y H:i', strtotime($export['created_at'])); ?>
                                            </td>
                                            <td>
                                                <?php echo $export['processed_by_name'] ? htmlspecialchars($export['processed_by_name']) : '-'; ?>
                                            </td>
                                            <td class="action-buttons">
                                                <?php if ($export['status'] == 'pending' || $export['status'] == 'processing'): ?>
                                                <a href="xu_ly_xuat_kho.php?export_id=<?php echo $export['export_id']; ?>" 
                                                   class="btn btn-sm btn-primary" title="Xử lý phiếu">
                                                    <i class="fas fa-tasks"></i> Xử lý
                                                </a>
                                                <?php endif; ?>
                                                
                                                <a href="xem_phieu_xuat.php?export_id=<?php echo $export['export_id']; ?>" 
                                                   class="btn btn-sm btn-info" title="Xem chi tiết">
                                                    <i class="fas fa-eye"></i> Xem
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
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
                        <span>Copyright &copy; Smart Warehouse System 2025</span>
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

    <script>
        $(document()).ready(function() {
            var table = $('#exportTable').DataTable({
                "language": {
                    "url": "//cdn.datatables.net/plug-ins/1.10.24/i18n/Vietnamese.json"
                },
                "order": [[6, "desc"]], // Sort by created date (adjusted for STT column)
                "pageLength": 25,
                "columnDefs": [
                    { "orderable": false, "targets": [0, 8] } // Disable sorting for STT and action column
                ],
                "drawCallback": function() {
                    // Update STT numbers after each draw (sort, filter, paginate)
                    this.api().column(0, {search:'applied', order:'applied'}).nodes().each(function(cell, i) {
                        var pageInfo = table.page.info();
                        cell.innerHTML = pageInfo.start + i + 1;
                    });
                }
            });
        });
        
        function refreshPage() {
            location.reload();
        }
        
        function filterByStatus(status) {
            const table = $('#exportTable').DataTable();
            table.column(2).search(status).draw();
        }
    </script>

    <?php include 'includes/modal_dang_xuat.php'; ?>
</body>
</html>
