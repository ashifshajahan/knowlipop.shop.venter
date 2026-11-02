<?php

/**
 * Pure PHP API: Buyer Orders Controller
 * Supports Order Placement, Details Retrieval, and Order Editing (Items, Quantities, Address, Status)
 */

require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';

function ensureOrdersSchema(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `orders` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
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
            INDEX `idx_orders_status` (`order_status`),
            INDEX `idx_orders_phone` (`buyer_phone`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `order_items` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `order_id` BIGINT UNSIGNED NOT NULL,
            `product_id` BIGINT UNSIGNED NULL DEFAULT NULL,
            `product_name` VARCHAR(150) NOT NULL,
            `product_image` LONGTEXT NULL DEFAULT NULL,
            `price` DECIMAL(10,2) NOT NULL,
            `quantity` INT NOT NULL DEFAULT 1,
            `subtotal` DECIMAL(10,2) NOT NULL,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_order_items_order` (`order_id`),
            INDEX `idx_order_items_product` (`product_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    $checked = true;
}

function apiGetOrders(PDO $pdo): void
{
    ensureOrdersSchema($pdo);

    $page = max(1, (int) ($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int) ($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $whereClauses = [];
    $params = [];

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
    $total = (int) $countStmt->fetchColumn();

    $query = "
        SELECT o.*,
               COUNT(i.id) AS items_count,
               COALESCE(SUM(i.quantity), 0) AS total_quantity
        FROM `orders` o
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

    // Format fields
    foreach ($orders as &$order) {
        $order['id'] = (int) $order['id'];
        $order['items_count'] = (int) $order['items_count'];
        $order['total_quantity'] = (int) $order['total_quantity'];
        $order['subtotal'] = (float) $order['subtotal'];
        $order['delivery_fee'] = (float) $order['delivery_fee'];
        $order['discount'] = (float) $order['discount'];
        $order['total_amount'] = (float) $order['total_amount'];
    }

    jsonResponse([
        'orders' => [
            'current_page' => $page,
            'data' => $orders,
            'total' => $total,
            'per_page' => $limit,
            'last_page' => ceil($total / $limit) ?: 1,
        ]
    ]);
}

function apiGetOrder(PDO $pdo, mixed $idOrNumber): void
{
    ensureOrdersSchema($pdo);

    if (is_numeric($idOrNumber)) {
        $stmt = $pdo->prepare("SELECT * FROM `orders` WHERE id = :id OR order_number = :num LIMIT 1");
        $stmt->execute([':id' => (int) $idOrNumber, ':num' => (string) $idOrNumber]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM `orders` WHERE order_number = :num LIMIT 1");
        $stmt->execute([':num' => (string) $idOrNumber]);
    }
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        errorResponse('Order not found.', [], 404);
    }
    $id = (int) $order['id'];

    $order['id'] = (int) $order['id'];
    $order['subtotal'] = (float) $order['subtotal'];
    $order['delivery_fee'] = (float) $order['delivery_fee'];
    $order['discount'] = (float) $order['discount'];
    $order['total_amount'] = (float) $order['total_amount'];

    $itemStmt = $pdo->prepare("SELECT * FROM `order_items` WHERE order_id = :order_id ORDER BY id ASC");
    $itemStmt->execute([':order_id' => $id]);
    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($items as &$item) {
        $item['id'] = (int) $item['id'];
        $item['order_id'] = (int) $item['order_id'];
        $item['product_id'] = $item['product_id'] ? (int) $item['product_id'] : null;
        $item['price'] = (float) $item['price'];
        $item['quantity'] = (int) $item['quantity'];
        $item['subtotal'] = (float) $item['subtotal'];
    }

    $order['items'] = $items;
    $order['items_count'] = count($items);
    $order['total_quantity'] = array_sum(array_column($items, 'quantity'));

    jsonResponse(['order' => $order]);
}

function apiCreateOrder(PDO $pdo): void
{
    ensureOrdersSchema($pdo);
    $input = getJsonInput();

    $buyerName = trim($input['buyer_name'] ?? '');
    $buyerPhone = trim($input['buyer_phone'] ?? '');
    $shippingAddress = trim($input['shipping_address'] ?? '');
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

    $subtotal = 0.00;
    $processedItems = [];

    foreach ($items as $item) {
        $name = trim($item['product_name'] ?? $item['name'] ?? 'Product');
        $price = max(0, (float) ($item['price'] ?? 0));
        $qty = max(1, (int) ($item['quantity'] ?? $item['qty'] ?? 1));
        $productId = !empty($item['product_id']) ? (int) $item['product_id'] : (!empty($item['id']) ? (int) $item['id'] : null);
        $image = $item['product_image'] ?? $item['image_url'] ?? $item['image'] ?? null;
        $lineSubtotal = round($price * $qty, 2);

        $subtotal += $lineSubtotal;
        $processedItems[] = [
            'product_id' => $productId,
            'product_name' => $name,
            'product_image' => $image,
            'price' => $price,
            'quantity' => $qty,
            'subtotal' => $lineSubtotal,
        ];
    }

    $deliveryFee = isset($input['delivery_fee']) ? max(0, (float) $input['delivery_fee']) : ($subtotal >= 1000 ? 0.00 : 50.00);
    $discount = max(0, (float) ($input['discount'] ?? 0));
    $totalAmount = max(0, round($subtotal + $deliveryFee - $discount, 2));

    // Generate unique human-readable order number
    $orderNumber = 'ORD-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            INSERT INTO `orders` (
                order_number, buyer_name, buyer_phone,
                shipping_address, landmark, city, state, pincode,
                order_status, payment_method, payment_status,
                subtotal, delivery_fee, discount, total_amount, notes
            ) VALUES (
                :order_number, :buyer_name, :buyer_phone,
                :shipping_address, :landmark, :city, :state, :pincode,
                'pending', :payment_method, 'unpaid',
                :subtotal, :delivery_fee, :discount, :total_amount, :notes
            )
        ");

        $stmt->execute([
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

        $orderId = (int) $pdo->lastInsertId();

        $itemStmt = $pdo->prepare("
            INSERT INTO `order_items` (
                order_id, product_id, product_name, product_image, price, quantity, subtotal
            ) VALUES (
                :order_id, :product_id, :product_name, :product_image, :price, :quantity, :subtotal
            )
        ");

        $stockStmt = $pdo->prepare("
            UPDATE `products`
            SET `stock` = GREATEST(0, `stock` - :qty)
            WHERE `id` = :product_id
        ");

        foreach ($processedItems as &$pi) {
            $itemStmt->execute([
                ':order_id' => $orderId,
                ':product_id' => $pi['product_id'],
                ':product_name' => $pi['product_name'],
                ':product_image' => $pi['product_image'],
                ':price' => $pi['price'],
                ':quantity' => $pi['quantity'],
                ':subtotal' => $pi['subtotal'],
            ]);
            $pi['id'] = (int) $pdo->lastInsertId();
            $pi['order_id'] = $orderId;

            // Decrease product stock
            if (!empty($pi['product_id'])) {
                $stockStmt->execute([
                    ':qty' => $pi['quantity'],
                    ':product_id' => $pi['product_id']
                ]);
            }
        }

        $pdo->commit();

        jsonResponse([
            'message' => 'Order created successfully.',
            'order' => [
                'id' => $orderId,
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
                'created_at' => date('Y-m-d H:i:s'),
            ]
        ], 201);
    } catch (Throwable $e) {
        $pdo->rollBack();
        errorResponse('Failed to create order: ' . $e->getMessage(), [], 500);
    }
}

function apiUpdateOrder(PDO $pdo, mixed $idOrNumber): void
{
    ensureOrdersSchema($pdo);
    $input = getJsonInput();

    if (is_numeric($idOrNumber)) {
        $stmt = $pdo->prepare("SELECT * FROM `orders` WHERE id = :id OR order_number = :num LIMIT 1");
        $stmt->execute([':id' => (int) $idOrNumber, ':num' => (string) $idOrNumber]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM `orders` WHERE order_number = :num LIMIT 1");
        $stmt->execute([':num' => (string) $idOrNumber]);
    }
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        errorResponse('Order not found.', [], 404);
    }
    $id = (int) $existing['id'];

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

    $subtotal = (float) $existing['subtotal'];
    $deliveryFee = isset($input['delivery_fee']) ? max(0, (float) $input['delivery_fee']) : (float) $existing['delivery_fee'];
    $discount = isset($input['discount']) ? max(0, (float) $input['discount']) : (float) $existing['discount'];

    $hasItemsUpdate = isset($input['items']) && is_array($input['items']);
    $processedItems = [];

    if ($hasItemsUpdate) {
        if (empty($input['items'])) {
            errorResponse('Order must have at least one product item.', [], 422);
        }
        $subtotal = 0.00;
        foreach ($input['items'] as $item) {
            $name = trim($item['product_name'] ?? $item['name'] ?? 'Product');
            $price = max(0, (float) ($item['price'] ?? 0));
            $qty = max(1, (int) ($item['quantity'] ?? $item['qty'] ?? 1));
            $productId = !empty($item['product_id']) ? (int) $item['product_id'] : (!empty($item['id']) ? (int) $item['id'] : null);
            $image = $item['product_image'] ?? $item['image_url'] ?? $item['image'] ?? null;
            $lineSubtotal = round($price * $qty, 2);

            $subtotal += $lineSubtotal;
            $processedItems[] = [
                'product_id' => $productId,
                'product_name' => $name,
                'product_image' => $image,
                'price' => $price,
                'quantity' => $qty,
                'subtotal' => $lineSubtotal,
            ];
        }
    }

    $totalAmount = max(0, round($subtotal + $deliveryFee - $discount, 2));

    $pdo->beginTransaction();
    try {
        $updateStmt = $pdo->prepare("
            UPDATE `orders`
            SET buyer_name = :buyer_name,
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
            // Restore stock of old items before replacing
            $oldItemsStmt = $pdo->prepare("SELECT product_id, quantity FROM `order_items` WHERE order_id = :order_id");
            $oldItemsStmt->execute([':order_id' => $id]);
            $oldItems = $oldItemsStmt->fetchAll(PDO::FETCH_ASSOC);

            $restoreStockStmt = $pdo->prepare("UPDATE `products` SET `stock` = `stock` + :qty WHERE `id` = :product_id");
            foreach ($oldItems as $oi) {
                if (!empty($oi['product_id'])) {
                    $restoreStockStmt->execute([':qty' => (int) $oi['quantity'], ':product_id' => (int) $oi['product_id']]);
                }
            }

            // Delete old items and insert fresh
            $delStmt = $pdo->prepare("DELETE FROM `order_items` WHERE order_id = :order_id");
            $delStmt->execute([':order_id' => $id]);

            $insStmt = $pdo->prepare("
                INSERT INTO `order_items` (
                    order_id, product_id, product_name, product_image, price, quantity, subtotal
                ) VALUES (
                    :order_id, :product_id, :product_name, :product_image, :price, :quantity, :subtotal
                )
            ");
            $deductStockStmt = $pdo->prepare("UPDATE `products` SET `stock` = GREATEST(0, `stock` - :qty) WHERE `id` = :product_id");

            foreach ($processedItems as &$pi) {
                $insStmt->execute([
                    ':order_id' => $id,
                    ':product_id' => $pi['product_id'],
                    ':product_name' => $pi['product_name'],
                    ':product_image' => $pi['product_image'],
                    ':price' => $pi['price'],
                    ':quantity' => $pi['quantity'],
                    ':subtotal' => $pi['subtotal'],
                ]);
                $pi['id'] = (int) $pdo->lastInsertId();
                $pi['order_id'] = $id;

                if (!empty($pi['product_id'])) {
                    $deductStockStmt->execute([':qty' => $pi['quantity'], ':product_id' => $pi['product_id']]);
                }
            }
        }

        $pdo->commit();

        // Return updated order
        apiGetOrder($pdo, $id);
    } catch (Throwable $e) {
        $pdo->rollBack();
        errorResponse('Failed to update order: ' . $e->getMessage(), [], 500);
    }
}

function apiDeleteOrder(PDO $pdo, mixed $idOrNumber): void
{
    ensureOrdersSchema($pdo);

    if (is_numeric($idOrNumber)) {
        $stmt = $pdo->prepare("SELECT id, order_number FROM `orders` WHERE id = :id OR order_number = :num LIMIT 1");
        $stmt->execute([':id' => (int) $idOrNumber, ':num' => (string) $idOrNumber]);
    } else {
        $stmt = $pdo->prepare("SELECT id, order_number FROM `orders` WHERE order_number = :num LIMIT 1");
        $stmt->execute([':num' => (string) $idOrNumber]);
    }
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        errorResponse('Order not found.', [], 404);
    }
    $id = (int) $order['id'];

    $pdo->beginTransaction();
    try {
        // Restore stock for deleted order items
        $itemsStmt = $pdo->prepare("SELECT product_id, quantity FROM `order_items` WHERE order_id = :order_id");
        $itemsStmt->execute([':order_id' => $id]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        $restoreStockStmt = $pdo->prepare("UPDATE `products` SET `stock` = `stock` + :qty WHERE `id` = :product_id");
        foreach ($items as $it) {
            if (!empty($it['product_id'])) {
                $restoreStockStmt->execute([':qty' => (int) $it['quantity'], ':product_id' => (int) $it['product_id']]);
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
