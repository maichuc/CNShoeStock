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
$userRole = $_SESSION['role'] ?? 'staff';

// Lấy thông tin warehouse
$warehouseName = 'Smart Warehouse';
if ($warehouseId) {
    $warehouseObj = new Warehouse($pdo);
    if ($warehouseObj->getById($warehouseId)) {
        $warehouseName = $warehouseObj->name;
    }
}

// Kiểm tra và thêm các cột cần thiết nếu chưa có
try {
    $checkColumn = $pdo->query("SHOW COLUMNS FROM warehouse_exports LIKE 'confirmed_delivery_at'");
    if ($checkColumn->rowCount() === 0) {
        $pdo->exec("ALTER TABLE warehouse_exports ADD COLUMN confirmed_delivery_at TIMESTAMP NULL DEFAULT NULL");
    }
    
    $checkColumn2 = $pdo->query("SHOW COLUMNS FROM warehouse_exports LIKE 'confirmed_delivery_by'");
    if ($checkColumn2->rowCount() === 0) {
        $pdo->exec("ALTER TABLE warehouse_exports ADD COLUMN confirmed_delivery_by INT(11) NULL DEFAULT NULL");
    }
} catch (PDOException $e) {
    error_log("Warning: Could not check/add columns: " . $e->getMessage());
}

// Debug: Kiểm tra tất cả phiếu xuất trong warehouse để debug
$debugSql = "SELECT export_id, export_code, status, confirmed_delivery_at, confirmed_delivery_by FROM warehouse_exports WHERE warehouse_id = :warehouse_id ORDER BY created_at DESC LIMIT 10";
$debugStmt = $pdo->prepare($debugSql);
$debugStmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
$debugStmt->execute();
$allExports = $debugStmt->fetchAll(PDO::FETCH_ASSOC);
error_log("DEBUG - All exports in warehouse $warehouseId: " . json_encode($allExports));

// Đếm số phiếu có confirmed_delivery_at (đã được xác nhận dù status có vấn đề)
$confirmedButEmptyStatus = 0;
foreach ($allExports as $exp) {
    if (!empty($exp['confirmed_delivery_at']) && empty($exp['status'])) {
        $confirmedButEmptyStatus++;
    }
}

// Lấy danh sách phiếu xuất đã xác nhận (dựa vào confirmed_delivery_at thay vì chỉ status)
// Vì có thể status bị lỗi hoặc rỗng nhưng confirmed_delivery_at vẫn có
$sql = "
    SELECT 
        we.export_id,
        we.export_code,
        we.order_id,
        we.status,
        we.confirmed_delivery_at,
        we.created_at,
        we.updated_at,
        o.customer_id,
        o.status as order_status,
        COALESCE(c.full_name, 'Khách lẻ') as customer_name,
        COALESCE(c.phone, '') as customer_phone,
        COALESCE(c.address, '') as customer_address,
        w.name as warehouse_name,
        cu.username as confirmed_by_name,
        (
            SELECT COUNT(*)
            FROM warehouse_export_details wed
            WHERE wed.export_id = we.export_id
        ) as total_items,
        (
            SELECT SUM(wed.quantity)
            FROM warehouse_export_details wed
            WHERE wed.export_id = we.export_id
        ) as total_quantity,
        (
            SELECT SUM(wed.total_price)
            FROM warehouse_export_details wed
            WHERE wed.export_id = we.export_id
        ) as total_amount
    FROM warehouse_exports we
    JOIN orders o ON we.order_id = o.order_id
    LEFT JOIN customers c ON o.customer_id = c.customer_id
    JOIN warehouses w ON we.warehouse_id = w.warehouse_id
    LEFT JOIN users cu ON we.confirmed_delivery_by = cu.user_id
    WHERE we.warehouse_id = :warehouse_id
    AND (we.status = 'confirmed' OR (we.confirmed_delivery_at IS NOT NULL AND we.confirmed_delivery_by IS NOT NULL))
    ORDER BY COALESCE(we.confirmed_delivery_at, we.updated_at, we.created_at) DESC
";

$stmt = $pdo->prepare($sql);
$stmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
$stmt->execute();
$exports = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Debug: Log số lượng phiếu xuất tìm thấy
error_log("Confirmed exports found: " . count($exports) . " for warehouse_id: " . $warehouseId);

// Thống kê
$stats = [
    'total_confirmed' => count($exports),
    'total_value' => array_sum(array_column($exports, 'total_amount')),
    'total_quantity' => array_sum(array_column($exports, 'total_quantity'))
];
?>

<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    
    <title>Danh sách phiếu xuất đã xác nhận - <?php echo htmlspecialchars($warehouseName); ?></title>

    <!-- Custom fonts for this template -->
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">

    <!-- Custom styles for this template -->
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    
    <!-- DataTables CSS -->
    <link href="vendor/datatables/dataTables.bootstrap4.min.css" rel="stylesheet">

    <style>
        .stats-card {
            border-left: 4px solid;
            transition: transform 0.2s;
        }
        .stats-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15) !important;
        }
        .export-table th {
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .status-badge {
            padding: 0.4rem 0.8rem;
            border-radius: 0.25rem;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
        }
        .status-confirmed {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .detail-section {
            line-height: 1.6;
        }
        .action-buttons {
            display: flex;
            gap: 0.3rem;
            flex-wrap: wrap;
        }
        .action-buttons .btn {
            padding: 0.25rem 0.5rem;
            font-size: 0.8rem;
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
                            <i class="fas fa-check-circle text-success mr-2"></i>
                            Danh sách phiếu xuất đã xác nhận
                        </h1>
                        <a href="quan_ly_xuat_kho.php" class="btn btn-sm btn-primary shadow-sm">
                            <i class="fas fa-arrow-left fa-sm mr-1"></i> Quay lại quản lý xuất kho
                        </a>
                    </div>

                    <!-- Stats Row -->
                    <div class="row mb-4">
                        <!-- Total Confirmed -->
                        <div class="col-xl-4 col-md-6 mb-4">
                            <div class="card stats-card border-left-success shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                                Tổng phiếu đã xác nhận
                                            </div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                                <?php echo number_format($stats['total_confirmed']); ?>
                                            </div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-file-invoice fa-2x text-gray-300"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Total Quantity -->
                        <div class="col-xl-4 col-md-6 mb-4">
                            <div class="card stats-card border-left-info shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                                Tổng số lượng đã xuất
                                            </div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                                <?php echo number_format($stats['total_quantity']); ?> sản phẩm
                                            </div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-boxes fa-2x text-gray-300"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Total Value -->
                        <div class="col-xl-4 col-md-6 mb-4">
                            <div class="card stats-card border-left-primary shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                                Tổng giá trị
                                            </div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                                <?php echo number_format($stats['total_value'], 0, ',', '.'); ?>đ
                                            </div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-dollar-sign fa-2x text-gray-300"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Exports List -->
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-success">
                                <i class="fas fa-list mr-2"></i>Danh sách phiếu xuất đã xác nhận xuất kho
                            </h6>
                        </div>
                        <div class="card-body">
                            <?php if (empty($exports)): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-inbox fa-3x text-gray-300 mb-3"></i>
                                <p class="text-muted mb-3"><strong>Chưa có phiếu xuất nào có trạng thái "confirmed"</strong></p>
                                <div class="alert alert-info text-left" role="alert">
                                    <strong><i class="fas fa-info-circle mr-2"></i>Điều kiện hiển thị:</strong>
                                    <ul class="mb-0 mt-2">
                                        <li>Phiếu xuất phải có <code>status = 'confirmed'</code></li>
                                        <li>Phiếu xuất được xác nhận tại trang <strong>"Xác nhận xuất hàng"</strong></li>
                                        <li>Chỉ hiển thị phiếu thuộc warehouse: <strong><?php echo htmlspecialchars($warehouseName); ?></strong> (ID: <?php echo $warehouseId; ?>)</li>
                                    </ul>
                                </div>
                                
                                <?php if (!empty($allExports)): ?>
                                <div class="alert alert-warning text-left mt-3" role="alert">
                                    <strong><i class="fas fa-database mr-2"></i>Thông tin debug:</strong>
                                    <p class="mb-2">Có <?php echo count($allExports); ?> phiếu xuất trong warehouse này:</p>
                                    <ul class="mb-0">
                                        <?php foreach ($allExports as $exp): ?>
                                        <li>
                                            <?php echo htmlspecialchars($exp['export_code']); ?>: 
                                            <code>status=<?php echo empty($exp['status']) ? '(rỗng/NULL)' : htmlspecialchars($exp['status']); ?></code>
                                            <?php if (!empty($exp['confirmed_delivery_at'])): ?>
                                                <span class="text-success">✓ Đã xác nhận lúc <?php echo date('d/m/Y H:i', strtotime($exp['confirmed_delivery_at'])); ?></span>
                                            <?php endif; ?>
                                        </li>
                                        <?php endforeach; ?>
                                    </ul>
                                    <?php if ($confirmedButEmptyStatus > 0): ?>
                                    <p class="mt-2 mb-0 text-danger">
                                        <strong>⚠️ Phát hiện <?php echo $confirmedButEmptyStatus; ?> phiếu đã xác nhận nhưng status bị rỗng!</strong><br>
                                        <small>Đang hiển thị các phiếu này dựa vào confirmed_delivery_at thay vì status.</small>
                                    </p>
                                    <?php else: ?>
                                    <p class="mt-2 mb-0"><small>💡 Các phiếu có status = 'completed' cần được xác nhận xuất để chuyển sang 'confirmed'</small></p>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                                
                                <div class="mt-3">
                                    <a href="xac_nhan_giao_hang.php" class="btn btn-primary">
                                        <i class="fas fa-check-circle mr-2"></i>Đi đến Xác nhận xuất hàng
                                    </a>
                                    <a href="quan_ly_xuat_kho.php" class="btn btn-secondary">
                                        <i class="fas fa-qrcode mr-2"></i>Xử lý đơn xuất kho
                                    </a>
                                </div>
                            </div>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-bordered export-table" id="exportsTable" width="100%" cellspacing="0">
                                    <thead>
                                        <tr>
                                            <th class="text-center">STT</th>
                                            <th>Mã phiếu xuất</th>
                                            <th>Khách hàng</th>
                                            <th class="text-center">Trạng thái</th>
                                            <th class="text-center">Số lượng</th>
                                            <th class="text-right">Tổng tiền</th>
                                            <th>Ngày xác nhận</th>
                                            <th>Người xác nhận</th>
                                            <th class="text-center">Thao tác</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($exports as $index => $export): ?>
                                        <tr>
                                            <td class="text-center"><?php echo $index + 1; ?></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($export['export_code']); ?></strong>
                                                <br>
                                                <small class="text-muted">Đơn #<?php echo str_pad($export['order_id'], 6, '0', STR_PAD_LEFT); ?></small>
                                            </td>
                                            <td>
                                                <div class="detail-section">
                                                    <strong><?php echo htmlspecialchars($export['customer_name']); ?></strong>
                                                    <?php if ($export['customer_phone']): ?>
                                                    <br><small class="text-muted"><i class="fas fa-phone mr-1"></i><?php echo htmlspecialchars($export['customer_phone']); ?></small>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td class="text-center">
                                                <span class="status-badge status-confirmed">
                                                    <i class="fas fa-check-circle"></i>Đã xác nhận
                                                </span>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge badge-info badge-pill">
                                                    <?php echo number_format($export['total_quantity']); ?> SP
                                                </span>
                                                <br>
                                                <small class="text-muted"><?php echo $export['total_items']; ?> loại</small>
                                            </td>
                                            <td class="text-right">
                                                <strong class="text-success">
                                                    <?php echo number_format($export['total_amount'] ?? 0, 0, ',', '.'); ?>đ
                                                </strong>
                                            </td>
                                            <td>
                                                <?php echo $export['confirmed_delivery_at'] ? date('d/m/Y H:i', strtotime($export['confirmed_delivery_at'])) : '-'; ?>
                                            </td>
                                            <td>
                                                <?php echo $export['confirmed_by_name'] ? htmlspecialchars($export['confirmed_by_name']) : '-'; ?>
                                            </td>
                                            <td class="action-buttons">
                                                <a href="xem_phieu_xuat.php?export_id=<?php echo $export['export_id']; ?>" 
                                                   class="btn btn-sm btn-info" title="Xem chi tiết">
                                                    <i class="fas fa-eye"></i> Chi tiết
                                                </a>
                                                
                                                <a href="phieu_xuat_kho.php?order_id=<?php echo $export['order_id']; ?>" 
                                                   class="btn btn-sm btn-secondary" title="In phiếu xuất" target="_blank">
                                                    <i class="fas fa-print"></i> In
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
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
                        <span>Copyright &copy; Smart Warehouse System 2025</span>
                    </div>
                </div>
            </footer>
            <!-- End of Footer -->

        </div>
        <!-- End of Content Wrapper -->

    </div>
    <!-- End of Page Wrapper -->

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
                    <h4 class="modal-title" id="exampleModalLabel">Xác nhận đăng xuất?</h4>
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
    <script src="vendor/datatables/jquery.dataTables.min.js"></script>
    <script src="vendor/datatables/dataTables.bootstrap4.min.js"></script>

    <script>
        $(document).ready(function() {
            $('#exportsTable').DataTable({
                "language": {
                    "url": "https://cdn.datatables.net/plug-ins/1.10.24/i18n/Vietnamese.json"
                },
                "pageLength": 25,
                "lengthMenu": [[10, 25, 50, 100, -1], [10, 25, 50, 100, "Tất cả"]],
                "order": [[ 6, "desc" ]], // Sort by confirmed date
                "columnDefs": [
                    { "orderable": false, "targets": [0, 8] } // Disable sorting for STT and action columns
                ],
                "drawCallback": function() {
                    // Update STT numbers after each draw
                    this.api().column(0, {search:'applied', order:'applied'}).nodes().each(function(cell, i) {
                        var pageInfo = $('#exportsTable').DataTable().page.info();
                        cell.innerHTML = pageInfo.start + i + 1;
                    });
                }
            });
        });
    </script>

</body>

</html>
