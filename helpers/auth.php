<?php

/**
 * Authentication & Token Helpers (Pure PHP Bearer Token & JWT System)
 * Full cross-engine compatibility with Node.js Express & Hostinger Production.
 */

require_once __DIR__ . '/response.php';

function getJwtSecret(): string
{
    static $secret = null;
    if ($secret !== null) {
        return $secret;
    }

    $envFile = __DIR__ . '/../.env';
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'JWT_SECRET=')) {
                $secret = trim(substr($line, 11), '"\' ');
                break;
            }
        }
    }

    if (empty($secret)) {
        $secret = getenv('JWT_SECRET') ?: 'vendor_jwt_secret_production_knowlipop_2026';
    }

    return $secret;
}

function getBearerToken(): ?string
{
    $authHeader = null;
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    } elseif (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        if (isset($headers['Authorization'])) {
            $authHeader = $headers['Authorization'];
        } elseif (isset($headers['authorization'])) {
            $authHeader = $headers['authorization'];
        }
    }

    if ($authHeader && preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
        return $matches[1];
    } elseif ($authHeader && !preg_match('/\s/', trim($authHeader))) {
        return trim($authHeader);
    } elseif (!empty($_GET['token'])) {
        return trim($_GET['token']);
    } elseif (!empty($_COOKIE['admin_token'])) {
        return trim($_COOKIE['admin_token']);
    } elseif (!empty($_COOKIE['token'])) {
        return trim($_COOKIE['token']);
    }

    return null;
}

function verifyJwtToken(string $token, string $secret): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }
    [$b64Header, $b64Payload, $b64Signature] = $parts;

    $sig = hash_hmac('sha256', "{$b64Header}.{$b64Payload}", $secret, true);
    $expectedSig = rtrim(strtr(base64_encode($sig), '+/', '-_'), '=');

    if (!hash_equals($expectedSig, $b64Signature)) {
        return null;
    }

    $payloadJson = base64_decode(strtr($b64Payload, '-_', '+/'));
    $payload = json_decode($payloadJson, true);
    if (!is_array($payload)) {
        return null;
    }

    if (isset($payload['exp']) && time() >= $payload['exp']) {
        return null;
    }

    return $payload;
}

function createToken(PDO $pdo, int $userId, ?string $phone = null, string $name = 'vendor-app', ?string $email = null, string $role = 'seller', ?int $tokenVersion = null): string
{
    $secret = getJwtSecret();
    $cleanPhone = ($phone !== null && trim($phone) !== '') ? trim($phone) : null;
    $cleanEmail = ($email !== null && trim($email) !== '' && !str_ends_with(strtolower(trim($email)), '@phone.vendor.local'))
        ? strtolower(trim($email))
        : null;

    $tv = $tokenVersion;
    if ($tv === null) {
        $tv = 1;
        try {
            $tvStmt = $pdo->prepare("SELECT token_version FROM users WHERE id = :id LIMIT 1");
            $tvStmt->execute([':id' => $userId]);
            $rowTv = $tvStmt->fetchColumn();
            if ($rowTv !== false && $rowTv !== null) {
                $tv = max(1, (int) $rowTv);
            }
        } catch (Throwable $e) {
            $tv = 1;
        }
    }

    // Standard JWT token matching Node.js
    $header = rtrim(strtr(base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'])), '+/', '-_'), '=');
    $payload = rtrim(strtr(base64_encode(json_encode([
        'id' => $userId,
        'email' => $cleanEmail,
        'phone' => $cleanPhone,
        'role' => $role ?: 'seller',
        'tv' => $tv,
        'iat' => time(),
        'exp' => time() + (30 * 86400)
    ])), '+/', '-_'), '=');
    $sig = rtrim(strtr(base64_encode(hash_hmac('sha256', "{$header}.{$payload}", $secret, true)), '+/', '-_'), '=');
    $jwtToken = "{$header}.{$payload}.{$sig}";

    // Also record in personal_access_tokens for backward compatibility
    try {
        $tokenHash = hash('sha256', $jwtToken);
        $stmt = $pdo->prepare("
            INSERT INTO personal_access_tokens (tokenable_type, tokenable_id, name, token, abilities, created_at, updated_at)
            VALUES ('App\\\\Models\\\\User', :user_id, :name, :token, '[\"*\"]', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':name' => $name,
            ':token' => $tokenHash,
        ]);
    } catch (Throwable $e) {
        // Silently continue if table not present
    }

    return $jwtToken;
}

function getAuthenticatedUserOptional(PDO $pdo): ?array
{
    $token = getBearerToken();
    if (!$token) {
        return null;
    }

    // 1. Verify standard JWT token (shared with Node.js)
    $jwtPayload = verifyJwtToken($token, getJwtSecret());
    if ($jwtPayload && isset($jwtPayload['id'])) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $jwtPayload['id']]);
            $user = $stmt->fetch();
            if ($user) {
                $dbTv = isset($user['token_version']) ? max(1, (int) $user['token_version']) : 1;
                $tokTv = isset($jwtPayload['tv']) ? max(1, (int) $jwtPayload['tv']) : 1;
                if ($tokTv !== $dbTv) {
                    return null;
                }
                $user['token_id'] = 0;
                return $user;
            }
            // If user row does not exist in DB, reject token
            return null;
        } catch (Throwable $e) {
            return [
                'id' => (int)$jwtPayload['id'],
                'role' => $jwtPayload['role'] ?? ((int)$jwtPayload['id'] === 1 ? 'admin' : 'seller'),
                'email' => $jwtPayload['email'] ?? null,
                'phone' => $jwtPayload['phone'] ?? null,
                'name' => $jwtPayload['name'] ?? 'Verified Seller',
                'seller_name' => $jwtPayload['seller_name'] ?? 'Verified Seller',
                'token_version' => isset($jwtPayload['tv']) ? (int) $jwtPayload['tv'] : 1,
                'account_status' => 'ACTIVE',
                'token_id' => 0
            ];
        }
    }

    // 2. Legacy Sanctum personal_access_tokens (id|plainText)
    $parts = explode('|', $token, 2);
    if (count($parts) === 2) {
        [$id, $plain] = $parts;
        $hash = hash('sha256', $plain);

        try {
            $stmt = $pdo->prepare("
                SELECT pat.id as token_id, pat.tokenable_id as user_id, u.*
                FROM personal_access_tokens pat
                JOIN users u ON pat.tokenable_id = u.id
                WHERE pat.id = :id AND pat.token = :hash
                LIMIT 1
            ");
            $stmt->execute([':id' => $id, ':hash' => $hash]);
            $user = $stmt->fetch();

            if ($user) {
                try {
                    $updateStmt = $pdo->prepare("UPDATE personal_access_tokens SET last_used_at = CURRENT_TIMESTAMP WHERE id = :id");
                    $updateStmt->execute([':id' => $id]);
                } catch (Throwable $e) {}
                return $user;
            }
        } catch (Throwable $e) {}
    }

    return null;
}

function authenticateUser(PDO $pdo): array
{
    $user = getAuthenticatedUserOptional($pdo);
    if (!$user) {
        errorResponse('Unauthenticated.', [], 401);
    }
    $accStatus = strtoupper((string) ($user['account_status'] ?? 'ACTIVE'));
    if (($user['role'] ?? 'seller') !== 'admin' && in_array($accStatus, ['LOCKED', 'DELETED'], true)) {
        $msg = $accStatus === 'DELETED'
            ? 'Seller account has been permanently deleted and access is revoked.'
            : 'Seller account is locked due to an active deletion workflow.';
        errorResponse($msg, ['account_status' => [$accStatus]], 403);
    }
    return $user;
}

function requireActiveSellerForMutationPhp(array $user): void
{
    if (($user['role'] ?? 'seller') === 'admin') {
        return;
    }
    $accStatus = strtoupper((string) ($user['account_status'] ?? 'ACTIVE'));
    if (in_array($accStatus, ['DELETION_PENDING', 'LOCKED', 'DELETED'], true)) {
        errorResponse(
            "Action blocked: seller account status is {$accStatus}.",
            ['account_status' => [$accStatus]],
            403
        );
    }
}

function deleteCurrentToken(PDO $pdo, int $tokenId): void
{
    if ($tokenId > 0) {
        $stmt = $pdo->prepare("DELETE FROM personal_access_tokens WHERE id = :id");
        $stmt->execute([':id' => $tokenId]);
    }
}

function getPublicSellerEmailPhp(?string $rawEmail, string $role = 'seller'): ?string
{
    if ($rawEmail === null) return null;
    $trimmed = trim($rawEmail);
    if ($trimmed === '') return null;
    if (str_ends_with(strtolower($trimmed), '@phone.vendor.local') && $role !== 'admin') {
        return null;
    }
    return $trimmed;
}

function safeSeller(array $user): array
{
    $role = $user['role'] ?? 'seller';
    $email = getPublicSellerEmailPhp($user['email'] ?? null, $role);
    $rawPhone = isset($user['phone']) ? trim((string)$user['phone']) : '';

    $data = [
        'id' => (int) $user['id'],
        'seller_name' => $user['seller_name'] ?? $user['name'] ?? '',
        'email' => $email,
        'phone' => $rawPhone !== '' ? $rawPhone : null,
        'address' => $user['address'] ?? null,
        'landmark' => $user['landmark'] ?? null,
        'state' => $user['state'] ?? null,
        'country' => $user['country'] ?? null,
        'avatar_url' => $user['avatar_url'] ?? null,
        'account_status' => $user['account_status'] ?? 'ACTIVE',
    ];

    if (!empty($user['role'])) {
        $data['role'] = $user['role'];
    }

    return $data;
}

function hashPassword(string $password): string
{
    return password_hash($password, PASSWORD_BCRYPT);
}

function verifyPassword(string $password, string $hash): bool
{
    return password_verify($password, $hash);
}

function getEnvVar(string $key, string $default = ''): string
{
    static $env = null;
    if ($env === null) {
        $env = [];
        $envFile = __DIR__ . '/../.env';
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
    }
    $runtimeEnv = getenv($key);
    if ($runtimeEnv !== false && trim((string)$runtimeEnv) !== '') {
        return trim((string)$runtimeEnv);
    }
    if (isset($env[$key]) && $env[$key] !== '') {
        return $env[$key];
    }
    return $default;
}

function getFirebaseTestPhone(): string
{
    return getEnvVar('FIREBASE_TEST_PHONE', '+919633426254');
}

function getFirebaseTestEmail(): string
{
    return getEnvVar('FIREBASE_TEST_EMAIL', '');
}

function getFirebaseTestOtp(): string
{
    return getEnvVar('FIREBASE_TEST_OTP', '000000');
}

function isFirebaseTestPhone(string $phone): bool
{
    $normInput = normalizePhoneNumberPhp($phone);
    $normTest = normalizePhoneNumberPhp(getFirebaseTestPhone());
    return $normInput !== '' && $normTest !== '' && $normInput === $normTest;
}

function isFirebaseTestEmail(string $email): bool
{
    $rawList = trim(getFirebaseTestEmail());
    if ($rawList === '' || trim($email) === '') {
        return false;
    }
    $target = strtolower(trim($email));
    $items = array_filter(array_map(fn($v) => strtolower(trim($v)), explode(',', $rawList)));
    return in_array($target, $items, true);
}

function firebaseHttpJsonPostPhp(string $url, array $payload): array
{
    $body = json_encode($payload);
    $statusCode = 0;
    $raw = '';
    $err = '';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $res = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($res === false) {
            $err = curl_error($ch);
        } else {
            $raw = (string) $res;
        }
        curl_close($ch);
    } else {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nContent-Length: " . strlen((string)$body) . "\r\n",
                'content' => $body,
                'timeout' => 15,
                'ignore_errors' => true
            ]
        ]);
        $res = @file_get_contents($url, false, $ctx);
        $raw = $res !== false ? (string) $res : '';
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $statusCode = (int) $m[1];
        }
    }

    $decoded = $raw !== '' ? json_decode($raw, true) : null;
    return [
        'ok' => $statusCode >= 200 && $statusCode < 300,
        'statusCode' => $statusCode,
        'data' => is_array($decoded) ? $decoded : null,
        'raw' => $raw,
        'error' => $err
    ];
}

function validatePhoneFormatPhp(string $normalizedPhone, string $rawPhone = ''): ?string
{
    if ($rawPhone !== '') {
        $stripped = preg_replace('/[\s\-\(\)\.]+/u', '', trim($rawPhone));
        if ($stripped === '' || $stripped === null || !preg_match('/^\+?\d+$/', $stripped)) {
            return 'The phone number contains invalid characters. Enter digits only with optional country code (e.g. +919876543210).';
        }
    }

    if ($normalizedPhone === '') {
        return $rawPhone !== ''
            ? 'The phone format is invalid (must include country code, e.g. +919876543210).'
            : null;
    }

    // Country-specific phone number length & prefix validation
    if (str_starts_with($normalizedPhone, '+91')) {
        if (!preg_match('/^\+91[6-9]\d{9}$/', $normalizedPhone)) {
            return 'The phone format is invalid for India (+91 followed by 10 digits starting with 6-9, e.g. +919876543210).';
        }
        return null;
    }
    if (str_starts_with($normalizedPhone, '+1')) {
        if (!preg_match('/^\+1[2-9]\d{9}$/', $normalizedPhone)) {
            return 'The phone format is invalid for US/Canada (+1 followed by 10 digits, e.g. +12025550143).';
        }
        return null;
    }
    if (str_starts_with($normalizedPhone, '+44')) {
        if (!preg_match('/^\+44[1-9]\d{8,9}$/', $normalizedPhone)) {
            return 'The phone format is invalid for UK (+44 followed by 9-10 digits).';
        }
        return null;
    }
    if (str_starts_with($normalizedPhone, '+971')) {
        if (!preg_match('/^\+971[2-9]\d{7,8}$/', $normalizedPhone)) {
            return 'The phone format is invalid for UAE (+971 followed by 8-9 digits).';
        }
        return null;
    }
    if (str_starts_with($normalizedPhone, '+966')) {
        if (!preg_match('/^\+966[1-9]\d{8}$/', $normalizedPhone)) {
            return 'The phone format is invalid for Saudi Arabia (+966 followed by 9 digits).';
        }
        return null;
    }
    if (str_starts_with($normalizedPhone, '+65')) {
        if (!preg_match('/^\+65[689]\d{7}$/', $normalizedPhone)) {
            return 'The phone format is invalid for Singapore (+65 followed by 8 digits).';
        }
        return null;
    }
    if (str_starts_with($normalizedPhone, '+61')) {
        if (!preg_match('/^\+61[2-478]\d{8}$/', $normalizedPhone)) {
            return 'The phone format is invalid for Australia (+61 followed by 9 digits).';
        }
        return null;
    }
    if (str_starts_with($normalizedPhone, '+49')) {
        if (!preg_match('/^\+49[1-9]\d{6,11}$/', $normalizedPhone)) {
            return 'The phone format is invalid for Germany (+49 followed by 7-12 digits).';
        }
        return null;
    }
    if (str_starts_with($normalizedPhone, '+60')) {
        if (!preg_match('/^\+60[1-9]\d{7,9}$/', $normalizedPhone)) {
            return 'The phone format is invalid for Malaysia (+60 followed by 8-10 digits).';
        }
        return null;
    }
    if (str_starts_with($normalizedPhone, '+974')) {
        if (!preg_match('/^\+974[3-7]\d{7}$/', $normalizedPhone)) {
            return 'The phone format is invalid for Qatar (+974 followed by 8 digits).';
        }
        return null;
    }
    if (str_starts_with($normalizedPhone, '+968')) {
        if (!preg_match('/^\+968[279]\d{7}$/', $normalizedPhone)) {
            return 'The phone format is invalid for Oman (+968 followed by 8 digits).';
        }
        return null;
    }
    if (str_starts_with($normalizedPhone, '+965')) {
        if (!preg_match('/^\+965[2569]\d{7}$/', $normalizedPhone)) {
            return 'The phone format is invalid for Kuwait (+965 followed by 8 digits).';
        }
        return null;
    }
    if (str_starts_with($normalizedPhone, '+973')) {
        if (!preg_match('/^\+973[13678]\d{7}$/', $normalizedPhone)) {
            return 'The phone format is invalid for Bahrain (+973 followed by 8 digits).';
        }
        return null;
    }

    if (!preg_match('/^\+[1-9]\d{7,14}$/', $normalizedPhone)) {
        return 'The phone format is invalid (must include country code, e.g. +919876543210).';
    }

    return null;
}

function normalizePhoneNumberPhp(string $raw, string $dialCode = ''): string
{
    $trimmed = trim($raw);
    if ($trimmed === '') {
        return '';
    }

    $cleaned = preg_replace('/[\s\-\(\)\.]+/u', '', $trimmed);
    if ($cleaned === '' || $cleaned === null || !preg_match('/^\+?\d+$/', $cleaned)) {
        return '';
    }

    // International IDD prefix 00 -> +
    if (str_starts_with($cleaned, '00') && strlen($cleaned) > 4) {
        $cleaned = '+' . substr($cleaned, 2);
    }

    // Clean explicit country dial code if provided separately
    $cleanDial = '';
    $rawDial = preg_replace('/[\s\-\(\)]+/', '', trim($dialCode));
    if ($rawDial !== '' && $rawDial !== null) {
        if (str_starts_with($rawDial, '00')) {
            $rawDial = '+' . substr($rawDial, 2);
        } elseif (!str_starts_with($rawDial, '+') && preg_match('/^\d{1,4}$/', $rawDial)) {
            $rawDial = '+' . $rawDial;
        }
        if (preg_match('/^\+[1-9]\d{0,3}$/', $rawDial)) {
            $cleanDial = $rawDial;
        }
    }

    // If raw phone already starts with +, preserve its country code carefully
    if (str_starts_with($cleaned, '+')) {
        // Normalize Indian +910XXXXXXXXXX trunk zero typo
        if (preg_match('/^\+910([6-9]\d{9})$/', $cleaned, $m)) {
            return '+91' . $m[1];
        }
        return $cleaned;
    }

    // Separate country dial code supplied (e.g. country picker + local input)
    if ($cleanDial !== '') {
        $dialDigits = substr($cleanDial, 1);
        if ($dialDigits === '91') {
            if (preg_match('/^91\d{10}$/', $cleaned)) {
                return '+' . $cleaned;
            }
            if (preg_match('/^0(\d{10})$/', $cleaned, $m)) {
                return '+91' . $m[1];
            }
            return '+91' . $cleaned;
        }

        // International country code selected explicitly
        if (preg_match('/^0(\d{5,14})$/', $cleaned, $m)) {
            $cleaned = $m[1];
        }
        if (str_starts_with($cleaned, $dialDigits)) {
            $candAsFull = '+' . $cleaned;
            $candPrefixed = '+' . $dialDigits . $cleaned;
            if (validatePhoneFormatPhp($candAsFull) === null && validatePhoneFormatPhp($candPrefixed) !== null) {
                return $candAsFull;
            }
        }
        return '+' . $dialDigits . $cleaned;
    }

    // No explicit dial code and no leading +
    if (preg_match('/^\d{10}$/', $cleaned)) {
        return '+91' . $cleaned;
    }
    if (preg_match('/^0(\d{10})$/', $cleaned, $m)) {
        return '+91' . $m[1];
    }
    if (preg_match('/^91\d{10}$/', $cleaned)) {
        return '+' . $cleaned;
    }

    return '+' . $cleaned;
}

function normalizePhoneSimplePhp(string $raw, string $dialCode = ''): string
{
    return normalizePhoneNumberPhp($raw, $dialCode);
}

function getPhoneLookupVariantsPhp(string $rawPhone, string $normalizedPhone = '', string $dialCode = ''): array
{
    $norm = $normalizedPhone !== '' ? $normalizedPhone : normalizePhoneNumberPhp($rawPhone, $dialCode);
    $variants = [];

    if ($norm !== '') {
        $variants[] = $norm;
        if (preg_match('/^\+91(\d{10})$/', $norm, $m)) {
            $ten = $m[1];
            $variants[] = '+91' . $ten;
            $variants[] = '91' . $ten;
            $variants[] = $ten;
            $variants[] = '0' . $ten;
            $variants[] = '0091' . $ten;
            $variants[] = '+910' . $ten;
        } elseif (preg_match('/^\+(\d{7,15})$/', $norm, $m)) {
            $digits = $m[1];
            $variants[] = $digits;
            $variants[] = '00' . $digits;
        }
    }

    $trimmedRaw = trim($rawPhone);
    if ($trimmedRaw !== '') {
        $cleanedRaw = preg_replace('/[\s\-\(\)\.]+/u', '', $trimmedRaw);
        if ($cleanedRaw !== '' && $cleanedRaw !== null && preg_match('/^\+?\d{8,15}$/', $cleanedRaw)) {
            $variants[] = $cleanedRaw;
        }
    }

    return array_values(array_unique(array_filter($variants, fn($v) => $v !== '')));
}

function authUsersHasColumnPhp(PDO $pdo, string $column): bool
{
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    try {
        if ($driver === 'sqlite') {
            $stmt = $pdo->query('PRAGMA table_info("users")');
            $cols = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            foreach ($cols as $col) {
                if (strtolower((string) ($col['name'] ?? '')) === strtolower($column)) {
                    return true;
                }
            }
            return false;
        }
        $stmt = $pdo->prepare('SHOW COLUMNS FROM `users` LIKE :col');
        $stmt->execute([':col' => $column]);
        return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

function authUsersHasUniqueNormalizedPhoneIndexPhp(PDO $pdo): bool
{
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    try {
        if ($driver === 'sqlite') {
            $idxStmt = $pdo->query('PRAGMA index_list("users")');
            $indexes = $idxStmt ? $idxStmt->fetchAll(PDO::FETCH_ASSOC) : [];
            foreach ($indexes as $idx) {
                if ((int) ($idx['unique'] ?? 0) !== 1) {
                    continue;
                }
                $idxName = (string) ($idx['name'] ?? '');
                if ($idxName === '') {
                    continue;
                }
                $infoStmt = $pdo->query('PRAGMA index_info("' . str_replace('"', '""', $idxName) . '")');
                $cols = $infoStmt ? $infoStmt->fetchAll(PDO::FETCH_ASSOC) : [];
                if (count($cols) === 1 && strtolower((string) ($cols[0]['name'] ?? '')) === 'normalized_phone') {
                    return true;
                }
            }
            return false;
        }
        $stmt = $pdo->query("SHOW INDEX FROM `users` WHERE Column_name = 'normalized_phone' AND Non_unique = 0");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($rows as $row) {
            if ((int) ($row['Seq_in_index'] ?? 0) === 1) {
                return true;
            }
        }
        return false;
    } catch (Throwable $e) {
        return false;
    }
}

function ensureNormalizedPhoneSchemaPhp(PDO $pdo, bool $force = false): array
{
    static $ensured = [];
    $oid = spl_object_id($pdo);
    if (!$force && !empty($ensured[$oid])) {
        return ['skipped' => true];
    }

    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    $isSqlite = ($driver === 'sqlite');
    $report = [
        'column_added' => false,
        'index_added' => false,
        'backfilled_rows' => 0,
        'duplicate_groups' => [],
    ];

    try {
        if (!authUsersHasColumnPhp($pdo, 'normalized_phone')) {
            if ($isSqlite) {
                $pdo->exec('ALTER TABLE users ADD COLUMN normalized_phone TEXT NULL');
            } else {
                $pdo->exec('ALTER TABLE `users` ADD COLUMN `normalized_phone` VARCHAR(30) NULL DEFAULT NULL AFTER `phone`');
            }
            $report['column_added'] = true;
        }
    } catch (Throwable $e) {
        if (!$force && str_contains(strtolower($e->getMessage()), 'duplicate column')) {
            // Concurrent schema init already added the column
        }
    }

    $hasUniqueIdx = authUsersHasUniqueNormalizedPhoneIndexPhp($pdo);
    $hasStatusCol = authUsersHasColumnPhp($pdo, 'account_status');
    $statusSelect = $hasStatusCol ? "COALESCE(account_status, 'ACTIVE') AS account_status" : "'ACTIVE' AS account_status";
    if ($force || !$hasUniqueIdx || $report['column_added']) {
        try {
            $rows = $pdo->query("SELECT id, phone, normalized_phone, role, {$statusSelect} FROM users WHERE phone IS NOT NULL AND TRIM(phone) != '' ORDER BY id ASC")
                ->fetchAll(PDO::FETCH_ASSOC);

            $groups = [];
            foreach ($rows as $row) {
                $norm = normalizePhoneNumberPhp((string) ($row['phone'] ?? ''));
                if ($norm === '') {
                    continue;
                }
                if (!isset($groups[$norm])) {
                    $groups[$norm] = [];
                }
                $row['_computed_norm'] = $norm;
                $groups[$norm][] = $row;
            }

            foreach ($groups as $norm => $members) {
                usort($members, function (array $a, array $b) use ($norm): int {
                    $aBlocked = in_array(strtoupper((string) ($a['account_status'] ?? 'ACTIVE')), ['LOCKED', 'DELETED'], true) ? 1 : 0;
                    $bBlocked = in_array(strtoupper((string) ($b['account_status'] ?? 'ACTIVE')), ['LOCKED', 'DELETED'], true) ? 1 : 0;
                    if ($aBlocked !== $bBlocked) {
                        return $aBlocked <=> $bBlocked;
                    }
                    $aHeld = ((string) ($a['normalized_phone'] ?? '') === $norm) ? 0 : 1;
                    $bHeld = ((string) ($b['normalized_phone'] ?? '') === $norm) ? 0 : 1;
                    if ($aHeld !== $bHeld) {
                        return $aHeld <=> $bHeld;
                    }
                    return ((int) $a['id']) <=> ((int) $b['id']);
                });

                $canonical = $members[0];
                if ((string) ($canonical['normalized_phone'] ?? '') !== $norm) {
                    // Clear any conflicting row holding $norm before assigning canonical row
                    $pdo->prepare('UPDATE users SET normalized_phone = NULL WHERE normalized_phone = :norm AND id != :cid')
                        ->execute([':norm' => $norm, ':cid' => (int) $canonical['id']]);
                    $pdo->prepare('UPDATE users SET normalized_phone = :norm WHERE id = :id')
                        ->execute([':norm' => $norm, ':id' => (int) $canonical['id']]);
                    $report['backfilled_rows']++;
                }

                if (count($members) > 1) {
                    $dupIds = array_map(fn($m) => (int) $m['id'], array_slice($members, 1));
                    $report['duplicate_groups'][] = [
                        'normalized_phone' => $norm,
                        'canonical_user_id' => (int) $canonical['id'],
                        'duplicate_user_ids' => $dupIds,
                    ];
                    foreach (array_slice($members, 1) as $dupMember) {
                        if ($dupMember['normalized_phone'] !== null && $dupMember['normalized_phone'] !== '') {
                            $pdo->prepare('UPDATE users SET normalized_phone = NULL WHERE id = :id')
                                ->execute([':id' => (int) $dupMember['id']]);
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            // Preserve safely without interrupting runtime requests
        }

        if (!$hasUniqueIdx) {
            try {
                if ($isSqlite) {
                    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uniq_users_normalized_phone ON users (normalized_phone)');
                } else {
                    $pdo->exec('ALTER TABLE `users` ADD UNIQUE INDEX `uniq_users_normalized_phone` (`normalized_phone`)');
                }
                $report['index_added'] = true;
            } catch (Throwable $e) {
                if (!str_contains(strtolower($e->getMessage()), 'already exists') && !str_contains(strtolower($e->getMessage()), 'duplicate key name')) {
                    // Surface only if called via forced migration; runtime lookup still protects via transaction + lock
                }
            }
        }
    }

    if (authUsersHasColumnPhp($pdo, 'normalized_phone')) {
        $ensured[$oid] = true;
    }

    return $report;
}

function findUsersByPhonePhp(PDO $pdo, string $rawPhone, string $normalizedPhone = '', string $dialCode = '', ?int $excludeUserId = null): array
{
    $norm = $normalizedPhone !== '' ? $normalizedPhone : normalizePhoneNumberPhp($rawPhone, $dialCode);
    $variants = getPhoneLookupVariantsPhp($rawPhone, $norm, $dialCode);
    if (empty($variants) && $norm === '') {
        return [];
    }

    $hasNormCol = authUsersHasColumnPhp($pdo, 'normalized_phone');
    $clauses = [];
    $params = [];

    if ($hasNormCol && $norm !== '') {
        $clauses[] = 'normalized_phone = :norm_phone';
        $params[':norm_phone'] = $norm;
    }

    if (!empty($variants)) {
        $ph1 = [];
        $ph2 = [];
        foreach ($variants as $idx => $val) {
            $k1 = ':vp' . $idx;
            $k2 = ':vr' . $idx;
            $ph1[] = $k1;
            $ph2[] = $k2;
            $params[$k1] = $val;
            $params[$k2] = $val;
        }
        $clauses[] = 'phone IN (' . implode(', ', $ph1) . ')';
        $clauses[] = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(phone, ''), ' ', ''), '-', ''), '(', ''), ')', ''), '.', '') IN (" . implode(', ', $ph2) . ')';
    }

    if (empty($clauses)) {
        return [];
    }

    $sql = 'SELECT * FROM users WHERE (' . implode(' OR ', $clauses) . ')';
    if ($excludeUserId !== null && $excludeUserId > 0) {
        $sql .= ' AND id != :exclude_user_id';
        $params[':exclude_user_id'] = $excludeUserId;
    }
    $sql .= ' ORDER BY id ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Filter out any accidental non-exact country collisions (e.g. compare computed normalized E.164 strictly)
    if ($norm !== '') {
        $filtered = [];
        foreach ($rows as $row) {
            $rowNorm = normalizePhoneNumberPhp((string) ($row['phone'] ?? ''), '');
            $colNorm = trim((string) ($row['normalized_phone'] ?? ''));
            if ($colNorm === $norm || $rowNorm === $norm) {
                $filtered[] = $row;
            }
        }
        $rows = $filtered;
    }

    usort($rows, function (array $a, array $b) use ($norm): int {
        $aBlocked = in_array(strtoupper((string) ($a['account_status'] ?? 'ACTIVE')), ['LOCKED', 'DELETED'], true) ? 1 : 0;
        $bBlocked = in_array(strtoupper((string) ($b['account_status'] ?? 'ACTIVE')), ['LOCKED', 'DELETED'], true) ? 1 : 0;
        if ($aBlocked !== $bBlocked) {
            return $aBlocked <=> $bBlocked;
        }
        $aNormMatch = ($norm !== '' && (string) ($a['normalized_phone'] ?? '') === $norm) ? 0 : 1;
        $bNormMatch = ($norm !== '' && (string) ($b['normalized_phone'] ?? '') === $norm) ? 0 : 1;
        if ($aNormMatch !== $bNormMatch) {
            return $aNormMatch <=> $bNormMatch;
        }
        return ((int) $a['id']) <=> ((int) $b['id']);
    });

    return $rows;
}

function findUserByPhonePhp(PDO $pdo, string $rawPhone, string $normalizedPhone = '', string $dialCode = '', ?int $excludeUserId = null): ?array
{
    $rows = findUsersByPhonePhp($pdo, $rawPhone, $normalizedPhone, $dialCode, $excludeUserId);
    return $rows[0] ?? null;
}

function isDuplicateKeyExceptionPhp(Throwable $e): bool
{
    $code = (string) $e->getCode();
    $msg = strtolower($e->getMessage());
    if ($code === '23000' || $code === '1062' || $code === '19') {
        return true;
    }
    return str_contains($msg, 'duplicate entry')
        || str_contains($msg, 'unique constraint failed')
        || str_contains($msg, 'integrity constraint violation');
}

function isValidEmailForGatewayPhp(string $email): bool
{
    $trimmed = strtolower(trim($email));
    if ($trimmed === '' || str_ends_with($trimmed, '@phone.vendor.local')) {
        return false;
    }
    return filter_var($trimmed, FILTER_VALIDATE_EMAIL) !== false;
}

function sendOtpSms(string $phone, string $otp, string $recaptchaToken = ''): array
{
    // 0. Firebase Test Phone Number Check
    if (isFirebaseTestPhone($phone)) {
        return [
            'sent' => true,
            'provider' => 'firebase',
            'channel' => 'phone',
            'status' => 'dispatched',
            'test_mode' => true,
            'note' => 'Firebase test phone number active. Verified with test OTP ' . getFirebaseTestOtp()
        ];
    }

    $firebaseApiKey = getEnvVar('FIREBASE_API_KEY');
    $fast2smsKey = getEnvVar('FAST2SMS_API_KEY');

    // 1. Firebase Identity Toolkit REST API (Phone OTP)
    if (!empty($firebaseApiKey)) {
        $url = 'https://identitytoolkit.googleapis.com/v1/accounts:sendVerificationCode?key=' . urlencode($firebaseApiKey);
        $payload = ['phoneNumber' => $phone];
        if (!empty($recaptchaToken)) {
            $payload['recaptchaToken'] = $recaptchaToken;
        }
        $res = firebaseHttpJsonPostPhp($url, $payload);
        if ($res['ok']) {
            return [
                'sent' => true,
                'provider' => 'firebase',
                'channel' => 'phone',
                'status' => 'dispatched',
                ...(!empty($res['data']['sessionInfo']) ? ['session_info' => $res['data']['sessionInfo']] : [])
            ];
        }
    }

    // 2. Fast2SMS (Indian numbers)
    if (!empty($fast2smsKey) && function_exists('curl_init')) {
        $digits = preg_replace('/\D+/', '', $phone);
        $num = substr($digits, -10);
        $ch = curl_init('https://www.fast2sms.com/dev/bulkV2');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'route' => 'otp',
            'variables_values' => $otp,
            'numbers' => $num
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["authorization: {$fast2smsKey}"]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            return ['sent' => true, 'provider' => 'fast2sms', 'channel' => 'phone', 'status' => 'dispatched'];
        }
        return ['sent' => false, 'provider' => 'fast2sms', 'channel' => 'phone', 'error' => "HTTP {$httpCode}: {$res}"];
    }

    // 3. Fallback: Firebase / SMS credentials not configured (developer mock mode)
    return [
        'sent' => false,
        'provider' => !empty($firebaseApiKey) ? 'firebase' : 'mock',
        'channel' => 'phone',
        'reason' => !empty($firebaseApiKey)
            ? 'Firebase Phone Gateway could not dispatch SMS automatically (recaptchaToken may be required by client). dev_otp returned for verification testing.'
            : 'Firebase credentials (FIREBASE_API_KEY) are not set in .env. dev_otp returned in payload for testing.'
    ];
}

function sendPasswordResetEmail(string $email, string $otp, string $continueUrl = '', string $authMode = 'password_reset'): array
{
    if (isFirebaseTestEmail($email)) {
        return [
            'sent' => true,
            'provider' => 'firebase',
            'channel' => 'email',
            'status' => 'dispatched',
            'test_mode' => true,
            'flow' => 'test_email',
            'note' => 'Firebase test email active. Verified with test OTP ' . getFirebaseTestOtp()
        ];
    }

    $firebaseApiKey = getEnvVar('FIREBASE_API_KEY');
    if (empty($firebaseApiKey)) {
        return [
            'sent' => false,
            'provider' => 'mock',
            'channel' => 'email',
            'reason' => 'Firebase credentials (FIREBASE_API_KEY) are not set in .env. dev_otp returned in payload for testing.'
        ];
    }

    $requestType = (strtolower($authMode) === 'email_link' && !empty($continueUrl)) ? 'EMAIL_SIGNIN' : 'PASSWORD_RESET';
    $payload = ['requestType' => $requestType, 'email' => $email];
    if (!empty($continueUrl)) {
        $payload['continueUrl'] = $continueUrl;
    }

    $sendUrl = 'https://identitytoolkit.googleapis.com/v1/accounts:sendOobCode?key=' . urlencode($firebaseApiKey);
    $res = firebaseHttpJsonPostPhp($sendUrl, $payload);

    $errMsg = (string) ($res['data']['error']['message'] ?? '');
    if (!$res['ok'] && str_contains($errMsg, 'EMAIL_NOT_FOUND')) {
        $tempPass = bin2hex(random_bytes(16)) . 'Aa1!';
        $signUpUrl = 'https://identitytoolkit.googleapis.com/v1/accounts:signUp?key=' . urlencode($firebaseApiKey);
        $signUpRes = firebaseHttpJsonPostPhp($signUpUrl, [
            'email' => $email,
            'password' => $tempPass,
            'returnSecureToken' => false
        ]);
        $signUpErr = (string) ($signUpRes['data']['error']['message'] ?? '');
        if ($signUpRes['ok'] || str_contains($signUpErr, 'EMAIL_EXISTS')) {
            $res = firebaseHttpJsonPostPhp($sendUrl, $payload);
        }
    }

    if ($res['ok']) {
        return [
            'sent' => true,
            'provider' => 'firebase',
            'channel' => 'email',
            'status' => 'dispatched',
            'flow' => strtolower($requestType),
            'note' => 'Password reset instruction dispatched via Firebase Email Gateway.'
        ];
    }

    $finalErr = (string) ($res['data']['error']['message'] ?? $res['error'] ?? 'Firebase Email Gateway rejected request.');
    return [
        'sent' => false,
        'provider' => 'firebase',
        'channel' => 'email',
        'status' => 'mock_mode',
        'firebase_error' => $finalErr,
        'reason' => "Firebase Email Gateway did not dispatch an email automatically ({$finalErr}). dev_otp returned for verification testing."
    ];
}

function verifyFirebaseIdentity(array $params): array
{
    $phone = trim((string) ($params['phone'] ?? ''));
    $email = strtolower(trim((string) ($params['email'] ?? '')));
    $code = trim((string) ($params['code'] ?? ''));
    $sessionInfo = trim((string) ($params['sessionInfo'] ?? ''));
    $idToken = trim((string) ($params['idToken'] ?? ''));
    $oobCode = trim((string) ($params['oobCode'] ?? ''));

    $testOtp = getFirebaseTestOtp();

    // 0a. Firebase Test Phone & Test OTP Check
    if ($phone !== '' && isFirebaseTestPhone($phone) && ($code === $testOtp || $code === '000000')) {
        return [
            'approved' => true,
            'provider' => 'firebase',
            'method' => 'test_phone',
            'resolvedPhone' => $phone,
            'resolvedEmail' => $email !== '' ? $email : null
        ];
    }

    // 0b. Firebase Test Email & Test OTP Check
    if ($email !== '' && isFirebaseTestEmail($email) && ($code === $testOtp || $code === '000000')) {
        return [
            'approved' => true,
            'provider' => 'firebase',
            'method' => 'test_email',
            'resolvedPhone' => $phone !== '' ? $phone : null,
            'resolvedEmail' => $email
        ];
    }

    $firebaseApiKey = getEnvVar('FIREBASE_API_KEY');
    if (empty($firebaseApiKey)) {
        return ['approved' => false];
    }

    $reqNormPhone = $phone !== '' ? normalizePhoneNumberPhp($phone) : '';

    // Flow A: Client-side Firebase Auth ID Token (Phone Auth, Email Link Sign-In, or Email Auth)
    if ($idToken !== '') {
        $url = 'https://identitytoolkit.googleapis.com/v1/accounts:lookup?key=' . urlencode($firebaseApiKey);
        $res = firebaseHttpJsonPostPhp($url, ['idToken' => $idToken]);
        if ($res['ok'] && !empty($res['data']['users'][0])) {
            $fbUser = $res['data']['users'][0];
            $fbPhoneRaw = (string) ($fbUser['phoneNumber'] ?? '');
            $fbNormPhone = $fbPhoneRaw !== '' ? normalizePhoneNumberPhp($fbPhoneRaw) : '';
            $fbEmail = strtolower(trim((string) ($fbUser['email'] ?? '')));

            $phoneMatched = $fbNormPhone !== '' && ($reqNormPhone === '' || $fbNormPhone === $reqNormPhone);
            $emailMatched = $fbEmail !== '' && isValidEmailForGatewayPhp($fbEmail) && ($email === '' || $fbEmail === $email);

            if (
                ($reqNormPhone !== '' && $email !== '' && $phoneMatched && $emailMatched) ||
                ($reqNormPhone !== '' && $email === '' && $phoneMatched) ||
                ($reqNormPhone === '' && $email !== '' && $emailMatched) ||
                ($reqNormPhone === '' && $email === '' && ($phoneMatched || $emailMatched))
            ) {
                return [
                    'approved' => true,
                    'provider' => 'firebase',
                    'method' => 'id_token',
                    'resolvedPhone' => $reqNormPhone !== '' ? $reqNormPhone : ($fbNormPhone !== '' ? $fbNormPhone : null),
                    'resolvedEmail' => $email !== '' ? $email : (isValidEmailForGatewayPhp($fbEmail) ? $fbEmail : null)
                ];
            }
        }
    }

    // Flow B: Backend Firebase signInWithPhoneNumber (sessionInfo + code)
    if ($sessionInfo !== '' && $code !== '') {
        $url = 'https://identitytoolkit.googleapis.com/v1/accounts:signInWithPhoneNumber?key=' . urlencode($firebaseApiKey);
        $res = firebaseHttpJsonPostPhp($url, ['sessionInfo' => $sessionInfo, 'code' => $code]);
        if ($res['ok'] && !empty($res['data']['phoneNumber'])) {
            $fbPhoneRaw = (string) $res['data']['phoneNumber'];
            $fbNormPhone = normalizePhoneNumberPhp($fbPhoneRaw);
            if ($reqNormPhone === '' || ($fbNormPhone !== '' && $fbNormPhone === $reqNormPhone)) {
                return [
                    'approved' => true,
                    'provider' => 'firebase',
                    'method' => 'session_info',
                    'resolvedPhone' => $reqNormPhone !== '' ? $reqNormPhone : ($fbNormPhone !== '' ? $fbNormPhone : null),
                    'resolvedEmail' => $email !== '' ? $email : null
                ];
            }
        }
    }

    // Flow C: Firebase Password Reset OOB Code check (verifies code without consuming it)
    if ($oobCode !== '') {
        $url = 'https://identitytoolkit.googleapis.com/v1/accounts:resetPassword?key=' . urlencode($firebaseApiKey);
        $res = firebaseHttpJsonPostPhp($url, ['oobCode' => $oobCode]);
        if ($res['ok'] && !empty($res['data']['email'])) {
            $fbEmail = strtolower(trim((string) $res['data']['email']));
            if (isValidEmailForGatewayPhp($fbEmail) && ($email === '' || $fbEmail === $email)) {
                return [
                    'approved' => true,
                    'provider' => 'firebase',
                    'method' => 'oob_code',
                    'resolvedPhone' => $phone !== '' ? $phone : null,
                    'resolvedEmail' => $email !== '' ? $email : $fbEmail
                ];
            }
        }
    }

    return ['approved' => false];
}

function applyFirebaseOobPasswordResetPhp(string $oobCode, string $newPassword): array
{
    $firebaseApiKey = getEnvVar('FIREBASE_API_KEY');
    if (empty($firebaseApiKey) || $oobCode === '') {
        return ['ok' => false, 'error' => 'Missing Firebase API key or OOB code.'];
    }
    $url = 'https://identitytoolkit.googleapis.com/v1/accounts:resetPassword?key=' . urlencode($firebaseApiKey);
    $res = firebaseHttpJsonPostPhp($url, ['oobCode' => $oobCode, 'newPassword' => $newPassword]);
    if ($res['ok'] && !empty($res['data']['email'])) {
        return ['ok' => true, 'email' => strtolower(trim((string) $res['data']['email']))];
    }
    $errMsg = (string) ($res['data']['error']['message'] ?? $res['error'] ?? 'Invalid or expired Firebase reset code.');
    return ['ok' => false, 'error' => $errMsg];
}

function verifyFirebaseOtp(string $phone, string $code, string $sessionInfo = '', string $idToken = '', string $email = '', string $oobCode = ''): bool
{
    $res = verifyFirebaseIdentity([
        'phone' => $phone,
        'email' => $email,
        'code' => $code,
        'sessionInfo' => $sessionInfo,
        'idToken' => $idToken,
        'oobCode' => $oobCode
    ]);
    return !empty($res['approved']);
}


