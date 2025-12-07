<?php
session_start();
require_once 'config/cau_hinh_csdl.php';
require_once 'helpers/GhiNhatKyKiemToan.php';
require_once 'classes/LichSuPhieuNhap.php';
require_once 'classes/QuanLyMaQR.php';
require_once 'classes/TaoMaQR.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    header('Location: login.html');
    exit();
}

// Kết nối database
$database = new Database();
$pdo = $database->getConnection();

$currentUser = $_SESSION['user_id'];
$userWarehouseId = $_SESSION['warehouse_id'] ?? null;
$userRole = $_SESSION['role'] ?? 'staff'; // Lấy role từ session (admin, manager, staff)

// Debug role và warehouse_id
error_log("Debug: userRole = " . $userRole);
error_log("Debug: userWarehouseId = " . ($userWarehouseId ?? 'NULL'));
file_put_contents('debug_log.txt', date('Y-m-d H:i:s') . " - User Role: " . $userRole . ", Warehouse ID: " . ($userWarehouseId ?? 'NULL') . "\n", FILE_APPEND);

// Kiểm tra warehouse_id có tồn tại không, nếu không thì reset
if ($userWarehouseId) {
    try {
        $stmt = $pdo->prepare("SELECT warehouse_id FROM warehouses WHERE warehouse_id = ?");
        $stmt->execute([$userWarehouseId]);
        if (!$stmt->fetch()) {
            // Warehouse_id không tồn tại, reset session
            $userWarehouseId = null;
            unset($_SESSION['warehouse_id']);
            file_put_contents('debug_log.txt', date('Y-m-d H:i:s') . " - Reset invalid warehouse_id from session\n", FILE_APPEND);
        }
    } catch (Exception $e) {
        file_put_contents('debug_log.txt', date('Y-m-d H:i:s') . " - Error checking warehouse_id: " . $e->getMessage() . "\n", FILE_APPEND);
    }
}

// Lấy thông tin kho
$warehouseName = 'Smart Warehouse'; // Mặc định

// Nếu không có warehouse_id trong session, tạo warehouse mặc định
if (!$userWarehouseId) {
    try {
        // Kiểm tra xem có warehouse nào không
        $stmt = $pdo->query("SELECT warehouse_id FROM warehouses LIMIT 1");
        $existingWarehouse = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$existingWarehouse) {
            // Tạo warehouse mặc định
            $stmt = $pdo->prepare("INSERT INTO warehouses (name, status) VALUES (?, ?)");
            $stmt->execute(['Smart Warehouse', 'active']);
            $userWarehouseId = $pdo->lastInsertId();
            
            // Lưu vào session
            $_SESSION['warehouse_id'] = $userWarehouseId;
            error_log("Created default warehouse with ID: " . $userWarehouseId);
            file_put_contents('debug_log.txt', date('Y-m-d H:i:s') . " - Created default warehouse with ID: " . $userWarehouseId . "\n", FILE_APPEND);
        } else {
            // Sử dụng warehouse đầu tiên có sẵn
            $userWarehouseId = $existingWarehouse['warehouse_id'];
            $_SESSION['warehouse_id'] = $userWarehouseId;
            error_log("Using existing warehouse ID: " . $userWarehouseId);
            file_put_contents('debug_log.txt', date('Y-m-d H:i:s') . " - Using existing warehouse ID: " . $userWarehouseId . "\n", FILE_APPEND);
        }
    } catch (Exception $e) {
        error_log("Error handling warehouse: " . $e->getMessage());
        file_put_contents('debug_log.txt', date('Y-m-d H:i:s') . " - Error handling warehouse: " . $e->getMessage() . "\n", FILE_APPEND);
    }
}

if ($userWarehouseId) {
    try {
        $stmt = $pdo->prepare("SELECT name FROM warehouses WHERE warehouse_id = ?");
        $stmt->execute([$userWarehouseId]);
        $warehouse = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($warehouse) {
            $warehouseName = $warehouse['name'];
        }
    } catch(PDOException $e) {
        error_log("Database error getting warehouse: " . $e->getMessage());
    }
}

// Kiểm tra xem có đang thêm sản phẩm vào phiếu đang chỉnh sửa không
$fromReceipt = $_GET['from_receipt'] ?? null;
$addMode = $_GET['mode'] ?? null;
$existingReceipt = null;

if ($fromReceipt && $addMode === 'add') {
    try {
        // Lấy thông tin phiếu nhập đang chỉnh sửa
        $stmt = $pdo->prepare("
            SELECT sr.*, s.name as supplier_name 
            FROM stock_receipts sr
            LEFT JOIN suppliers s ON sr.supplier_id = s.supplier_id
            WHERE sr.receipt_id = ? AND sr.status = 'draft'
        ");
        $stmt->execute([$fromReceipt]);
        $existingReceipt = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$existingReceipt) {
            error_log("ERROR: Receipt #$fromReceipt not found or not in draft status");
        } else {
            error_log("INFO: Adding products to receipt #$fromReceipt (Manual mode)");
        }
    } catch(PDOException $e) {
        error_log("ERROR: Failed to get receipt info: " . $e->getMessage());
    }
}

// Lấy danh sách nhà cung cấp TRONG WAREHOUSE HIỆN TẠI
$suppliers = [];
try {
    if ($userWarehouseId) {
        // Chỉ lấy suppliers thuộc warehouse hiện tại, bao gồm cả status
        $stmt = $pdo->prepare("SELECT supplier_id, name, phone, email, status FROM suppliers WHERE warehouse_id = ? ORDER BY name");
        $stmt->execute([$userWarehouseId]);
        $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        error_log("Loaded " . count($suppliers) . " suppliers for warehouse_id: " . $userWarehouseId);
    } else {
        error_log("WARNING: userWarehouseId is empty, no suppliers loaded");
    }
} catch(PDOException $e) {
    error_log("Database error loading suppliers: " . $e->getMessage());
}

// Note: Product data is now loaded dynamically via AJAX cascading filters

?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Tạo Phiếu Nhập Kho Thủ Công - Smart Warehouse</title>

    <!-- Custom fonts for this template-->
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">

    <!-- Custom styles for this template-->
    <link href="css/sb-admin-2.min.css" rel="stylesheet">

    <!-- SweetAlert2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.28/dist/sweetalert2.min.css" rel="stylesheet">

    <style>
        /* ===== CSS VARIABLES ===== */
        :root {
            --primary-blue: #2563eb;
            --dark-bg: #1f2937;
            --darker-bg: #111827;
            --beige-bg: #f5f5dc;
            --gray-border: #e5e7eb;
            --white: #ffffff;
            --yellow: #fbbf24;
            --yellow-light: #fef3c7;
            --success: #10b981;
            --danger: #ef4444;
            --info: #3b82f6;
        }

        /* Supplier dropdown styling for inactive suppliers */
        #supplierSelect option[disabled] {
            color: #6b7280;
            background-color: var(--beige-bg);
            font-style: italic;
        }

        /* Form labels: dark color for high contrast on white inputs */
        .form-group label {
            font-weight: 600;
            color: #111827; /* near-black for maximum contrast */
            margin-bottom: 8px;
        }
        
        /* ===== STEP INDICATOR ===== */
        .step-indicator {
            display: flex;
            justify-content: center;
            margin: 2rem 0;
            position: relative;
        }

        .step {
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
            flex: 1;
            max-width: 200px;
        }

        .step-number {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, #d1d5db 0%, #9ca3af 100%);
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 18px;
            margin-bottom: 0.5rem;
            z-index: 2;
            position: relative;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
            transition: all 0.3s ease;
        }

        .step.active .step-number {
            background: linear-gradient(135deg, var(--primary-blue) 0%, #1d4ed8 100%);
            color: var(--white);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.4);
            transform: scale(1.1);
        }

        .step.completed .step-number {
            background: linear-gradient(135deg, var(--success) 0%, #059669 100%);
            color: var(--white);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4);
        }

        .step span {
            color: #6b7280;
            font-weight: 600;
            font-size: 14px;
            text-align: center;
        }

        .step.active span {
            color: var(--primary-blue);
            font-weight: 700;
        }

        .step.completed span {
            color: var(--success);
        }

        .step:not(:last-child)::after {
            content: '';
            position: absolute;
            top: 25px;
            left: 50%;
            width: 100%;
            height: 3px;
            background-color: var(--gray-border);
            z-index: 1;
            transition: all 0.3s ease;
        }

        .step.completed:not(:last-child)::after {
            background: linear-gradient(90deg, var(--success) 0%, #059669 100%);
        }

        .step-content {
            display: none;
        }

        .step-content.active {
            display: block;
            animation: fadeIn 0.4s ease-in;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .hidden {
            display: none;
        }

        /* ===== FORM CONTROLS ===== */
        .form-control {
            border: 2px solid var(--gray-border);
            border-radius: 8px;
            padding: 10px 15px;
            min-height: 44px;
            overflow: visible;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 0.2rem rgba(37, 99, 235, 0.15);
        }

        .form-group select:disabled {
            background-color: var(--beige-bg);
            border-color: var(--gray-border);
            color: #6b7280;
        }

        .cascading-dropdown {
            transition: all 0.3s ease;
        }

        .cascading-dropdown:not(:disabled):hover {
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 0.15rem rgba(37, 99, 235, 0.15);
        }

        /* ===== AVAILABLE SIZES SECTION ===== */
        .existing-sizes-section {
            margin-bottom: 25px;
        }

        .existing-sizes-section h5 {
            color: var(--primary-blue);
            font-weight: 700;
            margin-bottom: 18px;
            font-size: 1.3rem;
        }

        #availableSizesTable {
            font-size: 0.9rem;
        }

        #availableSizesTable.table-hover tbody tr {
            transition: all 0.2s ease;
        }

        #availableSizesTable.table-hover tbody tr:hover {
            background-color: var(--beige-bg);
            transform: translateX(3px);
            box-shadow: 0 2px 6px rgba(37, 99, 235, 0.1);
        }

        #availableSizesTable thead.thead-light th {
            background: linear-gradient(135deg, var(--dark-bg) 0%, var(--darker-bg) 100%);
            color: var(--yellow-light);
            border: none;
            font-size: 0.9rem;
            font-weight: 700;
            padding: 12px;
        }

        #availableSizesTable tbody td {
            font-size: 0.9rem;
            padding: 12px;
            border-color: var(--gray-border);
        }

        /* ===== SELECTED SIZES TABLE ===== */
        #sizesTable {
            font-size: 0.9rem;
            border-collapse: separate;
            border-spacing: 0;
        }

        #sizesTable thead th {
            background: #5a5c69 !important;
            color: #ffffff !important;
            border: none !important;
            font-weight: 700;
            padding: 12px 10px;
            vertical-align: middle;
            text-align: center;
            font-size: 0.9rem;
        }

        #sizesTable tbody td {
            vertical-align: middle;
            border: 2px solid var(--gray-border) !important;
            padding: 12px 10px;
            background-color: var(--white);
            font-size: 0.9rem;
            transition: all 0.2s ease;
        }

        #sizesTable tbody tr:hover td {
            background-color: var(--beige-bg) !important;
            transform: scale(1.01);
        }

        #sizesTable tbody td small {
            font-size: 0.8rem;
            color: #6b7280;
        }

        #sizesTable tbody td code {
            font-size: 0.8rem;
            background-color: var(--beige-bg);
            padding: 2px 6px;
            border-radius: 4px;
            color: var(--dark-bg);
        }

        .multiple-sizes-section {
            animation: slideDown 0.4s ease-in-out;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-15px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .text-info option {
            background-color: #dbeafe;
            color: var(--primary-blue);
        }

        .required-field {
            border-left: 4px solid var(--danger);
        }

        .field-order-1 { order: 1; }
        .field-order-2 { order: 2; }
        .field-order-3 { order: 3; }
        .field-order-4 { order: 4; }

        /* ===== CARD STYLING ===== */
        .card {
            border: none;
            border-radius: 12px;
            overflow: hidden;
        }

        .card-header {
            background: linear-gradient(135deg, var(--dark-bg) 0%, var(--darker-bg) 100%);
            color: var(--yellow-light);
            border: none;
            padding: 15px 20px;
            font-weight: 700;
        }

        .card-header h6 {
            color: var(--yellow-light);
            margin: 0;
        }

        .bg-primary {
            background: linear-gradient(135deg, var(--dark-bg) 0%, var(--darker-bg) 100%) !important;
        }

        /* ===== BUTTONS ===== */
        .btn {
            border-radius: 8px;
            font-weight: 600;
            padding: 10px 20px;
            transition: all 0.3s ease;
            border: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary-blue) 0%, #1d4ed8 100%);
            color: var(--white);
            box-shadow: 0 2px 8px rgba(37, 99, 235, 0.3);
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.4);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success) 0%, #059669 100%);
            color: var(--white);
            box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
        }

        .btn-success:hover {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4);
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger) 0%, #dc2626 100%);
            color: var(--white);
            box-shadow: 0 2px 8px rgba(239, 68, 68, 0.3);
        }

        .btn-danger:hover {
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.4);
        }

        .btn-info {
            background: linear-gradient(135deg, var(--info) 0%, #2563eb 100%);
            color: var(--white);
            box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);
        }

        .btn-info:hover {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--yellow) 0%, #f59e0b 100%);
            color: var(--dark-bg);
            box-shadow: 0 2px 8px rgba(251, 191, 36, 0.3);
            font-weight: 700;
        }

        .btn-warning:hover {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(251, 191, 36, 0.4);
            color: var(--dark-bg);
        }

        .btn-outline-secondary {
            border: 2px solid var(--gray-border);
            color: #6b7280;
            background: var(--white);
        }

        .btn-outline-secondary:hover {
            background: var(--beige-bg);
            border-color: var(--primary-blue);
            color: var(--primary-blue);
        }

        .btn-lg {
            padding: 15px 40px;
            font-size: 1.1rem;
            border-radius: 10px;
        }

        /* ===== ALERTS ===== */
        .alert {
            border-radius: 10px;
            border: 2px solid;
            padding: 15px 20px;
        }

        .alert-info {
            background: linear-gradient(135deg, #dbeafe 0%, #eff6ff 100%);
            border-color: var(--primary-blue);
            color: #1e40af;
        }

        /* ===== BADGES ===== */
        .badge {
            padding: 6px 12px;
            font-weight: 600;
            border-radius: 6px;
        }

        /* ===== RECEIPT SUMMARY ===== */
        .receipt-summary {
            background: linear-gradient(135deg, var(--beige-bg) 0%, #fef3c7 100%);
            padding: 20px;
            border-radius: 10px;
            border-left: 5px solid var(--primary-blue);
            margin-bottom: 20px;
        }

        .receipt-summary h6 {
            color: var(--dark-bg);
            font-weight: 700;
            margin-bottom: 10px;
        }

        .receipt-summary p {
            color: #374151;
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 768px) {
            .step-indicator {
                flex-direction: column;
                gap: 15px;
            }

            .step {
                max-width: 100%;
            }

            .step:not(:last-child)::after {
                display: none;
            }

            .btn-lg {
                padding: 12px 24px;
                font-size: 1rem;
            }
        }
    </style>
</head>

<body id="page-top">
    <!-- DEBUG: Current User Role = <?php echo htmlspecialchars($userRole); ?> -->
    <div id="wrapper">
        <?php include 'includes/thanh_ben.php'; ?>

        <!-- Content Wrapper -->
        <div id="content-wrapper" class="d-flex flex-column">
            <div id="content">
                <?php include 'includes/thanh_tren.php'; ?>

                <!-- Page Content -->
                <!-- Page Content -->
                <div class="container-fluid">
                    <!-- Warehouse Name -->
                    <div class="d-flex align-items-center mb-2">
                        <i class="fas fa-warehouse text-primary mr-2"></i>
                        <span class="h5 mb-0 text-primary font-weight-bold"><?php echo htmlspecialchars($warehouseName); ?></span>
                    </div>
                    
                    <!-- Page Heading -->
                    <div class="d-sm-flex align-items-center justify-content-between mb-4">
                        <div class="d-flex align-items-center">
                            <button type="button" class="btn btn-outline-secondary mr-3" id="mainBackBtn" title="Quay lại trang quản lý">
                                <i class="fas fa-arrow-left"></i>
                            </button>
                            <h1 class="h3 mb-0 text-gray-800">
                                <?php if ($existingReceipt): ?>
                                    Thêm sản phẩm vào phiếu #<?php echo str_pad($fromReceipt, 6, '0', STR_PAD_LEFT); ?>
                                <?php else: ?>
                                    Tạo phiếu nhập kho thủ công
                                <?php endif; ?>
                            </h1>
                            <span class="badge badge-<?php echo $userRole === 'staff' ? 'warning' : ($userRole === 'manager' ? 'info' : 'success'); ?> ml-3">
                                Role: <?php echo ucfirst(htmlspecialchars($userRole)); ?>
                            </span>
                        </div>
                    </div>
                    
                    <?php if ($existingReceipt): ?>
                    <div class="alert alert-info alert-dismissible fade show mt-3" role="alert">
                        <i class="fas fa-info-circle mr-2"></i>
                        <strong>Chế độ thêm sản phẩm:</strong> Bạn đang thêm sản phẩm vào phiếu nhập <strong>#<?php echo str_pad($fromReceipt, 6, '0', STR_PAD_LEFT); ?></strong>
                        (Nhà cung cấp: <?php echo htmlspecialchars($existingReceipt['supplier_name']); ?>)
                        <button type="button" class="close" data-dismiss="alert">
                            <span>&times;</span>
                        </button>
                    </div>
                    <?php endif; ?>

                    <!-- Step Indicator -->
                    <div class="step-indicator">
                        <div class="step active" data-step="1">
                            <div class="step-number">1</div>
                            <span>Thông tin cơ bản</span>
                        </div>
                        <div class="step" data-step="2">
                            <div class="step-number">2</div>
                            <span>Chỉnh sửa thông tin</span>
                        </div>
                        <div class="step" data-step="3">
                            <div class="step-number">3</div>
                            <span>Hoàn thành</span>
                        </div>
                    </div>

                    <!-- Step 1: Thông tin cơ bản -->
                    <div id="step1" class="step-content active">
                        <div class="card shadow mb-4">
                            <div class="card-header py-3 bg-primary text-white">
                                <h6 class="m-0 font-weight-bold">
                                    <i class="fas fa-info-circle mr-2"></i>Thông tin cơ bản
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <!-- Cột trái -->
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="receiptId" class="font-weight-bold">Mã phiếu nhập <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" id="receiptId" 
                                                   value="Tự động tạo" 
                                                   readonly>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label for="receiptDate" class="font-weight-bold">Ngày nhập <span class="text-danger">*</span></label>
                                            <input type="date" class="form-control" id="receiptDate" 
                                                   value="<?php echo date('Y-m-d'); ?>">
                                        </div>
                                        
                                        <div class="form-group">
                                            <label for="supplierSelect" class="font-weight-bold">Nhà cung cấp <span class="text-danger">*</span></label>
                                            <div class="d-flex">
                                                <select class="form-control mr-2" id="supplierSelect" required>
                                                    <option value="">-- Chọn nhà cung cấp --</option>
                                                    <?php foreach ($suppliers as $supplier): ?>
                                                        <?php 
                                                            $isInactive = isset($supplier['status']) && $supplier['status'] === 'inactive';
                                                            $disabledAttr = $isInactive ? 'disabled' : '';
                                                            $inactiveLabel = $isInactive ? ' [TẠM NGƯNG - Cần kích hoạt tại Quản lý nhà cung cấp]' : '';
                                                        ?>
                                                        <option value="<?php echo $supplier['supplier_id']; ?>"
                                                                <?php echo $disabledAttr; ?>
                                                                data-status="<?php echo $supplier['status'] ?? 'active'; ?>">
                                                            <?php echo htmlspecialchars($supplier['name']); ?>
                                                            <?php echo $inactiveLabel; ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="button" class="btn btn-success" id="addSupplierBtn" title="Thêm nhà cung cấp mới">
                                                    <i class="fas fa-plus"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Cột phải -->
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="warehouseInfo" class="font-weight-bold">Kho nhập</label>
                                            <input type="text" class="form-control" id="warehouseInfo" 
                                                   value="<?php echo htmlspecialchars($warehouseName); ?>" 
                                                   readonly>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label for="receiptNotes" class="font-weight-bold">Ghi chú</label>
                                            <textarea class="form-control" id="receiptNotes" rows="4" 
                                                      placeholder="Nhập ghi chú về phiếu nhập này..."></textarea>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Thông báo hướng dẫn -->
                                <div class="alert alert-info mt-3 mb-0">
                                    <i class="fas fa-info-circle mr-2"></i>
                                    <strong>Hướng dẫn:</strong> Vui lòng điền đầy đủ thông tin cơ bản trước khi tiếp tục đến bước chỉnh sửa thông tin sản phẩm.
                                </div>
                            </div>
                        </div>
                        
                        <!-- Buttons -->
                        <div class="text-center">
                            <button type="button" class="btn btn-primary btn-lg" id="nextToEdit" disabled>
                                Tiếp theo: Chỉnh sửa thông tin <i class="fas fa-arrow-right ml-2"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Step 2: Chỉnh sửa thông tin sản phẩm (Bước 4 trong workflow gốc) -->
                    <div id="step2" class="step-content">
                        <div class="card shadow mb-4">
                            <div class="card-header py-3 bg-primary text-white">
                                <h6 class="m-0 font-weight-bold">
                                    <i class="fas fa-edit mr-2"></i>Chỉnh sửa thông tin sản phẩm
                                </h6>
                            </div>
                            <div class="card-body">
                                <form id="productDetailsForm">
                                    <div class="row">
                                        <!-- Cột trái - Theo thứ tự yêu cầu: Loại sản phẩm, Thương hiệu, Màu sắc, Tên sản phẩm -->
                                        <div class="col-md-6">
                                            <div class="form-group field-order-1">
                                                <label for="productType" class="font-weight-bold">1. Loại sản phẩm <span class="text-danger">*</span></label>
                                                <select class="form-control required-field cascading-dropdown" id="productType" required>
                                                    <option value="">-- Chọn loại sản phẩm --</option>
                                                </select>
                                                <small class="text-muted">Chọn loại sản phẩm để lọc thương hiệu</small>
                                            </div>
                                            
                                            <div class="form-group field-order-2">
                                                <label for="productBrand" class="font-weight-bold">2. Thương hiệu <span class="text-danger">*</span></label>
                                                <select class="form-control required-field cascading-dropdown" id="productBrand" required disabled>
                                                    <option value="">-- Chọn thương hiệu --</option>
                                                </select>
                                                <small class="text-muted">Chọn loại sản phẩm trước để xem thương hiệu</small>
                                            </div>

                                            <div class="form-group field-order-3">
                                                <label for="productColor" class="font-weight-bold">3. Màu sắc <span class="text-danger">*</span></label>
                                                <select class="form-control required-field cascading-dropdown" id="productColor" required disabled>
                                                    <option value="">-- Chọn màu sắc --</option>
                                                </select>
                                                <small class="text-muted">Chọn thương hiệu trước để xem màu sắc</small>
                                            </div>

                                            <div class="form-group field-order-4">
                                                <label for="productName" class="font-weight-bold">4. Tên sản phẩm <span class="text-danger">*</span></label>
                                                <select class="form-control required-field cascading-dropdown" id="productName" required disabled>
                                                    <option value="">-- Chọn tên sản phẩm --</option>
                                                </select>
                                                <small class="text-muted">Chọn màu sắc trước để xem tên sản phẩm</small>
                                            </div>
                                        </div>
                                        
                                        <!-- Cột phải - Chỉ giữ ghi chú -->
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="itemNotes" class="font-weight-bold">Ghi chú</label>
                                                <textarea class="form-control" id="itemNotes" rows="4"
                                                          placeholder="Ghi chú chung về sản phẩm này..."></textarea>
                                            </div>
                                            
                                            <!-- Thông tin hướng dẫn -->
                                            <div class="alert alert-info">
                                                <i class="fas fa-info-circle mr-2"></i>
                                                <strong>Hướng dẫn:</strong><br>
                                                - Chọn đầy đủ thông tin sản phẩm để xem SKU và kích thước có sẵn<br>
                                                - Sử dụng chức năng "Nhập nhiều kích thước" để nhập số lượng và giá cho từng size<br>
                                                - Vị trí lưu trữ sẽ được gợi ý từ database theo warehouse
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Hidden fields -->
                                    <input type="hidden" id="productId" value="">
                                    <input type="hidden" id="variantId" value="">
                                    
                                    <!-- Multiple Sizes Section -->
                                    <div class="mt-4" id="multipleSizesSection" style="display: none;">
                                        <!-- Phần 1: Chọn size cần nhập kho -->
                                        <div class="card shadow-sm mb-3" style="border-left: 4px solid #3b82f6; border-radius: 8px; background: #ffffff;">
                                            <div class="card-header py-3" style="background: #ffffff; border: none;">
                                                <h6 class="m-0 font-weight-bold" style="color: #6b7280;">
                                                    <i class="fas fa-hand-pointer mr-2" style="color: #3b82f6;"></i>
                                                    Chọn size cần nhập kho
                                                </h6>
                                                <small style="color: #9ca3af;"><i class="fas fa-info-circle mr-1"></i>Click nút bên dưới để chọn size từ danh sách có sẵn của sản phẩm này</small>
                                            </div>
                                        </div>

                                        <!-- Phần 2: Size có sẵn từ database -->
                                        <div class="existing-sizes-section mb-4">
                                            <h5><i class="fas fa-archive" style="color: #3b82f6;"></i> Size có sẵn từ database</h5>
                                            <div class="alert alert-info">
                                                <i class="fas fa-info-circle"></i> 
                                                Chọn các size bạn muốn nhập hàng từ danh sách có sẵn:
                                            </div>
                                            <table class="table table-hover" id="availableSizesTable">
                                                <thead class="thead-light">
                                                    <tr>
                                                        <th>Size</th>
                                                        <th>SKU</th>
                                                        <th>Giá nhập cũ</th>
                                                        <th>Giá bán cũ</th>
                                                        <th>Màu sắc</th>
                                                        <th>Hành động</th>
                                                    </tr>
                                                </thead>
                                                <tbody></tbody>
                                            </table>
                                        </div>

                                        <!-- Phần 3: Size đã chọn để nhập kho -->
                                        <div class="card shadow mb-4" id="selectedSizesCard" style="display: none;">
                                            <div class="card-header py-2" style="background: #f9fafb; border-bottom: 1px solid #e5e7eb;">
                                                <h6 class="m-0" style="color: #6b7280; font-weight: 600;">
                                                    <i class="fas fa-check-double mr-2" style="color: #06b6d4;"></i>Size đã chọn để nhập kho
                                                </h6>
                                            </div>
                                            <div class="card-body p-2">
                                                <div class="table-responsive">
                                                    <table class="table table-bordered table-hover mb-0" id="sizesTable">
                                                        <thead>
                                                            <tr>
                                                                <th class="text-center">Size</th>
                                                                <th class="text-center">SKU</th>
                                                                <th class="text-center">Màu sắc</th>
                                                                <th class="text-center">Số lượng nhập</th>
                                                                <th class="text-center">Giá nhập (VNĐ)</th>
                                                                <th class="text-center">Giá bán (VNĐ)</th>
                                                                <th class="text-center">Vị trí lưu</th>
                                                                <th class="text-center">Hành động</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody></tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mt-4 text-center">
                                        <button type="button" class="btn btn-outline-secondary btn-lg mr-3" id="backToBasic">
                                            <i class="fas fa-arrow-left mr-2"></i>Quay lại thông tin cơ bản
                                        </button>
                                        <button type="button" class="btn btn-success btn-lg" id="addAllSizesToReceipt">
                                            <?php if ($fromReceipt && $addMode === 'add'): ?>
                                            <i class="fas fa-check-circle mr-2"></i>Cập nhật phiếu nhập
                                            <?php else: ?>
                                            <i class="fas fa-check-circle mr-2"></i>Thêm sản phẩm vào phiếu nhập
                                            <?php endif; ?>
                                        </button>
                                        <button type="button" class="btn btn-info btn-lg ml-3" id="continueToStep3" style="display: none;">
                                            Xem phiếu nhập
                                            <span class="badge badge-light ml-2" id="receiptItemsCount">0</span>
                                            <i class="fas fa-arrow-right ml-2"></i>
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Step 3: Hoàn thành phiếu nhập (Bước 5 trong workflow gốc) -->
                    <div id="step3" class="step-content">
                        <div class="card shadow mb-4">
                            <div class="card-header py-3" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
                                <h6 class="m-0 font-weight-bold" style="color: #ffffff;">
                                    <i class="fas fa-check-circle mr-2"></i>Hoàn thành phiếu nhập
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="receipt-summary">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <h6>Thông tin nhà cung cấp:</h6>
                                            <p id="supplierInfo" class="mb-0"></p>
                                        </div>
                                        <div class="col-md-6">
                                            <h6>Thông tin phiếu nhập:</h6>
                                            <p class="mb-1"><strong>Người tạo:</strong> <?php echo htmlspecialchars($_SESSION['username'] ?? 'User'); ?></p>
                                            <p class="mb-1"><strong>Ngày tạo:</strong> <span id="currentDate"></span></p>
                                            <p class="mb-0"><strong>Ghi chú:</strong> <span id="receiptNotesDisplay"></span></p>
                                        </div>
                                    </div>
                                </div>

                                <h6 class="mt-4">Danh sách sản phẩm:</h6>
                                <div id="receiptItemsList"></div>
                                
                                <div class="mt-4 text-right">
                                    <p class="h5"><strong>Tổng tiền: <span id="totalAmount" class="text-success">0 VNĐ</span></strong></p>
                                </div>

                                <div class="mt-4">
                                    <button type="button" class="btn btn-secondary" id="backToEdit">
                                        <i class="fas fa-arrow-left mr-2"></i>Quay lại chỉnh sửa
                                    </button>
                                    <button type="button" class="btn btn-warning ml-2" id="saveDraftBtn">
                                        <i class="fas fa-save mr-2"></i>Lưu bản nháp
                                    </button>
                                    <button type="button" class="btn btn-primary ml-2" id="addMoreItems">
                                        <i class="fas fa-plus mr-2"></i>Thêm sản phẩm khác
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <footer class="sticky-footer bg-white">
                <div class="container my-auto">
                    <div class="copyright text-center my-auto">
                        <span>Copyright &copy; Smart Warehouse 2024</span>
                    </div>
                </div>
            </footer>
        </div>
    </div>

    <!-- Logout Modal-->
    <div class="modal fade" id="logoutModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Xác nhận đăng xuất</h5>
                    <button class="close" type="button" data-dismiss="modal">
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

    <!-- Add Supplier Modal -->
    <div class="modal fade" id="addSupplierModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-plus-circle mr-2"></i>Thêm nhà cung cấp mới
                    </h5>
                    <button class="close" type="button" data-dismiss="modal">
                        <span aria-hidden="true">×</span>
                    </button>
                </div>
                <div class="modal-body">
                    <form id="addSupplierForm">
                        <div class="form-group">
                            <label for="supplierName">Tên nhà cung cấp <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="supplierName" required>
                        </div>
                        <div class="form-group">
                            <label for="contactName">Tên người liên hệ</label>
                            <input type="text" class="form-control" id="contactName">
                        </div>
                        <div class="form-group">
                            <label for="supplierPhone">Số điện thoại</label>
                            <input type="text" class="form-control" id="supplierPhone">
                        </div>
                        <div class="form-group">
                            <label for="supplierEmail">Email</label>
                            <input type="email" class="form-control" id="supplierEmail">
                        </div>
                        <div class="form-group">
                            <label for="supplierAddress">Địa chỉ</label>
                            <textarea class="form-control" id="supplierAddress" rows="2"></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" type="button" data-dismiss="modal">Hủy</button>
                    <button class="btn btn-success" type="button" id="saveSupplierBtn">
                        <i class="fas fa-save mr-1"></i>Lưu
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap core JavaScript-->
    <script src="vendor/jquery/jquery.min.js"></script>
    <script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="vendor/jquery-easing/jquery.easing.min.js"></script>
    <script src="js/sb-admin-2.min.js"></script>
    
    <!-- SweetAlert2 JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.28/dist/sweetalert2.all.min.js"></script>

    <script>
        // Global variables
        let currentStep = 1;
        let receiptItems = [];
        let currentSupplier = null;
        
        // Lấy danh sách vị trí bị loại trừ từ localStorage (khi thêm sản phẩm vào phiếu hiện có)
        let excludedLocations = [];
        try {
            const excludedLocationsStr = localStorage.getItem('excluded_locations');
            if (excludedLocationsStr) {
                excludedLocations = JSON.parse(excludedLocationsStr);
                console.log('🚫 Excluded locations from current receipt:', excludedLocations);
            }
        } catch (e) {
            console.error('Error parsing excluded_locations:', e);
        }
        
        // Debug: Log user role
        console.log('Current User Role:', '<?php echo $userRole; ?>');
        console.log('Is Staff (Employee)?', '<?php echo $userRole === "staff" ? "YES" : "NO"; ?>');

        // Data is now loaded dynamically via cascading dropdowns

        $(document).ready(function() {
            // Initialize
            updateStepDisplay();
            setupCascadingDropdowns();
            setupPriceFormatting();
            
            // Event handlers
            $('#supplierSelect').on('change', function() {
                const supplierId = $(this).val();
                const selectedOption = $(this).find('option:selected');
                const supplierStatus = selectedOption.data('status');
                
                // Kiểm tra nếu chọn supplier bị tạm ngưng (không nên xảy ra vì đã disabled)
                if (supplierStatus === 'inactive') {
                    Swal.fire({
                        icon: 'warning',
                        title: '⚠️ Nhà cung cấp tạm ngưng',
                        html: '<p>Nhà cung cấp này đang bị <strong>tạm ngưng</strong>.</p>' +
                              '<p class="text-muted mt-2">Vui lòng liên hệ <strong>Admin</strong> hoặc <strong>Quản lý</strong> để kích hoạt lại nhà cung cấp tại trang <a href="quan_ly_nha_cung_cap.php" target="_blank">Quản lý nhà cung cấp</a>.</p>',
                        confirmButtonText: 'Đã hiểu',
                        confirmButtonColor: '#6c757d'
                    });
                    $(this).val('');
                    currentSupplier = null;
                    $('#nextToEdit').prop('disabled', true);
                    return;
                }
                
                if (supplierId) {
                    const supplierText = $(this).find('option:selected').text();
                    currentSupplier = {
                        id: supplierId,
                        name: supplierText
                    };
                    $('#nextToEdit').prop('disabled', false);
                } else {
                    currentSupplier = null;
                    $('#nextToEdit').prop('disabled', true);
                }
            });

            // Step navigation
            $('#nextToEdit').click(function() {
                if (validateStep1()) {
                    moveToStep(2);
                }
            });

            $('#backToBasic').click(function() {
                // Check if there's any data filled in the sizes table
                const hasSizeData = sizesTableData && sizesTableData.length > 0;
                
                if (hasSizeData) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Quay lại bước 1?',
                        html: `
                            <p>Bạn đã chọn <strong>${sizesTableData.length} size</strong> sản phẩm.</p>
                            <p>Dữ liệu này sẽ bị xóa nếu quay lại.</p>
                        `,
                        showCancelButton: true,
                        confirmButtonText: '<i class="fas fa-arrow-left mr-2"></i>Quay lại',
                        cancelButtonText: 'Ở lại',
                        confirmButtonColor: '#6c757d',
                        cancelButtonColor: '#3085d6'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            // Clear sizes data when going back
                            sizesTableData = [];
                            updateSizesTable();
                            moveToStep(1);
                        }
                    });
                } else {
                    moveToStep(1);
                }
            });

            $('#backToEdit').click(function() {
                // Always allow going back to step 2 without warning
                // because user might just want to review/edit
                moveToStep(2);
            });

            $('#continueToStep3').click(function() {
                // Navigate to step 3 to view receipt
                moveToStep(3);
            });

            $('#addToReceipt').click(function() {
                if (validateProductForm()) {
                    addProductToReceipt();
                }
            });

            $('#addMoreItems').click(function() {
                clearProductForm();
                moveToStep(2);
            });

            $('#completeReceipt').click(function() {
                // Kiểm tra quyền trước khi cho phép hoàn thành
                <?php if ($userRole === 'staff'): ?>
                Swal.fire({
                    icon: 'error',
                    title: 'Không có quyền!',
                    text: 'Bạn không có quyền hoàn thành phiếu nhập. Chỉ Admin/Manager mới có quyền này.',
                    confirmButtonText: 'Đã hiểu'
                });
                return false;
                <?php else: ?>
                completeStockReceipt();
                <?php endif; ?>
            });

            $('#saveDraftBtn').click(function() {
                saveDraftReceipt();
            });



            // Location suggestion
            $('#suggestLocationBtn').click(function() {
                suggestStorageLocation();
            });
            
            // Smart location suggestion for all sizes
            $('#suggestAllLocationsBtn').click(function() {
                if (sizesTableData.length === 0) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Chưa có kích thước',
                        text: 'Vui lòng thêm ít nhất một kích thước trước khi gợi ý vị trí!',
                        confirmButtonText: 'Đã hiểu'
                    });
                    return;
                }
                
                // Suggest location for the first size as a general suggestion
                const $firstRow = $('#sizesTable tbody tr').first();
                if ($firstRow.length && sizesTableData.length > 0) {
                    suggestLocationForSize(sizesTableData[0].size, $firstRow);
                }
            });

            // Multiple sizes functionality

            

            
            $('#addAllSizesBtn').click(function() {
                addAllAvailableSizes();
            });
            
            $('#addSingleSizeBtn').click(function() {
                // Show modal để chọn từng size
                showSizeSelectorModal();
            });
            
            $('#addAllSizesToReceipt').click(function() {
                addAllSizesToReceipt();
            });

            // Add supplier functionality - chuyển sang trang thêm nhà cung cấp
            $('#addSupplierBtn').click(function() {
                window.location.href = 'them_nha_cung_cap.php';
            });

            $('#saveSupplierBtn').click(function() {
                saveNewSupplier();
            });

            // Main back button - goes back to previous page
            $('#mainBackBtn').click(function() {
                // Check if there's any unsaved data
                const hasUnsavedData = sizesTableData && sizesTableData.length > 0;
                
                if (hasUnsavedData) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Bạn có muốn quay lại?',
                        text: 'Các thay đổi chưa lưu sẽ bị mất.',
                        showCancelButton: true,
                        confirmButtonText: 'Quay lại',
                        cancelButtonText: 'Ở lại',
                        confirmButtonColor: '#6c757d',
                        cancelButtonColor: '#3085d6'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            window.history.back();
                        }
                    });
                } else {
                    // No unsaved data, just go back
                    window.history.back();
                }
            });
        });

        function setupCascadingDropdowns() {
            // Load initial product types
            loadDropdownData('type');
            
            // Setup cascading change handlers
            $('#productType').on('change', function() {
                const selectedType = $(this).val();
                
                // Reset and disable subsequent dropdowns
                resetDropdown('productBrand', 'Chọn thương hiệu trước để xem thương hiệu');
                resetDropdown('productColor', 'Chọn thương hiệu trước để xem màu sắc');
                resetDropdown('productName', 'Chọn màu sắc trước để xem tên sản phẩm');
                resetDropdown('productSize', 'Chọn tên sản phẩm trước để xem kích thước có sẵn');
                
                if (selectedType) {
                    loadDropdownData('brand', {type: selectedType});
                    $('#productBrand').prop('disabled', false);
                    $('#productBrand').siblings('small').text('Đã chọn loại sản phẩm, có thể chọn thương hiệu');
                } else {
                    $('#productBrand').prop('disabled', true);
                    $('#productBrand').siblings('small').text('Chọn loại sản phẩm trước để xem thương hiệu');
                }
                
                // Clear SKU when type changes
                $('#productSku').val('');
            });
            
            $('#productBrand').on('change', function() {
                const selectedBrand = $(this).val();
                const selectedType = $('#productType').val();
                
                // Reset subsequent dropdowns
                resetDropdown('productColor', 'Chọn màu sắc trước để xem màu sắc');
                resetDropdown('productName', 'Chọn màu sắc trước để xem tên sản phẩm');
                resetDropdown('productSize', 'Chọn tên sản phẩm trước để xem kích thước có sẵn');
                
                if (selectedBrand && selectedType) {
                    loadDropdownData('color', {type: selectedType, brand: selectedBrand});
                    $('#productColor').prop('disabled', false);
                    $('#productColor').siblings('small').text('Đã chọn thương hiệu, có thể chọn màu sắc');
                } else {
                    $('#productColor').prop('disabled', true);
                    $('#productColor').siblings('small').text('Chọn thương hiệu trước để xem màu sắc');
                }
                
                // Clear SKU when brand changes
                $('#productSku').val('');
            });
            
            $('#productColor').on('change', function() {
                const selectedColor = $(this).val();
                const selectedType = $('#productType').val();
                const selectedBrand = $('#productBrand').val();
                
                // Reset subsequent dropdowns
                resetDropdown('productName', 'Chọn tên sản phẩm trước để xem tên sản phẩm');
                resetDropdown('productSize', 'Chọn tên sản phẩm trước để xem kích thước có sẵn');
                
                if (selectedColor && selectedType && selectedBrand) {
                    loadDropdownData('name', {type: selectedType, brand: selectedBrand, color: selectedColor});
                    $('#productName').prop('disabled', false);
                    $('#productName').siblings('small').text('Đã chọn màu sắc, có thể chọn tên sản phẩm');
                } else {
                    $('#productName').prop('disabled', true);
                    $('#productName').siblings('small').text('Chọn màu sắc trước để xem tên sản phẩm');
                }
                
                // Clear SKU when color changes
                $('#productSku').val('');
            });
            
            $('#productName').on('change', function() {
                const selectedName = $(this).val();
                const selectedType = $('#productType').val();
                const selectedBrand = $('#productBrand').val();
                const selectedColor = $('#productColor').val();
                
                // Reset size dropdown and dependent fields
                resetDropdown('productSize', 'Chọn kích thước trước để xem kích thước có sẵn');
                $('#productSku').val('');
                $('#storageLocation').empty().append('<option value="">-- Chọn vị trí lưu trữ --</option>');
                
                if (selectedName && selectedType && selectedBrand && selectedColor) {
                    console.log('All products selected, loading sizes for:', {
                        type: selectedType,
                        brand: selectedBrand, 
                        color: selectedColor,
                        name: selectedName
                    });
                    
                    // Load storage locations for this product type and brand
                    loadStorageLocations(selectedType, selectedBrand);
                    
                    // Show multiple sizes section and load all available sizes
                    $('#multipleSizesSection').show();
                    loadAllAvailableSizes(selectedType, selectedBrand, selectedColor, selectedName);
                    
                    // Enable multiple sizes functionality
                    $('#addAllSizesBtn').prop('disabled', false);
                } else {
                    // Hide multiple sizes section if product not fully selected
                    $('#multipleSizesSection').hide();
                    $('#addAllSizesBtn').prop('disabled', true);
                }
            });
            
            // Size handling is now done through the available sizes display
        }

        function loadDropdownData(field, filters = {}) {
            $.ajax({
                url: 'api_bo_loc_theo_tang.php',
                method: 'POST',
                data: {
                    field: field,
                    filters: filters,
                    warehouse_id: <?php echo $userWarehouseId; ?>
                },
                success: function(response) {
                    if (response.success) {
                        populateDropdown(field, response.data);
                    } else {
                        console.error('Error loading dropdown data:', response.message);
                        Swal.fire({
                            icon: 'error',
                            title: 'Lỗi tải dữ liệu',
                            text: response.message
                        });
                    }
                },
                error: function() {
                    console.error('Ajax error loading dropdown data for field:', field);
                    Swal.fire({
                        icon: 'error',
                        title: 'Lỗi kết nối',
                        text: 'Không thể tải dữ liệu cho ' + field
                    });
                }
            });
        }

        function populateDropdown(field, data) {
            const $dropdown = $('#product' + field.charAt(0).toUpperCase() + field.slice(1));
            const defaultOption = $dropdown.find('option:first').text();
            
            $dropdown.empty().append(`<option value="">${defaultOption}</option>`);
            
            // Lọc bỏ các giá trị null, undefined, empty hoặc chỉ có space
            const filteredData = data.filter(item => 
                item !== null && 
                item !== undefined && 
                item !== '' && 
                typeof item === 'string' && 
                item.trim() !== ''
            );
            
            filteredData.forEach(item => {
                const cleanItem = item.trim(); // Loại bỏ space đầu cuối
                if (cleanItem) { // Double check
                    $dropdown.append(`<option value="${cleanItem}">${cleanItem}</option>`);
                }
            });
        }

        function resetDropdown(fieldId, helpText) {
            const $dropdown = $('#' + fieldId);
            const defaultText = $dropdown.find('option:first').text();
            
            $dropdown.empty().append(`<option value="">${defaultText}</option>`);
            $dropdown.prop('disabled', true);
            $dropdown.siblings('small').text(helpText);
        }

        function loadProductSku(productInfo) {
            $.ajax({
                url: 'api_lay_du_lieu_san_pham.php',
                method: 'POST',
                data: {
                    action: 'get_sku',
                    ...productInfo,
                    warehouse_id: <?php echo $userWarehouseId; ?>
                },
                success: function(response) {
                    if (response.success) {
                        $('#productSku').val(response.sku);
                        
                        // If existing product, pre-fill price and location
                        if (response.is_existing) {
                            if (response.existing_price) {
                                $('#unitPrice').val(response.existing_price);
                            }
                            if (response.existing_location) {
                                $('#storageLocation').val(response.existing_location);
                            }
                        }
                        
                        // Auto-suggest location if not existing
                        if (!response.existing_location) {
                            suggestStorageLocation();
                        }
                    }
                },
                error: function() {
                    console.error('Error loading SKU');
                }
            });
        }

        function loadStorageLocations(type, brand) {
            $.ajax({
                url: 'api_lay_du_lieu_san_pham.php',
                method: 'POST',
                data: {
                    action: 'get_storage_locations',
                    type: type,
                    brand: brand,
                    warehouse_id: <?php echo $userWarehouseId; ?>
                },
                success: function(response) {
                    if (response.success) {
                        const $select = $('#storageLocation');
                        $select.empty().append('<option value="">-- Chọn vị trí lưu trữ --</option>');
                        
                        response.locations.forEach(location => {
                            $select.append(`<option value="${location}">${location}</option>`);
                        });
                    }
                },
                error: function() {
                    console.error('Error loading storage locations');
                }
            });
        }

        // Phase 1: Storage Location Suggestion System
        function suggestStorageLocation() {
            const productId = $('#productId').val();
            const productType = $('#productType').val();
            const productBrand = $('#productBrand').val();
            const productName = $('#productName').val();
            
            // Check if we have enough product information
            if (!productId && (!productType || !productBrand || !productName)) {
                Swal.fire({
                    icon: 'warning',
                    title: '<i class="fas fa-exclamation-triangle text-warning mr-2"></i>Thiếu thông tin sản phẩm',
                    html: `
                        <div class="text-left">
                            <p class="mb-3">Để sử dụng tính năng gợi ý vị trí thông minh, vui lòng chọn đầy đủ thông tin sản phẩm:</p>
                            <div class="steps-checklist">
                                <div class="step-item ${productType ? 'completed' : ''}">
                                    <i class="fas fa-${productType ? 'check-circle text-success' : 'circle text-muted'} mr-2"></i>
                                    <strong>Bước 1:</strong> Loại sản phẩm ${productType ? '✓' : '(chưa chọn)'}
                                </div>
                                <div class="step-item ${productBrand ? 'completed' : ''}">
                                    <i class="fas fa-${productBrand ? 'check-circle text-success' : 'circle text-muted'} mr-2"></i>
                                    <strong>Bước 2:</strong> Thương hiệu ${productBrand ? '✓' : '(chưa chọn)'}
                                </div>
                                <div class="step-item ${$('#productColor').val() ? 'completed' : ''}">
                                    <i class="fas fa-${$('#productColor').val() ? 'check-circle text-success' : 'circle text-muted'} mr-2"></i>
                                    <strong>Bước 3:</strong> Màu sắc ${$('#productColor').val() ? '✓' : '(chưa chọn)'}
                                </div>
                                <div class="step-item ${productName ? 'completed' : ''}">
                                    <i class="fas fa-${productName ? 'check-circle text-success' : 'circle text-muted'} mr-2"></i>
                                    <strong>Bước 4:</strong> Tên sản phẩm ${productName ? '✓' : '(chưa chọn)'}
                                </div>
                            </div>
                            <div class="mt-3 p-3 bg-info text-white rounded">
                                <i class="fas fa-lightbulb mr-2"></i>
                                <small>Sau khi chọn đầy đủ, hệ thống sẽ tự động gợi ý vị trí lưu trữ tối ưu dựa trên loại sản phẩm và thương hiệu.</small>
                            </div>
                        </div>
                        
                        <style>
                            .step-item {
                                padding: 0.5rem 0;
                                border-bottom: 1px solid #f1f1f1;
                            }
                            .step-item:last-child {
                                border-bottom: none;
                            }
                            .step-item.completed {
                                background-color: #f8f9fa;
                            }
                        </style>
                    `,
                    showConfirmButton: true,
                    confirmButtonText: '<i class="fas fa-arrow-left mr-2"></i>Tôi hiểu rồi',
                    customClass: {
                        confirmButton: 'btn-primary'
                    }
                });
                return;
            }
            
            // If we don't have productId but have product info, try to get it
            if (!productId && productType && productBrand && productName) {
                // Use the first available size to get product info
                if (availableSizes.length > 0) {
                    const firstSize = availableSizes[0];
                    // We can use the SKU response that should have set the productId
                    // Or we can call the suggestion API with the product information we have
                    callStorageSuggestionAPI(null, productType, productBrand, productName);
                    return;
                } else {
                    Swal.fire({
                        icon: 'info', 
                        title: '<i class="fas fa-info-circle text-info mr-2"></i>Chưa có kích thước khả dụng',
                        html: `
                            <div class="text-center">
                                <div class="mb-3">
                                    <i class="fas fa-ruler text-muted fa-3x mb-3"></i>
                                    <p>Chưa tìm thấy kích thước nào cho sản phẩm này.</p>
                                </div>
                                <div class="alert alert-info">
                                    <i class="fas fa-lightbulb mr-2"></i>
                                    <strong>Gợi ý:</strong> Hãy đợi hệ thống tải các kích thước có sẵn, 
                                    hoặc kiểm tra lại thông tin sản phẩm đã chọn.
                                </div>
                                <small class="text-muted">
                                    Tính năng gợi ý vị trí sẽ khả dụng sau khi có thông tin kích thước.
                                </small>
                            </div>
                        `,
                        showConfirmButton: true,
                        confirmButtonText: '<i class="fas fa-sync-alt mr-2"></i>Tôi hiểu',
                        customClass: {
                            confirmButton: 'btn-info'
                        }
                    });
                    return;
                }
            }
            
            callStorageSuggestionAPI(productId);
        }
        
        function callStorageSuggestionAPI(productId, productType = null, productBrand = null, productName = null) {
            const requestData = {
                warehouse_id: <?php echo $userWarehouseId; ?>
            };
            
            if (productId) {
                requestData.product_id = productId;
            } else {
                // Use product information instead
                requestData.product_type = productType || $('#productType').val();
                requestData.product_brand = productBrand || $('#productBrand').val(); 
                requestData.product_name = productName || $('#productName').val();
            }
            
            // Thêm các vị trí đã dùng trong phiếu hiện tại (nếu đang ở chế độ thêm sản phẩm)
            if (excludedLocations && excludedLocations.length > 0) {
                requestData.exclude_locations = excludedLocations;
            }
            
            $.ajax({
                url: 'api_goi_y_luu_tru.php',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify(requestData),
                success: function(response) {
                    if (response.success && response.suggestions.length > 0) {
                        showStorageSuggestionModal(response);
                    } else {
                        // Hiển thị thông báo với action nếu có
                        const actionRequired = response.action_required;
                        const message = response.message || 'Không tìm thấy vị trí phù hợp';
                        
                        let htmlContent = `
                            <div class="text-center">
                                <div class="mb-3">
                                    <i class="fas fa-exclamation-triangle text-warning fa-3x mb-3"></i>
                                    <h5>${message}</h5>
                                </div>
                        `;
                        
                        if (actionRequired) {
                            htmlContent += `
                                <div class="alert alert-info text-left">
                                    <h6><i class="fas fa-info-circle mr-2"></i>${actionRequired.title}</h6>
                                    <p class="mb-2">${actionRequired.description}</p>
                                    <a href="${actionRequired.link}" target="_blank" class="btn btn-sm btn-primary">
                                        <i class="fas fa-external-link-alt mr-1"></i>${actionRequired.link_text}
                                    </a>
                                </div>
                            `;
                        }
                        
                        htmlContent += `
                                <div class="alert alert-warning mt-3">
                                    <i class="fas fa-hand-point-right mr-2"></i>
                                    <strong>Tùy chọn:</strong> Bạn có thể nhập vị trí thủ công hoặc tạo vị trí mới trước.
                                </div>
                            </div>
                        `;
                        
                        Swal.fire({
                            icon: 'warning',
                            title: 'Chưa có vị trí kho',
                            html: htmlContent,
                            showCancelButton: actionRequired ? true : false,
                            confirmButtonText: '<i class="fas fa-keyboard mr-2"></i>Nhập thủ công',
                            cancelButtonText: actionRequired ? `<i class="fas fa-plus mr-2"></i>Tạo vị trí mới` : null,
                            customClass: {
                                confirmButton: 'btn-warning',
                                cancelButton: 'btn-primary'
                            }
                        }).then((result) => {
                            if (result.dismiss === Swal.DismissReason.cancel && actionRequired) {
                                // Mở trang tạo vị trí mới trong tab mới
                                window.open(actionRequired.link, '_blank');
                            }
                        });
                    }
                },
                error: function(xhr) {
                    console.error('Storage suggestion error:', xhr.responseText);
                    Swal.fire({
                        icon: 'error',
                        title: '<i class="fas fa-exclamation-circle text-danger mr-2"></i>Lỗi hệ thống',
                        html: `
                            <div class="text-center">
                                <div class="mb-3">
                                    <i class="fas fa-server text-danger fa-3x mb-3"></i>
                                    <p>Không thể kết nối đến hệ thống gợi ý vị trí lưu trữ.</p>
                                </div>
                                <div class="alert alert-danger">
                                    <i class="fas fa-bug mr-2"></i>
                                    <strong>Lỗi kỹ thuật:</strong> Vui lòng thử lại sau hoặc liên hệ quản trị viên.
                                </div>
                                <div class="mt-3">
                                    <small class="text-muted">
                                        <i class="fas fa-tools mr-1"></i>
                                        Trong thời gian này, bạn có thể nhập vị trí lưu trữ thủ công.
                                    </small>
                                </div>
                            </div>
                        `,
                        showConfirmButton: true,
                        confirmButtonText: '<i class="fas fa-redo mr-2"></i>Thử lại',
                        showCancelButton: true,
                        cancelButtonText: '<i class="fas fa-keyboard mr-2"></i>Nhập thủ công',
                        customClass: {
                            confirmButton: 'btn-danger',
                            cancelButton: 'btn-secondary'
                        }
                    }).then((result) => {
                        if (result.isConfirmed) {
                            // Thử lại
                            callStorageSuggestionAPI(productId, productType, productBrand, productName);
                        }
                    });
                }
            });
        }
        
        function showStorageSuggestionModal(response) {
            let html = `
                <div class="storage-suggestions">
                    <!-- Thông tin sản phẩm -->
                    <div class="product-info-card mb-4 p-3 bg-light rounded">
                        <div class="d-flex align-items-center mb-3">
                            <i class="fas fa-box text-primary mr-2 fa-lg"></i>
                            <h6 class="mb-0 text-primary font-weight-bold">${response.product.name}</h6>
                        </div>
                        <div class="row">
                            <div class="col-4">
                                <small class="text-muted">Loại:</small><br>
                                <strong class="text-dark">${response.product.type}</strong>
                            </div>
                            <div class="col-4">
                                <small class="text-muted">Brand:</small><br>
                                <strong class="text-dark">${response.product.brand}</strong>
                            </div>
                            <div class="col-4">
                                <small class="text-muted">Size:</small><br>
                                <strong class="text-dark">${response.product.size || 'N/A'}</strong>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Chiến lược áp dụng -->
                    <div class="strategy-card mb-3 p-3 border rounded">
                        <div class="d-flex align-items-center mb-2">
                            <i class="fas fa-brain text-info mr-2 fa-lg"></i>
                            <h6 class="mb-0 text-info font-weight-bold">${response.strategy.phase}: ${response.strategy.name}</h6>
                        </div>
                        <small class="text-muted">${response.strategy.description}</small>
                        
                        ${response.zone_allocation ? `
                        <div class="mt-3 bg-light p-2 rounded">
                            <div class="row">
                                <div class="col-6">
                                    <small class="text-muted">Zone được chọn:</small><br>
                                    <strong class="text-success">Zone ${response.zone_allocation.preferred_zone}</strong><br>
                                    <small>${response.zone_allocation.zone_purpose}</small>
                                </div>
                                <div class="col-6">
                                    <small class="text-muted">Shelf logic:</small><br>
                                    <strong class="text-info">Shelf ${response.zone_allocation.shelf_level}</strong><br>
                                    <small>${response.zone_allocation.shelf_logic}</small>
                                </div>
                            </div>
                        </div>
                        ` : ''}
                    </div>
                    
                    ${response.explanation ? `
                    <!-- Giải thích chi tiết -->
                    <div class="explanation-box mb-3 p-3 border-left border-warning bg-light">
                        <i class="fas fa-info-circle text-warning mr-2"></i>
                        <small class="text-dark"><strong>Giải thích:</strong> ${response.explanation}</small>
                    </div>
                    ` : ''}
                    
                    <!-- Danh sách vị trí gợi ý -->
                    <h6 class="mb-3">
                        <i class="fas fa-map-marker-alt text-success mr-2"></i>
                        Vị trí được đề xuất (${response.suggestions.length} vị trí)
                    </h6>
                    
                    <div class="suggestions-list">
            `;
            
            response.suggestions.forEach((suggestion, index) => {
                const priorityColor = suggestion.priority === 'High' ? 'success' : 'warning';
                const priorityIcon = suggestion.priority === 'High' ? 'star' : 'star-half-alt';
                const priorityText = suggestion.priority === 'High' ? 'Ưu tiên cao' : 'Ưu tiên trung bình';
                const isNewLocation = suggestion.create_new || false;
                
                html += `
                    <div class="suggestion-card mb-3 border rounded shadow-sm ${index === 0 ? 'border-success border-2' : ''}" 
                         style="cursor: pointer; transition: all 0.3s ease;" 
                         onclick="selectSuggestion('${suggestion.location_code}', ${index})"
                         onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 16px rgba(0,0,0,0.2)'"
                         onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 1px 3px rgba(0,0,0,0.12)'">
                        
                        <div class="p-3 ${index === 0 ? 'bg-light' : ''}">
                            <!-- Header của card -->
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <div class="d-flex align-items-center">
                                    <i class="fas ${isNewLocation ? 'fa-plus-circle' : 'fa-warehouse'} text-${priorityColor} mr-2 fa-lg"></i>
                                    <h5 class="mb-0 font-weight-bold text-dark">${suggestion.location_code}</h5>
                                </div>
                                <div class="text-right">
                                    <span class="badge badge-${priorityColor} px-3 py-2">
                                        <i class="fas fa-${priorityIcon} mr-1"></i>${priorityText}
                                    </span>
                                    ${index === 0 ? '<br><small class="text-success mt-1 font-weight-bold"><i class="fas fa-thumbs-up mr-1"></i>Khuyến nghị nhất</small>' : ''}
                                    ${isNewLocation ? '<br><small class="text-info mt-1"><i class="fas fa-exclamation-circle mr-1"></i>Vị trí mới (cần tạo)</small>' : ''}
                                </div>
                            </div>
                            
                            <!-- Mô tả vị trí -->
                            <div class="mb-3 pl-4">
                                <small class="text-muted">📍 Mô tả:</small><br>
                                <span class="text-dark">${suggestion.description}</span>
                            </div>
                            
                            <!-- Lý do gợi ý -->
                            <div class="pl-4">
                                <small class="text-muted mb-2 d-block">
                                    <i class="fas fa-lightbulb text-warning mr-1"></i><strong>Lý do đề xuất:</strong>
                                </small>
                                <div class="reasoning-list">
                `;
                
                suggestion.reasoning.forEach((reason, reasonIndex) => {
                    html += `
                        <div class="mb-1">
                            <small class="text-dark">
                                <i class="fas fa-check-circle text-success mr-1"></i>${reason}
                            </small>
                        </div>
                    `;
                });
                
                html += `
                                </div>
                            </div>
                            
                            <!-- Nút chọn -->
                            <div class="text-center mt-3 pt-3 border-top">
                                <button class="btn btn-${priorityColor} btn-md px-5 shadow-sm" onclick="event.stopPropagation(); selectSuggestion('${suggestion.location_code}', ${index})">
                                    <i class="fas fa-check-double mr-2"></i>Chọn vị trí này
                                </button>
                            </div>
                        </div>
                    </div>
                `;
            });
            
            html += `
                    </div>
                    
                    <!-- Next Steps Guide -->
                    <div class="next-steps-guide mt-4 p-3 bg-gradient-info text-white rounded shadow">
                        <h6 class="mb-3">
                            <i class="fas fa-tasks mr-2"></i>Các bước tiếp theo
                        </h6>
                        <div class="row">
                            <div class="col-12 mb-2">
                                <div class="d-flex align-items-start">
                                    <span class="badge badge-light text-info mr-2 font-weight-bold" style="min-width: 30px;">1</span>
                                    <small>${response.next_steps.step_1}</small>
                                </div>
                            </div>
                            <div class="col-12 mb-2">
                                <div class="d-flex align-items-start">
                                    <span class="badge badge-light text-info mr-2 font-weight-bold" style="min-width: 30px;">2</span>
                                    <small>${response.next_steps.step_2}</small>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="d-flex align-items-start">
                                    <span class="badge badge-light text-info mr-2 font-weight-bold" style="min-width: 30px;">3</span>
                                    <small>${response.next_steps.step_3}</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <style>
                    .suggestion-card {
                        background: #ffffff;
                        border: 2px solid #e9ecef;
                    }
                    
                    .suggestion-card:hover {
                        background: #f8f9fa;
                        border-color: #28a745 !important;
                    }
                    
                    .border-2 {
                        border-width: 3px !important;
                    }
                    
                    .product-info-card {
                        border-left: 5px solid #007bff;
                        background: linear-gradient(135deg, #ffffff 0%, #f0f8ff 100%);
                    }
                    
                    .strategy-card {
                        border-left: 5px solid #17a2b8;
                        background: linear-gradient(135deg, #ffffff 0%, #e7f7f9 100%);
                    }
                    
                    .explanation-box {
                        border-left-width: 4px;
                    }
                    
                    .bg-gradient-info {
                        background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
                    }
                    
                    .reasoning-list small {
                        line-height: 1.6;
                    }
                </style>
            `;
            
            Swal.fire({
                title: '<i class="fas fa-brain text-warning mr-2"></i>Gợi Ý Vị Trí Lưu Trữ Thông Minh',
                html: html,
                width: '800px',
                showConfirmButton: false,
                showCancelButton: true,
                cancelButtonText: '<i class="fas fa-times mr-2"></i>Đóng',
                customClass: {
                    content: 'text-left p-0',
                    cancelButton: 'btn-secondary btn-lg'
                },
                buttonsStyling: true
            });
        }
        
        function selectSuggestion(locationCode, index) {
            // Đếm số lượng rows sẽ được cập nhật
            let updatedCount = 0;
            
            // Apply to all size rows in the table
            $('#sizesTable tbody tr').each(function() {
                const $locationInput = $(this).find('.size-location');
                if ($locationInput.length > 0) {
                    $locationInput.val(locationCode);
                    // Trigger change event to update sizesTableData
                    $locationInput.trigger('change');
                    updatedCount++;
                }
            });
            
            Swal.close();
            
            // Hiển thị thông báo thành công với thông tin chi tiết
            Swal.fire({
                icon: 'success',
                title: '<i class="fas fa-check-circle text-success mr-2"></i>Đã áp dụng thành công!',
                html: `
                    <div class="text-center">
                        <div class="mb-3">
                            <i class="fas fa-warehouse text-primary fa-2x mb-2"></i>
                            <h5 class="text-primary">Vị trí: <strong>${locationCode}</strong></h5>
                        </div>
                        <div class="alert alert-success">
                            <i class="fas fa-info-circle mr-2"></i>
                            Đã cập nhật <strong>${updatedCount} kích thước</strong> với vị trí lưu trữ mới
                        </div>
                        <small class="text-muted">
                            <i class="fas fa-lightbulb mr-1"></i>
                            Bạn có thể điều chỉnh vị trí cho từng kích thước riêng lẻ nếu cần
                        </small>
                    </div>
                `,
                timer: 2500,
                showConfirmButton: true,
                confirmButtonText: '<i class="fas fa-thumbs-up mr-2"></i>Tuyệt vời!',
                customClass: {
                    confirmButton: 'btn-success'
                }
            });
        }

        // Suggest storage location for a specific size
        // Storage location suggestion functionality
        $(document).on('click', '.suggestion-btn-for-size', function() {
            const $button = $(this);
            const size = $button.data('size');
            const $row = $button.closest('.size-row, tr');
            
            console.log('🔍 Storage suggestion clicked for size:', size);
            
            suggestLocationForSize(size, $row);
        });
        
        function suggestLocationForSize(size, $targetRow) {
            console.log('=== suggestLocationForSize called ===');
            console.log('Size received:', size);
            
            // Get product information from various sources
            const productType = $('#productType').val() || $('#productTypeText').val() || '';
            const productBrand = $('#productBrand').val() || $('#brandName').val() || '';
            const productName = $('#productName').val() || '';
            
            // Get variant_id and SKU from row data or sizesTableData
            const rowIndex = $targetRow.index();
            let variantId = null;
            let sku = null;
            
            // Try to get from row data attributes first
            variantId = $targetRow.data('variant-id') || $targetRow.attr('data-variant-id');
            sku = $targetRow.data('sku') || $targetRow.attr('data-sku');
            
            // If not found in row, try to get from sizesTableData
            if (!variantId && sizesTableData && sizesTableData[rowIndex]) {
                variantId = sizesTableData[rowIndex].variantId;
                sku = sizesTableData[rowIndex].sku;
            }
            
            console.log('Variant ID:', variantId);
            console.log('SKU:', sku);
            
            if (!productType || !productBrand || !productName) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Thiếu thông tin sản phẩm',
                    text: 'Vui lòng đảm bảo có đầy đủ thông tin loại sản phẩm, thương hiệu và tên sản phẩm!',
                    confirmButtonText: 'Đã hiểu'
                });
                return;
            }
            
            // Call API with size information and variant_id
            callStorageSuggestionAPIForSize(productType, productBrand, productName, size, variantId, sku, $targetRow);
        }
        
        function callStorageSuggestionAPIForSize(productType, productBrand, productName, size, variantId, sku, $targetRow) {
            // Thu thập danh sách vị trí ĐÃ ĐƯỢC ĐIỀN vào input (các size khác)
            // KHÔNG exclude vị trí đã gợi ý nhưng chưa chọn
            const usedLocations = [];
            sizesTableData.forEach((item, index) => {
                if (item.storageLocation && item.storageLocation.trim() !== '') {
                    // Chỉ thêm nếu không phải row hiện tại
                    const currentIndex = $targetRow.index();
                    if (index !== currentIndex) {
                        const trimmedLocation = item.storageLocation.trim();
                        if (!usedLocations.includes(trimmedLocation)) {
                            usedLocations.push(trimmedLocation);
                        }
                    }
                }
            });
            
            // Thêm các vị trí đã dùng trong phiếu hiện tại (nếu đang ở chế độ thêm sản phẩm)
            if (excludedLocations && excludedLocations.length > 0) {
                excludedLocations.forEach(loc => {
                    if (!usedLocations.includes(loc)) {
                        usedLocations.push(loc);
                    }
                });
            }
            
            console.log('🚫 Excluding already used locations:', usedLocations);
            
            const requestData = {
                warehouse_id: <?php echo $userWarehouseId; ?>,
                product_type: productType,
                product_brand: productBrand,
                product_name: productName,
                product_size: size,
                variant_id: variantId,
                sku: sku,
                exclude_locations: usedLocations  // Loại trừ vị trí đã chọn
            };
            
            console.log('=== callStorageSuggestionAPIForSize ===');
            console.log('Request data:', requestData);
            
            // Show loading on button
            const $button = $targetRow.find('.suggestion-btn-for-size');
            const originalHtml = $button.html();
            $button.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>');
            
            $.ajax({
                url: 'api_goi_y_luu_tru.php',
                method: 'POST',
                data: JSON.stringify(requestData),
                contentType: 'application/json',
                success: function(response) {
                    console.log('=== API Response ===');
                    console.log('Full response:', response);
                    
                    if (response.success && response.suggestions && response.suggestions.length > 0) {
                        showStorageSuggestionModalForSize(response, $targetRow);
                    } else {
                        // Show message when no suggestions found
                        const message = response.message || 'Không tìm thấy vị trí phù hợp';
                        const product = response.product || {};
                        
                        Swal.fire({
                            icon: 'warning',
                            title: 'Chưa có vị trí kho',
                            html: `
                                <div class="text-center">
                                    <p>${message}</p>
                                    ${product.type ? '<div class="alert alert-light"><strong>Sản phẩm:</strong> ' + product.type + ' - ' + product.brand + (product.size ? ' (Size: ' + product.size + ')' : '') + '</div>' : ''}
                                    <div class="alert alert-warning">
                                        <i class="fas fa-hand-point-right mr-2"></i>
                                        <strong>Tùy chọn:</strong> Bạn có thể nhập vị trí thủ công hoặc tạo vị trí mới trước.
                                    </div>
                                </div>
                            `,
                            confirmButtonText: '<i class="fas fa-edit mr-1"></i>Nhập thủ công',
                            width: '600px'
                        });
                    }
                },
                error: function(xhr, status, error) {
                    console.error('❌ Storage suggestion API error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Lỗi hệ thống',
                        text: 'Không thể kết nối đến hệ thống gợi ý vị trí. Vui lòng thử lại sau.',
                        confirmButtonText: 'Đã hiểu'
                    });
                },
                complete: function() {
                    // Restore button
                    $button.prop('disabled', false).html(originalHtml);
                }
            });
        }
        
        function showStorageSuggestionModalForSize(apiResponse, $targetRow) {
            const suggestions = apiResponse.suggestions || [];
            const productInfo = apiResponse.product || {};
            const strategy = apiResponse.strategy || {};
            
            if (suggestions.length === 0) {
                return;
            }
            
            // ✅ KHÔNG track vị trí khi chỉ hiển thị modal
            // Chỉ track khi người dùng THỰC SỰ CHỌN vị trí
            // → Kết quả: Ấn nút gợi ý nhiều lần sẽ luôn ra kết quả GIỐNG NHAU
            
            const productDisplay = productInfo.type && productInfo.brand ? 
                `${productInfo.type} - ${productInfo.brand}${productInfo.size ? ' (Size: ' + productInfo.size + ')' : ''}` :
                'Sản phẩm';
            
            // Hiển thị strategy/phase information nếu có
            let strategyInfo = '';
            if (strategy.phase) {
                // Kiểm tra nếu có conflict (vị trí cố định bị trùng)
                const hasConflict = strategy.phase.includes('conflict') || apiResponse.warning;
                const phaseIcon = hasConflict ? '⚠️' :
                                 strategy.phase.includes('tồn tại') || strategy.phase.includes('cố định') ? '🎯' : 
                                 strategy.phase.includes('cùng kệ') ? '⭐' : 
                                 strategy.phase.includes('phù hợp') ? '✅' : '📍';
                const alertClass = hasConflict ? 'alert-warning' : 'alert-info';
                
                strategyInfo = `
                    <div class="alert ${alertClass} mb-3">
                        <strong>${phaseIcon} ${strategy.phase}</strong>
                        ${strategy.description ? '<br><small>' + strategy.description + '</small>' : ''}
                    </div>
                `;
            }
            
            // Hiển thị cảnh báo conflict nếu có
            let conflictWarning = '';
            if (apiResponse.warning) {
                conflictWarning = `
                    <div class="alert alert-danger border-danger mb-3">
                        <h6 class="mb-2">
                            <i class="fas fa-exclamation-triangle mr-2"></i>
                            <strong>CẢNH BÁO TRÙNG LẶP VỊ TRÍ</strong>
                        </h6>
                        <p class="mb-0">${apiResponse.warning}</p>
                        <hr class="my-2">
                        <small>
                            <i class="fas fa-info-circle mr-1"></i>
                            Mỗi size chỉ nên có 1 vị trí cố định. Vui lòng kiểm tra lại việc chọn size trong phiếu nhập này!
                        </small>
                    </div>
                `;
            }
            
            const suggestionsHtml = suggestions.map((suggestion, index) => {
                // API trả về priority: Highest, High, Medium, Low, Critical
                const priority = suggestion.priority || 'High';
                const isHighPriority = priority === 'High' || priority === 'Highest';
                const isCritical = priority === 'Critical';
                const hasConflict = suggestion.has_conflict === true;
                
                // Màu sắc theo priority và conflict
                const priorityColor = hasConflict ? 'warning' : (isCritical ? 'danger' : 'success');
                
                // Icon theo priority
                const priorityIcon = hasConflict ? '⚠️' : (isCritical ? '❗' : '⭐');
                
                // Border highlight
                const borderClass = hasConflict ? 'border-warning border-2' : (isHighPriority ? 'border-success' : '');
                
                // Label
                const priorityLabel = hasConflict ? 'Có conflict' : (isCritical ? 'Quan trọng' : (isHighPriority ? 'Khuyến nghị' : 'Phù hợp'));
                
                // Lấy reasoning array hoặc description
                const reasoning = suggestion.reasoning ? suggestion.reasoning.join('<br>') : 
                                 (suggestion.description || 'Vị trí phù hợp cho sản phẩm này');
                
                return `
                    <div class="card mb-3 suggestion-card ${borderClass}" 
                         data-location-code="${suggestion.location_code}" 
                         data-has-conflict="${hasConflict}"
                         style="cursor: pointer; transition: all 0.2s;">
                        <div class="card-body p-3 ${hasConflict ? 'bg-light-warning' : ''}">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="card-title mb-0">
                                    <i class="fas fa-map-marker-alt text-primary mr-2"></i>
                                    <strong>${suggestion.location_code}</strong>
                                    ${isHighPriority || hasConflict ? '<span class="badge badge-' + priorityColor + ' ml-2">' + priorityIcon + ' ' + priorityLabel + '</span>' : ''}
                                </h6>
                                <span class="badge badge-${priorityColor}">${priorityIcon} ${hasConflict ? 'Vị trí cố định' : 'Gợi ý'}</span>
                            </div>
                            <div class="card-text small ${hasConflict ? 'text-danger' : 'text-muted'} mb-2">
                                ${reasoning}
                            </div>
                            ${suggestion.location_id ? `<small class="text-info">ID: ${suggestion.location_id}</small>` : ''}
                        </div>
                    </div>
                `;
            }).join('');
            
            const modalHtml = `
                <div class="modal fade" id="storageSuggestionModal" tabindex="-1" role="dialog">
                    <div class="modal-dialog modal-lg" role="document">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">
                                    <i class="fas fa-warehouse mr-2"></i>Gợi ý vị trí lưu trữ
                                </h5>
                                <button type="button" class="close" data-dismiss="modal">
                                    <span>&times;</span>
                                </button>
                            </div>
                            <div class="modal-body">
                                <div class="alert alert-light border">
                                    <strong>📦 Sản phẩm:</strong> ${productDisplay}
                                </div>
                                ${conflictWarning}
                                ${strategyInfo}
                                <h6 class="mb-3">
                                    <i class="fas fa-list mr-2"></i>Các vị trí được đề xuất 
                                    <small class="text-muted">(${suggestions.length} vị trí)</small>
                                </h6>
                                <div class="suggestions-list" style="max-height: 400px; overflow-y: auto;">
                                    ${suggestionsHtml}
                                </div>
                                <div class="text-center mt-3">
                                    <small class="text-muted">
                                        <i class="fas fa-info-circle mr-1"></i>
                                        Click vào vị trí để chọn
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            // Remove existing modal and add new one
            $('#storageSuggestionModal').remove();
            $('body').append(modalHtml);
            
            // Set up click handlers for suggestion cards
            $('.suggestion-card').on('click', function() {
                const locationCode = $(this).data('location-code');
                const hasConflict = $(this).data('has-conflict') === true || $(this).data('has-conflict') === 'true';
                
                if (locationCode) {
                    // Nếu có conflict, hiển thị cảnh báo trước khi áp dụng
                    if (hasConflict) {
                        Swal.fire({
                            icon: 'warning',
                            title: 'Cảnh báo trùng lặp vị trí',
                            html: `
                                <div class="text-left">
                                    <p><strong>Vị trí "${locationCode}"</strong> là vị trí cố định của size này nhưng đang bị trùng với size khác trong phiếu hiện tại.</p>
                                    <div class="alert alert-warning mt-3">
                                        <i class="fas fa-exclamation-triangle mr-2"></i>
                                        <strong>Lưu ý:</strong> Mỗi size chỉ nên có 1 vị trí cố định. Vui lòng kiểm tra lại:
                                        <ul class="mb-0 mt-2">
                                            <li>Có phải bạn đã chọn size này ở hàng khác?</li>
                                            <li>Hoặc size khác đang dùng nhầm vị trí này?</li>
                                        </ul>
                                    </div>
                                    <p class="mb-0 mt-2">Bạn có muốn tiếp tục chọn vị trí này không?</p>
                                </div>
                            `,
                            showCancelButton: true,
                            confirmButtonText: 'Vẫn chọn vị trí này',
                            cancelButtonText: 'Hủy',
                            confirmButtonColor: '#f39c12',
                            cancelButtonColor: '#6c757d'
                        }).then((result) => {
                            if (result.isConfirmed) {
                                applyLocationToRow(locationCode, $targetRow);
                            }
                        });
                    } else {
                        // Không có conflict, áp dụng trực tiếp
                        applyLocationToRow(locationCode, $targetRow);
                    }
                }
            });
            
            // Function để áp dụng vị trí vào row
            function applyLocationToRow(locationCode, $row) {
                // Find and update the location input in the target row
                const $locationInput = $row.find('.size-location');
                $locationInput.val(locationCode);
                
                // Cập nhật sizesTableData
                const rowIndex = $row.index();
                if (sizesTableData[rowIndex]) {
                    sizesTableData[rowIndex].storageLocation = locationCode;
                }
                
                // ✅ CHỈ track vị trí khi người dùng CHỌN (không track khi chỉ xem)
                // Vị trí đã chọn sẽ được exclude cho các size KHÁC
                if (!window.suggestedLocations) {
                    window.suggestedLocations = [];
                }
                if (!window.suggestedLocations.includes(locationCode)) {
                    window.suggestedLocations.push(locationCode);
                    console.log('✅ Tracked selected location:', locationCode);
                }
                
                $('#storageSuggestionModal').modal('hide');
                
                Swal.fire({
                    icon: 'success',
                    title: 'Đã chọn vị trí',
                    text: `Vị trí "${locationCode}" đã được áp dụng`,
                    timer: 2000,
                    showConfirmButton: false
                });
            }
            
            // Hover effect
            $('.suggestion-card').hover(
                function() {
                    $(this).addClass('shadow-sm').css('transform', 'scale(1.02)');
                },
                function() {
                    $(this).removeClass('shadow-sm').css('transform', 'scale(1)');
                }
            );
            
            // Show modal
            $('#storageSuggestionModal').modal('show');
        }

        // Track loading state to prevent duplicate calls
        let isLoadingSizes = false;
        
        function loadAllAvailableSizes(type, brand, color, name) {
            // Prevent duplicate loading
            if (isLoadingSizes) {
                console.log('=== loadAllAvailableSizes: Already loading, skipping ===');
                return;
            }
            
            console.log('=== loadAllAvailableSizes called ===');
            console.log('Parameters:', { type, brand, color, name });
            console.log('Warehouse ID:', <?php echo $userWarehouseId; ?>);
            
            isLoadingSizes = true;
            
            $.ajax({
                url: 'api_bo_loc_theo_tang.php',
                method: 'POST',
                data: {
                    field: 'size',
                    filters: {
                        type: type,
                        brand: brand,
                        color: color,
                        name: name
                    },
                    warehouse_id: <?php echo $userWarehouseId; ?>
                },
                success: function(response) {
                    console.log('=== Cascading Filters API Response ===');
                    console.log('Raw response:', response);
                    console.log('Response type:', typeof response);
                    
                    // Try to parse if string
                    if (typeof response === 'string') {
                        try {
                            response = JSON.parse(response);
                            console.log('Parsed response:', response);
                        } catch (e) {
                            console.error('Failed to parse response as JSON:', e);
                            isLoadingSizes = false;
                            return;
                        }
                    }
                    
                    if (response.success && response.data && response.data.length > 0) {
                        console.log('=== Found sizes ===', response.data);
                        
                        // Clear existing sizes table data
                        sizesTableData = [];
                        availableSizes = [];
                        
                        // Get product_id from the first size response
                        // We'll get it in the SKU API call
                        
                        // Load each size with its SKU
                        let loadedCount = 0;
                        const totalSizes = response.data.length;
                        console.log('Loading SKU for', totalSizes, 'sizes');
                        
                        response.data.forEach(size => {
                            console.log('Loading SKU for size:', size);
                            
                            // Get SKU for this size
                            $.ajax({
                                url: 'api_lay_du_lieu_san_pham.php',
                                method: 'POST',
                                data: {
                                    action: 'get_sku',
                                    type: type,
                                    brand: brand,
                                    color: color,
                                    name: name,
                                    size: size,
                                    warehouse_id: <?php echo $userWarehouseId; ?>
                                },
                                success: function(skuResponse) {
                                    console.log('=== SKU Response for size', size, '===');
                                    console.log('Raw SKU response:', skuResponse);
                                    console.log('SKU response type:', typeof skuResponse);
                                    
                                    // Try to parse if string
                                    if (typeof skuResponse === 'string') {
                                        try {
                                            skuResponse = JSON.parse(skuResponse);
                                            console.log('Parsed SKU response:', skuResponse);
                                        } catch (e) {
                                            console.error('Failed to parse SKU response as JSON:', e);
                                        }
                                    }
                                    
                                    if (skuResponse.success && skuResponse.sku) {
                                        console.log('Successfully loaded SKU for size', size, ':', skuResponse.sku);
                                        
                                        // Set product ID when we get it from the first response
                                        if (skuResponse.product_id && !$('#productId').val()) {
                                            $('#productId').val(skuResponse.product_id);
                                            console.log('Set productId to:', skuResponse.product_id);
                                        }
                                        
                                        // Check if size already exists in availableSizes to avoid duplicates
                                        const existingSize = availableSizes.find(item => item.size === size);
                                        if (!existingSize) {
                                            // Add to available sizes for selection with prices
                                            availableSizes.push({
                                                size: size,
                                                sku: skuResponse.sku,
                                                variantId: skuResponse.variant_id || null,
                                                sizeText: size,
                                                unitPrice: skuResponse.existing_unit_price || 0,
                                                salePrice: skuResponse.existing_sale_price || 0,
                                                colors: skuResponse.colors || $('#productColor').val() || 'N/A'
                                            });
                                            console.log('Added size to availableSizes:', size, 'with prices - unit:', skuResponse.existing_unit_price, 'sale:', skuResponse.existing_sale_price);
                                        } else {
                                            console.log('Size already exists in availableSizes, skipping:', size);
                                        }
                                        
                                        console.log('Current availableSizes:', availableSizes);
                                    } else {
                                        console.error('Failed to get SKU for size', size, ':', skuResponse);
                                    }
                                    
                                    loadedCount++;
                                    console.log('Loaded count:', loadedCount, '/', totalSizes);
                                    
                                    if (loadedCount === totalSizes) {
                                        console.log('=== All sizes loaded ===');
                                        console.log('Final availableSizes:', availableSizes);
                                        console.log('Calling updateAvailableSizesDisplay()');
                                        updateAvailableSizesDisplay();
                                        isLoadingSizes = false; // Reset loading state
                                    }
                                },
                                error: function(xhr, status, error) {
                                    console.error('=== Error loading SKU for size', size, '===');
                                    console.error('XHR:', xhr);
                                    console.error('Status:', status);
                                    console.error('Error:', error);
                                    console.error('Response text:', xhr.responseText);
                                    
                                    loadedCount++;
                                    if (loadedCount === totalSizes) {
                                        console.log('All sizes processed (with errors), calling updateAvailableSizesDisplay()');
                                        updateAvailableSizesDisplay();
                                        isLoadingSizes = false; // Reset loading state
                                    }
                                }
                            });
                        });
                    } else {
                        // No sizes available or error
                        console.log('=== No sizes available or error ===');
                        console.log('Response:', response);
                        
                        if (response.message === 'Unauthorized') {
                            console.log('Unauthorized access detected');
                            Swal.fire({
                                icon: 'warning',
                                title: 'Chưa đăng nhập',
                                text: 'Vui lòng đăng nhập lại để sử dụng chức năng này!',
                                confirmButtonText: 'Đến trang đăng nhập'
                            }).then(() => {
                                window.location.href = 'login.php';
                            });
                            return;
                        }
                        
                        availableSizes = [];
                        console.log('Setting availableSizes to empty array');
                        updateAvailableSizesDisplay();
                        isLoadingSizes = false; // Reset loading state
                    }
                },
                error: function(xhr, status, error) {
                    console.error('=== Error loading available sizes ===');
                    console.error('XHR:', xhr);
                    console.error('Status:', status);
                    console.error('Error:', error);
                    console.error('Response text:', xhr.responseText);
                    isLoadingSizes = false; // Reset loading state
                    
                    let errorMessage = 'Có lỗi khi tải dữ liệu kích thước';
                    if (xhr.status === 401) {
                        errorMessage = 'Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại!';
                    }
                    
                    Swal.fire({
                        icon: 'error',
                        title: 'Lỗi tải dữ liệu',
                        text: errorMessage
                    });
                    
                    availableSizes = [];
                    updateAvailableSizesDisplay();
                }
            });
        }

        function updateAvailableSizesDisplay() {
            // Update both tables
            updateAvailableSizesTable();
            updateSizesTable();
        }

        // Multiple sizes functionality
        let sizesTableData = [];
        let availableSizes = [];
        let multipleSizesMode = false;

        function toggleMultipleSizesMode() {
            multipleSizesMode = !multipleSizesMode;
            
            if (multipleSizesMode) {
                $('#multipleSizesSection').show();
                $('#enableMultipleSizes').html('<i class="fas fa-times mr-2"></i>Hủy nhập nhiều kích thước').removeClass('btn-primary').addClass('btn-secondary');
                $('#addToReceipt').hide();
                $('#addAllSizesToReceipt').show();
                
                // Clear and reset sizes table
                sizesTableData = [];
                updateSizesTable();
            } else {
                $('#multipleSizesSection').hide();
                $('#enableMultipleSizes').html('<i class="fas fa-layer-group mr-2"></i>Nhập nhiều kích thước').removeClass('btn-secondary').addClass('btn-primary');
                $('#addToReceipt').show();
                $('#addAllSizesToReceipt').hide();
            }
        }

        function addSizeToTable() {
            // This function is no longer needed as we use addSizeFromAvailable instead
            Swal.fire({
                icon: 'info',
                title: 'Sử dụng danh sách có sẵn',
                text: 'Vui lòng chọn kích thước từ danh sách hiển thị trong bảng!'
            });
        }

        function updateAvailableSizesTable() {
            const tbody = $('#availableSizesTable tbody');
            tbody.empty();

            if (availableSizes.length === 0) {
                tbody.append(`
                    <tr>
                        <td colspan="6" class="text-center text-muted">
                            <i class="fas fa-info-circle mr-2"></i>Không có size nào trong database
                        </td>
                    </tr>
                `);
                return;
            }

            availableSizes.forEach(size => {
                const isSelected = sizesTableData.some(s => s.size === size.size);
                const row = $(`
                    <tr ${isSelected ? 'style="display: none;"' : ''}>
                        <td><strong>${size.size}</strong></td>
                        <td><code style="color: #999; background: #f5f5f5; padding: 2px 6px; border-radius: 3px; font-size: 11px;">${size.sku}</code></td>
                        <td>
                            <i class="fas fa-shopping-cart text-primary"></i> 
                            <strong>${size.unitPrice > 0 ? new Intl.NumberFormat('vi-VN').format(size.unitPrice) + ' ₫' : '-'}</strong>
                        </td>
                        <td>
                            <i class="fas fa-tags text-success"></i> 
                            <strong>${size.salePrice > 0 ? new Intl.NumberFormat('vi-VN').format(size.salePrice) + ' ₫' : '-'}</strong>
                        </td>
                        <td><small>${size.colors || 'N/A'}</small></td>
                        <td>
                            <button type="button" class="btn btn-sm btn-primary select-size-btn" 
                                    onclick="addSizeFromAvailable('${size.size}', '${size.sku}')">
                                <i class="fas fa-plus mr-1"></i>Chọn
                            </button>
                        </td>
                    </tr>
                `);
                tbody.append(row);
            });
        }

        function updateSizesTable() {
            const tbody = $('#sizesTable tbody');
            tbody.empty();

            if (sizesTableData.length === 0) {
                $('#selectedSizesCard').hide();
                return;
            }

            // Show card with selected sizes
            $('#selectedSizesCard').show();

            // Show added sizes with input fields
            sizesTableData.forEach((item, index) => {
                console.log('Creating row for size:', item.size, 'at index:', index, 'variantId:', item.variantId);
                
                const row = $(`
                    <tr data-variant-id="${item.variantId || ''}" data-sku="${item.sku || ''}">
                        <td class="text-center align-middle"><strong>${item.size}</strong></td>
                        <td class="text-center align-middle">
                            <code style="color: #999; background: #f5f5f5; padding: 2px 6px; border-radius: 3px; font-size: 11px;">
                                ${item.sku}
                            </code>
                        </td>
                        <td class="align-middle"><small>${item.colors || 'Trắng, Bạc, Xám'}</small></td>
                        <td class="align-middle">
                            <input type="number" class="form-control form-control-sm size-quantity" value="${item.quantity}" 
                                   min="1" onchange="updateSizeData(${index}, 'quantity', this.value)" required>
                        </td>
                        <td class="align-middle">
                            <input type="text" class="form-control form-control-sm size-price price-format-input" 
                                   value="${item.unitPrice > 0 ? new Intl.NumberFormat('vi-VN').format(item.unitPrice) : ''}" 
                                   data-raw-value="${item.unitPrice}" placeholder="0" 
                                   onchange="updateSizeData(${index}, 'unitPrice', this.value)" required>
                            <small class="text-muted d-block mt-1">
                                ${item.unitPrice > 0 ? '<i class="fas fa-database text-primary"></i> Giá cũ: <strong>' + new Intl.NumberFormat('vi-VN').format(item.unitPrice) + ' ₫</strong>' : '<i class="fas fa-edit"></i> Nhập giá nhập mới'}
                            </small>
                        </td>
                        <td class="align-middle">
                            <input type="text" class="form-control form-control-sm sale-price-input price-format-input" 
                                   value="${item.salePrice > 0 ? new Intl.NumberFormat('vi-VN').format(item.salePrice) : ''}" 
                                   data-raw-value="${item.salePrice || ''}" placeholder="Giá bán" 
                                   onchange="updateSizeData(${index}, 'salePrice', this.value)">
                            <small class="text-muted d-block mt-1">
                                ${item.salePrice > 0 ? '<i class="fas fa-database text-success"></i> Giá cũ: <strong>' + new Intl.NumberFormat('vi-VN').format(item.salePrice) + ' ₫</strong>' : '<i class="fas fa-edit"></i> Nhập giá bán mới'}
                            </small>
                        </td>
                        <td class="align-middle">
                            <div class="input-group input-group-sm">
                                <input type="text" class="form-control form-control-sm size-location" value="${item.storageLocation}" 
                                       placeholder="Nhập vị trí..." onchange="updateSizeData(${index}, 'storageLocation', this.value)">
                                <div class="input-group-append">
                                    <button type="button" class="btn btn-info btn-sm suggestion-btn-for-size" 
                                            data-size="${item.size}" 
                                            data-index="${index}"
                                            title="Gợi ý vị trí thông minh cho size ${item.size}">
                                        <i class="fas fa-lightbulb"></i>
                                    </button>
                                </div>
                            </div>
                        </td>
                        <td class="text-center align-middle">
                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeSizeFromTable(${index})" title="Xóa">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                `);
                    
                    // Attach click event handler properly
                    row.find('.suggestion-btn-for-size').on('click', function() {
                        const size = $(this).data('size');
                        const $row = $(this).closest('tr');
                        console.log('Button clicked - Size:', size);
                        suggestLocationForSize(size, $row);
                    });
                    
                    tbody.append(row);
                });

            // Update available sizes table to show selected state
            updateAvailableSizesTable();
        }

        function removeSizeFromTable(index) {
            sizesTableData.splice(index, 1);
            updateSizesTable();
        }

        function updateSizeData(index, field, value) {
            // Handle formatted price values (remove non-digits)
            if (field === 'unitPrice' || field === 'salePrice') {
                const rawValue = value.replace ? value.replace(/[^\d]/g, '') : value;
                value = parseInt(rawValue) || 0;
            }
            
            if (field === 'quantity') {
                sizesTableData[index].quantity = parseInt(value) || 0;
                sizesTableData[index].totalPrice = sizesTableData[index].quantity * sizesTableData[index].unitPrice;
            } else if (field === 'unitPrice') {
                sizesTableData[index].unitPrice = value;  // Already parsed above
                sizesTableData[index].totalPrice = sizesTableData[index].quantity * sizesTableData[index].unitPrice;
            } else if (field === 'salePrice') {
                sizesTableData[index].salePrice = value;  // Already parsed above
            } else if (field === 'storageLocation') {
                sizesTableData[index].storageLocation = value;
                
                // Update the input field styling based on whether it has a value
                const $input = $(`input.size-location[onchange*="${index}"]`);
                if (value && value.trim() !== '') {
                    $input.css('border', '2px solid #28a745'); // Green border when filled
                    $input.removeClass('border-danger');
                } else {
                    $input.css('border', '2px solid #e74a3b'); // Red border when empty
                    $input.addClass('border-danger');
                }
            }
        }

        function addSizeFromAvailable(size, sku) {
            // Check if size already exists
            const existingIndex = sizesTableData.findIndex(item => item.size === size);
            if (existingIndex !== -1) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Kích thước đã tồn tại',
                    text: 'Kích thước này đã được thêm vào danh sách!'
                });
                return;
            }

            // Tìm thông tin từ availableSizes
            const sizeInfo = availableSizes.find(item => item.size === size);
            const unitPrice = sizeInfo && sizeInfo.unitPrice ? sizeInfo.unitPrice : 0;
            const salePrice = sizeInfo && sizeInfo.salePrice ? sizeInfo.salePrice : 0;
            const colors = sizeInfo && sizeInfo.colors ? sizeInfo.colors : '';
            const variantId = sizeInfo && sizeInfo.variantId ? sizeInfo.variantId : null;

            console.log('Adding size from available:', size, 'with unitPrice:', unitPrice, 'salePrice:', salePrice, 'colors:', colors, 'variantId:', variantId);

            sizesTableData.push({
                size: size,
                sku: sku,
                variantId: variantId,
                quantity: 1, // Default quantity
                unitPrice: unitPrice, // Giá nhập từ database
                salePrice: salePrice, // Giá bán từ database
                colors: colors, // Màu sắc
                storageLocation: '', // Will be filled by user
                totalPrice: unitPrice * 1
            });

            // Hide the row in available sizes table
            $('#availableSizesTable tbody tr').each(function() {
                const rowSize = $(this).find('td:first strong').text();
                if (rowSize === size) {
                    $(this).fadeOut('fast');
                }
            });

            updateSizesTable();

            // Show success message
            Swal.fire({
                icon: 'success',
                title: 'Đã thêm kích thước!',
                text: `Kích thước ${size} đã được thêm vào danh sách`,
                timer: 1000,
                showConfirmButton: false
            });
        }

        function addAllAvailableSizes() {
            console.log('=== addAllAvailableSizes called ===');
            
            const productInfo = {
                type: $('#productType').val(),
                brand: $('#productBrand').val(),
                color: $('#productColor').val(),
                name: $('#productName').val()
            };
            
            console.log('Product info:', productInfo);
            console.log('Warehouse ID:', <?php echo $userWarehouseId; ?>);

            $.ajax({
                url: 'api_lay_du_lieu_san_pham.php',
                method: 'POST',
                data: {
                    action: 'get_all_sizes',
                    ...productInfo,
                    warehouse_id: <?php echo $userWarehouseId; ?>
                },
                success: function(response) {
                    console.log('=== get_all_sizes API Response ===');
                    console.log('Raw response:', response);
                    console.log('Response type:', typeof response);
                    
                    // Try to parse if string
                    if (typeof response === 'string') {
                        try {
                            response = JSON.parse(response);
                            console.log('Parsed response:', response);
                        } catch (e) {
                            console.error('Failed to parse response as JSON:', e);
                            return;
                        }
                    }
                    
                    if (response.success && response.sizes.length > 0) {
                        console.log('Found sizes:', response.sizes);
                        
                        Swal.fire({
                            title: 'Thêm tất cả kích thước?',
                            text: `Tìm thấy ${response.sizes.length} kích thước có sẵn. Thêm tất cả với thông tin mặc định?`,
                            icon: 'question',
                            showCancelButton: true,
                            confirmButtonText: 'Thêm tất cả',
                            cancelButtonText: 'Hủy'
                        }).then((result) => {
                            if (result.isConfirmed) {
                                console.log('User confirmed, adding all sizes...');
                                
                                response.sizes.forEach(sizeData => {
                                    console.log('Processing size:', sizeData);
                                    
                                    const existingIndex = sizesTableData.findIndex(item => item.size === sizeData.size);
                                    if (existingIndex === -1) {
                                        const unitPrice = sizeData.unit_price || 0;
                                        const salePrice = sizeData.sale_price || 0;
                                        const newItem = {
                                            size: sizeData.size,
                                            sku: sizeData.sku,
                                            variantId: sizeData.variant_id || null,
                                            quantity: 1, // Default quantity
                                            unitPrice: unitPrice, // Giá nhập từ database
                                            salePrice: salePrice, // Giá bán từ database
                                            storageLocation: sizeData.location_name || '',
                                            totalPrice: unitPrice * 1
                                        };
                                        
                                        console.log('Adding new item with prices - unit:', unitPrice, 'sale:', salePrice, 'variantId:', sizeData.variant_id);
                                        sizesTableData.push(newItem);
                                    } else {
                                        console.log('Size already exists, skipping:', sizeData.size);
                                    }
                                });
                                
                                console.log('Final sizesTableData:', sizesTableData);
                                updateSizesTable();
                                
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Thành công!',
                                    text: 'Đã thêm tất cả kích thước có sẵn',
                                    timer: 1500,
                                    showConfirmButton: false
                                });
                            }
                        });
                    } else {
                        Swal.fire({
                            icon: 'info',
                            title: 'Không có dữ liệu',
                            text: 'Không tìm thấy kích thước có sẵn cho sản phẩm này'
                        });
                    }
                },
                error: function() {
                    Swal.fire({
                        icon: 'error',
                        title: 'Lỗi!',
                        text: 'Không thể tải danh sách kích thước'
                    });
                }
            });
        }

        function addAllSizesToReceipt() {
            <?php if ($fromReceipt && $addMode === 'add'): ?>
            // ⭐ MODE: Thêm vào phiếu có sẵn - Gọi trực tiếp API
            addSizesToExistingReceipt();
            <?php else: ?>
            // MODE: Tạo phiếu mới - Flow bình thường
            addProductToReceipt();
            <?php endif; ?>
        }
        
        <?php if ($fromReceipt && $addMode === 'add'): ?>
        // Hàm mới: Thêm sizes vào phiếu có sẵn
        function addSizesToExistingReceipt() {
            if (sizesTableData.length === 0) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Chưa có kích thước nào',
                    text: 'Vui lòng thêm ít nhất một kích thước vào danh sách!'
                });
                return;
            }

            // Validate all sizes have required data
            for (let i = 0; i < sizesTableData.length; i++) {
                const sizeData = sizesTableData[i];
                if (!sizeData.quantity || sizeData.quantity <= 0) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Thiếu số lượng',
                        text: `Vui lòng nhập số lượng cho kích thước ${sizeData.size}!`
                    });
                    return;
                }
                if (!sizeData.unitPrice || sizeData.unitPrice <= 0) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Thiếu giá nhập',
                        text: `Vui lòng nhập giá nhập cho kích thước ${sizeData.size}!`
                    });
                    return;
                }
                if (!sizeData.storageLocation || sizeData.storageLocation.trim() === '') {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Thiếu vị trí lưu trữ',
                        html: `
                            <div class="text-center">
                                <p>Vui lòng nhập vị trí lưu trữ cho kích thước <strong>${sizeData.size}</strong>!</p>
                                <div class="alert alert-info mt-3">
                                    <i class="fas fa-info-circle mr-2"></i>
                                    <strong>Gợi ý:</strong> Nhấn nút <span class="badge badge-info"><i class="fas fa-warehouse"></i> Gợi ý</span>
                                </div>
                            </div>
                        `,
                        confirmButtonText: 'Đã hiểu'
                    });
                    return;
                }
            }
            
            console.log('✅ Validation passed, adding sizes to receipt #<?php echo $fromReceipt; ?>');
            console.log('📦 Sizes to add:', sizesTableData);
            
            // Show loading
            Swal.fire({
                title: 'Đang thêm vào phiếu...',
                html: `Đang thêm ${sizesTableData.length} size vào phiếu nhập`,
                allowOutsideClick: false,
                allowEscapeKey: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            let addedCount = 0;
            let errorCount = 0;
            const totalSizes = sizesTableData.length;
            
            sizesTableData.forEach(function(size, index) {
                if (!size.variantId) {
                    console.error('❌ Size không có variantId:', size);
                    errorCount++;
                    checkComplete();
                    return;
                }
                
                console.log(`📤 Sending size ${index + 1}/${totalSizes} to API:`, {
                    variant_id: size.variantId,
                    quantity: size.quantity,
                    unit_price: size.unitPrice,
                    sale_price: size.salePrice,
                    location_code: size.storageLocation
                });
                
                $.ajax({
                    url: 'api_them_san_pham_vao_phieu.php',
                    method: 'POST',
                    data: {
                        receipt_id: <?php echo $fromReceipt; ?>,
                        variant_id: size.variantId,
                        quantity: size.quantity || 1,
                        unit_price: size.unitPrice || 0,
                        sale_price: size.salePrice || 0,
                        location_code: size.storageLocation || null
                    },
                    success: function(response) {
                        console.log(`✅ Đã thêm size ${index + 1}/${totalSizes}:`, response);
                        addedCount++;
                        checkComplete();
                    },
                    error: function(xhr, status, error) {
                        console.error(`❌ Lỗi khi thêm size ${index + 1}:`, error);
                        errorCount++;
                        checkComplete();
                    }
                });
            });
            
            function checkComplete() {
                if (addedCount + errorCount === totalSizes) {
                    Swal.close();
                    
                    if (errorCount > 0) {
                        Swal.fire({
                            icon: 'warning',
                            title: 'Hoàn thành có lỗi',
                            html: `
                                <p>Đã thêm <strong>${addedCount}/${totalSizes}</strong> size vào phiếu</p>
                                <p class="text-danger">Có <strong>${errorCount}</strong> lỗi</p>
                            `,
                            confirmButtonText: 'Quay lại quản lý phiếu'
                        }).then(() => {
                            window.location.href = 'quan_ly_phieu_nhap_kho.php';
                        });
                    } else {
                        Swal.fire({
                            icon: 'success',
                            title: 'Thành công!',
                            text: `Đã thêm ${addedCount} size vào phiếu nhập`,
                            timer: 2000,
                            showConfirmButton: false
                        }).then(() => {
                            window.location.href = 'quan_ly_phieu_nhap_kho.php';
                        });
                    }
                }
            }
        }
        <?php endif; ?>

        function moveToStep(step) {
            // Hide all steps
            $('.step-content').removeClass('active');
            $('.step').removeClass('active completed');
            
            // Show target step
            $('#step' + step).addClass('active');
            
            // Update step indicator
            for (let i = 1; i <= step; i++) {
                if (i < step) {
                    $('.step[data-step="' + i + '"]').addClass('completed');
                } else if (i === step) {
                    $('.step[data-step="' + i + '"]').addClass('active');
                }
            }
            
            currentStep = step;
            updateStepDisplay();
        }

        function updateStepDisplay() {
            if (currentStep === 2) {
                // Initialize cascading dropdowns when entering product info step
                setupCascadingDropdowns();
            } else if (currentStep === 3) {
                updateReceiptSummary();
            }
        }
        
        function showSizeSelectorModal() {
            if (!availableSizes || availableSizes.length === 0) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Chưa có size nào',
                    text: 'Vui lòng chọn đầy đủ thông tin sản phẩm trước để tải danh sách size!',
                    confirmButtonText: 'Đã hiểu'
                });
                return;
            }
            
            // Tạo danh sách size chưa được thêm
            const remainingSizes = availableSizes.filter(size => 
                !sizesTableData.some(added => added.size === size.size)
            );
            
            if (remainingSizes.length === 0) {
                Swal.fire({
                    icon: 'info',
                    title: 'Đã thêm hết',
                    text: 'Tất cả các size có sẵn đã được thêm vào danh sách!',
                    confirmButtonText: 'Đã hiểu'
                });
                return;
            }
            
            const sizesHtml = remainingSizes.map(size => `
                <div class="col-6 col-md-4 col-lg-3 mb-3">
                    <div class="card size-option h-100" style="cursor: pointer;" onclick="selectSizeFromModal('${size.size}', '${size.sku}')">
                        <div class="card-body p-3 text-center">
                            <div class="size-number font-weight-bold text-primary mb-2" style="font-size: 1.5rem;">
                                ${size.size}
                            </div>
                            <div class="size-details">
                                <small class="text-muted d-block mb-1">
                                    <i class="fas fa-barcode"></i> ${size.sku}
                                </small>
                                <small class="text-primary d-block mb-1">
                                    <i class="fas fa-shopping-cart"></i> Nhập: <strong>${size.unit_price > 0 ? new Intl.NumberFormat('vi-VN').format(size.unit_price) + ' ₫' : 'Chưa có'}</strong>
                                </small>
                                <small class="text-success d-block">
                                    <i class="fas fa-tag"></i> Bán: <strong>${size.sale_price > 0 ? new Intl.NumberFormat('vi-VN').format(size.sale_price) + ' ₫' : 'Chưa có'}</strong>
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            `).join('');
            
            Swal.fire({
                title: '<i class="fas fa-layer-group text-primary mr-2"></i>Chọn Size Cần Thêm',
                html: `
                    <div class="container-fluid">
                        <div class="alert alert-info mb-3">
                            <i class="fas fa-info-circle mr-2"></i>
                            <strong>Còn ${remainingSizes.length} size chưa thêm.</strong> Click vào size để thêm vào danh sách.
                        </div>
                        <div class="row">
                            ${sizesHtml}
                        </div>
                    </div>
                `,
                width: '80%',
                showConfirmButton: false,
                showCancelButton: true,
                cancelButtonText: '<i class="fas fa-times mr-2"></i>Đóng',
                customClass: {
                    content: 'text-left',
                    cancelButton: 'btn-secondary'
                }
            });
        }
        
        function selectSizeFromModal(size, sku) {
            addSizeFromAvailable(size, sku);
            Swal.close();
            
            // Show success message
            Swal.fire({
                icon: 'success',
                title: 'Thành công!',
                text: `Đã thêm size ${size} vào danh sách`,
                timer: 1500,
                showConfirmButton: false
            });
        }

        function validateStep1() {
            const supplierId = $('#supplierSelect').val();
            const receiptDate = $('#receiptDate').val();
            
            if (!supplierId) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Thông tin chưa đầy đủ',
                    text: 'Vui lòng chọn nhà cung cấp!'
                });
                return false;
            }
            
            if (!receiptDate) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Thông tin chưa đầy đủ',
                    text: 'Vui lòng chọn ngày nhập!'
                });
                return false;
            }
            
            return true;
        }

        function validateProductForm() {
            const requiredFields = ['productType', 'productBrand', 'productColor', 'productName'];
            
            for (let field of requiredFields) {
                const value = $('#' + field).val();
                if (!value || value.trim() === '') {
                    const label = $('label[for="' + field + '"]').text().replace(' *', '');
                    Swal.fire({
                        icon: 'warning',
                        title: 'Thông tin chưa đầy đủ',
                        text: 'Vui lòng chọn ' + label + '!'
                    });
                    $('#' + field).focus();
                    return false;
                }
            }
            
            return true;
        }



        function addProductToReceipt() {
            if (sizesTableData.length === 0) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Chưa có kích thước nào',
                    text: 'Vui lòng thêm ít nhất một kích thước vào danh sách!'
                });
                return;
            }

            // Validate all sizes have required data
            for (let i = 0; i < sizesTableData.length; i++) {
                const sizeData = sizesTableData[i];
                if (!sizeData.quantity || sizeData.quantity <= 0) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Thiếu số lượng',
                        text: `Vui lòng nhập số lượng cho kích thước ${sizeData.size}!`
                    });
                    return;
                }
                if (!sizeData.unitPrice || sizeData.unitPrice <= 0) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Thiếu giá nhập',
                        text: `Vui lòng nhập giá nhập cho kích thước ${sizeData.size}!`
                    });
                    return;
                }
                if (!sizeData.storageLocation || sizeData.storageLocation.trim() === '') {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Thiếu vị trí lưu trữ',
                        html: `
                            <div class="text-center">
                                <p>Vui lòng nhập vị trí lưu trữ cho kích thước <strong>${sizeData.size}</strong>!</p>
                                <div class="alert alert-info mt-3">
                                    <i class="fas fa-info-circle mr-2"></i>
                                    <strong>Gợi ý:</strong> Nhấn nút <span class="badge badge-info"><i class="fas fa-warehouse"></i> Gợi ý</span> 
                                    để hệ thống đề xuất vị trí phù hợp, hoặc nhập thủ công vào ô "Vị trí lưu trữ".
                                </div>
                                <small class="text-muted">Ví dụ: A1-01, B2-15, KHO-A-001...</small>
                            </div>
                        `,
                        confirmButtonText: 'Đã hiểu',
                        width: '500px'
                    });
                    return;
                }
            }

            // Add each size as a separate item
            sizesTableData.forEach(sizeData => {
                const product = {
                    id: Date.now() + Math.random(), // Unique ID for each size
                    type: $('#productType').val(),
                    brand: $('#productBrand').val(),
                    color: $('#productColor').val(),
                    name: $('#productName').val(),
                    sku: sizeData.sku,
                    size: sizeData.size,
                    quantity: sizeData.quantity,
                    unitPrice: sizeData.unitPrice,
                    salePrice: sizeData.salePrice || 0, // Thêm giá bán
                    storageLocation: sizeData.storageLocation,
                    notes: $('#itemNotes').val(),
                    totalPrice: sizeData.quantity * sizeData.unitPrice
                };
                
                receiptItems.push(product);
            });
            
            // Clear the sizes table for next product
            sizesTableData = [];
            updateSizesTable();
            
            Swal.fire({
                icon: 'success',
                title: 'Thành công!',
                text: `Đã thêm sản phẩm với ${sizesTableData.length || $('#productName').val()} kích thước vào phiếu nhập`,
                timer: 1500,
                showConfirmButton: false
            });
            
            moveToStep(3);
        }

        function updateReceiptSummary() {
            // Update supplier info
            $('#supplierInfo').text(currentSupplier ? currentSupplier.name : 'Chưa chọn nhà cung cấp');
            $('#currentDate').text($('#receiptDate').val());
            $('#receiptNotesDisplay').text($('#receiptNotes').val() || 'Không có');
            
            // Update items list
            let itemsHtml = '';
            let totalAmount = 0;
            
            if (receiptItems.length === 0) {
                itemsHtml = '<p class="text-muted text-center">Chưa có sản phẩm nào trong phiếu nhập</p>';
            } else {
                itemsHtml = '<div class="table-responsive"><table class="table table-bordered"><thead class="thead-light"><tr><th>STT</th><th>Sản phẩm</th><th>SKU</th><th>Màu sắc</th><th>Kích thước</th><th>SL</th><th>Đơn giá</th><th>Giá bán</th><th>Thành tiền</th><th>Hành động</th></tr></thead><tbody>';
                
                receiptItems.forEach((item, index) => {
                    totalAmount += item.totalPrice;
                    itemsHtml += `
                        <tr>
                            <td>${index + 1}</td>
                            <td><strong>${item.name}</strong><br><small class="text-muted">${item.brand} - ${item.type}</small></td>
                            <td><code>${item.sku}</code></td>
                            <td>${item.color}</td>
                            <td>${item.size}</td>
                            <td>${item.quantity}</td>
                            <td>${formatCurrency(item.unitPrice)}</td>
                            <td>${item.salePrice > 0 ? formatCurrency(item.salePrice) : '<span class="text-muted">Chưa nhập</span>'}</td>
                            <td><strong>${formatCurrency(item.totalPrice)}</strong></td>
                            <td>
                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeReceiptItem(${item.id})">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                    `;
                });
                
                itemsHtml += '</tbody></table></div>';
            }
            
            $('#receiptItemsList').html(itemsHtml);
            $('#totalAmount').text(formatCurrency(totalAmount));
            
            // Update the "Continue to Step 3" button visibility
            updateContinueButton();
        }

        function updateContinueButton() {
            const $continueBtn = $('#continueToStep3');
            const $itemsCount = $('#receiptItemsCount');
            
            if (receiptItems.length > 0) {
                $continueBtn.show();
                $itemsCount.text(receiptItems.length);
            } else {
                $continueBtn.hide();
            }
        }

        function removeReceiptItem(itemId) {
            receiptItems = receiptItems.filter(item => item.id !== itemId);
            updateReceiptSummary();
        }

        function clearProductForm() {
            $('#productDetailsForm')[0].reset();
            $('#productSku').val('');
            
            // Reset all cascading dropdowns
            resetDropdown('productBrand', 'Chọn loại sản phẩm trước để xem thương hiệu');
            resetDropdown('productColor', 'Chọn thương hiệu trước để xem màu sắc');
            resetDropdown('productName', 'Chọn màu sắc trước để xem tên sản phẩm');
            resetDropdown('productSize', 'Chọn tên sản phẩm trước để xem kích thước có sẵn');
            $('#storageLocation').empty().append('<option value="">-- Chọn vị trí lưu trữ --</option>');
            
            // Reset multiple sizes mode
            if (multipleSizesMode) {
                toggleMultipleSizesMode();
            }
            sizesTableData = [];
            
            // Disable multiple sizes functionality
            $('#enableMultipleSizes, #addSizeBtn, #addAllSizesBtn').prop('disabled', true);
            
            // Load initial product types again
            loadDropdownData('type');
        }



        function saveDraftReceipt() {
            if (receiptItems.length === 0) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Không có dữ liệu',
                    text: 'Vui lòng thêm ít nhất một sản phẩm vào phiếu nhập!'
                });
                return;
            }

            // NGĂN CHẶN DOUBLE-CLICK: Disable button và thêm loading
            const $draftBtn = $('#saveDraftBtn');
            if ($draftBtn.prop('disabled')) {
                console.log('Button already clicked, preventing duplicate');
                return; // Đã click rồi, không cho click lần 2
            }
            
            $draftBtn.prop('disabled', true);
            $draftBtn.html('<i class="fas fa-spinner fa-spin mr-1"></i>Đang lưu...');

            const receiptData = {
                supplier_id: currentSupplier.id,
                receipt_date: $('#receiptDate').val(),
                notes: $('#receiptNotes').val(),
                items: receiptItems,
                status: 'draft',
                manual_entry: true
            };

            $.ajax({
                url: 'api_luu_phieu_nhap_thu_cong.php',
                method: 'POST',
                data: JSON.stringify(receiptData),
                contentType: 'application/json',
                success: function(response) {
                    if (response.success) {
                        console.log('Draft saved successfully:', response);
                        if (response.duplicate_prevented) {
                            console.warn('Duplicate prevented by backend!');
                        }
                        Swal.fire({
                            icon: 'success',
                            title: 'Thành công!',
                            text: 'Đã lưu bản nháp phiếu nhập thành công',
                            confirmButtonText: 'OK'
                        }).then(() => {
                            // Add timestamp to force refresh and avoid cache
                            window.location.href = 'quan_ly_phieu_nhap_kho.php?t=' + new Date().getTime();
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Lỗi!',
                            text: response.message || 'Có lỗi xảy ra khi lưu bản nháp'
                        });
                        // Re-enable button on error
                        $draftBtn.prop('disabled', false);
                        $draftBtn.html('<i class="fas fa-save mr-1"></i>Lưu bản nháp');
                    }
                },
                error: function() {
                    Swal.fire({
                        icon: 'error',
                        title: 'Lỗi!',
                        text: 'Có lỗi xảy ra khi kết nối với server'
                    });
                    // Re-enable button on error
                    $draftBtn.prop('disabled', false);
                    $draftBtn.html('<i class="fas fa-save mr-1"></i>Lưu bản nháp');
                }
            });
        }

        function completeStockReceipt() {
            if (receiptItems.length === 0) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Không có dữ liệu',
                    text: 'Vui lòng thêm ít nhất một sản phẩm vào phiếu nhập!'
                });
                return;
            }

            Swal.fire({
                title: 'Xác nhận hoàn thành',
                text: 'Bạn có chắc chắn muốn hoàn thành phiếu nhập này không?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#28a745',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Hoàn thành',
                cancelButtonText: 'Hủy'
            }).then((result) => {
                if (result.isConfirmed) {
                    // NGĂN CHẶN DOUBLE-CLICK: Disable button và thêm loading
                    const $completeBtn = $('#completeReceipt');
                    if ($completeBtn.prop('disabled')) {
                        console.log('Button already clicked, preventing duplicate');
                        return; // Đã click rồi, không cho click lần 2
                    }
                    
                    $completeBtn.prop('disabled', true);
                    $completeBtn.html('<i class="fas fa-spinner fa-spin mr-1"></i>Đang xử lý...');
                    
                    const receiptData = {
                        supplier_id: currentSupplier.id,
                        receipt_date: $('#receiptDate').val(),
                        notes: $('#receiptNotes').val(),
                        items: receiptItems,
                        status: 'completed',
                        manual_entry: true
                    };

                    $.ajax({
                        url: 'api_luu_phieu_nhap_thu_cong.php',
                        method: 'POST',
                        data: JSON.stringify(receiptData),
                        contentType: 'application/json',
                        success: function(response) {
                            if (response.success) {
                                console.log('Receipt completed successfully:', response);
                                if (response.duplicate_prevented) {
                                    console.warn('Duplicate prevented by backend!');
                                }
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Thành công!',
                                    text: 'Phiếu nhập đã được tạo thành công!',
                                    confirmButtonText: 'OK'
                                }).then(() => {
                                    // Add timestamp to force refresh and avoid cache
                                    window.location.href = 'quan_ly_phieu_nhap_kho.php?t=' + new Date().getTime();
                                });
                            } else {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Lỗi!',
                                    text: response.message || 'Có lỗi xảy ra khi tạo phiếu nhập'
                                });
                                // Re-enable button on error
                                $completeBtn.prop('disabled', false);
                                $completeBtn.html('<i class="fas fa-check mr-1"></i>Hoàn thành phiếu nhập');
                            }
                        },
                        error: function() {
                            Swal.fire({
                                icon: 'error',
                                title: 'Lỗi!',
                                text: 'Có lỗi xảy ra khi kết nối với server'
                            });
                            // Re-enable button on error
                            $completeBtn.prop('disabled', false);
                            $completeBtn.html('<i class="fas fa-check mr-1"></i>Hoàn thành phiếu nhập');
                        }
                    });
                }
            });
        }

        function saveNewSupplier() {
            const name = $('#supplierName').val().trim();
            const contactName = $('#contactName').val().trim();
            const phone = $('#supplierPhone').val().trim();
            const email = $('#supplierEmail').val().trim();
            const address = $('#supplierAddress').val().trim();

            if (!name) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Thông tin chưa đầy đủ',
                    text: 'Vui lòng nhập tên nhà cung cấp!'
                });
                return;
            }

            const supplierData = {
                name: name,
                contact_name: contactName,
                phone: phone,
                email: email,
                address: address,
                warehouse_id: <?php echo $userWarehouseId; ?>
            };

            $.ajax({
                url: 'api_them_nha_cung_cap.php',
                method: 'POST',
                data: supplierData,
                success: function(response) {
                    if (response.success) {
                        // Add new supplier to select dropdown
                        const option = `<option value="${response.supplier_id}" selected>
                            ${name}${contactName ? ' - ' + contactName : ''}
                        </option>`;
                        $('#supplierSelect').append(option);
                        
                        // Set current supplier
                        currentSupplier = {
                            id: response.supplier_id,
                            name: name + (contactName ? ' - ' + contactName : '')
                        };
                        
                        // Enable next button
                        $('#nextToEdit').prop('disabled', false);
                        
                        // Close modal and reset form
                        $('#addSupplierModal').modal('hide');
                        $('#addSupplierForm')[0].reset();
                        
                        Swal.fire({
                            icon: 'success',
                            title: 'Thành công!',
                            text: 'Nhà cung cấp mới đã được thêm thành công',
                            timer: 1500,
                            showConfirmButton: false
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Lỗi!',
                            text: response.message || 'Có lỗi xảy ra khi thêm nhà cung cấp'
                        });
                    }
                },
                error: function() {
                    Swal.fire({
                        icon: 'error',
                        title: 'Lỗi!',
                        text: 'Có lỗi xảy ra khi kết nối với server'
                    });
                }
            });
        }

        function formatCurrency(amount) {
            return new Intl.NumberFormat('vi-VN', {
                style: 'currency',
                currency: 'VND'
            }).format(amount);
        }

        // Function to setup price formatting for input fields
        function setupPriceFormatting() {
            // Format price on blur only (avoid cursor jumping during typing)
            $(document).on('blur', '.price-format-input', function() {
                let input = $(this);
                let value = input.val().replace(/[^\d]/g, ''); // Remove non-digits
                
                if (value === '' || value === '0') {
                    input.val('');
                    input.data('raw-value', '0');
                } else {
                    let formatted = new Intl.NumberFormat('vi-VN').format(parseInt(value));
                    input.val(formatted);
                    input.data('raw-value', value);
                }
            });
            
            // On focus, show unformatted value for easy editing
            $(document).on('focus', '.price-format-input', function() {
                let input = $(this);
                let rawValue = input.data('raw-value');
                if (rawValue && rawValue !== '0') {
                    input.val(rawValue);
                }
                // Select all after a short delay
                setTimeout(function() {
                    input.select();
                }, 50);
            });
            
            // Allow only digits during typing
            $(document).on('keypress', '.price-format-input', function(e) {
                // Allow: backspace, delete, tab, escape, enter
                if ($.inArray(e.keyCode, [46, 8, 9, 27, 13]) !== -1 ||
                    // Allow: Ctrl+A, Ctrl+C, Ctrl+V, Ctrl+X
                    (e.keyCode === 65 && e.ctrlKey === true) ||
                    (e.keyCode === 67 && e.ctrlKey === true) ||
                    (e.keyCode === 86 && e.ctrlKey === true) ||
                    (e.keyCode === 88 && e.ctrlKey === true)) {
                    return;
                }
                // Ensure that it is a number and stop the keypress
                if ((e.shiftKey || (e.keyCode < 48 || e.keyCode > 57)) && (e.keyCode < 96 || e.keyCode > 105)) {
                    e.preventDefault();
                }
            });
            
            // Update raw value on input
            $(document).on('input', '.price-format-input', function() {
                let input = $(this);
                let value = input.val().replace(/[^\d]/g, '');
                input.data('raw-value', value || '0');
            });
        }
        
        // ============================================
        // XỬ LÝ CHẾ ĐỘ THÊM SẢN PHẨM VÀO PHIẾU HIỆN CÓ
        // ============================================
        <?php if ($fromReceipt && $addMode === 'add'): ?>
        const FROM_RECEIPT_ID = <?php echo $fromReceipt; ?>;
        const ADD_TO_EXISTING_MODE = true;
        
        console.log('📝 Chế độ thêm sản phẩm thủ công vào phiếu #' + FROM_RECEIPT_ID);
        <?php endif; ?>
        
    </script>
</body>
</html>