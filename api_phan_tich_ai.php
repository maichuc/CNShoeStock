<?php
header('Content-Type: application/json');
require_once 'config/database.php';
session_start();

/**
 * Chuẩn hóa thương hiệu sản phẩm
 * Tất cả các thương hiệu không xác định sẽ được chuyển về "Unknown"
 */
function standardizeBrand($brand) {
    // Danh sách các giá trị brand cần chuyển thành "Unknown"
    $unknownBrands = [
        // Fashion (thường bị AI nhận diện sai)
        'fashion',
        
        // Không xác định (tiếng Việt)
        'không xác định', 'khong xac dinh', 'chưa xác định', 'chua xac dinh',
        'không rõ', 'khong ro', 'chưa rõ', 'chua ro',
        
        // Không thương hiệu
        'không thương hiệu', 'khong thuong hieu', 
        'không có thương hiệu', 'khong co thuong hieu',
        'chưa có thương hiệu', 'chua co thuong hieu',
        
        // Không nhãn hiệu
        'không nhãn hiệu', 'khong nhan hieu', 
        'không có nhãn hiệu', 'khong co nhan hieu',
        'không nhãn', 'khong nhan', 'chưa có nhãn', 'chua co nhan',
        
        // Không brand
        'không brand', 'khong brand', 
        'không có brand', 'khong co brand',
        
        // English variants
        'no brand', 'nobrand', 'no-brand',
        'unbranded', 'non-branded', 'non branded',
        'undefined', 'none', 'null',
        
        // N/A variants
        'n/a', 'na', 'n.a', 'n.a.',
        
        // Ký tự đặc biệt
        '-', '--', '---', '_', '__', '___',
        '?', '??', '???',
    ];
    
    // Nếu brand rỗng hoặc chỉ có khoảng trắng
    if (empty($brand) || !trim($brand)) {
        return 'Unknown';
    }
    
    // Chuẩn hóa để so sánh
    $brandLower = mb_strtolower(trim($brand), 'UTF-8');
    
    // Kiểm tra trong danh sách unknown brands
    if (in_array($brandLower, $unknownBrands)) {
        error_log("⚠️ Standardizing brand '{$brand}' to 'Unknown'");
        return 'Unknown';
    }
    
    // Giữ nguyên brand hợp lệ (capitalize first letter)
    return ucfirst(trim($brand));
}

/**
 * Chuẩn hóa tên loại sản phẩm sang tiếng Việt thống nhất
 * Giữ nguyên tên phổ biến như Sneaker, Sandal
 */
function standardizeProductType($type) {
    if (empty($type)) {
        return '';
    }
    
    // Danh sách ánh xạ tên loại sản phẩm
    $typeMapping = [
        // Giày thể thao - Giữ nguyên "Sneaker"
        'sneaker' => 'Sneaker',
        'sneakers' => 'Sneaker',
        'giày thể thao' => 'Sneaker',
        'giay the thao' => 'Sneaker',
        'running' => 'Sneaker',
        'running shoe' => 'Sneaker',
        'running shoes' => 'Sneaker',
        'training' => 'Sneaker',
        'training shoe' => 'Sneaker',
        'training shoes' => 'Sneaker',
        'casual' => 'Sneaker',
        'casual shoe' => 'Sneaker',
        'casual shoes' => 'Sneaker',
        'lifestyle' => 'Sneaker',
        'basketball' => 'Sneaker',
        'basketball shoe' => 'Sneaker',
        'basketball shoes' => 'Sneaker',
        'tennis' => 'Sneaker',
        'tennis shoe' => 'Sneaker',
        'tennis shoes' => 'Sneaker',
        'walking shoe' => 'Sneaker',
        'walking shoes' => 'Sneaker',
        'gym shoe' => 'Sneaker',
        'gym shoes' => 'Sneaker',
        'athletic shoe' => 'Sneaker',
        'athletic shoes' => 'Sneaker',
        'sport shoe' => 'Sneaker',
        'sport shoes' => 'Sneaker',
        'sports shoe' => 'Sneaker',
        'sports shoes' => 'Sneaker',
        
        // Sandal - Giữ nguyên "Sandal"
        'sandal' => 'Sandal',
        'sandals' => 'Sandal',
        'dép' => 'Sandal',
        'dep' => 'Sandal',
        'slide' => 'Sandal',
        'flip-flop' => 'Sandal',
        'flipflop' => 'Sandal',
        
        // Giày cao gót
        'high heels' => 'Giày cao gót',
        'high heel' => 'Giày cao gót',
        'cao gót' => 'Giày cao gót',
        'cao got' => 'Giày cao gót',
        'giày cao gót' => 'Giày cao gót',
        'giay cao got' => 'Giày cao gót',
        'pump' => 'Giày cao gót',
        'pumps' => 'Giày cao gót',
        'stiletto' => 'Giày cao gót',
        'stilettos' => 'Giày cao gót',
        'block heel' => 'Giày cao gót',
        'kitten heels' => 'Giày cao gót',
        'kitten heel' => 'Giày cao gót',
        'slingback' => 'Giày cao gót',
        'slingbacks' => 'Giày cao gót',
        
        // Giày đế xuồng
        'wedge' => 'Giày đế xuồng',
        'wedges' => 'Giày đế xuồng',
        'đế xuồng' => 'Giày đế xuồng',
        'de xuong' => 'Giày đế xuồng',
        'giày đế xuồng' => 'Giày đế xuồng',
        'sandal đế xuồng' => 'Giày đế xuồng',  // Ưu tiên đế xuồng hơn sandal
        'sandal de xuong' => 'Giày đế xuồng',
        'sandal bịt mũi đế xuồng' => 'Giày đế xuồng',
        'sandal bit mui de xuong' => 'Giày đế xuồng',
        'wedge sandal' => 'Giày đế xuồng',
        'wedge sandals' => 'Giày đế xuồng',
        
        // Giày bệt
        'flat' => 'Giày bệt',
        'flats' => 'Giày bệt',
        'giày bệt' => 'Giày bệt',
        'giay bet' => 'Giày bệt',
        'ballet flat' => 'Giày bệt',
        'ballet flats' => 'Giày bệt',
        'giày búp bê' => 'Giày bệt',
        'bệt' => 'Giày bệt',
        'bet' => 'Giày bệt',
        
        // Giày lười
        'loafer' => 'Giày lười',
        'loafers' => 'Giày lười',
        'giày lười' => 'Giày lười',
        'giay luoi' => 'Giày lười',
        'slip-on' => 'Giày lười',
        'slip on' => 'Giày lười',
        'slipon' => 'Giày lười',
        'moccasin' => 'Giày lười',
        'moccasins' => 'Giày lười',
        'boat shoe' => 'Giày lười',
        'boat shoes' => 'Giày lười',
        
        // Giày tây
        'oxford' => 'Giày tây',
        'oxfords' => 'Giày tây',
        'giày tây' => 'Giày tây',
        'giay tay' => 'Giày tây',
        'dress shoe' => 'Giày tây',
        'dress shoes' => 'Giày tây',
        'formal shoe' => 'Giày tây',
        'formal shoes' => 'Giày tây',
        'business shoe' => 'Giày tây',
        'derby' => 'Giày tây',
        'brogue' => 'Giày tây',
        
        // Giày boot
        'boot' => 'Giày boot',
        'boots' => 'Giày boot',
        'giày boot' => 'Giày boot',
        'giay boot' => 'Giày boot',
        'ankle boot' => 'Giày boot',
        'ankle boots' => 'Giày boot',
        'chelsea boot' => 'Giày boot',
        'chelsea boots' => 'Giày boot',
        'combat boot' => 'Giày boot',
        'combat boots' => 'Giày boot',
        'knee boot' => 'Giày boot',
        'knee boots' => 'Giày boot',
        
        // Giày mules
        'mule' => 'Giày mules',
        'mules' => 'Giày mules',
        'giày mules' => 'Giày mules',
        
        // Giày quai hậu
        'quai hậu' => 'Giày quai hậu',
        'quai hau' => 'Giày quai hậu',
        'giày quai hậu' => 'Giày quai hậu',
        
        // Giày thể thao chuyên dụng
        'football boot' => 'Giày thể thao chuyên dụng',
        'football boots' => 'Giày thể thao chuyên dụng',
        'soccer cleat' => 'Giày thể thao chuyên dụng',
        'golf shoe' => 'Giày thể thao chuyên dụng',
        'golf shoes' => 'Giày thể thao chuyên dụng',
    ];
    
    // Chuyển về chữ thường để so sánh
    $typeLower = strtolower(trim($type));
    
    // QUAN TRỌNG: Sắp xếp mapping theo độ dài giảm dần để kiểm tra cụm từ dài trước
    // VD: "sandal đế xuồng" phải kiểm tra trước "sandal"
    $sortedMapping = $typeMapping;
    uksort($sortedMapping, function($a, $b) {
        return strlen($b) - strlen($a);  // Sắp xếp từ dài đến ngắn
    });
    
    // Kiểm tra trong bảng ánh xạ (từ dài đến ngắn)
    if (isset($sortedMapping[$typeLower])) {
        return $sortedMapping[$typeLower];
    }
    
    // Nếu không tìm thấy exact match, tìm partial match với cụm từ dài nhất
    foreach ($sortedMapping as $key => $value) {
        if (strpos($typeLower, $key) !== false) {
            error_log("🔍 Partial match found: '{$typeLower}' contains '{$key}' → '{$value}'");
            return $value;
        }
    }
    
    // Nếu không tìm thấy, giữ nguyên nhưng viết hoa chữ cái đầu
    return ucfirst($type);
}

/**
 * Chuẩn hóa text tiếng Anh sang tiếng Việt cho các trường thông tin sản phẩm
 * (trừ brand và các trường hợp đặc biệt như Sneaker, Sandal)
 */
function translateToVietnamese($text, $fieldType = 'general') {
    if (empty($text)) {
        return $text;
    }
    
    // Bảng dịch màu sắc
    $colorMapping = [
        // Màu cơ bản
        'black' => 'Đen',
        'white' => 'Trắng',
        'red' => 'Đỏ',
        'blue' => 'Xanh dương',
        'green' => 'Xanh lá',
        'yellow' => 'Vàng',
        'orange' => 'Cam',
        'purple' => 'Tím',
        'pink' => 'Hồng',
        'brown' => 'Nâu',
        'gray' => 'Xám',
        'grey' => 'Xám',
        'beige' => 'Be',
        'navy' => 'Xanh navy',
        'navy blue' => 'Xanh navy',
        'silver' => 'Bạc',
        'gold' => 'Vàng kim',
        'bronze' => 'Đồng',
        'cream' => 'Kem',
        'ivory' => 'Ngà',
        'khaki' => 'Kaki',
        'olive' => 'Xanh ô liu',
        'maroon' => 'Nâu đỏ',
        'burgundy' => 'Đỏ burgundy',
        'turquoise' => 'Xanh lam',
        'teal' => 'Xanh mòng két',
        'mint' => 'Xanh bạc hà',
        'coral' => 'San hô',
        'lavender' => 'Tím lavender',
        'tan' => 'Nâu nhạt',
        
        // Tổ hợp màu
        'light blue' => 'Xanh dương nhạt',
        'dark blue' => 'Xanh dương đậm',
        'light green' => 'Xanh lá nhạt',
        'dark green' => 'Xanh lá đậm',
        'light pink' => 'Hồng nhạt',
        'dark pink' => 'Hồng đậm',
        'light gray' => 'Xám nhạt',
        'dark gray' => 'Xám đậm',
        'light grey' => 'Xám nhạt',
        'dark grey' => 'Xám đậm',
    ];
    
    // Bảng dịch chất liệu
    $materialMapping = [
        'leather' => 'Da',
        'genuine leather' => 'Da thật',
        'synthetic leather' => 'Da tổng hợp',
        'faux leather' => 'Da giả',
        'patent leather' => 'Da bóng',
        'suede' => 'Da lộn',
        'nubuck' => 'Da nubuck',
        'canvas' => 'Vải canvas',
        'mesh' => 'Lưới',
        'fabric' => 'Vải',
        'textile' => 'Vải dệt',
        'rubber' => 'Cao su',
        'eva' => 'EVA',
        'foam' => 'Xốp',
        'synthetic' => 'Tổng hợp',
        'plastic' => 'Nhựa',
        'metal' => 'Kim loại',
        'wood' => 'Gỗ',
        'cork' => 'Cork',
        'satin' => 'Vải satin',
        'velvet' => 'Nhung',
        'lace' => 'Ren',
        'denim' => 'Vải denim',
        'knit' => 'Dệt kim',
    ];
    
    // Bảng dịch tính năng
    $featureMapping = [
        'waterproof' => 'Chống nước',
        'breathable' => 'Thoáng khí',
        'lightweight' => 'Nhẹ',
        'comfortable' => 'Thoải mái',
        'durable' => 'Bền',
        'cushioned' => 'Đệm êm',
        'anti-slip' => 'Chống trượt',
        'slip-resistant' => 'Chống trượt',
        'non-slip' => 'Chống trượt',
        'flexible' => 'Linh hoạt',
        'supportive' => 'Hỗ trợ tốt',
        'air cushion' => 'Đệm khí',
        'gel cushion' => 'Đệm gel',
        'memory foam' => 'Xốp nhớ hình',
        'shock absorption' => 'Giảm sốc',
        'arch support' => 'Hỗ trợ vòm bàn chân',
        'heel support' => 'Hỗ trợ gót chân',
        'ankle support' => 'Hỗ trợ mắt cá',
        'reflective' => 'Phản quang',
        'quick-dry' => 'Khô nhanh',
        'odor-resistant' => 'Kháng mùi',
        'antibacterial' => 'Kháng khuẩn',
        'high traction' => 'Bám đường tốt',
        'insulated' => 'Giữ nhiệt',
        'padded' => 'Có đệm',
        'reinforced' => 'Gia cố',
        'removable insole' => 'Lót trong tháo được',
        'lace-up' => 'Buộc dây',
        'slip-on' => 'Không dây',
        'velcro' => 'Dán dính',
        'zipper' => 'Khóa kéo',
        'buckle' => 'Khóa cài',
    ];
    
    // Bảng dịch từ chung
    $generalMapping = array_merge($colorMapping, $materialMapping, $featureMapping);
    
    // Tách text thành các từ để dịch từng phần
    $words = preg_split('/[\s,]+/', strtolower(trim($text)));
    $translatedWords = [];
    
    foreach ($words as $word) {
        $word = trim($word);
        if (empty($word)) continue;
        
        // Kiểm tra trong bảng dịch
        if (isset($generalMapping[$word])) {
            $translatedWords[] = $generalMapping[$word];
        } else {
            // Giữ nguyên nếu không tìm thấy
            $translatedWords[] = $word;
        }
    }
    
    // Nối lại các từ
    $result = implode(', ', $translatedWords);
    
    // Viết hoa chữ cái đầu
    return ucfirst($result);
}

try {
    $response = ['success' => false, 'message' => ''];
    
    // Kiểm tra đăng nhập (bỏ qua cho test mode)
    $testMode = $_POST['test_mode'] ?? null;
    if (!$testMode && !isset($_SESSION['user_id'])) {
        throw new Exception('Chưa đăng nhập');
    }
    
    // Lấy warehouse_id của user hiện tại
    $userWarehouseId = $_SESSION['warehouse_id'] ?? null;
    
    // Lấy danh sách ảnh từ POST
    $imagePaths = $_POST['image_paths'] ?? [];
    
    if (empty($imagePaths) && !$testMode) {
        throw new Exception('Không có ảnh để phân tích');
    }
    
    // Kết nối database để kiểm tra sản phẩm
    $database = new Database();
    $pdo = $database->getConnection();
    
    // Test mode handling
    if ($testMode) {
        switch ($testMode) {
            case 'existing_product':
                $response = simulateExistingProduct($pdo);
                break;
            case 'new_product':
                $response = simulateNewProduct();
                break;
            case 'unrecognized':
                $response = simulateUnrecognized();
                break;
            case 'database_check':
                $response = testDatabaseCheck($pdo, $_POST['test_product_name'] ?? 'Nike Air Max 90', $_POST['test_color'] ?? 'Trắng');
                break;
            default:
                throw new Exception('Test mode không hợp lệ');
        }
        echo json_encode($response);
        return;
    }
    
    // PHÂN TÍCH AI VỚI GEMINI THẬT (với normalization)
    error_log("Starting AI analysis for images: " . json_encode($imagePaths));
    
    // Gọi API AI để phân tích
    error_log("Calling Gemini API");
    $aiData = analyzeImagesWithAI($imagePaths);
    
    // Normalize AI output để tăng tính nhất quán
    if ($aiData) {
        $aiData = normalizeAIOutput($aiData);
    }
    
    error_log("AI analysis result (after normalization): " . json_encode($aiData));
    
    if ($aiData) {
        // Kiểm tra sản phẩm đã có trong DB chưa
        $productCheck = checkProductInDatabase($pdo, $aiData);
        
        if ($productCheck['exists']) {
            // Sản phẩm đã có trong DB - lấy thông tin chuẩn từ database
            $response['success'] = true;
            $response['data'] = $productCheck['product_data'];
            $response['message'] = 'Nhận diện sản phẩm thành công - Đã có trong database';
            $response['product_status'] = 'existing';
            $response['existing_variant_id'] = $productCheck['variant_id'];
        } else {
            // Sản phẩm chưa có trong DB - cần thêm mới
            $response['success'] = true;
            $response['data'] = $aiData;
            $response['message'] = 'Nhận diện sản phẩm thành công - Cần thêm sản phẩm mới';
            $response['product_status'] = 'new';
        }
    } else {
        $response['success'] = false;
        $response['message'] = 'AI không thể nhận diện sản phẩm - Vui lòng nhập thủ công';
        $response['product_status'] = 'unknown';
    }
    
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
}

echo json_encode($response);

function checkProductInDatabase($pdo, $aiData) {
    global $userWarehouseId; // Lấy warehouse_id của user hiện tại
    
    $result = ['exists' => false, 'product_data' => null, 'variant_id' => null];
    
    // Kiểm tra warehouse_id
    if (empty($userWarehouseId)) {
        error_log("⚠️ No warehouse_id for current user in api_phan_tich_ai.php");
        return $result;
    }
    
    // Tìm sản phẩm tương tự dựa trên tên và thương hiệu TRONG WAREHOUSE HIỆN TẠI
    $searchTerms = [];
    $params = [$userWarehouseId]; // Bắt đầu với warehouse_id
    
    // Tách tên sản phẩm thành từ khóa
    $productName = $aiData['product_name'] ?? '';
    $brand = $aiData['brand'] ?? '';
    $color = $aiData['color'] ?? '';
    
    if ($productName) {
        $nameWords = explode(' ', trim($productName));
        foreach ($nameWords as $word) {
            if (strlen(trim($word)) > 2) { // Bỏ qua từ quá ngắn
                $searchTerms[] = "LOWER(p.name) LIKE LOWER(?)";
                $params[] = '%' . trim($word) . '%';
            }
        }
    }
    
    if ($brand) {
        $searchTerms[] = "LOWER(p.brand) LIKE LOWER(?)";
        $params[] = '%' . $brand . '%';
    }
    
    if (empty($searchTerms)) {
        return $result;
    }
    
    // Query tìm kiếm sản phẩm CHỈ TRONG WAREHOUSE HIỆN TẠI
    $sql = "
        SELECT p.product_id, p.name, p.brand, p.type, p.material, p.features, p.tags,
               pv.variant_id, pv.sku, pv.color, pv.size, pv.price,
               c.name as category_name, c.category_id,
               COUNT(*) as match_score
        FROM products p
        JOIN product_variants pv ON p.product_id = pv.product_id
        JOIN categories c ON p.category_id = c.category_id
        INNER JOIN inventory inv ON pv.variant_id = inv.variant_id
        WHERE inv.warehouse_id = ? AND (" . implode(' OR ', $searchTerms) . ")
    ";
    
    // Nếu có màu sắc, ưu tiên sản phẩm cùng màu
    if ($color) {
        $sql .= " AND LOWER(pv.color) LIKE LOWER(?)";
        $params[] = '%' . $color . '%';
    }
    
    $sql .= "
        GROUP BY p.product_id, pv.variant_id
        ORDER BY match_score DESC, p.product_id DESC
        LIMIT 1
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($product) {
        // Tính toán độ tương tự
        $similarity = calculateSimilarity($aiData, $product);
        
        // Chỉ coi là "có trong DB" nếu độ tương tự đủ cao
        if ($similarity >= 0.5) { // Giảm từ 70% xuống 50% để phù hợp hơn
            $result['exists'] = true;
            $result['variant_id'] = $product['variant_id'];
            $result['product_data'] = [
                'product_id' => $product['product_id'],
                'variant_id' => $product['variant_id'],
                'product_name' => $product['name'],
                'brand' => $product['brand'],
                'type' => $product['type'],
                'color' => $product['color'],
                'size' => $product['size'],
                'material' => $product['material'],
                'features' => $product['features'],
                'tags' => $product['tags'],
                'sku' => $product['sku'],
                'price' => $product['price'],
                'category_id' => $product['category_id'],
                'category_name' => $product['category_name'],
                'confidence' => $aiData['confidence'] ?? 0.8,
                'similarity_score' => $similarity,
                'match_reason' => 'Tìm thấy sản phẩm tương tự trong database'
            ];
        }
    }
    
    return $result;
}

// Hàm calculateSimilarity() cũ đã được XÓA
// Project hiện thống nhất dùng calculateEnhancedSimilarity() từ helpers/TroGiupDoTuongDong.php
// Công thức mới: Brand(30%) + Name(25%) + Color(25%) + Type(20%)

function compareStrings($str1, $str2) {
    $str1 = strtolower(trim($str1));
    $str2 = strtolower(trim($str2));
    
    if ($str1 === $str2) {
        return 1.0;
    }
    
    // Cải thiện: nếu str1 chứa hoàn toàn trong str2 hoặc ngược lại
    if (strpos($str2, $str1) !== false || strpos($str1, $str2) !== false) {
        return max(strlen($str1) / strlen($str2), strlen($str2) / strlen($str1));
    }
    
    // Sử dụng similar_text để tính độ tương tự
    $percent = 0;
    similar_text($str1, $str2, $percent);
    
    return $percent / 100;
}

function analyzeImagesWithAI($imagePaths) {
    // Sử dụng Gemini AI thật với TẤT CẢ ảnh thay vì chỉ 1 ảnh
    try {
        if (empty($imagePaths)) {
            return false;
        }
        
        error_log("Analyzing " . count($imagePaths) . " images with AI");
        
        // Gọi Gemini API để phân tích TẤT CẢ ảnh
        $geminiResult = callGeminiForMultipleImages($imagePaths);
        
        if ($geminiResult && $geminiResult['success']) {
            $aiData = $geminiResult['data'];
            
            // Chuẩn hóa dữ liệu trả về theo format cũ
            return [
                'product_name' => $aiData['product_name'] ?? 'Sản phẩm không xác định',
                'brand' => $aiData['brand'] ?? '',
                'type' => $aiData['category'] ?? '',
                'color' => $aiData['color'] ?? '',
                'size' => $aiData['size'] ?? '',
                'material' => $aiData['material'] ?? '',
                'confidence' => $aiData['confidence'] ?? 0.5,
                'features' => [$aiData['style'] ?? ''],
                'suggested_category' => $aiData['category'] ?? '',
                'tags' => explode(', ', $aiData['description'] ?? ''),
                'description' => $aiData['description'] ?? '',
                'analyzed_images' => count($imagePaths),
                'image_analysis' => $aiData['image_analysis'] ?? []
            ];
        }
        
        return false;
        
    } catch (Exception $e) {
        error_log("Error in analyzeImagesWithAI: " . $e->getMessage());
        return false;
    }
}

/**
 * Gọi Gemini API để phân tích sản phẩm từ ảnh
 */
function callGeminiForProductAnalysis($imagePath) {
    $apiKey = 'AIzaSyBYrQqbBNv88IxUB_DuuHvq5R9tsGmNXyU';
    
    try {
        error_log("Calling Gemini API for image: " . $imagePath);
        
        // Check if file exists
        if (!file_exists($imagePath)) {
            error_log("Image file not found: " . $imagePath);
            return ['success' => false, 'error' => 'File không tồn tại'];
        }
        
        // Encode image to base64
        $imageData = base64_encode(file_get_contents($imagePath));
        $mimeType = mime_content_type($imagePath);
        
        error_log("Image encoded, mime type: " . $mimeType . ", size: " . strlen($imageData));
        
        $prompt = "Phân tích ảnh sản phẩm này và trả về CHÍNH XÁC định dạng JSON sau đây (không thêm text khác):

{
    \"product_name\": \"tên sản phẩm cụ thể\",
    \"brand\": \"thương hiệu (nếu nhận diện được)\",
    \"colors\": [\"màu 1\", \"màu 2\"],
    \"size\": \"kích thước (nếu có)\",
    \"type\": \"loại sản phẩm theo quy tắc chuẩn\",
    \"material\": \"chất liệu (nếu nhận diện được)\",
    \"features\": \"tính năng nổi bật\",
    \"confidence\": 0.8,
    \"description\": \"mô tả chi tiết sản phẩm\",
    \"tags\": [\"tag1\", \"tag2\", \"tag3\"]
}

⚠️ QUY TẮC PHÂN LOẠI GIÀY - CỰC KỲ QUAN TRỌNG ⚠️
=== NGUYÊN TẮC ƯU TIÊN TUYỆT ĐỐI ===
LUÔN LUÔN chọn loại CỤ THỂ NHẤT, KHÔNG BAO GIỜ chọn loại CHUNG:

🔴 QUY TẮC ĐẶC BIỆT CHO ĐẾ XUỒNG (PRIORITY #1):
- Nếu giày/sandal có ĐẾ XUỒNG (wedge heel) → type PHẢI LÀ \"Giày đế xuồng\"
- KHÔNG được dùng \"Sandal\" nếu có đế xuồng
- KHÔNG được dùng \"Sandal đế xuồng\" - chỉ dùng \"Giày đế xuồng\"
- VÍ DỤ: Sandal nữ đế xuồng → type: \"Giày đế xuồng\" (KHÔNG PHẢI \"Sandal\")

Các quy tắc ưu tiên khác:
- Giày cao gót quai hậu → type: \"Giày cao gót\" (KHÔNG phải \"Giày quai hậu\")
- Running shoe → type: \"Sneaker\" (KHÔNG phải \"Giày thể thao chuyên dụng\")
- Giày cao gót có quai hậu → Chọn \"Giày cao gót\" (KHÔNG phải \"Giày quai hậu\")
- Running shoe → Chọn \"Sneaker\" (KHÔNG phải \"Giày thể thao chuyên dụng\")

=== GIÀY THỂ THAO/CASUAL ===
- Sneaker: Giày thể thao, running, training, gym, basketball, tennis, casual, lifestyle, walking
- Giày thể thao chuyên dụng: Giày chuyên dụng (đá bóng, golf, bóng chuyền)

=== GIÀY CAO GÓT & ĐẾ CAO ===  
- Giày cao gót: Giày cao gót nhọn, pump, stiletto, block heel (gót riêng biệt)
- Giày đế xuồng: Giày có đế xuồng liền (wedge heel), kể cả sandal đế xuồng

=== GIÀY BỆT & LƯỜI ===
- Giày bệt: Giày bệt kín mũi, ballet flat, giày búp bê
- Giày lười: Giày lười, slip-on, moccasin, boat shoe

=== GIÀY CHÍNH THỨC ===
- Giày tây: Giày tây, dress shoe, formal shoe, business shoe, oxford, derby

=== GIÀY ĐẶC BIỆT ===
- Giày boot: Tất cả loại boot có cổ (ankle, knee, combat, chelsea)
- Giày mules: Giày hở gót, không quai hậu (không phải sandal)
- Giày quai hậu: Giày có quai hậu điều chỉnh được (slingback)

=== DÉP & SANDAL ===
- Sandal: Sandal thông thường, dép quai, slide, flip-flop (KHÔNG có đế xuồng)
- Dép: Dép đi trong nhà, dép lê

QUY TẮC NGÔN NGỮ - CỰC KỲ QUAN TRỌNG:
- TẤT CẢ thông tin PHẢI bằng TIẾNG VIỆT (product_name, colors, material, features, description, tags)
- CHỈ CÓ các trường hợp NGOẠI LỆ sau được phép giữ tiếng Anh:
  + brand: Tên thương hiệu gốc (Nike, Adidas, Gucci, Unknown...)
  + type: CHỈ \"Sneaker\" và \"Sandal\" GIỮ NGUYÊN tiếng Anh, TẤT CẢ các loại khác PHẢI có chữ \"Giày\" ở đầu:
    * Giày cao gót (KHÔNG phải \"Cao gót\")
    * Giày bệt (KHÔNG phải \"Bệt\")
    * Giày lười (KHÔNG phải \"Lười\")
    * Giày tây (KHÔNG phải \"Tây\" hoặc \"Oxford\")
    * Giày boot (KHÔNG phải \"Boot\")
    * Giày đế xuồng (KHÔNG phải \"Đế xuồng\" hoặc \"Wedge\")
    * Giày mules (KHÔNG phải \"Mules\")
    * Giày quai hậu (KHÔNG phải \"Quai hậu\" hoặc \"Slingback\")
    * Giày thể thao chuyên dụng (KHÔNG phải \"Thể thao chuyên dụng\")
    * Dép (giữ nguyên)

VÍ DỤ PHÂN LOẠI ĐÚNG:
1. Sandal đế xuồng → type: \"Giày đế xuồng\" (vì đế xuồng là đặc điểm cụ thể hơn sandal)
2. Giày cao gót quai hậu → type: \"Giày cao gót\" (vì cao gót là đặc điểm chính)
3. Sneaker chạy bộ → type: \"Sneaker\" (không phải \"Giày thể thao chuyên dụng\")
4. Sandal quai ngang → type: \"Sandal\" (sandal thông thường)
5. Giày boot cao cổ → type: \"Giày boot\" (PHẢI có chữ \"Giày\")
6. Giày lười da → type: \"Giày lười\"

QUY TẮC TÍNH NHẤT QUÁN - CỰC KỲ QUAN TRỌNG:
PHẢI PHÂN TÍCH CHÍNH XÁC VÀ NHẤT QUÁN:
- CÙNG 1 SẢN PHẨM = PHẢI TRẢ VỀ CÙNG KẾT QUẢ MỌI LẦN
- KHÔNG ĐƯỢC thay đổi category/type giữa các lần phân tích
- KHÔNG ĐƯỢC thay đổi màu sắc giữa các lần phân tích  
- Chỉ phân tích MÀU CHÍNH, KHÔNG mô tả chi tiết như khóa, lót, viền

VÍ DỤ: 
SAI - Lần 1 ra Giày cao gót, Lần 2 ra Giày quai hậu (KHÔNG NHẤT QUÁN)
ĐÚNG - Mọi lần đều ra Giày cao gót (nếu có gót cao thì ưu tiên)

SAI - colors là Đỏ burgundy, Đen lót trong, Vàng khóa (Quá chi tiết)
ĐÚNG - colors là Đỏ burgundy, Đen, Vàng kim (Chỉ màu chính)

⚠️ QUY TẮC CHUẨN HÓA MÀU SẮC - BẮT BUỘC ⚠️
PHẢI sử dụng TÊN MÀU TIẾNG VIỆT CHUẨN:

MÀU CƠ BẢN: Đen, Trắng, Đỏ, Xanh dương, Xanh lá, Vàng, Hồng, Tím, Cam, Nâu, Xám

MÀU NÂNG CAO:
- Đỏ burgundy, Đỏ rượu, Nâu đỏ, San hô
- Xanh navy, Xanh lam, Xanh mòng két, Xanh bạc hà, Xanh ô liu
- Vàng kim, Vàng hồng, Vàng champagne, Bạc, Đồng
- Nâu nhạt, Nâu đậm, Nâu camel, Nâu chocolate, Nâu cà phê
- Kem, Ngà, Be, Da
- Xám nhạt, Xám đậm, Xám đen, Xám nâu

MÀU VỚI ĐỘ SÁNG: Thêm nhạt hoặc đậm (VD: Xanh dương nhạt, Đỏ đậm)

NHIỀU MÀU: Dùng Nhiều màu hoặc liệt kê 2-3 màu chính

QUAN TRỌNG:
- KHÔNG dùng tiếng Anh: Black, White, Red
- KHÔNG mô tả vị trí: Đen lót trong, Vàng khóa
- KHÔNG lặp lại: Đỏ Đỏ Đỏ burgundy
- CHỈ dùng màu từ DANH SÁCH CHUẨN trên

VÍ DỤ MÀU SẮC ĐÚNG:
ĐÚNG: colors là Đen
ĐÚNG: colors là Đỏ burgundy
ĐÚNG: colors là Xanh dương, Trắng
ĐÚNG: colors là Vàng hồng
ĐÚNG: colors là Nhiều màu

VÍ DỤ MÀU SẮC SAI:
SAI: colors là Black (phải dùng Đen)
SAI: colors là Navy Blue (phải dùng Xanh navy)
SAI: colors là Rose Gold (phải dùng Vàng hồng)
SAI: colors là Đỏ Đỏ Đỏ burgundy (lặp lại, phải dùng Đỏ burgundy)

VÍ DỤ VỀ NGÔN NGỮ KHÁC:
- colors: [\"Đen\", \"Trắng\"] (KHÔNG: [\"Black\", \"White\"])
- Nếu chỉ 1 màu: colors: [\"Đen\"] (vẫn dùng mảng)
- material: \"Da thật, đế cao su\" (KHÔNG: \"Genuine leather, rubber sole\")
- features: \"Đệm khí, chống nước\" (KHÔNG: \"Air cushion, waterproof\")
- description: \"Giày chạy bộ cao cấp với công nghệ đệm êm\" (KHÔNG: \"Premium running shoe with soft cushioning\")
- tags: [\"thể thao\", \"chạy bộ\", \"thoáng khí\"] (KHÔNG: [\"sport\", \"running\", \"breathable\"])

QUY TẮC THƯƠNG HIỆU:
- Nếu không nhận diện được thương hiệu, PHẢI điền \"Unknown\" (chữ U viết hoa)
- KHÔNG được dùng \"Fashion\", \"Không xác định\", \"khong xac dinh\", \"N/A\" hoặc để trống brand
- brand PHẢI là \"Unknown\" nếu không thấy logo/text thương hiệu rõ ràng trên sản phẩm
- KHÔNG BAO GIỜ sử dụng \"Fashion\" làm thương hiệu - phải dùng \"Unknown\" thay thế

QUY TẮC KHÁC:
- Chỉ trả về JSON, không có text giải thích
- Confidence từ 0.1 đến 1.0
- product_name phải cụ thể và mô tả rõ sản phẩm BẰNG TIẾNG VIỆT
- type PHẢI chọn CHÍNH XÁC từ danh sách trên (Sneaker, Giày cao gót, Giày bệt, Giày lười, Giày tây, Boot, Sandal, Giày đế xuồng, Giày mules, Giày quai hậu, Dép, Giày thể thao chuyên dụng)
- PHẢI NHẤT QUÁN: Cùng 1 sản phẩm phải cho kết quả giống nhau mọi lần
- Ưu tiên đặc điểm CỤ THỂ: Sandal đế xuồng → \"Giày đế xuồng\" (không phải \"Sandal\")
- Không viết \"Giày\" trước Boot: Dùng \"Boot\" (không phải \"Giày boot\")";

        $data = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt],
                        [
                            'inline_data' => [
                                'mime_type' => $mimeType,
                                'data' => $imageData
                            ]
                        ]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.0,
                'topK' => 1,
                'topP' => 1,
                'maxOutputTokens' => 4096
            ]
        ];

        // Danh sách endpoints để thử
        $endpoints = [
            "https://generativelanguage.googleapis.com/v1/models/gemini-1.5-flash:generateContent?key=" . $apiKey,
            "https://generativelanguage.googleapis.com/v1/models/gemini-1.5-pro:generateContent?key=" . $apiKey,
            "https://generativelanguage.googleapis.com/v1beta/models/gemini-pro-vision:generateContent?key=" . $apiKey
        ];

        foreach ($endpoints as $endpoint) {
            error_log("Trying endpoint: " . $endpoint);
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $endpoint,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                ],
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => false
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            error_log("Response code: " . $httpCode);
            if ($curlError) {
                error_log("Curl error: " . $curlError);
            }
            if ($response) {
                error_log("Response received, length: " . strlen($response));
            }

            if ($httpCode === 200 && $response) {
                $result = json_decode($response, true);
                error_log("JSON decoded result: " . json_encode($result));
                
                if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
                    $aiText = $result['candidates'][0]['content']['parts'][0]['text'];
                    error_log("AI Text received: " . $aiText);
                    
                    // Làm sạch JSON response
                    $aiText = preg_replace('/```json\s*/', '', $aiText);
                    $aiText = preg_replace('/```\s*$/', '', $aiText);
                    $aiText = trim($aiText);
                    
                    error_log("Cleaned AI Text: " . $aiText);
                    
                    $aiData = json_decode($aiText, true);
                    
                    // Chuẩn hóa thương hiệu thành "Unknown" nếu không xác định
                    if (isset($aiData['brand'])) {
                        $originalBrand = $aiData['brand'];
                        $aiData['brand'] = standardizeBrand($originalBrand);
                        if ($originalBrand !== $aiData['brand']) {
                            error_log("🏷️ Standardized brand: '{$originalBrand}' -> '{$aiData['brand']}'");
                        }
                    }
                    
                    // Chuẩn hóa tên loại sản phẩm
                    if (isset($aiData['type'])) {
                        $originalType = $aiData['type'];
                        $aiData['type'] = standardizeProductType($originalType);
                        if ($originalType !== $aiData['type']) {
                            error_log("📝 Standardized product type: '{$originalType}' -> '{$aiData['type']}'");
                        }
                    }
                    if (isset($aiData['category'])) {
                        $originalCategory = $aiData['category'];
                        $aiData['category'] = standardizeProductType($originalCategory);
                        if ($originalCategory !== $aiData['category']) {
                            error_log("📝 Standardized category: '{$originalCategory}' -> '{$aiData['category']}'");
                        }
                    }
                    
                    // 🔴 BẮT BUỘC: Kiểm tra đặc biệt cho Sandal đế xuồng
                    if (isset($aiData['type']) && strtolower($aiData['type']) === 'sandal') {
                        $checkFields = [
                            $aiData['product_name'] ?? '',
                            $aiData['description'] ?? '',
                            is_array($aiData['tags'] ?? []) ? implode(' ', $aiData['tags']) : ($aiData['tags'] ?? ''),
                            $aiData['features'] ?? ''
                        ];
                        
                        $combinedText = strtolower(implode(' ', $checkFields));
                        
                        // Nếu có từ khóa "đế xuồng" hoặc "wedge" → BUỘC chuyển thành "Giày đế xuồng"
                        if (strpos($combinedText, 'đế xuồng') !== false || 
                            strpos($combinedText, 'de xuong') !== false ||
                            strpos($combinedText, 'wedge') !== false) {
                            error_log("🔴 FORCED CORRECTION: Sandal with wedge heel detected → Changing type from 'Sandal' to 'Giày đế xuồng'");
                            $aiData['type'] = 'Giày đế xuồng';
                        }
                    }
                    
                    // Chuẩn hóa các trường text tiếng Anh sang tiếng Việt
                    $fieldsToTranslate = ['color', 'colors', 'material', 'features', 'description'];
                    foreach ($fieldsToTranslate as $field) {
                        if (isset($aiData[$field]) && !empty($aiData[$field])) {
                            $original = $aiData[$field];
                            $aiData[$field] = translateToVietnamese($original, $field);
                            if ($original !== $aiData[$field]) {
                                error_log("🌐 Translated {$field}: '{$original}' -> '{$aiData[$field]}'");
                            }
                        }
                    }
                    
                    // Chuẩn hóa tags nếu là array
                    if (isset($aiData['tags']) && is_array($aiData['tags'])) {
                        $originalTags = $aiData['tags'];
                        $aiData['tags'] = array_map(function($tag) {
                            return translateToVietnamese($tag, 'tag');
                        }, $aiData['tags']);
                        if ($originalTags !== $aiData['tags']) {
                            error_log("🌐 Translated tags: " . json_encode($originalTags) . " -> " . json_encode($aiData['tags']));
                        }
                    }
                    
                    error_log("Final AI Data: " . json_encode($aiData));
                    
                    if ($aiData && is_array($aiData)) {
                        return [
                            'success' => true,
                            'data' => $aiData
                        ];
                    } else {
                        error_log("Failed to parse AI JSON response");
                    }
                } else {
                    error_log("No text found in API response");
                }
            } else {
                error_log("API call failed with code: " . $httpCode);
                if ($response) {
                    error_log("Error response: " . $response);
                }
            }
        }
        
        return ['success' => false, 'error' => 'Không thể kết nối với Gemini AI'];
        
    } catch (Exception $e) {
        error_log("Gemini API Error: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Test simulation functions chỉ cho test mode
function simulateExistingProduct($pdo) {
    // Tạo dữ liệu AI giả cho test
    $aiData = [
        'product_name' => 'Nike Air Max 90',
        'brand' => 'Nike',
        'color' => 'Trắng',
        'size' => '42',
        'confidence' => 0.95
    ];
    
    // Kiểm tra trong database
    $dbCheck = checkProductInDatabase($pdo, $aiData);
    
    if ($dbCheck['exists']) {
        return [
            'success' => true,
            'product_status' => 'existing',
            'data' => $dbCheck['product_data'],
            'existing_variant_id' => $dbCheck['variant_id'],
            'message' => 'Test: Tìm thấy sản phẩm trong database'
        ];
    } else {
        return [
            'success' => true,
            'product_status' => 'new',
            'data' => $aiData,
            'message' => 'Test: Sản phẩm chưa có trong database'
        ];
    }
}

function simulateNewProduct() {
    $testData = [
        'product_name' => 'Adidas UltraBoost 22 Special Edition',
        'brand' => 'Adidas',
        'type' => 'Running Shoe',  // This will be normalized to "Sneaker"
        'category' => 'Running Shoe', // This will be normalized to "Sneaker"
        'color' => 'Xanh Navy',
        'size' => '43',
        'confidence' => 0.88,
        'description' => 'Giày chạy bộ cao cấp với công nghệ Boost'
    ];
    
    // Apply normalization to test data
    $normalizedData = normalizeAIOutput($testData);
    
    return [
        'success' => true,
        'product_status' => 'new',
        'data' => $normalizedData,
        'message' => 'Test: AI nhận diện sản phẩm mới (đã chuẩn hóa)'
    ];
}

function simulateUnrecognized() {
    return [
        'success' => false,
        'product_status' => 'unrecognized',
        'data' => null,
        'message' => 'Test: AI không thể nhận diện sản phẩm'
    ];
}

function testDatabaseCheck($pdo, $productName, $color) {
    $dbCheck = checkProductInDatabase($pdo, [
        'product_name' => $productName,
        'color' => $color,
        'brand' => 'Nike'
    ]);
    
    return [
        'success' => true,
        'test_query' => "Tìm kiếm: $productName - $color",
        'database_result' => $dbCheck,
        'message' => 'Test database check completed'
    ];
}

/**
 * Gọi Gemini API để phân tích NHIỀU ảnh cùng lúc
 */
function callGeminiForMultipleImages($imagePaths) {
    $apiKey = 'AIzaSyBYrQqbBNv88IxUB_DuuHvq5R9tsGmNXyU';
    
    try {
        error_log("Calling Gemini API for multiple images: " . json_encode($imagePaths));
        
        // Chuẩn bị parts cho request - text prompt trước
        $parts = [];
        
        // Thêm text prompt
        $prompt = "Phân tích TẤT CẢ ảnh sản phẩm này (có thể là cùng 1 sản phẩm từ nhiều góc độ khác nhau) và trả về CHÍNH XÁC định dạng JSON sau đây:

{
    \"product_name\": \"tên sản phẩm cụ thể và chi tiết nhất\",
    \"brand\": \"thương hiệu (nhận diện từ logo, text trên sản phẩm)\",
    \"colors\": [\"màu 1\", \"màu 2\", \"màu 3\"],
    \"size\": \"kích thước (nếu có)\",
    \"category\": \"danh mục sản phẩm theo quy tắc chuẩn\",
    \"material\": \"chất liệu (da, vải, cao su, etc.)\",
    \"style\": \"phong cách/kiểu dáng chi tiết\",
    \"confidence\": 0.9,
    \"description\": \"mô tả tổng hợp từ tất cả ảnh\",
    \"image_analysis\": [
        {\"image_index\": 1, \"description\": \"mô tả ảnh 1\", \"key_features\": [\"đặc điểm 1\", \"đặc điểm 2\"]},
        {\"image_index\": 2, \"description\": \"mô tả ảnh 2\", \"key_features\": [\"đặc điểm 1\", \"đặc điểm 2\"]}
    ]
}

QUY TẮC PHÂN LOẠI GIÀY QUAN TRỌNG (PHẢI tuân thủ):
=== GIÀY THỂ THAO/CASUAL ===
- Sneaker: Tất cả loại giày thể thao, running, training, gym, basketball, tennis, casual, lifestyle, walking
- Giày thể thao chuyên dụng: Giày chuyên dụng như đá bóng, golf

=== GIÀY CAO GÓT ===  
- Giày cao gót: Giày cao gót thông thường, pump, stiletto, block heel
- Giày đế xuồng: Giày đế xuồng

=== GIÀY BỆT & LƯỜI ===
- Giày bệt: Giày bệt, ballet flat, giày búp bê
- Giày lười: Giày lười, slip-on, moccasin, boat shoe

=== GIÀY CHÍNH THỨC ===
- Giày tây: Giày tây, dress shoe, formal shoe, business shoe

=== KHÁC ===
- Giày boot: Tất cả loại boot (ankle, knee, combat, chelsea, etc.)
- Sandal: Tất cả loại sandal, dép, slide, flip-flop
- Giày mules: Giày không quai hậu
- Giày quai hậu: Giày có quai hậu
- Dép: Dép đi trong nhà

QUY TẮC TÍNH NHẤT QUÁN - CỰC KỲ QUAN TRỌNG:
PHẢI PHÂN TÍCH CHÍNH XÁC VÀ NHẤT QUÁN:
- CÙNG 1 SẢN PHẨM = PHẢI TRẢ VỀ CÙNG KẾT QUẢ MỌI LẦN
- KHÔNG ĐƯỢC thay đổi category/type giữa các lần phân tích
- KHÔNG ĐƯỢC thay đổi màu sắc giữa các lần phân tích
- Chỉ phân tích MÀU CHÍNH của sản phẩm, KHÔNG mô tả chi tiết như khóa, lót, viền

VÍ DỤ VỀ TÍNH NHẤT QUÁN:
SAI: Lần 1 ra Giày cao gót, Lần 2 ra Giày quai hậu (KHÔNG NHẤT QUÁN)
ĐÚNG: Mọi lần đều ra Giày cao gót (nếu có gót cao, ưu tiên Giày cao gót)

SAI: colors là Đỏ burgundy, Đen (lót), Vàng (khóa) - Quá chi tiết
ĐÚNG: colors là Đỏ burgundy, Đen, Vàng kim - Chỉ màu chính

SAI: colors là Đỏ Đỏ Đỏ burgundy - Lặp lại
ĐÚNG: colors là Đỏ burgundy - Không lặp

QUY TẮC CHUẨN HÓA MÀU SẮC (BẮT BUỘC):
PHẢI sử dụng TÊN MÀU TIẾNG VIỆT CHUẨN:

Màu cơ bản: Đen, Trắng, Đỏ, Xanh dương, Xanh lá, Vàng, Hồng, Tím, Cam, Nâu, Xám

Màu nâng cao:
- Đỏ burgundy, Đỏ rượu, Nâu đỏ, San hô
- Xanh navy, Xanh lam, Xanh mòng két, Xanh bạc hà, Xanh ô liu  
- Vàng kim, Vàng hồng, Vàng champagne, Bạc, Đồng
- Nâu nhạt, Nâu đậm, Nâu camel, Nâu chocolate, Nâu cà phê
- Kem, Ngà, Be, Da
- Xám nhạt, Xám đậm, Xám đen, Xám nâu

⚠️ CHỈ LIỆT KÊ MÀU CHÍNH, KHÔNG MÔ TẢ VỊ TRÍ:
✅ ĐÚNG: [\"Đỏ burgundy\", \"Đen\", \"Vàng kim\"]
❌ SAI: [\"Đỏ burgundy\", \"Đen (lót trong)\", \"Vàng kim (khóa)\"]
❌ SAI: [\"Đỏ burgundy\", \"Đen\", \"Vàng Đồng (khóa)\"]

VÍ DỤ MÀU SẮC ĐÚNG:
✅ colors: [\"Đen\"]
✅ colors: [\"Đỏ burgundy\"]  
✅ colors: [\"Xanh dương\", \"Trắng\"]
✅ colors: [\"Vàng hồng\"]
✅ colors: [\"Nhiều màu\"]

KHÔNG ĐƯỢC DÙNG:
❌ colors: [\"Black\", \"White\"] → Phải dùng [\"Đen\", \"Trắng\"]
❌ colors: [\"Navy Blue\"] → Phải dùng [\"Xanh navy\"]
❌ colors: [\"Rose Gold\"] → Phải dùng [\"Vàng hồng\"]
❌ colors: [\"Đỏ Đỏ Đỏ burgundy\"] → Phải dùng [\"Đỏ burgundy\"]

QUY TẮC CHUNG:
- Phân tích TẤT CẢ ảnh để có thông tin đầy đủ nhất
- Kết hợp thông tin từ nhiều góc nhìn để tăng độ chính xác
- Chỉ trả về JSON, không có text giải thích
- Confidence cao hơn khi có nhiều ảnh xác nhận cùng sản phẩm
- Nhận diện chính xác thương hiệu từ logo/text trên sản phẩm
- Nếu không nhận diện được thương hiệu, PHẢI điền \"Unknown\" (chữ U viết hoa)
- KHÔNG được dùng \"Không xác định\", \"khong xac dinh\", \"N/A\" hoặc để trống brand
- brand PHẢI là \"Unknown\" nếu không thấy logo/text thương hiệu rõ ràng trên sản phẩm
- category PHẢI chọn CHÍNH XÁC từ danh sách trên (Sneaker, Giày cao gót, Giày bệt, Giày lười, Giày tây, Giày boot, Sandal, Giày đế xuồng, Giày mules, Giày quai hậu, Dép, Giày thể thao chuyên dụng)
- colors PHẢI sử dụng TÊN MÀU TIẾNG VIỆT CHUẨN từ danh sách trên";

        $parts[] = ['text' => $prompt];
        
        // Thêm tất cả ảnh vào request
        foreach ($imagePaths as $index => $imagePath) {
            if (!file_exists($imagePath)) {
                error_log("Image file not found: " . $imagePath);
                continue;
            }
            
            $imageData = base64_encode(file_get_contents($imagePath));
            $mimeType = mime_content_type($imagePath);
            
            $parts[] = [
                'inline_data' => [
                    'mime_type' => $mimeType,
                    'data' => $imageData
                ]
            ];
            
            error_log("Added image " . ($index + 1) . ": " . $imagePath . " (mime: " . $mimeType . ")");
        }

        $data = [
            'contents' => [
                [
                    'parts' => $parts
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.0,
                'topK' => 1,
                'topP' => 1,
                'maxOutputTokens' => 4096
            ]
        ];

        // Sử dụng gemini-1.5-pro cho xử lý nhiều ảnh tốt hơn
        $endpoint = "https://generativelanguage.googleapis.com/v1/models/gemini-1.5-pro:generateContent?key=" . $apiKey;
        
        error_log("Sending request with " . count($parts) . " parts to Gemini API");
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 60, // Tăng timeout cho xử lý nhiều ảnh
            CURLOPT_SSL_VERIFYPEER => false
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        error_log("Multi-image analysis response code: " . $httpCode);
        if ($curlError) {
            error_log("Curl error: " . $curlError);
        }

        if ($httpCode === 200 && $response) {
            $responseData = json_decode($response, true);
            error_log("Multi-image analysis response: " . $response);
            
            if (isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
                $aiResponse = $responseData['candidates'][0]['content']['parts'][0]['text'];
                
                // Parse JSON response
                $jsonData = json_decode($aiResponse, true);
                if ($jsonData !== null) {
                    // Chuẩn hóa thương hiệu thành "Unknown" nếu không xác định
                    if (isset($jsonData['brand'])) {
                        $originalBrand = $jsonData['brand'];
                        $jsonData['brand'] = standardizeBrand($originalBrand);
                        if ($originalBrand !== $jsonData['brand']) {
                            error_log("🏷️ Standardized brand: '{$originalBrand}' -> '{$jsonData['brand']}'");
                        }
                    }
                    
                    // Chuẩn hóa tên loại sản phẩm
                    if (isset($jsonData['type'])) {
                        $originalType = $jsonData['type'];
                        $jsonData['type'] = standardizeProductType($originalType);
                        if ($originalType !== $jsonData['type']) {
                            error_log("📝 Standardized product type: '{$originalType}' -> '{$jsonData['type']}'");
                        }
                    }
                    if (isset($jsonData['category'])) {
                        $originalCategory = $jsonData['category'];
                        $jsonData['category'] = standardizeProductType($originalCategory);
                        if ($originalCategory !== $jsonData['category']) {
                            error_log("📝 Standardized category: '{$originalCategory}' -> '{$jsonData['category']}'");
                        }
                    }
                    
                    // 🔴 BẮT BUỘC: Kiểm tra đặc biệt cho Sandal đế xuồng
                    if (isset($jsonData['type']) && strtolower($jsonData['type']) === 'sandal') {
                        $checkFields = [
                            $jsonData['product_name'] ?? '',
                            $jsonData['description'] ?? '',
                            is_array($jsonData['tags'] ?? []) ? implode(' ', $jsonData['tags']) : ($jsonData['tags'] ?? ''),
                            $jsonData['features'] ?? ''
                        ];
                        
                        $combinedText = strtolower(implode(' ', $checkFields));
                        
                        // Nếu có từ khóa "đế xuồng" hoặc "wedge" → BUỘC chuyển thành "Giày đế xuồng"
                        if (strpos($combinedText, 'đế xuồng') !== false || 
                            strpos($combinedText, 'de xuong') !== false ||
                            strpos($combinedText, 'wedge') !== false) {
                            error_log("🔴 FORCED CORRECTION: Sandal with wedge heel detected → Changing type from 'Sandal' to 'Giày đế xuồng'");
                            $jsonData['type'] = 'Giày đế xuồng';
                        }
                    }
                    
                    // Chuẩn hóa các trường text tiếng Anh sang tiếng Việt
                    $fieldsToTranslate = ['color', 'colors', 'material', 'features', 'description'];
                    foreach ($fieldsToTranslate as $field) {
                        if (isset($jsonData[$field]) && !empty($jsonData[$field])) {
                            $original = $jsonData[$field];
                            $jsonData[$field] = translateToVietnamese($original, $field);
                            if ($original !== $jsonData[$field]) {
                                error_log("🌐 Translated {$field}: '{$original}' -> '{$jsonData[$field]}'");
                            }
                        }
                    }
                    
                    // Chuẩn hóa tags nếu là array
                    if (isset($jsonData['tags']) && is_array($jsonData['tags'])) {
                        $originalTags = $jsonData['tags'];
                        $jsonData['tags'] = array_map(function($tag) {
                            return translateToVietnamese($tag, 'tag');
                        }, $jsonData['tags']);
                        if ($originalTags !== $jsonData['tags']) {
                            error_log("🌐 Translated tags: " . json_encode($originalTags) . " -> " . json_encode($jsonData['tags']));
                        }
                    }
                    
                    error_log("Multi-image analysis successful, confidence: " . ($jsonData['confidence'] ?? 'unknown'));
                    return ['success' => true, 'data' => $jsonData];
                } else {
                    error_log("Failed to parse JSON from multi-image AI response: " . $aiResponse);
                    return ['success' => false, 'error' => 'Invalid JSON response from multi-image analysis'];
                }
            }
        }
        
        error_log("Multi-image analysis failed with code: " . $httpCode);
        return ['success' => false, 'error' => 'Multi-image analysis failed'];
        
    } catch (Exception $e) {
        error_log("Error in callGeminiForMultipleImages: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Chuẩn hóa output từ AI để tăng tính nhất quán
 */
function normalizeAIOutput($aiData) {
    // 1. CHUẨN HÓA CATEGORY/TYPE THEO CÁC LOẠI GIÀY PHỔ BIẾN NHẤT
    $typeMapping = [
        // === SNEAKER (Giày thể thao/casual) ===
        'sneaker' => 'Sneaker',
        'sneakers' => 'Sneaker',
        'running shoe' => 'Sneaker',
        'running shoes' => 'Sneaker',
        'giày chạy bộ' => 'Sneaker',
        'giày thể thao' => 'Sneaker',
        'sport shoes' => 'Sneaker',
        'sport shoe' => 'Sneaker',
        'Sport Shoe' => 'Sneaker',
        'Sport Shoes' => 'Sneaker',
        'sports shoes' => 'Giày thể thao chuyên dụng',
        'athletic shoes' => 'Sneaker',
        'athletic shoe' => 'Sneaker',
        'Athletic Shoe' => 'Sneaker',
        'Athletic Shoes' => 'Sneaker',
        'gym shoes' => 'Sneaker',
        'gym shoe' => 'Sneaker',
        'training shoes' => 'Sneaker',
        'training shoe' => 'Sneaker',
        'tennis shoes' => 'Sneaker',
        'tennis shoe' => 'Sneaker',
        'basketball shoes' => 'Sneaker',
        'basketball shoe' => 'Sneaker',
        'casual shoes' => 'Sneaker',
        'casual shoe' => 'Sneaker',
        'lifestyle shoes' => 'Sneaker',
        'lifestyle shoe' => 'Sneaker',
        'walking shoes' => 'Sneaker',
        'walking shoe' => 'Sneaker',
        'giày casual' => 'Sneaker',
        'giày lifestyle' => 'Sneaker',
        'giày tập gym' => 'Sneaker',
        'giày tennis' => 'Sneaker',
        'giày bóng rổ' => 'Sneaker',
        'giày training' => 'Sneaker',
        
        // === GIÀY CAO GÓT ===
        'high heel' => 'Giày cao gót',
        'high heels' => 'Giày cao gót',
        'giày cao gót' => 'Giày cao gót',
        'cao gót' => 'Giày cao gót',
        'heel' => 'Giày cao gót',
        'heels' => 'Giày cao gót',
        'pump' => 'Giày cao gót',
        'pumps' => 'Giày cao gót',
        'stiletto' => 'Giày cao gót',
        'stilettos' => 'Giày cao gót',
        'platform heels' => 'Giày cao gót',
        'platform heel' => 'Giày cao gót',
        'block heel' => 'Giày cao gót',
        'block heels' => 'Giày cao gót',
        'wedge heel' => 'Giày đế xuồng',
        'wedge heels' => 'Giày đế xuồng',
        'wedges' => 'Giày đế xuồng',
        'wedge' => 'Giày đế xuồng',
        'giày đế xuồng' => 'Giày đế xuồng',
        
        // === SANDAL ===
        'sandal' => 'Sandal',
        'sandals' => 'Sandal',
        'xăng đan' => 'Sandal',
        'slide' => 'Sandal',
        'slides' => 'Sandal',
        'flip flop' => 'Sandal',
        'flip flops' => 'Sandal',
        'dép xỏ ngón' => 'Sandal',
        'gladiator sandal' => 'Sandal',
        'gladiator sandals' => 'Sandal',
        'strappy sandal' => 'Sandal',
        'strappy sandals' => 'Sandal',
        'sport sandal' => 'Sandal',
        'sport sandals' => 'Sandal',
        
        // === GIÀY BOOT ===
        'boot' => 'Giày boot',
        'boots' => 'Giày boot',
        'bốt' => 'Giày boot',
        'giày boot' => 'Giày boot',
        'ankle boot' => 'Giày boot',
        'ankle boots' => 'Giày boot',
        'knee boot' => 'Giày boot',
        'knee boots' => 'Giày boot',
        'thigh boot' => 'Giày boot',
        'thigh boots' => 'Giày boot',
        'combat boot' => 'Giày boot',
        'combat boots' => 'Giày boot',
        'chelsea boot' => 'Giày boot',
        'chelsea boots' => 'Giày boot',
        'riding boot' => 'Giày boot',
        'riding boots' => 'Giày boot',
        'work boot' => 'Giày boot',
        'work boots' => 'Giày boot',
        'hiking boot' => 'Giày boot',
        'hiking boots' => 'Giày boot',
        'giày cổ cao' => 'Giày boot',
        'giày bảo hộ' => 'Giày boot',
        
        // === GIÀY TÂY ===
        'oxford' => 'Giày tây',
        'oxfords' => 'Giày tây',
        'oxford shoes' => 'Giày tây',
        'oxford shoe' => 'Giày tây',
        'giày oxford' => 'Giày tây',
        'dress shoe' => 'Giày tây',
        'dress shoes' => 'Giày tây',
        'formal shoe' => 'Giày tây',
        'formal shoes' => 'Giày tây',
        'giày tây' => 'Giày tây',
        'giày công sở' => 'Giày tây',
        'business shoe' => 'Giày tây',
        'business shoes' => 'Giày tây',
        'derby' => 'Giày tây',
        'derbies' => 'Giày tây',
        'brogue' => 'Giày tây',
        'brogues' => 'Giày tây',
        'monk strap' => 'Giày tây',
        'monk straps' => 'Giày tây',
        
        // === GIÀY LƯỜI ===
        'loafer' => 'Giày lười',
        'loafers' => 'Giày lười',
        'giày lười' => 'Giày lười',
        'slip on' => 'Giày lười',
        'slip-on' => 'Giày lười',
        'slip-on shoes' => 'Giày lười',
        'slip-on shoe' => 'Giày lười',
        'slip on shoes' => 'Giày lười',
        'slip ons' => 'Giày lười',
        'slip-ons' => 'Giày lười',
        'moccasin' => 'Giày lười',
        'moccasins' => 'Giày lười',
        'boat shoe' => 'Giày lười',
        'boat shoes' => 'Giày lười',
        'driving shoe' => 'Giày lười',
        'driving shoes' => 'Giày lười',
        'penny loafer' => 'Giày lười',
        'penny loafers' => 'Giày lười',
        'tassel loafer' => 'Giày lười',
        'tassel loafers' => 'Giày lười',
        
        // === GIÀY BỆT ===
        'flat' => 'Giày bệt',
        'flats' => 'Giày bệt',
        'giày bệt' => 'Giày bệt',
        'bệt' => 'Giày bệt',
        'ballet flat' => 'Giày bệt',
        'ballet flats' => 'Giày bệt',
        'giày búp bê' => 'Giày bệt',
        'mary jane' => 'Giày bệt',
        'mary janes' => 'Giày bệt',
        'point toe flat' => 'Giày bệt',
        'pointed flat' => 'Giày bệt',
        'round toe flat' => 'Giày bệt',
        'square toe flat' => 'Giày bệt',
        
        // === GIÀY QUAI HẬU ===
        'slingback' => 'Giày quai hậu',
        'slingbacks' => 'Giày quai hậu',
        'giày quai hậu' => 'Giày quai hậu',
        
        // === GIÀY MULES ===
        'mule' => 'Giày mules',
        'mules' => 'Giày mules',
        'giày mule' => 'Giày mules',
        'backless shoe' => 'Giày mules',
        'backless shoes' => 'Giày mules',
        
        // === GIÀY CÓI ===
        'espadrille' => 'Giày cói',
        'espadrilles' => 'Giày cói',
        'giày cói' => 'Giày cói',
        
        // === GIÀY GỖ ===
        'clog' => 'Giày gỗ',
        'clogs' => 'Giày gỗ',
        'giày gỗ' => 'Giày gỗ',
        
        // === GIÀY THỂ THAO CHUYÊN DỤNG ===
        'football boot' => 'Giày thể thao chuyên dụng',
        'football boots' => 'Giày thể thao chuyên dụng',
        'soccer cleats' => 'Giày thể thao chuyên dụng',
        'soccer cleat' => 'Giày thể thao chuyên dụng',
        'golf shoe' => 'Giày thể thao chuyên dụng',
        'golf shoes' => 'Giày thể thao chuyên dụng',
        'giày đá bóng' => 'Giày thể thao chuyên dụng',
        'giày golf' => 'Giày thể thao chuyên dụng',
        
        // === DÉP ===
        'slipper' => 'Dép',
        'slippers' => 'Dép',
        'house slipper' => 'Dép',
        'house slippers' => 'Dép',
        'dép' => 'Dép',
        'house shoe' => 'Dép',
        'house shoes' => 'Dép',
        'bedroom slipper' => 'Dép',
        'bedroom slippers' => 'Dép'
    ];
    
    if (isset($aiData['category'])) {
        $categoryLower = strtolower(trim($aiData['category']));
        if (isset($typeMapping[$categoryLower])) {
            $aiData['category'] = $typeMapping[$categoryLower];
            $aiData['type'] = $typeMapping[$categoryLower];
        }
    }
    
    if (isset($aiData['type'])) {
        $typeLower = strtolower(trim($aiData['type']));
        if (isset($typeMapping[$typeLower])) {
            $aiData['type'] = $typeMapping[$typeLower];
        }
    }
    
    // LOGIC ƯU TIÊN: Nếu có "Giày quai hậu" nhưng mô tả có "cao gót", chuyển thành "Giày cao gót"
    if (isset($aiData['type']) && $aiData['type'] === 'Giày quai hậu') {
        $description = strtolower($aiData['product_name'] ?? '') . ' ' . strtolower($aiData['description'] ?? '');
        if (strpos($description, 'cao gót') !== false || strpos($description, 'cao got') !== false || 
            strpos($description, 'heel') !== false || strpos($description, 'pump') !== false) {
            $aiData['type'] = 'Giày cao gót';
            $aiData['category'] = 'Giày cao gót';
            error_log("Type priority: 'Giày quai hậu' → 'Giày cao gót' (has heel feature)");
        }
    }
    
    // 2. Chuẩn hóa màu sắc (English -> Vietnamese) - MỞ RỘNG
    $colorMapping = [
        // Màu cơ bản
        'black' => 'Đen',
        'white' => 'Trắng',
        'red' => 'Đỏ',
        'blue' => 'Xanh dương',
        'green' => 'Xanh lá',
        'yellow' => 'Vàng',
        'pink' => 'Hồng',
        'purple' => 'Tím',
        'orange' => 'Cam',
        'brown' => 'Nâu',
        'gray' => 'Xám',
        'grey' => 'Xám',
        
        // Màu nâng cao
        'navy' => 'Xanh navy',
        'navy blue' => 'Xanh navy',
        'beige' => 'Be',
        'gold' => 'Vàng kim',
        'golden' => 'Vàng kim',
        'silver' => 'Bạc',
        'bronze' => 'Đồng',
        'copper' => 'Đồng',
        'burgundy' => 'Đỏ burgundy',
        'wine' => 'Đỏ rượu',
        'maroon' => 'Nâu đỏ',
        'cream' => 'Kem',
        'ivory' => 'Ngà',
        'khaki' => 'Kaki',
        'olive' => 'Xanh ô liu',
        'turquoise' => 'Xanh lam',
        'teal' => 'Xanh mòng két',
        'mint' => 'Xanh bạc hà',
        'coral' => 'San hô',
        'lavender' => 'Tím lavender',
        'tan' => 'Nâu nhạt',
        'taupe' => 'Xám nâu',
        'rose' => 'Hồng',
        'rose gold' => 'Vàng hồng',
        'champagne' => 'Vàng champagne',
        'pearl' => 'Ngọc trai',
        'nude' => 'Da',
        'camel' => 'Nâu camel',
        'chocolate' => 'Nâu chocolate',
        'espresso' => 'Nâu đậm',
        'coffee' => 'Nâu cà phê',
        'charcoal' => 'Xám đen',
        'slate' => 'Xám đá',
        'ash' => 'Xám tro',
        'sky blue' => 'Xanh da trời',
        'royal blue' => 'Xanh hoàng gia',
        'cobalt' => 'Xanh cobalt',
        'indigo' => 'Chàm',
        'violet' => 'Tím',
        'magenta' => 'Đỏ tươi',
        'fuchsia' => 'Đỏ tươi',
        'crimson' => 'Đỏ thẫm',
        'scarlet' => 'Đỏ tươi',
        'rust' => 'Gỉ sắt',
        'terracotta' => 'Đất nung',
        'salmon' => 'Hồng cam',
        'peach' => 'Đào',
        'apricot' => 'Mơ',
        'lime' => 'Xanh chanh',
        'emerald' => 'Xanh ngọc lục bảo',
        'jade' => 'Ngọc',
        'forest green' => 'Xanh rừng',
        'sage' => 'Xanh xám',
        'mustard' => 'Vàng mù tạt',
        'canary' => 'Vàng canary',
        'lemon' => 'Vàng chanh',
        'amber' => 'Hổ phách',
        'honey' => 'Mật ong',
        'blush' => 'Hồng nhạt',
        'mauve' => 'Tím nhạt',
        'plum' => 'Mận',
        'lilac' => 'Tím lilac',
        'periwinkle' => 'Tím nhạt',
        
        // Tổ hợp màu với độ sáng
        'light black' => 'Đen nhạt',
        'dark black' => 'Đen đậm',
        'light white' => 'Trắng',
        'dark white' => 'Trắng kem',
        'light red' => 'Đỏ nhạt',
        'dark red' => 'Đỏ đậm',
        'light blue' => 'Xanh dương nhạt',
        'dark blue' => 'Xanh dương đậm',
        'light green' => 'Xanh lá nhạt',
        'dark green' => 'Xanh lá đậm',
        'light yellow' => 'Vàng nhạt',
        'dark yellow' => 'Vàng đậm',
        'light pink' => 'Hồng nhạt',
        'dark pink' => 'Hồng đậm',
        'light purple' => 'Tím nhạt',
        'dark purple' => 'Tím đậm',
        'light orange' => 'Cam nhạt',
        'dark orange' => 'Cam đậm',
        'light brown' => 'Nâu nhạt',
        'dark brown' => 'Nâu đậm',
        'light gray' => 'Xám nhạt',
        'dark gray' => 'Xám đậm',
        'light grey' => 'Xám nhạt',
        'dark grey' => 'Xám đậm',
        
        // Màu nhiều tông (multicolor)
        'multicolor' => 'Nhiều màu',
        'multi-color' => 'Nhiều màu',
        'multi color' => 'Nhiều màu',
        'colorful' => 'Nhiều màu',
        'rainbow' => 'Nhiều màu',
        'mixed color' => 'Nhiều màu',
        'mixed colors' => 'Nhiều màu',
        'various colors' => 'Nhiều màu',
        'assorted colors' => 'Nhiều màu',
        
        // Màu trong suốt và đặc biệt
        'transparent' => 'Trong suốt',
        'clear' => 'Trong suốt',
        'metallic' => 'Kim loại',
        'glossy' => 'Bóng',
        'matte' => 'Mờ',
        'neon' => 'Huỳnh quang',
        'pastel' => 'Pastel',
        'holographic' => 'Holo',
        'iridescent' => 'Ánh kim',
        'shimmery' => 'Óng ánh',
        'sparkle' => 'Lấp lánh',
        'glitter' => 'Lấp lánh'
    ];
    
    if (isset($aiData['color']) && !empty($aiData['color'])) {
        $originalColor = $aiData['color'];
        
        // BƯỚC 1: Loại bỏ mô tả vị trí trong ngoặc đơn
        // VD: "Đen (lót Trọng)" → "Đen", "Vàng Đồng (khóa)" → "Vàng Đồng"
        $colorString = preg_replace('/\s*\([^)]*\)/u', '', $aiData['color']);
        
        // BƯỚC 2: Chuẩn hóa separators trước để tránh comma dính vào từ
        // VD: "Xanh dương Dương, Trắng" → "Xanh dương Dương | Trắng"
        $separators = [',', ' and ', ' và ', '/', ' & ', '+'];
        foreach ($separators as $sep) {
            $colorString = str_ireplace($sep, '|', $colorString);
        }
        
        // BƯỚC 3: Xử lý từng màu riêng biệt để loại bỏ duplicate
        $colorParts = explode('|', $colorString);
        $processedParts = [];
        
        foreach ($colorParts as $part) {
            $part = trim($part);
            if (empty($part)) continue;
            
            // BƯỚC 3A: Loại bỏ màu lặp lại đơn giản
            // VD: "Đỏ Đỏ Đỏ burgundy" → "Đỏ burgundy"
            $part = preg_replace('/\b(\p{L}+)\s+\1(\s+\1)*\s+/u', '$1 ', $part);
            
            // BƯỚC 3B: Loại bỏ từ lặp lại trong cụm màu phức hợp
            // VD: "Xanh dương Dương Dương" → "Xanh dương", "Trắng Trắng kem" → "Trắng kem"
            $words = preg_split('/\s+/u', trim($part));
            $cleanedWords = [];
            $prevWord = '';
            foreach ($words as $word) {
                $wordLower = mb_strtolower($word, 'UTF-8');
                $prevLower = mb_strtolower($prevWord, 'UTF-8');
                // Skip if current word is same as previous (case-insensitive)
                if ($wordLower !== $prevLower) {
                    $cleanedWords[] = $word;
                }
                $prevWord = $word;
            }
            $processedParts[] = implode(' ', $cleanedWords);
        }
        
        // Rejoin with commas
        $colorString = implode(',', $processedParts);
        
        $colors = explode(',', $colorString);
        $normalizedColors = [];
        
        foreach ($colors as $color) {
            $colorTrimmed = trim($color);
            if (empty($colorTrimmed)) continue;
            
            $colorLower = mb_strtolower($colorTrimmed, 'UTF-8');
            
            // Tìm exact match trước (ưu tiên cụm từ dài trước)
            $found = false;
            
            // Sort keys by length descending để match cụm từ dài trước
            $sortedKeys = array_keys($colorMapping);
            usort($sortedKeys, function($a, $b) {
                return strlen($b) - strlen($a);
            });
            
            foreach ($sortedKeys as $en) {
                // Exact match
                if ($colorLower === $en) {
                    $normalizedColors[] = $colorMapping[$en];
                    $found = true;
                    break;
                }
                // Partial match (chứa từ khóa)
                if (strpos($colorLower, $en) !== false) {
                    $normalizedColors[] = $colorMapping[$en];
                    $found = true;
                    break;
                }
            }
            
            // Nếu không tìm thấy trong mapping
            if (!$found) {
                // Kiểm tra xem có phải tiếng Việt không
                if (preg_match('/[àáạảãâầấậẩẫăằắặẳẵèéẹẻẽêềếệểễìíịỉĩòóọỏõôồốộổỗơờớợởỡùúụủũưừứựửữỳýỵỷỹđ]/u', $colorLower)) {
                    // Giữ nguyên tiếng Việt nhưng capitalize
                    $normalizedColors[] = ucfirst($colorTrimmed);
                } else {
                    // Tiếng Anh không có trong mapping -> giữ nguyên và capitalize
                    $normalizedColors[] = ucwords($colorTrimmed);
                }
            }
        }
        
        // Loại bỏ trùng lặp và join lại
        $normalizedColors = array_unique($normalizedColors);
        $aiData['color'] = implode(', ', $normalizedColors);
        
        error_log("Color normalized: '$originalColor' → '" . $aiData['color'] . "'");
    } else {
        // Nếu không có màu sắc, để trống
        $aiData['color'] = '';
    }
    
    // 3. Chuẩn hóa brand name (capitalize properly)
    if (isset($aiData['brand']) && !empty($aiData['brand'])) {
        $brandLower = strtolower(trim($aiData['brand']));
        
        // CHUẨN HÓA "KHÔNG XÁC ĐỊNH" → "UNKNOWN"
        $unknownBrandVariants = [
            'không xác định',
            'khong xac dinh',
            'không biết',
            'khong biet',
            'chưa xác định',
            'chua xac dinh',
            'unknown',
            'not identified',
            'unidentified',
            'n/a',
            'na',
            'no brand',
            'generic',
            'không có thương hiệu',
            'khong co thuong hieu',
            'unbranded',
            'fashion',  // Thêm "fashion" vào danh sách unknown brands
            ''  // Empty string
        ];
        
        // Kiểm tra xem brand có phải là "không xác định" không
        $isUnknownBrand = false;
        foreach ($unknownBrandVariants as $variant) {
            if ($brandLower === $variant || empty($brandLower)) {
                $isUnknownBrand = true;
                break;
            }
        }
        
        if ($isUnknownBrand) {
            // Tất cả biến thể "không xác định" → "Unknown"
            $aiData['brand'] = 'Unknown';
            error_log("Brand normalized to 'Unknown' from: " . $aiData['brand']);
        } else {
            // Danh sách brand names phổ biến với cách viết chuẩn
            $brandNames = [
                'nike' => 'Nike',
                'adidas' => 'Adidas',
                'puma' => 'Puma',
                'reebok' => 'Reebok',
                'new balance' => 'New Balance',
                'converse' => 'Converse',
                'vans' => 'Vans',
                'asics' => 'ASICS',
                'under armour' => 'Under Armour',
                'mlb' => 'MLB',
                'charles & keith' => 'Charles & Keith',
                'charles keith' => 'Charles & Keith',
                'jeremy' => 'Jeremy',
                'gucci' => 'Gucci',
                'balenciaga' => 'Balenciaga',
                'versace' => 'Versace',
                'lecos' => 'Lecos',
                'boston' => 'Boston'
            ];
            
            if (isset($brandNames[$brandLower])) {
                $aiData['brand'] = $brandNames[$brandLower];
            } else {
                // Capitalize mỗi từ
                $aiData['brand'] = ucwords($aiData['brand']);
            }
        }
    } else {
        // Nếu brand trống hoặc không có → set "Unknown"
        $aiData['brand'] = 'Unknown';
        error_log("Brand was empty, set to 'Unknown'");
    }
    
    // 4. Đảm bảo product_name có đầy đủ thông tin
    if (isset($aiData['product_name']) && isset($aiData['brand'])) {
        // Nếu tên chưa có brand, thêm vào
        if (stripos($aiData['product_name'], $aiData['brand']) === false) {
            $aiData['product_name'] = $aiData['brand'] . ' ' . $aiData['product_name'];
        }
    }
    
    // 5. Log normalization
    error_log("AI Output normalized:");
    error_log("  - Type: " . ($aiData['type'] ?? 'N/A'));
    error_log("  - Category: " . ($aiData['category'] ?? 'N/A'));
    error_log("  - Color: " . ($aiData['color'] ?? 'N/A'));
    error_log("  - Brand: " . ($aiData['brand'] ?? 'N/A'));
    error_log("  - Product name: " . ($aiData['product_name'] ?? 'N/A'));
    
    return $aiData;
}
?>

