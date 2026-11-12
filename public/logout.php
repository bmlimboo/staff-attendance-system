<?php
require __DIR__ . '/../app/bootstrap.php';
if (is_post()) {
    csrf_verify();
    if (current_user()) {
        audit('logout');
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']);
    }
    session_destroy();
}
redirect('/login.php');
