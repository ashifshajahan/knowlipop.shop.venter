<?php

/**
 * Migration: Create and enhance Razorpay payment tables and columns.
 *
 * Ensures:
 * 1. Orders table has payment_gateway, gateway_order_id, gateway_payment_id, captured_amount, refunded_amount.
 * 2. Payment_transactions table has all columns including commission, net_seller_amount, buyer details, and refund tracking.
 * 3. Webhook_logs table for idempotent webhook event deduplication.
 * 4. Seller_refund_records and seller_payouts tables.
 *
 * Safe to run repeatedly (idempotent). Never deletes existing records or recreates tables.
 *
 * CLI usage:
 *   php database/migrations/2026_11_13_000001_create_razorpay_payment_tables.php
 *   php database/migrations/2026_11_13_000001_create_razorpay_payment_tables.php --sqlite=/path/to/test.sqlite
 */

require_once __DIR__ . '/../../config/database.php';

function dbMigrateHasColumnPhp(PDO $pdo, string $table, string $column): bool
{
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    try {
        if ($driver === 'sqlite') {
            $stmt = $pdo->query("PRAGMA table_info(\"{$table}\")");
            $cols = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            foreach ($cols as $col) {
                if (strtolower((string) ($col['name'] ?? '')) === strtolower($column)) {
                    return true;
                }
            }
            return false;
        }
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE :col");
        $stmt->execute([':col' => $column]);
        return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

function migrateRazorpayPaymentTables(PDO $pdo): array
{
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    $isSqlite = ($driver === 'sqlite');
    $changes = [];

    $addCol = function (string $table, string $col, string $mysqlDef, string $sqliteDef) use ($pdo, $isSqlite, &$changes): void {
        if (dbMigrateHasColumnPhp($pdo, $table, $col)) {
            return;
        }
        try {
            $def = $isSqlite ? $sqliteDef : $mysqlDef;
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN {$def}");
            $changes[] = "Added column {$col} to {$table}";
        } catch (Throwable $e) {
            $changes[] = "Warning on adding {$col} to {$table}: " . $e->getMessage();
        }
    };

    // 1. Orders table
    if ($isSqlite) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS orders (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                seller_id INTEGER NULL DEFAULT NULL,
                order_number TEXT NOT NULL UNIQUE,
                buyer_name TEXT NOT NULL,
                buyer_phone TEXT NOT NULL,
                shipping_address TEXT NOT NULL,
                landmark TEXT NULL DEFAULT NULL,
                city TEXT NULL DEFAULT NULL,
                state TEXT NULL DEFAULT NULL,
                pincode TEXT NULL DEFAULT NULL,
                order_status TEXT NOT NULL DEFAULT 'pending',
                payment_method TEXT NOT NULL DEFAULT 'cod',
                payment_status TEXT NOT NULL DEFAULT 'unpaid',
                subtotal REAL NOT NULL DEFAULT 0.00,
                delivery_fee REAL NOT NULL DEFAULT 0.00,
                discount REAL NOT NULL DEFAULT 0.00,
                total_amount REAL NOT NULL DEFAULT 0.00,
                notes TEXT NULL DEFAULT NULL,
                payment_gateway TEXT NULL DEFAULT NULL,
                gateway_order_id TEXT NULL DEFAULT NULL,
                gateway_payment_id TEXT NULL DEFAULT NULL,
                captured_amount REAL NULL DEFAULT NULL,
                refunded_amount REAL NOT NULL DEFAULT 0.00,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
        ");
    }

    $addCol('orders', 'payment_gateway', "`payment_gateway` VARCHAR(40) NULL DEFAULT NULL", "`payment_gateway` TEXT NULL DEFAULT NULL");
    $addCol('orders', 'gateway_order_id', "`gateway_order_id` VARCHAR(120) NULL DEFAULT NULL", "`gateway_order_id` TEXT NULL DEFAULT NULL");
    $addCol('orders', 'gateway_payment_id', "`gateway_payment_id` VARCHAR(120) NULL DEFAULT NULL", "`gateway_payment_id` TEXT NULL DEFAULT NULL");
    $addCol('orders', 'captured_amount', "`captured_amount` DECIMAL(10,2) NULL DEFAULT NULL", "`captured_amount` REAL NULL DEFAULT NULL");
    $addCol('orders', 'refunded_amount', "`refunded_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00", "`refunded_amount` REAL NOT NULL DEFAULT 0.00");

    // 2. Payment Transactions table
    if ($isSqlite) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS payment_transactions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                order_id INTEGER NOT NULL,
                seller_id INTEGER NULL,
                gateway TEXT NOT NULL DEFAULT 'razorpay',
                gateway_order_id TEXT NULL,
                gateway_payment_id TEXT NOT NULL,
                transaction_type TEXT NOT NULL DEFAULT 'capture',
                amount REAL NOT NULL DEFAULT 0.00,
                currency TEXT NOT NULL DEFAULT 'INR',
                status TEXT NOT NULL DEFAULT 'captured',
                commission_rate REAL NOT NULL DEFAULT 0.0500,
                commission_amount REAL NOT NULL DEFAULT 0.00,
                net_seller_amount REAL NOT NULL DEFAULT 0.00,
                buyer_phone TEXT NULL,
                buyer_name TEXT NULL,
                refund_id TEXT NULL,
                idempotency_key TEXT NULL,
                error_code TEXT NULL,
                error_description TEXT NULL,
                gateway_payload TEXT NULL,
                settled_at DATETIME NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
        ");
    } else {
        $pdo->exec("
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
                `commission_rate` DECIMAL(5,4) NOT NULL DEFAULT 0.0500,
                `commission_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                `net_seller_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                `buyer_phone` VARCHAR(30) NULL DEFAULT NULL,
                `buyer_name` VARCHAR(150) NULL DEFAULT NULL,
                `refund_id` VARCHAR(120) NULL DEFAULT NULL,
                `idempotency_key` VARCHAR(120) NULL DEFAULT NULL,
                `error_code` VARCHAR(100) NULL DEFAULT NULL,
                `error_description` TEXT NULL DEFAULT NULL,
                `gateway_payload` LONGTEXT NULL DEFAULT NULL,
                `settled_at` TIMESTAMP NULL DEFAULT NULL,
                `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_pt_order` (`order_id`),
                INDEX `idx_pt_seller` (`seller_id`),
                INDEX `idx_pt_gateway_payment` (`gateway_payment_id`),
                INDEX `idx_pt_gateway_order` (`gateway_order_id`),
                INDEX `idx_pt_status` (`status`),
                INDEX `idx_pt_type` (`transaction_type`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    // Ensure columns in existing payment_transactions if table already existed
    $addCol('payment_transactions', 'commission_rate', "`commission_rate` DECIMAL(5,4) NOT NULL DEFAULT 0.0500 AFTER `status`", "`commission_rate` REAL NOT NULL DEFAULT 0.0500");
    $addCol('payment_transactions', 'commission_amount', "`commission_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `commission_rate`", "`commission_amount` REAL NOT NULL DEFAULT 0.00");
    $addCol('payment_transactions', 'net_seller_amount', "`net_seller_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `commission_amount`", "`net_seller_amount` REAL NOT NULL DEFAULT 0.00");
    $addCol('payment_transactions', 'buyer_phone', "`buyer_phone` VARCHAR(30) NULL DEFAULT NULL AFTER `net_seller_amount`", "`buyer_phone` TEXT NULL DEFAULT NULL");
    $addCol('payment_transactions', 'buyer_name', "`buyer_name` VARCHAR(150) NULL DEFAULT NULL AFTER `buyer_phone`", "`buyer_name` TEXT NULL DEFAULT NULL");
    $addCol('payment_transactions', 'refund_id', "`refund_id` VARCHAR(120) NULL DEFAULT NULL AFTER `buyer_name`", "`refund_id` TEXT NULL DEFAULT NULL");
    $addCol('payment_transactions', 'idempotency_key', "`idempotency_key` VARCHAR(120) NULL DEFAULT NULL AFTER `refund_id`", "`idempotency_key` TEXT NULL DEFAULT NULL");
    $addCol('payment_transactions', 'error_code', "`error_code` VARCHAR(100) NULL DEFAULT NULL AFTER `idempotency_key`", "`error_code` TEXT NULL DEFAULT NULL");
    $addCol('payment_transactions', 'error_description', "`error_description` TEXT NULL DEFAULT NULL AFTER `error_code`", "`error_description` TEXT NULL DEFAULT NULL");

    // 3. Webhook Logs table for idempotent deduplication
    if ($isSqlite) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS webhook_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                event_id TEXT NOT NULL UNIQUE,
                event_type TEXT NOT NULL,
                gateway TEXT NOT NULL DEFAULT 'razorpay',
                order_id INTEGER NULL,
                payload TEXT NULL,
                status TEXT NOT NULL DEFAULT 'processed',
                error_message TEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
        ");
    } else {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `webhook_logs` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `event_id` VARCHAR(120) NOT NULL,
                `event_type` VARCHAR(100) NOT NULL,
                `gateway` VARCHAR(40) NOT NULL DEFAULT 'razorpay',
                `order_id` BIGINT UNSIGNED NULL DEFAULT NULL,
                `payload` LONGTEXT NULL DEFAULT NULL,
                `status` VARCHAR(40) NOT NULL DEFAULT 'processed',
                `error_message` TEXT NULL DEFAULT NULL,
                `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_webhook_event` (`event_id`),
                INDEX `idx_wl_order` (`order_id`),
                INDEX `idx_wl_event` (`event_type`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    // 4. Seller Refund Records table
    if ($isSqlite) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS seller_refund_records (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                idempotency_key TEXT NOT NULL UNIQUE,
                deletion_request_id INTEGER NULL,
                order_id INTEGER NOT NULL,
                order_item_id INTEGER NULL,
                seller_id INTEGER NOT NULL,
                payment_transaction_id INTEGER NULL,
                gateway TEXT NOT NULL DEFAULT 'razorpay',
                gateway_payment_id TEXT NULL,
                gateway_refund_id TEXT NULL,
                order_amount REAL NOT NULL DEFAULT 0.00,
                eligible_refund_amount REAL NOT NULL DEFAULT 0.00,
                refunded_amount REAL NOT NULL DEFAULT 0.00,
                status TEXT NOT NULL DEFAULT 'REFUND_PENDING',
                gateway_response TEXT NULL,
                failure_reason TEXT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                initiated_by INTEGER NULL,
                last_attempted_at DATETIME NULL,
                confirmed_at DATETIME NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
        ");
    } else {
        $pdo->exec("
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
        ");
    }

    // 5. Seller Payouts table
    if ($isSqlite) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS seller_payouts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                seller_id INTEGER NOT NULL,
                deletion_request_id INTEGER NULL,
                amount REAL NOT NULL DEFAULT 0.00,
                commission_deducted REAL NOT NULL DEFAULT 0.00,
                refund_recovery_deducted REAL NOT NULL DEFAULT 0.00,
                net_amount REAL NOT NULL DEFAULT 0.00,
                status TEXT NOT NULL DEFAULT 'PENDING',
                payout_reference TEXT NULL,
                gateway_payout_id TEXT NULL,
                settled_by INTEGER NULL,
                settled_at DATETIME NULL,
                notes TEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
        ");
    } else {
        $pdo->exec("
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
        ");
    }

    return [
        'status' => 'ok',
        'driver' => $driver,
        'changes' => $changes,
        'tables_verified' => [
            'orders',
            'payment_transactions',
            'webhook_logs',
            'seller_refund_records',
            'seller_payouts'
        ]
    ];
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $sqlitePath = null;
    foreach ($argv ?? [] as $arg) {
        if (str_starts_with($arg, '--sqlite=')) {
            $sqlitePath = substr($arg, 9);
        }
    }

    if ($sqlitePath !== null && $sqlitePath !== '') {
        $pdo = new PDO('sqlite:' . $sqlitePath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    } else {
        $pdo = getDatabaseConnection();
    }

    $result = migrateRazorpayPaymentTables($pdo);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}
