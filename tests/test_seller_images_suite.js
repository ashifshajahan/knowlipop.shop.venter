const https = require('https');
const crypto = require('crypto');

const BASE_URL = 'https://knowlipop.shop';

// 1x1 valid PNG image buffer
const SAMPLE_PNG_1 = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
const SAMPLE_PNG_2 = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', 'base64');
const SAMPLE_PNG_3 = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPj/HwADBwIAMCbHYQAAAABJRU5ErkJggg==', 'base64');
const SAMPLE_PNG_4 = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==', 'base64');

function makeRequest(path, options = {}, bodyBuffer = null) {
  return new Promise((resolve, reject) => {
    const url = new URL(path, BASE_URL);
    const reqOptions = {
      method: options.method || 'GET',
      hostname: url.hostname,
      port: 443,
      path: url.pathname + url.search,
      headers: options.headers || {}
    };

    const req = https.request(reqOptions, (res) => {
      const chunks = [];
      res.on('data', chunk => chunks.push(chunk));
      res.on('end', () => {
        const raw = Buffer.concat(chunks);
        let parsed = null;
        try {
          parsed = JSON.parse(raw.toString('utf8'));
        } catch (e) {
          parsed = raw.toString('utf8');
        }
        resolve({ status: res.statusCode, headers: res.headers, body: parsed, rawLength: raw.length });
      });
    });

    req.on('error', reject);
    if (bodyBuffer) {
      req.write(bodyBuffer);
    }
    req.end();
  });
}

function buildMultipart(fields, files) {
  const boundary = '----WebKitFormBoundary' + crypto.randomBytes(16).toString('hex');
  const chunks = [];

  for (const [k, v] of Object.entries(fields)) {
    chunks.push(Buffer.from(
      `--${boundary}\r\nContent-Disposition: form-data; name="${k}"\r\n\r\n${v}\r\n`
    ));
  }

  for (const file of files) {
    chunks.push(Buffer.from(
      `--${boundary}\r\nContent-Disposition: form-data; name="${file.fieldname}"; filename="${file.filename}"\r\nContent-Type: ${file.contentType || 'image/png'}\r\n\r\n`
    ));
    chunks.push(file.buffer);
    chunks.push(Buffer.from('\r\n'));
  }

  chunks.push(Buffer.from(`--${boundary}--\r\n`));
  const fullBuffer = Buffer.concat(chunks);
  return {
    buffer: fullBuffer,
    headers: {
      'Content-Type': `multipart/form-data; boundary=${boundary}`,
      'Content-Length': fullBuffer.length
    }
  };
}

async function registerSeller(prefix) {
  const phone = '+91' + Math.floor(6000000000 + Math.random() * 3999999999);
  const email = `${prefix}_${Date.now()}@example.com`;
  const res = await makeRequest('/api/register', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' }
  }, Buffer.from(JSON.stringify({
    name: `${prefix} Seller`,
    email,
    phone,
    password: 'Password123!',
    password_confirmation: 'Password123!',
    role: 'seller',
    address: '123 Market Street, Ernakulam'
  })));

  if (res.status !== 201 && res.status !== 200) {
    throw new Error(`Failed to register seller: ${JSON.stringify(res.body)}`);
  }
  return {
    id: res.body.user ? res.body.user.id : res.body.data.id,
    token: res.body.token || res.body.access_token,
    phone,
    email
  };
}

async function run() {
  console.log('=== SELLER MULTIPLE PRODUCT IMAGES TEST SUITE ===\n');

  // Register 2 sellers for ownership tests
  console.log('1. Registering test sellers...');
  const sellerA = await registerSeller('sellerA');
  const sellerB = await registerSeller('sellerB');
  console.log(`✓ Seller A ID: ${sellerA.id}, Seller B ID: ${sellerB.id}`);

  // ---------------------------------------------------------
  // Test 1: Product created with 0 images
  // Requirement 12: If no image is uploaded, return an empty image list. Never insert or display an automatic image.
  // ---------------------------------------------------------
  console.log('\n2. Testing Product with 0 images...');
  const resZero = await makeRequest('/api/products', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${sellerA.token}`
    }
  }, Buffer.from(JSON.stringify({
    name: 'Zero Image Product',
    price: 150.00,
    stock: 10,
    sku: 'ZERO-01'
  })));

  console.log(`Status: ${resZero.status}`);
  const prodZero = resZero.body.product || resZero.body.data;
  console.log('Zero Image Response:', {
    id: prodZero.id,
    image_url: prodZero.image_url,
    images: prodZero.images
  });

  if (prodZero.image_url !== null) throw new Error(`Expected image_url to be null, got: ${prodZero.image_url}`);
  if (!Array.isArray(prodZero.images) || prodZero.images.length !== 0) throw new Error(`Expected images to be empty array, got: ${JSON.stringify(prodZero.images)}`);
  console.log('✓ Requirement 12 verified: 0 images returns image_url=null and images=[]');

  // ---------------------------------------------------------
  // Test 2: Product created with 1 real image
  // ---------------------------------------------------------
  console.log('\n3. Testing Product with 1 real image...');
  const mp1 = buildMultipart(
    { name: 'Single Image Product', price: '200.00', stock: '5', sku: 'ONE-01' },
    [{ fieldname: 'images', filename: 'photo1.png', buffer: SAMPLE_PNG_1, contentType: 'image/png' }]
  );

  const resOne = await makeRequest('/api/products', {
    method: 'POST',
    headers: { ...mp1.headers, 'Authorization': `Bearer ${sellerA.token}` }
  }, mp1.buffer);

  const prodOne = resOne.body.product || resOne.body.data;
  console.log('1 Image Product:', {
    id: prodOne.id,
    image_url: prodOne.image_url,
    images: prodOne.images
  });

  if (!prodOne.image_url || !prodOne.image_url.startsWith('https://knowlipop.shop/uploads/products/prod_')) {
    throw new Error(`Invalid image_url: ${prodOne.image_url}`);
  }
  if (!Array.isArray(prodOne.images) || prodOne.images.length !== 1) {
    throw new Error(`Expected images length 1, got: ${prodOne.images?.length}`);
  }
  console.log('✓ Successfully created product with 1 image.');

  // ---------------------------------------------------------
  // Test 3: Product created with 2 real images
  // ---------------------------------------------------------
  console.log('\n4. Testing Product with 2 real images...');
  const mp2 = buildMultipart(
    { name: 'Dual Image Product', price: '300.00', stock: '5', sku: 'TWO-01' },
    [
      { fieldname: 'images[]', filename: 'photo1.png', buffer: SAMPLE_PNG_1, contentType: 'image/png' },
      { fieldname: 'images[]', filename: 'photo2.png', buffer: SAMPLE_PNG_2, contentType: 'image/png' }
    ]
  );

  const resTwo = await makeRequest('/api/products', {
    method: 'POST',
    headers: { ...mp2.headers, 'Authorization': `Bearer ${sellerA.token}` }
  }, mp2.buffer);

  const prodTwo = resTwo.body.product || resTwo.body.data;
  console.log('2 Image Product:', {
    id: prodTwo.id,
    image_url: prodTwo.image_url,
    images: prodTwo.images
  });

  if (!Array.isArray(prodTwo.images) || prodTwo.images.length !== 2) {
    throw new Error(`Expected images length 2, got: ${prodTwo.images?.length}`);
  }
  console.log('✓ Successfully created product with 2 images.');

  // ---------------------------------------------------------
  // Test 4: Product created with 3 real images
  // ---------------------------------------------------------
  console.log('\n5. Testing Product with 3 real images...');
  const mp3 = buildMultipart(
    { name: 'Triple Image Product', price: '400.00', stock: '5', sku: 'THREE-01' },
    [
      { fieldname: 'image', filename: 'photo1.png', buffer: SAMPLE_PNG_1, contentType: 'image/png' },
      { fieldname: 'image', filename: 'photo2.png', buffer: SAMPLE_PNG_2, contentType: 'image/png' },
      { fieldname: 'image', filename: 'photo3.png', buffer: SAMPLE_PNG_3, contentType: 'image/png' }
    ]
  );

  const resThree = await makeRequest('/api/products', {
    method: 'POST',
    headers: { ...mp3.headers, 'Authorization': `Bearer ${sellerA.token}` }
  }, mp3.buffer);

  const prodThree = resThree.body.product || resThree.body.data;
  console.log('3 Image Product:', {
    id: prodThree.id,
    image_url: prodThree.image_url,
    images: prodThree.images
  });

  if (!Array.isArray(prodThree.images) || prodThree.images.length !== 3) {
    throw new Error(`Expected images length 3, got: ${prodThree.images?.length}`);
  }
  console.log('✓ Successfully created product with 3 images.');

  // ---------------------------------------------------------
  // Test 5: Product created with 4 real images (Max limit)
  // ---------------------------------------------------------
  console.log('\n6. Testing Product with 4 real images...');
  const mp4 = buildMultipart(
    { name: 'Quad Image Product', price: '500.00', stock: '5', sku: 'FOUR-01' },
    [
      { fieldname: 'file', filename: 'photo1.png', buffer: SAMPLE_PNG_1, contentType: 'image/png' },
      { fieldname: 'file', filename: 'photo2.png', buffer: SAMPLE_PNG_2, contentType: 'image/png' },
      { fieldname: 'file', filename: 'photo3.png', buffer: SAMPLE_PNG_3, contentType: 'image/png' },
      { fieldname: 'file', filename: 'photo4.png', buffer: SAMPLE_PNG_4, contentType: 'image/png' }
    ]
  );

  const resFour = await makeRequest('/api/products', {
    method: 'POST',
    headers: { ...mp4.headers, 'Authorization': `Bearer ${sellerA.token}` }
  }, mp4.buffer);

  const prodFour = resFour.body.product || resFour.body.data;
  console.log('4 Image Product:', {
    id: prodFour.id,
    image_url: prodFour.image_url,
    images: prodFour.images
  });

  if (!Array.isArray(prodFour.images) || prodFour.images.length !== 4) {
    throw new Error(`Expected images length 4, got: ${prodFour.images?.length}`);
  }
  console.log('✓ Successfully created product with 4 images.');

  // ---------------------------------------------------------
  // Test 6: Preserving images when adding another (Requirement 8)
  // ---------------------------------------------------------
  console.log('\n7. Testing preserving images when adding another...');
  // We take prodTwo which has 2 images, and add a 3rd image via POST /api/products/:id/images
  const mpAdd3 = buildMultipart(
    {},
    [{ fieldname: 'image', filename: 'photo3.png', buffer: SAMPLE_PNG_3, contentType: 'image/png' }]
  );

  const resAdd3 = await makeRequest(`/api/products/${prodTwo.id}/images`, {
    method: 'POST',
    headers: { ...mpAdd3.headers, 'Authorization': `Bearer ${sellerA.token}` }
  }, mpAdd3.buffer);

  console.log(`Add image 3 status: ${resAdd3.status}`);
  const prodTwoUpdated = resAdd3.body.product || resAdd3.body.data;
  console.log('After adding 3rd image:', {
    id: prodTwo.id,
    image_count: prodTwoUpdated.images?.length,
    images: prodTwoUpdated.images
  });

  if (!Array.isArray(prodTwoUpdated.images) || prodTwoUpdated.images.length !== 3) {
    throw new Error(`Expected 3 images after appending, got: ${prodTwoUpdated.images?.length}`);
  }
  console.log('✓ Requirement 8 verified: Previous 2 images preserved and 3rd image added.');

  // Add 4th image
  const mpAdd4 = buildMultipart(
    {},
    [{ fieldname: 'image', filename: 'photo4.png', buffer: SAMPLE_PNG_4, contentType: 'image/png' }]
  );
  const resAdd4 = await makeRequest(`/api/products/${prodTwo.id}/images`, {
    method: 'POST',
    headers: { ...mpAdd4.headers, 'Authorization': `Bearer ${sellerA.token}` }
  }, mpAdd4.buffer);

  const prodTwoQuad = resAdd4.body.product || resAdd4.body.data;
  console.log('After adding 4th image:', {
    id: prodTwo.id,
    image_count: prodTwoQuad.images?.length
  });

  if (prodTwoQuad.images.length !== 4) {
    throw new Error(`Expected 4 images, got: ${prodTwoQuad.images.length}`);
  }
  console.log('✓ Product now has maximum 4 images.');

  // Try adding a 5th image -> Must be rejected with 422
  console.log('\n8. Testing exceeding 4 images limit...');
  const mpAdd5 = buildMultipart(
    {},
    [{ fieldname: 'image', filename: 'photo5.png', buffer: SAMPLE_PNG_1, contentType: 'image/png' }]
  );
  const resAdd5 = await makeRequest(`/api/products/${prodTwo.id}/images`, {
    method: 'POST',
    headers: { ...mpAdd5.headers, 'Authorization': `Bearer ${sellerA.token}` }
  }, mpAdd5.buffer);

  console.log(`Exceed limit status: ${resAdd5.status}, message: ${resAdd5.body.message}`);
  if (resAdd5.status !== 422) {
    throw new Error(`Expected 422 when exceeding 4 images, got ${resAdd5.status}`);
  }
  console.log('✓ Requirement 5 & 11 verified: Exceeding 4 images rejected with 422.');

  // ---------------------------------------------------------
  // Test 7: Ownership Verification (Requirement 10)
  // ---------------------------------------------------------
  console.log('\n9. Testing unauthorized seller access...');
  // Seller B tries to add an image to Seller A's product
  const resUnauth = await makeRequest(`/api/products/${prodTwo.id}/images`, {
    method: 'POST',
    headers: { ...mpAdd5.headers, 'Authorization': `Bearer ${sellerB.token}` }
  }, mpAdd5.buffer);

  console.log(`Unauthorized status: ${resUnauth.status}, message: ${resUnauth.body.message}`);
  if (resUnauth.status !== 403) {
    throw new Error(`Expected 403 Forbidden for cross-seller modification, got ${resUnauth.status}`);
  }
  console.log('✓ Requirement 10 verified: Non-owner blocked with 403 Forbidden.');

  // ---------------------------------------------------------
  // Test 8: Verify Image Retrieval APIs & Image Fetch (Requirement 9)
  // ---------------------------------------------------------
  console.log('\n10. Testing GET APIs and image download...');
  // GET /api/products/:id
  const resGet = await makeRequest(`/api/products/${prodTwo.id}`);
  const getProd = resGet.body.product || resGet.body.data;
  console.log(`GET /api/products/${prodTwo.id}: status ${resGet.status}, images count: ${getProd.images?.length}`);

  if (getProd.images.length !== 4) {
    throw new Error(`Expected 4 images in GET product, got: ${getProd.images?.length}`);
  }

  // GET /api/products/:id/images
  const resGetImgs = await makeRequest(`/api/products/${prodTwo.id}/images`);
  console.log(`GET /api/products/${prodTwo.id}/images: status ${resGetImgs.status}, images count: ${resGetImgs.body.images?.length}`);

  // Test downloading the images directly via HTTP GET
  for (let i = 0; i < getProd.images.length; i++) {
    const imgUrl = getProd.images[i];
    const path = new URL(imgUrl).pathname;
    const imgRes = await makeRequest(path);
    if (imgRes.status !== 200 || imgRes.rawLength === 0) {
      throw new Error(`Failed to load uploaded image ${i + 1} from ${imgUrl}: status ${imgRes.status}`);
    }
    console.log(`✓ Image ${i + 1} (${path}): HTTP 200 OK (${imgRes.rawLength} bytes)`);
  }

  console.log('\n=== ALL SELLER PRODUCT IMAGE TESTS PASSED SUCCESSFULLY! ===');
}

run().catch(err => {
  console.error('\n❌ Test Failure:', err);
  process.exit(1);
});
