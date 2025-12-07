<?php
session_start();
header('Content-Type: application/json');

// Include database config
require_once '../config/database.php';

try {
    // Get input data
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    // Validate input
    if (empty($username) || empty($password)) {
        echo json_encode(['success' => false, 'message' => 'Vui lòng nhập đầy đủ thông tin!']);
        exit;
    }

    // Connect to database
    $database = new Database();
    $db = $database->getConnection();

    // Lấy thông tin user kèm warehouse - cho phép status pending/active
    $query = "SELECT u.*, w.name as warehouse_name 
              FROM users u 
              LEFT JOIN warehouses w ON u.warehouse_id = w.warehouse_id 
              WHERE u.username = :username 
              AND u.status IN ('pending', 'active')";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':username', $username);
    $stmt->execute();

    if ($stmt->rowCount() > 0) {
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Check if account is locked
        if (isset($user['locked_until']) && $user['locked_until']) {
            $lockedUntil = new DateTime($user['locked_until']);
            $now = new DateTime();
            
            if ($now < $lockedUntil) {
                $remainingTime = $now->diff($lockedUntil);
                echo json_encode([
                    'success' => false, 
                    'message' => 'Tài khoản bị khóa đến ' . $lockedUntil->format('H:i d/m/Y') . 
                                 ' (còn ' . $remainingTime->format('%i phút') . ')'
                ]);
                exit;
            }
        }
        
        if (password_verify($password, $user['password_hash'])) {
            // Reset failed login attempts on successful login
            $reset_query = "UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE user_id = :user_id";
            $reset_stmt = $db->prepare($reset_query);
            $reset_stmt->bindParam(':user_id', $user['user_id']);
            $reset_stmt->execute();
            
            // Tạo session
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['warehouse_id'] = $user['warehouse_id'];
            $_SESSION['warehouse_name'] = $user['warehouse_name'];
            $_SESSION['logged_in'] = true;
            
            // Cập nhật last login
            $update_query = "UPDATE users SET last_login = NOW() WHERE user_id = :user_id";
            $update_stmt = $db->prepare($update_query);
            $update_stmt->bindParam(':user_id', $user['user_id']);
            $update_stmt->execute();
            
            // UC-NV-02: Check if user must change password
            // Use relative path from web root (not from this file location)
            $redirectUrl = 'trang_chu.php';
            if (isset($user['must_change_password']) && $user['must_change_password'] == 1) {
                $redirectUrl = 'bat_buoc_doi_mat_khau.php';
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Đăng nhập thành công!',
                'redirect' => $redirectUrl,
                'must_change_password' => ($user['must_change_password'] == 1),
                'user' => [
                    'id' => $user['user_id'],
                    'username' => $user['username'],
                    'full_name' => $user['full_name'],
                    'role' => $user['role'],
                    'warehouse_name' => $user['warehouse_name']
                ]
            ]);
        } else {
            // Increment failed login attempts
            $failedAttempts = ($user['failed_login_attempts'] ?? 0) + 1;
            
            // Lock account after 5 failed attempts for 15 minutes
            // Use MySQL NOW() + INTERVAL for consistent timezone
            if ($failedAttempts >= 5) {
                $update_query = "UPDATE users SET 
                                failed_login_attempts = :attempts,
                                locked_until = DATE_ADD(NOW(), INTERVAL 15 MINUTE)
                                WHERE user_id = :user_id";
            } else {
                $update_query = "UPDATE users SET 
                                failed_login_attempts = :attempts
                                WHERE user_id = :user_id";
            }
            
            $update_stmt = $db->prepare($update_query);
            $update_stmt->bindParam(':attempts', $failedAttempts);
            $update_stmt->bindParam(':user_id', $user['user_id']);
            $update_stmt->execute();
            
            $message = 'Mật khẩu không đúng!';
            if ($failedAttempts >= 5) {
                $message .= ' Tài khoản đã bị khóa 15 phút do đăng nhập sai quá nhiều lần.';
            } else {
                $remaining = 5 - $failedAttempts;
                $message .= " Còn $remaining lần thử.";
            }
            
            echo json_encode(['success' => false, 'message' => $message]);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Tài khoản không tồn tại!']);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Có lỗi xảy ra: ' . $e->getMessage()]);
}
?>