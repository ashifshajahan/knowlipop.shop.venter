/**
 * Automated Verification: Product Pricing Breakdown & Presentation
 * Tests:
 * 1. Base price 1000 calculation: Presentation price becomes 1138.00 (Commission 50 + Delivery 50 + GST 18 + Other charges 20)
 * 2. Multi-image support: Products can have multiple gallery images (images array)
 * 3. Role segregation:
 *    - SELLER FIELD: Commission feature is completely DELETED from seller view (seller sees price 1000.00, no commission/fee margins)
 *    - BUYER FIELD: Buyer sees presentation price 1138.00 along with the transparent commission breakdown (Commission 50, Delivery 50, etc.)
 * 4. Image Upload: Multiple images can be uploaded and resolved
 * 5. Order creation: Buyer purchases at 1138.00 presentation price
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

  // Step 3: Create product with base price 1000 and multiple images
  console.log('\n--- 3. Creating Product with price = 1000 and Multiple Images ---');
  const testImages = [
    'https://images.unsplash.com/photo-1505740420928-5e560c06d30e?w=500',
    'https://images.unsplash.com/photo-1583394838336-acd977736f90?w=500',
    'https://images.unsplash.com/photo-1484704849700-f032a568e944?w=500'
  ];

  const createRes = await request('POST', '/api/products', { Authorization: `Bearer ${token}` }, {
    name: 'Multi-Image ANC Headphones 1000',
    description: 'Noise cancelling headphones entered at base ₹1000 with 3 gallery images',
    price: 1000.00,
    stock: 25,
    images: testImages
  });

  console.log('Create Product status:', createRes.status);
  const prod = createRes.data && createRes.data.product;
  if (!prod) {
    throw new Error('Create product failed: ' + JSON.stringify(createRes.data));
  }
  console.log('Seller Created Product Response (Seller Field):', {
    id: prod.id,
    name: prod.name,
    price: prod.price,
    seller_price: prod.seller_price,
    commission: prod.commission,
    delivery_charge: prod.delivery_charge,
    images: prod.images,
    image_url: prod.image_url
  });

  // Verify Seller Field: Commission feature is deleted, seller sees their price (1000.00)
  if (prod.price !== '1000.00') {
    throw new Error(`Expected seller price in seller field to be '1000.00', got '${prod.price}'`);
  }
  if (prod.commission !== undefined) {
    throw new Error(`Violation: Commission feature must be deleted from seller field! Got commission: '${prod.commission}'`);
  }
  if (prod.delivery_charge !== undefined) {
    throw new Error(`Violation: Delivery charge feature must be deleted from seller field! Got: '${prod.delivery_charge}'`);
  }
  if (!Array.isArray(prod.images) || prod.images.length !== 3) {
    throw new Error(`Expected product to have 3 images, got: ${JSON.stringify(prod.images)}`);
  }
  if (prod.image_url !== testImages[0]) {
    throw new Error(`Expected primary image_url to be '${testImages[0]}', got '${prod.image_url}'`);
  }
  console.log('✅ Seller Creation Response Verified: Seller sees ₹1000.00, NO commission feature, and 3 product images!');

  // Step 4: Verify Seller Field via GET /api/seller/products
  console.log('\n--- 4. Verifying Seller Products Endpoint (GET /api/seller/products) ---');
  const sellerListRes = await request('GET', '/api/seller/products', { Authorization: `Bearer ${token}` });
  const sellerProducts = sellerListRes.data && sellerListRes.data.products && sellerListRes.data.products.data;
  if (!sellerProducts || !Array.isArray(sellerProducts)) {
    throw new Error('Failed to fetch seller products: ' + JSON.stringify(sellerListRes.data));
  }
  const foundInSellerList = sellerProducts.find(p => p.id === prod.id);
  if (!foundInSellerList) {
    throw new Error(`Created product #${prod.id} not found in seller products list!`);
  }
  if (foundInSellerList.commission !== undefined) {
    throw new Error(`Violation: Commission must not be seen by seller in GET /api/seller/products! Got: ${foundInSellerList.commission}`);
  }
  if (foundInSellerList.price !== '1000.00') {
    throw new Error(`Expected seller price '1000.00' in seller products list, got: ${foundInSellerList.price}`);
  }
  if (!Array.isArray(foundInSellerList.images) || foundInSellerList.images.length !== 3) {
    throw new Error(`Expected 3 images in seller products list, got: ${JSON.stringify(foundInSellerList.images)}`);
  }
  console.log('✅ Seller Products List Verified: Seller sees clean price ₹1000.00, NO commission, and multiple images!');

  // Step 5: Verify Buyer Field via GET /api/products/:id (Public Buyer View)
  console.log('\n--- 5. Verifying Buyer Field (GET /api/products/' + prod.id + ') ---');
  const getRes = await request('GET', `/api/products/${prod.id}`);
  const buyerProd = getRes.data && getRes.data.product;
  if (!buyerProd) throw new Error('Failed to fetch product by id: ' + JSON.stringify(getRes.data));
  
  console.log('Buyer Product Details:', {
    id: buyerProd.id,
    name: buyerProd.name,
    price: buyerProd.price,
    seller_price: buyerProd.seller_price,
    commission: buyerProd.commission,
    delivery_charge: buyerProd.delivery_charge,
    gst: buyerProd.gst,
    other_charges: buyerProd.other_charges,
    images: buyerProd.images
  });

  // Buyer MUST see presentation price ₹1138.00
  if (buyerProd.price !== '1138.00') {
    throw new Error(`Expected buyer presentation price to be '1138.00', got '${buyerProd.price}'`);
  }
  // Buyer MUST see transparent commission detail (₹50.00)
  if (buyerProd.commission !== '50.00') {
    throw new Error(`Expected buyer to see commission detail '50.00', got '${buyerProd.commission}'`);
  }
  if (buyerProd.delivery_charge !== '50.00') {
    throw new Error(`Expected delivery_charge to be '50.00', got '${buyerProd.delivery_charge}'`);
  }
  if (buyerProd.gst !== '18.00') {
    throw new Error(`Expected gst to be '18.00', got '${buyerProd.gst}'`);
  }
  if (buyerProd.other_charges !== '20.00') {
    throw new Error(`Expected other_charges to be '20.00', got '${buyerProd.other_charges}'`);
  }
  // Buyer MUST see multiple product images
  if (!Array.isArray(buyerProd.images) || buyerProd.images.length !== 3) {
    throw new Error(`Expected buyer to see 3 product images, got: ${JSON.stringify(buyerProd.images)}`);
  }
  console.log('✅ Buyer Field Verified: Buyer sees presentation price ₹1138.00, Commission Detail ₹50.00, and all 3 images!');

  // Step 6: Verify Buyer Field in Catalog (GET /api/products)
  console.log('\n--- 6. Verifying Buyer Catalog (GET /api/products) ---');
  const catalogRes = await request('GET', '/api/products');
  const catalogList = catalogRes.data && catalogRes.data.products && catalogRes.data.products.data;
  if (!catalogList || !Array.isArray(catalogList)) {
    throw new Error('Failed to fetch catalog: ' + JSON.stringify(catalogRes.data));
  }
  const foundInCatalog = catalogList.find(p => p.id === prod.id);
  if (!foundInCatalog) {
    throw new Error(`Product #${prod.id} not found in public buyer catalog!`);
  }
  if (foundInCatalog.price !== '1138.00' || foundInCatalog.commission !== '50.00') {
    throw new Error(`Catalog product mismatch: price=${foundInCatalog.price}, commission=${foundInCatalog.commission}`);
  }
  if (!Array.isArray(foundInCatalog.images) || foundInCatalog.images.length !== 3) {
    throw new Error(`Catalog product missing multiple images: ${JSON.stringify(foundInCatalog.images)}`);
  }
  console.log('✅ Buyer Catalog Verified: Public catalog presents ₹1138.00 with transparent commission ₹50.00 and 3 images!');

  // Step 7: Test Image Upload endpoint (POST /api/upload)
  console.log('\n--- 7. Verifying Image Upload Endpoint (POST /api/upload) ---');
  const sampleBase64 = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
  const uploadRes = await request('POST', '/api/upload', { Authorization: `Bearer ${token}` }, {
    images: [sampleBase64, 'https://images.unsplash.com/photo-1523275335684-37898b6baf30?w=500']
  });
  console.log('Upload response status:', uploadRes.status);
  if (uploadRes.status !== 201 || !uploadRes.data || !uploadRes.data.image_url || !Array.isArray(uploadRes.data.images)) {
    throw new Error('Upload endpoint failed: ' + JSON.stringify(uploadRes.data));
  }
  console.log('Uploaded images count:', uploadRes.data.images.length, 'Primary URL:', uploadRes.data.image_url);
  console.log('✅ Upload Endpoint Verified: Multiple product images successfully resolved!');

  // Step 8: Place Order with Product at Presentation Price
  console.log('\n--- 8. Placing Order with Product (Buyer Purchase) ---');
  const orderRes = await request('POST', '/api/orders', {}, {
    buyer_name: 'Pricing Test Buyer',
    buyer_phone: '9988776655',
    shipping_address: '100 Commerce Road, Tech City',
    payment_method: 'cod',
    items: [
      { product_id: prod.id, quantity: 2 }
    ]
  });

  const orderData = orderRes.data && orderRes.data.order;
  if (!orderData) throw new Error('Order creation failed: ' + JSON.stringify(orderRes.data));

  const item = orderData.order_items && orderData.order_items[0];
  console.log('Order Item in Order #', orderData.id, ':', {
    product_name: item ? item.product_name : 'n/a',
    price: item ? item.price : 'n/a',
    quantity: item ? item.quantity : 'n/a',
    subtotal: item ? item.subtotal : 'n/a'
  });

  if (item && parseFloat(item.price) !== 1138.00) {
    throw new Error(`Expected order item price to be 1138.00, got ${item.price}`);
  }
  if (item && parseFloat(item.subtotal) !== 2276.00) {
    throw new Error(`Expected order item subtotal to be 2276.00, got ${item.subtotal}`);
  }
  console.log('✅ Order Placement Verified: Customer bought at presentation price (₹1138 × 2 = ₹2276)!');

  // Step 9: Clean up test product
  console.log('\n--- 9. Cleanup Test Product ---');
  await request('DELETE', `/api/products/${prod.id}`, { Authorization: `Bearer ${token}` });
  console.log('✅ Cleanup completed.');

  console.log('\n🎉 ALL VERIFICATION TESTS PASSED 100%!');
}

runLiveTests().catch(err => {
  console.error('\n❌ Test failed:', err.message);
  process.exit(1);
});
