<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\SellerPaymentController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json(['status' => 'ok', 'app' => config('app.name')]);
});

Route::match(['get', 'head'], '/register', function () {
    return response()->json([
        'message' => 'The register endpoint only accepts POST requests with seller details (seller_name, phone, address, landmark, state, country, password, password_confirmation).',
    ], 405);
});
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');

Route::match(['get', 'head'], '/login', function () {
    return response()->json([
        'message' => 'The login endpoint only accepts POST requests with credentials (phone, password).',
    ], 405);
});
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:3,10');
Route::post('/verify-otp', [AuthController::class, 'verifyOtp'])->middleware('throttle:5,10');
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,10');

Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{id}', [ProductController::class, 'show']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::post('/products', [ProductController::class, 'store']);
    Route::match(['put', 'patch'], '/products/{id}', [ProductController::class, 'update']);
    Route::delete('/products/{id}', [ProductController::class, 'destroy']);

    Route::post('/seller/bank-account/onboarding', [SellerPaymentController::class, 'saveOnboardingReference']);
    Route::get('/seller/bank-account/status', [SellerPaymentController::class, 'status']);
    Route::get('/seller/payouts', [SellerPaymentController::class, 'payouts']);
    Route::post('/marketplace/commission-preview', [SellerPaymentController::class, 'commissionPreview']);
});
