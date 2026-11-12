/**
 * Express Router for Seller Account Deletion, Refund Management & Settlement Verification
 * Parity with api/seller_deletion.php & index.php routes.
 */

const express = require('express');
const {
  deletionListRequestsNode,
  deletionPreflightNode,
  deletionCreateRequestNode,
  sanitizeDeletionPayloadForSellerNode,
  deletionGetSellerStatusNode,
  deletionWithdrawBySellerNode,
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

  // =========================================================================
  // Seller Self-Service Account Deletion Request, Status & Withdrawal Routes
  // =========================================================================

  async function handleSellerRequestDeletion(req, res, { urlSellerId = null, requireExplicitForAdmin = false } = {}) {
    const isAdmin = String(req.user?.role || 'seller').toLowerCase() === 'admin';
    const body = req.body || {};
    let targetSellerId;

    if (urlSellerId !== null) {
      if (!Number.isFinite(urlSellerId) || urlSellerId <= 0) {
        return sendErrorJson(res, 422, 'Valid seller_id is required.', { seller_id: ['Valid seller_id is required.'] });
      }
      if (!isAdmin && urlSellerId !== Number(req.user.id)) {
        return sendErrorJson(res, 403, 'Forbidden. You can only submit deletion requests for your own seller account.');
      }
      targetSellerId = urlSellerId;
    } else if (body.seller_id !== undefined && body.seller_id !== null && String(body.seller_id).trim() !== '') {
      const bodySid = parseInt(body.seller_id, 10);
      if (!bodySid || bodySid <= 0) {
        return sendErrorJson(res, 422, 'Valid seller_id is required.', { seller_id: ['Valid seller_id is required.'] });
      }
      if (!isAdmin && bodySid !== Number(req.user.id)) {
        return sendErrorJson(res, 403, 'Forbidden. You can only submit deletion requests for your own seller account.');
      }
      targetSellerId = bodySid;
    } else {
      if (requireExplicitForAdmin && isAdmin) {
        return sendErrorJson(res, 422, 'Valid seller_id is required.', { seller_id: ['Valid seller_id is required.'] });
      }
      targetSellerId = Number(req.user.id);
    }

    const actorRole = isAdmin ? 'admin' : 'seller';
    const reason = String(body.reason || body.request_reason || body.deletion_reason || body.notes || body.comment || '').trim();
    const remarks = isAdmin ? String(body.remarks || body.admin_remarks || '').trim() : '';

    try {
      const result = await deletionCreateRequestNode(
        getPool(),
        deletionCtx,
        targetSellerId,
        Number(req.user.id),
        reason,
        remarks,
        getClientIp(req),
        actorRole
      );
      if (result.error) {
        const extras = result.existing_request_id ? { existing_request_id: result.existing_request_id } : {};
        return sendErrorJson(res, result.code || 400, result.error, extras);
      }

      const payload = isAdmin ? { ...result.data } : sanitizeDeletionPayloadForSellerNode(result.data);
      payload.has_request = true;
      payload.has_active_request = true;
      payload.account_status = payload.seller?.account_status || 'DELETION_PENDING';
      payload.can_request_deletion = false;
      payload.can_withdraw_request = true;

      return res.status(result.statusCode || 201).json({
        status: 'success',
        message: isAdmin
          ? 'Seller deletion request created. Account entered DELETION_PENDING status and new orders are blocked.'
          : 'Seller deletion request submitted. Account entered DELETION_PENDING status and new orders are blocked.',
        ...payload
      });
    } catch (err) {
      return sendErrorJson(res, 500, `Failed to submit deletion request: ${err.message}`);
    }
  }

  async function handleSellerGetDeletionStatus(req, res, { urlSellerId = null, allowAdminList = false } = {}) {
    const isAdmin = String(req.user?.role || 'seller').toLowerCase() === 'admin';
    try {
      if (allowAdminList && isAdmin && urlSellerId === null && !req.query.seller_id) {
        const filters = {
          status: req.query.status || '',
          stage: req.query.stage || '',
          search: req.query.search || req.query.q || ''
        };
        const out = await deletionListRequestsNode(getPool(), deletionCtx, filters);
        return res.json({ status: 'success', ...out });
      }

      let targetSellerId;
      if (urlSellerId !== null) {
        if (!Number.isFinite(urlSellerId) || urlSellerId <= 0) {
          return sendErrorJson(res, 422, 'Valid seller_id is required.');
        }
        if (!isAdmin && urlSellerId !== Number(req.user.id)) {
          return sendErrorJson(res, 403, 'Forbidden. You can only view deletion status for your own seller account.');
        }
        targetSellerId = urlSellerId;
      } else if (req.query.seller_id !== undefined && req.query.seller_id !== null && String(req.query.seller_id).trim() !== '') {
        const qSid = parseInt(req.query.seller_id, 10);
        if (!qSid || qSid <= 0) {
          return sendErrorJson(res, 422, 'Valid seller_id is required.');
        }
        if (!isAdmin && qSid !== Number(req.user.id)) {
          return sendErrorJson(res, 403, 'Forbidden. You can only view deletion status for your own seller account.');
        }
        targetSellerId = qSid;
      } else {
        targetSellerId = Number(req.user.id);
      }

      const result = await deletionGetSellerStatusNode(getPool(), deletionCtx, targetSellerId, !isAdmin);
      if (result.error) {
        return sendErrorJson(res, result.code || 400, result.error);
      }
      return res.status(result.statusCode || 200).json({ status: 'success', ...result.data });
    } catch (err) {
      return sendErrorJson(res, 500, `Failed to load seller deletion status: ${err.message}`);
    }
  }

  async function handleSellerWithdrawDeletion(req, res, { urlSellerId = null, urlDeletionReqId = null } = {}) {
    const isAdmin = String(req.user?.role || 'seller').toLowerCase() === 'admin';
    const body = req.body || {};
    let targetSellerId;

    if (urlSellerId !== null) {
      if (!Number.isFinite(urlSellerId) || urlSellerId <= 0) {
        return sendErrorJson(res, 422, 'Valid seller_id is required.');
      }
      if (!isAdmin && urlSellerId !== Number(req.user.id)) {
        return sendErrorJson(res, 403, 'Forbidden. You can only withdraw deletion requests for your own seller account.');
      }
      targetSellerId = urlSellerId;
    } else if (body.seller_id !== undefined && body.seller_id !== null && String(body.seller_id).trim() !== '') {
      const bodySid = parseInt(body.seller_id, 10);
      if (!bodySid || bodySid <= 0) {
        return sendErrorJson(res, 422, 'Valid seller_id is required.', { seller_id: ['Valid seller_id is required.'] });
      }
      if (!isAdmin && bodySid !== Number(req.user.id)) {
        return sendErrorJson(res, 403, 'Forbidden. You can only withdraw deletion requests for your own seller account.');
      }
      targetSellerId = bodySid;
    } else {
      targetSellerId = Number(req.user.id);
    }

    const delReqId = urlDeletionReqId !== null
      ? urlDeletionReqId
      : (body.deletion_request_id ? parseInt(body.deletion_request_id, 10) : null);
    const reason = String(body.reason || body.withdrawal_reason || body.notes || '').trim();

    try {
      const result = await deletionWithdrawBySellerNode(
        getPool(),
        deletionCtx,
        targetSellerId,
        Number(req.user.id),
        reason,
        getClientIp(req),
        isAdmin ? 'admin' : 'seller',
        delReqId
      );
      if (result.error) {
        return sendErrorJson(res, result.code || 400, result.error);
      }
      return res.status(result.statusCode || 200).json({
        status: 'success',
        message: 'Seller deletion request withdrawn. Account restored to ACTIVE status.',
        ...result.data
      });
    } catch (err) {
      return sendErrorJson(res, 500, `Failed to withdraw deletion request: ${err.message}`);
    }
  }

  // Seller self-service GET status & preflight routes
  router.get([
    '/api/seller/deletion-request',
    '/api/seller/deletion-requests',
    '/api/seller/deletion',
    '/api/seller/deletion/status',
    '/api/seller/deletion-status',
    '/api/seller/request-deletion',
    '/api/seller/delete-request',
    '/api/seller/delete-account',
    '/api/seller/account/deletion-request',
    '/api/seller/deletion/preflight',
    '/api/seller/deletion-request/preflight'
  ], authenticateToken, (req, res) => handleSellerGetDeletionStatus(req, res));

  router.get(['/api/sellers/:seller_id/deletion-request', '/api/seller/:seller_id/deletion-request'], (req, res, next) => {
    if (!/^\d+$/.test(req.params.seller_id)) return next('route');
    return next();
  }, authenticateToken, (req, res) => handleSellerGetDeletionStatus(req, res, { urlSellerId: parseInt(req.params.seller_id, 10) }));

  router.get('/api/seller-deletions', authenticateToken, (req, res) => handleSellerGetDeletionStatus(req, res, { allowAdminList: true }));

  // Seller self-service POST / DELETE submit deletion request routes
  router.post([
    '/api/seller/deletion-request',
    '/api/seller/deletion-requests',
    '/api/seller/request-deletion',
    '/api/seller/deletion',
    '/api/seller/delete-request',
    '/api/seller/delete-account',
    '/api/seller/account/delete',
    '/api/seller/account/deletion-request',
    '/api/seller/profile/delete'
  ], authenticateToken, (req, res) => handleSellerRequestDeletion(req, res));

  router.delete([
    '/api/seller/profile',
    '/api/seller/account',
    '/api/me',
    '/api/seller/delete-account',
    '/api/seller/account/delete'
  ], authenticateToken, (req, res) => handleSellerRequestDeletion(req, res));

  router.post([
    '/api/sellers/:seller_id/deletion-request',
    '/api/seller/:seller_id/deletion-request',
    '/api/sellers/:seller_id/request-deletion',
    '/api/seller/:seller_id/request-deletion'
  ], (req, res, next) => {
    if (!/^\d+$/.test(req.params.seller_id)) return next('route');
    return next();
  }, authenticateToken, (req, res) => handleSellerRequestDeletion(req, res, { urlSellerId: parseInt(req.params.seller_id, 10) }));

  router.delete(['/api/sellers/:seller_id', '/api/seller/:seller_id'], (req, res, next) => {
    if (!/^\d+$/.test(req.params.seller_id)) return next('route');
    return next();
  }, authenticateToken, (req, res) => handleSellerRequestDeletion(req, res, { urlSellerId: parseInt(req.params.seller_id, 10) }));

  router.post(['/api/seller-deletions', '/api/seller-deletions/request'], authenticateToken, (req, res) =>
    handleSellerRequestDeletion(req, res, { requireExplicitForAdmin: true })
  );

  // Seller self-service withdraw / cancel initial pending request
  router.post([
    '/api/seller/deletion-request/cancel',
    '/api/seller/deletion-request/withdraw',
    '/api/seller/deletion/cancel',
    '/api/seller/deletion/withdraw'
  ], authenticateToken, (req, res) => handleSellerWithdrawDeletion(req, res));

  router.delete([
    '/api/seller/deletion-request',
    '/api/seller/deletion-requests',
    '/api/seller/deletion'
  ], authenticateToken, (req, res) => handleSellerWithdrawDeletion(req, res));

  router.post([
    '/api/seller/deletion-request/:id/cancel',
    '/api/seller/deletion-request/:id/withdraw',
    '/api/seller/deletion-requests/:id/cancel',
    '/api/seller/deletion-requests/:id/withdraw'
  ], (req, res, next) => {
    if (!/^\d+$/.test(req.params.id)) return next('route');
    return next();
  }, authenticateToken, (req, res) => handleSellerWithdrawDeletion(req, res, { urlDeletionReqId: parseInt(req.params.id, 10) }));

  router.delete([
    '/api/seller/deletion-request/:id',
    '/api/seller/deletion-requests/:id',
    '/api/seller/deletion-request/:id/cancel',
    '/api/seller/deletion-request/:id/withdraw'
  ], (req, res, next) => {
    if (!/^\d+$/.test(req.params.id)) return next('route');
    return next();
  }, authenticateToken, (req, res) => handleSellerWithdrawDeletion(req, res, { urlDeletionReqId: parseInt(req.params.id, 10) }));

  router.post([
    '/api/sellers/:seller_id/deletion-request/cancel',
    '/api/sellers/:seller_id/deletion-request/withdraw',
    '/api/seller/:seller_id/deletion-request/cancel',
    '/api/seller/:seller_id/deletion-request/withdraw'
  ], (req, res, next) => {
    if (!/^\d+$/.test(req.params.seller_id)) return next('route');
    return next();
  }, authenticateToken, (req, res) => handleSellerWithdrawDeletion(req, res, { urlSellerId: parseInt(req.params.seller_id, 10) }));

  router.delete([
    '/api/sellers/:seller_id/deletion-request',
    '/api/seller/:seller_id/deletion-request'
  ], (req, res, next) => {
    if (!/^\d+$/.test(req.params.seller_id)) return next('route');
    return next();
  }, authenticateToken, (req, res) => handleSellerWithdrawDeletion(req, res, { urlSellerId: parseInt(req.params.seller_id, 10) }));

  // =========================================================================
  // Admin Seller Account Deletion & Refund Routes
  // =========================================================================

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

  // 3. POST /api/admin/seller-deletions (and /request alias)
  router.post(['/api/admin/seller-deletions', '/api/admin/seller-deletions/request'], authenticateToken, requireAdminRole, async (req, res) => {
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

  // 5. POST /api/admin/seller-deletions/:id/lock (and /lock-account alias)
  router.post(['/api/admin/seller-deletions/:id/lock', '/api/admin/seller-deletions/:id/lock-account'], authenticateToken, requireAdminRole, async (req, res) => {
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
