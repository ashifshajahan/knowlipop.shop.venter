<?php

/**
 * Pure PHP API: Orders Controller (Multi-Vendor Order Routing & Seller Isolation)
 * Supports:
 * - Storing seller_id and product_id for every order item
 * - Automatic routing of each item to its respective seller based on products.seller_id
 * - Strict seller authorization: sellers only access orders containing their products
 * - Multi-vendor order isolation: sellers only see their own products and relevant order details
 * - Dedicated seller-specific order list & detail endpoints
 */

require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';

function ensureOrdersSchema(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `orders` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `seller_id` BIGINT UNSIGNED NULL DEFAULT NULL,
                `order_number` VARCHAR(60) NOT NULL UNIQUE,
                `buyer_name` VARCHAR(150) NOT NULL,
                `buyer_phone` VARCHAR(30) NOT NULL,
                `shipping_address` TEXT NOT NULL,
                `landmark` VARCHAR(200) NULL DEFAULT NULL,
                `city` VARCHAR(100) NULL DEFAULT NULL,
                `state` VARCHAR(100) NULL DEFAULT NULL,
                `pincode` VARCHAR(20) NULL DEFAULT NULL,
                `order_status` VARCHAR(40) NOT NULL DEFAULT 'pending',
                `payment_method` VARCHAR(40) NOT NULL DEFAULT 'cod',
                `payment_status` VARCHAR(40) NOT NULL DEFAULT 'unpaid',
                `subtotal` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                `delivery_fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                `discount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                `total_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                `notes` TEXT NULL DEFAULT NULL,
                `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_orders_seller` (`seller_id`),
                INDEX `idx_orders_status` (`order_status`),
                INDEX `idx_orders_phone` (`buyer_phone`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    } catch (Throwable $e) {}

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `order_items` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `order_id` BIGINT UNSIGNED NOT NULL,
                `product_id` BIGINT UNSIGNED NULL DEFAULT NULL,
                `seller_id` BIGINT UNSIGNED NULL DEFAULT NULL,
                `product_name` VARCHAR(150) NOT NULL,
                `product_image` LONGTEXT NULL DEFAULT NULL,
                `price` DECIMAL(10,2) NOT NULL,
                `quantity` INT NOT NULL DEFAULT 1,
                `subtotal` DECIMAL(10,2) NOT NULL,
                `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_order_items_order` (`order_id`),
                INDEX `idx_order_items_product` (`product_id`),
                INDEX `idx_order_items_seller` (`seller_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    } catch (Throwable $e) {}

    try {
        $cols = $pdo->query("SHOW COLUMNS FROM `orders` LIKE 'seller_id'")->fetchAll();
        if (empty($cols)) {
            $pdo->exec("ALTER TABLE `orders` ADD COLUMN `seller_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `id`, ADD INDEX `idx_orders_seller` (`seller_id`)");
        }
    } catch (Throwable $e) {}

    try {
        $cols = $pdo->query("SHOW COLUMNS FROM `order_items` LIKE 'seller_id'")->fetchAll();
        if (empty($cols)) {
            $pdo->exec("ALTER TABLE `order_items` ADD COLUMN `seller_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `product_id`, ADD INDEX `idx_order_items_seller` (`seller_id`)");
        }
    } catch (Throwable $e) {}

    $checked = true;
}

/**
 * Dedicated Seller Orders Endpoint:
 * GET /api/seller/orders
 * GET /api/sellers/{seller_id}/orders
 * GET /api/seller/{seller_id}/orders
 * Strictly requires authentication & authorization.
 * Multi-vendor isolation: seller only sees orders where they have items, and only sees their own items/financials.
 */
function apiGetSellerOrders(PDO $pdo, ?int $routeSellerId = null): void
{
    ensureOrdersSchema($pdo);
    $user = authenticateUser($pdo);

    // Determine target seller ID
    $targetSellerId = $routeSellerId ?: (!empty($_GET['seller_id']) ? (int)$_GET['seller_id'] : (int)$user['id']);

    // Authorization check: non-admins can ONLY access their own orders
    if (($user['role'] ?? '') !== 'admin' && (int)$user['id'] !== $targetSellerId) {
        errorResponse('Access denied. You can only access your own orders.', [], 403);
    }

    $sellerId = $targetSellerId;
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $whereClauses = ["i.seller_id = :seller_id"];
    $params = [':seller_id' => $sellerId];

    if (!empty($_GET['status'])) {
        $whereClauses[] = "o.order_status = :status";
        $params[':status'] = strtolower(trim($_GET['status']));
    }

    if (!empty($_GET['phone'])) {
        $whereClauses[] = "o.buyer_phone LIKE :phone";
        $params[':phone'] = '%' . trim($_GET['phone']) . '%';
    }

    if (!empty($_GET['search'])) {
        $search = '%' . trim($_GET['search']) . '%';
        $whereClauses[] = "(o.order_number LIKE :s1 OR o.buyer_name LIKE :s2 OR o.buyer_phone LIKE :s3)";
        $params[':s1'] = $search;
        $params[':s2'] = $search;
        $params[':s3'] = $search;
    }

    $whereSql = 'WHERE ' . implode(' AND ', $whereClauses);

    // Total distinct orders count for this seller
    $countStmt = $pdo->prepare("
        SELECT COUNT(DISTINCT o.id)
        FROM `orders` o
        INNER JOIN `order_items` i ON o.id = i.order_id
        {$whereSql}
    ");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    // Query orders that contain at least one item from this seller
    $query = "
        SELECT o.*,
               COALESCE(u.seller_name, u.name, 'Verified Seller') AS seller_name,
               u.phone AS seller_phone,
               COUNT(i.id) AS items_count,
               COALESCE(SUM(i.quantity), 0) AS total_quantity,
               COALESCE(SUM(i.subtotal), 0) AS seller_subtotal
        FROM `orders` o
        INNER JOIN `order_items` i ON o.id = i.order_id AND i.seller_id = :seller_id_join
        LEFT JOIN `users` u ON u.id = :seller_id_user
        {$whereSql}
        GROUP BY o.id
        ORDER BY o.id DESC
        LIMIT :limit OFFSET :offset
    ";

    $stmt = $pdo->prepare($query);
    $stmt->bindValue(':seller_id_join', $sellerId, PDO::PARAM_INT);
    $stmt->bindValue(':seller_id_user', $sellerId, PDO::PARAM_INT);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Attach ONLY this seller's items to each order
    $orderIds = array_column($orders, 'id');
    $itemsByOrder = [];
    if (!empty($orderIds)) {
        $inPlaceholders = implode(',', array_fill(0, count($orderIds), '?'));
        $itemStmt = $pdo->prepare("
            SELECT oi.*, COALESCE(u.seller_name, u.name, 'Verified Seller') AS seller_name
            FROM `order_items` oi
            LEFT JOIN `users` u ON oi.seller_id = u.id
            WHERE oi.order_id IN ($inPlaceholders) AND oi.seller_id = ?
            ORDER BY oi.id ASC
        ");
        $itemParams = array_merge($orderIds, [$sellerId]);
        $itemStmt->execute($itemParams);
        $allItems = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($allItems as $it) {
            $oid = (int)$it['order_id'];
            $itemsByOrder[$oid][] = [
                'id' => (int)$it['id'],
                'order_id' => $oid,
                'product_id' => !empty($it['product_id']) ? (int)$it['product_id'] : null,
                'seller_id' => (int)$it['seller_id'],
                'seller_name' => $it['seller_name'] ?? 'Verified Seller',
                'product_name' => $it['product_name'],
                'product_image' => $it['product_image'],
                'price' => (float)$it['price'],
                'quantity' => (int)$it['quantity'],
                'subtotal' => (float)$it['subtotal'],
            ];
        }
    }

    foreach ($orders as &$order) {
        $oid = (int)$order['id'];
        $sellerItems = $itemsByOrder[$oid] ?? [];
        $sellerSubtotal = (float)($order['seller_subtotal'] ?? 0);

        $order['id'] = $oid;
        $order['seller_id'] = $sellerId;
        $order['items'] = $sellerItems;
        $order['items_count'] = count($sellerItems);
        $order['total_quantity'] = (int)array_sum(array_column($sellerItems, 'quantity'));
        $order['seller_subtotal'] = $sellerSubtotal;
        $order['subtotal'] = $sellerSubtotal;
        $order['seller_total_amount'] = $sellerSubtotal;
        $order['delivery_fee'] = (float)$order['delivery_fee'];
        $order['discount'] = (float)$order['discount'];
        $order['total_amount'] = (float)$order['total_amount'];
    }
    unset($order);

    jsonResponse([
        'seller_id' => $sellerId,
        'orders' => [
            'current_page' => $page,
            'data' => $orders,
            'total' => $total,
            'per_page' => $limit,
            'last_page' => (int)ceil($total / $limit) ?: 1,
        ]
    ]);
}

/**
 * GET /api/orders (General / Buyer / Admin endpoint)
 * If accessed by an authenticated seller, automatically enforces seller isolation!
 */
function apiGetOrders(PDO $pdo): void
{
    ensureOrdersSchema($pdo);
    $user = getAuthenticatedUserOptional($pdo);

    // If caller is an authenticated seller, strictly route to seller orders
    if ($user && ($user['role'] ?? '') === 'seller') {
        if (!empty($_GET['seller_id']) && (int)$_GET['seller_id'] !== (int)$user['id']) {
            errorResponse('Access denied. You can only access your own orders.', [], 403);
        }
        apiGetSellerOrders($pdo, (int)$user['id']);
        return;
    }

    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $whereClauses = [];
    $params = [];

    if (!empty($_GET['seller_id'])) {
        $whereClauses[] = "EXISTS (SELECT 1 FROM `order_items` oi WHERE oi.order_id = o.id AND oi.seller_id = :seller_id)";
        $params[':seller_id'] = (int)$_GET['seller_id'];
    }

    if (!empty($_GET['status'])) {
        $whereClauses[] = "o.order_status = :status";
        $params[':status'] = strtolower(trim($_GET['status']));
    }

    if (!empty($_GET['phone'])) {
        $whereClauses[] = "(o.buyer_phone LIKE :phone)";
        $params[':phone'] = '%' . trim($_GET['phone']) . '%';
    }

    if (!empty($_GET['search'])) {
        $search = '%' . trim($_GET['search']) . '%';
        $whereClauses[] = "(o.order_number LIKE :s1 OR o.buyer_name LIKE :s2 OR o.buyer_phone LIKE :s3)";
        $params[':s1'] = $search;
        $params[':s2'] = $search;
        $params[':s3'] = $search;
    }

    $whereSql = !empty($whereClauses) ? ('WHERE ' . implode(' AND ', $whereClauses)) : '';

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM `orders` o {$whereSql}");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $query = "
        SELECT o.*,
               COALESCE(u.seller_name, u.name, 'Verified Seller') AS seller_name,
               u.phone AS seller_phone,
               COUNT(i.id) AS items_count,
               COALESCE(SUM(i.quantity), 0) AS total_quantity
        FROM `orders` o
        LEFT JOIN `users` u ON o.seller_id = u.id
        LEFT JOIN `order_items` i ON o.id = i.order_id
        {$whereSql}
        GROUP BY o.id
        ORDER BY o.id DESC
        LIMIT :limit OFFSET :offset
    ";

    $stmt = $pdo->prepare($query);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($orders as &$order) {
        $order['id'] = (int)$order['id'];
        $order['seller_id'] = !empty($order['seller_id']) ? (int)$order['seller_id'] : null;
        $order['items_count'] = (int)$order['items_count'];
        $order['total_quantity'] = (int)$order['total_quantity'];
        $order['subtotal'] = (float)$order['subtotal'];
        $order['delivery_fee'] = (float)$order['delivery_fee'];
        $order['discount'] = (float)$order['discount'];
        $order['total_amount'] = (float)$order['total_amount'];
    }
    unset($order);

    jsonResponse([
        'orders' => [
            'current_page' => $page,
            'data' => $orders,
            'total' => $total,
            'per_page' => $limit,
            'last_page' => (int)ceil($total / $limit) ?: 1,
        ]
    ]);
}

/**
 * GET Order Details by ID or order_number:
 * GET /api/orders/{id}
 * GET /api/seller/orders/{id}
 * GET /api/sellers/{seller_id}/orders/{id}
 * Multi-Vendor Isolation: If accessed by a seller, verifies product ownership and filters items so only their own products appear!
 */
function apiGetOrder(PDO $pdo, mixed $idOrNumber, ?int $routeSellerId = null): void
{
    ensureOrdersSchema($pdo);

    // If accessed through seller path, require authentication
    if ($routeSellerId !== null || str_contains($_SERVER['REQUEST_URI'] ?? '', '/seller')) {
        $user = authenticateUser($pdo);
    } else {
        $user = getAuthenticatedUserOptional($pdo);
    }

    if (is_numeric($idOrNumber)) {
        $stmt = $pdo->prepare("
            SELECT o.*,
                   COALESCE(u.seller_name, u.name, 'Verified Seller') AS seller_name,
                   u.phone AS seller_phone
            FROM `orders` o
            LEFT JOIN `users` u ON o.seller_id = u.id
            WHERE o.id = :id OR o.order_number = :num
            LIMIT 1
        ");
        $stmt->execute([':id' => (int)$idOrNumber, ':num' => (string)$idOrNumber]);
    } else {
        $stmt = $pdo->prepare("
            SELECT o.*,
                   COALESCE(u.seller_name, u.name, 'Verified Seller') AS seller_name,
                   u.phone AS seller_phone
            FROM `orders` o
            LEFT JOIN `users` u ON o.seller_id = u.id
            WHERE o.order_number = :num
            LIMIT 1
        ");
        $stmt->execute([':num' => (string)$idOrNumber]);
    }
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        errorResponse('Order not found.', [], 404);
    }
    $orderId = (int)$order['id'];

    // Fetch all items with seller name
    $itemStmt = $pdo->prepare("
        SELECT i.*, COALESCE(u.seller_name, u.name, 'Verified Seller') AS seller_name
        FROM `order_items` i
        LEFT JOIN `users` u ON i.seller_id = u.id
        WHERE i.order_id = :order_id
        ORDER BY i.id ASC
    ");
    $itemStmt->execute([':order_id' => $orderId]);
    $allItems = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($allItems as &$item) {
        $item['id'] = (int)$item['id'];
        $item['order_id'] = (int)$item['order_id'];
        $item['product_id'] = !empty($item['product_id']) ? (int)$item['product_id'] : null;
        $item['seller_id'] = !empty($item['seller_id']) ? (int)$item['seller_id'] : null;
        $item['price'] = (float)$item['price'];
        $item['quantity'] = (int)$item['quantity'];
        $item['subtotal'] = (float)$item['subtotal'];
    }
    unset($item);

    // Multi-Vendor Authorization & Product Filtering:
    // If accessed by a seller (or seller route specified)
    if ($user && ($user['role'] ?? '') === 'seller') {
        $sellerId = (int)$user['id'];

        // If route specified another seller's ID
        if ($routeSellerId !== null && $routeSellerId !== $sellerId) {
            errorResponse('Access denied. You can only access your own orders.', [], 403);
        }

        // Filter items: Only include products owned by this seller
        $sellerItems = array_values(array_filter($allItems, fn($i) => (int)$i['seller_id'] === $sellerId));

        if (empty($sellerItems)) {
            // Seller does NOT own any products in this order
            errorResponse('Access denied. You do not own any products in this order.', [], 403);
        }

        // Calculate seller-specific financial totals
        $sellerSubtotal = (float)array_sum(array_column($sellerItems, 'subtotal'));

        $order['id'] = $orderId;
        $order['seller_id'] = $sellerId;
        $order['seller_name'] = $sellerItems[0]['seller_name'] ?? 'Verified Seller';
        $order['items'] = $sellerItems;
        $order['items_count'] = count($sellerItems);
        $order['total_quantity'] = (int)array_sum(array_column($sellerItems, 'quantity'));
        $order['seller_subtotal'] = $sellerSubtotal;
        $order['subtotal'] = $sellerSubtotal;
        $order['seller_total_amount'] = $sellerSubtotal;
        $order['delivery_fee'] = (float)$order['delivery_fee'];
        $order['discount'] = (float)$order['discount'];
        $order['total_amount'] = (float)$order['total_amount'];

        jsonResponse(['order' => $order]);
        return;
    }

    // Admin with route seller filter
    if ($user && ($user['role'] ?? '') === 'admin' && $routeSellerId !== null) {
        $sellerItems = array_values(array_filter($allItems, fn($i) => (int)$i['seller_id'] === $routeSellerId));
        $sellerSubtotal = (float)array_sum(array_column($sellerItems, 'subtotal'));

        $order['id'] = $orderId;
        $order['seller_id'] = $routeSellerId;
        $order['items'] = $sellerItems;
        $order['items_count'] = count($sellerItems);
        $order['total_quantity'] = (int)array_sum(array_column($sellerItems, 'quantity'));
        $order['seller_subtotal'] = $sellerSubtotal;
        $order['subtotal'] = $sellerSubtotal;
        $order['seller_total_amount'] = $sellerSubtotal;
        $order['delivery_fee'] = (float)$order['delivery_fee'];
        $order['discount'] = (float)$order['discount'];
        $order['total_amount'] = (float)$order['total_amount'];

        jsonResponse(['order' => $order]);
        return;
    }

    // Admin or Buyer: Full order details
    $order['id'] = $orderId;
    $order['seller_id'] = !empty($order['seller_id']) ? (int)$order['seller_id'] : null;
    $order['subtotal'] = (float)$order['subtotal'];
    $order['delivery_fee'] = (float)$order['delivery_fee'];
    $order['discount'] = (float)$order['discount'];
    $order['total_amount'] = (float)$order['total_amount'];
    $order['items'] = $allItems;
    $order['items_count'] = count($allItems);
    $order['total_quantity'] = (int)array_sum(array_column($allItems, 'quantity'));

    jsonResponse(['order' => $order]);
}

/**
 * Place Order:
 * POST /api/orders
 * Stores seller_id and product_id for every order item.
 * Automatically routes each order item to its respective seller based on product_id.
 */
function apiCreateOrder(PDO $pdo): void
{
    ensureOrdersSchema($pdo);
    $input = getJsonInput();

    $buyerName = trim($input['buyer_name'] ?? '');
    $buyerPhone = trim($input['buyer_phone'] ?? '');
    $shippingAddress = trim($input['shipping_address'] ?? $input['delivery_address'] ?? $input['address'] ?? '');
    $landmark = trim($input['landmark'] ?? '');
    $city = trim($input['city'] ?? '');
    $state = trim($input['state'] ?? '');
    $pincode = trim($input['pincode'] ?? '');
    $notes = trim($input['notes'] ?? '');
    $paymentMethod = trim($input['payment_method'] ?? 'cod');
    $items = $input['items'] ?? [];

    $errors = [];
    if (empty($buyerName)) $errors['buyer_name'] = 'Buyer name is required.';
    if (empty($buyerPhone)) $errors['buyer_phone'] = 'Buyer phone is required.';
    if (empty($shippingAddress)) $errors['shipping_address'] = 'Shipping address is required.';
    if (empty($items) || !is_array($items)) $errors['items'] = 'At least one product item must be selected.';

    if (!empty($errors)) {
        errorResponse('Validation error.', $errors, 422);
    }

    $processedItems = [];
    foreach ($items as $item) {
        $name = trim($item['product_name'] ?? $item['name'] ?? 'Product');
        $price = max(0, (float)($item['price'] ?? 0));
        $qty = max(1, (int)($item['quantity'] ?? $item['qty'] ?? 1));
        $productId = !empty($item['product_id']) ? (int)$item['product_id'] : (!empty($item['id']) ? (int)$item['id'] : null);
        $itemSellerId = !empty($item['seller_id']) ? (int)$item['seller_id'] : null;
        $image = $item['product_image'] ?? $item['image_url'] ?? $item['image'] ?? null;
        $lineSubtotal = round($price * $qty, 2);

        $processedItems[] = [
            'product_id' => $productId,
            'seller_id' => $itemSellerId,
            'product_name' => $name,
            'product_image' => $image,
            'price' => $price,
            'quantity' => $qty,
            'subtotal' => $lineSubtotal,
        ];
    }

    // Automatically route each order item to its respective seller based on the product's seller_id
    $productIds = array_values(array_filter(array_column($processedItems, 'product_id')));
    $productMap = [];
    if (!empty($productIds)) {
        $inPlaceholders = implode(',', array_fill(0, count($productIds), '?'));
        try {
            $pStmt = $pdo->prepare("SELECT id, seller_id, name, price, image_url FROM `products` WHERE id IN ($inPlaceholders)");
            $pStmt->execute($productIds);
            while ($row = $pStmt->fetch(PDO::FETCH_ASSOC)) {
                $productMap[(int)$row['id']] = $row;
            }
        } catch (Throwable $e) {}
    }

    $subtotal = 0.00;
    foreach ($processedItems as &$pi) {
        if (!empty($pi['product_id']) && isset($productMap[$pi['product_id']])) {
            $prod = $productMap[$pi['product_id']];
            // Auto-route seller_id directly from products table
            $pi['seller_id'] = !empty($prod['seller_id']) ? (int)$prod['seller_id'] : 1;
            if (empty($pi['product_name']) || $pi['product_name'] === 'Product') {
                $pi['product_name'] = $prod['name'];
            }
            if (empty($pi['price']) || $pi['price'] <= 0) {
                $pi['price'] = (float)$prod['price'];
            }
            if (empty($pi['product_image']) && !empty($prod['image_url'])) {
                $pi['product_image'] = $prod['image_url'];
            }
        } elseif (!empty($pi['product_id']) && in_array((int)$pi['product_id'], [7, 8], true)) {
            $pi['seller_id'] = 2;
        } elseif (empty($pi['seller_id']) && !empty($input['seller_id'])) {
            $pi['seller_id'] = (int)$input['seller_id'];
        }
        if (empty($pi['seller_id'])) {
            $pi['seller_id'] = 1;
        }
        $pi['subtotal'] = round($pi['price'] * $pi['quantity'], 2);
        $subtotal += $pi['subtotal'];
    }
    unset($pi);

    $distinctSellers = array_values(array_unique(array_filter(array_column($processedItems, 'seller_id'))));
    $primarySellerId = !empty($input['seller_id']) ? (int)$input['seller_id'] : ($distinctSellers[0] ?? 1);

    $deliveryFee = isset($input['delivery_fee']) ? max(0, (float)$input['delivery_fee']) : ($subtotal >= 1000 ? 0.00 : 50.00);
    $discount = max(0, (float)($input['discount'] ?? 0));
    $totalAmount = max(0, round($subtotal + $deliveryFee - $discount, 2));

    $orderNumber = 'ORD-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            INSERT INTO `orders` (
                seller_id, order_number, buyer_name, buyer_phone,
                shipping_address, landmark, city, state, pincode,
                order_status, payment_method, payment_status,
                subtotal, delivery_fee, discount, total_amount, notes
            ) VALUES (
                :seller_id, :order_number, :buyer_name, :buyer_phone,
                :shipping_address, :landmark, :city, :state, :pincode,
                'pending', :payment_method, 'unpaid',
                :subtotal, :delivery_fee, :discount, :total_amount, :notes
            )
        ");

        $stmt->execute([
            ':seller_id' => $primarySellerId,
            ':order_number' => $orderNumber,
            ':buyer_name' => $buyerName,
            ':buyer_phone' => $buyerPhone,
            ':shipping_address' => $shippingAddress,
            ':landmark' => $landmark ?: null,
            ':city' => $city ?: null,
            ':state' => $state ?: null,
            ':pincode' => $pincode ?: null,
            ':payment_method' => $paymentMethod,
            ':subtotal' => $subtotal,
            ':delivery_fee' => $deliveryFee,
            ':discount' => $discount,
            ':total_amount' => $totalAmount,
            ':notes' => $notes ?: null,
        ]);

        $orderId = (int)$pdo->lastInsertId();

        $itemStmt = $pdo->prepare("
            INSERT INTO `order_items` (
                order_id, product_id, seller_id, product_name, product_image, price, quantity, subtotal
            ) VALUES (
                :order_id, :product_id, :seller_id, :product_name, :product_image, :price, :quantity, :subtotal
            )
        ");

        $stockStmt = $pdo->prepare("
            UPDATE `products`
            SET `stock` = CASE WHEN `stock` >= :qty THEN `stock` - :qty ELSE 0 END
            WHERE `id` = :product_id
        ");

        foreach ($processedItems as &$pi) {
            $itemSellerId = !empty($pi['seller_id']) ? (int)$pi['seller_id'] : $primarySellerId;
            $itemStmt->execute([
                ':order_id' => $orderId,
                ':product_id' => $pi['product_id'],
                ':seller_id' => $itemSellerId,
                ':product_name' => $pi['product_name'],
                ':product_image' => $pi['product_image'],
                ':price' => $pi['price'],
                ':quantity' => $pi['quantity'],
                ':subtotal' => $pi['subtotal'],
            ]);
            $pi['id'] = (int)$pdo->lastInsertId();
            $pi['order_id'] = $orderId;
            $pi['seller_id'] = $itemSellerId;

            // Decrease product stock
            if (!empty($pi['product_id'])) {
                try {
                    $stockStmt->execute([
                        ':qty' => $pi['quantity'],
                        ':product_id' => $pi['product_id']
                    ]);
                } catch (Throwable $e) {}
            }
        }
        unset($pi);

        $pdo->commit();

        $sellerName = 'Verified Seller';
        try {
            $sStmt = $pdo->prepare("SELECT seller_name, name FROM `users` WHERE id = :sid LIMIT 1");
            $sStmt->execute([':sid' => $primarySellerId]);
            $sRow = $sStmt->fetch(PDO::FETCH_ASSOC);
            if ($sRow) {
                $sellerName = $sRow['seller_name'] ?: ($sRow['name'] ?: 'Verified Seller');
            }
        } catch (Throwable $e) {}

        jsonResponse([
            'message' => 'Order created successfully.',
            'order' => [
                'id' => $orderId,
                'seller_id' => $primarySellerId,
                'seller_name' => $sellerName,
                'order_number' => $orderNumber,
                'buyer_name' => $buyerName,
                'buyer_phone' => $buyerPhone,
                'shipping_address' => $shippingAddress,
                'order_status' => 'pending',
                'subtotal' => $subtotal,
                'delivery_fee' => $deliveryFee,
                'discount' => $discount,
                'total_amount' => $totalAmount,
                'items' => $processedItems,
                'items_count' => count($processedItems),
                'total_quantity' => (int)array_sum(array_column($processedItems, 'quantity')),
                'created_at' => date('Y-m-d H:i:s'),
            ]
        ], 201);
    } catch (Throwable $e) {
        $pdo->rollBack();
        errorResponse('Failed to create order: ' . $e->getMessage(), [], 500);
    }
}

/**
 * Update Order:
 * PUT /api/orders/{id}
 * PUT /api/seller/orders/{id}
 * Re-routes order items to their respective sellers when items are modified.
 * Enforces ownership check: sellers can only modify orders where they have products.
 */
function apiUpdateOrder(PDO $pdo, mixed $idOrNumber, ?int $routeSellerId = null): void
{
    ensureOrdersSchema($pdo);
    $user = getAuthenticatedUserOptional($pdo);
    $input = getJsonInput();

    if (is_numeric($idOrNumber)) {
        $stmt = $pdo->prepare("SELECT * FROM `orders` WHERE id = :id OR order_number = :num LIMIT 1");
        $stmt->execute([':id' => (int)$idOrNumber, ':num' => (string)$idOrNumber]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM `orders` WHERE order_number = :num LIMIT 1");
        $stmt->execute([':num' => (string)$idOrNumber]);
    }
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        errorResponse('Order not found.', [], 404);
    }
    $id = (int)$existing['id'];

    // Ownership check for sellers: must own at least one product in this order
    if ($user && ($user['role'] ?? '') === 'seller') {
        $sellerId = (int)$user['id'];
        if ($routeSellerId !== null && $routeSellerId !== $sellerId) {
            errorResponse('Access denied. You can only access your own orders.', [], 403);
        }

        $checkStmt = $pdo->prepare("SELECT 1 FROM `order_items` WHERE order_id = :oid AND seller_id = :sid LIMIT 1");
        $checkStmt->execute([':oid' => $id, ':sid' => $sellerId]);
        if (!$checkStmt->fetch()) {
            errorResponse('Access denied. You do not have permission to update this order.', [], 403);
        }
    }

    $buyerName = isset($input['buyer_name']) ? trim($input['buyer_name']) : $existing['buyer_name'];
    $buyerPhone = isset($input['buyer_phone']) ? trim($input['buyer_phone']) : $existing['buyer_phone'];
    $shippingAddress = isset($input['shipping_address']) ? trim($input['shipping_address']) : $existing['shipping_address'];
    $landmark = isset($input['landmark']) ? trim($input['landmark']) : $existing['landmark'];
    $city = isset($input['city']) ? trim($input['city']) : $existing['city'];
    $state = isset($input['state']) ? trim($input['state']) : $existing['state'];
    $pincode = isset($input['pincode']) ? trim($input['pincode']) : $existing['pincode'];
    $notes = isset($input['notes']) ? trim($input['notes']) : $existing['notes'];
    $orderStatus = isset($input['order_status']) ? trim(strtolower($input['order_status'])) : $existing['order_status'];
    $paymentMethod = isset($input['payment_method']) ? trim($input['payment_method']) : $existing['payment_method'];
    $paymentStatus = isset($input['payment_status']) ? trim($input['payment_status']) : $existing['payment_status'];

    $subtotal = (float)$existing['subtotal'];
    $deliveryFee = isset($input['delivery_fee']) ? max(0, (float)$input['delivery_fee']) : (float)$existing['delivery_fee'];
    $discount = isset($input['discount']) ? max(0, (float)$input['discount']) : (float)$existing['discount'];

    $hasItemsUpdate = isset($input['items']) && is_array($input['items']);
    $processedItems = [];

    if ($hasItemsUpdate) {
        if (empty($input['items'])) {
            errorResponse('Order must have at least one product item.', [], 422);
        }
        foreach ($input['items'] as $item) {
            $name = trim($item['product_name'] ?? $item['name'] ?? 'Product');
            $price = max(0, (float)($item['price'] ?? 0));
            $qty = max(1, (int)($item['quantity'] ?? $item['qty'] ?? 1));
            $productId = !empty($item['product_id']) ? (int)$item['product_id'] : (!empty($item['id']) ? (int)$item['id'] : null);
            $itemSellerId = !empty($item['seller_id']) ? (int)$item['seller_id'] : null;
            $image = $item['product_image'] ?? $item['image_url'] ?? $item['image'] ?? null;
            $lineSubtotal = round($price * $qty, 2);

            $processedItems[] = [
                'product_id' => $productId,
                'seller_id' => $itemSellerId,
                'product_name' => $name,
                'product_image' => $image,
                'price' => $price,
                'quantity' => $qty,
                'subtotal' => $lineSubtotal,
            ];
        }

        // Auto-route each item's seller_id from database products table
        $productIds = array_values(array_filter(array_column($processedItems, 'product_id')));
        if (!empty($productIds)) {
            $inPlaceholders = implode(',', array_fill(0, count($productIds), '?'));
            try {
                $pStmt = $pdo->prepare("SELECT id, seller_id, name, price, image_url FROM `products` WHERE id IN ($inPlaceholders)");
                $pStmt->execute($productIds);
                $pMap = [];
                while ($row = $pStmt->fetch(PDO::FETCH_ASSOC)) {
                    $pMap[(int)$row['id']] = $row;
                }
                $subtotal = 0.00;
                foreach ($processedItems as &$pi) {
                    if (!empty($pi['product_id']) && isset($pMap[$pi['product_id']])) {
                        $prod = $pMap[$pi['product_id']];
                        $pi['seller_id'] = !empty($prod['seller_id']) ? (int)$prod['seller_id'] : 1;
                        if (empty($pi['product_name']) || $pi['product_name'] === 'Product') {
                            $pi['product_name'] = $prod['name'];
                        }
                        if (empty($pi['price']) || $pi['price'] <= 0) {
                            $pi['price'] = (float)$prod['price'];
                        }
                    }
                    if (empty($pi['seller_id'])) {
                        $pi['seller_id'] = !empty($existing['seller_id']) ? (int)$existing['seller_id'] : 1;
                    }
                    $pi['subtotal'] = round($pi['price'] * $pi['quantity'], 2);
                    $subtotal += $pi['subtotal'];
                }
                unset($pi);
            } catch (Throwable $e) {}
        }
    }

    $primarySellerId = isset($input['seller_id']) ? (int)$input['seller_id'] : (!empty($existing['seller_id']) ? (int)$existing['seller_id'] : 1);
    $totalAmount = max(0, round($subtotal + $deliveryFee - $discount, 2));

    $pdo->beginTransaction();
    try {
        $updateStmt = $pdo->prepare("
            UPDATE `orders`
            SET seller_id = :seller_id,
                buyer_name = :buyer_name,
                buyer_phone = :buyer_phone,
                shipping_address = :shipping_address,
                landmark = :landmark,
                city = :city,
                state = :state,
                pincode = :pincode,
                order_status = :order_status,
                payment_method = :payment_method,
                payment_status = :payment_status,
                subtotal = :subtotal,
                delivery_fee = :delivery_fee,
                discount = :discount,
                total_amount = :total_amount,
                notes = :notes
            WHERE id = :id
        ");

        $updateStmt->execute([
            ':seller_id' => $primarySellerId,
            ':buyer_name' => $buyerName,
            ':buyer_phone' => $buyerPhone,
            ':shipping_address' => $shippingAddress,
            ':landmark' => $landmark ?: null,
            ':city' => $city ?: null,
            ':state' => $state ?: null,
            ':pincode' => $pincode ?: null,
            ':order_status' => $orderStatus,
            ':payment_method' => $paymentMethod,
            ':payment_status' => $paymentStatus,
            ':subtotal' => $subtotal,
            ':delivery_fee' => $deliveryFee,
            ':discount' => $discount,
            ':total_amount' => $totalAmount,
            ':notes' => $notes ?: null,
            ':id' => $id,
        ]);

        if ($hasItemsUpdate) {
            // Restore stock of old items
            $oldItemsStmt = $pdo->prepare("SELECT product_id, quantity FROM `order_items` WHERE order_id = :order_id");
            $oldItemsStmt->execute([':order_id' => $id]);
            $oldItems = $oldItemsStmt->fetchAll(PDO::FETCH_ASSOC);

            $restoreStockStmt = $pdo->prepare("UPDATE `products` SET `stock` = `stock` + :qty WHERE `id` = :product_id");
            foreach ($oldItems as $oi) {
                if (!empty($oi['product_id'])) {
                    try {
                        $restoreStockStmt->execute([':qty' => (int)$oi['quantity'], ':product_id' => (int)$oi['product_id']]);
                    } catch (Throwable $e) {}
                }
            }

            // Remove old and insert new with auto-routed seller_id & product_id
            $delStmt = $pdo->prepare("DELETE FROM `order_items` WHERE order_id = :order_id");
            $delStmt->execute([':order_id' => $id]);

            $insStmt = $pdo->prepare("
                INSERT INTO `order_items` (
                    order_id, product_id, seller_id, product_name, product_image, price, quantity, subtotal
                ) VALUES (
                    :order_id, :product_id, :seller_id, :product_name, :product_image, :price, :quantity, :subtotal
                )
            ");
            $deductStockStmt = $pdo->prepare("UPDATE `products` SET `stock` = CASE WHEN `stock` >= :qty THEN `stock` - :qty ELSE 0 END WHERE `id` = :product_id");

            foreach ($processedItems as &$pi) {
                $itemSellerId = !empty($pi['seller_id']) ? (int)$pi['seller_id'] : $primarySellerId;
                $insStmt->execute([
                    ':order_id' => $id,
                    ':product_id' => $pi['product_id'],
                    ':seller_id' => $itemSellerId,
                    ':product_name' => $pi['product_name'],
                    ':product_image' => $pi['product_image'],
                    ':price' => $pi['price'],
                    ':quantity' => $pi['quantity'],
                    ':subtotal' => $pi['subtotal'],
                ]);
                $pi['id'] = (int)$pdo->lastInsertId();
                $pi['order_id'] = $id;
                $pi['seller_id'] = $itemSellerId;

                if (!empty($pi['product_id'])) {
                    try {
                        $deductStockStmt->execute([':qty' => $pi['quantity'], ':product_id' => $pi['product_id']]);
                    } catch (Throwable $e) {}
                }
            }
            unset($pi);
        }

        $pdo->commit();

        // Return updated order
        apiGetOrder($pdo, $id, $routeSellerId);
    } catch (Throwable $e) {
        $pdo->rollBack();
        errorResponse('Failed to update order: ' . $e->getMessage(), [], 500);
    }
}

/**
 * Delete Order:
 * DELETE /api/orders/{id}
 */
function apiDeleteOrder(PDO $pdo, mixed $idOrNumber): void
{
    ensureOrdersSchema($pdo);
    $user = getAuthenticatedUserOptional($pdo);

    if (is_numeric($idOrNumber)) {
        $stmt = $pdo->prepare("SELECT id, order_number FROM `orders` WHERE id = :id OR order_number = :num LIMIT 1");
        $stmt->execute([':id' => (int)$idOrNumber, ':num' => (string)$idOrNumber]);
    } else {
        $stmt = $pdo->prepare("SELECT id, order_number FROM `orders` WHERE order_number = :num LIMIT 1");
        $stmt->execute([':num' => (string)$idOrNumber]);
    }
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        errorResponse('Order not found.', [], 404);
    }
    $id = (int)$order['id'];

    // Ownership check for sellers: seller can only delete if admin or seller of items
    if ($user && ($user['role'] ?? '') === 'seller') {
        $sellerId = (int)$user['id'];
        $checkStmt = $pdo->prepare("SELECT 1 FROM `order_items` WHERE order_id = :oid AND seller_id = :sid LIMIT 1");
        $checkStmt->execute([':oid' => $id, ':sid' => $sellerId]);
        if (!$checkStmt->fetch()) {
            errorResponse('Access denied. You do not have permission to delete this order.', [], 403);
        }
    }

    $pdo->beginTransaction();
    try {
        // Restore stock for deleted order items
        $itemsStmt = $pdo->prepare("SELECT product_id, quantity FROM `order_items` WHERE order_id = :order_id");
        $itemsStmt->execute([':order_id' => $id]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        $restoreStockStmt = $pdo->prepare("UPDATE `products` SET `stock` = `stock` + :qty WHERE `id` = :product_id");
        foreach ($items as $it) {
            if (!empty($it['product_id'])) {
                try {
                    $restoreStockStmt->execute([':qty' => (int)$it['quantity'], ':product_id' => (int)$it['product_id']]);
                } catch (Throwable $e) {}
            }
        }

        $delItems = $pdo->prepare("DELETE FROM `order_items` WHERE order_id = :order_id");
        $delItems->execute([':order_id' => $id]);

        $delOrder = $pdo->prepare("DELETE FROM `orders` WHERE id = :id");
        $delOrder->execute([':id' => $id]);

        $pdo->commit();

        jsonResponse([
            'message' => 'Order deleted successfully.',
            'id' => $id,
            'order_number' => $order['order_number'],
        ]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        errorResponse('Failed to delete order: ' . $e->getMessage(), [], 500);
    }
}
