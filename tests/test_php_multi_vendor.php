<?php

/**
 * End-to-End Test Suite for PHP Multi-Vendor Order Routing & Seller Isolation
 */

$testDbFile = __DIR__ . '/test_vendor.sqlite';
if (file_exists($testDbFile)) {
    unlink($testDbFile);
}

// 1. Initialize SQLite database schema
$pdo = new PDO("sqlite:{$testDbFile}");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec("
    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        email TEXT UNIQUE,
        password TEXT NOT NULL,
        seller_name TEXT,
        phone TEXT UNIQUE,
        role TEXT NOT NULL DEFAULT 'seller',
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE products (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        seller_id INTEGER NOT NULL,
        name TEXT NOT NULL,
        description TEXT,
        price REAL NOT NULL,
        stock INTEGER NOT NULL DEFAULT 0,
        image_url TEXT,
        is_active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE orders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        seller_id INTEGER,
        order_number TEXT NOT NULL UNIQUE,
        buyer_name TEXT NOT NULL,
        buyer_phone TEXT NOT NULL,
        shipping_address TEXT NOT NULL,
        landmark TEXT,
        city TEXT,
        state TEXT,
        pincode TEXT,
        order_status TEXT NOT NULL DEFAULT 'pending',
        payment_method TEXT NOT NULL DEFAULT 'cod',
        payment_status TEXT NOT NULL DEFAULT 'unpaid',
        subtotal REAL NOT NULL DEFAULT 0.00,
        delivery_fee REAL NOT NULL DEFAULT 0.00,
        discount REAL NOT NULL DEFAULT 0.00,
        total_amount REAL NOT NULL DEFAULT 0.00,
        notes TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE order_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL,
        product_id INTEGER,
        seller_id INTEGER,
        product_name TEXT NOT NULL,
        product_image TEXT,
        price REAL NOT NULL,
        quantity INTEGER NOT NULL DEFAULT 1,
        subtotal REAL NOT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
");

// Insert Seed Users
$pdo->exec("
    INSERT INTO users (id, name, email, password, seller_name, phone, role) VALUES
    (1, 'Vendor Administrator', 'admin@vendor.app', 'hash', 'Verified Seller 1', '9876543210', 'admin'),
    (2, 'Seller Two Shop', 'seller2@vendor.app', 'hash', 'Verified Seller 2', '9876543211', 'seller'),
    (3, 'Seller Three Shop', 'seller3@vendor.app', 'hash', 'Verified Seller 3', '9876543212', 'seller');
");

// Insert Seed Products
$pdo->exec("
    INSERT INTO products (id, seller_id, name, price, stock, image_url, is_active) VALUES
    (1, 1, 'Smart Wireless Headset Pro', 2999.00, 50, 'https://example.com/p1.jpg', 1),
    (7, 2, 'Noise-Cancelling Studio Earbuds', 1899.00, 45, 'https://example.com/p7.jpg', 1);
");

echo "\n===========================================\n";
echo "🧪 RUNNING PURE PHP ENGINE TEST SUITE\n";
echo "===========================================\n";

require_once __DIR__ . '/../helpers/auth.php';

// Generate JWT tokens for Seller 1, 2, 3
$token1 = createToken($pdo, 1, '9876543210');
$token2 = createToken($pdo, 2, '9876543211');
$token3 = createToken($pdo, 3, '9876543212');

function runPhpSubprocess(string $action, array $serverEnv = [], array $postData = []): array
{
    global $testDbFile;
    $runner = __DIR__ . '/php_case_runner.php';
    $payloadJson = json_encode([
        'action' => $action,
        'db_file' => $testDbFile,
        'server' => $serverEnv,
        'post' => $postData
    ]);

    $cmd = 'php ' . escapeshellarg($runner);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];
    $process = proc_open($cmd, $descriptors, $pipes);
    fwrite($pipes[0], $payloadJson);
    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);
    $json = json_decode($stdout, true);

    return [
        'exitCode' => $exitCode,
        'stdout' => $stdout,
        'stderr' => $stderr,
        'json' => $json
    ];
}

function assertTest(bool $condition, string $message, $res = null): void
{
    if (!$condition) {
        echo "❌ FAILED: {$message}\n";
        if ($res) {
            echo "STDOUT:\n" . ($res['stdout'] ?? '') . "\n";
            echo "STDERR:\n" . ($res['stderr'] ?? '') . "\n";
        }
        exit(1);
    } else {
        echo "✅ PASSED: {$message}\n";
    }
}

// 1. Unauthenticated Seller Orders Check
echo "\n--- 1. Authentication & Security Check ---\n";
$res1 = runPhpSubprocess('get_seller_orders', [
    'REQUEST_URI' => '/api/seller/orders',
    'REQUEST_METHOD' => 'GET'
]);
assertTest(($res1['json']['message'] ?? '') === 'Unauthenticated.', "Unauthenticated /api/seller/orders returns 401 Unauthenticated", $res1);

// 2. Create Multi-Vendor Order
echo "\n--- 2. Multi-Vendor Order Creation & Auto-Routing ---\n";
$orderData = [
    'buyer_name' => 'John Buyer',
    'buyer_phone' => '9123456789',
    'shipping_address' => '42 Park Avenue, Suite 10',
    'items' => [
        [
            'product_id' => 1, // Seller 1 product
            'product_name' => 'Smart Wireless Headset Pro',
            'price' => 2999.00,
            'quantity' => 2
        ],
        [
            'product_id' => 7, // Seller 2 product
            'product_name' => 'Noise-Cancelling Studio Earbuds',
            'price' => 1899.00,
            'quantity' => 1
        ]
    ]
];
$res2 = runPhpSubprocess('create_order', [
    'REQUEST_URI' => '/api/orders',
    'REQUEST_METHOD' => 'POST'
], $orderData);

assertTest(!empty($res2['json']['order']['id']), "Order created successfully with ID " . ($res2['json']['order']['id'] ?? 'null'), $res2);
$createdOrderId = (int)$res2['json']['order']['id'];

// Verify directly from SQLite order_items table that product_id and seller_id are saved
$stmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = :oid ORDER BY id ASC");
$stmt->execute([':oid' => $createdOrderId]);
$itemsInDb = $stmt->fetchAll(PDO::FETCH_ASSOC);

assertTest(count($itemsInDb) === 2, "Database contains exactly 2 order_items for order {$createdOrderId}");
assertTest((int)$itemsInDb[0]['product_id'] === 1 && (int)$itemsInDb[0]['seller_id'] === 1, "Item 1 auto-routed to seller_id 1 (product 1)");
assertTest((int)$itemsInDb[1]['product_id'] === 7 && (int)$itemsInDb[1]['seller_id'] === 2, "Item 2 auto-routed to seller_id 2 (product 7)");

// 3. Seller 2 fetches order list
echo "\n--- 3. Seller 2 Order Isolation (GET /api/seller/orders) ---\n";
$res3 = runPhpSubprocess('get_seller_orders', [
    'REQUEST_URI' => '/api/seller/orders',
    'REQUEST_METHOD' => 'GET',
    'HTTP_AUTHORIZATION' => "Bearer {$token2}"
]);
assertTest(isset($res3['json']['orders']['data']), "Seller 2 order list returned data");
$s2Orders = $res3['json']['orders']['data'];
$s2Order = null;
foreach ($s2Orders as $o) {
    if ((int)$o['id'] === $createdOrderId) {
        $s2Order = $o;
        break;
    }
}
assertTest($s2Order !== null, "Multi-vendor order is visible in Seller 2 orders list");
assertTest(count($s2Order['items']) === 1, "Seller 2 sees only 1 item in multi-vendor order");
assertTest((int)$s2Order['items'][0]['product_id'] === 7, "Seller 2 item is product 7");
assertTest((float)$s2Order['seller_subtotal'] === 1899.00, "Seller 2 subtotal is 1899.00");
assertTest((int)$s2Order['total_quantity'] === 1, "Seller 2 total quantity is 1");

// 4. Seller 2 fetches single order detail
echo "\n--- 4. Single Order Retrieval & Product Filtering (/api/seller/orders/:id) ---\n";
$res4 = runPhpSubprocess('get_order_detail', [
    'REQUEST_URI' => "/api/seller/orders/{$createdOrderId}",
    'REQUEST_METHOD' => 'GET',
    'HTTP_AUTHORIZATION' => "Bearer {$token2}"
], ['order_id' => $createdOrderId]);

assertTest(isset($res4['json']['order']), "Seller 2 retrieved order details");
$detailOrder = $res4['json']['order'];
assertTest(count($detailOrder['items']) === 1, "Order detail contains strictly 1 item for Seller 2");
assertTest((int)$detailOrder['items'][0]['product_id'] === 7, "Detail item is product 7");
assertTest((float)$detailOrder['subtotal'] === 1899.00, "Detail subtotal is 1899.00");

// 5. Seller 3 (who owns NO items) attempts to fetch this order
echo "\n--- 5. Cross-Seller Access Denial (403 Forbidden) ---\n";
$res5 = runPhpSubprocess('get_order_detail', [
    'REQUEST_URI' => "/api/seller/orders/{$createdOrderId}",
    'REQUEST_METHOD' => 'GET',
    'HTTP_AUTHORIZATION' => "Bearer {$token3}"
], ['order_id' => $createdOrderId]);
assertTest(str_contains($res5['json']['message'] ?? '', 'Access denied. You do not own any products in this order.'), "Seller 3 blocked with 403: '{$res5['json']['message']}'");

// 6. Seller 2 attempts to query Seller 1's orders route
$res6 = runPhpSubprocess('get_seller_orders', [
    'REQUEST_URI' => '/api/sellers/1/orders',
    'REQUEST_METHOD' => 'GET',
    'HTTP_AUTHORIZATION' => "Bearer {$token2}"
], ['route_seller_id' => 1]);
assertTest(str_contains($res6['json']['message'] ?? '', 'Access denied. You can only access your own orders.'), "Seller 2 blocked from accessing Seller 1's orders: '{$res6['json']['message']}'");

// 7. Seller Catalog & Cross-Seller Product Security
echo "\n--- 6. Seller Catalog & Cross-Seller Product Security ---\n";
$res7 = runPhpSubprocess('get_seller_products', [
    'REQUEST_URI' => '/api/seller/products',
    'REQUEST_METHOD' => 'GET',
    'HTTP_AUTHORIZATION' => "Bearer {$token2}"
]);
$s2Prods = $res7['json']['data'] ?? ($res7['json']['products']['data'] ?? null);
assertTest(is_array($s2Prods), "Seller 2 catalog retrieved", $res7);
assertTest(count($s2Prods) === 1 && (int)$s2Prods[0]['seller_id'] === 2, "Seller 2 only sees their product (id 7)");

// Seller 2 attempts to update Seller 1's product (id: 1)
$res8 = runPhpSubprocess('update_product', [
    'REQUEST_URI' => '/api/products/1',
    'REQUEST_METHOD' => 'PUT',
    'HTTP_AUTHORIZATION' => "Bearer {$token2}"
], ['product_id' => 1, 'name' => 'Hacked Name']);
assertTest(str_contains($res8['json']['message'] ?? '', 'Access denied. You do not own this product.'), "Seller 2 updating Seller 1 product blocked with 403: '{$res8['json']['message']}'");

// Seller 2 attempts to delete Seller 1's product (id: 1)
$res9 = runPhpSubprocess('delete_product', [
    'REQUEST_URI' => '/api/products/1',
    'REQUEST_METHOD' => 'DELETE',
    'HTTP_AUTHORIZATION' => "Bearer {$token2}"
], ['product_id' => 1]);
assertTest(str_contains($res9['json']['message'] ?? '', 'Access denied. You do not own this product.'), "Seller 2 deleting Seller 1 product blocked with 403: '{$res9['json']['message']}'");

// Cleanup test db
unset($stmt);
$pdo = null;
if (file_exists($testDbFile)) {
    @unlink($testDbFile);
}

echo "\n===========================================\n";
echo "🎉 ALL 18 PHP ENGINE TEST ASSERTIONS PASSED!\n";
echo "===========================================\n\n";
