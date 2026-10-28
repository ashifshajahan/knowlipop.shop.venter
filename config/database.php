<?php

/**
 * Pure PHP Database Connection (PDO MySQL)
 * Reads from config.php or environment variables.
 */

function getDatabaseConfig(): array
{
    $configFile = __DIR__ . '/../config.php';
    if (file_exists($configFile)) {
        $cfg = require $configFile;
        if (isset($cfg['db'])) {
            return $cfg['db'];
        }
    }

    // Fallback: check .env file if present
    $envFile = __DIR__ . '/../.env';
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
        'host' => $env['DB_HOST'] ?? getenv('DB_HOST') ?: '127.0.0.1',
        'port' => $env['DB_PORT'] ?? getenv('DB_PORT') ?: '3306',
        'name' => $env['DB_DATABASE'] ?? getenv('DB_DATABASE') ?: 'u999617803_knolipop',
        'user' => $env['DB_USERNAME'] ?? getenv('DB_USERNAME') ?: 'u999617803_xtragclub',
        'pass' => $env['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: '',
    ];
}

function getDatabaseConnection(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $config = getDatabaseConfig();
    $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['name']};charset=utf8mb4";

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => true,
    ];

    try {
        $pdo = new PDO($dsn, $config['user'], $config['pass'], $options);
        return $pdo;
    } catch (PDOException $e) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'error' => 'Database connection failed',
            'message' => $e->getMessage(),
            'hint' => 'Please check your database credentials in config.php or .env'
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
