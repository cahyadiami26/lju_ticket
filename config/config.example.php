<?php
/**
 * LJU IT Helpdesk & Ticketing System — Konfigurasi
 * Copy file ini menjadi config.php lalu isi sesuai environment server.
 * JANGAN commit config.php ke Git (sudah di-.gitignore).
 */

// --- Mode development ---
// true  = tampilkan demo-login.php untuk testing lokal tanpa SSO
// false = WAJIB di production (selalu lewat SSO)
define('DEV_MODE', true);

// --- Aplikasi ---
define('APP_NAME', 'LJU IT Helpdesk');
define('APP_URL', 'https://helpdesk.lju.co.id');
define('SSO_URL', 'https://lju.co.id/dashboard/');
define('LOGO_URL', 'https://dms.lju.co.id/assets/img/logo.png');
define('AVATAR_BASE_URL', 'https://dms.lju.co.id/uploads/avatars/');

// --- Database Helpdesk (database aplikasi ini) ---
define('DB_HOST', 'localhost');
define('DB_NAME', 'lju_helpdesk');
define('DB_USER', 'lju_helpdesk');
define('DB_PASS', 'ganti_password_anda');
define('DB_CHARSET', 'utf8mb4');

// --- Database Users (satu mesin, database berbeda — berisi tabel `users`) ---
define('USERS_DB_HOST', 'localhost');
define('USERS_DB_NAME', 'lju_users'); // sesuaikan nama database SSO/users
define('USERS_DB_USER', 'lju_users');
define('USERS_DB_PASS', 'ganti_password_anda');
define('USERS_DB_CHARSET', 'utf8mb4');

// --- Upload ---
define('UPLOAD_DIR', __DIR__ . '/../uploads/tickets/');
define('UPLOAD_MAX_SIZE', 10 * 1024 * 1024); // 10 MB
define('UPLOAD_ALLOWED_EXT', 'jpg,jpeg,png,gif,pdf,doc,docx,xls,xlsx,txt,zip,mp4');

// --- Timezone ---
date_default_timezone_set('Asia/Jakarta');
