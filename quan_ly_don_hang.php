<?php
session_start();
require_once 'config/cau_hinh_csdl.php';
require_once 'classes/Kho.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    header('Location: login.html');
    exit;
}

$database = new Database();
$pdo = $database->getConnection();

// Lấy thông tin user và warehouse
$userWarehouseId = $_SESSION['warehouse_id'] ?? null;
$userRole = $_SESSION['role'] ?? 'staff';
$warehouseName = 'Smart Warehouse';
if ($userWarehouseId) {
    $warehouseObj = new Warehouse($pdo);
    if ($warehouseObj->getById($userWarehouseId)) {
        $warehouseName = $warehouseObj->name;
    }
}

// Xử lý filter
$statusFilter = $_GET['status'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

// Build query conditions
$whereConditions = ['o.warehouse_id = :warehouse_id'];
$queryParams = [':warehouse_id' => $userWarehouseId];

if ($statusFilter) {
    $whereConditions[] = 'o.status = :status';
    $queryParams[':status'] = $statusFilter;
}

if ($dateFrom) {
    $whereConditions[] = 'DATE(o.created_at) >= :date_from';
    $queryParams[':date_from'] = $dateFrom;
}

if ($dateTo) {
    $whereConditions[] = 'DATE(o.created_at) <= :date_to';
    $queryParams[':date_to'] = $dateTo;
}

$whereClause = implode(' AND ', $whereConditions);

// Get orders with customer and product info
$ordersQuery = "
    SELECT 
        o.order_id,
        o.status,
        o.cancellation_reason,
        o.discount,
        o.total_price,
        o.created_at,
        o.updated_at,
        c.full_name as customer_name,
        c.phone as customer_phone,
        c.address as customer_address,
        COUNT(od.order_detail_id) as item_count,
        u.username as created_by_name
    FROM orders o
    LEFT JOIN customers c ON o.customer_id = c.customer_id
    LEFT JOIN order_details od ON o.order_id = od.order_id
    LEFT JOIN users u ON o.created_by = u.user_id
    WHERE $whereClause
    GROUP BY o.order_id
    ORDER BY o.created_at DESC
    LIMIT 50
";

$ordersStmt = $pdo->prepare($ordersQuery);
foreach ($queryParams as $key => $value) {
    $ordersStmt->bindValue($key, $value);
}
$ordersStmt->execute();
$orders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$statsQuery = "
    SELECT 
        COUNT(*) as total_orders,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_orders,
        SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) as confirmed_orders,
        SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered_orders,
        SUM(CASE WHEN status = 'canceled' THEN 1 ELSE 0 END) as cancelled_orders,
        SUM(total_price) as total_revenue
    FROM orders 
    WHERE warehouse_id = :warehouse_id
";

$statsStmt = $pdo->prepare($statsQuery);
$statsStmt->bindParam(':warehouse_id', $userWarehouseId);
$statsStmt->execute();
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// Status options theo UC_DH_XN
$statusOptions = [
    '' => 'Tất cả trạng thái',
    'pending' => 'Chờ duyệt',
    'accepted' => 'Chấp nhận',
    'canceled' => 'Đã hủy bỏ',
    'waiting_delivery' => 'Chờ giao hàng',
    'delivered' => 'Đã giao hàng',
    'failed' => 'Giao hàng thất bại'
];

$statusColors = [
    'pending' => 'warning',        // Vàng - Chờ duyệt
    'accepted' => 'info',          // Xanh nhạt - Chấp nhận
    'canceled' => 'danger',        // Đỏ - Đã hủy bỏ
    'waiting_delivery' => 'primary', // Xanh đậm - Chờ giao hàng
    'delivered' => 'success',      // Xanh lá - Đã giao hàng
    'failed' => 'secondary'        // Xám - Giao hàng thất bại
];
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="description" content="">
    <meta name="author" content="">
    
    <title>Quản lý đơn hàng - <?php echo htmlspecialchars($warehouseName); ?></title>

    <!-- Custom fonts for this template-->
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">
    
    <!-- Custom styles for this template-->
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    
    <!-- Custom styles for this page -->
    <link href="vendor/datatables/dataTables.bootstrap4.min.css" rel="stylesheet">

    <style>
        .order-status-badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
            font-weight: 600;
            border-radius: 0.375rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        /* Custom status colors for better distinction */
        .badge-warning {
            background-color: #ffc107 !important;
            color: #212529 !important;
        }
        
        .badge-info {
            background-color: #17a2b8 !important;
            color: white !important;
        }
        
        .badge-danger {
            background-color: #dc3545 !important;
            color: white !important;
        }
        
        .badge-primary {
            background-color: #007bff !important;
            color: white !important;
        }
        
        .badge-success {
            background-color: #28a745 !important;
            color: white !important;
        }
        
        .badge-secondary {
            background-color: #6c757d !important;
            color: white !important;
        }
        
        /* Action buttons with matching colors */
        .btn-status-pending {
            background-color: #ffc107;
            border-color: #ffc107;
            color: #212529;
        }
        
        .btn-status-accepted {
            background-color: #17a2b8;
            border-color: #17a2b8;
            color: white;
        }
        
        .btn-status-waiting {
            background-color: #007bff;
            border-color: #007bff;
            color: white;
        }
        
        .btn-status-delivered {
            background-color: #28a745;
            border-color: #28a745;
            color: white;
        }
        
        .btn-status-failed {
            background-color: #6c757d;
            border-color: #6c757d;
            color: white;
        }
        
        .btn-status-canceled {
            background-color: #dc3545;
            border-color: #dc3545;
            color: white;
        }
        .filter-form {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        /* Ensure labels on white inputs are dark for better contrast */
        .form-group label {
            color: #111827;
        }
        /* Also apply to labels used inside the filter form (e.g., Trạng thái, Từ ngày, Đến ngày) */
        .filter-form label {
            color: #111827;
        }
        .stats-card {
            border-left: 4px solid;
        }
        .stats-card.pending { border-left-color: #f6c23e; }
        .stats-card.accepted { border-left-color: #36b9cc; }
        .stats-card.delivered { border-left-color: #1cc88a; }
        .stats-card.canceled { border-left-color: #e74a3b; }
        .order-actions {
            white-space: nowrap;
        }
        .table-responsive {
            border-radius: 0.35rem;
        }
        
        /* Animation cho hiệu ứng thay đổi trạng thái */
        @keyframes flash {
            0% { background-color: #fff3cd; transform: scale(1); }
            50% { background-color: #ffc107; transform: scale(1.1); }
            100% { background-color: inherit; transform: scale(1); }
        }
        
        .order-status-badge {
            transition: all 0.3s ease;
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
                        <h1 class="h3 mb-0 text-gray-800">Quản lý đơn hàng</h1>
                        <a href="tao_don_hang.php" class="d-none d-sm-inline-block btn btn-sm btn-primary shadow-sm">
                            <i class="fas fa-plus fa-sm text-white-50"></i> Tạo đơn hàng mới
                        </a>
                    </div>

                    <!-- Statistics Cards -->
                    <div class="row">
                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="card stats-card pending shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                                Chờ duyệt</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($stats['pending_orders']); ?></div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-clock fa-2x text-warning-300"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="card stats-card accepted shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                                Đã duyệt</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($stats['confirmed_orders']); ?></div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-check-circle fa-2x text-info-300"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="card stats-card delivered shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                                Đã giao hàng</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($stats['delivered_orders']); ?></div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-shipping-fast fa-2x text-success-300"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="card stats-card canceled shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">
                                                Đã hủy</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($stats['cancelled_orders']); ?></div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-times-circle fa-2x text-danger-300"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Filter Form -->
                    <form method="GET" class="filter-form">
                        <div class="row">
                            <div class="col-md-3">
                                <label for="status">Trạng thái</label>
                                <select class="form-control" name="status" id="status">
                                    <?php foreach ($statusOptions as $value => $label): ?>
                                        <option value="<?php echo $value; ?>" <?php echo $statusFilter === $value ? 'selected' : ''; ?>>
                                            <?php echo $label; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="date_from">Từ ngày</label>
                                <input type="date" class="form-control" name="date_from" id="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>">
                            </div>
                            <div class="col-md-3">
                                <label for="date_to">Đến ngày</label>
                                <input type="date" class="form-control" name="date_to" id="date_to" value="<?php echo htmlspecialchars($dateTo); ?>">
                            </div>
                            <div class="col-md-3">
                                <label>&nbsp;</label><br>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-search"></i> Lọc
                                </button>
                                <a href="quan_ly_don_hang.php" class="btn btn-secondary">
                                    <i class="fas fa-refresh"></i> Reset
                                </a>
                            </div>
                        </div>
                    </form>

                    <!-- Status Color Guide -->
                    <div class="card mb-3">
                        <div class="card-body py-2">
                            <small class="text-muted">
                                <strong>Chú thích màu sắc:</strong>
                                <span class="badge badge-warning mx-1">Chờ duyệt</span>
                                <span class="badge badge-info mx-1">Chấp nhận</span>
                                <span class="badge badge-primary mx-1">Chờ giao hàng</span>
                                <span class="badge badge-success mx-1">Đã giao hàng</span>
                                <span class="badge badge-danger mx-1">Đã hủy bỏ</span>
                            </small>
                        </div>
                    </div>

                    <!-- Orders Table -->
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Danh sách đơn hàng</h6>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered" id="ordersTable" width="100%" cellspacing="0">
                                    <thead>
                                        <tr>
                                            <th>STT</th>
                                            <th>Mã đơn hàng</th>
                                            <th>Khách hàng</th>
                                            <th>Số điện thoại</th>
                                            <th>Số sản phẩm</th>
                                            <th>Tổng tiền</th>
                                            <th>Trạng thái</th>
                                            <th>Ngày tạo</th>
                                            <th>Người tạo</th>
                                            <th>Thao tác</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($orders as $index => $order): ?>
                                        <tr>
                                            <td class="text-center"><?php echo $index + 1; ?></td>
                                            <td>
                                                <strong>DH<?php echo str_pad($order['order_id'], 6, '0', STR_PAD_LEFT); ?></strong>
                                            </td>
                                            <td><?php echo htmlspecialchars($order['customer_name']); ?></td>
                                            <td><?php echo htmlspecialchars($order['customer_phone']); ?></td>
                                            <td>
                                                <span class="badge badge-info"><?php echo $order['item_count']; ?> sản phẩm</span>
                                            </td>
                                            <td><strong><?php echo number_format($order['total_price']); ?> VNĐ</strong></td>
                                            <td>
                                                <?php 
                                                $currentStatus = $order['status'] ?: 'pending'; // Nếu rỗng thì mặc định là pending
                                                $statusColor = $statusColors[$currentStatus] ?? 'secondary';
                                                $statusText = $statusOptions[$currentStatus] ?? ($currentStatus ?: 'Chưa xác định');
                                                ?>
                                                <span class="badge badge-<?php echo $statusColor; ?> order-status-badge">
                                                    <?php echo $statusText; ?>
                                                </span>
                                            </td>
                                            <td data-sort="<?php echo strtotime($order['created_at']); ?>"><?php echo date('d/m/Y H:i', strtotime($order['created_at'])); ?></td>
                                            <td><?php echo htmlspecialchars($order['created_by_name']); ?></td>
                                            <td class="order-actions">
                                                <!-- Nút xem chi tiết luôn hiển thị -->
                                                <button class="btn btn-sm btn-info" onclick="viewOrder(<?php echo $order['order_id']; ?>)" title="Xem chi tiết">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                
                                                <!-- Theo UC_DH_XN: Chỉ đơn hàng "chờ duyệt" mới có nút Chấp nhận/Hủy bỏ -->
                                                <!-- Chỉ Manager và Admin được phép xác nhận đơn hàng -->
                                                <?php if (($order['status'] === 'pending' || $order['status'] === '') && in_array($userRole, ['admin', 'manager'])): ?>
                                                <button class="btn btn-sm btn-status-accepted" onclick="confirmOrder(<?php echo $order['order_id']; ?>, 'accepted'); $('#confirmModal').modal('show');" title="Chấp nhận đơn hàng">
                                                    <i class="fas fa-check"></i> Chấp nhận
                                                </button>
                                                <button class="btn btn-sm btn-status-canceled" onclick="confirmOrder(<?php echo $order['order_id']; ?>, 'canceled'); $('#confirmModal').modal('show');" title="Hủy bỏ đơn hàng">
                                                    <i class="fas fa-times"></i> Hủy bỏ
                                                </button>
                                                
                                                <!-- Nhân viên (staff) chỉ xem được trạng thái -->
                                                <?php elseif (($order['status'] === 'pending' || $order['status'] === '') && $userRole === 'staff'): ?>
                                                <span class="badge badge-warning">Chờ duyệt (Không có quyền xác nhận)</span>
                                                
                                                <!-- Đơn hàng đã chấp nhận: điều hướng sang xử lý đơn xuất -->
                                                <?php elseif ($order['status'] === 'accepted'): ?>
                                                <a href="quan_ly_xuat_kho.php" class="btn btn-sm btn-success" title="Xử lý đơn xuất kho">
                                                    <i class="fas fa-file-export"></i> Xử lý xuất kho
                                                </a>
                                                
                                                <!-- Các trạng thái khác -->
                                                <?php elseif ($order['status'] === 'waiting_delivery'): ?>
                                                <a href="cap_nhat_trang_thai_giao_hang.php" class="btn btn-sm btn-status-waiting" title="Quản lý giao hàng">
                                                    <i class="fas fa-shipping-fast"></i> Quản lý giao hàng
                                                </a>
                                                
                                                <?php elseif ($order['status'] === 'canceled'): ?>
                                                <?php if (!empty($order['cancellation_reason'])): ?>
                                                <small class="text-muted"><i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($order['cancellation_reason']); ?></small>
                                                <?php endif; ?>
                                                
                                                <?php elseif ($order['status'] === 'delivered'): ?>
                                                <span class="badge badge-success">Đã hoàn thành</span>
                                                <?php endif; ?>
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

            <?php include 'includes/chan_trang.php'; ?>
        </div>
        <!-- End of Content Wrapper -->
    </div>
    <!-- End of Page Wrapper -->

    <!-- Scroll to Top Button-->
    <a class="scroll-to-top rounded" href="#page-top">
        <i class="fas fa-angle-up"></i>
    </a>

    <!-- Order Detail Modal -->
    <div class="modal fade" id="orderDetailModal" tabindex="-1" role="dialog" aria-labelledby="orderDetailModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="orderDetailModalLabel">Chi tiết đơn hàng</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body" id="orderDetailContent">
                    <!-- Content will be loaded here -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Đóng</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Confirm Order Modal theo UC_DH_XN -->
    <div class="modal fade" id="confirmModal" tabindex="-1" role="dialog" aria-labelledby="confirmModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="confirmModalLabel">Xác nhận thao tác</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body" id="confirmModalBody">
                    <!-- Content will be set by JavaScript -->
                </div>
                <div class="modal-footer" id="confirmModalFooter">
                    <!-- Buttons will be set by JavaScript -->
                </div>
            </div>
        </div>
    </div>

    <!-- Logout Modal-->
    <div class="modal fade" id="logoutModal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="exampleModalLabel">Xác nhận đăng xuất</h5>
                    <button class="close" type="button" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">×</span>
                    </button>
                </div>
                <div class="modal-body">Bạn có chắc chắn muốn đăng xuất?</div>
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
            var table = $('#ordersTable').DataTable({
                language: {
                    "sProcessing":   "Đang xử lý...",
                    "sLengthMenu":   "Hiển thị _MENU_ dòng",
                    "sZeroRecords":  "Không tìm thấy kết quả",
                    "sInfo":         "Hiển thị _START_ đến _END_ trong tổng số _TOTAL_ dòng",
                    "sInfoEmpty":    "Hiển thị 0 đến 0 trong tổng số 0 dòng",
                    "sInfoFiltered": "(lọc từ _MAX_ dòng)",
                    "sSearch":       "Tìm kiếm:",
                    "oPaginate": {
                        "sFirst":    "Đầu",
                        "sPrevious": "Trước",
                        "sNext":     "Tiếp",
                        "sLast":     "Cuối"
                    }
                },
                order: [[7, 'desc']], // Sort by created date desc (adjusted for STT column)
                columnDefs: [
                    { orderable: false, targets: [0, 9] }, // Disable sorting for STT and Actions columns
                    { 
                        targets: [7], // Cột ngày tạo - sử dụng data-sort attribute
                        type: 'num' // Sắp xếp theo số (timestamp)
                    }
                ],
                pageLength: 25,
                responsive: true,
                drawCallback: function() {
                    // Update STT numbers after each draw (sort, filter, paginate)
                    this.api().column(0, {search:'applied', order:'applied'}).nodes().each(function(cell, i) {
                        var pageInfo = table.page.info();
                        cell.innerHTML = pageInfo.start + i + 1;
                    });
                }
            });
            
            // Kiểm tra URL parameters để hiển thị thông báo thành công
            const urlParams = new URLSearchParams(window.location.search);
            const updatedOrderId = urlParams.get('updated');
            const newStatus = urlParams.get('status');
            
            if (updatedOrderId && newStatus) {
                const orderCode = 'DH' + String(updatedOrderId).padStart(6, '0');
                const statusText = newStatus === 'confirmed' ? 'Chấp nhận' : 
                                 newStatus === 'cancelled' ? 'Đã hủy bỏ' : newStatus;
                
                // Hiển thị thông báo thành công
                const alertDiv = document.createElement('div');
                alertDiv.className = 'alert alert-success alert-dismissible fade show';
                alertDiv.style.position = 'fixed';
                alertDiv.style.top = '20px';
                alertDiv.style.right = '20px';
                alertDiv.style.zIndex = '9999';
                alertDiv.style.minWidth = '300px';
                alertDiv.innerHTML = `
                    <strong>✅ Thành công!</strong><br>
                    Đơn hàng <strong>${orderCode}</strong> đã được cập nhật.<br>
                    Trạng thái: <span class="badge badge-${newStatus === 'confirmed' ? 'success' : 'danger'}">${statusText}</span>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                `;
                
                document.body.appendChild(alertDiv);
                
                // Highlight dòng đơn hàng vừa cập nhật
                setTimeout(() => {
                    const rows = document.querySelectorAll('tbody tr');
                    rows.forEach(row => {
                        const orderCell = row.querySelector('td strong');
                        if (orderCell && orderCell.textContent.includes(String(updatedOrderId).padStart(6, '0'))) {
                            row.style.backgroundColor = '#d4edda';
                            row.style.border = '2px solid #c3e6cb';
                            
                            // Scroll đến dòng này
                            row.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            
                            // Bỏ highlight sau 5 giây
                            setTimeout(() => {
                                row.style.backgroundColor = '';
                                row.style.border = '';
                            }, 5000);
                        }
                    });
                }, 500);
                
                // Xóa parameters khỏi URL sau 3 giây
                setTimeout(() => {
                    const newUrl = window.location.protocol + "//" + window.location.host + window.location.pathname;
                    window.history.replaceState({}, document.title, newUrl);
                }, 3000);
            }
        });

        function viewOrder(orderId) {
            $.ajax({
                url: 'api/lay_chi_tiet_don_hang.php',
                method: 'GET',
                data: { order_id: orderId },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        displayOrderDetail(response.data);
                        $('#orderDetailModal').modal('show');
                    } else {
                        alert('Không thể tải thông tin đơn hàng: ' + response.message);
                    }
                },
                error: function() {
                    alert('Lỗi khi tải thông tin đơn hàng');
                }
            });
        }

        function displayOrderDetail(orderData) {
            const statusOptions = {
                'pending': 'Chờ duyệt',
                'confirmed': 'Chấp nhận',
                'cancelled': 'Đã hủy bỏ',
                'waiting_delivery': 'Chờ giao hàng',
                'delivered': 'Đã giao hàng',
                'failed': 'Giao hàng thất bại'
            };

            const statusColors = {
                'pending': 'warning',
                'accepted': 'info',
                'canceled': 'danger',
                'waiting_delivery': 'primary',
                'delivered': 'success',
                'failed': 'secondary'
            };

            let html = `
                <div class="row">
                    <div class="col-md-6">
                        <h6>Thông tin đơn hàng</h6>
                        <table class="table table-sm">
                            <tr><td><strong>Mã đơn hàng:</strong></td><td>DH${String(orderData.order.order_id).padStart(6, '0')}</td></tr>
                            <tr><td><strong>Trạng thái:</strong></td><td><span class="badge badge-${statusColors[orderData.order.status]}">${statusOptions[orderData.order.status]}</span></td></tr>
                            <tr><td><strong>Chiết khấu:</strong></td><td>${orderData.order.discount}%</td></tr>
                            <tr><td><strong>Tổng tiền:</strong></td><td><strong>${formatCurrency(orderData.order.total_price)} VNĐ</strong></td></tr>
                            <tr><td><strong>Ngày tạo:</strong></td><td>${formatDateTime(orderData.order.created_at)}</td></tr>
                            <tr><td><strong>Người tạo:</strong></td><td>${orderData.order.created_by_name}</td></tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h6>Thông tin khách hàng</h6>
                        <table class="table table-sm">
                            <tr><td><strong>Họ tên:</strong></td><td>${orderData.customer.full_name}</td></tr>
                            <tr><td><strong>Số điện thoại:</strong></td><td>${orderData.customer.phone}</td></tr>
                            <tr><td><strong>Email:</strong></td><td>${orderData.customer.email || 'Không có'}</td></tr>
                            <tr><td><strong>Địa chỉ:</strong></td><td>${orderData.customer.address}</td></tr>
                            <tr><td><strong>Ghi chú:</strong></td><td>${orderData.customer.note || 'Không có'}</td></tr>
                        </table>
                    </div>
                </div>
                <hr>
                <h6>Chi tiết sản phẩm</h6>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered">
                        <thead class="thead-light">
                            <tr>
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
            `;

            orderData.details.forEach(function(detail) {
                html += `
                    <tr>
                        <td>${detail.product_name}</td>
                        <td>${detail.sku}</td>
                        <td>${detail.size}</td>
                        <td>${detail.color}</td>
                        <td>${detail.quantity}</td>
                        <td>${formatCurrency(detail.unit_price)} VNĐ</td>
                        <td><strong>${formatCurrency(detail.total_price)} VNĐ</strong></td>
                    </tr>
                `;
            });

            html += `
                        </tbody>
                    </table>
                </div>
            `;

            $('#orderDetailContent').html(html);
        }

        function updateOrderStatus(orderId, newStatus) {
            const statusText = {
                'confirmed': 'duyệt đơn hàng',
                'cancelled': 'hủy đơn hàng',
                'waiting_delivery': 'chuyển đơn sang giao hàng',
                'delivered': 'xác nhận đã giao hàng',
                'failed': 'xác nhận giao hàng thất bại'
            };

            // Show confirmation modal for critical actions
            if (newStatus === 'confirmed' || newStatus === 'cancelled') {
                showConfirmModal(orderId, newStatus === 'confirmed' ? 'accept' : 'reject');
                return;
            }

            const actionText = statusText[newStatus] || newStatus;
            if (confirm(`Bạn có chắc chắn muốn ${actionText}?`)) {
                $.ajax({
                    url: 'api/cap_nhat_trang_thai_don_hang.php',
                    method: 'POST',
                    data: {
                        order_id: orderId,
                        status: newStatus
                    },
                    dataType: 'json',
                    success: function(response) {
                        console.log('Response:', response);
                        if (response.success) {
                            location.reload();
                        } else {
                            alert('Lỗi: ' + response.message);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX Error:', xhr.responseText);
                        console.error('Status:', status);
                        console.error('Error:', error);
                        alert('Lỗi khi cập nhật trạng thái đơn hàng: ' + (xhr.responseJSON?.message || xhr.responseText || error));
                    }
                });
            }
        }

        // Functions for order confirmation according to UC_DH_XN

        function viewExportSlip(orderId) {
            window.open('phieu_xuat_kho.php?order_id=' + orderId, '_blank');
        }

        // Function xử lý modal xác nhận theo UC_DH_XN
        function confirmOrder(orderId, action) {
            const actionText = action === 'accepted' ? 'Chấp nhận' : 'Hủy bỏ';
            const actionColor = action === 'accepted' ? 'success' : 'danger';
            const actionIcon = action === 'accepted' ? 'fa-check' : 'fa-times';
            
            // Set modal content
            $('#confirmModalLabel').text('Xác nhận ' + actionText.toLowerCase() + ' đơn hàng');
            $('#confirmModalBody').html(`
                <div class="text-center">
                    <i class="fas ${actionIcon} fa-3x text-${actionColor} mb-3"></i>
                    <h5>Bạn có chắc chắn muốn ${actionText.toLowerCase()} đơn hàng DH${String(orderId).padStart(6, '0')}?</h5>
                    ${action === 'accepted' ? 
                        '<p class="text-muted">Hệ thống sẽ tự động tạo phiếu xuất kho và cập nhật tồn kho.</p>' : 
                        '<p class="text-muted">Đơn hàng sẽ chuyển sang trạng thái "Hủy bỏ".</p>'
                    }
                </div>
            `);
            
            $('#confirmModalFooter').html(`
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Không</button>
                <button type="button" class="btn btn-${actionColor}" onclick="executeConfirmOrder(${orderId}, '${action}')">
                    <i class="fas ${actionIcon}"></i> ${actionText}
                </button>
            `);
        }

        // Function thực hiện xác nhận đơn hàng theo UC_DH_XN
        function executeConfirmOrder(orderId, status) {
            // Show loading
            $('#confirmModalFooter').html(`
                <div class="text-center">
                    <i class="fas fa-spinner fa-spin"></i> Đang xử lý...
                </div>
            `);

            $.ajax({
                url: 'api/xac_nhan_don_hang.php',
                method: 'POST',
                data: JSON.stringify({
                    order_id: orderId,
                    status: status
                }),
                contentType: 'application/json',
                dataType: 'json',
                success: function(response) {
                    $('#confirmModal').modal('hide');
                    
                    if (response.success) {
                        if (status === 'accepted') {
                            alert('🎉 Đơn hàng đã được CHẤP NHẬN thành công!\n\n' +
                                  '✅ Trạng thái: Chờ duyệt → Chấp nhận\n' +
                                  '📄 Phiếu xuất kho: Đã tạo tự động\n' +
                                  '📦 Tồn kho: Đã cập nhật\n' +
                                  '🔍 Vui lòng kiểm tra phiếu xuất kho');
                        } else {
                            alert('❌ Đơn hàng đã được HỦY BỎ!\n\n' +
                                  '✅ Trạng thái: Chờ duyệt → Đã hủy bỏ');
                        }
                        
                        // Cập nhật trực tiếp DOM thay vì reload
                        updateOrderRowStatus(orderId, status);
                        
                        // Sau đó reload để đảm bảo đồng bộ
                        setTimeout(() => {
                            window.location.href = window.location.href + '?updated=' + orderId + '&status=' + status + '&t=' + Date.now();
                        }, 2000);
                    } else {
                        alert('❌ Lỗi: ' + response.message);
                    }
                },
                error: function(xhr, status, error) {
                    $('#confirmModal').modal('hide');
                    console.error('AJAX Error:', {xhr, status, error});
                    alert('❌ Lỗi kết nối khi xác nhận đơn hàng!\nVui lòng thử lại sau.');
                }
            });
        }

        function formatCurrency(amount) {
            return new Intl.NumberFormat('vi-VN').format(amount);
        }

        function formatDateTime(dateTimeString) {
            const date = new Date(dateTimeString);
            return date.toLocaleString('vi-VN');
        }

        // Hàm cập nhật trạng thái trực tiếp trên giao diện
        function updateOrderRowStatus(orderId, newStatus) {
            const statusLabels = {
                'pending': 'Chờ duyệt',
                'accepted': 'Chấp nhận',
                'canceled': 'Đã hủy bỏ',
                'waiting_delivery': 'Chờ giao hàng',
                'delivered': 'Đã giao hàng',
                'failed': 'Giao hàng thất bại'
            };
            
            const statusColors = {
                'pending': 'warning',
                'accepted': 'info',
                'canceled': 'danger',
                'waiting_delivery': 'primary',
                'delivered': 'success',
                'failed': 'secondary'
            };
            
            // Tìm row của đơn hàng
            const rows = document.querySelectorAll('tbody tr');
            rows.forEach(row => {
                const orderCell = row.querySelector('td strong');
                if (orderCell && orderCell.textContent.includes(String(orderId).padStart(6, '0'))) {
                    // Cập nhật badge trạng thái
                    const statusBadge = row.querySelector('.order-status-badge');
                    if (statusBadge) {
                        // Xóa class cũ và thêm class mới
                        statusBadge.className = `badge badge-${statusColors[newStatus]} order-status-badge`;
                        statusBadge.textContent = statusLabels[newStatus];
                        
                        // Hiệu ứng flash để user thấy thay đổi
                        statusBadge.style.animation = 'flash 2s ease-in-out';
                    }
                    
                    // Cập nhật các nút action
                    const actionsCell = row.querySelector('.order-actions');
                    if (actionsCell && newStatus === 'accepted') {
                        // Xóa nút Chấp nhận/Hủy bỏ và thêm nút xem phiếu xuất
                        const confirmBtn = actionsCell.querySelector('.btn-success');
                        const cancelBtn = actionsCell.querySelector('.btn-danger');
                        
                        if (confirmBtn) confirmBtn.remove();
                        if (cancelBtn) cancelBtn.remove();
                        
                        // Thêm nút xem phiếu xuất kho
                        const exportBtn = document.createElement('button');
                        exportBtn.className = 'btn btn-sm btn-outline-primary';
                        exportBtn.innerHTML = '<i class="fas fa-file-export"></i> Phiếu xuất';
                        exportBtn.onclick = () => window.open(`phieu_xuat_kho.php?order_id=${orderId}`, '_blank');
                        actionsCell.appendChild(exportBtn);
                    }
                }
            });
        }
    </script>

<?php include 'includes/chan_trang.php'; ?>
</html>