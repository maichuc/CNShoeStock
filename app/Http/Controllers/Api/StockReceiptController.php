<?php
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../Models/StockReceipt.php';
require_once __DIR__ . '/../../../Classes/LichSuPhieuNhap.php';
require_once __DIR__ . '/../../../Classes/QuanLyMaQR.php';
require_once __DIR__ . '/../../../Classes/TaoMaQR.php';

class StockReceiptController {

    private function initDb() {
        $database = new Database();
        return $database->getConnection();
    }

    private function checkAuth() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (!isset($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Lỗi: Bạn chưa đăng nhập.']);
            exit;
        }
    }

    private function getInput() {
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $input = $_POST;
        }
        return $input;
    }

    public function getDraftReceipts() {
        header('Access-Control-Allow-Origin: *');
        header('Content-Type: application/json; charset=UTF-8');
        $this->checkAuth();

        try {
            $db = $this->initDb();
            $model = new StockReceipt($db);
            echo json_encode(['success' => true, 'receipts' => $model->getDraftReceipts()]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function getReceiptDetails() {
        header('Access-Control-Allow-Origin: *');
        header('Content-Type: application/json; charset=UTF-8');
        $this->checkAuth();

        try {
            $db = $this->initDb();
            $model = new StockReceipt($db);
            $input = $this->getInput();
            $receiptId = $input['receipt_id'] ?? ($_GET['receipt_id'] ?? 0);
            
            if (!$receiptId) throw new Exception('Thiếu receipt_id');
            
            $receipt = $model->getReceiptById($receiptId);
            if (!$receipt) throw new Exception('Không tìm thấy phiếu nhập');
            
            $items = $model->getReceiptItems($receiptId);
            
            echo json_encode([
                'success' => true,
                'receipt' => $receipt,
                'items' => $items,
                'can_edit' => $receipt['status'] === 'draft'
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function updateDraft() {
        header('Access-Control-Allow-Origin: *');
        header('Content-Type: application/json; charset=UTF-8');
        $this->checkAuth();

        try {
            $db = $this->initDb();
            $model = new StockReceipt($db);
            $historyManager = new StockReceiptHistory($db);
            $input = $this->getInput();
            
            $receiptId = $input['receipt_id'] ?? 0;
            $userId = $_SESSION['user_id'];
            $warehouseId = $_SESSION['warehouse_id'] ?? null;
            
            if (!$receiptId) throw new Exception('Thiếu receipt_id');
            if (!$warehouseId) throw new Exception('Không xác định được kho hàng');
            
            $receipt = $model->getReceiptById($receiptId);
            if (!$receipt) throw new Exception('Không tìm thấy phiếu nhập');
            if ($receipt['warehouse_id'] != $warehouseId) throw new Exception('Phiếu nhập không thuộc kho của bạn');
            if ($receipt['status'] != 'draft') throw new Exception('Chỉ có thể chỉnh sửa phiếu ở trạng thái bản nháp.');

            $db->beginTransaction();
            
            $oldData = json_encode($receipt);
            $supplierId = $input['supplier_id'] ?? $receipt['supplier_id'];
            $notes = $input['notes'] ?? $receipt['notes'];
            
            $model->updateDraft($receiptId, $supplierId, $notes, $userId);
            
            $items = $input['items'] ?? null;
            if (is_string($items)) $items = json_decode($items, true);
            
            if ($items && is_array($items) && count($items) > 0) {
                $model->deleteItems($receiptId);
                foreach ($items as $item) {
                    $model->insertItem($receiptId, $item['variant_id'], $item['quantity'], $item['unit_price'], $item['location_code'] ?? null);
                }
            }
            
            $newData = json_encode($input);
            $historyManager->logChange($receiptId, $userId, 'update', 'receipt_details', $oldData, $newData, 'Chỉnh sửa phiếu nhập trước khi xác nhận');
            
            $db->commit();
            echo json_encode(['success' => true, 'message' => 'Đã cập nhật phiếu nhập thành công']);
            
        } catch (Exception $e) {
            if (isset($db) && $db->inTransaction()) $db->rollback();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function confirmReceipt() {
        header('Access-Control-Allow-Origin: *');
        header('Content-Type: application/json; charset=UTF-8');
        $this->checkAuth();

        $userRole = $_SESSION['role'] ?? 'employee';
        if ($userRole !== 'admin' && $userRole !== 'manager') {
            echo json_encode(['success' => false, 'message' => 'Không có quyền xác nhận phiếu']);
            return;
        }

        try {
            $db = $this->initDb();
            $model = new StockReceipt($db);
            $historyManager = new StockReceiptHistory($db);
            $qrManager = new QRCodeManager($db);
            $input = $this->getInput();
            
            $receiptId = $input['receipt_id'] ?? 0;
            $userId = $_SESSION['user_id'];
            $warehouseId = $_SESSION['warehouse_id'] ?? null;
            
            if (!$receiptId) throw new Exception('Thiếu receipt_id');
            if (!$warehouseId) throw new Exception('Không xác định được kho hàng');
            
            $receipt = $model->getReceiptById($receiptId);
            if (!$receipt) throw new Exception('Không tìm thấy phiếu nhập');
            if ($receipt['warehouse_id'] != $warehouseId) throw new Exception('Phiếu nhập không thuộc kho của bạn');
            if ($receipt['status'] === 'confirmed') throw new Exception('Phiếu đã được xác nhận trước đó');

            $db->beginTransaction();
            
            $model->updateStatus($receiptId, 'confirmed', $userId);
            
            $items = $model->getReceiptItems($receiptId);
            foreach ($items as $item) {
                if (!empty($item['location_code'])) {
                    $locationId = $model->getLocationId($item['location_code'], $warehouseId);
                    if ($locationId) {
                        $model->updateItemLocation($item['receipt_item_id'], $locationId);
                        $item['location_id'] = $locationId;
                    }
                }
                
                $model->updateInventoryStock($item['variant_id'], $warehouseId, $item['quantity'], $item['location_id'] ?? null);
                $model->updateStockLevels($item['variant_id'], $item['quantity']);
                
                try {
                    if (!empty($item['product_id']) && !empty($item['variant_id'])) {
                        $existingQR = $model->checkExistingQR($item['variant_id']);
                        if (!$existingQR) {
                            $qrResult = $qrManager->generateQRCode($item['product_id'], $item['variant_id'], $userId);
                            if ($qrResult && isset($qrResult['qr_id'])) {
                                $model->updateQRLocation($warehouseId, $item['location_code'] ?? null, $receipt['supplier_id'] ?? null, $qrResult['qr_id']);
                            }
                        }
                    }
                } catch (Exception $qrError) {
                    error_log("QR error: " . $qrError->getMessage());
                }
            }
            
            $historyManager->logChange($receiptId, $userId, 'confirm', null, null, null, 'Xác nhận phiếu nhập kho');
            $receiptCode = 'RC-' . str_pad($receiptId, 6, '0', STR_PAD_LEFT);
            $model->logAuditAction($userId, 'RECEIPT_CONFIRMED', "Xác nhận phiếu nhập #{$receiptCode}", $receiptId, $warehouseId);
            
            $db->commit();
            echo json_encode(['success' => true, 'message' => 'Đã xác nhận phiếu nhập thành công', 'receipt_code' => $receiptCode]);
            
        } catch (Exception $e) {
            if (isset($db) && $db->inTransaction()) $db->rollback();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function delete() {
        header('Access-Control-Allow-Origin: *');
        header('Content-Type: application/json; charset=UTF-8');
        $this->checkAuth();

        try {
            $db = $this->initDb();
            $model = new StockReceipt($db);
            $historyManager = new StockReceiptHistory($db);
            $input = $this->getInput();
            
            $receiptId = $input['receipt_id'] ?? 0;
            $userId = $_SESSION['user_id'];
            $warehouseId = $_SESSION['warehouse_id'] ?? null;
            
            if (!$receiptId) throw new Exception('Thiếu receipt_id');
            if (!$warehouseId) throw new Exception('Không xác định được kho hàng');
            
            $receipt = $model->getReceiptById($receiptId);
            if (!$receipt) throw new Exception('Không tìm thấy phiếu nhập');
            if ($receipt['warehouse_id'] != $warehouseId) throw new Exception('Phiếu nhập không thuộc kho của bạn');
            if ($receipt['status'] !== 'draft') throw new Exception('Chỉ có thể xóa phiếu nhập ở trạng thái bản nháp');

            $db->beginTransaction();
            
            $model->deleteItems($receiptId);
            $historyManager->logChange($receiptId, $userId, 'delete', null, json_encode($receipt), null, 'Xóa phiếu nhập bản nháp');
            $model->deleteReceipt($receiptId);
            
            $receiptCode = 'RC-' . str_pad($receiptId, 6, '0', STR_PAD_LEFT);
            $model->logAuditAction($userId, 'RECEIPT_DELETED', "Xóa phiếu nhập #{$receiptCode}", $receiptId, $warehouseId);
            
            $db->commit();
            echo json_encode(['success' => true, 'message' => 'Đã xóa phiếu nhập thành công']);
            
        } catch (Exception $e) {
            if (isset($db) && $db->inTransaction()) $db->rollback();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function getReceiptHistory() {
        header('Access-Control-Allow-Origin: *');
        header('Content-Type: application/json; charset=UTF-8');
        $this->checkAuth();

        try {
            $db = $this->initDb();
            $historyManager = new StockReceiptHistory($db);
            $input = $this->getInput();
            
            $receiptId = $input['receipt_id'] ?? 0;
            if (!$receiptId) throw new Exception('Thiếu receipt_id');
            
            $history = $historyManager->getFormattedHistory($receiptId);
            echo json_encode(['success' => true, 'history' => $history]);
            
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
?>
