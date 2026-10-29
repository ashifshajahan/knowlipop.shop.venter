<?php

/**
 * Pure PHP API: Authentication Controller
 */

require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';

function extractFieldPhp(array $input, array $candidates, string $default = ''): string
{
    foreach ($candidates as $c) {
        if (isset($input[$c]) && trim((string)$input[$c]) !== '') {
            return trim((string)$input[$c]);
        }
    }
    // Case-insensitive search
    foreach ($candidates as $c) {
        $clean = strtolower(preg_replace('/[^a-z0-9]/', '', $c));
        foreach ($input as $k => $v) {
            if (strtolower(preg_replace('/[^a-z0-9]/', '', (string)$k)) === $clean && trim((string)$v) !== '') {
                return trim((string)$v);
            }
        }
    }
    return $default;
}

function extractPhonePhp(array $input): string
{
    // Object / array check
    foreach (['phone', 'mobile', 'contact', 'telephone'] as $k) {
        if (isset($input[$k]) && is_array($input[$k])) {
            $num = $input[$k]['number'] ?? $input[$k]['phone'] ?? $input[$k]['value'] ?? '';
            $code = $input[$k]['country_code'] ?? $input[$k]['countryCode'] ?? $input[$k]['dial_code'] ?? $input[$k]['dialCode'] ?? '+91';
            if ($num !== '') {
                return (str_starts_with((string)$num, '+') ? '' : $code) . (string)$num;
            }
        }
    }

    $candidates = [
        'phone', 'phone_no', 'phoneNo', 'phoneno', 'phone_number', 'phoneNumber', 'phonenumber',
        'mobile', 'mobile_no', 'mobileNo', 'mobileno', 'mobile_number', 'mobileNumber',
        'contact', 'contact_no', 'contactNo', 'contactno', 'contact_number', 'contactNumber',
        'seller_phone', 'sellerPhone', 'seller_mobile', 'sellerMobile',
        'user_phone', 'userPhone', 'user_mobile', 'userMobile',
        'telephone', 'tel', 'cell', 'cellphone'
    ];

    $raw = extractFieldPhp($input, $candidates, '');
    if ($raw === '') {
        foreach ($input as $k => $v) {
            if (preg_match('/^(seller|user)?_?(phone|mobile|contact|tel)/i', (string)$k)) {
                if (trim((string)$v) !== '') {
                    $raw = trim((string)$v);
                    break;
                }
            }
        }
    }

    if ($raw === '') return '';

    $dialCode = $input['country_code'] ?? $input['countryCode'] ?? $input['dial_code'] ?? $input['dialCode'] ?? null;
    if ($dialCode && !str_starts_with($raw, '+')) {
        $raw = trim((string)$dialCode) . $raw;
    }

    return $raw;
}

function normalizePhonePhp(string $raw): string
{
    if (!$raw) return '';
    $str = preg_replace('/[\s\-\(\)]/', '', trim($raw));
    if (!$str) return '';
    if (str_starts_with($str, '+')) return $str;
    if (preg_match('/^\d{10}$/', $str)) return '+91' . $str;
    if (preg_match('/^91\d{10}$/', $str)) return '+' . $str;
    return '+' . $str;
}

function extractPasswordPhp(array $input): string
{
    return trim(extractFieldPhp($input, ['password', 'pass', 'user_password', 'userPassword', 'pwd']));
}

function extractConfirmPasswordPhp(array $input, string $password): string
{
    $candidates = [
        'password_confirmation', 'confirm_password', 'confirmPassword', 'confirmpassword',
        'passwordConfirmation', 'password_confirm', 'passwordConfirm', 'confirm_pass',
        'c_password', 'cpassword', 'repeat_password', 'repeatPassword',
        're_password', 'rePassword', 'password2', 'pass2', 'confirm'
    ];
    $val = trim(extractFieldPhp($input, $candidates));
    if ($val === '') {
        return $password;
    }
    return $val;
}

function apiRegister(PDO $pdo): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'GET' || $_SERVER['REQUEST_METHOD'] === 'HEAD') {
        errorResponse('The register endpoint only accepts POST requests with seller details (seller_name, phone, address, landmark, state, country, password, password_confirmation).', [], 405);
    }

    $input = getJsonInput();
    $sellerName = extractFieldPhp($input, [
        'seller_name', 'sellerName', 'name', 'business_name', 'businessName',
        'seller', 'shop_name', 'shopName', 'store_name', 'storeName',
        'fullname', 'full_name', 'user_name', 'userName'
    ]);
    $rawPhone = extractPhonePhp($input);
    $phone = normalizePhonePhp($rawPhone);
    $address = extractFieldPhp($input, ['address', 'shop_address', 'shopAddress', 'business_address', 'street', 'location']);
    $landmark = extractFieldPhp($input, ['landmark', 'land_mark', 'area', 'nearby'], 'None');
    $state = extractFieldPhp($input, ['state', 'province', 'region'], 'Kerala');
    $country = extractFieldPhp($input, ['country', 'nation'], 'India');
    $password = extractPasswordPhp($input);
    $confirmPassword = extractConfirmPasswordPhp($input, $password);

    $errors = [];

    if (empty($sellerName)) {
        $errors['seller_name'] = ['The seller name field is required.'];
    }
    if (empty($rawPhone)) {
        $errors['phone'] = ['The phone field is required.'];
    } elseif (!preg_match('/^\+[1-9]\d{7,14}$/', $phone)) {
        $errors['phone'] = ['The phone format is invalid (must include country code, e.g. +919876543210).'];
    }
    if (empty($address)) {
        $errors['address'] = ['The address field is required.'];
    }
    if (empty($password)) {
        $errors['password'] = ['The password field is required.'];
    } elseif (strlen($password) < 8) {
        $errors['password'] = ['The password must be at least 8 characters.'];
    } elseif ($password !== $confirmPassword) {
        $errors['password'] = ['The password confirmation does not match.'];
    }

    if (!empty($errors)) {
        errorResponse('Validation error occurred.', $errors, 422);
    }

    $rawNoPrefix = preg_replace('/^\+91|^\+/', '', $rawPhone);
    $checkStmt = $pdo->prepare("SELECT id, password FROM users WHERE phone = :phone OR phone = :rawPhone OR phone = :rawNoPrefix LIMIT 1");
    $checkStmt->execute([':phone' => $phone, ':rawPhone' => $rawPhone, ':rawNoPrefix' => $rawNoPrefix]);
    $existing = $checkStmt->fetch();
    if ($existing) {
        if (verifyPassword($password, $existing['password'])) {
            $token = createToken($pdo, (int)$existing['id'], $phone);
            jsonResponse([
                'message' => 'Seller account already exists. Successfully signed in.',
                'user' => safeSeller([
                    'id' => $existing['id'],
                    'seller_name' => $sellerName,
                    'phone' => $phone,
                    'address' => $address,
                    'landmark' => $landmark,
                    'state' => $state,
                    'country' => $country
                ]),
                'token' => $token,
                'token_type' => 'Bearer'
            ], 200);
        }
        errorResponse('The phone has already been taken.', ['phone' => ['The phone has already been taken.']], 422);
    }

    $phoneDigits = preg_replace('/\D+/', '', $phone);
    $email = 'seller_' . $phoneDigits . '@phone.vendor.local';
    $hashedPassword = hashPassword($password);

    $stmt = $pdo->prepare("
        INSERT INTO users (name, email, seller_name, phone, address, landmark, state, country, password, role, created_at, updated_at)
        VALUES (:name, :email, :seller_name, :phone, :address, :landmark, :state, :country, :password, 'seller', NOW(), NOW())
    ");
    $stmt->execute([
        ':name' => $sellerName,
        ':email' => $email,
        ':seller_name' => $sellerName,
        ':phone' => $phone,
        ':address' => $address,
        ':landmark' => $landmark,
        ':state' => $state,
        ':country' => $country,
        ':password' => $hashedPassword,
    ]);

    $userId = (int) $pdo->lastInsertId();
    $token = createToken($pdo, $userId, $phone);

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
    $rawPhone = extractPhonePhp($input) ?: extractFieldPhp($input, ['email', 'username', 'user', 'login']);
    $phone = normalizePhonePhp($rawPhone);
    $password = extractPasswordPhp($input);
    $errors = [];

    if (empty($rawPhone)) {
        $errors['phone'] = ['The phone field is required.'];
    }
    if (empty($password)) {
        $errors['password'] = ['The password field is required.'];
    }

    if (!empty($errors)) {
        errorResponse('Validation error occurred.', $errors, 422);
    }

    $rawNoPrefix = preg_replace('/^\+91|^\+/', '', $rawPhone);
    $stmt = $pdo->prepare("SELECT * FROM users WHERE phone = :phone OR phone = :rawPhone OR phone = :rawNoPrefix OR email = :rawEmail LIMIT 1");
    $stmt->execute([':phone' => $phone, ':rawPhone' => $rawPhone, ':rawNoPrefix' => $rawNoPrefix, ':rawEmail' => $rawPhone]);
    $user = $stmt->fetch();

    if (!$user || !verifyPassword($password, $user['password'])) {
        errorResponse('The supplied phone number or password is incorrect.', [
            'phone' => ['The supplied phone number or password is incorrect.']
        ], 422);
    }

    $token = createToken($pdo, (int) $user['id'], $user['phone'] ?? '');

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
    $rawPhone = extractPhonePhp($input);
    if (empty($rawPhone)) {
        errorResponse('The phone field is required.', ['phone' => ['The phone field is required.']], 422);
    }

    $phone = normalizePhonePhp($rawPhone);
    $rawNoPrefix = preg_replace('/^\+91|^\+/', '', $rawPhone);

    $stmt = $pdo->prepare("SELECT id, phone FROM users WHERE phone = :phone OR phone = :rawPhone OR phone = :rawNoPrefix LIMIT 1");
    $stmt->execute([':phone' => $phone, ':rawPhone' => $rawPhone, ':rawNoPrefix' => $rawNoPrefix]);
    $user = $stmt->fetch();

    $otp = null;
    $smsResult = null;
    if ($user) {
        $targetPhone = $user['phone'] ?? $phone;
        $otp = isFirebaseTestPhone($targetPhone) ? getFirebaseTestOtp() : sprintf('%06d', mt_rand(100000, 999999));
        $hashedOtp = hashPassword($otp);

        $pdo->prepare("DELETE FROM password_reset_otps WHERE phone = :phone OR phone = :rawPhone OR phone = :rawNoPrefix")
            ->execute([':phone' => $targetPhone, ':rawPhone' => $rawPhone, ':rawNoPrefix' => $rawNoPrefix]);
        $ins = $pdo->prepare("INSERT INTO password_reset_otps (phone, token, created_at) VALUES (:phone, :token, NOW())");
        $ins->execute([':phone' => $targetPhone, ':token' => $hashedOtp]);

        // Dispatch SMS via configured gateway (Firebase / Fast2SMS) or return mock in dev
        $recaptchaToken = extractFieldPhp($input, ['recaptcha_token', 'recaptchaToken', 'recaptcha']);
        $smsResult = sendOtpSms($targetPhone, $otp, $recaptchaToken);
    }

    $response = ['message' => 'If this phone number is registered, an OTP will be sent.'];
    if ($otp !== null) {
        $response['dev_otp'] = $otp; // Included for Postman & frontend developer testing
        if ($smsResult && isset($smsResult['provider'])) {
            $response['sms_status'] = $smsResult['sent'] ? 'dispatched' : 'mock_mode';
            $response['gateway'] = $smsResult['provider'];
            if (!empty($smsResult['session_info'])) {
                $response['session_info'] = $smsResult['session_info'];
            }
            if (!$smsResult['sent'] && isset($smsResult['reason'])) {
                $response['sms_note'] = $smsResult['reason'];
            }
        }
    }
    jsonResponse($response);
}

function apiVerifyOtp(PDO $pdo): void
{
    $input = getJsonInput();
    $rawPhone = extractPhonePhp($input);
    $code = trim(extractFieldPhp($input, ['code', 'otp', 'otp_code', 'verification_code']));
    $sessionInfo = trim(extractFieldPhp($input, ['session_info', 'sessionInfo', 'session']));
    $idToken = trim(extractFieldPhp($input, ['id_token', 'idToken', 'firebase_token']));

    if (empty($rawPhone) || (empty($code) && empty($idToken))) {
        errorResponse('Validation error', ['phone' => ['Phone and OTP code (or Firebase ID Token) are required.']], 422);
    }

    $phone = normalizePhonePhp($rawPhone);
    $rawNoPrefix = preg_replace('/^\+91|^\+/', '', $rawPhone);

    $stmt = $pdo->prepare("SELECT * FROM password_reset_otps WHERE phone = :phone OR phone = :rawPhone OR phone = :rawNoPrefix ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([':phone' => $phone, ':rawPhone' => $rawPhone, ':rawNoPrefix' => $rawNoPrefix]);
    $row = $stmt->fetch();

    $firebaseApproved = verifyFirebaseOtp($phone, $code, $sessionInfo, $idToken);
    $localValid = $row && !empty($code) && verifyPassword($code, $row['token']);

    if (!$firebaseApproved && !$localValid) {
        errorResponse('Invalid or expired OTP code.', ['code' => ['Invalid or expired OTP code.']], 422);
    }

    $resetToken = bin2hex(random_bytes(32));
    $hashedResetToken = hashPassword($resetToken);
    $targetPhone = $row['phone'] ?? $phone;

    $pdo->prepare("DELETE FROM password_reset_otps WHERE phone = :phone OR phone = :rawPhone OR phone = :rawNoPrefix")
        ->execute([':phone' => $targetPhone, ':rawPhone' => $rawPhone, ':rawNoPrefix' => $rawNoPrefix]);
    $ins = $pdo->prepare("INSERT INTO password_reset_otps (phone, token, created_at) VALUES (:phone, :token, NOW())");
    $ins->execute([':phone' => $targetPhone, ':token' => $hashedResetToken]);

    jsonResponse([
        'message' => 'OTP verified successfully.',
        'reset_token' => $resetToken
    ]);
}

function apiResetPassword(PDO $pdo): void
{
    $input = getJsonInput();
    $rawPhone = extractPhonePhp($input);
    $resetToken = trim(extractFieldPhp($input, ['reset_token', 'resetToken', 'token']));
    $password = extractPasswordPhp($input);
    $confirmPassword = extractConfirmPasswordPhp($input, $password);

    $errors = [];
    if (empty($rawPhone)) {
        $errors['phone'] = ['The phone field is required.'];
    }
    if (empty($resetToken)) {
        $errors['reset_token'] = ['The reset token field is required.'];
    }
    if (empty($password)) {
        $errors['password'] = ['The password field is required.'];
    } elseif (strlen($password) < 8) {
        $errors['password'] = ['The password must be at least 8 characters.'];
    } elseif ($password !== $confirmPassword) {
        $errors['password'] = ['The password confirmation does not match.'];
    }

    if (!empty($errors)) {
        errorResponse('Validation error occurred.', $errors, 422);
    }

    $phone = normalizePhonePhp($rawPhone);
    $rawNoPrefix = preg_replace('/^\+91|^\+/', '', $rawPhone);

    $stmt = $pdo->prepare("SELECT * FROM password_reset_otps WHERE phone = :phone OR phone = :rawPhone OR phone = :rawNoPrefix ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([':phone' => $phone, ':rawPhone' => $rawPhone, ':rawNoPrefix' => $rawNoPrefix]);
    $row = $stmt->fetch();

    if (!$row || !verifyPassword($resetToken, $row['token'])) {
        errorResponse('The reset token is invalid or expired. Verify your OTP again.', [], 422);
    }

    $targetPhone = $row['phone'] ?? $phone;
    $hashed = hashPassword($password);

    $upd = $pdo->prepare("UPDATE users SET password = :password, updated_at = NOW() WHERE phone = :targetPhone OR phone = :phone OR phone = :rawPhone OR phone = :rawNoPrefix");
    $upd->execute([
        ':password' => $hashed,
        ':targetPhone' => $targetPhone,
        ':phone' => $phone,
        ':rawPhone' => $rawPhone,
        ':rawNoPrefix' => $rawNoPrefix
    ]);

    // Delete token after successful password reset
    $pdo->prepare("DELETE FROM password_reset_otps WHERE phone = :targetPhone OR phone = :phone OR phone = :rawPhone OR phone = :rawNoPrefix")
        ->execute([
            ':targetPhone' => $targetPhone,
            ':phone' => $phone,
            ':rawPhone' => $rawPhone,
            ':rawNoPrefix' => $rawNoPrefix
        ]);

    jsonResponse(['message' => 'Password reset successful. Please log in again.']);
}

