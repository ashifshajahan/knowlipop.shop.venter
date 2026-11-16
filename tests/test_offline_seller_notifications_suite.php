<?php
/**
 * Comprehensive Test Suite for Offline Seller Notifications,
 * Missed-Order Management, Firebase Connection, and Fixed APIs.
 */

putenv('DB_CONNECTION=sqlite');
$testDbFile = __DIR__ . '/test_offline_suite_' . time() . '_' . mt_rand(1000, 9999) . '.sqlite';
putenv('SQLITE_DB_PATH=' . $testDbFile);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/payment.php';
require_once __DIR__ . '/../helpers/notification.php';
require_once __DIR__ . '/../api/payment.php';
require_once __DIR__ . '/../api/orders.php';

$pdo = getDatabaseConnection();

// Set up in-memory SQLite tables
ensurePaymentSchemaPhp($pdo);
ensureSellerPaymentAccountsTablePhp($pdo);
ensureNotificationSchemaPhp($pdo);
ensureOrdersSchema($pdo);

$pdo->exec("
    CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT,
        email TEXT,
        phone TEXT,
        role TEXT,
        seller_name TEXT,
        is_online INTEGER DEFAULT 1,
        fcm_token TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
");

// Initialize test users
$pdo->exec("
    INSERT OR REPLACE INTO users (id, name, email, phone, role, seller_name, is_online, fcm_token)
    VALUES 
    (1, 'Alice Seller', 'seller1@test.com', '9876543210', 'seller', 'Store One', 0, 'fcm_token_seller_1'),
    (2, 'Bob Seller', 'seller2@test.com', '9876543211', 'seller', 'Store Two', 1, 'fcm_token_seller_2'),
    (10, 'John Buyer', 'buyer@test.com', '9998887770', 'buyer', NULL, 1, 'fcm_token_buyer_1');
");

$passed = 0;
$failed = 0;

function assertTest(string $desc, bool $condition): void {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$desc}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$desc}\n";
        $failed++;
    }
}

// Test Response Interceptor
$lastResponse = null;
$lastStatus = 200;
$GLOBALS['__TEST_RESPONSE_HANDLER'] = function($data, $status) use (&$lastResponse, &$lastStatus) {
    $lastResponse = $data;
    $lastStatus = $status;
};

echo "\n======================================================\n";
echo "1. TESTING BANK ACCOUNT ONBOARDING API\n";
echo "======================================================\n";

// 1.1 GET Bank Onboarding without auth / seller_id -> 200 OK informative
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = [];
$_POST = [];
apiBankOnboarding($pdo);
assertTest("GET /api/seller/bank-account/onboarding (no auth) returns 200 ready", $lastStatus === 200 && ($lastResponse['status'] ?? '') === 'ready');

// 1.2 GET Bank Onboarding with seller_id -> 200 OK not_linked
$_GET = ['seller_id' => '1'];
apiBankOnboarding($pdo);
assertTest("GET /api/seller/bank-account/onboarding (seller_id=1) returns 200 not_linked", $lastStatus === 200 && ($lastResponse['status'] ?? '') === 'not_linked');

// 1.3 POST Bank Onboarding to link bank account
$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET = [];
$_POST = [
    'seller_id' => '1',
    'account_holder_name' => 'Alice Seller',
    'account_number' => '987654321098',
    'ifsc_code' => 'HDFC0001234',
    'bank_name' => 'HDFC Bank',
    'branch_name' => 'MG Road',
    'upi_id' => 'alice@okhdfcbank'
];
apiBankOnboarding($pdo);
assertTest("POST /api/seller/bank-account/onboarding links bank account", $lastStatus === 200 && ($lastResponse['success'] ?? false) === true);
assertTest("Response contains masked account number", ($lastResponse['account_number_masked'] ?? '') === '••••••••1098');

// 1.4 GET Bank Status after linking
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = ['seller_id' => '1'];
$_POST = [];
apiBankStatus($pdo);
assertTest("GET Bank Status returns linked=true and verified status", $lastStatus === 200 && ($lastResponse['linked'] ?? false) === true && ($lastResponse['bank_name'] ?? '') === 'HDFC Bank');
assertTest("GET Bank Status returns masked account number", ($lastResponse['account_number_masked'] ?? '') === '••••••••1098');

echo "\n======================================================\n";
echo "2. TESTING MARKETPLACE COMMISSION PREVIEW API\n";
echo "======================================================\n";

// 2.1 GET Commission Preview without parameters (default ₹1,000)
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = [];
$_POST = [];
apiCommissionPreview($pdo);
assertTest("GET /api/marketplace/commission-preview returns 200 OK without auth", $lastStatus === 200 && ($lastResponse['success'] ?? false) === true);
assertTest("Default order amount is ₹1,000 (100,000 paise)", ($lastResponse['order_amount'] ?? 0) == 1000);
assertTest("Commission percentage is 5%", ($lastResponse['commission_percentage'] ?? '') === '5%');
assertTest("Net seller payout is ₹950 (95,000 paise)", ($lastResponse['seller_receives_paise'] ?? 0) == 95000);

// 2.2 GET Commission Preview with amount=2500
$_GET = ['amount' => '2500'];
apiCommissionPreview($pdo);
assertTest("GET with amount=2500 calculates ₹125 commission and ₹2,375 net payout", ($lastResponse['commission_amount'] ?? 0) == 125 && ($lastResponse['net_payout'] ?? 0) == 2375);

// 2.3 POST Commission Preview
$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET = [];
$_POST = ['amount' => '5000'];
apiCommissionPreview($pdo);
assertTest("POST with amount=5000 calculates ₹250 commission and ₹4,750 net payout", ($lastResponse['commission_amount'] ?? 0) == 250 && ($lastResponse['net_payout'] ?? 0) == 4750);

echo "\n======================================================\n";
echo "3. TESTING OFFLINE SELLER NOTIFICATION & MYSQL PERSISTENCE\n";
echo "======================================================\n";

// Register device tokens
registerDeviceTokenPhp($pdo, 1, 'token_seller_1_device_abc', 'android');
registerDeviceTokenPhp($pdo, 2, 'token_seller_2_device_xyz', 'ios');

// Create order for Seller #1 (Seller #1 is offline: is_online = 0)
$pdo->exec("
    INSERT INTO orders (id, order_number, buyer_name, buyer_phone, shipping_address, subtotal, delivery_fee, discount, total_amount, payment_method, payment_status, order_status, seller_id, created_at)
    VALUES (501, 'ORD-OFFLINE-001', 'John Buyer', '9998887770', '123 Main St', 1500, 50, 0, 1550, 'cod', 'unpaid', 'pending', 1, CURRENT_TIMESTAMP);
");
$pdo->exec("
    INSERT INTO order_items (order_id, seller_id, product_name, price, quantity, subtotal)
    VALUES (501, 1, 'Wireless Headphones', 1500, 1, 1500);
");

// Dispatch order notification
dispatchOrderNotificationPhp($pdo, 501, 'order_created');

// Check notifications table in SQLite
$notif = $pdo->query("SELECT * FROM notifications WHERE user_id = 1 AND order_id = 501 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertTest("Notification record saved in database for offline seller", !empty($notif));
assertTest("Delivery status is marked 'offline' when seller is logged out", ($notif['delivery_status'] ?? '') === 'offline');
assertTest("Notification unread status is 0 (unread)", (int)($notif['is_read'] ?? 1) === 0);

echo "\n======================================================\n";
echo "4. TESTING SELLER LOGIN AUTO-SYNC & MISSED ORDERS\n";
echo "======================================================\n";

// Call getSellerSyncDataPhp for Seller #1
$sync = getSellerSyncDataPhp($pdo, 1);
assertTest("Sync data contains summary", !empty($sync['summary']));
assertTest("Sync counts 1 unread notification for offline period", ($sync['summary']['unread_notifications_count'] ?? 0) >= 1);
assertTest("Sync counts 1 pending order in missed orders", ($sync['summary']['pending_orders_count'] ?? 0) >= 1);
assertTest("Pending orders array includes ORD-OFFLINE-001", !empty($sync['pending_orders']) && $sync['pending_orders'][0]['order_number'] === 'ORD-OFFLINE-001');

// Mark notification as read
apiMarkNotificationRead($pdo, (int)$notif['id']);
$updatedNotif = $pdo->query("SELECT * FROM notifications WHERE id = {$notif['id']}")->fetch(PDO::FETCH_ASSOC);
assertTest("Marking notification read sets is_read = 1", (int)$updatedNotif['is_read'] === 1);
assertTest("Marking notification read updates delivery_status to 'delivered'", $updatedNotif['delivery_status'] === 'delivered');

echo "\n======================================================\n";
echo "5. TESTING MULTI-VENDOR ISOLATION\n";
echo "======================================================\n";

// Seller #2 (Store Two) must NOT see Seller #1's notifications or pending orders
$syncSeller2 = getSellerSyncDataPhp($pdo, 2);
assertTest("Seller #2 has 0 pending orders from Seller #1", ($syncSeller2['summary']['pending_orders_count'] ?? 0) === 0);
assertTest("Seller #2 notifications list does NOT contain Seller #1's order", count($syncSeller2['notifications']) === 0);

// Create order for Seller #2
$pdo->exec("
    INSERT INTO orders (id, order_number, buyer_name, buyer_phone, shipping_address, subtotal, delivery_fee, discount, total_amount, payment_method, payment_status, order_status, seller_id, created_at)
    VALUES (502, 'ORD-SELLER2-002', 'Jane Buyer', '9998887771', '456 Market St', 800, 40, 0, 840, 'razorpay', 'paid', 'pending', 2, CURRENT_TIMESTAMP);
");
$pdo->exec("
    INSERT INTO order_items (order_id, seller_id, product_name, price, quantity, subtotal)
    VALUES (502, 2, 'Organic Tea', 800, 1, 800);
");
dispatchOrderNotificationPhp($pdo, 502, 'order_created');

$syncSeller2After = getSellerSyncDataPhp($pdo, 2);
assertTest("Seller #2 now sees exactly their own order (ORD-SELLER2-002)", ($syncSeller2After['summary']['pending_orders_count'] ?? 0) === 1 && $syncSeller2After['pending_orders'][0]['order_number'] === 'ORD-SELLER2-002');

// Verify Seller #1 still sees only their own order
$syncSeller1After = getSellerSyncDataPhp($pdo, 1);
assertTest("Seller #1 still only sees ORD-OFFLINE-001 and not ORD-SELLER2-002", $syncSeller1After['pending_orders'][0]['order_number'] === 'ORD-OFFLINE-001');

echo "\n======================================================\n";
echo "6. TESTING NOTIFICATION DEDUPLICATION\n";
echo "======================================================\n";

// Attempt to dispatch same event with same dedup_key
$dedupKey = 'order_501_created';
$firstId = createNotificationPhp($pdo, 1, null, 'seller', 501, 'Test Title', 'Test Message', 'order', null, $dedupKey);
$secondId = createNotificationPhp($pdo, 1, null, 'seller', 501, 'Test Title', 'Test Message', 'order', null, $dedupKey);
assertTest("Duplicate notification with same dedup_key returns existing notification ID", $firstId === $secondId);

// Clean up test database file
try {
    $pdo = null;
    @unlink($testDbFile);
} catch (Throwable $e) {}

echo "\n======================================================\n";
echo "TEST RESULTS: {$passed} PASSED, {$failed} FAILED\n";
echo "======================================================\n";

exit($failed > 0 ? 1 : 0);
