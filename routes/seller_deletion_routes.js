/**
 * Express Router for Seller Account Deletion, Refund Management & Settlement Verification
 * Parity with api/seller_deletion.php & index.php routes.
 */

const express = require('express');
const {
  deletionListRequestsNode,
  deletionPreflightNode,
  deletionCreateRequestNode,
  buildDeletionWorkspacePayloadNode,
  deletionLockAccountNode,
  deletionVerifyOrdersNode,
  deletionProcessRefundsNode,
  deletionVerifySettlementNode,
  deletionSettlePayoutNode,
  deletionResolveDisputeNode,
  deletionHoldNode,
  deletionRejectNode,
  deletionApproveNode,
  deletionFinalDeleteNode,
  recordOrderPaymentCaptureNode,
  createOrderDisputeNode
} = require('../helpers/seller_deletion');

function sendErrorJson(res, statusCode, message, extras = {}) {
  const payload = { message };
  if (extras && Object.keys(extras).length > 0) {
    payload.errors = extras;
    Object.assign(payload, extras);
  }
  return res.status(statusCode).json(payload);
}

function getClientIp(req) {
  return (req.headers['x-forwarded-for'] && String(req.headers['x-forwarded-for']).split(',')[0].trim())
    || req.socket?.remoteAddress
    || req.ip
    || null;
}

function createSellerDeletionRouter({
  getPool,
  deletionCtx,
  authenticateToken,
  requireAdminRole,
  resolveOptionalUserAsync
}) {
  const router = express.Router();

  // 1. GET /api/admin/seller-deletions
  router.get('/api/admin/seller-deletions', authenticateToken, requireAdminRole, async (req, res) => {
    try {
      const filters = {
        status: req.query.status || '',
        stage: req.query.stage || '',
        search: req.query.search || req.query.q || ''
      };
      const out = await deletionListRequestsNode(getPool(), deletionCtx, filters);
      return res.json({ status: 'success', ...out });
    } catch (err) {
      return sendErrorJson(res, 500, `Failed to list deletion requests: ${err.message}`);
    }
  });

  // 2. GET /api/admin/seller-deletions/preflight/:seller_id
  router.get('/api/admin/seller-deletions/preflight/:seller_id', authenticateToken, requireAdminRole, async (req, res) => {
    const sellerId = parseInt(req.params.seller_id, 10);
    if (!sellerId || sellerId <= 0) {
      return sendErrorJson(res, 422, 'Valid seller_id is required.');
    }
    try {
      const result = await deletionPreflightNode(getPool(), deletionCtx, sellerId);
      if (result.error) {
        return sendErrorJson(res, result.code || 404, result.error);
      }
      return res.status(result.statusCode || 200).json({ status: 'success', ...result.data });
    } catch (err) {
      return sendErrorJson(res, 500, `Failed to compute deletion preflight: ${err.message}`);
    }
  });

  // 3. POST /api/admin/seller-deletions
  router.post('/api/admin/seller-deletions', authenticateToken, requireAdminRole, async (req, res) => {
    const body = req.body || {};
    const sellerId = parseInt(body.seller_id || body.id || 0, 10);
    if (!sellerId || sellerId <= 0) {
      return sendErrorJson(res, 422, 'Valid seller_id is required.', { seller_id: ['Valid seller_id is required.'] });
    }
    const reason = String(body.reason || body.request_reason || '').trim();
    const remarks = String(body.remarks || body.admin_remarks || '').trim();
    try {
      const result = await deletionCreateRequestNode(
        getPool(),
        deletionCtx,
        sellerId,
        Number(req.user.id),
        reason,
        remarks,
        getClientIp(req)
      );
      if (result.error) {
        const extras = result.existing_request_id ? { existing_request_id: result.existing_request_id } : {};
        return sendErrorJson(res, result.code || 400, result.error, extras);
      }
      return res.status(result.statusCode || 201).json({
        status: 'success',
        message: 'Seller deletion request created. Account entered DELETION_PENDING status and new orders are blocked.',
        ...result.data
      });
    } catch (err) {
      return sendErrorJson(res, 500, `Failed to create deletion request: ${err.message}`);
    }
  });

  // 4. GET /api/admin/seller-deletions/:id
  router.get('/api/admin/seller-deletions/:id', authenticateToken, requireAdminRole, async (req, res) => {
    const delReqId = parseInt(req.params.id, 10);
    if (!delReqId || delReqId <= 0) {
      return sendErrorJson(res, 404, 'Seller deletion request not found.');
    }
    try {
      const data = await buildDeletionWorkspacePayloadNode(getPool(), deletionCtx, delReqId);
      if (!data) {
        return sendErrorJson(res, 404, 'Seller deletion request not found.');
      }
      return res.json({ status: 'success', ...data });
    } catch (err) {
      return sendErrorJson(res, 500, `Failed to load deletion details: ${err.message}`);
    }
  });

  // 5. POST /api/admin/seller-deletions/:id/lock
  router.post('/api/admin/seller-deletions/:id/lock', authenticateToken, requireAdminRole, async (req, res) => {
    const delReqId = parseInt(req.params.id, 10);
    if (!delReqId || delReqId <= 0) return sendErrorJson(res, 404, 'Deletion request not found.');
    const body = req.body || {};
    const remarks = String(body.remarks || body.admin_remarks || '').trim();
    try {
      const result = await deletionLockAccountNode(getPool(), deletionCtx, delReqId, Number(req.user.id), remarks, getClientIp(req));
      if (result.error) return sendErrorJson(res, result.code || 400, result.error);
      return res.status(result.statusCode || 200).json({ status: 'success', message: 'Seller account locked.', ...result.data });
    } catch (err) {
      return sendErrorJson(res, 500, `Failed to lock seller account: ${err.message}`);
    }
  });

  // 6. POST /api/admin/seller-deletions/:id/verify-orders
  router.post('/api/admin/seller-deletions/:id/verify-orders', authenticateToken, requireAdminRole, async (req, res) => {
    const delReqId = parseInt(req.params.id, 10);
    if (!delReqId || delReqId <= 0) return sendErrorJson(res, 404, 'Deletion request not found.');
    try {
      const result = await deletionVerifyOrdersNode(getPool(), deletionCtx, delReqId, Number(req.user.id), req.body || {}, getClientIp(req));
      if (result.error) return sendErrorJson(res, result.code || 400, result.error);
      return res.status(result.statusCode || 200).json({ status: 'success', message: 'Order verification completed.', ...result.data });
    } catch (err) {
      return sendErrorJson(res, 500, `Failed to verify orders: ${err.message}`);
    }
  });

  // 7. GET /api/admin/seller-deletions/:id/refunds
  router.get('/api/admin/seller-deletions/:id/refunds', authenticateToken, requireAdminRole, async (req, res) => {
    const delReqId = parseInt(req.params.id, 10);
    if (!delReqId || delReqId <= 0) return sendErrorJson(res, 404, 'Seller deletion request not found.');
    try {
      const data = await buildDeletionWorkspacePayloadNode(getPool(), deletionCtx, delReqId);
      if (!data) return sendErrorJson(res, 404, 'Seller deletion request not found.');
      return res.json({
        status: 'success',
        deletion_request_id: delReqId,
        refunds: data.refunds,
        refund_stats: data.refund_stats
      });
    } catch (err) {
      return sendErrorJson(res, 500, `Failed to load refunds: ${err.message}`);
    }
  });

  // 8. POST /api/admin/seller-deletions/:id/process-refunds
  router.post('/api/admin/seller-deletions/:id/process-refunds', authenticateToken, requireAdminRole, async (req, res) => {
    const delReqId = parseInt(req.params.id, 10);
    if (!delReqId || delReqId <= 0) return sendErrorJson(res, 404, 'Deletion request not found.');
    const body = req.body || {};
    const remarks = String(body.remarks || body.admin_remarks || '').trim();
    try {
      const result = await deletionProcessRefundsNode(getPool(), deletionCtx, delReqId, Number(req.user.id), remarks, getClientIp(req));
      if (result.error) return sendErrorJson(res, result.code || 400, result.error);
      return res.status(result.statusCode || 200).json({
        status: 'success',
        message: 'Refund batch processed against payment gateway.',
        ...result.data
      });
    } catch (err) {
      return sendErrorJson(res, 500, `Failed to process refunds: ${err.message}`);
    }
  });

  // 9. POST /api/admin/seller-deletions/:id/verify-settlement
  router.post('/api/admin/seller-deletions/:id/verify-settlement', authenticateToken, requireAdminRole, async (req, res) => {
    const delReqId = parseInt(req.params.id, 10);
    if (!delReqId || delReqId <= 0) return sendErrorJson(res, 404, 'Deletion request not found.');
    const body = req.body || {};
    const remarks = String(body.remarks || body.admin_remarks || '').trim();
    try {
      const result = await deletionVerifySettlementNode(getPool(), deletionCtx, delReqId, Number(req.user.id), remarks, getClientIp(req));
      if (result.error) return sendErrorJson(res, result.code || 400, result.error);
      return res.status(result.statusCode || 200).json({
        status: 'success',
        message: 'Financial settlement verification completed.',
        ...result.data
      });
    } catch (err) {
      return sendErrorJson(res, 500, `Failed to verify settlement: ${err.message}`);
    }
  });

  // 10. POST /api/admin/seller-deletions/:id/settle-payout
  router.post('/api/admin/seller-deletions/:id/settle-payout', authenticateToken, requireAdminRole, async (req, res) => {
    const delReqId = parseInt(req.params.id, 10);
    if (!delReqId || delReqId <= 0) return sendErrorJson(res, 404, 'Deletion request not found.');
    try {
      const result = await deletionSettlePayoutNode(getPool(), deletionCtx, delReqId, Number(req.user.id), req.body || {}, getClientIp(req));
      if (result.error) return sendErrorJson(res, result.code || 400, result.error);
      return res.status(result.statusCode || 200).json({
        status: 'success',
        message: 'Seller payout settlement recorded.',
        ...result.data
      });
    } catch (err) {
      return sendErrorJson(res, 500, `Failed to settle payout: ${err.message}`);
    }
  });

  // 11. POST /api/admin/seller-deletions/:id/resolve-dispute
  router.post('/api/admin/seller-deletions/:id/resolve-dispute', authenticateToken, requireAdminRole, async (req, res) => {
    const delReqId = parseInt(req.params.id, 10);
    if (!delReqId || delReqId <= 0) return sendErrorJson(res, 404, 'Deletion request not found.');
    try {
      const result = await deletionResolveDisputeNode(getPool(), deletionCtx, delReqId, Number(req.user.id), req.body || {}, getClientIp(req));
      if (result.error) return sendErrorJson(res, result.code || 400, result.error);
      return res.status(result.statusCode || 200).json({
        status: 'success',
        message: 'Dispute resolved and checklist re-evaluated.',
        ...result.data
      });
    } catch (err) {
      return sendErrorJson(res, 500, `Failed to resolve dispute: ${err.message}`);
    }
  });

  // 12. POST /api/admin/seller-deletions/:id/approve
  router.post('/api/admin/seller-deletions/:id/approve', authenticateToken, requireAdminRole, async (req, res) => {
    const delReqId = parseInt(req.params.id, 10);
    if (!delReqId || delReqId <= 0) return sendErrorJson(res, 404, 'Deletion request not found.');
    const body = req.body || {};
    const remarks = String(body.remarks || body.admin_remarks || '').trim();
    try {
      const result = await deletionApproveNode(getPool(), deletionCtx, delReqId, Number(req.user.id), remarks, getClientIp(req));
      if (result.error) {
        const extras = result.failing_checks ? { failing_checks: result.failing_checks } : {};
        return sendErrorJson(res, result.code || 400, result.error, extras);
      }
      return res.status(result.statusCode || 200).json({
        status: 'success',
        message: 'Seller deletion approved. Explicit second confirmation required to finalize.',
        ...result.data
      });
    } catch (err) {
      return sendErrorJson(res, 500, `Failed to approve deletion: ${err.message}`);
    }
  });

  // 13. POST /api/admin/seller-deletions/:id/reject
  router.post('/api/admin/seller-deletions/:id/reject', authenticateToken, requireAdminRole, async (req, res) => {
    const delReqId = parseInt(req.params.id, 10);
    if (!delReqId || delReqId <= 0) return sendErrorJson(res, 404, 'Deletion request not found.');
    const body = req.body || {};
    const reason = String(body.reason || body.rejection_reason || body.remarks || '').trim();
    const remarks = String(body.remarks || body.admin_remarks || '').trim();
    try {
      const result = await deletionRejectNode(getPool(), deletionCtx, delReqId, Number(req.user.id), reason, remarks, getClientIp(req));
      if (result.error) return sendErrorJson(res, result.code || 400, result.error);
      return res.status(result.statusCode || 200).json({
        status: 'success',
        message: 'Seller deletion request rejected. Seller account restored to ACTIVE.',
        ...result.data
      });
    } catch (err) {
      return sendErrorJson(res, 500, `Failed to reject deletion request: ${err.message}`);
    }
  });

  // 14. POST /api/admin/seller-deletions/:id/hold
  router.post('/api/admin/seller-deletions/:id/hold', authenticateToken, requireAdminRole, async (req, res) => {
    const delReqId = parseInt(req.params.id, 10);
    if (!delReqId || delReqId <= 0) return sendErrorJson(res, 404, 'Deletion request not found.');
    const body = req.body || {};
    const reason = String(body.reason || body.hold_reason || body.remarks || '').trim();
    const remarks = String(body.remarks || body.admin_remarks || '').trim();
    try {
      const result = await deletionHoldNode(getPool(), deletionCtx, delReqId, Number(req.user.id), reason, remarks, getClientIp(req));
      if (result.error) return sendErrorJson(res, result.code || 400, result.error);
      return res.status(result.statusCode || 200).json({
        status: 'success',
        message: 'Seller deletion request placed ON HOLD.',
        ...result.data
      });
    } catch (err) {
      return sendErrorJson(res, 500, `Failed to put deletion request on hold: ${err.message}`);
    }
  });

  // 15. POST /api/admin/seller-deletions/:id/final-delete
  router.post('/api/admin/seller-deletions/:id/final-delete', authenticateToken, requireAdminRole, async (req, res) => {
    const delReqId = parseInt(req.params.id, 10);
    if (!delReqId || delReqId <= 0) return sendErrorJson(res, 404, 'Deletion request not found.');
    try {
      const result = await deletionFinalDeleteNode(getPool(), deletionCtx, delReqId, Number(req.user.id), req.body || {}, getClientIp(req));
      if (result.error) return sendErrorJson(res, result.code || 400, result.error);
      return res.status(result.statusCode || 200).json({
        status: 'success',
        message: 'Seller account permanently soft-deleted. Financial and order records preserved.',
        ...result.data
      });
    } catch (err) {
      return sendErrorJson(res, 500, `Final deletion failed: ${err.message}`);
    }
  });

  // 16. POST /api/orders/:id/payment-verify
  router.post(['/api/orders/:id/payment-verify', '/api/order/:id/payment-verify'], async (req, res) => {
    try {
      const actor = typeof resolveOptionalUserAsync === 'function' ? await resolveOptionalUserAsync(req) : null;
      const result = await recordOrderPaymentCaptureNode(getPool(), deletionCtx, req.params.id, req.body || {}, actor);
      if (result.error) return sendErrorJson(res, result.code || 400, result.error);
      return res.status(result.statusCode || 200).json(result.data);
    } catch (err) {
      return sendErrorJson(res, 500, `Payment verification failed: ${err.message}`);
    }
  });

  // 17. POST /api/orders/:id/disputes
  router.post(['/api/orders/:id/disputes', '/api/order/:id/disputes'], async (req, res) => {
    try {
      const actor = typeof resolveOptionalUserAsync === 'function' ? await resolveOptionalUserAsync(req) : null;
      const result = await createOrderDisputeNode(getPool(), deletionCtx, req.params.id, req.body || {}, actor);
      if (result.error) return sendErrorJson(res, result.code || 400, result.error);
      return res.status(result.statusCode || 201).json(result.data);
    } catch (err) {
      return sendErrorJson(res, 500, `Dispute creation failed: ${err.message}`);
    }
  });

  return router;
}

module.exports = { createSellerDeletionRouter };
