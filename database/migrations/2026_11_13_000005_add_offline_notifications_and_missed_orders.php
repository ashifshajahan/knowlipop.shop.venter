<?php

/**
 * Migration: Add Offline Notifications and Missed Orders Schema
 *
 * Ensures:
 * 1. `notifications` table:
 *    - `delivery_status` VARCHAR(40) NOT NULL DEFAULT 'delivered'
 *    - `read_at` DATETIME/TIMESTAMP NULL
 *    - `delivered_at` DATETIME/TIMESTAMP NULL
 *    - `dedup_key` VARCHAR(120) NULL
 *    - `action_url` VARCHAR(255) NULL
 * 2. `users` table:
 *    - `last_login_at` DATETIME/TIMESTAMP NULL
 *    - `last_active_at` DATETIME/TIMESTAMP NULL
 *    - `is_online` TINYINT(1) NOT NULL DEFAULT 0
 * 3. `orders` table:
 *    - `acknowledged_at` DATETIME/TIMESTAMP NULL
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';

try {
    $pdo = getDatabaseConnection();
    $driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    $isSqlite = ($driver === 'sqlite');

    echo "[Migration 2026_11_13_000005] Updating offline notifications and missed orders schema on {$driver}...\n";

    $addCol = function(string $table, string $col, string $mysqlDef, string $sqliteDef) use ($pdo, $isSqlite): void {
        try {
            if ($isSqlite) {
                $colsStmt = $pdo->query("PRAGMA table_info({$table})");
                $existing = [];
                while ($c = $colsStmt->fetch(PDO::FETCH_ASSOC)) {
                    $existing[] = strtolower((string)$c['name']);
                }
                if (!in_array(strtolower($col), $existing, true)) {
                    $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN {$sqliteDef}");
                    echo "  + Added column '{$col}' to '{$table}' (SQLite)\n";
                }
            } else {
                $check = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE '{$col}'")->fetchAll();
                if (empty($check)) {
                    $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN {$mysqlDef}");
                    echo "  + Added column '{$col}' to '{$table}' (MySQL)\n";
                }
            }
        } catch (Throwable $e) {
            echo "  ! Notice adding '{$col}' to '{$table}': " . $e->getMessage() . "\n";
        }
    };

    // 1. Notifications columns
    $addCol('notifications', 'delivery_status', "`delivery_status` VARCHAR(40) NOT NULL DEFAULT 'delivered' AFTER `is_read`", "`delivery_status` TEXT NOT NULL DEFAULT 'delivered'");
    $addCol('notifications', 'read_at', "`read_at` TIMESTAMP NULL DEFAULT NULL AFTER `delivery_status`", "`read_at` DATETIME NULL DEFAULT NULL");
    $addCol('notifications', 'delivered_at', "`delivered_at` TIMESTAMP NULL DEFAULT NULL AFTER `read_at`", "`delivered_at` DATETIME NULL DEFAULT NULL");
    $addCol('notifications', 'dedup_key', "`dedup_key` VARCHAR(120) NULL DEFAULT NULL AFTER `delivered_at`", "`dedup_key` TEXT NULL DEFAULT NULL");
    $addCol('notifications', 'action_url', "`action_url` VARCHAR(255) NULL DEFAULT NULL AFTER `dedup_key`", "`action_url` TEXT NULL DEFAULT NULL");

    // Indexes on notifications
    try {
        if ($isSqlite) {
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_notifications_delivery ON notifications(delivery_status)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_notifications_dedup ON notifications(dedup_key)");
        } else {
            $idxChk = $pdo->query("SHOW INDEX FROM `notifications` WHERE Key_name = 'idx_notifications_delivery'")->fetchAll();
            if (empty($idxChk)) {
                $pdo->exec("ALTER TABLE `notifications` ADD INDEX `idx_notifications_delivery` (`delivery_status`)");
            }
            $idxChk2 = $pdo->query("SHOW INDEX FROM `notifications` WHERE Key_name = 'idx_notifications_dedup'")->fetchAll();
            if (empty($idxChk2)) {
                $pdo->exec("ALTER TABLE `notifications` ADD INDEX `idx_notifications_dedup` (`dedup_key`)");
            }
        }
    } catch (Throwable $e) {}

    // 2. Users offline/login columns
    $addCol('users', 'last_login_at', "`last_login_at` TIMESTAMP NULL DEFAULT NULL", "`last_login_at` DATETIME NULL DEFAULT NULL");
    $addCol('users', 'last_active_at', "`last_active_at` TIMESTAMP NULL DEFAULT NULL", "`last_active_at` DATETIME NULL DEFAULT NULL");
    $addCol('users', 'is_online', "`is_online` TINYINT(1) NOT NULL DEFAULT 0", "`is_online` INTEGER NOT NULL DEFAULT 0");

    // 3. Orders acknowledgment column
    $addCol('orders', 'acknowledged_at', "`acknowledged_at` TIMESTAMP NULL DEFAULT NULL", "`acknowledged_at` DATETIME NULL DEFAULT NULL");

    echo "[Migration 2026_11_13_000005] Offline notifications and missed orders schema successfully updated.\n";
} catch (Throwable $e) {
    echo "[Migration 2026_11_13_000005] ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

