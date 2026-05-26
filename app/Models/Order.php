<?php
// app/Models/Order.php
class Order {
    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function getAll($warehouseId, $filters = []) {
        $sql = "SELECT o.*, c.full_name as customer_name 
                FROM orders o 
                LEFT JOIN customers c ON o.customer_id = c.customer_id 
                WHERE o.warehouse_id = ?";
        
        $params = [$warehouseId];
        // Add filters here (status, date range, etc.)
        
        $sql .= " ORDER BY o.created_at DESC";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getById($id) {
        $sql = "SELECT o.*, c.full_name as customer_name, c.phone, c.address 
                FROM orders o 
                LEFT JOIN customers c ON o.customer_id = c.customer_id 
                WHERE o.order_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getItems($orderId) {
        $sql = "SELECT oi.*, p.name as product_name, pv.sku, pv.color, pv.size 
                FROM order_details oi 
                JOIN product_variants pv ON oi.variant_id = pv.variant_id 
                JOIN products p ON pv.product_id = p.product_id 
                WHERE oi.order_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$orderId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function updateStatus($id, $status, $userId) {
        $this->conn->beginTransaction();
        try {
            // Update order status
            $sql = "UPDATE orders SET status = ?, updated_at = NOW() WHERE order_id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$status, $id]);

            // If accepted, reduce inventory and create export slip
            if ($status === 'accepted') {
                $order = $this->getById($id);
                $items = $this->getItems($id);
                
                // 1. Create export slip
                $exportId = $this->createExport($order, $items, $userId);
                
                // 2. Reduce inventory (FIFO)
                foreach ($items as $item) {
                    $this->reduceInventory($item['variant_id'], $item['quantity'], $order['warehouse_id']);
                }

                // 3. Log to audit_logs
                $auditSql = "INSERT INTO audit_logs (user_id, action, table_name, record_id, new_values, warehouse_id, created_at) 
                            VALUES (?, 'accept_order_reduce_inventory', 'orders', ?, ?, ?, NOW())";
                $auditStmt = $this->conn->prepare($auditSql);
                $auditValues = json_encode([
                    'order_id' => $id,
                    'export_id' => $exportId,
                    'status' => $status,
                    'items_count' => count($items)
                ]);
                $auditStmt->execute([$userId, $id, $auditValues, $order['warehouse_id']]);
            }

            $this->conn->commit();
            return true;
        } catch (Exception $e) {
            $this->conn->rollBack();
            throw $e;
        }
    }

    private function createExport($order, $items, $userId) {
        $exportCode = 'EXP' . date('Ymd') . str_pad($order['order_id'], 4, '0', STR_PAD_LEFT);
        
        // Check if warehouse_exports table exists
        $tableCheck = $this->conn->query("SHOW TABLES LIKE 'warehouse_exports'")->rowCount();
        if ($tableCheck === 0) return null;

        $sql = "INSERT INTO warehouse_exports (order_id, warehouse_id, export_code, customer_name, status, created_at, created_by) 
                VALUES (?, ?, ?, ?, 'pending', NOW(), ?)";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$order['order_id'], $order['warehouse_id'], $exportCode, $order['customer_name'], $userId]);
        $exportId = $this->conn->lastInsertId();

        // Create details if table exists
        $detailTableCheck = $this->conn->query("SHOW TABLES LIKE 'warehouse_export_details'")->rowCount();
        if ($detailTableCheck > 0) {
            $detailSql = "INSERT INTO warehouse_export_details (export_id, variant_id, quantity, unit_price, total_price) 
                         VALUES (?, ?, ?, ?, ?)";
            $detailStmt = $this->conn->prepare($detailSql);
            foreach ($items as $item) {
                $detailStmt->execute([$exportId, $item['variant_id'], $item['quantity'], $item['unit_price'], $item['total_price']]);
            }
        }

        return $exportId;
    }

    private function reduceInventory($variantId, $quantity, $warehouseId) {
        // Get positions with inventory, sorted by FIFO (inventory_id ASC)
        $sql = "SELECT inventory_id, quantity FROM inventory 
                WHERE variant_id = ? AND warehouse_id = ? AND quantity > 0 
                ORDER BY inventory_id ASC";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$variantId, $warehouseId]);
        $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalAvailable = array_sum(array_column($locations, 'quantity'));
        if ($totalAvailable < $quantity) {
            throw new Exception("Không đủ tồn kho cho variant_id $variantId. Yêu cầu: $quantity, Có sẵn: $totalAvailable");
        }

        $remainingQty = $quantity;
        foreach ($locations as $location) {
            if ($remainingQty <= 0) break;
            $deductQty = min($remainingQty, $location['quantity']);
            
            $updateSql = "UPDATE inventory SET quantity = quantity - ?, updated_at = NOW() 
                         WHERE inventory_id = ? AND quantity >= ?";
            $updateStmt = $this->conn->prepare($updateSql);
            $updateStmt->execute([$deductQty, $location['inventory_id'], $deductQty]);
            
            if ($updateStmt->rowCount() === 0) {
                throw new Exception("Lỗi khi trừ tồn kho tại inventory_id: " . $location['inventory_id']);
            }
            $remainingQty -= $deductQty;
        }
    }

    public function create($data, $userId) {
        $this->conn->beginTransaction();
        try {
            $customerData = $data['customer'];
            $orderInfo = $data['order'];
            $warehouseId = $orderInfo['warehouse_id'];
            
            // Handle different key names between frontend and backend
            $customerName = $customerData['name'] ?? ($customerData['full_name'] ?? '');
            $customerPhone = $customerData['phone'] ?? '';
            $customerAddress = $customerData['address'] ?? '';
            $customerEmail = $customerData['email'] ?? '';
            $customerNote = $customerData['note'] ?? '';

            // 1. Find or create customer
            $stmt = $this->conn->prepare("SELECT customer_id FROM customers WHERE phone = ? LIMIT 1");
            $stmt->execute([$customerPhone]);
            $customerId = $stmt->fetchColumn();

            if (!$customerId) {
                $stmt = $this->conn->prepare("INSERT INTO customers (full_name, phone, address, email, note, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$customerName, $customerPhone, $customerAddress, $customerEmail, $customerNote]);
                $customerId = $this->conn->lastInsertId();
            }

            // 2. Create order
            $totalPrice = $orderInfo['total_price'] ?? array_sum(array_column($orderInfo['items'], 'total_price'));
            $discount = $orderInfo['discount'] ?? 0;
            
            $sql = "INSERT INTO orders (customer_id, warehouse_id, status, total_price, discount, 
                                      created_by, created_at, updated_at) 
                    VALUES (?, ?, 'pending', ?, ?, ?, NOW(), NOW())";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                $customerId, 
                $warehouseId, 
                $totalPrice,
                $discount,
                $userId
            ]);
            $orderId = $this->conn->lastInsertId();

            // 3. Create order details
            $itemSql = "INSERT INTO order_details (order_id, variant_id, quantity, unit_price, total_price) 
                        VALUES (?, ?, ?, ?, ?)";
            $itemStmt = $this->conn->prepare($itemSql);
            foreach ($orderInfo['items'] as $item) {
                $itemStmt->execute([$orderId, $item['variant_id'], $item['quantity'], $item['unit_price'], $item['total_price']]);
            }

            $this->conn->commit();
            return $orderId;
        } catch (Exception $e) {
            $this->conn->rollBack();
            throw $e;
        }
    }
}
?>
