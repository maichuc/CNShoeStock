# PHÂN TÍCH DEPENDENCIES VÀ ĐỀ XUẤT RENAME PROJECT

## 📊 TỔNG QUAN PROJECT

**Ngày phân tích:** 7/12/2025  
**Tổng số file PHP:** 125+ files (không bao gồm vendor/)

### Cấu trúc thư mục:
- **Root:** 70+ PHP files
- **api/:** 14 files
- **auth/:** 4 files
- **classes/:** 11 files
- **config/:** 2 files
- **helpers/:** 8 files
- **includes/:** 5 files
- **modals/:** 2 files

---

## 🗺️ BẢNG MAPPING TÊN MỚI (125 FILES)

### A. ROOT DIRECTORY (70 files)

| STT | Tên hiện tại | Tên đề xuất | Loại |
|-----|--------------|-------------|------|
| 1 | `index.php` | `trang_chu.php` | Trang |
| 2 | `login.html` | `dang_nhap.html` | Trang |
| 3 | `register.html` | `dang_ky.html` | Trang |
| 4 | `logout.php` | `dang_xuat.php` | Action |
| 5 | `change_password.php` | `doi_mat_khau.php` | Trang |
| 6 | `force_change_password.php` | `bat_buoc_doi_mat_khau.php` | Trang |
| 7 | `profile_settings.php` | `cai_dat_ca_nhan.php` | Trang |
| 8 | `danh_sach_sp.php` | `danh_sach_san_pham.php` | Trang |
| 9 | `view_product.php` | `xem_san_pham.php` | Trang |
| 10 | `edit_product.php` | `sua_san_pham.php` | Trang |
| 11 | `delete_product.php` | `xoa_san_pham.php` | Action |
| 12 | `reactivate_product.php` | `kich_hoat_lai_san_pham.php` | Action |
| 13 | `add_product_ai.php` | `them_san_pham_ai.php` | Trang |
| 14 | `warehouse_management.php` | `quan_ly_kho.php` | Trang |
| 15 | `warehouse_products.php` | `san_pham_kho.php` | Trang |
| 16 | `warehouse_locations.php` | `vi_tri_kho.php` | Trang |
| 17 | `stock_receipts_management.php` | `quan_ly_phieu_nhap.php` | Trang |
| 18 | `create_new_stock_receipt.php` | `tao_phieu_nhap_moi.php` | Trang |
| 19 | `create_manual_stock_receipt.php` | `tao_phieu_nhap_thu_cong.php` | Trang |
| 20 | `view_receipt.php` | `xem_phieu_nhap.php` | Trang |
| 21 | `export_management.php` | `quan_ly_xuat_kho.php` | Trang |
| 22 | `process_export.php` | `xu_ly_xuat_kho.php` | Trang |
| 23 | `export_slip.php` | `phieu_xuat_kho.php` | Trang |
| 24 | `view_export.php` | `xem_xuat_kho.php` | Trang |
| 25 | `confirmed_exports.php` | `xuat_kho_da_xac_nhan.php` | Trang |
| 26 | `confirm_delivery.php` | `xac_nhan_giao_hang.php` | Trang |
| 27 | `update_delivery_status.php` | `cap_nhat_trang_thai_giao_hang.php` | Trang |
| 28 | `orders_management.php` | `quan_ly_don_hang.php` | Trang |
| 29 | `create_order.php` | `tao_don_hang.php` | Trang |
| 30 | `suppliers_management.php` | `quan_ly_nha_cung_cap.php` | Trang |
| 31 | `add_supplier.php` | `them_nha_cung_cap.php` | Trang |
| 32 | `employee_management.php` | `quan_ly_nhan_vien.php` | Trang |
| 33 | `ai_analytics_dashboard.php` | `bang_dieu_khien_phan_tich_ai.php` | Trang |
| 34 | `ai_forecast.php` | `du_bao_ai.php` | Trang |
| 35 | `ai_inventory_analysis.php` | `phan_tich_ton_kho_ai.php` | Trang |
| 36 | `qr_diagnostics.php` | `chan_doan_ma_qr.php` | Trang |
| 37 | `process_email_queue.php` | `xu_ly_hang_doi_email.php` | Script |
| 38 | `customer_methods_extension.php` | `mo_rong_phuong_thuc_khach_hang.php` | Helper |
| 39 | `enhanced_duplicate_functions.php` | `ham_kiem_tra_trung_lap_nang_cao.php` | Helper |
| 40 | `env_loader.php` | `tai_bien_moi_truong.php` | Config |

### B. API ROOT FILES (30 files)

| STT | Tên hiện tại | Tên đề xuất | Endpoint |
|-----|--------------|-------------|----------|
| 41 | `api_ai_analyze.php` | `api_phan_tich_ai.php` | API |
| 42 | `api_ai_forecast.php` | `api_du_bao_ai.php` | API |
| 43 | `api_ai_inventory_analysis.php` | `api_phan_tich_ton_kho_ai.php` | API |
| 44 | `api_ai_shoe_analysis.php` | `api_phan_tich_giay_ai.php` | API |
| 45 | `api_add_product_to_receipt.php` | `api_them_san_pham_vao_phieu.php` | API |
| 46 | `api_add_supplier.php` | `api_them_nha_cung_cap.php` | API |
| 47 | `api_cascading_filters.php` | `api_bo_loc_lien_tiep.php` | API |
| 48 | `api_check_duplicates_manual.php` | `api_kiem_tra_trung_lap_thu_cong.php` | API |
| 49 | `api_confirm_delivery.php` | `api_xac_nhan_giao_hang.php` | API |
| 50 | `api_consumer_behavior.php` | `api_hanh_vi_nguoi_tieu_dung.php` | API |
| 51 | `api_create_manual_product.php` | `api_tao_san_pham_thu_cong.php` | API |
| 52 | `api_customers_warehouse.php` | `api_kho_khach_hang.php` | API |
| 53 | `api_data_quality.php` | `api_chat_luong_du_lieu.php` | API |
| 54 | `api_employee_management.php` | `api_quan_ly_nhan_vien.php` | API |
| 55 | `api_export_forecast.php` | `api_xuat_du_bao.php` | API |
| 56 | `api_export_process.php` | `api_xu_ly_xuat_kho.php` | API |
| 57 | `api_get_product_data.php` | `api_lay_du_lieu_san_pham.php` | API |
| 58 | `api_get_product_details.php` | `api_lay_chi_tiet_san_pham.php` | API |
| 59 | `api_get_product_suggestions.php` | `api_lay_goi_y_san_pham.php` | API |
| 60 | `api_get_size_price.php` | `api_lay_gia_theo_size.php` | API |
| 61 | `api_inventory_turnover.php` | `api_vong_quay_hang_ton.php` | API |
| 62 | `api_market_trends.php` | `api_xu_huong_thi_truong.php` | API |
| 63 | `api_profitability.php` | `api_loi_nhuan.php` | API |
| 64 | `api_qr_management.php` | `api_quan_ly_ma_qr.php` | API |
| 65 | `api_reprocess_export.php` | `api_xu_ly_lai_xuat_kho.php` | API |
| 66 | `api_save_ai_receipt.php` | `api_luu_phieu_nhap_ai.php` | API |
| 67 | `api_save_manual_stock_receipt.php` | `api_luu_phieu_nhap_thu_cong.php` | API |
| 68 | `api_stock_receipt_management.php` | `api_quan_ly_phieu_nhap.php` | API |
| 69 | `api_stock_receipt_simple.php` | `api_phieu_nhap_don_gian.php` | API |
| 70 | `api_storage_suggestions.php` | `api_goi_y_luu_tru.php` | API |
| 71 | `api_supply_chain.php` | `api_chuoi_cung_ung.php` | API |
| 72 | `api_trend_analysis.php` | `api_phan_tich_xu_huong.php` | API |
| 73 | `api_update_delivery_status.php` | `api_cap_nhat_trang_thai_giao_hang.php` | API |
| 74 | `api_upload_images.php` | `api_tai_len_hinh_anh.php` | API |
| 75 | `api_warehouse_access.php` | `api_quyen_truy_cap_kho.php` | API |
| 76 | `api_warehouse_locations.php` | `api_vi_tri_kho.php` | API |

### C. API SUBFOLDER (/api/ - 14 files)

| STT | Tên hiện tại | Tên đề xuất |
|-----|--------------|-------------|
| 77 | `api/add_supplier.php` | `api/them_nha_cung_cap.php` |
| 78 | `api/confirm_order.php` | `api/xac_nhan_don_hang.php` |
| 79 | `api/create_order.php` | `api/tao_don_hang.php` |
| 80 | `api/delete_supplier.php` | `api/xoa_nha_cung_cap.php` |
| 81 | `api/get_order_detail.php` | `api/lay_chi_tiet_don_hang.php` |
| 82 | `api/get_order_details.php` | `api/lay_thong_tin_don_hang.php` |
| 83 | `api/get_orders.php` | `api/lay_danh_sach_don_hang.php` |
| 84 | `api/get_supplier.php` | `api/lay_nha_cung_cap.php` |
| 85 | `api/save_supplier.php` | `api/luu_nha_cung_cap.php` |
| 86 | `api/search_customers.php` | `api/tim_kiem_khach_hang.php` |
| 87 | `api/search_products.php` | `api/tim_kiem_san_pham.php` |
| 88 | `api/suggest_location.php` | `api/goi_y_vi_tri.php` |
| 89 | `api/toggle_supplier_status.php` | `api/chuyen_trang_thai_nha_cung_cap.php` |
| 90 | `api/update_order_status.php` | `api/cap_nhat_trang_thai_don_hang.php` |

### D. AUTH (/auth/ - 4 files)

| STT | Tên hiện tại | Tên đề xuất |
|-----|--------------|-------------|
| 91 | `auth/auth_middleware.php` | `auth/kiem_tra_xac_thuc.php` |
| 92 | `auth/login_process.php` | `auth/xu_ly_dang_nhap.php` |
| 93 | `auth/logout.php` | `auth/dang_xuat.php` |
| 94 | `auth/register_process.php` | `auth/xu_ly_dang_ky.php` |

### E. CLASSES (/classes/ - 11 files)

| STT | Tên hiện tại | Tên đề xuất |
|-----|--------------|-------------|
| 95 | `classes/AIService.php` | `classes/DichVuAI.php` |
| 96 | `classes/EmailService.php` | `classes/DichVuEmail.php` |
| 97 | `classes/EmployeeManager.php` | `classes/QuanLyNhanVien.php` |
| 98 | `classes/QRCodeGenerator.php` | `classes/TaoMaQR.php` |
| 99 | `classes/QRCodeManager.php` | `classes/QuanLyMaQR.php` |
| 100 | `classes/SimpleQRGenerator.php` | `classes/TaoMaQRDonGian.php` |
| 101 | `classes/SmartProductMatcher.php` | `classes/GhepSanPhamThongMinh.php` |
| 102 | `classes/StockReceiptHistory.php` | `classes/LichSuPhieuNhap.php` |
| 103 | `classes/User.php` | `classes/NguoiDung.php` |
| 104 | `classes/Warehouse.php` | `classes/Kho.php` |
| 105 | `classes/WarehouseAccessControl.php` | `classes/KiemSoatTruyCapKho.php` |

### F. CONFIG (/config/ - 2 files)

| STT | Tên hiện tại | Tên đề xuất |
|-----|--------------|-------------|
| 106 | `config/database.php` | `config/co_so_du_lieu.php` |
| 107 | `config/database.example.php` | `config/co_so_du_lieu.example.php` |

### G. HELPERS (/helpers/ - 8 files)

| STT | Tên hiện tại | Tên đề xuất |
|-----|--------------|-------------|
| 108 | `helpers/AIAnalysisHelper.php` | `helpers/TroGiupPhanTichAI.php` |
| 109 | `helpers/AuditLogger.php` | `helpers/GhiNhatKyKiemToan.php` |
| 110 | `helpers/EmailService.php` | `helpers/DichVuEmail.php` |
| 111 | `helpers/generate_employee_template.php` | `helpers/tao_mau_nhan_vien.php` |
| 112 | `helpers/ImageUploadService.php` | `helpers/DichVuTaiLenHinhAnh.php` |
| 113 | `helpers/PathHelper.php` | `helpers/TroGiupDuongDan.php` |
| 114 | `helpers/SimilarityHelper.php` | `helpers/TroGiupDoTuongDong.php` |
| 115 | `helpers/UsernameGenerator.php` | `helpers/TaoTenDangNhap.php` |

### H. INCLUDES (/includes/ - 5 files)

| STT | Tên hiện tại | Tên đề xuất |
|-----|--------------|-------------|
| 116 | `includes/footer.php` | `includes/chan_trang.php` |
| 117 | `includes/logout_modal.php` | `includes/hop_thoai_dang_xuat.php` |
| 118 | `includes/menu_components.php` | `includes/thanh_phan_menu.php` |
| 119 | `includes/sidebar.php` | `includes/thanh_ben.php` |
| 120 | `includes/topbar.php` | `includes/thanh_tren.php` |

### I. MODALS (/modals/ - 2 files)

| STT | Tên hiện tại | Tên đề xuất |
|-----|--------------|-------------|
| 121 | `modals/category_modals.php` | `modals/hop_thoai_danh_muc.php` |
| 122 | `modals/supplier_modals.php` | `modals/hop_thoai_nha_cung_cap.php` |

---

## 📈 TOP 20 FILES CÓ NHIỀU DEPENDENCIES NHẤT (RISK CAO)

### 1. **config/database.php** (config/co_so_du_lieu.php)
- **Loại:** Core Config
- **Được require bởi:** 50+ files
- **Risk Level:** 🔴 CỰC CAO
- **Lý do:** Được include bởi TẤT CẢ các file cần DB

### 2. **includes/sidebar.php** (includes/thanh_ben.php)
- **Loại:** UI Component
- **Được include bởi:** 30+ pages
- **Risk Level:** 🔴 CỰC CAO
- **Lý do:** Component layout chính

### 3. **includes/topbar.php** (includes/thanh_tren.php)
- **Loại:** UI Component
- **Được include bởi:** 30+ pages
- **Risk Level:** 🔴 CỰC CAO
- **Lý do:** Component layout chính

### 4. **includes/footer.php** (includes/chan_trang.php)
- **Loại:** UI Component
- **Được include bởi:** 25+ pages
- **Risk Level:** 🔴 CAO
- **Lý do:** Component layout chính

### 5. **classes/Warehouse.php** (classes/Kho.php)
- **Loại:** Business Logic
- **Được require bởi:** 15+ files
- **Risk Level:** 🔴 CAO
- **Dependencies:** database.php
- **Used by:** warehouse_management.php, stock_receipts_management.php, etc.

### 6. **api_get_product_details.php** (api_lay_chi_tiet_san_pham.php)
- **Loại:** API Endpoint
- **AJAX calls từ:** 8+ pages
- **Risk Level:** 🟡 CAO
- **Called by:** create_new_stock_receipt.php, add_product_ai.php, etc.

### 7. **api_employee_management.php** (api_quan_ly_nhan_vien.php)
- **Loại:** API Endpoint
- **AJAX calls từ:** 6+ pages
- **Risk Level:** 🟡 CAO
- **Called by:** employee_management.php, force_change_password.php, change_password.php

### 8. **api_warehouse_locations.php** (api_vi_tri_kho.php)
- **Loại:** API Endpoint
- **AJAX calls từ:** 5+ pages
- **Risk Level:** 🟡 CAO
- **Called by:** warehouse_locations.php, JS modules

### 9. **api_ai_forecast.php** (api_du_bao_ai.php)
- **Loại:** API Endpoint
- **AJAX calls từ:** 4+ pages
- **Risk Level:** 🟡 TRUNG BÌNH
- **Called by:** ai_forecast.php, index.php

### 10. **classes/QRCodeManager.php** (classes/QuanLyMaQR.php)
- **Loại:** Business Logic
- **Được require bởi:** 5+ files
- **Risk Level:** 🟡 TRUNG BÌNH
- **Used by:** api_qr_management.php, api_stock_receipt_management.php

### 11. **api_stock_receipt_management.php** (api_quan_ly_phieu_nhap.php)
- **Loại:** API Endpoint
- **AJAX calls từ:** 4+ pages
- **Risk Level:** 🟡 TRUNG BÌNH
- **Called by:** stock_receipts_management.php, JS modules

### 12. **api_storage_suggestions.php** (api_goi_y_luu_tru.php)
- **Loại:** API Endpoint
- **AJAX calls từ:** 4+ pages
- **Risk Level:** 🟡 TRUNG BÌNH
- **Called by:** create_manual_stock_receipt.php, create_new_stock_receipt.php

### 13. **helpers/AuditLogger.php** (helpers/GhiNhatKyKiemToan.php)
- **Loại:** Helper Class
- **Được require bởi:** 3+ files
- **Risk Level:** 🟢 TRUNG BÌNH THẤP

### 14. **api_ai_analyze.php** (api_phan_tich_ai.php)
- **Loại:** API Endpoint
- **AJAX calls từ:** 2+ pages
- **Risk Level:** 🟢 TRUNG BÌNH THẤP
- **Imported by:** api_ai_forecast.php, JS modules

### 15. **classes/StockReceiptHistory.php** (classes/LichSuPhieuNhap.php)
- **Loại:** Business Logic
- **Được require bởi:** 2+ files
- **Risk Level:** 🟢 TRUNG BÌNH THẤP

### 16. **helpers/AIAnalysisHelper.php** (helpers/TroGiupPhanTichAI.php)
- **Loại:** Helper Class
- **Được require bởi:** 2+ files
- **Risk Level:** 🟢 THẤP

### 17. **enhanced_duplicate_functions.php** (ham_kiem_tra_trung_lap_nang_cao.php)
- **Loại:** Helper Functions
- **Được require bởi:** 1 file
- **Risk Level:** 🟢 THẤP
- **Used by:** create_new_stock_receipt.php

### 18. **api_qr_management.php** (api_quan_ly_ma_qr.php)
- **Loại:** API Endpoint
- **AJAX calls từ:** 3+ pages
- **Risk Level:** 🟢 THẤP

### 19. **api_cascading_filters.php** (api_bo_loc_lien_tiep.php)
- **Loại:** API Endpoint
- **AJAX calls từ:** 2+ pages
- **Risk Level:** 🟢 THẤP

### 20. **api_get_product_data.php** (api_lay_du_lieu_san_pham.php)
- **Loại:** API Endpoint
- **AJAX calls từ:** 3+ pages
- **Risk Level:** 🟢 THẤP

---

## 🎯 CHIẾN LƯỢC RENAME AN TOÀN

### GIAI ĐOẠN 1: CHUẨN BỊ (1 ngày)
✅ **Mục tiêu:** Backup và test environment

1. **Backup toàn bộ project**
   ```bash
   # Tạo backup folder
   cp -r CNShoeStock CNShoeStock_backup_20251207
   
   # Backup database
   mysqldump -u root smart_shoes_warehouse2 > backup_db_20251207.sql
   ```

2. **Tạo branch Git mới**
   ```bash
   git checkout -b refactor/rename-vietnamese-files
   git add .
   git commit -m "Backup trước khi rename files"
   ```

3. **Test environment**
   - Đảm bảo tất cả features hoạt động
   - Document các bugs hiện tại

### GIAI ĐOẠN 2: RENAME CÁC FILES ÍT DEPENDENCY (2-3 ngày)

**Thứ tự ưu tiên: LOW RISK → HIGH RISK**

#### Bước 1: Modals (Risk: 🟢 THẤP)
```php
// 2 files - không có dependencies phức tạp
modals/category_modals.php → modals/hop_thoai_danh_muc.php
modals/supplier_modals.php → modals/hop_thoai_nha_cung_cap.php
```

#### Bước 2: Standalone Pages (Risk: 🟢 THẤP)
```php
// 5 files - ít được reference
qr_diagnostics.php → chan_doan_ma_qr.php
process_email_queue.php → xu_ly_hang_doi_email.php
customer_methods_extension.php → mo_rong_phuong_thuc_khach_hang.php
enhanced_duplicate_functions.php → ham_kiem_tra_trung_lap_nang_cao.php
env_loader.php → tai_bien_moi_truong.php
```

#### Bước 3: Helper Classes (Risk: 🟢 THẤP-TRUNG BÌNH)
```php
// 8 files helpers/
helpers/PathHelper.php → helpers/TroGiupDuongDan.php
helpers/UsernameGenerator.php → helpers/TaoTenDangNhap.php
helpers/SimilarityHelper.php → helpers/TroGiupDoTuongDong.php
helpers/generate_employee_template.php → helpers/tao_mau_nhan_vien.php
helpers/ImageUploadService.php → helpers/DichVuTaiLenHinhAnh.php
helpers/EmailService.php → helpers/DichVuEmail.php
helpers/AIAnalysisHelper.php → helpers/TroGiupPhanTichAI.php
helpers/AuditLogger.php → helpers/GhiNhatKyKiemToan.php
```

**Action:** Update các file require helper này

#### Bước 4: Business Logic Classes (Risk: 🟡 TRUNG BÌNH)
```php
// 11 files classes/
classes/SimpleQRGenerator.php → classes/TaoMaQRDonGian.php
classes/SmartProductMatcher.php → classes/GhepSanPhamThongMinh.php
classes/User.php → classes/NguoiDung.php
classes/EmailService.php → classes/DichVuEmail.php
classes/AIService.php → classes/DichVuAI.php
classes/EmployeeManager.php → classes/QuanLyNhanVien.php
classes/StockReceiptHistory.php → classes/LichSuPhieuNhap.php
classes/QRCodeGenerator.php → classes/TaoMaQR.php
classes/QRCodeManager.php → classes/QuanLyMaQR.php
classes/WarehouseAccessControl.php → classes/KiemSoatTruyCapKho.php
classes/Warehouse.php → classes/Kho.php
```

**Action:** Update tất cả require_once cho classes

### GIAI ĐOẠN 3: RENAME API ENDPOINTS (3-4 ngày)

#### Bước 5: API Subfolder (Risk: 🟢 THẤP)
```php
// 14 files trong api/
api/add_supplier.php → api/them_nha_cung_cap.php
api/confirm_order.php → api/xac_nhan_don_hang.php
api/create_order.php → api/tao_don_hang.php
api/delete_supplier.php → api/xoa_nha_cung_cap.php
api/get_order_detail.php → api/lay_chi_tiet_don_hang.php
api/get_order_details.php → api/lay_thong_tin_don_hang.php
api/get_orders.php → api/lay_danh_sach_don_hang.php
api/get_supplier.php → api/lay_nha_cung_cap.php
api/save_supplier.php → api/luu_nha_cung_cap.php
api/search_customers.php → api/tim_kiem_khach_hang.php
api/search_products.php → api/tim_kiem_san_pham.php
api/suggest_location.php → api/goi_y_vi_tri.php
api/toggle_supplier_status.php → api/chuyen_trang_thai_nha_cung_cap.php
api/update_order_status.php → api/cap_nhat_trang_thai_don_hang.php
```

**Action:** Update AJAX calls trong JS/PHP pages

#### Bước 6: Root API Files - Low Traffic (Risk: 🟡 TRUNG BÌNH)
```php
// 10 files - ít được gọi
api_upload_images.php → api_tai_len_hinh_anh.php
api_profitability.php → api_loi_nhuan.php
api_inventory_turnover.php → api_vong_quay_hang_ton.php
api_data_quality.php → api_chat_luong_du_lieu.php
api_consumer_behavior.php → api_hanh_vi_nguoi_tieu_dung.php
api_supply_chain.php → api_chuoi_cung_ung.php
api_market_trends.php → api_xu_huong_thi_truong.php
api_trend_analysis.php → api_phan_tich_xu_huong.php
api_create_manual_product.php → api_tao_san_pham_thu_cong.php
api_customers_warehouse.php → api_kho_khach_hang.php
```

#### Bước 7: Root API Files - Medium Traffic (Risk: 🟡 CAO)
```php
// 10 files - được gọi vừa phải
api_add_supplier.php → api_them_nha_cung_cap.php
api_cascading_filters.php → api_bo_loc_lien_tiep.php
api_get_product_suggestions.php → api_lay_goi_y_san_pham.php
api_get_size_price.php → api_lay_gia_theo_size.php
api_check_duplicates_manual.php → api_kiem_tra_trung_lap_thu_cong.php
api_qr_management.php → api_quan_ly_ma_qr.php
api_get_product_data.php → api_lay_du_lieu_san_pham.php
api_reprocess_export.php → api_xu_ly_lai_xuat_kho.php
api_add_product_to_receipt.php → api_them_san_pham_vao_phieu.php
api_stock_receipt_simple.php → api_phieu_nhap_don_gian.php
```

**Action:** Update trong create_manual_stock_receipt.php, add_product_ai.php

#### Bước 8: Root API Files - High Traffic (Risk: 🔴 CAO)
```php
// 10 files - được gọi nhiều
api_ai_shoe_analysis.php → api_phan_tich_giay_ai.php
api_ai_analyze.php → api_phan_tich_ai.php
api_save_ai_receipt.php → api_luu_phieu_nhap_ai.php
api_save_manual_stock_receipt.php → api_luu_phieu_nhap_thu_cong.php
api_storage_suggestions.php → api_goi_y_luu_tru.php
api_export_process.php → api_xu_ly_xuat_kho.php
api_confirm_delivery.php → api_xac_nhan_giao_hang.php
api_update_delivery_status.php → api_cap_nhat_trang_thai_giao_hang.php
api_export_forecast.php → api_xuat_du_bao.php
api_warehouse_access.php → api_quyen_truy_cap_kho.php
```

**Action:** Update tất cả AJAX calls, requires, JS modules

### GIAI ĐOẠN 4: RENAME MAIN PAGES (3-4 ngày)

#### Bước 9: Simple View Pages (Risk: 🟡 TRUNG BÌNH)
```php
// 15 files - ít redirects
view_product.php → xem_san_pham.php
view_receipt.php → xem_phieu_nhap.php
view_export.php → xem_xuat_kho.php
export_slip.php → phieu_xuat_kho.php
profile_settings.php → cai_dat_ca_nhan.php
qr_diagnostics.php → chan_doan_ma_qr.php
ai_inventory_analysis.php → phan_tich_ton_kho_ai.php
confirmed_exports.php → xuat_kho_da_xac_nhan.php
warehouse_products.php → san_pham_kho.php
warehouse_locations.php → vi_tri_kho.php
orders_management.php → quan_ly_don_hang.php
create_order.php → tao_don_hang.php
add_supplier.php → them_nha_cung_cap.php
reactivate_product.php → kich_hoat_lai_san_pham.php
delete_product.php → xoa_san_pham.php
```

**Action:** Update redirects, header locations

#### Bước 10: Management Pages (Risk: 🔴 CAO)
```php
// 10 files - nhiều references
edit_product.php → sua_san_pham.php
danh_sach_sp.php → danh_sach_san_pham.php
suppliers_management.php → quan_ly_nha_cung_cap.php
export_management.php → quan_ly_xuat_kho.php
process_export.php → xu_ly_xuat_kho.php
confirm_delivery.php → xac_nhan_giao_hang.php
update_delivery_status.php → cap_nhat_trang_thai_giao_hang.php
employee_management.php → quan_ly_nhan_vien.php
warehouse_management.php → quan_ly_kho.php
ai_analytics_dashboard.php → bang_dieu_khien_phan_tich_ai.php
```

**Action:** Update menu, sidebars, tất cả links

#### Bước 11: Critical Pages (Risk: 🔴 CỰC CAO)
```php
// 6 files - core functionality
ai_forecast.php → du_bao_ai.php
stock_receipts_management.php → quan_ly_phieu_nhap.php
create_manual_stock_receipt.php → tao_phieu_nhap_thu_cong.php
add_product_ai.php → them_san_pham_ai.php
create_new_stock_receipt.php → tao_phieu_nhap_moi.php
```

**Action:** Test toàn bộ workflow

### GIAI ĐOẠN 5: RENAME CORE FILES (2 ngày)

#### Bước 12: Auth System (Risk: 🔴 CỰC CAO)
```php
// 4 files auth/
auth/register_process.php → auth/xu_ly_dang_ky.php
auth/logout.php → auth/dang_xuat.php
auth/login_process.php → auth/xu_ly_dang_nhap.php
auth/auth_middleware.php → auth/kiem_tra_xac_thuc.php
```

**Action:** Update login.html, register.html forms

#### Bước 13: Auth Pages (Risk: 🔴 CỰC CAO)
```php
// 4 files
change_password.php → doi_mat_khau.php
force_change_password.php → bat_buoc_doi_mat_khau.php
logout.php → dang_xuat.php
```

**Action:** Update tất cả auth references

#### Bước 14: Entry Points (Risk: 🔴 CỰC CAO - CUỐI CÙNG)
```php
// 3 files - main entry
login.html → dang_nhap.html
register.html → dang_ky.html
index.php → trang_chu.php (hoặc giữ nguyên index.php)
```

**⚠️ LƯU Ý:** index.php nên giữ nguyên để làm entry point

### GIAI ĐOẠN 6: RENAME INCLUDES/CONFIG (1 ngày)

#### Bước 15: Includes - UI Components (Risk: 🔴 CỰC CAO)
```php
// 5 files - được include ở mọi nơi
includes/logout_modal.php → includes/hop_thoai_dang_xuat.php
includes/menu_components.php → includes/thanh_phan_menu.php
includes/footer.php → includes/chan_trang.php
includes/topbar.php → includes/thanh_tren.php
includes/sidebar.php → includes/thanh_ben.php
```

**Action:** Find/Replace trong TẤT CẢ files

#### Bước 16: Config (Risk: 🔴 CỰC CAO - CUỐI CÙNG NHẤT)
```php
// 2 files - core config
config/database.example.php → config/co_so_du_lieu.example.php
config/database.php → config/co_so_du_lieu.php
```

**⚠️ QUAN TRỌNG:** Rename cuối cùng, update trong 50+ files

---

## 🛠️ SCRIPT TỰ ĐỘNG HÓA

### Script 1: Tạo backup
```php
<?php
// backup_project.php
$source = 'C:/xampp/htdocs/CNShoeStock';
$dest = 'C:/xampp/htdocs/CNShoeStock_backup_' . date('Ymd_His');

function recurse_copy($src, $dst) {
    $dir = opendir($src);
    @mkdir($dst);
    while(false !== ( $file = readdir($dir)) ) {
        if (( $file != '.' ) && ( $file != '..' )) {
            if ( is_dir($src . '/' . $file) ) {
                if ($file !== 'vendor' && $file !== 'node_modules') {
                    recurse_copy($src . '/' . $file, $dst . '/' . $file);
                }
            }
            else {
                copy($src . '/' . $file, $dst . '/' . $file);
            }
        }
    }
    closedir($dir);
}

recurse_copy($source, $dest);
echo "Backup completed: $dest\n";
```

### Script 2: Find all dependencies
```php
<?php
// find_dependencies.php
$projectRoot = 'C:/xampp/htdocs/CNShoeStock';
$dependencies = [];

function scanPhpFiles($dir) {
    global $dependencies;
    
    $files = glob($dir . '/*.php');
    foreach ($files as $file) {
        $content = file_get_contents($file);
        
        // Find require/include
        preg_match_all('/require_once\s+[\'"]([^\'"]+)[\'"]/i', $content, $requires);
        preg_match_all('/include\s+[\'"]([^\'"]+)[\'"]/i', $content, $includes);
        
        $dependencies[basename($file)] = [
            'requires' => $requires[1],
            'includes' => $includes[1]
        ];
    }
    
    // Recurse subdirectories
    $dirs = glob($dir . '/*', GLOB_ONLYDIR);
    foreach ($dirs as $subdir) {
        if (basename($subdir) !== 'vendor') {
            scanPhpFiles($subdir);
        }
    }
}

scanPhpFiles($projectRoot);
file_put_contents('dependencies.json', json_encode($dependencies, JSON_PRETTY_PRINT));
echo "Dependencies exported to dependencies.json\n";
```

### Script 3: Batch rename với update references
```php
<?php
// batch_rename.php
$renameMap = [
    // Modals (Phase 2 - Step 1)
    'modals/category_modals.php' => 'modals/hop_thoai_danh_muc.php',
    'modals/supplier_modals.php' => 'modals/hop_thoai_nha_cung_cap.php',
    
    // Add more mappings here...
];

$projectRoot = 'C:/xampp/htdocs/CNShoeStock';

foreach ($renameMap as $oldPath => $newPath) {
    $oldFull = $projectRoot . '/' . $oldPath;
    $newFull = $projectRoot . '/' . $newPath;
    
    if (file_exists($oldFull)) {
        // Rename file
        rename($oldFull, $newFull);
        echo "✓ Renamed: $oldPath → $newPath\n";
        
        // Update all references
        updateReferences($oldPath, $newPath, $projectRoot);
    } else {
        echo "✗ Not found: $oldPath\n";
    }
}

function updateReferences($oldName, $newName, $root) {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root),
        RecursiveIteratorIterator::SELF_FIRST
    );
    
    foreach ($files as $file) {
        if ($file->isFile() && preg_match('/\.(php|js|html)$/', $file->getFilename())) {
            if (strpos($file->getPathname(), 'vendor') !== false) continue;
            
            $content = file_get_contents($file->getPathname());
            $oldBasename = basename($oldName);
            $newBasename = basename($newName);
            
            $patterns = [
                "/require_once\s+['\"]([^'\"]*)" . preg_quote($oldBasename, '/') . "['\"]/",
                "/include\s+['\"]([^'\"]*)" . preg_quote($oldBasename, '/') . "['\"]/",
                "/header\s*\(\s*['\"]Location:\s*([^'\"]*)" . preg_quote($oldBasename, '/') . "['\"]/",
                "/url\s*:\s*['\"]([^'\"]*)" . preg_quote($oldBasename, '/') . "['\"]/",
                "/fetch\s*\(\s*['\"]([^'\"]*)" . preg_quote($oldBasename, '/') . "['\"]/",
            ];
            
            $updated = $content;
            foreach ($patterns as $pattern) {
                $updated = preg_replace($pattern, function($matches) use ($newBasename) {
                    return str_replace(basename($matches[0]), $newBasename, $matches[0]);
                }, $updated);
            }
            
            if ($updated !== $content) {
                file_put_contents($file->getPathname(), $updated);
                echo "  → Updated references in: " . $file->getFilename() . "\n";
            }
        }
    }
}
```

### Script 4: Validation sau rename
```php
<?php
// validate_rename.php
$projectRoot = 'C:/xampp/htdocs/CNShoeStock';
$errors = [];

function validatePhpFile($file) {
    global $errors;
    
    $content = file_get_contents($file);
    
    // Check for old file references
    $oldPatterns = [
        'warehouse_management.php',
        'employee_management.php',
        'api_ai_forecast.php',
        // Add more old names...
    ];
    
    foreach ($oldPatterns as $pattern) {
        if (strpos($content, $pattern) !== false) {
            $errors[] = [
                'file' => $file,
                'issue' => "Still references old filename: $pattern"
            ];
        }
    }
    
    // Check for broken includes
    preg_match_all('/require_once\s+[\'"]([^\'"]+)[\'"]/i', $content, $requires);
    foreach ($requires[1] as $requiredFile) {
        $fullPath = dirname($file) . '/' . $requiredFile;
        if (!file_exists($fullPath)) {
            $errors[] = [
                'file' => $file,
                'issue' => "Broken require: $requiredFile"
            ];
        }
    }
}

$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($projectRoot),
    RecursiveIteratorIterator::SELF_FIRST
);

foreach ($files as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        if (strpos($file->getPathname(), 'vendor') === false) {
            validatePhpFile($file->getPathname());
        }
    }
}

if (empty($errors)) {
    echo "✓ Validation passed! No issues found.\n";
} else {
    echo "✗ Found " . count($errors) . " issues:\n";
    foreach ($errors as $error) {
        echo "  - {$error['file']}: {$error['issue']}\n";
    }
}
```

---

## 📋 CHECKLIST THỰC HIỆN

### Trước khi bắt đầu:
- [ ] Backup toàn bộ project
- [ ] Backup database
- [ ] Tạo Git branch mới
- [ ] Test environment hoạt động
- [ ] Thông báo team về maintenance

### Mỗi giai đoạn:
- [ ] Rename files theo thứ tự
- [ ] Update tất cả references
- [ ] Test chức năng liên quan
- [ ] Commit Git với message rõ ràng
- [ ] Document các issues gặp phải

### Sau khi hoàn thành:
- [ ] Chạy validation script
- [ ] Test toàn bộ hệ thống
- [ ] Check error logs
- [ ] Update documentation
- [ ] Merge vào main branch
- [ ] Deploy lên production

---

## ⚠️ LƯU Ý QUAN TRỌNG

### 1. **KHÔNG RENAME NGAY:**
- `index.php` - Entry point chính
- `vendor/` - Thư viện bên thứ 3
- `.htaccess` - Server config

### 2. **TEST KỸ CÀNG:**
- Login/Logout flow
- CRUD operations
- AI features
- QR code generation
- Export/Import functions

### 3. **ROLLBACK PLAN:**
```bash
# Nếu có vấn đề, rollback ngay:
git reset --hard HEAD~1
# Hoặc restore từ backup:
cp -r CNShoeStock_backup_20251207/* CNShoeStock/
```

### 4. **SEARCH & REPLACE TOOLS:**
Sử dụng VS Code với regex:
```regex
# Tìm tất cả references của một file
require_once.*warehouse_management\.php
include.*warehouse_management\.php
header.*Location:.*warehouse_management\.php
url.*warehouse_management\.php
```

### 5. **DATABASE:**
KHÔNG đổi tên tables/columns, chỉ đổi tên files PHP!

---

## 📞 HỖ TRỢ

**Thời gian dự kiến:** 10-15 ngày làm việc  
**Risk Level:** 🔴 CAO - Cần có kế hoạch chi tiết và test kỹ

**Recommendation:**  
Nên thực hiện trên môi trường staging trước, sau đó mới apply lên production.

---

**Generated:** 2025-12-07  
**Version:** 1.0  
**Status:** READY FOR IMPLEMENTATION
