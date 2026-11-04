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
    name: 'DupTest Seller',
    email: 'duptest_' + Date.now() + '@example.com',
    phone,
    password: 'Password123!',
    password_confirmation: 'Password123!',
    role: 'seller',
    address: 'Duplication Street'
  })));
  const token = reg.body.token || reg.body.access_token;
  console.log('Registered seller, token received. ID:', reg.body.user ? reg.body.user.id : reg.body.data.id);

  const p1 = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
  const p2 = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', 'base64');

  // Test 1: Upload 2 images in POST /api/products
  const mp = buildMultipart({
    name: '2 Images Product',
    price: '250',
    stock: '10',
    category: 'Electronics'
  }, [
    { fieldname: 'images[]', filename: 'img1.png', buffer: p1 },
    { fieldname: 'images[]', filename: 'img2.png', buffer: p2 }
  ]);

  const createRes = await makeRequest('/api/products', {
    method: 'POST',
    headers: { ...mp.headers, Authorization: 'Bearer ' + token }
  }, mp.buffer);

  console.log('POST /api/products status:', createRes.status);
  const prod = createRes.body.product || createRes.body.data;
  console.log('Returned images count:', prod.images ? prod.images.length : 0);
  console.log('Returned images:', prod.images);
  console.log('Returned image_url:', prod.image_url);
  console.log('Returned gallery count:', prod.gallery ? prod.gallery.length : 0);

  // Now GET /api/products/:id
  const getRes = await makeRequest('/api/products/' + prod.id, {
    headers: { Authorization: 'Bearer ' + token }
  });
  console.log('GET /api/products/:id images count:', getRes.body.product.images.length, getRes.body.product.images);

  // Now GET /api/products/:id/images
  const getImgsRes = await makeRequest('/api/products/' + prod.id + '/images', {
    headers: { Authorization: 'Bearer ' + token }
  });
  console.log('GET /api/products/:id/images images count:', getImgsRes.body.images.length, getImgsRes.body.images);
  console.log('GET /api/products/:id/images gallery count:', getImgsRes.body.gallery.length, getImgsRes.body.gallery);
}

run().catch(console.error);
