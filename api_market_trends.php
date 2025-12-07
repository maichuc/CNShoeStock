<?php
/**
 * API: Market Trends & Forecasting Analysis  
 * Module 7 của AI Analytics Dashboard
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
    // 1. Sales Trend Analysis & Forecasting
    $salesTrends = getSalesTrendAnalysis($pdo, $warehouseId);
    
    // 2. Product Lifecycle & Market Position
    $productLifecycle = getProductLifecycleAnalysis($pdo, $warehouseId);
    
    // 3. Competitive Analysis (based on pricing & demand)
    $competitiveAnalysis = getCompetitiveAnalysis($pdo, $warehouseId);
    
    // 4. Demand Forecasting
    $demandForecast = getDemandForecastAnalysis($pdo, $warehouseId);
    
    // 5. Market Opportunity Analysis
    $marketOpportunities = getMarketOpportunityAnalysis($pdo, $warehouseId);
    
    // 6. AI Predictions & Strategic Recommendations
    $aiPredictions = generateMarketPredictions($salesTrends, $productLifecycle, $demandForecast);
    
    $response = [
        'success' => true,
        'data' => [
            'sales_trends' => $salesTrends,
            'product_lifecycle' => $productLifecycle,
            'competitive_analysis' => $competitiveAnalysis,
            'demand_forecast' => $demandForecast,
            'market_opportunities' => $marketOpportunities,
            'ai_predictions' => $aiPredictions,
            'charts_data' => [
                'trend_chart' => formatTrendChartData($salesTrends),
                'lifecycle_chart' => formatLifecycleChartData($productLifecycle),
                'forecast_chart' => formatForecastChartData($demandForecast),
                'opportunity_chart' => formatOpportunityChartData($marketOpportunities)
            ],
            'summary' => [
                'growth_rate' => calculateGrowthRate($salesTrends),
                'trending_categories' => getTrendingCategories($salesTrends),
                'declining_products' => getDecliningProducts($productLifecycle),
                'forecast_accuracy' => calculateForecastAccuracy($demandForecast)
            ]
        ],
        'timestamp' => time()
    ];
    
    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Market trends analysis failed: ' . $e->getMessage()
    ]);
}

function getSalesTrendAnalysis($pdo, $warehouseId) {
    $sql = "SELECT 
                DATE_FORMAT(o.created_at, '%Y-%m') as month_year,
                YEAR(o.created_at) as year,
                MONTH(o.created_at) as month,
                QUARTER(o.created_at) as quarter,
                
                -- Sales metrics
                COUNT(DISTINCT o.order_id) as total_orders,
                COUNT(DISTINCT o.customer_id) as unique_customers,
                SUM(od.quantity) as total_quantity_sold,
                SUM(od.quantity * od.unit_price) as total_revenue,
                AVG(order_totals.order_total) as avg_order_value,
                
                -- Growth calculations (month-over-month)
                LAG(SUM(od.quantity * od.unit_price), 1) OVER (ORDER BY YEAR(o.created_at), MONTH(o.created_at)) as prev_month_revenue,
                LAG(COUNT(DISTINCT o.order_id), 1) OVER (ORDER BY YEAR(o.created_at), MONTH(o.created_at)) as prev_month_orders,
                
                -- Year-over-year comparison
                LAG(SUM(od.quantity * od.unit_price), 12) OVER (ORDER BY YEAR(o.created_at), MONTH(o.created_at)) as same_month_last_year_revenue,
                
                -- Category performance
                c.name as category_name,
                SUM(CASE WHEN c.category_id IS NOT NULL THEN od.quantity * od.unit_price ELSE 0 END) as category_revenue,
                
                -- Market trends indicators
                COUNT(DISTINCT p.product_id) as unique_products_sold,
                AVG(od.quantity) as avg_quantity_per_item,
                
                -- Customer acquisition
                COUNT(DISTINCT CASE WHEN customer_first_order.first_order_month = DATE_FORMAT(o.created_at, '%Y-%m') THEN o.customer_id END) as new_customers,
                
                -- Seasonal indicators
                CASE 
                    WHEN MONTH(o.created_at) IN (12, 1, 2) THEN 'Winter'
                    WHEN MONTH(o.created_at) IN (3, 4, 5) THEN 'Spring'
                    WHEN MONTH(o.created_at) IN (6, 7, 8) THEN 'Summer'
                    ELSE 'Fall'
                END as season
                
            FROM orders o
            JOIN order_details od ON o.order_id = od.order_id
            JOIN product_variants pv ON od.variant_id = pv.variant_id
            JOIN products p ON pv.product_id = p.product_id
            LEFT JOIN categories c ON p.category_id = c.category_id
            
            LEFT JOIN (
                SELECT 
                    o2.order_id,
                    SUM(od2.quantity * od2.unit_price) as order_total
                FROM orders o2
                JOIN order_details od2 ON o2.order_id = od2.order_id
                WHERE o2.status = 'delivered'
                GROUP BY o2.order_id
            ) order_totals ON o.order_id = order_totals.order_id
            
            LEFT JOIN (
                SELECT 
                    customer_id,
                    DATE_FORMAT(MIN(created_at), '%Y-%m') as first_order_month
                FROM orders
                WHERE status = 'delivered'
                GROUP BY customer_id
            ) customer_first_order ON o.customer_id = customer_first_order.customer_id
            
            WHERE o.status = 'delivered'
                AND o.warehouse_id = :warehouse_id
                AND o.created_at >= DATE_SUB(NOW(), INTERVAL 24 MONTH)
            GROUP BY 
                DATE_FORMAT(o.created_at, '%Y-%m'),
                c.category_id
            ORDER BY year DESC, month DESC, category_revenue DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
    $stmt->execute();
    
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate growth rates and trends
    foreach ($results as &$row) {
        // Month-over-month growth
        if ($row['prev_month_revenue'] > 0) {
            $row['mom_revenue_growth'] = round(
                (($row['total_revenue'] - $row['prev_month_revenue']) / $row['prev_month_revenue']) * 100, 2
            );
        } else {
            $row['mom_revenue_growth'] = null;
        }
        
        // Year-over-year growth
        if ($row['same_month_last_year_revenue'] > 0) {
            $row['yoy_revenue_growth'] = round(
                (($row['total_revenue'] - $row['same_month_last_year_revenue']) / $row['same_month_last_year_revenue']) * 100, 2
            );
        } else {
            $row['yoy_revenue_growth'] = null;
        }
        
        // Trend classification
        if ($row['mom_revenue_growth'] !== null) {
            if ($row['mom_revenue_growth'] > 10) {
                $row['trend'] = 'strong_growth';
            } elseif ($row['mom_revenue_growth'] > 0) {
                $row['trend'] = 'growth';
            } elseif ($row['mom_revenue_growth'] > -10) {
                $row['trend'] = 'stable';
            } else {
                $row['trend'] = 'decline';
            }
        } else {
            $row['trend'] = 'new';
        }
    }
    
    return $results;
}

function getProductLifecycleAnalysis($pdo, $warehouseId) {
    $sql = "SELECT 
                p.product_id,
                p.name as product_name,
                pv.sku,
                c.name as category_name,
                p.created_at as launch_date,
                DATEDIFF(NOW(), p.created_at) as days_in_market,
                
                -- Sales performance over time
                COALESCE(first_quarter.sales, 0) as first_quarter_sales,
                COALESCE(recent_quarter.sales, 0) as recent_quarter_sales,
                COALESCE(total_sales.total_sales, 0) as total_lifetime_sales,
                COALESCE(total_sales.total_revenue, 0) as total_lifetime_revenue,
                
                -- Market position indicators
                COALESCE(market_share.category_rank, 999) as category_rank,
                COALESCE(velocity.avg_monthly_sales, 0) as avg_monthly_velocity,
                
                -- Growth trajectory
                CASE 
                    WHEN COALESCE(recent_quarter.sales, 0) > COALESCE(first_quarter.sales, 0) * 1.5 THEN 'growth'
                    WHEN COALESCE(recent_quarter.sales, 0) > COALESCE(first_quarter.sales, 0) * 0.8 THEN 'maturity'
                    WHEN COALESCE(recent_quarter.sales, 0) > 0 THEN 'decline'
                    ELSE 'discontinued'
                END as lifecycle_stage,
                
                -- Competitive metrics
                competitor_analysis.avg_category_price,
                (COALESCE(pv.selling_price, 0) / NULLIF(competitor_analysis.avg_category_price, 0)) as price_competitiveness,
                
                -- Innovation indicators
                DATEDIFF(NOW(), MAX(pv_updates.last_price_update)) as days_since_price_update,
                COUNT(DISTINCT pv_all.variant_id) as total_variants,
                
                -- Customer adoption
                COUNT(DISTINCT recent_customers.customer_id) as recent_unique_customers,
                COALESCE(customer_retention.repeat_rate, 0) as customer_repeat_rate
                
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.category_id
            LEFT JOIN product_variants pv ON p.product_id = pv.product_id
            LEFT JOIN product_variants pv_all ON p.product_id = pv_all.product_id
            
            -- First quarter sales (first 3 months after launch)
            LEFT JOIN (
                SELECT 
                    p1.product_id,
                    SUM(od.quantity) as sales
                FROM products p1
                JOIN product_variants pv1 ON p1.product_id = pv1.product_id
                JOIN order_details od ON pv1.variant_id = od.variant_id
                JOIN orders o ON od.order_id = o.order_id
                WHERE o.status = 'delivered'
                    AND o.created_at BETWEEN p1.created_at AND DATE_ADD(p1.created_at, INTERVAL 3 MONTH)
                GROUP BY p1.product_id
            ) first_quarter ON p.product_id = first_quarter.product_id
            
            -- Recent quarter sales
            LEFT JOIN (
                SELECT 
                    p2.product_id,
                    SUM(od.quantity) as sales
                FROM products p2
                JOIN product_variants pv2 ON p2.product_id = pv2.product_id
                JOIN order_details od ON pv2.variant_id = od.variant_id
                JOIN orders o ON od.order_id = o.order_id
                WHERE o.status = 'delivered'
                    AND o.created_at >= DATE_SUB(NOW(), INTERVAL 3 MONTH)
                GROUP BY p2.product_id
            ) recent_quarter ON p.product_id = recent_quarter.product_id
            
            -- Total lifetime sales
            LEFT JOIN (
                SELECT 
                    p3.product_id,
                    SUM(od.quantity) as total_sales,
                    SUM(od.quantity * od.unit_price) as total_revenue
                FROM products p3
                JOIN product_variants pv3 ON p3.product_id = pv3.product_id
                JOIN order_details od ON pv3.variant_id = od.variant_id
                JOIN orders o ON od.order_id = o.order_id
                WHERE o.status = 'delivered'
                GROUP BY p3.product_id
            ) total_sales ON p.product_id = total_sales.product_id
            
            -- Market share within category
            LEFT JOIN (
                SELECT 
                    p4.product_id,
                    RANK() OVER (PARTITION BY p4.category_id ORDER BY SUM(od.quantity) DESC) as category_rank
                FROM products p4
                JOIN product_variants pv4 ON p4.product_id = pv4.product_id
                JOIN order_details od ON pv4.variant_id = od.variant_id
                JOIN orders o ON od.order_id = o.order_id
                WHERE o.status = 'delivered'
                    AND o.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                GROUP BY p4.product_id
            ) market_share ON p.product_id = market_share.product_id
            
            -- Monthly velocity
            LEFT JOIN (
                SELECT 
                    p5.product_id,
                    AVG(monthly_sales.monthly_quantity) as avg_monthly_sales
                FROM products p5
                JOIN (
                    SELECT 
                        pv5.product_id,
                        DATE_FORMAT(o.created_at, '%Y-%m') as month_year,
                        SUM(od.quantity) as monthly_quantity
                    FROM product_variants pv5
                    JOIN order_details od ON pv5.variant_id = od.variant_id
                    JOIN orders o ON od.order_id = o.order_id
                    WHERE o.status = 'delivered'
                        AND o.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                    GROUP BY pv5.product_id, DATE_FORMAT(o.created_at, '%Y-%m')
                ) monthly_sales ON p5.product_id = monthly_sales.product_id
                GROUP BY p5.product_id
            ) velocity ON p.product_id = velocity.product_id
            
            -- Competitor analysis (category average price)
            LEFT JOIN (
                SELECT 
                    p6.category_id,
                    AVG(pv6.selling_price) as avg_category_price
                FROM products p6
                JOIN product_variants pv6 ON p6.product_id = pv6.product_id
                WHERE pv6.selling_price > 0
                GROUP BY p6.category_id
            ) competitor_analysis ON p.category_id = competitor_analysis.category_id
            
            -- Price update tracking
            LEFT JOIN (
                SELECT 
                    product_id,
                    MAX(updated_at) as last_price_update
                FROM product_variants
                WHERE updated_at IS NOT NULL
                GROUP BY product_id
            ) pv_updates ON p.product_id = pv_updates.product_id
            
            -- Recent customers
            LEFT JOIN (
                SELECT DISTINCT 
                    pv7.product_id,
                    o.customer_id
                FROM product_variants pv7
                JOIN order_details od ON pv7.variant_id = od.variant_id
                JOIN orders o ON od.order_id = o.order_id
                WHERE o.status = 'delivered'
                    AND o.created_at >= DATE_SUB(NOW(), INTERVAL 3 MONTH)
            ) recent_customers ON p.product_id = recent_customers.product_id
            
            -- Customer retention
            LEFT JOIN (
                SELECT 
                    pv8.product_id,
                    (COUNT(DISTINCT repeat_customers.customer_id) * 1.0 / NULLIF(COUNT(DISTINCT all_customers.customer_id), 0)) * 100 as repeat_rate
                FROM product_variants pv8
                LEFT JOIN (
                    SELECT DISTINCT od1.variant_id, o1.customer_id
                    FROM order_details od1
                    JOIN orders o1 ON od1.order_id = o1.order_id
                    WHERE o1.status = 'delivered'
                ) all_customers ON pv8.variant_id = all_customers.variant_id
                LEFT JOIN (
                    SELECT od2.variant_id, o2.customer_id
                    FROM order_details od2
                    JOIN orders o2 ON od2.order_id = o2.order_id
                    WHERE o2.status = 'delivered'
                    GROUP BY od2.variant_id, o2.customer_id
                    HAVING COUNT(*) > 1
                ) repeat_customers ON pv8.variant_id = repeat_customers.variant_id AND all_customers.customer_id = repeat_customers.customer_id
                GROUP BY pv8.product_id
            ) customer_retention ON p.product_id = customer_retention.product_id
            
            WHERE p.warehouse_id = :warehouse_id
            GROUP BY p.product_id, pv.variant_id
            ORDER BY total_lifetime_revenue DESC, total_lifetime_sales DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
    $stmt->execute();
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getCompetitiveAnalysis($pdo, $warehouseId) {
    $sql = "SELECT 
                c.name as category_name,
                
                -- Price positioning
                COUNT(DISTINCT p.product_id) as total_products,
                AVG(pv.selling_price) as avg_selling_price,
                MIN(pv.selling_price) as min_price,
                MAX(pv.selling_price) as max_price,
                STDDEV(pv.selling_price) as price_variance,
                
                -- Market share indicators (based on sales volume)
                SUM(sales_data.total_quantity) as category_volume,
                SUM(sales_data.total_revenue) as category_revenue,
                
                -- Top performers in category
                top_performer.top_product_name,
                top_performer.top_product_sales,
                
                -- Price competitiveness segments
                COUNT(CASE WHEN pv.selling_price <= price_segments.low_threshold THEN 1 END) as budget_segment_count,
                COUNT(CASE WHEN pv.selling_price > price_segments.low_threshold AND pv.selling_price <= price_segments.high_threshold THEN 1 END) as mid_segment_count,
                COUNT(CASE WHEN pv.selling_price > price_segments.high_threshold THEN 1 END) as premium_segment_count,
                
                -- Performance metrics
                AVG(sales_data.conversion_rate) as avg_conversion_rate,
                AVG(sales_data.velocity) as avg_sales_velocity,
                
                -- Market trends
                growth_data.category_growth_rate,
                growth_data.growth_trend
                
            FROM categories c
            LEFT JOIN products p ON c.category_id = p.category_id
            LEFT JOIN product_variants pv ON p.product_id = pv.product_id
            
            -- Sales data aggregation
            LEFT JOIN (
                SELECT 
                    p1.category_id,
                    p1.product_id,
                    SUM(od.quantity) as total_quantity,
                    SUM(od.quantity * od.unit_price) as total_revenue,
                    COUNT(DISTINCT o.customer_id) as unique_customers,
                    COUNT(DISTINCT o.customer_id) * 1.0 / NULLIF(COUNT(DISTINCT o.order_id), 0) as conversion_rate,
                    SUM(od.quantity) * 1.0 / 12 as velocity  -- Monthly average
                FROM products p1
                JOIN product_variants pv1 ON p1.product_id = pv1.product_id
                JOIN order_details od ON pv1.variant_id = od.variant_id
                JOIN orders o ON od.order_id = o.order_id
                WHERE o.status = 'delivered'
                    AND o.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                    AND p1.warehouse_id = :warehouse_id
                GROUP BY p1.category_id, p1.product_id
            ) sales_data ON p.category_id = sales_data.category_id AND p.product_id = sales_data.product_id
            
            -- Price segmentation thresholds
            LEFT JOIN (
                SELECT 
                    c1.category_id,
                    PERCENTILE_CONT(0.33) WITHIN GROUP (ORDER BY pv2.selling_price) as low_threshold,
                    PERCENTILE_CONT(0.67) WITHIN GROUP (ORDER BY pv2.selling_price) as high_threshold
                FROM categories c1
                JOIN products p2 ON c1.category_id = p2.category_id
                JOIN product_variants pv2 ON p2.product_id = pv2.product_id
                WHERE p2.warehouse_id = :warehouse_id
                    AND pv2.selling_price > 0
                GROUP BY c1.category_id
            ) price_segments ON c.category_id = price_segments.category_id
            
            -- Top performer identification
            LEFT JOIN (
                SELECT 
                    p3.category_id,
                    p3.name as top_product_name,
                    sales_ranking.total_sales as top_product_sales
                FROM products p3
                JOIN (
                    SELECT 
                        p4.product_id,
                        SUM(od.quantity) as total_sales,
                        ROW_NUMBER() OVER (PARTITION BY p4.category_id ORDER BY SUM(od.quantity) DESC) as rn
                    FROM products p4
                    JOIN product_variants pv4 ON p4.product_id = pv4.product_id
                    JOIN order_details od ON pv4.variant_id = od.variant_id
                    JOIN orders o ON od.order_id = o.order_id
                    WHERE o.status = 'delivered'
                        AND o.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                        AND p4.warehouse_id = :warehouse_id
                    GROUP BY p4.product_id
                ) sales_ranking ON p3.product_id = sales_ranking.product_id
                WHERE sales_ranking.rn = 1
            ) top_performer ON c.category_id = top_performer.category_id
            
            -- Growth rate calculation
            LEFT JOIN (
                SELECT 
                    p5.category_id,
                    CASE 
                        WHEN recent_sales.recent_revenue > 0 AND previous_sales.previous_revenue > 0
                        THEN ROUND(((recent_sales.recent_revenue - previous_sales.previous_revenue) / previous_sales.previous_revenue) * 100, 2)
                        ELSE NULL
                    END as category_growth_rate,
                    CASE 
                        WHEN recent_sales.recent_revenue > previous_sales.previous_revenue * 1.1 THEN 'growing'
                        WHEN recent_sales.recent_revenue > previous_sales.previous_revenue * 0.9 THEN 'stable'
                        ELSE 'declining'
                    END as growth_trend
                FROM (SELECT DISTINCT category_id FROM products WHERE warehouse_id = :warehouse_id) p5
                LEFT JOIN (
                    SELECT 
                        p6.category_id,
                        SUM(od.quantity * od.unit_price) as recent_revenue
                    FROM products p6
                    JOIN product_variants pv6 ON p6.product_id = pv6.product_id
                    JOIN order_details od ON pv6.variant_id = od.variant_id
                    JOIN orders o ON od.order_id = o.order_id
                    WHERE o.status = 'delivered'
                        AND o.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                        AND p6.warehouse_id = :warehouse_id
                    GROUP BY p6.category_id
                ) recent_sales ON p5.category_id = recent_sales.category_id
                LEFT JOIN (
                    SELECT 
                        p7.category_id,
                        SUM(od.quantity * od.unit_price) as previous_revenue
                    FROM products p7
                    JOIN product_variants pv7 ON p7.product_id = pv7.product_id
                    JOIN order_details od ON pv7.variant_id = od.variant_id
                    JOIN orders o ON od.order_id = o.order_id
                    WHERE o.status = 'delivered'
                        AND o.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                        AND o.created_at < DATE_SUB(NOW(), INTERVAL 6 MONTH)
                        AND p7.warehouse_id = :warehouse_id
                    GROUP BY p7.category_id
                ) previous_sales ON p5.category_id = previous_sales.category_id
            ) growth_data ON c.category_id = growth_data.category_id
            
            WHERE p.warehouse_id = :warehouse_id
            GROUP BY c.category_id
            HAVING total_products > 0
            ORDER BY category_revenue DESC, category_volume DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
    $stmt->execute();
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getDemandForecastAnalysis($pdo, $warehouseId) {
    $sql = "SELECT 
                p.product_id,
                p.name as product_name,
                c.name as category_name,
                
                -- Historical demand (monthly data for last 12 months)
                historical_data.monthly_demands,
                historical_data.avg_monthly_demand,
                historical_data.demand_variance,
                historical_data.trend_slope,
                
                -- Seasonality analysis
                seasonal_data.seasonality_factor,
                seasonal_data.peak_season,
                seasonal_data.low_season,
                
                -- Current stock and velocity
                COALESCE(i.quantity, 0) as current_stock,
                COALESCE(velocity.avg_daily_sales, 0) as avg_daily_sales,
                
                -- Stock-out risk calculation
                CASE 
                    WHEN COALESCE(velocity.avg_daily_sales, 0) > 0 
                    THEN ROUND(COALESCE(i.quantity, 0) / velocity.avg_daily_sales, 0)
                    ELSE 999
                END as days_of_stock_remaining,
                
                -- Forecast for next 3 months (simplified linear + seasonal)
                ROUND(
                    COALESCE(historical_data.avg_monthly_demand, 0) * 3 * 
                    (1 + COALESCE(historical_data.trend_slope, 0) / 100) * 
                    COALESCE(seasonal_data.seasonality_factor, 1), 0
                ) as forecast_next_3_months,
                
                -- Recommended reorder point
                ROUND(
                    COALESCE(velocity.avg_daily_sales, 0) * 30 + 
                    COALESCE(velocity.avg_daily_sales, 0) * 7, 0  -- 30 days demand + 7 days safety
                ) as recommended_reorder_point,
                
                -- Market growth impact
                category_growth.growth_multiplier
                
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.category_id
            LEFT JOIN product_variants pv ON p.product_id = pv.product_id
            LEFT JOIN inventory i ON pv.variant_id = i.variant_id
            
            -- Historical demand analysis
            LEFT JOIN (
                SELECT 
                    p1.product_id,
                    GROUP_CONCAT(monthly_demand.demand ORDER BY monthly_demand.month_year) as monthly_demands,
                    AVG(monthly_demand.demand) as avg_monthly_demand,
                    STDDEV(monthly_demand.demand) as demand_variance,
                    
                    -- Simple trend calculation (linear regression slope approximation)
                    (SUM((month_number - 6.5) * demand) / NULLIF(SUM(POWER(month_number - 6.5, 2)), 0)) as trend_slope
                    
                FROM products p1
                LEFT JOIN (
                    SELECT 
                        pv1.product_id,
                        DATE_FORMAT(o.created_at, '%Y-%m') as month_year,
                        ROW_NUMBER() OVER (ORDER BY DATE_FORMAT(o.created_at, '%Y-%m')) as month_number,
                        SUM(od.quantity) as demand
                    FROM product_variants pv1
                    JOIN order_details od ON pv1.variant_id = od.variant_id
                    JOIN orders o ON od.order_id = o.order_id
                    WHERE o.status = 'delivered'
                        AND o.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                    GROUP BY pv1.product_id, DATE_FORMAT(o.created_at, '%Y-%m')
                ) monthly_demand ON p1.product_id = monthly_demand.product_id
                WHERE p1.warehouse_id = :warehouse_id
                GROUP BY p1.product_id
            ) historical_data ON p.product_id = historical_data.product_id
            
            -- Seasonality analysis
            LEFT JOIN (
                SELECT 
                    p2.product_id,
                    CASE 
                        WHEN MAX(seasonal_demand.demand) > 0 
                        THEN AVG(seasonal_demand.demand) / NULLIF(MAX(seasonal_demand.demand), 0)
                        ELSE 1
                    END as seasonality_factor,
                    season_peaks.peak_season,
                    season_lows.low_season
                FROM products p2
                LEFT JOIN (
                    SELECT 
                        pv2.product_id,
                        QUARTER(o.created_at) as quarter,
                        AVG(od.quantity) as demand
                    FROM product_variants pv2
                    JOIN order_details od ON pv2.variant_id = od.variant_id
                    JOIN orders o ON od.order_id = o.order_id
                    WHERE o.status = 'delivered'
                        AND o.created_at >= DATE_SUB(NOW(), INTERVAL 24 MONTH)
                    GROUP BY pv2.product_id, QUARTER(o.created_at)
                ) seasonal_demand ON p2.product_id = seasonal_demand.product_id
                LEFT JOIN (
                    SELECT 
                        pv3.product_id,
                        CASE quarter_with_max.max_quarter
                            WHEN 1 THEN 'Q1'
                            WHEN 2 THEN 'Q2'
                            WHEN 3 THEN 'Q3'
                            WHEN 4 THEN 'Q4'
                        END as peak_season
                    FROM product_variants pv3
                    JOIN (
                        SELECT 
                            pv4.product_id,
                            QUARTER(o.created_at) as max_quarter,
                            ROW_NUMBER() OVER (PARTITION BY pv4.product_id ORDER BY SUM(od.quantity) DESC) as rn
                        FROM product_variants pv4
                        JOIN order_details od ON pv4.variant_id = od.variant_id
                        JOIN orders o ON od.order_id = o.order_id
                        WHERE o.status = 'delivered'
                        GROUP BY pv4.product_id, QUARTER(o.created_at)
                    ) quarter_with_max ON pv3.product_id = quarter_with_max.product_id
                    WHERE quarter_with_max.rn = 1
                ) season_peaks ON p2.product_id = season_peaks.product_id
                LEFT JOIN (
                    SELECT 
                        pv5.product_id,
                        CASE quarter_with_min.min_quarter
                            WHEN 1 THEN 'Q1'
                            WHEN 2 THEN 'Q2'
                            WHEN 3 THEN 'Q3'
                            WHEN 4 THEN 'Q4'
                        END as low_season
                    FROM product_variants pv5
                    JOIN (
                        SELECT 
                            pv6.product_id,
                            QUARTER(o.created_at) as min_quarter,
                            ROW_NUMBER() OVER (PARTITION BY pv6.product_id ORDER BY SUM(od.quantity) ASC) as rn
                        FROM product_variants pv6
                        JOIN order_details od ON pv6.variant_id = od.variant_id
                        JOIN orders o ON od.order_id = o.order_id
                        WHERE o.status = 'delivered'
                        GROUP BY pv6.product_id, QUARTER(o.created_at)
                    ) quarter_with_min ON pv5.product_id = quarter_with_min.product_id
                    WHERE quarter_with_min.rn = 1
                ) season_lows ON p2.product_id = season_lows.product_id
                WHERE p2.warehouse_id = :warehouse_id
                GROUP BY p2.product_id
            ) seasonal_data ON p.product_id = seasonal_data.product_id
            
            -- Velocity calculation
            LEFT JOIN (
                SELECT 
                    pv7.product_id,
                    SUM(od.quantity) / 30.0 as avg_daily_sales
                FROM product_variants pv7
                JOIN order_details od ON pv7.variant_id = od.variant_id
                JOIN orders o ON od.order_id = o.order_id
                WHERE o.status = 'delivered'
                    AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY pv7.product_id
            ) velocity ON p.product_id = velocity.product_id
            
            -- Category growth multiplier
            LEFT JOIN (
                SELECT 
                    c1.category_id,
                    CASE 
                        WHEN recent_growth.growth_rate > 20 THEN 1.2
                        WHEN recent_growth.growth_rate > 10 THEN 1.1
                        WHEN recent_growth.growth_rate > 0 THEN 1.05
                        WHEN recent_growth.growth_rate > -10 THEN 0.95
                        ELSE 0.9
                    END as growth_multiplier
                FROM categories c1
                LEFT JOIN (
                    SELECT 
                        p8.category_id,
                        CASE 
                            WHEN past_sales.past_revenue > 0
                            THEN ((recent_sales.recent_revenue - past_sales.past_revenue) / past_sales.past_revenue) * 100
                            ELSE 0
                        END as growth_rate
                    FROM products p8
                    LEFT JOIN (
                        SELECT 
                            p9.category_id,
                            SUM(od.quantity * od.unit_price) as recent_revenue
                        FROM products p9
                        JOIN product_variants pv9 ON p9.product_id = pv9.product_id
                        JOIN order_details od ON pv9.variant_id = od.variant_id
                        JOIN orders o ON od.order_id = o.order_id
                        WHERE o.status = 'delivered'
                            AND o.created_at >= DATE_SUB(NOW(), INTERVAL 3 MONTH)
                        GROUP BY p9.category_id
                    ) recent_sales ON p8.category_id = recent_sales.category_id
                    LEFT JOIN (
                        SELECT 
                            p10.category_id,
                            SUM(od.quantity * od.unit_price) as past_revenue
                        FROM products p10
                        JOIN product_variants pv10 ON p10.product_id = pv10.product_id
                        JOIN order_details od ON pv10.variant_id = od.variant_id
                        JOIN orders o ON od.order_id = o.order_id
                        WHERE o.status = 'delivered'
                            AND o.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                            AND o.created_at < DATE_SUB(NOW(), INTERVAL 3 MONTH)
                        GROUP BY p10.category_id
                    ) past_sales ON p8.category_id = past_sales.category_id
                    WHERE p8.warehouse_id = :warehouse_id
                    GROUP BY p8.category_id
                ) recent_growth ON c1.category_id = recent_growth.category_id
            ) category_growth ON p.category_id = category_growth.category_id
            
            WHERE p.warehouse_id = :warehouse_id
            ORDER BY forecast_next_3_months DESC, avg_daily_sales DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
    $stmt->execute();
    
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Add risk classification
    foreach ($results as &$product) {
        if ($product['days_of_stock_remaining'] <= 7) {
            $product['stock_risk'] = 'critical';
        } elseif ($product['days_of_stock_remaining'] <= 30) {
            $product['stock_risk'] = 'high';
        } elseif ($product['days_of_stock_remaining'] <= 60) {
            $product['stock_risk'] = 'medium';
        } else {
            $product['stock_risk'] = 'low';
        }
        
        // Forecast accuracy indicator (simplified)
        if ($product['demand_variance'] && $product['avg_monthly_demand'] > 0) {
            $cv = $product['demand_variance'] / $product['avg_monthly_demand'];
            if ($cv < 0.3) {
                $product['forecast_confidence'] = 'high';
            } elseif ($cv < 0.6) {
                $product['forecast_confidence'] = 'medium';
            } else {
                $product['forecast_confidence'] = 'low';
            }
        } else {
            $product['forecast_confidence'] = 'insufficient_data';
        }
    }
    
    return $results;
}

function getMarketOpportunityAnalysis($pdo, $warehouseId) {
    $sql = "SELECT 
                'category_expansion' as opportunity_type,
                c.name as opportunity_name,
                expansion_metrics.untapped_potential,
                expansion_metrics.market_size_estimate,
                expansion_metrics.competition_level,
                expansion_metrics.growth_rate,
                expansion_metrics.investment_required,
                expansion_metrics.expected_roi,
                'Add new products to high-growth, low-competition categories' as recommendation
                
            FROM categories c
            LEFT JOIN (
                SELECT 
                    c1.category_id,
                    -- Estimate untapped potential based on low product count but high demand indicators
                    CASE 
                        WHEN COUNT(DISTINCT p1.product_id) < 5 AND SUM(COALESCE(sales.demand_score, 0)) > 100 
                        THEN 'high'
                        WHEN COUNT(DISTINCT p1.product_id) < 10 AND SUM(COALESCE(sales.demand_score, 0)) > 50 
                        THEN 'medium'
                        ELSE 'low'
                    END as untapped_potential,
                    
                    -- Market size estimate
                    SUM(COALESCE(sales.total_revenue, 0)) as market_size_estimate,
                    
                    -- Competition level
                    CASE 
                        WHEN COUNT(DISTINCT p1.product_id) > 20 THEN 'high'
                        WHEN COUNT(DISTINCT p1.product_id) > 10 THEN 'medium'
                        ELSE 'low'
                    END as competition_level,
                    
                    -- Growth rate
                    growth.category_growth_rate as growth_rate,
                    
                    -- Investment estimate (based on average product cost)
                    AVG(pv1.cost_price) * 10 as investment_required,
                    
                    -- Expected ROI (simplified)
                    CASE 
                        WHEN growth.category_growth_rate > 20 THEN 'high'
                        WHEN growth.category_growth_rate > 10 THEN 'medium'
                        ELSE 'low'
                    END as expected_roi
                    
                FROM categories c1
                LEFT JOIN products p1 ON c1.category_id = p1.category_id AND p1.warehouse_id = :warehouse_id
                LEFT JOIN product_variants pv1 ON p1.product_id = pv1.product_id
                LEFT JOIN (
                    SELECT 
                        p2.category_id,
                        SUM(od.quantity) as demand_score,
                        SUM(od.quantity * od.unit_price) as total_revenue
                    FROM products p2
                    JOIN product_variants pv2 ON p2.product_id = pv2.product_id
                    JOIN order_details od ON pv2.variant_id = od.variant_id
                    JOIN orders o ON od.order_id = o.order_id
                    WHERE o.status = 'delivered'
                        AND o.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                        AND p2.warehouse_id = :warehouse_id
                    GROUP BY p2.category_id
                ) sales ON c1.category_id = sales.category_id
                LEFT JOIN (
                    SELECT 
                        p3.category_id,
                        CASE 
                            WHEN past_period.past_revenue > 0
                            THEN ((recent_period.recent_revenue - past_period.past_revenue) / past_period.past_revenue) * 100
                            ELSE 0
                        END as category_growth_rate
                    FROM (SELECT DISTINCT category_id FROM products WHERE warehouse_id = :warehouse_id) p3
                    LEFT JOIN (
                        SELECT 
                            p4.category_id,
                            SUM(od.quantity * od.unit_price) as recent_revenue
                        FROM products p4
                        JOIN product_variants pv4 ON p4.product_id = pv4.product_id
                        JOIN order_details od ON pv4.variant_id = od.variant_id
                        JOIN orders o ON od.order_id = o.order_id
                        WHERE o.status = 'delivered'
                            AND o.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                            AND p4.warehouse_id = :warehouse_id
                        GROUP BY p4.category_id
                    ) recent_period ON p3.category_id = recent_period.category_id
                    LEFT JOIN (
                        SELECT 
                            p5.category_id,
                            SUM(od.quantity * od.unit_price) as past_revenue
                        FROM products p5
                        JOIN product_variants pv5 ON p5.product_id = pv5.product_id
                        JOIN order_details od ON pv5.variant_id = od.variant_id
                        JOIN orders o ON od.order_id = o.order_id
                        WHERE o.status = 'delivered'
                            AND o.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                            AND o.created_at < DATE_SUB(NOW(), INTERVAL 6 MONTH)
                            AND p5.warehouse_id = :warehouse_id
                        GROUP BY p5.category_id
                    ) past_period ON p3.category_id = past_period.category_id
                ) growth ON c1.category_id = growth.category_id
                GROUP BY c1.category_id
            ) expansion_metrics ON c.category_id = expansion_metrics.category_id
            
            WHERE expansion_metrics.untapped_potential IN ('high', 'medium')
            
            UNION ALL
            
            SELECT 
                'price_optimization' as opportunity_type,
                p.name as opportunity_name,
                pricing_opportunity.optimization_potential,
                pricing_opportunity.revenue_impact,
                'medium' as competition_level,
                10 as growth_rate,
                0 as investment_required,
                pricing_opportunity.expected_roi,
                CONCAT('Adjust price from ', pv.selling_price, ' to ', pricing_opportunity.suggested_price) as recommendation
                
            FROM products p
            JOIN product_variants pv ON p.product_id = pv.product_id
            LEFT JOIN (
                SELECT 
                    p6.product_id,
                    'high' as optimization_potential,
                    (suggested_pricing.suggested_price - pv6.selling_price) * sales_volume.monthly_volume * 12 as revenue_impact,
                    suggested_pricing.suggested_price,
                    'high' as expected_roi
                FROM products p6
                JOIN product_variants pv6 ON p6.product_id = pv6.product_id
                LEFT JOIN (
                    SELECT 
                        p7.product_id,
                        AVG(competitor_price.avg_category_price) * 0.95 as suggested_price
                    FROM products p7
                    LEFT JOIN (
                        SELECT 
                            p8.category_id,
                            AVG(pv8.selling_price) as avg_category_price
                        FROM products p8
                        JOIN product_variants pv8 ON p8.product_id = pv8.product_id
                        WHERE p8.warehouse_id = :warehouse_id
                        GROUP BY p8.category_id
                    ) competitor_price ON p7.category_id = competitor_price.category_id
                    WHERE p7.warehouse_id = :warehouse_id
                    GROUP BY p7.product_id
                ) suggested_pricing ON p6.product_id = suggested_pricing.product_id
                LEFT JOIN (
                    SELECT 
                        pv9.product_id,
                        SUM(od.quantity) / 12.0 as monthly_volume
                    FROM product_variants pv9
                    JOIN order_details od ON pv9.variant_id = od.variant_id
                    JOIN orders o ON od.order_id = o.order_id
                    WHERE o.status = 'delivered'
                        AND o.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                    GROUP BY pv9.product_id
                ) sales_volume ON p6.product_id = sales_volume.product_id
                WHERE p6.warehouse_id = :warehouse_id
                    AND sales_volume.monthly_volume > 10  -- Only high-volume products
                    AND ABS(pv6.selling_price - suggested_pricing.suggested_price) > pv6.selling_price * 0.05  -- >5% difference
            ) pricing_opportunity ON p.product_id = pricing_opportunity.product_id
            
            WHERE p.warehouse_id = :warehouse_id
                AND pricing_opportunity.optimization_potential = 'high'
            LIMIT 20";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
    $stmt->execute();
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function generateMarketPredictions($salesTrends, $productLifecycle, $demandForecast) {
    $predictions = [
        'market_outlook' => [],
        'growth_predictions' => [],
        'risk_alerts' => [],
        'strategic_recommendations' => []
    ];
    
    // 1. Market Outlook based on trends
    $recentTrends = array_slice($salesTrends, 0, 6); // Last 6 months
    $growthTrends = array_filter($recentTrends, function($trend) {
        return $trend['trend'] === 'strong_growth' || $trend['trend'] === 'growth';
    });
    
    if (count($growthTrends) > count($recentTrends) * 0.6) {
        $predictions['market_outlook'][] = [
            'prediction' => 'market_expansion',
            'confidence' => 'high',
            'timeframe' => '3-6 months',
            'description' => 'Thị trường đang trong giai đoạn mở rộng mạnh',
            'supporting_data' => count($growthTrends) . '/' . count($recentTrends) . ' categories growing'
        ];
    }
    
    // 2. Growth Predictions by Category
    $categoryGrowth = [];
    foreach ($salesTrends as $trend) {
        if (!isset($categoryGrowth[$trend['category_name']])) {
            $categoryGrowth[$trend['category_name']] = [];
        }
        if ($trend['mom_revenue_growth'] !== null) {
            $categoryGrowth[$trend['category_name']][] = $trend['mom_revenue_growth'];
        }
    }
    
    foreach ($categoryGrowth as $category => $growthRates) {
        if (count($growthRates) >= 3) {
            $avgGrowth = array_sum($growthRates) / count($growthRates);
            if ($avgGrowth > 15) {
                $predictions['growth_predictions'][] = [
                    'category' => $category,
                    'predicted_growth' => $avgGrowth,
                    'confidence' => 'medium',
                    'description' => "Dự báo {$category} sẽ tăng trưởng {$avgGrowth}% trong 3 tháng tới"
                ];
            }
        }
    }
    
    // 3. Risk Alerts from demand forecast
    $criticalStockouts = array_filter($demandForecast, function($product) {
        return $product['stock_risk'] === 'critical' && $product['avg_daily_sales'] > 1;
    });
    
    foreach ($criticalStockouts as $product) {
        $predictions['risk_alerts'][] = [
            'type' => 'stockout_risk',
            'product_name' => $product['product_name'],
            'urgency' => 'immediate',
            'description' => "Sản phẩm {$product['product_name']} sẽ hết hàng trong {$product['days_of_stock_remaining']} ngày",
            'recommended_action' => 'Đặt hàng khẩn cấp'
        ];
    }
    
    // 4. Strategic Recommendations
    $decliningProducts = array_filter($productLifecycle, function($product) {
        return $product['lifecycle_stage'] === 'decline' && $product['total_lifetime_revenue'] > 1000000;
    });
    
    if (count($decliningProducts) > 0) {
        $predictions['strategic_recommendations'][] = [
            'type' => 'product_refresh',
            'urgency' => 'medium',
            'description' => count($decliningProducts) . ' sản phẩm có giá trị cao đang trong giai đoạn suy giảm',
            'recommendation' => 'Cần refresh product line hoặc marketing strategy',
            'affected_products' => count($decliningProducts)
        ];
    }
    
    // 5. Market timing recommendations
    $predictions['strategic_recommendations'][] = [
        'type' => 'seasonal_preparation',
        'urgency' => 'low',
        'description' => 'Chuẩn bị cho mùa cao điểm sắp tới',
        'recommendation' => 'Tăng stock cho các sản phẩm có tính seasonal cao',
        'timeframe' => 'Next 2 months'
    ];
    
    return $predictions;
}

// Chart formatting functions
function formatTrendChartData($salesTrends) {
    $monthlyData = [];
    
    // Group by month
    foreach ($salesTrends as $trend) {
        $month = $trend['month_year'];
        if (!isset($monthlyData[$month])) {
            $monthlyData[$month] = [
                'total_revenue' => 0,
                'total_orders' => 0
            ];
        }
        $monthlyData[$month]['total_revenue'] += $trend['total_revenue'];
        $monthlyData[$month]['total_orders'] += $trend['total_orders'];
    }
    
    ksort($monthlyData);
    $last12Months = array_slice($monthlyData, -12, 12, true);
    
    $labels = array_keys($last12Months);
    $revenues = array_column($last12Months, 'total_revenue');
    $orders = array_column($last12Months, 'total_orders');
    
    return [
        'labels' => $labels,
        'datasets' => [
            [
                'label' => 'Revenue Trend',
                'data' => $revenues,
                'borderColor' => '#1cc88a',
                'backgroundColor' => 'rgba(28, 200, 138, 0.1)',
                'fill' => true
            ],
            [
                'label' => 'Orders',
                'data' => $orders,
                'borderColor' => '#4e73df',
                'backgroundColor' => 'rgba(78, 115, 223, 0.1)',
                'yAxisID' => 'y1'
            ]
        ]
    ];
}

function formatLifecycleChartData($productLifecycle) {
    $stages = [];
    foreach ($productLifecycle as $product) {
        if (!isset($stages[$product['lifecycle_stage']])) {
            $stages[$product['lifecycle_stage']] = 0;
        }
        $stages[$product['lifecycle_stage']]++;
    }
    
    $stageLabels = [
        'growth' => 'Growth',
        'maturity' => 'Maturity',
        'decline' => 'Decline',
        'discontinued' => 'Discontinued'
    ];
    
    $labels = [];
    $data = [];
    $colors = ['#28a745', '#ffc107', '#fd7e14', '#dc3545'];
    
    $colorIndex = 0;
    foreach ($stages as $stage => $count) {
        $labels[] = $stageLabels[$stage] ?? $stage;
        $data[] = $count;
    }
    
    return [
        'labels' => $labels,
        'datasets' => [
            [
                'label' => 'Products',
                'data' => $data,
                'backgroundColor' => array_slice($colors, 0, count($data))
            ]
        ]
    ];
}

function formatForecastChartData($demandForecast) {
    $topProducts = array_slice($demandForecast, 0, 10);
    
    $labels = [];
    $currentStock = [];
    $forecastDemand = [];
    
    foreach ($topProducts as $product) {
        $labels[] = substr($product['product_name'], 0, 15) . '...';
        $currentStock[] = $product['current_stock'];
        $forecastDemand[] = $product['forecast_next_3_months'];
    }
    
    return [
        'labels' => $labels,
        'datasets' => [
            [
                'label' => 'Current Stock',
                'data' => $currentStock,
                'backgroundColor' => '#36b9cc'
            ],
            [
                'label' => 'Forecast Demand (3M)',
                'data' => $forecastDemand,
                'backgroundColor' => '#e74a3b'
            ]
        ]
    ];
}

function formatOpportunityChartData($marketOpportunities) {
    $labels = [];
    $impacts = [];
    
    foreach ($marketOpportunities as $opportunity) {
        $labels[] = substr($opportunity['opportunity_name'], 0, 20) . '...';
        $impacts[] = is_numeric($opportunity['market_size_estimate']) ? 
            $opportunity['market_size_estimate'] : 0;
    }
    
    return [
        'labels' => $labels,
        'datasets' => [
            [
                'label' => 'Market Size Impact',
                'data' => $impacts,
                'backgroundColor' => '#f6c23e'
            ]
        ]
    ];
}

// Summary calculation functions
function calculateGrowthRate($salesTrends) {
    $growthRates = array_filter(array_column($salesTrends, 'mom_revenue_growth'), function($rate) {
        return $rate !== null;
    });
    
    return count($growthRates) > 0 ? round(array_sum($growthRates) / count($growthRates), 2) : 0;
}

function getTrendingCategories($salesTrends) {
    $categoryGrowth = [];
    
    foreach ($salesTrends as $trend) {
        if ($trend['mom_revenue_growth'] !== null && $trend['mom_revenue_growth'] > 10) {
            if (!isset($categoryGrowth[$trend['category_name']])) {
                $categoryGrowth[$trend['category_name']] = 0;
            }
            $categoryGrowth[$trend['category_name']] += $trend['mom_revenue_growth'];
        }
    }
    
    arsort($categoryGrowth);
    return array_slice($categoryGrowth, 0, 5, true);
}

function getDecliningProducts($productLifecycle) {
    return array_filter($productLifecycle, function($product) {
        return $product['lifecycle_stage'] === 'decline';
    });
}

function calculateForecastAccuracy($demandForecast) {
    $confidenceDistribution = array_count_values(array_column($demandForecast, 'forecast_confidence'));
    $total = array_sum($confidenceDistribution);
    
    if ($total === 0) return 0;
    
    $highConfidence = $confidenceDistribution['high'] ?? 0;
    return round(($highConfidence / $total) * 100, 2);
}
?>