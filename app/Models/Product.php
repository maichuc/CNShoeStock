<?php
// app/Models/Product.php
class Product {
    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function getExistingVariant($data, $warehouseId) {
        $sql = "SELECT pv.sku, pv.variant_id, pv.price as sale_price, pv.product_id, i.location_id, l.shelf_code as location_name
                FROM product_variants pv 
                JOIN products p ON pv.product_id = p.product_id
                LEFT JOIN inventory i ON pv.variant_id = i.variant_id AND i.warehouse_id = ?
                LEFT JOIN locations l ON i.location_id = l.location_id
                WHERE p.warehouse_id = ? 
                AND p.type = ? AND p.brand = ? AND p.name = ? 
                AND pv.color = ? AND pv.size = ?
                LIMIT 1";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$warehouseId, $warehouseId, $data['type'], $data['brand'], $data['name'], $data['color'], $data['size']]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getLastReceiptPrice($variantId, $warehouseId) {
        $sql = "SELECT sri.unit_price 
                FROM stock_receipt_items sri
                WHERE sri.variant_id = ? AND sri.receipt_id IN (SELECT receipt_id FROM stock_receipts WHERE warehouse_id = ?)
                ORDER BY sri.receipt_item_id DESC
                LIMIT 1";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$variantId, $warehouseId]);
        return $stmt->fetchColumn();
    }

    public function getStorageLocations($warehouseId, $type = null, $brand = null) {
        $sql = "SELECT DISTINCT l.shelf_code as location_name
                FROM inventory i
                JOIN product_variants pv ON i.variant_id = pv.variant_id
                JOIN products p ON pv.product_id = p.product_id
                LEFT JOIN locations l ON i.location_id = l.location_id
                WHERE i.warehouse_id = ? 
                AND l.shelf_code IS NOT NULL 
                AND l.shelf_code != ''";
        
        $params = [$warehouseId];
        if ($type) { $sql .= " AND p.type = ?"; $params[] = $type; }
        if ($brand) { $sql .= " AND p.brand = ?"; $params[] = $brand; }
        $sql .= " ORDER BY l.shelf_code";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function getAllSizes($data, $warehouseId) {
        $sql = "SELECT DISTINCT pv.size, pv.sku, pv.variant_id, pv.price as sale_price, l.shelf_code as location_name
                FROM product_variants pv 
                JOIN products p ON pv.product_id = p.product_id
                LEFT JOIN inventory i ON pv.variant_id = i.variant_id AND i.warehouse_id = ?
                LEFT JOIN locations l ON i.location_id = l.location_id
                WHERE p.warehouse_id = ? 
                AND p.type = ? AND p.brand = ? AND p.name = ? AND pv.color = ?
                ORDER BY CAST(pv.size AS UNSIGNED), pv.size";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$warehouseId, $warehouseId, $data['type'], $data['brand'], $data['name'], $data['color']]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getDetails($productId, $warehouseId) {
        $sql = "SELECT * FROM products WHERE product_id = ? AND warehouse_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$productId, $warehouseId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getVariants($productId, $warehouseId) {
        $sql = "SELECT pv.*, 
                (SELECT sri.unit_price FROM stock_receipt_items sri 
                 WHERE sri.variant_id = pv.variant_id AND sri.warehouse_id = ? 
                 ORDER BY sri.receipt_item_id DESC LIMIT 1) as unit_price
                FROM product_variants pv 
                JOIN products p ON pv.product_id = p.product_id
                WHERE pv.product_id = ? AND p.warehouse_id = ?
                ORDER BY CAST(pv.size AS UNSIGNED), pv.size";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$warehouseId, $productId, $warehouseId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getImages($productId) {
        $sql = "SELECT image_id, file_path, is_primary, created_at FROM product_images WHERE product_id = ? ORDER BY is_primary DESC, created_at ASC";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$productId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
