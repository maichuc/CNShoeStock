<?php
/**
 * Email Service Class
 * Xử lý tất cả chức năng gửi email cho hệ thống quản lý nhân viên
 * 
 * Features:
 * - Email templates (Welcome, OTP, Password Changed, etc.)
 * - Email queue system
 * - Rate limiting
 * - Retry mechanism
 * - PHPMailer integration
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/database.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class EmailService {
    private $pdo;
    private $mailer;
    
    // Email configuration - ĐỒNG BỘ VỚI helpers/DichVuEmail.php
    private $smtp_host = 'smtp.gmail.com';
    private $smtp_port = 587;
    private $smtp_username = 'cnshoestockcompany@gmail.com'; // Email đang hoạt động
    private $smtp_password = 'daxa iqfy pxwa voao'; // App Password thật từ Google
    private $from_email = 'cnshoestockcompany@gmail.com';
    private $from_name = 'CN Shoes Stock Company';
    
    // Rate limiting: 10 emails/phút
    private $rate_limit = 10;
    private $rate_window = 60; // seconds
    
    public function __construct($pdo = null) {
        if ($pdo === null) {
            $database = new Database();
            $this->pdo = $database->getConnection();
        } else {
            $this->pdo = $pdo;
        }
        
        $this->initMailer();
    }
    
    /**
     * Khởi tạo PHPMailer
     */
    private function initMailer() {
        $this->mailer = new PHPMailer(true);
        
        try {
            // Server settings
            $this->mailer->isSMTP();
            $this->mailer->Host = $this->smtp_host;
            $this->mailer->SMTPAuth = true;
            $this->mailer->Username = $this->smtp_username;
            $this->mailer->Password = $this->smtp_password;
            $this->mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $this->mailer->Port = $this->smtp_port;
            $this->mailer->CharSet = 'UTF-8';
            
            // SMTP options cho Gmail - QUAN TRỌNG
            $this->mailer->SMTPOptions = array(
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                )
            );
            
            // Sender
            $this->mailer->setFrom($this->from_email, $this->from_name);
        } catch (Exception $e) {
            error_log("PHPMailer init error: " . $e->getMessage());
        }
    }
    
    /**
     * UC-NV-01: Gửi email chào mừng kèm thông tin đăng nhập
     */
    public function sendWelcomeEmail($recipientEmail, $recipientName, $username, $temporaryPassword, $warehouseName = '') {
        $subject = "Chào mừng bạn đến với Smart Warehouse System";
        
        $body = $this->getWelcomeTemplate($recipientName, $username, $temporaryPassword, $warehouseName);
        
        // Gửi email trực tiếp với logging
        return $this->sendEmailNow(
            $recipientEmail,
            $recipientName,
            $subject,
            $body
        );
    }
    
    /**
     * UC-NV-01B: Gửi email chào mừng cho bulk import
     */
    public function sendBulkWelcomeEmail($recipientEmail, $recipientName, $username, $temporaryPassword, $warehouseName = '', $importId = null) {
        $subject = "Tài khoản của bạn đã được tạo - Smart Warehouse System";
        
        $body = $this->getWelcomeTemplate($recipientName, $username, $temporaryPassword, $warehouseName);
        
        // Gửi trực tiếp
        return $this->sendEmailNow(
            $recipientEmail,
            $recipientName,
            $subject,
            $body
        );
    }
    
    /**
     * (REMOVED) UC-NV-03: sendOTPEmail - Đã xóa chức năng gửi OTP
     */
    
    /**
     * UC-NV-04, UC-NV-05: Thông báo mật khẩu đã được thay đổi
     */
    public function sendPasswordChangedEmail($recipientEmail, $recipientName, $changeType = 'manual') {
        $subject = "Mật khẩu của bạn đã được thay đổi - Smart Warehouse System";
        
        $body = $this->getPasswordChangedTemplate($recipientName, $changeType);
        
        // Gửi trực tiếp
        return $this->sendEmailNow(
            $recipientEmail,
            $recipientName,
            $subject,
            $body
        );
    }
    
    /**
     * Admin Reset Password - Gửi mật khẩu tạm thời mới
     */
    public function sendPasswordResetByAdminEmail($recipientEmail, $recipientName, $username, $temporaryPassword) {
        $subject = "Mật khẩu của bạn đã được reset - Smart Warehouse System";
        
        $body = $this->getPasswordResetByAdminTemplate($recipientName, $username, $temporaryPassword);
        
        // Gửi trực tiếp
        return $this->sendEmailNow(
            $recipientEmail,
            $recipientName,
            $subject,
            $body
        );
    }
    
    /**
     * UC-NV-07: Thông báo tài khoản bị vô hiệu hóa
     */
    public function sendAccountDeactivatedEmail($recipientEmail, $recipientName, $reason = '') {
        $subject = "Tài khoản của bạn đã bị vô hiệu hóa - Smart Warehouse System";
        
        $body = $this->getAccountDeactivatedTemplate($recipientName, $reason);
        
        // Gửi trực tiếp thay vì queue
        return $this->sendEmailNow(
            $recipientEmail,
            $recipientName,
            $subject,
            $body
        );
    }
    
    /**
     * UC-NV-07: Thông báo tài khoản được kích hoạt lại
     */
    public function sendAccountActivatedEmail($recipientEmail, $recipientName) {
        $subject = "Tài khoản của bạn đã được kích hoạt - Smart Warehouse System";
        
        $body = $this->getAccountActivatedTemplate($recipientName);
        
        // Gửi ngay lập tức
        return $this->sendEmailNow(
            $recipientEmail,
            $recipientName,
            $subject,
            $body
        );
    }
    
    /**
     * UC-NV-06: Thông báo vai trò đã thay đổi
     */
    public function sendRoleChangedEmail($recipientEmail, $recipientName, $oldRole, $newRole) {
        $subject = "Vai trò của bạn đã được cập nhật - Smart Warehouse System";
        
        $body = $this->getRoleChangedTemplate($recipientName, $oldRole, $newRole);
        
        // Gửi trực tiếp thay vì queue
        return $this->sendEmailNow(
            $recipientEmail,
            $recipientName,
            $subject,
            $body
        );
    }
    
    /**
     * UC-NV-01B: Gửi báo cáo kết quả bulk import cho quản lý
     */
    public function sendBulkImportResultEmail($recipientEmail, $recipientName, $importStats) {
        $subject = "Kết quả import nhân viên hàng loạt - Smart Warehouse System";
        
        $body = $this->getBulkImportResultTemplate($recipientName, $importStats);
        
        // Gửi trực tiếp thay vì queue
        return $this->sendEmailNow(
            $recipientEmail,
            $recipientName,
            $subject,
            $body
        );
    }
    
    /**
     * Thêm email vào hàng đợi
     */
    private function queueEmail($recipientEmail, $recipientName, $subject, $bodyHtml, $emailType, $relatedUserId = null, $relatedImportId = null) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO email_queue 
                (recipient_email, recipient_name, subject, body_html, email_type, related_user_id, related_import_id, status, created_at)
                VALUES 
                (:recipient_email, :recipient_name, :subject, :body_html, :email_type, :related_user_id, :related_import_id, 'pending', NOW())
            ");
            
            $stmt->execute([
                ':recipient_email' => $recipientEmail,
                ':recipient_name' => $recipientName,
                ':subject' => $subject,
                ':body_html' => $bodyHtml,
                ':email_type' => $emailType,
                ':related_user_id' => $relatedUserId,
                ':related_import_id' => $relatedImportId
            ]);
            
            return [
                'success' => true,
                'queue_id' => $this->pdo->lastInsertId(),
                'message' => 'Email đã được thêm vào hàng đợi'
            ];
        } catch (PDOException $e) {
            error_log("Queue email error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Không thể thêm email vào hàng đợi'
            ];
        }
    }
    
    /**
     * Xử lý hàng đợi email - Gửi emails pending
     * Gọi định kỳ bởi cron job hoặc background worker
     */
    public function processEmailQueue($batchSize = 10) {
        // Rate limiting check
        if (!$this->checkRateLimit()) {
            return [
                'success' => false,
                'message' => 'Đã đạt giới hạn gửi email. Vui lòng thử lại sau.'
            ];
        }
        
        try {
            // Lấy emails pending
            $stmt = $this->pdo->prepare("
                SELECT * FROM email_queue 
                WHERE status = 'pending' 
                AND attempts < max_attempts
                ORDER BY created_at ASC
                LIMIT :batch_size
            ");
            $stmt->bindValue(':batch_size', $batchSize, PDO::PARAM_INT);
            $stmt->execute();
            $emails = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $sent = 0;
            $failed = 0;
            
            foreach ($emails as $email) {
                // Update status to sending
                $this->updateEmailStatus($email['queue_id'], 'sending');
                
                // Send email
                $result = $this->sendEmailNow(
                    $email['recipient_email'],
                    $email['recipient_name'],
                    $email['subject'],
                    $email['body_html']
                );
                
                if ($result['success']) {
                    $this->updateEmailStatus($email['queue_id'], 'sent', null, true);
                    $sent++;
                } else {
                    $attempts = $email['attempts'] + 1;
                    $status = ($attempts >= $email['max_attempts']) ? 'failed' : 'pending';
                    $this->updateEmailStatus($email['queue_id'], $status, $result['message'], false, $attempts);
                    $failed++;
                }
                
                // Rate limiting: Sleep between emails
                sleep(6); // 10 emails/minute = 1 email/6 seconds
            }
            
            return [
                'success' => true,
                'sent' => $sent,
                'failed' => $failed,
                'total' => count($emails)
            ];
        } catch (PDOException $e) {
            error_log("Process email queue error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Lỗi xử lý hàng đợi email'
            ];
        }
    }
    
    /**
     * Gửi email ngay lập tức (không qua queue)
     */
    private function sendEmailNow($to, $toName, $subject, $bodyHtml) {
        try {
            $this->mailer->clearAddresses();
            $this->mailer->clearAllRecipients();
            $this->mailer->addAddress($to, $toName);
            $this->mailer->Subject = $subject;
            $this->mailer->isHTML(true);
            $this->mailer->Body = $bodyHtml;
            
            $this->mailer->send();
            
            // Log success to database
            $this->logEmailToDatabase($to, $toName, 'sent', null);
            
            return [
                'success' => true,
                'message' => 'Email đã được gửi thành công'
            ];
        } catch (Exception $e) {
            // Log failure to database
            $this->logEmailToDatabase($to, $toName, 'failed', $this->mailer->ErrorInfo);
            
            return [
                'success' => false,
                'message' => $this->mailer->ErrorInfo
            ];
        }
    }
    
    /**
     * Log email activity to database
     */
    private function logEmailToDatabase($recipientEmail, $recipientName, $status, $errorMessage = null) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO email_logs 
                (recipient_email, recipient_name, email_type, status, error_message, sent_at)
                VALUES 
                (:recipient, :name, 'notification', :status, :error, NOW())
            ");
            
            $stmt->execute([
                ':recipient' => $recipientEmail,
                ':name' => $recipientName,
                ':status' => $status,
                ':error' => $errorMessage
            ]);
        } catch (PDOException $e) {
            // Silently fail - don't break email sending if logging fails
            error_log("Failed to log email to database: " . $e->getMessage());
        }
    }
    
    /**
     * Gửi email từ queue trong background (async)
     * Sử dụng exec để chạy PHP script riêng không blocking
     */
    private function sendQueuedEmailAsync($queueId) {
        // Tạo background process để gửi email
        $scriptPath = __DIR__ . '/../process_single_email.php';
        
        // Windows: sử dụng start /B
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            pclose(popen("start /B php \"$scriptPath\" $queueId", "r"));
        } else {
            // Linux/Mac: sử dụng &
            exec("php \"$scriptPath\" $queueId > /dev/null 2>&1 &");
        }
        
        return true;
    }
    
    /**
     * Update trạng thái email trong queue
     */
    private function updateEmailStatus($queueId, $status, $error = null, $setSentAt = false, $attempts = null) {
        try {
            $sql = "UPDATE email_queue SET status = :status, updated_at = NOW()";
            $params = [':status' => $status, ':queue_id' => $queueId];
            
            if ($error !== null) {
                $sql .= ", last_error = :error";
                $params[':error'] = $error;
            }
            
            if ($setSentAt) {
                $sql .= ", sent_at = NOW()";
            }
            
            if ($attempts !== null) {
                $sql .= ", attempts = :attempts";
                $params[':attempts'] = $attempts;
            }
            
            $sql .= " WHERE queue_id = :queue_id";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
        } catch (PDOException $e) {
            error_log("Update email status error: " . $e->getMessage());
        }
    }
    
    /**
     * Check rate limit
     */
    private function checkRateLimit() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as count 
                FROM email_queue 
                WHERE status = 'sent' 
                AND sent_at >= DATE_SUB(NOW(), INTERVAL :window SECOND)
            ");
            $stmt->execute([':window' => $this->rate_window]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $result['count'] < $this->rate_limit;
        } catch (PDOException $e) {
            return true; // Allow on error
        }
    }
    
    // ==================== EMAIL TEMPLATES ====================
    
    /**
     * Helper: Lấy login URL động dựa trên cấu hình hiện tại
     */
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
        $projectRoot = dirname(dirname($scriptPath));
        
        // Nếu script chạy từ root (như api_*.php), lấy folder name từ document root
        if ($projectRoot === '/' || $projectRoot === '\\') {
            // Lấy tên thư mục project từ đường dẫn tuyệt đối
            $docRoot = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']); // Chuẩn hóa về forward slash
            $currentDir = str_replace('\\', '/', dirname(__DIR__)); // c:/xampp/htdocs/warehouse5
            
            // Loại bỏ document root để chỉ lấy phần project folder
            $projectFolder = str_replace($docRoot, '', $currentDir);
            
            // Đảm bảo bắt đầu bằng /
            if (substr($projectFolder, 0, 1) !== '/') {
                $projectFolder = '/' . $projectFolder;
            }
            
            $loginUrl = $protocol . '://' . $host . $projectFolder . '/login.html';
        } else {
            $loginUrl = $protocol . '://' . $host . $projectRoot . '/login.html';
        }
        
        return $loginUrl;
    }
    
    /**
     * Template: Welcome Email
     */
    private function getWelcomeTemplate($name, $username, $password, $warehouseName) {
        $loginUrl = $this->getLoginUrl();
        
        return "
<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #4e73df; color: white; padding: 20px; text-align: center; border-radius: 5px 5px 0 0; }
        .content { background: #f8f9fc; padding: 30px; border: 1px solid #e3e6f0; }
        .credentials { background: white; padding: 20px; margin: 20px 0; border-left: 4px solid #4e73df; }
        .credential-item { margin: 10px 0; }
        .credential-label { font-weight: bold; color: #5a5c69; }
        .credential-value { font-family: monospace; background: #f1f3f5; padding: 5px 10px; display: inline-block; margin-top: 5px; }
        .warning { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; }
        .button { display: inline-block; padding: 12px 30px; background: #4e73df; color: white; text-decoration: none; border-radius: 5px; margin: 20px 0; }
        .footer { text-align: center; padding: 20px; color: #858796; font-size: 12px; }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h1>🎉 Chào mừng đến với Smart Warehouse System!</h1>
        </div>
        <div class='content'>
            <p>Xin chào <strong>{$name}</strong>,</p>
            <p>Tài khoản của bạn đã được tạo thành công trong hệ thống Smart Warehouse System" . 
            ($warehouseName ? " tại kho <strong>{$warehouseName}</strong>" : "") . ".</p>
            
            <div class='credentials'>
                <h3>📋 Thông tin đăng nhập:</h3>
                <div class='credential-item'>
                    <div class='credential-label'>Tên đăng nhập:</div>
                    <div class='credential-value'>{$username}</div>
                </div>
                <div class='credential-item'>
                    <div class='credential-label'>Mật khẩu tạm thời:</div>
                    <div class='credential-value'>{$password}</div>
                </div>
            </div>
            
            <div class='warning'>
                <strong>⚠️ Quan trọng:</strong>
                <ul>
                    <li>Đây là mật khẩu tạm thời, bạn <strong>BẮT BUỘC</strong> phải đổi mật khẩu khi đăng nhập lần đầu</li>
                    <li>Vui lòng không chia sẻ thông tin đăng nhập với bất kỳ ai</li>
                    <li>Mật khẩu mới phải có ít nhất 8 ký tự, bao gồm chữ hoa, chữ thường và số</li>
                </ul>
            </div>
            
            <center>
                <a href='{$loginUrl}' class='button'>Đăng nhập ngay</a>
            </center>
            
            <p style='margin-top: 30px;'>Nếu bạn cần hỗ trợ, vui lòng liên hệ với quản lý kho của bạn.</p>
            
            <p>Trân trọng,<br><strong>Smart Warehouse System Team</strong></p>
        </div>
        <div class='footer'>
            <p>Email này được gửi tự động. Vui lòng không trả lời email này.</p>
            <p>&copy; " . date('Y') . " Smart Warehouse System. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
        ";
    }
    
    /**
     * Template: Admin Reset Password Email
     */
    private function getPasswordResetByAdminTemplate($name, $username, $temporaryPassword) {
        $loginUrl = $this->getLoginUrl();
        
        return "
<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #e74a3b; color: white; padding: 20px; text-align: center; border-radius: 5px 5px 0 0; }
        .content { background: #f8f9fc; padding: 30px; border: 1px solid #e3e6f0; }
        .credentials { background: white; padding: 20px; margin: 20px 0; border-left: 4px solid #e74a3b; }
        .credential-item { margin: 10px 0; }
        .credential-label { font-weight: bold; color: #5a5c69; }
        .credential-value { font-family: monospace; background: #f1f3f5; padding: 5px 10px; display: inline-block; margin-top: 5px; }
        .warning { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; }
        .alert { background: #f8d7da; border-left: 4px solid #e74a3b; padding: 15px; margin: 20px 0; }
        .button { display: inline-block; padding: 12px 30px; background: #e74a3b; color: white; text-decoration: none; border-radius: 5px; margin: 20px 0; }
        .footer { text-align: center; padding: 20px; color: #858796; font-size: 12px; }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h1>🔄 Mật khẩu đã được reset</h1>
        </div>
        <div class='content'>
            <p>Xin chào <strong>{$name}</strong>,</p>
            
            <div class='alert'>
                <strong>⚠️ Thông báo bảo mật:</strong>
                <p>Quản trị viên đã reset mật khẩu của bạn. Nếu bạn không yêu cầu thay đổi này, vui lòng liên hệ ngay với quản lý để bảo vệ tài khoản.</p>
            </div>
            
            <p>Mật khẩu tài khoản của bạn đã được reset bởi quản trị viên hệ thống. Đây là thông tin đăng nhập mới:</p>
            
            <div class='credentials'>
                <h3>📋 Thông tin đăng nhập:</h3>
                <div class='credential-item'>
                    <div class='credential-label'>Tên đăng nhập:</div>
                    <div class='credential-value'>{$username}</div>
                </div>
                <div class='credential-item'>
                    <div class='credential-label'>Mật khẩu tạm thời:</div>
                    <div class='credential-value'>{$temporaryPassword}</div>
                </div>
            </div>
            
            <div class='warning'>
                <strong>⚠️ Quan trọng:</strong>
                <ul>
                    <li>Đây là mật khẩu tạm thời, bạn <strong>BẮT BUỘC</strong> phải đổi mật khẩu khi đăng nhập lần đầu</li>
                    <li>Mật khẩu tạm thời này chỉ có thể sử dụng <strong>1 lần duy nhất</strong></li>
                    <li>Vui lòng không chia sẻ thông tin đăng nhập với bất kỳ ai</li>
                    <li>Mật khẩu mới phải có ít nhất 8 ký tự, bao gồm chữ hoa, chữ thường, số và ký tự đặc biệt</li>
                </ul>
            </div>
            
            <center>
                <a href='{$loginUrl}' class='button'>Đăng nhập ngay</a>
            </center>
            
            <p style='margin-top: 30px;'>Nếu bạn cần hỗ trợ, vui lòng liên hệ với quản lý kho của bạn.</p>
            
            <p>Trân trọng,<br><strong>Smart Warehouse System Team</strong></p>
        </div>
        <div class='footer'>
            <p>Email này được gửi tự động. Vui lòng không trả lời email này.</p>
            <p>&copy; " . date('Y') . " Smart Warehouse System. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
        ";
    }
    
    /**
     * (REMOVED) Template: OTP Email - Đã xóa template OTP
     */
    
    /**
     * Template: Password Changed
     */
    private function getPasswordChangedTemplate($name, $changeType) {
        $typeText = ($changeType === 'reset') ? 'đặt lại' : 'thay đổi';
        
        return "
<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #36b9cc; color: white; padding: 20px; text-align: center; border-radius: 5px 5px 0 0; }
        .content { background: #f8f9fc; padding: 30px; border: 1px solid #e3e6f0; }
        .success { background: #d4edda; border-left: 4px solid #28a745; padding: 15px; margin: 20px 0; }
        .info { background: #d1ecf1; border-left: 4px solid #17a2b8; padding: 15px; margin: 20px 0; }
        .footer { text-align: center; padding: 20px; color: #858796; font-size: 12px; }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h1>✅ Mật khẩu đã được cập nhật</h1>
        </div>
        <div class='content'>
            <p>Xin chào <strong>{$name}</strong>,</p>
            
            <div class='success'>
                <p><strong>Mật khẩu của bạn đã được {$typeText} thành công.</strong></p>
                <p>Thời gian: " . date('d/m/Y H:i:s') . "</p>
            </div>
            
            <div class='info'>
                <strong>⚠️ Bạn không thực hiện thay đổi này?</strong>
                <p>Nếu bạn không {$typeText} mật khẩu, vui lòng liên hệ ngay với quản lý kho để được hỗ trợ.</p>
            </div>
            
            <p>Trân trọng,<br><strong>Smart Warehouse System Team</strong></p>
        </div>
        <div class='footer'>
            <p>Email này được gửi tự động. Vui lòng không trả lời email này.</p>
            <p>&copy; " . date('Y') . " Smart Warehouse System. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
        ";
    }
    
    /**
     * Template: Account Deactivated
     */
    private function getAccountDeactivatedTemplate($name, $reason) {
        return "
<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #e74a3b; color: white; padding: 20px; text-align: center; border-radius: 5px 5px 0 0; }
        .content { background: #f8f9fc; padding: 30px; border: 1px solid #e3e6f0; }
        .warning { background: #f8d7da; border-left: 4px solid #dc3545; padding: 15px; margin: 20px 0; }
        .footer { text-align: center; padding: 20px; color: #858796; font-size: 12px; }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h1>⛔ Tài khoản đã bị vô hiệu hóa</h1>
        </div>
        <div class='content'>
            <p>Xin chào <strong>{$name}</strong>,</p>
            
            <div class='warning'>
                <p><strong>Tài khoản của bạn đã bị vô hiệu hóa.</strong></p>
                " . ($reason ? "<p><strong>Lý do:</strong> {$reason}</p>" : "") . "
                <p>Thời gian: " . date('d/m/Y H:i:s') . "</p>
            </div>
            
            <p>Bạn sẽ không thể đăng nhập vào hệ thống cho đến khi tài khoản được kích hoạt lại.</p>
            <p>Nếu bạn cho rằng đây là sự nhầm lẫn, vui lòng liên hệ với quản lý kho của bạn.</p>
            
            <p>Trân trọng,<br><strong>Smart Warehouse System Team</strong></p>
        </div>
        <div class='footer'>
            <p>Email này được gửi tự động. Vui lòng không trả lời email này.</p>
            <p>&copy; " . date('Y') . " Smart Warehouse System. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
        ";
    }
    
    /**
     * Template: Account Activated
     */
    private function getAccountActivatedTemplate($name) {
        $loginUrl = $this->getLoginUrl();
        
        return "
<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #1cc88a; color: white; padding: 20px; text-align: center; border-radius: 5px 5px 0 0; }
        .content { background: #f8f9fc; padding: 30px; border: 1px solid #e3e6f0; }
        .success { background: #d4edda; border-left: 4px solid #28a745; padding: 15px; margin: 20px 0; }
        .button { display: inline-block; padding: 12px 30px; background: #1cc88a; color: white; text-decoration: none; border-radius: 5px; margin: 20px 0; }
        .footer { text-align: center; padding: 20px; color: #858796; font-size: 12px; }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h1>✅ Tài khoản đã được kích hoạt</h1>
        </div>
        <div class='content'>
            <p>Xin chào <strong>{$name}</strong>,</p>
            
            <div class='success'>
                <p><strong>Tài khoản của bạn đã được kích hoạt lại.</strong></p>
                <p>Bạn có thể đăng nhập vào hệ thống ngay bây giờ.</p>
                <p>Thời gian: " . date('d/m/Y H:i:s') . "</p>
            </div>
            
            <center>
                <a href='{$loginUrl}' class='button'>Đăng nhập ngay</a>
            </center>
            
            <p>Trân trọng,<br><strong>Smart Warehouse System Team</strong></p>
        </div>
        <div class='footer'>
            <p>Email này được gửi tự động. Vui lòng không trả lời email này.</p>
            <p>&copy; " . date('Y') . " Smart Warehouse System. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
        ";
    }
    
    /**
     * Template: Role Changed
     */
    private function getRoleChangedTemplate($name, $oldRole, $newRole) {
        $roleNames = [
            'admin' => 'Quản trị viên',
            'manager' => 'Quản lý kho',
            'supervisor' => 'Giám sát',
            'staff' => 'Nhân viên'
        ];
        
        $oldRoleName = $roleNames[$oldRole] ?? $oldRole;
        $newRoleName = $roleNames[$newRole] ?? $newRole;
        
        return "
<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #f6c23e; color: white; padding: 20px; text-align: center; border-radius: 5px 5px 0 0; }
        .content { background: #f8f9fc; padding: 30px; border: 1px solid #e3e6f0; }
        .info { background: #d1ecf1; border-left: 4px solid #17a2b8; padding: 15px; margin: 20px 0; }
        .footer { text-align: center; padding: 20px; color: #858796; font-size: 12px; }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h1>🔄 Vai trò đã được cập nhật</h1>
        </div>
        <div class='content'>
            <p>Xin chào <strong>{$name}</strong>,</p>
            
            <div class='info'>
                <p><strong>Vai trò của bạn đã được cập nhật:</strong></p>
                <p>Vai trò cũ: <strong>{$oldRoleName}</strong></p>
                <p>Vai trò mới: <strong>{$newRoleName}</strong></p>
                <p>Thời gian: " . date('d/m/Y H:i:s') . "</p>
            </div>
            
            <p>Quyền hạn của bạn trong hệ thống có thể đã thay đổi. Vui lòng đăng xuất và đăng nhập lại để cập nhật.</p>
            
            <p>Trân trọng,<br><strong>Smart Warehouse System Team</strong></p>
        </div>
        <div class='footer'>
            <p>Email này được gửi tự động. Vui lòng không trả lời email này.</p>
            <p>&copy; " . date('Y') . " Smart Warehouse System. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
        ";
    }
    
    /**
     * Template: Bulk Import Result
     */
    private function getBulkImportResultTemplate($name, $stats) {
        return "
<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #4e73df; color: white; padding: 20px; text-align: center; border-radius: 5px 5px 0 0; }
        .content { background: #f8f9fc; padding: 30px; border: 1px solid #e3e6f0; }
        .stats { background: white; padding: 20px; margin: 20px 0; border: 1px solid #e3e6f0; }
        .stat-item { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #f1f3f5; }
        .stat-label { font-weight: bold; color: #5a5c69; }
        .stat-value { color: #4e73df; font-weight: bold; }
        .success { color: #1cc88a; }
        .error { color: #e74a3b; }
        .footer { text-align: center; padding: 20px; color: #858796; font-size: 12px; }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h1>📊 Kết quả Import Nhân Viên</h1>
        </div>
        <div class='content'>
            <p>Xin chào <strong>{$name}</strong>,</p>
            <p>Quá trình import nhân viên hàng loạt đã hoàn tất. Dưới đây là kết quả chi tiết:</p>
            
            <div class='stats'>
                <div class='stat-item'>
                    <span class='stat-label'>Tổng số bản ghi:</span>
                    <span class='stat-value'>{$stats['total']}</span>
                </div>
                <div class='stat-item'>
                    <span class='stat-label'>Tạo thành công:</span>
                    <span class='stat-value success'>{$stats['success']}</span>
                </div>
                <div class='stat-item'>
                    <span class='stat-label'>Thất bại:</span>
                    <span class='stat-value error'>{$stats['failed']}</span>
                </div>
                <div class='stat-item'>
                    <span class='stat-label'>Email đã gửi:</span>
                    <span class='stat-value success'>{$stats['email_sent']}</span>
                </div>
                <div class='stat-item'>
                    <span class='stat-label'>Email lỗi:</span>
                    <span class='stat-value error'>{$stats['email_failed']}</span>
                </div>
                <div class='stat-item'>
                    <span class='stat-label'>Tỷ lệ thành công:</span>
                    <span class='stat-value'>{$stats['success_rate']}%</span>
                </div>
            </div>
            
            " . (isset($stats['result_file_url']) ? "
            <p><strong>File kết quả chi tiết:</strong> <a href='{$stats['result_file_url']}'>Tải xuống</a></p>
            " : "") . "
            
            <p>Trân trọng,<br><strong>Smart Warehouse System Team</strong></p>
        </div>
        <div class='footer'>
            <p>Email này được gửi tự động. Vui lòng không trả lời email này.</p>
            <p>&copy; " . date('Y') . " Smart Warehouse System. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
        ";
    }
}
?>
