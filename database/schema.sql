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
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_users_role` (`role`),
  INDEX `idx_users_phone` (`phone`)
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
  INDEX `idx_products_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `password_reset_otps` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `phone` VARCHAR(30) NOT NULL,
  `token` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_otp_phone` (`phone`)
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
(1, 1, 'Smart Wireless Headset Pro', 'Active noise cancelling over-ear headphones with 40-hour battery life.', 2999.00, 149.95, 50.00, 35.99, 20.00, 3254.94, 50, 'https://images.unsplash.com/photo-1505740420928-5e560c06d30e?w=500', 1),
(2, 1, 'Ultra HD 4K Smart Watch', 'AMOLED display smartwatch with heart rate monitor, GPS tracking, and 7-day battery life.', 4499.00, 224.95, 50.00, 49.49, 20.00, 4843.44, 35, 'https://images.unsplash.com/photo-1523275335684-37898b6baf30?w=500', 1),
(3, 1, 'Vintage Leather Travel Backpack', 'Water-resistant genuine leather travel bag with 15.6 inch laptop sleeve and ergonomic straps.', 3299.00, 164.95, 50.00, 38.69, 20.00, 3572.64, 20, 'https://images.unsplash.com/photo-1553062407-98eeb64c6a62?w=500', 1),
(4, 1, 'Air Cushion Running Sneakers', 'Lightweight breathable sports shoes designed for marathon running and daily training.', 2499.00, 124.95, 50.00, 31.49, 20.00, 2725.44, 60, 'https://images.unsplash.com/photo-1542291026-7eec264c27ff?w=500', 1),
(5, 1, 'Polarized Aviator Sunglasses', 'UV400 protection titanium frame sunglasses with anti-glare scratch resistant lenses.', 1299.00, 64.95, 50.00, 20.69, 20.00, 1454.64, 100, 'https://images.unsplash.com/photo-1511499767150-a48a237f0083?w=500', 1),
(6, 1, 'RGB Mechanical Gaming Keyboard', 'Custom hot-swappable tactile blue switches with per-key RGB backlighting and aluminum frame.', 3999.00, 199.95, 50.00, 44.99, 20.00, 4313.94, 25, 'https://images.unsplash.com/photo-1587829741301-dc798b83add3?w=500', 1),
(7, 2, 'Noise-Cancelling Studio Earbuds', 'Wireless stereo earbuds with deep bass and IPX5 water resistance.', 1899.00, 94.95, 50.00, 26.09, 20.00, 2090.04, 45, 'https://images.unsplash.com/photo-1590658268037-6bf12165a8df?w=500', 1),
(8, 2, 'Ergonomic Vertical Mouse Pro', 'Rechargeable wireless ergonomic mouse with adjustable DPI and silent clicks.', 1499.00, 74.95, 50.00, 22.49, 20.00, 1666.44, 30, 'https://images.unsplash.com/photo-1527864550417-7fd91fc51a46?w=500', 1)
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
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_orders_seller` (`seller_id`),
  INDEX `idx_orders_status` (`order_status`),
  INDEX `idx_orders_phone` (`buyer_phone`)
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
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_order_items_order` (`order_id`),
  INDEX `idx_order_items_product` (`product_id`),
  INDEX `idx_order_items_seller` (`seller_id`),
  CONSTRAINT `fk_order_items_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sample Starter Order for Buyer Module Testing
INSERT INTO `orders` (`id`, `seller_id`, `order_number`, `buyer_name`, `buyer_phone`, `shipping_address`, `landmark`, `city`, `state`, `pincode`, `order_status`, `payment_method`, `payment_status`, `subtotal`, `delivery_fee`, `discount`, `total_amount`, `notes`)
VALUES
(1, 1, 'ORD-2026-8941', 'Rahul Sharma', '+919876543210', 'Flat 402, Lotus Residency, MG Road', 'Near Metro Station Gate 2', 'Bengaluru', 'Karnataka', '560001', 'processing', 'cod', 'unpaid', 7297.00, 50.00, 0.00, 7347.00, 'Please ring the doorbell twice.')
ON DUPLICATE KEY UPDATE `seller_id` = VALUES(`seller_id`), `buyer_name` = VALUES(`buyer_name`), `total_amount` = VALUES(`total_amount`);

INSERT INTO `order_items` (`id`, `order_id`, `product_id`, `seller_id`, `product_name`, `product_image`, `price`, `quantity`, `subtotal`)
VALUES
(1, 1, 1, 1, 'Smart Wireless Headset Pro', 'https://images.unsplash.com/photo-1505740420928-5e560c06d30e?w=500', 2999.00, 2, 5998.00),
(2, 1, 5, 1, 'Polarized Aviator Sunglasses', 'https://images.unsplash.com/photo-1511499767150-a48a237f0083?w=500', 1299.00, 1, 1299.00)
ON DUPLICATE KEY UPDATE `seller_id` = VALUES(`seller_id`), `quantity` = VALUES(`quantity`), `subtotal` = VALUES(`subtotal`);


