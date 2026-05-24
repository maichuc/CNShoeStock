<?php
// session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../app/Classes/Kho.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    header('Location: login.html');
    exit;
}

$database = new Database();
$pdo = $database->getConnection();

$userId = $_SESSION['user_id'];
$warehouseId = $_SESSION['warehouse_id'];

// Lấy thông tin warehouse
$warehouseName = 'Smart Warehouse';
if ($warehouseId) {
    $warehouseObj = new Warehouse($pdo);
    if ($warehouseObj->getById($warehouseId)) {
        $warehouseName = $warehouseObj->name;
    }
}

// Kiểm tra xem có phải edit mode không
$editMode = false;
$supplier = null;
if (isset($_GET['edit']) && !empty($_GET['edit'])) {
    $editMode = true;
    $supplierId = (int)$_GET['edit'];
    
    $stmt = $pdo->prepare("SELECT * FROM suppliers WHERE supplier_id = ?");
    $stmt->execute([$supplierId]);
    $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$supplier) {
        header('Location: quan_ly_nha_cung_cap.php');
        exit;
    }
}

// Load danh sách tỉnh/thành phố (có thể từ database hoặc hardcode)
$provinces = [
    'An Giang', 'Bà Rịa - Vũng Tàu', 'Bắc Giang', 'Bắc Kạn', 'Bạc Liêu', 
    'Bắc Ninh', 'Bến Tre', 'Bình Định', 'Bình Dương', 'Bình Phước', 
    'Bình Thuận', 'Cà Mau', 'Cao Bằng', 'Đắk Lắk', 'Đắk Nông', 
    'Điện Biên', 'Đồng Nai', 'Đồng Tháp', 'Gia Lai', 'Hà Giang', 
    'Hà Nam', 'Hà Tĩnh', 'Hải Dương', 'Hậu Giang', 'Hòa Bình', 
    'Hưng Yên', 'Khánh Hòa', 'Kiên Giang', 'Kon Tum', 'Lai Châu', 
    'Lâm Đồng', 'Lạng Sơn', 'Lào Cai', 'Long An', 'Nam Định', 
    'Nghệ An', 'Ninh Bình', 'Ninh Thuận', 'Phú Thọ', 'Quảng Bình', 
    'Quảng Nam', 'Quảng Ngãi', 'Quảng Ninh', 'Quảng Trị', 'Sóc Trăng', 
    'Sơn La', 'Tây Ninh', 'Thái Bình', 'Thái Nguyên', 'Thanh Hóa', 
    'Thừa Thiên Huế', 'Tiền Giang', 'Trà Vinh', 'Tuyên Quang', 'Vĩnh Long', 
    'Vĩnh Phúc', 'Yên Bái', 'Phú Yên', 'Cần Thơ', 'Đà Nẵng', 
    'Hải Phòng', 'Hà Nội', 'TP. Hồ Chí Minh'
];

// Tự động tạo mã nhà cung cấp
function generateSupplierCode($pdo) {
    $today = date('Ymd');
    $prefix = 'NCC' . $today;
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM suppliers WHERE supplier_code LIKE ?");
    $stmt->execute([$prefix . '%']);
    $count = $stmt->fetchColumn();
    
    return $prefix . str_pad($count + 1, 3, '0', STR_PAD_LEFT);
}

$newSupplierCode = $editMode ? $supplier['supplier_code'] : generateSupplierCode($pdo);
?>

<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    
    <title><?php echo $editMode ? 'Chỉnh sửa' : 'Thêm'; ?> nhà cung cấp - <?php echo htmlspecialchars($warehouseName); ?></title>

    <!-- Custom fonts for this template -->
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">

    <!-- Custom styles for this template -->
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style>
        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .form-section {
            background: #f8f9fc;
            border: 1px solid #e3e6f0;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .form-section h5 {
            color: #5a5c69;
            font-weight: 700;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e3e6f0;
        }
        
        .required-field {
            color: #e74a3b;
        }
        
        .form-control:focus {
            border-color: #4e73df;
            box-shadow: 0 0 0 0.2rem rgba(78, 115, 223, 0.25);
        }
        
        .btn-save {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            border: none;
            color: white;
            font-weight: 600;
            padding: 12px 30px;
            transition: all 0.3s ease;
        }
        
        .btn-save:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            color: white;
        }
        
        .btn-save-and-new {
            background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
            border: none;
            color: white;
            font-weight: 600;
            padding: 12px 30px;
            transition: all 0.3s ease;
        }
        
        .btn-save-and-new:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            color: white;
        }
        
        .form-group label {
            font-weight: 600;
            color: #5a5c69;
        }
        
        .auto-generated {
            background-color: #f8f9fa;
            color: #6c757d;
        }
        
        .validation-feedback {
            font-size: 0.8em;
            margin-top: 5px;
        }
        
        .validation-feedback.valid {
            color: #28a745;
        }
        
        .validation-feedback.invalid {
            color: #dc3545;
        }
        
        .input-group-text {
            background-color: #f8f9fa;
            border-color: #d1d3e2;
        }
    </style>
</head>

<body id="page-top">

    <!-- Page Wrapper -->
    <div id="wrapper">

        <!-- Sidebar -->
        <?php include __DIR__ . '/includes/thanh_ben.php'; ?>

        <!-- Content Wrapper -->
        <div id="content-wrapper" class="d-flex flex-column">

            <!-- Main Content -->
            <div id="content">

                <!-- Topbar -->
                <?php include __DIR__ . '/includes/thanh_tren.php'; ?>

                <!-- Begin Page Content -->
                <div class="container-fluid">

                    <!-- Page Heading -->
                    <div class="d-sm-flex align-items-center justify-content-between mb-4">
                        <h1 class="h3 mb-0 text-gray-800">
                            <i class="fas fa-<?php echo $editMode ? 'edit' : 'plus'; ?> text-primary"></i>
                            <?php echo $editMode ? 'Chỉnh sửa' : 'Thêm'; ?> nhà cung cấp
                        </h1>
                    </div>

                    <!-- Main Form -->
                    <form id="supplierForm" novalidate>
                        <input type="hidden" id="supplier_id" value="<?php echo $editMode ? $supplier['supplier_id'] : ''; ?>">
                        
                        <div class="row">
                            <!-- Left Column -->
                            <div class="col-lg-6">
                                <!-- Basic Information Section -->
                                <div class="form-section">
                                    <h5><i class="fas fa-info-circle text-primary"></i> Thông tin cơ bản</h5>
                                    
                                    <div class="form-group">
                                        <label for="supplier_code">Mã nhà cung cấp <span class="required-field">*</span></label>
                                        <input type="text" class="form-control auto-generated" id="supplier_code" 
                                               value="<?php echo htmlspecialchars($newSupplierCode); ?>" readonly>
                                        <small class="form-text text-muted">Mã được tự động tạo</small>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="name">Tên nhà cung cấp <span class="required-field">*</span></label>
                                        <input type="text" class="form-control" id="name" 
                                               value="<?php echo $editMode ? htmlspecialchars($supplier['name']) : ''; ?>"
                                               placeholder="Nhập tên đầy đủ của nhà cung cấp" required>
                                        <div class="validation-feedback" id="name-feedback"></div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="short_name">Tên viết tắt</label>
                                        <input type="text" class="form-control" id="short_name" 
                                               value="<?php echo $editMode ? htmlspecialchars($supplier['short_name']) : ''; ?>"
                                               placeholder="Tên viết tắt (tùy chọn)">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="tax_code">Mã số thuế <span class="required-field">*</span></label>
                                        <input type="text" class="form-control" id="tax_code" 
                                               value="<?php echo $editMode ? htmlspecialchars($supplier['tax_code']) : ''; ?>"
                                               placeholder="Nhập mã số thuế (10-13 chữ số)" required maxlength="13">
                                        <div class="validation-feedback" id="tax_code-feedback"></div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="type">Loại hình</label>
                                        <select class="form-control" id="type">
                                            <option value="">Chọn loại hình</option>
                                            <option value="Công ty TNHH" <?php echo ($editMode && $supplier['type'] == 'Công ty TNHH') ? 'selected' : ''; ?>>Công ty TNHH</option>
                                            <option value="Công ty Cổ phần" <?php echo ($editMode && $supplier['type'] == 'Công ty Cổ phần') ? 'selected' : ''; ?>>Công ty Cổ phần</option>
                                            <option value="Doanh nghiệp tư nhân" <?php echo ($editMode && $supplier['type'] == 'Doanh nghiệp tư nhân') ? 'selected' : ''; ?>>Doanh nghiệp tư nhân</option>
                                            <option value="Hợp tác xã" <?php echo ($editMode && $supplier['type'] == 'Hợp tác xã') ? 'selected' : ''; ?>>Hợp tác xã</option>
                                            <option value="Cá nhân kinh doanh" <?php echo ($editMode && $supplier['type'] == 'Cá nhân kinh doanh') ? 'selected' : ''; ?>>Cá nhân kinh doanh</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Right Column -->
                            <div class="col-lg-6">
                                <!-- Contact Information Section -->
                                <div class="form-section">
                                    <h5><i class="fas fa-address-book text-success"></i> Thông tin liên hệ</h5>
                                    
                                    <div class="form-group">
                                        <label for="address">Địa chỉ <span class="required-field">*</span></label>
                                        <textarea class="form-control" id="address" rows="3" 
                                                  placeholder="Nhập địa chỉ chi tiết" required><?php echo $editMode ? htmlspecialchars($supplier['address']) : ''; ?></textarea>
                                        <div class="validation-feedback" id="address-feedback"></div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="province">Tỉnh/Thành phố</label>
                                                <select class="form-control" id="province">
                                                    <option value="">Chọn tỉnh/thành phố</option>
                                                    <?php foreach ($provinces as $province): ?>
                                                    <option value="<?php echo htmlspecialchars($province); ?>" 
                                                            <?php echo ($editMode && $supplier['province'] == $province) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($province); ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="district">Quận/Huyện</label>
                                                <input type="text" class="form-control" id="district" 
                                                       value="<?php echo $editMode ? htmlspecialchars($supplier['district']) : ''; ?>"
                                                       placeholder="Nhập quận/huyện">
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="phone">Số điện thoại <span class="required-field">*</span></label>
                                        <div class="input-group">
                                            <div class="input-group-prepend">
                                                <span class="input-group-text"><i class="fas fa-phone"></i></span>
                                            </div>
                                            <input type="text" class="form-control" id="phone" 
                                                   value="<?php echo $editMode ? htmlspecialchars($supplier['phone']) : ''; ?>"
                                                   placeholder="Nhập số điện thoại" required>
                                        </div>
                                        <div class="validation-feedback" id="phone-feedback"></div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="email">Email</label>
                                        <div class="input-group">
                                            <div class="input-group-prepend">
                                                <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                                            </div>
                                            <input type="email" class="form-control" id="email" 
                                                   value="<?php echo $editMode ? htmlspecialchars($supplier['email']) : ''; ?>"
                                                   placeholder="Nhập địa chỉ email">
                                        </div>
                                        <div class="validation-feedback" id="email-feedback"></div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="website">Website</label>
                                        <div class="input-group">
                                            <div class="input-group-prepend">
                                                <span class="input-group-text"><i class="fas fa-globe"></i></span>
                                            </div>
                                            <input type="url" class="form-control" id="website" 
                                                   value="<?php echo $editMode ? htmlspecialchars($supplier['website']) : ''; ?>"
                                                   placeholder="https://example.com">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Additional Information Section -->
                        <div class="row">
                            <div class="col-12">
                                <div class="form-section">
                                    <h5><i class="fas fa-plus-circle text-info"></i> Thông tin bổ sung</h5>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="contact_person">Người liên hệ chính</label>
                                                <input type="text" class="form-control" id="contact_person" 
                                                       value="<?php echo $editMode ? htmlspecialchars($supplier['contact_person']) : ''; ?>"
                                                       placeholder="Tên người liên hệ">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="contact_position">Chức vụ</label>
                                                <input type="text" class="form-control" id="contact_position" 
                                                       value="<?php echo $editMode ? htmlspecialchars($supplier['contact_position']) : ''; ?>"
                                                       placeholder="Chức vụ của người liên hệ">
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="notes">Ghi chú</label>
                                        <textarea class="form-control" id="notes" rows="3" 
                                                  placeholder="Ghi chú thêm về nhà cung cấp (tùy chọn)"><?php echo $editMode ? htmlspecialchars($supplier['notes']) : ''; ?></textarea>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label>Trạng thái</label>
                                        <div class="form-control border-0 bg-transparent p-0">
                                            <div class="custom-control custom-radio custom-control-inline">
                                                <input type="radio" id="status_active" name="status" value="active" class="custom-control-input" 
                                                       <?php echo (!$editMode || $supplier['status'] == 'active') ? 'checked' : ''; ?>>
                                                <label class="custom-control-label" for="status_active">
                                                    <i class="fas fa-check-circle text-success"></i> Hoạt động
                                                </label>
                                            </div>
                                            <div class="custom-control custom-radio custom-control-inline">
                                                <input type="radio" id="status_inactive" name="status" value="inactive" class="custom-control-input"
                                                       <?php echo ($editMode && $supplier['status'] == 'inactive') ? 'checked' : ''; ?>>
                                                <label class="custom-control-label" for="status_inactive">
                                                    <i class="fas fa-pause-circle text-warning"></i> Tạm ngưng
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Action Buttons -->
                        <div class="row">
                            <div class="col-12">
                                <div class="card shadow">
                                    <div class="card-body text-center">
                                        <?php if (!$editMode): ?>
                                        <button type="button" class="btn btn-save-and-new btn-lg mr-3" onclick="saveSupplier(true)">
                                            <i class="fas fa-save mr-2"></i>
                                            Lưu & Thêm mới
                                        </button>
                                        <?php endif; ?>
                                        
                                        <button type="button" class="btn btn-save btn-lg mr-3" onclick="saveSupplier(false)">
                                            <i class="fas fa-<?php echo $editMode ? 'edit' : 'save'; ?> mr-2"></i>
                                            <?php echo $editMode ? 'Cập nhật' : 'Lưu'; ?> & Đóng
                                        </button>
                                        
                                        <a href="quan_ly_nha_cung_cap.php" class="btn btn-secondary btn-lg">
                                            <i class="fas fa-times mr-2"></i>
                                            Hủy
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>

                </div>
                <!-- /.container-fluid -->

            </div>
            <!-- End of Main Content -->

            <!-- Footer -->
            <?php include __DIR__ . '/includes/chan_trang.php'; ?>

        </div>
        <!-- End of Content Wrapper -->

    </div>
    <!-- End of Page Wrapper -->

    <!-- Scroll to Top Button-->
    <a class="scroll-to-top rounded" href="#page-top">
        <i class="fas fa-angle-up"></i>
    </a>

    <!-- Logout Modal-->
    <?php include __DIR__ . '/includes/modal_dang_xuat.php'; ?>

    <!-- Bootstrap core JavaScript-->
    <script src="vendor/jquery/jquery.min.js"></script>
    <script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script>

    <!-- Core plugin JavaScript-->
    <script src="vendor/jquery-easing/jquery.easing.min.js"></script>

    <!-- Custom scripts for all pages-->
    <script src="js/sb-admin-2.min.js"></script>

    <script>
        $(document).ready(function() {
            // Auto-generate short name from full name
            $('#name').on('input', function() {
                const fullName = $(this).val();
                if (fullName && !$('#short_name').val()) {
                    const words = fullName.split(' ');
                    let shortName = '';
                    words.forEach(word => {
                        if (word) shortName += word.charAt(0).toUpperCase();
                    });
                    $('#short_name').val(shortName);
                }
                validateField('name', fullName);
            });

            // Tax code validation
            $('#tax_code').on('input', function() {
                let value = $(this).val().replace(/\D/g, ''); // Only numbers
                $(this).val(value);
                validateField('tax_code', value);
                
                // Kiểm tra duplicate
                if (value.length >= 10) {
                    checkDuplicateTaxCode(value);
                }
            });

            // Phone validation and formatting
            $('#phone').on('input', function() {
                let value = $(this).val().replace(/\D/g, ''); // Only numbers
                
                // Auto format phone number
                if (value.length >= 10) {
                    if (value.startsWith('84')) {
                        value = '0' + value.substring(2);
                    }
                    // Định dạng: 0987.654.321
                    if (value.length == 10) {
                        value = value.replace(/(\d{4})(\d{3})(\d{3})/, '$1.$2.$3');
                    }
                }
                
                $(this).val(value);
                validateField('phone', value.replace(/\./g, ''));
            });

            // Email validation
            $('#email').on('input', function() {
                const email = $(this).val();
                validateField('email', email);
            });

            // Address validation
            $('#address').on('input', function() {
                const address = $(this).val();
                validateField('address', address);
            });
        });

        // Field validation functions
        function validateField(fieldName, value) {
            const feedback = $(`#${fieldName}-feedback`);
            let isValid = true;
            let message = '';

            switch (fieldName) {
                case 'name':
                    if (!value || value.length < 3) {
                        isValid = false;
                        message = '❌ Tên nhà cung cấp phải có ít nhất 3 ký tự';
                    } else if (value.length > 100) {
                        isValid = false;
                        message = '❌ Tên nhà cung cấp không được quá 100 ký tự';
                    } else {
                        message = '✅ Tên hợp lệ';
                    }
                    break;

                case 'tax_code':
                    if (!value) {
                        isValid = false;
                        message = '❌ Mã số thuế là bắt buộc';
                    } else if (value.length < 10 || value.length > 13) {
                        isValid = false;
                        message = '❌ Mã số thuế phải có 10-13 chữ số';
                    } else if (!/^\d+$/.test(value)) {
                        isValid = false;
                        message = '❌ Mã số thuế chỉ được chứa số';
                    } else {
                        message = '✅ Mã số thuế hợp lệ';
                    }
                    break;

                case 'phone':
                    if (!value) {
                        isValid = false;
                        message = '❌ Số điện thoại là bắt buộc';
                    } else if (!/^(84|0)[3-9]\d{8}$/.test(value)) {
                        isValid = false;
                        message = '❌ Số điện thoại không đúng định dạng Việt Nam';
                    } else {
                        message = '✅ Số điện thoại hợp lệ';
                    }
                    break;

                case 'email':
                    if (value && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
                        isValid = false;
                        message = '❌ Địa chỉ email không hợp lệ';
                    } else if (value) {
                        message = '✅ Email hợp lệ';
                    }
                    break;

                case 'address':
                    if (!value || value.length < 10) {
                        isValid = false;
                        message = '❌ Địa chỉ phải có ít nhất 10 ký tự';
                    } else {
                        message = '✅ Địa chỉ hợp lệ';
                    }
                    break;
            }

            feedback.removeClass('valid invalid')
                   .addClass(isValid ? 'valid' : 'invalid')
                   .text(message);

            return isValid;
        }

        // Kiểm tra duplicate tax code
        function checkDuplicateTaxCode(taxCode) {
            const supplierId = $('#supplier_id').val();
            
            $.ajax({
                url: 'api_kiem_tra_ncc_trung.php',
                method: 'POST',
                data: { 
                    tax_code: taxCode,
                    supplier_id: supplierId || null
                },
                success: function(response) {
                    if (response.exists) {
                        $('#tax_code-feedback')
                            .removeClass('valid')
                            .addClass('invalid')
                            .text(`❌ Mã số thuế đã tồn tại (NCC: ${response.supplier_name})`);
                    }
                }
            });
        }

        // Lưu supplier function
        function saveSupplier(addNew = false) {
            // Kiểm tra all required fields
            const name = $('#name').val().trim();
            const taxCode = $('#tax_code').val().trim();
            const phone = $('#phone').val().replace(/\./g, '').trim();
            const address = $('#address').val().trim();

            let isValid = true;
            isValid &= validateField('name', name);
            isValid &= validateField('tax_code', taxCode);
            isValid &= validateField('phone', phone);
            isValid &= validateField('address', address);

            // Kiểm tra email nếu có
            const email = $('#email').val().trim();
            if (email) {
                isValid &= validateField('email', email);
            }

            if (!isValid) {
                Swal.fire({
                    title: '❌ Dữ liệu không hợp lệ',
                    text: 'Vui lòng kiểm tra và sửa các lỗi được đánh dấu.',
                    icon: 'error',
                    confirmButtonText: '✅ Đã hiểu'
                });
                return;
            }

            // Chuẩn bị data
            const formData = {
                supplier_id: $('#supplier_id').val() || null,
                supplier_code: $('#supplier_code').val(),
                name: name,
                short_name: $('#short_name').val().trim(),
                tax_code: taxCode,
                type: $('#type').val(),
                address: address,
                province: $('#province').val(),
                district: $('#district').val().trim(),
                phone: phone,
                email: email,
                website: $('#website').val().trim(),
                contact_person: $('#contact_person').val().trim(),
                contact_position: $('#contact_position').val().trim(),
                notes: $('#notes').val().trim(),
                status: $('input[name="status"]:checked').val()
            };

            // Hiển thị loading
            Swal.fire({
                title: '⏳ Đang xử lý...',
                text: 'Vui lòng chờ trong giây lát',
                allowOutsideClick: false,
                showConfirmButton: false,
                willOpen: () => {
                    Swal.showLoading();
                }
            });

            // Gửi AJAX request
            $.ajax({
                url: 'api_luu_nha_cung_cap.php',
                method: 'POST',
                data: formData,
                success: function(response) {
                    if (response.success) {
                        const isEdit = formData.supplier_id !== null && formData.supplier_id !== '';
                        
                        Swal.fire({
                            title: '🎉 Thành công!',
                            html: `
                                <div class="text-left" style="font-size: 14px;">
                                    <p><strong>✅ ${isEdit ? 'Cập nhật' : 'Thêm'} nhà cung cấp thành công!</strong></p>
                                    <hr>
                                    <p><strong>🏷️ Mã NCC:</strong> ${response.data.supplier_code}</p>
                                    <p><strong>🏢 Tên:</strong> ${response.data.name}</p>
                                    <p><strong>📞 SĐT:</strong> ${response.data.phone}</p>
                                    <p><strong>📧 Email:</strong> ${response.data.email || 'Không có'}</p>
                                    <hr>
                                    <p class="text-muted">Nhà cung cấp đã được ${isEdit ? 'cập nhật' : 'thêm vào'} hệ thống và sẵn sàng sử dụng.</p>
                                </div>
                            `,
                            icon: 'success',
                            confirmButtonText: addNew ? '➕ Thêm NCC khác' : '📋 Về danh sách',
                            showCancelButton: false, // Removed unnecessary cancel button
                            confirmButtonColor: '#28a745'
                        }).then((result) => {
                            if (addNew && result.isConfirmed) {
                                // Đặt lại form for new entry
                                resetForm();
                            } else {
                                // Go back to supplier list
                                window.location.href = 'quan_ly_nha_cung_cap.php';
                            }
                        });
                    } else {
                        Swal.fire({
                            title: '❌ Lỗi',
                            text: response.message || 'Có lỗi xảy ra khi lưu thông tin',
                            icon: 'error',
                            confirmButtonText: '✅ Đã hiểu'
                        });
                    }
                },
                error: function(xhr, status, error) {
                    Swal.fire({
                        title: '❌ Lỗi kết nối',
                        text: 'Không thể kết nối đến server. Vui lòng thử lại.',
                        icon: 'error',
                        confirmButtonText: '🔄 Thử lại'
                    });
                }
            });
        }

        // Đặt lại form for new entry
        function resetForm() {
            $('#supplierForm')[0].reset();
            $('#supplier_id').val('');
            
            // Tạo new supplier code
            $.ajax({
                url: 'api_tao_ma_nha_cung_cap.php',
                method: 'GET',
                success: function(response) {
                    if (response.success) {
                        $('#supplier_code').val(response.code);
                    }
                }
            });
            
            // Xóa validation feedback
            $('.validation-feedback').removeClass('valid invalid').text('');
            
            // Đặt lại status to active
            $('#status_active').prop('checked', true);
            
            // Focus on first field
            $('#name').focus();
        }

        // Xử lý unsaved changes warning
        let formChanged = false;
        $('#supplierForm input, #supplierForm select, #supplierForm textarea').on('input change', function() {
            formChanged = true;
        });
    </script>

</body>
</html>
