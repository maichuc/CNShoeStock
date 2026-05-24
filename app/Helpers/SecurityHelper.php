<?php
/**
 * Security Helper - Mã hóa/Giải mã ID sản phẩm
 * Sử dụng OpenSSL AES-256-CBC encryption
 */

class SecurityHelper {

    // Secret key - 32 ký tự (256-bit)
    private static $secret_key = 'your-super-secret-key-32-chars!';
    private static $cipher = 'AES-256-CBC';

    /**
     * Mã hóa ID sản phẩm
     * @param int $id - Product ID
     * @return string - Encrypted ID (URL safe)
     */
    public static function encryptId($id) {
        if (!$id || $id <= 0) {
            return null;
        }

        try {
            // Tạo IV ngẫu nhiên
            $iv_length = openssl_cipher_iv_length(self::$cipher);
            $iv = openssl_random_pseudo_bytes($iv_length);

            // Mã hóa
            $encrypted = openssl_encrypt(
                (string)$id,
                self::$cipher,
                self::$secret_key,
                false,  // không dùng base64 encoding trong openssl_encrypt
                $iv
            );

            // Kết hợp IV + encrypted data và encode
            $data = $iv . $encrypted;
            $encoded = base64_encode($data);

            // URL safe
            return urlencode($encoded);

        } catch (Exception $e) {
            error_log('Encryption error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Giải mã ID sản phẩm
     * @param string $encrypted - Encrypted ID từ URL
     * @return int|null - Product ID hoặc null nếu lỗi
     */
    public static function decryptId($encrypted) {
        if (!$encrypted) {
            return null;
        }

        try {
            // Decode URL safe
            $encrypted = urldecode($encrypted);

            // Decode base64
            $data = base64_decode($encrypted, true);

            if ($data === false) {
                error_log('Invalid base64 encoding');
                return null;
            }

            // Tách IV từ encrypted data
            $iv_length = openssl_cipher_iv_length(self::$cipher);
            $iv = substr($data, 0, $iv_length);
            $encrypted_data = substr($data, $iv_length);

            // Giải mã
            $decrypted = openssl_decrypt(
                $encrypted_data,
                self::$cipher,
                self::$secret_key,
                false,
                $iv
            );

            if ($decrypted === false) {
                error_log('Decryption failed');
                return null;
            }

            // Kiểm tra ID hợp lệ
            $id = (int)$decrypted;
            if ($id <= 0) {
                return null;
            }

            return $id;

        } catch (Exception $e) {
            error_log('Decryption error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Thiết lập secret key (gọi lần đầu)
     * @param string $key - 32 ký tự
     */
    public static function setSecretKey($key) {
        if (strlen($key) >= 32) {
            self::$secret_key = $key;
        } else {
            error_log('Secret key must be at least 32 characters');
        }
    }
}
?>
