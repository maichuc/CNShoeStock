<?php
// Lấy warehouse information
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../app/Classes/Kho.php';
if (!isset($_SESSION)) {
    // session_start(); - Đã được khởi tạo tại public/index.php
}
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

// Lấy current page name for active menu
$currentPage = $_GET['route'] ?? 'trang_chu.php';
?>

<!-- Sidebar -->
<ul class="navbar-nav bg-gradient-primary sidebar sidebar-dark accordion" id="accordionSidebar">

    <!-- Sidebar - Brand -->
    <a class="sidebar-brand d-flex align-items-center justify-content-center" href="trang_chu.php">
        <div class="sidebar-brand-icon rotate-n-15">
            <i class="fas fa-home"></i>
        </div>
        <div class="sidebar-brand-text mx-3"><?php echo htmlspecialchars($warehouseName); ?></div>
    </a>

    <!-- Divider -->
    <hr class="sidebar-divider my-0">

    <!-- Nav Item - Dashboard -->
    <li class="nav-item <?php echo ($currentPage == 'trang_chu.php') ? 'active' : ''; ?>">
        <a class="nav-link" href="trang_chu.php">
            <i class="fas fa-fw fa-tachometer-alt"></i>
            <span>Dashboard</span>
        </a>
    </li>

    <!-- Divider -->
    <hr class="sidebar-divider">

    <!-- Heading -->
    <div class="sidebar-heading">Quản lý kho</div>

    <!-- Nav Item - Quản lý sản phẩm -->
    <li class="nav-item">
        <a class="nav-link collapsed" href="#" data-toggle="collapse" data-target="#collapseProducts" 
           aria-expanded="<?php echo (in_array($currentPage, ['them_san_pham_ai.php', 'danh_sach_san_pham.php'])) ? 'true' : 'false'; ?>" 
           aria-controls="collapseProducts">
            <i class="fas fa-fw fa-box"></i>
            <span>Quản lý sản phẩm</span>
        </a>
        <div id="collapseProducts" class="collapse <?php echo (in_array($currentPage, ['them_san_pham_ai.php', 'danh_sach_san_pham.php'])) ? 'show' : ''; ?>" 
             aria-labelledby="headingProducts" data-parent="#accordionSidebar">
            <div class="bg-white py-2 collapse-inner rounded">
                <a class="collapse-item <?php echo ($currentPage == 'them_san_pham_ai.php') ? 'active' : ''; ?>" href="them_san_pham_ai.php">
                    <i class="fas fa-plus-circle mr-1"></i> Thêm sản phẩm
                </a>
                <a class="collapse-item <?php echo ($currentPage == 'danh_sach_san_pham.php') ? 'active' : ''; ?>" href="danh_sach_san_pham.php">
                    <i class="fas fa-list mr-1"></i> Danh sách sản phẩm
                </a>
            </div>
        </div>
    </li>

    <!-- Nav Item - Nhập kho -->
    <li class="nav-item">
        <a class="nav-link collapsed" href="#" data-toggle="collapse" data-target="#collapseReceipts" 
           aria-expanded="<?php echo (in_array($currentPage, ['tao_phieu_nhap_moi.php', 'tao_phieu_nhap_thu_cong.php', 'quan_ly_phieu_nhap_kho.php'])) ? 'true' : 'false'; ?>" 
           aria-controls="collapseReceipts">
            <i class="fas fa-fw fa-dolly"></i>
            <span>Nhập kho</span>
        </a>
        <div id="collapseReceipts" class="collapse <?php echo (in_array($currentPage, ['tao_phieu_nhap_moi.php', 'tao_phieu_nhap_thu_cong.php', 'quan_ly_phieu_nhap_kho.php'])) ? 'show' : ''; ?>" 
             aria-labelledby="headingReceipts" data-parent="#accordionSidebar">
            <div class="bg-white py-2 collapse-inner rounded">
                <a class="collapse-item <?php echo ($currentPage == 'tao_phieu_nhap_moi.php') ? 'active' : ''; ?>" href="tao_phieu_nhap_moi.php">
                    <i class="fas fa-plus-circle mr-1"></i> Nhập kho AI
                </a>
                <a class="collapse-item <?php echo ($currentPage == 'tao_phieu_nhap_thu_cong.php') ? 'active' : ''; ?>" href="tao_phieu_nhap_thu_cong.php">
                    <i class="fas fa-keyboard mr-1"></i> Nhập kho thủ công
                </a>
                <a class="collapse-item <?php echo ($currentPage == 'quan_ly_phieu_nhap_kho.php') ? 'active' : ''; ?>" href="quan_ly_phieu_nhap_kho.php">
                    <i class="fas fa-clipboard-list mr-1"></i> Quản lý phiếu nhập
                </a>
            </div>
        </div>
    </li>

    <!-- Nav Item - Quản lý đơn hàng -->
    <li class="nav-item">
        <a class="nav-link collapsed" href="#" data-toggle="collapse" data-target="#collapseOrders" 
           aria-expanded="<?php echo (in_array($currentPage, ['tao_don_hang.php', 'quan_ly_don_hang.php', 'cap_nhat_trang_thai_giao_hang.php'])) ? 'true' : 'false'; ?>" 
           aria-controls="collapseOrders">
            <i class="fas fa-fw fa-shopping-cart"></i>
            <span>Quản lý đơn hàng</span>
        </a>
        <div id="collapseOrders" class="collapse <?php echo (in_array($currentPage, ['tao_don_hang.php', 'quan_ly_don_hang.php', 'cap_nhat_trang_thai_giao_hang.php'])) ? 'show' : ''; ?>" 
             aria-labelledby="headingOrders" data-parent="#accordionSidebar">
            <div class="bg-white py-2 collapse-inner rounded">
                <a class="collapse-item <?php echo ($currentPage == 'tao_don_hang.php') ? 'active' : ''; ?>" href="tao_don_hang.php">
                    <i class="fas fa-plus-circle mr-1"></i> Tạo đơn hàng
                </a>
                <a class="collapse-item <?php echo ($currentPage == 'quan_ly_don_hang.php') ? 'active' : ''; ?>" href="quan_ly_don_hang.php">
                    <i class="fas fa-list mr-1"></i> Danh sách đơn hàng
                </a>
                <a class="collapse-item <?php echo ($currentPage == 'cap_nhat_trang_thai_giao_hang.php') ? 'active' : ''; ?>" href="cap_nhat_trang_thai_giao_hang.php">
                    <i class="fas fa-shipping-fast mr-1"></i> Cập nhật giao hàng
                </a>
            </div>
        </div>
    </li>

    <!-- Nav Item - Xuất kho -->
    <li class="nav-item">
        <a class="nav-link collapsed" href="#" data-toggle="collapse" data-target="#collapseExports" 
           aria-expanded="<?php echo (in_array($currentPage, ['quan_ly_xuat_kho.php', 'xu_ly_xuat_kho.php', 'xem_phieu_xuat.php', 'xac_nhan_giao_hang.php', 'cac_phieu_xuat_da_xac_nhan.php'])) ? 'true' : 'false'; ?>" 
           aria-controls="collapseExports">
            <i class="fas fa-fw fa-truck-loading"></i>
            <span>Xuất kho</span>
        </a>
        <div id="collapseExports" class="collapse <?php echo (in_array($currentPage, ['quan_ly_xuat_kho.php', 'xu_ly_xuat_kho.php', 'xem_phieu_xuat.php', 'xac_nhan_giao_hang.php', 'cac_phieu_xuat_da_xac_nhan.php'])) ? 'show' : ''; ?>" 
             aria-labelledby="headingExports" data-parent="#accordionSidebar">
            <div class="bg-white py-2 collapse-inner rounded">
                <a class="collapse-item <?php echo (in_array($currentPage, ['quan_ly_xuat_kho.php', 'xu_ly_xuat_kho.php', 'xem_phieu_xuat.php'])) ? 'active' : ''; ?>" href="quan_ly_xuat_kho.php">
                    <i class="fas fa-qrcode mr-1"></i> Xử lý đơn xuất kho
                </a>
                <a class="collapse-item <?php echo ($currentPage == 'xac_nhan_giao_hang.php') ? 'active' : ''; ?>" href="xac_nhan_giao_hang.php">
                    <i class="fas fa-check-circle mr-1"></i> Xác nhận xuất hàng
                </a>
                <a class="collapse-item <?php echo ($currentPage == 'cac_phieu_xuat_da_xac_nhan.php') ? 'active' : ''; ?>" href="cac_phieu_xuat_da_xac_nhan.php">
                    <i class="fas fa-list-check mr-1"></i> Danh sách phiếu xuất
                </a>
            </div>
        </div>
    </li>

    <!-- Nav Item - Quản lý nhà cung cấp -->
    <li class="nav-item">
        <a class="nav-link collapsed" href="#" data-toggle="collapse" data-target="#collapseSuppliers" 
           aria-expanded="<?php echo (in_array($currentPage, ['quan_ly_nha_cung_cap.php', 'them_nha_cung_cap.php'])) ? 'true' : 'false'; ?>" 
           aria-controls="collapseSuppliers">
            <i class="fas fa-fw fa-building"></i>
            <span>Nhà cung cấp</span>
        </a>
        <div id="collapseSuppliers" class="collapse <?php echo (in_array($currentPage, ['quan_ly_nha_cung_cap.php', 'them_nha_cung_cap.php'])) ? 'show' : ''; ?>" 
             aria-labelledby="headingSuppliers" data-parent="#accordionSidebar">
            <div class="bg-white py-2 collapse-inner rounded">
                <a class="collapse-item <?php echo ($currentPage == 'them_nha_cung_cap.php') ? 'active' : ''; ?>" href="them_nha_cung_cap.php">
                    <i class="fas fa-plus-circle mr-1"></i> Thêm nhà cung cấp
                </a>
                <a class="collapse-item <?php echo ($currentPage == 'quan_ly_nha_cung_cap.php') ? 'active' : ''; ?>" href="quan_ly_nha_cung_cap.php">
                    <i class="fas fa-list mr-1"></i> Danh sách NCC
                </a>
            </div>
        </div>
    </li>

    <!-- Divider -->
    <hr class="sidebar-divider">

    <!-- Heading -->
    <div class="sidebar-heading">Hệ thống</div>

    <?php
    // Lấy role của user
    $userRole = $_SESSION['role'] ?? 'staff';
    
    // Chỉ hiển thị menu Quản lý nhân viên cho Manager và Admin
    if (in_array($userRole, ['admin', 'manager'])):
    ?>
    <!-- Nav Item - Quản lý nhân viên -->
    <li class="nav-item <?php echo ($currentPage == 'quan_ly_nhan_vien.php') ? 'active' : ''; ?>">
        <a class="nav-link" href="quan_ly_nhan_vien.php">
            <i class="fas fa-fw fa-users"></i>
            <span>Quản lý nhân viên</span>
        </a>
    </li>
    <?php endif; ?>

    <!-- Nav Item - Quản lý kho hàng (Warehouse Management) -->
    <?php if (in_array($userRole, ['admin', 'manager'])): ?>
    <li class="nav-item">
        <a class="nav-link collapsed" href="#" data-toggle="collapse" data-target="#collapseWarehouse" 
           aria-expanded="<?php echo (in_array($currentPage, ['quan_ly_kho.php', 'vi_tri_kho.php'])) ? 'true' : 'false'; ?>" 
           aria-controls="collapseWarehouse">
            <i class="fas fa-fw fa-warehouse"></i>
            <span>Quản lý kho hàng</span>
        </a>
        <div id="collapseWarehouse" class="collapse <?php echo (in_array($currentPage, ['quan_ly_kho.php', 'vi_tri_kho.php'])) ? 'show' : ''; ?>" 
             aria-labelledby="headingWarehouse" data-parent="#accordionSidebar">
            <div class="bg-white py-2 collapse-inner rounded">
                <?php if ($userRole === 'admin'): ?>
                <a class="collapse-item <?php echo ($currentPage == 'quan_ly_kho.php') ? 'active' : ''; ?>" href="quan_ly_kho.php">
                    <i class="fas fa-warehouse mr-1"></i> Danh sách kho
                </a>
                <?php endif; ?>
                <a class="collapse-item <?php echo ($currentPage == 'vi_tri_kho.php') ? 'active' : ''; ?>" href="vi_tri_kho.php">
                    <i class="fas fa-map-marked-alt mr-1"></i> Vị trí kho
                </a>
            </div>
        </div>
    </li>
    <?php endif; ?>

    <!-- Nav Item - Dự báo thông minh -->
    <li class="nav-item <?php echo ($currentPage == 'du_bao_ai.php') ? 'active' : ''; ?>">
        <a class="nav-link" href="du_bao_ai.php">
            <i class="fas fa-fw fa-brain"></i>
            <span>Dự báo thông minh</span>
        </a>
    </li>

    <!-- Divider -->
    <?php
    /*
    ======================================================================
    ADDONS MENU - HIDDEN (Kept for future use, especially forgot-password)
    ======================================================================
    These menus are commented out but files still exist:
    - forgot-password.html - Kept for future password recovery feature
    - Other utility pages (utilities-color.html, utilities-border.html, etc.)
    - Charts and Tables pages
    
    To re-enable, uncomment the section below.
    ======================================================================
    
    <!-- Heading -->
    <div class="sidebar-heading">Addons</div>

    <!-- Nav Item - Utilities Collapse Menu -->
    <li class="nav-item">
        <a class="nav-link collapsed" href="#" data-toggle="collapse" data-target="#collapseUtilities"
            aria-expanded="true" aria-controls="collapseUtilities">
            <i class="fas fa-fw fa-wrench"></i>
            <span>Utilities</span>
        </a>
        <div id="collapseUtilities" class="collapse" aria-labelledby="headingUtilities"
            data-parent="#accordionSidebar">
            <div class="bg-white py-2 collapse-inner rounded">
                <h6 class="collapse-header">Custom Utilities:</h6>
                <a class="collapse-item" href="utilities-color.html">Colors</a>
                <a class="collapse-item" href="utilities-border.html">Borders</a>
                <a class="collapse-item" href="utilities-animation.html">Animations</a>
                <a class="collapse-item" href="utilities-other.html">Other</a>
            </div>
        </div>
    </li>

    <!-- Nav Item - Pages Collapse Menu -->
    <li class="nav-item">
        <a class="nav-link collapsed" href="#" data-toggle="collapse" data-target="#collapsePages"
            aria-expanded="true" aria-controls="collapsePages">
            <i class="fas fa-fw fa-folder"></i>
            <span>Pages</span>
        </a>
        <div id="collapsePages" class="collapse" aria-labelledby="headingPages" data-parent="#accordionSidebar">
            <div class="bg-white py-2 collapse-inner rounded">
                <h6 class="collapse-header">Login Screens:</h6>
                <a class="collapse-item" href="login.html">Login</a>
                <a class="collapse-item" href="register.html">Register</a>
                <a class="collapse-item" href="forgot-password.html">Forgot Password</a>
                <div class="collapse-divider"></div>
                <h6 class="collapse-header">Other Pages:</h6>
                <a class="collapse-item" href="404.html">404 Page</a>
                <a class="collapse-item" href="blank.html">Blank Page</a>
            </div>
        </div>
    </li>

    <!-- Nav Item - Charts -->
    <li class="nav-item">
        <a class="nav-link" href="charts.html">
            <i class="fas fa-fw fa-chart-area"></i>
            <span>Charts</span></a>
    </li>

    <!-- Nav Item - Tables -->
    <li class="nav-item">
        <a class="nav-link" href="tables.html">
            <i class="fas fa-fw fa-table"></i>
            <span>Tables</span></a>
    </li>
    
    ======================================================================
    END OF HIDDEN ADDONS MENU
    ======================================================================
    */
    ?>

    <!-- Divider -->
    <hr class="sidebar-divider d-none d-md-block">

    <!-- Sidebar Toggler (Sidebar) -->
    <div class="text-center d-none d-md-inline">
        <button class="rounded-circle border-0" id="sidebarToggle"></button>
    </div>

</ul>
<!-- End of Sidebar -->
