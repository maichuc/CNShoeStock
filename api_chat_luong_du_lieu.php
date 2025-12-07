<?php
/**
 * API: Data Quality Analysis  
 * Module 6 của AI Analytics Dashboard
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
    // 1. Product Data Quality Assessment
    $productDataQuality = getProductDataQualityAnalysis($pdo, $warehouseId);
    
    // 2. Customer Data Quality Assessment
    $customerDataQuality = getCustomerDataQualityAnalysis($pdo, $warehouseId);
    
    // 3. Order Data Integrity Check
    $orderDataIntegrity = getOrderDataIntegrityAnalysis($pdo, $warehouseId);
    
    // 4. Inventory Data Accuracy
    $inventoryDataAccuracy = getInventoryDataAccuracyAnalysis($pdo, $warehouseId);
    
    // 5. SKU and Pricing Consistency
    $skuPricingConsistency = getSKUPricingConsistencyAnalysis($pdo, $warehouseId);
    
    // 6. AI Recommendations cho data quality improvement
    $aiRecommendations = generateDataQualityRecommendations($productDataQuality, $customerDataQuality, $orderDataIntegrity);
    
    $response = [
        'success' => true,
        'data' => [
            'product_data_quality' => $productDataQuality,
            'customer_data_quality' => $customerDataQuality,
            'order_data_integrity' => $orderDataIntegrity,
            'inventory_data_accuracy' => $inventoryDataAccuracy,
            'sku_pricing_consistency' => $skuPricingConsistency,
            'ai_recommendations' => $aiRecommendations,
            'charts_data' => [
                'quality_score_chart' => formatQualityScoreChartData($productDataQuality, $customerDataQuality),
                'completeness_chart' => formatCompletenessChartData($productDataQuality),
                'consistency_chart' => formatConsistencyChartData($skuPricingConsistency)
            ],
            'summary' => [
                'overall_quality_score' => calculateOverallQualityScore($productDataQuality, $customerDataQuality, $orderDataIntegrity),
                'critical_issues' => countCriticalIssues($productDataQuality, $customerDataQuality, $orderDataIntegrity),
                'data_completeness' => calculateDataCompleteness($productDataQuality),
                'accuracy_percentage' => calculateAccuracyPercentage($inventoryDataAccuracy)
            ]
        ],
        'timestamp' => time()
    ];
    
    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Data quality analysis failed: ' . $e->getMessage()
    ]);
}

function getProductDataQualityAnalysis($pdo, $warehouseId) {
    $sql = "SELECT 
                'product_completeness' as metric_type,
                COUNT(*) as total_products,
                
                -- Name completeness
                COUNT(CASE WHEN p.name IS NULL OR p.name = '' THEN 1 END) as missing_names,
                ROUND((1 - COUNT(CASE WHEN p.name IS NULL OR p.name = '' THEN 1 END) / COUNT(*)) * 100, 2) as name_completeness_score,
                
                -- Description completeness
                COUNT(CASE WHEN p.description IS NULL OR p.description = '' THEN 1 END) as missing_descriptions,
                ROUND((1 - COUNT(CASE WHEN p.description IS NULL OR p.description = '' THEN 1 END) / COUNT(*)) * 100, 2) as description_completeness_score,
                
                -- Category assignment
                COUNT(CASE WHEN p.category_id IS NULL THEN 1 END) as missing_categories,
                ROUND((1 - COUNT(CASE WHEN p.category_id IS NULL THEN 1 END) / COUNT(*)) * 100, 2) as category_completeness_score,
                
                -- Variant data quality
                COUNT(DISTINCT pv.variant_id) as total_variants,
                COUNT(CASE WHEN pv.sku IS NULL OR pv.sku = '' THEN 1 END) as missing_skus,
                COUNT(CASE WHEN pv.cost_price IS NULL OR pv.cost_price <= 0 THEN 1 END) as invalid_cost_prices,
                COUNT(CASE WHEN pv.selling_price IS NULL OR pv.selling_price <= 0 THEN 1 END) as invalid_selling_prices,
                
                -- Price consistency checks
                COUNT(CASE WHEN pv.selling_price < pv.cost_price THEN 1 END) as negative_margin_variants,
                COUNT(CASE WHEN pv.selling_price > pv.cost_price * 10 THEN 1 END) as excessive_markup_variants,
                
                -- SKU format consistency
                COUNT(CASE WHEN pv.sku NOT REGEXP '^[A-Z]+-[A-Z]+-[0-9]+-[0-9]+$' THEN 1 END) as non_standard_skus,
                
                -- Duplicate detection
                COUNT(*) - COUNT(DISTINCT p.name) as duplicate_product_names,
                COUNT(DISTINCT pv.variant_id) - COUNT(DISTINCT pv.sku) as duplicate_skus
                
            FROM products p
            LEFT JOIN product_variants pv ON p.product_id = pv.product_id
            WHERE p.warehouse_id = :warehouse_id
            
            UNION ALL
            
            SELECT 
                'image_quality' as metric_type,
                COUNT(DISTINCT p.product_id) as total_products,
                COUNT(CASE WHEN p.image IS NULL OR p.image = '' THEN 1 END) as missing_images,
                ROUND((1 - COUNT(CASE WHEN p.image IS NULL OR p.image = '' THEN 1 END) / COUNT(DISTINCT p.product_id)) * 100, 2) as image_completeness_score,
                0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0
            FROM products p
            WHERE p.warehouse_id = :warehouse_id";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
    $stmt->execute();
    
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate overall product data quality score
    $productMetrics = $results[0];
    $imageMetrics = $results[1] ?? ['image_completeness_score' => 0];
    
    $qualityScores = [
        'name_completeness' => $productMetrics['name_completeness_score'],
        'description_completeness' => $productMetrics['description_completeness_score'],
        'category_completeness' => $productMetrics['category_completeness_score'],
        'image_completeness' => $imageMetrics['image_completeness_score'],
        'sku_validity' => $productMetrics['total_variants'] > 0 ? 
            (1 - $productMetrics['missing_skus'] / $productMetrics['total_variants']) * 100 : 100,
        'price_validity' => $productMetrics['total_variants'] > 0 ? 
            (1 - ($productMetrics['invalid_cost_prices'] + $productMetrics['invalid_selling_prices']) / ($productMetrics['total_variants'] * 2)) * 100 : 100
    ];
    
    $overallScore = array_sum($qualityScores) / count($qualityScores);
    
    return [
        'metrics' => $productMetrics,
        'quality_scores' => $qualityScores,
        'overall_score' => round($overallScore, 2),
        'issues' => [
            'missing_names' => $productMetrics['missing_names'],
            'missing_descriptions' => $productMetrics['missing_descriptions'],
            'missing_categories' => $productMetrics['missing_categories'],
            'invalid_prices' => $productMetrics['invalid_cost_prices'] + $productMetrics['invalid_selling_prices'],
            'negative_margins' => $productMetrics['negative_margin_variants'],
            'non_standard_skus' => $productMetrics['non_standard_skus'],
            'duplicates' => $productMetrics['duplicate_product_names'] + $productMetrics['duplicate_skus']
        ]
    ];
}

function getCustomerDataQualityAnalysis($pdo, $warehouseId) {
    $sql = "SELECT 
                COUNT(*) as total_customers,
                
                -- Basic field completeness
                COUNT(CASE WHEN name IS NULL OR name = '' THEN 1 END) as missing_names,
                COUNT(CASE WHEN email IS NULL OR email = '' THEN 1 END) as missing_emails,
                COUNT(CASE WHEN phone IS NULL OR phone = '' THEN 1 END) as missing_phones,
                COUNT(CASE WHEN address IS NULL OR address = '' THEN 1 END) as missing_addresses,
                
                -- Email format validation
                COUNT(CASE WHEN email NOT REGEXP '^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\\.[A-Za-z]{2,}$' THEN 1 END) as invalid_email_formats,
                
                -- Phone format validation (Vietnamese format)
                COUNT(CASE WHEN phone NOT REGEXP '^(0|\\+84)[0-9]{9,10}$' THEN 1 END) as invalid_phone_formats,
                
                -- Duplicate detection
                COUNT(*) - COUNT(DISTINCT email) as duplicate_emails,
                COUNT(*) - COUNT(DISTINCT phone) as duplicate_phones,
                
                -- Data consistency
                COUNT(CASE WHEN created_at > NOW() THEN 1 END) as future_creation_dates,
                
                -- Activity validation (customers with orders)
                COUNT(DISTINCT c.customer_id) as customers_with_data,
                COUNT(DISTINCT o.customer_id) as customers_with_orders,
                
                -- Geographic data quality
                COUNT(CASE WHEN city IS NULL OR city = '' THEN 1 END) as missing_cities,
                COUNT(CASE WHEN district IS NULL OR district = '' THEN 1 END) as missing_districts
                
            FROM customers c
            LEFT JOIN orders o ON c.customer_id = o.customer_id AND o.warehouse_id = :warehouse_id";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
    $stmt->execute();
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Calculate quality scores
    $totalCustomers = $result['total_customers'];
    $qualityScores = [
        'name_completeness' => $totalCustomers > 0 ? (1 - $result['missing_names'] / $totalCustomers) * 100 : 100,
        'email_completeness' => $totalCustomers > 0 ? (1 - $result['missing_emails'] / $totalCustomers) * 100 : 100,
        'phone_completeness' => $totalCustomers > 0 ? (1 - $result['missing_phones'] / $totalCustomers) * 100 : 100,
        'address_completeness' => $totalCustomers > 0 ? (1 - $result['missing_addresses'] / $totalCustomers) * 100 : 100,
        'email_validity' => $totalCustomers > 0 ? (1 - $result['invalid_email_formats'] / $totalCustomers) * 100 : 100,
        'phone_validity' => $totalCustomers > 0 ? (1 - $result['invalid_phone_formats'] / $totalCustomers) * 100 : 100
    ];
    
    $overallScore = array_sum($qualityScores) / count($qualityScores);
    
    return [
        'metrics' => $result,
        'quality_scores' => $qualityScores,
        'overall_score' => round($overallScore, 2),
        'issues' => [
            'missing_data' => $result['missing_names'] + $result['missing_emails'] + $result['missing_phones'],
            'invalid_formats' => $result['invalid_email_formats'] + $result['invalid_phone_formats'],
            'duplicates' => $result['duplicate_emails'] + $result['duplicate_phones'],
            'inactive_customers' => $result['customers_with_data'] - $result['customers_with_orders']
        ]
    ];
}

function getOrderDataIntegrityAnalysis($pdo, $warehouseId) {
    $sql = "SELECT 
                COUNT(*) as total_orders,
                
                -- Status consistency
                COUNT(CASE WHEN status NOT IN ('pending', 'processing', 'shipped', 'delivered', 'cancelled') THEN 1 END) as invalid_statuses,
                
                -- Date consistency
                COUNT(CASE WHEN created_at > NOW() THEN 1 END) as future_order_dates,
                COUNT(CASE WHEN updated_at < created_at THEN 1 END) as invalid_update_dates,
                
                -- Customer reference integrity
                COUNT(CASE WHEN customer_id NOT IN (SELECT customer_id FROM customers) THEN 1 END) as orphaned_customer_refs,
                
                -- Order details integrity
                COUNT(DISTINCT o.order_id) as orders_with_data,
                COUNT(DISTINCT od.order_id) as orders_with_details,
                
                -- Amount consistency
                COUNT(CASE WHEN total_amount <= 0 THEN 1 END) as invalid_amounts,
                
                -- Order details validation
                SUM(CASE WHEN od.quantity <= 0 THEN 1 ELSE 0 END) as invalid_quantities,
                SUM(CASE WHEN od.unit_price <= 0 THEN 1 ELSE 0 END) as invalid_unit_prices,
                
                -- Calculate total from details vs order total
                COUNT(CASE 
                    WHEN ABS(o.total_amount - order_calculated.calculated_total) > 1000 
                    THEN 1 
                END) as amount_mismatches,
                
                -- Shipping data consistency
                COUNT(CASE WHEN status = 'shipped' AND shipping_address IS NULL THEN 1 END) as shipped_without_address,
                COUNT(CASE WHEN status = 'delivered' AND delivery_date IS NULL THEN 1 END) as delivered_without_date
                
            FROM orders o
            LEFT JOIN order_details od ON o.order_id = od.order_id
            LEFT JOIN (
                SELECT 
                    od2.order_id,
                    SUM(od2.quantity * od2.unit_price) as calculated_total
                FROM order_details od2
                GROUP BY od2.order_id
            ) order_calculated ON o.order_id = order_calculated.order_id
            
            WHERE o.warehouse_id = :warehouse_id";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
    $stmt->execute();
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Calculate integrity scores
    $totalOrders = $result['total_orders'];
    $integrityScores = [
        'status_validity' => $totalOrders > 0 ? (1 - $result['invalid_statuses'] / $totalOrders) * 100 : 100,
        'date_consistency' => $totalOrders > 0 ? (1 - ($result['future_order_dates'] + $result['invalid_update_dates']) / $totalOrders) * 100 : 100,
        'reference_integrity' => $totalOrders > 0 ? (1 - $result['orphaned_customer_refs'] / $totalOrders) * 100 : 100,
        'amount_accuracy' => $totalOrders > 0 ? (1 - ($result['invalid_amounts'] + $result['amount_mismatches']) / $totalOrders) * 100 : 100,
        'completeness' => $result['orders_with_data'] > 0 ? ($result['orders_with_details'] / $result['orders_with_data']) * 100 : 100
    ];
    
    $overallScore = array_sum($integrityScores) / count($integrityScores);
    
    return [
        'metrics' => $result,
        'integrity_scores' => $integrityScores,
        'overall_score' => round($overallScore, 2),
        'issues' => [
            'data_inconsistencies' => $result['invalid_statuses'] + $result['future_order_dates'] + $result['invalid_update_dates'],
            'reference_errors' => $result['orphaned_customer_refs'],
            'amount_errors' => $result['invalid_amounts'] + $result['amount_mismatches'],
            'missing_details' => $result['orders_with_data'] - $result['orders_with_details']
        ]
    ];
}

function getInventoryDataAccuracyAnalysis($pdo, $warehouseId) {
    $sql = "SELECT 
                COUNT(*) as total_inventory_records,
                
                -- Quantity validation
                COUNT(CASE WHEN quantity < 0 THEN 1 END) as negative_quantities,
                COUNT(CASE WHEN quantity IS NULL THEN 1 END) as missing_quantities,
                
                -- Variant reference integrity
                COUNT(CASE WHEN variant_id NOT IN (SELECT variant_id FROM product_variants) THEN 1 END) as orphaned_variant_refs,
                
                -- Stock movement tracking
                COUNT(DISTINCT i.variant_id) as variants_with_inventory,
                COUNT(DISTINCT pv.variant_id) as total_variants,
                
                -- High-value stock accuracy
                COUNT(CASE WHEN quantity > 1000 THEN 1 END) as high_quantity_items,
                
                -- Zero stock items that had recent sales
                COUNT(CASE 
                    WHEN i.quantity = 0 AND recent_sales.has_recent_sales = 1 
                    THEN 1 
                END) as zero_stock_with_recent_sales,
                
                -- Last updated validation
                COUNT(CASE WHEN updated_at IS NULL THEN 1 END) as missing_update_dates,
                COUNT(CASE WHEN updated_at < DATE_SUB(NOW(), INTERVAL 90 DAY) THEN 1 END) as stale_inventory_records
                
            FROM inventory i
            LEFT JOIN product_variants pv ON i.variant_id = pv.variant_id
            LEFT JOIN products p ON pv.product_id = p.product_id
            LEFT JOIN (
                SELECT 
                    od.variant_id,
                    1 as has_recent_sales
                FROM order_details od
                JOIN orders o ON od.order_id = o.order_id
                WHERE o.status = 'delivered'
                    AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                    AND o.warehouse_id = :warehouse_id
                GROUP BY od.variant_id
            ) recent_sales ON i.variant_id = recent_sales.variant_id
            
            WHERE p.warehouse_id = :warehouse_id";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
    $stmt->execute();
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Calculate accuracy scores
    $totalRecords = $result['total_inventory_records'];
    $accuracyScores = [
        'quantity_validity' => $totalRecords > 0 ? (1 - ($result['negative_quantities'] + $result['missing_quantities']) / $totalRecords) * 100 : 100,
        'reference_integrity' => $totalRecords > 0 ? (1 - $result['orphaned_variant_refs'] / $totalRecords) * 100 : 100,
        'completeness' => $result['total_variants'] > 0 ? ($result['variants_with_inventory'] / $result['total_variants']) * 100 : 100,
        'freshness' => $totalRecords > 0 ? (1 - $result['stale_inventory_records'] / $totalRecords) * 100 : 100
    ];
    
    $overallScore = array_sum($accuracyScores) / count($accuracyScores);
    
    return [
        'metrics' => $result,
        'accuracy_scores' => $accuracyScores,
        'overall_score' => round($overallScore, 2),
        'issues' => [
            'data_quality_issues' => $result['negative_quantities'] + $result['missing_quantities'],
            'reference_issues' => $result['orphaned_variant_refs'],
            'potential_stockouts' => $result['zero_stock_with_recent_sales'],
            'stale_data' => $result['stale_inventory_records']
        ]
    ];
}

function getSKUPricingConsistencyAnalysis($pdo, $warehouseId) {
    $sql = "SELECT 
                -- SKU format consistency
                COUNT(*) as total_skus,
                COUNT(CASE WHEN sku REGEXP '^[A-Z]+-[A-Z]+-[0-9]+-[0-9]+$' THEN 1 END) as standard_format_skus,
                COUNT(DISTINCT sku) as unique_skus,
                
                -- Pricing consistency within product groups
                brand_consistency.brand_price_variations,
                category_consistency.category_price_variations,
                
                -- Price range analysis
                MIN(selling_price) as min_selling_price,
                MAX(selling_price) as max_selling_price,
                AVG(selling_price) as avg_selling_price,
                STDDEV(selling_price) as price_stddev,
                
                -- Margin consistency
                AVG((selling_price - cost_price) / NULLIF(selling_price, 0) * 100) as avg_margin_percentage,
                STDDEV((selling_price - cost_price) / NULLIF(selling_price, 0) * 100) as margin_stddev,
                
                -- Cost vs selling price validation
                COUNT(CASE WHEN selling_price <= cost_price THEN 1 END) as unprofitable_variants,
                COUNT(CASE WHEN selling_price > cost_price * 5 THEN 1 END) as excessive_markup_variants
                
            FROM product_variants pv
            JOIN products p ON pv.product_id = p.product_id
            
            CROSS JOIN (
                SELECT COUNT(*) as brand_price_variations
                FROM (
                    SELECT 
                        SUBSTRING_INDEX(pv2.sku, '-', 1) as brand,
                        COUNT(DISTINCT ROUND(pv2.selling_price, -3)) as price_points
                    FROM product_variants pv2
                    JOIN products p2 ON pv2.product_id = p2.product_id
                    WHERE p2.warehouse_id = :warehouse_id
                        AND pv2.selling_price > 0
                    GROUP BY SUBSTRING_INDEX(pv2.sku, '-', 1)
                    HAVING price_points > 3
                ) brand_analysis
            ) brand_consistency
            
            CROSS JOIN (
                SELECT COUNT(*) as category_price_variations
                FROM (
                    SELECT 
                        c.name,
                        COUNT(DISTINCT ROUND(pv3.selling_price, -3)) as price_points
                    FROM product_variants pv3
                    JOIN products p3 ON pv3.product_id = p3.product_id
                    JOIN categories c ON p3.category_id = c.category_id
                    WHERE p3.warehouse_id = :warehouse_id
                        AND pv3.selling_price > 0
                    GROUP BY c.category_id
                    HAVING price_points > 5
                ) category_analysis
            ) category_consistency
            
            WHERE p.warehouse_id = :warehouse_id
                AND pv.selling_price > 0
                AND pv.cost_price > 0";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
    $stmt->execute();
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Calculate consistency scores
    $totalSKUs = $result['total_skus'];
    $consistencyScores = [
        'sku_format_consistency' => $totalSKUs > 0 ? ($result['standard_format_skus'] / $totalSKUs) * 100 : 100,
        'sku_uniqueness' => $totalSKUs > 0 ? ($result['unique_skus'] / $totalSKUs) * 100 : 100,
        'pricing_reasonableness' => $totalSKUs > 0 ? (1 - ($result['unprofitable_variants'] + $result['excessive_markup_variants']) / $totalSKUs) * 100 : 100,
        'brand_consistency' => 100 - min($result['brand_price_variations'] * 10, 50), // Penalty for inconsistency
        'category_consistency' => 100 - min($result['category_price_variations'] * 10, 50)
    ];
    
    $overallScore = array_sum($consistencyScores) / count($consistencyScores);
    
    return [
        'metrics' => $result,
        'consistency_scores' => $consistencyScores,
        'overall_score' => round($overallScore, 2),
        'issues' => [
            'format_inconsistencies' => $totalSKUs - $result['standard_format_skus'],
            'duplicate_skus' => $totalSKUs - $result['unique_skus'],
            'pricing_issues' => $result['unprofitable_variants'] + $result['excessive_markup_variants'],
            'brand_price_variations' => $result['brand_price_variations'],
            'category_price_variations' => $result['category_price_variations']
        ]
    ];
}

function generateDataQualityRecommendations($productDataQuality, $customerDataQuality, $orderDataIntegrity) {
    $recommendations = [
        'urgent' => [],
        'important' => [],
        'suggested' => []
    ];
    
    // 1. Urgent: Critical data integrity issues
    if ($orderDataIntegrity['issues']['reference_errors'] > 0) {
        $recommendations['urgent'][] = [
            'type' => 'data_integrity_critical',
            'issue' => "Có {$orderDataIntegrity['issues']['reference_errors']} đơn hàng tham chiếu đến khách hàng không tồn tại",
            'recommendation' => 'Kiểm tra và sửa chữa reference integrity ngay lập tức',
            'priority_score' => 95,
            'impact' => 'Ảnh hưởng đến báo cáo và analytics'
        ];
    }
    
    // 2. Important: Low data quality scores
    if ($productDataQuality['overall_score'] < 70) {
        $recommendations['important'][] = [
            'type' => 'product_data_quality',
            'current_score' => $productDataQuality['overall_score'],
            'issue' => "Chất lượng dữ liệu sản phẩm thấp: {$productDataQuality['overall_score']}%",
            'recommendation' => 'Thiết lập quy trình validation dữ liệu sản phẩm',
            'priority_score' => 80,
            'missing_data' => $productDataQuality['issues']['missing_names'] + $productDataQuality['issues']['missing_descriptions']
        ];
    }
    
    // 3. Important: Customer data issues
    if ($customerDataQuality['issues']['invalid_formats'] > 0) {
        $recommendations['important'][] = [
            'type' => 'customer_data_validation',
            'affected_records' => $customerDataQuality['issues']['invalid_formats'],
            'issue' => "Có {$customerDataQuality['issues']['invalid_formats']} khách hàng với email/phone không hợp lệ",
            'recommendation' => 'Implement input validation và clean existing data',
            'priority_score' => 75,
            'impact' => 'Ảnh hưởng đến khả năng liên lạc với khách hàng'
        ];
    }
    
    // 4. Suggested: SKU standardization
    if ($productDataQuality['issues']['non_standard_skus'] > 0) {
        $recommendations['suggested'][] = [
            'type' => 'sku_standardization',
            'affected_skus' => $productDataQuality['issues']['non_standard_skus'],
            'issue' => "Có {$productDataQuality['issues']['non_standard_skus']} SKU không theo format chuẩn",
            'recommendation' => 'Chạy script chuẩn hóa SKU theo format BRAND-TYPE-NUMBER-SIZE',
            'priority_score' => 60,
            'benefit' => 'Cải thiện khả năng tìm kiếm và quản lý'
        ];
    }
    
    // 5. Suggested: Duplicate cleanup
    $totalDuplicates = $productDataQuality['issues']['duplicates'] + $customerDataQuality['issues']['duplicates'];
    if ($totalDuplicates > 0) {
        $recommendations['suggested'][] = [
            'type' => 'duplicate_cleanup',
            'total_duplicates' => $totalDuplicates,
            'issue' => "Phát hiện {$totalDuplicates} bản ghi trùng lặp",
            'recommendation' => 'Thiết lập job định kỳ để phát hiện và merge duplicates',
            'priority_score' => 55,
            'impact' => 'Giảm storage cost và cải thiện data accuracy'
        ];
    }
    
    return $recommendations;
}

// Chart formatting functions
function formatQualityScoreChartData($productDataQuality, $customerDataQuality) {
    $labels = ['Product Data', 'Customer Data'];
    $scores = [$productDataQuality['overall_score'], $customerDataQuality['overall_score']];
    $colors = [];
    
    foreach ($scores as $score) {
        if ($score >= 90) {
            $colors[] = '#28a745';
        } elseif ($score >= 70) {
            $colors[] = '#ffc107';
        } else {
            $colors[] = '#dc3545';
        }
    }
    
    return [
        'labels' => $labels,
        'datasets' => [
            [
                'label' => 'Quality Score (%)',
                'data' => $scores,
                'backgroundColor' => $colors
            ]
        ]
    ];
}

function formatCompletenessChartData($productDataQuality) {
    $labels = ['Name', 'Description', 'Category', 'SKU', 'Price'];
    $completeness = [
        $productDataQuality['quality_scores']['name_completeness'],
        $productDataQuality['quality_scores']['description_completeness'],
        $productDataQuality['quality_scores']['category_completeness'],
        $productDataQuality['quality_scores']['sku_validity'],
        $productDataQuality['quality_scores']['price_validity']
    ];
    
    return [
        'labels' => $labels,
        'datasets' => [
            [
                'label' => 'Completeness (%)',
                'data' => $completeness,
                'backgroundColor' => '#36b9cc',
                'borderColor' => '#36b9cc',
                'borderWidth' => 1
            ]
        ]
    ];
}

function formatConsistencyChartData($skuPricingConsistency) {
    $labels = ['SKU Format', 'SKU Uniqueness', 'Pricing', 'Brand Consistency', 'Category Consistency'];
    $consistency = [
        $skuPricingConsistency['consistency_scores']['sku_format_consistency'],
        $skuPricingConsistency['consistency_scores']['sku_uniqueness'],
        $skuPricingConsistency['consistency_scores']['pricing_reasonableness'],
        $skuPricingConsistency['consistency_scores']['brand_consistency'],
        $skuPricingConsistency['consistency_scores']['category_consistency']
    ];
    
    return [
        'type' => 'radar',
        'data' => [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Consistency Score',
                    'data' => $consistency,
                    'borderColor' => '#4e73df',
                    'backgroundColor' => 'rgba(78, 115, 223, 0.2)',
                    'pointBackgroundColor' => '#4e73df'
                ]
            ]
        ]
    ];
}

// Summary calculation functions
function calculateOverallQualityScore($productDataQuality, $customerDataQuality, $orderDataIntegrity) {
    $scores = [
        $productDataQuality['overall_score'],
        $customerDataQuality['overall_score'],
        $orderDataIntegrity['overall_score']
    ];
    
    return round(array_sum($scores) / count($scores), 2);
}

function countCriticalIssues($productDataQuality, $customerDataQuality, $orderDataIntegrity) {
    return $productDataQuality['issues']['negative_margins'] + 
           $customerDataQuality['issues']['invalid_formats'] + 
           $orderDataIntegrity['issues']['reference_errors'];
}

function calculateDataCompleteness($productDataQuality) {
    $completenessScores = [
        $productDataQuality['quality_scores']['name_completeness'],
        $productDataQuality['quality_scores']['description_completeness'],
        $productDataQuality['quality_scores']['category_completeness']
    ];
    
    return round(array_sum($completenessScores) / count($completenessScores), 2);
}

function calculateAccuracyPercentage($inventoryDataAccuracy) {
    return $inventoryDataAccuracy['overall_score'];
}
?>