<?php

/**
 * Comprehensive End-to-End Test Suite for Pure PHP Seller Account Deletion,
 * Order Verification (Rules A-E), Razorpay Refund Idempotency & Async Polling,
 * Financial Settlement, 2-Step Approval, RBAC, and Audit/Record Preservation.
 */

if (in_array('--worker', $argv ?? [], true)) {
    // =========================================================================
    // SUBPROCESS WORKER MODE: Executes one isolated PHP controller invocation
    // =========================================================================
    $rawStdin = file_get_contents('php://stdin');
    $job = json_decode($rawStdin ?: '{}', true);
    if (!is_array($job)) {
        fwrite(STDERR, "Invalid worker JSON payload.\n");
        exit(2);
    }

    $dbFile = (string) ($job['db_file'] ?? '');
    $rpStateFile = (string) ($job['rp_state_file'] ?? '');
    $action = (string) ($job['action'] ?? '');
    $server = $job['server'] ?? [];
    $get = $job['get'] ?? [];
    $post = $job['post'] ?? [];
    $params = $job['params'] ?? [];
    $envOverrides = $job['env'] ?? [];

    $defaultEnv = [
        'RAZORPAY_KEY_ID' => 'rzp_test_php_key',
        'RAZORPAY_KEY_SECRET' => 'rzp_test_php_secret',
        'RAZORPAY_API_BASE_URL' => 'https://api.razorpay.com/v1',
        'RETURN_WINDOW_DAYS' => '7',
        'MARKETPLACE_COMMISSION_RATE' => '0.05',
    ];
    foreach (array_merge($defaultEnv, $envOverrides) as $ek => $ev) {
        putenv("{$ek}={$ev}");
        $_ENV[$ek] = $ev;
    }

    foreach ($server as $k => $v) {
        $_SERVER[$k] = $v;
    }
    $_GET = is_array($get) ? $get : [];
    $_POST = is_array($post) ? $post : [];
    $GLOBALS['_CUSTOM_JSON_INPUT'] = is_array($post) ? $post : [];

    // Register CLI-only mock Razorpay HTTP transport backed by locked state file
    $GLOBALS['__SELLER_DELETION_TEST_HTTP_TRANSPORT'] = function (string $method, string $url, array $headers, ?string $bodyStr) use ($rpStateFile): array {
        $fp = fopen($rpStateFile, 'c+');
        if (!$fp) {
            return ['ok' => false, 'statusCode' => 500, 'data' => null, 'raw' => '', 'error' => 'Cannot open mock state file'];
        }
        flock($fp, LOCK_EX);
        $rawState = stream_get_contents($fp);
        $state = $rawState ? json_decode($rawState, true) : null;
        if (!is_array($state)) {
            $state = ['mode' => 'success', 'next_seq' => 100, 'calls' => [], 'refunds' => []];
        }

        $upperMethod = strtoupper($method);
        $decodedBody = $bodyStr ? json_decode($bodyStr, true) : null;

        // Validate Basic Auth header
        $expectedBasic = 'Basic ' . base64_encode('rzp_test_php_key:rzp_test_php_secret');
        $hasAuth = false;
        $idempotencyKey = '';
        foreach ($headers as $h) {
            if (stripos($h, 'Authorization:') === 0) {
                $hasAuth = trim(substr($h, strlen('Authorization:'))) === $expectedBasic;
            }
            if (stripos($h, 'X-Razorpay-Idempotency:') === 0) {
                $idempotencyKey = trim(substr($h, strlen('X-Razorpay-Idempotency:')));
            }
        }

        $state['calls'][] = [
            'method' => $upperMethod,
            'url' => $url,
            'has_basic_auth' => $hasAuth,
            'idempotency_key' => $idempotencyKey,
            'body' => $decodedBody,
        ];

        $respStatus = 200;
        $respData = [];

        if (!$hasAuth) {
            $respStatus = 401;
            $respData = ['error' => ['code' => 'BAD_REQUEST_ERROR', 'description' => 'Invalid Razorpay Basic credentials']];
        } elseif ($upperMethod === 'POST' && preg_match('#/payments/([^/]+)/refund#', $url, $m)) {
            $mode = $state['mode'] ?? 'success';
            if ($mode === 'fail') {
                $respStatus = 500;
                $respData = ['error' => ['code' => 'SERVER_ERROR', 'description' => 'Simulated Razorpay gateway 500 timeout']];
            } else {
                $state['next_seq'] = (int) ($state['next_seq'] ?? 100) + 1;
                $rfId = 'rfnd_php_' . $state['next_seq'];
                $amt = isset($decodedBody['amount']) ? (int) $decodedBody['amount'] : 0;
                $rfStatus = ($mode === 'pending_then_processed') ? 'created' : 'processed';
                $rfObj = [
                    'id' => $rfId,
                    'entity' => 'refund',
                    'payment_id' => $m[1],
                    'amount' => $amt,
                    'currency' => 'INR',
                    'status' => $rfStatus,
                ];
                $state['refunds'][$rfId] = $rfObj;
                $respStatus = 200;
                $respData = $rfObj;
            }
        } elseif ($upperMethod === 'GET' && preg_match('#/refunds/([^/?]+)#', $url, $m)) {
            $rfId = $m[1];
            $rfObj = $state['refunds'][$rfId] ?? [
                'id' => $rfId,
                'entity' => 'refund',
                'amount' => 0,
                'currency' => 'INR',
                'status' => 'created',
            ];
            if (($state['mode'] ?? 'success') === 'fail') {
                $respStatus = 500;
                $respData = ['error' => ['code' => 'SERVER_ERROR', 'description' => 'Simulated GET refund timeout']];
            } else {
                $rfObj['status'] = 'processed';
                $state['refunds'][$rfId] = $rfObj;
                $respStatus = 200;
                $respData = $rfObj;
            }
        } else {
            $respStatus = 404;
            $respData = ['error' => ['description' => 'Unknown mock Razorpay endpoint']];
        }

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($state));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        $rawOut = json_encode($respData);
        return [
            'ok' => $respStatus >= 200 && $respStatus < 300,
            'statusCode' => $respStatus,
            'data' => $respData,
            'raw' => $rawOut,
            'error' => '',
        ];
    };

    ob_start();
    $workerState = ['handled' => false, 'exception' => null];
    register_shutdown_function(function () use (&$workerState): void {
        $buf = ob_get_contents();
        if ($buf !== false) {
            ob_end_clean();
        } else {
            $buf = '';
        }
        $code = http_response_code();
        if ($code === false || $code === 0) {
            $code = 200;
        }
        $lastErr = error_get_last();
        if ($lastErr && in_array($lastErr['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            $code = 500;
            $workerState['exception'] = $lastErr['message'] . ' in ' . $lastErr['file'] . ':' . $lastErr['line'];
        }
        $decoded = json_decode($buf, true);
        echo "\n<<<PHP_TEST_ENVELOPE>>>" . json_encode([
            'status' => $code,
            'json' => is_array($decoded) ? $decoded : null,
            'raw' => $buf,
            'exception' => $workerState['exception'],
        ]) . "<<<END_PHP_TEST_ENVELOPE>>>\n";
    });

    try {
        $pdo = new PDO("sqlite:{$dbFile}");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA busy_timeout = 5000;');

        require_once __DIR__ . '/../helpers/response.php';
        require_once __DIR__ . '/../helpers/auth.php';
        require_once __DIR__ . '/../helpers/seller_deletion.php';
        require_once __DIR__ . '/../api/auth.php';
        require_once __DIR__ . '/../api/products.php';
        require_once __DIR__ . '/../api/orders.php';
        require_once __DIR__ . '/../api/seller.php';
        require_once __DIR__ . '/../api/payment.php';
        require_once __DIR__ . '/../api/seller_deletion.php';

        switch ($action) {
            case 'admin_list':
                apiAdminListSellerDeletions($pdo);
                break;
            case 'admin_preflight':
                apiAdminSellerDeletionPreflight($pdo, (int) ($params['seller_id'] ?? 0));
                break;
            case 'admin_create':
                apiAdminCreateSellerDeletion($pdo);
                break;
            case 'admin_detail':
                apiAdminGetSellerDeletionDetail($pdo, (int) ($params['id'] ?? 0));
                break;
            case 'admin_lock':
                apiAdminLockSellerAccount($pdo, (int) ($params['id'] ?? 0));
                break;
            case 'admin_verify_orders':
                apiAdminVerifySellerDeletionOrders($pdo, (int) ($params['id'] ?? 0));
                break;
            case 'admin_refunds':
                apiAdminGetSellerDeletionRefunds($pdo, (int) ($params['id'] ?? 0));
                break;
            case 'admin_process_refunds':
                apiAdminProcessSellerDeletionRefunds($pdo, (int) ($params['id'] ?? 0));
                break;
            case 'admin_verify_settlement':
                apiAdminVerifySellerDeletionSettlement($pdo, (int) ($params['id'] ?? 0));
                break;
            case 'admin_settle_payout':
                apiAdminSettleSellerDeletionPayout($pdo, (int) ($params['id'] ?? 0));
                break;
            case 'admin_resolve_dispute':
                apiAdminResolveSellerDeletionDispute($pdo, (int) ($params['id'] ?? 0));
                break;
            case 'admin_approve':
                apiAdminApproveSellerDeletion($pdo, (int) ($params['id'] ?? 0));
                break;
            case 'admin_reject':
                apiAdminRejectSellerDeletion($pdo, (int) ($params['id'] ?? 0));
                break;
            case 'admin_hold':
                apiAdminHoldSellerDeletion($pdo, (int) ($params['id'] ?? 0));
                break;
            case 'admin_final_delete':
                apiAdminFinalDeleteSeller($pdo, (int) ($params['id'] ?? 0));
                break;
            case 'order_payment_verify':
                apiOrderPaymentVerify($pdo, $params['order_id']);
                break;
            case 'order_create_dispute':
                apiOrderCreateDispute($pdo, $params['order_id']);
                break;
            case 'create_order':
                apiCreateOrder($pdo);
                break;
            case 'update_order':
                apiUpdateOrder($pdo, $params['order_id']);
                break;
            case 'accept_order':
                apiAcceptOrder($pdo, $params['order_id']);
                break;
            case 'delete_order':
                apiDeleteOrder($pdo, $params['order_id']);
                break;
            case 'create_product':
                apiCreateProduct($pdo);
                break;
            case 'get_products':
                apiGetProducts($pdo);
                break;
            case 'get_product':
                apiGetProduct($pdo, (int) ($params['id'] ?? 0));
                break;
            case 'get_seller_profile':
                apiGetSellerProfile($pdo);
                break;
            case 'get_payouts':
                apiPayouts($pdo);
                break;
            case 'get_notifications':
                apiGetNotifications($pdo);
                break;
            case 'register':
                apiRegister($pdo);
                break;
            case 'login':
                apiLogin($pdo);
                break;
            case 'me':
                apiMe($pdo);
                break;
            default:
                errorResponse("Unknown worker action: {$action}", [], 500);
        }
        $workerState['handled'] = true;
    } catch (Throwable $e) {
        $workerState['exception'] = $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
        http_response_code(500);
        echo json_encode(['error' => $workerState['exception']]);
    }
    exit(0);
}

// =============================================================================
// PARENT TEST RUNNER
// =============================================================================
ini_set('display_errors', '1');
error_reporting(E_ALL);

$tempPrefix = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'venter_del_php_' . getmypid() . '_' . bin2hex(random_bytes(3));
$dbFile = $tempPrefix . '.sqlite';
$rpStateFile = $tempPrefix . '_rp.json';

$cleanupFiles = function () use ($dbFile, $rpStateFile): void {
    foreach ([$dbFile, $rpStateFile] as $f) {
        if (file_exists($f)) {
            @unlink($f);
        }
    }
};
register_shutdown_function($cleanupFiles);

echo "\n===================================================================\n";
echo "🧪 RUNNING PURE PHP SELLER DELETION & REFUND E2E TEST SUITE (11 SCENARIOS)\n";
echo "===================================================================\n";

require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/seller_deletion.php';
require_once __DIR__ . '/../api/products.php';

// Initialize clean SQLite DB
$pdo = new PDO("sqlite:{$dbFile}");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA busy_timeout = 5000;');

$pdo->exec("
    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        email TEXT UNIQUE,
        password TEXT NOT NULL,
        seller_name TEXT,
        phone TEXT UNIQUE,
        address TEXT,
        landmark TEXT,
        state TEXT,
        country TEXT,
        role TEXT NOT NULL DEFAULT 'seller',
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE personal_access_tokens (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        tokenable_type TEXT,
        tokenable_id INTEGER NOT NULL,
        name TEXT,
        token TEXT NOT NULL,
        abilities TEXT,
        last_used_at DATETIME NULL,
        expires_at DATETIME NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE products (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        seller_id INTEGER NOT NULL,
        name TEXT NOT NULL,
        description TEXT,
        sku TEXT,
        category TEXT,
        category_id INTEGER,
        brand TEXT,
        mrp REAL DEFAULT 0.00,
        seller_price REAL DEFAULT 0.00,
        commission REAL DEFAULT 0.00,
        delivery_charge REAL DEFAULT 0.00,
        gst REAL DEFAULT 0.00,
        other_charges REAL DEFAULT 0.00,
        price REAL NOT NULL DEFAULT 0.00,
        stock INTEGER NOT NULL DEFAULT 0,
        image_url TEXT,
        images TEXT,
        is_active INTEGER NOT NULL DEFAULT 1,
        rating REAL DEFAULT 4.5,
        ratings_count INTEGER DEFAULT 15,
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

    CREATE TABLE seller_gst_details (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        seller_id INTEGER NOT NULL UNIQUE,
        gstin TEXT NULL,
        business_name TEXT NULL,
        business_address TEXT NULL,
        state TEXT NULL,
        pincode TEXT NULL,
        status TEXT NOT NULL DEFAULT 'Pending',
        rejection_reason TEXT NULL,
        verified_by INTEGER NULL,
        verified_at DATETIME NULL,
        submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
");

ensureProductCategoriesTable($pdo);
ensureSellerDeletionSchemaPhp($pdo);

$adminHash = password_hash('Admin123!', PASSWORD_BCRYPT);
$pdo->prepare("
    INSERT INTO users (id, name, email, password, seller_name, phone, address, landmark, state, country, role, account_status, token_version)
    VALUES (1, 'Vendor Administrator', 'admin@vendor.app', :pass, 'Vendor Admin', '+919999900001', 'HQ', 'Center', 'Kerala', 'India', 'admin', 'ACTIVE', 1)
")->execute([':pass' => $adminHash]);

$adminToken = createToken($pdo, 1, '+919999900001', 'admin-test', 'admin@vendor.app', 'admin', 1);

function setRazorpayMockMode(string $mode): void
{
    global $rpStateFile;
    $fp = fopen($rpStateFile, 'c+');
    flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $state = $raw ? json_decode($raw, true) : null;
    if (!is_array($state)) {
        $state = ['mode' => 'success', 'next_seq' => 100, 'calls' => [], 'refunds' => []];
    }
    $state['mode'] = $mode;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($state));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

function getRazorpayCalls(): array
{
    global $rpStateFile;
    if (!file_exists($rpStateFile)) {
        return [];
    }
    $fp = fopen($rpStateFile, 'r');
    flock($fp, LOCK_SH);
    $raw = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $state = $raw ? json_decode($raw, true) : null;
    return is_array($state['calls'] ?? null) ? $state['calls'] : [];
}

setRazorpayMockMode('success');

$assertCount = 0;
function assertTrue(bool $cond, string $msg, mixed $ctx = null): void
{
    global $assertCount;
    if (!$cond) {
        echo "\n❌ ASSERTION FAILED: {$msg}\n";
        if ($ctx !== null) {
            echo "Context: " . (is_string($ctx) ? $ctx : json_encode($ctx, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n";
        }
        exit(1);
    }
    $assertCount++;
    echo "✅ PASS: {$msg}\n";
}

function assertEq(mixed $actual, mixed $expected, string $msg, mixed $ctx = null): void
{
    assertTrue($actual === $expected, "{$msg} (expected=" . json_encode($expected) . ", actual=" . json_encode($actual) . ")", $ctx);
}

function callWorker(string $action, ?string $token = null, array $post = [], array $params = [], array $get = [], string $method = 'POST', array $env = []): array
{
    global $dbFile, $rpStateFile;
    $server = [
        'REQUEST_METHOD' => strtoupper($method),
        'REQUEST_URI' => '/test/' . $action,
        'HTTP_HOST' => 'localhost',
    ];
    if ($token !== null && $token !== '') {
        $server['HTTP_AUTHORIZATION'] = "Bearer {$token}";
    }

    $cmd = 'php ' . escapeshellarg(__FILE__) . ' --worker';
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $desc, $pipes);
    if (!is_resource($proc)) {
        throw new RuntimeException("Failed to spawn PHP worker for {$action}");
    }

    fwrite($pipes[0], json_encode([
        'db_file' => $dbFile,
        'rp_state_file' => $rpStateFile,
        'action' => $action,
        'server' => $server,
        'get' => $get,
        'post' => $post,
        'params' => $params,
        'env' => $env,
    ]));
    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($proc);

    if (preg_match('/<<<PHP_TEST_ENVELOPE>>>(.*?)<<<END_PHP_TEST_ENVELOPE>>>/s', $stdout, $m)) {
        $envData = json_decode($m[1], true);
        if (is_array($envData)) {
            return [
                'status' => (int) ($envData['status'] ?? 500),
                'json' => $envData['json'] ?? null,
                'raw' => $envData['raw'] ?? '',
                'exception' => $envData['exception'] ?? null,
                'stderr' => $stderr,
                'exitCode' => $exitCode,
            ];
        }
    }

    return [
        'status' => 500,
        'json' => null,
        'raw' => $stdout,
        'exception' => 'Missing worker envelope',
        'stderr' => $stderr,
        'exitCode' => $exitCode,
    ];
}

function createSellerViaApi(string $label, string $phone, string $email): array
{
    global $pdo;
    $res = callWorker('register', null, [
        'seller_name' => $label,
        'email' => $email,
        'phone' => $phone,
        'address' => '12 Market Road',
        'landmark' => 'Center',
        'state' => 'Kerala',
        'country' => 'India',
        'password' => 'Seller123!',
        'password_confirmation' => 'Seller123!',
    ]);
    assertTrue(in_array($res['status'], [200, 201], true), "Register seller {$label}", $res);
    $sellerId = (int) ($res['json']['user']['id'] ?? 0);
    assertTrue($sellerId > 0, "Seller {$label} ID present", $res);

    // Seed GST record directly to validate GST retention
    $gstNum = '32ABCDE' . str_pad((string) $sellerId, 4, '0', STR_PAD_LEFT) . 'F1Z5';
    $pdo->prepare("
        INSERT INTO seller_gst_details (seller_id, gstin, business_name, state, status)
        VALUES (:sid, :gstin, :bname, 'Kerala', 'Approved')
    ")->execute([':sid' => $sellerId, ':gstin' => $gstNum, ':bname' => $label]);

    return [
        'id' => $sellerId,
        'token' => (string) $res['json']['token'],
        'phone' => $phone,
        'email' => $email,
        'label' => $label,
    ];
}

function createProductViaApi(array $seller, string $name, float $price, int $stock = 20): int
{
    $res = callWorker('create_product', $seller['token'], [
        'name' => $name,
        'price' => $price,
        'seller_price' => $price,
        'stock' => $stock,
        'category_id' => 1,
    ]);
    assertEq($res['status'], 201, "Create product {$name}", $res);
    return (int) ($res['json']['product']['id'] ?? $res['json']['data']['id'] ?? 0);
}

function createOrderViaApi(string $buyerPhone, array $items, string $paymentMethod = 'razorpay', float $deliveryFee = 0.0): int
{
    $res = callWorker('create_order', null, [
        'buyer_name' => 'Buyer ' . substr($buyerPhone, -4),
        'buyer_phone' => $buyerPhone,
        'shipping_address' => '221B Marine Drive, Kochi',
        'payment_method' => $paymentMethod,
        'delivery_fee' => $deliveryFee,
        'items' => $items,
    ]);
    assertEq($res['status'], 201, "Create order for buyer {$buyerPhone}", $res);
    return (int) ($res['json']['order']['id'] ?? 0);
}

function checkByKey(array $detailJson, string $key): string
{
    foreach ($detailJson['checks'] ?? [] as $ck) {
        if (($ck['check_key'] ?? '') === $key) {
            return (string) ($ck['status'] ?? '');
        }
    }
    return '';
}

function finalizeToDeleted(int $delReqId, int $sellerId, string $label): void
{
    global $adminToken;
    $app = callWorker('admin_approve', $adminToken, ['remarks' => "Approve {$label}"], ['id' => $delReqId]);
    assertEq($app['status'], 200, "{$label}: Approve returns 200", $app);
    $confTok = (string) ($app['json']['deletion_request']['confirmation_token'] ?? '');
    assertTrue($confTok !== '', "{$label}: Confirmation token issued");
    $fin = callWorker('admin_final_delete', $adminToken, [
        'confirmation_token' => $confTok,
        'confirmation_phrase' => "DELETE SELLER #{$sellerId}",
        'remarks' => "Finalize {$label}",
    ], ['id' => $delReqId]);
    assertEq($fin['status'], 200, "{$label}: Final delete returns 200", $fin);
    assertEq($fin['json']['deletion_request']['status'] ?? '', 'DELETED', "{$label}: Status becomes DELETED");
}

// -----------------------------------------------------------------------------
// 0. RBAC, Admin Self-Deletion Guard & Admin UI Badges Check
// -----------------------------------------------------------------------------
echo "\n--- 0. RBAC, Security Guards & Admin Dashboard UI Verification ---\n";
$s0 = createSellerViaApi('RBAC Probe Shop', '+919800000000', 's0@php.test');

assertEq(callWorker('admin_list', null, [], [], [], 'GET')['status'], 401, 'Unauthenticated GET /api/admin/seller-deletions -> 401');
assertEq(callWorker('admin_preflight', $s0['token'], [], ['seller_id' => $s0['id']], [], 'GET')['status'], 403, 'Non-admin seller preflight -> 403');
assertEq(callWorker('admin_create', $s0['token'], ['seller_id' => $s0['id']])['status'], 403, 'Non-admin seller create deletion -> 403');
assertEq(callWorker('admin_create', $adminToken, ['seller_id' => 1])['status'], 422, 'Master admin self-deletion blocked with 422');

$dashHtml = file_get_contents(__DIR__ . '/../admin/dashboard.php') ?: '';
assertTrue(str_contains($dashHtml, 'Seller Deletion Requests'), 'admin/dashboard.php contains Seller Deletion Requests menu');
assertTrue(str_contains($dashHtml, '/admin/seller-deletions'), 'admin/dashboard.php links to /admin/seller-deletions');
assertTrue(str_contains($dashHtml, 'Request Seller Deletion'), 'admin/dashboard.php contains Request Seller Deletion button');
foreach (['del-yellow', 'del-blue', 'del-green', 'del-red', 'del-orange'] as $cssBadge) {
    assertTrue(str_contains($dashHtml, $cssBadge), "admin/dashboard.php contains badge style {$cssBadge}");
}
assertEq(getStatusColorBadgePhp('DELETION_REQUESTED'), 'yellow', 'Badge color PENDING -> yellow');
assertEq(getStatusColorBadgePhp('REFUND_PROCESSING'), 'blue', 'Badge color PROCESSING -> blue');
assertEq(getStatusColorBadgePhp('APPROVED_FOR_DELETION'), 'green', 'Badge color SUCCESS -> green');
assertEq(getStatusColorBadgePhp('REFUND_FAILED'), 'red', 'Badge color FAILED -> red');
assertEq(getStatusColorBadgePhp('DELETION_ON_HOLD'), 'orange', 'Badge color ON HOLD -> orange');

// Clean up RBAC probe seller cleanly
$r0 = callWorker('admin_create', $adminToken, ['seller_id' => $s0['id'], 'reason' => 'Probe']);
$r0Id = (int) $r0['json']['deletion_request']['id'];
callWorker('admin_verify_orders', $adminToken, [], ['id' => $r0Id]);
finalizeToDeleted($r0Id, $s0['id'], 'S0');

// -----------------------------------------------------------------------------
// Scenario 1: Seller with no orders
// -----------------------------------------------------------------------------
echo "\n--- Scenario 1: Seller With No Orders ---\n";
$s1 = createSellerViaApi('Scenario1 Zero Orders', '+919800000001', 's1@php.test');
$p1 = createProductViaApi($s1, 'S1 Item', 400);

$pre1 = callWorker('admin_preflight', $adminToken, [], ['seller_id' => $s1['id']], [], 'GET');
assertEq($pre1['status'], 200, 'Scenario 1 preflight 200');
assertEq($pre1['json']['metrics']['total_products'] ?? -1, 1, 'Scenario 1 metrics total_products=1');
assertEq($pre1['json']['metrics']['total_orders'] ?? -1, 0, 'Scenario 1 metrics total_orders=0');

$cr1 = callWorker('admin_create', $adminToken, ['seller_id' => $s1['id'], 'reason' => 'No orders test']);
assertEq($cr1['status'], 201, 'Scenario 1 create deletion request -> 201', $cr1);
$d1 = (int) $cr1['json']['deletion_request']['id'];
assertEq($cr1['json']['seller']['account_status'] ?? '', 'DELETION_PENDING', 'Scenario 1 seller entered DELETION_PENDING');

$notifsS1 = callWorker('get_notifications', $s1['token'], [], [], [], 'GET');
assertTrue(count($notifsS1['json']['notifications'] ?? []) >= 1, 'Scenario 1 deletion requested notification sent to seller');

$lock1 = callWorker('admin_lock', $adminToken, ['remarks' => 'Lock account'], ['id' => $d1]);
assertEq($lock1['status'], 200, 'Scenario 1 lock -> 200');
assertEq($lock1['json']['deletion_request']['stage'] ?? '', 'ACCOUNT_LOCKED', 'Scenario 1 stage ACCOUNT_LOCKED');
assertEq(callWorker('get_products', null, [], [], [], 'GET')['json']['data'][0]['id'] ?? null, null, 'Scenario 1 locked seller product hidden from public catalog');
assertEq(callWorker('get_product', null, [], ['id' => $p1], [], 'GET')['status'], 404, 'Scenario 1 locked seller product detail -> 404');

callWorker('admin_verify_orders', $adminToken, [], ['id' => $d1]);
assertEq(callWorker('admin_final_delete', $adminToken, ['confirmation_token' => 'fake', 'confirmation_phrase' => "DELETE SELLER #{$s1['id']}"], ['id' => $d1])['status'], 409, 'Scenario 1 final-delete before approve -> 409');

$app1 = callWorker('admin_approve', $adminToken, ['remarks' => 'First confirm'], ['id' => $d1]);
assertEq($app1['status'], 200, 'Scenario 1 approve -> 200');
$tok1 = (string) $app1['json']['deletion_request']['confirmation_token'];

assertEq(callWorker('admin_final_delete', $adminToken, ['confirmation_token' => $tok1, 'confirmation_phrase' => 'WRONG'], ['id' => $d1])['status'], 422, 'Scenario 1 bad confirmation phrase -> 422');
assertEq(callWorker('admin_final_delete', $adminToken, ['confirmation_token' => 'badtok', 'confirmation_phrase' => "DELETE SELLER #{$s1['id']}"], ['id' => $d1])['status'], 422, 'Scenario 1 bad confirmation token -> 422');

$fin1 = callWorker('admin_final_delete', $adminToken, ['confirmation_token' => $tok1, 'confirmation_phrase' => "DELETE SELLER #{$s1['id']}"], ['id' => $d1]);
assertEq($fin1['status'], 200, 'Scenario 1 final delete -> 200');
assertEq($fin1['json']['deletion_request']['status'] ?? '', 'DELETED', 'Scenario 1 DELETED');

// -----------------------------------------------------------------------------
// Scenario 2: Seller with unpaid orders (COD pending)
// -----------------------------------------------------------------------------
echo "\n--- Scenario 2: Seller With Unpaid Orders ---\n";
$s2 = createSellerViaApi('Scenario2 Unpaid Orders', '+919800000002', 's2@php.test');
$p2 = createProductViaApi($s2, 'S2 COD Item', 650, 10);
$o2 = createOrderViaApi('+919700000002', [['product_id' => $p2, 'quantity' => 2]], 'cod');

$cr2 = callWorker('admin_create', $adminToken, ['seller_id' => $s2['id']]);
$d2 = (int) $cr2['json']['deletion_request']['id'];

assertEq(callWorker('create_order', null, ['buyer_name' => 'Blocked Buyer', 'buyer_phone' => '+919700999999', 'shipping_address' => 'Test', 'items' => [['product_id' => $p2, 'quantity' => 1]]])['status'], 403, 'Scenario 2 new orders blocked while DELETION_PENDING');
assertEq(callWorker('accept_order', $s2['token'], [], ['order_id' => $o2])['status'], 403, 'Scenario 2 pending order accept blocked while DELETION_PENDING');

$vo2 = callWorker('admin_verify_orders', $adminToken, [], ['id' => $d2]);
assertEq($vo2['status'], 200, 'Scenario 2 verify-orders -> 200');
assertEq($vo2['json']['orders'][0]['effective_status'] ?? '', 'cancelled', 'Scenario 2 Rule A cancelled unpaid order');
assertEq((int) ($vo2['json']['refund_stats']['total_count'] ?? -1), 0, 'Scenario 2 queued 0 refunds for unpaid order');
assertTrue((bool) ($vo2['json']['all_checks_passed'] ?? false), 'Scenario 2 all checks passed after Rule A');

$stockAfter2 = (int) $pdo->query("SELECT stock FROM products WHERE id = {$p2}")->fetchColumn();
assertEq($stockAfter2, 10, 'Scenario 2 stock restored cleanly to 10');
finalizeToDeleted($d2, $s2['id'], 'Scenario 2');

// -----------------------------------------------------------------------------
// Scenario 3: Seller with paid but unaccepted orders (HMAC signature verify + Rule A refund)
// -----------------------------------------------------------------------------
echo "\n--- Scenario 3: Seller With Paid But Unaccepted Orders + HMAC Payment Verify ---\n";
$s3 = createSellerViaApi('Scenario3 Paid Unaccepted', '+919800000003', 's3@php.test');
$p3 = createProductViaApi($s3, 'S3 Paid Item', 700, 5);
$o3 = createOrderViaApi('+919700000003', [['product_id' => $p3, 'quantity' => 1]], 'razorpay');

$gwOrder3 = 'order_php_hmac_3';
$gwPay3 = 'pay_php_hmac_3';
$badVerify = callWorker('order_payment_verify', null, [
    'gateway_order_id' => $gwOrder3,
    'gateway_payment_id' => $gwPay3,
    'razorpay_signature' => 'invalid_sig',
], ['order_id' => $o3]);
assertEq($badVerify['status'], 403, 'Scenario 3 invalid HMAC signature rejected -> 403');

$goodSig3 = hash_hmac('sha256', "{$gwOrder3}|{$gwPay3}", 'rzp_test_php_secret');
$okVerify = callWorker('order_payment_verify', null, [
    'gateway_order_id' => $gwOrder3,
    'gateway_payment_id' => $gwPay3,
    'razorpay_signature' => $goodSig3,
    'captured_amount' => 700,
], ['order_id' => $o3]);
assertEq($okVerify['status'], 200, 'Scenario 3 valid Razorpay HMAC signature accepted -> 200', $okVerify);

$cr3 = callWorker('admin_create', $adminToken, ['seller_id' => $s3['id']]);
$d3 = (int) $cr3['json']['deletion_request']['id'];
$vo3 = callWorker('admin_verify_orders', $adminToken, [], ['id' => $d3]);
assertEq($vo3['json']['orders'][0]['effective_status'] ?? '', 'cancelled', 'Scenario 3 pending paid order cancelled via Rule A');
assertEq(checkByKey($vo3['json'], 'refunds_confirmed'), 'PENDING', 'Scenario 3 refunds_confirmed = PENDING');
assertEq(callWorker('admin_approve', $adminToken, [], ['id' => $d3])['status'], 409, 'Scenario 3 approve blocked while refund PENDING');

$callsBefore3 = count(getRazorpayCalls());
$pr3 = callWorker('admin_process_refunds', $adminToken, [], ['id' => $d3]);
assertEq($pr3['status'], 200, 'Scenario 3 process-refunds -> 200');
assertEq($pr3['json']['refunds'][0]['status'] ?? '', 'REFUND_SUCCESS', 'Scenario 3 refund confirmed REFUND_SUCCESS');
assertTrue(!empty($pr3['json']['refunds'][0]['gateway_refund_id']), 'Scenario 3 gateway_refund_id recorded');
assertEq(count(getRazorpayCalls()), $callsBefore3 + 1, 'Scenario 3 made 1 Razorpay call');

$pr3Repeat = callWorker('admin_process_refunds', $adminToken, [], ['id' => $d3]);
assertEq(count(getRazorpayCalls()), $callsBefore3 + 1, 'Scenario 3 repeat process-refunds idempotent (0 extra Razorpay calls)');

$ordSt3 = $pdo->query("SELECT payment_status, refunded_amount FROM orders WHERE id = {$o3}")->fetch(PDO::FETCH_ASSOC);
assertEq($ordSt3['payment_status'] ?? '', 'refunded', 'Scenario 3 order payment_status updated to refunded');
assertEq((float) ($ordSt3['refunded_amount'] ?? 0), 700.0, 'Scenario 3 refunded_amount=700');
finalizeToDeleted($d3, $s3['id'], 'Scenario 3');

// -----------------------------------------------------------------------------
// Scenario 4: Seller with accepted orders (Rule B requires approval)
// -----------------------------------------------------------------------------
echo "\n--- Scenario 4: Seller With Accepted Orders (Rule B) ---\n";
$s4 = createSellerViaApi('Scenario4 Accepted', '+919800000004', 's4@php.test');
$p4 = createProductViaApi($s4, 'S4 Accepted Item', 900);
$o4 = createOrderViaApi('+919700000004', [['product_id' => $p4, 'quantity' => 1]], 'razorpay');
assertEq(callWorker('order_payment_verify', $adminToken, ['gateway_payment_id' => 'pay_php_4', 'captured_amount' => 900], ['order_id' => $o4])['status'], 200, 'Scenario 4 capture paid');
assertEq(callWorker('accept_order', $s4['token'], [], ['order_id' => $o4])['status'], 200, 'Scenario 4 seller accepted order');

$cr4 = callWorker('admin_create', $adminToken, ['seller_id' => $s4['id']]);
$d4 = (int) $cr4['json']['deletion_request']['id'];

$vo4a = callWorker('admin_verify_orders', $adminToken, [], ['id' => $d4]);
assertEq($vo4a['json']['orders'][0]['effective_status'] ?? '', 'accepted', 'Scenario 4 Rule B does NOT auto-cancel accepted order without approval');
assertEq(callWorker('admin_approve', $adminToken, [], ['id' => $d4])['status'], 409, 'Scenario 4 approve blocked before Rule B approval');

$vo4b = callWorker('admin_verify_orders', $adminToken, ['approve_accepted_cancellations' => true], ['id' => $d4]);
assertEq($vo4b['json']['orders'][0]['effective_status'] ?? '', 'cancelled', 'Scenario 4 accepted order cancelled after Admin cancellation approval');
$pr4 = callWorker('admin_process_refunds', $adminToken, [], ['id' => $d4]);
assertEq($pr4['json']['refunds'][0]['status'] ?? '', 'REFUND_SUCCESS', 'Scenario 4 accepted order refund confirmed');
finalizeToDeleted($d4, $s4['id'], 'Scenario 4');

// -----------------------------------------------------------------------------
// Scenario 5: Shipped Orders (Rule C) + Multi-vendor proportional cap + Settlement
// -----------------------------------------------------------------------------
echo "\n--- Scenario 5: Shipped Orders (Rule C) & Multi-Vendor Proportional Cap ---\n";
$s5 = createSellerViaApi('Scenario5 Shipped Seller', '+919800000005', 's5@php.test');
$s5Peer = createSellerViaApi('Scenario5 Peer Seller', '+919800000055', 's5peer@php.test');
$p5a = createProductViaApi($s5, 'S5 Multi Item', 300);
$p5b = createProductViaApi($s5, 'S5 Ship Item', 1000);
$p5Peer = createProductViaApi($s5Peer, 'Peer Multi Item', 700);

$mvOrder = createOrderViaApi('+919700000005', [
    ['product_id' => $p5a, 'quantity' => 1],
    ['product_id' => $p5Peer, 'quantity' => 1],
], 'razorpay');
assertEq(callWorker('order_payment_verify', $adminToken, ['gateway_payment_id' => 'pay_php_mv5', 'captured_amount' => 1000], ['order_id' => $mvOrder])['status'], 200, 'Scenario 5 multi-vendor order captured 1000');

$shipOrder = createOrderViaApi('+919700000105', [['product_id' => $p5b, 'quantity' => 1]], 'razorpay');
assertEq(callWorker('order_payment_verify', $adminToken, ['gateway_payment_id' => 'pay_php_ship5', 'captured_amount' => 1000, 'settled' => false], ['order_id' => $shipOrder])['status'], 200, 'Scenario 5 shipped order captured 1000 (unsettled)');
assertEq(callWorker('update_order', $s5['token'], ['order_status' => 'shipped'], ['order_id' => $shipOrder])['status'], 200, 'Scenario 5 marked shipped');

$cr5 = callWorker('admin_create', $adminToken, ['seller_id' => $s5['id']]);
$d5 = (int) $cr5['json']['deletion_request']['id'];

$vo5 = callWorker('admin_verify_orders', $adminToken, [], ['id' => $d5]);
assertEq(checkByKey($vo5['json'], 'orders_resolved'), 'ON_HOLD', 'Scenario 5 orders_resolved ON_HOLD due to shipped order (Rule C)');

$pr5 = callWorker('admin_process_refunds', $adminToken, [], ['id' => $d5]);
assertEq((float) ($pr5['json']['refunds'][0]['refunded_amount'] ?? 0), 300.0, 'Scenario 5 multi-vendor refund proportionally capped at 300 of 1000');
assertEq($pdo->query("SELECT payment_status FROM orders WHERE id = {$mvOrder}")->fetchColumn(), 'partially_refunded', 'Scenario 5 multi-vendor order marked partially_refunded');
assertEq($pdo->query("SELECT order_status FROM orders WHERE id = {$mvOrder}")->fetchColumn(), 'pending', 'Scenario 5 multi-vendor peer order stays pending (not cancelled globally)');
assertEq($pdo->query("SELECT item_status FROM order_items WHERE order_id = {$mvOrder} AND seller_id = {$s5Peer['id']}")->fetchColumn(), 'pending', 'Scenario 5 peer item untouched');

assertEq(callWorker('admin_settle_payout', $adminToken, [], ['id' => $d5])['status'], 409, 'Scenario 5 settle-payout blocked while shipped order open');
assertEq(callWorker('admin_approve', $adminToken, [], ['id' => $d5])['status'], 409, 'Scenario 5 approve blocked while shipped order open');

$resShip5 = callWorker('admin_resolve_dispute', $adminToken, [
    'order_id' => $shipOrder,
    'mark_delivered' => true,
    'close_return_window' => true,
    'resolution_notes' => 'Carrier POD verified delivered and return window closed',
], ['id' => $d5]);
assertEq($resShip5['status'], 200, 'Scenario 5 resolve-dispute marks shipped delivered', $resShip5);
assertEq(checkByKey($resShip5['json'], 'orders_resolved'), 'SUCCESS', 'Scenario 5 orders_resolved becomes SUCCESS');
assertEq((float) ($resShip5['json']['settlement']['available_seller_balance'] ?? -1), 950.0, 'Scenario 5 available_seller_balance = 950 (5% commission on 1000)');
assertEq((float) ($resShip5['json']['settlement']['outstanding_commissions'] ?? -1), 50.0, 'Scenario 5 outstanding_commissions = 50');

// pay_php_ship5 is still 'captured' (not settled) until confirm_gateway_settled is passed
assertEq(callWorker('admin_settle_payout', $adminToken, [], ['id' => $d5])['status'], 409, 'Scenario 5 settle-payout blocked when gateway capture unsettled');

$settle5 = callWorker('admin_settle_payout', $adminToken, ['confirm_gateway_settled' => true, 'payout_reference' => 'PO-PHP-5'], ['id' => $d5]);
assertEq($settle5['status'], 200, 'Scenario 5 settle-payout succeeds with confirm_gateway_settled', $settle5);
assertTrue((bool) ($settle5['json']['all_checks_passed'] ?? false), 'Scenario 5 all checks pass after payout settlement');
finalizeToDeleted($d5, $s5['id'], 'Scenario 5');

// -----------------------------------------------------------------------------
// Scenario 6: Delivered Orders (Rule D return window + COD commission)
// -----------------------------------------------------------------------------
echo "\n--- Scenario 6: Delivered Orders (Rule D Return Window & COD Commission) ---\n";
$s6 = createSellerViaApi('Scenario6 Delivered', '+919800000006', 's6@php.test');
$p6a = createProductViaApi($s6, 'S6 Online Delivered', 1000);
$p6b = createProductViaApi($s6, 'S6 COD Delivered', 1000);

$o6a = createOrderViaApi('+919700000006', [['product_id' => $p6a, 'quantity' => 1]], 'razorpay');
callWorker('order_payment_verify', $adminToken, ['gateway_payment_id' => 'pay_php_6a', 'captured_amount' => 1000], ['order_id' => $o6a]);
callWorker('update_order', $s6['token'], ['order_status' => 'delivered'], ['order_id' => $o6a]); // Return window open 7 days

$o6b = createOrderViaApi('+919700000106', [['product_id' => $p6b, 'quantity' => 1]], 'cod');
callWorker('update_order', $s6['token'], ['order_status' => 'delivered'], ['order_id' => $o6b]);
$pdo->prepare("UPDATE orders SET return_window_ends_at = :past WHERE id = :id")
    ->execute([':past' => gmdate('Y-m-d H:i:s', time() - 3600), ':id' => $o6b]);

$cr6 = callWorker('admin_create', $adminToken, ['seller_id' => $s6['id']]);
$d6 = (int) $cr6['json']['deletion_request']['id'];
assertEq(checkByKey($cr6['json'], 'orders_resolved'), 'ON_HOLD', 'Scenario 6 active return window puts orders_resolved ON_HOLD');
assertEq(callWorker('admin_approve', $adminToken, [], ['id' => $d6])['status'], 409, 'Scenario 6 approve blocked during return window');

$closeRw6 = callWorker('admin_resolve_dispute', $adminToken, [
    'order_id' => $o6a,
    'close_return_window' => true,
    'resolution_notes' => 'Return window completed with no buyer complaints',
], ['id' => $d6]);
assertEq(checkByKey($closeRw6['json'], 'orders_resolved'), 'SUCCESS', 'Scenario 6 orders_resolved SUCCESS after closing return window');
assertEq((float) ($closeRw6['json']['settlement']['outstanding_commissions'] ?? -1), 100.0, 'Scenario 6 commission = 100 (5% of 1000 online + 1000 COD)');
assertEq((float) ($closeRw6['json']['settlement']['available_seller_balance'] ?? -1), 900.0, 'Scenario 6 net payable = 950 online - 50 COD commission = 900');

$settle6 = callWorker('admin_settle_payout', $adminToken, ['confirm_gateway_settled' => true, 'payout_reference' => 'PO-PHP-6'], ['id' => $d6]);
assertEq($settle6['status'], 200, 'Scenario 6 settle-payout succeeds');
finalizeToDeleted($d6, $s6['id'], 'Scenario 6');

// -----------------------------------------------------------------------------
// Scenario 7: Pending Refunds (Async Razorpay created -> GET poll + Missing gateway ID review)
// -----------------------------------------------------------------------------
echo "\n--- Scenario 7: Pending Refunds (Async Poll & Missing Gateway ID Review) ---\n";
$s7 = createSellerViaApi('Scenario7 Pending Refunds', '+919800000007', 's7@php.test');
$p7 = createProductViaApi($s7, 'S7 Item', 450);
$o7 = createOrderViaApi('+919700000007', [['product_id' => $p7, 'quantity' => 1]], 'razorpay');
callWorker('order_payment_verify', $adminToken, ['gateway_payment_id' => 'pay_php_7', 'captured_amount' => 450], ['order_id' => $o7]);
callWorker('update_order', $s7['token'], ['order_status' => 'cancelled', 'cancellation_reason' => 'Seller out of stock'], ['order_id' => $o7]);

$cr7 = callWorker('admin_create', $adminToken, ['seller_id' => $s7['id']]);
$d7 = (int) $cr7['json']['deletion_request']['id'];
assertEq($cr7['json']['metrics']['pending_refunds_count'] ?? -1, 1, 'Scenario 7 detects unrefunded cancelled paid order (Rule E)');
assertEq(callWorker('admin_approve', $adminToken, [], ['id' => $d7])['status'], 409, 'Scenario 7 approve blocked while refund pending');

setRazorpayMockMode('pending_then_processed');
$callsBefore7 = count(getRazorpayCalls());
$pr7a = callWorker('admin_process_refunds', $adminToken, [], ['id' => $d7]);
assertEq($pr7a['status'], 200, 'Scenario 7 first process-refunds returns 200');
assertEq($pr7a['json']['refunds'][0]['status'] ?? '', 'REFUND_PROCESSING', 'Scenario 7 gateway status created -> REFUND_PROCESSING (not fake success)');
assertEq($pr7a['json']['deletion_request']['status'] ?? '', 'REFUND_PROCESSING', 'Scenario 7 request stays REFUND_PROCESSING');
assertEq(callWorker('admin_approve', $adminToken, [], ['id' => $d7])['status'], 409, 'Scenario 7 approve blocked while REFUND_PROCESSING');

$pr7b = callWorker('admin_process_refunds', $adminToken, [], ['id' => $d7]);
$callsAfter7 = getRazorpayCalls();
assertEq(count($callsAfter7), $callsBefore7 + 2, 'Scenario 7 poll made exactly 1 GET call (no duplicate POST refund)');
assertEq($callsAfter7[count($callsAfter7) - 1]['method'] ?? '', 'GET', 'Scenario 7 second call polled GET /refunds/:id');
assertEq($pr7b['json']['refunds'][0]['status'] ?? '', 'REFUND_SUCCESS', 'Scenario 7 confirmed REFUND_SUCCESS after poll');
setRazorpayMockMode('success');
finalizeToDeleted($d7, $s7['id'], 'Scenario 7');

// Sub-check 7B: Missing gateway_payment_id -> REFUND_REQUIRES_REVIEW
$s7b = createSellerViaApi('Scenario7B Missing Gateway ID', '+919800000077', 's7b@php.test');
$p7b = createProductViaApi($s7b, 'S7B Item', 300);
$o7b = createOrderViaApi('+919700000177', [['product_id' => $p7b, 'quantity' => 1]], 'razorpay');
$pdo->prepare("UPDATE orders SET payment_status = 'paid', order_status = 'cancelled' WHERE id = :oid")->execute([':oid' => $o7b]);
$cr7b = callWorker('admin_create', $adminToken, ['seller_id' => $s7b['id']]);
$d7b = (int) $cr7b['json']['deletion_request']['id'];
$pr7Missing = callWorker('admin_process_refunds', $adminToken, [], ['id' => $d7b]);
assertEq($pr7Missing['json']['refunds'][0]['status'] ?? '', 'REFUND_REQUIRES_REVIEW', 'Scenario 7B missing gateway_payment_id -> REFUND_REQUIRES_REVIEW');
assertEq(checkByKey($pr7Missing['json'], 'refunds_confirmed'), 'ON_HOLD', 'Scenario 7B refunds_confirmed -> ON_HOLD');
assertEq(callWorker('admin_approve', $adminToken, [], ['id' => $d7b])['status'], 409, 'Scenario 7B missing gateway_payment_id blocks approval');

// -----------------------------------------------------------------------------
// Scenario 8: Failed Refunds + Retry + Hold & Reject Workflow
// -----------------------------------------------------------------------------
echo "\n--- Scenario 8: Failed Refunds, Idempotent Retry, Hold & Reject ---\n";
$s8 = createSellerViaApi('Scenario8 Failed Refunds', '+919800000008', 's8@php.test');
$p8 = createProductViaApi($s8, 'S8 Item', 550);
$o8 = createOrderViaApi('+919700000008', [['product_id' => $p8, 'quantity' => 1]], 'razorpay');
callWorker('order_payment_verify', $adminToken, ['gateway_payment_id' => 'pay_php_8', 'captured_amount' => 550], ['order_id' => $o8]);

$cr8 = callWorker('admin_create', $adminToken, ['seller_id' => $s8['id']]);
$d8 = (int) $cr8['json']['deletion_request']['id'];
callWorker('admin_verify_orders', $adminToken, [], ['id' => $d8]);

setRazorpayMockMode('fail');
$pr8Fail = callWorker('admin_process_refunds', $adminToken, [], ['id' => $d8]);
assertEq($pr8Fail['json']['refunds'][0]['status'] ?? '', 'REFUND_FAILED', 'Scenario 8 Razorpay 500 -> REFUND_FAILED');
assertEq($pr8Fail['json']['deletion_request']['status'] ?? '', 'REFUND_FAILED', 'Scenario 8 request status REFUND_FAILED');
assertEq($pr8Fail['json']['deletion_request']['badge_color'] ?? '', 'red', 'Scenario 8 failed status badge color red');
assertEq(callWorker('admin_settle_payout', $adminToken, [], ['id' => $d8])['status'], 409, 'Scenario 8 settle-payout blocked while refund failed');
assertEq(callWorker('admin_approve', $adminToken, [], ['id' => $d8])['status'], 409, 'Scenario 8 approve blocked while refund failed');

// Test Hold + Reject recovery
$hold8 = callWorker('admin_hold', $adminToken, ['reason' => 'Awaiting payment gateway ticket'], ['id' => $d8]);
assertEq($hold8['json']['deletion_request']['status'] ?? '', 'DELETION_ON_HOLD', 'Scenario 8 put ON HOLD');
assertEq($hold8['json']['deletion_request']['badge_color'] ?? '', 'orange', 'Scenario 8 hold badge color orange');

assertEq(callWorker('admin_reject', $adminToken, ['reason' => ''], ['id' => $d8])['status'], 422, 'Scenario 8 reject without reason -> 422');
$rej8 = callWorker('admin_reject', $adminToken, ['reason' => 'Seller requested continuation'], ['id' => $d8]);
assertEq($rej8['status'], 200, 'Scenario 8 reject with reason -> 200');
assertEq($rej8['json']['deletion_request']['status'] ?? '', 'DELETION_REJECTED', 'Scenario 8 DELETION_REJECTED');
assertEq($rej8['json']['seller']['account_status'] ?? '', 'ACTIVE', 'Scenario 8 seller restored to ACTIVE');
assertEq(callWorker('me', $s8['token'], [], [], [], 'GET')['status'], 200, 'Scenario 8 restored seller token still valid on /api/me');

// Re-initiate deletion & recover failed refund on gateway success
setRazorpayMockMode('success');
$cr8b = callWorker('admin_create', $adminToken, ['seller_id' => $s8['id']]);
$d8b = (int) $cr8b['json']['deletion_request']['id'];
$pr8Ok = callWorker('admin_process_refunds', $adminToken, [], ['id' => $d8b]);
assertEq($pr8Ok['json']['refunds'][0]['status'] ?? '', 'REFUND_SUCCESS', 'Scenario 8 failed refund recovered on retry');
assertEq((int) ($pr8Ok['json']['refunds'][0]['attempts'] ?? 0), 2, 'Scenario 8 refund attempts incremented to 2');
finalizeToDeleted($d8b, $s8['id'], 'Scenario 8');

// -----------------------------------------------------------------------------
// Scenario 9: Seller With Pending Payouts
// -----------------------------------------------------------------------------
echo "\n--- Scenario 9: Seller With Pending Payouts ---\n";
$s9 = createSellerViaApi('Scenario9 Pending Payouts', '+919800000009', 's9@php.test');
$p9 = createProductViaApi($s9, 'S9 Item', 1000);
$o9 = createOrderViaApi('+919700000009', [['product_id' => $p9, 'quantity' => 1]], 'razorpay');
callWorker('order_payment_verify', $adminToken, ['gateway_payment_id' => 'pay_php_9', 'captured_amount' => 1000], ['order_id' => $o9]);
callWorker('update_order', $s9['token'], ['order_status' => 'delivered'], ['order_id' => $o9]);
$pdo->prepare("UPDATE orders SET return_window_ends_at = :past WHERE id = :id")->execute([':past' => gmdate('Y-m-d H:i:s', time() - 60), ':id' => $o9]);
$pdo->prepare("
    INSERT INTO seller_payouts (seller_id, amount, commission_deducted, net_amount, status, notes)
    VALUES (:sid, 500.00, 25.00, 475.00, 'PENDING', 'Scheduled weekly payout')
")->execute([':sid' => $s9['id']]);

$cr9 = callWorker('admin_create', $adminToken, ['seller_id' => $s9['id']]);
$d9 = (int) $cr9['json']['deletion_request']['id'];
assertEq($cr9['json']['metrics']['pending_payouts_count'] ?? -1, 1, 'Scenario 9 pending_payouts_count = 1');
assertEq((float) ($cr9['json']['metrics']['pending_payouts_amount'] ?? -1), 475.0, 'Scenario 9 pending_payouts_amount = 475');
assertEq(checkByKey($cr9['json'], 'payouts_settled'), 'PENDING', 'Scenario 9 payouts_settled = PENDING');
assertEq(callWorker('admin_approve', $adminToken, [], ['id' => $d9])['status'], 409, 'Scenario 9 approve blocked with pending payouts');

$settle9 = callWorker('admin_settle_payout', $adminToken, ['confirm_gateway_settled' => true, 'payout_reference' => 'PO-PHP-9'], ['id' => $d9]);
assertEq($settle9['status'], 200, 'Scenario 9 settle-payout succeeds');
assertEq(checkByKey($settle9['json'], 'payouts_settled'), 'SUCCESS', 'Scenario 9 payouts_settled = SUCCESS');

$payoutCountBeforeRepeat9 = (int) $pdo->query("SELECT COUNT(*) FROM seller_payouts WHERE seller_id = {$s9['id']}")->fetchColumn();
callWorker('admin_settle_payout', $adminToken, ['confirm_gateway_settled' => true, 'payout_reference' => 'PO-PHP-9-DUP'], ['id' => $d9]);
$payoutCountAfterRepeat9 = (int) $pdo->query("SELECT COUNT(*) FROM seller_payouts WHERE seller_id = {$s9['id']}")->fetchColumn();
assertEq($payoutCountAfterRepeat9, $payoutCountBeforeRepeat9, 'Scenario 9 repeat settle-payout does not duplicate payout rows');

$s9PayoutsApi = callWorker('get_payouts', $s9['token'], [], [], [], 'GET');
assertEq($s9PayoutsApi['status'], 200, 'Scenario 9 seller /api/payouts returns 200');
assertTrue(count($s9PayoutsApi['json']['payouts'] ?? []) >= 1, 'Scenario 9 /api/payouts returns real DB payouts');
finalizeToDeleted($d9, $s9['id'], 'Scenario 9');

// -----------------------------------------------------------------------------
// Scenario 10: Seller With Active Disputes
// -----------------------------------------------------------------------------
echo "\n--- Scenario 10: Seller With Active Disputes ---\n";
$s10 = createSellerViaApi('Scenario10 Active Disputes', '+919800000010', 's10@php.test');
$p10 = createProductViaApi($s10, 'S10 Item', 600);
$o10 = createOrderViaApi('+919700000010', [['product_id' => $p10, 'quantity' => 1]], 'razorpay');
callWorker('order_payment_verify', $adminToken, ['gateway_payment_id' => 'pay_php_10', 'captured_amount' => 600, 'settled' => true], ['order_id' => $o10]);
callWorker('update_order', $s10['token'], ['order_status' => 'delivered'], ['order_id' => $o10]);
$pdo->prepare("UPDATE orders SET return_window_ends_at = :past, settled_at = CURRENT_TIMESTAMP WHERE id = :id")
    ->execute([':past' => gmdate('Y-m-d H:i:s', time() - 60), ':id' => $o10]);

assertEq(callWorker('order_create_dispute', null, ['buyer_phone' => '+910000000000', 'description' => 'Unauthorized phone'], ['order_id' => $o10])['status'], 401, 'Scenario 10 dispute with mismatched buyer phone -> 401');
$disp10 = callWorker('order_create_dispute', null, ['buyer_phone' => '+919700000010', 'dispute_type' => 'damaged_item', 'claim_amount' => 600, 'description' => 'Item arrived damaged'], ['order_id' => $o10]);
assertEq($disp10['status'], 201, 'Scenario 10 create dispute with matching buyer_phone -> 201');
$dispId10 = (int) ($disp10['json']['dispute']['id'] ?? 0);

$cr10 = callWorker('admin_create', $adminToken, ['seller_id' => $s10['id']]);
$d10 = (int) $cr10['json']['deletion_request']['id'];
assertEq(checkByKey($cr10['json'], 'disputes_resolved'), 'ON_HOLD', 'Scenario 10 disputes_resolved = ON_HOLD');
assertEq(callWorker('admin_settle_payout', $adminToken, [], ['id' => $d10])['status'], 409, 'Scenario 10 settle-payout blocked with open dispute');
assertEq(callWorker('admin_approve', $adminToken, [], ['id' => $d10])['status'], 409, 'Scenario 10 approve blocked with open dispute');

$res10 = callWorker('admin_resolve_dispute', $adminToken, ['dispute_id' => $dispId10, 'resolution' => 'RESOLVED', 'resolution_notes' => 'Replacement confirmed by buyer'], ['id' => $d10]);
assertEq($res10['status'], 200, 'Scenario 10 resolve-dispute -> 200');
assertEq(checkByKey($res10['json'], 'disputes_resolved'), 'SUCCESS', 'Scenario 10 disputes_resolved -> SUCCESS');
finalizeToDeleted($d10, $s10['id'], 'Scenario 10');

// -----------------------------------------------------------------------------
// Scenario 11: Fully Settled Orders + Permanent Deletion & History Preservation
// -----------------------------------------------------------------------------
echo "\n--- Scenario 11: Fully Settled Seller, Soft-Delete, Token Revocation & History Preservation ---\n";
$s11 = createSellerViaApi('Scenario11 Fully Settled', '+919800000011', 's11@php.test');
$p11 = createProductViaApi($s11, 'S11 Settled Watch', 1200, 15);
$o11 = createOrderViaApi('+919700000011', [['product_id' => $p11, 'quantity' => 1]], 'razorpay');
callWorker('order_payment_verify', $adminToken, ['gateway_payment_id' => 'pay_php_11', 'captured_amount' => 1200, 'settled' => true], ['order_id' => $o11]);
callWorker('update_order', $s11['token'], ['order_status' => 'delivered'], ['order_id' => $o11]);
$pdo->prepare("UPDATE orders SET return_window_ends_at = :past, settled_at = CURRENT_TIMESTAMP WHERE id = :id")
    ->execute([':past' => gmdate('Y-m-d H:i:s', time() - 60), ':id' => $o11]);
$pdo->prepare("INSERT INTO seller_payouts (seller_id, amount, commission_deducted, net_amount, status, payout_reference, settled_at) VALUES (:sid, 1200, 60, 1140, 'SETTLED', 'PO-PHP-11', CURRENT_TIMESTAMP)")
    ->execute([':sid' => $s11['id']]);
$pdo->prepare("INSERT INTO device_tokens (user_id, phone, token, platform) VALUES (:sid, :ph, 'fcm_tok_s11', 'android')")
    ->execute([':sid' => $s11['id'], ':ph' => $s11['phone']]);

$cr11 = callWorker('admin_create', $adminToken, ['seller_id' => $s11['id'], 'reason' => 'Voluntary account closure']);
$d11 = (int) $cr11['json']['deletion_request']['id'];
assertTrue((bool) ($cr11['json']['all_checks_passed'] ?? false), 'Scenario 11 pre-settled seller passes all checks immediately');
callWorker('admin_verify_orders', $adminToken, [], ['id' => $d11]);
callWorker('admin_verify_settlement', $adminToken, [], ['id' => $d11]);
finalizeToDeleted($d11, $s11['id'], 'Scenario 11');

// Post-delete security & preservation assertions
assertTrue(in_array(callWorker('me', $s11['token'], [], [], [], 'GET')['status'], [401, 403], true), 'Scenario 11 old seller JWT revoked');
assertEq(callWorker('login', null, ['email_or_phone' => $s11['phone'], 'password' => 'Seller123!'])['status'], 403, 'Scenario 11 login rejected (403)');
assertEq(callWorker('register', null, ['seller_name' => 'Re-reg', 'phone' => $s11['phone'], 'email' => $s11['email'], 'address' => 'A', 'password' => 'Seller123!', 'password_confirmation' => 'Seller123!'])['status'], 403, 'Scenario 11 re-register rejected (403)');
assertEq(callWorker('get_seller_profile', null, [], [], ['seller_id' => $s11['id']], 'GET')['status'], 404, 'Scenario 11 public seller profile returns 404');

$patLeft = (int) $pdo->query("SELECT COUNT(*) FROM personal_access_tokens WHERE tokenable_id = {$s11['id']}")->fetchColumn();
$devLeft = (int) $pdo->query("SELECT COUNT(*) FROM device_tokens WHERE user_id = {$s11['id']}")->fetchColumn();
assertEq($patLeft, 0, 'Scenario 11 personal_access_tokens deleted');
assertEq($devLeft, 0, 'Scenario 11 device_tokens deleted');

$prodRow11 = $pdo->query("SELECT is_active, stock FROM products WHERE id = {$p11}")->fetch(PDO::FETCH_ASSOC);
assertEq((int) $prodRow11['is_active'], 0, 'Scenario 11 product soft-deactivated (is_active=0)');
assertEq((int) $prodRow11['stock'], 0, 'Scenario 11 product stock zeroed');

$pubList11 = callWorker('get_products', null, [], [], ['seller_id' => $s11['id']], 'GET');
assertEq(count($pubList11['json']['data'] ?? []), 0, 'Scenario 11 deactivated products hidden from /api/products');
assertEq(callWorker('get_product', null, [], ['id' => $p11], [], 'GET')['status'], 404, 'Scenario 11 deactivated product -> 404');

// Historical preservation checks
assertEq((int) $pdo->query("SELECT COUNT(*) FROM orders WHERE id = {$o11}")->fetchColumn(), 1, 'Scenario 11 historical order preserved');
assertEq((int) $pdo->query("SELECT COUNT(*) FROM order_items WHERE order_id = {$o11}")->fetchColumn(), 1, 'Scenario 11 historical order_items preserved');
assertEq((int) $pdo->query("SELECT COUNT(*) FROM payment_transactions WHERE order_id = {$o11}")->fetchColumn(), 1, 'Scenario 11 payment_transactions preserved');
assertEq((int) $pdo->query("SELECT COUNT(*) FROM seller_payouts WHERE seller_id = {$s11['id']}")->fetchColumn(), 1, 'Scenario 11 seller_payouts preserved');
assertEq((int) $pdo->query("SELECT COUNT(*) FROM seller_gst_details WHERE seller_id = {$s11['id']}")->fetchColumn(), 1, 'Scenario 11 seller_gst_details preserved');
assertTrue((int) $pdo->query("SELECT COUNT(*) FROM seller_deletion_audit_logs WHERE seller_id = {$s11['id']}")->fetchColumn() >= 4, 'Scenario 11 audit trail preserved');
assertEq(callWorker('delete_order', $adminToken, [], ['order_id' => $o11])['status'], 403, 'Scenario 11 DELETE /api/orders/:id blocked with 403 for deleted seller order');

echo "\n===================================================================\n";
echo "🎉 ALL PHP SELLER DELETION TESTS PASSED ({$assertCount} assertions)!\n";
echo "===================================================================\n\n";
