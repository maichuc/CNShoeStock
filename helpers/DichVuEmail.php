<?php
// Attempt to load PHPMailer from local vendor folder; disable email if missing.
$__phpmailer_base = __DIR__ . '/../vendor/phpmailer';
$__ex = $__phpmailer_base . '/src/Exception.php';
$__ph = $__phpmailer_base . '/src/PHPMailer.php';
$__sm = $__phpmailer_base . '/src/SMTP.php';

if (file_exists($__ex) && file_exists($__ph) && file_exists($__sm)) {
    require_once $__ex;
    require_once $__ph;
    require_once $__sm;
} else {
    define('EMAILSERVICE_DISABLED', true);
}

class EmailService {
    private $mail;
    private $enabled = true;
    private $pdo;
    
    public function __construct($pdo = null) {
        $this->pdo = $pdo;
        
        if (defined('EMAILSERVICE_DISABLED') && EMAILSERVICE_DISABLED === true) {
            $this->enabled = false;
            return;
        }
        
        // Load environment variables from .env file
        $this->loadEnvironmentVariables();
        
        $this->mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $this->configureMailer();
    }
    
    /**
     * Load environment variables from .env file
     */
    private function loadEnvironmentVariables() {
        $envFile = __DIR__ . '/../.env';
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos($line, '#') === 0) continue; // Skip comments
                if (strpos($line, '=') !== false) {
                    list($key, $value) = explode('=', $line, 2);
                    $key = trim($key);
                    $value = trim($value);
                    if (!getenv($key)) {
                        putenv("$key=$value");
                    }
                }
            }
        }
    }
    
    private function configureMailer() {
        if (!$this->enabled) {
            // Mailing disabled; pretend success without sending
            return true;
        }
        try {
            // Cấu hình SMTP Gmail với App Password
            $this->mail->isSMTP();
            $this->mail->Host       = getenv('MAIL_HOST') ?: 'smtp.gmail.com';
            $this->mail->SMTPAuth   = true;
            $this->mail->Username   = getenv('MAIL_USERNAME') ?: 'cnshoestockcompany@gmail.com';
            $this->mail->Password   = getenv('MAIL_PASSWORD') ?: 'daxa iqfy pxwa voao'; // App Password
            $this->mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $this->mail->Port       = (int)(getenv('MAIL_PORT') ?: 587);
            
            // Cấu hình bổ sung cho Gmail
            $this->mail->SMTPOptions = array(
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                )
            );
            
            // Enable verbose debug output (chỉ khi debug)
            if (getenv('APP_DEBUG') === 'true') {
                $this->mail->SMTPDebug = \PHPMailer\PHPMailer\SMTP::DEBUG_SERVER;
                $this->mail->Debugoutput = function($str, $level) {
                    error_log("PHPMailer: $str");
                };
            }
            
            // Cấu hình mã hóa
            $this->mail->CharSet = 'UTF-8';
            $this->mail->Encoding = 'base64';
            
            // Người gửi
            $fromAddress = getenv('MAIL_FROM_ADDRESS') ?: 'cnshoestockcompany@gmail.com';
            $fromName = getenv('MAIL_FROM_NAME') ?: 'CN Shoes Stock Company';
            $this->mail->setFrom($fromAddress, $fromName);
            
        } catch (Exception $e) {
            error_log("Email configuration error: " . $e->getMessage());
            throw new Exception("Không thể cấu hình email: " . $e->getMessage());
        }
    }
    
    /**
     * Gửi email thông tin đăng nhập cho admin mới
     */
    public function sendWelcomeEmail($adminEmail, $adminName, $username, $password, $warehouseName) {
        if (!$this->enabled) {
            return [
                'success' => false,
                'message' => 'Email service is disabled (PHPMailer not found)'
            ];
        }
        
        try {
            // Reset recipients for each send
            $this->mail->clearAddresses();
            $this->mail->clearAllRecipients();
            
            // Người nhận
            $this->mail->addAddress($adminEmail, $adminName);
            
            // Nội dung email
            $this->mail->isHTML(true);
            $this->mail->Subject = 'Tài khoản quản lý kho đã được tạo - CN Shoes Stock Company';
            
            $loginUrl = $this->getLoginUrl();
            
            $emailBody = $this->generateWelcomeEmailTemplate(
                $adminName, 
                $username, 
                $password, 
                $warehouseName, 
                $loginUrl
            );
            
            $this->mail->Body = $emailBody;
            $this->mail->AltBody = $this->generatePlainTextEmail($adminName, $username, $password, $warehouseName, $loginUrl);
            
            // Send email
            $sendResult = $this->mail->send();
            
            // Log to database if pdo is available
            if ($this->pdo) {
                $this->logEmailToDatabase($adminEmail, $adminName, 'welcome_employee', $sendResult ? 'sent' : 'failed');
            }
            
            return [
                'success' => true,
                'message' => 'Email sent successfully'
            ];
            
        } catch (Exception $e) {
            error_log("Email sending failed: " . $this->mail->ErrorInfo);
            error_log("Exception: " . $e->getMessage());
            
            // Log failure to database if pdo is available
            if ($this->pdo) {
                $this->logEmailToDatabase($adminEmail, $adminName, 'welcome_employee', 'failed', $e->getMessage());
            }
            
            return [
                'success' => false,
                'message' => 'Mailer Error: ' . $this->mail->ErrorInfo . ' | Exception: ' . $e->getMessage()
            ];
        }
    }
    
    private function getLoginUrl() {
        // Check if running from web context
        if (!isset($_SERVER['HTTP_HOST'])) {
            // Running from CLI or background - use default URL
            return 'http://localhost/cmix_warehouse23_11/login.html';
        }
        
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        
        // Lấy đường dẫn thư mục gốc của project
        $scriptPath = $_SERVER['SCRIPT_NAME'];
        $projectRoot = dirname($scriptPath);
        
        // Nếu script ở trong subfolder (như /auth/xu_ly_dang_ky.php), 
        // thì cần lùi về root folder
        $pathParts = explode('/', trim($projectRoot, '/'));
        
        // Nếu có nhiều hơn 1 phần (ví dụ: warehouse5/auth), lấy phần đầu
        if (count($pathParts) > 1) {
            $projectRoot = '/' . $pathParts[0];
        } else if ($projectRoot === '' || $projectRoot === '.') {
            // Nếu ở root, lấy tên folder từ document root
            $docRoot = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']); // Chuẩn hóa
            $currentDir = str_replace('\\', '/', dirname(__DIR__)); // c:/xampp/htdocs/warehouse5
            
            // Loại bỏ document root để lấy project folder
            $projectFolder = str_replace($docRoot, '', $currentDir);
            
            // Đảm bảo bắt đầu bằng /
            if (substr($projectFolder, 0, 1) !== '/') {
                $projectFolder = '/' . $projectFolder;
            }
            
            $projectRoot = $projectFolder;
        }
        
        $loginUrl = $protocol . '://' . $host . $projectRoot . '/login.html';
        return $loginUrl;
    }
    
    private function generateWelcomeEmailTemplate($adminName, $username, $password, $warehouseName, $loginUrl) {
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #4e73df; color: white; padding: 20px; text-align: center; border-radius: 5px 5px 0 0; }
                .content { background: #f8f9fc; padding: 30px; border-radius: 0 0 5px 5px; }
                .info-box { background: white; padding: 20px; margin: 20px 0; border-radius: 5px; border-left: 4px solid #4e73df; }
                .login-info { background: #e3f2fd; padding: 15px; border-radius: 5px; margin: 15px 0; font-family: monospace; }
                .button { display: inline-block; background: #4e73df; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; margin: 20px 0; }
                .warning { background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 5px; margin: 15px 0; }
                .footer { text-align: center; margin-top: 30px; color: #666; font-size: 12px; }
                .logo { font-size: 24px; font-weight: bold; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <div class='logo'>👟 CN Shoes Stock Company</div>
                    <h1>Smart Warehouse System</h1>
                    <p>Chào mừng bạn đến với hệ thống quản lý kho giày thông minh!</p>
                </div>
                
                <div class='content'>
                    <h2>Xin chào {$adminName}!</h2>
                    <p>Tài khoản quản lý kho của bạn đã được tạo thành công tại <strong>CN Shoes Stock Company</strong>. 
                    Bạn hiện là <strong>Administrator</strong> của kho <strong>{$warehouseName}</strong>.</p>
                    
                    <div class='info-box'>
                        <h3>📋 Thông tin đăng nhập của bạn:</h3>
                        <div class='login-info'>
                            <p><strong>🏢 Công ty:</strong> CN Shoes Stock Company</p>
                            <p><strong>🏬 Kho quản lý:</strong> {$warehouseName}</p>
                            <p><strong>👤 Tên đăng nhập:</strong> {$username}</p>
                            <p><strong>🔑 Mật khẩu:</strong> {$password}</p>
                            <p><strong>🎯 Quyền hạn:</strong> Administrator (Quản lý toàn quyền)</p>
                        </div>
                    </div>
                    
                    <div class='warning'>
                        <h4>⚠️ Lưu ý bảo mật quan trọng:</h4>
                        <ul>
                            <li>🔄 Vui lòng <strong>đổi mật khẩu</strong> ngay sau lần đăng nhập đầu tiên</li>
                            <li>🔒 Không chia sẻ thông tin đăng nhập với bất kỳ ai</li>
                            <li>💪 Sử dụng mật khẩu mạnh có ít nhất 8 ký tự, bao gồm chữ hoa, chữ thường, số và ký tự đặc biệt</li>
                            <li>🚪 Luôn đăng xuất sau khi sử dụng xong</li>
                            <li>🚫 Không đăng nhập trên máy tính công cộng</li>
                        </ul>
                    </div>
                    
                    <div style='text-align: center;'>
                        <a href='{$loginUrl}' class='button'>🚀 Đăng nhập ngay bây giờ</a>
                    </div>
                    
                    <div class='info-box'>
                        <h4>🎯 Với quyền Administrator, bạn có thể:</h4>
                        <div style='display: grid; grid-template-columns: 1fr 1fr; gap: 10px;'>
                            <div>
                                <p>✅ Quản lý toàn bộ kho hàng</p>
                                <p>✅ Tạo và quản lý tài khoản nhân viên</p>
                                <p>✅ Quản lý sản phẩm và danh mục</p>
                                <p>✅ Quản lý nhà cung cấp</p>
                            </div>
                            <div>
                                <p>✅ Xử lý đơn hàng và khách hàng</p>
                                <p>✅ Quản lý xuất nhập kho</p>
                                <p>✅ Xem báo cáo và thống kê</p>
                                <p>✅ Cấu hình hệ thống</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class='info-box' style='background: #e8f5e8;'>
                        <h4>📞 Hỗ trợ kỹ thuật:</h4>
                        <p>Nếu bạn gặp khó khăn trong quá trình sử dụng, vui lòng liên hệ:</p>
                        <p><strong>📧 Email:</strong> support@cnshoesstock.com</p>
                        <p><strong>☎️ Hotline:</strong> 1900-xxxx (8:00 - 18:00, T2-T6)</p>
                    </div>
                </div>
                
                <div class='footer'>
                    <p><strong>CN Shoes Stock Company</strong> - Hệ thống quản lý kho giày thông minh</p>
                    <p>Email này được gửi tự động từ hệ thống. Vui lòng không trả lời email này.</p>
                    <p>© 2024 CN Shoes Stock Company. All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>";
    }
    
    private function generatePlainTextEmail($adminName, $username, $password, $warehouseName, $loginUrl) {
        return "
CN SHOES STOCK COMPANY - Smart Warehouse System
==============================================

Xin chào {$adminName}!

Tài khoản quản lý kho của bạn đã được tạo thành công.

THÔNG TIN ĐĂNG NHẬP:
- Công ty: CN Shoes Stock Company
- Kho quản lý: {$warehouseName}
- Tên đăng nhập: {$username}
- Mật khẩu: {$password}
- Quyền hạn: Administrator

LINK ĐĂNG NHẬP: {$loginUrl}

LƯU Ý BẢO MẬT:
- Vui lòng đổi mật khẩu ngay sau lần đăng nhập đầu tiên
- Không chia sẻ thông tin đăng nhập với người khác
- Sử dụng mật khẩu mạnh có ít nhất 8 ký tự
- Luôn đăng xuất sau khi sử dụng xong

HỖ TRỢ KỸ THUẬT:
Email: support@cnshoesstock.com
Hotline: 1900-xxxx (8:00-18:00, T2-T6)

---
CN Shoes Stock Company
© 2024 All rights reserved.
        ";
    }
    
    /**
     * Log email activity to database
     */
    private function logEmailToDatabase($recipient, $recipientName, $emailType, $status, $errorMessage = null) {
        if (!$this->pdo) {
            return;
        }
        
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO email_logs 
                (recipient_email, recipient_name, email_type, status, error_message, sent_at)
                VALUES 
                (:recipient, :name, :type, :status, :error, NOW())
            ");
            
            $stmt->execute([
                ':recipient' => $recipient,
                ':name' => $recipientName,
                ':type' => $emailType,
                ':status' => $status,
                ':error' => $errorMessage
            ]);
        } catch (PDOException $e) {
            // Silently fail - don't break email sending if logging fails
            error_log("Failed to log email to database: " . $e->getMessage());
        }
    }
}
?>