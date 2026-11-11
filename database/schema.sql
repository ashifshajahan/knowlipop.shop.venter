-- ======================================================
-- Vendor App Database Schema (Pure PHP & MySQL)
-- Import this file into phpMyAdmin on Hostinger / cPanel
-- ======================================================

CREATE TABLE IF NOT EXISTS `users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255) NOT NULL UNIQUE,
  `email_verified_at` TIMESTAMP NULL DEFAULT NULL,
  `password` VARCHAR(255) NOT NULL,
  `remember_token` VARCHAR(100) NULL DEFAULT NULL,
  `seller_name` VARCHAR(120) NULL DEFAULT NULL,
  `phone` VARCHAR(30) NULL DEFAULT NULL UNIQUE,
  `address` TEXT NULL DEFAULT NULL,
  `landmark` VARCHAR(200) NULL DEFAULT NULL,
  `state` VARCHAR(100) NULL DEFAULT NULL,
  `country` VARCHAR(100) NULL DEFAULT NULL,
  `avatar_url` LONGTEXT NULL DEFAULT NULL,
  `role` VARCHAR(20) NOT NULL DEFAULT 'seller',
  `account_status` VARCHAR(40) NOT NULL DEFAULT 'ACTIVE',
  `deletion_requested_at` TIMESTAMP NULL DEFAULT NULL,
  `deleted_at` TIMESTAMP NULL DEFAULT NULL,
  `deleted_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `token_version` INT NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_users_role` (`role`),
  INDEX `idx_users_phone` (`phone`),
  INDEX `idx_users_account_status` (`account_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `personal_access_tokens` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tokenable_type` VARCHAR(255) NOT NULL DEFAULT 'App\\Models\\User',
  `tokenable_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(255) NOT NULL DEFAULT 'vendor-app',
  `token` VARCHAR(64) NOT NULL UNIQUE,
  `abilities` TEXT NULL DEFAULT NULL,
  `last_used_at` TIMESTAMP NULL DEFAULT NULL,
  `expires_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_pat_tokenable` (`tokenable_type`, `tokenable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `products` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `seller_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `sku` VARCHAR(100) NULL DEFAULT NULL,
  `category` VARCHAR(100) NULL DEFAULT 'General',
  `category_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `brand` VARCHAR(100) NULL DEFAULT NULL,
  `description` TEXT NULL DEFAULT NULL,
  `seller_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `commission` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `delivery_charge` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `gst` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `other_charges` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `mrp` DECIMAL(10,2) NULL DEFAULT NULL,
  `price` DECIMAL(10,2) NOT NULL,
  `stock` INT NOT NULL DEFAULT 0,
  `rating` DECIMAL(3,2) NOT NULL DEFAULT 4.50,
  `ratings_count` INT NOT NULL DEFAULT 1,
  `image_url` LONGTEXT NULL DEFAULT NULL,
  `images` LONGTEXT NULL DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_products_seller` (`seller_id`),
  INDEX `idx_products_category` (`category_id`),
  INDEX `idx_products_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================================================
-- Product Categories Table (5 Default Editable Categories)
-- ======================================================

CREATE TABLE IF NOT EXISTS `categories` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `slug` VARCHAR(120) NOT NULL UNIQUE,
  `description` VARCHAR(255) NULL DEFAULT NULL,
  `icon` VARCHAR(60) NULL DEFAULT NULL,
  `image_url` LONGTEXT NULL DEFAULT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_categories_active` (`is_active`),
  INDEX `idx_categories_sort` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `categories` (`id`, `name`, `slug`, `description`, `icon`, `sort_order`, `is_active`) VALUES
(1, 'Electronics', 'electronics', 'Smartphones, headphones, gadgets & tech accessories', '📱', 1, 1),
(2, 'Fashion', 'fashion', 'Apparel, footwear, bags, sunglasses & style essentials', '👕', 2, 1),
(3, 'Home & Kitchen', 'home-kitchen', 'Appliances, cookware, home decor & living essentials', '🏠', 3, 1),
(4, 'Beauty & Personal Care', 'beauty-personal-care', 'Skincare, grooming, wellness & cosmetics', '✨', 4, 1),
(5, 'Grocery', 'grocery', 'Daily staples, snacks, beverages & household essentials', '🛒', 5, 1);

CREATE TABLE IF NOT EXISTS `password_reset_otps` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `phone` VARCHAR(255) NULL DEFAULT NULL,
  `email` VARCHAR(255) NULL DEFAULT NULL,
  `channel` VARCHAR(20) NOT NULL DEFAULT 'phone',
  `token` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_otp_phone` (`phone`),
  INDEX `idx_otp_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `seller_payment_accounts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `seller_id` BIGINT UNSIGNED NOT NULL UNIQUE,
  `provider` VARCHAR(50) NOT NULL DEFAULT 'razorpay',
  `account_reference` VARCHAR(120) NOT NULL,
  `status` VARCHAR(50) NOT NULL DEFAULT 'created',
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default Admin Account: admin@vendor.app / Admin123!
INSERT INTO `users` (`id`, `name`, `email`, `password`, `role`)
VALUES (1, 'Vendor Administrator', 'admin@vendor.app', '$2y$10$KqFHH9/yS.H5eLF3TbK/AO/U8w9auESJ1u5LZLCMKO0fNpAlPtnTC', 'admin')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `email` = VALUES(`email`), `password` = VALUES(`password`), `role` = 'admin';

-- Default Starter Products for App & Catalog Testing (with transparent pricing breakdown)
INSERT INTO `products` (`id`, `seller_id`, `name`, `description`, `seller_price`, `commission`, `delivery_charge`, `gst`, `other_charges`, `price`, `stock`, `image_url`, `is_active`)
VALUES
(1, 1, 'Smart Wireless Headset Pro', 'Active noise cancelling over-ear headphones with 40-hour battery life.', 2999.00, 149.95, 50.00, 35.99, 20.00, 3254.94, 50, 'https://knowlipop.shop/uploads/products/placeholder.jpg', 1),
(2, 1, 'Ultra HD 4K Smart Watch', 'AMOLED display smartwatch with heart rate monitor, GPS tracking, and 7-day battery life.', 4499.00, 224.95, 50.00, 49.49, 20.00, 4843.44, 35, 'https://knowlipop.shop/uploads/products/placeholder.jpg', 1),
(3, 1, 'Vintage Leather Travel Backpack', 'Water-resistant genuine leather travel bag with 15.6 inch laptop sleeve and ergonomic straps.', 3299.00, 164.95, 50.00, 38.69, 20.00, 3572.64, 20, 'https://knowlipop.shop/uploads/products/placeholder.jpg', 1),
(4, 1, 'Air Cushion Running Sneakers', 'Lightweight breathable sports shoes designed for marathon running and daily training.', 2499.00, 124.95, 50.00, 31.49, 20.00, 2725.44, 60, 'https://knowlipop.shop/uploads/products/placeholder.jpg', 1),
(5, 1, 'Polarized Aviator Sunglasses', 'UV400 protection titanium frame sunglasses with anti-glare scratch resistant lenses.', 1299.00, 64.95, 50.00, 20.69, 20.00, 1454.64, 100, 'https://knowlipop.shop/uploads/products/placeholder.jpg', 1),
(6, 1, 'RGB Mechanical Gaming Keyboard', 'Custom hot-swappable tactile blue switches with per-key RGB backlighting and aluminum frame.', 3999.00, 199.95, 50.00, 44.99, 20.00, 4313.94, 25, 'https://knowlipop.shop/uploads/products/placeholder.jpg', 1),
(7, 2, 'Noise-Cancelling Studio Earbuds', 'Wireless stereo earbuds with deep bass and IPX5 water resistance.', 1899.00, 94.95, 50.00, 26.09, 20.00, 2090.04, 45, 'https://knowlipop.shop/uploads/products/placeholder.jpg', 1),
(8, 2, 'Ergonomic Vertical Mouse Pro', 'Rechargeable wireless ergonomic mouse with adjustable DPI and silent clicks.', 1499.00, 74.95, 50.00, 22.49, 20.00, 1666.44, 30, 'https://knowlipop.shop/uploads/products/placeholder.jpg', 1)
ON DUPLICATE KEY UPDATE `seller_id` = VALUES(`seller_id`), `name` = VALUES(`name`), `seller_price` = VALUES(`seller_price`), `commission` = VALUES(`commission`), `delivery_charge` = VALUES(`delivery_charge`), `gst` = VALUES(`gst`), `other_charges` = VALUES(`other_charges`), `price` = VALUES(`price`), `stock` = VALUES(`stock`), `image_url` = VALUES(`image_url`);

-- ======================================================
-- Buyer Orders & Order Items Tables
-- ======================================================

CREATE TABLE IF NOT EXISTS `orders` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `seller_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `order_number` VARCHAR(60) NOT NULL UNIQUE,
  `buyer_name` VARCHAR(150) NOT NULL,
  `buyer_phone` VARCHAR(30) NOT NULL,
  `shipping_address` TEXT NOT NULL,
  `landmark` VARCHAR(200) NULL DEFAULT NULL,
  `city` VARCHAR(100) NULL DEFAULT NULL,
  `state` VARCHAR(100) NULL DEFAULT NULL,
  `pincode` VARCHAR(20) NULL DEFAULT NULL,
  `order_status` VARCHAR(40) NOT NULL DEFAULT 'pending',
  `payment_method` VARCHAR(40) NOT NULL DEFAULT 'cod',
  `payment_status` VARCHAR(40) NOT NULL DEFAULT 'unpaid',
  `subtotal` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `delivery_fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `discount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `total_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `notes` TEXT NULL DEFAULT NULL,
  `rejection_reason` TEXT NULL DEFAULT NULL,
  `cancellation_reason` TEXT NULL DEFAULT NULL,
  `shipped_at` TIMESTAMP NULL DEFAULT NULL,
  `delivered_at` TIMESTAMP NULL DEFAULT NULL,
  `payment_gateway` VARCHAR(40) NULL DEFAULT NULL,
  `gateway_order_id` VARCHAR(120) NULL DEFAULT NULL,
  `gateway_payment_id` VARCHAR(120) NULL DEFAULT NULL,
  `captured_amount` DECIMAL(10,2) NULL DEFAULT NULL,
  `refunded_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `return_window_ends_at` TIMESTAMP NULL DEFAULT NULL,
  `settled_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_orders_seller` (`seller_id`),
  INDEX `idx_orders_status` (`order_status`),
  INDEX `idx_orders_phone` (`buyer_phone`),
  INDEX `idx_orders_gateway_payment` (`gateway_payment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `order_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `product_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `seller_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `product_name` VARCHAR(150) NOT NULL,
  `product_image` LONGTEXT NULL DEFAULT NULL,
  `price` DECIMAL(10,2) NOT NULL,
  `quantity` INT NOT NULL DEFAULT 1,
  `subtotal` DECIMAL(10,2) NOT NULL,
  `item_status` VARCHAR(40) NOT NULL DEFAULT 'pending',
  `rejection_reason` TEXT NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_order_items_order` (`order_id`),
  INDEX `idx_order_items_product` (`product_id`),
  INDEX `idx_order_items_seller` (`seller_id`),
  CONSTRAINT `fk_order_items_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notifications` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `buyer_phone` VARCHAR(30) NULL DEFAULT NULL,
  `title` VARCHAR(255) NOT NULL,
  `message` TEXT NOT NULL,
  `type` VARCHAR(50) NOT NULL DEFAULT 'order',
  `data` JSON NULL DEFAULT NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_notifications_user` (`user_id`),
  INDEX `idx_notifications_phone` (`buyer_phone`),
  INDEX `idx_notifications_read` (`is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `device_tokens` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `phone` VARCHAR(30) NULL DEFAULT NULL,
  `token` VARCHAR(255) NOT NULL,
  `platform` VARCHAR(20) NOT NULL DEFAULT 'android',
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_token` (`token`),
  INDEX `idx_device_tokens_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `product_images` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `seller_id` BIGINT UNSIGNED NOT NULL,
  `product_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `image_url` LONGTEXT NOT NULL,
  `relative_path` VARCHAR(255) NOT NULL,
  `file_name` VARCHAR(255) NOT NULL,
  `file_size` BIGINT UNSIGNED NULL DEFAULT NULL,
  `mime_type` VARCHAR(50) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_pi_seller` (`seller_id`),
  INDEX `idx_pi_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sample Starter Order for Buyer Module Testing
INSERT INTO `orders` (`id`, `seller_id`, `order_number`, `buyer_name`, `buyer_phone`, `shipping_address`, `landmark`, `city`, `state`, `pincode`, `order_status`, `payment_method`, `payment_status`, `subtotal`, `delivery_fee`, `discount`, `total_amount`, `notes`)
VALUES
(1, 1, 'ORD-2026-8941', 'Rahul Sharma', '+919876543210', 'Flat 402, Lotus Residency, MG Road', 'Near Metro Station Gate 2', 'Bengaluru', 'Karnataka', '560001', 'processing', 'cod', 'unpaid', 7297.00, 50.00, 0.00, 7347.00, 'Please ring the doorbell twice.')
ON DUPLICATE KEY UPDATE `seller_id` = VALUES(`seller_id`), `buyer_name` = VALUES(`buyer_name`), `total_amount` = VALUES(`total_amount`);

INSERT INTO `order_items` (`id`, `order_id`, `product_id`, `seller_id`, `product_name`, `product_image`, `price`, `quantity`, `subtotal`)
VALUES
(1, 1, 1, 1, 'Smart Wireless Headset Pro', 'https://knowlipop.shop/uploads/products/placeholder.jpg', 2999.00, 2, 5998.00),
(2, 1, 5, 1, 'Polarized Aviator Sunglasses', 'https://knowlipop.shop/uploads/products/placeholder.jpg', 1299.00, 1, 1299.00)
ON DUPLICATE KEY UPDATE `seller_id` = VALUES(`seller_id`), `quantity` = VALUES(`quantity`), `subtotal` = VALUES(`subtotal`);

-- ======================================================
-- Seller GST Document Upload & Admin Verification Schema
-- ======================================================

CREATE TABLE IF NOT EXISTS `seller_gst_details` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `seller_id` BIGINT UNSIGNED NOT NULL,
  `gstin` VARCHAR(30) NULL DEFAULT NULL,
  `business_name` VARCHAR(255) NULL DEFAULT NULL,
  `business_address` TEXT NULL DEFAULT NULL,
  `state` VARCHAR(100) NULL DEFAULT NULL,
  `pincode` VARCHAR(20) NULL DEFAULT NULL,
  `status` VARCHAR(30) NOT NULL DEFAULT 'Pending',
  `rejection_reason` TEXT NULL DEFAULT NULL,
  `verified_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `verified_at` TIMESTAMP NULL DEFAULT NULL,
  `submitted_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_gst_seller` (`seller_id`),
  INDEX `idx_gst_status` (`status`),
  INDEX `idx_gst_gstin` (`gstin`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `seller_gst_documents` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `seller_id` BIGINT UNSIGNED NOT NULL,
  `gst_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `document_type` VARCHAR(50) NOT NULL DEFAULT 'gst_certificate',
  `original_name` VARCHAR(255) NOT NULL,
  `file_name` VARCHAR(255) NOT NULL,
  `file_path` VARCHAR(255) NOT NULL,
  `file_size` BIGINT UNSIGNED NOT NULL,
  `mime_type` VARCHAR(100) NOT NULL,
  `is_primary` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_gstdoc_seller` (`seller_id`),
  INDEX `idx_gstdoc_gst` (`gst_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `seller_gst_verification_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `seller_id` BIGINT UNSIGNED NOT NULL,
  `gst_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `action` VARCHAR(40) NOT NULL,
  `previous_status` VARCHAR(30) NULL DEFAULT NULL,
  `new_status` VARCHAR(30) NOT NULL,
  `notes` TEXT NULL DEFAULT NULL,
  `performed_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `performer_role` VARCHAR(20) NOT NULL DEFAULT 'seller',
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_gstlog_seller` (`seller_id`),
  INDEX `idx_gstlog_gst` (`gst_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================================================
-- Seller Account Deletion, Refund & Settlement Schema
-- (Zero cascading deletes on financial or order history)
-- ======================================================

CREATE TABLE IF NOT EXISTS `payment_transactions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `seller_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `gateway` VARCHAR(40) NOT NULL DEFAULT 'razorpay',
  `gateway_order_id` VARCHAR(120) NULL DEFAULT NULL,
  `gateway_payment_id` VARCHAR(120) NOT NULL,
  `transaction_type` VARCHAR(40) NOT NULL DEFAULT 'capture',
  `amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'INR',
  `status` VARCHAR(40) NOT NULL DEFAULT 'captured',
  `gateway_payload` LONGTEXT NULL DEFAULT NULL,
  `settled_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_pt_order` (`order_id`),
  INDEX `idx_pt_seller` (`seller_id`),
  INDEX `idx_pt_gateway_payment` (`gateway_payment_id`),
  INDEX `idx_pt_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `seller_deletion_requests` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `seller_id` BIGINT UNSIGNED NOT NULL,
  `requested_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `stage` VARCHAR(50) NOT NULL DEFAULT 'DELETION_REQUESTED',
  `status` VARCHAR(50) NOT NULL DEFAULT 'DELETION_REQUESTED',
  `request_reason` TEXT NULL DEFAULT NULL,
  `admin_remarks` TEXT NULL DEFAULT NULL,
  `hold_reason` TEXT NULL DEFAULT NULL,
  `rejection_reason` TEXT NULL DEFAULT NULL,
  `confirmation_token` VARCHAR(128) NULL DEFAULT NULL,
  `confirmation_expires_at` TIMESTAMP NULL DEFAULT NULL,
  `snapshot_json` LONGTEXT NULL DEFAULT NULL,
  `financial_summary_json` LONGTEXT NULL DEFAULT NULL,
  `approved_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `approved_at` TIMESTAMP NULL DEFAULT NULL,
  `deleted_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `deleted_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_sdr_seller` (`seller_id`),
  INDEX `idx_sdr_stage` (`stage`),
  INDEX `idx_sdr_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `seller_deletion_checks` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `deletion_request_id` BIGINT UNSIGNED NOT NULL,
  `check_key` VARCHAR(60) NOT NULL,
  `label` VARCHAR(255) NOT NULL,
  `status` VARCHAR(30) NOT NULL DEFAULT 'PENDING',
  `is_blocking` TINYINT(1) NOT NULL DEFAULT 1,
  `details_json` LONGTEXT NULL DEFAULT NULL,
  `resolved_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `resolved_at` TIMESTAMP NULL DEFAULT NULL,
  `notes` TEXT NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_del_check` (`deletion_request_id`, `check_key`),
  INDEX `idx_sdc_req` (`deletion_request_id`),
  INDEX `idx_sdc_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `seller_refund_records` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `idempotency_key` VARCHAR(120) NOT NULL,
  `deletion_request_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `order_item_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `seller_id` BIGINT UNSIGNED NOT NULL,
  `payment_transaction_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `gateway` VARCHAR(40) NOT NULL DEFAULT 'razorpay',
  `gateway_payment_id` VARCHAR(120) NULL DEFAULT NULL,
  `gateway_refund_id` VARCHAR(120) NULL DEFAULT NULL,
  `order_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `eligible_refund_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `refunded_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `status` VARCHAR(40) NOT NULL DEFAULT 'REFUND_PENDING',
  `gateway_response` LONGTEXT NULL DEFAULT NULL,
  `failure_reason` TEXT NULL DEFAULT NULL,
  `attempts` INT NOT NULL DEFAULT 0,
  `initiated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `last_attempted_at` TIMESTAMP NULL DEFAULT NULL,
  `confirmed_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_srr_idempotency` (`idempotency_key`),
  INDEX `idx_srr_del` (`deletion_request_id`),
  INDEX `idx_srr_order` (`order_id`),
  INDEX `idx_srr_seller` (`seller_id`),
  INDEX `idx_srr_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `seller_payouts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `seller_id` BIGINT UNSIGNED NOT NULL,
  `deletion_request_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `commission_deducted` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `refund_recovery_deducted` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `net_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `status` VARCHAR(40) NOT NULL DEFAULT 'PENDING',
  `payout_reference` VARCHAR(120) NULL DEFAULT NULL,
  `gateway_payout_id` VARCHAR(120) NULL DEFAULT NULL,
  `settled_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `settled_at` TIMESTAMP NULL DEFAULT NULL,
  `notes` TEXT NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_sp_seller` (`seller_id`),
  INDEX `idx_sp_del` (`deletion_request_id`),
  INDEX `idx_sp_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `order_disputes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `seller_id` BIGINT UNSIGNED NOT NULL,
  `buyer_phone` VARCHAR(30) NULL DEFAULT NULL,
  `dispute_type` VARCHAR(50) NOT NULL DEFAULT 'complaint',
  `status` VARCHAR(40) NOT NULL DEFAULT 'OPEN',
  `claim_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `description` TEXT NULL DEFAULT NULL,
  `resolution_notes` TEXT NULL DEFAULT NULL,
  `resolved_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `resolved_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_od_order` (`order_id`),
  INDEX `idx_od_seller` (`seller_id`),
  INDEX `idx_od_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `seller_deletion_audit_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `deletion_request_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `seller_id` BIGINT UNSIGNED NOT NULL,
  `action` VARCHAR(80) NOT NULL,
  `previous_stage` VARCHAR(50) NULL DEFAULT NULL,
  `new_stage` VARCHAR(50) NULL DEFAULT NULL,
  `previous_status` VARCHAR(50) NULL DEFAULT NULL,
  `new_status` VARCHAR(50) NULL DEFAULT NULL,
  `actor_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `actor_role` VARCHAR(30) NOT NULL DEFAULT 'admin',
  `remarks` TEXT NULL DEFAULT NULL,
  `metadata_json` LONGTEXT NULL DEFAULT NULL,
  `ip_address` VARCHAR(64) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_sdal_del` (`deletion_request_id`),
  INDEX `idx_sdal_seller` (`seller_id`),
  INDEX `idx_sdal_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


