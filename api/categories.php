<?php

/**
 * Pure PHP: Product Categories CRUD & Product Category Assignment API
 * Supports 5 seeded editable starter categories + full create/edit/delete/product-assignment
 */

require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';

function getDefaultStarterCategoriesPhp(): array
{
    return [
        [
            'id' => 1,
            'name' => 'Electronics',
            'slug' => 'electronics',
            'description' => 'Smartphones, headphones, gadgets & tech accessories',
            'icon' => '📱',
            'image_url' => null,
            'sort_order' => 1,
            'is_active' => 1,
        ],
        [
            'id' => 2,
            'name' => 'Fashion',
            'slug' => 'fashion',
            'description' => 'Apparel, footwear, bags, sunglasses & style essentials',
            'icon' => '👕',
            'image_url' => null,
            'sort_order' => 2,
            'is_active' => 1,
        ],
        [
            'id' => 3,
            'name' => 'Home & Kitchen',
            'slug' => 'home-kitchen',
            'description' => 'Appliances, cookware, home decor & living essentials',
            'icon' => '🏠',
            'image_url' => null,
            'sort_order' => 3,
            'is_active' => 1,
        ],
        [
            'id' => 4,
            'name' => 'Beauty & Personal Care',
            'slug' => 'beauty-personal-care',
            'description' => 'Skincare, grooming, wellness & cosmetics',
            'icon' => '✨',
            'image_url' => null,
            'sort_order' => 4,
            'is_active' => 1,
        ],
        [
            'id' => 5,
            'name' => 'Grocery',
            'slug' => 'grocery',
            'description' => 'Daily staples, snacks, beverages & household essentials',
            'icon' => '🛒',
            'image_url' => null,
            'sort_order' => 5,
            'is_active' => 1,
        ],
    ];
}

function toCategorySlugPhp(string $name): string
{
    $slug = strtolower(trim($name));
    $slug = str_replace('&', ' ', $slug);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim((string) $slug, '-');
    return $slug !== '' ? $slug : 'category';
}

function ensureUniqueCategorySlugPhp(PDO $pdo, string $baseSlug, ?int $excludeId = null): string
{
    $candidate = $baseSlug !== '' ? $baseSlug : 'category';
    $suffix = 2;
    while (true) {
        if ($excludeId !== null) {
            $stmt = $pdo->prepare("SELECT id FROM categories WHERE slug = :slug AND id != :id LIMIT 1");
            $stmt->execute([':slug' => $candidate, ':id' => $excludeId]);
        } else {
            $stmt = $pdo->prepare("SELECT id FROM categories WHERE slug = :slug LIMIT 1");
            $stmt->execute([':slug' => $candidate]);
        }
        if (!$stmt->fetch()) {
            return $candidate;
        }
        $candidate = $baseSlug . '-' . $suffix;
        $suffix++;
        if ($suffix > 200) {
            return $baseSlug . '-' . time();
        }
    }
}

function ensureProductCategoriesTable(PDO $pdo): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `categories` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name` VARCHAR(100) NOT NULL,
                `slug` VARCHAR(120) NOT NULL UNIQUE,
                `description` VARCHAR(255) NULL DEFAULT NULL,
                `icon` VARCHAR(60) NULL DEFAULT NULL,
                `image_url` LONGTEXT NULL DEFAULT NULL,
                `sort_order` INT NOT NULL DEFAULT 0,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_categories_active` (`is_active`),
                INDEX `idx_categories_sort` (`sort_order`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // Ensure products.category_id column exists
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM `products` LIKE 'category_id'")->fetchAll();
            if (empty($cols)) {
                $pdo->exec("ALTER TABLE `products` ADD COLUMN `category_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `category`");
                try {
                    $pdo->exec("ALTER TABLE `products` ADD INDEX `idx_products_category` (`category_id`)");
                } catch (\Throwable $e) {}
            }
        } catch (\Throwable $e) {}

        // Seed 5 default categories ONLY if the table is completely empty (never overwrite edits!)
        $cnt = (int) $pdo->query("SELECT COUNT(*) FROM `categories`")->fetchColumn();
        if ($cnt === 0) {
            $ins = $pdo->prepare("
                INSERT INTO `categories` (`id`, `name`, `slug`, `description`, `icon`, `sort_order`, `is_active`, `created_at`, `updated_at`)
                VALUES (:id, :name, :slug, :description, :icon, :sort_order, :is_active, NOW(), NOW())
            ");
            foreach (getDefaultStarterCategoriesPhp() as $cat) {
                $ins->execute([
                    ':id' => $cat['id'],
                    ':name' => $cat['name'],
                    ':slug' => $cat['slug'],
                    ':description' => $cat['description'],
                    ':icon' => $cat['icon'],
                    ':sort_order' => $cat['sort_order'],
                    ':is_active' => $cat['is_active'],
                ]);
            }
        }

        // Non-destructive backfill of category_id for existing products matching category name/slug
        try {
            $pdo->exec("
                UPDATE `products` p
                JOIN `categories` c ON (LOWER(TRIM(p.category)) = LOWER(TRIM(c.name)) OR LOWER(TRIM(p.category)) = LOWER(TRIM(c.slug)))
                SET p.category_id = c.id, p.category = c.name
                WHERE p.category_id IS NULL AND p.category IS NOT NULL AND TRIM(p.category) != ''
            ");
        } catch (\Throwable $e) {}

        $initialized = true;
    } catch (\Throwable $e) {
        // ignore if table inspection failed transiently
    }
}

function formatCategoryRecord(array $row, ?int $overrideCount = null): array
{
    $productsCount = $overrideCount !== null ? $overrideCount : (int) ($row['products_count'] ?? 0);
    return [
        'id' => (int) $row['id'],
        'name' => (string) ($row['name'] ?? ''),
        'slug' => (string) ($row['slug'] ?? ''),
        'description' => $row['description'] ?? null,
        'icon' => $row['icon'] ?? null,
        'image_url' => $row['image_url'] ?? null,
        'sort_order' => (int) ($row['sort_order'] ?? 0),
        'is_active' => isset($row['is_active']) ? (bool) $row['is_active'] : true,
        'products_count' => $productsCount,
        'created_at' => $row['created_at'] ?? null,
        'updated_at' => $row['updated_at'] ?? null,
    ];
}

function findCategoryByIdOrSlugPhp(PDO $pdo, string $idOrSlug): ?array
{
    $trimmed = trim($idOrSlug);
    if ($trimmed === '') return null;

    if (ctype_digit($trimmed)) {
        $stmt = $pdo->prepare("SELECT * FROM categories WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => (int) $trimmed]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) return $row;
    }

    $stmt = $pdo->prepare("SELECT * FROM categories WHERE LOWER(slug) = LOWER(:s1) OR LOWER(name) = LOWER(:s2) LIMIT 1");
    $stmt->execute([':s1' => $trimmed, ':s2' => $trimmed]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function resolveProductCategoryPhp(PDO $pdo, array $input, bool $isCreate = false): array
{
    ensureProductCategoriesTable($pdo);

    $hasCategoryId = array_key_exists('category_id', $input) && $input['category_id'] !== '' && $input['category_id'] !== null;
    $hasCategoryName = array_key_exists('category', $input) || array_key_exists('category_name', $input) || array_key_exists('category_slug', $input);

    if ($hasCategoryId) {
        $rawId = $input['category_id'];
        if (!is_numeric($rawId) || (int) $rawId <= 0) {
            return ['provided' => true, 'error' => ['category_id' => ['Category ID must be a positive integer.']]];
        }
        $stmt = $pdo->prepare("SELECT * FROM categories WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => (int) $rawId]);
        $cat = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$cat) {
            return ['provided' => true, 'error' => ['category_id' => ['Selected category was not found.']]];
        }
        return [
            'provided' => true,
            'category' => (string) $cat['name'],
            'category_id' => (int) $cat['id'],
            'error' => null,
        ];
    }

    if ($hasCategoryName) {
        $rawCat = trim((string) ($input['category'] ?? ($input['category_name'] ?? ($input['category_slug'] ?? ''))));
        if ($rawCat === '') {
            return [
                'provided' => true,
                'category' => 'General',
                'category_id' => null,
                'error' => null,
            ];
        }

        if (ctype_digit($rawCat)) {
            $stmt = $pdo->prepare("SELECT * FROM categories WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => (int) $rawCat]);
            $cat = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($cat) {
                return [
                    'provided' => true,
                    'category' => (string) $cat['name'],
                    'category_id' => (int) $cat['id'],
                    'error' => null,
                ];
            }
        }

        $stmt = $pdo->prepare("SELECT * FROM categories WHERE LOWER(name) = LOWER(:q1) OR LOWER(slug) = LOWER(:q2) LIMIT 1");
        $stmt->execute([':q1' => $rawCat, ':q2' => $rawCat]);
        $cat = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($cat) {
            return [
                'provided' => true,
                'category' => (string) $cat['name'],
                'category_id' => (int) $cat['id'],
                'error' => null,
            ];
        }

        // Preserve backward compatibility for custom or legacy category labels (e.g. 'General')
        return [
            'provided' => true,
            'category' => $rawCat,
            'category_id' => null,
            'error' => null,
        ];
    }

    if ($isCreate) {
        return [
            'provided' => true,
            'category' => 'General',
            'category_id' => null,
            'error' => null,
        ];
    }

    return ['provided' => false, 'category' => null, 'category_id' => null, 'error' => null];
}

function getCategoryProductCountPhp(PDO $pdo, array $cat): int
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM products
            WHERE is_active = 1 AND (category_id = :cid OR LOWER(category) = LOWER(:cname))
        ");
        $stmt->execute([':cid' => (int) $cat['id'], ':cname' => $cat['name']]);
        return (int) $stmt->fetchColumn();
    } catch (\Throwable $e) {
        return 0;
    }
}

function apiGetCategories(PDO $pdo): void
{
    ensureProductCategoriesTable($pdo);

    $includeInactive = isset($_GET['include_inactive']) && in_array(strtolower((string) $_GET['include_inactive']), ['1', 'true', 'yes'], true);
    if (!$includeInactive && isset($_GET['all']) && in_array(strtolower((string) $_GET['all']), ['1', 'true', 'yes'], true)) {
        $includeInactive = true;
    }
    $search = trim((string) ($_GET['search'] ?? ($_GET['q'] ?? '')));

    $where = "WHERE 1=1";
    $params = [];
    if (!$includeInactive) {
        $where .= " AND c.is_active = 1";
    }
    if ($search !== '') {
        $where .= " AND (c.name LIKE :s1 OR c.description LIKE :s2 OR c.slug LIKE :s3)";
        $sLike = '%' . $search . '%';
        $params[':s1'] = $sLike;
        $params[':s2'] = $sLike;
        $params[':s3'] = $sLike;
    }

    $stmt = $pdo->prepare("
        SELECT c.*,
               (SELECT COUNT(*) FROM products p WHERE p.is_active = 1 AND (p.category_id = c.id OR LOWER(p.category) = LOWER(c.name))) AS products_count
        FROM categories c
        {$where}
        ORDER BY c.sort_order ASC, c.id ASC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $formatted = array_map(fn($r) => formatCategoryRecord($r), $rows);

    jsonResponse([
        'success' => true,
        'status' => true,
        'data' => $formatted,
        'categories' => $formatted,
        'total' => count($formatted),
    ]);
}

function apiGetCategoryDetail(PDO $pdo, string $idOrSlug): void
{
    ensureProductCategoriesTable($pdo);
    $cat = findCategoryByIdOrSlugPhp($pdo, $idOrSlug);
    if (!$cat) {
        errorResponse('Category not found.', [], 404);
    }

    $count = getCategoryProductCountPhp($pdo, $cat);
    $formatted = formatCategoryRecord($cat, $count);

    jsonResponse([
        'success' => true,
        'status' => true,
        'data' => $formatted,
        'category' => $formatted,
    ]);
}

function apiGetCategoryProducts(PDO $pdo, string $idOrSlug): void
{
    ensureProductCategoriesTable($pdo);
    $cat = findCategoryByIdOrSlugPhp($pdo, $idOrSlug);
    if (!$cat) {
        errorResponse('Category not found.', [], 404);
    }

    require_once __DIR__ . '/products.php';
    ensureProductPriceColumns($pdo);

    $page = max(1, (int) ($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int) ($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    $search = trim((string) ($_GET['search'] ?? ($_GET['q'] ?? '')));

    $where = "WHERE p.is_active = 1 AND (p.category_id = :cat_id OR LOWER(p.category) = LOWER(:cat_name))";
    $params = [
        ':cat_id' => (int) $cat['id'],
        ':cat_name' => $cat['name'],
    ];

    if ($search !== '') {
        $where .= " AND (p.name LIKE :s1 OR p.description LIKE :s2)";
        $sLike = '%' . $search . '%';
        $params[':s1'] = $sLike;
        $params[':s2'] = $sLike;
    }

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM products p {$where}");
    foreach ($params as $k => $v) {
        $countStmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $countStmt->execute();
    $total = (int) $countStmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT p.id, p.seller_id, COALESCE(u.seller_name, u.name, 'Verified Seller') AS seller_name,
               p.name, p.description, p.sku, p.category, p.category_id, p.brand, p.mrp, p.rating, p.ratings_count,
               p.seller_price, p.commission, p.delivery_charge, p.gst, p.other_charges, p.price,
               p.stock, p.image_url, p.images, p.is_active, p.created_at, p.updated_at
        FROM products p
        LEFT JOIN users u ON p.seller_id = u.id
        {$where}
        ORDER BY p.id DESC
        LIMIT :limit OFFSET :offset
    ");
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rawProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $products = array_map(fn($p) => formatProductRecord($p, false), $rawProducts);
    $lastPage = (int) ceil($total / $limit) ?: 1;
    $catFormatted = formatCategoryRecord($cat, $total);

    jsonResponse([
        'success' => true,
        'status' => true,
        'category' => $catFormatted,
        'data' => $products,
        'total' => $total,
        'current_page' => $page,
        'per_page' => $limit,
        'last_page' => $lastPage,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total' => $total,
            'last_page' => $lastPage,
        ],
    ]);
}

function apiCreateCategory(PDO $pdo, bool $requireAdmin = false): void
{
    $user = authenticateUser($pdo);
    if ($requireAdmin && ($user['role'] ?? '') !== 'admin') {
        errorResponse('Unauthorized. Admin access required.', [], 403);
    }
    ensureProductCategoriesTable($pdo);

    $input = array_merge($_POST, getJsonInput());
    $name = trim((string) ($input['name'] ?? ($input['category_name'] ?? '')));
    if ($name === '') {
        errorResponse('Validation error occurred.', ['name' => ['The category name field is required.']], 422);
    }
    if (mb_strlen($name) > 100) {
        errorResponse('Validation error occurred.', ['name' => ['The category name may not exceed 100 characters.']], 422);
    }

    $dupStmt = $pdo->prepare("SELECT id FROM categories WHERE LOWER(name) = LOWER(:name) LIMIT 1");
    $dupStmt->execute([':name' => $name]);
    if ($dupStmt->fetch()) {
        errorResponse('A category with this name already exists.', ['name' => ['A category with this name already exists.']], 422);
    }

    $rawSlug = trim((string) ($input['slug'] ?? ''));
    $baseSlug = $rawSlug !== '' ? toCategorySlugPhp($rawSlug) : toCategorySlugPhp($name);
    $slug = ensureUniqueCategorySlugPhp($pdo, $baseSlug);

    $description = isset($input['description']) ? trim((string) $input['description']) : null;
    $icon = isset($input['icon']) ? trim((string) $input['icon']) : null;
    $imageUrl = isset($input['image_url']) ? trim((string) $input['image_url']) : null;
    if (isset($input['sort_order']) && is_numeric($input['sort_order'])) {
        $sortOrder = (int) $input['sort_order'];
    } else {
        $maxSort = (int) $pdo->query("SELECT COALESCE(MAX(sort_order), 0) FROM categories")->fetchColumn();
        $sortOrder = $maxSort + 1;
    }
    $isActive = isset($input['is_active']) ? (filter_var($input['is_active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? ((int)$input['is_active'] ? true : false)) : true;

    $stmt = $pdo->prepare("
        INSERT INTO categories (name, slug, description, icon, image_url, sort_order, is_active, created_at, updated_at)
        VALUES (:name, :slug, :description, :icon, :image_url, :sort_order, :is_active, NOW(), NOW())
    ");
    $stmt->execute([
        ':name' => $name,
        ':slug' => $slug,
        ':description' => $description !== '' ? $description : null,
        ':icon' => $icon !== '' ? $icon : null,
        ':image_url' => $imageUrl !== '' ? $imageUrl : null,
        ':sort_order' => $sortOrder,
        ':is_active' => $isActive ? 1 : 0,
    ]);

    $id = (int) $pdo->lastInsertId();
    $cat = findCategoryByIdOrSlugPhp($pdo, (string) $id);
    $formatted = formatCategoryRecord($cat, getCategoryProductCountPhp($pdo, $cat));

    jsonResponse([
        'success' => true,
        'status' => true,
        'message' => 'Category created.',
        'data' => $formatted,
        'category' => $formatted,
    ], 201);
}

function apiUpdateCategory(PDO $pdo, string $idOrSlug, bool $requireAdmin = false): void
{
    $user = authenticateUser($pdo);
    if ($requireAdmin && ($user['role'] ?? '') !== 'admin') {
        errorResponse('Unauthorized. Admin access required.', [], 403);
    }
    ensureProductCategoriesTable($pdo);

    $existing = findCategoryByIdOrSlugPhp($pdo, $idOrSlug);
    if (!$existing) {
        errorResponse('Category not found.', [], 404);
    }

    $catId = (int) $existing['id'];
    $oldName = (string) $existing['name'];
    $input = array_merge($_POST, getJsonInput());

    $updates = [];
    $params = [':id' => $catId];
    $newName = $oldName;
    $nameChanged = false;

    $incomingName = $input['name'] ?? ($input['category_name'] ?? null);
    if ($incomingName !== null) {
        $trimmedName = trim((string) $incomingName);
        if ($trimmedName === '') {
            errorResponse('Validation error occurred.', ['name' => ['Category name cannot be empty.']], 422);
        }
        if (mb_strlen($trimmedName) > 100) {
            errorResponse('Validation error occurred.', ['name' => ['Category name may not exceed 100 characters.']], 422);
        }
        $dupStmt = $pdo->prepare("SELECT id FROM categories WHERE LOWER(name) = LOWER(:name) AND id != :id LIMIT 1");
        $dupStmt->execute([':name' => $trimmedName, ':id' => $catId]);
        if ($dupStmt->fetch()) {
            errorResponse('A category with this name already exists.', ['name' => ['A category with this name already exists.']], 422);
        }
        $updates[] = "name = :name";
        $params[':name'] = $trimmedName;
        $newName = $trimmedName;
        $nameChanged = (strcasecmp($oldName, $newName) !== 0 || $oldName !== $newName);
    }

    if (array_key_exists('slug', $input) && trim((string) $input['slug']) !== '') {
        $newSlug = ensureUniqueCategorySlugPhp($pdo, toCategorySlugPhp((string) $input['slug']), $catId);
        $updates[] = "slug = :slug";
        $params[':slug'] = $newSlug;
    } elseif ($nameChanged) {
        $newSlug = ensureUniqueCategorySlugPhp($pdo, toCategorySlugPhp($newName), $catId);
        $updates[] = "slug = :slug";
        $params[':slug'] = $newSlug;
    }

    if (array_key_exists('description', $input)) {
        $desc = trim((string) ($input['description'] ?? ''));
        $updates[] = "description = :description";
        $params[':description'] = $desc !== '' ? $desc : null;
    }
    if (array_key_exists('icon', $input)) {
        $icon = trim((string) ($input['icon'] ?? ''));
        $updates[] = "icon = :icon";
        $params[':icon'] = $icon !== '' ? $icon : null;
    }
    if (array_key_exists('image_url', $input)) {
        $img = trim((string) ($input['image_url'] ?? ''));
        $updates[] = "image_url = :image_url";
        $params[':image_url'] = $img !== '' ? $img : null;
    }
    if (isset($input['sort_order']) && is_numeric($input['sort_order'])) {
        $updates[] = "sort_order = :sort_order";
        $params[':sort_order'] = (int) $input['sort_order'];
    }
    if (array_key_exists('is_active', $input)) {
        $activeBool = is_bool($input['is_active'])
            ? $input['is_active']
            : in_array(strtolower((string) $input['is_active']), ['1', 'true', 'yes', 'on'], true);
        $updates[] = "is_active = :is_active";
        $params[':is_active'] = $activeBool ? 1 : 0;
    }

    if (!empty($updates)) {
        $updates[] = "updated_at = NOW()";
        $sql = "UPDATE categories SET " . implode(', ', $updates) . " WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }

    $updatedProductsCount = 0;
    if ($nameChanged) {
        try {
            $syncStmt = $pdo->prepare("
                UPDATE products
                SET category = :new_name, category_id = :cat_id, updated_at = NOW()
                WHERE category_id = :match_cid OR LOWER(category) = LOWER(:old_name)
            ");
            $syncStmt->execute([
                ':new_name' => $newName,
                ':cat_id' => $catId,
                ':match_cid' => $catId,
                ':old_name' => $oldName,
            ]);
            $updatedProductsCount = $syncStmt->rowCount();
        } catch (\Throwable $e) {}
    }

    $fresh = findCategoryByIdOrSlugPhp($pdo, (string) $catId);
    $formatted = formatCategoryRecord($fresh, getCategoryProductCountPhp($pdo, $fresh));

    jsonResponse([
        'success' => true,
        'status' => true,
        'message' => 'Category updated.',
        'updated_products_count' => $updatedProductsCount,
        'data' => $formatted,
        'category' => $formatted,
    ]);
}

function apiDeleteCategory(PDO $pdo, string $idOrSlug, bool $requireAdmin = false): void
{
    $user = authenticateUser($pdo);
    if ($requireAdmin && ($user['role'] ?? '') !== 'admin') {
        errorResponse('Unauthorized. Admin access required.', [], 403);
    }
    ensureProductCategoriesTable($pdo);

    $existing = findCategoryByIdOrSlugPhp($pdo, $idOrSlug);
    if (!$existing) {
        errorResponse('Category not found.', [], 404);
    }

    $catId = (int) $existing['id'];
    $oldName = (string) $existing['name'];

    try {
        $detachStmt = $pdo->prepare("
            UPDATE products
            SET category = 'General', category_id = NULL, updated_at = NOW()
            WHERE category_id = :cid OR LOWER(category) = LOWER(:old_name)
        ");
        $detachStmt->execute([':cid' => $catId, ':old_name' => $oldName]);
    } catch (\Throwable $e) {}

    $delStmt = $pdo->prepare("DELETE FROM categories WHERE id = :id");
    $delStmt->execute([':id' => $catId]);

    jsonResponse([
        'success' => true,
        'status' => true,
        'message' => 'Category deleted.',
    ]);
}

function apiUpdateProductCategory(PDO $pdo, int $productId): void
{
    $user = authenticateUser($pdo);
    ensureProductCategoriesTable($pdo);
    require_once __DIR__ . '/products.php';
    ensureProductPriceColumns($pdo);

    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        errorResponse('Product not found.', [], 404);
    }

    if (($user['role'] ?? '') !== 'admin' && (int) $product['seller_id'] !== (int) $user['id']) {
        errorResponse('Access denied. You do not own this product.', [], 403);
    }

    $input = array_merge($_POST, getJsonInput());
    $catRes = resolveProductCategoryPhp($pdo, $input, false);
    if (empty($catRes['provided'])) {
        errorResponse('Validation error occurred.', ['category' => ['Please provide category or category_id.']], 422);
    }
    if (!empty($catRes['error'])) {
        errorResponse('Validation error occurred.', $catRes['error'], 422);
    }

    $upd = $pdo->prepare("UPDATE products SET category = :category, category_id = :category_id, updated_at = NOW() WHERE id = :id");
    $upd->execute([
        ':category' => $catRes['category'],
        ':category_id' => $catRes['category_id'],
        ':id' => $productId,
    ]);

    $fetchStmt = $pdo->prepare("
        SELECT p.id, p.seller_id, COALESCE(u.seller_name, u.name, 'Verified Seller') AS seller_name,
               p.name, p.description, p.sku, p.category, p.category_id, p.brand, p.mrp, p.rating, p.ratings_count,
               p.seller_price, p.commission, p.delivery_charge, p.gst, p.other_charges, p.price,
               p.stock, p.image_url, p.images, p.is_active, p.created_at, p.updated_at
        FROM products p
        LEFT JOIN users u ON p.seller_id = u.id
        WHERE p.id = :id
    ");
    $fetchStmt->execute([':id' => $productId]);
    $updated = $fetchStmt->fetch(PDO::FETCH_ASSOC);
    $formatted = formatProductRecord($updated, true);

    jsonResponse([
        'success' => true,
        'status' => true,
        'message' => 'Product category updated.',
        'product' => $formatted,
        'data' => $formatted,
    ]);
}
