<?php

/**
 * Pure PHP API: Product Controller with Pricing Breakdown
 */

require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';

function resolveProductPricing(array $input, ?array $existingRow = null): array
{
    $rawPrice = 0.0;
    if (isset($input['price']) && $input['price'] !== '' && is_numeric($input['price'])) {
        $rawPrice = (float) $input['price'];
    } elseif (isset($input['seller_price']) && $input['seller_price'] !== '' && is_numeric($input['seller_price'])) {
        $rawPrice = (float) $input['seller_price'];
    } elseif ($existingRow) {
        $rawPrice = (float) ($existingRow['seller_price'] ?? ($existingRow['price'] ?? 0.0));
    }
    $cleanPrice = max(0.0, round($rawPrice, 2));

    // Maximum Retail Price (MRP)
    $rawMrp = null;
    if (isset($input['mrp']) && $input['mrp'] !== '' && is_numeric($input['mrp'])) {
        $rawMrp = (float) $input['mrp'];
    } elseif ($existingRow && isset($existingRow['mrp']) && is_numeric($existingRow['mrp'])) {
        $rawMrp = (float) $existingRow['mrp'];
    }
    $mrp = ($rawMrp !== null && $rawMrp >= $cleanPrice)
        ? round($rawMrp, 2)
        : ($cleanPrice > 0 ? round($cleanPrice * 1.30, 2) : 0.0);

    // Discount percentage
    $discount_percent = ($mrp > $cleanPrice && $mrp > 0)
        ? (int) round((($mrp - $cleanPrice) / $mrp) * 100)
        : 0;

    // Amazon/Flipkart referral fee preview (5% deduction preview from payout)
    $commission = round($cleanPrice * 0.05, 2);
    $estimated_payout = max(0.0, round($cleanPrice - $commission, 2));

    // Delivery rules
    $delivery_charge = $cleanPrice >= 500 ? 0.00 : 50.00;
    $delivery_text = $cleanPrice >= 500 ? 'FREE Delivery' : 'Standard Delivery (₹50)';

    return [
        'price' => $cleanPrice,
        'seller_price' => $cleanPrice,
        'selling_price' => $cleanPrice,
        'presentation_price' => $cleanPrice,
        'mrp' => $mrp,
        'discount_percent' => $discount_percent,
        'commission_rate' => '5%',
        'commission' => $commission,
        'estimated_fee' => $commission,
        'estimated_payout' => $estimated_payout,
        'delivery_charge' => $delivery_charge,
        'delivery_text' => $delivery_text,
        'gst' => 0.00,
        'other_charges' => 0.00,
    ];
}

function calculatePricingBreakdown(array $input): array
{
    return resolveProductPricing($input);
}

function formatProductRecord(array $p, bool $isSellerView = false): array
{
    $rawPrice = isset($p['seller_price']) && is_numeric($p['seller_price']) && (float) $p['seller_price'] > 0
        ? (float) $p['seller_price']
        : (isset($p['price']) && is_numeric($p['price']) ? (float) $p['price'] : 0.0);
    $cleanPrice = max(0.0, round($rawPrice, 2));

    // Maximum Retail Price (MRP)
    $rawMrp = isset($p['mrp']) && is_numeric($p['mrp']) && (float) $p['mrp'] > 0
        ? (float) $p['mrp']
        : ($cleanPrice > 0 ? round($cleanPrice * 1.30, 2) : 0.0);
    $mrp = max($cleanPrice, round($rawMrp, 2));

    // Discount percentage
    $discount_percent = ($mrp > $cleanPrice && $mrp > 0)
        ? (int) round((($mrp - $cleanPrice) / $mrp) * 100)
        : 0;

    // Amazon/Flipkart referral fee preview
    $commission = round($cleanPrice * 0.05, 2);
    $estimated_payout = max(0.0, round($cleanPrice - $commission, 2));

    // Delivery
    $delivery_charge = $cleanPrice >= 500 ? 0.00 : 50.00;
    $delivery_text = $cleanPrice >= 500 ? 'FREE Delivery' : 'Standard Delivery (₹50)';

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

    $stock = (int) ($p['stock'] ?? 0);
    $rating = isset($p['rating']) && is_numeric($p['rating']) ? (float) $p['rating'] : 4.5;
    $ratingsCount = isset($p['ratings_count']) && is_numeric($p['ratings_count']) ? (int) $p['ratings_count'] : 15;
    $sku = !empty($p['sku']) ? $p['sku'] : ('SKU-' . str_pad((string)($p['id'] ?? 1), 4, '0', STR_PAD_LEFT));
    $category = !empty($p['category']) ? $p['category'] : 'General';
    $brand = !empty($p['brand']) ? $p['brand'] : 'Generic';

    $result = [
        'id' => (int) $p['id'],
        'seller_id' => (int) $p['seller_id'],
        'seller_name' => $p['seller_name'] ?? 'Verified Seller',
        'name' => (string) ($p['name'] ?? ''),
        'description' => $p['description'] ?? null,
        'sku' => $sku,
        'category' => $category,
        'brand' => $brand,
        'stock' => $stock,
        'in_stock' => $stock > 0,
        'rating' => $rating,
        'ratings_count' => $ratingsCount,
        'image_url' => $primaryImageUrl,
        'images' => $imagesArray,
        'is_active' => (bool) ($p['is_active'] ?? true),
        'created_at' => $p['created_at'] ?? null,
        'updated_at' => $p['updated_at'] ?? null,
    ];

    if ($isSellerView) {
        // SELLER FIELD: Amazon / Flipkart Seller Central model
        // Seller sets price (₹1000.00). Strictly ₹1000.00 with ZERO compounding!
        $result['price'] = number_format($cleanPrice, 2, '.', '');
        $result['seller_price'] = number_format($cleanPrice, 2, '.', '');
        $result['selling_price'] = number_format($cleanPrice, 2, '.', '');
        $result['mrp'] = number_format($mrp, 2, '.', '');
        $result['discount_percent'] = $discount_percent;
        $result['commission_rate'] = '5%';
        $result['estimated_fee'] = number_format($commission, 2, '.', '');
        $result['estimated_payout'] = number_format($estimated_payout, 2, '.', '');
    } else {
        // BUYER FIELD: Amazon / Flipkart Marketplace store model
        $result['price'] = number_format($cleanPrice, 2, '.', '');
        $result['selling_price'] = number_format($cleanPrice, 2, '.', '');
        $result['presentation_price'] = number_format($cleanPrice, 2, '.', '');
        $result['seller_price'] = number_format($cleanPrice, 2, '.', '');
        $result['mrp'] = number_format($mrp, 2, '.', '');
        $result['discount_percent'] = $discount_percent;
        $result['delivery_charge'] = number_format($delivery_charge, 2, '.', '');
        $result['delivery_text'] = $delivery_text;
    }

    return $result;
}

function ensureProductPriceColumns(PDO $pdo): void
{
    try {
        $addColumnIfNotExists = function(string $colName, string $colDef) use ($pdo) {
            try {
                $cols = $pdo->query("SHOW COLUMNS FROM `products` LIKE '{$colName}'")->fetchAll();
                if (empty($cols)) {
                    $pdo->exec("ALTER TABLE `products` ADD COLUMN {$colDef}");
                }
            } catch (\Throwable $e) {}
        };

        $addColumnIfNotExists('seller_price', '`seller_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `description`');
        $addColumnIfNotExists('commission', '`commission` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `seller_price`');
        $addColumnIfNotExists('delivery_charge', '`delivery_charge` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `commission`');
        $addColumnIfNotExists('gst', '`gst` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `delivery_charge`');
        $addColumnIfNotExists('other_charges', '`other_charges` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `gst`');
        $addColumnIfNotExists('mrp', '`mrp` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `price`');
        $addColumnIfNotExists('sku', '`sku` VARCHAR(100) NULL AFTER `mrp`');
        $addColumnIfNotExists('category', '`category` VARCHAR(100) NULL AFTER `sku`');
        $addColumnIfNotExists('brand', '`brand` VARCHAR(100) NULL AFTER `category`');
        $addColumnIfNotExists('rating', '`rating` DECIMAL(3,2) NOT NULL DEFAULT 4.50 AFTER `brand`');
        $addColumnIfNotExists('ratings_count', '`ratings_count` INT NOT NULL DEFAULT 15 AFTER `rating`');
        $addColumnIfNotExists('images', '`images` LONGTEXT NULL DEFAULT NULL AFTER `image_url`');

        // Safe database repair:
        // 1. Recover any prices that compounded above seller_price
        try {
            $pdo->exec("UPDATE `products` SET `price` = `seller_price` WHERE `seller_price` > 0 AND `price` > `seller_price`");
        } catch (\Throwable $e) {}
        // 2. Ensure seller_price is set
        try {
            $pdo->exec("UPDATE `products` SET `seller_price` = `price` WHERE (`seller_price` = 0.00 OR `seller_price` IS NULL) AND `price` > 0");
        } catch (\Throwable $e) {}
        // 3. Ensure MRP is populated
        try {
            $pdo->exec("UPDATE `products` SET `mrp` = ROUND(`price` * 1.30, 2) WHERE (`mrp` IS NULL OR `mrp` = 0.00) AND `price` > 0");
        } catch (\Throwable $e) {}
        // 4. Ensure SKU is set
        try {
            $pdo->exec("UPDATE `products` SET `sku` = CONCAT('SKU-', LPAD(`id`, 4, '0')) WHERE `sku` IS NULL OR `sku` = ''");
        } catch (\Throwable $e) {}
        // 5. Ensure images JSON array is populated if images is null
        try {
            $pdo->exec("UPDATE `products` SET `images` = JSON_ARRAY(`image_url`) WHERE `images` IS NULL AND `image_url` IS NOT NULL AND `image_url` != ''");
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
               p.name, p.description, p.sku, p.category, p.brand, p.mrp, p.rating, p.ratings_count,
               p.seller_price, p.commission, p.delivery_charge, p.gst, p.other_charges, p.price,
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
               p.name, p.description, p.sku, p.category, p.brand, p.mrp, p.rating, p.ratings_count,
               p.seller_price, p.commission, p.delivery_charge, p.gst, p.other_charges, p.price,
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

    $pricing = resolveProductPricing($input);
    $sku = trim((string)($input['sku'] ?? '')) ?: null;
    $category = trim((string)($input['category'] ?? '')) ?: 'General';
    $brand = trim((string)($input['brand'] ?? '')) ?: 'Generic';
    $mrp = (float) $pricing['mrp'];

    $stmt = $pdo->prepare("
        INSERT INTO products (seller_id, name, description, sku, category, brand, mrp, seller_price, commission, delivery_charge, gst, other_charges, price, stock, image_url, images, is_active, created_at, updated_at)
        VALUES (:seller_id, :name, :description, :sku, :category, :brand, :mrp, :seller_price, :commission, :delivery_charge, :gst, :other_charges, :price, :stock, :image_url, :images, 1, NOW(), NOW())
    ");
    $stmt->execute([
        ':seller_id' => $user['id'],
        ':name' => $name,
        ':description' => $input['description'] ?? null,
        ':sku' => $sku,
        ':category' => $category,
        ':brand' => $brand,
        ':mrp' => number_format($mrp, 2, '.', ''),
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
               p.name, p.description, p.sku, p.category, p.brand, p.mrp, p.rating, p.ratings_count,
               p.seller_price, p.commission, p.delivery_charge, p.gst, p.other_charges, p.price,
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
               p.name, p.description, p.sku, p.category, p.brand, p.mrp, p.rating, p.ratings_count,
               p.seller_price, p.commission, p.delivery_charge, p.gst, p.other_charges, p.price,
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
        (isset($input['price']) && $input['price'] !== '' && is_numeric($input['price'])) ||
        (isset($input['seller_price']) && $input['seller_price'] !== '' && is_numeric($input['seller_price']))
    );

    if ($pricingModified) {
        $rawVal = isset($input['price']) && $input['price'] !== ''
            ? (float) $input['price']
            : (float) $input['seller_price'];
        $cleanPrice = max(0.0, round($rawVal, 2));
        $updates[] = "price = :price";
        $params[':price'] = number_format($cleanPrice, 2, '.', '');
        $updates[] = "seller_price = :seller_price";
        $params[':seller_price'] = number_format($cleanPrice, 2, '.', '');
    }

    if (isset($input['mrp']) && $input['mrp'] !== '' && is_numeric($input['mrp'])) {
        $updates[] = "mrp = :mrp";
        $params[':mrp'] = number_format(max(0.0, round((float)$input['mrp'], 2)), 2, '.', '');
    }
    if (isset($input['sku'])) {
        $updates[] = "sku = :sku";
        $params[':sku'] = trim((string)$input['sku']);
    }
    if (isset($input['category'])) {
        $updates[] = "category = :category";
        $params[':category'] = trim((string)$input['category']);
    }
    if (isset($input['brand'])) {
        $updates[] = "brand = :brand";
        $params[':brand'] = trim((string)$input['brand']);
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
               p.name, p.description, p.sku, p.category, p.brand, p.mrp, p.rating, p.ratings_count,
               p.seller_price, p.commission, p.delivery_charge, p.gst, p.other_charges, p.price,
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
