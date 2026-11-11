const https = require('https');

https.get('https://knowlipop.shop/api/products?limit=50', (res) => {
  let data = '';
  res.on('data', chunk => data += chunk);
  res.on('end', () => {
    try {
      const json = JSON.parse(data);
      console.log('Total products:', json.data ? json.data.length : 0);
      if (Array.isArray(json.data)) {
        json.data.slice(0, 15).forEach(p => {
          console.log(`\nID: ${p.id} | Seller: ${p.seller_name} (ID: ${p.seller_id}) | Name: ${p.name}`);
          console.log(`  image_url: ${p.image_url}`);
          console.log(`  images:`, p.images);
          console.log(`  keys in product:`, Object.keys(p));
        });
      }
    } catch (e) {
      console.error(e.message);
    }
  });
});

