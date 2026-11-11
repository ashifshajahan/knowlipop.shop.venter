<?php

/**
 * Pure PHP Database Connection (PDO MySQL)
 * Reads from config.php or environment variables.
 */

function getDatabaseConfig(): array
{
    $connOverride = strtolower(trim((string) (getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? ($_SERVER['DB_CONNECTION'] ?? '')))));
    if ($connOverride === 'sqlite') {
        $sqlitePath = trim((string) (getenv('SQLITE_DB_PATH') ?: ($_ENV['SQLITE_DB_PATH'] ?? ($_SERVER['SQLITE_DB_PATH'] ?? ''))));
        if ($sqlitePath === '') {
            $sqlitePath = __DIR__ . '/../database/database.sqlite';
        }
        return [
            'connection' => 'sqlite',
            'sqlite_path' => $sqlitePath,
            'host' => getenv('DB_HOST') ?: '127.0.0.1',
            'port' => getenv('DB_PORT') ?: '3306',
            'name' => getenv('DB_DATABASE') ?: 'u999617803_knowlipop1',
            'user' => getenv('DB_USERNAME') ?: 'u999617803_knowlipop1',
            'pass' => getenv('DB_PASSWORD') ?: '',
        ];
    }

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

    $connection = strtolower(trim((string) ($env['DB_CONNECTION'] ?? getenv('DB_CONNECTION') ?: 'mysql')));
    $sqlitePath = trim((string) ($env['SQLITE_DB_PATH'] ?? getenv('SQLITE_DB_PATH') ?: ''));
    if ($sqlitePath === '') {
        $sqlitePath = __DIR__ . '/../database/database.sqlite';
    }

    return [
        'connection' => $connection,
        'sqlite_path' => $sqlitePath,
        'host' => $env['DB_HOST'] ?? getenv('DB_HOST') ?: '127.0.0.1',
        'port' => $env['DB_PORT'] ?? getenv('DB_PORT') ?: '3306',
        'name' => $env['DB_DATABASE'] ?? getenv('DB_DATABASE') ?: 'u999617803_knowlipop1',
        'user' => $env['DB_USERNAME'] ?? getenv('DB_USERNAME') ?: 'u999617803_knowlipop1',
        'pass' => $env['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: 'Xtragclub_vendor2026',
    ];
}

function getDatabaseConnection(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $config = getDatabaseConfig();

    if (($config['connection'] ?? 'mysql') === 'sqlite') {
        $sqlitePath = $config['sqlite_path'] ?? (__DIR__ . '/../database/database.sqlite');
        try {
            $pdo = new PDO('sqlite:' . $sqlitePath);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->exec('PRAGMA foreign_keys = OFF; PRAGMA busy_timeout = 5000;');
            return $pdo;
        } catch (PDOException $e) {
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode([
                'error' => 'Database connection failed',
                'message' => $e->getMessage(),
                'hint' => 'Please verify SQLITE_DB_PATH permissions.'
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            exit;
        }
    }

    $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['name']};charset=utf8mb4";

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => true,
        PDO::ATTR_TIMEOUT => 10,
    ];

    $candidates = [
        ['user' => $config['user'], 'pass' => $config['pass']],
        ['user' => 'root', 'pass' => ''],
    ];

    $lastError = null;
    foreach ($candidates as $cand) {
        try {
            $pdo = new PDO($dsn, $cand['user'], $cand['pass'], $options);
            return $pdo;
        } catch (PDOException $e) {
            $lastError = $e;
        }
    }

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'error' => 'Database connection failed',
        'message' => $lastError ? $lastError->getMessage() : 'Could not connect to database',
        'hint' => 'Please check your database credentials in config.php or .env'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}
