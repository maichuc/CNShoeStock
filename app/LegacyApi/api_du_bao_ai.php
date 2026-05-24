<?php
/**
 * API Dự Báo Thông Minh - AI Forecast Analysis
 * Phân tích và dự báo tồn kho thông minh dựa trên:
 * - Khí hậu theo 3 miền Bắc, Trung, Nam
 * - Xu hướng mạng xã hội
 * - Dữ liệu lịch sử nhập xuất tồn
 * - Các ngày lễ và sự kiện
 */

// Tải environment variables
require_once __DIR__ . '/../../env_loader.php';

// Suppress all PHP errors and warnings for clean JSON output
error_reporting(0);
ini_set('display_errors', 0);

// Clean any potential output buffer
if (ob_get_level()) {
    ob_end_clean();
}
ob_start();

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/api_phan_tich_giay_ai.php';  // Import hàm chuẩn hóa loại giày (API đã gộp)

// session_start(); // Đã khởi tạo tại index.php

// Local debug bypass: allow calling the API from localhost or CLI with debug=1
// This sets a temporary session user and warehouse for easy local testing only.
if ((isset($_GET['debug']) && $_GET['debug'] == '1') && (PHP_SAPI === 'cli' || ($_SERVER['REMOTE_ADDR'] ?? '') === '127.0.0.1' || ($_SERVER['REMOTE_ADDR'] ?? '') === '::1')) {
    $_SESSION['user_id'] = $_SESSION['user_id'] ?? 7;  // User có warehouse 6
    $_SESSION['warehouse_id'] = $_SESSION['warehouse_id'] ?? 6;  // Warehouse có dữ liệu thật
    error_log('AI Forecast API - Debug mode enabled, session injected (Warehouse 6 - Real Data)');
}
// Debug information - chỉ log vào file, không echo
error_log('AI Forecast API - Session ID: ' . session_id());
error_log('AI Forecast API - User ID: ' . ($_SESSION['user_id'] ?? 'not set'));
error_log('AI Forecast API - Warehouse ID: ' . ($_SESSION['warehouse_id'] ?? 'not set'));

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    error_log('AI Forecast API - User not logged in');
    // Clean output buffer before JSON
    if (ob_get_level()) ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Vui lòng đăng nhập']);
    exit;
}

$database = new Database();
$conn = $database->getConnection();

// Lấy warehouse_id từ database nếu chưa có trong session
if (!isset($_SESSION['warehouse_id']) || empty($_SESSION['warehouse_id'])) {
    $stmt = $conn->prepare("SELECT warehouse_id FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user && $user['warehouse_id']) {
        $_SESSION['warehouse_id'] = $user['warehouse_id'];
    } else {
        error_log('AI Forecast API - User has no warehouse assigned');
        if (ob_get_level()) ob_end_clean();
        echo json_encode([
            'success' => false, 
            'message' => 'Tài khoản chưa được phân công kho hàng. Vui lòng liên hệ quản trị viên.',
            'no_warehouse' => true
        ]);
        exit;
    }
}
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Nếu đang được include từ file khác (như export), không chạy logic API
if (defined('EXPORT_FORECAST_INCLUDE') && EXPORT_FORECAST_INCLUDE === true) {
    // Chỉ load functions, không chạy switch-case
    error_log('AI Forecast API - Included mode, skipping action handler');
    return; // Dừng execution ở đây
}

try {
    error_log('AI Forecast API - Action: ' . $action);
    
    switch ($action) {
        case 'test':
            echo json_encode([
                'success' => true,
                'message' => 'API đang hoạt động',
                'user_id' => $_SESSION['user_id'] ?? 'not set',
                'warehouse_id' => $_SESSION['warehouse_id'] ?? 'not set',
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            break;
            
        case 'get_regional_forecast':
            getRegionalForecast($conn);
            break;
        
        case 'get_seasonal_analysis':
            getSeasonalAnalysis($conn);
            break;
        
        case 'get_trend_predictions':
            getTrendPredictions($conn);
            break;
        
        case 'get_inventory_insights':
            getInventoryInsights($conn);
            break;
        
        case 'get_abc_analysis':
            getABCAnalysis($conn);
            break;
        
        case 'get_event_forecast':
            getEventForecast($conn);
            break;
        
        case 'get_dashboard_summary':
            getDashboardSummary($conn);
            break;
            
        case 'get_vietnam_climate':
            $month = $_GET['month'] ?? date('n');
            if (ob_get_level()) ob_clean();
            echo json_encode([
                'success' => true,
                'data' => getVietnamClimateAnalysis($month)
            ]);
            break;
            
        case 'get_vietnam_festivals':
            $month = $_GET['month'] ?? date('n');
            if (ob_get_level()) ob_clean();
            echo json_encode([
                'success' => true,
                'data' => getVietnameseFestivalAnalysis($month)
            ]);
            break;
            
        case 'get_social_trends':
            if (ob_get_level()) ob_clean();
            echo json_encode(getSocialTrendsVietnam($conn));
            break;
            
        case 'get_restock_suggestions':
            $warehouse_id = $_SESSION['warehouse_id'];
            $month = $_GET['month'] ?? date('n');
            $region = $_GET['region'] ?? 'north';
            if (ob_get_level()) ob_clean();
            echo json_encode(getRestockSuggestions($conn, $warehouse_id, $month, $region));
            break;
            
        case 'get_low_stock_suggestions':
            $warehouse_id = $_SESSION['warehouse_id'];
            $priority = $_GET['priority'] ?? 'CRITICAL';
            if (ob_get_level()) ob_clean();
            echo json_encode(getLowStockSuggestions($conn, $warehouse_id, $priority));
            break;
        
        default:
            if (ob_get_level()) ob_clean();
            echo json_encode(['success' => false, 'message' => 'Action không hợp lệ: ' . $action]);
    }
} catch (Exception $e) {
    error_log('API Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (ob_get_level()) ob_clean();
    echo json_encode([
        'success' => false,
        'message' => 'Lỗi: ' . $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine()
    ]);
}

/**
 * Dự báo theo vùng miền - Phân tích 3 miền Bắc, Trung, Nam
 */
function getRegionalForecast($conn) {
    try {
        $warehouse_id = $_SESSION['warehouse_id'];
        $region = $_POST['region'] ?? 'all';
        $month = $_POST['month'] ?? date('n');
        
        error_log('AI Forecast - getRegionalForecast called with warehouse_id: ' . $warehouse_id . ', region: ' . $region . ', month: ' . $month);
        
        // Định nghĩa vùng miền Việt Nam
        $regions = [
            'north' => ['name' => 'Miền Bắc', 'provinces' => ['Hà Nội', 'Hải Phòng', 'Quảng Ninh', 'Thái Nguyên']],
            'central' => ['name' => 'Miền Trung', 'provinces' => ['Đà Nẵng', 'Huế', 'Quảng Nam', 'Khánh Hòa']],
            'south' => ['name' => 'Miền Nam', 'provinces' => ['Hồ Chí Minh', 'Cần Thơ', 'Bình Dương', 'Đồng Nai']]
        ];
        
        // Phân tích khí hậu theo tháng và miền
        $climate_data = getClimateByRegionAndMonth($month);
        
        // Lấy dữ liệu bán hàng 12 tháng gần nhất để phân tích xu hướng
        $sql_historical = "SELECT 
                    COALESCE(c.name, 'Chưa phân loại') as category_name,
                    MONTH(o.created_at) as month,
                    YEAR(o.created_at) as year,
                    COALESCE(SUM(od.quantity), 0) as total_sold,
                    COALESCE(SUM(od.total_price), 0) as revenue,
                    COUNT(DISTINCT o.order_id) as order_count
                FROM orders o
                JOIN order_details od ON o.order_id = od.order_id
                JOIN product_variants pv ON od.variant_id = pv.variant_id
                JOIN products p ON pv.product_id = p.product_id
                WHERE o.warehouse_id = :wh_history
                    AND o.status = 'delivered'
                    AND o.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                GROUP BY p.type, YEAR(o.created_at), MONTH(o.created_at)
                ORDER BY year DESC, month DESC, total_sold DESC";
        
        $stmt = $conn->prepare($sql_historical);
        $stmt->execute(['wh_history' => $warehouse_id]);
        $historical_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Lấy dữ liệu tồn kho hiện tại
        $sql_inventory = "SELECT 
                    COALESCE(p.type, 'Chưa phân loại') as category_name,
                    SUM(i.quantity) as current_stock,
                    COUNT(DISTINCT p.product_id) as product_count
                FROM inventory i
                JOIN product_variants pv ON i.variant_id = pv.variant_id
                JOIN products p ON pv.product_id = p.product_id
                WHERE i.warehouse_id = :warehouse_id AND i.quantity > 0
                GROUP BY p.type";
        
        $stmt = $conn->prepare($sql_inventory);
        $stmt->execute(['warehouse_id' => $warehouse_id]);
        $inventory_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // CHỈ SỬ DỤNG DỮ LIỆU THẬT - Không tạo dữ liệu giả
        // Nếu không có dữ liệu lịch sử hoặc tồn kho, trả về empty array
        
        // Phân tích và tạo dự báo nếu có dữ liệu
        $forecast = [];
        if (!empty($historical_data) && !empty($inventory_data)) {
            $forecast = generateIntelligentForecast($historical_data, $inventory_data, $month, $region);
        }
        
        error_log('AI Forecast - Generated ' . count($forecast) . ' forecast items');
        
        echo json_encode([
            'success' => true,
            'data' => [
                'region' => $regions[$region] ?? ['name' => 'Toàn quốc'],
                'month' => $month,
                'climate_info' => $climate_data[$region] ?? $climate_data['general'],
                'forecast' => $forecast,
                'analysis_date' => date('Y-m-d H:i:s'),
                'forecast_confidence' => calculateForecastConfidence($historical_data),
                'market_insights' => generateMarketInsights($month, $region, $forecast)
            ]
        ]);
    } catch (Exception $e) {
        error_log('AI Forecast - getRegionalForecast error: ' . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'Lỗi getRegionalForecast: ' . $e->getMessage()
        ]);
    }
}

/**
 * Phân tích theo mùa - Seasonal Analysis
 */
function getSeasonalAnalysis($conn) {
    $warehouse_id = $_SESSION['warehouse_id'];
    
    // Lấy dữ liệu 12 tháng gần nhất
    $sql = "SELECT 
                MONTH(o.created_at) as month,
                YEAR(o.created_at) as year,
                c.name as category_name,
                SUM(od.quantity) as quantity_sold,
                SUM(od.total_price) as revenue,
                COUNT(DISTINCT o.order_id) as orders
            FROM orders o
            JOIN order_details od ON o.order_id = od.order_id
            JOIN product_variants pv ON od.variant_id = pv.variant_id
            JOIN products p ON pv.product_id = p.product_id
            WHERE o.warehouse_id = :warehouse_id
                AND o.status = 'delivered'
                AND o.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
            GROUP BY YEAR(o.created_at), MONTH(o.created_at), p.type
            ORDER BY year DESC, month DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute(['warehouse_id' => $warehouse_id]);
    $seasonal_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Phân tích xu hướng theo mùa
    $analysis = processSeasonalData($seasonal_data);
    
    echo json_encode([
        'success' => true,
        'data' => $analysis
    ]);
}

/**
 * Dự đoán xu hướng từ mạng xã hội
 */
function getTrendPredictions($conn) {
    // Mô phỏng dữ liệu xu hướng từ mạng xã hội
    // Trong thực tế, đây sẽ là API call đến các social media platforms
    
    $trending_products = [
        [
            'product_name' => 'Giày Sneaker Chunky',
            'trend_score' => 95,
            'social_mentions' => 12500,
            'growth_rate' => 145,
            'platforms' => ['TikTok', 'Instagram', 'Facebook'],
            'target_audience' => 'Gen Z (18-25 tuổi)',
            'recommendation' => 'Xu hướng đang tăng mạnh. Đề xuất nhập 200-300 đôi để thử nghiệm thị trường.',
            'risk_level' => 'low'
        ],
        [
            'product_name' => 'Giày Loafer Da',
            'trend_score' => 78,
            'social_mentions' => 8200,
            'growth_rate' => 32,
            'platforms' => ['Instagram', 'Facebook'],
            'target_audience' => 'Văn phòng (25-40 tuổi)',
            'recommendation' => 'Xu hướng ổn định, phù hợp cho dòng sản phẩm chính.',
            'risk_level' => 'low'
        ],
        [
            'product_name' => 'Giày Búp Bê Nơ',
            'trend_score' => 42,
            'social_mentions' => 3100,
            'growth_rate' => -18,
            'platforms' => ['Facebook'],
            'target_audience' => 'Nữ (20-35 tuổi)',
            'recommendation' => 'Xu hướng đang giảm. Xem xét giảm giá để xả hàng tồn kho.',
            'risk_level' => 'medium'
        ],
        [
            'product_name' => 'Sneaker Chạy Bộ',
            'trend_score' => 88,
            'social_mentions' => 15600,
            'growth_rate' => 67,
            'platforms' => ['TikTok', 'Instagram', 'YouTube'],
            'target_audience' => 'Thể thao (18-45 tuổi)',
            'recommendation' => 'Nhu cầu cao và ổn định. Duy trì lượng tồn kho đầy đủ.',
            'risk_level' => 'low'
        ]
    ];
    
    echo json_encode([
        'success' => true,
        'data' => [
            'trending_products' => $trending_products,
            'analysis_date' => date('Y-m-d H:i:s'),
            'data_source' => 'Social Media Analytics API (Simulated)',
            'next_update' => date('Y-m-d H:i:s', strtotime('+6 hours'))
        ]
    ]);
}

/**
 * Phân tích thông minh về Nhập - Xuất - Tồn
 */
function getInventoryInsights($conn) {
    $warehouse_id = $_SESSION['warehouse_id'];
    
    try {
        // Tốc độ quay vòng hàng tồn kho
        $sql = "SELECT 
                    p.name as product_name,
                    pv.size,
                    pv.color,
                    i.quantity as current_stock,
                    COALESCE(sales.total_sold, 0) as sold_last_30days,
                    COALESCE(sales.days_active, 30) as days_active,
                    COALESCE(sales.total_sold / GREATEST(sales.days_active, 1), 0) as daily_velocity,
                    CASE 
                        WHEN i.quantity > 0 AND COALESCE(sales.total_sold / GREATEST(sales.days_active, 1), 0) > 0 
                        THEN ROUND(i.quantity / (sales.total_sold / GREATEST(sales.days_active, 1)), 1)
                        ELSE NULL
                    END as days_of_stock
                FROM inventory i
                JOIN product_variants pv ON i.variant_id = pv.variant_id
                JOIN products p ON pv.product_id = p.product_id
                LEFT JOIN (
                    SELECT 
                        od.variant_id,
                        SUM(od.quantity) as total_sold,
                        DATEDIFF(NOW(), MIN(o.created_at)) + 1 as days_active
                    FROM orders o
                    JOIN order_details od ON o.order_id = od.order_id
                    WHERE o.warehouse_id = :warehouse_id
                        AND o.status = 'delivered'
                        AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                    GROUP BY od.variant_id
                ) sales ON i.variant_id = sales.variant_id
                WHERE i.warehouse_id = :warehouse_id
                    AND COALESCE(sales.total_sold, 0) > 0
                ORDER BY COALESCE(sales.total_sold, 0) DESC, daily_velocity DESC";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute(['warehouse_id' => $warehouse_id]);
        $inventory_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // CHỈ SỬ DỤNG DỮ LIỆU THẬT - Không tạo dữ liệu mẫu
        
        // Phân loại theo ranking và velocity threshold
        $insights = [
            'fast_moving' => [],
            'normal' => [],
            'slow_moving' => [],
            'summary' => [
                'total_products' => count($inventory_data),
                'fast_moving_count' => 0,
                'normal_count' => 0,
                'slow_moving_count' => 0
            ]
        ];
        
        foreach ($inventory_data as $index => $item) {
            $daily_velocity = floatval($item['daily_velocity']);
            $ranking = $index + 1;
            
            // Phân loại dựa trên: (Top 20 VÀ velocity >= 1) HOẶC velocity >= 5 đôi/ngày
            if (($ranking <= 20 && $daily_velocity >= 1) || $daily_velocity >= 5) {
                $category = 'fast_moving';
                $item['recommendation'] = 'Sản phẩm bán chạy! Cần nhập thêm hàng để tránh hết hàng.';
                $item['action'] = 'reorder';
                $insights['summary']['fast_moving_count']++;
            } elseif ($daily_velocity > 0) {
                $category = 'normal';
                $item['recommendation'] = 'Tốc độ bán hàng bình thường. Duy trì mức tồn kho hiện tại.';
                $item['action'] = 'maintain';
                $insights['summary']['normal_count']++;
            } else {
                $category = 'slow_moving';
                $item['recommendation'] = 'Sản phẩm bán chậm. Xem xét chạy chương trình khuyến mãi.';
                $item['action'] = 'promotion';
                $insights['summary']['slow_moving_count']++;
            }
            
            $item['ranking'] = $ranking;
            $item['movement_category'] = $category;
            $insights[$category][] = $item;
        }
        
        // Lấy dữ liệu ABC thực tế
        $abc_sql = "SELECT 
                        p.product_id,
                        p.name as product_name,
                        pv.variant_id,
                        pv.size,
                        pv.color,
                        SUM(od.total_price) as revenue,
                        SUM(od.quantity) as quantity_sold
                    FROM orders o
                    JOIN order_details od ON o.order_id = od.order_id
                    JOIN product_variants pv ON od.variant_id = pv.variant_id
                    JOIN products p ON pv.product_id = p.product_id
                    WHERE o.status = 'delivered'
                        AND o.created_at >= DATE_SUB(NOW(), INTERVAL 3 MONTH)
                        AND o.warehouse_id = :warehouse_id
                    GROUP BY p.product_id, p.name, pv.variant_id, pv.size, pv.color
                    ORDER BY revenue DESC";
        
        $abc_stmt = $conn->prepare($abc_sql);
        $abc_stmt->execute(['warehouse_id' => $warehouse_id]);
        $abc_products = $abc_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Tính tổng doanh thu
        $total_revenue = array_sum(array_column($abc_products, 'revenue'));
        
        // Phân loại ABC
        $abc_classification = [
            'A' => ['products' => [], 'revenue' => 0, 'percentage' => 0, 'count' => 0],
            'B' => ['products' => [], 'revenue' => 0, 'percentage' => 0, 'count' => 0],
            'C' => ['products' => [], 'revenue' => 0, 'percentage' => 0, 'count' => 0]
        ];
        
        if ($total_revenue > 0) {
            $cumulative_percentage = 0;
            foreach ($abc_products as $product) {
                $product_percentage = ($product['revenue'] / $total_revenue) * 100;
                $cumulative_percentage += $product_percentage;
                
                // Thêm thông tin velocity nếu có trong fast_moving
                foreach ($insights['fast_moving'] as $fast) {
                    if ($fast['product_name'] === $product['product_name'] && 
                        $fast['size'] === $product['size'] && 
                        $fast['color'] === $product['color']) {
                        $product['daily_velocity'] = $fast['daily_velocity'];
                        break;
                    }
                }
                
                // Phân loại: A (0-80%), B (80-95%), C (95-100%)
                if ($cumulative_percentage <= 80) {
                    $abc_classification['A']['products'][] = $product;
                    $abc_classification['A']['revenue'] += $product['revenue'];
                } elseif ($cumulative_percentage <= 95) {
                    $abc_classification['B']['products'][] = $product;
                    $abc_classification['B']['revenue'] += $product['revenue'];
                } else {
                    $abc_classification['C']['products'][] = $product;
                    $abc_classification['C']['revenue'] += $product['revenue'];
                }
            }
            
            // Tính phần trăm và count
            foreach (['A', 'B', 'C'] as $class) {
                $abc_classification[$class]['percentage'] = round(($abc_classification[$class]['revenue'] / $total_revenue) * 100, 1);
                $abc_classification[$class]['count'] = count($abc_classification[$class]['products']);
            }
        }
        
        echo json_encode([
            'success' => true,
            'data' => [
                'fast_moving' => $insights['fast_moving'],
                'normal' => $insights['normal'],
                'slow_moving' => $insights['slow_moving'],
                'summary' => $insights['summary'],
                'abc_classification' => $abc_classification
            ]
        ]);
        
    } catch (Exception $e) {
        error_log('Inventory Insights Error: ' . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'Lỗi phân tích tồn kho: ' . $e->getMessage()
        ]);
    }
}

/**
 * Phân tích ABC
 */
function getABCAnalysis($conn) {
    $warehouse_id = $_SESSION['warehouse_id'];
    
    // Lấy dữ liệu doanh thu 3 tháng gần nhất
    $sql = "SELECT 
                p.product_id,
                p.name as product_name,
                SUM(od.total_price) as revenue,
                SUM(od.quantity) as quantity_sold,
                COUNT(DISTINCT o.order_id) as order_count
            FROM orders o
            JOIN order_details od ON o.order_id = od.order_id
            JOIN product_variants pv ON od.variant_id = pv.variant_id
            JOIN products p ON pv.product_id = p.product_id
            WHERE o.warehouse_id = :warehouse_id
                AND o.status = 'delivered'
                AND o.created_at >= DATE_SUB(NOW(), INTERVAL 3 MONTH)
            GROUP BY p.product_id, p.name
            ORDER BY revenue DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute(['warehouse_id' => $warehouse_id]);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Tính tổng doanh thu
    $total_revenue = array_sum(array_column($products, 'revenue'));
    
    // Phân loại ABC
    $abc_data = [
        'A' => ['products' => [], 'revenue' => 0, 'percentage' => 0],
        'B' => ['products' => [], 'revenue' => 0, 'percentage' => 0],
        'C' => ['products' => [], 'revenue' => 0, 'percentage' => 0]
    ];
    
    $cumulative_percentage = 0;
    foreach ($products as $product) {
        $product_percentage = ($product['revenue'] / $total_revenue) * 100;
        $cumulative_percentage += $product_percentage;
        
        $product['revenue_percentage'] = round($product_percentage, 2);
        $product['cumulative_percentage'] = round($cumulative_percentage, 2);
        
        // Phân loại: A (0-80%), B (80-95%), C (95-100%)
        if ($cumulative_percentage <= 80) {
            $abc_data['A']['products'][] = $product;
            $abc_data['A']['revenue'] += $product['revenue'];
            $product['classification'] = 'A';
        } elseif ($cumulative_percentage <= 95) {
            $abc_data['B']['products'][] = $product;
            $abc_data['B']['revenue'] += $product['revenue'];
            $product['classification'] = 'B';
        } else {
            $abc_data['C']['products'][] = $product;
            $abc_data['C']['revenue'] += $product['revenue'];
            $product['classification'] = 'C';
        }
    }
    
    // Tính phần trăm
    foreach (['A', 'B', 'C'] as $class) {
        $abc_data[$class]['percentage'] = round(($abc_data[$class]['revenue'] / $total_revenue) * 100, 2);
        $abc_data[$class]['count'] = count($abc_data[$class]['products']);
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'abc_classification' => $abc_data,
            'total_revenue' => $total_revenue,
            'total_products' => count($products),
            'analysis_period' => '3 tháng gần nhất'
        ]
    ]);
}

/**
 * Lấy thông tin ngày lễ từ Calendarific API
 */
function getHolidaysFromAPI($year, $country = null) {
    // Lấy cấu hình từ .env
    $apiKey = env('CALENDARIFIC_API_KEY');
    $country = $country ?? env('CALENDARIFIC_COUNTRY', 'VN');
    $cacheDays = (int)env('CALENDARIFIC_CACHE_DAYS', 30);
    
    // Kiểm tra API key
    if (empty($apiKey)) {
        error_log('Calendarific API key not found in .env file');
        return null;
    }
    
    // Kiểm tra cache
    $cacheDir = __DIR__ . "/cache";
    $cacheFile = "{$cacheDir}/holidays_{$year}_{$country}.json";
    
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 86400 * $cacheDays) {
        $cached = file_get_contents($cacheFile);
        return json_decode($cached, true);
    }
    
    // Gọi API
    $url = "https://calendarific.com/api/v2/holidays?api_key={$apiKey}&country={$country}&year={$year}";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode == 200 && $response) {
        $data = json_decode($response, true);
        
        // Lưu cache
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        file_put_contents($cacheFile, $response);
        
        return $data;
    }
    
    error_log("Calendarific API error: HTTP {$httpCode}");
    return null;
}

function getVietnamLunarNewYear($year) {
    // 1. Thử lấy từ API thuc.me (Ưu tiên)
    $apiKey = 'al_n5qzdfzde1ea7hnp1b';
    // Tết thường rơi vào tháng 1 hoặc 2. Ta sẽ tìm ngày 1/1 âm lịch.
    // Tuy nhiên API này thường trả về lịch âm cho 1 ngày dương.
    // Để tìm Tết, ta có thể brute force hoặc dùng dữ liệu có sẵn.
    
    // Sử dụng dữ liệu tham khảo cho các năm gần để nhanh chóng
    $lunarNewYearDates = [
        2024 => '2024-02-10', // Tết Giáp Thìn
        2025 => '2025-01-29', // Tết Ất Tỵ
        2026 => '2026-02-17', // Tết Bính Ngọ
        2027 => '2027-02-06', // Tết Đinh Mùi
        2028 => '2028-01-26', // Tết Mậu Thân
        2029 => '2029-02-13', // Tết Kỷ Dậu
        2030 => '2030-02-03', // Tết Canh Tuất
        2031 => '2031-01-23',
        2032 => '2032-02-11',
        2033 => '2033-01-31',
        2034 => '2034-02-19',
        2035 => '2035-02-08'
    ];
    
    if (isset($lunarNewYearDates[$year])) {
        return $lunarNewYearDates[$year];
    }
    
    // 2. Nếu không có trong danh sách, gọi API để lấy (Dự phòng)
    return null;
}

/**
 * Lấy thông tin lịch âm từ API thuc.me
 */
function getLunarDateFromAPI($day, $month, $year) {
    $apiKey = 'al_n5qzdfzde1ea7hnp1b';
    $url = "https://apiamlich.thuc.me/v1/get-lunar-date?day={$day}&month={$month}&year={$year}&api_key={$apiKey}";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200 && $response) {
        return json_decode($response, true);
    }
    
    return null;
}

/**
 * Mapping sự kiện từ API với hệ thống nội bộ
 */
function mapHolidayToEvent($holiday) {
    $name = $holiday['name'];
    $categories = [];
    $impact = 'medium';
    
    // Mapping tên sự kiện tiếng Anh -> Việt và phân loại
    $eventMapping = [
        'New Year\'s Day' => [
            'name' => 'Tết Dương lịch',
            'impact' => 'high',
            'categories' => ['Giày cao gót', 'Giày boot', 'Sneaker']
        ],
        'Vietnamese New Year\'s Eve' => [
            'name' => 'Giao thừa Tết',
            'impact' => 'very_high',
            'categories' => ['Sneaker', 'Giày cao gót', 'Giày lười', 'Giày tây']
        ],
        'Vietnamese New Year' => [
            'name' => 'Tết Nguyên Đán',
            'impact' => 'very_high',
            'categories' => ['Sneaker', 'Giày cao gót', 'Giày lười', 'Giày tây']
        ],
        'Valentine\'s Day' => [
            'name' => 'Valentine',
            'impact' => 'high',
            'categories' => ['Giày cao gót', 'Giày tây']
        ],
        'International Women\'s Day' => [
            'name' => 'Quốc tế Phụ nữ',
            'impact' => 'very_high',
            'categories' => ['Giày cao gót', 'Sandal']
        ],
        'Reunification Day' => [
            'name' => 'Giải phóng miền Nam',
            'impact' => 'high',
            'categories' => ['Sneaker', 'Sandal']
        ],
        'International Labor Day' => [
            'name' => 'Quốc tế Lao động',
            'impact' => 'high',
            'categories' => ['Sneaker', 'Sandal']
        ],
        'International Children\'s Day' => [
            'name' => 'Quốc tế Thiếu nhi',
            'impact' => 'high',
            'categories' => ['Sneaker', 'Giày vải', 'Dép']
        ],
        'National Day' => [
            'name' => 'Quốc Khánh Việt Nam',
            'impact' => 'high',
            'categories' => ['Sneaker', 'Giày tây']
        ],
        'Christmas Day' => [
            'name' => 'Giáng Sinh',
            'impact' => 'very_high',
            'categories' => ['Giày cao gót', 'Giày boot', 'Giày tây']
        ]
    ];
    
    foreach ($eventMapping as $apiName => $eventData) {
        if (stripos($name, $apiName) !== false || stripos($name, str_replace('\'', '', $apiName)) !== false) {
            return $eventData;
        }
    }
    
    return null;
}

/**
 * Dự báo theo sự kiện và ngày lễ
 */
function getEventForecast($conn) {
    // Nhận tháng từ request, nếu không có thì dùng tháng hiện tại
    $current_month = isset($_GET['month']) ? intval($_GET['month']) : date('n');
    $current_year = date('Y');
    
    // Danh sách sự kiện thương mại và văn hóa quan trọng (không có trong API chính thức)
    $customEvents = [
        ['month' => 2, 'day' => 14, 'name' => 'Valentine', 'impact' => 'high', 'categories' => ['Giày cao gót', 'Giày tây']],
        ['month' => 9, 'day' => 5, 'name' => 'Khai giảng năm học mới', 'impact' => 'very_high', 'categories' => ['Sneaker', 'Giày tây', 'Giày vải']],
        ['month' => 10, 'day' => 20, 'name' => 'Phụ nữ Việt Nam', 'impact' => 'very_high', 'categories' => ['Giày cao gót', 'Sandal']],
        ['month' => 10, 'day' => 31, 'name' => 'Halloween', 'impact' => 'medium', 'categories' => ['Sneaker', 'Giày boot']],
        ['month' => 11, 'day' => 11, 'name' => 'Ngày Độc thân 11/11', 'impact' => 'very_high', 'categories' => ['Tất cả']],
        ['month' => 11, 'day' => 20, 'name' => 'Nhà giáo Việt Nam', 'impact' => 'high', 'categories' => ['Giày cao gót', 'Giày tây']],
        ['month' => 12, 'day' => 31, 'name' => 'Đón năm mới', 'impact' => 'high', 'categories' => ['Giày cao gót', 'Giày boot', 'Sneaker']]
    ];
    
    // Lấy ngày lễ chính thức từ API cho năm hiện tại và năm sau
    $allApiEvents = [];
    $yearsToFetch = array_unique([$current_year, $current_year + 1]);
    
    foreach ($yearsToFetch as $year) {
        $apiData = getHolidaysFromAPI($year);
        if ($apiData && isset($apiData['response']['holidays'])) {
            foreach ($apiData['response']['holidays'] as $holiday) {
                $mapped = mapHolidayToEvent($holiday);
                if ($mapped) {
                    $date = $holiday['date']['iso'];
                    $allApiEvents[] = [
                        'month' => (int)date('n', strtotime($date)),
                        'day' => (int)date('j', strtotime($date)),
                        'year' => (int)date('Y', strtotime($date)),
                        'name' => $mapped['name'],
                        'impact' => $mapped['impact'],
                        'categories' => $mapped['categories'],
                        'date_type' => 'api',
                        'full_date' => $date
                    ];
                }
            }
        }
    }
    
    // Lọc sự kiện cho 3 tháng tới từ tháng được chọn (xuyên năm)
    $upcoming_events = [];
    $has_tet_from_api = false; // Flag để tránh thêm Tết 2 lần
    
    // Kiểm tra xem API có trả về Tết không (check TRƯỚC vòng lặp)
    foreach ($allApiEvents as $apiEvent) {
        if (stripos($apiEvent['name'], 'Tết Nguyên Đán') !== false) {
            $has_tet_from_api = true;
            break;
        }
    }
    
    for ($i = 0; $i < 3; $i++) {
        $target_month = $current_month + $i;
        $target_year = $current_year;
        
        // Xử lý tháng vượt quá 12 (sang năm mới)
        if ($target_month > 12) {
            $target_month = $target_month - 12;
            $target_year = $current_year + 1;
        }
        
        // 1. Thêm sự kiện từ API
        foreach ($allApiEvents as $apiEvent) {
            if ($apiEvent['month'] == $target_month && $apiEvent['year'] == $target_year) {
                $apiEvent['forecast_month'] = $target_month;
                $apiEvent['forecast_year'] = $target_year;
                $apiEvent['forecast_date'] = date('d/m/Y', strtotime($apiEvent['full_date']));
                
                $impact_text = [
                    'very_high' => 'rất cao',
                    'high' => 'cao',
                    'medium' => 'trung bình'
                ];
                $categories = implode(', ', $apiEvent['categories']);
                $impact = $impact_text[$apiEvent['impact']];
                $apiEvent['recommendation'] = "Sự kiện '{$apiEvent['name']}' ({$apiEvent['forecast_date']}) có mức độ ảnh hưởng {$impact}. Đề xuất tăng cường tồn kho cho các dòng: {$categories}. Nên chuẩn bị trước 2-3 tuần.";
                
                $upcoming_events[] = $apiEvent;
            }
        }
        
        // 2. Xử lý Tết Nguyên Đán (lịch âm) - CHỈ KHI API KHÔNG CÓ
        if (!$has_tet_from_api) {
            $lunarNewYearDate = getVietnamLunarNewYear($target_year);
            if ($lunarNewYearDate) {
                $lunarMonth = (int)date('n', strtotime($lunarNewYearDate));
                $lunarDay = (int)date('j', strtotime($lunarNewYearDate));
                
                if ($lunarMonth == $target_month) {
                    $tetEvent = [
                        'month' => $lunarMonth,
                        'day' => $lunarDay,
                        'name' => 'Tết Nguyên Đán',
                        'impact' => 'very_high',
                        'categories' => ['Sneaker', 'Giày cao gót', 'Giày lười', 'Giày tây'],
                        'date_type' => 'lunar',
                        'forecast_month' => $target_month,
                        'forecast_year' => $target_year,
                        'forecast_date' => date('d/m/Y', strtotime($lunarNewYearDate))
                    ];
                    
                    $impact_text = [
                        'very_high' => 'rất cao',
                        'high' => 'cao',
                        'medium' => 'trung bình'
                    ];
                    $categories = implode(', ', $tetEvent['categories']);
                    $tetEvent['recommendation'] = "Sự kiện '{$tetEvent['name']}' ({$tetEvent['forecast_date']}) có mức độ ảnh hưởng {$impact_text[$tetEvent['impact']]}. Đề xuất tăng cường tồn kho cho các dòng: {$categories}. Nên chuẩn bị trước 3-4 tuần.";
                    
                    $upcoming_events[] = $tetEvent;
                }
            }
        }
        
        // 3. Xử lý các sự kiện tùy chỉnh (không có trong API chính thức)
        foreach ($customEvents as $event) {
            if ($event['month'] == $target_month) {
                $event['forecast_month'] = $target_month;
                $event['forecast_year'] = $target_year;
                $event['forecast_date'] = sprintf('%02d/%02d/%d', $event['day'], $target_month, $target_year);
                
                $impact_text = [
                    'very_high' => 'rất cao',
                    'high' => 'cao',
                    'medium' => 'trung bình'
                ];
                $categories = implode(', ', $event['categories']);
                $impact = $impact_text[$event['impact']];
                $event['recommendation'] = "Sự kiện '{$event['name']}' ({$event['forecast_date']}) có mức độ ảnh hưởng {$impact}. Đề xuất tăng cường tồn kho cho các dòng: {$categories}. Nên chuẩn bị trước 2-3 tuần.";
                
                $upcoming_events[] = $event;
            }
        }
    }
    
    // LOẠI BỎ SỰ KIỆN TRÙNG LẶP (theo tên và ngày)
    $unique_events = [];
    $seen_keys = [];
    
    foreach ($upcoming_events as $event) {
        // Tạo key duy nhất dựa trên tên và ngày
        $key = strtolower(trim($event['name'])) . '_' . $event['forecast_date'];
        
        if (!isset($seen_keys[$key])) {
            $unique_events[] = $event;
            $seen_keys[$key] = true;
        } else {
            error_log("🔄 Loại bỏ sự kiện trùng lặp: {$event['name']} ({$event['forecast_date']})");
        }
    }
    
    // Sắp xếp theo ngày
    usort($unique_events, function($a, $b) {
        $dateA = strtotime(str_replace('/', '-', $a['forecast_date']));
        $dateB = strtotime(str_replace('/', '-', $b['forecast_date']));
        return $dateA - $dateB;
    });
    
    if (ob_get_level()) ob_clean();
    echo json_encode([
        'success' => true,
        'data' => [
            'upcoming_events' => $unique_events,
            'current_month' => $current_month,
            'forecast_period' => '3 tháng tới'
        ]
    ]);
}

/**
 * Tạo dữ liệu mẫu thông minh dựa trên thời gian và vùng miền
 */
function generateSmartSampleData($month, $region) {
    $base_data = [
        'Sneaker' => ['base_demand' => 120, 'seasonal_factor' => getSportsShoesSeasonality($month)],
        'Giày cao gót' => ['base_demand' => 75, 'seasonal_factor' => getHeelsSeasonality($month)],
        'Sandal' => ['base_demand' => 90, 'seasonal_factor' => getSandalSeasonality($month)],
        'Giày boot' => ['base_demand' => 50, 'seasonal_factor' => getLeatherShoesSeasonality($month)],
        'Giày lười' => ['base_demand' => 40, 'seasonal_factor' => getFlatsSeasonality($month)]
    ];
    
    $sample_data = [];
    foreach ($base_data as $category => $data) {
        // Điều chỉnh theo vùng miền
        $regional_factor = getRegionalDemandFactor($category, $region);
        
        for ($i = 11; $i >= 0; $i--) {
            $target_month = ($month - $i) <= 0 ? ($month - $i + 12) : ($month - $i);
            $seasonal_adj = $data['seasonal_factor'][$target_month] ?? 1.0;
            $monthly_demand = round($data['base_demand'] * $seasonal_adj * $regional_factor * (0.8 + rand(0, 40)/100));
            
            $sample_data[] = [
                'category_name' => $category,
                'month' => $target_month,
                'year' => date('Y'),
                'total_sold' => $monthly_demand,
                'revenue' => $monthly_demand * rand(800000, 1500000),
                'order_count' => round($monthly_demand / rand(2, 4))
            ];
        }
    }
    
    return $sample_data;
}

/**
 * Tạo dự báo thông minh dựa trên nhiều yếu tố
 */
function generateIntelligentForecast($historical_data, $inventory_data, $target_month, $region) {
    $forecast = [];
    $categories_analyzed = [];
    
    // Phân tích từng danh mục
    foreach ($inventory_data as $inventory_item) {
        $category = $inventory_item['category_name'];
        if (in_array($category, $categories_analyzed)) continue;
        
        $categories_analyzed[] = $category;
        
        // Tính xu hướng lịch sử
        $historical_trend = analyzeHistoricalTrend($historical_data, $category, $target_month);
        
        // Yếu tố khí hậu
        $climate_factor = getAdvancedClimateFactor($category, $target_month, $region);
        
        // Yếu tố thị trường
        $market_factor = getMarketFactor($category, $target_month);
        
        // Yếu tố tồn kho
        $inventory_factor = getInventoryFactor($inventory_item, $historical_trend['avg_monthly_sales']);
        
        // Tính dự báo cuối cùng
        $base_demand = $historical_trend['avg_monthly_sales'];
        $predicted_demand = round($base_demand * $climate_factor * $market_factor * $inventory_factor);
        
        $change_percent = round((($predicted_demand - $base_demand) / max($base_demand, 1)) * 100, 1);
        
        // Tính độ tin cậy
        $confidence = calculatePredictionConfidence($historical_trend, $climate_factor, $market_factor);
        
        $forecast[] = [
            'category' => $category,
            'current_month_sales' => $base_demand,
            'predicted_demand' => max($predicted_demand, 0),
            'change_percent' => $change_percent,
            'trend' => $change_percent > 5 ? 'increase' : ($change_percent < -5 ? 'decrease' : 'stable'),
            'confidence' => $confidence,
            'current_stock' => $inventory_item['current_stock'] ?? 0,
            'stock_status' => getStockStatus($inventory_item['current_stock'] ?? 0, $predicted_demand),
            'factors' => [
                'climate' => round($climate_factor, 3),
                'market' => round($market_factor, 3),
                'inventory' => round($inventory_factor, 3),
                'historical' => $historical_trend['trend_direction']
            ],
            'recommendation' => generateAdvancedRecommendation($category, $change_percent, $target_month, $inventory_item, $confidence),
            'risk_level' => assessRiskLevel($change_percent, $confidence, $inventory_item['current_stock'] ?? 0, $predicted_demand)
        ];
    }
    
    return $forecast;
}

/**
 * Phân tích xu hướng lịch sử
 */
function analyzeHistoricalTrend($historical_data, $category, $target_month) {
    $category_data = array_filter($historical_data, function($item) use ($category) {
        return $item['category_name'] === $category;
    });
    
    if (empty($category_data)) {
        return [
            'avg_monthly_sales' => 50,
            'trend_direction' => 'stable',
            'seasonality' => 1.0,
            'volatility' => 'low'
        ];
    }
    
    $monthly_sales = [];
    $same_month_sales = [];
    
    foreach ($category_data as $item) {
        $monthly_sales[] = $item['total_sold'];
        if ($item['month'] == $target_month) {
            $same_month_sales[] = $item['total_sold'];
        }
    }
    
    $avg_monthly = array_sum($monthly_sales) / count($monthly_sales);
    $avg_same_month = empty($same_month_sales) ? $avg_monthly : (array_sum($same_month_sales) / count($same_month_sales));
    
    // Tính xu hướng (3 tháng gần nhất so với 3 tháng trước đó)
    $recent_sales = array_slice($monthly_sales, 0, 3);
    $older_sales = array_slice($monthly_sales, -3, 3);
    
    $recent_avg = array_sum($recent_sales) / max(count($recent_sales), 1);
    $older_avg = array_sum($older_sales) / max(count($older_sales), 1);
    
    $trend_direction = 'stable';
    if ($recent_avg > $older_avg * 1.1) $trend_direction = 'increasing';
    elseif ($recent_avg < $older_avg * 0.9) $trend_direction = 'decreasing';
    
    return [
        'avg_monthly_sales' => round($avg_same_month),
        'trend_direction' => $trend_direction,
        'seasonality' => $avg_same_month / max($avg_monthly, 1),
        'volatility' => calculateVolatility($monthly_sales)
    ];
}

/**
 * Yếu tố khí hậu nâng cao
 */
function getAdvancedClimateFactor($category, $month, $region) {
    $base_factor = getClimateFactor($category, $month, $region);
    
    // Điều chỉnh theo xu hướng biến đổi khí hậu
    $climate_trends = [
        'north' => ['hot_months' => [6,7,8], 'cold_months' => [12,1,2], 'intensity' => 1.1],
        'central' => ['hot_months' => [5,6,7,8,9], 'cold_months' => [12,1,2], 'intensity' => 1.2],
        'south' => ['hot_months' => [3,4,5], 'cold_months' => [], 'intensity' => 1.0]
    ];
    
    $region_trend = $climate_trends[$region] ?? $climate_trends['south'];
    
    if (in_array($month, $region_trend['hot_months'])) {
        if (in_array($category, ['Sandal', 'Sneaker'])) {
            $base_factor *= (1.0 + 0.1 * $region_trend['intensity']);
        }
    }
    
    return $base_factor;
}

/**
 * Yếu tố thị trường
 */
function getMarketFactor($category, $month) {
    $market_events = [
        1 => ['factor' => 1.5, 'event' => 'Tết Nguyên Đán'],
        2 => ['factor' => 0.8, 'event' => 'Sau Tết'],
        3 => ['factor' => 1.1, 'event' => 'Xuân - mùa cưới'],
        4 => ['factor' => 1.2, 'event' => 'Lễ 30/4'],
        5 => ['factor' => 1.0, 'event' => 'Bình thường'],
        6 => ['factor' => 1.3, 'event' => 'Hè - du lịch'],
        7 => ['factor' => 1.4, 'event' => 'Cao điểm hè'],
        8 => ['factor' => 1.2, 'event' => 'Hè'],
        9 => ['factor' => 1.1, 'event' => 'Tựu trường'],
        10 => ['factor' => 1.0, 'event' => 'Thu'],
        11 => ['factor' => 1.4, 'event' => 'Black Friday'],
        12 => ['factor' => 1.3, 'event' => 'Giáng Sinh - Năm mới']
    ];
    
    $base_factor = $market_events[$month]['factor'] ?? 1.0;
    
    // Điều chỉnh theo danh mục
    $category_adjustments = [
        'Giày cao gót' => [1 => 1.8, 3 => 1.5, 12 => 1.6], // Tết, cưới, party
        'Sandal' => [6 => 1.8, 7 => 2.0, 8 => 1.7], // Hè
        'Sneaker' => [4 => 1.3, 6 => 1.4, 9 => 1.3], // Du lịch, thể thao
        'Giày boot' => [1 => 1.6, 11 => 1.5, 12 => 1.5] // Formal events
    ];
    
    if (isset($category_adjustments[$category][$month])) {
        $base_factor *= $category_adjustments[$category][$month];
    }
    
    return $base_factor;
}

/**
 * Các hàm hỗ trợ mùa vụ cho từng loại giày
 */
function getSportsShoesSeasonality($month) {
    // Sneaker: cao vào hè và tháng tựu trường
    $seasonality = [
        1 => 0.9, 2 => 0.8, 3 => 1.0, 4 => 1.2, 5 => 1.3, 6 => 1.5,
        7 => 1.6, 8 => 1.4, 9 => 1.3, 10 => 1.1, 11 => 1.0, 12 => 0.9
    ];
    return $seasonality;
}

function getHeelsSeasonality($month) {
    // Giày cao gót: cao vào dịp lễ, cưới hỏi, party
    $seasonality = [
        1 => 1.5, 2 => 0.8, 3 => 1.4, 4 => 1.2, 5 => 1.1, 6 => 1.0,
        7 => 1.0, 8 => 1.0, 9 => 1.1, 10 => 1.2, 11 => 1.3, 12 => 1.6
    ];
    return $seasonality;
}

function getSandalSeasonality($month) {
    // Sandal: cao nhất vào mùa hè
    $seasonality = [
        1 => 0.5, 2 => 0.6, 3 => 1.0, 4 => 1.3, 5 => 1.6, 6 => 1.8,
        7 => 2.0, 8 => 1.9, 9 => 1.4, 10 => 1.0, 11 => 0.7, 12 => 0.5
    ];
    return $seasonality;
}

function getLeatherShoesSeasonality($month) {
    // Giày da: cao vào mùa đông và dịp trang trọng
    $seasonality = [
        1 => 1.4, 2 => 1.2, 3 => 1.3, 4 => 1.1, 5 => 0.9, 6 => 0.8,
        7 => 0.7, 8 => 0.8, 9 => 1.0, 10 => 1.2, 11 => 1.3, 12 => 1.5
    ];
    return $seasonality;
}

function getFlatsSeasonality($month) {
    // Giày búp bê: ổn định quanh năm, tăng nhẹ mùa xuân thu
    $seasonality = [
        1 => 1.1, 2 => 1.0, 3 => 1.2, 4 => 1.2, 5 => 1.1, 6 => 1.0,
        7 => 0.9, 8 => 0.9, 9 => 1.1, 10 => 1.2, 11 => 1.1, 12 => 1.1
    ];
    return $seasonality;
}

/**
 * Yếu tố nhu cầu theo vùng miền
 */
function getRegionalDemandFactor($category, $region) {
    $regional_factors = [
        'north' => [
            'Giày boot' => 1.3, // Miền Bắc thích formal hơn
            'Giày cao gót' => 1.2,
            'Sneaker' => 1.0,
            'Sandal' => 0.8,
            'Giày lười' => 1.1
        ],
        'central' => [
            'Sandal' => 1.4, // Miền Trung nóng
            'Sneaker' => 1.1,
            'Giày cao gót' => 1.0,
            'Giày boot' => 0.9,
            'Giày lười' => 1.0
        ],
        'south' => [
            'Sandal' => 1.5, // Miền Nam nóng nhất
            'Sneaker' => 1.2,
            'Giày cao gót' => 1.1,
            'Giày boot' => 0.8,
            'Giày lười' => 1.0
        ]
    ];
    
    return $regional_factors[$region][$category] ?? 1.0;
}

/**
 * Yếu tố tồn kho
 */
function getInventoryFactor($inventory_item, $avg_sales) {
    $current_stock = $inventory_item['current_stock'] ?? 0;
    
    if ($avg_sales <= 0) return 1.0;
    
    $stock_ratio = $current_stock / $avg_sales;
    
    // Nếu tồn kho quá nhiều (>3 tháng) -> giảm dự báo
    if ($stock_ratio > 3) return 0.7;
    // Nếu tồn kho ít (<0.5 tháng) -> tăng dự báo
    if ($stock_ratio < 0.5) return 1.3;
    // Nếu tồn kho thấp (<1 tháng) -> tăng nhẹ
    if ($stock_ratio < 1) return 1.1;
    
    return 1.0;
}

/**
 * Tính độ tin cậy dự đoán
 */
function calculatePredictionConfidence($historical_trend, $climate_factor, $market_factor) {
    $base_confidence = 75;
    
    // Giảm confidence nếu volatility cao
    if ($historical_trend['volatility'] === 'high') $base_confidence -= 20;
    elseif ($historical_trend['volatility'] === 'medium') $base_confidence -= 10;
    
    // Tăng confidence nếu có xu hướng rõ ràng
    if ($historical_trend['trend_direction'] !== 'stable') $base_confidence += 10;
    
    // Điều chỉnh theo yếu tố khí hậu và thị trường
    $factor_stability = abs($climate_factor - 1) + abs($market_factor - 1);
    if ($factor_stability < 0.2) $base_confidence += 5;
    if ($factor_stability > 0.5) $base_confidence -= 10;
    
    return max(30, min(95, $base_confidence));
}

/**
 * Trạng thái tồn kho
 */
function getStockStatus($current_stock, $predicted_demand) {
    if ($predicted_demand <= 0) return 'unknown';
    
    $stock_ratio = $current_stock / $predicted_demand;
    
    if ($stock_ratio < 0.5) return 'low';
    if ($stock_ratio < 1.0) return 'adequate';
    if ($stock_ratio < 2.0) return 'good';
    return 'excess';
}

/**
 * Tạo khuyến nghị nâng cao
 */
function generateAdvancedRecommendation($category, $change_percent, $month, $inventory_item, $confidence) {
    $current_stock = $inventory_item['current_stock'] ?? 0;
    $recommendations = [];
    
    // Khuyến nghị dựa trên xu hướng
    if ($change_percent > 20 && $confidence > 70) {
        $recommendations[] = "Tăng đặt hàng {$category} ngay lập tức do nhu cầu dự báo tăng mạnh";
        if ($current_stock < 50) {
            $recommendations[] = "KHẨN CẤP: Tồn kho thấp, cần nhập hàng trong 1-2 tuần";
        }
    } elseif ($change_percent > 10) {
        $recommendations[] = "Cân nhắc tăng nhẹ đơn hàng {$category}";
    } elseif ($change_percent < -20 && $confidence > 70) {
        $recommendations[] = "Giảm đặt hàng {$category} và xem xét khuyến mãi";
        if ($current_stock > 100) {
            $recommendations[] = "Tồn kho cao, nên có chương trình giảm giá";
        }
    }
    
    // Khuyến nghị theo mùa
    $seasonal_advice = getSeasonalAdvice($category, $month);
    if ($seasonal_advice) {
        $recommendations[] = $seasonal_advice;
    }
    
    // Khuyến nghị theo độ tin cậy
    if ($confidence < 60) {
        $recommendations[] = "Độ tin cậy thấp - cần theo dõi thêm dữ liệu thực tế";
    }
    
    return empty($recommendations) ? "Duy trì kế hoạch hiện tại" : implode(". ", $recommendations);
}

/**
 * Khuyến nghị theo mùa
 */
function getSeasonalAdvice($category, $month) {
    $advice = [
        'Sandal' => [
            3 => "Chuẩn bị cho mùa hè, tăng đặt hàng sandal",
            6 => "Cao điểm hè, đảm bảo đủ hàng sandal",
            10 => "Giảm đặt hàng sandal, chuẩn bị thanh lý"
        ],
        'Giày cao gót' => [
            1 => "Tết Nguyên Đán - tăng đặt hàng giày cao gót",
            12 => "Mùa tiệc cuối năm - đảm bảo đủ giày cao gót"
        ],
        'Giày da' => [
            12 => "Mùa đông - tăng đặt hàng giày da",
            1 => "Dịp Tết - chuẩn bị giày da formal"
        ]
    ];
    
    return $advice[$category][$month] ?? null;
}

/**
 * Đánh giá mức độ rủi ro
 */
function assessRiskLevel($change_percent, $confidence, $current_stock, $predicted_demand) {
    $risk_score = 0;
    
    // Rủi ro từ biến động nhu cầu
    if (abs($change_percent) > 30) $risk_score += 3;
    elseif (abs($change_percent) > 15) $risk_score += 2;
    elseif (abs($change_percent) > 5) $risk_score += 1;
    
    // Rủi ro từ độ tin cậy thấp
    if ($confidence < 50) $risk_score += 3;
    elseif ($confidence < 70) $risk_score += 2;
    elseif ($confidence < 80) $risk_score += 1;
    
    // Rủi ro từ tồn kho
    if ($predicted_demand > 0) {
        $stock_ratio = $current_stock / $predicted_demand;
        if ($stock_ratio < 0.3 || $stock_ratio > 3) $risk_score += 2;
        elseif ($stock_ratio < 0.5 || $stock_ratio > 2) $risk_score += 1;
    }
    
    if ($risk_score >= 6) return 'high';
    if ($risk_score >= 3) return 'medium';
    return 'low';
}

/**
 * Tính độ biến động
 */
function calculateVolatility($sales_data) {
    if (count($sales_data) < 3) return 'low';
    
    $mean = array_sum($sales_data) / count($sales_data);
    $variance = 0;
    
    foreach ($sales_data as $value) {
        $variance += pow($value - $mean, 2);
    }
    
    $std_dev = sqrt($variance / count($sales_data));
    $coefficient_variation = $mean > 0 ? ($std_dev / $mean) : 0;
    
    if ($coefficient_variation > 0.5) return 'high';
    if ($coefficient_variation > 0.3) return 'medium';
    return 'low';
}

/**
 * Tính độ tin cậy tổng thể của dự báo
 */
function calculateForecastConfidence($historical_data) {
    if (empty($historical_data)) return 50;
    
    $confidence = 70; // Base confidence
    
    // Tăng confidence nếu có đủ dữ liệu lịch sử
    if (count($historical_data) >= 12) $confidence += 10;
    elseif (count($historical_data) >= 6) $confidence += 5;
    
    // Tính độ ổn định của dữ liệu
    $monthly_sales = array_column($historical_data, 'total_sold');
    if (count($monthly_sales) >= 3) {
        $volatility = calculateVolatility($monthly_sales);
        if ($volatility === 'low') $confidence += 10;
        elseif ($volatility === 'high') $confidence -= 15;
    }
    
    // Kiểm tra tính nhất quán theo mùa
    $seasonal_consistency = analyzeSeasonalConsistency($historical_data);
    if ($seasonal_consistency > 0.7) $confidence += 5;
    
    return max(30, min(95, $confidence));
}

/**
 * Phân tích tính nhất quán theo mùa
 */
function analyzeSeasonalConsistency($historical_data) {
    $monthly_patterns = [];
    
    foreach ($historical_data as $record) {
        $month = $record['month'];
        if (!isset($monthly_patterns[$month])) {
            $monthly_patterns[$month] = [];
        }
        $monthly_patterns[$month][] = $record['total_sold'];
    }
    
    $consistency_scores = [];
    foreach ($monthly_patterns as $month => $sales) {
        if (count($sales) > 1) {
            $avg = array_sum($sales) / count($sales);
            $variance = 0;
            foreach ($sales as $sale) {
                $variance += pow($sale - $avg, 2);
            }
            $std_dev = sqrt($variance / count($sales));
            $cv = $avg > 0 ? ($std_dev / $avg) : 1;
            $consistency_scores[] = max(0, 1 - $cv); // Lower coefficient of variation = higher consistency
        }
    }
    
    return empty($consistency_scores) ? 0.5 : (array_sum($consistency_scores) / count($consistency_scores));
}

/**
 * Tạo thông tin chi tiết thị trường
 */
function generateMarketInsights($month, $region, $forecast_data) {
    $insights = [];
    
    // Phân tích xu hướng chung
    $total_increase = 0;
    $total_decrease = 0;
    $high_growth_categories = [];
    $declining_categories = [];
    
    foreach ($forecast_data as $item) {
        if ($item['change_percent'] > 15) {
            $total_increase += $item['change_percent'];
            $high_growth_categories[] = $item['category'];
        } elseif ($item['change_percent'] < -15) {
            $total_decrease += abs($item['change_percent']);
            $declining_categories[] = $item['category'];
        }
    }
    
    // Thông tin tổng quan thị trường
    $market_condition = 'ổn định';
    if ($total_increase > $total_decrease * 1.5) {
        $market_condition = 'tăng trưởng mạnh';
    } elseif ($total_decrease > $total_increase * 1.5) {
        $market_condition = 'suy giảm';
    }
    
    $insights['market_condition'] = $market_condition;
    
    // Dự báo theo vùng miền
    $regional_insights = getRegionalMarketInsights($region, $month);
    $insights['regional_factors'] = $regional_insights;
    
    // Danh mục tiềm năng cao
    if (!empty($high_growth_categories)) {
        $insights['high_potential'] = [
            'categories' => $high_growth_categories,
            'recommendation' => 'Tập trung tăng đầu tư và marketing cho các danh mục này'
        ];
    }
    
    // Danh mục cần chú ý
    if (!empty($declining_categories)) {
        $insights['attention_needed'] = [
            'categories' => $declining_categories,
            'recommendation' => 'Cần chiến lược khuyến mãi hoặc điều chỉnh sản phẩm'
        ];
    }
    
    // Cơ hội thị trường theo mùa
    $seasonal_opportunities = getSeasonalOpportunities($month);
    $insights['seasonal_opportunities'] = $seasonal_opportunities;
    
    // Rủi ro và thách thức
    $insights['risks'] = identifyMarketRisks($month, $region, $forecast_data);
    
    // Khuyến nghị chiến lược
    $insights['strategic_recommendations'] = generateStrategicRecommendations($market_condition, $month, $region);
    
    return $insights;
}

/**
 * Thông tin thị trường theo vùng miền
 */
function getRegionalMarketInsights($region, $month) {
    $insights = [
        'north' => [
            'climate' => 'Mùa đông lạnh, ảnh hưởng đến nhu cầu giày đóng',
            'culture' => 'Thích sự trang trọng, giày da và cao gót có nhu cầu cao',
            'economy' => 'Thu nhập cao, sẵn sàng chi cho sản phẩm chất lượng'
        ],
        'central' => [
            'climate' => 'Khí hậu khắc nghiệt, nhu cầu giày bền và thoáng khí',
            'culture' => 'Thực dụng, ưa chuộng sản phẩm bền và giá hợp lý',
            'economy' => 'Thu nhập trung bình, nhạy cảm với giá'
        ],
        'south' => [
            'climate' => 'Nóng ẩm quanh năm, sandal và giày thoáng có ưu thế',
            'culture' => 'Năng động, thời trang, thích đổi mới',
            'economy' => 'Đa dạng, từ cao cấp đến bình dân'
        ]
    ];
    
    $base_insight = $insights[$region] ?? $insights['south'];
    
    // Điều chỉnh theo tháng
    $monthly_adjustments = [
        1 => 'Tết Nguyên Đán - nhu cầu giày formal tăng cao',
        6 => 'Đầu mùa hè - sandal và giày thoáng bắt đầu hot',
        11 => 'Black Friday - cơ hội tăng doanh số',
        12 => 'Cuối năm - tiệc tùng nhiều, giày cao gót và da tăng'
    ];
    
    if (isset($monthly_adjustments[$month])) {
        $base_insight['monthly_factor'] = $monthly_adjustments[$month];
    }
    
    return $base_insight;
}

/**
 * Cơ hội theo mùa
 */
function getSeasonalOpportunities($month) {
    $opportunities = [
        1 => 'Tết Nguyên Đán - khuyến mãi giày formal, đặc biệt giày boot và cao gót',
        2 => 'Sau Tết - thanh lý hàng tồn, chuẩn bị hàng mùa xuân',
        3 => 'Mùa cưới - tăng marketing giày cưới và formal',
        4 => 'Du lịch mùa xuân - quảng bá sneaker và sandal',
        5 => 'Chuẩn bị mùa hè - stock sandal và giày thoáng',
        6 => 'Đầu hè - launch collection sandal mới',
        7 => 'Cao điểm hè - tối ưu hóa sandal và sneaker',
        8 => 'Hè muộn - chuẩn bị back-to-school',
        9 => 'Tựu trường - marketing sneaker và giày tây',
        10 => 'Thu - transition collection, giày đa năng',
        11 => 'Black Friday - khuyến mãi lớn, clear stock',
        12 => 'Cuối năm - tiệc tùng, giày formal và party'
    ];
    
    return $opportunities[$month] ?? 'Theo dõi xu hướng thị trường';
}

/**
 * Nhận diện rủi ro thị trường
 */
function identifyMarketRisks($month, $region, $forecast_data) {
    $risks = [];
    
    // Rủi ro thời tiết
    if ($month >= 6 && $month <= 8 && $region === 'central') {
        $risks[] = 'Mùa mưa bão có thể ảnh hưởng logistics và nhu cầu';
    }
    
    // Rủi ro kinh tế theo mùa
    if ($month === 2) {
        $risks[] = 'Sau Tết - sức mua giảm, cần chuẩn bị khuyến mãi';
    }
    
    // Rủi ro từ dự báo
    foreach ($forecast_data as $item) {
        if ($item['risk_level'] === 'high') {
            $risks[] = "Rủi ro cao cho {$item['category']} - cần theo dõi sát";
        }
        if ($item['confidence'] < 60) {
            $risks[] = "Độ tin cậy thấp cho {$item['category']} - cần thêm dữ liệu";
        }
    }
    
    return empty($risks) ? ['Không có rủi ro đáng kể'] : $risks;
}

/**
 * Khuyến nghị chiến lược
 */
function generateStrategicRecommendations($market_condition, $month, $region) {
    $recommendations = [];
    
    // Theo tình hình thị trường
    switch ($market_condition) {
        case 'tăng trưởng mạnh':
            $recommendations[] = 'Tăng đầu tư marketing và mở rộng kho hàng';
            $recommendations[] = 'Cân nhắc tăng giá cho các sản phẩm hot';
            break;
        case 'suy giảm':
            $recommendations[] = 'Tập trung vào hiệu quả chi phí và khuyến mãi';
            $recommendations[] = 'Diversify sang các danh mục ít bị ảnh hưởng';
            break;
        default:
            $recommendations[] = 'Duy trì chiến lược hiện tại với điều chỉnh nhỏ';
    }
    
    // Theo vùng miền
    $regional_recs = [
        'north' => 'Focus vào chất lượng và branding cao cấp',
        'central' => 'Nhấn mạnh độ bền và tính thực dụng',
        'south' => 'Đa dạng hóa và cập nhật trend nhanh'
    ];
    $recommendations[] = $regional_recs[$region] ?? $regional_recs['south'];
    
    return $recommendations;
}

/**
 * Tổng quan Dashboard với AI Analysis nâng cao
 */
function getDashboardSummary($conn) {
    $warehouse_id = $_SESSION['warehouse_id'];
    
    try {
        // Lấy thông tin warehouse
        $warehouse_info = getWarehouseInfo($conn, $warehouse_id);
        
        // Tổng quan nhanh
        $sql_summary = "SELECT 
            (SELECT COUNT(DISTINCT p.product_id) FROM products p INNER JOIN product_variants pv ON p.product_id = pv.product_id WHERE p.warehouse_id = :wh1 AND p.status = 'active') as total_products,
            (SELECT COALESCE(SUM(quantity), 0) FROM inventory WHERE warehouse_id = :wh2) as total_stock,
            (SELECT COUNT(*) FROM orders WHERE warehouse_id = :wh3 AND status = 'delivered' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) as orders_last_month,
            (SELECT COALESCE(SUM(od.total_price), 0) FROM orders o JOIN order_details od ON o.order_id = od.order_id WHERE o.warehouse_id = :wh4 AND o.status = 'delivered' AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) as revenue_last_month";
        
        $stmt = $conn->prepare($sql_summary);
        $stmt->execute([
            'wh1' => $warehouse_id,
            'wh2' => $warehouse_id,
            'wh3' => $warehouse_id,
            'wh4' => $warehouse_id
        ]);
        $summary = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Đảm bảo các giá trị không null
        $summary['total_products'] = $summary['total_products'] ?? 0;
        $summary['total_stock'] = $summary['total_stock'] ?? 0;
        $summary['orders_last_month'] = $summary['orders_last_month'] ?? 0;
        $summary['revenue_last_month'] = $summary['revenue_last_month'] ?? 0;
        
        // Phân tích tồn kho chi tiết
        $inventory_analysis = getDetailedInventoryAnalysis($conn, $warehouse_id);
        
        // Phân tích hiệu quả kinh doanh
        $business_performance = analyzeBusinessPerformance($conn, $warehouse_id, $summary);
        
        // Tạo AI Insights thông minh
        $ai_insights = generateSmartAIInsights($conn, $warehouse_id, $warehouse_info, $inventory_analysis, $business_performance, $summary);
        
        // Kết hợp tất cả dữ liệu
        $summary['warehouse_info'] = $warehouse_info;
        $summary['inventory_analysis'] = $inventory_analysis;
        $summary['business_performance'] = $business_performance;
        
        // Flatten AI insights để frontend dễ access
        if (isset($ai_insights['warehouse_health']['score'])) {
            $summary['warehouse_health_score'] = $ai_insights['warehouse_health']['score'];
        }
        if (isset($ai_insights['risk_alerts'])) {
            $summary['risk_alerts'] = $ai_insights['risk_alerts'];
        }
        if (isset($ai_insights['business_insights'])) {
            $summary['business_insights'] = $ai_insights['business_insights'];
        }
        if (isset($ai_insights['next_month_forecast'])) {
            $summary['next_month_forecast'] = $ai_insights['next_month_forecast'];
        }
        
        $summary['ai_insights'] = $ai_insights;
        
        // Clean output buffer before JSON
        if (ob_get_level()) ob_clean();
        echo json_encode([
            'success' => true,
            'data' => $summary
        ]);
    } catch (Exception $e) {
        // Clean output buffer on error too
        if (ob_get_level()) ob_clean();
        echo json_encode([
            'success' => false,
            'message' => 'Lỗi getDashboardSummary: ' . $e->getMessage()
        ]);
    }
}

/**
 * Lấy thông tin warehouse
 */
function getWarehouseInfo($conn, $warehouse_id) {
    $sql = "SELECT w.name, w.address, w.status, u.full_name as manager_name, u.phone as manager_phone 
            FROM warehouses w
            LEFT JOIN users u ON w.warehouse_id = u.warehouse_id AND u.role = 'manager'
            WHERE w.warehouse_id = :wh_id
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute(['wh_id' => $warehouse_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [
        'name' => 'Kho chưa xác định',
        'address' => 'Chưa có địa chỉ',
        'manager_name' => 'Chưa có quản lý',
        'manager_phone' => null,
        'status' => 'active'
    ];
}

/**
 * Phân tích tồn kho chi tiết
 */
function getDetailedInventoryAnalysis($conn, $warehouse_id) {
    // Phân tích ABC theo số lượng và giá trị
    $sql_abc = "SELECT 
        COALESCE(p.type, 'Chưa phân loại') as category_name,
        COUNT(DISTINCT i.variant_id) as product_count,
        SUM(i.quantity) as total_quantity,
        AVG(i.quantity) as avg_quantity,
        MIN(i.quantity) as min_quantity,
        MAX(i.quantity) as max_quantity,
        SUM(CASE WHEN i.quantity < 10 THEN 1 ELSE 0 END) as low_stock_count,
        SUM(CASE WHEN i.quantity = 0 THEN 1 ELSE 0 END) as out_of_stock_count
    FROM inventory i
    JOIN product_variants pv ON i.variant_id = pv.variant_id
    JOIN products p ON pv.product_id = p.product_id
    WHERE i.warehouse_id = :wh_id
    GROUP BY p.type
    ORDER BY total_quantity DESC";
    
    $stmt = $conn->prepare($sql_abc);
    $stmt->execute(['wh_id' => $warehouse_id]);
    $category_analysis = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // CHỈ SỬ DỤNG DỮ LIỆU THẬT - Không tạo dữ liệu mẫu
    // Nếu không có dữ liệu, trả về array rỗng
    
    // Tính tỷ lệ turnover (nếu có dữ liệu bán hàng)
    $sql_turnover = "SELECT 
        COALESCE(p.type, 'Chưa phân loại') as category_name,
        COUNT(DISTINCT od.variant_id) as variants_sold,
        SUM(od.quantity) as total_sold_30d,
        AVG(i.quantity) as avg_inventory
    FROM order_details od
    JOIN orders o ON od.order_id = o.order_id
    JOIN product_variants pv ON od.variant_id = pv.variant_id
    JOIN products p ON pv.product_id = p.product_id
    LEFT JOIN inventory i ON od.variant_id = i.variant_id AND i.warehouse_id = :wh_id
    WHERE o.status = 'delivered' 
    AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY p.type";
    
    $stmt = $conn->prepare($sql_turnover);
    $stmt->execute(['wh_id' => $warehouse_id]);
    $turnover_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // CHỈ SỬ DỤNG DỮ LIỆU THẬT - Không tạo dữ liệu mẫu
    
    return [
        'category_analysis' => $category_analysis,
        'turnover_data' => $turnover_data,
        'total_categories' => count($category_analysis),
        'total_products' => array_sum(array_column($category_analysis, 'product_count')),
        'total_quantity' => array_sum(array_column($category_analysis, 'total_quantity')),
        'low_stock_products' => array_sum(array_column($category_analysis, 'low_stock_count')),
        'out_of_stock_products' => array_sum(array_column($category_analysis, 'out_of_stock_count')),
        'analysis_date' => date('Y-m-d H:i:s')
    ];
}

/**
 * Phân tích hiệu quả kinh doanh
 */
function analyzeBusinessPerformance($conn, $warehouse_id, $basic_summary) {
    // So sánh với tháng trước
    $sql_previous = "SELECT 
        COUNT(*) as prev_orders,
        COALESCE(SUM(od.total_price), 0) as prev_revenue
    FROM orders o
    JOIN order_details od ON o.order_id = od.order_id
    WHERE o.warehouse_id = :warehouse_id
        AND o.status = 'delivered' 
        AND o.created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY)
        AND o.created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)";
    
    $stmt = $conn->prepare($sql_previous);
    $stmt->execute(['warehouse_id' => $warehouse_id]);
    $previous_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Tính growth rate
    $orders_growth = $previous_data['prev_orders'] > 0 
        ? round((($basic_summary['orders_last_month'] - $previous_data['prev_orders']) / $previous_data['prev_orders']) * 100, 1)
        : 0;
    
    $revenue_growth = $previous_data['prev_revenue'] > 0
        ? round((($basic_summary['revenue_last_month'] - $previous_data['prev_revenue']) / $previous_data['prev_revenue']) * 100, 1)
        : 0;
    
    // Top selling products
    $sql_top = "SELECT 
        p.name as product_name,
        COALESCE(p.type, 'Chưa phân loại') as category_name,
        SUM(od.quantity) as total_sold,
        SUM(od.total_price) as total_revenue,
        COUNT(DISTINCT o.order_id) as order_count
    FROM order_details od
    JOIN orders o ON od.order_id = o.order_id
    JOIN product_variants pv ON od.variant_id = pv.variant_id
    JOIN products p ON pv.product_id = p.product_id
    WHERE o.warehouse_id = :warehouse_id
        AND o.status = 'delivered' 
        AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY p.product_id
    ORDER BY total_sold DESC
    LIMIT 5";
    
    $stmt = $conn->prepare($sql_top);
    $stmt->execute(['warehouse_id' => $warehouse_id]);
    $top_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'orders_growth' => $orders_growth,
        'revenue_growth' => $revenue_growth,
        'previous_month' => $previous_data,
        'top_products' => $top_products,
        'avg_order_value' => $basic_summary['orders_last_month'] > 0 
            ? round($basic_summary['revenue_last_month'] / $basic_summary['orders_last_month'], 0)
            : 0
    ];
}

/**
 * Tạo AI Insights thông minh và cụ thể
 */
function generateSmartAIInsights($conn, $warehouse_id, $warehouse_info, $inventory_analysis, $business_performance, $summary) {
    $current_month = date('n');
    $insights = [];
    
    // 1. Đánh giá tình trạng warehouse
    $warehouse_health = assessWarehouseHealth($inventory_analysis, $business_performance, $summary);
    $insights['warehouse_health'] = $warehouse_health;
    
    // 2. Phân tích tồn kho và đưa ra khuyến nghị cụ thể
    $inventory_recommendations = generateInventoryRecommendations($inventory_analysis, $current_month);
    $insights['inventory_recommendations'] = $inventory_recommendations;
    
    // 3. Phân tích hiệu quả kinh doanh và dự báo
    $business_insights = generateBusinessInsights($business_performance, $current_month, $warehouse_info);
    $insights['business_insights'] = $business_insights;
    
    // 4. Rủi ro và cảnh báo
    $risk_alerts = identifyRisksAndAlerts($inventory_analysis, $business_performance, $summary);
    $insights['risk_alerts'] = $risk_alerts;
    
    // 5. Kế hoạch hành động cụ thể cho 30 ngày tới
    $action_plan = generate30DayActionPlan($warehouse_info, $inventory_analysis, $business_performance, $current_month);
    $insights['action_plan'] = $action_plan;
    
    // 6. Dự báo cho tháng tới
    $next_month_forecast = generateNextMonthForecast($business_performance, $current_month, $warehouse_info['address']);
    $insights['next_month_forecast'] = $next_month_forecast;
    
    return $insights;
}

/**
 * Đánh giá sức khỏe warehouse
 */
function assessWarehouseHealth($inventory_analysis, $business_performance, $summary) {
    $health_score = 100;
    $issues = [];
    $strengths = [];
    
    // Đánh giá tồn kho
    $total_products = $summary['total_products'];
    $total_stock = $summary['total_stock'];
    
    if ($total_products == 0) {
        $health_score -= 40;
        $issues[] = "NGHIÊM TRỌNG: Warehouse không có sản phẩm nào";
    } elseif ($total_products < 10) {
        $health_score -= 15;
        $issues[] = "Số lượng sản phẩm quá ít ({$total_products} sản phẩm)";
    } else {
        $strengths[] = "Đa dạng sản phẩm tốt ({$total_products} sản phẩm)";
    }
    
    // Đánh giá stock level
    if ($total_stock == 0) {
        $health_score -= 35;
        $issues[] = "NGHIÊM TRỌNG: Hết hàng hoàn toàn";
    } else {
        $avg_stock_per_product = $total_products > 0 ? round($total_stock / $total_products, 1) : 0;
        if ($avg_stock_per_product < 20) {
            $health_score -= 20;
            $issues[] = "Tồn kho thấp (TB: {$avg_stock_per_product} sản phẩm/loại)";
        } elseif ($avg_stock_per_product > 200) {
            $health_score -= 10;
            $issues[] = "Tồn kho quá cao, có thể bị ứ đọng";
        } else {
            $strengths[] = "Mức tồn kho hợp lý";
        }
    }
    
    // Đánh giá hiệu quả kinh doanh
    $revenue_growth = $business_performance['revenue_growth'];
    $orders_growth = $business_performance['orders_growth'];
    
    if ($revenue_growth > 15) {
        $strengths[] = "Doanh thu tăng trưởng mạnh (+{$revenue_growth}%)";
    } elseif ($revenue_growth < -15) {
        $health_score -= 15;
        $issues[] = "Doanh thu giảm đáng lo ngại ({$revenue_growth}%)";
    }
    
    if ($orders_growth > 10) {
        $strengths[] = "Số đơn hàng tăng tốt (+{$orders_growth}%)";
    } elseif ($orders_growth < -10) {
        $health_score -= 10;
        $issues[] = "Số đơn hàng giảm ({$orders_growth}%)";
    }
    
    // Tính final health status
    $health_status = 'excellent';
    if ($health_score < 60) $health_status = 'poor';
    elseif ($health_score < 75) $health_status = 'fair';
    elseif ($health_score < 90) $health_status = 'good';
    
    return [
        'score' => max(0, $health_score),
        'status' => $health_status,
        'issues' => $issues,
        'strengths' => $strengths,
        'recommendation' => getHealthRecommendation($health_status, $issues)
    ];
}

/**
 * Khuyến nghị dựa trên health status
 */
function getHealthRecommendation($status, $issues) {
    switch ($status) {
        case 'poor':
            return "KHẨN CẤP: Cần hành động ngay lập tức để cải thiện tình hình warehouse";
        case 'fair':
            return "Cần chú ý và cải thiện những vấn đề đã được chỉ ra";
        case 'good':
            return "Warehouse hoạt động ổn định, cần duy trì và tối ưu hóa";
        case 'excellent':
            return "Warehouse hoạt động xuất sắc, có thể mở rộng quy mô";
        default:
            return "Cần theo dõi thêm dữ liệu để đánh giá";
    }
}

/**
 * Khuyến nghị tồn kho cụ thể
 */
function generateInventoryRecommendations($inventory_analysis, $current_month) {
    $recommendations = [];
    $categories = $inventory_analysis['category_analysis'] ?? [];
    
    foreach ($categories as $category) {
        $category_name = $category['category_name'] ?? 'Danh mục';
        $total_qty = $category['total_quantity'] ?? 0;
        $low_stock = $category['low_stock_count'] ?? 0;
        $out_of_stock = $category['out_of_stock_count'] ?? 0;
        $product_count = $category['product_count'] ?? 0;
        
        // Phân tích từng category
        if ($out_of_stock > 0) {
            $recommendations[] = [
                'title' => 'Nhập hàng khẩn cấp',
                'description' => "Có {$out_of_stock} sản phẩm hết hàng trong {$category_name}. Cần nhập hàng trong 3-5 ngày tới.",
                'priority' => 'high',
                'expected_impact' => 'Tránh mất doanh thu từ stock-out'
            ];
        }
        
        if ($low_stock > $product_count * 0.3) {
            $recommendations[] = [
                'category' => $category_name,
                'priority' => 'MEDIUM',
                'issue' => "Có {$low_stock}/{$product_count} sản phẩm sắp hết hàng",
                'action' => "Lập kế hoạch nhập hàng cho {$category_name}",
                'timeline' => "Trong 1-2 tuần",
                'estimated_quantity' => $low_stock * 30
            ];
        }
        
        // Kiểm tra seasonal adjustment
        $seasonal_advice = getSeasonalInventoryAdvice($category_name, $current_month);
        if ($seasonal_advice) {
            $recommendations[] = array_merge($seasonal_advice, [
                'category' => $category_name,
                'priority' => 'MEDIUM'
            ]);
        }
        
        // Kiểm tra overstocking
        $avg_qty = $category['avg_quantity'];
        if ($avg_qty > 150) {
            $recommendations[] = [
                'category' => $category_name,
                'priority' => 'LOW',
                'issue' => "Tồn kho cao (TB: {$avg_qty} sản phẩm/loại)",
                'action' => "Xem xét giảm giá hoặc khuyến mãi cho {$category_name}",
                'timeline' => "Trong 2-4 tuần",
                'estimated_reduction' => round($avg_qty * 0.3)
            ];
        }
    }
    
    return empty($recommendations) ? [[
        'category' => 'Tổng thể',
        'priority' => 'INFO',
        'issue' => 'Không có vấn đề tồn kho nghiêm trọng',
        'action' => 'Duy trì mức tồn kho hiện tại',
        'timeline' => 'Theo dõi hàng tuần'
    ]] : $recommendations;
}

/**
 * Lời khuyên tồn kho theo mùa
 */
function getSeasonalInventoryAdvice($category, $month) {
    $seasonal_patterns = [
        'Sandal' => [
            2 => ['action' => 'Bắt đầu tăng stock sandal cho mùa hè', 'quantity_multiplier' => 1.5],
            5 => ['action' => 'Đảm bảo đủ sandal cho cao điểm hè', 'quantity_multiplier' => 2.0],
            9 => ['action' => 'Giảm stock sandal, chuẩn bị thanh lý', 'quantity_multiplier' => 0.7]
        ],
        'Giày cao gót' => [
            12 => ['action' => 'Tăng stock giày cao gót cho dịp cuối năm', 'quantity_multiplier' => 1.4],
            1 => ['action' => 'Đảm bảo đủ giày cao gót cho Tết', 'quantity_multiplier' => 1.6]
        ],
        'Giày boot' => [
            11 => ['action' => 'Tăng stock giày boot cho mùa lạnh', 'quantity_multiplier' => 1.3],
            1 => ['action' => 'Peak season giày boot, đảm bảo supply', 'quantity_multiplier' => 1.5]
        ],
        'Sneaker' => [
            6 => ['action' => 'Tăng stock sneaker cho mùa hè', 'quantity_multiplier' => 1.3],
            8 => ['action' => 'Chuẩn bị back-to-school season', 'quantity_multiplier' => 1.2]
        ]
    ];
    
    return $seasonal_patterns[$category][$month] ?? null;
}

/**
 * Phân tích insights kinh doanh
 */
function generateBusinessInsights($business_performance, $current_month, $warehouse_info) {
    $insights = [];
    
    // Phân tích growth với null check
    $revenue_growth = $business_performance['revenue_growth'] ?? 0;
    $orders_growth = $business_performance['orders_growth'] ?? 0;
    $avg_order_value = $business_performance['avg_order_value'] ?? 0;
    
    // Revenue analysis
    if ($revenue_growth > 20) {
        $insights[] = [
            'title' => 'Doanh thu tăng trưởng mạnh',
            'description' => "Doanh thu tăng {$revenue_growth}% so với tháng trước - hiệu quả kinh doanh xuất sắc. Tận dụng momentum này để mở rộng marketing và tăng inventory.",
            'impact' => 'Tích cực cao'
        ];
    } elseif ($revenue_growth < -10) {
        $insights[] = [
            'title' => 'Doanh thu giảm',
            'description' => "Doanh thu giảm {$revenue_growth}% - cần hành động khắc phục. Kiểm tra chất lượng sản phẩm, giá cả và chiến lược marketing.",
            'impact' => 'Cần chú ý'
        ];
    }
    
    // Order value analysis
    if ($avg_order_value > 0) {
        if ($avg_order_value >= 1000000) {
            $insights[] = [
                'type' => 'success',
                'title' => 'Giá trị đơn hàng cao',
                'description' => "AOV: " . number_format($avg_order_value) . "đ - khách hàng có sức mua tốt",
                'recommendation' => "Focus vào sản phẩm cao cấp và premium"
            ];
        } elseif ($avg_order_value < 500000) {
            $insights[] = [
                'type' => 'info',
                'title' => 'Cơ hội tăng AOV',
                'description' => "AOV hiện tại: " . number_format($avg_order_value) . "đ",
                'recommendation' => "Thử nghiệm upselling, cross-selling và combo deals"
            ];
        }
    }
    
    // Top products analysis
    $top_products = $business_performance['top_products'] ?? [];
    if (!empty($top_products)) {
        $best_seller = $top_products[0];
        $product_name = $best_seller['product_name'] ?? 'Sản phẩm';
        $total_sold = $best_seller['total_sold'] ?? 0;
        
        $insights[] = [
            'title' => 'Sản phẩm bán chạy nhất',
            'description' => "{$product_name} - {$total_sold} sản phẩm đã bán. Đảm bảo stock đầy đủ và consider expanding similar products.",
            'impact' => 'Thông tin'
        ];
        
        // Phân tích concentration risk
        $total_top5_sales = array_sum(array_column($top_products, 'total_sold'));
        if (count($top_products) >= 2) {
            $concentration = $total_top5_sales > 0 ? round(($best_seller['total_sold'] / $total_top5_sales) * 100, 1) : 0;
            if ($concentration > 40) {
                $insights[] = [
                    'title' => 'Rủi ro tập trung sản phẩm',
                    'description' => "Sản phẩm top chiếm {$concentration}% doanh số - đề xuất đa dạng hóa sản phẩm",
                    'impact' => 'Cảnh báo'
                ];
            }
        }
    }

    // Default insight nếu không có data
    if (empty($insights)) {
        $insights[] = [
            'title' => 'Hệ thống đang phân tích',
            'description' => 'Đang thu thập và phân tích dữ liệu để đưa ra insights chính xác.',
            'impact' => 'Thông tin'
        ];
    }

    return $insights;
}

/**
 * Nhận diện rủi ro và cảnh báo
 */
function identifyRisksAndAlerts($inventory_analysis, $business_performance, $summary) {
    $alerts = [];
    
    // Critical inventory alerts
    $categories = $inventory_analysis['category_analysis'] ?? [];
    foreach ($categories as $category) {
        // Đảm bảo các trường cần thiết tồn tại
        $category_name = $category['category_name'] ?? 'Danh mục không xác định';
        $out_of_stock_count = $category['out_of_stock_count'] ?? 0;
        $low_stock_count = $category['low_stock_count'] ?? 0;
        $product_count = $category['product_count'] ?? 0;
        
        if ($out_of_stock_count > 0) {
            $alerts[] = [
                'level' => 'high',
                'type' => 'inventory',
                'title' => 'Hết hàng',
                'description' => "{$category_name}: {$out_of_stock_count} sản phẩm hết hàng - Cần nhập hàng khẩn cấp"
            ];
        }
        
        if ($product_count > 0 && $low_stock_count > $product_count * 0.5) {
            $alerts[] = [
                'level' => 'medium',
                'type' => 'inventory', 
                'title' => 'Sắp hết hàng',
                'description' => "{$category_name}: {$low_stock_count}/{$product_count} sản phẩm sắp hết - Lập kế hoạch nhập hàng"
            ];
        }
    }
    
    // Business performance alerts
    $revenue_growth = $business_performance['revenue_growth'] ?? 0;
    $orders_growth = $business_performance['orders_growth'] ?? 0;
    
    if ($revenue_growth < -20) {
        $alerts[] = [
            'level' => 'high',
            'type' => 'performance',
            'title' => 'Doanh thu giảm mạnh',
            'description' => "Doanh thu giảm {$revenue_growth}% - Cần review pricing strategy và marketing"
        ];
    }
    
    if ($orders_growth < -25) {
        $alerts[] = [
            'level' => 'high',
            'type' => 'performance',
            'title' => 'Đơn hàng giảm nghiêm trọng',
            'description' => "Đơn hàng giảm {$orders_growth}% - Kiểm tra customer experience"
        ];
    }
    
    // Seasonal risks
    $current_month = date('n');
    $seasonal_risks = getSeasonalRisks($current_month);
    foreach ($seasonal_risks as $risk) {
        $alerts[] = [
            'level' => 'medium',
            'type' => 'seasonal',
            'title' => 'Rủi ro theo mùa',
            'description' => $risk['message'] ?? 'Cần theo dõi yếu tố mùa vụ'
        ];
    }
    
    return $alerts;
}

/**
 * Rủi ro theo mùa
 */
function getSeasonalRisks($month) {
    $risks = [
        2 => ['message' => 'Post-Tết: Sức mua có thể giảm', 'action_required' => 'Chuẩn bị khuyến mãi'],
        6 => ['message' => 'Mùa mưa bắt đầu: Ảnh hưởng logistics', 'action_required' => 'Đảm bảo supply chain'],
        9 => ['message' => 'Cuối hè: Sandal sales giảm', 'action_required' => 'Thanh lý summer inventory'],
        11 => ['message' => 'Black Friday competition', 'action_required' => 'Chuẩn bị chiến dịch marketing']
    ];
    
    return isset($risks[$month]) ? [$risks[$month]] : [];
}

/**
 * Kế hoạch hành động 30 ngày
 */
function generate30DayActionPlan($warehouse_info, $inventory_analysis, $business_performance, $current_month) {
    $plan = [];
    
    // Week 1 priorities
    $week1 = [];
    
    // Critical inventory actions
    $categories = $inventory_analysis['category_analysis'] ?? [];
    foreach ($categories as $category) {
        $category_name = $category['category_name'] ?? 'Danh mục';
        $out_of_stock_count = $category['out_of_stock_count'] ?? 0;
        
        if ($out_of_stock_count > 0) {
            $week1[] = "Đặt hàng khẩn cấp cho {$category_name} (còn thiếu {$out_of_stock_count} sản phẩm)";
        }
    }
    
    // Business performance actions
    $revenue_growth = $business_performance['revenue_growth'] ?? 0;
    if ($revenue_growth < 0) {
        $week1[] = "Phân tích nguyên nhân doanh thu giảm và đưa ra kế hoạch khắc phục";
    }
    
    $week1[] = "Review và update pricing strategy dựa trên market conditions";
    
    // Week 2-3 priorities
    $week2_3 = [
        "Thực hiện inventory audit toàn bộ warehouse {$warehouse_info['name']}",
        "Optimize storage layout để tăng efficiency",
        "Training team về AI forecasting system",
        "Setup automated alerts cho low stock items"
    ];
    
    // Week 4 priorities
    $week4 = [
        "Prepare cho tháng " . ($current_month + 1) . " dựa trên forecast",
        "Đánh giá performance của tháng hiện tại",
        "Plan marketing campaigns cho tháng tới",
        "Review supplier relationships và negotiate terms"
    ];
    
    $plan = [
        'week_1' => $week1 ?: ["Duy trì operations bình thường", "Monitor key metrics"],
        'week_2_3' => $week2_3,
        'week_4' => $week4,
        'success_metrics' => [
            "Giảm out-of-stock xuống dưới 2%",
            "Tăng revenue growth lên tối thiểu 5%",
            "Cải thiện inventory turnover rate",
            "Đạt 95% order fulfillment rate"
        ]
    ];
    
    return $plan;
}

/**
 * Dự báo tháng tới
 */
function generateNextMonthForecast($business_performance, $current_month, $warehouse_address) {
    $next_month = $current_month + 1;
    if ($next_month > 12) $next_month = 1;
    
    // Dự báo dựa trên historical performance với null check
    $revenue_trend = $business_performance['revenue_growth'] ?? 0;
    $orders_trend = $business_performance['orders_growth'] ?? 0;
    
    // Seasonal adjustments
    $seasonal_factor = getSeasonalBusinessFactor($next_month);
    
    // Regional adjustments based on address
    $region_factor = detectRegionFromAddress($warehouse_address ?? '');
    
    $predicted_revenue_growth = round($revenue_trend * $seasonal_factor * $region_factor, 1);
    $predicted_orders_growth = round($orders_trend * $seasonal_factor * $region_factor, 1);
    
    $month_names = [
        1 => 'Tháng 1', 2 => 'Tháng 2', 3 => 'Tháng 3', 4 => 'Tháng 4',
        5 => 'Tháng 5', 6 => 'Tháng 6', 7 => 'Tháng 7', 8 => 'Tháng 8',
        9 => 'Tháng 9', 10 => 'Tháng 10', 11 => 'Tháng 11', 12 => 'Tháng 12'
    ];
    
    $forecast = [
        'month' => $next_month,
        'month_name' => $month_names[$next_month] ?? 'Tháng không xác định',
        'expected_sales' => ($business_performance['current_revenue'] ?? 100000) * (1 + $predicted_revenue_growth/100),
        'inventory_needs' => [
            ['product' => 'Sản phẩm hot', 'quantity' => '200-300', 'reason' => 'Theo xu hướng mùa'],
            ['product' => 'Sản phẩm thông thường', 'quantity' => '100-150', 'reason' => 'Duy trì stock level']
        ],
        'seasonal_factors' => [
            ['event' => 'Yếu tố mùa vụ tháng ' . $next_month, 'impact' => 'Ảnh hưởng ' . ($seasonal_factor > 1 ? 'tích cực' : 'tiêu cực')]
        ]
    ];
    
    return $forecast;
}

/**
 * Helper functions for forecast
 */
function getSeasonalBusinessFactor($month) {
    $factors = [
        1 => 1.5,  // Tết
        2 => 0.7,  // Post Tết
        3 => 1.1,  // Spring
        4 => 1.2,  // Spring festivals
        5 => 1.0,  // Normal
        6 => 1.3,  // Summer start
        7 => 1.4,  // Summer peak
        8 => 1.2,  // Summer
        9 => 1.1,  // Back to school
        10 => 1.0, // Autumn
        11 => 1.4, // Black Friday
        12 => 1.3  // Year end
    ];
    
    return $factors[$month] ?? 1.0;
}

function detectRegionFromAddress($address) {
    if (empty($address)) return 1.0;
    
    $address = strtolower($address);
    if (strpos($address, 'hà nội') !== false || strpos($address, 'hải phòng') !== false) {
        return 1.1; // North
    } elseif (strpos($address, 'đà nẵng') !== false || strpos($address, 'huế') !== false) {
        return 1.0; // Central
    } elseif (strpos($address, 'hồ chí minh') !== false || strpos($address, 'sài gòn') !== false) {
        return 1.2; // South
    }
    return 1.0;
}

function getMonthName($month) {
    $names = [
        1 => 'Tháng Giêng', 2 => 'Tháng Hai', 3 => 'Tháng Ba', 4 => 'Tháng Tư',
        5 => 'Tháng Năm', 6 => 'Tháng Sáu', 7 => 'Tháng Bảy', 8 => 'Tháng Tám',
        9 => 'Tháng Chín', 10 => 'Tháng Mười', 11 => 'Tháng Mười Một', 12 => 'Tháng Mười Hai'
    ];
    return $names[$month] ?? "Tháng {$month}";
}

function getMonthlyOpportunities($month) {
    $opportunities = [
        1 => ['Tết sales', 'Gift giving', 'New year resolutions'],
        2 => ['Valentine promotion', 'New stock introduction'],
        3 => ['Spring fashion', 'Women\'s day', 'Wedding season prep'],
        4 => ['Spring outdoor activities', 'Easter promotions'],
        5 => ['Summer prep', 'Mother\'s day', 'Graduation season'],
        6 => ['Summer peak', 'Vacation footwear', 'Student discounts'],
        7 => ['Summer peak continues', 'Tourist season'],
        8 => ['Back to school', 'Late summer sales'],
        9 => ['Autumn fashion', 'Back to work'],
        10 => ['Halloween', 'Autumn collections'],
        11 => ['Black Friday', '11.11', 'Pre-Christmas'],
        12 => ['Christmas', 'Year-end parties', 'Holiday gifting']
    ];
    
    return $opportunities[$month] ?? ['Regular operations'];
}

function getMonthlyChallenges($month) {
    $challenges = [
        1 => ['High expectations', 'Increased competition'],
        2 => ['Post-holiday slump', 'Economic uncertainty'],
        3 => ['Weather transition', 'Supply chain adjustments'],
        4 => ['Spring allergies affect shopping', 'Economic pressures'],
        5 => ['Summer preparation competition'],
        6 => ['Rainy season logistics', 'High temperatures'],
        7 => ['Peak competition', 'Supply shortages'],
        8 => ['Back-to-school competition', 'Weather issues'],
        9 => ['Seasonal transition', 'Economic factors'],
        10 => ['Weather cooling', 'Holiday preparation'],
        11 => ['Intense competition', 'Inventory pressure'],
        12 => ['High expectations', 'Supply chain stress']
    ];
    
    return $challenges[$month] ?? ['Normal market challenges'];
}

function getRecommendedFocus($month, $predicted_growth) {
    if ($predicted_growth > 15) {
        return 'Growth và expansion - tận dụng momentum tích cực';
    } elseif ($predicted_growth < -10) {
        return 'Cost optimization và retention - focus vào efficiency';
    } else {
        return 'Steady operations với selective improvements';
    }
}

function getSuggestedPromotions($month) {
    $promotions = [
        1 => ['Tết Special Offers', 'Buy 2 Get 1 Free', 'Lucky Draw'],
        2 => ['Valentine Couple Deals', 'Post-Tết Clearance'],
        3 => ['Women\'s Day Special', 'Spring Collection Launch'],
        4 => ['Spring Sale', 'Easter Promotions'],
        5 => ['Mother\'s Day Gifts', 'Summer Preview'],
        6 => ['Summer Kickoff', 'Student Discounts'],
        7 => ['Mid-Summer Sale', 'Vacation Specials'],
        8 => ['Back-to-School', 'Late Summer Clearance'],
        9 => ['Autumn Welcome', 'New Season Launch'],
        10 => ['Halloween Specials', 'Autumn Colors'],
        11 => ['Black Friday Mega Sale', '11.11 Flash Sales'],
        12 => ['Christmas Specials', 'Year-End Clearance']
    ];
    
    return $promotions[$month] ?? ['Seasonal Promotions'];
}

/**
 * Phân tích hot trends giày từ mạng xã hội Việt Nam
 */
function getSocialTrendsVietnam($conn) {
    // Mô phỏng việc crawl data từ các platform phổ biến ở VN
    $vietnam_social_trends = [
        'tiktok_trends' => [
            [
                'keyword' => 'giày chunky sneaker',
                'mentions' => 15680,
                'engagement_rate' => 8.5,
                'target_age' => '18-25',
                'trend_direction' => 'tăng mạnh',
                'hashtags' => ['#chunkysneaker', '#giayulzzang', '#streetstyle'],
                'influencer_count' => 45,
                'recommendation' => 'Nhập 150-200 đôi chunky sneaker cho Gen Z, focus màu trắng và đen'
            ],
            [
                'keyword' => 'giày mary jane vintage',
                'mentions' => 12340,
                'engagement_rate' => 7.2,
                'target_age' => '20-30',
                'trend_direction' => 'đang tăng',
                'hashtags' => ['#maryjane', '#vintagestyle', '#koreanfashion'],
                'influencer_count' => 38,
                'recommendation' => 'Tăng stock giày mary jane, đặc biệt màu đen và nâu'
            ]
        ],
        'facebook_trends' => [
            [
                'product' => 'Giày cao gót block heel',
                'groups_discussing' => 28,
                'total_members' => 125000,
                'sentiment' => 'tích cực',
                'price_range_discussed' => '300k-800k',
                'recommendation' => 'Block heel đang hot trong các group mẹ bỉm, office workers'
            ]
        ],
        'instagram_trends' => [
            [
                'hashtag' => '#giayconverse',
                'posts_last_week' => 8900,
                'growth_rate' => 23,
                'popular_styles' => ['Chuck Taylor All Star', 'Platform'],
                'color_trends' => ['Trắng', 'Đen', 'Hồng pastel'],
                'recommendation' => 'Converse style vẫn hot, focus vào platform và màu basic'
            ]
        ]
    ];
    
    // Phân tích và đưa ra insights cho thị trường Việt Nam
    $insights = analyzeSocialTrendsForBusiness($vietnam_social_trends);
    
    return [
        'success' => true,
        'data' => [
            'trending_products' => $vietnam_social_trends,
            'business_insights' => $insights,
            'updated_at' => date('Y-m-d H:i:s')
        ]
    ];
}

/**
 * Phân tích trends cho business decisions
 */
function analyzeSocialTrendsForBusiness($trends_data) {
    $insights = [
        'hot_categories' => [],
        'declining_categories' => [],
        'target_demographics' => [],
        'pricing_insights' => [],
        'stock_recommendations' => [],
        'marketing_suggestions' => []
    ];
    
    // Phân tích TikTok trends (quan trọng nhất cho Gen Z)
    foreach ($trends_data['tiktok_trends'] as $trend) {
        if ($trend['engagement_rate'] > 7) {
            $insights['hot_categories'][] = [
                'category' => $trend['keyword'],
                'urgency' => 'cao',
                'target_market' => $trend['target_age'],
                'action' => $trend['recommendation']
            ];
        }
        
        $insights['target_demographics'][$trend['target_age']] = [
            'preferences' => $trend['keyword'],
            'engagement' => $trend['engagement_rate'],
            'marketing_channel' => 'TikTok + Instagram'
        ];
    }
    
    // Phân tích Facebook (quan trọng cho millennials và Gen X)
    foreach ($trends_data['facebook_trends'] as $trend) {
        if ($trend['sentiment'] === 'tích cực') {
            $insights['hot_categories'][] = [
                'category' => $trend['product'],
                'target_market' => 'working women 25-40',
                'price_point' => $trend['price_range_discussed'],
                'action' => $trend['recommendation']
            ];
        }
    }
    
    // Pricing insights dựa trên social discussions
    $insights['pricing_insights'] = [
        'chunky_sneaker' => '400k-900k (sweet spot: 600k)',
        'mary_jane_vintage' => '350k-750k (popular: 500k)',
        'block_heel' => '300k-800k (office appropriate)',
        'converse_style' => '250k-600k (mass market)'
    ];
    
    // Stock recommendations dựa trên social momentum
    $insights['stock_recommendations'] = [
        [
            'category' => 'Chunky Sneaker',
            'recommended_qty' => 180,
            'priority' => 'HIGH',
            'reason' => 'TikTok viral, Gen Z target, 15k+ mentions',
            'timeline' => 'Trong 2 tuần',
            'colors' => ['Trắng (40%)', 'Đen (35%)', 'Pastel (25%)']
        ],
        [
            'category' => 'Mary Jane Vintage',
            'recommended_qty' => 120,
            'priority' => 'MEDIUM',
            'reason' => 'Korean fashion trend, K-drama influence',
            'timeline' => 'Trong 3-4 tuần',
            'colors' => ['Đen (50%)', 'Nâu (30%)', 'Kem (20%)']
        ],
        [
            'category' => 'Block Heel Office',
            'recommended_qty' => 90,
            'priority' => 'MEDIUM',
            'reason' => 'Working women community positive sentiment',
            'timeline' => 'Trong 4 tuần',
            'heel_height' => ['5cm (40%)', '7cm (60%)']
        ]
    ];
    
    return $insights;
}

/**
 * Cải tiến phân tích khí hậu Việt Nam chi tiết theo 3 miền
 */
function getVietnamClimateAnalysis($month) {
    $climate_data = [
        'north' => [
            'region_name' => 'Miền Bắc (Hà Nội, Hải Phòng, Quảng Ninh...)',
            'climate_type' => 'Cận nhiệt đới gió mùa',
            'monthly_analysis' => [
                1 => [
                    'season' => 'Đông lạnh',
                    'temp_range' => '13-20°C',
                    'humidity' => '75-85%',
                    'rainfall' => 'Ít mưa, sương mù',
                    'events' => 'Tết Nguyên Đán, Tết Tây',
                    'shoe_demand' => [
                        'high' => ['Giày boot', 'Giày đóng cổ cao', 'Sneaker dài'],
                        'medium' => ['Giày tây', 'Sneaker cao cổ'],
                        'low' => ['Sandal', 'Giày mules', 'Giày hở ngón']
                    ],
                    'business_advice' => 'Peak season giày đóng. Tăng stock boots 150%, giảm sandal 80%',
                    'cultural_events' => ['Tết Nguyên Đán', 'Lễ cúng ông Táo'],
                    'shopping_behavior' => 'Mua sắm Tết, tập trung giày đẹp cho du xuân'
                ],
                2 => [
                    'season' => 'Xuân - Ẩm lạnh',
                    'temp_range' => '15-22°C',
                    'humidity' => '80-90%',
                    'rainfall' => 'Mưa phùn kéo dài',
                    'events' => 'Lễ tình nhân (14/2)',
                    'shoe_demand' => [
                        'high' => ['Giày boot nhẹ', 'Sneaker chống nước', 'Giày da'],
                        'medium' => ['Giày tây', 'Giày thể thao'],
                        'low' => ['Sandal', 'Dép']
                    ],
                    'business_advice' => 'Mưa phùn - tăng giày chống nước nhẹ, giảm boots dày',
                    'cultural_events' => ['Sau Tết', 'Lễ hội đầu xuân'],
                    'shopping_behavior' => 'Mua giày đi làm sau Tết'
                ],
                3 => [
                    'season' => 'Xuân - Ấm dần',
                    'temp_range' => '18-25°C',
                    'humidity' => '80-90%',
                    'rainfall' => 'Mưa phùn, ẩm ướt',
                    'events' => 'Ngày Quốc tế Phụ nữ (8/3)',
                    'shoe_demand' => [
                        'high' => ['Sneaker', 'Giày da nhẹ', 'Giày vải'],
                        'medium' => ['Giày tây', 'Boot thấp'],
                        'low' => ['Giày đông dày', 'Boot cao']
                    ],
                    'business_advice' => 'Chuyển mùa - clear giày đông, chuẩn bị hè',
                    'cultural_events' => ['Giỗ Tổ Hùng Vương 10/3 ÂL'],
                    'shopping_behavior' => 'Mua giày nhẹ, thoáng cho thời tiết ấm'
                ],
                4 => [
                    'season' => 'Xuân hè - Nóng ẩm',
                    'temp_range' => '22-30°C',
                    'humidity' => '75-85%',
                    'rainfall' => 'Mưa rào thỉnh thoảng',
                    'events' => 'Giỗ Tổ Hùng Vương (10/3 ÂL), Giải phóng (30/4)',
                    'shoe_demand' => [
                        'high' => ['Sneaker thoáng', 'Giày vải', 'Sandal'],
                        'medium' => ['Giày da nhẹ', 'Giày thể thao'],
                        'low' => ['Boot', 'Giày đóng dày']
                    ],
                    'business_advice' => 'Bắt đầu mùa hè - tăng sandal, giày thoáng',
                    'cultural_events' => ['Giỗ Tổ 10/3', 'Giải phóng 30/4'],
                    'shopping_behavior' => 'Nghỉ lễ - mua giày du lịch'
                ],
                5 => [
                    'season' => 'Hè - Nóng bức',
                    'temp_range' => '25-35°C',
                    'humidity' => '75-85%',
                    'rainfall' => 'Mưa dông chiều',
                    'shoe_demand' => [
                        'high' => ['Sandal', 'Dép', 'Giày thoáng'],
                        'medium' => ['Sneaker lưới', 'Giày vải nhẹ'],
                        'low' => ['Boot', 'Giày da dày']
                    ],
                    'business_advice' => 'Cao điểm hè - focus sandal và giày thoáng',
                    'cultural_events' => ['Nghỉ hè bắt đầu', 'Quốc tế Thiếu nhi 1/6'],
                    'shopping_behavior' => 'Mua giày hè cho học sinh'
                ],
                6 => [
                    'season' => 'Hè - Nóng đỉnh điểm',
                    'temp_range' => '27-36°C',
                    'humidity' => '75-85%',
                    'rainfall' => 'Mưa dông chiều',
                    'shoe_demand' => [
                        'high' => ['Sandal', 'Giày thoáng khí', 'Dép thời trang'],
                        'medium' => ['Sneaker lưới', 'Giày vải'],
                        'low' => ['Boots', 'Giày da dày']
                    ],
                    'business_advice' => 'Focus sandal cao cấp, thoáng khí. Giảm giá boots để clear inventory',
                    'cultural_events' => ['Nghỉ hè', 'Du lịch biển'],
                    'shopping_behavior' => 'Mua giày thoáng, chống thấm, du lịch'
                ],
                7 => [
                    'season' => 'Hè - Nóng nhất',
                    'temp_range' => '28-37°C',
                    'humidity' => '75-85%',
                    'rainfall' => 'Mưa dông chiều',
                    'shoe_demand' => [
                        'high' => ['Sandal', 'Dép', 'Giày thoáng'],
                        'medium' => ['Sneaker lưới', 'Giày vải'],
                        'low' => ['Boots', 'Giày da']
                    ],
                    'business_advice' => 'Cao điểm hè, tối ưu sandal và giày thoáng. Stock đủ cho nhu cầu du lịch',
                    'cultural_events' => ['Nghỉ hè học sinh', 'Du lịch cao điểm'],
                    'shopping_behavior' => 'Mua nhiều giày du lịch, thể thao ngoài trời'
                ],
                8 => [
                    'season' => 'Hè - Cuối mùa',
                    'temp_range' => '27-35°C',
                    'humidity' => '75-85%',
                    'rainfall' => 'Mưa dông',
                    'shoe_demand' => [
                        'high' => ['Sandal', 'Sneaker thoáng', 'Dép'],
                        'medium' => ['Giày vải', 'Giày thể thao'],
                        'low' => ['Boot', 'Giày da dày']
                    ],
                    'business_advice' => 'Cuối hè - giảm giá sandal, chuẩn bị giày thu',
                    'cultural_events' => ['Chuẩn bị năm học mới'],
                    'shopping_behavior' => 'Mua giày cho học sinh đi học'
                ],
                9 => [
                    'season' => 'Thu - Mát mẻ',
                    'temp_range' => '24-32°C',
                    'humidity' => '70-80%',
                    'rainfall' => 'Ít mưa, khô ráo',
                    'shoe_demand' => [
                        'high' => ['Sneaker', 'Giày thể thao', 'Giày da'],
                        'medium' => ['Giày vải', 'Sandal'],
                        'low' => ['Boot cao', 'Giày đông']
                    ],
                    'business_advice' => 'Thời tiết đẹp nhất năm - tăng mọi loại giày',
                    'cultural_events' => ['Khai giảng', 'Quốc khánh 2/9', 'Trung thu'],
                    'shopping_behavior' => 'Mua giày đi học, đi làm'
                ],
                10 => [
                    'season' => 'Thu - Khô ráo',
                    'temp_range' => '22-30°C',
                    'humidity' => '70-80%',
                    'rainfall' => 'Ít mưa',
                    'shoe_demand' => [
                        'high' => ['Sneaker', 'Giày da', 'Giày công sở'],
                        'medium' => ['Giày thể thao', 'Boot thấp'],
                        'low' => ['Sandal', 'Dép']
                    ],
                    'business_advice' => 'Thu đẹp - focus giày công sở và thời trang',
                    'cultural_events' => ['Phụ nữ Việt Nam 20/10'],
                    'shopping_behavior' => 'Mua giày làm quà 20/10'
                ],
                11 => [
                    'season' => 'Thu đông - Se lạnh',
                    'temp_range' => '18-26°C',
                    'humidity' => '70-80%',
                    'rainfall' => 'Khô hanh',
                    'shoe_demand' => [
                        'high' => ['Giày da', 'Sneaker', 'Boot thấp'],
                        'medium' => ['Giày tây', 'Boot cao'],
                        'low' => ['Sandal', 'Dép']
                    ],
                    'business_advice' => 'Bắt đầu mùa đông - tăng boots và giày đóng',
                    'cultural_events' => ['Ngày Nhà giáo 20/11'],
                    'shopping_behavior' => 'Mua giày ấm cho mùa đông'
                ],
                12 => [
                    'season' => 'Đông - Lạnh',
                    'temp_range' => '14-22°C',
                    'humidity' => '75-85%',
                    'rainfall' => 'Rét, sương mù',
                    'shoe_demand' => [
                        'high' => ['Boot', 'Giày đóng', 'Sneaker dài'],
                        'medium' => ['Giày da dày', 'Giày tây'],
                        'low' => ['Sandal', 'Giày hở']
                    ],
                    'business_advice' => 'Peak đông - stock đủ boots, giày ấm. Chuẩn bị Tết',
                    'cultural_events' => ['Giáng sinh', 'Tết Dương lịch'],
                    'shopping_behavior' => 'Mua giày ấm và chuẩn bị sắm Tết'
                ]
            ]
        ],
        'central' => [
            'region_name' => 'Miền Trung (Đà Nẵng, Huế, Nha Trang...)',
            'climate_type' => 'Nhiệt đới gió mùa, khắc nghiệt',
            'monthly_analysis' => [
                1 => [
                    'season' => 'Khô mát',
                    'temp_range' => '18-25°C',
                    'humidity' => '70-80%',
                    'rainfall' => 'Ít mưa, khô ráo',
                    'shoe_demand' => [
                        'high' => ['Giày da công sở', 'Sneaker', 'Giày cao gót'],
                        'medium' => ['Boots nhẹ', 'Giày vải'],
                        'low' => ['Sandal nhựa']
                    ],
                    'business_advice' => 'Thời tiết đẹp, tăng giày formal và thời trang',
                    'cultural_events' => ['Festival Huế', 'Tết Nguyên Đán'],
                    'shopping_behavior' => 'Mua giày đi chơi, du lịch trong nước'
                ],
                2 => [
                    'season' => 'Khô ấm',
                    'temp_range' => '20-28°C',
                    'humidity' => '65-75%',
                    'rainfall' => 'Rất ít mưa',
                    'shoe_demand' => [
                        'high' => ['Sneaker', 'Giày da', 'Sandal thời trang'],
                        'medium' => ['Giày vải', 'Giày thể thao'],
                        'low' => ['Boot']
                    ],
                    'business_advice' => 'Thời tiết đẹp cho du lịch - tăng giày thời trang',
                    'cultural_events' => ['Sau Tết', 'Bắt đầu mùa du lịch'],
                    'shopping_behavior' => 'Mua giày du lịch, đi biển'
                ],
                3 => [
                    'season' => 'Khô nóng',
                    'temp_range' => '23-32°C',
                    'humidity' => '60-70%',
                    'rainfall' => 'Khô hanh',
                    'shoe_demand' => [
                        'high' => ['Sandal', 'Giày thoáng', 'Dép'],
                        'medium' => ['Sneaker nhẹ', 'Giày vải'],
                        'low' => ['Boot', 'Giày da dày']
                    ],
                    'business_advice' => 'Nóng bức - focus giày thoáng và sandal',
                    'cultural_events' => ['Giỗ Tổ 10/3', 'Du lịch cao điểm'],
                    'shopping_behavior' => 'Mua sandal, giày thoáng'
                ],
                4 => [
                    'season' => 'Nóng gay gắt',
                    'temp_range' => '25-35°C',
                    'humidity' => '60-70%',
                    'rainfall' => 'Rất khô, nắng gắt',
                    'shoe_demand' => [
                        'high' => ['Sandal', 'Dép', 'Giày thoáng'],
                        'medium' => ['Sneaker lưới'],
                        'low' => ['Boot', 'Giày da', 'Giày đóng']
                    ],
                    'business_advice' => 'CẢNH BÁO nóng - chỉ bán giày siêu thoáng',
                    'cultural_events' => ['30/4 - Giải phóng', 'Du lịch biển'],
                    'shopping_behavior' => 'Chỉ mua sandal và dép'
                ],
                5 => [
                    'season' => 'Nóng đỉnh điểm',
                    'temp_range' => '27-38°C',
                    'humidity' => '55-65%',
                    'rainfall' => 'Khô cực độ, nắng như đổ lửa',
                    'shoe_demand' => [
                        'high' => ['Sandal chống nóng', 'Dép', 'Giày thoáng tối đa'],
                        'medium' => ['Sneaker lưới mỏng'],
                        'low' => ['Mọi loại giày đóng']
                    ],
                    'business_advice' => 'Nóng NHẤT Việt Nam - chỉ stock sandal/dép',
                    'cultural_events' => ['Du lịch nghỉ hè'],
                    'shopping_behavior' => 'Tránh mua sắm, ở nhà tránh nóng'
                ],
                6 => [
                    'season' => 'Nóng cực độ',
                    'temp_range' => '28-39°C',
                    'humidity' => '55-65%',
                    'rainfall' => 'Khô, gió Tây Nam nóng',
                    'shoe_demand' => [
                        'high' => ['Sandal', 'Dép', 'Giày biển'],
                        'medium' => ['Sneaker siêu thoáng'],
                        'low' => ['Giày đóng']
                    ],
                    'business_advice' => 'Nóng gay gắt - ưu tiên sandal bền, chống nóng',
                    'cultural_events' => ['Nghỉ hè', 'Du lịch Nha Trang, Quy Nhơn'],
                    'shopping_behavior' => 'Mua dép/sandal biển'
                ],
                7 => [
                    'season' => 'Nóng hạ',
                    'temp_range' => '28-38°C',
                    'humidity' => '60-70%',
                    'rainfall' => 'Khô nóng',
                    'shoe_demand' => [
                        'high' => ['Sandal', 'Dép', 'Giày thoáng'],
                        'medium' => ['Sneaker nhẹ', 'Giày vải'],
                        'low' => ['Boots', 'Giày da dày']
                    ],
                    'business_advice' => 'Cao điểm du lịch - tăng giày biển và sandal',
                    'cultural_events' => ['Du lịch biển Đà Nẵng, Nha Trang'],
                    'shopping_behavior' => 'Mua giày thoáng, du lịch'
                ],
                8 => [
                    'season' => 'Chuyển mùa',
                    'temp_range' => '26-34°C',
                    'humidity' => '70-80%',
                    'rainfall' => 'Bắt đầu có mưa',
                    'shoe_demand' => [
                        'high' => ['Sandal chống nước', 'Sneaker', 'Giày thể thao'],
                        'medium' => ['Giày da', 'Dép'],
                        'low' => ['Boot']
                    ],
                    'business_advice' => 'Chuẩn bị mùa mưa - tăng giày chống nước',
                    'cultural_events' => ['Năm học mới'],
                    'shopping_behavior' => 'Mua giày đi học'
                ],
                9 => [
                    'season' => 'Mưa bão đầu mùa',
                    'temp_range' => '24-30°C', 
                    'humidity' => '85-95%',
                    'rainfall' => 'Mưa to, bão lũ',
                    'shoe_demand' => [
                        'high' => ['Giày chống nước', 'Boots cao su', 'Sandal chống trượt'],
                        'medium' => ['Sneaker chống nước'],
                        'low' => ['Giày da', 'Giày vải thường']
                    ],
                    'business_advice' => 'QUAN TRỌNG: Stock giày chống nước, boots an toàn. Logistics khó khăn',
                    'cultural_events' => ['Mùa bão', 'Quốc khánh 2/9'],
                    'shopping_behavior' => 'Mua giày bảo hộ, chống nước, ít shopping giải trí'
                ],
                10 => [
                    'season' => 'Mưa bão cao điểm',
                    'temp_range' => '23-28°C',
                    'humidity' => '85-95%',
                    'rainfall' => 'Bão lũ nghiêm trọng',
                    'shoe_demand' => [
                        'high' => ['Boots cao su', 'Giày chống ngập', 'Giày bảo hộ'],
                        'medium' => ['Sandal chống trượt'],
                        'low' => ['Giày da', 'Sneaker thường']
                    ],
                    'business_advice' => 'CẢNH BÁO BÃO - Ưu tiên an toàn. Logistics có thể tê liệt',
                    'cultural_events' => ['Mùa bão lũ'],
                    'shopping_behavior' => 'Chỉ mua giày thiết yếu, chống nước'
                ],
                11 => [
                    'season' => 'Mưa bão cuối mùa',
                    'temp_range' => '21-27°C',
                    'humidity' => '80-90%',
                    'rainfall' => 'Mưa còn nhiều',
                    'shoe_demand' => [
                        'high' => ['Giày chống nước', 'Sneaker', 'Boots'],
                        'medium' => ['Giày da', 'Giày thể thao'],
                        'low' => ['Sandal hở']
                    ],
                    'business_advice' => 'Bão giảm dần - bắt đầu stock giày mùa khô',
                    'cultural_events' => ['Cuối mùa bão'],
                    'shopping_behavior' => 'Dần trở lại mua sắm bình thường'
                ],
                12 => [
                    'season' => 'Khô mát dần',
                    'temp_range' => '19-26°C',
                    'humidity' => '70-80%',
                    'rainfall' => 'Ít mưa, khô ráo',
                    'shoe_demand' => [
                        'high' => ['Sneaker', 'Giày da', 'Giày công sở'],
                        'medium' => ['Boots thấp', 'Giày thể thao'],
                        'low' => ['Sandal']
                    ],
                    'business_advice' => 'Mùa đẹp trở lại - tăng mọi loại giày thời trang',
                    'cultural_events' => ['Giáng sinh', 'Tết Dương lịch'],
                    'shopping_behavior' => 'Mua sắm cuối năm, quà tặng'
                ]
            ]
        ],
        'south' => [
            'region_name' => 'Miền Nam (TP.HCM, Cần Thơ, Vũng Tàu...)',
            'climate_type' => 'Nhiệt đới gió mùa, nóng quanh năm',
            'monthly_analysis' => [
                1 => [
                    'season' => 'Khô nóng',
                    'temp_range' => '22-33°C',
                    'humidity' => '65-75%',
                    'rainfall' => 'Ít mưa, nắng đẹp',
                    'shoe_demand' => [
                        'high' => ['Sandal thời trang', 'Giày cao gót', 'Sneaker thoáng'],
                        'medium' => ['Giày da nhẹ', 'Giày vải'],
                        'low' => ['Boots', 'Giày đóng dày']
                    ],
                    'business_advice' => 'Peak Tết, focus sandal cao cấp và giày thời trang',
                    'cultural_events' => ['Tết Nguyên Đán', 'Lễ hội hoa', 'Du xuân'],
                    'shopping_behavior' => 'Mua sắm Tết mạnh nhất cả nước, giày đẹp để đi chơi'
                ],
                2 => [
                    'season' => 'Khô ấm',
                    'temp_range' => '23-34°C',
                    'humidity' => '65-75%',
                    'rainfall' => 'Rất ít mưa',
                    'shoe_demand' => [
                        'high' => ['Sandal', 'Sneaker', 'Giày thời trang'],
                        'medium' => ['Giày da', 'Dép'],
                        'low' => ['Boot']
                    ],
                    'business_advice' => 'Sau Tết - giảm giá clearance, chuẩn bị hàng mùa mưa',
                    'cultural_events' => ['Sau Tết'],
                    'shopping_behavior' => 'Giảm mua sắm sau Tết'
                ],
                3 => [
                    'season' => 'Khô nóng',
                    'temp_range' => '24-35°C',
                    'humidity' => '65-75%',
                    'rainfall' => 'Ít mưa',
                    'shoe_demand' => [
                        'high' => ['Sandal', 'Dép', 'Giày thoáng'],
                        'medium' => ['Sneaker', 'Giày vải'],
                        'low' => ['Boot', 'Giày da dày']
                    ],
                    'business_advice' => 'Nóng dần - tăng sandal, giày thoáng',
                    'cultural_events' => ['Giỗ Tổ 10/3'],
                    'shopping_behavior' => 'Bắt đầu mua giày hè'
                ],
                4 => [
                    'season' => 'Khô nóng đỉnh điểm',
                    'temp_range' => '26-36°C',
                    'humidity' => '65-75%',
                    'rainfall' => 'Khô hanh',
                    'shoe_demand' => [
                        'high' => ['Sandal', 'Dép', 'Giày siêu thoáng'],
                        'medium' => ['Sneaker lưới'],
                        'low' => ['Giày đóng']
                    ],
                    'business_advice' => 'Nóng peak - chỉ bán sandal và giày thoáng',
                    'cultural_events' => ['30/4', '1/5', 'Nghỉ lễ dài'],
                    'shopping_behavior' => 'Mua giày du lịch, đi biển'
                ],
                5 => [
                    'season' => 'Bắt đầu mưa',
                    'temp_range' => '26-34°C',
                    'humidity' => '75-85%',
                    'rainfall' => 'Mưa chiều bắt đầu',
                    'shoe_demand' => [
                        'high' => ['Sandal chống nước', 'Giày chống trượt', 'Dép cao su'],
                        'medium' => ['Sneaker', 'Giày vải'],
                        'low' => ['Giày da cao cấp']
                    ],
                    'business_advice' => 'Chuyển mùa - tăng giày chống nước dần',
                    'cultural_events' => ['Nghỉ hè bắt đầu'],
                    'shopping_behavior' => 'Mua giày chống nước cho mùa mưa'
                ],
                6 => [
                    'season' => 'Mưa',
                    'temp_range' => '25-32°C',
                    'humidity' => '80-90%',
                    'rainfall' => 'Mưa chiều thường xuyên',
                    'shoe_demand' => [
                        'high' => ['Sandal chống nước', 'Dép cao su', 'Giày chống ngập'],
                        'medium' => ['Sneaker chống nước', 'Giày thoáng khí'],
                        'low' => ['Giày da cao cấp', 'Giày vải']
                    ],
                    'business_advice' => 'Mùa mưa HCM, tăng sản phẩm chống nước, giảm giày da cao cấp',
                    'cultural_events' => ['Mùa mưa', 'Nghỉ hè'],
                    'shopping_behavior' => 'Practical shopping, focus tiện dụng hơn thời trang'
                ],
                7 => [
                    'season' => 'Mưa nhiều',
                    'temp_range' => '25-32°C',
                    'humidity' => '80-90%',
                    'rainfall' => 'Mưa gần như hàng ngày',
                    'shoe_demand' => [
                        'high' => ['Sandal chống nước', 'Dép', 'Giày chống ngập'],
                        'medium' => ['Sneaker chống nước'],
                        'low' => ['Giày da', 'Giày vải']
                    ],
                    'business_advice' => 'Cao điểm mưa, focus giày chống nước và tiện dụng',
                    'cultural_events' => ['Mùa mưa'],
                    'shopping_behavior' => 'Mua giày thực dụng, chống nước'
                ],
                8 => [
                    'season' => 'Mưa dông',
                    'temp_range' => '25-32°C',
                    'humidity' => '80-90%',
                    'rainfall' => 'Mưa to, dông',
                    'shoe_demand' => [
                        'high' => ['Dép cao su', 'Sandal chống nước', 'Giày chống trượt'],
                        'medium' => ['Sneaker chống nước'],
                        'low' => ['Giày da', 'Giày vải cao cấp']
                    ],
                    'business_advice' => 'Mưa dông - ưu tiên giày an toàn, chống trượt',
                    'cultural_events' => ['Khai giảng'],
                    'shopping_behavior' => 'Mua giày đi học chống nước'
                ],
                9 => [
                    'season' => 'Mưa giảm dần',
                    'temp_range' => '25-32°C',
                    'humidity' => '75-85%',
                    'rainfall' => 'Mưa còn nhiều',
                    'shoe_demand' => [
                        'high' => ['Sandal', 'Sneaker', 'Giày thể thao'],
                        'medium' => ['Giày da', 'Dép'],
                        'low' => ['Boot']
                    ],
                    'business_advice' => 'Mưa giảm - bắt đầu tăng giày thời trang trở lại',
                    'cultural_events' => ['Quốc khánh 2/9', 'Trung thu'],
                    'shopping_behavior' => 'Mua giày cho trẻ em Trung thu'
                ],
                10 => [
                    'season' => 'Cuối mùa mưa',
                    'temp_range' => '25-32°C',
                    'humidity' => '75-85%',
                    'rainfall' => 'Mưa thưa dần',
                    'shoe_demand' => [
                        'high' => ['Sneaker', 'Sandal', 'Giày thể thao'],
                        'medium' => ['Giày da', 'Giày công sở'],
                        'low' => ['Boot']
                    ],
                    'business_advice' => 'Mưa sắp hết - tăng giày thời trang, công sở',
                    'cultural_events' => ['Phụ nữ VN 20/10'],
                    'shopping_behavior' => 'Mua giày làm quà 20/10'
                ],
                11 => [
                    'season' => 'Chuyển khô',
                    'temp_range' => '24-32°C',
                    'humidity' => '70-80%',
                    'rainfall' => 'Ít mưa',
                    'shoe_demand' => [
                        'high' => ['Sneaker', 'Giày da', 'Sandal thời trang'],
                        'medium' => ['Giày cao gót', 'Giày công sở'],
                        'low' => ['Dép cao su']
                    ],
                    'business_advice' => 'Thời tiết đẹp trở lại - tăng mọi loại giày',
                    'cultural_events' => ['Nhà giáo 20/11', 'Black Friday'],
                    'shopping_behavior' => 'Mua sắm tăng mạnh, săn sale'
                ],
                12 => [
                    'season' => 'Khô mát',
                    'temp_range' => '23-32°C',
                    'humidity' => '70-80%',
                    'rainfall' => 'Rất ít mưa',
                    'shoe_demand' => [
                        'high' => ['Sneaker', 'Giày cao gót', 'Giày da công sở'],
                        'medium' => ['Sandal thời trang', 'Giày thể thao'],
                        'low' => ['Dép rẻ']
                    ],
                    'business_advice' => 'Mùa lễ hội cuối năm - tăng giày cao cấp, thời trang',
                    'cultural_events' => ['Noel', 'Tết Dương lịch', 'Sale cuối năm'],
                    'shopping_behavior' => 'Peak mua sắm cuối năm, quà tặng'
                ]
            ]
        ]
    ];
    
    return $climate_data;
}

/**
 * Phân tích lễ hội và sự kiện Việt Nam
 */
function getVietnameseFestivalAnalysis($month) {
    $festivals = [
        1 => [
            'major_events' => [
                'Tết Nguyên Đán' => [
                    'duration' => '7-10 ngày',
                    'impact_level' => 'CRITICAL',
                    'shopping_peak' => '2 tuần trước Tết',
                    'shoe_trends' => [
                        'Giày đỏ may mắn' => '+300%',
                        'Giày da công sở nam' => '+200%',
                        'Giày cao gót nữ' => '+250%',
                        'Giày trẻ em đẹp' => '+400%'
                    ],
                    'business_strategy' => 'Tăng inventory x2.5, focus màu đỏ/vàng/đen, premium quality',
                    'pricing_strategy' => 'Có thể tăng giá 10-15% do nhu cầu cao',
                    'logistics_note' => 'Chuẩn bị stock sớm, shipper nghỉ lễ'
                ]
            ],
            'regional_differences' => [
                'north' => 'Trọng formal shoes cho lễ chúc Tết',
                'central' => 'Giày đẹp cho festival và du xuân',
                'south' => 'Peak shopping nhất, đa dạng styles'
            ]
        ],
        4 => [
            'major_events' => [
                'Giỗ Tổ Hùng Vương (10/3 AL)' => [
                    'impact_level' => 'MEDIUM',
                    'shoe_trends' => 'Giày đi lễ, formal shoes',
                    'business_strategy' => 'Tăng nhẹ giày formal'
                ],
                'Lễ 30/4 - 1/5' => [
                    'duration' => '4-5 ngày nghỉ',
                    'impact_level' => 'HIGH',
                    'shoe_trends' => [
                        'Giày du lịch' => '+150%',
                        'Sneaker thoải mái' => '+120%',
                        'Sandal biển' => '+200%'
                    ],
                    'business_strategy' => 'Focus giày du lịch, thoải mái, beach shoes',
                    'regional_note' => 'Biển miền Trung-Nam hot, giày chống nước cần thiết'
                ]
            ]
        ],
        8 => [
            'major_events' => [
                'Tết Trung Thu' => [
                    'impact_level' => 'HIGH',
                    'target_group' => 'Trẻ em và gia đình',
                    'shoe_trends' => [
                        'Giày trẻ em' => '+300%',
                        'Giày gia đình matching' => '+150%',
                        'Giày đẹp cho chụp ảnh' => '+100%'
                    ],
                    'business_strategy' => 'Tăng mạnh giày trẻ em, family sets, photo-worthy shoes'
                ],
                'Khai giảng năm học' => [
                    'impact_level' => 'HIGH',
                    'shoe_trends' => [
                        'Giày lười' => '+400%',
                        'Sneaker học đường' => '+250%',
                        'Giày tây cho teachers' => '+100%'
                    ]
                ]
            ]
        ],
        11 => [
            'major_events' => [
                'Black Friday (Global)' => [
                    'impact_level' => 'HIGH',
                    'consumer_behavior' => 'Price-sensitive, bulk buying',
                    'strategy' => 'Aggressive pricing, clear old inventory',
                    'competition_level' => 'EXTREME'
                ],
                '11.11 Shopping' => [
                    'impact_level' => 'MEDIUM',
                    'platform' => 'Online focus',
                    'strategy' => 'Online-exclusive deals'
                ]
            ]
        ],
        12 => [
            'major_events' => [
                'Giáng Sinh' => [
                    'impact_level' => 'HIGH',
                    'target_group' => 'Urban millennials, Gen Z',
                    'shoe_trends' => [
                        'Party heels' => '+200%',
                        'Winter boots (fashion)' => '+150%',
                        'Couple matching shoes' => '+100%'
                    ]
                ],
                'Năm mới dương lịch' => [
                    'impact_level' => 'HIGH',
                    'shoe_trends' => 'Party shoes, new year new shoes mentality'
                ]
            ]
        ]
    ];
    
    return $festivals[$month] ?? ['major_events' => [], 'note' => 'Tháng bình thường'];
}

/**
 * Gợi ý nhập hàng dựa trên khí hậu và tồn kho thực tế
 */
function getRestockSuggestions($conn, $warehouse_id, $month, $region) {
    try {
        // 1. Lấy thông tin khí hậu và giày HOT theo tháng
        $climate_data = getVietnamClimateAnalysis($month);
        $region_data = $climate_data[$region] ?? $climate_data['north'];
        $month_data = $region_data['monthly_analysis'][$month] ?? null;
        
        if (!$month_data) {
            return [
                'success' => false,
                'message' => 'Không có dữ liệu khí hậu cho tháng ' . $month
            ];
        }
        
        // 2. Lấy danh sách giày HOT (nhu cầu cao)
        $hot_shoes = $month_data['shoe_demand']['high'];
        
        // 2.1. Phân tích lễ hội/sự kiện trong tháng
        $events = $month_data['events'] ?? '';
        $event_boost = 1.0; // Hệ số tăng quantity
        $event_preferences = []; // Loại giày ưu tiên theo sự kiện
        
        // Phân tích các sự kiện đặc biệt
        if (strpos($events, 'Tết') !== false || strpos($events, 'Xuân') !== false) {
            // Tết → Giày đẹp, màu đỏ/vàng may mắn, sneaker cao cấp
            $event_boost = 2.5;
            $event_preferences = ['Sneaker', 'Giày tây', 'Giày thể thao'];
            $color_preference = ['đỏ', 'vàng', 'gold', 'red'];
            $event_note = 'Tết Nguyên Đán - Nhu cầu giày mới cao';
        } elseif (strpos($events, 'Black Friday') !== false || strpos($events, '11.11') !== false) {
            // Sale lớn → Tăng gấp đôi stock mọi loại
            $event_boost = 2.0;
            $event_preferences = ['Sneaker', 'Giày boot', 'Sandal'];
            $event_note = 'Black Friday/11.11 - Chuẩn bị stock cho sale lớn';
        } elseif (strpos($events, 'Trung thu') !== false) {
            // Trung thu → Giày trẻ em, gia đình
            $event_boost = 1.5;
            $event_preferences = ['Sneaker'];
            $event_note = 'Tết Trung Thu - Tăng nhu cầu giày trẻ em';
        } elseif (strpos($events, 'Lễ tình nhân') !== false || strpos($events, 'Valentine') !== false) {
            // Valentine → Giày đẹp, thời trang
            $event_boost = 1.3;
            $event_preferences = ['Sneaker', 'Giày tây'];
            $event_note = 'Valentine - Nhu cầu giày thời trang tăng';
        } elseif (strpos($events, '8/3') !== false || strpos($events, 'Phụ nữ') !== false) {
            // 8/3 → Giày nữ, cao gót, thời trang
            $event_boost = 1.4;
            $event_preferences = ['Sneaker', 'Giày cao gót'];
            $event_note = 'Ngày Quốc tế Phụ nữ - Tập trung giày nữ';
        } elseif (strpos($events, 'Khai giảng') !== false || strpos($events, 'Khai trường') !== false) {
            // Khai giảng → Giày học sinh, trẻ em
            $event_boost = 1.6;
            $event_preferences = ['Sneaker', 'Giày thể thao'];
            $event_note = 'Mùa khai giảng - Nhu cầu giày học sinh';
        } elseif (strpos($events, 'Giáng sinh') !== false || strpos($events, 'Noel') !== false) {
            // Giáng sinh → Giày đẹp, quà tặng
            $event_boost = 1.5;
            $event_preferences = ['Sneaker', 'Giày boot'];
            $event_note = 'Giáng sinh - Nhu cầu giày làm quà tặng';
        } else {
            $event_note = '';
        }
        
        // 3. Map giày HOT với type trong database (DÙNG TYPE thực tế)
        $shoe_mapping = [
            'Giày boot' => 'Giày boot',
            'Boots' => 'Giày boot',
            'Boot' => 'Giày boot',
            'Boot thấp' => 'Giày boot',
            'Boot cao' => 'Giày boot',
            'Giày đóng' => 'Sneaker',
            'Giày đóng cổ cao' => 'Sneaker',
            'Sneaker' => 'Sneaker',
            'Sneaker dài' => 'Sneaker',
            'Sneaker cao cổ' => 'Sneaker',
            'Sneaker lưới' => 'Sneaker',
            'Sneaker chống nước' => 'Sneaker',
            'Sneaker thoáng' => 'Sneaker',
            'Sandal' => 'Sandal',
            'Sandal thời trang' => 'Sandal',
            'Sandal chống nước' => 'Sandal',
            'Sandal chống trượt' => 'Sandal',
            'Sandal hở' => 'Sandal',
            'Giày biển' => 'Sandal',  // ← Thêm mapping cho "Giày biển"
            'Dép' => 'Sandal',
            'Dép cao su' => 'Sandal',
            'Dép thời trang' => 'Sandal',
            'Dép xỏ ngón' => 'Sandal',
            'Giày da' => 'Giày tây',
            'Giày da nhẹ' => 'Giày tây',
            'Giày da cao cấp' => 'Giày tây',
            'Giày tây' => 'Giày tây',
            'Giày công sở' => 'Giày tây',
            'Giày thoáng' => 'Sneaker',
            'Giày thoáng khí' => 'Sneaker',
            'Giày chống nước' => 'Sneaker',
            'Giày chống ngập' => 'Sneaker',
            'Giày cao gót' => 'Giày cao gót',
            'Giày vải' => 'Sneaker',
            'Giày thể thao' => 'Sneaker'
        ];
        
        // 4. Lấy sản phẩm thực từ database theo type + phân biệt theo vùng miền
        $suggestions = [];
        $processed_products = []; // Tránh trùng lặp
        
        // Thêm điều kiện lọc theo nhiệt độ/mùa để phân biệt vùng miền
        $temp_avg = (int)preg_replace('/[^0-9]/', '', explode('-', $month_data['temp_range'])[0]);
        $is_cold = $temp_avg < 22; // Miền Bắc mùa đông
        $is_hot = $temp_avg > 28; // Miền Nam/Trung mùa nóng
        $is_rainy = strpos($month_data['rainfall'], 'Mưa') !== false;
        
        foreach ($hot_shoes as $shoe_type) {
            // Sử dụng hàm chuẩn hóa từ api_phan_tich_giay_ai.php (API đã gộp)
            $mapped_type = $shoe_mapping[$shoe_type] ?? standardizeProductType($shoe_type);
            
            // Log để debug
            error_log("🔍 Hot shoe: '{$shoe_type}' → Mapped to: '{$mapped_type}'");
            
            // Tạo từ khóa tìm kiếm dựa trên đặc điểm khí hậu
            $search_keywords = [];
            
            // Nếu là giày "thoáng", "nhẹ" cho mùa nóng
            if (in_array($shoe_type, ['Sandal', 'Sandal thời trang', 'Giày thoáng', 'Giày thoáng khí'])) {
                $search_keywords = ['thoáng', 'nhẹ', 'mát', 'sandal', 'lưới', 'hở', '通気', 'mesh', 'breathable'];
            }
            // Nếu là giày "ấm", "kín" cho mùa lạnh
            elseif (in_array($shoe_type, ['Giày boot', 'Boot', 'Giày đóng', 'Sneaker'])) {
                $search_keywords = ['boot', 'cao cổ', 'ấm', 'kín', 'chống nước', 'da', 'warm', 'waterproof'];
            }
            // Nếu là giày chống nước cho mùa mưa
            elseif ($is_rainy) {
                $search_keywords = ['chống nước', 'không thấm', 'mưa', 'waterproof', 'chống trượt', 'cao su'];
            }
            
            // Query lấy sản phẩm THẬT từ warehouse - TÌM KIẾM LINH HOẠT theo mô tả
            $sql = "SELECT 
                        p.product_id,
                        p.name as product_name,
                        p.type as product_type,
                        p.description,
                        p.brand,
                        COALESCE(pi.file_path, 'default-shoe.jpg') as image_url,
                        COALESCE(MIN(pv.price), 0) as price,
                        COALESCE(SUM(i.quantity), 0) as current_stock,
                        COALESCE(COUNT(DISTINCT o.order_id), 0) as total_orders,
                        COALESCE(SUM(od.quantity), 0) as total_sold
                    FROM products p
                    LEFT JOIN product_variants pv ON p.product_id = pv.product_id
                    LEFT JOIN inventory i ON pv.variant_id = i.variant_id AND i.warehouse_id = :wh_id1
                    LEFT JOIN product_images pi ON p.product_id = pi.product_id AND pi.is_primary = 1
                    LEFT JOIN order_details od ON pv.variant_id = od.variant_id
                    LEFT JOIN orders o ON od.order_id = o.order_id 
                        AND o.warehouse_id = :wh_id2
                        AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                        AND o.status = 'delivered'
                    WHERE p.warehouse_id = :wh_id3
                        AND (
                            p.type LIKE :product_type 
                            OR p.name LIKE :product_type
                            OR p.description LIKE :product_type
                        )
                        AND p.status = 'active'
                    GROUP BY p.product_id, p.name, p.type, p.description, p.brand, pi.file_path
                    ORDER BY total_sold DESC, current_stock ASC
                    LIMIT 15";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(':wh_id1', $warehouse_id);
            $stmt->bindParam(':wh_id2', $warehouse_id);
            $stmt->bindParam(':wh_id3', $warehouse_id);
            $type_pattern = '%' . $mapped_type . '%';
            $stmt->bindParam(':product_type', $type_pattern);
            $stmt->execute();
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                // Tránh sản phẩm trùng lặp
                if (isset($processed_products[$row['product_id']])) {
                    continue;
                }
                
                // Chuẩn hóa product type từ database
                $product_type_standardized = standardizeProductType($row['product_type']);
                $product_name_lower = strtolower($row['product_name'] . ' ' . $row['description']);
                $product_type_lower = strtolower($product_type_standardized);
                $skip = false;
                
                // LOGIC MỚI: So sánh theo loại SẢN PHẨM + ĐẶC ĐIỂM trong mô tả
                $is_match = false;
                $match_reason = '';
                
                // Lấy mô tả sản phẩm để kiểm tra đặc điểm
                $description_lower = strtolower($row['description']);
                
                // 1. KIỂM TRA EXACT MATCH theo product_type
                if ($mapped_type === $product_type_standardized) {
                    $is_match = true;
                    $match_reason = 'Exact type match';
                    error_log("✅ EXACT MATCH: '{$shoe_type}' → '{$mapped_type}' === '{$product_type_standardized}' (Product: {$row['product_name']})");
                }
                
                // 2. KIỂM TRA THEO ĐẶC ĐIỂM KHÍ HẬU trong mô tả (QUAN TRỌNG!)
                // Nếu sản phẩm có các đặc điểm phù hợp với khí hậu → cho phép gợi ý
                if (!$is_match && !empty($search_keywords)) {
                    foreach ($search_keywords as $keyword) {
                        if (strpos($product_name_lower, $keyword) !== false || 
                            strpos($description_lower, $keyword) !== false) {
                            $is_match = true;
                            $match_reason = "Có đặc điểm phù hợp: '{$keyword}'";
                            error_log("✅ DESCRIPTION MATCH: '{$keyword}' found in '{$row['product_name']}' (Type: {$product_type_standardized})");
                            break;
                        }
                    }
                }
                
                // 3. Fallback: Kiểm tra partial match trong tên sản phẩm/type
                if (!$is_match) {
                    $shoe_type_lower = strtolower($shoe_type);
                    
                    // 1. SANDAL/DÉP/GIÀY BIỂN
                    if (in_array($mapped_type, ['Sandal'])) {
                        if (strpos($product_name_lower, 'sandal') !== false || 
                            strpos($product_name_lower, 'dép') !== false ||
                            strpos($product_type_lower, 'sandal') !== false) {
                            $is_match = true;
                            $match_reason = 'Partial sandal match';
                        }
                    }
                    // 2. BOOT
                    elseif (in_array($mapped_type, ['Giày boot', 'Boot'])) {
                        if (strpos($product_name_lower, 'boot') !== false || 
                            strpos($product_name_lower, 'bốt') !== false) {
                            $is_match = true;
                            $match_reason = 'Partial boot match';
                        }
                    }
                    // 3. SNEAKER
                    elseif ($mapped_type === 'Sneaker') {
                        if (strpos($product_name_lower, 'sneaker') !== false ||
                            strpos($product_name_lower, 'thể thao') !== false ||
                            // Nếu không có các từ khóa loại giày khác → có thể là sneaker
                            (strpos($product_name_lower, 'sandal') === false && 
                             strpos($product_name_lower, 'boot') === false &&
                             strpos($product_name_lower, 'dép') === false &&
                             strpos($product_name_lower, 'cao gót') === false &&
                             strpos($product_name_lower, 'tây') === false)) {
                            $is_match = true;
                            $match_reason = 'Partial sneaker match';
                        }
                    }
                    // 4. Các loại khác
                    else {
                        if (strpos($product_type_lower, strtolower($mapped_type)) !== false) {
                            $is_match = true;
                            $match_reason = 'Partial type match';
                        }
                    }
                }
                
                // Nếu không match → skip ngay
                if (!$is_match) {
                    $skip = true;
                    error_log("❌ NO MATCH: '{$shoe_type}' → '{$mapped_type}' !== '{$product_type_standardized}' (Product: {$row['product_name']}) [Reason: No match found]");
                }
                
                // LOẠI TRỪ các sản phẩm KHÔNG PHÙ HỢP với đặc điểm gợi ý
                // Ví dụ: Nếu gợi ý "Giày thoáng", loại bỏ giày cao gót (dù có từ "sandal" trong tên)
                if (!$skip) {
                    // Nếu gợi ý giày "thoáng, nhẹ" (sandal) → CHỈ loại bỏ giày cao gót
                    // KHÔNG loại bỏ đế xuồng vì đế xuồng có thể thoáng nếu có các đặc điểm phù hợp
                    if (in_array($mapped_type, ['Sandal']) || in_array($shoe_type, ['Giày thoáng', 'Giày thoáng khí'])) {
                        // CHỈ loại bỏ giày cao gót THẬT SỰ (không phải đế xuồng)
                        if ((strpos($product_type_lower, 'cao gót') !== false && strpos($product_type_lower, 'đế xuồng') === false) || 
                            (strpos($product_name_lower, 'cao gót') !== false && strpos($product_name_lower, 'đế xuồng') === false)) {
                            $skip = true;
                            error_log("❌ FILTER OUT: Giày cao gót không phù hợp với gợi ý '{$shoe_type}' (Product: {$row['product_name']})");
                        }
                    }
                    
                    // Nếu gợi ý giày "ấm" (boot) → loại bỏ sandal/dép hở (nhưng không loại đế xuồng kín)
                    if (in_array($mapped_type, ['Giày boot', 'Boot'])) {
                        // Chỉ loại sandal hở, KHÔNG loại đế xuồng
                        if ((strpos($product_type_lower, 'sandal') !== false && strpos($product_type_lower, 'đế xuồng') === false) || 
                            strpos($product_type_lower, 'dép') !== false) {
                            $skip = true;
                            error_log("❌ FILTER OUT: Sandal/Dép hở không phù hợp với gợi ý '{$shoe_type}' (Product: {$row['product_name']})");
                        }
                    }
                }
                
                // Lọc THÊM theo khí hậu (chỉ áp dụng khi nhiệt độ cực đoan)
                // Miền Bắc RẤT lạnh (<15°C) → tránh sandal hở
                if (!$skip && $temp_avg < 15 && (strpos($product_name_lower, 'sandal') !== false || strpos($product_name_lower, 'dép') !== false)) {
                    $skip = true;
                    error_log("❌ CLIMATE FILTER: Quá lạnh cho sandal (Product: {$row['product_name']})");
                }
                
                // Miền nóng CHÁY (>35°C) → tránh boots dày
                if (!$skip && $temp_avg > 35 && (strpos($product_name_lower, 'boot') !== false)) {
                    $skip = true;
                    error_log("❌ CLIMATE FILTER: Quá nóng cho boot (Product: {$row['product_name']})");
                }
                
                // Mùa mưa → ưu tiên chống nước
                if (!$skip && $is_rainy && strpos($shoe_type, 'chống nước') !== false) {
                    // Ưu tiên sản phẩm có từ "chống nước"
                    if (strpos($product_name_lower, 'chống nước') === false && 
                        strpos($product_name_lower, 'cao su') === false &&
                        strpos($description_lower, 'chống nước') === false &&
                        strpos($description_lower, 'không thấm') === false) {
                        $skip = true;
                        error_log("❌ CLIMATE FILTER: Không chống nước trong mùa mưa (Product: {$row['product_name']})");
                    }
                }
                
                if ($skip) {
                    continue;
                }
                
                $processed_products[$row['product_id']] = true;
                
                // Tính toán mức độ ưu tiên - RELAXED để hiển thị nhiều sản phẩm hơn
                $stock_level = (int)$row['current_stock'];
                $sales_velocity = (int)$row['total_sold'];
                $priority = 'MEDIUM';
                
                if ($stock_level < 20 && $sales_velocity > 20) {
                    $priority = 'CRITICAL';
                } elseif ($stock_level < 50 && $sales_velocity > 10) {
                    $priority = 'HIGH';
                } elseif ($stock_level < 150) {  // Tăng từ 100 lên 150
                    $priority = 'MEDIUM';
                } else {
                    // Stock cao, vẫn hiển thị nhưng priority LOW
                    $priority = 'LOW';
                    // Không skip nữa, vẫn hiển thị
                }
                
                // Tính số lượng nên nhập
                $avg_daily_sales = $sales_velocity > 0 ? $sales_velocity / 30 : 0.5;
                $days_of_stock = $stock_level > 0 ? $stock_level / max($avg_daily_sales, 0.1) : 0;
                $base_quantity = max(50, ceil($avg_daily_sales * 60)); // 60 ngày stock cơ bản
                
                // ÁP DỤNG HỆ SỐ LỄ HỘI/SỰ KIỆN
                $suggested_quantity = ceil($base_quantity * $event_boost);
                
                // Kiểm tra ưu tiên màu sắc (nếu có)
                $color_boost = 1.0;
                if (!empty($color_preference)) {
                    $product_color = strtolower($row['product_name'] . ' ' . $row['description']);
                    foreach ($color_preference as $preferred_color) {
                        if (strpos($product_color, $preferred_color) !== false) {
                            $color_boost = 1.3; // Tăng 30% cho màu may mắn/hot
                            break;
                        }
                    }
                    $suggested_quantity = ceil($suggested_quantity * $color_boost);
                }
                
                // Kiểm tra nếu sản phẩm thuộc loại ưu tiên sự kiện
                $event_priority_boost = false;
                if (!empty($event_preferences)) {
                    foreach ($event_preferences as $preferred_type) {
                        if (strpos($row['product_type'], $preferred_type) !== false) {
                            $event_priority_boost = true;
                            // Tăng priority nếu trùng sự kiện
                            if ($priority === 'MEDIUM') $priority = 'HIGH';
                            elseif ($priority === 'HIGH') $priority = 'CRITICAL';
                            break;
                        }
                    }
                }
                
                // Thêm điểm đặc trưng theo vùng miền
                $region_note = '';
                if ($is_cold) $region_note = ' - Phù hợp khí hậu lạnh';
                if ($is_hot) $region_note = ' - Phù hợp khí hậu nóng';
                if ($is_rainy) $region_note = ' - Phù hợp mùa mưa';
                
                // Tạo lý do chi tiết
                $full_reason = "Phù hợp với khí hậu tháng $month: {$month_data['season']} ({$month_data['temp_range']})" . $region_note;
                if ($event_note) {
                    $full_reason .= " | 🎉 " . $event_note;
                    if ($color_boost > 1.0) {
                        $full_reason .= " (Màu hot!)";
                    }
                }
                
                $suggestions[] = [
                    'product_id' => $row['product_id'],
                    'product_name' => $row['product_name'],
                    'image_url' => $row['image_url'] ?: 'default-shoe.jpg',
                    'category' => $product_type_standardized ?: 'Sneaker',  // Sử dụng type đã chuẩn hóa
                    'current_stock' => $stock_level,
                    'total_sold_30days' => $sales_velocity,
                    'priority' => $priority,
                    'suggested_quantity' => (int)$suggested_quantity,
                    'days_of_stock_remaining' => round($days_of_stock, 1),
                    'reason' => $full_reason,
                    'demand_type' => $shoe_type,
                    'price' => (float)$row['price'],
                    'brand' => $row['brand'] ?: '',
                    'region' => $region,
                    'event_boost' => $event_boost,
                    'is_event_priority' => $event_priority_boost
                ];
                
                // Giới hạn số lượng gợi ý mỗi loại giày (tăng lên 3-4 sản phẩm/loại)
                $current_type_count = count(array_filter($suggestions, function($s) use ($shoe_type) {
                    return $s['demand_type'] === $shoe_type;
                }));
                
                if ($current_type_count >= 3) {
                    break;
                }
            }
        }
        
        // 5. Sắp xếp theo mức độ ưu tiên
        $priority_order = ['CRITICAL' => 0, 'HIGH' => 1, 'MEDIUM' => 2];
        usort($suggestions, function($a, $b) use ($priority_order) {
            $priority_diff = $priority_order[$a['priority']] - $priority_order[$b['priority']];
            if ($priority_diff != 0) return $priority_diff;
            // Nếu cùng priority, ưu tiên stock thấp hơn
            return $a['current_stock'] - $b['current_stock'];
        });
        
        return [
            'success' => true,
            'data' => [
                'month' => (int)$month,
                'region' => $region_data['region_name'],
                'season' => $month_data['season'],
                'temperature' => $month_data['temp_range'],
                'hot_shoe_types' => $hot_shoes,
                'suggestions' => $suggestions, // Không giới hạn ở đây
                'total_suggestions' => count($suggestions),
                'business_advice' => $month_data['business_advice']
            ]
        ];
        
    } catch (Exception $e) {
        error_log('getRestockSuggestions Error: ' . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Lỗi: ' . $e->getMessage()
        ];
    }
}

/**
 * Gợi ý nhập hàng dựa trên TỒN KHO THẤP
 * Không phụ thuộc khí hậu/sự kiện, chỉ dựa vào stock và sales velocity
 */
function getLowStockSuggestions($conn, $warehouse_id, $priority_filter = null) {
    try {
        error_log("🔍 getLowStockSuggestions called: warehouse_id=$warehouse_id, priority=$priority_filter");
        $suggestions = [];
        
        // Query lấy sản phẩm tồn kho thấp
        $sql = "SELECT 
                    p.product_id,
                    p.name as product_name,
                    p.type as product_type,
                    p.description,
                    p.brand,
                    COALESCE(pi.file_path, 'default-shoe.jpg') as image_url,
                    COALESCE(MIN(pv.price), 0) as price,
                    COALESCE(SUM(i.quantity), 0) as current_stock,
                    COALESCE(SUM(od.quantity), 0) as total_sold
                FROM products p
                LEFT JOIN product_variants pv ON p.product_id = pv.product_id
                LEFT JOIN inventory i ON pv.variant_id = i.variant_id AND i.warehouse_id = :wh_id1
                LEFT JOIN product_images pi ON p.product_id = pi.product_id AND pi.is_primary = 1
                LEFT JOIN order_details od ON pv.variant_id = od.variant_id
                LEFT JOIN orders o ON od.order_id = o.order_id 
                    AND o.warehouse_id = :wh_id2
                    AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                    AND o.status = 'delivered'
                WHERE p.warehouse_id = :wh_id3
                    AND p.status = 'active'
                GROUP BY p.product_id, p.name, p.type, p.description, p.brand, pi.file_path
                HAVING COALESCE(SUM(i.quantity), 0) < 100
                ORDER BY 
                    CASE 
                        WHEN COALESCE(SUM(i.quantity), 0) < 20 AND COALESCE(SUM(od.quantity), 0) > 20 THEN 1
                        WHEN COALESCE(SUM(i.quantity), 0) < 50 AND COALESCE(SUM(od.quantity), 0) > 10 THEN 2
                        WHEN COALESCE(SUM(i.quantity), 0) < 100 THEN 3
                        ELSE 4
                    END,
                    COALESCE(SUM(i.quantity), 0) ASC,
                    COALESCE(SUM(od.quantity), 0) DESC
                LIMIT 50";
        
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':wh_id1', $warehouse_id);
        $stmt->bindParam(':wh_id2', $warehouse_id);
        $stmt->bindParam(':wh_id3', $warehouse_id);
        $stmt->execute();
        
        $row_count = $stmt->rowCount();
        error_log("📊 Query returned $row_count rows");
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $stock_level = (int)$row['current_stock'];
            $sales_velocity = (int)$row['total_sold'];
            
            // Chuẩn hóa product type
            $low_stock_type_standardized = standardizeProductType($row['product_type']);
            
            // Xác định priority
            $priority = 'LOW';
            if ($stock_level < 20 && $sales_velocity > 20) {
                $priority = 'CRITICAL';
                $reason = 'Tồn RẤT THẤP + Bán CHẠY → Cần nhập GẤP!';
            } elseif ($stock_level < 50 && $sales_velocity > 10) {
                $priority = 'HIGH';
                $reason = 'Tồn thấp + Bán chạy → Nên nhập sớm';
            } elseif ($stock_level < 100) {
                $priority = 'MEDIUM';
                $reason = 'Tồn kho trung bình → Cân nhắc nhập thêm';
            } else {
                continue; // Skip stock cao
            }
            
            // Filter theo priority nếu có
            if ($priority_filter && $priority !== $priority_filter) {
                continue;
            }
            
            // Tính số lượng nên nhập
            $avg_daily_sales = $sales_velocity > 0 ? $sales_velocity / 30 : 0.5;
            $days_of_stock = $stock_level > 0 ? $stock_level / max($avg_daily_sales, 0.1) : 0;
            $suggested_quantity = max(50, ceil($avg_daily_sales * 60)); // 60 ngày stock
            
            $suggestions[] = [
                'product_id' => $row['product_id'],
                'product_name' => $row['product_name'],
                'image_url' => $row['image_url'] ?: 'default-shoe.jpg',
                'category' => $low_stock_type_standardized ?: 'Sneaker',  // Sử dụng type đã chuẩn hóa
                'current_stock' => $stock_level,
                'total_sold_30days' => $sales_velocity,
                'priority' => $priority,
                'suggested_quantity' => (int)$suggested_quantity,
                'days_of_stock_remaining' => round($days_of_stock, 1),
                'reason' => $reason,
                'price' => (float)$row['price'],
                'brand' => $row['brand'] ?: '',
                'avg_daily_sales' => round($avg_daily_sales, 2)
            ];
        }
        
        $total = count($suggestions);
        error_log("✅ getLowStockSuggestions returning $total suggestions for priority: " . ($priority_filter ?: 'ALL'));
        
        return [
            'success' => true,
            'data' => [
                'priority' => $priority_filter ?: 'ALL',
                'suggestions' => $suggestions,
                'total_suggestions' => $total
            ]
        ];
        
    } catch (Exception $e) {
        error_log('getLowStockSuggestions Error: ' . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Lỗi: ' . $e->getMessage()
        ];
    }
}

/**
 * Tạo khuyến nghị theo thực tế VN
 */
function generateInventoryRecommendationsVN($category, $turnover, $days_inventory, $margin, $month) {
    $recommendations = [];
    
    // Khuyến nghị dựa trên turnover
    if ($turnover < 0.5) {
        $recommendations[] = [
            'type' => 'URGENT',
            'action' => 'Thanh lý hoặc khuyến mãi mạnh',
            'reason' => "Vòng quay quá chậm ({$turnover}), hàng có nguy cơ ế",
            'timeline' => 'Trong 2 tuần',
            'expected_impact' => 'Giảm tồn kho 30-50%'
        ];
    } elseif ($turnover > 3) {
        $recommendations[] = [
            'type' => 'OPPORTUNITY',
            'action' => 'Tăng đầu tư và stock',
            'reason' => "Vòng quay rất nhanh ({$turnover}), cơ hội tăng doanh thu",
            'timeline' => 'Ngay lập tức',
            'expected_impact' => 'Tăng revenue 20-40%'
        ];
    }
    
    // Khuyến nghị theo mùa Việt Nam
    $seasonal_advice = getSeasonalAdviceVN($category, $month);
    if ($seasonal_advice) {
        $recommendations[] = $seasonal_advice;
    }
    
    // Khuyến nghị margin
    if ($margin < 25) {
        $recommendations[] = [
            'type' => 'PRICING',
            'action' => 'Review pricing strategy',
            'reason' => "Margin thấp ({$margin}%), cần tối ưu giá bán hoặc cost",
            'timeline' => 'Trong tháng',
            'expected_impact' => 'Cải thiện profitability'
        ];
    }
    
    return $recommendations;
}

/**
 * Lời khuyên theo mùa VN
 */
function getSeasonalAdviceVN($category, $month) {
    $advice_map = [
        'Sandal' => [
            1 => ['type' => 'REDUCE', 'action' => 'Giảm stock sandal sau Tết', 'reason' => 'Thời tiết lạnh miền Bắc'],
            3 => ['type' => 'INCREASE', 'action' => 'Chuẩn bị stock cho mùa hè', 'reason' => 'Sắp vào mùa nóng'],
            6 => ['type' => 'MAXIMIZE', 'action' => 'Tối đa stock sandal', 'reason' => 'Peak mùa hè'],
            10 => ['type' => 'CLEARANCE', 'action' => 'Thanh lý sandal', 'reason' => 'Kết thúc mùa hè']
        ],
        'Giày cao gót' => [
            12 => ['type' => 'INCREASE', 'action' => 'Tăng stock giày cao gót', 'reason' => 'Mùa tiệc cuối năm'],
            1 => ['type' => 'PEAK', 'action' => 'Đảm bảo stock đầy đủ', 'reason' => 'Peak Tết']
        ],
        'Boots' => [
            11 => ['type' => 'INCREASE', 'action' => 'Nhập boots cho mùa lạnh', 'reason' => 'Chuẩn bị đông Bắc Bộ'],
            3 => ['type' => 'CLEARANCE', 'action' => 'Thanh lý boots', 'reason' => 'Kết thúc mùa lạnh']
        ]
    ];
    
    return $advice_map[$category][$month] ?? null;
}

/**
 * Tính tổng kết inventory
 */
function calculateInventorySummaryVN($categories_data) {
    $total_ending_value = array_sum(array_column($categories_data, 'ending_stock')['value'] ?? []);
    $total_revenue = array_sum(array_column($categories_data, 'sales')['revenue'] ?? []);
    $avg_margin = array_sum(array_column($categories_data, 'kpi_metrics')['gross_margin_percent'] ?? []) / count($categories_data);
    
    return [
        'total_inventory_value' => $total_ending_value,
        'total_monthly_revenue' => $total_revenue,
        'average_gross_margin' => round($avg_margin, 1),
        'overall_health' => $avg_margin > 30 ? 'Healthy' : 'Needs Improvement',
        'vietnam_benchmark' => [
            'typical_shoe_margin' => '30-60%',
            'good_turnover' => '2-4 times/month',
            'optimal_inventory_days' => '15-30 days'
        ]
    ];
}

// ============ Helper Functions ============

/**
 * Lấy thông tin khí hậu theo vùng miền và tháng
 */
function getClimateByRegionAndMonth($month) {
    $climate = [
        'north' => [
            1 => ['season' => 'Đông', 'temp' => '10-15°C', 'note' => 'Lạnh, khô'],
            2 => ['season' => 'Xuân', 'temp' => '15-20°C', 'note' => 'Mát mẻ, mưa phùn'],
            3 => ['season' => 'Xuân', 'temp' => '20-25°C', 'note' => 'Ấm áp'],
            4 => ['season' => 'Xuân/Hè', 'temp' => '25-30°C', 'note' => 'Nóng dần'],
            5 => ['season' => 'Hè', 'temp' => '28-35°C', 'note' => 'Nóng, mưa dông'],
            6 => ['season' => 'Hè', 'temp' => '30-35°C', 'note' => 'Nóng ẩm'],
            7 => ['season' => 'Hè', 'temp' => '30-35°C', 'note' => 'Nóng, mưa nhiều'],
            8 => ['season' => 'Hè/Thu', 'temp' => '28-32°C', 'note' => 'Nóng ẩm'],
            9 => ['season' => 'Thu', 'temp' => '25-30°C', 'note' => 'Mát mẻ'],
            10 => ['season' => 'Thu', 'temp' => '22-27°C', 'note' => 'Mát, dễ chịu'],
            11 => ['season' => 'Đông', 'temp' => '18-23°C', 'note' => 'Lạnh dần'],
            12 => ['season' => 'Đông', 'temp' => '12-18°C', 'note' => 'Lạnh, khô']
        ],
        'central' => [
            1 => ['season' => 'Đông', 'temp' => '18-22°C', 'note' => 'Mát'],
            2 => ['season' => 'Xuân', 'temp' => '20-25°C', 'note' => 'Ấm'],
            3 => ['season' => 'Xuân', 'temp' => '23-28°C', 'note' => 'Nóng dần'],
            4 => ['season' => 'Hè', 'temp' => '27-32°C', 'note' => 'Nóng'],
            5 => ['season' => 'Hè', 'temp' => '30-35°C', 'note' => 'Nóng, khô'],
            6 => ['season' => 'Hè', 'temp' => '32-38°C', 'note' => 'Rất nóng'],
            7 => ['season' => 'Hè', 'temp' => '32-38°C', 'note' => 'Rất nóng'],
            8 => ['season' => 'Thu', 'temp' => '28-33°C', 'note' => 'Nóng, mưa'],
            9 => ['season' => 'Thu', 'temp' => '25-30°C', 'note' => 'Mưa bão'],
            10 => ['season' => 'Thu', 'temp' => '23-27°C', 'note' => 'Mưa nhiều'],
            11 => ['season' => 'Đông', 'temp' => '20-25°C', 'note' => 'Mát, mưa'],
            12 => ['season' => 'Đông', 'temp' => '18-23°C', 'note' => 'Mát']
        ],
        'south' => [
            1 => ['season' => 'Khô', 'temp' => '25-32°C', 'note' => 'Nóng, khô'],
            2 => ['season' => 'Khô', 'temp' => '27-33°C', 'note' => 'Nóng, khô'],
            3 => ['season' => 'Khô', 'temp' => '28-34°C', 'note' => 'Rất nóng'],
            4 => ['season' => 'Khô/Mưa', 'temp' => '28-34°C', 'note' => 'Nóng, mưa rào'],
            5 => ['season' => 'Mưa', 'temp' => '27-32°C', 'note' => 'Mưa nhiều'],
            6 => ['season' => 'Mưa', 'temp' => '26-31°C', 'note' => 'Mưa nhiều'],
            7 => ['season' => 'Mưa', 'temp' => '26-31°C', 'note' => 'Mưa nhiều'],
            8 => ['season' => 'Mưa', 'temp' => '26-31°C', 'note' => 'Mưa nhiều'],
            9 => ['season' => 'Mưa', 'temp' => '26-31°C', 'note' => 'Mưa nhiều'],
            10 => ['season' => 'Mưa', 'temp' => '26-30°C', 'note' => 'Mưa dần giảm'],
            11 => ['season' => 'Mưa/Khô', 'temp' => '26-30°C', 'note' => 'Chuyển mùa'],
            12 => ['season' => 'Khô', 'temp' => '25-31°C', 'note' => 'Khô ráo']
        ]
    ];
    
    return [
        'north' => $climate['north'][$month] ?? [],
        'central' => $climate['central'][$month] ?? [],
        'south' => $climate['south'][$month] ?? [],
        'general' => ['month' => $month, 'description' => 'Khí hậu đa dạng theo vùng miền']
    ];
}

/**
 * Tính hệ số điều chỉnh dựa trên khí hậu
 */
function getClimateFactor($category, $month, $region) {
    // Định nghĩa ảnh hưởng khí hậu đến từng loại sản phẩm
    $factors = [
        'Sneaker' => ['base' => 1.0, 'summer' => 1.2, 'winter' => 0.9],
        'Giày cao gót' => ['base' => 1.0, 'summer' => 0.8, 'winter' => 1.3],
        'Sandal' => ['base' => 1.0, 'summer' => 1.8, 'winter' => 0.3],
        'Giày boot' => ['base' => 1.0, 'summer' => 0.2, 'winter' => 2.5],
        'Giày tây' => ['base' => 1.0, 'summer' => 0.7, 'winter' => 1.4]
    ];
    
    // Xác định mùa
    $season = 'base';
    if (in_array($month, [6, 7, 8, 9])) $season = 'summer';
    if (in_array($month, [11, 12, 1, 2])) $season = 'winter';
    
    return $factors[$category][$season] ?? 1.0;
}

/**
 * Tạo recommendation thông minh
 */
function generateRecommendation($category, $change_percent, $month) {
    if ($change_percent > 30) {
        return "Nhu cầu tăng mạnh (+{$change_percent}%)! Đề xuất: Tăng lượng nhập hàng {$category} lên 40-50% so với tháng trước. Chuẩn bị chiến dịch marketing để tận dụng xu hướng.";
    } elseif ($change_percent > 10) {
        return "Nhu cầu tăng nhẹ (+{$change_percent}%). Đề xuất: Tăng lượng nhập hàng {$category} thêm 15-20%.";
    } elseif ($change_percent < -20) {
        return "Nhu cầu giảm mạnh ({$change_percent}%)! Đề xuất: Giảm lượng nhập hàng. Chạy chương trình giảm giá 30-40% để xả hàng tồn kho {$category}.";
    } elseif ($change_percent < 0) {
        return "Nhu cầu giảm nhẹ ({$change_percent}%). Đề xuất: Giảm lượng nhập hàng {$category} xuống 10-15%.";
    } else {
        return "Nhu cầu ổn định. Đề xuất: Duy trì mức nhập hàng hiện tại cho {$category}.";
    }
}

/**
 * Xử lý dữ liệu theo mùa
 */
function processSeasonalData($data) {
    // Nhóm by category and month
    $processed = [];
    foreach ($data as $row) {
        $category = $row['category_name'];
        $month = $row['month'];
        
        if (!isset($processed[$category])) {
            $processed[$category] = [
                'category' => $category,
                'monthly_data' => []
            ];
        }
        
        $processed[$category]['monthly_data'][] = [
            'month' => $month,
            'year' => $row['year'],
            'quantity' => (int)$row['quantity_sold'],
            'revenue' => (float)$row['revenue'],
            'orders' => (int)$row['orders']
        ];
    }
    
    return array_values($processed);
}

/**
 * Tạo recommendation cho sự kiện
 */
function generateEventRecommendation($event) {
    $impact_text = [
        'very_high' => 'rất cao',
        'high' => 'cao',
        'medium' => 'trung bình'
    ];
    
    $categories = implode(', ', $event['categories']);
    $impact = $impact_text[$event['impact']];
    
    return "Sự kiện '{$event['name']}' có mức độ ảnh hưởng {$impact}. Đề xuất tăng cường tồn kho cho các dòng: {$categories}. Nên chuẩn bị trước 2-3 tuần.";
}

?>