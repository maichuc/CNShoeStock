<?php
/**
 * Environment Configuration Loader
 * Loads and manages environment variables from .env file
 */

class EnvLoader {
    private static $loaded = false;
    private static $env = [];

    /**
     * Load environment variables from .env file
     */
    public static function load($path = null) {
        if (self::$loaded) {
            return;
        }

        if ($path === null) {
            $path = __DIR__ . '/.env';
        }

        if (!file_exists($path)) {
            throw new Exception(".env file not found at: {$path}");
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($lines as $line) {
            // Bỏ qua comments
            if (strpos(trim($line), '#') === 0) {
                continue;
            }

            // Phân tích cú pháp key=value
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                
                // Xóa quotes if present
                $value = trim($value, '"\'');
                
                // Store in static array
                self::$env[$key] = $value;
                
                // Also set as PHP environment variable
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }

        self::$loaded = true;
    }

    /**
     * Get environment variable value
     * 
     * @param string $key Variable name
     * @param mixed $default Default value if not found
     * @return mixed
     */
    public static function get($key, $default = null) {
        if (!self::$loaded) {
            self::load();
        }

        if (isset(self::$env[$key])) {
            return self::$env[$key];
        }

        // Try from $_ENV
        if (isset($_ENV[$key])) {
            return $_ENV[$key];
        }

        // Try from getenv()
        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }

        return $default;
    }

    /**
     * Check if environment variable exists
     */
    public static function has($key) {
        if (!self::$loaded) {
            self::load();
        }
        return isset(self::$env[$key]) || isset($_ENV[$key]) || getenv($key) !== false;
    }

    /**
     * Get all environment variables
     */
    public static function all() {
        if (!self::$loaded) {
            self::load();
        }
        return self::$env;
    }
}

/**
 * Helper function to get environment variable
 * 
 * @param string $key Variable name
 * @param mixed $default Default value
 * @return mixed
 */
function env($key, $default = null) {
    return EnvLoader::get($key, $default);
}

// Auto-load on include
try {
    EnvLoader::load();
} catch (Exception $e) {
    error_log("Warning: Could not load .env file - " . $e->getMessage());
}
