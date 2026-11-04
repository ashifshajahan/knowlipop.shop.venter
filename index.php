<?php

/**
 * Vendor App — Pure PHP Entry Point & Router
 * Zero dependencies, works on 100% of PHP hosting environments (Hostinger / cPanel / Apache / Nginx / PHP CLI)
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/helpers/response.php';
require_once __DIR__ . '/helpers/auth.php';

// Handle CORS for mobile app & frontend requests
handleCors();

// Parse requested URI & method
$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// Normalize URI (remove trailing slash except for root)
if ($requestUri !== '/' && str_ends_with($requestUri, '/')) {
    $requestUri = rtrim($requestUri, '/');
}

// Strip /index.php if present in URI
if (str_starts_with($requestUri, '/index.php')) {
    $requestUri = substr($requestUri, 10) ?: '/';
}

// ==========================================
// 1. Web & Admin Routes
// ==========================================

if ($requestUri === '/test-firebase' || $requestUri === '/firebase-otp') {
    header('Content-Type: text/html; charset=utf-8');
    if (file_exists(__DIR__ . '/test-firebase.html')) {
        readfile(__DIR__ . '/test-firebase.html');
    } else {
        echo 'Firebase tester file not found.';
    }
    exit;
}

if ($requestUri === '/test-buyer' || $requestUri === '/buyer') {
    header('Content-Type: text/html; charset=utf-8');
    if (file_exists(__DIR__ . '/test-buyer.html')) {
        readfile(__DIR__ . '/test-buyer.html');
    } else {
        echo 'Buyer test module file not found.';
    }
    exit;
}

// Static uploaded files handler (/uploads/products/..., /api/images/...)
if (str_starts_with($requestUri, '/uploads/') || str_starts_with($requestUri, '/api/images/') || str_starts_with($requestUri, '/public/uploads/') || str_starts_with($requestUri, '/storage/uploads/')) {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
    header('Cross-Origin-Resource-Policy: cross-origin');
    if ($method === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    $cleanUri = parse_url($requestUri, PHP_URL_PATH);
    if (str_starts_with($cleanUri, '/api/images/')) {
        $cleanUri = '/uploads/products/' . substr($cleanUri, strlen('/api/images/'));
    } elseif (str_starts_with($cleanUri, '/public/uploads/')) {
        $cleanUri = '/uploads/' . substr($cleanUri, strlen('/public/uploads/'));
    } elseif (str_starts_with($cleanUri, '/storage/uploads/')) {
        $cleanUri = '/uploads/' . substr($cleanUri, strlen('/storage/uploads/'));
    }

    $filePath = __DIR__ . $cleanUri;
    if (file_exists($filePath) && is_file($filePath)) {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mimes = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png', 'webp' => 'image/webp',
            'gif' => 'image/gif', 'svg' => 'image/svg+xml'
        ];
        header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
        header('Cache-Control: public, max-age=31536000, immutable');
        readfile($filePath);
        exit;
    }
    // Reliable Fallback for missing uploaded images: Never return 404/blank!
    if (str_starts_with($cleanUri, '/uploads/products/')) {
        $placeholder = __DIR__ . '/uploads/products/placeholder.jpg';
        if (file_exists($placeholder)) {
            header('Content-Type: image/jpeg');
            header('Cache-Control: public, max-age=86400');
            readfile($placeholder);
            exit;
        }
        $samplePng = __DIR__ . '/uploads/products/prod_1793598726521_793ff4ae.png';
        if (file_exists($samplePng)) {
            header('Content-Type: image/png');
            header('Cache-Control: public, max-age=86400');
            readfile($samplePng);
            exit;
        }
        header('Location: https://images.unsplash.com/photo-1505740420928-5e560c06d30e?w=800', true, 302);
        exit;
    }
    if (str_starts_with($cleanUri, '/uploads/avatars/')) {
        header('Location: https://images.unsplash.com/photo-1535713875002-d1d0cf377fde?w=400', true, 302);
        exit;
    }
    errorResponse('File not found.', [], 404);
}

if ($requestUri === '/' || $requestUri === '') {
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Vendor App Backend</title>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0f172a; color: #f8fafc; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
            .card { background: #1e293b; padding: 40px; border-radius: 16px; border: 1px solid #334155; max-width: 520px; width: 90%; text-align: center; box-shadow: 0 20px 40px rgba(0,0,0,0.3); }
            h1 { color: #38bdf8; margin-top: 0; }
            p { color: #94a3b8; line-height: 1.6; }
            .badge { display: inline-block; background: #064e3b; color: #34d399; font-size: 13px; font-weight: 700; padding: 6px 14px; border-radius: 9999px; margin-bottom: 20px; }
            .btn { display: inline-block; background: #6366f1; color: white; text-decoration: none; padding: 12px 24px; border-radius: 8px; font-weight: 700; margin-top: 20px; }
            .btn:hover { background: #4f46e5; }
            .endpoints { text-align: left; background: #0f172a; padding: 16px; border-radius: 8px; margin-top: 24px; font-family: monospace; font-size: 13px; color: #cbd5e1; }
        </style>
    </head>
    <body>
        <div class="card">
            <span class="badge">● Pure Native PHP Online</span>
            <h1>Vendor App Backend</h1>
            <p>High-performance, zero-dependency REST API for Vendor marketplace.</p>
            <div style="display: flex; gap: 10px; justify-content: center; flex-wrap: wrap;">
                <a href="/admin/login" class="btn">Admin Dashboard</a>
                <a href="/test-buyer" class="btn" style="background: #0284c7;">Buyer Test Module</a>
            </div>
            <div class="endpoints">
                <strong>Available Endpoints:</strong><br>
                • GET /test-buyer (Interactive Buyer Hub & Order Editor)<br>
                • GET /invoice/{id} (Downloadable Provider Tax Invoice)<br>
                • GET /api/orders<br>
                • POST /api/orders<br>
                • GET /api/orders/{id}<br>
                • PUT /api/orders/{id}<br>
                • DELETE /api/orders/{id}<br>
                • GET /api/products<br>
                • GET /admin/login
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

if ($requestUri === '/login') {
    header('Location: /admin/login');
    exit;
}

if ($requestUri === '/admin/login') {
    require __DIR__ . '/admin/login.php';
    exit;
}

if ($requestUri === '/admin' || $requestUri === '/admin/dashboard' || $requestUri === '/admin/sellers') {
    require __DIR__ . '/admin/dashboard.php';
    exit;
}

if ($requestUri === '/admin/logout') {
    require __DIR__ . '/admin/logout.php';
    exit;
}

// ==========================================
// 2. API Routes
// ==========================================

require_once __DIR__ . '/api/auth.php';
require_once __DIR__ . '/api/products.php';
require_once __DIR__ . '/api/payment.php';
require_once __DIR__ . '/api/seller.php';
require_once __DIR__ . '/api/orders.php';
require_once __DIR__ . '/api/invoice.php';

// Health Check
if ($requestUri === '/api/health') {
    $dbStatus = 'connected';
    $dbError = null;
    try {
        $checkPdo = getDatabaseConnection();
        $checkPdo->query('SELECT 1');
    } catch (Throwable $e) {
        $dbStatus = 'disconnected';
        $dbError = $e->getMessage();
    }

    jsonResponse([
        'status' => $dbStatus === 'connected' ? 'ok' : 'degraded',
        'app' => 'Vendor App API',
        'engine' => 'Pure PHP',
        'database' => [
            'status' => $dbStatus,
            ...( $dbError ? ['error' => $dbError] : [] )
        ]
    ], $dbStatus === 'connected' ? 200 : 503);
}

$pdo = getDatabaseConnection();

// Authentication
if ($requestUri === '/api/register') {
    apiRegister($pdo);
}

if ($requestUri === '/api/login') {
    apiLogin($pdo);
}

if ($requestUri === '/api/me') {
    if ($method === 'GET') {
        apiGetSellerProfile($pdo);
    } elseif ($method === 'PUT' || $method === 'PATCH' || $method === 'POST') {
        apiUpdateSellerProfile($pdo);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

if ($requestUri === '/api/logout') {
    if ($method !== 'POST') {
        errorResponse('Method Not Allowed', [], 405);
    }
    apiLogout($pdo);
}

if ($requestUri === '/api/forgot-password') {
    if ($method !== 'POST') {
        errorResponse('Method Not Allowed', [], 405);
    }
    apiForgotPassword($pdo);
}

if ($requestUri === '/api/verify-otp') {
    if ($method !== 'POST') {
        errorResponse('Method Not Allowed', [], 405);
    }
    apiVerifyOtp($pdo);
}

if ($requestUri === '/api/reset-password') {
    if ($method !== 'POST') {
        errorResponse('Method Not Allowed', [], 405);
    }
    apiResetPassword($pdo);
}

// Products Catalog & CRUD
if ($requestUri === '/api/products') {
    if ($method === 'GET') {
        apiGetProducts($pdo);
    } elseif ($method === 'POST') {
        apiCreateProduct($pdo);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

// Dedicated Image Upload Endpoint: /api/upload or /api/products/upload
if ($requestUri === '/api/upload' || $requestUri === '/api/products/upload') {
    if ($method !== 'POST') errorResponse('Method Not Allowed', [], 405);
    apiUploadImage($pdo);
}

// Product images list: /api/products/{id}/images
if (preg_match('#^/api/products/(\d+)/images$#', $requestUri, $matches)) {
    if ($method !== 'GET') errorResponse('Method Not Allowed', [], 405);
    apiGetProductImages($pdo, (int) $matches[1]);
}

// Product image replacement: /api/products/{id}/image or /upload
if (preg_match('#^/api/products/(\d+)/(image|upload)$#', $requestUri, $matches)) {
    $productId = (int) $matches[1];
    if ($method === 'PUT' || $method === 'POST' || $method === 'PATCH') {
        apiReplaceProductImage($pdo, $productId);
    } elseif ($method === 'DELETE') {
        apiDeleteProductImage($pdo, $productId);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

// Product detail / update / delete: /api/products/{id}, /api/product/{id}, /api/seller/products/{id}, /api/seller/product/{id}
if (preg_match('#^/api/(?:seller/)?products?/(\d+)$#', $requestUri, $matches)) {
    $productId = (int) $matches[1];
    if ($method === 'GET') {
        apiGetProduct($pdo, $productId);
    } elseif ($method === 'PUT' || $method === 'PATCH' || $method === 'POST') {
        apiUpdateProduct($pdo, $productId);
    } elseif ($method === 'DELETE') {
        apiDeleteProduct($pdo, $productId);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

// Seller Profile & Edit (Authenticated Seller)
if ($requestUri === '/api/seller/profile') {
    if ($method === 'GET') {
        apiGetSellerProfile($pdo);
    } elseif ($method === 'PUT' || $method === 'PATCH' || $method === 'POST') {
        apiUpdateSellerProfile($pdo);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

if ($requestUri === '/api/seller/profile/update') {
    if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
        apiUpdateSellerProfile($pdo);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

// Separate Password Update: (current_password, new_password, confirm_password)
if ($requestUri === '/api/seller/change-password' || $requestUri === '/api/change-password' || $requestUri === '/api/seller/password') {
    if ($method !== 'POST' && $method !== 'PUT' && $method !== 'PATCH') {
        errorResponse('Method Not Allowed', [], 405);
    }
    apiChangePassword($pdo);
}

// Seller Products Catalog: /api/seller/products or /api/sellers/products
if ($requestUri === '/api/seller/products' || $requestUri === '/api/sellers/products') {
    if ($method === 'GET') {
        apiGetSellerProducts($pdo);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

// Seller Products by Seller ID: /api/sellers/{id}/products or /api/seller/{id}/products
if (preg_match('#^/(?:api/sellers?)/(\d+)/products$#', $requestUri, $matches)) {
    $sellerId = (int)$matches[1];
    if ($method === 'GET') {
        apiGetSellerProducts($pdo, $sellerId);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

// Seller Orders List: /api/seller/orders or /api/sellers/orders
if ($requestUri === '/api/seller/orders' || $requestUri === '/api/sellers/orders') {
    if ($method === 'GET') {
        apiGetSellerOrders($pdo);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

// Seller Orders List by Seller ID: /api/sellers/{id}/orders or /api/seller/{id}/orders
if (preg_match('#^/(?:api/sellers?)/(\d+)/orders$#', $requestUri, $matches)) {
    $sellerId = (int)$matches[1];
    if ($method === 'GET') {
        apiGetSellerOrders($pdo, $sellerId);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

// Seller Dashboard & Analytics: /api/seller/dashboard or /api/seller/stats or /api/sellers/dashboard
if ($requestUri === '/api/seller/dashboard' || $requestUri === '/api/seller/stats' || $requestUri === '/api/sellers/dashboard' || $requestUri === '/api/sellers/stats' || $requestUri === '/api/seller/analytics') {
    if ($method === 'GET') {
        apiGetSellerDashboard($pdo);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

// Seller Dashboard by Seller ID: /api/sellers/{id}/dashboard or /api/sellers/{id}/stats
if (preg_match('#^/(?:api/sellers?)/(\d+)/(?:dashboard|stats|analytics)$#', $requestUri, $matches)) {
    $sellerId = (int)$matches[1];
    if ($method === 'GET') {
        apiGetSellerDashboard($pdo, $sellerId);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

// Notifications Endpoints
if ($requestUri === '/api/seller/notifications' || $requestUri === '/api/notifications') {
    if ($method === 'GET') {
        apiGetNotifications($pdo);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

if ($requestUri === '/api/seller/notifications/unread-count' || $requestUri === '/api/notifications/unread-count') {
    if ($method === 'GET') {
        apiGetUnreadNotificationsCount($pdo);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

if ($requestUri === '/api/seller/notifications/mark-all-read' || $requestUri === '/api/notifications/read-all' || $requestUri === '/api/notifications/mark-all-read') {
    if ($method === 'PUT' || $method === 'POST') {
        apiMarkAllNotificationsRead($pdo);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

if (preg_match('#^/(?:api/seller/notifications|api/notifications)/(\d+)/read$#', $requestUri, $matches)) {
    $notifId = (int)$matches[1];
    if ($method === 'PUT' || $method === 'POST') {
        apiMarkNotificationRead($pdo, $notifId);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

if ($requestUri === '/api/seller/fcm-token' || $requestUri === '/api/fcm-token' || $requestUri === '/api/device-token' || $requestUri === '/api/notifications/token') {
    if ($method === 'POST') {
        apiRegisterDeviceToken($pdo);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

// Seller Orders History: /api/seller/orders/history or /api/sellers/orders/history
if ($requestUri === '/api/seller/orders/history' || $requestUri === '/api/sellers/orders/history') {
    if ($method === 'GET') {
        $_GET['history'] = 'true';
        apiGetSellerOrders($pdo);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

// Seller Orders Actions: /api/seller/orders/{order_id}/(accept|reject|cancel|status|process|pack|ship|deliver)
if (preg_match('#^/api/seller/orders/([^/]+)/(accept|reject|cancel|status|process|pack|ship|deliver)$#', $requestUri, $matches)) {
    $orderParam = trim($matches[1]);
    $action = strtolower(trim($matches[2]));
    if ($action === 'accept') {
        apiAcceptOrder($pdo, $orderParam);
    } elseif ($action === 'reject') {
        apiRejectOrder($pdo, $orderParam);
    } elseif ($action === 'cancel') {
        apiCancelOrder($pdo, $orderParam);
    } elseif ($action === 'status') {
        apiUpdateOrderStatus($pdo, $orderParam);
    } else {
        $statusMap = ['process' => 'processing', 'pack' => 'packed', 'ship' => 'shipped', 'deliver' => 'delivered'];
        $st = $statusMap[$action] ?? $action;
        $input = getJsonInput();
        $input['order_status'] = $st;
        $GLOBALS['_CUSTOM_JSON_INPUT'] = $input;
        apiUpdateOrder($pdo, $orderParam);
    }
}

// Seller Single Order Detail by Order ID: /api/seller/orders/{order_id}
if (preg_match('#^/api/seller/orders/([^/]+)$#', $requestUri, $matches)) {
    $orderParam = trim($matches[1]);
    if ($orderParam === 'history') {
        $_GET['history'] = 'true';
        apiGetSellerOrders($pdo);
    } elseif ($method === 'GET') {
        apiGetOrder($pdo, $orderParam);
    } elseif ($method === 'PUT' || $method === 'PATCH' || $method === 'POST') {
        apiUpdateOrder($pdo, $orderParam);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

// Seller Single Order Detail by Seller ID & Order ID: /api/sellers/{seller_id}/orders/{order_id}
if (preg_match('#^/(?:api/sellers?)/(\d+)/orders/([^/]+)$#', $requestUri, $matches)) {
    $sellerId = (int)$matches[1];
    $orderParam = trim($matches[2]);
    if ($method === 'GET') {
        apiGetOrder($pdo, $orderParam, $sellerId);
    } elseif ($method === 'PUT' || $method === 'PATCH' || $method === 'POST') {
        apiUpdateOrder($pdo, $orderParam, $sellerId);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

// Sellers Directory
if ($requestUri === '/api/sellers') {
    if ($method === 'GET') {
        apiGetSellersList($pdo);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

// Seller details by ID: /api/sellers/{id} or /api/seller/{id} or /api/seller/profile/{id}
if (preg_match('#^/(?:api/sellers?|api/seller/profile)/(\d+)$#', $requestUri, $matches)) {
    $sellerId = (int) $matches[1];
    if ($method === 'GET') {
        apiGetSellerById($pdo, $sellerId);
    } elseif ($method === 'PUT' || $method === 'PATCH' || $method === 'POST') {
        apiUpdateSellerProfile($pdo, $sellerId);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

// Seller Bank Account & Payments
if ($requestUri === '/api/seller/bank-account/onboarding') {
    if ($method !== 'POST') errorResponse('Method Not Allowed', [], 405);
    apiBankOnboarding($pdo);
}

if ($requestUri === '/api/seller/bank-account/status') {
    if ($method !== 'GET') errorResponse('Method Not Allowed', [], 405);
    apiBankStatus($pdo);
}

if ($requestUri === '/api/seller/payouts') {
    if ($method !== 'GET') errorResponse('Method Not Allowed', [], 405);
    apiPayouts($pdo);
}

if ($requestUri === '/api/marketplace/commission-preview') {
    if ($method !== 'POST') errorResponse('Method Not Allowed', [], 405);
    apiCommissionPreview($pdo);
}

// Buyer Orders & Order Items CRUD (supports /api/orders and /api/order)
if ($requestUri === '/api/orders' || $requestUri === '/api/order') {
    if ($method === 'GET') {
        apiGetOrders($pdo);
    } elseif ($method === 'POST') {
        apiCreateOrder($pdo);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

// Provider Invoice Generator & Download: /api/orders/{id}/invoice, /api/order/{id}/invoice, /invoice/{id}
if (preg_match('#^(?:/api/orders?/([^/]+)/invoice|/invoice/([^/]+))$#', $requestUri, $matches)) {
    $orderParam = trim(!empty($matches[1]) ? $matches[1] : $matches[2]);
    if ($method === 'GET') {
        apiGenerateOrderInvoice($pdo, $orderParam);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

// Order Detail / Update / Delete: /api/orders/{id} or /api/order/{id}
if (preg_match('#^/api/orders?/([^/]+)$#', $requestUri, $matches)) {
    $orderParam = trim($matches[1]);
    if ($method === 'GET') {
        apiGetOrder($pdo, $orderParam);
    } elseif ($method === 'PUT' || $method === 'PATCH' || $method === 'POST') {
        apiUpdateOrder($pdo, $orderParam);
    } elseif ($method === 'DELETE') {
        apiDeleteOrder($pdo, $orderParam);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

// Route Not Found fallback
errorResponse('Route not found.', [], 404);
