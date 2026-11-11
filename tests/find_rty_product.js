const https = require('https');

https.get('https://knowlipop.shop/api/products?search=Rty', (res) => {
  let data = '';
  res.on('data', c => data += c);
  res.on('end', () => {
    console.log('Search Rty:', data);
  });
});

https.get('https://knowlipop.shop/api/products?search=54', (res) => {
  let data = '';
  res.on('data', c => data += c);
  res.on('end', () => {
    console.log('Search 54:', data);
  });
});

