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

function formatProductRecord(array $p, bool $isSellerView = false): array
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

    // Parse multiple product images
    $imagesArray = [];
    if (!empty($p['images'])) {
        if (is_array($p['images'])) {
            $imagesArray = $p['images'];
        } elseif (is_string($p['images'])) {
            $decoded = json_decode($p['images'], true);
            if (is_array($decoded)) {
                $imagesArray = $decoded;
            } elseif (strpos($p['images'], ',') !== false) {
                $imagesArray = array_map('trim', explode(',', $p['images']));
            } else {
                $imagesArray = [trim($p['images'])];
            }
        }
    }
    if (empty($imagesArray) && !empty($p['image_url'])) {
        $imagesArray = [$p['image_url']];
    }
    $primaryImageUrl = !empty($imagesArray) ? $imagesArray[0] : ($p['image_url'] ?? null);

    $result = array_merge($p, [
        'id' => (int) $p['id'],
        'seller_id' => (int) $p['seller_id'],
        'name' => (string) ($p['name'] ?? ''),
        'description' => $p['description'] ?? null,
        'stock' => (int) ($p['stock'] ?? 0),
        'image_url' => $primaryImageUrl,
        'images' => $imagesArray,
        'is_active' => (bool) ($p['is_active'] ?? true),
    ]);

    if ($isSellerView) {
        // SELLER FIELD: Commission feature is completely DELETED from seller view!
        // Seller only sees their own price (₹1000.00). No commission, delivery charge, gst, other charges.
        $result['price'] = number_format($seller_price, 2, '.', '');
        $result['seller_price'] = number_format($seller_price, 2, '.', '');
        unset($result['commission'], $result['delivery_charge'], $result['gst'], $result['other_charges']);
    } else {
        // BUYER FIELD: Buyer sees presentation price (₹1138.00) AND the transparent commission breakdown!
        $result['price'] = number_format($presentation_price, 2, '.', '');
        $result['presentation_price'] = number_format($presentation_price, 2, '.', '');
        $result['selling_price'] = number_format($presentation_price, 2, '.', '');
        $result['seller_price'] = number_format($seller_price, 2, '.', '');
        $result['base_price'] = number_format($seller_price, 2, '.', '');
        $result['commission'] = number_format($commission, 2, '.', '');
        $result['delivery_charge'] = number_format($delivery_charge, 2, '.', '');
        $result['gst'] = number_format($gst, 2, '.', '');
        $result['other_charges'] = number_format($other_charges, 2, '.', '');
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

        // Ensure images LONGTEXT column exists for multiple product images
        try {
            $imgCols = $pdo->query("SHOW COLUMNS FROM `products` LIKE 'images'")->fetchAll();
            if (empty($imgCols)) {
                $pdo->exec("ALTER TABLE `products` ADD COLUMN `images` LONGTEXT NULL DEFAULT NULL AFTER `image_url`");
                $pdo->exec("UPDATE `products` SET `images` = JSON_ARRAY(`image_url`) WHERE `images` IS NULL AND `image_url` IS NOT NULL AND `image_url` != ''");
            }
        } catch (\Throwable $e) {}
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
               p.stock, p.image_url, p.images, p.is_active, p.created_at, p.updated_at
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

    // Buyer / Public Catalog: Buyer sees presentation price, commission detail, and multiple images!
    $products = array_map(function($p) {
        return formatProductRecord($p, false);
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
               p.stock, p.image_url, p.images, p.is_active, p.created_at, p.updated_at
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

    $isSellerView = (isset($_GET['seller_view']) && ($_GET['seller_view'] === '1' || $_GET['seller_view'] === 'true'));
    $formatted = formatProductRecord($product, $isSellerView);
    jsonResponse(['product' => $formatted]);
}

function saveUploadedProductImages(array $input = [], ?string $fallback = null): array
{
    $uploadDir = __DIR__ . '/../uploads/products/';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }

    $baseUrl = rtrim(getenv('APP_URL') ?: 'https://knowlipop.shop', '/');
    $collected = [];

    // Helper to process uploaded file
    $processUploadedFile = function($tmp, $origName) use ($uploadDir, $baseUrl, &$collected) {
        if (!empty($tmp) && is_uploaded_file($tmp)) {
            $ext = strtolower(pathinfo($origName ?: 'product.jpg', PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'])) {
                $ext = 'jpg';
            }
            $filename = 'prod_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
            if (move_uploaded_file($tmp, $uploadDir . $filename)) {
                $collected[] = $baseUrl . '/uploads/products/' . $filename;
            }
        }
    };

    // 1. Process $_FILES (both single fields and multiple file array uploads)
    foreach ($_FILES as $field => $fileData) {
        if (is_array($fileData['tmp_name'])) {
            foreach ($fileData['tmp_name'] as $idx => $tmp) {
                $name = $fileData['name'][$idx] ?? 'product.jpg';
                $processUploadedFile($tmp, $name);
            }
        } else {
            $processUploadedFile($fileData['tmp_name'], $fileData['name'] ?? 'product.jpg');
        }
    }

    // Helper to process string candidates (Base64 data URIs or existing HTTP/relative URLs)
    $processCandidate = function($candidate) use ($uploadDir, $baseUrl, &$collected) {
        if (empty($candidate) || !is_string($candidate)) return;
        $trimmed = trim($candidate);
        if (empty($trimmed)) return;

        if (str_starts_with($trimmed, 'data:image/')) {
            if (preg_match('/^data:image\/(\w+);base64,(.+)$/', $trimmed, $matches)) {
                $ext = strtolower($matches[1]);
                if ($ext === 'jpeg') $ext = 'jpg';
                if (!in_array($ext, ['jpg', 'png', 'webp', 'gif', 'svg'])) $ext = 'png';
                $data = base64_decode($matches[2]);
                if ($data !== false) {
                    $filename = 'prod_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                    if (file_put_contents($uploadDir . $filename, $data)) {
                        $collected[] = $baseUrl . '/uploads/products/' . $filename;
                    }
                }
            }
        } elseif (str_starts_with($trimmed, 'http://') || str_starts_with($trimmed, 'https://') || str_starts_with($trimmed, '/uploads/')) {
            $collected[] = str_starts_with($trimmed, '/uploads/') ? ($baseUrl . $trimmed) : $trimmed;
        }
    };

    // 2. Check input['images'] (can be an array, json string, or comma-separated)
    if (!empty($input['images'])) {
        $imgs = $input['images'];
        if (is_string($imgs)) {
            $decoded = json_decode($imgs, true);
            if (is_array($decoded)) {
                $imgs = $decoded;
            } elseif (strpos($imgs, ',') !== false) {
                $imgs = explode(',', $imgs);
            } else {
                $imgs = [$imgs];
            }
        }
        if (is_array($imgs)) {
            foreach ($imgs as $item) {
                $processCandidate($item);
            }
        }
    }

    // 3. Check individual fields
    $candidates = [
        $input['image'] ?? null,
        $input['image_url'] ?? null,
        $input['image_base64'] ?? null,
        $input['file'] ?? null,
        $fallback
    ];
    foreach ($candidates as $cand) {
        $processCandidate($cand);
    }

    // Also check indexed keys like image_0, image_1
    foreach ($input as $k => $v) {
        if (preg_match('/^image_\d+$/i', $k) || preg_match('/^images\[\d+\]$/i', $k)) {
            $processCandidate($v);
        }
    }

    $images = array_values(array_unique(array_filter($collected)));
    $primaryImageUrl = !empty($images) ? $images[0] : null;

    return [
        'primaryImageUrl' => $primaryImageUrl,
        'images' => $images,
        'imagesJson' => !empty($images) ? json_encode($images) : null,
    ];
}

function saveUploadedProductImage(?string $fallback = null): ?string
{
    $input = getJsonInput();
    $res = saveUploadedProductImages($input, $fallback);
    return $res['primaryImageUrl'];
}

function apiUploadImage(PDO $pdo): void
{
    authenticateUser($pdo);
    $input = getJsonInput();
    $res = saveUploadedProductImages($input);
    if (empty($res['images'])) {
        errorResponse('No image uploaded or invalid format.', [
            'image' => ['Please upload an image file (jpg, png, webp) or provide image data/URLs.']
        ], 422);
    }
    jsonResponse([
        'message' => 'Image(s) uploaded successfully.',
        'image_url' => $res['primaryImageUrl'],
        'images' => $res['images'],
        'url' => $res['primaryImageUrl']
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

    // Process image file uploads, base64, or multiple images
    $imgResult = saveUploadedProductImages($input, $input['image_url'] ?? $input['image'] ?? null);

    $pricing = calculatePricingBreakdown($input);

    $stmt = $pdo->prepare("
        INSERT INTO products (seller_id, name, description, seller_price, commission, delivery_charge, gst, other_charges, price, stock, image_url, images, is_active, created_at, updated_at)
        VALUES (:seller_id, :name, :description, :seller_price, :commission, :delivery_charge, :gst, :other_charges, :price, :stock, :image_url, :images, 1, NOW(), NOW())
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
        ':image_url' => $imgResult['primaryImageUrl'],
        ':images' => $imgResult['imagesJson'],
    ]);

    $id = (int) $pdo->lastInsertId();
    $fetchStmt = $pdo->prepare("
        SELECT p.id, p.seller_id, COALESCE(u.seller_name, u.name, 'Verified Seller') AS seller_name,
               p.name, p.description, p.seller_price, p.commission, p.delivery_charge, p.gst, p.other_charges, p.price,
               p.stock, p.image_url, p.images, p.is_active, p.created_at, p.updated_at
        FROM products p
        LEFT JOIN users u ON p.seller_id = u.id
        WHERE p.id = :id
    ");
    $fetchStmt->execute([':id' => $id]);
    $product = $fetchStmt->fetch(PDO::FETCH_ASSOC);

    // Seller created product: Seller view has NO commission feature!
    $formatted = formatProductRecord($product, true);
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
               p.stock, p.image_url, p.images, p.is_active, p.created_at, p.updated_at
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

    // Seller view: Commission feature is deleted from seller field!
    $products = array_map(function($p) {
        return formatProductRecord($p, true);
    }, $rawProducts);

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

    // Process uploaded file(s) or base64 or fallback URL
    $imgResult = saveUploadedProductImages($input, $input['image_url'] ?? $input['image'] ?? null);
    if (!empty($imgResult['images'])) {
        $updates[] = "image_url = :image_url";
        $params[':image_url'] = $imgResult['primaryImageUrl'];
        $updates[] = "images = :images";
        $params[':images'] = $imgResult['imagesJson'];
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
               p.stock, p.image_url, p.images, p.is_active, p.created_at, p.updated_at
        FROM products p
        LEFT JOIN users u ON p.seller_id = u.id
        WHERE p.id = :id
    ");
    $fetchStmt->execute([':id' => $id]);
    $updated = $fetchStmt->fetch(PDO::FETCH_ASSOC);

    // Seller updated product: Seller view has NO commission feature!
    $formatted = formatProductRecord($updated, true);
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
