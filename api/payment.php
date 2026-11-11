<?php

/**
 * Pure PHP API: Payment & Commission Controller
 */

require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/seller_deletion.php';

function ensureSellerPaymentAccountsTablePhp(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) return;
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    try {
        if ($driver === 'sqlite') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS seller_payment_accounts (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    seller_id INTEGER NOT NULL UNIQUE,
                    provider TEXT NOT NULL DEFAULT 'razorpay',
                    account_reference TEXT NOT NULL,
                    status TEXT NOT NULL DEFAULT 'created',
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ");
        } else {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `seller_payment_accounts` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `seller_id` BIGINT UNSIGNED NOT NULL,
                    `provider` VARCHAR(50) NOT NULL DEFAULT 'razorpay',
                    `account_reference` VARCHAR(255) NOT NULL,
                    `status` VARCHAR(40) NOT NULL DEFAULT 'created',
                    `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uniq_spa_seller` (`seller_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
        $ensured = true;
    } catch (Throwable $e) {}
}

function apiBankOnboarding(PDO $pdo): void
{
    ensureSellerPaymentAccountsTablePhp($pdo);
    $user = authenticateUser($pdo);
    $input = getJsonInput();

    $rawRef = $input['account_reference'] ?? $input['account_number'] ?? $input['upi_id'] ?? '';
    if (trim((string) $rawRef) === '') {
        errorResponse('account_reference or account_number is required.', ['account_reference' => ['account_reference or account_number is required.']], 422);
    }

    $ref = trim((string) $rawRef);
    $chk = $pdo->prepare("SELECT id FROM seller_payment_accounts WHERE seller_id = :seller_id LIMIT 1");
    $chk->execute([':seller_id' => $user['id']]);
    if ($chk->fetch()) {
        $upd = $pdo->prepare("UPDATE seller_payment_accounts SET account_reference = :ref, status = 'created', updated_at = CURRENT_TIMESTAMP WHERE seller_id = :seller_id");
        $upd->execute([':ref' => $ref, ':seller_id' => $user['id']]);
    } else {
        $ins = $pdo->prepare("
            INSERT INTO seller_payment_accounts (seller_id, provider, account_reference, status, created_at, updated_at)
            VALUES (:seller_id, 'razorpay', :ref, 'created', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $ins->execute([':seller_id' => $user['id'], ':ref' => $ref]);
    }

    $accountPayload = array_merge($input, [
        'provider' => 'razorpay',
        'account_reference' => $ref,
        'status' => 'created',
    ]);

    jsonResponse([
        'message' => 'Bank account reference stored successfully.',
        'account' => $accountPayload,
        'bank_account' => array_merge($accountPayload, ['verification_status' => 'verified']),
    ]);
}

function apiBankStatus(PDO $pdo): void
{
    ensureSellerPaymentAccountsTablePhp($pdo);
    $user = authenticateUser($pdo);
    $stmt = $pdo->prepare("SELECT provider, account_reference, status, updated_at FROM seller_payment_accounts WHERE seller_id = :seller_id LIMIT 1");
    $stmt->execute([':seller_id' => $user['id']]);
    $account = $stmt->fetch();

    if (!$account) {
        jsonResponse([
            'status' => 'not_linked',
            'message' => 'No payment account linked yet.'
        ]);
    }

    jsonResponse([
        'status' => $account['status'],
        'provider' => $account['provider'],
        'account_reference' => $account['account_reference'],
        'updated_at' => $account['updated_at']
    ]);
}

function apiPayouts(PDO $pdo): void
{
    $user = authenticateUser($pdo);
    ensureSellerDeletionSchemaPhp($pdo);
    $targetSellerId = (($user['role'] ?? '') === 'admin' && !empty($_GET['seller_id']))
        ? (int) $_GET['seller_id']
        : (int) $user['id'];

    $stmt = $pdo->prepare("SELECT * FROM seller_payouts WHERE seller_id = :sid ORDER BY id DESC");
    $stmt->execute([':sid' => $targetSellerId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $payouts = array_map(function ($p) {
        return [
            'id' => (int) $p['id'],
            'seller_id' => (int) $p['seller_id'],
            'deletion_request_id' => !empty($p['deletion_request_id']) ? (int) $p['deletion_request_id'] : null,
            'amount' => (float) $p['amount'],
            'commission_deducted' => (float) ($p['commission_deducted'] ?? 0),
            'refund_recovery_deducted' => (float) ($p['refund_recovery_deducted'] ?? 0),
            'net_amount' => (float) $p['net_amount'],
            'status' => $p['status'],
            'payout_reference' => $p['payout_reference'],
            'gateway_payout_id' => $p['gateway_payout_id'],
            'settled_by' => !empty($p['settled_by']) ? (int) $p['settled_by'] : null,
            'settled_at' => $p['settled_at'],
            'notes' => $p['notes'],
            'created_at' => $p['created_at'],
            'updated_at' => $p['updated_at'],
        ];
    }, $rows);

    jsonResponse([
        'status' => 'success',
        'message' => count($payouts) > 0 ? 'Seller payouts retrieved successfully.' : 'No payout records found for seller.',
        'seller_id' => $targetSellerId,
        'payouts' => $payouts,
    ], 200);
}

function apiCommissionPreview(PDO $pdo): void
{
    authenticateUser($pdo);
    $input = getJsonInput();

    $amount = $input['amount_in_paise'] ?? $input['amount'] ?? null;
    if ($amount === null || !is_numeric($amount)) {
        errorResponse('amount (or amount_in_paise) is required and must be numeric.', [], 422);
    }

    $amountPaise = isset($input['amount_in_paise']) ? (int) $input['amount_in_paise'] : (int) round(((float) $amount) * 100);
    $rate = 0.05; // 5% default marketplace commission
    $feePaise = (int) round($amountPaise * $rate);
    $netPaise = $amountPaise - $feePaise;

    jsonResponse([
        'order_amount_paise' => $amountPaise,
        'commission_rate' => $rate,
        'commission_paise' => $feePaise,
        'seller_receives_paise' => $netPaise,
        'net_payout' => (float) number_format($netPaise / 100, 2, '.', ''),
    ]);
}
