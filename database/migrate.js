const fs = require('fs');
const path = require('path');
const mysql = require('mysql2/promise');
const bcrypt = require('bcryptjs');
require('dotenv').config({ path: path.join(__dirname, '../.env') });

async function runMigration() {
  const host = process.env.DB_HOST || '127.0.0.1';
  const port = parseInt(process.env.DB_PORT || '3306', 10);
  const user = (process.env.DB_USERNAME && process.env.DB_USERNAME !== 'root') ? process.env.DB_USERNAME : 'u999617803_xtragclub';
  const password = process.env.DB_PASSWORD || '';
  const database = (process.env.DB_DATABASE && process.env.DB_DATABASE !== 'vendor_app' && process.env.DB_DATABASE !== 'venter_app') ? process.env.DB_DATABASE : 'u999617803_knolipop';

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
