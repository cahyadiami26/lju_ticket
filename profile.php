<?php
/** Profil user — data dari session (di-refresh dari tabel users). */
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/db.php';

$pageTitle = 'Profil Saya';
require_once __DIR__ . '/includes/header.php';

$user = current_user();
$rows = [
    ['Nama Lengkap', $user['name']],
    ['NIK', $user['nik'] ?: '-'],
    ['Username', $user['username']],
    ['Email', $user['email'] ?: '-'],
    ['Jabatan (Position)', $user['position'] ?: '-'],
    ['Nama Bidang (division)', $user['division'] ?: '-'],
    ['Nama Divisi (department)', $user['department'] ?: '-'],
    ['Role', $user['role']],
    ['RFID', ($_SESSION['rfid'] ?? '') ?: '-'],
    ['Webmail User', ($_SESSION['webmail_user'] ?? '') ?: '-'],
];
?>

<div class="card" style="max-width:640px">
    <div class="flex-between" style="margin-bottom:16px">
        <h3 class="card-title mb-0">Profil Saya</h3>
        <?= avatar_html($user, 64) ?>
    </div>
    <table class="table">
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr><th style="width:200px"><?= e($r[0]) ?></th><td><?= e($r[1]) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="text-muted text-sm" style="margin-top:16px">
        Data profil berasal dari Portal SSO LJU (tabel <code>users</code>).
        Untuk perubahan data, silakan hubungi bagian SDM / admin SSO.
    </p>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
