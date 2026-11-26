<?php
/**
 * Koneksi Database (PDO)
 * - $pdo        : database helpdesk (aplikasi ini)
 * - $usersPdo   : database users (satu mesin, database berbeda)
 */

require_once __DIR__ . '/../config/config.php';

function pdo_connect($host, $name, $user, $pass, $charset = 'utf8mb4') {
    $dsn = "mysql:host={$host};dbname={$name};charset={$charset}";
    try {
        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        // Jangan tampilkan detail error ke user di production
        error_log('[DB] ' . $e->getMessage());
        die('Koneksi database gagal. Silakan hubungi tim IT.');
    }
}

$pdo = pdo_connect(DB_HOST, DB_NAME, DB_USER, DB_PASS, DB_CHARSET);

// Koneksi ke database users bersifat opsional saat development
// (agar halaman tetap bisa dibuka walau DB users belum dikonfigurasi).
$usersPdo = null;
try {
    $usersPdo = pdo_connect(USERS_DB_HOST, USERS_DB_NAME, USERS_DB_USER, USERS_DB_PASS, USERS_DB_CHARSET);
} catch (Throwable $e) {
    $usersPdo = null;
}
