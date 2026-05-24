<?php
/**
 * API Xuất Excel Gợi Ý Nhập Hàng - Giải Pháp Gộp Thông Minh
 * Gộp dữ liệu từ 2 nguồn:
 * 1. Gợi ý dựa trên KHÍ HẬU & SỰ KIỆN (3 miền)
 * 2. Gợi ý dựa trên TỒN KHO (CRITICAL, HIGH, MEDIUM)
 * 
 * Loại bỏ trùng lặp theo SKU, thêm cột "Lý Do Nhập Hàng" tổng hợp
 */

// Tăng thời gian thực thi
set_time_limit(300); // 5 phút
ini_set('max_execution_time', 300);

// Suppress all PHP errors and warnings to prevent file corruption
error_reporting(0);
ini_set('display_errors', 0);

// session_start();

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../public/vendor/autoload.php'; // PHPSpreadsheet

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Font;

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    die('Vui lòng đăng nhập để xuất Excel');
}

$database = new Database();
$conn = $database->getConnection();

// ============================================
// LOAD HELPER FUNCTIONS TỪ api_du_bao_ai.php
// ============================================

/**
 * Load functions từ api_du_bao_ai.php mà KHÔNG chạy logic API
 * Set flag để api_du_bao_ai.php biết là đang được include
 */
define('EXPORT_FORECAST_INCLUDE', true);

// Bỏ qua output từ api_du_bao_ai.php
ob_start();
require_once 'api_du_bao_ai.php';
ob_end_clean();

/**
 * Wrapper function để gọi getRestockSuggestions từ api_du_bao_ai.php
 */
function exportGetRestockSuggestions($conn, $warehouse_id, $month, $region) {
    try {
        // Gọi trực tiếp hàm từ api_du_bao_ai.php
        return getRestockSuggestions($conn, $warehouse_id, $month, $region);
    } catch (Exception $e) {
        error_log('exportGetRestockSuggestions Error: ' . $e->getMessage());
        return ['success' => false, 'data' => ['suggestions' => []]];
    }
}

/**
 * Wrapper function để gọi getLowStockSuggestions từ api_du_bao_ai.php
 */
function exportGetLowStockSuggestions($conn, $warehouse_id, $priority) {
    try {
        // Gọi trực tiếp hàm từ api_du_bao_ai.php
        return getLowStockSuggestions($conn, $warehouse_id, $priority);
    } catch (Exception $e) {
        error_log('exportGetLowStockSuggestions Error: ' . $e->getMessage());
        return ['success' => false, 'data' => ['suggestions' => []]];
    }
}

// ============================================
// MAIN LOGIC - XỬ LÝ XUẤT EXCEL
// ============================================

try {
    $warehouse_id = $_SESSION['warehouse_id'] ?? 1;
    $month = $_GET['month'] ?? date('n');
    
    // ============================================
    // BƯỚC 1: Lấy dữ liệu từ 2 nguồn
    // ============================================
    
    // 1.1. Gợi ý dựa trên KHÍ HẬU & SỰ KIỆN (3 miền)
    $climate_suggestions = [];
    
    foreach (['north', 'central', 'south'] as $region) {
        $climate_data = exportGetRestockSuggestions($conn, $warehouse_id, $month, $region);
        
        if ($climate_data['success'] && !empty($climate_data['data']['suggestions'])) {
            foreach ($climate_data['data']['suggestions'] as $product) {
                $product_id = $product['product_id'];
                
                // Lấy SKU cơ sở và tất cả size từ product_variants
                if (!isset($product['base_sku']) || empty($product['base_sku'])) {
                    $stmt = $conn->prepare("SELECT sku, size FROM product_variants WHERE product_id = :pid ORDER BY size");
                    $stmt->execute([':pid' => $product_id]);
                    $variants = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    if (!empty($variants)) {
                        // Lấy SKU cơ sở từ SKU đầu tiên (bỏ phần -size cuối)
                        $first_sku = $variants[0]['sku'];
                        // Tách SKU cơ sở: NIKE-SNE-851-39 -> NIKE-SNE-851
                        $product['base_sku'] = preg_replace('/-\d+$/', '', $first_sku);
                        
                        // Gộp tất cả size
                        $sizes = array_filter(array_column($variants, 'size'));
                        $product['available_sizes'] = !empty($sizes) ? implode(', ', $sizes) : 'N/A';
                    } else {
                        $product['base_sku'] = 'P' . str_pad($product_id, 6, '0', STR_PAD_LEFT);
                        $product['available_sizes'] = 'N/A';
                    }
                }
                
                // Sử dụng product_id làm key thay vì SKU cụ thể
                $key = $product_id;
                
                if (!isset($climate_suggestions[$key])) {
                    $climate_suggestions[$key] = $product;
                    $climate_suggestions[$key]['reasons'] = [];
                    $climate_suggestions[$key]['regions'] = [];
                }
                
                // Thêm lý do theo vùng miền
                $region_name = [
                    'north' => 'Miền Bắc',
                    'central' => 'Miền Trung',
                    'south' => 'Miền Nam'
                ][$region];
                
                $climate_suggestions[$key]['regions'][] = $region_name;
                $climate_suggestions[$key]['reasons'][] = "Khí hậu " . $region_name;
                
                // Lưu event boost cao nhất
                if (isset($product['event_boost'])) {
                    $climate_suggestions[$key]['event_boost'] = max(
                        $climate_suggestions[$key]['event_boost'] ?? 1.0,
                        $product['event_boost']
                    );
                    
                    if ($product['event_boost'] > 1.0 && !in_array('Sự kiện', $climate_suggestions[$key]['reasons'])) {
                        $climate_suggestions[$key]['reasons'][] = "Sự kiện/Lễ hội";
                    }
                }
            }
        }
    }
    
    // 1.2. Gợi ý dựa trên TỒN KHO (CRITICAL, HIGH, MEDIUM)
    $stock_suggestions = [];
    
    foreach (['CRITICAL', 'HIGH', 'MEDIUM'] as $priority) {
        $stock_data = exportGetLowStockSuggestions($conn, $warehouse_id, $priority);
        
        if ($stock_data['success'] && !empty($stock_data['data']['suggestions'])) {
            foreach ($stock_data['data']['suggestions'] as $product) {
                $product_id = $product['product_id'];
                
                // Lấy SKU cơ sở và tất cả size từ product_variants
                if (!isset($product['base_sku']) || empty($product['base_sku'])) {
                    $stmt = $conn->prepare("SELECT sku, size FROM product_variants WHERE product_id = :pid ORDER BY size");
                    $stmt->execute([':pid' => $product_id]);
                    $variants = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    if (!empty($variants)) {
                        // Lấy SKU cơ sở từ SKU đầu tiên (bỏ phần -size cuối)
                        $first_sku = $variants[0]['sku'];
                        // Tách SKU cơ sở: NIKE-SNE-851-39 -> NIKE-SNE-851
                        $product['base_sku'] = preg_replace('/-\d+$/', '', $first_sku);
                        
                        // Gộp tất cả size
                        $sizes = array_filter(array_column($variants, 'size'));
                        $product['available_sizes'] = !empty($sizes) ? implode(', ', $sizes) : 'N/A';
                    } else {
                        $product['base_sku'] = 'P' . str_pad($product_id, 6, '0', STR_PAD_LEFT);
                        $product['available_sizes'] = 'N/A';
                    }
                }
                
                // Sử dụng product_id làm key thay vì SKU cụ thể
                $key = $product_id;
                
                if (!isset($stock_suggestions[$key])) {
                    $stock_suggestions[$key] = $product;
                }
                
                // Lưu priority cao nhất
                $priorities = ['CRITICAL' => 3, 'HIGH' => 2, 'MEDIUM' => 1];
                $current_priority_level = $priorities[$stock_suggestions[$key]['priority']] ?? 0;
                $new_priority_level = $priorities[$priority] ?? 0;
                
                if ($new_priority_level > $current_priority_level) {
                    $stock_suggestions[$key]['priority'] = $priority;
                }
            }
        }
    }
    
    // ============================================
    // BƯỚC 2: Gộp 2 nguồn dữ liệu - LOẠI BỎ TRÙNG LẶP
    // ============================================
    
    $merged_products = [];
    
    // 2.1. Thêm sản phẩm từ climate_suggestions
    foreach ($climate_suggestions as $sku => $product) {
        $merged_products[$sku] = $product;
        $merged_products[$sku]['source'] = 'climate';
    }
    
    // 2.2. Gộp hoặc thêm sản phẩm từ stock_suggestions
    foreach ($stock_suggestions as $sku => $product) {
        if (isset($merged_products[$sku])) {
            // SẢN PHẨM TRÙNG LẶP → Gộp thông tin
            $merged_products[$sku]['reasons'][] = "Hết/Sắp hết tồn kho ({$product['priority']})";
            $merged_products[$sku]['source'] = 'both'; // Đánh dấu là từ cả 2 nguồn
            $merged_products[$sku]['priority'] = $product['priority']; // Ưu tiên từ tồn kho
            
            // Cập nhật số lượng đề xuất (lấy max hoặc sum)
            $merged_products[$sku]['suggested_quantity'] = max(
                $merged_products[$sku]['suggested_quantity'] ?? 0,
                $product['suggested_quantity'] ?? 0
            );
        } else {
            // SẢN PHẨM MỚI từ tồn kho
            $merged_products[$sku] = $product;
            $merged_products[$sku]['reasons'] = ["Hết/Sắp hết tồn kho ({$product['priority']})"];
            $merged_products[$sku]['source'] = 'stock';
            $merged_products[$sku]['regions'] = [];
        }
    }
    
    // ============================================
    // BƯỚC 3: Phân nhóm theo độ ưu tiên
    // ============================================
    
    $grouped_products = [
        'A' => [], // CRITICAL hoặc cả 2 nguồn (ưu tiên cao)
        'B' => [], // HIGH hoặc chỉ từ climate với event
        'C' => []  // MEDIUM hoặc chỉ từ climate thường
    ];
    
    foreach ($merged_products as $sku => $product) {
        // Tính điểm ưu tiên
        $priority_score = 0;
        
        // Điểm từ tồn kho
        if (isset($product['priority'])) {
            $priority_score += ['CRITICAL' => 30, 'HIGH' => 20, 'MEDIUM' => 10][$product['priority']] ?? 0;
        }
        
        // Điểm từ nguồn gộp
        if ($product['source'] === 'both') {
            $priority_score += 25; // Bonus cao nếu từ cả 2 nguồn
        }
        
        // Điểm từ sự kiện
        if (isset($product['event_boost']) && $product['event_boost'] > 1.0) {
            $priority_score += 15;
        }
        
        // Điểm từ số vùng miền
        $region_count = count($product['regions'] ?? []);
        if ($region_count === 3) $priority_score += 12;
        elseif ($region_count === 2) $priority_score += 8;
        elseif ($region_count === 1) $priority_score += 4;
        
        // Lưu điểm
        $product['priority_score'] = $priority_score;
        
        // Phân nhóm
        if ($priority_score >= 40) {
            $grouped_products['A'][] = $product;
        } elseif ($priority_score >= 20) {
            $grouped_products['B'][] = $product;
        } else {
            $grouped_products['C'][] = $product;
        }
    }
    
    // Sắp xếp theo điểm trong mỗi nhóm
    foreach ($grouped_products as $group => &$products) {
        usort($products, function($a, $b) {
            return $b['priority_score'] - $a['priority_score'];
        });
    }
    
    // ============================================
    // BƯỚC 4: Tạo file Excel
    // ============================================
    
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Gợi Ý Nhập Hàng');
    
    // Header
    $headers = [
        'A1' => 'STT',
        'B1' => 'Nhóm',
        'C1' => 'SKU',
        'D1' => 'Tên Sản Phẩm',
        'E1' => 'Loại',
        'F1' => 'Size',
        'G1' => 'Tồn Kho',
        'H1' => 'Bán 30 Ngày',
        'I1' => 'Lý Do Nhập Hàng',
        'J1' => 'Vùng Miền',
        'K1' => 'Số Lượng Đề Xuất',
        'L1' => 'Ghi Chú'
    ];
    
    foreach ($headers as $cell => $value) {
        $sheet->setCellValue($cell, $value);
    }
    
    // Style header
    $headerStyle = [
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 12],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4472C4']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
    ];
    $sheet->getStyle('A1:L1')->applyFromArray($headerStyle);
    
    // Đặt column widths
    $sheet->getColumnDimension('A')->setWidth(6);
    $sheet->getColumnDimension('B')->setWidth(8);
    $sheet->getColumnDimension('C')->setWidth(15);
    $sheet->getColumnDimension('D')->setWidth(35);
    $sheet->getColumnDimension('E')->setWidth(15);
    $sheet->getColumnDimension('F')->setWidth(8);
    $sheet->getColumnDimension('G')->setWidth(10);
    $sheet->getColumnDimension('H')->setWidth(12);
    $sheet->getColumnDimension('I')->setWidth(45);
    $sheet->getColumnDimension('J')->setWidth(25);
    $sheet->getColumnDimension('K')->setWidth(15);
    $sheet->getColumnDimension('L')->setWidth(30);
    
    // Data rows
    $row = 2;
    $stt = 1;
    
    foreach (['A', 'B', 'C'] as $group) {
        foreach ($grouped_products[$group] as $product) {
            // Sử dụng SKU cơ sở (đã tách bỏ phần size)
            $sku = $product['base_sku'] ?? ('P' . str_pad($product['product_id'], 6, '0', STR_PAD_LEFT));
            $sizes = $product['available_sizes'] ?? 'N/A';
            $reasons = implode(' + ', array_unique($product['reasons'] ?? []));
            $regions = implode(', ', array_unique($product['regions'] ?? []));
            
            // Ghi chú đặc biệt
            $note = '';
            if ($product['source'] === 'both') {
                $note = '⚠️ VỪA hết hàng VỪA cần cho sự kiện/khí hậu';
            } elseif (isset($product['event_boost']) && $product['event_boost'] > 1.5) {
                $note = '🎉 Sự kiện lớn - Tăng gấp ' . number_format($product['event_boost'], 1) . ' lần';
            }
            
            $sheet->setCellValue('A' . $row, $stt++);
            $sheet->setCellValue('B' . $row, $group);
            $sheet->setCellValue('C' . $row, $sku);
            $sheet->setCellValue('D' . $row, $product['product_name'] ?? 'N/A');
            $sheet->setCellValue('E' . $row, $product['category'] ?? $product['product_type'] ?? 'N/A');
            $sheet->setCellValue('F' . $row, $sizes); // Hiển thị tất cả size cần nhập
            $sheet->setCellValue('G' . $row, $product['current_stock'] ?? 0);
            $sheet->setCellValue('H' . $row, $product['total_sold_30days'] ?? 0);
            $sheet->setCellValue('I' . $row, $reasons);
            $sheet->setCellValue('J' . $row, $regions ?: 'Tất cả');
            $sheet->setCellValue('K' . $row, $product['suggested_quantity'] ?? 0);
            $sheet->setCellValue('L' . $row, $note);
            
            // Style theo nhóm
            $groupColors = [
                'A' => 'FFE6E6', // Đỏ nhạt
                'B' => 'FFF4E6', // Vàng nhạt
                'C' => 'E6F7FF'  // Xanh nhạt
            ];
            
            $sheet->getStyle('A' . $row . ':L' . $row)->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $groupColors[$group]]],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CCCCCC']]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true]
            ]);
            
            // Căn giữa cho các cột số
            $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('B' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('F' . $row . ':H' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('K' . $row . ':L' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            
            $row++;
        }
    }
    
    // Thêm legend ở cuối
    $row += 2;
    $sheet->setCellValue('A' . $row, 'CHƯƠNG TRÌNH GIẢI THÍCH:');
    $sheet->getStyle('A' . $row)->getFont()->setBold(true);
    $row++;
    
    $legends = [
        'Nhóm A (Ưu Tiên Cao):' => 'Sản phẩm VỪA hết tồn kho VỪA cần cho sự kiện/khí hậu → Nhập NGAY',
        'Nhóm B (Ưu Tiên Trung Bình):' => 'Sản phẩm tồn kho thấp HOẶC cần cho khí hậu/sự kiện → Nhập trong tuần',
        'Nhóm C (Chuẩn Bị Trước):' => 'Sản phẩm chuẩn bị cho mùa/khu vực → Nhập dần',
        '' => '',
        'Lý Do Nhập Hàng:' => 'Tổng hợp tất cả lý do từ nhiều nguồn (Khí hậu + Sự kiện + Tồn kho)',
        'Vùng Miền:' => 'Các vùng miền đề xuất nhập sản phẩm này (Bắc/Trung/Nam)',
        'Điểm Ưu Tiên:' => 'Điểm tổng hợp để xếp hạng (càng cao = càng cần nhập gấp)'
    ];
    
    foreach ($legends as $title => $desc) {
        $sheet->setCellValue('A' . $row, $title);
        $sheet->setCellValue('B' . $row, $desc);
        $sheet->mergeCells('B' . $row . ':M' . $row);
        if ($title) {
            $sheet->getStyle('A' . $row)->getFont()->setBold(true);
        }
        $row++;
    }
    
    // ============================================
    // BƯỚC 5: Xuất file
    // ============================================
    
    $filename = 'Goi_Y_Nhap_Hang_Thang_' . $month . '_' . date('Ymd_His') . '.xlsx';
    
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
    
} catch (Exception $e) {
    error_log('Export Forecast Error: ' . $e->getMessage());
    die('Lỗi xuất Excel: ' . $e->getMessage());
}
