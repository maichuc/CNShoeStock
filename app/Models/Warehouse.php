<?php
// app/Models/Warehouse.php
class Warehouse {
    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function getAll() {
        $stmt = $this->conn->prepare("SELECT * FROM warehouses ORDER BY name");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getById($id) {
        $stmt = $this->conn->prepare("SELECT * FROM warehouses WHERE warehouse_id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getLocations($warehouseId) {
        $stmt = $this->conn->prepare("SELECT * FROM locations WHERE warehouse_id = ? ORDER BY shelf_code");
        $stmt->execute([$warehouseId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getSuggestLocations($warehouseId, $type = null, $brand = null) {
        $sql = "SELECT l.shelf_code as location_name, COUNT(*) as count
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
        
        $sql .= " GROUP BY l.shelf_code ORDER BY count DESC LIMIT 10";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
