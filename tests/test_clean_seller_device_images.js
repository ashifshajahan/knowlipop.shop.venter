const https = require('https');
const crypto = require('crypto');

const BASE_URL = 'https://knowlipop.shop';

// Valid 1x1 PNG image buffers
const DEVICE_IMG_1 = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
const DEVICE_IMG_2 = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', 'base64');

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
        const raw = Buffer.concat(chunks);
        let parsed = null;
        try { parsed = JSON.parse(raw.toString('utf8')); }
        catch (e) { parsed = raw.toString('utf8'); }
        resolve({ status: res.statusCode, headers: res.headers, body: parsed });
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
  console.log('================================================================');
  console.log('TEST: CLEAN SELLER DEVICE PRODUCT IMAGES (ONLY `images` ARRAY)');
  console.log('Base URL: https://knowlipop.shop');
  console.log('================================================================\n');

  // Test 1: Check existing products in GET /api/products
  console.log('Test 1: Verifying GET /api/products schema cleanliness...');
  const listRes = await makeRequest('/api/products');
  console.log(`Status: ${listRes.status}`);
  const products = listRes.body.data;
  console.log(`Products returned in data: ${products.length}`);

  let anyConfusingKeysFound = false;
  for (const p of products) {
    if ('image_url' in p || 'image' in p || 'relative_path' in p || 'path' in p || 'gallery_items' in p) {
      console.error(`FAILED: Product ID ${p.id} still has confusing image keys! Keys:`, Object.keys(p).filter(k => k.includes('image') || k.includes('path') || k.includes('gallery')));
      anyConfusingKeysFound = true;
    }
  }

  if (!anyConfusingKeysFound) {
    console.log('✓ PASS: All products in /api/products have NO image_url, NO image, NO relative_path, NO gallery_items!');
  }

  // Inspect the first product
  if (products.length > 0) {
    const sample = products[0];
    console.log(`\nSample Product (${sample.name}, ID: ${sample.id}):`);
    console.log('- image_url present:', 'image_url' in sample);
    console.log('- image present:', 'image' in sample);
    console.log('- relative_path present:', 'relative_path' in sample);
    console.log('- gallery_items present:', 'gallery_items' in sample);
    console.log('- images field:', sample.images);
  }

  // Test 2: Seller adding image from device
  console.log('\n----------------------------------------------------------------');
  console.log('Test 2: Seller adding product with images from device...');
  const phone = '+91' + Math.floor(6000000000 + Math.random() * 3999999999);
  const reg = await makeRequest('/api/register', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' }
  }, Buffer.from(JSON.stringify({
    name: 'Device Seller',
    email: 'devseller_' + Date.now() + '@example.com',
    phone,
    password: 'Password123!',
    password_confirmation: 'Password123!',
    role: 'seller',
    address: 'Device Test Street 45'
  })));

  const token = reg.body.token || reg.body.access_token;
  console.log(`✓ Seller registered. Token acquired: ${!!token}`);

  const mp = buildMultipart({
    name: 'Seller Device Uploaded Watch',
    description: 'Product created with 2 photos picked from seller device',
    price: '1500',
    stock: '8',
    category: 'Watches'
  }, [
    { fieldname: 'images[]', filename: 'camera_shot_front.png', buffer: DEVICE_IMG_1 },
    { fieldname: 'images[]', filename: 'camera_shot_back.png', buffer: DEVICE_IMG_2 }
  ]);

  const createRes = await makeRequest('/api/products', {
    method: 'POST',
    headers: { ...mp.headers, Authorization: 'Bearer ' + token }
  }, mp.buffer);

  console.log(`Product creation status: ${createRes.status}`);
  const created = createRes.body.product || createRes.body.data;
  console.log(`Created Product ID: ${created.id}`);
  console.log(`Created Product images:`, created.images);
  console.log('- image_url present:', 'image_url' in created);
  console.log('- image present:', 'image' in created);
  console.log('- relative_path present:', 'relative_path' in created);
  console.log('- gallery_items present:', 'gallery_items' in created);

  // Test 3: Download uploaded files from server to verify storage
  console.log('\n----------------------------------------------------------------');
  console.log('Test 3: Verifying images stored on server and return HTTP 200...');
  for (let i = 0; i < created.images.length; i++) {
    const imgUrl = created.images[i];
    const pathPart = new URL(imgUrl).pathname;
    const check = await makeRequest(pathPart);
    console.log(`  [Image ${i + 1}] ${imgUrl} -> HTTP ${check.status} (${check.headers['content-type']})`);
  }

  // Test 4: Re-query individual product (simulate page refresh / app reopen)
  console.log('\n----------------------------------------------------------------');
  console.log('Test 4: Simulating page refresh / app reopening (GET /api/products/:id)...');
  const getSingle = await makeRequest(`/api/products/${created.id}`);
  console.log(`GET /api/products/${created.id} status: ${getSingle.status}`);
  const fetched = getSingle.body.data || getSingle.body.product;
  console.log(`Fetched Product images count: ${fetched.images?.length}`);
  console.log(`Fetched Product images array:`, fetched.images);
  console.log('- image_url present:', 'image_url' in fetched);
  console.log('- image present:', 'image' in fetched);
  console.log('- relative_path present:', 'relative_path' in fetched);
  console.log('- gallery_items present:', 'gallery_items' in fetched);

  console.log('\n================================================================');
  console.log('RESULTS:');
  console.log(`1. image_url removed: ${!('image_url' in fetched) ? 'PASS' : 'FAIL'}`);
  console.log(`2. image removed: ${!('image' in fetched) ? 'PASS' : 'FAIL'}`);
  console.log(`3. relative_path / image.path removed: ${!('relative_path' in fetched) ? 'PASS' : 'FAIL'}`);
  console.log(`4. gallery_items removed: ${!('gallery_items' in fetched) ? 'PASS' : 'FAIL'}`);
  console.log(`5. images contains strictly seller device uploaded images: ${fetched.images.length === 2 ? 'PASS' : 'FAIL'}`);
  console.log('================================================================\n');
}

run().catch(err => {
  console.error('Test error:', err);
  process.exit(1);
});
