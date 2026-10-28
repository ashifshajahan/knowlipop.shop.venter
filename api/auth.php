<?php

/**
 * Pure PHP API: Authentication Controller
 */

require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';

function apiRegister(PDO $pdo): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'GET' || $_SERVER['REQUEST_METHOD'] === 'HEAD') {
        errorResponse('The register endpoint only accepts POST requests with seller details (seller_name, phone, address, landmark, state, country, password, password_confirmation).', [], 405);
    }

    $input = getJsonInput();
    $errors = [];

    if (empty($input['seller_name'])) {
        $errors['seller_name'] = ['The seller name field is required.'];
    }
    if (empty($input['phone'])) {
        $errors['phone'] = ['The phone field is required.'];
    } elseif (!preg_match('/^\+[1-9]\d{7,14}$/', $input['phone'])) {
        $errors['phone'] = ['The phone format is invalid. Must include country code, e.g. +919876543210.'];
    }
    if (empty($input['address'])) {
        $errors['address'] = ['The address field is required.'];
    }
    if (empty($input['landmark'])) {
        $errors['landmark'] = ['The landmark field is required.'];
    }
    if (empty($input['state'])) {
        $errors['state'] = ['The state field is required.'];
    }
    if (empty($input['country'])) {
        $errors['country'] = ['The country field is required.'];
    }
    if (empty($input['password'])) {
        $errors['password'] = ['The password field is required.'];
    } elseif (strlen($input['password']) < 8) {
        $errors['password'] = ['The password must be at least 8 characters.'];
    } elseif (!isset($input['password_confirmation']) || $input['password'] !== $input['password_confirmation']) {
        $errors['password'] = ['The password field confirmation does not match.'];
    }

    if (!empty($errors)) {
        errorResponse('Validation error occurred.', $errors, 422);
    }

    // Check duplicate phone
    $checkStmt = $pdo->prepare("SELECT id FROM users WHERE phone = :phone LIMIT 1");
    $checkStmt->execute([':phone' => $input['phone']]);
    if ($checkStmt->fetch()) {
        errorResponse('The phone has already been taken.', ['phone' => ['The phone has already been taken.']], 422);
    }

    $phoneDigits = preg_replace('/\D+/', '', $input['phone']);
    $email = 'seller_' . $phoneDigits . '@phone.vendor.local';
    $hashedPassword = hashPassword($input['password']);

    $stmt = $pdo->prepare("
        INSERT INTO users (name, email, seller_name, phone, address, landmark, state, country, password, role, created_at, updated_at)
        VALUES (:name, :email, :seller_name, :phone, :address, :landmark, :state, :country, :password, 'seller', NOW(), NOW())
    ");
    $stmt->execute([
        ':name' => $input['seller_name'],
        ':email' => $email,
        ':seller_name' => $input['seller_name'],
        ':phone' => $input['phone'],
        ':address' => $input['address'],
        ':landmark' => $input['landmark'],
        ':state' => $input['state'],
        ':country' => $input['country'],
        ':password' => $hashedPassword,
    ]);

    $userId = (int) $pdo->lastInsertId();
    $token = createToken($pdo, $userId);

    $fetchStmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
    $fetchStmt->execute([':id' => $userId]);
    $user = $fetchStmt->fetch();

    jsonResponse([
        'message' => 'Seller registration successful.',
        'user' => safeSeller($user),
        'token' => $token,
        'token_type' => 'Bearer',
    ], 201);
}

function apiLogin(PDO $pdo): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'GET' || $_SERVER['REQUEST_METHOD'] === 'HEAD') {
        errorResponse('The login endpoint only accepts POST requests with credentials (phone, password).', [], 405);
    }

    $input = getJsonInput();
    $errors = [];

    if (empty($input['phone'])) {
        $errors['phone'] = ['The phone field is required.'];
    }
    if (empty($input['password'])) {
        $errors['password'] = ['The password field is required.'];
    }

    if (!empty($errors)) {
        errorResponse('Validation error occurred.', $errors, 422);
    }

    $stmt = $pdo->prepare("SELECT * FROM users WHERE phone = :phone LIMIT 1");
    $stmt->execute([':phone' => $input['phone']]);
    $user = $stmt->fetch();

    if (!$user || !verifyPassword($input['password'], $user['password'])) {
        errorResponse('The supplied phone number or password is incorrect.', [
            'phone' => ['The supplied phone number or password is incorrect.']
        ], 422);
    }

    $token = createToken($pdo, (int) $user['id']);

    jsonResponse([
        'message' => 'Login successful.',
        'user' => safeSeller($user),
        'token' => $token,
        'token_type' => 'Bearer',
    ]);
}

function apiMe(PDO $pdo): void
{
    $user = authenticateUser($pdo);
    jsonResponse(['user' => safeSeller($user)]);
}

function apiLogout(PDO $pdo): void
{
    $user = authenticateUser($pdo);
    deleteCurrentToken($pdo, (int) $user['token_id']);
    jsonResponse(['message' => 'Logout successful.']);
}

function apiForgotPassword(PDO $pdo): void
{
    $input = getJsonInput();
    if (empty($input['phone'])) {
        errorResponse('The phone field is required.', ['phone' => ['The phone field is required.']], 422);
    }

    $phone = $input['phone'];
    $stmt = $pdo->prepare("SELECT id FROM users WHERE phone = :phone LIMIT 1");
    $stmt->execute([':phone' => $phone]);
    $user = $stmt->fetch();

    $otp = null;
    if ($user) {
        $otp = sprintf('%06d', mt_rand(100000, 999999));
        $hashedOtp = hashPassword($otp);

        $pdo->prepare("DELETE FROM password_reset_otps WHERE phone = :phone")->execute([':phone' => $phone]);
        $ins = $pdo->prepare("INSERT INTO password_reset_otps (phone, token, created_at) VALUES (:phone, :token, NOW())");
        $ins->execute([':phone' => $phone, ':token' => $hashedOtp]);
    }

    $response = ['message' => 'If this phone number is registered, an OTP will be sent.'];
    if ($otp !== null) {
        $response['dev_otp'] = $otp; // Included for Postman & frontend developer testing
    }
    jsonResponse($response);
}

function apiVerifyOtp(PDO $pdo): void
{
    $input = getJsonInput();
    if (empty($input['phone']) || empty($input['code'])) {
        errorResponse('Validation error', ['phone' => ['Phone and OTP code are required.']], 422);
    }

    $stmt = $pdo->prepare("SELECT * FROM password_reset_otps WHERE phone = :phone ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([':phone' => $input['phone']]);
    $row = $stmt->fetch();

    if (!$row || !verifyPassword($input['code'], $row['token'])) {
        errorResponse('Invalid or expired OTP code.', ['code' => ['Invalid or expired OTP code.']], 422);
    }

    $resetToken = bin2hex(random_bytes(32));
    $hashedResetToken = hashPassword($resetToken);

    $upd = $pdo->prepare("UPDATE password_reset_otps SET token = :token WHERE phone = :phone");
    $upd->execute([':token' => $hashedResetToken, ':phone' => $input['phone']]);

    jsonResponse([
        'message' => 'OTP verified successfully.',
        'reset_token' => $resetToken
    ]);
}

function apiResetPassword(PDO $pdo): void
{
    $input = getJsonInput();
    if (empty($input['phone']) || empty($input['reset_token']) || empty($input['password'])) {
        errorResponse('All fields are required.', [], 422);
    }

    $stmt = $pdo->prepare("SELECT * FROM password_reset_otps WHERE phone = :phone ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([':phone' => $input['phone']]);
    $row = $stmt->fetch();

    if (!$row || !verifyPassword($input['reset_token'], $row['token'])) {
        errorResponse('The reset token is invalid or expired. Verify your OTP again.', [], 422);
    }

    $hashed = hashPassword($input['password']);
    $upd = $pdo->prepare("UPDATE users SET password = :password, updated_at = NOW() WHERE phone = :phone");
    $upd->execute([':password' => $hashed, ':phone' => $input['phone']]);

    // Delete token and revoke tokens
    $pdo->prepare("DELETE FROM password_reset_otps WHERE phone = :phone")->execute([':phone' => $input['phone']]);

    jsonResponse(['message' => 'Password reset successful. Please log in again.']);
}
