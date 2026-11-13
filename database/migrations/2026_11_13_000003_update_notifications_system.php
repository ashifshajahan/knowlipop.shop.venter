<?php

/**
 * Migration: Update Notifications & Realtime System Schema
 * Ensures `recipient_role` and `order_id` in `notifications` table,
 * and ensures indexes for fast query performance.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';

try {
    $pdo = getDatabaseConnection();
    $driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    $isSqlite = ($driver === 'sqlite');

    echo "[Migration 2026_11_13_000003] Checking notifications table schema...\n";

    if ($isSqlite) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS notifications (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NULL DEFAULT NULL,
                buyer_phone TEXT NULL DEFAULT NULL,
                recipient_role TEXT NULL DEFAULT NULL,
                order_id INTEGER NULL DEFAULT NULL,
                title TEXT NOT NULL,
                message TEXT NOT NULL,
                type TEXT NOT NULL DEFAULT 'order',
                data TEXT NULL DEFAULT NULL,
                is_read INTEGER NOT NULL DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE INDEX IF NOT EXISTS idx_notifications_user ON notifications(user_id);
            CREATE INDEX IF NOT EXISTS idx_notifications_phone ON notifications(buyer_phone);
            CREATE INDEX IF NOT EXISTS idx_notifications_read ON notifications(is_read);
            CREATE INDEX IF NOT EXISTS idx_notifications_order ON notifications(order_id);

            CREATE TABLE IF NOT EXISTS device_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NULL DEFAULT NULL,
                phone TEXT NULL DEFAULT NULL,
                token TEXT NOT NULL UNIQUE,
                platform TEXT NOT NULL DEFAULT 'android',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE INDEX IF NOT EXISTS idx_device_tokens_user ON device_tokens(user_id);
        ");
    } else {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `notifications` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` BIGINT UNSIGNED NULL DEFAULT NULL,
                `buyer_phone` VARCHAR(30) NULL DEFAULT NULL,
                `recipient_role` VARCHAR(40) NULL DEFAULT NULL,
                `order_id` BIGINT UNSIGNED NULL DEFAULT NULL,
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
                INDEX `idx_notifications_read` (`is_read`),
                INDEX `idx_notifications_order` (`order_id`)
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
        ");

        // Add columns if table already existed without them
        try {
            $colStmt = $pdo->query("SHOW COLUMNS FROM `notifications` LIKE 'recipient_role'");
            if (!$colStmt->fetch()) {
                $pdo->exec("ALTER TABLE `notifications` ADD COLUMN `recipient_role` VARCHAR(40) NULL DEFAULT NULL AFTER `buyer_phone`");
                echo "[Migration] Added recipient_role column to notifications.\n";
            }
        } catch (Throwable $e) {}

        try {
            $colStmt = $pdo->query("SHOW COLUMNS FROM `notifications` LIKE 'order_id'");
            if (!$colStmt->fetch()) {
                $pdo->exec("ALTER TABLE `notifications` ADD COLUMN `order_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `recipient_role`");
                $pdo->exec("ALTER TABLE `notifications` ADD INDEX `idx_notifications_order` (`order_id`)");
                echo "[Migration] Added order_id column and index to notifications.\n";
            }
        } catch (Throwable $e) {}

        try {
            $colStmt = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'fcm_token'");
            if (!$colStmt->fetch()) {
                $pdo->exec("ALTER TABLE `users` ADD COLUMN `fcm_token` VARCHAR(255) NULL DEFAULT NULL");
                echo "[Migration] Added fcm_token column to users.\n";
            }
        } catch (Throwable $e) {}
    }

    echo "[Migration 2026_11_13_000003] Schema updated successfully.\n";
} catch (Throwable $e) {
    echo "[Migration 2026_11_13_000003] Note / Error: " . $e->getMessage() . "\n";
}

