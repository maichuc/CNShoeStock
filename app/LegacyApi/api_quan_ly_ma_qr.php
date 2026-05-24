<?php
// Suppress all PHP errors and warnings for clean JSON output
error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');

// session_start();
ob_start();

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../app/Classes/QuanLyMaQR.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Chưa đăng nhập']);
    exit();
}

$database = new Database();
$pdo = $database->getConnection();
$qrManager = new QRCodeManager($pdo);

$userId = $_SESSION['user_id'];

// Lấy dữ liệu POST
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

try {
    switch ($action) {
        case 'generate':
            echo json_encode(generateQR($qrManager, $input, $userId));
            break;
            
        case 'reprint':
            echo json_encode(reprintQR($qrManager, $input));
            break;
            
        case 'get_product_qrs':
            echo json_encode(getProductQRs($qrManager, $input));
            break;
            
        case 'deactivate':
            echo json_encode(deactivateQR($qrManager, $input));
            break;
            
        case 'scan_qr':
            echo json_encode(scanQR($qrManager, $input));
            break;
            
        case 'generate_receipt_qrs':
            echo json_encode(generateReceiptQRs($qrManager, $pdo, $input, $userId));
            break;
            
        case 'get_statistics':
            echo json_encode(getQRStatistics($pdo));
            break;
            
        case 'get_inventory_history':
            echo json_encode(getInventoryHistory($pdo, $input));
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Action không hợp lệ']);
    }
} catch (Exception $e) {
    error_log("QR API Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Có lỗi xảy ra: ' . $e->getMessage()]);
}

/**
 * Tạo QR code mới
 */
function generateQR($qrManager, $data, $userId) {
    try {
        $productId = $data['product_id'] ?? null;
        $variantId = $data['variant_id'] ?? null;
        
        if (!$productId) {
            return ['success' => false, 'message' => 'Thiếu product_id'];
        }
        
        $result = $qrManager->generateQRCode($productId, $variantId, $userId);
        
        if ($result) {
            return [
                'success' => true,
                'message' => 'Tạo QR code thành công',
                'qr_data' => $result
            ];
        } else {
            return ['success' => false, 'message' => 'Không thể tạo QR code'];
        }
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Lỗi: ' . $e->getMessage()];
    }
}

/**
 * In lại QR code
 */
function reprintQR($qrManager, $data) {
    global $pdo;
    try {
        $qrCode = $data['qr_code'] ?? null;
        
        if (!$qrCode) {
            return ['success' => false, 'message' => 'Thiếu mã QR'];
        }
        
        $qrData = $qrManager->getProductFromQR($qrCode);
        
        if ($qrData) {
            // Bổ sung thông tin ngày nhập gần nhất + vị trí lưu trữ nếu cần
            if (empty($qrData['last_receipt_date']) && !empty($qrData['variant_id'])) {
                $latest = getLatestReceiptForVariant($pdo, $qrData['variant_id']);
                if ($latest) {
                    $qrData['last_receipt_date'] = $latest['created_at'];
                    // nếu muốn hiển thị mã phiếu nhập cũ thì giữ receipt_code, nhưng theo yêu cầu đổi thành vị trí lưu trữ
                    $qrData['location_code'] = $latest['location_code'] ?? ($qrData['location_code'] ?? null);
                }
            }
            
            // Tạo URL để in QR
            $printUrl = createQRPrintPage($qrData);
            
            return [
                'success' => true,
                'message' => 'Sẵn sàng in QR code',
                'print_url' => $printUrl,
                'qr_data' => $qrData
            ];
        } else {
            return ['success' => false, 'message' => 'Không tìm thấy QR code'];
        }
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Lỗi: ' . $e->getMessage()];
    }
}

/**
 * Lấy danh sách QR của sản phẩm
 */
function getProductQRs($qrManager, $data) {
    try {
        $productId = $data['product_id'] ?? null;
        
        if (!$productId) {
            return ['success' => false, 'message' => 'Thiếu product_id'];
        }
        
        $qrCodes = $qrManager->getProductQRCodes($productId);
        
        return [
            'success' => true,
            'qr_codes' => $qrCodes
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Lỗi: ' . $e->getMessage()];
    }
}

/**
 * Vô hiệu hóa QR code
 */
function deactivateQR($qrManager, $data) {
    try {
        $qrId = $data['qr_id'] ?? null;
        
        if (!$qrId) {
            return ['success' => false, 'message' => 'Thiếu qr_id'];
        }
        
        $result = $qrManager->deactivateQRCode($qrId);
        
        if ($result) {
            return [
                'success' => true,
                'message' => 'Đã vô hiệu hóa QR code'
            ];
        } else {
            return ['success' => false, 'message' => 'Không thể vô hiệu hóa QR code'];
        }
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Lỗi: ' . $e->getMessage()];
    }
}

/**
 * Enhanced scan QR code with comprehensive debugging and case-insensitive DB mapping
 */
function scanQR($qrManager, $data) {
    try {
        $qrCode = $data['qr_code'] ?? null;
        $debug = $data['debug'] ?? false;
        
        // Debug logging if requested
        if ($debug) {
            error_log("=== QR SCAN API DEBUG SESSION START ===");
            error_log("Raw QR Code: " . $qrCode);
            error_log("QR Length: " . strlen($qrCode));
            error_log("QR Type: " . gettype($qrCode));
        }
        
        if (!$qrCode) {
            if ($debug) {
                error_log("Error: Missing QR code");
            }
            return ['success' => false, 'message' => 'Thiếu mã QR'];
        }
        
        // Clean and normalize QR code for better matching
        $originalQR = $qrCode;
        $qrCode = trim($qrCode); // Remove whitespace
        $qrCodeLower = strtolower($qrCode); // lower for comparisons
        
        if ($debug) {
            error_log("Cleaned QR Code: " . $qrCodeLower);
            $isUUID = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $qrCode);
            error_log("Is UUID format: " . ($isUUID ? 'YES' : 'NO'));
        }
        
        // Try multiple search strategies for better compatibility
        $productData = null;
        $searchMethods = [
            'original' => $originalQR,
            'cleaned_lower' => $qrCodeLower,
            'uppercase' => strtoupper($qrCodeLower),
            'trimmed' => trim($originalQR)
        ];
        
        foreach ($searchMethods as $method => $searchQR) {
            if ($debug) {
                error_log("Trying search method '$method' with QR: '$searchQR'");
            }
            
            $productData = $qrManager->getProductFromQR($searchQR);
            
            if ($productData) {
                if ($debug) {
                    error_log("Found product using method: $method");
                    error_log("Product data: " . json_encode($productData));
                }
                break;
            } else {
                if ($debug) {
                    error_log("No product found with method: $method");
                }
            }
        }
        
        if ($productData) {
            // If productData lacks last_receipt_date or location_code, try to fetch latest by variant_id
            global $pdo;
            if (empty($productData['last_receipt_date']) && !empty($productData['variant_id'])) {
                $latest = getLatestReceiptForVariant($pdo, $productData['variant_id']);
                if ($latest) {
                    $productData['last_receipt_date'] = $latest['created_at'];
                    $productData['location_code'] = $latest['location_code'] ?? ($productData['location_code'] ?? null);
                }
            }
            
            if ($debug) {
                error_log("SUCCESS: Product found!");
                error_log("=== QR SCAN API DEBUG SESSION SUCCESS ===");
            }
            
            return [
                'success' => true,
                'message' => 'Tìm thấy sản phẩm',
                'product' => $productData,
                'debug_info' => $debug ? [
                    'original_qr' => $originalQR,
                    'cleaned_qr' => $qrCodeLower,
                    'search_methods_tried' => array_keys($searchMethods),
                    'successful_method' => $method ?? 'unknown'
                ] : null
            ];
        } else {
            if ($debug) {
                error_log("FAILED: No product found with any method");
                error_log("Suggestions: Check if QR exists in database; verify QR format; test QR with phone scanner");
                error_log("=== QR SCAN API DEBUG SESSION FAILED ===");
            }
            
            $errorMessage = 'Không tìm thấy sản phẩm với QR code này';
            
            if ($debug) {
                $errorMessage .= "\n\nDebug Info:\n";
                $errorMessage .= "• Original QR: $originalQR\n";
                $errorMessage .= "• Cleaned QR: $qrCodeLower\n";
                $errorMessage .= "• Methods tried: " . implode(', ', array_keys($searchMethods)) . "\n";
                $errorMessage .= "• Suggestion: Test QR with phone first (Zalo/Google Lens)";
            }
            
            return [
                'success' => false, 
                'message' => $errorMessage,
                'debug_info' => $debug ? [
                    'original_qr' => $originalQR,
                    'cleaned_qr' => $qrCodeLower,
                    'search_methods_tried' => array_keys($searchMethods),
                    'all_failed' => true
                ] : null
            ];
        }
        
    } catch (Exception $e) {
        if ($debug ?? false) {
            error_log("EXCEPTION in QR scan: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
        }
        
        return [
            'success' => false, 
            'message' => 'Lỗi: ' . $e->getMessage(),
            'debug_info' => ($debug ?? false) ? [
                'exception' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ] : null
        ];
    }
}

/**
 * Tạo trang in QR code
 */
function createQRPrintPage($qrData) {
    $printPageContent = generateQRPrintHTML($qrData);
    
    // Tạo file tạm thời để in (đường dẫn tuyệt đối)
    $tempDir = dirname(__FILE__) . '/temp';
    if (!file_exists($tempDir)) {
        mkdir($tempDir, 0777, true);
    }
    
    $safeCode = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $qrData['qr_code']);
    $tempFile = $tempDir . '/qr_print_' . $safeCode . '_' . time() . '.html';
    
    file_put_contents($tempFile, $printPageContent);
    
    return $tempFile;
}

/**
 * Generate HTML cho trang in QR
 */
function generateQRPrintHTML($qrData) {
    // Nếu có last_receipt_date, dùng làm "Ngày nhập gần nhất", ngược lại dùng created_at
    $dateField = $qrData['last_receipt_date'] ?? $qrData['created_at'] ?? null;
    $dateDisplay = $dateField ? date('d/m/Y H:i', strtotime($dateField)) : '-';
    $location = $qrData['location_code'] ?? '-';
    $productName = htmlspecialchars($qrData['product_name'] ?? '-', ENT_QUOTES, 'UTF-8');
    $variantName = htmlspecialchars($qrData['variant_name'] ?? '', ENT_QUOTES, 'UTF-8');
    $qrCode = htmlspecialchars($qrData['qr_code'] ?? '', ENT_QUOTES, 'UTF-8');
    $qrImageUrl = "https://chart.googleapis.com/chart?chs=200x200&cht=qr&chl=" . urlencode($qrCode);
    
    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='utf-8'>
        <title>In QR Code - {$qrCode}</title>
        <style>
            body { font-family: Arial, sans-serif; text-align: center; margin: 20px; }
            .qr-container { border: 2px solid #000; padding: 20px; display: inline-block; margin: 10px; }
            .qr-code { margin: 10px 0; }
            .product-info { margin-top: 10px; font-size: 14px; text-align: left; }
            .product-info strong { display: inline-block; width: 140px; }
            @media print {
                body { margin: 0; }
                .no-print { display: none; }
            }
        </style>
    </head>
    <body>
        <div class='qr-container'>
            <h3>{$productName}</h3>
            <div class='qr-code'>
                <img src='{$qrImageUrl}' alt='QR Code'>
            </div>
            <div class='product-info'>
                <div><strong>Mã QR:</strong> {$qrCode}</div>
                " . ($variantName ? "<div><strong>Biến thể:</strong> {$variantName}</div>" : "") . "
                <div><strong>Vị trí lưu trữ:</strong> {$location}</div>
                <div><strong>Ngày nhập gần nhất:</strong> {$dateDisplay}</div>
            </div>
        </div>
        
        <div class='no-print' style='margin-top: 20px; text-align:center;'>
            <button onclick='window.print()' style='padding: 10px 20px; font-size: 16px;'>In QR Code</button>
            <button onclick='window.close()' style='padding: 10px 20px; font-size: 16px; margin-left: 10px;'>Đóng</button>
        </div>
        
        <script>
            // Auto print khi trang load xong
            window.onload = function() {
                setTimeout(function() {
                    window.print();
                }, 500);
            };
        </script>
    </body>
    </html>
    ";
}

/**
 * Tạo QR codes cho tất cả sản phẩm trong phiếu nhập
 */
function generateReceiptQRs($qrManager, $pdo, $data, $userId) {
    try {
        $receiptId = $data['receipt_id'] ?? null;
        
        if (!$receiptId) {
            return ['success' => false, 'message' => 'Thiếu receipt_id'];
        }
        
        // Lấy danh sách sản phẩm trong phiếu nhập
        $sql = "SELECT DISTINCT sri.product_id, sri.variant_id
                FROM stock_receipt_items sri
                INNER JOIN stock_receipts sr ON sri.receipt_id = sr.receipt_id
                WHERE sri.receipt_id = ? AND sr.status = 'confirmed'";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$receiptId]);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($products)) {
            return ['success' => false, 'message' => 'Không tìm thấy sản phẩm trong phiếu nhập hoặc phiếu chưa được xác nhận'];
        }
        
        $createdCount = 0;
        $errors = [];
        
        foreach ($products as $product) {
            try {
                $result = $qrManager->generateQRCode($product['product_id'], $product['variant_id'], $userId);
                if ($result) {
                    $createdCount++;
                } else {
                    $errors[] = "Không thể tạo QR cho sản phẩm ID: {$product['product_id']}";
                }
            } catch (Exception $e) {
                $errors[] = "Lỗi tạo QR cho sản phẩm ID {$product['product_id']}: " . $e->getMessage();
            }
        }
        
        $message = "Đã tạo {$createdCount} QR codes";
        if (!empty($errors)) {
            $message .= ". Có " . count($errors) . " lỗi.";
        }
        
        return [
            'success' => true,
            'message' => $message,
            'created_count' => $createdCount,
            'errors' => $errors
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Lỗi: ' . $e->getMessage()];
    }
}

/**
 * Lấy thống kê QR codes
 */
function getQRStatistics($pdo) {
    try {
        $stats = [];
        
        // Tổng số QR codes
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM product_qr_codes");
        $stmt->execute();
        $stats['total'] = $stmt->fetchColumn();
        
        // QR codes đang hoạt động
        $stmt = $pdo->prepare("SELECT COUNT(*) as active FROM product_qr_codes WHERE is_active = 1");
        $stmt->execute();
        $stats['active'] = $stmt->fetchColumn();
        
        // QR codes vô hiệu hóa
        $stmt = $pdo->prepare("SELECT COUNT(*) as inactive FROM product_qr_codes WHERE is_active = 0");
        $stmt->execute();
        $stats['inactive'] = $stmt->fetchColumn();
        
        // QR codes tạo hôm nay
        $stmt = $pdo->prepare("SELECT COUNT(*) as today FROM product_qr_codes WHERE DATE(created_at) = CURDATE()");
        $stmt->execute();
        $stats['today'] = $stmt->fetchColumn();
        
        // QR codes tạo tuần này
        $stmt = $pdo->prepare("SELECT COUNT(*) as this_week FROM product_qr_codes WHERE YEARWEEK(created_at) = YEARWEEK(NOW())");
        $stmt->execute();
        $stats['this_week'] = $stmt->fetchColumn();
        
        // QR codes tạo tháng này
        $stmt = $pdo->prepare("SELECT COUNT(*) as this_month FROM product_qr_codes WHERE YEAR(created_at) = YEAR(NOW()) AND MONTH(created_at) = MONTH(NOW())");
        $stmt->execute();
        $stats['this_month'] = $stmt->fetchColumn();
        
        return [
            'success' => true,
            'stats' => $stats
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Lỗi: ' . $e->getMessage()];
    }
}

/**
 * Lấy lịch sử nhập kho của variant
 */
function getInventoryHistory($pdo, $data) {
    try {
        $variantId = $data['variant_id'] ?? null;
        
        if (!$variantId) {
            return ['success' => false, 'message' => 'Thiếu variant_id'];
        }
        
        $stmt = $pdo->prepare("
            SELECT 
                sr.receipt_code,
                sr.created_at,
                sri.quantity,
                sri.unit_price,
                sri.location_code,
                s.name as supplier_name,
                s.supplier_code,
                u.username as created_by
            FROM stock_receipt_items sri
            LEFT JOIN stock_receipts sr ON sri.receipt_id = sr.receipt_id
            LEFT JOIN suppliers s ON sr.supplier_id = s.supplier_id
            LEFT JOIN users u ON sr.created_by = u.user_id
            WHERE sri.variant_id = ? AND sr.status = 'confirmed'
            ORDER BY sr.created_at DESC
        ");
        $stmt->execute([$variantId]);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'success' => true,
            'history' => $history
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Lỗi: ' . $e->getMessage()];
    }
}

/**
 * Lấy phiếu nhập gần nhất cho một variant (dùng để bổ sung ngày nhập gần nhất & vị trí)
 */
function getLatestReceiptForVariant($pdo, $variantId) {
    try {
        $sql = "
            SELECT sr.receipt_code, sr.created_at, sri.location_code
            FROM stock_receipt_items sri
            INNER JOIN stock_receipts sr ON sri.receipt_id = sr.receipt_id
            WHERE sri.variant_id = ? AND sr.status = 'confirmed'
            ORDER BY sr.created_at DESC
            LIMIT 1
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$variantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row : null;
    } catch (Exception $e) {
        error_log("Error getting latest receipt for variant {$variantId}: " . $e->getMessage());
        return null;
    }
}
?>
