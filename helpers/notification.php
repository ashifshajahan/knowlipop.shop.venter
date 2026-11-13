<?php

/**
 * Pure PHP Centralized Notification & Real-Time Dispatch System
 * 
 * Supports:
 * - MySQL / SQLite database persistence
 * - Buyer, multi-vendor seller, and admin notification dispatching
 * - Strict multi-vendor item isolation (sellers only receive their own items and subtotal)
 * - Node.js real-time Socket.IO trigger via internal low-latency HTTP API
 * - Firebase Cloud Messaging (FCM) mobile push delivery
 * - Automatic stale/invalid device token pruning
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/response.php';

/**
 * Ensure Notifications and Device Tokens schema
 */
function ensureNotificationSchemaPhp(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) return;

    $driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    $isSqlite = ($driver === 'sqlite');

    try {
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
                CREATE INDEX IF NOT EXISTS idx_notifications_role ON notifications(recipient_role);

                CREATE TABLE IF NOT EXISTS users (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    email TEXT NULL,
                    phone TEXT NULL,
                    seller_name TEXT NULL,
                    role TEXT NOT NULL DEFAULT 'seller',
                    fcm_token TEXT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );

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
                CREATE INDEX IF NOT EXISTS idx_device_tokens_phone ON device_tokens(phone);
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
                    INDEX `idx_notifications_order` (`order_id`),
                    INDEX `idx_notifications_role` (`recipient_role`)
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
                    INDEX `idx_device_tokens_user` (`user_id`),
                    INDEX `idx_device_tokens_phone` (`phone`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");

            // Ensure recipient_role column
            try {
                $colStmt = $pdo->query("SHOW COLUMNS FROM `notifications` LIKE 'recipient_role'");
                if (!$colStmt->fetch()) {
                    $pdo->exec("ALTER TABLE `notifications` ADD COLUMN `recipient_role` VARCHAR(40) NULL DEFAULT NULL AFTER `buyer_phone`");
                }
            } catch (Throwable $e) {}

            // Ensure order_id column
            try {
                $colStmt = $pdo->query("SHOW COLUMNS FROM `notifications` LIKE 'order_id'");
                if (!$colStmt->fetch()) {
                    $pdo->exec("ALTER TABLE `notifications` ADD COLUMN `order_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `recipient_role`");
                }
            } catch (Throwable $e) {}
        }
        $ensured = true;
    } catch (Throwable $e) {
        // Schema check failed or already exists
    }
}

/**
 * Remove an expired or invalid FCM device registration token
 */
function pruneInvalidDeviceTokenPhp(PDO $pdo, string $token): void
{
    $token = trim($token);
    if ($token === '') return;

    try {
        $delStmt = $pdo->prepare("DELETE FROM device_tokens WHERE token = :token");
        $delStmt->execute([':token' => $token]);

        $updUser = $pdo->prepare("UPDATE `users` SET fcm_token = NULL WHERE fcm_token = :token");
        $updUser->execute([':token' => $token]);
    } catch (Throwable $e) {}
}

/**
 * Fast non-blocking HTTP dispatch to Node.js notification service
 */
function triggerNodeNotificationPhp(array $notification): bool
{
    // Check if a test mock transport is injected
    if (isset($GLOBALS['__NODE_TEST_HTTP_TRANSPORT']) && is_callable($GLOBALS['__NODE_TEST_HTTP_TRANSPORT'])) {
        try {
            $mockRes = $GLOBALS['__NODE_TEST_HTTP_TRANSPORT']($notification);
            return !empty($mockRes['ok']);
        } catch (Throwable $e) {
            return false;
        }
    }

    $nodePort = getenv('PORT') ?: (getenv('NODE_PORT') ?: '8000');
    $nodeHost = getenv('NODE_HOST') ?: '127.0.0.1';
    $url = "http://{$nodeHost}:{$nodePort}/api/internal/dispatch-notification";

    $secretKey = getenv('INTERNAL_SERVICE_SECRET') ?: 'vendor_internal_secret_key_2026';
    $payloadJson = json_encode($notification, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    // If cURL is not loaded or disabled, fallback to stream context
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payloadJson);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 500);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, 1500);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-Internal-Secret: ' . $secretKey,
            'User-Agent: VendorApp-PHP-NotificationEngine/1.0'
        ]);

        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($httpCode >= 200 && $httpCode < 300);
    }

    // Stream context fallback
    try {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nX-Internal-Secret: {$secretKey}\r\nUser-Agent: VendorApp-PHP-NotificationEngine/1.0\r\n",
                'content' => $payloadJson,
                'timeout' => 1.5,
                'ignore_errors' => true,
            ]
        ]);
        $fp = @fopen($url, 'r', false, $ctx);
        if ($fp) {
            fclose($fp);
            return true;
        }
    } catch (Throwable $e) {}

    return false;
}

/**
 * Dispatch Firebase Cloud Messaging (FCM) Push Notifications
 */
function sendPushNotificationPhp(PDO $pdo, ?int $userId, ?string $phone, string $title, string $message, mixed $data = null): array
{
    ensureNotificationSchemaPhp($pdo);
    $tokens = [];

    try {
        if ($userId) {
            $stmt = $pdo->prepare("SELECT token FROM device_tokens WHERE user_id = :uid");
            $stmt->execute([':uid' => $userId]);
            while ($tok = $stmt->fetchColumn()) {
                if ($tok) $tokens[] = (string)$tok;
            }

            $uStmt = $pdo->prepare("SELECT fcm_token FROM `users` WHERE id = :uid AND fcm_token IS NOT NULL");
            $uStmt->execute([':uid' => $userId]);
            $uTok = $uStmt->fetchColumn();
            if ($uTok) $tokens[] = (string)$uTok;
        }

        if ($phone) {
            $pStmt = $pdo->prepare("SELECT token FROM device_tokens WHERE phone = :phone");
            $pStmt->execute([':phone' => $phone]);
            while ($tok = $pStmt->fetchColumn()) {
                if ($tok) $tokens[] = (string)$tok;
            }
        }
    } catch (Throwable $e) {}

    $tokens = array_values(array_unique(array_filter($tokens)));
    if (empty($tokens)) {
        return ['ok' => true, 'sent' => 0, 'tokens_count' => 0, 'reason' => 'No registered device tokens'];
    }

    // Check FCM Server Key from env or configuration
    $fcmServerKey = getenv('FCM_SERVER_KEY') ?: ($_ENV['FCM_SERVER_KEY'] ?? '');
    if (empty($fcmServerKey)) {
        // FCM not configured or running in simulated mode
        return [
            'ok' => true,
            'simulated' => true,
            'sent' => count($tokens),
            'tokens_count' => count($tokens),
            'message' => 'FCM push simulated (FCM_SERVER_KEY not set)'
        ];
    }

    $invalidTokens = [];
    $sentCount = 0;

    foreach ($tokens as $token) {
        $body = [
            'to' => $token,
            'notification' => [
                'title' => $title,
                'body' => $message,
                'sound' => 'default',
            ],
            'data' => is_array($data) ? $data : ['payload' => is_string($data) ? $data : json_encode($data)],
            'priority' => 'high'
        ];

        if (function_exists('curl_init')) {
            $ch = curl_init('https://fcm.googleapis.com/fcm/send');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 3);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: key=' . $fcmServerKey,
                'Content-Type: application/json'
            ]);
            $rawRes = curl_exec($ch);
            curl_close($ch);

            if ($rawRes) {
                $resData = json_decode($rawRes, true);
                if (!empty($resData['results'][0]['error'])) {
                    $err = $resData['results'][0]['error'];
                    if (in_array($err, ['NotRegistered', 'InvalidRegistration', 'registration-token-not-registered'], true)) {
                        $invalidTokens[] = $token;
                    }
                } else if (!empty($resData['success'])) {
                    $sentCount++;
                }
            }
        }
    }

    // Prune invalid tokens
    foreach ($invalidTokens as $badToken) {
        pruneInvalidDeviceTokenPhp($pdo, $badToken);
    }

    return [
        'ok' => true,
        'sent' => $sentCount,
        'tokens_count' => count($tokens),
        'pruned_tokens' => count($invalidTokens)
    ];
}

/**
 * Core Notification Creator:
 * 1. Persists notification in MySQL notifications table
 * 2. Fires non-blocking HTTP dispatch to Node.js Socket.IO realtime layer
 * 3. Dispatches FCM mobile push notifications to registered devices
 */
function createNotificationPhp(
    PDO $pdo,
    ?int $userId,
    ?string $phone,
    string $title,
    string $message,
    string $type = 'order',
    mixed $data = null,
    ?string $recipientRole = null,
    ?int $orderId = null
): int {
    ensureNotificationSchemaPhp($pdo);

    $dataStr = is_string($data) ? $data : ($data !== null ? json_encode($data, JSON_UNESCAPED_UNICODE) : null);
    $notifId = 0;

    try {
        $stmt = $pdo->prepare("
            INSERT INTO `notifications` (user_id, buyer_phone, recipient_role, order_id, title, message, type, data, is_read)
            VALUES (:uid, :phone, :role, :oid, :title, :msg, :type, :data, 0)
        ");
        $stmt->execute([
            ':uid' => $userId ?: null,
            ':phone' => $phone ?: null,
            ':role' => $recipientRole ?: null,
            ':oid' => $orderId ?: null,
            ':title' => $title,
            ':msg' => $message,
            ':type' => $type,
            ':data' => $dataStr,
        ]);
        $notifId = (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        // Fallback in case columns differ
        try {
            $stmt = $pdo->prepare("
                INSERT INTO `notifications` (user_id, buyer_phone, title, message, type, data, is_read)
                VALUES (:uid, :phone, :title, :msg, :type, :data, 0)
            ");
            $stmt->execute([
                ':uid' => $userId ?: null,
                ':phone' => $phone ?: null,
                ':title' => $title,
                ':msg' => $message,
                ':type' => $type,
                ':data' => $dataStr,
            ]);
            $notifId = (int)$pdo->lastInsertId();
        } catch (Throwable $e2) {
            return 0;
        }
    }

    // Build realtime payload
    $payload = [
        'id' => $notifId,
        'user_id' => $userId,
        'phone' => $phone,
        'recipient_role' => $recipientRole,
        'order_id' => $orderId,
        'title' => $title,
        'message' => $message,
        'type' => $type,
        'data' => is_string($data) ? json_decode($data, true) : $data,
        'is_read' => false,
        'created_at' => date('Y-m-d H:i:s'),
    ];

    // Trigger Node.js real-time Socket.IO dispatch
    try {
        triggerNodeNotificationPhp($payload);
    } catch (Throwable $e) {}

    // Dispatch Push Notification (FCM)
    try {
        sendPushNotificationPhp($pdo, $userId, $phone, $title, $message, $data);
    } catch (Throwable $e) {}

    return $notifId;
}

/**
 * Backwards-compatible createNotification wrapper
 */
if (!function_exists('createNotification')) {
    function createNotification(PDO $pdo, ?int $userId, ?string $phone, string $title, string $message, string $type = 'order', mixed $data = null): void
    {
        createNotificationPhp($pdo, $userId, $phone, $title, $message, $type, $data);
    }
}

/**
 * Dispatch Order Notifications with Strict Multi-Vendor Isolation
 * 
 * Guarantees:
 * - Buyer receives notification for their entire order
 * - Each vendor receives ONLY their own product items and financial subtotal
 * - Admins receive order milestone, cancellation, and payment/refund alerts
 */
function dispatchOrderNotificationPhp(PDO $pdo, int $orderId, string $event, array $extra = []): void
{
    ensureNotificationSchemaPhp($pdo);

    try {
        $hasUsers = false;
        try {
            $uChk = $pdo->query("SELECT 1 FROM `users` LIMIT 1");
            $hasUsers = true;
        } catch (Throwable $e) {}

        if ($hasUsers) {
            $stmt = $pdo->prepare("SELECT o.*, u.seller_name, u.name AS seller_user_name FROM `orders` o LEFT JOIN `users` u ON o.seller_id = u.id WHERE o.id = :oid LIMIT 1");
        } else {
            $stmt = $pdo->prepare("SELECT o.*, NULL AS seller_name, NULL AS seller_user_name FROM `orders` o WHERE o.id = :oid LIMIT 1");
        }
        $stmt->execute([':oid' => $orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) return;

        $orderNum = (string)$order['order_number'];
        $buyerPhone = (string)($order['buyer_phone'] ?? '');
        $buyerUserId = !empty($order['buyer_id']) ? (int)$order['buyer_id'] : (!empty($order['user_id']) ? (int)$order['user_id'] : null);
        $totalFormatted = '₹' . number_format((float)$order['total_amount'], 2);

        // Fetch order items to extract multi-vendor partitions
        $itemsStmt = $pdo->prepare("SELECT * FROM `order_items` WHERE order_id = :oid");
        $itemsStmt->execute([':oid' => $orderId]);
        $allItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Group items strictly by seller_id
        $sellerItemMap = [];
        foreach ($allItems as $item) {
            $sid = !empty($item['seller_id']) ? (int)$item['seller_id'] : (!empty($order['seller_id']) ? (int)$order['seller_id'] : 0);
            if ($sid > 0) {
                if (!isset($sellerItemMap[$sid])) {
                    $sellerItemMap[$sid] = [];
                }
                $sellerItemMap[$sid][] = [
                    'id' => (int)$item['id'],
                    'product_id' => (int)($item['product_id'] ?? 0),
                    'title' => (string)($item['title'] ?? ($item['product_name'] ?? ($item['product_title'] ?? 'Product'))),
                    'quantity' => (int)($item['quantity'] ?? 1),
                    'price' => (float)($item['unit_price'] ?? ($item['price'] ?? 0)),
                    'subtotal' => (float)($item['subtotal'] ?? ((float)($item['unit_price'] ?? ($item['price'] ?? 0)) * (int)($item['quantity'] ?? 1))),
                ];
            }
        }

        // If no order_items were linked, fallback to order.seller_id
        if (empty($sellerItemMap) && !empty($order['seller_id'])) {
            $sellerItemMap[(int)$order['seller_id']] = [];
        }

        // Helper to query admin user IDs
        $getAdminIds = function() use ($pdo): array {
            $admins = [];
            try {
                $aStmt = $pdo->query("SELECT id FROM `users` WHERE role = 'admin'");
                while ($aid = $aStmt->fetchColumn()) {
                    $admins[] = (int)$aid;
                }
            } catch (Throwable $e) {}
            return $admins;
        };

        switch ($event) {
            case 'order_created':
            case 'order_placed':
                // 1. Buyer notification
                createNotificationPhp(
                    $pdo,
                    $buyerUserId,
                    $buyerPhone,
                    'Order Placed! 🎉',
                    "Thank you for your order #{$orderNum}. Total amount: {$totalFormatted}.",
                    'order',
                    ['order_id' => $orderId, 'order_number' => $orderNum, 'total_amount' => (float)$order['total_amount']],
                    'buyer',
                    $orderId
                );

                // 2. Multi-Vendor isolated seller notifications
                foreach ($sellerItemMap as $sid => $items) {
                    $sSubtotal = array_reduce($items, fn($sum, $it) => $sum + (float)$it['subtotal'], 0.0);
                    $sSubtotalFmt = '₹' . number_format($sSubtotal, 2);
                    $itemCount = count($items);
                    $itemDesc = $itemCount > 0 ? " ({$itemCount} item" . ($itemCount > 1 ? 's' : '') . ", {$sSubtotalFmt})" : '';

                    createNotificationPhp(
                        $pdo,
                        $sid,
                        null,
                        'New Order Received! 🛍️',
                        "You received a new order for #{$orderNum}{$itemDesc}.",
                        'order_created',
                        [
                            'order_id' => $orderId,
                            'order_number' => $orderNum,
                            'seller_id' => $sid,
                            'seller_subtotal' => $sSubtotal,
                            'items' => $items, // STRICT VENDOR ISOLATION: Only this seller's items
                        ],
                        'seller',
                        $orderId
                    );
                }

                // 3. Admin notification
                $adminIds = $getAdminIds();
                if (!empty($adminIds)) {
                    foreach ($adminIds as $aid) {
                        createNotificationPhp(
                            $pdo,
                            $aid,
                            null,
                            'New Marketplace Order Placed 📢',
                            "New order #{$orderNum} placed. Total: {$totalFormatted} across " . count($sellerItemMap) . " vendor(s).",
                            'admin_order_alert',
                            ['order_id' => $orderId, 'order_number' => $orderNum, 'total_amount' => (float)$order['total_amount']],
                            'admin',
                            $orderId
                        );
                    }
                } else {
                    createNotificationPhp(
                        $pdo,
                        null,
                        null,
                        'New Marketplace Order Placed 📢',
                        "New order #{$orderNum} placed. Total: {$totalFormatted}.",
                        'admin_order_alert',
                        ['order_id' => $orderId, 'order_number' => $orderNum, 'total_amount' => (float)$order['total_amount']],
                        'admin',
                        $orderId
                    );
                }
                break;

            case 'order_confirmed':
            case 'order_accepted':
                createNotificationPhp(
                    $pdo,
                    $buyerUserId,
                    $buyerPhone,
                    'Order Confirmed! ✅',
                    "Your order #{$orderNum} has been accepted and confirmed by the seller.",
                    'order_confirmed',
                    ['order_id' => $orderId, 'order_number' => $orderNum],
                    'buyer',
                    $orderId
                );
                break;

            case 'order_processing':
                createNotificationPhp(
                    $pdo,
                    $buyerUserId,
                    $buyerPhone,
                    'Order In Progress 📦',
                    "Your order #{$orderNum} is now being processed and packed for dispatch.",
                    'order_processing',
                    ['order_id' => $orderId, 'order_number' => $orderNum],
                    'buyer',
                    $orderId
                );
                break;

            case 'order_shipped':
                $tracking = !empty($extra['tracking_number']) ? " Tracking: {$extra['tracking_number']}" : '';
                $carrier = !empty($extra['carrier']) ? " via {$extra['carrier']}." : '.';
                createNotificationPhp(
                    $pdo,
                    $buyerUserId,
                    $buyerPhone,
                    'Order Shipped! 🚚',
                    "Your order #{$orderNum} is on its way{$carrier}{$tracking}",
                    'order_shipped',
                    [
                        'order_id' => $orderId,
                        'order_number' => $orderNum,
                        'tracking_number' => $extra['tracking_number'] ?? null,
                        'carrier' => $extra['carrier'] ?? null
                    ],
                    'buyer',
                    $orderId
                );
                break;

            case 'order_delivered':
                createNotificationPhp(
                    $pdo,
                    $buyerUserId,
                    $buyerPhone,
                    'Order Delivered! 🎁',
                    "Your order #{$orderNum} has been delivered. Thank you for shopping with us!",
                    'order_delivered',
                    ['order_id' => $orderId, 'order_number' => $orderNum],
                    'buyer',
                    $orderId
                );
                foreach (array_keys($sellerItemMap) as $sid) {
                    createNotificationPhp(
                        $pdo,
                        $sid,
                        null,
                        'Order Delivered 📬',
                        "Order #{$orderNum} was marked delivered to buyer.",
                        'order_delivered',
                        ['order_id' => $orderId, 'order_number' => $orderNum],
                        'seller',
                        $orderId
                    );
                }
                break;

            case 'cancellation_requested':
                $reason = !empty($extra['reason']) ? ": {$extra['reason']}" : '.';
                // Buyer notification
                createNotificationPhp(
                    $pdo,
                    $buyerUserId,
                    $buyerPhone,
                    'Cancellation Requested ⏳',
                    "Your cancellation request for order #{$orderNum} is under review.",
                    'cancellation_requested',
                    ['order_id' => $orderId, 'order_number' => $orderNum, 'reason' => $extra['reason'] ?? ''],
                    'buyer',
                    $orderId
                );

                // Sellers notification
                foreach ($sellerItemMap as $sid => $items) {
                    createNotificationPhp(
                        $pdo,
                        $sid,
                        null,
                        'Cancellation Request Received ⚠️',
                        "Buyer requested cancellation for order #{$orderNum}{$reason}",
                        'cancellation_requested',
                        [
                            'order_id' => $orderId,
                            'order_number' => $orderNum,
                            'seller_id' => $sid,
                            'reason' => $extra['reason'] ?? '',
                            'items' => $items,
                        ],
                        'seller',
                        $orderId
                    );
                }

                // Admin notification
                $adminIds = $getAdminIds();
                if (!empty($adminIds)) {
                    foreach ($adminIds as $aid) {
                        createNotificationPhp(
                            $pdo,
                            $aid,
                            null,
                            'Action Required: Cancellation Request ⚠️',
                            "Cancellation requested for order #{$orderNum}{$reason}",
                            'admin_cancellation_alert',
                            ['order_id' => $orderId, 'order_number' => $orderNum, 'reason' => $extra['reason'] ?? ''],
                            'admin',
                            $orderId
                        );
                    }
                } else {
                    createNotificationPhp(
                        $pdo,
                        null,
                        null,
                        'Action Required: Cancellation Request ⚠️',
                        "Cancellation requested for order #{$orderNum}{$reason}",
                        'admin_cancellation_alert',
                        ['order_id' => $orderId, 'order_number' => $orderNum, 'reason' => $extra['reason'] ?? ''],
                        'admin',
                        $orderId
                    );
                }
                break;

            case 'cancellation_approved':
            case 'order_cancelled':
                $refundAmtPaise = $extra['refund_amount'] ?? $order['total_amount'];
                $refundFmt = '₹' . number_format((float)$refundAmtPaise, 2);
                $refundMsg = !empty($extra['refunded']) ? " Refund of {$refundFmt} has been initiated." : '';

                createNotificationPhp(
                    $pdo,
                    $buyerUserId,
                    $buyerPhone,
                    'Order Cancelled 🚫',
                    "Order #{$orderNum} has been cancelled.{$refundMsg}",
                    'order_cancelled',
                    ['order_id' => $orderId, 'order_number' => $orderNum, 'refunded' => !empty($extra['refunded'])],
                    'buyer',
                    $orderId
                );

                foreach ($sellerItemMap as $sid => $items) {
                    createNotificationPhp(
                        $pdo,
                        $sid,
                        null,
                        'Order Cancelled ❌',
                        "Order #{$orderNum} was approved for cancellation.",
                        'order_cancelled',
                        ['order_id' => $orderId, 'order_number' => $orderNum, 'seller_id' => $sid],
                        'seller',
                        $orderId
                    );
                }
                break;

            case 'cancellation_rejected':
                $expl = !empty($extra['reason']) ? " Reason: {$extra['reason']}" : '';
                createNotificationPhp(
                    $pdo,
                    $buyerUserId,
                    $buyerPhone,
                    'Cancellation Request Declined ⚠️',
                    "Your cancellation request for order #{$orderNum} was declined.{$expl}",
                    'cancellation_rejected',
                    ['order_id' => $orderId, 'order_number' => $orderNum, 'reason' => $extra['reason'] ?? ''],
                    'buyer',
                    $orderId
                );
                break;

            case 'payment_success':
            case 'payment_captured':
                $payAmt = !empty($extra['amount']) ? ('₹' . number_format((float)$extra['amount'], 2)) : $totalFormatted;
                createNotificationPhp(
                    $pdo,
                    $buyerUserId,
                    $buyerPhone,
                    'Payment Successful! 💳',
                    "Payment of {$payAmt} for order #{$orderNum} was verified and captured successfully.",
                    'payment_success',
                    [
                        'order_id' => $orderId,
                        'order_number' => $orderNum,
                        'payment_id' => $extra['payment_id'] ?? null,
                        'amount' => $extra['amount'] ?? null
                    ],
                    'buyer',
                    $orderId
                );

                foreach ($sellerItemMap as $sid => $items) {
                    $sSubtotal = array_reduce($items, fn($sum, $it) => $sum + (float)$it['subtotal'], 0.0);
                    $sSubtotalFmt = '₹' . number_format($sSubtotal, 2);
                    createNotificationPhp(
                        $pdo,
                        $sid,
                        null,
                        'Payment Confirmed 💰',
                        "Payment for order #{$orderNum} was confirmed. Your item total: {$sSubtotalFmt}.",
                        'payment_success',
                        ['order_id' => $orderId, 'order_number' => $orderNum, 'seller_id' => $sid, 'seller_subtotal' => $sSubtotal],
                        'seller',
                        $orderId
                    );
                }
                break;

            case 'payment_failed':
                $failReason = !empty($extra['error_description']) ? ": {$extra['error_description']}" : '.';
                createNotificationPhp(
                    $pdo,
                    $buyerUserId,
                    $buyerPhone,
                    'Payment Failed ❌',
                    "Payment for order #{$orderNum} could not be processed{$failReason} Please retry checkout.",
                    'payment_failed',
                    ['order_id' => $orderId, 'order_number' => $orderNum, 'reason' => $extra['error_description'] ?? ''],
                    'buyer',
                    $orderId
                );

                // Admin notification on payment failure
                $adminIds = $getAdminIds();
                foreach ($adminIds as $aid) {
                    createNotificationPhp(
                        $pdo,
                        $aid,
                        null,
                        'Payment Failure Alert ⚠️',
                        "Payment failed for order #{$orderNum}{$failReason}",
                        'admin_payment_alert',
                        ['order_id' => $orderId, 'order_number' => $orderNum, 'reason' => $extra['error_description'] ?? ''],
                        'admin',
                        $orderId
                    );
                }
                break;

            case 'refund_processed':
                $amt = !empty($extra['amount']) ? ('₹' . number_format((float)$extra['amount'], 2)) : $totalFormatted;
                createNotificationPhp(
                    $pdo,
                    $buyerUserId,
                    $buyerPhone,
                    'Refund Processed 💳',
                    "A refund of {$amt} for order #{$orderNum} has been processed successfully.",
                    'refund_processed',
                    ['order_id' => $orderId, 'order_number' => $orderNum, 'amount' => $extra['amount'] ?? null],
                    'buyer',
                    $orderId
                );
                foreach (array_keys($sellerItemMap) as $sid) {
                    createNotificationPhp(
                        $pdo,
                        $sid,
                        null,
                        'Refund Logged',
                        "Refund of {$amt} logged for order #{$orderNum}.",
                        'refund_processed',
                        ['order_id' => $orderId, 'order_number' => $orderNum],
                        'seller',
                        $orderId
                    );
                }
                break;

            case 'refund_failed':
                $reason = !empty($extra['reason']) ? ": {$extra['reason']}" : '.';
                $adminIds = $getAdminIds();
                foreach ($adminIds as $aid) {
                    createNotificationPhp(
                        $pdo,
                        $aid,
                        null,
                        'Critical: Gateway Refund Failed 🚨',
                        "Automated refund failed for order #{$orderNum}{$reason} Manual review required.",
                        'admin_refund_alert',
                        ['order_id' => $orderId, 'order_number' => $orderNum, 'reason' => $extra['reason'] ?? ''],
                        'admin',
                        $orderId
                    );
                }
                break;

            case 'order_rejected':
                $reason = !empty($extra['reason']) ? ": {$extra['reason']}" : '.';
                createNotificationPhp(
                    $pdo,
                    $buyerUserId,
                    $buyerPhone,
                    'Order Rejected ❌',
                    "Your order #{$orderNum} could not be accepted by the seller{$reason}",
                    'order_rejected',
                    ['order_id' => $orderId, 'order_number' => $orderNum, 'reason' => $extra['reason'] ?? ''],
                    'buyer',
                    $orderId
                );
                break;

            case 'return_requested':
                $reason = !empty($extra['reason']) ? ": {$extra['reason']}" : '.';
                createNotificationPhp(
                    $pdo,
                    $buyerUserId,
                    $buyerPhone,
                    'Return Requested 📦',
                    "Your return request for order #{$orderNum} has been submitted.",
                    'return_requested',
                    ['order_id' => $orderId, 'order_number' => $orderNum],
                    'buyer',
                    $orderId
                );
                foreach ($sellerItemMap as $sid => $items) {
                    createNotificationPhp(
                        $pdo,
                        $sid,
                        null,
                        'Return Request Received ⚠️',
                        "Return requested for order #{$orderNum}{$reason}",
                        'return_requested',
                        ['order_id' => $orderId, 'order_number' => $orderNum, 'seller_id' => $sid, 'items' => $items],
                        'seller',
                        $orderId
                    );
                }
                break;
        }
    } catch (Throwable $e) {}
}
