/**
 * Automated Verification: Product Pricing Breakdown & Presentation
 * Tests that when a seller adds/updates a product with price 1000:
 * - Raw price is NOT presented directly on the field
 * - Breakdown added: Commission (50), Delivery (50), GST (18), Other charges (20)
 * - Presentation field ('price') is 1138.00
 */

const https = require('https');

const BASE_URL = 'https://knowlipop.shop';

function request(method, path, headers = {}, body = null) {
  return new Promise((resolve, reject) => {
    const url = new URL(path, BASE_URL);
    const options = {
      method,
      hostname: url.hostname,
      port: 443,
      path: url.pathname + url.search,
      headers: {
        'Accept': 'application/json',
        ...headers
      }
    };

    if (body) {
      const data = typeof body === 'string' ? body : JSON.stringify(body);
      options.headers['Content-Type'] = 'application/json';
      options.headers['Content-Length'] = Buffer.byteLength(data);
    }

    const req = https.request(options, (res) => {
      let data = '';
      res.on('data', chunk => { data += chunk; });
      res.on('end', () => {
        let json = null;
        try { json = JSON.parse(data); } catch (e) { json = data; }
        resolve({ status: res.statusCode, headers: res.headers, data: json });
      });
    });

    req.on('error', reject);
    if (body) req.write(typeof body === 'string' ? body : JSON.stringify(body));
    req.end();
  });
}

// Local unit test of the pricing breakdown formula
function testFormula() {
  console.log('\n--- 1. Testing Pricing Breakdown Formula in Memory ---');

  function calculatePricingBreakdown(body = {}) {
    const rawPrice = (body.seller_price !== undefined && body.seller_price !== null && body.seller_price !== '')
      ? parseFloat(body.seller_price)
      : ((body.price !== undefined && body.price !== null && body.price !== '') ? parseFloat(body.price) : 0);

    const seller_price = isNaN(rawPrice) ? 0 : Math.max(0, Math.round(rawPrice * 100) / 100);

    let commission;
    if (body.commission !== undefined && body.commission !== null && body.commission !== '' && !isNaN(body.commission)) {
      commission = Math.max(0, Math.round(parseFloat(body.commission) * 100) / 100);
    } else {
      commission = Math.round(seller_price * 0.05 * 100) / 100;
    }

    let delivery_charge;
    const rawDelivery = body.delivery_charge !== undefined ? body.delivery_charge : (body.shipping_charge !== undefined ? body.shipping_charge : body.delivery_fee);
    if (rawDelivery !== undefined && rawDelivery !== null && rawDelivery !== '' && !isNaN(rawDelivery)) {
      delivery_charge = Math.max(0, Math.round(parseFloat(rawDelivery) * 100) / 100);
    } else {
      delivery_charge = 50.00;
    }

    let gst;
    const rawGst = body.gst !== undefined ? body.gst : (body.tax !== undefined ? body.tax : body.tax_amount);
    if (rawGst !== undefined && rawGst !== null && rawGst !== '' && !isNaN(rawGst)) {
      gst = Math.max(0, Math.round(parseFloat(rawGst) * 100) / 100);
    } else {
      const feeBase = commission + delivery_charge;
      gst = Math.round(feeBase * 0.18 * 100) / 100;
      if (gst === 0 && seller_price > 0) gst = 18.00;
    }

    let other_charges;
    const rawOther = body.other_charges !== undefined ? body.other_charges : (body.handling_fee !== undefined ? body.handling_fee : body.platform_fee);
    if (rawOther !== undefined && rawOther !== null && rawOther !== '' && !isNaN(rawOther)) {
      other_charges = Math.max(0, Math.round(parseFloat(rawOther) * 100) / 100);
    } else {
      other_charges = 20.00;
    }

    const presentation_price = Math.round((seller_price + commission + delivery_charge + gst + other_charges) * 100) / 100;

    return {
      seller_price,
      commission,
      delivery_charge,
      gst,
      other_charges,
      price: presentation_price,
      presentation_price,
      selling_price: presentation_price
    };
  }

  // Case 1: Base 1000 with smart default charges
  const r1 = calculatePricingBreakdown({ price: 1000 });
  console.log('Case 1 (Base 1000 default):', r1);
  if (r1.seller_price !== 1000 || r1.commission !== 50 || r1.delivery_charge !== 50 || r1.gst !== 18 || r1.other_charges !== 20 || r1.price !== 1138) {
    throw new Error('Case 1 failed: Expected price 1138, got ' + r1.price);
  }
  console.log('✅ Case 1 Passed: Base 1000 -> Presentation 1138.00 (Commission 50 + Delivery 50 + GST 18 + Other 20)');

  // Case 2: Custom overrides
  const r2 = calculatePricingBreakdown({ seller_price: 1000, commission: 80, delivery_charge: 60, gst: 25, other_charges: 15 });
  console.log('Case 2 (Custom overrides):', r2);
  if (r2.price !== 1180) {
    throw new Error('Case 2 failed: Expected price 1180, got ' + r2.price);
  }
  console.log('✅ Case 2 Passed: Custom overrides calculated correctly: 1180.00');

  // Case 3: Other base price e.g. 500
  const r3 = calculatePricingBreakdown({ price: 500 });
  console.log('Case 3 (Base 500):', r3);
  if (r3.price !== 608.5) {
    throw new Error('Case 3 failed: Expected price 608.50, got ' + r3.price);
  }
  console.log('✅ Case 3 Passed: Base 500 -> Presentation 608.50');
}

async function runLiveTests() {
  testFormula();

  console.log('\n--- 2. Testing Live Endpoints on ' + BASE_URL + ' ---');

  // Step 1: Health check
  const health = await request('GET', '/api/health');
  console.log('Health Check status:', health.status, 'db:', health.data && health.data.db ? health.data.db.status : 'n/a');

  // Step 2: Login or register seller
  let token = null;
  const loginRes = await request('POST', '/api/login', {}, {
    phone: '9876543210',
    password: 'Password123!'
  });

  if (loginRes.status === 200 && loginRes.data && loginRes.data.token) {
    token = loginRes.data.token;
    console.log('✅ Logged in successfully with existing seller. Seller ID:', loginRes.data.user.id);
  } else {
    const regPhone = '9' + Math.floor(100000000 + Math.random() * 900000000);
    const regRes = await request('POST', '/api/register', {}, {
      name: 'Pricing Test Seller',
      seller_name: 'Pricing Test Store',
      phone: regPhone,
      address: '452 MG Road, Suite 300',
      state: 'Kerala',
      country: 'India',
      password: 'Password123!',
      password_confirmation: 'Password123!'
    });
    if (regRes.status === 201 && regRes.data && regRes.data.token) {
      token = regRes.data.token;
      console.log('✅ Registered new seller. Phone:', regPhone, 'Seller ID:', regRes.data.user.id);
    } else {
      throw new Error('Failed to authenticate seller: ' + JSON.stringify(regRes.data));
    }
  }

  // Step 3: Create product with base price 1000
  console.log('\n--- 3. Creating Product with price = 1000 ---');
  const createRes = await request('POST', '/api/products', { Authorization: `Bearer ${token}` }, {
    name: 'Smart ANC Headphones Pro 1000',
    description: 'Noise cancelling headphones entered at base ₹1000',
    price: 1000.00,
    stock: 25,
    image_url: 'https://images.unsplash.com/photo-1505740420928-5e560c06d30e?w=500'
  });

  console.log('Create Product status:', createRes.status);
  const prod = createRes.data && createRes.data.product;
  if (!prod) {
    throw new Error('Create product failed: ' + JSON.stringify(createRes.data));
  }
  console.log('Returned Product:', {
    id: prod.id,
    name: prod.name,
    seller_price: prod.seller_price,
    commission: prod.commission,
    delivery_charge: prod.delivery_charge,
    gst: prod.gst,
    other_charges: prod.other_charges,
    price: prod.price,
    presentation_price: prod.presentation_price
  });

  if (prod.seller_price !== '1000.00') {
    throw new Error(`Expected seller_price to be '1000.00', got '${prod.seller_price}'`);
  }
  if (prod.price !== '1138.00') {
    throw new Error(`Expected presentation price to be '1138.00', got '${prod.price}'`);
  }
  if (prod.commission !== '50.00') {
    throw new Error(`Expected commission to be '50.00', got '${prod.commission}'`);
  }
  if (prod.delivery_charge !== '50.00') {
    throw new Error(`Expected delivery_charge to be '50.00', got '${prod.delivery_charge}'`);
  }
  if (prod.gst !== '18.00') {
    throw new Error(`Expected gst to be '18.00', got '${prod.gst}'`);
  }
  if (prod.other_charges !== '20.00') {
    throw new Error(`Expected other_charges to be '20.00', got '${prod.other_charges}'`);
  }
  console.log('✅ Product Creation Verified: Base 1000 -> Presentation 1138.00 with all 4 charges included!');

  // Step 4: Fetch product by ID (GET /api/products/:id)
  console.log('\n--- 4. Fetching Product Details (GET /api/products/' + prod.id + ') ---');
  const getRes = await request('GET', `/api/products/${prod.id}`);
  const fetchedProd = getRes.data && getRes.data.product;
  if (!fetchedProd) throw new Error('Failed to fetch product by id: ' + JSON.stringify(getRes.data));
  if (fetchedProd.seller_price !== '1000.00' || fetchedProd.price !== '1138.00') {
    throw new Error(`GET /api/products/:id mismatch: seller_price=${fetchedProd.seller_price}, price=${fetchedProd.price}`);
  }
  console.log('✅ GET /api/products/:id Verified: Persisted breakdown matches perfectly!');

  // Step 5: Update product with custom overrides (PUT /api/products/:id)
  console.log('\n--- 5. Updating Product with Custom Breakdown (PUT /api/products/' + prod.id + ') ---');
  const updateRes = await request('PUT', `/api/products/${prod.id}`, { Authorization: `Bearer ${token}` }, {
    name: 'Smart ANC Headphones Pro 1000 (Updated)',
    seller_price: 1000.00,
    commission: 80.00,
    delivery_charge: 60.00,
    gst: 25.00,
    other_charges: 15.00
  });

  const updatedProd = updateRes.data && updateRes.data.product;
  if (!updatedProd) throw new Error('Update failed: ' + JSON.stringify(updateRes.data));
  console.log('Updated Product:', {
    seller_price: updatedProd.seller_price,
    commission: updatedProd.commission,
    delivery_charge: updatedProd.delivery_charge,
    gst: updatedProd.gst,
    other_charges: updatedProd.other_charges,
    price: updatedProd.price
  });

  if (updatedProd.price !== '1180.00' || updatedProd.seller_price !== '1000.00') {
    throw new Error(`Expected updated price '1180.00', got '${updatedProd.price}'`);
  }
  console.log('✅ PUT /api/products/:id Verified: Custom overrides recalculated to 1180.00!');

  // Step 6: Place an order using this product to verify line item subtotal
  console.log('\n--- 6. Placing Order with the Product ---');
  const orderRes = await request('POST', '/api/orders', {}, {
    buyer_name: 'Pricing Test Buyer',
    buyer_phone: '9988776655',
    shipping_address: '100 Commerce Road, Tech City',
    payment_method: 'cod',
    items: [
      { product_id: prod.id, quantity: 2 }
    ]
  });

  console.log('Order creation status:', orderRes.status);
  const orderData = orderRes.data && orderRes.data.order;
  if (!orderData) throw new Error('Order creation failed: ' + JSON.stringify(orderRes.data));

  const item = orderData.order_items && orderData.order_items[0];
  console.log('Order Item in Order #', orderData.id, ':', {
    product_name: item ? item.product_name : 'n/a',
    price: item ? item.price : 'n/a',
    quantity: item ? item.quantity : 'n/a',
    subtotal: item ? item.subtotal : 'n/a'
  });

  if (item && parseFloat(item.price) !== 1180.00) {
    throw new Error(`Expected order item price to be 1180.00, got ${item.price}`);
  }
  if (item && parseFloat(item.subtotal) !== 2360.00) {
    throw new Error(`Expected order item subtotal to be 2360.00, got ${item.subtotal}`);
  }
  console.log('✅ Order Placement Verified: Customer bought at presentation price (₹1180 × 2 = ₹2360)!');

  // Step 7: Clean up test product
  console.log('\n--- 7. Cleanup Test Product ---');
  await request('DELETE', `/api/products/${prod.id}`, { Authorization: `Bearer ${token}` });
  console.log('✅ Cleanup completed.');

  console.log('\n🎉 ALL PRICING PRESENTATION TESTS PASSED 100%!');
}

runLiveTests().catch(err => {
  console.error('\n❌ Test failed:', err.message);
  process.exit(1);
});
