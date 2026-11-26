<?php
/**
 * Kompatibilitas Konstanta (defensif)
 *
 * config/config.php di server sering dibuat sejak Batch 1, sehingga konstanta
 * yang ditambahkan pada batch berikutnya (ENCRYPT_KEY, MIKROTIK_*, LOGO_URL, dst.)
 * belum ada. Tanpa file ini, memakai konstanta yang belum didefinisikan
 * menghasilkan fatal error: "Undefined constant ...".
 *
 * File ini hanya mengisi nilai default untuk konstanta yang BELUM ada
 * (memakai defined()), sehingga config.php tetap menjadi sumber utama
 * dan tidak ada nilai yang ditimpa.
 */

require_once __DIR__ . '/../config/config.php';

// --- Identitas aplikasi ---
if (!defined('APP_NAME'))  define('APP_NAME', 'LJU IT Helpdesk');
if (!defined('SSO_URL'))   define('SSO_URL', 'https://lju.co.id/dashboard/');
if (!defined('LOGO_URL'))  define('LOGO_URL', 'https://dms.lju.co.id/assets/img/logo.png');
if (!defined('AVATAR_BASE_URL')) define('AVATAR_BASE_URL', 'https://dms.lju.co.id/uploads/avatars/');

// APP_URL: pakai konfigurasi bila ada, jika tidak susun dari request saat ini
if (!defined('APP_URL')) {
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'helpdesk.lju.co.id';
    $dir    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    // buang sub-folder modul (mis. /tickets, /admin, /reports) agar APP_URL tetap base aplikasi
    foreach (['/tickets', '/admin', '/reports', '/monitoring', '/network', '/settings', '/cron'] as $sub) {
        if ($dir === $sub || substr($dir, -strlen($sub)) === $sub) {
            $dir = substr($dir, 0, -strlen($sub));
        }
    }
    define('APP_URL', $scheme . '://' . $host . rtrim($dir, '/'));
}

// --- Mode & basis data ---
// Default production-safe: SSO wajib (true hanya jika config.php memang mengaktifkan)
if (!defined('DEV_MODE')) define('DEV_MODE', false);
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');
if (!defined('USERS_DB_CHARSET')) define('USERS_DB_CHARSET', 'utf8mb4');

// --- Upload ---
if (!defined('UPLOAD_DIR')) define('UPLOAD_DIR', __DIR__ . '/../uploads/tickets/');
if (!defined('UPLOAD_MAX_SIZE')) define('UPLOAD_MAX_SIZE', 10 * 1024 * 1024);
if (!defined('UPLOAD_ALLOWED_EXT')) define('UPLOAD_ALLOWED_EXT', 'jpg,jpeg,png,gif,pdf,doc,docx,xls,xlsx,txt,zip,mp4');

// --- Monitoring MikroTik (Batch 7) ---
if (!defined('MIKROTIK_POLL_SECONDS')) define('MIKROTIK_POLL_SECONDS', 60);
if (!defined('MIKROTIK_HISTORY_DAYS')) define('MIKROTIK_HISTORY_DAYS', 7);

// ENCRYPT_KEY sengaja TIDAK diberi default palsu: nilai lemah lebih berbahaya
// daripada tidak ada. includes/mikrotik.php sudah menangani kondisi belum diisi,
// dan menu Pengaturan akan menampilkan peringatan.
