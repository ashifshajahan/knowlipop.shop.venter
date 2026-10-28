<?php

/**
 * Pure PHP: Admin Logout (Hostinger Production)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();

// Clear authentication cookies
setcookie('admin_logged_in', '', time() - 3600, '/');
setcookie('admin_email', '', time() - 3600, '/');
setcookie('admin_token', '', time() - 3600, '/');

header('Location: /admin/login');
exit;
