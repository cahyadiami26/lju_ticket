<?php
/**
 * Menentukan BASE_URL aplikasi secara otomatis.
 * Dipakai agar asset (CSS/JS) dan link tetap benar dari subfolder mana pun
 * (mis. tickets/create.php, tickets/view.php).
 */
if (!function_exists('app_base_url')) {
    function app_base_url() {
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
        $dir = rtrim(dirname($script), '/');

        // Folder aplikasi = bagian sebelum segmen pertama yang dikenal
        $known = ['tickets', 'admin', 'network', 'reports', 'monitoring', 'settings', 'ajax', 'cron'];
        $parts = $dir === '' ? [] : explode('/', trim($dir, '/'));
        foreach ($parts as $i => $seg) {
            if (in_array($seg, $known, true)) {
                $parts = array_slice($parts, 0, $i);
                break;
            }
        }
        $base = '/' . implode('/', $parts);
        return $base === '/' ? '' : rtrim($base, '/');
    }
}

if (!function_exists('url')) {
    /** URL absolut-path relatif terhadap root aplikasi. */
    function url($path = '') {
        return app_base_url() . '/' . ltrim((string)$path, '/');
    }
}

if (!function_exists('asset')) {
    /** URL asset (css/js/img). */
    function asset($path) {
        return url($path);
    }
}
