<?php
class StockReceipt {
    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function getDraftReceipts() {
        $sql = "SELECT sr.receipt_id, sr.receipt_code, sr.created_at, sr.notes,
                       s.name as supplier_name, w.name as warehouse_name,
                       u.full_name as created_by,
                       COUNT(sri.item_id) as total_items,
                       SUM(sri.quantity * sri.unit_price) as total_value
                FROM stock_receipts sr
                LEFT JOIN suppliers s ON sr.supplier_id = s.supplier_id
                LEFT JOIN warehouses w ON sr.warehouse_id = w.warehouse_id
                LEFT JOIN users u ON sr.user_id = u.user_id
                LEFT JOIN stock_receipt_items sri ON sr.receipt_id = sri.receipt_id
                WHERE sr.status = 'draft'
                GROUP BY sr.receipt_id
                ORDER BY sr.created_at DESC";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getReceiptById($receiptId) {
        $sql = "SELECT sr.*, s.name as supplier_name, s.phone as supplier_phone,
                       w.name as warehouse_name, w.address as warehouse_address,
                       u.full_name as created_by
                FROM stock_receipts sr
                LEFT JOIN suppliers s ON sr.supplier_id = s.supplier_id
                LEFT JOIN warehouses w ON sr.warehouse_id = w.warehouse_id
                LEFT JOIN users u ON sr.user_id = u.user_id
                WHERE sr.receipt_id = :id";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':id', $receiptId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getReceiptItems($receiptId) {
        $sql = "SELECT sri.*, p.product_id, p.name as product_name, p.description,
                       CONCAT(COALESCE(pv.color, ''), 
                              CASE WHEN pv.color IS NOT NULL AND pv.size IS NOT NULL THEN ' - ' ELSE '' END,
                              COALESCE(pv.size, '')) as variant_name,
                       pv.sku, pv.color, pv.size, pv.price as current_price,
                       l.shelf_code as location_code, l.description as location_name,
                       qr.qr_code, qr.qr_image_path, pv.product_id
                FROM stock_receipt_items sri
                LEFT JOIN product_variants pv ON sri.variant_id = pv.variant_id
                LEFT JOIN products p ON pv.product_id = p.product_id
                LEFT JOIN locations l ON sri.location_code = l.shelf_code
                LEFT JOIN product_qr_codes qr ON (qr.product_id = p.product_id AND qr.variant_id = pv.variant_id AND qr.is_active = 1)
                WHERE sri.receipt_id = :id
                ORDER BY p.name, pv.color, pv.size";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':id', $receiptId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function updateDraft($receiptId, $supplierId, $notes, $userId) {
        $sql = "UPDATE stock_receipts SET supplier_id = ?, notes = ?, last_modified_by = ?, last_modified_at = NOW() WHERE receipt_id = ?";
        $stmt = $this->conn->prepare($sql);
        return $stmt->execute([$supplierId, $notes, $userId, $receiptId]);
    }

    public function deleteItems($receiptId) {
        $sql = "DELETE FROM stock_receipt_items WHERE receipt_id = ?";
        $stmt = $this->conn->prepare($sql);
        return $stmt->execute([$receiptId]);
    }

    public function insertItem($receiptId, $variantId, $quantity, $unitPrice, $locationCode) {
        $sql = "INSERT INTO stock_receipt_items (receipt_id, variant_id, quantity, unit_price, location_code) 
                VALUES (?, ?, ?, ?, ?)";
        $stmt = $this->conn->prepare($sql);
        return $stmt->execute([$receiptId, $variantId, $quantity, $unitPrice, $locationCode]);
    }

    public function updateStatus($receiptId, $status, $userId) {
        $sql = "UPDATE stock_receipts SET 
                status = ?, 
                confirmed_at = NOW(),
                confirmed_by = ?
                WHERE receipt_id = ?";
        $stmt = $this->conn->prepare($sql);
        return $stmt->execute([$status, $userId, $receiptId]);
    }

    public function getLocationId($locationCode, $warehouseId) {
        $stmt = $this->conn->prepare("SELECT location_id FROM locations WHERE shelf_code = ? AND warehouse_id = ? LIMIT 1");
        $stmt->execute([$locationCode, $warehouseId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['location_id'] : null;
    }

    public function updateItemLocation($receiptItemId, $locationId) {
        $stmt = $this->conn->prepare("UPDATE stock_receipt_items SET location_id = ? WHERE receipt_item_id = ?");
        return $stmt->execute([$locationId, $receiptItemId]);
    }

    public function updateInventoryStock($variantId, $warehouseId, $quantity, $locationId) {
        $sql = "INSERT INTO inventory (variant_id, warehouse_id, quantity, location_id, updated_at) 
                VALUES (?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE 
                    quantity = quantity + VALUES(quantity),
                    location_id = VALUES(location_id),
                    updated_at = NOW()";
        $stmt = $this->conn->prepare($sql);
        return $stmt->execute([$variantId, $warehouseId, $quantity, $locationId]);
    }

    public function updateStockLevels($variantId, $quantity) {
        $sql = "SHOW TABLES LIKE 'stock_levels'";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute();
        if ($stmt->fetch()) {
            $sql = "INSERT INTO stock_levels (variant_id, quantity, updated_at) 
                    VALUES (?, ?, NOW())
                    ON DUPLICATE KEY UPDATE 
                    quantity = quantity + VALUES(quantity),
                    updated_at = NOW()";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$variantId, $quantity]);
        }
    }

    public function logAuditAction($userId, $action, $description, $referenceId, $warehouseId) {
        $sql = "SHOW TABLES LIKE 'audit_logs'";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute();
        if ($stmt->fetch()) {
            $sql = "INSERT INTO audit_logs (user_id, action, table_name, record_id, details, warehouse_id, created_at) 
                    VALUES (?, ?, 'stock_receipts', ?, ?, ?, NOW())";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$userId, $action, $referenceId, $description, $warehouseId]);
        }
    }

    public function deleteReceipt($receiptId) {
        $sql = "DELETE FROM stock_receipts WHERE receipt_id = ?";
        $stmt = $this->conn->prepare($sql);
        return $stmt->execute([$receiptId]);
    }

    public function checkExistingQR($variantId) {
        $stmt = $this->conn->prepare("SELECT qr_id FROM product_qr_codes WHERE variant_id = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$variantId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function updateQRLocation($warehouseId, $locationCode, $supplierId, $qrId) {
        $stmt = $this->conn->prepare("UPDATE product_qr_codes SET warehouse_id = ?, location_code = ?, supplier_id = ? WHERE qr_id = ?");
        return $stmt->execute([$warehouseId, $locationCode, $supplierId, $qrId]);
    }
}
?>
