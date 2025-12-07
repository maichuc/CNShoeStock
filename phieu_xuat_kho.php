<?php
session_start();
require_once 'config/cau_hinh_csdl.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    header('Location: login.html');
    exit;
}

$database = new Database();
$pdo = $database->getConnection();

$orderId = $_GET['order_id'] ?? null;

if (!$orderId) {
    die('Order ID is required');
}

// Get export slip details with all products
$sql = "
    SELECT 
        we.export_id,
        we.export_code,
        we.status as export_status,
        we.created_at,
        o.order_id,
        o.total_price as order_total,
        o.discount,
        COALESCE(c.full_name, 'Khách lẻ') as customer_name,
        COALESCE(c.phone, '') as customer_phone,
        COALESCE(c.address, '') as customer_address,
        w.name as warehouse_name,
        w.address as warehouse_address
    FROM orders o
    LEFT JOIN warehouse_exports we ON o.order_id = we.order_id
    LEFT JOIN customers c ON o.customer_id = c.customer_id
    JOIN warehouses w ON o.warehouse_id = w.warehouse_id
    WHERE o.order_id = :order_id
";

$stmt = $pdo->prepare($sql);
$stmt->bindParam(':order_id', $orderId, PDO::PARAM_INT);
$stmt->execute();
$exportData = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$exportData) {
    die('Order not found');
}

// Get current logged-in user for signature
$currentUserSql = "SELECT COALESCE(full_name, username) as full_name FROM users WHERE user_id = :user_id";
$currentUserStmt = $pdo->prepare($currentUserSql);
$currentUserStmt->execute(['user_id' => $_SESSION['user_id']]);
$currentUser = $currentUserStmt->fetch(PDO::FETCH_ASSOC);
$createdByName = $currentUser['full_name'] ?? '';

// Get export details (products) - try from warehouse_export_details first, fallback to order_details
$exportDetails = [];

if ($exportData['export_id']) {
    // Get from warehouse_export_details if export exists
    $detailsSql = "
        SELECT 
            wed.variant_id,
            wed.quantity,
            wed.unit_price,
            wed.total_price,
            p.name as product_name,
            pv.sku as product_code,
            pv.size,
            pv.color,
            ROW_NUMBER() OVER (ORDER BY wed.created_at) as stt
        FROM warehouse_export_details wed
        LEFT JOIN product_variants pv ON wed.variant_id = pv.variant_id
        LEFT JOIN products p ON pv.product_id = p.product_id
        WHERE wed.export_id = :export_id
        ORDER BY wed.created_at
    ";

    $detailsStmt = $pdo->prepare($detailsSql);
    $detailsStmt->bindParam(':export_id', $exportData['export_id'], PDO::PARAM_INT);
    $detailsStmt->execute();
    $exportDetails = $detailsStmt->fetchAll(PDO::FETCH_ASSOC);
}

// If no export details found, get from order_details
if (empty($exportDetails)) {
    $detailsSql = "
        SELECT 
            od.variant_id,
            od.quantity,
            od.unit_price,
            od.total_price,
            p.name as product_name,
            pv.sku as product_code,
            pv.size,
            pv.color,
            ROW_NUMBER() OVER (ORDER BY od.order_detail_id) as stt
        FROM order_details od
        LEFT JOIN product_variants pv ON od.variant_id = pv.variant_id
        LEFT JOIN products p ON pv.product_id = p.product_id
        WHERE od.order_id = :order_id
        ORDER BY od.order_detail_id
    ";

    $detailsStmt = $pdo->prepare($detailsSql);
    $detailsStmt->bindParam(':order_id', $orderId, PDO::PARAM_INT);
    $detailsStmt->execute();
    $exportDetails = $detailsStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Calculate totals
$totalQuantity = array_sum(array_column($exportDetails, 'quantity'));
$subtotalAmount = array_sum(array_column($exportDetails, 'total_price'));

// Calculate discount and final total
$discountPercent = $exportData['discount'] ?? 0;
$discountAmount = 0;
$totalAmount = $subtotalAmount;

if ($discountPercent > 0) {
    $discountAmount = $subtotalAmount * ($discountPercent / 100);
    $totalAmount = $subtotalAmount - $discountAmount;
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    
    <title>Phiếu xuất kho - <?php echo htmlspecialchars($exportData['export_code'] ?? 'PXK-' . str_pad($exportData['export_id'], 3, '0', STR_PAD_LEFT)); ?></title>

    <style>
        body {
            font-family: 'Times New Roman', serif;
            font-size: 12px;
            line-height: 1.4;
            margin: 0;
            padding: 20px;
            color: #000;
        }
        
        .container {
            max-width: 210mm;
            margin: 0 auto;
            background: white;
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
            border-bottom: 1px solid #000;
            padding-bottom: 10px;
        }
        
        .company-info {
            flex: 1;
        }
        
        .form-info {
            text-align: right;
            flex: 1;
        }
        
        .title {
            text-align: center;
            margin: 30px 0;
        }
        
        .title h1 {
            font-size: 18px;
            font-weight: bold;
            margin: 0;
            text-transform: uppercase;
        }
        
        .title .date {
            font-size: 12px;
            font-style: italic;
            margin: 5px 0;
        }
        
        .title .code {
            font-size: 12px;
            margin: 5px 0;
        }
        
        .info-section {
            display: flex;
            justify-content: space-between;
            margin: 20px 0;
        }
        
        .info-left, .info-right {
            flex: 1;
        }
        
        .info-right {
            margin-left: 50px;
        }
        
        .table-container {
            margin: 30px 0;
        }
        
        .export-table {
            width: 100%;
            border-collapse: collapse;
            border: 1px solid #000;
        }
        
        .export-table th,
        .export-table td {
            border: 1px solid #000;
            padding: 8px 5px;
            text-align: center;
            vertical-align: middle;
        }
        
        .export-table th {
            background-color: #f5f5f5;
            font-weight: bold;
            font-size: 11px;
        }
        
        .export-table td {
            font-size: 11px;
        }
        
        .export-table .text-left {
            text-align: left;
        }
        
        .export-table .text-right {
            text-align: right;
        }
        
        .footer-info {
            margin-top: 30px;
            font-size: 11px;
        }
        
        .signature-section {
            display: flex;
            justify-content: space-between;
            margin-top: 50px;
        }
        
        .signature-box {
            text-align: center;
            flex: 1;
        }
        
        .signature-title {
            font-weight: bold;
            margin-bottom: 60px;
        }
        
        .signature-name {
            font-style: italic;
        }
        
        @media print {
            body { margin: 0; padding: 10px; }
            .no-print { display: none !important; }
            .container { max-width: none; }
        }
        
        .no-print {
            text-align: center;
            margin: 20px 0;
        }
        
        .btn {
            display: inline-block;
            padding: 10px 20px;
            background: #007bff;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            margin: 5px;
            border: none;
            cursor: pointer;
        }
        
        .btn:hover {
            background: #0056b3;
        }
        
        .btn-print {
            background: #28a745;
        }
        
        .btn-print:hover {
            background: #1e7e34;
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="company-info">
                <strong>Đơn vị: <?php echo htmlspecialchars($exportData['warehouse_name']); ?></strong><br>
                <strong>Địa chỉ: <?php echo htmlspecialchars($exportData['warehouse_address'] ?: 'Địa chỉ kho hàng'); ?></strong>
            </div>
            <div class="form-info">
                <strong>Mẫu số 02 - VT</strong><br>
                (Ban hành theo Thông tư số 200/2014/TT-BTC<br>
                Ngày 22/12/2014 của Bộ Tài chính)
            </div>
        </div>

        <!-- Title -->
        <div class="title">
            <h1>PHIẾU XUẤT KHO</h1>
            <div class="date">Ngày <?php echo date('d', strtotime($exportData['created_at'])); ?> tháng <?php echo date('m', strtotime($exportData['created_at'])); ?> năm <?php echo date('Y', strtotime($exportData['created_at'])); ?></div>
            <div class="code">Số: <?php echo htmlspecialchars($exportData['export_code'] ?: 'PXK-' . str_pad($exportData['export_id'], 3, '0', STR_PAD_LEFT)); ?></div>
            <div style="text-align: right; margin-top: 10px;">
                <div>Nợ: 632</div>
                <div>Có: 156</div>
            </div>
        </div>

        <!-- Info Section -->
        <div class="info-section">
            <div class="info-left">
                <div><strong>Họ và tên người nhận hàng:</strong> <?php echo htmlspecialchars($exportData['customer_name']); ?></div>
                <div><strong>Lý do xuất kho:</strong> Xuất bán</div>
                <div><strong>Xuất tại kho (ngăn lô):</strong> Hàng hóa</div>
            </div>
            <div class="info-right">
                <div><strong>Địa chỉ (bộ phận):</strong> <?php echo htmlspecialchars($exportData['customer_address'] ?: 'Khách hàng'); ?></div>
                <div><strong>Địa điểm:</strong> <?php echo htmlspecialchars($exportData['warehouse_address'] ?: 'Kho hàng'); ?></div>
            </div>
        </div>

        <!-- Table -->
        <div class="table-container">
            <table class="export-table">
                <thead>
                    <tr>
                        <th rowspan="2" style="width: 40px;">STT</th>
                        <th rowspan="2" style="width: 200px;">Tên, nhãn hiệu, quy cách, phẩm chất vật tư, dụng cụ, sản phẩm hàng hóa</th>
                        <th rowspan="2" style="width: 60px;">Mã số</th>
                        <th rowspan="2" style="width: 40px;">ĐVT</th>
                        <th colspan="2" style="width: 120px;">Số lượng</th>
                        <th rowspan="2" style="width: 80px;">Đơn giá</th>
                        <th rowspan="2" style="width: 100px;">Thành tiền</th>
                    </tr>
                    <tr>
                        <th style="width: 60px;">Theo chứng từ</th>
                        <th style="width: 60px;">Thực xuất</th>
                    </tr>
                    <tr style="text-align: center; font-style: italic;">
                        <td>A</td>
                        <td>B</td>
                        <td>C</td>
                        <td>D</td>
                        <td>1</td>
                        <td>2</td>
                        <td>3</td>
                        <td>4</td>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($exportDetails)): ?>
                        <?php foreach ($exportDetails as $index => $detail): ?>
                        <tr>
                            <td><?php echo $detail['stt']; ?></td>
                            <td class="text-left">
                                <?php 
                                $productName = $detail['product_name'] ?: '[Sản phẩm đã xóa - Variant ID: ' . ($detail['variant_id'] ?? 'N/A') . ']';
                                echo htmlspecialchars($productName); 
                                ?>
                                <?php if ($detail['color'] || $detail['size']): ?>
                                    <br><small>
                                        <?php if ($detail['color']): ?>Màu: <?php echo htmlspecialchars($detail['color']); ?><?php endif; ?>
                                        <?php if ($detail['color'] && $detail['size']): ?> - <?php endif; ?>
                                        <?php if ($detail['size']): ?>Size: <?php echo htmlspecialchars($detail['size']); ?><?php endif; ?>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($detail['product_code'] ?: '[N/A]'); ?></td>
                            <td>Cặp</td>
                            <td><?php echo number_format($detail['quantity'], 0, ',', '.'); ?></td>
                            <td><?php echo number_format($detail['quantity'], 0, ',', '.'); ?></td>
                            <td class="text-right"><?php echo number_format($detail['unit_price'], 0, ',', '.'); ?></td>
                            <td class="text-right"><?php echo number_format($detail['total_price'], 0, ',', '.'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="text-center">Không có sản phẩm</td>
                        </tr>
                    <?php endif; ?>
                    
                    <!-- Subtotal Row -->
                    <tr style="font-weight: bold;">
                        <td colspan="4" class="text-right">Tổng tiền hàng:</td>
                        <td><?php echo number_format($totalQuantity, 0, ',', '.'); ?></td>
                        <td><?php echo number_format($totalQuantity, 0, ',', '.'); ?></td>
                        <td></td>
                        <td class="text-right"><?php echo number_format($subtotalAmount, 0, ',', '.'); ?></td>
                    </tr>
                    <?php if ($discountPercent > 0): ?>
                    <!-- Discount Row -->
                    <tr>
                        <td colspan="7" class="text-right">Chiết khấu (<?php echo $discountPercent; ?>%):</td>
                        <td class="text-right">-<?php echo number_format($discountAmount, 0, ',', '.'); ?></td>
                    </tr>
                    <!-- Final Total Row -->
                    <tr style="font-weight: bold; border-top: 2px solid #000;">
                        <td colspan="7" class="text-right">Tổng cộng:</td>
                        <td class="text-right"><?php echo number_format($totalAmount, 0, ',', '.'); ?></td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Footer Info -->
        <div class="footer-info">
            <div><strong>Tổng số tiền (viết bằng chữ):</strong> <?php echo ucfirst(convertNumberToWords($totalAmount)); ?> đồng</div>
            <div><strong>Số chứng từ gốc kèm theo:</strong> HD GTGT 156377</div>
        </div>

        <!-- Signature Section -->
        <div class="signature-section">
            <div class="signature-box">
                <div class="signature-title">Người lập</div>
                <div class="signature-name">(Ký, họ tên)</div>
                <br><br><br>
                <div><?php echo htmlspecialchars($createdByName); ?></div>
            </div>
            
            <div class="signature-box">
                <div class="signature-title">Người nhận hàng</div>
                <div class="signature-name">(Ký, họ tên)</div>
                <br><br><br>
                <div><?php echo htmlspecialchars($exportData['customer_name']); ?></div>
            </div>
            
            <div class="signature-box">
                <div class="signature-title">Thủ kho</div>
                <div class="signature-name">(Ký, họ tên)</div>
            </div>
            
            <div class="signature-box">
                <div class="signature-title">Kế toán trưởng</div>
                <div class="signature-name">(Hoặc bộ phận có nhu cầu)</div>
            </div>
            
            <div class="signature-box">
                <div class="signature-title">Giám đốc</div>
                <div class="signature-name">(Ký, họ)</div>
            </div>
        </div>
    </div>

    <!-- Print Controls -->
    <div class="no-print">
        <button class="btn btn-print" onclick="window.print()">
            <i class="fas fa-print"></i> In phiếu
        </button>
        <a href="quan_ly_xuat_kho.php" class="btn">
            <i class="fas fa-arrow-left"></i> Quay lại
        </a>
    </div>

    <script>
        // Auto print when page loads (optional)
        // window.onload = function() { window.print(); }
    </script>
</body>
</html>

<?php
// Function to convert number to words in Vietnamese
function convertNumberToWords($number) {
    $hyphen      = ' ';
    $conjunction = ' ';
    $separator   = ' ';
    $negative    = 'âm ';
    $decimal     = ' phẩy ';
    $dictionary  = array(
        0                   => 'không',
        1                   => 'một',
        2                   => 'hai',
        3                   => 'ba',
        4                   => 'bốn',
        5                   => 'năm',
        6                   => 'sáu',
        7                   => 'bảy',
        8                   => 'tám',
        9                   => 'chín',
        10                  => 'mười',
        11                  => 'mười một',
        12                  => 'mười hai',
        13                  => 'mười ba',
        14                  => 'mười bốn',
        15                  => 'mười lăm',
        16                  => 'mười sáu',
        17                  => 'mười bảy',
        18                  => 'mười tám',
        19                  => 'mười chín',
        20                  => 'hai mười',
        30                  => 'ba mười',
        40                  => 'bốn mười',
        50                  => 'năm mười',
        60                  => 'sáu mười',
        70                  => 'bảy mười',
        80                  => 'tám mười',
        90                  => 'chín mười',
        100                 => 'trăm',
        1000                => 'nghìn',
        1000000             => 'triệu',
        1000000000          => 'tỷ',
        1000000000000       => 'nghìn tỷ',
        1000000000000000    => 'triệu tỷ',
        1000000000000000000 => 'tỷ tỷ'
    );

    if (!is_numeric($number)) {
        return false;
    }

    if ($number < 0) {
        return $negative . convertNumberToWords(abs($number));
    }

    $string = $fraction = null;

    if (strpos($number, '.') !== false) {
        list($number, $fraction) = explode('.', $number);
    }

    switch (true) {
        case $number < 21:
            $string = $dictionary[$number];
            break;
        case $number < 100:
            $tens   = ((int) ($number / 10)) * 10;
            $units  = $number % 10;
            $string = $dictionary[$tens];
            if ($units) {
                $string .= $hyphen . $dictionary[$units];
            }
            break;
        case $number < 1000:
            $hundreds  = $number / 100;
            $remainder = $number % 100;
            $string = $dictionary[$hundreds] . ' ' . $dictionary[100];
            if ($remainder) {
                $string .= $conjunction . convertNumberToWords($remainder);
            }
            break;
        default:
            $baseUnit = pow(1000, floor(log($number, 1000)));
            $numBaseUnits = (int) ($number / $baseUnit);
            $remainder = $number % $baseUnit;
            $string = convertNumberToWords($numBaseUnits) . ' ' . $dictionary[$baseUnit];
            if ($remainder) {
                $string .= $remainder < 100 ? $conjunction : $separator;
                $string .= convertNumberToWords($remainder);
            }
            break;
    }

    if (null !== $fraction && is_numeric($fraction)) {
        $string .= $decimal;
        $words = array();
        foreach (str_split((string) $fraction) as $digit) {
            $words[] = $dictionary[intval($digit)];
        }
        $string .= implode(' ', $words);
    }

    return $string;
}
?>