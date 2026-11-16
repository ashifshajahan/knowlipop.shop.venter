<?php

/**
 * Migration: Add Order Idempotency Schema
 * Prevents duplicate orders from rapid repeated requests, double clicks, or retries within 50ms.
 * Ensures:
 * - `orders.idempotency_key` VARCHAR(120) NULL with UNIQUE KEY `uniq_orders_idempotency`
 * - `order_idempotency_keys` table for atomic idempotency management, request hashing, and response caching.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';

try {
    $pdo = getDatabaseConnection();
    $driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    $isSqlite = ($driver === 'sqlite');

    echo "[Migration 2026_11_13_000004] Checking order idempotency schema on {$driver}...\n";

    if ($isSqlite) {
        // Ensure idempotency_key column on orders
        $colsStmt = $pdo->query("PRAGMA table_info(orders)");
        $existingCols = [];
        while ($col = $colsStmt->fetch(PDO::FETCH_ASSOC)) {
            $existingCols[] = strtolower((string)$col['name']);
        }
        if (!in_array('idempotency_key', $existingCols, true)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN idempotency_key TEXT NULL DEFAULT NULL");
            echo "  + Added column 'idempotency_key' to 'orders' (SQLite)\n";
        }

        // Unique index on orders.idempotency_key
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS uniq_orders_idempotency ON orders(idempotency_key)");
        echo "  + Ensured unique index 'uniq_orders_idempotency' on orders(idempotency_key) (SQLite)\n";

        // Idempotency tracking table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS order_idempotency_keys (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                idempotency_key TEXT NOT NULL UNIQUE,
                order_id INTEGER NULL DEFAULT NULL,
                order_number TEXT NULL DEFAULT NULL,
                request_hash TEXT NULL DEFAULT NULL,
                status TEXT NOT NULL DEFAULT 'completed',
                response_payload TEXT NULL DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE INDEX IF NOT EXISTS idx_oik_order ON order_idempotency_keys(order_id);
            CREATE INDEX IF NOT EXISTS idx_oik_status ON order_idempotency_keys(status);
        ");
        echo "  + Ensured table 'order_idempotency_keys' (SQLite)\n";
    } else {
        // MySQL
        $checkCol = $pdo->query("SHOW COLUMNS FROM `orders` LIKE 'idempotency_key'")->fetchAll();
        if (empty($checkCol)) {
            $pdo->exec("ALTER TABLE `orders` ADD COLUMN `idempotency_key` VARCHAR(120) NULL DEFAULT NULL AFTER `order_number`");
            echo "  + Added column 'idempotency_key' to 'orders' (MySQL)\n";
        }

        // Check index
        $checkIdx = $pdo->query("SHOW INDEX FROM `orders` WHERE `Key_name` = 'uniq_orders_idempotency'")->fetchAll();
        if (empty($checkIdx)) {
            $pdo->exec("ALTER TABLE `orders` ADD UNIQUE KEY `uniq_orders_idempotency` (`idempotency_key`)");
            echo "  + Added UNIQUE KEY 'uniq_orders_idempotency' on `orders`(`idempotency_key`) (MySQL)\n";
        }

        // Ensure order_idempotency_keys table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `order_idempotency_keys` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `idempotency_key` VARCHAR(120) NOT NULL,
                `order_id` BIGINT UNSIGNED NULL DEFAULT NULL,
                `order_number` VARCHAR(60) NULL DEFAULT NULL,
                `request_hash` VARCHAR(64) NULL DEFAULT NULL,
                `status` VARCHAR(30) NOT NULL DEFAULT 'completed',
                `response_payload` LONGTEXT NULL DEFAULT NULL,
                `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_order_idempotency_key` (`idempotency_key`),
                INDEX `idx_oik_order` (`order_id`),
                INDEX `idx_oik_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
        echo "  + Ensured table 'order_idempotency_keys' (MySQL)\n";
    }

    echo "[Migration 2026_11_13_000004] Order idempotency schema successfully migrated.\n";
} catch (Throwable $e) {
    echo "[Migration 2026_11_13_000004] ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

