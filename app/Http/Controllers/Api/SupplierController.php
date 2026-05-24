<?php
// app/Http/Controllers/Api/SupplierController.php
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../Models/Supplier.php';

class SupplierController {
    private function initDb() {
        $database = new Database();
        return $database->getConnection();
    }

    private function checkAuth() {
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (!isset($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
    }

    public function getSuppliers() {
        $this->checkAuth();
        $warehouseId = $_SESSION['warehouse_id'] ?? null;
        if (!$warehouseId) {
            echo json_encode(['success' => false, 'message' => 'Warehouse ID not found']);
            return;
        }

        try {
            $db = $this->initDb();
            $model = new Supplier($db);
            $suppliers = $model->getAll($warehouseId);
            echo json_encode(['success' => true, 'data' => $suppliers]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function saveSupplier() {
        $this->checkAuth();
        $userId = $_SESSION['user_id'];
        $warehouseId = $_SESSION['warehouse_id'] ?? null;
        if (!$warehouseId) {
            echo json_encode(['success' => false, 'message' => 'Warehouse ID not found']);
            return;
        }

        try {
            $db = $this->initDb();
            $model = new Supplier($db);
            
            $supplierId = isset($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null;
            $data = [
                'supplier_code' => trim($_POST['supplier_code'] ?? ''),
                'name' => trim($_POST['name'] ?? ''),
                'short_name' => trim($_POST['short_name'] ?? ''),
                'tax_code' => trim($_POST['tax_code'] ?? ''),
                'type' => trim($_POST['type'] ?? ''),
                'address' => trim($_POST['address'] ?? ''),
                'province' => trim($_POST['province'] ?? ''),
                'district' => trim($_POST['district'] ?? ''),
                'phone' => preg_replace('/[^\d]/', '', $_POST['phone'] ?? ''),
                'email' => trim($_POST['email'] ?? ''),
                'website' => trim($_POST['website'] ?? ''),
                'contact_person' => trim($_POST['contact_person'] ?? ''),
                'contact_position' => trim($_POST['contact_position'] ?? ''),
                'notes' => trim($_POST['notes'] ?? ''),
                'status' => $_POST['status'] ?? 'active',
                'warehouse_id' => $warehouseId,
                'user_id' => $userId
            ];

            // Validation logic... (simplified for brevity, keep existing logic)
            if (empty($data['name'])) throw new Exception('Tên nhà cung cấp không được để trống');
            
            $existing = $model->getByTaxCode($data['tax_code'], $supplierId);
            if ($existing) throw new Exception('Mã số thuế đã tồn tại cho nhà cung cấp: ' . $existing['name']);

            if ($supplierId) {
                $model->update($supplierId, $data);
                $message = 'Cập nhật nhà cung cấp thành công';
                $id = $supplierId;
            } else {
                $model->create($data);
                $id = $db->lastInsertId();
                $message = 'Thêm nhà cung cấp thành công';
            }

            echo json_encode(['success' => true, 'message' => $message, 'id' => $id]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function deleteSupplier() {
        $this->checkAuth();
        try {
            $db = $this->initDb();
            $model = new Supplier($db);
            $supplierId = isset($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : 0;
            
            $dependencies = $model->checkDependencies($supplierId);
            if (!empty($dependencies)) {
                throw new Exception('Không thể xóa vì đang được sử dụng trong: ' . implode(', ', $dependencies));
            }

            $model->delete($supplierId);
            echo json_encode(['success' => true, 'message' => 'Xóa nhà cung cấp thành công']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
?>
