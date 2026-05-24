<?php
// app/Http/Controllers/Api/StaffController.php
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../Models/Staff.php';
require_once __DIR__ . '/../../../Classes/QuanLyNhanVien.php';

class StaffController {
    private function initDb() {
        $database = new Database();
        return $database->getConnection();
    }

    private function checkAuth() {
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (!isset($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
    }

    public function getList() {
        $this->checkAuth();
        $userRole = $_SESSION['role'] ?? 'staff';
        $warehouseId = $_SESSION['warehouse_id'] ?? null;
        $search = $_GET['search'] ?? $_POST['search'] ?? '';

        try {
            $db = $this->initDb();
            $model = new Staff($db);
            $employees = $model->getList($warehouseId, $userRole, $search);
            echo json_encode(['success' => true, 'data' => $employees]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function getDetail() {
        $this->checkAuth();
        $employeeId = $_GET['user_id'] ?? $_POST['user_id'] ?? 0;
        try {
            $db = $this->initDb();
            $model = new Staff($db);
            $employee = $model->getById($employeeId);
            if (!$employee) throw new Exception('Nhân viên không tồn tại');
            
            // Security check
            $userRole = $_SESSION['role'] ?? 'staff';
            $warehouseId = $_SESSION['warehouse_id'] ?? null;
            if ($userRole !== 'admin' && $employee['warehouse_id'] != $warehouseId) {
                throw new Exception('Bạn không có quyền xem nhân viên này');
            }

            unset($employee['password_hash']);
            echo json_encode(['success' => true, 'data' => $employee]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function update() {
        $this->checkAuth();
        $userRole = $_SESSION['role'] ?? 'staff';
        if (!in_array($userRole, ['admin', 'manager'])) {
            echo json_encode(['success' => false, 'message' => 'Forbidden']);
            return;
        }

        try {
            $db = $this->initDb();
            $employeeManager = new EmployeeManager($db);
            $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $employeeId = $data['user_id'] ?? 0;
            $result = $employeeManager->updateEmployee($employeeId, $data, $_SESSION['user_id']);
            echo json_encode($result);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function changePassword() {
        $this->checkAuth();
        $userId = $_SESSION['user_id'];
        $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        
        try {
            $db = $this->initDb();
            $model = new Staff($db);
            
            $currentPassword = $data['current_password'] ?? '';
            $newPassword = $data['new_password'] ?? '';
            
            $user = $model->getById($userId);
            if (!password_verify($currentPassword, $user['password_hash'])) {
                throw new Exception('Mật khẩu hiện tại không chính xác');
            }

            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $model->updatePassword($userId, $newHash);
            echo json_encode(['success' => true, 'message' => 'Đổi mật khẩu thành công']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
?>
