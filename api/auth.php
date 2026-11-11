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
    $rawPhone = extractPhonePhp($input);

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

    $dialCode = $input['country_code'] ?? $input['countryCode'] ?? $input['dial_code'] ?? $input['dialCode'] ?? null;
    $attachDialCode = function (string $val) use ($dialCode): string {
        $trimmed = trim($val);
        if ($trimmed === '') return '';
        if ($dialCode && !str_starts_with($trimmed, '+')) {
            return trim((string)$dialCode) . $trimmed;
        }
        return $trimmed;
    };

    if ($rawEmail === '' && $rawPhone === '' && $rawIdentifier !== '') {
        if (str_contains($rawIdentifier, '@')) {
            $rawEmail = $rawIdentifier;
        } elseif (preg_match('/^\+?[\d\s\-().]+$/', $rawIdentifier)) {
            $rawPhone = $attachDialCode($rawIdentifier);
        } else {
            $identifierError = 'Please provide a valid email address or phone number.';
        }
    }

    // Handle single-input forms wired only to `email` or `phone`
    if ($rawEmail === '' && $rawPhone !== '' && str_contains($rawPhone, '@')) {
        $rawEmail = $rawPhone;
        $rawPhone = '';
    } elseif ($rawPhone === '' && $rawEmail !== '' && !str_contains($rawEmail, '@') && preg_match('/^\+?[\d\s\-().]{7,20}$/', $rawEmail)) {
        $rawPhone = $attachDialCode($rawEmail);
        $rawEmail = '';
    }

    $email = $rawEmail !== '' ? strtolower(trim($rawEmail)) : '';
    $phone = $rawPhone !== '' ? normalizePhonePhp($rawPhone) : '';

    return [
        'rawEmail' => $rawEmail,
        'email' => $email,
        'rawPhone' => $rawPhone,
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
        $errors['email'] = [$identifierError];
        $errors['phone'] = [$identifierError];
    } elseif ($email === '' && $rawPhone === '') {
        $contactMsg = 'Please provide an email address or phone number.';
        $errors['email_or_phone'] = [$contactMsg];
        $errors['email'] = [$contactMsg];
        $errors['phone'] = [$contactMsg];
    } else {
        if ($email !== '' && !isValidEmailAddressPhp($email)) {
            $errors['email'] = ['The email address format is invalid.'];
        }
        if ($rawPhone !== '' && !preg_match('/^\+[1-9]\d{7,14}$/', $phone)) {
            $errors['phone'] = ['The phone format is invalid (must include country code, e.g. +919876543210).'];
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
        errorResponse('Validation error occurred.', $errors, 422);
    }

    $rawNoPrefix = $rawPhone !== '' ? preg_replace('/^\+91|^\+/', '', $rawPhone) : '';

    $phoneMatch = null;
    if ($phone !== '') {
        $phoneStmt = $pdo->prepare("SELECT * FROM users WHERE phone = :phone OR phone = :rawPhone OR phone = :rawNoPrefix LIMIT 1");
        $phoneStmt->execute([':phone' => $phone, ':rawPhone' => $rawPhone, ':rawNoPrefix' => $rawNoPrefix]);
        $phoneMatch = $phoneStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    $emailMatch = null;
    if ($email !== '') {
        $emailStmt = $pdo->prepare("SELECT * FROM users WHERE LOWER(email) = LOWER(:email) LIMIT 1");
        $emailStmt->execute([':email' => $email]);
        $emailMatch = $emailStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if ($phoneMatch) {
        if (verifyPassword($password, $phoneMatch['password'])) {
            if ($emailMatch && (int) $emailMatch['id'] !== (int) $phoneMatch['id']) {
                errorResponse('The email has already been taken.', ['email' => ['The email has already been taken.']], 422);
            }
            $existingPublicEmail = getPublicSellerEmailPhp($phoneMatch['email'] ?? null, $phoneMatch['role'] ?? 'seller');
            if ($email !== '' && $existingPublicEmail !== null && strtolower($existingPublicEmail) !== $email) {
                errorResponse('The phone has already been taken.', ['phone' => ['The phone has already been taken.']], 422);
            }
            $resolvedEmail = $phoneMatch['email'] ?? '';
            if ($email !== '' && (empty($resolvedEmail) || str_ends_with(strtolower((string) $resolvedEmail), '@phone.vendor.local'))) {
                $updEmail = $pdo->prepare("UPDATE users SET email = :email, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
                $updEmail->execute([':email' => $email, ':id' => (int) $phoneMatch['id']]);
                $resolvedEmail = $email;
            }
            $role = $phoneMatch['role'] ?? 'seller';
            $publicEmail = getPublicSellerEmailPhp($resolvedEmail, $role);
            $resolvedPhone = !empty($phoneMatch['phone']) ? $phoneMatch['phone'] : $phone;
            $token = createToken($pdo, (int) $phoneMatch['id'], $resolvedPhone, 'vendor-app', $publicEmail, $role);
            jsonResponse([
                'message' => 'Seller account already exists. Successfully signed in.',
                'user' => safeSeller([
                    'id' => $phoneMatch['id'],
                    'seller_name' => $sellerName ?: ($phoneMatch['seller_name'] ?? $phoneMatch['name'] ?? ''),
                    'email' => $resolvedEmail,
                    'phone' => $resolvedPhone,
                    'address' => $address ?: ($phoneMatch['address'] ?? null),
                    'landmark' => $landmark ?: ($phoneMatch['landmark'] ?? null),
                    'state' => $state ?: ($phoneMatch['state'] ?? null),
                    'country' => $country ?: ($phoneMatch['country'] ?? 'India'),
                    'role' => $role,
                ]),
                'token' => $token,
                'token_type' => 'Bearer'
            ], 200);
        }
        errorResponse('The phone has already been taken.', ['phone' => ['The phone has already been taken.']], 422);
    }

    if ($emailMatch) {
        errorResponse('The email has already been taken.', ['email' => ['The email has already been taken.']], 422);
    }

    $hashedPassword = hashPassword($password);
    $dbEmail = $email !== '' ? $email : makeSyntheticPhoneEmailPhp($phone);
    $dbPhone = $phone !== '' ? $phone : null;

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

    $userId = (int) $pdo->lastInsertId();
    $publicEmail = $email !== '' ? $email : null;
    $token = createToken($pdo, $userId, $dbPhone, 'vendor-app', $publicEmail, 'seller');

    $fetchStmt = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
    $fetchStmt->execute([':id' => $userId]);
    $user = $fetchStmt->fetch() ?: [
        'id' => $userId,
        'seller_name' => $sellerName,
        'email' => $dbEmail,
        'phone' => $dbPhone,
        'address' => $address,
        'landmark' => $landmark,
        'state' => $state,
        'country' => $country ?: 'India',
        'role' => 'seller',
    ];

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
    $phone = $ids['phone'];
    $identifierError = $ids['identifierError'];
    $password = extractPasswordPhp($input);

    $errors = [];

    if ($identifierError !== '') {
        $errors['email_or_phone'] = [$identifierError];
        $errors['email'] = [$identifierError];
        $errors['phone'] = [$identifierError];
    } elseif ($email === '' && $rawPhone === '') {
        $contactMsg = 'The email or phone field is required.';
        $errors['email_or_phone'] = [$contactMsg];
        $errors['email'] = [$contactMsg];
        $errors['phone'] = [$contactMsg];
    } else {
        if ($email !== '' && !isValidEmailAddressPhp($email)) {
            $errors['email'] = ['The email address format is invalid.'];
        }
        if ($rawPhone !== '' && !preg_match('/^\+[1-9]\d{7,14}$/', $phone)) {
            $errors['phone'] = ['The phone format is invalid (must include country code, e.g. +919876543210).'];
        }
    }
    if (empty($password)) {
        $errors['password'] = ['The password field is required.'];
    }

    if (!empty($errors)) {
        errorResponse('Validation error occurred.', $errors, 422);
    }

    $rawNoPrefix = $rawPhone !== '' ? preg_replace('/^\+91|^\+/', '', $rawPhone) : '';
    $invalidCredsMsg = 'The supplied login credentials (email/phone or password) are incorrect.';
    $invalidCredsErrors = [
        'email_or_phone' => [$invalidCredsMsg],
        'email' => [$invalidCredsMsg],
        'phone' => [$invalidCredsMsg],
    ];

    $user = null;
    if ($email !== '' && $rawPhone !== '') {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE (phone = :phone OR phone = :rawPhone OR phone = :rawNoPrefix) AND LOWER(email) = LOWER(:email) LIMIT 1");
        $stmt->execute([
            ':phone' => $phone,
            ':rawPhone' => $rawPhone,
            ':rawNoPrefix' => $rawNoPrefix,
            ':email' => $email,
        ]);
        $user = $stmt->fetch() ?: null;
    } elseif ($email !== '') {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE LOWER(email) = LOWER(:email) LIMIT 1");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch() ?: null;
        if (!$user && $email === 'admin@venter.app') {
            $adminStmt = $pdo->prepare("SELECT * FROM users WHERE LOWER(email) = 'admin@vendor.app' AND role = 'admin' LIMIT 1");
            $adminStmt->execute();
            $user = $adminStmt->fetch() ?: null;
        }
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE phone = :phone OR phone = :rawPhone OR phone = :rawNoPrefix LIMIT 1");
        $stmt->execute([
            ':phone' => $phone,
            ':rawPhone' => $rawPhone,
            ':rawNoPrefix' => $rawNoPrefix,
        ]);
        $user = $stmt->fetch() ?: null;
    }

    if (!$user || !verifyPassword($password, $user['password'])) {
        errorResponse($invalidCredsMsg, $invalidCredsErrors, 422);
    }

    $role = $user['role'] ?? 'seller';
    $publicEmail = getPublicSellerEmailPhp($user['email'] ?? null, $role);
    $userPhone = isset($user['phone']) && trim((string)$user['phone']) !== '' ? trim((string)$user['phone']) : null;
    $token = createToken($pdo, (int) $user['id'], $userPhone, 'vendor-app', $publicEmail, $role);

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

function findPasswordResetUserPhp(PDO $pdo, string $email, string $rawPhone, string $phone, string $rawNoPrefix): ?array
{
    if ($email !== '' && $rawPhone !== '') {
        $stmt = $pdo->prepare("SELECT id, email, phone, role FROM users WHERE (phone = :phone OR phone = :rawPhone OR phone = :rawNoPrefix) AND LOWER(email) = :email LIMIT 1");
        $stmt->execute([':phone' => $phone, ':rawPhone' => $rawPhone, ':rawNoPrefix' => $rawNoPrefix, ':email' => $email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
    if ($email !== '') {
        $stmt = $pdo->prepare("SELECT id, email, phone, role FROM users WHERE LOWER(email) = :email LIMIT 1");
        $stmt->execute([':email' => $email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
    if ($rawPhone !== '' || $phone !== '') {
        $effRaw = $rawPhone !== '' ? $rawPhone : $phone;
        $effNoPrefix = $rawNoPrefix !== '' ? $rawNoPrefix : preg_replace('/^\+91|^\+/', '', $effRaw);
        $stmt = $pdo->prepare("SELECT id, email, phone, role FROM users WHERE phone = :phone OR phone = :rawPhone OR phone = :rawNoPrefix LIMIT 1");
        $stmt->execute([':phone' => $phone, ':rawPhone' => $effRaw, ':rawNoPrefix' => $effNoPrefix]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
    return null;
}

function findLatestOtpRecordPhp(PDO $pdo, string $email, string $rawPhone, string $phone, string $rawNoPrefix): ?array
{
    $effRaw = $rawPhone !== '' ? $rawPhone : $phone;
    $effNoPrefix = $rawNoPrefix !== '' ? $rawNoPrefix : preg_replace('/^\+91|^\+/', '', $effRaw);

    if ($email !== '' && ($rawPhone !== '' || $phone !== '')) {
        $stmt = $pdo->prepare("
            SELECT * FROM password_reset_otps
            WHERE (LOWER(email) = :email1 AND (phone IS NULL OR phone = :p1 OR phone = :rp1 OR phone = :np1))
               OR ((email IS NULL OR LOWER(email) = :email2) AND (phone = :p2 OR phone = :rp2 OR phone = :np2))
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([
            ':email1' => $email, ':p1' => $phone, ':rp1' => $effRaw, ':np1' => $effNoPrefix,
            ':email2' => $email, ':p2' => $phone, ':rp2' => $effRaw, ':np2' => $effNoPrefix
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
    if ($email !== '') {
        $stmt = $pdo->prepare("SELECT * FROM password_reset_otps WHERE LOWER(email) = :email ORDER BY id DESC LIMIT 1");
        $stmt->execute([':email' => $email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
    if ($rawPhone !== '' || $phone !== '') {
        $stmt = $pdo->prepare("SELECT * FROM password_reset_otps WHERE phone = :phone OR phone = :rawPhone OR phone = :rawNoPrefix ORDER BY id DESC LIMIT 1");
        $stmt->execute([':phone' => $phone, ':rawPhone' => $effRaw, ':rawNoPrefix' => $effNoPrefix]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
    return null;
}

function clearOtpRecordsPhp(PDO $pdo, string $email, string $rawPhone, string $phone, string $rawNoPrefix): void
{
    $effRaw = $rawPhone !== '' ? $rawPhone : $phone;
    $effNoPrefix = $rawNoPrefix !== '' ? $rawNoPrefix : preg_replace('/^\+91|^\+/', '', $effRaw);
    $clauses = [];
    $params = [];

    if ($email !== '') {
        $clauses[] = "LOWER(email) = :email";
        $params[':email'] = strtolower(trim($email));
    }
    if ($phone !== '' || $rawPhone !== '') {
        $clauses[] = "phone = :phone OR phone = :rawPhone OR phone = :rawNoPrefix";
        $params[':phone'] = $phone !== '' ? $phone : $effRaw;
        $params[':rawPhone'] = $effRaw;
        $params[':rawNoPrefix'] = $effNoPrefix;
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
    $phone = $ids['phone'];
    $identifierError = $ids['identifierError'];

    $recaptchaToken = extractFieldPhp($input, ['recaptcha_token', 'recaptchaToken', 'recaptcha'], '');
    $continueUrl = extractFieldPhp($input, ['continue_url', 'continueUrl', 'redirect_url', 'redirectUrl'], '');
    $authMode = strtolower(extractFieldPhp($input, ['auth_mode', 'authMode', 'flow'], 'password_reset'));
    $requestedChannel = strtolower(extractFieldPhp($input, ['channel', 'gateway_channel'], ''));

    $errors = [];
    if ($identifierError !== '') {
        $errors['email_or_phone'] = [$identifierError];
        $errors['email'] = [$identifierError];
        $errors['phone'] = [$identifierError];
    } elseif ($email === '' && $rawPhone === '') {
        $msg = 'Please provide an email address or phone number.';
        $errors['email_or_phone'] = [$msg];
        $errors['email'] = [$msg];
        $errors['phone'] = [$msg];
    } else {
        if ($email !== '' && !isValidEmailAddressPhp($email)) {
            $errors['email'] = ['The email address format is invalid.'];
        }
        if ($rawPhone !== '' && !preg_match('/^\+[1-9]\d{7,14}$/', $phone)) {
            $errors['phone'] = ['The phone format is invalid (must include country code, e.g. +919876543210).'];
        }
    }

    if (!empty($errors)) {
        errorResponse('Validation error occurred.', $errors, 422);
    }

    $rawNoPrefix = $rawPhone !== '' ? preg_replace('/^\+91|^\+/', '', $rawPhone) : '';
    $activeChannel = ($requestedChannel === 'email' || ($rawPhone === '' && $email !== '')) ? 'email' : 'phone';

    $user = findPasswordResetUserPhp($pdo, $email, $rawPhone, $phone, $rawNoPrefix);

    $otp = null;
    $gatewayResult = null;
    if ($user) {
        $targetPhone = (!empty($user['phone'])) ? trim((string) $user['phone']) : ($phone !== '' ? $phone : null);
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

        clearOtpRecordsPhp($pdo, $targetEmail ?: $email, $rawPhone, $targetPhone ?: $phone, $rawNoPrefix);
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
    $phone = $ids['phone'];
    $identifierError = $ids['identifierError'];

    $code = trim(extractFieldPhp($input, ['code', 'otp', 'otp_code', 'verification_code'], ''));
    $sessionInfo = trim(extractFieldPhp($input, ['session_info', 'sessionInfo', 'session'], ''));
    $idToken = trim(extractFieldPhp($input, ['id_token', 'idToken', 'firebase_token'], ''));
    $oobCode = trim(extractFieldPhp($input, ['oob_code', 'oobCode'], ''));

    $errors = [];
    if ($identifierError !== '') {
        $errors['email_or_phone'] = [$identifierError];
        $errors['email'] = [$identifierError];
        $errors['phone'] = [$identifierError];
    } else {
        if ($email !== '' && !isValidEmailAddressPhp($email)) {
            $errors['email'] = ['The email address format is invalid.'];
        }
        if ($rawPhone !== '' && !preg_match('/^\+[1-9]\d{7,14}$/', $phone)) {
            $errors['phone'] = ['The phone format is invalid (must include country code, e.g. +919876543210).'];
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
        errorResponse($firstMsg, $errors, 422);
    }

    $rawNoPrefix = $rawPhone !== '' ? preg_replace('/^\+91|^\+/', '', $rawPhone) : '';
    $firebaseRes = verifyFirebaseIdentity([
        'phone' => $phone,
        'email' => $email,
        'code' => $code,
        'sessionInfo' => $sessionInfo,
        'idToken' => $idToken,
        'oobCode' => $oobCode
    ]);

    $resolvedEmail = $email !== '' ? $email : (string) ($firebaseRes['resolvedEmail'] ?? '');
    $resolvedPhone = $phone !== '' ? $phone : (string) ($firebaseRes['resolvedPhone'] ?? '');
    $resolvedRawNoPrefix = $rawNoPrefix !== '' ? $rawNoPrefix : preg_replace('/^\+91|^\+/', '', $resolvedPhone);

    if ($resolvedEmail !== '' && !isValidEmailAddressPhp($resolvedEmail)) {
        errorResponse('The email address format is invalid.', ['email' => ['The email address format is invalid.']], 422);
    }

    $row = findLatestOtpRecordPhp($pdo, $resolvedEmail, $rawPhone, $resolvedPhone, $resolvedRawNoPrefix);
    $matchedUser = findPasswordResetUserPhp($pdo, $resolvedEmail, $rawPhone, $resolvedPhone, $resolvedRawNoPrefix);

    $firebaseApproved = !empty($firebaseRes['approved']);
    $localValid = $row && $code !== '' && verifyPassword($code, $row['token']);

    if (!$firebaseApproved && !$localValid) {
        errorResponse('Invalid or expired OTP code.', ['code' => ['Invalid or expired OTP code.']], 422);
    }

    if ($firebaseApproved && !$localValid && !isFirebaseTestPhone($resolvedPhone) && !isFirebaseTestEmail($resolvedEmail) && !$matchedUser) {
        errorResponse('No seller account matches this verified Firebase email or phone number.', [], 422);
    }

    $resetToken = bin2hex(random_bytes(32));
    $hashedResetToken = hashPassword($resetToken);

    $targetPhone = ($matchedUser['phone'] ?? null) ?: (($row['phone'] ?? null) ?: ($resolvedPhone !== '' ? $resolvedPhone : null));
    $targetEmail = ($matchedUser ? getPublicSellerEmailPhp($matchedUser['email'] ?? null, (string) ($matchedUser['role'] ?? 'seller')) : null)
        ?: (($row['email'] ?? null) ?: ($resolvedEmail !== '' ? $resolvedEmail : null));
    $resolvedChannel = ($row['channel'] ?? null) ?: (($resolvedEmail !== '' && $resolvedPhone === '') ? 'email' : 'phone');

    clearOtpRecordsPhp($pdo, $targetEmail ?: $resolvedEmail, $rawPhone, $targetPhone ?: $resolvedPhone, $resolvedRawNoPrefix);
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
    $input = getJsonInput();
    $ids = extractAuthIdentifiersPhp($input, false);
    $email = $ids['email'];
    $rawPhone = $ids['rawPhone'];
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
        $errors['email'] = [$identifierError];
        $errors['phone'] = [$identifierError];
    } else {
        if ($email !== '' && !isValidEmailAddressPhp($email)) {
            $errors['email'] = ['The email address format is invalid.'];
        }
        if ($rawPhone !== '' && !preg_match('/^\+[1-9]\d{7,14}$/', $phone)) {
            $errors['phone'] = ['The phone format is invalid (must include country code, e.g. +919876543210).'];
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
        errorResponse($firstMsg, $errors, 422);
    }

    $rawNoPrefix = $rawPhone !== '' ? preg_replace('/^\+91|^\+/', '', $rawPhone) : '';
    $resolvedEmail = $email;
    $resolvedPhone = $phone;
    $resolvedChannel = ($email !== '' && $rawPhone === '') ? 'email' : 'phone';
    $firebaseResetDone = false;

    if ($resetToken !== '') {
        $row = findLatestOtpRecordPhp($pdo, $resolvedEmail, $rawPhone, $resolvedPhone, $rawNoPrefix);
        if (!$row || !verifyPassword($resetToken, $row['token'])) {
            errorResponse('Invalid or expired reset token.', ['reset_token' => ['The reset token is invalid or expired. Verify your OTP again.']], 422);
        }
        $resolvedEmail = $resolvedEmail !== '' ? $resolvedEmail : (string) ($row['email'] ?? '');
        $resolvedPhone = $resolvedPhone !== '' ? $resolvedPhone : (string) ($row['phone'] ?? '');
        $resolvedChannel = ($row['channel'] ?? null) ?: (($resolvedEmail !== '' && $resolvedPhone === '') ? 'email' : 'phone');
    } elseif ($idToken !== '') {
        $fbCheck = verifyFirebaseIdentity([
            'phone' => $resolvedPhone,
            'email' => $resolvedEmail,
            'idToken' => $idToken
        ]);
        if (empty($fbCheck['approved'])) {
            errorResponse('Invalid or expired Firebase ID token.', ['id_token' => ['Invalid or expired Firebase ID token.']], 422);
        }
        $resolvedEmail = $resolvedEmail !== '' ? $resolvedEmail : (string) ($fbCheck['resolvedEmail'] ?? '');
        $resolvedPhone = $resolvedPhone !== '' ? $resolvedPhone : (string) ($fbCheck['resolvedPhone'] ?? '');
        $resolvedChannel = ($resolvedEmail !== '' && $resolvedPhone === '') ? 'email' : 'phone';
    } elseif ($oobCode !== '') {
        $fbCheck = verifyFirebaseIdentity([
            'email' => $resolvedEmail,
            'oobCode' => $oobCode
        ]);
        if (empty($fbCheck['approved'])) {
            errorResponse('Invalid or expired Firebase OOB reset code.', ['oob_code' => ['Invalid or expired Firebase OOB reset code.']], 422);
        }
        $resolvedEmail = $resolvedEmail !== '' ? $resolvedEmail : (string) ($fbCheck['resolvedEmail'] ?? '');
        $resolvedChannel = 'email';
    }

    $resolvedRawNoPrefix = $rawNoPrefix !== '' ? $rawNoPrefix : preg_replace('/^\+91|^\+/', '', $resolvedPhone);
    $matchedUser = findPasswordResetUserPhp($pdo, $resolvedEmail, $rawPhone, $resolvedPhone, $resolvedRawNoPrefix);

    if (!$matchedUser && !isFirebaseTestPhone($resolvedPhone) && !isFirebaseTestEmail($resolvedEmail)) {
        errorResponse('No seller account matches the supplied email or phone number.', [], 422);
    }

    if ($oobCode !== '' && $resetToken === '') {
        $applied = applyFirebaseOobPasswordResetPhp($oobCode, $password);
        if (empty($applied['ok'])) {
            errorResponse((string) ($applied['error'] ?? 'Invalid or expired Firebase OOB reset code.'), [], 422);
        }
        $firebaseResetDone = true;
    }

    $hashed = hashPassword($password);
    if ($matchedUser) {
        $pdo->prepare("UPDATE users SET password = :password, updated_at = CURRENT_TIMESTAMP WHERE id = :id")
            ->execute([':password' => $hashed, ':id' => (int) $matchedUser['id']]);
    } elseif ($resolvedEmail !== '' && $resolvedPhone === '') {
        $pdo->prepare("UPDATE users SET password = :password, updated_at = CURRENT_TIMESTAMP WHERE LOWER(email) = :email")
            ->execute([':password' => $hashed, ':email' => $resolvedEmail]);
    } elseif ($resolvedPhone !== '') {
        $effRaw = $rawPhone !== '' ? $rawPhone : $resolvedPhone;
        $pdo->prepare("UPDATE users SET password = :password, updated_at = CURRENT_TIMESTAMP WHERE phone = :phone OR phone = :rawPhone OR phone = :rawNoPrefix")
            ->execute([
                ':password' => $hashed,
                ':phone' => $resolvedPhone,
                ':rawPhone' => $effRaw,
                ':rawNoPrefix' => $resolvedRawNoPrefix
            ]);
    }

    clearOtpRecordsPhp($pdo, $resolvedEmail, $rawPhone, $resolvedPhone, $resolvedRawNoPrefix);

    $resPayload = [
        'message' => 'Password reset successful. Please log in again.',
        'channel' => $resolvedChannel
    ];
    if ($firebaseResetDone) {
        $resPayload['firebase_synced'] = true;
    }
    jsonResponse($resPayload);
}

