<?php
/**
 * Generate Excel Template for Bulk Employee Import
 * Tạo file Excel mẫu cho import nhân viên hàng loạt
 */

require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Color;

function generateBulkImportTemplate() {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    
    // Đặt sheet name
    $sheet->setTitle('Employee Import');
    
    // ==================== HEADER ROW ====================
    $headers = [
        'A1' => ['value' => 'Họ và tên *', 'width' => 25],
        'B1' => ['value' => 'Email *', 'width' => 30],
        'C1' => ['value' => 'Username *', 'width' => 20],
        'D1' => ['value' => 'Số điện thoại', 'width' => 15],
        'E1' => ['value' => 'Vai trò', 'width' => 15]
    ];
    
    foreach ($headers as $cell => $data) {
        $sheet->setCellValue($cell, $data['value']);
        $column = substr($cell, 0, 1);
        $sheet->getColumnDimension($column)->setWidth($data['width']);
    }
    
    // Style header
    $headerStyle = [
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['rgb' => '4E73DF']
        ],
        'font' => [
            'bold' => true,
            'color' => ['rgb' => 'FFFFFF'],
            'size' => 12
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER
        ],
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['rgb' => '000000']
            ]
        ]
    ];
    
    $sheet->getStyle('A1:E1')->applyFromArray($headerStyle);
    $sheet->getRowDimension(1)->setRowHeight(25);
    
    // ==================== SAMPLE DATA ====================
    $sampleData = [
        ['Nguyễn Văn A', 'nguyenvana@example.com', 'nguyenvana', '0901234567', 'staff'],
        ['Trần Thị B', 'tranthib@example.com', 'tranthib', '0912345678', 'manager'],
        ['Lê Văn C', 'levanc@example.com', 'levanc', '', 'staff']
    ];
    
    $row = 2;
    foreach ($sampleData as $data) {
        $col = 'A';
        foreach ($data as $value) {
            $sheet->setCellValue($col . $row, $value);
            $col++;
        }
        $row++;
    }
    
    // Style sample data
    $dataStyle = [
        'alignment' => [
            'vertical' => Alignment::VERTICAL_CENTER
        ],
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['rgb' => 'CCCCCC']
            ]
        ]
    ];
    
    $sheet->getStyle('A2:E' . ($row - 1))->applyFromArray($dataStyle);
    
    // ==================== INSTRUCTIONS SHEET ====================
    $instructionsSheet = $spreadsheet->createSheet();
    $instructionsSheet->setTitle('Hướng dẫn');
    
    $instructions = [
        ['HƯỚNG DẪN SỬ DỤNG FILE IMPORT NHÂN VIÊN'],
        [''],
        ['1. QUY TẮC CHUNG'],
        ['   - Các cột có dấu (*) là bắt buộc phải điền'],
        ['   - Tối đa 100 nhân viên/lần import'],
        ['   - File phải có định dạng .xlsx hoặc .csv'],
        ['   - Kích thước file tối đa 2MB'],
        [''],
        ['2. CHI TIẾT CÁC CỘT'],
        [''],
        ['   A. Họ và tên * (Bắt buộc)'],
        ['      - Độ dài: 2-100 ký tự'],
        ['      - Cho phép ký tự tiếng Việt có dấu'],
        ['      - Ví dụ: Nguyễn Văn An, Trần Thị Bình'],
        [''],
        ['   B. Email * (Bắt buộc)'],
        ['      - Phải là email hợp lệ'],
        ['      - Email phải là duy nhất trong hệ thống'],
        ['      - Không chứa khoảng trắng'],
        ['      - Ví dụ: nguyenvanan@company.com'],
        [''],
        ['   C. Username * (Bắt buộc)'],
        ['      - Độ dài: 4-50 ký tự'],
        ['      - Chỉ chứa chữ cái, số, dấu chấm (.) và gạch dưới (_)'],
        ['      - Không có khoảng trắng'],
        ['      - Username phải là duy nhất trong hệ thống'],
        ['      - Ví dụ: nguyenvanan, tran.thi.binh, user_123'],
        [''],
        ['   D. Số điện thoại (Tùy chọn)'],
        ['      - Định dạng: 10-11 số'],
        ['      - Bắt đầu bằng số 0'],
        ['      - Để trống nếu không có'],
        ['      - Ví dụ: 0901234567, 0123456789'],
        [''],
        ['   E. Vai trò (Tùy chọn)'],
        ['      - Giá trị: staff, manager'],
        ['      - Không phân biệt hoa thường'],
        ['      - Để trống = staff (mặc định)'],
        ['      - Ví dụ: staff, MANAGER, Manager'],
        [''],
        ['3. SAU KHI IMPORT'],
        ['   - Hệ thống sẽ tự động sinh mật khẩu tạm thời cho mỗi nhân viên'],
        ['   - Email chào mừng kèm thông tin đăng nhập sẽ được gửi tự động'],
        ['   - Nhân viên phải đổi mật khẩu khi đăng nhập lần đầu'],
        ['   - File kết quả chi tiết sẽ được tạo sau khi import hoàn tất'],
        [''],
        ['4. LƯU Ý QUAN TRỌNG'],
        ['   - Kiểm tra kỹ email và username trước khi import'],
        ['   - Email và username không thể thay đổi sau khi tạo'],
        ['   - Nếu gửi email thất bại, bạn có thể gửi lại sau'],
        ['   - Mỗi nhân viên sẽ được phân vào kho của người thực hiện import'],
        [''],
        ['5. XỬ LÝ LỖI'],
        ['   - Nếu có dòng nào lỗi, hệ thống sẽ bỏ qua và tiếp tục xử lý các dòng hợp lệ'],
        ['   - File kết quả sẽ liệt kê chi tiết lỗi của từng dòng'],
        ['   - Bạn có thể sửa lỗi và import lại các dòng thất bại'],
        [''],
        ['6. VÍ DỤ DỮ LIỆU MẪU'],
        ['   Vui lòng xem tab "Employee Import" để tham khảo dữ liệu mẫu'],
        [''],
        ['7. HỖ TRỢ'],
        ['   Nếu gặp vấn đề, vui lòng liên hệ quản trị hệ thống'],
        [''],
        ['© 2025 Smart Warehouse System. All rights reserved.']
    ];
    
    $row = 1;
    foreach ($instructions as $line) {
        $instructionsSheet->setCellValue('A' . $row, $line[0]);
        $row++;
    }
    
    // Style instructions
    $instructionsSheet->getColumnDimension('A')->setWidth(80);
    $instructionsSheet->getStyle('A1')->applyFromArray([
        'font' => [
            'bold' => true,
            'size' => 14,
            'color' => ['rgb' => '4E73DF']
        ]
    ]);
    
    $instructionsSheet->getStyle('A3')->applyFromArray([
        'font' => ['bold' => true, 'size' => 12]
    ]);
    
    $instructionsSheet->getStyle('A9')->applyFromArray([
        'font' => ['bold' => true, 'size' => 12]
    ]);
    
    // ==================== VALIDATION SHEET ====================
    $validationSheet = $spreadsheet->createSheet();
    $validationSheet->setTitle('Validation Rules');
    
    $validationData = [
        ['Cột', 'Quy tắc', 'Ví dụ hợp lệ', 'Ví dụ không hợp lệ'],
        ['Họ và tên', '2-100 ký tự, Unicode', 'Nguyễn Văn An', 'A, (tên quá ngắn)'],
        ['Email', 'Email hợp lệ, unique', 'user@example.com', 'invalid-email, email trùng'],
        ['Username', '4-50 ký tự, a-z, 0-9, _, .', 'user123, user.name', 'user@123, u (quá ngắn)'],
        ['Số điện thoại', '10-11 số, bắt đầu 0', '0901234567', '123456789, 9012345678'],
        ['Vai trò', 'staff, manager', 'staff, MANAGER', 'admin, employee']
    ];
    
    $row = 1;
    foreach ($validationData as $data) {
        $col = 'A';
        foreach ($data as $value) {
            $validationSheet->setCellValue($col . $row, $value);
            $col++;
        }
        $row++;
    }
    
    // Style validation sheet
    $validationSheet->getColumnDimension('A')->setWidth(15);
    $validationSheet->getColumnDimension('B')->setWidth(40);
    $validationSheet->getColumnDimension('C')->setWidth(25);
    $validationSheet->getColumnDimension('D')->setWidth(30);
    
    $validationSheet->getStyle('A1:D1')->applyFromArray($headerStyle);
    $validationSheet->getStyle('A2:D' . ($row - 1))->applyFromArray($dataStyle);
    
    // ==================== SET ACTIVE SHEET ====================
    $spreadsheet->setActiveSheetIndex(0);
    
    // ==================== SAVE FILE ====================
    $outputDir = __DIR__ . '/../temp/';
    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0777, true);
    }
    
    $filename = 'employee_import_template.xlsx';
    $filepath = $outputDir . $filename;
    
    $writer = new Xlsx($spreadsheet);
    $writer->save($filepath);
    
    return [
        'success' => true,
        'filepath' => $filepath,
        'filename' => $filename,
        'message' => 'Template file created successfully'
    ];
}

// Tạo template when accessed directly
if (php_sapi_name() === 'cli' || (isset($_GET['generate']) && $_GET['generate'] === 'template')) {
    $result = generateBulkImportTemplate();
    
    if (php_sapi_name() === 'cli') {
        echo "Template generated: " . $result['filepath'] . "\n";
    } else {
        // Tải xuống file
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="' . $result['filename'] . '"');
        header('Cache-Control: max-age=0');
        readfile($result['filepath']);
        exit;
    }
}
?>
