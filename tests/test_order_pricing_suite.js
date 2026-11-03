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

async function runPriceTests() {
  console.log('================================================================');
  console.log('🧪 TESTING ORDER PRICING & 3-ITEM TOTALS ON knowlipop.shop');
  console.log('================================================================\n');

  // Test 1: Single product order with NO price sent by client
  console.log('[Test 1] 1 Product Order without client passing price...');
  const order1Res = await request('POST', '/api/orders', {
    buyer_name: 'Single Product Buyer',
    buyer_phone: '+919876543210',
    shipping_address: '101 Rose Villa, MG Road, Bengaluru',
    items: [
      { product_id: 1, quantity: 1 } // Notice: NO price sent!
    ]
  });

  console.log('Order 1 create status:', order1Res.status);
  const o1 = order1Res.data.order;
  console.log('Order 1 details:', {
    id: o1.id,
    order_number: o1.order_number,
    subtotal: o1.subtotal,
    total_amount: o1.total_amount,
    order_price: o1.order_price,
    item_name: o1.items[0]?.product_name,
    item_price: o1.items[0]?.price,
    item_unit_price: o1.items[0]?.unit_price,
    item_subtotal: o1.items[0]?.subtotal
  });

  if (!o1.items[0] || o1.items[0].price <= 0) {
    throw new Error('Test 1 Failed: Product price was 0 or missing on single product order!');
  }
  if (o1.total_amount <= 0) {
    throw new Error('Test 1 Failed: Total amount was 0 on single product order!');
  }
  console.log('✅ Test 1 Passed: 1-product order price automatically resolved and displayed!\n');

  // Test 2: Person purchases 3 items (quantity = 3) of Product 1 without passing price
  console.log('[Test 2] Purchasing 3 items (quantity = 3) without client passing price...');
  const order3Res = await request('POST', '/api/orders', {
    buyer_name: 'Three Items Buyer',
    buyer_phone: '+919876543211',
    shipping_address: '202 Sunshine Apartments, Indiranagar',
    items: [
      { product_id: 1, quantity: 3 } // 3 units
    ]
  });

  const o3 = order3Res.data.order;
  console.log('Order 3 details:', {
    id: o3.id,
    subtotal: o3.subtotal,
    total_amount: o3.total_amount,
    item_price: o3.items[0]?.price,
    item_qty: o3.items[0]?.quantity,
    item_subtotal: o3.items[0]?.subtotal
  });

  const expectedSubtotal = o3.items[0].price * 3;
  if (o3.items[0].quantity !== 3 || o3.items[0].subtotal !== expectedSubtotal) {
    throw new Error(`Test 2 Failed: Item subtotal expected ${expectedSubtotal}, got ${o3.items[0]?.subtotal}`);
  }
  if (o3.subtotal !== expectedSubtotal || o3.total_amount !== expectedSubtotal) {
    throw new Error(`Test 2 Failed: Total amount expected ${expectedSubtotal}, got ${o3.total_amount}`);
  }
  console.log('✅ Test 2 Passed: 3-item order total amount correctly calculated (3 × ₹' + o3.items[0].price + ' = ₹' + o3.total_amount + ')!\n');

  // Test 3: Person purchases 3 distinct items
  console.log('[Test 3] Purchasing 3 distinct products in 1 order...');
  const order3DistRes = await request('POST', '/api/orders', {
    buyer_name: 'Multi-Product Shopper',
    buyer_phone: '+919876543212',
    shipping_address: '303 Tech Park, Whitefield',
    items: [
      { product_id: 1, quantity: 1 },
      { product_id: 2, quantity: 1 },
      { product_id: 3, quantity: 1 }
    ]
  });

  const o3Dist = order3DistRes.data.order;
  console.log(`Order 3 distinct items created: ID=${o3Dist.id}, Items count=${o3Dist.items.length}`);
  for (const it of o3Dist.items) {
    console.log(`  - ${it.product_name}: ₹${it.price} × ${it.quantity} = ₹${it.subtotal}`);
  }
  console.log(`Subtotal: ₹${o3Dist.subtotal}, Total Amount: ₹${o3Dist.total_amount}`);
  const sumItems = o3Dist.items.reduce((s, i) => s + i.subtotal, 0);
  if (o3Dist.subtotal !== sumItems) {
    throw new Error(`Test 3 Failed: Subtotal ${o3Dist.subtotal} does not match items sum ${sumItems}`);
  }
  console.log('✅ Test 3 Passed: 3 distinct products calculated accurately!\n');

  // Test 4: Retrieve Order Details for Order 3
  console.log(`[Test 4] GET /api/orders/${o3.id} (Order Details endpoint)...`);
  const detailRes = await request('GET', `/api/orders/${o3.id}`);
  const detailOrder = detailRes.data.order;
  console.log('Detail order response:', {
    id: detailOrder.id,
    order_number: detailOrder.order_number,
    subtotal: detailOrder.subtotal,
    total_amount: detailOrder.total_amount,
    total_price: detailOrder.total_price,
    order_price: detailOrder.order_price,
    items_count: detailOrder.items.length,
    item_name: detailOrder.items[0]?.product_name,
    item_price: detailOrder.items[0]?.price,
    item_subtotal: detailOrder.items[0]?.subtotal
  });

  if (detailOrder.items[0].price <= 0) {
    throw new Error('Test 4 Failed: Detail order item price is 0!');
  }
  if (detailOrder.total_amount !== expectedSubtotal) {
    throw new Error(`Test 4 Failed: Detail total amount mismatch! Expected ${expectedSubtotal}, got ${detailOrder.total_amount}`);
  }
  console.log('✅ Test 4 Passed: Order details price and totals match perfectly!\n');

  // Test 5: Verify Orders List has items and prices
  console.log('[Test 5] GET /api/orders (Orders list preview)...');
  const listRes = await request('GET', '/api/orders?limit=5');
  const firstOrder = listRes.data.orders.data[0];
  console.log('First order in list preview:', {
    id: firstOrder.id,
    order_number: firstOrder.order_number,
    items_count: firstOrder.items_count,
    items_attached: (firstOrder.items || []).length,
    first_item_price: firstOrder.items?.[0]?.price,
    total_amount: firstOrder.total_amount
  });
  if (!firstOrder.items || firstOrder.items.length === 0) {
    throw new Error('Test 5 Failed: Orders in list do not have items attached!');
  }
  console.log('✅ Test 5 Passed: Orders list includes items with individual prices!\n');

  console.log('================================================================');
  console.log('🎉 ALL PRICING & 3-ITEM ORDER TOTAL TESTS COMPLETED!');
  console.log('================================================================');
}

runPriceTests().catch(err => {
  console.error('\n❌ Pricing Test Failed:', err.message);
  process.exit(1);
});
