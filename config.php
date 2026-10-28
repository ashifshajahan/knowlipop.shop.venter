<?php

/**
 * Vendor App Configuration
 * Reads from .env if available, with fallbacks for your server (e.g. Hostinger / cPanel / Localhost)
 */

$envFile = __DIR__ . '/.env';
$env = [];
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (str_contains($line, '=')) {
            [$k, $v] = explode('=', $line, 2);
            $env[trim($k)] = trim(trim($v), '"\'');
        }
    }
}

return [
    'app_name' => $env['APP_NAME'] ?? 'Vendor App API',
    'app_env' => $env['APP_ENV'] ?? 'production',
    'app_url' => $env['APP_URL'] ?? 'https://knowlipop.shop',

    'db' => [
        'host' => $env['DB_HOST'] ?? '127.0.0.1',
        'port' => $env['DB_PORT'] ?? '3306',
        'name' => $env['DB_DATABASE'] ?? 'u999617803_knowlipop1',
        'user' => $env['DB_USERNAME'] ?? 'u999617803_knowlipop1',
        'pass' => $env['DB_PASSWORD'] ?? 'Xtragclub_vendor2026',
    ],

    // CORS allowed origins (comma-separated or '*' for public API)
    'cors_origins' => '*',
];
