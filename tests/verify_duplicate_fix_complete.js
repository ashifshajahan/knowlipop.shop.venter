const https = require('https');
const crypto = require('crypto');

const BASE_URL = 'https://knowlipop.shop';

// Valid 1x1 PNG buffers with distinct colors to generate distinct SHA-256 hashes
const PNG_RED = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
const PNG_GREEN = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', 'base64');
const PNG_BLUE = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPj/HwADBwIAMCbHYQAAAABJRU5ErkJggg==', 'base64');
const PNG_YELLOW = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==', 'base64');

function makeRequest(path, options = {}, bodyBuffer = null) {
  return new Promise((resolve, reject) => {
    const url = new URL(path, BASE_URL);
    const req = https.request({
      method: options.method || 'GET',
      hostname: url.hostname,
      port: 443,
      path: url.pathname + url.search,
      headers: options.headers || {}
    }, (res) => {
      const chunks = [];
      res.on('data', c => chunks.push(c));
      res.on('end', () => {
        const raw = Buffer.concat(chunks).toString('utf8');
        try { resolve({ status: res.statusCode, body: JSON.parse(raw) }); }
        catch (e) { resolve({ status: res.statusCode, body: raw }); }
      });
    });
    req.on('error', reject);
    if (bodyBuffer) req.write(bodyBuffer);
    req.end();
  });
}

function buildMultipart(fields, files) {
  const boundary = '----WebKitFormBoundary' + crypto.randomBytes(16).toString('hex');
  const chunks = [];
  for (const [k, v] of Object.entries(fields)) {
    chunks.push(Buffer.from(`--${boundary}\r\nContent-Disposition: form-data; name="${k}"\r\n\r\n${v}\r\n`));
  }
  for (const f of files) {
    chunks.push(Buffer.from(`--${boundary}\r\nContent-Disposition: form-data; name="${f.fieldname}"; filename="${f.filename}"\r\nContent-Type: image/png\r\n\r\n`));
    chunks.push(f.buffer);
    chunks.push(Buffer.from('\r\n'));
  }
  chunks.push(Buffer.from(`--${boundary}--\r\n`));
  const buf = Buffer.concat(chunks);
  return { buffer: buf, headers: { 'Content-Type': `multipart/form-data; boundary=${boundary}`, 'Content-Length': buf.length } };
}

async function run() {
  console.log('=== VERIFY DUPLICATE IMAGES FIX ===\n');

  // Register seller
  const phone = '+91' + Math.floor(6000000000 + Math.random() * 3999999999);
  const reg = await makeRequest('/api/register', { method: 'POST', headers: { 'Content-Type': 'application/json' } }, Buffer.from(JSON.stringify({
    name: 'ZeroDup Seller',
    email: 'zerodup_' + Date.now() + '@example.com',
    phone,
    password: 'Password123!',
    password_confirmation: 'Password123!',
    role: 'seller',
    address: 'Zero Duplicate Road'
  })));
  const token = reg.body.token || reg.body.access_token;
  console.log('✓ Registered seller ID:', reg.body.user ? reg.body.user.id : reg.body.data.id);

  // TEST 1: Seller uploads 2 images via multipart, where mobile app sends both 'image' (primary) and 'images[]'
  console.log('\n--- TEST 1: Mobile App sends 2 images via multipart with field `image` + `images[]` ---');
  const mp1 = buildMultipart({
    name: 'Two Images Product Test',
    price: '499',
    stock: '15',
    category: 'Footwear'
  }, [
    { fieldname: 'image', filename: 'red.png', buffer: PNG_RED },          // image 1 (primary copy)
    { fieldname: 'images[]', filename: 'red_copy.png', buffer: PNG_RED },  // image 1 (duplicate in array)
    { fieldname: 'images[]', filename: 'green.png', buffer: PNG_GREEN }    // image 2
  ]);

  const res1 = await makeRequest('/api/products', {
    method: 'POST',
    headers: { ...mp1.headers, Authorization: 'Bearer ' + token }
  }, mp1.buffer);

  console.log('Status:', res1.status);
  const p1 = res1.body.product || res1.body.data;
  console.log('Images count:', p1.images.length);
  console.log('Images array:', p1.images);
  console.log('Gallery count:', p1.gallery.length);
  console.log('Gallery items count:', p1.gallery_items.length);
  if (p1.images.length === 2) {
    console.log('>>> PASS: Exactly 2 images returned! Duplicate copy of image 1 was eliminated!');
  } else {
    console.error('>>> FAIL: Expected 2 images, got ' + p1.images.length);
    process.exit(1);
  }

  // TEST 2: GET /api/products/:id verification
  console.log('\n--- TEST 2: GET /api/products/:id verifies strictly 2 non-duplicate images ---');
  const getRes = await makeRequest('/api/products/' + p1.id, {
    headers: { Authorization: 'Bearer ' + token }
  });
  const fetchedP = getRes.body.product || getRes.body.data;
  console.log('GET product images count:', fetchedP.images.length);
  console.log('GET product images:', fetchedP.images);
  if (fetchedP.images.length === 2 && fetchedP.images[0] !== fetchedP.images[1]) {
    console.log('>>> PASS: GET API returns strictly 2 distinct images!');
  } else {
    console.error('>>> FAIL: GET API duplicate detected!');
    process.exit(1);
  }

  // TEST 3: GET /api/products/:id/images verification
  console.log('\n--- TEST 3: GET /api/products/:id/images verifies images & gallery ---');
  const getImgsRes = await makeRequest('/api/products/' + p1.id + '/images', {
    headers: { Authorization: 'Bearer ' + token }
  });
  console.log('GET /images images count:', getImgsRes.body.images.length);
  console.log('GET /images gallery count:', getImgsRes.body.gallery.length);
  if (getImgsRes.body.images.length === 2 && getImgsRes.body.gallery.length === 2) {
    console.log('>>> PASS: Image retrieval endpoint returns strictly 2 non-duplicate items!');
  } else {
    console.error('>>> FAIL: GET /images returned duplicates!');
    process.exit(1);
  }

  // TEST 4: Seller uploads 4 images: all 4 must be displayed!
  console.log('\n--- TEST 4: Seller uploads 4 images (with primary field `image` duplicate) ---');
  const mp4 = buildMultipart({
    name: 'Four Images Product Test',
    price: '999',
    stock: '20',
    category: 'Electronics'
  }, [
    { fieldname: 'image', filename: 'red.png', buffer: PNG_RED },          // primary copy
    { fieldname: 'images[]', filename: 'red_arr.png', buffer: PNG_RED },   // arr 1
    { fieldname: 'images[]', filename: 'green.png', buffer: PNG_GREEN },   // arr 2
    { fieldname: 'images[]', filename: 'blue.png', buffer: PNG_BLUE },     // arr 3
    { fieldname: 'images[]', filename: 'yellow.png', buffer: PNG_YELLOW }  // arr 4
  ]);

  const res4 = await makeRequest('/api/products', {
    method: 'POST',
    headers: { ...mp4.headers, Authorization: 'Bearer ' + token }
  }, mp4.buffer);

  console.log('Status:', res4.status);
  const p4 = res4.body.product || res4.body.data;
  console.log('4 Images Product count:', p4.images.length);
  console.log('4 Images Product array:', p4.images);
  if (p4.images.length === 4) {
    console.log('>>> PASS: Exactly all 4 unique images displayed without truncation or duplication!');
  } else {
    console.error('>>> FAIL: Expected 4 images, got ' + p4.images.length);
    process.exit(1);
  }

  // TEST 5: Verify 4 images on GET /api/products/:id
  console.log('\n--- TEST 5: GET /api/products/:id for 4-image product ---');
  const getRes4 = await makeRequest('/api/products/' + p4.id, {
    headers: { Authorization: 'Bearer ' + token }
  });
  const fetched4 = getRes4.body.product || getRes4.body.data;
  console.log('Fetched 4 images count:', fetched4.images.length);
  if (fetched4.images.length === 4) {
    console.log('>>> PASS: All 4 images confirmed on GET!');
  } else {
    console.error('>>> FAIL: GET 4 images failed!');
    process.exit(1);
  }

  console.log('\n========================================');
  console.log('ALL VERIFICATION TESTS PASSED 100% LIVE!');
  console.log('========================================');
}

run().catch(err => {
  console.error('Verification error:', err);
  process.exit(1);
});
