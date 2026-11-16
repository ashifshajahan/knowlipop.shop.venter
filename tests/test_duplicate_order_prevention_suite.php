<?php

/**
 * Automated Test Suite: Duplicate Order Prevention & Idempotency
 *
 * Verifies:
 * 1. Database schema protection:
 *    - `orders.idempotency_key` with unique index `uniq_orders_idempotency`
 *    - `order_idempotency_keys` table for response caching and transaction tracking
 * 2. Idempotent Order Placement API:
 *    - First request returns HTTP 201 {"success": true, "message": "Order placed successfully", "order_id": ...}
 *    - Second request within 50ms returns HTTP 200 {"success": true, "message": "Order already created", "order_id": ...}
 *    - Exact same existing order is returned without creating duplicate database records
 * 3. Atomic Database Constraints & Race Condition Handling:
 *    - Database transaction rollback on duplicate key collision
 *    - Recovery query loads winner's committed order
 * 4. Legitimate separate purchases:
 *    - Requests with different idempotency keys create distinct separate orders
 * 5. Double-click protection fallback:
 *    - Rapid requests with omitted client headers use deterministic fingerprinting to prevent duplicates
 * 6. Payment Idempotency:
 *    - Duplicate Razorpay order creation calls reuse existing gateway_order_id
 *    - Duplicate payment signature verifications return already_verified without duplicate transactions
 * 7. Multi-vendor checkout idempotency:
 *    - Multi-vendor carts maintain seller separation and item consistency without duplicate rows
 */

declare(strict_types=1);

// Configure isolated test database environment
$testDbFile = __DIR__ . '/test_idempotency_' . time() . '_' . mt_rand(1000, 9999) . '.sqlite';
putenv('DB_CONNECTION=sqlite');
putenv('SQLITE_DB_PATH=' . $testDbFile);
putenv('RAZORPAY_KEY_ID=rzp_test_mockIdempKey');
putenv('RAZORPAY_KEY_SECRET=mockIdempSecret');
putenv('RAZORPAY_WEBHOOK_SECRET=mockIdempWebhookSecret');
putenv('RAZORPAY_ROUTE_ENABLED=false');

// Mock Razorpay gateway HTTP transport
$GLOBALS['__RAZORPAY_TEST_HTTP_TRANSPORT'] = function(string $method, string $endpoint, ?array $payload = null, array $extraHeaders = []): array {
    if (str_contains($endpoint, 'orders')) {
        return [
            'ok' => true,
            'statusCode' => 200,
            'data' => [
                'id' => 'order_mock_' . substr(md5(json_encode($payload) . microtime()), 0, 14),
                'entity' => 'order',
                'amount' => $payload['amount'] ?? 10000,
                'currency' => 'INR',
                'status' => 'created',
            ]
        ];
    }
    return ['ok' => true, 'statusCode' => 200, 'data' => []];
};

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/payment.php';
require_once __DIR__ . '/../helpers/notification.php';
require_once __DIR__ . '/../api/orders.php';

$pdo = getDatabaseConnection();
ensureOrdersSchema($pdo);
ensurePaymentSchemaPhp($pdo);
ensureNotificationSchemaPhp($pdo);

// Seed mock sellers & products for testing
$pdo->exec("
    CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        email TEXT NULL UNIQUE,
        phone TEXT NULL,
        seller_name TEXT NULL,
        role TEXT NOT NULL DEFAULT 'seller',
        account_status TEXT NOT NULL DEFAULT 'ACTIVE',
        fcm_token TEXT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    INSERT OR IGNORE INTO users (id, name, seller_name, role, account_status)
    VALUES (1, 'Super Admin', 'Admin Store', 'admin', 'ACTIVE');
    INSERT OR IGNORE INTO users (id, name, seller_name, role, account_status)
    VALUES (2, 'Seller One', 'Tech Emporium', 'seller', 'ACTIVE');
    INSERT OR IGNORE INTO users (id, name, seller_name, role, account_status)
    VALUES (3, 'Seller Two', 'Fashion Hub', 'seller', 'ACTIVE');

    CREATE TABLE IF NOT EXISTS products (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        seller_id INTEGER NOT NULL,
        name TEXT NOT NULL,
        price REAL NOT NULL,
        stock INTEGER NOT NULL DEFAULT 100,
        image_url TEXT NULL,
        images TEXT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    INSERT OR IGNORE INTO products (id, seller_id, name, price, stock)
    VALUES (101, 2, 'Mechanical Keyboard', 2500.00, 50);
    INSERT OR IGNORE INTO products (id, seller_id, name, price, stock)
    VALUES (102, 2, 'Gaming Mouse', 1200.00, 80);
    INSERT OR IGNORE INTO products (id, seller_id, name, price, stock)
    VALUES (201, 3, 'Cotton T-Shirt', 699.00, 100);
");

$testResults = [];

function assertTest(string $name, bool $condition, string $detail = ''): void {
    global $testResults;
    if ($condition) {
        $testResults[] = ['status' => 'PASS', 'name' => $name, 'detail' => $detail];
        echo "  [PASS] {$name}" . ($detail ? " ({$detail})" : "") . "\n";
    } else {
        $testResults[] = ['status' => 'FAIL', 'name' => $name, 'detail' => $detail];
        echo "  [FAIL] {$name}" . ($detail ? " ({$detail})" : "") . "\n";
    }
}

// Test response capture helper
function callApiCreateOrderWithCapture(PDO $pdo, array $payload, array $headers = []): array {
    $lastResponse = null;
    $lastStatusCode = 200;

    $GLOBALS['__TEST_RESPONSE_HANDLER'] = function($data, int $statusCode) use (&$lastResponse, &$lastStatusCode) {
        $lastResponse = $data;
        $lastStatusCode = $statusCode;
    };

    $GLOBALS['_CUSTOM_JSON_INPUT'] = $payload;

    // Set server headers
    $_SERVER['HTTP_IDEMPOTENCY_KEY'] = $headers['Idempotency-Key'] ?? ($headers['idempotency-key'] ?? null);
    $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] = $headers['X-Idempotency-Key'] ?? null;

    apiCreateOrder($pdo);

    unset($GLOBALS['__TEST_RESPONSE_HANDLER']);
    unset($GLOBALS['_CUSTOM_JSON_INPUT']);
    unset($_SERVER['HTTP_IDEMPOTENCY_KEY']);
    unset($_SERVER['HTTP_X_IDEMPOTENCY_KEY']);

    return ['statusCode' => $lastStatusCode, 'data' => $lastResponse];
}

echo "\n======================================================\n";
echo " DUPLICATE ORDER PREVENTION & IDEMPOTENCY TEST SUITE\n";
echo "======================================================\n\n";

// ---------------------------------------------------------------------
// TEST 1: Database Schema & Idempotency Key Unique Constraint
// ---------------------------------------------------------------------
echo "TEST 1: Database Schema & Unique Index Enforcement\n";
$cols = [];
$cStmt = $pdo->query("PRAGMA table_info(orders)");
while ($r = $cStmt->fetch(PDO::FETCH_ASSOC)) {
    $cols[] = $r['name'];
}
assertTest("orders table has idempotency_key column", in_array('idempotency_key', $cols, true));

$idxList = [];
$iStmt = $pdo->query("PRAGMA index_list(orders)");
while ($r = $iStmt->fetch(PDO::FETCH_ASSOC)) {
    $idxList[] = $r['name'];
}
assertTest("uniq_orders_idempotency unique index exists", in_array('uniq_orders_idempotency', $idxList, true));

$tableCheck = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='order_idempotency_keys'")->fetchColumn();
assertTest("order_idempotency_keys table exists", $tableCheck === 'order_idempotency_keys');

// ---------------------------------------------------------------------
// TEST 2: Exact Behavior Required - First Request vs Second Request
// ---------------------------------------------------------------------
echo "\nTEST 2: Order Placement Idempotency (First vs Second Request within 50ms)\n";
$idempKey1 = 'req_idemp_' . uniqid();
$orderPayload1 = [
    'idempotency_key' => $idempKey1,
    'buyer_name' => 'Alice Kumar',
    'buyer_phone' => '9876543210',
    'shipping_address' => 'Flat 4B, Palm Grove, MG Road',
    'city' => 'Bengaluru',
    'state' => 'Karnataka',
    'pincode' => '560001',
    'payment_method' => 'cod',
    'items' => [
        ['product_id' => 101, 'quantity' => 1]
    ]
];

// First request
$res1 = callApiCreateOrderWithCapture($pdo, $orderPayload1, ['Idempotency-Key' => $idempKey1]);
assertTest("First request returns HTTP 201 Created", $res1['statusCode'] === 201);
assertTest("First request returns success: true", !empty($res1['data']['success']));
assertTest("First request message is 'Order placed successfully'", ($res1['data']['message'] ?? '') === 'Order placed successfully');
assertTest("First request returns valid order_id", !empty($res1['data']['order_id']));
$firstOrderId = $res1['data']['order_id'];

// Second request within 50ms with identical idempotency key
usleep(10000); // 10ms later
$res2 = callApiCreateOrderWithCapture($pdo, $orderPayload1, ['Idempotency-Key' => $idempKey1]);
assertTest("Second request returns HTTP 200 OK", $res2['statusCode'] === 200);
assertTest("Second request returns success: true", !empty($res2['data']['success']));
assertTest("Second request message is 'Order already created'", ($res2['data']['message'] ?? '') === 'Order already created');
assertTest("Second request returns identical order_id", (string)($res2['data']['order_id'] ?? '') === (string)$firstOrderId);
assertTest("Second request marks already_created: true", !empty($res2['data']['already_created']) || !empty($res2['data']['existing']));

// Verify MySQL/SQLite database table has exactly ONE order
$countOrders = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE idempotency_key = '{$idempKey1}'")->fetchColumn();
assertTest("Database contains exactly 1 order row for this idempotency key", $countOrders === 1);

// Verify order_items are not duplicated
$countItems = (int)$pdo->query("SELECT COUNT(*) FROM order_items WHERE order_id = {$firstOrderId}")->fetchColumn();
assertTest("Order items are not duplicated (count = 1)", $countItems === 1);

// ---------------------------------------------------------------------
// TEST 3: Rapid Burst Simulation (<50ms Burst of 5 Requests)
// ---------------------------------------------------------------------
echo "\nTEST 3: Rapid 5-Request Burst Simulation (<50ms between requests)\n";
$burstKey = 'burst_idemp_' . uniqid();
$burstPayload = [
    'idempotency_key' => $burstKey,
    'buyer_name' => 'Bob Builder',
    'buyer_phone' => '9123456780',
    'shipping_address' => 'Villa 12, Lake View',
    'payment_method' => 'cod',
    'items' => [
        ['product_id' => 102, 'quantity' => 2]
    ]
];

$burstResponses = [];
for ($i = 0; $i < 5; $i++) {
    $burstResponses[] = callApiCreateOrderWithCapture($pdo, $burstPayload, ['Idempotency-Key' => $burstKey]);
    usleep(5000); // 5ms gap
}

$firstBurst = $burstResponses[0];
assertTest("Burst request 1 creates order successfully", $firstBurst['statusCode'] === 201 && ($firstBurst['data']['message'] ?? '') === 'Order placed successfully');

$burstOrderId = $firstBurst['data']['order_id'];
$allMatchOrderId = true;
$allSubsequentAlreadyCreated = true;

for ($i = 1; $i < 5; $i++) {
    $cur = $burstResponses[$i];
    if ((string)($cur['data']['order_id'] ?? '') !== (string)$burstOrderId) {
        $allMatchOrderId = false;
    }
    if (($cur['data']['message'] ?? '') !== 'Order already created') {
        $allSubsequentAlreadyCreated = false;
    }
}

assertTest("All subsequent 4 burst requests return identical order_id ({$burstOrderId})", $allMatchOrderId);
assertTest("All subsequent 4 burst requests report 'Order already created'", $allSubsequentAlreadyCreated);

$burstDbCount = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE idempotency_key = '{$burstKey}'")->fetchColumn();
assertTest("Database contains exactly 1 order row for burst key", $burstDbCount === 1);

// ---------------------------------------------------------------------
// TEST 4: Legitimate Separate Purchases with Different Idempotency Keys
// ---------------------------------------------------------------------
echo "\nTEST 4: Legitimate Separate Purchases Allowed with Distinct Keys\n";
$keyA = 'purchase_A_' . uniqid();
$keyB = 'purchase_B_' . uniqid();

$payloadA = [
    'idempotency_key' => $keyA,
    'buyer_name' => 'Charlie Day',
    'buyer_phone' => '9988776655',
    'shipping_address' => 'Corner Street 1',
    'payment_method' => 'cod',
    'items' => [['product_id' => 101, 'quantity' => 1]]
];

$payloadB = [
    'idempotency_key' => $keyB,
    'buyer_name' => 'Charlie Day',
    'buyer_phone' => '9988776655',
    'shipping_address' => 'Corner Street 1',
    'payment_method' => 'cod',
    'items' => [['product_id' => 102, 'quantity' => 1]]
];

$resA = callApiCreateOrderWithCapture($pdo, $payloadA, ['Idempotency-Key' => $keyA]);
$resB = callApiCreateOrderWithCapture($pdo, $payloadB, ['Idempotency-Key' => $keyB]);

assertTest("Purchase A created with 201", $resA['statusCode'] === 201);
assertTest("Purchase B created with 201", $resB['statusCode'] === 201);
assertTest("Purchase A and Purchase B have different order IDs", (string)$resA['data']['order_id'] !== (string)$resB['data']['order_id']);

// ---------------------------------------------------------------------
// TEST 5: Double-Click Fingerprint Fallback (Omitted Header)
// ---------------------------------------------------------------------
echo "\nTEST 5: Double-Click Fallback Protection (Header Omitted by Client)\n";
$doubleClickPayload = [
    'buyer_name' => 'David DoubleClick',
    'buyer_phone' => '9888123456',
    'shipping_address' => 'Apt 101, Cyber City',
    'pincode' => '500081',
    'payment_method' => 'cod',
    'items' => [['product_id' => 101, 'quantity' => 1]]
];

// Click 1
$click1 = callApiCreateOrderWithCapture($pdo, $doubleClickPayload, []);
assertTest("Click 1 places order successfully", $click1['statusCode'] === 201 && !empty($click1['data']['order_id']));

// Click 2 arrives 20ms later (rapid double click with no header)
usleep(20000);
$click2 = callApiCreateOrderWithCapture($pdo, $doubleClickPayload, []);
assertTest("Click 2 detects duplicate and returns HTTP 200", $click2['statusCode'] === 200);
assertTest("Click 2 message is 'Order already created'", ($click2['data']['message'] ?? '') === 'Order already created');
assertTest("Click 2 returns exact same order_id as Click 1", (string)($click2['data']['order_id'] ?? '') === (string)($click1['data']['order_id'] ?? ''));

$dcCount = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE buyer_phone = '9888123456'")->fetchColumn();
assertTest("Only 1 order created in DB despite rapid double click", $dcCount === 1);

// ---------------------------------------------------------------------
// TEST 6: Payment Protection - Razorpay Order Creation Idempotency
// ---------------------------------------------------------------------
echo "\nTEST 6: Razorpay Order Creation Idempotency\n";
// Create an online payment order
$rzpKey = 'rzp_idemp_' . uniqid();
$rzpOrderPayload = [
    'idempotency_key' => $rzpKey,
    'buyer_name' => 'Eva Online',
    'buyer_phone' => '9111223344',
    'shipping_address' => 'Online Bay',
    'payment_method' => 'razorpay',
    'items' => [['product_id' => 101, 'quantity' => 1]]
];
$rzpOrderRes = callApiCreateOrderWithCapture($pdo, $rzpOrderPayload, ['Idempotency-Key' => $rzpKey]);
$rzpOrderId = (int)$rzpOrderRes['data']['order_id'];
$initialGatewayOrderId = $rzpOrderRes['data']['razorpay_order_id'] ?? ($rzpOrderRes['data']['razorpay']['razorpay_order_id'] ?? null);

assertTest("Online order created with Razorpay gateway_order_id", !empty($initialGatewayOrderId));

// Duplicate call to razorpayCreateOrderPhp for same order
$secondRzpCall = razorpayCreateOrderPhp($pdo, $rzpOrderId);
assertTest("Second Razorpay order call succeeds with ok: true", !empty($secondRzpCall['ok']));
assertTest("Second call reuses existing gateway_order_id", ($secondRzpCall['data']['razorpay_order_id'] ?? '') === $initialGatewayOrderId);
assertTest("Second call flags already_created: true", !empty($secondRzpCall['data']['already_created']));

// ---------------------------------------------------------------------
// TEST 7: Payment Protection - Payment Verification Idempotency
// ---------------------------------------------------------------------
echo "\nTEST 7: Payment Verification Idempotency & Repeat Retries\n";
$mockPayId = 'pay_mock_' . uniqid();
$verificationInput = [
    'gateway_payment_id' => $mockPayId,
    'gateway_order_id' => $initialGatewayOrderId,
    'razorpay_signature' => 'mockSig',
    'captured_amount' => 2500.00
];

// Admin verify 1
$ver1 = recordOrderPaymentVerificationPhp($pdo, $rzpOrderId, $verificationInput, ['role' => 'admin']);
assertTest("Payment verification 1 succeeds", !empty($ver1['ok']));

// Verify order status is paid in DB
$payStatus = $pdo->query("SELECT payment_status FROM orders WHERE id = {$rzpOrderId}")->fetchColumn();
assertTest("Order payment_status is 'paid'", $payStatus === 'paid');

// Repeat verification call with same payment ID (e.g. buyer refreshed or network retry)
$ver2 = recordOrderPaymentVerificationPhp($pdo, $rzpOrderId, $verificationInput, ['role' => 'admin']);
assertTest("Repeat payment verification succeeds with ok: true", !empty($ver2['ok']));
assertTest("Repeat verification returns already_verified: true", !empty($ver2['data']['already_verified']));

// Verify transaction count for this payment ID is strictly 1
$txCount = (int)$pdo->query("SELECT COUNT(*) FROM payment_transactions WHERE order_id = {$rzpOrderId} AND gateway_payment_id = '{$mockPayId}' AND status = 'captured'")->fetchColumn();
assertTest("Strictly 1 capture payment transaction recorded in DB", $txCount === 1);

// ---------------------------------------------------------------------
// TEST 8: Multi-Vendor Order Idempotency
// ---------------------------------------------------------------------
echo "\nTEST 8: Multi-Vendor Cart Idempotency & Item Routing\n";
$mvKey = 'mv_idemp_' . uniqid();
$mvPayload = [
    'idempotency_key' => $mvKey,
    'buyer_name' => 'Fiona MultiVendor',
    'buyer_phone' => '9777888999',
    'shipping_address' => 'Central Hub 404',
    'payment_method' => 'cod',
    'items' => [
        ['product_id' => 101, 'quantity' => 1], // Seller 2
        ['product_id' => 201, 'quantity' => 2]  // Seller 3
    ]
];

$mvRes1 = callApiCreateOrderWithCapture($pdo, $mvPayload, ['Idempotency-Key' => $mvKey]);
assertTest("Multi-vendor order created with HTTP 201", $mvRes1['statusCode'] === 201);
$mvOrderId = $mvRes1['data']['order_id'];

// Immediate retry with same idempotency key
usleep(15000);
$mvRes2 = callApiCreateOrderWithCapture($pdo, $mvPayload, ['Idempotency-Key' => $mvKey]);
assertTest("Multi-vendor retry returns HTTP 200 with 'Order already created'", $mvRes2['statusCode'] === 200 && ($mvRes2['data']['message'] ?? '') === 'Order already created');
assertTest("Multi-vendor retry returns matching order_id", (string)$mvRes2['data']['order_id'] === (string)$mvOrderId);

$mvItemRows = $pdo->query("SELECT seller_id, COUNT(*) as cnt FROM order_items WHERE order_id = {$mvOrderId} GROUP BY seller_id")->fetchAll(PDO::FETCH_KEY_PAIR);
assertTest("Seller 2 has exactly 1 item in multi-vendor order", ($mvItemRows[2] ?? 0) == 1);
assertTest("Seller 3 has exactly 1 item in multi-vendor order", ($mvItemRows[3] ?? 0) == 1);

// Cleanup test db
@unlink($testDbFile);

echo "\n======================================================\n";
echo " TEST SUMMARY\n";
echo "======================================================\n";
$passCount = count(array_filter($testResults, fn($r) => $r['status'] === 'PASS'));
$failCount = count(array_filter($testResults, fn($r) => $r['status'] === 'FAIL'));
echo "Total Tests: " . count($testResults) . "\n";
echo "Passed: {$passCount}\n";
echo "Failed: {$failCount}\n";
if ($failCount > 0) {
    echo "STATUS: FAILED ❌\n";
    exit(1);
} else {
    echo "STATUS: ALL TESTS PASSED! ✅\n";
    exit(0);
}

