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
    $clean1 = preg_replace('/\D+/', '', $phone);
    $clean2 = preg_replace('/\D+/', '', getFirebaseTestPhone());
    return !empty($clean1) && !empty($clean2) && strlen($clean1) >= 10 && strlen($clean2) >= 10 && str_ends_with($clean1, substr($clean2, -10));
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

function normalizePhoneSimplePhp(string $raw): string
{
    if ($raw === '') return '';
    $str = preg_replace('/[\s\-\(\)]/', '', trim($raw));
    if ($str === '' || $str === null) return '';
    if (str_starts_with($str, '+')) return $str;
    if (preg_match('/^\d{10}$/', $str)) return '+91' . $str;
    if (preg_match('/^91\d{10}$/', $str)) return '+' . $str;
    return '+' . $str;
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

    $cleanPhone = preg_replace('/\D+/', '', $phone);

    // Flow A: Client-side Firebase Auth ID Token (Phone Auth, Email Link Sign-In, or Email Auth)
    if ($idToken !== '') {
        $url = 'https://identitytoolkit.googleapis.com/v1/accounts:lookup?key=' . urlencode($firebaseApiKey);
        $res = firebaseHttpJsonPostPhp($url, ['idToken' => $idToken]);
        if ($res['ok'] && !empty($res['data']['users'][0])) {
            $fbUser = $res['data']['users'][0];
            $fbPhoneRaw = (string) ($fbUser['phoneNumber'] ?? '');
            $fbPhoneDigits = preg_replace('/\D+/', '', $fbPhoneRaw);
            $fbEmail = strtolower(trim((string) ($fbUser['email'] ?? '')));

            $phoneMatched = $fbPhoneDigits !== '' && ($cleanPhone === '' || (strlen($cleanPhone) >= 10 && str_ends_with($fbPhoneDigits, substr($cleanPhone, -10))));
            $emailMatched = $fbEmail !== '' && isValidEmailForGatewayPhp($fbEmail) && ($email === '' || $fbEmail === $email);

            if (
                ($cleanPhone !== '' && $email !== '' && $phoneMatched && $emailMatched) ||
                ($cleanPhone !== '' && $email === '' && $phoneMatched) ||
                ($cleanPhone === '' && $email !== '' && $emailMatched) ||
                ($cleanPhone === '' && $email === '' && ($phoneMatched || $emailMatched))
            ) {
                return [
                    'approved' => true,
                    'provider' => 'firebase',
                    'method' => 'id_token',
                    'resolvedPhone' => $phone !== '' ? $phone : ($fbPhoneRaw !== '' ? normalizePhoneSimplePhp($fbPhoneRaw) : null),
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
            $fbPhoneDigits = preg_replace('/\D+/', '', $fbPhoneRaw);
            if ($cleanPhone === '' || (strlen($cleanPhone) >= 10 && str_ends_with($fbPhoneDigits, substr($cleanPhone, -10)))) {
                return [
                    'approved' => true,
                    'provider' => 'firebase',
                    'method' => 'session_info',
                    'resolvedPhone' => $phone !== '' ? $phone : normalizePhoneSimplePhp($fbPhoneRaw),
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


