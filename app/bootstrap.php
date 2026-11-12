<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Thimphu');
define('APP_ROOT', dirname(__DIR__));

$configFile = APP_ROOT . '/app/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('Missing app/config.php - copy app/config.sample.php to app/config.php and edit it.');
}
$GLOBALS['config'] = require $configFile;

if (!empty($GLOBALS['config']['debug'])) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED);
}

require_once APP_ROOT . '/app/helpers.php';
require_once APP_ROOT . '/app/auth.php';
require_once APP_ROOT . '/app/layout.php';

if (PHP_SAPI !== 'cli') {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'");

    $secure = cfg('cookie_secure');
    if ($secure === null) {
        $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    }
    session_name('cnr_att');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => (bool)$secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    // Idle timeout: 2 hours
    if (!empty($_SESSION['last_seen']) && time() - $_SESSION['last_seen'] > 7200) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['last_seen'] = time();
}
