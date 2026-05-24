<?php
// session_start();
require_once __DIR__ . '/../../config/database.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    header('Location: login.html');
    exit();
}

// Kiểm tra xem có cần đổi mật khẩu không
$database = new Database();
$pdo = $database->getConnection();

$stmt = $pdo->prepare("SELECT must_change_password FROM users WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

// Nếu không cần đổi mật khẩu, chuyển về trang chủ
if (!$user || !$user['must_change_password']) {
    header('Location: trang_chu.php');
    exit();
}

$userName = $_SESSION['full_name'] ?? 'User';
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Đổi mật khẩu bắt buộc - Smart Warehouse System</title>

    <!-- Custom fonts -->
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">

    <!-- Custom styles -->
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">

    <style>
        .password-requirements {
            background: #f8f9fc;
            border-left: 3px solid #4e73df;
            padding: 18px 20px;
            margin: 20px 0;
            font-size: 0.9rem;
            border-radius: 4px;
        }
        
        .password-requirements h6 {
            color: #5a5c69;
            font-weight: 600;
            margin-bottom: 12px;
            font-size: 0.95rem;
        }
        
        .password-requirements ul {
            margin-bottom: 0;
            padding-left: 0;
        }
        
        .password-requirements li {
            color: #5a5c69;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            line-height: 1.5;
        }
        
        .password-strength {
            height: 4px;
            border-radius: 2px;
            margin-top: 8px;
            transition: all 0.3s;
        }
        
        .strength-weak { background: #e74a3b; width: 33%; }
        .strength-medium { background: #f6c23e; width: 66%; }
        .strength-strong { background: #1cc88a; width: 100%; }
        
        .password-toggle {
            position: absolute;
            right: 15px;
            top: 38px;
            cursor: pointer;
            color: #858796;
        }
        
        .password-toggle:hover {
            color: #4e73df;
        }
        
        .requirement-check {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 18px;
            height: 18px;
            min-width: 18px;
            border-radius: 50%;
            text-align: center;
            margin-right: 10px;
            font-size: 12px;
            font-weight: bold;
            flex-shrink: 0;
        }
        
        .requirement-check.valid {
            background: #1cc88a;
            color: white;
        }
        
        .requirement-check.invalid {
            background: #e3e6f0;
            color: #858796;
        }
        
        .form-group label {
            color: #5a5c69;
            font-weight: 600;
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }
        
        .alert ul {
            margin-bottom: 0;
            padding-left: 20px;
        }
        
        .alert ul li {
            margin-bottom: 5px;
        }
    </style>
</head>

<body class="bg-gradient-primary">
    <div class="container">
        <!-- Outer Row -->
        <div class="row justify-content-center">
            <div class="col-xl-10 col-lg-12 col-md-9">
                <div class="card o-hidden border-0 shadow-lg my-5">
                    <div class="card-body p-0">
                        <div class="row">
                            <div class="col-lg-12">
                                <div class="p-5">
                                    <div class="text-center mb-4">
                                        <i class="fas fa-shield-alt fa-3x text-primary mb-3"></i>
                                        <h1 class="h4 text-gray-900 mb-2">Đổi mật khẩu bắt buộc</h1>
                                        <p class="text-muted">
                                            Xin chào, <strong><?php echo htmlspecialchars($userName); ?></strong>!
                                        </p>
                                    </div>
                    
                                    <div class="alert alert-warning">
                                        <strong><i class="fas fa-exclamation-triangle"></i> Yêu cầu bảo mật:</strong><br>
                                        Đây là lần đăng nhập đầu tiên. Bạn phải đổi mật khẩu tạm thời thành mật khẩu cố định để tiếp tục sử dụng hệ thống.
                                    </div>
                    
                                    <div class="alert alert-info">
                                        <strong><i class="fas fa-info-circle"></i> Lưu ý:</strong>
                                        <ul class="mb-0 mt-2">
                                            <li>Mật khẩu tạm thời chỉ có thể dùng <strong>1 lần duy nhất</strong></li>
                                            <li>Sau khi đổi mật khẩu thành công, tài khoản sẽ được <strong>kích hoạt</strong></li>
                                            <li>Không thể sử dụng lại mật khẩu tạm thời hoặc 3 mật khẩu gần nhất</li>
                                            <li>Mật khẩu mới sẽ được dùng cho tất cả các lần đăng nhập tiếp theo</li>
                                        </ul>
                                    </div>
                    
                                    <form class="user" id="changePasswordForm">
                                        <div class="form-group">
                                            <label for="currentPassword">Mật khẩu tạm thời (từ email)</label>
                                            <div class="position-relative">
                                                <input type="password" class="form-control form-control-user" id="currentPassword" 
                                                       name="current_password" required 
                                                       placeholder="Nhập mật khẩu hiện tại">
                                                <i class="fas fa-eye password-toggle" onclick="togglePassword('currentPassword')"></i>
                                            </div>
                                        </div>
                        
                                        <div class="form-group">
                                            <label for="newPassword">Mật khẩu mới</label>
                                            <div class="position-relative">
                                                <input type="password" class="form-control form-control-user" id="newPassword" 
                                                       name="new_password" required 
                                                       placeholder="Nhập mật khẩu mới"
                                                       oninput="checkPasswordStrength()">
                                                <i class="fas fa-eye password-toggle" onclick="togglePassword('newPassword')"></i>
                                                <div class="password-strength" id="passwordStrength"></div>
                                            </div>
                                        </div>
                        
                                        <div class="form-group">
                                            <label for="confirmPassword">Xác nhận mật khẩu mới</label>
                                            <div class="position-relative">
                                                <input type="password" class="form-control form-control-user" id="confirmPassword" 
                                                       name="confirm_password" required 
                                                       placeholder="Nhập lại mật khẩu mới"
                                                       oninput="checkPasswordMatch()">
                                                <i class="fas fa-eye password-toggle" onclick="togglePassword('confirmPassword')"></i>
                                            </div>
                                            <small id="matchMessage" class="form-text mt-2 d-block"></small>
                                        </div>
                        
                                        <div class="password-requirements">
                                            <h6>Yêu cầu mật khẩu:</h6>
                                            <ul id="requirements" style="list-style: none;">
                                                <li id="req-length">
                                                    <span class="requirement-check invalid">×</span>
                                                    Tối thiểu 8 ký tự
                                                </li>
                                                <li id="req-uppercase">
                                                    <span class="requirement-check invalid">×</span>
                                                    Có ít nhất 1 chữ hoa
                                                </li>
                                                <li id="req-lowercase">
                                                    <span class="requirement-check invalid">×</span>
                                                    Có ít nhất 1 chữ thường
                                                </li>
                                                <li id="req-number">
                                                    <span class="requirement-check invalid">×</span>
                                                    Có ít nhất 1 chữ số
                                                </li>
                                                <li id="req-special">
                                                    <span class="requirement-check invalid">×</span>
                                                    Có ít nhất 1 ký tự đặc biệt (@$!%*?&#)
                                                </li>
                                            </ul>
                                        </div>
                        
                                        <button type="submit" class="btn btn-primary btn-user btn-block">
                                            <i class="fas fa-save"></i> Đổi mật khẩu và tiếp tục
                                        </button>
                                    </form>
                    
                                    <hr>
                                    <div class="text-center">
                                        <a class="small" href="dang_xuat.php">
                                            <i class="fas fa-sign-out-alt"></i> Đăng xuất
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="vendor/jquery/jquery.min.js"></script>
    <script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        function togglePassword(inputId) {
            const input = document.getElementById(inputId);
            const icon = input.nextElementSibling;
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        function checkPasswordStrength() {
            const password = document.getElementById('newPassword').value;
            const strengthBar = document.getElementById('passwordStrength');
            
            // Kiểm tra requirements
            const requirements = {
                length: password.length >= 8,
                uppercase: /[A-Z]/.test(password),
                lowercase: /[a-z]/.test(password),
                number: /[0-9]/.test(password),
                special: /[@$!%*?&#]/.test(password)
            };
            
            // Cập nhật requirement checks
            updateRequirement('req-length', requirements.length);
            updateRequirement('req-uppercase', requirements.uppercase);
            updateRequirement('req-lowercase', requirements.lowercase);
            updateRequirement('req-number', requirements.number);
            updateRequirement('req-special', requirements.special);
            
            // Tính toán strength
            const validCount = Object.values(requirements).filter(v => v).length;
            
            strengthBar.className = 'password-strength';
            if (validCount === 0) {
                strengthBar.style.width = '0%';
            } else if (validCount <= 2) {
                strengthBar.classList.add('strength-weak');
            } else if (validCount <= 4) {
                strengthBar.classList.add('strength-medium');
            } else {
                strengthBar.classList.add('strength-strong');
            }
        }

        function updateRequirement(id, isValid) {
            const element = document.getElementById(id);
            const check = element.querySelector('.requirement-check');
            
            if (isValid) {
                check.classList.remove('invalid');
                check.classList.add('valid');
                check.innerHTML = '&#10003;';
            } else {
                check.classList.remove('valid');
                check.classList.add('invalid');
                check.innerHTML = '&times;';
            }
        }

        function checkPasswordMatch() {
            const newPass = document.getElementById('newPassword').value;
            const confirmPass = document.getElementById('confirmPassword').value;
            const message = document.getElementById('matchMessage');
            
            if (confirmPass.length === 0) {
                message.textContent = '';
                return;
            }
            
            if (newPass === confirmPass) {
                message.textContent = '✓ Mật khẩu khớp';
                message.className = 'form-text text-success';
            } else {
                message.textContent = '✗ Mật khẩu không khớp';
                message.className = 'form-text text-danger';
            }
        }

        // Form submission
        document.getElementById('changePasswordForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const currentPassword = document.getElementById('currentPassword').value;
            const newPassword = document.getElementById('newPassword').value;
            const confirmPassword = document.getElementById('confirmPassword').value;
            
            // Kiểm tra
            if (newPassword !== confirmPassword) {
                Swal.fire('Lỗi!', 'Mật khẩu mới và xác nhận không khớp', 'error');
                return;
            }
            
            // Kiểm tra password requirements
            const requirements = {
                length: newPassword.length >= 8,
                uppercase: /[A-Z]/.test(newPassword),
                lowercase: /[a-z]/.test(newPassword),
                number: /[0-9]/.test(newPassword),
                special: /[@$!%*?&#]/.test(newPassword)
            };
            
            if (!Object.values(requirements).every(v => v)) {
                Swal.fire('Lỗi!', 'Mật khẩu mới chưa đáp ứng đầy đủ yêu cầu', 'error');
                return;
            }
            
            if (currentPassword === newPassword) {
                Swal.fire('Lỗi!', 'Mật khẩu mới phải khác mật khẩu hiện tại', 'error');
                return;
            }
            
            // Gửi AJAX request
            $.ajax({
                url: 'api_quan_ly_nhan_vien.php',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    action: 'force-change-password',
                    old_password: currentPassword,
                    new_password: newPassword,
                    confirm_password: confirmPassword
                }),
                success: function(response) {
                    if (response.success) {
                        Swal.fire({
                            title: 'Thành công!',
                            text: 'Mật khẩu đã được thay đổi. Bạn sẽ được chuyển đến trang chủ.',
                            icon: 'success',
                            confirmButtonText: 'OK'
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
    </script>
</body>
</html>
