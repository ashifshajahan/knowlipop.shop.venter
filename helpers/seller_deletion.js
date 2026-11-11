/**
 * Seller Account Deletion, Order Verification, Razorpay Refund & Settlement Engine (Node.js)
 * Parity implementation of helpers/seller_deletion.php supporting both MySQL pool and Fallback In-Memory Store.
 */

const crypto = require('crypto');
const http = require('http');
const https = require('https');

const ACTIVE_DISPUTE_STATUSES = ['OPEN', 'INVESTIGATING', 'ESCALATED'];
const TERMINAL_DELETION_STATUSES = ['DELETED', 'DELETION_REJECTED'];

function getSellerDeletionConfigNode() {
  const days = parseInt(process.env.RETURN_WINDOW_DAYS || '7', 10);
  const rate = parseFloat(process.env.MARKETPLACE_COMMISSION_RATE || '0.05');
  return {
    return_window_days: Number.isFinite(days) && days >= 1 ? days : 7,
    commission_rate: Number.isFinite(rate) && rate >= 0 ? rate : 0.05,
    razorpay_key_id: String(process.env.RAZORPAY_KEY_ID || '').trim(),
    razorpay_key_secret: String(process.env.RAZORPAY_KEY_SECRET || '').trim(),
    razorpay_api_base: String(process.env.RAZORPAY_API_BASE_URL || 'https://api.razorpay.com/v1').trim().replace(/\/+$/, '') || 'https://api.razorpay.com/v1'
  };
}

function nowSqlUtc() {
  return new Date().toISOString().slice(0, 19).replace('T', ' ');
}

function addDaysSqlUtc(days, fromDate = new Date()) {
  const d = new Date(fromDate.getTime() + days * 86400 * 1000);
  return d.toISOString().slice(0, 19).replace('T', ' ');
}

function parseTimestampMs(val) {
  if (!val) return null;
  if (val instanceof Date) {
    const t = val.getTime();
    return Number.isNaN(t) ? null : t;
  }
  const str = String(val).trim();
  if (!str) return null;
  if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(str)) {
    const t = Date.parse(str.replace(' ', 'T') + 'Z');
    return Number.isNaN(t) ? null : t;
  }
  const t = Date.parse(str);
  return Number.isNaN(t) ? null : t;
}

function toIsoStringSafe(val) {
  if (!val) return null;
  if (val instanceof Date) return val.toISOString();
  return String(val);
}

function safeJsonParse(raw, fallback = null) {
  if (raw === null || raw === undefined || raw === '') return fallback;
  if (typeof raw === 'object') return raw;
  try {
    return JSON.parse(raw);
  } catch (e) {
    return fallback;
  }
}

function round2(n) {
  return Math.round((Number(n) || 0) * 100) / 100;
}

function getStatusColorBadgeNode(status) {
  const st = String(status || '').trim().toUpperCase();
  if (['SUCCESS', 'REFUND_SUCCESS', 'SETTLED', 'RESOLVED', 'CLOSED', 'APPROVED_FOR_DELETION', 'DELETED'].includes(st)) {
    return 'green';
  }
  if (['PROCESSING', 'REFUND_PROCESSING', 'ACCOUNT_LOCKED', 'ORDER_VERIFICATION', 'FINANCIAL_SETTLEMENT', 'ADMIN_REVIEW', 'INVESTIGATING'].includes(st)) {
    return 'blue';
  }
  if (['FAILED', 'REFUND_FAILED', 'DELETION_REJECTED'].includes(st)) {
    return 'red';
  }
  if (['ON_HOLD', 'DELETION_ON_HOLD', 'HELD', 'ESCALATED', 'REFUND_REQUIRES_REVIEW'].includes(st)) {
    return 'orange';
  }
  return 'yellow';
}

function safeSellerNode(user, ctx) {
  if (ctx && typeof ctx.safeSeller === 'function') {
    return ctx.safeSeller(user);
  }
  if (!user) return {};
  const role = user.role || 'seller';
  const email = user.email && !String(user.email).toLowerCase().endsWith('@phone.vendor.local')
    ? user.email
    : (role === 'admin' ? (user.email || null) : null);
  const data = {
    id: Number(user.id),
    seller_name: user.seller_name || user.name || '',
    email: email || null,
    phone: user.phone || null,
    address: user.address || null,
    landmark: user.landmark || null,
    state: user.state || null,
    country: user.country || null,
    avatar_url: user.avatar_url || null,
    account_status: user.account_status || 'ACTIVE',
    deletion_requested_at: user.deletion_requested_at || null,
    deleted_at: user.deleted_at || null
  };
  if (user.role) data.role = user.role;
  return data;
}

function createDefaultFallbackDeletionStore() {
  return {
    deletionRequests: [],
    deletionChecks: [],
    refundRecords: [],
    payouts: [],
    paymentTransactions: [],
    disputes: [],
    auditLogs: [],
    gstDetails: [],
    nextIds: {
      deletionRequest: 1,
      deletionCheck: 1,
      refundRecord: 1,
      payout: 1,
      paymentTransaction: 1,
      dispute: 1,
      auditLog: 1
    }
  };
}

let dbReachabilityCache = { checkedAt: 0, ok: null };
async function checkDbReachable(pool, ctx) {
  if (ctx && ctx.forceFallback) return false;
  if (!pool || typeof pool.query !== 'function') return false;
  if (ctx && typeof ctx.isDbAvailable === 'function') {
    const known = ctx.isDbAvailable();
    if (known === false) return false;
  }
  const now = Date.now();
  if (dbReachabilityCache.ok === false && (now - dbReachabilityCache.checkedAt) < 3000) {
    return false;
  }
  try {
    await pool.query('SELECT 1');
    dbReachabilityCache = { checkedAt: now, ok: true };
    return true;
  } catch (err) {
    dbReachabilityCache = { checkedAt: now, ok: false };
    return false;
  }
}

let nodeDeletionSchemaEnsured = false;
async function ensureSellerDeletionSchemaNode(pool) {
  if (nodeDeletionSchemaEnsured || !pool) return;
  try {
    await pool.query('SELECT 1');
  } catch (e) {
    return;
  }

  const ensureCol = async (table, colName, alterSql) => {
    try {
      const [cols] = await pool.query(`SHOW COLUMNS FROM \`${table}\` LIKE ?`, [colName]);
      if (cols.length === 0) {
        await pool.query(`ALTER TABLE \`${table}\` ADD COLUMN ${alterSql}`);
      }
    } catch (e) {}
  };

  await ensureCol('users', 'account_status', "`account_status` VARCHAR(40) NOT NULL DEFAULT 'ACTIVE'");
  await ensureCol('users', 'deletion_requested_at', "`deletion_requested_at` TIMESTAMP NULL DEFAULT NULL");
  await ensureCol('users', 'deleted_at', "`deleted_at` TIMESTAMP NULL DEFAULT NULL");
  await ensureCol('users', 'deleted_by', "`deleted_by` BIGINT UNSIGNED NULL DEFAULT NULL");
  await ensureCol('users', 'token_version', "`token_version` INT NOT NULL DEFAULT 1");
  await ensureCol('users', 'fcm_token', "`fcm_token` VARCHAR(255) NULL DEFAULT NULL");

  await ensureCol('orders', 'rejection_reason', "`rejection_reason` TEXT NULL DEFAULT NULL");
  await ensureCol('orders', 'cancellation_reason', "`cancellation_reason` TEXT NULL DEFAULT NULL");
  await ensureCol('orders', 'shipped_at', "`shipped_at` TIMESTAMP NULL DEFAULT NULL");
  await ensureCol('orders', 'delivered_at', "`delivered_at` TIMESTAMP NULL DEFAULT NULL");
  await ensureCol('orders', 'payment_gateway', "`payment_gateway` VARCHAR(40) NULL DEFAULT NULL");
  await ensureCol('orders', 'gateway_order_id', "`gateway_order_id` VARCHAR(120) NULL DEFAULT NULL");
  await ensureCol('orders', 'gateway_payment_id', "`gateway_payment_id` VARCHAR(120) NULL DEFAULT NULL");
  await ensureCol('orders', 'captured_amount', "`captured_amount` DECIMAL(10,2) NULL DEFAULT NULL");
  await ensureCol('orders', 'refunded_amount', "`refunded_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00");
  await ensureCol('orders', 'return_window_ends_at', "`return_window_ends_at` TIMESTAMP NULL DEFAULT NULL");
  await ensureCol('orders', 'settled_at', "`settled_at` TIMESTAMP NULL DEFAULT NULL");

  await ensureCol('order_items', 'item_status', "`item_status` VARCHAR(40) NOT NULL DEFAULT 'pending'");
  await ensureCol('order_items', 'rejection_reason', "`rejection_reason` TEXT NULL DEFAULT NULL");

  const tableSqls = [
    `CREATE TABLE IF NOT EXISTS \`payment_transactions\` (
      \`id\` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      \`order_id\` BIGINT UNSIGNED NOT NULL,
      \`seller_id\` BIGINT UNSIGNED NULL DEFAULT NULL,
      \`gateway\` VARCHAR(40) NOT NULL DEFAULT 'razorpay',
      \`gateway_order_id\` VARCHAR(120) NULL DEFAULT NULL,
      \`gateway_payment_id\` VARCHAR(120) NOT NULL,
      \`transaction_type\` VARCHAR(40) NOT NULL DEFAULT 'capture',
      \`amount\` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
      \`currency\` VARCHAR(10) NOT NULL DEFAULT 'INR',
      \`status\` VARCHAR(40) NOT NULL DEFAULT 'captured',
      \`gateway_payload\` LONGTEXT NULL DEFAULT NULL,
      \`settled_at\` TIMESTAMP NULL DEFAULT NULL,
      \`created_at\` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
      \`updated_at\` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (\`id\`),
      INDEX \`idx_pt_order\` (\`order_id\`),
      INDEX \`idx_pt_seller\` (\`seller_id\`),
      INDEX \`idx_pt_gateway_payment\` (\`gateway_payment_id\`),
      INDEX \`idx_pt_status\` (\`status\`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`,
    `CREATE TABLE IF NOT EXISTS \`seller_deletion_requests\` (
      \`id\` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      \`seller_id\` BIGINT UNSIGNED NOT NULL,
      \`requested_by\` BIGINT UNSIGNED NULL DEFAULT NULL,
      \`stage\` VARCHAR(50) NOT NULL DEFAULT 'DELETION_REQUESTED',
      \`status\` VARCHAR(50) NOT NULL DEFAULT 'DELETION_REQUESTED',
      \`request_reason\` TEXT NULL DEFAULT NULL,
      \`admin_remarks\` TEXT NULL DEFAULT NULL,
      \`hold_reason\` TEXT NULL DEFAULT NULL,
      \`rejection_reason\` TEXT NULL DEFAULT NULL,
      \`confirmation_token\` VARCHAR(128) NULL DEFAULT NULL,
      \`confirmation_expires_at\` TIMESTAMP NULL DEFAULT NULL,
      \`snapshot_json\` LONGTEXT NULL DEFAULT NULL,
      \`financial_summary_json\` LONGTEXT NULL DEFAULT NULL,
      \`approved_by\` BIGINT UNSIGNED NULL DEFAULT NULL,
      \`approved_at\` TIMESTAMP NULL DEFAULT NULL,
      \`deleted_by\` BIGINT UNSIGNED NULL DEFAULT NULL,
      \`deleted_at\` TIMESTAMP NULL DEFAULT NULL,
      \`created_at\` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
      \`updated_at\` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (\`id\`),
      INDEX \`idx_sdr_seller\` (\`seller_id\`),
      INDEX \`idx_sdr_stage\` (\`stage\`),
      INDEX \`idx_sdr_status\` (\`status\`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`,
    `CREATE TABLE IF NOT EXISTS \`seller_deletion_checks\` (
      \`id\` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      \`deletion_request_id\` BIGINT UNSIGNED NOT NULL,
      \`check_key\` VARCHAR(60) NOT NULL,
      \`label\` VARCHAR(255) NOT NULL,
      \`status\` VARCHAR(30) NOT NULL DEFAULT 'PENDING',
      \`is_blocking\` TINYINT(1) NOT NULL DEFAULT 1,
      \`details_json\` LONGTEXT NULL DEFAULT NULL,
      \`resolved_by\` BIGINT UNSIGNED NULL DEFAULT NULL,
      \`resolved_at\` TIMESTAMP NULL DEFAULT NULL,
      \`notes\` TEXT NULL DEFAULT NULL,
      \`created_at\` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
      \`updated_at\` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (\`id\`),
      UNIQUE KEY \`uniq_del_check\` (\`deletion_request_id\`, \`check_key\`),
      INDEX \`idx_sdc_req\` (\`deletion_request_id\`),
      INDEX \`idx_sdc_status\` (\`status\`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`,
    `CREATE TABLE IF NOT EXISTS \`seller_refund_records\` (
      \`id\` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      \`idempotency_key\` VARCHAR(120) NOT NULL,
      \`deletion_request_id\` BIGINT UNSIGNED NULL DEFAULT NULL,
      \`order_id\` BIGINT UNSIGNED NOT NULL,
      \`order_item_id\` BIGINT UNSIGNED NULL DEFAULT NULL,
      \`seller_id\` BIGINT UNSIGNED NOT NULL,
      \`payment_transaction_id\` BIGINT UNSIGNED NULL DEFAULT NULL,
      \`gateway\` VARCHAR(40) NOT NULL DEFAULT 'razorpay',
      \`gateway_payment_id\` VARCHAR(120) NULL DEFAULT NULL,
      \`gateway_refund_id\` VARCHAR(120) NULL DEFAULT NULL,
      \`order_amount\` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
      \`eligible_refund_amount\` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
      \`refunded_amount\` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
      \`status\` VARCHAR(40) NOT NULL DEFAULT 'REFUND_PENDING',
      \`gateway_response\` LONGTEXT NULL DEFAULT NULL,
      \`failure_reason\` TEXT NULL DEFAULT NULL,
      \`attempts\` INT NOT NULL DEFAULT 0,
      \`initiated_by\` BIGINT UNSIGNED NULL DEFAULT NULL,
      \`last_attempted_at\` TIMESTAMP NULL DEFAULT NULL,
      \`confirmed_at\` TIMESTAMP NULL DEFAULT NULL,
      \`created_at\` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
      \`updated_at\` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (\`id\`),
      UNIQUE KEY \`uniq_srr_idempotency\` (\`idempotency_key\`),
      INDEX \`idx_srr_del\` (\`deletion_request_id\`),
      INDEX \`idx_srr_order\` (\`order_id\`),
      INDEX \`idx_srr_seller\` (\`seller_id\`),
      INDEX \`idx_srr_status\` (\`status\`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`,
    `CREATE TABLE IF NOT EXISTS \`seller_payouts\` (
      \`id\` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      \`seller_id\` BIGINT UNSIGNED NOT NULL,
      \`deletion_request_id\` BIGINT UNSIGNED NULL DEFAULT NULL,
      \`amount\` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
      \`commission_deducted\` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
      \`refund_recovery_deducted\` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
      \`net_amount\` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
      \`status\` VARCHAR(40) NOT NULL DEFAULT 'PENDING',
      \`payout_reference\` VARCHAR(120) NULL DEFAULT NULL,
      \`gateway_payout_id\` VARCHAR(120) NULL DEFAULT NULL,
      \`settled_by\` BIGINT UNSIGNED NULL DEFAULT NULL,
      \`settled_at\` TIMESTAMP NULL DEFAULT NULL,
      \`notes\` TEXT NULL DEFAULT NULL,
      \`created_at\` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
      \`updated_at\` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (\`id\`),
      INDEX \`idx_sp_seller\` (\`seller_id\`),
      INDEX \`idx_sp_del\` (\`deletion_request_id\`),
      INDEX \`idx_sp_status\` (\`status\`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`,
    `CREATE TABLE IF NOT EXISTS \`order_disputes\` (
      \`id\` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      \`order_id\` BIGINT UNSIGNED NOT NULL,
      \`seller_id\` BIGINT UNSIGNED NOT NULL,
      \`buyer_phone\` VARCHAR(30) NULL DEFAULT NULL,
      \`dispute_type\` VARCHAR(50) NOT NULL DEFAULT 'complaint',
      \`status\` VARCHAR(40) NOT NULL DEFAULT 'OPEN',
      \`claim_amount\` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
      \`description\` TEXT NULL DEFAULT NULL,
      \`resolution_notes\` TEXT NULL DEFAULT NULL,
      \`resolved_by\` BIGINT UNSIGNED NULL DEFAULT NULL,
      \`resolved_at\` TIMESTAMP NULL DEFAULT NULL,
      \`created_at\` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
      \`updated_at\` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (\`id\`),
      INDEX \`idx_od_order\` (\`order_id\`),
      INDEX \`idx_od_seller\` (\`seller_id\`),
      INDEX \`idx_od_status\` (\`status\`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`,
    `CREATE TABLE IF NOT EXISTS \`seller_deletion_audit_logs\` (
      \`id\` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      \`deletion_request_id\` BIGINT UNSIGNED NULL DEFAULT NULL,
      \`seller_id\` BIGINT UNSIGNED NOT NULL,
      \`action\` VARCHAR(80) NOT NULL,
      \`previous_stage\` VARCHAR(50) NULL DEFAULT NULL,
      \`new_stage\` VARCHAR(50) NULL DEFAULT NULL,
      \`previous_status\` VARCHAR(50) NULL DEFAULT NULL,
      \`new_status\` VARCHAR(50) NULL DEFAULT NULL,
      \`actor_id\` BIGINT UNSIGNED NULL DEFAULT NULL,
      \`actor_role\` VARCHAR(30) NOT NULL DEFAULT 'admin',
      \`remarks\` TEXT NULL DEFAULT NULL,
      \`metadata_json\` LONGTEXT NULL DEFAULT NULL,
      \`ip_address\` VARCHAR(64) NULL DEFAULT NULL,
      \`created_at\` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (\`id\`),
      INDEX \`idx_sdal_del\` (\`deletion_request_id\`),
      INDEX \`idx_sdal_seller\` (\`seller_id\`),
      INDEX \`idx_sdal_action\` (\`action\`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`
  ];

  for (const sql of tableSqls) {
    try {
      await pool.query(sql);
    } catch (e) {}
  }

  nodeDeletionSchemaEnsured = true;
}

async function sendDeletionNotificationNode(pool, ctx, { userId = null, phone = null, title, message, type, data = {} }, useDb = false) {
  if (ctx && typeof ctx.createNotification === 'function') {
    try {
      await ctx.createNotification(useDb ? pool : null, {
        userId: userId || null,
        phone: phone || null,
        title,
        message,
        type,
        data
      });
      return;
    } catch (e) {}
  }
  if (!useDb && ctx && typeof ctx.getFallbackNotifications === 'function') {
    const arr = ctx.getFallbackNotifications();
    const id = arr.length ? Math.max(...arr.map(n => Number(n.id) || 0)) + 1 : 1;
    arr.unshift({
      id,
      user_id: userId || null,
      buyer_phone: phone || null,
      title,
      message,
      type,
      data,
      is_read: 0,
      created_at: nowSqlUtc()
    });
  }
}

async function logSellerDeletionAuditNode(pool, ctx, entry, useDb = false) {
  const {
    deletionRequestId = null,
    sellerId,
    action,
    prevStage = null,
    newStage = null,
    prevStatus = null,
    newStatus = null,
    actorId = null,
    actorRole = 'admin',
    remarks = null,
    metadata = {},
    ipAddress = null
  } = entry;

  if (useDb) {
    try {
      await pool.query(
        `INSERT INTO seller_deletion_audit_logs
          (deletion_request_id, seller_id, action, previous_stage, new_stage, previous_status, new_status, actor_id, actor_role, remarks, metadata_json, ip_address, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)`,
        [
          deletionRequestId,
          sellerId,
          action,
          prevStage,
          newStage,
          prevStatus,
          newStatus,
          actorId,
          actorRole,
          remarks,
          Object.keys(metadata || {}).length > 0 ? JSON.stringify(metadata) : null,
          ipAddress
        ]
      );
    } catch (e) {}
    return;
  }

  const store = ctx.getFallbackDeletionStore();
  const id = store.nextIds.auditLog++;
  store.auditLogs.push({
    id,
    deletion_request_id: deletionRequestId,
    seller_id: Number(sellerId),
    action,
    previous_stage: prevStage,
    new_stage: newStage,
    previous_status: prevStatus,
    new_status: newStatus,
    actor_id: actorId,
    actor_role: actorRole,
    remarks,
    metadata_json: Object.keys(metadata || {}).length > 0 ? JSON.stringify(metadata) : null,
    ip_address: ipAddress,
    created_at: nowSqlUtc()
  });
}

function buildEnrichedOrdersFromRaw({ sellerId, orders, itemsByOrder, txByOrder, disputesByOrder, refundsByOrder, dbNowMs, dbUtcNowMs, returnDays }) {
  const enriched = [];

  for (const o of orders) {
    const oid = Number(o.id);
    const items = itemsByOrder[oid] || [];
    const sellerItems = [];
    const otherItems = [];
    let sellerGross = 0;
    let orderItemsTotal = 0;

    for (const it of items) {
      const sub = Number(it.subtotal !== undefined ? it.subtotal : ((Number(it.price) || 0) * (Number(it.quantity) || 1)));
      orderItemsTotal += sub;
      const itSellerId = Number(it.seller_id || 0);
      if (itSellerId === Number(sellerId) || (!it.seller_id && Number(o.seller_id || 0) === Number(sellerId))) {
        sellerItems.push(it);
        sellerGross += sub;
      } else {
        otherItems.push(it);
      }
    }

    if (items.length === 0 && Number(o.seller_id || 0) === Number(sellerId)) {
      sellerGross = Number(o.subtotal ?? o.total_amount ?? 0);
      orderItemsTotal = sellerGross;
    }

    const isMultiVendor = otherItems.length > 0;
    const rawOrderStatus = String(o.order_status || 'pending').trim().toLowerCase();

    let effectiveStatus = rawOrderStatus;
    if (isMultiVendor && sellerItems.length > 0) {
      const itemStatuses = [...new Set(sellerItems.map(it => String(it.item_status || 'pending').trim().toLowerCase()))];
      if (itemStatuses.length === 1 && itemStatuses[0] !== 'pending') {
        effectiveStatus = itemStatuses[0];
      } else if (itemStatuses.length === 1 && rawOrderStatus !== 'pending' && itemStatuses[0] === 'pending') {
        effectiveStatus = rawOrderStatus;
      } else if (itemStatuses.length > 1) {
        effectiveStatus = (itemStatuses.includes('cancelled') || itemStatuses.includes('rejected'))
          ? (rawOrderStatus !== 'pending' ? rawOrderStatus : itemStatuses[0])
          : rawOrderStatus;
      }
    }

    const txs = txByOrder[oid] || [];
    const captureTxs = txs.filter(t => String(t.transaction_type || 'capture').toLowerCase() === 'capture');
    let capturedFromTx = 0;
    let gatewaySettledFromTx = 0;
    let primaryGatewayPaymentId = String(o.gateway_payment_id || '').trim();
    let primaryTxId = null;

    for (const ct of captureTxs) {
      const cStatus = String(ct.status || '').toLowerCase();
      if (['captured', 'settled', 'refunded'].includes(cStatus)) {
        capturedFromTx += Number(ct.amount || 0);
        if (primaryTxId === null) primaryTxId = Number(ct.id);
        if (!primaryGatewayPaymentId && ct.gateway_payment_id) {
          primaryGatewayPaymentId = String(ct.gateway_payment_id).trim();
        }
      }
      if (cStatus === 'settled' || ct.settled_at) {
        gatewaySettledFromTx += Number(ct.amount || 0);
      }
    }

    const paymentStatus = String(o.payment_status || 'unpaid').trim().toLowerCase();
    const paymentMethod = String(o.payment_method || 'cod').trim().toLowerCase();
    const orderCapturedField = (o.captured_amount !== null && o.captured_amount !== undefined) ? Number(o.captured_amount) : null;

    const isPaid = ['paid', 'refunded', 'partially_refunded'].includes(paymentStatus)
      || (orderCapturedField !== null && orderCapturedField > 0)
      || (capturedFromTx > 0);

    const capturedTotal = capturedFromTx > 0
      ? capturedFromTx
      : ((orderCapturedField !== null && orderCapturedField > 0)
        ? orderCapturedField
        : (isPaid ? Number(o.total_amount ?? sellerGross) : 0));

    const sellerProportion = orderItemsTotal > 0.001 ? Math.min(1, sellerGross / orderItemsTotal) : 1;
    let sellerPaidShare = round2(isMultiVendor ? capturedTotal * sellerProportion : capturedTotal);
    if (sellerPaidShare <= 0 && isPaid) {
      sellerPaidShare = round2(sellerGross);
    }

    const allOrderRefunds = refundsByOrder[oid] || [];
    const sellerRefundRecords = allOrderRefunds.filter(r => Number(r.seller_id) === Number(sellerId));
    let sellerSucceededRefund = 0;
    for (const sr of sellerRefundRecords) {
      if (sr.status === 'REFUND_SUCCESS') {
        sellerSucceededRefund += Number(sr.refunded_amount || 0);
      }
    }
    const orderRefundedField = Number(o.refunded_amount || 0);
    if (!isMultiVendor && sellerSucceededRefund <= 0 && orderRefundedField > 0 && paymentStatus === 'refunded') {
      sellerSucceededRefund = orderRefundedField;
    }

    let otherRefundAllocated = 0;
    for (const rr of allOrderRefunds) {
      if (Number(rr.seller_id) !== Number(sellerId) && ['REFUND_SUCCESS', 'REFUND_PROCESSING'].includes(rr.status)) {
        otherRefundAllocated += Number(rr.eligible_refund_amount ?? rr.refunded_amount ?? 0);
      }
    }

    const remainingOnOrder = Math.max(0, round2(capturedTotal - Math.max(orderRefundedField, sellerSucceededRefund) - otherRefundAllocated));
    const outstandingRefundAmount = Math.max(0, round2(Math.min(Math.max(0, sellerPaidShare - sellerSucceededRefund), remainingOnOrder)));

    const deliveredAt = o.delivered_at || null;
    const returnWindowEndsAt = o.return_window_ends_at || null;
    let effectiveWindowEnd = returnWindowEndsAt;
    let effectiveWindowEndMs = parseTimestampMs(returnWindowEndsAt);
    let returnWindowActive = false;
    if (effectiveStatus === 'delivered') {
      if (effectiveWindowEndMs !== null) {
        const refNowMs = (dbUtcNowMs !== undefined && dbUtcNowMs !== null) ? dbUtcNowMs : Date.now();
        returnWindowActive = effectiveWindowEndMs > refNowMs;
      } else {
        const baseMs = parseTimestampMs(deliveredAt) || parseTimestampMs(o.updated_at || o.created_at) || dbNowMs;
        if (baseMs) {
          effectiveWindowEndMs = baseMs + (returnDays * 86400 * 1000);
          effectiveWindowEnd = new Date(effectiveWindowEndMs).toISOString().slice(0, 19).replace('T', ' ');
          returnWindowActive = effectiveWindowEndMs > dbNowMs;
        }
      }
    }

    const orderDisputes = disputesByOrder[oid] || [];
    const openDisputes = orderDisputes.filter(d => ACTIVE_DISPUTE_STATUSES.includes(String(d.status || '').toUpperCase()));

    const hasGatewayUnsettledTx = captureTxs.length > 0 && gatewaySettledFromTx <= 0 && !o.settled_at;
    const isGatewaySettled = Boolean(o.settled_at) || gatewaySettledFromTx > 0 || (captureTxs.length === 0 && effectiveStatus === 'delivered' && isPaid);

    enriched.push({
      order: o,
      id: oid,
      order_number: o.order_number || `ORD-${oid}`,
      buyer_name: o.buyer_name || '',
      buyer_phone: o.buyer_phone || '',
      raw_order_status: rawOrderStatus,
      effective_status: effectiveStatus,
      payment_method: paymentMethod,
      payment_status: paymentStatus,
      payment_gateway: o.payment_gateway || (primaryGatewayPaymentId ? 'razorpay' : paymentMethod),
      gateway_payment_id: primaryGatewayPaymentId || null,
      primary_payment_transaction_id: primaryTxId,
      is_multi_vendor: isMultiVendor,
      seller_items: sellerItems,
      seller_gross: round2(sellerGross),
      order_total_amount: round2(o.total_amount || 0),
      captured_total: round2(capturedTotal),
      seller_paid_share: round2(sellerPaidShare),
      is_paid: isPaid,
      seller_succeeded_refund: round2(sellerSucceededRefund),
      outstanding_refund_amount: round2(outstandingRefundAmount),
      seller_refund_records: sellerRefundRecords,
      delivered_at: toIsoStringSafe(deliveredAt),
      return_window_ends_at: toIsoStringSafe(effectiveWindowEnd),
      return_window_active: returnWindowActive,
      open_disputes: openDisputes,
      all_disputes: orderDisputes,
      has_gateway_unsettled_tx: hasGatewayUnsettledTx,
      is_gateway_settled: isGatewaySettled,
      settled_at: toIsoStringSafe(o.settled_at || null)
    });
  }

  return enriched;
}

async function getSellerOrdersDetailedNode(pool, ctx, sellerId, useDb = false) {
  const cfg = getSellerDeletionConfigNode();
  const returnDays = cfg.return_window_days;

  if (useDb) {
    const [nowRows] = await pool.query('SELECT NOW() AS now_ts, UTC_TIMESTAMP() AS utc_now_ts');
    const dbNowMs = parseTimestampMs(nowRows[0] && nowRows[0].now_ts) || Date.now();
    const dbUtcNowMs = parseTimestampMs(nowRows[0] && nowRows[0].utc_now_ts) || Date.now();

    const [orders] = await pool.query(
      `SELECT DISTINCT o.*
       FROM orders o
       LEFT JOIN order_items oi ON oi.order_id = o.id
       WHERE oi.seller_id = ? OR o.seller_id = ?
       ORDER BY o.id DESC`,
      [sellerId, sellerId]
    );
    if (orders.length === 0) return [];

    const orderIds = orders.map(o => Number(o.id));
    const [allItems] = await pool.query('SELECT * FROM order_items WHERE order_id IN (?) ORDER BY id ASC', [orderIds]);
    const itemsByOrder = {};
    for (const it of allItems) {
      const k = Number(it.order_id);
      if (!itemsByOrder[k]) itemsByOrder[k] = [];
      itemsByOrder[k].push(it);
    }

    const txByOrder = {};
    try {
      const [txRows] = await pool.query('SELECT * FROM payment_transactions WHERE order_id IN (?) ORDER BY id ASC', [orderIds]);
      for (const tx of txRows) {
        const k = Number(tx.order_id);
        if (!txByOrder[k]) txByOrder[k] = [];
        txByOrder[k].push(tx);
      }
    } catch (e) {}

    const disputesByOrder = {};
    try {
      const [dRows] = await pool.query('SELECT * FROM order_disputes WHERE order_id IN (?) AND seller_id = ? ORDER BY id DESC', [orderIds, sellerId]);
      for (const d of dRows) {
        const k = Number(d.order_id);
        if (!disputesByOrder[k]) disputesByOrder[k] = [];
        disputesByOrder[k].push(d);
      }
    } catch (e) {}

    const refundsByOrder = {};
    try {
      const [rRows] = await pool.query('SELECT * FROM seller_refund_records WHERE order_id IN (?) ORDER BY id ASC', [orderIds]);
      for (const rf of rRows) {
        const k = Number(rf.order_id);
        if (!refundsByOrder[k]) refundsByOrder[k] = [];
        refundsByOrder[k].push(rf);
      }
    } catch (e) {}

    return buildEnrichedOrdersFromRaw({
      sellerId,
      orders,
      itemsByOrder,
      txByOrder,
      disputesByOrder,
      refundsByOrder,
      dbNowMs,
      dbUtcNowMs,
      returnDays
    });
  }

  // Fallback mode
  const store = ctx.getFallbackDeletionStore();
  const fbOrders = ctx.getFallbackOrders();
  const sellerOrders = fbOrders
    .filter(o => Number(o.seller_id) === Number(sellerId) || (o.items || []).some(it => Number(it.seller_id || o.seller_id || 0) === Number(sellerId)))
    .sort((a, b) => Number(b.id) - Number(a.id));

  if (sellerOrders.length === 0) return [];

  const itemsByOrder = {};
  const txByOrder = {};
  const disputesByOrder = {};
  const refundsByOrder = {};

  for (const o of sellerOrders) {
    const oid = Number(o.id);
    itemsByOrder[oid] = (o.items || []).map(it => ({ ...it, order_id: oid }));
    txByOrder[oid] = store.paymentTransactions.filter(t => Number(t.order_id) === oid);
    disputesByOrder[oid] = store.disputes.filter(d => Number(d.order_id) === oid && Number(d.seller_id) === Number(sellerId));
    refundsByOrder[oid] = store.refundRecords.filter(r => Number(r.order_id) === oid);
  }

  return buildEnrichedOrdersFromRaw({
    sellerId,
    orders: sellerOrders,
    itemsByOrder,
    txByOrder,
    disputesByOrder,
    refundsByOrder,
    dbNowMs: Date.now(),
    dbUtcNowMs: Date.now(),
    returnDays
  });
}

function finalizeComputedSummary({
  seller,
  totalProducts,
  activeProducts,
  enrichedOrders,
  allRefunds,
  payouts,
  allDisputes,
  gstSaved,
  commissionRate,
  ctx
}) {
  const counts = {
    total_orders: enrichedOrders.length,
    pending_orders: 0,
    confirmed_orders: 0,
    shipped_orders: 0,
    delivered_orders: 0,
    cancelled_orders: 0
  };

  let totalSuccessfulPayments = 0;
  let deliveredPaidSales = 0;
  let codDeliveredUnsettledSales = 0;
  let onlineDeliveredUnsettledSales = 0;
  let unsettledGatewayPaymentsAmount = 0;
  let unsettledGatewayCount = 0;

  const unresolvedOrderBlockers = [];
  const stageAAutoCancellable = [];
  const stageBRequiresApproval = [];
  const stageCShippedBlocking = [];
  const stageDReturnWindowBlocking = [];
  const ordersRequiringRefund = [];

  for (const eo of enrichedOrders) {
    const st = eo.effective_status;
    if (st === 'pending') {
      counts.pending_orders++;
      unresolvedOrderBlockers.push({ order_id: eo.id, order_number: eo.order_number, reason: 'Unaccepted pending order (Rule A)' });
      stageAAutoCancellable.push(eo.id);
    } else if (['accepted', 'processing', 'packed'].includes(st)) {
      counts.confirmed_orders++;
      unresolvedOrderBlockers.push({ order_id: eo.id, order_number: eo.order_number, reason: `Confirmed (${st}) order requires Admin cancellation approval (Rule B)` });
      stageBRequiresApproval.push(eo.id);
    } else if (st === 'shipped') {
      counts.shipped_orders++;
      unresolvedOrderBlockers.push({ order_id: eo.id, order_number: eo.order_number, reason: 'Shipped order cannot be auto-cancelled; requires delivery, return, or dispute resolution (Rule C)' });
      stageCShippedBlocking.push(eo.id);
    } else if (st === 'delivered') {
      counts.delivered_orders++;
      if (eo.return_window_active) {
        unresolvedOrderBlockers.push({
          order_id: eo.id,
          order_number: eo.order_number,
          reason: `Delivered order still within return/refund window until ${eo.return_window_ends_at} (Rule D)`
        });
        stageDReturnWindowBlocking.push(eo.id);
      }
      if (eo.open_disputes.length > 0) {
        unresolvedOrderBlockers.push({
          order_id: eo.id,
          order_number: eo.order_number,
          reason: `Delivered order has ${eo.open_disputes.length} open customer dispute(s) (Rule D)`
        });
      }
    } else if (['cancelled', 'rejected'].includes(st)) {
      counts.cancelled_orders++;
      if (eo.is_paid && eo.outstanding_refund_amount > 0.01) {
        ordersRequiringRefund.push(eo);
      }
    } else {
      unresolvedOrderBlockers.push({ order_id: eo.id, order_number: eo.order_number, reason: `Order in status '${st}'` });
    }

    if (eo.is_paid && !['cancelled', 'rejected'].includes(st)) {
      totalSuccessfulPayments += eo.seller_paid_share;
    }

    if (st === 'delivered' && eo.is_paid) {
      deliveredPaidSales += eo.seller_gross;
      if (!eo.settled_at) {
        if (eo.payment_method === 'cod') {
          codDeliveredUnsettledSales += eo.seller_gross;
        } else {
          onlineDeliveredUnsettledSales += eo.seller_gross;
        }
      }
    }

    if (!['cancelled', 'rejected'].includes(st) && eo.is_paid && eo.has_gateway_unsettled_tx) {
      unsettledGatewayPaymentsAmount += eo.seller_paid_share;
      unsettledGatewayCount++;
    }
  }

  const refundStats = {
    total_count: allRefunds.length,
    pending_count: 0,
    processing_count: 0,
    success_count: 0,
    failed_count: 0,
    review_count: 0,
    pending_amount: 0,
    refunded_total_amount: 0,
    failed_amount: 0
  };

  for (const rf of allRefunds) {
    const rst = String(rf.status || 'REFUND_PENDING').toUpperCase();
    const elig = Number(rf.eligible_refund_amount || 0);
    if (rst === 'REFUND_SUCCESS') {
      refundStats.success_count++;
      refundStats.refunded_total_amount += Number(rf.refunded_amount ?? elig);
    } else if (rst === 'REFUND_FAILED') {
      refundStats.failed_count++;
      refundStats.failed_amount += elig;
      refundStats.pending_amount += elig;
      refundStats.pending_count++;
    } else if (rst === 'REFUND_PROCESSING') {
      refundStats.processing_count++;
      refundStats.pending_amount += elig;
      refundStats.pending_count++;
    } else if (rst === 'REFUND_REQUIRES_REVIEW') {
      refundStats.review_count++;
      refundStats.pending_amount += elig;
      refundStats.pending_count++;
    } else {
      refundStats.pending_count++;
      refundStats.pending_amount += elig;
    }
  }

  const recordedOrderIds = new Set(allRefunds.map(r => Number(r.order_id)));
  let unqueuedRefundCount = 0;
  let unqueuedRefundAmount = 0;
  for (const reqRf of ordersRequiringRefund) {
    if (!recordedOrderIds.has(Number(reqRf.id))) {
      unqueuedRefundCount++;
      unqueuedRefundAmount += reqRf.outstanding_refund_amount;
    }
  }
  const totalPendingRefundsCount = refundStats.pending_count + unqueuedRefundCount;
  const totalPendingRefundsAmount = round2(refundStats.pending_amount + unqueuedRefundAmount);

  let pendingPayoutsCount = 0;
  let pendingPayoutsAmount = 0;
  let failedPayoutsCount = 0;
  let heldPayoutsCount = 0;
  let settledPayoutsNet = 0;
  let settledCommissionDeducted = 0;

  for (const p of payouts) {
    const pst = String(p.status || 'PENDING').toUpperCase();
    const amt = Number(p.amount || 0);
    const net = Number(p.net_amount ?? amt);
    if (['PENDING', 'PROCESSING'].includes(pst)) {
      pendingPayoutsCount++;
      pendingPayoutsAmount += net;
    } else if (pst === 'FAILED') {
      pendingPayoutsCount++;
      failedPayoutsCount++;
      pendingPayoutsAmount += net;
    } else if (pst === 'HELD') {
      pendingPayoutsCount++;
      heldPayoutsCount++;
      pendingPayoutsAmount += net;
    } else if (pst === 'SETTLED') {
      settledPayoutsNet += net;
      settledCommissionDeducted += Number(p.commission_deducted || 0);
    }
  }

  const openDisputes = allDisputes.filter(d => ACTIVE_DISPUTE_STATUSES.includes(String(d.status || '').toUpperCase()));
  const disputesClaimAmount = openDisputes.reduce((acc, od) => acc + Number(od.claim_amount || 0), 0);

  const grossCommissionTotal = round2(deliveredPaidSales * commissionRate);
  const onlineUnsettledNet = Math.max(0, round2(onlineDeliveredUnsettledSales * (1 - commissionRate)));
  const codCommissionOwed = round2(codDeliveredUnsettledSales * commissionRate);
  const onlineCommissionPending = round2(onlineDeliveredUnsettledSales * commissionRate);

  const settledOnlinePayoutCoverage = Math.max(0, settledPayoutsNet + settledCommissionDeducted);
  const remainingOnlineUnsettledNet = Math.max(0, round2(onlineUnsettledNet - settledPayoutsNet));
  const remainingOnlineCommission = (settledOnlinePayoutCoverage >= (onlineDeliveredUnsettledSales - 0.01) || remainingOnlineUnsettledNet <= 0.01)
    ? 0
    : Math.max(0, round2(onlineCommissionPending - settledCommissionDeducted));

  const outstandingCommissions = Math.max(0, round2(codCommissionOwed + remainingOnlineCommission));
  const availableSellerBalance = Math.max(0, round2(remainingOnlineUnsettledNet - codCommissionOwed - disputesClaimAmount - totalPendingRefundsAmount));

  const outstandingFinancialObligations = round2(
    totalPendingRefundsAmount +
    disputesClaimAmount +
    Math.max(0, round2(codCommissionOwed - remainingOnlineUnsettledNet)) +
    unsettledGatewayPaymentsAmount
  );

  const ordersResolvedStatus = unresolvedOrderBlockers.length === 0
    ? 'SUCCESS'
    : ((stageCShippedBlocking.length > 0 || stageDReturnWindowBlocking.length > 0) ? 'ON_HOLD' : 'PENDING');

  let refundsConfirmedStatus = 'SUCCESS';
  if (refundStats.failed_count > 0) {
    refundsConfirmedStatus = 'FAILED';
  } else if (refundStats.review_count > 0) {
    refundsConfirmedStatus = 'ON_HOLD';
  } else if (refundStats.processing_count > 0) {
    refundsConfirmedStatus = 'PROCESSING';
  } else if (totalPendingRefundsCount > 0 || unqueuedRefundCount > 0) {
    refundsConfirmedStatus = 'PENDING';
  }

  let payoutsSettledStatus = 'SUCCESS';
  if (failedPayoutsCount > 0) {
    payoutsSettledStatus = 'FAILED';
  } else if (heldPayoutsCount > 0) {
    payoutsSettledStatus = 'ON_HOLD';
  } else if (pendingPayoutsCount > 0 || availableSellerBalance > 0.01) {
    payoutsSettledStatus = 'PENDING';
  }

  let liabilitiesClearedStatus = 'SUCCESS';
  if (totalPendingRefundsAmount > 0.01 && refundStats.failed_count > 0) {
    liabilitiesClearedStatus = 'FAILED';
  } else if (unsettledGatewayPaymentsAmount > 0.01 || totalPendingRefundsAmount > 0.01 || outstandingCommissions > 0.01 || disputesClaimAmount > 0.01) {
    liabilitiesClearedStatus = 'PENDING';
  }

  const disputesResolvedStatus = openDisputes.length === 0 ? 'SUCCESS' : 'ON_HOLD';
  const recordsPreservedStatus = 'SUCCESS';

  const checks = [
    {
      check_key: 'orders_resolved',
      label: 'All eligible orders resolved (Rules A–E)',
      status: ordersResolvedStatus,
      is_blocking: 1,
      details: {
        blockers: unresolvedOrderBlockers,
        stage_a_pending_ids: stageAAutoCancellable,
        stage_b_accepted_ids: stageBRequiresApproval,
        stage_c_shipped_ids: stageCShippedBlocking,
        stage_d_return_window_ids: stageDReturnWindowBlocking
      }
    },
    {
      check_key: 'refunds_confirmed',
      label: 'All required refunds confirmed successful via gateway',
      status: refundsConfirmedStatus,
      is_blocking: 1,
      details: {
        ...refundStats,
        unqueued_count: unqueuedRefundCount,
        unqueued_amount: round2(unqueuedRefundAmount),
        total_pending_amount: totalPendingRefundsAmount
      }
    },
    {
      check_key: 'payouts_settled',
      label: 'All pending seller payouts settled or formally resolved',
      status: payoutsSettledStatus,
      is_blocking: 1,
      details: {
        pending_payouts_count: pendingPayoutsCount,
        pending_payouts_amount: round2(pendingPayoutsAmount),
        available_seller_balance: round2(availableSellerBalance)
      }
    },
    {
      check_key: 'liabilities_cleared',
      label: 'All financial liabilities, gateway settlements & commissions cleared',
      status: liabilitiesClearedStatus,
      is_blocking: 1,
      details: {
        unsettled_gateway_count: unsettledGatewayCount,
        unsettled_gateway_amount: round2(unsettledGatewayPaymentsAmount),
        outstanding_commissions: outstandingCommissions,
        refund_liabilities: totalPendingRefundsAmount,
        dispute_claims: round2(disputesClaimAmount)
      }
    },
    {
      check_key: 'disputes_resolved',
      label: 'No active disputes or customer complaints remain',
      status: disputesResolvedStatus,
      is_blocking: 1,
      details: {
        open_disputes_count: openDisputes.length,
        open_disputes: openDisputes
      }
    },
    {
      check_key: 'records_preserved',
      label: 'GST, financial & order history preserved for retention/audit',
      status: recordsPreservedStatus,
      is_blocking: 1,
      details: {
        gst_record: gstSaved,
        preserved_orders_count: enrichedOrders.length,
        preserved_refunds_count: allRefunds.length,
        preserved_payouts_count: payouts.length
      }
    }
  ];

  const allChecksPassed = checks.every(ck => ck.status === 'SUCCESS');

  const metrics = {
    total_products: Number(totalProducts || 0),
    active_products: Number(activeProducts || 0),
    total_orders: counts.total_orders,
    pending_orders: counts.pending_orders,
    confirmed_orders: counts.confirmed_orders,
    shipped_orders: counts.shipped_orders,
    delivered_orders: counts.delivered_orders,
    cancelled_orders: counts.cancelled_orders,
    total_successful_payments: round2(totalSuccessfulPayments),
    pending_refunds_count: totalPendingRefundsCount,
    pending_refunds_amount: totalPendingRefundsAmount,
    seller_wallet_balance: round2(availableSellerBalance),
    pending_payouts_count: pendingPayoutsCount,
    pending_payouts_amount: round2(pendingPayoutsAmount),
    outstanding_commissions: outstandingCommissions,
    open_disputes_count: openDisputes.length
  };

  const settlement = {
    commission_rate: commissionRate,
    delivered_paid_sales: round2(deliveredPaidSales),
    marketplace_commission: grossCommissionTotal,
    outstanding_commissions: outstandingCommissions,
    available_seller_balance: round2(availableSellerBalance),
    pending_payouts_count: pendingPayoutsCount,
    pending_payouts_amount: round2(pendingPayoutsAmount),
    settled_payouts_amount: round2(settledPayoutsNet),
    gateway_settled_ok: unsettledGatewayCount === 0,
    unsettled_gateway_count: unsettledGatewayCount,
    unsettled_gateway_amount: round2(unsettledGatewayPaymentsAmount),
    pending_disputes_count: openDisputes.length,
    disputes_claim_amount: round2(disputesClaimAmount),
    refund_liabilities_amount: totalPendingRefundsAmount,
    outstanding_financial_obligations: outstandingFinancialObligations,
    settlement_cleared: (payoutsSettledStatus === 'SUCCESS' && liabilitiesClearedStatus === 'SUCCESS' && disputesResolvedStatus === 'SUCCESS')
  };

  return {
    seller: safeSellerNode(seller, ctx),
    raw_seller: seller,
    metrics,
    settlement,
    checks,
    all_checks_passed: allChecksPassed,
    ready_for_approval: allChecksPassed,
    orders_enriched: enrichedOrders,
    refunds: allRefunds,
    refund_stats: refundStats,
    orders_requiring_refund: ordersRequiringRefund,
    payouts,
    disputes: allDisputes,
    open_disputes: openDisputes,
    gst: gstSaved,
    blockers: {
      orders: unresolvedOrderBlockers,
      stage_a_pending_ids: stageAAutoCancellable,
      stage_b_accepted_ids: stageBRequiresApproval,
      stage_c_shipped_ids: stageCShippedBlocking,
      stage_d_return_window_ids: stageDReturnWindowBlocking
    }
  };
}

async function computeSellerFinancialAndChecklistNode(pool, ctx, sellerId, deletionRequestId = null, useDb = null) {
  const cfg = getSellerDeletionConfigNode();
  const dbActive = useDb !== null ? useDb : await checkDbReachable(pool, ctx);

  if (dbActive) {
    await ensureSellerDeletionSchemaNode(pool);
    const [uRows] = await pool.query('SELECT * FROM users WHERE id = ? LIMIT 1', [sellerId]);
    if (uRows.length === 0) return { error: 'Seller not found.' };
    const seller = uRows[0];

    const [pRows] = await pool.query(
      'SELECT COUNT(*) AS total_products, SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_products FROM products WHERE seller_id = ?',
      [sellerId]
    );
    const totalProducts = Number(pRows[0]?.total_products || 0);
    const activeProducts = Number(pRows[0]?.active_products || 0);

    const enrichedOrders = await getSellerOrdersDetailedNode(pool, ctx, sellerId, true);
    const [allRefunds] = await pool.query('SELECT * FROM seller_refund_records WHERE seller_id = ? ORDER BY id ASC', [sellerId]);
    const [payouts] = await pool.query('SELECT * FROM seller_payouts WHERE seller_id = ? ORDER BY id DESC', [sellerId]);
    const [allDisputes] = await pool.query('SELECT * FROM order_disputes WHERE seller_id = ? ORDER BY id DESC', [sellerId]);

    let gstSaved = null;
    try {
      const [gRows] = await pool.query('SELECT id, gstin, status FROM seller_gst_details WHERE seller_id = ? LIMIT 1', [sellerId]);
      gstSaved = gRows[0] || null;
    } catch (e) {}

    const computed = finalizeComputedSummary({
      seller,
      totalProducts,
      activeProducts,
      enrichedOrders,
      allRefunds,
      payouts,
      allDisputes,
      gstSaved,
      commissionRate: cfg.commission_rate,
      ctx
    });

    if (deletionRequestId !== null) {
      for (const ck of computed.checks) {
        const detailsJson = JSON.stringify(ck.details);
        const [ex] = await pool.query(
          'SELECT id FROM seller_deletion_checks WHERE deletion_request_id = ? AND check_key = ? LIMIT 1',
          [deletionRequestId, ck.check_key]
        );
        if (ex.length > 0) {
          if (ck.status === 'SUCCESS') {
            await pool.query(
              `UPDATE seller_deletion_checks
               SET label = ?, status = ?, details_json = ?, resolved_at = COALESCE(resolved_at, CURRENT_TIMESTAMP), updated_at = CURRENT_TIMESTAMP
               WHERE id = ?`,
              [ck.label, ck.status, detailsJson, ex[0].id]
            );
          } else {
            await pool.query(
              `UPDATE seller_deletion_checks
               SET label = ?, status = ?, details_json = ?, resolved_at = NULL, updated_at = CURRENT_TIMESTAMP
               WHERE id = ?`,
              [ck.label, ck.status, detailsJson, ex[0].id]
            );
          }
        } else {
          await pool.query(
            `INSERT INTO seller_deletion_checks
              (deletion_request_id, check_key, label, status, is_blocking, details_json, resolved_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, 1, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)`,
            [deletionRequestId, ck.check_key, ck.label, ck.status, detailsJson, ck.status === 'SUCCESS' ? nowSqlUtc() : null]
          );
        }
      }
    }

    return computed;
  }

  // Fallback mode
  const fbSellers = ctx.getFallbackSellers();
  const seller = fbSellers.find(s => Number(s.id) === Number(sellerId));
  if (!seller) return { error: 'Seller not found.' };

  const fbProducts = ctx.getFallbackProducts();
  const sellerProds = fbProducts.filter(p => Number(p.seller_id) === Number(sellerId));
  const totalProducts = sellerProds.length;
  const activeProducts = sellerProds.filter(p => Number(p.is_active ?? 1) === 1).length;

  const store = ctx.getFallbackDeletionStore();
  const enrichedOrders = await getSellerOrdersDetailedNode(pool, ctx, sellerId, false);
  const allRefunds = store.refundRecords.filter(r => Number(r.seller_id) === Number(sellerId)).sort((a, b) => Number(a.id) - Number(b.id));
  const payouts = store.payouts.filter(p => Number(p.seller_id) === Number(sellerId)).sort((a, b) => Number(b.id) - Number(a.id));
  const allDisputes = store.disputes.filter(d => Number(d.seller_id) === Number(sellerId)).sort((a, b) => Number(b.id) - Number(a.id));
  const gstSaved = (store.gstDetails || []).find(g => Number(g.seller_id) === Number(sellerId)) || null;

  const computed = finalizeComputedSummary({
    seller,
    totalProducts,
    activeProducts,
    enrichedOrders,
    allRefunds,
    payouts,
    allDisputes,
    gstSaved,
    commissionRate: cfg.commission_rate,
    ctx
  });

  if (deletionRequestId !== null) {
    const nowStr = nowSqlUtc();
    for (const ck of computed.checks) {
      const existing = store.deletionChecks.find(c => Number(c.deletion_request_id) === Number(deletionRequestId) && c.check_key === ck.check_key);
      if (existing) {
        existing.label = ck.label;
        existing.status = ck.status;
        existing.details_json = JSON.stringify(ck.details);
        existing.resolved_at = ck.status === 'SUCCESS' ? (existing.resolved_at || nowStr) : null;
        existing.updated_at = nowStr;
      } else {
        store.deletionChecks.push({
          id: store.nextIds.deletionCheck++,
          deletion_request_id: Number(deletionRequestId),
          check_key: ck.check_key,
          label: ck.label,
          status: ck.status,
          is_blocking: 1,
          details_json: JSON.stringify(ck.details),
          resolved_at: ck.status === 'SUCCESS' ? nowStr : null,
          created_at: nowStr,
          updated_at: nowStr
        });
      }
    }
  }

  return computed;
}

function formatWorkspacePayload({ req, computed, logs }) {
  const sellerId = Number(req.seller_id);
  const ordersOut = computed.orders_enriched.map(eo => ({
    id: eo.id,
    order_number: eo.order_number,
    buyer_name: eo.buyer_name,
    buyer_phone: eo.buyer_phone,
    raw_order_status: eo.raw_order_status,
    effective_status: eo.effective_status,
    payment_method: eo.payment_method,
    payment_status: eo.payment_status,
    gateway_payment_id: eo.gateway_payment_id,
    is_multi_vendor: eo.is_multi_vendor,
    seller_gross: eo.seller_gross,
    order_total_amount: eo.order_total_amount,
    captured_total: eo.captured_total,
    seller_paid_share: eo.seller_paid_share,
    is_paid: eo.is_paid,
    seller_succeeded_refund: eo.seller_succeeded_refund,
    outstanding_refund_amount: eo.outstanding_refund_amount,
    delivered_at: eo.delivered_at,
    return_window_ends_at: eo.return_window_ends_at,
    return_window_active: eo.return_window_active,
    open_disputes_count: eo.open_disputes.length,
    is_gateway_settled: eo.is_gateway_settled,
    settled_at: eo.settled_at,
    created_at: eo.order?.created_at || null
  }));

  const refundsOut = computed.refunds.map(r => ({
    id: Number(r.id),
    idempotency_key: r.idempotency_key,
    order_id: Number(r.order_id),
    seller_id: Number(r.seller_id),
    gateway: r.gateway,
    gateway_payment_id: r.gateway_payment_id,
    gateway_refund_id: r.gateway_refund_id,
    order_amount: Number(r.order_amount || 0),
    eligible_refund_amount: Number(r.eligible_refund_amount || 0),
    refunded_amount: Number(r.refunded_amount || 0),
    status: r.status,
    badge_color: getStatusColorBadgeNode(r.status),
    gateway_response: safeJsonParse(r.gateway_response, null),
    failure_reason: r.failure_reason || null,
    attempts: Number(r.attempts || 0),
    last_attempted_at: r.last_attempted_at || null,
    confirmed_at: r.confirmed_at || null,
    created_at: r.created_at || null
  }));

  const formattedLogs = logs.map(lg => ({
    ...lg,
    id: Number(lg.id),
    metadata: safeJsonParse(lg.metadata_json, null)
  }));

  const finalDeleteAllowed = (
    req.stage === 'APPROVED_FOR_DELETION' &&
    req.status === 'APPROVED_FOR_DELETION' &&
    Boolean(req.confirmation_token) &&
    computed.all_checks_passed
  );

  return {
    deletion_request: {
      id: Number(req.id),
      seller_id: sellerId,
      requested_by: req.requested_by ? Number(req.requested_by) : null,
      stage: req.stage,
      status: req.status,
      badge_color: getStatusColorBadgeNode(req.status),
      request_reason: req.request_reason || null,
      admin_remarks: req.admin_remarks || null,
      hold_reason: req.hold_reason || null,
      rejection_reason: req.rejection_reason || null,
      has_confirmation_token: Boolean(req.confirmation_token),
      confirmation_token: req.confirmation_token || null,
      confirmation_expires_at: toIsoStringSafe(req.confirmation_expires_at),
      expected_confirmation_phrase: `DELETE SELLER #${sellerId}`,
      approved_by: req.approved_by ? Number(req.approved_by) : null,
      approved_at: toIsoStringSafe(req.approved_at),
      deleted_by: req.deleted_by ? Number(req.deleted_by) : null,
      deleted_at: toIsoStringSafe(req.deleted_at),
      created_at: toIsoStringSafe(req.created_at),
      updated_at: toIsoStringSafe(req.updated_at),
      final_delete_allowed: finalDeleteAllowed,
      approve_allowed: computed.all_checks_passed && !TERMINAL_DELETION_STATUSES.includes(req.status)
    },
    seller: computed.seller,
    metrics: computed.metrics,
    orders: ordersOut,
    blockers: computed.blockers,
    refunds: refundsOut,
    refund_stats: computed.refund_stats,
    payouts: computed.payouts,
    disputes: computed.disputes,
    settlement: computed.settlement,
    checks: computed.checks,
    all_checks_passed: computed.all_checks_passed,
    gst: computed.gst,
    audit_logs: formattedLogs
  };
}

async function buildDeletionWorkspacePayloadNode(pool, ctx, deletionRequestId, useDb = null) {
  const dbActive = useDb !== null ? useDb : await checkDbReachable(pool, ctx);

  if (dbActive) {
    await ensureSellerDeletionSchemaNode(pool);
    const [rRows] = await pool.query('SELECT * FROM seller_deletion_requests WHERE id = ? LIMIT 1', [deletionRequestId]);
    if (rRows.length === 0) return null;
    const req = rRows[0];
    const sellerId = Number(req.seller_id);

    const computed = await computeSellerFinancialAndChecklistNode(pool, ctx, sellerId, deletionRequestId, true);
    if (computed.error) return null;

    try {
      await pool.query('UPDATE seller_deletion_requests SET financial_summary_json = ? WHERE id = ?', [
        JSON.stringify(computed.settlement),
        deletionRequestId
      ]);
    } catch (e) {}

    const [logs] = await pool.query(
      'SELECT * FROM seller_deletion_audit_logs WHERE deletion_request_id = ? OR seller_id = ? ORDER BY id DESC',
      [deletionRequestId, sellerId]
    );
    return formatWorkspacePayload({ req, computed, logs });
  }

  // Fallback mode
  const store = ctx.getFallbackDeletionStore();
  const req = store.deletionRequests.find(r => Number(r.id) === Number(deletionRequestId));
  if (!req) return null;
  const sellerId = Number(req.seller_id);

  const computed = await computeSellerFinancialAndChecklistNode(pool, ctx, sellerId, deletionRequestId, false);
  if (computed.error) return null;
  req.financial_summary_json = JSON.stringify(computed.settlement);

  const logs = store.auditLogs
    .filter(l => Number(l.deletion_request_id) === Number(deletionRequestId) || Number(l.seller_id) === sellerId)
    .sort((a, b) => Number(b.id) - Number(a.id));

  return formatWorkspacePayload({ req, computed, logs });
}

function callRazorpayHttpNode(method, urlStr, keyId, keySecret, payload = null, extraHeaders = {}) {
  return new Promise((resolve) => {
    let parsed;
    try {
      parsed = new URL(urlStr);
    } catch (e) {
      return resolve({ ok: false, statusCode: 0, data: null, raw: '', error: `Invalid gateway URL: ${urlStr}` });
    }

    const bodyStr = payload !== null && payload !== undefined ? JSON.stringify(payload) : null;
    const authToken = Buffer.from(`${keyId}:${keySecret}`).toString('base64');
    const headers = {
      'Content-Type': 'application/json',
      'Authorization': `Basic ${authToken}`,
      ...extraHeaders
    };
    if (bodyStr !== null) {
      headers['Content-Length'] = Buffer.byteLength(bodyStr);
    }

    const transport = parsed.protocol === 'http:' ? http : https;
    const req = transport.request(
      {
        protocol: parsed.protocol,
        hostname: parsed.hostname,
        port: parsed.port || (parsed.protocol === 'http:' ? 80 : 443),
        path: `${parsed.pathname}${parsed.search}`,
        method: String(method || 'GET').toUpperCase(),
        headers,
        timeout: 20000
      },
      (res) => {
        let raw = '';
        res.on('data', chunk => { raw += chunk.toString(); });
        res.on('end', () => {
          const decoded = safeJsonParse(raw, null);
          resolve({
            ok: res.statusCode >= 200 && res.statusCode < 300,
            statusCode: res.statusCode || 0,
            data: decoded && typeof decoded === 'object' ? decoded : null,
            raw,
            error: ''
          });
        });
      }
    );

    req.on('timeout', () => {
      req.destroy(new Error('Razorpay HTTP request timed out'));
    });

    req.on('error', (err) => {
      resolve({
        ok: false,
        statusCode: 0,
        data: null,
        raw: '',
        error: err.message || 'HTTP request failed.'
      });
    });

    if (bodyStr !== null) req.write(bodyStr);
    req.end();
  });
}

async function queueEligibleRefundsForSellerNode(pool, ctx, deletionRequestId, sellerId, adminId, useDb = false) {
  const computed = await computeSellerFinancialAndChecklistNode(pool, ctx, sellerId, deletionRequestId, useDb);
  const queued = [];

  for (const eo of computed.orders_requiring_refund || []) {
    const orderId = Number(eo.id);
    const eligibleAmount = round2(eo.outstanding_refund_amount);
    if (eligibleAmount <= 0) continue;

    const idempotencyKey = `refund_del_${deletionRequestId}_ord_${orderId}_sel_${sellerId}`;
    const gatewayPaymentId = eo.gateway_payment_id;
    const hasGatewayId = Boolean(gatewayPaymentId);
    const initialStatus = hasGatewayId ? 'REFUND_PENDING' : 'REFUND_REQUIRES_REVIEW';
    const failureReason = hasGatewayId ? null : 'Paid order is missing original gateway_payment_id. Requires manual admin verification before refunding.';

    if (useDb) {
      const [exRows] = await pool.query(
        'SELECT * FROM seller_refund_records WHERE (order_id = ? AND seller_id = ?) OR idempotency_key = ? LIMIT 1',
        [orderId, sellerId, idempotencyKey]
      );
      if (exRows.length > 0) {
        const ex = exRows[0];
        if (ex.status !== 'REFUND_SUCCESS' && !ex.deletion_request_id) {
          await pool.query('UPDATE seller_refund_records SET deletion_request_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?', [deletionRequestId, ex.id]);
        }
        continue;
      }

      const [insRes] = await pool.query(
        `INSERT INTO seller_refund_records
          (idempotency_key, deletion_request_id, order_id, order_item_id, seller_id, payment_transaction_id,
           gateway, gateway_payment_id, order_amount, eligible_refund_amount, refunded_amount, status, failure_reason, initiated_by, created_at, updated_at)
         VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, 0.00, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)`,
        [
          idempotencyKey,
          deletionRequestId,
          orderId,
          sellerId,
          eo.primary_payment_transaction_id || null,
          eo.payment_gateway || 'razorpay',
          gatewayPaymentId || null,
          eo.seller_gross,
          eligibleAmount,
          initialStatus,
          failureReason,
          adminId
        ]
      );
      queued.push(insRes.insertId);
    } else {
      const store = ctx.getFallbackDeletionStore();
      const ex = store.refundRecords.find(r => (Number(r.order_id) === orderId && Number(r.seller_id) === Number(sellerId)) || r.idempotency_key === idempotencyKey);
      if (ex) {
        if (ex.status !== 'REFUND_SUCCESS' && !ex.deletion_request_id) {
          ex.deletion_request_id = Number(deletionRequestId);
          ex.updated_at = nowSqlUtc();
        }
        continue;
      }
      const newId = store.nextIds.refundRecord++;
      const nowStr = nowSqlUtc();
      store.refundRecords.push({
        id: newId,
        idempotency_key: idempotencyKey,
        deletion_request_id: Number(deletionRequestId),
        order_id: orderId,
        order_item_id: null,
        seller_id: Number(sellerId),
        payment_transaction_id: eo.primary_payment_transaction_id || null,
        gateway: eo.payment_gateway || 'razorpay',
        gateway_payment_id: gatewayPaymentId || null,
        gateway_refund_id: null,
        order_amount: eo.seller_gross,
        eligible_refund_amount: eligibleAmount,
        refunded_amount: 0,
        status: initialStatus,
        gateway_response: null,
        failure_reason: failureReason,
        attempts: 0,
        initiated_by: adminId,
        last_attempted_at: null,
        confirmed_at: null,
        created_at: nowStr,
        updated_at: nowStr
      });
      queued.push(newId);
    }
  }

  return queued;
}

async function executeSingleRefundRecordNode(pool, ctx, refundRecordId, adminId, useDb = false) {
  let rec;
  let order;

  if (useDb) {
    const conn = await pool.getConnection();
    try {
      await conn.beginTransaction();
      const [recRows] = await conn.query('SELECT * FROM seller_refund_records WHERE id = ? LIMIT 1 FOR UPDATE', [refundRecordId]);
      if (recRows.length === 0) {
        await conn.rollback();
        conn.release();
        return { status: 'NOT_FOUND' };
      }
      rec = recRows[0];
      if (rec.status === 'REFUND_SUCCESS') {
        await conn.commit();
        conn.release();
        return { status: 'REFUND_SUCCESS', idempotent_skip: true, record: rec };
      }

      const [oRows] = await conn.query('SELECT * FROM orders WHERE id = ? LIMIT 1 FOR UPDATE', [rec.order_id]);
      if (oRows.length === 0) {
        await conn.rollback();
        conn.release();
        return { status: 'REFUND_REQUIRES_REVIEW', error: 'Original order record missing.' };
      }
      order = oRows[0];

      let captured = (order.captured_amount !== null && order.captured_amount !== undefined) ? Number(order.captured_amount) : 0;
      if (captured <= 0) {
        const [ptRows] = await conn.query(
          "SELECT COALESCE(SUM(amount), 0) AS s FROM payment_transactions WHERE order_id = ? AND transaction_type = 'capture' AND status IN ('captured','settled','refunded')",
          [order.id]
        );
        captured = Number(ptRows[0]?.s || 0);
      }
      if (captured <= 0 && String(order.payment_status || '').toLowerCase() === 'paid') {
        captured = Number(order.total_amount || 0);
      }

      const alreadyRefunded = Number(order.refunded_amount || 0);
      const eligibleAmt = round2(rec.eligible_refund_amount);
      const remainingCap = Math.max(0, round2(captured - alreadyRefunded));

      if (eligibleAmt <= 0 || eligibleAmt > (remainingCap + 0.01)) {
        const reason = `Refund cap validation failed: requested ${eligibleAmt}, remaining captured refundable balance is ${remainingCap}.`;
        await conn.query("UPDATE seller_refund_records SET status = 'REFUND_REQUIRES_REVIEW', failure_reason = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?", [reason, refundRecordId]);
        await conn.commit();
        conn.release();
        return { status: 'REFUND_REQUIRES_REVIEW', error: reason };
      }

      const gatewayPaymentId = String(rec.gateway_payment_id || order.gateway_payment_id || '').trim();
      if (!gatewayPaymentId) {
        const reason = 'Missing gateway_payment_id; cannot process automatic gateway refund.';
        await conn.query("UPDATE seller_refund_records SET status = 'REFUND_REQUIRES_REVIEW', failure_reason = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?", [reason, refundRecordId]);
        await conn.commit();
        conn.release();
        return { status: 'REFUND_REQUIRES_REVIEW', error: reason };
      }

      await conn.query(
        `UPDATE seller_refund_records
         SET status = 'REFUND_PROCESSING',
             attempts = attempts + 1,
             last_attempted_at = CURRENT_TIMESTAMP,
             initiated_by = COALESCE(initiated_by, ?),
             updated_at = CURRENT_TIMESTAMP
         WHERE id = ?`,
        [adminId, refundRecordId]
      );
      await conn.commit();
      conn.release();
    } catch (e) {
      try { await conn.rollback(); } catch (_) {}
      conn.release();
      throw e;
    }

    const [freshRecs] = await pool.query('SELECT * FROM seller_refund_records WHERE id = ? LIMIT 1', [refundRecordId]);
    rec = freshRecs[0];
    const [freshOrds] = await pool.query('SELECT * FROM orders WHERE id = ? LIMIT 1', [rec.order_id]);
    order = freshOrds[0];
  } else {
    const store = ctx.getFallbackDeletionStore();
    rec = store.refundRecords.find(r => Number(r.id) === Number(refundRecordId));
    if (!rec) return { status: 'NOT_FOUND' };
    if (rec.status === 'REFUND_SUCCESS') {
      return { status: 'REFUND_SUCCESS', idempotent_skip: true, record: rec };
    }

    order = ctx.getFallbackOrders().find(o => Number(o.id) === Number(rec.order_id));
    if (!order) return { status: 'REFUND_REQUIRES_REVIEW', error: 'Original order record missing.' };

    let captured = (order.captured_amount !== null && order.captured_amount !== undefined) ? Number(order.captured_amount) : 0;
    if (captured <= 0) {
      captured = store.paymentTransactions
        .filter(t => Number(t.order_id) === Number(order.id) && String(t.transaction_type || 'capture') === 'capture' && ['captured', 'settled', 'refunded'].includes(String(t.status || '').toLowerCase()))
        .reduce((acc, c) => acc + Number(c.amount || 0), 0);
    }
    if (captured <= 0 && String(order.payment_status || '').toLowerCase() === 'paid') {
      captured = Number(order.total_amount || 0);
    }

    const alreadyRefunded = Number(order.refunded_amount || 0);
    const eligibleAmt = round2(rec.eligible_refund_amount);
    const remainingCap = Math.max(0, round2(captured - alreadyRefunded));

    if (eligibleAmt <= 0 || eligibleAmt > (remainingCap + 0.01)) {
      rec.status = 'REFUND_REQUIRES_REVIEW';
      rec.failure_reason = `Refund cap validation failed: requested ${eligibleAmt}, remaining captured refundable balance is ${remainingCap}.`;
      rec.updated_at = nowSqlUtc();
      return { status: 'REFUND_REQUIRES_REVIEW', error: rec.failure_reason };
    }

    const gatewayPaymentId = String(rec.gateway_payment_id || order.gateway_payment_id || '').trim();
    if (!gatewayPaymentId) {
      rec.status = 'REFUND_REQUIRES_REVIEW';
      rec.failure_reason = 'Missing gateway_payment_id; cannot process automatic gateway refund.';
      rec.updated_at = nowSqlUtc();
      return { status: 'REFUND_REQUIRES_REVIEW', error: rec.failure_reason };
    }

    rec.status = 'REFUND_PROCESSING';
    rec.attempts = Number(rec.attempts || 0) + 1;
    rec.last_attempted_at = nowSqlUtc();
    rec.initiated_by = rec.initiated_by || adminId;
    rec.updated_at = nowSqlUtc();
  }

  const cfg = getSellerDeletionConfigNode();
  const keyId = cfg.razorpay_key_id;
  const keySecret = cfg.razorpay_key_secret;
  const baseUrl = cfg.razorpay_api_base;

  const persistRefundFailure = async (errMsg, rawJson, gwRefundId = null) => {
    if (useDb) {
      await pool.query(
        `UPDATE seller_refund_records
         SET status = 'REFUND_FAILED',
             gateway_refund_id = COALESCE(?, gateway_refund_id),
             gateway_response = ?,
             failure_reason = ?,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = ?`,
        [gwRefundId, rawJson, errMsg, refundRecordId]
      );
    } else {
      rec.status = 'REFUND_FAILED';
      if (gwRefundId) rec.gateway_refund_id = gwRefundId;
      rec.gateway_response = rawJson;
      rec.failure_reason = errMsg;
      rec.updated_at = nowSqlUtc();
    }
    await sendDeletionNotificationNode(pool, ctx, {
      phone: order.buyer_phone || null,
      title: 'Refund Failed',
      message: `Refund for order ${order.order_number} failed: ${errMsg}`,
      type: 'refund_failed',
      data: { order_id: Number(order.id), refund_id: Number(refundRecordId) }
    }, useDb);
  };

  if (!keyId || !keySecret) {
    const reason = 'Razorpay credentials (RAZORPAY_KEY_ID / RAZORPAY_KEY_SECRET) are not configured. Real gateway confirmation is required.';
    await persistRefundFailure(reason, JSON.stringify({ error: { code: 'MISSING_GATEWAY_CREDENTIALS', description: reason } }));
    return { status: 'REFUND_FAILED', error: reason };
  }

  const gatewayPaymentId = String(rec.gateway_payment_id || order.gateway_payment_id || '').trim();
  const existingGatewayRefundId = String(rec.gateway_refund_id || '').trim();

  if (Number(rec.attempts || 1) === 1) {
    await sendDeletionNotificationNode(pool, ctx, {
      phone: order.buyer_phone || null,
      title: 'Refund Initiated',
      message: `A refund of ₹${round2(rec.eligible_refund_amount).toFixed(2)} has been initiated for order ${order.order_number}.`,
      type: 'refund_initiated',
      data: { order_id: Number(order.id), refund_id: Number(refundRecordId) }
    }, useDb);
  }

  let httpRes;
  if (existingGatewayRefundId) {
    httpRes = await callRazorpayHttpNode('GET', `${baseUrl}/refunds/${encodeURIComponent(existingGatewayRefundId)}`, keyId, keySecret);
  } else {
    const amountPaise = Math.round(Number(rec.eligible_refund_amount) * 100);
    httpRes = await callRazorpayHttpNode(
      'POST',
      `${baseUrl}/payments/${encodeURIComponent(gatewayPaymentId)}/refund`,
      keyId,
      keySecret,
      {
        amount: amountPaise,
        speed: 'normal',
        notes: {
          deletion_request_id: String(rec.deletion_request_id || ''),
          order_id: String(rec.order_id),
          seller_id: String(rec.seller_id),
          idempotency_key: String(rec.idempotency_key)
        }
      },
      { 'X-Razorpay-Idempotency': String(rec.idempotency_key) }
    );
  }

  const rawJson = httpRes.raw || JSON.stringify({ error: { description: httpRes.error || `HTTP ${httpRes.statusCode}` } });
  const data = httpRes.data;

  if (!httpRes.ok || !data || !data.id) {
    const errMsg = (data && data.error && (data.error.description || data.error.reason)) || httpRes.error || `Gateway returned HTTP ${httpRes.statusCode}`;
    await persistRefundFailure(String(errMsg), rawJson);
    return { status: 'REFUND_FAILED', error: String(errMsg) };
  }

  const gwStatus = String(data.status || '').trim().toLowerCase();
  const gwRefundId = String(data.id);

  if (gwStatus === 'processed') {
    const refundedAmt = (data.amount !== undefined && !isNaN(Number(data.amount)))
      ? Math.min(Number(rec.eligible_refund_amount), round2(Number(data.amount) / 100))
      : round2(rec.eligible_refund_amount);

    const newOrderRefunded = round2(Number(order.refunded_amount || 0) + refundedAmt);
    const capTotal = (order.captured_amount !== null && order.captured_amount !== undefined)
      ? Number(order.captured_amount)
      : Number(order.total_amount || 0);
    const newPayStatus = (newOrderRefunded >= capTotal - 0.01) ? 'refunded' : 'partially_refunded';

    if (useDb) {
      const conn = await pool.getConnection();
      try {
        await conn.beginTransaction();
        await conn.query(
          `UPDATE seller_refund_records
           SET status = 'REFUND_SUCCESS',
               gateway_refund_id = ?,
               refunded_amount = ?,
               gateway_response = ?,
               failure_reason = NULL,
               confirmed_at = CURRENT_TIMESTAMP,
               updated_at = CURRENT_TIMESTAMP
           WHERE id = ?`,
          [gwRefundId, refundedAmt, rawJson, refundRecordId]
        );
        await conn.query(
          'UPDATE orders SET refunded_amount = ?, payment_status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?',
          [newOrderRefunded, newPayStatus, order.id]
        );
        await conn.query(
          `INSERT INTO payment_transactions
            (order_id, seller_id, gateway, gateway_order_id, gateway_payment_id, transaction_type, amount, currency, status, gateway_payload, created_at, updated_at)
           VALUES (?, ?, 'razorpay', ?, ?, 'refund', ?, 'INR', 'refunded', ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)`,
          [order.id, rec.seller_id, order.gateway_order_id || null, gwRefundId, refundedAmt, rawJson]
        );
        await conn.commit();
        conn.release();
      } catch (e) {
        try { await conn.rollback(); } catch (_) {}
        conn.release();
        throw e;
      }
    } else {
      const store = ctx.getFallbackDeletionStore();
      const nowStr = nowSqlUtc();
      rec.status = 'REFUND_SUCCESS';
      rec.gateway_refund_id = gwRefundId;
      rec.refunded_amount = refundedAmt;
      rec.gateway_response = rawJson;
      rec.failure_reason = null;
      rec.confirmed_at = nowStr;
      rec.updated_at = nowStr;

      order.refunded_amount = newOrderRefunded;
      order.payment_status = newPayStatus;
      order.updated_at = nowStr;

      store.paymentTransactions.push({
        id: store.nextIds.paymentTransaction++,
        order_id: Number(order.id),
        seller_id: Number(rec.seller_id),
        gateway: 'razorpay',
        gateway_order_id: order.gateway_order_id || null,
        gateway_payment_id: gwRefundId,
        transaction_type: 'refund',
        amount: refundedAmt,
        currency: 'INR',
        status: 'refunded',
        gateway_payload: rawJson,
        settled_at: null,
        created_at: nowStr,
        updated_at: nowStr
      });
    }

    await sendDeletionNotificationNode(pool, ctx, {
      phone: order.buyer_phone || null,
      title: 'Refund Succeeded',
      message: `Your refund of ₹${refundedAmt.toFixed(2)} for order ${order.order_number} has been confirmed (${gwRefundId}).`,
      type: 'refund_success',
      data: { order_id: Number(order.id), refund_id: Number(refundRecordId), gateway_refund_id: gwRefundId }
    }, useDb);

    return { status: 'REFUND_SUCCESS', gateway_refund_id: gwRefundId, refunded_amount: refundedAmt };
  }

  if (['pending', 'created', 'initiated'].includes(gwStatus)) {
    if (useDb) {
      await pool.query(
        `UPDATE seller_refund_records
         SET status = 'REFUND_PROCESSING',
             gateway_refund_id = ?,
             gateway_response = ?,
             failure_reason = NULL,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = ?`,
        [gwRefundId, rawJson, refundRecordId]
      );
    } else {
      rec.status = 'REFUND_PROCESSING';
      rec.gateway_refund_id = gwRefundId;
      rec.gateway_response = rawJson;
      rec.failure_reason = null;
      rec.updated_at = nowSqlUtc();
    }
    return { status: 'REFUND_PROCESSING', gateway_refund_id: gwRefundId };
  }

  const errMsg = String((data.error && data.error.description) || `Gateway returned non-success refund status: ${gwStatus}`);
  await persistRefundFailure(errMsg, rawJson, gwRefundId);
  return { status: 'REFUND_FAILED', error: errMsg };
}

function deriveNextStageAndStatusNode(computed, currentStage, currentStatus) {
  if (['DELETED', 'DELETION_REJECTED', 'APPROVED_FOR_DELETION'].includes(currentStatus)) {
    return [currentStage, currentStatus];
  }
  if (currentStatus === 'DELETION_ON_HOLD') {
    return [currentStage, 'DELETION_ON_HOLD'];
  }

  const checksByKey = {};
  for (const ck of computed.checks || []) {
    checksByKey[ck.check_key] = ck.status;
  }

  if ((checksByKey.orders_resolved || 'PENDING') !== 'SUCCESS') {
    return ['ORDER_VERIFICATION', 'ORDER_VERIFICATION'];
  }
  if ((checksByKey.refunds_confirmed || 'PENDING') === 'FAILED') {
    return ['REFUND_PROCESSING', 'REFUND_FAILED'];
  }
  if ((checksByKey.refunds_confirmed || 'PENDING') !== 'SUCCESS') {
    return ['REFUND_PROCESSING', 'REFUND_PROCESSING'];
  }
  if (
    (checksByKey.payouts_settled || 'PENDING') !== 'SUCCESS' ||
    (checksByKey.liabilities_cleared || 'PENDING') !== 'SUCCESS' ||
    (checksByKey.disputes_resolved || 'PENDING') !== 'SUCCESS'
  ) {
    return ['FINANCIAL_SETTLEMENT', 'SETTLEMENT_PENDING'];
  }
  if (computed.all_checks_passed) {
    return ['ADMIN_REVIEW', 'ADMIN_REVIEW'];
  }

  return [currentStage, currentStatus];
}

async function deletionListRequestsNode(pool, ctx, filters = {}) {
  const dbActive = await checkDbReachable(pool, ctx);
  const statusFilter = String(filters.status || '').trim();
  const stageFilter = String(filters.stage || '').trim();
  const searchFilter = String(filters.search || filters.q || '').trim().toLowerCase();

  let rows = [];
  const kpis = { total: 0, pending: 0, processing: 0, failed: 0, on_hold: 0, approved: 0, deleted: 0, rejected: 0 };

  if (dbActive) {
    await ensureSellerDeletionSchemaNode(pool);
    const where = ['1=1'];
    const params = [];
    if (statusFilter) {
      where.push('sdr.status = ?');
      params.push(statusFilter);
    }
    if (stageFilter) {
      where.push('sdr.stage = ?');
      params.push(stageFilter);
    }
    if (searchFilter) {
      const s = `%${searchFilter}%`;
      where.push('(LOWER(u.seller_name) LIKE ? OR LOWER(u.name) LIKE ? OR LOWER(u.email) LIKE ? OR u.phone LIKE ? OR CAST(sdr.seller_id AS CHAR) LIKE ?)');
      params.push(s, s, s, s, s);
    }

    const [dbRows] = await pool.query(
      `SELECT sdr.*, u.seller_name, u.name AS user_name, u.email AS user_email, u.phone AS user_phone, u.account_status AS seller_account_status
       FROM seller_deletion_requests sdr
       LEFT JOIN users u ON sdr.seller_id = u.id
       WHERE ${where.join(' AND ')}
       ORDER BY sdr.id DESC`,
      params
    );
    rows = dbRows;

    const [allKpiRows] = await pool.query('SELECT status FROM seller_deletion_requests');
    for (const kr of allKpiRows) {
      kpis.total++;
      const st = String(kr.status || '').toUpperCase();
      if (st === 'DELETED') kpis.deleted++;
      else if (st === 'DELETION_REJECTED') kpis.rejected++;
      else if (st === 'APPROVED_FOR_DELETION') kpis.approved++;
      else if (st === 'DELETION_ON_HOLD') kpis.on_hold++;
      else if (st === 'REFUND_FAILED') kpis.failed++;
      else if (['DELETION_REQUESTED', 'SETTLEMENT_PENDING'].includes(st)) kpis.pending++;
      else kpis.processing++;
    }
  } else {
    const store = ctx.getFallbackDeletionStore();
    const sellers = ctx.getFallbackSellers();

    for (const kr of store.deletionRequests) {
      kpis.total++;
      const st = String(kr.status || '').toUpperCase();
      if (st === 'DELETED') kpis.deleted++;
      else if (st === 'DELETION_REJECTED') kpis.rejected++;
      else if (st === 'APPROVED_FOR_DELETION') kpis.approved++;
      else if (st === 'DELETION_ON_HOLD') kpis.on_hold++;
      else if (st === 'REFUND_FAILED') kpis.failed++;
      else if (['DELETION_REQUESTED', 'SETTLEMENT_PENDING'].includes(st)) kpis.pending++;
      else kpis.processing++;
    }

    rows = store.deletionRequests
      .map(r => {
        const u = sellers.find(s => Number(s.id) === Number(r.seller_id)) || {};
        return {
          ...r,
          seller_name: u.seller_name,
          user_name: u.name,
          user_email: u.email,
          user_phone: u.phone,
          seller_account_status: u.account_status || 'ACTIVE'
        };
      })
      .filter(r => {
        if (statusFilter && r.status !== statusFilter) return false;
        if (stageFilter && r.stage !== stageFilter) return false;
        if (searchFilter) {
          const hay = [r.seller_name, r.user_name, r.user_email, r.user_phone, String(r.seller_id)].filter(Boolean).join(' ').toLowerCase();
          if (!hay.includes(searchFilter)) return false;
        }
        return true;
      })
      .sort((a, b) => Number(b.id) - Number(a.id));
  }

  const requests = rows.map(r => {
    const snapshot = safeJsonParse(r.snapshot_json, null);
    const finSummary = safeJsonParse(r.financial_summary_json, null);
    const safeEmail = safeSellerNode({ email: r.user_email, role: 'seller' }, ctx).email;
    return {
      id: Number(r.id),
      seller_id: Number(r.seller_id),
      seller_name: r.seller_name || r.user_name || `Seller #${r.seller_id}`,
      seller_email: safeEmail,
      seller_phone: r.user_phone || null,
      seller_account_status: r.seller_account_status || 'ACTIVE',
      stage: r.stage,
      status: r.status,
      badge_color: getStatusColorBadgeNode(r.status),
      request_reason: r.request_reason || null,
      admin_remarks: r.admin_remarks || null,
      metrics: snapshot ? snapshot.metrics : null,
      financial_summary: finSummary,
      approved_at: toIsoStringSafe(r.approved_at),
      deleted_at: toIsoStringSafe(r.deleted_at),
      created_at: toIsoStringSafe(r.created_at),
      updated_at: toIsoStringSafe(r.updated_at)
    };
  });

  return { requests, kpis };
}

async function deletionPreflightNode(pool, ctx, sellerId) {
  const computed = await computeSellerFinancialAndChecklistNode(pool, ctx, sellerId, null);
  if (computed.error) {
    return { error: computed.error, code: 404 };
  }
  return {
    statusCode: 200,
    data: {
      seller: computed.seller,
      metrics: computed.metrics,
      settlement: computed.settlement,
      checks: computed.checks,
      blockers: computed.blockers
    }
  };
}

async function deletionCreateRequestNode(pool, ctx, sellerId, adminId, reason = '', remarks = '', ipAddress = null) {
  const dbActive = await checkDbReachable(pool, ctx);

  if (dbActive) {
    await ensureSellerDeletionSchemaNode(pool);
    const conn = await pool.getConnection();
    try {
      await conn.beginTransaction();
      const [uRows] = await conn.query('SELECT * FROM users WHERE id = ? LIMIT 1 FOR UPDATE', [sellerId]);
      if (uRows.length === 0) {
        await conn.rollback();
        conn.release();
        return { error: 'Seller not found.', code: 404 };
      }
      const seller = uRows[0];
      if (seller.role === 'admin') {
        await conn.rollback();
        conn.release();
        return { error: 'Master admin account cannot be deleted.', code: 422 };
      }
      if ((seller.account_status || 'ACTIVE') === 'DELETED') {
        await conn.rollback();
        conn.release();
        return { error: 'Seller account has already been permanently deleted.', code: 409 };
      }

      const [exRows] = await conn.query(
        "SELECT id, status FROM seller_deletion_requests WHERE seller_id = ? AND status NOT IN ('DELETED', 'DELETION_REJECTED') ORDER BY id DESC LIMIT 1 FOR UPDATE",
        [sellerId]
      );
      if (exRows.length > 0) {
        await conn.rollback();
        conn.release();
        return {
          error: `An active deletion request (#${exRows[0].id}) already exists for this seller in status ${exRows[0].status}.`,
          code: 409,
          existing_request_id: Number(exRows[0].id)
        };
      }

      const prevAccStatus = seller.account_status || 'ACTIVE';
      await conn.query(
        `UPDATE users
         SET account_status = 'DELETION_PENDING',
             deletion_requested_at = COALESCE(deletion_requested_at, CURRENT_TIMESTAMP),
             updated_at = CURRENT_TIMESTAMP
         WHERE id = ?`,
        [sellerId]
      );
      await conn.commit();
      conn.release();

      const computed = await computeSellerFinancialAndChecklistNode(pool, ctx, sellerId, null, true);
      const snapshotPayload = {
        seller: computed.seller,
        metrics: computed.metrics,
        captured_at: new Date().toISOString()
      };

      const [insRes] = await pool.query(
        `INSERT INTO seller_deletion_requests
          (seller_id, requested_by, stage, status, request_reason, admin_remarks, snapshot_json, financial_summary_json, created_at, updated_at)
         VALUES (?, ?, 'DELETION_REQUESTED', 'DELETION_REQUESTED', ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)`,
        [
          sellerId,
          adminId,
          reason || 'Admin requested seller account deletion',
          remarks || null,
          JSON.stringify(snapshotPayload),
          JSON.stringify(computed.settlement)
        ]
      );
      const delReqId = insRes.insertId;

      await computeSellerFinancialAndChecklistNode(pool, ctx, sellerId, delReqId, true);
      await logSellerDeletionAuditNode(pool, ctx, {
        deletionRequestId: delReqId,
        sellerId,
        action: 'DELETION_REQUESTED',
        prevStage: null,
        newStage: 'DELETION_REQUESTED',
        prevStatus: prevAccStatus,
        newStatus: 'DELETION_REQUESTED',
        actorId: adminId,
        actorRole: 'admin',
        remarks: remarks || reason || 'Deletion request initiated',
        metadata: { metrics: computed.metrics },
        ipAddress
      }, true);

      await sendDeletionNotificationNode(pool, ctx, {
        userId: sellerId,
        phone: seller.phone || null,
        title: 'Account Deletion Requested',
        message: 'Your seller account has entered DELETION_PENDING status following an administrative deletion request. New orders are temporarily blocked during verification.',
        type: 'seller_deletion_requested',
        data: { deletion_request_id: delReqId, seller_id: sellerId }
      }, true);

      return { statusCode: 201, data: await buildDeletionWorkspacePayloadNode(pool, ctx, delReqId, true) };
    } catch (e) {
      try { await conn.rollback(); } catch (_) {}
      conn.release();
      return { error: `Failed to create deletion request: ${e.message}`, code: 500 };
    }
  }

  // Fallback mode
  const store = ctx.getFallbackDeletionStore();
  const seller = ctx.getFallbackSellers().find(s => Number(s.id) === Number(sellerId));
  if (!seller) return { error: 'Seller not found.', code: 404 };
  if (seller.role === 'admin') return { error: 'Master admin account cannot be deleted.', code: 422 };
  if ((seller.account_status || 'ACTIVE') === 'DELETED') {
    return { error: 'Seller account has already been permanently deleted.', code: 409 };
  }

  const existingReq = store.deletionRequests.find(r => Number(r.seller_id) === Number(sellerId) && !TERMINAL_DELETION_STATUSES.includes(r.status));
  if (existingReq) {
    return {
      error: `An active deletion request (#${existingReq.id}) already exists for this seller in status ${existingReq.status}.`,
      code: 409,
      existing_request_id: Number(existingReq.id)
    };
  }

  const prevAccStatus = seller.account_status || 'ACTIVE';
  const nowStr = nowSqlUtc();
  seller.account_status = 'DELETION_PENDING';
  seller.deletion_requested_at = seller.deletion_requested_at || nowStr;
  seller.updated_at = nowStr;

  const computed = await computeSellerFinancialAndChecklistNode(pool, ctx, sellerId, null, false);
  const snapshotPayload = {
    seller: computed.seller,
    metrics: computed.metrics,
    captured_at: new Date().toISOString()
  };

  const delReqId = store.nextIds.deletionRequest++;
  store.deletionRequests.push({
    id: delReqId,
    seller_id: Number(sellerId),
    requested_by: adminId,
    stage: 'DELETION_REQUESTED',
    status: 'DELETION_REQUESTED',
    request_reason: reason || 'Admin requested seller account deletion',
    admin_remarks: remarks || null,
    hold_reason: null,
    rejection_reason: null,
    confirmation_token: null,
    confirmation_expires_at: null,
    snapshot_json: JSON.stringify(snapshotPayload),
    financial_summary_json: JSON.stringify(computed.settlement),
    approved_by: null,
    approved_at: null,
    deleted_by: null,
    deleted_at: null,
    created_at: nowStr,
    updated_at: nowStr
  });

  await computeSellerFinancialAndChecklistNode(pool, ctx, sellerId, delReqId, false);
  await logSellerDeletionAuditNode(pool, ctx, {
    deletionRequestId: delReqId,
    sellerId,
    action: 'DELETION_REQUESTED',
    prevStage: null,
    newStage: 'DELETION_REQUESTED',
    prevStatus: prevAccStatus,
    newStatus: 'DELETION_REQUESTED',
    actorId: adminId,
    actorRole: 'admin',
    remarks: remarks || reason || 'Deletion request initiated',
    metadata: { metrics: computed.metrics },
    ipAddress
  }, false);

  await sendDeletionNotificationNode(pool, ctx, {
    userId: sellerId,
    phone: seller.phone || null,
    title: 'Account Deletion Requested',
    message: 'Your seller account has entered DELETION_PENDING status following an administrative deletion request. New orders are temporarily blocked during verification.',
    type: 'seller_deletion_requested',
    data: { deletion_request_id: delReqId, seller_id: sellerId }
  }, false);

  return { statusCode: 201, data: await buildDeletionWorkspacePayloadNode(pool, ctx, delReqId, false) };
}

async function loadRequestForMutation(pool, ctx, delReqId) {
  const dbActive = await checkDbReachable(pool, ctx);
  if (dbActive) {
    await ensureSellerDeletionSchemaNode(pool);
    const [rows] = await pool.query('SELECT * FROM seller_deletion_requests WHERE id = ? LIMIT 1', [delReqId]);
    return { dbActive: true, req: rows[0] || null };
  }
  const store = ctx.getFallbackDeletionStore();
  const req = store.deletionRequests.find(r => Number(r.id) === Number(delReqId)) || null;
  return { dbActive: false, req };
}

async function deletionLockAccountNode(pool, ctx, delReqId, adminId, remarks = '', ipAddress = null) {
  const { dbActive, req } = await loadRequestForMutation(pool, ctx, delReqId);
  if (!req) return { error: 'Deletion request not found.', code: 404 };
  if (TERMINAL_DELETION_STATUSES.includes(req.status)) {
    return { error: `Cannot lock account when deletion request is ${req.status}.`, code: 409 };
  }

  const sellerId = Number(req.seller_id);
  const prevStage = req.stage;
  const prevStatus = req.status;
  const newStage = 'ACCOUNT_LOCKED';
  const newStatus = req.status === 'DELETION_ON_HOLD' ? 'DELETION_ON_HOLD' : 'ACCOUNT_LOCKED';

  if (dbActive) {
    await pool.query("UPDATE users SET account_status = 'LOCKED', updated_at = CURRENT_TIMESTAMP WHERE id = ?", [sellerId]);
    await pool.query(
      'UPDATE seller_deletion_requests SET stage = ?, status = ?, admin_remarks = COALESCE(?, admin_remarks), updated_at = CURRENT_TIMESTAMP WHERE id = ?',
      [newStage, newStatus, remarks || null, delReqId]
    );
  } else {
    const seller = ctx.getFallbackSellers().find(s => Number(s.id) === sellerId);
    if (seller) {
      seller.account_status = 'LOCKED';
      seller.updated_at = nowSqlUtc();
    }
    req.stage = newStage;
    req.status = newStatus;
    if (remarks) req.admin_remarks = remarks;
    req.updated_at = nowSqlUtc();
  }

  await logSellerDeletionAuditNode(pool, ctx, {
    deletionRequestId: delReqId,
    sellerId,
    action: 'ACCOUNT_LOCKED',
    prevStage,
    newStage,
    prevStatus,
    newStatus,
    actorId: adminId,
    actorRole: 'admin',
    remarks: remarks || 'Seller account locked; new orders blocked',
    ipAddress
  }, dbActive);

  return { statusCode: 200, data: await buildDeletionWorkspacePayloadNode(pool, ctx, delReqId, dbActive) };
}

async function deletionVerifyOrdersNode(pool, ctx, delReqId, adminId, input = {}, ipAddress = null) {
  const { dbActive, req } = await loadRequestForMutation(pool, ctx, delReqId);
  if (!req) return { error: 'Deletion request not found.', code: 404 };
  if (TERMINAL_DELETION_STATUSES.includes(req.status)) {
    return { error: `Cannot verify orders when deletion request is ${req.status}.`, code: 409 };
  }

  const sellerId = Number(req.seller_id);
  const approveAllAccepted = Boolean(input.approve_accepted_cancellations);
  const approveOrderIds = Array.isArray(input.approve_cancellation_order_ids)
    ? input.approve_cancellation_order_ids.map(n => Number(n))
    : [];
  const remarks = String(input.remarks || input.admin_remarks || '').trim();

  const enrichedOrders = await getSellerOrdersDetailedNode(pool, ctx, sellerId, dbActive);
  let cancelledCount = 0;
  let acceptedCancelledCount = 0;
  const cancelledNotices = [];

  for (const eo of enrichedOrders) {
    const oid = Number(eo.id);
    const st = eo.effective_status;
    const shouldCancelA = st === 'pending';
    const shouldCancelB = ['accepted', 'processing', 'packed'].includes(st) && (approveAllAccepted || approveOrderIds.includes(oid));

    if (!shouldCancelA && !shouldCancelB) continue;

    const cancelReason = shouldCancelB
      ? 'Cancelled after admin cancellation approval during seller deletion verification'
      : 'Cancelled automatically due to seller account deletion (unaccepted order)';

    if (dbActive) {
      for (const si of eo.seller_items) {
        if (si.product_id && !['cancelled', 'rejected'].includes(String(si.item_status || 'pending').toLowerCase())) {
          await pool.query('UPDATE products SET stock = stock + ? WHERE id = ?', [Number(si.quantity || 0), Number(si.product_id)]);
        }
      }

      if (!eo.is_multi_vendor) {
        await pool.query(
          "UPDATE order_items SET item_status = 'cancelled', rejection_reason = ?, updated_at = CURRENT_TIMESTAMP WHERE order_id = ? AND (seller_id = ? OR seller_id IS NULL)",
          [cancelReason, oid, sellerId]
        );
      } else {
        await pool.query(
          "UPDATE order_items SET item_status = 'cancelled', rejection_reason = ?, updated_at = CURRENT_TIMESTAMP WHERE order_id = ? AND seller_id = ?",
          [cancelReason, oid, sellerId]
        );
      }

      const [remRows] = await pool.query(
        "SELECT COUNT(*) AS cnt FROM order_items WHERE order_id = ? AND COALESCE(item_status, 'pending') NOT IN ('cancelled', 'rejected')",
        [oid]
      );
      if (Number(remRows[0]?.cnt || 0) === 0) {
        await pool.query(
          "UPDATE orders SET order_status = 'cancelled', cancellation_reason = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?",
          [cancelReason, oid]
        );
      }
    } else {
      const fbProducts = ctx.getFallbackProducts();
      const ord = eo.order;
      const nowStr = nowSqlUtc();
      for (const it of ord.items || []) {
        const itSellerId = Number(it.seller_id || ord.seller_id || 0);
        const matches = !eo.is_multi_vendor ? (itSellerId === sellerId || !it.seller_id) : (itSellerId === sellerId);
        if (matches) {
          if (it.product_id && !['cancelled', 'rejected'].includes(String(it.item_status || 'pending').toLowerCase())) {
            const pObj = fbProducts.find(p => Number(p.id) === Number(it.product_id));
            if (pObj) pObj.stock = Number(pObj.stock || 0) + Number(it.quantity || 0);
          }
          it.item_status = 'cancelled';
          it.rejection_reason = cancelReason;
          it.updated_at = nowStr;
        }
      }
      const remActive = (ord.items || []).filter(it => !['cancelled', 'rejected'].includes(String(it.item_status || 'pending').toLowerCase())).length;
      if (remActive === 0) {
        ord.order_status = 'cancelled';
        ord.cancellation_reason = cancelReason;
        ord.updated_at = nowStr;
      }
    }

    cancelledCount++;
    if (shouldCancelB) acceptedCancelledCount++;
    cancelledNotices.push({
      order_id: oid,
      order_number: eo.order_number,
      buyer_phone: eo.buyer_phone,
      is_paid: eo.is_paid,
      reason: cancelReason
    });
  }

  const queuedRefunds = await queueEligibleRefundsForSellerNode(pool, ctx, delReqId, sellerId, adminId, dbActive);
  const computed = await computeSellerFinancialAndChecklistNode(pool, ctx, sellerId, delReqId, dbActive);
  const next = req.status === 'DELETION_ON_HOLD'
    ? ['ORDER_VERIFICATION', 'DELETION_ON_HOLD']
    : deriveNextStageAndStatusNode(computed, 'ORDER_VERIFICATION', 'ORDER_VERIFICATION');

  const prevStage = req.stage;
  const prevStatus = req.status;

  if (dbActive) {
    await pool.query(
      'UPDATE seller_deletion_requests SET stage = ?, status = ?, admin_remarks = COALESCE(?, admin_remarks), updated_at = CURRENT_TIMESTAMP WHERE id = ?',
      [next[0], next[1], remarks || null, delReqId]
    );
  } else {
    req.stage = next[0];
    req.status = next[1];
    if (remarks) req.admin_remarks = remarks;
    req.updated_at = nowSqlUtc();
  }

  await logSellerDeletionAuditNode(pool, ctx, {
    deletionRequestId: delReqId,
    sellerId,
    action: 'ORDERS_VERIFIED',
    prevStage,
    newStage: next[0],
    prevStatus,
    newStatus: next[1],
    actorId: adminId,
    actorRole: 'admin',
    remarks: remarks || `Verified orders: ${cancelledCount} cancelled (${acceptedCancelledCount} Stage B approved), ${queuedRefunds.length} refund(s) queued`,
    metadata: { cancelled_count: cancelledCount, accepted_cancelled_count: acceptedCancelledCount, queued_refund_ids: queuedRefunds },
    ipAddress
  }, dbActive);

  for (const notice of cancelledNotices) {
    await sendDeletionNotificationNode(pool, ctx, {
      phone: notice.buyer_phone || null,
      title: 'Order Cancelled',
      message: `Your order ${notice.order_number} has been cancelled because the seller account is undergoing deletion.${notice.is_paid ? ' An automatic refund has been queued.' : ''}`,
      type: 'order_cancelled',
      data: { order_id: notice.order_id, order_number: notice.order_number }
    }, dbActive);
  }

  return { statusCode: 200, data: await buildDeletionWorkspacePayloadNode(pool, ctx, delReqId, dbActive) };
}

async function deletionProcessRefundsNode(pool, ctx, delReqId, adminId, remarks = '', ipAddress = null) {
  const { dbActive, req } = await loadRequestForMutation(pool, ctx, delReqId);
  if (!req) return { error: 'Deletion request not found.', code: 404 };
  if (TERMINAL_DELETION_STATUSES.includes(req.status)) {
    return { error: `Cannot process refunds when deletion request is ${req.status}.`, code: 409 };
  }

  const sellerId = Number(req.seller_id);
  await queueEligibleRefundsForSellerNode(pool, ctx, delReqId, sellerId, adminId, dbActive);

  let refundRows;
  if (dbActive) {
    const [rRows] = await pool.query('SELECT * FROM seller_refund_records WHERE seller_id = ? ORDER BY id ASC', [sellerId]);
    refundRows = rRows;
  } else {
    refundRows = ctx.getFallbackDeletionStore().refundRecords
      .filter(r => Number(r.seller_id) === sellerId)
      .sort((a, b) => Number(a.id) - Number(b.id));
  }

  const results = [];
  for (const rf of refundRows) {
    if (rf.status === 'REFUND_SUCCESS' || rf.status === 'REFUND_REQUIRES_REVIEW') {
      results.push({ refund_id: Number(rf.id), order_id: Number(rf.order_id), status: rf.status, skipped: true });
      continue;
    }
    const execRes = await executeSingleRefundRecordNode(pool, ctx, Number(rf.id), adminId, dbActive);
    results.push({ refund_id: Number(rf.id), order_id: Number(rf.order_id), ...execRes });
  }

  const computed = await computeSellerFinancialAndChecklistNode(pool, ctx, sellerId, delReqId, dbActive);
  const next = req.status === 'DELETION_ON_HOLD'
    ? ['REFUND_PROCESSING', 'DELETION_ON_HOLD']
    : deriveNextStageAndStatusNode(computed, 'REFUND_PROCESSING', 'REFUND_PROCESSING');

  const prevStage = req.stage;
  const prevStatus = req.status;

  if (dbActive) {
    await pool.query(
      'UPDATE seller_deletion_requests SET stage = ?, status = ?, admin_remarks = COALESCE(?, admin_remarks), updated_at = CURRENT_TIMESTAMP WHERE id = ?',
      [next[0], next[1], remarks || null, delReqId]
    );
  } else {
    req.stage = next[0];
    req.status = next[1];
    if (remarks) req.admin_remarks = remarks;
    req.updated_at = nowSqlUtc();
  }

  await logSellerDeletionAuditNode(pool, ctx, {
    deletionRequestId: delReqId,
    sellerId,
    action: 'REFUNDS_PROCESSED',
    prevStage,
    newStage: next[0],
    prevStatus,
    newStatus: next[1],
    actorId: adminId,
    actorRole: 'admin',
    remarks: remarks || 'Executed gateway refund batch',
    metadata: { results },
    ipAddress
  }, dbActive);

  const payload = await buildDeletionWorkspacePayloadNode(pool, ctx, delReqId, dbActive);
  payload.refund_execution_results = results;
  return { statusCode: 200, data: payload };
}

async function deletionVerifySettlementNode(pool, ctx, delReqId, adminId, remarks = '', ipAddress = null) {
  const { dbActive, req } = await loadRequestForMutation(pool, ctx, delReqId);
  if (!req) return { error: 'Deletion request not found.', code: 404 };
  if (TERMINAL_DELETION_STATUSES.includes(req.status)) {
    return { error: `Cannot verify settlement when deletion request is ${req.status}.`, code: 409 };
  }

  const sellerId = Number(req.seller_id);
  const computed = await computeSellerFinancialAndChecklistNode(pool, ctx, sellerId, delReqId, dbActive);
  const next = req.status === 'DELETION_ON_HOLD'
    ? ['FINANCIAL_SETTLEMENT', 'DELETION_ON_HOLD']
    : deriveNextStageAndStatusNode(computed, 'FINANCIAL_SETTLEMENT', req.status);

  const prevStage = req.stage;
  const prevStatus = req.status;

  if (dbActive) {
    await pool.query(
      'UPDATE seller_deletion_requests SET stage = ?, status = ?, admin_remarks = COALESCE(?, admin_remarks), financial_summary_json = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?',
      [next[0], next[1], remarks || null, JSON.stringify(computed.settlement), delReqId]
    );
  } else {
    req.stage = next[0];
    req.status = next[1];
    if (remarks) req.admin_remarks = remarks;
    req.financial_summary_json = JSON.stringify(computed.settlement);
    req.updated_at = nowSqlUtc();
  }

  await logSellerDeletionAuditNode(pool, ctx, {
    deletionRequestId: delReqId,
    sellerId,
    action: 'SETTLEMENT_VERIFIED',
    prevStage,
    newStage: next[0],
    prevStatus,
    newStatus: next[1],
    actorId: adminId,
    actorRole: 'admin',
    remarks: remarks || (next[1] === 'ADMIN_REVIEW' ? 'All financial settlements cleared' : 'Settlement verification found remaining blockers'),
    metadata: { settlement: computed.settlement },
    ipAddress
  }, dbActive);

  return { statusCode: 200, data: await buildDeletionWorkspacePayloadNode(pool, ctx, delReqId, dbActive) };
}

async function deletionSettlePayoutNode(pool, ctx, delReqId, adminId, input = {}, ipAddress = null) {
  const { dbActive, req } = await loadRequestForMutation(pool, ctx, delReqId);
  if (!req) return { error: 'Deletion request not found.', code: 404 };
  if (TERMINAL_DELETION_STATUSES.includes(req.status)) {
    return { error: `Cannot settle payouts when deletion request is ${req.status}.`, code: 409 };
  }

  const sellerId = Number(req.seller_id);
  const computed = await computeSellerFinancialAndChecklistNode(pool, ctx, sellerId, delReqId, dbActive);
  const checksByKey = {};
  for (const ck of computed.checks) checksByKey[ck.check_key] = ck.status;

  if ((checksByKey.orders_resolved || 'PENDING') !== 'SUCCESS') {
    return { error: 'Cannot release seller payout before all eligible orders are resolved.', code: 409 };
  }
  if ((checksByKey.refunds_confirmed || 'PENDING') !== 'SUCCESS') {
    return { error: 'Cannot release seller payout while refunds are pending or failed.', code: 409 };
  }
  if ((checksByKey.disputes_resolved || 'PENDING') !== 'SUCCESS') {
    return { error: 'Cannot release seller payout while active customer disputes remain open.', code: 409 };
  }
  if ((computed.settlement.unsettled_gateway_count || 0) > 0 && !input.confirm_gateway_settled) {
    return { error: 'Cannot release seller payout while payment gateway transactions remain unsettled.', code: 409 };
  }

  const payoutRef = String(input.payout_reference || `PAYOUT-DEL-${delReqId}-${Math.floor(Date.now() / 1000)}`).trim();
  const notes = String(input.notes || input.remarks || 'Formal payout settlement verified during seller deletion').trim();
  const payoutId = input.payout_id ? Number(input.payout_id) : null;
  const prevStage = req.stage;
  const prevStatus = req.status;

  if (dbActive) {
    const conn = await pool.getConnection();
    try {
      await conn.beginTransaction();
      if (input.confirm_gateway_settled) {
        await conn.query(
          `UPDATE payment_transactions
           SET status = 'settled', settled_at = COALESCE(settled_at, CURRENT_TIMESTAMP), updated_at = CURRENT_TIMESTAMP
           WHERE order_id IN (SELECT id FROM orders WHERE seller_id = ? UNION SELECT DISTINCT order_id FROM order_items WHERE seller_id = ?)
             AND transaction_type = 'capture' AND status = 'captured'`,
          [sellerId, sellerId]
        );
      }

      if (payoutId) {
        await conn.query(
          `UPDATE seller_payouts
           SET status = 'SETTLED',
               deletion_request_id = COALESCE(deletion_request_id, ?),
               payout_reference = ?,
               settled_by = ?,
               settled_at = CURRENT_TIMESTAMP,
               notes = ?,
               updated_at = CURRENT_TIMESTAMP
           WHERE id = ? AND seller_id = ?`,
          [delReqId, payoutRef, adminId, notes, payoutId, sellerId]
        );
      } else {
        await conn.query(
          `UPDATE seller_payouts
           SET status = 'SETTLED',
               deletion_request_id = COALESCE(deletion_request_id, ?),
               payout_reference = COALESCE(payout_reference, ?),
               settled_by = ?,
               settled_at = CURRENT_TIMESTAMP,
               notes = COALESCE(?, notes),
               updated_at = CURRENT_TIMESTAMP
           WHERE seller_id = ? AND status IN ('PENDING', 'PROCESSING', 'HELD', 'FAILED')`,
          [delReqId, payoutRef, adminId, notes, sellerId]
        );

        await conn.commit();
        conn.release();

        // Recompute balance AFTER marking existing pending payouts SETTLED to prevent double payout
        const afterExisting = await computeSellerFinancialAndChecklistNode(pool, ctx, sellerId, null, true);
        const remainingBal = Number(afterExisting.settlement.available_seller_balance || 0);
        const outComm = Number(afterExisting.settlement.outstanding_commissions || 0);
        if (remainingBal > 0.01 || outComm > 0.01) {
          await pool.query(
            `INSERT INTO seller_payouts
              (seller_id, deletion_request_id, amount, commission_deducted, refund_recovery_deducted, net_amount, status, payout_reference, settled_by, settled_at, notes, created_at, updated_at)
             VALUES (?, ?, ?, ?, 0.00, ?, 'SETTLED', ?, ?, CURRENT_TIMESTAMP, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)`,
            [sellerId, delReqId, round2(remainingBal + outComm), outComm, remainingBal, payoutRef, adminId, notes]
          );
        }

        await pool.query(
          `UPDATE orders
           SET settled_at = COALESCE(settled_at, CURRENT_TIMESTAMP), updated_at = CURRENT_TIMESTAMP
           WHERE order_status = 'delivered'
             AND (seller_id = ? OR id IN (SELECT DISTINCT order_id FROM order_items WHERE seller_id = ?))`,
          [sellerId, sellerId]
        );

        const recomputed = await computeSellerFinancialAndChecklistNode(pool, ctx, sellerId, delReqId, true);
        const next = deriveNextStageAndStatusNode(recomputed, 'FINANCIAL_SETTLEMENT', 'FINANCIAL_SETTLEMENT');
        await pool.query(
          'UPDATE seller_deletion_requests SET stage = ?, status = ?, financial_summary_json = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?',
          [next[0], next[1], JSON.stringify(recomputed.settlement), delReqId]
        );

        await logSellerDeletionAuditNode(pool, ctx, {
          deletionRequestId: delReqId,
          sellerId,
          action: 'PAYOUT_SETTLED',
          prevStage,
          newStage: next[0],
          prevStatus,
          newStatus: next[1],
          actorId: adminId,
          actorRole: 'admin',
          remarks: `${notes} (Ref: ${payoutRef})`,
          ipAddress
        }, true);

        return { statusCode: 200, data: await buildDeletionWorkspacePayloadNode(pool, ctx, delReqId, true) };
      }

      await conn.query(
        `UPDATE orders
         SET settled_at = COALESCE(settled_at, CURRENT_TIMESTAMP), updated_at = CURRENT_TIMESTAMP
         WHERE order_status = 'delivered'
           AND (seller_id = ? OR id IN (SELECT DISTINCT order_id FROM order_items WHERE seller_id = ?))`,
        [sellerId, sellerId]
      );
      await conn.commit();
      conn.release();
    } catch (e) {
      try { await conn.rollback(); } catch (_) {}
      conn.release();
      return { error: `Failed to settle payout: ${e.message}`, code: 500 };
    }

    const recomputed = await computeSellerFinancialAndChecklistNode(pool, ctx, sellerId, delReqId, true);
    const next = deriveNextStageAndStatusNode(recomputed, 'FINANCIAL_SETTLEMENT', 'FINANCIAL_SETTLEMENT');
    await pool.query(
      'UPDATE seller_deletion_requests SET stage = ?, status = ?, financial_summary_json = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?',
      [next[0], next[1], JSON.stringify(recomputed.settlement), delReqId]
    );

    await logSellerDeletionAuditNode(pool, ctx, {
      deletionRequestId: delReqId,
      sellerId,
      action: 'PAYOUT_SETTLED',
      prevStage,
      newStage: next[0],
      prevStatus,
      newStatus: next[1],
      actorId: adminId,
      actorRole: 'admin',
      remarks: `${notes} (Ref: ${payoutRef})`,
      ipAddress
    }, true);

    return { statusCode: 200, data: await buildDeletionWorkspacePayloadNode(pool, ctx, delReqId, true) };
  }

  // Fallback mode
  const store = ctx.getFallbackDeletionStore();
  const fbOrders = ctx.getFallbackOrders();
  const nowStr = nowSqlUtc();

  if (input.confirm_gateway_settled) {
    const sellerOrderIds = new Set(
      fbOrders
        .filter(o => Number(o.seller_id) === sellerId || (o.items || []).some(it => Number(it.seller_id || o.seller_id) === sellerId))
        .map(o => Number(o.id))
    );
    for (const pt of store.paymentTransactions) {
      if (sellerOrderIds.has(Number(pt.order_id)) && pt.transaction_type === 'capture' && pt.status === 'captured') {
        pt.status = 'settled';
        pt.settled_at = pt.settled_at || nowStr;
        pt.updated_at = nowStr;
      }
    }
  }

  if (payoutId) {
    const targetPayout = store.payouts.find(p => Number(p.id) === payoutId && Number(p.seller_id) === sellerId);
    if (targetPayout) {
      targetPayout.status = 'SETTLED';
      targetPayout.deletion_request_id = targetPayout.deletion_request_id || Number(delReqId);
      targetPayout.payout_reference = payoutRef;
      targetPayout.settled_by = adminId;
      targetPayout.settled_at = nowStr;
      targetPayout.notes = notes;
      targetPayout.updated_at = nowStr;
    }
  } else {
    for (const p of store.payouts) {
      if (Number(p.seller_id) === sellerId && ['PENDING', 'PROCESSING', 'HELD', 'FAILED'].includes(String(p.status).toUpperCase())) {
        p.status = 'SETTLED';
        p.deletion_request_id = p.deletion_request_id || Number(delReqId);
        p.payout_reference = p.payout_reference || payoutRef;
        p.settled_by = adminId;
        p.settled_at = nowStr;
        p.notes = notes || p.notes;
        p.updated_at = nowStr;
      }
    }

    const afterExisting = await computeSellerFinancialAndChecklistNode(pool, ctx, sellerId, null, false);
    const remainingBal = Number(afterExisting.settlement.available_seller_balance || 0);
    const outComm = Number(afterExisting.settlement.outstanding_commissions || 0);
    if (remainingBal > 0.01 || outComm > 0.01) {
      store.payouts.push({
        id: store.nextIds.payout++,
        seller_id: sellerId,
        deletion_request_id: Number(delReqId),
        amount: round2(remainingBal + outComm),
        commission_deducted: outComm,
        refund_recovery_deducted: 0,
        net_amount: remainingBal,
        status: 'SETTLED',
        payout_reference: payoutRef,
        gateway_payout_id: null,
        settled_by: adminId,
        settled_at: nowStr,
        notes,
        created_at: nowStr,
        updated_at: nowStr
      });
    }
  }

  for (const o of fbOrders) {
    if (String(o.order_status).toLowerCase() === 'delivered' &&
      (Number(o.seller_id) === sellerId || (o.items || []).some(it => Number(it.seller_id || o.seller_id) === sellerId))) {
      o.settled_at = o.settled_at || nowStr;
      o.updated_at = nowStr;
    }
  }

  const recomputed = await computeSellerFinancialAndChecklistNode(pool, ctx, sellerId, delReqId, false);
  const next = deriveNextStageAndStatusNode(recomputed, 'FINANCIAL_SETTLEMENT', 'FINANCIAL_SETTLEMENT');
  req.stage = next[0];
  req.status = next[1];
  req.financial_summary_json = JSON.stringify(recomputed.settlement);
  req.updated_at = nowStr;

  await logSellerDeletionAuditNode(pool, ctx, {
    deletionRequestId: delReqId,
    sellerId,
    action: 'PAYOUT_SETTLED',
    prevStage,
    newStage: next[0],
    prevStatus,
    newStatus: next[1],
    actorId: adminId,
    actorRole: 'admin',
    remarks: `${notes} (Ref: ${payoutRef})`,
    ipAddress
  }, false);

  return { statusCode: 200, data: await buildDeletionWorkspacePayloadNode(pool, ctx, delReqId, false) };
}

async function deletionResolveDisputeNode(pool, ctx, delReqId, adminId, input = {}, ipAddress = null) {
  const { dbActive, req } = await loadRequestForMutation(pool, ctx, delReqId);
  if (!req) return { error: 'Deletion request not found.', code: 404 };
  if (TERMINAL_DELETION_STATUSES.includes(req.status)) {
    return { error: `Cannot resolve disputes when deletion request is ${req.status}.`, code: 409 };
  }

  const sellerId = Number(req.seller_id);
  const disputeId = input.dispute_id ? Number(input.dispute_id) : 0;
  let resolutionStatus = String(input.resolution || input.status || 'RESOLVED').trim().toUpperCase();
  if (!['RESOLVED', 'CLOSED'].includes(resolutionStatus)) resolutionStatus = 'RESOLVED';

  const notes = String(input.resolution_notes || input.remarks || 'Dispute formally resolved by administrator').trim();
  const newOrderStatus = String(input.new_order_status || '').trim().toLowerCase();
  const markDelivered = Boolean(input.mark_delivered) || newOrderStatus === 'delivered';
  const triggerRefund = Boolean(input.issue_refund || input.mark_returned) || ['cancelled', 'returned'].includes(newOrderStatus);
  const closeReturnWindow = Boolean(input.close_return_window || input.return_window_completed);
  const cfg = getSellerDeletionConfigNode();

  let targetDisputes = [];
  const processedOrderIds = [];
  const cancelNotices = [];

  if (dbActive) {
    if (disputeId > 0) {
      const [dRows] = await pool.query('SELECT * FROM order_disputes WHERE id = ? AND seller_id = ? LIMIT 1', [disputeId, sellerId]);
      if (dRows.length === 0) return { error: 'Dispute not found for this seller.', code: 404 };
      targetDisputes = dRows;
      await pool.query(
        'UPDATE order_disputes SET status = ?, resolution_notes = ?, resolved_by = ?, resolved_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ?',
        [resolutionStatus, notes, adminId, disputeId]
      );
    } else {
      const [dRows] = await pool.query(
        "SELECT * FROM order_disputes WHERE seller_id = ? AND status IN ('OPEN', 'INVESTIGATING', 'ESCALATED')",
        [sellerId]
      );
      targetDisputes = dRows;
      await pool.query(
        "UPDATE order_disputes SET status = ?, resolution_notes = ?, resolved_by = ?, resolved_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE seller_id = ? AND status IN ('OPEN', 'INVESTIGATING', 'ESCALATED')",
        [resolutionStatus, notes, adminId, sellerId]
      );
    }

    for (const disp of targetDisputes) {
      if (disp.order_id && !processedOrderIds.includes(Number(disp.order_id))) {
        processedOrderIds.push(Number(disp.order_id));
      }
    }
    if (input.order_id && !processedOrderIds.includes(Number(input.order_id))) {
      processedOrderIds.push(Number(input.order_id));
    }

    for (const oid of processedOrderIds) {
      const [oRows] = await pool.query('SELECT * FROM orders WHERE id = ? LIMIT 1', [oid]);
      if (oRows.length === 0) continue;
      const ord = oRows[0];

      const [ownRows] = await pool.query('SELECT COUNT(*) AS cnt FROM order_items WHERE order_id = ? AND seller_id = ?', [oid, sellerId]);
      if (Number(ownRows[0]?.cnt || 0) === 0 && Number(ord.seller_id || 0) !== sellerId) continue;

      const curStatus = String(ord.order_status || 'pending').toLowerCase();
      if (markDelivered && curStatus === 'shipped') {
        const winEndSql = closeReturnWindow
          ? new Date(Date.now() - 60000).toISOString().slice(0, 19).replace('T', ' ')
          : addDaysSqlUtc(cfg.return_window_days);
        await pool.query(
          `UPDATE orders SET order_status = 'delivered', delivered_at = COALESCE(delivered_at, CURRENT_TIMESTAMP), return_window_ends_at = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?`,
          [winEndSql, oid]
        );
        await pool.query(
          "UPDATE order_items SET item_status = 'delivered', updated_at = CURRENT_TIMESTAMP WHERE order_id = ? AND (seller_id = ? OR seller_id IS NULL)",
          [oid, sellerId]
        );
      } else if (closeReturnWindow && curStatus === 'delivered') {
        const winEndSql = new Date(Date.now() - 60000).toISOString().slice(0, 19).replace('T', ' ');
        await pool.query(
          'UPDATE orders SET return_window_ends_at = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?',
          [winEndSql, oid]
        );
      } else if (triggerRefund && !['cancelled', 'rejected'].includes(curStatus)) {
        const [siRows] = await pool.query(
          'SELECT product_id, quantity, item_status FROM order_items WHERE order_id = ? AND (seller_id = ? OR seller_id IS NULL)',
          [oid, sellerId]
        );
        for (const si of siRows) {
          if (si.product_id && !['cancelled', 'rejected'].includes(String(si.item_status || 'pending').toLowerCase())) {
            await pool.query('UPDATE products SET stock = stock + ? WHERE id = ?', [Number(si.quantity || 0), Number(si.product_id)]);
          }
        }
        const cancelReason = `Cancelled via dispute/return resolution: ${notes}`;
        await pool.query(
          "UPDATE order_items SET item_status = 'cancelled', rejection_reason = ?, updated_at = CURRENT_TIMESTAMP WHERE order_id = ? AND (seller_id = ? OR seller_id IS NULL)",
          [notes, oid, sellerId]
        );
        const [remRows] = await pool.query(
          "SELECT COUNT(*) AS cnt FROM order_items WHERE order_id = ? AND COALESCE(item_status, 'pending') NOT IN ('cancelled', 'rejected')",
          [oid]
        );
        if (Number(remRows[0]?.cnt || 0) === 0) {
          await pool.query("UPDATE orders SET order_status = 'cancelled', cancellation_reason = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?", [cancelReason, oid]);
        }
        cancelNotices.push({ order_id: oid, order_number: ord.order_number || `ORD-${oid}`, buyer_phone: ord.buyer_phone || null });
      }
    }
  } else {
    const store = ctx.getFallbackDeletionStore();
    const fbOrders = ctx.getFallbackOrders();
    const fbProducts = ctx.getFallbackProducts();
    const nowStr = nowSqlUtc();

    if (disputeId > 0) {
      const d = store.disputes.find(x => Number(x.id) === disputeId && Number(x.seller_id) === sellerId);
      if (!d) return { error: 'Dispute not found for this seller.', code: 404 };
      d.status = resolutionStatus;
      d.resolution_notes = notes;
      d.resolved_by = adminId;
      d.resolved_at = nowStr;
      d.updated_at = nowStr;
      targetDisputes.push(d);
    } else {
      targetDisputes = store.disputes.filter(x => Number(x.seller_id) === sellerId && ACTIVE_DISPUTE_STATUSES.includes(String(x.status).toUpperCase()));
      for (const d of targetDisputes) {
        d.status = resolutionStatus;
        d.resolution_notes = notes;
        d.resolved_by = adminId;
        d.resolved_at = nowStr;
        d.updated_at = nowStr;
      }
    }

    for (const disp of targetDisputes) {
      if (disp.order_id && !processedOrderIds.includes(Number(disp.order_id))) {
        processedOrderIds.push(Number(disp.order_id));
      }
    }
    if (input.order_id && !processedOrderIds.includes(Number(input.order_id))) {
      processedOrderIds.push(Number(input.order_id));
    }

    for (const oid of processedOrderIds) {
      const ord = fbOrders.find(o => Number(o.id) === oid);
      if (!ord) continue;
      const ownsOrder = Number(ord.seller_id || 0) === sellerId || (ord.items || []).some(it => Number(it.seller_id || ord.seller_id) === sellerId);
      if (!ownsOrder) continue;

      const curStatus = String(ord.order_status || 'pending').toLowerCase();
      if (markDelivered && curStatus === 'shipped') {
        ord.order_status = 'delivered';
        ord.delivered_at = ord.delivered_at || nowStr;
        ord.return_window_ends_at = closeReturnWindow
          ? new Date(Date.now() - 60000).toISOString().slice(0, 19).replace('T', ' ')
          : addDaysSqlUtc(cfg.return_window_days);
        ord.updated_at = nowStr;
        for (const it of ord.items || []) {
          if (Number(it.seller_id || ord.seller_id) === sellerId) {
            it.item_status = 'delivered';
          }
        }
      } else if (closeReturnWindow && curStatus === 'delivered') {
        ord.return_window_ends_at = new Date(Date.now() - 60000).toISOString().slice(0, 19).replace('T', ' ');
        ord.updated_at = nowStr;
      } else if (triggerRefund && !['cancelled', 'rejected'].includes(curStatus)) {
        for (const it of ord.items || []) {
          if (Number(it.seller_id || ord.seller_id) === sellerId) {
            if (it.product_id && !['cancelled', 'rejected'].includes(String(it.item_status || 'pending').toLowerCase())) {
              const pObj = fbProducts.find(p => Number(p.id) === Number(it.product_id));
              if (pObj) pObj.stock = Number(pObj.stock || 0) + Number(it.quantity || 0);
            }
            it.item_status = 'cancelled';
            it.rejection_reason = notes;
          }
        }
        const remActive = (ord.items || []).filter(it => !['cancelled', 'rejected'].includes(String(it.item_status || 'pending').toLowerCase())).length;
        if (remActive === 0) {
          ord.order_status = 'cancelled';
          ord.cancellation_reason = `Cancelled via dispute/return resolution: ${notes}`;
        }
        ord.updated_at = nowStr;
        cancelNotices.push({ order_id: oid, order_number: ord.order_number || `ORD-${oid}`, buyer_phone: ord.buyer_phone || null });
      }
    }
  }

  if (triggerRefund) {
    await queueEligibleRefundsForSellerNode(pool, ctx, delReqId, sellerId, adminId, dbActive);
  }

  const recomputed = await computeSellerFinancialAndChecklistNode(pool, ctx, sellerId, delReqId, dbActive);
  const next = req.status === 'DELETION_ON_HOLD'
    ? [req.stage, 'DELETION_ON_HOLD']
    : deriveNextStageAndStatusNode(recomputed, req.stage, req.status);

  const prevStage = req.stage;
  const prevStatus = req.status;

  if (dbActive) {
    await pool.query('UPDATE seller_deletion_requests SET stage = ?, status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?', [next[0], next[1], delReqId]);
  } else {
    req.stage = next[0];
    req.status = next[1];
    req.updated_at = nowSqlUtc();
  }

  await logSellerDeletionAuditNode(pool, ctx, {
    deletionRequestId: delReqId,
    sellerId,
    action: 'DISPUTE_RESOLVED',
    prevStage,
    newStage: next[0],
    prevStatus,
    newStatus: next[1],
    actorId: adminId,
    actorRole: 'admin',
    remarks: notes,
    metadata: { dispute_id: disputeId || 'ALL' },
    ipAddress
  }, dbActive);

  for (const cn of cancelNotices) {
    await sendDeletionNotificationNode(pool, ctx, {
      phone: cn.buyer_phone || null,
      title: 'Order Cancelled',
      message: `Order ${cn.order_number} was cancelled following dispute resolution. Eligible refund has been queued.`,
      type: 'order_cancelled',
      data: { order_id: cn.order_id, order_number: cn.order_number }
    }, dbActive);
  }

  return { statusCode: 200, data: await buildDeletionWorkspacePayloadNode(pool, ctx, delReqId, dbActive) };
}

async function deletionHoldNode(pool, ctx, delReqId, adminId, reason, remarks = '', ipAddress = null) {
  const { dbActive, req } = await loadRequestForMutation(pool, ctx, delReqId);
  if (!req) return { error: 'Deletion request not found.', code: 404 };
  if (TERMINAL_DELETION_STATUSES.includes(req.status)) {
    return { error: `Cannot put on hold when deletion request is ${req.status}.`, code: 409 };
  }

  const sellerId = Number(req.seller_id);
  const holdMsg = String(reason || '').trim() || String(remarks || '').trim() || 'Placed on administrative hold';
  const prevStatus = req.status;

  if (dbActive) {
    await pool.query(
      "UPDATE seller_deletion_requests SET status = 'DELETION_ON_HOLD', hold_reason = ?, admin_remarks = COALESCE(?, admin_remarks), updated_at = CURRENT_TIMESTAMP WHERE id = ?",
      [holdMsg, remarks || null, delReqId]
    );
  } else {
    req.status = 'DELETION_ON_HOLD';
    req.hold_reason = holdMsg;
    if (remarks) req.admin_remarks = remarks;
    req.updated_at = nowSqlUtc();
  }

  await logSellerDeletionAuditNode(pool, ctx, {
    deletionRequestId: delReqId,
    sellerId,
    action: 'DELETION_PUT_ON_HOLD',
    prevStage: req.stage,
    newStage: req.stage,
    prevStatus,
    newStatus: 'DELETION_ON_HOLD',
    actorId: adminId,
    actorRole: 'admin',
    remarks: holdMsg,
    ipAddress
  }, dbActive);

  return { statusCode: 200, data: await buildDeletionWorkspacePayloadNode(pool, ctx, delReqId, dbActive) };
}

async function deletionRejectNode(pool, ctx, delReqId, adminId, reason, remarks = '', ipAddress = null) {
  const cleanReason = String(reason || '').trim();
  if (!cleanReason) return { error: 'A rejection reason is required.', code: 422 };

  const { dbActive, req } = await loadRequestForMutation(pool, ctx, delReqId);
  if (!req) return { error: 'Deletion request not found.', code: 404 };
  if (req.status === 'DELETED') {
    return { error: 'Seller has already been permanently deleted.', code: 409 };
  }

  const sellerId = Number(req.seller_id);
  const prevStage = req.stage;
  const prevStatus = req.status;
  let sellerPhone = null;

  if (dbActive) {
    await pool.query(
      `UPDATE seller_deletion_requests
       SET stage = 'DELETION_REJECTED',
           status = 'DELETION_REJECTED',
           rejection_reason = ?,
           admin_remarks = COALESCE(?, admin_remarks),
           confirmation_token = NULL,
           confirmation_expires_at = NULL,
           updated_at = CURRENT_TIMESTAMP
       WHERE id = ?`,
      [cleanReason, remarks || null, delReqId]
    );
    await pool.query(
      "UPDATE users SET account_status = 'ACTIVE', deletion_requested_at = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND account_status != 'DELETED'",
      [sellerId]
    );
    const [uRows] = await pool.query('SELECT phone FROM users WHERE id = ? LIMIT 1', [sellerId]);
    sellerPhone = uRows[0]?.phone || null;
  } else {
    const nowStr = nowSqlUtc();
    req.stage = 'DELETION_REJECTED';
    req.status = 'DELETION_REJECTED';
    req.rejection_reason = cleanReason;
    if (remarks) req.admin_remarks = remarks;
    req.confirmation_token = null;
    req.confirmation_expires_at = null;
    req.updated_at = nowStr;

    const seller = ctx.getFallbackSellers().find(s => Number(s.id) === sellerId);
    if (seller && seller.account_status !== 'DELETED') {
      seller.account_status = 'ACTIVE';
      seller.deletion_requested_at = null;
      seller.updated_at = nowStr;
      sellerPhone = seller.phone || null;
    }
  }

  await logSellerDeletionAuditNode(pool, ctx, {
    deletionRequestId: delReqId,
    sellerId,
    action: 'DELETION_REJECTED',
    prevStage,
    newStage: 'DELETION_REJECTED',
    prevStatus,
    newStatus: 'DELETION_REJECTED',
    actorId: adminId,
    actorRole: 'admin',
    remarks: cleanReason,
    ipAddress
  }, dbActive);

  await sendDeletionNotificationNode(pool, ctx, {
    userId: sellerId,
    phone: sellerPhone,
    title: 'Account Deletion Rejected',
    message: `Your seller deletion request (#${delReqId}) was rejected by Admin: ${cleanReason}. Your account has been restored to ACTIVE status.`,
    type: 'seller_deletion_rejected',
    data: { deletion_request_id: Number(delReqId), reason: cleanReason }
  }, dbActive);

  return { statusCode: 200, data: await buildDeletionWorkspacePayloadNode(pool, ctx, delReqId, dbActive) };
}

async function deletionApproveNode(pool, ctx, delReqId, adminId, remarks = '', ipAddress = null) {
  const { dbActive, req } = await loadRequestForMutation(pool, ctx, delReqId);
  if (!req) return { error: 'Deletion request not found.', code: 404 };
  if (TERMINAL_DELETION_STATUSES.includes(req.status)) {
    return { error: `Cannot approve request in status ${req.status}.`, code: 409 };
  }

  const sellerId = Number(req.seller_id);
  const computed = await computeSellerFinancialAndChecklistNode(pool, ctx, sellerId, delReqId, dbActive);
  if (!computed.all_checks_passed) {
    const failing = computed.checks.filter(c => c.status !== 'SUCCESS').map(c => `${c.check_key} (${c.status})`);
    return {
      error: `Cannot approve seller for deletion while checklist requirements remain incomplete: ${failing.join(', ')}`,
      code: 409,
      failing_checks: failing
    };
  }

  const confToken = crypto.randomBytes(24).toString('hex');
  const expiresAtSql = new Date(Date.now() + 3600 * 1000).toISOString().slice(0, 19).replace('T', ' ');
  const prevStage = req.stage;
  const prevStatus = req.status;

  if (dbActive) {
    await pool.query(
      `UPDATE seller_deletion_requests
       SET stage = 'APPROVED_FOR_DELETION',
           status = 'APPROVED_FOR_DELETION',
           confirmation_token = ?,
           confirmation_expires_at = ?,
           approved_by = ?,
           approved_at = CURRENT_TIMESTAMP,
           admin_remarks = COALESCE(?, admin_remarks),
           updated_at = CURRENT_TIMESTAMP
       WHERE id = ?`,
      [confToken, expiresAtSql, adminId, remarks || null, delReqId]
    );
  } else {
    const nowStr = nowSqlUtc();
    req.stage = 'APPROVED_FOR_DELETION';
    req.status = 'APPROVED_FOR_DELETION';
    req.confirmation_token = confToken;
    req.confirmation_expires_at = expiresAtSql;
    req.approved_by = adminId;
    req.approved_at = nowStr;
    if (remarks) req.admin_remarks = remarks;
    req.updated_at = nowStr;
  }

  await logSellerDeletionAuditNode(pool, ctx, {
    deletionRequestId: delReqId,
    sellerId,
    action: 'APPROVED_FOR_DELETION',
    prevStage,
    newStage: 'APPROVED_FOR_DELETION',
    prevStatus,
    newStatus: 'APPROVED_FOR_DELETION',
    actorId: adminId,
    actorRole: 'admin',
    remarks: remarks || 'First approval confirmation completed; single-use final deletion token issued',
    ipAddress
  }, dbActive);

  await sendDeletionNotificationNode(pool, ctx, {
    userId: sellerId,
    phone: computed.raw_seller?.phone || null,
    title: 'Account Deletion Approved',
    message: `Seller account deletion request (#${delReqId}) has been approved by Admin and is awaiting final deactivation.`,
    type: 'seller_deletion_approved',
    data: { deletion_request_id: Number(delReqId) }
  }, dbActive);

  return { statusCode: 200, data: await buildDeletionWorkspacePayloadNode(pool, ctx, delReqId, dbActive) };
}

async function deletionFinalDeleteNode(pool, ctx, delReqId, adminId, input = {}, ipAddress = null) {
  const { dbActive, req } = await loadRequestForMutation(pool, ctx, delReqId);
  if (!req) return { error: 'Deletion request not found.', code: 404 };
  if (req.status === 'DELETED') {
    return { error: 'Seller account has already been permanently deleted.', code: 409 };
  }
  if (req.stage !== 'APPROVED_FOR_DELETION' || req.status !== 'APPROVED_FOR_DELETION') {
    return { error: 'First Admin Approval is required before Final Delete.', code: 409 };
  }

  const sellerId = Number(req.seller_id);
  const expectedPhrase = `DELETE SELLER #${sellerId}`;
  const providedToken = String(input.confirmation_token || '').trim();
  const providedPhrase = String(input.confirmation_phrase || '').trim();

  if (!providedToken || !req.confirmation_token) {
    return { error: 'Invalid or missing second confirmation token.', code: 422 };
  }
  const tokA = Buffer.from(String(req.confirmation_token));
  const tokB = Buffer.from(providedToken);
  if (tokA.length !== tokB.length || !crypto.timingSafeEqual(tokA, tokB)) {
    return { error: 'Invalid or missing second confirmation token.', code: 422 };
  }

  let isExpired = false;
  if (dbActive) {
    const [expRows] = await pool.query(
      'SELECT (confirmation_expires_at IS NOT NULL AND confirmation_expires_at < ?) AS is_exp FROM seller_deletion_requests WHERE id = ? LIMIT 1',
      [nowSqlUtc(), delReqId]
    );
    isExpired = Number(expRows[0]?.is_exp || 0) === 1;
  } else {
    const expMs = parseTimestampMs(req.confirmation_expires_at);
    isExpired = Boolean(expMs && expMs < Date.now());
  }
  if (isExpired) {
    return { error: 'Confirmation token has expired. Please re-approve to generate a fresh confirmation token.', code: 422 };
  }

  if (providedPhrase !== expectedPhrase) {
    return { error: `Confirmation phrase mismatch. Please enter exact phrase: ${expectedPhrase}`, code: 422 };
  }

  const computed = await computeSellerFinancialAndChecklistNode(pool, ctx, sellerId, delReqId, dbActive);
  if (!computed.all_checks_passed) {
    const failing = computed.checks.filter(c => c.status !== 'SUCCESS').map(c => `${c.check_key} (${c.status})`);
    return { error: `Live verification blocked Final Delete due to incomplete checks: ${failing.join(', ')}`, code: 409 };
  }

  const remarks = String(input.remarks || input.admin_remarks || '').trim();

  if (dbActive) {
    const conn = await pool.getConnection();
    try {
      await conn.beginTransaction();
      await conn.query(
        `UPDATE users
         SET account_status = 'DELETED',
             deleted_at = CURRENT_TIMESTAMP,
             deleted_by = ?,
             token_version = COALESCE(token_version, 1) + 1,
             fcm_token = NULL,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = ?`,
        [adminId, sellerId]
      );
      try { await conn.query('DELETE FROM personal_access_tokens WHERE tokenable_id = ?', [sellerId]); } catch (_) {}
      try { await conn.query('DELETE FROM device_tokens WHERE user_id = ?', [sellerId]); } catch (_) {}

      await conn.query('UPDATE products SET is_active = 0, stock = 0, updated_at = CURRENT_TIMESTAMP WHERE seller_id = ?', [sellerId]);
      await conn.query(
        `UPDATE seller_deletion_requests
         SET stage = 'DELETED',
             status = 'DELETED',
             deleted_by = ?,
             deleted_at = CURRENT_TIMESTAMP,
             confirmation_token = NULL,
             confirmation_expires_at = NULL,
             admin_remarks = COALESCE(?, admin_remarks),
             updated_at = CURRENT_TIMESTAMP
         WHERE id = ?`,
        [adminId, remarks || null, delReqId]
      );
      await conn.query(
        "UPDATE seller_deletion_checks SET status = 'SUCCESS', resolved_by = ?, resolved_at = CURRENT_TIMESTAMP WHERE deletion_request_id = ? AND check_key = 'records_preserved'",
        [adminId, delReqId]
      );
      await conn.commit();
      conn.release();
    } catch (e) {
      try { await conn.rollback(); } catch (_) {}
      conn.release();
      return { error: `Final deletion failed: ${e.message}`, code: 500 };
    }
  } else {
    const nowStr = nowSqlUtc();
    const seller = ctx.getFallbackSellers().find(s => Number(s.id) === sellerId);
    if (seller) {
      seller.account_status = 'DELETED';
      seller.deleted_at = nowStr;
      seller.deleted_by = adminId;
      seller.token_version = Number(seller.token_version || 1) + 1;
      seller.fcm_token = null;
      seller.updated_at = nowStr;
    }

    if (typeof ctx.getFallbackDeviceTokens === 'function') {
      const dTokens = ctx.getFallbackDeviceTokens();
      for (let i = dTokens.length - 1; i >= 0; i--) {
        if (Number(dTokens[i].user_id) === sellerId) dTokens.splice(i, 1);
      }
    }

    for (const pr of ctx.getFallbackProducts()) {
      if (Number(pr.seller_id) === sellerId) {
        pr.is_active = 0;
        pr.stock = 0;
        pr.updated_at = nowStr;
      }
    }

    req.stage = 'DELETED';
    req.status = 'DELETED';
    req.deleted_by = adminId;
    req.deleted_at = nowStr;
    req.confirmation_token = null;
    req.confirmation_expires_at = null;
    if (remarks) req.admin_remarks = remarks;
    req.updated_at = nowStr;

    const store = ctx.getFallbackDeletionStore();
    const recPresCheck = store.deletionChecks.find(c => Number(c.deletion_request_id) === Number(delReqId) && c.check_key === 'records_preserved');
    if (recPresCheck) {
      recPresCheck.status = 'SUCCESS';
      recPresCheck.resolved_by = adminId;
      recPresCheck.resolved_at = nowStr;
    }
  }

  await logSellerDeletionAuditNode(pool, ctx, {
    deletionRequestId: delReqId,
    sellerId,
    action: 'FINAL_SELLER_DELETE',
    prevStage: 'APPROVED_FOR_DELETION',
    newStage: 'DELETED',
    prevStatus: 'APPROVED_FOR_DELETION',
    newStatus: 'DELETED',
    actorId: adminId,
    actorRole: 'admin',
    remarks: remarks || 'Seller soft-deleted, sessions revoked, products deactivated, financial & order history preserved',
    metadata: {
      preserved_orders: computed.orders_enriched.length,
      preserved_refunds: computed.refunds.length,
      preserved_payouts: computed.payouts.length,
      gst_preserved: Boolean(computed.gst)
    },
    ipAddress
  }, dbActive);

  return { statusCode: 200, data: await buildDeletionWorkspacePayloadNode(pool, ctx, delReqId, dbActive) };
}

async function recordOrderPaymentCaptureNode(pool, ctx, orderIdOrNum, input = {}, actorUser = null) {
  const dbActive = await checkDbReachable(pool, ctx);
  const cfg = getSellerDeletionConfigNode();

  let order = null;
  if (dbActive) {
    await ensureSellerDeletionSchemaNode(pool);
    const numId = parseInt(String(orderIdOrNum), 10);
    const isNumeric = !isNaN(numId) && String(numId) === String(orderIdOrNum).trim();
    const [oRows] = isNumeric
      ? await pool.query('SELECT * FROM orders WHERE id = ? OR order_number = ? LIMIT 1', [numId, String(orderIdOrNum)])
      : await pool.query('SELECT * FROM orders WHERE order_number = ? LIMIT 1', [String(orderIdOrNum)]);
    order = oRows[0] || null;
  } else {
    order = ctx.getFallbackOrders().find(o => String(o.id) === String(orderIdOrNum) || o.order_number === String(orderIdOrNum)) || null;
  }

  if (!order) return { error: 'Order not found.', code: 404 };

  const gatewayPaymentId = String(input.gateway_payment_id || input.razorpay_payment_id || '').trim();
  const gatewayOrderId = String(input.gateway_order_id || input.razorpay_order_id || order.gateway_order_id || '').trim();
  const razorpaySig = String(input.razorpay_signature || '').trim();
  const isAdmin = Boolean(actorUser && actorUser.role === 'admin');

  if (!isAdmin) {
    if (!cfg.razorpay_key_secret) {
      return { error: 'Admin authentication or Razorpay signature configuration is required to verify payment capture.', code: 401 };
    }
    if (!razorpaySig || !gatewayOrderId || !gatewayPaymentId) {
      return { error: 'Razorpay payment verification signature, gateway_order_id, and gateway_payment_id are required.', code: 403 };
    }
    const expectedSig = crypto.createHmac('sha256', cfg.razorpay_key_secret).update(`${gatewayOrderId}|${gatewayPaymentId}`).digest('hex');
    const bufA = Buffer.from(expectedSig);
    const bufB = Buffer.from(razorpaySig);
    if (bufA.length !== bufB.length || !crypto.timingSafeEqual(bufA, bufB)) {
      return { error: 'Invalid payment signature.', code: 403 };
    }
  }

  if (!gatewayPaymentId) {
    return { error: 'gateway_payment_id is required.', code: 422 };
  }

  const capturedAmount = (input.captured_amount !== undefined && !isNaN(Number(input.captured_amount)))
    ? round2(input.captured_amount)
    : round2(order.total_amount);
  const gateway = String(input.payment_gateway || input.gateway || 'razorpay').trim() || 'razorpay';
  const isSettled = Boolean(input.settled) || String(input.status || '').toLowerCase() === 'settled';
  const txStatus = isSettled ? 'settled' : 'captured';
  const oid = Number(order.id);
  let txId;

  if (dbActive) {
    const conn = await pool.getConnection();
    try {
      await conn.beginTransaction();
      await conn.query(
        `UPDATE orders
         SET payment_method = CASE WHEN payment_method = 'cod' THEN ? ELSE payment_method END,
             payment_gateway = ?,
             gateway_order_id = COALESCE(?, gateway_order_id),
             gateway_payment_id = ?,
             payment_status = 'paid',
             captured_amount = ?,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = ?`,
        [gateway, gateway, gatewayOrderId || null, gatewayPaymentId, capturedAmount, oid]
      );

      const [exRows] = await conn.query(
        "SELECT id FROM payment_transactions WHERE order_id = ? AND gateway_payment_id = ? AND transaction_type = 'capture' LIMIT 1",
        [oid, gatewayPaymentId]
      );
      if (exRows.length > 0) {
        txId = Number(exRows[0].id);
        if (isSettled) {
          await conn.query("UPDATE payment_transactions SET status = 'settled', settled_at = COALESCE(settled_at, CURRENT_TIMESTAMP), updated_at = CURRENT_TIMESTAMP WHERE id = ?", [txId]);
        }
      } else {
        const [insTx] = await conn.query(
          `INSERT INTO payment_transactions
            (order_id, seller_id, gateway, gateway_order_id, gateway_payment_id, transaction_type, amount, currency, status, gateway_payload, settled_at, created_at, updated_at)
           VALUES (?, ?, ?, ?, ?, 'capture', ?, 'INR', ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)`,
          [
            oid,
            order.seller_id ? Number(order.seller_id) : null,
            gateway,
            gatewayOrderId || null,
            gatewayPaymentId,
            capturedAmount,
            txStatus,
            JSON.stringify(input),
            isSettled ? nowSqlUtc() : null
          ]
        );
        txId = insTx.insertId;
      }
      await conn.commit();
      conn.release();
    } catch (e) {
      try { await conn.rollback(); } catch (_) {}
      conn.release();
      return { error: `Failed to record payment capture: ${e.message}`, code: 500 };
    }
  } else {
    const store = ctx.getFallbackDeletionStore();
    const nowStr = nowSqlUtc();
    if (order.payment_method === 'cod') order.payment_method = gateway;
    order.payment_gateway = gateway;
    if (gatewayOrderId) order.gateway_order_id = gatewayOrderId;
    order.gateway_payment_id = gatewayPaymentId;
    order.payment_status = 'paid';
    order.captured_amount = capturedAmount;
    order.updated_at = nowStr;

    const exTx = store.paymentTransactions.find(t => Number(t.order_id) === oid && t.gateway_payment_id === gatewayPaymentId && t.transaction_type === 'capture');
    if (exTx) {
      txId = exTx.id;
      if (isSettled) {
        exTx.status = 'settled';
        exTx.settled_at = exTx.settled_at || nowStr;
        exTx.updated_at = nowStr;
      }
    } else {
      txId = store.nextIds.paymentTransaction++;
      store.paymentTransactions.push({
        id: txId,
        order_id: oid,
        seller_id: order.seller_id ? Number(order.seller_id) : null,
        gateway,
        gateway_order_id: gatewayOrderId || null,
        gateway_payment_id: gatewayPaymentId,
        transaction_type: 'capture',
        amount: capturedAmount,
        currency: 'INR',
        status: txStatus,
        gateway_payload: JSON.stringify(input),
        settled_at: isSettled ? nowStr : null,
        created_at: nowStr,
        updated_at: nowStr
      });
    }
  }

  return {
    statusCode: 200,
    data: {
      success: true,
      message: 'Payment capture verified and recorded.',
      order_id: oid,
      payment_transaction_id: txId,
      gateway_payment_id: gatewayPaymentId,
      captured_amount: capturedAmount,
      status: txStatus
    }
  };
}

async function createOrderDisputeNode(pool, ctx, orderIdOrNum, input = {}, actorUser = null) {
  const dbActive = await checkDbReachable(pool, ctx);
  let order = null;

  if (dbActive) {
    await ensureSellerDeletionSchemaNode(pool);
    const numId = parseInt(String(orderIdOrNum), 10);
    const isNumeric = !isNaN(numId) && String(numId) === String(orderIdOrNum).trim();
    const [oRows] = isNumeric
      ? await pool.query('SELECT * FROM orders WHERE id = ? OR order_number = ? LIMIT 1', [numId, String(orderIdOrNum)])
      : await pool.query('SELECT * FROM orders WHERE order_number = ? LIMIT 1', [String(orderIdOrNum)]);
    order = oRows[0] || null;
  } else {
    order = ctx.getFallbackOrders().find(o => String(o.id) === String(orderIdOrNum) || o.order_number === String(orderIdOrNum)) || null;
  }

  if (!order) return { error: 'Order not found.', code: 404 };

  const suppliedPhone = String(input.buyer_phone || '').trim();
  const isAdmin = Boolean(actorUser && actorUser.role === 'admin');
  const isSeller = Boolean(actorUser && actorUser.role === 'seller');
  if (!isAdmin && !isSeller) {
    if (!suppliedPhone || suppliedPhone !== String(order.buyer_phone || '').trim()) {
      return { error: 'Unauthorized. Admin/Seller authentication or matching buyer_phone is required to create a dispute.', code: 401 };
    }
  }
  const buyerPhone = suppliedPhone || String(order.buyer_phone || '').trim();

  let sellerId = input.seller_id ? Number(input.seller_id) : Number(order.seller_id || 0);
  if (sellerId <= 0) {
    if (dbActive) {
      const [oiRows] = await pool.query('SELECT seller_id FROM order_items WHERE order_id = ? LIMIT 1', [order.id]);
      sellerId = Number(oiRows[0]?.seller_id || 0);
    } else if (Array.isArray(order.items) && order.items[0]) {
      sellerId = Number(order.items[0].seller_id || 0);
    }
  }

  const disputeType = String(input.dispute_type || 'complaint').trim() || 'complaint';
  const claimAmount = (input.claim_amount !== undefined && !isNaN(Number(input.claim_amount)))
    ? round2(input.claim_amount)
    : round2(order.total_amount || 0);
  const description = String(input.description || input.reason || 'Customer dispute raised').trim();

  let disputeId;
  if (dbActive) {
    const [insRes] = await pool.query(
      `INSERT INTO order_disputes (order_id, seller_id, buyer_phone, dispute_type, status, claim_amount, description, created_at, updated_at)
       VALUES (?, ?, ?, ?, 'OPEN', ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)`,
      [order.id, sellerId, order.buyer_phone || buyerPhone, disputeType, claimAmount, description]
    );
    disputeId = insRes.insertId;
  } else {
    const store = ctx.getFallbackDeletionStore();
    const nowStr = nowSqlUtc();
    disputeId = store.nextIds.dispute++;
    store.disputes.push({
      id: disputeId,
      order_id: Number(order.id),
      seller_id: sellerId,
      buyer_phone: order.buyer_phone || buyerPhone,
      dispute_type: disputeType,
      status: 'OPEN',
      claim_amount: claimAmount,
      description,
      resolution_notes: null,
      resolved_by: null,
      resolved_at: null,
      created_at: nowStr,
      updated_at: nowStr
    });
  }

  return {
    statusCode: 201,
    data: {
      status: 'success',
      message: 'Order dispute created.',
      dispute: {
        id: disputeId,
        order_id: Number(order.id),
        seller_id: sellerId,
        dispute_type: disputeType,
        status: 'OPEN',
        claim_amount: claimAmount,
        description
      }
    }
  };
}

module.exports = {
  ACTIVE_DISPUTE_STATUSES,
  TERMINAL_DELETION_STATUSES,
  getSellerDeletionConfigNode,
  getStatusColorBadgeNode,
  createDefaultFallbackDeletionStore,
  checkDbReachable,
  ensureSellerDeletionSchemaNode,
  getSellerOrdersDetailedNode,
  computeSellerFinancialAndChecklistNode,
  buildDeletionWorkspacePayloadNode,
  callRazorpayHttpNode,
  queueEligibleRefundsForSellerNode,
  executeSingleRefundRecordNode,
  deriveNextStageAndStatusNode,
  deletionListRequestsNode,
  deletionPreflightNode,
  deletionCreateRequestNode,
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
};
