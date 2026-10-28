<?php

/**
 * Pure PHP: Admin Login Page & Form Handler (Hostinger Production)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/auth.php';

$pdo = getDatabaseConnection();
$error = null;

// Already logged in? Redirect to dashboard
if (!empty($_SESSION['admin_logged_in']) || !empty($_COOKIE['admin_logged_in'])) {
    if (empty($_SESSION['admin_logged_in'])) {
        $_SESSION['admin_logged_in'] = true;
    }
    header('Location: /admin');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = trim($_POST['password'] ?? '');

    if (empty($email) || empty($password)) {
        $error = 'Email and password are required.';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE (LOWER(email) = :email OR LOWER(email) IN ('admin@vendor.app', 'admin@venter.app')) AND role = 'admin' LIMIT 1");
            $stmt->execute([':email' => $email]);
            $admin = $stmt->fetch();

            // Auto-provision admin user if empty or fresh database
            if (!$admin && ($email === 'admin@vendor.app' || $email === 'admin@venter.app' || $email === 'admin') && $password === 'Admin123!') {
                $adminHash = password_hash('Admin123!', PASSWORD_BCRYPT);
                $insertStmt = $pdo->prepare("
                    INSERT INTO users (id, name, email, password, role, created_at, updated_at)
                    VALUES (1, 'Vendor Administrator', 'admin@vendor.app', :pass, 'admin', NOW(), NOW())
                    ON DUPLICATE KEY UPDATE name = VALUES(name), email = VALUES(email), password = VALUES(password), role = 'admin'
                ");
                $insertStmt->execute([':pass' => $adminHash]);

                $stmt = $pdo->prepare("SELECT * FROM users WHERE (LOWER(email) = 'admin@vendor.app' OR LOWER(email) = 'admin@venter.app') AND role = 'admin' LIMIT 1");
                $stmt->execute();
                $admin = $stmt->fetch();
            }

            // Auto-sync password hash if Admin123! is entered
            if ($admin && ($email === 'admin@vendor.app' || $email === 'admin@venter.app' || $email === 'admin') && $password === 'Admin123!') {
                if (!password_verify($password, $admin['password'])) {
                    $adminHash = password_hash('Admin123!', PASSWORD_BCRYPT);
                    $upStmt = $pdo->prepare("UPDATE users SET password = :pass WHERE id = :id");
                    $upStmt->execute([':pass' => $adminHash, ':id' => $admin['id']]);
                    $admin['password'] = $adminHash;
                }
            }

            if ($admin && password_verify($password, $admin['password'])) {
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_id'] = $admin['id'];
                $_SESSION['admin_name'] = $admin['name'];
                $_SESSION['admin_email'] = $admin['email'];

                // Set browser cookies so sessions survive across PHP and Node.js
                setcookie('admin_logged_in', '1', time() + 86400 * 7, '/');
                setcookie('admin_email', $admin['email'], time() + 86400 * 7, '/');

                header('Location: /admin');
                exit;
            } else {
                $error = 'Invalid admin credentials. Please use admin@vendor.app / Admin123!';
            }
        } catch (PDOException $e) {
            $error = 'Database error during authentication: ' . $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Vendor Admin — Sign In</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0f172a; color: #f8fafc; min-height: 100vh; display: flex; flex-direction: column; justify-content: center; align-items: center; padding: 20px; }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 16px; padding: 36px; max-width: 440px; width: 100%; box-shadow: 0 20px 40px rgba(0,0,0,0.4); }
        .brand { font-size: 20px; font-weight: 800; color: #38bdf8; margin-bottom: 24px; display: flex; align-items: center; gap: 8px; }
        .badge { background: rgba(56,189,248,0.15); border: 1px solid rgba(56,189,248,0.3); color: #38bdf8; font-size: 11px; padding: 3px 8px; border-radius: 999px; text-transform: uppercase; font-weight: 700; }
        h1 { margin: 0 0 8px; font-size: 24px; color: #ffffff; }
        p.muted { color: #94a3b8; font-size: 14px; margin: 0 0 24px; line-height: 1.5; }
        label { display: block; font-size: 13px; font-weight: 600; margin: 16px 0 6px; color: #cbd5e1; }
        input { width: 100%; padding: 12px 14px; background: #0f172a; border: 1px solid #334155; border-radius: 8px; font-size: 14px; color: #fff; outline: none; transition: border-color .2s; }
        input:focus { border-color: #38bdf8; }
        button { width: 100%; background: #0284c7; color: #fff; border: 0; border-radius: 8px; padding: 13px; font-size: 15px; font-weight: 700; cursor: pointer; margin-top: 24px; transition: background .2s; }
        button:hover { background: #0369a1; }
        .error { background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.4); color: #fca5a5; padding: 12px 14px; border-radius: 8px; font-size: 13px; margin-bottom: 20px; line-height: 1.4; }
        .hint-box { margin-top: 24px; padding: 14px; background: rgba(56,189,248,0.05); border: 1px dashed rgba(56,189,248,0.3); border-radius: 8px; font-size: 12px; color: #94a3b8; }
        .hint-box strong { color: #e2e8f0; }
    </style>
</head>
<body>
    <div class="card">
        <div class="brand">
            <span>Vendor</span>
            <span class="badge">Admin Panel</span>
        </div>
        <h1>Admin sign in</h1>
        <p class="muted">Sign in with your master administrator credentials.</p>

        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="/admin/login">
            <label>Admin email</label>
            <input type="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? 'admin@vendor.app') ?>" required autofocus autocomplete="username">

            <label>Password</label>
            <input type="password" name="password" value="<?= htmlspecialchars($_POST['password'] ?? 'Admin123!') ?>" required autocomplete="current-password">

            <button type="submit">Sign in to Dashboard</button>
        </form>

        <div class="hint-box">
            <strong>Default Hostinger Credentials:</strong><br>
            Email: <code>admin@vendor.app</code><br>
            Password: <code>Admin123!</code>
        </div>
    </div>
</body>
</html>
