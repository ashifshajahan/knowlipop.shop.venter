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

$distinctProductGalleries = [
    'headphones' => [
        'https://images.unsplash.com/photo-1505740420928-5e560c06d30e?w=800',
        'https://images.unsplash.com/photo-1583394838336-acd977736f90?w=800',
        'https://images.unsplash.com/photo-1484704849700-f032a568e944?w=800'
    ],
    'watch' => [
        'https://images.unsplash.com/photo-1523275335684-37898b6baf30?w=800',
        'https://images.unsplash.com/photo-1508685096489-7aacd43bd3b1?w=800',
        'https://images.unsplash.com/photo-1579586337278-3befd40fd17a?w=800'
    ],
    'backpack' => [
        'https://images.unsplash.com/photo-1553062407-98eeb64c6a62?w=800',
        'https://images.unsplash.com/photo-1622560480605-d83c853bc5c3?w=800',
        'https://images.unsplash.com/photo-1546938576-6e6a64f317cc?w=800'
    ],
    'sneakers' => [
        'https://images.unsplash.com/photo-1542291026-7eec264c27ff?w=800',
        'https://images.unsplash.com/photo-1608231387042-66d1773070a5?w=800',
        'https://images.unsplash.com/photo-1600185365926-3a2ce3cdb9eb?w=800'
    ],
    'sunglasses' => [
        'https://images.unsplash.com/photo-1511499767150-a48a237f0083?w=800',
        'https://images.unsplash.com/photo-1572635196237-14b3f281503f?w=800',
        'https://images.unsplash.com/photo-1577803645773-f96470509666?w=800'
    ],
    'keyboard' => [
        'https://images.unsplash.com/photo-1587829741301-dc798b83add3?w=800',
        'https://images.unsplash.com/photo-1618384887929-16ec33fab9ef?w=800',
        'https://images.unsplash.com/photo-1595225476474-87563907a212?w=800'
    ],
    'earbuds' => [
        'https://images.unsplash.com/photo-1590658268037-6bf12165a8df?w=800',
        'https://images.unsplash.com/photo-1606220588913-b3aacb4d2f46?w=800',
        'https://images.unsplash.com/photo-1572536147248-ac59a8abfa4b?w=800'
    ],
    'mouse' => [
        'https://images.unsplash.com/photo-1527864550417-7fd91fc51a46?w=800',
        'https://images.unsplash.com/photo-1615663245857-ac93bb7c39e7?w=800',
        'https://images.unsplash.com/photo-1626218174358-7769486c4b79?w=800'
    ],
    'general' => [
        'https://images.unsplash.com/photo-1526170375885-4d8ecf77b99f?w=800',
        'https://images.unsplash.com/photo-1560343090-f0409e92791a?w=800',
        'https://images.unsplash.com/photo-1546868871-7041f2a55e12?w=800'
    ]
];

function getDistinctDefaultImages(string $name = '', string $category = '', int $id = 0): array
{
    global $distinctProductGalleries;
    $text = strtolower($name . ' ' . $category);
    $gallery = $distinctProductGalleries['general'];

    if (strpos($text, 'headphone') !== false || strpos($text, 'headset') !== false) {
        $gallery = $distinctProductGalleries['headphones'];
    } elseif (strpos($text, 'watch') !== false || strpos($text, 'wearable') !== false) {
        $gallery = $distinctProductGalleries['watch'];
    } elseif (strpos($text, 'backpack') !== false || strpos($text, 'bag') !== false || strpos($text, 'luggage') !== false) {
        $gallery = $distinctProductGalleries['backpack'];
    } elseif (strpos($text, 'sneaker') !== false || strpos($text, 'shoe') !== false || strpos($text, 'footwear') !== false) {
        $gallery = $distinctProductGalleries['sneakers'];
    } elseif (strpos($text, 'glass') !== false || strpos($text, 'aviator') !== false || strpos($text, 'opti') !== false) {
        $gallery = $distinctProductGalleries['sunglasses'];
    } elseif (strpos($text, 'keyboard') !== false) {
        $gallery = $distinctProductGalleries['keyboard'];
    } elseif (strpos($text, 'earbud') !== false || strpos($text, 'audio') !== false) {
        $gallery = $distinctProductGalleries['earbuds'];
    } elseif (strpos($text, 'mouse') !== false) {
        $gallery = $distinctProductGalleries['mouse'];
    }

    return array_slice($gallery, 0, 4);
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

    $baseUrl = rtrim(getenv('APP_URL') ?: 'https://knowlipop.shop', '/');
    $normalizeUrl = function(?string $u) use ($baseUrl): ?string {
        if (!$u) return null;
        $t = trim($u);
        if (empty($t) || in_array($t, ['null', 'undefined', '[]', '{}'], true)) return null;
        while ((str_starts_with($t, '"') && str_ends_with($t, '"')) || (str_starts_with($t, "'") && str_ends_with($t, "'"))) {
            $t = trim(substr($t, 1, -1));
        }
        if (empty($t) || str_starts_with($t, 'blob:') || str_starts_with($t, 'file:') || str_starts_with($t, '/data/user/') || str_starts_with($t, '/var/mobile/')) return null;
        if (str_starts_with($t, '//')) return 'https:' . $t;
        if (str_starts_with($t, 'http://') || str_starts_with($t, 'https://')) {
            if (str_starts_with($t, 'http://localhost') || str_starts_with($t, 'http://127.0.0.1')) {
                return preg_replace('/^http:\/\/(localhost|127\.0\.0\.1)(:\d+)?/', $baseUrl, $t);
            }
            return $t;
        }
        if (str_starts_with($t, 'data:image/')) return $t;
        if (str_starts_with($t, '/uploads/') || str_starts_with($t, 'uploads/') || str_starts_with($t, '/storage/') || str_starts_with($t, 'storage/') || str_starts_with($t, '/public/uploads/') || str_starts_with($t, 'public/uploads/')) {
            $clean = ltrim(preg_replace('/^\/?(storage\/|public\/uploads\/)/', 'uploads/', $t), '/');
            return $baseUrl . '/' . $clean;
        }
        if (preg_match('/^prod_\d+_[a-f0-9]+\.(jpg|jpeg|png|webp|gif|svg)$/i', $t) || preg_match('/^placeholder\.(jpg|jpeg|png)$/i', $t) || preg_match('/^[a-zA-Z0-9_\-\.]+\.(jpg|jpeg|png|webp)$/i', $t)) {
            return $baseUrl . '/uploads/products/' . $t;
        }
        return $t;
    };

    // Parse multiple product images across all supported formats and aliases
    $imagesArray = [];
    $gallerySources = [
        $p['images'] ?? null,
        $p['image_urls'] ?? null,
        $p['product_images'] ?? null,
        $p['gallery'] ?? null,
        $p['photos'] ?? null,
    ];

    foreach ($gallerySources as $src) {
        if (empty($src)) continue;
        if (is_array($src)) {
            foreach ($src as $item) {
                if (is_string($item)) {
                    $norm = $normalizeUrl($item);
                    if ($norm) $imagesArray[] = $norm;
                }
            }
        } elseif (is_string($src) && trim($src)) {
            $decoded = json_decode($src, true);
            if (is_array($decoded)) {
                foreach ($decoded as $item) {
                    if (is_string($item)) {
                        $norm = $normalizeUrl($item);
                        if ($norm) $imagesArray[] = $norm;
                    }
                }
                if (!empty($imagesArray)) break;
                continue;
            }
            if (preg_match('/[,;\n\r|]/', $src)) {
                foreach (preg_split('/[,;\n\r|]+/', $src) as $item) {
                    $norm = $normalizeUrl($item);
                    if ($norm) $imagesArray[] = $norm;
                }
            } else {
                $norm = $normalizeUrl($src);
                if ($norm) $imagesArray[] = $norm;
            }
        }
        if (!empty($imagesArray)) break;
    }

    // If no multi-image gallery found, fall back to single image fields
    if (empty($imagesArray)) {
        $singleSources = [
            $p['image_url'] ?? null,
            $p['image'] ?? null,
            $p['product_image'] ?? null,
            $p['photo'] ?? null,
            $p['thumbnail'] ?? null,
            $p['path'] ?? null,
        ];
        foreach ($singleSources as $src) {
            if (is_string($src)) {
                $norm = $normalizeUrl($src);
                if ($norm) {
                    $imagesArray[] = $norm;
                    break;
                }
            }
        }
    }

    // Deduplicate
    $imagesArray = array_values(array_unique(array_filter($imagesArray)));

    // Fallback: If no valid images could be resolved, supply clean distinct category default images!
    $prodName = (string)($p['name'] ?? '');
    $prodCat = (string)($p['category'] ?? '');
    $prodId = (int)($p['id'] ?? 0);
    if (empty($imagesArray)) {
        $imagesArray = array_slice(getDistinctDefaultImages($prodName, $prodCat, $prodId), 0, 4);
    }

    // Strictly enforce seller multiple URL limit of 4 images
    $imagesArray = array_slice($imagesArray, 0, 4);
    $primaryImageUrl = !empty($imagesArray) ? $imagesArray[0] : ($baseUrl . '/uploads/products/placeholder.jpg');
    $relativePaths = array_map(function($u) {
        $fn = basename(explode('?', $u)[0]);
        return str_contains($u, '/uploads/products/') ? 'uploads/products/' . $fn : $u;
    }, $imagesArray);
    $primaryRelative = !empty($relativePaths) ? '/' . $relativePaths[0] : null;

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
        'image' => $primaryImageUrl,
        'product_image' => $primaryImageUrl,
        'thumbnail' => $primaryImageUrl,
        'photo' => $primaryImageUrl,
        'path' => $primaryRelative,
        'relative_path' => $relativePaths[0] ?? null,
        'images' => $imagesArray,
        'image_urls' => $imagesArray,
        'product_images' => $imagesArray,
        'gallery' => $imagesArray,
        'photos' => $imagesArray,
        'image_paths' => $relativePaths,
        'relative_paths' => $relativePaths,
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
        // Ensure product_images table exists
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `product_images` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `seller_id` BIGINT UNSIGNED NOT NULL,
                    `product_id` BIGINT UNSIGNED NULL DEFAULT NULL,
                    `image_url` LONGTEXT NOT NULL,
                    `relative_path` VARCHAR(255) NOT NULL,
                    `file_name` VARCHAR(255) NOT NULL,
                    `file_size` BIGINT UNSIGNED NULL DEFAULT NULL,
                    `mime_type` VARCHAR(50) NULL DEFAULT NULL,
                    `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    INDEX `idx_pi_seller` (`seller_id`),
                    INDEX `idx_pi_product` (`product_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
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
    $filesInfo = [];
    $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];
    $allowedMimes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
    $dangerousExts = ['php', 'phtml', 'php5', 'exe', 'sh', 'bat', 'cmd', 'js', 'vbs', 'html', 'htm', 'cgi', 'pl'];

    // Helper to process uploaded file with strict validation
    $processUploadedFile = function($tmp, $origName) use ($uploadDir, $baseUrl, $allowedExts, $allowedMimes, $dangerousExts, &$collected, &$filesInfo) {
        if (!empty($tmp) && is_uploaded_file($tmp)) {
            $ext = strtolower(pathinfo($origName ?: 'product.jpg', PATHINFO_EXTENSION));
            if (in_array($ext, $dangerousExts, true)) {
                return; // Strictly reject dangerous files
            }
            if (filesize($tmp) > 15 * 1024 * 1024) {
                return; // Exceeds 15MB
            }

            // Verify MIME type
            $mime = 'image/jpeg';
            if (function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $detected = finfo_file($finfo, $tmp);
                finfo_close($finfo);
                if ($detected) $mime = strtolower($detected);
            } elseif (function_exists('mime_content_type')) {
                $detected = mime_content_type($tmp);
                if ($detected) $mime = strtolower($detected);
            }

            if (!in_array($ext, $allowedExts, true) && !in_array($mime, $allowedMimes, true)) {
                return;
            }
            if (!in_array($ext, $allowedExts, true)) {
                $ext = ($mime === 'image/png') ? 'png' : (($mime === 'image/webp') ? 'webp' : 'jpg');
            }

            $filename = 'prod_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
            if (move_uploaded_file($tmp, $uploadDir . $filename)) {
                @chmod($uploadDir . $filename, 0644);
                $fullUrl = $baseUrl . '/uploads/products/' . $filename;
                $collected[] = $fullUrl;
                $filesInfo[] = [
                    'file_name' => $filename,
                    'relative_path' => 'uploads/products/' . $filename,
                    'image_url' => $fullUrl,
                    'file_size' => @filesize($uploadDir . $filename) ?: 0,
                    'mime_type' => $mime
                ];
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

    // Helper to process candidate items (string URLs, comma-separated URLs, JSON arrays, objects, base64)
    $processCandidate = null;
    $processCandidate = function($candidate) use ($uploadDir, $baseUrl, &$collected, &$filesInfo, &$processCandidate) {
        if (empty($candidate)) return;
        if (is_array($candidate)) {
            if (isset($candidate['url']) || isset($candidate['image_url']) || isset($candidate['src']) || isset($candidate['path']) || isset($candidate['link']) || isset($candidate['image'])) {
                $c = $candidate['url'] ?? $candidate['image_url'] ?? $candidate['src'] ?? $candidate['path'] ?? $candidate['link'] ?? $candidate['image'];
                $processCandidate($c);
                return;
            }
            foreach ($candidate as $item) {
                $processCandidate($item);
            }
            return;
        }
        if (!is_string($candidate)) return;
        $trimmed = trim($candidate);
        if (empty($trimmed) || in_array($trimmed, ['null', 'undefined', '[]', '{}'], true)) return;

        // Ignore temporary device URIs
        if (str_starts_with($trimmed, 'blob:') || str_starts_with($trimmed, 'file:') || str_starts_with($trimmed, '/data/user/') || str_starts_with($trimmed, '/var/mobile/')) {
            return;
        }

        // Clean wrapping quotes e.g. "https://..." or 'https://...'
        while ((str_starts_with($trimmed, '"') && str_ends_with($trimmed, '"')) || (str_starts_with($trimmed, "'") && str_ends_with($trimmed, "'"))) {
            $trimmed = trim(substr($trimmed, 1, -1));
        }

        // JSON array string e.g. ["url1", "url2"] or ['url1', 'url2']
        if (str_starts_with($trimmed, '[') && str_ends_with($trimmed, ']')) {
            $decoded = json_decode($trimmed, true);
            if (!is_array($decoded)) {
                $decoded = json_decode(str_replace("'", '"', $trimmed), true);
            }
            if (is_array($decoded)) {
                foreach ($decoded as $item) {
                    $processCandidate($item);
                }
                return;
            }
        }

        // Multi-URL strings separated by comma, newline, semicolon, pipe, or space
        if (!str_starts_with($trimmed, 'data:image/')) {
            if (preg_match('/[,;\n\r|]/', $trimmed)) {
                $parts = preg_split('/[,;\n\r|]+/', $trimmed);
                if (count($parts) > 1) {
                    foreach ($parts as $p) {
                        $processCandidate($p);
                    }
                    return;
                }
            }
            if (preg_match('/https?:\/\/[^\s]+\s+https?:\/\//', $trimmed)) {
                $spaceParts = preg_split('/\s+/', $trimmed);
                if (count($spaceParts) > 1) {
                    foreach ($spaceParts as $p) {
                        $processCandidate($p);
                    }
                    return;
                }
            }
        }

        // Base64 data URI or raw base64 string
        $isB64 = str_starts_with($trimmed, 'data:image/') || (strlen($trimmed) > 50 && preg_match('/^(\/9j\/|iVBORw0KGgo|R0lGOD|UklGR)/', $trimmed) && preg_match('/^[A-Za-z0-9+\/=\s\r\n]+$/', $trimmed));
        if ($isB64) {
            $rawB64 = '';
            $ext = 'jpg';
            if (preg_match('/^data:image\/(\w+);base64,(.+)$/is', $trimmed, $matches)) {
                $ext = strtolower($matches[1]);
                if ($ext === 'jpeg') $ext = 'jpg';
                if (!in_array($ext, ['jpg', 'png', 'webp'])) $ext = 'jpg';
                $rawB64 = preg_replace('/\s+/', '', $matches[2]);
            } else {
                $rawB64 = preg_replace('/\s+/', '', $trimmed);
                if (str_starts_with($rawB64, '/9j/')) $ext = 'jpg';
                elseif (str_starts_with($rawB64, 'iVBORw0KGgo')) $ext = 'png';
                elseif (str_starts_with($rawB64, 'UklGR')) $ext = 'webp';
            }
            $data = base64_decode($rawB64);
            if ($data !== false && strlen($data) > 10 && strlen($data) <= 15 * 1024 * 1024) {
                $filename = 'prod_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                if (file_put_contents($uploadDir . $filename, $data)) {
                    @chmod($uploadDir . $filename, 0644);
                    $fullUrl = $baseUrl . '/uploads/products/' . $filename;
                    $collected[] = $fullUrl;
                    $filesInfo[] = [
                        'file_name' => $filename,
                        'relative_path' => 'uploads/products/' . $filename,
                        'image_url' => $fullUrl,
                        'file_size' => strlen($data),
                        'mime_type' => ($ext === 'png') ? 'image/png' : (($ext === 'webp') ? 'image/webp' : 'image/jpeg')
                    ];
                }
            }
            return;
        }

        // HTTP/HTTPS or relative URLs
        if (str_starts_with($trimmed, 'http://') || str_starts_with($trimmed, 'https://') || str_starts_with($trimmed, '//')) {
            $collected[] = str_starts_with($trimmed, '//') ? ('https:' . $trimmed) : $trimmed;
        } elseif (str_starts_with($trimmed, '/uploads/') || str_starts_with($trimmed, 'uploads/') || str_starts_with($trimmed, '/storage/') || str_starts_with($trimmed, 'storage/') || str_starts_with($trimmed, '/public/uploads/') || str_starts_with($trimmed, 'public/uploads/')) {
            $clean = ltrim(preg_replace('/^\/?(storage\/|public\/uploads\/)/', 'uploads/', $trimmed), '/');
            $collected[] = $baseUrl . '/' . $clean;
        } elseif (preg_match('/^prod_\d+_[a-f0-9]+\.(jpg|jpeg|png|webp|gif|svg)$/i', $trimmed) || preg_match('/^placeholder\.(jpg|jpeg|png)$/i', $trimmed)) {
            $collected[] = $baseUrl . '/uploads/products/' . $trimmed;
        }
    };

    // Check all input keys for image candidates
    foreach ($input as $k => $v) {
        if (empty($v)) continue;
        $lowerKey = strtolower((string)$k);
        if (
            strpos($lowerKey, 'image') !== false ||
            strpos($lowerKey, 'img') !== false ||
            strpos($lowerKey, 'photo') !== false ||
            strpos($lowerKey, 'picture') !== false ||
            strpos($lowerKey, 'pic') !== false ||
            strpos($lowerKey, 'file') !== false ||
            strpos($lowerKey, 'url') !== false ||
            strpos($lowerKey, 'gallery') !== false ||
            strpos($lowerKey, 'media') !== false ||
            strpos($lowerKey, 'attachment') !== false ||
            strpos($lowerKey, 'thumb') !== false ||
            preg_match('/^image\d+$/i', $k) ||
            preg_match('/^img\d+$/i', $k) ||
            preg_match('/^file\d+$/i', $k)
        ) {
            $processCandidate($v);
        } elseif (is_string($v) && (str_starts_with($v, 'http://') || str_starts_with($v, 'https://') || str_starts_with($v, 'data:image/') || (strlen(trim($v)) > 50 && preg_match('/^(\/9j\/|iVBORw0KGgo|R0lGOD|UklGR)/', trim($v))))) {
            $processCandidate($v);
        }
    }

    if (!empty($fallback)) {
        $processCandidate($fallback);
    }

    // Deduplicate
    $uniqueImages = array_values(array_unique(array_filter($collected)));
    $hasImages = count($uniqueImages) > 0;

    // Strictly enforce seller multiple URL limit of 4 images
    $uniqueImages = array_slice($uniqueImages, 0, 4);
    $primaryImageUrl = !empty($uniqueImages) ? $uniqueImages[0] : null;

    return [
        'hasImages' => $hasImages,
        'primaryImageUrl' => $primaryImageUrl,
        'image' => $primaryImageUrl,
        'images' => $uniqueImages,
        'image_urls' => $uniqueImages,
        'product_images' => $uniqueImages,
        'gallery' => $uniqueImages,
        'photos' => $uniqueImages,
        'imagesJson' => !empty($uniqueImages) ? json_encode($uniqueImages) : null,
        'filesInfo' => $filesInfo,
        'limit' => 4,
        'total' => count($uniqueImages),
    ];
}

function saveUploadedProductImage(?string $fallback = null): ?string
{
    $input = getJsonInput();
    $res = saveUploadedProductImages($input, $fallback);
    return $res['primaryImageUrl'];
}

function syncProductImagesRecordPHP(PDO $pdo, int $sellerId, ?int $productId, array $images, array $filesInfo = []): void
{
    if (empty($images) || empty($sellerId)) return;
    $uploadDir = __DIR__ . '/../uploads/products/';

    foreach ($images as $imgUrl) {
        $fileName = basename(explode('?', $imgUrl)[0]);
        $relativePath = str_contains($imgUrl, '/uploads/products/')
            ? 'uploads/products/' . $fileName
            : (str_starts_with($imgUrl, 'http') ? $imgUrl : 'uploads/products/' . $fileName);

        $fileSize = null;
        $mimeType = null;
        foreach ($filesInfo as $fi) {
            if (($fi['file_name'] ?? '') === $fileName || ($fi['image_url'] ?? '') === $imgUrl) {
                $fileSize = $fi['file_size'] ?? null;
                $mimeType = $fi['mime_type'] ?? null;
                break;
            }
        }
        if (!$fileSize && file_exists($uploadDir . $fileName)) {
            $fileSize = @filesize($uploadDir . $fileName);
            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $mimes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
            $mimeType = $mimes[$ext] ?? 'image/jpeg';
        }

        try {
            $checkStmt = $pdo->prepare("SELECT id FROM product_images WHERE seller_id = :seller_id AND (image_url = :url OR file_name = :fn) LIMIT 1");
            $checkStmt->execute([':seller_id' => $sellerId, ':url' => $imgUrl, ':fn' => $fileName]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                if ($productId) {
                    $upd = $pdo->prepare("UPDATE product_images SET product_id = :pid, updated_at = NOW() WHERE id = :id");
                    $upd->execute([':pid' => $productId, ':id' => $existing['id']]);
                }
            } else {
                $ins = $pdo->prepare("
                    INSERT INTO product_images (seller_id, product_id, image_url, relative_path, file_name, file_size, mime_type, created_at, updated_at)
                    VALUES (:seller_id, :product_id, :image_url, :relative_path, :file_name, :file_size, :mime_type, NOW(), NOW())
                ");
                $ins->execute([
                    ':seller_id' => $sellerId,
                    ':product_id' => $productId,
                    ':image_url' => $imgUrl,
                    ':relative_path' => $relativePath,
                    ':file_name' => $fileName,
                    ':file_size' => $fileSize,
                    ':mime_type' => $mimeType
                ]);
            }
        } catch (\Throwable $e) {}
    }
}

function safeDeleteLocalImageFilePHP(PDO $pdo, string $fileName, ?int $excludeProductId = null): bool
{
    $safeName = basename(explode('?', $fileName)[0]);
    if (!str_starts_with($safeName, 'prod_') || !preg_match('/\.(jpg|jpeg|png|webp)$/i', $safeName)) {
        return false;
    }

    try {
        $search = '%' . $safeName . '%';
        $sql = "SELECT id FROM products WHERE (image_url LIKE :s1 OR images LIKE :s2)";
        $params = [':s1' => $search, ':s2' => $search];
        if ($excludeProductId) {
            $sql .= " AND id != :ex_id";
            $params[':ex_id'] = $excludeProductId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if ($stmt->fetch()) {
            return false; // Still referenced by another product
        }

        $piSql = "SELECT id FROM product_images WHERE file_name = :fn";
        $piParams = [':fn' => $safeName];
        if ($excludeProductId) {
            $piSql .= " AND (product_id IS NULL OR product_id != :ex_id)";
            $piParams[':ex_id'] = $excludeProductId;
        }
        $piStmt = $pdo->prepare($piSql);
        $piStmt->execute($piParams);
        if ($piStmt->fetch()) {
            return false;
        }

        $uploadDir = __DIR__ . '/../uploads/products/';
        if (file_exists($uploadDir . $safeName)) {
            @unlink($uploadDir . $safeName);
            return true;
        }
    } catch (\Throwable $e) {}
    return false;
}

function apiUploadImage(PDO $pdo): void
{
    $user = authenticateUser($pdo);
    ensureProductPriceColumns($pdo);
    $input = array_merge($_POST, getJsonInput());
    $res = saveUploadedProductImages($input);
    if (empty($res['images'])) {
        errorResponse('No image uploaded or invalid format.', [
            'image' => ['Please upload an image file (jpg, jpeg, png, webp) or provide image data/URLs.']
        ], 422);
    }

    $targetProductId = !empty($input['product_id']) ? (int)$input['product_id'] : (!empty($_GET['product_id']) ? (int)$_GET['product_id'] : null);
    $attachedProduct = null;

    if ($targetProductId) {
        $pStmt = $pdo->prepare("SELECT * FROM products WHERE id = :id LIMIT 1");
        $pStmt->execute([':id' => $targetProductId]);
        $prod = $pStmt->fetch(PDO::FETCH_ASSOC);
        if (!$prod) {
            errorResponse('Target product not found.', [], 404);
        }
        if (($user['role'] ?? '') !== 'admin' && (int)$prod['seller_id'] !== (int)$user['id']) {
            errorResponse('Access denied. You do not own this product.', [], 403);
        }

        $isReplace = !empty($input['replace']) && ($input['replace'] === true || $input['replace'] === 'true' || $input['replace'] === '1');
        $existingImgs = [];
        if (!empty($prod['images'])) {
            $dec = json_decode($prod['images'], true);
            if (is_array($dec)) $existingImgs = $dec;
        } elseif (!empty($prod['image_url'])) {
            $existingImgs = [$prod['image_url']];
        }

        $combinedImgs = $isReplace ? $res['images'] : array_slice(array_values(array_unique(array_merge($existingImgs, $res['images']))), 0, 4);
        $primaryUrl = $combinedImgs[0] ?? $res['primaryImageUrl'];
        $imagesJson = json_encode($combinedImgs);

        $updStmt = $pdo->prepare("UPDATE products SET image_url = :url, images = :imgs, updated_at = NOW() WHERE id = :id");
        $updStmt->execute([':url' => $primaryUrl, ':imgs' => $imagesJson, ':id' => $targetProductId]);

        $fetchStmt = $pdo->prepare("SELECT * FROM products WHERE id = :id LIMIT 1");
        $fetchStmt->execute([':id' => $targetProductId]);
        $attachedProduct = formatProductRecord($fetchStmt->fetch(PDO::FETCH_ASSOC), true);
    }

    syncProductImagesRecordPHP($pdo, (int)$user['id'], $targetProductId, $res['images'], $res['filesInfo'] ?? []);

    $fileName = basename(explode('?', $res['primaryImageUrl'])[0]);
    $relativePaths = array_map(function($u) {
        $fn = basename(explode('?', $u)[0]);
        return str_contains($u, '/uploads/products/') ? 'uploads/products/' . $fn : $u;
    }, $res['images']);

    jsonResponse([
        'message' => 'Images uploaded successfully.',
        'image_url' => $res['primaryImageUrl'],
        'image' => $res['primaryImageUrl'],
        'url' => $res['primaryImageUrl'],
        'product_image' => $res['primaryImageUrl'],
        'path' => '/uploads/products/' . $fileName,
        'relative_path' => $relativePaths[0] ?? null,
        'file_name' => $fileName,
        'images' => $res['images'],
        'image_urls' => $res['images'],
        'relative_paths' => $relativePaths,
        'product_id' => $targetProductId,
        'product' => $attachedProduct,
        'data' => [
            'url' => $res['primaryImageUrl'],
            'image_url' => $res['primaryImageUrl'],
            'image' => $res['primaryImageUrl'],
            'images' => $res['images'],
            'relative_path' => $relativePaths[0] ?? null
        ],
        'limit' => 4,
        'total' => count($res['images'])
    ], 201);
}

function apiGetProductImages(PDO $pdo, int $productId): void
{
    ensureProductPriceColumns($pdo);
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $productId]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$p) {
        errorResponse('Product not found.', [], 404);
    }

    $baseUrl = rtrim(getenv('APP_URL') ?: 'https://knowlipop.shop', '/');
    $formatted = formatProductRecord($p);

    $piStmt = $pdo->prepare("SELECT * FROM product_images WHERE product_id = :pid ORDER BY id ASC");
    $piStmt->execute([':pid' => $productId]);
    $gallery = $piStmt->fetchAll(PDO::FETCH_ASSOC);

    jsonResponse([
        'product_id' => $productId,
        'image_url' => $formatted['image_url'],
        'images' => $formatted['images'],
        'gallery' => array_map(function($r) {
            return [
                'id' => (int)$r['id'],
                'image_url' => $r['image_url'],
                'relative_path' => $r['relative_path'],
                'file_name' => $r['file_name'],
                'file_size' => $r['file_size'] ? (int)$r['file_size'] : null,
                'mime_type' => $r['mime_type']
            ];
        }, $gallery)
    ]);
}

function apiReplaceProductImage(PDO $pdo, int $productId): void
{
    $user = authenticateUser($pdo);
    ensureProductPriceColumns($pdo);

    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$product) {
        errorResponse('Product not found.', [], 404);
    }
    if (($user['role'] ?? '') !== 'admin' && (int)$product['seller_id'] !== (int)$user['id']) {
        errorResponse('Access denied. You do not own this product.', [], 403);
    }

    $input = array_merge($_POST, getJsonInput());
    $res = saveUploadedProductImages($input);
    if (empty($res['images'])) {
        errorResponse('No replacement image provided.', [
            'image' => ['Please upload an image file (jpg, jpeg, png, webp) or image URL.']
        ], 422);
    }

    $oldImageUrl = $product['image_url'];
    $newImageUrl = $res['primaryImageUrl'];
    $isReplaceAll = !empty($input['replace_all']) && ($input['replace_all'] === true || $input['replace_all'] === 'true' || $input['replace_all'] === '1');
    $imgIndex = isset($input['image_index']) && is_numeric($input['image_index']) ? (int)$input['image_index'] : 0;

    $currentImgs = [];
    if (!empty($product['images'])) {
        $dec = json_decode($product['images'], true);
        if (is_array($dec)) $currentImgs = $dec;
    } elseif (!empty($product['image_url'])) {
        $currentImgs = [$product['image_url']];
    }

    if ($isReplaceAll) {
        $newImages = array_slice($res['images'], 0, 4);
    } else {
        if ($imgIndex >= 0 && $imgIndex < count($currentImgs)) {
            $currentImgs[$imgIndex] = $newImageUrl;
        } else {
            array_unshift($currentImgs, $newImageUrl);
        }
        $newImages = array_slice(array_values(array_unique($currentImgs)), 0, 4);
    }

    $primaryUrl = $newImages[0] ?? $newImageUrl;
    $imagesJson = json_encode($newImages);

    $updStmt = $pdo->prepare("UPDATE products SET image_url = :url, images = :imgs, updated_at = NOW() WHERE id = :id");
    $updStmt->execute([':url' => $primaryUrl, ':imgs' => $imagesJson, ':id' => $productId]);

    syncProductImagesRecordPHP($pdo, (int)$user['id'], $productId, $newImages, $res['filesInfo'] ?? []);

    if ($oldImageUrl && $oldImageUrl !== $primaryUrl && str_contains($oldImageUrl, '/uploads/products/')) {
        $oldFileName = basename(explode('?', $oldImageUrl)[0]);
        safeDeleteLocalImageFilePHP($pdo, $oldFileName, $productId);
    }

    $fetchStmt = $pdo->prepare("SELECT * FROM products WHERE id = :id LIMIT 1");
    $fetchStmt->execute([':id' => $productId]);
    $updated = formatProductRecord($fetchStmt->fetch(PDO::FETCH_ASSOC), true);

    jsonResponse([
        'message' => 'Product image updated successfully.',
        'image_url' => $primaryUrl,
        'images' => $newImages,
        'product' => $updated
    ]);
}

function apiDeleteProductImage(PDO $pdo, int $productId): void
{
    $user = authenticateUser($pdo);
    ensureProductPriceColumns($pdo);

    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$product) {
        errorResponse('Product not found.', [], 404);
    }
    if (($user['role'] ?? '') !== 'admin' && (int)$product['seller_id'] !== (int)$user['id']) {
        errorResponse('Access denied. You do not own this product.', [], 403);
    }

    $input = array_merge($_GET, $_POST, getJsonInput());
    $targetFilename = $input['filename'] ?? ($input['image_url'] ?? null);

    $currentImgs = [];
    if (!empty($product['images'])) {
        $dec = json_decode($product['images'], true);
        if (is_array($dec)) $currentImgs = $dec;
    } elseif (!empty($product['image_url'])) {
        $currentImgs = [$product['image_url']];
    }

    $removedUrl = null;
    if ($targetFilename) {
        $safeTarget = basename(explode('?', (string)$targetFilename)[0]);
        foreach ($currentImgs as $idx => $u) {
            if (str_contains($u, $safeTarget)) {
                $removedUrl = $u;
                unset($currentImgs[$idx]);
                break;
            }
        }
        $currentImgs = array_values($currentImgs);
    } else {
        $removedUrl = array_shift($currentImgs);
    }

    $primaryUrl = !empty($currentImgs) ? $currentImgs[0] : null;
    $imagesJson = !empty($currentImgs) ? json_encode($currentImgs) : null;

    $updStmt = $pdo->prepare("UPDATE products SET image_url = :url, images = :imgs, updated_at = NOW() WHERE id = :id");
    $updStmt->execute([':url' => $primaryUrl, ':imgs' => $imagesJson, ':id' => $productId]);

    if ($removedUrl) {
        $removedFn = basename(explode('?', $removedUrl)[0]);
        try {
            $delPi = $pdo->prepare("DELETE FROM product_images WHERE product_id = :pid AND file_name = :fn");
            $delPi->execute([':pid' => $productId, ':fn' => $removedFn]);
        } catch (\Throwable $e) {}
        safeDeleteLocalImageFilePHP($pdo, $removedFn, $productId);
    }

    $fetchStmt = $pdo->prepare("SELECT * FROM products WHERE id = :id LIMIT 1");
    $fetchStmt->execute([':id' => $productId]);
    $updated = formatProductRecord($fetchStmt->fetch(PDO::FETCH_ASSOC), true);

    jsonResponse([
        'message' => 'Product image deleted successfully.',
        'image_url' => $primaryUrl,
        'images' => $currentImgs,
        'product' => $updated
    ]);
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

    // Process image file uploads, base64, or multiple images (up to 4 max)
    $imgResult = saveUploadedProductImages($input, $input['image_url'] ?? $input['image'] ?? null);
    $finalImages = $imgResult['hasImages'] ? $imgResult['images'] : array_slice(getDistinctDefaultImages($name, $category, 0), 0, 4);
    $finalPrimaryUrl = !empty($finalImages) ? $finalImages[0] : null;
    $finalImagesJson = !empty($finalImages) ? json_encode($finalImages) : null;

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
        ':image_url' => $finalPrimaryUrl,
        ':images' => $finalImagesJson,
    ]);

    $id = (int) $pdo->lastInsertId();
    syncProductImagesRecordPHP($pdo, (int)$user['id'], $id, $finalImages, $imgResult['filesInfo'] ?? []);
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

    // Process uploaded file(s), base64, or image URLs if provided (up to 4 max)
    $imgResult = saveUploadedProductImages($input, $input['image_url'] ?? $input['image'] ?? null);
    if (!empty($imgResult['hasImages']) && !empty($imgResult['images'])) {
        $updates[] = "image_url = :image_url";
        $params[':image_url'] = $imgResult['primaryImageUrl'];
        $updates[] = "images = :images";
        $params[':images'] = $imgResult['imagesJson'];
        syncProductImagesRecordPHP($pdo, (int)$user['id'], $id, $imgResult['images'], $imgResult['filesInfo'] ?? []);
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

    $imagesToDelete = [];
    if (!empty($product['images'])) {
        $dec = json_decode($product['images'], true);
        if (is_array($dec)) $imagesToDelete = $dec;
    } elseif (!empty($product['image_url'])) {
        $imagesToDelete = [$product['image_url']];
    }

    $delStmt = $pdo->prepare("DELETE FROM products WHERE id = :id");
    $delStmt->execute([':id' => $id]);

    try {
        $delPi = $pdo->prepare("DELETE FROM product_images WHERE product_id = :id");
        $delPi->execute([':id' => $id]);
    } catch (\Throwable $e) {}

    foreach ($imagesToDelete as $imgUrl) {
        if ($imgUrl && str_contains($imgUrl, '/uploads/products/')) {
            $fn = basename(explode('?', $imgUrl)[0]);
            safeDeleteLocalImageFilePHP($pdo, $fn, $id);
        }
    }

    jsonResponse(['message' => 'Product deleted.']);
}
