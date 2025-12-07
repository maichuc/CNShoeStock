<?php
/**
 * Class quản lý phân quyền Warehouse
 * Đảm bảo mỗi user chỉ thấy dữ liệu của warehouse mình
 */

class WarehouseAccessControl {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Kiểm tra quyền truy cập warehouse
     */
    public function checkAccess($userId, $targetWarehouseId) {
        $stmt = $this->pdo->prepare("SELECT CheckWarehouseAccess(?, ?) as has_access");
        $stmt->execute([$userId, $targetWarehouseId]);
        $result = $stmt->fetch();
        return $result['has_access'] == 1;
    }
    
    /**
     * Lấy warehouse_id của user
     */
    public function getUserWarehouseId($userId) {
        $stmt = $this->pdo->prepare("SELECT warehouse_id FROM users WHERE user_id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        return $user ? $user['warehouse_id'] : null;
    }
    
    /**
     * Lấy danh sách sản phẩm theo warehouse
     */
    public function getWarehouseProducts($warehouseId, $limit = null, $offset = 0) {
        $sql = "
            SELECT 
                p.product_id,
                p.name as product_name,
                p.description,
                p.brand,
                p.type,
                p.material,
                p.features,
                p.tags,
                p.created_at,
                w.name as warehouse_name,
                COUNT(DISTINCT pv.variant_id) as variant_count,
                COALESCE(SUM(i.quantity), 0) as total_stock,
                (SELECT file_path FROM product_images pi 
                 WHERE pi.product_id = p.product_id AND pi.is_primary = 1 
                 LIMIT 1) as primary_image
            FROM products p
            LEFT JOIN warehouses w ON p.warehouse_id = w.warehouse_id
            LEFT JOIN product_variants pv ON p.product_id = pv.product_id
            LEFT JOIN inventory i ON pv.variant_id = i.variant_id AND i.warehouse_id = p.warehouse_id
            WHERE p.warehouse_id = ?
            GROUP BY p.product_id
            ORDER BY p.created_at DESC
        ";
        
        if ($limit) {
            $sql .= " LIMIT $limit OFFSET $offset";
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$warehouseId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Lấy thông tin chi tiết sản phẩm (kiểm tra quyền truy cập)
     */
    public function getProductDetails($productId, $userId) {
        // Kiểm tra sản phẩm thuộc warehouse nào
        $stmt = $this->pdo->prepare("SELECT warehouse_id FROM products WHERE product_id = ?");
        $stmt->execute([$productId]);
        $product = $stmt->fetch();
        
        if (!$product) {
            throw new Exception("Sản phẩm không tồn tại");
        }
        
        // Kiểm tra quyền truy cập
        if (!$this->checkAccess($userId, $product['warehouse_id'])) {
            throw new Exception("Không có quyền truy cập sản phẩm này");
        }
        
        // Lấy thông tin chi tiết
        $sql = "
            SELECT 
                p.*,
                w.name as warehouse_name,
                u.full_name as manager_name,
                u.phone as manager_phone,
                u.email as manager_email
            FROM products p
            LEFT JOIN warehouses w ON p.warehouse_id = w.warehouse_id
            LEFT JOIN users u ON w.warehouse_id = u.warehouse_id AND u.role = 'manager'
            WHERE p.product_id = ?
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$productId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Lấy variants của sản phẩm (có kiểm tra quyền)
     */
    public function getProductVariants($productId, $userId) {
        // Kiểm tra quyền truy cập sản phẩm trước
        $this->getProductDetails($productId, $userId);
        
        $sql = "
            SELECT 
                pv.*,
                COALESCE(i.quantity, 0) as stock_quantity,
                i.updated_at as stock_updated_at
            FROM product_variants pv
            LEFT JOIN inventory i ON pv.variant_id = i.variant_id 
                AND i.warehouse_id = pv.warehouse_id
            WHERE pv.product_id = ?
            ORDER BY pv.size, pv.color
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$productId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Tạo sản phẩm mới (tự động gán warehouse_id)
     */
    public function createProduct($productData, $userId) {
        $userWarehouseId = $this->getUserWarehouseId($userId);
        if (!$userWarehouseId) {
            throw new Exception("User không thuộc warehouse nào");
        }
        
        // Thêm warehouse_id vào dữ liệu sản phẩm
        $productData['warehouse_id'] = $userWarehouseId;
        
        $sql = "
            INSERT INTO products (
                warehouse_id, name, description, 
                brand, type, material, features, tags
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            $productData['warehouse_id'],
            $productData['name'],
            $productData['description'],
            $productData['brand'] ?? null,
            $productData['type'] ?? null,
            $productData['material'] ?? null,
            $productData['features'] ?? null,
            $productData['tags'] ?? null
        ]);
        
        return $this->pdo->lastInsertId();
    }
    
    /**
     * Cập nhật sản phẩm (kiểm tra quyền)
     */
    public function updateProduct($productId, $productData, $userId) {
        // Kiểm tra quyền truy cập
        $this->getProductDetails($productId, $userId);
        
        $allowedFields = ['name', 'description', 'brand', 'type', 'material', 'features', 'tags'];
        $updateFields = [];
        $values = [];
        
        foreach ($allowedFields as $field) {
            if (isset($productData[$field])) {
                $updateFields[] = "$field = ?";
                $values[] = $productData[$field];
            }
        }
        
        if (empty($updateFields)) {
            throw new Exception("Không có dữ liệu để cập nhật");
        }
        
        $values[] = $productId;
        
        $sql = "UPDATE products SET " . implode(', ', $updateFields) . " WHERE product_id = ?";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($values);
    }
    
    /**
     * Xóa sản phẩm (kiểm tra quyền)
     */
    public function deleteProduct($productId, $userId) {
        // Kiểm tra quyền truy cập
        $this->getProductDetails($productId, $userId);
        
        // Kiểm tra xem sản phẩm có tồn kho không
        $stmt = $this->pdo->prepare("
            SELECT SUM(i.quantity) as total_stock 
            FROM product_variants pv
            JOIN inventory i ON pv.variant_id = i.variant_id
            WHERE pv.product_id = ?
        ");
        $stmt->execute([$productId]);
        $result = $stmt->fetch();
        
        if ($result['total_stock'] > 0) {
            throw new Exception("Không thể xóa sản phẩm còn tồn kho");
        }
        
        // Xóa sản phẩm (cascade sẽ xóa variants và images)
        $stmt = $this->pdo->prepare("DELETE FROM products WHERE product_id = ?");
        return $stmt->execute([$productId]);
    }
    
    /**
     * Lấy thống kê warehouse
     */
    public function getWarehouseStats($warehouseId) {
        $sql = "
            SELECT 
                COUNT(DISTINCT p.product_id) as total_products,
                COUNT(DISTINCT pv.variant_id) as total_variants,
                COALESCE(SUM(i.quantity), 0) as total_inventory,
                AVG(pv.price) as avg_price
            FROM products p
            LEFT JOIN product_variants pv ON p.product_id = pv.product_id
            LEFT JOIN inventory i ON pv.variant_id = i.variant_id AND i.warehouse_id = p.warehouse_id
            WHERE p.warehouse_id = ?
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$warehouseId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Tìm kiếm sản phẩm trong warehouse
     */
    public function searchProducts($warehouseId, $searchTerm) {
        $sql = "
            SELECT 
                p.product_id,
                p.name as product_name,
                p.description,
                p.brand,
                p.type,
                COUNT(DISTINCT pv.variant_id) as variant_count,
                COALESCE(SUM(i.quantity), 0) as total_stock
            FROM products p
            LEFT JOIN product_variants pv ON p.product_id = pv.product_id
            LEFT JOIN inventory i ON pv.variant_id = i.variant_id AND i.warehouse_id = p.warehouse_id
            WHERE p.warehouse_id = ?
            AND (
                p.name LIKE ? OR 
                p.description LIKE ? OR 
                p.brand LIKE ? OR 
                p.tags LIKE ?
            )
            GROUP BY p.product_id ORDER BY p.name
        ";
        
        $params = [$warehouseId];
        $searchPattern = "%$searchTerm%";
        $params = array_merge($params, [$searchPattern, $searchPattern, $searchPattern, $searchPattern]);
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Lấy danh sách suppliers theo warehouse
     */
    public function getWarehouseSuppliers($warehouseId) {
        $sql = "
            SELECT 
                s.*,
                COUNT(sr.receipt_id) as receipt_count
            FROM suppliers s
            LEFT JOIN stock_receipts sr ON s.supplier_id = sr.supplier_id
            WHERE s.warehouse_id = ?
            GROUP BY s.supplier_id
            ORDER BY s.name
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$warehouseId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Cập nhật supplier (kiểm tra quyền)
     */
    public function createSupplier($supplierData, $userId) {
        $userWarehouseId = $this->getUserWarehouseId($userId);
        if (!$userWarehouseId) {
            throw new Exception("User không thuộc warehouse nào");
        }
        
        // DEBUG: Log dữ liệu nhận được
        error_log("=== CREATE SUPPLIER DEBUG ===");
        error_log("Raw supplierData: " . print_r($supplierData, true));
        error_log("contact_name value: '" . ($supplierData['contact_name'] ?? 'NULL') . "'");
        error_log("contact_info value: '" . ($supplierData['contact_info'] ?? 'NULL') . "'");
        error_log("contact_name empty: " . (empty($supplierData['contact_name']) ? 'YES' : 'NO'));
        error_log("contact_info empty: " . (empty($supplierData['contact_info']) ? 'YES' : 'NO'));
        
        // Xử lý empty string thành NULL, nhưng giữ giá trị nếu có
        $contactName = isset($supplierData['contact_name']) && trim($supplierData['contact_name']) !== '' 
            ? trim($supplierData['contact_name']) 
            : null;
        $contactInfo = isset($supplierData['contact_info']) && trim($supplierData['contact_info']) !== '' 
            ? trim($supplierData['contact_info']) 
            : null;
        
        error_log("Final contact_name: '" . ($contactName ?? 'NULL') . "'");
        error_log("Final contact_info: '" . ($contactInfo ?? 'NULL') . "'");
        
        $sql = "
            INSERT INTO suppliers (warehouse_id, name, contact_name, phone, email, address, contact_info) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            $userWarehouseId,
            $supplierData['name'],
            $contactName,
            $supplierData['phone'] ?? null,
            $supplierData['email'] ?? null,
            $supplierData['address'] ?? null,
            $contactInfo
        ]);
        
        return $this->pdo->lastInsertId();
    }
    
    /**
     * Cập nhật supplier (kiểm tra quyền)
     */
    public function updateSupplier($supplierId, $supplierData, $userId) {
        // Kiểm tra supplier có thuộc warehouse của user không
        $stmt = $this->pdo->prepare("SELECT warehouse_id FROM suppliers WHERE supplier_id = ?");
        $stmt->execute([$supplierId]);
        $supplier = $stmt->fetch();
        
        if (!$supplier) {
            throw new Exception("Supplier không tồn tại");
        }
        
        if (!$this->checkAccess($userId, $supplier['warehouse_id'])) {
            throw new Exception("Không có quyền sửa supplier này");
        }
        
        $allowedFields = ['name', 'contact_name', 'phone', 'email', 'address', 'contact_info'];
        $updateFields = [];
        $values = [];
        
        foreach ($allowedFields as $field) {
            if (isset($supplierData[$field])) {
                $updateFields[] = "$field = ?";
                $values[] = $supplierData[$field];
            }
        }
        
        if (empty($updateFields)) {
            throw new Exception("Không có dữ liệu để cập nhật");
        }
        
        $values[] = $supplierId;
        
        $sql = "UPDATE suppliers SET " . implode(', ', $updateFields) . " WHERE supplier_id = ?";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($values);
    }
    
    /**
     * Xóa supplier (kiểm tra quyền và ràng buộc)
     */
    public function deleteSupplier($supplierId, $userId) {
        // Kiểm tra quyền truy cập
        $stmt = $this->pdo->prepare("SELECT warehouse_id FROM suppliers WHERE supplier_id = ?");
        $stmt->execute([$supplierId]);
        $supplier = $stmt->fetch();
        
        if (!$supplier) {
            throw new Exception("Supplier không tồn tại");
        }
        
        if (!$this->checkAccess($userId, $supplier['warehouse_id'])) {
            throw new Exception("Không có quyền xóa supplier này");
        }
        
        // Kiểm tra xem có stock receipts nào đang sử dụng supplier này không
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM stock_receipts WHERE supplier_id = ?");
        $stmt->execute([$supplierId]);
        $receiptCount = $stmt->fetchColumn();
        
        if ($receiptCount > 0) {
            throw new Exception("Không thể xóa supplier đang được sử dụng bởi {$receiptCount} phiếu nhập");
        }
        
        $stmt = $this->pdo->prepare("DELETE FROM suppliers WHERE supplier_id = ?");
        return $stmt->execute([$supplierId]);
    }
    
    /**
     * Lấy thông tin chi tiết warehouse (full data)
     */
    public function getWarehouseFullData($warehouseId, $userId) {
        if (!$this->checkAccess($userId, $warehouseId)) {
            throw new Exception("Không có quyền truy cập warehouse này");
        }
        
        $data = [];
        
        // Thông tin warehouse
        $stmt = $this->pdo->prepare("SELECT * FROM warehouses WHERE warehouse_id = ?");
        $stmt->execute([$warehouseId]);
        $data['warehouse'] = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Suppliers
        $data['suppliers'] = $this->getWarehouseSuppliers($warehouseId);
        
        // Products
        $data['products'] = $this->getWarehouseProducts($warehouseId);
        
        // Statistics
        $data['stats'] = $this->getWarehouseStats($warehouseId);
        
        return $data;
    }
    
    /**
     * Kiểm tra middleware cho API
     */
    public function validateWarehouseAccess($userId, $requestedWarehouseId = null) {
        if (!$userId) {
            throw new Exception("User ID không được để trống");
        }
        
        $userWarehouseId = $this->getUserWarehouseId($userId);
        if (!$userWarehouseId) {
            throw new Exception("User không thuộc warehouse nào");
        }
        
        if ($requestedWarehouseId && $requestedWarehouseId != $userWarehouseId) {
            throw new Exception("Không có quyền truy cập warehouse này");
        }
        
        return $userWarehouseId;
    }
    
    /**
     * Kiểm tra quyền truy cập warehouse - method đơn giản
     */
    public function checkWarehouseAccess($userId, $targetWarehouseId) {
        if (!$userId || !$targetWarehouseId) {
            return false;
        }
        
        $userWarehouseId = $this->getUserWarehouseId($userId);
        return $userWarehouseId == $targetWarehouseId;
    }
    
    // ===== CUSTOMERS METHODS =====
    
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
}