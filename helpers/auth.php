<?php

/**
 * Authentication & Token Helpers (Pure PHP Bearer Token System)
 */

require_once __DIR__ . '/response.php';

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

function createToken(PDO $pdo, int $userId, string $name = 'vendor-app'): string
{
    $plainText = bin2hex(random_bytes(24));
    $tokenHash = hash('sha256', $plainText);

    // Save in personal_access_tokens table
    $stmt = $pdo->prepare("
        INSERT INTO personal_access_tokens (tokenable_type, tokenable_id, name, token, abilities, created_at, updated_at)
        VALUES ('App\\\\Models\\\\User', :user_id, :name, :token, '[\"*\"]', NOW(), NOW())
    ");
    $stmt->execute([
        ':user_id' => $userId,
        ':name' => $name,
        ':token' => $tokenHash,
    ]);

    $tokenId = $pdo->lastInsertId();
    return "{$tokenId}|{$plainText}";
}

function authenticateUser(PDO $pdo): array
{
    $token = getBearerToken();
    if (!$token) {
        errorResponse('Unauthenticated.', [], 401);
    }

    // Parse token: "id|plainText"
    $parts = explode('|', $token, 2);
    if (count($parts) !== 2) {
        errorResponse('Unauthenticated.', [], 401);
    }

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

    if (!$user) {
        errorResponse('Unauthenticated.', [], 401);
    }

    // Update last_used_at
    $updateStmt = $pdo->prepare("UPDATE personal_access_tokens SET last_used_at = NOW() WHERE id = :id");
    $updateStmt->execute([':id' => $id]);

    return $user;
}

function deleteCurrentToken(PDO $pdo, int $tokenId): void
{
    $stmt = $pdo->prepare("DELETE FROM personal_access_tokens WHERE id = :id");
    $stmt->execute([':id' => $tokenId]);
}

function safeSeller(array $user): array
{
    return [
        'id' => (int) $user['id'],
        'seller_name' => $user['seller_name'] ?? $user['name'],
        'phone' => $user['phone'] ?? null,
        'address' => $user['address'] ?? null,
        'landmark' => $user['landmark'] ?? null,
        'state' => $user['state'] ?? null,
        'country' => $user['country'] ?? null,
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
