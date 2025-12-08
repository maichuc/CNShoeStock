<?php
session_start();
require_once 'config/database.php';
require_once 'classes/KiemSoatTruyCapKho.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    header('Location: login.html');
    exit();
}

// Kiểm tra quyền truy cập (chỉ Manager và Admin)
$userRole = $_SESSION['role'] ?? 'staff';
if (!in_array($userRole, ['admin', 'manager'])) {
    header('Location: 404.html');
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

// Lấy danh sách warehouses (cho Admin)
$warehouses = [];
if ($userRole === 'admin') {
    $stmt = $pdo->query("SELECT warehouse_id, name FROM warehouses WHERE status = 'active' ORDER BY name");
    $warehouses = $stmt->fetchAll();
}
?>

<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Quản lý nhân viên - Smart Warehouse System</title>

    <!-- Custom fonts for this template -->
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">

    <!-- Custom styles for this template -->
    <link href="css/sb-admin-2.min.css" rel="stylesheet">

    <!-- Page level plugin CSS-->
    <link href="vendor/datatables/dataTables.bootstrap4.min.css" rel="stylesheet">
    
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">

    <style>
        
        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.6875rem;
            font-weight: 600;
            text-transform: uppercase;
            white-space: nowrap;
            line-height: 1.2;
        }
        
        .status-active { background-color: #d4edda; color: #155724; }
        .status-inactive { background-color: #f8d7da; color: #721c24; }
        .status-pending { background-color: #fff3cd; color: #856404; }
        .status-locked { background-color: #f8d7da; color: #721c24; border: 2px solid #dc3545; }
        
        .role-badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.6875rem;
            font-weight: 600;
            white-space: nowrap;
            line-height: 1.2;
        }
        
        .role-admin { background-color: #dc3545; color: white; }
        .role-manager { background-color: #007bff; color: white; }
        .role-staff { background-color: #6c757d; color: white; }
        
        .action-btn {
            padding: 0.375rem 0.5rem;
            margin: 0;
            border-radius: 0.25rem;
            font-size: 0.875rem;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            white-space: nowrap;
            border: none;
            width: 2rem;
            height: 2rem;
            flex-shrink: 0;
        }
        
        .action-btn i {
            font-size: 0.875rem;
        }
        
        .action-btn:hover { 
            transform: translateY(-2px);
            box-shadow: 0 0.125rem 0.5rem rgba(0,0,0,0.15);
        }
        
        .action-buttons-wrapper {
            display: inline-flex;
            gap: 0.25rem;
            align-items: center;
            justify-content: center;
            flex-wrap: nowrap;
            white-space: nowrap;
        }
        
        /* Đảm bảo table responsive */
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        
        #employeeTable {
            width: 100%;
            font-size: 0.8125rem;
            table-layout: fixed;
        }
        
        #employeeTable th {
            white-space: nowrap;
            font-size: 0.8125rem;
            padding: 0.75rem 0.5rem;
            vertical-align: middle;
            font-weight: 600;
        }
        
        #employeeTable td {
            padding: 0.625rem 0.5rem;
            vertical-align: middle;
            word-wrap: break-word;
        }
        
        /* Cố định width cho từng cột */
        #employeeTable th:nth-child(1),
        #employeeTable td:nth-child(1) { width: 3%; min-width: 2.5rem; } /* STT */
        
        #employeeTable th:nth-child(2),
        #employeeTable td:nth-child(2) { width: 6%; min-width: 5rem; } /* Mã NV */
        
        #employeeTable th:nth-child(3),
        #employeeTable td:nth-child(3) { width: 10%; min-width: 7rem; } /* Họ tên */
        
        #employeeTable th:nth-child(4),
        #employeeTable td:nth-child(4) { width: 8%; min-width: 6rem; } /* Username */
        
        #employeeTable th:nth-child(5),
        #employeeTable td:nth-child(5) { width: 12%; min-width: 9rem; } /* Email */
        
        #employeeTable th:nth-child(6),
        #employeeTable td:nth-child(6) { width: 7%; min-width: 6rem; text-align: center; } /* Điện thoại */
        
        #employeeTable th:nth-child(7),
        #employeeTable td:nth-child(7) { width: 7%; min-width: 5.5rem; text-align: center; } /* Vai trò */
        
        #employeeTable th:nth-child(8),
        #employeeTable td:nth-child(8) { width: 7%; min-width: 5rem; text-align: center; } /* Kho */
        
        #employeeTable th:nth-child(9),
        #employeeTable td:nth-child(9) { width: 9%; min-width: 6.5rem; text-align: center; } /* Trạng thái */
        
        #employeeTable th:nth-child(10),
        #employeeTable td:nth-child(10) { width: 10%; min-width: 7rem; text-align: center; } /* Đăng nhập cuối */
        
        #employeeTable th:nth-child(11),
        #employeeTable td:nth-child(11) { width: 11%; min-width: 10rem; text-align: center; } /* Thao tác */
        
        /* Text trong các cột không wrap */
        #employeeTable td:nth-child(7),
        #employeeTable td:nth-child(8),
        #employeeTable td:nth-child(9) {
            white-space: nowrap;
            overflow: hidden;
        }
        
        /* Responsive cho màn hình nhỏ */
        @media (max-width: 1400px) {
            #employeeTable {
                font-size: 0.75rem;
            }
            #employeeTable th,
            #employeeTable td {
                padding: 0.5rem 0.375rem;
            }
            .status-badge,
            .role-badge {
                font-size: 0.625rem;
                padding: 0.1875rem 0.375rem;
            }
        }
        
        @media (max-width: 1200px) {
            #employeeTable {
                font-size: 0.6875rem;
                table-layout: auto;
            }
            .action-btn {
                width: 1.75rem;
                height: 1.75rem;
            }
            .action-btn i {
                font-size: 0.75rem;
            }
            .status-badge,
            .role-badge {
                font-size: 0.5625rem;
                padding: 0.125rem 0.3125rem;
            }
        }
        
        .required-field::after {
            content: ' *';
            color: red;
        }
        
        .card-header-custom {
            background: linear-gradient(135deg, #224abe 0%, #224abe 100%);
            color: white;
        }
        
        .stats-card {
            border-left: 4px solid #5B8DB8;
            transition: all 0.3s;
        }
        
        .stats-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }
        
        .password-display {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            border: 2px dashed #5B8DB8;
            margin: 15px 0;
        }
        
        .password-display .password-text {
            font-size: 1.5rem;
            font-weight: bold;
            color: #5B8DB8;
            font-family: 'Courier New', monospace;
            letter-spacing: 3px;
        }
        
        .import-progress {
            display: none;
            margin-top: 15px;
        }
        
        .employee-code {
            font-family: 'Courier New', monospace;
            background: #e9ecef;
            padding: 2px 8px;
            border-radius: 4px;
            font-weight: 600;
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
                            <i class="fas fa-users text-primary"></i> Quản lý nhân viên
                        </h1>
                        <div>
                            <a href="helpers/tao_mau_nhan_vien.php?generate=template" class="btn btn-success btn-sm mr-2">
                                <i class="fas fa-download"></i> Tải file mẫu Excel
                            </a>
                            <button class="btn btn-info btn-sm mr-2" data-toggle="modal" data-target="#bulkImportModal">
                                <i class="fas fa-file-excel"></i> Import hàng loạt
                            </button>
                            <button class="btn btn-primary btn-sm" data-toggle="modal" data-target="#createEmployeeModal">
                                <i class="fas fa-user-plus"></i> Thêm nhân viên
                            </button>
                        </div>
                    </div>

                    <!-- Statistics Cards -->
                    <div class="row mb-4">
                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="card stats-card border-left-primary shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                                Tổng nhân viên</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800" id="totalEmployees">0</div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-users fa-2x text-gray-300"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="card stats-card border-left-success shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                                Đang hoạt động</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800" id="activeEmployees">0</div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-user-check fa-2x text-gray-300"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="card stats-card border-left-warning shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                                Chờ kích hoạt</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800" id="pendingEmployees">0</div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-user-clock fa-2x text-gray-300"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="card stats-card border-left-danger shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">
                                                Tạm khóa</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800" id="inactiveEmployees">0</div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-user-lock fa-2x text-gray-300"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- DataTable Card -->
                    <div class="card shadow mb-4">
                        <div class="card-header card-header-custom py-3 d-flex flex-row align-items-center justify-content-between">
                            <h6 class="m-0 font-weight-bold">
                                <i class="fas fa-list"></i> Danh sách nhân viên
                                <?php if ($warehouse): ?>
                                    - Kho: <strong><?php echo htmlspecialchars($warehouse['name']); ?></strong>
                                <?php endif; ?>
                            </h6>
                            <div class="dropdown no-arrow">
                                <button class="btn btn-sm btn-light" onclick="refreshTable()">
                                    <i class="fas fa-sync-alt"></i> Làm mới
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if ($userRole === 'admin'): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i>
                                <strong>Lưu ý:</strong> Bạn đang xem với quyền Admin. Chọn kho bên dưới để lọc nhân viên theo kho cụ thể, hoặc để trống để xem tất cả các kho.
                            </div>
                            <div class="row mb-3">
                                <div class="col-md-4">
                                    <label><i class="fas fa-warehouse"></i> Lọc theo kho:</label>
                                    <select class="form-control" id="filterWarehouse" onchange="refreshTable()">
                                        <option value="">-- Tất cả kho --</option>
                                        <?php foreach ($warehouses as $wh): ?>
                                            <option value="<?php echo $wh['warehouse_id']; ?>">
                                                <?php echo htmlspecialchars($wh['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <?php else: ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle"></i>
                                <strong>Warehouse Isolation:</strong> Bạn chỉ có thể xem và quản lý nhân viên thuộc kho 
                                <strong><?php echo htmlspecialchars($warehouse['name']); ?></strong>.
                            </div>
                            <?php endif; ?>
                            
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover" id="employeeTable" width="100%" cellspacing="0">
                                    <thead class="thead-light">
                                        <tr>
                                            <th>STT</th>
                                            <th>Mã NV</th>
                                            <th>Họ tên</th>
                                            <th>Username</th>
                                            <th>Email</th>
                                            <th>Điện thoại</th>
                                            <th>Vai trò</th>
                                            <th>Kho</th>
                                            <th>Trạng thái</th>
                                            <th>Đăng nhập cuối</th>
                                            <th>Thao tác</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <!-- Data will be loaded via AJAX -->
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
            <?php include 'includes/chan_trang.php'; ?>
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

    <!-- Create Employee Modal -->
    <div class="modal fade" id="createEmployeeModal" tabindex="-1" role="dialog" aria-labelledby="createEmployeeModalLabel">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="createEmployeeModalLabel">
                        <i class="fas fa-user-plus"></i> Thêm nhân viên mới
                    </h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <form id="createEmployeeForm">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="required-field">Họ và tên</label>
                                    <input type="text" class="form-control" name="full_name" id="createFullName" required>
                                    <small class="form-text text-muted">Nhập họ tên để tự động tạo username</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="required-field">Username</label>
                                    <input type="text" class="form-control" name="username" id="createUsername" required readonly
                                           pattern="[a-z0-9._]+" 
                                           title="Username được tự động tạo từ họ tên và mã kho">
                                    <small class="form-text text-muted">Tự động tạo từ họ tên + mã kho</small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="required-field">Email</label>
                                    <input type="email" class="form-control" name="email" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Điện thoại</label>
                                    <input type="text" class="form-control" name="phone" 
                                           pattern="[0-9]{10,11}" 
                                           title="Số điện thoại 10-11 chữ số">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="required-field">Vai trò</label>
                                    <select class="form-control" name="role" required>
                                        <option value="">-- Chọn vai trò --</option>
                                        <option value="staff">Nhân viên</option>
                                        <option value="manager">Quản lý</option>
                                        <?php if ($userRole === 'admin'): ?>
                                        <option value="admin">Admin</option>
                                        <?php endif; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="required-field">Kho</label>
                                    <select class="form-control" name="warehouse_id" id="createWarehouse" required>
                                        <?php if ($userRole === 'admin'): ?>
                                            <option value="">-- Chọn kho --</option>
                                            <?php foreach ($warehouses as $wh): ?>
                                                <option value="<?php echo $wh['warehouse_id']; ?>" 
                                                        data-warehouse-name="<?php echo htmlspecialchars($wh['name']); ?>">
                                                    <?php echo htmlspecialchars($wh['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <option value="<?php echo $userWarehouseId; ?>" 
                                                    data-warehouse-name="<?php echo htmlspecialchars($warehouse['name']); ?>" selected>
                                                <?php echo htmlspecialchars($warehouse['name']); ?>
                                            </option>
                                        <?php endif; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            <strong>Lưu ý:</strong> Hệ thống sẽ tự động tạo mật khẩu ngẫu nhiên và gửi email cho nhân viên.
                            Nhân viên sẽ phải đổi mật khẩu khi đăng nhập lần đầu.
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">
                        <i class="fas fa-times"></i> Hủy
                    </button>
                    <button type="button" class="btn btn-primary" onclick="createEmployee()">
                        <i class="fas fa-save"></i> Tạo nhân viên
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Employee Modal -->
    <div class="modal fade" id="editEmployeeModal" tabindex="-1" role="dialog" aria-labelledby="editEmployeeModalLabel">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editEmployeeModalLabel">
                        <i class="fas fa-user-edit"></i> Chỉnh sửa thông tin nhân viên
                    </h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <form id="editEmployeeForm">
                        <input type="hidden" name="user_id" id="edit_user_id">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Username</label>
                                    <input type="text" class="form-control" id="edit_username" disabled>
                                    <small class="text-muted">Username không thể thay đổi</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Mã nhân viên</label>
                                    <input type="text" class="form-control" id="edit_employee_code" disabled>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="required-field">Họ và tên</label>
                                    <input type="text" class="form-control" name="full_name" id="edit_full_name" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="required-field">Email</label>
                                    <input type="email" class="form-control" name="email" id="edit_email" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Điện thoại</label>
                                    <input type="text" class="form-control" name="phone" id="edit_phone">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="required-field">Vai trò</label>
                                    <select class="form-control" name="role" id="edit_role" required>
                                        <option value="staff">Nhân viên</option>
                                        <option value="manager">Quản lý</option>
                                        <?php if ($userRole === 'admin'): ?>
                                        <option value="admin">Admin</option>
                                        <?php endif; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <?php if ($userRole === 'admin'): ?>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="required-field">Kho</label>
                                    <select class="form-control" name="warehouse_id" id="edit_warehouse_id" required>
                                        <?php foreach ($warehouses as $wh): ?>
                                            <option value="<?php echo $wh['warehouse_id']; ?>">
                                                <?php echo htmlspecialchars($wh['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">
                        <i class="fas fa-times"></i> Hủy
                    </button>
                    <button type="button" class="btn btn-primary" onclick="updateEmployee()">
                        <i class="fas fa-save"></i> Cập nhật
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bulk Import Modal -->
    <div class="modal fade" id="bulkImportModal" tabindex="-1" role="dialog" aria-labelledby="bulkImportModalLabel">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="bulkImportModalLabel">
                        <i class="fas fa-file-excel"></i> Import nhân viên hàng loạt
                    </h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>Hướng dẫn:</strong>
                        <ol class="mb-0 mt-2">
                            <li>Tải file Excel mẫu từ nút "Tải file mẫu Excel"</li>
                            <li>Điền thông tin nhân viên vào file Excel (tối đa 100 nhân viên/lần)</li>
                            <li>Upload file đã điền thông tin</li>
                            <li>Kiểm tra kết quả import</li>
                        </ol>
                    </div>
                    
                    <form id="bulkImportForm">
                        <div class="form-group">
                            <label class="required-field">Chọn file Excel</label>
                            <div class="custom-file">
                                <input type="file" class="custom-file-input" id="excelFile" 
                                       accept=".xlsx,.xls" required>
                                <label class="custom-file-label" for="excelFile">Chọn file...</label>
                            </div>
                            <small class="form-text text-muted">
                                Chỉ chấp nhận file .xlsx hoặc .xls, tối đa 2MB
                            </small>
                        </div>
                        
                        <div class="form-group">
                            <label class="required-field">Kho</label>
                            <select class="form-control" name="warehouse_id" id="import_warehouse_id" required>
                                <?php if ($userRole === 'admin'): ?>
                                    <option value="">-- Chọn kho --</option>
                                    <?php foreach ($warehouses as $wh): ?>
                                        <option value="<?php echo $wh['warehouse_id']; ?>">
                                            <?php echo htmlspecialchars($wh['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <option value="<?php echo $userWarehouseId; ?>" selected>
                                        <?php echo htmlspecialchars($warehouse['name']); ?>
                                    </option>
                                <?php endif; ?>
                            </select>
                        </div>
                    </form>
                    
                    <div class="import-progress" id="importProgress">
                        <div class="progress">
                            <div class="progress-bar progress-bar-striped progress-bar-animated" 
                                 role="progressbar" style="width: 100%">
                                Đang xử lý...
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">
                        <i class="fas fa-times"></i> Hủy
                    </button>
                    <button type="button" class="btn btn-primary" onclick="bulkImportEmployees()">
                        <i class="fas fa-upload"></i> Import
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- View Details Modal -->
    <div class="modal fade" id="viewDetailsModal" tabindex="-1" role="dialog" aria-labelledby="viewDetailsModalLabel">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="viewDetailsModalLabel">
                        <i class="fas fa-info-circle"></i> Chi tiết nhân viên
                    </h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body" id="employeeDetailsContent">
                    <!-- Content will be loaded via AJAX -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">
                        <i class="fas fa-times"></i> Đóng
                    </button>
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

    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        let employeeTable;
        const userRole = '<?php echo $userRole; ?>';
        const userWarehouseId = <?php echo $userWarehouseId ?? 'null'; ?>;

        $(document).ready(function() {
            // Khởi tạo DataTable
            initializeDataTable();
            
            // Tải statistics
            loadStatistics();
            
            // Custom file input label
            $('.custom-file-input').on('change', function() {
                let fileName = $(this).val().split('\\').pop();
                $(this).next('.custom-file-label').html(fileName);
            });
            
            // Auto-generate username when full name or warehouse changes
            $('#createFullName, #createWarehouse').on('input change', function() {
                generateUsername();
            });
            
            // Kiểm tra username availability after generation
            let usernameCheckTimeout;
            $('#createUsername').on('input', function() {
                clearTimeout(usernameCheckTimeout);
                const username = $(this).val().trim();
                
                if (!username) {
                    $('#createUsername').removeClass('is-valid is-invalid');
                    return;
                }
                
                usernameCheckTimeout = setTimeout(function() {
                    checkUsernameAvailability(username);
                }, 500);
            });
            
            // Trigger initial username generation if warehouse is pre-selected
            if ($('#createWarehouse').val()) {
                generateUsername();
            }
        });

        function initializeDataTable() {
            employeeTable = $('#employeeTable').DataTable({
                "ajax": {
                    "url": "api_quan_ly_nhan_vien.php",
                    "type": "POST",
                    "data": function(d) {
                        const filterWarehouse = $('#filterWarehouse');
                        const warehouseValue = filterWarehouse.length ? filterWarehouse.val() : null;
                        
                        return JSON.stringify({
                            action: 'list',
                            warehouse_id: userRole === 'admin' ? (warehouseValue || null) : userWarehouseId,
                            search: d.search ? d.search.value : null
                        });
                    },
                    "contentType": "application/json",
                    "dataSrc": function(json) {
                        if (json.success) {
                            return json.data;
                        } else {
                            Swal.fire('Lỗi!', json.message, 'error');
                            return [];
                        }
                    }
                },
                "columns": [
                    { 
                        "data": null,
                        "render": function(data, type, row, meta) {
                            return meta.row + 1;
                        }
                    },
                    { 
                        "data": "employee_code",
                        "render": function(data) {
                            return data ? `<span class="employee-code">${data}</span>` : '<em>Chưa có</em>';
                        }
                    },
                    { 
                        "data": "full_name",
                        "render": function(data, type, row) {
                            return `<strong>${data}</strong>`;
                        }
                    },
                    { "data": "username" },
                    { "data": "email" },
                    { 
                        "data": "phone",
                        "render": function(data) {
                            return data || '<em>Chưa có</em>';
                        }
                    },
                    { 
                        "data": "role",
                        "render": function(data) {
                            const roleMap = {
                                'admin': '<span class="role-badge role-admin">ADMIN</span>',
                                'manager': '<span class="role-badge role-manager">QUẢN LÝ</span>',
                                'staff': '<span class="role-badge role-staff">NHÂN VIÊN</span>'
                            };
                            return roleMap[data] || data;
                        }
                    },
                    { 
                        "data": "warehouse_name",
                        "render": function(data) {
                            return data || '<em>Chưa có</em>';
                        }
                    },
                    { 
                        "data": "account_status",
                        "render": function(data) {
                            const statusMap = {
                                'active': '<span class="status-badge status-active">Hoạt động</span>',
                                'inactive': '<span class="status-badge status-inactive">Tạm khóa</span>',
                                'pending': '<span class="status-badge status-pending">Chờ kích hoạt</span>',
                                'locked': '<span class="status-badge status-locked">Bị khóa</span>'
                            };
                            return statusMap[data] || data;
                        }
                    },
                    { 
                        "data": "last_login",
                        "render": function(data) {
                            if (!data) return '<em>Chưa đăng nhập</em>';
                            return new Date(data).toLocaleString('vi-VN');
                        }
                    },
                    { 
                        "data": null,
                        "orderable": false,
                        "render": function(data, type, row) {
                            let buttons = '<div class="action-buttons-wrapper">';
                            
                            buttons += `
                                <button class="btn btn-sm btn-info action-btn" onclick="viewDetails(${row.user_id})" title="Xem chi tiết">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button class="btn btn-sm btn-primary action-btn" onclick="editEmployee(${row.user_id})" title="Chỉnh sửa">
                                    <i class="fas fa-edit"></i>
                                </button>
                            `;
                            
                            if (row.account_status === 'active') {
                                buttons += `
                                    <button class="btn btn-sm btn-warning action-btn" onclick="deactivateEmployee(${row.user_id})" title="Tạm khóa">
                                        <i class="fas fa-ban"></i>
                                    </button>
                                `;
                            } else if (row.account_status === 'inactive' || row.account_status === 'pending') {
                                buttons += `
                                    <button class="btn btn-sm btn-success action-btn" onclick="activateEmployee(${row.user_id})" title="Kích hoạt">
                                        <i class="fas fa-check"></i>
                                    </button>
                                `;
                            }
                            
                            buttons += '</div>';
                            return buttons;
                        }
                    }
                ],
                "language": {
                    "emptyTable": "Không có dữ liệu",
                    "info": "Hiển thị _START_ đến _END_ của _TOTAL_ bản ghi",
                    "infoEmpty": "Hiển thị 0 đến 0 của 0 bản ghi",
                    "infoFiltered": "(lọc từ _MAX_ bản ghi)",
                    "lengthMenu": "Hiển thị _MENU_ bản ghi",
                    "loadingRecords": "Đang tải...",
                    "processing": "Đang xử lý...",
                    "search": "Tìm kiếm:",
                    "zeroRecords": "Không tìm thấy bản ghi nào",
                    "paginate": {
                        "first": "Đầu",
                        "last": "Cuối",
                        "next": "Sau",
                        "previous": "Trước"
                    }
                },
                "pageLength": 25,
                "order": [[2, 'asc']],
                "processing": true
            });
        }

        function refreshTable() {
            employeeTable.ajax.reload();
            loadStatistics();
        }

        // ==================== AUTO GENERATE USERNAME ====================
        function generateUsername() {
            const fullName = $('#createFullName').val().trim();
            const warehouseSelect = $('#createWarehouse');
            const warehouseName = warehouseSelect.find('option:selected').data('warehouse-name') || '';
            
            if (!fullName || !warehouseName) {
                $('#createUsername').val('');
                return;
            }
            
            // Generate name initials (e.g., "Nguyễn Văn Trí" → "nvtri")
            const nameInitials = generateNameInitials(fullName);
            
            // Tạo warehouse code (e.g., "chuctest" → "chuctest")
            const warehouseCode = generateWarehouseCode(warehouseName);
            
            // Combine to create username
            const username = nameInitials + warehouseCode;
            
            $('#createUsername').val(username.toLowerCase());
            
            // Kiểm tra availability after generation
            if (username) {
                checkUsernameAvailability(username.toLowerCase());
            }
        }
        
        function checkUsernameAvailability(username) {
            $.ajax({
                url: 'api_quan_ly_nhan_vien.php',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    action: 'check-username',
                    username: username
                }),
                success: function(response) {
                    if (response.success) {
                        const usernameInput = $('#createUsername');
                        
                        if (response.available) {
                            usernameInput.removeClass('is-invalid').addClass('is-valid');
                            usernameInput.next('.invalid-feedback').remove();
                            usernameInput.next('.valid-feedback').remove();
                            usernameInput.after('<div class="valid-feedback">Username khả dụng ✓</div>');
                        } else {
                            usernameInput.removeClass('is-valid').addClass('is-invalid');
                            usernameInput.next('.invalid-feedback').remove();
                            usernameInput.next('.valid-feedback').remove();
                            
                            let feedbackHtml = '<div class="invalid-feedback">Username đã tồn tại.';
                            if (response.suggestions && response.suggestions.length > 0) {
                                feedbackHtml += ' Gợi ý: ' + response.suggestions.join(', ');
                            }
                            feedbackHtml += '</div>';
                            
                            usernameInput.after(feedbackHtml);
                        }
                    }
                },
                error: function() {
                    $('#createUsername').removeClass('is-valid is-invalid');
                }
            });
        }
        
        function generateNameInitials(fullName) {
            // Xóa Vietnamese tones and convert to lowercase
            const normalized = removeVietnameseTones(fullName).toLowerCase().trim();
            
            // Split into words
            const words = normalized.split(/\s+/).filter(word => word.length > 0);
            
            if (words.length === 0) return '';
            
            let initials = '';
            
            // Lấy first letter of first name(s)
            for (let i = 0; i < words.length - 1; i++) {
                initials += words[i].charAt(0);
            }
            
            // Thêm full last name (last word)
            if (words.length > 0) {
                initials += words[words.length - 1];
            }
            
            return initials;
        }
        
        function generateWarehouseCode(warehouseName) {
            // Xóa Vietnamese tones, spaces, and special characters
            const normalized = removeVietnameseTones(warehouseName).toLowerCase();
            return normalized.replace(/[^a-z0-9]/g, '');
        }
        
        function removeVietnameseTones(str) {
            const accents = {
                'à|á|ạ|ả|ã|â|ầ|ấ|ậ|ẩ|ẫ|ă|ằ|ắ|ặ|ẳ|ẵ': 'a',
                'è|é|ẹ|ẻ|ẽ|ê|ề|ế|ệ|ể|ễ': 'e',
                'ì|í|ị|ỉ|ĩ': 'i',
                'ò|ó|ọ|ỏ|õ|ô|ồ|ố|ộ|ổ|ỗ|ơ|ờ|ớ|ợ|ở|ỡ': 'o',
                'ù|ú|ụ|ủ|ũ|ư|ừ|ứ|ự|ử|ữ': 'u',
                'ỳ|ý|ỵ|ỷ|ỹ': 'y',
                'đ': 'd',
                'À|Á|Ạ|Ả|Ã|Â|Ầ|Ấ|Ậ|Ẩ|Ẫ|Ă|Ằ|Ắ|Ặ|Ẳ|Ẵ': 'A',
                'È|É|Ẹ|Ẻ|Ẽ|Ê|Ề|Ế|Ệ|Ể|Ễ': 'E',
                'Ì|Í|Ị|Ỉ|Ĩ': 'I',
                'Ò|Ó|Ọ|Ỏ|Õ|Ô|Ồ|Ố|Ộ|Ổ|Ỗ|Ơ|Ờ|Ớ|Ợ|Ở|Ỡ': 'O',
                'Ù|Ú|Ụ|Ủ|Ũ|Ư|Ừ|Ứ|Ự|Ử|Ữ': 'U',
                'Ỳ|Ý|Ỵ|Ỷ|Ỹ': 'Y',
                'Đ': 'D'
            };
            
            for (const [pattern, replacement] of Object.entries(accents)) {
                str = str.replace(new RegExp(`[${pattern}]`, 'g'), replacement);
            }
            
            return str;
        }

        function loadStatistics() {
            const filterWarehouse = $('#filterWarehouse');
            const warehouseValue = filterWarehouse.length ? filterWarehouse.val() : null;
            
            $.ajax({
                url: 'api_quan_ly_nhan_vien.php',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    action: 'list',
                    warehouse_id: userRole === 'admin' ? (warehouseValue || null) : userWarehouseId
                }),
                success: function(response) {
                    if (response.success) {
                        const employees = response.data;
                        $('#totalEmployees').text(employees.length);
                        $('#activeEmployees').text(employees.filter(e => e.account_status === 'active').length);
                        $('#pendingEmployees').text(employees.filter(e => e.account_status === 'pending').length);
                        $('#inactiveEmployees').text(employees.filter(e => e.account_status === 'inactive' || e.account_status === 'locked').length);
                    }
                }
            });
        }

        function createEmployee() {
            const formData = new FormData(document.getElementById('createEmployeeForm'));
            const data = {
                action: 'create',
                username: formData.get('username'),
                full_name: formData.get('full_name'),
                email: formData.get('email'),
                phone: formData.get('phone'),  // Correct field name
                role: formData.get('role'),
                warehouse_id: parseInt(formData.get('warehouse_id'))
            };

            // Gỡ lỗi logging
            console.log('Creating employee with data:', data);

            // Hiển thị loading ngay lập tức
            Swal.fire({
                title: 'Đang xử lý...',
                text: 'Vui lòng đợi trong giây lát',
                icon: 'info',
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            $.ajax({
                url: 'api_quan_ly_nhan_vien.php',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify(data),
                success: function(response) {
                    console.log('API Response:', response);
                    if (response.success) {
                        $('#createEmployeeModal').modal('hide');
                        
                        // Security enhancement: Don't display password on screen
                        // Password is only sent via email
                        Swal.fire({
                            title: 'Thành công!',
                            html: `
                                <p>${response.message}</p>
                                <div class="alert alert-info mt-3">
                                    <i class="fas fa-envelope"></i> 
                                    <strong>Thông tin đăng nhập đã được gửi qua email</strong>
                                    <p class="mb-0 mt-2 small">Nhân viên sẽ nhận được email chứa username và mật khẩu tạm thời để đăng nhập lần đầu.</p>
                                </div>
                            `,
                            icon: 'success',
                            confirmButtonText: 'OK'
                        }).then(() => {
                            // Tải lại datatable only, faster than full page reload
                            employeeTable.ajax.reload(null, false);
                        });
                    } else {
                        Swal.fire('Lỗi!', response.message, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('API Error:', {
                        status: xhr.status,
                        statusText: xhr.statusText,
                        responseText: xhr.responseText,
                        error: error
                    });
                    
                    let errorMessage = 'Không thể kết nối với server';
                    try {
                        const errorResponse = JSON.parse(xhr.responseText);
                        errorMessage = errorResponse.message || errorMessage;
                    } catch (e) {
                        // Keep default message
                    }
                    
                    Swal.fire('Lỗi!', errorMessage, 'error');
                }
            });
        }

        // UC_NV_S - Bước 1,2: Nhấn nút "Chỉnh sửa" và lấy thông tin chi tiết nhân viên
        function editEmployee(userId) {
            $.ajax({
                url: 'api_quan_ly_nhan_vien.php?action=detail&user_id=' + userId,
                method: 'GET',
                success: function(response) {
                    if (response.success) {
                        const emp = response.data;
                        // Bước 4: Hiển thị form chỉnh sửa với các thông tin hiện tại
                        $('#edit_user_id').val(emp.user_id);
                        $('#edit_username').val(emp.username); // Không cho sửa
                        $('#edit_employee_code').val(emp.employee_code || 'Chưa có'); // Không cho sửa
                        $('#edit_full_name').val(emp.full_name);
                        $('#edit_email').val(emp.email);
                        $('#edit_phone').val(emp.phone || '');
                        $('#edit_role').val(emp.role);
                        if (userRole === 'admin') {
                            $('#edit_warehouse_id').val(emp.warehouse_id);
                        }
                        $('#editEmployeeModal').modal('show');
                    } else {
                        Swal.fire('Lỗi!', response.message, 'error');
                    }
                },
                error: function(xhr) {
                    let errorMsg = 'Không thể tải thông tin nhân viên';
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        errorMsg = xhr.responseJSON.message;
                    }
                    Swal.fire('Lỗi!', errorMsg, 'error');
                }
            });
        }

        // UC_NV_S - Bước 6,7,8,9,10,11: Cập nhật thông tin nhân viên
        function updateEmployee() {
            const formData = new FormData(document.getElementById('editEmployeeForm'));
            
            // Validate form trước khi gửi (Luồng ngoại lệ 6b)
            const fullName = formData.get('full_name')?.trim();
            const email = formData.get('email')?.trim();
            const role = formData.get('role');
            
            if (!fullName || !email || !role) {
                Swal.fire('Lỗi!', 'Vui lòng điền đầy đủ thông tin hợp lệ', 'error');
                return;
            }
            
            // Kiểm tra email format
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                Swal.fire('Lỗi!', 'Email không đúng định dạng', 'error');
                return;
            }
            
            // Validate phone format (nếu có)
            const phone = formData.get('phone')?.trim();
            if (phone) {
                const phoneRegex = /^[0-9]{10,11}$/;
                if (!phoneRegex.test(phone)) {
                    Swal.fire('Lỗi!', 'Số điện thoại phải có 10-11 chữ số', 'error');
                    return;
                }
            }
            
            const data = {
                action: 'update',
                user_id: parseInt(formData.get('user_id')),
                full_name: fullName,
                email: email,
                phone: phone || '',
                role: role
            };

            if (userRole === 'admin') {
                data.warehouse_id = parseInt(formData.get('warehouse_id'));
            }

            // Bước 7,8,9,10: Gửi request cập nhật
            $.ajax({
                url: 'api_quan_ly_nhan_vien.php',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify(data),
                success: function(response) {
                    if (response.success) {
                        // Bước 11: Thông báo "Cập nhật thông tin thành công"
                        $('#editEmployeeModal').modal('hide');
                        Swal.fire({
                            title: 'Thành công!',
                            text: response.message,
                            icon: 'success',
                            confirmButtonText: 'OK'
                        }).then(() => {
                            // Làm mới danh sách nhân viên
                            employeeTable.ajax.reload(null, false);
                            loadStatistics();
                        });
                    } else {
                        // Luồng thay thế 8a hoặc Luồng ngoại lệ 6c
                        Swal.fire('Lỗi!', response.message, 'error');
                    }
                },
                error: function(xhr) {
                    let errorMsg = 'Không thể cập nhật thông tin nhân viên';
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        errorMsg = xhr.responseJSON.message;
                    }
                    Swal.fire('Lỗi!', errorMsg, 'error');
                }
            });
        }

        function activateEmployee(userId) {
            Swal.fire({
                title: 'Xác nhận kích hoạt?',
                text: 'Nhân viên này sẽ được kích hoạt lại và có thể đăng nhập.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Kích hoạt',
                cancelButtonText: 'Hủy'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.ajax({
                        url: 'api_quan_ly_nhan_vien.php',
                        method: 'POST',
                        contentType: 'application/json',
                        data: JSON.stringify({
                            action: 'activate',
                            user_id: userId
                        }),
                        success: function(response) {
                            if (response.success) {
                                Swal.fire({
                                    title: 'Thành công!',
                                    html: `
                                        <p>Đã kích hoạt tài khoản thành công. Email đã được gửi đến nhân viên.</p>
                                        <div class="alert alert-info mt-3">
                                            <i class="fas fa-info-circle"></i> 
                                            Mật khẩu tạm thời mới đã được gửi qua email đến nhân viên.
                                            <br><small>Vì lý do bảo mật, mật khẩu không hiển thị ở đây.</small>
                                        </div>
                                    `,
                                    icon: 'success',
                                    confirmButtonText: 'OK'
                                }).then(() => {
                                    employeeTable.ajax.reload(null, false);
                                });
                            } else {
                                Swal.fire('Lỗi!', response.message, 'error');
                            }
                        }
                    });
                }
            });
        }

        function deactivateEmployee(userId) {
            Swal.fire({
                title: 'Xác nhận tạm khóa?',
                text: 'Nhân viên này sẽ không thể đăng nhập vào hệ thống.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Tạm khóa',
                cancelButtonText: 'Hủy',
                confirmButtonColor: '#d33'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.ajax({
                        url: 'api_quan_ly_nhan_vien.php',
                        method: 'POST',
                        contentType: 'application/json',
                        data: JSON.stringify({
                            action: 'deactivate',
                            user_id: userId
                        }),
                        success: function(response) {
                            if (response.success) {
                                Swal.fire({
                                    title: 'Thành công!',
                                    text: response.message,
                                    icon: 'success',
                                    confirmButtonText: 'OK'
                                }).then(() => {
                                    employeeTable.ajax.reload(null, false);
                                });
                            } else {
                                Swal.fire('Lỗi!', response.message, 'error');
                            }
                        }
                    });
                }
            });
        }

        function viewDetails(userId) {
            $.ajax({
                url: 'api_quan_ly_nhan_vien.php',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    action: 'detail',
                    user_id: userId
                }),
                success: function(response) {
                    if (response.success) {
                        const emp = response.data;
                        const statusMap = {
                            'active': '<span class="status-badge status-active">Hoạt động</span>',
                            'inactive': '<span class="status-badge status-inactive">Tạm khóa</span>',
                            'pending': '<span class="status-badge status-pending">Chờ kích hoạt</span>',
                            'locked': '<span class="status-badge status-locked">Bị khóa</span>'
                        };
                        
                        const html = `
                            <div class="row">
                                <div class="col-md-6">
                                    <h6 class="text-primary">Thông tin cơ bản</h6>
                                    <table class="table table-sm">
                                        <tr><th width="40%">Mã NV:</th><td><span class="employee-code">${emp.employee_code || 'Chưa có'}</span></td></tr>
                                        <tr><th>Username:</th><td>${emp.username}</td></tr>
                                        <tr><th>Họ tên:</th><td><strong>${emp.full_name}</strong></td></tr>
                                        <tr><th>Email:</th><td>${emp.email}</td></tr>
                                        <tr><th>Điện thoại:</th><td>${emp.phone || 'Chưa có'}</td></tr>
                                        <tr><th>Vai trò:</th><td>${emp.role}</td></tr>
                                        <tr><th>Kho:</th><td>${emp.warehouse_name || 'Chưa có'}</td></tr>
                                    </table>
                                </div>
                                <div class="col-md-6">
                                    <h6 class="text-primary">Thông tin tài khoản</h6>
                                    <table class="table table-sm">
                                        <tr><th width="40%">Trạng thái:</th><td>${statusMap[emp.account_status] || emp.account_status}</td></tr>
                                        <tr><th>Đổi MK lần đầu:</th><td>${emp.must_change_password ? '<span class="badge badge-warning">Bắt buộc</span>' : '<span class="badge badge-success">Không</span>'}</td></tr>
                                        <tr><th>MK đổi lần cuối:</th><td>${emp.password_changed_at ? new Date(emp.password_changed_at).toLocaleString('vi-VN') : 'Chưa đổi'}</td></tr>
                                        <tr><th>Đăng nhập cuối:</th><td>${emp.last_login ? new Date(emp.last_login).toLocaleString('vi-VN') : 'Chưa đăng nhập'}</td></tr>
                                        <tr><th>Lần đăng nhập sai:</th><td>${emp.failed_login_attempts || 0}</td></tr>
                                        <tr><th>Khóa đến:</th><td>${emp.locked_until ? new Date(emp.locked_until).toLocaleString('vi-VN') : 'Không'}</td></tr>
                                        <tr><th>Tạo lúc:</th><td>${new Date(emp.created_at).toLocaleString('vi-VN')}</td></tr>
                                        <tr><th>Cập nhật lúc:</th><td>${new Date(emp.updated_at).toLocaleString('vi-VN')}</td></tr>
                                    </table>
                                </div>
                            </div>
                        `;
                        
                        $('#employeeDetailsContent').html(html);
                        $('#viewDetailsModal').modal('show');
                    } else {
                        Swal.fire('Lỗi!', response.message, 'error');
                    }
                }
            });
        }

        function bulkImportEmployees() {
            const fileInput = document.getElementById('excelFile');
            const warehouseId = document.getElementById('import_warehouse_id').value;
            
            if (!fileInput.files[0]) {
                Swal.fire('Lỗi!', 'Vui lòng chọn file Excel', 'error');
                return;
            }
            
            if (!warehouseId) {
                Swal.fire('Lỗi!', 'Vui lòng chọn kho', 'error');
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'bulk-import');
            formData.append('file', fileInput.files[0]);
            formData.append('warehouse_id', warehouseId);
            
            $('#importProgress').show();
            
            $.ajax({
                url: 'api_quan_ly_nhan_vien.php',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    $('#importProgress').hide();
                    $('#bulkImportModal').modal('hide');
                    
                    if (response.success) {
                        // response có thể có data hoặc trả về trực tiếp
                        const result = response.data || response;
                        let html = `
                            <div class="text-center">
                                <h4 class="text-success mb-3">Import thành công!</h4>
                                <div class="row">
                                    <div class="col-4">
                                        <div class="card">
                                            <div class="card-body">
                                                <h5 class="text-primary">${result.total || 0}</h5>
                                                <p class="mb-0">Tổng số</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-4">
                                        <div class="card">
                                            <div class="card-body">
                                                <h5 class="text-success">${result.success || result.created || 0}</h5>
                                                <p class="mb-0">Thành công</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-4">
                                        <div class="card">
                                            <div class="card-body">
                                                <h5 class="text-danger">${result.failed || 0}</h5>
                                                <p class="mb-0">Thất bại</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        `;
                        
                        if (result.errors && result.errors.length > 0) {
                            html += '<hr><h6 class="text-danger">Chi tiết lỗi:</h6><ul class="text-left">';
                            result.errors.forEach(err => {
                                html += `<li>${err}</li>`;
                            });
                            html += '</ul>';
                        }
                        
                        Swal.fire({
                            title: 'Kết quả Import',
                            html: html,
                            icon: (result.failed || 0) > 0 ? 'warning' : 'success',
                            width: '600px',
                            confirmButtonText: 'OK'
                        }).then(() => {
                            // Reload trang để cập nhật dữ liệu
                            window.location.reload();
                        });
                        
                        document.getElementById('bulkImportForm').reset();
                        $('.custom-file-label').html('Chọn file...');
                    } else {
                        Swal.fire('Lỗi!', response.message, 'error');
                    }
                },
                error: function() {
                    $('#importProgress').hide();
                    Swal.fire('Lỗi!', 'Không thể kết nối với server', 'error');
                }
            });
        }
    </script>

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

</body>
</html>
