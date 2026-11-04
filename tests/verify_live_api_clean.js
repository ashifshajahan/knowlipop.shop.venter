const https = require('https');

function fetchJson(url) {
  return new Promise((resolve, reject) => {
    https.get(url, (res) => {
      let data = '';
      res.on('data', chunk => data += chunk);
      res.on('end', () => {
        try {
          resolve({ status: res.statusCode, body: JSON.parse(data) });
        } catch (e) {
          reject(new Error(`Failed to parse JSON from ${url}: ${e.message}\nRaw: ${data.slice(0, 200)}`));
        }
      });
    }).on('error', reject);
  });
}

function checkHead(url) {
  return new Promise((resolve) => {
    try {
      const parsed = new URL(url);
      const req = https.request({
        method: 'HEAD',
        hostname: parsed.hostname,
        path: parsed.pathname + (parsed.search || ''),
      }, (res) => {
        resolve(res.statusCode);
      });
      req.on('error', () => resolve(null));
      req.end();
    } catch (e) {
      resolve(null);
    }
  });
}

async function verify() {
  console.log('--- Verifying Live Clean API Response on https://knowlipop.shop ---\n');

  // 1. GET /api/products
  console.log('1. Fetching GET https://knowlipop.shop/api/products ...');
  const resList = await fetchJson('https://knowlipop.shop/api/products');
  console.log(`HTTP Status: ${resList.status}`);
  const listKeys = Object.keys(resList.body);
  console.log('Top-level response keys:', listKeys);

  const hasItems = 'items' in resList.body;
  const hasResults = 'results' in resList.body;
  const hasProductsData = resList.body.products && 'data' in resList.body.products;
  console.log(`- 'items' present: ${hasItems} (should be false)`);
  console.log(`- 'results' present: ${hasResults} (should be false)`);
  console.log(`- 'products.data' present: ${hasProductsData} (should be false)`);

  const products = resList.body.data;
  console.log(`Total products returned in 'data': ${Array.isArray(products) ? products.length : 'not an array'}`);

  // Find product 106 in list
  const prod106InList = products.find(p => p.id === 106);
  if (prod106InList) {
    console.log(`Found Product 106 in main 'data' list: "${prod106InList.name}"`);
    console.log('Product 106 keys in list:', Object.keys(prod106InList));
    console.log('image_url:', prod106InList.image_url);
    console.log('images count:', Array.isArray(prod106InList.images) ? prod106InList.images.length : 'none');
    console.log('images:', prod106InList.images);
    console.log('gallery_items count:', Array.isArray(prod106InList.gallery_items) ? prod106InList.gallery_items.length : 'none');
    console.log('- image_urls present:', 'image_urls' in prod106InList);
    console.log('- product_images present:', 'product_images' in prod106InList);
    console.log('- gallery present:', 'gallery' in prod106InList);
    console.log('- photos present:', 'photos' in prod106InList);
  } else {
    console.log('Product 106 not in the first page of /api/products (might be on later page or inactive)');
  }

  // Check product IDs uniqueness in list
  const idCounts = {};
  for (const p of products) {
    idCounts[p.id] = (idCounts[p.id] || 0) + 1;
  }
  const duplicateIds = Object.entries(idCounts).filter(([_, count]) => count > 1);
  console.log(`Duplicate product IDs in main 'data' list: ${duplicateIds.length === 0 ? 'None (All Unique)' : JSON.stringify(duplicateIds)}`);

  console.log('\n2. Fetching GET https://knowlipop.shop/api/products/106 ...');
  const resSingle = await fetchJson('https://knowlipop.shop/api/products/106');
  console.log(`HTTP Status: ${resSingle.status}`);
  console.log('Single product top-level response keys:', Object.keys(resSingle.body));

  const prod106 = resSingle.body.data || resSingle.body.product;
  console.log('Product 106 keys:', Object.keys(prod106));
  console.log('image_url:', prod106.image_url);
  console.log('images count:', Array.isArray(prod106.images) ? prod106.images.length : 'none');
  console.log('images list:');
  for (let i = 0; i < (prod106.images || []).length; i++) {
    const img = prod106.images[i];
    const status = await checkHead(img);
    console.log(`  [${i + 1}] ${img} -> HTTP ${status}`);
  }

  console.log('gallery_items:');
  for (const gi of (prod106.gallery_items || [])) {
    console.log(`  id: ${gi.id}, product_id: ${gi.product_id}, image_url: ${gi.image_url}`);
  }

  console.log('\nSummary of Cleanliness Checks:');
  console.log('1. Top-level duplicate collections removed:', !hasItems && !hasResults && !hasProductsData ? 'PASS' : 'FAIL');
  console.log('2. Each product appears only once in data collection:', duplicateIds.length === 0 ? 'PASS' : 'FAIL');
  console.log('3. Primary image_url is string:', typeof prod106.image_url === 'string' ? 'PASS' : 'FAIL');
  console.log('4. images contains all 4 seller-uploaded URLs:', prod106.images.length === 4 ? 'PASS' : 'FAIL');
  console.log('5. gallery_items contains 4 items with metadata:', prod106.gallery_items.length === 4 ? 'PASS' : 'FAIL');
  console.log('6. Redundant image aliases removed from product:',
    !('image_urls' in prod106) && !('product_images' in prod106) && !('gallery' in prod106) && !('photos' in prod106) ? 'PASS' : 'FAIL'
  );
  console.log('7. No demo/Unsplash URLs present:',
    !JSON.stringify(prod106).includes('unsplash') && !JSON.stringify(prod106).includes('placeholder') ? 'PASS' : 'FAIL'
  );
}

verify().catch(console.error);
