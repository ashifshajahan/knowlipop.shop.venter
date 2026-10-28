const path = require('path');
const mysql = require('mysql2/promise');
const bcrypt = require('bcryptjs');
require('dotenv').config({ path: path.join(__dirname, '../.env') });

async function seedAdmin() {
  const host = process.env.DB_HOST || 'localhost';
  const port = parseInt(process.env.DB_PORT || '3306', 10);
  const user = process.env.DB_USERNAME || 'root';
  const password = process.env.DB_PASSWORD || '';
  const database = process.env.DB_DATABASE || 'vendor_app';

  const adminEmail = process.env.ADMIN_EMAIL || 'admin@vendor.app';
  const adminPassword = process.env.ADMIN_PASSWORD || 'Admin123!';

  console.log(`[Vendor Admin Seed] Connecting to MySQL (${host}:${port}/${database})...`);

  const conn = await mysql.createConnection({ host, port, user, password, database });

  const hashed = await bcrypt.hash(adminPassword, 10);

  await conn.query(
    `INSERT INTO users (id, name, email, password, role)
     VALUES (1, 'Vendor Administrator', ?, ?, 'admin')
     ON DUPLICATE KEY UPDATE name = VALUES(name), email = VALUES(email), password = VALUES(password), role = 'admin'`,
    [adminEmail, hashed]
  );

  console.log('✅ Admin account configured successfully:');
  console.log(`   Email:    ${adminEmail}`);
  console.log(`   Password: ${adminPassword}`);

  await conn.end();
}

seedAdmin().catch(err => {
  console.error('❌ Failed to seed admin:', err.message);
  process.exit(1);
});
