<?php

/**
 * Pure PHP: Admin Dashboard & Seller Management
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/gst.php';
require_once __DIR__ . '/../api/products.php';
require_once __DIR__ . '/../helpers/seller_deletion.php';

$rawJwtToken = getBearerToken();
$adminJwtPayload = !empty($rawJwtToken) ? verifyJwtToken((string) $rawJwtToken, getJwtSecret()) : null;
$hasValidAdminJwt = is_array($adminJwtPayload) && strtolower((string) ($adminJwtPayload['role'] ?? '')) === 'admin';

if (empty($_SESSION['admin_logged_in'])) {
    if ($hasValidAdminJwt) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_id'] = (int) ($adminJwtPayload['id'] ?? 1);
        $_SESSION['admin_email'] = $adminJwtPayload['email'] ?? ($_COOKIE['admin_email'] ?? 'admin@vendor.app');
    } else {
        header('Location: /admin/login');
        exit;
    }
}

$pdo = getDatabaseConnection();
ensureGstTables($pdo);
ensureProductCategoriesTable($pdo);
ensureSellerDeletionSchemaPhp($pdo);

if (!$hasValidAdminJwt && empty($_SERVER['HTTP_AUTHORIZATION']) && !headers_sent()) {
    $mintedAdminJwt = createToken(
        $pdo,
        (int) ($_SESSION['admin_id'] ?? 1),
        null,
        'admin-dashboard',
        $_SESSION['admin_email'] ?? 'admin@vendor.app',
        'admin',
        1
    );
    setcookie('admin_token', $mintedAdminJwt, time() + 86400 * 7, '/');
}
$success = null;
$error = null;

// Handle view / preview GST document for admin session
if (isset($_GET['action']) && $_GET['action'] === 'view_gst_doc' && !empty($_GET['id'])) {
    $docId = (int) $_GET['id'];
    $mode = (isset($_GET['mode']) && $_GET['mode'] === 'download') ? 'download' : 'preview';
    $docStmt = $pdo->prepare("SELECT * FROM seller_gst_documents WHERE id = :id LIMIT 1");
    $docStmt->execute([':id' => $docId]);
    $doc = $docStmt->fetch();
    $filePath = $doc ? resolveGstFilePathPhp($doc) : null;
    if (!$doc || !$filePath) {
        http_response_code(404);
        echo 'Document file not found on server.';
        exit;
    }
    $ext = strtolower(pathinfo($doc['original_name'] ?: $filePath, PATHINFO_EXTENSION));
    $mimeType = ($ext === 'pdf') ? 'application/pdf' : ($doc['mime_type'] ?: 'application/octet-stream');
    $fileName = $doc['original_name'] ?: basename($filePath);
    $disposition = ($mode === 'download') ? 'attachment' : 'inline';
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . filesize($filePath));
    header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode($fileName) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($filePath);
    exit;
}

// Handle JSON fetch of seller GST details & documents for review modal
if (isset($_GET['action']) && $_GET['action'] === 'get_gst_details' && !empty($_GET['seller_id'])) {
    $targetId = (int) $_GET['seller_id'];
    $gstStmt = $pdo->prepare("SELECT * FROM seller_gst_details WHERE seller_id = :id LIMIT 1");
    $gstStmt->execute([':id' => $targetId]);
    $gst = $gstStmt->fetch();

    $docsStmt = $pdo->prepare("SELECT * FROM seller_gst_documents WHERE seller_id = :id ORDER BY is_primary DESC, id DESC");
    $docsStmt->execute([':id' => $targetId]);
    $docs = array_map(fn($d) => formatGstDocument($d, true), $docsStmt->fetchAll());

    $logsStmt = $pdo->prepare("SELECT * FROM seller_gst_verification_logs WHERE seller_id = :id ORDER BY id DESC");
    $logsStmt->execute([':id' => $targetId]);
    $logs = $logsStmt->fetchAll();

    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'success',
        'gst' => $gst,
        'documents' => $docs,
        'logs' => $logs
    ]);
    exit;
}

// Handle approve GST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'approve_gst') {
    $sellerId = (int) ($_POST['seller_id'] ?? 0);
    $notes = trim($_POST['notes'] ?? 'Approved by administrator');
    $adminId = 1;

    $gstStmt = $pdo->prepare("SELECT * FROM seller_gst_details WHERE seller_id = :seller_id LIMIT 1");
    $gstStmt->execute([':seller_id' => $sellerId]);
    $gst = $gstStmt->fetch();

    if ($gst) {
        $prevStatus = $gst['status'];
        $up = $pdo->prepare("
            UPDATE seller_gst_details
            SET status = 'Approved', rejection_reason = NULL, verified_by = :admin, verified_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $up->execute([':admin' => $adminId, ':id' => $gst['id']]);
        logGstVerification($pdo, $sellerId, (int)$gst['id'], 'approved', $prevStatus, 'Approved', $notes, $adminId, 'admin');
        $success = "Seller #{$sellerId} GST verification has been APPROVED successfully!";
    } else {
        $error = "No GST record found for Seller #{$sellerId}.";
    }
}

// Handle reject GST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reject_gst') {
    $sellerId = (int) ($_POST['seller_id'] ?? 0);
    $reason = trim($_POST['rejection_reason'] ?? '');
    $adminId = 1;

    if (empty($reason)) {
        $error = 'A rejection reason is mandatory when rejecting GST documents.';
    } else {
        $gstStmt = $pdo->prepare("SELECT * FROM seller_gst_details WHERE seller_id = :seller_id LIMIT 1");
        $gstStmt->execute([':seller_id' => $sellerId]);
        $gst = $gstStmt->fetch();

        if ($gst) {
            $prevStatus = $gst['status'];
            $up = $pdo->prepare("
                UPDATE seller_gst_details
                SET status = 'Rejected', rejection_reason = :reason, verified_by = :admin, verified_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");
            $up->execute([':reason' => $reason, ':admin' => $adminId, ':id' => $gst['id']]);
            logGstVerification($pdo, $sellerId, (int)$gst['id'], 'rejected', $prevStatus, 'Rejected', $reason, $adminId, 'admin');
            $success = "Seller #{$sellerId} GST has been REJECTED. Rejection reason has been recorded.";
        } else {
            $error = "No GST record found for Seller #{$sellerId}.";
        }
    }
}

// Handle create seller
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_seller') {
    $name = trim($_POST['seller_name'] ?? '');
    $rawPhone = trim($_POST['phone'] ?? '');
    $phone = '';
    if ($rawPhone !== '') {
        $strP = preg_replace('/[\s\-\(\)]/', '', $rawPhone);
        if (str_starts_with($strP, '+')) {
            $phone = $strP;
        } elseif (preg_match('/^\d{10}$/', $strP)) {
            $phone = '+91' . $strP;
        } elseif (preg_match('/^91\d{10}$/', $strP)) {
            $phone = '+' . $strP;
        } else {
            $phone = '+' . $strP;
        }
    }
    $email = strtolower(trim($_POST['email'] ?? ''));
    $address = trim($_POST['address'] ?? '');
    $landmark = trim($_POST['landmark'] ?? '') ?: 'None';
    $state = trim($_POST['state'] ?? '');
    $country = trim($_POST['country'] ?? 'India');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['password_confirmation'] ?? '';

    if (empty($name) || (empty($email) && empty($rawPhone)) || empty($address) || empty($state) || empty($password)) {
        $error = 'Seller name, email or phone number, address, state, and password are required.';
    } elseif ($email !== '' && (str_ends_with($email, '@phone.vendor.local') || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
        $error = 'The email address format is invalid.';
    } elseif ($rawPhone !== '' && !preg_match('/^\+[1-9]\d{7,14}$/', $phone)) {
        $error = 'Phone must be in international format with country code (e.g. +919876543210).';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Password confirmation does not match.';
    } else {
        $dupPhone = false;
        if ($phone !== '') {
            $chk = $pdo->prepare("SELECT id FROM users WHERE phone = :phone LIMIT 1");
            $chk->execute([':phone' => $phone]);
            $dupPhone = (bool) $chk->fetch();
        }
        $dupEmail = false;
        if ($email !== '') {
            $chkEmail = $pdo->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(:email) LIMIT 1");
            $chkEmail->execute([':email' => $email]);
            $dupEmail = (bool) $chkEmail->fetch();
        }
        if ($dupPhone) {
            $error = 'A seller with this phone number already exists.';
        } elseif ($dupEmail) {
            $error = 'A seller with this email address already exists.';
        } else {
            $hashed = hashPassword($password);
            $digits = preg_replace('/\D+/', '', $phone) ?: (string) time();
            $dbEmail = $email !== '' ? $email : "seller_{$digits}@phone.vendor.local";
            $dbPhone = $phone !== '' ? $phone : null;

            $ins = $pdo->prepare("
                INSERT INTO users (name, email, seller_name, phone, address, landmark, state, country, password, role, created_at, updated_at)
                VALUES (:name, :email, :seller_name, :phone, :address, :landmark, :state, :country, :password, 'seller', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ");
            $ins->execute([
                ':name' => $name,
                ':email' => $dbEmail,
                ':seller_name' => $name,
                ':phone' => $dbPhone,
                ':address' => $address,
                ':landmark' => $landmark,
                ':state' => $state,
                ':country' => $country,
                ':password' => $hashed,
            ]);

            $success = 'Seller account created successfully!';
        }
    }
}

// Handle edit seller
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_seller') {
    $sellerId = (int) ($_POST['seller_id'] ?? 0);
    $name = trim($_POST['seller_name'] ?? '');
    $rawPhone = trim($_POST['phone'] ?? '');
    $phone = '';
    if ($rawPhone !== '') {
        $strP = preg_replace('/[\s\-\(\)]/', '', $rawPhone);
        if (str_starts_with($strP, '+')) {
            $phone = $strP;
        } elseif (preg_match('/^\d{10}$/', $strP)) {
            $phone = '+91' . $strP;
        } elseif (preg_match('/^91\d{10}$/', $strP)) {
            $phone = '+' . $strP;
        } else {
            $phone = '+' . $strP;
        }
    }
    $email = strtolower(trim($_POST['email'] ?? ''));
    $address = trim($_POST['address'] ?? '');
    $landmark = trim($_POST['landmark'] ?? '');
    $state = trim($_POST['state'] ?? '');
    $country = trim($_POST['country'] ?? 'India');
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (empty($sellerId) || empty($name) || (empty($rawPhone) && empty($email)) || empty($address) || empty($state)) {
        $error = 'Seller ID, name, email or phone number, address, and state are required.';
    } elseif (!empty($email) && (str_ends_with($email, '@phone.vendor.local') || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
        $error = 'The email address format is invalid.';
    } elseif ($rawPhone !== '' && !preg_match('/^\+[1-9]\d{7,14}$/', $phone)) {
        $error = 'Phone must be in international format with country code (e.g. +919876543210).';
    } else {
        $dupPhone = false;
        if ($phone !== '') {
            $chk = $pdo->prepare("SELECT id FROM users WHERE phone = :phone AND id != :id LIMIT 1");
            $chk->execute([':phone' => $phone, ':id' => $sellerId]);
            $dupPhone = (bool) $chk->fetch();
        }
        if ($dupPhone) {
            $error = 'This phone number is already registered to another account.';
        } else {
            if (!empty($email)) {
                $chkEmail = $pdo->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(:email) AND id != :id LIMIT 1");
                $chkEmail->execute([':email' => $email, ':id' => $sellerId]);
                if ($chkEmail->fetch()) {
                    $error = 'This email address is already registered to another account.';
                }
            }
            $passwordToUpdate = null;
            if (empty($error) && !empty($newPassword)) {
                if (strlen($newPassword) < 8) {
                    $error = 'The new password must be at least 8 characters.';
                } elseif ($newPassword !== $confirmPassword) {
                    $error = 'The new password and confirm password do not match.';
                } else {
                    if (!empty($currentPassword)) {
                        $pStmt = $pdo->prepare("SELECT password FROM users WHERE id = :id LIMIT 1");
                        $pStmt->execute([':id' => $sellerId]);
                        $currHash = $pStmt->fetchColumn();
                        if ($currHash && !verifyPassword($currentPassword, $currHash)) {
                            $error = 'The current password is incorrect.';
                        }
                    }
                    if (empty($error)) {
                        $passwordToUpdate = hashPassword($newPassword);
                    }
                }
            }

            if (empty($error)) {
                $sql = "UPDATE users SET name = :name, seller_name = :seller_name, phone = :phone, address = :address, landmark = :landmark, state = :state, country = :country";
                $params = [
                    ':id' => $sellerId,
                    ':name' => $name,
                    ':seller_name' => $name,
                    ':phone' => $phone !== '' ? $phone : null,
                    ':address' => $address,
                    ':landmark' => $landmark,
                    ':state' => $state,
                    ':country' => $country,
                ];
                if (!empty($email)) {
                    $sql .= ", email = :email";
                    $params[':email'] = $email;
                }
                if ($passwordToUpdate !== null) {
                    $sql .= ", password = :password";
                    $params[':password'] = $passwordToUpdate;
                }
                $sql .= ", updated_at = CURRENT_TIMESTAMP WHERE id = :id";
                $upd = $pdo->prepare($sql);
                $upd->execute($params);

                $success = "Seller #{$sellerId} profile updated successfully!";
            }
        }
    }
}

// Handle Add Category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_category') {
    $catName = trim((string) ($_POST['name'] ?? ''));
    $catDesc = trim((string) ($_POST['description'] ?? ''));
    $catIcon = trim((string) ($_POST['icon'] ?? '🏷️')) ?: '🏷️';
    $catSort = is_numeric($_POST['sort_order'] ?? null) ? (int) $_POST['sort_order'] : ((int) $pdo->query("SELECT COALESCE(MAX(sort_order), 0) FROM categories")->fetchColumn() + 1);
    $catActive = isset($_POST['is_active']) ? 1 : 0;

    if ($catName === '') {
        $error = 'Category name is required.';
    } else {
        $dup = $pdo->prepare("SELECT id FROM categories WHERE LOWER(name) = LOWER(:name) LIMIT 1");
        $dup->execute([':name' => $catName]);
        if ($dup->fetch()) {
            $error = 'A category with this name already exists.';
        } else {
            $slug = ensureUniqueCategorySlugPhp($pdo, toCategorySlugPhp($catName));
            $insCat = $pdo->prepare("INSERT INTO categories (name, slug, description, icon, sort_order, is_active, created_at, updated_at) VALUES (:name, :slug, :desc, :icon, :sort, :active, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
            $insCat->execute([
                ':name' => $catName,
                ':slug' => $slug,
                ':desc' => $catDesc !== '' ? $catDesc : null,
                ':icon' => $catIcon,
                ':sort' => $catSort,
                ':active' => $catActive,
            ]);
            $success = "Category \"{$catName}\" added successfully!";
        }
    }
}

// Handle Edit Category (cascades name change to products)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_category') {
    $catId = (int) ($_POST['category_id'] ?? 0);
    $catName = trim((string) ($_POST['name'] ?? ''));
    $catDesc = trim((string) ($_POST['description'] ?? ''));
    $catIcon = trim((string) ($_POST['icon'] ?? ''));
    $catSort = is_numeric($_POST['sort_order'] ?? null) ? (int) $_POST['sort_order'] : 0;
    $catActive = isset($_POST['is_active']) ? 1 : 0;

    if ($catId <= 0 || $catName === '') {
        $error = 'Category ID and name are required.';
    } else {
        $currStmt = $pdo->prepare("SELECT * FROM categories WHERE id = :id LIMIT 1");
        $currStmt->execute([':id' => $catId]);
        $existingCat = $currStmt->fetch(PDO::FETCH_ASSOC);
        if (!$existingCat) {
            $error = "Category #{$catId} not found.";
        } else {
            $dup = $pdo->prepare("SELECT id FROM categories WHERE LOWER(name) = LOWER(:name) AND id != :id LIMIT 1");
            $dup->execute([':name' => $catName, ':id' => $catId]);
            if ($dup->fetch()) {
                $error = 'Another category with this name already exists.';
            } else {
                $oldName = $existingCat['name'];
                $newSlug = ($oldName !== $catName)
                    ? ensureUniqueCategorySlugPhp($pdo, toCategorySlugPhp($catName), $catId)
                    : $existingCat['slug'];

                $updCat = $pdo->prepare("
                    UPDATE categories
                    SET name = :name, slug = :slug, description = :desc, icon = :icon, sort_order = :sort, is_active = :active, updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id
                ");
                $updCat->execute([
                    ':name' => $catName,
                    ':slug' => $newSlug,
                    ':desc' => $catDesc !== '' ? $catDesc : null,
                    ':icon' => $catIcon !== '' ? $catIcon : null,
                    ':sort' => $catSort,
                    ':active' => $catActive,
                    ':id' => $catId,
                ]);

                if ($oldName !== $catName) {
                    $syncProd = $pdo->prepare("UPDATE products SET category = :new_name, category_id = :cid, updated_at = CURRENT_TIMESTAMP WHERE category_id = :match_cid OR LOWER(category) = LOWER(:old_name)");
                    $syncProd->execute([':new_name' => $catName, ':cid' => $catId, ':match_cid' => $catId, ':old_name' => $oldName]);
                }
                $success = "Category #{$catId} (\"{$catName}\") updated successfully!";
            }
        }
    }
}

// Handle Delete Category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_category') {
    $catId = (int) ($_POST['category_id'] ?? 0);
    $currStmt = $pdo->prepare("SELECT * FROM categories WHERE id = :id LIMIT 1");
    $currStmt->execute([':id' => $catId]);
    $existingCat = $currStmt->fetch(PDO::FETCH_ASSOC);
    if ($existingCat) {
        $pdo->prepare("UPDATE products SET category = 'General', category_id = NULL, updated_at = CURRENT_TIMESTAMP WHERE category_id = :cid OR LOWER(category) = LOWER(:cname)")
            ->execute([':cid' => $catId, ':cname' => $existingCat['name']]);
        $pdo->prepare("DELETE FROM categories WHERE id = :id")->execute([':id' => $catId]);
        $success = "Category \"{$existingCat['name']}\" deleted.";
    } else {
        $error = "Category #{$catId} not found.";
    }
}

// Handle Assign / Edit Product Category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_product_category') {
    $prodId = (int) ($_POST['product_id'] ?? 0);
    $targetCatId = (int) ($_POST['category_id'] ?? 0);
    if ($prodId > 0 && $targetCatId > 0) {
        $cStmt = $pdo->prepare("SELECT * FROM categories WHERE id = :id LIMIT 1");
        $cStmt->execute([':id' => $targetCatId]);
        $catRow = $cStmt->fetch(PDO::FETCH_ASSOC);
        if ($catRow) {
            $pdo->prepare("UPDATE products SET category = :cat, category_id = :cid, updated_at = CURRENT_TIMESTAMP WHERE id = :pid")
                ->execute([':cat' => $catRow['name'], ':cid' => (int) $catRow['id'], ':pid' => $prodId]);
            $success = "Product #{$prodId} category updated to \"{$catRow['name']}\"!";
        } else {
            $error = 'Selected category not found.';
        }
    } else {
        $error = 'Please choose a valid product and category.';
    }
}

// Marketplace Stats
try {
    $sellerCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'seller'")->fetchColumn();
} catch (Throwable $e) { $sellerCount = 0; }

try {
    $productCount = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE is_active = 1")->fetchColumn();
} catch (Throwable $e) { $productCount = 0; }

try {
    $categoryCount = (int) $pdo->query("SELECT COUNT(*) FROM categories WHERE is_active = 1")->fetchColumn();
} catch (Throwable $e) { $categoryCount = 0; }

try {
    $categoriesListStmt = $pdo->query("
        SELECT c.*,
               (SELECT COUNT(*) FROM products p WHERE p.is_active = 1 AND (p.category_id = c.id OR LOWER(p.category) = LOWER(c.name))) AS products_count
        FROM categories c
        ORDER BY c.sort_order ASC, c.id ASC
    ");
    $categoriesList = $categoriesListStmt ? $categoriesListStmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable $e) { $categoriesList = []; }

try {
    $adminProductsStmt = $pdo->query("
        SELECT p.id, p.seller_id, p.name, p.sku, p.category, p.category_id, p.price, p.stock, p.is_active,
               COALESCE(u.seller_name, u.name, 'Verified Seller') AS seller_name
        FROM products p
        LEFT JOIN users u ON p.seller_id = u.id
        ORDER BY p.id DESC
        LIMIT 80
    ");
    $adminProducts = $adminProductsStmt ? $adminProductsStmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable $e) { $adminProducts = []; }

try {
    $orderCount = (int) $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
} catch (Throwable $e) { $orderCount = 0; }

// PDF Document Verification Stats
try {
    $gstCountsStmt = $pdo->query("
        SELECT
            COUNT(*) as total_gst,
            SUM(CASE WHEN status = 'Pending' OR status = 'Resubmitted' THEN 1 ELSE 0 END) as pending_gst,
            SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) as approved_gst,
            SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) as rejected_gst
        FROM seller_gst_details
    ");
    $gstCounts = $gstCountsStmt ? $gstCountsStmt->fetch() : [];
    $pendingGstCount = (int) ($gstCounts['pending_gst'] ?? 0);
    $approvedGstCount = (int) ($gstCounts['approved_gst'] ?? 0);
    $rejectedGstCount = (int) ($gstCounts['rejected_gst'] ?? 0);
    $totalGstCount = (int) ($gstCounts['total_gst'] ?? 0);
} catch (Throwable $e) {
    $pendingGstCount = 0; $approvedGstCount = 0; $rejectedGstCount = 0; $totalGstCount = 0;
}

// Fetch all sellers with uploaded PDF documents
$gstSellersStmt = $pdo->query("
    SELECT g.*, u.seller_name, u.name as user_name, u.phone, u.email, u.address as user_address, u.state as user_state,
           (SELECT COUNT(*) FROM seller_gst_documents d WHERE d.seller_id = g.seller_id) as doc_count,
           (SELECT COUNT(*) FROM seller_gst_documents d WHERE d.seller_id = g.seller_id AND (LOWER(d.mime_type) = 'application/pdf' OR d.original_name LIKE '%.pdf')) as pdf_count,
           (SELECT d.id FROM seller_gst_documents d WHERE d.seller_id = g.seller_id ORDER BY d.is_primary DESC, d.id DESC LIMIT 1) as latest_doc_id,
           (SELECT d.original_name FROM seller_gst_documents d WHERE d.seller_id = g.seller_id ORDER BY d.is_primary DESC, d.id DESC LIMIT 1) as latest_doc_name,
           (SELECT d.file_size FROM seller_gst_documents d WHERE d.seller_id = g.seller_id ORDER BY d.is_primary DESC, d.id DESC LIMIT 1) as latest_doc_size
    FROM seller_gst_details g
    JOIN users u ON g.seller_id = u.id
    ORDER BY (CASE WHEN g.status = 'Pending' OR g.status = 'Resubmitted' THEN 0 ELSE 1 END), g.updated_at DESC
    LIMIT 100
");
$gstSellers = $gstSellersStmt ? $gstSellersStmt->fetchAll() : [];

// Sellers list with product counts, document status, account status & deletion request link
$sellersStmt = $pdo->query("
    SELECT u.id, u.name, u.seller_name, u.email, u.phone, u.address, u.landmark, u.state, u.country, u.created_at,
           COALESCE(u.account_status, 'ACTIVE') AS account_status,
           u.deletion_requested_at, u.deleted_at,
           (SELECT COUNT(*) FROM products p WHERE p.seller_id = u.id AND p.is_active = 1) AS products_count,
           (SELECT g.status FROM seller_gst_details g WHERE g.seller_id = u.id LIMIT 1) AS doc_status,
           (SELECT d.id FROM seller_gst_documents d WHERE d.seller_id = u.id ORDER BY d.is_primary DESC, d.id DESC LIMIT 1) AS latest_doc_id,
           (SELECT sdr.id FROM seller_deletion_requests sdr WHERE sdr.seller_id = u.id AND sdr.status NOT IN ('DELETED', 'DELETION_REJECTED') ORDER BY sdr.id DESC LIMIT 1) AS active_deletion_request_id
    FROM users u
    WHERE u.role = 'seller'
    ORDER BY u.id DESC
    LIMIT 100
");
$sellers = $sellersStmt ? $sellersStmt->fetchAll() : [];

$deletionData = deletionListRequestsPhp($pdo, []);
$delRequests = $deletionData['requests'] ?? [];
$delKpis = $deletionData['kpis'] ?? ['total' => 0, 'pending' => 0, 'processing' => 0, 'failed' => 0, 'on_hold' => 0, 'approved' => 0, 'deleted' => 0, 'rejected' => 0];
$focusDeletionTab = str_contains($_SERVER['REQUEST_URI'] ?? '', 'seller-deletions') || (($_GET['tab'] ?? '') === 'seller-deletions');

function formatBytesPhp($bytes): string {
    if (!$bytes) return '0 KB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    return round($bytes / 1024, 1) . ' KB';
}

function delBadgeClassPhp(?string $statusOrColor): string {
    $s = strtoupper(trim((string) $statusOrColor));
    if ($s === 'GREEN' || in_array($s, ['SUCCESS', 'REFUND_SUCCESS', 'APPROVED_FOR_DELETION', 'DELETED', 'SETTLED', 'ACTIVE', 'RESOLVED'], true)) return 'del-badge del-green';
    if ($s === 'BLUE' || str_contains($s, 'PROCESSING') || str_contains($s, 'VERIFICATION') || str_contains($s, 'REVIEW') || $s === 'LOCKED') return 'del-badge del-blue';
    if ($s === 'RED' || str_contains($s, 'FAILED') || str_contains($s, 'REJECTED')) return 'del-badge del-red';
    if ($s === 'ORANGE' || str_contains($s, 'HOLD') || $s === 'OPEN' || str_contains($s, 'REQUIRES_REVIEW')) return 'del-badge del-orange';
    return 'del-badge del-yellow';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Vendor Admin Dashboard</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f4f5fb; color: #20243a; }
        .top { background: #25204a; color: white; padding: 18px 5%; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
        .brand { font-size: 21px; font-weight: 800; }
        .top-nav { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        .nav-pill { color: #e2e8f0; text-decoration: none; font-size: 13px; font-weight: 700; padding: 7px 12px; border-radius: 8px; background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.14); transition: all .15s; }
        .nav-pill:hover, .nav-pill.active { background: #6366f1; color: #fff; border-color: #818cf8; }
        .wrap { max-width: 1180px; margin: 28px auto; padding: 0 18px; }
        .card { background: #fff; border-radius: 14px; padding: 24px; box-shadow: 0 8px 30px rgba(37,32,74,0.06); margin-bottom: 24px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; }
        .stat { font-size: 32px; font-weight: 800; color: #5c49d8; margin-top: 6px; }
        h1, h2 { margin-top: 0; color: #1e1b4b; }
        p.muted { color: #64748b; font-size: 14px; margin-top: -6px; }
        label { display: block; font-size: 13px; font-weight: 600; margin: 12px 0 6px; color: #334155; }
        input, textarea, select { width: 100%; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; outline: none; font-family: inherit; }
        input:focus, textarea:focus, select:focus { border-color: #6366f1; }
        button, .btn { background: #5b4bd8; color: #fff; border: 0; border-radius: 8px; padding: 12px 20px; font-weight: 700; cursor: pointer; text-decoration: none; display: inline-block; font-size: 14px; }
        button:hover, .btn:hover { background: #4a3cb8; }
        button:disabled { opacity: 0.45; cursor: not-allowed; }
        table { width: 100%; border-collapse: collapse; margin-top: 14px; }
        th, td { text-align: left; padding: 12px; border-bottom: 1px solid #f1f5f9; font-size: 14px; vertical-align: middle; }
        th { color: #64748b; font-weight: 600; }
        .error { background: #fef2f2; color: #b91c1c; padding: 12px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
        .success { background: #ecfdf5; color: #047857; padding: 12px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
        .logout-btn { background: rgba(255,255,255,0.15); color: #fff; border: 1px solid rgba(255,255,255,0.3); padding: 8px 14px; border-radius: 6px; text-decoration: none; font-size: 13px; }
        .logout-btn:hover { background: rgba(255,255,255,0.25); }
        .btn-sm { background: #6366f1; color: white; border: none; border-radius: 6px; padding: 6px 12px; font-size: 12px; font-weight: 700; cursor: pointer; }
        .btn-sm:hover { background: #4f46e5; }
        .btn-hold-sm { background: #ea580c; color: white; border: none; border-radius: 6px; padding: 5px 11px; font-weight: 700; cursor: pointer; font-size: 12px; }
        .btn-hold-sm:hover { background: #c2410c; }
        .btn-del-req { background: #fef2f2; color: #b91c1c; border: 1px solid #fca5a5; border-radius: 6px; padding: 5px 10px; font-size: 12px; font-weight: 700; cursor: pointer; }
        .btn-del-req:hover { background: #fee2e2; }
        .badge-prod { background: #e0e7ff; color: #4338ca; font-weight: 700; font-size: 12px; padding: 3px 8px; border-radius: 9999px; }
        .del-badge { font-weight: 700; font-size: 11px; padding: 4px 10px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 4px; text-transform: uppercase; letter-spacing: 0.3px; }
        .del-yellow { background: #fef3c7; color: #b45309; border: 1px solid #fcd34d; }
        .del-blue { background: #dbeafe; color: #1d4ed8; border: 1px solid #93c5fd; }
        .del-green { background: #dcfce7; color: #15803d; border: 1px solid #86efac; }
        .del-red { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }
        .del-orange { background: #ffedd5; color: #c2410c; border: 1px solid #fdba74; }
        /* Modal Styles */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(15, 23, 42, 0.7); backdrop-filter: blur(4px); z-index: 1000; align-items: center; justify-content: center; }
        .modal-overlay.active { display: flex; }
        .modal-box { background: #fff; width: 92%; max-width: 650px; max-height: 90vh; overflow-y: auto; border-radius: 16px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); border: 1px solid #e2e8f0; }
        .modal-box.wide { max-width: 1040px; }
        .modal-header { padding: 20px 24px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; background: #fff; z-index: 5; }
        .modal-header h3 { margin: 0; font-size: 18px; color: #1e1b4b; }
        .modal-close { background: transparent; border: none; font-size: 20px; font-weight: 700; color: #94a3b8; cursor: pointer; padding: 4px; }
        .modal-close:hover { color: #0f172a; }
        .modal-body { padding: 24px; }
        .metric-mini { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 10px 12px; }
        .metric-mini .k { font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; }
        .metric-mini .v { font-size: 17px; font-weight: 800; color: #1e1b4b; margin-top: 3px; }
        .ws-section { border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px; margin-bottom: 16px; background: #fff; }
        .ws-section h4 { margin: 0 0 10px; color: #1e1b4b; font-size: 15px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; }
        .status-legend { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; font-size: 12px; margin-top: 6px; }
        .info-pill { background: #f8fafc; border: 1px solid #e2e8f0; padding: 12px; border-radius: 8px; margin-bottom: 18px; display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 12px; }
        .info-pill-item { font-size: 12px; color: #64748b; }
        .info-pill-item strong { display: block; font-size: 14px; color: #1e293b; margin-top: 2px; }
        /* GST Badges & Components */
        .badge-pending { background: #fef3c7; color: #b45309; font-weight: 700; font-size: 12px; padding: 4px 10px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 4px; }
        .badge-approved { background: #dcfce7; color: #15803d; font-weight: 700; font-size: 12px; padding: 4px 10px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 4px; }
        .badge-rejected { background: #fee2e2; color: #b91c1c; font-weight: 700; font-size: 12px; padding: 4px 10px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 4px; }
        .badge-pdf { background: #fee2e2; color: #dc2626; font-weight: 800; font-size: 11px; padding: 2px 6px; border-radius: 4px; border: 1px solid #fca5a5; }
        .badge-img { background: #e0f2fe; color: #0284c7; font-weight: 800; font-size: 11px; padding: 2px 6px; border-radius: 4px; border: 1px solid #bae6fd; }
        .gstin-pill { font-family: monospace; font-size: 13px; font-weight: 800; background: #eef2ff; color: #3730a3; padding: 3px 8px; border-radius: 6px; border: 1px solid #c7d2fe; letter-spacing: 0.5px; }
        .tabs-bar { display: flex; gap: 8px; margin: 16px 0; border-bottom: 2px solid #e2e8f0; padding-bottom: 8px; flex-wrap: wrap; }
        .tab-btn { background: #f1f5f9; color: #475569; border: none; padding: 8px 16px; border-radius: 8px; font-weight: 700; font-size: 13px; cursor: pointer; transition: all .2s; }
        .tab-btn.active { background: #4f46e5; color: #fff; }
        .doc-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px; margin-bottom: 12px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        .doc-title { font-weight: 700; font-size: 14px; color: #1e293b; display: flex; align-items: center; gap: 8px; }
        .doc-meta { font-size: 12px; color: #64748b; margin-top: 4px; }
        .btn-view { background: #0284c7; color: white; padding: 6px 12px; border-radius: 6px; text-decoration: none; font-size: 12px; font-weight: 700; display: inline-block; }
        .btn-view:hover { background: #0369a1; }
        .btn-dl { background: #475569; color: white; padding: 6px 12px; border-radius: 6px; text-decoration: none; font-size: 12px; font-weight: 700; display: inline-block; }
        .btn-dl:hover { background: #334155; }
        .btn-approve { background: #16a34a; color: white; border: none; border-radius: 8px; padding: 10px 18px; font-weight: 700; cursor: pointer; font-size: 14px; }
        .btn-approve:hover { background: #15803d; }
        .btn-approve-sm { background: #16a34a; color: white; border: none; border-radius: 6px; padding: 5px 11px; font-weight: 700; cursor: pointer; font-size: 12px; transition: background .15s; }
        .btn-approve-sm:hover { background: #15803d; }
        .btn-reject { background: #dc2626; color: white; border: none; border-radius: 8px; padding: 10px 18px; font-weight: 700; cursor: pointer; font-size: 14px; }
        .btn-reject:hover { background: #b91c1c; }
        .btn-reject-sm { background: #dc2626; color: white; border: none; border-radius: 6px; padding: 5px 11px; font-weight: 700; cursor: pointer; font-size: 12px; transition: background .15s; }
        .btn-reject-sm:hover { background: #b91c1c; }
        .search-box { display: flex; align-items: center; gap: 8px; width: 100%; max-width: 320px; }
        .search-box input { width: 100%; padding: 8px 12px; border-radius: 8px; font-size: 13px; border: 1px solid #cbd5e1; outline: none; }
        .search-box input:focus { border-color: #6366f1; }
        .log-row { padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; display: flex; justify-content: space-between; gap: 12px; }
        .log-time { color: #64748b; font-size: 12px; min-width: 130px; }
    </style>
</head>
<body>
    <header class="top">
        <div class="brand">Vendor <span style="font-weight:400; opacity:.85;">Admin</span></div>
        <nav class="top-nav">
            <a href="/admin#sellersSection" class="nav-pill <?= !$focusDeletionTab ? 'active' : '' ?>">Seller Management</a>
            <a href="/admin/seller-deletions" class="nav-pill <?= $focusDeletionTab ? 'active' : '' ?>">Seller Deletion Requests (<?= (int) ($delKpis['total'] ?? 0) ?>)</a>
            <a href="/admin#gstSection" class="nav-pill">PDF Verifications</a>
            <a href="/admin#categoriesSection" class="nav-pill">Categories</a>
        </nav>
        <div>
            <span style="margin-right:15px; font-size:14px; opacity:.9;">Welcome, <?= htmlspecialchars($_SESSION['admin_name'] ?? 'Admin') ?></span>
            <a href="/admin/logout" class="logout-btn">Sign out</a>
        </div>
    </header>
    <main class="wrap">
        <div style="display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:12px; margin-bottom:14px;">
            <div>
                <h1 style="margin-bottom:6px;"><?= $focusDeletionTab ? 'Seller Management → Seller Deletion Requests' : 'Dashboard' ?></h1>
                <p class="muted" style="margin:0;">Manage marketplace sellers, Seller Account Deletion & Refund workflows, categories, and PDF verifications.</p>
            </div>
            <div class="status-legend">
                <span class="del-badge del-yellow">PENDING</span>
                <span class="del-badge del-blue">PROCESSING</span>
                <span class="del-badge del-green">SUCCESS</span>
                <span class="del-badge del-red">FAILED</span>
                <span class="del-badge del-orange">ON HOLD</span>
            </div>
        </div>

        <?php if ($success): ?><div class="success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); margin-bottom: 24px;">
            <section class="card">
                <div style="color:#64748b; font-size:12px; font-weight:700; text-transform:uppercase;">Registered Sellers</div>
                <div class="stat"><?= $sellerCount ?></div>
            </section>
            <section class="card">
                <div style="color:#64748b; font-size:12px; font-weight:700; text-transform:uppercase;">Active Products</div>
                <div class="stat" style="color:#2563eb;"><?= $productCount ?></div>
            </section>
            <section class="card">
                <div style="color:#64748b; font-size:12px; font-weight:700; text-transform:uppercase;">Product Categories</div>
                <div class="stat" style="color:#7c3aed;"><?= $categoryCount ?></div>
            </section>
            <section class="card">
                <div style="color:#64748b; font-size:12px; font-weight:700; text-transform:uppercase;">Marketplace Orders</div>
                <div class="stat" style="color:#0891b2;"><?= $orderCount ?></div>
            </section>
            <section class="card" style="border-left: 4px solid #f59e0b; background: <?= $pendingGstCount > 0 ? '#fffbeb' : '#fff' ?>;">
                <div style="color:#b45309; font-size:12px; font-weight:700; text-transform:uppercase; display:flex; align-items:center; gap:6px;">
                    Pending PDF Reviews
                    <?php if ($pendingGstCount > 0): ?>
                        <span style="background:#f59e0b; color:white; font-size:10px; padding:2px 6px; border-radius:999px;">Action Required</span>
                    <?php endif; ?>
                </div>
                <div class="stat" style="color:#b45309;"><?= $pendingGstCount ?></div>
            </section>
            <section class="card" style="border-left: 4px solid #10b981;">
                <div style="color:#15803d; font-size:12px; font-weight:700; text-transform:uppercase;">Approved Sellers</div>
                <div class="stat" style="color:#10b981;"><?= $approvedGstCount ?></div>
            </section>
            <section class="card" style="border-left: 4px solid #ef4444;">
                <div style="color:#b91c1c; font-size:12px; font-weight:700; text-transform:uppercase;">Rejected Requests</div>
                <div class="stat" style="color:#ef4444;"><?= $rejectedGstCount ?></div>
            </section>
        </div>

        <!-- Section 1: Seller PDF Document Verification Hub (Primary Function) -->
        <section class="card" id="gstSection">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:14px; margin-bottom: 8px;">
                <div>
                    <h2>Seller PDF Document Verification</h2>
                    <p class="muted">Inspect seller-uploaded PDF certificates with 1-click preview, approve for marketplace compliance, or reject with feedback.</p>
                </div>
                <div class="search-box">
                    <input type="text" id="gstSearchInput" onkeyup="filterGstSearch(this.value)" placeholder="Search seller, phone, or email...">
                </div>
            </div>

            <div class="tabs-bar">
                <button type="button" class="tab-btn active" onclick="filterGstRows('all', this)">All Documents (<?= $totalGstCount ?>)</button>
                <button type="button" class="tab-btn" onclick="filterGstRows('pending', this)">
                    Pending Review (<?= $pendingGstCount ?>)
                    <?php if ($pendingGstCount > 0): ?><span style="background:#f59e0b; color:#fff; border-radius:999px; padding:1px 6px; font-size:11px; margin-left:4px;">●</span><?php endif; ?>
                </button>
                <button type="button" class="tab-btn" onclick="filterGstRows('approved', this)">Approved (<?= $approvedGstCount ?>)</button>
                <button type="button" class="tab-btn" onclick="filterGstRows('rejected', this)">Rejected (<?= $rejectedGstCount ?>)</button>
            </div>

            <table>
                <thead>
                    <tr>
                        <th style="width:24%;">Seller</th>
                        <th style="width:28%;">Uploaded PDF Document</th>
                        <th style="width:16%;">Verification Status</th>
                        <th style="width:14%;">Submitted</th>
                        <th style="width:18%;">Admin Actions</th>
                    </tr>
                </thead>
                <tbody id="gstTableBody">
                    <?php if (empty($gstSellers)): ?>
                        <tr><td colspan="5" style="color:#94a3b8; text-align:center; padding:32px;">No sellers have uploaded verification documents yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($gstSellers as $g): ?>
                            <?php
                                $st = strtolower($g['status']);
                                if ($st === 'resubmitted') $st = 'pending';
                                $badgeClass = ($st === 'approved') ? 'badge-approved' : (($st === 'rejected') ? 'badge-rejected' : 'badge-pending');
                                $sellerLabel = $g['seller_name'] ?? $g['user_name'] ?? 'Seller #' . $g['seller_id'];
                                $displayGstEmail = getPublicSellerEmailPhp($g['email'] ?? null);
                                $searchString = strtolower($sellerLabel . ' ' . ($g['phone'] ?? '') . ' ' . ($displayGstEmail ?? ''));
                            ?>
                            <tr class="gst-row" data-status="<?= $st ?>" data-search="<?= htmlspecialchars($searchString) ?>">
                                <td>
                                    <strong style="font-size:15px; color:#1e1b4b;"><?= htmlspecialchars($sellerLabel) ?></strong>
                                    <div style="font-size:12px; color:#64748b; margin-top:2px;">
                                        <span class="badge-prod" style="font-size:10px; padding:1px 6px;">ID #<?= (int)$g['seller_id'] ?></span>
                                        <?= htmlspecialchars($g['phone'] ?? '') ?>
                                    </div>
                                    <?php if (!empty($displayGstEmail)): ?>
                                        <div style="font-size:12px; color:#94a3b8;"><?= htmlspecialchars($displayGstEmail) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($g['latest_doc_id'])): ?>
                                        <div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
                                            <span class="badge-pdf">PDF</span>
                                            <strong style="color:#334155; font-size:13px;"><?= htmlspecialchars($g['latest_doc_name'] ?: 'Certificate.pdf') ?></strong>
                                            <span style="color:#94a3b8; font-size:11px;">(<?= formatBytesPhp($g['latest_doc_size']) ?>)</span>
                                        </div>
                                        <div style="margin-top:6px; display:flex; gap:6px; align-items:center;">
                                            <a href="/admin/dashboard.php?action=view_gst_doc&id=<?= $g['latest_doc_id'] ?>&mode=preview" target="_blank" class="btn-view" style="font-size:11px; padding:4px 10px;">
                                                📄 1-Click Preview PDF
                                            </a>
                                            <a href="/admin/dashboard.php?action=view_gst_doc&id=<?= $g['latest_doc_id'] ?>&mode=download" class="btn-dl" style="font-size:11px; padding:4px 8px;" title="Download PDF">
                                                ⬇
                                            </a>
                                            <?php if ((int)$g['doc_count'] > 1): ?>
                                                <span style="font-size:11px; color:#64748b; font-weight:600;">+<?= (int)$g['doc_count'] - 1 ?> more</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <span style="color:#94a3b8; font-style:italic; font-size:13px;">No document attached</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="<?= $badgeClass ?>">
                                        <?= ($st === 'pending') ? '⏳ ' : (($st === 'approved') ? '✓ ' : '✕ ') ?>
                                        <?= htmlspecialchars($g['status']) ?>
                                    </span>
                                    <?php if ($st === 'rejected' && !empty($g['rejection_reason'])): ?>
                                        <div style="font-size:11px; color:#b91c1c; margin-top:4px; max-width:220px; line-height:1.3;" title="<?= htmlspecialchars($g['rejection_reason']) ?>">
                                            <strong>Reason:</strong> <?= htmlspecialchars(strlen($g['rejection_reason']) > 55 ? substr($g['rejection_reason'], 0, 52) . '...' : $g['rejection_reason']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="font-size:13px; color:#334155;"><?= htmlspecialchars(substr($g['submitted_at'] ?? $g['updated_at'] ?? '', 0, 10)) ?></div>
                                    <div style="font-size:11px; color:#94a3b8;"><?= htmlspecialchars(substr($g['submitted_at'] ?? $g['updated_at'] ?? '', 11, 5)) ?></div>
                                </td>
                                <td>
                                    <div style="display:flex; gap:6px; flex-wrap:wrap; align-items:center;">
                                        <?php if ($st !== 'approved'): ?>
                                            <form method="POST" action="/admin" style="display:inline;" onsubmit="return confirm('Approve verification for <?= htmlspecialchars(addslashes($sellerLabel), ENT_QUOTES) ?>?');">
                                                <input type="hidden" name="action" value="approve_gst">
                                                <input type="hidden" name="seller_id" value="<?= (int)$g['seller_id'] ?>">
                                                <input type="hidden" name="notes" value="Approved by administrator">
                                                <button type="submit" class="btn-approve-sm" title="Instant Approve">✓ Approve</button>
                                            </form>
                                        <?php endif; ?>

                                        <?php if ($st !== 'rejected'): ?>
                                            <button type="button" class="btn-reject-sm" onclick="openGstModalWithReject(<?= (int)$g['seller_id'] ?>, '<?= htmlspecialchars(addslashes($sellerLabel), ENT_QUOTES) ?>')" title="Reject with Reason">✕ Reject</button>
                                        <?php endif; ?>

                                        <button type="button" class="btn-sm" style="background:#4f46e5; padding:5px 9px;" onclick="openGstModal(<?= (int)$g['seller_id'] ?>, '<?= htmlspecialchars(addslashes($sellerLabel), ENT_QUOTES) ?>')">Details & Logs</button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>

        <!-- Section 2: Seller Accounts Overview with Account Status & Deletion Controls -->
        <section class="card" id="sellersSection" style="margin-top:24px;">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:12px;">
                <div>
                    <h2>Seller Accounts</h2>
                    <p class="muted">All registered sellers on Venter App with inventory counts, verification status, and deletion controls.</p>
                </div>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Seller Name</th>
                        <th>Contact</th>
                        <th>Location</th>
                        <th>Products</th>
                        <th>Document Status</th>
                        <th>Account Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($sellers)): ?>
                        <tr><td colspan="9" style="color:#94a3b8; text-align:center; padding:24px;">No sellers registered yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($sellers as $s): ?>
                            <?php
                                $dSt = strtolower($s['doc_status'] ?? '');
                                if ($dSt === 'resubmitted') $dSt = 'pending';
                                $docBadgeClass = ($dSt === 'approved') ? 'badge-approved' : (($dSt === 'rejected') ? 'badge-rejected' : (($dSt === 'pending') ? 'badge-pending' : ''));
                                $accStatus = strtoupper((string) ($s['account_status'] ?? 'ACTIVE'));
                                $accBadgeCls = $accStatus === 'ACTIVE' ? 'del-badge del-green' : ($accStatus === 'DELETION_PENDING' ? 'del-badge del-yellow' : ($accStatus === 'LOCKED' ? 'del-badge del-orange' : 'del-badge del-red'));
                            ?>
                            <tr>
                                <td>#<?= $s['id'] ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($s['seller_name'] ?? $s['name']) ?></strong>
                                </td>
                                <td>
                                    <div><?= htmlspecialchars($s['phone'] ?? '—') ?></div>
                                    <div style="font-size:11px; color:#94a3b8;"><?= htmlspecialchars(getPublicSellerEmailPhp($s['email'] ?? null) ?? '') ?></div>
                                </td>
                                <td><?= htmlspecialchars($s['state'] ?? '—') ?></td>
                                <td><span class="badge-prod"><?= (int)($s['products_count'] ?? 0) ?> products</span></td>
                                <td>
                                    <?php if (!empty($docBadgeClass)): ?>
                                        <span class="<?= $docBadgeClass ?>"><?= htmlspecialchars($s['doc_status']) ?></span>
                                        <?php if (!empty($s['latest_doc_id'])): ?>
                                            <a href="/admin/dashboard.php?action=view_gst_doc&id=<?= $s['latest_doc_id'] ?>&mode=preview" target="_blank" style="margin-left:4px; font-size:11px; font-weight:700; color:#0284c7; text-decoration:none;">📄 View</a>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color:#94a3b8; font-size:12px;">Not Uploaded</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="<?= $accBadgeCls ?>"><?= htmlspecialchars($accStatus) ?></span></td>
                                <td><?= htmlspecialchars(substr($s['created_at'] ?? '', 0, 10)) ?></td>
                                <td>
                                    <div style="display:flex; gap:6px; flex-wrap:wrap; align-items:center;">
                                        <button type="button" class="btn-sm" onclick='openSellerModal(<?= htmlspecialchars(json_encode($s, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, "UTF-8") ?>)'>View / Edit</button>
                                        <?php if (!empty($s['active_deletion_request_id'])): ?>
                                            <button type="button" class="btn-hold-sm" onclick="openDeletionWorkspaceModal(<?= (int) $s['active_deletion_request_id'] ?>)">Manage Deletion #<?= (int) $s['active_deletion_request_id'] ?></button>
                                        <?php elseif ($accStatus !== 'DELETED'): ?>
                                            <button type="button" class="btn-del-req" onclick="openDeletionPreflightModal(<?= (int) $s['id'] ?>)">Request Seller Deletion</button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>

        <!-- Section 2B: Seller Management → Seller Deletion Requests -->
        <section class="card" id="deletionRequestsSection" style="margin-top:24px; border-top: 4px solid #dc2626;">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:10px;">
                <div>
                    <h2>Seller Management → Seller Deletion Requests</h2>
                    <p class="muted">Track order verification (Rules A–E), automated Razorpay refunds, settlement checks, multi-stage Admin approvals, and audit logs.</p>
                </div>
                <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                    <div class="search-box" style="max-width:260px;">
                        <input type="text" id="deletionSearchInput" onkeyup="filterDeletionTableSearch(this.value)" placeholder="Search seller name, email, ID...">
                    </div>
                    <button type="button" class="btn-sm" onclick="reloadDeletionRequestsList()">↻ Refresh</button>
                </div>
            </div>

            <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(125px, 1fr)); gap:10px; margin-bottom:14px;">
                <div class="metric-mini"><div class="k">Total Requests</div><div class="v" id="delKpiTotal"><?= (int) ($delKpis['total'] ?? 0) ?></div></div>
                <div class="metric-mini" style="background:#fffbeb;"><div class="k" style="color:#b45309;">Pending (Yellow)</div><div class="v" style="color:#b45309;" id="delKpiPending"><?= (int) ($delKpis['pending'] ?? 0) ?></div></div>
                <div class="metric-mini" style="background:#eff6ff;"><div class="k" style="color:#1d4ed8;">Processing (Blue)</div><div class="v" style="color:#1d4ed8;" id="delKpiProcessing"><?= (int) ($delKpis['processing'] ?? 0) ?></div></div>
                <div class="metric-mini" style="background:#fef2f2;"><div class="k" style="color:#b91c1c;">Failed (Red)</div><div class="v" style="color:#b91c1c;" id="delKpiFailed"><?= (int) ($delKpis['failed'] ?? 0) ?></div></div>
                <div class="metric-mini" style="background:#fff7ed;"><div class="k" style="color:#c2410c;">On Hold (Orange)</div><div class="v" style="color:#c2410c;" id="delKpiHold"><?= (int) ($delKpis['on_hold'] ?? 0) ?></div></div>
                <div class="metric-mini" style="background:#f0fdf4;"><div class="k" style="color:#15803d;">Approved / Deleted</div><div class="v" style="color:#15803d;" id="delKpiApprovedDeleted"><?= (int) ($delKpis['approved'] ?? 0) + (int) ($delKpis['deleted'] ?? 0) ?></div></div>
            </div>

            <div class="tabs-bar" id="delFilterTabs">
                <button type="button" class="tab-btn active" onclick="filterDeletionStatus('all', this)">All</button>
                <button type="button" class="tab-btn" onclick="filterDeletionStatus('DELETION_REQUESTED', this)">Requested</button>
                <button type="button" class="tab-btn" onclick="filterDeletionStatus('REFUND_FAILED', this)">Refund Failed</button>
                <button type="button" class="tab-btn" onclick="filterDeletionStatus('SETTLEMENT_PENDING', this)">Settlement Pending</button>
                <button type="button" class="tab-btn" onclick="filterDeletionStatus('DELETION_ON_HOLD', this)">On Hold</button>
                <button type="button" class="tab-btn" onclick="filterDeletionStatus('APPROVED_FOR_DELETION', this)">Approved</button>
                <button type="button" class="tab-btn" onclick="filterDeletionStatus('DELETED', this)">Deleted</button>
                <button type="button" class="tab-btn" onclick="filterDeletionStatus('DELETION_REJECTED', this)">Rejected</button>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Req ID</th>
                        <th>Seller &amp; ID</th>
                        <th>Requested Date</th>
                        <th>Deletion Stage</th>
                        <th>Status</th>
                        <th>Metrics / Balance Snapshot</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="deletionRequestsTbody">
                    <?php if (empty($delRequests)): ?>
                        <tr><td colspan="7" style="color:#94a3b8; text-align:center; padding:28px;">No seller deletion requests submitted yet. Use "Request Seller Deletion" in Seller Accounts above.</td></tr>
                    <?php else: ?>
                        <?php foreach ($delRequests as $dr): ?>
                            <?php
                                $snapMetrics = is_array($dr['metrics'] ?? null) ? $dr['metrics'] : [];
                                $finSum = is_array($dr['financial_summary'] ?? null) ? $dr['financial_summary'] : [];
                                $badgeCls = delBadgeClassPhp($dr['badge_color'] ?? $dr['status'] ?? 'PENDING');
                                $searchTxt = strtolower(($dr['seller_name'] ?? '') . ' ' . ($dr['seller_email'] ?? '') . ' ' . ($dr['seller_phone'] ?? '') . ' ' . $dr['seller_id'] . ' ' . $dr['status'] . ' ' . $dr['stage']);
                            ?>
                            <tr class="del-req-row" data-status="<?= htmlspecialchars($dr['status']) ?>" data-search="<?= htmlspecialchars($searchTxt) ?>">
                                <td><strong>#<?= (int) $dr['id'] ?></strong></td>
                                <td>
                                    <strong style="color:#1e1b4b;"><?= htmlspecialchars($dr['seller_name']) ?></strong>
                                    <div style="font-size:12px; color:#64748b;">Seller ID #<?= (int) $dr['seller_id'] ?><?= !empty($dr['seller_phone']) ? ' &bull; ' . htmlspecialchars($dr['seller_phone']) : '' ?></div>
                                    <?php if (!empty($dr['seller_email'])): ?>
                                        <div style="font-size:11px; color:#94a3b8;"><?= htmlspecialchars($dr['seller_email']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="font-size:13px;"><?= htmlspecialchars(substr((string) ($dr['created_at'] ?? ''), 0, 10)) ?></div>
                                    <div style="font-size:11px; color:#94a3b8;"><?= htmlspecialchars(substr((string) ($dr['created_at'] ?? ''), 11, 8)) ?></div>
                                </td>
                                <td><code style="background:#f1f5f9; padding:3px 6px; border-radius:4px; font-size:12px;"><?= htmlspecialchars($dr['stage']) ?></code></td>
                                <td><span class="<?= $badgeCls ?>"><?= htmlspecialchars($dr['status']) ?></span></td>
                                <td style="font-size:12px; color:#475569;">
                                    Orders: <strong><?= (int) ($snapMetrics['total_orders'] ?? 0) ?></strong> &bull;
                                    Balance: <strong>₹<?= number_format((float) ($finSum['available_seller_balance'] ?? $snapMetrics['seller_wallet_balance'] ?? 0), 2) ?></strong>
                                </td>
                                <td>
                                    <button type="button" class="btn-sm" onclick="openDeletionWorkspaceModal(<?= (int) $dr['id'] ?>)">Manage Deletion</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>

        <!-- Section 3: Product Categories Management (5 Starter Categories + Editable) -->
        <section class="card" id="categoriesSection" style="margin-top:24px;">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:12px;">
                <div>
                    <h2>Product Categories (Editable)</h2>
                    <p class="muted">Manage product categories (5 default starter categories pre-seeded). Renaming a category automatically syncs linked products.</p>
                </div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Category Name</th>
                        <th>Slug</th>
                        <th>Description</th>
                        <th>Products</th>
                        <th>Order</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($categoriesList)): ?>
                        <tr><td colspan="8" style="color:#94a3b8; text-align:center; padding:24px;">No categories found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($categoriesList as $cat): ?>
                            <tr>
                                <td>#<?= (int)$cat['id'] ?></td>
                                <td>
                                    <span style="font-size:16px; margin-right:4px;"><?= htmlspecialchars($cat['icon'] ?? '🏷️') ?></span>
                                    <strong><?= htmlspecialchars($cat['name']) ?></strong>
                                </td>
                                <td><code style="background:#f1f5f9; padding:2px 6px; border-radius:4px; font-size:12px;"><?= htmlspecialchars($cat['slug']) ?></code></td>
                                <td style="color:#64748b; font-size:13px; max-width:260px;"><?= htmlspecialchars($cat['description'] ?? '—') ?></td>
                                <td><span class="badge-prod"><?= (int)($cat['products_count'] ?? 0) ?> products</span></td>
                                <td><?= (int)($cat['sort_order'] ?? 0) ?></td>
                                <td>
                                    <?php if (!empty($cat['is_active'])): ?>
                                        <span class="badge-approved">Active</span>
                                    <?php else: ?>
                                        <span class="badge-rejected">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="display:flex; gap:6px; align-items:center;">
                                        <button type="button" class="btn-sm" onclick='openCategoryModal(<?= htmlspecialchars(json_encode($cat, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, "UTF-8") ?>)'>Edit</button>
                                        <form method="POST" action="/admin" style="display:inline;" onsubmit="return confirm('Delete category <?= htmlspecialchars(addslashes($cat['name']), ENT_QUOTES) ?>? Products will be moved to General.');">
                                            <input type="hidden" name="action" value="delete_category">
                                            <input type="hidden" name="category_id" value="<?= (int)$cat['id'] ?>">
                                            <button type="submit" class="btn-reject-sm">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <div style="margin-top:20px; padding-top:16px; border-top:1px solid #e2e8f0;">
                <h3 style="margin:0 0 8px; font-size:15px; color:#1e1b4b;">+ Add New Category</h3>
                <form method="POST" action="/admin">
                    <input type="hidden" name="action" value="add_category">
                    <div class="grid">
                        <div>
                            <label>Category Name *</label>
                            <input name="name" required maxlength="100" placeholder="e.g. Sports & Fitness">
                        </div>
                        <div>
                            <label>Icon (Emoji)</label>
                            <input name="icon" maxlength="20" value="🏷️" placeholder="e.g. ⚽">
                        </div>
                        <div>
                            <label>Description</label>
                            <input name="description" maxlength="255" placeholder="Short description">
                        </div>
                        <div>
                            <label>Sort Order</label>
                            <input type="number" name="sort_order" value="<?= count($categoriesList) + 1 ?>">
                        </div>
                    </div>
                    <div style="margin-top:10px; display:flex; align-items:center; gap:14px;">
                        <label style="display:inline-flex; align-items:center; gap:6px; margin:0; cursor:pointer;">
                            <input type="checkbox" name="is_active" value="1" checked style="width:auto;"> Active
                        </label>
                        <button type="submit" style="background:#4f46e5;">+ Add Category</button>
                    </div>
                </form>
            </div>
        </section>

        <!-- Section 4: Assign / Edit Product Category -->
        <section class="card" id="productCategoriesSection" style="margin-top:24px;">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:12px;">
                <div>
                    <h2>Products — Edit Category</h2>
                    <p class="muted">Reassign or update the category of any active product directly.</p>
                </div>
                <div class="search-box">
                    <input type="text" id="prodCatSearchInput" onkeyup="filterAdminProductSearch(this.value)" placeholder="Search product or current category...">
                </div>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Product</th>
                        <th>Seller</th>
                        <th>Price</th>
                        <th>Current Category</th>
                        <th>Change Category</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($adminProducts)): ?>
                        <tr><td colspan="6" style="color:#94a3b8; text-align:center; padding:24px;">No products available yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($adminProducts as $ap): ?>
                            <?php $pSearch = strtolower(($ap['name'] ?? '') . ' ' . ($ap['category'] ?? '') . ' ' . ($ap['seller_name'] ?? '')); ?>
                            <tr class="prod-cat-row" data-search="<?= htmlspecialchars($pSearch) ?>">
                                <td>#<?= (int)$ap['id'] ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($ap['name']) ?></strong>
                                    <div style="font-size:11px; color:#64748b;">Stock: <?= (int)$ap['stock'] ?></div>
                                </td>
                                <td><?= htmlspecialchars($ap['seller_name']) ?></td>
                                <td>₹<?= number_format((float)$ap['price'], 2) ?></td>
                                <td><span class="badge-prod"><?= htmlspecialchars($ap['category'] ?: 'General') ?></span></td>
                                <td>
                                    <form method="POST" action="/admin" style="display:flex; gap:6px; align-items:center;">
                                        <input type="hidden" name="action" value="update_product_category">
                                        <input type="hidden" name="product_id" value="<?= (int)$ap['id'] ?>">
                                        <select name="category_id" required style="padding:6px 8px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px;">
                                            <?php
                                                $hasSelectedCat = false;
                                                foreach ($categoriesList as $catOptCheck) {
                                                    if ((int)($ap['category_id'] ?? 0) === (int)$catOptCheck['id'] || strcasecmp((string)$ap['category'], (string)$catOptCheck['name']) === 0) {
                                                        $hasSelectedCat = true;
                                                        break;
                                                    }
                                                }
                                            ?>
                                            <?php if (!$hasSelectedCat): ?>
                                                <option value="" disabled selected>-- Select Category --</option>
                                            <?php endif; ?>
                                            <?php foreach ($categoriesList as $catOpt): ?>
                                                <?php $selected = ((int)($ap['category_id'] ?? 0) === (int)$catOpt['id'] || strcasecmp((string)$ap['category'], (string)$catOpt['name']) === 0) ? 'selected' : ''; ?>
                                                <option value="<?= (int)$catOpt['id'] ?>" <?= $selected ?>><?= htmlspecialchars($catOpt['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn-sm">Save</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>

        <!-- Section 5: Create Seller Account -->
        <section class="card" style="margin-top:24px;">
            <h2>Create New Seller Account</h2>
            <p class="muted">Add a new merchant to Venter App manually. Provide Email Address or Phone Number (with international country code, e.g. +919876543210).</p>
            <form method="POST" action="/admin">
                <input type="hidden" name="action" value="create_seller">
                <div class="grid">
                    <div>
                        <label>Seller / Shop Name</label>
                        <input name="seller_name" value="<?= htmlspecialchars($_POST['seller_name'] ?? '') ?>" required maxlength="120" placeholder="e.g. Apex Electronics">
                    </div>
                    <div>
                        <label>Phone (+country code)</label>
                        <input name="phone" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" placeholder="+919876543210">
                    </div>
                    <div>
                        <label>Email Address</label>
                        <input type="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" placeholder="seller@example.com">
                    </div>
                    <div>
                        <label>State</label>
                        <input name="state" value="<?= htmlspecialchars($_POST['state'] ?? '') ?>" required placeholder="e.g. Karnataka">
                    </div>
                    <div>
                        <label>Address</label>
                        <input name="address" value="<?= htmlspecialchars($_POST['address'] ?? '') ?>" required placeholder="Shop address">
                    </div>
                    <div>
                        <label>Landmark</label>
                        <input name="landmark" value="<?= htmlspecialchars($_POST['landmark'] ?? '') ?>" placeholder="Nearest landmark">
                    </div>
                    <div>
                        <label>Password (min 8 characters)</label>
                        <input type="password" name="password" required minlength="8" autocomplete="new-password">
                    </div>
                    <div>
                        <label>Confirm Password</label>
                        <input type="password" name="password_confirmation" required minlength="8" autocomplete="new-password">
                    </div>
                </div>
                <br>
                <button type="submit" style="background:#4f46e5;">+ Create Seller Account</button>
            </form>
        </section>
    </main>

    <!-- GST Review & Verification Modal -->
    <div id="gstReviewModal" class="modal-overlay" onclick="handleGstOverlayClick(event)">
        <div class="modal-box" style="max-width: 780px;">
            <div class="modal-header">
                <h3 id="gstModalTitle">GST Verification Review</h3>
                <button type="button" class="modal-close" onclick="closeGstModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div id="gstModalLoading" style="text-align:center; padding:32px; color:#64748b;">
                    Loading GST details & documents...
                </div>

                <div id="gstModalContent" style="display:none;">
                    <div class="info-pill" style="grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));">
                        <div class="info-pill-item">Seller<strong id="modalSellerName">—</strong></div>
                        <div class="info-pill-item">Status<strong id="modalGstStatus">—</strong></div>
                        <div class="info-pill-item">Submitted At<strong id="modalSubmittedAt">—</strong></div>
                        <div class="info-pill-item">Verified At<strong id="modalVerifiedAt">—</strong></div>
                    </div>

                    <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:16px; margin-bottom:18px;">
                        <div style="font-size:12px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:8px;">Registered GST Information</div>
                        <div style="display:flex; align-items:center; gap:12px; margin-bottom:10px;">
                            <span style="font-size:13px; color:#475569;">GSTIN:</span>
                            <span class="gstin-pill" id="modalGstin" style="font-size:15px;">—</span>
                        </div>
                        <div style="font-size:14px; margin-bottom:4px;"><strong>Business Name:</strong> <span id="modalBusinessName">—</span></div>
                        <div style="font-size:14px; margin-bottom:4px;"><strong>Address:</strong> <span id="modalBusinessAddress">—</span></div>
                        <div style="font-size:14px;"><strong>State & PIN:</strong> <span id="modalStatePin">—</span></div>

                        <div id="rejectionAlertBox" style="display:none; background:#fee2e2; border-left:4px solid #ef4444; padding:12px; border-radius:6px; margin-top:12px;">
                            <strong style="color:#b91c1c; font-size:13px;">Rejection Reason:</strong>
                            <div id="modalRejectionReason" style="color:#7f1d1d; font-size:13px; margin-top:4px;">—</div>
                        </div>
                    </div>

                    <h4 style="margin: 18px 0 10px; color:#1e1b4b;">Uploaded Documents (<span id="modalDocCount">0</span>)</h4>
                    <div id="modalDocumentsList" style="margin-bottom: 24px;">
                        <!-- Documents rendered via JS -->
                    </div>

                    <div style="border-top:1px solid #e2e8f0; padding-top:18px; margin-top:18px;">
                        <h4 style="margin: 0 0 12px; color:#1e1b4b;">Admin Verification Actions</h4>
                        
                        <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-start;">
                            <!-- Approve Form -->
                            <form method="POST" action="/admin" onsubmit="return confirm('Are you sure you want to APPROVE this seller GST verification?');">
                                <input type="hidden" name="action" value="approve_gst">
                                <input type="hidden" name="seller_id" id="approveSellerId">
                                <input type="hidden" name="notes" value="Approved by administrator">
                                <button type="submit" class="btn-approve">Approve GST</button>
                            </form>

                            <!-- Reject Toggle Button -->
                            <button type="button" class="btn-reject" onclick="toggleRejectBox()">Reject GST...</button>
                        </div>

                        <!-- Reject Reason Form Box -->
                        <div id="rejectBox" style="display:none; background:#fef2f2; border:1px solid #fecaca; border-radius:10px; padding:16px; margin-top:14px;">
                            <form method="POST" action="/admin">
                                <input type="hidden" name="action" value="reject_gst">
                                <input type="hidden" name="seller_id" id="rejectSellerId">
                                <label style="margin-top:0; color:#991b1b; font-weight:700;">Mandatory Rejection Reason <span style="color:#ef4444;">*</span></label>
                                <textarea name="rejection_reason" required rows="3" style="width:100%; padding:10px; border:1px solid #fca5a5; border-radius:8px; font-family:inherit; font-size:13px; outline:none; margin-top:4px;" placeholder="Explain clearly to the seller why their GST documents were rejected (e.g. invalid document, blurry certificate, name mismatch)..."></textarea>
                                <div style="display:flex; gap:10px; margin-top:10px;">
                                    <button type="submit" class="btn-reject">Confirm Rejection</button>
                                    <button type="button" onclick="toggleRejectBox()" style="background:#e2e8f0; color:#334155;" class="btn">Cancel</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Verification Audit Logs -->
                    <div style="border-top:1px solid #e2e8f0; padding-top:18px; margin-top:24px;">
                        <h4 style="margin: 0 0 10px; color:#1e1b4b;">Verification Audit Trail</h4>
                        <div id="modalLogsList" style="max-height:160px; overflow-y:auto; border:1px solid #f1f5f9; border-radius:8px; padding:0 12px; background:#fafafa;">
                            <!-- Logs rendered via JS -->
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Category Modal -->
    <div id="editCategoryModal" class="modal-overlay" onclick="if(event.target.id==='editCategoryModal') closeCategoryModal();">
        <div class="modal-box">
            <div class="modal-header">
                <h3 id="catModalTitle">Edit Category</h3>
                <button type="button" class="modal-close" onclick="closeCategoryModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" action="/admin">
                    <input type="hidden" name="action" value="edit_category">
                    <input type="hidden" name="category_id" id="editCatId">
                    <div class="grid">
                        <div>
                            <label>Category Name *</label>
                            <input name="name" id="editCatName" required maxlength="100">
                        </div>
                        <div>
                            <label>Icon (Emoji)</label>
                            <input name="icon" id="editCatIcon" maxlength="20">
                        </div>
                        <div style="grid-column: 1 / -1;">
                            <label>Description</label>
                            <input name="description" id="editCatDesc" maxlength="255">
                        </div>
                        <div>
                            <label>Sort Order</label>
                            <input type="number" name="sort_order" id="editCatSort">
                        </div>
                        <div style="display:flex; align-items:flex-end; padding-bottom:6px;">
                            <label style="display:inline-flex; align-items:center; gap:8px; margin:0; cursor:pointer;">
                                <input type="checkbox" name="is_active" id="editCatActive" value="1" style="width:auto;"> Category Active
                            </label>
                        </div>
                    </div>
                    <br>
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <button type="submit">Save Category Changes</button>
                        <button type="button" onclick="closeCategoryModal()" style="background:#e2e8f0; color:#334155;">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Seller Profile & Edit Modal -->
    <div id="sellerModal" class="modal-overlay" onclick="handleOverlayClick(event)">
        <div class="modal-box">
            <div class="modal-header">
                <h3 id="modalTitle">Seller Profile</h3>
                <button type="button" class="modal-close" onclick="closeSellerModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="info-pill" style="grid-template-columns: repeat(2, 1fr);">
                    <div class="info-pill-item">Seller ID<strong id="viewSellerId">—</strong></div>
                    <div class="info-pill-item">Registered On<strong id="viewCreatedAt">—</strong></div>
                </div>

                <form method="POST" action="/admin">
                    <input type="hidden" name="action" value="edit_seller">
                    <input type="hidden" name="seller_id" id="formSellerId">
                    
                    <div class="grid">
                        <div>
                            <label>Seller / Shop Name</label>
                            <input name="seller_name" id="formSellerName" required maxlength="120">
                        </div>
                        <div>
                            <label>Phone (+country code)</label>
                            <input name="phone" id="formPhone" placeholder="+919876543210">
                        </div>
                        <div style="grid-column: 1 / -1;">
                            <label>Email Address</label>
                            <input type="email" name="email" id="formEmail" placeholder="seller@example.com">
                        </div>
                        <div>
                            <label>State</label>
                            <input name="state" id="formState" required>
                        </div>
                        <div>
                            <label>Country</label>
                            <input name="country" id="formCountry" required>
                        </div>
                        <div style="grid-column: 1 / -1;">
                            <label>Address</label>
                            <input name="address" id="formAddress" required>
                        </div>
                        <div style="grid-column: 1 / -1;">
                            <label>Landmark</label>
                            <input name="landmark" id="formLandmark" required>
                        </div>
                        <div style="grid-column: 1 / -1; margin-top: 6px; padding-top: 10px; border-top: 1px solid #e2e8f0;">
                            <strong style="color: #334155; font-size: 13px;">Change Password</strong>
                            <span style="font-size: 12px; color: #64748b; margin-left: 6px;">(leave blank if not changing)</span>
                        </div>
                        <div>
                            <label>Current Password</label>
                            <input type="password" name="current_password" id="formCurrentPassword" placeholder="Current password" autocomplete="current-password">
                        </div>
                        <div>
                            <label>New Password</label>
                            <input type="password" name="new_password" id="formNewPassword" minlength="8" placeholder="At least 8 characters" autocomplete="new-password">
                        </div>
                        <div style="grid-column: 1 / -1;">
                            <label>Confirm Password</label>
                            <input type="password" name="confirm_password" id="formConfirmPassword" minlength="8" placeholder="Confirm new password" autocomplete="new-password">
                        </div>
                    </div>
                    <br>
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <button type="submit">Save Changes</button>
                        <button type="button" onclick="closeSellerModal()" style="background:#e2e8f0; color:#334155;">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Seller Deletion Preflight Modal -->
    <div id="deletionPreflightModal" class="modal-overlay" onclick="if(event.target.id==='deletionPreflightModal') closeDeletionPreflightModal();">
        <div class="modal-box wide">
            <div class="modal-header">
                <h3 id="delPreflightTitle">Request Seller Deletion — Preflight Audit</h3>
                <button type="button" class="modal-close" onclick="closeDeletionPreflightModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div id="delPreflightLoading" style="text-align:center; padding:32px; color:#64748b;">Computing orders, refunds, payouts &amp; settlement snapshot...</div>
                <div id="delPreflightBody" style="display:none;"></div>
            </div>
        </div>
    </div>

    <!-- Seller Deletion Review Workspace Modal -->
    <div id="deletionWorkspaceModal" class="modal-overlay" onclick="if(event.target.id==='deletionWorkspaceModal') closeDeletionWorkspaceModal();">
        <div class="modal-box wide">
            <div class="modal-header">
                <h3 id="delWsTitle">Seller Deletion Review Workspace</h3>
                <button type="button" class="modal-close" onclick="closeDeletionWorkspaceModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div id="delWsLoading" style="text-align:center; padding:32px; color:#64748b;">Loading seller deletion workspace...</div>
                <div id="delWsBody" style="display:none;"></div>
            </div>
        </div>
    </div>

    <script>
        function openCategoryModal(cat) {
            document.getElementById('catModalTitle').innerText = 'Edit Category #' + cat.id + ' — ' + (cat.name || '');
            document.getElementById('editCatId').value = cat.id;
            document.getElementById('editCatName').value = cat.name || '';
            document.getElementById('editCatIcon').value = cat.icon || '';
            document.getElementById('editCatDesc').value = cat.description || '';
            document.getElementById('editCatSort').value = cat.sort_order !== undefined ? cat.sort_order : 0;
            document.getElementById('editCatActive').checked = Boolean(Number(cat.is_active));
            document.getElementById('editCategoryModal').classList.add('active');
        }

        function closeCategoryModal() {
            document.getElementById('editCategoryModal').classList.remove('active');
        }

        function filterAdminProductSearch(query) {
            const q = (query || '').toLowerCase().trim();
            document.querySelectorAll('.prod-cat-row').forEach(r => {
                const s = r.getAttribute('data-search') || '';
                r.style.display = (!q || s.includes(q)) ? '' : 'none';
            });
        }

        function openSellerModal(seller) {
            document.getElementById('modalTitle').innerText = 'Seller Profile — ' + (seller.seller_name || seller.name || 'Seller');
            document.getElementById('viewSellerId').innerText = '#' + seller.id;
            document.getElementById('viewCreatedAt').innerText = (seller.created_at || '').slice(0, 10) || '—';

            document.getElementById('formSellerId').value = seller.id;
            document.getElementById('formSellerName').value = seller.seller_name || seller.name || '';
            document.getElementById('formPhone').value = seller.phone || '';
            document.getElementById('formEmail').value = (seller.email && !seller.email.endsWith('@phone.vendor.local')) ? seller.email : '';
            document.getElementById('formAddress').value = seller.address || '';
            document.getElementById('formLandmark').value = seller.landmark || '';
            document.getElementById('formState').value = seller.state || '';
            document.getElementById('formCountry').value = seller.country || 'India';

            document.getElementById('formCurrentPassword').value = '';
            document.getElementById('formNewPassword').value = '';
            document.getElementById('formConfirmPassword').value = '';

            document.getElementById('sellerModal').classList.add('active');
        }

        function closeSellerModal() {
            document.getElementById('sellerModal').classList.remove('active');
        }

        function handleOverlayClick(e) {
            if (e.target.id === 'sellerModal') {
                closeSellerModal();
            }
        }

        // GST Verification Table Filter by Status
        function filterGstRows(status, btn) {
            document.querySelectorAll('#gstSection .tab-btn').forEach(b => b.classList.remove('active'));
            if (btn) btn.classList.add('active');

            const rows = document.querySelectorAll('.gst-row');
            rows.forEach(r => {
                const rStatus = r.getAttribute('data-status');
                if (status === 'all' || rStatus === status) {
                    r.style.display = '';
                } else {
                    r.style.display = 'none';
                }
            });
        }

        // Live Search Filter for GST PDF Table
        function filterGstSearch(query) {
            const q = (query || '').toLowerCase().trim();
            const rows = document.querySelectorAll('.gst-row');
            rows.forEach(r => {
                const searchData = r.getAttribute('data-search') || '';
                if (!q || searchData.includes(q)) {
                    r.style.display = '';
                } else {
                    r.style.display = 'none';
                }
            });
        }

        // Quick Reject Shortcut
        function openGstModalWithReject(sellerId, sellerName) {
            openGstModal(sellerId, sellerName);
            setTimeout(() => {
                const b = document.getElementById('rejectBox');
                if (b) {
                    b.style.display = 'block';
                    b.scrollIntoView({ behavior: 'smooth' });
                }
            }, 300);
        }

        // GST Review Modal
        function openGstModal(sellerId, sellerName) {
            document.getElementById('gstModalTitle').innerText = 'GST Verification Review — ' + (sellerName || 'Seller #' + sellerId);
            document.getElementById('approveSellerId').value = sellerId;
            document.getElementById('rejectSellerId').value = sellerId;
            document.getElementById('rejectBox').style.display = 'none';

            document.getElementById('gstModalLoading').style.display = 'block';
            document.getElementById('gstModalContent').style.display = 'none';
            document.getElementById('gstReviewModal').classList.add('active');

            fetch('/admin/dashboard.php?action=get_gst_details&seller_id=' + sellerId)
                .then(res => res.json())
                .then(data => {
                    document.getElementById('gstModalLoading').style.display = 'none';
                    document.getElementById('gstModalContent').style.display = 'block';

                    const g = data.gst || {};
                    const docs = data.documents || [];
                    const logs = data.logs || [];

                    document.getElementById('modalSellerName').innerText = sellerName + ' (#' + sellerId + ')';
                    document.getElementById('modalSubmittedAt').innerText = (g.submitted_at || g.created_at || '—').slice(0, 16);
                    document.getElementById('modalVerifiedAt').innerText = (g.verified_at || '—').slice(0, 16);

                    // Status Badge
                    const st = (g.status || 'Pending').toLowerCase();
                    let badgeHtml = '<span class="badge-pending">' + (g.status || 'Pending') + '</span>';
                    if (st === 'approved') badgeHtml = '<span class="badge-approved">' + g.status + '</span>';
                    if (st === 'rejected') badgeHtml = '<span class="badge-rejected">' + g.status + '</span>';
                    document.getElementById('modalGstStatus').innerHTML = badgeHtml;

                    document.getElementById('modalGstin').innerText = g.gstin || '—';
                    document.getElementById('modalBusinessName').innerText = g.business_name || '—';
                    document.getElementById('modalBusinessAddress').innerText = g.business_address || '—';
                    document.getElementById('modalStatePin').innerText = (g.state || '—') + (g.pincode ? ' (PIN: ' + g.pincode + ')' : '');

                    // Rejection alert box
                    const rejBox = document.getElementById('rejectionAlertBox');
                    if (g.rejection_reason) {
                        rejBox.style.display = 'block';
                        document.getElementById('modalRejectionReason').innerText = g.rejection_reason;
                    } else {
                        rejBox.style.display = 'none';
                    }

                    // Render Documents
                    document.getElementById('modalDocCount').innerText = docs.length;
                    const docsContainer = document.getElementById('modalDocumentsList');
                    if (docs.length === 0) {
                        docsContainer.innerHTML = '<div style="color:#94a3b8; font-size:13px; padding:12px; background:#f8fafc; border-radius:8px;">No documents uploaded by this seller yet.</div>';
                    } else {
                        let docsHtml = '';
                        docs.forEach(d => {
                            const isPdf = d.is_pdf || (d.mime_type && d.mime_type.toLowerCase() === 'application/pdf');
                            const typeBadge = isPdf ? '<span class="badge-pdf">PDF</span>' : '<span class="badge-img">IMAGE</span>';
                            const primaryTag = d.is_primary ? '<span style="background:#e0e7ff; color:#3730a3; font-size:11px; font-weight:700; padding:2px 6px; border-radius:4px; margin-left:6px;">Main Certificate</span>' : '';
                            const docLabel = (d.document_type === 'gst_certificate') ? 'GST Registration Certificate' : 'Supporting Document';

                            docsHtml += `
                                <div class="doc-card">
                                    <div>
                                        <div class="doc-title">${typeBadge} ${escapeHtml(docLabel)} ${primaryTag}</div>
                                        <div class="doc-meta">${escapeHtml(d.original_name)} &bull; ${d.file_size_formatted || ''} &bull; Uploaded ${d.created_at ? d.created_at.slice(0, 10) : ''}</div>
                                    </div>
                                    <div style="display:flex; gap:8px;">
                                        <a href="/admin/dashboard.php?action=view_gst_doc&id=${d.id}&mode=preview" target="_blank" class="btn-view">${isPdf ? '📄 Preview PDF' : '🔍 Preview'}</a>
                                        <a href="/admin/dashboard.php?action=view_gst_doc&id=${d.id}&mode=download" class="btn-dl">⬇ Download</a>
                                    </div>
                                </div>
                            `;
                        });
                        docsContainer.innerHTML = docsHtml;
                    }

                    // Render Audit Logs
                    const logsContainer = document.getElementById('modalLogsList');
                    if (logs.length === 0) {
                        logsContainer.innerHTML = '<div style="color:#94a3b8; font-size:12px; padding:10px 0;">No history logs recorded yet.</div>';
                    } else {
                        let logsHtml = '';
                        logs.forEach(l => {
                            logsHtml += `
                                <div class="log-row">
                                    <div class="log-time">${escapeHtml((l.created_at || '').slice(0, 16))}</div>
                                    <div style="flex:1;">
                                        <strong>${escapeHtml(l.action.toUpperCase())}</strong>
                                        <span style="color:#64748b;">(${escapeHtml(l.performer_role || 'seller')}):</span>
                                        ${escapeHtml(l.notes || '')}
                                    </div>
                                    <div><span class="badge-prod" style="background:#f1f5f9; color:#475569;">${escapeHtml(l.new_status)}</span></div>
                                </div>
                            `;
                        });
                        logsContainer.innerHTML = logsHtml;
                    }
                })
                .catch(err => {
                    document.getElementById('gstModalLoading').innerText = 'Failed to load GST details: ' + err.message;
                });
        }

        function closeGstModal() {
            document.getElementById('gstReviewModal').classList.remove('active');
        }

        function toggleRejectBox() {
            const b = document.getElementById('rejectBox');
            b.style.display = (b.style.display === 'none' || !b.style.display) ? 'block' : 'none';
        }

        function handleGstOverlayClick(e) {
            if (e.target.id === 'gstReviewModal') {
                closeGstModal();
            }
        }

        function escapeHtml(str) {
            if (!str) return '';
            return String(str).replace(/[&<>'"]/g, tag => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                "'": '&#39;',
                '"': '&quot;'
            }[tag] || tag));
        }

        // === Seller Deletion & Refund Management UI Controller ===
        window._activeDelStatusFilter = 'all';
        window._currentWsData = null;

        function delStatusBadgeHtml(statusOrColor, textOverride) {
            var s = String(statusOrColor || '').toUpperCase();
            var cls = 'del-yellow';
            if (s === 'GREEN' || s === 'SUCCESS' || s === 'REFUND_SUCCESS' || s === 'APPROVED_FOR_DELETION' || s === 'DELETED' || s === 'SETTLED' || s === 'ACTIVE' || s === 'RESOLVED') cls = 'del-green';
            else if (s === 'BLUE' || s.indexOf('PROCESSING') !== -1 || s.indexOf('VERIFICATION') !== -1 || s.indexOf('REVIEW') !== -1 || s === 'LOCKED') cls = 'del-blue';
            else if (s === 'RED' || s.indexOf('FAILED') !== -1 || s.indexOf('REJECTED') !== -1) cls = 'del-red';
            else if (s === 'ORANGE' || s.indexOf('HOLD') !== -1 || s === 'OPEN' || s.indexOf('REQUIRES_REVIEW') !== -1) cls = 'del-orange';
            return '<span class="del-badge ' + cls + '">' + escapeHtml(textOverride || statusOrColor || 'PENDING') + '</span>';
        }

        function fmtCur(v) {
            return '₹' + Number(v || 0).toFixed(2);
        }

        function filterDeletionStatus(status, btn) {
            window._activeDelStatusFilter = status || 'all';
            document.querySelectorAll('#delFilterTabs .tab-btn').forEach(function(b) { b.classList.remove('active'); });
            if (btn) btn.classList.add('active');
            applyDeletionTableFilters();
        }

        function filterDeletionTableSearch(q) {
            window._activeDelSearch = (q || '').toLowerCase().trim();
            applyDeletionTableFilters();
        }

        function applyDeletionTableFilters() {
            var st = window._activeDelStatusFilter || 'all';
            var q = window._activeDelSearch || '';
            document.querySelectorAll('.del-req-row').forEach(function(r) {
                var rSt = r.getAttribute('data-status') || '';
                var rTxt = r.getAttribute('data-search') || '';
                var matchSt = (st === 'all' || rSt === st);
                var matchQ = (!q || rTxt.indexOf(q) !== -1);
                r.style.display = (matchSt && matchQ) ? '' : 'none';
            });
        }

        function reloadDeletionRequestsList() {
            fetch('/api/admin/seller-deletions', { credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data || !data.requests) return;
                    var k = data.kpis || {};
                    var el;
                    if ((el = document.getElementById('delKpiTotal'))) el.innerText = Number(k.total || 0);
                    if ((el = document.getElementById('delKpiPending'))) el.innerText = Number(k.pending || 0);
                    if ((el = document.getElementById('delKpiProcessing'))) el.innerText = Number(k.processing || 0);
                    if ((el = document.getElementById('delKpiFailed'))) el.innerText = Number(k.failed || 0);
                    if ((el = document.getElementById('delKpiHold'))) el.innerText = Number(k.on_hold || 0);
                    if ((el = document.getElementById('delKpiApprovedDeleted'))) el.innerText = Number(k.approved || 0) + Number(k.deleted || 0);

                    var tbody = document.getElementById('deletionRequestsTbody');
                    if (!tbody) return;
                    if (data.requests.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="7" style="color:#94a3b8; text-align:center; padding:28px;">No seller deletion requests submitted yet.</td></tr>';
                        return;
                    }
                    var html = '';
                    data.requests.forEach(function(dr) {
                        var snapM = dr.metrics || {};
                        var finS = dr.financial_summary || {};
                        var searchTxt = ((dr.seller_name || '') + ' ' + (dr.seller_email || '') + ' ' + (dr.seller_phone || '') + ' ' + dr.seller_id + ' ' + dr.status + ' ' + dr.stage).toLowerCase();
                        html += '<tr class="del-req-row" data-status="' + escapeHtml(dr.status) + '" data-search="' + escapeHtml(searchTxt) + '">' +
                            '<td><strong>#' + Number(dr.id) + '</strong></td>' +
                            '<td><strong style="color:#1e1b4b;">' + escapeHtml(dr.seller_name) + '</strong>' +
                            '<div style="font-size:12px; color:#64748b;">Seller ID #' + Number(dr.seller_id) + (dr.seller_phone ? ' &bull; ' + escapeHtml(dr.seller_phone) : '') + '</div>' +
                            (dr.seller_email ? '<div style="font-size:11px; color:#94a3b8;">' + escapeHtml(dr.seller_email) + '</div>' : '') +
                            '</td>' +
                            '<td><div style="font-size:13px;">' + escapeHtml((dr.created_at || '').slice(0, 10)) + '</div><div style="font-size:11px; color:#94a3b8;">' + escapeHtml((dr.created_at || '').slice(11, 19)) + '</div></td>' +
                            '<td><code style="background:#f1f5f9; padding:3px 6px; border-radius:4px; font-size:12px;">' + escapeHtml(dr.stage) + '</code></td>' +
                            '<td>' + delStatusBadgeHtml(dr.badge_color || dr.status, dr.status) + '</td>' +
                            '<td style="font-size:12px; color:#475569;">Orders: <strong>' + Number(snapM.total_orders || 0) + '</strong> &bull; Balance: <strong>' + fmtCur(finS.available_seller_balance || snapM.seller_wallet_balance || 0) + '</strong></td>' +
                            '<td><button type="button" class="btn-sm" onclick="openDeletionWorkspaceModal(' + Number(dr.id) + ')">Manage Deletion</button></td>' +
                            '</tr>';
                    });
                    tbody.innerHTML = html;
                    applyDeletionTableFilters();
                })
                .catch(function() {});
        }

        function closeDeletionPreflightModal() {
            document.getElementById('deletionPreflightModal').classList.remove('active');
        }

        function openDeletionPreflightModal(sellerId) {
            document.getElementById('delPreflightTitle').innerText = 'Request Seller Deletion — Seller #' + sellerId;
            document.getElementById('delPreflightLoading').style.display = 'block';
            document.getElementById('delPreflightBody').style.display = 'none';
            document.getElementById('deletionPreflightModal').classList.add('active');

            fetch('/api/admin/seller-deletions/preflight/' + Number(sellerId), { credentials: 'same-origin' })
                .then(function(r) { return r.json().then(function(d) { return { ok: r.ok, d: d }; }); })
                .then(function(resObj) {
                    document.getElementById('delPreflightLoading').style.display = 'none';
                    var bodyEl = document.getElementById('delPreflightBody');
                    bodyEl.style.display = 'block';
                    if (!resObj.ok || !resObj.d || !resObj.d.seller) {
                        bodyEl.innerHTML = '<div style="background:#fee2e2;color:#b91c1c;padding:14px;border-radius:8px;">' + escapeHtml((resObj.d && resObj.d.message) || 'Failed to load preflight metrics.') + '</div>';
                        return;
                    }
                    var s = resObj.d.seller;
                    var m = resObj.d.metrics || {};
                    var cards = [
                        ['Total Products (Active / Total)', Number(m.active_products || 0) + ' / ' + Number(m.total_products || 0)],
                        ['Total Orders', Number(m.total_orders || 0)],
                        ['Pending Orders (Rule A)', Number(m.pending_orders || 0)],
                        ['Confirmed / Accepted Orders (Rule B)', Number(m.confirmed_orders || 0)],
                        ['Shipped Orders (Rule C)', Number(m.shipped_orders || 0)],
                        ['Delivered Orders (Rule D)', Number(m.delivered_orders || 0)],
                        ['Cancelled Orders (Rule E)', Number(m.cancelled_orders || 0)],
                        ['Total Successful Payments', fmtCur(m.total_successful_payments || 0)],
                        ['Pending Refunds', Number(m.pending_refunds_count || 0) + ' (' + fmtCur(m.pending_refunds_amount || 0) + ')'],
                        ['Seller Wallet Balance', fmtCur(m.seller_wallet_balance || 0)],
                        ['Pending Payouts', Number(m.pending_payouts_count || 0) + ' (' + fmtCur(m.pending_payouts_amount || 0) + ')'],
                        ['Outstanding Commissions', fmtCur(m.outstanding_commissions || 0)],
                        ['Open Disputes', Number(m.open_disputes_count || 0)]
                    ];
                    var html = '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px;margin-bottom:14px;display:flex;justify-content:space-between;flex-wrap:wrap;gap:10px;">' +
                        '<div><strong style="font-size:16px;color:#1e1b4b;">' + escapeHtml(s.seller_name || s.name || ('Seller #' + s.id)) + '</strong>' +
                        '<div style="font-size:12px;color:#64748b;margin-top:2px;">Seller ID #' + Number(s.id) + ' &bull; ' + escapeHtml(s.phone || '—') + (s.email ? ' &bull; ' + escapeHtml(s.email) : '') + '</div></div>' +
                        '<div>' + delStatusBadgeHtml(s.account_status === 'ACTIVE' ? 'GREEN' : 'YELLOW', s.account_status || 'ACTIVE') + '</div></div>' +
                        '<div class="grid" style="grid-template-columns:repeat(auto-fit, minmax(175px, 1fr));gap:10px;margin-bottom:16px;">';
                    cards.forEach(function(c) {
                        html += '<div class="metric-mini"><div class="k">' + escapeHtml(c[0]) + '</div><div class="v">' + escapeHtml(String(c[1])) + '</div></div>';
                    });
                    html += '</div>' +
                        '<div style="background:#fffbeb;border:1px solid #fcd34d;padding:12px;border-radius:8px;font-size:13px;color:#92400e;margin-bottom:14px;">' +
                        'Submitting this request sets the seller account immediately to <strong>DELETION_PENDING</strong>, notifies the seller, and blocks new buyer orders without deleting any historical data.' +
                        '</div>' +
                        '<label>Deletion Request Reason *</label>' +
                        '<input type="text" id="preflightReason" value="Admin initiated seller deletion audit" maxlength="255">' +
                        '<label>Admin Remarks</label>' +
                        '<textarea id="preflightRemarks" rows="2" placeholder="Optional initial audit remarks..."></textarea>' +
                        '<div id="preflightErrBox" style="color:#b91c1c;font-size:13px;margin-top:8px;"></div>' +
                        '<div style="display:flex;gap:10px;margin-top:16px;justify-content:flex-end;">' +
                        '<button type="button" onclick="closeDeletionPreflightModal()" style="background:#e2e8f0;color:#334155;">Cancel</button>' +
                        '<button type="button" onclick="submitCreateDeletionRequest(' + Number(s.id) + ')" style="background:#dc2626;">Confirm Deletion Request (DELETION_PENDING)</button>' +
                        '</div>';
                    bodyEl.innerHTML = html;
                })
                .catch(function(err) {
                    document.getElementById('delPreflightLoading').style.display = 'none';
                    document.getElementById('delPreflightBody').style.display = 'block';
                    document.getElementById('delPreflightBody').innerHTML = '<div style="color:#b91c1c;">Error: ' + escapeHtml(err.message) + '</div>';
                });
        }

        function submitCreateDeletionRequest(sellerId) {
            var reason = (document.getElementById('preflightReason') || {}).value || '';
            var remarks = (document.getElementById('preflightRemarks') || {}).value || '';
            var errBox = document.getElementById('preflightErrBox');
            if (errBox) errBox.innerText = '';
            fetch('/api/admin/seller-deletions', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ seller_id: sellerId, reason: reason, remarks: remarks })
            })
            .then(function(r) { return r.json().then(function(d) { return { ok: r.ok, d: d }; }); })
            .then(function(resObj) {
                if (!resObj.ok) {
                    if (errBox) errBox.innerText = (resObj.d && resObj.d.message) || 'Failed to create deletion request.';
                    return;
                }
                closeDeletionPreflightModal();
                reloadDeletionRequestsList();
                if (resObj.d && resObj.d.deletion_request) {
                    openDeletionWorkspaceModal(resObj.d.deletion_request.id);
                }
            })
            .catch(function(err) {
                if (errBox) errBox.innerText = err.message;
            });
        }

        function closeDeletionWorkspaceModal() {
            document.getElementById('deletionWorkspaceModal').classList.remove('active');
        }

        function openDeletionWorkspaceModal(reqId) {
            document.getElementById('delWsTitle').innerText = 'Seller Deletion Request #' + Number(reqId);
            document.getElementById('delWsLoading').style.display = 'block';
            document.getElementById('delWsBody').style.display = 'none';
            document.getElementById('deletionWorkspaceModal').classList.add('active');

            fetch('/api/admin/seller-deletions/' + Number(reqId), { credentials: 'same-origin' })
                .then(function(r) { return r.json().then(function(d) { return { ok: r.ok, d: d }; }); })
                .then(function(resObj) {
                    document.getElementById('delWsLoading').style.display = 'none';
                    var bodyEl = document.getElementById('delWsBody');
                    bodyEl.style.display = 'block';
                    if (!resObj.ok || !resObj.d || !resObj.d.deletion_request) {
                        bodyEl.innerHTML = '<div style="color:#b91c1c;">' + escapeHtml((resObj.d && resObj.d.message) || 'Failed to load deletion request.') + '</div>';
                        return;
                    }
                    renderDeletionWorkspace(resObj.d);
                })
                .catch(function(err) {
                    document.getElementById('delWsLoading').style.display = 'none';
                    document.getElementById('delWsBody').style.display = 'block';
                    document.getElementById('delWsBody').innerHTML = '<div style="color:#b91c1c;">Error: ' + escapeHtml(err.message) + '</div>';
                });
        }

        function runDelAction(reqId, subPath, bodyObj) {
            var remarksInput = document.getElementById('wsAdminRemarks');
            var payload = Object.assign({}, bodyObj || {});
            if (remarksInput && remarksInput.value.trim() && !payload.remarks) {
                payload.remarks = remarksInput.value.trim();
            }
            var msgBox = document.getElementById('wsFeedbackBox');
            if (msgBox) msgBox.innerHTML = '<span style="color:#1d4ed8;">Processing action...</span>';

            fetch('/api/admin/seller-deletions/' + Number(reqId) + '/' + subPath, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(function(r) { return r.json().then(function(d) { return { ok: r.ok, d: d }; }); })
            .then(function(resObj) {
                if (!resObj.ok) {
                    if (msgBox) msgBox.innerHTML = '<div style="background:#fee2e2;color:#b91c1c;padding:10px;border-radius:8px;margin-bottom:12px;">' + escapeHtml((resObj.d && resObj.d.message) || 'Action failed.') + '</div>';
                    return;
                }
                if (resObj.d && resObj.d.deletion_request) {
                    renderDeletionWorkspace(resObj.d, (resObj.d.message || 'Action completed.'));
                }
                reloadDeletionRequestsList();
            })
            .catch(function(err) {
                if (msgBox) msgBox.innerHTML = '<div style="background:#fee2e2;color:#b91c1c;padding:10px;border-radius:8px;margin-bottom:12px;">' + escapeHtml(err.message) + '</div>';
            });
        }

        function handleSettlePayoutClick(reqId) {
            var chk = document.getElementById('wsConfirmGatewaySettled');
            var ref = document.getElementById('wsPayoutRef');
            runDelAction(reqId, 'settle-payout', {
                confirm_gateway_settled: Boolean(chk && chk.checked),
                payout_reference: (ref && ref.value.trim()) || ''
            });
        }

        function handleHoldClick(reqId) {
            var reason = prompt('Enter reason for putting this seller deletion request ON HOLD:', 'Awaiting manual verification / return period');
            if (reason === null) return;
            runDelAction(reqId, 'hold', { reason: reason });
        }

        function handleRejectClick(reqId) {
            var reason = prompt('Enter MANDATORY reason to REJECT seller deletion and restore seller to ACTIVE:', '');
            if (!reason || !reason.trim()) return;
            runDelAction(reqId, 'reject', { reason: reason.trim() });
        }

        function toggleFinalDeleteConfirm() {
            var b = document.getElementById('wsFinalConfirmBox');
            if (b) b.style.display = (b.style.display === 'none' || !b.style.display) ? 'block' : 'none';
        }

        function submitFinalDelete(reqId) {
            var dr = (window._currentWsData && window._currentWsData.deletion_request) || {};
            var phraseInput = document.getElementById('wsConfirmPhraseInput');
            var phrase = phraseInput ? phraseInput.value.trim() : '';
            runDelAction(reqId, 'final-delete', {
                confirmation_token: dr.confirmation_token || '',
                confirmation_phrase: phrase
            });
        }

        function renderDeletionWorkspace(data, successBanner) {
            window._currentWsData = data;
            var dr = data.deletion_request || {};
            var s = data.seller || {};
            var m = data.metrics || {};
            var set = data.settlement || {};
            var orders = data.orders || [];
            var refunds = data.refunds || [];
            var payouts = data.payouts || [];
            var disputes = data.disputes || [];
            var checks = data.checks || [];
            var logs = data.audit_logs || [];
            var isTerminal = (dr.status === 'DELETED' || dr.status === 'DELETION_REJECTED');

            var html = '<div id="wsFeedbackBox">' +
                (successBanner ? '<div style="background:#dcfce7;color:#15803d;padding:10px 14px;border-radius:8px;margin-bottom:12px;font-weight:600;">' + escapeHtml(successBanner) + '</div>' : '') +
                '</div>';

            html += '<div class="ws-section" style="background:#f8fafc;">' +
                '<div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;">' +
                '<div>' +
                '<div style="font-size:17px;font-weight:800;color:#1e1b4b;">' + escapeHtml(s.seller_name || s.name || ('Seller #' + dr.seller_id)) + ' <span style="font-size:13px;color:#64748b;">(Seller ID #' + Number(dr.seller_id) + ')</span></div>' +
                '<div style="font-size:13px;color:#475569;margin-top:4px;">Phone: ' + escapeHtml(s.phone || '—') + ' &bull; Email: ' + escapeHtml(s.email || '—') + ' &bull; Account: <strong>' + escapeHtml(s.account_status || 'ACTIVE') + '</strong></div>' +
                '<div style="font-size:12px;color:#64748b;margin-top:4px;">Request Date: <strong>' + escapeHtml((dr.created_at || '').slice(0, 19).replace('T', ' ')) + '</strong> &bull; Reason: ' + escapeHtml(dr.request_reason || '—') + '</div>' +
                '</div>' +
                '<div style="text-align:right;">' +
                '<div style="margin-bottom:6px;">Stage: <code style="background:#e0e7ff;color:#3730a3;padding:3px 8px;border-radius:6px;font-weight:700;">' + escapeHtml(dr.stage) + '</code></div>' +
                '<div>' + delStatusBadgeHtml(dr.badge_color || dr.status, dr.status) + '</div>' +
                '</div></div></div>';

            html += '<div class="ws-section"><h4><span>6-Gate Verification Checklist</span><span>' +
                (data.all_checks_passed ? delStatusBadgeHtml('GREEN', 'ALL CHECKS PASSED') : delStatusBadgeHtml('YELLOW', 'VERIFICATION INCOMPLETE')) +
                '</span></h4><table><thead><tr><th>Check Requirement</th><th>Status</th></tr></thead><tbody>';
            checks.forEach(function(ck) {
                html += '<tr><td><strong>' + escapeHtml(ck.label) + '</strong></td><td>' + delStatusBadgeHtml(ck.status, ck.status) + '</td></tr>';
            });
            html += '</tbody></table></div>';

            html += '<div class="ws-section"><h4><span>Order Summary (' + Number(m.total_orders || 0) + ' orders)</span>' +
                '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">' +
                '<label style="margin:0;display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:600;cursor:pointer;"><input type="checkbox" id="wsApproveRuleB" style="width:auto;"> Approve Accepted Order Cancellations (Rule B)</label>' +
                '<button type="button" class="btn-sm" ' + (isTerminal ? 'disabled' : '') + ' onclick="runDelAction(' + Number(dr.id) + ', \'lock\', {})">Lock Account</button>' +
                '<button type="button" class="btn-sm" ' + (isTerminal ? 'disabled' : '') + ' onclick="runDelAction(' + Number(dr.id) + ', \'verify-orders\', { approve_accepted_cancellations: Boolean(document.getElementById(\'wsApproveRuleB\') && document.getElementById(\'wsApproveRuleB\').checked) })">Verify Orders</button>' +
                '</div></h4>' +
                '<div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:8px;margin-bottom:10px;">' +
                '<div class="metric-mini"><div class="k">Pending (A)</div><div class="v">' + Number(m.pending_orders || 0) + '</div></div>' +
                '<div class="metric-mini"><div class="k">Confirmed (B)</div><div class="v">' + Number(m.confirmed_orders || 0) + '</div></div>' +
                '<div class="metric-mini"><div class="k">Shipped (C)</div><div class="v">' + Number(m.shipped_orders || 0) + '</div></div>' +
                '<div class="metric-mini"><div class="k">Delivered (D)</div><div class="v">' + Number(m.delivered_orders || 0) + '</div></div>' +
                '<div class="metric-mini"><div class="k">Cancelled (E)</div><div class="v">' + Number(m.cancelled_orders || 0) + '</div></div>' +
                '</div>';
            if (orders.length > 0) {
                html += '<table><thead><tr><th>Order</th><th>Status</th><th>Payment</th><th>Seller Share</th><th>Gateway / Window</th></tr></thead><tbody>';
                orders.forEach(function(o) {
                    html += '<tr><td>#' + Number(o.id) + ' (' + escapeHtml(o.order_number || '') + ')</td>' +
                        '<td><strong>' + escapeHtml(o.effective_status) + '</strong></td>' +
                        '<td>' + escapeHtml(o.payment_method || '') + ' / ' + escapeHtml(o.payment_status || '') + '</td>' +
                        '<td>' + fmtCur(o.seller_gross || 0) + '</td>' +
                        '<td style="font-size:12px;">' + (o.is_gateway_settled ? 'Gateway Settled' : 'Unsettled/Pending') + (o.return_window_active ? ' &bull; Return Window Active' : '') + (o.open_disputes_count > 0 ? ' &bull; Open Disputes: ' + Number(o.open_disputes_count) : '') + '</td></tr>';
                });
                html += '</tbody></table>';
            }
            html += '</div>';

            html += '<div class="ws-section"><h4><span>Payment &amp; Automatic Refund Summary</span>' +
                '<button type="button" class="btn-sm" ' + (isTerminal ? 'disabled' : '') + ' onclick="runDelAction(' + Number(dr.id) + ', \'process-refunds\', {})">Process Eligible Refunds (Razorpay)</button></h4>' +
                '<div style="font-size:13px;color:#475569;margin-bottom:8px;">Successful Payments: <strong>' + fmtCur(m.total_successful_payments || 0) + '</strong> &bull; Pending Refunds: <strong>' + Number(m.pending_refunds_count || 0) + ' (' + fmtCur(m.pending_refunds_amount || 0) + ')</strong></div>';
            if (refunds.length === 0) {
                html += '<div style="font-size:12px;color:#94a3b8;">No refund records queued for this seller.</div>';
            } else {
                html += '<table><thead><tr><th>Refund ID</th><th>Order ID</th><th>Eligible / Refunded</th><th>Gateway Refund ID</th><th>Status</th><th>Gateway Note</th></tr></thead><tbody>';
                refunds.forEach(function(rf) {
                    html += '<tr><td>#' + Number(rf.id) + '</td><td>#' + Number(rf.order_id) + '</td><td>' + fmtCur(rf.eligible_refund_amount) + ' / ' + fmtCur(rf.refunded_amount) + '</td>' +
                        '<td><code>' + escapeHtml(rf.gateway_refund_id || '—') + '</code></td>' +
                        '<td>' + delStatusBadgeHtml(rf.badge_color || rf.status, rf.status) + '</td>' +
                        '<td style="font-size:11px;color:#b91c1c;">' + escapeHtml(rf.failure_reason || 'Confirmed') + '</td></tr>';
                });
                html += '</tbody></table>';
            }
            html += '</div>';

            html += '<div class="ws-section"><h4><span>Financial Settlement &amp; Disputes Summary</span>' +
                '<button type="button" class="btn-sm" ' + (isTerminal ? 'disabled' : '') + ' onclick="runDelAction(' + Number(dr.id) + ', \'verify-settlement\', {})">Verify Settlement</button></h4>' +
                '<div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px;margin-bottom:12px;">' +
                '<div class="metric-mini"><div class="k">Wallet Balance</div><div class="v">' + fmtCur(set.available_seller_balance) + '</div></div>' +
                '<div class="metric-mini"><div class="k">Pending Payouts</div><div class="v">' + Number(set.pending_payouts_count || 0) + ' (' + fmtCur(set.pending_payouts_amount) + ')</div></div>' +
                '<div class="metric-mini"><div class="k">Outstanding Commission</div><div class="v">' + fmtCur(set.outstanding_commissions) + '</div></div>' +
                '<div class="metric-mini"><div class="k">Gateway Settlement</div><div class="v" style="font-size:14px;">' + (set.gateway_settled_ok ? 'ALL SETTLED' : Number(set.unsettled_gateway_count || 0) + ' UNSETTLED') + '</div></div>' +
                '<div class="metric-mini"><div class="k">Pending Disputes</div><div class="v">' + Number(set.pending_disputes_count || 0) + '</div></div>' +
                '<div class="metric-mini"><div class="k">Total Obligations</div><div class="v">' + fmtCur(set.outstanding_financial_obligations) + '</div></div>' +
                '</div>' +
                '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:10px;background:#f8fafc;border-radius:8px;margin-bottom:10px;">' +
                '<label style="margin:0;display:inline-flex;align-items:center;gap:5px;font-size:12px;cursor:pointer;"><input type="checkbox" id="wsConfirmGatewaySettled" style="width:auto;" ' + (set.gateway_settled_ok ? 'checked' : '') + '> Confirm Gateway Capture Settled</label>' +
                '<input type="text" id="wsPayoutRef" placeholder="Payout Reference (e.g. UTR/NEFT)" style="max-width:220px;padding:6px 10px;font-size:12px;">' +
                '<button type="button" class="btn-approve-sm" ' + (isTerminal ? 'disabled' : '') + ' onclick="handleSettlePayoutClick(' + Number(dr.id) + ')">Settle Payout / Clearance</button>' +
                '</div>';
            if (disputes.length > 0) {
                html += '<table><thead><tr><th>Dispute #</th><th>Order ID</th><th>Type</th><th>Claim</th><th>Status</th><th>Action</th></tr></thead><tbody>';
                disputes.forEach(function(dp) {
                    var isOpen = ['OPEN', 'UNDER_REVIEW', 'ESCALATED'].indexOf(String(dp.status || '').toUpperCase()) !== -1;
                    html += '<tr><td>#' + Number(dp.id) + '</td><td>#' + Number(dp.order_id) + '</td><td>' + escapeHtml(dp.dispute_type) + '</td><td>' + fmtCur(dp.claim_amount) + '</td>' +
                        '<td>' + delStatusBadgeHtml(isOpen ? 'ORANGE' : 'GREEN', dp.status) + '</td>' +
                        '<td>' + (isOpen && !isTerminal
                            ? '<button type="button" class="btn-sm" onclick="runDelAction(' + Number(dr.id) + ', \'resolve-dispute\', { dispute_id: ' + Number(dp.id) + ', resolution: \'RESOLVED\', mark_delivered: true, close_return_window: true })">Mark Delivered &amp; Resolve</button>'
                            : 'Resolved') + '</td></tr>';
                });
                html += '</tbody></table>';
            }
            html += '</div>';

            html += '<div class="ws-section"><h4>Admin Decision &amp; Final Confirmation</h4>' +
                '<label style="margin-top:0;">Admin Remarks</label>' +
                '<textarea id="wsAdminRemarks" rows="2" placeholder="Enter audit remarks...">' + escapeHtml(dr.admin_remarks || '') + '</textarea>' +
                '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;align-items:center;">' +
                '<button type="button" class="btn-approve-sm" style="padding:10px 16px;font-size:13px;" ' + (!dr.approve_allowed ? 'disabled' : '') + ' onclick="runDelAction(' + Number(dr.id) + ', \'approve\', {})">✓ Approve Deletion</button>' +
                '<button type="button" class="btn-hold-sm" style="padding:10px 16px;font-size:13px;" ' + (isTerminal ? 'disabled' : '') + ' onclick="handleHoldClick(' + Number(dr.id) + ')">⏸ Put On Hold</button>' +
                '<button type="button" class="btn-reject-sm" style="padding:10px 16px;font-size:13px;" ' + (isTerminal ? 'disabled' : '') + ' onclick="handleRejectClick(' + Number(dr.id) + ')">✕ Reject Request</button>' +
                '<button type="button" id="wsFinalDeleteBtn" style="background:#7f1d1d;padding:10px 18px;font-size:13px;" ' + (!dr.final_delete_allowed ? 'disabled' : '') + ' onclick="toggleFinalDeleteConfirm()">🗑 Final Delete Seller</button>' +
                '</div>' +
                (!dr.final_delete_allowed && dr.status !== 'DELETED' ? '<div style="font-size:12px;color:#64748b;margin-top:8px;">Final Delete remains disabled until all 6 checks are GREEN (SUCCESS) and Admin Approval issues the confirmation token.</div>' : '') +
                '<div id="wsFinalConfirmBox" style="display:none;background:#fef2f2;border:2px solid #dc2626;border-radius:10px;padding:14px;margin-top:12px;">' +
                '<strong style="color:#991b1b;font-size:14px;">Second Explicit Confirmation Required</strong>' +
                '<p style="font-size:13px;color:#7f1d1d;margin:6px 0;">Type exact phrase <code>' + escapeHtml(dr.expected_confirmation_phrase || ('DELETE SELLER #' + dr.seller_id)) + '</code> below to soft-delete this seller, revoke tokens, and hide listings while preserving all orders and financial history.</p>' +
                '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">' +
                '<input type="text" id="wsConfirmPhraseInput" placeholder="' + escapeHtml(dr.expected_confirmation_phrase || '') + '" style="max-width:280px;">' +
                '<button type="button" class="btn-reject-sm" style="padding:10px 16px;" onclick="submitFinalDelete(' + Number(dr.id) + ')">Confirm Permanent Soft-Delete</button>' +
                '</div></div></div>';

            html += '<div class="ws-section"><h4>Admin Activity History (' + logs.length + ')</h4>';
            if (logs.length === 0) {
                html += '<div style="font-size:12px;color:#94a3b8;">No admin activity recorded yet.</div>';
            } else {
                logs.forEach(function(lg) {
                    html += '<div class="log-row"><div style="color:#64748b;font-size:12px;min-width:140px;">' + escapeHtml((lg.created_at || '').slice(0, 19).replace('T', ' ')) + '</div>' +
                        '<div style="flex:1;"><strong>' + escapeHtml(lg.action) + '</strong> (' + escapeHtml(lg.actor_role || 'admin') + ')<div style="color:#475569;font-size:12px;">' + escapeHtml(lg.remarks || '') + '</div></div>' +
                        '<div>' + delStatusBadgeHtml(lg.new_status || lg.new_stage || 'INFO', lg.new_status || lg.new_stage || '') + '</div></div>';
                });
            }
            html += '</div>';

            document.getElementById('delWsBody').innerHTML = html;
        }

        if (window.location.pathname.indexOf('seller-deletions') !== -1 || window.location.search.indexOf('seller-deletions') !== -1) {
            window.addEventListener('DOMContentLoaded', function() {
                var sec = document.getElementById('deletionRequestsSection');
                if (sec && sec.scrollIntoView) sec.scrollIntoView({ behavior: 'smooth' });
            });
        }
    </script>
</body>
</html>
