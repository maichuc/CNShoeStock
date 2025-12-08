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

// Lấy thông tin user
$stmt = $pdo->prepare("
    SELECT u.*, w.name as warehouse_name, w.address as warehouse_address
    FROM users u
    LEFT JOIN warehouses w ON u.warehouse_id = w.warehouse_id
    WHERE u.user_id = ?
");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) {
    header('Location: login.html');
    exit();
}

// Xử lý cập nhật thông tin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    try {
        $fullName = trim($_POST['full_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $warehouseAddress = trim($_POST['warehouse_address'] ?? '');
        
        if (empty($fullName)) {
            throw new Exception('Họ và tên không được để trống');
        }
        
        // Cập nhật thông tin user
        $updateUserSql = "UPDATE users SET full_name = ?, phone = ? WHERE user_id = ?";
        $updateUserStmt = $pdo->prepare($updateUserSql);
        $updateUserStmt->execute([$fullName, $phone, $_SESSION['user_id']]);
        
        // Cập nhật địa chỉ kho nếu user có warehouse_id
        if ($user['warehouse_id'] && !empty($warehouseAddress)) {
            $updateWarehouseSql = "UPDATE warehouses SET address = ? WHERE warehouse_id = ?";
            $updateWarehouseStmt = $pdo->prepare($updateWarehouseSql);
            $updateWarehouseStmt->execute([$warehouseAddress, $user['warehouse_id']]);
        }
        
        // Cập nhật session
        $_SESSION['full_name'] = $fullName;
        
        $successMessage = "Cập nhật thông tin thành công!";
        
        // Tải lại user data
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
        
    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
    }
}

$userName = $user['full_name'] ?? 'User';
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Thông tin cá nhân - Smart Warehouse System</title>

    <!-- Custom fonts -->
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">

    <!-- Custom styles -->
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">

    <style>
        /* Tông màu chủ đạo: Xanh dương #224abe, Đen đậm #2c3e50, Trắng */
        body {
            background-color: #f5f7fa;
        }
        
        .container-fluid {
            background-color: #ffffff;
            padding: 25px;
        }
        
        .profile-header {
            background: #224abe;
            color: #ffffff;
            padding: 35px 30px;
            border-radius: 6px;
            margin-bottom: 25px;
            box-shadow: 0 2px 6px rgba(34, 74, 190, 0.15);
        }
        
        .profile-header h2 {
            color: #ffffff !important;
            font-weight: 600;
            font-size: 1.75rem;
            margin-bottom: 8px;
        }
        
        .profile-header p {
            color: #ffffff !important;
            opacity: 0.95;
            font-size: 0.95rem;
        }
        
        .profile-avatar {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            border: 3px solid #ffffff;
            background: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
            color: #224abe;
            margin: 0 auto 15px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.12);
        }
        
        .info-card {
            background: #ffffff;
            border: none;
            border-radius: 6px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            transition: all 0.3s;
        }
        
        .info-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .card-header.bg-gradient-primary {
            background: #2c3e50 !important;
            color: white !important;
            border-bottom: none;
            padding: 15px 20px !important;
            border-radius: 6px 6px 0 0 !important;
        }
        
        .card-header.bg-gradient-success {
            background: #10b981 !important;
            color: white !important;
            border-bottom: none;
            padding: 15px 20px !important;
            border-radius: 6px 6px 0 0 !important;
        }
        
        .card-body {
            padding: 25px;
        }
        
        .info-label {
            font-weight: 600;
            color: #4b5563;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
            display: block;
        }
        
        .info-value {
            font-size: 0.95rem;
            color: #1f2937;
            font-weight: 500;
            padding: 12px 15px;
            background: #f9fafb;
            border-radius: 4px;
            border: 1px solid #e5e7eb;
            margin-bottom: 0;
        }
        
        .badge-role {
            font-size: 0.8rem;
            padding: 6px 14px;
            border-radius: 4px;
            font-weight: 600;
            letter-spacing: 0.3px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-control {
            border: 1.5px solid #d1d5db;
            border-radius: 4px;
            padding: 10px 14px;
            color: #1f2937;
            background-color: #ffffff;
            font-weight: 500;
            font-size: 0.95rem;
            transition: all 0.2s;
        }
        
        .form-control:focus {
            border-color: #224abe;
            box-shadow: 0 0 0 3px rgba(34, 74, 190, 0.1);
            background-color: #ffffff;
            color: #1f2937;
        }
        
        .form-control:disabled {
            background-color: #f3f4f6;
            color: #6b7280;
        }
        
        .text-muted {
            color: #6b7280 !important;
            font-size: 0.8rem;
        }
        
        .btn-update {
            background: #224abe;
            border: none;
            padding: 12px 28px;
            font-weight: 600;
            color: #ffffff;
            border-radius: 4px;
            letter-spacing: 0.3px;
            font-size: 0.95rem;
            transition: all 0.3s;
        }
        
        .btn-update:hover {
            background: #1a3a8f;
            color: #ffffff;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(34, 74, 190, 0.25);
        }
        
        .text-gray-800 {
            color: #1f2937 !important;
        }
        
        h1, h2, h3, h4, h5, h6 {
            color: #1f2937;
            font-weight: 600;
        }
        
        .card-header h6 {
            color: #ffffff !important;
            font-size: 0.95rem;
            font-weight: 600;
        }
        
        .alert {
            border-radius: 4px;
            border: none;
            padding: 14px 18px;
            font-size: 0.9rem;
        }
        
        hr {
            border-top: 1px solid #e5e7eb;
            margin: 20px 0;
        }
    </style>
</head>

<body id="page-top">
    <!-- Page Wrapper -->
    <div id="wrapper">
        <!-- Sidebar -->
        <?php include 'includes/thanh_ben.php'; ?>
        <!-- End of Sidebar -->

        <!-- Content Wrapper -->
        <div id="content-wrapper" class="d-flex flex-column">
            <!-- Main Content -->
            <div id="content">
                <!-- Topbar -->
                <?php include 'includes/thanh_tren.php'; ?>
                <!-- End of Topbar -->

                <!-- Begin Page Content -->
                <div class="container-fluid">
                    <!-- Page Heading -->
                    <div class="d-sm-flex align-items-center justify-content-between mb-4">
                        <h1 class="h3 mb-0 text-gray-800">
                            <i class="fas fa-user-circle"></i> Thông tin cá nhân
                        </h1>
                    </div>

                    <?php if (isset($successMessage)): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle mr-2"></i><?php echo $successMessage; ?>
                        <button type="button" class="close" data-dismiss="alert">
                            <span>&times;</span>
                        </button>
                    </div>
                    <?php endif; ?>

                    <?php if (isset($errorMessage)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle mr-2"></i><?php echo $errorMessage; ?>
                        <button type="button" class="close" data-dismiss="alert">
                            <span>&times;</span>
                        </button>
                    </div>
                    <?php endif; ?>

                    <!-- Profile Header -->
                    <div class="profile-header">
                        <div class="text-center">
                            <div class="profile-avatar">
                                <i class="fas fa-user"></i>
                            </div>
                            <h2 class="mb-2"><?php echo htmlspecialchars($user['full_name']); ?></h2>
                            <p class="mb-1">
                                <i class="fas fa-envelope mr-2"></i><?php echo htmlspecialchars($user['email']); ?>
                            </p>
                            <div class="mt-3">
                                <?php
                                $roleBadgeClass = '';
                                $roleLabel = '';
                                switch($user['role']) {
                                    case 'admin':
                                        $roleBadgeClass = 'badge-danger';
                                        $roleLabel = 'Quản trị viên';
                                        break;
                                    case 'manager':
                                        $roleBadgeClass = 'badge-warning';
                                        $roleLabel = 'Quản lý kho';
                                        break;
                                    default:
                                        $roleBadgeClass = 'badge-info';
                                        $roleLabel = 'Nhân viên';
                                }
                                ?>
                                <span class="badge badge-role <?php echo $roleBadgeClass; ?>">
                                    <i class="fas fa-user-tag mr-1"></i><?php echo $roleLabel; ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <!-- Thông tin cá nhân -->
                        <div class="col-lg-6 mb-4">
                            <div class="card shadow info-card h-100">
                                <div class="card-header py-3 bg-gradient-primary">
                                    <h6 class="m-0 font-weight-bold text-white">
                                        <i class="fas fa-id-card mr-2"></i>Thông tin tài khoản
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <form method="POST" action="">
                                        <input type="hidden" name="action" value="update_profile">
                                        
                                        <div class="form-group">
                                            <label class="info-label">
                                                <i class="fas fa-user mr-1"></i>Họ và tên
                                            </label>
                                            <input type="text" class="form-control" name="full_name" 
                                                   value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                                        </div>

                                        <div class="form-group">
                                            <label class="info-label">
                                                <i class="fas fa-envelope mr-1"></i>Email (Tên đăng nhập)
                                            </label>
                                            <input type="email" class="form-control" 
                                                   value="<?php echo htmlspecialchars($user['email']); ?>" disabled>
                                            <small class="text-muted">Email không thể thay đổi</small>
                                        </div>

                                        <div class="form-group">
                                            <label class="info-label">
                                                <i class="fas fa-phone mr-1"></i>Số điện thoại
                                            </label>
                                            <input type="tel" class="form-control" name="phone" 
                                                   value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" 
                                                   placeholder="Nhập số điện thoại">
                                        </div>

                                        <div class="form-group">
                                            <label class="info-label">
                                                <i class="fas fa-map-marker-alt mr-1"></i>Địa chỉ kho
                                            </label>
                                            <textarea class="form-control" name="warehouse_address" rows="3" 
                                                      placeholder="Nhập địa chỉ kho"><?php echo htmlspecialchars($user['warehouse_address'] ?? ''); ?></textarea>
                                            <small class="text-muted">Cập nhật địa chỉ kho làm việc</small>
                                        </div>

                                        <button type="submit" class="btn btn-primary btn-update btn-block">
                                            <i class="fas fa-save mr-2"></i>Cập nhật thông tin
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- Thông tin kho hàng -->
                        <div class="col-lg-6 mb-4">
                            <div class="card shadow info-card h-100">
                                <div class="card-header py-3 bg-gradient-success">
                                    <h6 class="m-0 font-weight-bold text-white">
                                        <i class="fas fa-warehouse mr-2"></i>Thông tin kho hàng
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <?php if ($user['warehouse_name']): ?>
                                        <div class="mb-4">
                                            <div class="info-label">Kho làm việc</div>
                                            <div class="info-value">
                                                <i class="fas fa-building text-success mr-2"></i>
                                                <?php echo htmlspecialchars($user['warehouse_name']); ?>
                                            </div>
                                        </div>

                                        <?php if ($user['warehouse_address']): ?>
                                        <div class="mb-4">
                                            <div class="info-label">Địa chỉ kho</div>
                                            <div class="info-value">
                                                <i class="fas fa-map-marker-alt text-danger mr-2"></i>
                                                <?php echo htmlspecialchars($user['warehouse_address']); ?>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="alert alert-warning">
                                            <i class="fas fa-exclamation-triangle mr-2"></i>
                                            Bạn chưa được phân công vào kho hàng nào.
                                        </div>
                                    <?php endif; ?>

                                    <hr>

                                    <div class="mb-4">
                                        <div class="info-label">Ngày tạo tài khoản</div>
                                        <div class="info-value">
                                            <i class="fas fa-calendar-plus text-primary mr-2"></i>
                                            <?php echo date('d/m/Y H:i', strtotime($user['created_at'])); ?>
                                        </div>
                                    </div>

                                    <?php if ($user['updated_at'] && $user['updated_at'] != $user['created_at']): ?>
                                    <div class="mb-4">
                                        <div class="info-label">Cập nhật lần cuối</div>
                                        <div class="info-value">
                                            <i class="fas fa-clock text-warning mr-2"></i>
                                            <?php echo date('d/m/Y H:i', strtotime($user['updated_at'])); ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>

                                    <div class="mt-4">
                                        <a href="doi_mat_khau.php" class="btn btn-outline-primary btn-block">
                                            <i class="fas fa-key mr-2"></i>Đổi mật khẩu
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Thống kê hoạt động (nếu có) -->
                    <div class="row">
                        <div class="col-12">
                            <div class="card shadow info-card">
                                <div class="card-header py-3 bg-gradient-info">
                                    <h6 class="m-0 font-weight-bold text-white">
                                        <i class="fas fa-chart-line mr-2"></i>Thông tin bổ sung
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="row text-center">
                                        <div class="col-md-3">
                                            <div class="border-right">
                                                <h5 class="text-primary mb-2">
                                                    <i class="fas fa-user-shield fa-2x"></i>
                                                </h5>
                                                <p class="info-label mb-1">Vai trò</p>
                                                <p class="info-value"><?php echo $roleLabel; ?></p>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="border-right">
                                                <?php
                                                $statusColor = 'text-success';
                                                $statusIcon = 'fa-check-circle';
                                                $statusText = 'Đang hoạt động';
                                                
                                                if ($user['status'] == 'pending') {
                                                    $statusColor = 'text-warning';
                                                    $statusIcon = 'fa-clock';
                                                    $statusText = 'Chờ kích hoạt';
                                                } elseif ($user['status'] == 'inactive') {
                                                    $statusColor = 'text-danger';
                                                    $statusIcon = 'fa-ban';
                                                    $statusText = 'Vô hiệu hóa';
                                                }
                                                ?>
                                                <h5 class="<?php echo $statusColor; ?> mb-2">
                                                    <i class="fas <?php echo $statusIcon; ?> fa-2x"></i>
                                                </h5>
                                                <p class="info-label mb-1">Trạng thái</p>
                                                <p class="info-value"><?php echo $statusText; ?></p>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="border-right">
                                                <h5 class="text-primary mb-2">
                                                    <i class="fas fa-sign-in-alt fa-2x"></i>
                                                </h5>
                                                <p class="info-label mb-1">Đăng nhập cuối</p>
                                                <p class="info-value">
                                                    <?php echo $user['last_login'] ? date('d/m/Y H:i', strtotime($user['last_login'])) : 'Chưa có'; ?>
                                                </p>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <h5 class="text-info mb-2">
                                                <i class="fas fa-warehouse fa-2x"></i>
                                            </h5>
                                            <p class="info-label mb-1">Mã kho</p>
                                            <p class="info-value">
                                                <?php echo $user['warehouse_id'] ? '#' . $user['warehouse_id'] : 'N/A'; ?>
                                            </p>
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
    <?php include 'includes/modal_dang_xuat.php'; ?>

    <!-- Scripts -->
    <script src="vendor/jquery/jquery.min.js"></script>
    <script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="vendor/jquery-easing/jquery.easing.min.js"></script>
    <script src="js/sb-admin-2.min.js"></script>
    
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        // Auto hide alerts
        $(document).ready(function() {
            setTimeout(function() {
                $('.alert').fadeOut('slow');
            }, 3000);
        });
    </script>
</body>
</html>
