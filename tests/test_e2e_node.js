const { PassThrough } = require('stream');
const http = require('http');
const jwt = require('jsonwebtoken');
const app = require('../server');

const JWT_SECRET = process.env.JWT_SECRET || 'vendor_jwt_secret_key_987654321';

// Generate test JWT tokens
const tokenSeller1 = jwt.sign({ id: 1, role: 'seller', phone: '9876543210' }, JWT_SECRET, { expiresIn: '1d' });
const tokenSeller2 = jwt.sign({ id: 2, role: 'seller', phone: '9876543211' }, JWT_SECRET, { expiresIn: '1d' });
const tokenSeller3 = jwt.sign({ id: 3, role: 'seller', phone: '9876543212' }, JWT_SECRET, { expiresIn: '1d' });

function dispatch(app, options) {
  return new Promise((resolve, reject) => {
    const { method = 'GET', url = '/', headers = {}, body = null } = options;

    const socket = new PassThrough();
    const req = new http.IncomingMessage(socket);
    req.method = method;
    req.url = url;
    req.headers = {
      'host': 'localhost',
      'user-agent': 'test-runner',
      ...headers
    };

    let payload = null;
    if (body) {
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
      // Extract HTTP body from raw output
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

function assert(condition, message) {
  if (!condition) {
    console.error(`❌ FAILED: ${message}`);
    throw new Error(`Assertion failed: ${message}`);
  } else {
    console.log(`✅ PASSED: ${message}`);
  }
}

async function run() {
  console.log('\n===========================================');
  console.log('🧪 RUNNING NODE.JS REST API TEST SUITE');
  console.log('===========================================');

  console.log('\n--- 1. Authentication & Security Check ---');
  const unauthRes = await dispatch(app, { method: 'GET', url: '/api/seller/orders' });
  assert(unauthRes.status === 401, `Unauthenticated /api/seller/orders returns 401 (got ${unauthRes.status})`);

  console.log('\n--- 2. Multi-Vendor Order Creation & Auto-Routing ---');
  const orderPayload = {
    buyer_name: 'Jane Doe',
    buyer_phone: '9876500000',
    shipping_address: '100 Silicon Blvd, Suite 400',
    landmark: 'Near Central Park',
    city: 'Metropolis',
    state: 'Tech State',
    pincode: '560001',
    items: [
      {
        product_id: 1, // Seller 1 product
        product_name: 'Smart Wireless Headset Pro',
        price: 2999.00,
        quantity: 2
      },
      {
        product_id: 7, // Seller 2 product
        product_name: 'Noise-Cancelling Studio Earbuds',
        price: 1899.00,
        quantity: 1
      }
    ]
  };

  const createRes = await dispatch(app, {
    method: 'POST',
    url: '/api/orders',
    body: orderPayload
  });
  assert(createRes.status === 201 || createRes.status === 200, `Order creation succeeded with status ${createRes.status}`);
  const createdOrder = createRes.body.order;
  assert(createdOrder && createdOrder.id, 'Order created with valid ID');
  const orderId = createdOrder.id;
  console.log(`Created Order ID: ${orderId}, order_number: ${createdOrder.order_number}`);

  const createdItems = createdOrder.items || [];
  assert(createdItems.length === 2, `Order contains 2 items`);
  const item1 = createdItems.find(i => Number(i.product_id) === 1);
  const item2 = createdItems.find(i => Number(i.product_id) === 7);
  assert(item1 && Number(item1.seller_id) === 1, 'Item 1 auto-routed to seller_id 1');
  assert(item2 && Number(item2.seller_id) === 2, 'Item 2 auto-routed to seller_id 2');

  console.log('\n--- 3. Seller 1 Order Isolation (GET /api/seller/orders) ---');
  const seller1OrdersRes = await dispatch(app, {
    method: 'GET',
    url: '/api/seller/orders',
    headers: { 'authorization': `Bearer ${tokenSeller1}` }
  });
  assert(seller1OrdersRes.status === 200, `Seller 1 orders returned 200 (got ${seller1OrdersRes.status})`);
  const seller1Orders = seller1OrdersRes.body.orders.data;
  const s1Order = seller1Orders.find(o => Number(o.id) === Number(orderId));
  assert(s1Order !== undefined, `Order #${orderId} is visible in Seller 1's list`);
  assert(s1Order.items.length === 1, `Seller 1 sees exactly 1 item in multi-vendor order (got ${s1Order.items.length})`);
  assert(Number(s1Order.items[0].product_id) === 1, 'Seller 1 sees only their product (ID 1)');
  assert(Number(s1Order.items[0].seller_id) === 1, 'Item seller_id is 1');
  assert(s1Order.seller_subtotal === 5998 || s1Order.subtotal === 5998, `Seller 1 subtotal is 5998.00 (got ${s1Order.subtotal})`);
  assert(s1Order.total_quantity === 2, `Seller 1 total_quantity is 2 (got ${s1Order.total_quantity})`);

  console.log('\n--- 4. Seller 2 Order Isolation (GET /api/seller/orders) ---');
  const seller2OrdersRes = await dispatch(app, {
    method: 'GET',
    url: '/api/seller/orders',
    headers: { 'authorization': `Bearer ${tokenSeller2}` }
  });
  assert(seller2OrdersRes.status === 200, `Seller 2 orders returned 200 (got ${seller2OrdersRes.status})`);
  const seller2Orders = seller2OrdersRes.body.orders.data;
  const s2Order = seller2Orders.find(o => Number(o.id) === Number(orderId));
  assert(s2Order !== undefined, `Order #${orderId} is visible in Seller 2's list`);
  assert(s2Order.items.length === 1, `Seller 2 sees exactly 1 item in multi-vendor order (got ${s2Order.items.length})`);
  assert(Number(s2Order.items[0].product_id) === 7, 'Seller 2 sees only their product (ID 7)');
  assert(Number(s2Order.items[0].seller_id) === 2, 'Item seller_id is 2');
  assert(s2Order.seller_subtotal === 1899 || s2Order.subtotal === 1899, `Seller 2 subtotal is 1899.00 (got ${s2Order.subtotal})`);
  assert(s2Order.total_quantity === 1, `Seller 2 total_quantity is 1 (got ${s2Order.total_quantity})`);

  console.log('\n--- 5. Single Order Retrieval & Product Filtering (/api/seller/orders/:id) ---');
  const s1DetailRes = await dispatch(app, {
    method: 'GET',
    url: `/api/seller/orders/${orderId}`,
    headers: { 'authorization': `Bearer ${tokenSeller1}` }
  });
  assert(s1DetailRes.status === 200, `Seller 1 fetching order detail returned 200`);
  assert(s1DetailRes.body.order.items.length === 1, 'Seller 1 detail items length is 1');
  assert(Number(s1DetailRes.body.order.items[0].product_id) === 1, 'Seller 1 detail contains product 1');
  assert(s1DetailRes.body.order.seller_subtotal === 5998 || s1DetailRes.body.order.subtotal === 5998, 'Seller 1 detail subtotal is 5998');

  const s2DetailRes = await dispatch(app, {
    method: 'GET',
    url: `/api/seller/orders/${orderId}`,
    headers: { 'authorization': `Bearer ${tokenSeller2}` }
  });
  assert(s2DetailRes.status === 200, `Seller 2 fetching order detail returned 200`);
  assert(s2DetailRes.body.order.items.length === 1, 'Seller 2 detail items length is 1');
  assert(Number(s2DetailRes.body.order.items[0].product_id) === 7, 'Seller 2 detail contains product 7');
  assert(s2DetailRes.body.order.seller_subtotal === 1899 || s2DetailRes.body.order.subtotal === 1899, 'Seller 2 detail subtotal is 1899');

  console.log('\n--- 6. Cross-Seller Access Denial (403 Forbidden) ---');
  // Seller 3 has NO products in this order
  const s3DetailRes = await dispatch(app, {
    method: 'GET',
    url: `/api/seller/orders/${orderId}`,
    headers: { 'authorization': `Bearer ${tokenSeller3}` }
  });
  assert(s3DetailRes.status === 403, `Seller 3 accessing order detail is blocked with 403 (got ${s3DetailRes.status})`);
  console.log(`403 message: ${s3DetailRes.body.message}`);

  // Seller 1 attempting to access Seller 2's orders route
  const crossSellerOrdersRes = await dispatch(app, {
    method: 'GET',
    url: `/api/sellers/2/orders`,
    headers: { 'authorization': `Bearer ${tokenSeller1}` }
  });
  assert(crossSellerOrdersRes.status === 403, `Seller 1 accessing /api/sellers/2/orders is blocked with 403 (got ${crossSellerOrdersRes.status})`);
  console.log(`403 message: ${crossSellerOrdersRes.body.message}`);

  console.log('\n--- 7. Seller Catalog & Cross-Seller Product Security ---');
  // Seller 1 accesses /api/seller/products
  const s1ProductsRes = await dispatch(app, {
    method: 'GET',
    url: '/api/seller/products',
    headers: { 'authorization': `Bearer ${tokenSeller1}` }
  });
  assert(s1ProductsRes.status === 200, `Seller 1 catalog returned 200`);
  const s1Products = s1ProductsRes.body.products?.data || s1ProductsRes.body.products || [];
  assert(s1Products.every(p => Number(p.seller_id) === 1), 'All returned products belong to Seller 1');

  // Seller 1 tries to access Seller 2's catalog route
  const crossSellerProductsRes = await dispatch(app, {
    method: 'GET',
    url: '/api/sellers/2/products',
    headers: { 'authorization': `Bearer ${tokenSeller1}` }
  });
  assert(crossSellerProductsRes.status === 403, `Seller 1 accessing /api/sellers/2/products is blocked with 403`);

  // Seller 1 tries to update Seller 2's product (id: 7)
  const updateOtherProductRes = await dispatch(app, {
    method: 'PUT',
    url: '/api/products/7',
    headers: { 'authorization': `Bearer ${tokenSeller1}` },
    body: { name: 'Hacked Name', price: 99 }
  });
  assert(updateOtherProductRes.status === 403, `Seller 1 updating Seller 2 product blocked with 403 (got ${updateOtherProductRes.status})`);

  // Seller 1 tries to delete Seller 2's product (id: 7)
  const deleteOtherProductRes = await dispatch(app, {
    method: 'DELETE',
    url: '/api/products/7',
    headers: { 'authorization': `Bearer ${tokenSeller1}` }
  });
  assert(deleteOtherProductRes.status === 403, `Seller 1 deleting Seller 2 product blocked with 403 (got ${deleteOtherProductRes.status})`);

  console.log('\n===========================================');
  console.log('🎉 ALL 20 INTEGRATION TEST ASSERTIONS PASSED!');
  console.log('===========================================\n');
}

run().catch(err => {
  console.error('\n❌ TEST RUNNER FAILED WITH ERROR:', err);
  process.exit(1);
});
