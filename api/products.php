<?php

/**
 * Pure PHP API: Product Controller
 */

require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';

function apiGetProducts(PDO $pdo): void
{
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
               p.name, p.description, p.price, p.stock, p.image_url, p.is_active, p.created_at, p.updated_at
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
    $products = $stmt->fetchAll();

    foreach ($products as &$p) {
        $p['id'] = (int) $p['id'];
        $p['seller_id'] = (int) $p['seller_id'];
        $p['price'] = (string) number_format((float) $p['price'], 2, '.', '');
        $p['stock'] = (int) $p['stock'];
        $p['is_active'] = (bool) $p['is_active'];
    }

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
    $stmt = $pdo->prepare("
        SELECT p.id, p.seller_id, COALESCE(u.seller_name, u.name, 'Verified Seller') AS seller_name,
               p.name, p.description, p.price, p.stock, p.image_url, p.is_active, p.created_at, p.updated_at
        FROM products p
        LEFT JOIN users u ON p.seller_id = u.id
        WHERE p.id = :id AND p.is_active = 1
        LIMIT 1
    ");
    $stmt->execute([':id' => $id]);
    $product = $stmt->fetch();

    if (!$product) {
        errorResponse('Product not found.', [], 404);
    }

    $product['id'] = (int) $product['id'];
    $product['seller_id'] = (int) $product['seller_id'];
    $product['price'] = (string) number_format((float) $product['price'], 2, '.', '');
    $product['stock'] = (int) $product['stock'];
    $product['is_active'] = (bool) $product['is_active'];

    jsonResponse(['product' => $product]);
}

function saveUploadedProductImage(?string $fallback = null): ?string
{
    $uploadDir = __DIR__ . '/../uploads/products/';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }

    $baseUrl = rtrim(getenv('APP_URL') ?: 'https://knowlipop.shop', '/');

    // 1. Check $_FILES for multipart/form-data upload (field names: image, file, product_image, photo, picture, img)
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
    $input = getJsonInput();
    $errors = [];

    $name = trim($input['name'] ?? '');
    $price = $input['price'] ?? null;
    $stock = $input['stock'] ?? null;

    if (empty($name)) {
        $errors['name'] = ['The name field is required.'];
    }
    if (!isset($price) || !is_numeric($price) || $price < 0) {
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

    $stmt = $pdo->prepare("
        INSERT INTO products (seller_id, name, description, price, stock, image_url, is_active, created_at, updated_at)
        VALUES (:seller_id, :name, :description, :price, :stock, :image_url, 1, NOW(), NOW())
    ");
    $stmt->execute([
        ':seller_id' => $user['id'],
        ':name' => $name,
        ':description' => $input['description'] ?? null,
        ':price' => number_format((float) $price, 2, '.', ''),
        ':stock' => (int) $stock,
        ':image_url' => $imageUrl,
    ]);

    $id = (int) $pdo->lastInsertId();
    $fetchStmt = $pdo->prepare("
        SELECT p.id, p.seller_id, COALESCE(u.seller_name, u.name, 'Verified Seller') AS seller_name,
               p.name, p.description, p.price, p.stock, p.image_url, p.is_active, p.created_at, p.updated_at
        FROM products p
        LEFT JOIN users u ON p.seller_id = u.id
        WHERE p.id = :id
    ");
    $fetchStmt->execute([':id' => $id]);
    $product = $fetchStmt->fetch();

    $product['id'] = (int) $product['id'];
    $product['seller_id'] = (int) $product['seller_id'];
    $product['price'] = (string) number_format((float) $product['price'], 2, '.', '');
    $product['stock'] = (int) $product['stock'];
    $product['is_active'] = (bool) $product['is_active'];

    jsonResponse(['message' => 'Product created.', 'product' => $product], 201);
}

function apiUpdateProduct(PDO $pdo, int $id): void
{
    $user = authenticateUser($pdo);
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = :id AND seller_id = :seller_id LIMIT 1");
    $stmt->execute([':id' => $id, ':seller_id' => $user['id']]);
    $product = $stmt->fetch();

    if (!$product) {
        errorResponse('Product not found or access denied.', [], 404);
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
    if (isset($input['price']) && is_numeric($input['price'])) {
        $updates[] = "price = :price";
        $params[':price'] = number_format((float) $input['price'], 2, '.', '');
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
               p.name, p.description, p.price, p.stock, p.image_url, p.is_active, p.created_at, p.updated_at
        FROM products p
        LEFT JOIN users u ON p.seller_id = u.id
        WHERE p.id = :id
    ");
    $fetchStmt->execute([':id' => $id]);
    $updated = $fetchStmt->fetch();

    $updated['id'] = (int) $updated['id'];
    $updated['seller_id'] = (int) $updated['seller_id'];
    $updated['price'] = (string) number_format((float) $updated['price'], 2, '.', '');
    $updated['stock'] = (int) $updated['stock'];
    $updated['is_active'] = (bool) $updated['is_active'];

    jsonResponse(['message' => 'Product updated.', 'product' => $updated]);
}

function apiDeleteProduct(PDO $pdo, int $id): void
{
    $user = authenticateUser($pdo);
    $stmt = $pdo->prepare("SELECT id FROM products WHERE id = :id AND seller_id = :seller_id LIMIT 1");
    $stmt->execute([':id' => $id, ':seller_id' => $user['id']]);
    if (!$stmt->fetch()) {
        errorResponse('Product not found or access denied.', [], 404);
    }

    $delStmt = $pdo->prepare("DELETE FROM products WHERE id = :id");
    $delStmt->execute([':id' => $id]);

    jsonResponse(['message' => 'Product deleted.']);
}

