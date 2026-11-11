<?php

/**
 * Pure PHP API: Admin Seller Deletion & Refund Management Controller
 */

require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/seller_deletion.php';

function authenticateAdminForDeletionPhp(PDO $pdo): array
{
    $user = authenticateUser($pdo);
    if (strtolower((string) ($user['role'] ?? '')) !== 'admin') {
        errorResponse('Forbidden. Administrator privileges are required for seller deletion operations.', [], 403);
    }
    return $user;
}

function apiAdminListSellerDeletions(PDO $pdo): void
{
    authenticateAdminForDeletionPhp($pdo);
    $filters = [
        'status' => $_GET['status'] ?? '',
        'stage' => $_GET['stage'] ?? '',
        'search' => $_GET['search'] ?? $_GET['q'] ?? '',
    ];
    $res = deletionListRequestsPhp($pdo, $filters);
    jsonResponse(array_merge(['status' => 'success'], $res));
}

function apiAdminSellerDeletionPreflight(PDO $pdo, int $sellerId): void
{
    authenticateAdminForDeletionPhp($pdo);
    $computed = computeSellerFinancialAndChecklistPhp($pdo, $sellerId, null);
    if (!empty($computed['error'])) {
        errorResponse($computed['error'], [], 404);
    }
    jsonResponse([
        'status' => 'success',
        'seller' => $computed['seller'],
        'metrics' => $computed['metrics'],
        'settlement' => $computed['settlement'],
        'checks' => $computed['checks'],
        'blockers' => $computed['blockers'],
    ]);
}

function apiAdminCreateSellerDeletion(PDO $pdo): void
{
    $admin = authenticateAdminForDeletionPhp($pdo);
    $input = getJsonInput();
    $sellerId = (int) ($input['seller_id'] ?? $input['id'] ?? 0);
    if ($sellerId <= 0) {
        errorResponse('Valid seller_id is required.', ['seller_id' => ['Valid seller_id is required.']], 422);
    }
    $reason = trim((string) ($input['reason'] ?? $input['request_reason'] ?? ''));
    $remarks = trim((string) ($input['remarks'] ?? $input['admin_remarks'] ?? ''));

    $res = deletionCreateRequestPhp($pdo, $sellerId, (int) $admin['id'], $reason, $remarks);
    if (!empty($res['error'])) {
        $extra = !empty($res['existing_request_id']) ? ['existing_request_id' => $res['existing_request_id']] : [];
        errorResponse($res['error'], $extra, (int) ($res['code'] ?? 400));
    }
    jsonResponse(array_merge([
        'status' => 'success',
        'message' => 'Seller deletion request created. Account entered DELETION_PENDING status and new orders are blocked.',
    ], $res['data']), $res['statusCode']);
}

function apiAdminGetSellerDeletionDetail(PDO $pdo, int $delReqId): void
{
    authenticateAdminForDeletionPhp($pdo);
    $data = buildDeletionWorkspacePayloadPhp($pdo, $delReqId);
    if (!$data) {
        errorResponse('Seller deletion request not found.', [], 404);
    }
    jsonResponse(array_merge(['status' => 'success'], $data));
}

function apiAdminLockSellerAccount(PDO $pdo, int $delReqId): void
{
    $admin = authenticateAdminForDeletionPhp($pdo);
    $input = getJsonInput();
    $remarks = trim((string) ($input['remarks'] ?? $input['admin_remarks'] ?? ''));
    $res = deletionLockAccountPhp($pdo, $delReqId, (int) $admin['id'], $remarks);
    if (!empty($res['error'])) {
        errorResponse($res['error'], [], (int) ($res['code'] ?? 400));
    }
    jsonResponse(array_merge(['status' => 'success', 'message' => 'Seller account locked.'], $res['data']), $res['statusCode']);
}

function apiAdminVerifySellerDeletionOrders(PDO $pdo, int $delReqId): void
{
    $admin = authenticateAdminForDeletionPhp($pdo);
    $input = getJsonInput();
    $res = deletionVerifyOrdersPhp($pdo, $delReqId, (int) $admin['id'], $input);
    if (!empty($res['error'])) {
        errorResponse($res['error'], [], (int) ($res['code'] ?? 400));
    }
    jsonResponse(array_merge(['status' => 'success', 'message' => 'Order verification completed.'], $res['data']), $res['statusCode']);
}

function apiAdminGetSellerDeletionRefunds(PDO $pdo, int $delReqId): void
{
    authenticateAdminForDeletionPhp($pdo);
    $data = buildDeletionWorkspacePayloadPhp($pdo, $delReqId);
    if (!$data) {
        errorResponse('Seller deletion request not found.', [], 404);
    }
    jsonResponse([
        'status' => 'success',
        'deletion_request_id' => $delReqId,
        'refunds' => $data['refunds'],
        'refund_stats' => $data['refund_stats'],
    ]);
}

function apiAdminProcessSellerDeletionRefunds(PDO $pdo, int $delReqId): void
{
    $admin = authenticateAdminForDeletionPhp($pdo);
    $input = getJsonInput();
    $remarks = trim((string) ($input['remarks'] ?? $input['admin_remarks'] ?? ''));
    $res = deletionProcessRefundsPhp($pdo, $delReqId, (int) $admin['id'], $remarks);
    if (!empty($res['error'])) {
        errorResponse($res['error'], [], (int) ($res['code'] ?? 400));
    }
    jsonResponse(array_merge(['status' => 'success', 'message' => 'Refund batch processed against payment gateway.'], $res['data']), $res['statusCode']);
}

function apiAdminVerifySellerDeletionSettlement(PDO $pdo, int $delReqId): void
{
    $admin = authenticateAdminForDeletionPhp($pdo);
    $input = getJsonInput();
    $remarks = trim((string) ($input['remarks'] ?? $input['admin_remarks'] ?? ''));
    $res = deletionVerifySettlementPhp($pdo, $delReqId, (int) $admin['id'], $remarks);
    if (!empty($res['error'])) {
        errorResponse($res['error'], [], (int) ($res['code'] ?? 400));
    }
    jsonResponse(array_merge(['status' => 'success', 'message' => 'Financial settlement verification completed.'], $res['data']), $res['statusCode']);
}

function apiAdminSettleSellerDeletionPayout(PDO $pdo, int $delReqId): void
{
    $admin = authenticateAdminForDeletionPhp($pdo);
    $input = getJsonInput();
    $res = deletionSettlePayoutPhp($pdo, $delReqId, (int) $admin['id'], $input);
    if (!empty($res['error'])) {
        errorResponse($res['error'], [], (int) ($res['code'] ?? 400));
    }
    jsonResponse(array_merge(['status' => 'success', 'message' => 'Seller payout settlement recorded.'], $res['data']), $res['statusCode']);
}

function apiAdminResolveSellerDeletionDispute(PDO $pdo, int $delReqId): void
{
    $admin = authenticateAdminForDeletionPhp($pdo);
    $input = getJsonInput();
    $res = deletionResolveDisputePhp($pdo, $delReqId, (int) $admin['id'], $input);
    if (!empty($res['error'])) {
        errorResponse($res['error'], [], (int) ($res['code'] ?? 400));
    }
    jsonResponse(array_merge(['status' => 'success', 'message' => 'Dispute resolved and checklist re-evaluated.'], $res['data']), $res['statusCode']);
}

function apiAdminApproveSellerDeletion(PDO $pdo, int $delReqId): void
{
    $admin = authenticateAdminForDeletionPhp($pdo);
    $input = getJsonInput();
    $remarks = trim((string) ($input['remarks'] ?? $input['admin_remarks'] ?? ''));
    $res = deletionApprovePhp($pdo, $delReqId, (int) $admin['id'], $remarks);
    if (!empty($res['error'])) {
        $extra = !empty($res['failing_checks']) ? ['failing_checks' => $res['failing_checks']] : [];
        errorResponse($res['error'], $extra, (int) ($res['code'] ?? 400));
    }
    jsonResponse(array_merge(['status' => 'success', 'message' => 'Seller deletion approved. Explicit second confirmation required to finalize.'], $res['data']), $res['statusCode']);
}

function apiAdminRejectSellerDeletion(PDO $pdo, int $delReqId): void
{
    $admin = authenticateAdminForDeletionPhp($pdo);
    $input = getJsonInput();
    $reason = trim((string) ($input['reason'] ?? $input['rejection_reason'] ?? $input['remarks'] ?? ''));
    $remarks = trim((string) ($input['remarks'] ?? $input['admin_remarks'] ?? ''));
    $res = deletionRejectPhp($pdo, $delReqId, (int) $admin['id'], $reason, $remarks);
    if (!empty($res['error'])) {
        errorResponse($res['error'], [], (int) ($res['code'] ?? 400));
    }
    jsonResponse(array_merge(['status' => 'success', 'message' => 'Seller deletion request rejected. Seller account restored to ACTIVE.'], $res['data']), $res['statusCode']);
}

function apiAdminHoldSellerDeletion(PDO $pdo, int $delReqId): void
{
    $admin = authenticateAdminForDeletionPhp($pdo);
    $input = getJsonInput();
    $reason = trim((string) ($input['reason'] ?? $input['hold_reason'] ?? $input['remarks'] ?? ''));
    $remarks = trim((string) ($input['remarks'] ?? $input['admin_remarks'] ?? ''));
    $res = deletionHoldPhp($pdo, $delReqId, (int) $admin['id'], $reason, $remarks);
    if (!empty($res['error'])) {
        errorResponse($res['error'], [], (int) ($res['code'] ?? 400));
    }
    jsonResponse(array_merge(['status' => 'success', 'message' => 'Seller deletion request placed ON HOLD.'], $res['data']), $res['statusCode']);
}

function apiAdminFinalDeleteSeller(PDO $pdo, int $delReqId): void
{
    $admin = authenticateAdminForDeletionPhp($pdo);
    $input = getJsonInput();
    $res = deletionFinalDeletePhp($pdo, $delReqId, (int) $admin['id'], $input);
    if (!empty($res['error'])) {
        errorResponse($res['error'], [], (int) ($res['code'] ?? 400));
    }
    jsonResponse(array_merge(['status' => 'success', 'message' => 'Seller account permanently soft-deleted. Financial and order records preserved.'], $res['data']), $res['statusCode']);
}

function apiOrderPaymentVerify(PDO $pdo, mixed $orderIdOrNum): void
{
    $actor = getAuthenticatedUserOptional($pdo);
    $input = getJsonInput();
    $res = recordOrderPaymentCapturePhp($pdo, $orderIdOrNum, $input, $actor);
    if (!empty($res['error'])) {
        errorResponse($res['error'], [], (int) ($res['code'] ?? 400));
    }
    jsonResponse($res['data'], $res['statusCode']);
}

function apiOrderCreateDispute(PDO $pdo, mixed $orderIdOrNum): void
{
    ensureSellerDeletionSchemaPhp($pdo);
    $input = getJsonInput();
    $actor = getAuthenticatedUserOptional($pdo);
    if (is_numeric($orderIdOrNum)) {
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = :id OR order_number = :num LIMIT 1");
        $stmt->execute([':id' => (int) $orderIdOrNum, ':num' => (string) $orderIdOrNum]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE order_number = :num LIMIT 1");
        $stmt->execute([':num' => (string) $orderIdOrNum]);
    }
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        errorResponse('Order not found.', [], 404);
    }

    $suppliedPhone = trim((string) ($input['buyer_phone'] ?? ''));
    $isAdmin = $actor && (($actor['role'] ?? '') === 'admin');
    $isSeller = $actor && (($actor['role'] ?? '') === 'seller');
    if (!$isAdmin && !$isSeller) {
        if ($suppliedPhone === '' || $suppliedPhone !== trim((string) ($order['buyer_phone'] ?? ''))) {
            errorResponse('Unauthorized. Admin/Seller authentication or matching buyer_phone is required to create a dispute.', [], 401);
        }
    }
    $buyerPhone = $suppliedPhone !== '' ? $suppliedPhone : trim((string) ($order['buyer_phone'] ?? ''));

    $sellerId = !empty($input['seller_id']) ? (int) $input['seller_id'] : (int) ($order['seller_id'] ?? 0);
    if ($sellerId <= 0) {
        $oiStmt = $pdo->prepare("SELECT seller_id FROM order_items WHERE order_id = :oid LIMIT 1");
        $oiStmt->execute([':oid' => (int) $order['id']]);
        $sellerId = (int) ($oiStmt->fetchColumn() ?: 0);
    }

    $disputeType = trim((string) ($input['dispute_type'] ?? 'complaint')) ?: 'complaint';
    $claimAmount = isset($input['claim_amount']) && is_numeric($input['claim_amount']) ? round((float) $input['claim_amount'], 2) : round((float) ($order['total_amount'] ?? 0), 2);
    $description = trim((string) ($input['description'] ?? $input['reason'] ?? 'Customer dispute raised'));

    $ins = $pdo->prepare("
        INSERT INTO order_disputes (order_id, seller_id, buyer_phone, dispute_type, status, claim_amount, description, created_at, updated_at)
        VALUES (:oid, :sid, :bp, :dt, 'OPEN', :ca, :desc, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
    ");
    $ins->execute([
        ':oid' => (int) $order['id'],
        ':sid' => $sellerId,
        ':bp' => $order['buyer_phone'] ?? $buyerPhone,
        ':dt' => $disputeType,
        ':ca' => $claimAmount,
        ':desc' => $description,
    ]);
    $disputeId = (int) $pdo->lastInsertId();

    jsonResponse([
        'status' => 'success',
        'message' => 'Order dispute created.',
        'dispute' => [
            'id' => $disputeId,
            'order_id' => (int) $order['id'],
            'seller_id' => $sellerId,
            'dispute_type' => $disputeType,
            'status' => 'OPEN',
            'claim_amount' => $claimAmount,
            'description' => $description,
        ],
    ], 201);
}
