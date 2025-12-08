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

// Lấy role của user
$userRole = $_SESSION['role'] ?? 'employee'; // Mặc định là employee
error_log("Suppliers Management - User role: " . $userRole);

// Lấy thông tin warehouse
$warehouseName = 'Smart Warehouse';
if ($warehouseId) {
    $warehouseObj = new Warehouse($pdo);
    if ($warehouseObj->getById($warehouseId)) {
        $warehouseName = $warehouseObj->name;
    }
}

// Lấy danh sách nhà cung cấp
$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? '';

// Build query - chỉ lấy suppliers của warehouse hiện tại
$sql = "SELECT s.*, 
        u1.full_name as created_by_name,
        u2.full_name as updated_by_name
        FROM suppliers s
        LEFT JOIN users u1 ON s.created_by = u1.user_id
        LEFT JOIN users u2 ON s.updated_by = u2.user_id
        WHERE s.warehouse_id = ?";

$params = [$warehouseId];

if (!empty($search)) {
    $sql .= " AND (s.name LIKE ? OR s.supplier_code LIKE ? OR s.tax_code LIKE ? OR s.phone LIKE ? OR s.email LIKE ?)";
    $searchTerm = "%{$search}%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if (!empty($status)) {
    $sql .= " AND s.status = ?";
    $params[] = $status;
}

$sql .= " ORDER BY s.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Lấy thống kê - CHỈ CỦA WAREHOUSE HIỆN TẠI
$statsStmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive
    FROM suppliers
    WHERE warehouse_id = ?
");
$statsStmt->execute([$warehouseId]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    
    <title>Quản lý nhà cung cấp - <?php echo htmlspecialchars($warehouseName); ?></title>

    <!-- Custom fonts for this template -->
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">

    <!-- Custom styles for this template -->
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    
    <!-- Custom styles for this page -->
    <link href="vendor/datatables/dataTables.bootstrap4.min.css" rel="stylesheet">
    
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style>
        .card-header {
            background: linear-gradient(135deg, #224abe 0%, #1a3a8f 100%); /* Updated to blue gradient */
            color: white;
        }
        
        .stats-card {
            border: none;
            border-radius: 15px;
            transition: all 0.3s ease;
        }
        
        .stats-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .status-badge {
            font-size: 0.8em;
            padding: 0.4em 0.8em;
            border-radius: 20px;
        }
        
        .btn-add-supplier {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            border: none;
            color: white;
            font-weight: 600;
            padding: 10px 20px;
            border-radius: 25px;
            transition: all 0.3s ease;
        }
        
        .btn-add-supplier:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            color: white;
        }
        
        .table-hover tbody tr:hover {
            background-color: rgba(0,123,255,.1);
        }
        
        .action-buttons {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 5px;
            flex-wrap: nowrap;
        }
        
        .action-buttons .btn {
            margin: 0;
            padding: 6px 10px;
            font-size: 12px;
            border-radius: 4px;
            min-width: 32px;
            height: 32px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            white-space: nowrap;
            transition: all 0.2s ease;
        }
        
        .action-buttons .btn:hover {
            transform: scale(1.05);
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }
        
        .action-buttons .btn i {
            font-size: 13px;
        }
        
        /* Button specific colors */
        .btn-info {
            background-color: #17a2b8;
            border-color: #17a2b8;
        }
        
        .btn-warning {
            background-color: #224abe; /* Updated to blue */
            border-color: #224abe;
            color: white !important;
        }
        
        .btn-secondary {
            background-color: #dc3545; /* Updated to red */
            border-color: #dc3545;
            color: white; /* Ensure text is visible */
        }
        
        .btn-success {
            background-color: #218838; /* Updated to dark green */
            border-color: #218838;
            color: white; /* Ensure text is visible */
        }
        
        .search-card {
            border: none;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
        }
        
        /* Responsive adjustments for action buttons */
        @media (max-width: 768px) {
            .action-buttons {
                flex-direction: column;
                gap: 3px;
            }
            
            .action-buttons .btn {
                width: 100%;
                justify-content: flex-start;
                padding-left: 12px;
            }
            
            .action-buttons .btn i {
                margin-right: 5px;
            }
        }
        
        @media (max-width: 576px) {
            .table-responsive {
                font-size: 12px;
            }
            
            .action-buttons .btn {
                font-size: 11px;
                padding: 4px 8px;
                min-width: 28px;
                height: 28px;
            }
        }
        
        /* DataTable action column width fix */
        .dataTables_wrapper .dataTable td:last-child {
            white-space: nowrap;
        }
        
        .bulk-actions {
            background: #f5f5dc; /* Updated to beige */
            border: 1px solid #d1d5db; /* Neutral gray */
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
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
                            <i class="fas fa-building text-primary"></i>
                            Quản lý nhà cung cấp
                        </h1>
                        <a href="them_nha_cung_cap.php" class="btn btn-add-supplier">
                            <i class="fas fa-plus fa-sm"></i>
                            Thêm nhà cung cấp
                        </a>
                    </div>

                    <!-- Content Row - Stats -->
                    <div class="row">
                        <!-- Total Suppliers Card -->
                        <div class="col-xl-4 col-md-6 mb-4">
                            <div class="card stats-card shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                                Tổng số nhà cung cấp</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $stats['total']; ?></div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-building fa-2x text-gray-300"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Active Suppliers Card -->
                        <div class="col-xl-4 col-md-6 mb-4">
                            <div class="card border-left-success shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                                Đang hoạt động</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $stats['active']; ?></div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-check-circle fa-2x text-success"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Inactive Suppliers Card -->
                        <div class="col-xl-4 col-md-6 mb-4">
                            <div class="card border-left-danger shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">
                                                Tạm ngưng</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $stats['inactive']; ?></div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-pause-circle fa-2x text-danger"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Search and Filter Row -->
                    <div class="row">
                        <div class="col-12">
                            <div class="card search-card mb-4">
                                <div class="card-body">
                                    <form method="GET" class="d-flex align-items-center">
                                        <div class="col-md-4 px-2">
                                            <div class="input-group">
                                                <div class="input-group-prepend">
                                                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                                                </div>
                                                <input type="text" class="form-control" placeholder="Tìm kiếm NCC..." 
                                                       name="search" value="<?php echo htmlspecialchars($search); ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-3 px-2">
                                            <select name="status" class="form-control">
                                                <option value="">Tất cả trạng thái</option>
                                                <option value="active" <?php echo ($status == 'active') ? 'selected' : ''; ?>>Hoạt động</option>
                                                <option value="inactive" <?php echo ($status == 'inactive') ? 'selected' : ''; ?>>Tạm ngưng</option>
                                            </select>
                                        </div>
                                        <div class="col-md-2 px-2">
                                            <button type="submit" class="btn btn-primary btn-block">
                                                <i class="fas fa-filter"></i> Lọc
                                            </button>
                                        </div>
                                        <div class="col-md-2 px-2">
                                            <a href="quan_ly_nha_cung_cap.php" class="btn btn-secondary btn-block">
                                                <i class="fas fa-undo"></i> Reset
                                            </a>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Bulk Actions (Hidden by default) -->
                    <div id="bulkActions" class="bulk-actions d-none">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <strong><span id="bulkCount">0</span> nhà cung cấp được chọn</strong>
                            </div>
                            <div>
                                <?php if (in_array($userRole, ['admin', 'manager'])): ?>
                                <button class="btn btn-success btn-sm" onclick="bulkActivate()">
                                    <i class="fas fa-check"></i> Kích hoạt
                                </button>
                                <?php endif; ?>
                                <button class="btn btn-warning btn-sm" onclick="bulkDeactivate()">
                                    <i class="fas fa-pause"></i> Tạm ngưng
                                </button>
                                <button class="btn btn-info btn-sm" onclick="exportSelected()">
                                    <i class="fas fa-download"></i> Xuất dữ liệu
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- DataTales -->
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-white">
                                <i class="fas fa-list mr-1"></i>
                                Danh sách nhà cung cấp (<?php echo count($suppliers); ?> kết quả)
                            </h6>
                        </div>
                        <div class="card-body">
                            <?php if (empty($suppliers)): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-building fa-3x text-gray-300 mb-3"></i>
                                <h5 class="text-gray-500">Chưa có nhà cung cấp nào</h5>
                                <p class="text-gray-400">
                                    <?php if (!empty($search) || !empty($status)): ?>
                                        Không tìm thấy nhà cung cấp phù hợp với điều kiện tìm kiếm.
                                        <br>
                                        <a href="quan_ly_nha_cung_cap.php" class="text-primary">Xem tất cả nhà cung cấp</a>
                                    <?php else: ?>
                                        Hãy thêm nhà cung cấp đầu tiên để bắt đầu quản lý.
                                        <br>
                                        <a href="them_nha_cung_cap.php" class="btn btn-primary mt-3">
                                            <i class="fas fa-plus"></i> Thêm nhà cung cấp
                                        </a>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover" id="suppliersTable" width="100%" cellspacing="0">
                                    <thead>
                                        <tr>
                                            <th width="3%">
                                                <input type="checkbox" id="selectAll" onchange="selectAllSuppliers()">
                                            </th>
                                            <th width="3%">STT</th>
                                            <th width="12%">Mã NCC</th>
                                            <th width="18%">Tên nhà cung cấp</th>
                                            <th width="10%">Mã số thuế</th>
                                            <th width="15%">Liên hệ</th>
                                            <th width="16%">Địa chỉ</th>
                                            <th width="8%">Trạng thái</th>
                                            <th width="12%">Ngày tạo</th>
                                            <th width="15%" class="text-center">Thao tác</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($suppliers as $index => $supplier): ?>
                                        <tr>
                                            <td class="text-center">
                                                <input type="checkbox" class="supplier-checkbox" value="<?php echo $supplier['supplier_id']; ?>">
                                            </td>
                                            <td class="text-center"><?php echo $index + 1; ?></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($supplier['supplier_code']); ?></strong>
                                            </td>
                                            <td>
                                                <div>
                                                    <strong><?php echo htmlspecialchars($supplier['name']); ?></strong>
                                                    <?php if ($supplier['short_name']): ?>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars($supplier['short_name']); ?></small>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($supplier['tax_code']); ?></td>
                                            <td>
                                                <div>
                                                    <?php echo htmlspecialchars($supplier['phone']); ?>
                                                    <?php if ($supplier['email']): ?>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars($supplier['email']); ?></small>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <small>
                                                    <?php echo htmlspecialchars($supplier['address']); ?>
                                                    <?php if ($supplier['district'] && $supplier['province']): ?>
                                                    <br><?php echo htmlspecialchars($supplier['district'] . ', ' . $supplier['province']); ?>
                                                    <?php endif; ?>
                                                </small>
                                            </td>
                                            <td class="text-center">
                                                <span class="status-badge badge badge-<?php echo $supplier['status'] == 'active' ? 'success' : 'secondary'; ?>">
                                                    <?php echo $supplier['status'] == 'active' ? 'Hoạt động' : 'Tạm ngưng'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <small>
                                                    <?php echo date('d/m/Y H:i', strtotime($supplier['created_at'])); ?>
                                                    <?php if ($supplier['created_by_name']): ?>
                                                    <br><em class="text-muted">bởi <?php echo htmlspecialchars($supplier['created_by_name']); ?></em>
                                                    <?php endif; ?>
                                                </small>
                                            </td>
                                            <td class="text-center">
                                                <div class="action-buttons">
                                                    <button class="btn btn-info btn-sm" onclick="viewSupplier(<?php echo $supplier['supplier_id']; ?>)" title="Xem chi tiết">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <button class="btn btn-warning btn-sm" onclick="editSupplier(<?php echo $supplier['supplier_id']; ?>)" title="Chỉnh sửa">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <?php 
                                                    // Logic hiển thị nút kích hoạt/tạm ngưng:
                                                    // 1. Nếu status = 'active' → cho phép TẤT CẢ tạm ngưng (nút tạm ngưng)
                                                    // 2. Nếu status = 'inactive' → CHỈ admin/manager được kích hoạt lại (nút kích hoạt)
                                                    $isActive = ($supplier['status'] == 'active');
                                                    $isAdminOrManager = in_array($userRole, ['admin', 'manager']);
                                                    $canToggle = $isActive || ($isAdminOrManager && !$isActive);
                                                    
                                                    if ($canToggle):
                                                    ?>
                                                    <button class="btn btn-<?php echo $supplier['status'] == 'active' ? 'secondary' : 'success'; ?> btn-sm" 
                                                            onclick="toggleStatus(<?php echo $supplier['supplier_id']; ?>, '<?php echo $supplier['status']; ?>')" 
                                                            title="<?php echo $supplier['status'] == 'active' ? 'Tạm ngưng' : 'Kích hoạt'; ?>">
                                                        <i class="fas fa-<?php echo $supplier['status'] == 'active' ? 'pause' : 'play'; ?>"></i>
                                                    </button>
                                                    <?php else: ?>
                                                    <button class="btn btn-secondary btn-sm" disabled
                                                            title="Chỉ Admin/Quản lý mới có thể kích hoạt">
                                                        <i class="fas fa-lock"></i>
                                                    </button>
                                                    <?php endif; ?>
                                                    <button class="btn btn-outline-secondary btn-sm" onclick="exportSupplierInfo(<?php echo $supplier['supplier_id']; ?>)" title="Xuất thông tin">
                                                        <i class="fas fa-download"></i>
                                                    </button>
                                                </div>
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

    <!-- Page level plugins -->
    <script src="vendor/datatables/jquery.dataTables.min.js"></script>
    <script src="vendor/datatables/dataTables.bootstrap4.min.js"></script>

    <script>
        // User role from PHP session (for permission checks)
        const userRole = '<?php echo $userRole; ?>';
        const canActivateSuppliers = ['admin', 'manager'].includes(userRole);
        console.log('User role:', userRole, '| Can activate suppliers:', canActivateSuppliers);
        
        $(document).ready(function() {
            // Khởi tạo DataTable
            var table = $('#suppliersTable').DataTable({
                "language": {
                    "url": "//cdn.datatables.net/plug-ins/1.10.24/i18n/Vietnamese.json"
                },
                "pageLength": 25,
                "order": [[ 8, "desc" ]], // Sort by created_at (adjusted for checkbox and STT columns)
                "columnDefs": [
                    { "orderable": false, "targets": [0, 1, 9] } // Disable sorting for checkbox, STT and action column
                ],
                "drawCallback": function() {
                    // Cập nhật STT numbers after each draw (sort, filter, paginate)
                    this.api().column(1, {search:'applied', order:'applied'}).nodes().each(function(cell, i) {
                        var pageInfo = table.page.info();
                        cell.innerHTML = pageInfo.start + i + 1;
                    });
                }
            });
        });

        // View supplier details
        function viewSupplier(supplierId) {
            Swal.fire({
                title: '🏢 Chi tiết nhà cung cấp',
                html: '<div class="text-center"><i class="fas fa-spinner fa-spin fa-2x"></i><br>Đang tải thông tin...</div>',
                showConfirmButton: false,
                allowOutsideClick: false
            });

            // Tải supplier details via AJAX
            $.ajax({
                url: 'api/lay_nha_cung_cap.php',
                method: 'GET',
                data: { id: supplierId },
                success: function(response) {
                    if (response.success) {
                        const supplier = response.data;
                        Swal.fire({
                            title: '🏢 ' + supplier.name,
                            html: `
                                <div class="text-left" style="font-size: 14px;">
                                    <p><strong>📋 Mã NCC:</strong> ${supplier.supplier_code}</p>
                                    <p><strong>🏷️ Tên viết tắt:</strong> ${supplier.short_name || 'Không có'}</p>
                                    <p><strong>🆔 Mã số thuế:</strong> ${supplier.tax_code}</p>
                                    <p><strong>🏢 Loại hình:</strong> ${supplier.type || 'Không xác định'}</p>
                                    <p><strong>📍 Địa chỉ:</strong> ${supplier.address}</p>
                                    <p><strong>📞 Điện thoại:</strong> ${supplier.phone}</p>
                                    <p><strong>📧 Email:</strong> ${supplier.email || 'Không có'}</p>
                                    <p><strong>🌐 Website:</strong> ${supplier.website || 'Không có'}</p>
                                    <p><strong>👤 Người liên hệ:</strong> ${supplier.contact_person || 'Không có'}</p>
                                    <p><strong>💼 Chức vụ:</strong> ${supplier.contact_position || 'Không có'}</p>
                                    <p><strong>📝 Ghi chú:</strong> ${supplier.notes || 'Không có'}</p>
                                    <p><strong>⚡ Trạng thái:</strong> 
                                        <span class="badge badge-${supplier.status === 'active' ? 'success' : 'danger'}">
                                            ${supplier.status === 'active' ? 'Hoạt động' : 'Tạm ngưng'}
                                        </span>
                                    </p>
                                    <hr>
                                    <p><strong>📅 Ngày tạo:</strong> ${supplier.created_at}</p>
                                    <p><strong>👤 Người tạo:</strong> ${supplier.created_by_name || 'Không xác định'}</p>
                                    <p><strong>📅 Cập nhật:</strong> ${supplier.updated_at}</p>
                                    <p><strong>👤 Người cập nhật:</strong> ${supplier.updated_by_name || 'Không xác định'}</p>
                                </div>
                            `,
                            confirmButtonText: '✏️ Chỉnh sửa',
                            cancelButtonText: '❌ Đóng',
                            showCancelButton: true,
                            confirmButtonColor: '#ffc107'
                        }).then((result) => {
                            if (result.isConfirmed) {
                                editSupplier(supplierId);
                            }
                        });
                    } else {
                        Swal.fire('❌ Lỗi', response.message, 'error');
                    }
                },
                error: function() {
                    Swal.fire('❌ Lỗi', 'Không thể tải thông tin nhà cung cấp', 'error');
                }
            });
        }

        // Edit supplier
        function editSupplier(supplierId) {
            window.location.href = `them_nha_cung_cap.php?edit=${supplierId}`;
        }

        // Bật/tắt supplier status
        function toggleStatus(supplierId, currentStatus) {
            const newStatus = currentStatus === 'active' ? 'inactive' : 'active';
            const actionText = newStatus === 'active' ? 'kích hoạt' : 'tạm ngưng';
            
            // Kiểm tra quyền: chỉ admin/manager mới có thể kích hoạt lại
            if (newStatus === 'active' && !canActivateSuppliers) {
                Swal.fire({
                    icon: 'warning',
                    title: '⚠️ Không có quyền',
                    html: '<p>Chỉ <strong>Admin</strong> và <strong>Quản lý</strong> mới có thể kích hoạt lại nhà cung cấp.</p>' +
                          '<p class="text-muted mt-2">Vui lòng liên hệ quản lý để được hỗ trợ.</p>',
                    confirmButtonText: 'Đã hiểu',
                    confirmButtonColor: '#6c757d'
                });
                return;
            }
            
            Swal.fire({
                title: `🤔 Xác nhận ${actionText}`,
                text: `Bạn có chắc chắn muốn ${actionText} nhà cung cấp này?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: newStatus === 'active' ? '#28a745' : '#6c757d',
                cancelButtonColor: '#dc3545',
                confirmButtonText: `✅ ${actionText.charAt(0).toUpperCase() + actionText.slice(1)}`,
                cancelButtonText: '❌ Hủy'
            }).then((result) => {
                if (result.isConfirmed) {
                    // Cập nhật status via AJAX
                    $.ajax({
                        url: 'api/chuyen_doi_trang_thai_ncc.php',
                        method: 'POST',
                        data: { 
                            supplier_id: supplierId,
                            status: newStatus
                        },
                        success: function(response) {
                            if (response.success) {
                                Swal.fire(
                                    '✅ Thành công!',
                                    `Đã ${actionText} nhà cung cấp thành công.`,
                                    'success'
                                ).then(() => {
                                    location.reload();
                                });
                            } else {
                                Swal.fire('❌ Lỗi', response.message, 'error');
                            }
                        },
                        error: function() {
                            Swal.fire('❌ Lỗi', `Không thể ${actionText} nhà cung cấp`, 'error');
                        }
                    });
                }
            });
        }

        // Xuất supplier info function
        function exportSupplierInfo(supplierId) {
            Swal.fire({
                title: '📤 Xuất thông tin',
                text: 'Chọn định dạng xuất thông tin nhà cung cấp:',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: '📄 PDF',
                cancelButtonText: '📊 Excel',
                showDenyButton: true,
                denyButtonText: '❌ Hủy',
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#28a745',
                denyButtonColor: '#6c757d'
            }).then((result) => {
                if (result.isConfirmed) {
                    // Xuất to PDF
                    exportToPDF(supplierId);
                } else if (result.dismiss === Swal.DismissReason.cancel) {
                    // Xuất to Excel
                    exportToExcel(supplierId);
                }
            });
        }

        function exportToPDF(supplierId) {
            // Temporary alert since API not implemented yet
            Swal.fire('🚧 Chức năng đang phát triển', 'Xuất PDF sẽ sớm được cập nhật!', 'info');
            // window.open(`api/export_supplier_pdf.php?id=${supplierId}`, '_blank');
        }

        function exportToExcel(supplierId) {
            // Temporary alert since API not implemented yet  
            Swal.fire('🚧 Chức năng đang phát triển', 'Xuất Excel sẽ sớm được cập nhật!', 'info');
            // window.open(`api/export_supplier_excel.php?id=${supplierId}`, '_blank');
        }

        // Bulk actions
        function selectAllSuppliers() {
            $('.supplier-checkbox').prop('checked', $('#selectAll').is(':checked'));
            updateBulkActionButtons();
        }

        $(document).on('change', '.supplier-checkbox', function() {
            updateBulkActionButtons();
        });

        function updateBulkActionButtons() {
            const checkedCount = $('.supplier-checkbox:checked').length;
            if (checkedCount > 0) {
                $('#bulkActions').removeClass('d-none');
                $('#bulkCount').text(checkedCount);
            } else {
                $('#bulkActions').addClass('d-none');
            }
        }

        function bulkActivate() {
            // Kiểm tra quyền
            if (!canActivateSuppliers) {
                Swal.fire({
                    icon: 'warning',
                    title: '⚠️ Không có quyền',
                    html: '<p>Chỉ <strong>Admin</strong> và <strong>Quản lý</strong> mới có thể kích hoạt nhà cung cấp.</p>' +
                          '<p class="text-muted mt-2">Vui lòng liên hệ quản lý để được hỗ trợ.</p>',
                    confirmButtonText: 'Đã hiểu',
                    confirmButtonColor: '#6c757d'
                });
                return;
            }
            
            const selectedIds = $('.supplier-checkbox:checked').map(function() {
                return $(this).val();
            }).get();

            if (selectedIds.length === 0) {
                Swal.fire('⚠️ Thông báo', 'Vui lòng chọn ít nhất một nhà cung cấp', 'warning');
                return;
            }

            Swal.fire({
                title: '✅ Kích hoạt hàng loạt',
                text: `Kích hoạt ${selectedIds.length} nhà cung cấp đã chọn?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: '✅ Kích hoạt',
                cancelButtonText: '❌ Hủy'
            }).then((result) => {
                if (result.isConfirmed) {
                    bulkUpdateStatus(selectedIds, 'active');
                }
            });
        }

        function bulkDeactivate() {
            const selectedIds = $('.supplier-checkbox:checked').map(function() {
                return $(this).val();
            }).get();

            if (selectedIds.length === 0) {
                Swal.fire('⚠️ Thông báo', 'Vui lòng chọn ít nhất một nhà cung cấp', 'warning');
                return;
            }

            Swal.fire({
                title: '⏸️ Tạm ngưng hàng loạt',
                text: `Tạm ngưng ${selectedIds.length} nhà cung cấp đã chọn?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: '⏸️ Tạm ngưng',
                cancelButtonText: '❌ Hủy'
            }).then((result) => {
                if (result.isConfirmed) {
                    bulkUpdateStatus(selectedIds, 'inactive');
                }
            });
        }

        function bulkUpdateStatus(supplierIds, status) {
            // Temporary implementation - would need API
            Swal.fire('🚧 Chức năng đang phát triển', 'Cập nhật hàng loạt sẽ sớm được cập nhật!', 'info');
        }

        function exportSelected() {
            const selectedIds = $('.supplier-checkbox:checked').map(function() {
                return $(this).val();
            }).get();

            if (selectedIds.length === 0) {
                Swal.fire('⚠️ Thông báo', 'Vui lòng chọn ít nhất một nhà cung cấp', 'warning');
                return;
            }

            // Temporary implementation - would need API
            Swal.fire('🚧 Chức năng đang phát triển', 'Xuất dữ liệu hàng loạt sẽ sớm được cập nhật!', 'info');
        }
    </script>

</body>
</html>