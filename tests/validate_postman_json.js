const fs = require('fs');

const files = [
  'Vendor_App_Postman_Collection.json',
  'Vendor_App_API.postman_collection.json',
  'Vendor_App_Environment.postman_environment.json'
];

for (const file of files) {
  try {
    const raw = fs.readFileSync(file, 'utf8');
    const parsed = JSON.parse(raw);
    console.log(`[VALID JSON] ${file} (${(raw.length / 1024).toFixed(1)} KB)`);
  } catch (e) {
    console.error(`[INVALID JSON] ${file}: ${e.message}`);
    process.exit(1);
  }
}
console.log('\nAll Postman JSON files successfully validated!');
