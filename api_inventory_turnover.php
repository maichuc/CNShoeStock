<?php
/**
 * API: Inventory Turnover & Aging Analysis  
 * Module 2 của AI Analytics Dashboard
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
    // 1. Phân tích vòng quay tồn kho
    $turnoverAnalysis = getInventoryTurnoverAnalysis($pdo, $warehouseId);
    
    // 2. Phân tích độ tuổi hàng tồn
    $agingAnalysis = getInventoryAgingAnalysis($pdo, $warehouseId);
    
    // 3. Days in Inventory (DII) calculation
    $diiAnalysis = getDaysInInventoryAnalysis($pdo, $warehouseId);
    
    // 4. AI Recommendations cho tối ưu tồn kho
    $aiRecommendations = generateInventoryRecommendations($turnoverAnalysis, $agingAnalysis, $diiAnalysis);
    
    $response = [
        'success' => true,
        'data' => [
            'turnover_analysis' => $turnoverAnalysis,
            'aging_analysis' => $agingAnalysis,
            'dii_analysis' => $diiAnalysis,
            'ai_recommendations' => $aiRecommendations,
            'charts_data' => [
                'turnover_chart' => formatTurnoverChartData($turnoverAnalysis),
                'aging_chart' => formatAgingChartData($agingAnalysis),
                'dii_chart' => formatDIIChartData($diiAnalysis)
            ],
            'summary' => [
                'avg_turnover_rate' => calculateAverageTurnoverRate($turnoverAnalysis),
                'slow_moving_items' => countSlowMovingItems($agingAnalysis),
                'optimal_stock_items' => countOptimalStockItems($turnoverAnalysis)
            ]
        ],
        'timestamp' => time()
    ];
    
    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Inventory analysis failed: ' . $e->getMessage()
    ]);
}

function getInventoryTurnoverAnalysis($pdo, $warehouseId) {
    $sql = "SELECT 
                p.product_id,
                p.name as product_name,
                pv.sku,
                c.name as category_name,
                
                -- Current stock
                COALESCE(i.quantity, 0) as current_stock,
                
                -- Cost of goods sold (COGS) trong 12 tháng
                COALESCE(sales.total_quantity_sold, 0) as total_sold_12m,
                COALESCE(sales.total_revenue, 0) as total_revenue_12m,
                COALESCE(sales.avg_cost_price, pv.cost_price) as avg_cost_price,
                
                -- Average inventory (giả sử = current stock, có thể cải thiện bằng historical data)
                COALESCE(i.quantity, 0) as avg_inventory,
                
                -- Inventory Turnover Rate = COGS / Average Inventory
                CASE 
                    WHEN COALESCE(i.quantity, 0) > 0 
                    THEN ROUND(COALESCE(sales.total_quantity_sold, 0) / COALESCE(i.quantity, 1), 2)
                    ELSE 0 
                END as turnover_rate,
                
                -- Days in Inventory = 365 / Turnover Rate
                CASE 
                    WHEN COALESCE(sales.total_quantity_sold, 0) > 0 AND COALESCE(i.quantity, 0) > 0
                    THEN ROUND(365 / (COALESCE(sales.total_quantity_sold, 0) / COALESCE(i.quantity, 1)), 0)
                    ELSE 999 
                END as days_in_inventory,
                
                -- Stock value
                (COALESCE(i.quantity, 0) * COALESCE(pv.cost_price, 0)) as stock_value
                
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.category_id
            LEFT JOIN product_variants pv ON p.product_id = pv.product_id
            LEFT JOIN inventory i ON pv.variant_id = i.variant_id
            LEFT JOIN (
                SELECT 
                    pv2.variant_id,
                    SUM(od.quantity) as total_quantity_sold,
                    SUM(od.quantity * od.unit_price) as total_revenue,
                    AVG(COALESCE(pv2.cost_price, od.unit_price * 0.7)) as avg_cost_price
                FROM order_details od
                JOIN product_variants pv2 ON od.variant_id = pv2.variant_id
                JOIN orders o ON od.order_id = o.order_id
                WHERE o.status = 'delivered'
                    AND o.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                GROUP BY pv2.variant_id
            ) sales ON pv.variant_id = sales.variant_id
            
            WHERE p.warehouse_id = :warehouse_id
            ORDER BY turnover_rate DESC, days_in_inventory ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
    $stmt->execute();
    
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Phân loại performance
    foreach ($results as &$item) {
        if ($item['turnover_rate'] >= 6) {
            $item['performance'] = 'excellent';
            $item['performance_label'] = 'Xuất sắc';
        } elseif ($item['turnover_rate'] >= 4) {
            $item['performance'] = 'good';
            $item['performance_label'] = 'Tốt';
        } elseif ($item['turnover_rate'] >= 2) {
            $item['performance'] = 'average';
            $item['performance_label'] = 'Trung bình';
        } else {
            $item['performance'] = 'poor';
            $item['performance_label'] = 'Kém';
        }
    }
    
    return $results;
}

function getInventoryAgingAnalysis($pdo, $warehouseId) {
    $sql = "SELECT 
                p.product_id,
                p.name as product_name,
                pv.sku,
                c.name as category_name,
                COALESCE(i.quantity, 0) as current_stock,
                
                -- Lấy ngày nhập gần nhất
                COALESCE(latest_receipt.receipt_date, p.created_at) as last_received_date,
                
                -- Tính số ngày tồn kho
                DATEDIFF(NOW(), COALESCE(latest_receipt.receipt_date, p.created_at)) as days_in_stock,
                
                -- Phân loại độ tuổi
                CASE 
                    WHEN DATEDIFF(NOW(), COALESCE(latest_receipt.receipt_date, p.created_at)) <= 30 THEN '0-30 ngày'
                    WHEN DATEDIFF(NOW(), COALESCE(latest_receipt.receipt_date, p.created_at)) <= 60 THEN '31-60 ngày'
                    WHEN DATEDIFF(NOW(), COALESCE(latest_receipt.receipt_date, p.created_at)) <= 90 THEN '61-90 ngày'
                    ELSE '>90 ngày'
                END as aging_category,
                
                -- Giá trị tồn kho
                (COALESCE(i.quantity, 0) * COALESCE(pv.cost_price, 0)) as stock_value,
                
                -- Velocity (số bán trung bình per ngày trong 90 ngày qua)
                COALESCE(recent_sales.daily_velocity, 0) as daily_velocity
                
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.category_id
            LEFT JOIN product_variants pv ON p.product_id = pv.product_id
            LEFT JOIN inventory i ON pv.variant_id = i.variant_id
            
            -- Latest receipt date
            LEFT JOIN (
                SELECT 
                    p2.product_id,
                    MAX(sr.created_at) as receipt_date
                FROM products p2
                JOIN stock_receipts sr ON p2.warehouse_id = sr.warehouse_id
                WHERE sr.status = 'completed'
                GROUP BY p2.product_id
            ) latest_receipt ON p.product_id = latest_receipt.product_id
            
            -- Recent sales velocity
            LEFT JOIN (
                SELECT 
                    pv3.product_id,
                    SUM(od.quantity) / 90.0 as daily_velocity
                FROM order_details od
                JOIN product_variants pv3 ON od.variant_id = pv3.variant_id
                JOIN orders o ON od.order_id = o.order_id
                WHERE o.status = 'delivered'
                    AND o.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
                GROUP BY pv3.product_id
            ) recent_sales ON p.product_id = recent_sales.product_id
            
            WHERE p.warehouse_id = :warehouse_id
                AND COALESCE(i.quantity, 0) > 0
            ORDER BY days_in_stock DESC, stock_value DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
    $stmt->execute();
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getDaysInInventoryAnalysis($pdo, $warehouseId) {
    $sql = "SELECT 
                c.name as category_name,
                COUNT(*) as product_count,
                AVG(CASE 
                    WHEN sales.total_quantity_sold > 0 AND i.quantity > 0
                    THEN 365 / (sales.total_quantity_sold / i.quantity)
                    ELSE NULL 
                END) as avg_days_in_inventory,
                
                MIN(CASE 
                    WHEN sales.total_quantity_sold > 0 AND i.quantity > 0
                    THEN 365 / (sales.total_quantity_sold / i.quantity)
                    ELSE NULL 
                END) as min_dii,
                
                MAX(CASE 
                    WHEN sales.total_quantity_sold > 0 AND i.quantity > 0
                    THEN 365 / (sales.total_quantity_sold / i.quantity)
                    ELSE NULL 
                END) as max_dii,
                
                SUM(i.quantity * pv.cost_price) as total_stock_value
                
            FROM products p
            JOIN categories c ON p.category_id = c.category_id
            JOIN product_variants pv ON p.product_id = pv.product_id
            JOIN inventory i ON pv.variant_id = i.variant_id
            LEFT JOIN (
                SELECT 
                    pv2.variant_id,
                    SUM(od.quantity) as total_quantity_sold
                FROM order_details od
                JOIN product_variants pv2 ON od.variant_id = pv2.variant_id
                JOIN orders o ON od.order_id = o.order_id
                WHERE o.status = 'delivered'
                    AND o.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                GROUP BY pv2.variant_id
            ) sales ON pv.variant_id = sales.variant_id
            
            WHERE p.warehouse_id = :warehouse_id
                AND i.quantity > 0
            GROUP BY c.category_id
            ORDER BY avg_days_in_inventory ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
    $stmt->execute();
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function generateInventoryRecommendations($turnoverAnalysis, $agingAnalysis, $diiAnalysis) {
    $recommendations = [
        'urgent' => [],
        'important' => [],
        'suggested' => []
    ];
    
    // 1. Urgent: Hàng chậm luân chuyển + tồn lâu
    foreach ($agingAnalysis as $item) {
        if ($item['days_in_stock'] > 90 && $item['daily_velocity'] < 0.1) {
            $recommendations['urgent'][] = [
                'type' => 'slow_moving_old_stock',
                'product_name' => $item['product_name'],
                'sku' => $item['sku'],
                'issue' => "Tồn kho {$item['days_in_stock']} ngày, bán chậm",
                'recommendation' => 'Khuyến mãi mạnh hoặc thanh lý',
                'priority_score' => 95,
                'potential_loss' => round($item['stock_value'] * 0.3, 0)
            ];
        }
    }
    
    // 2. Important: Turnover rate quá thấp
    foreach ($turnoverAnalysis as $item) {
        if ($item['turnover_rate'] < 2 && $item['stock_value'] > 1000000) {
            $recommendations['important'][] = [
                'type' => 'low_turnover_high_value',
                'product_name' => $item['product_name'],
                'sku' => $item['sku'],
                'issue' => "Vòng quay {$item['turnover_rate']}x/năm, giá trị cao",
                'recommendation' => 'Giảm nhập hàng, tăng marketing',
                'priority_score' => 80,
                'impact' => 'Giải phóng vốn lưu động'
            ];
        }
    }
    
    // 3. Suggested: Tối ưu hóa theo category
    foreach ($diiAnalysis as $category) {
        if ($category['avg_days_in_inventory'] > 60) {
            $recommendations['suggested'][] = [
                'type' => 'category_optimization',
                'category' => $category['category_name'],
                'issue' => "DII trung bình {$category['avg_days_in_inventory']} ngày",
                'recommendation' => 'Xem xét giảm đơn hàng cho danh mục này',
                'priority_score' => 60,
                'affected_products' => $category['product_count']
            ];
        }
    }
    
    return $recommendations;
}

function formatTurnoverChartData($turnoverAnalysis) {
    $categories = [];
    $turnoverData = [];
    
    foreach ($turnoverAnalysis as $item) {
        if (!isset($categories[$item['category_name']])) {
            $categories[$item['category_name']] = [
                'total_turnover' => 0,
                'count' => 0
            ];
        }
        
        $categories[$item['category_name']]['total_turnover'] += $item['turnover_rate'];
        $categories[$item['category_name']]['count']++;
    }
    
    $labels = [];
    $data = [];
    $colors = ['#4e73df', '#1cc88a', '#36b9cc', '#f6c23e', '#e74a3b'];
    $backgroundColors = [];
    
    $colorIndex = 0;
    foreach ($categories as $categoryName => $categoryData) {
        if ($categoryData['count'] > 0) {
            $labels[] = $categoryName;
            $data[] = round($categoryData['total_turnover'] / $categoryData['count'], 2);
            $backgroundColors[] = $colors[$colorIndex % count($colors)];
            $colorIndex++;
        }
    }
    
    return [
        'labels' => $labels,
        'datasets' => [
            [
                'label' => 'Vòng quay trung bình (lần/năm)',
                'data' => $data,
                'backgroundColor' => $backgroundColors
            ]
        ]
    ];
}

function formatAgingChartData($agingAnalysis) {
    $agingCategories = [
        '0-30 ngày' => 0,
        '31-60 ngày' => 0,
        '61-90 ngày' => 0,
        '>90 ngày' => 0
    ];
    
    foreach ($agingAnalysis as $item) {
        if (isset($agingCategories[$item['aging_category']])) {
            $agingCategories[$item['aging_category']]++;
        }
    }
    
    return [
        'labels' => array_keys($agingCategories),
        'datasets' => [
            [
                'label' => 'Số sản phẩm',
                'data' => array_values($agingCategories),
                'backgroundColor' => ['#28a745', '#ffc107', '#fd7e14', '#dc3545']
            ]
        ]
    ];
}

function formatDIIChartData($diiAnalysis) {
    $labels = [];
    $data = [];
    
    foreach ($diiAnalysis as $category) {
        $labels[] = $category['category_name'];
        $data[] = round($category['avg_days_in_inventory'], 0);
    }
    
    return [
        'labels' => $labels,
        'datasets' => [
            [
                'label' => 'Days in Inventory',
                'data' => $data,
                'backgroundColor' => '#36b9cc',
                'borderColor' => '#36b9cc',
                'borderWidth' => 1
            ]
        ]
    ];
}

function calculateAverageTurnoverRate($turnoverAnalysis) {
    if (count($turnoverAnalysis) === 0) return 0;
    
    $totalTurnover = 0;
    $count = 0;
    
    foreach ($turnoverAnalysis as $item) {
        if ($item['turnover_rate'] > 0) {
            $totalTurnover += $item['turnover_rate'];
            $count++;
        }
    }
    
    return $count > 0 ? round($totalTurnover / $count, 2) : 0;
}

function countSlowMovingItems($agingAnalysis) {
    $count = 0;
    foreach ($agingAnalysis as $item) {
        if ($item['days_in_stock'] > 90 && $item['daily_velocity'] < 0.1) {
            $count++;
        }
    }
    return $count;
}

function countOptimalStockItems($turnoverAnalysis) {
    $count = 0;
    foreach ($turnoverAnalysis as $item) {
        if ($item['turnover_rate'] >= 4 && $item['turnover_rate'] <= 12) {
            $count++;
        }
    }
    return $count;
}
?>