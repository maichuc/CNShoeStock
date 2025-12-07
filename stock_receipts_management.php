<?php
session_start();

// Prevent browser caching
header("Cache-Control: no-cache, no-store, must-revalidate"); // HTTP 1.1
header("Pragma: no-cache"); // HTTP 1.0
header("Expires: 0"); // Proxies

require_once 'config/database.php';
require_once 'classes/Warehouse.php';
require_once 'classes/StockReceiptHistory.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    header('Location: login.html');
    exit();
}

// Kết nối database
$database = new Database();
$pdo = $database->getConnection();
$historyManager = new StockReceiptHistory($pdo);

$currentUser = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? 'employee';
$userWarehouseId = $_SESSION['warehouse_id'] ?? null;

// Nếu không có warehouse_id trong session, lấy từ database
if (!$userWarehouseId && $currentUser) {
    try {
        $stmt = $pdo->prepare("SELECT warehouse_id FROM users WHERE user_id = ?");
        $stmt->execute([$currentUser]);
        $user = $stmt->fetch();
        if ($user && $user['warehouse_id']) {
            $userWarehouseId = $user['warehouse_id'];
            $_SESSION['warehouse_id'] = $userWarehouseId;
        }
    } catch(PDOException $e) {
        error_log("Error getting warehouse_id: " . $e->getMessage());
    }
}

// Lấy thông tin warehouse
$warehouseName = 'SB Admin 2';
if ($userWarehouseId) {
    $warehouseObj = new Warehouse($pdo);
    if ($warehouseObj->getById($userWarehouseId)) {
        $warehouseName = $warehouseObj->name;
    }
}

// Lấy thống kê phiếu nhập
$stats = [];
try {
    // Tổng số phiếu nhập
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM stock_receipts WHERE warehouse_id = ?");
    $stmt->execute([$userWarehouseId]);
    $stats['total'] = $stmt->fetchColumn();

    // Phiếu nhập hôm nay
    $stmt = $pdo->prepare("SELECT COUNT(*) as today FROM stock_receipts WHERE warehouse_id = ? AND DATE(created_at) = CURDATE()");
    $stmt->execute([$userWarehouseId]);
    $stats['today'] = $stmt->fetchColumn();

    // Bản nháp
    $stmt = $pdo->prepare("SELECT COUNT(*) as draft FROM stock_receipts WHERE warehouse_id = ? AND status = 'draft'");
    $stmt->execute([$userWarehouseId]);
    $stats['draft'] = $stmt->fetchColumn();

    // Phiếu nhập đã xác nhận
    $stmt = $pdo->prepare("SELECT COUNT(*) as confirmed FROM stock_receipts WHERE warehouse_id = ? AND status IN ('confirmed', 'completed')");
    $stmt->execute([$userWarehouseId]);
    $stats['confirmed'] = $stmt->fetchColumn();

} catch(PDOException $e) {
    error_log("Error getting stats: " . $e->getMessage());
    $stats = ['total' => 0, 'today' => 0, 'draft' => 0, 'confirmed' => 0];
}

// Lấy danh sách nhà cung cấp cho bộ lọc
$suppliers = [];
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT s.supplier_id, s.name 
        FROM suppliers s
        INNER JOIN stock_receipts sr ON s.supplier_id = sr.supplier_id
        WHERE sr.warehouse_id = ?
        ORDER BY s.name
    ");
    $stmt->execute([$userWarehouseId]);
    $suppliers = $stmt->fetchAll();
} catch(PDOException $e) {
    error_log("Error getting suppliers: " . $e->getMessage());
}

// Lấy danh sách tất cả phiếu nhập
$allReceipts = [];
try {
    $stmt = $pdo->prepare("
        SELECT sr.*, s.name as supplier_name 
        FROM stock_receipts sr 
        LEFT JOIN suppliers s ON sr.supplier_id = s.supplier_id 
        WHERE sr.warehouse_id = ? 
        ORDER BY sr.created_at DESC
    ");
    $stmt->execute([$userWarehouseId]);
    $allReceipts = $stmt->fetchAll();
} catch(PDOException $e) {
    error_log("Error getting all receipts: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="description" content="">
    <meta name="author" content="">

    <title>Quản lý phiếu nhập kho - Smart Warehouse</title>

    <!-- Custom fonts for this template-->
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">

    <!-- Custom styles for this template-->
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    
    <!-- Page level plugin CSS-->
    <link href="vendor/datatables/dataTables.bootstrap4.min.css" rel="stylesheet">
    
    <!-- Custom styles for QR management -->
    <style>
        .qr-actions {
            display: flex;
            gap: 5px;
        }
        .qr-badge {
            font-size: 10px;
            padding: 2px 6px;
        }
        .timeline-item {
            border-left-width: 3px !important;
        }
        .filter-section {
            background: #f8f9fc;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .filter-section .form-group {
            margin-bottom: 10px;
        }
        .filter-section label {
            font-size: 0.85rem;
            font-weight: 600;
            color: #5a5c69;
            margin-bottom: 5px;
        }
        .filter-section .form-control,
        .filter-section .form-control-sm {
            font-size: 0.85rem;
        }
        /* Ensure labels on white inputs are dark for better contrast */
        .form-group label {
            color: #111827;
        }
    </style>
</head>

<body id="page-top">

    <!-- Page Wrapper -->
    <div id="wrapper">

        <?php include 'includes/sidebar.php'; ?>

        <!-- Content Wrapper -->
        <div id="content-wrapper" class="d-flex flex-column">

            <!-- Main Content -->
            <div id="content">

                <?php include 'includes/topbar.php'; ?>

                <!-- Begin Page Content -->
                <div class="container-fluid">

                    <!-- Page Heading -->
                    <div class="d-sm-flex align-items-center justify-content-between mb-4">
                        <h1 class="h3 mb-0 text-gray-800">Quản lý phiếu nhập kho</h1>
                        <div class="d-flex">
                            <button class="btn btn-sm btn-info shadow-sm mr-2" onclick="qrManager.startQRScanner()">
                                <i class="fas fa-qrcode fa-sm text-white-50"></i> Scan QR Code
                            </button>
                            <a href="create_stock_receipt.php" class="btn btn-sm btn-primary shadow-sm">
                                <i class="fas fa-plus fa-sm text-white-50"></i> Tạo phiếu nhập mới
                            </a>
                        </div>
                    </div>

                    <!-- Content Row -->
                    <div class="row">

                        <!-- Tổng số phiếu nhập -->
                        <div class="col-xl-3 col-md-6 col-sm-6 mb-4">
                            <div class="card border-left-primary shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                                Tổng số phiếu nhập</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($stats['total']); ?></div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-clipboard-list fa-2x text-gray-300"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Phiếu nhập hôm nay -->
                        <div class="col-xl-3 col-md-6 col-sm-6 mb-4">
                            <div class="card border-left-success shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                                Phiếu nhập hôm nay</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($stats['today']); ?></div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-calendar fa-2x text-gray-300"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Bản nháp -->
                        <div class="col-xl-3 col-md-6 col-sm-6 mb-4">
                            <div class="card border-left-warning shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                                Bản nháp</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($stats['draft']); ?></div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-file-alt fa-2x text-gray-300"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Phiếu nhập đã xác nhận -->
                        <div class="col-xl-3 col-md-6 col-sm-6 mb-4">
                            <div class="card border-left-success shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                                Đã xác nhận</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($stats['confirmed']); ?></div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-check-circle fa-2x text-gray-300"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>

                    <!-- Content Row -->
                    <div class="row">

                        <!-- Danh sách tất cả phiếu nhập -->
                        <div class="col-lg-8">
                            <div class="card shadow mb-4">
                                <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                                    <h6 class="m-0 font-weight-bold text-primary">Tất cả phiếu nhập</h6>
                                    <div class="dropdown no-arrow">
                                        <a class="dropdown-toggle" href="#" role="button" id="dropdownMenuLink"
                                            data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                            <i class="fas fa-ellipsis-v fa-sm fa-fw text-gray-400"></i>
                                        </a>
                                        <div class="dropdown-menu dropdown-menu-right shadow animated--fade-in"
                                            aria-labelledby="dropdownMenuLink">
                                            <div class="dropdown-header">Tùy chọn:</div>
                                            <a class="dropdown-item" href="#">Xuất Excel</a>
                                            <a class="dropdown-item" href="#">Xuất PDF</a>
                                        </div>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <!-- Bộ lọc -->
                                    <div class="filter-section">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <h6 class="m-0 font-weight-bold text-primary">
                                                <i class="fas fa-filter"></i> Bộ lọc
                                            </h6>
                                            <button class="btn btn-sm btn-secondary" id="resetFilters">
                                                <i class="fas fa-redo"></i> Đặt lại
                                            </button>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-3">
                                                <div class="form-group">
                                                    <label for="filterStatus">Trạng thái</label>
                                                    <select class="form-control form-control-sm" id="filterStatus">
                                                        <option value="">Tất cả</option>
                                                        <option value="draft">Bản nháp</option>
                                                        <option value="confirmed">Đã xác nhận</option>
                                                        <option value="completed">Hoàn thành</option>
                                                        <option value="rejected">Đã từ chối</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="form-group">
                                                    <label for="filterSupplier">Nhà cung cấp</label>
                                                    <select class="form-control form-control-sm" id="filterSupplier">
                                                        <option value="">Tất cả</option>
                                                        <?php foreach ($suppliers as $supplier): ?>
                                                        <option value="<?php echo htmlspecialchars($supplier['name']); ?>">
                                                            <?php echo htmlspecialchars($supplier['name']); ?>
                                                        </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="form-group">
                                                    <label for="filterDateFrom">Từ ngày</label>
                                                    <input type="date" class="form-control form-control-sm" id="filterDateFrom">
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="form-group">
                                                    <label for="filterDateTo">Đến ngày</label>
                                                    <input type="date" class="form-control form-control-sm" id="filterDateTo">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="table-responsive">
                                        <table class="table table-bordered" id="dataTable" width="100%" cellspacing="0">
                                            <thead>
                                                <tr>
                                                    <th>STT</th>
                                                    <th>Mã phiếu</th>
                                                    <th>Nhà cung cấp</th>
                                                    <th>Trạng thái</th>
                                                    <th>Ngày tạo</th>
                                                    <th>Hành động</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (empty($allReceipts)): ?>
                                                <tr>
                                                    <td colspan="6" class="text-center">Chưa có phiếu nhập nào</td>
                                                </tr>
                                                <?php else: ?>
                                                    <?php foreach ($allReceipts as $index => $receipt): ?>
                                                    <tr>
                                                        <td class="text-center"><?php echo $index + 1; ?></td>
                                                        <td>#<?php echo str_pad($receipt['receipt_id'], 6, '0', STR_PAD_LEFT); ?></td>
                                                        <td><?php echo htmlspecialchars($receipt['supplier_name'] ?? 'N/A'); ?></td>
                                                        <td>
                                                            <?php if ($receipt['status'] == 'draft'): ?>
                                                                <span class="badge badge-warning">Bản nháp</span>
                                                            <?php elseif ($receipt['status'] == 'confirmed'): ?>
                                                                <span class="badge badge-success">Đã xác nhận</span>
                                                            <?php elseif ($receipt['status'] == 'completed'): ?>
                                                                <span class="badge badge-success">Hoàn thành</span>
                                                            <?php elseif ($receipt['status'] == 'rejected'): ?>
                                                                <span class="badge badge-danger">Đã từ chối</span>
                                                            <?php else: ?>
                                                                <span class="badge badge-secondary"><?php echo ucfirst($receipt['status']); ?></span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><?php echo date('d/m/Y H:i', strtotime($receipt['created_at'])); ?></td>
                                                        <td>
                                                            <div class="qr-actions">
                                                                <button class="btn btn-sm btn-info" onclick="viewReceiptDetails(<?php echo $receipt['receipt_id']; ?>)" title="Xem chi tiết">
                                                                    <i class="fas fa-eye"></i>
                                                                </button>
                                                                
                                                <?php if ($receipt['status'] == 'draft'): ?>
                                                <button class="btn btn-sm btn-warning" onclick="editReceipt(<?php echo $receipt['receipt_id']; ?>)" title="Chỉnh sửa">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-sm btn-success" onclick="confirmReceipt(<?php echo $receipt['receipt_id']; ?>)" title="Xác nhận">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                                <button class="btn btn-sm btn-danger" onclick="deleteReceipt(<?php echo $receipt['receipt_id']; ?>)" title="Xóa">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                                <?php endif; ?>                                                                <?php if ($receipt['status'] == 'completed'): ?>
                                                                <button class="btn btn-sm btn-success" onclick="confirmReceipt(<?php echo $receipt['receipt_id']; ?>)" title="Xác nhận vào kho">
                                                                    <i class="fas fa-check"></i>
                                                                </button>
                                                                <?php endif; ?>
                                                                
                                                                <?php if ($receipt['status'] == 'confirmed'): ?>
                                                                <button class="btn btn-sm btn-warning" onclick="viewReceiptQRs(<?php echo $receipt['receipt_id']; ?>)" title="QR Codes">
                                                                    <i class="fas fa-qrcode"></i>
                                                                </button>
                                                                <?php endif; ?>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Biểu đồ thống kê -->
                        <div class="col-lg-4">
                            <div class="card shadow mb-4">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">Thống kê theo trạng thái</h6>
                                </div>
                                <div class="card-body">
                                    <div class="chart-pie pt-4 pb-2">
                                        <canvas id="myPieChart"></canvas>
                                    </div>
                                    <div class="mt-4 text-center small">
                                        <span class="mr-2">
                                            <i class="fas fa-circle text-warning"></i> Bản nháp
                                        </span>
                                        <span class="mr-2">
                                            <i class="fas fa-circle text-success"></i> Đã xác nhận
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>

                </div>
                <!-- /.container-fluid -->

            </div>
            <!-- End of Main Content -->

            <?php include 'includes/footer.php'; ?>

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
                    <h5 class="modal-title" id="exampleModalLabel">Sẵn sàng để rời đi?</h5>
                    <button class="close" type="button" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">×</span>
                    </button>
                </div>
                <div class="modal-body">Chọn "Đăng xuất" bên dưới nếu bạn sẵn sàng kết thúc phiên làm việc hiện tại.</div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" type="button" data-dismiss="modal">Hủy</button>
                    <a class="btn btn-primary" href="logout.php">Đăng xuất</a>
                </div>
            </div>
        </div>
    </div>

    <!-- SweetAlert2 for beautiful alerts -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
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

    <!-- QR Code Management Scripts -->
    <script src="js/qr-code-manager.js"></script>

    <!-- Page level plugins -->
    <script src="vendor/chart.js/Chart.min.js"></script>

    <!-- Page level custom scripts -->
    <script>
    $(document).ready(function() {
        // Initialize DataTable
        var table = $('#dataTable').DataTable({
            "language": {
                "url": "https://cdn.datatables.net/plug-ins/1.10.24/i18n/Vietnamese.json"
            },
            "pageLength": 20,
            "lengthMenu": [[20, 50, 100, -1], [20, 50, 100, "Tất cả"]],
            "order": [[ 4, "desc" ]], // Sort by created date (adjusted for STT column)
            "columnDefs": [
                { "orderable": false, "targets": [0, 5] } // Disable sorting for STT and action column
            ],
            "drawCallback": function() {
                // Update STT numbers after each draw (sort, filter, paginate)
                this.api().column(0, {search:'applied', order:'applied'}).nodes().each(function(cell, i) {
                    var pageInfo = table.page.info();
                    cell.innerHTML = pageInfo.start + i + 1;
                });
            }
        });

        // Custom filtering function
        $.fn.dataTable.ext.search.push(
            function(settings, data, dataIndex) {
                var filterStatus = $('#filterStatus').val();
                var filterSupplier = $('#filterSupplier').val();
                var filterDateFrom = $('#filterDateFrom').val();
                var filterDateTo = $('#filterDateTo').val();
                
                // Get data from table columns
                var supplier = data[2] || ''; // Column index 2: Nhà cung cấp
                var statusHtml = data[3] || ''; // Column index 3: Trạng thái
                var dateStr = data[4] || ''; // Column index 4: Ngày tạo
                
                // Extract status from badge HTML
                var status = '';
                if (statusHtml.includes('Bản nháp')) status = 'draft';
                else if (statusHtml.includes('Đã xác nhận')) status = 'confirmed';
                else if (statusHtml.includes('Hoàn thành')) status = 'completed';
                else if (statusHtml.includes('Đã từ chối')) status = 'rejected';
                
                // Parse date from format "dd/mm/yyyy HH:ii"
                var dateParts = dateStr.split(' ')[0].split('/');
                var rowDate = null;
                if (dateParts.length === 3) {
                    // Convert dd/mm/yyyy to yyyy-mm-dd for comparison
                    rowDate = dateParts[2] + '-' + dateParts[1].padStart(2, '0') + '-' + dateParts[0].padStart(2, '0');
                }
                
                // Check status filter
                if (filterStatus && status !== filterStatus) {
                    return false;
                }
                
                // Check supplier filter
                if (filterSupplier && supplier !== filterSupplier) {
                    return false;
                }
                
                // Check date range filter
                if (filterDateFrom && rowDate && rowDate < filterDateFrom) {
                    return false;
                }
                
                if (filterDateTo && rowDate && rowDate > filterDateTo) {
                    return false;
                }
                
                return true;
            }
        );

        // Event listeners for filters
        $('#filterStatus, #filterSupplier, #filterDateFrom, #filterDateTo').on('change keyup', function() {
            table.draw();
        });

        // Reset filters button
        $('#resetFilters').on('click', function() {
            $('#filterStatus').val('');
            $('#filterSupplier').val('');
            $('#filterDateFrom').val('');
            $('#filterDateTo').val('');
            table.draw();
        });
    });
    
    // Kiểm tra xem có cần quay lại modal chỉnh sửa không (sau khi thêm sản phẩm)
    $(document).ready(function() {
        const returnToEdit = localStorage.getItem('return_to_edit');
        const editReceiptId = localStorage.getItem('edit_receipt_id');
        
        if (returnToEdit === 'true' && editReceiptId) {
            // Xóa flag
            localStorage.removeItem('return_to_edit');
            localStorage.removeItem('edit_receipt_id');
            
            // Hiển thị thông báo
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'success',
                    title: 'Đã thêm sản phẩm!',
                    text: 'Sản phẩm đã được thêm vào phiếu nhập. Đang mở lại modal chỉnh sửa...',
                    timer: 2000,
                    showConfirmButton: false
                });
            }
            
            // Đợi 1 giây rồi mở lại modal
            setTimeout(function() {
                editReceipt(parseInt(editReceiptId));
            }, 1000);
        }
    });
    
    // Biểu đồ pie chart
    Chart.defaults.global.defaultFontFamily = 'Nunito', '-apple-system,system-ui,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif';
    Chart.defaults.global.defaultFontColor = '#858796';

    var ctx = document.getElementById("myPieChart");
    var myPieChart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ["Bản nháp", "Đã xác nhận"],
            datasets: [{
                data: [<?php echo $stats['draft']; ?>, <?php echo $stats['confirmed']; ?>],
                backgroundColor: ['#f6c23e', '#1cc88a'],
                hoverBackgroundColor: ['#f4b619', '#17a673'],
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

    // Hàm xác nhận phiếu nhập
    function confirmReceipt(receiptId) {
        if (confirm('Bạn có chắc chắn muốn xác nhận phiếu nhập này? Sau khi xác nhận sẽ không thể chỉnh sửa.')) {
            // Disable button và hiển thị loading
            const btn = event.target;
            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Đang xử lý...';
            
            console.log('Confirming receipt ID:', receiptId);
            
            $.ajax({
                url: 'api_stock_receipt_management.php?v=' + Date.now(),
                method: 'POST',
                dataType: 'json',
                data: JSON.stringify({
                    action: 'confirm_receipt',
                    receipt_id: receiptId
                }),
                contentType: 'application/json',
                cache: false,
                success: function(response) {
                    console.log('Success response:', response);
                    if (response.success) {
                        alert('Đã xác nhận phiếu nhập thành công! Mã phiếu: ' + response.receipt_code);
                        location.reload();
                    } else {
                        alert('Lỗi: ' + response.message);
                        btn.disabled = false;
                        btn.innerHTML = originalText;
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', {
                        status: status,
                        error: error,
                        responseText: xhr.responseText,
                        status_code: xhr.status
                    });
                    
                    // Try to parse error response
                    try {
                        const errorResponse = JSON.parse(xhr.responseText);
                        alert('Lỗi: ' + errorResponse.message);
                    } catch (e) {
                        alert('Có lỗi xảy ra khi xác nhận phiếu: ' + error + ' (Status: ' + xhr.status + ')');
                    }
                    
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                }
            });
        }
    }

    // Hàm chỉnh sửa phiếu nhập
    function editReceipt(receiptId) {
        // Chuyển hướng đến trang tạo phiếu nhập với receipt_id để chỉnh sửa
        window.location.href = 'create_stock_receipt.php?edit=' + receiptId;
    }

    // Hàm xóa phiếu nhập
    function deleteReceipt(receiptId) {
        if (confirm('Bạn có chắc chắn muốn xóa phiếu nhập này?')) {
            $.ajax({
                url: 'api_stock_receipt_management.php',
                type: 'POST',
                data: {
                    action: 'delete',
                    receipt_id: receiptId
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        alert('Đã xóa phiếu nhập thành công!');
                        location.reload();
                    } else {
                        alert('Lỗi: ' + response.message);
                    }
                },
                error: function() {
                    alert('Có lỗi xảy ra khi xóa phiếu nhập');
                }
            });
        }
    }

    // Hàm chỉnh sửa phiếu nhập (draft)
    function editReceipt(receiptId) {
        // Tạo modal động
        const modalHtml = `
            <div class="modal fade" id="editReceiptModal" tabindex="-1" role="dialog">
                <div class="modal-dialog modal-lg" role="document">
                    <div class="modal-content">
                        <div class="modal-header bg-warning text-white">
                            <h5 class="modal-title"><i class="fas fa-edit"></i> Chỉnh sửa phiếu nhập #<span id="editReceiptNumber"></span></h5>
                            <button type="button" class="close text-white" data-dismiss="modal">
                                <span>&times;</span>
                            </button>
                        </div>
                        <div class="modal-body" id="editReceiptContent">
                            <div class="text-center">
                                <i class="fas fa-spinner fa-spin fa-2x mb-3"></i>
                                <p>Đang tải dữ liệu...</p>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Hủy</button>
                            <button type="button" class="btn btn-primary" onclick="saveReceiptChanges()"><i class="fas fa-save"></i> Lưu thay đổi</button>
                        </div>
                    </div>
                </div>
            </div>
        `;

        // Xóa modal cũ nếu có
        $('#editReceiptModal').remove();
        // Thêm modal mới
        $('body').append(modalHtml);
        $('#editReceiptModal').modal('show');

        // Load dữ liệu từ API
        $.ajax({
            url: 'api_stock_receipt_simple.php',
            method: 'POST',
            dataType: 'json',
            data: JSON.stringify({
                action: 'get_receipt_details',
                receipt_id: receiptId
            }),
            contentType: 'application/json',
            success: function(response) {
                if (response.success) {
                    const receipt = response.receipt;
                    const items = response.items;
                    displayEditForm(receipt, items);
                } else {
                    $('#editReceiptContent').html('<div class="alert alert-danger">' + response.message + '</div>');
                }
            },
            error: function() {
                $('#editReceiptContent').html('<div class="alert alert-danger">Có lỗi xảy ra khi tải dữ liệu</div>');
            }
        });
    }

    // Hiển thị form chỉnh sửa
    function displayEditForm(receipt, items) {
        $('#editReceiptNumber').text(String(receipt.receipt_id).padStart(6, '0'));
        
        let html = `
            <input type="hidden" id="edit_receipt_id" value="${receipt.receipt_id}">
            
            <div class="form-group">
                <label>Nhà cung cấp</label>
                <select class="form-control" id="edit_supplier_id">
                    <option value="">Đang tải...</option>
                </select>
            </div>
            
            <div class="form-group">
                <label>Ghi chú</label>
                <textarea class="form-control" id="edit_notes" rows="3">${receipt.notes || ''}</textarea>
            </div>
            
            <hr>
            <h6><i class="fas fa-box"></i> Danh sách sản phẩm</h6>
            <div class="table-responsive">
                <table class="table table-bordered table-sm">
                    <thead class="thead-light">
                        <tr>
                            <th>Sản phẩm</th>
                            <th>Size</th>
                            <th width="100">Số lượng</th>
                            <th width="120">Đơn giá</th>
                            <th width="100">Vị trí</th>
                            <th width="80">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody id="edit_items_table">
                    </tbody>
                </table>
            </div>
            <button type="button" class="btn btn-sm btn-success" onclick="showAddProductModal()"><i class="fas fa-plus"></i> Thêm sản phẩm nhập</button>
        `;
        
        $('#editReceiptContent').html(html);
        
        // Lưu receipt_id vào data attribute của modal để dùng sau
        $('#editReceiptModal').data('receipt-id', receipt.receipt_id);
        
        // Load danh sách nhà cung cấp
        loadSuppliersForEdit(receipt.supplier_id);
        
        // Hiển thị items hiện tại
        console.log('Items data:', items); // Debug log
        items.forEach(item => {
            console.log('Processing item:', item); // Debug log
            addItemRow(item);
        });
    }

    // Load danh sách nhà cung cấp
    function loadSuppliersForEdit(selectedId) {
        $.ajax({
            url: 'api_stock_receipt_simple.php',
            method: 'POST',
            dataType: 'json',
            data: JSON.stringify({
                action: 'get_suppliers'
            }),
            contentType: 'application/json',
            success: function(response) {
                if (response.success) {
                    let options = '<option value="">-- Chọn nhà cung cấp --</option>';
                    response.suppliers.forEach(supplier => {
                        const selected = supplier.supplier_id == selectedId ? 'selected' : '';
                        options += `<option value="${supplier.supplier_id}" ${selected}>${supplier.name}</option>`;
                    });
                    $('#edit_supplier_id').html(options);
                }
            }
        });
    }

    // Thêm dòng sản phẩm vào bảng chỉnh sửa
    function addItemRow(item = null) {
        const rowId = 'item_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
        
        // Lấy số lượng từ các field có thể có
        let quantity = 1;
        if (item) {
            quantity = item.quantity || item.quantity_expected || item.quantity_received || 1;
        }
        
        // Lấy giá từ các field có thể có
        let unitPrice = 0;
        if (item) {
            unitPrice = item.unit_price || item.current_price || 0;
        }
        
        // Helper function để escape HTML attributes
        function escapeHtml(text) {
            if (!text) return '';
            return String(text)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }
        
        // Tạo row bằng jQuery để tránh lỗi escape
        const $row = $('<tr>', { id: rowId });
        
        // Cột sản phẩm
        const $productCol = $('<td>');
        $productCol.append($('<input>', {
            type: 'hidden',
            class: 'item-variant-id',
            value: item ? item.variant_id : ''
        }));
        $productCol.append($('<input>', {
            type: 'text',
            class: 'form-control form-control-sm',
            value: item ? (item.product_name || '') : '',
            readonly: true
        }));
        $productCol.append($('<small>', {
            class: 'text-muted',
            text: item ? ('SKU: ' + (item.sku || '')) : ''
        }));
        $row.append($productCol);
        
        // Cột size
        $row.append($('<td>').append($('<input>', {
            type: 'text',
            class: 'form-control form-control-sm',
            value: item ? (item.size || '') : '',
            readonly: true
        })));
        
        // Cột số lượng
        $row.append($('<td>').append($('<input>', {
            type: 'number',
            class: 'form-control form-control-sm item-quantity',
            value: quantity,
            min: 1
        })));
        
        // Cột đơn giá
        $row.append($('<td>').append($('<input>', {
            type: 'number',
            class: 'form-control form-control-sm item-price',
            value: unitPrice,
            min: 0,
            step: 0.01
        })));
        
        // Cột vị trí
        $row.append($('<td>').append($('<input>', {
            type: 'text',
            class: 'form-control form-control-sm item-location',
            value: item ? (item.location_code || '') : '',
            placeholder: 'A-01-01'
        })));
        
        // Cột thao tác
        const $actionCol = $('<td>', { class: 'text-center' });
        const $deleteBtn = $('<button>', {
            type: 'button',
            class: 'btn btn-sm btn-danger',
            title: 'Xóa',
            onclick: `removeItemRow('${rowId}')`
        });
        $deleteBtn.append($('<i>', { class: 'fas fa-trash' }));
        $actionCol.append($deleteBtn);
        $row.append($actionCol);
        
        $('#edit_items_table').append($row);
        console.log('✅ Added row:', rowId, 'for variant:', item ? item.variant_id : 'new');
    }

    // Xóa dòng sản phẩm
    function removeItemRow(rowId) {
        $('#' + rowId).remove();
    }

    // Hiển thị modal chọn hình thức thêm sản phẩm
    function showAddProductModal() {
        const receiptId = $('#editReceiptModal').data('receipt-id');
        
        const modalHtml = `
            <div class="modal fade" id="addProductChoiceModal" tabindex="-1" role="dialog">
                <div class="modal-dialog modal-dialog-centered" role="document">
                    <div class="modal-content">
                        <div class="modal-header bg-success text-white">
                            <h5 class="modal-title"><i class="fas fa-plus-circle"></i> Chọn hình thức thêm sản phẩm</h5>
                            <button type="button" class="close text-white" data-dismiss="modal">
                                <span>&times;</span>
                            </button>
                        </div>
                        <div class="modal-body">
                            <p class="text-muted mb-3">Vui lòng chọn hình thức nhập sản phẩm vào phiếu nhập #${String(receiptId).padStart(6, '0')}:</p>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <button type="button" class="btn btn-lg btn-outline-primary btn-block py-4" onclick="goToAIImport(${receiptId})">
                                        <i class="fas fa-robot fa-3x mb-2"></i>
                                        <h5>Nhập kho AI</h5>
                                        <small>Scan ảnh & nhận diện tự động</small>
                                    </button>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <button type="button" class="btn btn-lg btn-outline-success btn-block py-4" onclick="goToManualImport(${receiptId})">
                                        <i class="fas fa-keyboard fa-3x mb-2"></i>
                                        <h5>Nhập thủ công</h5>
                                        <small>Nhập thông tin thủ công</small>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Hủy</button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        // Xóa modal cũ nếu có
        $('#addProductChoiceModal').remove();
        // Thêm modal mới
        $('body').append(modalHtml);
        $('#addProductChoiceModal').modal('show');
    }
    
    // Điều hướng đến trang nhập kho AI
    function goToAIImport(receiptId) {
        // Thu thập các vị trí đã được sử dụng trong phiếu nhập hiện tại
        const usedLocations = [];
        $('#edit_items_table tr').each(function() {
            const locationCode = $(this).find('.item-location').val();
            if (locationCode && locationCode.trim() !== '') {
                usedLocations.push(locationCode.trim());
            }
        });
        
        console.log('Used locations in receipt:', usedLocations);
        
        // Lưu thông tin vào localStorage
        localStorage.setItem('edit_receipt_id', receiptId);
        localStorage.setItem('return_to_edit', 'true');
        localStorage.setItem('excluded_locations', JSON.stringify(usedLocations));
        
        // Chuyển đến trang nhập kho AI với tham số
        window.location.href = `create_new_stock_receipt.php?from_receipt=${receiptId}&mode=add`;
    }
    
    // Điều hướng đến trang nhập kho thủ công
    function goToManualImport(receiptId) {
        // Thu thập các vị trí đã được sử dụng trong phiếu nhập hiện tại
        const usedLocations = [];
        $('#edit_items_table tr').each(function() {
            const locationCode = $(this).find('.item-location').val();
            if (locationCode && locationCode.trim() !== '') {
                usedLocations.push(locationCode.trim());
            }
        });
        
        console.log('Used locations in receipt:', usedLocations);
        
        // Lưu thông tin vào localStorage
        localStorage.setItem('edit_receipt_id', receiptId);
        localStorage.setItem('return_to_edit', 'true');
        localStorage.setItem('excluded_locations', JSON.stringify(usedLocations));
        
        // Chuyển đến trang nhập kho thủ công với tham số
        window.location.href = `create_manual_stock_receipt.php?from_receipt=${receiptId}&mode=add`;
    }

    // Lưu thay đổi
    function saveReceiptChanges() {
        const receiptId = $('#edit_receipt_id').val();
        const supplierId = $('#edit_supplier_id').val();
        const notes = $('#edit_notes').val();
        
        console.log('Receipt ID:', receiptId);
        console.log('Supplier ID:', supplierId);
        console.log('Notes:', notes);
        
        if (!supplierId) {
            alert('Vui lòng chọn nhà cung cấp');
            return;
        }
        
        // Lấy danh sách items
        const items = [];
        $('#edit_items_table tr').each(function() {
            const variantId = $(this).find('.item-variant-id').val();
            const quantity = $(this).find('.item-quantity').val();
            const unitPrice = $(this).find('.item-price').val();
            const locationCode = $(this).find('.item-location').val();
            
            if (variantId && quantity) {
                items.push({
                    variant_id: variantId,
                    quantity: parseInt(quantity),
                    unit_price: parseFloat(unitPrice) || 0,
                    location_code: locationCode || null
                });
            }
        });
        
        console.log('Items to save:', items);
        
        if (items.length === 0) {
            alert('Phiếu nhập phải có ít nhất 1 sản phẩm');
            return;
        }
        
        // Chuẩn bị data để gửi dưới dạng form data với items là JSON string
        const postData = {
            action: 'update_draft',
            receipt_id: receiptId,
            supplier_id: supplierId,
            notes: notes,
            items: JSON.stringify(items) // Chuyển items thành JSON string
        };
        
        console.log('Sending data:', postData);
        
        // Gửi request cập nhật
        $.ajax({
            url: 'api_stock_receipt_management.php',
            type: 'POST',
            data: postData,
            dataType: 'json',
            beforeSend: function() {
                console.log('Sending AJAX request...');
            },
            success: function(response) {
                console.log('Response received:', response);
                if (response.success) {
                    // Kiểm tra xem Swal có tồn tại không
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            icon: 'success',
                            title: 'Thành công!',
                            text: 'Đã cập nhật phiếu nhập',
                            timer: 1500,
                            showConfirmButton: false
                        }).then(() => {
                            $('#editReceiptModal').modal('hide');
                            location.reload();
                        });
                    } else {
                        alert('Đã cập nhật phiếu nhập thành công!');
                        $('#editReceiptModal').modal('hide');
                        location.reload();
                    }
                } else {
                    alert('Lỗi: ' + response.message);
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', {
                    status: status,
                    error: error,
                    response: xhr.responseText
                });
                alert('Có lỗi xảy ra khi lưu thay đổi: ' + error + '\nChi tiết: ' + xhr.responseText);
            }
        });
    }

    // Hàm xem chi tiết phiếu nhập
    function viewReceiptDetails(receiptId) {
        // Tạo modal động
        const modalHtml = `
            <div class="modal fade" id="receiptModal" tabindex="-1" role="dialog">
                <div class="modal-dialog modal-lg" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Chi tiết phiếu nhập</h5>
                            <button type="button" class="close" data-dismiss="modal">
                                <span>&times;</span>
                            </button>
                        </div>
                        <div class="modal-body" id="receiptDetailsContent">
                            <div class="text-center">
                                <i class="fas fa-spinner fa-spin fa-2x mb-3"></i>
                                <p>Đang tải dữ liệu...</p>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Đóng</button>
                        </div>
                    </div>
                </div>
            </div>
        `;

        // Xóa modal cũ nếu có
        $('#receiptModal').remove();
        // Thêm modal mới
        $('body').append(modalHtml);
        $('#receiptModal').modal('show');

        // Load dữ liệu từ API simple
        $.ajax({
            url: 'api_stock_receipt_simple.php',
            method: 'POST',
            dataType: 'json',
            data: JSON.stringify({
                action: 'get_receipt_details',
                receipt_id: receiptId
            }),
            contentType: 'application/json',
            success: function(response) {
                if (response.success) {
                    const receipt = response.receipt;
                    const items = response.items;
                    // Kiểm tra nếu phiếu đã xác nhận thì load thêm QR codes
                    if (receipt.status === 'confirmed') {
                        loadReceiptWithQR(receipt, items, receiptId);
                    } else {
                        // Phiếu tạm chỉ hiển thị thông tin sản phẩm
                        displayReceiptDetails(receipt, items, null);
                    }
                } else {
                    $('#receiptDetailsContent').html('<div class="alert alert-danger">' + response.message + '</div>');
                }
            },
            error: function() {
                $('#receiptDetailsContent').html('<div class="alert alert-danger">Có lỗi xảy ra khi tải dữ liệu</div>');
            }
        });
    }

    // Hàm load phiếu đã xác nhận với QR codes
    function loadReceiptWithQR(receipt, items, receiptId) {
        $.ajax({
            url: 'api_stock_receipt_simple.php',
            method: 'POST',
            dataType: 'json',
            data: JSON.stringify({
                action: 'get_receipt_qrs',
                receipt_id: receiptId
            }),
            contentType: 'application/json',
            success: function(qrResponse) {
                console.log('QR Response:', qrResponse); // Debug log
                console.log('QR Items count:', qrResponse.qr_items ? qrResponse.qr_items.length : 0);
                console.log('Receipt status:', receipt.status);
                
                if (qrResponse.success && qrResponse.qr_items && qrResponse.qr_items.length > 0) {
                    console.log('Displaying receipt with QR codes');
                    displayReceiptDetails(receipt, items, qrResponse.qr_items);
                } else {
                    // Nếu không load được QR thì vẫn hiển thị thông tin cơ bản
                    console.log('No QR items found, displaying without QR');
                    displayReceiptDetails(receipt, items, null);
                }
            },
            error: function() {
                // Nếu có lỗi thì vẫn hiển thị thông tin cơ bản
                displayReceiptDetails(receipt, items, null);
            }
        });
    }

    // Hàm xem lịch sử thay đổi
    function viewReceiptHistory(receiptId) {
        const modalHtml = `
            <div class="modal fade" id="historyModal" tabindex="-1" role="dialog">
                <div class="modal-dialog modal-lg" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Lịch sử thay đổi</h5>
                            <button type="button" class="close" data-dismiss="modal">
                                <span>&times;</span>
                            </button>
                        </div>
                        <div class="modal-body" id="historyContent">
                            <div class="text-center">
                                <i class="fas fa-spinner fa-spin fa-2x mb-3"></i>
                                <p>Đang tải lịch sử...</p>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Đóng</button>
                        </div>
                    </div>
                </div>
            </div>
        `;

        $('#historyModal').remove();
        $('body').append(modalHtml);
        $('#historyModal').modal('show');

        $.ajax({
            url: 'api_stock_receipt_management.php',
            method: 'POST',
            dataType: 'json',
            data: JSON.stringify({
                action: 'get_history',
                receipt_id: receiptId
            }),
            contentType: 'application/json',
            success: function(response) {
                if (response.success) {
                    displayHistory(response.history);
                } else {
                    $('#historyContent').html('<div class="alert alert-danger">' + response.message + '</div>');
                }
            },
            error: function() {
                $('#historyContent').html('<div class="alert alert-danger">Có lỗi xảy ra khi tải lịch sử</div>');
            }
        });
    }

    function displayReceiptDetails(receipt, items, qrData = null) {
        // Helper to safely format receipt code
        function formatReceiptCode(r) {
            if (r.receipt_code) return r.receipt_code;
            if (r.receipt_id) return 'RC-' + String(r.receipt_id).padStart(6, '0');
            return 'Chưa có';
        }

        // Tạo map QR data theo variant_id để dễ lookup
        const qrMap = {};
        if (qrData && Array.isArray(qrData)) {
            console.log('Creating QR map from qrData:', qrData);
            qrData.forEach(qr => {
                console.log('Processing QR item:', qr);
                // Key theo SKU trước, sau đó variant_id
                if (qr.sku) {
                    qrMap[qr.sku] = qr;
                    console.log(`Added to qrMap by SKU[${qr.sku}]:`, qr);
                }
                // Cũng lưu theo variant_id nếu có
                if (qr.variant_id) {
                    qrMap[qr.variant_id] = qr;
                    console.log(`Added to qrMap by variant_id[${qr.variant_id}]:`, qr);
                }
            });
        }
        console.log('Final QR Map:', qrMap);

        let html = `
            <div class="row">
                <div class="col-md-6">
                    <h6>Thông tin phiếu</h6>
                    <table class="table table-sm">
                        <tr><td><strong>Mã phiếu:</strong></td><td>${formatReceiptCode(receipt)}</td></tr>
                        <tr><td><strong>Trạng thái:</strong></td><td><span class="badge badge-${getStatusColor(receipt.status)}">${getStatusLabel(receipt.status)}</span></td></tr>
                        <tr><td><strong>Ngày tạo:</strong></td><td>${receipt.created_at ? new Date(receipt.created_at).toLocaleString('vi-VN') : 'N/A'}</td></tr>
                        <tr><td><strong>Người tạo:</strong></td><td>${receipt.created_by_full || receipt.created_by_name || receipt.created_by || receipt.username || 'N/A'}</td></tr>
                        ${receipt.notes ? `<tr><td><strong>Ghi chú:</strong></td><td>${receipt.notes}</td></tr>` : ''}
                    </table>
                </div>
                <div class="col-md-6">
                    <!-- Additional info placeholder -->
                </div>
            </div>
            <hr>
            <h6>Danh sách sản phẩm</h6>
        `;

        if (items && items.length > 0) {
            html += `
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Sản phẩm</th>
                                <th>Biến thể</th>
                                <th class="text-center">Số lượng</th>
                                <th class="text-right">Giá nhập</th>
                                <th class="text-right">Giá bán</th>
                                <th class="text-right">Thành tiền</th>
                                <th class="text-center">QR Code</th>
                            </tr>
                        </thead>
                        <tbody>
            `;

            let total = 0;
            items.forEach(item => {
                // Fallbacks for quantity and price
                const quantity = parseFloat(item.quantity || item.quantity_received || item.quantity_expected || item.qty || 0) || 0;
                const unitPrice = parseFloat(item.unit_price || item.price || item.original_price || 0) || 0;
                const salePrice = parseFloat(item.current_price || 0) || 0;
                const itemTotal = quantity * unitPrice;
                total += itemTotal;

                // Variant display (color - size or variant_name)
                let variantDisplay = item.variant_name || '';
                if (!variantDisplay) {
                    const color = item.color || item.variant_color || '';
                    const size = item.size || item.variant_size || '';
                    variantDisplay = [color, size].filter(Boolean).join(' - ') || '-';
                }

                // QR info - kiểm tra trong qrMap bằng SKU trước, sau đó variant_id
                let qrInfo = '<span class="text-muted">Chưa có QR</span>';
                let qrItem = null;
                
                // Thử tìm theo SKU trước
                if (item.sku && qrMap[item.sku]) {
                    qrItem = qrMap[item.sku];
                    console.log(`Found QR by SKU ${item.sku}:`, qrItem);
                }
                // Nếu không có, thử tìm theo variant_id
                else if (item.variant_id && qrMap[item.variant_id]) {
                    qrItem = qrMap[item.variant_id];
                    console.log(`Found QR by variant_id ${item.variant_id}:`, qrItem);
                }
                
                console.log(`Looking for QR for item SKU: ${item.sku}, variant_id: ${item.variant_id}`, 'Found:', qrItem);
                
                if (qrItem && qrItem.qr_url) {
                    // Just show download button instead of image to avoid 404 errors
                    qrInfo = `<div class='text-center'>
                        <button class="btn btn-sm btn-outline-primary" onclick="downloadQR('${qrItem.qr_url}', '${qrItem.sku}')" title="Tải QR Code">
                            <i class="fas fa-download"></i>
                        </button>
                    </div>`;
                    console.log(`QR download button set for ${item.sku}`);
                }

                html += `
                    <tr>
                        <td>${item.product_name || item.name || 'N/A'}</td>
                        <td>${variantDisplay}</td>
                        <td class="text-center">${quantity}</td>
                        <td class="text-right">${new Intl.NumberFormat('vi-VN').format(unitPrice)} VNĐ</td>
                        <td class="text-right">${new Intl.NumberFormat('vi-VN').format(salePrice)} VNĐ</td>
                        <td class="text-right">${new Intl.NumberFormat('vi-VN').format(itemTotal)} VNĐ</td>
                        <td class="text-center">${qrInfo}</td>
                    </tr>
                `;
            });

            html += `
                        </tbody>
                        <tfoot>
                            <tr class="font-weight-bold">
                                <td colspan="5">Tổng cộng:</td>
                                <td class="text-right">${new Intl.NumberFormat('vi-VN').format(total)} VNĐ</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            `;
        } else {
            html += '<p class="text-muted">Chưa có sản phẩm nào</p>';
        }

        // Hiển thị QR codes nếu phiếu đã xác nhận và có dữ liệu QR
        console.log('Checking QR display conditions:', {
            'qrData exists': !!qrData,
            'qrData length': qrData ? qrData.length : 0,
            'receipt status': receipt.status
        });
        
        if (qrData && qrData.length > 0 && receipt.status === 'confirmed') {
            console.log('Displaying QR section');
            html += `
                <hr>
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="mb-0">Mã QR sản phẩm</h6>
                    <small class="text-muted">Phiếu đã xác nhận - Có thể quét mã QR</small>
                </div>
                <div class="row">
            `;
            
            qrData.forEach(qr => {
                console.log('Processing QR Item:', qr);
                let qrUrl = qr.qr_url || qr.qr_url_full || qr.qr_image_path || '';
                
                // Fix QR URL path for local files
                if (qrUrl && !qrUrl.startsWith('http')) {
                    qrUrl = window.location.origin + window.location.pathname.replace(/[^/]*$/, '') + qrUrl;
                }
                
                console.log('QR URL for display:', qrUrl);
                html += `
                    <div class="col-md-6 col-lg-4 mb-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <h6 class="card-title">${qr.product_name}</h6>
                                <p class="card-text small text-muted">${qr.variant_name || 'Không có biến thể'}</p>
                                <div class="qr-container mb-2">
                                    ${qrUrl ? `<img src="${qrUrl}" alt="QR Code ${qr.sku}" class="img-fluid qr-image" style="max-width: 150px;" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                                    <div class="alert alert-warning" style="display:none;">
                                        <small>Không thể tải QR<br>${qr.sku}</small>
                                    </div>` : '<div class="alert alert-info"><small>Chưa có QR</small></div>'}
                                </div>
                                <div class="mb-2">
                                    <small class="text-muted">SKU: ${qr.sku || 'N/A'}<br>SL: ${qr.quantity}</small>
                                </div>
                                <!-- Download button removed as requested; QR image and info kept -->
                            </div>
                        </div>
                    </div>
                `;
            });
            
            html += '</div>';
        } else if (receipt.status === 'confirmed') {
            console.log('Receipt confirmed but no QR data');
            html += `
                <hr>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Phiếu đã xác nhận:</strong> Chưa có mã QR cho sản phẩm. QR có thể đang được tạo.
                </div>
            `;
        } else if (receipt.status === 'draft') {
            html += `
                <hr>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    <strong>Phiếu tạm:</strong> Mã QR sẽ được tạo sau khi xác nhận phiếu nhập.
                </div>
            `;
        }

        $('#receiptDetailsContent').html(html);
    }

    function displayHistory(history) {
        let html = '<div class="timeline">';
        
        if (history && history.length > 0) {
            history.forEach(item => {
                html += `
                    <div class="timeline-item mb-3 p-3 border-left border-${getActionColor(item.action)}">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <strong>${item.action}</strong>
                                ${item.details ? `<div class="small text-muted mt-1">${item.details}</div>` : ''}
                                ${item.reason ? `<div class="small text-info mt-1"><em>Lý do: ${item.reason}</em></div>` : ''}
                            </div>
                            <div class="text-right">
                                <div class="small font-weight-bold">${item.user}</div>
                                <div class="small text-muted">${item.time}</div>
                            </div>
                        </div>
                    </div>
                `;
            });
        } else {
            html += '<p class="text-muted">Chưa có lịch sử thay đổi</p>';
        }
        
        html += '</div>';
        $('#historyContent').html(html);
    }

    // Hàm xem QR codes của phiếu nhập
    function viewReceiptQRs(receiptId) {
        const modalHtml = `
            <div class="modal fade" id="receiptQRsModal" tabindex="-1" role="dialog">
                <div class="modal-dialog modal-lg" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">QR Codes - Phiếu nhập #${receiptId}</h5>
                            <button type="button" class="close" data-dismiss="modal">
                                <span>&times;</span>
                            </button>
                        </div>
                        <div class="modal-body" id="receiptQRsContent">
                            <div class="text-center">
                                <i class="fas fa-spinner fa-spin fa-2x mb-3"></i>
                                <p>Đang tải danh sách QR codes...</p>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-primary" onclick="generateAllQRsForReceipt(${receiptId})">
                                <i class="fas fa-qrcode"></i> Tạo QR cho tất cả sản phẩm
                            </button>
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Đóng</button>
                        </div>
                    </div>
                </div>
            </div>
        `;

        $('#receiptQRsModal').remove();
        $('body').append(modalHtml);
        $('#receiptQRsModal').modal('show');

        // Load QR codes cho phiếu nhập này
        loadReceiptQRs(receiptId);
    }

    // Load QR codes cho phiếu nhập (với hình ảnh QR)
    function loadReceiptQRs(receiptId) {
        $('#receiptQRsContent').html('<div class="text-center"><i class="fas fa-spinner fa-spin"></i> Đang tạo QR codes...</div>');
        
        $.ajax({
            url: 'api_stock_receipt_simple.php',
            method: 'POST',
            dataType: 'json',
            data: JSON.stringify({
                action: 'get_receipt_qrs',
                receipt_id: receiptId
            }),
            contentType: 'application/json',
            success: function(response) {
                if (response.success && response.qr_items) {
                    displayQRItems(response.qr_items, receiptId);
                } else {
                    $('#receiptQRsContent').html('<div class="alert alert-warning">Không có items để tạo QR codes</div>');
                }
            },
            error: function() {
                $('#receiptQRsContent').html('<div class="alert alert-danger">Có lỗi xảy ra khi tạo QR codes</div>');
            }
        });
    }
    
    // Hiển thị QR codes với hình ảnh
    function displayQRItems(qrItems, receiptId) {
        let html = `
            <div class="mb-3">
                <button class="btn btn-primary btn-sm" onclick="printAllQRs(${receiptId})">
                    <i class="fas fa-print"></i> In tất cả QR codes
                </button>
                <button class="btn btn-success btn-sm ml-2" onclick="downloadAllQRs(${receiptId})">
                    <i class="fas fa-download"></i> Tải xuống
                </button>
            </div>
            <div class="row">
        `;
        
        qrItems.forEach(function(item, index) {
            html += `
                <div class="col-md-6 col-lg-4 mb-4">
                    <div class="card h-100">
                        <div class="card-header text-center bg-light">
                            <h6 class="mb-0">${item.product_name}</h6>
                            <small class="text-muted">${item.variant_name || 'N/A'}</small>
                        </div>
                        <div class="card-body text-center">
                            <div class="mb-2">
                                <img src="${item.qr_url_simple}" alt="QR Code" class="img-fluid" style="max-width: 120px;">
                            </div>
                            <div class="small">
                                <strong>SKU:</strong> ${item.sku || 'N/A'}<br>
                                <strong>Số lượng:</strong> ${item.quantity_expected || item.quantity_received || item.quantity || 0}<br>
                                <strong>Vị trí:</strong> <span class="badge badge-info">${item.location_code || 'N/A'}</span>
                            </div>
                        </div>
                        <div class="card-footer">
                            <div class="btn-group btn-group-sm w-100">
                                <button class="btn btn-outline-primary" onclick="viewQRDetails('${item.uuid}', ${index})">
                                    <i class="fas fa-eye"></i> Chi tiết
                                </button>
                                <button class="btn btn-outline-success" onclick="printSingleQR(${index})">
                                    <i class="fas fa-print"></i> In
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        });
        
        html += '</div>';
        $('#receiptQRsContent').html(html);
    }
    
    // In một QR code
    function printSingleQR(index) {
        alert('Tính năng in QR đang phát triển. QR #' + (index + 1));
    }
    
    // In tất cả QR codes
    function printAllQRs(receiptId) {
        alert('Tính năng in tất cả QR codes đang phát triển. Receipt #' + receiptId);
    }
    
    // Tải xuống QR codes
    function downloadAllQRs(receiptId) {
        alert('Tính năng tải xuống QR codes đang phát triển. Receipt #' + receiptId);
    }
    
    // Xem chi tiết QR
    function viewQRDetails(uuid, index) {
        alert('QR Details:\\nUUID: ' + uuid + '\\nIndex: ' + index);
    }

    // Hiển thị QR codes của phiếu nhập
    function displayReceiptQRs(products, existingQRs) {
        let html = '<div class="table-responsive">';
        html += '<table class="table table-sm">';
        html += '<thead><tr><th>Sản phẩm</th><th>Biến thể</th><th>Số lượng</th><th>QR Codes</th><th>Hành động</th></tr></thead>';
        html += '<tbody>';

        products.forEach(product => {
            const productQRs = existingQRs.filter(qr => qr.product_id == product.product_id && qr.variant_id == product.variant_id);
            
            html += `
                <tr>
                    <td>${product.product_name}</td>
                    <td>${product.variant_name || '-'}</td>
                    <td>${product.quantity_expected || product.quantity_received || product.quantity || 0}</td>
                    <td>
                        ${productQRs.length > 0 ? 
                            productQRs.map(qr => `
                                <span class="badge badge-success qr-badge mr-1" title="${qr.qr_code}">
                                    QR-${qr.id}
                                    ${qr.is_active ? '' : ' (Inactive)'}
                                </span>
                            `).join('') : 
                            '<span class="text-muted">Chưa có QR</span>'
                        }
                    </td>
                    <td>
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-outline-primary" onclick="generateProductQR(${product.product_id}, ${product.variant_id || 'null'})" title="Tạo QR mới">
                                <i class="fas fa-plus"></i>
                            </button>
                            ${productQRs.length > 0 ? `
                                <button class="btn btn-outline-info" onclick="qrManager.viewProductQRs(${product.product_id})" title="Xem tất cả QR">
                                    <i class="fas fa-list"></i>
                                </button>
                            ` : ''}
                        </div>
                    </td>
                </tr>
            `;
        });

        html += '</tbody></table></div>';
        $('#receiptQRsContent').html(html);
    }

    // Tạo QR cho sản phẩm cụ thể
    function generateProductQR(productId, variantId) {
        qrManager.generateQR(productId, variantId);
    }

    // Tạo QR cho tất cả sản phẩm trong phiếu nhập
    function generateAllQRsForReceipt(receiptId) {
        if (!confirm('Bạn có chắc muốn tạo QR code cho tất cả sản phẩm trong phiếu nhập này?')) return;

        // Disable button
        const btn = event.target;
        const originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Đang tạo...';

        $.ajax({
            url: 'api_qr_management.php',
            method: 'POST',
            dataType: 'json',
            data: JSON.stringify({
                action: 'generate_receipt_qrs',
                receipt_id: receiptId
            }),
            contentType: 'application/json',
            success: function(response) {
                btn.disabled = false;
                btn.innerHTML = originalText;
                
                if (response.success) {
                    alert(`Đã tạo thành công ${response.created_count} QR codes!`);
                    loadReceiptQRs(receiptId); // Reload danh sách
                } else {
                    alert('Lỗi: ' + response.message);
                }
            },
            error: function() {
                btn.disabled = false;
                btn.innerHTML = originalText;
                alert('Có lỗi xảy ra khi tạo QR codes');
            }
        });
    }

    // Hàm hiển thị QR management
    function showQRManagement() {
        const modalHtml = `
            <div class="modal fade" id="qrManagementModal" tabindex="-1" role="dialog">
                <div class="modal-dialog modal-xl" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="fas fa-qrcode"></i> Quản lý QR Code
                            </h5>
                            <button type="button" class="close" data-dismiss="modal">
                                <span>&times;</span>
                            </button>
                        </div>
                        <div class="modal-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="card">
                                        <div class="card-header">
                                            <h6 class="card-title mb-0">Scan QR Code</h6>
                                        </div>
                                        <div class="card-body">
                                            <button class="btn btn-primary btn-block" onclick="qrManager.startQRScanner()">
                                                <i class="fas fa-camera"></i> Bắt đầu scan
                                            </button>
                                            <hr>
                                            <div class="form-group">
                                                <label>Hoặc nhập mã QR:</label>
                                                <input type="text" class="form-control" id="manual-qr-scan" placeholder="Nhập mã QR...">
                                                <button class="btn btn-sm btn-outline-primary mt-2" onclick="scanManualQR()">
                                                    <i class="fas fa-search"></i> Kiểm tra
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="card">
                                        <div class="card-header">
                                            <h6 class="card-title mb-0">Thống kê QR Code</h6>
                                        </div>
                                        <div class="card-body" id="qr-stats">
                                            <div class="text-center">
                                                <i class="fas fa-spinner fa-spin"></i> Đang tải...
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `;

        $('#qrManagementModal').remove();
        $('body').append(modalHtml);
        $('#qrManagementModal').modal('show');

        // Load QR statistics
        loadQRStatistics();
    }

    // Load thống kê QR
    function loadQRStatistics() {
        $.ajax({
            url: 'api_qr_management.php',
            method: 'POST',
            dataType: 'json',
            data: JSON.stringify({
                action: 'get_statistics'
            }),
            contentType: 'application/json',
            success: function(response) {
                if (response.success) {
                    displayQRStatistics(response.stats);
                } else {
                    $('#qr-stats').html('<div class="alert alert-danger">Không thể tải thống kê</div>');
                }
            },
            error: function() {
                $('#qr-stats').html('<div class="alert alert-danger">Có lỗi xảy ra</div>');
            }
        });
    }

    // Hiển thị thống kê QR
    function displayQRStatistics(stats) {
        const html = `
            <div class="row text-center">
                <div class="col-6">
                    <div class="border rounded p-3">
                        <h4 class="text-primary">${stats.total || 0}</h4>
                        <small class="text-muted">Tổng QR codes</small>
                    </div>
                </div>
                <div class="col-6">
                    <div class="border rounded p-3">
                        <h4 class="text-success">${stats.active || 0}</h4>
                        <small class="text-muted">Đang hoạt động</small>
                    </div>
                </div>
                <div class="col-6 mt-3">
                    <div class="border rounded p-3">
                        <h4 class="text-warning">${stats.today || 0}</h4>
                        <small class="text-muted">Tạo hôm nay</small>
                    </div>
                </div>
                <div class="col-6 mt-3">
                    <div class="border rounded p-3">
                        <h4 class="text-danger">${stats.inactive || 0}</h4>
                        <small class="text-muted">Vô hiệu hóa</small>
                    </div>
                </div>
            </div>
        `;
        $('#qr-stats').html(html);
    }

    // Scan QR thủ công
    function scanManualQR() {
        const qrCode = $('#manual-qr-scan').val().trim();
        if (qrCode) {
            qrManager.scanQRCode(qrCode);
            $('#qrManagementModal').modal('hide');
        } else {
            alert('Vui lòng nhập mã QR');
        }
    }

    // Listen for QR events
    $(document).on('qr-generated', function(event, qrData) {
        // Reload QR list nếu đang mở modal
        if ($('#receiptQRsModal').hasClass('show')) {
            const receiptId = $('#receiptQRsModal').data('receipt-id');
            if (receiptId) {
                loadReceiptQRs(receiptId);
            }
        }
    });

    $(document).on('qr-scanned', function(event, data) {
        // Hiển thị thông báo tìm thấy sản phẩm
        const message = `
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <strong>Tìm thấy sản phẩm:</strong> ${data.product.product_name}
                ${data.product.variant_name ? `<br><strong>Biến thể:</strong> ${data.product.variant_name}` : ''}
                <button type="button" class="close" data-dismiss="alert">
                    <span>&times;</span>
                </button>
            </div>
        `;
        
        $('.container-fluid').prepend(message);
        
        // Auto hide after 10 seconds
        setTimeout(() => {
            $('.alert-success').alert('close');
        }, 10000);
    });

    function getStatusLabel(status) {
        const labels = {
            'draft': 'Bản nháp',
            'completed': 'Hoàn thành',
            'confirmed': 'Đã xác nhận',
            'rejected': 'Đã từ chối'
        };
        return labels[status] || status;
    }

    function getStatusColor(status) {
        const colors = {
            'draft': 'warning',
            'completed': 'success',
            'confirmed': 'success',
            'rejected': 'danger'
        };
        return colors[status] || 'secondary';
    }

    function getActionColor(action) {
        const colors = {
            'Tạo phiếu': 'success',
            'Cập nhật': 'warning',
            'Xác nhận': 'info',
            'Xóa': 'danger'
        };
        return colors[action] || 'secondary';
    }

    // Function to download QR code
    function downloadQR(qrUrl, sku) {
        try {
            // Handle local file paths vs full URLs
            let downloadUrl = qrUrl;
            if (!qrUrl.startsWith('http')) {
                downloadUrl = window.location.origin + window.location.pathname.replace(/[^/]*$/, '') + qrUrl;
            }
            // Fetch the image as blob and trigger download via object URL (no navigation)
            fetch(downloadUrl, { mode: 'cors' })
                .then(resp => {
                    if (!resp.ok) throw new Error('Network response was not ok');
                    return resp.blob();
                })
                .then(blob => {
                    const blobUrl = URL.createObjectURL(blob);
                    const link = document.createElement('a');
                    link.href = blobUrl;
                    link.download = `QR_${sku}_${Date.now()}.png`;
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                    // Release object URL
                    URL.revokeObjectURL(blobUrl);

                    Swal.fire({
                        icon: 'success',
                        title: 'Thành công!',
                        text: `Đã tải QR code cho ${sku}`,
                        timer: 1500,
                        showConfirmButton: false
                    });
                })
                .catch(err => {
                    console.error('Download QR failed:', err);
                    Swal.fire({
                        icon: 'error',
                        title: 'Lỗi tải',
                        text: 'Không thể tải QR. Vui lòng thử mở ảnh và lưu thủ công.',
                        timer: 3000,
                        showConfirmButton: false
                    });
                });
                
        } catch (error) {
            console.error('Download QR error:', error);
            Swal.fire({
                icon: 'error',
                title: 'Lỗi',
                text: 'Không thể tải QR code. Vui lòng thử lại.',
                timer: 2000,
                showConfirmButton: false
            });
        }
    }
    </script>

<?php include 'includes/footer.php'; ?>

</html>
