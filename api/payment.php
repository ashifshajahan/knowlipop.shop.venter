<?php

/**
 * Pure PHP API: Payment, Razorpay Gateway & Commission Controller
 *
 * Provides REST endpoints for:
 * - Razorpay order creation: POST /api/payment/razorpay/create-order, POST /api/payment/create-order
 * - Payment signature verification: POST /api/payment/razorpay/verify, POST /api/payment/verify
 * - Razorpay webhooks: POST /api/payment/webhook, POST /api/payment/razorpay/webhook
 * - Payment status checking: GET /api/payment/status/{id}, GET /api/payment/status
 * - Full & Partial refunds: POST /api/payment/refund, POST /api/orders/{id}/refund
 * - Payment failure reporting: POST /api/payment/failed
 * - Admin transaction management: GET /api/admin/payment-transactions, GET /api/admin/payment-transactions/{id}, POST /api/admin/payment-transactions/{id}/refund
 * - Seller bank account onboarding & status
 * - Seller payouts list & commission preview
 */

require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/seller_deletion.php';
require_once __DIR__ . '/../helpers/payment.php';
require_once __DIR__ . '/../helpers/notification.php';

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
    ensurePaymentSchemaPhp($pdo);
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
    $cfg = getRazorpayConfigPhp();
    $rate = $cfg['commission_rate'] ?: 0.05;
    $feePaise = (int) round($amountPaise * $rate);
    $netPaise = $amountPaise - $feePaise;

    jsonResponse([
        'order_amount_paise' => $amountPaise,
        'commission_rate' => $rate,
        'commission_paise' => $feePaise,
        'seller_receives_paise' => $netPaise,
        'net_payout' => (float) number_format($netPaise / 100, 2, '.', ''),
        'route_enabled' => $cfg['route_enabled'],
    ]);
}

/**
 * 1. Create Razorpay Order from backend
 * Route: POST /api/payment/razorpay/create-order or POST /api/payment/create-order or POST /api/orders/{id}/razorpay-order
 */
function apiRazorpayCreateOrder(PDO $pdo, mixed $urlOrderId = null): void
{
    $input = getJsonInput();
    $orderParam = $urlOrderId ?: ($input['order_id'] ?? ($input['order_number'] ?? null));

    if (empty($orderParam)) {
        errorResponse('order_id or order_number is required.', ['order_id' => ['order_id or order_number is required.']], 422);
    }

    $res = razorpayCreateOrderPhp($pdo, $orderParam);
    if (!$res['ok']) {
        errorResponse($res['error'] ?? 'Failed to create Razorpay order.', [], (int) ($res['statusCode'] ?? 400));
    }

    jsonResponse($res['data'], (int) ($res['statusCode'] ?? 200));
}

/**
 * 3. Verify Razorpay Payment Signature
 * Route: POST /api/payment/razorpay/verify or POST /api/payment/verify or POST /api/orders/{id}/payment-verify
 */
function apiRazorpayVerify(PDO $pdo, mixed $urlOrderId = null): void
{
    $input = getJsonInput();
    $orderParam = $urlOrderId ?: ($input['order_id'] ?? ($input['order_number'] ?? null));

    if (empty($orderParam)) {
        errorResponse('order_id or order_number is required for payment verification.', ['order_id' => ['order_id is required.']], 422);
    }

    $actorUser = getAuthenticatedUserOptional($pdo);
    $res = recordOrderPaymentVerificationPhp($pdo, $orderParam, $input, $actorUser);

    if (!$res['ok']) {
        errorResponse($res['error'] ?? 'Payment verification failed.', [], (int) ($res['statusCode'] ?? 400));
    }

    jsonResponse($res['data'], (int) ($res['statusCode'] ?? 200));
}

/**
 * 4. Razorpay Webhooks with Signature Verification & Idempotency
 * Route: POST /api/payment/webhook or POST /api/payment/razorpay/webhook
 */
function apiRazorpayWebhook(PDO $pdo): void
{
    $rawPayload = (string) file_get_contents('php://input');
    $headerSignature = (string) (
        $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ??
        ($_SERVER['REDIRECT_HTTP_X_RAZORPAY_SIGNATURE'] ??
        (function_exists('getallheaders') ? (getallheaders()['X-Razorpay-Signature'] ?? '') : ''))
    );

    if (trim($rawPayload) === '') {
        errorResponse('Empty webhook payload.', [], 400);
    }

    $res = processRazorpayWebhookPhp($pdo, $rawPayload, $headerSignature);
    if (!$res['ok']) {
        errorResponse($res['error'] ?? 'Webhook rejected.', [], (int) ($res['statusCode'] ?? 400));
    }

    jsonResponse($res['data'], (int) ($res['statusCode'] ?? 200));
}

/**
 * 9. Payment Status Checking API
 * Route: GET /api/payment/status/{id} or GET /api/payment/status?order_id=... or GET /api/orders/{id}/payment-status
 */
function apiPaymentStatus(PDO $pdo, mixed $urlOrderId = null): void
{
    $orderParam = $urlOrderId ?: ($_GET['order_id'] ?? ($_GET['id'] ?? ($_GET['order_number'] ?? null)));
    if (empty($orderParam)) {
        errorResponse('order_id or order_number parameter is required.', [], 422);
    }

    $syncWithGateway = !empty($_GET['sync']) || !empty($_GET['refresh']);
    $res = getPaymentStatusPhp($pdo, $orderParam, $syncWithGateway);

    if (!$res['ok']) {
        errorResponse($res['error'] ?? 'Could not retrieve payment status.', [], (int) ($res['statusCode'] ?? 404));
    }

    jsonResponse($res['data'], (int) ($res['statusCode'] ?? 200));
}

/**
 * 10. Full and Partial Refund API
 * Route: POST /api/payment/refund or POST /api/orders/{id}/refund
 */
function apiPaymentRefund(PDO $pdo, mixed $urlOrderId = null): void
{
    $user = authenticateUser($pdo);
    $input = getJsonInput();
    $orderParam = $urlOrderId ?: ($input['order_id'] ?? ($input['order_number'] ?? null));

    if (empty($orderParam)) {
        errorResponse('order_id or order_number is required.', ['order_id' => ['order_id is required.']], 422);
    }

    $amount = isset($input['amount']) && is_numeric($input['amount']) ? (float) $input['amount'] : null;
    $reason = trim((string) ($input['reason'] ?? ($input['notes'] ?? '')));

    $res = processPaymentRefundPhp($pdo, $orderParam, $amount, $reason, $user);
    if (!$res['ok']) {
        errorResponse($res['error'] ?? 'Refund failed.', [], (int) ($res['statusCode'] ?? 400));
    }

    jsonResponse($res['data'], (int) ($res['statusCode'] ?? 200));
}

/**
 * Client-reported payment failure notification
 * Route: POST /api/payment/failed
 */
function apiPaymentFailed(PDO $pdo): void
{
    ensurePaymentSchemaPhp($pdo);
    $input = getJsonInput();
    $orderParam = $input['order_id'] ?? ($input['order_number'] ?? null);

    if ($orderParam) {
        $stmt = is_numeric($orderParam)
            ? $pdo->prepare("SELECT id, payment_status FROM orders WHERE id = :id LIMIT 1")
            : $pdo->prepare("SELECT id, payment_status FROM orders WHERE order_number = :num LIMIT 1");
        $stmt->execute(is_numeric($orderParam) ? [':id' => (int) $orderParam] : [':num' => (string) $orderParam]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($order && strtolower((string) ($order['payment_status'] ?? '')) !== 'paid') {
            $errCode = $input['error']['code'] ?? 'FRONTEND_FAILURE';
            $errDesc = $input['error']['description'] ?? ($input['message'] ?? 'Payment failed on checkout.');

            $pdo->prepare("
                UPDATE orders
                SET payment_status = CASE WHEN payment_status = 'unpaid' THEN 'failed' ELSE payment_status END,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ")->execute([':id' => $order['id']]);

            try {
                $pdo->prepare("
                    INSERT INTO payment_transactions
                        (order_id, gateway, transaction_type, amount, status, error_code, error_description, gateway_payload, created_at, updated_at)
                    VALUES
                        (:oid, 'razorpay', 'client_failure', 0.00, 'failed', :ecode, :edesc, :payload, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                ")->execute([
                    ':oid' => $order['id'],
                    ':ecode' => $errCode,
                    ':edesc' => $errDesc,
                    ':payload' => json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                ]);
            } catch (Throwable $e) {}

            logPaymentEventPhp('client_payment_failed', ['order_id' => $order['id'], 'error' => $errDesc]);

            if (function_exists('dispatchOrderNotificationPhp')) {
                try {
                    dispatchOrderNotificationPhp($pdo, (int)$order['id'], 'payment_failed', [
                        'error_description' => $errDesc
                    ]);
                } catch (Throwable $e) {}
            }
        }
    }

    jsonResponse(['success' => true, 'message' => 'Payment failure recorded.']);
}

/**
 * 13. Admin Payment Transaction Management List
 * Route: GET /api/admin/payment-transactions or GET /api/admin/payments
 */
function apiAdminPaymentTransactions(PDO $pdo): void
{
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    if (empty($_SESSION['admin_logged_in'])) {
        $u = authenticateUser($pdo);
        if (strtolower((string) ($u['role'] ?? '')) !== 'admin') {
            errorResponse('Unauthorized. Admin access required.', [], 403);
        }
    }

    $res = getAdminPaymentTransactionsPhp($pdo, $_GET);
    jsonResponse($res['data'], 200);
}

/**
 * 13. Admin Payment Transaction Detail
 * Route: GET /api/admin/payment-transactions/{id}
 */
function apiAdminPaymentTransactionDetail(PDO $pdo, int $txId): void
{
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    if (empty($_SESSION['admin_logged_in'])) {
        $u = authenticateUser($pdo);
        if (strtolower((string) ($u['role'] ?? '')) !== 'admin') {
            errorResponse('Unauthorized. Admin access required.', [], 403);
        }
    }

    ensurePaymentSchemaPhp($pdo);

    $stmt = $pdo->prepare("
        SELECT pt.*,
               o.order_number, o.buyer_name AS order_buyer_name, o.buyer_phone AS order_buyer_phone,
               o.order_status, o.payment_status AS order_payment_status, o.total_amount AS order_total_amount,
               o.captured_amount AS order_captured_amount, o.refunded_amount AS order_refunded_amount,
               u.seller_name, u.name AS seller_user_name, u.email AS seller_email, u.phone AS seller_phone
        FROM payment_transactions pt
        LEFT JOIN orders o ON o.id = pt.order_id
        LEFT JOIN users u ON u.id = pt.seller_id
        WHERE pt.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $txId]);
    $tx = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tx) {
        errorResponse('Payment transaction record not found.', [], 404);
    }

    $payloadDecoded = null;
    if (!empty($tx['gateway_payload'])) {
        $payloadDecoded = json_decode($tx['gateway_payload'], true);
    }

    jsonResponse([
        'success' => true,
        'transaction' => [
            'id' => (int) $tx['id'],
            'order_id' => (int) $tx['order_id'],
            'order_number' => $tx['order_number'],
            'seller_id' => !empty($tx['seller_id']) ? (int) $tx['seller_id'] : null,
            'seller_name' => $tx['seller_name'] ?: ($tx['seller_user_name'] ?: ('Seller #' . ($tx['seller_id'] ?? ''))),
            'seller_phone' => $tx['seller_phone'] ?? '',
            'seller_email' => $tx['seller_email'] ?? '',
            'buyer_name' => $tx['buyer_name'] ?: ($tx['order_buyer_name'] ?: 'Guest Buyer'),
            'buyer_phone' => $tx['buyer_phone'] ?: ($tx['order_buyer_phone'] ?: '—'),
            'gateway' => $tx['gateway'],
            'gateway_order_id' => $tx['gateway_order_id'],
            'gateway_payment_id' => $tx['gateway_payment_id'],
            'refund_id' => $tx['refund_id'],
            'transaction_type' => $tx['transaction_type'],
            'amount' => (float) $tx['amount'],
            'amount_formatted' => '₹' . number_format((float) $tx['amount'], 2),
            'currency' => $tx['currency'],
            'status' => $tx['status'],
            'commission_rate' => (float) ($tx['commission_rate'] ?? 0.05),
            'commission_amount' => (float) ($tx['commission_amount'] ?? 0.0),
            'net_seller_amount' => (float) ($tx['net_seller_amount'] ?? 0.0),
            'error_code' => $tx['error_code'],
            'error_description' => $tx['error_description'],
            'settled_at' => $tx['settled_at'],
            'created_at' => $tx['created_at'],
            'updated_at' => $tx['updated_at'],
            'order' => [
                'order_status' => $tx['order_status'],
                'payment_status' => $tx['order_payment_status'],
                'total_amount' => (float) $tx['order_total_amount'],
                'captured_amount' => (float) ($tx['order_captured_amount'] ?? 0),
                'refunded_amount' => (float) ($tx['order_refunded_amount'] ?? 0),
            ],
            'payload' => $payloadDecoded,
        ]
    ]);
}

/**
 * 13. Admin Process Refund on a Payment Transaction
 * Route: POST /api/admin/payment-transactions/{id}/refund
 */
function apiAdminPaymentTransactionRefund(PDO $pdo, int $txId): void
{
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    $adminUser = null;
    if (!empty($_SESSION['admin_logged_in'])) {
        $adminUser = ['id' => $_SESSION['admin_id'] ?? 1, 'role' => 'admin'];
    } else {
        $adminUser = authenticateUser($pdo);
        if (strtolower((string) ($adminUser['role'] ?? '')) !== 'admin') {
            errorResponse('Unauthorized. Admin access required.', [], 403);
        }
    }

    ensurePaymentSchemaPhp($pdo);

    $stmt = $pdo->prepare("SELECT order_id FROM payment_transactions WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $txId]);
    $orderId = $stmt->fetchColumn();

    if (!$orderId) {
        errorResponse('Payment transaction record not found.', [], 404);
    }

    $input = getJsonInput();
    $amount = isset($input['amount']) && is_numeric($input['amount']) ? (float) $input['amount'] : null;
    $reason = trim((string) ($input['reason'] ?? 'Admin issued refund'));

    $res = processPaymentRefundPhp($pdo, (int) $orderId, $amount, $reason, $adminUser);
    if (!$res['ok']) {
        errorResponse($res['error'] ?? 'Refund could not be processed.', [], (int) ($res['statusCode'] ?? 400));
    }

    jsonResponse($res['data'], 200);
}
