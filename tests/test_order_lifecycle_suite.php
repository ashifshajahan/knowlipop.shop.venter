<?php

/**
 * Complete Order Lifecycle, Cancellation, and Multi-Vendor Management Test Suite
 * 
 * Verifies end-to-end integration across:
 * - Buyer App (test-buyer.html)
 * - Seller App / Simulator (multi-vendor isolation, accept/reject, ship, deliver, manage cancellations)
 * - Admin Backend (dashboard.php, cancellation triage queue, refund oversight)
 * - Razorpay Payment & Automated Gateway Refund on cancellation
 * - Stock inventory restoration
 * - Audit timeline in order_activities
 * - In-app notification dispatching
 */

declare(strict_types=1);

// Set test environment
putenv('DB_CONNECTION=sqlite');
putenv('RAZORPAY_KEY_ID=rzp_test_mockLifecycleKey');
putenv('RAZORPAY_KEY_SECRET=mockLifecycleSecret');
putenv('RAZORPAY_WEBHOOK_SECRET=mockLifecycleWebhookSecret');
putenv('RAZORPAY_ROUTE_ENABLED=false');
putenv('MARKETPLACE_COMMISSION_RATE=0.05');

// Mock Razorpay gateway HTTP transport for refund execution
$GLOBALS['__RAZORPAY_TEST_HTTP_TRANSPORT'] = function(string $method, string $endpoint, ?array $payload = null, array $extraHeaders = []): array {
    if (str_contains($endpoint, 'refund')) {
        $amountPaise = $payload['amount'] ?? 10000;
        return [
            'ok' => true,
            'statusCode' => 200,
            'data' => [
                'id' => 'rfnd_auto_' . uniqid(),
                'entity' => 'refund',
                'amount' => $amountPaise,
                'currency' => 'INR',
                'payment_id' => 'pay_mock_lifecycle_123',
                'status' => 'processed',
                'speed_processed' => 'normal',
            ]
        ];
    }
    return ['ok' => true, 'statusCode' => 200, 'data' => []];
};

require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/payment.php';
require_once __DIR__ . '/../api/orders.php';

echo "\n=======================================================\n";
echo "  ORDER MANAGEMENT & CANCELLATION LIFECYCLE TEST SUITE \n";
echo "=======================================================\n\n";

$passCount = 0;
$failCount = 0;

function runTest(string $name, callable $fn): void {
    global $passCount, $failCount;
    try {
        $fn();
        echo "  [PASS] {$name}\n";
        $passCount++;
    } catch (Throwable $e) {
        echo "  [FAIL] {$name}: " . $e->getMessage() . "\n";
        echo "         in " . $e->getFile() . ":" . $e->getLine() . "\n";
        $failCount++;
    }
}

// 1. Initialize SQLite in-memory test database
$testDbFile = __DIR__ . '/test_lifecycle_' . time() . '.sqlite';
if (file_exists($testDbFile)) {
    @unlink($testDbFile);
}
$pdo = new PDO('sqlite:' . $testDbFile, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// Initialize core schema matching schema.sql
$pdo->exec("
    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name VARCHAR(255) NOT NULL,
        email VARCHAR(255) UNIQUE,
        phone VARCHAR(50) UNIQUE,
        role VARCHAR(50) DEFAULT 'seller',
        seller_name VARCHAR(255),
        account_status VARCHAR(50) DEFAULT 'ACTIVE',
        token_version INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE products (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        seller_id INTEGER NOT NULL,
        name VARCHAR(255) NOT NULL,
        sku VARCHAR(100) DEFAULT NULL,
        category VARCHAR(100) DEFAULT 'General',
        brand VARCHAR(100) DEFAULT NULL,
        description TEXT DEFAULT NULL,
        seller_price DECIMAL(10,2) DEFAULT 0.00,
        commission DECIMAL(10,2) DEFAULT 0.00,
        delivery_charge DECIMAL(10,2) DEFAULT 0.00,
        gst DECIMAL(10,2) DEFAULT 0.00,
        other_charges DECIMAL(10,2) DEFAULT 0.00,
        mrp DECIMAL(10,2) DEFAULT NULL,
        price DECIMAL(10,2) NOT NULL,
        stock INTEGER DEFAULT 100,
        rating DECIMAL(3,2) DEFAULT 4.50,
        ratings_count INTEGER DEFAULT 1,
        image_url TEXT DEFAULT NULL,
        images TEXT DEFAULT NULL,
        is_active INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE orders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_number VARCHAR(100) UNIQUE NOT NULL,
        seller_id INTEGER DEFAULT 1,
        buyer_id INTEGER DEFAULT NULL,
        buyer_name VARCHAR(255) NOT NULL,
        buyer_phone VARCHAR(50) NOT NULL,
        shipping_address TEXT NOT NULL,
        delivery_address TEXT,
        landmark VARCHAR(255),
        city VARCHAR(100),
        state VARCHAR(100),
        pincode VARCHAR(20),
        order_status VARCHAR(50) DEFAULT 'pending',
        payment_method VARCHAR(50) DEFAULT 'cod',
        payment_status VARCHAR(50) DEFAULT 'unpaid',
        payment_gateway VARCHAR(50) DEFAULT 'razorpay',
        gateway_payment_id VARCHAR(100),
        gateway_order_id VARCHAR(100),
        captured_amount DECIMAL(10,2) DEFAULT NULL,
        refunded_amount DECIMAL(10,2) DEFAULT 0.00,
        subtotal DECIMAL(10,2) DEFAULT 0.00,
        delivery_fee DECIMAL(10,2) DEFAULT 0.00,
        discount DECIMAL(10,2) DEFAULT 0.00,
        total_amount DECIMAL(10,2) NOT NULL,
        carrier VARCHAR(100) DEFAULT NULL,
        tracking_number VARCHAR(100) DEFAULT NULL,
        rejection_reason TEXT DEFAULT NULL,
        cancellation_reason TEXT DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        shipped_at DATETIME DEFAULT NULL,
        delivered_at DATETIME DEFAULT NULL,
        cancelled_at DATETIME DEFAULT NULL,
        return_window_ends_at DATETIME DEFAULT NULL,
        settled_at DATETIME DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE order_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL,
        product_id INTEGER NOT NULL,
        seller_id INTEGER DEFAULT 1,
        product_name VARCHAR(255),
        product_title VARCHAR(255),
        product_image TEXT,
        price DECIMAL(10,2) NOT NULL,
        unit_price DECIMAL(10,2),
        product_price DECIMAL(10,2),
        quantity INTEGER NOT NULL DEFAULT 1,
        qty INTEGER NOT NULL DEFAULT 1,
        subtotal DECIMAL(10,2) NOT NULL,
        item_status VARCHAR(50) DEFAULT 'pending',
        rejection_reason TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE notifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER DEFAULT NULL,
        buyer_phone VARCHAR(50) DEFAULT NULL,
        title VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        type VARCHAR(50) DEFAULT 'order',
        data TEXT DEFAULT NULL,
        is_read INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
");

// Ensure payment tables and order_activities table
ensurePaymentSchemaPhp($pdo);
ensureOrdersSchema($pdo);

// Seed sample users (Admin, Seller 1, Seller 2)
$pdo->exec("
    INSERT INTO users (id, name, email, phone, role, seller_name) VALUES
    (1, 'TechHub Direct Admin', 'seller1@knowlipop.shop', '+919876543201', 'seller', 'TechHub Direct'),
    (2, 'Urban Leather Admin', 'seller2@knowlipop.shop', '+919876543202', 'seller', 'Urban Leather Works'),
    (99, 'Super Administrator', 'admin@knowlipop.shop', '+919999999999', 'admin', 'Platform Superadmin');
");

// Seed sample products
$pdo->exec("
    INSERT INTO products (id, seller_id, name, price, stock) VALUES
    (101, 1, 'Smart Wireless Headset Pro', 2999.00, 50),
    (102, 1, 'Ultra HD 4K Smart Watch', 4499.00, 30),
    (201, 2, 'Vintage Leather Travel Backpack', 3299.00, 25);
");

// Helper to execute API requests with test response interceptor
function callApi(callable $apiFn, array $get = [], array $post = [], array $headers = []): array {
    $lastResponse = ['status' => 200, 'data' => null];
    
    $_GET = $get;
    $_POST = $post;
    $GLOBALS['_CUSTOM_JSON_INPUT'] = $post;
    
    foreach ($headers as $k => $v) {
        $headerKey = 'HTTP_' . strtoupper(str_replace('-', '_', $k));
        $_SERVER[$headerKey] = $v;
    }

    $GLOBALS['__TEST_RESPONSE_HANDLER'] = function($data, $statusCode) use (&$lastResponse) {
        $lastResponse = ['status' => $statusCode, 'data' => $data];
    };

    try {
        $apiFn();
    } finally {
        unset($GLOBALS['__TEST_RESPONSE_HANDLER']);
        unset($GLOBALS['_CUSTOM_JSON_INPUT']);
        foreach ($headers as $k => $v) {
            $headerKey = 'HTTP_' . strtoupper(str_replace('-', '_', $k));
            unset($_SERVER[$headerKey]);
        }
    }

    return $lastResponse;
}

// ----------------------------------------------------
// TEST 1: Schema Integrity & Order Activity Logging
// ----------------------------------------------------
runTest("Test 1: Schema check - order_activities, carrier & tracking_number columns", function() use ($pdo) {
    $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='order_activities'");
    if (!$stmt->fetch()) {
        throw new Exception("order_activities table does not exist");
    }

    $logId = logOrderActivityPhp($pdo, 9999, 'system_test', null, null, [
        'actor_role' => 'system',
        'actor_name' => 'Automated Test Harness',
        'notes' => 'Testing activity logger mechanism',
    ]);
    if ($logId <= 0) {
        throw new Exception("Failed to insert test order activity record");
    }

    $activities = getOrderActivitiesPhp($pdo, 9999);
    if (count($activities) !== 1 || $activities[0]['action'] !== 'system_test') {
        throw new Exception("Activity log retrieval mismatch");
    }
});

// ----------------------------------------------------
// TEST 2: Order Placement & Automatic Activity Logging
// ----------------------------------------------------
$order1Id = 0;
runTest("Test 2: Create order -> Activity 'created' logged & notification dispatched", function() use ($pdo, &$order1Id) {
    $initialStock = (int)$pdo->query("SELECT stock FROM products WHERE id = 101")->fetchColumn();

    $res = callApi(function() use ($pdo) {
        apiCreateOrder($pdo);
    }, [], [
        'buyer_name' => 'Rahul Sharma',
        'buyer_phone' => '+91 98765 43210',
        'shipping_address' => 'Flat 402, Lotus Residency, Bengaluru',
        'delivery_address' => 'Flat 402, Lotus Residency, Bengaluru',
        'payment_method' => 'cod',
        'notes' => 'Ring bell twice',
        'items' => [
            [
                'product_id' => 101,
                'seller_id' => 1,
                'product_name' => 'Smart Wireless Headset Pro',
                'price' => 2999.00,
                'quantity' => 2,
                'subtotal' => 5998.00,
            ]
        ]
    ]);

    if ($res['status'] !== 201 && $res['status'] !== 200) {
        throw new Exception("Expected 201 Created, got status " . $res['status'] . ": " . json_encode($res['data']));
    }

    $order = $res['data']['order'];
    $order1Id = (int)$order['id'];
    if ($order1Id <= 0) {
        throw new Exception("Invalid order ID returned");
    }
    if ($order['order_status'] !== 'pending') {
        throw new Exception("New order status must be 'pending', got: " . $order['order_status']);
    }

    // Verify stock was decremented
    $newStock = (int)$pdo->query("SELECT stock FROM products WHERE id = 101")->fetchColumn();
    if ($newStock !== $initialStock - 2) {
        throw new Exception("Stock not decremented properly: was {$initialStock}, now {$newStock}");
    }

    // Verify activity logged
    $activities = getOrderActivitiesPhp($pdo, $order1Id);
    if (empty($activities) || $activities[0]['action'] !== 'created') {
        throw new Exception("Activity log for 'created' was not found");
    }

    // Verify notifications table
    $stmtNotif = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE data LIKE ? OR message LIKE ?");
    $stmtNotif->execute(['%"order_id":' . $order1Id . '%', '%' . $order['order_number'] . '%']);
    $notifCount = (int)$stmtNotif->fetchColumn();
    if ($notifCount < 1) {
        throw new Exception("Notifications not dispatched for order creation");
    }
});

// ----------------------------------------------------
// TEST 3: Multi-Vendor Isolation
// ----------------------------------------------------
$multiVendorOrderId = 0;
runTest("Test 3: Multi-Vendor Order Isolation - sellers only see their items & financial subtotal", function() use ($pdo, &$multiVendorOrderId) {
    // Create an order containing products from both Seller 1 and Seller 2
    $res = callApi(function() use ($pdo) {
        apiCreateOrder($pdo);
    }, [], [
        'buyer_name' => 'Ananya Patel',
        'buyer_phone' => '+91 91234 56789',
        'shipping_address' => 'Bandra West, Mumbai',
        'payment_method' => 'cod',
        'items' => [
            [
                'product_id' => 101, // Seller 1
                'seller_id' => 1,
                'product_name' => 'Smart Wireless Headset Pro',
                'price' => 2999.00,
                'quantity' => 1,
                'subtotal' => 2999.00,
            ],
            [
                'product_id' => 201, // Seller 2
                'seller_id' => 2,
                'product_name' => 'Vintage Leather Travel Backpack',
                'price' => 3299.00,
                'quantity' => 1,
                'subtotal' => 3299.00,
            ]
        ]
    ]);

    $multiVendorOrderId = (int)$res['data']['order']['id'];

    // 1. Query as Seller 1
    $resSeller1 = callApi(function() use ($pdo) {
        apiGetSellerOrders($pdo);
    }, ['seller_id' => 1], [], ['X-Seller-Id' => '1']);

    $ordersS1 = $resSeller1['data']['orders']['data'] ?? [];
    $matchedS1 = null;
    foreach ($ordersS1 as $o) {
        if ((int)$o['id'] === $multiVendorOrderId) {
            $matchedS1 = $o;
            break;
        }
    }
    if (!$matchedS1) {
        throw new Exception("Seller 1 should see the order containing their product");
    }
    if (count($matchedS1['items']) !== 1 || (int)$matchedS1['items'][0]['seller_id'] !== 1) {
        throw new Exception("Multi-vendor leak! Seller 1 sees items from other sellers");
    }
    if ((float)$matchedS1['total_amount'] != 2999.00) {
        throw new Exception("Seller 1 financial total must be filtered to their items only (2999.00), got: " . $matchedS1['total_amount']);
    }

    // 2. Query as Seller 2
    $resSeller2 = callApi(function() use ($pdo) {
        apiGetSellerOrders($pdo);
    }, ['seller_id' => 2], [], ['X-Seller-Id' => '2']);

    $ordersS2 = $resSeller2['data']['orders']['data'] ?? [];
    $matchedS2 = null;
    foreach ($ordersS2 as $o) {
        if ((int)$o['id'] === $multiVendorOrderId) {
            $matchedS2 = $o;
            break;
        }
    }
    if (!$matchedS2) {
        throw new Exception("Seller 2 should see the order containing their product");
    }
    if (count($matchedS2['items']) !== 1 || (int)$matchedS2['items'][0]['seller_id'] !== 2) {
        throw new Exception("Multi-vendor leak! Seller 2 sees items from other sellers");
    }
    if ((float)$matchedS2['total_amount'] != 3299.00) {
        throw new Exception("Seller 2 financial total must be filtered to their items only (3299.00), got: " . $matchedS2['total_amount']);
    }
});

// ----------------------------------------------------
// TEST 4: Seller Accepts Order -> Confirmed
// ----------------------------------------------------
runTest("Test 4: Seller 1 accepts Order 1 -> status transitions to 'confirmed'", function() use ($pdo, $order1Id) {
    $res = callApi(function() use ($pdo, $order1Id) {
        apiUpdateOrder($pdo, $order1Id);
    }, [], [
        'order_status' => 'confirmed',
        'notes' => 'Seller accepted order for fulfillment'
    ], ['X-Seller-Id' => '1']);

    if ($res['status'] !== 200) {
        throw new Exception("Failed to accept order: " . json_encode($res['data']));
    }

    $st = $pdo->query("SELECT order_status FROM orders WHERE id = {$order1Id}")->fetchColumn();
    if ($st !== 'confirmed') {
        throw new Exception("Expected order_status to be 'confirmed', got '{$st}'");
    }

    $activities = getOrderActivitiesPhp($pdo, $order1Id);
    $acceptedAct = array_filter($activities, fn($a) => $a['action'] === 'confirmed' || $a['to_status'] === 'confirmed');
    if (empty($acceptedAct)) {
        throw new Exception("Audit activity for confirmation not recorded");
    }
});

// ----------------------------------------------------
// TEST 5: Seller Marks Order as Processing
// ----------------------------------------------------
runTest("Test 5: Seller 1 moves Order 1 to 'processing'", function() use ($pdo, $order1Id) {
    $res = callApi(function() use ($pdo, $order1Id) {
        apiUpdateOrder($pdo, $order1Id);
    }, [], [
        'order_status' => 'processing',
        'notes' => 'Package moved to packing station'
    ], ['X-Seller-Id' => '1']);

    if ($res['status'] !== 200) {
        throw new Exception("Failed to update status to processing: " . json_encode($res['data']));
    }

    $st = $pdo->query("SELECT order_status FROM orders WHERE id = {$order1Id}")->fetchColumn();
    if ($st !== 'processing') {
        throw new Exception("Expected order_status to be 'processing', got '{$st}'");
    }
});

// ----------------------------------------------------
// TEST 6: Cancellation Rule - Processing Order Requires Approval
// ----------------------------------------------------
runTest("Test 6: Buyer cancels 'processing' order -> status becomes 'cancellation_requested'", function() use ($pdo, $order1Id) {
    $res = callApi(function() use ($pdo, $order1Id) {
        apiCancelOrder($pdo, $order1Id);
    }, [], [
        'reason' => 'Need to change delivery address',
        'notes' => 'Can I please cancel before shipping?'
    ]);

    if ($res['status'] !== 200) {
        throw new Exception("Failed to process cancellation request: " . json_encode($res['data']));
    }

    $st = $pdo->query("SELECT order_status FROM orders WHERE id = {$order1Id}")->fetchColumn();
    if ($st !== 'cancellation_requested') {
        throw new Exception("Processing order cancellation should transition to 'cancellation_requested', got '{$st}'");
    }

    $activities = getOrderActivitiesPhp($pdo, $order1Id);
    $reqAct = array_filter($activities, fn($a) => $a['action'] === 'cancellation_requested');
    if (empty($reqAct)) {
        throw new Exception("Activity log for 'cancellation_requested' not found");
    }
});

// ----------------------------------------------------
// TEST 7: Seller Rejects Cancellation Request
// ----------------------------------------------------
runTest("Test 7: Seller rejects cancellation request -> status reverts to 'processing'", function() use ($pdo, $order1Id) {
    $res = callApi(function() use ($pdo, $order1Id) {
        apiRejectCancellation($pdo, $order1Id);
    }, [], [
        'reason' => 'Item is already packed and labeled for carrier pickup.'
    ], ['X-Seller-Id' => '1']);

    if ($res['status'] !== 200) {
        throw new Exception("Failed to reject cancellation: " . json_encode($res['data']));
    }

    $st = $pdo->query("SELECT order_status FROM orders WHERE id = {$order1Id}")->fetchColumn();
    if ($st !== 'processing') {
        throw new Exception("Expected status to revert to 'processing', got '{$st}'");
    }

    $activities = getOrderActivitiesPhp($pdo, $order1Id);
    $rejAct = array_filter($activities, fn($a) => $a['action'] === 'cancellation_rejected');
    if (empty($rejAct)) {
        throw new Exception("Activity log for 'cancellation_rejected' not found");
    }
});

// ----------------------------------------------------
// TEST 8: Seller Ships Order with Carrier & Tracking Number
// ----------------------------------------------------
runTest("Test 8: Seller ships order with carrier & tracking number -> status 'shipped'", function() use ($pdo, $order1Id) {
    $res = callApi(function() use ($pdo, $order1Id) {
        apiUpdateOrder($pdo, $order1Id);
    }, [], [
        'order_status' => 'shipped',
        'carrier' => 'BlueDart Express',
        'tracking_number' => 'BLU987654321IN',
        'notes' => 'Handed over to courier hub'
    ], ['X-Seller-Id' => '1']);

    if ($res['status'] !== 200) {
        throw new Exception("Failed to ship order: " . json_encode($res['data']));
    }

    $order = $pdo->query("SELECT order_status, carrier, tracking_number, shipped_at FROM orders WHERE id = {$order1Id}")->fetch();
    if ($order['order_status'] !== 'shipped') {
        throw new Exception("Expected status 'shipped', got '{$order['order_status']}'");
    }
    if ($order['carrier'] !== 'BlueDart Express') {
        throw new Exception("Expected carrier 'BlueDart Express', got '{$order['carrier']}'");
    }
    if ($order['tracking_number'] !== 'BLU987654321IN') {
        throw new Exception("Expected tracking number 'BLU987654321IN', got '{$order['tracking_number']}'");
    }
    if (empty($order['shipped_at'])) {
        throw new Exception("shipped_at timestamp not set");
    }
});

// ----------------------------------------------------
// TEST 9: Cancellation Rule - Shipped Order Cannot Be Cancelled
// ----------------------------------------------------
runTest("Test 9: Buyer cannot cancel a shipped order (400 Bad Request)", function() use ($pdo, $order1Id) {
    $res = callApi(function() use ($pdo, $order1Id) {
        apiCancelOrder($pdo, $order1Id);
    }, [], [
        'reason' => 'Changed mind'
    ]);

    if ($res['status'] !== 400) {
        throw new Exception("Expected 400 Bad Request for cancelling shipped order, got: " . $res['status']);
    }
    if (!str_contains($res['data']['message'] ?? '', 'in transit') && !str_contains($res['data']['message'] ?? '', 'shipped')) {
        throw new Exception("Unexpected error message: " . json_encode($res['data']));
    }
});

// ----------------------------------------------------
// TEST 10: Seller Marks Order Delivered
// ----------------------------------------------------
runTest("Test 10: Seller marks order as 'delivered'", function() use ($pdo, $order1Id) {
    $res = callApi(function() use ($pdo, $order1Id) {
        apiUpdateOrder($pdo, $order1Id);
    }, [], [
        'order_status' => 'delivered',
        'notes' => 'Package delivered to consignee'
    ], ['X-Seller-Id' => '1']);

    if ($res['status'] !== 200) {
        throw new Exception("Failed to mark delivered: " . json_encode($res['data']));
    }

    $st = $pdo->query("SELECT order_status FROM orders WHERE id = {$order1Id}")->fetchColumn();
    if ($st !== 'delivered') {
        throw new Exception("Expected status 'delivered', got '{$st}'");
    }
});

// ----------------------------------------------------
// TEST 11: Cancellation Rule - Delivered Order Cannot Be Cancelled
// ----------------------------------------------------
runTest("Test 11: Delivered orders cannot be cancelled directly (must use return workflow)", function() use ($pdo, $order1Id) {
    $res = callApi(function() use ($pdo, $order1Id) {
        apiCancelOrder($pdo, $order1Id);
    }, [], [
        'reason' => 'Do not want this anymore'
    ]);

    if ($res['status'] !== 400) {
        throw new Exception("Expected 400 Bad Request when cancelling delivered order, got: " . $res['status']);
    }
    if (!str_contains($res['data']['message'] ?? '', 'return')) {
        throw new Exception("Expected guidance to use return process: " . json_encode($res['data']));
    }
});

// ----------------------------------------------------
// TEST 12: Buyer Requests Return on Delivered Order
// ----------------------------------------------------
runTest("Test 12: Buyer submits return request -> status transitions to 'return_requested'", function() use ($pdo, $order1Id) {
    $res = callApi(function() use ($pdo, $order1Id) {
        apiReturnOrder($pdo, $order1Id);
    }, [], [
        'reason' => 'Defective or damaged product',
        'notes' => 'Right earbud sound is muffled'
    ]);

    if ($res['status'] !== 200) {
        throw new Exception("Failed to request return: " . json_encode($res['data']));
    }

    $st = $pdo->query("SELECT order_status FROM orders WHERE id = {$order1Id}")->fetchColumn();
    if ($st !== 'return_requested') {
        throw new Exception("Expected status 'return_requested', got '{$st}'");
    }

    $activities = getOrderActivitiesPhp($pdo, $order1Id);
    $retAct = array_filter($activities, fn($a) => $a['action'] === 'return_requested');
    if (empty($retAct)) {
        throw new Exception("Activity log for 'return_requested' not found");
    }
});

// ----------------------------------------------------
// TEST 13: Paid Order Cancellation -> Stock Restored + Automated Razorpay Refund
// ----------------------------------------------------
runTest("Test 13: Paid order cancellation restores stock & triggers automated Razorpay refund", function() use ($pdo) {
    // 1. Create a paid order
    $initialStock = (int)$pdo->query("SELECT stock FROM products WHERE id = 102")->fetchColumn();

    $res = callApi(function() use ($pdo) {
        apiCreateOrder($pdo);
    }, [], [
        'buyer_name' => 'Vikram Singh',
        'buyer_phone' => '+91 99887 76655',
        'shipping_address' => 'Sector 29, Gurgaon',
        'payment_method' => 'razorpay',
        'items' => [
            [
                'product_id' => 102,
                'seller_id' => 1,
                'product_name' => 'Ultra HD 4K Smart Watch',
                'price' => 4499.00,
                'quantity' => 2,
                'subtotal' => 8998.00,
            ]
        ]
    ]);

    if ($res['status'] !== 201 && $res['status'] !== 200) {
        throw new Exception("Failed to create order: " . json_encode($res['data']));
    }

    $paidOrderId = (int)$res['data']['order']['id'];

    // Verify stock decremented by 2
    $stockAfterOrder = (int)$pdo->query("SELECT stock FROM products WHERE id = 102")->fetchColumn();
    if ($stockAfterOrder !== $initialStock - 2) {
        throw new Exception("Stock not decremented on order placement");
    }

    // Record verified payment capture
    $pdo->exec("
        UPDATE orders 
        SET payment_status = 'paid',
            order_status = 'confirmed',
            gateway_payment_id = 'pay_mock_lifecycle_123',
            gateway_order_id = 'order_mock_lifecycle_123',
            captured_amount = 8998.00
        WHERE id = {$paidOrderId}
    ");

    $pdo->exec("
        INSERT INTO payment_transactions (order_id, seller_id, buyer_name, buyer_phone, gateway_payment_id, gateway_order_id, amount, currency, status, transaction_type, commission_rate, commission_amount, net_seller_amount)
        VALUES ({$paidOrderId}, 1, 'Vikram Singh', '+91 99887 76655', 'pay_mock_lifecycle_123', 'order_mock_lifecycle_123', 8998.00, 'INR', 'captured', 'capture', 0.05, 449.90, 8548.10)
    ");

    // 2. Buyer cancels confirmed paid order
    $resCancel = callApi(function() use ($pdo, $paidOrderId) {
        apiCancelOrder($pdo, $paidOrderId);
    }, [], [
        'reason' => 'Found better price elsewhere'
    ]);

    if ($resCancel['status'] !== 200) {
        throw new Exception("Failed to cancel paid order: " . json_encode($resCancel['data']));
    }

    // 3. Verify order status is cancelled
    $order = $pdo->query("SELECT order_status, payment_status, refunded_amount FROM orders WHERE id = {$paidOrderId}")->fetch();
    if ($order['order_status'] !== 'cancelled') {
        throw new Exception("Expected order_status 'cancelled', got '{$order['order_status']}'");
    }
    if ($order['payment_status'] !== 'refunded') {
        throw new Exception("Expected payment_status 'refunded', got '{$order['payment_status']}'");
    }
    if ((float)$order['refunded_amount'] != 8998.00) {
        throw new Exception("Expected refunded_amount 8998.00, got: " . $order['refunded_amount']);
    }

    // 4. Verify stock was restored
    $restoredStock = (int)$pdo->query("SELECT stock FROM products WHERE id = 102")->fetchColumn();
    if ($restoredStock !== $initialStock) {
        throw new Exception("Inventory stock was not restored upon cancellation! Was {$initialStock}, now {$restoredStock}");
    }

    // 5. Verify refund transaction was recorded
    $stmtRfnd = $pdo->prepare("SELECT * FROM payment_transactions WHERE order_id = ? AND transaction_type = 'refund'");
    $stmtRfnd->execute([$paidOrderId]);
    $rfndTx = $stmtRfnd->fetch();
    if (!$rfndTx) {
        throw new Exception("payment_transactions record for automated refund was not created");
    }
    if ((float)$rfndTx['amount'] != 8998.00) {
        throw new Exception("Refund transaction amount mismatch: " . $rfndTx['amount']);
    }

    // 6. Verify audit activity logs both cancellation and refund
    $activities = getOrderActivitiesPhp($pdo, $paidOrderId);
    $hasCancel = false;
    $hasRefund = false;
    foreach ($activities as $a) {
        if ($a['action'] === 'cancelled') $hasCancel = true;
        if ($a['action'] === 'refund_processed') $hasRefund = true;
    }
    if (!$hasCancel) throw new Exception("Activity log for 'cancelled' missing");
    if (!$hasRefund) throw new Exception("Activity log for 'refund_processed' missing");
});

// ----------------------------------------------------
// TEST 14: Order Tracking API & Chronological Audit Timeline
// ----------------------------------------------------
runTest("Test 14: apiGetOrderTracking returns full tracking metadata, carrier, and activity timeline", function() use ($pdo, $order1Id) {
    $res = callApi(function() use ($pdo, $order1Id) {
        apiGetOrderTracking($pdo, $order1Id);
    });

    if ($res['status'] !== 200) {
        throw new Exception("Failed to fetch order tracking: " . json_encode($res['data']));
    }

    $t = $res['data']['tracking'] ?? [];
    if (empty($t['order_number'])) {
        throw new Exception("Tracking response missing order_number");
    }
    if ($t['carrier'] !== 'BlueDart Express') {
        throw new Exception("Tracking response carrier mismatch: " . ($t['carrier'] ?? 'null'));
    }
    if ($t['tracking_number'] !== 'BLU987654321IN') {
        throw new Exception("Tracking response tracking_number mismatch: " . ($t['tracking_number'] ?? 'null'));
    }

    $activities = $t['activities'] ?? [];
    if (count($activities) < 5) {
        throw new Exception("Expected at least 5 chronological activity steps, got " . count($activities));
    }
});

// ----------------------------------------------------
// TEST 15: Admin Orders & Cancellation Queue Monitoring
// ----------------------------------------------------
runTest("Test 15: Admin orders endpoint supports cancellation triage queue filtering", function() use ($pdo) {
    // Create an order specifically in 'cancellation_requested' status
    $pdo->exec("
        INSERT INTO orders (id, order_number, seller_id, buyer_name, buyer_phone, shipping_address, order_status, payment_method, total_amount)
        VALUES (8888, 'ORD-CANCEL-TEST-8888', 1, 'Triage Buyer', '+919999988888', '123 MG Road', 'cancellation_requested', 'cod', 1500.00)
    ");

    // Start session as admin
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['admin_id'] = 99;
    $_SESSION['admin_name'] = 'Super Administrator';

    // Call admin orders with cancellation_requests_only = true
    $res = callApi(function() use ($pdo) {
        apiAdminGetOrders($pdo);
    }, ['cancellation_requests_only' => 'true']);

    if ($res['status'] !== 200) {
        throw new Exception("Admin get orders failed with status " . $res['status'] . ": " . json_encode($res['data']));
    }

    // Admin should see order 8888 in the triage queue
    $orders = $res['data']['orders']['data'] ?? [];
    $found = false;
    foreach ($orders as $o) {
        if ((int)$o['id'] === 8888) {
            $found = true;
        }
        if ($o['order_status'] !== 'cancellation_requested') {
            throw new Exception("Triage queue returned non-cancellation order: " . $o['order_status']);
        }
    }
    if (!$found) {
        throw new Exception("Order 8888 not found in cancellation triage queue");
    }

    // Verify cancellation metrics
    $cancellationPendingCount = $res['data']['metrics']['total_cancellation_requests'] ?? $res['data']['cancellation_requests_count'] ?? 0;
    if ($cancellationPendingCount < 1) {
        throw new Exception("cancellation_requests_count should be >= 1, got {$cancellationPendingCount}");
    }
});

// Clean up temporary test database
if (file_exists($testDbFile)) {
    @unlink($testDbFile);
}

echo "\n-------------------------------------------------------\n";
echo "  SUMMARY: {$passCount} PASSED, {$failCount} FAILED\n";
echo "-------------------------------------------------------\n\n";

if ($failCount > 0) {
    exit(1);
}
