<?php
/**
 * Extension methods cho WarehouseAccessControl - Customers
 */

// Thêm vào class WarehouseAccessControl:

/**
 * Lấy danh sách customers theo warehouse
 */
public function getWarehouseCustomers($warehouseId, $limit = null, $offset = 0) {
    $sql = "
        SELECT 
            c.customer_id,
            c.full_name,
            c.phone,
            c.email,
            c.address,
            c.note,
            c.created_at,
            w.name as warehouse_name
        FROM customers c
        LEFT JOIN warehouses w ON c.warehouse_id = w.warehouse_id
        WHERE c.warehouse_id = ?
        ORDER BY c.created_at DESC
    ";
    
    if ($limit) {
        $sql .= " LIMIT " . intval($limit) . " OFFSET " . intval($offset);
    }
    
    $stmt = $this->pdo->prepare($sql);
    $stmt->execute([$warehouseId]);
    return $stmt->fetchAll();
}

/**
 * Tạo customer mới với warehouse_id
 */
public function createCustomer($warehouseId, $data) {
    $this->validateWarehouseAccess($data["user_id"], $warehouseId);
    
    $sql = "INSERT INTO customers (warehouse_id, full_name, phone, email, address, note) 
            VALUES (?, ?, ?, ?, ?, ?)";
    
    $stmt = $this->pdo->prepare($sql);
    return $stmt->execute([
        $warehouseId,
        $data["full_name"],
        $data["phone"] ?? null,
        $data["email"] ?? null,
        $data["address"] ?? null,
        $data["note"] ?? null
    ]);
}

/**
 * Cập nhật customer (chỉ trong warehouse của user)
 */
public function updateCustomer($customerId, $userId, $data) {
    // Kiểm tra customer có thuộc warehouse của user không
    $stmt = $this->pdo->prepare("
        SELECT c.warehouse_id 
        FROM customers c
        JOIN users u ON c.warehouse_id = u.warehouse_id
        WHERE c.customer_id = ? AND u.user_id = ?
    ");
    $stmt->execute([$customerId, $userId]);
    
    if (!$stmt->fetch()) {
        throw new Exception("Không có quyền sửa customer này");
    }
    
    $sql = "UPDATE customers SET full_name = ?, phone = ?, email = ?, address = ?, note = ? 
            WHERE customer_id = ?";
    
    $stmt = $this->pdo->prepare($sql);
    return $stmt->execute([
        $data["full_name"],
        $data["phone"] ?? null,
        $data["email"] ?? null,
        $data["address"] ?? null,
        $data["note"] ?? null,
        $customerId
    ]);
}

/**
 * Xóa customer (chỉ trong warehouse của user)
 */
public function deleteCustomer($customerId, $userId) {
    // Kiểm tra customer có thuộc warehouse của user không
    $stmt = $this->pdo->prepare("
        SELECT c.warehouse_id 
        FROM customers c
        JOIN users u ON c.warehouse_id = u.warehouse_id
        WHERE c.customer_id = ? AND u.user_id = ?
    ");
    $stmt->execute([$customerId, $userId]);
    
    if (!$stmt->fetch()) {
        throw new Exception("Không có quyền xóa customer này");
    }
    
    $stmt = $this->pdo->prepare("DELETE FROM customers WHERE customer_id = ?");
    return $stmt->execute([$customerId]);
}

/**
 * Tìm kiếm customers trong warehouse
 */
public function searchWarehouseCustomers($warehouseId, $keyword) {
    $sql = "
        SELECT c.*, w.name as warehouse_name
        FROM customers c
        LEFT JOIN warehouses w ON c.warehouse_id = w.warehouse_id
        WHERE c.warehouse_id = ? 
        AND (c.full_name LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)
        ORDER BY c.created_at DESC
    ";
    
    $searchTerm = "%{$keyword}%";
    $stmt = $this->pdo->prepare($sql);
    $stmt->execute([$warehouseId, $searchTerm, $searchTerm, $searchTerm]);
    return $stmt->fetchAll();
}
?>