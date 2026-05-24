<?php
// session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../app/Classes/KiemSoatTruyCapKho.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    header('Location: login.html');
    exit();
}

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

// Lấy thông tin warehouse và manager
$stmt = $pdo->prepare("
    SELECT w.*, u.full_name as manager_name, u.phone as manager_phone, u.email as manager_email
    FROM warehouses w
    LEFT JOIN users u ON w.warehouse_id = u.warehouse_id AND u.role = 'manager'
    WHERE w.warehouse_id = ?
    LIMIT 1
");
$stmt->execute([$userWarehouseId]);
$warehouse = $stmt->fetch();

// Lấy dữ liệu categories và suppliers
$categories = $warehouseControl->getWarehouseCategories($userWarehouseId);
$suppliers = $warehouseControl->getWarehouseSuppliers($userWarehouseId);

// Xử lý các thao tác CRUD
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'create_category':
                $categoryData = [
                    'name' => $_POST['category_name'],
                    'description' => $_POST['category_description']
                ];
                $warehouseControl->createCategory($categoryData, $currentUser);
                $message = 'Tạo category thành công!';
                $messageType = 'success';
                break;
                
            case 'create_supplier':
                $supplierData = [
                    'name' => $_POST['supplier_name'],
                    'contact_name' => $_POST['supplier_contact_name'],
                    'phone' => $_POST['supplier_phone'],
                    'email' => $_POST['supplier_email'],
                    'address' => $_POST['supplier_address'],
                    'contact_info' => $_POST['supplier_contact_info']
                ];
                $warehouseControl->createSupplier($supplierData, $currentUser);
                $message = 'Tạo supplier thành công!';
                $messageType = 'success';
                break;
                
            case 'update_category':
                $categoryData = [
                    'name' => $_POST['category_name'],
                    'description' => $_POST['category_description']
                ];
                $warehouseControl->updateCategory($_POST['category_id'], $categoryData, $currentUser);
                $message = 'Cập nhật category thành công!';
                $messageType = 'success';
                break;
                
            case 'update_supplier':
                $supplierData = [
                    'name' => $_POST['supplier_name'],
                    'contact_name' => $_POST['supplier_contact_name'],
                    'phone' => $_POST['supplier_phone'],
                    'email' => $_POST['supplier_email'],
                    'address' => $_POST['supplier_address'],
                    'contact_info' => $_POST['supplier_contact_info']
                ];
                $warehouseControl->updateSupplier($_POST['supplier_id'], $supplierData, $currentUser);
                $message = 'Cập nhật supplier thành công!';
                $messageType = 'success';
                break;
                
            case 'delete_category':
                $warehouseControl->deleteCategory($_POST['category_id'], $currentUser);
                $message = 'Xóa category thành công!';
                $messageType = 'success';
                break;
                
            case 'delete_supplier':
                $warehouseControl->deleteSupplier($_POST['supplier_id'], $currentUser);
                $message = 'Xóa supplier thành công!';
                $messageType = 'success';
                break;
        }
        
        // Refresh data sau khi thực hiện thao tác
        $categories = $warehouseControl->getWarehouseCategories($userWarehouseId);
        $suppliers = $warehouseControl->getWarehouseSuppliers($userWarehouseId);
        
    } catch (Exception $e) {
        $message = 'Lỗi: ' . $e->getMessage();
        $messageType = 'danger';
    }
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Quản lý Categories & Suppliers - <?php echo htmlspecialchars($warehouse['name'] ?? 'N/A'); ?></title>

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- FontAwesome -->
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet">
    <!-- Bootstrap -->
    <link href="vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    
    <style>
        :root {
            --primary-color: #4e73df;
            --secondary-color: #858796;
            --success-color: #1cc88a;
            --info-color: #36b9cc;
            --warning-color: #f6c23e;
            --danger-color: #e74a3b;
            --light-color: #f8f9fc;
            --dark-color: #5a5c69;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--light-color);
        }
        
        .warehouse-header {
            background: linear-gradient(135deg, var(--primary-color), #224abe);
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
        }
        
        .warehouse-info {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 15px;
            padding: 1.5rem;
            backdrop-filter: blur(10px);
        }
        
        .management-card {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
            margin-bottom: 2rem;
        }
        
        .item-card {
            background: white;
            border-radius: 10px;
            border: 1px solid #e3e6f0;
            padding: 1rem;
            margin-bottom: 1rem;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        
        .item-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 0.5rem 1rem rgba(58, 59, 69, 0.15);
        }
        
        .isolation-notice {
            background: linear-gradient(135deg, #1cc88a, #17a2b8);
            color: white;
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 2rem;
        }
        
        .btn-create {
            background: linear-gradient(135deg, var(--success-color), #17a673);
            border: none;
            border-radius: 25px;
            padding: 0.5rem 1.5rem;
            color: white;
            font-weight: 600;
        }
        
        .btn-create:hover {
            color: white;
            transform: translateY(-1px);
        }
        
        .stats-badge {
            background: var(--info-color);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 600;
        }
    </style>
</head>

<body>
    <!-- Warehouse Header -->
    <div class="warehouse-header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <div class="warehouse-info">
                        <h1 class="h3 mb-2">
                            <i class="fas fa-cogs mr-3"></i>
                            Quản lý Kho: <?php echo htmlspecialchars($warehouse['name'] ?? 'N/A'); ?>
                        </h1>
                        <p class="mb-1">
                            <i class="fas fa-map-marker-alt mr-2"></i>
                            <?php echo htmlspecialchars($warehouse['address'] ?? 'N/A'); ?>
                        </p>
                        <p class="mb-0">
                            <i class="fas fa-user-tie mr-2"></i>
                            Quản lý: <?php echo htmlspecialchars($warehouse['manager_name'] ?? 'N/A'); ?>
                        </p>
                    </div>
                </div>
                <div class="col-md-4 text-right">
                    <a href="san_pham_trong_kho.php" class="btn btn-light btn-lg mr-2">
                        <i class="fas fa-box mr-2"></i>Quản lý Sản phẩm
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="container-fluid">
        <!-- Isolation Notice -->
        <div class="isolation-notice">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h5 class="mb-1">
                        <i class="fas fa-shield-alt mr-2"></i>
                        Dữ liệu riêng biệt theo Warehouse
                    </h5>
                    <p class="mb-0">
                        Categories và Suppliers được tách biệt hoàn toàn. Mỗi kho hàng có danh mục và nhà cung cấp riêng.
                    </p>
                </div>
                <div class="col-md-4 text-right">
                    <span class="badge badge-light badge-pill px-3 py-2">
                        <i class="fas fa-warehouse mr-1"></i>
                        Kho ID: <?php echo $userWarehouseId; ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Message Alert -->
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?> mr-2"></i>
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="close" data-dismiss="alert">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        <?php endif; ?>

        <div class="row">
            <!-- Categories Management -->
            <div class="col-lg-6">
                <div class="management-card">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h4 class="mb-0">
                            <i class="fas fa-tags text-primary mr-2"></i>
                            Categories 
                            <span class="stats-badge"><?php echo count($categories); ?></span>
                        </h4>
                        <button class="btn btn-create" data-toggle="modal" data-target="#createCategoryModal">
                            <i class="fas fa-plus mr-1"></i>Thêm Category
                        </button>
                    </div>
                    
                    <?php if (empty($categories)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-tags fa-3x text-gray-300 mb-3"></i>
                            <h6 class="text-gray-600">Chưa có categories nào</h6>
                            <p class="text-gray-500">Hãy tạo category đầu tiên cho kho hàng này!</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($categories as $category): ?>
                            <div class="item-card">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div class="flex-grow-1">
                                        <h6 class="mb-1 font-weight-bold"><?php echo htmlspecialchars($category['name']); ?></h6>
                                        <?php if ($category['description']): ?>
                                            <p class="text-muted mb-1 small"><?php echo htmlspecialchars($category['description']); ?></p>
                                        <?php endif; ?>
                                        <small class="text-info">
                                            <i class="fas fa-box mr-1"></i>
                                            <?php echo $category['product_count']; ?> sản phẩm
                                        </small>
                                    </div>
                                    <div class="ml-2">
                                        <button class="btn btn-sm btn-outline-primary mr-1" 
                                                onclick="editCategory(<?php echo $category['category_id']; ?>, '<?php echo htmlspecialchars($category['name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($category['description'], ENT_QUOTES); ?>')">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <?php if ($category['product_count'] == 0): ?>
                                            <button class="btn btn-sm btn-outline-danger"
                                                    onclick="deleteCategory(<?php echo $category['category_id']; ?>, '<?php echo htmlspecialchars($category['name'], ENT_QUOTES); ?>')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        <?php else: ?>
                                            <button class="btn btn-sm btn-outline-secondary" disabled title="Không thể xóa category đang được sử dụng">
                                                <i class="fas fa-lock"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Suppliers Management -->
            <div class="col-lg-6">
                <div class="management-card">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h4 class="mb-0">
                            <i class="fas fa-truck text-warning mr-2"></i>
                            Suppliers 
                            <span class="stats-badge"><?php echo count($suppliers); ?></span>
                        </h4>
                        <button class="btn btn-create" data-toggle="modal" data-target="#createSupplierModal">
                            <i class="fas fa-plus mr-1"></i>Thêm Supplier
                        </button>
                    </div>
                    
                    <?php if (empty($suppliers)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-truck fa-3x text-gray-300 mb-3"></i>
                            <h6 class="text-gray-600">Chưa có suppliers nào</h6>
                            <p class="text-gray-500">Hãy tạo supplier đầu tiên cho kho hàng này!</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($suppliers as $supplier): ?>
                            <div class="item-card">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div class="flex-grow-1">
                                        <h6 class="mb-1 font-weight-bold"><?php echo htmlspecialchars($supplier['name']); ?></h6>
                                        <?php if ($supplier['contact_name']): ?>
                                            <p class="mb-1 small">
                                                <i class="fas fa-user mr-1"></i>
                                                <?php echo htmlspecialchars($supplier['contact_name']); ?>
                                            </p>
                                        <?php endif; ?>
                                        <?php if ($supplier['phone']): ?>
                                            <p class="mb-1 small">
                                                <i class="fas fa-phone mr-1"></i>
                                                <?php echo htmlspecialchars($supplier['phone']); ?>
                                            </p>
                                        <?php endif; ?>
                                        <?php if ($supplier['email']): ?>
                                            <p class="mb-1 small">
                                                <i class="fas fa-envelope mr-1"></i>
                                                <?php echo htmlspecialchars($supplier['email']); ?>
                                            </p>
                                        <?php endif; ?>
                                        <small class="text-info">
                                            <i class="fas fa-receipt mr-1"></i>
                                            <?php echo $supplier['receipt_count']; ?> phiếu nhập
                                        </small>
                                    </div>
                                    <div class="ml-2">
                                        <button class="btn btn-sm btn-outline-primary mr-1" 
                                                onclick="editSupplier(<?php echo htmlspecialchars(json_encode($supplier), ENT_QUOTES); ?>)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <?php if ($supplier['receipt_count'] == 0): ?>
                                            <button class="btn btn-sm btn-outline-danger"
                                                    onclick="deleteSupplier(<?php echo $supplier['supplier_id']; ?>, '<?php echo htmlspecialchars($supplier['name'], ENT_QUOTES); ?>')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        <?php else: ?>
                                            <button class="btn btn-sm btn-outline-secondary" disabled title="Không thể xóa supplier đang được sử dụng">
                                                <i class="fas fa-lock"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Modals -->
    <?php include __DIR__ . '/modals/modal_danh_muc.php'; ?>
    <?php include __DIR__ . '/modals/modal_nha_cung_cap.php'; ?>

    <!-- Scripts -->
    <script src="vendor/jquery/jquery.min.js"></script>
    <script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        function editCategory(id, name, description) {
            $('#editCategoryId').val(id);
            $('#editCategoryName').val(name);
            $('#editCategoryDescription').val(description);
            $('#editCategoryModal').modal('show');
        }
        
        function deleteCategory(id, name) {
            Swal.fire({
                title: 'Xác nhận xóa',
                text: `Bạn có chắc muốn xóa category "${name}"?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Xóa',
                cancelButtonText: 'Hủy'
            }).then((result) => {
                if (result.isConfirmed) {
                    $('#deleteCategoryId').val(id);
                    $('#deleteCategoryForm').submit();
                }
            });
        }
        
        function editSupplier(supplier) {
            $('#editSupplierId').val(supplier.supplier_id);
            $('#editSupplierName').val(supplier.name);
            $('#editSupplierContactName').val(supplier.contact_name);
            $('#editSupplierPhone').val(supplier.phone);
            $('#editSupplierEmail').val(supplier.email);
            $('#editSupplierAddress').val(supplier.address);
            $('#editSupplierContactInfo').val(supplier.contact_info);
            $('#editSupplierModal').modal('show');
        }
        
        function deleteSupplier(id, name) {
            Swal.fire({
                title: 'Xác nhận xóa',
                text: `Bạn có chắc muốn xóa supplier "${name}"?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Xóa',
                cancelButtonText: 'Hủy'
            }).then((result) => {
                if (result.isConfirmed) {
                    $('#deleteSupplierId').val(id);
                    $('#deleteSupplierForm').submit();
                }
            });
        }
    </script>
</body>
</html>