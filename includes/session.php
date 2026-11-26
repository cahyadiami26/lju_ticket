<?php
/**
 * SSO Session — LJU Apps
 * Session di-handle oleh Portal SSO (domain .lju.co.id).
 * Jika belum login, redirect ke Portal SSO dengan parameter target.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/url.php';

ini_set('session.cookie_domain', '.lju.co.id');
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

// Proteksi halaman: jika belum login → redirect ke Portal SSO
if (empty($_SESSION['user_id'])) {
    // Mode development: arahkan ke demo login (tanpa SSO)
    if (defined('DEV_MODE') && DEV_MODE === true) {
        header('Location: ' . url('demo-login.php'));
        exit;
    }

    $current_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
        . '://' . ($_SERVER['HTTP_HOST'] ?? 'helpdesk.lju.co.id')
        . ($_SERVER['REQUEST_URI'] ?? '/');

    header('Location: ' . SSO_URL
        . '?target_system=' . urlencode(APP_URL)
        . '&redirect_after_login=' . urlencode($current_url));
    exit;
}
