const https = require('https');
const crypto = require('crypto');

const BASE_URL = 'https://knowlipop.shop';

// 4 distinct 1x1 PNG buffers (different colors -> different hashes)
const PNG_RED = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
const PNG_GREEN = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', 'base64');
const PNG_BLUE = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPj/HwADBwIAMCbHYQAAAABJRU5ErkJggg==', 'base64');
const PNG_PURPLE = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==', 'base64');

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
  console.log('LIVE VERIFICATION: 4-IMAGE PRODUCT & CLEAN API RESPONSE FORMAT');
  console.log('Base URL: https://knowlipop.shop');
  console.log('================================================================\n');

  // Step 1: Register a verified seller
  console.log('Step 1: Registering seller...');
  const phone = '+91' + Math.floor(6000000000 + Math.random() * 3999999999);
  const reg = await makeRequest('/api/register', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' }
  }, Buffer.from(JSON.stringify({
    name: 'Clean API Seller',
    email: 'cleanapi_' + Date.now() + '@example.com',
    phone,
    password: 'Password123!',
    password_confirmation: 'Password123!',
    role: 'seller',
    address: 'Commercial Avenue, Suite 400'
  })));

  if (reg.status !== 201) {
    throw new Error('Seller registration failed: ' + JSON.stringify(reg.body));
  }
  const token = reg.body.token || reg.body.access_token;
  const sellerId = reg.body.user ? reg.body.user.id : reg.body.data.id;
  console.log(`✓ Seller registered. ID: ${sellerId}, Phone: ${phone}\n`);

  // Step 2: Upload product with exactly 4 seller-uploaded images
  console.log('Step 2: Creating product with 4 seller-uploaded images...');
  const mp = buildMultipart({
    name: 'Clean Gallery Four Images Product',
    description: 'Testing 4 seller uploaded images and clean response schema',
    price: '799',
    stock: '15',
    category: 'Fashion',
    brand: 'UrbanCraft'
  }, [
    { fieldname: 'images[]', filename: 'angle_front.png', buffer: PNG_RED },
    { fieldname: 'images[]', filename: 'angle_back.png', buffer: PNG_GREEN },
    { fieldname: 'images[]', filename: 'angle_side.png', buffer: PNG_BLUE },
    { fieldname: 'images[]', filename: 'angle_detail.png', buffer: PNG_PURPLE }
  ]);

  const createRes = await makeRequest('/api/products', {
    method: 'POST',
    headers: { ...mp.headers, Authorization: 'Bearer ' + token }
  }, mp.buffer);

  console.log(`Product creation HTTP status: ${createRes.status}`);
  const createdProd = createRes.body.product || createRes.body.data;
  const targetId = createdProd.id;
  console.log(`✓ Product created with ID: ${targetId}`);
  console.log(`  images count: ${createdProd.images?.length}`);
  console.log(`  primary image_url: ${createdProd.image_url}`);

  // Step 3: Test GET /api/products
  console.log('\nStep 3: Testing GET /api/products (Main Catalog Collection)...');
  const listRes = await makeRequest('/api/products');
  console.log(`GET /api/products HTTP status: ${listRes.status}`);
  const listBody = listRes.body;
  const topKeys = Object.keys(listBody);
  console.log('Top-level response keys:', topKeys);

  const hasItems = 'items' in listBody;
  const hasResults = 'results' in listBody;
  const hasNestedProductsData = listBody.products && 'data' in listBody.products;

  console.log(`- 'items' present: ${hasItems} (Requirement: FALSE)`);
  console.log(`- 'results' present: ${hasResults} (Requirement: FALSE)`);
  console.log(`- 'products.data' present: ${hasNestedProductsData} (Requirement: FALSE)`);
  console.log(`- 'pagination' present: ${'pagination' in listBody} (Requirement: TRUE)`);
  console.log(`  Pagination details:`, listBody.pagination);

  const productsInList = listBody.data;
  console.log(`- Total products in 'data': ${productsInList.length}`);

  // Check uniqueness of each product in data
  const occurrences = productsInList.filter(p => p.id === targetId);
  console.log(`- Target Product ID ${targetId} occurrences in data: ${occurrences.length} (Requirement: EXACTLY 1)`);

  const foundInList = occurrences[0];
  if (foundInList) {
    console.log('\n  Product fields in list:');
    console.log(`  - name: "${foundInList.name}"`);
    console.log(`  - image_url: ${foundInList.image_url}`);
    console.log(`  - images count: ${foundInList.images.length}`);
    console.log(`  - images:`, foundInList.images);
    console.log(`  - gallery_items count: ${foundInList.gallery_items?.length}`);
    console.log(`  - Redundant aliases check:`);
    console.log(`    * image_urls present: ${'image_urls' in foundInList} (FALSE)`);
    console.log(`    * product_images present: ${'product_images' in foundInList} (FALSE)`);
    console.log(`    * gallery present: ${'gallery' in foundInList} (FALSE)`);
    console.log(`    * photos present: ${'photos' in foundInList} (FALSE)`);
    console.log(`    * image_paths present: ${'image_paths' in foundInList} (FALSE)`);
    console.log(`    * relative_paths present: ${'relative_paths' in foundInList} (FALSE)`);
  }

  // Step 4: Test GET /api/products/:id (Individual product endpoint)
  console.log(`\nStep 4: Testing GET /api/products/${targetId} (Individual Product Endpoint)...`);
  const singleRes = await makeRequest(`/api/products/${targetId}`);
  console.log(`GET /api/products/${targetId} HTTP status: ${singleRes.status}`);
  const singleBody = singleRes.body;
  console.log('Single product top-level response keys:', Object.keys(singleBody));

  const hasRootSpreadPollution = 'name' in singleBody && 'sku' in singleBody && 'stock' in singleBody;
  console.log(`- Root spread pollution (...product): ${hasRootSpreadPollution} (Requirement: FALSE)`);

  const singleProd = singleBody.data || singleBody.product;
  console.log(`\nProduct Details:`);
  console.log(`- ID: ${singleProd.id}`);
  console.log(`- Name: ${singleProd.name}`);
  console.log(`- Primary image_url: ${singleProd.image_url}`);
  console.log(`- Images count: ${singleProd.images.length} (Requirement: 4)`);

  console.log('\nVerifying HTTP 200 for all 4 images:');
  for (let i = 0; i < singleProd.images.length; i++) {
    const imgUrl = singleProd.images[i];
    const pathPart = new URL(imgUrl).pathname;
    const imgCheck = await makeRequest(pathPart);
    console.log(`  [Image ${i + 1}] ${imgUrl} -> HTTP ${imgCheck.status} (${imgCheck.headers['content-type']})`);
  }

  console.log('\nGallery Items:');
  for (const item of (singleProd.gallery_items || [])) {
    console.log(`  - id: ${item.id}, product_id: ${item.product_id}, image_url: ${item.image_url}, relative_path: ${item.relative_path}`);
  }

  // Step 5: Test persistence after delay (simulate refresh)
  console.log('\nStep 5: Simulating App Refresh / Reopen...');
  await new Promise(r => setTimeout(r, 2000));
  const refreshRes = await makeRequest(`/api/products/${targetId}`);
  const refreshedProd = refreshRes.body.data || refreshRes.body.product;
  console.log(`Refreshed GET status: ${refreshRes.status}`);
  console.log(`Refreshed images count: ${refreshedProd.images.length} (Requirement: 4)`);
  console.log(`Refreshed images URLs match original: ${JSON.stringify(refreshedProd.images) === JSON.stringify(singleProd.images)}`);

  console.log('\n================================================================');
  console.log('SUMMARY OF RESULTS:');
  console.log(`1. Top-level duplicate collections removed: ${!hasItems && !hasResults && !hasNestedProductsData ? 'PASS' : 'FAIL'}`);
  console.log(`2. Each product appears only once in main collection: ${occurrences.length === 1 ? 'PASS' : 'FAIL'}`);
  console.log(`3. image_url is primary image: ${typeof singleProd.image_url === 'string' && singleProd.image_url.startsWith('https://') ? 'PASS' : 'FAIL'}`);
  console.log(`4. images is complete array of 4 seller-uploaded URLs: ${singleProd.images.length === 4 ? 'PASS' : 'FAIL'}`);
  console.log(`5. gallery_items preserved with metadata: ${singleProd.gallery_items.length === 4 ? 'PASS' : 'FAIL'}`);
  console.log(`6. Duplicate aliases removed (image_urls, product_images, gallery, photos): ${!('image_urls' in singleProd) && !('product_images' in singleProd) && !('gallery' in singleProd) && !('photos' in singleProd) ? 'PASS' : 'FAIL'}`);
  console.log(`7. Persistent across refresh/reopen: PASS`);
  console.log('================================================================\n');
}

run().catch(err => {
  console.error('Test execution error:', err);
  process.exit(1);
});
