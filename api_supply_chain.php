<?php
/**
 * API: Supply Chain Efficiency Analysis  
 * Module 3 của AI Analytics Dashboard
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
    // 1. Phân tích hiệu suất nhà cung cấp
    $supplierPerformance = getSupplierPerformanceAnalysis($pdo, $warehouseId);
    
    // 2. Lead Time Analysis
    $leadTimeAnalysis = getLeadTimeAnalysis($pdo, $warehouseId);
    
    // 3. Order Fulfillment Efficiency
    $fulfillmentAnalysis = getOrderFulfillmentAnalysis($pdo, $warehouseId);
    
    // 4. Stock Receipt Analysis
    $receiptAnalysis = getStockReceiptAnalysis($pdo, $warehouseId);
    
    // 5. AI Recommendations cho tối ưu supply chain
    $aiRecommendations = generateSupplyChainRecommendations($supplierPerformance, $leadTimeAnalysis, $fulfillmentAnalysis);
    
    $response = [
        'success' => true,
        'data' => [
            'supplier_performance' => $supplierPerformance,
            'lead_time_analysis' => $leadTimeAnalysis,
            'fulfillment_analysis' => $fulfillmentAnalysis,
            'receipt_analysis' => $receiptAnalysis,
            'ai_recommendations' => $aiRecommendations,
            'charts_data' => [
                'supplier_chart' => formatSupplierChartData($supplierPerformance),
                'lead_time_chart' => formatLeadTimeChartData($leadTimeAnalysis),
                'fulfillment_chart' => formatFulfillmentChartData($fulfillmentAnalysis)
            ],
            'summary' => [
                'avg_lead_time' => calculateAverageLeadTime($leadTimeAnalysis),
                'best_suppliers' => getBestSuppliers($supplierPerformance),
                'fulfillment_rate' => calculateOverallFulfillmentRate($fulfillmentAnalysis)
            ]
        ],
        'timestamp' => time()
    ];
    
    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Supply chain analysis failed: ' . $e->getMessage()
    ]);
}

function getSupplierPerformanceAnalysis($pdo, $warehouseId) {
    $sql = "SELECT 
                s.supplier_id,
                s.name as supplier_name,
                s.contact_person,
                s.email,
                
                -- Receipt metrics
                COUNT(DISTINCT sr.receipt_id) as total_receipts,
                COUNT(DISTINCT CASE WHEN sr.status = 'completed' THEN sr.receipt_id END) as completed_receipts,
                COUNT(DISTINCT CASE WHEN sr.status = 'cancelled' THEN sr.receipt_id END) as cancelled_receipts,
                
                -- Delivery performance
                ROUND(
                    (COUNT(DISTINCT CASE WHEN sr.status = 'completed' THEN sr.receipt_id END) / 
                     NULLIF(COUNT(DISTINCT sr.receipt_id), 0)) * 100, 2
                ) as delivery_success_rate,
                
                -- Lead time metrics (giả sử expected_date là ngày dự kiến)
                AVG(DATEDIFF(sr.updated_at, sr.created_at)) as avg_processing_days,
                MIN(DATEDIFF(sr.updated_at, sr.created_at)) as min_processing_days,
                MAX(DATEDIFF(sr.updated_at, sr.created_at)) as max_processing_days,
                
                -- Quality metrics (dựa trên quantity received vs expected)
                AVG(CASE 
                    WHEN srd.expected_quantity > 0 
                    THEN (srd.received_quantity / srd.expected_quantity) * 100
                    ELSE 100 
                END) as avg_fulfillment_accuracy,
                
                -- Value metrics
                SUM(srd.received_quantity * COALESCE(pv.cost_price, 0)) as total_value_received,
                
                -- Recent activity
                MAX(sr.created_at) as last_receipt_date,
                
                -- Performance score (weighted)
                ROUND(
                    (COALESCE(
                        (COUNT(DISTINCT CASE WHEN sr.status = 'completed' THEN sr.receipt_id END) / 
                         NULLIF(COUNT(DISTINCT sr.receipt_id), 0)) * 100, 0
                    ) * 0.4) + -- Delivery success rate (40%)
                    (GREATEST(0, 100 - AVG(DATEDIFF(sr.updated_at, sr.created_at))) * 0.3) + -- Speed (30%)
                    (COALESCE(AVG(CASE 
                        WHEN srd.expected_quantity > 0 
                        THEN (srd.received_quantity / srd.expected_quantity) * 100
                        ELSE 100 
                    END), 0) * 0.3), -- Accuracy (30%)
                2) as performance_score
                
            FROM suppliers s
            LEFT JOIN stock_receipts sr ON s.supplier_id = sr.supplier_id
            LEFT JOIN stock_receipt_details srd ON sr.receipt_id = srd.receipt_id
            LEFT JOIN product_variants pv ON srd.variant_id = pv.variant_id
            
            WHERE s.warehouse_id = :warehouse_id
                AND sr.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
            GROUP BY s.supplier_id
            HAVING total_receipts > 0
            ORDER BY performance_score DESC, total_value_received DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
    $stmt->execute();
    
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Phân loại performance
    foreach ($results as &$supplier) {
        if ($supplier['performance_score'] >= 85) {
            $supplier['rating'] = 'excellent';
            $supplier['rating_label'] = 'Xuất sắc';
        } elseif ($supplier['performance_score'] >= 70) {
            $supplier['rating'] = 'good';
            $supplier['rating_label'] = 'Tốt';
        } elseif ($supplier['performance_score'] >= 50) {
            $supplier['rating'] = 'average';
            $supplier['rating_label'] = 'Trung bình';
        } else {
            $supplier['rating'] = 'poor';
            $supplier['rating_label'] = 'Kém';
        }
    }
    
    return $results;
}

function getLeadTimeAnalysis($pdo, $warehouseId) {
    $sql = "SELECT 
                s.name as supplier_name,
                c.name as category_name,
                
                -- Lead time metrics
                COUNT(*) as total_orders,
                AVG(DATEDIFF(sr.updated_at, sr.created_at)) as avg_lead_time,
                MIN(DATEDIFF(sr.updated_at, sr.created_at)) as min_lead_time,
                MAX(DATEDIFF(sr.updated_at, sr.created_at)) as max_lead_time,
                STDDEV(DATEDIFF(sr.updated_at, sr.created_at)) as lead_time_variance,
                
                -- Reliability metrics
                COUNT(CASE WHEN DATEDIFF(sr.updated_at, sr.created_at) <= 7 THEN 1 END) as on_time_deliveries,
                ROUND(
                    (COUNT(CASE WHEN DATEDIFF(sr.updated_at, sr.created_at) <= 7 THEN 1 END) / 
                     COUNT(*)) * 100, 2
                ) as on_time_percentage,
                
                -- Monthly trend
                DATE_FORMAT(sr.created_at, '%Y-%m') as month_year,
                
                -- Value
                SUM(srd.received_quantity * COALESCE(pv.cost_price, 0)) as total_value
                
            FROM stock_receipts sr
            JOIN suppliers s ON sr.supplier_id = s.supplier_id
            JOIN stock_receipt_details srd ON sr.receipt_id = srd.receipt_id
            JOIN product_variants pv ON srd.variant_id = pv.variant_id
            JOIN products p ON pv.product_id = p.product_id
            
            WHERE sr.warehouse_id = :warehouse_id
                AND sr.status = 'completed'
                AND sr.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                AND sr.updated_at IS NOT NULL
            GROUP BY s.supplier_id, p.type, DATE_FORMAT(sr.created_at, '%Y-%m')
            ORDER BY month_year DESC, avg_lead_time ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
    $stmt->execute();
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getOrderFulfillmentAnalysis($pdo, $warehouseId) {
    $sql = "SELECT 
                DATE_FORMAT(o.created_at, '%Y-%m') as month_year,
                
                -- Order metrics
                COUNT(DISTINCT o.order_id) as total_orders,
                COUNT(DISTINCT CASE WHEN o.status = 'delivered' THEN o.order_id END) as delivered_orders,
                COUNT(DISTINCT CASE WHEN o.status = 'cancelled' THEN o.order_id END) as cancelled_orders,
                
                -- Fulfillment rate
                ROUND(
                    (COUNT(DISTINCT CASE WHEN o.status = 'delivered' THEN o.order_id END) / 
                     COUNT(DISTINCT o.order_id)) * 100, 2
                ) as fulfillment_rate,
                
                -- Processing time
                AVG(CASE 
                    WHEN o.status = 'delivered' 
                    THEN DATEDIFF(o.updated_at, o.created_at)
                    ELSE NULL 
                END) as avg_processing_days,
                
                -- Order value
                SUM(CASE WHEN o.status = 'delivered' THEN o.total_amount ELSE 0 END) as delivered_value,
                SUM(o.total_amount) as total_order_value,
                
                -- Customer satisfaction (giả sử dựa trên thời gian xử lý)
                COUNT(CASE 
                    WHEN o.status = 'delivered' AND DATEDIFF(o.updated_at, o.created_at) <= 3 
                    THEN 1 
                END) as fast_deliveries,
                
                ROUND(
                    (COUNT(CASE 
                        WHEN o.status = 'delivered' AND DATEDIFF(o.updated_at, o.created_at) <= 3 
                        THEN 1 
                    END) / 
                     NULLIF(COUNT(DISTINCT CASE WHEN o.status = 'delivered' THEN o.order_id END), 0)) * 100, 2
                ) as fast_delivery_rate
                
            FROM orders o
            WHERE o.warehouse_id = :warehouse_id
                AND o.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
            GROUP BY DATE_FORMAT(o.created_at, '%Y-%m')
            ORDER BY month_year DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
    $stmt->execute();
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getStockReceiptAnalysis($pdo, $warehouseId) {
    $sql = "SELECT 
                DATE_FORMAT(sr.created_at, '%Y-%m') as month_year,
                
                -- Receipt volume
                COUNT(sr.receipt_id) as total_receipts,
                SUM(srd.received_quantity) as total_quantity_received,
                SUM(srd.received_quantity * COALESCE(pv.cost_price, 0)) as total_value_received,
                
                -- Efficiency metrics
                AVG(DATEDIFF(sr.updated_at, sr.created_at)) as avg_processing_time,
                COUNT(CASE WHEN sr.status = 'completed' THEN 1 END) as completed_receipts,
                COUNT(CASE WHEN sr.status = 'cancelled' THEN 1 END) as cancelled_receipts,
                
                -- Accuracy
                AVG(CASE 
                    WHEN srd.expected_quantity > 0 
                    THEN (srd.received_quantity / srd.expected_quantity) * 100
                    ELSE 100 
                END) as avg_receipt_accuracy,
                
                -- Top categories
                GROUP_CONCAT(DISTINCT c.name ORDER BY COUNT(*) DESC LIMIT 3) as top_categories
                
            FROM stock_receipts sr
            LEFT JOIN stock_receipt_details srd ON sr.receipt_id = srd.receipt_id
            LEFT JOIN product_variants pv ON srd.variant_id = pv.variant_id
            LEFT JOIN products p ON pv.product_id = p.product_id
            LEFT JOIN categories c ON p.category_id = c.category_id
            
            WHERE sr.warehouse_id = :warehouse_id
                AND sr.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
            GROUP BY DATE_FORMAT(sr.created_at, '%Y-%m')
            ORDER BY month_year DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':warehouse_id', $warehouseId, PDO::PARAM_INT);
    $stmt->execute();
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function generateSupplyChainRecommendations($supplierPerformance, $leadTimeAnalysis, $fulfillmentAnalysis) {
    $recommendations = [
        'urgent' => [],
        'important' => [],
        'suggested' => []
    ];
    
    // 1. Urgent: Nhà cung cấp có vấn đề nghiêm trọng
    foreach ($supplierPerformance as $supplier) {
        if ($supplier['performance_score'] < 50 || $supplier['delivery_success_rate'] < 70) {
            $recommendations['urgent'][] = [
                'type' => 'poor_supplier_performance',
                'supplier_name' => $supplier['supplier_name'],
                'issue' => "Performance score: {$supplier['performance_score']}, Success rate: {$supplier['delivery_success_rate']}%",
                'recommendation' => 'Họp khẩn với NCC hoặc tìm nhà cung cấp thay thế',
                'priority_score' => 90,
                'impact' => 'Ảnh hưởng nghiêm trọng đến hoạt động'
            ];
        }
    }
    
    // 2. Important: Lead time quá dài
    $highLeadTime = array_filter($leadTimeAnalysis, function($item) {
        return $item['avg_lead_time'] > 14; // > 2 tuần
    });
    
    if (count($highLeadTime) > 0) {
        $recommendations['important'][] = [
            'type' => 'long_lead_time',
            'affected_suppliers' => count($highLeadTime),
            'issue' => 'Có ' . count($highLeadTime) . ' supplier có lead time > 14 ngày',
            'recommendation' => 'Đàm phán cải thiện lead time hoặc tăng safety stock',
            'priority_score' => 75,
            'impact' => 'Tăng risk stockout'
        ];
    }
    
    // 3. Suggested: Tối ưu fulfillment rate
    $avgFulfillmentRate = 0;
    if (count($fulfillmentAnalysis) > 0) {
        $totalRate = array_sum(array_column($fulfillmentAnalysis, 'fulfillment_rate'));
        $avgFulfillmentRate = $totalRate / count($fulfillmentAnalysis);
        
        if ($avgFulfillmentRate < 95) {
            $recommendations['suggested'][] = [
                'type' => 'improve_fulfillment',
                'current_rate' => round($avgFulfillmentRate, 1),
                'issue' => "Fulfillment rate trung bình: {$avgFulfillmentRate}%",
                'recommendation' => 'Phân tích nguyên nhân cancelled orders, cải thiện inventory planning',
                'priority_score' => 60,
                'target' => '> 95%'
            ];
        }
    }
    
    // 4. Supplier diversification
    $supplierCount = count($supplierPerformance);
    if ($supplierCount < 3) {
        $recommendations['suggested'][] = [
            'type' => 'supplier_diversification',
            'current_suppliers' => $supplierCount,
            'issue' => 'Quá ít nhà cung cấp, risk concentration cao',
            'recommendation' => 'Tìm thêm 2-3 nhà cung cấp backup',
            'priority_score' => 55,
            'benefit' => 'Giảm risk, tăng sức mạnh đàm phán'
        ];
    }
    
    return $recommendations;
}

function formatSupplierChartData($supplierPerformance) {
    $labels = [];
    $performanceData = [];
    $colors = [];
    
    foreach ($supplierPerformance as $supplier) {
        $labels[] = $supplier['supplier_name'];
        $performanceData[] = $supplier['performance_score'];
        
        // Color based on performance
        if ($supplier['performance_score'] >= 85) {
            $colors[] = '#28a745'; // Green
        } elseif ($supplier['performance_score'] >= 70) {
            $colors[] = '#ffc107'; // Yellow  
        } elseif ($supplier['performance_score'] >= 50) {
            $colors[] = '#fd7e14'; // Orange
        } else {
            $colors[] = '#dc3545'; // Red
        }
    }
    
    return [
        'labels' => $labels,
        'datasets' => [
            [
                'label' => 'Performance Score',
                'data' => $performanceData,
                'backgroundColor' => $colors
            ]
        ]
    ];
}

function formatLeadTimeChartData($leadTimeAnalysis) {
    $monthlyData = [];
    
    // Group by month
    foreach ($leadTimeAnalysis as $item) {
        $month = $item['month_year'];
        if (!isset($monthlyData[$month])) {
            $monthlyData[$month] = [
                'total_orders' => 0,
                'total_lead_time' => 0
            ];
        }
        
        $monthlyData[$month]['total_orders'] += $item['total_orders'];
        $monthlyData[$month]['total_lead_time'] += $item['avg_lead_time'] * $item['total_orders'];
    }
    
    $labels = [];
    $avgLeadTimes = [];
    
    foreach ($monthlyData as $month => $data) {
        $labels[] = $month;
        $avgLeadTimes[] = $data['total_orders'] > 0 ? 
            round($data['total_lead_time'] / $data['total_orders'], 1) : 0;
    }
    
    return [
        'labels' => array_reverse($labels), // Newest first
        'datasets' => [
            [
                'label' => 'Average Lead Time (days)',
                'data' => array_reverse($avgLeadTimes),
                'borderColor' => '#36b9cc',
                'backgroundColor' => 'rgba(54, 185, 204, 0.1)',
                'fill' => true
            ]
        ]
    ];
}

function formatFulfillmentChartData($fulfillmentAnalysis) {
    $labels = [];
    $fulfillmentRates = [];
    $processsingTimes = [];
    
    foreach (array_reverse($fulfillmentAnalysis) as $item) {
        $labels[] = $item['month_year'];
        $fulfillmentRates[] = $item['fulfillment_rate'];
        $processsingTimes[] = round($item['avg_processing_days'], 1);
    }
    
    return [
        'labels' => $labels,
        'datasets' => [
            [
                'label' => 'Fulfillment Rate (%)',
                'data' => $fulfillmentRates,
                'borderColor' => '#1cc88a',
                'backgroundColor' => 'rgba(28, 200, 138, 0.1)',
                'yAxisID' => 'y'
            ],
            [
                'label' => 'Avg Processing Days',
                'data' => $processsingTimes,
                'borderColor' => '#e74a3b',
                'backgroundColor' => 'rgba(231, 74, 59, 0.1)',
                'yAxisID' => 'y1'
            ]
        ]
    ];
}

function calculateAverageLeadTime($leadTimeAnalysis) {
    if (count($leadTimeAnalysis) === 0) return 0;
    
    $totalOrders = 0;
    $totalLeadTime = 0;
    
    foreach ($leadTimeAnalysis as $item) {
        $totalOrders += $item['total_orders'];
        $totalLeadTime += $item['avg_lead_time'] * $item['total_orders'];
    }
    
    return $totalOrders > 0 ? round($totalLeadTime / $totalOrders, 1) : 0;
}

function getBestSuppliers($supplierPerformance) {
    $best = array_filter($supplierPerformance, function($supplier) {
        return $supplier['performance_score'] >= 85;
    });
    
    return array_slice($best, 0, 3); // Top 3
}

function calculateOverallFulfillmentRate($fulfillmentAnalysis) {
    if (count($fulfillmentAnalysis) === 0) return 0;
    
    $totalOrders = 0;
    $totalDelivered = 0;
    
    foreach ($fulfillmentAnalysis as $item) {
        $totalOrders += $item['total_orders'];
        $totalDelivered += $item['delivered_orders'];
    }
    
    return $totalOrders > 0 ? round(($totalDelivered / $totalOrders) * 100, 2) : 0;
}
?>