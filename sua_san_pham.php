<?php
session_start();
require_once 'config/database.php';

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

// Xử lý cập nhật
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        error_log("POST data received: " . print_r($_POST, true));
        
        $name = trim($_POST['name']);
        $description = trim($_POST['description']);
        $type = !empty($_POST['type']) ? trim($_POST['type']) : null;
        
        // If type is empty string, set to null
        if ($type === '') {
            $type = null;
        }
        
        error_log("Processing update - Name: $name, Type: $type");
        
        if (empty($name)) {
            throw new Exception('Tên sản phẩm không được để trống');
        }
        
        $sql = "UPDATE products SET name = :name, description = :description, type = :type WHERE product_id = :product_id AND warehouse_id = :warehouse_id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':description', $description);
        $stmt->bindParam(':type', $type);
        $stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
        $stmt->bindParam(':warehouse_id', $userWarehouseId, PDO::PARAM_INT);
        
        if ($stmt->execute()) {
            $success_message = 'Cập nhật sản phẩm thành công!';
            error_log("Update successful for product_id: $product_id");
        } else {
            throw new Exception('Có lỗi xảy ra khi cập nhật sản phẩm');
        }
        
    } catch (Exception $e) {
        $error_message = $e->getMessage();
        error_log("Update error: " . $e->getMessage());
    }
}

// Lấy thông tin sản phẩm - chỉ của warehouse hiện tại
$sql = "SELECT p.* FROM products p WHERE p.product_id = :product_id AND p.warehouse_id = :warehouse_id";
$stmt = $pdo->prepare($sql);
$stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
$stmt->bindParam(':warehouse_id', $userWarehouseId, PDO::PARAM_INT);
$stmt->execute();
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    header('Location: danh_sach_san_pham.php?error=product_not_found_or_access_denied');
    exit();
}

// Lấy danh sách loại sản phẩm từ database (các loại đã được sử dụng)
$typeStmt = $pdo->query("
    SELECT DISTINCT type 
    FROM products 
    WHERE type IS NOT NULL AND type != '' 
    ORDER BY type ASC
");
$product_types = [];
while ($row = $typeStmt->fetch(PDO::FETCH_ASSOC)) {
    $product_types[] = $row['type'];
}

// Nếu không có loại nào trong database, dùng danh sách mặc định
if (empty($product_types)) {
    $product_types = [
        'giày sneaker',
        'giày thể thao',
        'giày boot',
        'giày cao gót',
        'giày sandal',
        'dép',
        'giày tây',
        'giày lười'
    ];
}

// Check if current type is custom (không có trong danh sách database)
$isCustom = !empty($product['type']) && !in_array($product['type'], $product_types);
?>

<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Chỉnh sửa sản phẩm - <?php echo htmlspecialchars($product['name']); ?></title>

    <!-- Custom fonts for this template -->
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">

    <!-- Custom styles for this template -->
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
                            <i class="fas fa-edit text-primary mr-2"></i>
                            Chỉnh sửa sản phẩm
                        </h1>
                        <div>
                            <a href="xem_san_pham.php?id=<?php echo $product_id; ?>" class="btn btn-info btn-sm mr-2">
                                <i class="fas fa-eye"></i> Xem chi tiết
                            </a>
                            <a href="danh_sach_san_pham.php" class="btn btn-secondary btn-sm">
                                <i class="fas fa-arrow-left"></i> Quay lại
                            </a>
                        </div>
                    </div>

                    <!-- Form Card -->
                    <div class="row justify-content-center">
                        <div class="col-lg-8">
                            <div class="card shadow mb-4">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">Thông tin sản phẩm</h6>
                                </div>
                                <div class="card-body">
                                    <form method="POST" id="editProductForm">
                                        <div class="form-group row">
                                            <label for="name" class="col-sm-3 col-form-label font-weight-bold">Tên sản phẩm <span class="text-danger">*</span></label>
                                            <div class="col-sm-9">
                                                <input type="text" class="form-control" id="name" name="name" 
                                                       value="<?php echo htmlspecialchars($product['name']); ?>" 
                                                       required maxlength="255">
                                            </div>
                                        </div>
                                        
                        <div class="form-group row">
                            <label for="type" class="col-sm-3 col-form-label font-weight-bold">Loại sản phẩm</label>
                            <div class="col-sm-9">
                                <select class="form-control" id="type" name="type">
                                    <option value="">-- Chọn loại sản phẩm --</option>
                                    <?php foreach ($product_types as $type): ?>
                                        <option value="<?php echo htmlspecialchars($type); ?>" 
                                                <?php echo ($type == ($product['type'] ?? '')) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($type); ?>
                                        </option>
                                    <?php endforeach; ?>
                                    <option value="__custom__" <?php 
                                        $isCustom = !empty($product['type']) && !in_array($product['type'], $product_types);
                                        echo $isCustom ? 'selected' : ''; 
                                    ?>>Nhập loại khác...</option>
                                </select>
                                <input type="text" class="form-control mt-2" id="type_custom" 
                                       placeholder="Nhập loại sản phẩm tùy chỉnh..." 
                                       style="<?php echo $isCustom ? '' : 'display: none;'; ?>"
                                       value="<?php echo $isCustom ? htmlspecialchars($product['type']) : ''; ?>">
                                <small class="form-text text-muted">Nhập hoặc chọn loại sản phẩm từ danh sách gợi ý</small>
                            </div>
                        </div>                                        <div class="form-group row">
                                            <label for="description" class="col-sm-3 col-form-label font-weight-bold">Mô tả</label>
                                            <div class="col-sm-9">
                                                <textarea class="form-control" id="description" name="description" 
                                                          rows="4" placeholder="Mô tả chi tiết về sản phẩm..."><?php echo htmlspecialchars($product['description']); ?></textarea>
                                            </div>
                                        </div>
                                        
                                        <div class="form-group row">
                                            <div class="col-sm-9 offset-sm-3">
                                                <button type="submit" class="btn btn-primary">
                                                    <i class="fas fa-save"></i> Cập nhật sản phẩm
                                                </button>
                                                <a href="danh_sach_san_pham.php" class="btn btn-secondary ml-2">
                                                    <i class="fas fa-times"></i> Hủy
                                                </a>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
                <!-- /.container-fluid -->

            </div>
            <!-- End of Main Content -->

            <?php include 'includes/chan_trang.php'; ?>

    <!-- Bootstrap core JavaScript-->
    <script src="vendor/jquery/jquery.min.js"></script>
    <script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script>

    <!-- Core plugin JavaScript-->
    <script src="vendor/jquery-easing/jquery.easing.min.js"></script>

    <!-- Custom scripts for all pages-->
    <script src="js/sb-admin-2.min.js"></script>

    <script>
        <?php if (isset($success_message)): ?>
        Swal.fire({
            title: 'Thành công!',
            text: <?php echo json_encode($success_message); ?>,
            icon: 'success',
            confirmButtonText: 'OK'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = 'xem_san_pham.php?id=<?php echo $product_id; ?>';
            }
        });
        <?php endif; ?>
        
        <?php if (isset($error_message)): ?>
        Swal.fire({
            title: 'Lỗi!',
            text: <?php echo json_encode($error_message); ?>,
            icon: 'error',
            confirmButtonText: 'OK'
        });
        <?php endif; ?>
        
        // Xử lý custom type input
        $('#type').on('change', function() {
            const selectedValue = $(this).val();
            console.log('Type changed to:', selectedValue);
            if (selectedValue === '__custom__') {
                $('#type_custom').show().focus();
            } else {
                $('#type_custom').hide();
            }
        });
        
        // Validation and form submit
        $('#editProductForm').on('submit', function(e) {
            console.log('Form submitted');
            
            const name = $('#name').val().trim();
            
            if (name === '') {
                e.preventDefault();
                Swal.fire({
                    title: 'Lỗi!',
                    text: 'Tên sản phẩm không được để trống.',
                    icon: 'error',
                    confirmButtonText: 'OK'
                });
                $('#name').focus();
                return false;
            }
            
            // Xử lý custom type - set the custom value as the select value
            if ($('#type').val() === '__custom__') {
                const customType = $('#type_custom').val().trim();
                console.log('Custom type:', customType);
                if (customType) {
                    // Change the select value to the custom type
                    $('#type').val(customType);
                    // If option doesn't exist, add it temporarily
                    if ($('#type option[value="' + customType + '"]').length === 0) {
                        $('#type').append($('<option>', {
                            value: customType,
                            selected: true
                        }));
                    }
                } else {
                    // No custom type entered, set to empty
                    $('#type').val('');
                }
            }
            
            console.log('Final type value:', $('#type').val());
            // Let form submit normally
            return true;
        });
    </script>

    <?php include 'includes/modal_dang_xuat.php'; ?>
</body>
</html>