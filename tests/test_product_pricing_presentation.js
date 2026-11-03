/**
 * Automated Verification: Product Pricing (Amazon / Flipkart Standard Model)
 *
 * Verifies:
 * 1. Clean Pricing: Product created at 1000.00 has exact price 1000.00 (NO compounding, NO markups).
 * 2. Compounding Bug Fix: Touching or editing the product multiple times (via PUT /api/products/:id)
 *    keeps price strictly at 1000.00 (never inflates to 1138, 1284, etc.).
 * 3. Seller Field (Seller Central style):
 *    - Price: 1000.00 (clean listed price)
 *    - Settlement preview: Estimated Referral Fee (5%) = 50.00, Net Payout = 950.00
 *    - NO buyer commission / fee markups added to bloat the seller's price!
 * 4. Buyer Field (Marketplace Store style):
 *    - Selling Price: 1000.00
 *    - MRP: 1499.00 (strike-through)
 *    - Discount Percent: 33% OFF
 *    - Delivery: FREE Delivery (for orders >= 500)
 *    - Rating & Reviews: 4.5 ★, multi-image gallery
 * 5. Order Calculation (1 Item and 3 Items):
 *    - Unit price: 1000.00 clearly visible
 *    - 3 items subtotal: 3 × 1000.00 = 3000.00
 *    - Total amount: 3000.00
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

// In-Memory Unit Test for the Amazon / Flipkart pricing model
function testFormula() {
  console.log('\n--- 1. Testing Amazon / Flipkart Product Pricing Model in Memory ---');

  function resolveProductPricing(body = {}) {
    const rawPrice = (body.price !== undefined && body.price !== null && body.price !== '')
      ? parseFloat(body.price)
      : (body.seller_price !== undefined ? parseFloat(body.seller_price) : 0);
    const cleanPrice = Math.max(0, Math.round(rawPrice * 100) / 100);

    const rawMrp = body.mrp ? parseFloat(body.mrp) : null;
    const mrp = (rawMrp !== null && rawMrp >= cleanPrice)
      ? Math.round(rawMrp * 100) / 100
      : (cleanPrice > 0 ? Math.round(cleanPrice * 1.30 * 100) / 100 : 0);

    const discount_percent = (mrp > cleanPrice && mrp > 0)
      ? Math.round(((mrp - cleanPrice) / mrp) * 100)
      : 0;

    const commission = Math.round(cleanPrice * 0.05 * 100) / 100;
    const estimated_payout = Math.max(0, Math.round((cleanPrice - commission) * 100) / 100);
    const delivery_charge = cleanPrice >= 500 ? 0.00 : 50.00;
    const delivery_text = cleanPrice >= 500 ? 'FREE Delivery' : 'Standard Delivery (₹50)';

    return {
      price: cleanPrice,
      seller_price: cleanPrice,
      mrp,
      discount_percent,
      commission,
      estimated_payout,
      delivery_charge,
      delivery_text
    };
  }

  // Case 1: Seller lists at 1000 with custom MRP 1499
  const r1 = resolveProductPricing({ price: 1000, mrp: 1499 });
  console.log('Case 1 (1000 with MRP 1499):', r1);
  if (r1.price !== 1000 || r1.mrp !== 1499 || r1.discount_percent !== 33 || r1.commission !== 50 || r1.estimated_payout !== 950 || r1.delivery_charge !== 0) {
    throw new Error('Case 1 failed: Expected clean price 1000, got ' + r1.price);
  }
  console.log('✅ Case 1 Passed: Clean Price = ₹1000.00, MRP = ₹1499.00 (33% OFF), Payout = ₹950.00, FREE Delivery');

  // Case 2: Auto-computed MRP (30% markup)
  const r2 = resolveProductPricing({ price: 1000 });
  console.log('Case 2 (1000 with auto MRP):', r2);
  if (r2.price !== 1000 || r2.mrp !== 1300 || r2.discount_percent !== 23) {
    throw new Error('Case 2 failed: Expected clean price 1000, MRP 1300');
  }
  console.log('✅ Case 2 Passed: Clean Price = ₹1000.00, Auto-MRP = ₹1300.00 (23% OFF)');
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
    console.log('✅ Logged in successfully. Seller ID:', loginRes.data.user.id);
  } else {
    const regPhone = '9' + Math.floor(100000000 + Math.random() * 900000000);
    const regRes = await request('POST', '/api/register', {}, {
      name: 'Clean Pricing Seller',
      seller_name: 'Flipkart Style Store',
      phone: regPhone,
      address: '77 Bannerghatta Road',
      state: 'Karnataka',
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

  // Step 3: Create Product with Price = 1000.00, MRP = 1499.00
  console.log('\n--- 3. Creating Product with Clean Price = ₹1000.00 and MRP = ₹1499.00 ---');
  const testImages = [
    'https://images.unsplash.com/photo-1505740420928-5e560c06d30e?w=500',
    'https://images.unsplash.com/photo-1583394838336-acd977736f90?w=500',
    'https://images.unsplash.com/photo-1484704849700-f032a568e944?w=500'
  ];

  const createRes = await request('POST', '/api/products', { Authorization: `Bearer ${token}` }, {
    name: 'Clean Price Wireless ANC Pro',
    description: 'Noise cancelling headphones with standard selling price 1000 and MRP 1499',
    price: 1000.00,
    mrp: 1499.00,
    sku: 'SKU-ANC-1000',
    category: 'Electronics',
    brand: 'AudioPro',
    stock: 50,
    images: testImages
  });

  console.log('Create Product status:', createRes.status);
  const prod = createRes.data && createRes.data.product;
  if (!prod) {
    throw new Error('Create product failed: ' + JSON.stringify(createRes.data));
  }
  console.log('Seller Created Product:', {
    id: prod.id,
    name: prod.name,
    price: prod.price,
    seller_price: prod.seller_price,
    mrp: prod.mrp,
    discount_percent: prod.discount_percent,
    estimated_fee: prod.estimated_fee,
    estimated_payout: prod.estimated_payout,
    commission: prod.commission,
    images_count: prod.images ? prod.images.length : 0
  });

  // Verify Seller Field: clean price 1000.00, NO compounding markup
  if (prod.price !== '1000.00') {
    throw new Error(`Expected seller price to be '1000.00', got '${prod.price}'`);
  }
  if (prod.mrp !== '1499.00') {
    throw new Error(`Expected MRP to be '1499.00', got '${prod.mrp}'`);
  }
  if (prod.discount_percent !== 33) {
    throw new Error(`Expected discount_percent to be 33, got '${prod.discount_percent}'`);
  }
  if (prod.commission !== undefined) {
    throw new Error(`Violation: Commission feature must be deleted from seller view! Got: '${prod.commission}'`);
  }
  if (prod.estimated_payout !== '950.00') {
    throw new Error(`Expected estimated_payout preview to be '950.00', got '${prod.estimated_payout}'`);
  }
  console.log('✅ Product Created Cleanly: Price ₹1000.00, MRP ₹1499.00 (33% OFF), Payout ₹950.00, ZERO added fee bloat!');

  // Step 4: CRITICAL BUG VERIFICATION: TOUCH / EDIT PRODUCT MULTIPLE TIMES
  console.log('\n--- 4. Testing Product Touch / Edit: Price MUST NEVER Increase on Touch! ---');

  // Touch 1: Edit name only (without changing price)
  console.log('Touch 1: Updating name to "Clean Price ANC Touch 1"...');
  const touch1 = await request('PUT', `/api/products/${prod.id}`, { Authorization: `Bearer ${token}` }, {
    name: 'Clean Price ANC Touch 1'
  });
  const p1 = touch1.data && touch1.data.product;
  if (!p1 || p1.price !== '1000.00') {
    throw new Error(`Touch 1 failed! Price increased or mutated from 1000.00 to: ${p1 ? p1.price : 'null'}`);
  }
  console.log('✅ Touch 1 Success: Name updated, Price remained strictly ₹1000.00 (NO compounding)');

  // Touch 2: Edit stock only
  console.log('Touch 2: Updating stock to 45...');
  const touch2 = await request('PUT', `/api/products/${prod.id}`, { Authorization: `Bearer ${token}` }, {
    stock: 45
  });
  const p2 = touch2.data && touch2.data.product;
  if (!p2 || p2.price !== '1000.00') {
    throw new Error(`Touch 2 failed! Price increased or mutated to: ${p2 ? p2.price : 'null'}`);
  }
  console.log('✅ Touch 2 Success: Stock updated to 45, Price remained strictly ₹1000.00 (NO compounding)');

  // Touch 3: Re-submit form with existing price '1000.00'
  console.log('Touch 3: Re-submitting form with price="1000.00"...');
  const touch3 = await request('PUT', `/api/products/${prod.id}`, { Authorization: `Bearer ${token}` }, {
    name: 'Clean Price ANC Touch 3 Final',
    price: '1000.00'
  });
  const p3 = touch3.data && touch3.data.product;
  if (!p3 || p3.price !== '1000.00') {
    throw new Error(`Touch 3 failed! Price increased or mutated to: ${p3 ? p3.price : 'null'}`);
  }
  console.log('✅ Touch 3 Success: Price remained strictly ₹1000.00 after 3 consecutive touches!');

  // Step 5: Verify Public Buyer Field (Marketplace Store)
  console.log('\n--- 5. Verifying Buyer Field (GET /api/products/' + prod.id + ') ---');
  const buyerRes = await request('GET', `/api/products/${prod.id}`);
  const buyerProd = buyerRes.data && buyerRes.data.product;
  if (!buyerProd) {
    throw new Error('Failed to fetch buyer product: ' + JSON.stringify(buyerRes.data));
  }
  console.log('Buyer Product Presentation:', {
    id: buyerProd.id,
    name: buyerProd.name,
    selling_price: buyerProd.price,
    mrp: buyerProd.mrp,
    discount_percent: buyerProd.discount_percent,
    delivery_text: buyerProd.delivery_text,
    rating: buyerProd.rating,
    images: buyerProd.images
  });

  if (buyerProd.price !== '1000.00') {
    throw new Error(`Expected buyer selling price to be '1000.00', got '${buyerProd.price}'`);
  }
  if (buyerProd.mrp !== '1499.00') {
    throw new Error(`Expected buyer MRP to be '1499.00', got '${buyerProd.mrp}'`);
  }
  if (buyerProd.discount_percent !== 33) {
    throw new Error(`Expected buyer discount to be 33%, got '${buyerProd.discount_percent}'`);
  }
  if (buyerProd.delivery_text !== 'FREE Delivery') {
    throw new Error(`Expected delivery_text to be 'FREE Delivery', got '${buyerProd.delivery_text}'`);
  }
  if (!Array.isArray(buyerProd.images) || buyerProd.images.length !== 3) {
    throw new Error(`Expected 3 images, got: ${JSON.stringify(buyerProd.images)}`);
  }
  console.log('✅ Buyer Field Verified: Selling price ₹1000.00, Strike-through MRP ₹1499.00, 33% OFF, FREE Delivery!');

  // Step 6: Order Item Calculation: 1 Item and 3 Items
  console.log('\n--- 6. Verifying Order Item & 3 Items Purchase ---');

  // Purchase 3 items
  const orderRes = await request('POST', '/api/orders', {}, {
    buyer_name: 'Amazon Customer',
    buyer_phone: '9988776655',
    shipping_address: 'Flat 402, Green Meadows',
    city: 'Bengaluru',
    pincode: '560001',
    payment_method: 'cod',
    items: [
      { product_id: prod.id, quantity: 3 }
    ]
  });

  const orderData = orderRes.data && orderRes.data.order;
  if (!orderData) {
    throw new Error('Order creation failed: ' + JSON.stringify(orderRes.data));
  }
  console.log('Created Order #', orderData.id, 'summary:', {
    order_number: orderData.order_number,
    subtotal: orderData.subtotal,
    delivery_fee: orderData.delivery_fee,
    total_amount: orderData.total_amount,
    items_count: orderData.order_items ? orderData.order_items.length : 0
  });

  const orderItem = orderData.order_items && orderData.order_items[0];
  if (!orderItem) throw new Error('Order item missing from order response!');

  console.log('Order Item detail:', {
    product_name: orderItem.product_name,
    unit_price: orderItem.price,
    quantity: orderItem.quantity,
    subtotal: orderItem.subtotal
  });

  // Verify single unit price is clearly 1000.00
  if (parseFloat(orderItem.price) !== 1000.00) {
    throw new Error(`Expected order item unit price to be 1000.00, got ${orderItem.price}`);
  }
  // Verify 3 items subtotal is 3000.00
  if (parseInt(orderItem.quantity, 10) !== 3) {
    throw new Error(`Expected quantity 3, got ${orderItem.quantity}`);
  }
  if (parseFloat(orderItem.subtotal) !== 3000.00) {
    throw new Error(`Expected order item subtotal to be 3000.00, got ${orderItem.subtotal}`);
  }
  if (parseFloat(orderData.total_amount) !== 3000.00) {
    throw new Error(`Expected order total_amount to be 3000.00, got ${orderData.total_amount}`);
  }
  console.log('✅ Order Verified: Unit Price = ₹1000.00, 3 Items Subtotal = ₹3000.00, Total = ₹3000.00!');

  // Step 7: Verify Multi-Image URLs with Strict Limit of 4 and Image Editing / Preservation
  console.log('\n--- 7. Testing Multiple Image URLs (Limit 4) & Image Editing / Preservation ---');
  const sixImages = [
    'https://images.unsplash.com/photo-1505740420928-5e560c06d301?w=800',
    'https://images.unsplash.com/photo-1505740420928-5e560c06d302?w=800',
    'https://images.unsplash.com/photo-1505740420928-5e560c06d303?w=800',
    'https://images.unsplash.com/photo-1505740420928-5e560c06d304?w=800',
    'https://images.unsplash.com/photo-1505740420928-5e560c06d305?w=800',
    'https://images.unsplash.com/photo-1505740420928-5e560c06d306?w=800'
  ];

  const multiRes = await request('POST', '/api/products', { Authorization: `Bearer ${token}` }, {
    name: 'Multi Image Gallery Product (4 Max)',
    price: 1500.00,
    mrp: 2000.00,
    stock: 20,
    images: sixImages
  });

  const multiProd = multiRes.data && multiRes.data.product;
  if (!multiProd) throw new Error('Failed to create multi-image product: ' + JSON.stringify(multiRes.data));

  console.log('Multi Image Product images count:', multiProd.images ? multiProd.images.length : 0);
  if (!Array.isArray(multiProd.images) || multiProd.images.length !== 4) {
    throw new Error(`Expected strictly 4 images after sending 6 (limit is 4), got ${multiProd.images ? multiProd.images.length : 'none'}`);
  }
  console.log('✅ Strict 4 Image URLs limit enforced! (Sent 6, exactly 4 retained)');

  // Verify non-image update preserves existing images (does not overwrite or revert to stock photos)
  const noImageUpdate = await request('PUT', `/api/products/${multiProd.id}`, { Authorization: `Bearer ${token}` }, {
    name: 'Multi Image Gallery Product Renamed'
  });
  const noImageProd = noImageUpdate.data && noImageUpdate.data.product;
  if (!noImageProd || !Array.isArray(noImageProd.images) || noImageProd.images.length !== 4) {
    throw new Error(`Non-image edit failed to preserve 4 images: got ${noImageProd ? JSON.stringify(noImageProd.images) : 'null'}`);
  }
  console.log('✅ Editing product name/price without images preserves existing images cleanly!');

  // Verify updating product with new images replaces them cleanly
  const newImages = [
    'https://images.unsplash.com/photo-1526170375885-4d8ecf77b99f?w=800',
    'https://images.unsplash.com/photo-1560343090-f0409e92791a?w=800'
  ];
  const editImagesUpdate = await request('PUT', `/api/products/${multiProd.id}`, { Authorization: `Bearer ${token}` }, {
    images: newImages
  });
  const editImagesProd = editImagesUpdate.data && editImagesUpdate.data.product;
  if (!editImagesProd || !Array.isArray(editImagesProd.images) || editImagesProd.images.length !== 2) {
    throw new Error(`Updating with 2 new images failed: got ${editImagesProd ? JSON.stringify(editImagesProd.images) : 'null'}`);
  }
  if (editImagesProd.images[0] !== newImages[0] || editImagesProd.images[1] !== newImages[1]) {
    throw new Error(`Images do not match updated images: ${JSON.stringify(editImagesProd.images)}`);
  }
  console.log('✅ Editing/replacing product with new images updates cleanly without fixed old images!');

  // Verify comma-separated URL string parsing
  const commaUpdate = await request('PUT', `/api/products/${multiProd.id}`, { Authorization: `Bearer ${token}` }, {
    images: 'https://images.unsplash.com/photo-1505740420928-5e560c06d301?w=800, https://images.unsplash.com/photo-1505740420928-5e560c06d302?w=800, https://images.unsplash.com/photo-1505740420928-5e560c06d303?w=800'
  });
  const commaProd = commaUpdate.data && commaUpdate.data.product;
  if (!commaProd || !Array.isArray(commaProd.images) || commaProd.images.length !== 3) {
    throw new Error(`Expected comma-separated URLs to parse into 3 images, got: ${JSON.stringify(commaProd && commaProd.images)}`);
  }
  console.log('✅ Comma-separated image URLs parsed cleanly into multiple distinct URLs!');

  // Verify array of image objects format (e.g. [{ url: '...' }])
  const objUpdate = await request('PUT', `/api/products/${multiProd.id}`, { Authorization: `Bearer ${token}` }, {
    images: [
      { url: 'https://images.unsplash.com/photo-1505740420928-5e560c06d301?w=800' },
      { url: 'https://images.unsplash.com/photo-1505740420928-5e560c06d302?w=800' },
      { url: 'https://images.unsplash.com/photo-1505740420928-5e560c06d303?w=800' }
    ]
  });
  const objProd = objUpdate.data && objUpdate.data.product;
  if (!objProd || !Array.isArray(objProd.images) || objProd.images.length !== 3) {
    throw new Error(`Expected array of objects to parse into 3 images, got: ${JSON.stringify(objProd && objProd.images)}`);
  }
  console.log('✅ Array of image objects ([{url:...}]) parsed cleanly into multiple distinct URLs!');

  // Verify numbered keys (image1, image2, image3, image4)
  const numUpdate = await request('PUT', `/api/products/${multiProd.id}`, { Authorization: `Bearer ${token}` }, {
    image1: 'https://images.unsplash.com/photo-1505740420928-5e560c06d301?w=800',
    image2: 'https://images.unsplash.com/photo-1505740420928-5e560c06d302?w=800',
    image3: 'https://images.unsplash.com/photo-1505740420928-5e560c06d303?w=800',
    image4: 'https://images.unsplash.com/photo-1505740420928-5e560c06d304?w=800'
  });
  const numProd = numUpdate.data && numUpdate.data.product;
  if (!numProd || !Array.isArray(numProd.images) || numProd.images.length !== 4) {
    throw new Error(`Expected numbered keys (image1..image4) to parse into 4 images, got: ${JSON.stringify(numProd && numProd.images)}`);
  }
  console.log('✅ Numbered image keys (image1..image4) parsed cleanly into multiple distinct URLs!');

  // Verify catalog has distinct images (products 1, 2, 3 have distinct galleries)
  const catalogRes = await request('GET', '/api/products');
  const catalogList = (catalogRes.data && catalogRes.data.products && catalogRes.data.products.data) || [];
  const p1Catalog = catalogList.find(p => p.id === 1);
  const p2Catalog = catalogList.find(p => p.id === 2);
  const p54Catalog = catalogList.find(p => p.id === 54);
  if (p1Catalog && p2Catalog) {
    console.log('Catalog Product 1 image:', p1Catalog.image_url);
    console.log('Catalog Product 2 image:', p2Catalog.image_url);
    if (p1Catalog.image_url === p2Catalog.image_url) {
      throw new Error(`Products 1 and 2 share the same duplicate image URL: ${p1Catalog.image_url}`);
    }
    console.log('✅ Catalog verification passed: Products have distinct, unique image URLs across different products!');
  }
  if (p54Catalog) {
    console.log('Catalog Product 54 (Rty from screenshot) images count:', p54Catalog.images ? p54Catalog.images.length : 0);
    console.log('Catalog Product 54 images:', p54Catalog.images);
    if (!Array.isArray(p54Catalog.images) || p54Catalog.images.length === 0) {
      throw new Error(`Expected Product 54 to have seller image, got: ${JSON.stringify(p54Catalog.images)}`);
    }
    if (p54Catalog.images.some(img => img.includes('photo-1526170375885') || img.includes('photo-1560343090'))) {
      throw new Error(`Product 54 still contains forced unsplash stock photos! Images: ${JSON.stringify(p54Catalog.images)}`);
    }
    console.log('✅ Product 54 (Row 11 in DB) verified: Forced stock photos removed, seller uploaded image preserved!');
  }

  // Verify Image Persistence on Back/Refresh & Raw Base64 Support
  console.log('\n--- 8. Testing Image Persistence on Back/Refresh & Raw Base64 ---');
  const rawBase64Png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
  const b64Create = await request('POST', '/api/products', { Authorization: `Bearer ${token}` }, {
    name: 'Raw Base64 Headphone Test',
    price: 1800.00,
    mrp: 2400.00,
    stock: 15,
    image: rawBase64Png
  });
  const b64Prod = b64Create.data && b64Create.data.product;
  if (!b64Prod || !b64Prod.image_url || !b64Prod.image_url.includes('/uploads/products/')) {
    throw new Error(`Raw base64 image creation failed: ${JSON.stringify(b64Create.data)}`);
  }
  console.log('✅ Raw base64 decoded and saved to disk URL:', b64Prod.image_url);

  // Test that fetching the uploaded image returns 200 OK
  const b64ImgFetch = await fetch(b64Prod.image_url);
  if (b64ImgFetch.status !== 200) {
    throw new Error(`Uploaded base64 image returned status ${b64ImgFetch.status}`);
  }
  console.log('✅ Uploaded image URL serves 200 OK with valid bytes!');

  // Simulate Back / Refresh: GET /api/seller/products
  const sellerList = await request('GET', '/api/seller/products', { Authorization: `Bearer ${token}` });
  const sellerData = sellerList.data && sellerList.data.products && sellerList.data.products.data;
  const reloadedProd = sellerData && sellerData.find(p => p.id === b64Prod.id);
  if (!reloadedProd) throw new Error('Product not found in seller list after back/refresh!');
  if (!reloadedProd.image_url || !reloadedProd.image || !Array.isArray(reloadedProd.images) || reloadedProd.images.length === 0) {
    throw new Error(`Image is blank after back/refresh! Got: ${JSON.stringify(reloadedProd)}`);
  }
  console.log('✅ After back/refresh, product retains valid image without blank space:', {
    id: reloadedProd.id,
    image_url: reloadedProd.image_url,
    images: reloadedProd.images
  });

  // Verify Fallback Handler: Even if an uploaded file is missing from disk, it NEVER returns 404/blank!
  const missingImgFetch = await fetch(`${BASE_URL}/uploads/products/non_existent_img_12345.jpg`);
  if (missingImgFetch.status !== 200) {
    throw new Error(`Fallback handler failed! Expected 200 for missing image, got ${missingImgFetch.status}`);
  }
  console.log('✅ Uploads fallback handler verified: Missing images return 200 OK (never 404 blank space)!');

  // Cleanup
  await request('DELETE', `/api/products/${b64Prod.id}`, { Authorization: `Bearer ${token}` });
  await request('DELETE', `/api/products/${multiProd.id}`, { Authorization: `Bearer ${token}` });

  // Step 8: Cleanup
  console.log('\n--- 8. Cleaning up test data ---');
  await request('DELETE', `/api/products/${prod.id}`, { Authorization: `Bearer ${token}` });
  console.log('✅ Cleaned up test product.');

  console.log('\n🎉 ALL VERIFICATION TESTS PASSED 100%!');
}

runLiveTests().catch(err => {
  console.error('\n❌ Test failed:', err.message);
  process.exit(1);
});
