<?php

/**
 * Pure PHP Razorpay Payment Gateway Integration Helper
 *
 * Implements:
 * - Environment credential reading & validation
 * - Secure HMAC-SHA256 signature verification (client and webhooks)
 * - Razorpay order creation via REST API
 * - Server-side payment capture verification with idempotency
 * - Idempotent webhook handling with event deduplication
 * - Full & partial refunds with authorization
 * - Seller commission calculation (e.g. 5% default)
 * - Safe seller payout separation (pending until Razorpay Route is enabled)
 * - Transaction logging and MySQL/SQLite schema enforcement
 */

require_once __DIR__ . '/response.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/seller_deletion.php';
require_once __DIR__ . '/notification.php';

function getRazorpayConfigPhp(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $envFile = __DIR__ . '/../.env';
    $env = [];
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            if (str_contains($line, '=')) {
                [$k, $v] = explode('=', $line, 2);
                $env[trim($k)] = trim(trim($v), '"\' ');
            }
        }
    }

    $getVal = function (string $key, string $default = '') use ($env): string {
        $val = getenv($key);
        if ($val !== false && trim((string) $val) !== '') {
            return trim((string) $val);
        }
        if (isset($_ENV[$key]) && trim((string) $_ENV[$key]) !== '') {
            return trim((string) $_ENV[$key]);
        }
        if (isset($_SERVER[$key]) && trim((string) $_SERVER[$key]) !== '') {
            return trim((string) $_SERVER[$key]);
        }
        if (isset($env[$key]) && trim((string) $env[$key]) !== '') {
            return trim((string) $env[$key]);
        }
        return $default;
    };

    $keyId = $getVal('RAZORPAY_KEY_ID', '');
    $keySecret = $getVal('RAZORPAY_KEY_SECRET', '');
    $webhookSecret = $getVal('RAZORPAY_WEBHOOK_SECRET', '');
    $apiBase = rtrim($getVal('RAZORPAY_API_BASE_URL', 'https://api.razorpay.com/v1'), '/');
    $commissionRate = max(0.0, (float) $getVal('MARKETPLACE_COMMISSION_RATE', '0.05'));
    $routeRaw = strtolower($getVal('RAZORPAY_ROUTE_ENABLED', 'false'));
    $routeEnabled = in_array($routeRaw, ['1', 'true', 'yes', 'on'], true);

    $config = [
        'key_id' => $keyId,
        'key_secret' => $keySecret,
        'webhook_secret' => $webhookSecret,
        'api_base_url' => $apiBase,
        'commission_rate' => $commissionRate,
        'route_enabled' => $routeEnabled,
        'is_configured' => ($keyId !== '' && $keySecret !== ''),
        'is_test_mode' => str_starts_with($keyId, 'rzp_test_'),
    ];

    return $config;
}

function logPaymentEventPhp(string $type, array $data): void
{
    try {
        $logDir = __DIR__ . '/../storage/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        $logFile = $logDir . '/razorpay.log';

        // Redact any secrets before writing
        $cleanData = $data;
        foreach (['key_secret', 'secret', 'webhook_secret', 'password'] as $secKey) {
            if (isset($cleanData[$secKey])) {
                $cleanData[$secKey] = '***REDACTED***';
            }
        }

        $line = sprintf(
            "[%s] [%s] %s\n",
            date('Y-m-d H:i:s'),
            strtoupper($type),
            json_encode($cleanData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    } catch (Throwable $e) {}
}

function ensurePaymentSchemaPhp(PDO $pdo): void
{
    static $ensured = [];
    $oid = spl_object_id($pdo);
    if (!empty($ensured[$oid])) {
        return;
    }
    $ensured[$oid] = true;

    ensureSellerDeletionSchemaPhp($pdo);

    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    $isSqlite = ($driver === 'sqlite');

    $addCol = function (string $table, string $col, string $mysqlDef, string $sqliteDef) use ($pdo, $isSqlite): void {
        if (dbHasColumnPhp($pdo, $table, $col)) {
            return;
        }
        try {
            $def = $isSqlite ? $sqliteDef : $mysqlDef;
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN {$def}");
        } catch (Throwable $e) {}
    };

    // Orders payment columns
    $addCol('orders', 'payment_gateway', "`payment_gateway` VARCHAR(40) NULL DEFAULT NULL", "`payment_gateway` TEXT NULL DEFAULT NULL");
    $addCol('orders', 'gateway_order_id', "`gateway_order_id` VARCHAR(120) NULL DEFAULT NULL", "`gateway_order_id` TEXT NULL DEFAULT NULL");
    $addCol('orders', 'gateway_payment_id', "`gateway_payment_id` VARCHAR(120) NULL DEFAULT NULL", "`gateway_payment_id` TEXT NULL DEFAULT NULL");
    $addCol('orders', 'captured_amount', "`captured_amount` DECIMAL(10,2) NULL DEFAULT NULL", "`captured_amount` REAL NULL DEFAULT NULL");
    $addCol('orders', 'refunded_amount', "`refunded_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00", "`refunded_amount` REAL NOT NULL DEFAULT 0.00");
    $addCol('orders', 'updated_at', "`updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP", "`updated_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP");

    // Payment transactions table
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
            CREATE TABLE IF NOT EXISTS seller_payouts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                seller_id INTEGER NOT NULL,
                amount REAL NOT NULL DEFAULT 0.00,
                commission_deducted REAL NOT NULL DEFAULT 0.00,
                net_amount REAL NOT NULL DEFAULT 0.00,
                refund_recovery_deducted REAL NOT NULL DEFAULT 0.00,
                status TEXT NOT NULL DEFAULT 'PENDING',
                notes TEXT NULL,
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

            CREATE TABLE IF NOT EXISTS `seller_payouts` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `seller_id` BIGINT UNSIGNED NOT NULL,
                `amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                `commission_deducted` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                `net_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                `refund_recovery_deducted` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                `status` VARCHAR(50) NOT NULL DEFAULT 'PENDING',
                `notes` TEXT NULL DEFAULT NULL,
                `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_sp_seller` (`seller_id`),
                INDEX `idx_sp_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    $addCol('payment_transactions', 'commission_rate', "`commission_rate` DECIMAL(5,4) NOT NULL DEFAULT 0.0500 AFTER `status`", "`commission_rate` REAL NOT NULL DEFAULT 0.0500");
    $addCol('payment_transactions', 'commission_amount', "`commission_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `commission_rate`", "`commission_amount` REAL NOT NULL DEFAULT 0.00");
    $addCol('payment_transactions', 'net_seller_amount', "`net_seller_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `commission_amount`", "`net_seller_amount` REAL NOT NULL DEFAULT 0.00");
    $addCol('payment_transactions', 'buyer_phone', "`buyer_phone` VARCHAR(30) NULL DEFAULT NULL AFTER `net_seller_amount`", "`buyer_phone` TEXT NULL DEFAULT NULL");
    $addCol('payment_transactions', 'buyer_name', "`buyer_name` VARCHAR(150) NULL DEFAULT NULL AFTER `buyer_phone`", "`buyer_name` TEXT NULL DEFAULT NULL");
    $addCol('payment_transactions', 'refund_id', "`refund_id` VARCHAR(120) NULL DEFAULT NULL AFTER `buyer_name`", "`refund_id` TEXT NULL DEFAULT NULL");
    $addCol('payment_transactions', 'idempotency_key', "`idempotency_key` VARCHAR(120) NULL DEFAULT NULL AFTER `refund_id`", "`idempotency_key` TEXT NULL DEFAULT NULL");
    $addCol('payment_transactions', 'error_code', "`error_code` VARCHAR(100) NULL DEFAULT NULL AFTER `idempotency_key`", "`error_code` TEXT NULL DEFAULT NULL");
    $addCol('payment_transactions', 'error_description', "`error_description` TEXT NULL DEFAULT NULL AFTER `error_code`", "`error_description` TEXT NULL DEFAULT NULL");
}

function callRazorpayGatewayPhp(string $method, string $endpoint, ?array $payload = null, array $extraHeaders = []): array
{
    $cfg = getRazorpayConfigPhp();
    $keyId = $cfg['key_id'];
    $keySecret = $cfg['key_secret'];

    // Support unit test mock transport hook
    if (isset($GLOBALS['__RAZORPAY_TEST_HTTP_TRANSPORT']) && is_callable($GLOBALS['__RAZORPAY_TEST_HTTP_TRANSPORT'])) {
        return ($GLOBALS['__RAZORPAY_TEST_HTTP_TRANSPORT'])($method, $endpoint, $payload, $extraHeaders);
    }

    if ($keyId === '' || $keySecret === '') {
        return [
            'ok' => false,
            'statusCode' => 401,
            'data' => null,
            'error' => 'Razorpay credentials (RAZORPAY_KEY_ID / RAZORPAY_KEY_SECRET) are not configured in environment.'
        ];
    }

    $url = $cfg['api_base_url'] . '/' . ltrim($endpoint, '/');
    return callRazorpayHttpPhp($method, $url, $keyId, $keySecret, $payload, $extraHeaders);
}

function verifyPaymentSignaturePhp(string $razorpayOrderId, string $razorpayPaymentId, string $razorpaySignature, ?string $keySecret = null): bool
{
    if ($keySecret === null || $keySecret === '') {
        $cfg = getRazorpayConfigPhp();
        $keySecret = $cfg['key_secret'];
    }
    if ($keySecret === '' || $razorpayOrderId === '' || $razorpayPaymentId === '' || $razorpaySignature === '') {
        return false;
    }
    $expected = hash_hmac('sha256', "{$razorpayOrderId}|{$razorpayPaymentId}", $keySecret);
    return hash_equals($expected, $razorpaySignature);
}

function verifyWebhookSignaturePhp(string $rawPayload, string $headerSignature, ?string $webhookSecret = null): bool
{
    if ($webhookSecret === null || $webhookSecret === '') {
        $cfg = getRazorpayConfigPhp();
        $webhookSecret = $cfg['webhook_secret'];
    }
    if ($webhookSecret === '' || $rawPayload === '' || $headerSignature === '') {
        return false;
    }
    $expected = hash_hmac('sha256', $rawPayload, $webhookSecret);
    return hash_equals($expected, $headerSignature);
}

function razorpayCreateOrderPhp(PDO $pdo, mixed $orderIdOrData): array
{
    ensurePaymentSchemaPhp($pdo);
    $cfg = getRazorpayConfigPhp();

    $order = null;
    if (is_array($orderIdOrData)) {
        if (!empty($orderIdOrData['id'])) {
            $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => (int) $orderIdOrData['id']]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
        } elseif (!empty($orderIdOrData['order_number'])) {
            $stmt = $pdo->prepare("SELECT * FROM orders WHERE order_number = :num LIMIT 1");
            $stmt->execute([':num' => (string) $orderIdOrData['order_number']]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $order = $orderIdOrData;
        }
    } elseif (is_numeric($orderIdOrData)) {
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => (int) $orderIdOrData]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE order_number = :num LIMIT 1");
        $stmt->execute([':num' => (string) $orderIdOrData]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$order || empty($order['id'])) {
        return ['ok' => false, 'statusCode' => 404, 'error' => 'Order not found for Razorpay order creation.'];
    }

    $totalAmount = (float) ($order['total_amount'] ?? 0.0);
    if ($totalAmount <= 0.0) {
        return ['ok' => false, 'statusCode' => 422, 'error' => 'Cannot create payment order with zero or negative total amount.'];
    }

    // Check if already paid
    if (strtolower((string) ($order['payment_status'] ?? '')) === 'paid') {
        return ['ok' => false, 'statusCode' => 409, 'error' => 'Order #' . $order['order_number'] . ' is already paid.'];
    }

    $amountPaise = (int) round($totalAmount * 100);
    $orderNumber = (string) $order['order_number'];
    $orderId = (int) $order['id'];

    $rzpOrderId = null;
    $rzpOrderPayload = [
        'amount' => $amountPaise,
        'currency' => 'INR',
        'receipt' => $orderNumber,
        'notes' => [
            'order_id' => (string) $orderId,
            'order_number' => $orderNumber,
            'buyer_name' => (string) ($order['buyer_name'] ?? ''),
            'buyer_phone' => (string) ($order['buyer_phone'] ?? ''),
        ]
    ];

    if ($cfg['is_configured']) {
        $apiRes = callRazorpayGatewayPhp('POST', 'orders', $rzpOrderPayload);
        if ($apiRes['ok'] && !empty($apiRes['data']['id'])) {
            $rzpOrderId = (string) $apiRes['data']['id'];
        } else {
            $errDesc = ($apiRes['error'] ?? null) ?: ($apiRes['data']['error']['description'] ?? 'Razorpay API rejected order creation.');
            logPaymentEventPhp('order_creation_failed', [
                'order_id' => $orderId,
                'error' => $errDesc,
                'gateway_response' => $apiRes['data']
            ]);
            return [
                'ok' => false,
                'statusCode' => $apiRes['statusCode'] ?: 502,
                'error' => 'Razorpay Order Creation Failed: ' . $errDesc,
                'raw_error' => $apiRes
            ];
        }
    } else {
        // Fallback for test harness / development when credentials not yet set
        $rzpOrderId = 'order_test_' . substr(md5($orderId . '_' . time()), 0, 14);
    }

    // Persist gateway_order_id in orders
    $pdo->prepare("
        UPDATE orders
        SET gateway_order_id = :goid,
            payment_gateway = 'razorpay',
            payment_status = CASE WHEN payment_status = 'unpaid' THEN 'pending' ELSE payment_status END,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
    ")->execute([
        ':goid' => $rzpOrderId,
        ':id' => $orderId
    ]);

    // Record pending transaction
    $chkTx = $pdo->prepare("SELECT id FROM payment_transactions WHERE order_id = :oid AND gateway_order_id = :goid LIMIT 1");
    $chkTx->execute([':oid' => $orderId, ':goid' => $rzpOrderId]);
    if (!$chkTx->fetch()) {
        $insTx = $pdo->prepare("
            INSERT INTO payment_transactions
                (order_id, seller_id, gateway, gateway_order_id, gateway_payment_id, transaction_type, amount, currency, status, buyer_phone, buyer_name, gateway_payload, created_at, updated_at)
            VALUES
                (:oid, :sid, 'razorpay', :goid, '', 'create_order', :amt, 'INR', 'pending', :phone, :bname, :payload, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $insTx->execute([
            ':oid' => $orderId,
            ':sid' => !empty($order['seller_id']) ? (int) $order['seller_id'] : null,
            ':goid' => $rzpOrderId,
            ':amt' => $totalAmount,
            ':phone' => $order['buyer_phone'] ?? null,
            ':bname' => $order['buyer_name'] ?? null,
            ':payload' => json_encode($rzpOrderPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        ]);
    }

    logPaymentEventPhp('order_created', [
        'order_id' => $orderId,
        'order_number' => $orderNumber,
        'razorpay_order_id' => $rzpOrderId,
        'amount_paise' => $amountPaise
    ]);

    return [
        'ok' => true,
        'statusCode' => 200,
        'data' => [
            'success' => true,
            'message' => 'Razorpay order created successfully.',
            'order_id' => $orderId,
            'order_number' => $orderNumber,
            'razorpay_order_id' => $rzpOrderId,
            'razorpay_key_id' => $cfg['key_id'],
            'amount_in_paise' => $amountPaise,
            'amount' => $totalAmount,
            'amount_formatted' => '₹' . number_format($totalAmount, 2),
            'currency' => 'INR',
            'buyer_name' => $order['buyer_name'] ?? '',
            'buyer_phone' => $order['buyer_phone'] ?? '',
            'notes' => $rzpOrderPayload['notes'],
        ]
    ];
}

function calculateOrderSellerCommissionsPhp(PDO $pdo, int $orderId, float $totalAmount): array
{
    $cfg = getRazorpayConfigPhp();
    $rate = $cfg['commission_rate'];

    $stmt = $pdo->prepare("SELECT id, seller_id, price, quantity, subtotal FROM order_items WHERE order_id = :oid");
    $stmt->execute([':oid' => $orderId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $sellerTotals = [];
    foreach ($items as $item) {
        $sid = !empty($item['seller_id']) ? (int) $item['seller_id'] : 1;
        $sub = (float) ($item['subtotal'] ?? ($item['price'] * $item['quantity']));
        if (!isset($sellerTotals[$sid])) {
            $sellerTotals[$sid] = 0.0;
        }
        $sellerTotals[$sid] += $sub;
    }

    $breakdowns = [];
    $totalCommission = 0.0;
    $totalSellerNet = 0.0;

    foreach ($sellerTotals as $sid => $gross) {
        $comm = round($gross * $rate, 2);
        $net = round($gross - $comm, 2);
        $totalCommission += $comm;
        $totalSellerNet += $net;

        $breakdowns[] = [
            'seller_id' => $sid,
            'gross_amount' => $gross,
            'commission_rate' => $rate,
            'commission_amount' => $comm,
            'net_seller_amount' => $net,
        ];

        // Maintain seller payout record safely
        // If Razorpay Route is disabled (default), keep seller payouts separate in PENDING status
        try {
            $chkP = $pdo->prepare("SELECT id FROM seller_payouts WHERE seller_id = :sid AND notes LIKE :nmatch AND status = 'PENDING' LIMIT 1");
            $chkP->execute([':sid' => $sid, ':nmatch' => "%Order #{$orderId}%"]);
            if (!$chkP->fetch()) {
                $insP = $pdo->prepare("
                    INSERT INTO seller_payouts (seller_id, amount, commission_deducted, net_amount, status, notes, created_at, updated_at)
                    VALUES (:sid, :amt, :comm, :net, 'PENDING', :notes, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                ");
                $insP->execute([
                    ':sid' => $sid,
                    ':amt' => $gross,
                    ':comm' => $comm,
                    ':net' => $net,
                    ':notes' => "Payout pending for Order #{$orderId}. Razorpay Route: " . ($cfg['route_enabled'] ? 'Enabled' : 'Disabled (Direct Marketplace Hold)'),
                ]);
            }
        } catch (Throwable $e) {}
    }

    return [
        'commission_rate' => $rate,
        'total_commission' => round($totalCommission, 2),
        'total_seller_net' => round($totalSellerNet, 2),
        'seller_breakdowns' => $breakdowns,
        'route_enabled' => $cfg['route_enabled'],
        'route_status' => $cfg['route_enabled'] ? 'ACTIVE' : 'SEPARATED_PENDING_RELEASE'
    ];
}

function recordOrderPaymentVerificationPhp(PDO $pdo, mixed $orderIdOrNum, array $input, ?array $actorUser = null): array
{
    ensurePaymentSchemaPhp($pdo);
    $cfg = getRazorpayConfigPhp();

    if (is_numeric($orderIdOrNum)) {
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = :id OR order_number = :num LIMIT 1");
        $stmt->execute([':id' => (int) $orderIdOrNum, ':num' => (string) $orderIdOrNum]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE order_number = :num LIMIT 1");
        $stmt->execute([':num' => (string) $orderIdOrNum]);
    }
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        return ['ok' => false, 'statusCode' => 404, 'error' => 'Order not found.'];
    }

    $orderId = (int) $order['id'];
    $orderNumber = (string) $order['order_number'];
    $gatewayPaymentId = trim((string) ($input['gateway_payment_id'] ?? $input['razorpay_payment_id'] ?? ''));
    $gatewayOrderId = trim((string) ($input['gateway_order_id'] ?? $input['razorpay_order_id'] ?? ($order['gateway_order_id'] ?? '')));
    $razorpaySig = trim((string) ($input['razorpay_signature'] ?? ''));
    $isAdmin = $actorUser && (($actorUser['role'] ?? '') === 'admin');

    if ($gatewayPaymentId === '') {
        return ['ok' => false, 'statusCode' => 422, 'error' => 'gateway_payment_id (or razorpay_payment_id) is required.'];
    }

    // Server-side signature verification:
    // If not an admin manual capture, signature is strictly required
    if (!$isAdmin) {
        if ($cfg['key_secret'] === '') {
            return ['ok' => false, 'statusCode' => 401, 'error' => 'Razorpay secret key configuration is missing on server.'];
        }
        if ($gatewayOrderId === '' || $razorpaySig === '') {
            return ['ok' => false, 'statusCode' => 400, 'error' => 'Razorpay payment verification requires razorpay_order_id and razorpay_signature.'];
        }
        $validSig = verifyPaymentSignaturePhp($gatewayOrderId, $gatewayPaymentId, $razorpaySig, $cfg['key_secret']);
        if (!$validSig) {
            logPaymentEventPhp('security_signature_mismatch', [
                'order_id' => $orderId,
                'gateway_payment_id' => $gatewayPaymentId,
                'gateway_order_id' => $gatewayOrderId
            ]);
            // Record failed transaction attempt
            try {
                $insFailed = $pdo->prepare("
                    INSERT INTO payment_transactions
                        (order_id, seller_id, gateway, gateway_order_id, gateway_payment_id, transaction_type, amount, status, error_code, error_description, gateway_payload, created_at, updated_at)
                    VALUES
                        (:oid, :sid, 'razorpay', :goid, :gpid, 'capture_failed', :amt, 'failed', 'BAD_SIGNATURE', 'Razorpay payment signature mismatch.', :payload, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                ");
                $insFailed->execute([
                    ':oid' => $orderId,
                    ':sid' => $order['seller_id'] ? (int) $order['seller_id'] : null,
                    ':goid' => $gatewayOrderId,
                    ':gpid' => $gatewayPaymentId,
                    ':amt' => (float) $order['total_amount'],
                    ':payload' => json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                ]);
            } catch (Throwable $e) {}

            return ['ok' => false, 'statusCode' => 400, 'error' => 'Invalid Razorpay payment signature. Payment verification rejected.'];
        }
    }

    $capturedAmount = isset($input['captured_amount']) && is_numeric($input['captured_amount'])
        ? round((float) $input['captured_amount'], 2)
        : round((float) $order['total_amount'], 2);

    $pdo->beginTransaction();
    try {
        // Idempotency: check if order is already marked paid
        $currentPayStatus = strtolower((string) ($order['payment_status'] ?? ''));
        if ($currentPayStatus === 'paid') {
            if ($order['gateway_payment_id'] === $gatewayPaymentId) {
                // Idempotent success response
                $pdo->rollBack();
                return [
                    'ok' => true,
                    'statusCode' => 200,
                    'data' => [
                        'success' => true,
                        'already_verified' => true,
                        'message' => 'Payment has already been verified and recorded.',
                        'order_id' => $orderId,
                        'order_number' => $orderNumber,
                        'gateway_payment_id' => $gatewayPaymentId,
                        'payment_status' => 'paid',
                        'captured_amount' => (float) ($order['captured_amount'] ?? $capturedAmount),
                    ]
                ];
            } else {
                $pdo->rollBack();
                return [
                    'ok' => false,
                    'statusCode' => 409,
                    'error' => "Order #{$orderNumber} is already marked as paid under payment ID: " . $order['gateway_payment_id']
                ];
            }
        }

        // Prevent duplicate payment token usage across different orders
        $chkDup = $pdo->prepare("SELECT order_id FROM payment_transactions WHERE gateway_payment_id = :gpid AND order_id != :oid AND status = 'captured' LIMIT 1");
        $chkDup->execute([':gpid' => $gatewayPaymentId, ':oid' => $orderId]);
        $dupRow = $chkDup->fetch(PDO::FETCH_ASSOC);
        if ($dupRow) {
            $pdo->rollBack();
            return [
                'ok' => false,
                'statusCode' => 409,
                'error' => "Duplicate payment token: Payment ID {$gatewayPaymentId} is already captured for Order #{$dupRow['order_id']}."
            ];
        }

        // Calculate multi-vendor commissions & seller payout records
        $commissionData = calculateOrderSellerCommissionsPhp($pdo, $orderId, $capturedAmount);

        // Update Order record
        $pdo->prepare("
            UPDATE orders
            SET payment_method = 'razorpay',
                payment_gateway = 'razorpay',
                gateway_order_id = COALESCE(:goid, gateway_order_id),
                gateway_payment_id = :gpid,
                payment_status = 'paid',
                captured_amount = :cap,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :oid
        ")->execute([
            ':goid' => $gatewayOrderId !== '' ? $gatewayOrderId : null,
            ':gpid' => $gatewayPaymentId,
            ':cap' => $capturedAmount,
            ':oid' => $orderId,
        ]);

        // Insert or update captured payment_transaction
        $chkTx = $pdo->prepare("SELECT id FROM payment_transactions WHERE order_id = :oid AND gateway_payment_id = :gpid AND transaction_type = 'capture' LIMIT 1");
        $chkTx->execute([':oid' => $orderId, ':gpid' => $gatewayPaymentId]);
        $exTx = $chkTx->fetch(PDO::FETCH_ASSOC);

        $txId = 0;
        if ($exTx) {
            $txId = (int) $exTx['id'];
            $pdo->prepare("
                UPDATE payment_transactions
                SET status = 'captured',
                    amount = :amt,
                    commission_rate = :crate,
                    commission_amount = :camt,
                    net_seller_amount = :snet,
                    gateway_payload = :payload,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ")->execute([
                ':amt' => $capturedAmount,
                ':crate' => $commissionData['commission_rate'],
                ':camt' => $commissionData['total_commission'],
                ':snet' => $commissionData['total_seller_net'],
                ':payload' => json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ':id' => $txId
            ]);
        } else {
            $insTx = $pdo->prepare("
                INSERT INTO payment_transactions
                    (order_id, seller_id, gateway, gateway_order_id, gateway_payment_id, transaction_type, amount, currency, status, commission_rate, commission_amount, net_seller_amount, buyer_phone, buyer_name, gateway_payload, created_at, updated_at)
                VALUES
                    (:oid, :sid, 'razorpay', :goid, :gpid, 'capture', :amt, 'INR', 'captured', :crate, :camt, :snet, :phone, :bname, :payload, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ");
            $insTx->execute([
                ':oid' => $orderId,
                ':sid' => !empty($order['seller_id']) ? (int) $order['seller_id'] : null,
                ':goid' => $gatewayOrderId !== '' ? $gatewayOrderId : null,
                ':gpid' => $gatewayPaymentId,
                ':amt' => $capturedAmount,
                ':crate' => $commissionData['commission_rate'],
                ':camt' => $commissionData['total_commission'],
                ':snet' => $commissionData['total_seller_net'],
                ':phone' => $order['buyer_phone'] ?? null,
                ':bname' => $order['buyer_name'] ?? null,
                ':payload' => json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
            $txId = (int) $pdo->lastInsertId();
        }

        $pdo->commit();

        logPaymentEventPhp('payment_verified', [
            'order_id' => $orderId,
            'order_number' => $orderNumber,
            'gateway_payment_id' => $gatewayPaymentId,
            'captured_amount' => $capturedAmount,
            'commission' => $commissionData['total_commission'],
            'seller_net' => $commissionData['total_seller_net']
        ]);

        if (function_exists('dispatchOrderNotificationPhp')) {
            try {
                dispatchOrderNotificationPhp($pdo, $orderId, 'payment_success', [
                    'payment_id' => $gatewayPaymentId,
                    'amount' => $capturedAmount
                ]);
            } catch (Throwable $e) {}
        }

        return [
            'ok' => true,
            'statusCode' => 200,
            'data' => [
                'success' => true,
                'message' => 'Payment signature verified and payment captured successfully.',
                'order_id' => $orderId,
                'order_number' => $orderNumber,
                'payment_transaction_id' => $txId,
                'gateway_payment_id' => $gatewayPaymentId,
                'gateway_order_id' => $gatewayOrderId,
                'captured_amount' => $capturedAmount,
                'payment_status' => 'paid',
                'order_status' => $order['order_status'] ?? 'pending',
                'commissions' => $commissionData,
            ]
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        logPaymentEventPhp('payment_verification_error', ['error' => $e->getMessage()]);
        return ['ok' => false, 'statusCode' => 500, 'error' => 'Database error during payment capture: ' . $e->getMessage()];
    }
}

function processRazorpayWebhookPhp(PDO $pdo, string $rawPayload, string $headerSignature): array
{
    ensurePaymentSchemaPhp($pdo);
    $cfg = getRazorpayConfigPhp();

    if ($cfg['webhook_secret'] === '') {
        return ['ok' => false, 'statusCode' => 500, 'error' => 'Razorpay webhook secret (RAZORPAY_WEBHOOK_SECRET) is not configured in backend environment.'];
    }

    if (!verifyWebhookSignaturePhp($rawPayload, $headerSignature, $cfg['webhook_secret'])) {
        logPaymentEventPhp('webhook_invalid_signature', [
            'raw_length' => strlen($rawPayload),
            'header_sig' => substr($headerSignature, 0, 10) . '...'
        ]);
        return ['ok' => false, 'statusCode' => 400, 'error' => 'Invalid Razorpay webhook signature. Request rejected.'];
    }

    $event = json_decode($rawPayload, true);
    if (!is_array($event) || empty($event['event'])) {
        return ['ok' => false, 'statusCode' => 422, 'error' => 'Malformed webhook payload JSON.'];
    }

    $eventType = (string) $event['event'];
    $eventId = (string) ($event['id'] ?? ($event['event_id'] ?? md5($rawPayload)));

    // Idempotency: Check if webhook event was already processed
    $chk = $pdo->prepare("SELECT id, status FROM webhook_logs WHERE event_id = :eid LIMIT 1");
    $chk->execute([':eid' => $eventId]);
    $exEvent = $chk->fetch(PDO::FETCH_ASSOC);
    if ($exEvent) {
        return [
            'ok' => true,
            'statusCode' => 200,
            'data' => [
                'status' => 'ignored',
                'message' => 'Webhook event already processed (idempotent duplicate).',
                'event_id' => $eventId,
                'event' => $eventType
            ]
        ];
    }

    // Record webhook reception
    $pdo->prepare("
        INSERT INTO webhook_logs (event_id, event_type, gateway, payload, status, created_at)
        VALUES (:eid, :etype, 'razorpay', :payload, 'processing', CURRENT_TIMESTAMP)
    ")->execute([
        ':eid' => $eventId,
        ':etype' => $eventType,
        ':payload' => $rawPayload
    ]);
    $webhookLogId = (int) $pdo->lastInsertId();

    $payloadEntity = $event['payload'] ?? [];
    $paymentEntity = $payloadEntity['payment']['entity'] ?? null;
    $orderEntity = $payloadEntity['order']['entity'] ?? null;
    $refundEntity = $payloadEntity['refund']['entity'] ?? null;

    $matchedOrderId = null;
    $notes = $paymentEntity['notes'] ?? ($orderEntity['notes'] ?? []);
    if (!empty($notes['order_id'])) {
        $matchedOrderId = (int) $notes['order_id'];
    }

    $razorpayPaymentId = $paymentEntity['id'] ?? null;
    $razorpayOrderId = $paymentEntity['order_id'] ?? ($orderEntity['id'] ?? null);

    if (!$matchedOrderId && !empty($razorpayOrderId)) {
        $oStmt = $pdo->prepare("SELECT id FROM orders WHERE gateway_order_id = :goid LIMIT 1");
        $oStmt->execute([':goid' => $razorpayOrderId]);
        $matchedOrderId = $oStmt->fetchColumn() ?: null;
    }

    if (!$matchedOrderId && !empty($notes['order_number'])) {
        $oStmt = $pdo->prepare("SELECT id FROM orders WHERE order_number = :num LIMIT 1");
        $oStmt->execute([':num' => (string) $notes['order_number']]);
        $matchedOrderId = $oStmt->fetchColumn() ?: null;
    }

    $processError = null;

    try {
        switch ($eventType) {
            case 'payment.captured':
            case 'order.paid':
                if ($matchedOrderId && $razorpayPaymentId) {
                    $capAmount = isset($paymentEntity['amount']) ? round(((float) $paymentEntity['amount']) / 100, 2) : 0.0;
                    recordOrderPaymentVerificationPhp($pdo, $matchedOrderId, [
                        'gateway_payment_id' => $razorpayPaymentId,
                        'gateway_order_id' => $razorpayOrderId,
                        'captured_amount' => $capAmount,
                        'razorpay_signature' => 'webhook_verified',
                    ], ['role' => 'admin']);
                }
                break;

            case 'payment.failed':
                if ($matchedOrderId) {
                    $errCode = $paymentEntity['error_code'] ?? 'PAYMENT_FAILED';
                    $errDesc = $paymentEntity['error_description'] ?? 'Payment attempt failed.';
                    $pdo->prepare("
                        UPDATE orders
                        SET payment_status = CASE WHEN payment_status = 'unpaid' THEN 'failed' ELSE payment_status END,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE id = :oid
                    ")->execute([':oid' => $matchedOrderId]);

                    $pdo->prepare("
                        INSERT INTO payment_transactions
                            (order_id, gateway, gateway_order_id, gateway_payment_id, transaction_type, amount, status, error_code, error_description, gateway_payload, created_at, updated_at)
                        VALUES
                            (:oid, 'razorpay', :goid, :gpid, 'payment_failed', :amt, 'failed', :ecode, :edesc, :payload, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                    ")->execute([
                        ':oid' => $matchedOrderId,
                        ':goid' => $razorpayOrderId,
                        ':gpid' => $razorpayPaymentId ?: '',
                        ':amt' => isset($paymentEntity['amount']) ? round(((float) $paymentEntity['amount']) / 100, 2) : 0.0,
                        ':ecode' => $errCode,
                        ':edesc' => $errDesc,
                        ':payload' => $rawPayload
                    ]);

                    if (function_exists('dispatchOrderNotificationPhp')) {
                        try {
                            dispatchOrderNotificationPhp($pdo, (int)$matchedOrderId, 'payment_failed', [
                                'error_description' => $errDesc
                            ]);
                        } catch (Throwable $e) {}
                    }
                }
                break;

            case 'refund.processed':
            case 'refund.created':
                if ($refundEntity) {
                    $rfId = $refundEntity['id'] ?? null;
                    $rfAmt = isset($refundEntity['amount']) ? round(((float) $refundEntity['amount']) / 100, 2) : 0.0;
                    $rfPayId = $refundEntity['payment_id'] ?? null;
                    if ($rfPayId) {
                        $pOrder = $pdo->prepare("SELECT id, captured_amount, refunded_amount FROM orders WHERE gateway_payment_id = :gpid LIMIT 1");
                        $pOrder->execute([':gpid' => $rfPayId]);
                        $foundOrder = $pOrder->fetch(PDO::FETCH_ASSOC);
                        if ($foundOrder) {
                            $oid = (int) $foundOrder['id'];
                            $newRefunded = round(((float) $foundOrder['refunded_amount']) + $rfAmt, 2);
                            $capAmt = (float) $foundOrder['captured_amount'];
                            $newPayStatus = ($newRefunded >= $capAmt && $capAmt > 0) ? 'refunded' : 'partially_refunded';

                            $pdo->prepare("
                                UPDATE orders
                                SET refunded_amount = :ramt,
                                    payment_status = :pst,
                                    updated_at = CURRENT_TIMESTAMP
                                WHERE id = :oid
                            ")->execute([':ramt' => $newRefunded, ':pst' => $newPayStatus, ':oid' => $oid]);

                            $pdo->prepare("
                                UPDATE payment_transactions
                                SET status = :pst,
                                    updated_at = CURRENT_TIMESTAMP
                                WHERE order_id = :oid AND transaction_type = 'capture'
                            ")->execute([':pst' => $newPayStatus, ':oid' => $oid]);

                            // Record refund transaction
                            $pdo->prepare("
                                INSERT INTO payment_transactions
                                    (order_id, gateway, gateway_payment_id, refund_id, transaction_type, amount, status, gateway_payload, created_at, updated_at)
                                VALUES
                                    (:oid, 'razorpay', :gpid, :rfid, 'refund', :amt, 'refunded', :payload, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                            ")->execute([
                                ':oid' => $oid,
                                ':gpid' => $rfPayId,
                                ':rfid' => $rfId,
                                ':amt' => $rfAmt,
                                ':payload' => $rawPayload
                            ]);
                        }
                    }
                }
                break;
        }

        $pdo->prepare("
            UPDATE webhook_logs
            SET status = 'processed', order_id = :oid
            WHERE id = :id
        ")->execute([':oid' => $matchedOrderId, ':id' => $webhookLogId]);

    } catch (Throwable $e) {
        $processError = $e->getMessage();
        $pdo->prepare("
            UPDATE webhook_logs
            SET status = 'failed', error_message = :err
            WHERE id = :id
        ")->execute([':err' => $processError, ':id' => $webhookLogId]);
    }

    logPaymentEventPhp('webhook_processed', [
        'event_id' => $eventId,
        'event' => $eventType,
        'order_id' => $matchedOrderId,
        'error' => $processError
    ]);

    return [
        'ok' => true,
        'statusCode' => 200,
        'data' => [
            'status' => 'success',
            'message' => 'Webhook received and processed.',
            'event' => $eventType,
            'event_id' => $eventId,
            'matched_order_id' => $matchedOrderId,
            'error' => $processError
        ]
    ];
}

function processPaymentRefundPhp(PDO $pdo, mixed $orderParam, ?float $requestedAmount = null, string $reason = '', ?array $actorUser = null): array
{
    ensurePaymentSchemaPhp($pdo);
    $cfg = getRazorpayConfigPhp();

    if (is_numeric($orderParam)) {
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = :id OR order_number = :num LIMIT 1");
        $stmt->execute([':id' => (int) $orderParam, ':num' => (string) $orderParam]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE order_number = :num LIMIT 1");
        $stmt->execute([':num' => (string) $orderParam]);
    }
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        return ['ok' => false, 'statusCode' => 404, 'error' => 'Order not found for refund.'];
    }

    $orderId = (int) $order['id'];
    $orderNumber = (string) $order['order_number'];
    $payStatus = strtolower((string) ($order['payment_status'] ?? ''));

    if (!in_array($payStatus, ['paid', 'partially_refunded'], true)) {
        return ['ok' => false, 'statusCode' => 422, 'error' => "Cannot refund order with payment_status '{$payStatus}'. Only paid orders are eligible for refund."];
    }

    $gatewayPaymentId = trim((string) ($order['gateway_payment_id'] ?? ''));
    if ($gatewayPaymentId === '') {
        return ['ok' => false, 'statusCode' => 422, 'error' => 'Order does not have a recorded gateway_payment_id for gateway refund.'];
    }

    // Authorization check: Admin, System, or Seller owning items in this order
    $isPrivileged = $actorUser && in_array($actorUser['role'] ?? '', ['admin', 'system'], true);
    $authSellerId = $actorUser ? (int) ($actorUser['id'] ?? 0) : 0;

    if (!$isPrivileged) {
        if (!$actorUser || ($actorUser['role'] ?? '') !== 'seller') {
            return ['ok' => false, 'statusCode' => 401, 'error' => 'Authentication required to process refunds.'];
        }
        // Verify seller owns items in this order
        $sChk = $pdo->prepare("SELECT COUNT(*) FROM order_items WHERE order_id = :oid AND seller_id = :sid");
        $sChk->execute([':oid' => $orderId, ':sid' => $authSellerId]);
        if ((int) $sChk->fetchColumn() === 0) {
            return ['ok' => false, 'statusCode' => 403, 'error' => 'Access denied. You do not own any products in this order to issue a refund.'];
        }
    }

    $capturedAmount = (float) ($order['captured_amount'] ?: $order['total_amount']);
    $refundedAmount = (float) ($order['refunded_amount'] ?? 0.0);
    $eligibleRemaining = round(max(0.0, $capturedAmount - $refundedAmount), 2);

    if ($eligibleRemaining <= 0.0) {
        return ['ok' => false, 'statusCode' => 422, 'error' => 'Order has already been fully refunded. Eligible remaining refund balance is 0.'];
    }

    $refundAmount = ($requestedAmount === null || $requestedAmount <= 0)
        ? $eligibleRemaining
        : round($requestedAmount, 2);

    if ($refundAmount > $eligibleRemaining) {
        return ['ok' => false, 'statusCode' => 422, 'error' => "Requested refund amount (₹{$refundAmount}) exceeds eligible remaining balance (₹{$eligibleRemaining})."];
    }

    $amountPaise = (int) round($refundAmount * 100);
    $idempotencyKey = 'ref_' . $orderId . '_' . substr(md5(uniqid('', true)), 0, 12);

    $gatewayRefundId = null;
    $rawResponse = null;

    if ($cfg['is_configured']) {
        $refundPayload = [
            'amount' => $amountPaise,
            'notes' => [
                'order_id' => (string) $orderId,
                'order_number' => $orderNumber,
                'reason' => $reason ?: 'Merchant / Buyer refund request',
            ]
        ];
        $apiRes = callRazorpayGatewayPhp(
            'POST',
            "payments/{$gatewayPaymentId}/refund",
            $refundPayload,
            ['X-Razorpay-Idempotency: ' . $idempotencyKey]
        );
        if ($apiRes['ok'] && !empty($apiRes['data']['id'])) {
            $gatewayRefundId = (string) $apiRes['data']['id'];
            $rawResponse = $apiRes['data'];
        } else {
            $errDesc = $apiRes['error'] ?: ($apiRes['data']['error']['description'] ?? 'Razorpay refund API call failed.');
            logPaymentEventPhp('refund_failed', [
                'order_id' => $orderId,
                'amount' => $refundAmount,
                'error' => $errDesc
            ]);
            if (function_exists('dispatchOrderNotificationPhp')) {
                try {
                    dispatchOrderNotificationPhp($pdo, (int)$orderId, 'refund_failed', [
                        'amount' => $refundAmount,
                        'reason' => $errDesc
                    ]);
                } catch (Throwable $e) {}
            }
            return ['ok' => false, 'statusCode' => $apiRes['statusCode'] ?: 502, 'error' => 'Razorpay Refund Failed: ' . $errDesc];
        }
    } else {
        // Fallback test mode refund reference
        $gatewayRefundId = 'rfnd_test_' . substr(md5(uniqid()), 0, 14);
        $rawResponse = ['id' => $gatewayRefundId, 'status' => 'processed', 'mock' => true];
    }

    $pdo->beginTransaction();
    try {
        $newRefunded = round($refundedAmount + $refundAmount, 2);
        $newPaymentStatus = ($newRefunded >= $capturedAmount) ? 'refunded' : 'partially_refunded';

        $pdo->prepare("
            UPDATE orders
            SET refunded_amount = :ramt,
                payment_status = :pst,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :oid
        ")->execute([
            ':ramt' => $newRefunded,
            ':pst' => $newPaymentStatus,
            ':oid' => $orderId
        ]);

        $pdo->prepare("
            UPDATE payment_transactions
            SET status = :pst,
                updated_at = CURRENT_TIMESTAMP
            WHERE order_id = :oid AND transaction_type = 'capture'
        ")->execute([
            ':pst' => $newPaymentStatus,
            ':oid' => $orderId
        ]);

        // Insert refund record in payment_transactions
        $pdo->prepare("
            INSERT INTO payment_transactions
                (order_id, seller_id, gateway, gateway_order_id, gateway_payment_id, refund_id, transaction_type, amount, currency, status, idempotency_key, gateway_payload, created_at, updated_at)
            VALUES
                (:oid, :sid, 'razorpay', :goid, :gpid, :rfid, 'refund', :amt, 'INR', 'refunded', :ikey, :payload, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ")->execute([
            ':oid' => $orderId,
            ':sid' => $order['seller_id'] ? (int) $order['seller_id'] : null,
            ':goid' => $order['gateway_order_id'] ?? null,
            ':gpid' => $gatewayPaymentId,
            ':rfid' => $gatewayRefundId,
            ':amt' => $refundAmount,
            ':ikey' => $idempotencyKey,
            ':payload' => json_encode($rawResponse, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        ]);
        $txId = (int) $pdo->lastInsertId();

        // Also record in seller_refund_records
        try {
            $pdo->prepare("
                INSERT INTO seller_refund_records
                    (idempotency_key, order_id, seller_id, payment_transaction_id, gateway, gateway_payment_id, gateway_refund_id, order_amount, eligible_refund_amount, refunded_amount, status, gateway_response, initiated_by, confirmed_at, created_at, updated_at)
                VALUES
                    (:ikey, :oid, :sid, :ptid, 'razorpay', :gpid, :rfid, :oamt, :eamt, :ramt, 'REFUND_SUCCESS', :resp, :iniby, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ")->execute([
                ':ikey' => $idempotencyKey,
                ':oid' => $orderId,
                ':sid' => $order['seller_id'] ? (int) $order['seller_id'] : 1,
                ':ptid' => $txId,
                ':gpid' => $gatewayPaymentId,
                ':rfid' => $gatewayRefundId,
                ':oamt' => $capturedAmount,
                ':eamt' => $refundAmount,
                ':ramt' => $refundAmount,
                ':resp' => json_encode($rawResponse, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ':iniby' => $actorUser ? (int) ($actorUser['id'] ?? 1) : 1,
            ]);
        } catch (Throwable $e) {}

        // Adjust seller payout recovery deduction if payout was pending
        try {
            $pdo->prepare("
                UPDATE seller_payouts
                SET refund_recovery_deducted = refund_recovery_deducted + :rfamt,
                    net_amount = CASE WHEN net_amount >= :rfamt THEN net_amount - :rfamt ELSE 0.0 END,
                    updated_at = CURRENT_TIMESTAMP
                WHERE seller_id = :sid AND notes LIKE :nmatch AND status = 'PENDING'
            ")->execute([
                ':rfamt' => $refundAmount,
                ':sid' => $order['seller_id'] ? (int) $order['seller_id'] : 1,
                ':nmatch' => "%Order #{$orderId}%"
            ]);
        } catch (Throwable $e) {}

        $pdo->commit();

        logPaymentEventPhp('refund_success', [
            'order_id' => $orderId,
            'refund_id' => $gatewayRefundId,
            'refund_amount' => $refundAmount,
            'new_status' => $newPaymentStatus
        ]);

        if (function_exists('dispatchOrderNotificationPhp')) {
            try {
                dispatchOrderNotificationPhp($pdo, (int)$orderId, 'refund_processed', [
                    'amount' => $refundAmount,
                    'refund_id' => $gatewayRefundId
                ]);
            } catch (Throwable $e) {}
        }

        return [
            'ok' => true,
            'statusCode' => 200,
            'data' => [
                'success' => true,
                'message' => 'Refund processed successfully.',
                'order_id' => $orderId,
                'order_number' => $orderNumber,
                'gateway_payment_id' => $gatewayPaymentId,
                'gateway_refund_id' => $gatewayRefundId,
                'refund_amount' => $refundAmount,
                'refund_amount_formatted' => '₹' . number_format($refundAmount, 2),
                'total_refunded' => $newRefunded,
                'remaining_balance' => round(max(0.0, $capturedAmount - $newRefunded), 2),
                'payment_status' => $newPaymentStatus,
                'status' => 'refunded',
            ]
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'statusCode' => 500, 'error' => 'Database error during refund recording: ' . $e->getMessage()];
    }
}

function getPaymentStatusPhp(PDO $pdo, mixed $orderParam, bool $syncWithGateway = false): array
{
    ensurePaymentSchemaPhp($pdo);

    if (is_numeric($orderParam)) {
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = :id OR order_number = :num LIMIT 1");
        $stmt->execute([':id' => (int) $orderParam, ':num' => (string) $orderParam]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE order_number = :num LIMIT 1");
        $stmt->execute([':num' => (string) $orderParam]);
    }
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        return ['ok' => false, 'statusCode' => 404, 'error' => 'Order not found.'];
    }

    $orderId = (int) $order['id'];

    // If sync requested and not paid and has gateway_order_id, query Razorpay
    if ($syncWithGateway && strtolower((string) ($order['payment_status'] ?? '')) !== 'paid' && !empty($order['gateway_order_id'])) {
        $cfg = getRazorpayConfigPhp();
        if ($cfg['is_configured']) {
            $res = callRazorpayGatewayPhp('GET', "orders/{$order['gateway_order_id']}/payments");
            if ($res['ok'] && !empty($res['data']['items'])) {
                foreach ($res['data']['items'] as $payItem) {
                    if (($payItem['status'] ?? '') === 'captured') {
                        recordOrderPaymentVerificationPhp($pdo, $orderId, [
                            'gateway_payment_id' => $payItem['id'],
                            'gateway_order_id' => $order['gateway_order_id'],
                            'captured_amount' => round(((float) $payItem['amount']) / 100, 2),
                            'razorpay_signature' => 'synced_from_gateway'
                        ], ['role' => 'admin']);
                        // Reload refreshed order
                        $stmt->execute([':id' => $orderId, ':num' => (string) $order['order_number']]);
                        $order = $stmt->fetch(PDO::FETCH_ASSOC);
                        break;
                    }
                }
            }
        }
    }

    $txStmt = $pdo->prepare("SELECT * FROM payment_transactions WHERE order_id = :oid ORDER BY id DESC");
    $txStmt->execute([':oid' => $orderId]);
    $transactions = $txStmt->fetchAll(PDO::FETCH_ASSOC);

    $formattedTxs = array_map(function ($tx) {
        return [
            'id' => (int) $tx['id'],
            'transaction_type' => $tx['transaction_type'],
            'gateway' => $tx['gateway'],
            'gateway_order_id' => $tx['gateway_order_id'],
            'gateway_payment_id' => $tx['gateway_payment_id'],
            'refund_id' => $tx['refund_id'],
            'amount' => (float) $tx['amount'],
            'currency' => $tx['currency'],
            'status' => $tx['status'],
            'commission_amount' => (float) ($tx['commission_amount'] ?? 0),
            'net_seller_amount' => (float) ($tx['net_seller_amount'] ?? 0),
            'created_at' => $tx['created_at'],
        ];
    }, $transactions);

    return [
        'ok' => true,
        'statusCode' => 200,
        'data' => [
            'order_id' => $orderId,
            'order_number' => $order['order_number'],
            'order_status' => $order['order_status'],
            'payment_status' => $order['payment_status'],
            'payment_method' => $order['payment_method'],
            'payment_gateway' => $order['payment_gateway'] ?? 'razorpay',
            'total_amount' => (float) $order['total_amount'],
            'captured_amount' => (float) ($order['captured_amount'] ?? 0),
            'refunded_amount' => (float) ($order['refunded_amount'] ?? 0),
            'gateway_order_id' => $order['gateway_order_id'],
            'gateway_payment_id' => $order['gateway_payment_id'],
            'transactions' => $formattedTxs,
            'transactions_count' => count($formattedTxs),
        ]
    ];
}

function getAdminPaymentTransactionsPhp(PDO $pdo, array $filters = []): array
{
    ensurePaymentSchemaPhp($pdo);

    $page = max(1, (int) ($filters['page'] ?? 1));
    $limit = max(1, min(100, (int) ($filters['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $where = [];
    $params = [];

    if (!empty($filters['status']) && $filters['status'] !== 'all') {
        $where[] = "pt.status = :status";
        $params[':status'] = strtolower(trim((string) $filters['status']));
    }

    if (!empty($filters['transaction_type']) && $filters['transaction_type'] !== 'all') {
        $where[] = "pt.transaction_type = :ttype";
        $params[':ttype'] = strtolower(trim((string) $filters['transaction_type']));
    }

    if (!empty($filters['search'])) {
        $q = '%' . trim((string) $filters['search']) . '%';
        $where[] = "(pt.gateway_payment_id LIKE :q1 OR pt.gateway_order_id LIKE :q2 OR pt.refund_id LIKE :q3 OR o.order_number LIKE :q4 OR o.buyer_name LIKE :q5 OR o.buyer_phone LIKE :q6)";
        $params[':q1'] = $q;
        $params[':q2'] = $q;
        $params[':q3'] = $q;
        $params[':q4'] = $q;
        $params[':q5'] = $q;
        $params[':q6'] = $q;
    }

    if (!empty($filters['from_date'])) {
        $where[] = "pt.created_at >= :fdate";
        $params[':fdate'] = trim((string) $filters['from_date']) . ' 00:00:00';
    }

    if (!empty($filters['to_date'])) {
        $where[] = "pt.created_at <= :tdate";
        $params[':tdate'] = trim((string) $filters['to_date']) . ' 23:59:59';
    }

    $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    // Count query
    $cStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM payment_transactions pt
        LEFT JOIN orders o ON o.id = pt.order_id
        {$whereSql}
    ");
    $cStmt->execute($params);
    $totalCount = (int) $cStmt->fetchColumn();

    // Data query
    $dStmt = $pdo->prepare("
        SELECT pt.*,
               o.order_number, o.buyer_name AS order_buyer_name, o.buyer_phone AS order_buyer_phone,
               o.order_status, o.payment_status AS order_payment_status, o.total_amount AS order_total_amount,
               u.seller_name, u.name AS seller_user_name
        FROM payment_transactions pt
        LEFT JOIN orders o ON o.id = pt.order_id
        LEFT JOIN users u ON u.id = pt.seller_id
        {$whereSql}
        ORDER BY pt.id DESC
        LIMIT {$limit} OFFSET {$offset}
    ");
    $dStmt->execute($params);
    $rows = $dStmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate overall KPIs
    $metrics = [
        'total_volume' => 0.0,
        'captured_volume' => 0.0,
        'refunded_volume' => 0.0,
        'total_transactions' => 0,
        'captured_count' => 0,
        'failed_count' => 0,
        'refunded_count' => 0,
        'pending_count' => 0,
    ];

    try {
        $mStmt = $pdo->query("
            SELECT
                COUNT(*) AS total_tx,
                COALESCE(SUM(CASE WHEN status = 'captured' THEN amount ELSE 0 END), 0) AS captured_amt,
                COALESCE(SUM(CASE WHEN transaction_type = 'refund' OR status = 'refunded' THEN amount ELSE 0 END), 0) AS refund_amt,
                COALESCE(SUM(CASE WHEN status = 'captured' THEN 1 ELSE 0 END), 0) AS captured_cnt,
                COALESCE(SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END), 0) AS failed_cnt,
                COALESCE(SUM(CASE WHEN status = 'refunded' OR transaction_type = 'refund' THEN 1 ELSE 0 END), 0) AS refund_cnt,
                COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) AS pending_cnt
            FROM payment_transactions
        ");
        $mRow = $mStmt->fetch(PDO::FETCH_ASSOC);
        if ($mRow) {
            $metrics['total_transactions'] = (int) $mRow['total_tx'];
            $metrics['captured_volume'] = (float) $mRow['captured_amt'];
            $metrics['refunded_volume'] = (float) $mRow['refund_amt'];
            $metrics['captured_count'] = (int) $mRow['captured_cnt'];
            $metrics['failed_count'] = (int) $mRow['failed_cnt'];
            $metrics['refunded_count'] = (int) $mRow['refund_cnt'];
            $metrics['pending_count'] = (int) $mRow['pending_cnt'];
            $metrics['total_volume'] = $metrics['captured_volume'];
        }
    } catch (Throwable $e) {}

    $transactions = array_map(function ($r) {
        return [
            'id' => (int) $r['id'],
            'order_id' => (int) $r['order_id'],
            'order_number' => $r['order_number'],
            'seller_id' => !empty($r['seller_id']) ? (int) $r['seller_id'] : null,
            'seller_name' => $r['seller_name'] ?: ($r['seller_user_name'] ?: ('Seller #' . ($r['seller_id'] ?? ''))),
            'buyer_name' => $r['buyer_name'] ?: ($r['order_buyer_name'] ?: 'Guest Buyer'),
            'buyer_phone' => $r['buyer_phone'] ?: ($r['order_buyer_phone'] ?: '—'),
            'gateway' => $r['gateway'],
            'gateway_order_id' => $r['gateway_order_id'],
            'gateway_payment_id' => $r['gateway_payment_id'],
            'refund_id' => $r['refund_id'],
            'transaction_type' => $r['transaction_type'],
            'amount' => (float) $r['amount'],
            'amount_formatted' => '₹' . number_format((float) $r['amount'], 2),
            'currency' => $r['currency'],
            'status' => $r['status'],
            'commission_rate' => (float) ($r['commission_rate'] ?? 0.05),
            'commission_amount' => (float) ($r['commission_amount'] ?? 0.0),
            'net_seller_amount' => (float) ($r['net_seller_amount'] ?? 0.0),
            'error_code' => $r['error_code'],
            'error_description' => $r['error_description'],
            'settled_at' => $r['settled_at'],
            'created_at' => $r['created_at'],
            'order_status' => $r['order_status'],
            'order_payment_status' => $r['order_payment_status'],
        ];
    }, $rows);

    return [
        'ok' => true,
        'statusCode' => 200,
        'data' => [
            'transactions' => $transactions,
            'pagination' => [
                'total' => $totalCount,
                'page' => $page,
                'limit' => $limit,
                'pages' => ceil($totalCount / $limit) ?: 1,
            ],
            'metrics' => $metrics,
        ]
    ];
}
