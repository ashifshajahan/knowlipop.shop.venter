const { PassThrough } = require('stream');
const http = require('http');
const fs = require('fs');
const path = require('path');
const jwt = require('jsonwebtoken');
const app = require('../server');

const JWT_SECRET = process.env.JWT_SECRET || 'vendor_jwt_secret_key_987654321';

// Generate standard tokens
const tokenSeller1 = jwt.sign({ id: 1, role: 'seller', phone: '+919876543210' }, JWT_SECRET, { expiresIn: '1d' });
const tokenSeller2 = jwt.sign({ id: 2, role: 'seller', phone: '+919633426254' }, JWT_SECRET, { expiresIn: '1d' });
const tokenSeller3 = jwt.sign({ id: 3, role: 'seller', phone: '+919876543212' }, JWT_SECRET, { expiresIn: '1d' });
const tokenAdmin = jwt.sign({ id: 1, role: 'admin', phone: '+919876543210' }, JWT_SECRET, { expiresIn: '1d' });

function dispatch(app, options) {
  return new Promise((resolve, reject) => {
    const { method = 'GET', url = '/', headers = {}, body = null } = options;

    const socket = new PassThrough();
    const req = new http.IncomingMessage(socket);
    req.method = method;
    req.url = url;
    req.headers = {
      'host': 'localhost',
      'user-agent': 'master-test-runner',
      ...headers
    };

    let payload = null;
    if (body !== null && body !== undefined) {
      payload = typeof body === 'string' ? body : JSON.stringify(body);
      req.headers['content-type'] = 'application/json';
      req.headers['content-length'] = String(Buffer.byteLength(payload));
    }

    const clientRes = new PassThrough();
    const res = new http.ServerResponse(req);
    res.assignSocket(clientRes);

    let rawOutput = '';
    clientRes.on('data', chunk => {
      rawOutput += chunk.toString();
    });

    res.on('finish', () => {
      const splitIdx = rawOutput.indexOf('\r\n\r\n');
      const bodyPart = splitIdx !== -1 ? rawOutput.substring(splitIdx + 4) : rawOutput;
      let parsed = bodyPart;
      try {
        parsed = JSON.parse(bodyPart);
      } catch (e) {}

      resolve({
        status: res.statusCode,
        body: parsed,
        raw: bodyPart
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

let passedCount = 0;
let totalCount = 0;

function assert(condition, message) {
  totalCount++;
  if (!condition) {
    console.error(`❌ FAILED: ${message}`);
    throw new Error(`Assertion failed: ${message}`);
  } else {
    passedCount++;
    console.log(`✅ [${passedCount}] PASSED: ${message}`);
  }
}

async function runMasterTestSuite() {
  console.log('\n=============================================================');
  console.log('🚀 MASTER API TEST SUITE: COMPLETE SYSTEM VERIFICATION');
  console.log('=============================================================');

  // -----------------------------------------------------------------
  // 1. SYSTEM & WEB HUBS
  // -----------------------------------------------------------------
  console.log('\n--- Category 1: System & Web Hubs ---');
  
  const rootRes = await dispatch(app, { method: 'GET', url: '/' });
  assert(rootRes.status === 200 && rootRes.raw.includes('Vendor App Backend'), 'GET / (Root status card returns 200 HTML)');

  const healthRes = await dispatch(app, { method: 'GET', url: '/api/health' });
  assert([200, 503].includes(healthRes.status) && healthRes.body.engine === 'Node.js (Express)', 'GET /api/health returns valid system diagnostic');

  assert(fs.existsSync(path.resolve(__dirname, '../test-buyer.html')), 'GET /test-buyer (Buyer Module Hub file validated)');
  assert(fs.existsSync(path.resolve(__dirname, '../test-firebase.html')), 'GET /test-firebase (Firebase Auth Hub file validated)');

  // -----------------------------------------------------------------
  // 2. AUTHENTICATION & SESSION
  // -----------------------------------------------------------------
  console.log('\n--- Category 2: Authentication & Session ---');

  const ts = Date.now();
  const testPhone = `+9198765${Math.floor(10000 + Math.random() * 90000)}`;
  const testEmail = `superelec_${ts}_${Math.floor(Math.random() * 1000)}@example.com`;

  const missingContactRes = await dispatch(app, {
    method: 'POST',
    url: '/api/register',
    body: {
      seller_name: 'Missing Contact Shop',
      address: 'Shop 102, Electronic City',
      password: 'Password123!',
      password_confirmation: 'Password123!'
    }
  });
  assert(
    missingContactRes.status === 422 && missingContactRes.body.errors && missingContactRes.body.errors.email_or_phone,
    'POST /api/register rejects missing email AND phone with 422'
  );

  const invalidIdentifierRegRes = await dispatch(app, {
    method: 'POST',
    url: '/api/register',
    body: {
      seller_name: 'Bad Identifier Shop',
      identifier: 'not-email-or-phone',
      address: 'Shop 102, Electronic City',
      password: 'Password123!',
      password_confirmation: 'Password123!'
    }
  });
  assert(
    invalidIdentifierRegRes.status === 422 && invalidIdentifierRegRes.body.errors && invalidIdentifierRegRes.body.errors.email_or_phone,
    'POST /api/register rejects invalid single identifier with 422'
  );

  // 2A. Email-only registration & login (phone omitted)
  const emailOnlyAddr = `emailonly_${ts}_${Math.floor(Math.random() * 1000)}@example.com`;
  const regEmailOnlyRes = await dispatch(app, {
    method: 'POST',
    url: '/api/register',
    body: {
      seller_name: 'Email Only Mart',
      email: emailOnlyAddr,
      address: '44 Web Lane',
      password: 'Password123!',
      password_confirmation: 'Password123!'
    }
  });
  assert(
    regEmailOnlyRes.status === 201 &&
      regEmailOnlyRes.body.token &&
      regEmailOnlyRes.body.user.email === emailOnlyAddr &&
      !regEmailOnlyRes.body.user.phone,
    'POST /api/register (email-only registration returns 201 with null phone & JWT token)'
  );

  const loginEmailOnlyRes = await dispatch(app, {
    method: 'POST',
    url: '/api/login',
    body: {
      email: emailOnlyAddr,
      password: 'Password123!'
    }
  });
  assert(
    loginEmailOnlyRes.status === 200 &&
      loginEmailOnlyRes.body.token &&
      loginEmailOnlyRes.body.user.email === emailOnlyAddr,
    'POST /api/login (email-only credentials return 200 with JWT token)'
  );

  const loginEmailIdentifierRes = await dispatch(app, {
    method: 'POST',
    url: '/api/login',
    body: {
      identifier: emailOnlyAddr,
      password: 'Password123!'
    }
  });
  assert(
    loginEmailIdentifierRes.status === 200 && loginEmailIdentifierRes.body.token,
    'POST /api/login (single identifier field with email returns 200)'
  );

  const meEmailOnlyRes = await dispatch(app, {
    method: 'GET',
    url: '/api/me',
    headers: { authorization: `Bearer ${regEmailOnlyRes.body.token}` }
  });
  assert(
    meEmailOnlyRes.status === 200 && meEmailOnlyRes.body.user && meEmailOnlyRes.body.user.email === emailOnlyAddr,
    'GET /api/me (email-only seller preserves email and omits phone)'
  );

  const dupEmailRes = await dispatch(app, {
    method: 'POST',
    url: '/api/register',
    body: {
      seller_name: 'Duplicate Email Mart',
      email: emailOnlyAddr,
      address: '45 Web Lane',
      password: 'Password123!'
    }
  });
  assert(
    dupEmailRes.status === 422 && dupEmailRes.body.errors && dupEmailRes.body.errors.email,
    'POST /api/register rejects duplicate email with 422'
  );

  // 2B. Phone-only registration & login (email omitted; synthetic email hidden)
  const phoneOnlyNum = `+9197654${Math.floor(10000 + Math.random() * 90000)}`;
  const regPhoneOnlyRes = await dispatch(app, {
    method: 'POST',
    url: '/api/register',
    body: {
      seller_name: 'Phone Only Mart',
      phone: phoneOnlyNum,
      address: '88 Mobile Street',
      password: 'Password123!',
      password_confirmation: 'Password123!'
    }
  });
  assert(
    regPhoneOnlyRes.status === 201 &&
      regPhoneOnlyRes.body.token &&
      regPhoneOnlyRes.body.user.phone === phoneOnlyNum &&
      regPhoneOnlyRes.body.user.email === null,
    'POST /api/register (phone-only registration returns 201 with null public email & JWT token)'
  );

  const loginPhoneOnlyRes = await dispatch(app, {
    method: 'POST',
    url: '/api/login',
    body: {
      phone: phoneOnlyNum,
      password: 'Password123!'
    }
  });
  assert(
    loginPhoneOnlyRes.status === 200 &&
      loginPhoneOnlyRes.body.token &&
      loginPhoneOnlyRes.body.user.phone === phoneOnlyNum &&
      loginPhoneOnlyRes.body.user.email === null,
    'POST /api/login (phone-only credentials return 200 without leaking synthetic email)'
  );

  const loginPhoneSingleFieldRes = await dispatch(app, {
    method: 'POST',
    url: '/api/login',
    body: {
      login: phoneOnlyNum.slice(-10),
      password: 'Password123!'
    }
  });
  assert(
    loginPhoneSingleFieldRes.status === 200 && loginPhoneSingleFieldRes.body.token,
    'POST /api/login (single login field with 10-digit phone returns 200)'
  );

  // 2C. Both email & phone registration + login permutations
  const regRes = await dispatch(app, {
    method: 'POST',
    url: '/api/register',
    body: {
      seller_name: 'Super Electronics',
      email: testEmail,
      phone: testPhone,
      address: 'Shop 102, Electronic City',
      landmark: 'Near Metro',
      state: 'Karnataka',
      country: 'India',
      password: 'Password123!',
      password_confirmation: 'Password123!'
    }
  });
  assert(
    regRes.status === 201 &&
      regRes.body.token &&
      regRes.body.user.email === testEmail &&
      regRes.body.user.phone === testPhone,
    'POST /api/register (Seller registration with both email & phone returns 201 & JWT token)'
  );

  const loginMismatchRes = await dispatch(app, {
    method: 'POST',
    url: '/api/login',
    body: {
      email: testEmail,
      phone: '+919999900001',
      password: 'Password123!'
    }
  });
  assert(loginMismatchRes.status === 422, 'POST /api/login rejects mismatched email + wrong phone with 422');

  const loginWrongPassRes = await dispatch(app, {
    method: 'POST',
    url: '/api/login',
    body: {
      email: testEmail,
      password: 'WrongPassword1!'
    }
  });
  assert(loginWrongPassRes.status === 422, 'POST /api/login rejects incorrect password with 422');

  const loginMissingContactRes = await dispatch(app, {
    method: 'POST',
    url: '/api/login',
    body: {
      password: 'Password123!'
    }
  });
  assert(
    loginMissingContactRes.status === 422 && loginMissingContactRes.body.errors && loginMissingContactRes.body.errors.email_or_phone,
    'POST /api/login rejects missing email and phone with 422'
  );

  const loginRes = await dispatch(app, {
    method: 'POST',
    url: '/api/login',
    body: {
      email: testEmail,
      phone: testPhone,
      password: 'Password123!'
    }
  });
  assert(loginRes.status === 200 && loginRes.body.token, 'POST /api/login (Seller login with matching email + phone returns 200 with JWT token)');
  const dynamicToken = loginRes.body.token;

  const meRes = await dispatch(app, {
    method: 'GET',
    url: '/api/me',
    headers: { authorization: `Bearer ${dynamicToken}` }
  });
  assert(meRes.status === 200 && meRes.body.seller, 'GET /api/me (Current authenticated profile returns 200)');

  const updateMeRes = await dispatch(app, {
    method: 'PUT',
    url: '/api/me',
    headers: { authorization: `Bearer ${dynamicToken}` },
    body: { address: 'Updated Shop 102, New Phase' }
  });
  assert(updateMeRes.status === 200, 'PUT /api/me (Update profile details returns 200)');

  const logoutRes = await dispatch(app, {
    method: 'POST',
    url: '/api/logout',
    headers: { authorization: `Bearer ${dynamicToken}` }
  });
  assert(logoutRes.status === 200 && logoutRes.body.message.includes('Logout successful'), 'POST /api/logout returns 200');

  // -----------------------------------------------------------------
  // 3. SELLER PROFILE & DIRECTORY
  // -----------------------------------------------------------------
  console.log('\n--- Category 3: Seller Profile & Directory ---');

  const profileTokenRes = await dispatch(app, {
    method: 'GET',
    url: '/api/seller/profile',
    headers: { authorization: `Bearer ${tokenSeller1}` }
  });
  assert(profileTokenRes.status === 200 && profileTokenRes.body.seller.id, 'GET /api/seller/profile (via Bearer token returns 200)');

  const profileIdRes = await dispatch(app, {
    method: 'GET',
    url: '/api/seller/profile?seller_id=1'
  });
  assert(profileIdRes.status === 200 && profileIdRes.body.seller.id === 1, 'GET /api/seller/profile?seller_id=1 returns 200');

  const updateProfilePostRes = await dispatch(app, {
    method: 'POST',
    url: '/api/seller/profile/update',
    headers: { authorization: `Bearer ${tokenSeller1}` },
    body: { landmark: 'Updated Gate 1' }
  });
  assert(updateProfilePostRes.status === 200, 'POST /api/seller/profile/update alias returns 200');

  const sellersListRes = await dispatch(app, { method: 'GET', url: '/api/sellers?page=1&limit=10' });
  assert(sellersListRes.status === 200 && sellersListRes.body.sellers.data.length > 0, 'GET /api/sellers (Public marketplace directory returns 200)');

  const sellerDetailRes = await dispatch(app, { method: 'GET', url: '/api/sellers/1' });
  assert(sellerDetailRes.status === 200 && sellerDetailRes.body.seller.id === 1, 'GET /api/sellers/:id returns 200 with seller data');

  // -----------------------------------------------------------------
  // 4. PRODUCTS CATALOG & CRUD
  // -----------------------------------------------------------------
  console.log('\n--- Category 4: Products Catalog & CRUD ---');

  const productsListRes = await dispatch(app, { method: 'GET', url: '/api/products' });
  assert(productsListRes.status === 200 && productsListRes.body.products.data.length > 0, 'GET /api/products returns public catalog with pagination');

  const product1Res = await dispatch(app, { method: 'GET', url: '/api/products/1' });
  assert(product1Res.status === 200 && product1Res.body.product.id === 1, 'GET /api/products/:id returns product details');

  const sellerProductsRes = await dispatch(app, {
    method: 'GET',
    url: '/api/seller/products',
    headers: { authorization: `Bearer ${tokenSeller1}` }
  });
  assert(sellerProductsRes.status === 200 && sellerProductsRes.body.products, 'GET /api/seller/products returns seller-specific catalog');

  const sellerProductsByIdRes = await dispatch(app, {
    method: 'GET',
    url: '/api/sellers/1/products',
    headers: { authorization: `Bearer ${tokenSeller1}` }
  });
  assert(sellerProductsByIdRes.status === 200, 'GET /api/sellers/:seller_id/products returns 200');

  const createProdRes = await dispatch(app, {
    method: 'POST',
    url: '/api/products',
    headers: { authorization: `Bearer ${tokenSeller1}` },
    body: {
      name: 'Wireless Mechanical Numpad',
      description: 'Hot-swappable tactile switches with RGB',
      price: 1299.00,
      stock: 30
    }
  });
  assert(createProdRes.status === 201 && createProdRes.body.product.id, 'POST /api/products (Create product returns 201)');
  const newProdId = createProdRes.body.product.id;

  const updateProdRes = await dispatch(app, {
    method: 'PUT',
    url: `/api/products/${newProdId}`,
    headers: { authorization: `Bearer ${tokenSeller1}` },
    body: { price: 1399.00 }
  });
  assert(updateProdRes.status === 200, 'PUT /api/products/:id (Update product returns 200)');

  const deleteProdRes = await dispatch(app, {
    method: 'DELETE',
    url: `/api/products/${newProdId}`,
    headers: { authorization: `Bearer ${tokenSeller1}` }
  });
  assert(deleteProdRes.status === 200, 'DELETE /api/products/:id (Delete product returns 200)');

  // -----------------------------------------------------------------
  // 5. PASSWORD RESET FLOW (OTP GATEWAY)
  // -----------------------------------------------------------------
  console.log('\n--- Category 5: Password Reset Flow (OTP Gateway) ---');

  const forgotRes = await dispatch(app, {
    method: 'POST',
    url: '/api/forgot-password',
    body: { phone: '+919633426254' }
  });
  assert(forgotRes.status === 200 && forgotRes.body.message, 'POST /api/forgot-password returns 200 with dispatch confirmation');

  const verifyOtpRes = await dispatch(app, {
    method: 'POST',
    url: '/api/verify-otp',
    body: {
      phone: '+919633426254',
      code: '000000'
    }
  });
  assert(verifyOtpRes.status === 200 && verifyOtpRes.body.reset_token, 'POST /api/verify-otp with Firebase test code 000000 returns reset_token');
  const resetToken = verifyOtpRes.body.reset_token;

  const resetPassRes = await dispatch(app, {
    method: 'POST',
    url: '/api/reset-password',
    body: {
      phone: '+919633426254',
      reset_token: resetToken,
      password: 'NewSecurePassword123!',
      password_confirmation: 'NewSecurePassword123!'
    }
  });
  assert(resetPassRes.status === 200, 'POST /api/reset-password with valid token returns 200');

  // -----------------------------------------------------------------
  // 6. ORDERS, MULTI-VENDOR ROUTING & SELLER ISOLATION
  // -----------------------------------------------------------------
  console.log('\n--- Category 6: Orders & Multi-Vendor Routing ---');

  const orderRes = await dispatch(app, {
    method: 'POST',
    url: '/api/orders',
    body: {
      buyer_name: 'Master Test Buyer',
      buyer_phone: '+919876543210',
      shipping_address: 'Penthouse 12, Skyline Avenue',
      city: 'Bangalore',
      state: 'Karnataka',
      pincode: '560001',
      items: [
        { product_id: 1, product_name: 'Smart Wireless Headset Pro', price: 2999.00, quantity: 1 },
        { product_id: 7, product_name: 'Noise-Cancelling Studio Earbuds', price: 1899.00, quantity: 2 }
      ]
    }
  });
  assert(orderRes.status === 201 && orderRes.body.order.id, 'POST /api/orders (Place multi-vendor order returns 201)');
  const orderId = orderRes.body.order.id;

  // Verify multi-vendor auto-routing
  const items = orderRes.body.order.items;
  assert(Number(items[0].seller_id) === 1 && Number(items[1].seller_id) === 2, 'Line items auto-routed based on product seller_id (Item 1 -> Seller 1, Item 2 -> Seller 2)');

  const ordersListRes = await dispatch(app, { method: 'GET', url: '/api/orders?page=1&limit=20' });
  assert(ordersListRes.status === 200 && ordersListRes.body.orders, 'GET /api/orders returns orders list');

  const orderDetailRes = await dispatch(app, { method: 'GET', url: `/api/orders/${orderId}` });
  assert(orderDetailRes.status === 200 && orderDetailRes.body.order.id === orderId, 'GET /api/orders/:id returns order details');

  // Seller 1 isolation
  const s1OrdersRes = await dispatch(app, {
    method: 'GET',
    url: '/api/seller/orders',
    headers: { authorization: `Bearer ${tokenSeller1}` }
  });
  assert(s1OrdersRes.status === 200, 'GET /api/seller/orders (Seller 1 retrieves orders list)');
  const s1Found = s1OrdersRes.body.orders.data.find(o => o.id === orderId);
  assert(s1Found && s1Found.items.length === 1 && Number(s1Found.items[0].product_id) === 1, 'Seller 1 sees ONLY their product in multi-vendor order');
  assert(s1Found.seller_subtotal === 2999, 'Seller 1 order subtotal dynamically calculated for Seller 1 products only');

  // Seller 2 isolation
  const s2OrdersRes = await dispatch(app, {
    method: 'GET',
    url: '/api/sellers/2/orders',
    headers: { authorization: `Bearer ${tokenSeller2}` }
  });
  assert(s2OrdersRes.status === 200, 'GET /api/sellers/:seller_id/orders (Seller 2 retrieves orders list)');
  const s2Found = s2OrdersRes.body.orders.data.find(o => o.id === orderId);
  assert(s2Found && s2Found.items.length === 1 && Number(s2Found.items[0].product_id) === 7, 'Seller 2 sees ONLY their product in multi-vendor order');
  assert(s2Found.seller_subtotal === 3798, 'Seller 2 order subtotal dynamically calculated for Seller 2 products only (1899 x 2 = 3798)');

  // Seller single order detail
  const s1DetailRes = await dispatch(app, {
    method: 'GET',
    url: `/api/seller/orders/${orderId}`,
    headers: { authorization: `Bearer ${tokenSeller1}` }
  });
  assert(s1DetailRes.status === 200 && s1DetailRes.body.order.items.length === 1, 'GET /api/seller/orders/:id returns isolated order details');

  // Update order
  const updateOrderRes = await dispatch(app, {
    method: 'PUT',
    url: `/api/orders/${orderId}`,
    headers: { authorization: `Bearer ${tokenAdmin}` },
    body: { order_status: 'confirmed' }
  });
  assert(updateOrderRes.status === 200, 'PUT /api/orders/:id (Update order status returns 200)');

  // -----------------------------------------------------------------
  // 7. TAX INVOICE GENERATION
  // -----------------------------------------------------------------
  console.log('\n--- Category 7: Tax Invoice Hub ---');

  const invoiceHtmlRes = await dispatch(app, { method: 'GET', url: `/invoice/${orderId}` });
  assert(invoiceHtmlRes.status === 200 && invoiceHtmlRes.raw.includes('TAX INVOICE'), 'GET /invoice/:id generates printable HTML Provider Tax Invoice');

  const invoiceJsonRes = await dispatch(app, { method: 'GET', url: `/api/orders/${orderId}/invoice?format=json` });
  assert(invoiceJsonRes.status === 200 && invoiceJsonRes.body.invoice, 'GET /api/orders/:id/invoice?format=json returns complete invoice breakdown');

  // -----------------------------------------------------------------
  // 8. SELLER BANKING & PAYOUTS
  // -----------------------------------------------------------------
  console.log('\n--- Category 8: Seller Banking & Payouts ---');

  const bankOnboardRes = await dispatch(app, {
    method: 'POST',
    url: '/api/seller/bank-account/onboarding',
    headers: { authorization: `Bearer ${tokenSeller1}` },
    body: {
      account_reference: 'ACC-998877665544',
      ifsc: 'HDFC0001234',
      account_holder: 'Super Electronics'
    }
  });
  assert(bankOnboardRes.status === 200, 'POST /api/seller/bank-account/onboarding stores payment account reference');

  const bankStatusRes = await dispatch(app, {
    method: 'GET',
    url: '/api/seller/bank-account/status',
    headers: { authorization: `Bearer ${tokenSeller1}` }
  });
  assert(bankStatusRes.status === 200, 'GET /api/seller/bank-account/status returns verification status');

  const commissionRes = await dispatch(app, {
    method: 'POST',
    url: '/api/marketplace/commission-preview',
    headers: { authorization: `Bearer ${tokenSeller1}` },
    body: { amount: 5000.00 }
  });
  assert(commissionRes.status === 200 && commissionRes.body.net_payout === 4750, 'POST /api/marketplace/commission-preview calculates platform fee and net payout (5% fee)');

  const payoutsRes = await dispatch(app, {
    method: 'GET',
    url: '/api/seller/payouts',
    headers: { authorization: `Bearer ${tokenSeller1}` }
  });
  assert(payoutsRes.status === 200 && Array.isArray(payoutsRes.body.payouts), 'GET /api/seller/payouts returns payout records');

  // -----------------------------------------------------------------
  // 9. SECURITY & CROSS-SELLER ACCESS AUTHORIZATION (401 / 403)
  // -----------------------------------------------------------------
  console.log('\n--- Category 9: Strict Security & Authorization (401 / 403) ---');

  const unauthOrdersRes = await dispatch(app, { method: 'GET', url: '/api/seller/orders' });
  assert(unauthOrdersRes.status === 401, 'Protected endpoint without token returns 401 Unauthorized');

  const crossSellerOrdersRes = await dispatch(app, {
    method: 'GET',
    url: '/api/sellers/2/orders',
    headers: { authorization: `Bearer ${tokenSeller1}` }
  });
  assert(crossSellerOrdersRes.status === 403, 'Cross-seller orders breach (/api/sellers/2/orders by Seller 1) blocked with 403 Forbidden');

  const s3OrderDetailRes = await dispatch(app, {
    method: 'GET',
    url: `/api/seller/orders/${orderId}`,
    headers: { authorization: `Bearer ${tokenSeller3}` }
  });
  assert(s3OrderDetailRes.status === 403, 'Seller 3 fetching order where they own 0 items blocked with 403 Forbidden');

  const crossSellerProductsRes = await dispatch(app, {
    method: 'GET',
    url: '/api/sellers/2/products',
    headers: { authorization: `Bearer ${tokenSeller1}` }
  });
  assert(crossSellerProductsRes.status === 403, 'Cross-seller catalog breach (/api/sellers/2/products by Seller 1) blocked with 403 Forbidden');

  const crossProductEditRes = await dispatch(app, {
    method: 'PUT',
    url: '/api/products/7',
    headers: { authorization: `Bearer ${tokenSeller1}` },
    body: { name: 'Hacked Title' }
  });
  assert(crossProductEditRes.status === 403, 'Cross-seller product edit (Seller 1 editing Seller 2 product) blocked with 403 Forbidden');

  const crossProductDeleteRes = await dispatch(app, {
    method: 'DELETE',
    url: '/api/products/7',
    headers: { authorization: `Bearer ${tokenSeller1}` }
  });
  assert(crossProductDeleteRes.status === 403, 'Cross-seller product delete (Seller 1 deleting Seller 2 product) blocked with 403 Forbidden');

  // Delete test order
  const delOrderRes = await dispatch(app, {
    method: 'DELETE',
    url: `/api/orders/${orderId}`,
    headers: { authorization: `Bearer ${tokenAdmin}` }
  });
  assert(delOrderRes.status === 200, 'DELETE /api/orders/:id (Admin cancels order returns 200)');

  console.log('\n=============================================================');
  console.log(`🎉 ALL ${passedCount} OF ${totalCount} API TESTS PASSED SUCCESSFULLY!`);
  console.log('=============================================================\n');
}

runMasterTestSuite().catch(err => {
  console.error('\n❌ MASTER API TEST SUITE ENCOUNTERED ERROR:', err);
  process.exit(1);
});
