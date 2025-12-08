<?php
session_start();
require_once 'config/database.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    header('Location: login.html');
    exit();
}

$database = new Database();
$pdo = $database->getConnection();

$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

$userName = $_SESSION['full_name'] ?? 'User';
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Đổi mật khẩu - Smart Warehouse System</title>

    <!-- Custom fonts -->
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">

    <!-- Custom styles -->
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">

    <style>
        .password-strength {
            height: 5px;
            margin-top: 5px;
            border-radius: 3px;
            transition: all 0.3s;
        }
        .strength-weak { background: #dc3545; width: 33%; }
        .strength-medium { background: #ffc107; width: 66%; }
        .strength-strong { background: #28a745; width: 100%; }
        
        .requirement {
            font-size: 0.85rem;
            color: #6c757d;
            margin: 5px 0;
        }
        .requirement.met {
            color: #28a745;
        }
        .requirement.met i {
            color: #28a745;
        }
    </style>
</head>

<body id="page-top">

    <!-- Page Wrapper -->
    <div id="wrapper">

        <!-- Sidebar -->
        <?php include 'includes/thanh_ben.php'; ?>
        <!-- End of Sidebar -->

        <!-- Content Wrapper -->
        <div id="content-wrapper" class="d-flex flex-column">

            <!-- Main Content -->
            <div id="content">

                <!-- Topbar -->
                <?php include 'includes/thanh_tren.php'; ?>
                <!-- End of Topbar -->

                <!-- Begin Page Content -->
                <div class="container-fluid">

                    <!-- Page Heading -->
                    <h1 class="h3 mb-4 text-gray-800">Đổi Mật Khẩu</h1>

                    <div class="row justify-content-center">
                        <div class="col-lg-6">

                            <div class="card shadow mb-4">
                                <div class="card-header py-3 bg-primary text-white">
                                    <h6 class="m-0 font-weight-bold">
                                        <i class="fas fa-lock"></i> Thay đổi mật khẩu
                                    </h6>
                                </div>
                                <div class="card-body">
                                    
                                    <form id="changePasswordForm">
                                        <div class="form-group">
                                            <label for="currentPassword">
                                                <i class="fas fa-key"></i> Mật khẩu hiện tại
                                                <span class="text-danger">*</span>
                                            </label>
                                            <input type="password" class="form-control" id="currentPassword" 
                                                   name="current_password" required>
                                        </div>

                                        <div class="form-group">
                                            <label for="newPassword">
                                                <i class="fas fa-lock"></i> Mật khẩu mới
                                                <span class="text-danger">*</span>
                                            </label>
                                            <input type="password" class="form-control" id="newPassword" 
                                                   name="new_password" required>
                                            <div id="passwordStrength" class="password-strength"></div>
                                            
                                            <div class="mt-3">
                                                <small class="text-muted">Yêu cầu mật khẩu:</small>
                                                <div class="requirement" id="req-length">
                                                    <i class="fas fa-circle"></i> Tối thiểu 8 ký tự
                                                </div>
                                                <div class="requirement" id="req-uppercase">
                                                    <i class="fas fa-circle"></i> Ít nhất 1 chữ HOA
                                                </div>
                                                <div class="requirement" id="req-lowercase">
                                                    <i class="fas fa-circle"></i> Ít nhất 1 chữ thường
                                                </div>
                                                <div class="requirement" id="req-number">
                                                    <i class="fas fa-circle"></i> Ít nhất 1 số
                                                </div>
                                            </div>
                                        </div>

                                        <div class="form-group">
                                            <label for="confirmPassword">
                                                <i class="fas fa-check"></i> Xác nhận mật khẩu mới
                                                <span class="text-danger">*</span>
                                            </label>
                                            <input type="password" class="form-control" id="confirmPassword" 
                                                   name="confirm_password" required>
                                            <small id="passwordMatch" class="form-text"></small>
                                        </div>

                                        <div class="form-group mt-4">
                                            <button type="submit" class="btn btn-primary btn-block">
                                                <i class="fas fa-save"></i> Đổi mật khẩu
                                            </button>
                                            <a href="trang_chu.php" class="btn btn-secondary btn-block">
                                                <i class="fas fa-times"></i> Hủy
                                            </a>
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

            <!-- Footer -->
            <footer class="sticky-footer bg-white">
                <div class="container my-auto">
                    <div class="copyright text-center my-auto">
                        <span>Copyright &copy; Smart Warehouse System 2025</span>
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

    <!-- Bootstrap core JavaScript-->
    <script src="vendor/jquery/jquery.min.js"></script>
    <script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script>

    <!-- Core plugin JavaScript-->
    <script src="vendor/jquery-easing/jquery.easing.min.js"></script>

    <!-- Custom scripts for all pages-->
    <script src="js/sb-admin-2.min.js"></script>

    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        $(document).ready(function() {
            // Password strength checker
            $('#newPassword').on('input', function() {
                const password = $(this).val();
                checkPasswordRequirements(password);
                checkPasswordStrength(password);
            });

            // Password match checker
            $('#confirmPassword').on('input', function() {
                const newPassword = $('#newPassword').val();
                const confirmPassword = $(this).val();
                
                if (confirmPassword === '') {
                    $('#passwordMatch').text('').removeClass('text-danger text-success');
                } else if (newPassword === confirmPassword) {
                    $('#passwordMatch').text('✓ Mật khẩu khớp').removeClass('text-danger').addClass('text-success');
                } else {
                    $('#passwordMatch').text('✗ Mật khẩu không khớp').removeClass('text-success').addClass('text-danger');
                }
            });

            // Form submit
            $('#changePasswordForm').on('submit', function(e) {
                e.preventDefault();

                const currentPassword = $('#currentPassword').val();
                const newPassword = $('#newPassword').val();
                const confirmPassword = $('#confirmPassword').val();

                // Kiểm tra
                if (newPassword !== confirmPassword) {
                    Swal.fire('Lỗi!', 'Mật khẩu xác nhận không khớp', 'error');
                    return;
                }

                if (!validatePassword(newPassword)) {
                    Swal.fire('Lỗi!', 'Mật khẩu chưa đáp ứng yêu cầu', 'error');
                    return;
                }

                // Submit
                $.ajax({
                    url: 'api_quan_ly_nhan_vien.php',
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({
                        action: 'change-password',
                        current_password: currentPassword,
                        new_password: newPassword,
                        confirm_password: confirmPassword
                    }),
                    success: function(response) {
                        if (response.success) {
                            Swal.fire({
                                title: 'Thành công!',
                                text: response.message,
                                icon: 'success',
                                timer: 2000,
                                timerProgressBar: true
                            }).then(() => {
                                window.location.href = 'trang_chu.php';
                            });
                        } else {
                            Swal.fire('Lỗi!', response.message, 'error');
                        }
                    },
                    error: function() {
                        Swal.fire('Lỗi!', 'Không thể kết nối với server', 'error');
                    }
                });
            });
        });

        function checkPasswordRequirements(password) {
            // Length
            if (password.length >= 8) {
                $('#req-length').addClass('met');
            } else {
                $('#req-length').removeClass('met');
            }

            // Uppercase
            if (/[A-Z]/.test(password)) {
                $('#req-uppercase').addClass('met');
            } else {
                $('#req-uppercase').removeClass('met');
            }

            // Lowercase
            if (/[a-z]/.test(password)) {
                $('#req-lowercase').addClass('met');
            } else {
                $('#req-lowercase').removeClass('met');
            }

            // Number
            if (/[0-9]/.test(password)) {
                $('#req-number').addClass('met');
            } else {
                $('#req-number').removeClass('met');
            }
        }

        function checkPasswordStrength(password) {
            const strength = $('#passwordStrength');
            
            if (password.length === 0) {
                strength.removeClass('strength-weak strength-medium strength-strong');
                return;
            }

            let score = 0;
            if (password.length >= 8) score++;
            if (/[A-Z]/.test(password)) score++;
            if (/[a-z]/.test(password)) score++;
            if (/[0-9]/.test(password)) score++;
            if (/[^A-Za-z0-9]/.test(password)) score++;

            strength.removeClass('strength-weak strength-medium strength-strong');
            
            if (score < 3) {
                strength.addClass('strength-weak');
            } else if (score < 4) {
                strength.addClass('strength-medium');
            } else {
                strength.addClass('strength-strong');
            }
        }

        function validatePassword(password) {
            return password.length >= 8 &&
                   /[A-Z]/.test(password) &&
                   /[a-z]/.test(password) &&
                   /[0-9]/.test(password);
        }
    </script>

</body>
</html>
