<?php
class StockReceiptHistory {
    private $conn;
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    /**
     * Ghi lại lịch sử thay đổi
     */
    public function logChange($receiptId, $userId, $actionType, $fieldChanged = null, $oldValue = null, $newValue = null, $reason = null, $warehouseId = null) {
        try {
            // Get warehouse_id from stock_receipts if not provided
            if ($warehouseId === null) {
                $sqlWarehouse = "SELECT warehouse_id FROM stock_receipts WHERE receipt_id = ?";
                $stmtWarehouse = $this->conn->prepare($sqlWarehouse);
                $stmtWarehouse->execute([$receiptId]);
                $receipt = $stmtWarehouse->fetch(PDO::FETCH_ASSOC);
                $warehouseId = $receipt ? $receipt['warehouse_id'] : ($_SESSION['warehouse_id'] ?? 1);
            }
            
            $sql = "INSERT INTO stock_receipt_history 
                    (receipt_id, user_id, action_type, change_reason, warehouse_id) 
                    VALUES (?, ?, ?, ?, ?)";
            
            $stmt = $this->conn->prepare($sql);
            return $stmt->execute([
                $receiptId,
                $userId,
                $actionType,
                $reason,
                $warehouseId
            ]);
        } catch (Exception $e) {
            error_log("Error logging receipt history: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Lấy lịch sử thay đổi của một phiếu nhập
     */
    public function getReceiptHistory($receiptId) {
        try {
            $sql = "SELECT h.*, u.username, u.full_name 
                    FROM stock_receipt_history h
                    LEFT JOIN users u ON h.user_id = u.user_id
                    WHERE h.receipt_id = ?
                    ORDER BY h.created_at DESC";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$receiptId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error getting receipt history: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Lấy lịch sử thay đổi của một user
     */
    public function getUserHistory($userId, $limit = 50) {
        try {
            $sql = "SELECT h.*, sr.receipt_code, u.username 
                    FROM stock_receipt_history h
                    LEFT JOIN stock_receipts sr ON h.receipt_id = sr.receipt_id
                    LEFT JOIN users u ON h.user_id = u.user_id
                    WHERE h.user_id = ?
                    ORDER BY h.created_at DESC
                    LIMIT ?";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$userId, $limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error getting user history: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * So sánh và ghi lại thay đổi giữa 2 mảng dữ liệu
     */
    public function compareAndLog($receiptId, $userId, $oldData, $newData, $reason = null) {
        $changes = [];
        
        // Các trường cần theo dõi
        $fieldsToTrack = [
            'supplier_id' => 'Nhà cung cấp',
            'warehouse_id' => 'Kho',
            'status' => 'Trạng thái',
            'notes' => 'Ghi chú',
            'total_amount' => 'Tổng tiền'
        ];
        
        foreach ($fieldsToTrack as $field => $label) {
            if (isset($oldData[$field]) && isset($newData[$field])) {
                $oldVal = $oldData[$field];
                $newVal = $newData[$field];
                
                if ($oldVal != $newVal) {
                    $this->logChange(
                        $receiptId,
                        $userId,
                        'update',
                        $label,
                        $oldVal,
                        $newVal,
                        $reason
                    );
                    $changes[] = $label;
                }
            }
        }
        
        return $changes;
    }
    
    /**
     * Format lịch sử để hiển thị
     */
    public function formatHistoryForDisplay($history) {
        $formatted = [];
        
        foreach ($history as $record) {
            $item = [
                'id' => $record['history_id'],
                'user' => $record['full_name'] ?: $record['username'],
                'action' => $this->getActionLabel($record['action_type']),
                'details' => $this->getChangeDetails($record),
                'time' => date('d/m/Y H:i:s', strtotime($record['created_at'])),
                'reason' => $record['change_reason']
            ];
            
            $formatted[] = $item;
        }
        
        return $formatted;
    }
    
    private function getActionLabel($actionType) {
        $labels = [
            'create' => 'Tạo phiếu',
            'update' => 'Cập nhật',
            'confirm' => 'Xác nhận',
            'delete' => 'Xóa'
        ];
        
        return $labels[$actionType] ?? $actionType;
    }
    
    private function getChangeDetails($record) {
        if ($record['action_type'] === 'create') {
            return 'Tạo phiếu nhập mới';
        }
        
        if ($record['action_type'] === 'confirm') {
            return 'Xác nhận phiếu nhập';
        }
        
        if ($record['field_changed']) {
            $oldVal = $record['old_value'] ?: '(trống)';
            $newVal = $record['new_value'] ?: '(trống)';
            return "{$record['field_changed']}: {$oldVal} → {$newVal}";
        }
        
        return $record['action_type'];
    }
}
?>
