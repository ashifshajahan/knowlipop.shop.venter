const https = require('https');

function testUrl(url) {
  return new Promise((resolve, reject) => {
    const req = https.get(url, { timeout: 10000 }, (res) => {
      let body = '';
      res.on('data', chunk => body += chunk);
      res.on('end', () => {
        resolve({
          statusCode: res.statusCode,
          headers: res.headers,
          body: body.substring(0, 500)
        });
      });
    });
    req.on('error', err => reject(err));
    req.on('timeout', () => {
      req.destroy();
      reject(new Error('Request timed out'));
    });
  });
}

async function run() {
  console.log('Testing https://knowlipop.shop...');
  try {
    const rootRes = await testUrl('https://knowlipop.shop/');
    console.log('Root / status:', rootRes.statusCode);
    console.log('Root body preview:', rootRes.body);
  } catch (err) {
    console.error('Root / failed:', err.message);
  }

  try {
    const healthRes = await testUrl('https://knowlipop.shop/api/health');
    console.log('/api/health status:', healthRes.statusCode);
    console.log('/api/health body preview:', healthRes.body);
  } catch (err) {
    console.error('/api/health failed:', err.message);
  }
}

run();
