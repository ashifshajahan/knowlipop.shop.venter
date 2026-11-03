const https = require('https');

function testUrl(url) {
  return new Promise((resolve) => {
    const req = https.get(url, { timeout: 10000 }, (res) => {
      let body = '';
      res.on('data', chunk => body += chunk);
      res.on('end', () => {
        resolve({
          url,
          status: res.statusCode,
          headers: res.headers,
          body: body.substring(0, 300)
        });
      });
    });
    req.on('error', err => resolve({ url, error: err.message }));
    req.on('timeout', () => {
      req.destroy();
      resolve({ url, error: 'Timeout' });
    });
  });
}

async function probe() {
  const urls = [
    'https://knowlipop.shop/',
    'https://knowlipop.shop/index.php',
    'https://knowlipop.shop/api/health',
    'https://knowlipop.shop/api/orders.php',
    'https://knowlipop.shop/server.js'
  ];

  for (const u of urls) {
    const r = await testUrl(u);
    console.log(`[${r.status || 'ERR'}] ${r.url}`);
    if (r.body) console.log('   Preview:', r.body.replace(/\r?\n|\r/g, ' ').substring(0, 100));
    if (r.error) console.log('   Error:', r.error);
  }
}

probe();

