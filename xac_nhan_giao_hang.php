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

// Lấy danh sách phiếu xuất đã đóng gói (completed) - chờ xác nhận xuất hàng
$sql = "
    SELECT 
        we.export_id,
        we.export_code,
        we.order_id,
        we.status,
        we.completed_at,
        we.created_at,
        we.updated_at,
        o.customer_id,
        o.status as order_status,
        o.discount,
        COALESCE(c.full_name, 'Khách lẻ') as customer_name,
        COALESCE(c.phone, '') as customer_phone,
        COALESCE(c.address, '') as customer_address,
        w.name as warehouse_name,
        cu.username as completed_by_name,
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
        ) as subtotal,
        -- Tính total_amount sau khi trừ giảm giá
        (
            SELECT SUM(wed.total_price) * (1 - COALESCE(o.discount, 0) / 100)
            FROM warehouse_export_details wed
            WHERE wed.export_id = we.export_id
        ) as total_amount
    FROM warehouse_exports we
    JOIN orders o ON we.order_id = o.order_id
    LEFT JOIN customers c ON o.customer_id = c.customer_id
    JOIN warehouses w ON we.warehouse_id = w.warehouse_id
    LEFT JOIN users cu ON we.completed_by = cu.user_id
    WHERE we.warehouse_id = :warehouse_id
    AND we.status = 'completed'
    AND (we.confirmed_delivery_at IS NULL OR we.confirmed_delivery_at = '0000-00-00 00:00:00')
    ORDER BY we.completed_at DESC
";

$stmt = $pdo->prepare($sql);
$stmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
$stmt->execute();
$exports = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Thống kê
$stats = [
    'total_ready' => count($exports),
    'total_value' => array_sum(array_column($exports, 'total_amount'))
];
?>

<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    
    <title>Xác nhận xuất hàng - <?php echo htmlspecialchars($warehouseName); ?></title>

    <!-- Custom fonts for this template -->
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">

    <!-- Custom styles for this template -->
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    
    <!-- Page level plugin CSS-->
    <link href="vendor/datatables/dataTables.bootstrap4.min.css" rel="stylesheet">
    
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <!-- Animate.css for smooth animations -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    
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
        .status-ready { 
            background-color: #218838 !important; /* Updated to dark green */
            color: #ffffff !important;
            border: 1px solid #1e7e34; /* Sharper border */
        }
        .status-confirmed { 
            background-color: #224abe !important; /* Updated to blue */
            color: #ffffff !important;
            border: 1px solid #1a3a8f; /* Sharper border */
        }
        
        .action-buttons .btn {
            margin-right: 5px;
            margin-bottom: 5px;
        }
        
        .card-header {
            background: linear-gradient(135deg, #224abe 0%, #1a3a8f 100%); /* Updated to blue gradient */
            color: #ffffff; /* Ensured white text for clarity */
        }
        
        .stats-card {
            border-left: 4px solid #1cc88a;
        }
        
        .confirm-btn {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            border: none;
            color: white;
            transition: all 0.3s ease;
        }
        
        .confirm-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            background: linear-gradient(135deg, #20c997 0%, #28a745 100%);
        }
        
        /* Custom styling for success notification */
        .swal2-popup {
            border-radius: 20px !important;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15) !important;
            padding: 2rem !important;
            max-width: 500px !important;
        }
        
        .swal2-title {
            font-size: 1.8rem !important;
            font-weight: 700 !important;
            color: #2c3e50 !important;
            margin-bottom: 1rem !important;
        }
        
        .success-container {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 20px;
            margin: -2rem -2rem 1.5rem -2rem;
            text-align: center;
        }
        
        .success-icon {
            background: rgba(255, 255, 255, 0.2);
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            backdrop-filter: blur(10px);
        }
        
        .success-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .success-subtitle {
            font-size: 0.95rem;
            opacity: 0.9;
        }
        
        .info-cards {
            display: grid;
            grid-template-columns: 1fr;
            gap: 12px;
            margin: 1.5rem 0;
        }
        
        .info-card {
            background: #ffffff; /* Updated to pure white for elegance */
            color: #000000; /* Black text for sharp contrast */
            border: 1px solid #d1d5db; /* Neutral gray border */
            border-radius: 12px;
            padding: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.3s ease;
        }
        
        .info-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }
        
        .info-label {
            font-weight: 600;
            color: #5a5c69;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .info-value {
            font-weight: 700;
            font-size: 1.1rem;
        }
        
        .info-value.primary { color: #007bff; }
        .info-value.secondary { color: #6c757d; }
        .info-value.success { color: #28a745; }
        
        .status-alert {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            border: 1px solid #28a745;
            color: #155724;
            padding: 1rem;
            border-radius: 12px;
            margin: 1.5rem 0;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 600;
        }
        
        .status-icon {
            background: #28a745;
            color: white;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
        }
        
        .custom-buttons {
            display: flex;
            gap: 12px;
            justify-content: center;
            margin-top: 1.5rem;
        }
        
        .btn-modern {
            border: none !important;
            border-radius: 12px !important;
            padding: 12px 24px !important;
            font-weight: 600 !important;
            font-size: 14px !important;
            transition: all 0.3s ease !important;
            min-width: 140px !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 8px !important;
        }
        
        .btn-primary-modern {
            background: linear-gradient(135deg, #007bff 0%, #0056b3 100%) !important;
            color: white !important;
        }
        
        .btn-primary-modern:hover {
            transform: translateY(-2px) !important;
            box-shadow: 0 8px 25px rgba(0, 123, 255, 0.3) !important;
        }
        
        .btn-secondary-modern {
            background: linear-gradient(135deg, #6c757d 0%, #545b62 100%) !important;
            color: white !important;
        }
        
        .btn-secondary-modern:hover {
            transform: translateY(-2px) !important;
            box-shadow: 0 8px 25px rgba(108, 117, 125, 0.3) !important;
        }
            color: white;
        }
        
        .detail-section {
            background-color: #f8f9fc;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 10px;
        }
        
        .highlight-row {
            background-color: #fff3cd !important;
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
                            <i class="fas fa-shipping-fast text-primary mr-2"></i>
                            Xác nhận xuất hàng
                        </h1>
                        <div>
                            <button class="btn btn-sm btn-outline-primary" onclick="refreshPage()">
                                <i class="fas fa-sync-alt"></i> Làm mới
                            </button>
                        </div>
                    </div>

                    <!-- Content Row - Stats -->
                    <div class="row">
                        <!-- Ready for Delivery Card -->
                        <div class="col-xl-6 col-md-6 mb-4">
                            <div class="card stats-card shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                                Phiếu chờ xác nhận</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $stats['total_ready']; ?></div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-clipboard-check fa-2x text-success"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Total Value Card -->
                        <div class="col-xl-6 col-md-6 mb-4">
                            <div class="card border-left-info shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                                Tổng giá trị chờ xuất</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                                <?php echo number_format($stats['total_value'], 0, ',', '.'); ?>đ
                                            </div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-dollar-sign fa-2x text-info"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Hướng dẫn sử dụng -->
                    <div class="card border-left-warning shadow mb-4">
                        <div class="card-body">
                            <div class="row">
                                <div class="col-lg-12">
                                    <h6 class="font-weight-bold text-warning">
                                        <i class="fas fa-info-circle mr-2"></i>
                                        Hướng dẫn xác nhận xuất hàng
                                    </h6>
                                    <ul class="text-gray-600 mb-0">
                                        <li><strong>Tiền điều kiện:</strong> Phiếu xuất phải ở trạng thái "Đã đóng gói"</li>
                                        <li><strong>Khi xác nhận:</strong> Hệ thống sẽ giảm tồn kho thực tế và chuyển đơn hàng sang trạng thái "Chờ giao hàng"</li>
                                        <li><strong>Lưu ý:</strong> Sau khi xác nhận, hàng chính thức rời kho và không thể hoàn tác</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- DataTales Example -->
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-white">
                                <i class="fas fa-list-alt mr-2"></i>
                                Danh sách phiếu xuất chờ xác nhận
                            </h6>
                        </div>
                        <div class="card-body">
                            <?php if (empty($exports)): ?>
                            <div class="text-center py-4">
                                <i class="fas fa-inbox fa-3x text-gray-300 mb-3"></i>
                                <h5 class="text-gray-500">Không có phiếu xuất nào chờ xác nhận</h5>
                                <p class="text-gray-400">
                                    Các phiếu xuất sau khi <strong>hoàn thành đóng gói</strong> sẽ tự động xuất hiện tại đây để xác nhận xuất hàng.
                                    <br>
                                    Vui lòng kiểm tra trang <a href="quan_ly_xuat_kho.php" class="text-primary">Quản lý phiếu xuất kho</a> để xử lý các phiếu chưa hoàn thành.
                                </p>
                            </div>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-bordered" id="deliveryTable" width="100%" cellspacing="0">
                                    <thead>
                                        <tr>
                                            <th>STT</th>
                                            <th>Mã phiếu</th>
                                            <th>Khách hàng</th>
                                            <th>Trạng thái</th>
                                            <th>Số lượng</th>
                                            <th>Tổng tiền</th>
                                            <th>Ngày đóng gói</th>
                                            <th>Người đóng gói</th>
                                            <th>Thao tác</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($exports as $index => $export): ?>
                                        <tr id="export-row-<?php echo $export['export_id']; ?>">
                                            <td class="text-center"><?php echo $index + 1; ?></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($export['export_code']); ?></strong>
                                                <br>
                                                <small class="text-muted">#<?php echo str_pad($export['order_id'], 6, '0', STR_PAD_LEFT); ?></small>
                                            </td>
                                            <td>
                                                <div class="detail-section">
                                                    <strong><?php echo htmlspecialchars($export['customer_name']); ?></strong>
                                                    <?php if ($export['customer_phone']): ?>
                                                    <br><small class="text-muted"><i class="fas fa-phone mr-1"></i><?php echo htmlspecialchars($export['customer_phone']); ?></small>
                                                    <?php endif; ?>
                                                    <?php if ($export['customer_address']): ?>
                                                    <br><small class="text-muted"><i class="fas fa-map-marker-alt mr-1"></i><?php echo htmlspecialchars($export['customer_address']); ?></small>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td class="text-center">
                                                <span class="status-badge status-ready">
                                                    <i class="fas fa-box mr-1"></i>Đã đóng gói
                                                </span>
                                            </td>
                                            <td>
                                                <div class="text-center">
                                                    <span class="badge badge-info badge-pill">
                                                        <?php echo $export['total_quantity']; ?> sản phẩm
                                                    </span>
                                                    <br>
                                                    <small class="text-muted"><?php echo $export['total_items']; ?> loại</small>
                                                </div>
                                            </td>
                                            <td>
                                                <strong class="text-success">
                                                    <?php echo number_format($export['total_amount'] ?? 0, 0, ',', '.'); ?>đ
                                                </strong>
                                            </td>
                                            <td>
                                                <?php echo $export['completed_at'] ? date('d/m/Y H:i', strtotime($export['completed_at'])) : '-'; ?>
                                            </td>
                                            <td>
                                                <?php echo $export['completed_by_name'] ? htmlspecialchars($export['completed_by_name']) : '-'; ?>
                                            </td>
                                            <td class="action-buttons">
                                                <!-- Chỉ Manager và Admin được phép xác nhận xuất hàng -->
                                                <?php if (in_array($userRole, ['admin', 'manager'])): ?>
                                                <button class="btn btn-sm confirm-btn" 
                                                        onclick="confirmDelivery(<?php echo $export['export_id']; ?>)" 
                                                        title="Xác nhận xuất hàng">
                                                    <i class="fas fa-shipping-fast"></i> Xác nhận xuất
                                                </button>
                                                
                                                <button class="btn btn-sm btn-danger" 
                                                        onclick="cancelExport(<?php echo $export['export_id']; ?>, '<?php echo htmlspecialchars($export['export_code'], ENT_QUOTES); ?>')" 
                                                        title="Hủy phiếu xuất">
                                                    <i class="fas fa-times"></i> Hủy
                                                </button>
                                                <?php else: ?>
                                                <button class="btn btn-sm btn-secondary" disabled
                                                        title="Bạn không có quyền xác nhận xuất hàng">
                                                    <i class="fas fa-lock"></i> Không có quyền
                                                </button>
                                                <?php endif; ?>
                                                
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
            var table = $('#deliveryTable').DataTable({
                "language": {
                    "sProcessing":   "Đang xử lý...",
                    "sLengthMenu":   "Xem _MENU_ mục",
                    "sZeroRecords":  "Không tìm thấy dòng nào phù hợp",
                    "sInfo":         "Đang xem _START_ đến _END_ trong tổng số _TOTAL_ mục",
                    "sInfoEmpty":    "Đang xem 0 đến 0 trong tổng số 0 mục",
                    "sInfoFiltered": "(được lọc từ _MAX_ mục)",
                    "sInfoPostFix":  "",
                    "sSearch":       "Tìm:",
                    "sUrl":          "",
                    "oPaginate": {
                        "sFirst":    "Đầu",
                        "sPrevious": "Trước",
                        "sNext":     "Tiếp",
                        "sLast":     "Cuối"
                    }
                },
                "order": [[6, "desc"]], // Sort by date (adjusted for STT column)
                "pageLength": 25,
                "columnDefs": [
                    { "orderable": false, "targets": [0, 8] } // Disable sorting for STT and action column
                ],
                "drawCallback": function() {
                    // Cập nhật số STT sau mỗi lần vẽ (sắp xếp, lọc, phân trang)
                    var api = this.api();
                    api.column(0, {search:'applied', order:'applied'}).nodes().each(function(cell, i) {
                        var pageInfo = api.page.info();
                        cell.innerHTML = pageInfo.start + i + 1;
                    });
                }
            });
        });
        
        function refreshPage() {
            location.reload();
        }
        
        // Hàm hủy phiếu xuất
        function cancelExport(exportId, exportCode) {
            console.log('cancelExport called with exportId:', exportId, 'exportCode:', exportCode);
            
            Swal.fire({
                title: 'Hủy phiếu xuất?',
                html: `
                    <div class="text-left">
                        <p><strong>Bạn có chắc chắn muốn hủy phiếu xuất ${exportCode}?</strong></p>
                        <div class="alert alert-warning">
                            <small>
                                <i class="fas fa-exclamation-triangle mr-2"></i>
                                <strong>Lưu ý:</strong> Sau khi hủy:
                                <ul class="mb-0 mt-2">
                                    <li>Phiếu xuất sẽ chuyển sang trạng thái "Đã hủy"</li>
                                    <li>Đơn hàng sẽ quay về trạng thái "Đang xử lý"</li>
                                    <li>Có thể tạo phiếu xuất mới cho đơn hàng này</li>
                                </ul>
                            </small>
                        </div>
                        <div class="form-group mt-3">
                            <label for="cancelReason"><strong>Lý do hủy:</strong></label>
                            <textarea id="cancelReason" class="form-control" rows="3" placeholder="Nhập lý do hủy phiếu xuất..."></textarea>
                        </div>
                    </div>
                `,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: '<i class="fas fa-times mr-2"></i>Xác nhận hủy',
                cancelButtonText: '<i class="fas fa-arrow-left mr-2"></i>Quay lại',
                showLoaderOnConfirm: true,
                preConfirm: () => {
                    const cancelReason = document.getElementById('cancelReason').value.trim();
                    
                    if (!cancelReason) {
                        Swal.showValidationMessage('Vui lòng nhập lý do hủy');
                        return false;
                    }
                    
                    const requestData = {
                        action: 'cancel_export',
                        export_id: exportId,
                        cancel_reason: cancelReason
                    };
                    
                    console.log('Sending cancel request:', requestData);
                    
                    return fetch('api_xac_nhan_giao_hang.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json'
                        },
                        body: JSON.stringify(requestData)
                    })
                    .then(response => {
                        console.log('Response status:', response.status);
                        if (!response.ok) {
                            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                        }
                        return response.text();
                    })
                    .then(text => {
                        console.log('Response text:', text);
                        try {
                            const data = JSON.parse(text);
                            console.log('Parsed JSON:', data);
                            if (!data.success) {
                                throw new Error(data.message || 'Unknown error');
                            }
                            return data;
                        } catch (jsonError) {
                            console.error('JSON parse error:', jsonError);
                            throw new Error('Invalid JSON response: ' + text.substring(0, 100));
                        }
                    })
                    .catch(error => {
                        console.error('API Error:', error);
                        Swal.showValidationMessage(`Lỗi: ${error.message}`);
                        return false;
                    });
                },
                allowOutsideClick: () => !Swal.isLoading()
            }).then((result) => {
                if (result.isConfirmed && result.value) {
                    Swal.fire({
                        title: 'Đã hủy!',
                        text: result.value.message || 'Phiếu xuất đã được hủy thành công',
                        icon: 'success',
                        confirmButtonColor: '#28a745',
                        confirmButtonText: '<i class="fas fa-check"></i> OK'
                    }).then(() => {
                        location.reload();
                    });
                }
            });
        }
        
        // Hàm xác nhận xuất hàng
        function confirmDelivery(exportId) {
            console.log('confirmDelivery called with exportId:', exportId);
            
            Swal.fire({
                title: 'Xác nhận xuất hàng',
                html: `
                    <div class="text-left">
                        <p><strong>Bạn có chắc chắn muốn xác nhận xuất hàng này?</strong></p>
                        <div class="alert alert-warning">
                            <small>
                                <i class="fas fa-exclamation-triangle mr-2"></i>
                                <strong>Lưu ý:</strong> Sau khi xác nhận:
                                <ul class="mb-0 mt-2">
                                    <li>Tồn kho thực tế sẽ được giảm</li>
                                    <li>Đơn hàng chuyển sang trạng thái "Chờ giao hàng"</li>
                                    <li>Hàng chính thức rời kho</li>
                                    <li>Không thể hoàn tác thao tác này</li>
                                </ul>
                            </small>
                        </div>
                    </div>
                `,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#28a745',
                cancelButtonColor: '#6c757d',
                confirmButtonText: '<i class="fas fa-shipping-fast mr-2"></i>Xác nhận xuất',
                cancelButtonText: '<i class="fas fa-times mr-2"></i>Hủy bỏ',
                showLoaderOnConfirm: true,
                preConfirm: () => {
                    const requestData = {
                        action: 'confirm_delivery',
                        export_id: exportId
                    };
                    
                    console.log('Sending API request:', requestData);
                    
                    return fetch('api_xac_nhan_giao_hang.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json'
                        },
                        body: JSON.stringify(requestData)
                    })
                    .then(response => {
                        console.log('Response status:', response.status);
                        console.log('Response headers:', response.headers);
                        
                        if (!response.ok) {
                            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                        }
                        
                        return response.text();
                    })
                    .then(text => {
                        console.log('Response text:', text);
                        
                        try {
                            const data = JSON.parse(text);
                            console.log('Parsed JSON:', data);
                            
                            if (!data.success) {
                                throw new Error(data.message || 'Unknown error from server');
                            }
                            return data;
                        } catch (jsonError) {
                            console.error('JSON parse error:', jsonError);
                            throw new Error('Invalid JSON response from server: ' + text.substring(0, 100));
                        }
                    })
                    .catch(error => {
                        console.error('API Error:', error);
                        Swal.showValidationMessage(`
                            <div style="text-align: left;">
                                <strong>Lỗi kết nối API:</strong><br>
                                ${error.message}<br><br>
                                <small>Vui lòng kiểm tra:</small>
                                <ul style="font-size: 12px; margin: 5px 0;">
                                    <li>Kết nối internet</li>
                                    <li>Trạng thái server</li>
                                    <li>Session đăng nhập</li>
                                </ul>
                                <small>Chi tiết lỗi đã được ghi trong Console (F12)</small>
                            </div>
                        `);
                        return false;
                    });
                },
                allowOutsideClick: () => !Swal.isLoading()
            }).then((result) => {
                if (result.isConfirmed && result.value) {
                    Swal.fire({
                        title: 'Thành công!',
                        html: `
                            <div class="success-container">
                                <div class="success-icon">
                                    <i class="fas fa-check" style="font-size: 2rem;"></i>
                                </div>
                                <div class="success-title">Thành công!</div>
                                <div class="success-subtitle">Đơn hàng đã được xác nhận xuất kho thành công</div>
                            </div>
                            
                            <div class="info-cards">
                                <div class="info-card">
                                    <div class="info-label">
                                        <i class="fas fa-file-alt" style="color: #007bff;"></i>
                                        Mã phiếu xuất:
                                    </div>
                                    <div class="info-value primary">${result.value.data?.export_code || 'N/A'}</div>
                                </div>
                                
                                <div class="info-card">
                                    <div class="info-label">
                                        <i class="fas fa-user" style="color: #6c757d;"></i>
                                        Khách hàng:
                                    </div>
                                    <div class="info-value secondary">${result.value.data?.customer_name || 'N/A'}</div>
                                </div>
                                
                                <div class="info-card">
                                    <div class="info-label">
                                        <i class="fas fa-boxes" style="color: #28a745;"></i>
                                        Số lượng:
                                    </div>
                                    <div class="info-value success">${result.value.data?.total_quantity || 'N/A'} sản phẩm</div>
                                </div>
                            </div>
                            
                            <div class="status-alert">
                                <div class="status-icon">
                                    <i class="fas fa-truck"></i>
                                </div>
                                <div>Đơn hàng đã chuyển sang trạng thái "Chờ giao hàng"</div>
                            </div>
                        `,
                        icon: null,
                        showCancelButton: true,
                        confirmButtonColor: '#007bff',
                        cancelButtonColor: '#6c757d',
                        confirmButtonText: '<i class="fas fa-list"></i> Danh sách đơn hàng',
                        cancelButtonText: '<i class="fas fa-times"></i> Đóng',
                        reverseButtons: true,
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        width: '520px',
                        customClass: {
                            confirmButton: 'btn-modern btn-primary-modern',
                            cancelButton: 'btn-modern btn-secondary-modern',
                            popup: 'custom-popup'
                        },
                        buttonsStyling: false,
                        showClass: {
                            popup: 'animate__animated animate__fadeInUp animate__faster'
                        },
                        hideClass: {
                            popup: 'animate__animated animate__fadeOutDown animate__faster'
                        }
                    }).then((buttonResult) => {
                        if (buttonResult.isConfirmed) {
                            // Chuyển sang trang danh sách đơn hàng
                            window.location.href = 'quan_ly_don_hang.php';
                        } else {
                            // Luôn reload trang để cập nhật danh sách
                            location.reload();
                        }
                    });
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