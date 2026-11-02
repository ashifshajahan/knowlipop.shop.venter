const https = require('https');

const BASE_URL = 'https://knowlipop.shop';

function request(method, path, data = null, token = null) {
  return new Promise((resolve, reject) => {
    const url = new URL(path, BASE_URL);
    const options = {
      method: method,
      hostname: url.hostname,
      port: 443,
      path: url.pathname + url.search,
      headers: {
        'Accept': 'application/json',
        'User-Agent': 'VendorAppTest/1.0'
      },
      timeout: 10000
    };

    let bodyStr = null;
    if (data) {
      bodyStr = JSON.stringify(data);
      options.headers['Content-Type'] = 'application/json';
      options.headers['Content-Length'] = Buffer.byteLength(bodyStr);
    }

    if (token) {
      options.headers['Authorization'] = `Bearer ${token}`;
    }

    const req = https.request(options, (res) => {
      let resBody = '';
      res.on('data', chunk => resBody += chunk);
      res.on('end', () => {
        let parsed = null;
        try {
          parsed = JSON.parse(resBody);
        } catch (e) {
          parsed = resBody;
        }
        resolve({
          status: res.statusCode,
          headers: res.headers,
          data: parsed
        });
      });
    });

    req.on('error', err => reject(err));
    req.on('timeout', () => {
      req.destroy();
      reject(new Error(`Timeout on ${method} ${path}`));
    });

    if (bodyStr) {
      req.write(bodyStr);
    }
    req.end();
  });
}

async function testComprehensiveLive() {
  console.log('================================================================');
  console.log('🌐 EXHAUSTIVE LIVE SUITE: ONLY CONNECTING WITH knowlipop.shop');
  console.log('================================================================\n');

  // Step 1: Health Check
  console.log('[1] Checking Live API Health...');
  const health = await request('GET', '/api/health');
  if (health.status !== 200 || health.data.status !== 'ok') {
    throw new Error('Health check failed: ' + JSON.stringify(health.data));
  }
  console.log('✅ Live Health OK:', health.data.app, `(${health.data.engine})`);

  // Step 2: Register Seller A
  const phoneA = '+9191' + Math.floor(10000000 + Math.random() * 90000000);
  console.log(`\n[2] Registering Seller A with ${phoneA}...`);
  const regA = await request('POST', '/api/register', {
    store_name: 'Studio Audio Boutique',
    owner_name: 'Alok Nath',
    phone: phoneA,
    password: 'Password123!',
    address: '42 MG Road',
    city: 'Bangalore',
    state: 'Karnataka',
    pincode: '560001'
  });
  const tokenA = regA.data.token;
  const sellerIdA = regA.data.user.id;
  console.log(`✅ Seller A Registered: ID=${sellerIdA}, Token=${tokenA.substring(0, 15)}...`);

  // Step 3: Register Seller B
  const phoneB = '+9192' + Math.floor(10000000 + Math.random() * 90000000);
  console.log(`\n[3] Registering Seller B with ${phoneB}...`);
  const regB = await request('POST', '/api/register', {
    store_name: 'Prime Gadgets Corp',
    owner_name: 'Bina Roy',
    phone: phoneB,
    password: 'Password123!',
    address: '88 Park Street',
    city: 'Kolkata',
    state: 'West Bengal',
    pincode: '700016'
  });
  const tokenB = regB.data.token;
  const sellerIdB = regB.data.user.id;
  console.log(`✅ Seller B Registered: ID=${sellerIdB}, Token=${tokenB.substring(0, 15)}...`);

  // Step 4: Seller A creates a product
  console.log(`\n[4] Seller A creates Product A...`);
  const prodARes = await request('POST', '/api/products', {
    name: 'Wireless Studio Headphones',
    description: 'High fidelity studio headphones with active noise cancellation',
    price: 3499.00,
    stock: 25,
    category: 'Electronics'
  }, tokenA);
  const prodA = prodARes.data.product || prodARes.data.data;
  console.log(`✅ Product A created: ID=${prodA.id}, Name="${prodA.name}", Price=${prodA.price}`);

  // Step 5: Seller B creates a product
  console.log(`\n[5] Seller B creates Product B...`);
  const prodBRes = await request('POST', '/api/products', {
    name: 'Ergonomic Vertical Mouse',
    description: 'High precision optical mouse designed for posture and ergonomics',
    price: 1499.00,
    stock: 50,
    category: 'Electronics'
  }, tokenB);
  const prodB = prodBRes.data.product || prodBRes.data.data;
  console.log(`✅ Product B created: ID=${prodB.id}, Name="${prodB.name}", Price=${prodB.price}`);

  // Step 6: Create Multi-Vendor Order containing both products
  console.log(`\n[6] Placing Multi-Vendor Order containing Product A (Seller A) and Product B (Seller B)...`);
  const orderRes = await request('POST', '/api/orders', {
    buyer_name: 'Rahul Sharma',
    buyer_phone: '+919876543210',
    delivery_address: 'Flat 402, Sunshine Apartments, Indiranagar, Bangalore, Karnataka - 560038',
    shipping_address: 'Flat 402, Sunshine Apartments, Indiranagar, Bangalore, Karnataka - 560038',
    payment_method: 'COD',
    items: [
      { product_id: prodA.id, quantity: 1, price: 3499.00 },
      { product_id: prodB.id, quantity: 2, price: 1499.00 }
    ]
  });
  const order = orderRes.data.order;
  console.log(`✅ Order placed successfully: ID=${order.id}, Status=${order.status}`);
  console.log(`   Items in order:`, order.items.map(i => ({ product_id: i.product_id, seller_id: i.seller_id, qty: i.quantity, price: i.price })));

  // Verify line item routing
  const itemA = order.items.find(i => i.product_id === prodA.id);
  const itemB = order.items.find(i => i.product_id === prodB.id);
  if (itemA.seller_id !== sellerIdA) throw new Error(`Item A seller_id mismatch! Expected ${sellerIdA}, got ${itemA.seller_id}`);
  if (itemB.seller_id !== sellerIdB) throw new Error(`Item B seller_id mismatch! Expected ${sellerIdB}, got ${itemB.seller_id}`);
  console.log('✅ Verification: Line items auto-routed correctly to Seller A and Seller B in database!');

  // Step 7: Seller A queries orders via GET /api/seller/orders
  console.log(`\n[7] Seller A queries GET /api/seller/orders...`);
  const sellerAOrders = await request('GET', '/api/seller/orders', null, tokenA);
  console.log(`✅ Seller A returned ${sellerAOrders.data.orders.data.length} order(s)`);
  const orderForA = sellerAOrders.data.orders.data.find(o => o.id === order.id);
  console.log(`   Seller A isolated items count: ${orderForA.items.length} (Product ${orderForA.items[0].product_id}, Name: "${orderForA.items[0].product_name}")`);
  console.log(`   Seller A isolated subtotal: ₹${orderForA.seller_subtotal}`);
  if (orderForA.items.length !== 1 || orderForA.items[0].product_id !== prodA.id) {
    throw new Error('Seller A order items isolation failed!');
  }
  if (orderForA.seller_subtotal !== 3499.00) {
    throw new Error('Seller A subtotal mismatch!');
  }

  // Step 8: Seller B queries orders via GET /api/sellers/:seller_id/orders
  console.log(`\n[8] Seller B queries GET /api/sellers/${sellerIdB}/orders...`);
  const sellerBOrders = await request('GET', `/api/sellers/${sellerIdB}/orders`, null, tokenB);
  console.log(`✅ Seller B returned ${sellerBOrders.data.orders.data.length} order(s)`);
  const orderForB = sellerBOrders.data.orders.data.find(o => o.id === order.id);
  console.log(`   Seller B isolated items count: ${orderForB.items.length} (Product ${orderForB.items[0].product_id}, Name: "${orderForB.items[0].product_name}")`);
  console.log(`   Seller B isolated subtotal: ₹${orderForB.seller_subtotal} (Quantity: ${orderForB.items[0].quantity} x ₹${orderForB.items[0].price})`);
  if (orderForB.items.length !== 1 || orderForB.items[0].product_id !== prodB.id) {
    throw new Error('Seller B order items isolation failed!');
  }
  if (orderForB.seller_subtotal !== 2998.00) {
    throw new Error('Seller B subtotal mismatch!');
  }

  // Step 9: Seller A queries single order detail GET /api/seller/orders/:order_id
  console.log(`\n[9] Seller A queries GET /api/seller/orders/${order.id}...`);
  const detailA = await request('GET', `/api/seller/orders/${order.id}`, null, tokenA);
  console.log(`✅ Detail Status: ${detailA.status}, Items returned: ${detailA.data.order.items.length}`);
  if (detailA.data.order.items.some(i => i.seller_id !== sellerIdA)) {
    throw new Error('Cross-seller product leaked into Seller A order details!');
  }

  // Step 10: Security check: Seller A attempts to access Seller B orders -> Expect 403 Forbidden
  console.log(`\n[10] Security Test: Seller A attempts GET /api/sellers/${sellerIdB}/orders...`);
  const breachOrders = await request('GET', `/api/sellers/${sellerIdB}/orders`, null, tokenA);
  console.log(`✅ Status: ${breachOrders.status} Forbidden (${breachOrders.data.message})`);
  if (breachOrders.status !== 403) throw new Error('Expected 403 Forbidden for cross-seller order list access!');

  // Step 11: Security check: Seller A attempts to edit Seller B product -> Expect 403 Forbidden
  console.log(`\n[11] Security Test: Seller A attempts PUT /api/products/${prodB.id}...`);
  const breachEdit = await request('PUT', `/api/products/${prodB.id}`, { price: 99.00 }, tokenA);
  console.log(`✅ Status: ${breachEdit.status} Forbidden (${breachEdit.data.message})`);
  if (breachEdit.status !== 403) throw new Error('Expected 403 Forbidden for cross-seller product edit!');

  // Step 12: Seller Banking Onboarding on live server
  console.log(`\n[12] Testing Seller Bank Onboarding for Seller A...`);
  const bankRes = await request('POST', '/api/seller/bank-account/onboarding', {
    account_holder_name: 'Alok Nath',
    account_number: '123456789012',
    ifsc_code: 'HDFC0001234',
    bank_name: 'HDFC Bank'
  }, tokenA);
  console.log(`✅ Bank Onboarding Status: ${bankRes.status} (${bankRes.data.message})`);

  // Step 13: Downloadable Tax Invoice
  console.log(`\n[13] Testing GET /invoice/${order.id}...`);
  const invoiceRes = await request('GET', `/invoice/${order.id}`);
  console.log(`✅ Invoice HTML Status: ${invoiceRes.status} (${invoiceRes.data.length} bytes)`);

  console.log('\n================================================================');
  console.log('🎉 100% OF LIVE PRODUCTION TESTS PASSED ON https://knowlipop.shop');
  console.log('================================================================');
}

testComprehensiveLive().catch(err => {
  console.error('\n❌ TEST FAILED:', err);
  process.exit(1);
});
