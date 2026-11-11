const https = require('https');

https.get('https://knowlipop.shop/api/products', (res) => {
  let data = '';
  res.on('data', chunk => data += chunk);
  res.on('end', () => {
    try {
      const json = JSON.parse(data);
      console.log('Top keys:', Object.keys(json));
      console.log('Pagination:', json.pagination);
      console.log('Products count:', json.data ? json.data.length : 'none');
      if (Array.isArray(json.data)) {
        json.data.forEach(p => {
          console.log(`ID: ${p.id} | Name: ${p.name} | Images count: ${p.images ? p.images.length : 0}`);
          if (p.images && p.images.length > 0) {
            console.log('   Primary image_url:', p.image_url);
            console.log('   images:', p.images);
          }
        });
      }
    } catch (e) {
      console.error(e.message, data.slice(0, 300));
    }
  });
});

