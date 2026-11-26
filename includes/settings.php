<?php
/**
 * Pengaturan aplikasi berbasis tabel `system_settings` (Batch 5).
 * Semua nilai disimpan sebagai teks; helper meng-cast sesuai kebutuhan.
 */

require_once __DIR__ . '/db.php';

/** Ambil semua pengaturan (cached per request). */
function get_settings($pdo, $refresh = false) {
    static $cache = null;
    if ($cache !== null && !$refresh) return $cache;
    $cache = [];
    try {
        foreach ($pdo->query('SELECT setting_key, setting_value FROM system_settings')->fetchAll() as $row) {
            $cache[$row['setting_key']] = $row['setting_value'];
        }
    } catch (Throwable $e) {
        error_log('[SETTINGS] ' . $e->getMessage());
    }
    return $cache;
}

/** Satu nilai pengaturan dengan default. */
function setting($pdo, $key, $default = null) {
    $all = get_settings($pdo);
    return array_key_exists($key, $all) && $all[$key] !== null && $all[$key] !== '' ? $all[$key] : $default;
}

/** Nilai boolean pengaturan. */
function setting_bool($pdo, $key, $default = false) {
    $v = setting($pdo, $key, $default ? '1' : '0');
    return in_array((string)$v, ['1', 'true', 'on', 'yes'], true);
}

/** Simpan banyak pengaturan sekaligus. */
function save_settings($pdo, array $values) {
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        foreach ($values as $k => $v) {
            $stmt->execute([$k, (string)$v]);
        }
        get_settings($pdo, true);
        return ['ok' => true, 'message' => 'Pengaturan disimpan.'];
    } catch (Throwable $e) {
        error_log('[SETTINGS SAVE] ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Gagal menyimpan pengaturan.'];
    }
}
