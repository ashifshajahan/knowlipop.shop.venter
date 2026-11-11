const https = require('https');
const crypto = require('crypto');

const BASE_URL = 'https://knowlipop.shop';
const PNG_BUFFER = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');

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

async function testUploadDownload() {
  const phone = '+91' + Math.floor(6000000000 + Math.random() * 3999999999);
  const reg = await makeRequest('/api/register', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' }
  }, Buffer.from(JSON.stringify({
    name: 'Upload Test Seller',
    email: 'uptest_' + Date.now() + '@example.com',
    phone,
    password: 'Password123!',
    password_confirmation: 'Password123!',
    role: 'seller',
    address: 'Market Street 123'
  })));

  console.log('Register response:', reg.status, reg.body);
  const token = reg.body.token || reg.body.access_token || reg.body.data?.token;
  console.log('Registered seller token:', !!token);

  const mp = buildMultipart({
    name: 'Upload Download Verification Product',
    price: '499',
    stock: '5',
    category: 'Test'
  }, [
    { fieldname: 'images[]', filename: 'verified_upload.png', buffer: PNG_BUFFER }
  ]);

  const createRes = await makeRequest('/api/products', {
    method: 'POST',
    headers: { ...mp.headers, Authorization: 'Bearer ' + token }
  }, mp.buffer);

  console.log('Create product status:', createRes.status);
  console.log('Create product response:', createRes.body);

  const prod = createRes.body.product || createRes.body.data;
  if (!prod || !prod.image_url) {
    console.error('No image_url returned');
    return;
  }

  console.log('Image URL returned:', prod.image_url);
  const imgRes = await makeRequest(new URL(prod.image_url).pathname);
  console.log('Direct image GET status:', imgRes.status);
  console.log('Content-Type:', imgRes.headers['content-type']);
  console.log('Body length:', typeof imgRes.body === 'string' ? imgRes.body.length : 'buffer');
}

testUploadDownload().catch(console.error);
