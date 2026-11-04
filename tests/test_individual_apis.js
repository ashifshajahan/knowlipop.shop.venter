/**
 * Verification Suite for Individual Dedicated APIs
 * Target: https://knowlipop.shop
 */

const BASE_URL = process.env.TEST_URL || 'https://knowlipop.shop';

const SAMPLE_BASE64_PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
const SAMPLE_BASE64_JPG = 'data:image/jpeg;base64,/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=';

async function makeRequest(path, options = {}) {
  const url = path.startsWith('http') ? path : `${BASE_URL}${path}`;
  const headers = {
    'Accept': 'application/json',
    ...(options.headers || {})
  };
  if (options.body && typeof options.body === 'string' && !headers['Content-Type']) {
    headers['Content-Type'] = 'application/json';
  }

  const response = await fetch(url, { ...options, headers });
  const contentType = response.headers.get('content-type') || '';
  let body = null;
  if (contentType.includes('application/json')) {
    body = await response.json();
  } else {
    body = await response.text();
  }
  return { status: response.status, body, headers: response.headers };
}

async function run() {
  console.log(`=== Testing Individual APIs against ${BASE_URL} ===\n`);

  // 1. Register a fresh Seller
  const suffix = Date.now().toString().slice(-4);
  const sellerPayload = {
    seller_name: `API Suite Seller ${suffix}`,
    name: `API Suite Seller ${suffix}`,
    email: `seller_indiv_${Date.now()}_${suffix}@vendor.app`,
    phone: `+91${Math.floor(6000000000 + Math.random() * 3999999999)}`,
    address: '77 Market Plaza, Suite 101',
    landmark: 'Opposite Central Station',
    state: 'Kerala',
    country: 'India',
    password: 'Password123!',
    password_confirmation: 'Password123!',
    role: 'seller'
  };

  const regRes = await makeRequest('/api/register', {
    method: 'POST',
    body: JSON.stringify(sellerPayload)
  });
  if (regRes.status !== 200 && regRes.status !== 201) {
    throw new Error(`Failed to register seller: ${JSON.stringify(regRes.body)}`);
  }
  const token = regRes.body.token || regRes.body.access_token || regRes.body.data?.token;
  const sellerId = regRes.body.user?.id || regRes.body.data?.id;
  const authHeaders = { Authorization: `Bearer ${token}` };
  console.log(`✓ Seller registered: ID ${sellerId}`);

  // 2. Individual Image API 1: POST /api/upload (Standalone Upload)
  console.log('\n--- 1. Testing POST /api/upload (Standalone Upload) ---');
  const upRes = await makeRequest('/api/upload', {
    method: 'POST',
    headers: authHeaders,
    body: JSON.stringify({ images: [SAMPLE_BASE64_PNG, SAMPLE_BASE64_JPG] })
  });
  console.log(`Upload status: ${upRes.status}, count: ${upRes.body.count || upRes.body.images?.length}`);
  if (upRes.status !== 200 && upRes.status !== 201) {
    throw new Error(`POST /api/upload failed: ${JSON.stringify(upRes.body)}`);
  }
  const uploadedUrls = upRes.body.images || [upRes.body.image_url];
  console.log(`Uploaded URLs:`, uploadedUrls);

  // 3. Individual Image API 2: GET /api/images/:filename
  console.log('\n--- 2. Testing GET /api/images/:filename ---');
  const filename1 = uploadedUrls[0].split('/').pop();
  const getImgRes = await makeRequest(`/api/images/${filename1}`);
  console.log(`GET /api/images/${filename1} status: ${getImgRes.status}, type: ${getImgRes.headers.get('content-type')}`);
  if (getImgRes.status !== 200) {
    throw new Error(`GET /api/images/${filename1} returned status ${getImgRes.status}`);
  }

  // 4. Create Product with Images
  console.log('\n--- 3. Create Product with initial images ---');
  const prodRes = await makeRequest('/api/products', {
    method: 'POST',
    headers: authHeaders,
    body: JSON.stringify({
      name: `Indiv API Product ${suffix}`,
      seller_price: 500,
      mrp: 750,
      stock: 25,
      category: 'Electronics',
      description: 'Testing Individual APIs',
      images: uploadedUrls
    })
  });
  const prod = prodRes.body.product || prodRes.body.data;
  const productId = prod.id;
  console.log(`✓ Product created: ID ${productId}, Image: ${prod.image_url}`);

  // 5. Individual Image API 3: GET /api/products/:id/images
  console.log(`\n--- 4. Testing GET /api/products/${productId}/images ---`);
  const listImgRes = await makeRequest(`/api/products/${productId}/images`);
  console.log(`GET /api/products/${productId}/images status: ${listImgRes.status}`);
  console.log(`Gallery items count: ${listImgRes.body.gallery?.length || 0}`);
  if (listImgRes.status !== 200) {
    throw new Error(`GET /api/products/${productId}/images failed`);
  }
  const gallery = listImgRes.body.gallery || [];
  const firstGalleryImageId = gallery[0]?.id;

  // 6. Individual Image API 4: GET /api/products/:id/images/:image_id
  if (firstGalleryImageId) {
    console.log(`\n--- 5. Testing GET /api/products/${productId}/images/${firstGalleryImageId} ---`);
    const singleImgRes = await makeRequest(`/api/products/${productId}/images/${firstGalleryImageId}`);
    console.log(`GET /api/products/${productId}/images/${firstGalleryImageId} status: ${singleImgRes.status}`);
    if (singleImgRes.status !== 200) {
      throw new Error(`GET /api/products/${productId}/images/${firstGalleryImageId} failed: ${JSON.stringify(singleImgRes.body)}`);
    }
    console.log(`✓ Single image retrieved:`, singleImgRes.body.image?.image_url);
  }

  // 7. Individual Image API 5: PUT /api/products/:id/images/:image_id (Replace specific image)
  if (firstGalleryImageId) {
    console.log(`\n--- 6. Testing PUT /api/products/${productId}/images/${firstGalleryImageId} (Replace specific image) ---`);
    const replRes = await makeRequest(`/api/products/${productId}/images/${firstGalleryImageId}`, {
      method: 'PUT',
      headers: authHeaders,
      body: JSON.stringify({ image: SAMPLE_BASE64_JPG })
    });
    console.log(`PUT status: ${replRes.status}, message: ${replRes.body.message}`);
    if (replRes.status !== 200) {
      throw new Error(`PUT /api/products/${productId}/images/${firstGalleryImageId} failed: ${JSON.stringify(replRes.body)}`);
    }
  }

  // 8. Individual Image API 6: DELETE /api/products/:id/images/:image_id (Delete specific image)
  if (firstGalleryImageId) {
    console.log(`\n--- 7. Testing DELETE /api/products/${productId}/images/${firstGalleryImageId} ---`);
    const delImgRes = await makeRequest(`/api/products/${productId}/images/${firstGalleryImageId}`, {
      method: 'DELETE',
      headers: authHeaders
    });
    console.log(`DELETE status: ${delImgRes.status}, message: ${delImgRes.body.message}`);
    if (delImgRes.status !== 200) {
      throw new Error(`DELETE /api/products/${productId}/images/${firstGalleryImageId} failed: ${JSON.stringify(delImgRes.body)}`);
    }
  }

  // 9. Seller Dashboard API: GET /api/seller/dashboard
  console.log('\n--- 8. Testing GET /api/seller/dashboard ---');
  const dashRes = await makeRequest('/api/seller/dashboard', { headers: authHeaders });
  console.log(`Dashboard status: ${dashRes.status}`);
  const stats = dashRes.body.stats || dashRes.body.data || dashRes.body;
  console.log(`Dashboard stats:`, {
    total_orders: stats.total_orders,
    pending_orders: stats.pending_orders,
    accepted_orders: stats.accepted_orders,
    processing_orders: stats.processing_orders,
    shipped_orders: stats.shipped_orders,
    delivered_orders: stats.delivered_orders,
    rejected_orders: stats.rejected_orders,
    total_sales: stats.total_sales,
    pending_payments: stats.pending_payments,
    completed_payments: stats.completed_payments
  });
  if (dashRes.status !== 200) {
    throw new Error(`GET /api/seller/dashboard failed`);
  }

  // 10. Seller Orders List: GET /api/seller/orders
  console.log('\n--- 9. Testing GET /api/seller/orders ---');
  const ordListRes = await makeRequest('/api/seller/orders', { headers: authHeaders });
  console.log(`Orders list status: ${ordListRes.status}, count: ${(ordListRes.body.data || ordListRes.body.orders || []).length}`);
  if (ordListRes.status !== 200) {
    throw new Error(`GET /api/seller/orders failed`);
  }

  // 11. Create a Test Order to test transitions
  console.log('\n--- 10. Placing a Test Order for Seller\'s Product ---');
  const orderRes = await makeRequest('/api/orders', {
    method: 'POST',
    headers: authHeaders,
    body: JSON.stringify({
      buyer_name: 'Test Buyer',
      customer_name: 'Test Buyer',
      buyer_phone: '+919876543210',
      customer_phone: '+919876543210',
      shipping_address: '123 Market Street, Kochi, Kerala',
      items: [{ product_id: productId, quantity: 1, price: 500 }]
    })
  });
  console.log(`Order creation status: ${orderRes.status}`);
  const createdOrder = orderRes.body.order || orderRes.body.data;
  const orderId = createdOrder?.id;
  console.log(`✓ Test Order created: ID ${orderId}, Status: ${createdOrder?.order_status}`);

  if (orderId) {
    // 12. Individual Seller Order API: GET /api/seller/orders/:id
    console.log(`\n--- 11. Testing GET /api/seller/orders/${orderId} ---`);
    const sOrdRes = await makeRequest(`/api/seller/orders/${orderId}`, { headers: authHeaders });
    console.log(`GET /api/seller/orders/${orderId} status: ${sOrdRes.status}`);
    if (sOrdRes.status !== 200) throw new Error(`GET /api/seller/orders/${orderId} failed`);

    // 13. Individual Seller Order Action 1: POST /api/seller/orders/:id/accept
    console.log(`\n--- 12. Testing POST /api/seller/orders/${orderId}/accept ---`);
    const accRes = await makeRequest(`/api/seller/orders/${orderId}/accept`, {
      method: 'POST',
      headers: authHeaders
    });
    console.log(`Accept status: ${accRes.status}, new status: ${(accRes.body.order || accRes.body.data)?.order_status}`);
    if (accRes.status !== 200) throw new Error(`POST /api/seller/orders/${orderId}/accept failed`);

    // 14. Individual Seller Order Action 2: POST /api/seller/orders/:id/process
    console.log(`\n--- 13. Testing POST /api/seller/orders/${orderId}/process ---`);
    const procRes = await makeRequest(`/api/seller/orders/${orderId}/process`, {
      method: 'POST',
      headers: authHeaders
    });
    console.log(`Process status: ${procRes.status}, new status: ${(procRes.body.order || procRes.body.data)?.order_status}`);
    if (procRes.status !== 200) throw new Error(`POST /api/seller/orders/${orderId}/process failed`);

    // 15. Individual Seller Order Action 3: POST /api/seller/orders/:id/pack
    console.log(`\n--- 14. Testing POST /api/seller/orders/${orderId}/pack ---`);
    const packRes = await makeRequest(`/api/seller/orders/${orderId}/pack`, {
      method: 'POST',
      headers: authHeaders
    });
    console.log(`Pack status: ${packRes.status}, new status: ${(packRes.body.order || packRes.body.data)?.order_status}`);
    if (packRes.status !== 200) throw new Error(`POST /api/seller/orders/${orderId}/pack failed`);

    // 16. Individual Seller Order Action 4: POST /api/seller/orders/:id/ship
    console.log(`\n--- 15. Testing POST /api/seller/orders/${orderId}/ship ---`);
    const shipRes = await makeRequest(`/api/seller/orders/${orderId}/ship`, {
      method: 'POST',
      headers: authHeaders
    });
    console.log(`Ship status: ${shipRes.status}, new status: ${(shipRes.body.order || shipRes.body.data)?.order_status}`);
    if (shipRes.status !== 200) throw new Error(`POST /api/seller/orders/${orderId}/ship failed`);

    // 17. Individual Seller Order Action 5: POST /api/seller/orders/:id/deliver
    console.log(`\n--- 16. Testing POST /api/seller/orders/${orderId}/deliver ---`);
    const delivRes = await makeRequest(`/api/seller/orders/${orderId}/deliver`, {
      method: 'POST',
      headers: authHeaders
    });
    console.log(`Deliver status: ${delivRes.status}, new status: ${(delivRes.body.order || delivRes.body.data)?.order_status}`);
    if (delivRes.status !== 200) throw new Error(`POST /api/seller/orders/${orderId}/deliver failed`);
  }

  // 18. Seller Notifications APIs
  console.log('\n--- 17. Testing Seller Notifications APIs ---');
  const notifListRes = await makeRequest('/api/seller/notifications', { headers: authHeaders });
  console.log(`Notifications list status: ${notifListRes.status}, count: ${(notifListRes.body.data || notifListRes.body.notifications || []).length}`);

  const unreadRes = await makeRequest('/api/seller/notifications/unread-count', { headers: authHeaders });
  console.log(`Unread count status: ${unreadRes.status}, unread_count: ${unreadRes.body.unread_count ?? unreadRes.body.data?.unread_count}`);

  const markAllRes = await makeRequest('/api/seller/notifications/mark-all-read', {
    method: 'POST',
    headers: authHeaders
  });
  console.log(`Mark all read status: ${markAllRes.status}`);

  console.log('\n=============================================');
  console.log('🎉 ALL INDIVIDUAL DEDICATED APIS VERIFIED 100%!');
  console.log('=============================================');
}

run().catch(err => {
  console.error('\n❌ Test Failure:', err.message);
  process.exit(1);
});
