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
$existingAdminJwt = !empty($_COOKIE['admin_token']) ? verifyJwtToken((string) $_COOKIE['admin_token'], getJwtSecret()) : null;
if (!empty($_SESSION['admin_logged_in']) || ($existingAdminJwt && strtolower((string) ($existingAdminJwt['role'] ?? '')) === 'admin')) {
    if (empty($_SESSION['admin_logged_in'])) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_id'] = (int) ($existingAdminJwt['id'] ?? 1);
        $_SESSION['admin_email'] = $existingAdminJwt['email'] ?? ($_COOKIE['admin_email'] ?? 'admin@vendor.app');
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
            $stmt = $pdo->prepare("SELECT * FROM users WHERE (LOWER(email) = :email1 OR (:email2 IN ('admin@vendor.app', 'admin@venter.app', 'admin') AND LOWER(email) IN ('admin@vendor.app', 'admin@venter.app'))) AND role = 'admin' LIMIT 1");
            $stmt->execute([':email1' => $email, ':email2' => $email]);
            $admin = $stmt->fetch();

            // Auto-provision admin user if empty or fresh database
            if (!$admin && ($email === 'admin@vendor.app' || $email === 'admin@venter.app' || $email === 'admin') && $password === 'Admin123!') {
                $adminHash = password_hash('Admin123!', PASSWORD_BCRYPT);
                $chkOne = $pdo->query("SELECT id FROM users WHERE id = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                if ($chkOne) {
                    $upOne = $pdo->prepare("UPDATE users SET name = 'Vendor Administrator', email = 'admin@vendor.app', password = :pass, role = 'admin', updated_at = CURRENT_TIMESTAMP WHERE id = 1");
                    $upOne->execute([':pass' => $adminHash]);
                } else {
                    $insertStmt = $pdo->prepare("
                        INSERT INTO users (id, name, email, password, role, created_at, updated_at)
                        VALUES (1, 'Vendor Administrator', 'admin@vendor.app', :pass, 'admin', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                    ");
                    $insertStmt->execute([':pass' => $adminHash]);
                }

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

                // Mint Admin JWT + browser cookies so API endpoints and PHP/Node sessions stay authenticated
                $adminJwt = createToken(
                    $pdo,
                    (int) $admin['id'],
                    $admin['phone'] ?? null,
                    'admin-dashboard',
                    $admin['email'] ?? null,
                    'admin',
                    isset($admin['token_version']) ? (int) $admin['token_version'] : 1
                );
                setcookie('admin_logged_in', '1', time() + 86400 * 7, '/');
                setcookie('admin_email', $admin['email'], time() + 86400 * 7, '/');
                setcookie('admin_token', $adminJwt, time() + 86400 * 7, '/');

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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body {
            min-height: 100vh;
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
            display: flex;
            flex-direction: column;
        }
        .login-card {
            max-width: 440px;
            width: 100%;
            border-radius: 1rem;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-dark bg-dark shadow-sm">
        <div class="container">
            <span class="navbar-brand fw-bold d-flex align-items-center gap-2">
                <i class="bi bi-shop-window text-primary"></i>
                Vendor <span class="badge bg-primary fw-semibold">Admin Panel</span>
            </span>
        </div>
    </nav>

    <main class="flex-grow-1 d-flex align-items-center justify-content-center p-3 p-md-4">
        <div class="card login-card border-0 shadow-lg">
            <div class="card-body p-4 p-sm-5">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <span class="rounded-3 bg-primary bg-opacity-10 text-primary p-2 d-inline-flex align-items-center justify-content-center">
                        <i class="bi bi-shield-lock-fill fs-4"></i>
                    </span>
                    <div>
                        <h1 class="h4 fw-bold mb-0 text-dark">Admin sign in</h1>
                        <small class="text-body-secondary">Sign in with your master administrator credentials.</small>
                    </div>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger d-flex align-items-start gap-2 py-2 px-3 small" role="alert">
                        <i class="bi bi-exclamation-triangle-fill mt-1"></i>
                        <div><?= htmlspecialchars($error) ?></div>
                    </div>
                <?php endif; ?>

                <form method="POST" action="/admin/login" class="mt-3">
                    <div class="mb-3">
                        <label for="adminEmailInput" class="form-label fw-semibold small text-secondary">Admin email</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light"><i class="bi bi-envelope text-secondary"></i></span>
                            <input type="email" id="adminEmailInput" name="email" class="form-control" value="<?= htmlspecialchars($_POST['email'] ?? 'admin@vendor.app') ?>" required autofocus autocomplete="username">
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="adminPasswordInput" class="form-label fw-semibold small text-secondary">Password</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light"><i class="bi bi-key text-secondary"></i></span>
                            <input type="password" id="adminPasswordInput" name="password" class="form-control" value="<?= htmlspecialchars($_POST['password'] ?? 'Admin123!') ?>" required autocomplete="current-password">
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 fw-semibold py-2">
                        <i class="bi bi-box-arrow-in-right me-1"></i> Sign in to Dashboard
                    </button>
                </form>

                <div class="alert alert-light border mt-4 mb-0 small text-body-secondary">
                    <div class="fw-semibold text-dark mb-1"><i class="bi bi-info-circle me-1 text-primary"></i> Default Hostinger Credentials</div>
                    <div>Email: <code>admin@vendor.app</code></div>
                    <div>Password: <code>Admin123!</code></div>
                </div>
            </div>
        </div>
    </main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
