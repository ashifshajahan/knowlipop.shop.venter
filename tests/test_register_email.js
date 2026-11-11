const https = require('https');

const BASE_HOST = 'knowlipop.shop';

function request(method, path, data = null, headers = {}) {
  return new Promise((resolve, reject) => {
    const postData = data ? (typeof data === 'string' ? data : JSON.stringify(data)) : null;
    const reqHeaders = {
      'User-Agent': 'VenterAppEmailTest/1.0',
      'Accept': 'application/json',
      ...headers
    };
    if (postData) {
      reqHeaders['Content-Type'] = 'application/json';
      reqHeaders['Content-Length'] = Buffer.byteLength(postData);
    }

    const options = {
      hostname: BASE_HOST,
      port: 443,
      path: path,
      method: method,
      headers: reqHeaders
    };

    const req = https.request(options, (res) => {
      let raw = '';
      res.on('data', chunk => raw += chunk);
      res.on('end', () => {
        let json = null;
        try {
          json = JSON.parse(raw);
        } catch (e) {
          json = { _rawText: raw };
        }
        resolve({ status: res.statusCode, headers: res.headers, body: json });
      });
    });

    req.on('error', reject);
    if (postData) req.write(postData);
    req.end();
  });
}

async function runTests() {
  console.log('=== VENTER APP REGISTER & EMAIL TEST SUITE ===\n');

  const timestamp = Date.now().toString().slice(-7);
  const testEmail1 = `vendor_${timestamp}@testingmail.org`;
  const testPhone1 = `+9198${timestamp}`;
  const testPassword = 'Password123!';

  console.log(`[TEST 1] Registering new seller with valid email (${testEmail1})...`);
  const reg1 = await request('POST', '/api/register', {
    seller_name: `Vendor Test ${timestamp}`,
    email: testEmail1,
    phone: testPhone1,
    address: 'Suite 404, Tech Plaza',
    landmark: 'Central Tower',
    state: 'Kerala',
    country: 'India',
    password: testPassword,
    password_confirmation: testPassword
  });

  console.log('Status:', reg1.status);
  console.log('Response body:', JSON.stringify(reg1.body, null, 2));

  if (reg1.status === 201 && reg1.body.user && reg1.body.user.email === testEmail1) {
    console.log('✅ TEST 1 PASSED: Seller registered and real email is returned in user object!\n');
  } else {
    console.error('❌ TEST 1 FAILED');
  }

  const token1 = reg1.body.token;

  console.log('[TEST 2] Registering with invalid email format (bad_email_str)...');
  const reg2 = await request('POST', '/api/register', {
    seller_name: `Invalid Mail Seller`,
    email: 'bad_email_str',
    phone: `+9197${timestamp}`,
    address: 'Anywhere',
    landmark: 'None',
    state: 'Kerala',
    country: 'India',
    password: testPassword,
    password_confirmation: testPassword
  });

  console.log('Status:', reg2.status);
  console.log('Errors:', JSON.stringify(reg2.body.errors || reg2.body, null, 2));
  if (reg2.status === 422 && reg2.body.errors && reg2.body.errors.email) {
    console.log('✅ TEST 2 PASSED: Invalid email format properly rejected with 422 validation error!\n');
  } else {
    console.error('❌ TEST 2 FAILED');
  }

  console.log(`[TEST 3] Duplicate email rejection check (using ${testEmail1} with different phone)...`);
  const reg3 = await request('POST', '/api/register', {
    seller_name: `Duplicate Email Attempt`,
    email: testEmail1,
    phone: `+9196${timestamp}`,
    address: 'Somewhere Else',
    landmark: 'None',
    state: 'Kerala',
    country: 'India',
    password: testPassword,
    password_confirmation: testPassword
  });

  console.log('Status:', reg3.status);
  console.log('Errors:', JSON.stringify(reg3.body.errors || reg3.body, null, 2));
  if (reg3.status === 422 && reg3.body.errors && reg3.body.errors.email) {
    console.log('✅ TEST 3 PASSED: Duplicate email correctly rejected with 422!\n');
  } else {
    console.error('❌ TEST 3 FAILED');
  }

  console.log(`[TEST 4] Logging in using registered email (${testEmail1})...`);
  const login1 = await request('POST', '/api/login', {
    email: testEmail1,
    password: testPassword
  });

  console.log('Status:', login1.status);
  console.log('Response body:', JSON.stringify(login1.body, null, 2));
  if (login1.status === 200 && login1.body.user && login1.body.user.email === testEmail1) {
    console.log('✅ TEST 4 PASSED: Seller successfully logged in using email!\n');
  } else {
    console.error('❌ TEST 4 FAILED');
  }

  console.log('[TEST 5] Registering without email (backward compatibility)...');
  const noEmailPhone = `+9195${timestamp}`;
  const reg5 = await request('POST', '/api/register', {
    seller_name: `No Email Vendor ${timestamp}`,
    phone: noEmailPhone,
    address: 'Offline Street',
    landmark: 'None',
    state: 'Kerala',
    country: 'India',
    password: testPassword,
    password_confirmation: testPassword
  });

  console.log('Status:', reg5.status);
  console.log('Response user:', JSON.stringify(reg5.body.user, null, 2));
  if (reg5.status === 201 && (!reg5.body.user.email || !reg5.body.user.email.includes('@phone.vendor.local'))) {
    console.log('✅ TEST 5 PASSED: Registration without email succeeds gracefully without dummy email leaks!\n');
  } else {
    console.error('❌ TEST 5 FAILED');
  }

  const token5 = reg5.body.token;

  console.log('[TEST 6] Updating seller profile to add an email address...');
  const newEmailForUser5 = `added_email_${timestamp}@domain.com`;
  const updateRes = await request('PUT', '/api/seller/profile', {
    email: newEmailForUser5
  }, {
    'Authorization': `Bearer ${token5}`
  });

  console.log('Status:', updateRes.status);
  console.log('Response seller:', JSON.stringify(updateRes.body.seller || updateRes.body, null, 2));
  if (updateRes.status === 200 && (updateRes.body.seller?.email === newEmailForUser5 || updateRes.body.user?.email === newEmailForUser5)) {
    console.log('✅ TEST 6 PASSED: Seller profile email update works!\n');
  } else {
    console.error('❌ TEST 6 FAILED');
  }

  console.log('=== ALL TESTS COMPLETED ===');
}

runTests().catch(err => {
  console.error('Test Suite encountered error:', err);
});

