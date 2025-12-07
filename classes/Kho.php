<?php
require_once __DIR__ . '/../config/cau_hinh_csdl.php';

class Warehouse {
    private $conn;
    private $table_name = "warehouses";
    
    public $warehouse_id;
    public $name;
    public $address;
    public $status;
    public $created_at;
    public $updated_at;
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    /**
     * Kiểm tra tên kho đã tồn tại chưa
     */
    public function nameExists($name, $excludeId = null) {
        $query = "SELECT warehouse_id FROM " . $this->table_name . " WHERE name = :name";
        if ($excludeId) {
            $query .= " AND warehouse_id != :exclude_id";
        }
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':name', $name);
        if ($excludeId) {
            $stmt->bindParam(':exclude_id', $excludeId);
        }
        $stmt->execute();
        
        return $stmt->rowCount() > 0;
    }
    

    
    /**
     * Tạo kho mới
     */
    public function create() {
        $query = "INSERT INTO " . $this->table_name . "
                  (name, address, status)
                  VALUES (:name, :address, :status)";
        
        $stmt = $this->conn->prepare($query);
        
        // Sanitize input
        $this->name = htmlspecialchars(strip_tags($this->name));
        $this->address = htmlspecialchars(strip_tags($this->address));
        $this->status = $this->status ?? 'active';
        
        // Bind parameters
        $stmt->bindParam(':name', $this->name);
        $stmt->bindParam(':address', $this->address);
        $stmt->bindParam(':status', $this->status);
        
        if ($stmt->execute()) {
            $this->warehouse_id = $this->conn->lastInsertId();
            return true;
        }
        
        return false;
    }
    
    /**
     * Cập nhật thông tin kho
     */
    public function update() {
        $query = "UPDATE " . $this->table_name . " 
                  SET name = :name, address = :address, status = :status
                  WHERE warehouse_id = :warehouse_id";
        
        $stmt = $this->conn->prepare($query);
        
        // Sanitize input
        $this->name = htmlspecialchars(strip_tags($this->name));
        $this->address = htmlspecialchars(strip_tags($this->address));
        $this->status = $this->status ?? 'active';
        
        // Bind parameters
        $stmt->bindParam(':name', $this->name);
        $stmt->bindParam(':address', $this->address);
        $stmt->bindParam(':status', $this->status);
        $stmt->bindParam(':warehouse_id', $this->warehouse_id);
        
        return $stmt->execute();
    }
    
    /**
     * Validate dữ liệu đầu vào
     */
    public function validate() {
        $errors = [];
        
        // Kiểm tra tên kho
        if (empty($this->name)) {
            $errors[] = "Tên kho không được để trống";
        } elseif ($this->nameExists($this->name, $this->warehouse_id)) {
            $errors[] = "Tên kho đã được sử dụng";
        }
        
        // Kiểm tra địa chỉ
        if (empty($this->address)) {
            $errors[] = "Địa chỉ không được để trống";
        }
        
        return $errors;
    }
    
    /**
     * Lấy thông tin kho theo ID
     */
    public function getById($id) {
        $query = "SELECT * FROM " . $this->table_name . " WHERE warehouse_id = :warehouse_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':warehouse_id', $id);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $this->warehouse_id = $row['warehouse_id'];
            $this->name = $row['name'];
            $this->address = $row['address'];
            $this->status = $row['status'];
            $this->created_at = $row['created_at'];
            $this->updated_at = $row['updated_at'];
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Lấy danh sách tất cả kho
     */
    public function getAll() {
        $query = "SELECT * FROM " . $this->table_name . " ORDER BY created_at DESC";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Lấy danh sách kho đang hoạt động
     */
    public function getActive() {
        $query = "SELECT * FROM " . $this->table_name . " WHERE status = 'active' ORDER BY name";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Xóa kho
     */
    public function delete($id) {
        $query = "DELETE FROM " . $this->table_name . " WHERE warehouse_id = :warehouse_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':warehouse_id', $id);
        
        return $stmt->execute();
    }
}
?>