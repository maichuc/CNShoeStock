<?php
// app/Http/Controllers/Api/ProductController.php
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../Models/Product.php';

class ProductController {
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

    public function getProductData() {
        $this->checkAuth();
        $action = $_POST['action'] ?? $_GET['action'] ?? '';
        $warehouseId = $_POST['warehouse_id'] ?? $_GET['warehouse_id'] ?? $_SESSION['warehouse_id'];

        try {
            $db = $this->initDb();
            $model = new Product($db);

            switch ($action) {
                case 'get_sku':
                    $data = [
                        'type' => $_POST['type'] ?? '',
                        'brand' => $_POST['brand'] ?? '',
                        'color' => $_POST['color'] ?? '',
                        'name' => $_POST['name'] ?? '',
                        'size' => $_POST['size'] ?? ''
                    ];
                    $existing = $model->getExistingVariant($data, $warehouseId);
                    if ($existing) {
                        $lastPrice = $model->getLastReceiptPrice($existing['variant_id'], $warehouseId);
                        echo json_encode([
                            'success' => true,
                            'sku' => $existing['sku'],
                            'variant_id' => $existing['variant_id'],
                            'product_id' => $existing['product_id'],
                            'existing_unit_price' => $lastPrice,
                            'existing_sale_price' => $existing['sale_price'],
                            'existing_location' => $existing['location_name'],
                            'is_existing' => true
                        ]);
                    } else {
                        // Logic tạo SKU mới...
                        $sku = strtoupper(substr($data['type'], 0, 3)) . "-" . strtoupper(substr($data['brand'], 0, 3)) . "-" . strtoupper(substr($data['color'], 0, 1)) . $data['size'] . "-" . substr(time(), -4);
                        echo json_encode(['success' => true, 'sku' => $sku, 'is_existing' => false]);
                    }
                    break;

                case 'get_storage_locations':
                    $locations = $model->getStorageLocations($warehouseId, $_POST['type'] ?? null, $_POST['brand'] ?? null);
                    echo json_encode(['success' => true, 'locations' => $locations]);
                    break;

                case 'get_all_sizes':
                    $data = [
                        'type' => $_POST['type'] ?? '',
                        'brand' => $_POST['brand'] ?? '',
                        'color' => $_POST['color'] ?? '',
                        'name' => $_POST['name'] ?? ''
                    ];
                    $sizes = $model->getAllSizes($data, $warehouseId);
                    foreach ($sizes as &$size) {
                        $size['unit_price'] = $model->getLastReceiptPrice($size['variant_id'], $warehouseId);
                    }
                    echo json_encode(['success' => true, 'sizes' => $sizes]);
                    break;
                
                case 'get_product_details':
                    $productId = $_POST['product_id'] ?? '';
                    if (!$productId) {
                        echo json_encode(['success' => false, 'message' => 'Product ID is required']);
                        break;
                    }
                    $product = $model->getDetails($productId, $warehouseId);
                    if (!$product) {
                        error_log("Product not found: ID $productId, Warehouse $warehouseId");
                        echo json_encode(['success' => false, 'message' => 'Product not found']);
                        break;
                    }
                    $variants = $model->getVariants($productId, $warehouseId);
                    error_log("Found " . count($variants) . " variants for product ID $productId");
                    
                    // Calculate base_sku and unique colors
                    $baseSku = '';
                    $uniqueColors = [];
                    
                    if (!empty($variants)) {
                        // Get unique colors
                        $uniqueColors = array_values(array_unique(array_column($variants, 'color')));
                        
                        // Extract base SKU from the first variant's SKU
                        $firstSku = $variants[0]['sku'];
                        if (preg_match('/^(.+)-\d+$/', $firstSku, $matches)) {
                            $baseSku = $matches[1];
                        } else {
                            $parts = explode('-', $firstSku);
                            if (count($parts) > 1) {
                                array_pop($parts);
                                $baseSku = implode('-', $parts);
                            } else {
                                $baseSku = $firstSku;
                            }
                        }
                    }
                    
                    $product['base_sku'] = $baseSku;
                    $product['colors'] = $uniqueColors;
                    $product['colors_string'] = implode(',', $uniqueColors);
                    
                    // Add images
                    $images = $model->getImages($productId);
                    $product['images'] = $images;
                    
                    // Set main_image for compatibility
                    $product['main_image'] = null;
                    foreach ($images as $img) {
                        if ($img['is_primary']) {
                            $product['main_image'] = $img['file_path'];
                            break;
                        }
                    }
                    if (!$product['main_image'] && !empty($images)) {
                        $product['main_image'] = $images[0]['file_path'];
                    }

                    // Add sale_price alias for compatibility
                    foreach ($variants as &$v) {
                        $v['sale_price'] = $v['price'];
                    }
                    $product['variants'] = $variants;
                    echo json_encode(['success' => true, 'product' => $product]);
                    break;

                default:
                    echo json_encode(['success' => false, 'message' => 'Invalid action']);
            }
        } catch (Exception $e) {
            error_log("ProductController Error [" . $action . "]: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
?>
