const https = require('https');

function get(path, token) {
  return new Promise((resolve) => {
    const options = {
      hostname: 'knowlipop.shop',
      port: 443,
      path: path,
      method: 'GET',
      headers: { 'Accept': 'application/json' }
    };
    if (token) options.headers['Authorization'] = `Bearer ${token}`;
    const req = https.request(options, (res) => {
      let data = '';
      res.on('data', chunk => data += chunk);
      res.on('end', () => {
        try {
          resolve({ status: res.statusCode, data: JSON.parse(data) });
        } catch (e) {
          resolve({ status: res.statusCode, raw: data });
        }
      });
    });
    req.on('error', err => resolve({ error: err.message }));
    req.end();
  });
}

async function inspect() {
  console.log('--- Inspecting latest orders from https://knowlipop.shop ---');
  const res = await get('/api/orders?limit=5');
  console.log('Status:', res.status);
  if (res.data && res.data.orders && res.data.orders.data) {
    const orders = res.data.orders.data;
    console.log(`Found ${orders.length} orders:`);
    for (const o of orders.slice(0, 3)) {
      console.log('\n==================================');
      console.log(`Order ID: ${o.id}, Order Number: ${o.order_number}`);
      console.log('Order keys:', Object.keys(o));
      console.log('Total Amount:', o.total_amount, 'type:', typeof o.total_amount);
      console.log('Subtotal:', o.subtotal, 'type:', typeof o.subtotal);
      console.log('Delivery Fee:', o.delivery_fee, 'type:', typeof o.delivery_fee);
      console.log('Discount:', o.discount, 'type:', typeof o.discount);
      console.log('Items Count:', o.items_count);
      console.log('Total Quantity:', o.total_quantity);
      if (o.items && o.items.length > 0) {
        console.log('Items sample:');
        for (const it of o.items) {
          console.log('  Item:', {
            id: it.id,
            product_id: it.product_id,
            product_name: it.product_name,
            price: it.price,
            quantity: it.quantity,
            subtotal: it.subtotal,
            keys: Object.keys(it)
          });
        }
      } else {
        console.log('No items in list order!');
      }

      // Fetch detail for this order
      const detail = await get(`/api/orders/${o.id}`);
      console.log(`Detail for #${o.id}:`);
      if (detail.data && detail.data.order) {
        const do_ = detail.data.order;
        console.log('Detail order keys:', Object.keys(do_));
        console.log('Detail items:', do_.items);
        console.log('Detail subtotal:', do_.subtotal, 'total_amount:', do_.total_amount);
      } else {
        console.log('Detail failed:', detail);
      }
    }
  } else {
    console.log('Response:', res);
  }
}

inspect();
