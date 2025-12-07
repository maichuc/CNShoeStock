-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Máy chủ: 127.0.0.1
-- Thời gian đã tạo: Th12 06, 2025 lúc 04:26 AM
-- Phiên bản máy phục vụ: 10.4.32-MariaDB
-- Phiên bản PHP: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Cơ sở dữ liệu: `smart_shoes_warehouse2`
--

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `audit_logs`
--

CREATE TABLE `audit_logs` (
  `log_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `table_name` varchar(50) DEFAULT NULL,
  `record_id` int(11) DEFAULT NULL,
  `old_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_values`)),
  `new_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_values`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `details` text DEFAULT NULL,
  `warehouse_id` int(11) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Đang đổ dữ liệu cho bảng `audit_logs`
--

INSERT INTO `audit_logs` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_values`, `new_values`, `created_at`, `details`, `warehouse_id`) VALUES
(1, 1, 'register', 'users', 1, NULL, '{\"username\":\"btmchucmckho001\",\"warehouse_id\":\"1\"}', '2025-12-03 17:47:39', NULL, NULL),
(2, 1, 'create_product_ai', 'products', 1, NULL, '{\"product_name\":\"Giày cao gót CHARLES & KEITH Thiết kế mũi nhọn; Quai hậu slingback có thể điều chỉnh; Quai vắt chéo mu bàn chân; Khóa cài vàng kim; Gót nhọn thanh mảnh; Chất liệu bóng bẩy; Lót giày in logo thương hiệu. Đỏ burgundy, Đen, Vàng kim\",\"brand\":\"CHARLES & KEITH\",\"type\":\"Giày cao gót\",\"material\":\"Da bóng\",\"features\":\"Thiết kế mũi nhọn; Quai hậu slingback có thể điều chỉnh; Quai vắt chéo mu bàn chân; Khóa cài vàng kim; Gót nhọn thanh mảnh; Chất liệu bóng bẩy; Lót giày in logo thương hiệu.\",\"tags\":\"Giày cao gót, Mũi nhọn, Quai hậu, Slingback, Quai kép, Đỏ burgundy, Bóng, Sang trọng, Thời trang, Gót nhọn, CHARLES & KEITH\",\"base_sku\":\"CHAR-HIGH-953\",\"color\":\"Đỏ burgundy, Đen, Vàng kim\",\"sizes_count\":1,\"sizes_details\":[{\"variant_id\":\"1\",\"size\":\"43\",\"price\":0,\"current_quantity\":0,\"sku\":\"CHAR-HIGH-953-43\"}],\"image_count\":2,\"entry_method\":\"ai\",\"note\":\"Product created via AI analysis - Stock quantities will be added via stock receipts\"}', '2025-12-03 17:50:02', NULL, 1),
(3, 3, 'register', 'users', 3, NULL, '{\"username\":\"chuchochiminh003\",\"warehouse_id\":\"3\"}', '2025-12-03 21:02:29', NULL, NULL),
(4, 3, 'create_product_ai', 'products', 2, NULL, '{\"product_name\":\"Sneaker SECOND SUNDAY Thiết kế cổ thấp, buộc dây, sọc sóng tương phản, đế ngoài cao su chống trượt, logo thương hiệu trên lưỡi gà và gót. Trắng, Đen\",\"brand\":\"SECOND SUNDAY\",\"type\":\"Sneaker\",\"material\":\"Thân giày: Da tổng hợp\\/PU; Đế ngoài: Cao su\",\"features\":\"Thiết kế cổ thấp, buộc dây, sọc sóng tương phản, đế ngoài cao su chống trượt, logo thương hiệu trên lưỡi gà và gót.\",\"tags\":\"Sneaker, Giày thể thao, Giày cổ thấp, Giày buộc dây, Giày trắng, Giày đen, Phong cách retro, Giày casual, SECOND SUNDAY\",\"base_sku\":\"SECO-SNK-569\",\"color\":\"Trắng, Đen\",\"sizes_count\":4,\"sizes_details\":[{\"variant_id\":\"2\",\"size\":\"35\",\"price\":200000,\"current_quantity\":0,\"sku\":\"SECO-SNK-569-35\"},{\"variant_id\":\"3\",\"size\":\"36\",\"price\":200000,\"current_quantity\":0,\"sku\":\"SECO-SNK-569-36\"},{\"variant_id\":\"4\",\"size\":\"37\",\"price\":200000,\"current_quantity\":0,\"sku\":\"SECO-SNK-569-37\"},{\"variant_id\":\"5\",\"size\":\"39\",\"price\":20000,\"current_quantity\":0,\"sku\":\"SECO-SNK-569-39\"}],\"image_count\":3,\"entry_method\":\"ai\",\"note\":\"Product created via AI analysis - Stock quantities will be added via stock receipts\"}', '2025-12-04 15:39:41', NULL, 3),
(5, 3, 'create_product_ai', 'products', 3, NULL, '{\"product_name\":\"Sneaker DILY Đế chunky, Thân giày thoáng khí, Đế chống trượt, Thiết kế năng động Trắng kem, Nâu nhạt, Nâu\",\"brand\":\"DILY\",\"type\":\"Sneaker\",\"material\":\"Vải lưới, Da tổng hợp, Cao su\",\"features\":\"Đế chunky, Thân giày thoáng khí, Đế chống trượt, Thiết kế năng động\",\"tags\":\"Giày sneaker, DILY, AG0137, Giày nữ, Giày thể thao, Đế chunky, Trắng kem, Nâu nhạt, Thoáng khí, Phong cách năng động\",\"base_sku\":\"DILY-SNK-184\",\"color\":\"Trắng kem, Nâu nhạt, Nâu\",\"sizes_count\":1,\"sizes_details\":[{\"variant_id\":\"6\",\"size\":\"40\",\"price\":500000,\"current_quantity\":0,\"sku\":\"DILY-SNK-184-40\"}],\"image_count\":2,\"entry_method\":\"ai\",\"note\":\"Product created via AI analysis - Stock quantities will be added via stock receipts\"}', '2025-12-05 02:21:13', NULL, 3),
(6, 3, 'RECEIPT_CONFIRMED', 'stock_receipts', 1, NULL, NULL, '2025-12-05 02:26:15', 'Xác nhận phiếu nhập #RC-000001', 3),
(7, 3, 'accept_order_reduce_inventory', 'warehouse_exports', 1, NULL, '{\"export_id\":\"1\",\"export_code\":\"EXP202512050001\",\"order_id\":1,\"items_count\":1,\"note\":\"Inventory reduced when order accepted (new logic)\"}', '2025-12-05 02:29:19', NULL, 3),
(8, 3, 'update_picked_quantity', 'warehouse_export_details', 1, NULL, '{\"export_id\":1,\"detail_id\":\"1\",\"variant_id\":\"6\",\"picked_quantity\":1,\"required_quantity\":\"1\",\"note\":\"Inventory already reduced when order accepted\"}', '2025-12-05 02:30:39', '{\"export_id\":1,\"detail_id\":\"1\",\"variant_id\":\"6\",\"picked_quantity\":1,\"required_quantity\":\"1\",\"note\":\"Inventory already reduced when order accepted\"}', 3),
(9, 3, 'complete_export', 'warehouse_exports', 1, NULL, '{\"export_id\":1,\"status\":\"completed\",\"completed_items\":\"1\",\"total_items\":\"1\"}', '2025-12-05 02:30:47', '{\"export_id\":1,\"status\":\"completed\",\"completed_items\":\"1\",\"total_items\":\"1\"}', 3),
(10, 3, 'confirm_delivery', 'warehouse_exports', 1, NULL, '{\"export_id\":1,\"export_code\":\"EXP202512050001\",\"order_id\":\"1\",\"customer_name\":\"Fhh\",\"total_items\":\"1\",\"total_quantity\":\"1\",\"order_status_updated\":\"waiting_delivery\",\"note\":\"Inventory was already reduced during export processing\"}', '2025-12-05 02:31:30', '{\"export_id\":1,\"export_code\":\"EXP202512050001\",\"order_id\":\"1\",\"customer_name\":\"Fhh\",\"total_items\":\"1\",\"total_quantity\":\"1\",\"order_status_updated\":\"waiting_delivery\",\"note\":\"Inventory was already reduced during export processing\"}', 3),
(11, 3, 'update_delivery_status', 'orders', 1, NULL, '{\"status\":\"delivered\",\"reason\":null,\"updated_at\":\"2025-12-05 03:31:42\"}', '2025-12-05 02:31:42', '{\"order_id\":1,\"old_status\":\"waiting_delivery\",\"new_status\":\"delivered\",\"original_action\":\"delivered\",\"customer_name\":\"Fhh\",\"action_type\":\"delivery_status_update\",\"inventory_restored\":false}', 3),
(12, 3, 'create_product_ai', 'products', 4, NULL, '{\"product_name\":\"Giày cao gót JEREMY Đế đúp, Gót nhọn cao, Quai đính đá lấp lánh, Khóa kéo sau gót, Thiết kế quai chéo Đỏ, Bạc, Trắng, Nâu\",\"brand\":\"JEREMY\",\"type\":\"Giày cao gót\",\"material\":\"Vải satin, Đá pha lê, Cao su\",\"features\":\"Đế đúp, Gót nhọn cao, Quai đính đá lấp lánh, Khóa kéo sau gót, Thiết kế quai chéo\",\"tags\":\"Giày cao gót, Giày nữ, Giày Jeremy, Giày đỏ, Giày satin, Giày đính đá, Giày đế đúp, Giày gót nhọn, Giày dự tiệc, Giày dạ hội\",\"base_sku\":\"JERE-HIGH-531\",\"color\":\"Đỏ, Bạc, Trắng, Nâu\",\"sizes_count\":2,\"sizes_details\":[{\"variant_id\":\"7\",\"size\":\"45\",\"price\":450000,\"current_quantity\":0,\"sku\":\"JERE-HIGH-531-45\"},{\"variant_id\":\"8\",\"size\":\"46\",\"price\":450000,\"current_quantity\":0,\"sku\":\"JERE-HIGH-531-46\"}],\"image_count\":2,\"entry_method\":\"ai\",\"note\":\"Product created via AI analysis - Stock quantities will be added via stock receipts\"}', '2025-12-05 03:08:35', NULL, 3),
(13, 3, 'create_product_ai', 'products', 3, NULL, '{\"product_name\":\"Sneaker DILY Đế chunky, Thân giày thoáng khí, Đế chống trượt, Thiết kế năng động Trắng kem, Nâu nhạt, Nâu\",\"brand\":\"DILY\",\"type\":\"Sneaker\",\"material\":\"Vải lưới, Da tổng hợp, Cao su\",\"features\":\"Đế chunky, Thân giày thoáng khí, Đế chống trượt, Thiết kế năng động\",\"tags\":\"Giày sneaker, DILY, AG0137, Giày nữ, Giày thể thao, Đế chunky, Trắng kem, Nâu nhạt, Thoáng khí, Phong cách năng động\",\"base_sku\":\"DILY-SNK-184\",\"color\":\"Trắng kem, Nâu nhạt, Nâu\",\"sizes_count\":2,\"sizes_details\":[{\"variant_id\":6,\"size\":\"40\",\"price\":500000,\"current_quantity\":\"0\",\"sku\":\"DILY-SNK-184-40\"},{\"variant_id\":\"9\",\"size\":\"45\",\"price\":500000,\"current_quantity\":0,\"sku\":\"DILY-SNK-184-45\"}],\"image_count\":2,\"entry_method\":\"ai\",\"note\":\"Product created via AI analysis - Stock quantities will be added via stock receipts\"}', '2025-12-05 03:23:35', NULL, 3),
(14, 3, 'RECEIPT_CONFIRMED', 'stock_receipts', 2, NULL, NULL, '2025-12-05 03:25:13', 'Xác nhận phiếu nhập #RC-000002', 3),
(15, 3, 'accept_order_reduce_inventory', 'warehouse_exports', 2, NULL, '{\"export_id\":\"2\",\"export_code\":\"EXP202512050002\",\"order_id\":2,\"items_count\":1,\"note\":\"Inventory reduced when order accepted (new logic)\"}', '2025-12-05 03:26:19', NULL, 3),
(16, 3, 'update_picked_quantity', 'warehouse_export_details', 2, NULL, '{\"export_id\":2,\"detail_id\":\"2\",\"variant_id\":\"6\",\"picked_quantity\":10,\"required_quantity\":\"10\",\"note\":\"Inventory already reduced when order accepted\"}', '2025-12-05 03:28:45', '{\"export_id\":2,\"detail_id\":\"2\",\"variant_id\":\"6\",\"picked_quantity\":10,\"required_quantity\":\"10\",\"note\":\"Inventory already reduced when order accepted\"}', 3),
(17, 3, 'complete_export', 'warehouse_exports', 2, NULL, '{\"export_id\":2,\"status\":\"completed\",\"completed_items\":\"1\",\"total_items\":\"1\"}', '2025-12-05 03:28:50', '{\"export_id\":2,\"status\":\"completed\",\"completed_items\":\"1\",\"total_items\":\"1\"}', 3),
(18, 3, 'confirm_delivery', 'warehouse_exports', 2, NULL, '{\"export_id\":2,\"export_code\":\"EXP202512050002\",\"order_id\":\"2\",\"customer_name\":\"Tus\",\"total_items\":\"1\",\"total_quantity\":\"10\",\"order_status_updated\":\"waiting_delivery\",\"note\":\"Inventory was already reduced during export processing\"}', '2025-12-05 03:28:56', '{\"export_id\":2,\"export_code\":\"EXP202512050002\",\"order_id\":\"2\",\"customer_name\":\"Tus\",\"total_items\":\"1\",\"total_quantity\":\"10\",\"order_status_updated\":\"waiting_delivery\",\"note\":\"Inventory was already reduced during export processing\"}', 3),
(19, 3, 'update_delivery_status', 'orders', 2, NULL, '{\"status\":\"delivered\",\"reason\":null,\"updated_at\":\"2025-12-05 04:29:08\"}', '2025-12-05 03:29:08', '{\"order_id\":2,\"old_status\":\"waiting_delivery\",\"new_status\":\"delivered\",\"original_action\":\"delivered\",\"customer_name\":\"Tus\",\"action_type\":\"delivery_status_update\",\"inventory_restored\":false}', 3);

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `bulk_import_logs`
--

CREATE TABLE `bulk_import_logs` (
  `import_id` int(11) NOT NULL,
  `warehouse_id` int(11) NOT NULL COMMENT 'Kho thực hiện import',
  `imported_by` int(11) NOT NULL COMMENT 'User thực hiện import',
  `file_name` varchar(255) NOT NULL COMMENT 'Tên file upload',
  `total_records` int(11) DEFAULT 0 COMMENT 'Tổng số bản ghi trong file',
  `success_count` int(11) DEFAULT 0 COMMENT 'Số bản ghi tạo thành công',
  `failed_count` int(11) DEFAULT 0 COMMENT 'Số bản ghi thất bại',
  `email_sent_count` int(11) DEFAULT 0 COMMENT 'Số email đã gửi',
  `email_failed_count` int(11) DEFAULT 0 COMMENT 'Số email thất bại',
  `status` enum('processing','completed','failed') DEFAULT 'processing' COMMENT 'Trạng thái: processing, completed, failed',
  `result_file_path` varchar(500) DEFAULT NULL COMMENT 'Đường dẫn file kết quả',
  `error_details` longtext DEFAULT NULL COMMENT 'Chi tiết lỗi (JSON)',
  `started_at` timestamp NOT NULL DEFAULT current_timestamp() COMMENT 'Thời điểm bắt đầu',
  `completed_at` timestamp NULL DEFAULT NULL COMMENT 'Thời điểm hoàn thành',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Log import nhân viên hàng loạt';

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `customers`
--

CREATE TABLE `customers` (
  `customer_id` int(11) NOT NULL,
  `warehouse_id` int(11) NOT NULL DEFAULT 1,
  `full_name` varchar(100) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text NOT NULL,
  `note` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Đang đổ dữ liệu cho bảng `customers`
--

INSERT INTO `customers` (`customer_id`, `warehouse_id`, `full_name`, `phone`, `email`, `address`, `note`, `created_at`) VALUES
(1, 3, 'Fhh', '09785266413', 'rggr@gmail.com', 'Dgbb', '', '2025-12-05 02:28:54'),
(2, 3, 'Tus', '0991727831', '', 'ewretuu', '', '2025-12-05 03:26:12');

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `email_logs`
--

CREATE TABLE `email_logs` (
  `id` int(11) NOT NULL,
  `recipient_email` varchar(255) NOT NULL,
  `recipient_name` varchar(255) DEFAULT NULL,
  `email_type` varchar(50) NOT NULL,
  `status` varchar(20) NOT NULL,
  `error_message` text DEFAULT NULL,
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Đang đổ dữ liệu cho bảng `email_logs`
--

INSERT INTO `email_logs` (`id`, `recipient_email`, `recipient_name`, `email_type`, `status`, `error_message`, `sent_at`) VALUES
(1, 'nhichuc260@gmail.com', 'Dinh Tuyet NHi', 'notification', 'sent', NULL, '2025-12-05 03:10:12');

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `email_queue`
--

CREATE TABLE `email_queue` (
  `queue_id` int(11) NOT NULL,
  `recipient_email` varchar(100) NOT NULL COMMENT 'Email người nhận',
  `recipient_name` varchar(100) DEFAULT NULL COMMENT 'Tên người nhận',
  `subject` varchar(255) NOT NULL COMMENT 'Tiêu đề email',
  `body_html` longtext NOT NULL COMMENT 'Nội dung HTML',
  `email_type` varchar(50) NOT NULL COMMENT 'Loại email: welcome, otp, password_changed, etc.',
  `related_import_id` int(11) DEFAULT NULL COMMENT 'Batch import liên quan',
  `status` enum('pending','sending','sent','failed') DEFAULT 'pending' COMMENT 'Trạng thái',
  `attempts` int(11) DEFAULT 0 COMMENT 'Số lần thử gửi',
  `max_attempts` int(11) DEFAULT 3 COMMENT 'Số lần thử tối đa',
  `last_error` text DEFAULT NULL COMMENT 'Lỗi gần nhất',
  `sent_at` timestamp NULL DEFAULT NULL COMMENT 'Thời điểm gửi thành công',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Hàng đợi gửi email';

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `employee_activity_logs`
--

CREATE TABLE `employee_activity_logs` (
  `log_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `action` varchar(100) NOT NULL COMMENT 'Action type: admin_password_reset, force_password_change, etc.',
  `details` text DEFAULT NULL COMMENT 'Additional details about the action',
  `performed_by` int(11) DEFAULT NULL COMMENT 'User ID of who performed the action (NULL if system)',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Log all employee management activities for audit trail';

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `inventory`
--

CREATE TABLE `inventory` (
  `inventory_id` int(11) NOT NULL,
  `variant_id` int(11) NOT NULL,
  `warehouse_id` int(11) NOT NULL,
  `location_id` int(11) DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Đang đổ dữ liệu cho bảng `inventory`
--

INSERT INTO `inventory` (`inventory_id`, `variant_id`, `warehouse_id`, `location_id`, `quantity`, `updated_at`) VALUES
(1, 1, 1, NULL, 0, '2025-12-03 17:50:02'),
(2, 2, 3, NULL, 0, '2025-12-04 15:39:41'),
(3, 3, 3, NULL, 0, '2025-12-04 15:39:41'),
(4, 4, 3, NULL, 0, '2025-12-04 15:39:41'),
(5, 5, 3, NULL, 0, '2025-12-04 15:39:41'),
(6, 6, 3, 1, 90, '2025-12-05 03:26:19'),
(8, 7, 3, NULL, 0, '2025-12-05 03:08:35'),
(9, 8, 3, NULL, 0, '2025-12-05 03:08:35'),
(10, 9, 3, 2, 100, '2025-12-05 03:25:12');

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `locations`
--

CREATE TABLE `locations` (
  `location_id` int(11) NOT NULL,
  `warehouse_id` int(11) NOT NULL,
  `shelf_code` varchar(50) DEFAULT NULL,
  `type` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Đang đổ dữ liệu cho bảng `locations`
--

INSERT INTO `locations` (`location_id`, `warehouse_id`, `shelf_code`, `type`, `description`, `is_active`, `created_at`) VALUES
(1, 3, 'SNEAKER-K1-T1-P1', 'Sneaker', 'Khu vực Sneaker, kệ 1, tầng 1, vị trí 1', 1, '2025-12-04 15:44:15'),
(2, 3, 'SNEAKER-K1-T1-P2', 'Sneaker', 'Khu vực Sneaker, kệ 1, tầng 1, vị trí 2', 1, '2025-12-04 15:44:15'),
(3, 3, 'SNEAKER-K1-T1-P3', 'Sneaker', 'Khu vực Sneaker, kệ 1, tầng 1, vị trí 3', 1, '2025-12-04 15:44:15'),
(4, 3, 'SNEAKER-K1-T2-P1', 'Sneaker', 'Khu vực Sneaker, kệ 1, tầng 2, vị trí 1', 1, '2025-12-04 15:44:15'),
(5, 3, 'SNEAKER-K1-T2-P2', 'Sneaker', 'Khu vực Sneaker, kệ 1, tầng 2, vị trí 2', 1, '2025-12-04 15:44:15'),
(6, 3, 'SNEAKER-K1-T2-P3', 'Sneaker', 'Khu vực Sneaker, kệ 1, tầng 2, vị trí 3', 1, '2025-12-04 15:44:15'),
(7, 3, 'SNEAKER-K1-T3-P1', 'Sneaker', 'Khu vực Sneaker, kệ 1, tầng 3, vị trí 1', 1, '2025-12-04 15:44:15'),
(8, 3, 'SNEAKER-K1-T3-P2', 'Sneaker', 'Khu vực Sneaker, kệ 1, tầng 3, vị trí 2', 1, '2025-12-04 15:44:15'),
(9, 3, 'SNEAKER-K1-T3-P3', 'Sneaker', 'Khu vực Sneaker, kệ 1, tầng 3, vị trí 3', 1, '2025-12-04 15:44:15'),
(10, 3, 'SNEAKER-K1-T4-P1', 'Sneaker', 'Khu vực Sneaker, kệ 1, tầng 4, vị trí 1', 1, '2025-12-04 15:44:15'),
(11, 3, 'SNEAKER-K1-T4-P2', 'Sneaker', 'Khu vực Sneaker, kệ 1, tầng 4, vị trí 2', 1, '2025-12-04 15:44:15'),
(12, 3, 'SNEAKER-K1-T4-P3', 'Sneaker', 'Khu vực Sneaker, kệ 1, tầng 4, vị trí 3', 1, '2025-12-04 15:44:15'),
(13, 3, 'SNEAKER-K2-T1-P1', 'Sneaker', 'Khu vực Sneaker, kệ 2, tầng 1, vị trí 1', 1, '2025-12-04 15:44:15'),
(14, 3, 'SNEAKER-K2-T1-P2', 'Sneaker', 'Khu vực Sneaker, kệ 2, tầng 1, vị trí 2', 1, '2025-12-04 15:44:15'),
(15, 3, 'SNEAKER-K2-T1-P3', 'Sneaker', 'Khu vực Sneaker, kệ 2, tầng 1, vị trí 3', 1, '2025-12-04 15:44:15'),
(16, 3, 'SNEAKER-K2-T2-P1', 'Sneaker', 'Khu vực Sneaker, kệ 2, tầng 2, vị trí 1', 1, '2025-12-04 15:44:15'),
(17, 3, 'SNEAKER-K2-T2-P2', 'Sneaker', 'Khu vực Sneaker, kệ 2, tầng 2, vị trí 2', 1, '2025-12-04 15:44:15'),
(18, 3, 'SNEAKER-K2-T2-P3', 'Sneaker', 'Khu vực Sneaker, kệ 2, tầng 2, vị trí 3', 1, '2025-12-04 15:44:15'),
(19, 3, 'SNEAKER-K2-T3-P1', 'Sneaker', 'Khu vực Sneaker, kệ 2, tầng 3, vị trí 1', 1, '2025-12-04 15:44:15'),
(20, 3, 'SNEAKER-K2-T3-P2', 'Sneaker', 'Khu vực Sneaker, kệ 2, tầng 3, vị trí 2', 1, '2025-12-04 15:44:15'),
(21, 3, 'SNEAKER-K2-T3-P3', 'Sneaker', 'Khu vực Sneaker, kệ 2, tầng 3, vị trí 3', 1, '2025-12-04 15:44:15'),
(22, 3, 'SNEAKER-K2-T4-P1', 'Sneaker', 'Khu vực Sneaker, kệ 2, tầng 4, vị trí 1', 1, '2025-12-04 15:44:15'),
(23, 3, 'SNEAKER-K2-T4-P2', 'Sneaker', 'Khu vực Sneaker, kệ 2, tầng 4, vị trí 2', 1, '2025-12-04 15:44:15'),
(24, 3, 'SNEAKER-K2-T4-P3', 'Sneaker', 'Khu vực Sneaker, kệ 2, tầng 4, vị trí 3', 1, '2025-12-04 15:44:15'),
(25, 3, 'SNEAKER-K3-T1-P1', 'Sneaker', 'Khu vực Sneaker, kệ 3, tầng 1, vị trí 1', 1, '2025-12-04 15:44:15'),
(26, 3, 'SNEAKER-K3-T1-P2', 'Sneaker', 'Khu vực Sneaker, kệ 3, tầng 1, vị trí 2', 1, '2025-12-04 15:44:15'),
(27, 3, 'SNEAKER-K3-T1-P3', 'Sneaker', 'Khu vực Sneaker, kệ 3, tầng 1, vị trí 3', 1, '2025-12-04 15:44:15'),
(28, 3, 'SNEAKER-K3-T2-P1', 'Sneaker', 'Khu vực Sneaker, kệ 3, tầng 2, vị trí 1', 1, '2025-12-04 15:44:15'),
(29, 3, 'SNEAKER-K3-T2-P2', 'Sneaker', 'Khu vực Sneaker, kệ 3, tầng 2, vị trí 2', 1, '2025-12-04 15:44:15'),
(30, 3, 'SNEAKER-K3-T2-P3', 'Sneaker', 'Khu vực Sneaker, kệ 3, tầng 2, vị trí 3', 1, '2025-12-04 15:44:15'),
(31, 3, 'SNEAKER-K3-T3-P1', 'Sneaker', 'Khu vực Sneaker, kệ 3, tầng 3, vị trí 1', 1, '2025-12-04 15:44:15'),
(32, 3, 'SNEAKER-K3-T3-P2', 'Sneaker', 'Khu vực Sneaker, kệ 3, tầng 3, vị trí 2', 1, '2025-12-04 15:44:15'),
(33, 3, 'SNEAKER-K3-T3-P3', 'Sneaker', 'Khu vực Sneaker, kệ 3, tầng 3, vị trí 3', 1, '2025-12-04 15:44:15'),
(34, 3, 'SNEAKER-K3-T4-P1', 'Sneaker', 'Khu vực Sneaker, kệ 3, tầng 4, vị trí 1', 1, '2025-12-04 15:44:15'),
(35, 3, 'SNEAKER-K3-T4-P2', 'Sneaker', 'Khu vực Sneaker, kệ 3, tầng 4, vị trí 2', 1, '2025-12-04 15:44:15'),
(36, 3, 'SNEAKER-K3-T4-P3', 'Sneaker', 'Khu vực Sneaker, kệ 3, tầng 4, vị trí 3', 1, '2025-12-04 15:44:15'),
(37, 3, 'SNEAKER-K4-T1-P1', 'Sneaker', 'Khu vực Sneaker, kệ 4, tầng 1, vị trí 1', 1, '2025-12-04 15:44:15'),
(38, 3, 'SNEAKER-K4-T1-P2', 'Sneaker', 'Khu vực Sneaker, kệ 4, tầng 1, vị trí 2', 1, '2025-12-04 15:44:15'),
(39, 3, 'SNEAKER-K4-T1-P3', 'Sneaker', 'Khu vực Sneaker, kệ 4, tầng 1, vị trí 3', 1, '2025-12-04 15:44:15'),
(40, 3, 'SNEAKER-K4-T2-P1', 'Sneaker', 'Khu vực Sneaker, kệ 4, tầng 2, vị trí 1', 1, '2025-12-04 15:44:15'),
(41, 3, 'SNEAKER-K4-T2-P2', 'Sneaker', 'Khu vực Sneaker, kệ 4, tầng 2, vị trí 2', 1, '2025-12-04 15:44:15'),
(42, 3, 'SNEAKER-K4-T2-P3', 'Sneaker', 'Khu vực Sneaker, kệ 4, tầng 2, vị trí 3', 1, '2025-12-04 15:44:15'),
(43, 3, 'SNEAKER-K4-T3-P1', 'Sneaker', 'Khu vực Sneaker, kệ 4, tầng 3, vị trí 1', 1, '2025-12-04 15:44:15'),
(44, 3, 'SNEAKER-K4-T3-P2', 'Sneaker', 'Khu vực Sneaker, kệ 4, tầng 3, vị trí 2', 1, '2025-12-04 15:44:15'),
(45, 3, 'SNEAKER-K4-T3-P3', 'Sneaker', 'Khu vực Sneaker, kệ 4, tầng 3, vị trí 3', 1, '2025-12-04 15:44:15'),
(46, 3, 'SNEAKER-K4-T4-P1', 'Sneaker', 'Khu vực Sneaker, kệ 4, tầng 4, vị trí 1', 1, '2025-12-04 15:44:15'),
(47, 3, 'SNEAKER-K4-T4-P2', 'Sneaker', 'Khu vực Sneaker, kệ 4, tầng 4, vị trí 2', 1, '2025-12-04 15:44:15'),
(48, 3, 'SNEAKER-K4-T4-P3', 'Sneaker', 'Khu vực Sneaker, kệ 4, tầng 4, vị trí 3', 1, '2025-12-04 15:44:15');

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `orders`
--

CREATE TABLE `orders` (
  `order_id` int(11) NOT NULL,
  `warehouse_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `status` enum('pending','accepted','canceled','waiting_delivery','delivered','failed') DEFAULT 'pending',
  `cancellation_reason` varchar(255) DEFAULT NULL COMMENT 'Lý do hủy đơn hàng',
  `discount` decimal(5,2) DEFAULT 0.00,
  `total_price` decimal(12,2) DEFAULT 0.00,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Đang đổ dữ liệu cho bảng `orders`
--

INSERT INTO `orders` (`order_id`, `warehouse_id`, `customer_id`, `status`, `cancellation_reason`, `discount`, `total_price`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 3, 1, 'delivered', NULL, 0.00, 500000.00, 3, '2025-12-05 02:28:54', '2025-12-05 02:31:42'),
(2, 3, 2, 'delivered', NULL, 10.00, 4500000.00, 3, '2025-12-05 03:26:12', '2025-12-05 03:29:08');

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `order_details`
--

CREATE TABLE `order_details` (
  `order_detail_id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `variant_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `unit_price` decimal(12,2) NOT NULL,
  `total_price` decimal(12,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Đang đổ dữ liệu cho bảng `order_details`
--

INSERT INTO `order_details` (`order_detail_id`, `order_id`, `variant_id`, `quantity`, `unit_price`, `total_price`) VALUES
(1, 1, 6, 1, 500000.00, 500000.00),
(2, 2, 6, 10, 500000.00, 5000000.00);

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `password_history`
--

CREATE TABLE `password_history` (
  `history_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Lịch sử mật khẩu - tránh tái sử dụng mật khẩu cũ';

--
-- Đang đổ dữ liệu cho bảng `password_history`
--

INSERT INTO `password_history` (`history_id`, `user_id`, `password_hash`, `changed_at`) VALUES
(1, 4, '$2y$10$Q3LDlLFcII45WsC2qfcr6OOT/gdaNO20WwgmwuFYqujP1vud0wDAW', '2025-12-05 03:10:07');

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `products`
--

CREATE TABLE `products` (
  `product_id` int(11) NOT NULL,
  `warehouse_id` int(11) NOT NULL DEFAULT 1,
  `name` varchar(500) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `ai_analyzed` tinyint(1) DEFAULT 0,
  `brand` varchar(100) DEFAULT NULL,
  `type` varchar(100) DEFAULT NULL,
  `material` varchar(100) DEFAULT NULL,
  `features` text DEFAULT NULL,
  `tags` text DEFAULT NULL,
  `status` enum('active','inactive','deleted') DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Đang đổ dữ liệu cho bảng `products`
--

INSERT INTO `products` (`product_id`, `warehouse_id`, `name`, `description`, `created_at`, `ai_analyzed`, `brand`, `type`, `material`, `features`, `tags`, `status`) VALUES
(1, 1, 'Giày cao gót CHARLES & KEITH Thiết kế mũi nhọn; Quai hậu slingback có thể điều chỉnh; Quai vắt chéo mu bàn chân; Khóa cài vàng kim; Gót nhọn thanh mảnh; Chất liệu bóng bẩy; Lót giày in logo thương hiệu. Đỏ burgundy, Đen, Vàng kim', 'Đôi giày cao gót mũi nhọn với thiết kế quai kép tinh tế, bao gồm một quai hậu slingback và một quai vắt chéo qua mu bàn chân, cả hai đều có khóa cài màu vàng kim có thể điều chỉnh. Giày có màu đỏ burgundy bóng bẩy, tạo vẻ ngoài sang trọng và nổi bật. Gót nhọn thanh mảnh và lót giày màu đen có in logo thương hiệu hoàn thiện phong cách.', '2025-12-03 17:50:02', 1, 'CHARLES & KEITH', 'Giày cao gót', 'Da bóng', 'Thiết kế mũi nhọn; Quai hậu slingback có thể điều chỉnh; Quai vắt chéo mu bàn chân; Khóa cài vàng kim; Gót nhọn thanh mảnh; Chất liệu bóng bẩy; Lót giày in logo thương hiệu.', 'Giày cao gót, Mũi nhọn, Quai hậu, Slingback, Quai kép, Đỏ burgundy, Bóng, Sang trọng, Thời trang, Gót nhọn, CHARLES & KEITH', 'active'),
(2, 3, 'Sneaker SECOND SUNDAY Thiết kế cổ thấp, buộc dây, sọc sóng tương phản, đế ngoài cao su chống trượt, logo thương hiệu trên lưỡi gà và gót. Trắng, Đen', 'Đôi giày sneaker cổ thấp của thương hiệu SECOND SUNDAY có thiết kế thể thao cổ điển với tông màu trắng chủ đạo và các chi tiết màu đen tương phản. Thân giày màu trắng trơn được nhấn nhá bằng sọc sóng màu đen đặc trưng ở hai bên hông. Phần mũi giày tròn, hệ thống dây buộc màu trắng và lưỡi gà có logo thương hiệu. Đế ngoài bằng cao su màu đen có rãnh chống trượt, kéo dài lên phần gót và mũi giày, mang lại vẻ ngoài năng động và độ bám tốt. Logo \'SS\' cách điệu cũng xuất hiện trên lưỡi gà và gót giày.', '2025-12-04 15:39:41', 1, 'SECOND SUNDAY', 'Sneaker', 'Thân giày: Da tổng hợp/PU; Đế ngoài: Cao su', 'Thiết kế cổ thấp, buộc dây, sọc sóng tương phản, đế ngoài cao su chống trượt, logo thương hiệu trên lưỡi gà và gót.', 'Sneaker, Giày thể thao, Giày cổ thấp, Giày buộc dây, Giày trắng, Giày đen, Phong cách retro, Giày casual, SECOND SUNDAY', 'active'),
(3, 3, 'Sneaker DILY Đế chunky, Thân giày thoáng khí, Đế chống trượt, Thiết kế năng động Trắng kem, Nâu nhạt, Nâu', 'Giày sneaker nữ DILY AG0137 nổi bật với thiết kế đế chunky hiện đại, mang lại vẻ ngoài năng động và cá tính. Thân giày được phối màu trắng kem chủ đạo, kết hợp hài hòa với các chi tiết sọc và viền màu nâu nhạt tinh tế. Phần mũi và các mặt bên sử dụng chất liệu vải lưới thoáng khí, kết hợp với các lớp phủ da tổng hợp chắc chắn. Đế giày cao su dày dặn, có rãnh chống trượt, đảm bảo độ bám và sự thoải mái khi di chuyển. Logo DILY được đặt ở lưỡi gà và lót giày, khẳng định thương hiệu.', '2025-12-05 02:21:13', 1, 'DILY', 'Sneaker', 'Vải lưới, Da tổng hợp, Cao su', 'Đế chunky, Thân giày thoáng khí, Đế chống trượt, Thiết kế năng động', 'Giày sneaker, DILY, AG0137, Giày nữ, Giày thể thao, Đế chunky, Trắng kem, Nâu nhạt, Thoáng khí, Phong cách năng động', 'active'),
(4, 3, 'Giày cao gót JEREMY Đế đúp, Gót nhọn cao, Quai đính đá lấp lánh, Khóa kéo sau gót, Thiết kế quai chéo Đỏ, Bạc, Trắng, Nâu', 'Đôi giày cao gót nữ của thương hiệu Jeremy, nổi bật với tông màu đỏ rực rỡ và chất liệu vải satin bóng bẩy. Thiết kế đế đúp dày dặn kết hợp gót nhọn cao tạo dáng vẻ thanh lịch và quyến rũ. Phần quai giày được trang trí bằng những dải đá pha lê lấp lánh, bao gồm một quai ngang ở mũi chân và các quai chéo phức tạp ôm lấy mu bàn chân, nối liền với quai hậu có khóa kéo tiện lợi ở phía sau. Lót giày có in logo \'JEREMY\' màu trắng trên nền bạc, mang lại sự sang trọng và nhận diện thương hiệu rõ ràng.', '2025-12-05 03:08:35', 1, 'JEREMY', 'Giày cao gót', 'Vải satin, Đá pha lê, Cao su', 'Đế đúp, Gót nhọn cao, Quai đính đá lấp lánh, Khóa kéo sau gót, Thiết kế quai chéo', 'Giày cao gót, Giày nữ, Giày Jeremy, Giày đỏ, Giày satin, Giày đính đá, Giày đế đúp, Giày gót nhọn, Giày dự tiệc, Giày dạ hội', 'active');

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `product_images`
--

CREATE TABLE `product_images` (
  `image_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `file_path` varchar(512) NOT NULL,
  `is_primary` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `warehouse_id` int(11) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Đang đổ dữ liệu cho bảng `product_images`
--

INSERT INTO `product_images` (`image_id`, `product_id`, `file_path`, `is_primary`, `created_at`, `warehouse_id`) VALUES
(1, 1, 'uploads/products/product_6930783380fb3.webp', 1, '2025-12-03 17:50:02', 1),
(2, 1, 'uploads/products/product_6930783381aff.webp', 0, '2025-12-03 17:50:02', 1),
(3, 2, 'uploads/products/product_6931aa9ad4f57.png', 1, '2025-12-04 15:39:41', 1),
(4, 2, 'uploads/products/product_6931aa9ad5645.png', 0, '2025-12-04 15:39:41', 1),
(5, 2, 'uploads/products/product_6931aa9ad5c7f.png', 0, '2025-12-04 15:39:41', 1),
(8, 4, 'uploads/products/product_69324c7e86978.jpg', 1, '2025-12-05 03:08:35', 1),
(9, 4, 'uploads/products/product_69324c7e87285.jpg', 0, '2025-12-05 03:08:35', 1),
(12, 3, 'uploads/products/product_69324ffcc4bfe.jpg', 1, '2025-12-05 03:23:35', 3),
(13, 3, 'uploads/products/product_69324ffcc5955.jpg', 0, '2025-12-05 03:23:35', 3);

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `product_qr_codes`
--

CREATE TABLE `product_qr_codes` (
  `qr_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `variant_id` int(11) DEFAULT NULL,
  `qr_code` varchar(255) NOT NULL,
  `qr_image_path` varchar(500) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `location_code` varchar(50) DEFAULT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `warehouse_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Đang đổ dữ liệu cho bảng `product_qr_codes`
--

INSERT INTO `product_qr_codes` (`qr_id`, `product_id`, `variant_id`, `qr_code`, `qr_image_path`, `is_active`, `created_by`, `created_at`, `location_code`, `supplier_id`, `warehouse_id`) VALUES
(1, 3, 6, '{\"type\":\"product\",\"variant_id\":\"6\",\"sku\":\"DILY-SNK-184-40\",\"uuid\":\"c84ded44-32ee-4221-9643-ae1a8ce85f03\"}', 'uploads/qr/qr_c84ded44-32ee-4221-9643-ae1a8ce85f03.png', 1, 3, '2025-12-05 02:26:15', 'SNEAKER-K1-T1-P1', 1, 3),
(2, 3, 9, '{\"type\":\"product\",\"variant_id\":\"9\",\"sku\":\"DILY-SNK-184-45\",\"uuid\":\"f443a19a-e286-41d9-b3ea-1d8e8e26bd46\"}', 'uploads/qr/qr_f443a19a-e286-41d9-b3ea-1d8e8e26bd46.png', 1, 3, '2025-12-05 03:25:13', 'SNEAKER-K1-T1-P2', 2, 3);

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `product_variants`
--

CREATE TABLE `product_variants` (
  `variant_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `sku` varchar(100) NOT NULL,
  `color` varchar(50) DEFAULT NULL,
  `size` varchar(20) DEFAULT NULL,
  `price` decimal(12,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `warehouse_id` int(11) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Đang đổ dữ liệu cho bảng `product_variants`
--

INSERT INTO `product_variants` (`variant_id`, `product_id`, `sku`, `color`, `size`, `price`, `created_at`, `updated_at`, `warehouse_id`) VALUES
(1, 1, 'CHAR-HIGH-953-43', 'Đỏ burgundy, Đen, Vàng kim', '43', 0.00, '2025-12-03 17:50:02', '2025-12-03 17:50:02', 1),
(2, 2, 'SECO-SNK-569-35', 'Trắng, Đen', '35', 200000.00, '2025-12-04 15:39:41', '2025-12-04 15:39:41', 3),
(3, 2, 'SECO-SNK-569-36', 'Trắng, Đen', '36', 200000.00, '2025-12-04 15:39:41', '2025-12-04 15:39:41', 3),
(4, 2, 'SECO-SNK-569-37', 'Trắng, Đen', '37', 200000.00, '2025-12-04 15:39:41', '2025-12-04 15:39:41', 3),
(5, 2, 'SECO-SNK-569-39', 'Trắng, Đen', '39', 20000.00, '2025-12-04 15:39:41', '2025-12-04 15:39:41', 3),
(6, 3, 'DILY-SNK-184-40', 'Trắng kem, Nâu nhạt, Nâu', '40', 500000.00, '2025-12-05 02:21:13', '2025-12-05 03:23:34', 3),
(7, 4, 'JERE-HIGH-531-45', 'Đỏ, Bạc, Trắng, Nâu', '45', 450000.00, '2025-12-05 03:08:35', '2025-12-05 03:08:35', 3),
(8, 4, 'JERE-HIGH-531-46', 'Đỏ, Bạc, Trắng, Nâu', '46', 450000.00, '2025-12-05 03:08:35', '2025-12-05 03:08:35', 3),
(9, 3, 'DILY-SNK-184-45', 'Trắng kem, Nâu nhạt, Nâu', '45', 500000.00, '2025-12-05 03:23:34', '2025-12-05 03:23:34', 3);

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `stock_receipts`
--

CREATE TABLE `stock_receipts` (
  `receipt_id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `warehouse_id` int(11) NOT NULL,
  `status` enum('draft','pending','confirmed','rejected') DEFAULT 'draft',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `notes` text DEFAULT NULL,
  `last_modified_by` int(11) DEFAULT NULL,
  `last_modified_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `confirmed_at` timestamp NULL DEFAULT NULL,
  `confirmed_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Đang đổ dữ liệu cho bảng `stock_receipts`
--

INSERT INTO `stock_receipts` (`receipt_id`, `supplier_id`, `user_id`, `warehouse_id`, `status`, `created_at`, `notes`, `last_modified_by`, `last_modified_at`, `confirmed_at`, `confirmed_by`) VALUES
(1, 1, 3, 3, 'confirmed', '2025-12-05 02:25:53', 'Xghh', NULL, '2025-12-05 02:26:14', '2025-12-05 02:26:14', 3),
(2, 2, 3, 3, 'confirmed', '2025-12-05 03:25:04', 'sdfg', NULL, '2025-12-05 03:25:12', '2025-12-05 03:25:12', 3);

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `stock_receipt_history`
--

CREATE TABLE `stock_receipt_history` (
  `history_id` int(11) NOT NULL,
  `receipt_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `action_type` enum('create','update','confirm','delete') NOT NULL,
  `change_reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `warehouse_id` int(11) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Đang đổ dữ liệu cho bảng `stock_receipt_history`
--

INSERT INTO `stock_receipt_history` (`history_id`, `receipt_id`, `user_id`, `action_type`, `change_reason`, `created_at`, `warehouse_id`) VALUES
(1, 1, 3, 'confirm', 'Xác nhận phiếu nhập kho', '2025-12-05 02:26:15', 3),
(2, 2, 3, 'confirm', 'Xác nhận phiếu nhập kho', '2025-12-05 03:25:13', 3);

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `stock_receipt_items`
--

CREATE TABLE `stock_receipt_items` (
  `receipt_item_id` int(11) NOT NULL,
  `receipt_id` int(11) NOT NULL,
  `variant_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `unit_price` decimal(12,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `location_code` varchar(50) DEFAULT NULL,
  `location_id` int(11) DEFAULT NULL,
  `warehouse_id` int(11) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Đang đổ dữ liệu cho bảng `stock_receipt_items`
--

INSERT INTO `stock_receipt_items` (`receipt_item_id`, `receipt_id`, `variant_id`, `quantity`, `unit_price`, `created_at`, `location_code`, `location_id`, `warehouse_id`) VALUES
(1, 1, 6, 1, 97000.00, '2025-12-05 02:25:53', 'SNEAKER-K1-T1-P1', 1, 3),
(2, 2, 6, 100, 97000.00, '2025-12-05 03:25:04', 'SNEAKER-K1-T1-P1', 1, 3),
(3, 2, 9, 100, 97000.00, '2025-12-05 03:25:04', 'SNEAKER-K1-T1-P2', 2, 3);

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `suppliers`
--

CREATE TABLE `suppliers` (
  `supplier_id` int(11) NOT NULL,
  `warehouse_id` int(11) DEFAULT NULL,
  `name` varchar(200) NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `short_name` varchar(20) DEFAULT NULL,
  `type` varchar(50) DEFAULT NULL,
  `province` varchar(50) DEFAULT NULL,
  `district` varchar(50) DEFAULT NULL,
  `website` varchar(100) DEFAULT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `contact_position` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `supplier_code` varchar(20) NOT NULL,
  `tax_code` varchar(15) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Đang đổ dữ liệu cho bảng `suppliers`
--

INSERT INTO `suppliers` (`supplier_id`, `warehouse_id`, `name`, `phone`, `email`, `address`, `created_at`, `short_name`, `type`, `province`, `district`, `website`, `contact_person`, `contact_position`, `notes`, `status`, `updated_at`, `created_by`, `updated_by`, `supplier_code`, `tax_code`) VALUES
(1, 3, 'Nguyễn Thị An', '0337462385', 'cnttkhoaluan@gmail.com', 'Hồ Chí Minh', '2025-12-04 15:41:47', 'MCB', 'Hợp tác xã', 'TP. Hồ Chí Minh', 'q12', '', '', '', '', 'active', '2025-12-04 15:41:47', 3, 3, 'NCC20251204001', '0123455555555'),
(2, 3, 'Bảo', '0589100422', 'bao12003hcm@gmail.com', '158/65 phạm văn chieu', '2025-12-04 15:52:06', 'Beo', 'Cá nhân kinh doanh', 'TP. Hồ Chí Minh', 'gò vấp', 'https://s.shopee.vn/2qNG8aIZDU', '', '', '', 'active', '2025-12-04 15:52:06', 3, 3, 'NCC20251204002', '0123457789');

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `full_name` varchar(100) DEFAULT NULL,
  `employee_code` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `role` enum('admin','manager','staff') DEFAULT 'staff' COMMENT 'Vai trò: admin, manager (quản lý kho), staff (nhân viên)',
  `warehouse_id` int(11) DEFAULT NULL,
  `status` enum('pending','active','inactive') DEFAULT 'active' COMMENT 'Trạng thái: pending (chờ kích hoạt), active (hoạt động), inactive (vô hiệu hóa)',
  `last_login` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `must_change_password` tinyint(1) DEFAULT 0 COMMENT 'Bắt buộc đổi mật khẩu lần đầu đăng nhập',
  `password_changed_at` timestamp NULL DEFAULT NULL COMMENT 'Thời điểm đổi mật khẩu gần nhất',
  `failed_login_attempts` int(11) DEFAULT 0 COMMENT 'Số lần đăng nhập thất bại liên tiếp',
  `locked_until` timestamp NULL DEFAULT NULL COMMENT 'Thời điểm hết khóa tài khoản',
  `deactivated_at` timestamp NULL DEFAULT NULL COMMENT 'Thời điểm vô hiệu hóa',
  `activated_at` timestamp NULL DEFAULT NULL COMMENT 'Thời điểm kích hoạt lại'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Đang đổ dữ liệu cho bảng `users`
--

INSERT INTO `users` (`user_id`, `username`, `password_hash`, `full_name`, `employee_code`, `email`, `phone`, `role`, `warehouse_id`, `status`, `last_login`, `created_at`, `updated_at`, `must_change_password`, `password_changed_at`, `failed_login_attempts`, `locked_until`, `deactivated_at`, `activated_at`) VALUES
(1, 'btmchucmckho001', '$2y$10$KAzjO.4n7at5WQTQVJQBx.Ae98ZjBEyWGFDYrffRRBOPyWQhKFRfe', 'Bùi Thị Mai Chúc', 'EMP0001', 'cnttkhoaluan@gmail.com', '0333333456', 'manager', 1, 'active', '2025-12-03 17:47:53', '2025-12-03 17:47:34', '2025-12-03 17:47:53', 0, NULL, 0, NULL, NULL, NULL),
(3, 'chuchochiminh003', '$2y$10$YIuM5SkWx2ZCSO0J1RrSBea36Z.OYCePx9ew3luLS1PgfmcrPeW6y', 'chuc', 'EMP0002', 'maiichucc071023@gmail.com', '0337462385', 'manager', 3, 'active', '2025-12-06 03:04:41', '2025-12-03 21:02:25', '2025-12-06 03:04:41', 0, NULL, 0, NULL, NULL, NULL),
(4, 'dtnhihochiminh', '$2y$10$Q3LDlLFcII45WsC2qfcr6OOT/gdaNO20WwgmwuFYqujP1vud0wDAW', 'Dinh Tuyet NHi', 'EMP0003', 'nhichuc260@gmail.com', '0964546579', 'staff', 3, 'pending', '2025-12-05 03:21:24', '2025-12-05 03:10:07', '2025-12-05 03:21:24', 1, NULL, 0, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `warehouses`
--

CREATE TABLE `warehouses` (
  `warehouse_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `address` text DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Đang đổ dữ liệu cho bảng `warehouses`
--

INSERT INTO `warehouses` (`warehouse_id`, `name`, `address`, `status`, `created_at`, `updated_at`) VALUES
(1, 'MC_KHO', 'm', 'active', '2025-12-03 17:47:34', '2025-12-03 17:47:34'),
(3, 'Hồ Chí Minh', 'q12', 'active', '2025-12-03 21:02:25', '2025-12-03 21:02:25');

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `warehouse_exports`
--

CREATE TABLE `warehouse_exports` (
  `export_id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `warehouse_id` int(11) NOT NULL,
  `export_code` varchar(50) NOT NULL,
  `customer_name` varchar(255) DEFAULT NULL,
  `status` enum('pending','ready_pickup','processing','completed','confirmed','cancelled') DEFAULT 'pending',
  `processed_by` int(11) DEFAULT NULL,
  `completed_by` int(11) DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `confirmed_delivery_at` timestamp NULL DEFAULT NULL,
  `confirmed_delivery_by` int(11) DEFAULT NULL,
  `cancel_reason` text DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `cancelled_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Đang đổ dữ liệu cho bảng `warehouse_exports`
--

INSERT INTO `warehouse_exports` (`export_id`, `order_id`, `warehouse_id`, `export_code`, `customer_name`, `status`, `processed_by`, `completed_by`, `completed_at`, `created_at`, `updated_at`, `confirmed_delivery_at`, `confirmed_delivery_by`, `cancel_reason`, `cancelled_at`, `cancelled_by`) VALUES
(1, 1, 3, 'EXP202512050001', 'Fhh', 'confirmed', 3, 3, '2025-12-05 02:30:47', '2025-12-05 02:29:19', '2025-12-05 02:31:30', '2025-12-05 02:31:30', 3, NULL, NULL, NULL),
(2, 2, 3, 'EXP202512050002', 'Tus', 'confirmed', 3, 3, '2025-12-05 03:28:50', '2025-12-05 03:26:19', '2025-12-05 03:28:56', '2025-12-05 03:28:56', 3, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `warehouse_export_details`
--

CREATE TABLE `warehouse_export_details` (
  `detail_id` int(11) NOT NULL,
  `export_id` int(11) NOT NULL,
  `variant_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `picked_quantity` int(11) DEFAULT 0,
  `picked_by` int(11) DEFAULT NULL,
  `picked_at` timestamp NULL DEFAULT NULL,
  `unit_price` decimal(15,2) NOT NULL,
  `total_price` decimal(15,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Đang đổ dữ liệu cho bảng `warehouse_export_details`
--

INSERT INTO `warehouse_export_details` (`detail_id`, `export_id`, `variant_id`, `quantity`, `picked_quantity`, `picked_by`, `picked_at`, `unit_price`, `total_price`, `created_at`) VALUES
(1, 1, 6, 1, 1, 3, '2025-12-05 02:30:39', 500000.00, 500000.00, '2025-12-05 02:29:19'),
(2, 2, 6, 10, 10, 3, '2025-12-05 03:28:45', 500000.00, 5000000.00, '2025-12-05 03:26:19');

--
-- Chỉ mục cho các bảng đã đổ
--

--
-- Chỉ mục cho bảng `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_action` (`action`),
  ADD KEY `idx_warehouse_user` (`warehouse_id`,`user_id`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Chỉ mục cho bảng `bulk_import_logs`
--
ALTER TABLE `bulk_import_logs`
  ADD PRIMARY KEY (`import_id`),
  ADD KEY `idx_warehouse_id` (`warehouse_id`),
  ADD KEY `idx_imported_by` (`imported_by`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Chỉ mục cho bảng `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`customer_id`),
  ADD UNIQUE KEY `phone` (`phone`),
  ADD KEY `idx_customers_warehouse` (`warehouse_id`);

--
-- Chỉ mục cho bảng `email_logs`
--
ALTER TABLE `email_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_recipient` (`recipient_email`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_sent_at` (`sent_at`);

--
-- Chỉ mục cho bảng `email_queue`
--
ALTER TABLE `email_queue`
  ADD PRIMARY KEY (`queue_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_email_type` (`email_type`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_related_import` (`related_import_id`);

--
-- Chỉ mục cho bảng `employee_activity_logs`
--
ALTER TABLE `employee_activity_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_performed_by` (`performed_by`),
  ADD KEY `idx_action` (`action`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Chỉ mục cho bảng `inventory`
--
ALTER TABLE `inventory`
  ADD PRIMARY KEY (`inventory_id`),
  ADD UNIQUE KEY `unique_variant_warehouse` (`variant_id`,`warehouse_id`),
  ADD KEY `idx_variant_id` (`variant_id`),
  ADD KEY `idx_warehouse_id` (`warehouse_id`),
  ADD KEY `idx_inventory_warehouse` (`warehouse_id`);

--
-- Chỉ mục cho bảng `locations`
--
ALTER TABLE `locations`
  ADD PRIMARY KEY (`location_id`),
  ADD KEY `warehouse_id` (`warehouse_id`);

--
-- Chỉ mục cho bảng `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`order_id`),
  ADD KEY `warehouse_id` (`warehouse_id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Chỉ mục cho bảng `order_details`
--
ALTER TABLE `order_details`
  ADD PRIMARY KEY (`order_detail_id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `variant_id` (`variant_id`);

--
-- Chỉ mục cho bảng `password_history`
--
ALTER TABLE `password_history`
  ADD PRIMARY KEY (`history_id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_changed_at` (`changed_at`);

--
-- Chỉ mục cho bảng `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`product_id`),
  ADD KEY `idx_ai_analyzed` (`ai_analyzed`),
  ADD KEY `idx_products_warehouse` (`warehouse_id`);

--
-- Chỉ mục cho bảng `product_images`
--
ALTER TABLE `product_images`
  ADD PRIMARY KEY (`image_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Chỉ mục cho bảng `product_qr_codes`
--
ALTER TABLE `product_qr_codes`
  ADD PRIMARY KEY (`qr_id`),
  ADD UNIQUE KEY `qr_code` (`qr_code`),
  ADD KEY `variant_id` (`variant_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_product_id` (`product_id`),
  ADD KEY `idx_qr_code` (`qr_code`),
  ADD KEY `idx_is_active` (`is_active`);

--
-- Chỉ mục cho bảng `product_variants`
--
ALTER TABLE `product_variants`
  ADD PRIMARY KEY (`variant_id`),
  ADD UNIQUE KEY `sku` (`sku`),
  ADD KEY `product_id` (`product_id`);

--
-- Chỉ mục cho bảng `stock_receipts`
--
ALTER TABLE `stock_receipts`
  ADD PRIMARY KEY (`receipt_id`),
  ADD KEY `supplier_id` (`supplier_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `warehouse_id` (`warehouse_id`),
  ADD KEY `idx_stock_receipts_warehouse` (`warehouse_id`);

--
-- Chỉ mục cho bảng `stock_receipt_history`
--
ALTER TABLE `stock_receipt_history`
  ADD PRIMARY KEY (`history_id`),
  ADD KEY `idx_receipt_id` (`receipt_id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Chỉ mục cho bảng `stock_receipt_items`
--
ALTER TABLE `stock_receipt_items`
  ADD PRIMARY KEY (`receipt_item_id`),
  ADD KEY `receipt_id` (`receipt_id`),
  ADD KEY `variant_id` (`variant_id`),
  ADD KEY `idx_location_id` (`location_id`);

--
-- Chỉ mục cho bảng `suppliers`
--
ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`supplier_id`),
  ADD UNIQUE KEY `unique_supplier_code` (`supplier_code`),
  ADD UNIQUE KEY `unique_tax_code` (`tax_code`),
  ADD KEY `idx_suppliers_warehouse` (`warehouse_id`),
  ADD KEY `fk_suppliers_created_by` (`created_by`),
  ADD KEY `fk_suppliers_updated_by` (`updated_by`);

--
-- Chỉ mục cho bảng `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `unique_email` (`email`),
  ADD KEY `warehouse_id` (`warehouse_id`);

--
-- Chỉ mục cho bảng `warehouses`
--
ALTER TABLE `warehouses`
  ADD PRIMARY KEY (`warehouse_id`);

--
-- Chỉ mục cho bảng `warehouse_exports`
--
ALTER TABLE `warehouse_exports`
  ADD PRIMARY KEY (`export_id`),
  ADD UNIQUE KEY `export_code` (`export_code`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `warehouse_id` (`warehouse_id`),
  ADD KEY `status` (`status`),
  ADD KEY `idx_exports_status_warehouse` (`status`,`warehouse_id`),
  ADD KEY `idx_exports_created_at` (`created_at`),
  ADD KEY `fk_warehouse_exports_confirmed_by` (`confirmed_delivery_by`);

--
-- Chỉ mục cho bảng `warehouse_export_details`
--
ALTER TABLE `warehouse_export_details`
  ADD PRIMARY KEY (`detail_id`),
  ADD KEY `export_id` (`export_id`),
  ADD KEY `variant_id` (`variant_id`),
  ADD KEY `idx_export_details_picked` (`picked_quantity`,`quantity`);

--
-- AUTO_INCREMENT cho các bảng đã đổ
--

--
-- AUTO_INCREMENT cho bảng `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT cho bảng `bulk_import_logs`
--
ALTER TABLE `bulk_import_logs`
  MODIFY `import_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT cho bảng `customers`
--
ALTER TABLE `customers`
  MODIFY `customer_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT cho bảng `email_logs`
--
ALTER TABLE `email_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT cho bảng `email_queue`
--
ALTER TABLE `email_queue`
  MODIFY `queue_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT cho bảng `employee_activity_logs`
--
ALTER TABLE `employee_activity_logs`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT cho bảng `inventory`
--
ALTER TABLE `inventory`
  MODIFY `inventory_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT cho bảng `locations`
--
ALTER TABLE `locations`
  MODIFY `location_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=49;

--
-- AUTO_INCREMENT cho bảng `orders`
--
ALTER TABLE `orders`
  MODIFY `order_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT cho bảng `order_details`
--
ALTER TABLE `order_details`
  MODIFY `order_detail_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT cho bảng `password_history`
--
ALTER TABLE `password_history`
  MODIFY `history_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT cho bảng `products`
--
ALTER TABLE `products`
  MODIFY `product_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT cho bảng `product_images`
--
ALTER TABLE `product_images`
  MODIFY `image_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT cho bảng `product_qr_codes`
--
ALTER TABLE `product_qr_codes`
  MODIFY `qr_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT cho bảng `product_variants`
--
ALTER TABLE `product_variants`
  MODIFY `variant_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT cho bảng `stock_receipts`
--
ALTER TABLE `stock_receipts`
  MODIFY `receipt_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT cho bảng `stock_receipt_history`
--
ALTER TABLE `stock_receipt_history`
  MODIFY `history_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT cho bảng `stock_receipt_items`
--
ALTER TABLE `stock_receipt_items`
  MODIFY `receipt_item_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT cho bảng `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `supplier_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT cho bảng `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT cho bảng `warehouses`
--
ALTER TABLE `warehouses`
  MODIFY `warehouse_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT cho bảng `warehouse_exports`
--
ALTER TABLE `warehouse_exports`
  MODIFY `export_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT cho bảng `warehouse_export_details`
--
ALTER TABLE `warehouse_export_details`
  MODIFY `detail_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- Các ràng buộc cho các bảng đã đổ
--

--
-- Các ràng buộc cho bảng `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `audit_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Các ràng buộc cho bảng `bulk_import_logs`
--
ALTER TABLE `bulk_import_logs`
  ADD CONSTRAINT `fk_bulk_import_user` FOREIGN KEY (`imported_by`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_bulk_import_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`warehouse_id`) ON DELETE CASCADE;

--
-- Các ràng buộc cho bảng `customers`
--
ALTER TABLE `customers`
  ADD CONSTRAINT `fk_customers_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`warehouse_id`);

--
-- Các ràng buộc cho bảng `inventory`
--
ALTER TABLE `inventory`
  ADD CONSTRAINT `fk_inventory_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`warehouse_id`);

--
-- Các ràng buộc cho bảng `locations`
--
ALTER TABLE `locations`
  ADD CONSTRAINT `locations_ibfk_1` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`warehouse_id`) ON DELETE CASCADE;

--
-- Các ràng buộc cho bảng `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`warehouse_id`),
  ADD CONSTRAINT `orders_ibfk_2` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`customer_id`),
  ADD CONSTRAINT `orders_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`);

--
-- Các ràng buộc cho bảng `order_details`
--
ALTER TABLE `order_details`
  ADD CONSTRAINT `order_details_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`order_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `order_details_ibfk_2` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`variant_id`);

--
-- Các ràng buộc cho bảng `password_history`
--
ALTER TABLE `password_history`
  ADD CONSTRAINT `password_history_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Các ràng buộc cho bảng `products`
--
ALTER TABLE `products`
  ADD CONSTRAINT `fk_products_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`warehouse_id`);

--
-- Các ràng buộc cho bảng `product_images`
--
ALTER TABLE `product_images`
  ADD CONSTRAINT `product_images_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE;

--
-- Các ràng buộc cho bảng `product_qr_codes`
--
ALTER TABLE `product_qr_codes`
  ADD CONSTRAINT `product_qr_codes_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `product_qr_codes_ibfk_2` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`variant_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `product_qr_codes_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`);

--
-- Các ràng buộc cho bảng `product_variants`
--
ALTER TABLE `product_variants`
  ADD CONSTRAINT `product_variants_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE;

--
-- Các ràng buộc cho bảng `stock_receipts`
--
ALTER TABLE `stock_receipts`
  ADD CONSTRAINT `fk_stock_receipts_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`warehouse_id`),
  ADD CONSTRAINT `stock_receipts_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`supplier_id`),
  ADD CONSTRAINT `stock_receipts_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `stock_receipts_ibfk_3` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`warehouse_id`);

--
-- Các ràng buộc cho bảng `stock_receipt_history`
--
ALTER TABLE `stock_receipt_history`
  ADD CONSTRAINT `stock_receipt_history_ibfk_1` FOREIGN KEY (`receipt_id`) REFERENCES `stock_receipts` (`receipt_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `stock_receipt_history_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Các ràng buộc cho bảng `stock_receipt_items`
--
ALTER TABLE `stock_receipt_items`
  ADD CONSTRAINT `fk_stock_receipt_items_location` FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `stock_receipt_items_ibfk_1` FOREIGN KEY (`receipt_id`) REFERENCES `stock_receipts` (`receipt_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `stock_receipt_items_ibfk_2` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`variant_id`) ON DELETE CASCADE;

--
-- Các ràng buộc cho bảng `suppliers`
--
ALTER TABLE `suppliers`
  ADD CONSTRAINT `fk_suppliers_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_suppliers_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Các ràng buộc cho bảng `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`warehouse_id`) ON DELETE SET NULL;

--
-- Các ràng buộc cho bảng `warehouse_exports`
--
ALTER TABLE `warehouse_exports`
  ADD CONSTRAINT `fk_warehouse_exports_confirmed_by` FOREIGN KEY (`confirmed_delivery_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
