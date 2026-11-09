<?php

/**
 * Pure PHP: Admin Dashboard & Seller Management
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['admin_logged_in'])) {
    if (!empty($_COOKIE['admin_logged_in'])) {
        $_SESSION['admin_logged_in'] = true;
    } else {
        header('Location: /admin/login');
        exit;
    }
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/gst.php';

$pdo = getDatabaseConnection();
ensureGstTables($pdo);
$success = null;
$error = null;

// Handle view / preview GST document for admin session
if (isset($_GET['action']) && $_GET['action'] === 'view_gst_doc' && !empty($_GET['id'])) {
    $docId = (int) $_GET['id'];
    $mode = (isset($_GET['mode']) && $_GET['mode'] === 'download') ? 'download' : 'preview';
    $docStmt = $pdo->prepare("SELECT * FROM seller_gst_documents WHERE id = :id LIMIT 1");
    $docStmt->execute([':id' => $docId]);
    $doc = $docStmt->fetch();
    if (!$doc || !file_exists($doc['file_path'])) {
        http_response_code(404);
        echo 'Document file not found on server.';
        exit;
    }
    $mimeType = $doc['mime_type'] ?: 'application/octet-stream';
    $fileName = $doc['original_name'] ?: basename($doc['file_path']);
    $disposition = ($mode === 'download') ? 'attachment' : 'inline';
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . filesize($doc['file_path']));
    header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode($fileName) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($doc['file_path']);
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
            SET status = 'Approved', rejection_reason = NULL, verified_by = :admin, verified_at = NOW(), updated_at = NOW()
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
                SET status = 'Rejected', rejection_reason = :reason, verified_by = :admin, verified_at = NOW(), updated_at = NOW()
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
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $landmark = trim($_POST['landmark'] ?? '');
    $state = trim($_POST['state'] ?? '');
    $country = trim($_POST['country'] ?? 'India');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['password_confirmation'] ?? '';

    if (empty($name) || empty($phone) || empty($address) || empty($landmark) || empty($state) || empty($password)) {
        $error = 'All fields are required.';
    } elseif (!preg_match('/^\+[1-9]\d{7,14}$/', $phone)) {
        $error = 'Phone must be in international format with country code (e.g. +919876543210).';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Password confirmation does not match.';
    } else {
        $chk = $pdo->prepare("SELECT id FROM users WHERE phone = :phone LIMIT 1");
        $chk->execute([':phone' => $phone]);
        if ($chk->fetch()) {
            $error = 'A seller with this phone number already exists.';
        } else {
            $rawInputEmail = trim($_POST['email'] ?? '');
            $email = '';
            if (!empty($rawInputEmail)) {
                if (!filter_var($rawInputEmail, FILTER_VALIDATE_EMAIL)) {
                    $error = 'The email address format is invalid.';
                } else {
                    $chkEmail = $pdo->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(:email) LIMIT 1");
                    $chkEmail->execute([':email' => $rawInputEmail]);
                    if ($chkEmail->fetch()) {
                        $error = 'A seller with this email address already exists.';
                    } else {
                        $email = strtolower($rawInputEmail);
                    }
                }
            }

            if (empty($error)) {
                if (empty($email)) {
                    $phoneDigits = preg_replace('/\D+/', '', $phone);
                    $email = 'seller_' . $phoneDigits . '@phone.vendor.local';
                }
                $hashed = hashPassword($password);

                $ins = $pdo->prepare("
                    INSERT INTO users (name, email, seller_name, phone, address, landmark, state, country, password, role, created_at, updated_at)
                    VALUES (:name, :email, :seller_name, :phone, :address, :landmark, :state, :country, :password, 'seller', NOW(), NOW())
                ");
                $ins->execute([
                    ':name' => $name,
                    ':email' => $email,
                    ':seller_name' => $name,
                    ':phone' => $phone,
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
}

// Handle edit seller
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_seller') {
    $sellerId = (int) ($_POST['seller_id'] ?? 0);
    $name = trim($_POST['seller_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $landmark = trim($_POST['landmark'] ?? '');
    $state = trim($_POST['state'] ?? '');
    $country = trim($_POST['country'] ?? 'India');
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (empty($sellerId) || empty($name) || empty($phone) || empty($address) || empty($state)) {
        $error = 'Seller ID, name, phone, address, and state are required.';
    } elseif (!preg_match('/^\+[1-9]\d{7,14}$/', $phone)) {
        $error = 'Phone must be in international format with country code (e.g. +919876543210).';
    } else {
        $chk = $pdo->prepare("SELECT id FROM users WHERE phone = :phone AND id != :id LIMIT 1");
        $chk->execute([':phone' => $phone, ':id' => $sellerId]);
        if ($chk->fetch()) {
            $error = 'This phone number is already registered to another account.';
        } else {
            $passwordToUpdate = null;
            if (!empty($newPassword)) {
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
                    ':phone' => $phone,
                    ':address' => $address,
                    ':landmark' => $landmark,
                    ':state' => $state,
                    ':country' => $country,
                ];
                if ($passwordToUpdate !== null) {
                    $sql .= ", password = :password";
                    $params[':password'] = $passwordToUpdate;
                }
                $sql .= ", updated_at = NOW() WHERE id = :id";
                $upd = $pdo->prepare($sql);
                $upd->execute($params);

                $success = "Seller #{$sellerId} profile updated successfully!";
            }
        }
    }
}

// Stats
$sellerCountStmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'seller'");
$sellerCount = (int) $sellerCountStmt->fetchColumn();

// GST Verification Stats
$gstCountsStmt = $pdo->query("
    SELECT
        COUNT(*) as total_gst,
        SUM(CASE WHEN status = 'Pending' OR status = 'Resubmitted' THEN 1 ELSE 0 END) as pending_gst,
        SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) as approved_gst,
        SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) as rejected_gst
    FROM seller_gst_details
");
$gstCounts = $gstCountsStmt->fetch();
$pendingGstCount = (int) ($gstCounts['pending_gst'] ?? 0);
$approvedGstCount = (int) ($gstCounts['approved_gst'] ?? 0);
$rejectedGstCount = (int) ($gstCounts['rejected_gst'] ?? 0);
$totalGstCount = (int) ($gstCounts['total_gst'] ?? 0);

// Fetch all sellers with GST details
$gstSellersStmt = $pdo->query("
    SELECT g.*, u.seller_name, u.name as user_name, u.phone, u.email,
           (SELECT COUNT(*) FROM seller_gst_documents d WHERE d.seller_id = g.seller_id) as doc_count,
           (SELECT COUNT(*) FROM seller_gst_documents d WHERE d.seller_id = g.seller_id AND (LOWER(d.mime_type) = 'application/pdf' OR d.original_name LIKE '%.pdf')) as pdf_count
    FROM seller_gst_details g
    JOIN users u ON g.seller_id = u.id
    ORDER BY (CASE WHEN g.status = 'Pending' OR g.status = 'Resubmitted' THEN 0 ELSE 1 END), g.updated_at DESC
    LIMIT 100
");
$gstSellers = $gstSellersStmt->fetchAll();

// Sellers list with product counts
$sellersStmt = $pdo->query("
    SELECT u.id, u.name, u.seller_name, u.email, u.phone, u.address, u.landmark, u.state, u.country, u.created_at,
           (SELECT COUNT(*) FROM products p WHERE p.seller_id = u.id AND p.is_active = 1) AS products_count
    FROM users u
    WHERE u.role = 'seller'
    ORDER BY u.id DESC
    LIMIT 50
");
$sellers = $sellersStmt->fetchAll();
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
        .top { background: #25204a; color: white; padding: 18px 5%; display: flex; justify-content: space-between; align-items: center; }
        .brand { font-size: 21px; font-weight: 800; }
        .wrap { max-width: 1050px; margin: 32px auto; padding: 0 18px; }
        .card { background: #fff; border-radius: 14px; padding: 24px; box-shadow: 0 8px 30px rgba(37,32,74,0.06); margin-bottom: 24px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; }
        .stat { font-size: 32px; font-weight: 800; color: #5c49d8; margin-top: 6px; }
        h1, h2 { margin-top: 0; color: #1e1b4b; }
        p.muted { color: #64748b; font-size: 14px; margin-top: -6px; }
        label { display: block; font-size: 13px; font-weight: 600; margin: 12px 0 6px; color: #334155; }
        input { width: 100%; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; outline: none; }
        input:focus { border-color: #6366f1; }
        button, .btn { background: #5b4bd8; color: #fff; border: 0; border-radius: 8px; padding: 12px 20px; font-weight: 700; cursor: pointer; text-decoration: none; display: inline-block; font-size: 14px; }
        button:hover, .btn:hover { background: #4a3cb8; }
        table { width: 100%; border-collapse: collapse; margin-top: 14px; }
        th, td { text-align: left; padding: 12px; border-bottom: 1px solid #f1f5f9; font-size: 14px; }
        th { color: #64748b; font-weight: 600; }
        .error { background: #fef2f2; color: #b91c1c; padding: 12px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
        .success { background: #ecfdf5; color: #047857; padding: 12px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
        .logout-btn { background: rgba(255,255,255,0.15); color: #fff; border: 1px solid rgba(255,255,255,0.3); padding: 8px 14px; border-radius: 6px; text-decoration: none; font-size: 13px; }
        .logout-btn:hover { background: rgba(255,255,255,0.25); }
        .btn-sm { background: #6366f1; color: white; border: none; border-radius: 6px; padding: 6px 12px; font-size: 12px; font-weight: 700; cursor: pointer; }
        .btn-sm:hover { background: #4f46e5; }
        .badge-prod { background: #e0e7ff; color: #4338ca; font-weight: 700; font-size: 12px; padding: 3px 8px; border-radius: 9999px; }
        /* Modal Styles */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(15, 23, 42, 0.7); backdrop-filter: blur(4px); z-index: 1000; align-items: center; justify-content: center; }
        .modal-overlay.active { display: flex; }
        .modal-box { background: #fff; width: 92%; max-width: 650px; max-height: 90vh; overflow-y: auto; border-radius: 16px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); border: 1px solid #e2e8f0; }
        .modal-header { padding: 20px 24px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; }
        .modal-header h3 { margin: 0; font-size: 18px; color: #1e1b4b; }
        .modal-close { background: transparent; border: none; font-size: 20px; font-weight: 700; color: #94a3b8; cursor: pointer; padding: 4px; }
        .modal-close:hover { color: #0f172a; }
        .modal-body { padding: 24px; }
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
        .btn-reject { background: #dc2626; color: white; border: none; border-radius: 8px; padding: 10px 18px; font-weight: 700; cursor: pointer; font-size: 14px; }
        .btn-reject:hover { background: #b91c1c; }
        .log-row { padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; display: flex; justify-content: space-between; gap: 12px; }
        .log-time { color: #64748b; font-size: 12px; min-width: 130px; }
    </style>
</head>
<body>
    <header class="top">
        <div class="brand">Vendor <span style="font-weight:400; opacity:.85;">Admin</span></div>
        <div>
            <span style="margin-right:15px; font-size:14px; opacity:.9;">Welcome, <?= htmlspecialchars($_SESSION['admin_name'] ?? 'Admin') ?></span>
            <a href="/admin/logout" class="logout-btn">Sign out</a>
        </div>
    </header>
    <main class="wrap">
        <h1>Dashboard</h1>
        <p class="muted">Manage your marketplace sellers, GST verifications, and compliance.</p>

        <?php if ($success): ?><div class="success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));">
            <section class="card">
                <div style="color:#64748b; font-size:13px; font-weight:600;">Registered Sellers</div>
                <div class="stat"><?= $sellerCount ?></div>
            </section>
            <section class="card" style="border-left: 4px solid #f59e0b;">
                <div style="color:#b45309; font-size:13px; font-weight:700;">Pending GST Review</div>
                <div class="stat" style="color:#b45309;"><?= $pendingGstCount ?></div>
            </section>
            <section class="card" style="border-left: 4px solid #10b981;">
                <div style="color:#15803d; font-size:13px; font-weight:700;">Approved GST</div>
                <div class="stat" style="color:#10b981;"><?= $approvedGstCount ?></div>
            </section>
            <section class="card" style="border-left: 4px solid #ef4444;">
                <div style="color:#b91c1c; font-size:13px; font-weight:700;">Rejected GST</div>
                <div class="stat" style="color:#ef4444;"><?= $rejectedGstCount ?></div>
            </section>
        </div>

        <section class="card">
            <h2>Create seller account</h2>
            <p class="muted">Phone number must include country code, e.g. +919876543210.</p>
            <form method="POST" action="/admin">
                <input type="hidden" name="action" value="create_seller">
                <div class="grid">
                    <div>
                        <label>Seller name</label>
                        <input name="seller_name" value="<?= htmlspecialchars($_POST['seller_name'] ?? '') ?>" required maxlength="120">
                    </div>
                    <div>
                        <label>Phone (+country code)</label>
                        <input name="phone" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" required placeholder="+919876543210">
                    </div>
                    <div>
                        <label>Address</label>
                        <input name="address" value="<?= htmlspecialchars($_POST['address'] ?? '') ?>" required>
                    </div>
                    <div>
                        <label>Landmark</label>
                        <input name="landmark" value="<?= htmlspecialchars($_POST['landmark'] ?? '') ?>" required>
                    </div>
                    <div>
                        <label>State</label>
                        <input name="state" value="<?= htmlspecialchars($_POST['state'] ?? '') ?>" required>
                    </div>
                    <div>
                        <label>Country</label>
                        <input name="country" value="<?= htmlspecialchars($_POST['country'] ?? 'India') ?>" required>
                    </div>
                    <div>
                        <label>Password (min 8 characters)</label>
                        <input type="password" name="password" required minlength="8" autocomplete="new-password">
                    </div>
                    <div>
                        <label>Confirm password</label>
                        <input type="password" name="password_confirmation" required minlength="8" autocomplete="new-password">
                    </div>
                </div>
                <br>
                <button type="submit">Create seller</button>
            </form>
        </section>

        <section class="card">
            <h2>Seller Accounts</h2>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Seller Name</th>
                        <th>Phone</th>
                        <th>State</th>
                        <th>Products</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($sellers)): ?>
                        <tr><td colspan="7" style="color:#94a3b8; text-align:center; padding:24px;">No sellers registered yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($sellers as $s): ?>
                            <tr>
                                <td>#<?= $s['id'] ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($s['seller_name'] ?? $s['name']) ?></strong>
                                </td>
                                <td><?= htmlspecialchars($s['phone'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($s['state'] ?? '—') ?></td>
                                <td><span class="badge-prod"><?= (int)($s['products_count'] ?? 0) ?> products</span></td>
                                <td><?= htmlspecialchars(substr($s['created_at'] ?? '', 0, 10)) ?></td>
                                <td>
                                    <button type="button" class="btn-sm" onclick='openSellerModal(<?= htmlspecialchars(json_encode($s, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, "UTF-8") ?>)'>View / Edit</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>

        <!-- Seller GST Verification Section -->
        <section class="card" id="gstSection" style="margin-top: 24px;">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                <div>
                    <h2>Seller GST Verifications</h2>
                    <p class="muted">Review uploaded GST registration certificates (PDF / JPG / PNG), inspect details, approve, or reject with reason.</p>
                </div>
            </div>

            <div class="tabs-bar">
                <button type="button" class="tab-btn active" onclick="filterGstRows('all', this)">All Submissions (<?= $totalGstCount ?>)</button>
                <button type="button" class="tab-btn" onclick="filterGstRows('pending', this)">Pending Review (<?= $pendingGstCount ?>)</button>
                <button type="button" class="tab-btn" onclick="filterGstRows('approved', this)">Approved (<?= $approvedGstCount ?>)</button>
                <button type="button" class="tab-btn" onclick="filterGstRows('rejected', this)">Rejected (<?= $rejectedGstCount ?>)</button>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Seller</th>
                        <th>GSTIN</th>
                        <th>Business Name</th>
                        <th>State / PIN</th>
                        <th>Documents</th>
                        <th>Status</th>
                        <th>Submitted At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="gstTableBody">
                    <?php if (empty($gstSellers)): ?>
                        <tr><td colspan="8" style="color:#94a3b8; text-align:center; padding:28px;">No sellers have submitted GST details yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($gstSellers as $g): ?>
                            <?php
                                $st = strtolower($g['status']);
                                if ($st === 'resubmitted') $st = 'pending';
                                $badgeClass = ($st === 'approved') ? 'badge-approved' : (($st === 'rejected') ? 'badge-rejected' : 'badge-pending');
                            ?>
                            <tr class="gst-row" data-status="<?= $st ?>">
                                <td>
                                    <strong><?= htmlspecialchars($g['seller_name'] ?? $g['user_name'] ?? 'Seller #' . $g['seller_id']) ?></strong>
                                    <div style="font-size:12px; color:#64748b;">ID: #<?= (int)$g['seller_id'] ?> &bull; <?= htmlspecialchars($g['phone'] ?? '') ?></div>
                                </td>
                                <td><span class="gstin-pill"><?= htmlspecialchars($g['gstin']) ?></span></td>
                                <td><?= htmlspecialchars($g['business_name']) ?></td>
                                <td><?= htmlspecialchars($g['state']) ?> <span style="color:#94a3b8; font-size:12px;">(<?= htmlspecialchars($g['pincode']) ?>)</span></td>
                                <td>
                                    <strong><?= (int)$g['doc_count'] ?></strong> file<?= (int)$g['doc_count'] !== 1 ? 's' : '' ?>
                                    <?php if ((int)$g['pdf_count'] > 0): ?>
                                        <span class="badge-pdf" title="Contains PDF Certificate">PDF</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="<?= $badgeClass ?>"><?= htmlspecialchars($g['status']) ?></span></td>
                                <td><?= htmlspecialchars(substr($g['submitted_at'] ?? $g['updated_at'] ?? '', 0, 16)) ?></td>
                                <td>
                                    <button type="button" class="btn-sm" style="background:#4f46e5;" onclick="openGstModal(<?= (int)$g['seller_id'] ?>, '<?= htmlspecialchars(addslashes($g['seller_name'] ?? $g['user_name'] ?? 'Seller'), ENT_QUOTES) ?>')">Review & Verify</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
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
                            <input name="phone" id="formPhone" required placeholder="+919876543210">
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

    <script>
        function openSellerModal(seller) {
            document.getElementById('modalTitle').innerText = 'Seller Profile — ' + (seller.seller_name || seller.name || 'Seller');
            document.getElementById('viewSellerId').innerText = '#' + seller.id;
            document.getElementById('viewCreatedAt').innerText = (seller.created_at || '').slice(0, 10) || '—';

            document.getElementById('formSellerId').value = seller.id;
            document.getElementById('formSellerName').value = seller.seller_name || seller.name || '';
            document.getElementById('formPhone').value = seller.phone || '';
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

        // GST Verification Table Filter
        function filterGstRows(status, btn) {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
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
    </script>
</body>
</html>
