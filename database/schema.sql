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
  `description` TEXT NULL DEFAULT NULL,
  `price` DECIMAL(10,2) NOT NULL,
  `stock` INT NOT NULL DEFAULT 0,
  `image_url` LONGTEXT NULL DEFAULT NULL,
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

-- Default Starter Products for App & Catalog Testing
INSERT INTO `products` (`id`, `seller_id`, `name`, `description`, `price`, `stock`, `image_url`, `is_active`)
VALUES
(1, 1, 'Smart Wireless Headset Pro', 'Active noise cancelling over-ear headphones with 40-hour battery life.', 2999.00, 50, 'https://images.unsplash.com/photo-1505740420928-5e560c06d30e?w=500', 1),
(2, 1, 'Ultra HD 4K Smart Watch', 'AMOLED display smartwatch with heart rate monitor, GPS tracking, and 7-day battery life.', 4499.00, 35, 'https://images.unsplash.com/photo-1523275335684-37898b6baf30?w=500', 1),
(3, 1, 'Vintage Leather Travel Backpack', 'Water-resistant genuine leather travel bag with 15.6 inch laptop sleeve and ergonomic straps.', 3299.00, 20, 'https://images.unsplash.com/photo-1553062407-98eeb64c6a62?w=500', 1),
(4, 1, 'Air Cushion Running Sneakers', 'Lightweight breathable sports shoes designed for marathon running and daily training.', 2499.00, 60, 'https://images.unsplash.com/photo-1542291026-7eec264c27ff?w=500', 1),
(5, 1, 'Polarized Aviator Sunglasses', 'UV400 protection titanium frame sunglasses with anti-glare scratch resistant lenses.', 1299.00, 100, 'https://images.unsplash.com/photo-1511499767150-a48a237f0083?w=500', 1),
(6, 1, 'RGB Mechanical Gaming Keyboard', 'Custom hot-swappable tactile blue switches with per-key RGB backlighting and aluminum frame.', 3999.00, 25, 'https://images.unsplash.com/photo-1587829741301-dc798b83add3?w=500', 1)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `price` = VALUES(`price`), `stock` = VALUES(`stock`), `image_url` = VALUES(`image_url`);

