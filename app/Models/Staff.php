<?php
// app/Models/Staff.php
class Staff {
    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function getList($warehouseId, $role, $search = '') {
        $sql = "SELECT u.*, w.name as warehouse_name 
                FROM users u 
                LEFT JOIN warehouses w ON u.warehouse_id = w.warehouse_id 
                WHERE u.role IN ('admin', 'manager', 'staff')";
        
        $params = [];
        if ($role !== 'admin') {
            $sql .= " AND u.warehouse_id = :warehouse_id";
            $params[':warehouse_id'] = $warehouseId;
        }

        if ($search) {
            $sql .= " AND (u.full_name LIKE :search OR u.email LIKE :search OR u.username LIKE :search)";
            $params[':search'] = "%$search%";
        }

        $sql .= " ORDER BY u.created_at DESC";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getById($id) {
        $sql = "SELECT u.*, w.name as warehouse_name FROM users u LEFT JOIN warehouses w ON u.warehouse_id = w.warehouse_id WHERE u.user_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function updateStatus($id, $status, $reason = null, $performedBy = null) {
        $sql = "UPDATE users SET status = ?, updated_at = NOW() WHERE user_id = ?";
        $stmt = $this->conn->prepare($sql);
        return $stmt->execute([$status, $id]);
    }

    public function checkUsername($username) {
        $stmt = $this->conn->prepare("SELECT user_id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        return $stmt->rowCount() > 0;
    }

    public function updatePassword($userId, $newHash) {
        $sql = "UPDATE users SET password_hash = ?, must_change_password = 0, password_changed_at = NOW() WHERE user_id = ?";
        $stmt = $this->conn->prepare($sql);
        return $stmt->execute([$newHash, $userId]);
    }
}
?>
