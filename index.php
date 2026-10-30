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

// Static uploaded files handler (/uploads/products/...)
if (str_starts_with($requestUri, '/uploads/')) {
    $filePath = __DIR__ . $requestUri;
    if (file_exists($filePath) && is_file($filePath)) {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mimes = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png', 'webp' => 'image/webp',
            'gif' => 'image/gif', 'svg' => 'image/svg+xml'
        ];
        header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
        header('Cache-Control: public, max-age=86400');
        readfile($filePath);
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
            <a href="/admin/login" class="btn">Go to Admin Dashboard</a>
            <div class="endpoints">
                <strong>Available Endpoints:</strong><br>
                • GET /api/health<br>
                • POST /api/register<br>
                • POST /api/login<br>
                • GET /api/seller/profile<br>
                • PUT /api/seller/profile<br>
                • GET /api/sellers/{id}<br>
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

// Product detail / update / delete: /api/products/{id}
if (preg_match('#^/api/products/(\d+)$#', $requestUri, $matches)) {
    $productId = (int) $matches[1];
    if ($method === 'GET') {
        apiGetProduct($pdo, $productId);
    } elseif ($method === 'PUT' || $method === 'PATCH') {
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

// Route Not Found fallback
errorResponse('Route not found.', [], 404);
