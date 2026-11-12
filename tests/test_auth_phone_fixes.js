#!/usr/bin/env node
'use strict';

if (!process.env.DB_PORT) {
  process.env.DB_PORT = '65001';
}

const fs = require('fs');
const os = require('os');
const path = require('path');
const http = require('http');
const assert = require('assert');
const { execSync, spawn } = require('child_process');
const bcrypt = require('bcryptjs');

const ROOT_DIR = path.resolve(__dirname, '..');

function sleep(ms) {
  return new Promise((r) => setTimeout(r, ms));
}

function httpRequest(method, urlStr, body = null, headers = {}) {
  return new Promise((resolve, reject) => {
    const u = new URL(urlStr);
    let bodyStr = null;
    const outHeaders = { Accept: 'application/json', ...headers };

    if (body !== null && body !== undefined) {
      if (typeof body === 'string') {
        bodyStr = body;
      } else {
        bodyStr = JSON.stringify(body);
        if (!outHeaders['Content-Type']) {
          outHeaders['Content-Type'] = 'application/json';
        }
      }
      outHeaders['Content-Length'] = String(Buffer.byteLength(bodyStr, 'utf8'));
    }

    const req = http.request(
      {
        hostname: u.hostname,
        port: u.port,
        path: u.pathname + u.search,
        method,
        headers: outHeaders
      },
      (res) => {
        const chunks = [];
        res.on('data', (c) => chunks.push(c));
        res.on('end', () => {
          const text = Buffer.concat(chunks).toString('utf8');
          let json = null;
          try {
            json = JSON.parse(text);
          } catch (e) {}
          resolve({
            status: res.statusCode,
            headers: res.headers,
            text,
            body: json
          });
        });
      }
    );

    req.on('error', reject);
    if (bodyStr !== null) req.write(bodyStr, 'utf8');
    req.end();
  });
}

async function waitForServer(urlStr, timeoutMs = 10000) {
  const start = Date.now();
  let lastErr = null;
  while (Date.now() - start < timeoutMs) {
    try {
      const res = await httpRequest('GET', urlStr);
      if (res.status > 0) return res;
    } catch (e) {
      lastErr = e;
    }
    await sleep(80);
  }
  throw new Error(`Server at ${urlStr} did not become ready: ${lastErr ? lastErr.message : 'timeout'}`);
}

function getFreePort() {
  return new Promise((resolve, reject) => {
    const srv = require('net').createServer();
    srv.unref();
    srv.on('error', reject);
    srv.listen(0, '127.0.0.1', () => {
      const { port } = srv.address();
      srv.close(() => resolve(port));
    });
  });
}

function createSeededSqliteDb(dbPath, { legacyNoNormCol = false } = {}) {
  const phpInit = `
$db = new PDO('sqlite:' . $argv[1]);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA journal_mode = WAL; PRAGMA busy_timeout = 5000;');
$normColDef = ($argv[2] === 'legacy') ? '' : "normalized_phone TEXT NULL UNIQUE,";
$db->exec("
  CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE,
    email_verified_at TEXT NULL,
    password TEXT NOT NULL,
    remember_token TEXT NULL,
    seller_name TEXT NULL,
    phone TEXT NULL UNIQUE,
    {$normColDef}
    address TEXT NULL,
    landmark TEXT NULL,
    state TEXT NULL,
    country TEXT NULL DEFAULT 'India',
    avatar_url TEXT NULL,
    role TEXT NOT NULL DEFAULT 'seller',
    account_status TEXT NOT NULL DEFAULT 'ACTIVE',
    deletion_requested_at TEXT NULL,
    deleted_at TEXT NULL,
    deleted_by INTEGER NULL,
    token_version INTEGER NOT NULL DEFAULT 1,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
  );
  CREATE TABLE personal_access_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tokenable_type TEXT NOT NULL DEFAULT 'App\\\\Models\\\\User',
    tokenable_id INTEGER NOT NULL,
    name TEXT NOT NULL DEFAULT 'vendor-app',
    token TEXT NOT NULL UNIQUE,
    abilities TEXT NULL,
    last_used_at TEXT NULL,
    expires_at TEXT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
  );
");
$adminHash = password_hash('Admin123!', PASSWORD_BCRYPT);
$sellerHash = password_hash('Password123!', PASSWORD_BCRYPT);
$stmt = $db->prepare("INSERT INTO users (id, name, email, seller_name, phone, address, state, country, password, role, account_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'ACTIVE')");
$stmt->execute([1, 'Vendor Administrator', 'admin@vendor.app', 'Administrator', '+919876543210', 'HQ Tech Park', 'Kerala', 'India', $adminHash, 'admin']);
$stmt->execute([2, 'Existing Seller Two', 'seller2@vendor.app', 'Verified Seller 2', '+919633426254', 'Market Road', 'Karnataka', 'India', $sellerHash, 'seller']);
echo "OK";
`;
  const scriptFile = dbPath + '.init.php';
  fs.writeFileSync(scriptFile, '<?php\n' + phpInit);
  try {
    const out = execSync(`php "${scriptFile}" "${dbPath}" "${legacyNoNormCol ? 'legacy' : 'modern'}"`, { cwd: ROOT_DIR }).toString();
    assert.strictEqual(out.trim(), 'OK');
  } finally {
    try { fs.unlinkSync(scriptFile); } catch (e) {}
  }
}

async function startPhpServer(dbPath) {
  const port = await getFreePort();
  const proc = spawn('php', ['-S', `127.0.0.1:${port}`, 'index.php'], {
    cwd: ROOT_DIR,
    env: {
      ...process.env,
      DB_CONNECTION: 'sqlite',
      SQLITE_DB_PATH: dbPath,
      PHP_CLI_SERVER_WORKERS: '4'
    },
    stdio: ['ignore', 'pipe', 'pipe']
  });

  await waitForServer(`http://127.0.0.1:${port}/`, 10000);
  return {
    engine: 'Pure PHP',
    baseUrl: `http://127.0.0.1:${port}`,
    stop: async () => {
      if (proc.exitCode === null) {
        proc.kill();
        await sleep(80);
      }
    }
  };
}

async function startNodeServer() {
  const app = require(path.join(ROOT_DIR, 'server.js'));
  const server = http.createServer(app);
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  const { port } = server.address();
  return {
    engine: 'Node.js (Express)',
    baseUrl: `http://127.0.0.1:${port}`,
    stop: async () => {
      await new Promise((resolve) => server.close(resolve));
    }
  };
}

function assertSingleErrorNoTriplication(body, contextLabel) {
  assert.ok(body && typeof body === 'object', `${contextLabel}: expected JSON body`);
  if (body.errors && typeof body.errors === 'object') {
    const eop = body.errors.email_or_phone?.[0];
    const em = body.errors.email?.[0];
    const ph = body.errors.phone?.[0];
    const nonEmpty = [eop, em, ph].filter(Boolean);
    if (nonEmpty.length > 1) {
      const unique = new Set(nonEmpty);
      assert.strictEqual(
        unique.size,
        nonEmpty.length,
        `${contextLabel}: duplicate identical error returned across email_or_phone/email/phone: ${JSON.stringify(body.errors)}`
      );
    }
  }
}

function runPhpConcurrencyStress(dbPath, normalizedPhoneVariantPayloads) {
  const workerScript = dbPath + '.worker.php';
  fs.writeFileSync(
    workerScript,
    `<?php
require_once ${JSON.stringify(path.join(ROOT_DIR, 'helpers/response.php'))};
require_once ${JSON.stringify(path.join(ROOT_DIR, 'helpers/auth.php'))};
require_once ${JSON.stringify(path.join(ROOT_DIR, 'api/auth.php'))};
$payload = json_decode(file_get_contents('php://stdin'), true);
$_SERVER['REQUEST_METHOD'] = 'POST';
$GLOBALS['_CUSTOM_JSON_INPUT'] = $payload['body'];
$pdo = new PDO('sqlite:' . $payload['db']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA busy_timeout = 5000;');
apiRegister($pdo);
`
  );

  const runners = normalizedPhoneVariantPayloads.map((body, idx) => {
    return new Promise((resolve) => {
      const proc = spawn('php', [workerScript], { cwd: ROOT_DIR, stdio: ['pipe', 'pipe', 'pipe'] });
      let out = '';
      let err = '';
      proc.stdout.on('data', (c) => (out += c.toString()));
      proc.stderr.on('data', (c) => (err += c.toString()));
      proc.on('close', () => {
        let parsed = null;
        try { parsed = JSON.parse(out); } catch (e) {}
        resolve({ idx, parsed, raw: out, err });
      });
      proc.stdin.write(JSON.stringify({ db: dbPath, body }));
      proc.stdin.end();
    });
  });

  return Promise.all(runners).finally(() => {
    try { fs.unlinkSync(workerScript); } catch (e) {}
  });
}

async function runEngineSuite(engineCtx, sqliteDbPath = null) {
  const { engine, baseUrl } = engineCtx;
  console.log(`\n=== Running Auth & Phone Fixes Suite against [${engine}] ===`);
  let passed = 0;
  const pass = (label) => {
    passed++;
    console.log(`  ✅ [${engine}] ${label}`);
  };

  // --- Test A: Phone normalization & country-specific international validation ---
  const rIndianFormatted = await httpRequest('POST', `${baseUrl}/api/register`, {
    seller_name: 'Formatted India Shop',
    phone: '98111-22334',
    country_code: '+91',
    address: 'MG Road 10',
    state: 'Kerala',
    password: 'Password123!',
    password_confirmation: 'Password123!'
  });
  assert.strictEqual(rIndianFormatted.status, 201, `Expected 201, got ${rIndianFormatted.status}: ${rIndianFormatted.text}`);
  assert.strictEqual(rIndianFormatted.body.user.phone, '+919811122334');
  pass('Indian phone with hyphen + separate dialCode +91 normalized to +919811122334');

  const rUsIntl = await httpRequest('POST', `${baseUrl}/api/register`, {
    seller_name: 'US International Store',
    phone: '+1 (202) 555-0143',
    address: '100 Pennsylvania Ave',
    state: 'DC',
    country: 'USA',
    password: 'Password123!',
    password_confirmation: 'Password123!'
  });
  assert.strictEqual(rUsIntl.status, 201, `US intl failed: ${rUsIntl.text}`);
  assert.strictEqual(rUsIntl.body.user.phone, '+12025550143');
  pass('US international phone preserved +1 country code (+12025550143)');

  const rUkIntl = await httpRequest('POST', `${baseUrl}/api/register`, {
    seller_name: 'UK Store',
    phone: '+44 7700 900123',
    address: '221B Baker St',
    state: 'London',
    country: 'UK',
    password: 'Password123!',
    password_confirmation: 'Password123!'
  });
  assert.strictEqual(rUkIntl.status, 201, `UK intl failed: ${rUkIntl.text}`);
  assert.strictEqual(rUkIntl.body.user.phone, '+447700900123');
  pass('UK international phone preserved +44 country code (+447700900123)');

  const rInvalidIndia = await httpRequest('POST', `${baseUrl}/api/register`, {
    seller_name: 'Bad Phone Store',
    phone: '+911234567890',
    address: 'Somewhere',
    state: 'Kerala',
    password: 'Password123!',
    password_confirmation: 'Password123!'
  });
  assert.strictEqual(rInvalidIndia.status, 422);
  assert.strictEqual(rInvalidIndia.body.status, false);
  assertSingleErrorNoTriplication(rInvalidIndia.body, 'Invalid Indian phone');
  pass('Invalid Indian number (+911234567890) rejected 422 with single error');

  // --- Case 1: Registered phone + correct password across all formats = HTTP 200 ---
  const loginFormats = [
    { phone: '+919811122334' },
    { phone: '919811122334' },
    { phone: '9811122334' },
    { phone: '09811122334' },
    { phone: '+91 98111-22334' },
    { phone: '9811122334', country_code: '+91' },
    { email_or_phone: '9811122334' }
  ];
  for (const lf of loginFormats) {
    const res = await httpRequest('POST', `${baseUrl}/api/login`, {
      ...lf,
      password: 'Password123!'
    });
    assert.strictEqual(res.status, 200, `Login format ${JSON.stringify(lf)} failed: ${res.status} ${res.text}`);
    assert.ok(res.body.token, 'JWT token missing on 200 login');
    assert.strictEqual(res.body.user.phone, '+919811122334');
  }
  const meCheck = await httpRequest('GET', `${baseUrl}/api/me`, null, {
    Authorization: `Bearer ${rIndianFormatted.body.token}`
  });
  assert.strictEqual(meCheck.status, 200);
  pass('Case 1: Registered phone + correct password -> HTTP 200 across all 7 input formats & JWT verified');

  // --- Case 2: Registered phone + incorrect password -> HTTP 401 (NEVER unregistered / NEVER 3x duplicate) ---
  for (const payload of [
    { phone: '+919811122334', password: 'WrongPassword1!' },
    { phone: '9811122334', country_code: '+91', password: 'ssdd' },
    { email_or_phone: '09811122334', password: 'ssdd' }
  ]) {
    const res = await httpRequest('POST', `${baseUrl}/api/login`, payload);
    assert.strictEqual(res.status, 401, `Expected 401 for wrong password, got ${res.status}: ${res.text}`);
    assert.strictEqual(res.body.status, false);
    assert.strictEqual(res.body.error_code, 'INVALID_PASSWORD');
    assert.strictEqual(res.body.message, 'Incorrect password. Please try again.');
    assert.ok(!res.body.errors || Object.keys(res.body.errors).length === 0, 'Should not emit 3x errors object on 401');
  }
  pass('Case 2: Registered phone + wrong password -> HTTP 401 INVALID_PASSWORD (single clean error)');

  // --- Case 3: Unregistered phone -> HTTP 404 PHONE_NOT_REGISTERED (+ screenshot payload) ---
  for (const unregPayload of [
    { phone: '+917034265428', password: 'ssdd' },
    { phone: '7034265428', country_code: '+91', password: 'ssdd' },
    { email_or_phone: '7034265428', password: 'ssdd' }
  ]) {
    const res = await httpRequest('POST', `${baseUrl}/api/login`, unregPayload);
    assert.strictEqual(res.status, 404, `Expected 404 for unregistered phone, got ${res.status}: ${res.text}`);
    assert.strictEqual(res.body.status, false);
    assert.strictEqual(res.body.error_code, 'PHONE_NOT_REGISTERED');
    assert.strictEqual(res.body.message, 'This phone number is not registered. Please sign up.');
    assert.ok(!res.body.errors || Object.keys(res.body.errors).length === 0, 'Should not emit 3x errors object on 404');
  }

  // Unregistered email -> HTTP 404 EMAIL_NOT_REGISTERED
  const unregEmailRes = await httpRequest('POST', `${baseUrl}/api/login`, {
    email: 'never_registered_seller@example.com',
    password: 'Password123!'
  });
  assert.strictEqual(unregEmailRes.status, 404);
  assert.strictEqual(unregEmailRes.body.status, false);
  assert.strictEqual(unregEmailRes.body.error_code, 'EMAIL_NOT_REGISTERED');
  assertSingleErrorNoTriplication(unregEmailRes.body, 'Unregistered email');

  // Verify unregistered login did NOT auto-create an account!
  const probeSecondTry = await httpRequest('POST', `${baseUrl}/api/login`, {
    phone: '+917034265428',
    password: 'ssdd'
  });
  assert.strictEqual(probeSecondTry.status, 404);
  pass('Case 3: Unregistered phone -> HTTP 404 PHONE_NOT_REGISTERED (zero auto-creation, zero duplicate errors)');

  // Validation single-error test (no 3x errors across email_or_phone, email, phone)
  const badIdentifierRes = await httpRequest('POST', `${baseUrl}/api/login`, {
    email_or_phone: 'invalid-non-email-non-phone',
    password: 'Password123!'
  });
  assert.strictEqual(badIdentifierRes.status, 422);
  assertSingleErrorNoTriplication(badIdentifierRes.body, 'Invalid login identifier');
  assert.strictEqual(Object.keys(badIdentifierRes.body.errors || {}).length, 1);
  pass('Requirement 6: Invalid login validation returns single error key (no 3x duplication)');

  // --- Case 5: Existing seller registered WITHOUT +91 -> subsequent +91 registration blocked with 409 ---
  const regNoPrefix = await httpRequest('POST', `${baseUrl}/api/register`, {
    seller_name: 'No Prefix Seller',
    phone: '9822233445',
    address: 'Street 5',
    state: 'Kerala',
    password: 'Password123!',
    password_confirmation: 'Password123!'
  });
  assert.strictEqual(regNoPrefix.status, 201);
  assert.strictEqual(regNoPrefix.body.user.phone, '+919822233445');

  for (const dupPhoneVal of ['+919822233445', '919822233445', '+91 98222-33445', '09822233445']) {
    const dupAttempt = await httpRequest('POST', `${baseUrl}/api/register`, {
      seller_name: 'Duplicate Attacker',
      phone: dupPhoneVal,
      address: 'Other Place',
      state: 'Kerala',
      password: 'Password123!',
      password_confirmation: 'Password123!'
    });
    assert.strictEqual(dupAttempt.status, 409, `Expected 409 for ${dupPhoneVal}, got ${dupAttempt.status}: ${dupAttempt.text}`);
    assert.strictEqual(dupAttempt.body.status, false);
    assert.strictEqual(dupAttempt.body.error_code, 'PHONE_ALREADY_REGISTERED');
    assert.strictEqual(dupAttempt.body.message, 'This phone number is already registered. Please log in.');
  }
  pass('Case 4 & Case 5: Existing phone registered without +91 rejects +91 / 91 / spaced / 0 variants with HTTP 409');

  // --- Case 6: Existing seller registered WITH +91 -> subsequent without +91 blocked with 409 ---
  const regWithPrefix = await httpRequest('POST', `${baseUrl}/api/register`, {
    seller_name: 'Prefixed Seller',
    phone: '+919833344556',
    address: 'Street 6',
    state: 'Karnataka',
    password: 'OriginalPass123!',
    password_confirmation: 'OriginalPass123!'
  });
  assert.strictEqual(regWithPrefix.status, 201);

  for (const dupAttemptPayload of [
    { phone: '9833344556', password: 'DifferentPass123!' },
    { phone: '9833344556', country_code: '+91', password: 'OriginalPass123!' },
    { phone: '09833344556', password: 'OriginalPass123!' },
    { phone: '91 98333 44556', password: 'OriginalPass123!' }
  ]) {
    const dupAttempt = await httpRequest('POST', `${baseUrl}/api/register`, {
      seller_name: 'Duplicate Without Prefix',
      address: 'Street 6B',
      state: 'Karnataka',
      ...dupAttemptPayload,
      password_confirmation: dupAttemptPayload.password
    });
    assert.strictEqual(dupAttempt.status, 409, `Expected 409 for ${JSON.stringify(dupAttemptPayload)}, got ${dupAttempt.status}: ${dupAttempt.text}`);
    assert.strictEqual(dupAttempt.body.status, false);
    assert.strictEqual(dupAttempt.body.error_code, 'PHONE_ALREADY_REGISTERED');
  }

  // Confirm original seller password untouched
  const origLogin = await httpRequest('POST', `${baseUrl}/api/login`, {
    phone: '9833344556',
    password: 'OriginalPass123!'
  });
  assert.strictEqual(origLogin.status, 200);
  pass('Case 6: Existing phone registered with +91 rejects non-+91 duplicates with HTTP 409 & preserves original password');

  // --- Case 7: Concurrent duplicate registration (HTTP parallel burst) ---
  const raceVariants = [
    '+919844455667',
    '9844455667',
    '919844455667',
    '09844455667',
    '+91 98444-55667',
    '98444-55667',
    '00919844455667',
    '+919844455667'
  ];
  const raceResponses = await Promise.all(
    raceVariants.map((ph, idx) =>
      httpRequest('POST', `${baseUrl}/api/register`, {
        seller_name: `Race Seller ${idx}`,
        phone: ph,
        address: 'Concurrency Ave',
        state: 'Kerala',
        password: 'Password123!',
        password_confirmation: 'Password123!'
      })
    )
  );
  const createdCount = raceResponses.filter((r) => r.status === 201).length;
  const conflictCount = raceResponses.filter((r) => r.status === 409 && r.body?.error_code === 'PHONE_ALREADY_REGISTERED').length;
  assert.strictEqual(createdCount, 1, `Expected exactly 1 created on HTTP race, got statuses: ${raceResponses.map((r) => r.status)}`);
  assert.strictEqual(conflictCount, raceVariants.length - 1, `Expected all losers to return 409, got: ${raceResponses.map((r) => r.status)}`);
  pass(`Case 7 (HTTP concurrency): ${raceVariants.length} parallel register requests -> strictly 1x 201 + ${raceVariants.length - 1}x 409`);

  // --- Case 8: Email login + Seller profile update phone duplicate guard ---
  const emailSellerReg = await httpRequest('POST', `${baseUrl}/api/register`, {
    seller_name: 'Email Login Store',
    email: 'email_seller_case8@example.com',
    address: 'Email Lane 8',
    state: 'Tamil Nadu',
    password: 'Password123!',
    password_confirmation: 'Password123!'
  });
  assert.strictEqual(emailSellerReg.status, 201);
  const emailLoginOk = await httpRequest('POST', `${baseUrl}/api/login`, {
    email: 'EMAIL_SELLER_CASE8@EXAMPLE.COM',
    password: 'Password123!'
  });
  assert.strictEqual(emailLoginOk.status, 200);
  const emailLoginWrongPw = await httpRequest('POST', `${baseUrl}/api/login`, {
    email: 'email_seller_case8@example.com',
    password: 'WrongPassword!'
  });
  assert.strictEqual(emailLoginWrongPw.status, 401);
  assert.strictEqual(emailLoginWrongPw.body.error_code, 'INVALID_PASSWORD');

  const profilePhoneConflict = await httpRequest(
    'PUT',
    `${baseUrl}/api/seller/profile`,
    { phone: '9811122334' },
    { Authorization: `Bearer ${emailLoginOk.body.token}` }
  );
  assert.strictEqual(profilePhoneConflict.status, 409, `Expected 409 on profile phone collision, got ${profilePhoneConflict.status}`);
  assert.strictEqual(profilePhoneConflict.body.error_code, 'PHONE_ALREADY_REGISTERED');
  pass('Case 8: Email login works (200 ok / 401 wrong pass) & profile update blocks duplicate phone (409)');

  // --- Case 9: Forgot password + OTP verification + password reset (unnormalized -> normalized) ---
  const resetTargetPhone = '+919855566778';
  const rResetSeller = await httpRequest('POST', `${baseUrl}/api/register`, {
    seller_name: 'Reset Flow Seller',
    phone: resetTargetPhone,
    email: 'reset_seller_case9@example.com',
    address: 'Reset St',
    state: 'Kerala',
    password: 'OldPassword123!',
    password_confirmation: 'OldPassword123!'
  });
  assert.strictEqual(rResetSeller.status, 201);

  const forgotRes = await httpRequest('POST', `${baseUrl}/api/forgot-password`, {
    phone: '98555-66778'
  });
  assert.strictEqual(forgotRes.status, 200, `Forgot password failed: ${forgotRes.text}`);
  assert.ok(forgotRes.body.dev_otp, 'Expected dev_otp in test environment');

  const verifyBadOtp = await httpRequest('POST', `${baseUrl}/api/verify-otp`, {
    phone: '9855566778',
    code: '999999'
  });
  assert.strictEqual(verifyBadOtp.status, 422);
  assert.strictEqual(verifyBadOtp.body.error_code, 'INVALID_OTP');

  const verifyRes = await httpRequest('POST', `${baseUrl}/api/verify-otp`, {
    phone: '+91 98555 66778',
    code: forgotRes.body.dev_otp
  });
  assert.strictEqual(verifyRes.status, 200, `Verify OTP failed: ${verifyRes.text}`);
  assert.ok(verifyRes.body.reset_token);

  const resetRes = await httpRequest('POST', `${baseUrl}/api/reset-password`, {
    phone: '09855566778',
    reset_token: verifyRes.body.reset_token,
    password: 'NewPassword999!',
    password_confirmation: 'NewPassword999!'
  });
  assert.strictEqual(resetRes.status, 200, `Reset password failed: ${resetRes.text}`);

  const oldPwTry = await httpRequest('POST', `${baseUrl}/api/login`, {
    phone: '9855566778',
    password: 'OldPassword123!'
  });
  assert.strictEqual(oldPwTry.status, 401);
  assert.strictEqual(oldPwTry.body.error_code, 'INVALID_PASSWORD');

  const newPwTry = await httpRequest('POST', `${baseUrl}/api/login`, {
    phone: '9855566778',
    password: 'NewPassword999!'
  });
  assert.strictEqual(newPwTry.status, 200);

  // Firebase test phone OTP check using unnormalized format 9633426254
  const fbTestVerify = await httpRequest('POST', `${baseUrl}/api/verify-otp`, {
    phone: '9633426254',
    code: '000000'
  });
  assert.strictEqual(fbTestVerify.status, 200);
  assert.ok(fbTestVerify.body.reset_token);
  pass('Case 9: Forgot password, OTP verification (incl. Firebase test number), & password reset work with normalized phones');

  // --- Case 10: Admin login via API ---
  const adminOk1 = await httpRequest('POST', `${baseUrl}/api/login`, {
    email: 'admin@vendor.app',
    password: 'Admin123!'
  });
  assert.strictEqual(adminOk1.status, 200);
  assert.strictEqual(adminOk1.body.user.role, 'admin');

  const adminOkAlias = await httpRequest('POST', `${baseUrl}/api/login`, {
    email: 'admin@venter.app',
    password: 'Admin123!'
  });
  assert.strictEqual(adminOkAlias.status, 200);
  assert.strictEqual(adminOkAlias.body.user.role, 'admin');

  const adminWrongPw = await httpRequest('POST', `${baseUrl}/api/login`, {
    email: 'admin@vendor.app',
    password: 'WrongAdminPassword!'
  });
  assert.strictEqual(adminWrongPw.status, 401);
  assert.strictEqual(adminWrongPw.body.error_code, 'INVALID_PASSWORD');
  pass('Case 10 (API): Admin login works with admin@vendor.app & admin@venter.app; wrong pass returns 401');

  // --- Extra PHP-only verifications: Admin web login (/admin/login) & multi-process concurrency ---
  if (engine === 'Pure PHP' && sqliteDbPath) {
    const adminWebOk = await httpRequest(
      'POST',
      `${baseUrl}/admin/login`,
      'email=' + encodeURIComponent('admin@vendor.app') + '&password=' + encodeURIComponent('Admin123!'),
      { 'Content-Type': 'application/x-www-form-urlencoded' }
    );
    assert.strictEqual(adminWebOk.status, 302, `Expected 302 redirect on /admin/login, got ${adminWebOk.status}`);

    const nonAdminSpoof = await httpRequest(
      'POST',
      `${baseUrl}/admin/login`,
      'email=' + encodeURIComponent('random_attacker@example.com') + '&password=' + encodeURIComponent('Admin123!'),
      { 'Content-Type': 'application/x-www-form-urlencoded' }
    );
    assert.strictEqual(nonAdminSpoof.status, 200);
    assert.ok(nonAdminSpoof.text.includes('Invalid admin credentials'), 'Non-admin email must NOT match admin row');
    pass('Case 10 (Web /admin/login): Valid admin redirects 302 & arbitrary non-admin email rejected cleanly');

    // True OS multi-process simultaneous duplicate registration on SQLite
    const osRacePayloads = ['+919877711223', '9877711223', '919877711223', '09877711223', '+91 98777-11223', '98777-11223'].map((p, i) => ({
      seller_name: `OS Race ${i}`,
      phone: p,
      address: 'Multi Process St',
      state: 'Kerala',
      password: 'Password123!',
      password_confirmation: 'Password123!'
    }));
    const osRaceResults = await runPhpConcurrencyStress(sqliteDbPath, osRacePayloads);
    const osSuccess = osRaceResults.filter((r) => r.parsed && r.parsed.token);
    const osDup = osRaceResults.filter((r) => r.parsed && r.parsed.error_code === 'PHONE_ALREADY_REGISTERED');
    assert.strictEqual(osSuccess.length, 1, `Expected 1 OS-process success, got ${JSON.stringify(osRaceResults)}`);
    assert.strictEqual(osDup.length, osRacePayloads.length - 1, `Expected all other processes 409 PHONE_ALREADY_REGISTERED`);
    pass('Case 7 (OS multi-process SQLite concurrency): 6 simultaneous processes -> strictly 1 account + 5 PHONE_ALREADY_REGISTERED');
  }

  return passed;
}

async function runMigrationVerification() {
  console.log('\n=== Running Non-Destructive DB Migration & Constraint Verification ===');
  const tmpMigDb = path.join(os.tmpdir(), `venter_mig_verify_${Date.now()}.sqlite`);
  const seedScript = tmpMigDb + '.seed.php';
  const verifyScript = tmpMigDb + '.verify.php';

  try {
    // 1. Seed a legacy table WITHOUT normalized_phone column, containing messy phones + a legacy duplicate!
    fs.writeFileSync(
      seedScript,
      `<?php
$db = new PDO('sqlite:' . $argv[1]);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("
  CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE,
    password TEXT NOT NULL,
    seller_name TEXT NULL,
    phone TEXT NULL,
    address TEXT NULL,
    state TEXT NULL,
    country TEXT NULL DEFAULT 'India',
    role TEXT NOT NULL DEFAULT 'seller',
    account_status TEXT NOT NULL DEFAULT 'ACTIVE',
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
  );
  CREATE TABLE personal_access_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tokenable_type TEXT NOT NULL DEFAULT 'App\\\\Models\\\\User',
    tokenable_id INTEGER NOT NULL,
    name TEXT NOT NULL DEFAULT 'vendor-app',
    token TEXT NOT NULL UNIQUE,
    abilities TEXT NULL,
    last_used_at TEXT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
  );
");
$pw = password_hash('LegacyPass123!', PASSWORD_BCRYPT);
$ins = $db->prepare("INSERT INTO users (id, name, email, password, seller_name, phone, address, state, country, role) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
$ins->execute([1, 'Admin', 'admin@vendor.app', $pw, 'Admin', '+919876543210', 'HQ', 'Kerala', 'India', 'admin']);
$ins->execute([2, 'Legacy Without Plus', 'leg1@vendor.app', $pw, 'Legacy 1', '9866677889', 'Addr 1', 'Kerala', 'India', 'seller']);
$ins->execute([3, 'Legacy Duplicate Formatted', 'leg2@vendor.app', $pw, 'Legacy 2 Dup', '+91 98666-77889', 'Addr 2', 'Kerala', 'India', 'seller']);
$ins->execute([4, 'Legacy Leading Zero', 'leg3@vendor.app', $pw, 'Legacy 3 Zero', '09877788990', 'Addr 3', 'Kerala', 'India', 'seller']);
$ins->execute([5, 'Legacy US Intl', 'leg4@vendor.app', $pw, 'Legacy 4 US', '+1 (202) 555-0199', 'Addr 4', 'NY', 'USA', 'seller']);
echo "SEEDED";
`
    );
    const seedOut = execSync(`php "${seedScript}" "${tmpMigDb}"`, { cwd: ROOT_DIR }).toString().trim();
    assert.strictEqual(seedOut, 'SEEDED');

    // 2. Run migration twice (testing idempotency & non-destructive duplicate handling)
    const migPath = path.join(ROOT_DIR, 'database/migrations/2026_11_11_000001_add_normalized_phone_to_users.php');
    const mig1Raw = execSync(`php "${migPath}" --sqlite="${tmpMigDb}"`, { cwd: ROOT_DIR }).toString();
    const mig1 = JSON.parse(mig1Raw);
    assert.strictEqual(mig1.status, 'ok');
    assert.strictEqual(mig1.column_existed_before, false);
    assert.strictEqual(mig1.column_present, true);
    assert.strictEqual(mig1.unique_index_present, true);
    assert.strictEqual(mig1.index_name, 'uniq_users_normalized_phone');
    assert.strictEqual(mig1.deleted_records, 0);
    assert.strictEqual(mig1.duplicate_groups.length, 1);
    assert.strictEqual(mig1.duplicate_groups[0].normalized_phone, '+919866677889');
    assert.strictEqual(mig1.duplicate_groups[0].canonical_user_id, 2);
    assert.deepStrictEqual(mig1.duplicate_groups[0].duplicate_user_ids, [3]);
    console.log('  ✅ Migration run #1 added normalized_phone + UNIQUE index without deleting legacy duplicate records');

    const mig2Raw = execSync(`php "${migPath}" --sqlite="${tmpMigDb}"`, { cwd: ROOT_DIR }).toString();
    const mig2 = JSON.parse(mig2Raw);
    assert.strictEqual(mig2.status, 'ok');
    assert.strictEqual(mig2.column_existed_before, true);
    assert.strictEqual(mig2.unique_index_existed_before, true);
    assert.strictEqual(mig2.deleted_records, 0);
    console.log('  ✅ Migration run #2 verified idempotent');

    // 3. Inspect migrated rows & test direct SQL UNIQUE index enforcement + password integrity
    fs.writeFileSync(
      verifyScript,
      `<?php
require_once ${JSON.stringify(path.join(ROOT_DIR, 'helpers/auth.php'))};
$db = new PDO('sqlite:' . $argv[1]);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$rows = $db->query("SELECT id, phone, normalized_phone, password FROM users ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$constraintBlocked = false;
try {
  $db->exec("INSERT INTO users (name, email, password, seller_name, phone, normalized_phone, role) VALUES ('Direct Dup', 'ddup@example.com', 'hash', 'Direct Dup', '+919866677889', '+919866677889', 'seller')");
} catch (PDOException $e) {
  $constraintBlocked = isDuplicateKeyExceptionPhp($e);
}
echo json_encode([
  'row_count' => count($rows),
  'rows' => array_map(fn($r) => ['id' => (int)$r['id'], 'phone' => $r['phone'], 'normalized_phone' => $r['normalized_phone'], 'pw_ok' => password_verify('LegacyPass123!', $r['password'])], $rows),
  'constraint_blocked' => $constraintBlocked
]);
`
    );
    const vOut = JSON.parse(execSync(`php "${verifyScript}" "${tmpMigDb}"`, { cwd: ROOT_DIR }).toString());
    assert.strictEqual(vOut.row_count, 5, 'All 5 legacy users must remain untouched');
    assert.strictEqual(vOut.constraint_blocked, true, 'DB UNIQUE constraint must block direct duplicate normalized_phone insert');
    assert.strictEqual(vOut.rows[1].normalized_phone, '+919866677889');
    assert.strictEqual(vOut.rows[2].normalized_phone, null);
    assert.strictEqual(vOut.rows[3].normalized_phone, '+919877788990');
    assert.strictEqual(vOut.rows[4].normalized_phone, '+12025550199');
    assert.ok(vOut.rows.every((r) => r.pw_ok === true), 'All existing passwords must remain intact');
    console.log('  ✅ Verified all 5 legacy records & password hashes preserved intact + UNIQUE index blocks duplicate SQL inserts');

    // 4. Start PHP server over migrated DB & confirm +919866677889 registration is blocked with 409
    const phpMigSrv = await startPhpServer(tmpMigDb);
    try {
      const regDupLegacy = await httpRequest('POST', `${phpMigSrv.baseUrl}/api/register`, {
        seller_name: 'New Attempt',
        phone: '+919866677889',
        address: 'Somewhere',
        state: 'Kerala',
        password: 'Password123!',
        password_confirmation: 'Password123!'
      });
      assert.strictEqual(regDupLegacy.status, 409);
      assert.strictEqual(regDupLegacy.body.error_code, 'PHONE_ALREADY_REGISTERED');

      const loginLegacyZero = await httpRequest('POST', `${phpMigSrv.baseUrl}/api/login`, {
        phone: '+919877788990',
        password: 'LegacyPass123!'
      });
      assert.strictEqual(loginLegacyZero.status, 200);
      console.log('  ✅ Migrated DB enforces 409 duplicate registration and authenticates legacy 0-prefixed user cleanly');
    } finally {
      await phpMigSrv.stop();
    }

    return 4;
  } finally {
    for (const f of [seedScript, verifyScript, tmpMigDb, tmpMigDb + '-wal', tmpMigDb + '-shm']) {
      if (fs.existsSync(f)) {
        try { fs.unlinkSync(f); } catch (e) {}
      }
    }
  }
}

async function main() {
  const phpDbPath = path.join(os.tmpdir(), `venter_auth_suite_${Date.now()}.sqlite`);
  createSeededSqliteDb(phpDbPath, { legacyNoNormCol: false });

  let totalPassed = 0;
  const phpSrv = await startPhpServer(phpDbPath);
  const nodeSrv = await startNodeServer();

  try {
    totalPassed += await runEngineSuite(phpSrv, phpDbPath);
    totalPassed += await runEngineSuite(nodeSrv, null);
    totalPassed += await runMigrationVerification();
    console.log(`\n🎉 ALL ${totalPassed} AUTH, PHONE NORMALIZATION, CONCURRENCY & MIGRATION VERIFICATION GROUPS PASSED!`);
  } finally {
    await phpSrv.stop();
    await nodeSrv.stop();
    for (const f of [phpDbPath, phpDbPath + '-wal', phpDbPath + '-shm']) {
      if (fs.existsSync(f)) {
        try { fs.unlinkSync(f); } catch (e) {}
      }
    }
  }
  process.exit(0);
}

main().catch((err) => {
  console.error('\n❌ TEST SUITE FAILED:', err);
  process.exit(1);
});
