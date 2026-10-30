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

function createToken(PDO $pdo, int $userId, string $phone = '', string $name = 'vendor-app'): string
{
    $secret = getJwtSecret();

    // Standard JWT token matching Node.js
    $header = rtrim(strtr(base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'])), '+/', '-_'), '=');
    $payload = rtrim(strtr(base64_encode(json_encode([
        'id' => $userId,
        'phone' => $phone,
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
            VALUES ('App\\\\Models\\\\User', :user_id, :name, :token, '[\"*\"]', NOW(), NOW())
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':name' => $name,
            ':token' => $tokenHash,
        ]);
    } catch (Exception $e) {
        // Silently continue if table not present
    }

    return $jwtToken;
}

function authenticateUser(PDO $pdo): array
{
    $token = getBearerToken();
    if (!$token) {
        errorResponse('Unauthenticated.', [], 401);
    }

    // 1. Verify standard JWT token (shared with Node.js)
    $jwtPayload = verifyJwtToken($token, getJwtSecret());
    if ($jwtPayload && isset($jwtPayload['id'])) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $jwtPayload['id']]);
        $user = $stmt->fetch();
        if ($user) {
            $user['token_id'] = 0;
            return $user;
        }
    }

    // 2. Legacy Sanctum personal_access_tokens (id|plainText)
    $parts = explode('|', $token, 2);
    if (count($parts) === 2) {
        [$id, $plain] = $parts;
        $hash = hash('sha256', $plain);

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
            $updateStmt = $pdo->prepare("UPDATE personal_access_tokens SET last_used_at = NOW() WHERE id = :id");
            $updateStmt->execute([':id' => $id]);
            return $user;
        }
    }

    errorResponse('Unauthenticated.', [], 401);
}

function deleteCurrentToken(PDO $pdo, int $tokenId): void
{
    if ($tokenId > 0) {
        $stmt = $pdo->prepare("DELETE FROM personal_access_tokens WHERE id = :id");
        $stmt->execute([':id' => $tokenId]);
    }
}

function safeSeller(array $user): array
{
    return [
        'id' => (int) $user['id'],
        'seller_name' => $user['seller_name'] ?? $user['name'] ?? '',
        'name' => $user['name'] ?? $user['seller_name'] ?? '',
        'email' => $user['email'] ?? null,
        'phone' => $user['phone'] ?? null,
        'address' => $user['address'] ?? null,
        'landmark' => $user['landmark'] ?? null,
        'state' => $user['state'] ?? null,
        'country' => $user['country'] ?? null,
        'role' => $user['role'] ?? 'seller',
        'avatar_url' => $user['avatar_url'] ?? null,
        'created_at' => $user['created_at'] ?? null,
        'updated_at' => $user['updated_at'] ?? null,
    ];
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
    return $env[$key] ?? (getenv($key) !== false ? (string)getenv($key) : $default);
}

function getFirebaseTestPhone(): string
{
    return getEnvVar('FIREBASE_TEST_PHONE', '+919633426254');
}

function getFirebaseTestOtp(): string
{
    return getEnvVar('FIREBASE_TEST_OTP', '000000');
}

function isFirebaseTestPhone(string $phone): bool
{
    $clean1 = preg_replace('/\D+/', '', $phone);
    $clean2 = preg_replace('/\D+/', '', getFirebaseTestPhone());
    return !empty($clean1) && !empty($clean2) && str_ends_with($clean1, substr($clean2, -10));
}

function sendOtpSms(string $phone, string $otp, string $recaptchaToken = ''): array
{
    // 0. Firebase Test Phone Number Check
    if (isFirebaseTestPhone($phone)) {
        return [
            'sent' => true,
            'provider' => 'firebase',
            'status' => 'dispatched',
            'test_mode' => true,
            'note' => 'Firebase test phone number active. Verified with test OTP ' . getFirebaseTestOtp()
        ];
    }

    $firebaseApiKey = getEnvVar('FIREBASE_API_KEY');
    $fast2smsKey = getEnvVar('FAST2SMS_API_KEY');

    // 1. Firebase Identity Toolkit REST API (Phone OTP)
    if (!empty($firebaseApiKey)) {
        $url = "https://identitytoolkit.googleapis.com/v1/accounts:sendVerificationCode?key={$firebaseApiKey}";
        $payload = ['phoneNumber' => $phone];
        if (!empty($recaptchaToken)) {
            $payload['recaptchaToken'] = $recaptchaToken;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $json = json_decode((string)$res, true);
        if ($httpCode >= 200 && $httpCode < 300 && !empty($json['sessionInfo'])) {
            return [
                'sent' => true,
                'provider' => 'firebase',
                'status' => 'dispatched',
                'session_info' => $json['sessionInfo']
            ];
        }
    }

    // 2. Fast2SMS (Indian numbers)
    if (!empty($fast2smsKey)) {
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
            return ['sent' => true, 'provider' => 'fast2sms', 'status' => 'delivered'];
        }
        return ['sent' => false, 'provider' => 'fast2sms', 'error' => "HTTP {$httpCode}: {$res}"];
    }

    // 3. Fallback: Firebase / SMS credentials not configured (developer mock mode)
    return [
        'sent' => false,
        'provider' => 'mock',
        'reason' => 'Firebase credentials (FIREBASE_API_KEY) are not set in .env. dev_otp returned in payload for testing.'
    ];
}

function verifyFirebaseOtp(string $phone, string $code, string $sessionInfo = '', string $idToken = ''): bool
{
    // 0. Firebase Test Phone & Test OTP Check
    if (isFirebaseTestPhone($phone) && ($code === getFirebaseTestOtp() || $code === '000000')) {
        return true;
    }

    $firebaseApiKey = getEnvVar('FIREBASE_API_KEY');
    if (empty($firebaseApiKey)) {
        return false;
    }

    $cleanPhone = preg_replace('/\D+/', '', $phone);

    // Flow A: Client-side Firebase Phone Auth ID Token
    if (!empty($idToken)) {
        $url = "https://identitytoolkit.googleapis.com/v1/accounts:lookup?key={$firebaseApiKey}";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['idToken' => $idToken]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            $json = json_decode((string)$res, true);
            if (!empty($json['users'][0]['phoneNumber'])) {
                $userPhone = preg_replace('/\D+/', '', (string)$json['users'][0]['phoneNumber']);
                if (str_ends_with($userPhone, substr($cleanPhone, -10))) {
                    return true;
                }
            }
        }
    }

    // Flow B: Backend Firebase signInWithPhoneNumber (sessionInfo + code)
    if (!empty($sessionInfo) && !empty($code)) {
        $url = "https://identitytoolkit.googleapis.com/v1/accounts:signInWithPhoneNumber?key={$firebaseApiKey}";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['sessionInfo' => $sessionInfo, 'code' => $code]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            $json = json_decode((string)$res, true);
            if (!empty($json['phoneNumber'])) {
                $userPhone = preg_replace('/\D+/', '', (string)$json['phoneNumber']);
                if (str_ends_with($userPhone, substr($cleanPhone, -10))) {
                    return true;
                }
            }
        }
    }

    return false;
}


