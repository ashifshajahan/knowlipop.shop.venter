<?php

/**
 * Pure PHP API: Seller Profile & Management Controller
 * Supports Seller ID-based lookup, profile editing, catalog listing, and admin access.
 */

require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/auth.php';

function hasUserColumn(PDO $pdo, string $column): bool
{
    static $columns = null;
    if ($columns === null) {
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM users");
            $columns = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
        } catch (Throwable $e) {
            $columns = [];
        }
    }
    return in_array($column, $columns, true);
}

function ensureUserColumns(PDO $pdo): void
{
    try {
        if (!hasUserColumn($pdo, 'avatar_url')) {
            $pdo->exec("ALTER TABLE users ADD COLUMN avatar_url LONGTEXT NULL AFTER country");
        }
    } catch (Throwable $e) {
        // Silently continue if permissions restrict ALTER
    }
}

function saveUploadedAvatarImage(?string $fallback = null): ?string
{
    $uploadDir = __DIR__ . '/../uploads/avatars/';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }

    $baseUrl = rtrim(getenv('APP_URL') ?: 'https://knowlipop.shop', '/');

    // 1. Check $_FILES for multipart/form-data upload
    $fileFields = ['avatar', 'image', 'profile_image', 'photo', 'picture', 'file'];
    foreach ($fileFields as $field) {
        if (!empty($_FILES[$field]['tmp_name']) && is_uploaded_file($_FILES[$field]['tmp_name'])) {
            $tmp = $_FILES[$field]['tmp_name'];
            $origName = $_FILES[$field]['name'] ?? 'avatar.jpg';
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'])) {
                $ext = 'jpg';
            }
            $filename = 'avatar_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
            $destination = $uploadDir . $filename;
            if (move_uploaded_file($tmp, $destination)) {
                return $baseUrl . '/uploads/avatars/' . $filename;
            }
        }
    }

    // 2. Base64 encoded image string
    $input = getJsonInput();
    $base64 = $input['avatar'] ?? $input['image'] ?? $input['profile_image'] ?? $input['avatar_base64'] ?? null;
    if (is_string($base64) && str_starts_with($base64, 'data:image/')) {
        if (preg_match('/^data:image\/(\w+);base64,(.+)$/', $base64, $matches)) {
            $ext = strtolower($matches[1]);
            if ($ext === 'jpeg') $ext = 'jpg';
            if (!in_array($ext, ['jpg', 'png', 'webp', 'gif', 'svg'])) $ext = 'png';

            $data = base64_decode($matches[2]);
            if ($data !== false) {
                $filename = 'avatar_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                file_put_contents($uploadDir . $filename, $data);
                return $baseUrl . '/uploads/avatars/' . $filename;
            }
        }
    }

    // 3. Directly passed URL string
    $urlCandidate = $input['avatar_url'] ?? $input['avatar'] ?? $input['image_url'] ?? null;
    if (is_string($urlCandidate) && (str_starts_with($urlCandidate, 'http://') || str_starts_with($urlCandidate, 'https://'))) {
        return trim($urlCandidate);
    }

    return $fallback;
}

/**
 * GET /api/seller/profile
 * Returns authenticated seller profile with statistics and payment status
 */
function apiGetSellerProfile(PDO $pdo): void
{
    ensureUserColumns($pdo);
    $user = authenticateUser($pdo);
    $sellerId = (int) $user['id'];

    // Product counts
    $stmtProducts = $pdo->prepare("SELECT COUNT(*) FROM products WHERE seller_id = :seller_id AND is_active = 1");
    $stmtProducts->execute([':seller_id' => $sellerId]);
    $productsCount = (int) $stmtProducts->fetchColumn();

    // Payment account status
    $stmtPayment = $pdo->prepare("SELECT provider, account_reference, status, updated_at FROM seller_payment_accounts WHERE seller_id = :seller_id LIMIT 1");
    $stmtPayment->execute([':seller_id' => $sellerId]);
    $paymentAccount = $stmtPayment->fetch();

    $sellerData = safeSeller($user);
    $sellerData['products_count'] = $productsCount;
    $sellerData['payment_account'] = $paymentAccount ?: [
        'status' => 'not_linked',
        'message' => 'No payment account linked yet.'
    ];

    jsonResponse([
        'status' => 'success',
        'seller' => $sellerData,
        'user' => $sellerData // for backward compatibility
    ]);
}

/**
 * GET /api/sellers/{id} or /api/seller/{id}
 * Returns public/detailed seller profile based on seller ID, along with their active products catalog
 */
function apiGetSellerById(PDO $pdo, int $sellerId): void
{
    ensureUserColumns($pdo);

    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $sellerId]);
    $user = $stmt->fetch();

    if (!$user) {
        errorResponse('Seller not found.', [], 404);
    }

    // Fetch active products of this seller
    $prodStmt = $pdo->prepare("
        SELECT id, seller_id, name, description, price, stock, image_url, is_active, created_at, updated_at
        FROM products
        WHERE seller_id = :seller_id AND is_active = 1
        ORDER BY id DESC
    ");
    $prodStmt->execute([':seller_id' => $sellerId]);
    $products = $prodStmt->fetchAll();

    foreach ($products as &$p) {
        $p['id'] = (int) $p['id'];
        $p['seller_id'] = (int) $p['seller_id'];
        $p['price'] = (string) number_format((float) $p['price'], 2, '.', '');
        $p['stock'] = (int) $p['stock'];
        $p['is_active'] = (bool) $p['is_active'];
    }

    $sellerData = safeSeller($user);
    $sellerData['products_count'] = count($products);
    $sellerData['products'] = $products;

    jsonResponse([
        'status' => 'success',
        'seller' => $sellerData
    ]);
}

/**
 * GET /api/sellers
 * Returns paginated directory of registered sellers
 */
function apiGetSellersList(PDO $pdo): void
{
    ensureUserColumns($pdo);

    $page = max(1, (int) ($_GET['page'] ?? 1));
    $limit = min(50, max(1, (int) ($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    $search = trim($_GET['search'] ?? $_GET['q'] ?? '');

    $where = "WHERE (role = 'seller' OR role = 'admin')";
    $params = [];

    if ($search !== '') {
        $where .= " AND (seller_name LIKE :s1 OR name LIKE :s2 OR phone LIKE :s3 OR state LIKE :s4)";
        $params[':s1'] = "%{$search}%";
        $params[':s2'] = "%{$search}%";
        $params[':s3'] = "%{$search}%";
        $params[':s4'] = "%{$search}%";
    }

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM users {$where}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $sql = "
        SELECT u.id, u.name, u.seller_name, u.email, u.phone, u.address, u.landmark, u.state, u.country, u.role, u.created_at, u.updated_at,
               (SELECT COUNT(*) FROM products p WHERE p.seller_id = u.id AND p.is_active = 1) AS products_count
        FROM users u
        {$where}
        ORDER BY u.id DESC
        LIMIT :limit OFFSET :offset
    ";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $sellers = array_map(function ($row) {
        $data = safeSeller($row);
        $data['products_count'] = (int) ($row['products_count'] ?? 0);
        return $data;
    }, $rows);

    jsonResponse([
        'status' => 'success',
        'sellers' => [
            'current_page' => $page,
            'data' => $sellers,
            'total' => $total,
            'per_page' => $limit,
            'last_page' => ceil($total / $limit),
        ]
    ]);
}

/**
 * PUT/PATCH/POST /api/seller/profile or PUT/PATCH /api/sellers/{id}
 * Edits and updates seller profile details
 */
function apiUpdateSellerProfile(PDO $pdo, ?int $targetId = null): void
{
    ensureUserColumns($pdo);
    $currentUser = authenticateUser($pdo);

    // Authorization: seller can edit their own profile, or an admin can edit any profile
    if ($targetId !== null && (int)$currentUser['id'] !== $targetId && ($currentUser['role'] ?? '') !== 'admin') {
        errorResponse('Unauthorized. You can only edit your own seller profile.', [], 403);
    }

    $sellerId = $targetId !== null ? $targetId : (int) $currentUser['id'];

    // Fetch existing user record
    $fetchStmt = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
    $fetchStmt->execute([':id' => $sellerId]);
    $existing = $fetchStmt->fetch();
    if (!$existing) {
        errorResponse('Seller not found.', [], 404);
    }

    $input = getJsonInput();
    $errors = [];

    // Fields to update
    $sellerName = extractFieldPhp($input, ['seller_name', 'sellerName', 'business_name', 'shop_name', 'store_name']);
    $name = extractFieldPhp($input, ['name', 'fullname', 'full_name', 'owner_name']);
    $address = extractFieldPhp($input, ['address', 'shop_address', 'business_address', 'street', 'location']);
    $landmark = extractFieldPhp($input, ['landmark', 'land_mark', 'nearby']);
    $state = extractFieldPhp($input, ['state', 'province', 'region']);
    $country = extractFieldPhp($input, ['country', 'nation']);
    $rawPhone = extractPhonePhp($input);
    $email = trim(extractFieldPhp($input, ['email', 'email_address']));
    $password = extractPasswordPhp($input);
    $confirmPassword = extractConfirmPasswordPhp($input, $password);

    // Validate phone if provided
    $phone = '';
    if (!empty($rawPhone)) {
        $phone = normalizePhonePhp($rawPhone);
        if (!preg_match('/^\+[1-9]\d{7,14}$/', $phone)) {
            $errors['phone'] = ['The phone format is invalid (must include country code, e.g. +919876543210).'];
        } else {
            $rawNoPrefix = preg_replace('/^\+91|^\+/', '', $rawPhone);
            $chkPhone = $pdo->prepare("SELECT id FROM users WHERE (phone = :p OR phone = :rp OR phone = :rnp) AND id != :id LIMIT 1");
            $chkPhone->execute([':p' => $phone, ':rp' => $rawPhone, ':rnp' => $rawNoPrefix, ':id' => $sellerId]);
            if ($chkPhone->fetch()) {
                $errors['phone'] = ['This phone number is already registered to another account.'];
            }
        }
    }

    // Validate email if provided
    if (!empty($email)) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = ['The email address format is invalid.'];
        } else {
            $chkEmail = $pdo->prepare("SELECT id FROM users WHERE email = :email AND id != :id LIMIT 1");
            $chkEmail->execute([':email' => $email, ':id' => $sellerId]);
            if ($chkEmail->fetch()) {
                $errors['email'] = ['This email address is already in use by another account.'];
            }
        }
    }

    // Validate password if provided
    if (!empty($password)) {
        if (strlen($password) < 8) {
            $errors['password'] = ['The new password must be at least 8 characters.'];
        } elseif ($password !== $confirmPassword) {
            $errors['password'] = ['The password confirmation does not match.'];
        }
    }

    if (!empty($errors)) {
        errorResponse('Validation error occurred.', $errors, 422);
    }

    // Handle avatar image if provided
    $avatarUrl = saveUploadedAvatarImage($existing['avatar_url'] ?? null);

    // Build dynamic UPDATE statement
    $updates = [];
    $params = [':id' => $sellerId];

    if ($sellerName !== '') {
        $updates[] = "seller_name = :seller_name";
        $params[':seller_name'] = $sellerName;
        // Keep name in sync if name not provided separately
        if ($name === '') {
            $updates[] = "name = :name_synced";
            $params[':name_synced'] = $sellerName;
        }
    }
    if ($name !== '') {
        $updates[] = "name = :name";
        $params[':name'] = $name;
        if ($sellerName === '' && empty($existing['seller_name'])) {
            $updates[] = "seller_name = :seller_name_synced";
            $params[':seller_name_synced'] = $name;
        }
    }
    if ($address !== '') {
        $updates[] = "address = :address";
        $params[':address'] = $address;
    }
    if ($landmark !== '') {
        $updates[] = "landmark = :landmark";
        $params[':landmark'] = $landmark;
    }
    if ($state !== '') {
        $updates[] = "state = :state";
        $params[':state'] = $state;
    }
    if ($country !== '') {
        $updates[] = "country = :country";
        $params[':country'] = $country;
    }
    if ($phone !== '') {
        $updates[] = "phone = :phone";
        $params[':phone'] = $phone;
    }
    if ($email !== '') {
        $updates[] = "email = :email";
        $params[':email'] = $email;
    }
    if (!empty($password)) {
        $updates[] = "password = :password";
        $params[':password'] = hashPassword($password);
    }
    if ($avatarUrl !== null && hasUserColumn($pdo, 'avatar_url')) {
        $updates[] = "avatar_url = :avatar_url";
        $params[':avatar_url'] = $avatarUrl;
    }

    if (!empty($updates)) {
        $updates[] = "updated_at = NOW()";
        $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }

    // Fetch refreshed user record
    $refreshedStmt = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
    $refreshedStmt->execute([':id' => $sellerId]);
    $updatedUser = $refreshedStmt->fetch();

    // Products count
    $stmtProducts = $pdo->prepare("SELECT COUNT(*) FROM products WHERE seller_id = :seller_id AND is_active = 1");
    $stmtProducts->execute([':seller_id' => $sellerId]);
    $productsCount = (int) $stmtProducts->fetchColumn();

    $sellerData = safeSeller($updatedUser);
    $sellerData['products_count'] = $productsCount;

    jsonResponse([
        'message' => 'Seller profile updated successfully.',
        'seller' => $sellerData,
        'user' => $sellerData
    ]);
}
