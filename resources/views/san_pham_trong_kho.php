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

// Lấy danh sách sản phẩm của warehouse này
$products = $warehouseControl->getWarehouseProducts($userWarehouseId);

// Lấy thống kê
$stats = $warehouseControl->getWarehouseStats($userWarehouseId);

// Xử lý tìm kiếm
$searchTerm = $_GET['search'] ?? '';
$categoryId = $_GET['category'] ?? null;

if ($searchTerm) {
    $products = $warehouseControl->searchProducts($userWarehouseId, $searchTerm, $categoryId);
}

// Lấy danh sách categories
$stmt = $pdo->query("SELECT * FROM categories ORDER BY name");
$categories = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Sản phẩm Kho <?php echo htmlspecialchars($warehouse['name'] ?? 'N/A'); ?> - Smart Warehouse System</title>

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- FontAwesome -->
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet">
    <!-- Bootstrap -->
    <link href="vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <!-- DataTables -->
    <link href="vendor/datatables/dataTables.bootstrap4.min.css" rel="stylesheet">
    
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
        
        .stats-card {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
            border-left: 0.25rem solid var(--primary-color);
            transition: transform 0.3s ease;
        }
        
        .stats-card:hover {
            transform: translateY(-5px);
        }
        
        .product-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            overflow: hidden;
        }
        
        .product-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 0.5rem 2rem 0 rgba(58, 59, 69, 0.2);
        }
        
        .product-image {
            width: 100%;
            height: 200px;
            object-fit: cover;
            border-radius: 10px 10px 0 0;
        }
        
        .product-title {
            font-weight: 600;
            color: var(--dark-color);
            margin-bottom: 0.5rem;
        }
        
        .product-meta {
            font-size: 0.875rem;
            color: var(--secondary-color);
        }
        
        .stock-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .search-section {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
        }
        
        .isolation-notice {
            background: linear-gradient(135deg, #1cc88a, #17a2b8);
            color: white;
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1rem;
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
                            <i class="fas fa-warehouse mr-3"></i>
                            Kho hàng: <?php echo htmlspecialchars($warehouse['name'] ?? 'N/A'); ?>
                        </h1>
                        <p class="mb-1">
                            <i class="fas fa-map-marker-alt mr-2"></i>
                            <?php echo htmlspecialchars($warehouse['address'] ?? 'N/A'); ?>
                        </p>
                        <p class="mb-1">
                            <i class="fas fa-user-tie mr-2"></i>
                            Quản lý: <?php echo htmlspecialchars($warehouse['manager_name'] ?? 'N/A'); ?>
                        </p>
                        <p class="mb-0">
                            <i class="fas fa-phone mr-2"></i>
                            <?php echo htmlspecialchars($warehouse['phone'] ?? 'N/A'); ?>
                        </p>
                    </div>
                </div>
                <div class="col-md-4 text-right">
                    <a href="them_san_pham_ai.php" class="btn btn-light btn-lg">
                        <i class="fas fa-plus mr-2"></i>Thêm sản phẩm mới
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
                        Hệ thống Warehouse độc lập
                    </h5>
                    <p class="mb-0">
                        Bạn chỉ có thể xem và quản lý sản phẩm thuộc kho hàng của mình. 
                        Các kho khác không thể truy cập vào dữ liệu này.
                    </p>
                </div>
                <div class="col-md-4 text-right">
                    <span class="badge badge-light badge-pill px-3 py-2">
                        <i class="fas fa-lock mr-1"></i>
                        Dữ liệu được bảo mật
                    </span>
                </div>
            </div>
        </div>

        <!-- Statistics -->
        <div class="row mb-4">
            <div class="col-xl-3 col-md-6 mb-4">
                <div class="stats-card">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                Tổng sản phẩm
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format($stats['total_products']); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-box fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-md-6 mb-4">
                <div class="stats-card">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                Tổng biến thể
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format($stats['total_variants']); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-tags fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-md-6 mb-4">
                <div class="stats-card">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                Tổng tồn kho
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format($stats['total_inventory']); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-cubes fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-md-6 mb-4">
                <div class="stats-card">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                Giá trung bình
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format($stats['avg_price'], 0, ',', '.'); ?>đ
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-dollar-sign fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Search Section -->
        <div class="search-section">
            <form method="GET" action="">
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="search">Tìm kiếm sản phẩm:</label>
                            <input type="text" class="form-control" id="search" name="search" 
                                   value="<?php echo htmlspecialchars($searchTerm); ?>" 
                                   placeholder="Nhập tên, mô tả, thương hiệu hoặc tag...">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label for="category">Danh mục:</label>
                            <select class="form-control" id="category" name="category">
                                <option value="">Tất cả danh mục</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?php echo $category['category_id']; ?>"
                                            <?php echo ($categoryId == $category['category_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($category['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>&nbsp;</label>
                            <button type="submit" class="btn btn-primary btn-block">
                                <i class="fas fa-search mr-1"></i>Tìm kiếm
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <!-- Products Grid -->
        <div class="row">
            <?php if (empty($products)): ?>
                <div class="col-12">
                    <div class="text-center py-5">
                        <i class="fas fa-box-open fa-3x text-gray-300 mb-3"></i>
                        <h4 class="text-gray-600">Không có sản phẩm nào</h4>
                        <p class="text-gray-500">
                            <?php if ($searchTerm): ?>
                                Không tìm thấy sản phẩm nào với từ khóa "<?php echo htmlspecialchars($searchTerm); ?>"
                            <?php else: ?>
                                Kho hàng của bạn chưa có sản phẩm nào. Hãy thêm sản phẩm mới!
                            <?php endif; ?>
                        </p>
                        <a href="them_san_pham_ai.php" class="btn btn-primary">
                            <i class="fas fa-plus mr-2"></i>Thêm sản phẩm đầu tiên
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($products as $product): ?>
                    <div class="col-lg-4 col-md-6 mb-4">
                        <div class="product-card">
                            <div class="position-relative">
                                <?php if ($product['primary_image']): ?>
                                    <img src="<?php echo htmlspecialchars($product['primary_image']); ?>" 
                                         class="product-image" alt="<?php echo htmlspecialchars($product['product_name']); ?>">
                                <?php else: ?>
                                    <div class="product-image bg-light d-flex align-items-center justify-content-center">
                                        <i class="fas fa-image fa-3x text-gray-400"></i>
                                    </div>
                                <?php endif; ?>
                                
                                <!-- Stock badge -->
                                <?php
                                $stockClass = 'badge-danger';
                                $stockText = 'Hết hàng';
                                if ($product['total_stock'] > 0) {
                                    $stockClass = $product['total_stock'] > 50 ? 'badge-success' : 'badge-warning';
                                    $stockText = number_format($product['total_stock']) . ' sp';
                                }
                                ?>
                                <span class="stock-badge badge <?php echo $stockClass; ?>">
                                    <?php echo $stockText; ?>
                                </span>
                            </div>
                            
                            <div class="card-body">
                                <h5 class="product-title">
                                    <?php echo htmlspecialchars($product['product_name']); ?>
                                </h5>
                                
                                <div class="product-meta mb-2">
                                    <?php if ($product['brand']): ?>
                                        <span class="badge badge-primary mr-1">
                                            <?php echo htmlspecialchars($product['brand']); ?>
                                        </span>
                                    <?php endif; ?>
                                    
                                    <?php if ($product['type']): ?>
                                        <span class="badge badge-secondary mr-1">
                                            <?php echo htmlspecialchars($product['type']); ?>
                                        </span>
                                    <?php endif; ?>
                                    
                                    <span class="badge badge-info">
                                        <?php echo $product['variant_count']; ?> biến thể
                                    </span>
                                </div>
                                
                                <?php if ($product['description']): ?>
                                    <p class="text-muted small mb-3">
                                        <?php echo substr(htmlspecialchars($product['description']), 0, 100); ?>
                                        <?php echo strlen($product['description']) > 100 ? '...' : ''; ?>
                                    </p>
                                <?php endif; ?>
                                
                                <div class="product-meta mb-3">
                                    <small class="text-muted">
                                        <i class="fas fa-calendar mr-1"></i>
                                        <?php echo date('d/m/Y', strtotime($product['created_at'])); ?>
                                    </small>
                                </div>
                                
                                <div class="d-flex justify-content-between">
                                    <a href="xem_san_pham.php?id=<?php echo $product['product_id']; ?>" 
                                       class="btn btn-primary btn-sm">
                                        <i class="fas fa-eye mr-1"></i>Xem chi tiết
                                    </a>
                                    <a href="sua_san_pham.php?id=<?php echo $product['product_id']; ?>" 
                                       class="btn btn-outline-secondary btn-sm">
                                        <i class="fas fa-edit mr-1"></i>Sửa
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Scripts -->
    <script src="vendor/jquery/jquery.min.js"></script>
    <script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Auto-submit form when category changes
        document.getElementById('category').addEventListener('change', function() {
            this.form.submit();
        });
        
        // Tìm kiếm on Enter
        document.getElementById('search').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                this.form.submit();
            }
        });
    </script>
</body>
</html>