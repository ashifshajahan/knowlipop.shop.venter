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
require_once __DIR__ . '/../helpers/mailer.php';

$pdo = getDatabaseConnection();
ensureGstTables($pdo);
ensureProductPriceColumns($pdo);
ensureProductCategoriesTable($pdo);
ensureSellerDeletionSchemaPhp($pdo);
ensureNormalizedPhoneSchemaPhp($pdo);

$rawJwtToken = getBearerToken();
$adminJwtPayload = !empty($rawJwtToken) ? verifyJwtToken((string) $rawJwtToken, getJwtSecret()) : null;
$hasValidAdminJwt = false;
$verifiedAdminRow = null;

if (is_array($adminJwtPayload) && strtolower((string) ($adminJwtPayload['role'] ?? '')) === 'admin' && !empty($adminJwtPayload['id'])) {
    try {
        $admStmt = $pdo->prepare("SELECT id, name, email, role, token_version FROM users WHERE id = :id AND LOWER(role) = 'admin' LIMIT 1");
        $admStmt->execute([':id' => (int) $adminJwtPayload['id']]);
        $admRow = $admStmt->fetch(PDO::FETCH_ASSOC);
        if ($admRow) {
            $dbTv = max(1, (int) ($admRow['token_version'] ?? 1));
            $tokTv = isset($adminJwtPayload['tv']) ? max(1, (int) $adminJwtPayload['tv']) : 1;
            if ($tokTv === $dbTv) {
                $hasValidAdminJwt = true;
                $verifiedAdminRow = $admRow;
            }
        }
    } catch (Throwable $e) {
        $hasValidAdminJwt = true;
    }
}

$isJsonSubAction = isset($_GET['action']) && $_GET['action'] === 'get_gst_details';
if (empty($_SESSION['admin_logged_in'])) {
    if ($hasValidAdminJwt) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_id'] = (int) ($verifiedAdminRow['id'] ?? $adminJwtPayload['id'] ?? 1);
        $_SESSION['admin_name'] = $verifiedAdminRow['name'] ?? ($adminJwtPayload['name'] ?? 'Admin');
        $_SESSION['admin_email'] = $verifiedAdminRow['email'] ?? ($adminJwtPayload['email'] ?? ($_COOKIE['admin_email'] ?? 'admin@vendor.app'));
    } else {
        if ($isJsonSubAction) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Admin authentication required.']);
            exit;
        }
        header('Location: /admin/login');
        exit;
    }
}

if (empty($_COOKIE['admin_token']) && empty($_SERVER['HTTP_AUTHORIZATION']) && !headers_sent()) {
    $mintedAdminJwt = createToken(
        $pdo,
        (int) ($_SESSION['admin_id'] ?? 1),
        null,
        'admin-dashboard',
        $_SESSION['admin_email'] ?? 'admin@vendor.app',
        'admin',
        null
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
    if (!$doc) {
        http_response_code(404);
        echo 'Document not found in database.';
        exit;
    }
    $filePath = resolveGstFilePathPhp($doc);
    if (!$filePath) {
        http_response_code(404);
        $safeName = htmlspecialchars((string) ($doc['original_name'] ?: 'Document.pdf'), ENT_QUOTES, 'UTF-8');
        echo "<!doctype html><html><head><meta charset=\"utf-8\"><title>Document Unavailable</title>"
            . "<style>body{font-family:system-ui,sans-serif;background:#f8fafc;color:#1e293b;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:20px;}"
            . ".box{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:28px;max-width:520px;box-shadow:0 10px 25px rgba(0,0,0,0.06);}"
            . "h2{margin-top:0;color:#b91c1c;}p{color:#475569;line-height:1.6;}"
            . ".btn{display:inline-block;background:#4f46e5;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none;font-weight:700;margin-top:12px;}</style></head>"
            . "<body><div class=\"box\"><h2>📄 Document File Not Available</h2>"
            . "<p>The file <strong>{$safeName}</strong> (Document #" . (int) $doc['id'] . ") is missing from server storage or was uploaded to a temporary directory prior to persistent storage.</p>"
            . "<p>Please reject this verification with a request to re-upload the PDF certificate.</p>"
            . "<a href=\"/admin\" class=\"btn\">← Back to Admin Dashboard</a></div></body></html>";
        exit;
    }

    $mimeType = '';
    $fh = @fopen($filePath, 'rb');
    if ($fh) {
        $magic = fread($fh, 8);
        fclose($fh);
        if (is_string($magic)) {
            if (str_starts_with($magic, '%PDF-')) {
                $mimeType = 'application/pdf';
            } elseif (strlen($magic) >= 4 && $magic[0] === "\x89" && $magic[1] === 'P' && $magic[2] === 'N' && $magic[3] === 'G') {
                $mimeType = 'image/png';
            } elseif (strlen($magic) >= 3 && $magic[0] === "\xFF" && $magic[1] === "\xD8" && $magic[2] === "\xFF") {
                $mimeType = 'image/jpeg';
            }
        }
    }
    if ($mimeType === '') {
        $ext = strtolower(pathinfo((string) ($doc['original_name'] ?: ($doc['file_name'] ?? $filePath)), PATHINFO_EXTENSION));
        if ($ext === 'pdf') $mimeType = 'application/pdf';
        elseif ($ext === 'png') $mimeType = 'image/png';
        elseif ($ext === 'jpg' || $ext === 'jpeg') $mimeType = 'image/jpeg';
        elseif (!empty($doc['mime_type']) && $doc['mime_type'] !== 'application/octet-stream') $mimeType = (string) $doc['mime_type'];
        else $mimeType = 'application/pdf';
    }

    $fileName = (string) ($doc['original_name'] ?: basename($filePath));
    $safeAsciiName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileName) ?: 'document.pdf';
    $disposition = ($mode === 'download') ? 'attachment' : 'inline';
    if (ob_get_level() > 0 && (int) ob_get_length() > 0) ob_clean();
    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . filesize($filePath));
    header("Content-Disposition: {$disposition}; filename=\"{$safeAsciiName}\"; filename*=UTF-8''" . rawurlencode($fileName));
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Cache-Control: private, max-age=0, must-revalidate');
    readfile($filePath);
    exit;
}

// Handle JSON fetch of seller GST details & documents for review modal
if (isset($_GET['action']) && $_GET['action'] === 'get_gst_details') {
    $targetId = (int) ($_GET['seller_id'] ?? 0);
    if ($targetId <= 0) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Missing seller_id']);
        exit;
    }
    $uStmt = $pdo->prepare("SELECT id, name, seller_name, phone, email FROM users WHERE id = :id LIMIT 1");
    $uStmt->execute([':id' => $targetId]);
    $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);

    $gstStmt = $pdo->prepare("SELECT * FROM seller_gst_details WHERE seller_id = :id LIMIT 1");
    $gstStmt->execute([':id' => $targetId]);
    $gst = $gstStmt->fetch();

    $docsStmt = $pdo->prepare("SELECT * FROM seller_gst_documents WHERE seller_id = :id ORDER BY is_primary DESC, id DESC");
    $docsStmt->execute([':id' => $targetId]);
    $docs = array_map(function($d) {
        $fmt = formatGstDocument($d, true);
        $fmt['admin_preview_url'] = '/admin/dashboard.php?action=view_gst_doc&id=' . (int) $d['id'] . '&mode=preview';
        $fmt['admin_download_url'] = '/admin/dashboard.php?action=view_gst_doc&id=' . (int) $d['id'] . '&mode=download';
        $fmt['file_exists'] = (resolveGstFilePathPhp($d) !== null);
        return $fmt;
    }, $docsStmt->fetchAll());

    $logsStmt = $pdo->prepare("SELECT * FROM seller_gst_verification_logs WHERE seller_id = :id ORDER BY id DESC");
    $logsStmt->execute([':id' => $targetId]);
    $logs = $logsStmt->fetchAll();

    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'success',
        'seller' => $uRow ? [
            'id' => (int) $uRow['id'],
            'name' => $uRow['name'] ?? null,
            'seller_name' => $uRow['seller_name'] ?? null,
            'phone' => $uRow['phone'] ?? null,
            'email' => getPublicSellerEmailPhp($uRow['email'] ?? null),
        ] : null,
        'gst' => $gst ?: null,
        'documents' => $docs,
        'logs' => $logs
    ]);
    exit;
}

// Handle approve GST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'approve_gst') {
    $sellerId = (int) ($_POST['seller_id'] ?? 0);
    $notes = trim($_POST['notes'] ?? 'Approved by administrator');
    $adminId = (int) ($_SESSION['admin_id'] ?? 1);

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
        if (function_exists('sendGstStatusMailPhp')) {
            try {
                $uStmt = $pdo->prepare("SELECT email, seller_name, name FROM users WHERE id = :id LIMIT 1");
                $uStmt->execute([':id' => $sellerId]);
                $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
                if ($uRow && !empty($uRow['email'])) {
                    sendGstStatusMailPhp($pdo, (string) $uRow['email'], (string) ($uRow['seller_name'] ?? $uRow['name'] ?? 'Seller'), 'Approved', (string) ($gst['gstin'] ?? ''), $notes);
                }
            } catch (Throwable $ignoreMail) {}
        }
        $success = "Seller #{$sellerId} GST verification has been APPROVED successfully!";
    } else {
        $error = "No GST record found for Seller #{$sellerId}.";
    }
}

// Handle reject GST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reject_gst') {
    $sellerId = (int) ($_POST['seller_id'] ?? 0);
    $reason = trim($_POST['rejection_reason'] ?? '');
    $adminId = (int) ($_SESSION['admin_id'] ?? 1);

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
            if (function_exists('sendGstStatusMailPhp')) {
                try {
                    $uStmt = $pdo->prepare("SELECT email, seller_name, name FROM users WHERE id = :id LIMIT 1");
                    $uStmt->execute([':id' => $sellerId]);
                    $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
                    if ($uRow && !empty($uRow['email'])) {
                        sendGstStatusMailPhp($pdo, (string) $uRow['email'], (string) ($uRow['seller_name'] ?? $uRow['name'] ?? 'Seller'), 'Rejected', (string) ($gst['gstin'] ?? ''), $reason);
                    }
                } catch (Throwable $ignoreMail) {}
            }
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
    $dialCode = trim((string) ($_POST['country_code'] ?? $_POST['dial_code'] ?? ''));
    $phone = $rawPhone !== '' ? normalizePhoneNumberPhp($rawPhone, $dialCode) : '';
    $email = strtolower(trim($_POST['email'] ?? ''));
    $address = trim($_POST['address'] ?? '');
    $landmark = trim($_POST['landmark'] ?? '') ?: 'None';
    $state = trim($_POST['state'] ?? '');
    $country = trim($_POST['country'] ?? 'India');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['password_confirmation'] ?? '';
    $phoneErr = $rawPhone !== '' ? validatePhoneFormatPhp($phone, $rawPhone) : null;

    if (empty($name) || (empty($email) && empty($rawPhone)) || empty($address) || empty($state) || empty($password)) {
        $error = 'Seller name, email or phone number, address, state, and password are required.';
    } elseif ($email !== '' && (str_ends_with($email, '@phone.vendor.local') || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
        $error = 'The email address format is invalid.';
    } elseif ($phoneErr !== null) {
        $error = $phoneErr;
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Password confirmation does not match.';
    } else {
        $dupPhone = ($phone !== '') ? (bool) findUserByPhonePhp($pdo, $rawPhone, $phone, $dialCode) : false;
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
            $hasNormCol = authUsersHasColumnPhp($pdo, 'normalized_phone');

            try {
                if ($hasNormCol) {
                    $ins = $pdo->prepare("
                        INSERT INTO users (name, email, seller_name, phone, normalized_phone, address, landmark, state, country, password, role, created_at, updated_at)
                        VALUES (:name, :email, :seller_name, :phone, :normalized_phone, :address, :landmark, :state, :country, :password, 'seller', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                    ");
                    $ins->execute([
                        ':name' => $name,
                        ':email' => $dbEmail,
                        ':seller_name' => $name,
                        ':phone' => $dbPhone,
                        ':normalized_phone' => $dbPhone,
                        ':address' => $address,
                        ':landmark' => $landmark,
                        ':state' => $state,
                        ':country' => $country,
                        ':password' => $hashed,
                    ]);
                } else {
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
                }
                $success = 'Seller account created successfully!';
            } catch (PDOException $e) {
                if (isDuplicateKeyExceptionPhp($e)) {
                    $error = str_contains(strtolower($e->getMessage()), 'email')
                        ? 'A seller with this email address already exists.'
                        : 'A seller with this phone number already exists.';
                } else {
                    $error = 'Failed to create seller: ' . $e->getMessage();
                }
            }
        }
    }
}

// Handle edit seller
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_seller') {
    $sellerId = (int) ($_POST['seller_id'] ?? 0);
    $name = trim($_POST['seller_name'] ?? '');
    $rawPhone = trim($_POST['phone'] ?? '');
    $dialCode = trim((string) ($_POST['country_code'] ?? $_POST['dial_code'] ?? ''));
    $phone = $rawPhone !== '' ? normalizePhoneNumberPhp($rawPhone, $dialCode) : '';
    $email = strtolower(trim($_POST['email'] ?? ''));
    $address = trim($_POST['address'] ?? '');
    $landmark = trim($_POST['landmark'] ?? '');
    $state = trim($_POST['state'] ?? '');
    $country = trim($_POST['country'] ?? 'India');
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $phoneErr = $rawPhone !== '' ? validatePhoneFormatPhp($phone, $rawPhone) : null;

    if (empty($sellerId) || empty($name) || (empty($rawPhone) && empty($email)) || empty($address) || empty($state)) {
        $error = 'Seller ID, name, email or phone number, address, and state are required.';
    } elseif (!empty($email) && (str_ends_with($email, '@phone.vendor.local') || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
        $error = 'The email address format is invalid.';
    } elseif ($phoneErr !== null) {
        $error = $phoneErr;
    } else {
        $dupPhone = ($phone !== '') ? (bool) findUserByPhonePhp($pdo, $rawPhone, $phone, $dialCode, $sellerId) : false;
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
                $hasNormCol = authUsersHasColumnPhp($pdo, 'normalized_phone');
                $sql = "UPDATE users SET name = :name, seller_name = :seller_name, phone = :phone";
                $params = [
                    ':id' => $sellerId,
                    ':name' => $name,
                    ':seller_name' => $name,
                    ':phone' => $phone !== '' ? $phone : null,
                ];
                if ($hasNormCol) {
                    $sql .= ", normalized_phone = :normalized_phone";
                    $params[':normalized_phone'] = $phone !== '' ? $phone : null;
                }
                $sql .= ", address = :address, landmark = :landmark, state = :state, country = :country";
                $params[':address'] = $address;
                $params[':landmark'] = $landmark;
                $params[':state'] = $state;
                $params[':country'] = $country;
                if (!empty($email)) {
                    $sql .= ", email = :email";
                    $params[':email'] = $email;
                }
                if ($passwordToUpdate !== null) {
                    $sql .= ", password = :password";
                    $params[':password'] = $passwordToUpdate;
                }
                $sql .= ", updated_at = CURRENT_TIMESTAMP WHERE id = :id";
                try {
                    $upd = $pdo->prepare($sql);
                    $upd->execute($params);
                    $success = "Seller #{$sellerId} profile updated successfully!";
                } catch (PDOException $e) {
                    if (isDuplicateKeyExceptionPhp($e)) {
                        $error = str_contains(strtolower($e->getMessage()), 'email')
                            ? 'This email address is already registered to another account.'
                            : 'This phone number is already registered to another account.';
                    } else {
                        $error = 'Failed to update seller: ' . $e->getMessage();
                    }
                }
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

// Handle Admin Create Product
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_product') {
    $targetSellerId = (int) ($_POST['seller_id'] ?? 0);
    $prodName = trim((string) ($_POST['name'] ?? ''));
    $rawPrice = $_POST['price'] ?? '';
    $rawStock = $_POST['stock'] ?? '0';
    $prodDesc = trim((string) ($_POST['description'] ?? ''));
    $prodSku = trim((string) ($_POST['sku'] ?? ''));
    $prodBrand = trim((string) ($_POST['brand'] ?? '')) ?: 'Generic';
    $prodActive = isset($_POST['is_active']) ? 1 : 0;

    if ($targetSellerId <= 0 || $prodName === '' || $rawPrice === '' || !is_numeric($rawPrice) || (float) $rawPrice < 0) {
        $error = 'Valid seller, product name, and non-negative price are required.';
    } elseif (!is_numeric($rawStock) || (int) $rawStock < 0) {
        $error = 'Stock quantity must be zero or a positive integer.';
    } else {
        $sStmt = $pdo->prepare("SELECT id, role, COALESCE(account_status, 'ACTIVE') AS account_status FROM users WHERE id = :id LIMIT 1");
        $sStmt->execute([':id' => $targetSellerId]);
        $sRow = $sStmt->fetch(PDO::FETCH_ASSOC);
        $sAcc = strtoupper((string) ($sRow['account_status'] ?? 'ACTIVE'));
        if (!$sRow) {
            $error = "Seller #{$targetSellerId} was not found.";
        } elseif (in_array($sAcc, ['DELETED', 'LOCKED', 'DELETION_PENDING'], true)) {
            $error = "Cannot create products for seller #{$targetSellerId} because account status is {$sAcc}.";
        } else {
            $catRes = resolveProductCategoryPhp($pdo, $_POST, true);
            if (!empty($catRes['error'])) {
                $error = 'Selected category was not found.';
            } else {
                $urlText = trim((string) ($_POST['image_urls'] ?? ($_POST['images'] ?? '')));
                $imgInput = $urlText !== '' ? ['images' => $urlText] : [];
                $imgResult = saveUploadedProductImages($imgInput);
                if (!empty($imgResult['uploadErrors'])) {
                    $error = $imgResult['uploadErrors'][0];
                } else {
                    $finalImages = array_slice(dedupeImagesByFilename($imgResult['images'] ?? []), 0, 4);
                    $finalPrimaryUrl = $finalImages[0] ?? null;
                    $finalImagesJson = !empty($finalImages) ? json_encode($finalImages) : null;
                    $pricing = resolveProductPricing([
                        'price' => $rawPrice,
                        'mrp' => $_POST['mrp'] ?? null,
                    ]);
                    $insStmt = $pdo->prepare("
                        INSERT INTO products (seller_id, name, description, sku, category, category_id, brand, mrp, seller_price, commission, delivery_charge, gst, other_charges, price, stock, image_url, images, is_active, created_at, updated_at)
                        VALUES (:seller_id, :name, :description, :sku, :category, :category_id, :brand, :mrp, :seller_price, :commission, :delivery_charge, :gst, :other_charges, :price, :stock, :image_url, :images, :is_active, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                    ");
                    $insStmt->execute([
                        ':seller_id' => $targetSellerId,
                        ':name' => $prodName,
                        ':description' => $prodDesc !== '' ? $prodDesc : null,
                        ':sku' => $prodSku !== '' ? $prodSku : null,
                        ':category' => $catRes['category'],
                        ':category_id' => $catRes['category_id'],
                        ':brand' => $prodBrand,
                        ':mrp' => number_format((float) $pricing['mrp'], 2, '.', ''),
                        ':seller_price' => number_format((float) $pricing['seller_price'], 2, '.', ''),
                        ':commission' => number_format((float) $pricing['commission'], 2, '.', ''),
                        ':delivery_charge' => number_format((float) $pricing['delivery_charge'], 2, '.', ''),
                        ':gst' => number_format((float) $pricing['gst'], 2, '.', ''),
                        ':other_charges' => number_format((float) $pricing['other_charges'], 2, '.', ''),
                        ':price' => number_format((float) $pricing['price'], 2, '.', ''),
                        ':stock' => (int) $rawStock,
                        ':image_url' => $finalPrimaryUrl,
                        ':images' => $finalImagesJson,
                        ':is_active' => $prodActive,
                    ]);
                    $newProdId = (int) $pdo->lastInsertId();
                    if (!empty($finalImages)) {
                        syncProductImagesRecordPHP($pdo, $targetSellerId, $newProdId, $finalImages, $imgResult['filesInfo'] ?? []);
                    }
                    $success = "Product #{$newProdId} (\"{$prodName}\") created successfully!";
                }
            }
        }
    }
}

// Handle Admin Edit Product
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_product') {
    $prodId = (int) ($_POST['product_id'] ?? 0);
    $prodName = trim((string) ($_POST['name'] ?? ''));
    $rawPrice = $_POST['price'] ?? '';
    $rawStock = $_POST['stock'] ?? '0';
    $prodDesc = trim((string) ($_POST['description'] ?? ''));
    $prodSku = trim((string) ($_POST['sku'] ?? ''));
    $prodBrand = trim((string) ($_POST['brand'] ?? '')) ?: 'Generic';
    $prodActive = isset($_POST['is_active']) ? 1 : 0;

    $pStmt = $pdo->prepare("SELECT * FROM products WHERE id = :id LIMIT 1");
    $pStmt->execute([':id' => $prodId]);
    $existingProd = $pStmt->fetch(PDO::FETCH_ASSOC);

    if (!$existingProd) {
        $error = "Product #{$prodId} not found.";
    } elseif ($prodName === '' || $rawPrice === '' || !is_numeric($rawPrice) || (float) $rawPrice < 0) {
        $error = 'Product name and valid non-negative price are required.';
    } elseif (!is_numeric($rawStock) || (int) $rawStock < 0) {
        $error = 'Stock quantity must be zero or a positive integer.';
    } else {
        $targetSellerId = (int) ($_POST['seller_id'] ?? $existingProd['seller_id']);
        $sellerChanged = ($targetSellerId !== (int) $existingProd['seller_id']);
        $sStmt = $pdo->prepare("SELECT id, COALESCE(account_status, 'ACTIVE') AS account_status FROM users WHERE id = :id LIMIT 1");
        $sStmt->execute([':id' => $targetSellerId]);
        $sRow = $sStmt->fetch(PDO::FETCH_ASSOC);
        $sAcc = strtoupper((string) ($sRow['account_status'] ?? 'ACTIVE'));

        if (!$sRow) {
            $error = "Selected seller #{$targetSellerId} was not found.";
        } elseif ($sellerChanged && in_array($sAcc, ['DELETED', 'LOCKED', 'DELETION_PENDING'], true)) {
            $error = "Cannot reassign product to seller #{$targetSellerId} with account status {$sAcc}.";
        } else {
            $catRes = resolveProductCategoryPhp($pdo, $_POST, false);
            if (!empty($catRes['error'])) {
                $error = 'Selected category was not found.';
            } else {
                $oldImgs = [];
                if (!empty($existingProd['images'])) {
                    $dec = json_decode((string) $existingProd['images'], true);
                    if (is_array($dec)) $oldImgs = array_values(array_filter($dec, fn($u) => is_string($u) && trim($u) !== ''));
                } elseif (!empty($existingProd['image_url'])) {
                    $oldImgs = [(string) $existingProd['image_url']];
                }

                $clearImgs = !empty($_POST['clear_images']);
                $replaceImgs = !empty($_POST['replace_images']);
                $hasControl = !empty($_POST['existing_images_control']);
                $retainedRaw = (isset($_POST['retain_images']) && is_array($_POST['retain_images'])) ? array_values(array_filter($_POST['retain_images'], 'is_string')) : [];
                $newUrlsRaw = trim((string) ($_POST['image_urls'] ?? ''));

                if ($clearImgs) {
                    $imgResult = ['hasImages' => false, 'images' => [], 'filesInfo' => [], 'uploadErrors' => []];
                } else {
                    if ($replaceImgs) {
                        $candidateList = $newUrlsRaw !== '' ? [$newUrlsRaw] : [];
                    } elseif ($hasControl) {
                        $candidateList = array_merge($retainedRaw, $newUrlsRaw !== '' ? [$newUrlsRaw] : []);
                    } else {
                        $candidateList = array_merge($oldImgs, $newUrlsRaw !== '' ? [$newUrlsRaw] : []);
                    }

                    $imgResult = saveUploadedProductImages(!empty($candidateList) ? ['images' => $candidateList] : []);
                }
                if (!empty($imgResult['uploadErrors'])) {
                    $error = $imgResult['uploadErrors'][0];
                } else {
                    $finalImages = array_slice(dedupeImagesByFilename($imgResult['images'] ?? []), 0, 4);
                    if (!$clearImgs && !$replaceImgs && !$hasControl && empty($imgResult['hasImages'])) {
                        $finalImages = $oldImgs;
                    }
                    $finalPrimaryUrl = $finalImages[0] ?? null;
                    $finalImagesJson = !empty($finalImages) ? json_encode($finalImages) : null;

                    $pricing = resolveProductPricing([
                        'price' => $rawPrice,
                        'mrp' => $_POST['mrp'] ?? null,
                    ], $existingProd);

                    $updStmt = $pdo->prepare("
                        UPDATE products
                        SET seller_id = :seller_id,
                            name = :name,
                            description = :description,
                            sku = :sku,
                            category = :category,
                            category_id = :category_id,
                            brand = :brand,
                            mrp = :mrp,
                            seller_price = :seller_price,
                            commission = :commission,
                            delivery_charge = :delivery_charge,
                            price = :price,
                            stock = :stock,
                            image_url = :image_url,
                            images = :images,
                            is_active = :is_active,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE id = :id
                    ");
                    $updStmt->execute([
                        ':seller_id' => $targetSellerId,
                        ':name' => $prodName,
                        ':description' => $prodDesc !== '' ? $prodDesc : null,
                        ':sku' => $prodSku !== '' ? $prodSku : null,
                        ':category' => !empty($catRes['provided']) ? $catRes['category'] : ($existingProd['category'] ?: 'General'),
                        ':category_id' => !empty($catRes['provided']) ? $catRes['category_id'] : ($existingProd['category_id'] ?? null),
                        ':brand' => $prodBrand,
                        ':mrp' => number_format((float) $pricing['mrp'], 2, '.', ''),
                        ':seller_price' => number_format((float) $pricing['seller_price'], 2, '.', ''),
                        ':commission' => number_format((float) $pricing['commission'], 2, '.', ''),
                        ':delivery_charge' => number_format((float) $pricing['delivery_charge'], 2, '.', ''),
                        ':price' => number_format((float) $pricing['price'], 2, '.', ''),
                        ':stock' => (int) $rawStock,
                        ':image_url' => $finalPrimaryUrl,
                        ':images' => $finalImagesJson,
                        ':is_active' => $prodActive,
                        ':id' => $prodId,
                    ]);

                    // Sync & clean up removed image files safely
                    $keptNames = array_map('getImageBasename', $finalImages);
                    foreach ($oldImgs as $oldU) {
                        $oldBn = getImageBasename($oldU);
                        if ($oldBn !== '' && !in_array($oldBn, $keptNames, true)) {
                            try {
                                $pdo->prepare("DELETE FROM product_images WHERE product_id = :pid AND file_name = :fn")
                                    ->execute([':pid' => $prodId, ':fn' => $oldBn]);
                            } catch (Throwable $e) {}
                            safeDeleteLocalImageFilePHP($pdo, $oldBn, $prodId);
                        }
                    }
                    if (!empty($finalImages)) {
                        syncProductImagesRecordPHP($pdo, $targetSellerId, $prodId, $finalImages, $imgResult['filesInfo'] ?? []);
                    }

                    $success = "Product #{$prodId} (\"{$prodName}\") updated successfully!";
                }
            }
        }
    }
}

// Handle Admin Activate / Deactivate Product
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_product_status') {
    $prodId = (int) ($_POST['product_id'] ?? 0);
    $pStmt = $pdo->prepare("SELECT id, name, is_active FROM products WHERE id = :id LIMIT 1");
    $pStmt->execute([':id' => $prodId]);
    $prodRow = $pStmt->fetch(PDO::FETCH_ASSOC);
    if ($prodRow) {
        $nextActive = ((int) ($prodRow['is_active'] ?? 1) === 1) ? 0 : 1;
        $pdo->prepare("UPDATE products SET is_active = :st, updated_at = CURRENT_TIMESTAMP WHERE id = :id")
            ->execute([':st' => $nextActive, ':id' => $prodId]);
        $stateWord = $nextActive ? 'ACTIVATED' : 'DEACTIVATED';
        $success = "Product #{$prodId} (\"{$prodRow['name']}\") has been {$stateWord}.";
    } else {
        $error = "Product #{$prodId} not found.";
    }
}

// Handle Admin Order-Safe Delete / Archive Product
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_product') {
    $prodId = (int) ($_POST['product_id'] ?? 0);
    $pStmt = $pdo->prepare("SELECT id, name, image_url, images FROM products WHERE id = :id LIMIT 1");
    $pStmt->execute([':id' => $prodId]);
    $prodRow = $pStmt->fetch(PDO::FETCH_ASSOC);
    if (!$prodRow) {
        $error = "Product #{$prodId} not found.";
    } else {
        $orderRefCount = 0;
        try {
            $oiStmt = $pdo->prepare("SELECT COUNT(*) FROM order_items WHERE product_id = :id");
            $oiStmt->execute([':id' => $prodId]);
            $orderRefCount = (int) $oiStmt->fetchColumn();
        } catch (Throwable $e) {
            $orderRefCount = 0;
        }

        if ($orderRefCount > 0) {
            $pdo->prepare("UPDATE products SET is_active = 0, updated_at = CURRENT_TIMESTAMP WHERE id = :id")
                ->execute([':id' => $prodId]);
            $success = "Product #{$prodId} (\"{$prodRow['name']}\") has {$orderRefCount} historical order item(s), so it was archived (deactivated) instead of deleted to preserve order history.";
        } else {
            $imgsToDel = [];
            if (!empty($prodRow['images'])) {
                $dec = json_decode((string) $prodRow['images'], true);
                if (is_array($dec)) {
                    foreach ($dec as $u) {
                        if (is_string($u) && $u !== '') $imgsToDel[] = $u;
                    }
                }
            }
            if (!empty($prodRow['image_url'])) {
                $imgsToDel[] = (string) $prodRow['image_url'];
            }
            try {
                $piStmt = $pdo->prepare("SELECT file_name, image_url FROM product_images WHERE product_id = :id");
                $piStmt->execute([':id' => $prodId]);
                while ($piR = $piStmt->fetch(PDO::FETCH_ASSOC)) {
                    if (!empty($piR['file_name'])) $imgsToDel[] = (string) $piR['file_name'];
                    if (!empty($piR['image_url'])) $imgsToDel[] = (string) $piR['image_url'];
                }
            } catch (Throwable $e) {}
            $pdo->prepare("DELETE FROM products WHERE id = :id")->execute([':id' => $prodId]);
            try {
                $pdo->prepare("DELETE FROM product_images WHERE product_id = :id")->execute([':id' => $prodId]);
            } catch (Throwable $e) {}
            $deletedNames = [];
            foreach ($imgsToDel as $imgU) {
                $bn = is_string($imgU) ? getImageBasename($imgU) : '';
                if ($bn !== '' && !in_array($bn, $deletedNames, true)) {
                    $deletedNames[] = $bn;
                    safeDeleteLocalImageFilePHP($pdo, $bn, $prodId);
                }
            }
            $success = "Product #{$prodId} (\"{$prodRow['name']}\") deleted permanently.";
        }
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
        SELECT p.id, p.seller_id, p.name, p.description, p.sku, p.category, p.category_id, p.brand,
               p.mrp, p.seller_price, p.price, p.stock, p.image_url, p.images, p.is_active,
               p.created_at, p.updated_at,
               COALESCE(u.seller_name, u.name, 'Verified Seller') AS seller_name,
               COALESCE(u.account_status, 'ACTIVE') AS seller_account_status
        FROM products p
        LEFT JOIN users u ON p.seller_id = u.id
        ORDER BY p.id DESC
        LIMIT 1000
    ");
    $adminProducts = $adminProductsStmt ? $adminProductsStmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable $e) { $adminProducts = []; }

try {
    $eligibleSellersStmt = $pdo->query("
        SELECT id, COALESCE(seller_name, name, 'Verified Seller') AS display_name,
               phone, email, COALESCE(account_status, 'ACTIVE') AS account_status
        FROM users
        WHERE role = 'seller'
        ORDER BY id DESC
        LIMIT 1000
    ");
    $eligibleSellersForProducts = $eligibleSellersStmt ? $eligibleSellersStmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable $e) { $eligibleSellersForProducts = []; }

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
           (SELECT d.file_name FROM seller_gst_documents d WHERE d.seller_id = g.seller_id ORDER BY d.is_primary DESC, d.id DESC LIMIT 1) as latest_doc_file_name,
           (SELECT d.file_path FROM seller_gst_documents d WHERE d.seller_id = g.seller_id ORDER BY d.is_primary DESC, d.id DESC LIMIT 1) as latest_doc_file_path,
           (SELECT d.file_size FROM seller_gst_documents d WHERE d.seller_id = g.seller_id ORDER BY d.is_primary DESC, d.id DESC LIMIT 1) as latest_doc_size
    FROM seller_gst_details g
    JOIN users u ON g.seller_id = u.id
    ORDER BY (CASE WHEN g.status = 'Pending' OR g.status = 'Resubmitted' THEN 0 ELSE 1 END), g.updated_at DESC
    LIMIT 1000
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
    LIMIT 1000
");
$sellers = $sellersStmt ? $sellersStmt->fetchAll() : [];

$deletionData = deletionListRequestsPhp($pdo, []);
$delRequests = $deletionData['requests'] ?? [];
$delKpis = $deletionData['kpis'] ?? ['total' => 0, 'pending' => 0, 'processing' => 0, 'failed' => 0, 'on_hold' => 0, 'approved' => 0, 'deleted' => 0, 'rejected' => 0];

$totalListedProducts = count($adminProducts);
$activeListedProducts = 0;
$outOfStockProducts = 0;
foreach ($adminProducts as $apCountRow) {
    if ((int) ($apCountRow['is_active'] ?? 1) === 1) {
        $activeListedProducts++;
    }
    if ((int) ($apCountRow['stock'] ?? 0) <= 0) {
        $outOfStockProducts++;
    }
}
$inactiveListedProducts = max(0, $totalListedProducts - $activeListedProducts);

$allowedAdminTabs = ['overview', 'gst', 'sellers', 'create-seller', 'seller-deletions', 'products', 'categories', 'all'];
$reqPathOnly = strtolower((string) (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: ''));
$defaultPathTab = 'overview';
if (str_contains($reqPathOnly, 'seller-deletions')) {
    $defaultPathTab = 'seller-deletions';
} elseif ($reqPathOnly === '/admin/products') {
    $defaultPathTab = 'products';
} elseif ($reqPathOnly === '/admin/sellers') {
    $defaultPathTab = 'sellers';
} elseif ($reqPathOnly === '/admin/create-seller') {
    $defaultPathTab = 'create-seller';
} elseif ($reqPathOnly === '/admin/gst-verifications') {
    $defaultPathTab = 'gst';
} elseif ($reqPathOnly === '/admin/categories') {
    $defaultPathTab = 'categories';
}
$defaultTabByAction = [
    'approve_gst' => 'gst',
    'reject_gst' => 'gst',
    'add_category' => 'categories',
    'edit_category' => 'categories',
    'delete_category' => 'categories',
    'update_product_category' => 'products',
    'create_product' => 'products',
    'edit_product' => 'products',
    'toggle_product_status' => 'products',
    'delete_product' => 'products',
    'create_seller' => 'sellers',
    'edit_seller' => 'sellers',
];
$rawPostRetTab = strtolower(trim((string) ($_POST['return_tab'] ?? '')));
$rawQueryTab = strtolower(trim((string) ($_GET['tab'] ?? '')));
$postActionKey = trim((string) ($_POST['action'] ?? ''));
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($rawPostRetTab, $allowedAdminTabs, true)) {
    $activeTab = $rawPostRetTab;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($defaultTabByAction[$postActionKey])) {
    $activeTab = $defaultTabByAction[$postActionKey];
} elseif (in_array($rawQueryTab, $allowedAdminTabs, true)) {
    $activeTab = $rawQueryTab;
} else {
    $activeTab = $defaultPathTab;
}
$focusDeletionTab = ($activeTab === 'seller-deletions');
$panelVisiblePhp = static fn(string $panelTab): bool => ($activeTab === 'all' || $activeTab === $panelTab);
$tabMetaPhp = [
    'overview' => ['title' => 'Dashboard Overview', 'sub' => 'Structured Admin Header, Portal KPIs, Page-Wise Category Stepper & Footer Navigation.'],
    'gst' => ['title' => 'PDF Verifications', 'sub' => 'Page 1 — Inspect seller-uploaded PDF certificates inline, approve compliance, or reject with mandatory feedback.'],
    'sellers' => ['title' => 'Seller Accounts', 'sub' => 'Page 2 — Manage registered sellers, search & filter accounts, edit profiles, or trigger deletion audits.'],
    'create-seller' => ['title' => 'Create Seller Account', 'sub' => 'Page 3 — Register a new merchant account manually with normalized phone or email credentials.'],
    'seller-deletions' => ['title' => 'Seller Management → Seller Deletion Requests', 'sub' => 'Page 4 — Verify orders (Rules A–E), automated Razorpay refunds, settlements, and 6-gate deletion approvals.'],
    'products' => ['title' => 'Manage Products', 'sub' => 'Page 5 — Add products, upload gallery photos, edit pricing/stock, filter catalog, reassign categories, or archive safely.'],
    'categories' => ['title' => 'Product Categories', 'sub' => 'Page 6 — Create, edit, sort, and manage product categories with automatic catalog sync.'],
    'all' => ['title' => 'Complete Admin Portal (All Lists)', 'sub' => 'All management lists displayed together on a single page.'],
];
$curMetaPhp = $tabMetaPhp[$activeTab] ?? $tabMetaPhp['overview'];

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
    <title><?= htmlspecialchars($curMetaPhp['title']) ?> — Vendor Admin Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            color: #212529;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .admin-navbar {
            background-color: #1e293b;
        }
        #adminTopNav .nav-pill {
            color: rgba(255, 255, 255, 0.85);
            border-radius: 0.5rem;
            padding: 0.42rem 0.72rem;
            font-size: 0.8125rem;
            font-weight: 600;
            white-space: nowrap;
            transition: background-color 0.15s ease, color 0.15s ease;
        }
        #adminTopNav .nav-pill:hover {
            color: #fff;
            background-color: rgba(255, 255, 255, 0.12);
        }
        #adminTopNav .nav-pill.active {
            color: #fff;
            background-color: #0d6efd;
        }
        .admin-view-panel { display: none; }
        .admin-view-panel.is-visible { display: block; }
        .admin-view-panel + .admin-view-panel.is-visible { margin-top: 1.25rem; }

        #adminCategoryPager .cat-page-pill {
            font-size: 0.78rem;
            font-weight: 600;
            white-space: nowrap;
        }
        #adminCategoryPager .cat-page-pill.active {
            background-color: #0d6efd;
            color: #fff;
            border-color: #0d6efd;
        }

        .kpi-card-link {
            cursor: pointer;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }
        .kpi-card-link:hover {
            transform: translateY(-2px);
            box-shadow: 0 0.5rem 1rem rgba(15, 23, 42, 0.08) !important;
        }
        .dir-card {
            transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
        }
        .dir-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 0.5rem 1.25rem rgba(15, 23, 42, 0.08) !important;
        }

        /* Preserved Domain Status Badges (Bootstrap Pill Aesthetic + Test Class Hooks) */
        .del-badge {
            font-weight: 700;
            font-size: 0.6875rem;
            padding: 0.3rem 0.65rem;
            border-radius: 50rem;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .del-yellow { background: #fff3cd; color: #997404; border: 1px solid #ffe69c; }
        .del-blue { background: #cfe2ff; color: #084298; border: 1px solid #9ec5fe; }
        .del-green { background: #d1e7dd; color: #0f5132; border: 1px solid #a3cfbb; }
        .del-red { background: #f8d7da; color: #842029; border: 1px solid #f1aeb5; }
        .del-orange { background: #ffe5d0; color: #984c0c; border: 1px solid #fecba1; }

        .badge-pending { background: #fff3cd; color: #997404; border: 1px solid #ffe69c; font-weight: 700; font-size: 0.75rem; padding: 0.28rem 0.65rem; border-radius: 50rem; display: inline-flex; align-items: center; gap: 0.25rem; }
        .badge-approved { background: #d1e7dd; color: #0f5132; border: 1px solid #a3cfbb; font-weight: 700; font-size: 0.75rem; padding: 0.28rem 0.65rem; border-radius: 50rem; display: inline-flex; align-items: center; gap: 0.25rem; }
        .badge-rejected { background: #f8d7da; color: #842029; border: 1px solid #f1aeb5; font-weight: 700; font-size: 0.75rem; padding: 0.28rem 0.65rem; border-radius: 50rem; display: inline-flex; align-items: center; gap: 0.25rem; }
        .badge-prod { background: #e7f1ff; color: #0a58ca; border: 1px solid #cfe2ff; font-weight: 600; font-size: 0.75rem; padding: 0.25rem 0.6rem; border-radius: 50rem; display: inline-block; }
        .badge-pdf { background: #f8d7da; color: #b02a37; font-weight: 800; font-size: 0.6875rem; padding: 0.15rem 0.45rem; border-radius: 0.25rem; border: 1px solid #f1aeb5; }
        .badge-img { background: #cff4fc; color: #055160; font-weight: 800; font-size: 0.6875rem; padding: 0.15rem 0.45rem; border-radius: 0.25rem; border: 1px solid #9eeaf9; }

        .tabs-bar .tab-btn.active {
            background-color: #0d6efd !important;
            color: #fff !important;
            border-color: #0d6efd !important;
        }
        .prod-thumb { width: 44px; height: 44px; border-radius: 0.5rem; object-fit: cover; border: 1px solid #dee2e6; background: #f8f9fa; flex-shrink: 0; }
        .prod-thumb-empty { width: 44px; height: 44px; border-radius: 0.5rem; border: 1px dashed #ced4da; background: #f8f9fa; display: flex; align-items: center; justify-content: center; font-size: 1rem; color: #6c757d; flex-shrink: 0; }
        .existing-img-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(115px, 1fr)); gap: 0.625rem; margin-top: 0.375rem; }
        .existing-img-item { border: 1px solid #dee2e6; border-radius: 0.5rem; padding: 0.4rem; background: #f8f9fa; text-align: center; font-size: 0.75rem; }
        .existing-img-item img { width: 100%; height: 72px; object-fit: cover; border-radius: 0.375rem; display: block; margin-bottom: 0.25rem; }

        /* Bootstrap 5 Modal Overlay Compatibility Wrapper */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.65);
            backdrop-filter: blur(3px);
            z-index: 1055;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            overflow-y: auto;
        }
        .modal-overlay.active { display: flex; }
        .modal-overlay .modal-dialog {
            width: 100%;
            max-width: 780px;
            max-height: 92vh;
            margin: auto;
        }
        .modal-overlay .modal-dialog.modal-xl { max-width: 1060px; }
        .modal-overlay .modal-dialog.modal-sm-custom { max-width: 460px; }
        .modal-overlay .modal-content {
            max-height: 92vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .modal-overlay .modal-header {
            background: #fff;
            z-index: 5;
        }
        .modal-overlay .modal-body {
            overflow-y: auto;
        }

        .metric-mini { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 0.5rem; padding: 0.65rem 0.75rem; }
        .metric-mini .k { font-size: 0.6875rem; font-weight: 700; color: #6c757d; text-transform: uppercase; }
        .metric-mini .v { font-size: 1.05rem; font-weight: 700; color: #212529; margin-top: 0.15rem; }
        .ws-section { border: 1px solid #dee2e6; border-radius: 0.75rem; padding: 1rem; margin-bottom: 1rem; background: #fff; }
        .ws-section h4 { margin: 0 0 0.75rem; color: #212529; font-size: 0.95rem; font-weight: 700; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem; }
        .doc-card { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 0.625rem; padding: 0.875rem; margin-bottom: 0.75rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.75rem; }
        .log-row { padding: 0.6rem 0; border-bottom: 1px solid #edf2f7; font-size: 0.8125rem; display: flex; justify-content: space-between; gap: 0.75rem; }
        .reject-presets { display: flex; gap: 0.4rem; flex-wrap: wrap; margin: 0.5rem 0; }
        .admin-footer { background-color: #1e293b; color: #cbd5e1; margin-top: auto; }
        .footer-links a { color: #cbd5e1; text-decoration: none; font-size: 0.8125rem; font-weight: 600; }
        .footer-links a:hover, .footer-links a.active { color: #fff; text-decoration: underline; }
        .pagination-bar { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.75rem; margin-top: 1rem; padding-top: 0.875rem; border-top: 1px solid #dee2e6; font-size: 0.8125rem; color: #495057; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-xl navbar-dark admin-navbar sticky-top shadow-sm">
        <div class="container-fluid px-3 px-lg-4">
            <a href="/admin" class="navbar-brand fw-bold d-flex align-items-center gap-2" onclick="return switchAdminTab('overview', event);">
                <i class="bi bi-shop-window text-primary"></i>
                Vendor <span class="badge bg-primary-subtle text-primary-emphasis fw-semibold">Admin</span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#adminNavbarContent" aria-controls="adminNavbarContent" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="adminNavbarContent">
                <ul class="navbar-nav me-auto mb-2 mb-xl-0 gap-1" id="adminTopNav">
                    <li class="nav-item"><a href="/admin" data-tab="overview" class="nav-link nav-pill <?= $activeTab === 'overview' ? 'active' : '' ?>" onclick="return switchAdminTab('overview', event);">Overview</a></li>
                    <li class="nav-item"><a href="/admin/gst-verifications" data-tab="gst" class="nav-link nav-pill <?= $activeTab === 'gst' ? 'active' : '' ?>" onclick="return switchAdminTab('gst', event);">PDF Verifications<?= $pendingGstCount > 0 ? ' (' . (int) $pendingGstCount . ')' : '' ?></a></li>
                    <li class="nav-item"><a href="/admin/sellers" data-tab="sellers" class="nav-link nav-pill <?= $activeTab === 'sellers' ? 'active' : '' ?>" onclick="return switchAdminTab('sellers', event);">Seller Accounts (<?= (int) $sellerCount ?>)</a></li>
                    <li class="nav-item"><a href="/admin/create-seller" data-tab="create-seller" class="nav-link nav-pill <?= $activeTab === 'create-seller' ? 'active' : '' ?>" onclick="return switchAdminTab('create-seller', event);">+ Create Seller</a></li>
                    <li class="nav-item"><a href="/admin/seller-deletions" data-tab="seller-deletions" class="nav-link nav-pill <?= $activeTab === 'seller-deletions' ? 'active' : '' ?>" onclick="return switchAdminTab('seller-deletions', event);">Deletion Requests (<span id="navDelCount"><?= (int) ($delKpis['total'] ?? 0) ?></span>)</a></li>
                    <li class="nav-item"><a href="/admin/products" data-tab="products" class="nav-link nav-pill <?= $activeTab === 'products' ? 'active' : '' ?>" onclick="return switchAdminTab('products', event);">Manage Products (<?= (int) $totalListedProducts ?>)</a></li>
                    <li class="nav-item"><a href="/admin/categories" data-tab="categories" class="nav-link nav-pill <?= $activeTab === 'categories' ? 'active' : '' ?>" onclick="return switchAdminTab('categories', event);">Categories (<?= count($categoriesList) ?>)</a></li>
                    <li class="nav-item"><a href="/admin?tab=all" data-tab="all" class="nav-link nav-pill <?= $activeTab === 'all' ? 'active' : '' ?>" onclick="return switchAdminTab('all', event);">All Lists</a></li>
                </ul>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <button type="button" class="btn btn-outline-info btn-sm fw-semibold" onclick="openHostingerMailModal()">
                        <i class="bi bi-envelope-at"></i> Hostinger Mail
                    </button>
                    <button type="button" class="btn btn-success btn-sm fw-semibold" onclick="switchAdminTab('products'); openProductModal(null);">
                        <i class="bi bi-plus-lg"></i> Add Product
                    </button>
                    <span class="badge text-bg-secondary py-2 px-2">
                        <i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($_SESSION['admin_name'] ?? 'Admin') ?>
                    </span>
                    <a href="/admin/logout" class="btn btn-outline-light btn-sm">Sign out</a>
                </div>
            </div>
        </div>
    </nav>

    <main class="container-xl py-4 flex-grow-1">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3 mb-3">
            <div>
                <h1 id="adminPageTitle" class="h3 fw-bold mb-1"><?= htmlspecialchars($curMetaPhp['title']) ?></h1>
                <p id="adminPageSubtitle" class="text-body-secondary mb-0 small"><?= htmlspecialchars($curMetaPhp['sub']) ?></p>
            </div>
            <div class="d-flex gap-2 flex-wrap align-items-center">
                <span class="del-badge del-yellow">PENDING</span>
                <span class="del-badge del-blue">PROCESSING</span>
                <span class="del-badge del-green">SUCCESS</span>
                <span class="del-badge del-red">FAILED</span>
                <span class="del-badge del-orange">ON HOLD</span>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2" role="alert">
                <i class="bi bi-check-circle-fill"></i>
                <div><?= htmlspecialchars($success) ?></div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2" role="alert">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <div><?= htmlspecialchars($error) ?></div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Page-Wise Admin Category Stepper Bar -->
        <nav class="card border-0 shadow-sm mb-4" id="adminCategoryPager" aria-label="Admin Category Pages">
            <div class="card-body py-2 px-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="d-flex gap-1 flex-wrap align-items-center">
                    <a href="/admin" data-tab="overview" class="btn btn-sm btn-outline-secondary cat-page-pill <?= $activeTab === 'overview' ? 'active' : '' ?>" onclick="return switchAdminTab('overview', event);"><i class="bi bi-house-door me-1"></i>Overview</a>
                    <a href="/admin/gst-verifications" data-tab="gst" class="btn btn-sm btn-outline-secondary cat-page-pill <?= $activeTab === 'gst' ? 'active' : '' ?>" onclick="return switchAdminTab('gst', event);">1. Seller PDF Verification (<?= (int) $totalGstCount ?>)</a>
                    <a href="/admin/sellers" data-tab="sellers" class="btn btn-sm btn-outline-secondary cat-page-pill <?= $activeTab === 'sellers' ? 'active' : '' ?>" onclick="return switchAdminTab('sellers', event);">2. Seller Accounts (<?= (int) $sellerCount ?>)</a>
                    <a href="/admin/create-seller" data-tab="create-seller" class="btn btn-sm btn-outline-secondary cat-page-pill <?= $activeTab === 'create-seller' ? 'active' : '' ?>" onclick="return switchAdminTab('create-seller', event);">3. Create Seller</a>
                    <a href="/admin/seller-deletions" data-tab="seller-deletions" class="btn btn-sm btn-outline-secondary cat-page-pill <?= $activeTab === 'seller-deletions' ? 'active' : '' ?>" onclick="return switchAdminTab('seller-deletions', event);">4. Deletion Requests (<?= (int) ($delKpis['total'] ?? 0) ?>)</a>
                    <a href="/admin/products" data-tab="products" class="btn btn-sm btn-outline-secondary cat-page-pill <?= $activeTab === 'products' ? 'active' : '' ?>" onclick="return switchAdminTab('products', event);">5. Manage Products (<?= (int) $totalListedProducts ?>)</a>
                    <a href="/admin/categories" data-tab="categories" class="btn btn-sm btn-outline-secondary cat-page-pill <?= $activeTab === 'categories' ? 'active' : '' ?>" onclick="return switchAdminTab('categories', event);">6. Categories (<?= count($categoriesList) ?>)</a>
                    <a href="/admin?tab=all" data-tab="all" class="btn btn-sm btn-outline-secondary cat-page-pill <?= $activeTab === 'all' ? 'active' : '' ?>" onclick="return switchAdminTab('all', event);">All Pages</a>
                </div>
                <div class="d-flex gap-2 align-items-center flex-wrap">
                    <button type="button" id="catPrevBtn" class="btn btn-outline-primary btn-sm" onclick="stepAdminCategoryPage(-1)"><i class="bi bi-arrow-left"></i> Previous</button>
                    <span id="categoryPageIndicator" class="badge text-bg-light border text-dark fw-semibold px-2 py-2">Overview</span>
                    <button type="button" id="catNextBtn" class="btn btn-outline-primary btn-sm" onclick="stepAdminCategoryPage(1)">Next <i class="bi bi-arrow-right"></i></button>
                </div>
            </div>
        </nav>

        <!-- KPI Metrics Grid (shown cleanly on Overview & All Lists) -->
        <div class="admin-view-panel <?= ($activeTab === 'overview' || $activeTab === 'all') ? 'is-visible' : '' ?>" data-admin-panel="overview,all">
            <div class="row row-cols-2 row-cols-md-4 row-cols-xl-4 g-3 mb-4">
                <div class="col">
                    <div class="card h-100 border-0 shadow-sm border-start border-4 border-primary kpi-card-link" onclick="switchAdminTab('sellers')">
                        <div class="card-body">
                            <div class="text-body-secondary small fw-bold text-uppercase">Registered Sellers</div>
                            <div class="fs-2 fw-bold text-primary mt-1"><?= $sellerCount ?></div>
                        </div>
                    </div>
                </div>
                <div class="col">
                    <div class="card h-100 border-0 shadow-sm border-start border-4 border-info kpi-card-link" onclick="switchAdminTab('products')">
                        <div class="card-body">
                            <div class="text-body-secondary small fw-bold text-uppercase">Active Products</div>
                            <div class="fs-2 fw-bold text-info-emphasis mt-1"><?= $productCount ?></div>
                        </div>
                    </div>
                </div>
                <div class="col">
                    <div class="card h-100 border-0 shadow-sm border-start border-4 border-secondary kpi-card-link" onclick="switchAdminTab('categories')">
                        <div class="card-body">
                            <div class="text-body-secondary small fw-bold text-uppercase">Product Categories</div>
                            <div class="fs-2 fw-bold text-secondary-emphasis mt-1"><?= $categoryCount ?></div>
                        </div>
                    </div>
                </div>
                <div class="col">
                    <div class="card h-100 border-0 shadow-sm border-start border-4 border-dark">
                        <div class="card-body">
                            <div class="text-body-secondary small fw-bold text-uppercase">Marketplace Orders</div>
                            <div class="fs-2 fw-bold text-dark mt-1"><?= $orderCount ?></div>
                        </div>
                    </div>
                </div>
                <div class="col">
                    <div class="card h-100 border-0 shadow-sm border-start border-4 border-warning kpi-card-link <?= $pendingGstCount > 0 ? 'bg-warning-subtle' : '' ?>" onclick="switchAdminTab('gst')">
                        <div class="card-body">
                            <div class="text-warning-emphasis small fw-bold text-uppercase d-flex align-items-center justify-content-between gap-1">
                                <span>Pending PDF Reviews</span>
                                <?php if ($pendingGstCount > 0): ?>
                                    <span class="badge text-bg-warning">Action</span>
                                <?php endif; ?>
                            </div>
                            <div class="fs-2 fw-bold text-warning-emphasis mt-1"><?= $pendingGstCount ?></div>
                        </div>
                    </div>
                </div>
                <div class="col">
                    <div class="card h-100 border-0 shadow-sm border-start border-4 border-success kpi-card-link" onclick="switchAdminTab('gst')">
                        <div class="card-body">
                            <div class="text-success small fw-bold text-uppercase">Approved Sellers</div>
                            <div class="fs-2 fw-bold text-success mt-1"><?= $approvedGstCount ?></div>
                        </div>
                    </div>
                </div>
                <div class="col">
                    <div class="card h-100 border-0 shadow-sm border-start border-4 border-danger kpi-card-link" onclick="switchAdminTab('seller-deletions')">
                        <div class="card-body">
                            <div class="text-danger small fw-bold text-uppercase">Deletion Requests</div>
                            <div class="fs-2 fw-bold text-danger mt-1"><?= (int) ($delKpis['total'] ?? 0) ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Overview Directory Grid (clean page-wise category cards into each Admin page/list) -->
        <div class="admin-view-panel <?= $activeTab === 'overview' ? 'is-visible' : '' ?>" data-admin-panel="overview" id="overviewSection">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-4">
                    <h2 class="h5 fw-bold mb-1">Admin Categories — Page-Wise Navigation</h2>
                    <p class="text-body-secondary small mb-4">Select any category page from the stepper above, directory cards below, or footer. Every list supports search, status filters, and page-wise table pagination.</p>
                    <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-3">
                        <div class="col">
                            <div class="card h-100 border shadow-sm border-start border-4 border-warning dir-card">
                                <div class="card-body">
                                    <h3 class="h6 fw-bold mb-2"><i class="bi bi-file-earmark-pdf text-warning me-1"></i> Page 1: Seller PDF Document Verification</h3>
                                    <p class="text-body-secondary small mb-0">Inspect uploaded GST &amp; seller certificates inline, preview PDFs, approve compliance, view Details &amp; Logs, or reject with feedback.</p>
                                </div>
                                <div class="card-footer bg-transparent border-top d-flex justify-content-between align-items-center gap-2 flex-wrap">
                                    <span class="badge-pending"><?= (int) $pendingGstCount ?> pending &bull; <?= (int) $rejectedGstCount ?> rejected</span>
                                    <a href="/admin/gst-verifications" class="btn btn-warning btn-sm fw-semibold" onclick="return switchAdminTab('gst', event);">Open Page 1 →</a>
                                </div>
                            </div>
                        </div>
                        <div class="col">
                            <div class="card h-100 border shadow-sm border-start border-4 border-primary dir-card">
                                <div class="card-body">
                                    <h3 class="h6 fw-bold mb-2"><i class="bi bi-shop text-primary me-1"></i> Page 2: Seller Accounts</h3>
                                    <p class="text-body-secondary small mb-0">Search and paginate registered sellers, edit store profiles, inspect document links, or initiate Seller Deletion audits.</p>
                                </div>
                                <div class="card-footer bg-transparent border-top d-flex justify-content-between align-items-center gap-2 flex-wrap">
                                    <span class="badge-prod"><?= (int) $sellerCount ?> sellers</span>
                                    <a href="/admin/sellers" class="btn btn-primary btn-sm fw-semibold" onclick="return switchAdminTab('sellers', event);">Open Page 2 →</a>
                                </div>
                            </div>
                        </div>
                        <div class="col">
                            <div class="card h-100 border shadow-sm border-start border-4 border-info dir-card">
                                <div class="card-body">
                                    <h3 class="h6 fw-bold mb-2"><i class="bi bi-person-plus text-info me-1"></i> Page 3: Create Seller Account</h3>
                                    <p class="text-body-secondary small mb-0">Manually onboard a new merchant account with validated phone (+91 / international) or email and shop address details.</p>
                                </div>
                                <div class="card-footer bg-transparent border-top d-flex justify-content-end align-items-center">
                                    <a href="/admin/create-seller" class="btn btn-info btn-sm fw-semibold text-white" onclick="return switchAdminTab('create-seller', event);">Open Page 3 →</a>
                                </div>
                            </div>
                        </div>
                        <div class="col">
                            <div class="card h-100 border shadow-sm border-start border-4 border-danger dir-card">
                                <div class="card-body">
                                    <h3 class="h6 fw-bold mb-2"><i class="bi bi-trash3 text-danger me-1"></i> Page 4: Seller Deletion &amp; Refunds</h3>
                                    <p class="text-body-secondary small mb-0">Execute 6-gate order verification (Rules A–E), automated Razorpay refunds, settlement checks, holds, rejections, and final soft-deletion.</p>
                                </div>
                                <div class="card-footer bg-transparent border-top d-flex justify-content-between align-items-center gap-2 flex-wrap">
                                    <span class="badge-pending"><?= (int) (($delKpis['pending'] ?? 0) + ($delKpis['processing'] ?? 0)) ?> active &bull; <?= (int) ($delKpis['total'] ?? 0) ?> total</span>
                                    <a href="/admin/seller-deletions" class="btn btn-danger btn-sm fw-semibold" onclick="return switchAdminTab('seller-deletions', event);">Open Page 4 →</a>
                                </div>
                            </div>
                        </div>
                        <div class="col">
                            <div class="card h-100 border shadow-sm border-start border-4 border-success dir-card">
                                <div class="card-body">
                                    <h3 class="h6 fw-bold mb-2"><i class="bi bi-box-seam text-success me-1"></i> Page 5: Manage Products</h3>
                                    <p class="text-body-secondary small mb-0">Create products, upload up to 4 WebP photos, edit pricing/MRP/stock/SKU, filter by seller or category, toggle Active/Inactive, or archive safely.</p>
                                </div>
                                <div class="card-footer bg-transparent border-top d-flex justify-content-between align-items-center gap-2 flex-wrap">
                                    <span class="badge-prod"><?= (int) $totalListedProducts ?> total &bull; <?= (int) $activeListedProducts ?> active</span>
                                    <div class="d-flex gap-1 flex-wrap">
                                        <a href="/admin/products" class="btn btn-primary btn-sm fw-semibold" onclick="return switchAdminTab('products', event);">Open Page 5 →</a>
                                        <button type="button" class="btn btn-success btn-sm fw-semibold" onclick="switchAdminTab('products'); openProductModal(null);">+ Add</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col">
                            <div class="card h-100 border shadow-sm border-start border-4 border-secondary dir-card">
                                <div class="card-body">
                                    <h3 class="h6 fw-bold mb-2"><i class="bi bi-tags text-secondary me-1"></i> Page 6: Product Categories</h3>
                                    <p class="text-body-secondary small mb-0">Organize catalog taxonomy, icons, sort order, active status, and rename categories with automatic product syncing.</p>
                                </div>
                                <div class="card-footer bg-transparent border-top d-flex justify-content-between align-items-center gap-2 flex-wrap">
                                    <span class="badge-prod"><?= count($categoriesList) ?> categories</span>
                                    <a href="/admin/categories" class="btn btn-secondary btn-sm fw-semibold" onclick="return switchAdminTab('categories', event);">Open Page 6 →</a>
                                </div>
                            </div>
                        </div>
                        <div class="col">
                            <div class="card h-100 border shadow-sm border-start border-4 border-dark dir-card">
                                <div class="card-body">
                                    <h3 class="h6 fw-bold mb-2"><i class="bi bi-collection text-dark me-1"></i> Combined All-Lists Mode</h3>
                                    <p class="text-body-secondary small mb-0">View all paginated tables (PDF Verification, Sellers, Create Seller, Deletions, Categories, Products) stacked in one continuous screen.</p>
                                </div>
                                <div class="card-footer bg-transparent border-top d-flex justify-content-end align-items-center">
                                    <a href="/admin?tab=all" class="btn btn-dark btn-sm fw-semibold" onclick="return switchAdminTab('all', event);">Show All Sections</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Section 1: Seller PDF Document Verification Hub (Primary Function) -->
        <div class="admin-view-panel <?= $panelVisiblePhp('gst') ? 'is-visible' : '' ?>" data-admin-panel="gst">
        <section class="card border-0 shadow-sm border-top border-4 border-primary" id="gstSection">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <h2 class="h5 fw-bold mb-1"><i class="bi bi-file-earmark-pdf text-primary me-2"></i>Seller PDF Document Verification</h2>
                    <p class="text-body-secondary small mb-0">Inspect seller-uploaded PDF certificates with 1-click preview, approve for marketplace compliance, or reject with feedback.</p>
                </div>
                <div class="input-group input-group-sm" style="max-width: 310px;">
                    <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
                    <input type="text" class="form-control" id="gstSearchInput" onkeyup="filterGstSearch(this.value)" placeholder="Search seller, phone, or email...">
                </div>
            </div>
            <div class="card-body">
                <div class="tabs-bar nav nav-pills gap-2 pb-3 mb-3 border-bottom flex-wrap">
                    <button type="button" class="btn btn-sm btn-outline-secondary tab-btn active" onclick="filterGstRows('all', this)">All Documents (<?= $totalGstCount ?>)</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary tab-btn" onclick="filterGstRows('pending', this)">
                        Pending Review (<?= $pendingGstCount ?>)
                        <?php if ($pendingGstCount > 0): ?><span class="badge text-bg-warning ms-1">●</span><?php endif; ?>
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary tab-btn" onclick="filterGstRows('approved', this)">Approved (<?= $approvedGstCount ?>)</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary tab-btn" onclick="filterGstRows('rejected', this)">Rejected (<?= $rejectedGstCount ?>)</button>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
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
                                <tr><td colspan="5" class="text-body-secondary text-center py-4">No sellers have uploaded verification documents yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($gstSellers as $g): ?>
                                    <?php
                                        $st = strtolower($g['status']);
                                        if ($st === 'resubmitted') $st = 'pending';
                                        $badgeClass = ($st === 'approved') ? 'badge-approved' : (($st === 'rejected') ? 'badge-rejected' : 'badge-pending');
                                        $sellerLabel = $g['seller_name'] ?? $g['user_name'] ?? 'Seller #' . $g['seller_id'];
                                        $displayGstEmail = getPublicSellerEmailPhp($g['email'] ?? null);
                                        $searchString = strtolower($sellerLabel . ' ' . ($g['phone'] ?? '') . ' ' . ($displayGstEmail ?? ''));
                                        $docExistsPhp = !empty($g['latest_doc_id']) && (resolveGstFilePathPhp([
                                            'file_name' => $g['latest_doc_file_name'] ?? null,
                                            'file_path' => $g['latest_doc_file_path'] ?? null,
                                        ]) !== null);
                                    ?>
                                    <tr class="gst-row" data-status="<?= $st ?>" data-search="<?= htmlspecialchars($searchString) ?>">
                                        <td>
                                            <div class="fw-bold text-dark"><?= htmlspecialchars($sellerLabel) ?></div>
                                            <div class="small text-body-secondary mt-1">
                                                <span class="badge-prod me-1">ID #<?= (int)$g['seller_id'] ?></span>
                                                <?= htmlspecialchars($g['phone'] ?? '') ?>
                                            </div>
                                            <?php if (!empty($displayGstEmail)): ?>
                                                <div class="small text-muted"><?= htmlspecialchars($displayGstEmail) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($g['latest_doc_id'])): ?>
                                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                                    <span class="badge-pdf">PDF</span>
                                                    <span class="fw-semibold small text-dark"><?= htmlspecialchars($g['latest_doc_name'] ?: 'Certificate.pdf') ?></span>
                                                    <span class="text-body-secondary small">(<?= formatBytesPhp($g['latest_doc_size']) ?>)</span>
                                                    <?php if (!$docExistsPhp): ?>
                                                        <span class="badge text-bg-warning" title="File was uploaded to an earlier temporary build container">Re-upload needed</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="mt-2 d-flex gap-1 align-items-center flex-wrap">
                                                    <button type="button" class="btn btn-info btn-sm text-white py-1 px-2" data-seller-name="<?= htmlspecialchars($sellerLabel, ENT_QUOTES, 'UTF-8') ?>" onclick="openGstModal(<?= (int)$g['seller_id'] ?>, this.getAttribute('data-seller-name'), false, <?= (int)$g['latest_doc_id'] ?>)">
                                                        <i class="bi bi-file-earmark-text me-1"></i>Preview PDF
                                                    </button>
                                                    <a href="/admin/dashboard.php?action=view_gst_doc&id=<?= (int)$g['latest_doc_id'] ?>&mode=preview" target="_blank" class="btn btn-outline-secondary btn-sm py-1 px-2" title="Open in New Tab">
                                                        <i class="bi bi-box-arrow-up-right"></i> New Tab
                                                    </a>
                                                    <a href="/admin/dashboard.php?action=view_gst_doc&id=<?= (int)$g['latest_doc_id'] ?>&mode=download" class="btn btn-outline-dark btn-sm py-1 px-2" title="Download PDF">
                                                        <i class="bi bi-download"></i>
                                                    </a>
                                                    <?php if ((int)$g['doc_count'] > 1): ?>
                                                        <span class="small text-body-secondary fw-semibold ms-1">+<?= (int)$g['doc_count'] - 1 ?> more</span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-body-secondary fst-italic small">No document attached</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="<?= $badgeClass ?>">
                                                <?= ($st === 'pending') ? '⏳ ' : (($st === 'approved') ? '✓ ' : '✕ ') ?>
                                                <?= htmlspecialchars($g['status']) ?>
                                            </span>
                                            <?php if ($st === 'rejected' && !empty($g['rejection_reason'])): ?>
                                                <div class="small text-danger mt-1" style="max-width:220px;" title="<?= htmlspecialchars($g['rejection_reason']) ?>">
                                                    <strong>Reason:</strong> <?= htmlspecialchars(strlen($g['rejection_reason']) > 55 ? substr($g['rejection_reason'], 0, 52) . '...' : $g['rejection_reason']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="small text-dark"><?= htmlspecialchars(substr($g['submitted_at'] ?? $g['updated_at'] ?? '', 0, 10)) ?></div>
                                            <div class="small text-muted"><?= htmlspecialchars(substr($g['submitted_at'] ?? $g['updated_at'] ?? '', 11, 5)) ?></div>
                                        </td>
                                        <td>
                                            <div class="d-flex gap-1 flex-wrap align-items-center">
                                                <?php if ($st !== 'approved'): ?>
                                                    <form method="POST" action="/admin" class="d-inline" data-confirm-title="Approve GST Verification" data-confirm-msg="<?= htmlspecialchars('Approve GST verification for ' . $sellerLabel . '?', ENT_QUOTES, 'UTF-8') ?>" onsubmit="return openFormConfirmModal(this);">
                                                        <input type="hidden" name="action" value="approve_gst">
                                                        <input type="hidden" name="seller_id" value="<?= (int)$g['seller_id'] ?>">
                                                        <input type="hidden" name="notes" value="Approved by administrator">
                                                        <button type="submit" class="btn btn-success btn-sm fw-semibold" title="Instant Approve">✓ Approve</button>
                                                    </form>
                                                <?php endif; ?>

                                                <?php if ($st !== 'rejected'): ?>
                                                    <button type="button" class="btn btn-danger btn-sm fw-semibold" data-seller-name="<?= htmlspecialchars($sellerLabel, ENT_QUOTES, 'UTF-8') ?>" onclick="openGstModalWithReject(<?= (int)$g['seller_id'] ?>, this.getAttribute('data-seller-name'))" title="Reject with Reason">✕ Reject</button>
                                                <?php endif; ?>

                                                <button type="button" class="btn btn-primary btn-sm fw-semibold" data-seller-name="<?= htmlspecialchars($sellerLabel, ENT_QUOTES, 'UTF-8') ?>" onclick="openGstModal(<?= (int)$g['seller_id'] ?>, this.getAttribute('data-seller-name'), false)">Details &amp; Logs</button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div id="gstPaginationBar" class="pagination-bar"></div>
            </div>
        </section>
        </div>

        <!-- Section 2: Seller Accounts Overview with Search, Status Filter, Pagination & Deletion Controls -->
        <div class="admin-view-panel <?= $panelVisiblePhp('sellers') ? 'is-visible' : '' ?>" data-admin-panel="sellers">
        <section class="card border-0 shadow-sm border-top border-4 border-primary" id="sellersSection">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h2 class="h5 fw-bold mb-1"><i class="bi bi-shop text-primary me-2"></i>Seller Accounts</h2>
                    <p class="text-body-secondary small mb-0">All registered sellers on Venter App with inventory counts, verification status, search/filtering, and deletion controls.</p>
                </div>
                <a href="/admin/create-seller" class="btn btn-success btn-sm fw-semibold" onclick="return switchAdminTab('create-seller', event);"><i class="bi bi-plus-lg me-1"></i>Add New Seller</a>
            </div>
            <div class="card-body">
                <div class="row g-2 align-items-center bg-light p-3 rounded-3 border mb-3">
                    <div class="col-md-5">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                            <input type="text" class="form-control" id="sellersSearchInput" onkeyup="filterSellersSearch(this.value)" placeholder="Search seller name, phone, email, state, ID...">
                        </div>
                    </div>
                    <div class="col-md-4 col-lg-3">
                        <select class="form-select form-select-sm" id="sellersStatusFilter" onchange="applySellerFilters()">
                            <option value="all">All Account Statuses</option>
                            <option value="ACTIVE">ACTIVE</option>
                            <option value="DELETION_PENDING">DELETION_PENDING</option>
                            <option value="LOCKED">LOCKED</option>
                            <option value="DELETED">DELETED</option>
                        </select>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
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
                        <tbody id="sellersTableBody">
                            <?php if (empty($sellers)): ?>
                                <tr><td colspan="9" class="text-body-secondary text-center py-4">No sellers registered yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($sellers as $s): ?>
                                    <?php
                                        $dSt = strtolower($s['doc_status'] ?? '');
                                        if ($dSt === 'resubmitted') $dSt = 'pending';
                                        $docBadgeClass = ($dSt === 'approved') ? 'badge-approved' : (($dSt === 'rejected') ? 'badge-rejected' : (($dSt === 'pending') ? 'badge-pending' : ''));
                                        $accStatus = strtoupper((string) ($s['account_status'] ?? 'ACTIVE'));
                                        $accBadgeCls = $accStatus === 'ACTIVE' ? 'del-badge del-green' : ($accStatus === 'DELETION_PENDING' ? 'del-badge del-yellow' : ($accStatus === 'LOCKED' ? 'del-badge del-orange' : 'del-badge del-red'));
                                        $pubSellerEmail = getPublicSellerEmailPhp($s['email'] ?? null) ?? '';
                                        $sellerSearchStr = strtolower(($s['seller_name'] ?? $s['name'] ?? '') . ' ' . ($s['phone'] ?? '') . ' ' . $pubSellerEmail . ' ' . ($s['state'] ?? '') . ' #' . (int) $s['id']);
                                    ?>
                                    <tr id="seller-row-<?= (int) $s['id'] ?>" class="seller-account-row" data-status="<?= htmlspecialchars($accStatus, ENT_QUOTES, 'UTF-8') ?>" data-search="<?= htmlspecialchars($sellerSearchStr, ENT_QUOTES, 'UTF-8') ?>">
                                        <td class="fw-semibold">#<?= (int) $s['id'] ?></td>
                                        <td>
                                            <span class="fw-bold text-dark"><?= htmlspecialchars($s['seller_name'] ?? $s['name']) ?></span>
                                        </td>
                                        <td>
                                            <div class="small fw-medium"><?= htmlspecialchars($s['phone'] ?? '—') ?></div>
                                            <div class="small text-muted"><?= htmlspecialchars($pubSellerEmail) ?></div>
                                        </td>
                                        <td class="small"><?= htmlspecialchars($s['state'] ?? '—') ?></td>
                                        <td><span class="badge-prod"><?= (int)($s['products_count'] ?? 0) ?> products</span></td>
                                        <td>
                                            <?php if (!empty($docBadgeClass)): ?>
                                                <span class="<?= $docBadgeClass ?>"><?= htmlspecialchars($s['doc_status']) ?></span>
                                                <?php if (!empty($s['latest_doc_id'])): ?>
                                                    <a href="/admin/dashboard.php?action=view_gst_doc&id=<?= (int) $s['latest_doc_id'] ?>&mode=preview" target="_blank" class="btn btn-link btn-sm text-decoration-none p-0 ms-1 fw-semibold">📄 View</a>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-body-secondary small">Not Uploaded</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="seller-acc-cell"><span class="<?= $accBadgeCls ?>"><?= htmlspecialchars($accStatus) ?></span></td>
                                        <td class="small text-body-secondary"><?= htmlspecialchars(substr($s['created_at'] ?? '', 0, 10)) ?></td>
                                        <td>
                                            <div class="d-flex gap-1 flex-wrap align-items-center" data-seller-id="<?= (int) $s['id'] ?>">
                                                <button type="button" class="btn btn-primary btn-sm fw-semibold" data-seller="<?= htmlspecialchars(json_encode($s, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>" onclick="openSellerModal(this)">View / Edit</button>
                                                <span class="seller-del-btn-slot">
                                                    <?php if (!empty($s['active_deletion_request_id'])): ?>
                                                        <button type="button" class="btn btn-warning btn-sm fw-semibold" onclick="openDeletionWorkspaceModal(<?= (int) $s['active_deletion_request_id'] ?>)">Manage Deletion #<?= (int) $s['active_deletion_request_id'] ?></button>
                                                    <?php elseif ($accStatus !== 'DELETED'): ?>
                                                        <button type="button" class="btn btn-outline-danger btn-sm fw-semibold" onclick="openDeletionPreflightModal(<?= (int) $s['id'] ?>)">Request Seller Deletion</button>
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div id="sellersPaginationBar" class="pagination-bar"></div>
            </div>
        </section>
        </div>

        <!-- Section 2B: Seller Management → Seller Deletion Requests -->
        <div class="admin-view-panel <?= $panelVisiblePhp('seller-deletions') ? 'is-visible' : '' ?>" data-admin-panel="seller-deletions">
        <section class="card border-0 shadow-sm border-top border-4 border-danger" id="deletionRequestsSection">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <h2 class="h5 fw-bold mb-1"><i class="bi bi-trash3 text-danger me-2"></i>Seller Management → Seller Deletion Requests</h2>
                    <p class="text-body-secondary small mb-0">Track order verification (Rules A–E), automated Razorpay refunds, settlement checks, multi-stage Admin approvals, and audit logs.</p>
                </div>
                <div class="d-flex gap-2 align-items-center flex-wrap">
                    <div class="input-group input-group-sm" style="max-width: 260px;">
                        <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
                        <input type="text" class="form-control" id="deletionSearchInput" onkeyup="filterDeletionTableSearch(this.value)" placeholder="Search seller name, email, ID...">
                    </div>
                    <button type="button" class="btn btn-outline-primary btn-sm fw-semibold" onclick="reloadDeletionRequestsList()"><i class="bi bi-arrow-clockwise me-1"></i>Refresh</button>
                </div>
            </div>
            <div class="card-body">
                <div class="row row-cols-2 row-cols-md-3 row-cols-xl-6 g-2 mb-3">
                    <div class="col"><div class="metric-mini"><div class="k">Total Requests</div><div class="v" id="delKpiTotal"><?= (int) ($delKpis['total'] ?? 0) ?></div></div></div>
                    <div class="col"><div class="metric-mini bg-warning-subtle border-warning-subtle"><div class="k text-warning-emphasis">Pending (Yellow)</div><div class="v text-warning-emphasis" id="delKpiPending"><?= (int) ($delKpis['pending'] ?? 0) ?></div></div></div>
                    <div class="col"><div class="metric-mini bg-primary-subtle border-primary-subtle"><div class="k text-primary-emphasis">Processing (Blue)</div><div class="v text-primary-emphasis" id="delKpiProcessing"><?= (int) ($delKpis['processing'] ?? 0) ?></div></div></div>
                    <div class="col"><div class="metric-mini bg-danger-subtle border-danger-subtle"><div class="k text-danger-emphasis">Failed (Red)</div><div class="v text-danger-emphasis" id="delKpiFailed"><?= (int) ($delKpis['failed'] ?? 0) ?></div></div></div>
                    <div class="col"><div class="metric-mini" style="background:#ffe5d0; border-color:#fecba1;"><div class="k" style="color:#984c0c;">On Hold (Orange)</div><div class="v" style="color:#984c0c;" id="delKpiHold"><?= (int) ($delKpis['on_hold'] ?? 0) ?></div></div></div>
                    <div class="col"><div class="metric-mini bg-success-subtle border-success-subtle"><div class="k text-success-emphasis">Approved / Deleted</div><div class="v text-success-emphasis" id="delKpiApprovedDeleted"><?= (int) ($delKpis['approved'] ?? 0) + (int) ($delKpis['deleted'] ?? 0) ?></div></div></div>
                </div>

                <div class="tabs-bar nav nav-pills gap-2 pb-3 mb-3 border-bottom flex-wrap" id="delFilterTabs">
                    <button type="button" class="btn btn-sm btn-outline-secondary tab-btn active" onclick="filterDeletionStatus('all', this)">All</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary tab-btn" onclick="filterDeletionStatus('DELETION_REQUESTED', this)">Requested</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary tab-btn" onclick="filterDeletionStatus('REFUND_FAILED', this)">Refund Failed</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary tab-btn" onclick="filterDeletionStatus('SETTLEMENT_PENDING', this)">Settlement Pending</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary tab-btn" onclick="filterDeletionStatus('DELETION_ON_HOLD', this)">On Hold</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary tab-btn" onclick="filterDeletionStatus('APPROVED_FOR_DELETION', this)">Approved</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary tab-btn" onclick="filterDeletionStatus('DELETED', this)">Deleted</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary tab-btn" onclick="filterDeletionStatus('DELETION_REJECTED', this)">Rejected</button>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
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
                                <tr><td colspan="7" class="text-body-secondary text-center py-4">No seller deletion requests submitted yet. Use "Request Seller Deletion" in Seller Accounts above.</td></tr>
                            <?php else: ?>
                                <?php foreach ($delRequests as $dr): ?>
                                    <?php
                                        $snapMetrics = is_array($dr['metrics'] ?? null) ? $dr['metrics'] : [];
                                        $finSum = is_array($dr['financial_summary'] ?? null) ? $dr['financial_summary'] : [];
                                        $badgeCls = delBadgeClassPhp($dr['badge_color'] ?? $dr['status'] ?? 'PENDING');
                                        $searchTxt = strtolower(($dr['seller_name'] ?? '') . ' ' . ($dr['seller_email'] ?? '') . ' ' . ($dr['seller_phone'] ?? '') . ' ' . $dr['seller_id'] . ' ' . $dr['status'] . ' ' . $dr['stage']);
                                    ?>
                                    <tr class="del-req-row" data-status="<?= htmlspecialchars($dr['status']) ?>" data-search="<?= htmlspecialchars($searchTxt) ?>">
                                        <td class="fw-bold">#<?= (int) $dr['id'] ?></td>
                                        <td>
                                            <span class="fw-bold text-dark"><?= htmlspecialchars($dr['seller_name']) ?></span>
                                            <?php if (($dr['initiated_by_role'] ?? 'admin') === 'seller'): ?>
                                                <span class="del-badge del-yellow ms-1" style="font-size:10px; padding:2px 6px;">Seller Request</span>
                                            <?php endif; ?>
                                            <div class="small text-body-secondary">Seller ID #<?= (int) $dr['seller_id'] ?><?= !empty($dr['seller_phone']) ? ' &bull; ' . htmlspecialchars($dr['seller_phone']) : '' ?></div>
                                            <?php if (!empty($dr['seller_email'])): ?>
                                                <div class="small text-muted"><?= htmlspecialchars($dr['seller_email']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="small"><?= htmlspecialchars(substr((string) ($dr['created_at'] ?? ''), 0, 10)) ?></div>
                                            <div class="small text-muted"><?= htmlspecialchars(substr((string) ($dr['created_at'] ?? ''), 11, 8)) ?></div>
                                        </td>
                                        <td><code class="bg-light px-2 py-1 rounded border small"><?= htmlspecialchars($dr['stage']) ?></code></td>
                                        <td><span class="<?= $badgeCls ?>"><?= htmlspecialchars($dr['status']) ?></span></td>
                                        <td class="small text-body-secondary">
                                            Orders: <strong><?= (int) ($snapMetrics['total_orders'] ?? 0) ?></strong> &bull;
                                            Balance: <strong>₹<?= number_format((float) ($finSum['available_seller_balance'] ?? $snapMetrics['seller_wallet_balance'] ?? 0), 2) ?></strong>
                                        </td>
                                        <td>
                                            <button type="button" class="btn btn-primary btn-sm fw-semibold" onclick="openDeletionWorkspaceModal(<?= (int) $dr['id'] ?>)">Manage Deletion</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div id="delPaginationBar" class="pagination-bar"></div>
            </div>
        </section>
        </div>

        <!-- Section 3: Product Categories Management (5 Starter Categories + Editable) -->
        <div class="admin-view-panel <?= $panelVisiblePhp('categories') ? 'is-visible' : '' ?>" data-admin-panel="categories">
        <section class="card border-0 shadow-sm border-top border-4 border-secondary" id="categoriesSection">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <h2 class="h5 fw-bold mb-1"><i class="bi bi-tags text-secondary me-2"></i>Product Categories (Editable)</h2>
                    <p class="text-body-secondary small mb-0">Manage product categories (5 default starter categories pre-seeded). Renaming a category automatically syncs linked products.</p>
                </div>
                <div class="input-group input-group-sm" style="max-width: 280px;">
                    <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
                    <input type="text" class="form-control" id="categoriesSearchInput" onkeyup="filterCategorySearch(this.value)" placeholder="Search categories...">
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
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
                        <tbody id="categoriesTableBody">
                            <?php if (empty($categoriesList)): ?>
                                <tr><td colspan="8" class="text-body-secondary text-center py-4">No categories found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($categoriesList as $cat): ?>
                                    <?php $catSearchStr = strtolower(($cat['name'] ?? '') . ' ' . ($cat['slug'] ?? '') . ' ' . ($cat['description'] ?? '') . ' #' . (int) $cat['id']); ?>
                                    <tr class="cat-list-row" data-search="<?= htmlspecialchars($catSearchStr, ENT_QUOTES, 'UTF-8') ?>">
                                        <td class="fw-semibold">#<?= (int)$cat['id'] ?></td>
                                        <td>
                                            <span class="fs-6 me-1"><?= htmlspecialchars($cat['icon'] ?? '🏷️') ?></span>
                                            <span class="fw-bold"><?= htmlspecialchars($cat['name']) ?></span>
                                        </td>
                                        <td><code class="bg-light px-2 py-1 rounded border small"><?= htmlspecialchars($cat['slug']) ?></code></td>
                                        <td class="text-body-secondary small" style="max-width:260px;"><?= htmlspecialchars($cat['description'] ?? '—') ?></td>
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
                                            <div class="d-flex gap-1 align-items-center">
                                                <button type="button" class="btn btn-primary btn-sm fw-semibold" data-cat="<?= htmlspecialchars(json_encode($cat, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>" onclick="openCategoryModal(this)">Edit</button>
                                                <form method="POST" action="/admin" class="d-inline" data-confirm-title="Delete Category" data-confirm-msg="<?= htmlspecialchars('Delete category "' . $cat['name'] . '"? Products will be moved to General.', ENT_QUOTES, 'UTF-8') ?>" onsubmit="return openFormConfirmModal(this);">
                                                    <input type="hidden" name="action" value="delete_category">
                                                    <input type="hidden" name="category_id" value="<?= (int)$cat['id'] ?>">
                                                    <button type="submit" class="btn btn-danger btn-sm fw-semibold">Delete</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div id="categoriesPaginationBar" class="pagination-bar"></div>

                <div class="mt-4 pt-3 border-top">
                    <h3 class="h6 fw-bold mb-3"><i class="bi bi-plus-circle text-primary me-1"></i>Add New Category</h3>
                    <form method="POST" action="/admin">
                        <input type="hidden" name="action" value="add_category">
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label small fw-semibold">Category Name *</label>
                                <input class="form-control" name="name" required maxlength="100" placeholder="e.g. Sports & Fitness">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small fw-semibold">Icon (Emoji)</label>
                                <input class="form-control" name="icon" maxlength="20" value="🏷️" placeholder="e.g. ⚽">
                            </div>
                            <div class="col-md-5">
                                <label class="form-label small fw-semibold">Description</label>
                                <input class="form-control" name="description" maxlength="255" placeholder="Short description">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small fw-semibold">Sort Order</label>
                                <input class="form-control" type="number" name="sort_order" value="<?= count($categoriesList) + 1 ?>">
                            </div>
                        </div>
                        <div class="mt-3 d-flex align-items-center gap-3 flex-wrap">
                            <div class="form-check mb-0">
                                <input class="form-check-input" type="checkbox" name="is_active" id="addCatActiveChk" value="1" checked>
                                <label class="form-check-label small fw-semibold" for="addCatActiveChk">Active</label>
                            </div>
                            <button type="submit" class="btn btn-primary fw-semibold">+ Add Category</button>
                        </div>
                    </form>
                </div>
            </div>
        </section>
        </div>

        <!-- Section 4: Dedicated Admin Manage Products Page -->
        <div class="admin-view-panel <?= $panelVisiblePhp('products') ? 'is-visible' : '' ?>" data-admin-panel="products">
            <div class="row row-cols-2 row-cols-md-4 g-3 mb-3">
                <div class="col"><div class="metric-mini shadow-sm"><div class="k">Total Catalog Products</div><div class="v"><?= (int) $totalListedProducts ?></div></div></div>
                <div class="col"><div class="metric-mini bg-success-subtle border-success-subtle shadow-sm"><div class="k text-success-emphasis">Active on Storefront</div><div class="v text-success-emphasis"><?= (int) $activeListedProducts ?></div></div></div>
                <div class="col"><div class="metric-mini bg-danger-subtle border-danger-subtle shadow-sm"><div class="k text-danger-emphasis">Inactive / Archived</div><div class="v text-danger-emphasis"><?= (int) $inactiveListedProducts ?></div></div></div>
                <div class="col"><div class="metric-mini bg-warning-subtle border-warning-subtle shadow-sm"><div class="k text-warning-emphasis">Out of Stock</div><div class="v text-warning-emphasis"><?= (int) $outOfStockProducts ?></div></div></div>
            </div>
        <section class="card border-0 shadow-sm border-top border-4 border-success" id="productCategoriesSection">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-start flex-wrap gap-3">
                <div>
                    <h2 class="h5 fw-bold mb-1"><i class="bi bi-box-seam text-success me-2"></i>Products — Catalog &amp; Category Management</h2>
                    <p class="text-body-secondary small mb-0">Add new products, edit details/pricing/images, filter by seller/category/status, reassign categories, activate/deactivate, or delete/archive safely.</p>
                </div>
                <button type="button" class="btn btn-success btn-sm fw-semibold" onclick="openProductModal(null)"><i class="bi bi-plus-lg me-1"></i>Add New Product</button>
            </div>
            <div class="card-body">
                <div class="row g-2 align-items-center bg-light p-3 rounded-3 border mb-3">
                    <div class="col-md-4">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                            <input type="text" class="form-control" id="prodCatSearchInput" onkeyup="filterAdminProductSearch(this.value)" placeholder="Search name, SKU, brand, seller, ID...">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <select class="form-select form-select-sm" id="prodSellerFilter" onchange="applyAdminProductFilters()">
                            <option value="">All Sellers</option>
                            <?php foreach ($eligibleSellersForProducts as $sfOpt): ?>
                                <option value="<?= (int) $sfOpt['id'] ?>"><?= htmlspecialchars($sfOpt['display_name'] . ' (#' . (int) $sfOpt['id'] . ')') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select class="form-select form-select-sm" id="prodCategoryFilter" onchange="applyAdminProductFilters()">
                            <option value="">All Categories</option>
                            <?php foreach ($categoriesList as $cfOpt): ?>
                                <option value="<?= htmlspecialchars(strtolower((string) $cfOpt['name']), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($cfOpt['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select class="form-select form-select-sm" id="prodStatusFilter" onchange="applyAdminProductFilters()">
                            <option value="all">All Statuses</option>
                            <option value="1">Active Only</option>
                            <option value="0">Inactive Only</option>
                        </select>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Product</th>
                                <th>Seller</th>
                                <th>Pricing &amp; Stock</th>
                                <th>Status</th>
                                <th>Category &amp; Reassign</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="productsTableBody">
                            <?php if (empty($adminProducts)): ?>
                                <tr><td colspan="7" class="text-body-secondary text-center py-4">No products available yet. Use "+ Add New Product" above to add one.</td></tr>
                            <?php else: ?>
                                <?php foreach ($adminProducts as $ap): ?>
                                    <?php
                                        $formattedAp = formatProductRecord($ap, true);
                                        $sAccProd = strtoupper((string) ($ap['seller_account_status'] ?? 'ACTIVE'));
                                        $formattedAp['seller_account_status'] = $sAccProd;
                                        $isProdActive = !empty($formattedAp['is_active']);
                                        $thumbRaw = $formattedAp['images'][0] ?? '';
                                        $thumbSrc = ($thumbRaw !== '' && str_contains($thumbRaw, '/uploads/products/'))
                                            ? '/uploads/products/' . basename(explode('?', $thumbRaw)[0])
                                            : $thumbRaw;
                                        $pSearch = strtolower(
                                            ($formattedAp['name'] ?? '') . ' ' .
                                            ($formattedAp['sku'] ?? '') . ' ' .
                                            ($formattedAp['brand'] ?? '') . ' ' .
                                            ($formattedAp['category'] ?? '') . ' ' .
                                            ($formattedAp['seller_name'] ?? '') . ' #' . $formattedAp['id']
                                        );
                                    ?>
                                    <tr class="prod-cat-row"
                                        data-search="<?= htmlspecialchars($pSearch, ENT_QUOTES, 'UTF-8') ?>"
                                        data-seller-id="<?= (int) $ap['seller_id'] ?>"
                                        data-category="<?= htmlspecialchars(strtolower((string) ($formattedAp['category'] ?? 'General')), ENT_QUOTES, 'UTF-8') ?>"
                                        data-active="<?= $isProdActive ? '1' : '0' ?>">
                                        <td class="fw-bold">#<?= (int) $ap['id'] ?></td>
                                        <td>
                                            <div class="d-flex gap-2 align-items-center">
                                                <?php if (!empty($thumbSrc)): ?>
                                                    <img src="<?= htmlspecialchars($thumbSrc, ENT_QUOTES, 'UTF-8') ?>" alt="" class="prod-thumb" loading="lazy">
                                                <?php else: ?>
                                                    <div class="prod-thumb-empty" title="No Image">📦</div>
                                                <?php endif; ?>
                                                <div>
                                                    <div class="fw-bold text-dark"><?= htmlspecialchars($formattedAp['name']) ?></div>
                                                    <div class="small text-body-secondary">
                                                        SKU: <code><?= htmlspecialchars($formattedAp['sku']) ?></code> &bull; Brand: <?= htmlspecialchars($formattedAp['brand']) ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="fw-medium small"><?= htmlspecialchars($formattedAp['seller_name']) ?></div>
                                            <div class="small text-body-secondary">
                                                Seller #<?= (int) $ap['seller_id'] ?>
                                                <?php if ($sAccProd !== 'ACTIVE'): ?>
                                                    &bull; <span class="<?= delBadgeClassPhp($sAccProd) ?>" style="font-size:9px; padding:1px 5px;"><?= htmlspecialchars($sAccProd) ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="fw-bold">₹<?= number_format((float) $formattedAp['price'], 2) ?></div>
                                            <?php if ((float) $formattedAp['mrp'] > (float) $formattedAp['price']): ?>
                                                <div class="small text-muted text-decoration-line-through">MRP ₹<?= number_format((float) $formattedAp['mrp'], 2) ?></div>
                                            <?php endif; ?>
                                            <div class="small text-body-secondary">Stock: <strong><?= (int) $formattedAp['stock'] ?></strong></div>
                                        </td>
                                        <td>
                                            <?php if ($isProdActive): ?>
                                                <span class="badge-approved">Active</span>
                                            <?php else: ?>
                                                <span class="badge-rejected">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="mb-1"><span class="badge-prod"><?= htmlspecialchars($formattedAp['category'] ?: 'General') ?></span></div>
                                            <form method="POST" action="/admin" class="d-flex gap-1 align-items-center">
                                                <input type="hidden" name="action" value="update_product_category">
                                                <input type="hidden" name="product_id" value="<?= (int)$ap['id'] ?>">
                                                <select class="form-select form-select-sm" name="category_id" required style="max-width:155px;">
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
                                                <button type="submit" class="btn btn-primary btn-sm">Save</button>
                                            </form>
                                        </td>
                                        <td>
                                            <div class="d-flex gap-1 flex-wrap align-items-center">
                                                <button type="button" class="btn btn-primary btn-sm fw-semibold" data-product="<?= htmlspecialchars(json_encode($formattedAp, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>" onclick="openProductModal(this)">Edit</button>
                                                <form method="POST" action="/admin" class="d-inline">
                                                    <input type="hidden" name="action" value="toggle_product_status">
                                                    <input type="hidden" name="product_id" value="<?= (int) $ap['id'] ?>">
                                                    <button type="submit" class="btn btn-sm fw-semibold <?= $isProdActive ? 'btn-warning' : 'btn-success' ?>" title="<?= $isProdActive ? 'Hide from storefront' : 'Make visible on storefront' ?>">
                                                        <?= $isProdActive ? 'Deactivate' : 'Activate' ?>
                                                    </button>
                                                </form>
                                                <form method="POST" action="/admin" class="d-inline" data-confirm-title="Delete / Archive Product" data-confirm-msg="<?= htmlspecialchars('Delete product #' . (int)$ap['id'] . ' ("' . $formattedAp['name'] . '")? Products with existing orders are automatically archived (deactivated) instead of deleted to preserve order history.', ENT_QUOTES, 'UTF-8') ?>" onsubmit="return openFormConfirmModal(this);">
                                                    <input type="hidden" name="action" value="delete_product">
                                                    <input type="hidden" name="product_id" value="<?= (int) $ap['id'] ?>">
                                                    <button type="submit" class="btn btn-danger btn-sm fw-semibold">Delete</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div id="productsPaginationBar" class="pagination-bar"></div>
            </div>
        </section>
        </div>

        <!-- Section 5: Create Seller Account (Dedicated Page 3 or visible in All Lists) -->
        <div class="admin-view-panel <?= $panelVisiblePhp('create-seller') ? 'is-visible' : '' ?>" data-admin-panel="create-seller">
        <section class="card border-0 shadow-sm border-top border-4 border-info" id="createSellerSection">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h2 class="h5 fw-bold mb-1"><i class="bi bi-person-plus text-info me-2"></i>Create New Seller Account</h2>
                    <p class="text-body-secondary small mb-0">Add a new merchant to Venter App manually. Provide Email Address or Phone Number (with international country code, e.g. +919876543210).</p>
                </div>
                <a href="/admin/sellers" class="btn btn-outline-primary btn-sm fw-semibold" onclick="return switchAdminTab('sellers', event);">← Back to Seller Accounts</a>
            </div>
            <div class="card-body">
                <form method="POST" action="/admin" data-default-tab="sellers">
                    <input type="hidden" name="action" value="create_seller">
                    <div class="row g-3">
                        <div class="col-md-6 col-lg-3">
                            <label class="form-label small fw-semibold">Seller / Shop Name *</label>
                            <input class="form-control" name="seller_name" value="<?= htmlspecialchars($_POST['seller_name'] ?? '') ?>" required maxlength="120" placeholder="e.g. Apex Electronics">
                        </div>
                        <div class="col-md-6 col-lg-3">
                            <label class="form-label small fw-semibold">Phone (+country code)</label>
                            <input class="form-control" name="phone" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" placeholder="+919876543210">
                        </div>
                        <div class="col-md-6 col-lg-3">
                            <label class="form-label small fw-semibold">Email Address</label>
                            <input class="form-control" type="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" placeholder="seller@example.com">
                        </div>
                        <div class="col-md-6 col-lg-3">
                            <label class="form-label small fw-semibold">State *</label>
                            <input class="form-control" name="state" value="<?= htmlspecialchars($_POST['state'] ?? '') ?>" required placeholder="e.g. Karnataka">
                        </div>
                        <div class="col-md-6 col-lg-6">
                            <label class="form-label small fw-semibold">Address *</label>
                            <input class="form-control" name="address" value="<?= htmlspecialchars($_POST['address'] ?? '') ?>" required placeholder="Shop address">
                        </div>
                        <div class="col-md-6 col-lg-6">
                            <label class="form-label small fw-semibold">Landmark</label>
                            <input class="form-control" name="landmark" value="<?= htmlspecialchars($_POST['landmark'] ?? '') ?>" placeholder="Nearest landmark">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Password (min 8 characters) *</label>
                            <input class="form-control" type="password" name="password" required minlength="8" autocomplete="new-password">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Confirm Password *</label>
                            <input class="form-control" type="password" name="password_confirmation" required minlength="8" autocomplete="new-password">
                        </div>
                    </div>
                    <div class="mt-4">
                        <button type="submit" class="btn btn-primary fw-semibold px-4">+ Create Seller Account</button>
                    </div>
                </form>
            </div>
        </section>
        </div>
    </main>

    <footer class="admin-footer py-4 border-top border-secondary-subtle">
        <div class="container-xl d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
            <div>
                <div class="fw-bold text-white"><i class="bi bi-shop-window text-primary me-1"></i>Vendor Admin Portal &bull; Venter App Marketplace Operations</div>
                <div class="small text-secondary mt-1">Catalog, Sellers, GST Compliance, Automated Refunds &amp; Settlement Audit Suite</div>
            </div>
            <nav class="footer-links d-flex gap-3 flex-wrap align-items-center" id="adminFooterNav">
                <a href="/admin" data-tab="overview" class="<?= $activeTab === 'overview' ? 'active' : '' ?>" onclick="return switchAdminTab('overview', event);">Overview</a>
                <a href="/admin/gst-verifications" data-tab="gst" class="<?= $activeTab === 'gst' ? 'active' : '' ?>" onclick="return switchAdminTab('gst', event);">PDF Verifications</a>
                <a href="/admin/sellers" data-tab="sellers" class="<?= $activeTab === 'sellers' ? 'active' : '' ?>" onclick="return switchAdminTab('sellers', event);">Seller Accounts</a>
                <a href="/admin/create-seller" data-tab="create-seller" class="<?= $activeTab === 'create-seller' ? 'active' : '' ?>" onclick="return switchAdminTab('create-seller', event);">+ Create Seller</a>
                <a href="/admin/seller-deletions" data-tab="seller-deletions" class="<?= $activeTab === 'seller-deletions' ? 'active' : '' ?>" onclick="return switchAdminTab('seller-deletions', event);">Deletion Requests</a>
                <a href="/admin/products" data-tab="products" class="<?= $activeTab === 'products' ? 'active' : '' ?>" onclick="return switchAdminTab('products', event);">Manage Products</a>
                <a href="/admin/categories" data-tab="categories" class="<?= $activeTab === 'categories' ? 'active' : '' ?>" onclick="return switchAdminTab('categories', event);">Categories</a>
                <a href="/admin?tab=all" data-tab="all" class="<?= $activeTab === 'all' ? 'active' : '' ?>" onclick="return switchAdminTab('all', event);">All Lists</a>
            </nav>
            <div>
                <a href="/admin/logout" class="btn btn-outline-light btn-sm">Sign out</a>
            </div>
        </div>
    </footer>

    <!-- GST Review & Verification Modal -->
    <div id="gstReviewModal" class="modal-overlay" onclick="handleGstOverlayClick(event)">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <div class="modal-header border-bottom py-3 px-4">
                    <h3 class="modal-title h5 fw-bold mb-0" id="gstModalTitle">Document Verification Review</h3>
                    <button type="button" class="btn-close" aria-label="Close" onclick="closeGstModal()"></button>
                </div>
                <div class="modal-body p-4">
                    <div id="gstModalLoading" class="text-center py-4 text-body-secondary">
                        <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
                        Loading details &amp; documents...
                    </div>

                    <div id="gstModalContent" style="display:none;">
                        <div id="gstFetchErrorBox" class="alert alert-danger py-2 px-3 small mb-3" style="display:none;"></div>
                        <div class="card bg-light border mb-3">
                            <div class="card-body p-3">
                                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-1">
                                    <strong class="fs-6 text-dark" id="modalSellerName">—</strong>
                                    <div id="modalGstStatus">—</div>
                                </div>
                                <div id="modalSellerMeta" class="small text-body-secondary mb-2">—</div>
                                <div id="modalGstinRow" class="small text-dark mb-1" style="display:none;">
                                    <div><strong>GSTIN:</strong> <code id="modalGstin">—</code> &bull; <strong>Business:</strong> <span id="modalBusinessName">—</span></div>
                                    <div class="small text-body-secondary mt-1"><strong>Address:</strong> <span id="modalBusinessAddress">—</span> &bull; <strong>State &amp; PIN:</strong> <span id="modalStatePin">—</span></div>
                                </div>
                                <div class="small text-body-secondary">
                                    <strong>Submitted:</strong> <span id="modalSubmittedAt">—</span> &bull; <strong>Verified:</strong> <span id="modalVerifiedAt">—</span>
                                </div>

                                <div id="rejectionAlertBox" class="alert alert-danger mt-2 mb-0 py-2 px-3" style="display:none;">
                                    <strong class="small">Rejection Reason:</strong>
                                    <div id="modalRejectionReason" class="small mt-1">—</div>
                                </div>
                            </div>
                        </div>

                        <h4 class="h6 fw-bold mt-3 mb-2">Uploaded Documents (<span id="modalDocCount">0</span>)</h4>
                        <div id="modalDocumentsList" class="mb-3">
                            <!-- Documents rendered via JS -->
                        </div>

                        <!-- Embedded Live PDF / Document Viewer -->
                        <div id="modalInlinePreviewBox" class="card border mb-3 overflow-hidden" style="display:none;">
                            <div class="card-header bg-light py-2 px-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <strong class="small text-dark" id="modalPreviewLabel">Live Document Preview</strong>
                                <div class="d-flex gap-2 align-items-center">
                                    <a id="modalPreviewNewTab" href="#" target="_blank" class="btn btn-outline-primary btn-sm py-0 px-2">↗ Open Full Screen</a>
                                    <a id="modalPreviewDownload" href="#" class="btn btn-outline-secondary btn-sm py-0 px-2">⬇ Download</a>
                                    <button type="button" class="btn btn-secondary btn-sm py-0 px-2" onclick="closeInlineDocPreview()">Close Preview</button>
                                </div>
                            </div>
                            <div id="modalPreviewFrameWrap" class="bg-body-secondary d-flex align-items-center justify-content-center" style="width:100%; height:440px;"></div>
                            <div id="modalPreviewHint" class="small text-body-secondary px-3 py-2 bg-light border-top">If your browser blocks embedded PDF rendering, use <strong>Open Full Screen</strong> or <strong>Download</strong> above.</div>
                        </div>

                        <div class="border-top pt-3 mt-3">
                            <h4 class="h6 fw-bold mb-2">Admin Verification Actions</h4>
                            <div class="d-flex gap-2 flex-wrap align-items-start">
                                <form method="POST" action="/admin" data-confirm-title="Approve Document Verification" data-confirm-msg="Are you sure you want to APPROVE this seller document verification?" onsubmit="return openFormConfirmModal(this);">
                                    <input type="hidden" name="action" value="approve_gst">
                                    <input type="hidden" name="seller_id" id="approveSellerId">
                                    <input type="hidden" name="notes" value="Approved by administrator">
                                    <button type="submit" class="btn btn-success fw-semibold">✓ Approve Document</button>
                                </form>

                                <button type="button" class="btn btn-danger fw-semibold" onclick="toggleRejectBox()">✕ Reject Document...</button>
                            </div>

                            <!-- Reject Reason Form Box -->
                            <div id="rejectBox" class="alert alert-danger mt-3 mb-0 p-3" style="display:none;">
                                <form method="POST" action="/admin">
                                    <input type="hidden" name="action" value="reject_gst">
                                    <input type="hidden" name="seller_id" id="rejectSellerId">
                                    <label class="form-label small fw-bold text-danger-emphasis">Mandatory Rejection Reason <span class="text-danger">*</span></label>
                                    <div class="reject-presets mb-2">
                                        <button type="button" class="btn btn-outline-danger btn-sm rounded-pill reject-chip" onclick="setRejectPreset('Blurry or unreadable scan — please upload a clearer PDF.')">Blurry Scan</button>
                                        <button type="button" class="btn btn-outline-danger btn-sm rounded-pill reject-chip" onclick="setRejectPreset('Invalid or expired certificate — please upload a valid document.')">Invalid / Expired</button>
                                        <button type="button" class="btn btn-outline-danger btn-sm rounded-pill reject-chip" onclick="setRejectPreset('Business / Seller name mismatch with registration profile.')">Name Mismatch</button>
                                        <button type="button" class="btn btn-outline-danger btn-sm rounded-pill reject-chip" onclick="setRejectPreset('Uploaded file missing from storage — please re-upload PDF certificate.')">Request Re-upload</button>
                                    </div>
                                    <textarea class="form-control border-danger-subtle" name="rejection_reason" id="rejectReasonInput" required rows="3" placeholder="Explain clearly to the seller why their document was rejected (e.g. invalid document, blurry certificate, name mismatch)..."></textarea>
                                    <div class="d-flex gap-2 mt-2">
                                        <button type="submit" class="btn btn-danger btn-sm fw-semibold">Confirm Rejection</button>
                                        <button type="button" onclick="toggleRejectBox()" class="btn btn-outline-secondary btn-sm">Cancel</button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- Verification Audit Logs -->
                        <div class="border-top pt-3 mt-4">
                            <h4 class="h6 fw-bold mb-2">Verification Audit Trail</h4>
                            <div id="modalLogsList" class="border rounded-3 px-3 bg-light" style="max-height:160px; overflow-y:auto;">
                                <!-- Logs rendered via JS -->
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Category Modal -->
    <div id="editCategoryModal" class="modal-overlay" onclick="if(event.target.id==='editCategoryModal') closeCategoryModal();">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <div class="modal-header border-bottom py-3 px-4">
                    <h3 class="modal-title h5 fw-bold mb-0" id="catModalTitle">Edit Category</h3>
                    <button type="button" class="btn-close" aria-label="Close" onclick="closeCategoryModal()"></button>
                </div>
                <div class="modal-body p-4">
                    <form method="POST" action="/admin">
                        <input type="hidden" name="action" value="edit_category">
                        <input type="hidden" name="category_id" id="editCatId">
                        <div class="row g-3">
                            <div class="col-md-8">
                                <label class="form-label small fw-semibold">Category Name *</label>
                                <input class="form-control" name="name" id="editCatName" required maxlength="100">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Icon (Emoji)</label>
                                <input class="form-control" name="icon" id="editCatIcon" maxlength="20">
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-semibold">Description</label>
                                <input class="form-control" name="description" id="editCatDesc" maxlength="255">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Sort Order</label>
                                <input class="form-control" type="number" name="sort_order" id="editCatSort">
                            </div>
                            <div class="col-md-6 d-flex align-items-end pb-1">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="is_active" id="editCatActive" value="1">
                                    <label class="form-check-label small fw-semibold" for="editCatActive">Category Active</label>
                                </div>
                            </div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mt-4 pt-2 border-top">
                            <button type="button" onclick="closeCategoryModal()" class="btn btn-outline-secondary">Cancel</button>
                            <button type="submit" class="btn btn-primary fw-semibold">Save Category Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Seller Profile & Edit Modal -->
    <div id="sellerModal" class="modal-overlay" onclick="handleOverlayClick(event)">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <div class="modal-header border-bottom py-3 px-4">
                    <h3 class="modal-title h5 fw-bold mb-0" id="modalTitle">Seller Profile</h3>
                    <button type="button" class="btn-close" aria-label="Close" onclick="closeSellerModal()"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <div class="p-2 bg-light border rounded-3">
                                <div class="small text-body-secondary">Seller ID</div>
                                <strong class="text-dark" id="viewSellerId">—</strong>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="p-2 bg-light border rounded-3">
                                <div class="small text-body-secondary">Registered On</div>
                                <strong class="text-dark" id="viewCreatedAt">—</strong>
                            </div>
                        </div>
                    </div>

                    <form method="POST" action="/admin">
                        <input type="hidden" name="action" value="edit_seller">
                        <input type="hidden" name="seller_id" id="formSellerId">

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Seller / Shop Name *</label>
                                <input class="form-control" name="seller_name" id="formSellerName" required maxlength="120">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Phone (+country code)</label>
                                <input class="form-control" name="phone" id="formPhone" placeholder="+919876543210">
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-semibold">Email Address</label>
                                <input class="form-control" type="email" name="email" id="formEmail" placeholder="seller@example.com">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">State *</label>
                                <input class="form-control" name="state" id="formState" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Country *</label>
                                <input class="form-control" name="country" id="formCountry" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-semibold">Address *</label>
                                <input class="form-control" name="address" id="formAddress" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-semibold">Landmark</label>
                                <input class="form-control" name="landmark" id="formLandmark" placeholder="Nearest landmark (optional)">
                            </div>
                            <div class="col-12 mt-3 pt-2 border-top">
                                <strong class="small text-dark">Change Password</strong>
                                <span class="small text-body-secondary ms-1">(leave blank if not changing)</span>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Current Password</label>
                                <input class="form-control" type="password" name="current_password" id="formCurrentPassword" placeholder="Current password" autocomplete="current-password">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">New Password</label>
                                <input class="form-control" type="password" name="new_password" id="formNewPassword" minlength="8" placeholder="At least 8 characters" autocomplete="new-password">
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-semibold">Confirm Password</label>
                                <input class="form-control" type="password" name="confirm_password" id="formConfirmPassword" minlength="8" placeholder="Confirm new password" autocomplete="new-password">
                            </div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mt-4 pt-2 border-top">
                            <button type="button" onclick="closeSellerModal()" class="btn btn-outline-secondary">Cancel</button>
                            <button type="submit" class="btn btn-primary fw-semibold">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Seller Deletion Preflight Modal -->
    <div id="deletionPreflightModal" class="modal-overlay" onclick="if(event.target.id==='deletionPreflightModal') closeDeletionPreflightModal();">
        <div class="modal-dialog modal-dialog-centered modal-xl">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <div class="modal-header border-bottom py-3 px-4">
                    <h3 class="modal-title h5 fw-bold mb-0" id="delPreflightTitle">Request Seller Deletion — Preflight Audit</h3>
                    <button type="button" class="btn-close" aria-label="Close" onclick="closeDeletionPreflightModal()"></button>
                </div>
                <div class="modal-body p-4">
                    <div id="delPreflightLoading" class="text-center py-4 text-body-secondary">
                        <div class="spinner-border spinner-border-sm text-danger me-2" role="status"></div>
                        Computing orders, refunds, payouts &amp; settlement snapshot...
                    </div>
                    <div id="delPreflightBody" style="display:none;"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Seller Deletion Review Workspace Modal -->
    <div id="deletionWorkspaceModal" class="modal-overlay" onclick="if(event.target.id==='deletionWorkspaceModal') closeDeletionWorkspaceModal();">
        <div class="modal-dialog modal-dialog-centered modal-xl">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <div class="modal-header border-bottom py-3 px-4">
                    <h3 class="modal-title h5 fw-bold mb-0" id="delWsTitle">Seller Deletion Review Workspace</h3>
                    <button type="button" class="btn-close" aria-label="Close" onclick="closeDeletionWorkspaceModal()"></button>
                </div>
                <div class="modal-body p-4">
                    <div id="delWsLoading" class="text-center py-4 text-body-secondary">
                        <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
                        Loading seller deletion workspace...
                    </div>
                    <div id="delWsBody" style="display:none;"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Admin Product Add / Edit Modal -->
    <div id="productModal" class="modal-overlay" onclick="if(event.target.id==='productModal') closeProductModal();">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <div class="modal-header border-bottom py-3 px-4">
                    <h3 class="modal-title h5 fw-bold mb-0" id="prodModalTitle">Add New Product</h3>
                    <button type="button" class="btn-close" aria-label="Close" onclick="closeProductModal()"></button>
                </div>
                <div class="modal-body p-4">
                    <form method="POST" action="/admin" enctype="multipart/form-data">
                        <input type="hidden" name="action" id="prodFormAction" value="create_product">
                        <input type="hidden" name="product_id" id="prodFormId" value="">
                        <input type="hidden" name="existing_images_control" id="prodFormExistingControl" value="1">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label small fw-semibold">Product Name *</label>
                                <input class="form-control" name="name" id="prodFormName" required maxlength="200" placeholder="e.g. Wireless Noise-Cancelling Headphones">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Seller Account *</label>
                                <select class="form-select" name="seller_id" id="prodFormSeller" required>
                                    <option value="">-- Select Seller --</option>
                                    <?php foreach ($eligibleSellersForProducts as $spOpt): ?>
                                        <?php $spAcc = strtoupper((string) ($spOpt['account_status'] ?? 'ACTIVE')); ?>
                                        <option value="<?= (int) $spOpt['id'] ?>" data-acc-status="<?= htmlspecialchars($spAcc, ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars($spOpt['display_name'] . ' (#' . (int) $spOpt['id'] . ')' . ($spAcc !== 'ACTIVE' ? ' [' . $spAcc . ']' : '')) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Category *</label>
                                <select class="form-select" name="category_id" id="prodFormCategory" required>
                                    <?php foreach ($categoriesList as $catOpt): ?>
                                        <option value="<?= (int) $catOpt['id'] ?>"><?= htmlspecialchars($catOpt['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Selling Price (₹) *</label>
                                <input class="form-control" type="number" step="0.01" min="0" name="price" id="prodFormPrice" required placeholder="0.00">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">MRP (₹)</label>
                                <input class="form-control" type="number" step="0.01" min="0" name="mrp" id="prodFormMrp" placeholder="Optional (+30%)">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Stock Quantity *</label>
                                <input class="form-control" type="number" step="1" min="0" name="stock" id="prodFormStock" required value="0">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">SKU</label>
                                <input class="form-control" name="sku" id="prodFormSku" maxlength="100" placeholder="Auto-generated if blank">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Brand</label>
                                <input class="form-control" name="brand" id="prodFormBrand" maxlength="100" value="Generic" placeholder="Generic">
                            </div>
                            <div class="col-md-4 d-flex align-items-end pb-1">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="is_active" id="prodFormActive" value="1" checked>
                                    <label class="form-check-label small fw-semibold" for="prodFormActive">Active (Storefront)</label>
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-semibold">Description</label>
                                <textarea class="form-control" name="description" id="prodFormDesc" rows="3" placeholder="Product features, specifications, warranty details..."></textarea>
                            </div>
                            <div id="prodExistingImagesWrap" class="col-12" style="display:none;">
                                <label class="form-label small fw-semibold mb-1">Current Product Images (uncheck to remove)</label>
                                <div id="prodModalExistingImgs" class="existing-img-grid"></div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Upload Product Images (up to 4, auto-WebP)</label>
                                <input class="form-control" type="file" name="images[]" id="prodFormFiles" accept="image/*" multiple>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Add Image URLs (optional)</label>
                                <textarea class="form-control" name="image_urls" id="prodFormImageUrls" rows="2" placeholder="https://example.com/photo.jpg or /uploads/products/img.webp"></textarea>
                            </div>
                            <div id="prodEditImageOptions" class="col-12 bg-light border rounded-3 p-2 gap-3 flex-wrap align-items-center" style="display:none;">
                                <div class="form-check mb-0">
                                    <input class="form-check-input" type="checkbox" name="replace_images" id="prodFormReplaceImgs" value="1">
                                    <label class="form-check-label small" for="prodFormReplaceImgs">Replace all existing images with new uploads/URLs</label>
                                </div>
                                <div class="form-check mb-0">
                                    <input class="form-check-input" type="checkbox" name="clear_images" id="prodFormClearImgs" value="1">
                                    <label class="form-check-label small text-danger" for="prodFormClearImgs">Remove all images</label>
                                </div>
                            </div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mt-4 pt-2 border-top">
                            <button type="button" onclick="closeProductModal()" class="btn btn-outline-secondary">Cancel</button>
                            <button type="submit" id="prodFormSubmitBtn" class="btn btn-primary fw-semibold">Save Product</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Shared In-Page Form Confirmation Modal (replaces native confirm()) -->
    <div id="confirmActionModal" class="modal-overlay" style="z-index: 1100;" onclick="if(event.target.id==='confirmActionModal') closeConfirmModal();">
        <div class="modal-dialog modal-dialog-centered modal-sm-custom">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <div class="modal-header border-bottom py-3 px-4">
                    <h3 class="modal-title h5 fw-bold mb-0" id="confirmModalTitle">Confirm Action</h3>
                    <button type="button" class="btn-close" aria-label="Close" onclick="closeConfirmModal()"></button>
                </div>
                <div class="modal-body p-4">
                    <p id="confirmModalMessage" class="text-body-secondary mb-4">Are you sure?</p>
                    <div class="d-flex justify-content-end gap-2">
                        <button type="button" onclick="closeConfirmModal()" class="btn btn-outline-secondary">Cancel</button>
                        <button type="button" onclick="confirmPendingForm()" class="btn btn-danger fw-semibold">Confirm</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Hostinger Business Email (SMTP) Settings & Test Modal -->
    <div id="hostingerMailModal" class="modal-overlay" onclick="if(event.target.id==='hostingerMailModal') closeHostingerMailModal();">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <div class="modal-header border-bottom py-3 px-4">
                    <h3 class="modal-title h5 fw-bold mb-0"><i class="bi bi-envelope-at text-primary me-2"></i>Hostinger Mail SMTP Integration</h3>
                    <button type="button" class="btn-close" aria-label="Close" onclick="closeHostingerMailModal()"></button>
                </div>
                <div class="modal-body p-4">
                    <div id="hmStatusBanner" class="alert alert-secondary small py-2 mb-3">Loading Hostinger Mail status...</div>
                    <form onsubmit="saveHostingerMailSettings(event)">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">SMTP Host</label>
                                <input class="form-control form-control-sm" id="hmHost" value="smtp.hostinger.com" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small fw-semibold">Port</label>
                                <select class="form-select form-select-sm" id="hmPort" onchange="onHostingerPortChange()">
                                    <option value="465" selected>465 (SSL/TLS)</option>
                                    <option value="587">587 (STARTTLS)</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small fw-semibold">Encryption</label>
                                <input class="form-control form-control-sm" id="hmEncryption" value="ssl" readonly>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Hostinger Email (Username) *</label>
                                <input class="form-control form-control-sm" type="email" id="hmUsername" placeholder="contact@knowlipop.shop" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Mailbox Password</label>
                                <input class="form-control form-control-sm" type="password" id="hmPassword" placeholder="Enter Hostinger email password">
                                <div class="form-text small" id="hmPassHint">Leave blank to keep existing saved password.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">From Address</label>
                                <input class="form-control form-control-sm" type="email" id="hmFromAddress" placeholder="Defaults to Hostinger Email">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">From Name</label>
                                <input class="form-control form-control-sm" id="hmFromName" value="Knowlipop Vendor">
                            </div>
                        </div>
                        <div class="d-flex justify-content-end gap-2 mt-3">
                            <button type="submit" id="hmSaveBtn" class="btn btn-primary btn-sm fw-semibold"><i class="bi bi-save me-1"></i>Save Hostinger Mail Settings</button>
                        </div>
                    </form>
                    <hr class="my-3">
                    <div class="bg-light border rounded-3 p-3">
                        <label class="form-label small fw-bold mb-1">Send Live Test Email via Hostinger Mail</label>
                        <div class="input-group input-group-sm">
                            <input type="email" class="form-control" id="hmTestRecipient" placeholder="recipient@example.com">
                            <button type="button" id="hmTestBtn" class="btn btn-success fw-semibold" onclick="sendHostingerTestEmail()"><i class="bi bi-send me-1"></i>Send Test Email</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        window._pendingConfirmForm = null;
        window._activeGstStatus = 'all';
        window._activeGstSearch = '';
        window._activeSellerSearch = '';
        window._activeSellerStatus = 'all';
        window._activeCategorySearch = '';
        window._modalGstDocs = {};
        window._activeDelStatusFilter = 'all';
        window._activeDelSearch = '';
        window._currentWsData = null;
        window._tablePagerState = {};

        var ADMIN_CATEGORY_ORDER = ['overview', 'gst', 'sellers', 'create-seller', 'seller-deletions', 'products', 'categories', 'all'];
        var ADMIN_PAGE_LABELS = {
            overview: 'Overview Directory',
            gst: 'Page 1 of 6: PDF Verification',
            sellers: 'Page 2 of 6: Seller Accounts',
            'create-seller': 'Page 3 of 6: Create Seller',
            'seller-deletions': 'Page 4 of 6: Deletion Requests',
            products: 'Page 5 of 6: Manage Products',
            categories: 'Page 6 of 6: Categories',
            all: 'Combined All-Pages View'
        };

        var TABLE_PAGER_CFG = {
            gst: { tbodyId: 'gstTableBody', rowSelector: '.gst-row', barId: 'gstPaginationBar', defaultSize: 10, label: 'verification records' },
            sellers: { tbodyId: 'sellersTableBody', rowSelector: '.seller-account-row', barId: 'sellersPaginationBar', defaultSize: 10, label: 'sellers' },
            del: { tbodyId: 'deletionRequestsTbody', rowSelector: '.del-req-row', barId: 'delPaginationBar', defaultSize: 10, label: 'deletion requests' },
            products: { tbodyId: 'productsTableBody', rowSelector: '.prod-cat-row', barId: 'productsPaginationBar', defaultSize: 10, label: 'products' },
            categories: { tbodyId: 'categoriesTableBody', rowSelector: '.cat-list-row', barId: 'categoriesPaginationBar', defaultSize: 10, label: 'categories' }
        };

        var ADMIN_TAB_META = {
            overview: {
                url: '/admin',
                title: 'Dashboard Overview',
                sub: 'Structured Admin Header, Portal KPIs, Page-Wise Category Stepper & Footer Navigation.'
            },
            gst: {
                url: '/admin/gst-verifications',
                title: 'PDF Verifications',
                sub: 'Page 1 \u2014 Inspect seller-uploaded PDF certificates inline, approve compliance, or reject with mandatory feedback.'
            },
            sellers: {
                url: '/admin/sellers',
                title: 'Seller Accounts',
                sub: 'Page 2 \u2014 Manage registered sellers, search & filter accounts, edit profiles, or trigger deletion audits.'
            },
            'create-seller': {
                url: '/admin/create-seller',
                title: 'Create Seller Account',
                sub: 'Page 3 \u2014 Register a new merchant account manually with normalized phone or email credentials.'
            },
            'seller-deletions': {
                url: '/admin/seller-deletions',
                title: 'Seller Management \u2192 Seller Deletion Requests',
                sub: 'Page 4 \u2014 Verify orders (Rules A\u2013E), automated Razorpay refunds, settlements, and 6-gate deletion approvals.'
            },
            products: {
                url: '/admin/products',
                title: 'Manage Products',
                sub: 'Page 5 \u2014 Add products, upload gallery photos, edit pricing/stock, filter catalog, reassign categories, or archive safely.'
            },
            categories: {
                url: '/admin/categories',
                title: 'Product Categories',
                sub: 'Page 6 \u2014 Create, edit, sort, and manage product categories with automatic catalog sync.'
            },
            all: {
                url: '/admin?tab=all',
                title: 'Complete Admin Portal (All Lists)',
                sub: 'All management lists displayed together on a single page.'
            }
        };

        function detectInitialAdminTab() {
            var activePill = document.querySelector('#adminTopNav .nav-pill.active');
            if (activePill && activePill.getAttribute('data-tab')) {
                return activePill.getAttribute('data-tab');
            }
            var p = String(window.location.pathname || '').toLowerCase();
            var q = String(window.location.search || '').toLowerCase();
            if (q.indexOf('tab=all') !== -1) return 'all';
            if (p.indexOf('/admin/create-seller') !== -1 || q.indexOf('tab=create-seller') !== -1) return 'create-seller';
            if (p.indexOf('seller-deletions') !== -1 || q.indexOf('seller-deletions') !== -1) return 'seller-deletions';
            if (p.indexOf('/admin/products') !== -1 || q.indexOf('tab=products') !== -1) return 'products';
            if (p.indexOf('/admin/sellers') !== -1 || q.indexOf('tab=sellers') !== -1) return 'sellers';
            if (p.indexOf('/admin/gst-verifications') !== -1 || q.indexOf('tab=gst') !== -1) return 'gst';
            if (p.indexOf('/admin/categories') !== -1 || q.indexOf('tab=categories') !== -1) return 'categories';
            return 'overview';
        }

        window._activeAdminTab = detectInitialAdminTab();

        function getTableRows(key) {
            var cfg = TABLE_PAGER_CFG[key];
            if (!cfg) return [];
            var tbody = cfg.tbodyId ? document.getElementById(cfg.tbodyId) : null;
            var list = [];
            if (tbody && typeof tbody.querySelectorAll === 'function') {
                list = Array.prototype.slice.call(tbody.querySelectorAll(cfg.rowSelector) || []);
            }
            if (!list.length && typeof document.querySelectorAll === 'function') {
                list = Array.prototype.slice.call(document.querySelectorAll(cfg.rowSelector) || []);
            }
            return list;
        }

        function refreshTablePagination(key, resetPage) {
            var cfg = TABLE_PAGER_CFG[key];
            if (!cfg) return;
            if (!window._tablePagerState[key]) {
                window._tablePagerState[key] = { page: 1, pageSize: cfg.defaultSize || 10 };
            }
            var st = window._tablePagerState[key];
            if (resetPage) st.page = 1;

            var allRows = getTableRows(key);
            var matchedRows = [];
            allRows.forEach(function(r) {
                var flag = typeof r.getAttribute === 'function' ? r.getAttribute('data-filter-match') : null;
                if (flag !== '0') matchedRows.push(r);
            });

            var pageSize = Number(st.pageSize) || 10;
            var totalMatched = matchedRows.length;
            var totalPages = Math.max(1, Math.ceil(totalMatched / pageSize));
            if (st.page > totalPages) st.page = totalPages;
            if (st.page < 1) st.page = 1;

            var startIdx = (st.page - 1) * pageSize;
            var endIdx = startIdx + pageSize;

            allRows.forEach(function(r) {
                if (r && r.style) r.style.display = 'none';
            });
            matchedRows.forEach(function(r, idx) {
                if (r && r.style && idx >= startIdx && idx < endIdx) {
                    r.style.display = '';
                }
            });

            var barEl = cfg.barId ? document.getElementById(cfg.barId) : null;
            if (!barEl) return;

            var summaryText = '';
            if (allRows.length === 0) {
                summaryText = '0 ' + cfg.label;
            } else if (totalMatched === 0) {
                summaryText = '0 of ' + allRows.length + ' ' + cfg.label + ' match filter';
            } else {
                var fromNum = startIdx + 1;
                var toNum = Math.min(endIdx, totalMatched);
                summaryText = 'Showing ' + fromNum + '\u2013' + toNum + ' of ' + totalMatched + ' ' + cfg.label + ' (Page ' + st.page + ' of ' + totalPages + ')';
            }

            var sizeOpts = [10, 20, 50, 100];
            var optsHtml = '';
            sizeOpts.forEach(function(sz) {
                optsHtml += '<option value="' + sz + '"' + (pageSize === sz ? ' selected' : '') + '>' + sz + ' / page</option>';
            });

            var pagesHtml = '';
            var safeKey = escapeHtml(key);
            var winStart = Math.max(1, st.page - 2);
            var winEnd = Math.min(totalPages, st.page + 2);
            if (winStart > 1) {
                pagesHtml += '<li class="page-item"><button type="button" class="page-link pg-btn" data-pg-key="' + safeKey + '" data-pg-num="1" onclick="setTablePageFromBtn(this)">1</button></li>';
                if (winStart > 2) pagesHtml += '<li class="page-item disabled"><span class="page-link">...</span></li>';
            }
            for (var pNum = winStart; pNum <= winEnd; pNum++) {
                var isCurPg = (pNum === st.page);
                pagesHtml += '<li class="page-item' + (isCurPg ? ' active' : '') + '"><button type="button" class="page-link pg-btn' + (isCurPg ? ' active' : '') + '" data-pg-key="' + safeKey + '" data-pg-num="' + pNum + '" onclick="setTablePageFromBtn(this)">' + pNum + '</button></li>';
            }
            if (winEnd < totalPages) {
                if (winEnd < totalPages - 1) pagesHtml += '<li class="page-item disabled"><span class="page-link">...</span></li>';
                pagesHtml += '<li class="page-item"><button type="button" class="page-link pg-btn" data-pg-key="' + safeKey + '" data-pg-num="' + totalPages + '" onclick="setTablePageFromBtn(this)">' + totalPages + '</button></li>';
            }

            barEl.innerHTML = '<div class="small fw-semibold text-body-secondary">' + escapeHtml(summaryText) + '</div>' +
                '<div class="d-flex align-items-center gap-2 flex-wrap">' +
                '<select class="form-select form-select-sm w-auto pg-select" aria-label="Rows per page" data-pg-key="' + safeKey + '" onchange="setTablePageSizeFromEl(this)">' + optsHtml + '</select>' +
                '<nav aria-label="Table pagination"><ul class="pagination pagination-sm mb-0">' +
                '<li class="page-item' + (st.page <= 1 ? ' disabled' : '') + '"><button type="button" class="page-link pg-btn"' + (st.page <= 1 ? ' disabled' : '') + ' data-pg-key="' + safeKey + '" data-pg-num="' + (st.page - 1) + '" onclick="setTablePageFromBtn(this)">Prev</button></li>' +
                pagesHtml +
                '<li class="page-item' + (st.page >= totalPages ? ' disabled' : '') + '"><button type="button" class="page-link pg-btn"' + (st.page >= totalPages ? ' disabled' : '') + ' data-pg-key="' + safeKey + '" data-pg-num="' + (st.page + 1) + '" onclick="setTablePageFromBtn(this)">Next</button></li>' +
                '</ul></nav>' +
                '</div>';
        }

        function setTablePageFromBtn(btn) {
            if (!btn || typeof btn.getAttribute !== 'function') return;
            setTablePage(btn.getAttribute('data-pg-key'), btn.getAttribute('data-pg-num'));
        }

        function setTablePageSizeFromEl(sel) {
            if (!sel || typeof sel.getAttribute !== 'function') return;
            setTablePageSize(sel.getAttribute('data-pg-key'), sel.value);
        }

        function setTablePage(key, pageNum) {
            var cfg = TABLE_PAGER_CFG[key];
            if (!cfg) return;
            if (!window._tablePagerState[key]) {
                window._tablePagerState[key] = { page: 1, pageSize: cfg.defaultSize || 10 };
            }
            window._tablePagerState[key].page = Math.max(1, parseInt(pageNum, 10) || 1);
            refreshTablePagination(key, false);
        }

        function setTablePageSize(key, sizeVal) {
            var cfg = TABLE_PAGER_CFG[key];
            if (!cfg) return;
            if (!window._tablePagerState[key]) {
                window._tablePagerState[key] = { page: 1, pageSize: cfg.defaultSize || 10 };
            }
            var parsed = parseInt(sizeVal, 10);
            window._tablePagerState[key].pageSize = (parsed > 0) ? parsed : (cfg.defaultSize || 10);
            window._tablePagerState[key].page = 1;
            refreshTablePagination(key, false);
        }

        function refreshAllTablePaginations() {
            Object.keys(TABLE_PAGER_CFG).forEach(function(k) {
                refreshTablePagination(k, false);
            });
        }

        function updateCategoryPagerUI(tab) {
            var idx = ADMIN_CATEGORY_ORDER.indexOf(tab);
            if (idx === -1) idx = 0;
            var indEl = document.getElementById('categoryPageIndicator');
            if (indEl) indEl.innerText = ADMIN_PAGE_LABELS[tab] || ADMIN_PAGE_LABELS.overview;
            var prevBtn = document.getElementById('catPrevBtn');
            if (prevBtn) prevBtn.disabled = (idx <= 0);
            var nextBtn = document.getElementById('catNextBtn');
            if (nextBtn) nextBtn.disabled = (idx >= ADMIN_CATEGORY_ORDER.length - 1);
        }

        function stepAdminCategoryPage(delta) {
            var cur = window._activeAdminTab || 'overview';
            var idx = ADMIN_CATEGORY_ORDER.indexOf(cur);
            if (idx === -1) idx = 0;
            var nextIdx = idx + (Number(delta) || 0);
            if (nextIdx < 0) nextIdx = 0;
            if (nextIdx >= ADMIN_CATEGORY_ORDER.length) nextIdx = ADMIN_CATEGORY_ORDER.length - 1;
            return switchAdminTab(ADMIN_CATEGORY_ORDER[nextIdx]);
        }

        function syncReturnTabInputs(tab) {
            var currentTab = ADMIN_TAB_META[tab] ? tab : 'overview';
            document.querySelectorAll('form[method="POST"], form[method="post"]').forEach(function(formEl) {
                var panelEl = formEl.closest ? formEl.closest('[data-admin-panel]') : null;
                var actionInput = formEl.querySelector('input[name="action"]');
                var actName = actionInput ? String(actionInput.value || '') : '';
                var defaultTab = formEl.getAttribute('data-default-tab');
                if (!defaultTab && panelEl) {
                    var rawPanel = String(panelEl.getAttribute('data-admin-panel') || '');
                    var firstToken = rawPanel.split(',')[0].trim();
                    if (firstToken && firstToken !== 'overview') {
                        defaultTab = firstToken;
                    }
                }
                if (!defaultTab) {
                    if (actName === 'approve_gst' || actName === 'reject_gst') defaultTab = 'gst';
                    else if (actName === 'add_category' || actName === 'edit_category' || actName === 'delete_category') defaultTab = 'categories';
                    else if (actName === 'create_product' || actName === 'edit_product' || actName === 'toggle_product_status' || actName === 'delete_product' || actName === 'update_product_category') defaultTab = 'products';
                    else if (actName === 'create_seller' || actName === 'edit_seller') defaultTab = 'sellers';
                    else defaultTab = currentTab;
                }
                var targetReturnTab = (currentTab === 'all') ? 'all' : (defaultTab || currentTab);
                var retInput = formEl.querySelector('input[name="return_tab"]');
                if (!retInput) {
                    retInput = document.createElement('input');
                    retInput.type = 'hidden';
                    retInput.name = 'return_tab';
                    formEl.appendChild(retInput);
                }
                retInput.value = targetReturnTab;
            });
        }

        function switchAdminTab(tab, ev, skipPush) {
            if (ev && (ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey || ev.button === 1)) {
                return true;
            }
            if (ev && typeof ev.preventDefault === 'function') {
                ev.preventDefault();
            }
            var nextTab = ADMIN_TAB_META[tab] ? tab : 'overview';
            window._activeAdminTab = nextTab;

            document.querySelectorAll('[data-admin-panel]').forEach(function(el) {
                var tokens = String(el.getAttribute('data-admin-panel') || '').split(',');
                var visible = (nextTab === 'all')
                    ? (tokens.indexOf('all') !== -1 || tokens.indexOf('overview') === -1)
                    : (tokens.indexOf(nextTab) !== -1);
                if (visible) el.classList.add('is-visible');
                else el.classList.remove('is-visible');
            });

            document.querySelectorAll('#adminTopNav .nav-pill, #adminFooterNav a[data-tab], #adminCategoryPager .cat-page-pill').forEach(function(link) {
                var lt = link.getAttribute('data-tab');
                if (lt === nextTab) link.classList.add('active');
                else link.classList.remove('active');
            });

            var meta = ADMIN_TAB_META[nextTab] || ADMIN_TAB_META.overview;
            var titleEl = document.getElementById('adminPageTitle');
            var subEl = document.getElementById('adminPageSubtitle');
            if (titleEl) titleEl.innerText = meta.title;
            if (subEl) subEl.innerText = meta.sub;
            document.title = meta.title + ' \u2014 Vendor Admin Portal';

            updateCategoryPagerUI(nextTab);
            syncReturnTabInputs(nextTab);
            refreshAllTablePaginations();

            if (!skipPush && window.history && typeof window.history.pushState === 'function') {
                var currentPath = window.location.pathname + window.location.search;
                if (currentPath !== meta.url) {
                    window.history.pushState({ adminTab: nextTab }, '', meta.url);
                }
            }
            if (typeof window.scrollTo === 'function') {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
            return false;
        }

        window.addEventListener('popstate', function(ev) {
            var stTab = (ev.state && ev.state.adminTab) ? ev.state.adminTab : detectInitialAdminTab();
            switchAdminTab(stTab, null, true);
        });

        window.addEventListener('DOMContentLoaded', function() {
            syncReturnTabInputs(window._activeAdminTab);
            updateCategoryPagerUI(window._activeAdminTab);
            refreshAllTablePaginations();
        });

        document.addEventListener('submit', function() {
            syncReturnTabInputs(window._activeAdminTab);
        }, true);

        function parseDataJson(btnOrObj, attrName) {
            if (!btnOrObj) return null;
            if (typeof btnOrObj.getAttribute === 'function') {
                try {
                    return JSON.parse(btnOrObj.getAttribute(attrName) || 'null');
                } catch (e) {
                    return null;
                }
            }
            return btnOrObj;
        }

        function toAdminPreviewUrl(u) {
            if (!u) return '';
            var s = String(u);
            var idx = s.indexOf('/uploads/products/');
            if (idx !== -1) {
                return s.slice(idx);
            }
            return s;
        }

        function escapeHtml(str) {
            if (str === null || str === undefined) return '';
            return String(str).replace(/[&<>'"]/g, function(tag) {
                return ({
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    "'": '&#39;',
                    '"': '&quot;'
                })[tag] || tag;
            });
        }

        function openFormConfirmModal(form) {
            if (!form) return false;
            syncReturnTabInputs(window._activeAdminTab);
            window._pendingConfirmForm = form;
            document.getElementById('confirmModalTitle').innerText = form.getAttribute('data-confirm-title') || 'Confirm Action';
            document.getElementById('confirmModalMessage').innerText = form.getAttribute('data-confirm-msg') || 'Are you sure you want to proceed?';
            document.getElementById('confirmActionModal').classList.add('active');
            return false;
        }

        function closeConfirmModal() {
            window._pendingConfirmForm = null;
            document.getElementById('confirmActionModal').classList.remove('active');
        }

        function confirmPendingForm() {
            var f = window._pendingConfirmForm;
            closeConfirmModal();
            if (f) {
                HTMLFormElement.prototype.submit.call(f);
            }
        }

        function openCategoryModal(btnOrCat) {
            var cat = parseDataJson(btnOrCat, 'data-cat');
            if (!cat) return;
            document.getElementById('catModalTitle').innerText = 'Edit Category #' + cat.id + ' \u2014 ' + (cat.name || '');
            document.getElementById('editCatId').value = cat.id;
            document.getElementById('editCatName').value = cat.name || '';
            document.getElementById('editCatIcon').value = cat.icon || '';
            document.getElementById('editCatDesc').value = cat.description || '';
            document.getElementById('editCatSort').value = cat.sort_order !== undefined ? cat.sort_order : 0;
            document.getElementById('editCatActive').checked = Boolean(Number(cat.is_active));
            syncReturnTabInputs(window._activeAdminTab);
            document.getElementById('editCategoryModal').classList.add('active');
        }

        function closeCategoryModal() {
            document.getElementById('editCategoryModal').classList.remove('active');
        }

        function openProductModal(btnOrProd) {
            var prod = btnOrProd ? parseDataJson(btnOrProd, 'data-product') : null;
            var isEdit = Boolean(prod && prod.id);
            document.getElementById('prodModalTitle').innerText = isEdit
                ? ('Edit Product #' + prod.id + ' \u2014 ' + (prod.name || ''))
                : 'Add New Product';
            document.getElementById('prodFormAction').value = isEdit ? 'edit_product' : 'create_product';
            document.getElementById('prodFormId').value = isEdit ? prod.id : '';
            document.getElementById('prodFormName').value = isEdit ? (prod.name || '') : '';
            document.getElementById('prodFormSku').value = isEdit ? (prod.sku || '') : '';
            document.getElementById('prodFormBrand').value = isEdit ? (prod.brand || 'Generic') : 'Generic';
            document.getElementById('prodFormPrice').value = isEdit ? (prod.price !== undefined && prod.price !== null ? prod.price : '') : '';
            document.getElementById('prodFormMrp').value = isEdit ? (prod.mrp !== undefined && prod.mrp !== null ? prod.mrp : '') : '';
            document.getElementById('prodFormStock').value = isEdit ? (prod.stock !== undefined && prod.stock !== null ? prod.stock : 0) : 0;
            document.getElementById('prodFormDesc').value = isEdit ? (prod.description || '') : '';
            document.getElementById('prodFormActive').checked = isEdit ? Boolean(prod.is_active) : true;
            document.getElementById('prodFormFiles').value = '';
            document.getElementById('prodFormImageUrls').value = '';
            document.getElementById('prodFormReplaceImgs').checked = false;
            document.getElementById('prodFormClearImgs').checked = false;
            document.getElementById('prodEditImageOptions').style.display = isEdit ? 'flex' : 'none';
            document.getElementById('prodFormSubmitBtn').innerText = isEdit ? 'Save Product Changes' : '+ Create Product';

            var sellerSel = document.getElementById('prodFormSeller');
            if (isEdit) {
                var targetSid = String(prod.seller_id || '');
                var hasOpt = false;
                Array.from(sellerSel.options).forEach(function(opt) {
                    if (opt.value === targetSid) hasOpt = true;
                });
                if (!hasOpt && targetSid) {
                    var extra = document.createElement('option');
                    extra.value = targetSid;
                    extra.text = (prod.seller_name || ('Seller #' + targetSid)) + ' (#' + targetSid + ')';
                    sellerSel.appendChild(extra);
                }
                sellerSel.value = targetSid;
            } else {
                var firstActive = '';
                Array.from(sellerSel.options).forEach(function(opt) {
                    if (!firstActive && opt.value && (opt.getAttribute('data-acc-status') || 'ACTIVE') === 'ACTIVE') {
                        firstActive = opt.value;
                    }
                });
                sellerSel.value = firstActive || '';
            }

            var catSel = document.getElementById('prodFormCategory');
            if (isEdit) {
                var matchedCat = '';
                var targetCid = prod.category_id ? String(prod.category_id) : '';
                var targetCname = String(prod.category || '').toLowerCase();
                Array.from(catSel.options).forEach(function(opt) {
                    if ((targetCid && opt.value === targetCid) || (!matchedCat && opt.text.toLowerCase() === targetCname)) {
                        matchedCat = opt.value;
                    }
                });
                if (matchedCat) catSel.value = matchedCat;
            } else if (catSel.options.length > 0) {
                catSel.selectedIndex = 0;
            }

            var existingWrap = document.getElementById('prodExistingImagesWrap');
            var existingGrid = document.getElementById('prodModalExistingImgs');
            var imgs = (isEdit && Array.isArray(prod.images)) ? prod.images : ((isEdit && prod.image_url) ? [prod.image_url] : []);
            if (isEdit && imgs.length > 0) {
                existingWrap.style.display = 'block';
                var imgHtml = '';
                imgs.forEach(function(u, idx) {
                    var previewUrl = toAdminPreviewUrl(u);
                    imgHtml += '<div class="existing-img-item">' +
                        '<img src="' + escapeHtml(previewUrl) + '" alt="Image ' + (idx + 1) + '">' +
                        '<label style="margin:0;display:inline-flex;align-items:center;gap:4px;font-size:11px;cursor:pointer;">' +
                        '<input type="checkbox" name="retain_images[]" value="' + escapeHtml(u) + '" checked style="width:auto;"> Keep #' + (idx + 1) +
                        '</label>' +
                        '</div>';
                });
                existingGrid.innerHTML = imgHtml;
            } else {
                existingWrap.style.display = 'none';
                existingGrid.innerHTML = '';
            }

            syncReturnTabInputs(window._activeAdminTab);
            document.getElementById('productModal').classList.add('active');
        }

        function closeProductModal() {
            document.getElementById('productModal').classList.remove('active');
        }

        function applyAdminProductFilters(preservePage) {
            var q = (document.getElementById('prodCatSearchInput') ? document.getElementById('prodCatSearchInput').value : '').toLowerCase().trim();
            var sellerFilter = document.getElementById('prodSellerFilter') ? document.getElementById('prodSellerFilter').value : '';
            var catFilter = (document.getElementById('prodCategoryFilter') ? document.getElementById('prodCategoryFilter').value : '').toLowerCase();
            var statusFilter = document.getElementById('prodStatusFilter') ? document.getElementById('prodStatusFilter').value : 'all';

            getTableRows('products').forEach(function(r) {
                var s = r.getAttribute('data-search') || '';
                var rSid = r.getAttribute('data-seller-id') || '';
                var rCat = (r.getAttribute('data-category') || '').toLowerCase();
                var rAct = r.getAttribute('data-active') || '1';

                var matchQ = (!q || s.indexOf(q) !== -1);
                var matchS = (!sellerFilter || rSid === sellerFilter);
                var matchC = (!catFilter || rCat === catFilter);
                var matchSt = (statusFilter === 'all' || rAct === statusFilter);
                var okMatch = (matchQ && matchS && matchC && matchSt);
                if (typeof r.setAttribute === 'function') r.setAttribute('data-filter-match', okMatch ? '1' : '0');
            });
            refreshTablePagination('products', !preservePage);
        }

        function filterAdminProductSearch(query) {
            var inp = document.getElementById('prodCatSearchInput');
            if (inp && typeof query === 'string' && inp.value !== query) {
                inp.value = query;
            }
            applyAdminProductFilters(false);
        }

        function applySellerFilters(preservePage) {
            var searchEl = document.getElementById('sellersSearchInput');
            var q = (searchEl && typeof searchEl.value === 'string' && searchEl.value) ? searchEl.value.toLowerCase().trim() : (window._activeSellerSearch || '');
            var statusEl = document.getElementById('sellersStatusFilter');
            var st = statusEl ? (statusEl.value || 'all') : (window._activeSellerStatus || 'all');
            window._activeSellerStatus = st;

            getTableRows('sellers').forEach(function(r) {
                var s = r.getAttribute('data-search') || '';
                var rSt = r.getAttribute('data-status') || '';
                var matchQ = (!q || s.indexOf(q) !== -1);
                var matchSt = (st === 'all' || rSt === st);
                var okMatch = (matchQ && matchSt);
                if (typeof r.setAttribute === 'function') r.setAttribute('data-filter-match', okMatch ? '1' : '0');
            });
            refreshTablePagination('sellers', !preservePage);
        }

        function filterSellersSearch(query) {
            window._activeSellerSearch = (query || '').toLowerCase().trim();
            var inp = document.getElementById('sellersSearchInput');
            if (inp && typeof query === 'string' && inp.value !== query) {
                inp.value = query;
            }
            applySellerFilters(false);
        }

        function applyCategoryFilters(preservePage) {
            var searchEl = document.getElementById('categoriesSearchInput');
            var q = (searchEl && typeof searchEl.value === 'string' && searchEl.value) ? searchEl.value.toLowerCase().trim() : (window._activeCategorySearch || '');
            getTableRows('categories').forEach(function(r) {
                var s = r.getAttribute('data-search') || '';
                var okMatch = (!q || s.indexOf(q) !== -1);
                if (typeof r.setAttribute === 'function') r.setAttribute('data-filter-match', okMatch ? '1' : '0');
            });
            refreshTablePagination('categories', !preservePage);
        }

        function filterCategorySearch(query) {
            window._activeCategorySearch = (query || '').toLowerCase().trim();
            var inp = document.getElementById('categoriesSearchInput');
            if (inp && typeof query === 'string' && inp.value !== query) {
                inp.value = query;
            }
            applyCategoryFilters(false);
        }

        function openSellerModal(btnOrSeller) {
            var seller = parseDataJson(btnOrSeller, 'data-seller');
            if (!seller) return;
            document.getElementById('modalTitle').innerText = 'Seller Profile \u2014 ' + (seller.seller_name || seller.name || 'Seller');
            document.getElementById('viewSellerId').innerText = '#' + seller.id;
            document.getElementById('viewCreatedAt').innerText = (seller.created_at || '').slice(0, 10) || '\u2014';

            document.getElementById('formSellerId').value = seller.id;
            document.getElementById('formSellerName').value = seller.seller_name || seller.name || '';
            document.getElementById('formPhone').value = seller.phone || '';
            document.getElementById('formEmail').value = (seller.email && seller.email.indexOf('@phone.vendor.local') === -1) ? seller.email : '';
            document.getElementById('formAddress').value = seller.address || '';
            document.getElementById('formLandmark').value = seller.landmark || '';
            document.getElementById('formState').value = seller.state || '';
            document.getElementById('formCountry').value = seller.country || 'India';

            document.getElementById('formCurrentPassword').value = '';
            document.getElementById('formNewPassword').value = '';
            document.getElementById('formConfirmPassword').value = '';

            syncReturnTabInputs(window._activeAdminTab);
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

        function applyGstFilters(preservePage) {
            var status = window._activeGstStatus || 'all';
            var q = window._activeGstSearch || '';
            getTableRows('gst').forEach(function(r) {
                var rStatus = r.getAttribute('data-status') || '';
                var searchData = r.getAttribute('data-search') || '';
                var matchStatus = (status === 'all' || rStatus === status);
                var matchQuery = (!q || searchData.indexOf(q) !== -1);
                var okMatch = (matchStatus && matchQuery);
                if (typeof r.setAttribute === 'function') r.setAttribute('data-filter-match', okMatch ? '1' : '0');
            });
            refreshTablePagination('gst', !preservePage);
        }

        function filterGstRows(status, btn) {
            window._activeGstStatus = status || 'all';
            document.querySelectorAll('#gstSection .tab-btn').forEach(function(b) { b.classList.remove('active'); });
            if (btn && btn.classList) btn.classList.add('active');
            applyGstFilters(false);
        }

        function filterGstSearch(query) {
            window._activeGstSearch = (query || '').toLowerCase().trim();
            applyGstFilters(false);
        }

        function closeInlineDocPreview() {
            var box = document.getElementById('modalInlinePreviewBox');
            var wrap = document.getElementById('modalPreviewFrameWrap');
            if (wrap) wrap.innerHTML = '';
            if (box) box.style.display = 'none';
        }

        function focusRejectBoxInModal() {
            var b = document.getElementById('rejectBox');
            if (b) {
                b.style.display = 'block';
                if (typeof b.scrollIntoView === 'function') b.scrollIntoView({ behavior: 'smooth' });
            }
            var rInp = document.getElementById('rejectReasonInput');
            if (rInp && typeof rInp.focus === 'function') rInp.focus();
        }

        function setRejectPreset(reasonText) {
            var inp = document.getElementById('rejectReasonInput');
            if (inp) inp.value = String(reasonText || '');
            focusRejectBoxInModal();
        }

        function focusRejectWithReuploadPrompt() {
            setRejectPreset('Uploaded file missing from storage \u2014 please re-upload PDF certificate.');
        }

        function openGstModalWithReject(sellerId, sellerName) {
            openGstModal(sellerId, sellerName, true, null);
        }

        function showInlineDocPreview(docId) {
            var d = (window._modalGstDocs && window._modalGstDocs[docId]) || {};
            var docName = d.original_name || ('Document #' + docId);
            var isPdf = Boolean(d.is_pdf || (d.mime_type && String(d.mime_type).toLowerCase() === 'application/pdf') || (docName.toLowerCase().slice(-4) === '.pdf'));
            var fileExists = (d.file_exists !== false);

            var previewBox = document.getElementById('modalInlinePreviewBox');
            var frameWrap = document.getElementById('modalPreviewFrameWrap');
            var labelEl = document.getElementById('modalPreviewLabel');
            var newTabEl = document.getElementById('modalPreviewNewTab');
            var dlEl = document.getElementById('modalPreviewDownload');
            var hintEl = document.getElementById('modalPreviewHint');
            if (!previewBox || !frameWrap) return;

            var previewUrl = d.admin_preview_url || ('/admin/dashboard.php?action=view_gst_doc&id=' + Number(docId) + '&mode=preview');
            var dlUrl = d.admin_download_url || ('/admin/dashboard.php?action=view_gst_doc&id=' + Number(docId) + '&mode=download');
            if (labelEl) labelEl.innerText = 'Preview: ' + docName;
            if (newTabEl) newTabEl.href = previewUrl;
            if (dlEl) dlEl.href = dlUrl;
            previewBox.style.display = 'block';

            if (!fileExists) {
                if (hintEl) hintEl.style.display = 'none';
                frameWrap.innerHTML = '<div class="p-4 text-center bg-warning-subtle text-warning-emphasis w-100 h-100 d-flex flex-column align-items-center justify-content-center gap-2">' +
                    '<strong>File Not Found on Server Disk</strong>' +
                    '<p class="small mb-1" style="max-width:440px;">This document was uploaded before persistent storage was active or has been removed from disk. Ask the seller to re-upload their PDF certificate.</p>' +
                    '<button type="button" class="btn btn-danger btn-sm fw-semibold" onclick="focusRejectWithReuploadPrompt()">Reject &amp; Request Re-upload</button>' +
                    '</div>';
                return;
            }

            if (hintEl) hintEl.style.display = 'block';
            if (isPdf) {
                frameWrap.innerHTML = '<iframe src="' + escapeHtml(previewUrl) + '#toolbar=1&navpanes=0" title="PDF Preview" style="width:100%;height:100%;border:none;background:#fff;"></iframe>';
            } else {
                frameWrap.innerHTML = '<img src="' + escapeHtml(previewUrl) + '" alt="Document Preview" style="max-width:100%;max-height:100%;object-fit:contain;">';
            }
        }

        function openGstModal(sellerId, sellerName, autoShowReject, previewDocId) {
            document.getElementById('gstModalTitle').innerText = 'Verification Review \u2014 ' + (sellerName || 'Seller #' + sellerId);
            document.getElementById('approveSellerId').value = sellerId;
            document.getElementById('rejectSellerId').value = sellerId;
            var rejInputEl = document.getElementById('rejectReasonInput');
            if (rejInputEl) rejInputEl.value = '';
            document.getElementById('rejectBox').style.display = autoShowReject ? 'block' : 'none';
            var errBox = document.getElementById('gstFetchErrorBox');
            if (errBox) {
                errBox.style.display = 'none';
                errBox.innerText = '';
            }
            closeInlineDocPreview();
            window._modalGstDocs = {};

            var loadingEl = document.getElementById('gstModalLoading');
            loadingEl.innerText = 'Loading details & documents...';
            loadingEl.style.display = 'block';
            document.getElementById('gstModalContent').style.display = 'none';
            syncReturnTabInputs(window._activeAdminTab);
            document.getElementById('gstReviewModal').classList.add('active');

            fetch('/admin/dashboard.php?action=get_gst_details&seller_id=' + Number(sellerId), { credentials: 'same-origin' })
                .then(function(res) {
                    if (!res.ok) {
                        return res.text().then(function(t) { throw new Error('HTTP ' + res.status + ': ' + String(t || '').slice(0, 120)); });
                    }
                    return res.json();
                })
                .then(function(data) {
                    loadingEl.style.display = 'none';
                    document.getElementById('gstModalContent').style.display = 'block';

                    var sl = data.seller || {};
                    var g = data.gst || {};
                    var docs = data.documents || [];
                    var logs = data.logs || [];

                    window._modalGstDocs = {};
                    docs.forEach(function(d) { window._modalGstDocs[d.id] = d; });

                    var resolvedName = sellerName || sl.seller_name || sl.name || ('Seller #' + sellerId);
                    document.getElementById('modalSellerName').innerText = resolvedName + ' (#' + sellerId + ')';
                    var metaParts = [];
                    if (sl.phone) metaParts.push('Phone: ' + sl.phone);
                    if (sl.email) metaParts.push('Email: ' + sl.email);
                    var metaEl = document.getElementById('modalSellerMeta');
                    if (metaEl) metaEl.innerText = metaParts.length ? metaParts.join(' \u2022 ') : ('Seller ID #' + sellerId);

                    document.getElementById('modalSubmittedAt').innerText = String(g.submitted_at || g.created_at || '\u2014').slice(0, 16).replace('T', ' ');
                    document.getElementById('modalVerifiedAt').innerText = String(g.verified_at || '\u2014').slice(0, 16).replace('T', ' ');

                    var gstinRow = document.getElementById('modalGstinRow');
                    if (gstinRow) {
                        if (g.gstin || g.business_name || g.business_address || g.state || g.pincode) {
                            gstinRow.style.display = 'block';
                            document.getElementById('modalGstin').innerText = g.gstin || '\u2014';
                            document.getElementById('modalBusinessName').innerText = g.business_name || '\u2014';
                            var addrEl = document.getElementById('modalBusinessAddress');
                            if (addrEl) addrEl.innerText = g.business_address || '\u2014';
                            var spEl = document.getElementById('modalStatePin');
                            var statePinStr = ((g.state || '') + (g.pincode ? (' - ' + g.pincode) : '')).trim();
                            if (spEl) spEl.innerText = statePinStr || '\u2014';
                        } else {
                            gstinRow.style.display = 'none';
                        }
                    }

                    var st = String(g.status || 'Pending').toLowerCase();
                    var badgeHtml = '<span class="badge-pending">' + escapeHtml(g.status || 'Pending') + '</span>';
                    if (st === 'approved') badgeHtml = '<span class="badge-approved">' + escapeHtml(g.status) + '</span>';
                    if (st === 'rejected') badgeHtml = '<span class="badge-rejected">' + escapeHtml(g.status) + '</span>';
                    document.getElementById('modalGstStatus').innerHTML = badgeHtml;

                    var rejBox = document.getElementById('rejectionAlertBox');
                    if (g.rejection_reason) {
                        rejBox.style.display = 'block';
                        document.getElementById('modalRejectionReason').innerText = g.rejection_reason;
                    } else {
                        rejBox.style.display = 'none';
                    }

                    document.getElementById('modalDocCount').innerText = docs.length;
                    var docsContainer = document.getElementById('modalDocumentsList');
                    if (docs.length === 0) {
                        docsContainer.innerHTML = '<div class="text-body-secondary small p-3 bg-light rounded-3 border">No documents uploaded by this seller yet.</div>';
                    } else {
                        var docsHtml = '';
                        docs.forEach(function(d) {
                            var dName = String(d.original_name || ('Document #' + d.id));
                            var isPdf = Boolean(d.is_pdf || (d.mime_type && String(d.mime_type).toLowerCase() === 'application/pdf') || (dName.toLowerCase().slice(-4) === '.pdf'));
                            var typeBadge = isPdf ? '<span class="badge-pdf">PDF</span>' : '<span class="badge-img">IMAGE</span>';
                            var primaryTag = d.is_primary ? '<span class="badge text-bg-primary ms-1">Main Document</span>' : '';
                            var missingTag = (d.file_exists === false) ? '<span class="badge text-bg-warning ms-1">File Missing (Re-upload Needed)</span>' : '';
                            var previewHref = d.admin_preview_url || ('/admin/dashboard.php?action=view_gst_doc&id=' + Number(d.id) + '&mode=preview');
                            var dlHref = d.admin_download_url || ('/admin/dashboard.php?action=view_gst_doc&id=' + Number(d.id) + '&mode=download');

                            docsHtml += '<div class="doc-card">' +
                                '<div>' +
                                '<div class="fw-bold small text-dark d-flex align-items-center gap-2 flex-wrap">' +
                                typeBadge + ' ' + escapeHtml(dName) + ' ' + primaryTag + ' ' + missingTag +
                                '</div>' +
                                '<div class="small text-body-secondary mt-1">' +
                                escapeHtml(d.file_size_formatted || '') + ' &bull; Uploaded ' + escapeHtml(d.created_at ? String(d.created_at).slice(0, 10) : '') +
                                '</div>' +
                                '</div>' +
                                '<div class="d-flex gap-2 flex-wrap">' +
                                '<button type="button" class="btn btn-info btn-sm text-white fw-semibold" onclick="showInlineDocPreview(' + Number(d.id) + ')">View Here</button>' +
                                '<a href="' + escapeHtml(previewHref) + '" target="_blank" class="btn btn-outline-secondary btn-sm fw-semibold">New Tab</a>' +
                                '<a href="' + escapeHtml(dlHref) + '" class="btn btn-outline-dark btn-sm fw-semibold">Download</a>' +
                                '</div>' +
                                '</div>';
                        });
                        docsContainer.innerHTML = docsHtml;

                        var firstDocId = previewDocId || (docs[0] && docs[0].id);
                        if (firstDocId && (!autoShowReject || previewDocId)) {
                            showInlineDocPreview(firstDocId);
                        }
                    }

                    var logsContainer = document.getElementById('modalLogsList');
                    if (logs.length === 0) {
                        logsContainer.innerHTML = '<div class="text-body-secondary small py-2">No history logs recorded yet.</div>';
                    } else {
                        var logsHtml = '';
                        logs.forEach(function(l) {
                            logsHtml += '<div class="log-row">' +
                                '<div class="text-body-secondary small" style="min-width:130px;">' + escapeHtml(String(l.created_at || '').slice(0, 16).replace('T', ' ')) + '</div>' +
                                '<div class="flex-grow-1">' +
                                '<strong>' + escapeHtml(String(l.action || '').toUpperCase()) + '</strong> ' +
                                '<span class="text-body-secondary">(' + escapeHtml(l.performer_role || 'seller') + '):</span> ' +
                                escapeHtml(l.notes || '') +
                                '</div>' +
                                '<div><span class="badge text-bg-secondary">' + escapeHtml(l.new_status) + '</span></div>' +
                                '</div>';
                        });
                        logsContainer.innerHTML = logsHtml;
                    }

                    if (autoShowReject) {
                        focusRejectBoxInModal();
                    }
                })
                .catch(function(err) {
                    loadingEl.style.display = 'none';
                    var contentEl = document.getElementById('gstModalContent');
                    if (contentEl) contentEl.style.display = 'block';
                    var eBox = document.getElementById('gstFetchErrorBox');
                    if (eBox) {
                        eBox.style.display = 'block';
                        eBox.innerText = 'Could not load server details (' + ((err && err.message) ? err.message : 'Request failed') + '). You can still Approve or Reject this seller below.';
                    }
                    var msName = document.getElementById('modalSellerName');
                    if (msName) msName.innerText = (sellerName || ('Seller #' + sellerId)) + ' (#' + sellerId + ')';
                    var msMeta = document.getElementById('modalSellerMeta');
                    if (msMeta) msMeta.innerText = 'Seller ID #' + sellerId;
                    document.getElementById('modalGstStatus').innerHTML = '<span class="badge-pending">Offline / Partial</span>';
                    document.getElementById('modalDocCount').innerText = '0';
                    document.getElementById('modalDocumentsList').innerHTML = '<div class="text-body-secondary small p-3 bg-light rounded-3 border">Document metadata unavailable while fetch failed.</div>';
                    document.getElementById('modalLogsList').innerHTML = '<div class="text-body-secondary small py-2">Logs unavailable while fetch failed.</div>';
                    if (autoShowReject) {
                        focusRejectBoxInModal();
                    }
                });
        }

        function closeGstModal() {
            closeInlineDocPreview();
            document.getElementById('gstReviewModal').classList.remove('active');
        }

        function toggleRejectBox() {
            var b = document.getElementById('rejectBox');
            if (!b) return;
            b.style.display = (b.style.display === 'none' || !b.style.display) ? 'block' : 'none';
            if (b.style.display === 'block') {
                var rInp = document.getElementById('rejectReasonInput');
                if (rInp && typeof rInp.focus === 'function') rInp.focus();
            }
        }

        function handleGstOverlayClick(e) {
            if (e.target.id === 'gstReviewModal') {
                closeGstModal();
            }
        }

        // === Seller Deletion & Refund Management UI Controller ===
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
            return '\u20B9' + Number(v || 0).toFixed(2);
        }

        function syncSellerAccountRow(sellerId, accountStatus, activeReqId) {
            var row = document.getElementById('seller-row-' + Number(sellerId));
            if (!row) return;
            var accUpper = String(accountStatus || 'ACTIVE').toUpperCase();
            if (typeof row.setAttribute === 'function') row.setAttribute('data-status', accUpper);
            var accCell = row.querySelector('.seller-acc-cell');
            if (accCell) {
                var cls = accUpper === 'ACTIVE' ? 'del-green' : (accUpper === 'DELETION_PENDING' ? 'del-yellow' : (accUpper === 'LOCKED' ? 'del-orange' : 'del-red'));
                accCell.innerHTML = '<span class="del-badge ' + cls + '">' + escapeHtml(accUpper) + '</span>';
            }
            var slot = row.querySelector('.seller-del-btn-slot');
            if (slot) {
                if (activeReqId && accUpper !== 'DELETED') {
                    slot.innerHTML = '<button type="button" class="btn btn-warning btn-sm fw-semibold" onclick="openDeletionWorkspaceModal(' + Number(activeReqId) + ')">Manage Deletion #' + Number(activeReqId) + '</button>';
                } else if (accUpper !== 'DELETED') {
                    slot.innerHTML = '<button type="button" class="btn btn-outline-danger btn-sm fw-semibold" onclick="openDeletionPreflightModal(' + Number(sellerId) + ')">Request Seller Deletion</button>';
                } else {
                    slot.innerHTML = '';
                }
            }
        }

        function filterDeletionStatus(status, btn) {
            window._activeDelStatusFilter = status || 'all';
            document.querySelectorAll('#delFilterTabs .tab-btn').forEach(function(b) { b.classList.remove('active'); });
            if (btn && btn.classList) btn.classList.add('active');
            applyDeletionTableFilters(false);
        }

        function filterDeletionTableSearch(q) {
            window._activeDelSearch = (q || '').toLowerCase().trim();
            applyDeletionTableFilters(false);
        }

        function applyDeletionTableFilters(preservePage) {
            var st = window._activeDelStatusFilter || 'all';
            var q = window._activeDelSearch || '';
            getTableRows('del').forEach(function(r) {
                var rSt = r.getAttribute('data-status') || '';
                var rTxt = r.getAttribute('data-search') || '';
                var matchSt = (st === 'all' || rSt === st);
                var matchQ = (!q || rTxt.indexOf(q) !== -1);
                var okMatch = (matchSt && matchQ);
                if (typeof r.setAttribute === 'function') r.setAttribute('data-filter-match', okMatch ? '1' : '0');
            });
            refreshTablePagination('del', !preservePage);
        }

        function reloadDeletionRequestsList() {
            fetch('/api/admin/seller-deletions', { credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data || !data.requests) return;
                    var k = data.kpis || {};
                    var el;
                    if ((el = document.getElementById('delKpiTotal'))) el.innerText = Number(k.total || 0);
                    if ((el = document.getElementById('navDelCount'))) el.innerText = Number(k.total || 0);
                    if ((el = document.getElementById('delKpiPending'))) el.innerText = Number(k.pending || 0);
                    if ((el = document.getElementById('delKpiProcessing'))) el.innerText = Number(k.processing || 0);
                    if ((el = document.getElementById('delKpiFailed'))) el.innerText = Number(k.failed || 0);
                    if ((el = document.getElementById('delKpiHold'))) el.innerText = Number(k.on_hold || 0);
                    if ((el = document.getElementById('delKpiApprovedDeleted'))) el.innerText = Number(k.approved || 0) + Number(k.deleted || 0);

                    var tbody = document.getElementById('deletionRequestsTbody');
                    if (!tbody) return;
                    if (data.requests.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="7" class="text-body-secondary text-center py-4">No seller deletion requests submitted yet.</td></tr>';
                        refreshTablePagination('del', true);
                        return;
                    }
                    var html = '';
                    data.requests.forEach(function(dr) {
                        var snapM = dr.metrics || {};
                        var finS = dr.financial_summary || {};
                        var searchTxt = ((dr.seller_name || '') + ' ' + (dr.seller_email || '') + ' ' + (dr.seller_phone || '') + ' ' + dr.seller_id + ' ' + dr.status + ' ' + dr.stage).toLowerCase();
                        html += '<tr class="del-req-row" data-status="' + escapeHtml(dr.status) + '" data-search="' + escapeHtml(searchTxt) + '">' +
                            '<td class="fw-bold">#' + Number(dr.id) + '</td>' +
                            '<td><span class="fw-bold text-dark">' + escapeHtml(dr.seller_name) + '</span>' +
                            (dr.initiated_by_role === 'seller' ? ' <span class="del-badge del-yellow ms-1" style="font-size:10px; padding:2px 6px;">Seller Request</span>' : '') +
                            '<div class="small text-body-secondary">Seller ID #' + Number(dr.seller_id) + (dr.seller_phone ? ' &bull; ' + escapeHtml(dr.seller_phone) : '') + '</div>' +
                            (dr.seller_email ? '<div class="small text-muted">' + escapeHtml(dr.seller_email) + '</div>' : '') +
                            '</td>' +
                            '<td><div class="small">' + escapeHtml((dr.created_at || '').slice(0, 10)) + '</div><div class="small text-muted">' + escapeHtml((dr.created_at || '').slice(11, 19)) + '</div></td>' +
                            '<td><code class="bg-light px-2 py-1 rounded border small">' + escapeHtml(dr.stage) + '</code></td>' +
                            '<td>' + delStatusBadgeHtml(dr.badge_color || dr.status, dr.status) + '</td>' +
                            '<td class="small text-body-secondary">Orders: <strong>' + Number(snapM.total_orders || 0) + '</strong> &bull; Balance: <strong>' + fmtCur(finS.available_seller_balance || snapM.seller_wallet_balance || 0) + '</strong></td>' +
                            '<td><button type="button" class="btn btn-primary btn-sm fw-semibold" onclick="openDeletionWorkspaceModal(' + Number(dr.id) + ')">Manage Deletion</button></td>' +
                            '</tr>';
                    });
                    tbody.innerHTML = html;
                    applyDeletionTableFilters(true);
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
                        bodyEl.innerHTML = '<div class="alert alert-danger mb-0">' + escapeHtml((resObj.d && resObj.d.message) || 'Failed to load preflight metrics.') + '</div>';
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
                    var html = '<div class="card bg-light border mb-3"><div class="card-body p-3 d-flex justify-content-between align-items-center flex-wrap gap-2">' +
                        '<div><strong class="fs-6 text-dark">' + escapeHtml(s.seller_name || s.name || ('Seller #' + s.id)) + '</strong>' +
                        '<div class="small text-body-secondary mt-1">Seller ID #' + Number(s.id) + ' &bull; ' + escapeHtml(s.phone || '—') + (s.email ? ' &bull; ' + escapeHtml(s.email) : '') + '</div></div>' +
                        '<div>' + delStatusBadgeHtml(s.account_status === 'ACTIVE' ? 'GREEN' : 'YELLOW', s.account_status || 'ACTIVE') + '</div></div></div>' +
                        '<div class="row row-cols-2 row-cols-md-3 row-cols-lg-4 g-2 mb-3">';
                    cards.forEach(function(c) {
                        html += '<div class="col"><div class="metric-mini h-100"><div class="k">' + escapeHtml(c[0]) + '</div><div class="v">' + escapeHtml(String(c[1])) + '</div></div></div>';
                    });
                    html += '</div>' +
                        '<div class="alert alert-warning small mb-3">' +
                        'Submitting this request sets the seller account immediately to <strong>DELETION_PENDING</strong>, notifies the seller, and blocks new buyer orders without deleting any historical data.' +
                        '</div>' +
                        '<div class="mb-3"><label class="form-label small fw-semibold">Deletion Request Reason *</label>' +
                        '<input type="text" class="form-control" id="preflightReason" value="Admin initiated seller deletion audit" maxlength="255"></div>' +
                        '<div class="mb-2"><label class="form-label small fw-semibold">Admin Remarks</label>' +
                        '<textarea class="form-control" id="preflightRemarks" rows="2" placeholder="Optional initial audit remarks..."></textarea></div>' +
                        '<div id="preflightErrBox" class="text-danger small mt-2"></div>' +
                        '<div class="d-flex gap-2 mt-3 justify-content-end pt-2 border-top">' +
                        '<button type="button" onclick="closeDeletionPreflightModal()" class="btn btn-outline-secondary">Cancel</button>' +
                        '<button type="button" onclick="submitCreateDeletionRequest(' + Number(s.id) + ')" class="btn btn-danger fw-semibold">Confirm Deletion Request (DELETION_PENDING)</button>' +
                        '</div>';
                    bodyEl.innerHTML = html;
                })
                .catch(function(err) {
                    document.getElementById('delPreflightLoading').style.display = 'none';
                    document.getElementById('delPreflightBody').style.display = 'block';
                    document.getElementById('delPreflightBody').innerHTML = '<div class="alert alert-danger mb-0">Error: ' + escapeHtml(err.message) + '</div>';
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
                    syncSellerAccountRow(sellerId, (resObj.d.seller && resObj.d.seller.account_status) || 'DELETION_PENDING', resObj.d.deletion_request.id);
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
            if (msgBox) msgBox.innerHTML = '<div class="alert alert-info py-2 px-3 small mb-3">Processing action...</div>';

            fetch('/api/admin/seller-deletions/' + Number(reqId) + '/' + subPath, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(function(r) { return r.json().then(function(d) { return { ok: r.ok, d: d }; }); })
            .then(function(resObj) {
                if (!resObj.ok) {
                    if (msgBox) msgBox.innerHTML = '<div class="alert alert-danger py-2 px-3 small mb-3">' + escapeHtml((resObj.d && resObj.d.message) || 'Action failed.') + '</div>';
                    return;
                }
                if (resObj.d && resObj.d.deletion_request) {
                    var dr = resObj.d.deletion_request;
                    var sl = resObj.d.seller || {};
                    var nextActiveReq = (dr.status === 'DELETED' || dr.status === 'DELETION_REJECTED') ? null : dr.id;
                    syncSellerAccountRow(dr.seller_id, sl.account_status || 'DELETION_PENDING', nextActiveReq);
                    renderDeletionWorkspace(resObj.d, (resObj.d.message || 'Action completed.'));
                }
                reloadDeletionRequestsList();
            })
            .catch(function(err) {
                if (msgBox) msgBox.innerHTML = '<div class="alert alert-danger py-2 px-3 small mb-3">' + escapeHtml(err.message) + '</div>';
            });
        }

        function wsLockAccount(reqId) {
            runDelAction(reqId, 'lock', {});
        }

        function wsVerifyOrders(reqId) {
            var chk = document.getElementById('wsApproveRuleB');
            runDelAction(reqId, 'verify-orders', {
                approve_accepted_cancellations: Boolean(chk && chk.checked)
            });
        }

        function wsProcessRefunds(reqId) {
            runDelAction(reqId, 'process-refunds', {});
        }

        function wsVerifySettlement(reqId) {
            runDelAction(reqId, 'verify-settlement', {});
        }

        function wsResolveDispute(reqId, dpId) {
            runDelAction(reqId, 'resolve-dispute', {
                dispute_id: Number(dpId),
                resolution: 'RESOLVED',
                mark_delivered: true,
                close_return_window: true
            });
        }

        function wsApproveDeletion(reqId) {
            runDelAction(reqId, 'approve', {});
        }

        function handleSettlePayoutClick(reqId) {
            var chk = document.getElementById('wsConfirmGatewaySettled');
            var ref = document.getElementById('wsPayoutRef');
            runDelAction(reqId, 'settle-payout', {
                confirm_gateway_settled: Boolean(chk && chk.checked),
                payout_reference: (ref && ref.value.trim()) || ''
            });
        }

        function toggleWsHoldBox() {
            var h = document.getElementById('wsHoldBox');
            var r = document.getElementById('wsRejectBox');
            if (r) r.style.display = 'none';
            if (h) h.style.display = (h.style.display === 'none' || !h.style.display) ? 'block' : 'none';
        }

        function submitWsHold(reqId) {
            var inp = document.getElementById('wsHoldReasonInput');
            var reason = inp ? inp.value.trim() : '';
            runDelAction(reqId, 'hold', { reason: reason || 'Awaiting manual verification / return period' });
        }

        function toggleWsRejectBox() {
            var h = document.getElementById('wsHoldBox');
            var r = document.getElementById('wsRejectBox');
            var err = document.getElementById('wsRejectErr');
            if (h) h.style.display = 'none';
            if (err) err.innerText = '';
            if (r) r.style.display = (r.style.display === 'none' || !r.style.display) ? 'block' : 'none';
        }

        function submitWsReject(reqId) {
            var inp = document.getElementById('wsRejectReasonInput');
            var err = document.getElementById('wsRejectErr');
            var reason = inp ? inp.value.trim() : '';
            if (!reason) {
                if (err) err.innerText = 'Rejection reason is required to reject deletion and restore seller to ACTIVE.';
                return;
            }
            if (err) err.innerText = '';
            runDelAction(reqId, 'reject', { reason: reason });
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
                (successBanner ? '<div class="alert alert-success py-2 px-3 small fw-semibold mb-3">' + escapeHtml(successBanner) + '</div>' : '') +
                '</div>';

            html += '<div class="ws-section bg-light">' +
                '<div class="d-flex justify-content-between align-items-start flex-wrap gap-2">' +
                '<div>' +
                '<div class="fs-6 fw-bold text-dark">' + escapeHtml(s.seller_name || s.name || ('Seller #' + dr.seller_id)) + ' <span class="small text-body-secondary">(Seller ID #' + Number(dr.seller_id) + ')</span></div>' +
                '<div class="small text-body-secondary mt-1">Phone: ' + escapeHtml(s.phone || '—') + ' &bull; Email: ' + escapeHtml(s.email || '—') + ' &bull; Account: <strong>' + escapeHtml(s.account_status || 'ACTIVE') + '</strong></div>' +
                '<div class="small text-muted mt-1">Request Date: <strong>' + escapeHtml((dr.created_at || '').slice(0, 19).replace('T', ' ')) + '</strong> &bull; Reason: ' + escapeHtml(dr.request_reason || '—') + '</div>' +
                '</div>' +
                '<div class="text-md-end">' +
                '<div class="mb-1 small">Stage: <code class="bg-primary-subtle text-primary-emphasis px-2 py-1 rounded fw-bold">' + escapeHtml(dr.stage) + '</code></div>' +
                '<div>' + delStatusBadgeHtml(dr.badge_color || dr.status, dr.status) + '</div>' +
                '</div></div></div>';

            html += '<div class="ws-section"><h4><span>6-Gate Verification Checklist</span><span>' +
                (data.all_checks_passed ? delStatusBadgeHtml('GREEN', 'ALL CHECKS PASSED') : delStatusBadgeHtml('YELLOW', 'VERIFICATION INCOMPLETE')) +
                '</span></h4><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0"><thead class="table-light"><tr><th>Check Requirement</th><th>Status</th></tr></thead><tbody>';
            checks.forEach(function(ck) {
                html += '<tr><td><strong>' + escapeHtml(ck.label) + '</strong></td><td>' + delStatusBadgeHtml(ck.status, ck.status) + '</td></tr>';
            });
            html += '</tbody></table></div></div>';

            html += '<div class="ws-section"><h4><span>Order Summary (' + Number(m.total_orders || 0) + ' orders)</span>' +
                '<div class="d-flex gap-2 align-items-center flex-wrap">' +
                '<label class="form-check-label small fw-semibold d-inline-flex align-items-center gap-1 m-0" style="cursor:pointer;"><input type="checkbox" class="form-check-input mt-0" id="wsApproveRuleB"> Approve Accepted Order Cancellations (Rule B)</label>' +
                '<button type="button" class="btn btn-primary btn-sm fw-semibold" ' + (isTerminal ? 'disabled' : '') + ' onclick="wsLockAccount(' + Number(dr.id) + ')">Lock Account</button>' +
                '<button type="button" class="btn btn-primary btn-sm fw-semibold" ' + (isTerminal ? 'disabled' : '') + ' onclick="wsVerifyOrders(' + Number(dr.id) + ')">Verify Orders</button>' +
                '</div></h4>' +
                '<div class="row row-cols-2 row-cols-sm-5 g-2 mb-2">' +
                '<div class="col"><div class="metric-mini"><div class="k">Pending (A)</div><div class="v">' + Number(m.pending_orders || 0) + '</div></div></div>' +
                '<div class="col"><div class="metric-mini"><div class="k">Confirmed (B)</div><div class="v">' + Number(m.confirmed_orders || 0) + '</div></div></div>' +
                '<div class="col"><div class="metric-mini"><div class="k">Shipped (C)</div><div class="v">' + Number(m.shipped_orders || 0) + '</div></div></div>' +
                '<div class="col"><div class="metric-mini"><div class="k">Delivered (D)</div><div class="v">' + Number(m.delivered_orders || 0) + '</div></div></div>' +
                '<div class="col"><div class="metric-mini"><div class="k">Cancelled (E)</div><div class="v">' + Number(m.cancelled_orders || 0) + '</div></div></div>' +
                '</div>';
            if (orders.length > 0) {
                html += '<div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0"><thead class="table-light"><tr><th>Order</th><th>Status</th><th>Payment</th><th>Seller Share</th><th>Gateway / Window</th></tr></thead><tbody>';
                orders.forEach(function(o) {
                    html += '<tr><td>#' + Number(o.id) + ' (' + escapeHtml(o.order_number || '') + ')</td>' +
                        '<td><strong>' + escapeHtml(o.effective_status) + '</strong></td>' +
                        '<td>' + escapeHtml(o.payment_method || '') + ' / ' + escapeHtml(o.payment_status || '') + '</td>' +
                        '<td>' + fmtCur(o.seller_gross || 0) + '</td>' +
                        '<td class="small">' + (o.is_gateway_settled ? 'Gateway Settled' : 'Unsettled/Pending') + (o.return_window_active ? ' &bull; Return Window Active' : '') + (o.open_disputes_count > 0 ? ' &bull; Open Disputes: ' + Number(o.open_disputes_count) : '') + '</td></tr>';
                });
                html += '</tbody></table></div>';
            }
            html += '</div>';

            html += '<div class="ws-section"><h4><span>Payment &amp; Automatic Refund Summary</span>' +
                '<button type="button" class="btn btn-primary btn-sm fw-semibold" ' + (isTerminal ? 'disabled' : '') + ' onclick="wsProcessRefunds(' + Number(dr.id) + ')">Process Eligible Refunds (Razorpay)</button></h4>' +
                '<div class="small text-body-secondary mb-2">Successful Payments: <strong>' + fmtCur(m.total_successful_payments || 0) + '</strong> &bull; Pending Refunds: <strong>' + Number(m.pending_refunds_count || 0) + ' (' + fmtCur(m.pending_refunds_amount || 0) + ')</strong></div>';
            if (refunds.length === 0) {
                html += '<div class="small text-muted">No refund records queued for this seller.</div>';
            } else {
                html += '<div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0"><thead class="table-light"><tr><th>Refund ID</th><th>Order ID</th><th>Eligible / Refunded</th><th>Gateway Refund ID</th><th>Status</th><th>Gateway Note</th></tr></thead><tbody>';
                refunds.forEach(function(rf) {
                    html += '<tr><td>#' + Number(rf.id) + '</td><td>#' + Number(rf.order_id) + '</td><td>' + fmtCur(rf.eligible_refund_amount) + ' / ' + fmtCur(rf.refunded_amount) + '</td>' +
                        '<td><code>' + escapeHtml(rf.gateway_refund_id || '—') + '</code></td>' +
                        '<td>' + delStatusBadgeHtml(rf.badge_color || rf.status, rf.status) + '</td>' +
                        '<td class="small text-danger">' + escapeHtml(rf.failure_reason || 'Confirmed') + '</td></tr>';
                });
                html += '</tbody></table></div>';
            }
            html += '</div>';

            html += '<div class="ws-section"><h4><span>Financial Settlement &amp; Disputes Summary</span>' +
                '<button type="button" class="btn btn-primary btn-sm fw-semibold" ' + (isTerminal ? 'disabled' : '') + ' onclick="wsVerifySettlement(' + Number(dr.id) + ')">Verify Settlement</button></h4>' +
                '<div class="row row-cols-2 row-cols-md-3 g-2 mb-3">' +
                '<div class="col"><div class="metric-mini"><div class="k">Wallet Balance</div><div class="v">' + fmtCur(set.available_seller_balance) + '</div></div></div>' +
                '<div class="col"><div class="metric-mini"><div class="k">Pending Payouts</div><div class="v">' + Number(set.pending_payouts_count || 0) + ' (' + fmtCur(set.pending_payouts_amount) + ')</div></div></div>' +
                '<div class="col"><div class="metric-mini"><div class="k">Outstanding Commission</div><div class="v">' + fmtCur(set.outstanding_commissions) + '</div></div></div>' +
                '<div class="col"><div class="metric-mini"><div class="k">Gateway Settlement</div><div class="v small fw-bold">' + (set.gateway_settled_ok ? 'ALL SETTLED' : Number(set.unsettled_gateway_count || 0) + ' UNSETTLED') + '</div></div></div>' +
                '<div class="col"><div class="metric-mini"><div class="k">Pending Disputes</div><div class="v">' + Number(set.pending_disputes_count || 0) + '</div></div></div>' +
                '<div class="col"><div class="metric-mini"><div class="k">Total Obligations</div><div class="v">' + fmtCur(set.outstanding_financial_obligations) + '</div></div></div>' +
                '</div>' +
                '<div class="d-flex gap-2 align-items-center flex-wrap p-2 bg-light border rounded-3 mb-2">' +
                '<label class="form-check-label small d-inline-flex align-items-center gap-1 m-0" style="cursor:pointer;"><input type="checkbox" class="form-check-input mt-0" id="wsConfirmGatewaySettled" ' + (set.gateway_settled_ok ? 'checked' : '') + '> Confirm Gateway Capture Settled</label>' +
                '<input type="text" class="form-control form-control-sm" id="wsPayoutRef" placeholder="Payout Reference (e.g. UTR/NEFT)" style="max-width:220px;">' +
                '<button type="button" class="btn btn-success btn-sm fw-semibold" ' + (isTerminal ? 'disabled' : '') + ' onclick="handleSettlePayoutClick(' + Number(dr.id) + ')">Settle Payout / Clearance</button>' +
                '</div>';
            if (disputes.length > 0) {
                html += '<div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0"><thead class="table-light"><tr><th>Dispute #</th><th>Order ID</th><th>Type</th><th>Claim</th><th>Status</th><th>Action</th></tr></thead><tbody>';
                disputes.forEach(function(dp) {
                    var isOpen = ['OPEN', 'UNDER_REVIEW', 'ESCALATED'].indexOf(String(dp.status || '').toUpperCase()) !== -1;
                    html += '<tr><td>#' + Number(dp.id) + '</td><td>#' + Number(dp.order_id) + '</td><td>' + escapeHtml(dp.dispute_type) + '</td><td>' + fmtCur(dp.claim_amount) + '</td>' +
                        '<td>' + delStatusBadgeHtml(isOpen ? 'ORANGE' : 'GREEN', dp.status) + '</td>' +
                        '<td>' + (isOpen && !isTerminal
                            ? '<button type="button" class="btn btn-primary btn-sm" onclick="wsResolveDispute(' + Number(dr.id) + ', ' + Number(dp.id) + ')">Mark Delivered &amp; Resolve</button>'
                            : 'Resolved') + '</td></tr>';
                });
                html += '</tbody></table></div>';
            }
            html += '</div>';

            html += '<div class="ws-section"><h4>Admin Decision &amp; Final Confirmation</h4>' +
                '<label class="form-label small fw-semibold">Admin Remarks</label>' +
                '<textarea class="form-control" id="wsAdminRemarks" rows="2" placeholder="Enter audit remarks...">' + escapeHtml(dr.admin_remarks || '') + '</textarea>' +
                '<div class="d-flex gap-2 flex-wrap mt-3 align-items-center">' +
                '<button type="button" class="btn btn-success fw-semibold" ' + (!dr.approve_allowed ? 'disabled' : '') + ' onclick="wsApproveDeletion(' + Number(dr.id) + ')">✓ Approve Deletion</button>' +
                '<button type="button" class="btn btn-warning fw-semibold" ' + (isTerminal ? 'disabled' : '') + ' onclick="toggleWsHoldBox()">⏸ Put On Hold</button>' +
                '<button type="button" class="btn btn-danger fw-semibold" ' + (isTerminal ? 'disabled' : '') + ' onclick="toggleWsRejectBox()">✕ Reject Request</button>' +
                '<button type="button" id="wsFinalDeleteBtn" class="btn btn-dark border-danger fw-semibold" ' + (!dr.final_delete_allowed ? 'disabled' : '') + ' onclick="toggleFinalDeleteConfirm()">🗑 Final Delete Seller</button>' +
                '</div>' +
                '<div id="wsHoldBox" class="alert alert-warning mt-3 mb-0 p-3" style="display:none;">' +
                '<strong class="small d-block mb-2">Put Deletion Request On Hold</strong>' +
                '<input type="text" class="form-control form-control-sm" id="wsHoldReasonInput" value="Awaiting manual verification / return period">' +
                '<div class="d-flex gap-2 mt-2">' +
                '<button type="button" class="btn btn-warning btn-sm fw-semibold" onclick="submitWsHold(' + Number(dr.id) + ')">Confirm Put On Hold</button>' +
                '<button type="button" onclick="toggleWsHoldBox()" class="btn btn-outline-secondary btn-sm">Cancel</button>' +
                '</div></div>' +
                '<div id="wsRejectBox" class="alert alert-danger mt-3 mb-0 p-3" style="display:none;">' +
                '<strong class="small d-block mb-2">Mandatory Reason to Reject Deletion &amp; Restore Seller ACTIVE *</strong>' +
                '<textarea class="form-control form-control-sm border-danger-subtle" id="wsRejectReasonInput" rows="2" placeholder="Explain why this deletion request is rejected..."></textarea>' +
                '<div id="wsRejectErr" class="text-danger small mt-1"></div>' +
                '<div class="d-flex gap-2 mt-2">' +
                '<button type="button" class="btn btn-danger btn-sm fw-semibold" onclick="submitWsReject(' + Number(dr.id) + ')">Confirm Rejection &amp; Restore ACTIVE</button>' +
                '<button type="button" onclick="toggleWsRejectBox()" class="btn btn-outline-secondary btn-sm">Cancel</button>' +
                '</div></div>' +
                (!dr.final_delete_allowed && dr.status !== 'DELETED' ? '<div class="small text-body-secondary mt-2">Final Delete remains disabled until all 6 checks are GREEN (SUCCESS) and Admin Approval issues the confirmation token.</div>' : '') +
                '<div id="wsFinalConfirmBox" class="alert alert-danger border-2 border-danger mt-3 mb-0 p-3" style="display:none;">' +
                '<strong class="small d-block">Second Explicit Confirmation Required</strong>' +
                '<p class="small my-2">Type exact phrase <code>' + escapeHtml(dr.expected_confirmation_phrase || ('DELETE SELLER #' + dr.seller_id)) + '</code> below to soft-delete this seller, revoke tokens, and hide listings while preserving all orders and financial history.</p>' +
                '<div class="d-flex gap-2 align-items-center flex-wrap">' +
                '<input type="text" class="form-control form-control-sm" id="wsConfirmPhraseInput" placeholder="' + escapeHtml(dr.expected_confirmation_phrase || '') + '" style="max-width:280px;">' +
                '<button type="button" class="btn btn-danger btn-sm fw-semibold" onclick="submitFinalDelete(' + Number(dr.id) + ')">Confirm Permanent Soft-Delete</button>' +
                '</div></div></div>';

            html += '<div class="ws-section mb-0"><h4>Admin Activity History (' + logs.length + ')</h4>';
            if (logs.length === 0) {
                html += '<div class="small text-muted">No admin activity recorded yet.</div>';
            } else {
                logs.forEach(function(lg) {
                    html += '<div class="log-row"><div class="text-body-secondary small" style="min-width:140px;">' + escapeHtml((lg.created_at || '').slice(0, 19).replace('T', ' ')) + '</div>' +
                        '<div class="flex-grow-1"><strong>' + escapeHtml(lg.action) + '</strong> (' + escapeHtml(lg.actor_role || 'admin') + ')<div class="text-body-secondary small">' + escapeHtml(lg.remarks || '') + '</div></div>' +
                        '<div>' + delStatusBadgeHtml(lg.new_status || lg.new_stage || 'INFO', lg.new_status || lg.new_stage || '') + '</div></div>';
                });
            }
            html += '</div>';

            document.getElementById('delWsBody').innerHTML = html;
        }

        function onHostingerPortChange() {
            var port = document.getElementById('hmPort').value;
            document.getElementById('hmEncryption').value = (String(port) === '587') ? 'tls' : 'ssl';
        }

        function openHostingerMailModal() {
            document.getElementById('hostingerMailModal').classList.add('active');
            loadHostingerMailConfig();
        }

        function closeHostingerMailModal() {
            document.getElementById('hostingerMailModal').classList.remove('active');
        }

        function loadHostingerMailConfig() {
            var banner = document.getElementById('hmStatusBanner');
            banner.className = 'alert alert-secondary small py-2 mb-3';
            banner.innerText = 'Loading Hostinger Mail configuration...';
            fetch('/api/admin/email/config', { credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    var cfg = (data && data.email) || {};
                    if (cfg.host) document.getElementById('hmHost').value = cfg.host;
                    if (cfg.port) document.getElementById('hmPort').value = String(cfg.port);
                    document.getElementById('hmEncryption').value = cfg.encryption || (String(cfg.port) === '587' ? 'tls' : 'ssl');
                    document.getElementById('hmUsername').value = cfg.username || '';
                    document.getElementById('hmPassword').value = '';
                    document.getElementById('hmFromAddress').value = cfg.from_address || cfg.username || '';
                    document.getElementById('hmFromName').value = cfg.from_name || 'Knowlipop Vendor';
                    document.getElementById('hmPassHint').innerText = cfg.has_password
                        ? 'A Hostinger mailbox password is currently saved. Leave blank to keep it.'
                        : 'Enter your Hostinger Business Email mailbox password.';
                    if (cfg.configured) {
                        banner.className = 'alert alert-success small py-2 mb-3';
                        banner.innerText = 'Hostinger Mail SMTP is ACTIVE (' + (cfg.username || '') + ' via ' + (cfg.host || 'smtp.hostinger.com') + ':' + (cfg.port || 465) + ', source: ' + (cfg.source || 'env') + ').';
                    } else {
                        banner.className = 'alert alert-warning small py-2 mb-3';
                        banner.innerText = 'Hostinger Mail SMTP is not configured yet. Enter your Hostinger email address and password below to enable OTP & notification emails.';
                    }
                })
                .catch(function() {
                    banner.className = 'alert alert-danger small py-2 mb-3';
                    banner.innerText = 'Could not load email settings.';
                });
        }

        function saveHostingerMailSettings(ev) {
            if (ev && typeof ev.preventDefault === 'function') ev.preventDefault();
            var btn = document.getElementById('hmSaveBtn');
            var banner = document.getElementById('hmStatusBanner');
            btn.disabled = true;
            var payload = {
                host: document.getElementById('hmHost').value.trim(),
                port: parseInt(document.getElementById('hmPort').value, 10) || 465,
                encryption: document.getElementById('hmEncryption').value.trim() || 'ssl',
                username: document.getElementById('hmUsername').value.trim(),
                password: document.getElementById('hmPassword').value,
                from_address: document.getElementById('hmFromAddress').value.trim() || document.getElementById('hmUsername').value.trim(),
                from_name: document.getElementById('hmFromName').value.trim() || 'Knowlipop Vendor'
            };
            fetch('/api/admin/email/config', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(function(r) { return r.json().then(function(d) { return { ok: r.ok, d: d }; }); })
            .then(function(res) {
                btn.disabled = false;
                if (res.ok && res.d && res.d.status === 'success') {
                    banner.className = 'alert alert-success small py-2 mb-3';
                    banner.innerText = res.d.message || 'Saved Hostinger Mail settings.';
                    document.getElementById('hmPassword').value = '';
                    loadHostingerMailConfig();
                } else {
                    banner.className = 'alert alert-danger small py-2 mb-3';
                    banner.innerText = (res.d && res.d.message) || 'Failed to save settings.';
                }
            })
            .catch(function() {
                btn.disabled = false;
                banner.className = 'alert alert-danger small py-2 mb-3';
                banner.innerText = 'Network error saving mail settings.';
            });
        }

        function sendHostingerTestEmail() {
            var to = document.getElementById('hmTestRecipient').value.trim();
            var banner = document.getElementById('hmStatusBanner');
            if (!to || to.indexOf('@') === -1) {
                banner.className = 'alert alert-danger small py-2 mb-3';
                banner.innerText = 'Enter a valid recipient email address to send a test email.';
                return;
            }
            var btn = document.getElementById('hmTestBtn');
            btn.disabled = true;
            banner.className = 'alert alert-info small py-2 mb-3';
            banner.innerText = 'Connecting to Hostinger Mail SMTP and sending test email...';
            var payload = {
                to: to,
                host: document.getElementById('hmHost').value.trim(),
                port: parseInt(document.getElementById('hmPort').value, 10) || 465,
                encryption: document.getElementById('hmEncryption').value.trim() || 'ssl',
                username: document.getElementById('hmUsername').value.trim(),
                password: document.getElementById('hmPassword').value,
                from_address: document.getElementById('hmFromAddress').value.trim() || document.getElementById('hmUsername').value.trim(),
                from_name: document.getElementById('hmFromName').value.trim() || 'Knowlipop Vendor'
            };
            fetch('/api/admin/email/test', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(function(r) { return r.json().then(function(d) { return { ok: r.ok, d: d }; }); })
            .then(function(res) {
                btn.disabled = false;
                if (res.ok && res.d && res.d.status === 'success') {
                    banner.className = 'alert alert-success small py-2 mb-3';
                    banner.innerText = res.d.message || 'Test email sent successfully!';
                } else {
                    banner.className = 'alert alert-danger small py-2 mb-3';
                    banner.innerText = (res.d && res.d.message) || 'Test email failed.';
                }
            })
            .catch(function() {
                btn.disabled = false;
                banner.className = 'alert alert-danger small py-2 mb-3';
                banner.innerText = 'Network error sending test email.';
            });
        }
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
