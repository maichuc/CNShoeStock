<?php
class UsernameGenerator {
    
    /**
     * Tạo username theo quy tắc:
     * Chữ cái đầu họ tên + Mã kho + 3 số cuối employee_id
     */
    public static function generate($fullName, $warehouseName, $employeeId) {
        // Tạo chữ cái đầu từ họ tên
        $nameInitials = self::generateNameInitials($fullName);
        
        // Tạo mã kho từ tên kho
        $warehouseCode = self::generateWarehouseCode($warehouseName);
        
        // Lấy 3 số cuối của employee_id
        $employeeCode = str_pad($employeeId % 1000, 3, '0', STR_PAD_LEFT);
        
        return $nameInitials . $warehouseCode . $employeeCode;
    }
    
    /**
     * Tạo chữ cái đầu từ họ tên
     * Ví dụ: "Nguyễn Văn An" → "nvan"
     */
    private static function generateNameInitials($fullName) {
        // Loại bỏ dấu và chuyển thành chữ thường
        $normalized = self::removeVietnameseTones($fullName);
        $normalized = strtolower(trim($normalized));
        
        // Tách thành các từ
        $words = preg_split('/\s+/', $normalized);
        $words = array_filter($words, function($word) {
            return strlen($word) > 0;
        });
        
        if (empty($words)) return '';
        
        $initials = '';
        
        // Lấy chữ cái đầu của họ, tên đệm
        for ($i = 0; $i < count($words) - 1; $i++) {
            $initials .= substr($words[$i], 0, 1);
        }
        
        // Thêm tên đầy đủ (từ cuối cùng)
        if (count($words) > 0) {
            $initials .= $words[count($words) - 1];
        }
        
        return $initials;
    }
    
    /**
     * Tạo mã kho từ tên kho
     * Ví dụ: "Kho Giày ABC" → "khogiayabc"
     */
    private static function generateWarehouseCode($warehouseName) {
        // Loại bỏ dấu, khoảng trắng và ký tự đặc biệt
        $normalized = self::removeVietnameseTones($warehouseName);
        $normalized = strtolower($normalized);
        $normalized = preg_replace('/[^a-z0-9]/', '', $normalized);
        
        return $normalized;
    }
    
    /**
     * Loại bỏ dấu tiếng Việt
     */
    private static function removeVietnameseTones($str) {
        $accents = [
            'à|á|ạ|ả|ã|â|ầ|ấ|ậ|ẩ|ẫ|ă|ằ|ắ|ặ|ẳ|ẵ' => 'a',
            'è|é|ẹ|ẻ|ẽ|ê|ề|ế|ệ|ể|ễ' => 'e',
            'ì|í|ị|ỉ|ĩ' => 'i',
            'ò|ó|ọ|ỏ|õ|ô|ồ|ố|ộ|ổ|ỗ|ơ|ờ|ớ|ợ|ở|ỡ' => 'o',
            'ù|ú|ụ|ủ|ũ|ư|ừ|ứ|ự|ử|ữ' => 'u',
            'ỳ|ý|ỵ|ỷ|ỹ' => 'y',
            'đ' => 'd',
            'À|Á|Ạ|Ả|Ã|Â|Ầ|Ấ|Ậ|Ẩ|Ẫ|Ă|Ằ|Ắ|Ặ|Ẳ|Ẵ' => 'A',
            'È|É|Ẹ|Ẻ|Ẽ|Ê|Ề|Ế|Ệ|Ể|Ễ' => 'E',
            'Ì|Í|Ị|Ỉ|Ĩ' => 'I',
            'Ò|Ó|Ọ|Ỏ|Õ|Ô|Ồ|Ố|Ộ|Ổ|Ỗ|Ơ|Ờ|Ớ|Ợ|Ở|Ỡ' => 'O',
            'Ù|Ú|Ụ|Ủ|Ũ|Ư|Ừ|Ứ|Ự|Ử|Ữ' => 'U',
            'Ỳ|Ý|Ỵ|Ỷ|Ỹ' => 'Y',
            'Đ' => 'D'
        ];
        
        foreach ($accents as $pattern => $replacement) {
            $str = preg_replace('/(' . $pattern . ')/', $replacement, $str);
        }
        
        return $str;
    }
    
    /**
     * Kiểm tra username đã tồn tại chưa - sử dụng user_id
     */
    public static function isUsernameExists($username, $db) {
        $query = "SELECT user_id FROM users WHERE username = :username";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':username', $username);
        $stmt->execute();
        
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Tạo username unique (thêm số nếu trùng)
     */
    public static function generateUniqueUsername($fullName, $warehouseName, $employeeId, $db) {
        $baseUsername = self::generate($fullName, $warehouseName, $employeeId);
        $username = $baseUsername;
        $counter = 1;
        
        // Nếu username đã tồn tại, thêm số vào cuối
        while (self::isUsernameExists($username, $db)) {
            $username = $baseUsername . $counter;
            $counter++;
        }
        
        return $username;
    }
}
?>