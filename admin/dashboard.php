<?php

/**
 * Pure PHP: Admin Dashboard & Seller Management
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['admin_logged_in'])) {
    header('Location: /admin/login');
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/auth.php';

$pdo = getDatabaseConnection();
$success = null;
$error = null;

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
            $phoneDigits = preg_replace('/\D+/', '', $phone);
            $email = 'seller_' . $phoneDigits . '@phone.venter.local';
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

// Stats
$sellerCountStmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'seller'");
$sellerCount = (int) $sellerCountStmt->fetchColumn();

// Sellers list
$sellersStmt = $pdo->query("SELECT id, name, seller_name, phone, state, created_at FROM users WHERE role = 'seller' ORDER BY id DESC LIMIT 50");
$sellers = $sellersStmt->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Venter Admin Dashboard</title>
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
    </style>
</head>
<body>
    <header class="top">
        <div class="brand">Venter <span style="font-weight:400; opacity:.85;">Admin</span></div>
        <div>
            <span style="margin-right:15px; font-size:14px; opacity:.9;">Welcome, <?= htmlspecialchars($_SESSION['admin_name'] ?? 'Admin') ?></span>
            <a href="/admin/logout" class="logout-btn">Sign out</a>
        </div>
    </header>
    <main class="wrap">
        <h1>Dashboard</h1>
        <p class="muted">Manage your marketplace sellers and operations.</p>

        <?php if ($success): ?><div class="success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <div class="grid">
            <section class="card">
                <div style="color:#64748b; font-size:13px; font-weight:600;">Registered Sellers</div>
                <div class="stat"><?= $sellerCount ?></div>
            </section>
            <section class="card">
                <div style="color:#64748b; font-size:13px; font-weight:600;">System Mode</div>
                <div style="font-size: 18px; font-weight:700; color:#10b981; margin-top:8px;">Pure Native PHP (Zero Dependencies)</div>
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
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($sellers)): ?>
                        <tr><td colspan="5" style="color:#94a3b8; text-align:center; padding:24px;">No sellers registered yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($sellers as $s): ?>
                            <tr>
                                <td>#<?= $s['id'] ?></td>
                                <td><strong><?= htmlspecialchars($s['seller_name'] ?? $s['name']) ?></strong></td>
                                <td><?= htmlspecialchars($s['phone'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($s['state'] ?? '—') ?></td>
                                <td><?= htmlspecialchars(substr($s['created_at'] ?? '', 0, 10)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>
    </main>
</body>
</html>
