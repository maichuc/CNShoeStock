<?php
// Standard menu component based on trang_chu.php
function renderStandardSidebar($currentPage = '') {
    session_start();
    require_once 'config/cau_hinh_csdl.php';
    require_once 'classes/Kho.php';
    
    $database = new Database();
    $pdo = $database->getConnection();
    $userWarehouseId = $_SESSION['warehouse_id'] ?? null;
    $warehouseName = 'Smart Warehouse';
    
    if ($userWarehouseId) {
        $warehouseObj = new Warehouse($pdo);
        if ($warehouseObj->getById($userWarehouseId)) {
            $warehouseName = $warehouseObj->name;
        }
    }
    
    // Determine active nav item
    $activeClass = function($page) use ($currentPage) {
        return ($currentPage == $page) ? 'active' : '';
    };
    
    // Determine if a collapse menu should be open
    $collapseShow = function($pages) use ($currentPage) {
        return in_array($currentPage, $pages) ? 'show' : '';
    };
    
    return '
        <!-- Sidebar -->
        <ul class="navbar-nav bg-gradient-primary sidebar sidebar-dark accordion" id="accordionSidebar">

            <!-- Sidebar - Brand -->
            <a class="sidebar-brand d-flex align-items-center justify-content-center" href="trang_chu.php">
                <div class="sidebar-brand-icon rotate-n-15">
                    <i class="fas fa-laugh-wink"></i>
                </div>
                <div class="sidebar-brand-text mx-3">' . htmlspecialchars($warehouseName) . '</div>
            </a>

            <!-- Divider -->
            <hr class="sidebar-divider my-0">

            <!-- Nav Item - Dashboard -->
            <li class="nav-item ' . $activeClass('index') . '">
                <a class="nav-link" href="trang_chu.php">
                    <i class="fas fa-fw fa-tachometer-alt"></i>
                    <span>Dashboard</span></a>
            </li>

            <!-- Divider -->
            <hr class="sidebar-divider">

            <!-- Heading -->
            <div class="sidebar-heading">
                Sản phẩm
            </div>

            <!-- Nav Item - Quản lý sản phẩm -->
            <li class="nav-item">
                <a class="nav-link collapsed" href="#" data-toggle="collapse" data-target="#collapseProducts"
                    aria-expanded="true" aria-controls="collapseProducts">
                    <i class="fas fa-fw fa-box"></i>
                    <span>Quản lý sản phẩm</span>
                </a>
                <div id="collapseProducts" class="collapse ' . $collapseShow(['add_product_ai', 'danh_sach_sp']) . '" aria-labelledby="headingProducts" data-parent="#accordionSidebar">
                    <div class="bg-white py-2 collapse-inner rounded">
                        <a class="collapse-item ' . ($currentPage == 'add_product_ai' ? 'active' : '') . '" href="them_san_pham_ai.php">
                            <i class="fas fa-plus-circle mr-1"></i> Thêm sản phẩm
                        </a>
                        <a class="collapse-item ' . ($currentPage == 'danh_sach_sp' ? 'active' : '') . '" href="danh_sach_san_pham.php">
                            <i class="fas fa-list mr-1"></i> Danh sách sản phẩm
                        </a>
                    </div>
                </div>
            </li>

            <!-- Divider -->
            <hr class="sidebar-divider">

            <!-- Heading -->
            <div class="sidebar-heading">
                Kho hàng
            </div>

            <!-- Nav Item - Nhập kho -->
            <li class="nav-item">
                <a class="nav-link collapsed" href="#" data-toggle="collapse" data-target="#collapseReceipts"
                    aria-expanded="false" aria-controls="collapseReceipts">
                    <i class="fas fa-fw fa-dolly"></i>
                    <span>Nhập kho</span>
                </a>
                <div id="collapseReceipts" class="collapse ' . $collapseShow(['create_stock_receipt', 'stock_receipts_management']) . '" aria-labelledby="headingReceipts" data-parent="#accordionSidebar">
                    <div class="bg-white py-2 collapse-inner rounded">
                        <a class="collapse-item ' . ($currentPage == 'create_stock_receipt' ? 'active' : '') . '" href="create_stock_receipt.php">
                            <i class="fas fa-file-import mr-1"></i> Tạo phiếu nhập
                        </a>
                        <a class="collapse-item ' . ($currentPage == 'stock_receipts_management' ? 'active' : '') . '" href="quan_ly_phieu_nhap_kho.php">
                            <i class="fas fa-clipboard-list mr-1"></i> Quản lý phiếu nhập
                        </a>
                    </div>
                </div>
            </li>

            <!-- Nav Item - Xuất kho -->
            <li class="nav-item">
                <a class="nav-link collapsed" href="#" data-toggle="collapse" data-target="#collapseExports"
                    aria-expanded="false" aria-controls="collapseExports">
                    <i class="fas fa-fw fa-truck-loading"></i>
                    <span>Xuất kho</span>
                </a>
                <div id="collapseExports" class="collapse ' . $collapseShow(['export_management']) . '" aria-labelledby="headingExports" data-parent="#accordionSidebar">
                    <div class="bg-white py-2 collapse-inner rounded">
                        <a class="collapse-item ' . ($currentPage == 'export_management' ? 'active' : '') . '" href="quan_ly_xuat_kho.php">
                            <i class="fas fa-qrcode mr-1"></i> Xử lý đơn xuất kho
                        </a>
                    </div>
                </div>
            </li>

            <!-- Nav Item - Tồn kho -->
            <li class="nav-item ' . $activeClass('inventory') . '">
                <a class="nav-link" href="inventory.php">
                    <i class="fas fa-fw fa-boxes"></i>
                    <span>Tồn kho</span>
                </a>
            </li>

            <!-- Divider -->
            <hr class="sidebar-divider">

            <!-- Heading -->
            <div class="sidebar-heading">
                Đơn hàng
            </div>

            <!-- Nav Item - Quản lý đơn hàng -->
            <li class="nav-item">
                <a class="nav-link collapsed" href="#" data-toggle="collapse" data-target="#collapseOrders"
                    aria-expanded="false" aria-controls="collapseOrders">
                    <i class="fas fa-fw fa-shopping-cart"></i>
                    <span>Quản lý đơn hàng</span>
                </a>
                <div id="collapseOrders" class="collapse ' . $collapseShow(['create_order', 'orders_management']) . '" aria-labelledby="headingOrders" data-parent="#accordionSidebar">
                    <div class="bg-white py-2 collapse-inner rounded">
                        <a class="collapse-item ' . ($currentPage == 'create_order' ? 'active' : '') . '" href="tao_don_hang.php">
                            <i class="fas fa-plus-circle mr-1"></i> Tạo đơn hàng
                        </a>
                        <a class="collapse-item ' . ($currentPage == 'orders_management' ? 'active' : '') . '" href="quan_ly_don_hang.php">
                            <i class="fas fa-list mr-1"></i> Danh sách đơn hàng
                        </a>
                    </div>
                </div>
            </li>

            <!-- Divider -->
            <hr class="sidebar-divider">

            <!-- Divider -->
            <hr class="sidebar-divider d-none d-md-block">

            <!-- Sidebar Toggler (Sidebar) -->
            <div class="text-center d-none d-md-inline">
                <button class="rounded-circle border-0" id="sidebarToggle"></button>
            </div>

        </ul>
        <!-- End of Sidebar -->';
}

function renderStandardTopbar() {
    return '
                <!-- Topbar -->
                <nav class="navbar navbar-expand navbar-light bg-white topbar mb-4 static-top shadow">

                    <!-- Sidebar Toggle (Topbar) -->
                    <button id="sidebarToggleTop" class="btn btn-link d-md-none rounded-circle mr-3">
                        <i class="fa fa-bars"></i>
                    </button>

                    <!-- Topbar Search -->
                    <form class="d-none d-sm-inline-block form-inline mr-auto ml-md-3 my-2 my-md-0 mw-100 navbar-search">
                        <div class="input-group">
                            <input type="text" class="form-control bg-light border-0 small" placeholder="Search for..."
                                aria-label="Search" aria-describedby="basic-addon2">
                            <div class="input-group-append">
                                <button class="btn btn-primary" type="button">
                                    <i class="fas fa-search fa-sm"></i>
                                </button>
                            </div>
                        </div>
                    </form>

                    <!-- Topbar Navbar -->
                    <ul class="navbar-nav ml-auto">

                        <!-- Nav Item - Search Dropdown (Visible Only XS) -->
                        <li class="nav-item dropdown no-arrow d-sm-none">
                            <a class="nav-link dropdown-toggle" href="#" id="searchDropdown" role="button"
                                data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <i class="fas fa-search fa-fw"></i>
                            </a>
                            <div class="dropdown-menu dropdown-menu-right p-3 shadow animated--grow-in"
                                aria-labelledby="searchDropdown">
                                <form class="form-inline mr-auto w-100 navbar-search">
                                    <div class="input-group">
                                        <input type="text" class="form-control bg-light border-0 small"
                                            placeholder="Search for..." aria-label="Search"
                                            aria-describedby="basic-addon2">
                                        <div class="input-group-append">
                                            <button class="btn btn-primary" type="button">
                                                <i class="fas fa-search fa-sm"></i>
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </li>

                        <div class="topbar-divider d-none d-sm-block"></div>

                        <!-- Nav Item - User Information -->
                        <li class="nav-item dropdown no-arrow">
                            <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button"
                                data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <span class="mr-2 d-none d-lg-inline text-gray-600 small">' . ($_SESSION['username'] ?? 'User') . '</span>
                                <img class="img-profile rounded-circle" src="img/undraw_profile.svg">
                            </a>
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
                <!-- End of Topbar -->';
}

function renderLogoutModal() {
    return '
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
                    <a class="btn btn-primary" href="auth/dang_xuat.php">Đăng xuất</a>
                </div>
            </div>
        </div>
    </div>';
}
?>