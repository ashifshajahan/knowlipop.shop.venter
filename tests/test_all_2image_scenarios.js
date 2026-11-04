const https = require('https');
const crypto = require('crypto');

function makeRequest(path, options = {}, bodyBuffer = null) {
  return new Promise((resolve, reject) => {
    const url = new URL(path, 'https://knowlipop.shop');
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
  const phone = '+91' + Math.floor(6000000000 + Math.random() * 3999999999);
  const reg = await makeRequest('/api/register', { method: 'POST', headers: { 'Content-Type': 'application/json' } }, Buffer.from(JSON.stringify({
    name: 'Scenarios Tester',
    email: 'scenarios_' + Date.now() + '@example.com',
    phone,
    password: 'Password123!',
    password_confirmation: 'Password123!',
    role: 'seller',
    address: 'Testing Blvd'
  })));
  const token = reg.body.token || reg.body.access_token;
  console.log('Registered seller, token received.');

  const p1 = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
  const p2 = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', 'base64');

  // Scenario A: First upload 2 files via POST /api/upload
  console.log('\n--- Scenario A: Upload 2 files via /api/upload first ---');
  const upMp = buildMultipart({}, [
    { fieldname: 'images[]', filename: 'fileA.png', buffer: p1 },
    { fieldname: 'images[]', filename: 'fileB.png', buffer: p2 }
  ]);
  const upRes = await makeRequest('/api/upload', {
    method: 'POST',
    headers: { ...upMp.headers, Authorization: 'Bearer ' + token }
  }, upMp.buffer);
  console.log('/api/upload status:', upRes.status, 'returned images:', upRes.body.images);

  const url1 = upRes.body.images[0];
  const url2 = upRes.body.images[1];

  // Now create product passing image_url AND images:
  console.log('\n--- Scenario B: POST /api/products with image_url + images array ---');
  const createRes1 = await makeRequest('/api/products', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + token }
  }, Buffer.from(JSON.stringify({
    name: 'Product AB',
    price: '199',
    stock: '5',
    image_url: url1,
    images: [url1, url2]
  })));
  console.log('Create status:', createRes1.status);
  const pA = createRes1.body.product || createRes1.body.data;
  console.log('Product AB returned images count:', pA.images ? pA.images.length : 0);
  console.log('Product AB returned images:', pA.images);
  console.log('Product AB returned gallery_items count:', pA.gallery_items ? pA.gallery_items.length : 0);

  // Scenario C: Create product with 2 files directly in multipart AND body fields
  console.log('\n--- Scenario C: POST /api/products multipart 2 files + body image field ---');
  const mpCreate = buildMultipart({
    name: 'Product Multipart 2 Files',
    price: '299',
    stock: '8',
    image: url1
  }, [
    { fieldname: 'image', filename: 'dir1.png', buffer: p1 },
    { fieldname: 'images[]', filename: 'dir2.png', buffer: p2 }
  ]);
  const createRes2 = await makeRequest('/api/products', {
    method: 'POST',
    headers: { ...mpCreate.headers, Authorization: 'Bearer ' + token }
  }, mpCreate.buffer);
  console.log('Create 2 status:', createRes2.status);
  const pB = createRes2.body.product || createRes2.body.data;
  console.log('Product B returned images count:', pB.images ? pB.images.length : 0);
  console.log('Product B returned images:', pB.images);

  // Scenario D: Now update Product AB with PUT /api/products/:id
  console.log('\n--- Scenario D: PUT /api/products/:id updating stock with existing images ---');
  const putRes = await makeRequest('/api/products/' + pA.id, {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + token }
  }, Buffer.from(JSON.stringify({
    stock: 20,
    images: [url1, url2]
  })));
  console.log('PUT status:', putRes.status);
  const pAPut = putRes.body.product || putRes.body.data;
  console.log('Product AB after PUT images count:', pAPut.images ? pAPut.images.length : 0);
  console.log('Product AB after PUT images:', pAPut.images);

  // Scenario E: Update product with PUT multipart 1 new image (append test)
  console.log('\n--- Scenario E: PUT /api/products/:id multipart adding 1 image ---');
  const mpPut = buildMultipart({ stock: 25 }, [
    { fieldname: 'image', filename: 'extra.png', buffer: p1 }
  ]);
  const putRes2 = await makeRequest('/api/products/' + pA.id, {
    method: 'PUT',
    headers: { ...mpPut.headers, Authorization: 'Bearer ' + token }
  }, mpPut.buffer);
  console.log('PUT 2 status:', putRes2.status);
  const pAPut2 = putRes2.body.product || putRes2.body.data;
  console.log('Product AB after append images count:', pAPut2.images ? pAPut2.images.length : 0);
  console.log('Product AB after append images:', pAPut2.images);
}

run().catch(console.error);
