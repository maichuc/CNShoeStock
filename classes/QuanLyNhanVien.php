<?php
/**
 * Employee Manager Class
 * Core business logic cho quản lý nhân viên
 * 
 * Features:
 * - UC-NV-01: Tạo tài khoản nhân viên mới
 * - UC-NV-01B: Tạo hàng loạt tài khoản (Bulk Import)
 * - UC-NV-06: Cập nhật thông tin nhân viên
 * - UC-NV-07: Vô hiệu hóa/Kích hoạt tài khoản
 * - UC-NV-08: Xem lịch sử hoạt động
 * - Password management
 * - Validation & Security
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/DichVuEmail.php';
require_once __DIR__ . '/../helpers/TaoTenNguoiDung.php';

class EmployeeManager {
    private $pdo;
    private $emailService;
    
    // Password policy
    private $min_password_length = 8;
    private $password_require_uppercase = true;
    private $password_require_lowercase = true;
    private $password_require_number = true;
    private $password_require_special = false; // Optional
    
    // Bulk import limits
    private $max_bulk_import_size = 100;
    private $max_file_size = 2 * 1024 * 1024; // 2MB
    
    public function __construct($pdo = null) {
        if ($pdo === null) {
            $database = new Database();
            $this->pdo = $database->getConnection();
        } else {
            $this->pdo = $pdo;
        }
        
        $this->emailService = new EmailService($this->pdo);
    }
    
    // ==================== UC-NV-01: TẠO TÀI KHOẢN NHÂN VIÊN MỚI ====================
    
    /**
     * Tạo tài khoản nhân viên mới
     * 
     * @param array $data Thông tin nhân viên
     * @param int $createdBy User ID người tạo
     * @return array Result
     */
    public function createEmployee($data, $createdBy) {
        try {
            $this->pdo->beginTransaction();
            
            // 1. Validate dữ liệu đầu vào
            $validation = $this->validateEmployeeData($data);
            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'message' => 'Dữ liệu không hợp lệ',
                    'errors' => $validation['errors']
                ];
            }
            
            // 2. Kiểm tra email và username chưa tồn tại
            if ($this->emailExists($data['email'])) {
                return [
                    'success' => false,
                    'message' => 'Email này đã được đăng ký'
                ];
            }
            
            if ($this->usernameExists($data['username'])) {
                // Auto-generate unique username using UsernameGenerator
                $warehouseName = $this->getWarehouseName($data['warehouse_id']);
                $data['username'] = UsernameGenerator::generateUniqueUsername(
                    $data['full_name'],
                    $warehouseName,
                    time(), // Use timestamp as temporary employee_id
                    $this->pdo
                );
            }
            
            // 3. Sinh mật khẩu tạm thời
            $temporaryPassword = $this->generateTemporaryPassword();
            $passwordHash = password_hash($temporaryPassword, PASSWORD_DEFAULT);
            
            // 4. Tạo employee code nếu chưa có
            $employeeCode = $data['employee_code'] ?? $this->generateEmployeeCode();
            
            // 5. Insert vào database
            $stmt = $this->pdo->prepare("
                INSERT INTO users 
                (username, password_hash, full_name, employee_code, email, phone, role, warehouse_id, 
                 status, must_change_password, created_at, updated_at)
                VALUES 
                (:username, :password_hash, :full_name, :employee_code, :email, :phone, :role, :warehouse_id,
                 'pending', 1, NOW(), NOW())
            ");
            
            $stmt->execute([
                ':username' => $data['username'],
                ':password_hash' => $passwordHash,
                ':full_name' => $data['full_name'],
                ':employee_code' => $employeeCode,
                ':email' => $data['email'],
                ':phone' => $data['phone'] ?? null,
                ':role' => $data['role'] ?? 'staff',
                ':warehouse_id' => $data['warehouse_id']
            ]);
            
            $userId = $this->pdo->lastInsertId();
            
            // 5.5. Lưu password history để tránh tái sử dụng
            $this->savePasswordHistory($userId, $passwordHash);
            
            // 6. Ghi log
            $this->logAction($userId, 'create_employee', 'users', $userId, null, [
                'username' => $data['username'],
                'full_name' => $data['full_name'],
                'email' => $data['email'],
                'role' => $data['role'] ?? 'staff',
                'warehouse_id' => $data['warehouse_id'],
                'created_by' => $createdBy
            ], $data['warehouse_id']);
            
            // 7. Gửi email chào mừng
            $warehouseName = $this->getWarehouseName($data['warehouse_id']);
            $emailResult = $this->emailService->sendWelcomeEmail(
                $data['email'],
                $data['full_name'],
                $data['username'],
                $temporaryPassword,
                $warehouseName
            );
            
            $this->pdo->commit();
            
            return [
                'success' => true,
                'message' => 'Tạo tài khoản thành công' . 
                            ($emailResult['success'] ? '. Email đã được gửi đến nhân viên.' : 
                             '. Tuy nhiên không thể gửi email. Vui lòng gửi lại thông tin đăng nhập cho nhân viên.'),
                'data' => [
                    'user_id' => $userId,
                    'employee_code' => $employeeCode,
                    'password' => $temporaryPassword,
                    'email_sent' => $emailResult['success']
                ]
            ];
            
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            error_log("Create employee error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Lỗi khi tạo tài khoản: ' . $e->getMessage()
            ];
        }
    }
    
    // ==================== UC-NV-01B: BULK IMPORT ====================
    
    /**
     * Import hàng loạt nhân viên từ file Excel/CSV
     * 
     * @param string $filePath Đường dẫn file upload
     * @param int $warehouseId Warehouse ID
     * @param int $importedBy User ID người import
     * @return array Result
     */
    public function bulkImportEmployees($filePath, $warehouseId, $importedBy) {
        try {
            // 1. Kiểm tra file
            $fileValidation = $this->validateImportFile($filePath);
            if (!$fileValidation['valid']) {
                return [
                    'success' => false,
                    'message' => $fileValidation['message']
                ];
            }
            
            // 2. Phân tích cú pháp file
            $parseResult = $this->parseImportFile($filePath);
            if (!$parseResult['success']) {
                return $parseResult;
            }
            
            $records = $parseResult['data'];
            
            // 3. Validate từng record
            $validRecords = [];
            $invalidRecords = [];
            
            foreach ($records as $index => $record) {
                $record['warehouse_id'] = $warehouseId;
                $validation = $this->validateEmployeeData($record, true); // bulk mode
                
                if ($validation['valid']) {
                    // Kiểm tra duplicates
                    if ($this->emailExists($record['email']) || $this->usernameExists($record['username'])) {
                        $invalidRecords[] = [
                            'row' => $index + 2, // +2 for header and 0-index
                            'data' => $record,
                            'errors' => ['Email hoặc username đã tồn tại']
                        ];
                    } else {
                        $validRecords[] = [
                            'row' => $index + 2,
                            'data' => $record
                        ];
                    }
                } else {
                    $invalidRecords[] = [
                        'row' => $index + 2,
                        'data' => $record,
                        'errors' => $validation['errors']
                    ];
                }
            }
            
            // 4. Tạo log import
            $importId = $this->createBulkImportLog(
                $warehouseId,
                $importedBy,
                basename($filePath),
                count($records)
            );
            
            // 5. Nếu không có record hợp lệ
            if (empty($validRecords)) {
                $this->updateBulkImportLog($importId, 'failed', 0, count($invalidRecords));
                return [
                    'success' => false,
                    'message' => 'Không có nhân viên hợp lệ nào để tạo',
                    'total' => count($records),
                    'valid' => 0,
                    'invalid' => count($invalidRecords),
                    'invalid_records' => $invalidRecords
                ];
            }
            
            // 6. Xử lý valid records
            $successCount = 0;
            $failedCount = 0;
            $emailSentCount = 0;
            $emailFailedCount = 0;
            $resultDetails = [];
            
            foreach ($validRecords as $record) {
                try {
                    // Tạo password
                    $tempPassword = $this->generateTemporaryPassword();
                    $passwordHash = password_hash($tempPassword, PASSWORD_DEFAULT);
                    $employeeCode = $this->generateEmployeeCode();
                    
                    // Thêm
                    $stmt = $this->pdo->prepare("
                        INSERT INTO users 
                        (username, password_hash, full_name, employee_code, email, phone, role, warehouse_id, 
                         status, must_change_password, created_at, updated_at)
                        VALUES 
                        (:username, :password_hash, :full_name, :employee_code, :email, :phone, :role, :warehouse_id,
                         'pending', 1, NOW(), NOW())
                    ");
                    
                    $stmt->execute([
                        ':username' => $record['data']['username'],
                        ':password_hash' => $passwordHash,
                        ':full_name' => $record['data']['full_name'],
                        ':employee_code' => $employeeCode,
                        ':email' => $record['data']['email'],
                        ':phone' => $record['data']['phone'] ?? null,
                        ':role' => $record['data']['role'] ?? 'staff',
                        ':warehouse_id' => $warehouseId
                    ]);
                    
                    $userId = $this->pdo->lastInsertId();
                    $successCount++;
                    
                    // Queue email
                    $warehouseName = $this->getWarehouseName($warehouseId);
                    $emailResult = $this->emailService->sendBulkWelcomeEmail(
                        $record['data']['email'],
                        $record['data']['full_name'],
                        $record['data']['username'],
                        $tempPassword,
                        $warehouseName,
                        $importId
                    );
                    
                    if ($emailResult['success']) {
                        $emailSentCount++;
                    } else {
                        $emailFailedCount++;
                    }
                    
                    $resultDetails[] = [
                        'row' => $record['row'],
                        'status' => 'success',
                        'user_id' => $userId,
                        'employee_code' => $employeeCode,
                        'generated_password' => $tempPassword,
                        'email_sent' => $emailResult['success']
                    ];
                    
                } catch (PDOException $e) {
                    $failedCount++;
                    $resultDetails[] = [
                        'row' => $record['row'],
                        'status' => 'failed',
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            // 7. Cập nhật import log
            $this->updateBulkImportLog($importId, 'completed', $successCount, $failedCount, $emailSentCount, $emailFailedCount);
            
            // 8. Ghi nhật ký audit
            $this->logAction($importedBy, 'bulk_create_employees', 'bulk_import_logs', $importId, null, [
                'total' => count($records),
                'success' => $successCount,
                'failed' => $failedCount,
                'email_sent' => $emailSentCount,
                'email_failed' => $emailFailedCount
            ], $warehouseId);
            
            // 9. Tạo result file
            $resultFilePath = $this->generateResultFile($importId, $resultDetails, $invalidRecords);
            $this->updateBulkImportResultFile($importId, $resultFilePath);
            
            // 10. Gửi summary email to manager
            $managerInfo = $this->getUserInfo($importedBy);
            if ($managerInfo && $managerInfo['email']) {
                $this->emailService->sendBulkImportResultEmail(
                    $managerInfo['email'],
                    $managerInfo['full_name'],
                    [
                        'total' => count($records),
                        'success' => $successCount,
                        'failed' => $failedCount + count($invalidRecords),
                        'email_sent' => $emailSentCount,
                        'email_failed' => $emailFailedCount,
                        'success_rate' => round($successCount * 100 / count($records), 2),
                        'result_file_url' => $this->getResultFileUrl($resultFilePath)
                    ]
                );
            }
            
            $totalRecords = count($records);
            return [
                'success' => true,
                'message' => "Import hoàn tất. Tạo thành công {$successCount}/{$totalRecords} tài khoản.",
                'import_id' => $importId,
                'total' => count($records),
                'valid' => count($validRecords),
                'invalid' => count($invalidRecords),
                'success_count' => $successCount,
                'failed_count' => $failedCount,
                'email_sent' => $emailSentCount,
                'email_failed' => $emailFailedCount,
                'result_file' => $resultFilePath,
                'details' => $resultDetails,
                'invalid_records' => $invalidRecords
            ];
            
        } catch (Exception $e) {
            error_log("Bulk import error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Lỗi khi import: ' . $e->getMessage()
            ];
        }
    }
    
    // ==================== UC-NV-06: CẬP NHẬT THÔNG TIN ====================
    
    /**
     * Cập nhật thông tin nhân viên (UC_NV_S)
     * 
     * @param int $userId User ID cần cập nhật
     * @param array $data Dữ liệu cập nhật
     * @param int $updatedBy User ID người cập nhật
     * @return array Result
     */
    public function updateEmployee($userId, $data, $updatedBy) {
        try {
            $this->pdo->beginTransaction();
            
            // 1. Lấy thông tin cũ
            $oldData = $this->getUserInfo($userId);
            if (!$oldData) {
                return [
                    'success' => false,
                    'message' => 'Nhân viên không tồn tại'
                ];
            }
            
            // 2. Validate warehouse isolation (Tiền điều kiện: Quản lý chỉ được sửa nhân viên trong cùng kho)
            $updaterInfo = $this->getUserInfo($updatedBy);
            if ($updaterInfo['role'] !== 'admin' && $oldData['warehouse_id'] != $updaterInfo['warehouse_id']) {
                return [
                    'success' => false,
                    'message' => 'Bạn không có quyền cập nhật nhân viên từ kho khác'
                ];
            }
            
            // 3. Validate dữ liệu đầu vào (Luồng ngoại lệ 6b)
            $validation = $this->validateUpdateEmployeeData($data);
            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'message' => 'Vui lòng điền đầy đủ thông tin hợp lệ',
                    'errors' => $validation['errors']
                ];
            }
            
            // 4. Kiểm tra email trùng lặp (Luồng ngoại lệ 6c)
            if (isset($data['email']) && $data['email'] !== $oldData['email']) {
                if ($this->emailExistsForOtherUser($data['email'], $userId)) {
                    return [
                        'success' => false,
                        'message' => 'Email đã trùng với người khác'
                    ];
                }
            }
            
            // 5. Kiểm tra số điện thoại trùng lặp (Luồng ngoại lệ 6c)
            if (isset($data['phone']) && !empty($data['phone']) && $data['phone'] !== $oldData['phone']) {
                if ($this->phoneExistsForOtherUser($data['phone'], $userId)) {
                    return [
                        'success' => false,
                        'message' => 'Số điện thoại đã trùng với người khác'
                    ];
                }
            }
            
            // 6. Chuẩn bị dữ liệu update (chỉ cho phép update một số trường)
            // Các trường được phép: họ tên, email, số điện thoại, vai trò (và warehouse_id cho admin)
            $allowedFields = ['full_name', 'email', 'phone', 'role'];
            if ($updaterInfo['role'] === 'admin') {
                $allowedFields[] = 'warehouse_id';
            }
            
            $updateData = [];
            $updateFields = [];
            $changes = [];
            
            foreach ($allowedFields as $field) {
                if (isset($data[$field]) && $data[$field] !== $oldData[$field]) {
                    $updateData[$field] = $data[$field];
                    $updateFields[] = "$field = :$field";
                    $changes[$field] = [
                        'old' => $oldData[$field],
                        'new' => $data[$field]
                    ];
                }
            }
            
            // 7. Kiểm tra có thay đổi không (Luồng thay thế 8a)
            if (empty($updateFields)) {
                return [
                    'success' => false,
                    'message' => 'Không có thông tin nào được thay đổi'
                ];
            }
            
            // 8. Update database (Bước 9)
            $sql = "UPDATE users SET " . implode(', ', $updateFields) . ", updated_at = NOW() WHERE user_id = :user_id";
            $updateData['user_id'] = $userId;
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($updateData);
            
            // 9. Ghi log vào audit_logs với action='update_employee' (Bước 10)
            $this->logAction($updatedBy, 'update_employee', 'users', $userId, $oldData, $changes, $_SESSION['warehouse_id'] ?? $oldData['warehouse_id']);
            
            // 10. Gửi email nếu đổi role
            if (isset($updateData['role']) && $updateData['role'] !== $oldData['role']) {
                $this->emailService->sendRoleChangedEmail(
                    isset($updateData['email']) ? $updateData['email'] : $oldData['email'],
                    isset($updateData['full_name']) ? $updateData['full_name'] : $oldData['full_name'],
                    $oldData['role'],
                    $updateData['role']
                );
            }
            
            $this->pdo->commit();
            
            return [
                'success' => true,
                'message' => 'Cập nhật thông tin thành công'
            ];
            
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            error_log("Update employee error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Lỗi khi cập nhật: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Validate dữ liệu khi update nhân viên
     */
    private function validateUpdateEmployeeData($data) {
        $errors = [];
        
        // Họ tên (bắt buộc)
        if (isset($data['full_name'])) {
            if (empty(trim($data['full_name']))) {
                $errors['full_name'] = 'Họ tên không được để trống';
            } elseif (strlen($data['full_name']) < 3) {
                $errors['full_name'] = 'Họ tên phải có ít nhất 3 ký tự';
            }
        }
        
        // Email (bắt buộc và phải đúng định dạng)
        if (isset($data['email'])) {
            if (empty(trim($data['email']))) {
                $errors['email'] = 'Email không được để trống';
            } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'Email không đúng định dạng';
            }
        }
        
        // Số điện thoại (không bắt buộc, nhưng nếu có thì phải đúng định dạng)
        if (isset($data['phone']) && !empty($data['phone'])) {
            if (!preg_match('/^[0-9]{10,11}$/', $data['phone'])) {
                $errors['phone'] = 'Số điện thoại phải có 10-11 chữ số';
            }
        }
        
        // Vai trò (bắt buộc)
        if (isset($data['role'])) {
            if (empty($data['role'])) {
                $errors['role'] = 'Vai trò không được để trống';
            } elseif (!in_array($data['role'], ['admin', 'manager', 'staff'])) {
                $errors['role'] = 'Vai trò không hợp lệ';
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    /**
     * Kiểm tra email đã tồn tại cho user khác
     */
    private function emailExistsForOtherUser($email, $excludeUserId) {
        $stmt = $this->pdo->prepare("SELECT user_id FROM users WHERE email = :email AND user_id != :user_id");
        $stmt->execute([
            ':email' => $email,
            ':user_id' => $excludeUserId
        ]);
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Kiểm tra số điện thoại đã tồn tại cho user khác
     */
    private function phoneExistsForOtherUser($phone, $excludeUserId) {
        $stmt = $this->pdo->prepare("SELECT user_id FROM users WHERE phone = :phone AND user_id != :user_id");
        $stmt->execute([
            ':phone' => $phone,
            ':user_id' => $excludeUserId
        ]);
        return $stmt->rowCount() > 0;
    }
    
    // ==================== UC-NV-07: VÔ HIỆU HÓA / KÍCH HOẠT ====================
    
    /**
     * Vô hiệu hóa tài khoản
     */
    public function deactivateEmployee($userId, $reason, $deactivatedBy) {
        try {
            $this->pdo->beginTransaction();
            
            // Không cho phép tự vô hiệu hóa
            if ($userId == $deactivatedBy) {
                return [
                    'success' => false,
                    'message' => 'Bạn không thể vô hiệu hóa tài khoản của chính mình'
                ];
            }
            
            // Lấy user info
            $userInfo = $this->getUserInfo($userId);
            if (!$userInfo) {
                return ['success' => false, 'message' => 'Nhân viên không tồn tại'];
            }
            
            // Cập nhật status
            $stmt = $this->pdo->prepare("
                UPDATE users 
                SET status = 'inactive', 
                    deactivated_at = NOW(), 
                    updated_at = NOW()
                WHERE user_id = :user_id
            ");
            $stmt->execute([':user_id' => $userId]);
            
            // Log - dùng warehouse_id của user thực hiện thao tác
            $this->logAction($deactivatedBy, 'deactivate_employee', 'users', $userId, 
                ['status' => 'active'], 
                ['status' => 'inactive', 'reason' => $reason], 
                $_SESSION['warehouse_id'] ?? $userInfo['warehouse_id']
            );
            
            // Gửi email
            $this->emailService->sendAccountDeactivatedEmail(
                $userInfo['email'],
                $userInfo['full_name'],
                $reason
            );
            
            $this->pdo->commit();
            
            return [
                'success' => true,
                'message' => 'Đã vô hiệu hóa tài khoản'
            ];
            
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            return ['success' => false, 'message' => 'Lỗi: ' . $e->getMessage()];
        }
    }
    
    /**
     * Kích hoạt tài khoản
     */
    public function activateEmployee($userId, $activatedBy) {
        try {
            $this->pdo->beginTransaction();
            
            $userInfo = $this->getUserInfo($userId);
            if (!$userInfo) {
                return ['success' => false, 'message' => 'Nhân viên không tồn tại'];
            }
            
            // Sinh mật khẩu tạm thời mới - KHÔNG trùng với 3 mật khẩu cũ
            $temporaryPassword = $this->generateTemporaryPassword(10, $userId);
            $passwordHash = password_hash($temporaryPassword, PASSWORD_DEFAULT);
            
            // Cập nhật status và mật khẩu mới
            $stmt = $this->pdo->prepare("
                UPDATE users 
                SET status = 'active',
                    password_hash = :password_hash,
                    must_change_password = 1,
                    activated_at = NOW(),
                    failed_login_attempts = 0,
                    locked_until = NULL,
                    updated_at = NOW()
                WHERE user_id = :user_id
            ");
            $stmt->execute([
                ':user_id' => $userId,
                ':password_hash' => $passwordHash
            ]);
            
            // Lưu password history để tránh tái sử dụng
            $this->savePasswordHistory($userId, $passwordHash);
            
            // Ghi log - dùng warehouse_id của user thực hiện thao tác
            $this->logAction($activatedBy, 'activate_employee', 'users', $userId, 
                ['status' => $userInfo['status']], 
                ['status' => 'active', 'password_reset' => true], 
                $_SESSION['warehouse_id'] ?? $userInfo['warehouse_id']
            );
            
            // Lấy tên kho
            $warehouseName = $this->getWarehouseName($userInfo['warehouse_id']);
            
            // Gửi email chào mừng với mật khẩu mới
            $emailResult = $this->emailService->sendWelcomeEmail(
                $userInfo['email'],
                $userInfo['full_name'],
                $userInfo['username'],
                $temporaryPassword,
                $warehouseName
            );
            
            $this->pdo->commit();
            
            return [
                'success' => true,
                'message' => 'Đã kích hoạt tài khoản thành công' . 
                            ($emailResult['success'] ? '. Email đã được gửi đến nhân viên.' : 
                             '. Tuy nhiên không thể gửi email.'),
                'data' => [
                    'email_sent' => $emailResult['success']
                    // Không trả về password vì lý do bảo mật
                ]
            ];
            
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            error_log("Activate employee error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Lỗi: ' . $e->getMessage()];
        }
    }
    
    // ==================== UC-NV-08: XEM LỊCH SỬ ====================
    
    /**
     * Lấy lịch sử hoạt động nhân viên
     */
    public function getEmployeeActivityLogs($userId, $filters = []) {
        try {
            $sql = "
                SELECT al.*, u.full_name as actor_name
                FROM audit_logs al
                LEFT JOIN users u ON al.user_id = u.user_id
                WHERE (al.user_id = :user_id OR al.record_id = :user_id)
            ";
            
            $params = [':user_id' => $userId];
            
            // Filters
            if (!empty($filters['start_date'])) {
                $sql .= " AND al.created_at >= :start_date";
                $params[':start_date'] = $filters['start_date'];
            }
            
            if (!empty($filters['end_date'])) {
                $sql .= " AND al.created_at <= :end_date";
                $params[':end_date'] = $filters['end_date'];
            }
            
            if (!empty($filters['action'])) {
                $sql .= " AND al.action = :action";
                $params[':action'] = $filters['action'];
            }
            
            $sql .= " ORDER BY al.created_at DESC LIMIT 100";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            
            return [
                'success' => true,
                'logs' => $stmt->fetchAll(PDO::FETCH_ASSOC)
            ];
            
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Lỗi: ' . $e->getMessage()];
        }
    }
    
    // ==================== HELPER FUNCTIONS ====================
    
    /**
     * Generate temporary password
     */
    /**
     * Generate temporary password (8-12 ký tự)
     * Đảm bảo KHÔNG tái sử dụng mật khẩu cũ
     * 
     * @param int $length Độ dài mật khẩu (8-12)
     * @param int $userId ID người dùng (để check history)
     * @return string
     */
    private function generateTemporaryPassword($length = 10, $userId = null) {
        $uppercase = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lowercase = 'abcdefghijkmnopqrstuvwxyz';
        $numbers = '23456789';
        $special = '!@#$%&*';
        
        // Giới hạn length trong khoảng 8-12
        $length = max(8, min(12, $length));
        
        $maxAttempts = 10; // Tối đa 10 lần thử để tránh vòng lặp vô hạn
        $attempts = 0;
        
        do {
            $password = '';
            
            // Đảm bảo có ít nhất 1 ký tự mỗi loại
            $password .= $uppercase[random_int(0, strlen($uppercase) - 1)];
            $password .= $lowercase[random_int(0, strlen($lowercase) - 1)];
            $password .= $numbers[random_int(0, strlen($numbers) - 1)];
            $password .= $special[random_int(0, strlen($special) - 1)];
            
            // Điền các ký tự còn lại
            $allChars = $uppercase . $lowercase . $numbers . $special;
            for ($i = 4; $i < $length; $i++) {
                $password .= $allChars[random_int(0, strlen($allChars) - 1)];
            }
            
            // Shuffle để random vị trí
            $password = str_shuffle($password);
            
            // Kiểm tra KHÔNG trùng với mật khẩu cũ (nếu có userId)
            $isUnique = true;
            if ($userId) {
                $isUnique = !$this->isPasswordPreviouslyUsed($userId, $password);
            }
            
            $attempts++;
            
        } while (!$isUnique && $attempts < $maxAttempts);
        
        // Nếu vẫn trùng sau 10 lần (rất hiếm), thêm timestamp để đảm bảo unique
        if (!$isUnique) {
            $password .= substr(time(), -2); // Thêm 2 chữ số cuối timestamp
        }
        
        return $password;
    }
    
    /**
     * Kiểm tra mật khẩu có bị tái sử dụng không
     * So sánh với 3 mật khẩu gần nhất
     */
    private function isPasswordPreviouslyUsed($userId, $plainPassword) {
        try {
            // Lấy 3 mật khẩu gần nhất từ password_history
            $stmt = $this->pdo->prepare("
                SELECT password_hash 
                FROM password_history 
                WHERE user_id = :user_id 
                ORDER BY changed_at DESC 
                LIMIT 3
            ");
            $stmt->execute([':user_id' => $userId]);
            $hashes = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Kiểm tra từng hash
            foreach ($hashes as $hash) {
                if (password_verify($plainPassword, $hash)) {
                    return true; // Trùng với mật khẩu cũ
                }
            }
            
            return false; // Không trùng
            
        } catch (PDOException $e) {
            // Nếu bảng password_history chưa tồn tại, bỏ qua check
            error_log("Password history check error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Lưu password vào history
     * Giữ tối đa 5 password gần nhất
     */
    private function savePasswordHistory($userId, $passwordHash) {
        try {
            // Insert password mới vào history
            $stmt = $this->pdo->prepare("
                INSERT INTO password_history (user_id, password_hash, changed_at)
                VALUES (:user_id, :password_hash, NOW())
            ");
            $stmt->execute([
                ':user_id' => $userId,
                ':password_hash' => $passwordHash
            ]);
            
            // Xóa các password cũ (giữ lại 5 password gần nhất)
            $stmt = $this->pdo->prepare("
                DELETE FROM password_history 
                WHERE user_id = :user_id 
                AND history_id NOT IN (
                    SELECT history_id FROM (
                        SELECT history_id 
                        FROM password_history 
                        WHERE user_id = :user_id 
                        ORDER BY changed_at DESC 
                        LIMIT 5
                    ) AS keep_these
                )
            ");
            $stmt->execute([':user_id' => $userId]);
            
        } catch (PDOException $e) {
            // Nếu bảng chưa tồn tại, tạo bảng
            if ($e->getCode() == '42S02') { // Table doesn't exist
                $this->createPasswordHistoryTable();
                // Thử lại insert
                $stmt = $this->pdo->prepare("
                    INSERT INTO password_history (user_id, password_hash, changed_at)
                    VALUES (:user_id, :password_hash, NOW())
                ");
                $stmt->execute([
                    ':user_id' => $userId,
                    ':password_hash' => $passwordHash
                ]);
            } else {
                error_log("Save password history error: " . $e->getMessage());
            }
        }
    }
    
    /**
     * Tạo bảng password_history nếu chưa tồn tại
     */
    private function createPasswordHistoryTable() {
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS password_history (
                    history_id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    password_hash VARCHAR(255) NOT NULL,
                    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_user_id (user_id),
                    INDEX idx_changed_at (changed_at),
                    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                COMMENT='Lịch sử mật khẩu - tránh tái sử dụng mật khẩu cũ'
            ");
            error_log("Password history table created successfully");
        } catch (PDOException $e) {
            error_log("Create password history table error: " . $e->getMessage());
        }
    }
    
    /**
     * Generate employee code
     */
    private function generateEmployeeCode() {
        $stmt = $this->pdo->query("SELECT MAX(CAST(SUBSTRING(employee_code, 4) AS UNSIGNED)) as max_code FROM users WHERE employee_code LIKE 'EMP%'");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $nextNumber = ($result['max_code'] ?? 0) + 1;
        return 'EMP' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
    }
    
    /**
     * Validate employee data
     */
    private function validateEmployeeData($data, $bulkMode = false) {
        $errors = [];
        
        // Required fields
        if (empty($data['full_name'])) {
            $errors[] = 'Họ tên là bắt buộc';
        } elseif (mb_strlen($data['full_name']) < 2 || mb_strlen($data['full_name']) > 100) {
            $errors[] = 'Họ tên phải từ 2-100 ký tự';
        }
        
        if (empty($data['email'])) {
            $errors[] = 'Email là bắt buộc';
        } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email không hợp lệ';
        }
        
        if (empty($data['username'])) {
            $errors[] = 'Username là bắt buộc';
        } elseif (!preg_match('/^[a-zA-Z0-9_.]{4,50}$/', $data['username'])) {
            $errors[] = 'Username phải từ 4-50 ký tự, chỉ chứa chữ, số, dấu chấm và gạch dưới';
        }
        
        // Phone validation (optional)
        if (!empty($data['phone'])) {
            if (!preg_match('/^0[0-9]{9,10}$/', $data['phone'])) {
                $errors[] = 'Số điện thoại không hợp lệ';
            }
        }
        
        // Role validation
        if (!empty($data['role'])) {
            $validRoles = ['manager', 'staff'];
            if (!in_array(strtolower($data['role']), $validRoles)) {
                $errors[] = 'Vai trò không hợp lệ. Chỉ chấp nhận: manager, staff';
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    /**
     * Check email exists
     */
    private function emailExists($email, $excludeUserId = null) {
        $sql = "SELECT COUNT(*) FROM users WHERE email = :email";
        $params = [':email' => $email];
        
        if ($excludeUserId) {
            $sql .= " AND user_id != :user_id";
            $params[':user_id'] = $excludeUserId;
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn() > 0;
    }
    
    /**
     * Check username exists
     */
    private function usernameExists($username, $excludeUserId = null) {
        $sql = "SELECT COUNT(*) FROM users WHERE username = :username";
        $params = [':username' => $username];
        
        if ($excludeUserId) {
            $sql .= " AND user_id != :user_id";
            $params[':user_id'] = $excludeUserId;
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn() > 0;
    }
    
    /**
     * Get warehouse name
     */
    private function getWarehouseName($warehouseId) {
        $stmt = $this->pdo->prepare("SELECT name FROM warehouses WHERE warehouse_id = :id");
        $stmt->execute([':id' => $warehouseId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['name'] : '';
    }
    
    /**
     * Get user info
     */
    private function getUserInfo($userId) {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE user_id = :id");
        $stmt->execute([':id' => $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Log action to audit_logs
     */
    private function logAction($userId, $action, $tableName, $recordId, $oldValues, $newValues, $warehouseId) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO audit_logs 
                (user_id, action, table_name, record_id, old_values, new_values, warehouse_id, ip_address, user_agent, created_at)
                VALUES 
                (:user_id, :action, :table_name, :record_id, :old_values, :new_values, :warehouse_id, :ip, :ua, NOW())
            ");
            
            $stmt->execute([
                ':user_id' => $userId,
                ':action' => $action,
                ':table_name' => $tableName,
                ':record_id' => $recordId,
                ':old_values' => $oldValues ? json_encode($oldValues) : null,
                ':new_values' => $newValues ? json_encode($newValues) : null,
                ':warehouse_id' => $warehouseId,
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
        } catch (PDOException $e) {
            error_log("Log action error: " . $e->getMessage());
        }
    }
    
    /**
     * Create bulk import log
     */
    private function createBulkImportLog($warehouseId, $importedBy, $fileName, $totalRecords) {
        $stmt = $this->pdo->prepare("
            INSERT INTO bulk_import_logs 
            (warehouse_id, imported_by, file_name, total_records, status, started_at, created_at)
            VALUES 
            (:warehouse_id, :imported_by, :file_name, :total_records, 'processing', NOW(), NOW())
        ");
        
        $stmt->execute([
            ':warehouse_id' => $warehouseId,
            ':imported_by' => $importedBy,
            ':file_name' => $fileName,
            ':total_records' => $totalRecords
        ]);
        
        return $this->pdo->lastInsertId();
    }
    
    /**
     * Update bulk import log
     */
    private function updateBulkImportLog($importId, $status, $successCount, $failedCount, $emailSent = 0, $emailFailed = 0) {
        $stmt = $this->pdo->prepare("
            UPDATE bulk_import_logs 
            SET status = :status, 
                success_count = :success, 
                failed_count = :failed,
                email_sent_count = :email_sent,
                email_failed_count = :email_failed,
                completed_at = NOW()
            WHERE import_id = :import_id
        ");
        
        $stmt->execute([
            ':import_id' => $importId,
            ':status' => $status,
            ':success' => $successCount,
            ':failed' => $failedCount,
            ':email_sent' => $emailSent,
            ':email_failed' => $emailFailed
        ]);
    }
    
    /**
     * Update bulk import result file path
     */
    private function updateBulkImportResultFile($importId, $filePath) {
        $stmt = $this->pdo->prepare("
            UPDATE bulk_import_logs 
            SET result_file_path = :file_path
            WHERE import_id = :import_id
        ");
        
        $stmt->execute([
            ':import_id' => $importId,
            ':file_path' => $filePath
        ]);
    }
    
    /**
     * Validate import file
     */
    private function validateImportFile($filePath) {
        if (!file_exists($filePath)) {
            return ['valid' => false, 'message' => 'File không tồn tại'];
        }
        
        $fileSize = filesize($filePath);
        if ($fileSize > $this->max_file_size) {
            return ['valid' => false, 'message' => 'File vượt quá kích thước cho phép (2MB)'];
        }
        
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if (!in_array($extension, ['xlsx', 'csv'])) {
            return ['valid' => false, 'message' => 'File không đúng định dạng. Chỉ chấp nhận .xlsx hoặc .csv'];
        }
        
        return ['valid' => true];
    }
    
    /**
     * Parse import file (Excel/CSV)
     * Requires: composer require phpoffice/phpspreadsheet
     */
    private function parseImportFile($filePath) {
        try {
            require_once __DIR__ . '/../vendor/autoload.php';
            
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray();
            
            if (empty($rows) || count($rows) < 2) {
                return ['success' => false, 'message' => 'File không có dữ liệu'];
            }
            
            // Xóa header
            $header = array_shift($rows);
            
            if (count($rows) > $this->max_bulk_import_size) {
                return ['success' => false, 'message' => "File chứa quá nhiều bản ghi. Giới hạn tối đa {$this->max_bulk_import_size} nhân viên/lần"];
            }
            
            // Ánh xạ data
            $data = [];
            foreach ($rows as $row) {
                if (empty(array_filter($row))) continue; // Skip empty rows
                
                $data[] = [
                    'full_name' => $row[0] ?? '',
                    'email' => $row[1] ?? '',
                    'username' => $row[2] ?? '',
                    'phone' => $row[3] ?? '',
                    'role' => strtolower($row[4] ?? 'staff')
                ];
            }
            
            return ['success' => true, 'data' => $data];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Lỗi khi đọc file: ' . $e->getMessage()];
        }
    }
    
    /**
     * Generate result Excel file
     */
    private function generateResultFile($importId, $successRecords, $failedRecords) {
        // CẦN LÀM: Tạo file Excel với kết quả
        // For now, return a simple CSV path
        $resultDir = __DIR__ . '/../temp/bulk_imports/';
        if (!is_dir($resultDir)) {
            mkdir($resultDir, 0777, true);
        }
        
        $filename = "import_result_{$importId}_" . date('YmdHis') . ".csv";
        $filepath = $resultDir . $filename;
        
        $file = fopen($filepath, 'w');
        fputcsv($file, ['Row', 'Status', 'Full Name', 'Email', 'Username', 'Generated Password', 'Error Message']);
        
        foreach ($successRecords as $record) {
            fputcsv($file, [
                $record['row'],
                'SUCCESS',
                '',
                '',
                '',
                $record['generated_password'] ?? '',
                ''
            ]);
        }
        
        foreach ($failedRecords as $record) {
            fputcsv($file, [
                $record['row'],
                'FAILED',
                $record['data']['full_name'] ?? '',
                $record['data']['email'] ?? '',
                $record['data']['username'] ?? '',
                '',
                implode('; ', $record['errors'])
            ]);
        }
        
        fclose($file);
        
        return $filepath;
    }
    
    /**
     * Get result file URL
     */
    private function getResultFileUrl($filePath) {
        $baseUrl = 'http://' . $_SERVER['HTTP_HOST'] . '/warehouse3/';
        $relativePath = str_replace(__DIR__ . '/../', '', $filePath);
        return $baseUrl . $relativePath;
    }
}
?>
