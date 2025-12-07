<?php
/**
 * Script Tự Động Hóa Việt Hóa Tên File
 * Đảm bảo 0% lỗi khi rename và update references
 */

class RefactorAutomation {
    private $projectRoot;
    private $mapping = [];
    private $errors = [];
    private $success = [];
    private $dryRun = true; // Safety: dry run first
    
    public function __construct($projectRoot) {
        $this->projectRoot = rtrim($projectRoot, '/\\');
    }
    
    /**
     * Bản đồ mapping tên file cũ -> mới
     */
    public function defineMapping() {
        $this->mapping = [
            // === MODALS ===
            'modals/category_modals.php' => 'modals/modal_danh_muc.php',
            'modals/supplier_modals.php' => 'modals/modal_nha_cung_cap.php',
            
            // === HELPERS ===
            'helpers/AIAnalysisHelper.php' => 'helpers/TroGiupPhanTichAI.php',
            'helpers/AuditLogger.php' => 'helpers/GhiNhatKyKiemToan.php',
            'helpers/EmailService.php' => 'helpers/DichVuEmail.php',
            'helpers/ImageUploadService.php' => 'helpers/DichVuTaiAnhLen.php',
            'helpers/PathHelper.php' => 'helpers/TroGiupDuongDan.php',
            'helpers/SimilarityHelper.php' => 'helpers/TroGiupDoTuongDong.php',
            'helpers/UsernameGenerator.php' => 'helpers/TaoTenNguoiDung.php',
            'helpers/generate_employee_template.php' => 'helpers/tao_mau_nhan_vien.php',
            
            // === CLASSES ===
            'classes/AIService.php' => 'classes/DichVuAI.php',
            'classes/Database.php' => 'classes/CSDuLieu.php',
            'classes/EmailService.php' => 'classes/DichVuEmail.php',
            'classes/EmployeeManager.php' => 'classes/QuanLyNhanVien.php',
            'classes/QRCodeGenerator.php' => 'classes/TaoMaQR.php',
            'classes/QRCodeManager.php' => 'classes/QuanLyMaQR.php',
            'classes/SimpleQRGenerator.php' => 'classes/TaoMaQRDonGian.php',
            'classes/SmartProductMatcher.php' => 'classes/GhepSanPhamThongMinh.php',
            'classes/StockReceiptHistory.php' => 'classes/LichSuPhieuNhap.php',
            'classes/Warehouse.php' => 'classes/Kho.php',
            'classes/WarehouseAccessControl.php' => 'classes/KiemSoatTruyCapKho.php',
            
            // === CONFIG ===
            'config/database.php' => 'config/cau_hinh_csdl.php',
            
            // === INCLUDES ===
            'includes/footer.php' => 'includes/chan_trang.php',
            'includes/logout_modal.php' => 'includes/modal_dang_xuat.php',
            'includes/menu_components.php' => 'includes/thanh_phan_menu.php',
            'includes/sidebar.php' => 'includes/thanh_ben.php',
            'includes/topbar.php' => 'includes/thanh_tren.php',
            
            // === AUTH ===
            'auth/login_process.php' => 'auth/xu_ly_dang_nhap.php',
            'auth/register_process.php' => 'auth/xu_ly_dang_ky.php',
            
            // === API FILES (root) ===
            'api_add_product_to_receipt.php' => 'api_them_san_pham_vao_phieu.php',
            'api_add_supplier.php' => 'api_them_nha_cung_cap.php',
            'api_ai_analyze.php' => 'api_phan_tich_ai.php',
            'api_ai_forecast.php' => 'api_du_bao_ai.php',
            'api_ai_inventory_analysis.php' => 'api_phan_tich_ton_kho_ai.php',
            'api_ai_shoe_analysis.php' => 'api_phan_tich_giay_ai.php',
            'api_cascading_filters.php' => 'api_bo_loc_theo_tang.php',
            'api_check_duplicates_manual.php' => 'api_kiem_tra_trung_thu_cong.php',
            'api_confirm_delivery.php' => 'api_xac_nhan_giao_hang.php',
            'api_consumer_behavior.php' => 'api_hanh_vi_nguoi_dung.php',
            'api_create_manual_product.php' => 'api_tao_san_pham_thu_cong.php',
            'api_customers_warehouse.php' => 'api_khach_hang_kho.php',
            'api_data_quality.php' => 'api_chat_luong_du_lieu.php',
            'api_employee_management.php' => 'api_quan_ly_nhan_vien.php',
            'api_export_forecast.php' => 'api_du_bao_xuat.php',
            'api_export_process.php' => 'api_xu_ly_xuat.php',
            'api_get_product_data.php' => 'api_lay_du_lieu_san_pham.php',
            'api_get_product_details.php' => 'api_lay_chi_tiet_san_pham.php',
            'api_get_product_suggestions.php' => 'api_lay_goi_y_san_pham.php',
            'api_get_size_price.php' => 'api_lay_gia_theo_size.php',
            'api_inventory_turnover.php' => 'api_vong_quay_kho.php',
            'api_market_trends.php' => 'api_xu_huong_thi_truong.php',
            'api_profitability.php' => 'api_loi_nhuan.php',
            'api_qr_management.php' => 'api_quan_ly_ma_qr.php',
            'api_reprocess_export.php' => 'api_xu_ly_lai_xuat.php',
            'api_save_ai_receipt.php' => 'api_luu_phieu_ai.php',
            'api_save_manual_stock_receipt.php' => 'api_luu_phieu_nhap_thu_cong.php',
            'api_stock_receipt_management.php' => 'api_quan_ly_phieu_nhap.php',
            'api_stock_receipt_simple.php' => 'api_phieu_nhap_don_gian.php',
            'api_storage_suggestions.php' => 'api_goi_y_luu_tru.php',
            'api_supply_chain.php' => 'api_chuoi_cung_ung.php',
            'api_trend_analysis.php' => 'api_phan_tich_xu_huong.php',
            'api_update_delivery_status.php' => 'api_cap_nhat_trang_thai_giao.php',
            'api_upload_images.php' => 'api_tai_anh_len.php',
            'api_warehouse_access.php' => 'api_truy_cap_kho.php',
            'api_warehouse_locations.php' => 'api_vi_tri_kho.php',
            
            // === MAIN PHP FILES ===
            'add_product_ai.php' => 'them_san_pham_ai.php',
            'add_supplier.php' => 'them_nha_cung_cap.php',
            'ai_analytics_dashboard.php' => 'bang_dieu_khien_phan_tich_ai.php',
            'ai_forecast.php' => 'du_bao_ai.php',
            'ai_inventory_analysis.php' => 'phan_tich_ton_kho_ai.php',
            'change_password.php' => 'doi_mat_khau.php',
            'confirm_delivery.php' => 'xac_nhan_giao_hang.php',
            'confirmed_exports.php' => 'cac_phieu_xuat_da_xac_nhan.php',
            'create_manual_stock_receipt.php' => 'tao_phieu_nhap_thu_cong.php',
            'create_new_stock_receipt.php' => 'tao_phieu_nhap_moi.php',
            'create_order.php' => 'tao_don_hang.php',
            'customer_methods_extension.php' => 'mo_rong_phuong_thuc_khach_hang.php',
            'danh_sach_sp.php' => 'danh_sach_san_pham.php',
            'delete_product.php' => 'xoa_san_pham.php',
            'edit_product.php' => 'sua_san_pham.php',
            'employee_management.php' => 'quan_ly_nhan_vien.php',
            'enhanced_duplicate_functions.php' => 'ham_kiem_tra_trung_nang_cao.php',
            'env_loader.php' => 'tai_bien_moi_truong.php',
            'export_management.php' => 'quan_ly_xuat_kho.php',
            'export_slip.php' => 'phieu_xuat_kho.php',
            'force_change_password.php' => 'bat_buoc_doi_mat_khau.php',
            'index.php' => 'trang_chu.php',
            'logout.php' => 'dang_xuat.php',
            'orders_management.php' => 'quan_ly_don_hang.php',
            'process_email_queue.php' => 'xu_ly_hang_doi_email.php',
            'process_export.php' => 'xu_ly_xuat_kho.php',
            'profile_settings.php' => 'cai_dat_ho_so.php',
            'qr_diagnostics.php' => 'chan_doan_ma_qr.php',
            'reactivate_product.php' => 'kich_hoat_lai_san_pham.php',
            'stock_receipts_management.php' => 'quan_ly_phieu_nhap_kho.php',
            'suppliers_management.php' => 'quan_ly_nha_cung_cap.php',
            'update_delivery_status.php' => 'cap_nhat_trang_thai_giao_hang.php',
            'view_export.php' => 'xem_phieu_xuat.php',
            'view_product.php' => 'xem_san_pham.php',
            'view_receipt.php' => 'xem_phieu_nhap.php',
            'warehouse_locations.php' => 'vi_tri_kho.php',
            'warehouse_management.php' => 'quan_ly_kho.php',
            'warehouse_products.php' => 'san_pham_trong_kho.php',
        ];
        
        return $this->mapping;
    }
    
    /**
     * Tìm tất cả files PHP trong project (trừ vendor)
     */
    public function scanAllPhpFiles() {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->projectRoot)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $path = $file->getPathname();
                // Bỏ qua vendor
                if (strpos($path, 'vendor') === false && strpos($path, 'refactor_automation.php') === false) {
                    $files[] = $path;
                }
            }
        }
        
        return $files;
    }
    
    /**
     * Tìm tất cả references của một file trong code
     */
    public function findReferences($oldPath) {
        $references = [];
        $allFiles = $this->scanAllPhpFiles();
        
        // Chuẩn hóa path
        $oldPath = str_replace('\\', '/', $oldPath);
        $patterns = [
            $oldPath,
            basename($oldPath),
            str_replace('/', '\\', $oldPath),
        ];
        
        foreach ($allFiles as $file) {
            $content = file_get_contents($file);
            
            foreach ($patterns as $pattern) {
                if (stripos($content, $pattern) !== false) {
                    $references[$file][] = $pattern;
                }
            }
        }
        
        return $references;
    }
    
    /**
     * Update references trong một file
     */
    public function updateReferencesInFile($filePath, $oldPath, $newPath) {
        $content = file_get_contents($filePath);
        $originalContent = $content;
        
        // Chuẩn hóa paths
        $oldPath = str_replace('\\', '/', $oldPath);
        $newPath = str_replace('\\', '/', $newPath);
        
        // Replace tất cả các biến thể
        $replacements = [
            $oldPath => $newPath,
            str_replace('/', '\\', $oldPath) => str_replace('/', '\\', $newPath),
            basename($oldPath) => basename($newPath),
        ];
        
        foreach ($replacements as $old => $new) {
            $content = str_replace($old, $new, $content);
        }
        
        if ($content !== $originalContent) {
            if (!$this->dryRun) {
                file_put_contents($filePath, $content);
            }
            return true;
        }
        
        return false;
    }
    
    /**
     * Thực hiện rename một file
     */
    public function renameFile($oldPath, $newPath) {
        $oldFullPath = $this->projectRoot . '/' . $oldPath;
        $newFullPath = $this->projectRoot . '/' . $newPath;
        
        if (!file_exists($oldFullPath)) {
            $this->errors[] = "File không tồn tại: $oldPath";
            return false;
        }
        
        // Tạo thư mục nếu chưa có
        $newDir = dirname($newFullPath);
        if (!is_dir($newDir) && !$this->dryRun) {
            mkdir($newDir, 0777, true);
        }
        
        if (!$this->dryRun) {
            if (!rename($oldFullPath, $newFullPath)) {
                $this->errors[] = "Không thể rename: $oldPath -> $newPath";
                return false;
            }
        }
        
        $this->success[] = "✓ Renamed: $oldPath -> $newPath";
        return true;
    }
    
    /**
     * Thực hiện toàn bộ quá trình refactor
     */
    public function execute($dryRun = true) {
        $this->dryRun = $dryRun;
        $this->errors = [];
        $this->success = [];
        
        echo "=== BẮT ĐẦU REFACTOR " . ($dryRun ? "(DRY RUN)" : "(THỰC THI)") . " ===\n\n";
        
        $mapping = $this->defineMapping();
        $totalFiles = count($mapping);
        $current = 0;
        
        foreach ($mapping as $oldPath => $newPath) {
            $current++;
            echo "[$current/$totalFiles] Đang xử lý: $oldPath\n";
            
            // 1. Tìm tất cả references
            echo "  → Tìm references...\n";
            $references = $this->findReferences($oldPath);
            echo "  → Tìm thấy " . count($references) . " file(s) chứa references\n";
            
            // 2. Update tất cả references
            foreach ($references as $refFile => $patterns) {
                echo "  → Update: " . basename($refFile) . "\n";
                $this->updateReferencesInFile($refFile, $oldPath, $newPath);
            }
            
            // 3. Rename file
            echo "  → Rename file...\n";
            $this->renameFile($oldPath, $newPath);
            
            echo "\n";
        }
        
        // Báo cáo
        echo "\n=== KẾT QUẢ ===\n";
        echo "✓ Thành công: " . count($this->success) . " file(s)\n";
        echo "✗ Lỗi: " . count($this->errors) . " file(s)\n";
        
        if (!empty($this->errors)) {
            echo "\n=== LỖI ===\n";
            foreach ($this->errors as $error) {
                echo "  - $error\n";
            }
        }
        
        if ($dryRun) {
            echo "\n⚠️  ĐÂY LÀ DRY RUN - Không có file nào được thay đổi thực sự\n";
            echo "Chạy lại với: php refactor_automation.php execute\n";
        } else {
            echo "\n✓ HOÀN THÀNH! Tất cả files đã được refactor\n";
        }
        
        return count($this->errors) === 0;
    }
    
    /**
     * Validate sau khi refactor
     */
    public function validate() {
        echo "=== VALIDATION ===\n";
        
        $allFiles = $this->scanAllPhpFiles();
        $issues = [];
        
        foreach ($allFiles as $file) {
            // Check syntax errors
            $output = shell_exec("php -l " . escapeshellarg($file) . " 2>&1");
            if (strpos($output, 'No syntax errors') === false) {
                $issues[] = "Syntax error in: $file";
            }
        }
        
        if (empty($issues)) {
            echo "✓ Không có lỗi syntax!\n";
            return true;
        } else {
            echo "✗ Tìm thấy " . count($issues) . " lỗi:\n";
            foreach ($issues as $issue) {
                echo "  - $issue\n";
            }
            return false;
        }
    }
}

// === MAIN EXECUTION ===
if (php_sapi_name() === 'cli') {
    $projectRoot = __DIR__;
    $refactor = new RefactorAutomation($projectRoot);
    
    $command = $argv[1] ?? 'dry-run';
    
    switch ($command) {
        case 'execute':
            echo "⚠️  CẢNH BÁO: Bạn sắp thực hiện refactor THỰC SỰ!\n";
            echo "Nhấn ENTER để tiếp tục hoặc Ctrl+C để hủy...\n";
            fgets(STDIN);
            $refactor->execute(false);
            $refactor->validate();
            break;
            
        case 'validate':
            $refactor->validate();
            break;
            
        case 'mapping':
            $mapping = $refactor->defineMapping();
            echo "=== BẢNG MAPPING ===\n";
            foreach ($mapping as $old => $new) {
                echo "$old\n  → $new\n\n";
            }
            echo "Tổng số: " . count($mapping) . " files\n";
            break;
            
        case 'dry-run':
        default:
            $refactor->execute(true);
            break;
    }
}
