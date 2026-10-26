<?php

/**
 * Pure PHP: Admin Login Page & Form Handler
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/auth.php';

$pdo = getDatabaseConnection();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($email) || empty($password)) {
        $error = 'Email and password are required.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :email AND role = 'admin' LIMIT 1");
        $stmt->execute([':email' => $email]);
        $admin = $stmt->fetch();

        if ($admin && verifyPassword($password, $admin['password'])) {
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_id'] = $admin['id'];
            $_SESSION['admin_name'] = $admin['name'];
            header('Location: /admin');
            exit;
        } else {
            $error = 'Invalid admin credentials.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Venter Admin — Sign In</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f4f5fb; color: #20243a; }
        .top { background: #25204a; color: white; padding: 18px 5%; display: flex; justify-content: space-between; align-items: center; }
        .brand { font-size: 21px; font-weight: 800; }
        .wrap { max-width: 440px; margin: 60px auto; padding: 0 18px; }
        .card { background: #fff; border-radius: 14px; padding: 30px; box-shadow: 0 8px 30px rgba(37,32,74,0.08); }
        h1 { margin-top: 0; font-size: 24px; color: #1e1b4b; }
        p.muted { color: #64748b; font-size: 14px; margin-bottom: 24px; }
        label { display: block; font-size: 13px; font-weight: 600; margin: 16px 0 6px; color: #334155; }
        input { width: 100%; padding: 12px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; outline: none; transition: border-color .2s; }
        input:focus { border-color: #6366f1; }
        button { width: 100%; background: #5b4bd8; color: #fff; border: 0; border-radius: 8px; padding: 13px; font-size: 15px; font-weight: 700; cursor: pointer; margin-top: 24px; transition: background .2s; }
        button:hover { background: #4a3cb8; }
        .error { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; padding: 12px; border-radius: 8px; font-size: 14px; margin-bottom: 20px; }
    </style>
</head>
<body>
    <header class="top">
        <div class="brand">Venter <span style="font-weight:400; opacity:.85;">Admin</span></div>
    </header>
    <main class="wrap">
        <div class="card">
            <h1>Admin sign in</h1>
            <p class="muted">Sign in to manage Venter sellers and catalog.</p>

            <?php if ($error): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="/admin/login">
                <label>Admin email</label>
                <input type="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? 'admin@venter.app') ?>" required autofocus autocomplete="username">

                <label>Password</label>
                <input type="password" name="password" required autocomplete="current-password">

                <button type="submit">Sign in</button>
            </form>
        </div>
    </main>
</body>
</html>
