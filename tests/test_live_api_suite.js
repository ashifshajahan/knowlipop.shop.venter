const https = require('https');

const BASE_URL = 'https://knowlipop.shop';

function request(method, path, data = null, token = null) {
  return new Promise((resolve, reject) => {
    const url = new URL(path, BASE_URL);
    const options = {
      method: method,
      hostname: url.hostname,
      port: 443,
      path: url.pathname + url.search,
      headers: {
        'Accept': 'application/json',
        'User-Agent': 'VendorAppTest/1.0'
      },
      timeout: 10000
    };

    let bodyStr = null;
    if (data) {
      bodyStr = JSON.stringify(data);
      options.headers['Content-Type'] = 'application/json';
      options.headers['Content-Length'] = Buffer.byteLength(bodyStr);
    }

    if (token) {
      options.headers['Authorization'] = `Bearer ${token}`;
    }

    const req = https.request(options, (res) => {
      let resBody = '';
      res.on('data', chunk => resBody += chunk);
      res.on('end', () => {
        let parsed = null;
        try {
          parsed = JSON.parse(resBody);
        } catch (e) {
          parsed = resBody;
        }
        resolve({
          status: res.statusCode,
          headers: res.headers,
          data: parsed
        });
      });
    });

    req.on('error', err => reject(err));
    req.on('timeout', () => {
      req.destroy();
      reject(new Error(`Timeout on ${method} ${path}`));
    });

    if (bodyStr) {
      req.write(bodyStr);
    }
    req.end();
  });
}

async function testAll() {
  console.log('=== TESTING LIVE HOST: https://knowlipop.shop ===\n');

  // 1. Health
  console.log('1. GET /api/health');
  const health = await request('GET', '/api/health');
  console.log(`Status: ${health.status}`, health.data);

  // 2. Register or Login
  console.log('\n2. Testing Authentication...');
  const testPhone = '+919633426254';
  const testPassword = 'Password123!';

  // Attempt login first
  let loginRes = await request('POST', '/api/login', {
    phone: testPhone,
    password: testPassword
  });
  console.log('Login attempt status:', loginRes.status);

  let token = null;
  let sellerId = null;

  if (loginRes.status === 200 && (loginRes.data.token || (loginRes.data.data && loginRes.data.data.token))) {
    token = loginRes.data.token || loginRes.data.data.token;
    sellerId = loginRes.data.seller ? loginRes.data.seller.id : (loginRes.data.data ? loginRes.data.data.user.id : null);
    console.log(`Logged in successfully! Token: ${token.substring(0, 15)}..., Seller ID: ${sellerId}`);
  } else {
    // Try registering unique user
    const uniquePhone = '+91' + Math.floor(1000000000 + Math.random() * 9000000000);
    console.log(`Trying Register with ${uniquePhone}...`);
    const regRes = await request('POST', '/api/register', {
      store_name: 'Test Store Hostinger',
      owner_name: 'Hostinger Tester',
      email: `hostinger_${Date.now()}@example.com`,
      phone: uniquePhone,
      password: testPassword,
      address: '123 Cloud St',
      city: 'Kochi',
      state: 'Kerala',
      pincode: '682001'
    });
    console.log('Register status:', regRes.status, regRes.data);
    if (regRes.status === 200 || regRes.status === 201) {
      token = regRes.data.token || (regRes.data.data && regRes.data.data.token);
      sellerId = regRes.data.seller ? regRes.data.seller.id : (regRes.data.data ? regRes.data.data.user.id : null);
    }
  }

  if (!token) {
    console.error('Failed to obtain token from live server!');
    return;
  }

  // 3. /api/me
  console.log('\n3. GET /api/me');
  const meRes = await request('GET', '/api/me', null, token);
  console.log(`Status: ${meRes.status}`, meRes.data);
  if (!sellerId && meRes.data && meRes.data.user) {
    sellerId = meRes.data.user.id;
  }

  // 4. Products list
  console.log('\n4. GET /api/products');
  const prodsRes = await request('GET', '/api/products', null, token);
  console.log(`Status: ${prodsRes.status}`, Array.isArray(prodsRes.data) ? `Found ${prodsRes.data.length} products` : prodsRes.data);

  // 5. Seller specific orders
  console.log('\n5. GET /api/seller/orders');
  const sellerOrdersRes = await request('GET', '/api/seller/orders', null, token);
  console.log(`Status: ${sellerOrdersRes.status}`, sellerOrdersRes.data);

  if (sellerId) {
    console.log(`\n6. GET /api/sellers/${sellerId}/orders`);
    const sellerIdOrdersRes = await request('GET', `/api/sellers/${sellerId}/orders`, null, token);
    console.log(`Status: ${sellerIdOrdersRes.status}`, sellerIdOrdersRes.data);
  }

  // 7. Security check: cross-seller order access with invalid seller
  console.log('\n7. GET /api/sellers/99999/orders (Expect 403 Forbidden)');
  const crossSellerRes = await request('GET', '/api/sellers/99999/orders', null, token);
  console.log(`Status: ${crossSellerRes.status}`, crossSellerRes.data);

  // 8. Public Buyer APIs
  console.log('\n8. GET /api/buyer/home');
  const buyerHome = await request('GET', '/api/buyer/home');
  console.log(`Status: ${buyerHome.status}`, buyerHome.data ? 'OK' : 'Empty');

  console.log('\n=== LIVE TEST COMPLETE ===');
}

testAll().catch(console.error);

