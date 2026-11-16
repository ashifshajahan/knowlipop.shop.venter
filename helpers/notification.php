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
                    delivery_status TEXT NOT NULL DEFAULT 'delivered',
                    read_at DATETIME NULL DEFAULT NULL,
                    delivered_at DATETIME NULL DEFAULT NULL,
                    dedup_key TEXT NULL DEFAULT NULL,
                    action_url TEXT NULL DEFAULT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );
                CREATE INDEX IF NOT EXISTS idx_notifications_user ON notifications(user_id);
                CREATE INDEX IF NOT EXISTS idx_notifications_phone ON notifications(buyer_phone);
                CREATE INDEX IF NOT EXISTS idx_notifications_read ON notifications(is_read);
                CREATE INDEX IF NOT EXISTS idx_notifications_order ON notifications(order_id);
                CREATE INDEX IF NOT EXISTS idx_notifications_role ON notifications(recipient_role);
                CREATE INDEX IF NOT EXISTS idx_notifications_delivery ON notifications(delivery_status);
                CREATE INDEX IF NOT EXISTS idx_notifications_dedup ON notifications(dedup_key);

                CREATE TABLE IF NOT EXISTS users (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    email TEXT NULL,
                    phone TEXT NULL,
                    seller_name TEXT NULL,
                    role TEXT NOT NULL DEFAULT 'seller',
                    account_status TEXT NOT NULL DEFAULT 'active',
                    fcm_token TEXT NULL,
                    is_online INTEGER NOT NULL DEFAULT 0,
                    last_login_at DATETIME NULL DEFAULT NULL,
                    last_active_at DATETIME NULL DEFAULT NULL,
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

            // Check SQLite columns on existing tables
            $sqliteAdd = function(string $table, string $col, string $def) use ($pdo): void {
                try {
                    $cols = $pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC);
                    $names = array_map(fn($c) => strtolower((string)$c['name']), $cols);
                    if (!in_array(strtolower($col), $names, true)) {
                        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN {$def}");
                    }
                } catch (Throwable $e) {}
            };
            $sqliteAdd('notifications', 'recipient_role', "recipient_role TEXT NULL DEFAULT NULL");
            $sqliteAdd('notifications', 'order_id', "order_id INTEGER NULL DEFAULT NULL");
            $sqliteAdd('notifications', 'delivery_status', "delivery_status TEXT NOT NULL DEFAULT 'delivered'");
            $sqliteAdd('notifications', 'read_at', "read_at DATETIME NULL DEFAULT NULL");
            $sqliteAdd('notifications', 'delivered_at', "delivered_at DATETIME NULL DEFAULT NULL");
            $sqliteAdd('notifications', 'dedup_key', "dedup_key TEXT NULL DEFAULT NULL");
            $sqliteAdd('notifications', 'action_url', "action_url TEXT NULL DEFAULT NULL");
            $sqliteAdd('users', 'account_status', "account_status TEXT NOT NULL DEFAULT 'active'");
            $sqliteAdd('users', 'is_online', "is_online INTEGER NOT NULL DEFAULT 0");
            $sqliteAdd('users', 'last_login_at', "last_login_at DATETIME NULL DEFAULT NULL");
            $sqliteAdd('users', 'last_active_at', "last_active_at DATETIME NULL DEFAULT NULL");
            $sqliteAdd('orders', 'acknowledged_at', "acknowledged_at DATETIME NULL DEFAULT NULL");
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
                    `delivery_status` VARCHAR(40) NOT NULL DEFAULT 'delivered',
                    `read_at` TIMESTAMP NULL DEFAULT NULL,
                    `delivered_at` TIMESTAMP NULL DEFAULT NULL,
                    `dedup_key` VARCHAR(120) NULL DEFAULT NULL,
                    `action_url` VARCHAR(255) NULL DEFAULT NULL,
                    `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    INDEX `idx_notifications_user` (`user_id`),
                    INDEX `idx_notifications_phone` (`buyer_phone`),
                    INDEX `idx_notifications_read` (`is_read`),
                    INDEX `idx_notifications_order` (`order_id`),
                    INDEX `idx_notifications_role` (`recipient_role`),
                    INDEX `idx_notifications_delivery` (`delivery_status`),
                    INDEX `idx_notifications_dedup` (`dedup_key`)
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

            $mysqlAdd = function(string $table, string $col, string $def) use ($pdo): void {
                try {
                    $chk = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE '{$col}'")->fetchAll();
                    if (empty($chk)) {
                        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN {$def}");
                    }
                } catch (Throwable $e) {}
            };

            $mysqlAdd('notifications', 'recipient_role', "`recipient_role` VARCHAR(40) NULL DEFAULT NULL AFTER `buyer_phone`");
            $mysqlAdd('notifications', 'order_id', "`order_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `recipient_role`");
            $mysqlAdd('notifications', 'delivery_status', "`delivery_status` VARCHAR(40) NOT NULL DEFAULT 'delivered' AFTER `is_read`");
            $mysqlAdd('notifications', 'read_at', "`read_at` TIMESTAMP NULL DEFAULT NULL AFTER `delivery_status`");
            $mysqlAdd('notifications', 'delivered_at', "`delivered_at` TIMESTAMP NULL DEFAULT NULL AFTER `read_at`");
            $mysqlAdd('notifications', 'dedup_key', "`dedup_key` VARCHAR(120) NULL DEFAULT NULL AFTER `delivered_at`");
            $mysqlAdd('notifications', 'action_url', "`action_url` VARCHAR(255) NULL DEFAULT NULL AFTER `dedup_key`");
            $mysqlAdd('users', 'is_online', "`is_online` TINYINT(1) NOT NULL DEFAULT 0");
            $mysqlAdd('users', 'last_login_at', "`last_login_at` TIMESTAMP NULL DEFAULT NULL");
            $mysqlAdd('users', 'last_active_at', "`last_active_at` TIMESTAMP NULL DEFAULT NULL");
            $mysqlAdd('orders', 'acknowledged_at', "`acknowledged_at` TIMESTAMP NULL DEFAULT NULL");

            try {
                $idxChk = $pdo->query("SHOW INDEX FROM `notifications` WHERE Key_name = 'idx_notifications_delivery'")->fetchAll();
                if (empty($idxChk)) {
                    $pdo->exec("ALTER TABLE `notifications` ADD INDEX `idx_notifications_delivery` (`delivery_status`)");
                }
            } catch (Throwable $e) {}
            try {
                $idxChk2 = $pdo->query("SHOW INDEX FROM `notifications` WHERE Key_name = 'idx_notifications_dedup'")->fetchAll();
                if (empty($idxChk2)) {
                    $pdo->exec("ALTER TABLE `notifications` ADD INDEX `idx_notifications_dedup` (`dedup_key`)");
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
 * Register or update an FCM device token for a user/seller
 */
function registerDeviceTokenPhp(PDO $pdo, ?int $userId, string $token, string $platform = 'android', ?string $phone = null): void
{
    ensureNotificationSchemaPhp($pdo);
    try {
        $chk = $pdo->prepare("SELECT id FROM device_tokens WHERE token = :token LIMIT 1");
        $chk->execute([':token' => $token]);
        $ex = $chk->fetch(PDO::FETCH_ASSOC);
        if ($ex) {
            $upd = $pdo->prepare("UPDATE device_tokens SET user_id = :uid, phone = :phone, platform = :plat, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
            $upd->execute([':uid' => $userId, ':phone' => $phone, ':plat' => $platform, ':id' => $ex['id']]);
        } else {
            $ins = $pdo->prepare("INSERT INTO device_tokens (user_id, phone, token, platform, created_at, updated_at) VALUES (:uid, :phone, :token, :plat, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
            $ins->execute([':uid' => $userId, ':phone' => $phone, ':token' => $token, ':plat' => $platform]);
        }
        if ($userId) {
            $uStmt = $pdo->prepare("UPDATE `users` SET fcm_token = :token WHERE id = :uid");
            $uStmt->execute([':token' => $token, ':uid' => $userId]);
        }
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
 * Helper to load Firebase Service Account JSON
 */
function getFirebaseServiceAccountPhp(): ?array {
    static $sa = null;
    if ($sa !== null) return $sa;

    $searchedPaths = [];

    // 1. Check explicit environment paths
    $envPath = getenv('FIREBASE_SERVICE_ACCOUNT_PATH') ?: ($_ENV['FIREBASE_SERVICE_ACCOUNT_PATH'] ?? null);
    if (!empty($envPath)) {
        $searchedPaths[] = $envPath;
        if (file_exists($envPath)) {
            $parsed = json_decode((string)@file_get_contents($envPath), true);
            if (!empty($parsed['client_email']) && !empty($parsed['private_key'])) {
                $sa = $parsed;
                $GLOBALS['FCM_SERVICE_ACCOUNT_LOADED_PATH'] = $envPath;
                $GLOBALS['FCM_SERVICE_ACCOUNT_SEARCHED_PATHS'] = $searchedPaths;
                return $sa;
            }
        }
    }

    $googleCreds = getenv('GOOGLE_APPLICATION_CREDENTIALS') ?: ($_ENV['GOOGLE_APPLICATION_CREDENTIALS'] ?? null);
    if (!empty($googleCreds)) {
        $searchedPaths[] = $googleCreds;
        if (file_exists($googleCreds)) {
            $parsed = json_decode((string)@file_get_contents($googleCreds), true);
            if (!empty($parsed['client_email']) && !empty($parsed['private_key'])) {
                $sa = $parsed;
                $GLOBALS['FCM_SERVICE_ACCOUNT_LOADED_PATH'] = $googleCreds;
                $GLOBALS['FCM_SERVICE_ACCOUNT_SEARCHED_PATHS'] = $searchedPaths;
                return $sa;
            }
        }
    }

    // 2. Direct JSON in environment
    $rawEnv = getenv('FIREBASE_SERVICE_ACCOUNT_JSON') ?: ($_ENV['FIREBASE_SERVICE_ACCOUNT_JSON'] ?? null);
    if (!empty($rawEnv)) {
        $parsed = json_decode($rawEnv, true);
        if (!empty($parsed['client_email']) && !empty($parsed['private_key'])) {
            $sa = $parsed;
            $GLOBALS['FCM_SERVICE_ACCOUNT_LOADED_PATH'] = 'FIREBASE_SERVICE_ACCOUNT_JSON (env)';
            $GLOBALS['FCM_SERVICE_ACCOUNT_SEARCHED_PATHS'] = $searchedPaths;
            return $sa;
        }
    }

    // 3. Search directories (Hostinger structure, public_html, and parent directories)
    $candidateDirs = [
        __DIR__,
        __DIR__ . '/..',
        __DIR__ . '/../config',
        __DIR__ . '/config',
        __DIR__ . '/../..',
        __DIR__ . '/../../..',
        __DIR__ . '/../../../..',
        '/home/u999617803/domains/knowlipop.shop/public_html',
        '/home/u999617803/domains/knowlipop.shop/public_html/config',
        '/home/u999617803/domains/knowlipop.shop/public_html/api',
        '/home/u999617803/domains/knowlipop.shop',
        '/home/u999617803/domains/knowlipop.shop/config',
        '/home/u999617803',
        '/home/u999617803/config',
        '/home/u999617803/venter_uploads',
        '/home/u999617803/hbuilds'
    ];

    $candidateFileNames = [
        'knowlipop-vendor-firebase-adminsdk.json',
        'firebase-service-account.json',
        'serviceAccountKey.json',
        'firebase-credentials.json',
        'vendor-app-18b18-firebase-adminsdk.json'
    ];

    $uniqueDirs = array_unique(array_filter(array_map('realpath', $candidateDirs)));

    foreach ($uniqueDirs as $dir) {
        if (!is_dir($dir)) continue;

        // Check specific file names
        foreach ($candidateFileNames as $fn) {
            $fullPath = $dir . DIRECTORY_SEPARATOR . $fn;
            if (!in_array($fullPath, $searchedPaths, true)) {
                $searchedPaths[] = $fullPath;
            }
            if (file_exists($fullPath)) {
                $content = @file_get_contents($fullPath);
                if ($content) {
                    $parsed = json_decode($content, true);
                    if (!empty($parsed['client_email']) && !empty($parsed['private_key'])) {
                        $sa = $parsed;
                        $GLOBALS['FCM_SERVICE_ACCOUNT_LOADED_PATH'] = $fullPath;
                        $GLOBALS['FCM_SERVICE_ACCOUNT_SEARCHED_PATHS'] = $searchedPaths;
                        return $sa;
                    }
                }
            }
        }

        // Dynamic directory scan for any matching json
        try {
            $files = @scandir($dir) ?: [];
            foreach ($files as $f) {
                if (!str_ends_with(strtolower($f), '.json')) continue;
                $low = strtolower($f);
                if (str_contains($low, 'adminsdk') || str_contains($low, 'firebase') || str_contains($low, 'vendor-app-18b18') || str_contains($low, 'knowlipop-vendor')) {
                    $fullPath = $dir . DIRECTORY_SEPARATOR . $f;
                    if (!in_array($fullPath, $searchedPaths, true)) {
                        $searchedPaths[] = $fullPath;
                    }
                    if (file_exists($fullPath)) {
                        $content = @file_get_contents($fullPath);
                        if ($content) {
                            $parsed = json_decode($content, true);
                            if (!empty($parsed['client_email']) && !empty($parsed['private_key'])) {
                                $sa = $parsed;
                                $GLOBALS['FCM_SERVICE_ACCOUNT_LOADED_PATH'] = $fullPath;
                                $GLOBALS['FCM_SERVICE_ACCOUNT_SEARCHED_PATHS'] = $searchedPaths;
                                return $sa;
                            }
                        }
                    }
                }
            }
        } catch (Throwable $e) {}
    }

    $GLOBALS['FCM_SERVICE_ACCOUNT_SEARCHED_PATHS'] = $searchedPaths;
    return null;
}

/**
 * Generate Google OAuth2 Bearer Access Token using Service Account Private Key
 */
function getFirebaseAccessTokenPhp(array $sa): ?string {
    static $tokenCache = null;
    static $tokenExpiry = 0;

    if ($tokenCache && time() < ($tokenExpiry - 120)) {
        return $tokenCache;
    }

    $now = time();
    $header = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claims = base64_encode(json_encode([
        'iss' => $sa['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud' => 'https://oauth2.googleapis.com/token',
        'exp' => $now + 3600,
        'iat' => $now
    ]));

    $header = str_replace(['+', '/', '='], ['-', '_', ''], $header);
    $claims = str_replace(['+', '/', '='], ['-', '_', ''], $claims);
    $toSign = $header . '.' . $claims;

    $signature = '';
    if (!function_exists('openssl_sign') || !openssl_sign($toSign, $signature, $sa['private_key'], OPENSSL_ALGO_SHA256)) {
        return null;
    }

    $jwt = $toSign . '.' . str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

    if (!function_exists('curl_init')) return null;

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $jwt
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $res = curl_exec($ch);
    $curlErrNo = curl_errno($ch);
    $curlErrMsg = curl_error($ch);
    curl_close($ch);

    // SSL certificate error 60 fallback (common on shared cPanel/Hostinger)
    if ($curlErrNo === 60) {
        error_log("[FCM OAuth2] SSL verification error (errno 60), retrying with SSL_VERIFYPEER disabled...");
        $ch2 = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt($ch2, CURLOPT_POST, true);
        curl_setopt($ch2, CURLOPT_POSTFIELDS, http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt
        ]));
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch2, CURLOPT_TIMEOUT, 6);
        curl_setopt($ch2, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
        $res = curl_exec($ch2);
        curl_close($ch2);
    } else if ($curlErrNo !== 0) {
        error_log("[FCM OAuth2] cURL error ({$curlErrNo}): {$curlErrMsg}");
    }

    if ($res) {
        $data = json_decode($res, true);
        if (!empty($data['access_token'])) {
            $tokenCache = $data['access_token'];
            $tokenExpiry = $now + (int)($data['expires_in'] ?? 3600);
            return $tokenCache;
        } else if (!empty($data['error'])) {
            error_log("[FCM OAuth2] Google Token error: " . ($data['error_description'] ?? $data['error']));
        }
    }

    return null;
}

/**
 * Dispatch Firebase Cloud Messaging (FCM) Push Notifications
 * Uses High Priority and 3-Day TTL (259200s) for offline delivery.
 */
function sendPushNotificationPhp(
    PDO $pdo,
    ?int $userId,
    ?string $phone,
    string $title,
    string $message,
    mixed $data = null,
    string $priority = 'high',
    int $ttlSeconds = 259200
): array {
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
        error_log("[FCM PUSH] ⚠️ No registered device tokens for user #" . ($userId ?? 'guest'));
        return ['ok' => true, 'sent' => 0, 'tokens_count' => 0, 'reason' => 'No registered device tokens'];
    }

    error_log("[FCM PUSH] Dispatching push to " . count($tokens) . " device token(s) for user #" . ($userId ?? 'guest') . " ('{$title}')");

    $sa = getFirebaseServiceAccountPhp();
    if (empty($sa)) {
        error_log("[FCM PUSH] ⚠️ Firebase service account file was not found on server. Checked " . count($GLOBALS['FCM_SERVICE_ACCOUNT_SEARCHED_PATHS'] ?? []) . " locations.");
    }
    $accessToken = $sa ? getFirebaseAccessTokenPhp($sa) : null;
    $projectId = $sa['project_id'] ?? (getenv('FIREBASE_PROJECT_ID') ?: 'vendor-app-18b18');

    // 1. Try FCM v1 HTTP API if Service Account is available
    if ($accessToken && function_exists('curl_init')) {
        $sentCount = 0;
        $invalidTokens = [];

        // Prepare string-only data map for FCM v1
        $stringData = [];
        $rawArray = is_array($data) ? $data : (is_string($data) ? json_decode($data, true) : []);
        if (is_array($rawArray)) {
            foreach ($rawArray as $k => $v) {
                $stringData[(string)$k] = is_scalar($v) ? (string)$v : json_encode($v);
            }
        }

        foreach ($tokens as $token) {
            $fcmV1Payload = [
                'message' => [
                    'token' => $token,
                    'notification' => [
                        'title' => $title,
                        'body' => $message,
                    ],
                    'data' => !empty($stringData) ? $stringData : ['type' => 'general'],
                    'android' => [
                        'priority' => 'high',
                        'ttl' => "{$ttlSeconds}s",
                        'notification' => [
                            'channel_id' => 'seller_orders_channel',
                            'sound' => 'default',
                            'default_sound' => true
                        ]
                    ]
                ]
            ];

            $ch = curl_init("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send");
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fcmV1Payload));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 6);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

            $rawRes = curl_exec($ch);
            $curlErrNo = curl_errno($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            // SSL error 60 retry
            if ($curlErrNo === 60) {
                $ch2 = curl_init("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send");
                curl_setopt($ch2, CURLOPT_POST, true);
                curl_setopt($ch2, CURLOPT_POSTFIELDS, json_encode($fcmV1Payload));
                curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch2, CURLOPT_TIMEOUT, 6);
                curl_setopt($ch2, CURLOPT_HTTPHEADER, [
                    'Authorization: Bearer ' . $accessToken,
                    'Content-Type: application/json'
                ]);
                curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
                $rawRes = curl_exec($ch2);
                $httpCode = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
                curl_close($ch2);
            }

            if ($httpCode === 200) {
                $sentCount++;
                error_log("[FCM PUSH] ✅ Delivered to token " . substr($token, 0, 14) . "... (HTTP 200)");
            } else {
                error_log("[FCM PUSH] ❌ Error (HTTP {$httpCode}) for token " . substr($token, 0, 14) . "... Response: " . substr((string)$rawRes, 0, 180));
                if ($httpCode === 404 || str_contains((string)$rawRes, 'UNREGISTERED') || str_contains((string)$rawRes, 'NOT_FOUND')) {
                    $invalidTokens[] = $token;
                }
            }
        }

        // Prune invalid tokens
        foreach ($invalidTokens as $badToken) {
            error_log("[FCM PUSH] 🧹 Pruning unregistered/invalid token from MySQL: " . substr($badToken, 0, 14) . "...");
            pruneExpiredDeviceTokenPhp($pdo, $badToken);
        }

        return [
            'ok' => true,
            'api' => 'fcm_v1_admin_sdk',
            'sent' => $sentCount,
            'tokens_count' => count($tokens),
            'pruned_tokens' => count($invalidTokens)
        ];
    }

    // 2. Legacy FCM Server Key fallback
    $fcmServerKey = getenv('FCM_SERVER_KEY') ?: ($_ENV['FCM_SERVER_KEY'] ?? '');
    if (!empty($fcmServerKey) && function_exists('curl_init')) {
        $invalidTokens = [];
        $sentCount = 0;
        $dataPayload = is_array($data) ? $data : ['payload' => is_string($data) ? $data : json_encode($data)];

        foreach ($tokens as $token) {
            $body = [
                'to' => $token,
                'priority' => $priority,
                'time_to_live' => $ttlSeconds,
                'notification' => [
                    'title' => $title,
                    'body' => $message,
                    'sound' => 'default',
                    'channel_id' => 'seller_orders_channel'
                ],
                'data' => $dataPayload
            ];

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

        foreach ($invalidTokens as $badToken) {
            pruneExpiredDeviceTokenPhp($pdo, $badToken);
        }

        return ['ok' => true, 'sent' => $sentCount, 'tokens_count' => count($tokens)];
    }

    // 3. Simulated / Logged mode
    return [
        'ok' => true,
        'simulated' => true,
        'sent' => count($tokens),
        'tokens_count' => count($tokens),
        'priority' => $priority,
        'time_to_live' => $ttlSeconds,
        'message' => 'FCM push simulated (Firebase service account or server key not loaded, token stored for delivery)'
    ];
}

/**
 * Core Notification Creator:
 * 1. Checks deduplication key (prevents duplicate notification records on repeat events)
 * 2. Determines delivery status ('delivered' if user online, 'offline' if user offline / app closed)
 * 3. Persists notification record permanently in MySQL
 * 4. Fires non-blocking HTTP dispatch to Node.js Socket.IO realtime layer
 * 5. Dispatches FCM mobile push notification with high priority and 3-day TTL
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
    ?int $orderId = null,
    ?string $dedupKey = null,
    ?string $actionUrl = null
): int {
    ensureNotificationSchemaPhp($pdo);

    // Extract dedup key or action url from data array if not explicitly passed
    if ($dedupKey === null && is_array($data) && !empty($data['dedup_key'])) {
        $dedupKey = (string)$data['dedup_key'];
    }
    if ($actionUrl === null && is_array($data) && !empty($data['action_url'])) {
        $actionUrl = (string)$data['action_url'];
    }
    if ($actionUrl === null && is_array($data) && !empty($data['url'])) {
        $actionUrl = (string)$data['url'];
    }

    // 1. Deduplication check: if dedupKey provided, check if already recorded
    if ($dedupKey !== null && trim($dedupKey) !== '') {
        try {
            $chkDedup = $pdo->prepare("SELECT id FROM `notifications` WHERE dedup_key = :dk LIMIT 1");
            $chkDedup->execute([':dk' => trim($dedupKey)]);
            $exId = $chkDedup->fetchColumn();
            if ($exId) {
                // Existing notification found: return without creating duplicate
                return (int)$exId;
            }
        } catch (Throwable $e) {}
    }

    // 2. Check seller/user presence for delivery_status
    $deliveryStatus = 'delivered';
    $deliveredAt = date('Y-m-d H:i:s');
    if ($userId) {
        try {
            $uStmt = $pdo->prepare("SELECT is_online FROM `users` WHERE id = :uid LIMIT 1");
            $uStmt->execute([':uid' => $userId]);
            $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
            if ($uRow) {
                $isOnline = ((int)($uRow['is_online'] ?? 0) === 1);
                if (!$isOnline) {
                    $deliveryStatus = 'offline';
                    $deliveredAt = null;
                }
            }
        } catch (Throwable $e) {}
    }

    $dataStr = is_string($data) ? $data : ($data !== null ? json_encode($data, JSON_UNESCAPED_UNICODE) : null);
    $notifId = 0;

    // 3. Persist in notifications table
    try {
        $stmt = $pdo->prepare("
            INSERT INTO `notifications` (
                user_id, buyer_phone, recipient_role, order_id, title, message,
                type, data, is_read, delivery_status, delivered_at, dedup_key, action_url
            ) VALUES (
                :uid, :phone, :role, :oid, :title, :msg,
                :type, :data, 0, :status, :deliv_at, :dedup, :action_url
            )
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
            ':status' => $deliveryStatus,
            ':deliv_at' => $deliveredAt,
            ':dedup' => $dedupKey ?: null,
            ':action_url' => $actionUrl ?: null,
        ]);
        $notifId = (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        // Fallback in case table has fewer columns
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

    // Build payload for real-time and push
    $payloadData = is_string($data) ? json_decode($data, true) : (is_array($data) ? $data : []);
    if ($actionUrl && !isset($payloadData['action_url'])) {
        $payloadData['action_url'] = $actionUrl;
    }

    $payload = [
        'id' => $notifId,
        'user_id' => $userId,
        'phone' => $phone,
        'recipient_role' => $recipientRole,
        'order_id' => $orderId,
        'title' => $title,
        'message' => $message,
        'type' => $type,
        'delivery_status' => $deliveryStatus,
        'dedup_key' => $dedupKey,
        'action_url' => $actionUrl,
        'data' => $payloadData,
        'is_read' => false,
        'created_at' => date('Y-m-d H:i:s'),
    ];

    // 4. Trigger Node.js real-time Socket.IO dispatch
    try {
        triggerNodeNotificationPhp($payload);
    } catch (Throwable $e) {}

    // 5. Dispatch Push Notification (FCM) even when seller is offline
    try {
        sendPushNotificationPhp($pdo, $userId, $phone, $title, $message, $payloadData, 'high', 259200);
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
 * Dispatch Seller Product, Account & Payout Event Notifications
 */
function dispatchSellerEventNotificationPhp(PDO $pdo, int $sellerId, string $event, array $extra = []): int
{
    ensureNotificationSchemaPhp($pdo);

    $title = 'Seller Notification';
    $message = 'Important update regarding your seller account.';
    $type = $event;
    $actionUrl = '/seller/dashboard';
    $screen = 'dashboard';
    $dedupKey = null;

    switch ($event) {
        case 'product_stock_low':
            $pName = (string)($extra['product_name'] ?? 'Product');
            $stock = (int)($extra['stock'] ?? 0);
            $pid = (int)($extra['product_id'] ?? 0);
            $title = 'Low Stock Alert ⚠️';
            $message = "Stock for '{$pName}' has fallen to {$stock}. Please restock soon.";
            $actionUrl = "/seller/products/{$pid}";
            $screen = 'product_details';
            $dedupKey = "seller_{$sellerId}_prod_{$pid}_stock_low";
            break;

        case 'product_approved':
        case 'product_status_change':
            $pName = (string)($extra['product_name'] ?? 'Product');
            $status = (string)($extra['status'] ?? 'approved');
            $pid = (int)($extra['product_id'] ?? 0);
            $title = 'Product Status Updated 📦';
            $message = "Your product '{$pName}' status is now {$status}.";
            $actionUrl = "/seller/products/{$pid}";
            $screen = 'product_details';
            $dedupKey = "seller_{$sellerId}_prod_{$pid}_{$status}";
            break;

        case 'account_security_alert':
        case 'account_updated':
            $title = 'Account Security Notice 🔐';
            $message = (string)($extra['message'] ?? 'Your seller account profile details were updated.');
            $actionUrl = '/seller/profile';
            $screen = 'profile';
            $dedupKey = "seller_{$sellerId}_acc_update_" . date('YmdH');
            break;

        case 'payout_processed':
            $amtPaise = (float)($extra['amount'] ?? 0);
            $amtFmt = number_format($amtPaise, 2);
            $title = 'Payout Processed 💰';
            $message = "Seller payout of ₹{$amtFmt} has been initiated to your registered bank account.";
            $actionUrl = '/seller/payouts';
            $screen = 'payouts';
            $payoutId = $extra['payout_id'] ?? date('Ymd');
            $dedupKey = "seller_{$sellerId}_payout_{$payoutId}";
            break;

        default:
            $title = (string)($extra['title'] ?? 'Seller Alert');
            $message = (string)($extra['message'] ?? 'You have a new seller notification.');
            $actionUrl = (string)($extra['action_url'] ?? '/seller/dashboard');
            $screen = (string)($extra['screen'] ?? 'dashboard');
            break;
    }

    $notifData = array_merge($extra, [
        'seller_id' => $sellerId,
        'screen' => $screen,
        'action_url' => $actionUrl,
        'dedup_key' => $dedupKey,
    ]);

    return createNotificationPhp(
        $pdo,
        $sellerId,
        null,
        $title,
        $message,
        $type,
        $notifData,
        'seller',
        !empty($extra['order_id']) ? (int)$extra['order_id'] : null,
        $dedupKey,
        $actionUrl
    );
}

/**
 * Get Seller Synchronization Data:
 * Returns unread notifications, missed orders requiring seller action, and dashboard summary counters.
 * Guarantees a seller never loses access to actions even after days offline.
 */
function getSellerSyncDataPhp(PDO $pdo, int $sellerId): array
{
    ensureNotificationSchemaPhp($pdo);

    // 1. Fetch unread notifications for this seller
    $notifStmt = $pdo->prepare("
        SELECT id, user_id, buyer_phone, recipient_role, order_id, title, message,
               type, data, is_read, delivery_status, read_at, delivered_at, action_url, created_at
        FROM `notifications`
        WHERE (user_id = :sid OR (recipient_role = 'seller' AND (data LIKE :sidMatch OR user_id IS NULL)))
          AND is_read = 0
        ORDER BY id DESC
        LIMIT 50
    ");
    $notifStmt->execute([
        ':sid' => $sellerId,
        ':sidMatch' => '%"seller_id":' . $sellerId . '%',
    ]);
    $unreadNotifs = $notifStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($unreadNotifs as &$n) {
        $n['id'] = (int)$n['id'];
        $n['user_id'] = !empty($n['user_id']) ? (int)$n['user_id'] : null;
        $n['order_id'] = !empty($n['order_id']) ? (int)$n['order_id'] : null;
        $n['is_read'] = (bool)$n['is_read'];
        $decoded = !empty($n['data']) ? (is_array($n['data']) ? $n['data'] : json_decode($n['data'], true)) : [];
        $n['data'] = $decoded;
        $n['screen'] = $decoded['screen'] ?? ($n['order_id'] ? 'seller_order_details' : 'dashboard');
        $n['action_url'] = $n['action_url'] ?: ($decoded['action_url'] ?? ($n['order_id'] ? "/seller/orders/{$n['order_id']}" : "/seller/dashboard"));
    }
    unset($n);

    // 2. Fetch Missed Orders / Actionable Orders for this seller
    // Orders in status: pending, cancellation_requested, confirmed, accepted, processing, return_requested
    $missedStmt = $pdo->prepare("
        SELECT DISTINCT o.id, o.order_number, o.order_status, o.payment_method, o.payment_status,
               o.total_amount, o.buyer_name, o.buyer_phone, o.shipping_address, o.city, o.pincode,
               o.acknowledged_at, o.cancellation_reason, o.created_at, o.updated_at
        FROM `orders` o
        INNER JOIN `order_items` oi ON o.id = oi.order_id
        WHERE oi.seller_id = :sid
          AND o.order_status IN ('pending', 'cancellation_requested', 'confirmed', 'accepted', 'processing', 'return_requested')
        ORDER BY o.id DESC
    ");
    $missedStmt->execute([':sid' => $sellerId]);
    $rawMissedOrders = $missedStmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch this seller's specific items for each missed order
    $missedOrders = [];
    $pendingOrdersCount = 0;
    $cancellationRequestsCount = 0;

    foreach ($rawMissedOrders as $order) {
        $oid = (int)$order['id'];
        $st = strtolower(trim((string)$order['order_status']));

        if ($st === 'pending') {
            $pendingOrdersCount++;
            $pendingAction = 'accept_or_reject';
            $actionLabel = 'Order Pending Acceptance';
            $actionUrl = "/seller/orders/{$oid}";
        } elseif ($st === 'cancellation_requested') {
            $cancellationRequestsCount++;
            $pendingAction = 'review_cancellation';
            $actionLabel = 'Cancellation Review Required';
            $actionUrl = "/seller/orders/{$oid}/cancellation";
        } elseif (in_array($st, ['confirmed', 'accepted', 'processing'], true)) {
            $pendingAction = 'ship_order';
            $actionLabel = 'Awaiting Dispatch & Shipment';
            $actionUrl = "/seller/orders/{$oid}";
        } elseif ($st === 'return_requested') {
            $pendingAction = 'review_return';
            $actionLabel = 'Return Request Pending';
            $actionUrl = "/seller/orders/{$oid}/returns";
        } else {
            $pendingAction = 'view';
            $actionLabel = 'View Order';
            $actionUrl = "/seller/orders/{$oid}";
        }

        // Fetch seller items
        $iStmt = $pdo->prepare("
            SELECT id, product_id, product_name, price, quantity, subtotal
            FROM `order_items`
            WHERE order_id = :oid AND seller_id = :sid
        ");
        $iStmt->execute([':oid' => $oid, ':sid' => $sellerId]);
        $sItems = $iStmt->fetchAll(PDO::FETCH_ASSOC);
        $sSubtotal = 0.0;
        foreach ($sItems as &$it) {
            $it['id'] = (int)$it['id'];
            $it['product_id'] = (int)($it['product_id'] ?? 0);
            $it['price'] = (float)$it['price'];
            $it['quantity'] = (int)$it['quantity'];
            $it['subtotal'] = (float)$it['subtotal'];
            $sSubtotal += $it['subtotal'];
        }
        unset($it);

        $missedOrders[] = [
            'order_id' => $oid,
            'id' => $oid,
            'order_number' => $order['order_number'],
            'order_status' => $st,
            'payment_method' => $order['payment_method'],
            'payment_status' => $order['payment_status'],
            'buyer_name' => $order['buyer_name'],
            'buyer_phone' => $order['buyer_phone'],
            'shipping_address' => $order['shipping_address'],
            'city' => $order['city'],
            'pincode' => $order['pincode'],
            'cancellation_reason' => $order['cancellation_reason'],
            'created_at' => $order['created_at'],
            'acknowledged_at' => $order['acknowledged_at'],
            'is_acknowledged' => !empty($order['acknowledged_at']),
            'pending_action' => $pendingAction,
            'action_label' => $actionLabel,
            'action_url' => $actionUrl,
            'seller_items' => $sItems,
            'seller_subtotal' => $sSubtotal,
            'seller_subtotal_formatted' => '₹' . number_format($sSubtotal, 2),
        ];
    }

    $summary = [
        'seller_id' => $sellerId,
        'missed_orders_count' => count($missedOrders),
        'pending_orders_count' => $pendingOrdersCount,
        'cancellation_requests_count' => $cancellationRequestsCount,
        'pending_actions_count' => count($missedOrders),
        'unread_notifications_count' => count($unreadNotifs),
        'last_sync_at' => date('c'),
    ];

    return [
        'status' => 'success',
        'seller_id' => $sellerId,
        'last_sync_at' => date('c'),
        'summary' => $summary,
        'missed_orders' => $missedOrders,
        'pending_orders' => $missedOrders,
        'unread_notifications' => $unreadNotifs,
        'notifications' => $unreadNotifs,
    ];
}

/**
 * Acknowledge Seller Order
 */
function acknowledgeSellerOrderPhp(PDO $pdo, int $orderId, int $sellerId): bool
{
    ensureNotificationSchemaPhp($pdo);

    // Verify seller is linked to this order
    $chkStmt = $pdo->prepare("
        SELECT 1 FROM `order_items` WHERE order_id = :oid AND seller_id = :sid
        UNION
        SELECT 1 FROM `orders` WHERE id = :oid2 AND seller_id = :sid2
        LIMIT 1
    ");
    $chkStmt->execute([':oid' => $orderId, ':sid' => $sellerId, ':oid2' => $orderId, ':sid2' => $sellerId]);
    if (!$chkStmt->fetch()) {
        return false;
    }

    $upd = $pdo->prepare("UPDATE `orders` SET acknowledged_at = CURRENT_TIMESTAMP WHERE id = :oid");
    return $upd->execute([':oid' => $orderId]);
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
                    ['order_id' => $orderId, 'order_number' => $orderNum, 'total_amount' => (float)$order['total_amount'], 'screen' => 'order_details', 'action_url' => "/orders/{$orderId}"],
                    'buyer',
                    $orderId,
                    "buyer_ord_{$orderId}_created",
                    "/orders/{$orderId}"
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
                            'screen' => 'seller_order_details',
                            'action_url' => "/seller/orders/{$orderId}",
                            'action_required' => 'accept_or_reject',
                        ],
                        'seller',
                        $orderId,
                        "seller_{$sid}_ord_{$orderId}_created",
                        "/seller/orders/{$orderId}"
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
                            ['order_id' => $orderId, 'order_number' => $orderNum, 'total_amount' => (float)$order['total_amount'], 'screen' => 'admin_orders', 'action_url' => "/admin/orders/{$orderId}"],
                            'admin',
                            $orderId,
                            "admin_{$aid}_ord_{$orderId}_created",
                            "/admin/orders/{$orderId}"
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
                        ['order_id' => $orderId, 'order_number' => $orderNum, 'total_amount' => (float)$order['total_amount'], 'screen' => 'admin_orders', 'action_url' => "/admin/orders/{$orderId}"],
                        'admin',
                        $orderId,
                        "admin_ord_{$orderId}_created",
                        "/admin/orders/{$orderId}"
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
                    ['order_id' => $orderId, 'order_number' => $orderNum, 'screen' => 'order_details', 'action_url' => "/orders/{$orderId}"],
                    'buyer',
                    $orderId,
                    "buyer_ord_{$orderId}_confirmed",
                    "/orders/{$orderId}"
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
                    ['order_id' => $orderId, 'order_number' => $orderNum, 'screen' => 'order_details', 'action_url' => "/orders/{$orderId}"],
                    'buyer',
                    $orderId,
                    "buyer_ord_{$orderId}_processing",
                    "/orders/{$orderId}"
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
                        'carrier' => $extra['carrier'] ?? null,
                        'screen' => 'order_tracking',
                        'action_url' => "/orders/{$orderId}",
                    ],
                    'buyer',
                    $orderId,
                    "buyer_ord_{$orderId}_shipped",
                    "/orders/{$orderId}"
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
                    ['order_id' => $orderId, 'order_number' => $orderNum, 'screen' => 'order_details', 'action_url' => "/orders/{$orderId}"],
                    'buyer',
                    $orderId,
                    "buyer_ord_{$orderId}_delivered",
                    "/orders/{$orderId}"
                );
                foreach (array_keys($sellerItemMap) as $sid) {
                    createNotificationPhp(
                        $pdo,
                        $sid,
                        null,
                        'Order Delivered 📬',
                        "Order #{$orderNum} was marked delivered to buyer.",
                        'order_delivered',
                        ['order_id' => $orderId, 'order_number' => $orderNum, 'screen' => 'seller_order_details', 'action_url' => "/seller/orders/{$orderId}"],
                        'seller',
                        $orderId,
                        "seller_{$sid}_ord_{$orderId}_delivered",
                        "/seller/orders/{$orderId}"
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
                    ['order_id' => $orderId, 'order_number' => $orderNum, 'reason' => $extra['reason'] ?? '', 'screen' => 'order_details', 'action_url' => "/orders/{$orderId}"],
                    'buyer',
                    $orderId,
                    "buyer_ord_{$orderId}_cancel_req",
                    "/orders/{$orderId}"
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
                            'screen' => 'cancellation_review',
                            'action_url' => "/seller/orders/{$orderId}/cancellation",
                            'action_required' => 'review_cancellation',
                        ],
                        'seller',
                        $orderId,
                        "seller_{$sid}_ord_{$orderId}_cancel_req",
                        "/seller/orders/{$orderId}/cancellation"
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
                            ['order_id' => $orderId, 'order_number' => $orderNum, 'reason' => $extra['reason'] ?? '', 'screen' => 'admin_cancellations', 'action_url' => "/admin/orders/{$orderId}"],
                            'admin',
                            $orderId,
                            "admin_{$aid}_ord_{$orderId}_cancel_req",
                            "/admin/orders/{$orderId}"
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
                        ['order_id' => $orderId, 'order_number' => $orderNum, 'reason' => $extra['reason'] ?? '', 'screen' => 'admin_cancellations', 'action_url' => "/admin/orders/{$orderId}"],
                        'admin',
                        $orderId,
                        "admin_ord_{$orderId}_cancel_req",
                        "/admin/orders/{$orderId}"
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
                    ['order_id' => $orderId, 'order_number' => $orderNum, 'refunded' => !empty($extra['refunded']), 'screen' => 'order_details', 'action_url' => "/orders/{$orderId}"],
                    'buyer',
                    $orderId,
                    "buyer_ord_{$orderId}_cancelled",
                    "/orders/{$orderId}"
                );

                foreach ($sellerItemMap as $sid => $items) {
                    createNotificationPhp(
                        $pdo,
                        $sid,
                        null,
                        'Order Cancelled ❌',
                        "Order #{$orderNum} was approved for cancellation.",
                        'order_cancelled',
                        ['order_id' => $orderId, 'order_number' => $orderNum, 'seller_id' => $sid, 'screen' => 'seller_order_details', 'action_url' => "/seller/orders/{$orderId}"],
                        'seller',
                        $orderId,
                        "seller_{$sid}_ord_{$orderId}_cancelled",
                        "/seller/orders/{$orderId}"
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
                    ['order_id' => $orderId, 'order_number' => $orderNum, 'reason' => $extra['reason'] ?? '', 'screen' => 'order_details', 'action_url' => "/orders/{$orderId}"],
                    'buyer',
                    $orderId,
                    "buyer_ord_{$orderId}_cancel_rej",
                    "/orders/{$orderId}"
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
                        'amount' => $extra['amount'] ?? null,
                        'screen' => 'order_details',
                        'action_url' => "/orders/{$orderId}",
                    ],
                    'buyer',
                    $orderId,
                    "buyer_ord_{$orderId}_pay_succ",
                    "/orders/{$orderId}"
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
                        [
                            'order_id' => $orderId,
                            'order_number' => $orderNum,
                            'seller_id' => $sid,
                            'seller_subtotal' => $sSubtotal,
                            'screen' => 'seller_order_details',
                            'action_url' => "/seller/orders/{$orderId}",
                        ],
                        'seller',
                        $orderId,
                        "seller_{$sid}_ord_{$orderId}_pay_succ",
                        "/seller/orders/{$orderId}"
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
                    ['order_id' => $orderId, 'order_number' => $orderNum, 'reason' => $extra['error_description'] ?? '', 'screen' => 'order_details', 'action_url' => "/orders/{$orderId}"],
                    'buyer',
                    $orderId,
                    "buyer_ord_{$orderId}_pay_fail",
                    "/orders/{$orderId}"
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
                        ['order_id' => $orderId, 'order_number' => $orderNum, 'reason' => $extra['error_description'] ?? '', 'screen' => 'admin_orders', 'action_url' => "/admin/orders/{$orderId}"],
                        'admin',
                        $orderId,
                        "admin_{$aid}_ord_{$orderId}_pay_fail",
                        "/admin/orders/{$orderId}"
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
                    ['order_id' => $orderId, 'order_number' => $orderNum, 'amount' => $extra['amount'] ?? null, 'screen' => 'order_details', 'action_url' => "/orders/{$orderId}"],
                    'buyer',
                    $orderId,
                    "buyer_ord_{$orderId}_refund_proc",
                    "/orders/{$orderId}"
                );
                foreach (array_keys($sellerItemMap) as $sid) {
                    createNotificationPhp(
                        $pdo,
                        $sid,
                        null,
                        'Refund Logged',
                        "Refund of {$amt} logged for order #{$orderNum}.",
                        'refund_processed',
                        ['order_id' => $orderId, 'order_number' => $orderNum, 'screen' => 'seller_order_details', 'action_url' => "/seller/orders/{$orderId}"],
                        'seller',
                        $orderId,
                        "seller_{$sid}_ord_{$orderId}_refund_proc",
                        "/seller/orders/{$orderId}"
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
                        ['order_id' => $orderId, 'order_number' => $orderNum, 'reason' => $extra['reason'] ?? '', 'screen' => 'admin_orders', 'action_url' => "/admin/orders/{$orderId}"],
                        'admin',
                        $orderId,
                        "admin_{$aid}_ord_{$orderId}_refund_fail",
                        "/admin/orders/{$orderId}"
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
                    ['order_id' => $orderId, 'order_number' => $orderNum, 'reason' => $extra['reason'] ?? '', 'screen' => 'order_details', 'action_url' => "/orders/{$orderId}"],
                    'buyer',
                    $orderId,
                    "buyer_ord_{$orderId}_rejected",
                    "/orders/{$orderId}"
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
                    ['order_id' => $orderId, 'order_number' => $orderNum, 'screen' => 'order_details', 'action_url' => "/orders/{$orderId}"],
                    'buyer',
                    $orderId,
                    "buyer_ord_{$orderId}_ret_req",
                    "/orders/{$orderId}"
                );
                foreach ($sellerItemMap as $sid => $items) {
                    createNotificationPhp(
                        $pdo,
                        $sid,
                        null,
                        'Return Request Received ⚠️',
                        "Return requested for order #{$orderNum}{$reason}",
                        'return_requested',
                        ['order_id' => $orderId, 'order_number' => $orderNum, 'seller_id' => $sid, 'items' => $items, 'screen' => 'seller_order_details', 'action_url' => "/seller/orders/{$orderId}"],
                        'seller',
                        $orderId,
                        "seller_{$sid}_ord_{$orderId}_ret_req",
                        "/seller/orders/{$orderId}"
                    );
                }
                break;
        }
    } catch (Throwable $e) {}
}
