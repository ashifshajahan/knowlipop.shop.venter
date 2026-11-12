<?php

/**
 * Pure PHP API: Authentication Controller
 */

require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/seller_deletion.php';

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

function extractDialCodePhp(array $input): string
{
    foreach (['country_code', 'countryCode', 'dial_code', 'dialCode'] as $k) {
        if (isset($input[$k]) && !is_array($input[$k]) && trim((string) $input[$k]) !== '') {
            return trim((string) $input[$k]);
        }
    }
    foreach (['phone', 'mobile', 'contact', 'telephone'] as $k) {
        if (isset($input[$k]) && is_array($input[$k])) {
            $code = $input[$k]['country_code'] ?? $input[$k]['countryCode'] ?? $input[$k]['dial_code'] ?? $input[$k]['dialCode'] ?? '';
            if (trim((string) $code) !== '') {
                return trim((string) $code);
            }
        }
    }
    return '';
}

function extractPhonePartsPhp(array $input): array
{
    // Object / array check
    foreach (['phone', 'mobile', 'contact', 'telephone'] as $k) {
        if (isset($input[$k]) && is_array($input[$k])) {
            $num = trim((string) ($input[$k]['number'] ?? $input[$k]['phone'] ?? $input[$k]['value'] ?? ''));
            $code = trim((string) ($input[$k]['country_code'] ?? $input[$k]['countryCode'] ?? $input[$k]['dial_code'] ?? $input[$k]['dialCode'] ?? '+91'));
            if ($num !== '') {
                return ['rawPhone' => $num, 'dialCode' => str_starts_with($num, '+') ? '' : $code];
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
            if (
                preg_match('/^(seller|user)?_?(phone|mobile|contact|tel)/i', (string)$k)
                && !preg_match('/^contact_?(id|email|type|name|person)/i', (string)$k)
            ) {
                if (!is_array($v) && !is_object($v) && trim((string)$v) !== '') {
                    $raw = trim((string)$v);
                    break;
                }
            }
        }
    }

    if ($raw === '') {
        return ['rawPhone' => '', 'dialCode' => ''];
    }

    $dialCode = extractDialCodePhp($input);
    return ['rawPhone' => $raw, 'dialCode' => $dialCode];
}

function extractPhonePhp(array $input): string
{
    $parts = extractPhonePartsPhp($input);
    if ($parts['rawPhone'] === '') {
        return '';
    }
    $norm = normalizePhoneNumberPhp($parts['rawPhone'], $parts['dialCode']);
    return $norm !== '' ? $norm : $parts['rawPhone'];
}

function normalizePhonePhp(string $raw, string $dialCode = ''): string
{
    return normalizePhoneNumberPhp($raw, $dialCode);
}

function isValidEmailAddressPhp(string $email): bool
{
    $trimmed = strtolower(trim($email));
    if ($trimmed === '' || str_ends_with($trimmed, '@phone.vendor.local')) {
        return false;
    }
    return filter_var($trimmed, FILTER_VALIDATE_EMAIL) !== false;
}

function makeSyntheticPhoneEmailPhp(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone);
    if ($digits === '' || $digits === null) {
        $digits = (string) time();
    }
    return "seller_{$digits}@phone.vendor.local";
}

function extractAuthIdentifiersPhp(array $input, bool $allowUsername = false): array
{
    $rawEmail = extractFieldPhp($input, [
        'email', 'email_address', 'emailAddress', 'email_id', 'emailId',
        'user_email', 'seller_email', 'business_email', 'mail'
    ], '');
    $phoneParts = extractPhonePartsPhp($input);
    $rawPhone = $phoneParts['rawPhone'];
    $dialCode = $phoneParts['dialCode'];
    if ($dialCode === '') {
        $dialCode = extractDialCodePhp($input);
    }

    $singleCandidates = [
        'identifier', 'login', 'login_id', 'loginId',
        'email_or_phone', 'emailOrPhone', 'phone_or_email', 'phoneOrEmail',
        'contact_id', 'contactId', 'user_identifier', 'userIdentifier',
        'account', 'credential', 'auth_id', 'authId'
    ];
    if ($allowUsername) {
        $singleCandidates[] = 'username';
        $singleCandidates[] = 'user_name';
        $singleCandidates[] = 'userName';
    } elseif ($rawEmail === '' && $rawPhone === '') {
        // Allow username on register only when explicit contact fields were omitted
        $singleCandidates[] = 'username';
    }

    $rawIdentifier = extractFieldPhp($input, $singleCandidates, '');
    $identifierError = '';

    if ($rawEmail === '' && $rawPhone === '' && $rawIdentifier !== '') {
        if (str_contains($rawIdentifier, '@')) {
            $rawEmail = $rawIdentifier;
        } elseif (preg_match('/^\+?[\d\s\-().]+$/', $rawIdentifier)) {
            $rawPhone = $rawIdentifier;
        } else {
            $identifierError = 'Please provide a valid email address or phone number.';
        }
    } 

    // Handle single-input forms wired only to `email` or `phone`
    if ($rawEmail === '' && $rawPhone !== '' && str_contains($rawPhone, '@')) {
        $rawEmail = $rawPhone;
        $rawPhone = '';
    } elseif ($rawPhone === '' && $rawEmail !== '' && !str_contains($rawEmail, '@') && preg_match('/^\+?[\d\s\-().]{7,20}$/', $rawEmail)) {
        $rawPhone = $rawEmail;
        $rawEmail = '';
    }

    $email = $rawEmail !== '' ? strtolower(trim($rawEmail)) : '';
    $phone = $rawPhone !== '' ? normalizePhoneNumberPhp($rawPhone, $dialCode) : '';

    return [
        'rawEmail' => $rawEmail,
        'email' => $email,
        'rawPhone' => $rawPhone,
        'dialCode' => $dialCode,
        'phone' => $phone,
        'rawIdentifier' => $rawIdentifier,
        'identifierError' => $identifierError,
    ];
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
        errorResponse('The register endpoint only accepts POST requests with seller details (seller_name, email or phone, address, landmark, state, country, password, password_confirmation).', [], 405);
    }

    $input = getJsonInput();
    $sellerName = extractFieldPhp($input, [
        'seller_name', 'sellerName', 'name', 'business_name', 'businessName',
        'seller', 'shop_name', 'shopName', 'store_name', 'storeName',
        'fullname', 'full_name', 'user_name', 'userName'
    ]);
    $ids = extractAuthIdentifiersPhp($input, false);
    $email = $ids['email'];
    $rawPhone = $ids['rawPhone'];
    $dialCode = $ids['dialCode'] ?? '';
    $phone = $ids['phone'];
    $identifierError = $ids['identifierError'];

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
    if ($identifierError !== '') {
        $errors['email_or_phone'] = [$identifierError];
    } elseif ($email === '' && $rawPhone === '') {
        $errors['email_or_phone'] = ['Please provide an email address or phone number.'];
    } else {
        if ($email !== '' && !isValidEmailAddressPhp($email)) {
            $errors['email'] = ['The email address format is invalid.'];
        }
        if ($rawPhone !== '') {
            $phoneErr = validatePhoneFormatPhp($phone, $rawPhone);
            if ($phoneErr !== null) {
                $errors['phone'] = [$phoneErr];
            }
        }
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
        $firstMsg = reset($errors)[0] ?? 'Validation error occurred.';
        errorResponse(
            count($errors) === 1 ? $firstMsg : 'Validation error occurred.',
            $errors,
            422,
            ['status' => false, 'error_code' => 'VALIDATION_ERROR']
        );
    }

    // Ensure normalized_phone column & unique index BEFORE starting transaction (DDL causes implicit commit in MySQL)
    ensureNormalizedPhoneSchemaPhp($pdo);

    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    $isSqlite = ($driver === 'sqlite');
    $hasNormCol = authUsersHasColumnPhp($pdo, 'normalized_phone');
    $hashedPassword = hashPassword($password);
    $dbEmail = $email !== '' ? $email : makeSyntheticPhoneEmailPhp($phone);
    $dbPhone = $phone !== '' ? $phone : null;

    $outcome = null;
    $maxAttempts = $isSqlite ? 3 : 1;

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $mysqlLockKey = null;
        $inTx = false;
        try {
            if (!$isSqlite && $phone !== '') {
                $mysqlLockKey = 'venter_reg_' . sha1($phone);
                $lockStmt = $pdo->prepare('SELECT GET_LOCK(:k, 5)');
                $lockStmt->execute([':k' => $mysqlLockKey]);
            }

            if ($isSqlite) {
                $pdo->exec('BEGIN IMMEDIATE');
                $inTx = true;
            } else {
                $pdo->beginTransaction();
                $inTx = true;
            }

            $phoneMatches = $phone !== '' ? findUsersByPhonePhp($pdo, $rawPhone, $phone, $dialCode) : [];
            $emailMatch = null;
            if ($email !== '') {
                $emailSql = $isSqlite
                    ? 'SELECT * FROM users WHERE LOWER(email) = LOWER(:email) LIMIT 1'
                    : 'SELECT * FROM users WHERE LOWER(email) = LOWER(:email) LIMIT 1 FOR UPDATE';
                $emailStmt = $pdo->prepare($emailSql);
                $emailStmt->execute([':email' => $email]);
                $emailMatch = $emailStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }

            if (!empty($phoneMatches)) {
                $activePhoneMatch = null;
                $blockedPhoneMatch = null;
                foreach ($phoneMatches as $pm) {
                    $pmStatus = strtoupper((string) ($pm['account_status'] ?? 'ACTIVE'));
                    if (($pm['role'] ?? 'seller') !== 'admin' && in_array($pmStatus, ['LOCKED', 'DELETED'], true)) {
                        if ($blockedPhoneMatch === null) {
                            $blockedPhoneMatch = $pm;
                        }
                    } else {    
                        $activePhoneMatch = $pm;
                        break;
                    }
                }

                if ($activePhoneMatch !== null) {
                    $outcome = [
                        'ok' => false,
                        'status' => 409,
                        'message' => 'This phone number is already registered. Please log in.',
                        'errors' => [],
                        'extra' => ['status' => false, 'error_code' => 'PHONE_ALREADY_REGISTERED'],
                    ];
                    break;
                }

                if ($blockedPhoneMatch !== null) {
                    $pmStatus = strtoupper((string) ($blockedPhoneMatch['account_status'] ?? 'LOCKED'));
                    $outcome = [
                        'ok' => false,
                        'status' => 403,
                        'message' => $pmStatus === 'DELETED'
                            ? 'This seller account has been permanently deactivated and cannot be registered again.'
                            : 'This seller account is locked during deletion verification.',
                        'errors' => ['account_status' => [$pmStatus]],
                        'extra' => ['status' => false, 'error_code' => 'ACCOUNT_' . $pmStatus],
                    ];
                    break;
                }
            }

            if ($emailMatch) {
                $emStatus = strtoupper((string) ($emailMatch['account_status'] ?? 'ACTIVE'));
                if (($emailMatch['role'] ?? 'seller') !== 'admin' && in_array($emStatus, ['LOCKED', 'DELETED'], true)) {
                    $outcome = [
                        'ok' => false,
                        'status' => 403,
                        'message' => $emStatus === 'DELETED'
                            ? 'This seller account has been permanently deactivated.'
                            : 'This seller account is locked during deletion verification.',
                        'errors' => ['account_status' => [$emStatus]],
                        'extra' => ['status' => false, 'error_code' => 'ACCOUNT_' . $emStatus],
                    ];
                    break;
                }

                $outcome = [
                    'ok' => false,
                    'status' => 422,
                    'message' => 'The email has already been taken.',
                    'errors' => ['email' => ['The email has already been taken.']],
                    'extra' => ['status' => false, 'error_code' => 'EMAIL_ALREADY_REGISTERED'],
                ];
                break;
            }

            if ($hasNormCol) {
                $stmt = $pdo->prepare("
                    INSERT INTO users (name, email, seller_name, phone, normalized_phone, address, landmark, state, country, password, role, created_at, updated_at)
                    VALUES (:name, :email, :seller_name, :phone, :normalized_phone, :address, :landmark, :state, :country, :password, 'seller', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                ");
                $stmt->execute([
                    ':name' => $sellerName,
                    ':email' => $dbEmail,
                    ':seller_name' => $sellerName,
                    ':phone' => $dbPhone,
                    ':normalized_phone' => $dbPhone,
                    ':address' => $address,
                    ':landmark' => $landmark,
                    ':state' => $state,
                    ':country' => $country ?: 'India',
                    ':password' => $hashedPassword,
                ]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO users (name, email, seller_name, phone, address, landmark, state, country, password, role, created_at, updated_at)
                    VALUES (:name, :email, :seller_name, :phone, :address, :landmark, :state, :country, :password, 'seller', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                ");
                $stmt->execute([
                    ':name' => $sellerName,
                    ':email' => $dbEmail,
                    ':seller_name' => $sellerName,
                    ':phone' => $dbPhone,
                    ':address' => $address,
                    ':landmark' => $landmark,
                    ':state' => $state,
                    ':country' => $country ?: 'India',
                    ':password' => $hashedPassword,
                ]);
            }

            $userId = (int) $pdo->lastInsertId();
            if ($isSqlite) {
                $pdo->exec('COMMIT');
            } else {
                $pdo->commit();
            }
            $inTx = false;

            $fetchStmt = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
            $fetchStmt->execute([':id' => $userId]);
            $user = $fetchStmt->fetch() ?: [
                'id' => $userId,
                'seller_name' => $sellerName,
                'email' => $dbEmail,
                'phone' => $dbPhone,
                'normalized_phone' => $dbPhone,
                'address' => $address,
                'landmark' => $landmark,
                'state' => $state,
                'country' => $country ?: 'India',
                'role' => 'seller',
            ];

            $outcome = [
                'ok' => true,
                'userId' => $userId,
                'user' => $user,
            ];
            break;
        } catch (PDOException $e) {
            if ($inTx) {
                try {
                    if ($isSqlite) {
                        $pdo->exec('ROLLBACK');
                    } elseif ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                } catch (Throwable $ignore) {}
                $inTx = false;
            }

            $msgLower = strtolower($e->getMessage());
            if ($isSqlite && $attempt < $maxAttempts && (str_contains($msgLower, 'database is locked') || str_contains($msgLower, 'busy'))) {
                usleep(50000);
                continue;
            }

            if (isDuplicateKeyExceptionPhp($e)) {
                $isPhoneDup = false;
                if ($phone !== '') {
                    if (str_contains($msgLower, 'normalized_phone') || str_contains($msgLower, 'users.phone') || str_contains($msgLower, 'phone')) {
                        $isPhoneDup = true;
                    } else {
                        try {
                            $isPhoneDup = (bool) findUserByPhonePhp($pdo, $rawPhone, $phone, $dialCode);
                        } catch (Throwable $ignore) {}
                    }
                }
                if ($isPhoneDup) {
                    $outcome = [
                        'ok' => false,
                        'status' => 409,
                        'message' => 'This phone number is already registered. Please log in.',
                        'errors' => [],
                        'extra' => ['status' => false, 'error_code' => 'PHONE_ALREADY_REGISTERED'],
                    ];
                } else {
                    $outcome = [
                        'ok' => false,
                        'status' => 422,
                        'message' => 'The email has already been taken.',
                        'errors' => ['email' => ['The email has already been taken.']],
                        'extra' => ['status' => false, 'error_code' => 'EMAIL_ALREADY_REGISTERED'],
                    ];
                }
                break;
            }

            throw $e;
        } finally {
            if ($inTx) {
                try {
                    if ($isSqlite) {
                        $pdo->exec('ROLLBACK');
                    } elseif ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                } catch (Throwable $ignore) {}
            }
            if ($mysqlLockKey !== null) {
                try {
                    $relStmt = $pdo->prepare('SELECT RELEASE_LOCK(:k)');
                    $relStmt->execute([':k' => $mysqlLockKey]);
                } catch (Throwable $ignore) {}
            }
        }
    }

    if (!$outcome || empty($outcome['ok'])) {
        errorResponse(
            $outcome['message'] ?? 'Registration could not be completed.',
            $outcome['errors'] ?? [],
            (int) ($outcome['status'] ?? 409),
            $outcome['extra'] ?? ['status' => false, 'error_code' => 'REGISTRATION_FAILED']
        );
    }

    $userId = (int) $outcome['userId'];
    $user = $outcome['user'];
    $publicEmail = $email !== '' ? $email : null;
    $token = createToken($pdo, $userId, $dbPhone, 'vendor-app', $publicEmail, 'seller');

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
        errorResponse('The login endpoint only accepts POST requests with credentials (email or phone, and password).', [], 405);
    }

    $input = getJsonInput();
    $ids = extractAuthIdentifiersPhp($input, true);
    $email = $ids['email'];
    $rawPhone = $ids['rawPhone'];
    $dialCode = $ids['dialCode'] ?? '';
    $phone = $ids['phone'];
    $identifierError = $ids['identifierError'];
    $password = extractPasswordPhp($input);

    $errors = [];

    if ($identifierError !== '') {
        $errors['email_or_phone'] = [$identifierError];
    } elseif ($email === '' && $rawPhone === '') {
        $errors['email_or_phone'] = ['The email or phone field is required.'];
    } else {
        if ($email !== '' && !isValidEmailAddressPhp($email)) {
            $errors['email'] = ['The email address format is invalid.'];
        }
        if ($rawPhone !== '') {
            $phoneErr = validatePhoneFormatPhp($phone, $rawPhone);
            if ($phoneErr !== null) {
                $errors['phone'] = [$phoneErr];
            }
        }
    }
    if (empty($password)) {
        $errors['password'] = ['The password field is required.'];
    }

    if (!empty($errors)) {
        $firstMsg = reset($errors)[0] ?? 'Validation error occurred.';
        errorResponse(
            count($errors) === 1 ? $firstMsg : 'Validation error occurred.',
            $errors,
            422,
            ['status' => false, 'error_code' => 'VALIDATION_ERROR']
        );
    }

    ensureNormalizedPhoneSchemaPhp($pdo);

    $user = null;
    if ($email !== '' && $rawPhone !== '') {
        $emailStmt = $pdo->prepare("SELECT * FROM users WHERE LOWER(email) = LOWER(:email) LIMIT 1");
        $emailStmt->execute([':email' => $email]);
        $emailUser = $emailStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$emailUser && $email === 'admin@venter.app') {
            $adminStmt = $pdo->prepare("SELECT * FROM users WHERE LOWER(email) = 'admin@vendor.app' AND role = 'admin' LIMIT 1");
            $adminStmt->execute();
            $emailUser = $adminStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        $phoneUser = findUserByPhonePhp($pdo, $rawPhone, $phone, $dialCode);

        if (!$emailUser && !$phoneUser) {
            errorResponse(
                'This phone number is not registered. Please sign up.',
                [],
                404,
                ['status' => false, 'error_code' => 'PHONE_NOT_REGISTERED']
            );
        }
        if (!$emailUser || !$phoneUser || (int) $emailUser['id'] !== (int) $phoneUser['id']) {
            errorResponse(
                'The supplied email and phone number do not match the same account.',
                [],
                422,
                ['status' => false, 'error_code' => 'CREDENTIALS_MISMATCH']
            );
        }
        $user = $emailUser;
    } elseif ($email !== '') {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE LOWER(email) = LOWER(:email) LIMIT 1");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$user && $email === 'admin@venter.app') {
            $adminStmt = $pdo->prepare("SELECT * FROM users WHERE LOWER(email) = 'admin@vendor.app' AND role = 'admin' LIMIT 1");
            $adminStmt->execute();
            $user = $adminStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if (!$user) {
            errorResponse(
                'This email address is not registered. Please sign up.',
                [],
                404,
                ['status' => false, 'error_code' => 'EMAIL_NOT_REGISTERED']
            );
        }
    } else {
        $user = findUserByPhonePhp($pdo, $rawPhone, $phone, $dialCode);
        if (!$user) {
            errorResponse(
                'This phone number is not registered. Please sign up.',
                [],
                404,
                ['status' => false, 'error_code' => 'PHONE_NOT_REGISTERED']
            );
        }
    }

    if (!verifyPassword($password, (string) ($user['password'] ?? ''))) {
        errorResponse(
            'Incorrect password. Please try again.',
            [],
            401,
            ['status' => false, 'error_code' => 'INVALID_PASSWORD']
        );
    }

    $accStatus = strtoupper((string) ($user['account_status'] ?? 'ACTIVE'));
    if (($user['role'] ?? 'seller') !== 'admin' && in_array($accStatus, ['LOCKED', 'DELETED'], true)) {
        $msg = $accStatus === 'DELETED'
            ? 'This seller account has been permanently deactivated and can no longer sign in.'
            : 'This seller account is locked pending administrative account deletion.';
        errorResponse($msg, ['account_status' => [$accStatus]], 403, ['status' => false, 'error_code' => 'ACCOUNT_' . $accStatus]);
    }

    $role = $user['role'] ?? 'seller';
    $publicEmail = getPublicSellerEmailPhp($user['email'] ?? null, $role);
    $userPhone = isset($user['phone']) && trim((string)$user['phone']) !== '' ? trim((string)$user['phone']) : null;
    $token = createToken($pdo, (int) $user['id'], $userPhone, 'vendor-app', $publicEmail, $role, (int) ($user['token_version'] ?? 1));

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
    try {
        $pdo->prepare("UPDATE users SET token_version = COALESCE(token_version, 1) + 1, updated_at = CURRENT_TIMESTAMP WHERE id = :id")
            ->execute([':id' => (int) $user['id']]);
    } catch (Throwable $e) {
    }
    try {
        $pdo->prepare("DELETE FROM personal_access_tokens WHERE tokenable_id = :id")
            ->execute([':id' => (int) $user['id']]);
    } catch (Throwable $e) {
    }
    jsonResponse(['message' => 'Logout successful.']);
}

function ensurePasswordResetSchemaPhp(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    if ($driver === 'sqlite') {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS password_reset_otps (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                phone TEXT NULL,
                email TEXT NULL,
                channel TEXT NOT NULL DEFAULT 'phone',
                token TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $cols = $pdo->query("PRAGMA table_info(password_reset_otps)")->fetchAll(PDO::FETCH_ASSOC);
        $colNames = array_map(fn($c) => strtolower((string) ($c['name'] ?? '')), $cols);
        if (!in_array('email', $colNames, true)) {
            $pdo->exec("ALTER TABLE password_reset_otps ADD COLUMN email TEXT NULL");
        }
        if (!in_array('channel', $colNames, true)) {
            $pdo->exec("ALTER TABLE password_reset_otps ADD COLUMN channel TEXT NOT NULL DEFAULT 'phone'");
        }
        $ensured = true;
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `password_reset_otps` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `phone` VARCHAR(255) NULL DEFAULT NULL,
            `email` VARCHAR(255) NULL DEFAULT NULL,
            `channel` VARCHAR(20) NOT NULL DEFAULT 'phone',
            `token` VARCHAR(255) NOT NULL,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_otp_phone` (`phone`),
            INDEX `idx_otp_email` (`email`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $phoneCol = $pdo->query("SHOW COLUMNS FROM `password_reset_otps` LIKE 'phone'")->fetch(PDO::FETCH_ASSOC);
    if ($phoneCol && (($phoneCol['Null'] ?? '') !== 'YES' || preg_match('/varchar\(30\)/i', (string) ($phoneCol['Type'] ?? '')))) {
        $pdo->exec("ALTER TABLE `password_reset_otps` MODIFY COLUMN `phone` VARCHAR(255) NULL DEFAULT NULL");
    }

    $emailCol = $pdo->query("SHOW COLUMNS FROM `password_reset_otps` LIKE 'email'")->fetch(PDO::FETCH_ASSOC);
    if (!$emailCol) {
        $pdo->exec("ALTER TABLE `password_reset_otps` ADD COLUMN `email` VARCHAR(255) NULL DEFAULT NULL AFTER `phone`");
    }

    try {
        $emailIdx = $pdo->query("SHOW INDEX FROM `password_reset_otps` WHERE Key_name = 'idx_otp_email'")->fetch(PDO::FETCH_ASSOC);
        if (!$emailIdx) {
            $pdo->exec("ALTER TABLE `password_reset_otps` ADD INDEX `idx_otp_email` (`email`)");
        }
    } catch (Throwable $e) {
    }

    $channelCol = $pdo->query("SHOW COLUMNS FROM `password_reset_otps` LIKE 'channel'")->fetch(PDO::FETCH_ASSOC);
    if (!$channelCol) {
        $pdo->exec("ALTER TABLE `password_reset_otps` ADD COLUMN `channel` VARCHAR(20) NOT NULL DEFAULT 'phone' AFTER `email`");
    }

    $ensured = true;
}

function findPasswordResetUserPhp(PDO $pdo, string $email, string $rawPhone, string $phone, string $dialCode = ''): ?array
{
    ensureNormalizedPhoneSchemaPhp($pdo);

    if ($email !== '' && ($rawPhone !== '' || $phone !== '')) {
        $matches = findUsersByPhonePhp($pdo, $rawPhone, $phone, $dialCode);
        foreach ($matches as $m) {
            if (strtolower(trim((string) ($m['email'] ?? ''))) === strtolower(trim($email))) {
                return $m;
            }
        }
        return null;
    }
    if ($email !== '') {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE LOWER(email) = LOWER(:email) LIMIT 1");
        $stmt->execute([':email' => $email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row && strtolower(trim($email)) === 'admin@venter.app') {
            $adminStmt = $pdo->prepare("SELECT * FROM users WHERE LOWER(email) = 'admin@vendor.app' AND role = 'admin' LIMIT 1");
            $adminStmt->execute();
            $row = $adminStmt->fetch(PDO::FETCH_ASSOC);
        }
        return $row ?: null;
    }
    if ($rawPhone !== '' || $phone !== '') {
        return findUserByPhonePhp($pdo, $rawPhone, $phone, $dialCode);
    }
    return null;
}

function findLatestOtpRecordPhp(PDO $pdo, string $email, string $rawPhone, string $phone, string $dialCode = ''): ?array
{
    $variants = ($rawPhone !== '' || $phone !== '')
        ? getPhoneLookupVariantsPhp($rawPhone, $phone, $dialCode)
        : [];
    $normExpr = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(phone, ''), ' ', ''), '-', ''), '(', ''), ')', ''), '.', '')";

    if ($email !== '' && !empty($variants)) {
        $params = [':email1' => $email, ':email2' => $email];
        $ph1 = [];
        $ph2 = [];
        foreach ($variants as $idx => $v) {
            $ph1[] = ':pa' . $idx;
            $ph2[] = ':pb' . $idx;
            $params[':pa' . $idx] = $v;
            $params[':pb' . $idx] = $v;
        }
        $in1 = implode(', ', $ph1);
        $in2 = implode(', ', $ph2);
        $stmt = $pdo->prepare("
            SELECT * FROM password_reset_otps
            WHERE (LOWER(email) = LOWER(:email1) AND (phone IS NULL OR {$normExpr} IN ({$in1})))
               OR ((email IS NULL OR LOWER(email) = LOWER(:email2)) AND {$normExpr} IN ({$in2}))
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
    if ($email !== '') {
        $stmt = $pdo->prepare("SELECT * FROM password_reset_otps WHERE LOWER(email) = LOWER(:email) ORDER BY id DESC LIMIT 1");
        $stmt->execute([':email' => $email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
    if (!empty($variants)) {
        $params = [];
        $ph = [];
        foreach ($variants as $idx => $v) {
            $ph[] = ':p' . $idx;
            $params[':p' . $idx] = $v;
        }
        $stmt = $pdo->prepare("SELECT * FROM password_reset_otps WHERE {$normExpr} IN (" . implode(', ', $ph) . ") ORDER BY id DESC LIMIT 1");
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
    return null;
}

function clearOtpRecordsPhp(PDO $pdo, string $email, string $rawPhone, string $phone, string $dialCode = ''): void
{
    $variants = ($rawPhone !== '' || $phone !== '')
        ? getPhoneLookupVariantsPhp($rawPhone, $phone, $dialCode)
        : [];
    $normExpr = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(phone, ''), ' ', ''), '-', ''), '(', ''), ')', ''), '.', '')";
    $clauses = [];
    $params = [];

    if ($email !== '') {
        $clauses[] = "LOWER(email) = LOWER(:email)";
        $params[':email'] = strtolower(trim($email));
    }
    if (!empty($variants)) {
        $ph = [];
        foreach ($variants as $idx => $v) {
            $ph[] = ':cp' . $idx;
            $params[':cp' . $idx] = $v;
        }
        $clauses[] = "{$normExpr} IN (" . implode(', ', $ph) . ")";
    }

    if (!empty($clauses)) {
        $sql = "DELETE FROM password_reset_otps WHERE " . implode(' OR ', $clauses);
        $pdo->prepare($sql)->execute($params);
    }
}

function apiForgotPassword(PDO $pdo): void
{
    ensurePasswordResetSchemaPhp($pdo);
    $input = getJsonInput();
    $ids = extractAuthIdentifiersPhp($input, false);
    $email = $ids['email'];
    $rawPhone = $ids['rawPhone'];
    $dialCode = $ids['dialCode'] ?? '';
    $phone = $ids['phone'];
    $identifierError = $ids['identifierError'];

    $recaptchaToken = extractFieldPhp($input, ['recaptcha_token', 'recaptchaToken', 'recaptcha'], '');
    $continueUrl = extractFieldPhp($input, ['continue_url', 'continueUrl', 'redirect_url', 'redirectUrl'], '');
    $authMode = strtolower(extractFieldPhp($input, ['auth_mode', 'authMode', 'flow'], 'password_reset'));
    $requestedChannel = strtolower(extractFieldPhp($input, ['channel', 'gateway_channel'], ''));

    $errors = [];
    if ($identifierError !== '') {
        $errors['email_or_phone'] = [$identifierError];
    } elseif ($email === '' && $rawPhone === '') {
        $errors['email_or_phone'] = ['Please provide an email address or phone number.'];
    } else {
        if ($email !== '' && !isValidEmailAddressPhp($email)) {
            $errors['email'] = ['The email address format is invalid.'];
        }
        if ($rawPhone !== '') {
            $phoneErr = validatePhoneFormatPhp($phone, $rawPhone);
            if ($phoneErr !== null) {
                $errors['phone'] = [$phoneErr];
            }
        }
    }

    if (!empty($errors)) {
        $firstMsg = reset($errors)[0] ?? 'Validation error occurred.';
        errorResponse(
            count($errors) === 1 ? $firstMsg : 'Validation error occurred.',
            $errors,
            422,
            ['status' => false, 'error_code' => 'VALIDATION_ERROR']
        );
    }

    $activeChannel = ($requestedChannel === 'email' || ($rawPhone === '' && $email !== '')) ? 'email' : 'phone';

    $user = findPasswordResetUserPhp($pdo, $email, $rawPhone, $phone, $dialCode);
    if ($user && ($user['role'] ?? 'seller') !== 'admin' && in_array(strtoupper((string) ($user['account_status'] ?? 'ACTIVE')), ['LOCKED', 'DELETED'], true)) {
        errorResponse('Password reset is disabled because this seller account is locked or deleted.', ['account_status' => [$user['account_status']]], 403, ['status' => false, 'error_code' => 'ACCOUNT_' . strtoupper((string) ($user['account_status'] ?? 'LOCKED'))]);
    }

    $otp = null;
    $gatewayResult = null;
    if ($user) {
        $userRawPhone = !empty($user['normalized_phone'])
            ? trim((string) $user['normalized_phone'])
            : (!empty($user['phone']) ? normalizePhoneNumberPhp((string) $user['phone']) : '');
        $targetPhone = $userRawPhone !== '' ? $userRawPhone : ($phone !== '' ? $phone : null);
        $publicEmail = getPublicSellerEmailPhp($user['email'] ?? null, (string) ($user['role'] ?? 'seller'));
        $targetEmail = $publicEmail ?: ($email !== '' ? $email : null);

        if ($activeChannel === 'email' && !$targetEmail && $targetPhone) {
            $activeChannel = 'phone';
        } elseif ($activeChannel === 'phone' && !$targetPhone && $targetEmail) {
            $activeChannel = 'email';
        }

        $isTestTarget = ($activeChannel === 'email' && $targetEmail)
            ? isFirebaseTestEmail($targetEmail)
            : ($targetPhone ? isFirebaseTestPhone($targetPhone) : false);
        $otp = $isTestTarget ? getFirebaseTestOtp() : sprintf('%06d', mt_rand(100000, 999999));
        $hashedOtp = hashPassword($otp);

        clearOtpRecordsPhp($pdo, $targetEmail ?: $email, $rawPhone, $targetPhone ?: $phone, $dialCode);
        $ins = $pdo->prepare("INSERT INTO password_reset_otps (phone, email, channel, token, created_at) VALUES (:phone, :email, :channel, :token, CURRENT_TIMESTAMP)");
        $ins->execute([
            ':phone' => $targetPhone,
            ':email' => $targetEmail,
            ':channel' => $activeChannel,
            ':token' => $hashedOtp
        ]);

        $gatewayResult = $activeChannel === 'email'
            ? sendPasswordResetEmail((string) $targetEmail, $otp, $continueUrl, $authMode)
            : sendOtpSms((string) $targetPhone, $otp, $recaptchaToken);
    }

    $response = [
        'message' => 'If this email or phone number is registered, a verification OTP / reset link will be sent.',
        'channel' => $activeChannel
    ];
    if ($otp !== null) {
        $response['dev_otp'] = $otp; // Included for Postman & developer testing
        if ($gatewayResult && isset($gatewayResult['provider'])) {
            $dispatched = !empty($gatewayResult['sent']) ? 'dispatched' : 'mock_mode';
            $response['gateway'] = $gatewayResult['provider'];
            $response['delivery_status'] = $dispatched;
            if ($activeChannel === 'email') {
                $response['email_status'] = $dispatched;
            } else {
                $response['sms_status'] = $dispatched;
            }
            if (!empty($gatewayResult['session_info'])) {
                $response['session_info'] = $gatewayResult['session_info'];
            }
            if (!empty($gatewayResult['flow'])) {
                $response['firebase_flow'] = $gatewayResult['flow'];
            }
            if (empty($gatewayResult['sent']) && isset($gatewayResult['reason'])) {
                $response['gateway_note'] = $gatewayResult['reason'];
                if ($activeChannel === 'phone') {
                    $response['sms_note'] = $gatewayResult['reason'];
                } else {
                    $response['email_note'] = $gatewayResult['reason'];
                }
            }
        }
    }
    jsonResponse($response);
}

function apiVerifyOtp(PDO $pdo): void
{
    ensurePasswordResetSchemaPhp($pdo);
    $input = getJsonInput();
    $ids = extractAuthIdentifiersPhp($input, false);
    $email = $ids['email'];
    $rawPhone = $ids['rawPhone'];
    $dialCode = $ids['dialCode'] ?? '';
    $phone = $ids['phone'];
    $identifierError = $ids['identifierError'];

    $code = trim(extractFieldPhp($input, ['code', 'otp', 'otp_code', 'verification_code'], ''));
    $sessionInfo = trim(extractFieldPhp($input, ['session_info', 'sessionInfo', 'session'], ''));
    $idToken = trim(extractFieldPhp($input, ['id_token', 'idToken', 'firebase_token'], ''));
    $oobCode = trim(extractFieldPhp($input, ['oob_code', 'oobCode'], ''));

    $errors = [];
    if ($identifierError !== '') {
        $errors['email_or_phone'] = [$identifierError];
    } else {
        if ($email !== '' && !isValidEmailAddressPhp($email)) {
            $errors['email'] = ['The email address format is invalid.'];
        }
        if ($rawPhone !== '') {
            $phoneErr = validatePhoneFormatPhp($phone, $rawPhone);
            if ($phoneErr !== null) {
                $errors['phone'] = [$phoneErr];
            }
        }
        if ($email === '' && $rawPhone === '' && $idToken === '' && $oobCode === '') {
            $errors['email_or_phone'] = ['Email or phone number (or Firebase ID Token / OOB code) is required.'];
        }
        if ($code === '' && $idToken === '' && $oobCode === '') {
            $errors['code'] = ['Verification OTP code, Firebase OOB code, or Firebase ID Token is required.'];
        }
    }

    if (!empty($errors)) {
        $firstMsg = reset($errors)[0] ?? 'Validation error occurred.';
        errorResponse($firstMsg, $errors, 422, ['status' => false, 'error_code' => 'VALIDATION_ERROR']);
    }

    $firebaseRes = verifyFirebaseIdentity([
        'phone' => $phone,
        'email' => $email,
        'code' => $code,
        'sessionInfo' => $sessionInfo,
        'idToken' => $idToken,
        'oobCode' => $oobCode
    ]);

    $resolvedEmail = $email !== '' ? $email : strtolower(trim((string) ($firebaseRes['resolvedEmail'] ?? '')));
    $resolvedPhoneRaw = $phone !== '' ? $phone : trim((string) ($firebaseRes['resolvedPhone'] ?? ''));
    $resolvedPhone = $resolvedPhoneRaw !== '' ? (normalizePhoneNumberPhp($resolvedPhoneRaw, $dialCode) ?: $resolvedPhoneRaw) : '';

    if ($resolvedEmail !== '' && !isValidEmailAddressPhp($resolvedEmail)) {
        errorResponse('The email address format is invalid.', ['email' => ['The email address format is invalid.']], 422, ['status' => false, 'error_code' => 'VALIDATION_ERROR']);
    }

    $row = findLatestOtpRecordPhp($pdo, $resolvedEmail, $rawPhone, $resolvedPhone, $dialCode);
    $matchedUser = findPasswordResetUserPhp($pdo, $resolvedEmail, $rawPhone, $resolvedPhone, $dialCode);
    if ($matchedUser && ($matchedUser['role'] ?? 'seller') !== 'admin' && in_array(strtoupper((string) ($matchedUser['account_status'] ?? 'ACTIVE')), ['LOCKED', 'DELETED'], true)) {
        errorResponse('Password reset is disabled because this seller account is locked or deleted.', ['account_status' => [$matchedUser['account_status']]], 403, ['status' => false, 'error_code' => 'ACCOUNT_' . strtoupper((string) ($matchedUser['account_status'] ?? 'LOCKED'))]);
    }

    $firebaseApproved = !empty($firebaseRes['approved']);
    $localValid = $row && $code !== '' && verifyPassword($code, $row['token']);

    if (!$firebaseApproved && !$localValid) {
        errorResponse('Invalid or expired OTP code.', ['code' => ['Invalid or expired OTP code.']], 422, ['status' => false, 'error_code' => 'INVALID_OTP']);
    }

    if ($firebaseApproved && !$localValid && !isFirebaseTestPhone($resolvedPhone) && !isFirebaseTestEmail($resolvedEmail) && !$matchedUser) {
        errorResponse('No seller account matches this verified Firebase email or phone number.', [], 422, ['status' => false, 'error_code' => 'ACCOUNT_NOT_FOUND']);
    }

    $resetToken = bin2hex(random_bytes(32));
    $hashedResetToken = hashPassword($resetToken);

    $userNormPhone = ($matchedUser && !empty($matchedUser['normalized_phone']))
        ? trim((string) $matchedUser['normalized_phone'])
        : (($matchedUser && !empty($matchedUser['phone'])) ? normalizePhoneNumberPhp((string) $matchedUser['phone']) : '');
    $targetPhone = ($userNormPhone !== '' ? $userNormPhone : null)
        ?: (($row['phone'] ?? null) ?: ($resolvedPhone !== '' ? $resolvedPhone : null));
    $targetEmail = ($matchedUser ? getPublicSellerEmailPhp($matchedUser['email'] ?? null, (string) ($matchedUser['role'] ?? 'seller')) : null)
        ?: (($row['email'] ?? null) ?: ($resolvedEmail !== '' ? $resolvedEmail : null));
    $resolvedChannel = ($row['channel'] ?? null) ?: (($resolvedEmail !== '' && $resolvedPhone === '') ? 'email' : 'phone');

    clearOtpRecordsPhp($pdo, $targetEmail ?: $resolvedEmail, $rawPhone, $targetPhone ?: $resolvedPhone, $dialCode);
    $ins = $pdo->prepare("INSERT INTO password_reset_otps (phone, email, channel, token, created_at) VALUES (:phone, :email, :channel, :token, CURRENT_TIMESTAMP)");
    $ins->execute([
        ':phone' => $targetPhone,
        ':email' => $targetEmail,
        ':channel' => $resolvedChannel,
        ':token' => $hashedResetToken
    ]);

    jsonResponse([
        'message' => 'OTP verified successfully.',
        'reset_token' => $resetToken,
        'channel' => $resolvedChannel,
        'gateway' => $firebaseApproved ? 'firebase' : 'local'
    ]);
}

function apiResetPassword(PDO $pdo): void
{
    ensurePasswordResetSchemaPhp($pdo);
    ensureSellerDeletionSchemaPhp($pdo);
    ensureNormalizedPhoneSchemaPhp($pdo);
    $input = getJsonInput();
    $ids = extractAuthIdentifiersPhp($input, false);
    $email = $ids['email'];
    $rawPhone = $ids['rawPhone'];
    $dialCode = $ids['dialCode'] ?? '';
    $phone = $ids['phone'];
    $identifierError = $ids['identifierError'];

    $resetToken = trim(extractFieldPhp($input, ['reset_token', 'resetToken', 'token'], ''));
    $oobCode = trim(extractFieldPhp($input, ['oob_code', 'oobCode'], ''));
    $idToken = trim(extractFieldPhp($input, ['id_token', 'idToken', 'firebase_token'], ''));
    $password = trim(extractFieldPhp($input, ['password', 'new_password', 'newPassword', 'pass'], ''));
    $confirmPassword = extractConfirmPasswordPhp($input, $password);

    $errors = [];
    if ($identifierError !== '') {
        $errors['email_or_phone'] = [$identifierError];
    } else {
        if ($email !== '' && !isValidEmailAddressPhp($email)) {
            $errors['email'] = ['The email address format is invalid.'];
        }
        if ($rawPhone !== '') {
            $phoneErr = validatePhoneFormatPhp($phone, $rawPhone);
            if ($phoneErr !== null) {
                $errors['phone'] = [$phoneErr];
            }
        }
        if ($email === '' && $rawPhone === '' && $idToken === '' && $oobCode === '') {
            $errors['email_or_phone'] = ['Please provide an email address or phone number.'];
        }
    }
    if ($resetToken === '' && $oobCode === '' && $idToken === '') {
        $errors['reset_token'] = ['The reset_token field (or Firebase OOB/ID token) is required.'];
    }
    if ($password === '') {
        $errors['password'] = ['The password field is required.'];
    } elseif (strlen($password) < 8) {
        $errors['password'] = ['The password must be at least 8 characters.'];
    } elseif ($password !== $confirmPassword) {
        $errors['password'] = ['The password confirmation does not match.'];
    }

    if (!empty($errors)) {
        $firstMsg = reset($errors)[0] ?? 'Validation error occurred.';
        errorResponse($firstMsg, $errors, 422, ['status' => false, 'error_code' => 'VALIDATION_ERROR']);
    }

    $resolvedEmail = $email;
    $resolvedPhone = $phone;
    $resolvedChannel = ($email !== '' && $rawPhone === '') ? 'email' : 'phone';
    $firebaseResetDone = false;

    if ($resetToken !== '') {
        $row = findLatestOtpRecordPhp($pdo, $resolvedEmail, $rawPhone, $resolvedPhone, $dialCode);
        if (!$row || !verifyPassword($resetToken, $row['token'])) {
            errorResponse('Invalid or expired reset token.', ['reset_token' => ['The reset token is invalid or expired. Verify your OTP again.']], 422, ['status' => false, 'error_code' => 'INVALID_RESET_TOKEN']);
        }
        $resolvedEmail = $resolvedEmail !== '' ? $resolvedEmail : strtolower(trim((string) ($row['email'] ?? '')));
        $rowPhone = trim((string) ($row['phone'] ?? ''));
        $resolvedPhone = $resolvedPhone !== '' ? $resolvedPhone : ($rowPhone !== '' ? (normalizePhoneNumberPhp($rowPhone, $dialCode) ?: $rowPhone) : '');
        $resolvedChannel = ($row['channel'] ?? null) ?: (($resolvedEmail !== '' && $resolvedPhone === '') ? 'email' : 'phone');
    } elseif ($idToken !== '') {
        $fbCheck = verifyFirebaseIdentity([
            'phone' => $resolvedPhone,
            'email' => $resolvedEmail,
            'idToken' => $idToken
        ]);
        if (empty($fbCheck['approved'])) {
            errorResponse('Invalid or expired Firebase ID token.', ['id_token' => ['Invalid or expired Firebase ID token.']], 422, ['status' => false, 'error_code' => 'INVALID_FIREBASE_TOKEN']);
        }
        $resolvedEmail = $resolvedEmail !== '' ? $resolvedEmail : strtolower(trim((string) ($fbCheck['resolvedEmail'] ?? '')));
        $fbPhone = trim((string) ($fbCheck['resolvedPhone'] ?? ''));
        $resolvedPhone = $resolvedPhone !== '' ? $resolvedPhone : ($fbPhone !== '' ? (normalizePhoneNumberPhp($fbPhone, $dialCode) ?: $fbPhone) : '');
        $resolvedChannel = ($resolvedEmail !== '' && $resolvedPhone === '') ? 'email' : 'phone';
    } elseif ($oobCode !== '') {
        $fbCheck = verifyFirebaseIdentity([
            'email' => $resolvedEmail,
            'oobCode' => $oobCode
        ]);
        if (empty($fbCheck['approved'])) {
            errorResponse('Invalid or expired Firebase OOB reset code.', ['oob_code' => ['Invalid or expired Firebase OOB reset code.']], 422, ['status' => false, 'error_code' => 'INVALID_OOB_CODE']);
        }
        $resolvedEmail = $resolvedEmail !== '' ? $resolvedEmail : strtolower(trim((string) ($fbCheck['resolvedEmail'] ?? '')));
        $resolvedChannel = 'email';
    }

    $matchedUser = findPasswordResetUserPhp($pdo, $resolvedEmail, $rawPhone, $resolvedPhone, $dialCode);
    if ($matchedUser && ($matchedUser['role'] ?? 'seller') !== 'admin' && in_array(strtoupper((string) ($matchedUser['account_status'] ?? 'ACTIVE')), ['LOCKED', 'DELETED'], true)) {
        errorResponse('Password reset is disabled because this seller account is locked or deleted.', ['account_status' => [$matchedUser['account_status']]], 403, ['status' => false, 'error_code' => 'ACCOUNT_' . strtoupper((string) ($matchedUser['account_status'] ?? 'LOCKED'))]);
    }

    if (!$matchedUser && !isFirebaseTestPhone($resolvedPhone) && !isFirebaseTestEmail($resolvedEmail)) {
        errorResponse('No seller account matches the supplied email or phone number.', [], 404, ['status' => false, 'error_code' => 'ACCOUNT_NOT_FOUND']);
    }

    if ($oobCode !== '' && $resetToken === '') {
        $applied = applyFirebaseOobPasswordResetPhp($oobCode, $password);
        if (empty($applied['ok'])) {
            errorResponse((string) ($applied['error'] ?? 'Invalid or expired Firebase OOB reset code.'), [], 422, ['status' => false, 'error_code' => 'INVALID_OOB_CODE']);
        }
        $firebaseResetDone = true;
    }

    $hashed = hashPassword($password);
    if ($matchedUser) {
        $pdo->prepare("UPDATE users SET password = :password, token_version = COALESCE(token_version, 1) + 1, updated_at = CURRENT_TIMESTAMP WHERE id = :id")
            ->execute([':password' => $hashed, ':id' => (int) $matchedUser['id']]);
        try {
            $pdo->prepare("DELETE FROM personal_access_tokens WHERE tokenable_id = :id")->execute([':id' => (int) $matchedUser['id']]);
        } catch (Throwable $e) {}
    } elseif ($resolvedEmail !== '' && $resolvedPhone === '') {
        $pdo->prepare("UPDATE users SET password = :password, token_version = COALESCE(token_version, 1) + 1, updated_at = CURRENT_TIMESTAMP WHERE LOWER(email) = LOWER(:email)")
            ->execute([':password' => $hashed, ':email' => $resolvedEmail]);
    } elseif ($resolvedPhone !== '') {
        $targetRow = findUserByPhonePhp($pdo, $rawPhone, $resolvedPhone, $dialCode);
        if ($targetRow) {
            $pdo->prepare("UPDATE users SET password = :password, token_version = COALESCE(token_version, 1) + 1, updated_at = CURRENT_TIMESTAMP WHERE id = :id")
                ->execute([':password' => $hashed, ':id' => (int) $targetRow['id']]);
        }
    }

    clearOtpRecordsPhp($pdo, $resolvedEmail, $rawPhone, $resolvedPhone, $dialCode);

    $resPayload = [
        'message' => 'Password reset successful. Please log in again.',
        'channel' => $resolvedChannel
    ];
    if ($firebaseResetDone) {
        $resPayload['firebase_synced'] = true;
    }
    jsonResponse($resPayload);
}

