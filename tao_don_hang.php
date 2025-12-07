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

// Lấy thông tin user và warehouse
$userWarehouseId = $_SESSION['warehouse_id'] ?? null;
$warehouseName = 'Smart Warehouse';
if ($userWarehouseId) {
    $warehouseObj = new Warehouse($pdo);
    if ($warehouseObj->getById($userWarehouseId)) {
        $warehouseName = $warehouseObj->name;
    }
}

// Debug (commented out for production)
// error_log("DEBUG tao_don_hang.php - User ID: " . ($_SESSION['user_id'] ?? 'null') . ", Warehouse ID: " . ($userWarehouseId ?? 'null'));

// Lấy danh sách types cho dropdown sản phẩm (chỉ types có products trong warehouse hiện tại)
if ($userWarehouseId) {
    $typesQuery = "
        SELECT DISTINCT p.type 
        FROM products p
        INNER JOIN product_variants pv ON p.product_id = pv.product_id
        INNER JOIN inventory i ON pv.variant_id = i.variant_id
        WHERE i.warehouse_id = ? AND p.type IS NOT NULL AND p.type != ''
        ORDER BY p.type
    ";
    $typesStmt = $pdo->prepare($typesQuery);
    $typesStmt->execute([$userWarehouseId]);
} else {
    // Fallback nếu không có warehouse_id
    $typesQuery = "SELECT DISTINCT type FROM products WHERE type IS NOT NULL AND type != '' ORDER BY type";
    $typesStmt = $pdo->prepare($typesQuery);
    $typesStmt->execute();
}
$productTypes = $typesStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="description" content="">
    <meta name="author" content="">
    
    <title>Tạo đơn hàng - <?php echo htmlspecialchars($warehouseName); ?></title>

    <!-- Custom fonts for this template-->
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">
    
    <!-- Custom styles for this template-->
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    
    <!-- Select2 CSS for better dropdowns -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    
    <!-- SweetAlert2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.28/dist/sweetalert2.min.css" rel="stylesheet">

    <style>
        :root {
            --primary-blue: #2563eb;
            --dark-blue: #1e40af;
            --light-blue: #3b82f6;
            --dark-bg: #1f2937;
            --darker-bg: #111827;
            --beige: #f5f5dc;
            --light-beige: #faf9f6;
            --white: #ffffff;
            --gray-light: #f3f4f6;
            --gray-border: #e5e7eb;
            --text-dark: #111827;
            --text-gray: #6b7280;
        }

        body {
            background-color: var(--light-beige);
        }

        .step-wizard {
            margin-bottom: 30px;
            background: linear-gradient(135deg, var(--primary-blue) 0%, var(--dark-blue) 100%);
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .step-wizard ul {
            list-style: none;
            padding: 0;
            margin: 0;
            display: flex;
            justify-content: space-between;
        }
        .step-wizard li {
            flex: 1;
            text-align: center;
            position: relative;
        }
        .step-wizard li:not(:last-child):after {
            content: '';
            position: absolute;
            top: 15px;
            right: -50%;
            width: 100%;
            height: 3px;
            background: rgba(255, 255, 255, 0.3);
            z-index: 1;
        }
        .step-wizard li.active:not(:last-child):after,
        .step-wizard li.completed:not(:last-child):after {
            background: var(--white);
        }
        .step-wizard .step-number {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            color: var(--white);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            position: relative;
            z-index: 2;
            margin-bottom: 8px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            transition: all 0.3s ease;
        }
        .step-wizard li.active .step-number {
            background: var(--white);
            color: var(--primary-blue);
            border-color: var(--white);
            box-shadow: 0 0 0 4px rgba(255, 255, 255, 0.3);
        }
        .step-wizard li.completed .step-number {
            background: var(--white);
            color: #10b981;
            border-color: var(--white);
        }
        .step-wizard .step-title {
            color: var(--white);
            font-size: 0.9em;
            font-weight: 500;
        }
        .step-content {
            display: none;
        }
        .step-content.active {
            display: block;
        }

        /* Two Column Layout for Step 2 */
        .product-cart-layout {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        @media (max-width: 992px) {
            .product-cart-layout {
                grid-template-columns: 1fr;
            }
        }

        .product-section {
            background: var(--white);
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            overflow: visible;
        }

        .product-section .card-header {
            background: linear-gradient(135deg, var(--dark-bg) 0%, var(--darker-bg) 100%);
            color: #fbbf24;
            padding: 20px;
            border: none;
        }

        .cart-section {
            background: var(--white);
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            position: sticky;
            top: 20px;
            max-height: calc(100vh - 40px);
            display: flex;
            flex-direction: column;
        }

        .cart-section .card-header {
            background: linear-gradient(135deg, var(--dark-bg) 0%, var(--darker-bg) 100%);
            color: #fef3c7;
            padding: 20px;
            border: none;
            flex-shrink: 0;
        }

        .cart-section .card-body {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
        }

        .card {
            border: none;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            border-radius: 12px;
        }

        .card-header {
            background: linear-gradient(135deg, var(--dark-bg) 0%, var(--darker-bg) 100%);
            color: #fef3c7;
            border-radius: 12px 12px 0 0 !important;
            padding: 20px;
        }

        .card-header h6 {
            margin: 0;
            font-weight: 600;
            letter-spacing: 0.5px;
            color: #fef3c7;
        }

        .form-control, .form-control:focus {
            box-sizing: border-box;
            min-height: 44px;
            line-height: 1.3;
            border-radius: 8px;
            border: 2px solid var(--gray-border);
            padding: 10px 15px;
            transition: all 0.3s ease;
            overflow: visible;
        }

        .form-control:focus {
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .form-group label {
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 8px;
        }
        
        .form-group label i {
            color: var(--primary-blue);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary-blue) 0%, var(--dark-blue) 100%);
            border: none;
            border-radius: 8px;
            padding: 10px 24px;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 2px 4px rgba(37, 99, 235, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(37, 99, 235, 0.4);
        }

        .btn-success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            border: none;
            border-radius: 8px;
            padding: 10px 24px;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 2px 4px rgba(16, 185, 129, 0.3);
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(16, 185, 129, 0.4);
        }

        .btn-secondary {
            background: var(--gray-light);
            color: var(--text-dark);
            border: none;
            border-radius: 8px;
            padding: 10px 24px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-secondary:hover {
            background: var(--gray-border);
            color: var(--text-dark);
        }

        .btn-danger {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            border: none;
            border-radius: 6px;
            transition: all 0.3s ease;
        }

        .btn-danger:hover {
            transform: scale(1.05);
            box-shadow: 0 2px 6px rgba(239, 68, 68, 0.4);
        }

        #addProductBtn {
            width: 100%;
            padding: 12px;
            font-size: 1.05em;
            margin-top: 10px;
        }

        .customer-search {
            position: relative;
        }
        .customer-suggestions {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: var(--white);
            border: 2px solid var(--primary-blue);
            border-top: none;
            border-radius: 0 0 8px 8px;
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .customer-suggestion {
            padding: 12px 15px;
            cursor: pointer;
            border-bottom: 1px solid var(--gray-border);
            transition: all 0.2s ease;
        }
        .customer-suggestion:hover {
            background-color: var(--gray-light);
        }
        .customer-suggestion:last-child {
            border-bottom: none;
        }

        .required {
            color: #ef4444;
        }

        .btn-step {
            min-width: 120px;
        }

        .order-summary {
            background: var(--gray-light);
            padding: 25px;
            border-radius: 12px;
            margin-top: 20px;
            border: 2px solid var(--gray-border);
        }
        
        .order-summary h5 {
            color: var(--text-dark);
            font-weight: 700;
        }
        
        .order-summary h5 i {
            color: var(--primary-blue);
        }
        
        .order-summary h6 {
            color: var(--text-dark);
            font-weight: 600;
        }
        
        .order-summary h6 i {
            color: #10b981;
        }

        .alert {
            margin-bottom: 20px;
            border-radius: 8px;
            border: none;
        }

        #cartTable {
            margin-top: 0;
            font-size: 0.9em;
        }
        #cartTable thead th {
            background-color: var(--dark-bg);
            color: #fef3c7;
            font-weight: 600;
            padding: 12px 8px;
            border: none;
            font-size: 0.85em;
        }
        #cartTable tbody td {
            vertical-align: middle;
            padding: 10px 8px;
            border-color: var(--gray-border);
        }
        #cartTable tbody tr:hover {
            background-color: var(--gray-light);
        }
        
        #cart_count {
            background: #fbbf24;
            color: var(--darker-bg);
            padding: 4px 12px;
            border-radius: 20px;
            font-weight: bold;
            font-size: 0.95em;
            box-shadow: 0 2px 4px rgba(251, 191, 36, 0.4);
        }

        .cart-header-title {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .cart-header-title h6 {
            color: #fef3c7 !important;
        }
        
        .cart-header-title i {
            color: #fef3c7;
        }

        .cart-empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-gray);
        }

        .cart-empty-state i {
            font-size: 3em;
            margin-bottom: 15px;
            opacity: 0.5;
        }

        .table-responsive {
            max-height: none;
            overflow-y: auto;
        }

        .cart-summary {
            background: linear-gradient(135deg, #4b5563 0%, #6b7280 100%);
            padding: 18px;
            border-radius: 10px;
            margin-top: 15px;
            border: 2px solid #fbbf24;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }
        
        .cart-summary label {
            color: #fef3c7 !important;
            font-weight: 600;
        }
        
        .cart-summary label i {
            color: #fbbf24 !important;
        }

        .cart-summary-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            font-size: 0.95em;
            color: #fef3c7;
        }

        .cart-summary-row.total {
            border-top: 2px solid #fbbf24;
            margin-top: 10px;
            padding-top: 15px;
            font-weight: bold;
            font-size: 1.3em;
            color: #fbbf24;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .select2-container--default .select2-selection--single {
            border: 2px solid var(--gray-border);
            border-radius: 8px;
            height: 42px;
            padding: 5px;
        }

        .select2-container--default .select2-selection--single:focus {
            border-color: var(--primary-blue);
        }

        .discount-badge {
            background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
            color: var(--darker-bg);
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 0.9em;
            font-weight: 700;
            box-shadow: 0 2px 4px rgba(251, 191, 36, 0.3);
        }
        
        .badge-primary {
            background: linear-gradient(135deg, var(--primary-blue) 0%, var(--dark-blue) 100%);
            color: #fef3c7;
            padding: 6px 12px;
            font-weight: 600;
        }
        
        .badge-info {
            background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%);
            color: #ffffff;
            padding: 6px 12px;
            font-weight: 600;
        }
        
        .thead-dark th {
            background: linear-gradient(135deg, var(--dark-bg) 0%, var(--darker-bg) 100%);
            color: #fef3c7 !important;
        }

        .product-info-badge {
            background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%);
            padding: 12px 15px;
            border-radius: 8px;
            margin-top: 10px;
            border-left: 4px solid #fbbf24;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .product-info-badge small {
            display: block;
            color: #fef3c7;
            font-size: 0.8em;
            margin-bottom: 4px;
            font-weight: 500;
        }

        .product-info-badge strong {
            color: #fbbf24;
            font-size: 1.1em;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
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
                        <h1 class="h3 mb-0 text-gray-800">Tạo đơn hàng mới</h1>
                    </div>

                    <!-- Alert Messages -->
                    <div id="alertMessages"></div>

                    <!-- Step Wizard -->
                    <div class="step-wizard">
                        <ul>
                            <li class="active" data-step="1">
                                <div class="step-number">1</div>
                                <div class="step-title">Thông tin khách hàng</div>
                            </li>
                            <li data-step="2">
                                <div class="step-number">2</div>
                                <div class="step-title">Thông tin sản phẩm</div>
                            </li>
                            <li data-step="3">
                                <div class="step-number">3</div>
                                <div class="step-title">Xác nhận đơn hàng</div>
                            </li>
                        </ul>
                    </div>

                    <!-- Order Form -->
                    <form id="orderForm">
                        <!-- Step 1: Customer Information -->
                        <div class="step-content active" id="step-1">
                            <div class="card shadow mb-4">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold">
                                        <i class="fas fa-user-circle mr-2"></i>Thông tin khách hàng
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="customer_name">
                                                    <i class="fas fa-user mr-1"></i>Họ tên khách hàng <span class="required">*</span>
                                                </label>
                                                <input type="text" class="form-control" id="customer_name" name="customer_name" required placeholder="Nhập họ tên khách hàng">
                                            </div>

                                            <div class="form-group">
                                                <label for="customer_phone">
                                                    <i class="fas fa-phone mr-1"></i>Số điện thoại <span class="required">*</span>
                                                </label>
                                                <input type="tel" class="form-control" id="customer_phone" name="customer_phone" required pattern="[0-9]{10,11}" placeholder="0XXXXXXXXX">
                                                <small class="form-text text-muted">Nhập số điện thoại 10-11 chữ số</small>
                                            </div>

                                            <div class="form-group">
                                                <label for="customer_email">
                                                    <i class="fas fa-envelope mr-1"></i>Email
                                                </label>
                                                <input type="email" class="form-control" id="customer_email" name="customer_email" placeholder="email@example.com">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="customer_address">
                                                    <i class="fas fa-map-marker-alt mr-1"></i>Địa chỉ giao hàng <span class="required">*</span>
                                                </label>
                                                <textarea class="form-control" id="customer_address" name="customer_address" rows="3" required placeholder="Số nhà, đường, phường/xã, quận/huyện, tỉnh/thành phố"></textarea>
                                            </div>

                                            <div class="form-group">
                                                <label for="customer_note">
                                                    <i class="fas fa-sticky-note mr-1"></i>Ghi chú
                                                </label>
                                                <textarea class="form-control" id="customer_note" name="customer_note" rows="2" placeholder="Ví dụ: Giao ngoài giờ hành chính, Khách thân thiết..."></textarea>
                                            </div>

                                            <div class="form-group customer-search">
                                                <label for="existing_customer">
                                                    <i class="fas fa-search mr-1"></i>Hoặc chọn khách hàng có sẵn
                                                </label>
                                                <input type="text" class="form-control" id="existing_customer" placeholder="Nhập tên hoặc số điện thoại để tìm kiếm...">
                                                <div class="customer-suggestions" id="customerSuggestions"></div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row mt-3">
                                        <div class="col text-center">
                                            <button type="button" class="btn btn-primary btn-step" id="nextStep1">
                                                Tiếp tục<i class="fas fa-arrow-right ml-2"></i>
                                            </button>
                                            <button type="button" class="btn btn-secondary btn-step ml-2" onclick="cancelOrder()">
                                                <i class="fas fa-times mr-2"></i>Hủy
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Step 2: Product Information -->
                        <div class="step-content" id="step-2">
                            <div class="product-cart-layout">
                                <!-- Left Column: Product Selection -->
                                <div class="product-section">
                                    <div class="card-header">
                                        <h6 class="m-0 font-weight-bold">
                                            <i class="fas fa-box-open mr-2"></i>Chọn sản phẩm
                                        </h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="form-group">
                                            <label for="order_id">Mã đơn hàng</label>
                                            <input type="text" class="form-control" id="order_id" name="order_id" readonly>
                                        </div>

                                        <div class="form-group">
                                            <label for="product_type">Phân loại sản phẩm</label>
                                            <select class="form-control" id="product_type" name="product_type">
                                                <option value="">-- Chọn phân loại --</option>
                                                <?php foreach ($productTypes as $type): ?>
                                                    <option value="<?php echo htmlspecialchars($type['type']); ?>"><?php echo htmlspecialchars($type['type']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="form-group">
                                            <label for="product_name">Tên sản phẩm</label>
                                            <select class="form-control" id="product_name" name="product_name" disabled>
                                                <option value="">-- Vui lòng chọn phân loại trước --</option>
                                            </select>
                                        </div>

                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label for="product_size">Size</label>
                                                    <select class="form-control" id="product_size" name="product_size" disabled>
                                                        <option value="">-- Chọn size --</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label for="product_color">Màu sắc</label>
                                                    <input type="text" class="form-control" id="product_color" name="product_color" readonly>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label for="product_sku">Mã sản phẩm (SKU)</label>
                                                    <input type="text" class="form-control" id="product_sku" name="product_sku" readonly>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label for="product_quantity">Số lượng</label>
                                                    <input type="number" class="form-control" id="product_quantity" name="product_quantity" min="1" value="1">
                                                </div>
                                            </div>
                                        </div>

                                        <div class="product-info-badge">
                                            <small>Đơn giá bán</small>
                                            <strong id="product_price_display">0 VNĐ</strong>
                                            <input type="hidden" id="product_price" name="product_price">
                                        </div>

                                        <div class="product-info-badge">
                                            <small>Tồn kho khả dụng</small>
                                            <strong><span id="available_stock">0</span> sản phẩm</strong>
                                        </div>

                                        <button type="button" class="btn btn-success" id="addProductBtn">
                                            <i class="fas fa-cart-plus mr-2"></i>Thêm vào giỏ hàng
                                        </button>
                                    </div>
                                </div>

                                <!-- Right Column: Shopping Cart -->
                                <div class="cart-section">
                                    <div class="card-header">
                                        <div class="cart-header-title">
                                            <h6 class="m-0 font-weight-bold">
                                                <i class="fas fa-shopping-cart mr-2"></i>Giỏ hàng
                                            </h6>
                                            <span id="cart_count">0</span>
                                        </div>
                                    </div>
                                    <div class="card-body">
                                        <div class="table-responsive">
                                            <table class="table table-sm" id="cartTable">
                                                <thead>
                                                    <tr>
                                                        <th style="width: 5%;">STT</th>
                                                        <th style="width: 25%;">Sản phẩm</th>
                                                        <th style="width: 15%;">Size/Màu</th>
                                                        <th style="width: 10%;">SL</th>
                                                        <th style="width: 20%;">Đơn giá</th>
                                                        <th style="width: 20%;">Thành tiền</th>
                                                        <th style="width: 5%;"></th>
                                                    </tr>
                                                </thead>
                                                <tbody id="cartBody">
                                                    <tr id="emptyCartRow">
                                                        <td colspan="7">
                                                            <div class="cart-empty-state">
                                                                <i class="fas fa-shopping-basket"></i>
                                                                <p>Chưa có sản phẩm nào trong giỏ hàng</p>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>

                                        <div class="cart-summary">
                                            <div class="form-group mb-3">
                                                <label for="order_discount">
                                                    <i class="fas fa-percent mr-1"></i>Chiết khấu toàn đơn (%)
                                                </label>
                                                <input type="number" class="form-control" id="order_discount" name="order_discount" min="0" max="100" value="0" step="0.01">
                                            </div>
                                            <div class="cart-summary-row total">
                                                <span>Tổng cộng:</span>
                                                <span id="total_amount_display">0 VNĐ</span>
                                            </div>
                                            <input type="hidden" id="total_amount" name="total_amount">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Navigation Buttons -->
                            <div class="row mt-4">
                                <div class="col text-center">
                                    <button type="button" class="btn btn-secondary btn-step" id="prevStep2">
                                        <i class="fas fa-arrow-left mr-2"></i>Quay lại
                                    </button>
                                    <button type="button" class="btn btn-primary btn-step ml-2" id="nextStep2">
                                        Tiếp tục<i class="fas fa-arrow-right ml-2"></i>
                                    </button>
                                    <button type="button" class="btn btn-secondary btn-step ml-2" onclick="cancelOrder()">
                                        <i class="fas fa-times mr-2"></i>Hủy
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Step 3: Order Confirmation -->
                        <div class="step-content" id="step-3">
                            <div class="card shadow mb-4">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold">
                                        <i class="fas fa-check-circle mr-2"></i>Xác nhận đơn hàng
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="order-summary">
                                        <h5 class="mb-4">
                                            <i class="fas fa-file-invoice mr-2 text-primary"></i>Thông tin đơn hàng
                                        </h5>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="card mb-3" style="border-left: 4px solid var(--primary-blue);">
                                                    <div class="card-body">
                                                        <h6 class="text-primary mb-3">
                                                            <i class="fas fa-user mr-2"></i>Thông tin khách hàng
                                                        </h6>
                                                        <p class="mb-2"><strong>Họ tên:</strong> <span id="confirm_customer_name"></span></p>
                                                        <p class="mb-2"><strong>Số điện thoại:</strong> <span id="confirm_customer_phone"></span></p>
                                                        <p class="mb-2"><strong>Email:</strong> <span id="confirm_customer_email"></span></p>
                                                        <p class="mb-2"><strong>Địa chỉ giao hàng:</strong><br><span id="confirm_customer_address"></span></p>
                                                        <p class="mb-0"><strong>Ghi chú:</strong> <span id="confirm_customer_note"></span></p>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="card mb-3" style="border-left: 4px solid var(--dark-bg);">
                                                    <div class="card-body">
                                                        <h6 class="mb-3" style="color: var(--dark-bg);">
                                                            <i class="fas fa-receipt mr-2"></i>Chi tiết đơn hàng
                                                        </h6>
                                                        <p class="mb-2"><strong>Mã đơn hàng:</strong> <span id="confirm_order_id" class="badge badge-primary"></span></p>
                                                        <p class="mb-2"><strong>Số lượng sản phẩm:</strong> <span id="confirm_product_count" class="badge badge-info"></span></p>
                                                        <p class="mb-2"><strong>Chiết khấu:</strong> <span id="confirm_order_discount" class="discount-badge"></span></p>
                                                        <p class="mb-0">
                                                            <strong>Tổng tiền:</strong><br>
                                                            <span id="confirm_total_amount" class="text-primary font-weight-bold" style="font-size: 1.5em;"></span> VNĐ
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="row mt-3">
                                            <div class="col">
                                                <h6 class="mb-3">
                                                    <i class="fas fa-list mr-2 text-success"></i>Danh sách sản phẩm
                                                </h6>
                                                <div class="table-responsive">
                                                    <table class="table table-bordered table-hover">
                                                        <thead class="thead-dark">
                                                            <tr>
                                                                <th>STT</th>
                                                                <th>Sản phẩm</th>
                                                                <th>SKU</th>
                                                                <th>Size</th>
                                                                <th>Màu</th>
                                                                <th class="text-center">Số lượng</th>
                                                                <th class="text-right">Đơn giá</th>
                                                                <th class="text-right">Thành tiền</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody id="confirmCartBody">
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row mt-4">
                                        <div class="col text-center">
                                            <button type="button" class="btn btn-secondary btn-step" id="prevStep3">
                                                <i class="fas fa-arrow-left mr-2"></i>Quay lại
                                            </button>
                                            <button type="submit" class="btn btn-success btn-step ml-2" id="submitOrder">
                                                <i class="fas fa-save mr-2"></i>Lưu đơn
                                            </button>
                                            <button type="button" class="btn btn-secondary btn-step ml-2" onclick="cancelOrder()">
                                                <i class="fas fa-times mr-2"></i>Hủy
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
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

    <!-- Select2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
    <!-- SweetAlert2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.28/dist/sweetalert2.all.min.js"></script>

    <script>
        // Cart items array
        let cartItems = [];
        
        $(document).ready(function() {
            // Generate order ID
            generateOrderId();
            
            // Initialize Select2
            $('#product_type').select2({
                placeholder: '-- Chọn phân loại --',
                allowClear: true
            });

            // Điều hướng bước
            $('#nextStep1').click(function() {
                if (validateStep1()) {
                    goToStep(2);
                }
            });

            $('#nextStep2').click(function() {
                if (validateStep2()) {
                    showConfirmation();
                    goToStep(3);
                }
            });

            $('#prevStep2').click(function() {
                goToStep(1);
            });

            $('#prevStep3').click(function() {
                goToStep(2);
            });

            // Add product to cart
            $('#addProductBtn').click(function() {
                addToCart();
            });

            // Customer search
            let customerSearchTimeout;
            $('#existing_customer').on('input', function() {
                clearTimeout(customerSearchTimeout);
                const query = $(this).val();
                
                if (query.length >= 2) {
                    customerSearchTimeout = setTimeout(() => {
                        searchCustomers(query);
                    }, 300);
                } else {
                    $('#customerSuggestions').hide();
                }
            });



            // Product type change
            $('#product_type').change(function() {
                const productType = $(this).val();
                if (productType) {
                    loadProductsByType(productType);
                } else {
                    // Clear product selection when no type selected
                    clearProductSelection();
                }
            });

            // Product selection change
            $('#product_name').change(function() {
                const selectedOption = $(this).find('option:selected');
                
                if (selectedOption.val()) {
                    // Get product data from data attributes
                    const productData = {
                        sku: selectedOption.attr('data-product-sku') || '',
                        color: selectedOption.attr('data-product-color') || '',
                        size: selectedOption.attr('data-product-size') || '',
                        price: parseFloat(selectedOption.attr('data-product-price')) || 0,
                        stock: parseInt(selectedOption.attr('data-product-stock')) || 0,
                        product_name: selectedOption.attr('data-product-name') || ''
                    };
                    
                    // console.log('Debug: Selected product data:', productData);
                    selectProduct(productData);
                } else {
                    // Clear product details when no product selected
                    $('#product_size').empty().append('<option value="">-- Chọn size --</option>').prop('disabled', true);
                    $('#product_color').val('');
                    $('#product_sku').val('');
                    $('#product_price').val('');
                    $('#product_price_display').text('0 VNĐ');
                    $('#available_stock').text('0');
                }
            });

            // Discount change
            $('#order_discount').on('input', function() {
                calculateCartTotal();
            });

            // Form submission
            $('#orderForm').submit(function(e) {
                e.preventDefault();
                submitOrder();
            });

            // Hide suggestions when clicking outside
            $(document).click(function(e) {
                if (!$(e.target).closest('.customer-search').length) {
                    $('#customerSuggestions').hide();
                }
            });
        });

        function generateOrderId() {
            const now = new Date();
            const timestamp = now.getFullYear().toString().substr(-2) + 
                            (now.getMonth() + 1).toString().padStart(2, '0') + 
                            now.getDate().toString().padStart(2, '0') + 
                            now.getHours().toString().padStart(2, '0') + 
                            now.getMinutes().toString().padStart(2, '0');
            const orderId = 'DH' + timestamp + Math.floor(Math.random() * 1000).toString().padStart(3, '0');
            $('#order_id').val(orderId);
        }

        function goToStep(step) {
            // Update step wizard
            $('.step-wizard li').removeClass('active completed');
            $('.step-wizard li[data-step="' + step + '"]').addClass('active');
            
            for (let i = 1; i < step; i++) {
                $('.step-wizard li[data-step="' + i + '"]').addClass('completed');
            }

            // Show/hide step content
            $('.step-content').removeClass('active');
            $('#step-' + step).addClass('active');

            // Scroll to top
            $('html, body').animate({ scrollTop: 0 }, 'fast');
        }

        function validateStep1() {
            const name = $('#customer_name').val().trim();
            const phone = $('#customer_phone').val().trim();
            const address = $('#customer_address').val().trim();
            const email = $('#customer_email').val().trim();

            let isValid = true;
            let errors = [];

            if (!name) {
                errors.push('Vui lòng nhập họ tên khách hàng');
                isValid = false;
            }

            if (!phone) {
                errors.push('Vui lòng nhập số điện thoại');
                isValid = false;
            } else if (!phone.match(/^[0-9]{10,11}$/)) {
                errors.push('Số điện thoại không hợp lệ (10-11 chữ số)');
                isValid = false;
            }

            if (!address) {
                errors.push('Vui lòng nhập địa chỉ giao hàng');
                isValid = false;
            }

            if (email && !email.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
                errors.push('Email không hợp lệ');
                isValid = false;
            }

            if (!isValid) {
                showAlert('danger', 'Thông tin khách hàng không hợp lệ:<br>' + errors.join('<br>'));
            }

            return isValid;
        }

        function validateStep2() {
            if (cartItems.length === 0) {
                showAlert('danger', 'Vui lòng thêm ít nhất một sản phẩm vào giỏ hàng');
                return false;
            }
            return true;
        }

        function addToCart() {
            const productVariantId = $('#product_name').val();
            const productName = $('#product_name option:selected').attr('data-product-name');
            const sku = $('#product_sku').val();
            const size = $('#product_size').val();
            const color = $('#product_color').val();
            const quantity = parseInt($('#product_quantity').val());
            const price = parseFloat($('#product_price').val());
            const availableStock = parseInt($('#available_stock').text());

            // Validation
            if (!productVariantId) {
                showAlert('danger', 'Vui lòng chọn sản phẩm');
                return;
            }

            if (!quantity || quantity <= 0) {
                showAlert('danger', 'Vui lòng nhập số lượng hợp lệ');
                return;
            }

            if (quantity > availableStock) {
                showAlert('danger', 'Tồn kho không đủ! Chỉ còn ' + availableStock + ' sản phẩm');
                return;
            }

            // Check if product already in cart
            const existingIndex = cartItems.findIndex(item => item.variant_id === productVariantId);
            
            if (existingIndex !== -1) {
                // Update quantity if product already exists
                const newQuantity = cartItems[existingIndex].quantity + quantity;
                if (newQuantity > availableStock) {
                    showAlert('danger', 'Tổng số lượng vượt quá tồn kho! Chỉ còn ' + availableStock + ' sản phẩm');
                    return;
                }
                cartItems[existingIndex].quantity = newQuantity;
                cartItems[existingIndex].total_price = newQuantity * price;
            } else {
                // Add new item to cart
                cartItems.push({
                    variant_id: productVariantId,
                    product_name: productName,
                    sku: sku,
                    size: size,
                    color: color,
                    quantity: quantity,
                    unit_price: price,
                    total_price: quantity * price,
                    available_stock: availableStock
                });
            }

            // Update cart display
            updateCartDisplay();
            
            // Clear product selection
            $('#product_type').val('').trigger('change');
            clearProductSelection();
            $('#product_quantity').val('1');
            
            showAlert('success', 'Đã thêm sản phẩm vào giỏ hàng');
        }

        function updateCartDisplay() {
            const cartBody = $('#cartBody');
            const emptyRow = $('#emptyCartRow');
            
            if (cartItems.length === 0) {
                emptyRow.show();
                cartBody.find('tr:not(#emptyCartRow)').remove();
            } else {
                emptyRow.hide();
                cartBody.find('tr:not(#emptyCartRow)').remove();
                
                cartItems.forEach((item, index) => {
                    const row = $('<tr></tr>');
                    row.append('<td class="text-center">' + (index + 1) + '</td>');
                    row.append('<td><small>' + item.product_name + '</small><br><span class="badge badge-secondary">' + item.sku + '</span></td>');
                    row.append('<td><small>Size: ' + item.size + '<br>Màu: ' + item.color + '</small></td>');
                    row.append('<td class="text-center"><strong>' + item.quantity + '</strong></td>');
                    row.append('<td class="text-right"><small>' + formatCurrency(item.unit_price) + '</small></td>');
                    row.append('<td class="text-right"><strong>' + formatCurrency(item.total_price) + '</strong></td>');
                    row.append('<td class="text-center"><button type="button" class="btn btn-sm btn-danger" onclick="removeFromCart(' + index + ')" title="Xóa"><i class="fas fa-trash"></i></button></td>');
                    
                    cartBody.append(row);
                });
            }
            
            // Update cart count
            $('#cart_count').text(cartItems.length);
            
            // Calculate total
            calculateCartTotal();
        }

        function removeFromCart(index) {
            Swal.fire({
                title: 'Xác nhận xóa',
                text: 'Bạn có chắc muốn xóa sản phẩm này khỏi giỏ hàng?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Xóa',
                cancelButtonText: 'Hủy'
            }).then((result) => {
                if (result.isConfirmed) {
                    cartItems.splice(index, 1);
                    updateCartDisplay();
                    showAlert('success', 'Đã xóa sản phẩm khỏi giỏ hàng');
                }
            });
        }

        function calculateCartTotal() {
            const discount = parseFloat($('#order_discount').val()) || 0;
            
            let subtotal = 0;
            cartItems.forEach(item => {
                subtotal += item.total_price;
            });
            
            const discountAmount = subtotal * (discount / 100);
            const total = subtotal - discountAmount;
            
            $('#total_amount').val(total);
            $('#total_amount_display').text(formatCurrency(total) + ' VNĐ');
        }

        function searchCustomers(query) {
            $.ajax({
                url: 'api/tim_kiem_khach_hang.php',
                method: 'GET',
                data: { query: query },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        displayCustomerSuggestions(response.data);
                    }
                },
                error: function() {
                    console.error('Error searching customers');
                }
            });
        }

        function displayCustomerSuggestions(customers) {
            const suggestionsDiv = $('#customerSuggestions');
            suggestionsDiv.empty();

            if (customers.length > 0) {
                customers.forEach(function(customer) {
                    const suggestion = $('<div class="customer-suggestion"></div>');
                    suggestion.html('<strong>' + customer.full_name + '</strong><br>' + 
                                  'SĐT: ' + customer.phone + '<br>' + 
                                  'Địa chỉ: ' + customer.address);
                    
                    suggestion.click(function() {
                        fillCustomerInfo(customer);
                        suggestionsDiv.hide();
                    });
                    
                    suggestionsDiv.append(suggestion);
                });
                suggestionsDiv.show();
            } else {
                suggestionsDiv.hide();
            }
        }

        function fillCustomerInfo(customer) {
            $('#customer_name').val(customer.full_name);
            $('#customer_phone').val(customer.phone);
            $('#customer_email').val(customer.email || '');
            $('#customer_address').val(customer.address);
            $('#customer_note').val(customer.note || '');
            $('#existing_customer').val('');
        }



        function loadProductsByType(productType) {
            const productSelect = $('#product_name');
            const warehouseId = <?php echo $userWarehouseId ?? 'null'; ?>;
            
            // console.log('Debug: Loading products for type:', productType, 'warehouse:', warehouseId);
            
            productSelect.empty().append('<option value="">-- Đang tải sản phẩm... --</option>').prop('disabled', true);
            
            $.ajax({
                url: 'api/tim_kiem_san_pham.php',
                method: 'GET',
                data: { 
                    product_type: productType,
                    warehouse_id: warehouseId,
                    limit: 100
                },
                dataType: 'json',
                success: function(response) {
                    // console.log('Debug: API Response:', response);
                    
                    if (response.success && response.data.length > 0) {
                        productSelect.empty().append('<option value="">-- Chọn sản phẩm --</option>');
                        
                        response.data.forEach(function(product) {
                            const option = $('<option></option>');
                            option.val(product.variant_id);
                            const displayText = product.product_name + 
                                              ' - ' + product.color + 
                                              ' - Size ' + product.size + 
                                              ' (Tồn: ' + product.stock + ')' +
                                              ' - ' + formatCurrency(product.price) + ' VNĐ';
                            option.text(displayText);
                            
                            // Store product data as data attributes instead of jQuery data()
                            option.attr('data-product-sku', product.sku || '');
                            option.attr('data-product-color', product.color || '');
                            option.attr('data-product-size', product.size || '');
                            option.attr('data-product-price', product.price || 0);
                            option.attr('data-product-stock', product.stock || 0);
                            option.attr('data-product-name', product.product_name || '');
                            
                            productSelect.append(option);
                        });
                        
                        // Enable product selection
                        productSelect.prop('disabled', false);
                    } else {
                        productSelect.empty().append('<option value="">-- Không có sản phẩm nào --</option>');
                        productSelect.prop('disabled', true);
                        // console.log('Debug: No products found');
                    }
                },
                error: function(xhr, status, error) {
                    productSelect.empty().append('<option value="">-- Lỗi tải dữ liệu --</option>');
                    productSelect.prop('disabled', true);
                    console.error('Error loading products by category:', error);
                    console.error('Response:', xhr.responseText);
                }
            });
        }

        function clearProductSelection() {
            $('#product_name').empty().append('<option value="">-- Vui lòng chọn phân loại trước --</option>').prop('disabled', true);
            $('#product_size').empty().append('<option value="">-- Chọn size --</option>').prop('disabled', true);
            $('#product_color').val('');
            $('#product_sku').val('');
            $('#product_price').val('');
            $('#product_price_display').text('0 VNĐ');
            $('#available_stock').text('0');
        }

        function selectProduct(product) {
            // console.log('Debug: selectProduct called with:', product);
            
            $('#product_sku').val(product.sku || '');
            $('#product_color').val(product.color || '');
            $('#product_price').val(product.price || 0);
            $('#product_price_display').text(formatCurrency(product.price || 0) + ' VNĐ');
            $('#available_stock').text(product.stock || 0);
            
            // Update size options
            const sizeSelect = $('#product_size');
            sizeSelect.empty().append('<option value="' + (product.size || '') + '">' + (product.size || 'N/A') + '</option>');
            sizeSelect.val(product.size || '');
            sizeSelect.prop('disabled', false);
            
            // console.log('Debug: Updated fields - Price:', product.price, 'Stock:', product.stock);
        }

        function showConfirmation() {
            // Fill customer information
            $('#confirm_customer_name').text($('#customer_name').val());
            $('#confirm_customer_phone').text($('#customer_phone').val());
            $('#confirm_customer_email').text($('#customer_email').val() || 'Không có');
            $('#confirm_customer_address').text($('#customer_address').val());
            $('#confirm_customer_note').text($('#customer_note').val() || 'Không có');
            
            // Fill order information
            $('#confirm_order_id').text($('#order_id').val());
            $('#confirm_product_count').text(cartItems.length);
            $('#confirm_order_discount').text($('#order_discount').val() + '%');
            $('#confirm_total_amount').text(formatCurrency($('#total_amount').val()));
            
            // Fill products table
            const confirmBody = $('#confirmCartBody');
            confirmBody.empty();
            
            cartItems.forEach((item, index) => {
                const row = $('<tr></tr>');
                row.append('<td class="text-center">' + (index + 1) + '</td>');
                row.append('<td>' + item.product_name + '</td>');
                row.append('<td>' + item.sku + '</td>');
                row.append('<td class="text-center">' + item.size + '</td>');
                row.append('<td>' + item.color + '</td>');
                row.append('<td class="text-center"><strong>' + item.quantity + '</strong></td>');
                row.append('<td class="text-right">' + formatCurrency(item.unit_price) + ' VNĐ</td>');
                row.append('<td class="text-right"><strong>' + formatCurrency(item.total_price) + ' VNĐ</strong></td>');
                
                confirmBody.append(row);
            });
        }

        function submitOrder() {
            if (cartItems.length === 0) {
                showAlert('danger', 'Giỏ hàng trống! Vui lòng thêm sản phẩm');
                return;
            }

            const orderData = {
                customer: {
                    name: $('#customer_name').val(),
                    phone: $('#customer_phone').val(),
                    email: $('#customer_email').val(),
                    address: $('#customer_address').val(),
                    note: $('#customer_note').val()
                },
                order: {
                    order_id: $('#order_id').val(),
                    warehouse_id: <?php echo $userWarehouseId ?? 'null'; ?>,
                    discount: parseFloat($('#order_discount').val()),
                    total_price: parseFloat($('#total_amount').val()),
                    items: cartItems.map(item => ({
                        variant_id: item.variant_id,
                        quantity: item.quantity,
                        unit_price: item.unit_price,
                        total_price: item.total_price
                    }))
                }
            };

            $('#submitOrder').prop('disabled', true).text('Đang xử lý...');

            $.ajax({
                url: 'api/tao_don_hang.php',
                method: 'POST',
                data: JSON.stringify(orderData),
                contentType: 'application/json',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Sử dụng SweetAlert2 cho thông báo thành công
                        Swal.fire({
                            icon: 'success',
                            title: 'Thành công!',
                            text: 'Tạo đơn hàng thành công, chờ duyệt!',
                            timer: 2000,
                            timerProgressBar: true,
                            showConfirmButton: false,
                            allowOutsideClick: false,
                            allowEscapeKey: false,
                            didOpen: () => {
                                // Disable nút submit để prevent double click
                                $('#submitOrder').prop('disabled', true);
                            }
                        }).then(() => {
                            // Sử dụng redirect URL từ response để tự động lọc trạng thái pending
                            window.location.href = response.redirect || 'quan_ly_don_hang.php?status=pending';
                        });
                    } else {
                        // Sử dụng SweetAlert2 cho thông báo lỗi
                        Swal.fire({
                            icon: 'error',
                            title: 'Lỗi!',
                            text: response.message || 'Lỗi hệ thống! Đơn hàng chưa được lưu. Vui lòng thử lại.',
                            confirmButtonText: 'Thử lại'
                        });
                        $('#submitOrder').prop('disabled', false).text('Lưu đơn');
                    }
                },
                error: function() {
                    Swal.fire({
                        icon: 'error',
                        title: 'Lỗi kết nối!',
                        text: 'Lỗi hệ thống! Đơn hàng chưa được lưu. Vui lòng thử lại.',
                        confirmButtonText: 'Thử lại'
                    });
                    $('#submitOrder').prop('disabled', false).text('Lưu đơn');
                }
            });
        }

        function cancelOrder() {
            Swal.fire({
                title: 'Xác nhận hủy đơn hàng',
                text: 'Bạn có chắc chắn muốn hủy tạo đơn hàng?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Có, hủy đơn',
                cancelButtonText: 'Không'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = 'trang_chu.php';
                }
            });
        }

        function showAlert(type, message) {
            const alertDiv = $('<div class="alert alert-' + type + ' alert-dismissible fade show" role="alert">' +
                             message +
                             '<button type="button" class="close" data-dismiss="alert" aria-label="Close">' +
                             '<span aria-hidden="true">&times;</span></button></div>');
            
            $('#alertMessages').empty().append(alertDiv);
            
            // Auto hide after 5 seconds
            setTimeout(function() {
                alertDiv.fadeOut();
            }, 5000);
        }

        function formatCurrency(amount) {
            return new Intl.NumberFormat('vi-VN').format(amount);
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