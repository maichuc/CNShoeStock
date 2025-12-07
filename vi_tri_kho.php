<?php
session_start();

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    header('Location: login.html');
    exit();
}

// Kiểm tra quyền (chỉ admin và manager)
if (!in_array($_SESSION['role'], ['admin', 'manager'])) {
    header('Location: 404.html');
    exit();
}

// Lấy thông tin user
$userRole = $_SESSION['role'];
$userWarehouseId = $_SESSION['warehouse_id'] ?? null;
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="description" content="">
    <meta name="author" content="">

    <title>Quản lý Vị trí Kho - Smart Warehouse</title>

    <!-- Custom fonts for this template -->
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">

    <!-- Custom styles for this template -->
    <link href="css/sb-admin-2.min.css" rel="stylesheet">

    <!-- Custom styles for this page -->
    <link href="vendor/datatables/dataTables.bootstrap4.min.css" rel="stylesheet">
    
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">

    <style>
        .badge-active {
            background-color: #1cc88a;
            color: #ffffff;
            font-weight: 600;
            padding: 6px 12px;
        }
        .badge-inactive {
            background-color: #e74a3b;
            color: #ffffff;
            font-weight: 600;
            padding: 6px 12px;
        }
        .location-code {
            font-weight: 600;
            color: #4e73df;
        }
        .btn-action {
            margin: 0 2px;
        }
        .bulk-import-section {
            background-color: #f8f9fc;
            border: 2px dashed #dddfeb;
            border-radius: 5px;
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
                <nav class="navbar navbar-expand navbar-light bg-white topbar mb-4 static-top shadow">

                    <!-- Sidebar Toggle (Topbar) -->
                    <button id="sidebarToggleTop" class="btn btn-link d-md-none rounded-circle mr-3">
                        <i class="fa fa-bars"></i>
                    </button>

                    <!-- Topbar Navbar -->
                    <ul class="navbar-nav ml-auto">

                        <div class="topbar-divider d-none d-sm-block"></div>

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
                        <h1 class="h3 mb-0 text-gray-800">
                            <i class="fas fa-map-marked-alt"></i> Quản lý Vị trí Kho
                        </h1>
                        <div>
                            <button class="btn btn-success btn-icon-split" data-toggle="modal" data-target="#addLocationModal">
                                <span class="icon text-white-50">
                                    <i class="fas fa-plus"></i>
                                </span>
                                <span class="text">Thêm Vị trí Mới</span>
                            </button>
                        </div>
                    </div>

                    <!-- Filter Section -->
                    <div class="card shadow mb-4">
                        <div class="card-header py-3 d-flex justify-content-between align-items-center">
                            <h6 class="m-0 font-weight-bold text-primary">
                                <i class="fas fa-filter"></i> Bộ lọc & Tìm kiếm
                            </h6>
                            <button class="btn btn-sm btn-outline-primary" type="button" data-toggle="collapse" data-target="#filterCollapse" aria-expanded="true">
                                <i class="fas fa-chevron-down"></i> Thu gọn/Mở rộng
                            </button>
                        </div>
                        <div class="collapse show" id="filterCollapse">
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-3">
                                        <div class="form-group">
                                            <label><i class="fas fa-warehouse text-primary"></i> Kho</label>
                                            <select class="form-control" id="filterWarehouse">
                                                <option value="">🏢 Tất cả các kho</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="form-group">
                                            <label><i class="fas fa-box text-primary"></i> Loại sản phẩm</label>
                                            <select class="form-control" id="filterProductType">
                                                <option value="">📦 Tất cả loại</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="form-group">
                                            <label><i class="fas fa-search text-primary"></i> Tìm kiếm</label>
                                            <input type="text" class="form-control" id="searchInput" placeholder="Nhập mã vị trí hoặc mô tả...">
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <div class="form-group">
                                            <label><i class="fas fa-toggle-on text-primary"></i> Trạng thái</label>
                                            <select class="form-control" id="filterStatus">
                                                <option value="">📋 Tất cả</option>
                                                <option value="1">✅ Đang hoạt động</option>
                                                <option value="0">❌ Không hoạt động</option>
                                            </select>
                                        </div>
                                    </div>

                                </div>
                                <div class="row">
                                    <div class="col-md-12">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <span class="badge badge-info" id="totalCount">Tổng: 0 vị trí</span>
                                                <span class="badge badge-success ml-2" id="activeCount">Hoạt động: 0</span>
                                                <span class="badge badge-secondary ml-2" id="inactiveCount">Không hoạt động: 0</span>
                                            </div>
                                            <div>
                                                <button class="btn btn-primary" onclick="loadLocations()">
                                                    <i class="fas fa-search"></i> Tìm kiếm
                                                </button>
                                                <button class="btn btn-secondary ml-2" onclick="resetFilters()">
                                                    <i class="fas fa-redo"></i> Đặt lại
                                                </button>
                                                <button class="btn btn-success ml-2" onclick="exportToExcel()">
                                                    <i class="fas fa-file-excel"></i> Xuất Excel
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- DataTales -->
                    <div class="card shadow mb-4">
                        <div class="card-header py-3 d-flex justify-content-between align-items-center">
                            <h6 class="m-0 font-weight-bold text-primary">
                                <i class="fas fa-table"></i> Danh sách Vị trí Kho <span class="badge badge-pill badge-primary ml-2" id="resultCount">0</span>
                            </h6>
                            <div>
                                <label class="mr-2 mb-0">Hiển thị:</label>
                                <select class="form-control form-control-sm d-inline-block" id="entriesPerPage" style="width: auto;" onchange="loadLocations()">
                                    <option value="10">10</option>
                                    <option value="25" selected>25</option>
                                    <option value="50">50</option>
                                    <option value="100">100</option>
                                    <option value="all">Tất cả</option>
                                </select>
                                <span class="ml-1">mục/trang</span>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover table-striped" id="locationsTable" width="100%" cellspacing="0">
                                    <thead class="thead-dark">
                                        <tr>
                                            <th width="5%">#</th>
                                            <th width="15%"><i class="fas fa-warehouse"></i> Kho</th>
                                            <th width="15%"><i class="fas fa-map-marker-alt"></i> Mã Vị trí</th>
                                            <th width="30%"><i class="fas fa-info-circle"></i> Mô tả</th>
                                            <th width="12%" class="text-center"><i class="fas fa-toggle-on"></i> Trạng thái</th>
                                            <th width="13%"><i class="fas fa-calendar"></i> Ngày tạo</th>
                                            <th width="10%" class="text-center"><i class="fas fa-cogs"></i> Thao tác</th>
                                        </tr>
                                    </thead>
                                    <tbody id="locationsTableBody">
                                        <tr>
                                            <td colspan="7" class="text-center py-5">
                                                <i class="fas fa-spinner fa-spin fa-2x text-primary"></i>
                                                <p class="mt-2">Đang tải dữ liệu...</p>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <!-- Pagination -->
                            <div class="row mt-3">
                                <div class="col-md-6">
                                    <div id="paginationInfo"></div>
                                </div>
                                <div class="col-md-6">
                                    <nav aria-label="Page navigation">
                                        <ul class="pagination justify-content-end mb-0" id="pagination"></ul>
                                    </nav>
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
                        <span>Copyright &copy; Smart Warehouse 2025</span>
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

    <!-- Add Location Modal -->
    <div class="modal fade" id="addLocationModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-plus-circle"></i> Thêm Vị trí Kho Mới
                    </h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <!-- Chế độ nhập -->
                    <div class="mb-3">
                        <div class="btn-group" role="group">
                            <button type="button" class="btn btn-primary active" id="btnSingleMode" onclick="switchInputMode('single')">
                                <i class="fas fa-plus"></i> Thêm đơn lẻ
                            </button>
                            <button type="button" class="btn btn-outline-primary" id="btnBulkMode" onclick="switchInputMode('bulk')">
                                <i class="fas fa-layer-group"></i> Thêm hàng loạt
                            </button>
                        </div>
                    </div>

                    <!-- Template nhanh -->
                    <div class="alert alert-info" id="templateSection">
                        <h6><i class="fas fa-magic"></i> Template Nhanh:</h6>
                        <div class="row">
                            <div class="col-md-6">
                                <strong>Vị trí kho:</strong>
                                <div class="mt-2">
                                    <button type="button" class="btn btn-outline-primary btn-sm mr-2 mb-2" onclick="applyTemplate('sneaker')">
                                        <i class="fas fa-shoe-prints"></i> Sneaker
                                    </button>
                                    <button type="button" class="btn btn-outline-info btn-sm mr-2 mb-2" onclick="applyTemplate('boot')">
                                        <i class="fas fa-hiking"></i> Boot
                                    </button>
                                    <button type="button" class="btn btn-outline-success btn-sm mr-2 mb-2" onclick="applyTemplate('sandal')">
                                        <i class="fas fa-socks"></i> Sandal
                                    </button>
                                    <button type="button" class="btn btn-outline-warning btn-sm mb-2" onclick="applyTemplate('slipper')">
                                        <i class="fas fa-shoe-prints"></i> Slipper
                                    </button>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <strong>Tủ chứa theo size:</strong>
                                <div class="mt-2">
                                    <button type="button" class="btn btn-outline-info btn-sm mr-2 mb-2" onclick="applyTemplate('size_kids')">
                                        <i class="fas fa-child"></i> Size 3-4
                                    </button>
                                    <button type="button" class="btn btn-outline-success btn-sm mr-2 mb-2" onclick="applyTemplate('size_women')">
                                        <i class="fas fa-female"></i> Size 35-40
                                    </button>
                                    <button type="button" class="btn btn-outline-warning btn-sm mb-2" onclick="applyTemplate('size_men')">
                                        <i class="fas fa-male"></i> Size 40-45
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <form id="addLocationForm">
                        <div class="row">
                            <div class="col-md-12">
                                <div class="form-group">
                                    <label>Kho <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="addWarehouseDisplay" readonly>
                                    <input type="hidden" id="addWarehouse" name="warehouse_id">
                                    <small class="form-text text-muted">
                                        <i class="fas fa-info-circle"></i> Vị trí sẽ được thêm vào kho hiện tại của bạn
                                    </small>
                                </div>
                            </div>
                        </div>

                        <!-- Hidden fields for location code and type - auto generated -->
                        <input type="hidden" id="addLocationCode" name="location_code" required>
                        <input type="hidden" id="addLocationType" name="type">
                        
                        <div class="row" style="display: none;">
                            <div class="col-md-8">
                                <div class="form-group">
                                    <div id="locationCodeFeedback"></div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Loại vị trí</label>
                                    <select class="form-control" id="locationType" onchange="updateLocationCodeFormat()">
                                        <option value="rack">Kệ hàng</option>
                                        <option value="size">Theo size</option>
                                        <option value="zone">Khu vực</option>
                                        <option value="custom">Tùy chỉnh</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Dynamic input based on type -->
                        <div id="rackInputs" class="location-type-inputs">
                            <div class="alert alert-info mb-3">
                                <i class="fas fa-info-circle"></i> <strong>Nhập đầy đủ 4 thông tin</strong> để tự động tạo mã vị trí
                            </div>
                            
                            <!-- Form nhập nhanh hàng loạt -->
                            <div id="bulkInputForm" style="display: none;">
                                <div class="alert alert-warning">
                                    <strong><i class="fas fa-bolt"></i> Chế độ nhập nhanh hàng loạt:</strong>
                                    <p class="mb-0">Ví dụ: Khu vực giày boot mua 4 kệ, mỗi kệ có 3 tầng, mỗi tầng có 3 vị trí để 3 size giày khác nhau</p>
                                </div>
                                <div class="row">
                                    <div class="col-md-12">
                                        <div class="form-group">
                                            <label>Khu vực <span class="text-danger">*</span></label>
                                            <select class="form-control" id="bulkRackZone">
                                                <option value="">-- Chọn loại sản phẩm --</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <label>Kệ (từ-đến) <span class="text-danger">*</span></label>
                                            <div class="input-group">
                                                <input type="number" class="form-control" id="bulkRackFrom" placeholder="1" min="1" max="999">
                                                <div class="input-group-append input-group-prepend">
                                                    <span class="input-group-text">-</span>
                                                </div>
                                                <input type="number" class="form-control" id="bulkRackTo" placeholder="4" min="1" max="999">
                                            </div>
                                            <small class="form-text text-muted">Ví dụ: 1-4 (tạo 4 kệ)</small>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <label>Tầng (từ-đến) <span class="text-danger">*</span></label>
                                            <div class="input-group">
                                                <input type="number" class="form-control" id="bulkFloorFrom" placeholder="1" min="1" max="99">
                                                <div class="input-group-append input-group-prepend">
                                                    <span class="input-group-text">-</span>
                                                </div>
                                                <input type="number" class="form-control" id="bulkFloorTo" placeholder="3" min="1" max="99">
                                            </div>
                                            <small class="form-text text-muted">Ví dụ: 1-3 (mỗi kệ 3 tầng)</small>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <label>Số vị trí/tầng <span class="text-danger">*</span></label>
                                            <input type="number" class="form-control" id="bulkPositionCount" placeholder="3" min="1" max="99">
                                            <small class="form-text text-muted">Ví dụ: 3 (mỗi tầng 3 vị trí)</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-12">
                                        <button type="button" class="btn btn-info btn-block" onclick="previewBulkLocations()">
                                            <i class="fas fa-eye"></i> Xem trước danh sách sẽ tạo
                                        </button>
                                    </div>
                                </div>
                                <div class="row mt-3" id="bulkPreviewSection" style="display: none;">
                                    <div class="col-12">
                                        <div class="alert alert-success">
                                            <h6><strong>Danh sách sẽ tạo:</strong> <span id="bulkTotalCount" class="badge badge-primary">0</span> vị trí</h6>
                                            <div id="bulkPreviewList" style="max-height: 200px; overflow-y: auto; font-size: 0.9em;">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Form nhập đơn lẻ -->
                            <div id="singleInputForm">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Khu vực <span class="text-danger">*</span></label>
                                        <select class="form-control" id="rackZone" onchange="generateRackCode()">
                                            <option value="">-- Chọn loại sản phẩm --</option>
                                        </select>
                                        <small class="form-text text-muted">Loại sản phẩm đã có trong kho</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Kệ <span class="text-danger">*</span></label>
                                        <input type="number" class="form-control" id="rackLetter" placeholder="1, 2, 3..." min="1" max="999" oninput="generateRackCode()">
                                        <small class="form-text text-muted">Số thứ tự kệ: 1, 2, 3...</small>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Tầng <span class="text-danger">*</span></label>
                                        <input type="number" class="form-control" id="rackFloor" placeholder="1, 2, 3..." min="1" max="99" oninput="generateRackCode()">
                                        <small class="form-text text-muted">Tầng của kệ: 1, 2, 3...</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Vị trí <span class="text-danger">*</span></label>
                                        <input type="number" class="form-control" id="rackPosition" placeholder="1, 2, 3..." min="1" max="99" oninput="generateRackCode()">
                                        <small class="form-text text-muted">Vị trí trên tầng: 1, 2, 3...</small>
                                    </div>
                                </div>
                            </div>
                            <!-- Preview generated code -->
                            <div class="row" id="generatedCodePreview" style="display: none;">
                                <div class="col-12">
                                    <div class="alert alert-success">
                                        <i class="fas fa-check-circle"></i> <strong>Mã vị trí được tạo:</strong> 
                                        <span id="generatedCodeDisplay" class="badge badge-primary" style="font-size: 1.1em; padding: 8px 15px;"></span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div id="sizeInputs" class="location-type-inputs" style="display: none;">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Size từ</label>
                                        <input type="text" class="form-control" id="sizeFrom" placeholder="35, 38..." oninput="generateSizeCode()">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Size đến</label>
                                        <input type="text" class="form-control" id="sizeTo" placeholder="40, 42..." oninput="generateSizeCode()">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div id="zoneInputs" class="location-type-inputs" style="display: none;">
                            <div class="form-group">
                                <label>Tên khu vực</label>
                                <input type="text" class="form-control" id="zoneName" placeholder="NHAP-KHO, XUAT-HANG..." oninput="generateZoneCode()">
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Mô tả <span class="text-muted">(tự động tạo)</span></label>
                            <textarea class="form-control" id="addDescription" name="description" rows="2" 
                                      placeholder="Mô tả sẽ được tạo tự động hoặc bạn có thể nhập thủ công..."></textarea>
                        </div>

                        <div class="form-group">
                            <div class="custom-control custom-checkbox">
                                <input type="checkbox" class="custom-control-input" id="addIsActive" name="is_active" checked>
                                <label class="custom-control-label" for="addIsActive">Kích hoạt ngay</label>
                            </div>
                        </div>

                        <!-- Preview -->
                        <div class="alert alert-secondary" id="locationPreview" style="display: none;">
                            <h6><i class="fas fa-eye"></i> Xem trước:</h6>
                            <div id="previewContent"></div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">
                        <i class="fas fa-times"></i> Hủy
                    </button>
                    <button type="button" class="btn btn-success" id="btnSaveSingle" onclick="saveLocation()">
                        <i class="fas fa-save"></i> Lưu
                    </button>
                    <button type="button" class="btn btn-primary" id="btnSaveBulk" style="display: none;" onclick="saveBulkLocations()">
                        <i class="fas fa-layer-group"></i> Tạo hàng loạt
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Location Modal -->
    <div class="modal fade" id="editLocationModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-edit"></i> Chỉnh sửa Vị trí Kho
                    </h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <form id="editLocationForm">
                        <input type="hidden" id="editLocationId" name="location_id">
                        <div class="form-group">
                            <label>Kho</label>
                            <input type="text" class="form-control" id="editWarehouseName" readonly>
                            <input type="hidden" id="editWarehouseId" name="warehouse_id">
                        </div>
                        <div class="form-group">
                            <label>Mã Vị trí <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="editLocationCode" name="location_code" required>
                        </div>
                        <div class="form-group">
                            <label>Mô tả</label>
                            <textarea class="form-control" id="editDescription" name="description" rows="3"></textarea>
                        </div>
                        <div class="form-group">
                            <div class="custom-control custom-checkbox">
                                <input type="checkbox" class="custom-control-input" id="editIsActive" name="is_active">
                                <label class="custom-control-label" for="editIsActive">Đang hoạt động</label>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">
                        <i class="fas fa-times"></i> Hủy
                    </button>
                    <button type="button" class="btn btn-primary" onclick="updateLocation()">
                        <i class="fas fa-save"></i> Cập nhật
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bulk Add Modal -->
    <div class="modal fade" id="bulkAddModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-layer-group"></i> Thêm Vị trí Hàng Loạt
                    </h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="bulk-import-section">
                        <h6><i class="fas fa-info-circle"></i> Hướng dẫn:</h6>
                        <ul>
                            <li>Chọn kho muốn thêm vị trí</li>
                            <li>Nhập từng vị trí mới trên một dòng</li>
                            <li>Định dạng: <strong>Mã_vị_trí|Mô_tả</strong></li>
                            <li>Ví dụ theo kệ: <code>A01-01|Kệ A, tầng 01, vị trí 01</code></li>
                            <li>Ví dụ theo size 3-4: <code>SIZE-3-4|Khu vực size 3-4</code></li>
                            <li>Ví dụ theo size cụ thể: <code>SIZE-38-39|Khu vực size 38-39</code></li>
                        </ul>
                    </div>
                    
                    <form id="bulkAddForm">
                        <div class="form-group">
                            <label>Kho <span class="text-danger">*</span></label>
                            <select class="form-control" id="bulkWarehouse" required <?php echo ($userRole === 'manager') ? 'disabled' : ''; ?>>
                                <option value="">-- Chọn kho --</option>
                            </select>
                            <?php if ($userRole === 'manager'): ?>
                            <input type="hidden" id="bulkWarehouseHidden">
                            <small class="form-text text-muted">Bạn chỉ có thể quản lý vị trí kho của kho được phân công</small>
                            <?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label>Danh sách Vị trí <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="bulkLocationList" rows="12" 
                                      placeholder="A01-01|Kệ A, tầng 01, vị trí 01&#10;A01-02|Kệ A, tầng 01, vị trí 02&#10;SIZE-3-4|Khu vực size 3-4&#10;SIZE-35-36|Khu vực size 35-36&#10;SIZE-38-39|Khu vực size 38-39&#10;SIZE-40-42|Khu vực size 40-42" 
                                      required></textarea>
                            <small class="form-text text-muted">
                                Mỗi dòng là một vị trí. Định dạng: Mã|Mô tả. Có thể tổ chức theo kệ hoặc theo nhóm size.
                            </small>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">
                        <i class="fas fa-times"></i> Hủy
                    </button>
                    <button type="button" class="btn btn-info" onclick="bulkAddLocations()">
                        <i class="fas fa-upload"></i> Thêm Hàng Loạt
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

    <!-- Custom script for warehouse locations -->
    <script>
        const userRole = '<?php echo $userRole; ?>';
        const userWarehouseId = <?php echo $userWarehouseId ? $userWarehouseId : 'null'; ?>;
    </script>
    <script src="js/warehouse_locations.js"></script>
    <script>

        // Chuyển đổi chế độ nhập
        function switchInputMode(mode) {
            if (mode === 'bulk') {
                $('#singleInputForm').hide();
                $('#bulkInputForm').show();
                $('#btnSaveSingle').hide();
                $('#btnSaveBulk').show();
                $('#templateSection').hide();
                
                // Update button states
                $('#btnSingleMode').removeClass('active').addClass('btn-outline-primary').removeClass('btn-primary');
                $('#btnBulkMode').addClass('active').removeClass('btn-outline-primary').addClass('btn-primary');
                
                // Copy product types to bulk select
                const options = $('#rackZone').html();
                $('#bulkRackZone').html(options);
            } else {
                $('#singleInputForm').show();
                $('#bulkInputForm').hide();
                $('#btnSaveSingle').show();
                $('#btnSaveBulk').hide();
                $('#templateSection').show();
                $('#bulkPreviewSection').hide();
                
                // Update button states
                $('#btnBulkMode').removeClass('active').addClass('btn-outline-primary').removeClass('btn-primary');
                $('#btnSingleMode').addClass('active').removeClass('btn-outline-primary').addClass('btn-primary');
            }
        }
        
        // Xem trước danh sách vị trí hàng loạt
        function previewBulkLocations() {
            const zone = $('#bulkRackZone').val();
            const rackFromVal = $('#bulkRackFrom').val();
            const rackToVal = $('#bulkRackTo').val();
            const floorFromVal = $('#bulkFloorFrom').val();
            const floorToVal = $('#bulkFloorTo').val();
            const positionCountVal = $('#bulkPositionCount').val();
            
            // Debug - xem giá trị đang lấy được
            console.log('Zone:', zone);
            console.log('RackFrom:', rackFromVal);
            console.log('RackTo:', rackToVal);
            console.log('FloorFrom:', floorFromVal);
            console.log('FloorTo:', floorToVal);
            console.log('PositionCount:', positionCountVal);
            
            // Kiểm tra đầy đủ thông tin
            if (!zone || !rackFromVal || !rackToVal || !floorFromVal || !floorToVal || !positionCountVal) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Cảnh báo',
                    text: 'Vui lòng nhập đầy đủ thông tin',
                    confirmButtonText: 'OK'
                });
                return;
            }
            
            const rackFrom = parseInt(rackFromVal);
            const rackTo = parseInt(rackToVal);
            const floorFrom = parseInt(floorFromVal);
            const floorTo = parseInt(floorToVal);
            const positionCount = parseInt(positionCountVal);
            
            // Kiểm tra giá trị hợp lệ
            if (rackFrom < 1 || rackTo < 1 || floorFrom < 1 || floorTo < 1 || positionCount < 1) {
                Swal.fire({
                    icon: 'error',
                    title: 'Lỗi',
                    text: 'Tất cả giá trị phải lớn hơn 0',
                    confirmButtonText: 'OK'
                });
                return;
            }
            
            if (rackFrom > rackTo) {
                Swal.fire({
                    icon: 'error',
                    title: 'Lỗi',
                    text: 'Kệ từ phải nhỏ hơn hoặc bằng kệ đến',
                    confirmButtonText: 'OK'
                });
                return;
            }
            
            if (floorFrom > floorTo) {
                Swal.fire({
                    icon: 'error',
                    title: 'Lỗi',
                    text: 'Tầng từ phải nhỏ hơn hoặc bằng tầng đến',
                    confirmButtonText: 'OK'
                });
                return;
            }
            
            const zoneCode = removeVietnameseTones(zone);
            let locations = [];
            let html = '<div class="row">';
            
            for (let rack = rackFrom; rack <= rackTo; rack++) {
                for (let floor = floorFrom; floor <= floorTo; floor++) {
                    for (let pos = 1; pos <= positionCount; pos++) {
                        const code = `${zoneCode}-K${rack}-T${floor}-P${pos}`;
                        const desc = `Khu vực ${zone}, kệ ${rack}, tầng ${floor}, vị trí ${pos}`;
                        locations.push({code, desc});
                        
                        html += `<div class="col-md-6 mb-1"><small><span class="badge badge-info">${code}</span> ${desc}</small></div>`;
                    }
                }
            }
            
            html += '</div>';
            
            $('#bulkTotalCount').text(locations.length);
            $('#bulkPreviewList').html(html);
            $('#bulkPreviewSection').slideDown();
            
            // Lưu vào biến global để dùng khi save
            window.bulkLocationsToCreate = locations;
        }
        
        // Lưu hàng loạt vị trí
        function saveBulkLocations() {
            if (!window.bulkLocationsToCreate || window.bulkLocationsToCreate.length === 0) {
                Swal.fire('Cảnh báo', 'Vui lòng xem trước danh sách trước khi tạo', 'warning');
                return;
            }
            
            const warehouseId = $('#addWarehouse').val();
            if (!warehouseId) {
                Swal.fire('Lỗi', 'Không xác định được kho', 'error');
                return;
            }
            
            const bulkZone = $('#bulkRackZone').val(); // Loại sản phẩm có dấu
            
            const locations = window.bulkLocationsToCreate.map(loc => ({
                location_code: loc.code,
                description: loc.desc
            }));
            
            const data = {
                warehouse_id: warehouseId,
                type: bulkZone || null,
                locations: locations
            };
            
            Swal.fire({
                title: 'Xác nhận tạo?',
                html: `Bạn sắp tạo <strong>${locations.length}</strong> vị trí kho.<br>Thao tác này không thể hoàn tác!`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Tạo ngay',
                cancelButtonText: 'Hủy'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.ajax({
                        url: 'api_vi_tri_kho.php?action=bulk_create',
                        type: 'POST',
                        contentType: 'application/json',
                        data: JSON.stringify(data),
                        success: function(response) {
                            if (response.success) {
                                const stats = response.stats || {};
                                const successCount = stats.success || 0;
                                const failedCount = stats.failed || 0;
                                const errors = stats.errors || [];
                                
                                // Hiển thị kết quả
                                let message = `✅ Thành công: ${successCount} vị trí`;
                                if (failedCount > 0) {
                                    message += `<br>❌ Thất bại: ${failedCount} vị trí`;
                                    
                                    if (errors.length > 0) {
                                        message += '<br><br><strong>Chi tiết lỗi:</strong><br>';
                                        message += '<div style="text-align: left; max-height: 200px; overflow-y: auto;">';
                                        errors.forEach(err => {
                                            message += `<small>• ${err}</small><br>`;
                                        });
                                        message += '</div>';
                                    }
                                }
                                
                                Swal.fire({
                                    title: failedCount > 0 ? 'Hoàn thành một phần' : 'Thành công!',
                                    html: message,
                                    icon: failedCount > 0 ? 'warning' : 'success',
                                    confirmButtonText: 'OK'
                                });
                                
                                $('#addLocationModal').modal('hide');
                                loadLocations();
                                
                                // Reset form
                                $('#bulkRackZone').val('');
                                $('#bulkRackFrom').val('');
                                $('#bulkRackTo').val('');
                                $('#bulkFloorFrom').val('');
                                $('#bulkFloorTo').val('');
                                $('#bulkPositionCount').val('');
                                $('#bulkPreviewSection').hide();
                                window.bulkLocationsToCreate = null;
                            } else {
                                Swal.fire('Lỗi', response.message, 'error');
                            }
                        },
                        error: function(xhr) {
                            console.error('Error:', xhr);
                            Swal.fire('Lỗi', 'Không thể kết nối đến server', 'error');
                        }
                    });
                }
            });
        }
        
        // Load danh sách loại sản phẩm theo warehouse
        function loadProductTypes(warehouseId, targetSelects = '#rackZone, #bulkRackZone') {
            if (!warehouseId) {
                $(targetSelects).html('<option value="">-- Chọn kho trước --</option>');
                return;
            }
            
            $.ajax({
                url: `api_vi_tri_kho.php?action=product_types&warehouse_id=${warehouseId}`,
                type: 'GET',
                success: function(response) {
                    if (response.success) {
                        let options = '<option value="">-- Chọn loại sản phẩm --</option>';
                        let filterOptions = '<option value="">📦 Tất cả loại</option>';
                        response.data.forEach(function(item) {
                            options += `<option value="${item.type}">${item.type}</option>`;
                            filterOptions += `<option value="${item.type}">${item.type}</option>`;
                        });
                        $(targetSelects).html(options);
                        // Cập nhật filter dropdown nếu có
                        if ($('#filterProductType').length) {
                            $('#filterProductType').html(filterOptions);
                        }
                    } else {
                        $(targetSelects).html('<option value="">Không có dữ liệu</option>');
                    }
                },
                error: function() {
                    $(targetSelects).html('<option value="">Lỗi tải dữ liệu</option>');
                }
            });
        }
        
        // Load danh sách warehouses
        function loadWarehouses() {
            $.ajax({
                url: 'api_vi_tri_kho.php?action=warehouses',
                type: 'GET',
                success: function(response) {
                    if (response.success) {
                        const warehouses = response.data;
                        let filterOptions = '<option value="">Tất cả các kho</option>';
                        let bulkOptions = '<option value="">-- Chọn kho --</option>';
                        
                        // Nếu là manager, chỉ hiển thị kho được phân công
                        const allowedWarehouses = (userRole === 'manager' && userWarehouseId) 
                            ? warehouses.filter(wh => wh.warehouse_id == userWarehouseId)
                            : warehouses;
                        
                        let currentWarehouseName = '';
                        allowedWarehouses.forEach(function(wh) {
                            bulkOptions += `<option value="${wh.warehouse_id}">${wh.name}</option>`;
                            filterOptions += `<option value="${wh.warehouse_id}">${wh.name}</option>`;
                            
                            // Tìm tên kho hiện tại của user
                            if (userWarehouseId && wh.warehouse_id == userWarehouseId) {
                                currentWarehouseName = wh.name;
                            }
                        });
                        
                        $('#bulkWarehouse').html(bulkOptions);
                        
                        // Nếu là manager, không hiển thị option "Tất cả các kho"
                        if (userRole === 'manager' && userWarehouseId) {
                            $('#filterWarehouse').html(filterOptions);
                        } else {
                            $('#filterWarehouse').html(filterOptions);
                        }
                        
                        // Set kho hiện tại cho modal thêm đơn lẻ
                        if (userWarehouseId) {
                            $('#addWarehouse').val(userWarehouseId);
                            $('#addWarehouseDisplay').val(currentWarehouseName || 'Kho của bạn');
                            $('#bulkWarehouse, #filterWarehouse').val(userWarehouseId);
                            $('#bulkWarehouseHidden').val(userWarehouseId);
                            // Tự động load locations của kho đó
                            loadLocations();
                            // Load danh sách loại sản phẩm của kho đó
                            loadProductTypes(userWarehouseId);
                            // Load product types cho filter
                            if (typeof loadFilterProductTypes === 'function') {
                                loadFilterProductTypes(userWarehouseId);
                            }
                        } else if (userRole === 'admin' && allowedWarehouses.length > 0) {
                            // Admin: chọn kho đầu tiên
                            const firstWarehouse = allowedWarehouses[0];
                            $('#addWarehouse').val(firstWarehouse.warehouse_id);
                            $('#addWarehouseDisplay').val(firstWarehouse.name);
                            // Load danh sách loại sản phẩm của kho đầu tiên
                            loadProductTypes(firstWarehouse.warehouse_id);
                            // Load product types cho filter
                            if (typeof loadFilterProductTypes === 'function') {
                                loadFilterProductTypes(firstWarehouse.warehouse_id);
                            }
                        }
                    }
                },
                error: function(xhr) {
                    console.error('Error loading warehouses:', xhr);
                }
            });
        }

        // Load danh sách locations
        function loadLocations() {
            const warehouseId = $('#filterWarehouse').val();
            const search = $('#searchInput').val();
            const isActive = $('#filterStatus').val();
            const productType = $('#filterProductType').val();
            
            $.ajax({
                url: 'api_vi_tri_kho.php?action=list',
                type: 'GET',
                data: {
                    warehouse_id: warehouseId,
                    search: search,
                    is_active: isActive,
                    product_type: productType
                },
                success: function(response) {
                    if (response.success) {
                        displayLocations(response.data);
                    } else {
                        showAlert('error', response.message || 'Không thể tải dữ liệu');
                    }
                },
                error: function(xhr) {
                    console.error('Error:', xhr);
                    showAlert('error', 'Lỗi kết nối đến server');
                }
            });
        }

        // Hiển thị danh sách locations
        function displayLocations(locations) {
            const tbody = $('#locationsTableBody');
            
            if (locations.length === 0) {
                tbody.html('<tr><td colspan="7" class="text-center">Không có dữ liệu</td></tr>');
                // Update statistics
                $('#totalCount').text('Tổng: 0 vị trí');
                $('#activeCount').text('Hoạt động: 0');
                $('#inactiveCount').text('Không hoạt động: 0');
                $('#resultCount').text('0');
                return;
            }
            
            // Calculate statistics
            let activeCount = 0;
            let inactiveCount = 0;
            
            let html = '';
            locations.forEach(function(loc) {
                const isActive = loc.is_active == 1;
                const statusBadge = isActive
                    ? '<span class="badge badge-active">Đang hoạt động</span>' 
                    : '<span class="badge badge-inactive">Không hoạt động</span>';
                
                // Count statistics
                if (isActive) {
                    activeCount++;
                } else {
                    inactiveCount++;
                }
                
                const createdAt = new Date(loc.created_at).toLocaleDateString('vi-VN');
                
                // Kiểm tra quyền cho manager
                const canModify = (userRole === 'admin') || (userRole === 'manager' && userWarehouseId == loc.warehouse_id);
                
                const deleteButton = canModify
                    ? `<button class="btn btn-sm btn-danger btn-action" onclick="deleteLocation(${loc.location_id}, '${loc.location_code}', ${loc.warehouse_id})" title="Xóa">
                        <i class="fas fa-trash"></i>
                       </button>`
                    : `<button class="btn btn-sm btn-secondary btn-action" disabled title="Không có quyền">
                        <i class="fas fa-trash"></i>
                       </button>`;
                
                // Nút Tạm ngưng/Kích hoạt (toggle is_active)
                const toggleButton = canModify
                    ? (isActive
                        ? `<button class="btn btn-sm btn-warning btn-action" onclick="toggleStatus(${loc.location_id}, 0, '${loc.location_code}')" title="Tạm ngưng">
                            <i class="fas fa-pause"></i>
                           </button>`
                        : `<button class="btn btn-sm btn-success btn-action" onclick="toggleStatus(${loc.location_id}, 1, '${loc.location_code}')" title="Kích hoạt lại">
                            <i class="fas fa-play"></i>
                           </button>`)
                    : `<button class="btn btn-sm btn-secondary btn-action" disabled title="Không có quyền">
                        <i class="fas fa-pause"></i>
                       </button>`;
                
                html += `
                    <tr>
                        <td>${loc.location_id}</td>
                        <td>${loc.warehouse_name || 'N/A'}</td>
                        <td><span class="location-code">${loc.location_code}</span></td>
                        <td>${loc.description || ''}</td>
                        <td class="text-center">${statusBadge}</td>
                        <td>${createdAt}</td>
                        <td class="text-center">
                            ${toggleButton}
                            ${deleteButton}
                        </td>
                    </tr>
                `;
            });
            
            tbody.html(html);
            
            // Update statistics badges
            $('#totalCount').text('Tổng: ' + locations.length + ' vị trí');
            $('#activeCount').text('Hoạt động: ' + activeCount);
            $('#inactiveCount').text('Không hoạt động: ' + inactiveCount);
            $('#resultCount').text(locations.length);
        }

        // Apply template
        function applyTemplate(type) {
            switch(type) {
                case 'sneaker':
                case 'boot':
                case 'sandal':
                case 'slipper':
                    $('#locationType').val('rack');
                    updateLocationCodeFormat();
                    // Chọn loại sản phẩm từ dropdown (nếu có)
                    const productType = type.toUpperCase();
                    if ($('#rackZone option[value="' + productType + '"]').length > 0) {
                        $('#rackZone').val(productType);
                    } else {
                        // Nếu không có trong dropdown, chọn option đầu tiên (sau "-- Chọn --")
                        const firstOption = $('#rackZone option:eq(1)').val();
                        if (firstOption) {
                            $('#rackZone').val(firstOption);
                        }
                    }
                    $('#rackLetter').val('1');
                    $('#rackFloor').val('1');
                    $('#rackPosition').val('1');
                    generateRackCode();
                    break;
                case 'size_kids':
                    $('#locationType').val('size');
                    updateLocationCodeFormat();
                    $('#sizeFrom').val('3');
                    $('#sizeTo').val('4');
                    generateSizeCode();
                    break;
                case 'size_women':
                    $('#locationType').val('size');
                    updateLocationCodeFormat();
                    $('#sizeFrom').val('35');
                    $('#sizeTo').val('40');
                    generateSizeCode();
                    break;
                case 'size_men':
                    $('#locationType').val('size');
                    updateLocationCodeFormat();
                    $('#sizeFrom').val('40');
                    $('#sizeTo').val('45');
                    generateSizeCode();
                    break;
            }
        }
        
        // Update location code format
        function updateLocationCodeFormat() {
            const type = $('#locationType').val();
            $('.location-type-inputs').hide();
            
            switch(type) {
                case 'rack':
                    $('#rackInputs').show();
                    $('#locationCodeHelp').text('Định dạng: [Khu vực]-K[Kệ]-T[Tầng]-P[Vị trí]. Ví dụ: SNEAKER-K1-T1-P1, BOOT-K2-T2-P3');
                    break;
                case 'size':
                    $('#sizeInputs').show();
                    $('#locationCodeHelp').text('Định dạng: SIZE-[từ]-[đến]. Ví dụ: SIZE-35-40, SIZE-3-4');
                    break;
                case 'zone':
                    $('#zoneInputs').show();
                    $('#locationCodeHelp').text('Định dạng: ZONE-[Tên]. Ví dụ: ZONE-NHAP-KHO, ZONE-XUAT-HANG');
                    break;
                case 'custom':
                    $('#locationCodeHelp').text('Nhập mã tùy chỉnh của bạn');
                    break;
            }
        }
        
        // Hàm chuyển tiếng Việt có dấu thành không dấu
        function removeVietnameseTones(str) {
            if (!str) return '';
            str = str.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
            str = str.replace(/đ/g, 'd').replace(/Đ/g, 'D');
            return str.toUpperCase().replace(/\s+/g, '-');
        }
        
        // Generate rack code
        function generateRackCode() {
            const zone = $('#rackZone').val(); // Tên gốc có thể có dấu
            const zoneCode = removeVietnameseTones(zone); // Chuyển thành không dấu cho mã
            const rackNum = $('#rackLetter').val();
            const floor = $('#rackFloor').val();
            const position = $('#rackPosition').val();
            
            if (zone && rackNum && floor && position) {
                const code = `${zoneCode}-K${rackNum}-T${floor}-P${position}`;
                $('#addLocationCode').val(code);
                $('#addDescription').val(`Khu vực ${zone}, kệ ${rackNum}, tầng ${floor}, vị trí ${position}`);
                
                // Lưu type (loại sản phẩm có dấu)
                $('#addLocationType').val(zone);
                
                // Show generated code preview
                $('#generatedCodeDisplay').text(code);
                $('#generatedCodePreview').slideDown();
                
                updatePreview();
            } else {
                $('#generatedCodePreview').slideUp();
            }
        }
        
        // Generate size code
        function generateSizeCode() {
            const from = $('#sizeFrom').val();
            const to = $('#sizeTo').val();
            
            if (from && to) {
                const code = `SIZE-${from}-${to}`;
                $('#addLocationCode').val(code);
                $('#addDescription').val(`Tủ chứa size ${from} đến ${to}`);
                updatePreview();
            }
        }
        
        // Generate zone code
        function generateZoneCode() {
            const name = $('#zoneName').val().toUpperCase();
            
            if (name) {
                const code = `ZONE-${name}`;
                $('#addLocationCode').val(code);
                $('#addDescription').val(`Khu vực ${name}`);
                updatePreview();
            }
        }
        
        // Validate location code
        function validateLocationCode(code) {
            const feedback = $('#locationCodeFeedback');
            
            if (!code) {
                feedback.html('');
                return;
            }
            
            // Check format
            const patterns = {
                rack: /^[A-Z-]+-K\d+-T\d+-P\d+$/,
                size: /^SIZE-\d+-\d+$/,
                zone: /^ZONE-[A-Z-]+$/
            };
            
            let isValid = false;
            let message = '';
            
            if (patterns.rack.test(code)) {
                isValid = true;
                message = '<i class="fas fa-check-circle text-success"></i> Mã vị trí kho hợp lệ (Khu vực-Kệ-Tầng-Vị trí)';
            } else if (patterns.size.test(code)) {
                isValid = true;
                message = '<i class="fas fa-check-circle text-success"></i> Mã tủ chứa size hợp lệ';
            } else if (patterns.zone.test(code)) {
                isValid = true;
                message = '<i class="fas fa-check-circle text-success"></i> Mã khu vực hợp lệ';
            } else {
                message = '<i class="fas fa-info-circle text-info"></i> Mã tùy chỉnh';
            }
            
            feedback.html(`<small class="form-text">${message}</small>`);
            updatePreview();
        }
        
        // Suggest next location code
        function suggestNextLocationCode() {
            const warehouseId = $('#addWarehouse').val();
                
            if (!warehouseId) {
                showAlert('warning', 'Không xác định được kho');
                return;
            }
            
            // Get existing locations for this warehouse
            const existingCodes = filteredLocations
                .filter(loc => loc.warehouse_id == warehouseId)
                .map(loc => loc.location_code);
            
            // Suggest next code based on pattern
            let suggested = '';
            const type = $('#locationType').val();
            
            if (type === 'rack') {
                // Find next rack position
                const rackPattern = /^([A-Z-]+)-K(\d+)-T(\d+)-P(\d+)$/;
                const racks = existingCodes.filter(code => rackPattern.test(code));
                
                if (racks.length > 0) {
                    const lastRack = racks.sort().pop();
                    const match = lastRack.match(rackPattern);
                    if (match) {
                        let [, zone, rackNum, floor, pos] = match;
                        pos = String(parseInt(pos) + 1);
                        if (parseInt(pos) > 20) {
                            pos = '1';
                            floor = String(parseInt(floor) + 1);
                        }
                        suggested = `${zone}-K${rackNum}-T${floor}-P${pos}`;
                        $('#rackZone').val(zone);
                        $('#rackLetter').val(rackNum);
                        $('#rackFloor').val(floor);
                        $('#rackPosition').val(pos);
                    }
                } else {
                    suggested = 'SNEAKER-K1-T1-P1';
                    $('#rackZone').val('SNEAKER');
                    $('#rackLetter').val('1');
                    $('#rackFloor').val('1');
                    $('#rackPosition').val('1');
                }
                
                generateRackCode();
                
            } else if (type === 'size') {
                showAlert('info', 'Vui lòng nhập range size mong muốn');
            }
            
            if (suggested) {
                showAlert('success', `Gợi ý: ${suggested}`);
            }
        }
        
        // Update preview
        function updatePreview() {
            const code = $('#addLocationCode').val();
            const desc = $('#addDescription').val();
            const warehouse = $('#addWarehouseDisplay').val();
            const activeCheckbox = document.getElementById('addIsActive');
            const active = activeCheckbox ? activeCheckbox.checked : true;
            
            if (code) {
                const preview = `
                    <table class="table table-sm table-bordered mb-0">
                        <tr><th width="30%">Kho:</th><td>${warehouse}</td></tr>
                        <tr><th>Mã vị trí:</th><td><strong class="text-primary">${code}</strong></td></tr>
                        <tr><th>Mô tả:</th><td>${desc || '<em class="text-muted">Chưa có</em>'}</td></tr>
                        <tr><th>Trạng thái:</th><td>${active ? '<span class="badge badge-success">Hoạt động</span>' : '<span class="badge badge-secondary">Không hoạt động</span>'}</td></tr>
                    </table>
                `;
                $('#previewContent').html(preview);
                $('#locationPreview').show();
            } else {
                $('#locationPreview').hide();
            }
        }
        
        // Lưu location mới
        function saveLocation() {
            const form = $('#addLocationForm');
            
            if (!form[0].checkValidity()) {
                form[0].reportValidity();
                return;
            }
            
            // Lấy warehouse_id từ hidden field
            const warehouseId = $('#addWarehouse').val();
            
            if (!warehouseId) {
                Swal.fire('Lỗi', 'Không xác định được kho. Vui lòng thử lại.', 'error');
                return;
            }
            
            const activeCheckbox = document.getElementById('addIsActive');
            const data = {
                warehouse_id: warehouseId,
                location_code: $('#addLocationCode').val().trim().toUpperCase(),
                type: $('#addLocationType').val() || null,
                description: $('#addDescription').val().trim(),
                is_active: (activeCheckbox && activeCheckbox.checked) ? 1 : 0
            };
            
            $.ajax({
                url: 'api_vi_tri_kho.php?action=create',
                type: 'POST',
                contentType: 'application/json',
                data: JSON.stringify(data),
                success: function(response) {
                    if (response.success) {
                        Swal.fire('Thành công!', response.message, 'success');
                        $('#addLocationModal').modal('hide');
                        form[0].reset();
                        $('#locationPreview').hide();
                        $('#locationCodeFeedback').html('');
                        $('.location-type-inputs').hide();
                        $('#rackInputs').show();
                        loadLocations();
                    } else {
                        // Hiển thị lỗi chi tiết nếu là lỗi trùng lặp
                        let errorMsg = response.message;
                        if (errorMsg.includes('tồn tại') || errorMsg.includes('trùng')) {
                            errorMsg = `<i class="fas fa-exclamation-triangle"></i> ${errorMsg}<br><small class="text-muted">Vui lòng kiểm tra lại mã vị trí hoặc thay đổi thông tin.</small>`;
                        }
                        Swal.fire({
                            title: 'Không thể tạo vị trí',
                            html: errorMsg,
                            icon: 'error',
                            confirmButtonText: 'OK'
                        });
                    }
                },
                error: function(xhr) {
                    console.error('Error:', xhr);
                    Swal.fire('Lỗi', 'Lỗi khi lưu dữ liệu', 'error');
                }
            });
        }

        // Edit location
        function editLocation(locationId) {
            $.ajax({
                url: 'api_vi_tri_kho.php?action=detail&location_id=' + locationId,
                type: 'GET',
                success: function(response) {
                    if (response.success) {
                        const loc = response.data;
                        
                        // Kiểm tra quyền: Manager chỉ được sửa vị trí của kho mình
                        if (userRole === 'manager' && userWarehouseId && loc.warehouse_id != userWarehouseId) {
                            showAlert('error', 'Bạn không có quyền chỉnh sửa vị trí của kho khác');
                            return;
                        }
                        
                        $('#editLocationId').val(loc.location_id);
                        $('#editWarehouseId').val(loc.warehouse_id);
                        $('#editWarehouseName').val(loc.warehouse_name);
                        $('#editLocationCode').val(loc.location_code);
                        $('#editDescription').val(loc.description || '');
                        $('#editIsActive').prop('checked', loc.is_active == 1);
                        $('#editLocationModal').modal('show');
                    } else {
                        showAlert('error', response.message);
                    }
                },
                error: function(xhr) {
                    console.error('Error:', xhr);
                    showAlert('error', 'Không thể tải thông tin vị trí');
                }
            });
        }

        // Update location
        function updateLocation() {
            const form = $('#editLocationForm');
            
            if (!form[0].checkValidity()) {
                form[0].reportValidity();
                return;
            }
            
            const data = {
                location_id: $('#editLocationId').val(),
                warehouse_id: $('#editWarehouseId').val(),
                location_code: $('#editLocationCode').val().trim(),
                description: $('#editDescription').val().trim(),
                is_active: $('#editIsActive').is(':checked') ? 1 : 0
            };
            
            $.ajax({
                url: 'api_vi_tri_kho.php?action=update',
                type: 'PUT',
                contentType: 'application/json',
                data: JSON.stringify(data),
                success: function(response) {
                    if (response.success) {
                        showAlert('success', response.message);
                        $('#editLocationModal').modal('hide');
                        loadLocations();
                    } else {
                        showAlert('error', response.message);
                    }
                },
                error: function(xhr) {
                    console.error('Error:', xhr);
                    showAlert('error', 'Lỗi khi cập nhật dữ liệu');
                }
            });
        }

        // Delete location
        function deleteLocation(locationId, locationCode, warehouseId) {
            // Kiểm tra quyền: Manager chỉ được xóa vị trí của kho mình
            if (userRole === 'manager' && userWarehouseId && warehouseId != userWarehouseId) {
                showAlert('error', 'Bạn không có quyền xóa vị trí của kho khác');
                return;
            }
            
            if (!confirm(`Bạn có chắc muốn xóa vị trí "${locationCode}"?\n\nLưu ý: Không thể xóa vị trí đang được sử dụng trong phiếu nhập kho.`)) {
                return;
            }
            
            $.ajax({
                url: 'api_vi_tri_kho.php?action=delete&location_id=' + locationId,
                type: 'DELETE',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        showAlert('success', response.message);
                        loadLocations();
                    } else {
                        showAlert('error', response.message);
                    }
                },
                error: function(xhr) {
                    console.error('Error:', xhr);
                    let errorMsg = 'Lỗi khi xóa dữ liệu';
                    
                    // Thử parse JSON response để lấy thông báo lỗi chi tiết
                    try {
                        const response = JSON.parse(xhr.responseText);
                        if (response.message) {
                            errorMsg = response.message;
                        }
                    } catch (e) {
                        console.error('Parse error:', e);
                    }
                    
                    showAlert('error', errorMsg);
                }
            });
        }

        // Bulk add locations
        function bulkAddLocations() {
            const form = $('#bulkAddForm');
            
            if (!form[0].checkValidity()) {
                form[0].reportValidity();
                return;
            }
            
            // Nếu là manager, lấy warehouse_id từ hidden field
            const warehouseId = (userRole === 'manager') 
                ? $('#bulkWarehouseHidden').val() || $('#bulkWarehouse').val()
                : $('#bulkWarehouse').val();
            const locationList = $('#bulkLocationList').val().trim();
            
            if (!locationList) {
                showAlert('warning', 'Vui lòng nhập danh sách vị trí');
                return;
            }
            
            // Parse danh sách
            const lines = locationList.split('\n');
            const locations = [];
            
            for (let i = 0; i < lines.length; i++) {
                const line = lines[i].trim();
                if (!line) continue;
                
                const parts = line.split('|');
                if (parts.length < 1) {
                    showAlert('warning', `Dòng ${i + 1} không đúng định dạng`);
                    return;
                }
                
                locations.push({
                    warehouse_id: warehouseId,
                    location_code: parts[0].trim(),
                    description: parts[1] ? parts[1].trim() : '',
                    is_active: 1
                });
            }
            
            if (locations.length === 0) {
                showAlert('warning', 'Không có vị trí nào để thêm');
                return;
            }
            
            $.ajax({
                url: 'api_vi_tri_kho.php?action=bulk_create',
                type: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ locations: locations }),
                success: function(response) {
                    if (response.success) {
                        const stats = response.stats;
                        let message = `Thêm thành công: ${stats.success}, Thất bại: ${stats.failed}`;
                        
                        if (stats.errors.length > 0) {
                            message += '\n\nLỗi:\n' + stats.errors.join('\n');
                        }
                        
                        showAlert(stats.failed > 0 ? 'warning' : 'success', message);
                        $('#bulkAddModal').modal('hide');
                        form[0].reset();
                        loadLocations();
                    } else {
                        showAlert('error', response.message);
                    }
                },
                error: function(xhr) {
                    console.error('Error:', xhr);
                    showAlert('error', 'Lỗi khi thêm hàng loạt');
                }
            });
        }

        // Reset filters
        function resetFilters() {
            $('#filterWarehouse').val('');
            $('#searchInput').val('');
            $('#filterStatus').val('');
            $('#filterProductType').val('');
            loadLocations();
        }

        // Toggle trạng thái hoạt động (Kích hoạt/Tạm ngưng)
        function toggleStatus(locationId, isActive, locationCode) {
            const action = isActive ? 'kích hoạt lại' : 'tạm ngưng';
            const message = isActive 
                ? `Kích hoạt lại vị trí "${locationCode}"?\n\nVị trí này sẽ được gợi ý bình thường khi nhập kho.`
                : `Tạm ngưng vị trí "${locationCode}"?\n\nVị trí này sẽ không được gợi ý khi nhập kho sản phẩm mới.`;
            
            Swal.fire({
                title: 'Xác nhận',
                text: message,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: isActive ? 'Kích hoạt' : 'Tạm ngưng',
                cancelButtonText: 'Hủy',
                confirmButtonColor: isActive ? '#1cc88a' : '#f6c23e'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.ajax({
                        url: 'api_vi_tri_kho.php?action=toggle_status',
                        type: 'PUT',
                        contentType: 'application/json',
                        data: JSON.stringify({
                            location_id: locationId,
                            is_active: isActive
                        }),
                        success: function(response) {
                            if (response.success) {
                                Swal.fire({
                                    title: 'Thành công!',
                                    text: response.message,
                                    icon: 'success',
                                    timer: 2000,
                                    showConfirmButton: false
                                });
                                loadLocations();
                            } else {
                                Swal.fire('Lỗi', response.message, 'error');
                            }
                        },
                        error: function(xhr) {
                            console.error('Error:', xhr);
                            let errorMsg = 'Lỗi khi cập nhật trạng thái';
                            try {
                                const response = JSON.parse(xhr.responseText);
                                if (response.message) {
                                    errorMsg = response.message;
                                }
                            } catch (e) {
                                console.error('Parse error:', e);
                            }
                            Swal.fire('Lỗi', errorMsg, 'error');
                        }
                    });
                }
            });
        }

        // Show alert
        function showAlert(type, message) {
            const alertTypes = {
                'success': 'alert-success',
                'error': 'alert-danger',
                'warning': 'alert-warning',
                'info': 'alert-info'
            };
            
            const alertClass = alertTypes[type] || 'alert-info';
            const icon = type === 'success' ? 'check-circle' : 
                        type === 'error' ? 'exclamation-circle' : 
                        type === 'warning' ? 'exclamation-triangle' : 'info-circle';
            
            const alertHtml = `
                <div class="alert ${alertClass} alert-dismissible fade show" role="alert" style="position: fixed; top: 80px; right: 20px; z-index: 9999; min-width: 300px;">
                    <i class="fas fa-${icon}"></i> ${message}
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
            `;
            
            $('body').append(alertHtml);
            
            setTimeout(function() {
                $('.alert').fadeOut('slow', function() {
                    $(this).remove();
                });
            }, 5000);
        }
    </script>

</body>

</html>
