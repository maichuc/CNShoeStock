<?php
// app/Http/Controllers/Api/OrderController.php
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../Models/Order.php';

class OrderController {
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

    public function getOrders() {
        $this->checkAuth();
        $warehouseId = $_SESSION['warehouse_id'] ?? null;
        try {
            $db = $this->initDb();
            $model = new Order($db);
            $orders = $model->getAll($warehouseId);
            echo json_encode(['success' => true, 'data' => $orders]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function getOrderDetail() {
        $this->checkAuth();
        $orderId = $_GET['order_id'] ?? $_POST['order_id'] ?? 0;
        try {
            $db = $this->initDb();
            $model = new Order($db);
            $order = $model->getById($orderId);
            if (!$order) throw new Exception('Đơn hàng không tồn tại');
            
            $items = $model->getItems($orderId);
            echo json_encode(['success' => true, 'order' => $order, 'items' => $items]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function updateStatus() {
        $this->checkAuth();
        $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $orderId = $data['order_id'] ?? 0;
        $status = $data['status'] ?? '';
        try {
            $db = $this->initDb();
            $model = new Order($db);
            $model->updateStatus($orderId, $status, $_SESSION['user_id']);
            echo json_encode(['success' => true, 'message' => 'Cập nhật trạng thái thành công']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function createOrder() {
        $this->checkAuth();
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) {
            echo json_encode(['success' => false, 'message' => 'Dữ liệu không hợp lệ']);
            return;
        }

        try {
            $db = $this->initDb();
            $model = new Order($db);
            $orderId = $model->create($data, $_SESSION['user_id']);
            
            echo json_encode([
                'success' => true, 
                'message' => 'Tạo đơn hàng thành công',
                'order_id' => $orderId,
                'redirect' => 'quan_ly_don_hang.php?status=pending'
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
?>
