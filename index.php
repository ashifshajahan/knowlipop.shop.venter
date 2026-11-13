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

if ($requestUri === '/test-buyer' || $requestUri === '/buyer') {
    header('Content-Type: text/html; charset=utf-8');
    if (file_exists(__DIR__ . '/test-buyer.html')) {
        readfile(__DIR__ . '/test-buyer.html');
    } else {
        echo 'Buyer test module file not found.';
    }
    exit;
}

// Static uploaded files handler & Omni-route image resolver (/uploads/, /api/images/, /api/uploads/, /storage/, /storage/app/public/, /public/storage/, /images/, /products/, double-URLs)
$imgMatch = [];
$hasImgFile = preg_match('/(prod_\d+_[a-f0-9]+\.(?:jpg|jpeg|jfif|png|webp|gif|bmp|tif|tiff|avif|heic|heif|ico|svg)|placeholder\.(?:jpg|jpeg|png)|avatar_\d+_[a-f0-9]+\.(?:jpg|jpeg|png|webp|gif|svg))/i', $requestUri, $imgMatch);
$isImgPrefix = str_starts_with($requestUri, '/uploads/') || str_starts_with($requestUri, '/api/images/') || 
               str_starts_with($requestUri, '/api/products/images/') || str_starts_with($requestUri, '/api/uploads/') || 
               str_starts_with($requestUri, '/public/uploads/') || str_starts_with($requestUri, '/storage/') || 
               str_starts_with($requestUri, '/public/storage/') ||
               str_starts_with($requestUri, '/images/') || str_starts_with($requestUri, '/products/') ||
               str_contains($requestUri, '/uploads/products/') || str_contains($requestUri, '/uploads/avatars/') ||
               str_contains($requestUri, '/storage/app/public/products/') || str_contains($requestUri, '/public/storage/products/');

if (in_array($method, ['GET', 'HEAD', 'OPTIONS']) && ($hasImgFile || $isImgPrefix)) {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
    header('Access-Control-Allow-Headers: *');
    header('Cross-Origin-Resource-Policy: cross-origin');
    if ($method === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    $fileName = !empty($imgMatch[1]) ? $imgMatch[1] : basename(parse_url($requestUri, PHP_URL_PATH));
    $isAvatar = str_contains($requestUri, '/avatars/') || str_starts_with(strtolower($fileName), 'avatar_');

    if (!empty($fileName) && !in_array(strtolower($fileName), ['uploads', 'products', 'avatars', 'images', 'storage', 'api'])) {
        $filePath = resolveUploadedMediaPathPhp($fileName, $isAvatar);

        if ($filePath && file_exists($filePath) && is_file($filePath)) {
            $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            $mimes = [
                'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'jfif' => 'image/jpeg',
                'png' => 'image/png', 'webp' => 'image/webp',
                'gif' => 'image/gif', 'bmp' => 'image/bmp',
                'tif' => 'image/tiff', 'tiff' => 'image/tiff',
                'avif' => 'image/avif', 'heic' => 'image/heic', 'heif' => 'image/heif',
                'ico' => 'image/x-icon', 'svg' => 'image/svg+xml'
            ];
            header('Content-Type: ' . ($mimes[$ext] ?? 'image/webp'));
            header('Cache-Control: public, max-age=31536000, immutable');
            readfile($filePath);
            exit;
        }
    }

    // File not found on disk: Never redirect to external sample/demo URLs or automatic placeholders
    if ($isAvatar) {
        $avatarPlaceholder = resolveUploadedMediaPathPhp('placeholder.jpg', true);
        if ($avatarPlaceholder && file_exists($avatarPlaceholder)) {
            header('Content-Type: image/jpeg');
            header('Cache-Control: public, max-age=86400');
            readfile($avatarPlaceholder);
            exit;
        }
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['message' => 'Avatar image not found.']);
        exit;
    }

    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['message' => 'Product image not found.']);
    exit;
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
                <a href="/test-buyer" class="btn" style="background: #0284c7;">Buyer Checkout &amp; Orders Hub</a>
            </div>
            <div class="endpoints">
                <strong>Available Endpoints:</strong><br>
                • GET /test-buyer (Interactive Buyer Hub &amp; Razorpay Checkout)<br>
                • GET /invoice/{id} (Downloadable Provider Tax Invoice)<br>
                • POST /api/payment/razorpay/create-order (Create Razorpay Order)<br>
                • POST /api/payment/verify (Verify Razorpay Payment Signature)<br>
                • POST /api/payment/webhook (Razorpay Webhooks)<br>
                • GET /api/payment/status/{id} (Check Payment Status)<br>
                • POST /api/payment/refund (Full &amp; Partial Refunds)<br>
                • GET /api/admin/payment-transactions (Admin Transactions Manager)<br>
                • GET /api/orders<br>
                • POST /api/orders<br>
                • GET /api/orders/{id}<br>
                • PUT /api/orders/{id}<br>
                • DELETE /api/orders/{id}<br>
                • GET /api/products (Catalog + 5 Editable Categories)<br>
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

if ($requestUri === '/admin/get_gst_details') {
    $_GET['action'] = 'get_gst_details';
    require __DIR__ . '/admin/dashboard.php';
    exit;
}

if ($requestUri === '/admin/view_gst_doc') {
    $_GET['action'] = 'view_gst_doc';
    require __DIR__ . '/admin/dashboard.php';
    exit;
}

if ($requestUri === '/admin/seller-deletions') {
    $_GET['tab'] = $_GET['tab'] ?? 'seller-deletions';
    require __DIR__ . '/admin/dashboard.php';
    exit;
}

if ($requestUri === '/admin/products') {
    $_GET['tab'] = $_GET['tab'] ?? 'products';
    require __DIR__ . '/admin/dashboard.php';
    exit;
}

if ($requestUri === '/admin/sellers') {
    $_GET['tab'] = $_GET['tab'] ?? 'sellers';
    require __DIR__ . '/admin/dashboard.php';
    exit;
}

if ($requestUri === '/admin/create-seller') {
    $_GET['tab'] = $_GET['tab'] ?? 'create-seller';
    require __DIR__ . '/admin/dashboard.php';
    exit;
}

if ($requestUri === '/admin/gst-verifications') {
    $_GET['tab'] = $_GET['tab'] ?? 'gst';
    require __DIR__ . '/admin/dashboard.php';
    exit;
}

if ($requestUri === '/admin/categories') {
    $_GET['tab'] = $_GET['tab'] ?? 'categories';
    require __DIR__ . '/admin/dashboard.php';
    exit;
}

if ($requestUri === '/admin/transactions' || $requestUri === '/admin/payments') {
    $_GET['tab'] = $_GET['tab'] ?? 'transactions';
    require __DIR__ . '/admin/dashboard.php';
    exit;
}

if ($requestUri === '/admin' || $requestUri === '/admin/dashboard' || $requestUri === '/admin/dashboard.php') {
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
require_once __DIR__ . '/api/gst.php';
require_once __DIR__ . '/api/seller_deletion.php';
require_once __DIR__ . '/helpers/mailer.php';

// Health Check
if ($requestUri === '/api/health') {
    $dbStatus = 'connected';
    $dbError = null;
    $dbCfg = getDatabaseConfig();
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
            'host' => $dbCfg['host'] ?? '127.0.0.1',
            'name' => $dbCfg['name'] ?? 'u999617803_knowlipop1',
            ...( $dbError ? ['error' => $dbError] : [] )
        ]
    ], $dbStatus === 'connected' ? 200 : 503);
}

$pdo = getDatabaseConnection();

// Authentication
if ($requestUri === '/api/register' || $requestUri === '/api/auth/register') {
    apiRegister($pdo);
}

if ($requestUri === '/api/login' || $requestUri === '/api/auth/login') {
    apiLogin($pdo);
}

if ($requestUri === '/api/me') {
    if ($method === 'GET') {
        apiGetSellerProfile($pdo);
    } elseif ($method === 'PUT' || $method === 'PATCH' || $method === 'POST') {
        apiUpdateSellerProfile($pdo);
    } elseif ($method === 'DELETE') {
        apiSellerRequestDeletion($pdo);
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

if ($requestUri === '/api/forgot-password' || $requestUri === '/api/auth/forgot-password') {
    if ($method !== 'POST') {
        errorResponse('Method Not Allowed', [], 405);
    }
    apiForgotPassword($pdo);
}

if ($requestUri === '/api/verify-otp' || $requestUri === '/api/auth/verify-otp') {
    if ($method !== 'POST') {
        errorResponse('Method Not Allowed', [], 405);
    }
    apiVerifyOtp($pdo);
}

if ($requestUri === '/api/reset-password' || $requestUri === '/api/auth/reset-password') {
    if ($method !== 'POST') {
        errorResponse('Method Not Allowed', [], 405);
    }
    apiResetPassword($pdo);
}

// Products Catalog, Categories & CRUD (All category listing, filtering & editing included inside Product API)
if ($requestUri === '/api/products') {
    if ($method === 'GET') {
        apiGetProducts($pdo);
    } elseif ($method === 'POST') {
        apiCreateProduct($pdo);
    } elseif ($method === 'PUT' || $method === 'PATCH' || $method === 'DELETE') {
        apiManageProductCategoriesViaProducts($pdo);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

// Dedicated Image Upload Endpoint: /api/upload or /api/products/upload
if ($requestUri === '/api/upload' || $requestUri === '/api/products/upload') {
    if ($method !== 'POST') errorResponse('Method Not Allowed', [], 405);
    apiUploadImage($pdo);
}

// Standalone image deletion: /api/images/{filename}
if (preg_match('#^/api/images/([^/]+)$#', $requestUri, $matches)) {
    if ($method === 'DELETE') {
        apiDeleteStandaloneImage($pdo, $matches[1]);
    }
}

// Product single image detail / replace / delete: /api/products/{id}/images/{image_id}
if (preg_match('#^/api/products/(\d+)/images/([^/]+)$#', $requestUri, $matches)) {
    $productId = (int) $matches[1];
    $targetIdOrFile = $matches[2];
    if ($method === 'GET') {
        apiGetProductImageDetail($pdo, $productId, $targetIdOrFile);
    } elseif ($method === 'PUT' || $method === 'POST' || $method === 'PATCH') {
        apiReplaceProductImage($pdo, $productId, $targetIdOrFile);
    } elseif ($method === 'DELETE') {
        apiDeleteProductImage($pdo, $productId, $targetIdOrFile);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

// Product images list / replace / delete: /api/products/{id}/images or /api/products/{id}/image or /upload
if (preg_match('#^/api/products/(\d+)/(images|image|upload)$#', $requestUri, $matches)) {
    $productId = (int) $matches[1];
    if ($method === 'GET') {
        apiGetProductImages($pdo, $productId);
    } elseif ($method === 'PUT' || $method === 'POST' || $method === 'PATCH') {
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
    } elseif ($method === 'DELETE') {
        apiSellerRequestDeletion($pdo);
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

if ($requestUri === '/api/seller/notifications/unread' || $requestUri === '/api/notifications/unread') {
    if ($method === 'GET') {
        apiGetUnreadNotifications($pdo);
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

if ($requestUri === '/api/seller/notifications/mark-all-read' || $requestUri === '/api/seller/notifications/read-all' || $requestUri === '/api/notifications/read-all' || $requestUri === '/api/notifications/mark-all-read') {
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

if (preg_match('#^/(?:api/seller/notifications|api/notifications)/(\d+)$#', $requestUri, $matches)) {
    $notifId = (int)$matches[1];
    if ($method === 'DELETE') {
        apiDeleteNotification($pdo, $notifId);
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

// Seller Cancellation Request Approve / Reject: /api/seller/orders/{order_id}/cancellation/(approve|reject)
if (preg_match('#^/api/seller/orders/([^/]+)/cancellation/(approve|reject)$#', $requestUri, $matches)) {
    $orderParam = trim($matches[1]);
    $act = strtolower(trim($matches[2]));
    if ($method !== 'POST' && $method !== 'PUT') {
        errorResponse('Method Not Allowed', [], 405);
    }
    if ($act === 'approve') {
        apiApproveCancellation($pdo, $orderParam);
    } else {
        apiRejectCancellation($pdo, $orderParam);
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
    } elseif ($method === 'DELETE' && !str_starts_with($requestUri, '/api/seller/profile/')) {
        apiSellerRequestDeletion($pdo, $sellerId);
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

// ==========================================
// Razorpay Payment Gateway Routes
// ==========================================

// Razorpay Order Creation
if ($requestUri === '/api/payment/razorpay/create-order' || $requestUri === '/api/payment/create-order') {
    if ($method !== 'POST') errorResponse('Method Not Allowed', [], 405);
    apiRazorpayCreateOrder($pdo);
}

if (preg_match('#^/api/orders?/([^/]+)/(?:razorpay-order|create-payment)$#', $requestUri, $matches)) {
    if ($method !== 'POST') errorResponse('Method Not Allowed', [], 405);
    apiRazorpayCreateOrder($pdo, trim($matches[1]));
}

// Razorpay Payment Signature Verification
if ($requestUri === '/api/payment/razorpay/verify' || $requestUri === '/api/payment/verify') {
    if ($method !== 'POST') errorResponse('Method Not Allowed', [], 405);
    apiRazorpayVerify($pdo);
}

// Razorpay Webhooks
if ($requestUri === '/api/payment/webhook' || $requestUri === '/api/payment/razorpay/webhook' || $requestUri === '/api/webhooks/razorpay') {
    if ($method !== 'POST') errorResponse('Method Not Allowed', [], 405);
    apiRazorpayWebhook($pdo);
}

// Payment Status
if (preg_match('#^/api/orders?/([^/]+)/payment-status$#', $requestUri, $matches)) {
    if ($method !== 'GET') errorResponse('Method Not Allowed', [], 405);
    apiPaymentStatus($pdo, trim($matches[1]));
}

if (preg_match('#^/api/payment/status/([^/]+)$#', $requestUri, $matches)) {
    if ($method !== 'GET') errorResponse('Method Not Allowed', [], 405);
    apiPaymentStatus($pdo, trim($matches[1]));
}

if ($requestUri === '/api/payment/status') {
    if ($method !== 'GET') errorResponse('Method Not Allowed', [], 405);
    apiPaymentStatus($pdo);
}

// Payment Refunds (Full & Partial)
if ($requestUri === '/api/payment/refund') {
    if ($method !== 'POST') errorResponse('Method Not Allowed', [], 405);
    apiPaymentRefund($pdo);
}

if (preg_match('#^/api/orders?/([^/]+)/refund$#', $requestUri, $matches)) {
    if ($method !== 'POST') errorResponse('Method Not Allowed', [], 405);
    apiPaymentRefund($pdo, trim($matches[1]));
}

// Client Payment Failed reporting
if ($requestUri === '/api/payment/failed') {
    if ($method !== 'POST') errorResponse('Method Not Allowed', [], 405);
    apiPaymentFailed($pdo);
}

// Admin Payment Transactions Management
if ($requestUri === '/api/admin/payment-transactions' || $requestUri === '/api/admin/payments') {
    if ($method !== 'GET') errorResponse('Method Not Allowed', [], 405);
    apiAdminPaymentTransactions($pdo);
}

if (preg_match('#^/api/admin/payment-transactions/(\d+)$#', $requestUri, $matches)) {
    if ($method === 'GET') {
        apiAdminPaymentTransactionDetail($pdo, (int) $matches[1]);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

if (preg_match('#^/api/admin/payment-transactions/(\d+)/refund$#', $requestUri, $matches)) {
    if ($method === 'POST') {
        apiAdminPaymentTransactionRefund($pdo, (int) $matches[1]);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

// ==========================================
// Seller GST Document Upload & Management
// ==========================================

if ($requestUri === '/api/seller/gst/submit' || $requestUri === '/api/seller/gst' || $requestUri === '/api/seller/gst/upload' || $requestUri === '/api/seller/gst/status') {
    if ($method === 'POST') {
        apiSellerSubmitGst($pdo);
    } elseif ($method === 'GET') {
        apiSellerGetGst($pdo);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

if ($requestUri === '/api/seller/gst/documents') {
    if ($method === 'POST') {
        apiSellerUploadGstDocument($pdo);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

if (preg_match('#^/api/seller/gst/documents/(\d+)$#', $requestUri, $matches)) {
    $docId = (int) $matches[1];
    if ($method === 'DELETE') {
        apiSellerDeleteGstDocument($pdo, $docId);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

if (preg_match('#^/api/seller/gst/documents/(\d+)/(preview|download)$#', $requestUri, $matches)) {
    $docId = (int) $matches[1];
    $mode = $matches[2];
    if ($method === 'GET') {
        apiSellerViewGstDocument($pdo, $docId, $mode);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

// ==========================================
// Admin GST Verification Routes
// ==========================================

if ($requestUri === '/api/admin/sellers/gst/pending') {
    if ($method === 'GET') {
        apiAdminGetPendingGst($pdo);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

if ($requestUri === '/api/admin/sellers/gst') {
    if ($method === 'GET') {
        apiAdminGetSellersGst($pdo);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

if (preg_match('#^/api/admin/sellers/gst/(\d+)$#', $requestUri, $matches)) {
    $targetSellerId = (int) $matches[1];
    if ($method === 'GET') {
        apiAdminGetSellerGstDetail($pdo, $targetSellerId);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

if (preg_match('#^/api/admin/sellers/gst/(\d+)/approve$#', $requestUri, $matches)) {
    $targetSellerId = (int) $matches[1];
    if ($method === 'POST') {
        apiAdminApproveGst($pdo, $targetSellerId);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

if (preg_match('#^/api/admin/sellers/gst/(\d+)/reject$#', $requestUri, $matches)) {
    $targetSellerId = (int) $matches[1];
    if ($method === 'POST') {
        apiAdminRejectGst($pdo, $targetSellerId);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

if (preg_match('#^/api/admin/sellers/gst/documents/(\d+)/(preview|download)$#', $requestUri, $matches)) {
    $docId = (int) $matches[1];
    $mode = $matches[2];
    if ($method === 'GET') {
        apiAdminViewGstDocument($pdo, $docId, $mode);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

// ==========================================
// Seller Self-Service Account Deletion Routes
// ==========================================

if (in_array($requestUri, [
    '/api/seller/deletion-request',
    '/api/seller/deletion-requests',
    '/api/seller/deletion',
    '/api/seller/request-deletion',
    '/api/seller/delete-request',
], true)) {
    if ($method === 'GET') {
        apiSellerGetDeletionStatus($pdo);
    } elseif ($method === 'POST') {
        apiSellerRequestDeletion($pdo);
    } elseif ($method === 'DELETE') {
        apiSellerWithdrawDeletion($pdo);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

if (in_array($requestUri, [
    '/api/seller/deletion/status',
    '/api/seller/deletion-status',
    '/api/seller/deletion/preflight',
    '/api/seller/deletion-request/preflight',
], true)) {
    if ($method === 'GET') {
        apiSellerGetDeletionStatus($pdo);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

if (in_array($requestUri, [
    '/api/seller/delete-account',
    '/api/seller/account/delete',
    '/api/seller/account/deletion-request',
], true)) {
    if ($method === 'GET') {
        apiSellerGetDeletionStatus($pdo);
    } elseif ($method === 'POST' || $method === 'DELETE') {
        apiSellerRequestDeletion($pdo);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

if ($requestUri === '/api/seller/profile/delete') {
    if ($method === 'POST' || $method === 'DELETE') {
        apiSellerRequestDeletion($pdo);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

if ($requestUri === '/api/seller/account') {
    if ($method === 'DELETE') {
        apiSellerRequestDeletion($pdo);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

if (in_array($requestUri, [
    '/api/seller/deletion-request/cancel',
    '/api/seller/deletion-request/withdraw',
    '/api/seller/deletion/cancel',
    '/api/seller/deletion/withdraw',
], true)) {
    if ($method === 'POST' || $method === 'DELETE') {
        apiSellerWithdrawDeletion($pdo);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

if (preg_match('#^/api/seller/deletion-requests?/(\d+)(?:/(cancel|withdraw))?$#', $requestUri, $matches)) {
    if (!empty($matches[2]) && ($method === 'POST' || $method === 'DELETE')) {
        apiSellerWithdrawDeletion($pdo, null, (int) $matches[1]);
    } elseif (empty($matches[2]) && $method === 'DELETE') {
        apiSellerWithdrawDeletion($pdo, null, (int) $matches[1]);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

if (preg_match('#^/api/sellers?/(\d+)/(deletion-request|request-deletion)$#', $requestUri, $matches)) {
    $targetSid = (int) $matches[1];
    if ($method === 'GET') {
        apiSellerGetDeletionStatus($pdo, $targetSid);
    } elseif ($method === 'POST') {
        apiSellerRequestDeletion($pdo, $targetSid);
    } elseif ($method === 'DELETE' && $matches[2] === 'deletion-request') {
        apiSellerWithdrawDeletion($pdo, $targetSid);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

if (preg_match('#^/api/sellers?/(\d+)/deletion-request/(cancel|withdraw)$#', $requestUri, $matches)) {
    if ($method === 'POST' || $method === 'DELETE') {
        apiSellerWithdrawDeletion($pdo, (int) $matches[1]);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

if ($requestUri === '/api/seller-deletions' || $requestUri === '/api/seller-deletions/request') {
    if ($method === 'GET' && $requestUri === '/api/seller-deletions') {
        apiSellerGetDeletionStatus($pdo, null, true);
    } elseif ($method === 'POST') {
        apiSellerRequestDeletion($pdo, null, true);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

// ==========================================
// Admin Seller Account Deletion & Refund Routes
// ==========================================

if ($requestUri === '/api/admin/seller-deletions') {
    if ($method === 'GET') {
        apiAdminListSellerDeletions($pdo);
    } elseif ($method === 'POST') {
        apiAdminCreateSellerDeletion($pdo);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

if ($requestUri === '/api/admin/seller-deletions/request') {
    if ($method === 'POST') {
        apiAdminCreateSellerDeletion($pdo);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

if (preg_match('#^/api/admin/seller-deletions/preflight/(\d+)$#', $requestUri, $matches)) {
    if ($method === 'GET') {
        apiAdminSellerDeletionPreflight($pdo, (int) $matches[1]);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

if (preg_match('#^/api/admin/seller-deletions/(\d+)$#', $requestUri, $matches)) {
    if ($method === 'GET') {
        apiAdminGetSellerDeletionDetail($pdo, (int) $matches[1]);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

if (preg_match('#^/api/admin/seller-deletions/(\d+)/(?:lock|lock-account)$#', $requestUri, $matches)) {
    if ($method === 'POST') {
        apiAdminLockSellerAccount($pdo, (int) $matches[1]);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

if (preg_match('#^/api/admin/seller-deletions/(\d+)/verify-orders$#', $requestUri, $matches)) {
    if ($method === 'POST') {
        apiAdminVerifySellerDeletionOrders($pdo, (int) $matches[1]);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

if (preg_match('#^/api/admin/seller-deletions/(\d+)/refunds$#', $requestUri, $matches)) {
    if ($method === 'GET') {
        apiAdminGetSellerDeletionRefunds($pdo, (int) $matches[1]);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

if (preg_match('#^/api/admin/seller-deletions/(\d+)/process-refunds$#', $requestUri, $matches)) {
    if ($method === 'POST') {
        apiAdminProcessSellerDeletionRefunds($pdo, (int) $matches[1]);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

if (preg_match('#^/api/admin/seller-deletions/(\d+)/verify-settlement$#', $requestUri, $matches)) {
    if ($method === 'POST') {
        apiAdminVerifySellerDeletionSettlement($pdo, (int) $matches[1]);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

if (preg_match('#^/api/admin/seller-deletions/(\d+)/settle-payout$#', $requestUri, $matches)) {
    if ($method === 'POST') {
        apiAdminSettleSellerDeletionPayout($pdo, (int) $matches[1]);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

if (preg_match('#^/api/admin/seller-deletions/(\d+)/resolve-dispute$#', $requestUri, $matches)) {
    if ($method === 'POST') {
        apiAdminResolveSellerDeletionDispute($pdo, (int) $matches[1]);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

if (preg_match('#^/api/admin/seller-deletions/(\d+)/approve$#', $requestUri, $matches)) {
    if ($method === 'POST') {
        apiAdminApproveSellerDeletion($pdo, (int) $matches[1]);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

if (preg_match('#^/api/admin/seller-deletions/(\d+)/reject$#', $requestUri, $matches)) {
    if ($method === 'POST') {
        apiAdminRejectSellerDeletion($pdo, (int) $matches[1]);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

if (preg_match('#^/api/admin/seller-deletions/(\d+)/hold$#', $requestUri, $matches)) {
    if ($method === 'POST') {
        apiAdminHoldSellerDeletion($pdo, (int) $matches[1]);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

if (preg_match('#^/api/admin/seller-deletions/(\d+)/final-delete$#', $requestUri, $matches)) {
    if ($method === 'POST') {
        apiAdminFinalDeleteSeller($pdo, (int) $matches[1]);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

// ==========================================
// Admin Hostinger Business Email (SMTP) Routes
// ==========================================

if ($requestUri === '/api/admin/email/config' || $requestUri === '/api/admin/email/status') {
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    $adminActorId = null;
    if (!empty($_SESSION['admin_logged_in']) && !empty($_SESSION['admin_id'])) {
        $adminActorId = (int) $_SESSION['admin_id'];
    } else {
        $u = authenticateUser($pdo);
        if (strtolower((string) ($u['role'] ?? '')) !== 'admin') {
            errorResponse('Unauthorized. Admin access required.', [], 403);
        }
        $adminActorId = (int) ($u['id'] ?? 1);
    }

    if ($method === 'GET') {
        jsonResponse([
            'status' => 'success',
            'email' => getPublicMailConfigSummaryPhp($pdo),
        ]);
    } elseif ($method === 'POST' || $method === 'PUT') {
        $input = getJsonInput();
        $saved = saveMailConfigPhp($pdo, $input, $adminActorId);
        jsonResponse([
            'status' => 'success',
            'message' => !empty($saved['configured'])
                ? 'Hostinger Business Email SMTP settings saved successfully.'
                : 'Mail settings saved (enter mailbox username & password to enable SMTP delivery).',
            'email' => $saved,
        ]);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

if ($requestUri === '/api/admin/email/test') {
    if ($method !== 'POST') {
        errorResponse('Method Not Allowed', [], 405);
    }
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    if (empty($_SESSION['admin_logged_in'])) {
        $u = authenticateUser($pdo);
        if (strtolower((string) ($u['role'] ?? '')) !== 'admin') {
            errorResponse('Unauthorized. Admin access required.', [], 403);
        }
    }
    $input = getJsonInput();
    $to = trim((string) ($input['to'] ?? $input['email'] ?? ''));
    if (!isRealDeliverableEmailPhp($to)) {
        errorResponse('Please provide a valid recipient email address.', ['to' => ['Valid recipient email is required.']], 422);
    }
    $override = [];
    foreach (['host', 'port', 'encryption', 'username', 'password', 'from_address', 'from_name', 'reply_to'] as $k) {
        if (isset($input[$k]) && $input[$k] !== '') {
            $override[$k] = $input[$k];
        }
    }
    $res = sendTestMailPhp($pdo, $to, $override);
    if (!empty($res['sent'])) {
        jsonResponse([
            'status' => 'success',
            'message' => "Test email dispatched to {$to} via Hostinger Mail SMTP.",
            'result' => $res,
        ]);
    }
    errorResponse((string) ($res['error'] ?? $res['reason'] ?? 'Failed to send test email via Hostinger SMTP.'), ['result' => $res], !empty($res['skipped']) ? 422 : 502);
}

if ($requestUri === '/api/admin/email/send') {
    if ($method !== 'POST') {
        errorResponse('Method Not Allowed', [], 405);
    }
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    if (empty($_SESSION['admin_logged_in'])) {
        $u = authenticateUser($pdo);
        if (strtolower((string) ($u['role'] ?? '')) !== 'admin') {
            errorResponse('Unauthorized. Admin access required.', [], 403);
        }
    }
    $input = getJsonInput();
    $to = trim((string) ($input['to'] ?? $input['email'] ?? ''));
    $subject = trim((string) ($input['subject'] ?? ''));
    $messageBody = trim((string) ($input['message'] ?? $input['text'] ?? ''));
    $htmlBody = (string) ($input['html'] ?? '');
    if (!isRealDeliverableEmailPhp($to)) {
        errorResponse('Please provide a valid recipient email address.', ['to' => ['Valid recipient email is required.']], 422);
    }
    if ($subject === '') {
        errorResponse('Subject is required.', ['subject' => ['Subject is required.']], 422);
    }
    if ($messageBody === '' && trim($htmlBody) === '') {
        errorResponse('Email message body is required.', ['message' => ['Email body is required.']], 422);
    }
    $appName = mailerReadEnvPhp('APP_NAME', 'Knowlipop Vendor');
    $finalHtml = trim($htmlBody) !== ''
        ? $htmlBody
        : wrapEmailLayoutPhp($appName, 'ADMIN MESSAGE', $subject, '<p style="margin:0;white-space:pre-line;">' . htmlspecialchars($messageBody, ENT_QUOTES, 'UTF-8') . '</p>');
    $res = sendHostingerEmailPhp([
        'pdo' => $pdo,
        'to' => $to,
        'subject' => $subject,
        'html' => $finalHtml,
        'text' => $messageBody ?: strip_tags($finalHtml),
    ]);
    if (!empty($res['sent'])) {
        jsonResponse([
            'status' => 'success',
            'message' => "Email sent to {$to} via Hostinger Mail SMTP.",
            'result' => $res,
        ]);
    }
    errorResponse((string) ($res['error'] ?? $res['reason'] ?? 'Failed to send email via Hostinger SMTP.'), ['result' => $res], !empty($res['skipped']) ? 422 : 502);
}

// ==========================================
// Admin Orders & Cancellation Routes
// ==========================================

if ($requestUri === '/api/admin/orders') {
    if ($method === 'GET') {
        apiAdminGetOrders($pdo);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

if (preg_match('#^/api/admin/orders/([^/]+)/status$#', $requestUri, $matches)) {
    $orderParam = trim($matches[1]);
    if ($method === 'PUT' || $method === 'POST' || $method === 'PATCH') {
        apiAdminUpdateOrderStatus($pdo, $orderParam);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

// Order Activities Timeline: /api/orders/{id}/activities or /api/order/{id}/activities
if (preg_match('#^/api/orders?/([^/]+)/activities$#', $requestUri, $matches)) {
    $orderParam = trim($matches[1]);
    if ($method === 'GET') {
        apiGetOrderActivities($pdo, $orderParam);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

// Order Tracking: /api/orders/{id}/tracking or /api/order/{id}/tracking
if (preg_match('#^/api/orders?/([^/]+)/tracking$#', $requestUri, $matches)) {
    $orderParam = trim($matches[1]);
    if ($method === 'GET') {
        apiGetOrderTracking($pdo, $orderParam);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

// Order Cancellation: /api/orders/{id}/cancel, /api/orders/{id}/cancellation-request, /api/orders/{id}/request-cancellation
if (preg_match('#^/api/orders?/([^/]+)/(?:cancel|cancellation-request|request-cancellation)$#', $requestUri, $matches)) {
    $orderParam = trim($matches[1]);
    if ($method === 'POST' || $method === 'PUT') {
        apiCancelOrder($pdo, $orderParam);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

// Order Cancellation Approval/Rejection: /api/orders/{id}/cancellation/(approve|reject)
if (preg_match('#^/api/orders?/([^/]+)/cancellation/(approve|reject)$#', $requestUri, $matches)) {
    $orderParam = trim($matches[1]);
    $act = strtolower(trim($matches[2]));
    if ($method !== 'POST' && $method !== 'PUT') {
        errorResponse('Method Not Allowed', [], 405);
    }
    if ($act === 'approve') {
        apiApproveCancellation($pdo, $orderParam);
    } else {
        apiRejectCancellation($pdo, $orderParam);
    }
}

// Order Return: /api/orders/{id}/return, /api/orders/{id}/request-return
if (preg_match('#^/api/orders?/([^/]+)/(?:return|request-return)$#', $requestUri, $matches)) {
    $orderParam = trim($matches[1]);
    if ($method === 'POST' || $method === 'PUT') {
        apiReturnOrder($pdo, $orderParam);
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
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

// Payment capture verification & Customer Dispute creation on an order
if (preg_match('#^/api/orders?/([^/]+)/payment-verify$#', $requestUri, $matches)) {
    if ($method === 'POST') {
        apiRazorpayVerify($pdo, trim($matches[1]));
    } else {
        errorResponse('Method Not Allowed', [], 405);
    }
}

if (preg_match('#^/api/orders?/([^/]+)/disputes$#', $requestUri, $matches)) {
    if ($method === 'POST') {
        apiOrderCreateDispute($pdo, trim($matches[1]));
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
