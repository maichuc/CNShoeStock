<?php
session_start();
require_once 'config/cau_hinh_csdl.php';
require_once 'helpers/TroGiupDuongDan.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    header('Location: login.html');
    exit();
}

// Kiểm tra ID sản phẩm
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: danh_sach_san_pham.php?error=invalid_id');
    exit();
}

$product_id = (int)$_GET['id'];
$currentUser = $_SESSION['user_id'];
$userWarehouseId = $_SESSION['warehouse_id'] ?? null;

// Kết nối database
$database = new Database();
$pdo = $database->getConnection();

// Lấy warehouse_id từ database nếu chưa có trong session
if (!$userWarehouseId) {
    $stmt = $pdo->prepare("SELECT warehouse_id FROM users WHERE user_id = ?");
    $stmt->execute([$currentUser]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result) {
        $userWarehouseId = $result['warehouse_id'];
        $_SESSION['warehouse_id'] = $userWarehouseId;
    }
}

// Lấy thông tin sản phẩm chi tiết - chỉ của warehouse hiện tại
$sql = "
    SELECT 
        p.product_id,
        p.name as product_name,
        p.description,
        p.type,
        p.brand,
        p.material,
        p.features,
        p.status,
        p.created_at,
        COUNT(DISTINCT pv.variant_id) as variant_count,
        MIN(pv.price) as min_price,
        MAX(pv.price) as max_price
    FROM products p
    LEFT JOIN product_variants pv ON p.product_id = pv.product_id
    WHERE p.product_id = :product_id AND p.warehouse_id = :warehouse_id
    GROUP BY p.product_id, p.name, p.description, p.type, p.brand, p.material, p.features, p.status, p.created_at
";

$stmt = $pdo->prepare($sql);
$stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
$stmt->bindParam(':warehouse_id', $userWarehouseId, PDO::PARAM_INT);
$stmt->execute();
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    header('Location: danh_sach_san_pham.php?error=product_not_found_or_access_denied');
    exit();
}

// Lấy danh sách variants - chỉ từ warehouse hiện tại
$sql_variants = "
    SELECT 
        pv.*,
        (SELECT SUM(i.quantity) FROM inventory i WHERE i.variant_id = pv.variant_id AND i.warehouse_id = :warehouse_id) as total_inventory
    FROM product_variants pv 
    INNER JOIN products p ON pv.product_id = p.product_id
    WHERE pv.product_id = :product_id AND p.warehouse_id = :warehouse_id
    ORDER BY pv.sku, pv.size
";

$stmt_variants = $pdo->prepare($sql_variants);
$stmt_variants->bindParam(':product_id', $product_id, PDO::PARAM_INT);
$stmt_variants->bindParam(':warehouse_id', $userWarehouseId, PDO::PARAM_INT);
$stmt_variants->execute();
$variants = $stmt_variants->fetchAll(PDO::FETCH_ASSOC);

// Lấy hình ảnh sản phẩm
$sql_images = "SELECT * FROM product_images WHERE product_id = :product_id ORDER BY is_primary DESC, image_id";
$stmt_images = $pdo->prepare($sql_images);
$stmt_images->bindParam(':product_id', $product_id, PDO::PARAM_INT);
$stmt_images->execute();
$images = $stmt_images->fetchAll(PDO::FETCH_ASSOC);

// Debug: Log image paths
if (!empty($images)) {
    error_log("=== DEBUG: Product ID $product_id has " . count($images) . " images ===");
    foreach ($images as $idx => $img) {
        error_log("Image $idx: file_path = " . ($img['file_path'] ?? 'NULL'));
        error_log("  Converted URL: " . PathHelper::getImageUrl($img['file_path'] ?? ''));
    }
}
?>

<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Chi tiết sản phẩm - <?php echo htmlspecialchars($product['product_name']); ?></title>

    <!-- Custom fonts for this template -->
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">

    <!-- Custom styles for this template -->
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    
    <style>
        .product-image {
            max-width: 100%;
            height: 200px;
            object-fit: cover;
            border-radius: 8px;
            margin-bottom: 10px;
        }
        
        .variant-card {
            border: 1px solid #e3e6f0;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            background: #f8f9fc;
        }
        
        .info-label {
            font-weight: 600;
            color: #5a5c69;
        }
        
        .info-value {
            color: #3a3b45;
        }
        
        .status-badge {
            padding: 4px 12px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-active {
            background-color: #d4edda;
            color: #155724;
        }
        
        .status-inactive {
            background-color: #f8d7da;
            color: #721c24;
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
                        <h1 class="h3 mb-0 text-gray-800">
                            <i class="fas fa-eye text-primary mr-2"></i>
                            Chi tiết sản phẩm
                        </h1>
                        <div>
                            <a href="sua_san_pham.php?id=<?php echo $product_id; ?>" class="btn btn-warning btn-sm mr-2">
                                <i class="fas fa-edit"></i> Chỉnh sửa
                            </a>
                            <a href="danh_sach_san_pham.php" class="btn btn-secondary btn-sm">
                                <i class="fas fa-arrow-left"></i> Quay lại
                            </a>
                        </div>
                    </div>

                    <div class="row">
                        <!-- Thông tin chính -->
                        <div class="col-lg-8">
                            <div class="card shadow mb-4">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">Thông tin sản phẩm</h6>
                                </div>
                                <div class="card-body">
                                    <div class="row mb-3">
                                        <div class="col-sm-3 info-label">Tên sản phẩm:</div>
                                        <div class="col-sm-9 info-value"><?php echo htmlspecialchars($product['product_name']); ?></div>
                                    </div>
                                    
                    <div class="row mb-3">
                        <div class="col-sm-3 info-label">Loại sản phẩm:</div>
                        <div class="col-sm-9 info-value">
                            <?php echo $product['type'] ? htmlspecialchars($product['type']) : '<span class="text-muted">Chưa xác định</span>'; ?>
                        </div>
                    </div>
                    
                    <?php if (!empty($product['brand'])): ?>
                    <div class="row mb-3">
                        <div class="col-sm-3 info-label">Thương hiệu:</div>
                        <div class="col-sm-9 info-value"><?php echo htmlspecialchars($product['brand']); ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($product['material'])): ?>
                    <div class="row mb-3">
                        <div class="col-sm-3 info-label">Chất liệu:</div>
                        <div class="col-sm-9 info-value"><?php echo htmlspecialchars($product['material']); ?></div>
                    </div>
                    <?php endif; ?>                                    <div class="row mb-3">
                                        <div class="col-sm-3 info-label">Mô tả:</div>
                                        <div class="col-sm-9 info-value">
                                            <?php echo $product['description'] ? nl2br(htmlspecialchars($product['description'])) : '<span class="text-muted">Chưa có mô tả</span>'; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="row mb-3">
                                        <div class="col-sm-3 info-label">Khoảng giá:</div>
                                        <div class="col-sm-9 info-value">
                                            <?php if ($product['min_price'] > 0): ?>
                                                <?php if ($product['min_price'] == $product['max_price']): ?>
                                                    <strong><?php echo number_format($product['min_price'], 0, ',', '.'); ?>₫</strong>
                                                <?php else: ?>
                                                    <strong><?php echo number_format($product['min_price'], 0, ',', '.'); ?>₫ - <?php echo number_format($product['max_price'], 0, ',', '.'); ?>₫</strong>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted">Chưa định giá</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="row mb-3">
                                        <div class="col-sm-3 info-label">Số variants:</div>
                                        <div class="col-sm-9 info-value"><strong><?php echo $product['variant_count']; ?></strong> variants</div>
                                    </div>
                                    
                                    <div class="row mb-3">
                                        <div class="col-sm-3 info-label">Ngày tạo:</div>
                                        <div class="col-sm-9 info-value"><?php echo date('d/m/Y H:i:s', strtotime($product['created_at'])); ?></div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Danh sách Variants -->
                            <?php if (!empty($variants)): ?>
                            <div class="card shadow mb-4">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">Danh sách Variants</h6>
                                </div>
                                <div class="card-body">
                                    <?php foreach ($variants as $variant): ?>
                                    <div class="variant-card">
                                        <div class="row">
                                            <div class="col-md-4">
                                                <div class="info-label">SKU:</div>
                                                <div class="info-value"><strong><?php echo htmlspecialchars($variant['sku']); ?></strong></div>
                                                <?php if (!empty($variant['color'])): ?>
                                                <div class="mt-2">
                                                    <small class="text-muted"><i class="fas fa-palette"></i> Màu: <?php echo htmlspecialchars($variant['color']); ?></small>
                                                </div>
                                                <?php endif; ?>
                                                <?php if (!empty($variant['size'])): ?>
                                                <div>
                                                    <small class="text-muted"><i class="fas fa-ruler"></i> Size: <?php echo htmlspecialchars($variant['size']); ?></small>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="info-label">Giá:</div>
                                                <div class="info-value"><strong class="text-success"><?php echo number_format($variant['price'], 0, ',', '.'); ?>₫</strong></div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="info-label">Tồn kho:</div>
                                                <div class="info-value">
                                                    <strong class="<?php echo ($variant['total_inventory'] ?? 0) > 0 ? 'text-primary' : 'text-danger'; ?>">
                                                        <?php echo $variant['total_inventory'] ?? 0; ?>
                                                    </strong> 
                                                    <small class="text-muted">sản phẩm</small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Hình ảnh -->
                        <div class="col-lg-4">
                            <div class="card shadow mb-4">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">Hình ảnh sản phẩm</h6>
                                </div>
                                <div class="card-body">
                                    <?php if (!empty($images)): ?>
                                        <?php foreach ($images as $image): ?>
                                            <div class="text-center mb-3">
                                                <img src="<?php echo htmlspecialchars(PathHelper::getImageUrl($image['file_path'])); ?>" 
                                                     alt="Product Image" 
                                                     class="product-image"
                                                     onerror="this.src='img/no-image.png'">
                                                <?php if ($image['is_primary']): ?>
                                                    <br><span class="badge badge-primary">Hình chính</span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="text-center text-muted">
                                            <i class="fas fa-image fa-3x mb-3"></i>
                                            <p>Chưa có hình ảnh</p>
                                        </div>
                                    <?php endif; ?>
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
                        <span>Copyright &copy; Smart Warehouse System 2024</span>
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

    <?php include 'includes/modal_dang_xuat.php'; ?>
</body>
</html>