<?php

/**
 * Migration: Create order_activities table and ensure order management columns
 * Supports:
 * - order_activities table (audit trail, actor, action, timestamps, tracking)
 * - carrier and tracking_number on orders table
 * - cancellation_reason and rejection_reason on orders table
 * - item_status and rejection_reason on order_items table
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';

function runOrderActivitiesMigration(): void
{
    $pdo = getDatabaseConnection();
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    $isSqlite = ($driver === 'sqlite');

    echo "Running Order Activities & Cancellation Management migration on {$driver}...\n";

    // Reusable column check helper
    $addCol = function (string $table, string $col, string $mysqlDef, string $sqliteDef) use ($pdo, $isSqlite): void {
        try {
            if ($isSqlite) {
                $colsStmt = $pdo->query("PRAGMA table_info({$table})");
                $existing = [];
                while ($row = $colsStmt->fetch(PDO::FETCH_ASSOC)) {
                    $existing[] = strtolower($row['name']);
                }
                if (!in_array(strtolower($col), $existing, true)) {
                    $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$sqliteDef}");
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

    // 1. Ensure columns on orders
    $addCol('orders', 'carrier', "`carrier` VARCHAR(100) NULL DEFAULT NULL AFTER `shipping_address`", "`carrier` TEXT NULL DEFAULT NULL");
    $addCol('orders', 'tracking_number', "`tracking_number` VARCHAR(100) NULL DEFAULT NULL AFTER `carrier`", "`tracking_number` TEXT NULL DEFAULT NULL");
    $addCol('orders', 'cancellation_reason', "`cancellation_reason` TEXT NULL DEFAULT NULL AFTER `notes`", "`cancellation_reason` TEXT NULL DEFAULT NULL");
    $addCol('orders', 'rejection_reason', "`rejection_reason` TEXT NULL DEFAULT NULL AFTER `cancellation_reason`", "`rejection_reason` TEXT NULL DEFAULT NULL");
    $addCol('orders', 'shipped_at', "`shipped_at` TIMESTAMP NULL DEFAULT NULL AFTER `rejection_reason`", "`shipped_at` DATETIME NULL DEFAULT NULL");
    $addCol('orders', 'delivered_at', "`delivered_at` TIMESTAMP NULL DEFAULT NULL AFTER `shipped_at`", "`delivered_at` DATETIME NULL DEFAULT NULL");
    $addCol('orders', 'return_window_ends_at', "`return_window_ends_at` TIMESTAMP NULL DEFAULT NULL AFTER `delivered_at`", "`return_window_ends_at` DATETIME NULL DEFAULT NULL");

    // 2. Ensure columns on order_items
    $addCol('order_items', 'item_status', "`item_status` VARCHAR(40) NOT NULL DEFAULT 'pending' AFTER `subtotal`", "`item_status` TEXT NOT NULL DEFAULT 'pending'");
    $addCol('order_items', 'rejection_reason', "`rejection_reason` TEXT NULL DEFAULT NULL AFTER `item_status`", "`rejection_reason` TEXT NULL DEFAULT NULL");

    // 3. Create order_activities table
    if ($isSqlite) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS order_activities (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                order_id INTEGER NOT NULL,
                actor_id INTEGER NULL DEFAULT NULL,
                actor_role TEXT NOT NULL DEFAULT 'system',
                actor_name TEXT NULL DEFAULT NULL,
                action TEXT NOT NULL,
                from_status TEXT NULL DEFAULT NULL,
                to_status TEXT NULL DEFAULT NULL,
                notes TEXT NULL DEFAULT NULL,
                tracking_number TEXT NULL DEFAULT NULL,
                carrier TEXT NULL DEFAULT NULL,
                metadata TEXT NULL DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE INDEX IF NOT EXISTS idx_oa_order ON order_activities(order_id);
            CREATE INDEX IF NOT EXISTS idx_oa_action ON order_activities(action);
            CREATE INDEX IF NOT EXISTS idx_oa_created ON order_activities(created_at);
        ");
    } else {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `order_activities` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `order_id` BIGINT UNSIGNED NOT NULL,
                `actor_id` BIGINT UNSIGNED NULL DEFAULT NULL,
                `actor_role` VARCHAR(40) NOT NULL DEFAULT 'system',
                `actor_name` VARCHAR(150) NULL DEFAULT NULL,
                `action` VARCHAR(60) NOT NULL,
                `from_status` VARCHAR(40) NULL DEFAULT NULL,
                `to_status` VARCHAR(40) NULL DEFAULT NULL,
                `notes` TEXT NULL DEFAULT NULL,
                `tracking_number` VARCHAR(100) NULL DEFAULT NULL,
                `carrier` VARCHAR(100) NULL DEFAULT NULL,
                `metadata` LONGTEXT NULL DEFAULT NULL,
                `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_oa_order` (`order_id`),
                INDEX `idx_oa_action` (`action`),
                INDEX `idx_oa_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    echo "✅ [Order Activities Migration] Successfully migrated order_activities and tracking columns.\n";
}

if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    runOrderActivitiesMigration();
}

