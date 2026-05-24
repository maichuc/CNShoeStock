<?php
// app/Models/Supplier.php
class Supplier {
    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function getAll($warehouseId) {
        $sql = "SELECT * FROM suppliers WHERE warehouse_id = ? ORDER BY name";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$warehouseId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getById($id) {
        $sql = "SELECT * FROM suppliers WHERE supplier_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getByTaxCode($taxCode, $excludeId = null) {
        $sql = "SELECT supplier_id, name FROM suppliers WHERE tax_code = ?";
        $params = [$taxCode];
        if ($excludeId) {
            $sql .= " AND supplier_id != ?";
            $params[] = $excludeId;
        }
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function create($data) {
        $sql = "INSERT INTO suppliers (
                supplier_code, name, short_name, tax_code, type,
                address, province, district, phone, email, website,
                contact_person, contact_position, notes, status,
                warehouse_id, created_by, updated_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $this->conn->prepare($sql);
        return $stmt->execute([
            $data['supplier_code'], $data['name'], $data['short_name'], $data['tax_code'], $data['type'],
            $data['address'], $data['province'], $data['district'], $data['phone'], $data['email'], $data['website'],
            $data['contact_person'], $data['contact_position'], $data['notes'], $data['status'],
            $data['warehouse_id'], $data['user_id'], $data['user_id']
        ]);
    }

    public function update($id, $data) {
        $sql = "UPDATE suppliers SET 
                name = ?, short_name = ?, tax_code = ?, type = ?, 
                address = ?, province = ?, district = ?, phone = ?, 
                email = ?, website = ?, contact_person = ?, contact_position = ?, 
                notes = ?, status = ?, updated_at = CURRENT_TIMESTAMP, updated_by = ?
                WHERE supplier_id = ?";
        
        $stmt = $this->conn->prepare($sql);
        return $stmt->execute([
            $data['name'], $data['short_name'], $data['tax_code'], $data['type'],
            $data['address'], $data['province'], $data['district'], $data['phone'],
            $data['email'], $data['website'], $data['contact_person'], $data['contact_position'],
            $data['notes'], $data['status'], $data['user_id'], $id
        ]);
    }

    public function delete($id) {
        $sql = "DELETE FROM suppliers WHERE supplier_id = ?";
        $stmt = $this->conn->prepare($sql);
        return $stmt->execute([$id]);
    }

    public function checkDependencies($id) {
        $checkTables = [
            'purchase_orders' => 'supplier_id',
            'stock_receipts' => 'supplier_id',
            'products' => 'supplier_id'
        ];
        
        $dependencies = [];
        foreach ($checkTables as $table => $column) {
            try {
                $stmt = $this->conn->prepare("SELECT COUNT(*) FROM $table WHERE $column = ?");
                $stmt->execute([$id]);
                $count = $stmt->fetchColumn();
                if ($count > 0) {
                    $dependencies[] = "$table ($count bản ghi)";
                }
            } catch (Exception $e) { continue; }
        }
        return $dependencies;
    }
}
?>
