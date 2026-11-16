const fs = require('fs');
const path = require('path');

/**
 * Loads Firebase service account credentials decoded from base64 if not directly on disk.
 */
function getServiceAccount() {
  const b64Path = path.join(__dirname, 'firebase-service-account.b64');
  if (fs.existsSync(b64Path)) {
    try {
      const b64 = fs.readFileSync(b64Path, 'utf8').trim();
      const json = Buffer.from(b64, 'base64').toString('utf8');
      const obj = JSON.parse(json);
      if (obj.private_key && obj.client_email) {
        return obj;
      }
    } catch (e) {
      console.warn('[FIREBASE CREDENTIALS] Error decoding base64 credentials:', e.message);
    }
  }
  return null;
}

module.exports = {
  getServiceAccount
};
