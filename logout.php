<?php
/**
 * Logout — hapus session lokal helpdesk.
 * Session SSO (LJU_APPS) di domain .lju.co.id akan ikut terhapus
 * karena cookie-nya sama; user diarahkan kembali ke Portal SSO.
 */
require_once __DIR__ . '/includes/constants.php';
require_once __DIR__ . '/includes/url.php';

session_name('LJU_APPS');
session_set_cookie_params([
    'lifetime' => 86400,
    'path'     => '/',
    'domain'   => '.lju.co.id',
    'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly'  => true,
    'samesite' => 'Lax'
]);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();

header('Location: ' . (defined('DEV_MODE') && DEV_MODE === true ? url('demo-login.php') : SSO_URL));
exit;
