<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/User.php';

class AuthMiddleware {
    private $db;
    private $user;
    
    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
        $this->user = new User($this->db);
    }
    
    public function checkAuth($required_role = null) {
        session_start();
        
        $authenticated = false;
        
        // Check existing simple session (fallback used by current app)
        if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true && isset($_SESSION['user_id'])) {
            if (method_exists($this->user, 'loadById') && $this->user->loadById((int)$_SESSION['user_id'])) {
                $authenticated = true;
                $_SESSION['last_activity'] = time();
            }
        }

        // Check token-based session (legacy)
        if (!$authenticated && isset($_SESSION['session_token']) && isset($_SESSION['user_id'])) {
            if (method_exists($this->user, 'validateSession') && $this->user->validateSession($_SESSION['session_token'])) {
                $authenticated = true;
                $_SESSION['last_activity'] = time();
            }
        }
        
        // Check remember me cookie if session is not valid
        if (!$authenticated && isset($_COOKIE['remember_user'])) {
            $cookie_data = json_decode(base64_decode($_COOKIE['remember_user']), true);
            
            if ($cookie_data && isset($cookie_data['session_token'])) {
                if ($this->user->validateSession($cookie_data['session_token'])) {
                    // Restore session
                    $_SESSION['user_id'] = $this->user->id;
                    $_SESSION['username'] = $this->user->username;
                    $_SESSION['full_name'] = $this->user->full_name;
                    $_SESSION['role'] = $this->user->role;
                    $_SESSION['session_token'] = $cookie_data['session_token'];
                    $_SESSION['last_activity'] = time();
                    
                    $authenticated = true;
                }
            }
        }
        
        if (!$authenticated) {
            $this->redirectToLogin();
        }
        
        // Check role if required
        if ($required_role) {
            $role = $_SESSION['role'] ?? null;
            if (!$role || ($role !== $required_role && !in_array($role, (array)$required_role))) {
                $this->accessDenied();
            }
        }
        
        // Check session timeout (24 hours)
        if (isset($_SESSION['last_activity']) && time() - $_SESSION['last_activity'] > 86400) {
            $this->logout();
        }
        
        return $this->user;
    }
    
    public function redirectToLogin() {
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            // AJAX request
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Session expired', 'redirect' => 'login.html']);
        } else {
            // Regular request
            header('Location: login.html');
        }
        exit;
    }
    
    public function accessDenied() {
        header('HTTP/1.0 403 Forbidden');
        echo "Access Denied";
        exit;
    }
    
    public function logout() {
        // Start session if not already started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (isset($_SESSION['session_token']) && method_exists($this->user, 'logout')) {
            $this->user->logout($_SESSION['session_token']);
        }
        
        // Clear session
        session_unset();
        session_destroy();
        
        // Clear remember me cookie
        if (isset($_COOKIE['remember_user'])) {
            setcookie('remember_user', '', time() - 3600, '/', '', false, true);
        }
        
        $this->redirectToLogin();
    }
}

// Helper function to check authentication
function requireAuth($required_role = null) {
    $auth = new AuthMiddleware();
    return $auth->checkAuth($required_role);
}
?>