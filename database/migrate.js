const fs = require('fs');
const path = require('path');
const mysql = require('mysql2/promise');
const bcrypt = require('bcryptjs');
require('dotenv').config({ path: path.join(__dirname, '../.env') });

function normalizePhoneMigration(raw) {
  if (!raw) return '';
  let cleaned = String(raw).trim().replace(/[\s\-\(\)\.]+/g, '');
  if (!cleaned || !/^\+?\d+$/.test(cleaned)) return '';
  if (cleaned.startsWith('00') && cleaned.length > 4) {
    cleaned = '+' + cleaned.slice(2);
  }
  if (cleaned.startsWith('+')) {
    const m91 = cleaned.match(/^\+910([6-9]\d{9})$/);
    return m91 ? '+91' + m91[1] : cleaned;
  }
  if (/^\d{10}$/.test(cleaned)) return '+91' + cleaned;
  const m0 = cleaned.match(/^0(\d{10})$/);
  if (m0) return '+91' + m0[1];
  if (/^91\d{10}$/.test(cleaned)) return '+' + cleaned;
  return '+' + cleaned;
}

async function runMigration() {
  const host = process.env.DB_HOST || '127.0.0.1';
  const port = parseInt(process.env.DB_PORT || '3306', 10);
  const user = (process.env.DB_USERNAME && process.env.DB_USERNAME !== 'root') ? process.env.DB_USERNAME : 'u999617803_knowlipop1';
  const password = process.env.DB_PASSWORD || 'Xtragclub_vendor2026';
  const database = (process.env.DB_DATABASE && process.env.DB_DATABASE !== 'vendor_app' && process.env.DB_DATABASE !== 'venter_app') ? process.env.DB_DATABASE : 'u999617803_knowlipop1';

  console.log(`[Vendor DB] Connecting to MySQL server:`);
  console.log(`  Host:     ${host}:${port}`);
  console.log(`  User:     ${user}`);
  console.log(`  Database: ${database}`);

  const conn = await mysql.createConnection({
    host,
    port,
    user,
    password,
    database,
    multipleStatements: true
  });

  const schemaPath = path.join(__dirname, 'schema.sql');
  const sql = fs.readFileSync(schemaPath, 'utf8');

  console.log('[Vendor DB] Executing database/schema.sql...');
  await conn.query(sql);

  // Ensure seller_payment_accounts has account_reference & status columns if table already existed
  try {
    const [cols] = await conn.query('SHOW COLUMNS FROM seller_payment_accounts LIKE "account_reference"');
    if (cols.length === 0) {
      await conn.query('ALTER TABLE seller_payment_accounts ADD COLUMN account_reference VARCHAR(120) NULL AFTER seller_id');
      console.log('✅ Added missing column account_reference to seller_payment_accounts');
    }
  } catch (e) {
    // ignore
  }

  try {
    const [cols] = await conn.query('SHOW COLUMNS FROM seller_payment_accounts LIKE "status"');
    if (cols.length === 0) {
      await conn.query('ALTER TABLE seller_payment_accounts ADD COLUMN status VARCHAR(50) NOT NULL DEFAULT "created" AFTER account_reference');
      console.log('✅ Added missing column status to seller_payment_accounts');
    }
  } catch (e) {
    // ignore
  }

  // Ensure users table has avatar_url column
  try {
    const [cols] = await conn.query('SHOW COLUMNS FROM users LIKE "avatar_url"');
    if (cols.length === 0) {
      await conn.query('ALTER TABLE users ADD COLUMN avatar_url LONGTEXT NULL AFTER country');
      console.log('✅ Added missing column avatar_url to users');
    }
  } catch (e) {
    // ignore
  }

  // Ensure users table has Seller Deletion & token revocation columns
  const ensureColumn = async (table, colName, alterSql) => {
    try {
      const [cols] = await conn.query(`SHOW COLUMNS FROM \`${table}\` LIKE ?`, [colName]);
      if (cols.length === 0) {
        await conn.query(`ALTER TABLE \`${table}\` ADD COLUMN ${alterSql}`);
        console.log(`✅ Added missing column ${colName} to ${table}`);
      }
    } catch (e) {
      // ignore
    }
  };

  await ensureColumn('users', 'normalized_phone', '`normalized_phone` VARCHAR(30) NULL DEFAULT NULL AFTER `phone`');
  await ensureColumn('users', 'account_status', "`account_status` VARCHAR(40) NOT NULL DEFAULT 'ACTIVE' AFTER `role`");
  await ensureColumn('users', 'deletion_requested_at', '`deletion_requested_at` TIMESTAMP NULL DEFAULT NULL AFTER `account_status`');
  await ensureColumn('users', 'deleted_at', '`deleted_at` TIMESTAMP NULL DEFAULT NULL AFTER `deletion_requested_at`');
  await ensureColumn('users', 'deleted_by', '`deleted_by` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `deleted_at`');
  await ensureColumn('users', 'token_version', '`token_version` INT NOT NULL DEFAULT 1 AFTER `deleted_by`');

  await ensureColumn('orders', 'carrier', '`carrier` VARCHAR(100) NULL DEFAULT NULL');
  await ensureColumn('orders', 'tracking_number', '`tracking_number` VARCHAR(100) NULL DEFAULT NULL');
  await ensureColumn('orders', 'cancellation_reason', '`cancellation_reason` TEXT NULL DEFAULT NULL');
  await ensureColumn('orders', 'rejection_reason', '`rejection_reason` TEXT NULL DEFAULT NULL');
  await ensureColumn('order_items', 'item_status', '`item_status` VARCHAR(40) NOT NULL DEFAULT "pending"');
  await ensureColumn('order_items', 'rejection_reason', '`rejection_reason` TEXT NULL DEFAULT NULL');

  await ensureColumn('notifications', 'recipient_role', '`recipient_role` VARCHAR(40) NULL DEFAULT NULL AFTER `buyer_phone`');
  await ensureColumn('notifications', 'order_id', '`order_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `recipient_role`');

  // Ensure order_activities table exists
  try {
    await conn.query(`
      CREATE TABLE IF NOT EXISTS \`order_activities\` (
        \`id\` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        \`order_id\` BIGINT UNSIGNED NOT NULL,
        \`actor_id\` BIGINT UNSIGNED NULL DEFAULT NULL,
        \`actor_role\` VARCHAR(40) NOT NULL DEFAULT 'system',
        \`actor_name\` VARCHAR(150) NULL DEFAULT NULL,
        \`action\` VARCHAR(60) NOT NULL,
        \`from_status\` VARCHAR(40) NULL DEFAULT NULL,
        \`to_status\` VARCHAR(40) NULL DEFAULT NULL,
        \`notes\` TEXT NULL DEFAULT NULL,
        \`tracking_number\` VARCHAR(100) NULL DEFAULT NULL,
        \`carrier\` VARCHAR(100) NULL DEFAULT NULL,
        \`metadata\` LONGTEXT NULL DEFAULT NULL,
        \`created_at\` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (\`id\`),
        INDEX \`idx_oa_order\` (\`order_id\`),
        INDEX \`idx_oa_action\` (\`action\`),
        INDEX \`idx_oa_created\` (\`created_at\`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    `);
  } catch (e) {}

  // Ensure orders table has payment columns
  await ensureColumn('orders', 'payment_gateway', '`payment_gateway` VARCHAR(40) NULL DEFAULT NULL AFTER `payment_status`');
  await ensureColumn('orders', 'gateway_order_id', '`gateway_order_id` VARCHAR(120) NULL DEFAULT NULL AFTER `payment_gateway`');
  await ensureColumn('orders', 'gateway_payment_id', '`gateway_payment_id` VARCHAR(120) NULL DEFAULT NULL AFTER `gateway_order_id`');
  await ensureColumn('orders', 'captured_amount', '`captured_amount` DECIMAL(10,2) NULL DEFAULT NULL AFTER `gateway_payment_id`');
  await ensureColumn('orders', 'refunded_amount', '`refunded_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `captured_amount`');

  // Ensure payment_transactions table has enhanced metadata columns
  await ensureColumn('payment_transactions', 'commission_rate', '`commission_rate` DECIMAL(5,4) NOT NULL DEFAULT 0.0500 AFTER `status`');
  await ensureColumn('payment_transactions', 'commission_amount', '`commission_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `commission_rate`');
  await ensureColumn('payment_transactions', 'net_seller_amount', '`net_seller_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `commission_amount`');
  await ensureColumn('payment_transactions', 'buyer_phone', '`buyer_phone` VARCHAR(30) NULL DEFAULT NULL AFTER `net_seller_amount`');
  await ensureColumn('payment_transactions', 'buyer_name', '`buyer_name` VARCHAR(150) NULL DEFAULT NULL AFTER `buyer_phone`');
  await ensureColumn('payment_transactions', 'refund_id', '`refund_id` VARCHAR(120) NULL DEFAULT NULL AFTER `buyer_name`');
  await ensureColumn('payment_transactions', 'idempotency_key', '`idempotency_key` VARCHAR(120) NULL DEFAULT NULL AFTER `refund_id`');
  await ensureColumn('payment_transactions', 'error_code', '`error_code` VARCHAR(100) NULL DEFAULT NULL AFTER `idempotency_key`');
  await ensureColumn('payment_transactions', 'error_description', '`error_description` TEXT NULL DEFAULT NULL AFTER `error_code`');

  // Non-destructive backfill & duplicate audit for normalized_phone before adding UNIQUE index
  try {
    const [phoneRows] = await conn.query(
      "SELECT id, phone, normalized_phone, COALESCE(account_status, 'ACTIVE') AS account_status FROM users WHERE phone IS NOT NULL AND TRIM(phone) != '' ORDER BY id ASC"
    );
    const groups = new Map();
    for (const row of phoneRows) {
      const norm = normalizePhoneMigration(row.phone);
      if (!norm) continue;
      if (!groups.has(norm)) groups.set(norm, []);
      groups.get(norm).push(row);
    }

    let backfilledCount = 0;
    const duplicateReports = [];
    for (const [norm, members] of groups.entries()) {
      members.sort((a, b) => {
        const aBlocked = ['LOCKED', 'DELETED'].includes(String(a.account_status || 'ACTIVE').toUpperCase()) ? 1 : 0;
        const bBlocked = ['LOCKED', 'DELETED'].includes(String(b.account_status || 'ACTIVE').toUpperCase()) ? 1 : 0;
        if (aBlocked !== bBlocked) return aBlocked - bBlocked;
        const aHeld = a.normalized_phone === norm ? 0 : 1;
        const bHeld = b.normalized_phone === norm ? 0 : 1;
        if (aHeld !== bHeld) return aHeld - bHeld;
        return Number(a.id) - Number(b.id);
      });

      const canonical = members[0];
      if (canonical.normalized_phone !== norm) {
        await conn.query('UPDATE users SET normalized_phone = NULL WHERE normalized_phone = ? AND id != ?', [norm, canonical.id]);
        await conn.query('UPDATE users SET normalized_phone = ? WHERE id = ?', [norm, canonical.id]);
        backfilledCount++;
      }

      if (members.length > 1) {
        duplicateReports.push({
          normalized_phone: norm,
          canonical_user_id: canonical.id,
          duplicate_user_ids: members.slice(1).map(m => m.id)
        });
        for (const dup of members.slice(1)) {
          if (dup.normalized_phone) {
            await conn.query('UPDATE users SET normalized_phone = NULL WHERE id = ?', [dup.id]);
          }
        }
      }
    }
    if (backfilledCount > 0) {
      console.log(`✅ Backfilled normalized_phone for ${backfilledCount} canonical user record(s)`);
    }
    if (duplicateReports.length > 0) {
      console.warn('⚠️ Preserved existing duplicate legacy phone records (normalized_phone cleared on secondary rows):', JSON.stringify(duplicateReports));
    }

    const [normIdx] = await conn.query("SHOW INDEX FROM users WHERE Column_name = 'normalized_phone' AND Non_unique = 0");
    if (normIdx.length === 0) {
      await conn.query('ALTER TABLE users ADD UNIQUE INDEX uniq_users_normalized_phone (normalized_phone)');
      console.log('✅ Added UNIQUE index uniq_users_normalized_phone on users(normalized_phone)');
    }
  } catch (normErr) {
    console.warn('⚠️ normalized_phone migration notice:', normErr.message);
  }

  try {
    const [uIdx] = await conn.query('SHOW INDEX FROM users WHERE Key_name = "idx_users_account_status"');
    if (uIdx.length === 0) {
      await conn.query('ALTER TABLE users ADD INDEX idx_users_account_status (account_status)');
    }
  } catch (e) {}

  // Ensure orders table has payment gateway, refund, return window & settlement columns
  await ensureColumn('orders', 'payment_gateway', '`payment_gateway` VARCHAR(40) NULL DEFAULT NULL AFTER `delivered_at`');
  await ensureColumn('orders', 'gateway_order_id', '`gateway_order_id` VARCHAR(120) NULL DEFAULT NULL AFTER `payment_gateway`');
  await ensureColumn('orders', 'gateway_payment_id', '`gateway_payment_id` VARCHAR(120) NULL DEFAULT NULL AFTER `gateway_order_id`');
  await ensureColumn('orders', 'captured_amount', '`captured_amount` DECIMAL(10,2) NULL DEFAULT NULL AFTER `gateway_payment_id`');
  await ensureColumn('orders', 'refunded_amount', '`refunded_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `captured_amount`');
  await ensureColumn('orders', 'return_window_ends_at', '`return_window_ends_at` TIMESTAMP NULL DEFAULT NULL AFTER `refunded_amount`');
  await ensureColumn('orders', 'settled_at', '`settled_at` TIMESTAMP NULL DEFAULT NULL AFTER `return_window_ends_at`');

  try {
    const [oIdx] = await conn.query('SHOW INDEX FROM orders WHERE Key_name = "idx_orders_gateway_payment"');
    if (oIdx.length === 0) {
      await conn.query('ALTER TABLE orders ADD INDEX idx_orders_gateway_payment (gateway_payment_id)');
    }
  } catch (e) {}

  // Ensure products table has category_id column
  try {
    const [cols] = await conn.query('SHOW COLUMNS FROM products LIKE "category_id"');
    if (cols.length === 0) {
      await conn.query('ALTER TABLE products ADD COLUMN category_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER category');
      console.log('✅ Added missing column category_id to products');
    }
  } catch (e) {
    // ignore
  }

  // Ensure password_reset_otps supports nullable phone, email, and channel
  try {
    const [phoneCol] = await conn.query('SHOW COLUMNS FROM password_reset_otps LIKE "phone"');
    if (phoneCol.length > 0 && (phoneCol[0].Null !== 'YES' || /varchar\(30\)/i.test(phoneCol[0].Type || ''))) {
      await conn.query('ALTER TABLE password_reset_otps MODIFY COLUMN phone VARCHAR(255) NULL DEFAULT NULL');
      console.log('✅ Updated password_reset_otps.phone to VARCHAR(255) NULL');
    }
  } catch (e) {
    // ignore
  }

  try {
    const [emailCol] = await conn.query('SHOW COLUMNS FROM password_reset_otps LIKE "email"');
    if (emailCol.length === 0) {
      await conn.query('ALTER TABLE password_reset_otps ADD COLUMN email VARCHAR(255) NULL DEFAULT NULL AFTER phone');
      console.log('✅ Added missing column email to password_reset_otps');
    }
  } catch (e) {
    // ignore
  }

  try {
    const [emailIdx] = await conn.query('SHOW INDEX FROM password_reset_otps WHERE Key_name = "idx_otp_email"');
    if (emailIdx.length === 0) {
      await conn.query('ALTER TABLE password_reset_otps ADD INDEX idx_otp_email (email)');
      console.log('✅ Added idx_otp_email index to password_reset_otps');
    }
  } catch (e) {
    // ignore
  }

  try {
    const [channelCol] = await conn.query('SHOW COLUMNS FROM password_reset_otps LIKE "channel"');
    if (channelCol.length === 0) {
      await conn.query('ALTER TABLE password_reset_otps ADD COLUMN channel VARCHAR(20) NOT NULL DEFAULT "phone" AFTER email');
      console.log('✅ Added missing column channel to password_reset_otps');
    }
  } catch (e) {
    // ignore
  }

  // Ensure default 5 product categories are seeded if categories table is empty
  try {
    const [[catCnt]] = await conn.query('SELECT COUNT(*) as cnt FROM categories');
    if ((catCnt?.cnt || 0) === 0) {
      await conn.query(`
        INSERT INTO categories (id, name, slug, description, icon, sort_order, is_active) VALUES
        (1, 'Electronics', 'electronics', 'Smartphones, headphones, gadgets & tech accessories', '📱', 1, 1),
        (2, 'Fashion', 'fashion', 'Apparel, footwear, bags, sunglasses & style essentials', '👕', 2, 1),
        (3, 'Home & Kitchen', 'home-kitchen', 'Appliances, cookware, home decor & living essentials', '🏠', 3, 1),
        (4, 'Beauty & Personal Care', 'beauty-personal-care', 'Skincare, grooming, wellness & cosmetics', '✨', 4, 1),
        (5, 'Grocery', 'grocery', 'Daily staples, snacks, beverages & household essentials', '🛒', 5, 1)
      `);
      console.log('✅ Seeded 5 starter product categories');
    }
  } catch (e) {
    // ignore
  }

  // Ensure admin user password matches Admin123!
  const adminHash = await bcrypt.hash('Admin123!', 10);
  await conn.query(
    `INSERT INTO users (id, name, email, password, role)
     VALUES (1, 'Vendor Administrator', 'admin@vendor.app', ?, 'admin')
     ON DUPLICATE KEY UPDATE name = VALUES(name), email = VALUES(email), password = VALUES(password), role = 'admin'`,
    [adminHash]
  );
  console.log('✅ Admin user verified with password Admin123!');

  console.log('✅ Tables created and migrated successfully:');
  const [tables] = await conn.query('SHOW TABLES');
  console.table(tables);

  await conn.end();
}

runMigration().catch(err => {
  console.error('\n❌ Migration failed:', err.message);
  if (err.code === 'ECONNREFUSED') {
    console.error('Hint: The database host is not reachable or MySQL service is not running.');
  } else if (err.code === 'ER_ACCESS_DENIED_ERROR') {
    console.error('Hint: Incorrect database username or password in .env.');
  } else if (err.code === 'ER_BAD_DB_ERROR') {
    console.error(`Hint: Database '${process.env.DB_DATABASE}' does not exist yet.`);
  }
  process.exit(1);
});
