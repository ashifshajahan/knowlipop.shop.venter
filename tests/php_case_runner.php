<?php

$inputRaw = file_get_contents('php://stdin');
$payload = json_decode($inputRaw, true) ?: [];

$dbFile = $payload['db_file'] ?? '';
$action = $payload['action'] ?? '';
$server = $payload['server'] ?? [];
$post = $payload['post'] ?? [];

foreach ($server as $k => $v) {
    $_SERVER[$k] = $v;
}

$pdo = new PDO("sqlite:{$dbFile}");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$_POST = $post;
$GLOBALS['_CUSTOM_JSON_INPUT'] = $post;

require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../api/auth.php';
require_once __DIR__ . '/../api/orders.php';
require_once __DIR__ . '/../api/products.php';

try {
    switch ($action) {
        case 'auth_register':
            apiRegister($pdo);
            break;

        case 'auth_login':
            apiLogin($pdo);
            break;

        case 'auth_me':
            apiMe($pdo);
            break;

        case 'forgot_password':
            apiForgotPassword($pdo);
            break;

        case 'verify_otp':
            apiVerifyOtp($pdo);
            break;

        case 'reset_password':
            apiResetPassword($pdo);
            break;

        case 'get_seller_orders':
            $routeSellerId = $post['route_seller_id'] ?? null;
            apiGetSellerOrders($pdo, $routeSellerId);
            break;

        case 'create_order':
            apiCreateOrder($pdo);
            break;

        case 'get_order_detail':
            $orderId = $post['order_id'] ?? 1;
            apiGetOrder($pdo, $orderId);
            break;

        case 'get_seller_products':
            $routeSellerId = $post['route_seller_id'] ?? null;
            apiGetSellerProducts($pdo, $routeSellerId);
            break;

        case 'update_product':
            $productId = $post['product_id'] ?? 1;
            apiUpdateProduct($pdo, (int)$productId);
            break;

        case 'delete_product':
            $productId = $post['product_id'] ?? 1;
            apiDeleteProduct($pdo, (int)$productId);
            break;

        default:
            echo json_encode(['error' => 'Unknown action']);
            break;
    }
} catch (Throwable $e) {
    echo json_encode(['exception' => $e->getMessage()]);
}
