<?php
/**
 * Koneksi Database (PDO)
 * - $pdo        : database helpdesk (aplikasi ini)
 * - $usersPdo   : database users (satu mesin, database berbeda) — opsional
 *
 * pdo_connect() mengembalikan PDO atau null (tidak pernah die()),
 * supaya koneksi opsional benar-benar bisa gagal tanpa mematikan halaman.
 */

require_once __DIR__ . '/constants.php';

if (!function_exists('pdo_connect')) {
    /**
     * @return PDO|null  null jika koneksi gagal (detail error masuk error_log)
     */
    function pdo_connect($host, $name, $user, $pass, $charset = 'utf8mb4') {
        $dsn = "mysql:host={$host};dbname={$name};charset={$charset}";
        try {
            return new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            // Jangan tampilkan detail error ke user; catat ke log server
            error_log('[DB] ' . $e->getMessage());
            return null;
        }
    }
}

// --- Validasi konfigurasi database (pesan jelas, bukan fatal error) ---
$missingDbConfig = [];
foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS',
          'USERS_DB_HOST', 'USERS_DB_NAME', 'USERS_DB_USER', 'USERS_DB_PASS'] as $constName) {
    if (!defined($constName)) {
        $missingDbConfig[] = $constName;
    }
}
if ($missingDbConfig) {
    http_response_code(500);
    exit('Konfigurasi database belum lengkap. Konstanta berikut belum ada di <code>config/config.php</code>: '
        . implode(', ', array_map('htmlspecialchars', $missingDbConfig))
        . '. Bandingkan config.php Anda dengan <code>config/config.example.php</code>, '
        . 'atau buka <a href="' . htmlspecialchars(APP_URL) . '/install-check.php">install-check.php</a>.');
}

// --- Database helpdesk: WAJIB ---
$pdo = pdo_connect(DB_HOST, DB_NAME, DB_USER, DB_PASS, DB_CHARSET);
if (!$pdo) {
    http_response_code(503);
    exit('Koneksi database helpdesk gagal. Periksa konfigurasi di <code>config/config.php</code> dan hubungi tim IT.');
}

// --- Database users: opsional (DB terpisah, satu mesin) ---
$usersPdo = pdo_connect(USERS_DB_HOST, USERS_DB_NAME, USERS_DB_USER, USERS_DB_PASS, USERS_DB_CHARSET);
