<?php
/**
 * Demo Login — HANYA untuk DEV_MODE (tanpa SSO).
 * Pilih user dari tabel `users` untuk mengisi session.
 * WAJIB nonaktif di production (DEV_MODE = false).
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/url.php';

if (!defined('DEV_MODE') || DEV_MODE !== true) {
    http_response_code(404);
    exit('Halaman tidak tersedia.');
}

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

// Jika sudah login → ke dashboard
if (!empty($_SESSION['user_id'])) {
    header('Location: ' . url('dashboard.php'));
    exit;
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php'; // untuk set_session_from_user() & avatar_html()

$users = [];
$error = '';
if ($usersPdo) {
    try {
        $users = $usersPdo->query('SELECT * FROM users WHERE ' . users_active_condition($usersPdo) . ' ORDER BY name LIMIT 100')->fetchAll();
    } catch (Throwable $e) {
        $error = 'Gagal membaca tabel users: ' . $e->getMessage();
    }
} else {
    $error = 'Koneksi ke database users gagal. Periksa USERS_DB_* di config/config.php.';
}

// Proses pilih user (POST + CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $error = 'Token keamanan tidak valid. Silakan coba lagi.';
    } else {
        $userId = (int)($_POST['user_id'] ?? 0);
        $row = get_user_by_id($usersPdo, $userId);
        if ($row) {
            set_session_from_user($row);
            header('Location: ' . url('dashboard.php'));
            exit;
        }
        $error = 'User tidak ditemukan.';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Demo Login — <?= e(APP_NAME) ?></title>
<link rel="icon" href="<?= e(LOGO_URL) ?>">
<link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
</head>
<body>
<div class="auth-wrap">
    <div class="auth-card">
        <div class="auth-logo"><img src="<?= e(LOGO_URL) ?>" alt="LJU" onerror="this.style.display='none'"></div>
        <h1 class="auth-title">LJU IT Helpdesk</h1>
        <p class="auth-sub">Mode Development — pilih user untuk masuk (tanpa SSO)</p>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if (empty($users)): ?>
            <div class="alert alert-info">
                Belum ada user aktif di tabel <code>users</code>, atau koneksi DB users belum dikonfigurasi.
                Di production, halaman ini tidak digunakan — login lewat Portal SSO LJU.
            </div>
        <?php else: ?>
            <form method="post">
                <?= csrf_field() ?>
                <?php foreach ($users as $u): ?>
                    <button type="submit" name="user_id" value="<?= (int)$u['id'] ?>" class="user-pick">
                        <?= avatar_html(['name' => $u['name'], 'avatar' => $u['avatar']], 36) ?>
                        <span>
                            <span class="user-pick-name"><?= e($u['name']) ?></span><br>
                            <span class="user-pick-meta"><?= e($u['username']) ?> · <?= e($u['position'] ?? '') ?> · <?= e($u['division'] ?? '') ?> · <?= e($u['role']) ?></span>
                        </span>
                    </button>
                <?php endforeach; ?>
            </form>
        <?php endif; ?>

        <p class="text-muted text-sm" style="text-align:center;margin-top:16px">
            Halaman ini hanya aktif saat <code>DEV_MODE = true</code>.
        </p>
    </div>
</div>
</body>
</html>
