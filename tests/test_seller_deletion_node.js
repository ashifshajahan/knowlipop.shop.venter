/**
 * Comprehensive End-to-End Integration Test Suite for Seller Deletion, Refund & Settlement System (Node.js Engine)
 * Tests all 11 required scenarios plus RBAC, Razorpay HTTP gateway integration, idempotency, multi-vendor refund cap,
 * Hold/Reject recovery, Admin HTML UI, session/token revocation, product removal & full historical preservation.
 */

const assert = require('assert');
const http = require('http');
const { EventEmitter } = require('events');
const { PassThrough } = require('stream');

const app = require('../server');

// Force pure deterministic fallback mode so tests run offline without external MySQL
if (app.__deletionTestContext && typeof app.__deletionTestContext.setForceFallback === 'function') {
  app.__deletionTestContext.setForceFallback(true);
}

let passed = 0;
function ok(label) {
  passed++;
  console.log(`  [PASS] ${label}`);
}

async function httpReq(_unusedBaseUrl, method, pathStr, body = null, headers = {}) {
  return new Promise((resolve, reject) => {
    const reqSocket = new PassThrough();
    reqSocket.remoteAddress = '127.0.0.1';
    const req = new http.IncomingMessage(reqSocket);
    req.method = String(method || 'GET').toUpperCase();
    req.url = pathStr;
    req.headers = {
      host: 'localhost',
      'user-agent': 'deletion-test-runner'
    };
    for (const [k, v] of Object.entries(headers || {})) {
      if (v !== undefined && v !== null) {
        req.headers[k.toLowerCase()] = v;
      }
    }

    let payload = null;
    if (body !== null && body !== undefined) {
      if (typeof body === 'string') {
        payload = body;
        if (!req.headers['content-type']) req.headers['content-type'] = 'application/x-www-form-urlencoded';
      } else {
        payload = JSON.stringify(body);
        if (!req.headers['content-type']) req.headers['content-type'] = 'application/json';
      }
      req.headers['content-length'] = String(Buffer.byteLength(payload));
    }

    const clientRes = new PassThrough();
    const res = new http.ServerResponse(req);
    res.assignSocket(clientRes);

    const chunks = [];
    clientRes.on('data', (chunk) => chunks.push(chunk));

    res.on('finish', () => {
      const raw = Buffer.concat(chunks).toString('utf8');
      const splitIdx = raw.indexOf('\r\n\r\n');
      const text = splitIdx !== -1 ? raw.slice(splitIdx + 4) : raw;
      let json = null;
      try {
        json = JSON.parse(text);
      } catch (e) {
        json = null;
      }

      const rawHeaders = res.getHeaders();
      const normalizedHeaders = {
        get: (name) => {
          const v = rawHeaders[String(name).toLowerCase()];
          if (Array.isArray(v)) return v.join('; ');
          return v !== undefined ? String(v) : null;
        },
        getSetCookie: () => {
          const v = rawHeaders['set-cookie'];
          if (!v) return [];
          return Array.isArray(v) ? v : [String(v)];
        }
      };

      resolve({
        status: res.statusCode,
        headers: normalizedHeaders,
        text,
        json
      });
    });

    res.on('error', reject);
    app.handle(req, res);

    if (payload !== null) {
      req.push(payload);
    }
    req.push(null);
  });
}

function installMockRazorpayTransport() {
  const calls = [];
  const state = {
    mode: 'processed', // 'processed' | 'fail' | 'created'
    seq: 0,
    amountsByRefundId: {}
  };

  const origHttpRequest = http.request;

  const dispatchMockRazorpay = (options, headers, rawBody) => {
    let parsed = null;
    try {
      parsed = rawBody ? JSON.parse(rawBody) : null;
    } catch (e) {
      parsed = null;
    }

    const lowerHeaders = {};
    for (const [k, v] of Object.entries(headers || {})) {
      lowerHeaders[k.toLowerCase()] = v;
    }

    const reqUrl = options.path || '/';
    const reqMethod = String(options.method || 'GET').toUpperCase();

    calls.push({
      method: reqMethod,
      url: reqUrl,
      headers: lowerHeaders,
      body: parsed
    });

    const expectedAuth = 'Basic ' + Buffer.from('rzp_test_key:rzp_test_secret').toString('base64');
    if (lowerHeaders.authorization !== expectedAuth) {
      return {
        statusCode: 401,
        body: JSON.stringify({ error: { code: 'BAD_REQUEST_ERROR', description: 'Invalid API key/secret' } })
      };
    }

    const refundMatch = reqUrl.match(/^\/v1\/payments\/([^/]+)\/refund$/);
    if (reqMethod === 'POST' && refundMatch) {
      const paymentId = decodeURIComponent(refundMatch[1]);
      if (state.mode === 'fail') {
        return {
          statusCode: 500,
          body: JSON.stringify({
            error: {
              code: 'GATEWAY_ERROR',
              description: 'Simulated Razorpay upstream outage'
            }
          })
        };
      }

      state.seq += 1;
      const rfId = `rfnd_mock_${state.seq}`;
      const amount = parsed && parsed.amount ? Number(parsed.amount) : 0;
      state.amountsByRefundId[rfId] = amount;

      if (state.mode === 'created') {
        return {
          statusCode: 200,
          body: JSON.stringify({
            id: rfId,
            entity: 'refund',
            payment_id: paymentId,
            amount,
            currency: 'INR',
            status: 'created'
          })
        };
      }

      return {
        statusCode: 200,
        body: JSON.stringify({
          id: rfId,
          entity: 'refund',
          payment_id: paymentId,
          amount,
          currency: 'INR',
          status: 'processed'
        })
      };
    }

    const getRefundMatch = reqUrl.match(/^\/v1\/refunds\/([^/]+)$/);
    if (reqMethod === 'GET' && getRefundMatch) {
      const rfId = decodeURIComponent(getRefundMatch[1]);
      if (state.mode === 'fail') {
        return {
          statusCode: 500,
          body: JSON.stringify({ error: { code: 'GATEWAY_ERROR', description: 'Simulated Razorpay get-refund outage' } })
        };
      }
      return {
        statusCode: 200,
        body: JSON.stringify({
          id: rfId,
          entity: 'refund',
          amount: state.amountsByRefundId[rfId] || 10000,
          currency: 'INR',
          status: 'processed'
        })
      };
    }

    return {
      statusCode: 404,
      body: JSON.stringify({ error: { description: 'Mock endpoint not found' } })
    };
  };

  http.request = function wrappedHttpRequest(options, cb) {
    const opts = typeof options === 'string' ? new URL(options) : options;
    if (opts && opts.hostname === 'mock-razorpay.local') {
      const clientReq = new EventEmitter();
      const bodyChunks = [];
      clientReq.write = (chunk) => {
        bodyChunks.push(Buffer.isBuffer(chunk) ? chunk : Buffer.from(String(chunk)));
        return true;
      };
      clientReq.destroy = (err) => {
        if (err) process.nextTick(() => clientReq.emit('error', err));
      };
      clientReq.end = (lastChunk) => {
        if (lastChunk) clientReq.write(lastChunk);
        const rawBody = Buffer.concat(bodyChunks).toString('utf8');
        process.nextTick(() => {
          const out = dispatchMockRazorpay(opts, opts.headers || {}, rawBody);
          const mockRes = new EventEmitter();
          mockRes.statusCode = out.statusCode;
          mockRes.headers = { 'content-type': 'application/json' };
          if (typeof cb === 'function') cb(mockRes);
          process.nextTick(() => {
            mockRes.emit('data', Buffer.from(out.body, 'utf8'));
            mockRes.emit('end');
          });
        });
      };
      return clientReq;
    }
    return origHttpRequest.apply(http, arguments);
  };

  return {
    calls,
    state,
    restore: () => {
      http.request = origHttpRequest;
    }
  };
}

let uniqueCounter = 1;
async function registerSeller(baseUrl, tag) {
  const n = uniqueCounter++;
  const email = `del_s${n}_${tag}@test.local`;
  const phone = `+91988100${String(1000 + n)}`;
  const password = 'Password123!';
  const res = await httpReq(baseUrl, 'POST', '/api/register', {
    seller_name: `Deletion Seller ${tag} ${n}`,
    email,
    phone,
    address: `${n} Market Street`,
    landmark: 'Central Hub',
    state: 'Kerala',
    country: 'India',
    password,
    password_confirmation: password
  });
  assert.ok(res.status === 200 || res.status === 201, `register seller failed: ${res.status} ${res.text}`);
  return {
    id: Number(res.json.user.id),
    token: res.json.token,
    email,
    phone,
    password
  };
}

async function createProduct(baseUrl, sellerToken, name, price, stock = 10) {
  const res = await httpReq(baseUrl, 'POST', '/api/products', {
    name,
    seller_price: price,
    price,
    stock,
    category_id: 1,
    description: `Test product ${name}`
  }, { Authorization: `Bearer ${sellerToken}` });
  assert.strictEqual(res.status, 201, `create product failed: ${res.status} ${res.text}`);
  return Number(res.json.product.id);
}

async function createOrder(baseUrl, productId, quantity = 1, paymentMethod = 'cod', buyerPhone = '+919777001122', extraItems = null) {
  const items = extraItems || [{ product_id: productId, quantity }];
  const res = await httpReq(baseUrl, 'POST', '/api/orders', {
    buyer_name: 'Test Buyer',
    buyer_phone: buyerPhone,
    shipping_address: '42 Buyer Lane',
    city: 'Kochi',
    state: 'Kerala',
    pincode: '682001',
    payment_method: paymentMethod,
    items
  });
  assert.strictEqual(res.status, 201, `create order failed: ${res.status} ${res.text}`);
  return Number((res.json.data && res.json.data.id) || (res.json.order && res.json.order.id));
}

async function runTests() {
  const rzp = installMockRazorpayTransport();

  try {
    process.env.RAZORPAY_API_BASE_URL = 'http://mock-razorpay.local/v1';
    process.env.RAZORPAY_KEY_ID = 'rzp_test_key';
    process.env.RAZORPAY_KEY_SECRET = 'rzp_test_secret';
    process.env.RETURN_WINDOW_DAYS = '7';
    process.env.MARKETPLACE_COMMISSION_RATE = '0.05';

    const baseUrl = 'http://localhost';

    const ctx = app.__deletionTestContext;
    const fbSellers = ctx.getFallbackSellers();
    const fbProducts = ctx.getFallbackProducts();
    const fbOrders = ctx.getFallbackOrders();
    const fbNotifications = ctx.getFallbackNotifications();
    const fbDeviceTokens = ctx.getFallbackDeviceTokens();
    const fbStore = ctx.getFallbackDeletionStore();

    // Admin login
    const adminLoginRes = await httpReq(baseUrl, 'POST', '/api/login', {
      email: 'admin@vendor.app',
      password: 'Admin123!'
    });
    assert.strictEqual(adminLoginRes.status, 200, `Admin login failed: ${adminLoginRes.text}`);
    const adminToken = adminLoginRes.json.token;
    const adminAuth = { Authorization: `Bearer ${adminToken}` };
    ok('Admin authenticated via POST /api/login');

    // =========================================================================
    // 0. RBAC & Security Guards + Admin Dashboard UI check
    // =========================================================================
    console.log('\n[Suite 0] RBAC Security & Admin UI Verification');
    const unauthList = await httpReq(baseUrl, 'GET', '/api/admin/seller-deletions');
    assert.strictEqual(unauthList.status, 401);
    ok('Unauthenticated request to /api/admin/seller-deletions rejected with 401');

    const rbacSeller = await registerSeller(baseUrl, 'rbac');
    const nonAdminList = await httpReq(baseUrl, 'GET', '/api/admin/seller-deletions', null, {
      Authorization: `Bearer ${rbacSeller.token}`
    });
    assert.strictEqual(nonAdminList.status, 403);
    ok('Non-admin seller rejected with 403 on admin seller deletion endpoints');

    // Admin web cookie login + /admin/seller-deletions UI check
    const webLogin = await httpReq(baseUrl, 'POST', '/admin/login', 'email=admin%40vendor.app&password=Admin123%21', {
      'Content-Type': 'application/x-www-form-urlencoded'
    });
    assert.strictEqual(webLogin.status, 302);
    const setCookies = typeof webLogin.headers.getSetCookie === 'function'
      ? webLogin.headers.getSetCookie()
      : [webLogin.headers.get('set-cookie')].filter(Boolean);
    const cookieHeader = setCookies.map((c) => String(c).split(';')[0]).join('; ');
    assert.ok(cookieHeader.includes('admin_logged_in=1'));

    const delUi = await httpReq(baseUrl, 'GET', '/admin/seller-deletions', null, { Cookie: cookieHeader });
    assert.strictEqual(delUi.status, 200);
    assert.ok(delUi.text.includes('Seller Deletion Requests'), 'Missing Seller Deletion Requests menu heading');
    for (const cls of ['del-yellow', 'del-blue', 'del-green', 'del-red', 'del-orange']) {
      assert.ok(delUi.text.includes(cls), `Missing status badge CSS class ${cls}`);
    }
    ok('Admin Dashboard UI renders Seller Deletion Requests menu and all 5 color status indicators');

    // Also test non-existent seller & admin self-deletion protection
    const missingPreflight = await httpReq(baseUrl, 'GET', '/api/admin/seller-deletions/preflight/999999', null, adminAuth);
    assert.strictEqual(missingPreflight.status, 404);
    const selfDel = await httpReq(baseUrl, 'POST', '/api/admin/seller-deletions', { seller_id: 1 }, adminAuth);
    assert.strictEqual(selfDel.status, 422);
    ok('Admin account cannot be requested for deletion (422)');

    // =========================================================================
    // Scenario 1: Seller with NO orders
    // =========================================================================
    console.log('\n[Scenario 1] Seller with no orders');
    const s1 = await registerSeller(baseUrl, 'no_orders');
    const p1 = await createProduct(baseUrl, s1.token, 'NoOrder Gadget', 500, 5);

    const pre1 = await httpReq(baseUrl, 'GET', `/api/admin/seller-deletions/preflight/${s1.id}`, null, adminAuth);
    assert.strictEqual(pre1.status, 200);
    assert.strictEqual(pre1.json.metrics.total_products, 1);
    assert.strictEqual(pre1.json.metrics.total_orders, 0);
    assert.strictEqual(pre1.json.metrics.seller_wallet_balance, 0);
    assert.strictEqual(pre1.json.checks.every((c) => c.status === 'SUCCESS'), true);

    const req1 = await httpReq(baseUrl, 'POST', '/api/admin/seller-deletions', {
      seller_id: s1.id,
      reason: 'Inactive test vendor closure'
    }, adminAuth);
    assert.strictEqual(req1.status, 201);
    const d1Id = req1.json.deletion_request.id;
    assert.strictEqual(req1.json.seller.account_status, 'DELETION_PENDING');
    assert.strictEqual(req1.json.deletion_request.stage, 'DELETION_REQUESTED');
    assert.ok(fbNotifications.some((n) => Number(n.user_id) === s1.id && n.type === 'seller_deletion_requested'));
    ok('Scenario 1: Request created -> seller DELETION_PENDING & seller notified');

    // Duplicate request must return 409
    const dup1 = await httpReq(baseUrl, 'POST', '/api/admin/seller-deletions', { seller_id: s1.id }, adminAuth);
    assert.strictEqual(dup1.status, 409);

    // Final delete cannot run before Step 1 approval
    const earlyFin1 = await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d1Id}/final-delete`, {
      confirmation_phrase: `DELETE SELLER #${s1.id}`
    }, adminAuth);
    assert.strictEqual(earlyFin1.status, 409);

    // Step 1 Approve
    const app1 = await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d1Id}/approve`, { remarks: 'Clean account' }, adminAuth);
    assert.strictEqual(app1.status, 200);
    assert.strictEqual(app1.json.deletion_request.status, 'APPROVED_FOR_DELETION');
    const tok1 = app1.json.deletion_request.confirmation_token;
    assert.ok(tok1 && tok1.length >= 20);
    assert.ok(fbNotifications.some((n) => Number(n.user_id) === s1.id && n.type === 'seller_deletion_approved'));

    // Step 2 Final delete with wrong token/phrase rejected 422
    const badTok1 = await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d1Id}/final-delete`, {
      confirmation_token: 'wrong-token',
      confirmation_phrase: `DELETE SELLER #${s1.id}`
    }, adminAuth);
    assert.strictEqual(badTok1.status, 422);

    const badPhrase1 = await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d1Id}/final-delete`, {
      confirmation_token: tok1,
      confirmation_phrase: 'WRONG PHRASE'
    }, adminAuth);
    assert.strictEqual(badPhrase1.status, 422);

    // Step 2 Valid Final Delete
    const fin1 = await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d1Id}/final-delete`, {
      confirmation_token: tok1,
      confirmation_phrase: `DELETE SELLER #${s1.id}`
    }, adminAuth);
    assert.strictEqual(fin1.status, 200);
    assert.strictEqual(fin1.json.deletion_request.status, 'DELETED');
    assert.strictEqual(fin1.json.seller.account_status, 'DELETED');

    // Verify product deactivated and excluded from GET /api/products/:id
    const prodAfter1 = await httpReq(baseUrl, 'GET', `/api/products/${p1}`);
    assert.strictEqual(prodAfter1.status, 404);
    ok('Scenario 1: Seller with no orders passed 2-step confirmation, soft-deleted & product hidden');

    // =========================================================================
    // Scenario 2: Seller with UNPAID orders (+ block new orders, block DELETE)
    // =========================================================================
    console.log('\n[Scenario 2] Seller with unpaid orders');
    const s2 = await registerSeller(baseUrl, 'unpaid');
    const p2 = await createProduct(baseUrl, s2.token, 'Unpaid Widget', 300, 10);
    const o2 = await createOrder(baseUrl, p2, 2, 'cod', '+919777002202');

    // Verify stock deducted from 10 to 8
    const prod2Row = fbProducts.find((p) => Number(p.id) === p2);
    assert.strictEqual(prod2Row.stock, 8);

    const req2 = await httpReq(baseUrl, 'POST', '/api/admin/seller-deletions', { seller_id: s2.id }, adminAuth);
    assert.strictEqual(req2.status, 201);
    const d2Id = req2.json.deletion_request.id;

    // New orders against DELETION_PENDING seller must be blocked (403)
    const blockedOrder2 = await httpReq(baseUrl, 'POST', '/api/orders', {
      buyer_name: 'Blocked Buyer',
      buyer_phone: '+919777009999',
      shipping_address: '99 Block Ave',
      payment_method: 'cod',
      items: [{ product_id: p2, quantity: 1 }]
    });
    assert.strictEqual(blockedOrder2.status, 403);
    ok('Scenario 2: New orders blocked (403) immediately when seller is DELETION_PENDING');

    // Historical order DELETE must be blocked (403)
    const delHistOrd2 = await httpReq(baseUrl, 'DELETE', `/api/orders/${o2}`, null, adminAuth);
    assert.strictEqual(delHistOrd2.status, 403);

    // Approve before verify-orders must fail (409)
    const earlyApp2 = await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d2Id}/approve`, {}, adminAuth);
    assert.strictEqual(earlyApp2.status, 409);

    // Verify orders cancels unpaid pending order and restores stock, creates 0 refunds
    const ver2 = await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d2Id}/verify-orders`, {}, adminAuth);
    assert.strictEqual(ver2.status, 200);
    assert.strictEqual(ver2.json.deletion_request.status, 'ADMIN_REVIEW');
    assert.strictEqual(ver2.json.refunds.length, 0);
    assert.strictEqual(prod2Row.stock, 10, 'Stock must be restored when unpaid pending order is auto-cancelled');

    const app2 = await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d2Id}/approve`, {}, adminAuth);
    assert.strictEqual(app2.status, 200);
    const fin2 = await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d2Id}/final-delete`, {
      confirmation_token: app2.json.deletion_request.confirmation_token,
      confirmation_phrase: `DELETE SELLER #${s2.id}`
    }, adminAuth);
    assert.strictEqual(fin2.status, 200);
    assert.strictEqual(fin2.json.orders.length, 1, 'Cancelled unpaid order record preserved after deletion');
    assert.strictEqual(fin2.json.orders[0].effective_status, 'cancelled');
    ok('Scenario 2: Unpaid pending order auto-cancelled, no spurious refund created, order history preserved');

    // =========================================================================
    // Scenario 3: Seller with PAID but UNACCEPTED orders (Rule A + Razorpay + Missing Gateway ID check)
    // =========================================================================
    console.log('\n[Scenario 3] Seller with paid but unaccepted orders (Rule A)');
    const s3 = await registerSeller(baseUrl, 'paid_unaccepted');
    const p3 = await createProduct(baseUrl, s3.token, 'Paid Unaccepted Item', 650, 5);
    const o3 = await createOrder(baseUrl, p3, 2, 'razorpay', '+919777003303');

    const cap3 = await httpReq(baseUrl, 'POST', `/api/orders/${o3}/payment-verify`, {
      gateway_payment_id: 'pay_test_s3_001',
      gateway_order_id: 'order_rzp_s3_001',
      captured_amount: 1300,
      settled: false
    }, adminAuth);
    assert.strictEqual(cap3.status, 200);

    const req3 = await httpReq(baseUrl, 'POST', '/api/admin/seller-deletions', { seller_id: s3.id }, adminAuth);
    assert.strictEqual(req3.status, 201);
    const d3Id = req3.json.deletion_request.id;

    // Rule A: Seller must be blocked from accepting pending order while DELETION_PENDING
    const sellerAcceptBlocked = await httpReq(baseUrl, 'POST', `/api/seller/orders/${o3}/accept`, {}, {
      Authorization: `Bearer ${s3.token}`
    });
    assert.strictEqual(sellerAcceptBlocked.status, 403);
    ok('Scenario 3: Seller blocked from accepting pending order once DELETION_PENDING');

    const lock3 = await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d3Id}/lock`, { remarks: 'Lock during audit' }, adminAuth);
    assert.strictEqual(lock3.status, 200);
    assert.strictEqual(lock3.json.seller.account_status, 'LOCKED');

    const ver3 = await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d3Id}/verify-orders`, {}, adminAuth);
    assert.strictEqual(ver3.status, 200);
    assert.strictEqual(ver3.json.refunds.length, 1);
    assert.strictEqual(ver3.json.refunds[0].status, 'REFUND_PENDING');
    assert.strictEqual(ver3.json.refunds[0].eligible_refund_amount, 1300);

    // Approve must still be blocked while refund is REFUND_PENDING
    const earlyApp3 = await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d3Id}/approve`, {}, adminAuth);
    assert.strictEqual(earlyApp3.status, 409);

    const prevCalls3 = rzp.calls.length;
    const ref3 = await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d3Id}/process-refunds`, {}, adminAuth);
    assert.strictEqual(ref3.status, 200);
    assert.strictEqual(rzp.calls.length, prevCalls3 + 1);
    const lastRzpCall3 = rzp.calls[rzp.calls.length - 1];
    assert.strictEqual(lastRzpCall3.url, '/v1/payments/pay_test_s3_001/refund');
    assert.strictEqual(lastRzpCall3.body.amount, 130000);
    assert.ok(lastRzpCall3.headers['x-razorpay-idempotency']);

    assert.strictEqual(ref3.json.refunds[0].status, 'REFUND_SUCCESS');
    assert.ok(String(ref3.json.refunds[0].gateway_refund_id).startsWith('rfnd_mock_'));
    assert.strictEqual(ref3.json.deletion_request.status, 'ADMIN_REVIEW');
    assert.ok(fbNotifications.some((n) => n.buyer_phone === '+919777003303' && n.type === 'order_cancelled'));
    assert.ok(fbNotifications.some((n) => n.buyer_phone === '+919777003303' && n.type === 'refund_initiated'));
    assert.ok(fbNotifications.some((n) => n.buyer_phone === '+919777003303' && n.type === 'refund_success'));

    // Finalize deletion
    const app3 = await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d3Id}/approve`, {}, adminAuth);
    assert.strictEqual(app3.status, 200);
    const fin3 = await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d3Id}/final-delete`, {
      confirmation_token: app3.json.deletion_request.confirmation_token,
      confirmation_phrase: `DELETE SELLER #${s3.id}`
    }, adminAuth);
    assert.strictEqual(fin3.status, 200);
    assert.strictEqual(fin3.json.deletion_request.status, 'DELETED');
    ok('Scenario 3: Paid unaccepted order blocked, cancelled, refunded via Razorpay HTTP, buyer notified, and deleted');

    // =========================================================================
    // Scenario 4: Seller with ACCEPTED orders (Rule B requires Admin approval)
    // =========================================================================
    console.log('\n[Scenario 4] Seller with accepted orders (Rule B)');
    const s4 = await registerSeller(baseUrl, 'accepted');
    const p4 = await createProduct(baseUrl, s4.token, 'Accepted Widget', 800, 6);
    const o4 = await createOrder(baseUrl, p4, 1, 'razorpay', '+919777004404');
    await httpReq(baseUrl, 'POST', `/api/orders/${o4}/payment-verify`, {
      gateway_payment_id: 'pay_test_s4_001',
      captured_amount: 800,
      settled: false
    }, adminAuth);

    // Seller accepts the order while still ACTIVE
    const acc4 = await httpReq(baseUrl, 'POST', `/api/seller/orders/${o4}/accept`, {}, {
      Authorization: `Bearer ${s4.token}`
    });
    assert.strictEqual(acc4.status, 200);

    const req4 = await httpReq(baseUrl, 'POST', '/api/admin/seller-deletions', { seller_id: s4.id }, adminAuth);
    const d4Id = req4.json.deletion_request.id;

    // Without approve_accepted_cancellations, Rule B must NOT auto-cancel!
    const ver4NoApprove = await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d4Id}/verify-orders`, {}, adminAuth);
    assert.strictEqual(ver4NoApprove.status, 200);
    assert.strictEqual(ver4NoApprove.json.metrics.confirmed_orders, 1);
    assert.ok(ver4NoApprove.json.blockers.stage_b_accepted_ids.includes(o4));
    assert.strictEqual((await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d4Id}/approve`, {}, adminAuth)).status, 409);

    // With Admin approval, Rule B cancels accepted order & queues refund
    const ver4Approve = await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d4Id}/verify-orders`, {
      approve_accepted_cancellations: true
    }, adminAuth);
    assert.strictEqual(ver4Approve.status, 200);
    assert.strictEqual(ver4Approve.json.metrics.confirmed_orders, 0);
    assert.strictEqual(ver4Approve.json.refunds.length, 1);

    const ref4 = await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d4Id}/process-refunds`, {}, adminAuth);
    assert.strictEqual(ref4.status, 200);
    assert.strictEqual(ref4.json.all_checks_passed, true);

    const app4 = await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d4Id}/approve`, {}, adminAuth);
    assert.strictEqual(app4.status, 200);
    const fin4 = await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d4Id}/final-delete`, {
      confirmation_token: app4.json.deletion_request.confirmation_token,
      confirmation_phrase: `DELETE SELLER #${s4.id}`
    }, adminAuth);
    assert.strictEqual(fin4.status, 200);
    assert.strictEqual(fin4.json.deletion_request.status, 'DELETED');
    ok('Scenario 4: Rule B blocked without approval, then cancelled & refunded cleanly with approval');

    // =========================================================================
    // Scenario 5: Seller with SHIPPED orders (Rule C blocks auto-cancel; resolve via delivery + settlement)
    // =========================================================================
    console.log('\n[Scenario 5] Seller with shipped orders (Rule C)');
    const s5 = await registerSeller(baseUrl, 'shipped');
    const p5 = await createProduct(baseUrl, s5.token, 'Shipped Camera', 1000, 4);
    const o5 = await createOrder(baseUrl, p5, 1, 'razorpay', '+919777005505');
    await httpReq(baseUrl, 'POST', `/api/orders/${o5}/payment-verify`, {
      gateway_payment_id: 'pay_test_s5_001',
      captured_amount: 1000,
      settled: true
    }, adminAuth);
    await httpReq(baseUrl, 'POST', `/api/seller/orders/${o5}/accept`, {}, { Authorization: `Bearer ${s5.token}` });
    const ship5 = await httpReq(baseUrl, 'POST', `/api/seller/orders/${o5}/status`, { status: 'shipped' }, { Authorization: `Bearer ${s5.token}` });
    assert.strictEqual(ship5.status, 200);

    const req5 = await httpReq(baseUrl, 'POST', '/api/admin/seller-deletions', { seller_id: s5.id }, adminAuth);
    const d5Id = req5.json.deletion_request.id;

    // Even with approve_accepted_cancellations=true, verify-orders must NOT cancel Rule C shipped order!
    const ver5 = await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d5Id}/verify-orders`, {
      approve_accepted_cancellations: true
    }, adminAuth);
    assert.strictEqual(ver5.status, 200);
    assert.strictEqual(ver5.json.metrics.shipped_orders, 1);
    assert.strictEqual(ver5.json.checks.find((c) => c.check_key === 'orders_resolved').status, 'ON_HOLD');
    assert.strictEqual((await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d5Id}/approve`, {}, adminAuth)).status, 409);

    // Resolve via delivery confirmation + close return window
    const res5 = await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d5Id}/resolve-dispute`, {
      order_id: o5,
      mark_delivered: true,
      close_return_window: true,
      resolution_notes: 'Courier confirmed POD delivery & return window elapsed'
    }, adminAuth);
    assert.strictEqual(res5.status, 200);
    assert.strictEqual(res5.json.metrics.shipped_orders, 0);
    assert.strictEqual(res5.json.metrics.delivered_orders, 1);
    assert.strictEqual(res5.json.checks.find((c) => c.check_key === 'orders_resolved').status, 'SUCCESS');
    assert.strictEqual(res5.json.settlement.available_seller_balance, 950);
    assert.strictEqual(res5.json.settlement.outstanding_commissions, 50);

    // Settle payout before final approval
    const set5 = await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d5Id}/settle-payout`, {
      payout_reference: 'UTR-S5-001',
      confirm_gateway_settled: true
    }, adminAuth);
    assert.strictEqual(set5.status, 200);
    assert.strictEqual(set5.json.all_checks_passed, true);

    const app5 = await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d5Id}/approve`, {}, adminAuth);
    assert.strictEqual(app5.status, 200);
    const fin5 = await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d5Id}/final-delete`, {
      confirmation_token: app5.json.deletion_request.confirmation_token,
      confirmation_phrase: `DELETE SELLER #${s5.id}`
    }, adminAuth);
    assert.strictEqual(fin5.status, 200);
    assert.strictEqual(fin5.json.deletion_request.status, 'DELETED');
    ok('Scenario 5: Shipped order never auto-cancelled, blocked until delivered & payout settled');

    // =========================================================================
    // Scenario 6: Seller with DELIVERED orders (Rule D return window + Hold action test)
    // =========================================================================
    console.log('\n[Scenario 6] Seller with delivered orders inside return window (Rule D)');
    const s6 = await registerSeller(baseUrl, 'delivered');
    const p6 = await createProduct(baseUrl, s6.token, 'Delivered Toaster', 1000, 5);
    const o6 = await createOrder(baseUrl, p6, 1, 'cod', '+919777006606');
    await httpReq(baseUrl, 'POST', `/api/seller/orders/${o6}/accept`, {}, { Authorization: `Bearer ${s6.token}` });
    const deliv6 = await httpReq(baseUrl, 'POST', `/api/seller/orders/${o6}/status`, { status: 'delivered' }, { Authorization: `Bearer ${s6.token}` });
    assert.strictEqual(deliv6.status, 200);

    const req6 = await httpReq(baseUrl, 'POST', '/api/admin/seller-deletions', { seller_id: s6.id }, adminAuth);
    const d6Id = req6.json.deletion_request.id;

    // Delivered within 7-day return window must show orders_resolved = ON_HOLD
    assert.strictEqual(req6.json.checks.find((c) => c.check_key === 'orders_resolved').status, 'ON_HOLD');
    assert.ok(req6.json.blockers.stage_d_return_window_ids.includes(o6));
    assert.strictEqual((await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d6Id}/approve`, {}, adminAuth)).status, 409);

    // Put On Hold explicitly & verify DELETION_ON_HOLD status
    const hold6 = await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d6Id}/hold`, {
      reason: 'Waiting for 7-day buyer return window expiration'
    }, adminAuth);
    assert.strictEqual(hold6.status, 200);
    assert.strictEqual(hold6.json.deletion_request.status, 'DELETION_ON_HOLD');

    // Complete return window
    const close6 = await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d6Id}/resolve-dispute`, {
      order_id: o6,
      close_return_window: true,
      resolution_notes: 'Return window elapsed cleanly with no complaints'
    }, adminAuth);
    assert.strictEqual(close6.status, 200);
    assert.strictEqual(close6.json.checks.find((c) => c.check_key === 'orders_resolved').status, 'SUCCESS');
    assert.strictEqual(close6.json.settlement.outstanding_commissions, 50);

    // Must clear COD commission liability before approve
    assert.strictEqual((await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d6Id}/approve`, {}, adminAuth)).status, 409);
    const set6 = await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d6Id}/settle-payout`, {
      payout_reference: 'COMM-CLR-S6'
    }, adminAuth);
    assert.strictEqual(set6.status, 200);
    assert.strictEqual(set6.json.all_checks_passed, true);

    const app6 = await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d6Id}/approve`, {}, adminAuth);
    assert.strictEqual(app6.status, 200);
    const fin6 = await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d6Id}/final-delete`, {
      confirmation_token: app6.json.deletion_request.confirmation_token,
      confirmation_phrase: `DELETE SELLER #${s6.id}`
    }, adminAuth);
    assert.strictEqual(fin6.status, 200);
    assert.strictEqual(fin6.json.deletion_request.status, 'DELETED');
    ok('Scenario 6: Delivered order blocked during return window & COD commission clearance, then completed');

    // =========================================================================
    // Scenario 7: Seller with PENDING REFUNDS (cancelled paid order - Rule E)
    // =========================================================================
    console.log('\n[Scenario 7] Seller with pending refunds (Rule E)');
    const s7 = await registerSeller(baseUrl, 'pending_refunds');
    const p7 = await createProduct(baseUrl, s7.token, 'Pending Refund Watch', 750, 5);
    const o7 = await createOrder(baseUrl, p7, 1, 'razorpay', '+919777007707');
    await httpReq(baseUrl, 'POST', `/api/orders/${o7}/payment-verify`, {
      gateway_payment_id: 'pay_test_s7_001',
      captured_amount: 750,
      settled: false
    }, adminAuth);
    // Cancel the order prior to deletion request
    const can7 = await httpReq(baseUrl, 'POST', `/api/seller/orders/${o7}/cancel`, {
      cancellation_reason: 'Customer cancelled paid order before shipping'
    }, { Authorization: `Bearer ${s7.token}` });
    assert.strictEqual(can7.status, 200);

    const req7 = await httpReq(baseUrl, 'POST', '/api/admin/seller-deletions', { seller_id: s7.id }, adminAuth);
    const d7Id = req7.json.deletion_request.id;
    assert.strictEqual(req7.json.metrics.cancelled_orders, 1);
    assert.strictEqual(req7.json.metrics.pending_refunds_count, 1);
    assert.strictEqual(req7.json.metrics.pending_refunds_amount, 750);
    assert.strictEqual(req7.json.checks.find((c) => c.check_key === 'refunds_confirmed').status, 'PENDING');
    assert.strictEqual((await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d7Id}/approve`, {}, adminAuth)).status, 409);

    const ref7 = await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d7Id}/process-refunds`, {}, adminAuth);
    assert.strictEqual(ref7.status, 200);
    assert.strictEqual(ref7.json.refunds[0].status, 'REFUND_SUCCESS');
    assert.strictEqual(ref7.json.metrics.pending_refunds_count, 0);
    assert.strictEqual(ref7.json.all_checks_passed, true);

    const app7 = await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d7Id}/approve`, {}, adminAuth);
    assert.strictEqual(app7.status, 200);
    const fin7 = await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d7Id}/final-delete`, {
      confirmation_token: app7.json.deletion_request.confirmation_token,
      confirmation_phrase: `DELETE SELLER #${s7.id}`
    }, adminAuth);
    assert.strictEqual(fin7.status, 200);
    ok('Scenario 7: Cancelled paid order detected unrefunded -> processed via Razorpay -> deleted');

    // =========================================================================
    // Scenario 8: Seller with FAILED REFUNDS + Retry + Idempotency + Missing Gateway Payment ID
    // =========================================================================
    console.log('\n[Scenario 8] Seller with failed refunds, retry idempotency & missing gateway ID');
    const s8 = await registerSeller(baseUrl, 'failed_refunds');
    const p8 = await createProduct(baseUrl, s8.token, 'Failed Refund Item', 900, 5);
    const o8 = await createOrder(baseUrl, p8, 1, 'razorpay', '+919777008808');
    await httpReq(baseUrl, 'POST', `/api/orders/${o8}/payment-verify`, {
      gateway_payment_id: 'pay_test_s8_fail',
      captured_amount: 900,
      settled: false
    }, adminAuth);

    const req8 = await httpReq(baseUrl, 'POST', '/api/admin/seller-deletions', { seller_id: s8.id }, adminAuth);
    const d8Id = req8.json.deletion_request.id;
    await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d8Id}/verify-orders`, {}, adminAuth);

    // Switch mock Razorpay to fail mode
    rzp.state.mode = 'fail';
    const fail8 = await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d8Id}/process-refunds`, {}, adminAuth);
    assert.strictEqual(fail8.status, 200);
    assert.strictEqual(fail8.json.refunds[0].status, 'REFUND_FAILED');
    assert.strictEqual(fail8.json.deletion_request.status, 'REFUND_FAILED');
    assert.strictEqual(fail8.json.checks.find((c) => c.check_key === 'refunds_confirmed').status, 'FAILED');
    assert.ok(fbNotifications.some((n) => n.buyer_phone === '+919777008808' && n.type === 'refund_failed'));

    // Approve & Settle Payout must be strictly blocked on REFUND_FAILED
    assert.strictEqual((await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d8Id}/approve`, {}, adminAuth)).status, 409);
    assert.strictEqual((await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d8Id}/settle-payout`, {}, adminAuth)).status, 409);

    // Recover gateway and retry
    rzp.state.mode = 'processed';
    const retry8 = await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d8Id}/process-refunds`, {}, adminAuth);
    assert.strictEqual(retry8.status, 200);
    assert.strictEqual(retry8.json.refunds[0].status, 'REFUND_SUCCESS');

    // Idempotency test: calling process-refunds again must make ZERO Razorpay API calls
    const callCountBeforeIdemp = rzp.calls.length;
    const idemp8 = await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d8Id}/process-refunds`, {}, adminAuth);
    assert.strictEqual(idemp8.status, 200);
    assert.strictEqual(rzp.calls.length, callCountBeforeIdemp, 'Idempotent retry must make ZERO duplicate Razorpay calls');

    const app8 = await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d8Id}/approve`, {}, adminAuth);
    assert.strictEqual(app8.status, 200);
    const fin8 = await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d8Id}/final-delete`, {
      confirmation_token: app8.json.deletion_request.confirmation_token,
      confirmation_phrase: `DELETE SELLER #${s8.id}`
    }, adminAuth);
    assert.strictEqual(fin8.status, 200);

    // Edge case: paid order missing gateway_payment_id -> REFUND_REQUIRES_REVIEW
    const sMissingGw = await registerSeller(baseUrl, 'missing_gw');
    const pMissingGw = await createProduct(baseUrl, sMissingGw.token, 'NoGW Item', 400, 3);
    const oMissingGw = await createOrder(baseUrl, pMissingGw, 1, 'razorpay', '+919777008899');
    const rawMissingOrd = fbOrders.find((o) => Number(o.id) === oMissingGw);
    rawMissingOrd.payment_status = 'paid';
    rawMissingOrd.order_status = 'cancelled';
    rawMissingOrd.captured_amount = 400;
    rawMissingOrd.gateway_payment_id = null;

    const reqMissing = await httpReq(baseUrl, 'POST', '/api/admin/seller-deletions', { seller_id: sMissingGw.id }, adminAuth);
    const procMissing = await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${reqMissing.json.deletion_request.id}/process-refunds`, {}, adminAuth);
    assert.strictEqual(procMissing.status, 200);
    assert.strictEqual(procMissing.json.refunds[0].status, 'REFUND_REQUIRES_REVIEW');
    assert.strictEqual(procMissing.json.checks.find((c) => c.check_key === 'refunds_confirmed').status, 'ON_HOLD');
    assert.strictEqual((await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${reqMissing.json.deletion_request.id}/approve`, {}, adminAuth)).status, 409);
    ok('Scenario 8: Failed refunds blocked deletion, retried safely with zero duplicate gateway calls, missing gateway_payment_id flagged REFUND_REQUIRES_REVIEW');

    // =========================================================================
    // Scenario 9: Seller with PENDING PAYOUTS + Multi-vendor proportional refund cap
    // =========================================================================
    console.log('\n[Scenario 9] Seller with pending payouts & unsettled gateway capture + multi-vendor proportional cap');
    const s9 = await registerSeller(baseUrl, 'pending_payouts');
    const p9 = await createProduct(baseUrl, s9.token, 'Payout Speaker', 1000, 5);
    const o9 = await createOrder(baseUrl, p9, 1, 'razorpay', '+919777009909');
    await httpReq(baseUrl, 'POST', `/api/orders/${o9}/payment-verify`, {
      gateway_payment_id: 'pay_test_s9_001',
      captured_amount: 1000,
      settled: false // Unsettled gateway capture!
    }, adminAuth);
    await httpReq(baseUrl, 'POST', `/api/seller/orders/${o9}/accept`, {}, { Authorization: `Bearer ${s9.token}` });
    await httpReq(baseUrl, 'POST', `/api/seller/orders/${o9}/status`, { status: 'delivered' }, { Authorization: `Bearer ${s9.token}` });
    // Advance return window past expiry on order 9
    const ord9Obj = fbOrders.find((o) => Number(o.id) === o9);
    ord9Obj.return_window_ends_at = new Date(Date.now() - 86400 * 1000).toISOString();

    // Seed an explicit PENDING payout row
    fbStore.payouts.push({
      id: fbStore.nextIds.payout++,
      seller_id: s9.id,
      deletion_request_id: null,
      amount: 1000,
      commission_deducted: 50,
      refund_recovery_deducted: 0,
      net_amount: 950,
      status: 'PENDING',
      payout_reference: null,
      settled_by: null,
      settled_at: null,
      created_at: new Date().toISOString()
    });

    const req9 = await httpReq(baseUrl, 'POST', '/api/admin/seller-deletions', { seller_id: s9.id }, adminAuth);
    const d9Id = req9.json.deletion_request.id;
    assert.strictEqual(req9.json.settlement.pending_payouts_count, 1);
    assert.strictEqual(req9.json.settlement.pending_payouts_amount, 950);
    assert.strictEqual(req9.json.settlement.unsettled_gateway_count, 1);
    assert.strictEqual(req9.json.checks.find((c) => c.check_key === 'payouts_settled').status, 'PENDING');
    assert.strictEqual(req9.json.checks.find((c) => c.check_key === 'liabilities_cleared').status, 'PENDING');

    // Attempt settle-payout WITHOUT confirm_gateway_settled -> blocked 409!
    const badSettle9 = await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d9Id}/settle-payout`, {
      payout_reference: 'UTR-PREMATURE'
    }, adminAuth);
    assert.strictEqual(badSettle9.status, 409);

    // Attempt approve -> blocked 409
    assert.strictEqual((await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d9Id}/approve`, {}, adminAuth)).status, 409);

    // Settle with gateway settlement confirmation
    const settle9 = await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d9Id}/settle-payout`, {
      confirm_gateway_settled: true,
      payout_reference: 'UTR-S9-SETTLED'
    }, adminAuth);
    assert.strictEqual(settle9.status, 200);
    assert.strictEqual(settle9.json.settlement.pending_payouts_count, 0);
    assert.strictEqual(settle9.json.settlement.settled_payouts_amount, 950);
    assert.strictEqual(settle9.json.settlement.gateway_settled_ok, true);
    assert.strictEqual(settle9.json.all_checks_passed, true);

    // Verify no double payout on repeat call
    const repeatSettle9 = await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d9Id}/settle-payout`, {
      confirm_gateway_settled: true
    }, adminAuth);
    assert.strictEqual(repeatSettle9.status, 200);
    assert.strictEqual(repeatSettle9.json.settlement.settled_payouts_amount, 950);

    const app9 = await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d9Id}/approve`, {}, adminAuth);
    const fin9 = await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d9Id}/final-delete`, {
      confirmation_token: app9.json.deletion_request.confirmation_token,
      confirmation_phrase: `DELETE SELLER #${s9.id}`
    }, adminAuth);
    assert.strictEqual(fin9.status, 200);

    // Bonus check: Multi-vendor proportional refund cap (Seller A = 300, Seller B = 700, total = 1000)
    const mvA = await registerSeller(baseUrl, 'mvA');
    const mvB = await registerSeller(baseUrl, 'mvB');
    const pMvA = await createProduct(baseUrl, mvA.token, 'MV Item A', 300, 5);
    const pMvB = await createProduct(baseUrl, mvB.token, 'MV Item B', 700, 5);
    const mvOrderId = await createOrder(baseUrl, pMvA, 1, 'razorpay', '+919777009955', [
      { product_id: pMvA, quantity: 1 },
      { product_id: pMvB, quantity: 1 }
    ]);
    await httpReq(baseUrl, 'POST', `/api/orders/${mvOrderId}/payment-verify`, {
      gateway_payment_id: 'pay_mv_shared_1',
      captured_amount: 1000,
      settled: false
    }, adminAuth);
    const reqMvA = await httpReq(baseUrl, 'POST', '/api/admin/seller-deletions', { seller_id: mvA.id }, adminAuth);
    const dMvAId = reqMvA.json.deletion_request.id;
    await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${dMvAId}/verify-orders`, {}, adminAuth);
    const mvProc = await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${dMvAId}/process-refunds`, {}, adminAuth);
    assert.strictEqual(mvProc.status, 200);
    assert.strictEqual(mvProc.json.refunds.length, 1);
    assert.strictEqual(mvProc.json.refunds[0].eligible_refund_amount, 300, 'Proportional multi-vendor refund cap must equal Seller A share (300)');
    assert.strictEqual(mvProc.json.refunds[0].refunded_amount, 300);
    const mvOrderObj = fbOrders.find((o) => Number(o.id) === mvOrderId);
    assert.strictEqual(mvOrderObj.payment_status, 'partially_refunded');
    assert.notStrictEqual(mvOrderObj.order_status, 'cancelled', 'Other active seller B item keeps order non-cancelled');
    ok('Scenario 9: Pending payouts & unsettled captures gated deletion, multi-vendor refund capped strictly to seller share (300 of 1000)');

    // =========================================================================
    // Scenario 10: Seller with ACTIVE DISPUTES
    // =========================================================================
    console.log('\n[Scenario 10] Seller with active disputes');
    const s10 = await registerSeller(baseUrl, 'disputes');
    const p10 = await createProduct(baseUrl, s10.token, 'Disputed Headphone', 1200, 5);
    const o10 = await createOrder(baseUrl, p10, 1, 'razorpay', '+919777010010');
    await httpReq(baseUrl, 'POST', `/api/orders/${o10}/payment-verify`, {
      gateway_payment_id: 'pay_test_s10_001',
      captured_amount: 1200,
      settled: true
    }, adminAuth);
    await httpReq(baseUrl, 'POST', `/api/seller/orders/${o10}/accept`, {}, { Authorization: `Bearer ${s10.token}` });
    await httpReq(baseUrl, 'POST', `/api/seller/orders/${o10}/status`, { status: 'delivered' }, { Authorization: `Bearer ${s10.token}` });
    fbOrders.find((o) => Number(o.id) === o10).return_window_ends_at = new Date(Date.now() - 86400 * 1000).toISOString();

    const dispRes10 = await httpReq(baseUrl, 'POST', `/api/orders/${o10}/disputes`, {
      buyer_phone: '+919777010010',
      dispute_type: 'damaged_item',
      claim_amount: 1200,
      description: 'Right earcup dead on arrival'
    });
    assert.strictEqual(dispRes10.status, 201);
    const disp10Id = dispRes10.json.dispute.id;

    const req10 = await httpReq(baseUrl, 'POST', '/api/admin/seller-deletions', { seller_id: s10.id }, adminAuth);
    const d10Id = req10.json.deletion_request.id;
    assert.strictEqual(req10.json.settlement.pending_disputes_count, 1);
    assert.strictEqual(req10.json.checks.find((c) => c.check_key === 'disputes_resolved').status, 'ON_HOLD');

    // Payout settlement & approval must BOTH fail while dispute is open
    assert.strictEqual((await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d10Id}/settle-payout`, { confirm_gateway_settled: true }, adminAuth)).status, 409);
    assert.strictEqual((await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d10Id}/approve`, {}, adminAuth)).status, 409);

    // Resolve dispute by issuing buyer refund
    const resDisp10 = await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d10Id}/resolve-dispute`, {
      dispute_id: disp10Id,
      resolution: 'RESOLVED',
      issue_refund: true,
      resolution_notes: 'Verified defect; approved buyer refund'
    }, adminAuth);
    assert.strictEqual(resDisp10.status, 200);
    assert.strictEqual(resDisp10.json.checks.find((c) => c.check_key === 'disputes_resolved').status, 'SUCCESS');
    assert.strictEqual(resDisp10.json.refunds.length, 1);

    const ref10 = await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d10Id}/process-refunds`, {}, adminAuth);
    assert.strictEqual(ref10.status, 200);
    assert.strictEqual(ref10.json.all_checks_passed, true);

    const app10 = await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d10Id}/approve`, {}, adminAuth);
    const fin10 = await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d10Id}/final-delete`, {
      confirmation_token: app10.json.deletion_request.confirmation_token,
      confirmation_phrase: `DELETE SELLER #${s10.id}`
    }, adminAuth);
    assert.strictEqual(fin10.status, 200);
    ok('Scenario 10: Active dispute blocked payouts & approval until resolved & refunded');

    // =========================================================================
    // Scenario 11: Seller with FULLY SETTLED orders/payments + Reject/Restore + Full Post-Delete Audit
    // =========================================================================
    console.log('\n[Scenario 11] Fully settled seller, Reject->ACTIVE recovery, Token revocation & Preservation');
    const s11 = await registerSeller(baseUrl, 'settled_full');
    const p11 = await createProduct(baseUrl, s11.token, 'Settled Laptop Stand', 2000, 10);
    const o11 = await createOrder(baseUrl, p11, 1, 'razorpay', '+919777011011');
    await httpReq(baseUrl, 'POST', `/api/orders/${o11}/payment-verify`, {
      gateway_payment_id: 'pay_test_s11_001',
      captured_amount: 2000,
      settled: true
    }, adminAuth);
    await httpReq(baseUrl, 'POST', `/api/seller/orders/${o11}/accept`, {}, { Authorization: `Bearer ${s11.token}` });
    await httpReq(baseUrl, 'POST', `/api/seller/orders/${o11}/status`, { status: 'delivered' }, { Authorization: `Bearer ${s11.token}` });

    // Pre-mark order settled, return window closed, payout settled, GST & device token attached
    const ord11Obj = fbOrders.find((o) => Number(o.id) === o11);
    ord11Obj.return_window_ends_at = new Date(Date.now() - 10 * 86400 * 1000).toISOString();
    ord11Obj.settled_at = new Date(Date.now() - 2 * 86400 * 1000).toISOString();
    fbStore.payouts.push({
      id: fbStore.nextIds.payout++,
      seller_id: s11.id,
      deletion_request_id: null,
      amount: 2000,
      commission_deducted: 100,
      refund_recovery_deducted: 0,
      net_amount: 1900,
      status: 'SETTLED',
      payout_reference: 'UTR-PRE-S11',
      settled_by: 1,
      settled_at: new Date().toISOString(),
      created_at: new Date().toISOString()
    });
    fbStore.gstDetails.push({
      id: 9011,
      seller_id: s11.id,
      gstin: '32ABCDE1234F1Z5',
      legal_name: 'Settled Vendor Pvt Ltd',
      status: 'Approved'
    });
    fbDeviceTokens.push({ user_id: s11.id, phone: s11.phone, token: 'fcm_tok_s11', platform: 'android' });

    // First test REJECT workflow and recovery to ACTIVE
    const req11a = await httpReq(baseUrl, 'POST', '/api/admin/seller-deletions', { seller_id: s11.id, reason: 'Initial check' }, adminAuth);
    assert.strictEqual(req11a.status, 201);
    const d11aId = req11a.json.deletion_request.id;

    // Missing rejection reason returns 422
    const badRej11 = await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d11aId}/reject`, {}, adminAuth);
    assert.strictEqual(badRej11.status, 422);

    const rej11 = await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d11aId}/reject`, {
      reason: 'Seller withdrew deletion request cleanly'
    }, adminAuth);
    assert.strictEqual(rej11.status, 200);
    assert.strictEqual(rej11.json.deletion_request.status, 'DELETION_REJECTED');
    assert.strictEqual(rej11.json.seller.account_status, 'ACTIVE');
    assert.ok(fbNotifications.some((n) => Number(n.user_id) === s11.id && n.type === 'seller_deletion_rejected'));

    // Seller can now create new products/orders while restored to ACTIVE
    const extraProd11 = await createProduct(baseUrl, s11.token, 'Restored Mouse', 400, 3);
    ok('Scenario 11 (part A): Reject requires reason, restores seller to ACTIVE & notifies seller');

    // Re-open fresh deletion request for full final deletion
    const req11 = await httpReq(baseUrl, 'POST', '/api/admin/seller-deletions', { seller_id: s11.id, reason: 'Final approved closure' }, adminAuth);
    assert.strictEqual(req11.status, 201);
    const d11Id = req11.json.deletion_request.id;
    assert.strictEqual(req11.json.all_checks_passed, true, 'Pre-settled seller passes all 6 checks immediately');
    assert.strictEqual(req11.json.gst.gstin, '32ABCDE1234F1Z5');

    const vs11 = await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d11Id}/verify-settlement`, {}, adminAuth);
    assert.strictEqual(vs11.status, 200);
    assert.strictEqual(vs11.json.deletion_request.status, 'ADMIN_REVIEW');

    const app11 = await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d11Id}/approve`, { remarks: 'All financials verified' }, adminAuth);
    assert.strictEqual(app11.status, 200);
    const fin11 = await httpReq(baseUrl, 'POST', `/api/admin/seller-deletions/${d11Id}/final-delete`, {
      confirmation_token: app11.json.deletion_request.confirmation_token,
      confirmation_phrase: `DELETE SELLER #${s11.id}`,
      remarks: 'Permanent soft-delete executed'
    }, adminAuth);
    assert.strictEqual(fin11.status, 200);
    assert.strictEqual(fin11.json.deletion_request.status, 'DELETED');
    assert.strictEqual(fin11.json.seller.account_status, 'DELETED');

    // Post-deletion security & preservation assertions:
    // 1. Old seller JWT must fail (401/403)
    const oldMe11 = await httpReq(baseUrl, 'GET', '/api/me', null, { Authorization: `Bearer ${s11.token}` });
    assert.ok(oldMe11.status === 401 || oldMe11.status === 403, `Expected 401/403 for revoked seller JWT, got ${oldMe11.status}`);
    const oldMut11 = await httpReq(baseUrl, 'POST', '/api/products', { name: 'Blocked', price: 100, stock: 1 }, { Authorization: `Bearer ${s11.token}` });
    assert.ok(oldMut11.status === 401 || oldMut11.status === 403);

    // 2. Login rejected with 403 account_status = DELETED
    const loginAfter11 = await httpReq(baseUrl, 'POST', '/api/login', { email: s11.email, password: s11.password });
    assert.strictEqual(loginAfter11.status, 403);
    assert.strictEqual(loginAfter11.json.errors.account_status[0], 'DELETED');

    // 3. Re-register rejected with 403
    const regAfter11 = await httpReq(baseUrl, 'POST', '/api/register', {
      seller_name: 'Retry Deleted',
      email: s11.email,
      phone: s11.phone,
      address: '123 St',
      state: 'Kerala',
      password: s11.password,
      password_confirmation: s11.password
    });
    assert.strictEqual(regAfter11.status, 403);

    // 4. Seller products hidden from public listings (GET /api/products and GET /api/products/:id), yet product rows remain in storage
    const pubList = await httpReq(baseUrl, 'GET', '/api/products');
    assert.strictEqual(pubList.status, 200);
    const pubIds = pubList.json.data.map((p) => Number(p.id));
    assert.ok(!pubIds.includes(p11) && !pubIds.includes(extraProd11), 'Deleted seller products must not appear in public GET /api/products');
    assert.strictEqual((await httpReq(baseUrl, 'GET', `/api/products/${p11}`)).status, 404);
    assert.strictEqual(Number(fbProducts.find((p) => Number(p.id) === p11).is_active), 0, 'Product row preserved softly deactivated');

    // 5. Device tokens revoked, while orders, payment_transactions, payouts, GST & audit logs are fully preserved
    assert.strictEqual(fbDeviceTokens.filter((t) => Number(t.user_id) === s11.id).length, 0);
    assert.ok(fbOrders.some((o) => Number(o.id) === o11), 'Order record preserved');
    assert.ok(fbStore.paymentTransactions.some((t) => Number(t.order_id) === o11), 'Payment transaction preserved');
    assert.ok(fbStore.payouts.some((p) => Number(p.seller_id) === s11.id), 'Payout record preserved');
    assert.ok(fbStore.gstDetails.some((g) => Number(g.seller_id) === s11.id), 'GST record preserved');
    assert.ok(fin11.json.audit_logs.length >= 4, 'Full admin activity audit trail recorded');
    const delActions = fin11.json.audit_logs.map((a) => a.action);
    assert.ok(delActions.includes('DELETION_REQUESTED'));
    assert.ok(delActions.includes('APPROVED_FOR_DELETION'));
    assert.ok(delActions.includes('FINAL_SELLER_DELETE'));
    ok('Scenario 11: Settled seller cleanly soft-deleted, tokens revoked, listings removed, all financial/GST/order/audit history preserved');

    // Verify GET /api/admin/seller-deletions lists & filters accurately
    const listAll = await httpReq(baseUrl, 'GET', '/api/admin/seller-deletions', null, adminAuth);
    assert.strictEqual(listAll.status, 200);
    assert.ok(listAll.json.kpis.deleted >= 10);
    assert.ok(listAll.json.kpis.rejected >= 1);
    ok('List endpoint returns accurate KPIs across all lifecycle states');

    console.log(`\nALL NODE SELLER DELETION TESTS PASSED (${passed} assertions).`);
  } finally {
    rzp.restore();
    try {
      const pool = app.__deletionTestContext && app.__deletionTestContext.getPool && app.__deletionTestContext.getPool();
      if (pool && typeof pool.end === 'function') {
        await Promise.race([pool.end(), new Promise((r) => setTimeout(r, 500))]);
      }
    } catch (e) {}
  }
}

runTests().then(() => {
  process.exit(0);
}).catch((err) => {
  console.error('\n[FAIL] Node Seller Deletion Test Suite Error:', err);
  process.exit(1);
});
