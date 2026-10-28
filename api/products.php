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

    $countStmt = $pdo->query("SELECT COUNT(*) FROM products WHERE is_active = 1");
    $total = (int) $countStmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT id, seller_id, name, description, price, stock, image_url, is_active, created_at, updated_at
        FROM products
        WHERE is_active = 1
        ORDER BY id DESC
        LIMIT :limit OFFSET :offset
    ");
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
        SELECT id, seller_id, name, description, price, stock, image_url, is_active, created_at, updated_at
        FROM products
        WHERE id = :id AND is_active = 1
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

function apiCreateProduct(PDO $pdo): void
{
    $user = authenticateUser($pdo);
    $input = getJsonInput();
    $errors = [];

    if (empty($input['name'])) {
        $errors['name'] = ['The name field is required.'];
    }
    if (!isset($input['price']) || !is_numeric($input['price']) || $input['price'] < 0) {
        $errors['price'] = ['The price field is required and must be a positive number.'];
    }
    if (!isset($input['stock']) || !is_numeric($input['stock']) || $input['stock'] < 0) {
        $errors['stock'] = ['The stock field is required and must be an integer.'];
    }

    if (!empty($errors)) {
        errorResponse('Validation error occurred.', $errors, 422);
    }

    $stmt = $pdo->prepare("
        INSERT INTO products (seller_id, name, description, price, stock, image_url, is_active, created_at, updated_at)
        VALUES (:seller_id, :name, :description, :price, :stock, :image_url, 1, NOW(), NOW())
    ");
    $stmt->execute([
        ':seller_id' => $user['id'],
        ':name' => $input['name'],
        ':description' => $input['description'] ?? null,
        ':price' => number_format((float) $input['price'], 2, '.', ''),
        ':stock' => (int) $input['stock'],
        ':image_url' => $input['image_url'] ?? null,
    ]);

    $id = (int) $pdo->lastInsertId();
    $fetchStmt = $pdo->prepare("SELECT * FROM products WHERE id = :id");
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

    if (isset($input['name'])) {
        $updates[] = "name = :name";
        $params[':name'] = $input['name'];
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
    if (isset($input['image_url'])) {
        $updates[] = "image_url = :image_url";
        $params[':image_url'] = $input['image_url'];
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

    $fetchStmt = $pdo->prepare("SELECT * FROM products WHERE id = :id");
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
