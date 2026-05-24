<?php
// app/Http/Controllers/Api/AuthController.php
require_once __DIR__ . '/../../../../config/database.php';

class AuthController {
    private function initDb() {
        $database = new Database();
        return $database->getConnection();
    }

    public function login() {
        header('Content-Type: application/json');
        if (session_status() === PHP_SESSION_NONE) session_start();

        try {
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';

            if (empty($username) || empty($password)) {
                echo json_encode(['success' => false, 'message' => 'Vui lòng nhập đầy đủ thông tin!']);
                return;
            }

            $db = $this->initDb();
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
                
                if (password_verify($password, $user['password_hash'])) {
                    // Reset failed login attempts
                    $db->prepare("UPDATE users SET failed_login_attempts = 0, locked_until = NULL, last_login = NOW() WHERE user_id = ?")
                       ->execute([$user['user_id']]);
                    
                    $_SESSION['user_id'] = $user['user_id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['full_name'] = $user['full_name'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['warehouse_id'] = $user['warehouse_id'];
                    $_SESSION['warehouse_name'] = $user['warehouse_name'];
                    $_SESSION['logged_in'] = true;
                    
                    $redirectUrl = 'trang_chu.php';
                    if (isset($user['must_change_password']) && $user['must_change_password'] == 1) {
                        $redirectUrl = 'bat_buoc_doi_mat_khau.php';
                    }
                    
                    echo json_encode([
                        'success' => true,
                        'message' => 'Đăng nhập thành công!',
                        'redirect' => $redirectUrl,
                        'must_change_password' => ($user['must_change_password'] == 1)
                    ]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Mật khẩu không đúng!']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Tài khoản không tồn tại!']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Lỗi: ' . $e->getMessage()]);
        }
    }

    public function logout() {
        if (session_status() === PHP_SESSION_NONE) session_start();
        session_destroy();
        echo json_encode(['success' => true, 'redirect' => 'login.html']);
    }
}
?>
