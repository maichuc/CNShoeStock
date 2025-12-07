<?php
/**
 * API: Trend & Seasonality Analysis
 * Module 1 của AI Analytics Dashboard
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$database = new Database();
$pdo = $database->getConnection();
$warehouseId = $_SESSION['warehouse_id'];

try {
    // 1. Xu hướng theo tháng
    $monthlyTrends = getMonthlyTrends($pdo, $warehouseId);
    
    // 2. Xu hướng mùa vụ
    $seasonalTrends = getSeasonalTrends($pdo, $warehouseId);
    
    // 3. Xu hướng thương hiệu
    $brandTrends = getBrandTrends($pdo, $warehouseId);
    
    // 4. Xu hướng màu sắc/size
    $colorSizeTrends = getColorSizeTrends($pdo, $warehouseId);
    
    // 5. AI Insights generation
    $aiInsights = generateTrendInsights($monthlyTrends, $seasonalTrends, $brandTrends);
    
    $response = [
        'success' => true,
        'data' => [
            'monthly_trends' => $monthlyTrends,
            'seasonal_trends' => $seasonalTrends,
            'brand_trends' => $brandTrends,
            'color_size_trends' => $colorSizeTrends,
            'ai_insights' => $aiInsights,
            'charts_data' => [
                'seasonal_chart' => formatSeasonalChartData($seasonalTrends),
                'brand_chart' => formatBrandChartData($brandTrends)
            ]
        ],
        'timestamp' => time()
    ];
    
    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Trend analysis failed: ' . $e->getMessage()
    ]);
}

function getMonthlyTrends($pdo, $warehouseId) {
    $sql = "SELECT 
                YEAR(o.created_at) as year,
                MONTH(o.created_at) as month,
                p.type as category_name,
                COUNT(od.order_detail_id) as total_orders,
                SUM(od.quantity) as total_quantity,
                SUM(od.quantity * od.unit_price) as total_revenue,
                AVG(od.quantity) as avg_quantity_per_order
            FROM orders o
            JOIN order_details od ON o.order_id = od.order_id
            JOIN product_variants pv ON od.variant_id = pv.variant_id
            JOIN products p ON pv.product_id = p.product_id
            WHERE p.warehouse_id = :warehouse_id
                AND o.status = 'delivered'
                AND o.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
            GROUP BY year, month, p.type
            ORDER BY year DESC, month DESC, total_quantity DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
    $stmt->execute();
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getSeasonalTrends($pdo, $warehouseId) {
    $sql = "SELECT 
                CASE 
                    WHEN MONTH(o.created_at) IN (3,4,5) THEN 'Mùa xuân'
                    WHEN MONTH(o.created_at) IN (6,7,8) THEN 'Mùa hè'
                    WHEN MONTH(o.created_at) IN (9,10,11) THEN 'Mùa thu'
                    ELSE 'Mùa đông'
                END as season,
                p.type as category_name,
                COUNT(od.order_detail_id) as total_orders,
                SUM(od.quantity) as total_quantity,
                SUM(od.quantity * od.unit_price) as total_revenue,
                AVG(od.quantity * od.unit_price) as avg_order_value
            FROM orders o
            JOIN order_details od ON o.order_id = od.order_id
            JOIN product_variants pv ON od.variant_id = pv.variant_id
            JOIN products p ON pv.product_id = p.product_id
            WHERE p.warehouse_id = :warehouse_id
                AND o.status = 'delivered'
                AND o.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
            GROUP BY season, p.type
            ORDER BY total_quantity DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
    $stmt->execute();
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getBrandTrends($pdo, $warehouseId) {
    $sql = "SELECT 
                SUBSTRING_INDEX(p.name, ' ', 1) as brand_name,
                COUNT(od.order_detail_id) as total_orders,
                SUM(od.quantity) as total_quantity,
                SUM(od.quantity * od.unit_price) as total_revenue,
                (SUM(od.quantity * od.unit_price) - SUM(od.quantity * pv.cost_price)) as profit,
                AVG(od.unit_price) as avg_selling_price,
                
                -- Tính tăng trưởng so với cùng kỳ năm trước
                (SELECT SUM(od2.quantity) 
                 FROM order_details od2 
                 JOIN product_variants pv2 ON od2.variant_id = pv2.variant_id
                 JOIN products p2 ON pv2.product_id = p2.product_id
                 JOIN orders o2 ON od2.order_id = o2.order_id
                 WHERE SUBSTRING_INDEX(p2.name, ' ', 1) = SUBSTRING_INDEX(p.name, ' ', 1)
                   AND p2.warehouse_id = :warehouse_id
                   AND o2.status = 'delivered'
                   AND o2.created_at BETWEEN DATE_SUB(NOW(), INTERVAL 24 MONTH) 
                                        AND DATE_SUB(NOW(), INTERVAL 12 MONTH)
                ) as quantity_last_year
                
            FROM orders o
            JOIN order_details od ON o.order_id = od.order_id
            JOIN product_variants pv ON od.variant_id = pv.variant_id
            JOIN products p ON pv.product_id = p.product_id
            WHERE p.warehouse_id = :warehouse_id
                AND o.status = 'delivered'
                AND o.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
            GROUP BY brand_name
            HAVING total_quantity > 0
            ORDER BY total_revenue DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
    $stmt->execute();
    
    $brands = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Tính % tăng trưởng
    foreach ($brands as &$brand) {
        if ($brand['quantity_last_year'] && $brand['quantity_last_year'] > 0) {
            $growth = (($brand['total_quantity'] - $brand['quantity_last_year']) / $brand['quantity_last_year']) * 100;
            $brand['growth_percentage'] = round($growth, 1);
        } else {
            $brand['growth_percentage'] = 0;
        }
    }
    
    return $brands;
}

function getColorSizeTrends($pdo, $warehouseId) {
    $sql = "SELECT 
                pv.color,
                pv.size,
                COUNT(od.order_detail_id) as total_orders,
                SUM(od.quantity) as total_quantity,
                SUM(od.quantity * od.unit_price) as total_revenue,
                
                -- Tính tỷ lệ % so với tổng
                ROUND((SUM(od.quantity) * 100.0 / (
                    SELECT SUM(od2.quantity) 
                    FROM order_details od2
                    JOIN product_variants pv2 ON od2.variant_id = pv2.variant_id
                    JOIN products p2 ON pv2.product_id = p2.product_id
                    JOIN orders o2 ON od2.order_id = o2.order_id
                    WHERE p2.warehouse_id = :warehouse_id
                        AND o2.status = 'delivered'
                        AND o2.created_at >= DATE_SUB(NOW(), INTERVAL 3 MONTH)
                )), 2) as percentage_of_total
                
            FROM orders o
            JOIN order_details od ON o.order_id = od.order_id
            JOIN product_variants pv ON od.variant_id = pv.variant_id
            JOIN products p ON pv.product_id = p.product_id
            WHERE p.warehouse_id = :warehouse_id
                AND o.status = 'delivered'
                AND o.created_at >= DATE_SUB(NOW(), INTERVAL 3 MONTH)
                AND pv.color IS NOT NULL
                AND pv.size IS NOT NULL
            GROUP BY pv.color, pv.size
            HAVING total_quantity > 0
            ORDER BY total_quantity DESC
            LIMIT 20";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
    $stmt->execute();
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function generateTrendInsights($monthlyTrends, $seasonalTrends, $brandTrends) {
    $insights = [];
    
    // 1. Phân tích mùa vụ
    $seasonalData = [];
    foreach ($seasonalTrends as $trend) {
        if (!isset($seasonalData[$trend['season']])) {
            $seasonalData[$trend['season']] = 0;
        }
        $seasonalData[$trend['season']] += $trend['total_quantity'];
    }
    
    arsort($seasonalData);
    $topSeason = array_key_first($seasonalData);
    $insights[] = [
        'type' => 'seasonal',
        'icon' => 'fa-calendar-alt',
        'message' => "{$topSeason} là mùa bán hàng tốt nhất với " . number_format($seasonalData[$topSeason]) . " sản phẩm bán ra",
        'priority' => 'high'
    ];
    
    // 2. Phân tích thương hiệu tăng trưởng
    foreach ($brandTrends as $brand) {
        if ($brand['growth_percentage'] > 20) {
            $insights[] = [
                'type' => 'brand_growth',
                'icon' => 'fa-arrow-up',
                'message' => "Thương hiệu {$brand['brand_name']} tăng trưởng {$brand['growth_percentage']}% so với cùng kỳ năm trước",
                'priority' => 'medium'
            ];
        } elseif ($brand['growth_percentage'] < -10) {
            $insights[] = [
                'type' => 'brand_decline',
                'icon' => 'fa-arrow-down',
                'message' => "Thương hiệu {$brand['brand_name']} giảm {$brand['growth_percentage']}% - cần xem xét chiến lược",
                'priority' => 'high'
            ];
        }
    }
    
    // 3. Phân tích xu hướng theo danh mục
    $categoryPerformance = [];
    foreach ($seasonalTrends as $trend) {
        if (!isset($categoryPerformance[$trend['category_name']])) {
            $categoryPerformance[$trend['category_name']] = [
                'total' => 0,
                'seasons' => []
            ];
        }
        $categoryPerformance[$trend['category_name']]['total'] += $trend['total_quantity'];
        $categoryPerformance[$trend['category_name']]['seasons'][$trend['season']] = $trend['total_quantity'];
    }
    
    foreach ($categoryPerformance as $category => $data) {
        arsort($data['seasons']);
        $bestSeason = array_key_first($data['seasons']);
        $insights[] = [
            'type' => 'category_seasonal',
            'icon' => 'fa-chart-line',
            'message' => "{$category} bán tốt nhất vào {$bestSeason}",
            'priority' => 'low'
        ];
    }
    
    return $insights;
}

function formatSeasonalChartData($seasonalTrends) {
    $chartData = [
        'labels' => [],
        'datasets' => []
    ];
    
    $categories = [];
    $seasons = [];
    
    // Tổ chức dữ liệu theo category và season
    foreach ($seasonalTrends as $trend) {
        if (!in_array($trend['season'], $seasons)) {
            $seasons[] = $trend['season'];
        }
        if (!in_array($trend['category_name'], $categories)) {
            $categories[] = $trend['category_name'];
        }
    }
    
    $chartData['labels'] = $seasons;
    
    $colors = ['#4e73df', '#1cc88a', '#36b9cc', '#f6c23e', '#e74a3b', '#858796'];
    $colorIndex = 0;
    
    foreach ($categories as $category) {
        $data = [];
        foreach ($seasons as $season) {
            $quantity = 0;
            foreach ($seasonalTrends as $trend) {
                if ($trend['category_name'] === $category && $trend['season'] === $season) {
                    $quantity = $trend['total_quantity'];
                    break;
                }
            }
            $data[] = $quantity;
        }
        
        $chartData['datasets'][] = [
            'label' => $category,
            'data' => $data,
            'borderColor' => $colors[$colorIndex % count($colors)],
            'backgroundColor' => $colors[$colorIndex % count($colors)] . '20',
            'tension' => 0.4
        ];
        $colorIndex++;
    }
    
    return $chartData;
}

function formatBrandChartData($brandTrends) {
    $chartData = [
        'labels' => [],
        'datasets' => [
            [
                'label' => 'Tăng trưởng (%)',
                'data' => [],
                'backgroundColor' => []
            ]
        ]
    ];
    
    foreach ($brandTrends as $brand) {
        $chartData['labels'][] = $brand['brand_name'];
        $chartData['datasets'][0]['data'][] = $brand['growth_percentage'];
        
        // Màu sắc dựa trên tăng trưởng
        if ($brand['growth_percentage'] > 10) {
            $chartData['datasets'][0]['backgroundColor'][] = '#28a745'; // Green
        } elseif ($brand['growth_percentage'] > 0) {
            $chartData['datasets'][0]['backgroundColor'][] = '#17a2b8'; // Blue
        } elseif ($brand['growth_percentage'] > -10) {
            $chartData['datasets'][0]['backgroundColor'][] = '#ffc107'; // Yellow
        } else {
            $chartData['datasets'][0]['backgroundColor'][] = '#dc3545'; // Red
        }
    }
    
    return $chartData;
}
?>