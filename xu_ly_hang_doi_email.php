<?php
/**
 * Email Queue Processor
 * Chạy file này để xử lý email đang pending trong queue
 * 
 * Usage:
 *   php xu_ly_hang_doi_email.php
 * 
 * Cron Job (mỗi phút):
 *   * * * * * php /path/to/xu_ly_hang_doi_email.php
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/DichVuEmail.php';

$database = new Database();
$pdo = $database->getConnection();
$emailService = new EmailService($pdo);

echo "====================================\n";
echo "   EMAIL QUEUE PROCESSOR\n";
echo "====================================\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n\n";

// Xử lý hàng đợi email
$result = $emailService->processEmailQueue();

echo "Results:\n";
echo "--------\n";
echo "✓ Sent: {$result['sent']}\n";
echo "✗ Failed: {$result['failed']}\n";
echo "⏭ Skipped: {$result['skipped']}\n";
echo "Total processed: " . ($result['sent'] + $result['failed'] + $result['skipped']) . "\n";

if ($result['failed'] > 0) {
    echo "\n⚠️ Some emails failed. Check email_queue table for details.\n";
}

if ($result['sent'] > 0) {
    echo "\n🎉 {$result['sent']} emails sent successfully!\n";
}

if ($result['sent'] === 0 && $result['failed'] === 0 && $result['skipped'] === 0) {
    echo "\nℹ️ No pending emails in queue.\n";
}

echo "\nFinished at: " . date('Y-m-d H:i:s') . "\n";
echo "====================================\n";
?>
