const express = require('express');
const cors = require('cors');
const mysql = require('mysql2/promise');
const bcrypt = require('bcryptjs');
const jwt = require('jsonwebtoken');
const path = require('path');
const fs = require('fs');
const https = require('https');
const crypto = require('crypto');
const multer = require('multer');

// Attempt loading .env from candidate paths
const candidateEnvPaths = [
  path.join(__dirname, '.env'),
  path.join(__dirname, '../.env'),
  path.join(process.cwd(), '.env'),
  path.join(process.cwd(), '../.env')
];
for (const envPath of candidateEnvPaths) {
  if (fs.existsSync(envPath)) {
    require('dotenv').config({ path: envPath });
    break;
  }
}
require('dotenv').config();

// Auto-detect Hostinger production environment
const isHostinger = process.cwd().includes('u999617803') ||
                    (process.env.USER && process.env.USER.includes('u999617803')) ||
                    fs.existsSync('/home/u999617803');

// Main Host Database Configuration (Hostinger)
let dbHost = process.env.DB_HOST || '127.0.0.1';
if (dbHost === 'localhost' || dbHost === 'knowlipop.shop' || dbHost === 'https://knowlipop.shop') {
  dbHost = '127.0.0.1';
}

const candidateConfigs = [
  // 1. knowlipop1 (User's primary database on Hostinger)
  {
    database: 'u999617803_knowlipop1',
    user: 'u999617803_knowlipop1',
    password: 'Xtragclub_vendor2026'
  },
  // 2. knowlipop2
  {
    database: 'u999617803_knowlipop2',
    user: 'u999617803_knowlipop2',
    password: 'Xtragclub_vendor2026'
  },
  // 3. .env explicit values if valid (not root / not local)
  {
    database: (process.env.DB_DATABASE && !['venter_app', 'vendor_app', 'localhost'].includes(process.env.DB_DATABASE)) ? process.env.DB_DATABASE : null,
    user: (process.env.DB_USERNAME && process.env.DB_USERNAME !== 'root') ? process.env.DB_USERNAME : null,
    password: process.env.DB_PASSWORD
  },
  // 4. Previous database knolipop
  {
    database: 'u999617803_knolipop',
    user: 'u999617803_xtragclub',
    password: 'Xtragclub_vendor2026'
  }
].filter(c => c.database && c.user && c.password);

let activeConfig = candidateConfigs[0] || {
  database: 'u999617803_knowlipop1',
  user: 'u999617803_knowlipop1',
  password: 'Xtragclub_vendor2026'
};

const app = express();
const PORT = process.env.PORT || 8000;
const JWT_SECRET = process.env.JWT_SECRET || 'vendor_jwt_secret_key_987654321';

// Middleware
app.use(cors({
  origin: (origin, callback) => callback(null, true), // Dynamic origin reflection for frontend developer machines & production
  credentials: true,
  methods: ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
  allowedHeaders: ['Content-Type', 'Authorization', 'Accept', 'X-Requested-With', 'Origin']
}));
app.use(express.json({ limit: '20mb' }));
app.use(express.urlencoded({ extended: true, limit: '20mb' }));
app.use('/uploads', express.static(path.resolve(__dirname, 'uploads')));

// File Upload & Image Storage Configuration
const uploadDir = path.resolve(__dirname, 'uploads', 'products');
if (!fs.existsSync(uploadDir)) {
  fs.mkdirSync(uploadDir, { recursive: true });
}

const storage = multer.diskStorage({
  destination: (req, file, cb) => cb(null, uploadDir),
  filename: (req, file, cb) => {
    const ext = path.extname(file.originalname).toLowerCase() || '.jpg';
    cb(null, `prod_${Date.now()}_${crypto.randomBytes(4).toString('hex')}${ext}`);
  }
});
const upload = multer({
  storage,
  limits: { fileSize: 15 * 1024 * 1024 }
});

function saveBase64Image(dataString) {
  if (typeof dataString !== 'string' || !dataString.startsWith('data:image/')) return null;
  const matches = dataString.match(/^data:image\/(\w+);base64,(.+)$/);
  if (!matches) return null;
  let ext = matches[1].toLowerCase();
  if (ext === 'jpeg') ext = 'jpg';
  if (!['jpg', 'png', 'webp', 'gif', 'svg'].includes(ext)) ext = 'png';
  const buffer = Buffer.from(matches[2], 'base64');
  const filename = `prod_${Date.now()}_${crypto.randomBytes(4).toString('hex')}.${ext}`;
  fs.writeFileSync(path.join(uploadDir, filename), buffer);
  const baseUrl = process.env.APP_URL || 'https://knowlipop.shop';
  return `${baseUrl.replace(/\/$/, '')}/uploads/products/${filename}`;
}

function resolveProductImage(req) {
  const baseUrl = process.env.APP_URL || 'https://knowlipop.shop';
  // 1. Multipart uploaded file
  if (req.files && req.files.length > 0) {
    return `${baseUrl.replace(/\/$/, '')}/uploads/products/${req.files[0].filename}`;
  }
  if (req.file) {
    return `${baseUrl.replace(/\/$/, '')}/uploads/products/${req.file.filename}`;
  }
  // 2. Base64 encoded image
  const body = req.body || {};
  const base64Candidate = body.image || body.image_base64 || body.file;
  if (base64Candidate && typeof base64Candidate === 'string' && base64Candidate.startsWith('data:image/')) {
    const saved = saveBase64Image(base64Candidate);
    if (saved) return saved;
  }
  // 3. Fallback to existing URL string
  if (body.image_url && typeof body.image_url === 'string') {
    return body.image_url;
  }
  if (body.image && typeof body.image === 'string' && (body.image.startsWith('http://') || body.image.startsWith('https://') || body.image.startsWith('/uploads/'))) {
    return body.image;
  }
  return null;
}

// Avatar File Upload & Image Storage Configuration
const avatarUploadDir = path.resolve(__dirname, 'uploads', 'avatars');
if (!fs.existsSync(avatarUploadDir)) {
  fs.mkdirSync(avatarUploadDir, { recursive: true });
}

const avatarStorage = multer.diskStorage({
  destination: (req, file, cb) => cb(null, avatarUploadDir),
  filename: (req, file, cb) => {
    const ext = path.extname(file.originalname).toLowerCase() || '.jpg';
    cb(null, `avatar_${Date.now()}_${crypto.randomBytes(4).toString('hex')}${ext}`);
  }
});
const avatarUpload = multer({
  storage: avatarStorage,
  limits: { fileSize: 10 * 1024 * 1024 }
});

function resolveAvatarImage(req) {
  const baseUrl = process.env.APP_URL || 'https://knowlipop.shop';
  if (req.file) {
    return `${baseUrl.replace(/\/$/, '')}/uploads/avatars/${req.file.filename}`;
  }
  if (req.files && req.files.length > 0) {
    return `${baseUrl.replace(/\/$/, '')}/uploads/avatars/${req.files[0].filename}`;
  }
  const body = req.body || {};
  const base64Candidate = body.avatar || body.image || body.profile_image || body.avatar_base64;
  if (base64Candidate && typeof base64Candidate === 'string' && base64Candidate.startsWith('data:image/')) {
    const matches = base64Candidate.match(/^data:image\/(\w+);base64,(.+)$/);
    if (matches) {
      let ext = matches[1].toLowerCase();
      if (ext === 'jpeg') ext = 'jpg';
      if (!['jpg', 'png', 'webp', 'gif', 'svg'].includes(ext)) ext = 'png';
      const buffer = Buffer.from(matches[2], 'base64');
      const filename = `avatar_${Date.now()}_${crypto.randomBytes(4).toString('hex')}.${ext}`;
      fs.writeFileSync(path.join(avatarUploadDir, filename), buffer);
      return `${baseUrl.replace(/\/$/, '')}/uploads/avatars/${filename}`;
    }
  }
  if (body.avatar_url && typeof body.avatar_url === 'string' && (body.avatar_url.startsWith('http://') || body.avatar_url.startsWith('https://'))) {
    return body.avatar_url.trim();
  }
  return null;
}

// Database Connection Pool
let pool = mysql.createPool({
  host: dbHost,
  port: parseInt(process.env.DB_PORT || '3306', 10),
  user: activeConfig.user,
  password: activeConfig.password,
  database: activeConfig.database,
  waitForConnections: true,
  connectionLimit: 10,
  queueLimit: 0,
  multipleStatements: true,
  connectTimeout: 10000,
  enableKeepAlive: true,
  keepAliveInitialDelay: 0
});

async function autoMigrateIfEmpty(p) {
  try {
    const [tables] = await p.query('SHOW TABLES');
    if (tables.length === 0) {
      console.log('[Vendor DB] Empty database detected, running auto-migration from schema.sql...');
      const schemaPath = path.join(__dirname, 'database', 'schema.sql');
      if (fs.existsSync(schemaPath)) {
        const sql = fs.readFileSync(schemaPath, 'utf8');
        await p.query(sql);
        console.log('✅ [Vendor DB] Schema imported successfully!');
      }
    }
    // Ensure admin user exists with password Admin123!
    const [admins] = await p.query('SELECT id, password FROM users WHERE role = "admin" LIMIT 1');
    const adminHash = await bcrypt.hash('Admin123!', 10);
    if (admins.length === 0) {
      await p.query(
        `INSERT INTO users (id, name, email, password, role)
         VALUES (1, 'Vendor Administrator', 'admin@vendor.app', ?, 'admin')
         ON DUPLICATE KEY UPDATE name = VALUES(name), email = VALUES(email), password = VALUES(password), role = 'admin'`,
        [adminHash]
      );
      console.log('✅ [Vendor DB] Seeded admin user: admin@vendor.app');
    }
    // Ensure image_url column is LONGTEXT to prevent Base64/URL truncation
    try {
      await p.query('ALTER TABLE products MODIFY COLUMN image_url LONGTEXT NULL DEFAULT NULL');
    } catch (e) {
      // Already LONGTEXT or modified
    }
    // Ensure avatar_url column exists in users
    try {
      const [cols] = await p.query('SHOW COLUMNS FROM users LIKE "avatar_url"');
      if (cols.length === 0) {
        await p.query('ALTER TABLE users ADD COLUMN avatar_url LONGTEXT NULL AFTER country');
        console.log('✅ [Vendor DB] Added avatar_url column to users table');
      }
    } catch (e) {
      // Already exists or ignore
    }
  } catch (err) {
    console.error('[Vendor DB] Auto-migration check:', err.message);
  }
}

// Probe database candidates on startup
(async function probeDatabase() {
  for (const c of candidateConfigs) {
    try {
      const conn = await mysql.createConnection({
        host: dbHost,
        port: parseInt(process.env.DB_PORT || '3306', 10),
        user: c.user,
        password: c.password,
        database: c.database,
        connectTimeout: 3000
      });
      await conn.query('SELECT 1');
      await conn.end();

      console.log(`✅ [Vendor DB] Connected to Hostinger DB: ${c.database} (user: ${c.user})`);
      activeConfig = c;
      pool = mysql.createPool({
        host: dbHost,
        port: parseInt(process.env.DB_PORT || '3306', 10),
        user: c.user,
        password: c.password,
        database: c.database,
        waitForConnections: true,
        connectionLimit: 10,
        queueLimit: 0,
        multipleStatements: true
      });
      await autoMigrateIfEmpty(pool);
      return;
    } catch (e) {
      // try next candidate
    }
  }
  console.warn('[Vendor DB] Warning: Could not connect to any candidate database.');
})();

// Helper: safe seller output
function safeSeller(user) {
  const data = {
    id: user.id,
    seller_name: user.seller_name || user.name || '',
    name: user.name || user.seller_name || '',
    phone: user.phone || null,
    address: user.address || null,
    landmark: user.landmark || null,
    state: user.state || null,
    country: user.country || null,
  };

  // Email is ONLY for admin users, not for regular sellers/users
  if (user.role === 'admin') {
    if (user.email) data.email = user.email;
    data.role = 'admin';
  }

  return data;
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
      <title>Vendor App Backend (Node.js)</title>
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
        <h1>Vendor App Backend</h1>
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

app.get('/api/health', async (req, res) => {
  let dbStatus = 'disconnected';
  let dbError = null;
  try {
    await pool.query('SELECT 1');
    dbStatus = 'connected';
  } catch (err) {
    dbError = err.message;
  }

  res.status(dbStatus === 'connected' ? 200 : 503).json({
    status: dbStatus === 'connected' ? 'ok' : 'degraded',
    app: 'Vendor App API',
    engine: 'Node.js (Express)',
    database: {
      status: dbStatus,
      host: dbHost,
      name: activeConfig.database,
      user: activeConfig.user,
      ...(dbError ? { error: dbError } : {})
    }
  });
});

// ==========================================
// 2. Authentication API
// ==========================================

// Helper to extract fields case-insensitively with alias support
function extractField(body, candidates, defaultValue = '') {
  if (!body || typeof body !== 'object') return defaultValue;
  for (const c of candidates) {
    if (body[c] !== undefined && body[c] !== null && String(body[c]).trim() !== '') {
      return String(body[c]).trim();
    }
  }
  // Case-insensitive & normalized search
  const keys = Object.keys(body);
  for (const c of candidates) {
    const cleanCandidate = c.toLowerCase().replace(/[^a-z0-9]/g, '');
    const matchedKey = keys.find(k => k.toLowerCase().replace(/[^a-z0-9]/g, '') === cleanCandidate);
    if (matchedKey && body[matchedKey] !== undefined && body[matchedKey] !== null && String(body[matchedKey]).trim() !== '') {
      return String(body[matchedKey]).trim();
    }
  }
  return defaultValue;
}

// Universal phone extractor supporting all frontend naming conventions
function extractPhone(body) {
  if (!body || typeof body !== 'object') return '';

  // 1. If phone is an object: { number, country_code }
  for (const k of ['phone', 'mobile', 'contact', 'telephone']) {
    if (body[k] && typeof body[k] === 'object') {
      const num = body[k].number || body[k].phone || body[k].value || '';
      const code = body[k].country_code || body[k].countryCode || body[k].dial_code || body[k].dialCode || '+91';
      if (num) {
        return (String(num).startsWith('+') ? '' : code) + String(num);
      }
    }
  }

  // 2. Direct check with comprehensive candidate list
  const candidates = [
    'phone', 'phone_no', 'phoneNo', 'phoneno', 'phone_number', 'phoneNumber', 'phonenumber',
    'mobile', 'mobile_no', 'mobileNo', 'mobileno', 'mobile_number', 'mobileNumber',
    'contact', 'contact_no', 'contactNo', 'contactno', 'contact_number', 'contactNumber',
    'seller_phone', 'sellerPhone', 'seller_mobile', 'sellerMobile',
    'user_phone', 'userPhone', 'user_mobile', 'userMobile',
    'telephone', 'tel', 'cell', 'cellphone'
  ];

  let raw = extractField(body, candidates, '');
  if (!raw) {
    for (const key of Object.keys(body)) {
      if (/^(seller|user)?_?(phone|mobile|contact|tel)/i.test(key)) {
        const val = body[key];
        if (val !== undefined && val !== null && String(val).trim() !== '') {
          raw = String(val).trim();
          break;
        }
      }
    }
  }

  if (!raw) return '';

  // Attach separate dial/country code if passed
  const dialCode = body.country_code || body.countryCode || body.dial_code || body.dialCode;
  if (dialCode && !raw.startsWith('+')) {
    raw = String(dialCode).trim() + raw;
  }

  return raw;
}

// Universal password extractor
function extractPassword(body) {
  return extractField(body, ['password', 'pass', 'user_password', 'userPassword', 'pwd'], '').trim();
}

// Universal password confirmation extractor
function extractConfirmPassword(body, password) {
  const candidates = [
    'password_confirmation', 'confirm_password', 'confirmPassword', 'confirmpassword',
    'passwordConfirmation', 'password_confirm', 'passwordConfirm', 'confirm_pass',
    'c_password', 'cpassword', 'repeat_password', 'repeatPassword',
    're_password', 'rePassword', 'password2', 'pass2', 'confirm'
  ];

  const val = extractField(body, candidates, '').trim();
  // If not supplied, null, or empty string in payload (e.g. client validated client-side),
  // fallback to password so it doesn't fail registration!
  if (!val) {
    return password;
  }
  return val;
}

// Helper to normalize phone number (+91 formatting)
function normalizePhone(raw) {
  if (!raw) return '';
  let str = raw.toString().trim().replace(/[\s\-\(\)]/g, '');
  if (!str) return '';
  if (str.startsWith('+')) return str;
  if (/^\d{10}$/.test(str)) return '+91' + str;
  if (/^91\d{10}$/.test(str)) return '+' + str;
  return '+' + str;
}

app.get('/api/register', (req, res) => {
  res.status(405).json({
    message: 'The register endpoint only accepts POST requests with seller details (seller_name, phone, address, landmark, state, country, password, password_confirmation).'
  });
});

app.post('/api/register', async (req, res) => {
  const body = req.body || {};
  const seller_name = extractField(body, [
    'seller_name', 'sellerName', 'name', 'business_name', 'businessName',
    'seller', 'shop_name', 'shopName', 'store_name', 'storeName',
    'fullname', 'full_name', 'user_name', 'userName'
  ]);
  const rawPhone = extractPhone(body);
  const phone = normalizePhone(rawPhone);
  const address = extractField(body, [
    'address', 'shop_address', 'shopAddress', 'business_address', 'street', 'location'
  ]);
  const landmark = extractField(body, [
    'landmark', 'land_mark', 'area', 'nearby'
  ], 'None');
  const state = extractField(body, [
    'state', 'province', 'region'
  ], 'Kerala');
  const country = extractField(body, [
    'country', 'nation'
  ], 'India');

  const password = extractPassword(body);
  const password_confirmation = extractConfirmPassword(body, password);

  const errors = {};

  if (!seller_name) errors.seller_name = ['The seller name field is required.'];
  if (!rawPhone) errors.phone = ['The phone field is required.'];
  else if (!/^\+[1-9]\d{7,14}$/.test(phone)) errors.phone = ['The phone format is invalid (must include country code, e.g. +919876543210).'];
  if (!address) errors.address = ['The address field is required.'];
  if (!password) errors.password = ['The password field is required.'];
  else if (password.length < 8) errors.password = ['The password must be at least 8 characters.'];
  else if (password !== password_confirmation) errors.password = ['The password confirmation does not match.'];

  if (Object.keys(errors).length > 0) {
    return res.status(422).json({ message: 'Validation error occurred.', errors });
  }

  try {
    const rawNoPrefix = rawPhone.replace(/^\+91/, '').replace(/^\+/, '');
    const [existing] = await pool.query(
      'SELECT id, password FROM users WHERE phone = ? OR phone = ? OR phone = ? LIMIT 1',
      [phone, rawPhone, rawNoPrefix]
    );
    if (existing.length > 0) {
      // If same seller is re-submitting with matching password, return successful auth
      const isSamePassword = await bcrypt.compare(password, existing[0].password);
      if (isSamePassword) {
        const token = jwt.sign({ id: existing[0].id, phone }, JWT_SECRET, { expiresIn: '30d' });
        return res.status(200).json({
          message: 'Seller account already exists. Successfully signed in.',
          user: safeSeller({ id: existing[0].id, seller_name, phone, address, landmark, state, country }),
          token,
          token_type: 'Bearer'
        });
      }
      return res.status(422).json({
        message: 'The phone has already been taken.',
        errors: { phone: ['The phone has already been taken.'] }
      });
    }

    const hashedPassword = await bcrypt.hash(password, 10);
    const phoneDigits = phone.replace(/\D/g, '');
    const email = `seller_${phoneDigits}@phone.vendor.local`;

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
  const body = req.body || {};
  const rawPhone = extractPhone(body) || extractField(body, ['email', 'username', 'user', 'login'], '');
  const phone = normalizePhone(rawPhone);
  const password = extractPassword(body);

  if (!rawPhone || !password) {
    return res.status(422).json({
      message: 'Validation error occurred.',
      errors: {
        ...(!rawPhone ? { phone: ['The phone field is required.'] } : {}),
        ...(!password ? { password: ['The password field is required.'] } : {})
      }
    });
  }

  try {
    const rawNoPrefix = rawPhone.replace(/^\+91/, '').replace(/^\+/, '');
    const [rows] = await pool.query(
      'SELECT * FROM users WHERE phone = ? OR phone = ? OR phone = ? OR email = ? LIMIT 1',
      [phone, rawPhone, rawNoPrefix, rawPhone]
    );
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

// Profile Update Logic
async function handleSellerProfileUpdate(req, res, targetSellerId = null) {
  const currentUser = req.user;
  const sellerId = targetSellerId !== null ? parseInt(targetSellerId, 10) : currentUser.id;

  if (targetSellerId !== null && currentUser.id !== sellerId && currentUser.role !== 'admin') {
    return res.status(403).json({ message: 'Unauthorized. You can only edit your own seller profile.' });
  }

  const [existingRows] = await pool.query('SELECT * FROM users WHERE id = ? LIMIT 1', [sellerId]);
  if (existingRows.length === 0) {
    return res.status(404).json({ message: 'Seller not found.' });
  }
  const existing = existingRows[0];

  const body = req.body || {};
  const isAdmin = req.user && req.user.role === 'admin';

  // Fields to update (id is strictly immutable)
  const sellerName = extractField(body, ['seller_name', 'sellerName', 'business_name', 'shop_name', 'store_name']);
  const name = extractField(body, ['name', 'fullname', 'full_name', 'owner_name']);
  const address = extractField(body, ['address', 'shop_address', 'business_address', 'street', 'location']);
  const landmark = extractField(body, ['landmark', 'land_mark', 'nearby']);
  const state = extractField(body, ['state', 'province', 'region']);
  const country = extractField(body, ['country', 'nation']);
  const rawPhone = extractPhone(body);
  const email = (extractField(body, ['email', 'email_address']) || '').trim();

  // Separate password fields: current_password, new_password, confirm_password
  const currentPassword = extractField(body, ['current_password', 'currentPassword', 'old_password', 'oldPassword']);
  const newPassword = extractField(body, ['new_password', 'newPassword', 'password']);
  const confirmPassword = extractConfirmPassword(body, newPassword);

  const errors = {};

  let phone = '';
  if (rawPhone) {
    phone = normalizePhone(rawPhone);
    if (!/^\+[1-9]\d{7,14}$/.test(phone)) {
      errors.phone = ['The phone format is invalid (must include country code, e.g. +919876543210).'];
    } else {
      const rawNoPrefix = rawPhone.replace(/^\+91|^\+/, '');
      const [chkPhone] = await pool.query(
        'SELECT id FROM users WHERE (phone = ? OR phone = ? OR phone = ?) AND id != ? LIMIT 1',
        [phone, rawPhone, rawNoPrefix, sellerId]
      );
      if (chkPhone.length > 0) {
        errors.phone = ['This phone number is already registered to another account.'];
      }
    }
  }

  // Email is ONLY for admin users (regular sellers do not have/change email)
  if (isAdmin && email) {
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      errors.email = ['The email address format is invalid.'];
    } else {
      const [chkEmail] = await pool.query('SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1', [email, sellerId]);
      if (chkEmail.length > 0) {
        errors.email = ['This email address is already in use by another account.'];
      }
    }
  }

  // Password validation with (current_password, new_password, confirm_password)
  if (newPassword) {
    if (!isAdmin || req.user.id === sellerId) {
      if (!currentPassword) {
        errors.current_password = ['The current password field is required to change password.'];
      } else {
        const matches = await bcrypt.compare(currentPassword, existing.password);
        if (!matches) {
          errors.current_password = ['The supplied current password is incorrect.'];
        }
      }
    }
    if (newPassword.length < 8) {
      errors.new_password = ['The new password must be at least 8 characters.'];
    } else if (newPassword !== confirmPassword) {
      errors.confirm_password = ['The password confirmation does not match.'];
    }
  }

  if (Object.keys(errors).length > 0) {
    return res.status(422).json({ message: 'Validation error occurred.', errors });
  }

  const avatarUrl = resolveAvatarImage(req) || (body.avatar_url !== undefined ? body.avatar_url : null);

  const updates = [];
  const params = [];

  if (sellerName) {
    updates.push('seller_name = ?');
    params.push(sellerName);
    if (!name) {
      updates.push('name = ?');
      params.push(sellerName);
    }
  }
  if (name) {
    updates.push('name = ?');
    params.push(name);
    if (!sellerName && !existing.seller_name) {
      updates.push('seller_name = ?');
      params.push(name);
    }
  }
  if (address) {
    updates.push('address = ?');
    params.push(address);
  }
  if (landmark) {
    updates.push('landmark = ?');
    params.push(landmark);
  }
  if (state) {
    updates.push('state = ?');
    params.push(state);
  }
  if (country) {
    updates.push('country = ?');
    params.push(country);
  }
  if (phone) {
    updates.push('phone = ?');
    params.push(phone);
  }
  if (isAdmin && email) {
    updates.push('email = ?');
    params.push(email);
  }
  if (newPassword) {
    const hashed = await bcrypt.hash(newPassword, 10);
    updates.push('password = ?');
    params.push(hashed);
  }
  if (avatarUrl) {
    try {
      const [cols] = await pool.query('SHOW COLUMNS FROM users LIKE "avatar_url"');
      if (cols.length > 0) {
        updates.push('avatar_url = ?');
        params.push(avatarUrl);
      }
    } catch (e) {}
  }

  if (updates.length > 0) {
    updates.push('updated_at = NOW()');
    params.push(sellerId);
    await pool.query(`UPDATE users SET ${updates.join(', ')} WHERE id = ?`, params);
  }

  const [refreshed] = await pool.query('SELECT * FROM users WHERE id = ? LIMIT 1', [sellerId]);
  const sellerData = safeSeller(refreshed[0]);

  res.json({
    message: 'Seller profile updated successfully.',
    seller: sellerData,
    user: sellerData
  });
}

// Dedicated Change Password Handler: requires (current_password, new_password, confirm_password)
async function handleChangePassword(req, res) {
  const sellerId = req.user.id;
  const [existingRows] = await pool.query('SELECT id, password FROM users WHERE id = ? LIMIT 1', [sellerId]);
  if (existingRows.length === 0) {
    return res.status(404).json({ message: 'User not found.' });
  }
  const existing = existingRows[0];

  const body = req.body || {};
  const currentPassword = extractField(body, ['current_password', 'currentPassword', 'old_password', 'oldPassword', 'current']);
  const newPassword = extractField(body, ['new_password', 'newPassword', 'password']);
  const confirmPassword = extractConfirmPassword(body, newPassword);

  const errors = {};

  if (!currentPassword) {
    errors.current_password = ['The current password field is required.'];
  } else {
    const matches = await bcrypt.compare(currentPassword, existing.password);
    if (!matches) {
      errors.current_password = ['The supplied current password is incorrect.'];
    }
  }

  if (!newPassword) {
    errors.new_password = ['The new password field is required.'];
  } else if (newPassword.length < 8) {
    errors.new_password = ['The new password must be at least 8 characters.'];
  }

  if (!confirmPassword) {
    errors.confirm_password = ['The confirm password field is required.'];
  } else if (newPassword !== confirmPassword) {
    errors.confirm_password = ['The password confirmation does not match.'];
  }

  if (Object.keys(errors).length > 0) {
    return res.status(422).json({ message: 'Validation error occurred.', errors });
  }

  const hashed = await bcrypt.hash(newPassword, 10);
  await pool.query('UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?', [hashed, sellerId]);

  res.json({ status: 'success', message: 'Password updated successfully.' });
}

// GET /api/me (authenticated seller profile)
app.get('/api/me', authenticateToken, async (req, res) => {
  const sellerData = safeSeller(req.user);
  res.json({ status: 'success', seller: sellerData, user: sellerData });
});

app.put('/api/me', authenticateToken, avatarUpload.any(), (req, res) => handleSellerProfileUpdate(req, res, null));
app.patch('/api/me', authenticateToken, avatarUpload.any(), (req, res) => handleSellerProfileUpdate(req, res, null));

// GET /api/seller/profile (supports ?seller_id=X or ?id=X, or Bearer token)
app.get('/api/seller/profile', async (req, res) => {
  const querySellerId = req.query.seller_id || req.query.id;
  let user;

  if (querySellerId) {
    const sellerId = parseInt(querySellerId, 10);
    const [rows] = await pool.query('SELECT * FROM users WHERE id = ? LIMIT 1', [sellerId]);
    if (rows.length === 0) {
      return res.status(404).json({ message: 'Seller not found.' });
    }
    user = rows[0];
  } else {
    // Authenticate token
    const authHeader = req.headers['authorization'];
    if (!authHeader) {
      return res.status(401).json({ message: 'Unauthenticated.' });
    }
    const token = authHeader.replace(/^Bearer\s+/i, '');
    try {
      const decoded = jwt.verify(token, JWT_SECRET);
      const [rows] = await pool.query('SELECT * FROM users WHERE id = ? LIMIT 1', [decoded.id || decoded.sub]);
      if (rows.length === 0) {
        return res.status(401).json({ message: 'User not found.' });
      }
      user = rows[0];
    } catch (err) {
      return res.status(401).json({ message: 'Invalid or expired token.' });
    }
  }

  const sellerData = safeSeller(user);
  res.json({ status: 'success', seller: sellerData, user: sellerData });
});

// PUT / PATCH / POST /api/seller/profile and /api/seller/profile/update
app.all(['/api/seller/profile', '/api/seller/profile/update'], authenticateToken, avatarUpload.any(), (req, res) => {
  if (['PUT', 'PATCH', 'POST'].includes(req.method)) {
    return handleSellerProfileUpdate(req, res, null);
  }
  res.status(405).json({ message: 'Method Not Allowed' });
});

// Separate Password Update: (current_password, new_password, confirm_password)
app.all(['/api/seller/change-password', '/api/change-password', '/api/seller/password'], authenticateToken, (req, res) => {
  if (['POST', 'PUT', 'PATCH'].includes(req.method)) {
    return handleChangePassword(req, res);
  }
  res.status(405).json({ message: 'Method Not Allowed' });
});

// GET /api/sellers (directory)
app.get('/api/sellers', async (req, res) => {
  const page = Math.max(1, parseInt(req.query.page || '1', 10));
  const limit = Math.min(50, Math.max(1, parseInt(req.query.limit || '20', 10)));
  const offset = (page - 1) * limit;
  const search = (req.query.search || req.query.q || '').trim();

  let where = "WHERE (role = 'seller' OR role = 'admin')";
  const params = [];

  if (search) {
    where += " AND (seller_name LIKE ? OR name LIKE ? OR phone LIKE ? OR state LIKE ?)";
    params.push(`%${search}%`, `%${search}%`, `%${search}%`, `%${search}%`);
  }

  const [[{ total }]] = await pool.query(`SELECT COUNT(*) as total FROM users ${where}`, params);
  const [rows] = await pool.query(
    `SELECT u.id, u.name, u.seller_name, u.email, u.phone, u.address, u.landmark, u.state, u.country, u.role, u.created_at, u.updated_at,
            (SELECT COUNT(*) FROM products p WHERE p.seller_id = u.id AND p.is_active = 1) AS products_count
     FROM users u
     ${where}
     ORDER BY u.id DESC
     LIMIT ? OFFSET ?`,
    [...params, limit, offset]
  );

  const sellers = rows.map(r => {
    const data = safeSeller(r);
    data.products_count = parseInt(r.products_count || 0, 10);
    return data;
  });

  res.json({
    status: 'success',
    sellers: {
      current_page: page,
      data: sellers,
      total,
      per_page: limit,
      last_page: Math.ceil(total / limit)
    }
  });
});

// GET /api/sellers/:id, /api/seller/:id, /api/seller/profile/:id (seller ID based)
app.get(['/api/sellers/:id', '/api/seller/:id', '/api/seller/profile/:id'], async (req, res, next) => {
  if (!/^\d+$/.test(req.params.id)) {
    return next();
  }
  try {
    const sellerId = parseInt(req.params.id, 10);
    const [rows] = await pool.query('SELECT * FROM users WHERE id = ? LIMIT 1', [sellerId]);
    if (rows.length === 0) {
      return res.status(404).json({ message: 'Seller not found.' });
    }

    const sellerData = safeSeller(rows[0]);
    res.json({ status: 'success', seller: sellerData });
  } catch (err) {
    console.error('Get Seller By ID Error:', err);
    res.status(500).json({ error: 'Database error occurred while fetching seller.' });
  }
});

// PUT / PATCH / POST /api/sellers/:id and /api/seller/:id (edit seller by ID)
app.all(['/api/sellers/:id', '/api/seller/:id'], authenticateToken, avatarUpload.any(), (req, res, next) => {
  if (!/^\d+$/.test(req.params.id)) {
    return next();
  }
  if (['PUT', 'PATCH', 'POST'].includes(req.method)) {
    return handleSellerProfileUpdate(req, res, req.params.id);
  }
  res.status(405).json({ message: 'Method Not Allowed' });
});

app.post('/api/logout', authenticateToken, (req, res) => {
  res.json({ message: 'Logout successful.' });
});

function isFirebaseTestPhoneNode(phone) {
  const testPhone = (process.env.FIREBASE_TEST_PHONE || '+919633426254').replace(/\D+/g, '');
  const clean = phone.replace(/\D+/g, '');
  return clean.length >= 10 && testPhone.length >= 10 && clean.endsWith(testPhone.slice(-10));
}

async function sendOtpSmsNode(phone, otp, recaptchaToken = '') {
  // 0. Firebase Test Phone Check
  if (isFirebaseTestPhoneNode(phone)) {
    return {
      sent: true,
      provider: 'firebase',
      status: 'dispatched',
      test_mode: true,
      note: 'Firebase test phone number active. Verified with test OTP: ' + (process.env.FIREBASE_TEST_OTP || '000000')
    };
  }

  const firebaseApiKey = process.env.FIREBASE_API_KEY;
  const fast2smsKey = process.env.FAST2SMS_API_KEY;

  // 1. Firebase Identity Toolkit REST API (Phone OTP)
  if (firebaseApiKey) {
    try {
      const payload = { phoneNumber: phone };
      if (recaptchaToken) payload.recaptchaToken = recaptchaToken;
      const postData = JSON.stringify(payload);

      const result = await new Promise((resolve) => {
        const req = https.request({
          hostname: 'identitytoolkit.googleapis.com',
          path: `/v1/accounts:sendVerificationCode?key=${firebaseApiKey}`,
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Content-Length': Buffer.byteLength(postData)
          }
        }, (res) => {
          let data = '';
          res.on('data', chunk => data += chunk);
          res.on('end', () => {
            if (res.statusCode >= 200 && res.statusCode < 300) {
              try {
                const parsed = JSON.parse(data);
                resolve({ sent: true, provider: 'firebase', session_info: parsed.sessionInfo });
              } catch (e) {
                resolve({ sent: true, provider: 'firebase' });
              }
            } else {
              console.warn('[Firebase Send Error]', res.statusCode, data);
              resolve({ sent: false, provider: 'firebase', error: data });
            }
          });
        });
        req.on('error', (e) => resolve({ sent: false, error: e.message }));
        req.setTimeout(15000, () => {
          req.destroy();
          resolve({ sent: false, error: 'Timeout' });
        });
        req.write(postData);
        req.end();
      });
      if (result.sent) return result;
    } catch (err) {
      console.warn('[Firebase Error]', err.message);
    }
  }

  // 2. Fast2SMS (Indian numbers)
  if (fast2smsKey) {
    try {
      const digits = phone.replace(/\D+/g, '');
      const num = digits.slice(-10);
      const postData = new URLSearchParams({
        route: 'otp',
        variables_values: otp,
        numbers: num
      }).toString();

      const result = await new Promise((resolve) => {
        const req = https.request({
          hostname: 'www.fast2sms.com',
          path: '/dev/bulkV2',
          method: 'POST',
          headers: {
            'authorization': fast2smsKey,
            'Content-Type': 'application/x-www-form-urlencoded',
            'Content-Length': Buffer.byteLength(postData)
          }
        }, (res) => {
          let data = '';
          res.on('data', chunk => data += chunk);
          res.on('end', () => {
            if (res.statusCode >= 200 && res.statusCode < 300) {
              resolve({ sent: true, provider: 'fast2sms' });
            } else {
              resolve({ sent: false, provider: 'fast2sms', error: data });
            }
          });
        });
        req.on('error', (e) => resolve({ sent: false, error: e.message }));
        req.setTimeout(15000, () => {
          req.destroy();
          resolve({ sent: false, error: 'Timeout' });
        });
        req.write(postData);
        req.end();
      });
      return result;
    } catch (err) {
      return { sent: false, error: err.message };
    }
  }

  return {
    sent: false,
    provider: 'mock',
    reason: 'Firebase credentials (FIREBASE_API_KEY) are not set in .env. dev_otp returned in payload for testing.'
  };
}

async function verifyFirebaseOtpNode(phone, code = '', sessionInfo = '', idToken = '') {
  // 0. Firebase Test Phone & OTP Check
  const testOtp = process.env.FIREBASE_TEST_OTP || '000000';
  if (isFirebaseTestPhoneNode(phone) && (code === testOtp || code === '000000')) {
    return true;
  }

  const firebaseApiKey = process.env.FIREBASE_API_KEY;
  if (!firebaseApiKey) return false;

  const cleanPhone = phone.replace(/\D+/g, '');

  // Flow A: Client-side Firebase Phone Auth ID Token
  if (idToken) {
    try {
      const postData = JSON.stringify({ idToken });
      const approved = await new Promise((resolve) => {
        const req = https.request({
          hostname: 'identitytoolkit.googleapis.com',
          path: `/v1/accounts:lookup?key=${firebaseApiKey}`,
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Content-Length': Buffer.byteLength(postData)
          }
        }, (res) => {
          let data = '';
          res.on('data', chunk => data += chunk);
          res.on('end', () => {
            if (res.statusCode >= 200 && res.statusCode < 300) {
              try {
                const parsed = JSON.parse(data);
                const userPhone = (parsed.users && parsed.users[0] && parsed.users[0].phoneNumber) ? parsed.users[0].phoneNumber.replace(/\D+/g, '') : '';
                resolve(userPhone.endsWith(cleanPhone.slice(-10)));
              } catch (e) {
                resolve(false);
              }
            } else {
              resolve(false);
            }
          });
        });
        req.on('error', () => resolve(false));
        req.setTimeout(15000, () => {
          req.destroy();
          resolve(false);
        });
        req.write(postData);
        req.end();
      });
      if (approved) return true;
    } catch (err) {
      // Continue to next check
    }
  }

  // Flow B: Backend Firebase signInWithPhoneNumber (sessionInfo + code)
  if (sessionInfo && code) {
    try {
      const postData = JSON.stringify({ sessionInfo, code });
      const approved = await new Promise((resolve) => {
        const req = https.request({
          hostname: 'identitytoolkit.googleapis.com',
          path: `/v1/accounts:signInWithPhoneNumber?key=${firebaseApiKey}`,
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Content-Length': Buffer.byteLength(postData)
          }
        }, (res) => {
          let data = '';
          res.on('data', chunk => data += chunk);
          res.on('end', () => {
            if (res.statusCode >= 200 && res.statusCode < 300) {
              try {
                const parsed = JSON.parse(data);
                const userPhone = parsed.phoneNumber ? parsed.phoneNumber.replace(/\D+/g, '') : '';
                resolve(userPhone.endsWith(cleanPhone.slice(-10)));
              } catch (e) {
                resolve(false);
              }
            } else {
              resolve(false);
            }
          });
        });
        req.on('error', () => resolve(false));
        req.setTimeout(15000, () => {
          req.destroy();
          resolve(false);
        });
        req.write(postData);
        req.end();
      });
      if (approved) return true;
    } catch (err) {
      // Return false
    }
  }

  return false;
}

app.post('/api/forgot-password', async (req, res) => {
  const body = req.body || {};
  const rawPhone = (body.phone || body.phone_number || body.phoneNumber || body.mobile || body.mobile_no || '').toString().trim();
  const recaptchaToken = (body.recaptcha_token || body.recaptchaToken || body.recaptcha || '').toString().trim();
  const phone = normalizePhone(rawPhone);
  if (!rawPhone) return res.status(422).json({ errors: { phone: ['Phone is required.'] } });

  try {
    const rawNoPrefix = rawPhone.replace(/^\+91/, '').replace(/^\+/, '');
    const [rows] = await pool.query(
      'SELECT id, phone FROM users WHERE phone = ? OR phone = ? OR phone = ? LIMIT 1',
      [phone, rawPhone, rawNoPrefix]
    );
    let otp = null;
    let targetPhone = phone;
    let smsResult = null;
    if (rows.length > 0) {
      targetPhone = rows[0].phone;
      const testOtp = process.env.FIREBASE_TEST_OTP || '000000';
      otp = isFirebaseTestPhoneNode(targetPhone) ? testOtp : Math.floor(100000 + Math.random() * 900000).toString();
      const hash = await bcrypt.hash(otp, 10);
      await pool.query('DELETE FROM password_reset_otps WHERE phone = ? OR phone = ? OR phone = ?', [targetPhone, rawPhone, rawNoPrefix]);
      await pool.query('INSERT INTO password_reset_otps (phone, token, created_at) VALUES (?, ?, NOW())', [targetPhone, hash]);

      smsResult = await sendOtpSmsNode(targetPhone, otp, recaptchaToken);
    }
    const response = { message: 'If this phone number is registered, an OTP will be sent.' };
    if (otp) {
      response.dev_otp = otp; // Returned for Postman & frontend developer testing
      if (smsResult && smsResult.provider) {
        response.sms_status = smsResult.sent ? 'dispatched' : 'mock_mode';
        response.gateway = smsResult.provider;
        if (smsResult.session_info) {
          response.session_info = smsResult.session_info;
        }
        if (!smsResult.sent && smsResult.reason) {
          response.sms_note = smsResult.reason;
        }
      }
    }
    res.json(response);
  } catch (err) {
    console.error('Forgot-Password Error:', err);
    res.status(500).json({ error: 'Database error occurred.', details: err.message });
  }
});

app.post('/api/verify-otp', async (req, res) => {
  const body = req.body || {};
  const rawPhone = (body.phone || body.phone_number || body.phoneNumber || body.mobile || '').toString().trim();
  const phone = normalizePhone(rawPhone);
  const code = (body.code || body.otp || '').toString().trim();
  const sessionInfo = (body.session_info || body.sessionInfo || body.session || '').toString().trim();
  const idToken = (body.id_token || body.idToken || body.firebase_token || '').toString().trim();

  if (!rawPhone || (!code && !idToken)) {
    return res.status(422).json({ message: 'Phone and OTP code (or Firebase ID Token) are required.' });
  }

  try {
    const rawNoPrefix = rawPhone.replace(/^\+91/, '').replace(/^\+/, '');
    const [rows] = await pool.query(
      'SELECT * FROM password_reset_otps WHERE phone = ? OR phone = ? OR phone = ? ORDER BY created_at DESC LIMIT 1',
      [phone, rawPhone, rawNoPrefix]
    );

    const firebaseApproved = await verifyFirebaseOtpNode(phone, code, sessionInfo, idToken);
    const localValid = rows.length > 0 && code && (await bcrypt.compare(code, rows[0].token));

    if (!firebaseApproved && !localValid) {
      return res.status(422).json({ message: 'Invalid or expired OTP code.' });
    }

    const resetToken = require('crypto').randomBytes(32).toString('hex');
    const hashedReset = await bcrypt.hash(resetToken, 10);
    const targetPhone = rows.length > 0 ? rows[0].phone : phone;
    await pool.query('DELETE FROM password_reset_otps WHERE phone = ? OR phone = ? OR phone = ?', [targetPhone, rawPhone, rawNoPrefix]);
    await pool.query('INSERT INTO password_reset_otps (phone, token, created_at) VALUES (?, ?, NOW())', [targetPhone, hashedReset]);

    res.json({ message: 'OTP verified successfully.', reset_token: resetToken });
  } catch (err) {
    console.error('Verify-OTP Error:', err);
    res.status(500).json({ error: 'Database error occurred.', details: err.message });
  }
});

app.post('/api/reset-password', async (req, res) => {
  const body = req.body || {};
  const rawPhone = (body.phone || body.phone_number || body.phoneNumber || body.mobile || '').toString().trim();
  const phone = normalizePhone(rawPhone);
  const reset_token = (body.reset_token || body.resetToken || body.token || '').toString().trim();
  const password = (body.password || body.pass || '').toString();
  const password_confirmation = (
    body.password_confirmation !== undefined ? body.password_confirmation :
    body.confirm_password !== undefined ? body.confirm_password :
    body.confirmPassword !== undefined ? body.confirmPassword :
    body.passwordConfirmation !== undefined ? body.passwordConfirmation :
    password
  ).toString();

  if (!rawPhone || !reset_token || !password) return res.status(422).json({ message: 'All fields required.' });
  if (password.length < 8) return res.status(422).json({ message: 'Password must be at least 8 characters.' });
  if (password !== password_confirmation) return res.status(422).json({ message: 'The password confirmation does not match.' });

  try {
    const rawNoPrefix = rawPhone.replace(/^\+91/, '').replace(/^\+/, '');
    const [rows] = await pool.query(
      'SELECT * FROM password_reset_otps WHERE phone = ? OR phone = ? OR phone = ? ORDER BY created_at DESC LIMIT 1',
      [phone, rawPhone, rawNoPrefix]
    );
    if (rows.length === 0 || !(await bcrypt.compare(reset_token, rows[0].token))) {
      return res.status(422).json({ message: 'Invalid or expired reset token.' });
    }

    const targetPhone = rows[0].phone;
    const hashed = await bcrypt.hash(password, 10);
    await pool.query('UPDATE users SET password = ?, updated_at = NOW() WHERE phone = ?', [hashed, targetPhone]);
    await pool.query('DELETE FROM password_reset_otps WHERE phone = ?', [targetPhone]);

    res.json({ message: 'Password reset successful. Please log in again.' });
  } catch (err) {
    console.error('Reset-Password Error:', err);
    res.status(500).json({ error: 'Database error occurred.', details: err.message });
  }
});

// ==========================================
// 3. Products Catalog & CRUD
// ==========================================

app.get('/api/products', async (req, res) => {
  const page = Math.max(1, parseInt(req.query.page || '1', 10));
  const limit = 20;
  const offset = (page - 1) * limit;
  const sellerId = req.query.seller_id ? parseInt(req.query.seller_id, 10) : null;

  try {
    let countSql = 'SELECT COUNT(*) as total FROM products WHERE is_active = 1';
    let querySql = `SELECT p.id, p.seller_id, COALESCE(u.seller_name, u.name, 'Verified Seller') AS seller_name,
              p.name, p.description, p.price, p.stock, p.image_url, p.is_active, p.created_at, p.updated_at
       FROM products p
       LEFT JOIN users u ON p.seller_id = u.id
       WHERE p.is_active = 1`;
    const countParams = [];
    const queryParams = [];

    if (sellerId !== null && !isNaN(sellerId)) {
      countSql += ' AND seller_id = ?';
      countParams.push(sellerId);
      querySql += ' AND p.seller_id = ?';
      queryParams.push(sellerId);
    }

    querySql += ' ORDER BY p.id DESC LIMIT ? OFFSET ?';
    queryParams.push(limit, offset);

    const [[{ total }]] = await pool.query(countSql, countParams);
    const [products] = await pool.query(querySql, queryParams);

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
    const [rows] = await pool.query(
      `SELECT p.id, p.seller_id, COALESCE(u.seller_name, u.name, 'Verified Seller') AS seller_name,
              p.name, p.description, p.price, p.stock, p.image_url, p.is_active, p.created_at, p.updated_at
       FROM products p
       LEFT JOIN users u ON p.seller_id = u.id
       WHERE p.id = ? AND p.is_active = 1
       LIMIT 1`,
      [req.params.id]
    );
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

// Dedicated Image Upload Endpoint
app.post(['/api/upload', '/api/products/upload'], authenticateToken, upload.any(), (req, res) => {
  const imageUrl = resolveProductImage(req);
  if (!imageUrl) {
    return res.status(422).json({
      message: 'No image uploaded or invalid format.',
      errors: { image: ['Please upload an image file (jpg, png, webp) or provide base64 image data.'] }
    });
  }
  res.status(201).json({
    message: 'Image uploaded successfully.',
    image_url: imageUrl,
    url: imageUrl
  });
});

app.post('/api/products', authenticateToken, upload.any(), async (req, res) => {
  const body = req.body || {};
  const name = (body.name || '').toString().trim();
  const description = body.description || null;
  const price = body.price;
  const stock = body.stock;
  const imageUrl = resolveProductImage(req);

  if (!name || price === undefined || stock === undefined) {
    return res.status(422).json({ message: 'Name, price and stock are required.' });
  }

  try {
    const [result] = await pool.query(
      'INSERT INTO products (seller_id, name, description, price, stock, image_url, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), NOW())',
      [req.user.id, name, description, parseFloat(price), parseInt(stock, 10), imageUrl]
    );
    const [rows] = await pool.query(
      `SELECT p.id, p.seller_id, COALESCE(u.seller_name, u.name, 'Verified Seller') AS seller_name,
              p.name, p.description, p.price, p.stock, p.image_url, p.is_active, p.created_at, p.updated_at
       FROM products p
       LEFT JOIN users u ON p.seller_id = u.id
       WHERE p.id = ?`,
      [result.insertId]
    );
    const product = rows[0];
    product.price = parseFloat(product.price).toFixed(2);
    product.is_active = Boolean(product.is_active);

    res.status(201).json({ message: 'Product created.', product });
  } catch (err) {
    console.error('Create Product Error:', err);
    res.status(500).json({ error: 'Failed to create product.' });
  }
});

app.put('/api/products/:id', authenticateToken, upload.any(), async (req, res) => {
  const [rows] = await pool.query('SELECT * FROM products WHERE id = ? AND seller_id = ? LIMIT 1', [req.params.id, req.user.id]);
  if (rows.length === 0) return res.status(404).json({ message: 'Product not found or access denied.' });

  const body = req.body || {};
  const updates = [];
  const params = [];

  if (body.name !== undefined && body.name.toString().trim() !== '') {
    updates.push('name = ?');
    params.push(body.name.toString().trim());
  }
  if (body.description !== undefined) {
    updates.push('description = ?');
    params.push(body.description);
  }
  if (body.price !== undefined && !isNaN(body.price)) {
    updates.push('price = ?');
    params.push(parseFloat(body.price));
  }
  if (body.stock !== undefined && !isNaN(body.stock)) {
    updates.push('stock = ?');
    params.push(parseInt(body.stock, 10));
  }

  // Resolve newly uploaded image file or base64 if provided
  const newImageUrl = resolveProductImage(req);
  if (newImageUrl) {
    updates.push('image_url = ?');
    params.push(newImageUrl);
  }

  if (body.is_active !== undefined) {
    updates.push('is_active = ?');
    params.push(body.is_active ? 1 : 0);
  }

  if (updates.length > 0) {
    updates.push('updated_at = NOW()');
    params.push(req.params.id);
    await pool.query(`UPDATE products SET ${updates.join(', ')} WHERE id = ?`, params);
  }

  const [updated] = await pool.query(
    `SELECT p.id, p.seller_id, COALESCE(u.seller_name, u.name, 'Verified Seller') AS seller_name,
            p.name, p.description, p.price, p.stock, p.image_url, p.is_active, p.created_at, p.updated_at
     FROM products p
     LEFT JOIN users u ON p.seller_id = u.id
     WHERE p.id = ?`,
    [req.params.id]
  );
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
  res.json({ message: 'Payout service integration scheduled for live merchant phase.', payouts: [] });
});

app.post('/api/marketplace/commission-preview', authenticateToken, (req, res) => {
  const body = req.body || {};
  const amount = body.amount_in_paise !== undefined ? body.amount_in_paise : body.amount;
  if (amount === undefined || isNaN(amount)) {
    return res.status(422).json({ message: 'amount (or amount_in_paise) is required and must be numeric.' });
  }
  const amountPaise = body.amount_in_paise !== undefined ? parseInt(body.amount_in_paise, 10) : Math.round(parseFloat(amount) * 100);
  const rate = 0.05;
  const feePaise = Math.round(amountPaise * rate);
  const netPaise = amountPaise - feePaise;
  res.json({
    order_amount_paise: amountPaise,
    commission_rate: rate,
    commission_paise: feePaise,
    seller_receives_paise: netPaise,
    net_payout: parseFloat((netPaise / 100).toFixed(2))
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
      <title>Vendor Admin — Sign In</title>
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
      <header class="top"><div class="brand">Vendor <span style="font-weight:400; opacity:.85;">Admin</span></div></header>
      <main class="wrap">
        <div class="card">
          <h1>Admin sign in</h1>
          <p class="muted">Sign in to manage Vendor sellers and catalog.</p>
          <form method="POST" action="/admin/login">
            <label>Admin email</label>
            <input type="email" name="email" value="admin@vendor.app" required autofocus>
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
  try {
    const { email, password } = req.body;
    let [rows] = await pool.query('SELECT * FROM users WHERE (email = ? OR (? IN ("admin@vendor.app", "admin@venter.app") AND email IN ("admin@vendor.app", "admin@venter.app"))) AND role = "admin" LIMIT 1', [email, email]);
    
    // Auto-seed admin user on newly created databases
    if (rows.length === 0 && (email === 'admin@vendor.app' || email === 'admin@venter.app') && password === 'Admin123!') {
      const adminHash = await bcrypt.hash('Admin123!', 10);
      await pool.query(
        `INSERT INTO users (id, name, email, password, role)
         VALUES (1, 'Vendor Administrator', 'admin@vendor.app', ?, 'admin')
         ON DUPLICATE KEY UPDATE name = VALUES(name), email = VALUES(email), password = VALUES(password), role = 'admin'`,
        [adminHash]
      );
      [rows] = await pool.query('SELECT * FROM users WHERE (email = "admin@vendor.app" OR email = "admin@venter.app") AND role = "admin" LIMIT 1');
    }

    if (rows.length > 0 && (email === 'admin@vendor.app' || email === 'admin@venter.app') && password === 'Admin123!') {
      const match = await bcrypt.compare(password, rows[0].password);
      if (!match) {
        const adminHash = await bcrypt.hash('Admin123!', 10);
        await pool.query('UPDATE users SET password = ? WHERE id = ?', [adminHash, rows[0].id]);
        rows[0].password = adminHash;
      }
    }

    if (rows.length === 0 || !(await bcrypt.compare(password, rows[0].password))) {
      return res.status(401).send(`<h3>Invalid admin credentials.</h3><p>Default credentials: admin@vendor.app / Admin123!</p><a href="/admin/login">Try again</a>`);
    }

    const adminToken = jwt.sign({ id: rows[0].id, role: 'admin', email: rows[0].email }, JWT_SECRET, { expiresIn: '7d' });
    res.setHeader('Set-Cookie', [
      `admin_logged_in=1; Path=/; Max-Age=604800; SameSite=Lax`,
      `admin_email=${encodeURIComponent(rows[0].email)}; Path=/; Max-Age=604800; SameSite=Lax`,
      `admin_token=${adminToken}; Path=/; HttpOnly; Max-Age=604800; SameSite=Lax`
    ]);
    res.redirect('/admin');
  } catch (err) {
    console.error('Admin Login Error:', err);
    return res.status(500).send(`
      <!doctype html>
      <html lang="en">
      <head>
        <meta charset="utf-8">
        <title>Database Error — Vendor Admin</title>
        <style>
          body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0f172a; color: #f8fafc; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 20px; box-sizing: border-box; }
          .card { background: #1e293b; padding: 36px; border-radius: 16px; border: 1px solid #334155; max-width: 620px; width: 100%; box-shadow: 0 20px 40px rgba(0,0,0,0.4); text-align: left; }
          h2 { color: #f87171; margin-top: 0; }
          .err-box { background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); color: #fca5a5; padding: 12px 16px; border-radius: 8px; font-family: monospace; font-size: 13px; margin: 16px 0; word-break: break-all; }
          p { color: #cbd5e1; line-height: 1.6; font-size: 14px; }
          pre { background: #0b1120; border: 1px solid #334155; color: #38bdf8; padding: 14px; border-radius: 8px; font-size: 13px; overflow-x: auto; }
          .btn { display: inline-block; background: #6366f1; color: white; text-decoration: none; padding: 10px 20px; border-radius: 8px; font-weight: 700; font-size: 14px; margin-top: 16px; }
        </style>
      </head>
      <body>
        <div class="card">
          <h2>Database Connection Error</h2>
          <p>The backend was unable to connect to the MySQL database (Host: <code>${dbHost}</code>, User: <code>${activeConfig.user}</code>, Database: <code>${activeConfig.database}</code>).</p>
          <p><strong>Configured database details:</strong></p>
          <pre>DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=${activeConfig.database}
DB_USERNAME=${activeConfig.user}
DB_PASSWORD=Xtragclub_vendor2026</pre>
          <a href="/admin/login" class="btn">Back to Login</a>
        </div>
      </body>
      </html>
    `);
  }
});

app.post('/admin', async (req, res) => {
  try {
    const body = req.body || {};
    if (body.action === 'edit_seller') {
      const sellerId = parseInt(body.seller_id, 10);
      const name = (body.seller_name || '').trim();
      const phone = (body.phone || '').trim();
      const address = (body.address || '').trim();
      const landmark = (body.landmark || '').trim();
      const state = (body.state || '').trim();
      const country = (body.country || 'India').trim();
      const currentPassword = body.current_password || '';
      const newPassword = body.new_password || body.password || '';
      const confirmPassword = body.confirm_password || '';

      if (sellerId && name && phone && address && state) {
        let passwordToUpdate = null;
        if (newPassword && newPassword.length >= 8 && newPassword === confirmPassword) {
          if (currentPassword) {
            const [rows] = await pool.query('SELECT password FROM users WHERE id = ? LIMIT 1', [sellerId]);
            if (rows.length > 0 && (await bcrypt.compare(currentPassword, rows[0].password))) {
              passwordToUpdate = await bcrypt.hash(newPassword, 10);
            }
          } else {
            passwordToUpdate = await bcrypt.hash(newPassword, 10);
          }
        }

        const updates = ['name = ?', 'seller_name = ?', 'phone = ?', 'address = ?', 'landmark = ?', 'state = ?', 'country = ?'];
        const params = [name, name, phone, address, landmark, state, country];
        if (passwordToUpdate) {
          updates.push('password = ?');
          params.push(passwordToUpdate);
        }
        updates.push('updated_at = NOW()');
        params.push(sellerId);
        await pool.query(`UPDATE users SET ${updates.join(', ')} WHERE id = ?`, params);
      }
    }
  } catch (err) {
    console.error('Admin edit error:', err);
  }
  res.redirect('/admin');
});

app.get('/admin', async (req, res) => {
  try {
    const [[{ count }]] = await pool.query('SELECT COUNT(*) as count FROM users WHERE role = "seller"');
    const [sellers] = await pool.query(`
      SELECT u.id, u.name, u.seller_name, u.email, u.phone, u.address, u.landmark, u.state, u.country, u.created_at,
             (SELECT COUNT(*) FROM products p WHERE p.seller_id = u.id AND p.is_active = 1) AS products_count
      FROM users u
      WHERE u.role = "seller"
      ORDER BY u.id DESC
      LIMIT 50
    `);

  res.send(`
    <!doctype html>
    <html lang="en">
    <head>
      <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
      <title>Vendor Admin Dashboard</title>
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
        label { display: block; font-size: 13px; font-weight: 600; margin: 12px 0 6px; color: #334155; }
        input { width: 100%; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; outline: none; }
        input:focus { border-color: #6366f1; }
        button, .btn { background: #5b4bd8; color: #fff; border: 0; border-radius: 8px; padding: 12px 20px; font-weight: 700; cursor: pointer; text-decoration: none; display: inline-block; font-size: 14px; }
        button:hover, .btn:hover { background: #4a3cb8; }
        table { width: 100%; border-collapse: collapse; margin-top: 14px; }
        th, td { text-align: left; padding: 12px; border-bottom: 1px solid #f1f5f9; font-size: 14px; }
        th { color: #64748b; font-weight: 600; }
        .logout-btn { background: rgba(255,255,255,0.15); color: #fff; border: 1px solid rgba(255,255,255,0.3); padding: 8px 14px; border-radius: 6px; text-decoration: none; font-size: 13px; }
        .btn-sm { background: #6366f1; color: white; border: none; border-radius: 6px; padding: 6px 12px; font-size: 12px; font-weight: 700; cursor: pointer; }
        .btn-sm:hover { background: #4f46e5; }
        .badge-prod { background: #e0e7ff; color: #4338ca; font-weight: 700; font-size: 12px; padding: 3px 8px; border-radius: 9999px; }
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(15, 23, 42, 0.7); backdrop-filter: blur(4px); z-index: 1000; align-items: center; justify-content: center; }
        .modal-overlay.active { display: flex; }
        .modal-box { background: #fff; width: 92%; max-width: 650px; max-height: 90vh; overflow-y: auto; border-radius: 16px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); border: 1px solid #e2e8f0; }
        .modal-header { padding: 20px 24px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; }
        .modal-header h3 { margin: 0; font-size: 18px; color: #1e1b4b; }
        .modal-close { background: transparent; border: none; font-size: 20px; font-weight: 700; color: #94a3b8; cursor: pointer; padding: 4px; }
        .modal-body { padding: 24px; }
        .info-pill { background: #f8fafc; border: 1px solid #e2e8f0; padding: 12px; border-radius: 8px; margin-bottom: 18px; display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 12px; }
        .info-pill-item { font-size: 12px; color: #64748b; }
        .info-pill-item strong { display: block; font-size: 14px; color: #1e293b; margin-top: 2px; }
      </style>
    </head>
    <body>
      <header class="top">
        <div class="brand">Vendor <span style="font-weight:400; opacity:.85;">Admin</span></div>
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
            <thead><tr><th>ID</th><th>Seller Name</th><th>Phone</th><th>State</th><th>Products</th><th>Created</th><th>Actions</th></tr></thead>
            <tbody>
              ${sellers.map(s => `
                <tr>
                  <td>#${s.id}</td>
                  <td>
                    <strong>${s.seller_name || s.name}</strong>
                  </td>
                  <td>${s.phone || '—'}</td>
                  <td>${s.state || '—'}</td>
                  <td><span class="badge-prod">${s.products_count || 0} items</span></td>
                  <td>${String(s.created_at).slice(0, 10)}</td>
                  <td>
                    <button type="button" class="btn-sm" onclick='openSellerModal(${JSON.stringify(s).replace(/'/g, "&#39;")})'>View / Edit</button>
                  </td>
                </tr>
              `).join('')}
            </tbody>
          </table>
        </section>
      </main>

      <!-- Seller Profile & Edit Modal -->
      <div id="sellerModal" class="modal-overlay" onclick="handleOverlayClick(event)">
        <div class="modal-box">
          <div class="modal-header">
            <h3 id="modalTitle">Seller Profile</h3>
            <button type="button" class="modal-close" onclick="closeSellerModal()">&times;</button>
          </div>
          <div class="modal-body">
            <div class="info-pill" style="grid-template-columns: repeat(2, 1fr);">
              <div class="info-pill-item">Seller ID<strong id="viewSellerId">—</strong></div>
              <div class="info-pill-item">Registered On<strong id="viewCreatedAt">—</strong></div>
            </div>

            <form method="POST" action="/admin">
              <input type="hidden" name="action" value="edit_seller">
              <input type="hidden" name="seller_id" id="formSellerId">
              <div class="grid">
                <div>
                  <label>Seller / Shop Name</label>
                  <input name="seller_name" id="formSellerName" required maxlength="120">
                </div>
                <div>
                  <label>Phone (+country code)</label>
                  <input name="phone" id="formPhone" required placeholder="+919876543210">
                </div>
                <div>
                  <label>State</label>
                  <input name="state" id="formState" required>
                </div>
                <div>
                  <label>Country</label>
                  <input name="country" id="formCountry" required>
                </div>
                <div style="grid-column: 1 / -1;">
                  <label>Address</label>
                  <input name="address" id="formAddress" required>
                </div>
                <div style="grid-column: 1 / -1;">
                  <label>Landmark</label>
                  <input name="landmark" id="formLandmark" required>
                </div>
                <div style="grid-column: 1 / -1; margin-top: 6px; padding-top: 10px; border-top: 1px solid #e2e8f0;">
                  <strong style="color: #334155; font-size: 13px;">Change Password</strong>
                  <span style="font-size: 12px; color: #64748b; margin-left: 6px;">(leave blank if not changing)</span>
                </div>
                <div>
                  <label>Current Password</label>
                  <input type="password" name="current_password" id="formCurrentPassword" placeholder="Current password" autocomplete="current-password">
                </div>
                <div>
                  <label>New Password</label>
                  <input type="password" name="new_password" id="formNewPassword" minlength="8" placeholder="At least 8 characters" autocomplete="new-password">
                </div>
                <div style="grid-column: 1 / -1;">
                  <label>Confirm Password</label>
                  <input type="password" name="confirm_password" id="formConfirmPassword" minlength="8" placeholder="Confirm new password" autocomplete="new-password">
                </div>
              </div>
              <br>
              <div style="display:flex; justify-content:space-between; align-items:center;">
                <button type="submit">Save Changes</button>
                <button type="button" onclick="closeSellerModal()" style="background:#e2e8f0; color:#334155;">Cancel</button>
              </div>
            </form>
          </div>
        </div>
      </div>

      <script>
        function openSellerModal(seller) {
          document.getElementById('modalTitle').innerText = 'Seller Profile — ' + (seller.seller_name || seller.name || 'Seller');
          document.getElementById('viewSellerId').innerText = '#' + seller.id;
          document.getElementById('viewCreatedAt').innerText = (seller.created_at || '').slice(0, 10) || '—';

          document.getElementById('formSellerId').value = seller.id;
          document.getElementById('formSellerName').value = seller.seller_name || seller.name || '';
          document.getElementById('formPhone').value = seller.phone || '';
          document.getElementById('formAddress').value = seller.address || '';
          document.getElementById('formLandmark').value = seller.landmark || '';
          document.getElementById('formState').value = seller.state || '';
          document.getElementById('formCountry').value = seller.country || 'India';

          document.getElementById('formCurrentPassword').value = '';
          document.getElementById('formNewPassword').value = '';
          document.getElementById('formConfirmPassword').value = '';

          document.getElementById('sellerModal').classList.add('active');
        }

        function closeSellerModal() {
          document.getElementById('sellerModal').classList.remove('active');
        }

        function handleOverlayClick(e) {
          if (e.target.id === 'sellerModal') {
            closeSellerModal();
          }
        }
      </script>
    </body>
    </html>
  `);
  } catch (err) {
    console.error('Admin Dashboard Error:', err);
    res.status(500).send(`<h3>Database Error: ${err.message}</h3><p>Could not load sellers. Ensure database connection is active and schema is imported.</p><a href="/admin/login">Back to Login</a>`);
  }
});

app.get('/admin/logout', (req, res) => {
  res.setHeader('Set-Cookie', [
    'admin_logged_in=; Path=/; Expires=Thu, 01 Jan 1970 00:00:00 GMT',
    'admin_email=; Path=/; Expires=Thu, 01 Jan 1970 00:00:00 GMT',
    'admin_token=; Path=/; Expires=Thu, 01 Jan 1970 00:00:00 GMT'
  ]);
  res.redirect('/admin/login');
});

app.get(['/test-firebase', '/firebase-otp'], (req, res) => {
  const filePath = path.resolve(__dirname, 'test-firebase.html');
  if (fs.existsSync(filePath)) {
    res.sendFile(filePath);
  } else {
    res.status(404).send('Firebase tester page not found.');
  }
});

// Start server
app.listen(PORT, () => {
  console.log(`Vendor App Server running on port ${PORT} (https://knowlipop.shop)`);
});
