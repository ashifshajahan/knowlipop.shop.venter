<?php

/**
 * Pure PHP API: Product Controller with Pricing Breakdown
 */

require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';

function calculatePricingBreakdown(array $input): array
{
    $rawPrice = isset($input['seller_price']) && $input['seller_price'] !== ''
        ? (float) $input['seller_price']
        : (isset($input['price']) && $input['price'] !== '' ? (float) $input['price'] : 0.0);

    $seller_price = max(0.0, round($rawPrice, 2));

    if (isset($input['commission']) && $input['commission'] !== '' && is_numeric($input['commission'])) {
        $commission = max(0.0, round((float) $input['commission'], 2));
    } else {
        $commission = round($seller_price * 0.05, 2);
    }

    $rawDel = $input['delivery_charge'] ?? $input['shipping_charge'] ?? $input['delivery_fee'] ?? null;
    if ($rawDel !== null && $rawDel !== '' && is_numeric($rawDel)) {
        $delivery_charge = max(0.0, round((float) $rawDel, 2));
    } else {
        $delivery_charge = 50.00;
    }

    $rawGst = $input['gst'] ?? $input['tax'] ?? $input['tax_amount'] ?? null;
    if ($rawGst !== null && $rawGst !== '' && is_numeric($rawGst)) {
        $gst = max(0.0, round((float) $rawGst, 2));
    } else {
        $feeBase = $commission + $delivery_charge;
        $gst = round($feeBase * 0.18, 2);
        if ($gst == 0.0 && $seller_price > 0) {
            $gst = 18.00;
        }
    }

    $rawOther = $input['other_charges'] ?? $input['handling_fee'] ?? $input['platform_fee'] ?? null;
    if ($rawOther !== null && $rawOther !== '' && is_numeric($rawOther)) {
        $other_charges = max(0.0, round((float) $rawOther, 2));
    } else {
        $other_charges = 20.00;
    }

    $presentation_price = round($seller_price + $commission + $delivery_charge + $gst + $other_charges, 2);

    return [
        'seller_price' => $seller_price,
        'commission' => $commission,
        'delivery_charge' => $delivery_charge,
        'gst' => $gst,
        'other_charges' => $other_charges,
        'price' => $presentation_price,
        'presentation_price' => $presentation_price,
        'selling_price' => $presentation_price,
    ];
}

function formatProductRecord(array $p, bool $isPublicUser = false): array
{
    $rawSeller = isset($p['seller_price']) && is_numeric($p['seller_price']) && (float) $p['seller_price'] > 0
        ? (float) $p['seller_price']
        : (isset($p['price']) && is_numeric($p['price']) ? (float) $p['price'] : 0.0);

    $seller_price = round($rawSeller, 2);

    $commission = isset($p['commission']) && is_numeric($p['commission']) && (float) $p['commission'] > 0
        ? round((float) $p['commission'], 2)
        : round($seller_price * 0.05, 2);

    $delivery_charge = isset($p['delivery_charge']) && is_numeric($p['delivery_charge']) && (float) $p['delivery_charge'] > 0
        ? round((float) $p['delivery_charge'], 2)
        : 50.00;

    $gst = isset($p['gst']) && is_numeric($p['gst']) && (float) $p['gst'] > 0
        ? round((float) $p['gst'], 2)
        : round(($commission + $delivery_charge) * 0.18, 2);

    $other_charges = isset($p['other_charges']) && is_numeric($p['other_charges']) && (float) $p['other_charges'] > 0
        ? round((float) $p['other_charges'], 2)
        : 20.00;

    $presentation_price = isset($p['price']) && is_numeric($p['price'])
        ? round((float) $p['price'], 2)
        : round($seller_price + $commission + $delivery_charge + $gst + $other_charges, 2);

    if (abs($presentation_price - $seller_price) < 0.001 && $seller_price > 0) {
        $presentation_price = round($seller_price + $commission + $delivery_charge + $gst + $other_charges, 2);
    }

    $result = array_merge($p, [
        'id' => (int) $p['id'],
        'seller_id' => (int) $p['seller_id'],
        'name' => (string) ($p['name'] ?? ''),
        'description' => $p['description'] ?? null,
        'price' => number_format($presentation_price, 2, '.', ''),
        'presentation_price' => number_format($presentation_price, 2, '.', ''),
        'selling_price' => number_format($presentation_price, 2, '.', ''),
        'stock' => (int) ($p['stock'] ?? 0),
        'image_url' => $p['image_url'] ?? null,
        'is_active' => (bool) ($p['is_active'] ?? true),
    ]);

    if (!$isPublicUser) {
        $result['seller_price'] = number_format($seller_price, 2, '.', '');
        $result['commission'] = number_format($commission, 2, '.', '');
        $result['delivery_charge'] = number_format($delivery_charge, 2, '.', '');
        $result['gst'] = number_format($gst, 2, '.', '');
        $result['other_charges'] = number_format($other_charges, 2, '.', '');
    } else {
        unset($result['seller_price'], $result['commission'], $result['other_charges']);
    }

    return $result;
}

function ensureProductPriceColumns(PDO $pdo): void
{
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM `products` LIKE 'seller_price'")->fetchAll();
        if (empty($cols)) {
            try { $pdo->exec("ALTER TABLE `products` ADD COLUMN `seller_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `description`"); } catch (\Throwable $e) {}
            try { $pdo->exec("ALTER TABLE `products` ADD COLUMN `commission` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `seller_price`"); } catch (\Throwable $e) {}
            try { $pdo->exec("ALTER TABLE `products` ADD COLUMN `delivery_charge` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `commission`"); } catch (\Throwable $e) {}
            try { $pdo->exec("ALTER TABLE `products` ADD COLUMN `gst` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `delivery_charge`"); } catch (\Throwable $e) {}
            try { $pdo->exec("ALTER TABLE `products` ADD COLUMN `other_charges` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `gst`"); } catch (\Throwable $e) {}

            try {
                $pdo->exec("
                    UPDATE `products`
                    SET
                      `seller_price` = `price`,
                      `commission` = ROUND(`price` * 0.05, 2),
                      `delivery_charge` = 50.00,
                      `gst` = ROUND((ROUND(`price` * 0.05, 2) + 50.00) * 0.18, 2),
                      `other_charges` = 20.00,
                      `price` = ROUND(`price` + ROUND(`price` * 0.05, 2) + 50.00 + ROUND((ROUND(`price` * 0.05, 2) + 50.00) * 0.18, 2) + 20.00, 2)
                    WHERE `seller_price` = 0.00 OR `seller_price` IS NULL
                ");
            } catch (\Throwable $e) {}
        } else {
            try {
                $pdo->exec("
                    UPDATE `products`
                    SET
                      `seller_price` = `price`,
                      `commission` = ROUND(`price` * 0.05, 2),
                      `delivery_charge` = 50.00,
                      `gst` = ROUND((ROUND(`price` * 0.05, 2) + 50.00) * 0.18, 2),
                      `other_charges` = 20.00,
                      `price` = ROUND(`price` + ROUND(`price` * 0.05, 2) + 50.00 + ROUND((ROUND(`price` * 0.05, 2) + 50.00) * 0.18, 2) + 20.00, 2)
                    WHERE (`seller_price` = 0.00 OR `seller_price` IS NULL) AND `price` > 0
                ");
            } catch (\Throwable $e) {}
        }
    } catch (\Throwable $e) {
        // ignore
    }
}

function apiGetProducts(PDO $pdo): void
{
    ensureProductPriceColumns($pdo);
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;
    $sellerId = !empty($_GET['seller_id']) ? (int) $_GET['seller_id'] : null;

    $where = "WHERE p.is_active = 1";
    $params = [];
    if ($sellerId !== null) {
        $where .= " AND p.seller_id = :seller_id";
        $params[':seller_id'] = $sellerId;
    }

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM products p {$where}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT p.id, p.seller_id, COALESCE(u.seller_name, u.name, 'Verified Seller') AS seller_name,
               p.name, p.description, p.seller_price, p.commission, p.delivery_charge, p.gst, p.other_charges, p.price,
               p.stock, p.image_url, p.is_active, p.created_at, p.updated_at
        FROM products p
        LEFT JOIN users u ON p.seller_id = u.id
        {$where}
        ORDER BY p.id DESC
        LIMIT :limit OFFSET :offset
    ");
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, PDO::PARAM_INT);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rawProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $optUser = getAuthenticatedUserOptional($pdo);
    $isSellerOrAdmin = $optUser && ((($optUser['role'] ?? '') === 'admin') || ($sellerId && (int)$optUser['id'] === $sellerId));
    $products = array_map(function($p) use ($isSellerOrAdmin) {
        return formatProductRecord($p, !$isSellerOrAdmin);
    }, $rawProducts);

    jsonResponse([
        'products' => [
            'current_page' => $page,
            'data' => $products,
            'total' => $total,
            'per_page' => $limit,
            'last_page' => ceil($total / $limit),
        ]
    ]);
}

function apiGetProduct(PDO $pdo, int $id): void
{
    ensureProductPriceColumns($pdo);
    $stmt = $pdo->prepare("
        SELECT p.id, p.seller_id, COALESCE(u.seller_name, u.name, 'Verified Seller') AS seller_name,
               p.name, p.description, p.seller_price, p.commission, p.delivery_charge, p.gst, p.other_charges, p.price,
               p.stock, p.image_url, p.is_active, p.created_at, p.updated_at
        FROM products p
        LEFT JOIN users u ON p.seller_id = u.id
        WHERE p.id = :id AND p.is_active = 1
        LIMIT 1
    ");
    $stmt->execute([':id' => $id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        errorResponse('Product not found.', [], 404);
    }

    $optUser = getAuthenticatedUserOptional($pdo);
    $isOwner = $optUser && ((($optUser['role'] ?? '') === 'admin') || ((int)$optUser['id'] === (int)$product['seller_id']));
    $formatted = formatProductRecord($product, !$isOwner);
    jsonResponse(['product' => $formatted]);
}

function saveUploadedProductImage(?string $fallback = null): ?string
{
    $uploadDir = __DIR__ . '/../uploads/products/';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }

    $baseUrl = rtrim(getenv('APP_URL') ?: 'https://knowlipop.shop', '/');

    // 1. Check $_FILES for multipart/form-data upload
    $fileFields = ['image', 'file', 'product_image', 'photo', 'picture', 'img'];
    foreach ($fileFields as $field) {
        if (!empty($_FILES[$field]['tmp_name']) && is_uploaded_file($_FILES[$field]['tmp_name'])) {
            $tmp = $_FILES[$field]['tmp_name'];
            $origName = $_FILES[$field]['name'] ?? 'product.jpg';
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'])) {
                $ext = 'jpg';
            }
            $filename = 'prod_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
            $destination = $uploadDir . $filename;
            if (move_uploaded_file($tmp, $destination)) {
                return $baseUrl . '/uploads/products/' . $filename;
            }
        }
    }

    // 2. Check for Base64 encoded image string in input
    $input = getJsonInput();
    $base64Candidates = [$input['image'] ?? null, $input['image_base64'] ?? null, $input['file'] ?? null, $fallback];
    foreach ($base64Candidates as $candidate) {
        if (is_string($candidate) && str_starts_with($candidate, 'data:image/')) {
            if (preg_match('/^data:image\/(\w+);base64,(.+)$/', $candidate, $matches)) {
                $ext = strtolower($matches[1]);
                if ($ext === 'jpeg') $ext = 'jpg';
                if (!in_array($ext, ['jpg', 'png', 'webp', 'gif', 'svg'])) $ext = 'png';
                $data = base64_decode($matches[2]);
                if ($data !== false) {
                    $filename = 'prod_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                    if (file_put_contents($uploadDir . $filename, $data)) {
                        return $baseUrl . '/uploads/products/' . $filename;
                    }
                }
            }
        }
    }

    // 3. Fallback: if user supplied an already hosted URL (e.g. https://...), keep it
    if (!empty($fallback) && (str_starts_with($fallback, 'http://') || str_starts_with($fallback, 'https://') || str_starts_with($fallback, '/uploads/'))) {
        return $fallback;
    }

    return null;
}

function apiUploadImage(PDO $pdo): void
{
    authenticateUser($pdo);
    $imageUrl = saveUploadedProductImage();
    if (!$imageUrl) {
        errorResponse('No image uploaded or invalid format.', [
            'image' => ['Please upload an image file (jpg, png, webp) or provide base64 image data.']
        ], 422);
    }
    jsonResponse([
        'message' => 'Image uploaded successfully.',
        'image_url' => $imageUrl,
        'url' => $imageUrl
    ], 201);
}

function apiCreateProduct(PDO $pdo): void
{
    $user = authenticateUser($pdo);
    ensureProductPriceColumns($pdo);
    $input = getJsonInput();
    $errors = [];

    $name = trim($input['name'] ?? '');
    $rawPrice = $input['seller_price'] ?? ($input['price'] ?? null);
    $stock = $input['stock'] ?? null;

    if (empty($name)) {
        $errors['name'] = ['The name field is required.'];
    }
    if (!isset($rawPrice) || !is_numeric($rawPrice) || $rawPrice < 0) {
        $errors['price'] = ['The price field is required and must be a positive number.'];
    }
    if (!isset($stock) || !is_numeric($stock) || $stock < 0) {
        $errors['stock'] = ['The stock field is required and must be an integer.'];
    }

    if (!empty($errors)) {
        errorResponse('Validation error occurred.', $errors, 422);
    }

    // Process image file upload, base64, or fallback URL
    $imageUrl = saveUploadedProductImage($input['image_url'] ?? $input['image'] ?? null);

    $pricing = calculatePricingBreakdown($input);

    $stmt = $pdo->prepare("
        INSERT INTO products (seller_id, name, description, seller_price, commission, delivery_charge, gst, other_charges, price, stock, image_url, is_active, created_at, updated_at)
        VALUES (:seller_id, :name, :description, :seller_price, :commission, :delivery_charge, :gst, :other_charges, :price, :stock, :image_url, 1, NOW(), NOW())
    ");
    $stmt->execute([
        ':seller_id' => $user['id'],
        ':name' => $name,
        ':description' => $input['description'] ?? null,
        ':seller_price' => number_format($pricing['seller_price'], 2, '.', ''),
        ':commission' => number_format($pricing['commission'], 2, '.', ''),
        ':delivery_charge' => number_format($pricing['delivery_charge'], 2, '.', ''),
        ':gst' => number_format($pricing['gst'], 2, '.', ''),
        ':other_charges' => number_format($pricing['other_charges'], 2, '.', ''),
        ':price' => number_format($pricing['price'], 2, '.', ''),
        ':stock' => (int) $stock,
        ':image_url' => $imageUrl,
    ]);

    $id = (int) $pdo->lastInsertId();
    $fetchStmt = $pdo->prepare("
        SELECT p.id, p.seller_id, COALESCE(u.seller_name, u.name, 'Verified Seller') AS seller_name,
               p.name, p.description, p.seller_price, p.commission, p.delivery_charge, p.gst, p.other_charges, p.price,
               p.stock, p.image_url, p.is_active, p.created_at, p.updated_at
        FROM products p
        LEFT JOIN users u ON p.seller_id = u.id
        WHERE p.id = :id
    ");
    $fetchStmt->execute([':id' => $id]);
    $product = $fetchStmt->fetch(PDO::FETCH_ASSOC);

    $formatted = formatProductRecord($product);
    jsonResponse(['message' => 'Product created.', 'product' => $formatted], 201);
}

function apiGetSellerProducts(PDO $pdo, ?int $routeSellerId = null): void
{
    $user = authenticateUser($pdo);
    ensureProductPriceColumns($pdo);
    $targetSellerId = $routeSellerId ?: (!empty($_GET['seller_id']) ? (int)$_GET['seller_id'] : (int)$user['id']);

    if (($user['role'] ?? '') !== 'admin' && (int)$user['id'] !== $targetSellerId) {
        errorResponse('Access denied. You can only access your own products.', [], 403);
    }

    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $where = "WHERE p.seller_id = :seller_id";
    $params = [':seller_id' => $targetSellerId];

    if (isset($_GET['is_active']) && $_GET['is_active'] !== '') {
        $where .= " AND p.is_active = :is_active";
        $params[':is_active'] = (int)$_GET['is_active'];
    }

    if (!empty($_GET['search'])) {
        $where .= " AND (p.name LIKE :s1 OR p.description LIKE :s2)";
        $params[':s1'] = '%' . trim($_GET['search']) . '%';
        $params[':s2'] = '%' . trim($_GET['search']) . '%';
    }

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM products p {$where}");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT p.id, p.seller_id, COALESCE(u.seller_name, u.name, 'Verified Seller') AS seller_name,
               p.name, p.description, p.seller_price, p.commission, p.delivery_charge, p.gst, p.other_charges, p.price,
               p.stock, p.image_url, p.is_active, p.created_at, p.updated_at
        FROM products p
        LEFT JOIN users u ON p.seller_id = u.id
        {$where}
        ORDER BY p.id DESC
        LIMIT :limit OFFSET :offset
    ");
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rawProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $products = array_map('formatProductRecord', $rawProducts);

    jsonResponse([
        'seller_id' => $targetSellerId,
        'products' => [
            'current_page' => $page,
            'data' => $products,
            'total' => $total,
            'per_page' => $limit,
            'last_page' => (int)ceil($total / $limit) ?: 1,
        ]
    ]);
}

function apiUpdateProduct(PDO $pdo, int $id): void
{
    $user = authenticateUser($pdo);
    ensureProductPriceColumns($pdo);
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        errorResponse('Product not found.', [], 404);
    }

    if (($user['role'] ?? '') !== 'admin' && (int)$product['seller_id'] !== (int)$user['id']) {
        errorResponse('Access denied. You do not own this product.', [], 403);
    }

    $input = getJsonInput();
    $updates = [];
    $params = [':id' => $id];

    if (isset($input['name']) && trim($input['name']) !== '') {
        $updates[] = "name = :name";
        $params[':name'] = trim($input['name']);
    }
    if (isset($input['description'])) {
        $updates[] = "description = :description";
        $params[':description'] = $input['description'];
    }

    $pricingModified = (
        isset($input['seller_price']) ||
        isset($input['price']) ||
        isset($input['commission']) ||
        isset($input['delivery_charge']) ||
        isset($input['shipping_charge']) ||
        isset($input['gst']) ||
        isset($input['tax']) ||
        isset($input['other_charges'])
    );

    if ($pricingModified) {
        $mergedPricingInput = [
            'seller_price' => $input['seller_price'] ?? ($input['price'] ?? ($product['seller_price'] ?? $product['price'])),
            'commission' => $input['commission'] ?? ($product['commission'] ?? null),
            'delivery_charge' => $input['delivery_charge'] ?? ($input['shipping_charge'] ?? ($product['delivery_charge'] ?? null)),
            'gst' => $input['gst'] ?? ($input['tax'] ?? ($product['gst'] ?? null)),
            'other_charges' => $input['other_charges'] ?? ($product['other_charges'] ?? null),
        ];
        $newPricing = calculatePricingBreakdown($mergedPricingInput);
        $updates[] = "seller_price = :seller_price";
        $params[':seller_price'] = number_format($newPricing['seller_price'], 2, '.', '');
        $updates[] = "commission = :commission";
        $params[':commission'] = number_format($newPricing['commission'], 2, '.', '');
        $updates[] = "delivery_charge = :delivery_charge";
        $params[':delivery_charge'] = number_format($newPricing['delivery_charge'], 2, '.', '');
        $updates[] = "gst = :gst";
        $params[':gst'] = number_format($newPricing['gst'], 2, '.', '');
        $updates[] = "other_charges = :other_charges";
        $params[':other_charges'] = number_format($newPricing['other_charges'], 2, '.', '');
        $updates[] = "price = :price";
        $params[':price'] = number_format($newPricing['price'], 2, '.', '');
    }

    if (isset($input['stock']) && is_numeric($input['stock'])) {
        $updates[] = "stock = :stock";
        $params[':stock'] = (int) $input['stock'];
    }

    // Process uploaded file or base64 or fallback URL
    $newImageUrl = saveUploadedProductImage($input['image_url'] ?? $input['image'] ?? null);
    if (!empty($newImageUrl)) {
        $updates[] = "image_url = :image_url";
        $params[':image_url'] = $newImageUrl;
    }

    if (isset($input['is_active'])) {
        $updates[] = "is_active = :is_active";
        $params[':is_active'] = $input['is_active'] ? 1 : 0;
    }

    if (!empty($updates)) {
        $updates[] = "updated_at = NOW()";
        $sql = "UPDATE products SET " . implode(', ', $updates) . " WHERE id = :id";
        $updStmt = $pdo->prepare($sql);
        $updStmt->execute($params);
    }

    $fetchStmt = $pdo->prepare("
        SELECT p.id, p.seller_id, COALESCE(u.seller_name, u.name, 'Verified Seller') AS seller_name,
               p.name, p.description, p.seller_price, p.commission, p.delivery_charge, p.gst, p.other_charges, p.price,
               p.stock, p.image_url, p.is_active, p.created_at, p.updated_at
        FROM products p
        LEFT JOIN users u ON p.seller_id = u.id
        WHERE p.id = :id
    ");
    $fetchStmt->execute([':id' => $id]);
    $updated = $fetchStmt->fetch(PDO::FETCH_ASSOC);

    $formatted = formatProductRecord($updated);
    jsonResponse(['message' => 'Product updated.', 'product' => $formatted]);
}

function apiDeleteProduct(PDO $pdo, int $id): void
{
    $user = authenticateUser($pdo);
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        errorResponse('Product not found.', [], 404);
    }

    if (($user['role'] ?? '') !== 'admin' && (int)$product['seller_id'] !== (int)$user['id']) {
        errorResponse('Access denied. You do not own this product.', [], 403);
    }

    $delStmt = $pdo->prepare("DELETE FROM products WHERE id = :id");
    $delStmt->execute([':id' => $id]);

    jsonResponse(['message' => 'Product deleted.']);
}
