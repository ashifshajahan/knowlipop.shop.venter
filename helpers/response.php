<?php

/**
 * HTTP & JSON Response Helpers
 */

function handleCors(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
    header("Access-Control-Allow-Origin: {$origin}");
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, Accept, X-Requested-With, Origin');
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Max-Age: 86400');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function jsonResponse(mixed $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    if (isset($GLOBALS['__TEST_RESPONSE_HANDLER']) && is_callable($GLOBALS['__TEST_RESPONSE_HANDLER'])) {
        ($GLOBALS['__TEST_RESPONSE_HANDLER'])($data, $statusCode);
        return;
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function errorResponse(string $message, array $errors = [], int $statusCode = 400, array $extra = []): void
{
    $payload = [];
    if (array_key_exists('status', $extra)) {
        $payload['status'] = $extra['status'];
    }
    $payload['message'] = $message;
    foreach ($extra as $k => $v) {
        if ($k === 'status' || $k === 'message' || $k === 'errors') {
            continue;
        }
        $payload[$k] = $v;
    }
    if (!empty($errors)) {
        $payload['errors'] = $errors;
    }
    jsonResponse($payload, $statusCode);
}

function getJsonInput(): array
{
    if (isset($GLOBALS['_CUSTOM_JSON_INPUT']) && is_array($GLOBALS['_CUSTOM_JSON_INPUT'])) {
        return $GLOBALS['_CUSTOM_JSON_INPUT'];
    }
    $raw = file_get_contents('php://input');
    if (!$raw) {
        return $_POST;
    }
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        return array_merge($_POST, $decoded);
    }
    return $_POST;
}

function getPersistentMediaDirPhp(string $subFolder = 'products'): string
{
    $safeSub = ($subFolder === 'avatars') ? 'avatars' : 'products';
    $candidates = [];
    $envDir = getenv('MEDIA_UPLOAD_DIR');
    if (is_string($envDir) && trim($envDir) !== '') {
        $candidates[] = rtrim(trim($envDir), "/\\") . '/' . $safeSub;
    }

    $rootDir = dirname(__DIR__);
    $isHostinger = str_contains($rootDir, 'u999617803') || is_dir('/home/u999617803');
    if ($isHostinger) {
        $candidates[] = '/home/u999617803/domains/knowlipop.shop/venter_uploads/' . $safeSub;
        $candidates[] = '/home/u999617803/venter_uploads/' . $safeSub;
        if (str_contains($rootDir, 'hbuilds')) {
            $candidates[] = dirname($rootDir, 3) . '/venter_uploads/' . $safeSub;
            $candidates[] = dirname($rootDir, 2) . '/venter_uploads/' . $safeSub;
        }
    }
    $candidates[] = $rootDir . '/uploads/' . $safeSub;

    foreach ($candidates as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            return rtrim($dir, "/\\") . '/';
        }
    }

    $fallback = $rootDir . '/uploads/' . $safeSub;
    if (!is_dir($fallback)) {
        @mkdir($fallback, 0755, true);
    }
    return rtrim($fallback, "/\\") . '/';
}

function resolveUploadedMediaPathPhp(string $fileName, bool $isAvatar = false): ?string
{
    $safeName = basename(explode('?', $fileName)[0]);
    if ($safeName === '' || $safeName === '.' || $safeName === '..') {
        return null;
    }

    $primarySub = $isAvatar ? 'avatars' : 'products';
    $altSub = $isAvatar ? 'products' : 'avatars';
    $rootDir = dirname(__DIR__);

    $candidates = [
        getPersistentMediaDirPhp($primarySub) . $safeName,
        getPersistentMediaDirPhp($altSub) . $safeName,
        $rootDir . '/uploads/' . $primarySub . '/' . $safeName,
        $rootDir . '/uploads/' . $altSub . '/' . $safeName,
        $rootDir . '/storage/app/public/' . $primarySub . '/' . $safeName,
        $rootDir . '/storage/app/public/' . $altSub . '/' . $safeName,
        $rootDir . '/public/storage/' . $primarySub . '/' . $safeName,
        $rootDir . '/public/storage/' . $altSub . '/' . $safeName,
        '/home/u999617803/domains/knowlipop.shop/venter_uploads/' . $primarySub . '/' . $safeName,
        '/home/u999617803/domains/knowlipop.shop/venter_uploads/' . $altSub . '/' . $safeName,
        '/home/u999617803/venter_uploads/' . $primarySub . '/' . $safeName,
        '/home/u999617803/venter_uploads/' . $altSub . '/' . $safeName,
        '/home/u999617803/domains/knowlipop.shop/public_html/uploads/' . $primarySub . '/' . $safeName,
        '/home/u999617803/domains/knowlipop.shop/public_html/uploads/' . $altSub . '/' . $safeName,
    ];

    foreach ($candidates as $p) {
        if (is_file($p)) {
            return $p;
        }
    }

    $versionsRoot = '/home/u999617803/domains/knowlipop.shop/hbuilds/versions';
    if (is_dir($versionsRoot)) {
        $versions = @scandir($versionsRoot);
        if (is_array($versions)) {
            foreach ($versions as $ver) {
                if ($ver === '.' || $ver === '..') continue;
                foreach ([$primarySub, $altSub] as $sub) {
                    $legacyPath = $versionsRoot . '/' . $ver . '/nodejs/uploads/' . $sub . '/' . $safeName;
                    if (is_file($legacyPath)) {
                        $destDir = getPersistentMediaDirPhp($sub);
                        $destPath = $destDir . $safeName;
                        if (!is_file($destPath)) {
                            @copy($legacyPath, $destPath);
                            @chmod($destPath, 0644);
                        }
                        return is_file($destPath) ? $destPath : $legacyPath;
                    }
                }
            }
        }
    }

    return null;
}


