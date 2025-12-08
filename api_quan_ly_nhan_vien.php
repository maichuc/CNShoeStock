<?php
/**
 * API Employee Management
 * Xử lý tất cả các request liên quan đến quản lý nhân viên
 * 
 * Endpoints:
 * - POST /create: UC-NV-01 - Tạo nhân viên mới
 * - POST /bulk-import: UC-NV-01B - Import hàng loạt
 * - GET /list: Danh sách nhân viên
 * - GET /detail: Chi tiết nhân viên
 * - POST /update: UC-NV-06 - Cập nhật thông tin
 * - POST /deactivate: UC-NV-07 - Vô hiệu hóa
 * - POST /activate: UC-NV-07 - Kích hoạt
 * - GET /activity-logs: UC-NV-08 - Lịch sử hoạt động
 * - POST /change-password: UC-NV-05 - Đổi mật khẩu
 * - (REMOVED) forgot-password, verify-otp, reset-password - Đã xóa chức năng quên mật khẩu
 */

header('Content-Type: application/json');
session_start();

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/QuanLyNhanVien.php';
require_once __DIR__ . '/classes/DichVuEmail.php';

// Kiểm tra authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$database = new Database();
$pdo = $database->getConnection();
$employeeManager = new EmployeeManager($pdo);
$emailService = new EmailService($pdo);

// Lấy action from POST body (JSON) or GET parameter or POST form data
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// Support both JSON and form-urlencoded
if (empty($data)) {
    $data = $_POST;
}

$action = $data['action'] ?? $_GET['action'] ?? '';

// Gỡ lỗi logging (remove in production)
if (empty($action)) {
    error_log("API Debug - No action found.");
    error_log("API Debug - Input: " . $input);
    error_log("API Debug - GET: " . print_r($_GET, true));
    error_log("API Debug - POST: " . print_r($_POST, true));
    error_log("API Debug - Content-Type: " . ($_SERVER['CONTENT_TYPE'] ?? 'not set'));
}

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? 'staff';
$warehouseId = $_SESSION['warehouse_id'] ?? null;

try {
    switch ($action) {
        
        // ==================== CHECK USERNAME AVAILABILITY ====================
        case 'check-username':
            $username = $data['username'] ?? '';
            
            if (empty($username)) {
                echo json_encode(['success' => false, 'available' => false, 'message' => 'Username không được để trống']);
                exit;
            }
            
            // Kiểm tra if username exists
            $stmt = $pdo->prepare("SELECT user_id FROM users WHERE username = :username");
            $stmt->execute([':username' => $username]);
            $exists = $stmt->rowCount() > 0;
            
            if ($exists) {
                // Suggest alternative usernames by adding numbers
                $suggestions = [];
                for ($i = 1; $i <= 5; $i++) {
                    $suggestedUsername = $username . $i;
                    $stmt = $pdo->prepare("SELECT user_id FROM users WHERE username = :username");
                    $stmt->execute([':username' => $suggestedUsername]);
                    
                    if ($stmt->rowCount() === 0) {
                        $suggestions[] = $suggestedUsername;
                    }
                    
                    if (count($suggestions) >= 3) break; // Limit to 3 suggestions
                }
                
                echo json_encode([
                    'success' => true,
                    'available' => false,
                    'message' => 'Username đã tồn tại',
                    'suggestions' => $suggestions
                ]);
            } else {
                echo json_encode([
                    'success' => true,
                    'available' => true,
                    'message' => 'Username khả dụng'
                ]);
            }
            break;
        
        // ==================== UC-NV-01: TẠO NHÂN VIÊN MỚI ====================
        case 'create':
            // Only manager and admin can create employees
            if (!in_array($userRole, ['admin', 'manager'])) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Bạn không có quyền thực hiện thao tác này']);
                exit;
            }
            
            // Lấy data từ JSON body (not from $data variable which might be used for action)
            $requestData = $data ?? [];
            $requestData['warehouse_id'] = $requestData['warehouse_id'] ?? $warehouseId;
            
            // Warehouse isolation check
            if ($userRole !== 'admin' && $requestData['warehouse_id'] != $warehouseId) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Bạn chỉ có thể tạo nhân viên trong kho của mình']);
                exit;
            }
            
            $result = $employeeManager->createEmployee($requestData, $userId);
            echo json_encode($result);
            break;
        
        // ==================== UC-NV-01B: BULK IMPORT ====================
        // ==================== UC-NV-01B: BULK IMPORT ====================
        case 'bulk-import':
            if (!in_array($userRole, ['admin', 'manager'])) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Bạn không có quyền thực hiện thao tác này']);
                exit;
            }
            
            if (!isset($_FILES['file'])) {
                echo json_encode(['success' => false, 'message' => 'Không có file được upload']);
                exit;
            }
            
            // Lấy warehouse_id from POST
            $importWarehouseId = $_POST['warehouse_id'] ?? $warehouseId;
            
            $uploadDir = __DIR__ . '/uploads/employee_imports/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            $fileName = 'import_' . $importWarehouseId . '_' . time() . '_' . uniqid() . '.xlsx';
            $uploadFile = $uploadDir . $fileName;
            
            if (move_uploaded_file($_FILES['file']['tmp_name'], $uploadFile)) {
                $result = $employeeManager->bulkImportEmployees($uploadFile, $importWarehouseId, $userId);
                echo json_encode($result);
                
                // Cleanup after 24 hours (in production use cron job)
                // @unlink($uploadFile);
            } else {
                echo json_encode(['success' => false, 'message' => 'Lỗi khi upload file: ' . $_FILES['file']['error']]);
            }
            break;
        
        // ==================== DANH SÁCH NHÂN VIÊN ====================
        // ==================== DANH SÁCH NHÂN VIÊN ====================
        case 'list':
            // Lấy data from POST JSON body
            $requestData = $data ?? [];
            $page = $requestData['page'] ?? $_GET['page'] ?? 1;
            $limit = $requestData['limit'] ?? $_GET['limit'] ?? 1000;
            $search = $requestData['search'] ?? $_GET['search'] ?? '';
            $filterWarehouseId = $requestData['warehouse_id'] ?? $_GET['warehouse_id'] ?? null;
            
            $offset = ($page - 1) * $limit;
            
            $sql = "SELECT 
                        u.user_id,
                        u.username,
                        u.full_name,
                        u.employee_code,
                        u.email,
                        u.phone,
                        u.role,
                        u.warehouse_id,
                        w.name AS warehouse_name,
                        u.status,
                        u.must_change_password,
                        u.password_changed_at,
                        u.last_login,
                        u.failed_login_attempts,
                        u.locked_until,
                        CASE 
                            WHEN u.locked_until IS NOT NULL AND u.locked_until > NOW() THEN 'locked'
                            WHEN u.status = 'inactive' THEN 'inactive'
                            WHEN u.status = 'pending' THEN 'pending'
                            ELSE 'active'
                        END AS account_status,
                        u.created_at,
                        u.updated_at
                    FROM users u 
                    LEFT JOIN warehouses w ON u.warehouse_id = w.warehouse_id
                    WHERE u.role IN ('admin', 'manager', 'staff')";
            
            $params = [];
            
            // WAREHOUSE ISOLATION - BẮT BUỘC
            // Manager: chỉ xem nhân viên trong kho của mình
            // Admin: có thể filter theo kho, nếu không filter thì xem tất cả
            if ($userRole === 'admin') {
                // Admin: nếu có filter warehouse thì áp dụng
                if ($filterWarehouseId) {
                    $sql .= " AND u.warehouse_id = :warehouse_id";
                    $params[':warehouse_id'] = $filterWarehouseId;
                }
                // Nếu không filter thì xem tất cả các kho
            } else {
                // Manager và các role khác: BẮT BUỘC chỉ xem kho của mình
                $sql .= " AND u.warehouse_id = :warehouse_id";
                $params[':warehouse_id'] = $warehouseId;
            }
            
            // Tìm kiếm
            if ($search) {
                $sql .= " AND (u.full_name LIKE :search OR u.email LIKE :search OR u.username LIKE :search OR u.employee_code LIKE :search)";
                $params[':search'] = "%$search%";
            }
            
            $sql .= " ORDER BY u.created_at DESC";
            
            // Count total với warehouse isolation
            $countSql = "SELECT COUNT(*) as total FROM users u WHERE u.role IN ('admin', 'manager', 'staff')";
            $countParams = [];
            
            if ($userRole === 'admin') {
                if ($filterWarehouseId) {
                    $countSql .= " AND u.warehouse_id = :warehouse_id";
                    $countParams[':warehouse_id'] = $filterWarehouseId;
                }
            } else {
                $countSql .= " AND u.warehouse_id = :warehouse_id";
                $countParams[':warehouse_id'] = $warehouseId;
            }
            
            if ($search) {
                $countSql .= " AND (u.full_name LIKE :search OR u.email LIKE :search OR u.username LIKE :search OR u.employee_code LIKE :search)";
                $countParams[':search'] = "%$search%";
            }
            
            $countStmt = $pdo->prepare($countSql);
            $countStmt->execute($countParams);
            $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            // Lấy data (no LIMIT for DataTables - let it handle pagination client-side)
            $stmt = $pdo->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->execute();
            
            $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Xóa password_hash from response
            foreach ($employees as &$emp) {
                unset($emp['password_hash']);
            }
            
            echo json_encode([
                'success' => true,
                'data' => $employees,
                'pagination' => [
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit,
                    'total_pages' => ceil($total / $limit)
                ]
            ]);
            break;
        
        // ==================== CHI TIẾT NHÂN VIÊN (UC_NV_S - Bước 2,3,4) ====================
        case 'detail':
            // Hỗ trợ cả GET và POST
            $employeeId = $data['user_id'] ?? $_GET['id'] ?? $_GET['user_id'] ?? 0;
            
            if (empty($employeeId)) {
                echo json_encode(['success' => false, 'message' => 'Thiếu user_id']);
                exit;
            }
            
            // Lấy thông tin chi tiết nhân viên (Bước 3)
            $stmt = $pdo->prepare("
                SELECT u.*, w.name as warehouse_name 
                FROM users u 
                LEFT JOIN warehouses w ON u.warehouse_id = w.warehouse_id
                WHERE u.user_id = :id
            ");
            $stmt->execute([':id' => $employeeId]);
            $employee = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$employee) {
                echo json_encode(['success' => false, 'message' => 'Nhân viên không tồn tại']);
                exit;
            }
            
            // Warehouse isolation (Tiền điều kiện: Quản lý chỉ được xem nhân viên trong cùng kho)
            if ($userRole !== 'admin' && $employee['warehouse_id'] != $warehouseId) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Bạn không có quyền xem nhân viên từ kho khác']);
                exit;
            }
            
            unset($employee['password_hash']);
            
            // Tính toán account_status dựa trên locked_until và status
            if (!empty($employee['locked_until']) && strtotime($employee['locked_until']) > time()) {
                $employee['account_status'] = 'locked';
            } elseif ($employee['status'] === 'inactive') {
                $employee['account_status'] = 'inactive';
            } elseif ($employee['status'] === 'pending') {
                $employee['account_status'] = 'pending';
            } else {
                $employee['account_status'] = 'active';
            }
            
            // Hiển thị form chỉnh sửa với các thông tin hiện tại (Bước 4)
            echo json_encode([
                'success' => true,
                'data' => $employee
            ]);
            break;
        
        // ==================== UC-NV-06: CẬP NHẬT THÔNG TIN ====================
        case 'update':
            if (!in_array($userRole, ['admin', 'manager'])) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Bạn không có quyền cập nhật thông tin nhân viên']);
                exit;
            }
            
            $data = json_decode(file_get_contents('php://input'), true);
            $employeeId = $data['user_id'] ?? 0;
            
            $result = $employeeManager->updateEmployee($employeeId, $data, $userId);
            echo json_encode($result);
            break;
        
        // ==================== UC-NV-07: VÔ HIỆU HÓA ====================
        case 'deactivate':
            if (!in_array($userRole, ['admin', 'manager'])) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Bạn không có quyền vô hiệu hóa tài khoản']);
                exit;
            }
            
            $data = json_decode(file_get_contents('php://input'), true);
            $employeeId = $data['user_id'] ?? 0;
            $reason = $data['reason'] ?? '';
            
            $result = $employeeManager->deactivateEmployee($employeeId, $reason, $userId);
            echo json_encode($result);
            break;
        
        // ==================== UC-NV-07: KÍCH HOẠT ====================
        case 'activate':
            if (!in_array($userRole, ['admin', 'manager'])) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Bạn không có quyền kích hoạt tài khoản']);
                exit;
            }
            
            $data = json_decode(file_get_contents('php://input'), true);
            $employeeId = $data['user_id'] ?? 0;
            
            $result = $employeeManager->activateEmployee($employeeId, $userId);
            echo json_encode($result);
            break;
        
        // ==================== UC-NV-08: LỊCH SỬ HOẠT ĐỘNG ====================
        case 'activity-logs':
            $employeeId = $_GET['employee_id'] ?? $userId; // Mặc định là chính mình
            
            // Kiểm tra quyền xem log của người khác
            if ($employeeId != $userId && !in_array($userRole, ['admin', 'manager', 'supervisor'])) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Bạn không có quyền xem lịch sử của người khác']);
                exit;
            }
            
            $filters = [
                'start_date' => $_GET['start_date'] ?? null,
                'end_date' => $_GET['end_date'] ?? null,
                'action' => $_GET['action_type'] ?? null
            ];
            
            $result = $employeeManager->getEmployeeActivityLogs($employeeId, $filters);
            echo json_encode($result);
            break;
        
        // ==================== UC-NV-05: ĐỔI MẬT KHẨU ====================
        case 'change-password':
            $data = json_decode(file_get_contents('php://input'), true);
            $currentPassword = $data['current_password'] ?? '';
            $newPassword = $data['new_password'] ?? '';
            $confirmPassword = $data['confirm_password'] ?? '';
            
            // Kiểm tra inputs
            if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
                echo json_encode(['success' => false, 'message' => 'Vui lòng điền đầy đủ thông tin']);
                exit;
            }
            
            if ($newPassword !== $confirmPassword) {
                echo json_encode(['success' => false, 'message' => 'Mật khẩu xác nhận không khớp']);
                exit;
            }
            
            // Kiểm tra password policy
            if (strlen($newPassword) < 8) {
                echo json_encode(['success' => false, 'message' => 'Mật khẩu phải có ít nhất 8 ký tự']);
                exit;
            }
            
            if (!preg_match('/[A-Z]/', $newPassword) || !preg_match('/[a-z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword)) {
                echo json_encode(['success' => false, 'message' => 'Mật khẩu phải bao gồm chữ hoa, chữ thường và số']);
                exit;
            }
            
            // Lấy current user
            $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = :id");
            $stmt->execute([':id' => $userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Xác minh current password
            if (!password_verify($currentPassword, $user['password_hash'])) {
                echo json_encode(['success' => false, 'message' => 'Mật khẩu hiện tại không chính xác']);
                exit;
            }
            
            // Kiểm tra new password != old password
            if (password_verify($newPassword, $user['password_hash'])) {
                echo json_encode(['success' => false, 'message' => 'Mật khẩu mới phải khác mật khẩu cũ']);
                exit;
            }
            
            // Cập nhật password
            $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("
                UPDATE users 
                SET password_hash = :password, 
                    password_changed_at = NOW(),
                    must_change_password = 0,
                    updated_at = NOW()
                WHERE user_id = :id
            ");
            $stmt->execute([
                ':password' => $newPasswordHash,
                ':id' => $userId
            ]);
            
            // Gửi email notification
            $emailService->sendPasswordChangedEmail($user['email'], $user['full_name'], 'manual');
            
            echo json_encode(['success' => true, 'message' => 'Đổi mật khẩu thành công']);
            break;
        
        // ==================== UC-NV-02: ĐỔI MẬT KHẨU BẮT BUỘC (FORCE CHANGE) ====================
        case 'force-change-password':
            $data = json_decode(file_get_contents('php://input'), true);
            $oldPassword = $data['old_password'] ?? '';
            $newPassword = $data['new_password'] ?? '';
            $confirmPassword = $data['confirm_password'] ?? '';
            
            // Kiểm tra inputs
            if (empty($oldPassword) || empty($newPassword) || empty($confirmPassword)) {
                echo json_encode(['success' => false, 'message' => 'Vui lòng điền đầy đủ thông tin']);
                exit;
            }
            
            if ($newPassword !== $confirmPassword) {
                echo json_encode(['success' => false, 'message' => 'Mật khẩu xác nhận không khớp']);
                exit;
            }
            
            // Kiểm tra password policy (UC-NV-02 requirements)
            if (strlen($newPassword) < 8) {
                echo json_encode(['success' => false, 'message' => 'Mật khẩu phải có ít nhất 8 ký tự']);
                exit;
            }
            
            if (!preg_match('/[A-Z]/', $newPassword)) {
                echo json_encode(['success' => false, 'message' => 'Mật khẩu phải có ít nhất 1 chữ hoa']);
                exit;
            }
            
            if (!preg_match('/[a-z]/', $newPassword)) {
                echo json_encode(['success' => false, 'message' => 'Mật khẩu phải có ít nhất 1 chữ thường']);
                exit;
            }
            
            if (!preg_match('/[0-9]/', $newPassword)) {
                echo json_encode(['success' => false, 'message' => 'Mật khẩu phải có ít nhất 1 chữ số']);
                exit;
            }
            
            if (!preg_match('/[@$!%*?&#]/', $newPassword)) {
                echo json_encode(['success' => false, 'message' => 'Mật khẩu phải có ít nhất 1 ký tự đặc biệt (@$!%*?&#)']);
                exit;
            }
            
            // Lấy current user
            $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = :id");
            $stmt->execute([':id' => $userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                echo json_encode(['success' => false, 'message' => 'Người dùng không tồn tại']);
                exit;
            }
            
            // Xác minh old password
            if (!password_verify($oldPassword, $user['password_hash'])) {
                // Increment failed login attempts
                $stmt = $pdo->prepare("
                    UPDATE users 
                    SET failed_login_attempts = failed_login_attempts + 1
                    WHERE user_id = :id
                ");
                $stmt->execute([':id' => $userId]);
                
                echo json_encode(['success' => false, 'message' => 'Mật khẩu hiện tại không chính xác']);
                exit;
            }
            
            // Kiểm tra new password != old password
            if (password_verify($newPassword, $user['password_hash'])) {
                echo json_encode(['success' => false, 'message' => 'Mật khẩu mới phải khác mật khẩu hiện tại']);
                exit;
            }
            
            // UC-NV-05: Kiểm tra password history - prevent reusing last 3 passwords
            $stmt = $pdo->prepare("
                SELECT password_hash 
                FROM password_history 
                WHERE user_id = :user_id 
                ORDER BY changed_at DESC 
                LIMIT 3
            ");
            $stmt->execute([':user_id' => $userId]);
            $passwordHistory = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            foreach ($passwordHistory as $oldHash) {
                if (password_verify($newPassword, $oldHash)) {
                    echo json_encode([
                        'success' => false, 
                        'message' => 'Không được sử dụng lại mật khẩu cũ (bao gồm mật khẩu tạm thời). Vui lòng chọn mật khẩu khác.'
                    ]);
                    exit;
                }
            }
            
            // Lưu current password to history before updating
            $stmt = $pdo->prepare("
                INSERT INTO password_history (user_id, password_hash, changed_at) 
                VALUES (:user_id, :password_hash, NOW())
            ");
            $stmt->execute([
                ':user_id' => $userId,
                ':password_hash' => $user['password_hash']
            ]);
            
            // Cập nhật password, reset must_change_password, and SET status='active'
            $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("
                UPDATE users 
                SET password_hash = :password, 
                    password_changed_at = NOW(),
                    must_change_password = 0,
                    status = 'active',
                    failed_login_attempts = 0,
                    updated_at = NOW()
                WHERE user_id = :id
            ");
            
            $success = $stmt->execute([
                ':password' => $newPasswordHash,
                ':id' => $userId
            ]);
            
            if ($success) {
                // Gửi email notification
                $emailService->sendPasswordChangedEmail($user['email'], $user['full_name'], 'first_login');
                
                // Ghi nhật ký activity
                $stmt = $pdo->prepare("
                    INSERT INTO employee_activity_logs 
                    (user_id, action, details, performed_by, created_at) 
                    VALUES (:user_id, 'force_password_change', 'Đổi mật khẩu lần đầu - Status chuyển sang Active', :performed_by, NOW())
                ");
                $stmt->execute([
                    ':user_id' => $userId,
                    ':performed_by' => $userId
                ]);
                
                echo json_encode([
                    'success' => true, 
                    'message' => 'Đổi mật khẩu thành công! Tài khoản đã được kích hoạt. Bạn có thể tiếp tục sử dụng hệ thống.',
                    'redirect' => 'trang_chu.php'
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Có lỗi xảy ra khi cập nhật mật khẩu']);
            }
            break;
        
        // ==================== ADMIN RESET PASSWORD ====================
        case 'admin-reset-password':
            // Only manager and admin can reset passwords
            if (!in_array($userRole, ['admin', 'manager'])) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Bạn không có quyền thực hiện thao tác này']);
                exit;
            }
            
            $targetUserId = $data['user_id'] ?? null;
            
            if (empty($targetUserId)) {
                echo json_encode(['success' => false, 'message' => 'Thiếu thông tin user_id']);
                exit;
            }
            
            // Lấy target user info
            $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = :id");
            $stmt->execute([':id' => $targetUserId]);
            $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$targetUser) {
                echo json_encode(['success' => false, 'message' => 'Người dùng không tồn tại']);
                exit;
            }
            
            // Warehouse isolation check
            if ($userRole !== 'admin' && $targetUser['warehouse_id'] != $warehouseId) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Bạn chỉ có thể reset mật khẩu nhân viên trong kho của mình']);
                exit;
            }
            
            // Tạo new temporary password
            $newPassword = bin2hex(random_bytes(6)); // 12 characters
            $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            
            // Lưu current password to history before updating
            $stmt = $pdo->prepare("
                INSERT INTO password_history (user_id, password_hash, changed_at) 
                VALUES (:user_id, :password_hash, NOW())
            ");
            $stmt->execute([
                ':user_id' => $targetUserId,
                ':password_hash' => $targetUser['password_hash']
            ]);
            
            // Cập nhật password and force change on next login
            $stmt = $pdo->prepare("
                UPDATE users 
                SET password_hash = :password, 
                    password_changed_at = NOW(),
                    must_change_password = 1,
                    status = 'pending',
                    updated_at = NOW()
                WHERE user_id = :id
            ");
            
            $success = $stmt->execute([
                ':password' => $newPasswordHash,
                ':id' => $targetUserId
            ]);
            
            if ($success) {
                // Gửi email with new password
                $emailService->sendPasswordResetByAdminEmail(
                    $targetUser['email'], 
                    $targetUser['full_name'], 
                    $targetUser['username'],
                    $newPassword
                );
                
                // Ghi nhật ký activity
                $stmt = $pdo->prepare("
                    INSERT INTO employee_activity_logs 
                    (user_id, action, details, performed_by, created_at) 
                    VALUES (:user_id, 'admin_password_reset', 'Admin reset mật khẩu', :performed_by, NOW())
                ");
                $stmt->execute([
                    ':user_id' => $targetUserId,
                    ':performed_by' => $userId
                ]);
                
                echo json_encode([
                    'success' => true, 
                    'message' => 'Reset mật khẩu thành công! Mật khẩu mới đã được gửi qua email.',
                    'data' => [
                        'email_sent' => true
                    ]
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Có lỗi xảy ra khi reset mật khẩu']);
            }
            break;
        
        // ==================== CHỨC NĂNG QUÊN MẬT KHẨU ĐÃ BỊ XÓA ====================
        // Các case: forgot-password, verify-otp, reset-password đã bị xóa
        // Lý do: Không sử dụng, để tránh maintain code không cần thiết
        // Nếu cần khôi phục mật khẩu, nhân viên liên hệ Admin để reset
        
        // ==================== RESEND WELCOME EMAIL ====================
        case 'resend-welcome-email':
            if (!in_array($userRole, ['admin', 'manager'])) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Bạn không có quyền thực hiện thao tác này']);
                exit;
            }
            
            $data = json_decode(file_get_contents('php://input'), true);
            $employeeId = $data['user_id'] ?? 0;
            
            $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = :id");
            $stmt->execute([':id' => $employeeId]);
            $employee = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$employee) {
                echo json_encode(['success' => false, 'message' => 'Nhân viên không tồn tại']);
                exit;
            }
            
            // Tạo new temp password
            $newTempPassword = bin2hex(random_bytes(6));
            $newPasswordHash = password_hash($newTempPassword, PASSWORD_DEFAULT);
            
            $stmt = $pdo->prepare("
                UPDATE users 
                SET password_hash = :password, 
                    must_change_password = 1,
                    status = 'pending',
                    updated_at = NOW()
                WHERE user_id = :id
            ");
            $stmt->execute([
                ':password' => $newPasswordHash,
                ':id' => $employeeId
            ]);
            
            $warehouseName = '';
            if ($employee['warehouse_id']) {
                $stmt = $pdo->prepare("SELECT warehouse_name FROM warehouses WHERE warehouse_id = :id");
                $stmt->execute([':id' => $employee['warehouse_id']]);
                $warehouse = $stmt->fetch(PDO::FETCH_ASSOC);
                $warehouseName = $warehouse['warehouse_name'] ?? '';
            }
            
            $emailResult = $emailService->sendWelcomeEmail(
                $employee['email'],
                $employee['full_name'],
                $employee['username'],
                $newTempPassword,
                $warehouseName
            );
            
            echo json_encode([
                'success' => $emailResult['success'],
                'message' => $emailResult['success'] ? 'Email đã được gửi lại' : 'Không thể gửi email',
                'temporary_password' => $newTempPassword
            ]);
            break;
        
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            break;
    }
    
} catch (Exception $e) {
    error_log("API Employee Management Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error',
        'error' => $e->getMessage()
    ]);
}
?>