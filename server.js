const express = require('express');
const cors = require('cors');
const mysql = require('mysql2/promise');
const bcrypt = require('bcryptjs');
const jwt = require('jsonwebtoken');
require('dotenv').config();

const app = express();
const PORT = process.env.PORT || 8000;
const JWT_SECRET = process.env.JWT_SECRET || 'venter_jwt_secret_key_987654321';

// Middleware
app.use(cors());
app.use(express.json());
app.use(express.urlencoded({ extended: true }));

// Database Connection Pool
const pool = mysql.createPool({
  host: process.env.DB_HOST || '127.0.0.1',
  port: parseInt(process.env.DB_PORT || '3306', 10),
  user: process.env.DB_USERNAME || 'root',
  password: process.env.DB_PASSWORD || '',
  database: process.env.DB_DATABASE || 'venter_app',
  waitForConnections: true,
  connectionLimit: 10,
  queueLimit: 0
});

// Helper: safe seller output
function safeSeller(user) {
  return {
    id: user.id,
    seller_name: user.seller_name || user.name,
    phone: user.phone || null,
    address: user.address || null,
    landmark: user.landmark || null,
    state: user.state || null,
    country: user.country || null
  };
}

// Middleware: Authenticate Bearer Token
async function authenticateToken(req, res, next) {
  const authHeader = req.headers['authorization'];
  if (!authHeader) {
    return res.status(401).json({ message: 'Unauthenticated.' });
  }

  const token = authHeader.replace(/^Bearer\s+/i, '');
  if (!token) {
    return res.status(401).json({ message: 'Unauthenticated.' });
  }

  // Support both JWT tokens and legacy personal_access_tokens
  try {
    const decoded = jwt.verify(token, JWT_SECRET);
    const [rows] = await pool.query('SELECT * FROM users WHERE id = ? LIMIT 1', [decoded.id]);
    if (rows.length === 0) {
      return res.status(401).json({ message: 'Unauthenticated.' });
    }
    req.user = rows[0];
    return next();
  } catch (err) {
    // If not valid JWT, check personal_access_tokens table
    const parts = token.split('|');
    if (parts.length === 2) {
      const crypto = require('crypto');
      const hash = crypto.createHash('sha256').update(parts[1]).digest('hex');
      const [tokenRows] = await pool.query(
        'SELECT u.* FROM personal_access_tokens pat JOIN users u ON pat.tokenable_id = u.id WHERE pat.id = ? AND pat.token = ? LIMIT 1',
        [parts[0], hash]
      );
      if (tokenRows.length > 0) {
        req.user = tokenRows[0];
        return next();
      }
    }
    return res.status(401).json({ message: 'Unauthenticated.' });
  }
}

// ==========================================
// 1. Root & Health
// ==========================================

app.get('/', (req, res) => {
  res.send(`
    <!doctype html>
    <html lang="en">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title>Venter App Backend (Node.js)</title>
      <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0f172a; color: #f8fafc; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        .card { background: #1e293b; padding: 40px; border-radius: 16px; border: 1px solid #334155; max-width: 520px; width: 90%; text-align: center; box-shadow: 0 20px 40px rgba(0,0,0,0.3); }
        h1 { color: #38bdf8; margin-top: 0; }
        p { color: #94a3b8; line-height: 1.6; }
        .badge { display: inline-block; background: #064e3b; color: #34d399; font-size: 13px; font-weight: 700; padding: 6px 14px; border-radius: 9999px; margin-bottom: 20px; }
        .btn { display: inline-block; background: #6366f1; color: white; text-decoration: none; padding: 12px 24px; border-radius: 8px; font-weight: 700; margin-top: 20px; }
        .btn:hover { background: #4f46e5; }
        .endpoints { text-align: left; background: #0f172a; padding: 16px; border-radius: 8px; margin-top: 24px; font-family: monospace; font-size: 13px; color: #cbd5e1; }
      </style>
    </head>
    <body>
      <div class="card">
        <span class="badge">● Node.js + PHP Dual Engine Active</span>
        <h1>Venter App Backend</h1>
        <p>Full REST API & Admin Panel running with high-concurrency Node.js support.</p>
        <a href="/admin/login" class="btn">Go to Admin Dashboard</a>
        <div class="endpoints">
          <strong>Available Endpoints:</strong><br>
          • GET /api/health<br>
          • POST /api/register<br>
          • POST /api/login<br>
          • GET /api/products<br>
          • GET /admin/login
        </div>
      </div>
    </body>
    </html>
  `);
});

app.get('/api/health', (req, res) => {
  res.json({
    status: 'ok',
    app: 'Venter App API',
    engine: 'Node.js (Express)'
  });
});

// ==========================================
// 2. Authentication API
// ==========================================

app.get('/api/register', (req, res) => {
  res.status(405).json({
    message: 'The register endpoint only accepts POST requests with seller details (seller_name, phone, address, landmark, state, country, password, password_confirmation).'
  });
});

app.post('/api/register', async (req, res) => {
  const { seller_name, phone, address, landmark, state, country, password, password_confirmation } = req.body;
  const errors = {};

  if (!seller_name) errors.seller_name = ['The seller name field is required.'];
  if (!phone) errors.phone = ['The phone field is required.'];
  else if (!/^\+[1-9]\d{7,14}$/.test(phone)) errors.phone = ['The phone format is invalid (must include country code, e.g. +919876543210).'];
  if (!address) errors.address = ['The address field is required.'];
  if (!landmark) errors.landmark = ['The landmark field is required.'];
  if (!state) errors.state = ['The state field is required.'];
  if (!country) errors.country = ['The country field is required.'];
  if (!password) errors.password = ['The password field is required.'];
  else if (password.length < 8) errors.password = ['The password must be at least 8 characters.'];
  else if (password !== password_confirmation) errors.password = ['The password confirmation does not match.'];

  if (Object.keys(errors).length > 0) {
    return res.status(422).json({ message: 'Validation error occurred.', errors });
  }

  try {
    const [existing] = await pool.query('SELECT id FROM users WHERE phone = ? LIMIT 1', [phone]);
    if (existing.length > 0) {
      return res.status(422).json({
        message: 'The phone has already been taken.',
        errors: { phone: ['The phone has already been taken.'] }
      });
    }

    const hashedPassword = await bcrypt.hash(password, 10);
    const phoneDigits = phone.replace(/\D/g, '');
    const email = `seller_${phoneDigits}@phone.venter.local`;

    const [result] = await pool.query(
      `INSERT INTO users (name, email, seller_name, phone, address, landmark, state, country, password, role, created_at, updated_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'seller', NOW(), NOW())`,
      [seller_name, email, seller_name, phone, address, landmark, state, country || 'India', hashedPassword]
    );

    const userId = result.insertId;
    const token = jwt.sign({ id: userId, phone }, JWT_SECRET, { expiresIn: '30d' });

    res.status(201).json({
      message: 'Seller registration successful.',
      user: safeSeller({ id: userId, seller_name, phone, address, landmark, state, country }),
      token,
      token_type: 'Bearer'
    });
  } catch (err) {
    console.error('Registration Error:', err);
    res.status(500).json({ error: 'Database error occurred during registration.' });
  }
});

app.get('/api/login', (req, res) => {
  res.status(405).json({
    message: 'The login endpoint only accepts POST requests with credentials (phone, password).'
  });
});

app.post('/api/login', async (req, res) => {
  const { phone, password } = req.body;
  if (!phone || !password) {
    return res.status(422).json({
      message: 'Validation error occurred.',
      errors: {
        ...(!phone ? { phone: ['The phone field is required.'] } : {}),
        ...(!password ? { password: ['The password field is required.'] } : {})
      }
    });
  }

  try {
    const [rows] = await pool.query('SELECT * FROM users WHERE phone = ? LIMIT 1', [phone]);
    if (rows.length === 0) {
      return res.status(422).json({
        message: 'The supplied phone number or password is incorrect.',
        errors: { phone: ['The supplied phone number or password is incorrect.'] }
      });
    }

    const user = rows[0];
    const match = await bcrypt.compare(password, user.password);
    if (!match) {
      return res.status(422).json({
        message: 'The supplied phone number or password is incorrect.',
        errors: { phone: ['The supplied phone number or password is incorrect.'] }
      });
    }

    const token = jwt.sign({ id: user.id, phone: user.phone }, JWT_SECRET, { expiresIn: '30d' });

    res.json({
      message: 'Login successful.',
      user: safeSeller(user),
      token,
      token_type: 'Bearer'
    });
  } catch (err) {
    console.error('Login Error:', err);
    res.status(500).json({ error: 'Database error occurred during login.' });
  }
});

app.get('/api/me', authenticateToken, (req, res) => {
  res.json({ user: safeSeller(req.user) });
});

app.post('/api/logout', authenticateToken, (req, res) => {
  res.json({ message: 'Logout successful.' });
});

app.post('/api/forgot-password', async (req, res) => {
  const { phone } = req.body;
  if (!phone) return res.status(422).json({ errors: { phone: ['Phone is required.'] } });

  const [rows] = await pool.query('SELECT id FROM users WHERE phone = ? LIMIT 1', [phone]);
  if (rows.length > 0) {
    const otp = Math.floor(100000 + Math.random() * 900000).toString();
    const hash = await bcrypt.hash(otp, 10);
    await pool.query('DELETE FROM password_reset_otps WHERE phone = ?', [phone]);
    await pool.query('INSERT INTO password_reset_otps (phone, token, created_at) VALUES (?, ?, NOW())', [phone, hash]);
  }
  res.json({ message: 'If this phone number is registered, an OTP will be sent.' });
});

app.post('/api/verify-otp', async (req, res) => {
  const { phone, code } = req.body;
  if (!phone || !code) return res.status(422).json({ message: 'Phone and code are required.' });

  const [rows] = await pool.query('SELECT * FROM password_reset_otps WHERE phone = ? ORDER BY id DESC LIMIT 1', [phone]);
  if (rows.length === 0 || !(await bcrypt.compare(code, rows[0].token))) {
    return res.status(422).json({ message: 'Invalid or expired OTP code.' });
  }

  const resetToken = require('crypto').randomBytes(32).toString('hex');
  const hashedReset = await bcrypt.hash(resetToken, 10);
  await pool.query('UPDATE password_reset_otps SET token = ? WHERE id = ?', [hashedReset, rows[0].id]);

  res.json({ message: 'OTP verified successfully.', reset_token: resetToken });
});

app.post('/api/reset-password', async (req, res) => {
  const { phone, reset_token, password } = req.body;
  if (!phone || !reset_token || !password) return res.status(422).json({ message: 'All fields required.' });

  const [rows] = await pool.query('SELECT * FROM password_reset_otps WHERE phone = ? ORDER BY id DESC LIMIT 1', [phone]);
  if (rows.length === 0 || !(await bcrypt.compare(reset_token, rows[0].token))) {
    return res.status(422).json({ message: 'Invalid or expired reset token.' });
  }

  const hashed = await bcrypt.hash(password, 10);
  await pool.query('UPDATE users SET password = ?, updated_at = NOW() WHERE phone = ?', [hashed, phone]);
  await pool.query('DELETE FROM password_reset_otps WHERE phone = ?', [phone]);

  res.json({ message: 'Password reset successful. Please log in again.' });
});

// ==========================================
// 3. Products Catalog & CRUD
// ==========================================

app.get('/api/products', async (req, res) => {
  const page = Math.max(1, parseInt(req.query.page || '1', 10));
  const limit = 20;
  const offset = (page - 1) * limit;

  try {
    const [[{ total }]] = await pool.query('SELECT COUNT(*) as total FROM products WHERE is_active = 1');
    const [products] = await pool.query(
      'SELECT id, seller_id, name, description, price, stock, image_url, is_active, created_at, updated_at FROM products WHERE is_active = 1 ORDER BY id DESC LIMIT ? OFFSET ?',
      [limit, offset]
    );

    const formatted = products.map(p => ({
      ...p,
      price: parseFloat(p.price).toFixed(2),
      is_active: Boolean(p.is_active)
    }));

    res.json({
      products: {
        current_page: page,
        data: formatted,
        total,
        per_page: limit,
        last_page: Math.ceil(total / limit)
      }
    });
  } catch (err) {
    console.error('Products List Error:', err);
    res.status(500).json({ error: 'Failed to fetch products.' });
  }
});

app.get('/api/products/:id', async (req, res) => {
  try {
    const [rows] = await pool.query('SELECT * FROM products WHERE id = ? AND is_active = 1 LIMIT 1', [req.params.id]);
    if (rows.length === 0) {
      return res.status(404).json({ message: 'Product not found.' });
    }
    const product = rows[0];
    product.price = parseFloat(product.price).toFixed(2);
    product.is_active = Boolean(product.is_active);
    res.json({ product });
  } catch (err) {
    res.status(500).json({ error: 'Database error.' });
  }
});

app.post('/api/products', authenticateToken, async (req, res) => {
  const { name, description, price, stock, image_url } = req.body;
  if (!name || price === undefined || stock === undefined) {
    return res.status(422).json({ message: 'Name, price and stock are required.' });
  }

  try {
    const [result] = await pool.query(
      'INSERT INTO products (seller_id, name, description, price, stock, image_url, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), NOW())',
      [req.user.id, name, description || null, parseFloat(price), parseInt(stock, 10), image_url || null]
    );
    const [rows] = await pool.query('SELECT * FROM products WHERE id = ?', [result.insertId]);
    const product = rows[0];
    product.price = parseFloat(product.price).toFixed(2);
    product.is_active = Boolean(product.is_active);

    res.status(201).json({ message: 'Product created.', product });
  } catch (err) {
    console.error('Create Product Error:', err);
    res.status(500).json({ error: 'Failed to create product.' });
  }
});

app.put('/api/products/:id', authenticateToken, async (req, res) => {
  const [rows] = await pool.query('SELECT * FROM products WHERE id = ? AND seller_id = ? LIMIT 1', [req.params.id, req.user.id]);
  if (rows.length === 0) return res.status(404).json({ message: 'Product not found or access denied.' });

  const { name, description, price, stock, image_url, is_active } = req.body;
  const updates = [];
  const params = [];

  if (name !== undefined) { updates.push('name = ?'); params.push(name); }
  if (description !== undefined) { updates.push('description = ?'); params.push(description); }
  if (price !== undefined) { updates.push('price = ?'); params.push(parseFloat(price)); }
  if (stock !== undefined) { updates.push('stock = ?'); params.push(parseInt(stock, 10)); }
  if (image_url !== undefined) { updates.push('image_url = ?'); params.push(image_url); }
  if (is_active !== undefined) { updates.push('is_active = ?'); params.push(is_active ? 1 : 0); }

  if (updates.length > 0) {
    updates.push('updated_at = NOW()');
    params.push(req.params.id);
    await pool.query(`UPDATE products SET ${updates.join(', ')} WHERE id = ?`, params);
  }

  const [updated] = await pool.query('SELECT * FROM products WHERE id = ?', [req.params.id]);
  updated[0].price = parseFloat(updated[0].price).toFixed(2);
  updated[0].is_active = Boolean(updated[0].is_active);

  res.json({ message: 'Product updated.', product: updated[0] });
});

app.delete('/api/products/:id', authenticateToken, async (req, res) => {
  const [rows] = await pool.query('SELECT id FROM products WHERE id = ? AND seller_id = ? LIMIT 1', [req.params.id, req.user.id]);
  if (rows.length === 0) return res.status(404).json({ message: 'Product not found or access denied.' });

  await pool.query('DELETE FROM products WHERE id = ?', [req.params.id]);
  res.json({ message: 'Product deleted.' });
});

// ==========================================
// 4. Seller Bank Account & Payments
// ==========================================

app.post('/api/seller/bank-account/onboarding', authenticateToken, async (req, res) => {
  const { account_reference } = req.body;
  if (!account_reference) return res.status(422).json({ message: 'account_reference is required.' });

  await pool.query(
    `INSERT INTO seller_payment_accounts (seller_id, provider, account_reference, status, created_at, updated_at)
     VALUES (?, 'razorpay', ?, 'created', NOW(), NOW())
     ON DUPLICATE KEY UPDATE account_reference = VALUES(account_reference), status = 'created', updated_at = NOW()`,
    [req.user.id, account_reference]
  );

  res.json({
    message: 'Bank account reference stored successfully.',
    account: { provider: 'razorpay', account_reference, status: 'created' }
  });
});

app.get('/api/seller/bank-account/status', authenticateToken, async (req, res) => {
  const [rows] = await pool.query('SELECT * FROM seller_payment_accounts WHERE seller_id = ? LIMIT 1', [req.user.id]);
  if (rows.length === 0) {
    return res.json({ status: 'not_linked', message: 'No payment account linked yet.' });
  }
  res.json(rows[0]);
});

app.get('/api/seller/payouts', authenticateToken, (req, res) => {
  res.status(501).json({ message: 'Payout service integration is scheduled for live merchant phase.', payouts: [] });
});

app.post('/api/marketplace/commission-preview', authenticateToken, (req, res) => {
  const { amount_in_paise } = req.body;
  if (!amount_in_paise || isNaN(amount_in_paise)) {
    return res.status(422).json({ message: 'amount_in_paise is required and must be numeric.' });
  }
  const amount = parseInt(amount_in_paise, 10);
  const rate = 0.05;
  const fee = Math.round(amount * rate);
  res.json({
    order_amount_paise: amount,
    commission_rate: rate,
    commission_paise: fee,
    seller_receives_paise: amount - fee
  });
});

// ==========================================
// 5. Web Admin Panel
// ==========================================

app.get('/login', (req, res) => res.redirect('/admin/login'));

app.get('/admin/login', (req, res) => {
  res.send(`
    <!doctype html>
    <html lang="en">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title>Venter Admin — Sign In</title>
      <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f4f5fb; color: #20243a; }
        .top { background: #25204a; color: white; padding: 18px 5%; display: flex; justify-content: space-between; align-items: center; }
        .brand { font-size: 21px; font-weight: 800; }
        .wrap { max-width: 440px; margin: 60px auto; padding: 0 18px; }
        .card { background: #fff; border-radius: 14px; padding: 30px; box-shadow: 0 8px 30px rgba(37,32,74,0.08); }
        h1 { margin-top: 0; font-size: 24px; color: #1e1b4b; }
        p.muted { color: #64748b; font-size: 14px; margin-bottom: 24px; }
        label { display: block; font-size: 13px; font-weight: 600; margin: 16px 0 6px; color: #334155; }
        input { width: 100%; padding: 12px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; outline: none; }
        input:focus { border-color: #6366f1; }
        button { width: 100%; background: #5b4bd8; color: #fff; border: 0; border-radius: 8px; padding: 13px; font-size: 15px; font-weight: 700; cursor: pointer; margin-top: 24px; }
        button:hover { background: #4a3cb8; }
      </style>
    </head>
    <body>
      <header class="top"><div class="brand">Venter <span style="font-weight:400; opacity:.85;">Admin</span></div></header>
      <main class="wrap">
        <div class="card">
          <h1>Admin sign in</h1>
          <p class="muted">Sign in to manage Venter sellers and catalog.</p>
          <form method="POST" action="/admin/login">
            <label>Admin email</label>
            <input type="email" name="email" value="admin@venter.app" required autofocus>
            <label>Password</label>
            <input type="password" name="password" required>
            <button type="submit">Sign in</button>
          </form>
        </div>
      </main>
    </body>
    </html>
  `);
});

app.post('/admin/login', async (req, res) => {
  const { email, password } = req.body;
  const [rows] = await pool.query('SELECT * FROM users WHERE email = ? AND role = "admin" LIMIT 1', [email]);
  if (rows.length === 0 || !(await bcrypt.compare(password, rows[0].password))) {
    return res.status(401).send(`<h3>Invalid admin credentials.</h3><a href="/admin/login">Try again</a>`);
  }
  res.redirect('/admin');
});

app.get('/admin', async (req, res) => {
  const [[{ count }]] = await pool.query('SELECT COUNT(*) as count FROM users WHERE role = "seller"');
  const [sellers] = await pool.query('SELECT id, name, seller_name, phone, state, created_at FROM users WHERE role = "seller" ORDER BY id DESC LIMIT 50');

  res.send(`
    <!doctype html>
    <html lang="en">
    <head>
      <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
      <title>Venter Admin Dashboard</title>
      <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f4f5fb; color: #20243a; }
        .top { background: #25204a; color: white; padding: 18px 5%; display: flex; justify-content: space-between; align-items: center; }
        .brand { font-size: 21px; font-weight: 800; }
        .wrap { max-width: 1050px; margin: 32px auto; padding: 0 18px; }
        .card { background: #fff; border-radius: 14px; padding: 24px; box-shadow: 0 8px 30px rgba(37,32,74,0.06); margin-bottom: 24px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; }
        .stat { font-size: 32px; font-weight: 800; color: #5c49d8; margin-top: 6px; }
        h1, h2 { margin-top: 0; color: #1e1b4b; }
        p.muted { color: #64748b; font-size: 14px; }
        table { width: 100%; border-collapse: collapse; margin-top: 14px; }
        th, td { text-align: left; padding: 12px; border-bottom: 1px solid #f1f5f9; font-size: 14px; }
        th { color: #64748b; font-weight: 600; }
        .logout-btn { background: rgba(255,255,255,0.15); color: #fff; border: 1px solid rgba(255,255,255,0.3); padding: 8px 14px; border-radius: 6px; text-decoration: none; font-size: 13px; }
      </style>
    </head>
    <body>
      <header class="top">
        <div class="brand">Venter <span style="font-weight:400; opacity:.85;">Admin</span></div>
        <div><a href="/admin/login" class="logout-btn">Sign out</a></div>
      </header>
      <main class="wrap">
        <h1>Dashboard</h1>
        <div class="grid">
          <section class="card">
            <div style="color:#64748b; font-size:13px; font-weight:600;">Registered Sellers</div>
            <div class="stat">${count}</div>
          </section>
        </div>
        <section class="card">
          <h2>Seller Accounts</h2>
          <table>
            <thead><tr><th>ID</th><th>Seller Name</th><th>Phone</th><th>State</th><th>Created</th></tr></thead>
            <tbody>
              ${sellers.map(s => `<tr><td>#${s.id}</td><td><strong>${s.seller_name || s.name}</strong></td><td>${s.phone || '—'}</td><td>${s.state || '—'}</td><td>${String(s.created_at).slice(0, 10)}</td></tr>`).join('')}
            </tbody>
          </table>
        </section>
      </main>
    </body>
    </html>
  `);
});

// Start server
app.listen(PORT, () => {
  console.log(`Venter App Server running on http://127.0.0.1:${PORT}`);
});
