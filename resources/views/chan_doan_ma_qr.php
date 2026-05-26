<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QR Code System Diagnostics</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            max-width: 1200px;
            margin: 40px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1 {
            color: #2c3e50;
            border-bottom: 3px solid #3498db;
            padding-bottom: 10px;
        }
        h2 {
            color: #34495e;
            margin-top: 30px;
        }
        .card {
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            padding: 20px;
            margin: 15px 0;
            background: #f9f9f9;
        }
        .card h3 {
            margin-top: 0;
            color: #2980b9;
        }
        .button {
            display: inline-block;
            padding: 12px 24px;
            margin: 8px 8px 8px 0;
            background: #3498db;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            font-weight: 500;
            transition: background 0.3s;
        }
        .button:hover {
            background: #2980b9;
        }
        .button.success {
            background: #27ae60;
        }
        .button.success:hover {
            background: #229954;
        }
        .button.warning {
            background: #f39c12;
        }
        .button.warning:hover {
            background: #e67e22;
        }
        .status {
            padding: 8px 16px;
            border-radius: 4px;
            display: inline-block;
            font-weight: 500;
            margin: 10px 0;
        }
        .status.ok {
            background: #d4edda;
            color: #155724;
        }
        .status.warning {
            background: #fff3cd;
            color: #856404;
        }
        .status.error {
            background: #f8d7da;
            color: #721c24;
        }
        .info-box {
            background: #e3f2fd;
            border-left: 4px solid #2196f3;
            padding: 15px;
            margin: 15px 0;
        }
        ul {
            line-height: 1.8;
        }
        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #e0e0e0;
            color: #7f8c8d;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 QR Code System Diagnostics & Tools</h1>
        
        <div class="info-box">
            <strong>Mục đích:</strong> Trang này giúp bạn kiểm tra và khắc phục các vấn đề liên quan đến tính năng tạo QR code khi xác nhận phiếu nhập hàng.
        </div>

        <h2>📋 Trạng Thái Hệ Thống</h2>
        <div class="card">
            <h3>PHP GD Extension</h3>
            <?php if (extension_loaded('gd')): ?>
                <div class="status ok">✓ Đã Kích Hoạt</div>
                <p>PHP GD extension đang hoạt động bình thường. QR code sẽ được tạo cực nhanh bằng thư viện local.</p>
            <?php else: ?>
                <div class="status warning">⚠ Chưa Kích Hoạt</div>
                <p>PHP GD extension chưa được bật. Hệ thống sẽ sử dụng external API để tạo QR code (cần internet).</p>
                <a href="enable_gd_extension.php" class="button warning">Bật GD Extension Tự Động</a>
            <?php endif; ?>
        </div>

        <div class="card">
            <h3>Thư Mục QR Code</h3>
            <?php 
            $qrDir = __DIR__ . '/../../public/uploads/qr/';
            $qrDirExists = is_dir($qrDir);
            $qrDirWritable = is_writable($qrDir);
            ?>
            <?php if ($qrDirExists && $qrDirWritable): ?>
                <div class="status ok">✓ Đã Sẵn Sàng</div>
                <p>Thư mục <code>public/uploads/qr/</code> tồn tại và có quyền ghi.</p>
                <?php
                $qrFiles = glob($qrDir . '*.png');
                $qrCount = count($qrFiles);
                echo "<p>Hiện có <strong>$qrCount</strong> file QR code đã được tạo.</p>";
                ?>
            <?php elseif ($qrDirExists && !$qrDirWritable): ?>
                <div class="status warning">⚠ Không Có Quyền Ghi</div>
                <p>Thư mục tồn tại nhưng không có quyền ghi. Cần cấp quyền write cho thư mục.</p>
            <?php else: ?>
                <div class="status error">✗ Chưa Tồn Tại</div>
                <p>Thư mục QR code chưa được tạo. Hệ thống sẽ tự động tạo khi tạo QR code lần đầu.</p>
            <?php endif; ?>
        </div>

        <div class="card">
            <h3>Database Connection</h3>
            <?php
            try {
                require_once __DIR__ . '/../../config/database.php';
                $database = new Database();
                $pdo = $database->getConnection();
                echo '<div class="status ok">✓ Kết Nối Thành Công</div>';
                echo '<p>Kết nối database hoạt động bình thường.</p>';
            } catch (Exception $e) {
                echo '<div class="status error">✗ Lỗi Kết Nối</div>';
                echo '<p>Không thể kết nối database: ' . htmlspecialchars($e->getMessage()) . '</p>';
            }
            ?>
        </div>

        <h2>🛠 Công Cụ Kiểm Tra</h2>
        
        <div class="card">
            <h3>1. Kiểm Tra GD Extension</h3>
            <p>Xem chi tiết thông tin về PHP GD extension và khả năng tạo hình ảnh.</p>
            <a href="check_gd_extension.php" class="button">Kiểm Tra GD Extension</a>
        </div>

        <div class="card">
            <h3>2. Test Tạo QR Code</h3>
            <p>Thử nghiệm tạo một QR code mẫu để kiểm tra tính năng hoạt động.</p>
            <a href="test_qr_generation.php" class="button">Test QR Generation</a>
        </div>

        <div class="card">
            <h3>3. Bật GD Extension</h3>
            <p>Tự động bật PHP GD extension trong file php.ini (khuyến nghị).</p>
            <a href="enable_gd_extension.php" class="button warning">Enable GD Extension</a>
        </div>

        <h2>📖 Tài Liệu & Hướng Dẫn</h2>
        
        <div class="card">
            <h3>Hướng Dẫn Khắc Phục Lỗi</h3>
            <p>Tài liệu chi tiết về các lỗi thường gặp và cách khắc phục.</p>
            <a href="HOTFIX_QR_CODE_GENERATION.md" class="button">Xem Tài Liệu</a>
        </div>

        <h2>🚀 Quay Lại Hệ Thống</h2>
        
        <div class="card">
            <h3>Quản Lý Phiếu Nhập</h3>
            <p>Quay lại trang quản lý phiếu nhập kho để tiếp tục công việc.</p>
            <a href="quan_ly_phieu_nhap_kho.php" class="button success">Stock Receipts Management</a>
        </div>

        <h2>📊 Thông Tin Hệ Thống</h2>
        
        <div class="card">
            <ul>
                <li><strong>PHP Version:</strong> <?php echo phpversion(); ?></li>
                <li><strong>Server:</strong> <?php echo $_SERVER['SERVER_SOFTWARE']; ?></li>
                <li><strong>Document Root:</strong> <?php echo $_SERVER['DOCUMENT_ROOT']; ?></li>
                <li><strong>Current Time:</strong> <?php echo date('Y-m-d H:i:s'); ?></li>
            </ul>
        </div>

        <h2>🔧 Troubleshooting Nhanh</h2>
        
        <div class="card">
            <h3>Vấn đề: QR Code Không Được Tạo</h3>
            <ol>
                <li>Kiểm tra GD extension đã bật chưa</li>
                <li>Kiểm tra quyền ghi thư mục <code>public/uploads/qr/</code></li>
                <li>Xem Apache error log: <code>C:\xampp\apache\logs\error.log</code></li>
                <li>Thử test tạo QR code thủ công</li>
            </ol>
        </div>

        <div class="card">
            <h3>Vấn đề: Phiếu Nhập Xác Nhận Nhưng Không Có QR</h3>
            <ol>
                <li>Kiểm tra database bảng <code>product_qr_codes</code></li>
                <li>Xem log file để tìm lỗi cụ thể</li>
                <li>Đảm bảo sản phẩm có <code>product_id</code> và <code>variant_id</code> hợp lệ</li>
            </ol>
        </div>

        <div class="footer">
            <p>QR Code System Diagnostics Tool v1.0</p>
            <p>Last Updated: 27/11/2025</p>
        </div>
    </div>
</body>
</html>
