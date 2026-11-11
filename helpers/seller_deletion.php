<?php

/**
 * Seller Account Deletion, Order Verification, Razorpay Refund & Settlement Engine (Pure PHP)
 * Supports both MySQL (production) and SQLite (unit/integration test harness).
 */

require_once __DIR__ . '/response.php';
require_once __DIR__ . '/auth.php';

function getSellerDeletionConfigPhp(): array
{
    return [
        'return_window_days' => max(1, (int) (getEnvVar('RETURN_WINDOW_DAYS', '7') ?: 7)),
        'commission_rate' => max(0.0, (float) (getEnvVar('MARKETPLACE_COMMISSION_RATE', '0.05') ?: 0.05)),
        'razorpay_key_id' => trim(getEnvVar('RAZORPAY_KEY_ID', '')),
        'razorpay_key_secret' => trim(getEnvVar('RAZORPAY_KEY_SECRET', '')),
        'razorpay_api_base' => rtrim(getEnvVar('RAZORPAY_API_BASE_URL', 'https://api.razorpay.com/v1') ?: 'https://api.razorpay.com/v1', '/'),
    ];
}

function getDbCurrentTimestampPhp(PDO $pdo): string
{
    try {
        $row = $pdo->query("SELECT CURRENT_TIMESTAMP AS now_ts")->fetch(PDO::FETCH_ASSOC);
        if (!empty($row['now_ts'])) {
            return (string) $row['now_ts'];
        }
    } catch (Throwable $e) {
    }
    return gmdate('Y-m-d H:i:s');
}

function parseUtcTimestampPhp(?string $val): ?int
{
    if ($val === null) {
        return null;
    }
    $str = trim($val);
    if ($str === '' || $str === '0000-00-00 00:00:00') {
        return null;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(\.\d+)?$/', $str)) {
        $clean = substr(str_replace('T', ' ', $str), 0, 19);
        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $clean, new DateTimeZone('UTC'));
        return $dt ? $dt->getTimestamp() : null;
    }
    $ts = strtotime($str);
    return $ts === false ? null : $ts;
}

function dbHasColumnPhp(PDO $pdo, string $table, string $column): bool
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

function ensureSellerDeletionSchemaPhp(PDO $pdo): void
{
    static $ensured = [];
    $oid = spl_object_id($pdo);
    if (!empty($ensured[$oid])) {
        return;
    }
    $ensured[$oid] = true;

    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    $isSqlite = ($driver === 'sqlite');

    $addCol = function (string $table, string $col, string $mysqlDef, string $sqliteDef) use ($pdo, $isSqlite): void {
        if (dbHasColumnPhp($pdo, $table, $col)) {
            return;
        }
        try {
            $def = $isSqlite ? $sqliteDef : $mysqlDef;
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN {$def}");
        } catch (Throwable $e) {
        }
    };

    // Extend users table safely
    $addCol('users', 'account_status', "`account_status` VARCHAR(40) NOT NULL DEFAULT 'ACTIVE'", "`account_status` TEXT NOT NULL DEFAULT 'ACTIVE'");
    $addCol('users', 'deletion_requested_at', "`deletion_requested_at` TIMESTAMP NULL DEFAULT NULL", "`deletion_requested_at` DATETIME NULL DEFAULT NULL");
    $addCol('users', 'deleted_at', "`deleted_at` TIMESTAMP NULL DEFAULT NULL", "`deleted_at` DATETIME NULL DEFAULT NULL");
    $addCol('users', 'deleted_by', "`deleted_by` BIGINT UNSIGNED NULL DEFAULT NULL", "`deleted_by` INTEGER NULL DEFAULT NULL");
    $addCol('users', 'token_version', "`token_version` INT NOT NULL DEFAULT 1", "`token_version` INTEGER NOT NULL DEFAULT 1");
    $addCol('users', 'fcm_token', "`fcm_token` VARCHAR(255) NULL DEFAULT NULL", "`fcm_token` TEXT NULL DEFAULT NULL");

    // Extend orders table safely
    $addCol('orders', 'rejection_reason', "`rejection_reason` TEXT NULL DEFAULT NULL", "`rejection_reason` TEXT NULL DEFAULT NULL");
    $addCol('orders', 'cancellation_reason', "`cancellation_reason` TEXT NULL DEFAULT NULL", "`cancellation_reason` TEXT NULL DEFAULT NULL");
    $addCol('orders', 'shipped_at', "`shipped_at` TIMESTAMP NULL DEFAULT NULL", "`shipped_at` DATETIME NULL DEFAULT NULL");
    $addCol('orders', 'delivered_at', "`delivered_at` TIMESTAMP NULL DEFAULT NULL", "`delivered_at` DATETIME NULL DEFAULT NULL");
    $addCol('orders', 'payment_gateway', "`payment_gateway` VARCHAR(40) NULL DEFAULT NULL", "`payment_gateway` TEXT NULL DEFAULT NULL");
    $addCol('orders', 'gateway_order_id', "`gateway_order_id` VARCHAR(120) NULL DEFAULT NULL", "`gateway_order_id` TEXT NULL DEFAULT NULL");
    $addCol('orders', 'gateway_payment_id', "`gateway_payment_id` VARCHAR(120) NULL DEFAULT NULL", "`gateway_payment_id` TEXT NULL DEFAULT NULL");
    $addCol('orders', 'captured_amount', "`captured_amount` DECIMAL(10,2) NULL DEFAULT NULL", "`captured_amount` REAL NULL DEFAULT NULL");
    $addCol('orders', 'refunded_amount', "`refunded_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00", "`refunded_amount` REAL NOT NULL DEFAULT 0.00");
    $addCol('orders', 'return_window_ends_at', "`return_window_ends_at` TIMESTAMP NULL DEFAULT NULL", "`return_window_ends_at` DATETIME NULL DEFAULT NULL");
    $addCol('orders', 'settled_at', "`settled_at` TIMESTAMP NULL DEFAULT NULL", "`settled_at` DATETIME NULL DEFAULT NULL");

    // Ensure order_items has item_status & rejection_reason
    $addCol('order_items', 'item_status', "`item_status` VARCHAR(40) NOT NULL DEFAULT 'pending'", "`item_status` TEXT NOT NULL DEFAULT 'pending'");
    $addCol('order_items', 'rejection_reason', "`rejection_reason` TEXT NULL DEFAULT NULL", "`rejection_reason` TEXT NULL DEFAULT NULL");

    if ($isSqlite) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS notifications (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NULL,
                buyer_phone TEXT NULL,
                title TEXT NOT NULL,
                message TEXT NOT NULL,
                type TEXT NOT NULL DEFAULT 'order',
                data TEXT NULL,
                is_read INTEGER NOT NULL DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS device_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NULL,
                phone TEXT NULL,
                token TEXT NOT NULL UNIQUE,
                platform TEXT NOT NULL DEFAULT 'android',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
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
                gateway_payload TEXT NULL,
                settled_at DATETIME NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS seller_deletion_requests (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                seller_id INTEGER NOT NULL,
                requested_by INTEGER NULL,
                stage TEXT NOT NULL DEFAULT 'DELETION_REQUESTED',
                status TEXT NOT NULL DEFAULT 'DELETION_REQUESTED',
                request_reason TEXT NULL,
                admin_remarks TEXT NULL,
                hold_reason TEXT NULL,
                rejection_reason TEXT NULL,
                confirmation_token TEXT NULL,
                confirmation_expires_at DATETIME NULL,
                snapshot_json TEXT NULL,
                financial_summary_json TEXT NULL,
                approved_by INTEGER NULL,
                approved_at DATETIME NULL,
                deleted_by INTEGER NULL,
                deleted_at DATETIME NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS seller_deletion_checks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                deletion_request_id INTEGER NOT NULL,
                check_key TEXT NOT NULL,
                label TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'PENDING',
                is_blocking INTEGER NOT NULL DEFAULT 1,
                details_json TEXT NULL,
                resolved_by INTEGER NULL,
                resolved_at DATETIME NULL,
                notes TEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(deletion_request_id, check_key)
            );
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
            CREATE TABLE IF NOT EXISTS order_disputes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                order_id INTEGER NOT NULL,
                seller_id INTEGER NOT NULL,
                buyer_phone TEXT NULL,
                dispute_type TEXT NOT NULL DEFAULT 'complaint',
                status TEXT NOT NULL DEFAULT 'OPEN',
                claim_amount REAL NOT NULL DEFAULT 0.00,
                description TEXT NULL,
                resolution_notes TEXT NULL,
                resolved_by INTEGER NULL,
                resolved_at DATETIME NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS seller_deletion_audit_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                deletion_request_id INTEGER NULL,
                seller_id INTEGER NOT NULL,
                action TEXT NOT NULL,
                previous_stage TEXT NULL,
                new_stage TEXT NULL,
                previous_status TEXT NULL,
                new_status TEXT NULL,
                actor_id INTEGER NULL,
                actor_role TEXT NOT NULL DEFAULT 'admin',
                remarks TEXT NULL,
                metadata_json TEXT NULL,
                ip_address TEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
        ");
        return;
    }

    // MySQL tables
    $statements = [
        "CREATE TABLE IF NOT EXISTS `payment_transactions` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `seller_deletion_requests` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `seller_deletion_checks` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `seller_refund_records` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `seller_payouts` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `order_disputes` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `seller_deletion_audit_logs` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];
    foreach ($statements as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Throwable $e) {
        }
    }
}

function sendDeletionNotificationPhp(PDO $pdo, ?int $userId, ?string $buyerPhone, string $title, string $message, string $type, array $data = []): void
{
    ensureSellerDeletionSchemaPhp($pdo);
    try {
        $stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, buyer_phone, title, message, type, data, is_read, created_at, updated_at)
            VALUES (:uid, :phone, :title, :msg, :type, :data, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            ':uid' => $userId ?: null,
            ':phone' => $buyerPhone ?: null,
            ':title' => $title,
            ':msg' => $message,
            ':type' => $type,
            ':data' => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    } catch (Throwable $e) {
    }
}

function logSellerDeletionAuditPhp(
    PDO $pdo,
    ?int $deletionRequestId,
    int $sellerId,
    string $action,
    ?string $prevStage,
    ?string $newStage,
    ?string $prevStatus,
    ?string $newStatus,
    ?int $actorId,
    string $actorRole = 'admin',
    ?string $remarks = null,
    array $metadata = []
): void {
    ensureSellerDeletionSchemaPhp($pdo);
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $stmt = $pdo->prepare("
            INSERT INTO seller_deletion_audit_logs
                (deletion_request_id, seller_id, action, previous_stage, new_stage, previous_status, new_status, actor_id, actor_role, remarks, metadata_json, ip_address, created_at)
            VALUES
                (:del_id, :sid, :action, :ps, :ns, :pst, :nst, :aid, :arole, :remarks, :meta, :ip, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            ':del_id' => $deletionRequestId,
            ':sid' => $sellerId,
            ':action' => $action,
            ':ps' => $prevStage,
            ':ns' => $newStage,
            ':pst' => $prevStatus,
            ':nst' => $newStatus,
            ':aid' => $actorId,
            ':arole' => $actorRole,
            ':remarks' => $remarks,
            ':meta' => !empty($metadata) ? json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            ':ip' => $ip,
        ]);
    } catch (Throwable $e) {
    }
}

function getSellerOrdersDetailedPhp(PDO $pdo, int $sellerId): array
{
    $stmt = $pdo->prepare("
        SELECT DISTINCT o.*
        FROM orders o
        LEFT JOIN order_items oi ON oi.order_id = o.id
        WHERE oi.seller_id = :sid1 OR o.seller_id = :sid2
        ORDER BY o.id DESC
    ");
    $stmt->execute([':sid1' => $sellerId, ':sid2' => $sellerId]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($orders)) {
        return [];
    }

    $orderIds = array_map(fn($o) => (int) $o['id'], $orders);
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));

    $itemStmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id IN ({$placeholders}) ORDER BY id ASC");
    $itemStmt->execute($orderIds);
    $allItems = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

    $itemsByOrder = [];
    foreach ($allItems as $it) {
        $itemsByOrder[(int) $it['order_id']][] = $it;
    }

    $txByOrder = [];
    try {
        $txStmt = $pdo->prepare("SELECT * FROM payment_transactions WHERE order_id IN ({$placeholders}) ORDER BY id ASC");
        $txStmt->execute($orderIds);
        foreach ($txStmt->fetchAll(PDO::FETCH_ASSOC) as $tx) {
            $txByOrder[(int) $tx['order_id']][] = $tx;
        }
    } catch (Throwable $e) {
    }

    $disputesByOrder = [];
    try {
        $dStmt = $pdo->prepare("SELECT * FROM order_disputes WHERE order_id IN ({$placeholders}) AND seller_id = ? ORDER BY id DESC");
        $dStmt->execute(array_merge($orderIds, [$sellerId]));
        foreach ($dStmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $disputesByOrder[(int) $d['order_id']][] = $d;
        }
    } catch (Throwable $e) {
    }

    $refundsByOrder = [];
    try {
        $rStmt = $pdo->prepare("SELECT * FROM seller_refund_records WHERE order_id IN ({$placeholders}) ORDER BY id ASC");
        $rStmt->execute($orderIds);
        foreach ($rStmt->fetchAll(PDO::FETCH_ASSOC) as $rf) {
            $refundsByOrder[(int) $rf['order_id']][] = $rf;
        }
    } catch (Throwable $e) {
    }

    $config = getSellerDeletionConfigPhp();
    $dbNowTs = parseUtcTimestampPhp(getDbCurrentTimestampPhp($pdo)) ?? time();
    $returnDays = $config['return_window_days'];

    $enriched = [];
    foreach ($orders as $o) {
        $oid = (int) $o['id'];
        $items = $itemsByOrder[$oid] ?? [];
        $sellerItems = [];
        $otherItems = [];
        $sellerGross = 0.0;
        $orderItemsTotal = 0.0;

        foreach ($items as $it) {
            $sub = (float) ($it['subtotal'] ?? ((float) ($it['price'] ?? 0) * (int) ($it['quantity'] ?? 1)));
            $orderItemsTotal += $sub;
            if ((int) ($it['seller_id'] ?? 0) === $sellerId || (empty($it['seller_id']) && (int) ($o['seller_id'] ?? 0) === $sellerId)) {
                $sellerItems[] = $it;
                $sellerGross += $sub;
            } else {
                $otherItems[] = $it;
            }
        }

        if (empty($items) && (int) ($o['seller_id'] ?? 0) === $sellerId) {
            $sellerGross = (float) ($o['subtotal'] ?? $o['total_amount'] ?? 0);
            $orderItemsTotal = $sellerGross;
        }

        $isMultiVendor = !empty($otherItems);
        $rawOrderStatus = strtolower(trim((string) ($o['order_status'] ?? 'pending')));

        // Effective status for this seller's portion
        $effectiveStatus = $rawOrderStatus;
        if ($isMultiVendor && !empty($sellerItems)) {
            $itemStatuses = array_unique(array_map(fn($it) => strtolower(trim((string) ($it['item_status'] ?? 'pending'))), $sellerItems));
            if (count($itemStatuses) === 1 && $itemStatuses[0] !== 'pending') {
                $effectiveStatus = $itemStatuses[0];
            } elseif (count($itemStatuses) === 1 && $rawOrderStatus !== 'pending' && $itemStatuses[0] === 'pending') {
                $effectiveStatus = $rawOrderStatus;
            } elseif (count($itemStatuses) > 1) {
                $effectiveStatus = in_array('cancelled', $itemStatuses, true) || in_array('rejected', $itemStatuses, true)
                    ? ($rawOrderStatus !== 'pending' ? $rawOrderStatus : $itemStatuses[0])
                    : $rawOrderStatus;
            }
        }

        $txs = $txByOrder[$oid] ?? [];
        $captureTxs = array_values(array_filter($txs, fn($t) => strtolower((string) ($t['transaction_type'] ?? 'capture')) === 'capture'));
        $capturedFromTx = 0.0;
        $gatewaySettledFromTx = 0.0;
        $primaryGatewayPaymentId = trim((string) ($o['gateway_payment_id'] ?? ''));
        $primaryTxId = null;

        foreach ($captureTxs as $ct) {
            $cStatus = strtolower((string) ($ct['status'] ?? ''));
            if (in_array($cStatus, ['captured', 'settled', 'refunded'], true)) {
                $capturedFromTx += (float) $ct['amount'];
                if ($primaryTxId === null) {
                    $primaryTxId = (int) $ct['id'];
                }
                if ($primaryGatewayPaymentId === '' && !empty($ct['gateway_payment_id'])) {
                    $primaryGatewayPaymentId = trim((string) $ct['gateway_payment_id']);
                }
            }
            if ($cStatus === 'settled' || !empty($ct['settled_at'])) {
                $gatewaySettledFromTx += (float) $ct['amount'];
            }
        }

        $paymentStatus = strtolower(trim((string) ($o['payment_status'] ?? 'unpaid')));
        $paymentMethod = strtolower(trim((string) ($o['payment_method'] ?? 'cod')));
        $orderCapturedField = isset($o['captured_amount']) && $o['captured_amount'] !== null ? (float) $o['captured_amount'] : null;

        $isPaid = in_array($paymentStatus, ['paid', 'refunded', 'partially_refunded'], true)
            || ($orderCapturedField !== null && $orderCapturedField > 0)
            || ($capturedFromTx > 0);

        $capturedTotal = $capturedFromTx > 0
            ? $capturedFromTx
            : (($orderCapturedField !== null && $orderCapturedField > 0)
                ? $orderCapturedField
                : ($isPaid ? (float) ($o['total_amount'] ?? $sellerGross) : 0.0));

        // Seller proportional eligible share
        $sellerProportion = ($orderItemsTotal > 0.001) ? min(1.0, $sellerGross / $orderItemsTotal) : 1.0;
        $sellerPaidShare = round(($isMultiVendor ? $capturedTotal * $sellerProportion : $capturedTotal), 2);
        if ($sellerPaidShare <= 0 && $isPaid) {
            $sellerPaidShare = round($sellerGross, 2);
        }

        $allOrderRefunds = $refundsByOrder[$oid] ?? [];
        $sellerRefundRecords = array_values(array_filter($allOrderRefunds, fn($r) => (int) $r['seller_id'] === $sellerId));
        $sellerSucceededRefund = 0.0;
        foreach ($sellerRefundRecords as $sr) {
            if (($sr['status'] ?? '') === 'REFUND_SUCCESS') {
                $sellerSucceededRefund += (float) ($sr['refunded_amount'] ?? 0);
            }
        }
        $orderRefundedField = (float) ($o['refunded_amount'] ?? 0);
        if (!$isMultiVendor && $sellerSucceededRefund <= 0 && $orderRefundedField > 0 && $paymentStatus === 'refunded') {
            $sellerSucceededRefund = $orderRefundedField;
        }

        $otherRefundAllocated = 0.0;
        foreach ($allOrderRefunds as $rr) {
            if ((int) $rr['seller_id'] !== $sellerId && in_array($rr['status'] ?? '', ['REFUND_SUCCESS', 'REFUND_PROCESSING'], true)) {
                $otherRefundAllocated += (float) ($rr['eligible_refund_amount'] ?? $rr['refunded_amount'] ?? 0);
            }
        }

        $remainingOnOrder = max(0.0, round($capturedTotal - max($orderRefundedField, $sellerSucceededRefund) - $otherRefundAllocated, 2));
        $outstandingRefundAmount = max(0.0, round(min(max(0.0, $sellerPaidShare - $sellerSucceededRefund), $remainingOnOrder), 2));

        // Return window calculation
        $deliveredAt = $o['delivered_at'] ?? null;
        $returnWindowEndsAt = $o['return_window_ends_at'] ?? null;
        $effectiveWindowEndStr = $returnWindowEndsAt;
        $returnWindowActive = false;
        if ($effectiveStatus === 'delivered') {
            if (!empty($returnWindowEndsAt)) {
                $endTs = parseUtcTimestampPhp((string) $returnWindowEndsAt);
                if ($endTs !== null && $endTs > time()) {
                    $returnWindowActive = true;
                }
            } else {
                $fallbackStr = !empty($deliveredAt)
                    ? (string) $deliveredAt
                    : (string) ($o['updated_at'] ?? $o['created_at'] ?? '');
                $baseTs = parseUtcTimestampPhp($fallbackStr);
                if ($baseTs !== null) {
                    $calcEndTs = $baseTs + ($returnDays * 86400);
                    $effectiveWindowEndStr = gmdate('Y-m-d H:i:s', $calcEndTs);
                    if ($calcEndTs > $dbNowTs) {
                        $returnWindowActive = true;
                    }
                }
            }
        }

        $orderDisputes = $disputesByOrder[$oid] ?? [];
        $openDisputes = array_values(array_filter($orderDisputes, fn($d) => in_array(strtoupper((string) ($d['status'] ?? '')), ['OPEN', 'INVESTIGATING', 'ESCALATED'], true)));

        // Gateway settlement status for non-cancelled paid orders
        $hasGatewayUnsettledTx = !empty($captureTxs) && ($gatewaySettledFromTx <= 0) && empty($o['settled_at']);
        $isGatewaySettled = !empty($o['settled_at']) || ($gatewaySettledFromTx > 0) || (empty($captureTxs) && $effectiveStatus === 'delivered' && $isPaid);

        $enriched[] = [
            'order' => $o,
            'id' => $oid,
            'order_number' => $o['order_number'] ?? ('ORD-' . $oid),
            'buyer_name' => $o['buyer_name'] ?? '',
            'buyer_phone' => $o['buyer_phone'] ?? '',
            'raw_order_status' => $rawOrderStatus,
            'effective_status' => $effectiveStatus,
            'payment_method' => $paymentMethod,
            'payment_status' => $paymentStatus,
            'payment_gateway' => $o['payment_gateway'] ?? (!empty($primaryGatewayPaymentId) ? 'razorpay' : $paymentMethod),
            'gateway_payment_id' => $primaryGatewayPaymentId !== '' ? $primaryGatewayPaymentId : null,
            'primary_payment_transaction_id' => $primaryTxId,
            'is_multi_vendor' => $isMultiVendor,
            'seller_items' => $sellerItems,
            'seller_gross' => round($sellerGross, 2),
            'order_total_amount' => round((float) ($o['total_amount'] ?? 0), 2),
            'captured_total' => round($capturedTotal, 2),
            'seller_paid_share' => round($sellerPaidShare, 2),
            'is_paid' => $isPaid,
            'seller_succeeded_refund' => round($sellerSucceededRefund, 2),
            'outstanding_refund_amount' => round($outstandingRefundAmount, 2),
            'seller_refund_records' => $sellerRefundRecords,
            'delivered_at' => $deliveredAt,
            'return_window_ends_at' => $effectiveWindowEndStr,
            'return_window_active' => $returnWindowActive,
            'open_disputes' => $openDisputes,
            'all_disputes' => $orderDisputes,
            'has_gateway_unsettled_tx' => $hasGatewayUnsettledTx,
            'is_gateway_settled' => $isGatewaySettled,
            'settled_at' => $o['settled_at'] ?? null,
        ];
    }

    return $enriched;
}

function computeSellerFinancialAndChecklistPhp(PDO $pdo, int $sellerId, ?int $deletionRequestId = null): array
{
    ensureSellerDeletionSchemaPhp($pdo);
    $config = getSellerDeletionConfigPhp();
    $commissionRate = $config['commission_rate'];

    $uStmt = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
    $uStmt->execute([':id' => $sellerId]);
    $seller = $uStmt->fetch(PDO::FETCH_ASSOC);
    if (!$seller) {
        return ['error' => 'Seller not found'];
    }

    $prodStmt = $pdo->prepare("
        SELECT COUNT(*) AS total_products,
               SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_products
        FROM products WHERE seller_id = :sid
    ");
    $prodStmt->execute([':sid' => $sellerId]);
    $prodRow = $prodStmt->fetch(PDO::FETCH_ASSOC) ?: ['total_products' => 0, 'active_products' => 0];

    $enrichedOrders = getSellerOrdersDetailedPhp($pdo, $sellerId);

    $counts = [
        'total_orders' => count($enrichedOrders),
        'pending_orders' => 0,
        'confirmed_orders' => 0,
        'shipped_orders' => 0,
        'delivered_orders' => 0,
        'cancelled_orders' => 0,
    ];

    $totalSuccessfulPayments = 0.0;
    $deliveredPaidSales = 0.0;
    $codDeliveredUnsettledSales = 0.0;
    $onlineDeliveredUnsettledSales = 0.0;
    $settledOrdersCommission = 0.0;
    $unsettledGatewayPaymentsAmount = 0.0;
    $unsettledGatewayCount = 0;

    $unresolvedOrderBlockers = [];
    $stageAAutoCancellable = [];
    $stageBRequiresApproval = [];
    $stageCShippedBlocking = [];
    $stageDReturnWindowBlocking = [];
    $ordersRequiringRefund = [];

    foreach ($enrichedOrders as $eo) {
        $st = $eo['effective_status'];
        if ($st === 'pending') {
            $counts['pending_orders']++;
            $unresolvedOrderBlockers[] = ['order_id' => $eo['id'], 'order_number' => $eo['order_number'], 'reason' => 'Unaccepted pending order (Rule A)'];
            $stageAAutoCancellable[] = $eo['id'];
        } elseif (in_array($st, ['accepted', 'processing', 'packed'], true)) {
            $counts['confirmed_orders']++;
            $unresolvedOrderBlockers[] = ['order_id' => $eo['id'], 'order_number' => $eo['order_number'], 'reason' => "Confirmed ({$st}) order requires Admin cancellation approval (Rule B)"];
            $stageBRequiresApproval[] = $eo['id'];
        } elseif ($st === 'shipped') {
            $counts['shipped_orders']++;
            // Check if dispute/resolution closed? If still shipped, Rule C blocks auto-cancel
            $unresolvedOrderBlockers[] = ['order_id' => $eo['id'], 'order_number' => $eo['order_number'], 'reason' => 'Shipped order cannot be auto-cancelled; requires delivery, return, or dispute resolution (Rule C)'];
            $stageCShippedBlocking[] = $eo['id'];
        } elseif ($st === 'delivered') {
            $counts['delivered_orders']++;
            if ($eo['return_window_active']) {
                $unresolvedOrderBlockers[] = [
                    'order_id' => $eo['id'],
                    'order_number' => $eo['order_number'],
                    'reason' => 'Delivered order still within return/refund window until ' . $eo['return_window_ends_at'] . ' (Rule D)'
                ];
                $stageDReturnWindowBlocking[] = $eo['id'];
            }
            if (!empty($eo['open_disputes'])) {
                $unresolvedOrderBlockers[] = [
                    'order_id' => $eo['id'],
                    'order_number' => $eo['order_number'],
                    'reason' => 'Delivered order has ' . count($eo['open_disputes']) . ' open customer dispute(s) (Rule D)'
                ];
            }
        } elseif (in_array($st, ['cancelled', 'rejected'], true)) {
            $counts['cancelled_orders']++;
            if ($eo['is_paid'] && $eo['outstanding_refund_amount'] > 0.01) {
                $ordersRequiringRefund[] = $eo;
            }
        } else {
            $unresolvedOrderBlockers[] = ['order_id' => $eo['id'], 'order_number' => $eo['order_number'], 'reason' => "Order in status '{$st}'"];
        }

        if ($eo['is_paid'] && !in_array($st, ['cancelled', 'rejected'], true)) {
            $totalSuccessfulPayments += $eo['seller_paid_share'];
        }

        if ($st === 'delivered' && $eo['is_paid']) {
            $deliveredPaidSales += $eo['seller_gross'];
            if (!empty($eo['settled_at'])) {
                $settledOrdersCommission += round($eo['seller_gross'] * $commissionRate, 2);
            } else {
                if ($eo['payment_method'] === 'cod') {
                    $codDeliveredUnsettledSales += $eo['seller_gross'];
                } else {
                    $onlineDeliveredUnsettledSales += $eo['seller_gross'];
                }
            }
        }

        if (!in_array($st, ['cancelled', 'rejected'], true) && $eo['is_paid'] && $eo['has_gateway_unsettled_tx']) {
            $unsettledGatewayPaymentsAmount += $eo['seller_paid_share'];
            $unsettledGatewayCount++;
        }
    }

    // Refunds query
    $refundStmt = $pdo->prepare("SELECT * FROM seller_refund_records WHERE seller_id = :sid ORDER BY id ASC");
    $refundStmt->execute([':sid' => $sellerId]);
    $allRefunds = $refundStmt->fetchAll(PDO::FETCH_ASSOC);

    $refundStats = [
        'total_count' => count($allRefunds),
        'pending_count' => 0,
        'processing_count' => 0,
        'success_count' => 0,
        'failed_count' => 0,
        'review_count' => 0,
        'pending_amount' => 0.0,
        'refunded_total_amount' => 0.0,
        'failed_amount' => 0.0,
    ];
    foreach ($allRefunds as $rf) {
        $rst = strtoupper((string) ($rf['status'] ?? 'REFUND_PENDING'));
        $elig = (float) ($rf['eligible_refund_amount'] ?? 0);
        if ($rst === 'REFUND_SUCCESS') {
            $refundStats['success_count']++;
            $refundStats['refunded_total_amount'] += (float) ($rf['refunded_amount'] ?? $elig);
        } elseif ($rst === 'REFUND_FAILED') {
            $refundStats['failed_count']++;
            $refundStats['failed_amount'] += $elig;
            $refundStats['pending_amount'] += $elig;
            $refundStats['pending_count']++;
        } elseif ($rst === 'REFUND_PROCESSING') {
            $refundStats['processing_count']++;
            $refundStats['pending_amount'] += $elig;
            $refundStats['pending_count']++;
        } elseif ($rst === 'REFUND_REQUIRES_REVIEW') {
            $refundStats['review_count']++;
            $refundStats['pending_amount'] += $elig;
            $refundStats['pending_count']++;
        } else {
            $refundStats['pending_count']++;
            $refundStats['pending_amount'] += $elig;
        }
    }

    // Include paid cancelled orders that don't yet have a refund record
    $recordedOrderIds = array_map(fn($r) => (int) $r['order_id'], $allRefunds);
    $unqueuedRefundCount = 0;
    $unqueuedRefundAmount = 0.0;
    foreach ($ordersRequiringRefund as $reqRf) {
        if (!in_array($reqRf['id'], $recordedOrderIds, true)) {
            $unqueuedRefundCount++;
            $unqueuedRefundAmount += $reqRf['outstanding_refund_amount'];
        }
    }
    $totalPendingRefundsCount = $refundStats['pending_count'] + $unqueuedRefundCount;
    $totalPendingRefundsAmount = round($refundStats['pending_amount'] + $unqueuedRefundAmount, 2);

    // Payouts query
    $pStmt = $pdo->prepare("SELECT * FROM seller_payouts WHERE seller_id = :sid ORDER BY id DESC");
    $pStmt->execute([':sid' => $sellerId]);
    $payouts = $pStmt->fetchAll(PDO::FETCH_ASSOC);

    $pendingPayoutsCount = 0;
    $pendingPayoutsAmount = 0.0;
    $failedPayoutsCount = 0;
    $heldPayoutsCount = 0;
    $settledPayoutsGross = 0.0;
    $settledPayoutsNet = 0.0;
    $settledCommissionDeducted = 0.0;

    foreach ($payouts as $p) {
        $pst = strtoupper((string) ($p['status'] ?? 'PENDING'));
        $amt = (float) ($p['amount'] ?? 0);
        $net = (float) ($p['net_amount'] ?? $amt);
        if (in_array($pst, ['PENDING', 'PROCESSING'], true)) {
            $pendingPayoutsCount++;
            $pendingPayoutsAmount += $net;
        } elseif ($pst === 'FAILED') {
            $pendingPayoutsCount++;
            $failedPayoutsCount++;
            $pendingPayoutsAmount += $net;
        } elseif ($pst === 'HELD') {
            $pendingPayoutsCount++;
            $heldPayoutsCount++;
            $pendingPayoutsAmount += $net;
        } elseif ($pst === 'SETTLED') {
            $settledPayoutsGross += $amt;
            $settledPayoutsNet += $net;
            $settledCommissionDeducted += (float) ($p['commission_deducted'] ?? 0);
        }
    }

    // Disputes query
    $dStmt = $pdo->prepare("SELECT * FROM order_disputes WHERE seller_id = :sid ORDER BY id DESC");
    $dStmt->execute([':sid' => $sellerId]);
    $allDisputes = $dStmt->fetchAll(PDO::FETCH_ASSOC);
    $openDisputes = array_values(array_filter($allDisputes, fn($d) => in_array(strtoupper((string) ($d['status'] ?? '')), ['OPEN', 'INVESTIGATING', 'ESCALATED'], true)));
    $disputesClaimAmount = 0.0;
    foreach ($openDisputes as $od) {
        $disputesClaimAmount += (float) ($od['claim_amount'] ?? 0);
    }

    // Financial Settlement Calculation
    $grossCommissionTotal = round($deliveredPaidSales * $commissionRate, 2);
    $commissionOnUnsettled = round(($codDeliveredUnsettledSales + $onlineDeliveredUnsettledSales) * $commissionRate, 2);

    // Marketplace holds online earnings; COD orders mean seller holds buyer cash and owes commission.
    $onlineUnsettledNet = max(0.0, round($onlineDeliveredUnsettledSales * (1.0 - $commissionRate), 2));
    $codCommissionOwed = round($codDeliveredUnsettledSales * $commissionRate, 2);
    $onlineCommissionPending = round($onlineDeliveredUnsettledSales * $commissionRate, 2);

    $settledOnlinePayoutCoverage = max(0.0, $settledPayoutsNet + $settledCommissionDeducted);
    $remainingOnlineUnsettledNet = max(0.0, round($onlineUnsettledNet - $settledPayoutsNet, 2));
    $remainingOnlineCommission = ($settledOnlinePayoutCoverage >= ($onlineDeliveredUnsettledSales - 0.01) || $remainingOnlineUnsettledNet <= 0.01)
        ? 0.0
        : max(0.0, round($onlineCommissionPending - $settledCommissionDeducted, 2));

    $outstandingCommissions = max(0.0, round($codCommissionOwed + $remainingOnlineCommission, 2));

    $netPayableToSeller = max(0.0, round($remainingOnlineUnsettledNet - $codCommissionOwed - $disputesClaimAmount - $totalPendingRefundsAmount, 2));
    $availableSellerBalance = $netPayableToSeller;

    $outstandingFinancialObligations = round(
        $totalPendingRefundsAmount +
        $disputesClaimAmount +
        max(0.0, round($codCommissionOwed - $remainingOnlineUnsettledNet, 2)) +
        $unsettledGatewayPaymentsAmount,
        2
    );

    // Check GST preservation
    $gstSaved = null;
    try {
        $gStmt = $pdo->prepare("SELECT id, gstin, status FROM seller_gst_details WHERE seller_id = :sid LIMIT 1");
        $gStmt->execute([':sid' => $sellerId]);
        $gstSaved = $gStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
    }

    // Build 6 Verification Checks
    $ordersResolvedStatus = empty($unresolvedOrderBlockers) ? 'SUCCESS' : (!empty($stageCShippedBlocking) || !empty($stageDReturnWindowBlocking) ? 'ON_HOLD' : 'PENDING');

    $refundsConfirmedStatus = 'SUCCESS';
    if ($refundStats['failed_count'] > 0) {
        $refundsConfirmedStatus = 'FAILED';
    } elseif ($refundStats['review_count'] > 0) {
        $refundsConfirmedStatus = 'ON_HOLD';
    } elseif ($refundStats['processing_count'] > 0) {
        $refundsConfirmedStatus = 'PROCESSING';
    } elseif ($totalPendingRefundsCount > 0 || $unqueuedRefundCount > 0) {
        $refundsConfirmedStatus = 'PENDING';
    }

    $payoutsSettledStatus = 'SUCCESS';
    if ($failedPayoutsCount > 0) {
        $payoutsSettledStatus = 'FAILED';
    } elseif ($heldPayoutsCount > 0) {
        $payoutsSettledStatus = 'ON_HOLD';
    } elseif ($pendingPayoutsCount > 0 || $availableSellerBalance > 0.01) {
        $payoutsSettledStatus = 'PENDING';
    }

    $liabilitiesClearedStatus = 'SUCCESS';
    if ($totalPendingRefundsAmount > 0.01 && $refundStats['failed_count'] > 0) {
        $liabilitiesClearedStatus = 'FAILED';
    } elseif ($unsettledGatewayPaymentsAmount > 0.01 || $totalPendingRefundsAmount > 0.01 || $outstandingCommissions > 0.01 || $disputesClaimAmount > 0.01) {
        $liabilitiesClearedStatus = 'PENDING';
    }

    $disputesResolvedStatus = empty($openDisputes) ? 'SUCCESS' : 'ON_HOLD';
    $recordsPreservedStatus = 'SUCCESS';

    $checks = [
        [
            'check_key' => 'orders_resolved',
            'label' => 'All eligible orders resolved (Rules A–E)',
            'status' => $ordersResolvedStatus,
            'is_blocking' => 1,
            'details' => [
                'blockers' => $unresolvedOrderBlockers,
                'stage_a_pending_ids' => $stageAAutoCancellable,
                'stage_b_accepted_ids' => $stageBRequiresApproval,
                'stage_c_shipped_ids' => $stageCShippedBlocking,
                'stage_d_return_window_ids' => $stageDReturnWindowBlocking,
            ],
        ],
        [
            'check_key' => 'refunds_confirmed',
            'label' => 'All required refunds confirmed successful via gateway',
            'status' => $refundsConfirmedStatus,
            'is_blocking' => 1,
            'details' => array_merge($refundStats, [
                'unqueued_count' => $unqueuedRefundCount,
                'unqueued_amount' => round($unqueuedRefundAmount, 2),
                'total_pending_amount' => $totalPendingRefundsAmount,
            ]),
        ],
        [
            'check_key' => 'payouts_settled',
            'label' => 'All pending seller payouts settled or formally resolved',
            'status' => $payoutsSettledStatus,
            'is_blocking' => 1,
            'details' => [
                'pending_payouts_count' => $pendingPayoutsCount,
                'pending_payouts_amount' => round($pendingPayoutsAmount, 2),
                'available_seller_balance' => round($availableSellerBalance, 2),
            ],
        ],
        [
            'check_key' => 'liabilities_cleared',
            'label' => 'All financial liabilities, gateway settlements & commissions cleared',
            'status' => $liabilitiesClearedStatus,
            'is_blocking' => 1,
            'details' => [
                'unsettled_gateway_count' => $unsettledGatewayCount,
                'unsettled_gateway_amount' => round($unsettledGatewayPaymentsAmount, 2),
                'outstanding_commissions' => $outstandingCommissions,
                'refund_liabilities' => $totalPendingRefundsAmount,
                'dispute_claims' => round($disputesClaimAmount, 2),
            ],
        ],
        [
            'check_key' => 'disputes_resolved',
            'label' => 'No active disputes or customer complaints remain',
            'status' => $disputesResolvedStatus,
            'is_blocking' => 1,
            'details' => [
                'open_disputes_count' => count($openDisputes),
                'open_disputes' => $openDisputes,
            ],
        ],
        [
            'check_key' => 'records_preserved',
            'label' => 'GST, financial & order history preserved for retention/audit',
            'status' => $recordsPreservedStatus,
            'is_blocking' => 1,
            'details' => [
                'gst_record' => $gstSaved,
                'preserved_orders_count' => count($enrichedOrders),
                'preserved_refunds_count' => count($allRefunds),
                'preserved_payouts_count' => count($payouts),
            ],
        ],
    ];

    $allChecksPassed = true;
    foreach ($checks as $ck) {
        if ($ck['status'] !== 'SUCCESS') {
            $allChecksPassed = false;
            break;
        }
    }

    // Sync checks table if deletionRequestId given
    if ($deletionRequestId !== null) {
        foreach ($checks as $ck) {
            $selCk = $pdo->prepare("SELECT id FROM seller_deletion_checks WHERE deletion_request_id = :rid AND check_key = :k LIMIT 1");
            $selCk->execute([':rid' => $deletionRequestId, ':k' => $ck['check_key']]);
            $existingCk = $selCk->fetch(PDO::FETCH_ASSOC);
            $detailsJson = json_encode($ck['details'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $resolvedAt = $ck['status'] === 'SUCCESS' ? getDbCurrentTimestampPhp($pdo) : null;
            if ($existingCk) {
                $updCk = $pdo->prepare("
                    UPDATE seller_deletion_checks
                    SET label = :lbl, status = :st, details_json = :det, resolved_at = COALESCE(resolved_at, :rat), updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id
                ");
                $updCk->execute([':lbl' => $ck['label'], ':st' => $ck['status'], ':det' => $detailsJson, ':rat' => $resolvedAt, ':id' => $existingCk['id']]);
                if ($ck['status'] !== 'SUCCESS') {
                    $pdo->prepare("UPDATE seller_deletion_checks SET resolved_at = NULL WHERE id = :id")->execute([':id' => $existingCk['id']]);
                }
            } else {
                $insCk = $pdo->prepare("
                    INSERT INTO seller_deletion_checks (deletion_request_id, check_key, label, status, is_blocking, details_json, resolved_at, created_at, updated_at)
                    VALUES (:rid, :k, :lbl, :st, 1, :det, :rat, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                ");
                $insCk->execute([':rid' => $deletionRequestId, ':k' => $ck['check_key'], ':lbl' => $ck['label'], ':st' => $ck['status'], ':det' => $detailsJson, ':rat' => $resolvedAt]);
            }
        }
    }

    $metrics = [
        'total_products' => (int) ($prodRow['total_products'] ?? 0),
        'active_products' => (int) ($prodRow['active_products'] ?? 0),
        'total_orders' => $counts['total_orders'],
        'pending_orders' => $counts['pending_orders'],
        'confirmed_orders' => $counts['confirmed_orders'],
        'shipped_orders' => $counts['shipped_orders'],
        'delivered_orders' => $counts['delivered_orders'],
        'cancelled_orders' => $counts['cancelled_orders'],
        'total_successful_payments' => round($totalSuccessfulPayments, 2),
        'pending_refunds_count' => $totalPendingRefundsCount,
        'pending_refunds_amount' => $totalPendingRefundsAmount,
        'seller_wallet_balance' => round($availableSellerBalance, 2),
        'pending_payouts_count' => $pendingPayoutsCount,
        'pending_payouts_amount' => round($pendingPayoutsAmount, 2),
        'outstanding_commissions' => $outstandingCommissions,
        'open_disputes_count' => count($openDisputes),
    ];

    $settlement = [
        'commission_rate' => $commissionRate,
        'delivered_paid_sales' => round($deliveredPaidSales, 2),
        'marketplace_commission' => $grossCommissionTotal,
        'outstanding_commissions' => $outstandingCommissions,
        'available_seller_balance' => round($availableSellerBalance, 2),
        'pending_payouts_count' => $pendingPayoutsCount,
        'pending_payouts_amount' => round($pendingPayoutsAmount, 2),
        'settled_payouts_amount' => round($settledPayoutsNet, 2),
        'gateway_settled_ok' => ($unsettledGatewayCount === 0),
        'unsettled_gateway_count' => $unsettledGatewayCount,
        'unsettled_gateway_amount' => round($unsettledGatewayPaymentsAmount, 2),
        'pending_disputes_count' => count($openDisputes),
        'disputes_claim_amount' => round($disputesClaimAmount, 2),
        'refund_liabilities_amount' => $totalPendingRefundsAmount,
        'outstanding_financial_obligations' => $outstandingFinancialObligations,
        'settlement_cleared' => ($payoutsSettledStatus === 'SUCCESS' && $liabilitiesClearedStatus === 'SUCCESS' && $disputesResolvedStatus === 'SUCCESS'),
    ];

    return [
        'seller' => safeSeller($seller),
        'raw_seller' => $seller,
        'metrics' => $metrics,
        'settlement' => $settlement,
        'checks' => $checks,
        'all_checks_passed' => $allChecksPassed,
        'ready_for_approval' => $allChecksPassed,
        'orders_enriched' => $enrichedOrders,
        'refunds' => $allRefunds,
        'refund_stats' => $refundStats,
        'orders_requiring_refund' => $ordersRequiringRefund,
        'payouts' => $payouts,
        'disputes' => $allDisputes,
        'open_disputes' => $openDisputes,
        'gst' => $gstSaved,
        'blockers' => [
            'orders' => $unresolvedOrderBlockers,
            'stage_a_pending_ids' => $stageAAutoCancellable,
            'stage_b_accepted_ids' => $stageBRequiresApproval,
            'stage_c_shipped_ids' => $stageCShippedBlocking,
            'stage_d_return_window_ids' => $stageDReturnWindowBlocking,
        ],
    ];
}

function getStatusColorBadgePhp(string $status): string
{
    $st = strtoupper(trim($status));
    if (in_array($st, ['SUCCESS', 'REFUND_SUCCESS', 'SETTLED', 'RESOLVED', 'CLOSED', 'APPROVED_FOR_DELETION', 'DELETED'], true)) {
        return 'green';
    }
    if (in_array($st, ['PROCESSING', 'REFUND_PROCESSING', 'ACCOUNT_LOCKED', 'ORDER_VERIFICATION', 'FINANCIAL_SETTLEMENT', 'ADMIN_REVIEW', 'INVESTIGATING'], true)) {
        return 'blue';
    }
    if (in_array($st, ['FAILED', 'REFUND_FAILED', 'DELETION_REJECTED'], true)) {
        return 'red';
    }
    if (in_array($st, ['ON_HOLD', 'DELETION_ON_HOLD', 'HELD', 'ESCALATED', 'REFUND_REQUIRES_REVIEW'], true)) {
        return 'orange';
    }
    return 'yellow'; // PENDING, DELETION_REQUESTED, DELETION_PENDING, REFUND_PENDING, SETTLEMENT_PENDING, OPEN
}

function buildDeletionWorkspacePayloadPhp(PDO $pdo, int $deletionRequestId): ?array
{
    ensureSellerDeletionSchemaPhp($pdo);
    $stmt = $pdo->prepare("SELECT * FROM seller_deletion_requests WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $deletionRequestId]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$req) {
        return null;
    }

    $sellerId = (int) $req['seller_id'];
    $computed = computeSellerFinancialAndChecklistPhp($pdo, $sellerId, $deletionRequestId);
    if (!empty($computed['error'])) {
        return null;
    }

    // Update financial summary JSON on request row
    try {
        $updFin = $pdo->prepare("UPDATE seller_deletion_requests SET financial_summary_json = :fj WHERE id = :id");
        $updFin->execute([
            ':fj' => json_encode($computed['settlement'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':id' => $deletionRequestId,
        ]);
    } catch (Throwable $e) {
    }

    $logsStmt = $pdo->prepare("SELECT * FROM seller_deletion_audit_logs WHERE deletion_request_id = :rid OR seller_id = :sid ORDER BY id DESC");
    $logsStmt->execute([':rid' => $deletionRequestId, ':sid' => $sellerId]);
    $logs = $logsStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($logs as &$lg) {
        $lg['id'] = (int) $lg['id'];
        $lg['metadata'] = !empty($lg['metadata_json']) ? json_decode($lg['metadata_json'], true) : null;
    }
    unset($lg);

    $ordersOut = array_map(function ($eo) {
        return [
            'id' => $eo['id'],
            'order_number' => $eo['order_number'],
            'buyer_name' => $eo['buyer_name'],
            'buyer_phone' => $eo['buyer_phone'],
            'raw_order_status' => $eo['raw_order_status'],
            'effective_status' => $eo['effective_status'],
            'payment_method' => $eo['payment_method'],
            'payment_status' => $eo['payment_status'],
            'gateway_payment_id' => $eo['gateway_payment_id'],
            'is_multi_vendor' => $eo['is_multi_vendor'],
            'seller_gross' => $eo['seller_gross'],
            'order_total_amount' => $eo['order_total_amount'],
            'captured_total' => $eo['captured_total'],
            'seller_paid_share' => $eo['seller_paid_share'],
            'is_paid' => $eo['is_paid'],
            'seller_succeeded_refund' => $eo['seller_succeeded_refund'],
            'outstanding_refund_amount' => $eo['outstanding_refund_amount'],
            'delivered_at' => $eo['delivered_at'],
            'return_window_ends_at' => $eo['return_window_ends_at'],
            'return_window_active' => $eo['return_window_active'],
            'open_disputes_count' => count($eo['open_disputes']),
            'is_gateway_settled' => $eo['is_gateway_settled'],
            'settled_at' => $eo['settled_at'],
            'created_at' => $eo['order']['created_at'] ?? null,
        ];
    }, $computed['orders_enriched']);

    $refundsOut = array_map(function ($r) {
        return [
            'id' => (int) $r['id'],
            'idempotency_key' => $r['idempotency_key'],
            'order_id' => (int) $r['order_id'],
            'seller_id' => (int) $r['seller_id'],
            'gateway' => $r['gateway'],
            'gateway_payment_id' => $r['gateway_payment_id'],
            'gateway_refund_id' => $r['gateway_refund_id'],
            'order_amount' => (float) $r['order_amount'],
            'eligible_refund_amount' => (float) $r['eligible_refund_amount'],
            'refunded_amount' => (float) $r['refunded_amount'],
            'status' => $r['status'],
            'badge_color' => getStatusColorBadgePhp((string) $r['status']),
            'gateway_response' => !empty($r['gateway_response']) ? json_decode($r['gateway_response'], true) : null,
            'failure_reason' => $r['failure_reason'],
            'attempts' => (int) $r['attempts'],
            'last_attempted_at' => $r['last_attempted_at'],
            'confirmed_at' => $r['confirmed_at'],
            'created_at' => $r['created_at'],
        ];
    }, $computed['refunds']);

    $finalDeleteAllowed = (
        $req['stage'] === 'APPROVED_FOR_DELETION' &&
        $req['status'] === 'APPROVED_FOR_DELETION' &&
        !empty($req['confirmation_token']) &&
        $computed['all_checks_passed']
    );

    return [
        'deletion_request' => [
            'id' => (int) $req['id'],
            'seller_id' => $sellerId,
            'requested_by' => $req['requested_by'] ? (int) $req['requested_by'] : null,
            'stage' => $req['stage'],
            'status' => $req['status'],
            'badge_color' => getStatusColorBadgePhp((string) $req['status']),
            'request_reason' => $req['request_reason'],
            'admin_remarks' => $req['admin_remarks'],
            'hold_reason' => $req['hold_reason'],
            'rejection_reason' => $req['rejection_reason'],
            'has_confirmation_token' => !empty($req['confirmation_token']),
            'confirmation_token' => $req['confirmation_token'],
            'confirmation_expires_at' => $req['confirmation_expires_at'],
            'expected_confirmation_phrase' => "DELETE SELLER #{$sellerId}",
            'approved_by' => $req['approved_by'] ? (int) $req['approved_by'] : null,
            'approved_at' => $req['approved_at'],
            'deleted_by' => $req['deleted_by'] ? (int) $req['deleted_by'] : null,
            'deleted_at' => $req['deleted_at'],
            'created_at' => $req['created_at'],
            'updated_at' => $req['updated_at'],
            'final_delete_allowed' => $finalDeleteAllowed,
            'approve_allowed' => $computed['all_checks_passed'] && !in_array($req['status'], ['DELETED', 'DELETION_REJECTED'], true),
        ],
        'seller' => $computed['seller'],
        'metrics' => $computed['metrics'],
        'orders' => $ordersOut,
        'blockers' => $computed['blockers'],
        'refunds' => $refundsOut,
        'refund_stats' => $computed['refund_stats'],
        'payouts' => $computed['payouts'],
        'disputes' => $computed['disputes'],
        'settlement' => $computed['settlement'],
        'checks' => $computed['checks'],
        'all_checks_passed' => $computed['all_checks_passed'],
        'gst' => $computed['gst'],
        'audit_logs' => $logs,
    ];
}

function callRazorpayHttpPhp(string $method, string $url, string $keyId, string $keySecret, ?array $payload = null, array $extraHeaders = []): array
{
    $authHeader = 'Authorization: Basic ' . base64_encode("{$keyId}:{$keySecret}");
    $headers = array_merge([
        'Content-Type: application/json',
        $authHeader,
    ], $extraHeaders);
    $bodyStr = $payload !== null ? json_encode($payload) : null;
    $statusCode = 0;
    $raw = '';
    $err = '';

    if (PHP_SAPI === 'cli' && isset($GLOBALS['__SELLER_DELETION_TEST_HTTP_TRANSPORT']) && is_callable($GLOBALS['__SELLER_DELETION_TEST_HTTP_TRANSPORT'])) {
        return ($GLOBALS['__SELLER_DELETION_TEST_HTTP_TRANSPORT'])($method, $url, $headers, $bodyStr);
    }

    if (function_exists('curl_init') && preg_match('#^https?://#i', $url)) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        if ($bodyStr !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $bodyStr);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        $res = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($res === false) {
            $err = curl_error($ch);
        } else {
            $raw = (string) $res;
        }
        curl_close($ch);
    } else {
        $ctxOpts = [
            'http' => [
                'method' => strtoupper($method),
                'header' => implode("\r\n", $headers) . "\r\n",
                'timeout' => 20,
                'ignore_errors' => true,
            ],
        ];
        if ($bodyStr !== null) {
            $ctxOpts['http']['content'] = $bodyStr;
            $ctxOpts['http']['header'] .= 'Content-Length: ' . strlen($bodyStr) . "\r\n";
        }
        $res = @file_get_contents($url, false, stream_context_create($ctxOpts));
        $raw = $res !== false ? (string) $res : '';
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $statusCode = (int) $m[1];
        }
        if ($res === false && $statusCode === 0) {
            $err = 'HTTP request failed.';
        }
    }

    $decoded = $raw !== '' ? json_decode($raw, true) : null;
    return [
        'ok' => $statusCode >= 200 && $statusCode < 300,
        'statusCode' => $statusCode,
        'data' => is_array($decoded) ? $decoded : null,
        'raw' => $raw,
        'error' => $err,
    ];
}

function queueEligibleRefundsForSellerPhp(PDO $pdo, int $deletionRequestId, int $sellerId, ?int $adminId): array
{
    $computed = computeSellerFinancialAndChecklistPhp($pdo, $sellerId, $deletionRequestId);
    $queued = [];
    foreach ($computed['orders_requiring_refund'] ?? [] as $eo) {
        $orderId = (int) $eo['id'];
        $eligibleAmount = round((float) $eo['outstanding_refund_amount'], 2);
        if ($eligibleAmount <= 0.0) {
            continue;
        }
        $idempotencyKey = "refund_del_{$deletionRequestId}_ord_{$orderId}_sel_{$sellerId}";

        $chk = $pdo->prepare("SELECT * FROM seller_refund_records WHERE (order_id = :oid AND seller_id = :sid) OR idempotency_key = :ikey LIMIT 1");
        $chk->execute([':oid' => $orderId, ':sid' => $sellerId, ':ikey' => $idempotencyKey]);
        $existing = $chk->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            if ($existing['status'] !== 'REFUND_SUCCESS' && empty($existing['deletion_request_id'])) {
                $pdo->prepare("UPDATE seller_refund_records SET deletion_request_id = :did, updated_at = CURRENT_TIMESTAMP WHERE id = :id")
                    ->execute([':did' => $deletionRequestId, ':id' => $existing['id']]);
            }
            continue;
        }

        $gatewayPaymentId = $eo['gateway_payment_id'];
        $hasGatewayId = !empty($gatewayPaymentId);
        $initialStatus = $hasGatewayId ? 'REFUND_PENDING' : 'REFUND_REQUIRES_REVIEW';
        $failureReason = $hasGatewayId ? null : 'Paid order is missing original gateway_payment_id. Requires manual admin verification before refunding.';

        $ins = $pdo->prepare("
            INSERT INTO seller_refund_records
                (idempotency_key, deletion_request_id, order_id, order_item_id, seller_id, payment_transaction_id,
                 gateway, gateway_payment_id, order_amount, eligible_refund_amount, refunded_amount, status, failure_reason, initiated_by, created_at, updated_at)
            VALUES
                (:ikey, :did, :oid, NULL, :sid, :ptid,
                 :gw, :gpid, :oamt, :elamt, 0.00, :st, :fr, :init_by, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $ins->execute([
            ':ikey' => $idempotencyKey,
            ':did' => $deletionRequestId,
            ':oid' => $orderId,
            ':sid' => $sellerId,
            ':ptid' => $eo['primary_payment_transaction_id'] ?? null,
            ':gw' => $eo['payment_gateway'] ?: 'razorpay',
            ':gpid' => $gatewayPaymentId,
            ':oamt' => $eo['seller_gross'],
            ':elamt' => $eligibleAmount,
            ':st' => $initialStatus,
            ':fr' => $failureReason,
            ':init_by' => $adminId,
        ]);
        $queued[] = (int) $pdo->lastInsertId();
    }
    return $queued;
}

function executeSingleRefundRecordPhp(PDO $pdo, int $refundRecordId, ?int $adminId): array
{
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    $lockSuffix = ($driver === 'mysql') ? ' FOR UPDATE' : '';

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT * FROM seller_refund_records WHERE id = :id LIMIT 1" . $lockSuffix);
        $stmt->execute([':id' => $refundRecordId]);
        $rec = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$rec) {
            $pdo->rollBack();
            return ['status' => 'NOT_FOUND'];
        }
        if ($rec['status'] === 'REFUND_SUCCESS') {
            $pdo->commit();
            return ['status' => 'REFUND_SUCCESS', 'idempotent_skip' => true, 'record' => $rec];
        }

        $oStmt = $pdo->prepare("SELECT * FROM orders WHERE id = :oid LIMIT 1" . $lockSuffix);
        $oStmt->execute([':oid' => (int) $rec['order_id']]);
        $order = $oStmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            $pdo->rollBack();
            return ['status' => 'REFUND_REQUIRES_REVIEW', 'error' => 'Original order record missing.'];
        }

        $captured = isset($order['captured_amount']) && $order['captured_amount'] !== null ? (float) $order['captured_amount'] : 0.0;
        if ($captured <= 0) {
            $ptStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) AS s FROM payment_transactions WHERE order_id = :oid AND transaction_type = 'capture' AND status IN ('captured','settled','refunded')");
            $ptStmt->execute([':oid' => (int) $order['id']]);
            $captured = (float) ($ptStmt->fetchColumn() ?: 0);
        }
        if ($captured <= 0 && strtolower((string) $order['payment_status']) === 'paid') {
            $captured = (float) $order['total_amount'];
        }

        $alreadyOrderRefunded = (float) ($order['refunded_amount'] ?? 0);
        $eligibleAmt = round((float) $rec['eligible_refund_amount'], 2);
        $remainingCap = max(0.0, round($captured - $alreadyOrderRefunded, 2));

        if ($eligibleAmt <= 0 || $eligibleAmt > ($remainingCap + 0.01)) {
            $reason = "Refund cap validation failed: requested {$eligibleAmt}, remaining captured refundable balance is {$remainingCap}.";
            $pdo->prepare("UPDATE seller_refund_records SET status = 'REFUND_REQUIRES_REVIEW', failure_reason = :r, updated_at = CURRENT_TIMESTAMP WHERE id = :id")
                ->execute([':r' => $reason, ':id' => $refundRecordId]);
            $pdo->commit();
            return ['status' => 'REFUND_REQUIRES_REVIEW', 'error' => $reason];
        }

        $gatewayPaymentId = trim((string) ($rec['gateway_payment_id'] ?? $order['gateway_payment_id'] ?? ''));
        if ($gatewayPaymentId === '') {
            $reason = 'Missing gateway_payment_id; cannot process automatic gateway refund.';
            $pdo->prepare("UPDATE seller_refund_records SET status = 'REFUND_REQUIRES_REVIEW', failure_reason = :r, updated_at = CURRENT_TIMESTAMP WHERE id = :id")
                ->execute([':r' => $reason, ':id' => $refundRecordId]);
            $pdo->commit();
            return ['status' => 'REFUND_REQUIRES_REVIEW', 'error' => $reason];
        }

        // Release DB lock while calling external Razorpay HTTP API after marking REFUND_PROCESSING
        $pdo->prepare("
            UPDATE seller_refund_records
            SET status = 'REFUND_PROCESSING',
                attempts = attempts + 1,
                last_attempted_at = CURRENT_TIMESTAMP,
                initiated_by = COALESCE(initiated_by, :aid),
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ")->execute([':aid' => $adminId, ':id' => $refundRecordId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    // Re-read outside lock for HTTP call
    $recStmt = $pdo->prepare("SELECT * FROM seller_refund_records WHERE id = :id LIMIT 1");
    $recStmt->execute([':id' => $refundRecordId]);
    $rec = $recStmt->fetch(PDO::FETCH_ASSOC);

    $oStmt = $pdo->prepare("SELECT * FROM orders WHERE id = :oid LIMIT 1");
    $oStmt->execute([':oid' => (int) $rec['order_id']]);
    $order = $oStmt->fetch(PDO::FETCH_ASSOC);

    $cfg = getSellerDeletionConfigPhp();
    $keyId = $cfg['razorpay_key_id'];
    $keySecret = $cfg['razorpay_key_secret'];
    $baseUrl = $cfg['razorpay_api_base'];

    if ($keyId === '' || $keySecret === '') {
        $reason = 'Razorpay credentials (RAZORPAY_KEY_ID / RAZORPAY_KEY_SECRET) are not configured. Real gateway confirmation is required.';
        $pdo->prepare("
            UPDATE seller_refund_records
            SET status = 'REFUND_FAILED',
                failure_reason = :fr,
                gateway_response = :gr,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ")->execute([
            ':fr' => $reason,
            ':gr' => json_encode(['error' => ['code' => 'MISSING_GATEWAY_CREDENTIALS', 'description' => $reason]]),
            ':id' => $refundRecordId,
        ]);
        sendDeletionNotificationPhp($pdo, null, $order['buyer_phone'] ?? null, 'Refund Processing Delayed', "Refund for order {$order['order_number']} encountered a gateway configuration error.", 'refund_failed', ['order_id' => (int) $order['id'], 'refund_id' => $refundRecordId]);
        return ['status' => 'REFUND_FAILED', 'error' => $reason];
    }

    $gatewayPaymentId = trim((string) ($rec['gateway_payment_id'] ?: $order['gateway_payment_id']));
    $existingGatewayRefundId = trim((string) ($rec['gateway_refund_id'] ?? ''));

    // Notify Buyer if first attempt
    if ((int) ($rec['attempts'] ?? 1) === 1) {
        sendDeletionNotificationPhp($pdo, null, $order['buyer_phone'] ?? null, 'Refund Initiated', "A refund of ₹" . number_format((float) $rec['eligible_refund_amount'], 2) . " has been initiated for order {$order['order_number']}.", 'refund_initiated', ['order_id' => (int) $order['id'], 'refund_id' => $refundRecordId]);
    }

    if ($existingGatewayRefundId !== '') {
        $httpRes = callRazorpayHttpPhp('GET', "{$baseUrl}/refunds/" . urlencode($existingGatewayRefundId), $keyId, $keySecret);
    } else {
        $amountPaise = (int) round(((float) $rec['eligible_refund_amount']) * 100);
        $httpRes = callRazorpayHttpPhp(
            'POST',
            "{$baseUrl}/payments/" . urlencode($gatewayPaymentId) . "/refund",
            $keyId,
            $keySecret,
            [
                'amount' => $amountPaise,
                'speed' => 'normal',
                'notes' => [
                    'deletion_request_id' => (string) ($rec['deletion_request_id'] ?? ''),
                    'order_id' => (string) $rec['order_id'],
                    'seller_id' => (string) $rec['seller_id'],
                    'idempotency_key' => (string) $rec['idempotency_key'],
                ],
            ],
            ['X-Razorpay-Idempotency: ' . $rec['idempotency_key']]
        );
    }

    $rawJson = $httpRes['raw'] !== '' ? $httpRes['raw'] : json_encode(['error' => ['description' => $httpRes['error'] ?: ('HTTP ' . $httpRes['statusCode'])]]);
    $data = $httpRes['data'];

    if (!$httpRes['ok'] || !is_array($data) || empty($data['id'])) {
        $errMsg = (string) ($data['error']['description'] ?? $data['error']['reason'] ?? $httpRes['error'] ?: ("Gateway returned HTTP {$httpRes['statusCode']}"));
        $pdo->prepare("
            UPDATE seller_refund_records
            SET status = 'REFUND_FAILED',
                failure_reason = :fr,
                gateway_response = :gr,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ")->execute([':fr' => $errMsg, ':gr' => $rawJson, ':id' => $refundRecordId]);
        sendDeletionNotificationPhp($pdo, null, $order['buyer_phone'] ?? null, 'Refund Failed', "Refund attempt for order {$order['order_number']} failed: {$errMsg}", 'refund_failed', ['order_id' => (int) $order['id'], 'refund_id' => $refundRecordId]);
        return ['status' => 'REFUND_FAILED', 'error' => $errMsg];
    }

    $gwStatus = strtolower(trim((string) ($data['status'] ?? '')));
    $gwRefundId = (string) $data['id'];

    if ($gwStatus === 'processed') {
        $refundedAmt = isset($data['amount']) && is_numeric($data['amount'])
            ? min((float) $rec['eligible_refund_amount'], round(((float) $data['amount']) / 100, 2))
            : (float) $rec['eligible_refund_amount'];

        $pdo->beginTransaction();
        try {
            $pdo->prepare("
                UPDATE seller_refund_records
                SET status = 'REFUND_SUCCESS',
                    gateway_refund_id = :grid,
                    refunded_amount = :ramt,
                    gateway_response = :gr,
                    failure_reason = NULL,
                    confirmed_at = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ")->execute([
                ':grid' => $gwRefundId,
                ':ramt' => $refundedAmt,
                ':gr' => $rawJson,
                ':id' => $refundRecordId,
            ]);

            $newOrderRefunded = round(((float) ($order['refunded_amount'] ?? 0)) + $refundedAmt, 2);
            $capTotal = isset($order['captured_amount']) && $order['captured_amount'] !== null ? (float) $order['captured_amount'] : (float) $order['total_amount'];
            $newPayStatus = ($newOrderRefunded >= $capTotal - 0.01) ? 'refunded' : 'partially_refunded';

            $pdo->prepare("UPDATE orders SET refunded_amount = :ra, payment_status = :ps, updated_at = CURRENT_TIMESTAMP WHERE id = :oid")
                ->execute([':ra' => $newOrderRefunded, ':ps' => $newPayStatus, ':oid' => (int) $order['id']]);

            $pdo->prepare("
                INSERT INTO payment_transactions
                    (order_id, seller_id, gateway, gateway_order_id, gateway_payment_id, transaction_type, amount, currency, status, gateway_payload, created_at, updated_at)
                VALUES
                    (:oid, :sid, 'razorpay', :goid, :gpid, 'refund', :amt, 'INR', 'refunded', :payload, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ")->execute([
                ':oid' => (int) $order['id'],
                ':sid' => (int) $rec['seller_id'],
                ':goid' => $order['gateway_order_id'] ?? null,
                ':gpid' => $gwRefundId,
                ':amt' => $refundedAmt,
                ':payload' => $rawJson,
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        sendDeletionNotificationPhp($pdo, null, $order['buyer_phone'] ?? null, 'Refund Succeeded', "Your refund of ₹" . number_format($refundedAmt, 2) . " for order {$order['order_number']} has been confirmed ({$gwRefundId}).", 'refund_success', ['order_id' => (int) $order['id'], 'refund_id' => $refundRecordId, 'gateway_refund_id' => $gwRefundId]);
        return ['status' => 'REFUND_SUCCESS', 'gateway_refund_id' => $gwRefundId, 'refunded_amount' => $refundedAmt];
    }

    if (in_array($gwStatus, ['pending', 'created', 'initiated'], true)) {
        $pdo->prepare("
            UPDATE seller_refund_records
            SET status = 'REFUND_PROCESSING',
                gateway_refund_id = :grid,
                gateway_response = :gr,
                failure_reason = NULL,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ")->execute([':grid' => $gwRefundId, ':gr' => $rawJson, ':id' => $refundRecordId]);
        return ['status' => 'REFUND_PROCESSING', 'gateway_refund_id' => $gwRefundId];
    }

    $errMsg = (string) ($data['error']['description'] ?? "Gateway returned non-success refund status: {$gwStatus}");
    $pdo->prepare("
        UPDATE seller_refund_records
        SET status = 'REFUND_FAILED',
            gateway_refund_id = :grid,
            gateway_response = :gr,
            failure_reason = :fr,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
    ")->execute([':grid' => $gwRefundId, ':gr' => $rawJson, ':fr' => $errMsg, ':id' => $refundRecordId]);
    sendDeletionNotificationPhp($pdo, null, $order['buyer_phone'] ?? null, 'Refund Failed', "Refund for order {$order['order_number']} failed: {$errMsg}", 'refund_failed', ['order_id' => (int) $order['id'], 'refund_id' => $refundRecordId]);
    return ['status' => 'REFUND_FAILED', 'error' => $errMsg];
}

function deriveNextStageAndStatusPhp(array $computed, string $currentStage, string $currentStatus): array
{
    if (in_array($currentStatus, ['DELETED', 'DELETION_REJECTED', 'APPROVED_FOR_DELETION'], true)) {
        return [$currentStage, $currentStatus];
    }
    if ($currentStatus === 'DELETION_ON_HOLD') {
        return [$currentStage, 'DELETION_ON_HOLD'];
    }

    $checksByKey = [];
    foreach ($computed['checks'] as $ck) {
        $checksByKey[$ck['check_key']] = $ck['status'];
    }

    if (($checksByKey['orders_resolved'] ?? 'PENDING') !== 'SUCCESS') {
        return ['ORDER_VERIFICATION', 'ORDER_VERIFICATION'];
    }
    if (($checksByKey['refunds_confirmed'] ?? 'PENDING') === 'FAILED') {
        return ['REFUND_PROCESSING', 'REFUND_FAILED'];
    }
    if (($checksByKey['refunds_confirmed'] ?? 'PENDING') !== 'SUCCESS') {
        return ['REFUND_PROCESSING', 'REFUND_PROCESSING'];
    }
    if (
        ($checksByKey['payouts_settled'] ?? 'PENDING') !== 'SUCCESS' ||
        ($checksByKey['liabilities_cleared'] ?? 'PENDING') !== 'SUCCESS' ||
        ($checksByKey['disputes_resolved'] ?? 'PENDING') !== 'SUCCESS'
    ) {
        return ['FINANCIAL_SETTLEMENT', 'SETTLEMENT_PENDING'];
    }
    if (!empty($computed['all_checks_passed'])) {
        return ['ADMIN_REVIEW', 'ADMIN_REVIEW'];
    }

    return [$currentStage, $currentStatus];
}

/**
 * High-level Service Operations called by api/seller_deletion.php & Admin UI
 */
function deletionListRequestsPhp(PDO $pdo, array $filters = []): array
{
    ensureSellerDeletionSchemaPhp($pdo);
    $where = ['1=1'];
    $params = [];

    if (!empty($filters['status'])) {
        $where[] = 'sdr.status = :status';
        $params[':status'] = trim((string) $filters['status']);
    }
    if (!empty($filters['stage'])) {
        $where[] = 'sdr.stage = :stage';
        $params[':stage'] = trim((string) $filters['stage']);
    }
    if (!empty($filters['search'])) {
        $s = '%' . trim((string) $filters['search']) . '%';
        $where[] = '(u.seller_name LIKE :s1 OR u.name LIKE :s2 OR u.email LIKE :s3 OR u.phone LIKE :s4 OR CAST(sdr.seller_id AS CHAR) LIKE :s5)';
        $params[':s1'] = $s;
        $params[':s2'] = $s;
        $params[':s3'] = $s;
        $params[':s4'] = $s;
        $params[':s5'] = $s;
    }

    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    if ($driver === 'sqlite' && isset($params[':s5'])) {
        $where[count($where) - 1] = '(u.seller_name LIKE :s1 OR u.name LIKE :s2 OR u.email LIKE :s3 OR u.phone LIKE :s4 OR CAST(sdr.seller_id AS TEXT) LIKE :s5)';
    }

    $whereSql = implode(' AND ', $where);
    $stmt = $pdo->prepare("
        SELECT sdr.*, u.seller_name, u.name AS user_name, u.email AS user_email, u.phone AS user_phone, u.account_status AS seller_account_status
        FROM seller_deletion_requests sdr
        LEFT JOIN users u ON sdr.seller_id = u.id
        WHERE {$whereSql}
        ORDER BY sdr.id DESC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $kpis = ['total' => 0, 'pending' => 0, 'processing' => 0, 'failed' => 0, 'on_hold' => 0, 'approved' => 0, 'deleted' => 0, 'rejected' => 0];
    $allKpiRows = $pdo->query("SELECT status FROM seller_deletion_requests")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($allKpiRows as $kr) {
        $kpis['total']++;
        $st = strtoupper((string) $kr['status']);
        if ($st === 'DELETED') $kpis['deleted']++;
        elseif ($st === 'DELETION_REJECTED') $kpis['rejected']++;
        elseif ($st === 'APPROVED_FOR_DELETION') $kpis['approved']++;
        elseif ($st === 'DELETION_ON_HOLD') $kpis['on_hold']++;
        elseif ($st === 'REFUND_FAILED') $kpis['failed']++;
        elseif (in_array($st, ['DELETION_REQUESTED', 'SETTLEMENT_PENDING'], true)) $kpis['pending']++;
        else $kpis['processing']++;
    }

    $requests = array_map(function ($r) {
        $snapshot = !empty($r['snapshot_json']) ? json_decode($r['snapshot_json'], true) : null;
        $finSummary = !empty($r['financial_summary_json']) ? json_decode($r['financial_summary_json'], true) : null;
        return [
            'id' => (int) $r['id'],
            'seller_id' => (int) $r['seller_id'],
            'seller_name' => $r['seller_name'] ?: ($r['user_name'] ?: "Seller #{$r['seller_id']}"),
            'seller_email' => getPublicSellerEmailPhp($r['user_email'] ?? null, 'seller'),
            'seller_phone' => $r['user_phone'] ?? null,
            'seller_account_status' => $r['seller_account_status'] ?? 'ACTIVE',
            'stage' => $r['stage'],
            'status' => $r['status'],
            'badge_color' => getStatusColorBadgePhp((string) $r['status']),
            'request_reason' => $r['request_reason'],
            'admin_remarks' => $r['admin_remarks'],
            'metrics' => $snapshot['metrics'] ?? null,
            'financial_summary' => $finSummary,
            'approved_at' => $r['approved_at'],
            'deleted_at' => $r['deleted_at'],
            'created_at' => $r['created_at'],
            'updated_at' => $r['updated_at'],
        ];
    }, $rows);

    return ['requests' => $requests, 'kpis' => $kpis];
}

function deletionCreateRequestPhp(PDO $pdo, int $sellerId, int $adminId, string $reason = '', string $remarks = ''): array
{
    ensureSellerDeletionSchemaPhp($pdo);
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    $lockSuffix = ($driver === 'mysql') ? ' FOR UPDATE' : '';

    $pdo->beginTransaction();
    try {
        $uStmt = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1" . $lockSuffix);
        $uStmt->execute([':id' => $sellerId]);
        $seller = $uStmt->fetch(PDO::FETCH_ASSOC);
        if (!$seller) {
            $pdo->rollBack();
            return ['error' => 'Seller not found.', 'code' => 404];
        }
        if (($seller['role'] ?? 'seller') === 'admin') {
            $pdo->rollBack();
            return ['error' => 'Master admin account cannot be deleted.', 'code' => 422];
        }
        if (($seller['account_status'] ?? 'ACTIVE') === 'DELETED') {
            $pdo->rollBack();
            return ['error' => 'Seller account has already been permanently deleted.', 'code' => 409];
        }

        $exStmt = $pdo->prepare("
            SELECT id, status FROM seller_deletion_requests
            WHERE seller_id = :sid AND status NOT IN ('DELETED', 'DELETION_REJECTED')
            ORDER BY id DESC LIMIT 1
        " . $lockSuffix);
        $exStmt->execute([':sid' => $sellerId]);
        $existingReq = $exStmt->fetch(PDO::FETCH_ASSOC);
        if ($existingReq) {
            $pdo->rollBack();
            return ['error' => "An active deletion request (#{$existingReq['id']}) already exists for this seller in status {$existingReq['status']}.", 'code' => 409, 'existing_request_id' => (int) $existingReq['id']];
        }

        $prevAccountStatus = $seller['account_status'] ?? 'ACTIVE';
        $pdo->prepare("
            UPDATE users
            SET account_status = 'DELETION_PENDING',
                deletion_requested_at = COALESCE(deletion_requested_at, CURRENT_TIMESTAMP),
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :sid
        ")->execute([':sid' => $sellerId]);

        $computed = computeSellerFinancialAndChecklistPhp($pdo, $sellerId, null);
        $snapshotPayload = [
            'seller' => $computed['seller'],
            'metrics' => $computed['metrics'],
            'captured_at' => gmdate('c'),
        ];

        $ins = $pdo->prepare("
            INSERT INTO seller_deletion_requests
                (seller_id, requested_by, stage, status, request_reason, admin_remarks, snapshot_json, financial_summary_json, created_at, updated_at)
            VALUES
                (:sid, :req_by, 'DELETION_REQUESTED', 'DELETION_REQUESTED', :reason, :remarks, :snap, :fin, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $ins->execute([
            ':sid' => $sellerId,
            ':req_by' => $adminId,
            ':reason' => $reason !== '' ? $reason : 'Admin requested seller account deletion',
            ':remarks' => $remarks !== '' ? $remarks : null,
            ':snap' => json_encode($snapshotPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':fin' => json_encode($computed['settlement'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
        $delReqId = (int) $pdo->lastInsertId();

        // Populate initial checks rows
        computeSellerFinancialAndChecklistPhp($pdo, $sellerId, $delReqId);

        logSellerDeletionAuditPhp(
            $pdo,
            $delReqId,
            $sellerId,
            'DELETION_REQUESTED',
            null,
            'DELETION_REQUESTED',
            $prevAccountStatus,
            'DELETION_REQUESTED',
            $adminId,
            'admin',
            $remarks !== '' ? $remarks : ($reason ?: 'Deletion request initiated'),
            ['metrics' => $computed['metrics']]
        );

        sendDeletionNotificationPhp(
            $pdo,
            $sellerId,
            $seller['phone'] ?? null,
            'Account Deletion Requested',
            'Your seller account has entered DELETION_PENDING status following an administrative deletion request. New orders are temporarily blocked during verification.',
            'seller_deletion_requested',
            ['deletion_request_id' => $delReqId, 'seller_id' => $sellerId]
        );

        $pdo->commit();
        return ['statusCode' => 201, 'data' => buildDeletionWorkspacePayloadPhp($pdo, $delReqId)];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['error' => 'Failed to create deletion request: ' . $e->getMessage(), 'code' => 500];
    }
}

function deletionLockAccountPhp(PDO $pdo, int $delReqId, int $adminId, string $remarks = ''): array
{
    ensureSellerDeletionSchemaPhp($pdo);
    $stmt = $pdo->prepare("SELECT * FROM seller_deletion_requests WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $delReqId]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$req) return ['error' => 'Deletion request not found.', 'code' => 404];
    if (in_array($req['status'], ['DELETED', 'DELETION_REJECTED'], true)) {
        return ['error' => "Cannot lock account when deletion request is {$req['status']}.", 'code' => 409];
    }

    $sellerId = (int) $req['seller_id'];
    $pdo->beginTransaction();
    try {
        $pdo->prepare("
            UPDATE users
            SET account_status = 'LOCKED',
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :sid
        ")->execute([':sid' => $sellerId]);

        $newStage = 'ACCOUNT_LOCKED';
        $newStatus = $req['status'] === 'DELETION_ON_HOLD' ? 'DELETION_ON_HOLD' : 'ACCOUNT_LOCKED';

        $pdo->prepare("
            UPDATE seller_deletion_requests
            SET stage = :st,
                status = :stat,
                admin_remarks = COALESCE(:rem, admin_remarks),
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ")->execute([
            ':st' => $newStage,
            ':stat' => $newStatus,
            ':rem' => $remarks !== '' ? $remarks : null,
            ':id' => $delReqId,
        ]);

        logSellerDeletionAuditPhp($pdo, $delReqId, $sellerId, 'ACCOUNT_LOCKED', $req['stage'], $newStage, $req['status'], $newStatus, $adminId, 'admin', $remarks ?: 'Seller account locked; new orders blocked');
        $pdo->commit();
        return ['statusCode' => 200, 'data' => buildDeletionWorkspacePayloadPhp($pdo, $delReqId)];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['error' => 'Failed to lock seller account: ' . $e->getMessage(), 'code' => 500];
    }
}

function deletionVerifyOrdersPhp(PDO $pdo, int $delReqId, int $adminId, array $input = []): array
{
    ensureSellerDeletionSchemaPhp($pdo);
    $stmt = $pdo->prepare("SELECT * FROM seller_deletion_requests WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $delReqId]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$req) return ['error' => 'Deletion request not found.', 'code' => 404];
    if (in_array($req['status'], ['DELETED', 'DELETION_REJECTED'], true)) {
        return ['error' => "Cannot verify orders when deletion request is {$req['status']}.", 'code' => 409];
    }

    $sellerId = (int) $req['seller_id'];
    $approveAllAccepted = !empty($input['approve_accepted_cancellations']);
    $approveOrderIds = [];
    if (!empty($input['approve_cancellation_order_ids']) && is_array($input['approve_cancellation_order_ids'])) {
        $approveOrderIds = array_map('intval', $input['approve_cancellation_order_ids']);
    }
    $remarks = trim((string) ($input['remarks'] ?? $input['admin_remarks'] ?? ''));

    $pdo->beginTransaction();
    try {
        $enrichedOrders = getSellerOrdersDetailedPhp($pdo, $sellerId);
        $cancelledCount = 0;
        $acceptedCancelledCount = 0;
        $cancelledOrderNotices = [];

        foreach ($enrichedOrders as $eo) {
            $oid = (int) $eo['id'];
            $st = $eo['effective_status'];
            $shouldCancelStageA = ($st === 'pending');
            $shouldCancelStageB = in_array($st, ['accepted', 'processing', 'packed'], true)
                && ($approveAllAccepted || in_array($oid, $approveOrderIds, true));

            if (!$shouldCancelStageA && !$shouldCancelStageB) {
                continue;
            }

            $cancelReason = $shouldCancelStageB
                ? 'Cancelled after admin cancellation approval during seller deletion verification'
                : 'Cancelled automatically due to seller account deletion (unaccepted order)';

            // Restore stock for seller items
            $restoreStmt = $pdo->prepare("UPDATE products SET stock = stock + :qty WHERE id = :pid");
            foreach ($eo['seller_items'] as $si) {
                if (!empty($si['product_id']) && !in_array(strtolower((string) ($si['item_status'] ?? 'pending')), ['cancelled', 'rejected'], true)) {
                    $restoreStmt->execute([':qty' => (int) $si['quantity'], ':pid' => (int) $si['product_id']]);
                }
            }

            // Update seller's order items
            if (empty($eo['is_multi_vendor'])) {
                $pdo->prepare("UPDATE order_items SET item_status = 'cancelled', rejection_reason = :rr, updated_at = CURRENT_TIMESTAMP WHERE order_id = :oid AND (seller_id = :sid OR seller_id IS NULL)")
                    ->execute([':rr' => $cancelReason, ':oid' => $oid, ':sid' => $sellerId]);
            } else {
                $pdo->prepare("UPDATE order_items SET item_status = 'cancelled', rejection_reason = :rr, updated_at = CURRENT_TIMESTAMP WHERE order_id = :oid AND seller_id = :sid")
                    ->execute([':rr' => $cancelReason, ':oid' => $oid, ':sid' => $sellerId]);
            }

            // Check whether ANY active item from another seller remains in this order
            $remStmt = $pdo->prepare("SELECT COUNT(*) FROM order_items WHERE order_id = :oid AND COALESCE(item_status, 'pending') NOT IN ('cancelled', 'rejected')");
            $remStmt->execute([':oid' => $oid]);
            $remainingActiveItems = (int) $remStmt->fetchColumn();

            if ($remainingActiveItems === 0) {
                $pdo->prepare("UPDATE orders SET order_status = 'cancelled', cancellation_reason = :cr, updated_at = CURRENT_TIMESTAMP WHERE id = :oid")
                    ->execute([':cr' => $cancelReason, ':oid' => $oid]);
            }

            $cancelledCount++;
            if ($shouldCancelStageB) $acceptedCancelledCount++;
            $cancelledOrderNotices[] = [
                'order_id' => $oid,
                'order_number' => $eo['order_number'],
                'buyer_phone' => $eo['buyer_phone'],
                'is_paid' => $eo['is_paid'],
                'reason' => $cancelReason,
            ];
        }

        // Queue eligible refunds for all cancelled/rejected paid orders (Rules A, B & E)
        $queuedRefunds = queueEligibleRefundsForSellerPhp($pdo, $delReqId, $sellerId, $adminId);

        $computed = computeSellerFinancialAndChecklistPhp($pdo, $sellerId, $delReqId);
        $next = ($req['status'] === 'DELETION_ON_HOLD')
            ? ['ORDER_VERIFICATION', 'DELETION_ON_HOLD']
            : deriveNextStageAndStatusPhp($computed, 'ORDER_VERIFICATION', 'ORDER_VERIFICATION');

        $pdo->prepare("
            UPDATE seller_deletion_requests
            SET stage = :st,
                status = :stat,
                admin_remarks = COALESCE(:rem, admin_remarks),
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ")->execute([
            ':st' => $next[0],
            ':stat' => $next[1],
            ':rem' => $remarks !== '' ? $remarks : null,
            ':id' => $delReqId,
        ]);

        logSellerDeletionAuditPhp(
            $pdo,
            $delReqId,
            $sellerId,
            'ORDERS_VERIFIED',
            $req['stage'],
            $next[0],
            $req['status'],
            $next[1],
            $adminId,
            'admin',
            $remarks ?: "Verified orders: {$cancelledCount} cancelled ({$acceptedCancelledCount} Stage B approved), " . count($queuedRefunds) . " refund(s) queued",
            ['cancelled_count' => $cancelledCount, 'accepted_cancelled_count' => $acceptedCancelledCount, 'queued_refund_ids' => $queuedRefunds]
        );

        $pdo->commit();

        foreach ($cancelledOrderNotices as $notice) {
            sendDeletionNotificationPhp(
                $pdo,
                null,
                $notice['buyer_phone'],
                'Order Cancelled',
                "Your order {$notice['order_number']} has been cancelled because the seller account is undergoing deletion." . ($notice['is_paid'] ? ' An automatic refund has been queued.' : ''),
                'order_cancelled',
                ['order_id' => $notice['order_id'], 'order_number' => $notice['order_number']]
            );
        }

        return ['statusCode' => 200, 'data' => buildDeletionWorkspacePayloadPhp($pdo, $delReqId)];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['error' => 'Failed to verify orders: ' . $e->getMessage(), 'code' => 500];
    }
}

function deletionProcessRefundsPhp(PDO $pdo, int $delReqId, int $adminId, string $remarks = ''): array
{
    ensureSellerDeletionSchemaPhp($pdo);
    $stmt = $pdo->prepare("SELECT * FROM seller_deletion_requests WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $delReqId]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$req) return ['error' => 'Deletion request not found.', 'code' => 404];
    if (in_array($req['status'], ['DELETED', 'DELETION_REJECTED'], true)) {
        return ['error' => "Cannot process refunds when deletion request is {$req['status']}.", 'code' => 409];
    }

    $sellerId = (int) $req['seller_id'];
    queueEligibleRefundsForSellerPhp($pdo, $delReqId, $sellerId, $adminId);

    $rStmt = $pdo->prepare("SELECT * FROM seller_refund_records WHERE seller_id = :sid ORDER BY id ASC");
    $rStmt->execute([':sid' => $sellerId]);
    $refundRows = $rStmt->fetchAll(PDO::FETCH_ASSOC);

    $results = [];
    foreach ($refundRows as $rf) {
        if ($rf['status'] === 'REFUND_SUCCESS' || $rf['status'] === 'REFUND_REQUIRES_REVIEW') {
            $results[] = ['refund_id' => (int) $rf['id'], 'order_id' => (int) $rf['order_id'], 'status' => $rf['status'], 'skipped' => true];
            continue;
        }
        $execRes = executeSingleRefundRecordPhp($pdo, (int) $rf['id'], $adminId);
        $results[] = array_merge(['refund_id' => (int) $rf['id'], 'order_id' => (int) $rf['order_id']], $execRes);
    }

    $computed = computeSellerFinancialAndChecklistPhp($pdo, $sellerId, $delReqId);
    $next = ($req['status'] === 'DELETION_ON_HOLD')
        ? ['REFUND_PROCESSING', 'DELETION_ON_HOLD']
        : deriveNextStageAndStatusPhp($computed, 'REFUND_PROCESSING', 'REFUND_PROCESSING');

    $pdo->prepare("
        UPDATE seller_deletion_requests
        SET stage = :st,
            status = :stat,
            admin_remarks = COALESCE(:rem, admin_remarks),
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
    ")->execute([
        ':st' => $next[0],
        ':stat' => $next[1],
        ':rem' => $remarks !== '' ? $remarks : null,
        ':id' => $delReqId,
    ]);

    logSellerDeletionAuditPhp(
        $pdo,
        $delReqId,
        $sellerId,
        'REFUNDS_PROCESSED',
        $req['stage'],
        $next[0],
        $req['status'],
        $next[1],
        $adminId,
        'admin',
        $remarks ?: 'Executed gateway refund batch',
        ['results' => $results]
    );

    $payload = buildDeletionWorkspacePayloadPhp($pdo, $delReqId);
    $payload['refund_execution_results'] = $results;
    return ['statusCode' => 200, 'data' => $payload];
}

function deletionVerifySettlementPhp(PDO $pdo, int $delReqId, int $adminId, string $remarks = ''): array
{
    ensureSellerDeletionSchemaPhp($pdo);
    $stmt = $pdo->prepare("SELECT * FROM seller_deletion_requests WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $delReqId]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$req) return ['error' => 'Deletion request not found.', 'code' => 404];
    if (in_array($req['status'], ['DELETED', 'DELETION_REJECTED'], true)) {
        return ['error' => "Cannot verify settlement when deletion request is {$req['status']}.", 'code' => 409];
    }

    $sellerId = (int) $req['seller_id'];
    $computed = computeSellerFinancialAndChecklistPhp($pdo, $sellerId, $delReqId);
    $next = ($req['status'] === 'DELETION_ON_HOLD')
        ? ['FINANCIAL_SETTLEMENT', 'DELETION_ON_HOLD']
        : deriveNextStageAndStatusPhp($computed, 'FINANCIAL_SETTLEMENT', $req['status']);

    $pdo->prepare("
        UPDATE seller_deletion_requests
        SET stage = :st,
            status = :stat,
            admin_remarks = COALESCE(:rem, admin_remarks),
            financial_summary_json = :fj,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
    ")->execute([
        ':st' => $next[0],
        ':stat' => $next[1],
        ':rem' => $remarks !== '' ? $remarks : null,
        ':fj' => json_encode($computed['settlement'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ':id' => $delReqId,
    ]);

    logSellerDeletionAuditPhp(
        $pdo,
        $delReqId,
        $sellerId,
        'SETTLEMENT_VERIFIED',
        $req['stage'],
        $next[0],
        $req['status'],
        $next[1],
        $adminId,
        'admin',
        $remarks ?: ($next[1] === 'ADMIN_REVIEW' ? 'All financial settlements cleared' : 'Settlement verification found remaining blockers'),
        ['settlement' => $computed['settlement']]
    );

    return ['statusCode' => 200, 'data' => buildDeletionWorkspacePayloadPhp($pdo, $delReqId)];
}

function deletionSettlePayoutPhp(PDO $pdo, int $delReqId, int $adminId, array $input = []): array
{
    ensureSellerDeletionSchemaPhp($pdo);
    $stmt = $pdo->prepare("SELECT * FROM seller_deletion_requests WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $delReqId]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$req) return ['error' => 'Deletion request not found.', 'code' => 404];
    if (in_array($req['status'], ['DELETED', 'DELETION_REJECTED'], true)) {
        return ['error' => "Cannot settle payouts when deletion request is {$req['status']}.", 'code' => 409];
    }

    $sellerId = (int) $req['seller_id'];
    $computed = computeSellerFinancialAndChecklistPhp($pdo, $sellerId, $delReqId);
    $checksByKey = [];
    foreach ($computed['checks'] as $ck) {
        $checksByKey[$ck['check_key']] = $ck['status'];
    }

    if (($checksByKey['orders_resolved'] ?? 'PENDING') !== 'SUCCESS') {
        return ['error' => 'Cannot release seller payout before all eligible orders are resolved.', 'code' => 409];
    }
    if (($checksByKey['refunds_confirmed'] ?? 'PENDING') !== 'SUCCESS') {
        return ['error' => 'Cannot release seller payout while refunds are pending or failed.', 'code' => 409];
    }
    if (($checksByKey['disputes_resolved'] ?? 'PENDING') !== 'SUCCESS') {
        return ['error' => 'Cannot release seller payout while active customer disputes remain open.', 'code' => 409];
    }
    if (($computed['settlement']['unsettled_gateway_count'] ?? 0) > 0 && empty($input['confirm_gateway_settled'])) {
        return ['error' => 'Cannot release seller payout while payment gateway transactions remain unsettled.', 'code' => 409];
    }

    $payoutRef = trim((string) ($input['payout_reference'] ?? ('PAYOUT-DEL-' . $delReqId . '-' . time())));
    $notes = trim((string) ($input['notes'] ?? $input['remarks'] ?? 'Formal payout settlement verified during seller deletion'));
    $payoutId = !empty($input['payout_id']) ? (int) $input['payout_id'] : null;

    $pdo->beginTransaction();
    try {
        if (!empty($input['confirm_gateway_settled'])) {
            $pdo->prepare("
                UPDATE payment_transactions
                SET status = 'settled', settled_at = COALESCE(settled_at, CURRENT_TIMESTAMP), updated_at = CURRENT_TIMESTAMP
                WHERE order_id IN (SELECT id FROM orders WHERE seller_id = :sid1 UNION SELECT DISTINCT order_id FROM order_items WHERE seller_id = :sid2)
                  AND transaction_type = 'capture' AND status = 'captured'
            ")->execute([':sid1' => $sellerId, ':sid2' => $sellerId]);
        }

        if ($payoutId) {
            $pdo->prepare("
                UPDATE seller_payouts
                SET status = 'SETTLED',
                    deletion_request_id = COALESCE(deletion_request_id, :did),
                    payout_reference = :pref,
                    settled_by = :aid,
                    settled_at = CURRENT_TIMESTAMP,
                    notes = :notes,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :pid AND seller_id = :sid
            ")->execute([
                ':did' => $delReqId,
                ':pref' => $payoutRef,
                ':aid' => $adminId,
                ':notes' => $notes,
                ':pid' => $payoutId,
                ':sid' => $sellerId,
            ]);
        } else {
            // Settle all pending/held/failed payouts
            $pdo->prepare("
                UPDATE seller_payouts
                SET status = 'SETTLED',
                    deletion_request_id = COALESCE(deletion_request_id, :did),
                    payout_reference = COALESCE(payout_reference, :pref),
                    settled_by = :aid,
                    settled_at = CURRENT_TIMESTAMP,
                    notes = COALESCE(:notes, notes),
                    updated_at = CURRENT_TIMESTAMP
                WHERE seller_id = :sid AND status IN ('PENDING', 'PROCESSING', 'HELD', 'FAILED')
            ")->execute([
                ':did' => $delReqId,
                ':pref' => $payoutRef,
                ':aid' => $adminId,
                ':notes' => $notes,
                ':sid' => $sellerId,
            ]);

            $afterExistingPayouts = computeSellerFinancialAndChecklistPhp($pdo, $sellerId, null);
            $remainingBal = (float) ($afterExistingPayouts['settlement']['available_seller_balance'] ?? 0);
            $outComm = (float) ($afterExistingPayouts['settlement']['outstanding_commissions'] ?? 0);
            if ($remainingBal > 0.01 || $outComm > 0.01) {
                $grossDelivered = (float) ($afterExistingPayouts['settlement']['delivered_paid_sales'] ?? 0);
                $pdo->prepare("
                    INSERT INTO seller_payouts
                        (seller_id, deletion_request_id, amount, commission_deducted, refund_recovery_deducted, net_amount, status, payout_reference, settled_by, settled_at, notes, created_at, updated_at)
                    VALUES
                        (:sid, :did, :amt, :comm, 0.00, :net, 'SETTLED', :pref, :aid, CURRENT_TIMESTAMP, :notes, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                ")->execute([
                    ':sid' => $sellerId,
                    ':did' => $delReqId,
                    ':amt' => round($remainingBal + $outComm, 2),
                    ':comm' => $outComm,
                    ':net' => $remainingBal,
                    ':pref' => $payoutRef,
                    ':aid' => $adminId,
                    ':notes' => $notes,
                ]);
            }
        }

        // Mark seller's delivered paid orders settled_at
        $pdo->prepare("
            UPDATE orders
            SET settled_at = COALESCE(settled_at, CURRENT_TIMESTAMP), updated_at = CURRENT_TIMESTAMP
            WHERE order_status = 'delivered'
              AND (seller_id = :sid1 OR id IN (SELECT DISTINCT order_id FROM order_items WHERE seller_id = :sid2))
        ")->execute([':sid1' => $sellerId, ':sid2' => $sellerId]);

        $recomputed = computeSellerFinancialAndChecklistPhp($pdo, $sellerId, $delReqId);
        $next = deriveNextStageAndStatusPhp($recomputed, 'FINANCIAL_SETTLEMENT', 'FINANCIAL_SETTLEMENT');

        $pdo->prepare("
            UPDATE seller_deletion_requests
            SET stage = :st, status = :stat, financial_summary_json = :fj, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ")->execute([
            ':st' => $next[0],
            ':stat' => $next[1],
            ':fj' => json_encode($recomputed['settlement'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':id' => $delReqId,
        ]);

        logSellerDeletionAuditPhp($pdo, $delReqId, $sellerId, 'PAYOUT_SETTLED', $req['stage'], $next[0], $req['status'], $next[1], $adminId, 'admin', "{$notes} (Ref: {$payoutRef})");
        $pdo->commit();
        return ['statusCode' => 200, 'data' => buildDeletionWorkspacePayloadPhp($pdo, $delReqId)];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['error' => 'Failed to settle payout: ' . $e->getMessage(), 'code' => 500];
    }
}

function deletionResolveDisputePhp(PDO $pdo, int $delReqId, int $adminId, array $input = []): array
{
    ensureSellerDeletionSchemaPhp($pdo);
    $stmt = $pdo->prepare("SELECT * FROM seller_deletion_requests WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $delReqId]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$req) return ['error' => 'Deletion request not found.', 'code' => 404];
    if (in_array($req['status'], ['DELETED', 'DELETION_REJECTED'], true)) {
        return ['error' => "Cannot resolve disputes when deletion request is {$req['status']}.", 'code' => 409];
    }

    $sellerId = (int) $req['seller_id'];
    $disputeId = !empty($input['dispute_id']) ? (int) $input['dispute_id'] : 0;
    $resolutionStatus = strtoupper(trim((string) ($input['resolution'] ?? $input['status'] ?? 'RESOLVED')));
    if (!in_array($resolutionStatus, ['RESOLVED', 'CLOSED'], true)) {
        $resolutionStatus = 'RESOLVED';
    }
    $notes = trim((string) ($input['resolution_notes'] ?? $input['remarks'] ?? 'Dispute formally resolved by administrator'));
    $newOrderStatus = strtolower(trim((string) ($input['new_order_status'] ?? '')));
    $markDelivered = !empty($input['mark_delivered']) || $newOrderStatus === 'delivered';
    $triggerRefund = !empty($input['issue_refund']) || !empty($input['mark_returned']) || $newOrderStatus === 'cancelled' || $newOrderStatus === 'returned';
    $closeReturnWindow = !empty($input['close_return_window']) || !empty($input['return_window_completed']);
    $cfg = getSellerDeletionConfigPhp();

    $pdo->beginTransaction();
    try {
        $targetDisputes = [];
        if ($disputeId > 0) {
            $dStmt = $pdo->prepare("SELECT * FROM order_disputes WHERE id = :did AND seller_id = :sid LIMIT 1");
            $dStmt->execute([':did' => $disputeId, ':sid' => $sellerId]);
            $dispute = $dStmt->fetch(PDO::FETCH_ASSOC);
            if (!$dispute) {
                $pdo->rollBack();
                return ['error' => 'Dispute not found for this seller.', 'code' => 404];
            }
            $targetDisputes[] = $dispute;
            $pdo->prepare("
                UPDATE order_disputes
                SET status = :st, resolution_notes = :rn, resolved_by = :aid, resolved_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
                WHERE id = :did
            ")->execute([':st' => $resolutionStatus, ':rn' => $notes, ':aid' => $adminId, ':did' => $disputeId]);
        } else {
            $dStmt = $pdo->prepare("SELECT * FROM order_disputes WHERE seller_id = :sid AND status IN ('OPEN', 'INVESTIGATING', 'ESCALATED')");
            $dStmt->execute([':sid' => $sellerId]);
            $targetDisputes = $dStmt->fetchAll(PDO::FETCH_ASSOC);
            $pdo->prepare("
                UPDATE order_disputes
                SET status = :st, resolution_notes = :rn, resolved_by = :aid, resolved_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
                WHERE seller_id = :sid AND status IN ('OPEN', 'INVESTIGATING', 'ESCALATED')
            ")->execute([':st' => $resolutionStatus, ':rn' => $notes, ':aid' => $adminId, ':sid' => $sellerId]);
        }

        $cancelNotices = [];
        $processedOrderIds = [];
        foreach ($targetDisputes as $disp) {
            if (!empty($disp['order_id'])) {
                $processedOrderIds[] = (int) $disp['order_id'];
            }
        }
        if (!empty($input['order_id'])) {
            $explicitOid = (int) $input['order_id'];
            if ($explicitOid > 0 && !in_array($explicitOid, $processedOrderIds, true)) {
                $processedOrderIds[] = $explicitOid;
            }
        }
        $processedOrderIds = array_values(array_unique($processedOrderIds));

        foreach ($processedOrderIds as $oid) {
            $oStmt = $pdo->prepare("SELECT * FROM orders WHERE id = :oid LIMIT 1");
            $oStmt->execute([':oid' => $oid]);
            $ord = $oStmt->fetch(PDO::FETCH_ASSOC);
            if (!$ord) continue;

            // Verify seller owns this order
            $ownStmt = $pdo->prepare("SELECT COUNT(*) FROM order_items WHERE order_id = :oid AND seller_id = :sid");
            $ownStmt->execute([':oid' => $oid, ':sid' => $sellerId]);
            $ownsViaItem = (int) $ownStmt->fetchColumn() > 0;
            if (!$ownsViaItem && (int) ($ord['seller_id'] ?? 0) !== $sellerId) {
                continue;
            }

            $curOrderStatus = strtolower((string) ($ord['order_status'] ?? 'pending'));
            if ($markDelivered && $curOrderStatus === 'shipped') {
                $winEnd = $closeReturnWindow
                    ? gmdate('Y-m-d H:i:s', time() - 60)
                    : gmdate('Y-m-d H:i:s', time() + ($cfg['return_window_days'] * 86400));
                $pdo->prepare("
                    UPDATE orders
                    SET order_status = 'delivered',
                        delivered_at = COALESCE(delivered_at, CURRENT_TIMESTAMP),
                        return_window_ends_at = :rwend,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = :oid
                ")->execute([':rwend' => $winEnd, ':oid' => $oid]);
                $pdo->prepare("UPDATE order_items SET item_status = 'delivered', updated_at = CURRENT_TIMESTAMP WHERE order_id = :oid AND (seller_id = :sid OR seller_id IS NULL)")
                    ->execute([':oid' => $oid, ':sid' => $sellerId]);
            } elseif ($closeReturnWindow && $curOrderStatus === 'delivered') {
                $winEnd = gmdate('Y-m-d H:i:s', time() - 60);
                $pdo->prepare("
                    UPDATE orders
                    SET return_window_ends_at = :rwend,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = :oid
                ")->execute([':rwend' => $winEnd, ':oid' => $oid]);
            } elseif ($triggerRefund && !in_array($curOrderStatus, ['cancelled', 'rejected'], true)) {
                $oiStmt = $pdo->prepare("SELECT product_id, quantity, item_status FROM order_items WHERE order_id = :oid AND (seller_id = :sid OR seller_id IS NULL)");
                $oiStmt->execute([':oid' => $oid, ':sid' => $sellerId]);
                $restoreStmt = $pdo->prepare("UPDATE products SET stock = stock + :qty WHERE id = :pid");
                foreach ($oiStmt->fetchAll(PDO::FETCH_ASSOC) as $si) {
                    if (!empty($si['product_id']) && !in_array(strtolower((string) ($si['item_status'] ?? 'pending')), ['cancelled', 'rejected'], true)) {
                        $restoreStmt->execute([':qty' => (int) $si['quantity'], ':pid' => (int) $si['product_id']]);
                    }
                }

                $cancelReason = "Cancelled via dispute/return resolution: {$notes}";
                $pdo->prepare("UPDATE order_items SET item_status = 'cancelled', rejection_reason = :cr, updated_at = CURRENT_TIMESTAMP WHERE order_id = :oid AND (seller_id = :sid OR seller_id IS NULL)")
                    ->execute([':cr' => $notes, ':oid' => $oid, ':sid' => $sellerId]);

                $remStmt = $pdo->prepare("SELECT COUNT(*) FROM order_items WHERE order_id = :oid AND COALESCE(item_status, 'pending') NOT IN ('cancelled', 'rejected')");
                $remStmt->execute([':oid' => $oid]);
                if ((int) $remStmt->fetchColumn() === 0) {
                    $pdo->prepare("UPDATE orders SET order_status = 'cancelled', cancellation_reason = :cr, updated_at = CURRENT_TIMESTAMP WHERE id = :oid")
                        ->execute([':cr' => $cancelReason, ':oid' => $oid]);
                }

                $cancelNotices[] = [
                    'order_id' => $oid,
                    'order_number' => $ord['order_number'] ?? ('ORD-' . $oid),
                    'buyer_phone' => $ord['buyer_phone'] ?? null,
                ];
            }
        }

        if ($triggerRefund) {
            queueEligibleRefundsForSellerPhp($pdo, $delReqId, $sellerId, $adminId);
        }

        $recomputed = computeSellerFinancialAndChecklistPhp($pdo, $sellerId, $delReqId);
        $next = ($req['status'] === 'DELETION_ON_HOLD')
            ? [$req['stage'], 'DELETION_ON_HOLD']
            : deriveNextStageAndStatusPhp($recomputed, $req['stage'], $req['status']);

        $pdo->prepare("
            UPDATE seller_deletion_requests
            SET stage = :st, status = :stat, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ")->execute([':st' => $next[0], ':stat' => $next[1], ':id' => $delReqId]);

        logSellerDeletionAuditPhp($pdo, $delReqId, $sellerId, 'DISPUTE_RESOLVED', $req['stage'], $next[0], $req['status'], $next[1], $adminId, 'admin', $notes, ['dispute_id' => $disputeId ?: 'ALL']);
        $pdo->commit();

        foreach ($cancelNotices as $cn) {
            sendDeletionNotificationPhp(
                $pdo,
                null,
                $cn['buyer_phone'],
                'Order Cancelled',
                "Order {$cn['order_number']} was cancelled following dispute resolution. Eligible refund has been queued.",
                'order_cancelled',
                ['order_id' => $cn['order_id'], 'order_number' => $cn['order_number']]
            );
        }

        return ['statusCode' => 200, 'data' => buildDeletionWorkspacePayloadPhp($pdo, $delReqId)];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['error' => 'Failed to resolve dispute: ' . $e->getMessage(), 'code' => 500];
    }
}

function deletionHoldPhp(PDO $pdo, int $delReqId, int $adminId, string $reason, string $remarks = ''): array
{
    ensureSellerDeletionSchemaPhp($pdo);
    $stmt = $pdo->prepare("SELECT * FROM seller_deletion_requests WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $delReqId]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$req) return ['error' => 'Deletion request not found.', 'code' => 404];
    if (in_array($req['status'], ['DELETED', 'DELETION_REJECTED'], true)) {
        return ['error' => "Cannot put on hold when deletion request is {$req['status']}.", 'code' => 409];
    }

    $sellerId = (int) $req['seller_id'];
    $holdMsg = trim($reason) !== '' ? trim($reason) : ($remarks !== '' ? $remarks : 'Placed on administrative hold');
    $pdo->prepare("
        UPDATE seller_deletion_requests
        SET status = 'DELETION_ON_HOLD',
            hold_reason = :hr,
            admin_remarks = COALESCE(:rem, admin_remarks),
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
    ")->execute([':hr' => $holdMsg, ':rem' => $remarks !== '' ? $remarks : null, ':id' => $delReqId]);

    logSellerDeletionAuditPhp($pdo, $delReqId, $sellerId, 'DELETION_PUT_ON_HOLD', $req['stage'], $req['stage'], $req['status'], 'DELETION_ON_HOLD', $adminId, 'admin', $holdMsg);
    return ['statusCode' => 200, 'data' => buildDeletionWorkspacePayloadPhp($pdo, $delReqId)];
}

function deletionRejectPhp(PDO $pdo, int $delReqId, int $adminId, string $reason, string $remarks = ''): array
{
    ensureSellerDeletionSchemaPhp($pdo);
    $cleanReason = trim($reason);
    if ($cleanReason === '') {
        return ['error' => 'A rejection reason is required.', 'code' => 422];
    }

    $stmt = $pdo->prepare("SELECT * FROM seller_deletion_requests WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $delReqId]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$req) return ['error' => 'Deletion request not found.', 'code' => 404];
    if ($req['status'] === 'DELETED') {
        return ['error' => 'Seller has already been permanently deleted.', 'code' => 409];
    }

    $sellerId = (int) $req['seller_id'];
    $pdo->beginTransaction();
    try {
        $pdo->prepare("
            UPDATE seller_deletion_requests
            SET stage = 'DELETION_REJECTED',
                status = 'DELETION_REJECTED',
                rejection_reason = :rr,
                admin_remarks = COALESCE(:rem, admin_remarks),
                confirmation_token = NULL,
                confirmation_expires_at = NULL,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ")->execute([':rr' => $cleanReason, ':rem' => $remarks !== '' ? $remarks : null, ':id' => $delReqId]);

        $pdo->prepare("
            UPDATE users
            SET account_status = 'ACTIVE',
                deletion_requested_at = NULL,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :sid AND account_status != 'DELETED'
        ")->execute([':sid' => $sellerId]);

        logSellerDeletionAuditPhp($pdo, $delReqId, $sellerId, 'DELETION_REJECTED', $req['stage'], 'DELETION_REJECTED', $req['status'], 'DELETION_REJECTED', $adminId, 'admin', $cleanReason);

        $uStmt = $pdo->prepare("SELECT phone FROM users WHERE id = :sid LIMIT 1");
        $uStmt->execute([':sid' => $sellerId]);
        $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
        sendDeletionNotificationPhp(
            $pdo,
            $sellerId,
            $uRow['phone'] ?? null,
            'Account Deletion Rejected',
            "Your seller deletion request (#{$delReqId}) was rejected by Admin: {$cleanReason}. Your account has been restored to ACTIVE status.",
            'seller_deletion_rejected',
            ['deletion_request_id' => $delReqId, 'reason' => $cleanReason]
        );

        $pdo->commit();
        return ['statusCode' => 200, 'data' => buildDeletionWorkspacePayloadPhp($pdo, $delReqId)];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['error' => 'Failed to reject deletion request: ' . $e->getMessage(), 'code' => 500];
    }
}

function deletionApprovePhp(PDO $pdo, int $delReqId, int $adminId, string $remarks = ''): array
{
    ensureSellerDeletionSchemaPhp($pdo);
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    $lockSuffix = ($driver === 'mysql') ? ' FOR UPDATE' : '';

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT * FROM seller_deletion_requests WHERE id = :id LIMIT 1" . $lockSuffix);
        $stmt->execute([':id' => $delReqId]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$req) {
            $pdo->rollBack();
            return ['error' => 'Deletion request not found.', 'code' => 404];
        }
        if (in_array($req['status'], ['DELETED', 'DELETION_REJECTED'], true)) {
            $pdo->rollBack();
            return ['error' => "Cannot approve request in status {$req['status']}.", 'code' => 409];
        }

        $sellerId = (int) $req['seller_id'];
        $computed = computeSellerFinancialAndChecklistPhp($pdo, $sellerId, $delReqId);
        if (empty($computed['all_checks_passed'])) {
            $failing = array_values(array_map(fn($c) => $c['check_key'] . ' (' . $c['status'] . ')', array_filter($computed['checks'], fn($c) => $c['status'] !== 'SUCCESS')));
            $pdo->rollBack();
            return [
                'error' => 'Cannot approve seller for deletion while checklist requirements remain incomplete: ' . implode(', ', $failing),
                'code' => 409,
                'failing_checks' => $failing,
            ];
        }

        $confToken = bin2hex(random_bytes(24));
        $expiresAt = gmdate('Y-m-d H:i:s', time() + 3600);

        $pdo->prepare("
            UPDATE seller_deletion_requests
            SET stage = 'APPROVED_FOR_DELETION',
                status = 'APPROVED_FOR_DELETION',
                confirmation_token = :tok,
                confirmation_expires_at = :exp,
                approved_by = :aid,
                approved_at = CURRENT_TIMESTAMP,
                admin_remarks = COALESCE(:rem, admin_remarks),
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ")->execute([
            ':tok' => $confToken,
            ':exp' => $expiresAt,
            ':aid' => $adminId,
            ':rem' => $remarks !== '' ? $remarks : null,
            ':id' => $delReqId,
        ]);

        logSellerDeletionAuditPhp(
            $pdo,
            $delReqId,
            $sellerId,
            'APPROVED_FOR_DELETION',
            $req['stage'],
            'APPROVED_FOR_DELETION',
            $req['status'],
            'APPROVED_FOR_DELETION',
            $adminId,
            'admin',
            $remarks ?: 'First approval confirmation completed; single-use final deletion token issued'
        );

        sendDeletionNotificationPhp(
            $pdo,
            $sellerId,
            $computed['raw_seller']['phone'] ?? null,
            'Account Deletion Approved',
            "Seller account deletion request (#{$delReqId}) has been approved by Admin and is awaiting final deactivation.",
            'seller_deletion_approved',
            ['deletion_request_id' => $delReqId]
        );

        $pdo->commit();
        return ['statusCode' => 200, 'data' => buildDeletionWorkspacePayloadPhp($pdo, $delReqId)];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['error' => 'Failed to approve deletion: ' . $e->getMessage(), 'code' => 500];
    }
}

function deletionFinalDeletePhp(PDO $pdo, int $delReqId, int $adminId, array $input = []): array
{
    ensureSellerDeletionSchemaPhp($pdo);
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    $lockSuffix = ($driver === 'mysql') ? ' FOR UPDATE' : '';

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT * FROM seller_deletion_requests WHERE id = :id LIMIT 1" . $lockSuffix);
        $stmt->execute([':id' => $delReqId]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$req) {
            $pdo->rollBack();
            return ['error' => 'Deletion request not found.', 'code' => 404];
        }
        if ($req['status'] === 'DELETED') {
            $pdo->rollBack();
            return ['error' => 'Seller account has already been permanently deleted.', 'code' => 409];
        }
        if ($req['stage'] !== 'APPROVED_FOR_DELETION' || $req['status'] !== 'APPROVED_FOR_DELETION') {
            $pdo->rollBack();
            return ['error' => 'First Admin Approval is required before Final Delete.', 'code' => 409];
        }

        $sellerId = (int) $req['seller_id'];
        $expectedPhrase = "DELETE SELLER #{$sellerId}";
        $providedToken = trim((string) ($input['confirmation_token'] ?? ''));
        $providedPhrase = trim((string) ($input['confirmation_phrase'] ?? ''));

        if ($providedToken === '' || empty($req['confirmation_token']) || !hash_equals((string) $req['confirmation_token'], $providedToken)) {
            $pdo->rollBack();
            return ['error' => 'Invalid or missing second confirmation token.', 'code' => 422];
        }
        $confExpiresAtTs = parseUtcTimestampPhp($req['confirmation_expires_at'] ?? null);
        if ($confExpiresAtTs !== null && $confExpiresAtTs < time()) {
            $pdo->rollBack();
            return ['error' => 'Confirmation token has expired. Please re-approve to generate a fresh confirmation token.', 'code' => 422];
        }
        if ($providedPhrase !== $expectedPhrase) {
            $pdo->rollBack();
            return ['error' => "Confirmation phrase mismatch. Please enter exact phrase: {$expectedPhrase}", 'code' => 422];
        }

        // Re-verify all 6 checks inside transaction lock before deactivation
        $computed = computeSellerFinancialAndChecklistPhp($pdo, $sellerId, $delReqId);
        if (empty($computed['all_checks_passed'])) {
            $failing = array_values(array_map(fn($c) => $c['check_key'] . ' (' . $c['status'] . ')', array_filter($computed['checks'], fn($c) => $c['status'] !== 'SUCCESS')));
            $pdo->rollBack();
            return ['error' => 'Live verification blocked Final Delete due to incomplete checks: ' . implode(', ', $failing), 'code' => 409];
        }

        // 1. Soft-delete seller user & revoke JWT tokens
        $pdo->prepare("
            UPDATE users
            SET account_status = 'DELETED',
                deleted_at = CURRENT_TIMESTAMP,
                deleted_by = :aid,
                token_version = COALESCE(token_version, 1) + 1,
                fcm_token = NULL,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :sid
        ")->execute([':aid' => $adminId, ':sid' => $sellerId]);

        // 2. Revoke Sanctum tokens & device push tokens (preserve history tables!)
        try {
            $pdo->prepare("DELETE FROM personal_access_tokens WHERE tokenable_id = :sid")->execute([':sid' => $sellerId]);
        } catch (Throwable $e) {
        }
        try {
            $pdo->prepare("DELETE FROM device_tokens WHERE user_id = :sid")->execute([':sid' => $sellerId]);
        } catch (Throwable $e) {
        }

        // 3. Deactivate seller products from public listings WITHOUT deleting product rows needed for historical order joins
        $pdo->prepare("UPDATE products SET is_active = 0, stock = 0, updated_at = CURRENT_TIMESTAMP WHERE seller_id = :sid")
            ->execute([':sid' => $sellerId]);

        // 4. Mark deletion request DELETED & clear single-use token
        $remarks = trim((string) ($input['remarks'] ?? $input['admin_remarks'] ?? ''));
        $pdo->prepare("
            UPDATE seller_deletion_requests
            SET stage = 'DELETED',
                status = 'DELETED',
                deleted_by = :aid,
                deleted_at = CURRENT_TIMESTAMP,
                confirmation_token = NULL,
                confirmation_expires_at = NULL,
                admin_remarks = COALESCE(:rem, admin_remarks),
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ")->execute([':aid' => $adminId, ':rem' => $remarks !== '' ? $remarks : null, ':id' => $delReqId]);

        $pdo->prepare("UPDATE seller_deletion_checks SET status = 'SUCCESS', resolved_by = :aid, resolved_at = CURRENT_TIMESTAMP WHERE deletion_request_id = :id AND check_key = 'records_preserved'")
            ->execute([':aid' => $adminId, ':id' => $delReqId]);

        logSellerDeletionAuditPhp(
            $pdo,
            $delReqId,
            $sellerId,
            'FINAL_SELLER_DELETE',
            'APPROVED_FOR_DELETION',
            'DELETED',
            'APPROVED_FOR_DELETION',
            'DELETED',
            $adminId,
            'admin',
            $remarks ?: 'Seller soft-deleted, sessions revoked, products deactivated, financial & order history preserved',
            [
                'preserved_orders' => count($computed['orders_enriched']),
                'preserved_refunds' => count($computed['refunds']),
                'preserved_payouts' => count($computed['payouts']),
                'gst_preserved' => !empty($computed['gst']),
            ]
        );

        $pdo->commit();
        return ['statusCode' => 200, 'data' => buildDeletionWorkspacePayloadPhp($pdo, $delReqId)];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['error' => 'Final deletion failed: ' . $e->getMessage(), 'code' => 500];
    }
}

function recordOrderPaymentCapturePhp(PDO $pdo, mixed $orderIdOrNum, array $input, ?array $actorUser = null): array
{
    ensureSellerDeletionSchemaPhp($pdo);
    if (is_numeric($orderIdOrNum)) {
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = :id OR order_number = :num LIMIT 1");
        $stmt->execute([':id' => (int) $orderIdOrNum, ':num' => (string) $orderIdOrNum]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE order_number = :num LIMIT 1");
        $stmt->execute([':num' => (string) $orderIdOrNum]);
    }
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        return ['error' => 'Order not found.', 'code' => 404];
    }

    $cfg = getSellerDeletionConfigPhp();
    $gatewayPaymentId = trim((string) ($input['gateway_payment_id'] ?? $input['razorpay_payment_id'] ?? ''));
    $gatewayOrderId = trim((string) ($input['gateway_order_id'] ?? $input['razorpay_order_id'] ?? ($order['gateway_order_id'] ?? '')));
    $razorpaySig = trim((string) ($input['razorpay_signature'] ?? ''));
    $isAdmin = $actorUser && (($actorUser['role'] ?? '') === 'admin');

    if (!$isAdmin) {
        if ($cfg['razorpay_key_secret'] === '') {
            return ['error' => 'Admin authentication or Razorpay signature configuration is required to verify payment capture.', 'code' => 401];
        }
        if ($razorpaySig === '' || $gatewayOrderId === '' || $gatewayPaymentId === '') {
            return ['error' => 'Razorpay payment verification signature, gateway_order_id, and gateway_payment_id are required.', 'code' => 403];
        }
        $expectedSig = hash_hmac('sha256', "{$gatewayOrderId}|{$gatewayPaymentId}", $cfg['razorpay_key_secret']);
        if (!hash_equals($expectedSig, $razorpaySig)) {
            return ['error' => 'Invalid payment signature.', 'code' => 403];
        }
    }

    if ($gatewayPaymentId === '') {
        return ['error' => 'gateway_payment_id is required.', 'code' => 422];
    }

    $capturedAmount = isset($input['captured_amount']) && is_numeric($input['captured_amount'])
        ? round((float) $input['captured_amount'], 2)
        : round((float) $order['total_amount'], 2);
    $gateway = trim((string) ($input['payment_gateway'] ?? $input['gateway'] ?? 'razorpay')) ?: 'razorpay';
    $isSettled = !empty($input['settled']) || strtolower((string) ($input['status'] ?? '')) === 'settled';
    $txStatus = $isSettled ? 'settled' : 'captured';
    $oid = (int) $order['id'];

    $pdo->beginTransaction();
    try {
        $pdo->prepare("
            UPDATE orders
            SET payment_method = CASE WHEN payment_method = 'cod' THEN :gw1 ELSE payment_method END,
                payment_gateway = :gw2,
                gateway_order_id = COALESCE(:goid, gateway_order_id),
                gateway_payment_id = :gpid,
                payment_status = 'paid',
                captured_amount = :cap,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :oid
        ")->execute([
            ':gw1' => $gateway,
            ':gw2' => $gateway,
            ':goid' => $gatewayOrderId !== '' ? $gatewayOrderId : null,
            ':gpid' => $gatewayPaymentId,
            ':cap' => $capturedAmount,
            ':oid' => $oid,
        ]);

        $chkTx = $pdo->prepare("SELECT id FROM payment_transactions WHERE order_id = :oid AND gateway_payment_id = :gpid AND transaction_type = 'capture' LIMIT 1");
        $chkTx->execute([':oid' => $oid, ':gpid' => $gatewayPaymentId]);
        $exTx = $chkTx->fetch(PDO::FETCH_ASSOC);

        if ($exTx) {
            $txId = (int) $exTx['id'];
            if ($isSettled) {
                $pdo->prepare("UPDATE payment_transactions SET status = 'settled', settled_at = COALESCE(settled_at, CURRENT_TIMESTAMP), updated_at = CURRENT_TIMESTAMP WHERE id = :id")
                    ->execute([':id' => $txId]);
            }
        } else {
            $insTx = $pdo->prepare("
                INSERT INTO payment_transactions
                    (order_id, seller_id, gateway, gateway_order_id, gateway_payment_id, transaction_type, amount, currency, status, gateway_payload, settled_at, created_at, updated_at)
                VALUES
                    (:oid, :sid, :gw, :goid, :gpid, 'capture', :amt, 'INR', :st, :payload, :sat, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ");
            $insTx->execute([
                ':oid' => $oid,
                ':sid' => $order['seller_id'] ? (int) $order['seller_id'] : null,
                ':gw' => $gateway,
                ':goid' => $gatewayOrderId !== '' ? $gatewayOrderId : null,
                ':gpid' => $gatewayPaymentId,
                ':amt' => $capturedAmount,
                ':st' => $txStatus,
                ':payload' => json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ':sat' => $isSettled ? getDbCurrentTimestampPhp($pdo) : null,
            ]);
            $txId = (int) $pdo->lastInsertId();
        }

        $pdo->commit();
        return [
            'statusCode' => 200,
            'data' => [
                'success' => true,
                'message' => 'Payment capture verified and recorded.',
                'order_id' => $oid,
                'payment_transaction_id' => $txId,
                'gateway_payment_id' => $gatewayPaymentId,
                'captured_amount' => $capturedAmount,
                'status' => $txStatus,
            ],
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['error' => 'Failed to record payment capture: ' . $e->getMessage(), 'code' => 500];
    }
}
