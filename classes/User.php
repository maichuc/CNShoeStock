<?php
require_once __DIR__ . '/../config/cau_hinh_csdl.php';

class User {
    private $conn;
    private $table_name = "users";

    // Fields aligned with DB schema
    public $user_id;
    public $username;
    public $password_hash;
    public $full_name;
    public $employee_code;
    public $email;
    public $phone;
    public $role; // enum('admin','staff')
    public $warehouse_id; // nullable int
    public $status; // enum('active','inactive')
    public $last_login;
    public $created_at;
    public $updated_at;

    public function __construct($db) {
        $this->conn = $db;
    }

    // Create new user
    public function create() {
        // Generate employee code if not set
        if (empty($this->employee_code)) {
            $this->employee_code = $this->generateEmployeeCode();
        }

        $sql = "INSERT INTO {$this->table_name}
                (username, password_hash, full_name, employee_code, email, phone, role, warehouse_id, status)
                VALUES (:username, :password_hash, :full_name, :employee_code, :email, :phone, :role, :warehouse_id, :status)";

        $stmt = $this->conn->prepare($sql);

        // Sanitize
        $this->username = trim($this->username);
        $this->full_name = trim($this->full_name);
        $this->email = $this->email ? trim($this->email) : null;
        $this->phone = $this->phone ? trim($this->phone) : null;
        $this->role = $this->role ?: 'staff';
        $this->status = $this->status ?: 'active';

        $stmt->bindParam(':username', $this->username);
        $stmt->bindParam(':password_hash', $this->password_hash);
        $stmt->bindParam(':full_name', $this->full_name);
        $stmt->bindParam(':employee_code', $this->employee_code);
        $stmt->bindParam(':email', $this->email);
        $stmt->bindParam(':phone', $this->phone);
        $stmt->bindParam(':role', $this->role);
        $stmt->bindParam(':warehouse_id', $this->warehouse_id);
        $stmt->bindParam(':status', $this->status);

        if ($stmt->execute()) {
            $this->user_id = $this->conn->lastInsertId();
            return true;
        }
        return false;
    }

    // Basic validation for registration
    public function validate() {
        $errors = [];

        if (empty($this->username)) $errors[] = 'Username không được để trống';
        if (empty($this->password_hash)) $errors[] = 'Password không hợp lệ';
        if (empty($this->full_name)) $errors[] = 'Họ tên không được để trống';
        if ($this->email && !filter_var($this->email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email không hợp lệ';

        // Unique username check
        $q = $this->conn->prepare("SELECT user_id FROM {$this->table_name} WHERE username = :u");
        $q->bindParam(':u', $this->username);
        $q->execute();
        if ($q->rowCount() > 0) $errors[] = 'Username đã được sử dụng';

        return $errors;
    }

    // Login using DB schema fields
    public function login($username, $password) {
        $query = "SELECT user_id, username, email, password_hash, full_name, phone, role, status, last_login, warehouse_id
                  FROM {$this->table_name}
                  WHERE (username = :username OR email = :username) AND status = 'active'";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':username', $username);
        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (password_verify($password, $row['password_hash'])) {
                $this->user_id = $row['user_id'];
                $this->username = $row['username'];
                $this->email = $row['email'];
                $this->full_name = $row['full_name'];
                $this->phone = $row['phone'];
                $this->role = $row['role'];
                $this->status = $row['status'];
                $this->last_login = $row['last_login'];
                $this->warehouse_id = $row['warehouse_id'];
                $this->updateLastLogin();
                return true;
            }
        }
        return false;
    }

    public function updateLastLogin() {
        $query = "UPDATE {$this->table_name} SET last_login = CURRENT_TIMESTAMP WHERE user_id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $this->user_id);
        $stmt->execute();
    }

    public function loadById(int $id): bool {
        $stmt = $this->conn->prepare("SELECT * FROM {$this->table_name} WHERE user_id = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            foreach ($row as $k=>$v) { $this->$k = $v; }
            return true;
        }
        return false;
    }

    public static function hashPassword($password) {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    /**
     * Generate unique employee code
     */
    private function generateEmployeeCode() {
        $stmt = $this->conn->query("SELECT MAX(CAST(SUBSTRING(employee_code, 4) AS UNSIGNED)) as max_code FROM users WHERE employee_code LIKE 'EMP%'");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $nextNumber = ($result['max_code'] ?? 0) + 1;
        return 'EMP' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
    }
}
?>
