<?php

/**
 * Seller GST Helper Functions (Pure PHP & PDO)
 * Provides validation, table auto-creation, secure document storage, and audit logging.
 */

function ensureGstTables(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) return;

    $isSqlite = ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite');

    try {
        if ($isSqlite) {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `seller_gst_details` (
                  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                  `seller_id` INTEGER NOT NULL UNIQUE,
                  `gstin` VARCHAR(30) NULL DEFAULT NULL,
                  `business_name` VARCHAR(255) NULL DEFAULT NULL,
                  `business_address` TEXT NULL DEFAULT NULL,
                  `state` VARCHAR(100) NULL DEFAULT NULL,
                  `pincode` VARCHAR(20) NULL DEFAULT NULL,
                  `status` VARCHAR(30) NOT NULL DEFAULT 'Pending',
                  `rejection_reason` TEXT NULL DEFAULT NULL,
                  `verified_by` INTEGER NULL DEFAULT NULL,
                  `verified_at` DATETIME NULL DEFAULT NULL,
                  `submitted_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
                  `created_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
                  `updated_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP
                );
            ");
            $pdo->exec("CREATE INDEX IF NOT EXISTS `idx_gst_status` ON `seller_gst_details` (`status`);");
            $pdo->exec("CREATE INDEX IF NOT EXISTS `idx_gst_gstin` ON `seller_gst_details` (`gstin`);");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `seller_gst_documents` (
                  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                  `seller_id` INTEGER NOT NULL,
                  `gst_id` INTEGER NULL DEFAULT NULL,
                  `document_type` VARCHAR(50) NOT NULL DEFAULT 'gst_certificate',
                  `original_name` VARCHAR(255) NOT NULL,
                  `file_name` VARCHAR(255) NOT NULL,
                  `file_path` VARCHAR(255) NOT NULL,
                  `file_size` INTEGER NOT NULL,
                  `mime_type` VARCHAR(100) NOT NULL,
                  `is_primary` INTEGER NOT NULL DEFAULT 1,
                  `created_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
                  `updated_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP
                );
            ");
            $pdo->exec("CREATE INDEX IF NOT EXISTS `idx_gstdoc_seller` ON `seller_gst_documents` (`seller_id`);");
            $pdo->exec("CREATE INDEX IF NOT EXISTS `idx_gstdoc_gst` ON `seller_gst_documents` (`gst_id`);");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `seller_gst_verification_logs` (
                  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                  `seller_id` INTEGER NOT NULL,
                  `gst_id` INTEGER NULL DEFAULT NULL,
                  `action` VARCHAR(40) NOT NULL,
                  `previous_status` VARCHAR(30) NULL DEFAULT NULL,
                  `new_status` VARCHAR(30) NOT NULL,
                  `notes` TEXT NULL DEFAULT NULL,
                  `performed_by` INTEGER NULL DEFAULT NULL,
                  `performer_role` VARCHAR(20) NOT NULL DEFAULT 'seller',
                  `created_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP
                );
            ");
            $pdo->exec("CREATE INDEX IF NOT EXISTS `idx_gstlog_seller` ON `seller_gst_verification_logs` (`seller_id`);");
            $pdo->exec("CREATE INDEX IF NOT EXISTS `idx_gstlog_gst` ON `seller_gst_verification_logs` (`gst_id`);");
        } else {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `seller_gst_details` (
                  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                  `seller_id` BIGINT UNSIGNED NOT NULL,
                  `gstin` VARCHAR(30) NULL DEFAULT NULL,
                  `business_name` VARCHAR(255) NULL DEFAULT NULL,
                  `business_address` TEXT NULL DEFAULT NULL,
                  `state` VARCHAR(100) NULL DEFAULT NULL,
                  `pincode` VARCHAR(20) NULL DEFAULT NULL,
                  `status` VARCHAR(30) NOT NULL DEFAULT 'Pending',
                  `rejection_reason` TEXT NULL DEFAULT NULL,
                  `verified_by` BIGINT UNSIGNED NULL DEFAULT NULL,
                  `verified_at` TIMESTAMP NULL DEFAULT NULL,
                  `submitted_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `uniq_gst_seller` (`seller_id`),
                  INDEX `idx_gst_status` (`status`),
                  INDEX `idx_gst_gstin` (`gstin`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");

            try {
                $pdo->exec("
                    ALTER TABLE `seller_gst_details`
                    MODIFY `gstin` VARCHAR(30) NULL DEFAULT NULL,
                    MODIFY `business_name` VARCHAR(255) NULL DEFAULT NULL,
                    MODIFY `business_address` TEXT NULL DEFAULT NULL,
                    MODIFY `state` VARCHAR(100) NULL DEFAULT NULL,
                    MODIFY `pincode` VARCHAR(20) NULL DEFAULT NULL;
                ");
            } catch (Throwable $ignoreAlter) {
                // Already altered or non-critical
            }

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `seller_gst_documents` (
                  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                  `seller_id` BIGINT UNSIGNED NOT NULL,
                  `gst_id` BIGINT UNSIGNED NULL DEFAULT NULL,
                  `document_type` VARCHAR(50) NOT NULL DEFAULT 'gst_certificate',
                  `original_name` VARCHAR(255) NOT NULL,
                  `file_name` VARCHAR(255) NOT NULL,
                  `file_path` VARCHAR(255) NOT NULL,
                  `file_size` BIGINT UNSIGNED NOT NULL,
                  `mime_type` VARCHAR(100) NOT NULL,
                  `is_primary` TINYINT(1) NOT NULL DEFAULT 1,
                  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  INDEX `idx_gstdoc_seller` (`seller_id`),
                  INDEX `idx_gstdoc_gst` (`gst_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `seller_gst_verification_logs` (
                  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                  `seller_id` BIGINT UNSIGNED NOT NULL,
                  `gst_id` BIGINT UNSIGNED NULL DEFAULT NULL,
                  `action` VARCHAR(40) NOT NULL,
                  `previous_status` VARCHAR(30) NULL DEFAULT NULL,
                  `new_status` VARCHAR(30) NOT NULL,
                  `notes` TEXT NULL DEFAULT NULL,
                  `performed_by` BIGINT UNSIGNED NULL DEFAULT NULL,
                  `performer_role` VARCHAR(20) NOT NULL DEFAULT 'seller',
                  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  INDEX `idx_gstlog_seller` (`seller_id`),
                  INDEX `idx_gstlog_gst` (`gst_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
        }

        $ensured = true;
    } catch (Throwable $e) {
        error_log('[GST Helper] Ensure tables error: ' . $e->getMessage());
    }
}

function getGstStorageDir(): string
{
    $candidates = [
        '/home/u999617803/domains/knowlipop.shop/venter_secure_storage/gst',
        '/home/u999617803/venter_secure_storage/gst',
        dirname(__DIR__) . '/uploads/secure_documents/gst'
    ];
    foreach ($candidates as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            $htaccess = dirname($dir) . '/.htaccess';
            if (!file_exists($htaccess)) {
                @file_put_contents($htaccess, "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n");
            }
            return $dir;
        }
    }
    $fallback = dirname(__DIR__) . '/uploads/secure_documents/gst';
    if (!is_dir($fallback)) {
        @mkdir($fallback, 0755, true);
    }
    return $fallback;
}

function resolveGstFilePathPhp(array $doc): ?string
{
    $fileName = $doc['file_name'] ?? (!empty($doc['file_path']) ? basename($doc['file_path']) : null);
    $candidates = array_filter([
        $doc['file_path'] ?? null,
        $fileName ? getGstStorageDir() . '/' . $fileName : null,
        $fileName ? '/home/u999617803/domains/knowlipop.shop/venter_secure_storage/gst/' . $fileName : null,
        $fileName ? '/home/u999617803/venter_secure_storage/gst/' . $fileName : null,
        $fileName ? dirname(__DIR__) . '/uploads/secure_documents/gst/' . $fileName : null
    ]);
    foreach ($candidates as $p) {
        if (is_file($p)) {
            return $p;
        }
    }
    return null;
}

/**
 * Validates Indian GSTIN (15-character format)
 * Pattern: 2 digits + 5 uppercase letters + 4 digits + 1 letter + 1 char + Z + 1 char
 */
function validateGstin(string $gstin): bool
{
    $clean = strtoupper(trim($gstin));
    if (strlen($clean) !== 15) {
        return false;
    }
    return (bool) preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/', $clean);
}

/**
 * Checks if file is allowed PDF, JPG, or PNG
 */
function isAllowedGstFile(string $originalName, string $mimeType): bool
{
    $allowedExts = ['pdf', 'jpg', 'jpeg', 'png'];
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExts, true)) {
        return false;
    }

    $allowedMimes = [
        'application/pdf',
        'image/jpeg',
        'image/jpg',
        'image/pjpeg',
        'image/png',
        'image/x-png',
        'application/octet-stream' // fallback for some browsers uploading pdf
    ];

    return in_array(strtolower($mimeType), $allowedMimes, true);
}

/**
 * Log GST Verification Action into audit trail
 */
function logGstVerification(
    PDO $pdo,
    int $sellerId,
    ?int $gstId,
    string $action,
    ?string $previousStatus,
    string $newStatus,
    ?string $notes = null,
    ?int $performedBy = null,
    string $performerRole = 'seller'
): void {
    ensureGstTables($pdo);
    try {
        $stmt = $pdo->prepare("
            INSERT INTO seller_gst_verification_logs
            (seller_id, gst_id, action, previous_status, new_status, notes, performed_by, performer_role, created_at)
            VALUES (:seller_id, :gst_id, :action, :previous_status, :new_status, :notes, :performed_by, :performer_role, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            ':seller_id' => $sellerId,
            ':gst_id' => $gstId,
            ':action' => $action,
            ':previous_status' => $previousStatus,
            ':new_status' => $newStatus,
            ':notes' => $notes,
            ':performed_by' => $performedBy,
            ':performer_role' => $performerRole
        ]);
    } catch (Throwable $e) {
        error_log('[GST Helper] Log error: ' . $e->getMessage());
    }
}

/**
 * Format document array with secure preview & download API links
 */
function formatGstDocument(array $doc, bool $isAdmin = false): array
{
    $docId = (int) $doc['id'];
    $baseUrl = (getenv('APP_URL') ?: 'https://knowlipop.shop');
    $baseUrl = rtrim($baseUrl, '/');

    $previewPath = $isAdmin
        ? "/api/admin/sellers/gst/documents/{$docId}/preview"
        : "/api/seller/gst/documents/{$docId}/preview";
    $downloadPath = $isAdmin
        ? "/api/admin/sellers/gst/documents/{$docId}/download"
        : "/api/seller/gst/documents/{$docId}/download";

    return [
        'id' => $docId,
        'seller_id' => (int) $doc['seller_id'],
        'gst_id' => !empty($doc['gst_id']) ? (int) $doc['gst_id'] : null,
        'document_type' => $doc['document_type'],
        'original_name' => $doc['original_name'],
        'file_name' => $doc['file_name'],
        'file_size' => (int) $doc['file_size'],
        'file_size_formatted' => round($doc['file_size'] / 1024, 1) . ' KB',
        'mime_type' => $doc['mime_type'],
        'is_pdf' => (strtolower($doc['mime_type']) === 'application/pdf' || str_ends_with(strtolower($doc['original_name']), '.pdf')),
        'is_primary' => (bool) $doc['is_primary'],
        'preview_url' => $baseUrl . $previewPath,
        'download_url' => $baseUrl . $downloadPath,
        'created_at' => $doc['created_at'],
        'updated_at' => $doc['updated_at'] ?? $doc['created_at'],
    ];
}

