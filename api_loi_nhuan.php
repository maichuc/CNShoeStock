<?php
/**
 * API: Profitability Analysis  
 * Module 4 của AI Analytics Dashboard
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

session_start();
require_once 'config/cau_hinh_csdl.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$database = new Database();
$pdo = $database->getConnection();
$warehouseId = $_SESSION['warehouse_id'];

try {
    // 1. Product Profitability Analysis
    $productProfitability = getProductProfitabilityAnalysis($pdo, $warehouseId);
    
    // 2. Category Margin Analysis
    $categoryMargins = getCategoryMarginAnalysis($pdo, $warehouseId);
    
    // 3. Monthly Profit Trends
    $monthlyProfits = getMonthlyProfitTrends($pdo, $warehouseId);
    
    // 4. Customer Profitability
    $customerProfitability = getCustomerProfitabilityAnalysis($pdo, $warehouseId);
    
    // 5. Pricing Optimization Opportunities
    $pricingOpportunities = getPricingOptimizationOpportunities($pdo, $warehouseId);
    
    // 6. AI Recommendations cho tối ưu lợi nhuận
    $aiRecommendations = generateProfitabilityRecommendations($productProfitability, $categoryMargins, $pricingOpportunities);
    
    $response = [
        'success' => true,
        'data' => [
            'product_profitability' => $productProfitability,
            'category_margins' => $categoryMargins,
            'monthly_profits' => $monthlyProfits,
            'customer_profitability' => $customerProfitability,
            'pricing_opportunities' => $pricingOpportunities,
            'ai_recommendations' => $aiRecommendations,
            'charts_data' => [
                'profitability_chart' => formatProfitabilityChartData($productProfitability),
                'margin_chart' => formatMarginChartData($categoryMargins),
                'profit_trend_chart' => formatProfitTrendChartData($monthlyProfits),
                'pricing_opportunity_chart' => formatPricingOpportunityChartData($pricingOpportunities)
            ],
            'summary' => [
                'total_revenue' => calculateTotalRevenue($monthlyProfits),
                'total_profit' => calculateTotalProfit($monthlyProfits),
                'avg_margin' => calculateAverageMargin($categoryMargins),
                'top_profitable_products' => getTopProfitableProducts($productProfitability, 5)
            ]
        ],
        'timestamp' => time()
    ];
    
    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Profitability analysis failed: ' . $e->getMessage()
    ]);
}

function getProductProfitabilityAnalysis($pdo, $warehouseId) {
    $sql = "SELECT 
                p.product_id,
                p.name as product_name,
                pv.sku,
                c.name as category_name,
                
                -- Cost data
                COALESCE(pv.cost_price, 0) as cost_price,
                COALESCE(pv.selling_price, 0) as selling_price,
                
                -- Unit margin
                (COALESCE(pv.selling_price, 0) - COALESCE(pv.cost_price, 0)) as unit_margin,
                CASE 
                    WHEN COALESCE(pv.selling_price, 0) > 0 
                    THEN ROUND(((COALESCE(pv.selling_price, 0) - COALESCE(pv.cost_price, 0)) / COALESCE(pv.selling_price, 1)) * 100, 2)
                    ELSE 0 
                END as margin_percentage,
                
                -- Sales data (last 12 months)
                COALESCE(sales.total_quantity_sold, 0) as total_quantity_sold,
                COALESCE(sales.total_revenue, 0) as total_revenue,
                COALESCE(sales.total_cost, 0) as total_cost,
                COALESCE(sales.total_profit, 0) as total_profit,
                
                -- Profitability metrics
                CASE 
                    WHEN COALESCE(sales.total_revenue, 0) > 0 
                    THEN ROUND((COALESCE(sales.total_profit, 0) / COALESCE(sales.total_revenue, 1)) * 100, 2)
                    ELSE 0 
                END as profit_margin_percentage,
                
                -- ROI calculation
                CASE 
                    WHEN COALESCE(sales.total_cost, 0) > 0 
                    THEN ROUND((COALESCE(sales.total_profit, 0) / COALESCE(sales.total_cost, 1)) * 100, 2)
                    ELSE 0 
                END as roi_percentage,
                
                -- Current inventory value
                COALESCE(i.quantity, 0) as current_stock,
                (COALESCE(i.quantity, 0) * COALESCE(pv.cost_price, 0)) as inventory_value,
                
                -- Performance classification
                CASE 
                    WHEN COALESCE(sales.total_profit, 0) > 1000000 AND COALESCE(sales.total_quantity_sold, 0) > 50 THEN 'star'
                    WHEN COALESCE(sales.total_profit, 0) > 500000 AND COALESCE(sales.total_quantity_sold, 0) > 20 THEN 'cash_cow'
                    WHEN COALESCE(sales.total_quantity_sold, 0) > 30 AND (COALESCE(pv.selling_price, 0) - COALESCE(pv.cost_price, 0)) < 100000 THEN 'problem_child'
                    ELSE 'dog'
                END as product_classification
                
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.category_id
            LEFT JOIN product_variants pv ON p.product_id = pv.product_id
            LEFT JOIN inventory i ON pv.variant_id = i.variant_id
            LEFT JOIN (
                SELECT 
                    pv2.variant_id,
                    SUM(od.quantity) as total_quantity_sold,
                    SUM(od.quantity * od.unit_price) as total_revenue,
                    SUM(od.quantity * COALESCE(pv2.cost_price, od.unit_price * 0.7)) as total_cost,
                    SUM(od.quantity * (od.unit_price - COALESCE(pv2.cost_price, od.unit_price * 0.7))) as total_profit
                FROM order_details od
                JOIN product_variants pv2 ON od.variant_id = pv2.variant_id
                JOIN orders o ON od.order_id = o.order_id
                WHERE o.status = 'delivered'
                    AND o.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                GROUP BY pv2.variant_id
            ) sales ON pv.variant_id = sales.variant_id
            
            WHERE p.warehouse_id = :warehouse_id
            ORDER BY total_profit DESC, total_revenue DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
    $stmt->execute();
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getCategoryMarginAnalysis($pdo, $warehouseId) {
    $sql = "SELECT 
                c.category_id,
                c.name as category_name,
                
                -- Product count
                COUNT(DISTINCT p.product_id) as product_count,
                COUNT(DISTINCT pv.variant_id) as variant_count,
                
                -- Average margins
                AVG(COALESCE(pv.selling_price, 0) - COALESCE(pv.cost_price, 0)) as avg_unit_margin,
                AVG(CASE 
                    WHEN COALESCE(pv.selling_price, 0) > 0 
                    THEN ((COALESCE(pv.selling_price, 0) - COALESCE(pv.cost_price, 0)) / COALESCE(pv.selling_price, 1)) * 100
                    ELSE 0 
                END) as avg_margin_percentage,
                
                -- Sales performance (last 12 months)
                COALESCE(SUM(sales.total_quantity_sold), 0) as total_quantity_sold,
                COALESCE(SUM(sales.total_revenue), 0) as total_revenue,
                COALESCE(SUM(sales.total_cost), 0) as total_cost,
                COALESCE(SUM(sales.total_profit), 0) as total_profit,
                
                -- Category profit margin
                CASE 
                    WHEN SUM(sales.total_revenue) > 0 
                    THEN ROUND((SUM(sales.total_profit) / SUM(sales.total_revenue)) * 100, 2)
                    ELSE 0 
                END as category_profit_margin,
                
                -- Inventory metrics
                SUM(COALESCE(i.quantity, 0)) as total_inventory_quantity,
                SUM(COALESCE(i.quantity, 0) * COALESCE(pv.cost_price, 0)) as total_inventory_value,
                
                -- Market share (revenue contribution)
                ROUND((SUM(sales.total_revenue) / (
                    SELECT SUM(od2.quantity * od2.unit_price)
                    FROM order_details od2
                    JOIN product_variants pv3 ON od2.variant_id = pv3.variant_id
                    JOIN products p3 ON pv3.product_id = p3.product_id
                    JOIN orders o2 ON od2.order_id = o2.order_id
                    WHERE p3.warehouse_id = :warehouse_id
                        AND o2.status = 'delivered'
                        AND o2.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                )) * 100, 2) as revenue_contribution_percentage
                
            FROM categories c
            LEFT JOIN products p ON c.category_id = p.category_id
            LEFT JOIN product_variants pv ON p.product_id = pv.product_id
            LEFT JOIN inventory i ON pv.variant_id = i.variant_id
            LEFT JOIN (
                SELECT 
                    pv2.variant_id,
                    SUM(od.quantity) as total_quantity_sold,
                    SUM(od.quantity * od.unit_price) as total_revenue,
                    SUM(od.quantity * COALESCE(pv2.cost_price, od.unit_price * 0.7)) as total_cost,
                    SUM(od.quantity * (od.unit_price - COALESCE(pv2.cost_price, od.unit_price * 0.7))) as total_profit
                FROM order_details od
                JOIN product_variants pv2 ON od.variant_id = pv2.variant_id
                JOIN orders o ON od.order_id = o.order_id
                WHERE o.status = 'delivered'
                    AND o.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                GROUP BY pv2.variant_id
            ) sales ON pv.variant_id = sales.variant_id
            
            WHERE p.warehouse_id = :warehouse_id
            GROUP BY c.category_id
            HAVING product_count > 0
            ORDER BY total_profit DESC, total_revenue DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
    $stmt->execute();
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getMonthlyProfitTrends($pdo, $warehouseId) {
    $sql = "SELECT 
                DATE_FORMAT(o.created_at, '%Y-%m') as month_year,
                DATE_FORMAT(o.created_at, '%Y') as year,
                DATE_FORMAT(o.created_at, '%m') as month,
                
                -- Revenue metrics
                COUNT(DISTINCT o.order_id) as total_orders,
                SUM(od.quantity) as total_quantity_sold,
                SUM(od.quantity * od.unit_price) as total_revenue,
                
                -- Cost and profit calculations
                SUM(od.quantity * COALESCE(pv.cost_price, od.unit_price * 0.7)) as total_cost,
                SUM(od.quantity * (od.unit_price - COALESCE(pv.cost_price, od.unit_price * 0.7))) as total_profit,
                
                -- Margin calculations
                ROUND(
                    (SUM(od.quantity * (od.unit_price - COALESCE(pv.cost_price, od.unit_price * 0.7))) / 
                     NULLIF(SUM(od.quantity * od.unit_price), 0)) * 100, 2
                ) as profit_margin_percentage,
                
                -- Average order value
                ROUND(SUM(od.quantity * od.unit_price) / COUNT(DISTINCT o.order_id), 0) as avg_order_value,
                
                -- Customer metrics
                COUNT(DISTINCT o.customer_id) as unique_customers,
                
                -- Growth calculations (vs same month last year)
                LAG(SUM(od.quantity * od.unit_price), 12) OVER (ORDER BY DATE_FORMAT(o.created_at, '%Y-%m')) as revenue_same_month_last_year,
                LAG(SUM(od.quantity * (od.unit_price - COALESCE(pv.cost_price, od.unit_price * 0.7))), 12) OVER (ORDER BY DATE_FORMAT(o.created_at, '%Y-%m')) as profit_same_month_last_year
                
            FROM orders o
            JOIN order_details od ON o.order_id = od.order_id
            JOIN product_variants pv ON od.variant_id = pv.variant_id
            JOIN products p ON pv.product_id = p.product_id
            
            WHERE o.status = 'delivered'
                AND o.warehouse_id = :warehouse_id
                AND o.created_at >= DATE_SUB(NOW(), INTERVAL 24 MONTH)
            GROUP BY DATE_FORMAT(o.created_at, '%Y-%m')
            ORDER BY month_year DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
    $stmt->execute();
    
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate growth rates
    foreach ($results as &$month) {
        if ($month['revenue_same_month_last_year'] > 0) {
            $month['revenue_growth_yoy'] = round(
                (($month['total_revenue'] - $month['revenue_same_month_last_year']) / 
                 $month['revenue_same_month_last_year']) * 100, 2
            );
        } else {
            $month['revenue_growth_yoy'] = null;
        }
        
        if ($month['profit_same_month_last_year'] > 0) {
            $month['profit_growth_yoy'] = round(
                (($month['total_profit'] - $month['profit_same_month_last_year']) / 
                 $month['profit_same_month_last_year']) * 100, 2
            );
        } else {
            $month['profit_growth_yoy'] = null;
        }
    }
    
    return $results;
}

function getCustomerProfitabilityAnalysis($pdo, $warehouseId) {
    $sql = "SELECT 
                c.customer_id,
                c.name as customer_name,
                c.email,
                
                -- Order metrics
                COUNT(DISTINCT o.order_id) as total_orders,
                SUM(od.quantity) as total_items_bought,
                
                -- Revenue and profit
                SUM(od.quantity * od.unit_price) as total_revenue,
                SUM(od.quantity * COALESCE(pv.cost_price, od.unit_price * 0.7)) as total_cost,
                SUM(od.quantity * (od.unit_price - COALESCE(pv.cost_price, od.unit_price * 0.7))) as total_profit,
                
                -- Customer value metrics
                ROUND(SUM(od.quantity * od.unit_price) / COUNT(DISTINCT o.order_id), 0) as avg_order_value,
                ROUND(SUM(od.quantity * (od.unit_price - COALESCE(pv.cost_price, od.unit_price * 0.7))) / COUNT(DISTINCT o.order_id), 0) as avg_profit_per_order,
                
                -- Customer lifetime value (CLV) estimation
                ROUND(
                    (SUM(od.quantity * od.unit_price) / COUNT(DISTINCT o.order_id)) * 
                    (COUNT(DISTINCT o.order_id) / NULLIF(DATEDIFF(MAX(o.created_at), MIN(o.created_at)), 0)) * 365 * 2, 0
                ) as estimated_clv,
                
                -- Activity metrics
                MIN(o.created_at) as first_order_date,
                MAX(o.created_at) as last_order_date,
                DATEDIFF(NOW(), MAX(o.created_at)) as days_since_last_order,
                
                -- Customer segment
                CASE 
                    WHEN SUM(od.quantity * (od.unit_price - COALESCE(pv.cost_price, od.unit_price * 0.7))) > 2000000 
                         AND COUNT(DISTINCT o.order_id) > 10 THEN 'vip'
                    WHEN SUM(od.quantity * (od.unit_price - COALESCE(pv.cost_price, od.unit_price * 0.7))) > 1000000 
                         AND COUNT(DISTINCT o.order_id) > 5 THEN 'loyal'
                    WHEN COUNT(DISTINCT o.order_id) > 3 THEN 'regular'
                    ELSE 'new'
                END as customer_segment
                
            FROM customers c
            JOIN orders o ON c.customer_id = o.customer_id
            JOIN order_details od ON o.order_id = od.order_id
            JOIN product_variants pv ON od.variant_id = pv.variant_id
            JOIN products p ON pv.product_id = p.product_id
            
            WHERE o.status = 'delivered'
                AND o.warehouse_id = :warehouse_id
                AND o.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
            GROUP BY c.customer_id
            ORDER BY total_profit DESC, total_revenue DESC
            LIMIT 100";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
    $stmt->execute();
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getPricingOptimizationOpportunities($pdo, $warehouseId) {
    $sql = "SELECT 
                p.product_id,
                p.name as product_name,
                pv.sku,
                c.name as category_name,
                
                -- Current pricing
                COALESCE(pv.cost_price, 0) as current_cost_price,
                COALESCE(pv.selling_price, 0) as current_selling_price,
                (COALESCE(pv.selling_price, 0) - COALESCE(pv.cost_price, 0)) as current_margin,
                
                -- Sales performance
                COALESCE(sales.total_quantity_sold, 0) as total_quantity_sold,
                COALESCE(sales.avg_selling_price, 0) as avg_actual_selling_price,
                
                -- Market comparison (category average)
                category_avg.avg_category_margin,
                category_avg.avg_category_price,
                
                -- Price elasticity indicator (simplified)
                CASE 
                    WHEN COALESCE(sales.total_quantity_sold, 0) > 50 AND 
                         (COALESCE(pv.selling_price, 0) - COALESCE(pv.cost_price, 0)) < category_avg.avg_category_margin
                    THEN 'underpriced'
                    WHEN COALESCE(sales.total_quantity_sold, 0) < 10 AND 
                         (COALESCE(pv.selling_price, 0) - COALESCE(pv.cost_price, 0)) > category_avg.avg_category_margin * 1.2
                    THEN 'overpriced'
                    ELSE 'optimally_priced'
                END as pricing_assessment,
                
                -- Suggested price adjustment
                CASE 
                    WHEN COALESCE(sales.total_quantity_sold, 0) > 50 AND 
                         (COALESCE(pv.selling_price, 0) - COALESCE(pv.cost_price, 0)) < category_avg.avg_category_margin
                    THEN ROUND(COALESCE(pv.cost_price, 0) + (category_avg.avg_category_margin * 0.9), 0)
                    WHEN COALESCE(sales.total_quantity_sold, 0) < 10 AND 
                         (COALESCE(pv.selling_price, 0) - COALESCE(pv.cost_price, 0)) > category_avg.avg_category_margin * 1.2
                    THEN ROUND(COALESCE(pv.cost_price, 0) + (category_avg.avg_category_margin * 1.1), 0)
                    ELSE COALESCE(pv.selling_price, 0)
                END as suggested_price,
                
                -- Potential impact
                CASE 
                    WHEN COALESCE(sales.total_quantity_sold, 0) > 50 AND 
                         (COALESCE(pv.selling_price, 0) - COALESCE(pv.cost_price, 0)) < category_avg.avg_category_margin
                    THEN ROUND((category_avg.avg_category_margin - (COALESCE(pv.selling_price, 0) - COALESCE(pv.cost_price, 0))) * COALESCE(sales.total_quantity_sold, 0), 0)
                    ELSE 0
                END as potential_additional_profit
                
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.category_id
            LEFT JOIN product_variants pv ON p.product_id = pv.product_id
            LEFT JOIN (
                SELECT 
                    pv1.variant_id,
                    SUM(od.quantity) as total_quantity_sold,
                    AVG(od.unit_price) as avg_selling_price
                FROM order_details od
                JOIN product_variants pv1 ON od.variant_id = pv1.variant_id
                JOIN orders o ON od.order_id = o.order_id
                WHERE o.status = 'delivered'
                    AND o.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                GROUP BY pv1.variant_id
            ) sales ON pv.variant_id = sales.variant_id
            LEFT JOIN (
                SELECT 
                    p2.category_id,
                    AVG(COALESCE(pv2.selling_price, 0) - COALESCE(pv2.cost_price, 0)) as avg_category_margin,
                    AVG(COALESCE(pv2.selling_price, 0)) as avg_category_price
                FROM products p2
                JOIN product_variants pv2 ON p2.product_id = pv2.product_id
                WHERE p2.warehouse_id = :warehouse_id
                    AND pv2.selling_price > 0
                GROUP BY p2.category_id
            ) category_avg ON p.category_id = category_avg.category_id
            
            WHERE p.warehouse_id = :warehouse_id
                AND pv.selling_price > 0
                AND pv.cost_price > 0
            ORDER BY potential_additional_profit DESC, total_quantity_sold DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
    $stmt->execute();
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function generateProfitabilityRecommendations($productProfitability, $categoryMargins, $pricingOpportunities) {
    $recommendations = [
        'urgent' => [],
        'important' => [],
        'suggested' => []
    ];
    
    // 1. Urgent: Products losing money
    foreach ($productProfitability as $product) {
        if ($product['unit_margin'] < 0 && $product['total_quantity_sold'] > 0) {
            $recommendations['urgent'][] = [
                'type' => 'negative_margin_product',
                'product_name' => $product['product_name'],
                'sku' => $product['sku'],
                'issue' => "Lỗ {$product['unit_margin']} VND/sản phẩm",
                'recommendation' => 'Tăng giá bán hoặc đàm phán giảm giá mua',
                'priority_score' => 95,
                'financial_impact' => abs($product['total_profit'])
            ];
        }
    }
    
    // 2. Important: Low margin categories
    foreach ($categoryMargins as $category) {
        if ($category['avg_margin_percentage'] < 15 && $category['total_revenue'] > 5000000) {
            $recommendations['important'][] = [
                'type' => 'low_margin_category',
                'category_name' => $category['category_name'],
                'current_margin' => $category['avg_margin_percentage'],
                'issue' => "Margin thấp: {$category['avg_margin_percentage']}%",
                'recommendation' => 'Review pricing strategy cho toàn bộ danh mục',
                'priority_score' => 80,
                'revenue_at_risk' => $category['total_revenue']
            ];
        }
    }
    
    // 3. Suggested: Pricing optimization opportunities
    $underpricedProducts = array_filter($pricingOpportunities, function($item) {
        return $item['pricing_assessment'] === 'underpriced' && $item['potential_additional_profit'] > 100000;
    });
    
    if (count($underpricedProducts) > 0) {
        $totalPotentialProfit = array_sum(array_column($underpricedProducts, 'potential_additional_profit'));
        $recommendations['suggested'][] = [
            'type' => 'pricing_optimization',
            'affected_products' => count($underpricedProducts),
            'issue' => count($underpricedProducts) . ' sản phẩm có thể tăng giá',
            'recommendation' => 'Thực hiện A/B test tăng giá cho các sản phẩm bán chạy',
            'priority_score' => 70,
            'potential_profit_increase' => $totalPotentialProfit
        ];
    }
    
    // 4. Cost optimization for high-volume, low-margin products
    $costOptimizationTargets = array_filter($productProfitability, function($product) {
        return $product['total_quantity_sold'] > 100 && 
               $product['margin_percentage'] < 20 && 
               $product['margin_percentage'] > 0;
    });
    
    if (count($costOptimizationTargets) > 0) {
        $recommendations['suggested'][] = [
            'type' => 'cost_optimization',
            'affected_products' => count($costOptimizationTargets),
            'issue' => 'Có ' . count($costOptimizationTargets) . ' sản phẩm bán chạy nhưng margin thấp',
            'recommendation' => 'Đàm phán giảm cost với supplier cho các sản phẩm volume cao',
            'priority_score' => 65,
            'volume_opportunity' => array_sum(array_column($costOptimizationTargets, 'total_quantity_sold'))
        ];
    }
    
    return $recommendations;
}

// Chart formatting functions
function formatProfitabilityChartData($productProfitability) {
    $topProducts = array_slice($productProfitability, 0, 10);
    
    $labels = [];
    $profits = [];
    $revenues = [];
    
    foreach ($topProducts as $product) {
        $labels[] = substr($product['product_name'], 0, 20) . '...';
        $profits[] = $product['total_profit'];
        $revenues[] = $product['total_revenue'];
    }
    
    return [
        'labels' => $labels,
        'datasets' => [
            [
                'label' => 'Lợi nhuận',
                'data' => $profits,
                'backgroundColor' => '#1cc88a',
                'yAxisID' => 'y'
            ],
            [
                'label' => 'Doanh thu',
                'data' => $revenues,
                'backgroundColor' => '#36b9cc',
                'yAxisID' => 'y1'
            ]
        ]
    ];
}

function formatMarginChartData($categoryMargins) {
    $labels = [];
    $margins = [];
    $colors = [];
    
    foreach ($categoryMargins as $category) {
        $labels[] = $category['category_name'];
        $margins[] = $category['avg_margin_percentage'];
        
        if ($category['avg_margin_percentage'] >= 25) {
            $colors[] = '#28a745';
        } elseif ($category['avg_margin_percentage'] >= 15) {
            $colors[] = '#ffc107';
        } else {
            $colors[] = '#dc3545';
        }
    }
    
    return [
        'labels' => $labels,
        'datasets' => [
            [
                'label' => 'Margin (%)',
                'data' => $margins,
                'backgroundColor' => $colors
            ]
        ]
    ];
}

function formatProfitTrendChartData($monthlyProfits) {
    $last12Months = array_slice(array_reverse($monthlyProfits), 0, 12);
    
    $labels = [];
    $revenues = [];
    $profits = [];
    $margins = [];
    
    foreach ($last12Months as $month) {
        $labels[] = $month['month_year'];
        $revenues[] = $month['total_revenue'];
        $profits[] = $month['total_profit'];
        $margins[] = $month['profit_margin_percentage'];
    }
    
    return [
        'labels' => $labels,
        'datasets' => [
            [
                'label' => 'Doanh thu',
                'data' => $revenues,
                'borderColor' => '#4e73df',
                'backgroundColor' => 'rgba(78, 115, 223, 0.1)',
                'yAxisID' => 'y'
            ],
            [
                'label' => 'Lợi nhuận',
                'data' => $profits,
                'borderColor' => '#1cc88a',
                'backgroundColor' => 'rgba(28, 200, 138, 0.1)',
                'yAxisID' => 'y'
            ],
            [
                'label' => 'Margin (%)',
                'data' => $margins,
                'borderColor' => '#e74a3b',
                'backgroundColor' => 'rgba(231, 74, 59, 0.1)',
                'yAxisID' => 'y1',
                'type' => 'line'
            ]
        ]
    ];
}

function formatPricingOpportunityChartData($pricingOpportunities) {
    $opportunities = array_filter($pricingOpportunities, function($item) {
        return $item['potential_additional_profit'] > 0;
    });
    
    $opportunities = array_slice($opportunities, 0, 10);
    
    $labels = [];
    $potentialProfits = [];
    
    foreach ($opportunities as $item) {
        $labels[] = substr($item['product_name'], 0, 15) . '...';
        $potentialProfits[] = $item['potential_additional_profit'];
    }
    
    return [
        'labels' => $labels,
        'datasets' => [
            [
                'label' => 'Potential Additional Profit',
                'data' => $potentialProfits,
                'backgroundColor' => '#f6c23e'
            ]
        ]
    ];
}

// Summary calculation functions
function calculateTotalRevenue($monthlyProfits) {
    return array_sum(array_column($monthlyProfits, 'total_revenue'));
}

function calculateTotalProfit($monthlyProfits) {
    return array_sum(array_column($monthlyProfits, 'total_profit'));
}

function calculateAverageMargin($categoryMargins) {
    if (count($categoryMargins) === 0) return 0;
    
    $totalRevenue = array_sum(array_column($categoryMargins, 'total_revenue'));
    $totalProfit = array_sum(array_column($categoryMargins, 'total_profit'));
    
    return $totalRevenue > 0 ? round(($totalProfit / $totalRevenue) * 100, 2) : 0;
}

function getTopProfitableProducts($productProfitability, $limit) {
    return array_slice($productProfitability, 0, $limit);
}
?>