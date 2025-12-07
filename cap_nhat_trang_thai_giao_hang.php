<?php
session_start();
require_once 'config/cau_hinh_csdl.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    header('Location: login.html');
    exit();
}

// Kết nối database
$database = new Database();
$pdo = $database->getConnection();

$userId = $_SESSION['user_id'];
$warehouseId = $_SESSION['warehouse_id'];

// Lấy tên kho
$warehouseName = 'Kho hàng';
if ($warehouseId) {
    $stmt = $pdo->prepare("SELECT name FROM warehouses WHERE warehouse_id = :warehouse_id");
    $stmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
    $stmt->execute();
    $warehouse = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($warehouse) {
        $warehouseName = $warehouse['name'];
    }
}

// Lấy danh sách đơn hàng chờ giao hàng
$sql = "
    SELECT 
        o.order_id,
        o.total_price,
        o.created_at,
        o.updated_at,
        c.customer_id,
        c.full_name as customer_name,
        c.phone as customer_phone,
        c.address as customer_address,
        w.name as warehouse_name,
        u.username as created_by_name,
        (
            SELECT we.export_id 
            FROM warehouse_exports we 
            WHERE we.order_id = o.order_id 
            ORDER BY we.created_at DESC 
            LIMIT 1
        ) as export_id,
        (
            SELECT we.export_code 
            FROM warehouse_exports we 
            WHERE we.order_id = o.order_id 
            ORDER BY we.created_at DESC 
            LIMIT 1
        ) as export_code,
        (
            SELECT we.completed_at 
            FROM warehouse_exports we 
            WHERE we.order_id = o.order_id 
            ORDER BY we.created_at DESC 
            LIMIT 1
        ) as export_completed_at,
        (
            SELECT COUNT(*)
            FROM order_details od
            WHERE od.order_id = o.order_id
        ) as total_items,
        (
            SELECT SUM(od.quantity)
            FROM order_details od
            WHERE od.order_id = o.order_id
        ) as total_quantity
    FROM orders o
    LEFT JOIN customers c ON o.customer_id = c.customer_id
    LEFT JOIN warehouses w ON o.warehouse_id = w.warehouse_id
    LEFT JOIN users u ON o.created_by = u.user_id
    WHERE o.status = 'waiting_delivery'
    AND o.warehouse_id = :warehouse_id
    ORDER BY o.updated_at DESC
";

$stmt = $pdo->prepare($sql);
$stmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
$stmt->execute();
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Thống kê
$stats = [
    'total_waiting' => count($orders),
    'total_value' => array_sum(array_column($orders, 'total_price'))
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

    <title>Cập nhật trạng thái giao hàng - <?php echo htmlspecialchars($warehouseName); ?></title>

    <!-- Custom fonts for this template -->
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">

    <!-- Custom styles for this template -->
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    
    <!-- Page level plugin CSS-->
    <link href="vendor/datatables/dataTables.bootstrap4.min.css" rel="stylesheet">
    
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style>
        .status-badge {
            font-size: 12px;
            padding: 4px 8px;
            border-radius: 12px;
            font-weight: 600;
        }
        .status-waiting {
            background-color: #ffc107; /* Reverted to original yellow */
            color: #212529; /* Original text color */
        }
        .status-delivered {
            background-color: #28a745; /* Reverted to original green */
            color: #ffffff;
        }
        .status-failed {
            background-color: #dc3545; /* Reverted to original red */
            color: #ffffff;
        }
        
        .action-buttons .btn {
            margin-right: 5px;
            margin-bottom: 5px;
        }
        
        .card-header {
            background: linear-gradient(135deg, #224abe 0%, #1a3a8f 100%); /* Updated to blue gradient */
            color: white;
        }
        
        .stats-card {
            border-left: 4px solid #ffc107;
        }
        
        .delivery-btn {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            border: none;
            color: white;
            transition: all 0.3s ease;
        }
        
        .delivery-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            background: linear-gradient(135deg, #20c997 0%, #28a745 100%);
            color: white;
        }
        
        .failed-btn {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            border: none;
            color: white;
            transition: all 0.3s ease;
        }
        
        .failed-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            background: linear-gradient(135deg, #c82333 0%, #dc3545 100%);
            color: white;
        }
        
        .order-info {
            background: #f8f9fc;
            border-radius: 8px;
            padding: 12px;  
            margin-bottom: 8px;
        }
        
        .customer-info {
            color: #000000; /* Updated to black */
        }
        
        .export-info {
            background: #f5f5dc; /* Updated to beige */
            border: 1px solid #d1d5db; /* Neutral gray */
            border-radius: 6px;
            padding: 8px;
            margin-top: 8px;
            font-size: 12px;
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
                            <i class="fas fa-shipping-fast text-warning mr-2"></i>
                            Cập nhật trạng thái giao hàng
                        </h1>
                        <div class="text-right">
                            <small class="text-muted">Kho: <?php echo htmlspecialchars($warehouseName); ?></small>
                        </div>
                    </div>

                    <!-- Statistics Cards -->
                    <div class="row mb-4">
                        <div class="col-xl-6 col-md-6 mb-4">
                            <div class="card border-left-warning shadow h-100 py-2 stats-card">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                                Đơn hàng chờ giao
                                            </div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                                <?php echo number_format($stats['total_waiting']); ?> đơn hàng
                                            </div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-clock fa-2x text-warning"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-xl-6 col-md-6 mb-4">
                            <div class="card border-left-success shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="row no-gutters align-items-center">
                                        <div class="col mr-2">
                                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                                Tổng giá trị chờ giao
                                            </div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                                <?php echo number_format($stats['total_value'], 0, ',', '.'); ?>₫
                                            </div>
                                        </div>
                                        <div class="col-auto">
                                            <i class="fas fa-dollar-sign fa-2x text-success"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Orders Table -->
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-white">
                                <i class="fas fa-list mr-2"></i>
                                Danh sách đơn hàng chờ giao hàng
                            </h6>
                        </div>
                        <div class="card-body">
                            <?php if (empty($orders)): ?>
                                <div class="text-center py-5">
                                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                    <h5 class="text-muted">Không có đơn hàng chờ giao</h5>
                                    <p class="text-muted mb-0">Tất cả đơn hàng đã được xử lý hoặc chưa có đơn hàng nào.</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-bordered" id="deliveryTable" width="100%" cellspacing="0">
                                        <thead>
                                            <tr>
                                                <th>STT</th>
                                                <th>Mã đơn hàng</th>
                                                <th>Khách hàng</th>
                                                <th>Số lượng</th>
                                                <th>Tổng giá</th>
                                                <th>Phiếu xuất</th>
                                                <th>Ngày cập nhật</th>
                                                <th>Hành động</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($orders as $index => $order): ?>
                                                <tr id="order-row-<?php echo $order['order_id']; ?>">
                                                    <td class="text-center"><?php echo $index + 1; ?></td>
                                                    <td>
                                                        <div class="order-info">
                                                            <strong>#<?php echo str_pad($order['order_id'], 6, '0', STR_PAD_LEFT); ?></strong>
                                                            <br>
                                                            <span class="status-badge status-waiting">Chờ giao hàng</span>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <div class="customer-info">
                                                            <strong><?php echo htmlspecialchars($order['customer_name']); ?></strong>
                                                            <br>
                                                            <i class="fas fa-phone text-muted mr-1"></i>
                                                            <?php echo htmlspecialchars($order['customer_phone']); ?>
                                                            <br>
                                                            <i class="fas fa-map-marker-alt text-muted mr-1"></i>
                                                            <small><?php echo htmlspecialchars($order['customer_address']); ?></small>
                                                        </div>
                                                    </td>
                                                    <td class="text-center">
                                                        <strong><?php echo number_format($order['total_quantity']); ?></strong>
                                                        <br>
                                                        <small class="text-muted"><?php echo $order['total_items']; ?> sản phẩm</small>
                                                    </td>
                                                    <td class="text-right">
                                                        <strong><?php echo number_format($order['total_price'], 0, ',', '.'); ?>₫</strong>
                                                    </td>
                                                    <td>
                                                        <?php if ($order['export_code']): ?>
                                                            <div class="export-info">
                                                                <i class="fas fa-file-export text-success mr-1"></i>
                                                                <strong><?php echo htmlspecialchars($order['export_code']); ?></strong>
                                                                <br>
                                                                <small>Hoàn thành: <?php echo date('d/m/Y H:i', strtotime($order['export_completed_at'])); ?></small>
                                                            </div>
                                                        <?php else: ?>
                                                            <span class="text-muted">Chưa có phiếu xuất</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td data-sort="<?php echo strtotime($order['updated_at']); ?>">
                                                        <small class="text-muted">
                                                            <?php echo date('d/m/Y H:i', strtotime($order['updated_at'])); ?>
                                                        </small>
                                                    </td>
                                                    <td>
                                                        <div class="action-buttons">
                                                            <button class="btn btn-sm delivery-btn" 
                                                                    onclick="updateDeliveryStatus(<?php echo $order['order_id']; ?>, 'delivered', '<?php echo htmlspecialchars($order['customer_name']); ?>')" 
                                                                    title="Đánh dấu đã giao thành công">
                                                                <i class="fas fa-check"></i> Giao thành công
                                                            </button>
                                                            <button class="btn btn-sm failed-btn" 
                                                                    onclick="updateDeliveryStatus(<?php echo $order['order_id']; ?>, 'failed', '<?php echo htmlspecialchars($order['customer_name']); ?>')" 
                                                                    title="Đánh dấu giao thất bại">
                                                                <i class="fas fa-times"></i> Giao thất bại
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
            <footer class="sticky-footer bg-white">
                <div class="container my-auto">
                    <div class="copyright text-center my-auto">
                        <span>Copyright &copy; Smart Warehouse System 2024</span>
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

    <!-- Page level custom scripts -->
    <script>
        $(document).ready(function() {
            var table = $('#deliveryTable').DataTable({
                "language": {
                    "search": "Tìm kiếm:",
                    "lengthMenu": "Hiển thị _MENU_ đơn hàng mỗi trang",
                    "info": "Hiển thị _START_ đến _END_ trong tổng số _TOTAL_ đơn hàng",
                    "infoEmpty": "Không có đơn hàng nào",
                    "infoFiltered": "(được lọc từ _MAX_ đơn hàng)",
                    "paginate": {
                        "first": "Đầu",
                        "last": "Cuối", 
                        "next": "Tiếp",
                        "previous": "Trước"
                    },
                    "emptyTable": "Không có dữ liệu trong bảng",
                    "zeroRecords": "Không tìm thấy kết quả phù hợp"
                },
                "order": [[ 6, "desc" ]], // Sort by updated date (adjusted for STT column)
                "pageLength": 10,
                "lengthMenu": [[10, 25, 50, -1], [10, 25, 50, "Tất cả"]],
                "responsive": true,
                "columnDefs": [
                    { "orderable": false, "targets": [0, 7] }, // Disable sorting for STT and Action columns
                    { 
                        "targets": [6], // Cột ngày cập nhật - sử dụng data-sort attribute
                        "type": "num" // Sắp xếp theo số (timestamp)
                    }
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

        function updateDeliveryStatus(orderId, newStatus, customerName) {
            const statusText = newStatus === 'delivered' ? 'giao thành công' : 'giao thất bại';
            const iconClass = newStatus === 'delivered' ? 'fa-check-circle' : 'fa-exclamation-triangle';
            const iconColor = newStatus === 'delivered' ? '#28a745' : '#dc3545';
            
            Swal.fire({
                title: `Xác nhận cập nhật trạng thái`,
                html: `
                    <div class="text-center">
                        <i class="fas ${iconClass}" style="color: ${iconColor}; font-size: 3rem; margin-bottom: 1rem;"></i>
                        <p class="mb-3">Bạn có chắc chắn muốn đánh dấu đơn hàng #${String(orderId).padStart(6, '0')} của khách hàng:</p>
                        <p class="font-weight-bold text-primary">${customerName}</p>
                        <p class="mb-3">là <span class="font-weight-bold" style="color: ${iconColor}">${statusText}</span>?</p>
                    </div>
                `,
                icon: newStatus === 'delivered' ? 'question' : 'warning',
                showCancelButton: true,
                confirmButtonColor: newStatus === 'delivered' ? '#28a745' : '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: `<i class="fas ${newStatus === 'delivered' ? 'fa-check' : 'fa-times'}"></i> Xác nhận ${statusText}`,
                cancelButtonText: '<i class="fas fa-times"></i> Hủy',
                reverseButtons: true,
                customClass: {
                    confirmButton: 'btn btn-lg mx-2',
                    cancelButton: 'btn btn-secondary btn-lg mx-2'
                },
                buttonsStyling: false,
                showLoaderOnConfirm: true,
                preConfirm: () => {
                    const requestData = {
                        action: 'update_delivery_status',
                        order_id: orderId,
                        status: newStatus
                    };
                    
                    console.log('Sending API request:', requestData);
                    
                    return fetch('api_cap_nhat_trang_thai_giao.php', {
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
                    const successIcon = newStatus === 'delivered' ? 'success' : 'info';
                    const successMessage = newStatus === 'delivered' ? 
                        `Đơn hàng #${String(orderId).padStart(6, '0')} đã được đánh dấu giao thành công!` :
                        `Đơn hàng #${String(orderId).padStart(6, '0')} đã được đánh dấu giao thất bại.`;
                    
                    Swal.fire({
                        title: 'Thành công!',
                        text: successMessage,
                        icon: successIcon,
                        confirmButtonColor: '#28a745',
                        confirmButtonText: 'OK'
                    }).then(() => {
                        // Remove the row from table
                        const $row = $('#order-row-' + orderId);
                        if ($row.length) {
                            $row.fadeOut(500, function() {
                                $(this).remove();
                                // If no more rows, reload page to show empty state
                                if ($('#deliveryTable tbody tr:visible').length === 0) {
                                    location.reload();
                                }
                            });
                        } else {
                            location.reload();
                        }
                    });
                }
            });
        }
    </script>

    <?php include 'includes/modal_dang_xuat.php'; ?>

</body>

</html>