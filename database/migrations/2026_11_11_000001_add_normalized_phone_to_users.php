<?php

/**
 * Migration: Add normalized_phone column, audit duplicates non-destructively,
 * backfill canonical normalized phone numbers, and add a UNIQUE index on users(normalized_phone).
 *
 * Safe to run repeatedly (idempotent). Never deletes existing seller records or recreates tables.
 *
 * CLI usage:
 *   php database/migrations/2026_11_11_000001_add_normalized_phone_to_users.php
 *   php database/migrations/2026_11_11_000001_add_normalized_phone_to_users.php --sqlite=/path/to/test.sqlite
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/auth.php';

function migrateAddNormalizedPhoneToUsers(PDO $pdo): array
{
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    $hasColBefore = authUsersHasColumnPhp($pdo, 'normalized_phone');
    $hasIdxBefore = authUsersHasUniqueNormalizedPhoneIndexPhp($pdo);

    $report = ensureNormalizedPhoneSchemaPhp($pdo, true);

    $hasColAfter = authUsersHasColumnPhp($pdo, 'normalized_phone');
    $hasIdxAfter = authUsersHasUniqueNormalizedPhoneIndexPhp($pdo);

    $totalUsersWithPhone = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE phone IS NOT NULL AND TRIM(phone) != ''")->fetchColumn();
    $totalNormalizedPopulated = $hasColAfter
        ? (int) $pdo->query("SELECT COUNT(*) FROM users WHERE normalized_phone IS NOT NULL AND TRIM(normalized_phone) != ''")->fetchColumn()
        : 0;

    return [
        'status' => ($hasColAfter && $hasIdxAfter) ? 'ok' : 'partial',
        'driver' => $driver,
        'column_existed_before' => $hasColBefore,
        'unique_index_existed_before' => $hasIdxBefore,
        'column_present' => $hasColAfter,
        'unique_index_present' => $hasIdxAfter,
        'index_name' => 'uniq_users_normalized_phone',
        'total_users_with_phone' => $totalUsersWithPhone,
        'canonical_normalized_rows' => $totalNormalizedPopulated,
        'backfilled_rows' => (int) ($report['backfilled_rows'] ?? 0),
        'duplicate_groups' => $report['duplicate_groups'] ?? [],
        'deleted_records' => 0,
    ];
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $sqlitePath = null;
    foreach ($argv ?? [] as $arg) {
        if (str_starts_with($arg, '--sqlite=')) {
            $sqlitePath = substr($arg, 9);
        }
    }

    if ($sqlitePath !== null && $sqlitePath !== '') {
        $pdo = new PDO('sqlite:' . $sqlitePath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    } else {
        $pdo = getDatabaseConnection();
    }

    $result = migrateAddNormalizedPhoneToUsers($pdo);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}
