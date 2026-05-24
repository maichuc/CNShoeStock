<?php
// session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../app/Classes/Kho.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    header('Location: login.html');
    exit();
}

// Kiểm tra receipt_id
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: quan_ly_phieu_nhap_kho.php');
    exit();
}

$receiptId = $_GET['id'];

// Kết nối database
$database = new Database();
$pdo = $database->getConnection();

$currentUser = $_SESSION['user_id'];
$userWarehouseId = $_SESSION['warehouse_id'] ?? null;

// Lấy thông tin phiếu nhập
$receipt = null;
try {
    $stmt = $pdo->prepare("
        SELECT sr.*, s.name as supplier_name, COALESCE(s.contact_info, '') as contact_info, u.username as created_by_name
        FROM stock_receipts sr 
        LEFT JOIN suppliers s ON sr.supplier_id = s.supplier_id 
        LEFT JOIN users u ON sr.created_by = u.user_id
        WHERE sr.receipt_id = ? AND sr.warehouse_id = ?
    ");
    $stmt->execute([$receiptId, $userWarehouseId]);
    $receipt = $stmt->fetch();
    
    if (!$receipt) {
        header('Location: quan_ly_phieu_nhap_kho.php');
        exit();
    }
} catch(PDOException $e) {
    error_log("Error getting receipt: " . $e->getMessage());
    header('Location: quan_ly_phieu_nhap_kho.php');
    exit();
}

// Lấy thông tin warehouse
$warehouseName = 'SB Admin 2';
if ($userWarehouseId) {
    $warehouseObj = new Warehouse($pdo);
    if ($warehouseObj->getById($userWarehouseId)) {
        $warehouseName = $warehouseObj->name;
    }
}

// Lấy danh sách items trong phiếu nhập
$receiptItems = [];
try {
    $stmt = $pdo->prepare("
        SELECT ri.*, p.name as product_name, pv.sku, pv.size, pv.color 
        FROM receipt_items ri
        LEFT JOIN product_variants pv ON ri.variant_id = pv.variant_id
        LEFT JOIN products p ON pv.product_id = p.product_id
        WHERE ri.receipt_id = ?
        ORDER BY ri.item_id
    ");
    $stmt->execute([$receiptId]);
    $receiptItems = $stmt->fetchAll();
} catch(PDOException $e) {
    error_log("Error getting receipt items: " . $e->getMessage());
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

    <title>Chi tiết phiếu nhập - Smart Warehouse</title>

    <!-- Custom fonts for this template-->
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">

    <!-- Custom styles for this template-->
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
</head>

<body id="page-top">

    <!-- Page Wrapper -->
    <div id="wrapper">

        <!-- Sidebar -->
        <ul class="navbar-nav bg-gradient-primary sidebar sidebar-dark accordion" id="accordionSidebar">

            <!-- Sidebar - Brand -->
            <a class="sidebar-brand d-flex align-items-center justify-content-center" href="trang_chu.php">
                <div class="sidebar-brand-icon rotate-n-15">
                    <i class="fas fa-warehouse"></i>
                </div>
                <div class="sidebar-brand-text mx-3"><?php echo htmlspecialchars($warehouseName); ?></div>
            </a>

            <!-- Divider -->
            <hr class="sidebar-divider my-0">

            <!-- Nav Item - Dashboard -->
            <li class="nav-item">
                <a class="nav-link" href="trang_chu.php">
                    <i class="fas fa-fw fa-tachometer-alt"></i>
                    <span>Dashboard</span></a>
            </li>

            <!-- Divider -->
            <hr class="sidebar-divider">

            <!-- Heading -->
            <div class="sidebar-heading">
                Quản lý kho
            </div>

            <!-- Nav Item - Quản lý sản phẩm -->
            <li class="nav-item">
                <a class="nav-link collapsed" href="#" data-toggle="collapse" data-target="#collapseProducts"
                    aria-expanded="true" aria-controls="collapseProducts">
                    <i class="fas fa-fw fa-box"></i>
                    <span>Quản lý sản phẩm</span>
                </a>
                <div id="collapseProducts" class="collapse" aria-labelledby="headingProducts" data-parent="#accordionSidebar">
                    <div class="bg-white py-2 collapse-inner rounded">
                        <h6 class="collapse-header">Sản phẩm:</h6>
                        <a class="collapse-item" href="them_san_pham_ai.php">Thêm sản phẩm</a>
                        <a class="collapse-item" href="danh_sach_san_pham.php">Danh sách sản phẩm</a>
                    </div>
                </div>
            </li>

            <!-- Nav Item - Quản lý phiếu nhập -->
            <li class="nav-item active">
                <a class="nav-link" href="#" data-toggle="collapse" data-target="#collapseReceipts"
                    aria-expanded="true" aria-controls="collapseReceipts">
                    <i class="fas fa-fw fa-clipboard-list"></i>
                    <span>Phiếu nhập kho</span>
                </a>
                <div id="collapseReceipts" class="collapse show" aria-labelledby="headingReceipts" data-parent="#accordionSidebar">
                    <div class="bg-white py-2 collapse-inner rounded">
                        <h6 class="collapse-header">Quản lý nhập kho:</h6>
                        <a class="collapse-item" href="tao_phieu_nhap_moi.php">Tạo phiếu nhập mới</a>
                        <a class="collapse-item active" href="quan_ly_phieu_nhap_kho.php">Quản lý phiếu nhập</a>
                    </div>
                </div>
            </li>

            <!-- Divider -->
            <hr class="sidebar-divider d-none d-md-block">

            <!-- Sidebar Toggler (Sidebar) -->
            <div class="text-center d-none d-md-inline">
                <button class="rounded-circle border-0" id="sidebarToggle"></button>
            </div>

        </ul>
        <!-- End of Sidebar -->

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

                    <!-- Topbar Navbar -->
                    <ul class="navbar-nav ml-auto">

                        <!-- Nav Item - User Information -->
                        <li class="nav-item dropdown no-arrow">
                            <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button"
                                data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <span class="mr-2 d-none d-lg-inline text-gray-600 small"><?php echo htmlspecialchars($_SESSION['username'] ?? 'User'); ?></span>
                                <img class="img-profile rounded-circle" src="img/undraw_profile.svg">
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

                    <!-- Page Heading -->
                    <div class="d-sm-flex align-items-center justify-content-between mb-4">
                        <div>
                            <h1 class="h3 mb-0 text-gray-800">Chi tiết phiếu nhập #<?php echo str_pad($receipt['receipt_id'], 6, '0', STR_PAD_LEFT); ?></h1>
                            <nav aria-label="breadcrumb">
                                <ol class="breadcrumb">
                                    <li class="breadcrumb-item"><a href="trang_chu.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="quan_ly_phieu_nhap_kho.php">Quản lý phiếu nhập</a></li>
                                    <li class="breadcrumb-item active" aria-current="page">Chi tiết phiếu nhập</li>
                                </ol>
                            </nav>
                        </div>
                        <div>
                            <a href="quan_ly_phieu_nhap_kho.php" class="btn btn-secondary btn-sm">
                                <i class="fas fa-arrow-left fa-sm text-white-50"></i> Quay lại
                            </a>
                            <?php if ($receipt['status'] == 'pending'): ?>
                            <button class="btn btn-success btn-sm" onclick="confirmReceipt()">
                                <i class="fas fa-check fa-sm text-white-50"></i> Xác nhận phiếu nhập
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Content Row -->
                    <div class="row">

                        <!-- Thông tin phiếu nhập -->
                        <div class="col-lg-8">
                            <div class="card shadow mb-4">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">Thông tin phiếu nhập</h6>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label class="font-weight-bold">Mã phiếu nhập:</label>
                                                <p>#<?php echo str_pad($receipt['receipt_id'], 6, '0', STR_PAD_LEFT); ?></p>
                                            </div>
                                            <div class="form-group">
                                                <label class="font-weight-bold">Nhà cung cấp:</label>
                                                <p><?php echo htmlspecialchars($receipt['supplier_name'] ?? 'N/A'); ?></p>
                                            </div>
                                            <div class="form-group">
                                                <label class="font-weight-bold">Người tạo:</label>
                                                <p><?php echo htmlspecialchars($receipt['created_by_name'] ?? 'N/A'); ?></p>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label class="font-weight-bold">Trạng thái:</label>
                                                <p>
                                                    <?php if ($receipt['status'] == 'pending'): ?>
                                                        <span class="badge badge-warning badge-pill">Bản nháp</span>
                                                    <?php elseif ($receipt['status'] == 'confirmed'): ?>
                                                        <span class="badge badge-success badge-pill">Đã xác nhận</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-secondary badge-pill"><?php echo ucfirst($receipt['status']); ?></span>
                                                    <?php endif; ?>
                                                </p>
                                            </div>
                                            <div class="form-group">
                                                <label class="font-weight-bold">Ngày tạo:</label>
                                                <p><?php echo date('d/m/Y H:i:s', strtotime($receipt['created_at'])); ?></p>
                                            </div>
                                            <?php if ($receipt['qr_code']): ?>
                                            <div class="form-group">
                                                <label class="font-weight-bold">Mã QR:</label>
                                                <p><?php echo htmlspecialchars($receipt['qr_code']); ?></p>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php if ($receipt['notes']): ?>
                                    <div class="form-group">
                                        <label class="font-weight-bold">Ghi chú:</label>
                                        <p class="text-muted"><?php echo nl2br(htmlspecialchars($receipt['notes'])); ?></p>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Danh sách sản phẩm -->
                            <div class="card shadow mb-4">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">Danh sách sản phẩm</h6>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($receiptItems)): ?>
                                        <div class="text-center text-muted">
                                            <i class="fas fa-inbox fa-3x mb-3"></i>
                                            <p>Chưa có sản phẩm nào trong phiếu nhập này</p>
                                        </div>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-bordered">
                                                <thead>
                                                    <tr>
                                                        <th>#</th>
                                                        <th>Sản phẩm</th>
                                                        <th>SKU</th>
                                                        <th>Size</th>
                                                        <th>Màu sắc</th>
                                                        <th>Số lượng</th>
                                                        <th>Đơn giá</th>
                                                        <th>Thành tiền</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php 
                                                    $totalAmount = 0;
                                                    foreach ($receiptItems as $index => $item): 
                                                        $subtotal = $item['quantity'] * $item['unit_price'];
                                                        $totalAmount += $subtotal;
                                                    ?>
                                                    <tr>
                                                        <td><?php echo $index + 1; ?></td>
                                                        <td><?php echo htmlspecialchars($item['product_name'] ?? 'N/A'); ?></td>
                                                        <td><?php echo htmlspecialchars($item['sku'] ?? 'N/A'); ?></td>
                                                        <td><?php echo htmlspecialchars($item['size'] ?? 'N/A'); ?></td>
                                                        <td><?php echo htmlspecialchars($item['color'] ?? 'N/A'); ?></td>
                                                        <td><?php echo number_format($item['quantity']); ?></td>
                                                        <td><?php echo number_format($item['unit_price'], 0, ',', '.'); ?>đ</td>
                                                        <td><?php echo number_format($subtotal, 0, ',', '.'); ?>đ</td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                                <tfoot>
                                                    <tr class="font-weight-bold">
                                                        <td colspan="7" class="text-right">Tổng cộng:</td>
                                                        <td><?php echo number_format($totalAmount, 0, ',', '.'); ?>đ</td>
                                                    </tr>
                                                </tfoot>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Sidebar thông tin bổ sung -->
                        <div class="col-lg-4">
                            <!-- Thông tin nhà cung cấp -->
                            <div class="card shadow mb-4">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">Thông tin nhà cung cấp</h6>
                                </div>
                                <div class="card-body">
                                    <div class="text-center">
                                        <i class="fas fa-building fa-3x text-gray-300 mb-3"></i>
                                        <h5><?php echo htmlspecialchars($receipt['supplier_name'] ?? 'N/A'); ?></h5>
                                        <?php if ($receipt['contact_info']): ?>
                                        <p class="text-muted"><?php echo nl2br(htmlspecialchars($receipt['contact_info'])); ?></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Thống kê -->
                            <div class="card shadow mb-4">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">Thống kê</h6>
                                </div>
                                <div class="card-body">
                                    <div class="row no-gutters">
                                        <div class="col-6 text-center border-right">
                                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                                Tổng sản phẩm
                                            </div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo count($receiptItems); ?></div>
                                        </div>
                                        <div class="col-6 text-center">
                                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                                Tổng số lượng
                                            </div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                                <?php 
                                                $totalQuantity = array_sum(array_column($receiptItems, 'quantity'));
                                                echo number_format($totalQuantity); 
                                                ?>
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

            <?php include __DIR__ . '/includes/chan_trang.php'; ?>

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

    <script>
    function confirmReceipt() {
        if (confirm('Bạn có chắc chắn muốn xác nhận phiếu nhập này? Hành động này không thể hoàn tác.')) {
            // CẦN LÀM: Triển khai chức năng xác nhận phiếu nhập qua AJAX
            $.ajax({
                url: 'api_xac_nhan_phieu_nhap.php',
                method: 'POST',
                data: { receipt_id: <?php echo $receiptId; ?> },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        alert('Đã xác nhận phiếu nhập thành công!');
                        location.reload();
                    } else {
                        alert('Lỗi: ' + response.message);
                    }
                },
                error: function() {
                    alert('Có lỗi xảy ra khi xác nhận phiếu nhập');
                }
            });
        }
    }
    </script>

</body>

</html>

