<?php

/**
 * Pure PHP API: Payment & Commission Controller
 */

require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';

function apiBankOnboarding(PDO $pdo): void
{
    $user = authenticateUser($pdo);
    $input = getJsonInput();

    if (empty($input['account_reference'])) {
        errorResponse('account_reference is required.', ['account_reference' => ['account_reference is required.']], 422);
    }

    $ref = trim($input['account_reference']);
    $stmt = $pdo->prepare("
        INSERT INTO seller_payment_accounts (seller_id, provider, account_reference, status, created_at, updated_at)
        VALUES (:seller_id, 'razorpay', :ref, 'created', NOW(), NOW())
        ON DUPLICATE KEY UPDATE account_reference = :ref_upd, status = 'created', updated_at = NOW()
    ");
    $stmt->execute([
        ':seller_id' => $user['id'],
        ':ref' => $ref,
        ':ref_upd' => $ref,
    ]);

    jsonResponse([
        'message' => 'Bank account reference stored successfully.',
        'account' => [
            'provider' => 'razorpay',
            'account_reference' => $ref,
            'status' => 'created'
        ]
    ]);
}

function apiBankStatus(PDO $pdo): void
{
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
    authenticateUser($pdo);
    jsonResponse([
        'message' => 'Payout service integration is scheduled for live merchant phase.',
        'payouts' => []
    ], 501);
}

function apiCommissionPreview(PDO $pdo): void
{
    authenticateUser($pdo);
    $input = getJsonInput();

    if (!isset($input['amount_in_paise']) || !is_numeric($input['amount_in_paise'])) {
        errorResponse('amount_in_paise is required and must be numeric.', [], 422);
    }

    $amount = (int) $input['amount_in_paise'];
    $rate = 0.05; // 5% default marketplace commission
    $fee = (int) round($amount * $rate);
    $sellerReceives = $amount - $fee;

    jsonResponse([
        'order_amount_paise' => $amount,
        'commission_rate' => $rate,
        'commission_paise' => $fee,
        'seller_receives_paise' => $sellerReceives,
    ]);
}
