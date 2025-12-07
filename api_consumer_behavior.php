<?php
/**
 * API: Consumer Behavior Analysis  
 * Module 5 của AI Analytics Dashboard
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
    // 1. Customer Segmentation Analysis
    $customerSegmentation = getCustomerSegmentationAnalysis($pdo, $warehouseId);
    
    // 2. Purchase Pattern Analysis
    $purchasePatterns = getPurchasePatternAnalysis($pdo, $warehouseId);
    
    // 3. Product Affinity Analysis
    $productAffinity = getProductAffinityAnalysis($pdo, $warehouseId);
    
    // 4. Customer Lifecycle Analysis
    $customerLifecycle = getCustomerLifecycleAnalysis($pdo, $warehouseId);
    
    // 5. Seasonal Behavior Patterns
    $seasonalBehavior = getSeasonalBehaviorPatterns($pdo, $warehouseId);
    
    // 6. AI Recommendations cho marketing và customer retention
    $aiRecommendations = generateBehaviorRecommendations($customerSegmentation, $purchasePatterns, $customerLifecycle);
    
    $response = [
        'success' => true,
        'data' => [
            'customer_segmentation' => $customerSegmentation,
            'purchase_patterns' => $purchasePatterns,
            'product_affinity' => $productAffinity,
            'customer_lifecycle' => $customerLifecycle,
            'seasonal_behavior' => $seasonalBehavior,
            'ai_recommendations' => $aiRecommendations,
            'charts_data' => [
                'segmentation_chart' => formatSegmentationChartData($customerSegmentation),
                'purchase_pattern_chart' => formatPurchasePatternChartData($purchasePatterns),
                'lifecycle_chart' => formatLifecycleChartData($customerLifecycle),
                'seasonal_chart' => formatSeasonalChartData($seasonalBehavior)
            ],
            'summary' => [
                'total_customers' => calculateTotalCustomers($customerSegmentation),
                'avg_purchase_frequency' => calculateAveragePurchaseFrequency($purchasePatterns),
                'customer_retention_rate' => calculateRetentionRate($customerLifecycle),
                'most_popular_categories' => getMostPopularCategories($purchasePatterns)
            ]
        ],
        'timestamp' => time()
    ];
    
    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Consumer behavior analysis failed: ' . $e->getMessage()
    ]);
}

function getCustomerSegmentationAnalysis($pdo, $warehouseId) {
    $sql = "SELECT 
                -- RFM Analysis (Recency, Frequency, Monetary)
                customer_metrics.*,
                
                -- RFM Scoring (1-5 scale)
                CASE 
                    WHEN customer_metrics.days_since_last_order <= 30 THEN 5
                    WHEN customer_metrics.days_since_last_order <= 60 THEN 4
                    WHEN customer_metrics.days_since_last_order <= 90 THEN 3
                    WHEN customer_metrics.days_since_last_order <= 180 THEN 2
                    ELSE 1
                END as recency_score,
                
                CASE 
                    WHEN customer_metrics.total_orders >= 20 THEN 5
                    WHEN customer_metrics.total_orders >= 10 THEN 4
                    WHEN customer_metrics.total_orders >= 5 THEN 3
                    WHEN customer_metrics.total_orders >= 2 THEN 2
                    ELSE 1
                END as frequency_score,
                
                CASE 
                    WHEN customer_metrics.total_spent >= 10000000 THEN 5
                    WHEN customer_metrics.total_spent >= 5000000 THEN 4
                    WHEN customer_metrics.total_spent >= 2000000 THEN 3
                    WHEN customer_metrics.total_spent >= 1000000 THEN 2
                    ELSE 1
                END as monetary_score
                
            FROM (
                SELECT 
                    c.customer_id,
                    c.name as customer_name,
                    c.email,
                    c.phone,
                    
                    -- Recency
                    COALESCE(DATEDIFF(NOW(), MAX(o.created_at)), 999) as days_since_last_order,
                    
                    -- Frequency
                    COUNT(DISTINCT o.order_id) as total_orders,
                    ROUND(COUNT(DISTINCT o.order_id) / NULLIF(DATEDIFF(MAX(o.created_at), MIN(o.created_at)), 0) * 30, 2) as avg_orders_per_month,
                    
                    -- Monetary
                    COALESCE(SUM(od.quantity * od.unit_price), 0) as total_spent,
                    COALESCE(AVG(order_totals.order_total), 0) as avg_order_value,
                    
                    -- Additional metrics
                    MIN(o.created_at) as first_order_date,
                    MAX(o.created_at) as last_order_date,
                    COUNT(DISTINCT YEAR(o.created_at), MONTH(o.created_at)) as active_months,
                    COUNT(DISTINCT p.category_id) as categories_purchased,
                    
                    -- Preferred shopping time
                    HOUR(AVG(TIME(o.created_at))) as avg_order_hour,
                    
                    -- Geographic info (if available)
                    c.city,
                    c.district
                    
                FROM customers c
                LEFT JOIN orders o ON c.customer_id = o.customer_id AND o.status = 'delivered'
                LEFT JOIN order_details od ON o.order_id = od.order_id
                LEFT JOIN product_variants pv ON od.variant_id = pv.variant_id
                LEFT JOIN products p ON pv.product_id = p.product_id
                LEFT JOIN (
                    SELECT 
                        o2.order_id,
                        SUM(od2.quantity * od2.unit_price) as order_total
                    FROM orders o2
                    JOIN order_details od2 ON o2.order_id = od2.order_id
                    WHERE o2.status = 'delivered'
                    GROUP BY o2.order_id
                ) order_totals ON o.order_id = order_totals.order_id
                
                WHERE o.warehouse_id = :warehouse_id
                    AND o.created_at >= DATE_SUB(NOW(), INTERVAL 24 MONTH)
                GROUP BY c.customer_id
                HAVING total_orders > 0
            ) customer_metrics
            ORDER BY total_spent DESC, total_orders DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
    $stmt->execute();
    
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Assign customer segments based on RFM scores
    foreach ($results as &$customer) {
        $rfm_score = $customer['recency_score'] + $customer['frequency_score'] + $customer['monetary_score'];
        
        if ($customer['recency_score'] >= 4 && $customer['frequency_score'] >= 4 && $customer['monetary_score'] >= 4) {
            $customer['segment'] = 'champions';
            $customer['segment_label'] = 'Champions';
            $customer['segment_description'] = 'Khách hàng tốt nhất - mua thường xuyên, gần đây, và chi tiêu cao';
        } elseif ($customer['recency_score'] >= 3 && $customer['frequency_score'] >= 3 && $customer['monetary_score'] >= 3) {
            $customer['segment'] = 'loyal_customers';
            $customer['segment_label'] = 'Loyal Customers';
            $customer['segment_description'] = 'Khách hàng trung thành';
        } elseif ($customer['recency_score'] >= 4 && $customer['frequency_score'] <= 2) {
            $customer['segment'] = 'new_customers';
            $customer['segment_label'] = 'New Customers';
            $customer['segment_description'] = 'Khách hàng mới';
        } elseif ($customer['recency_score'] <= 2 && $customer['frequency_score'] >= 3) {
            $customer['segment'] = 'at_risk';
            $customer['segment_label'] = 'At Risk';
            $customer['segment_description'] = 'Khách hàng có nguy cơ rời bỏ';
        } elseif ($customer['recency_score'] <= 2 && $customer['frequency_score'] <= 2 && $customer['monetary_score'] >= 3) {
            $customer['segment'] = 'cant_lose_them';
            $customer['segment_label'] = "Can't Lose Them";
            $customer['segment_description'] = 'Khách hàng có giá trị cao nhưng không hoạt động';
        } else {
            $customer['segment'] = 'hibernating';
            $customer['segment_label'] = 'Hibernating';
            $customer['segment_description'] = 'Khách hàng ngủ đông';
        }
    }
    
    return $results;
}

function getPurchasePatternAnalysis($pdo, $warehouseId) {
    $sql = "SELECT 
                -- Time-based patterns
                DAYNAME(o.created_at) as day_of_week,
                HOUR(o.created_at) as hour_of_day,
                DAYOFWEEK(o.created_at) as day_number,
                
                -- Category preferences
                c.name as category_name,
                
                -- Purchase metrics
                COUNT(DISTINCT o.order_id) as order_count,
                COUNT(DISTINCT o.customer_id) as unique_customers,
                SUM(od.quantity) as total_quantity,
                SUM(od.quantity * od.unit_price) as total_revenue,
                AVG(od.quantity * od.unit_price) as avg_order_value,
                
                -- Seasonal patterns
                QUARTER(o.created_at) as quarter,
                MONTH(o.created_at) as month,
                
                -- Product diversity
                COUNT(DISTINCT p.product_id) as unique_products_sold,
                
                -- Customer behavior
                AVG(order_details_count.items_per_order) as avg_items_per_order,
                
                -- Geographic patterns
                cu.city,
                COUNT(DISTINCT cu.customer_id) as customers_in_city
                
            FROM orders o
            JOIN customers cu ON o.customer_id = cu.customer_id
            JOIN order_details od ON o.order_id = od.order_id
            JOIN product_variants pv ON od.variant_id = pv.variant_id
            JOIN products p ON pv.product_id = p.product_id
            LEFT JOIN categories c ON p.category_id = c.category_id
            LEFT JOIN (
                SELECT 
                    od2.order_id,
                    COUNT(*) as items_per_order
                FROM order_details od2
                GROUP BY od2.order_id
            ) order_details_count ON o.order_id = order_details_count.order_id
            
            WHERE o.status = 'delivered'
                AND o.warehouse_id = :warehouse_id
                AND o.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
            GROUP BY 
                DAYNAME(o.created_at), 
                HOUR(o.created_at), 
                c.category_id,
                QUARTER(o.created_at),
                MONTH(o.created_at),
                cu.city
            ORDER BY total_revenue DESC, order_count DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
    $stmt->execute();
    
    $rawResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Process and structure the data
    $patterns = [
        'time_patterns' => [],
        'day_patterns' => [],
        'category_patterns' => [],
        'seasonal_patterns' => [],
        'geographic_patterns' => []
    ];
    
    // Group by different dimensions
    foreach ($rawResults as $row) {
        // Time patterns (hourly)
        if (!isset($patterns['time_patterns'][$row['hour_of_day']])) {
            $patterns['time_patterns'][$row['hour_of_day']] = [
                'hour' => $row['hour_of_day'],
                'total_orders' => 0,
                'total_revenue' => 0
            ];
        }
        $patterns['time_patterns'][$row['hour_of_day']]['total_orders'] += $row['order_count'];
        $patterns['time_patterns'][$row['hour_of_day']]['total_revenue'] += $row['total_revenue'];
        
        // Day patterns
        if (!isset($patterns['day_patterns'][$row['day_of_week']])) {
            $patterns['day_patterns'][$row['day_of_week']] = [
                'day' => $row['day_of_week'],
                'day_number' => $row['day_number'],
                'total_orders' => 0,
                'total_revenue' => 0
            ];
        }
        $patterns['day_patterns'][$row['day_of_week']]['total_orders'] += $row['order_count'];
        $patterns['day_patterns'][$row['day_of_week']]['total_revenue'] += $row['total_revenue'];
        
        // Category patterns
        if ($row['category_name'] && !isset($patterns['category_patterns'][$row['category_name']])) {
            $patterns['category_patterns'][$row['category_name']] = [
                'category' => $row['category_name'],
                'total_orders' => 0,
                'total_revenue' => 0,
                'unique_customers' => 0
            ];
        }
        if ($row['category_name']) {
            $patterns['category_patterns'][$row['category_name']]['total_orders'] += $row['order_count'];
            $patterns['category_patterns'][$row['category_name']]['total_revenue'] += $row['total_revenue'];
            $patterns['category_patterns'][$row['category_name']]['unique_customers'] += $row['unique_customers'];
        }
    }
    
    return $patterns;
}

function getProductAffinityAnalysis($pdo, $warehouseId) {
    $sql = "SELECT 
                p1.name as product_a,
                p1.product_id as product_a_id,
                p2.name as product_b,
                p2.product_id as product_b_id,
                c1.name as category_a,
                c2.name as category_b,
                
                -- Co-occurrence metrics
                COUNT(DISTINCT o.order_id) as orders_with_both,
                COUNT(DISTINCT o.customer_id) as customers_bought_both,
                
                -- Support metrics
                (COUNT(DISTINCT o.order_id) * 1.0 / (
                    SELECT COUNT(DISTINCT o2.order_id) 
                    FROM orders o2 
                    WHERE o2.warehouse_id = :warehouse_id 
                      AND o2.status = 'delivered'
                      AND o2.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                )) as support,
                
                -- Confidence (A->B)
                (COUNT(DISTINCT o.order_id) * 1.0 / NULLIF(product_a_orders.total_orders, 0)) as confidence_a_to_b,
                
                -- Lift
                ((COUNT(DISTINCT o.order_id) * 1.0 / NULLIF(product_a_orders.total_orders, 0)) / 
                 NULLIF(product_b_orders.order_frequency, 0)) as lift,
                 
                -- Revenue impact
                SUM(od1.quantity * od1.unit_price + od2.quantity * od2.unit_price) as combined_revenue
                
            FROM orders o
            JOIN order_details od1 ON o.order_id = od1.order_id
            JOIN order_details od2 ON o.order_id = od2.order_id AND od1.variant_id != od2.variant_id
            JOIN product_variants pv1 ON od1.variant_id = pv1.variant_id
            JOIN product_variants pv2 ON od2.variant_id = pv2.variant_id
            JOIN products p1 ON pv1.product_id = p1.product_id
            JOIN products p2 ON pv2.product_id = p2.product_id
            LEFT JOIN categories c1 ON p1.category_id = c1.category_id
            LEFT JOIN categories c2 ON p2.category_id = c2.category_id
            
            -- Get individual product order counts
            LEFT JOIN (
                SELECT 
                    p3.product_id,
                    COUNT(DISTINCT o3.order_id) as total_orders
                FROM orders o3
                JOIN order_details od3 ON o3.order_id = od3.order_id
                JOIN product_variants pv3 ON od3.variant_id = pv3.variant_id
                JOIN products p3 ON pv3.product_id = p3.product_id
                WHERE o3.warehouse_id = :warehouse_id
                    AND o3.status = 'delivered'
                    AND o3.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                GROUP BY p3.product_id
            ) product_a_orders ON p1.product_id = product_a_orders.product_id
            
            LEFT JOIN (
                SELECT 
                    p4.product_id,
                    COUNT(DISTINCT o4.order_id) * 1.0 / (
                        SELECT COUNT(DISTINCT o5.order_id) 
                        FROM orders o5 
                        WHERE o5.warehouse_id = :warehouse_id 
                          AND o5.status = 'delivered'
                          AND o5.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                    ) as order_frequency
                FROM orders o4
                JOIN order_details od4 ON o4.order_id = od4.order_id
                JOIN product_variants pv4 ON od4.variant_id = pv4.variant_id
                JOIN products p4 ON pv4.product_id = p4.product_id
                WHERE o4.warehouse_id = :warehouse_id
                    AND o4.status = 'delivered'
                    AND o4.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                GROUP BY p4.product_id
            ) product_b_orders ON p2.product_id = product_b_orders.product_id
            
            WHERE o.status = 'delivered'
                AND o.warehouse_id = :warehouse_id
                AND o.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                AND p1.product_id < p2.product_id  -- Avoid duplicates
            GROUP BY p1.product_id, p2.product_id
            HAVING orders_with_both >= 3  -- Minimum threshold
            ORDER BY lift DESC, orders_with_both DESC
            LIMIT 50";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
    $stmt->execute();
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getCustomerLifecycleAnalysis($pdo, $warehouseId) {
    $sql = "SELECT 
                customer_lifecycle.*,
                
                -- Lifecycle stage classification
                CASE 
                    WHEN customer_lifecycle.days_as_customer <= 30 THEN 'new'
                    WHEN customer_lifecycle.days_as_customer <= 90 AND customer_lifecycle.total_orders <= 3 THEN 'developing'
                    WHEN customer_lifecycle.total_orders >= 5 AND customer_lifecycle.days_since_last_order <= 60 THEN 'mature'
                    WHEN customer_lifecycle.days_since_last_order > 180 THEN 'dormant'
                    ELSE 'declining'
                END as lifecycle_stage,
                
                -- Churn risk assessment
                CASE 
                    WHEN customer_lifecycle.days_since_last_order > 365 THEN 'churned'
                    WHEN customer_lifecycle.days_since_last_order > 180 THEN 'high_risk'
                    WHEN customer_lifecycle.days_since_last_order > 90 THEN 'medium_risk'
                    ELSE 'low_risk'
                END as churn_risk
                
            FROM (
                SELECT 
                    c.customer_id,
                    c.name as customer_name,
                    
                    -- Temporal metrics
                    MIN(o.created_at) as first_order_date,
                    MAX(o.created_at) as last_order_date,
                    DATEDIFF(NOW(), MIN(o.created_at)) as days_as_customer,
                    DATEDIFF(NOW(), MAX(o.created_at)) as days_since_last_order,
                    DATEDIFF(MAX(o.created_at), MIN(o.created_at)) as active_period_days,
                    
                    -- Order metrics
                    COUNT(DISTINCT o.order_id) as total_orders,
                    ROUND(COUNT(DISTINCT o.order_id) / NULLIF(DATEDIFF(MAX(o.created_at), MIN(o.created_at)), 0) * 30, 2) as avg_orders_per_month,
                    
                    -- Financial metrics
                    SUM(od.quantity * od.unit_price) as total_spent,
                    AVG(order_values.order_value) as avg_order_value,
                    MAX(order_values.order_value) as max_order_value,
                    
                    -- Behavioral metrics
                    COUNT(DISTINCT p.category_id) as categories_purchased,
                    COUNT(DISTINCT p.product_id) as unique_products,
                    AVG(order_items.items_per_order) as avg_items_per_order,
                    
                    -- Engagement patterns
                    COUNT(DISTINCT DATE(o.created_at)) as active_days,
                    COUNT(DISTINCT YEAR(o.created_at), MONTH(o.created_at)) as active_months,
                    
                    -- Trend analysis (last 3 months vs previous 3 months)
                    COALESCE(recent_activity.recent_orders, 0) as recent_orders,
                    COALESCE(recent_activity.recent_spent, 0) as recent_spent,
                    COALESCE(previous_activity.previous_orders, 0) as previous_orders,
                    COALESCE(previous_activity.previous_spent, 0) as previous_spent
                    
                FROM customers c
                JOIN orders o ON c.customer_id = o.customer_id
                JOIN order_details od ON o.order_id = od.order_id
                JOIN product_variants pv ON od.variant_id = pv.variant_id
                JOIN products p ON pv.product_id = p.product_id
                
                LEFT JOIN (
                    SELECT 
                        o2.order_id,
                        SUM(od2.quantity * od2.unit_price) as order_value
                    FROM orders o2
                    JOIN order_details od2 ON o2.order_id = od2.order_id
                    WHERE o2.status = 'delivered'
                    GROUP BY o2.order_id
                ) order_values ON o.order_id = order_values.order_id
                
                LEFT JOIN (
                    SELECT 
                        o3.order_id,
                        COUNT(*) as items_per_order
                    FROM orders o3
                    JOIN order_details od3 ON o3.order_id = od3.order_id
                    WHERE o3.status = 'delivered'
                    GROUP BY o3.order_id
                ) order_items ON o.order_id = order_items.order_id
                
                -- Recent activity (last 3 months)
                LEFT JOIN (
                    SELECT 
                        c2.customer_id,
                        COUNT(DISTINCT o4.order_id) as recent_orders,
                        SUM(od4.quantity * od4.unit_price) as recent_spent
                    FROM customers c2
                    JOIN orders o4 ON c2.customer_id = o4.customer_id
                    JOIN order_details od4 ON o4.order_id = od4.order_id
                    WHERE o4.status = 'delivered'
                        AND o4.created_at >= DATE_SUB(NOW(), INTERVAL 3 MONTH)
                    GROUP BY c2.customer_id
                ) recent_activity ON c.customer_id = recent_activity.customer_id
                
                -- Previous activity (3-6 months ago)
                LEFT JOIN (
                    SELECT 
                        c3.customer_id,
                        COUNT(DISTINCT o5.order_id) as previous_orders,
                        SUM(od5.quantity * od5.unit_price) as previous_spent
                    FROM customers c3
                    JOIN orders o5 ON c3.customer_id = o5.customer_id
                    JOIN order_details od5 ON o5.order_id = od5.order_id
                    WHERE o5.status = 'delivered'
                        AND o5.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                        AND o5.created_at < DATE_SUB(NOW(), INTERVAL 3 MONTH)
                    GROUP BY c3.customer_id
                ) previous_activity ON c.customer_id = previous_activity.customer_id
                
                WHERE o.status = 'delivered'
                    AND o.warehouse_id = :warehouse_id
                GROUP BY c.customer_id
            ) customer_lifecycle
            ORDER BY total_spent DESC, total_orders DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
    $stmt->execute();
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getSeasonalBehaviorPatterns($pdo, $warehouseId) {
    $sql = "SELECT 
                QUARTER(o.created_at) as quarter,
                MONTH(o.created_at) as month,
                MONTHNAME(o.created_at) as month_name,
                YEAR(o.created_at) as year,
                
                -- Sales metrics
                COUNT(DISTINCT o.order_id) as total_orders,
                COUNT(DISTINCT o.customer_id) as unique_customers,
                SUM(od.quantity) as total_quantity,
                SUM(od.quantity * od.unit_price) as total_revenue,
                AVG(order_totals.order_total) as avg_order_value,
                
                -- Product metrics
                COUNT(DISTINCT p.product_id) as unique_products_sold,
                
                -- Category breakdown
                c.name as category_name,
                SUM(CASE WHEN c.category_id IS NOT NULL THEN od.quantity * od.unit_price ELSE 0 END) as category_revenue,
                
                -- Customer behavior
                AVG(customer_orders.orders_per_customer) as avg_orders_per_customer,
                
                -- Year-over-year comparison
                LAG(SUM(od.quantity * od.unit_price), 12) OVER (
                    PARTITION BY MONTH(o.created_at) 
                    ORDER BY YEAR(o.created_at), MONTH(o.created_at)
                ) as revenue_same_month_last_year
                
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
                    o3.customer_id,
                    YEAR(o3.created_at) as year,
                    MONTH(o3.created_at) as month,
                    COUNT(DISTINCT o3.order_id) as orders_per_customer
                FROM orders o3
                WHERE o3.status = 'delivered'
                GROUP BY o3.customer_id, YEAR(o3.created_at), MONTH(o3.created_at)
            ) customer_orders ON o.customer_id = customer_orders.customer_id 
                                AND YEAR(o.created_at) = customer_orders.year 
                                AND MONTH(o.created_at) = customer_orders.month
            
            WHERE o.status = 'delivered'
                AND o.warehouse_id = :warehouse_id
                AND o.created_at >= DATE_SUB(NOW(), INTERVAL 24 MONTH)
            GROUP BY 
                YEAR(o.created_at), 
                MONTH(o.created_at), 
                c.category_id
            ORDER BY year DESC, month DESC, category_revenue DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
    $stmt->execute();
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function generateBehaviorRecommendations($customerSegmentation, $purchasePatterns, $customerLifecycle) {
    $recommendations = [
        'urgent' => [],
        'important' => [],
        'suggested' => []
    ];
    
    // 1. Urgent: High-value customers at risk
    $atRiskHighValue = array_filter($customerSegmentation, function($customer) {
        return $customer['segment'] === 'cant_lose_them' || 
               ($customer['segment'] === 'at_risk' && $customer['total_spent'] > 2000000);
    });
    
    foreach ($atRiskHighValue as $customer) {
        $recommendations['urgent'][] = [
            'type' => 'high_value_at_risk',
            'customer_name' => $customer['customer_name'],
            'customer_id' => $customer['customer_id'],
            'issue' => "Khách hàng VIP không mua hàng {$customer['days_since_last_order']} ngày",
            'recommendation' => 'Liên hệ cá nhân, tặng voucher đặc biệt',
            'priority_score' => 95,
            'potential_loss' => $customer['total_spent']
        ];
    }
    
    // 2. Important: New customers need nurturing
    $newCustomers = array_filter($customerSegmentation, function($customer) {
        return $customer['segment'] === 'new_customers' && $customer['total_orders'] <= 2;
    });
    
    if (count($newCustomers) > 0) {
        $recommendations['important'][] = [
            'type' => 'new_customer_nurturing',
            'affected_customers' => count($newCustomers),
            'issue' => count($newCustomers) . ' khách hàng mới cần được chăm sóc',
            'recommendation' => 'Gửi email welcome series, offer first-time buyer discount',
            'priority_score' => 80,
            'opportunity' => 'Tăng retention rate cho new customers'
        ];
    }
    
    // 3. Suggested: Customer reactivation campaign
    $hibernatingCustomers = array_filter($customerSegmentation, function($customer) {
        return $customer['segment'] === 'hibernating' && $customer['total_spent'] > 1000000;
    });
    
    if (count($hibernatingCustomers) > 0) {
        $recommendations['suggested'][] = [
            'type' => 'reactivation_campaign',
            'target_customers' => count($hibernatingCustomers),
            'issue' => count($hibernatingCustomers) . ' khách hàng có giá trị đang "ngủ đông"',
            'recommendation' => 'Chạy win-back campaign với discount 15-20%',
            'priority_score' => 70,
            'potential_revenue' => array_sum(array_column($hibernatingCustomers, 'avg_order_value')) * 0.1
        ];
    }
    
    // 4. Product recommendations based on affinity
    $recommendations['suggested'][] = [
        'type' => 'cross_selling_opportunity',
        'issue' => 'Có cơ hội tăng AOV qua cross-selling',
        'recommendation' => 'Implement "customers who bought X also bought Y" recommendations',
        'priority_score' => 65,
        'impact' => 'Increase average order value by 10-15%'
    ];
    
    // 5. Lifecycle-based recommendations
    $matureCustomers = array_filter($customerLifecycle, function($customer) {
        return $customer['lifecycle_stage'] === 'mature' && $customer['recent_orders'] < $customer['previous_orders'];
    });
    
    if (count($matureCustomers) > 0) {
        $recommendations['suggested'][] = [
            'type' => 'mature_customer_engagement',
            'affected_customers' => count($matureCustomers),
            'issue' => count($matureCustomers) . ' khách hàng mature có dấu hiệu giảm hoạt động',
            'recommendation' => 'Loyalty program, exclusive offers, early access to new products',
            'priority_score' => 60,
            'goal' => 'Maintain engagement with mature customers'
        ];
    }
    
    return $recommendations;
}

// Chart formatting functions
function formatSegmentationChartData($customerSegmentation) {
    $segments = [];
    foreach ($customerSegmentation as $customer) {
        if (!isset($segments[$customer['segment']])) {
            $segments[$customer['segment']] = [
                'count' => 0,
                'revenue' => 0,
                'label' => $customer['segment_label']
            ];
        }
        $segments[$customer['segment']]['count']++;
        $segments[$customer['segment']]['revenue'] += $customer['total_spent'];
    }
    
    $labels = [];
    $counts = [];
    $colors = [
        'champions' => '#28a745',
        'loyal_customers' => '#17a2b8',
        'new_customers' => '#ffc107',
        'at_risk' => '#fd7e14',
        'cant_lose_them' => '#e74a3b',
        'hibernating' => '#6c757d'
    ];
    $backgroundColors = [];
    
    foreach ($segments as $segment => $data) {
        $labels[] = $data['label'];
        $counts[] = $data['count'];
        $backgroundColors[] = $colors[$segment] ?? '#6c757d';
    }
    
    return [
        'labels' => $labels,
        'datasets' => [
            [
                'label' => 'Number of Customers',
                'data' => $counts,
                'backgroundColor' => $backgroundColors
            ]
        ]
    ];
}

function formatPurchasePatternChartData($purchasePatterns) {
    $hourlyData = $purchasePatterns['time_patterns'];
    ksort($hourlyData);
    
    $labels = [];
    $orders = [];
    
    for ($hour = 0; $hour < 24; $hour++) {
        $labels[] = $hour . ':00';
        $orders[] = isset($hourlyData[$hour]) ? $hourlyData[$hour]['total_orders'] : 0;
    }
    
    return [
        'labels' => $labels,
        'datasets' => [
            [
                'label' => 'Orders by Hour',
                'data' => $orders,
                'borderColor' => '#4e73df',
                'backgroundColor' => 'rgba(78, 115, 223, 0.1)',
                'fill' => true
            ]
        ]
    ];
}

function formatLifecycleChartData($customerLifecycle) {
    $stages = [];
    foreach ($customerLifecycle as $customer) {
        if (!isset($stages[$customer['lifecycle_stage']])) {
            $stages[$customer['lifecycle_stage']] = 0;
        }
        $stages[$customer['lifecycle_stage']]++;
    }
    
    $stageLabels = [
        'new' => 'New',
        'developing' => 'Developing', 
        'mature' => 'Mature',
        'declining' => 'Declining',
        'dormant' => 'Dormant'
    ];
    
    $labels = [];
    $data = [];
    $colors = ['#28a745', '#17a2b8', '#ffc107', '#fd7e14', '#dc3545'];
    
    $colorIndex = 0;
    foreach ($stages as $stage => $count) {
        $labels[] = $stageLabels[$stage] ?? $stage;
        $data[] = $count;
    }
    
    return [
        'labels' => $labels,
        'datasets' => [
            [
                'label' => 'Customers',
                'data' => $data,
                'backgroundColor' => array_slice($colors, 0, count($data))
            ]
        ]
    ];
}

function formatSeasonalChartData($seasonalBehavior) {
    $monthlyData = [];
    
    foreach ($seasonalBehavior as $row) {
        $key = $row['year'] . '-' . str_pad($row['month'], 2, '0', STR_PAD_LEFT);
        if (!isset($monthlyData[$key])) {
            $monthlyData[$key] = [
                'month_name' => $row['month_name'],
                'total_revenue' => 0,
                'total_orders' => 0
            ];
        }
        $monthlyData[$key]['total_revenue'] += $row['total_revenue'];
        $monthlyData[$key]['total_orders'] += $row['total_orders'];
    }
    
    ksort($monthlyData);
    $recentMonths = array_slice($monthlyData, -12, 12, true);
    
    $labels = [];
    $revenues = [];
    $orders = [];
    
    foreach ($recentMonths as $month => $data) {
        $labels[] = $data['month_name'];
        $revenues[] = $data['total_revenue'];
        $orders[] = $data['total_orders'];
    }
    
    return [
        'labels' => $labels,
        'datasets' => [
            [
                'label' => 'Revenue',
                'data' => $revenues,
                'borderColor' => '#1cc88a',
                'backgroundColor' => 'rgba(28, 200, 138, 0.1)',
                'yAxisID' => 'y'
            ],
            [
                'label' => 'Orders',
                'data' => $orders,
                'borderColor' => '#36b9cc',
                'backgroundColor' => 'rgba(54, 185, 204, 0.1)',
                'yAxisID' => 'y1'
            ]
        ]
    ];
}

// Summary calculation functions
function calculateTotalCustomers($customerSegmentation) {
    return count($customerSegmentation);
}

function calculateAveragePurchaseFrequency($purchasePatterns) {
    $totalOrders = 0;
    $totalCustomers = 0;
    
    foreach ($purchasePatterns['category_patterns'] as $pattern) {
        $totalOrders += $pattern['total_orders'];
        $totalCustomers += $pattern['unique_customers'];
    }
    
    return $totalCustomers > 0 ? round($totalOrders / $totalCustomers, 2) : 0;
}

function calculateRetentionRate($customerLifecycle) {
    $activeCustomers = array_filter($customerLifecycle, function($customer) {
        return $customer['churn_risk'] !== 'churned';
    });
    
    $totalCustomers = count($customerLifecycle);
    return $totalCustomers > 0 ? round((count($activeCustomers) / $totalCustomers) * 100, 2) : 0;
}

function getMostPopularCategories($purchasePatterns) {
    $categories = $purchasePatterns['category_patterns'];
    uasort($categories, function($a, $b) {
        return $b['total_revenue'] - $a['total_revenue'];
    });
    
    return array_slice($categories, 0, 5);
}
?>