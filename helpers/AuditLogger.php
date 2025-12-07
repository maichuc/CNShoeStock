<?php
class AuditLogger {
    private $db;
    
    public function __construct($database) {
        $this->db = $database;
    }
    
    /**
     * Ghi log hành động của user
     */
    public function log($userId, $action, $tableName = null, $recordId = null, $oldValues = null, $newValues = null, $warehouseId = null) {
        try {
            // Lấy warehouse_id từ session nếu không truyền vào
            if ($warehouseId === null && isset($_SESSION['warehouse_id'])) {
                $warehouseId = $_SESSION['warehouse_id'];
            }
            
            $query = "INSERT INTO audit_logs (user_id, action, table_name, record_id, old_values, new_values, warehouse_id, created_at) 
                     VALUES (:user_id, :action, :table_name, :record_id, :old_values, :new_values, :warehouse_id, NOW())";
            
            $stmt = $this->db->prepare($query);
            
            // Chuyển đổi arrays thành JSON
            $oldValuesJson = $oldValues ? json_encode($oldValues, JSON_UNESCAPED_UNICODE) : null;
            $newValuesJson = $newValues ? json_encode($newValues, JSON_UNESCAPED_UNICODE) : null;
            
            $stmt->bindParam(':user_id', $userId);
            $stmt->bindParam(':action', $action);
            $stmt->bindParam(':table_name', $tableName);
            $stmt->bindParam(':record_id', $recordId);
            $stmt->bindParam(':old_values', $oldValuesJson);
            $stmt->bindParam(':new_values', $newValuesJson);
            $stmt->bindParam(':warehouse_id', $warehouseId);
            
            return $stmt->execute();
            
        } catch (Exception $e) {
            error_log("Audit log error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Log đăng ký tài khoản mới
     */
    public function logRegistration($userId, $warehouseId, $userData, $warehouseData) {
        $this->log(
            $userId,
            'account_registration',
            'users',
            $userId,
            null,
            [
                'user_data' => $userData,
                'warehouse_data' => $warehouseData,
                'registration_type' => 'admin_account',
                'email_sent' => true
            ]
        );
    }
    
    /**
     * Log gửi email
     */
    public function logEmailSent($userId, $emailType, $recipient, $success = true) {
        $this->log(
            $userId,
            'email_sent',
            null,
            null,
            null,
            [
                'email_type' => $emailType,
                'recipient' => $recipient,
                'success' => $success,
                'timestamp' => date('Y-m-d H:i:s')
            ]
        );
    }
}
?>