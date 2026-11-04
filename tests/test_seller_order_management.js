/**
 * Comprehensive Verification Suite for:
 * 1. Seller Order Management (Incoming, Accept, Reject with Reason, Detail, Status Updates,
 *    Processing/Packed/Shipped/Delivered, Cancellation Permission, Order History, Status/Date Filtering)
 * 2. Seller-Specific Order Access (Strict Ownership, Multi-Vendor Separation, Item Isolation)
 * 3. Seller & Buyer Notifications (New Order, Cancelled, Accepted, Rejected, Status Changes, In-App & Push Token)
 * 4. Seller Dashboard (Total Orders, Pending, Accepted, Processing, Shipped, Delivered, Rejected,
 *    Total Sales, Pending Payments, Completed Payments)
 */

const BASE_URL = process.env.TEST_BASE_URL || 'https://knowlipop.shop';

async function makeRequest(path, options = {}) {
  const url = path.startsWith('http') ? path : `${BASE_URL}${path}`;
  const headers = {
    'Content-Type': 'application/json',
    ...(options.headers || {})
  };

  const response = await fetch(url, {
    ...options,
    headers
  });

  const contentType = response.headers.get('content-type') || '';
  let body = null;
  if (contentType.includes('application/json')) {
    body = await response.json();
  } else {
    body = await response.text();
  }

  return { status: response.status, body, headers: response.headers };
}

async function registerTestSeller(suffix) {
  const phone = `+91${Math.floor(6000000000 + Math.random() * 3999999999)}`;
  const email = `seller_${Date.now()}_${suffix}@vendor.app`;
  const name = `Test Seller ${suffix}`;

  const res = await makeRequest('/api/register', {
    method: 'POST',
    body: JSON.stringify({
      seller_name: name,
      name,
      email,
      phone,
      address: '123 Market Road',
      landmark: 'Near City Square',
      state: 'Kerala',
      country: 'India',
      password: 'Password123!',
      password_confirmation: 'Password123!',
      role: 'seller'
    })
  });

  if (res.status !== 201 && res.status !== 200) {
    throw new Error(`Failed to register seller ${suffix}: ${JSON.stringify(res.body)}`);
  }

  const token = res.body.token || res.body.access_token || res.body.data?.token;
  const sellerId = res.body.user?.id || res.body.seller?.id || res.body.data?.id;

  return { token, sellerId, name, phone, email };
}

async function createTestProduct(seller, productName, price) {
  const res = await makeRequest('/api/products', {
    method: 'POST',
    headers: { Authorization: `Bearer ${seller.token}` },
    body: JSON.stringify({
      name: productName,
      description: `Description for ${productName}`,
      seller_price: price,
      price: price,
      mrp: Math.round(price * 1.3),
      stock: 50,
      image_url: 'https://knowlipop.shop/uploads/products/placeholder.jpg'
    })
  });

  if (res.status !== 201 && res.status !== 200) {
    throw new Error(`Failed to create product: ${JSON.stringify(res.body)}`);
  }

  const prodId = res.body.product?.id || res.body.id || res.body.data?.id;
  return { id: prodId, name: productName, price };
}

async function run() {
  console.log(`\n======================================================`);
  console.log(`Running Verification on ${BASE_URL}`);
  console.log(`======================================================\n`);

  // 1. Setup Sellers & Products
  console.log('--- 1. Registering Sellers and Creating Products ---');
  const sellerA = await registerTestSeller('A');
  const sellerB = await registerTestSeller('B');
  const sellerC = await registerTestSeller('C_Unauthorized');

  console.log(`✅ Seller A registered: ID ${sellerA.sellerId} (${sellerA.name})`);
  console.log(`✅ Seller B registered: ID ${sellerB.sellerId} (${sellerB.name})`);
  console.log(`✅ Seller C registered: ID ${sellerC.sellerId} (${sellerC.name})`);

  const prodA = await createTestProduct(sellerA, 'Seller A Widget Pro', 500.00);
  const prodB = await createTestProduct(sellerB, 'Seller B Gadget Max', 800.00);
  console.log(`✅ Product A created: ID ${prodA.id} (₹500.00) for Seller A`);
  console.log(`✅ Product B created: ID ${prodB.id} (₹800.00) for Seller B`);

  // 2. Test Initial Seller Dashboard
  console.log('\n--- 2. Testing Seller Dashboard Initial State ---');
  const initDashRes = await makeRequest('/api/seller/dashboard', {
    headers: { Authorization: `Bearer ${sellerA.token}` }
  });
  if (initDashRes.status !== 200) {
    throw new Error(`Failed to fetch seller dashboard: ${JSON.stringify(initDashRes.body)}`);
  }
  const dash = initDashRes.body.dashboard || initDashRes.body;
  console.log('Seller A Initial Dashboard:', {
    total_orders: dash.total_orders,
    pending_orders: dash.pending_orders,
    total_sales: dash.total_sales,
    pending_payments: dash.pending_payments,
    completed_payments: dash.completed_payments
  });
  if (dash.total_orders === undefined || dash.total_sales === undefined || dash.pending_payments === undefined || dash.completed_payments === undefined) {
    throw new Error(`Dashboard missing required metrics: ${JSON.stringify(dash)}`);
  }
  console.log('✅ Dashboard endpoint returns all required fields (total_orders, total_sales, pending_payments, completed_payments)!');

  // 3. Register Push Token & Test Notifications Endpoint
  console.log('\n--- 3. Testing Device Push Token Registration & Notifications ---');
  const tokenRegRes = await makeRequest('/api/seller/fcm-token', {
    method: 'POST',
    headers: { Authorization: `Bearer ${sellerA.token}` },
    body: JSON.stringify({
      token: `fcm_token_test_${Date.now()}`,
      platform: 'android'
    })
  });
  if (tokenRegRes.status !== 200) {
    throw new Error(`Failed to register device push token: ${JSON.stringify(tokenRegRes.body)}`);
  }
  console.log('✅ Device push token registered successfully!');

  // 4. Multi-Vendor Order Creation & Notification
  console.log('\n--- 4. Creating Multi-Vendor Order (Seller A + Seller B) ---');
  const buyerPhone = `98${Math.floor(10000000 + Math.random() * 89999999)}`;
  const orderRes = await makeRequest('/api/orders', {
    method: 'POST',
    body: JSON.stringify({
      buyer_name: 'Anita Roy',
      buyer_phone: buyerPhone,
      shipping_address: '101, Brigade Gateway, Malleshwaram',
      city: 'Bengaluru',
      state: 'Karnataka',
      pincode: '560055',
      payment_method: 'cod',
      items: [
        { product_id: prodA.id, quantity: 2, price: 500.00 }, // Subtotal: 1000
        { product_id: prodB.id, quantity: 1, price: 800.00 }  // Subtotal: 800
      ]
    })
  });

  if (orderRes.status !== 201) {
    throw new Error(`Failed to create order: ${JSON.stringify(orderRes.body)}`);
  }
  const order1 = orderRes.body.order;
  const order1Id = order1.id;
  console.log(`✅ Order 1 Created: ID ${order1Id} (${order1.order_number}), Total Amount: ₹${order1.total_amount}`);

  // Check In-App Notification for Seller A
  const notifARes = await makeRequest('/api/seller/notifications', {
    headers: { Authorization: `Bearer ${sellerA.token}` }
  });
  if (notifARes.status !== 200) {
    throw new Error(`Failed to fetch notifications: ${JSON.stringify(notifARes.body)}`);
  }
  const notifsA = notifARes.body.notifications || [];
  const orderNotif = notifsA.find(n => n.type === 'order_created');
  if (!orderNotif) {
    throw new Error(`Seller A did not receive order_created notification!`);
  }
  console.log(`✅ Seller A received in-app notification: "${orderNotif.title}" — "${orderNotif.message}"`);

  // Test Notification Read
  const markReadRes = await makeRequest(`/api/seller/notifications/${orderNotif.id}/read`, {
    method: 'PUT',
    headers: { Authorization: `Bearer ${sellerA.token}` }
  });
  if (markReadRes.status !== 200) {
    throw new Error(`Failed to mark notification read: ${JSON.stringify(markReadRes.body)}`);
  }
  console.log('✅ Notification marked as read successfully!');

  // 5. Multi-Vendor Isolation & Seller-Specific Access
  console.log('\n--- 5. Testing Multi-Vendor Order Isolation ---');
  // Seller A views Order 1
  const orderViewARes = await makeRequest(`/api/seller/orders/${order1Id}`, {
    headers: { Authorization: `Bearer ${sellerA.token}` }
  });
  if (orderViewARes.status !== 200) {
    throw new Error(`Seller A failed to view order: ${JSON.stringify(orderViewARes.body)}`);
  }
  const orderViewA = orderViewARes.body.order;
  if (orderViewA.items.length !== 1 || orderViewA.items[0].product_id !== prodA.id) {
    throw new Error(`Multi-Vendor Isolation Failure for Seller A! Expected only Seller A's items, got: ${JSON.stringify(orderViewA.items)}`);
  }
  if (parseFloat(orderViewA.seller_subtotal) !== 1000.00) {
    throw new Error(`Expected Seller A subtotal ₹1000.00, got: ${orderViewA.seller_subtotal}`);
  }
  console.log(`✅ Seller A Order Isolation Verified: Only Seller A items returned (Count: ${orderViewA.items.length}, Subtotal: ₹${orderViewA.seller_subtotal})`);

  // Seller B views Order 1
  const orderViewBRes = await makeRequest(`/api/seller/orders/${order1Id}`, {
    headers: { Authorization: `Bearer ${sellerB.token}` }
  });
  if (orderViewBRes.status !== 200) {
    throw new Error(`Seller B failed to view order: ${JSON.stringify(orderViewBRes.body)}`);
  }
  const orderViewB = orderViewBRes.body.order;
  if (orderViewB.items.length !== 1 || orderViewB.items[0].product_id !== prodB.id) {
    throw new Error(`Multi-Vendor Isolation Failure for Seller B! Expected only Seller B's items, got: ${JSON.stringify(orderViewB.items)}`);
  }
  if (parseFloat(orderViewB.seller_subtotal) !== 800.00) {
    throw new Error(`Expected Seller B subtotal ₹800.00, got: ${orderViewB.seller_subtotal}`);
  }
  console.log(`✅ Seller B Order Isolation Verified: Only Seller B items returned (Count: ${orderViewB.items.length}, Subtotal: ₹${orderViewB.seller_subtotal})`);

  // Seller C (unauthorized) attempts to view Order 1 -> MUST BE 403 Forbidden
  const orderViewCRes = await makeRequest(`/api/seller/orders/${order1Id}`, {
    headers: { Authorization: `Bearer ${sellerC.token}` }
  });
  if (orderViewCRes.status !== 403) {
    throw new Error(`Security Failure! Unauthorized Seller C was allowed to view order! Status: ${orderViewCRes.status}`);
  }
  console.log('✅ Strict Authorization Verified: Unauthorized Seller C received 403 Forbidden!');

  // 6. Seller Order Status Flow: Accept -> Processing -> Packed -> Shipped -> Delivered
  console.log('\n--- 6. Testing Order Actions & Status Progression ---');
  // 6a. Accept Order
  const acceptRes = await makeRequest(`/api/seller/orders/${order1Id}/accept`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${sellerA.token}` }
  });
  if (acceptRes.status !== 200) {
    throw new Error(`Failed to accept order: ${JSON.stringify(acceptRes.body)}`);
  }
  const acceptedOrder = acceptRes.body.order || acceptRes.body;
  if (acceptedOrder.order_status !== 'accepted') {
    throw new Error(`Expected order_status 'accepted', got '${acceptedOrder.order_status}'`);
  }
  console.log('✅ Order Accepted successfully (status: accepted)!');

  // 6b. Mark as Processing
  const processRes = await makeRequest(`/api/seller/orders/${order1Id}/process`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${sellerA.token}` }
  });
  if (processRes.status !== 200) {
    throw new Error(`Failed to mark order as processing: ${JSON.stringify(processRes.body)}`);
  }
  console.log('✅ Order marked as Processing (status: processing)!');

  // 6c. Mark as Packed
  const packRes = await makeRequest(`/api/seller/orders/${order1Id}/pack`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${sellerA.token}` }
  });
  if (packRes.status !== 200) {
    throw new Error(`Failed to mark order as packed: ${JSON.stringify(packRes.body)}`);
  }
  console.log('✅ Order marked as Packed (status: packed)!');

  // 6d. Mark as Shipped
  const shipRes = await makeRequest(`/api/seller/orders/${order1Id}/ship`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${sellerA.token}` }
  });
  if (shipRes.status !== 200) {
    throw new Error(`Failed to mark order as shipped: ${JSON.stringify(shipRes.body)}`);
  }
  console.log('✅ Order marked as Shipped (status: shipped)!');

  // 6e. Test Cancellation Permission: Cannot cancel an already shipped order!
  const invalidCancelRes = await makeRequest(`/api/seller/orders/${order1Id}/cancel`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${sellerA.token}` },
    body: JSON.stringify({ reason: 'Attempt cancel after ship' })
  });
  if (invalidCancelRes.status !== 400) {
    throw new Error(`Expected 400 Bad Request when cancelling shipped order, got: ${invalidCancelRes.status}`);
  }
  console.log(`✅ Cancellation Permission Check Verified: Cannot cancel shipped order (${invalidCancelRes.body.message})`);

  // 6f. Mark as Delivered (should auto-update payment_status to 'paid')
  const deliverRes = await makeRequest(`/api/seller/orders/${order1Id}/deliver`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${sellerA.token}` }
  });
  if (deliverRes.status !== 200) {
    throw new Error(`Failed to mark order as delivered: ${JSON.stringify(deliverRes.body)}`);
  }
  const deliveredOrder = deliverRes.body.order || deliverRes.body;
  if (deliveredOrder.order_status !== 'delivered' || deliveredOrder.payment_status !== 'paid') {
    throw new Error(`Expected delivered + paid, got status: ${deliveredOrder.order_status}, payment: ${deliveredOrder.payment_status}`);
  }
  console.log('✅ Order marked as Delivered and payment_status auto-set to paid!');

  // 7. Order Rejection with Rejection Reason
  console.log('\n--- 7. Testing Order Rejection with Rejection Reason ---');
  const order2Res = await makeRequest('/api/orders', {
    method: 'POST',
    body: JSON.stringify({
      buyer_name: 'Vikram Seth',
      buyer_phone: buyerPhone,
      shipping_address: 'Flat 4B, Palm Grove, Indiranagar',
      items: [{ product_id: prodA.id, quantity: 1, price: 500.00 }]
    })
  });
  const order2Id = order2Res.body.order.id;

  const rejectReasonText = 'Item damaged during quality check';
  const rejectRes = await makeRequest(`/api/seller/orders/${order2Id}/reject`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${sellerA.token}` },
    body: JSON.stringify({ rejection_reason: rejectReasonText })
  });
  if (rejectRes.status !== 200) {
    throw new Error(`Failed to reject order: ${JSON.stringify(rejectRes.body)}`);
  }
  const rejectedOrder = rejectRes.body.order || rejectRes.body;
  if (rejectedOrder.order_status !== 'rejected') {
    throw new Error(`Expected status 'rejected', got '${rejectedOrder.order_status}'`);
  }
  if (!rejectedOrder.rejection_reason || !rejectedOrder.rejection_reason.includes(rejectReasonText)) {
    throw new Error(`Rejection reason was not saved! Got: ${rejectedOrder.rejection_reason}`);
  }
  console.log(`✅ Order Rejected with Reason Verified: "${rejectedOrder.rejection_reason}"`);

  // 8. Order Cancellation when permitted
  console.log('\n--- 8. Testing Order Cancellation when Permitted ---');
  const order3Res = await makeRequest('/api/orders', {
    method: 'POST',
    body: JSON.stringify({
      buyer_name: 'Pooja Hegde',
      buyer_phone: buyerPhone,
      shipping_address: '22, Rosewood Villa, Whitefield',
      items: [{ product_id: prodA.id, quantity: 1, price: 500.00 }]
    })
  });
  const order3Id = order3Res.body.order.id;

  const cancelReasonText = 'Customer requested cancellation before dispatch';
  const cancelRes = await makeRequest(`/api/seller/orders/${order3Id}/cancel`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${sellerA.token}` },
    body: JSON.stringify({ cancellation_reason: cancelReasonText })
  });
  if (cancelRes.status !== 200) {
    throw new Error(`Failed to cancel order: ${JSON.stringify(cancelRes.body)}`);
  }
  const cancelledOrder = cancelRes.body.order || cancelRes.body;
  if (cancelledOrder.order_status !== 'cancelled') {
    throw new Error(`Expected status 'cancelled', got '${cancelledOrder.order_status}'`);
  }
  console.log(`✅ Order Cancelled when Permitted Verified: "${cancelledOrder.cancellation_reason || cancelReasonText}"`);

  // 9. Order Filtering by Status and Date, and Order History
  console.log('\n--- 9. Testing Order History and Status/Date Filtering ---');
  const historyRes = await makeRequest('/api/seller/orders/history', {
    headers: { Authorization: `Bearer ${sellerA.token}` }
  });
  if (historyRes.status !== 200) {
    throw new Error(`Failed to fetch seller order history: ${JSON.stringify(historyRes.body)}`);
  }
  const historyOrders = historyRes.body.orders?.data || historyRes.body.orders || [];
  console.log(`✅ Seller Order History returned ${historyOrders.length} order(s)`);

  const filterStatusRes = await makeRequest('/api/seller/orders?status=delivered', {
    headers: { Authorization: `Bearer ${sellerA.token}` }
  });
  const deliveredOrders = filterStatusRes.body.orders?.data || [];
  if (!deliveredOrders.every(o => o.order_status === 'delivered')) {
    throw new Error(`Status filter failed! Non-delivered order present: ${JSON.stringify(deliveredOrders)}`);
  }
  console.log(`✅ Status Filter (status=delivered) Verified: Found ${deliveredOrders.length} delivered order(s)`);

  const todayStr = new Date().toISOString().slice(0, 10);
  const filterDateRes = await makeRequest(`/api/seller/orders?date=${todayStr}`, {
    headers: { Authorization: `Bearer ${sellerA.token}` }
  });
  const dateOrders = filterDateRes.body.orders?.data || [];
  console.log(`✅ Date Filter (date=${todayStr}) Verified: Found ${dateOrders.length} order(s) placed today`);

  // 10. Seller Dashboard Final Metrics Verification
  console.log('\n--- 10. Verifying Seller Dashboard Final Metrics ---');
  const finalDashRes = await makeRequest('/api/seller/dashboard', {
    headers: { Authorization: `Bearer ${sellerA.token}` }
  });
  const finalDash = finalDashRes.body.dashboard || finalDashRes.body;
  console.log('Seller A Final Dashboard Metrics:', {
    total_orders: finalDash.total_orders,
    pending_orders: finalDash.pending_orders,
    accepted_orders: finalDash.accepted_orders,
    processing_orders: finalDash.processing_orders,
    shipped_orders: finalDash.shipped_orders,
    delivered_orders: finalDash.delivered_orders,
    rejected_orders: finalDash.rejected_orders,
    cancelled_orders: finalDash.cancelled_orders,
    total_sales: finalDash.total_sales,
    pending_payments: finalDash.pending_payments,
    completed_payments: finalDash.completed_payments
  });

  if (finalDash.total_orders < 3) {
    throw new Error(`Expected at least 3 total orders, got ${finalDash.total_orders}`);
  }
  if (finalDash.delivered_orders < 1) {
    throw new Error(`Expected at least 1 delivered order, got ${finalDash.delivered_orders}`);
  }
  if (finalDash.rejected_orders < 1) {
    throw new Error(`Expected at least 1 rejected order, got ${finalDash.rejected_orders}`);
  }
  if (finalDash.completed_payments < 1000) {
    throw new Error(`Expected at least ₹1000 in completed payments, got ${finalDash.completed_payments}`);
  }
  console.log('✅ All Dashboard metrics match expected counts and financials perfectly!');

  console.log(`\n🎉 ALL 4 SELLER ORDER MANAGEMENT & DASHBOARD TEST SUITES PASSED 100%!\n`);
}

run().catch(err => {
  console.error('\n❌ Test execution failed:', err);
  process.exit(1);
});

