<?php
/**
 * install-check.php — Diagnostik mandiri (TANPA login, TANPA database).
 *
 * Gunakan saat muncul "HTTP ERROR 500" atau halaman putih:
 *   1. Buka https://helpdesk.lju.co.id/install-check.php
 *   2. Kirim tangkapan layar hasilnya ke tim IT / lampirkan di tiket.
 *
 * HAPUS file ini setelah aplikasi berjalan normal.
 * CATATAN: file ini tidak menampilkan password/kunci rahasia.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$root = __DIR__;
$configFile = $root . '/config/config.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Install Check — LJU Helpdesk</title>
<style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; background:#f5f7fa; color:#1f2937; margin:0; padding:24px; }
    .wrap { max-width: 860px; margin: 0 auto; }
    h1 { font-size: 20px; margin: 0 0 4px; }
    p.sub { color:#6b7280; font-size:13px; margin:0 0 20px; }
    .card { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px 18px; margin-bottom:14px; }
    h2 { font-size:14px; text-transform:uppercase; letter-spacing:.4px; color:#6b7280; margin:0 0 10px; }
    ul { list-style:none; margin:0; padding:0; }
    li { display:grid; grid-template-columns:22px 240px 1fr; gap:8px; padding:7px 0; border-bottom:1px solid #f1f5f9; font-size:13px; align-items:start; }
    li:last-child { border-bottom:none; }
    .ok  { color:#059669; font-weight:700; }
    .warn{ color:#d97706; font-weight:700; }
    .bad { color:#dc2626; font-weight:700; }
    .d { color:#6b7280; word-break:break-word; }
    .bad + .d, .status-bad .d { color:#dc2626; }
    code { background:#f3f4f6; padding:1px 5px; border-radius:4px; font-size:12px; }
    .alert { padding:12px 14px; border-radius:8px; font-size:13px; margin-bottom:16px; }
    .alert-bad { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; }
    .alert-ok  { background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46; }
    .alert-warn{ background:#fffbeb; border:1px solid #fde68a; color:#92400e; }
</style>
</head>
<body>
<div class="wrap">
    <h1>Install Check — LJU Helpdesk</h1>
    <p class="sub">Diagnostik tanpa login. Hapus file ini setelah aplikasi normal.</p>
<?php
$ok = 0; $warn = 0; $bad = 0;

function row($label, $status, $detail) {
    global $ok, $warn, $bad;
    $cls = $status === 'ok' ? 'ok' : ($status === 'warn' ? 'warn' : 'bad');
    $icon = $status === 'ok' ? '&#10003;' : ($status === 'warn' ? '!' : '&#10005;');
    if ($status === 'ok') $ok++; elseif ($status === 'warn') $warn++; else $bad++;
    echo '<li class="status-' . $status . '"><span class="' . $cls . '">' . $icon . '</span>'
        . '<span>' . htmlspecialchars($label) . '</span>'
        . '<span class="d">' . $detail . '</span></li>';
}

function section($title, $callable) {
    ob_start();
    echo '<ul>';
    $callable();
    echo '</ul>';
    $content = ob_get_clean();
    echo '<div class="card"><h2>' . htmlspecialchars($title) . '</h2>' . $content . '</div>';
}

// ---------------- 1. Requirement PHP ----------------
section('PHP & Ekstensi', function () {
    $phpOk = version_compare(PHP_VERSION, '7.4.0', '>=');
    row('Versi PHP', $phpOk ? 'ok' : 'bad', PHP_VERSION . ($phpOk ? '' : ' — minimal 7.4'));
    foreach (['pdo_mysql' => 'Database', 'mbstring' => 'Teks', 'openssl' => 'Enkripsi',
              'json' => 'JSON', 'fileinfo' => 'Upload', 'curl' => 'MikroTik'] as $ext => $need) {
        $has = extension_loaded($ext);
        $needHard = $ext !== 'curl';
        row('Ekstensi ' . $ext, $has ? 'ok' : ($needHard ? 'bad' : 'warn'),
            $has ? 'tersedia (' . $need . ')' : 'TIDAK ADA' . ($needHard ? '' : ' — monitoring MikroTik nonaktif'));
    }
    row('output_buffering', 'ok', ini_get('output_buffering') ?: '0');
    row('upload_max_filesize / post_max_size', 'ok', ini_get('upload_max_filesize') . ' / ' . ini_get('post_max_size'));
    row('Timezone server', 'ok', date_default_timezone_get() . ' — ' . date('d M Y H:i'));
});

// ---------------- 2. File & folder ----------------
section('File & Folder', function () use ($root, $configFile) {
    row('config/config.php', file_exists($configFile) ? 'ok' : 'bad',
        file_exists($configFile) ? 'ada' : 'TIDAK ADA — salin config/config.example.php lalu isi kredensial');

    $required = [
        'includes/session.php', 'includes/auth.php', 'includes/db.php', 'includes/url.php',
        'includes/functions.php', 'includes/header.php', 'includes/footer.php', 'includes/sidebar.php',
        'includes/ticket.php', 'includes/ticket_actions.php', 'includes/network.php',
        'includes/settings.php', 'includes/notifier.php', 'includes/sla.php',
        'includes/reports.php', 'includes/pdf.php', 'includes/mikrotik.php',
    ];
    foreach ($required as $f) {
        row($f, file_exists($root . '/' . $f) ? 'ok' : 'bad', file_exists($root . '/' . $f) ? 'ada' : 'HILANG — upload ulang file ini');
    }

    // Cek khusus: session.php harus memuat auth.php (penyebab fatal is_it())
    $sess = @file_get_contents($root . '/includes/session.php');
    $sessOk = $sess !== false && strpos($sess, "'auth.php'") !== false;
    row('session.php memuat auth.php', $sessOk ? 'ok' : 'bad',
        $sessOk ? 'benar — is_it()/current_user() tersedia'
                : 'SALAH — inilah penyebab "Call to undefined function is_it()". Upload includes/session.php versi terbaru');

    $upload = $root . '/uploads/tickets';
    $uploadOk = is_dir($upload) && is_writable($upload);
    row('uploads/tickets (writable)', $uploadOk ? 'ok' : 'bad',
        is_dir($upload) ? (is_writable($upload) ? 'bisa ditulis' : 'ada tapi TIDAK bisa ditulis — chmod 755') : 'TIDAK ADA — buat folder + chmod 755');

    $dompdf = file_exists($root . '/library/dompdf/autoload.inc.php') || file_exists($root . '/library/dompdf/vendor/autoload.php');
    row('domPDF', $dompdf ? 'ok' : 'warn', $dompdf ? 'terpasang' : 'belum terpasang — export PDF jatuh ke mode preview/cetak');
});

// ---------------- 3. Config & koneksi DB ----------------
$pdo = null; $usersPdo = null; $cfgErr = '';
section('Konfigurasi & Database', function () use ($configFile, $root, &$pdo, &$usersPdo, &$cfgErr) {
    if (!file_exists($configFile)) {
        row('Memuat config', 'bad', 'config/config.php belum ada — pemeriksaan database dilewati');
        $cfgErr = 'no-config';
        return;
    }
    try {
        require $configFile;
        row('Memuat config', 'ok', 'config/config.php tidak menghasilkan error');
    } catch (Throwable $e) {
        row('Memuat config', 'bad', 'ERROR: ' . htmlspecialchars($e->getMessage()));
        $cfgErr = $e->getMessage();
        return;
    }

    $consts = ['APP_NAME', 'APP_URL', 'DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'DB_CHARSET',
               'USERS_DB_HOST', 'USERS_DB_NAME', 'USERS_DB_USER', 'USERS_DB_PASS', 'USERS_DB_CHARSET',
               'UPLOAD_DIR', 'UPLOAD_MAX_SIZE', 'ENCRYPT_KEY', 'DEV_MODE', 'LOGO_URL', 'AVATAR_BASE_URL'];
    $missing = [];
    foreach ($consts as $c) {
        if (!defined($c)) $missing[] = $c;
    }
    row('Konstanta konfigurasi', empty($missing) ? 'ok' : 'bad',
        empty($missing) ? count($consts) . ' konstanta lengkap'
            : 'BELUM Didefinisikan: ' . implode(', ', $missing) . ' — config.php Anda lebih lama dari config.example.php');

    if (defined('DEV_MODE') && DEV_MODE === true) {
        row('DEV_MODE', 'warn', 'true — matikan (false) di production');
    } else {
        row('DEV_MODE', 'ok', 'false');
    }

    if (defined('ENCRYPT_KEY')) {
        $len = strlen(ENCRYPT_KEY);
        $placeholder = strpos(ENCRYPT_KEY, 'ganti-') === 0;
        row('ENCRYPT_KEY', ($len >= 32 && !$placeholder) ? 'ok' : 'warn',
            'panjang ' . $len . ($placeholder ? ' — masih nilai contoh, ganti dengan kunci acak 32+ karakter' : ''));
    }

    // Koneksi helpdesk
    try {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
            DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                               PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        row('Koneksi database helpdesk', 'ok', DB_NAME . ' @ ' . DB_HOST);
    } catch (Throwable $e) {
        row('Koneksi database helpdesk', 'bad', 'GAGAL: ' . htmlspecialchars($e->getMessage()));
        return;
    }

    // Tabel wajib
    $tables = [
        'tickets' => 'Batch 1', 'categories' => 'Batch 1', 'ticket_comments' => 'Batch 1',
        'ticket_attachments' => 'Batch 1', 'ticket_history' => 'Batch 1', 'notifications' => 'Batch 1',
        'faq_articles' => 'Batch 2', 'sla_policies' => 'Batch 3', 'it_staff' => 'Batch 3',
        'system_settings' => 'Batch 5', 'rooms' => 'Batch 4', 'network_checks' => 'Batch 4',
        'notification_logs' => 'Batch 5', 'mikrotik_settings' => 'Batch 7',
        'mikrotik_interfaces' => 'Batch 7', 'mikrotik_traffic_history' => 'Batch 7',
    ];
    $existing = [];
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM) as $r) $existing[] = $r[0];
    $missingTables = [];
    foreach ($tables as $t => $batch) {
        if (!in_array($t, $existing, true)) $missingTables[] = $t . ' (' . $batch . ')';
    }
    row('Tabel database', empty($missingTables) ? 'ok' : 'bad',
        empty($missingTables) ? count($existing) . ' tabel tersedia'
            : 'BELUM ADA: ' . implode(', ', $missingTables) . ' — jalankan schema.sql / migrasi');

    // Kolom kritis (fitur lintas batch)
    $critical = [
        'tickets' => ['room_id' => 'Batch 4', 'is_network_complaint' => 'Batch 4', 'technician_result' => 'Batch 4',
                      'sla_response_due_at' => 'Batch 3', 'sla_breached' => 'Batch 3', 'escalate_level' => 'Batch 3',
                      'rating' => 'Batch 2'],
    ];
    foreach ($critical as $t => $cols) {
        if (!in_array($t, $existing, true)) continue;
        $have = [];
        foreach ($pdo->query('SHOW COLUMNS FROM `' . $t . '`')->fetchAll() as $c) $have[] = $c['Field'];
        $miss = [];
        foreach ($cols as $col => $batch) {
            if (!in_array($col, $have, true)) $miss[] = $col . ' (' . $batch . ')';
        }
        row('Kolom tabel ' . $t, empty($miss) ? 'ok' : 'bad',
            empty($miss) ? 'kolom fitur lengkap'
                : 'KURANG: ' . implode(', ', $miss) . ' — jalankan migrasi batch terkait');
    }

    // Data minimal
    $catCount = (int)$pdo->query('SELECT COUNT(*) c FROM categories')->fetch()['c'];
    row('Kategori tiket', $catCount > 0 ? 'ok' : 'warn', $catCount . ' kategori');

    // Koneksi users (SSO)
    try {
        $usersPdo = new PDO('mysql:host=' . USERS_DB_HOST . ';dbname=' . USERS_DB_NAME . ';charset=' . USERS_DB_CHARSET,
            USERS_DB_USER, USERS_DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                                           PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        row('Koneksi database users (SSO)', 'ok', USERS_DB_NAME . ' @ ' . USERS_DB_HOST);
        $userCols = [];
        foreach ($usersPdo->query('SHOW COLUMNS FROM users')->fetchAll() as $c) $userCols[] = $c['Field'];
        $need = ['id', 'name', 'username', 'email', 'role', 'position', 'division', 'department', 'avatar'];
        $miss = array_diff($need, $userCols);
        row('Kolom tabel users', empty($miss) ? 'ok' : 'bad',
            empty($miss) ? 'kolom SSO lengkap' : 'KURANG: ' . implode(', ', $miss));
        row('Jumlah user', 'ok', (int)$usersPdo->query('SELECT COUNT(*) c FROM users')->fetch()['c'] . ' user');
    } catch (Throwable $e) {
        row('Koneksi database users (SSO)', 'warn', 'GAGAL: ' . htmlspecialchars($e->getMessage())
            . ' — login SSO & nama bidang tidak akan tampil');
    }
});

// ---------------- 4. Session ----------------
section('Session & Login', function () {
    row('session.save_path', is_writable(session_save_path() ?: sys_get_temp_dir()) ? 'ok' : 'bad',
        (session_save_path() ?: sys_get_temp_dir()) . (is_writable(session_save_path() ?: sys_get_temp_dir()) ? ' (writable)' : ' (TIDAK writable)'));
    row('SSO terdeteksi', !empty($_SESSION['user_id']) ? 'ok' : 'warn',
        !empty($_SESSION['user_id'])
            ? 'sudah login sebagai ' . htmlspecialchars($_SESSION['user_name'] ?? '-')
            : 'belum login (normal jika membuka halaman ini langsung) — login dulu di https://lju.co.id/dashboard/');
    row('Domain cookie', 'ok', '.lju.co.id');
});

// ---------------- Ringkasan ----------------
$total = $ok + $warn + $bad;
if ($bad > 0) {
    $cls = 'alert-bad';
    $msg = '<strong>' . $bad . ' masalah kritis ditemukan.</strong> Perbaiki item bertanda &#10005; terlebih dahulu, mulai dari yang paling atas.';
} elseif ($warn > 0) {
    $cls = 'alert-warn';
    $msg = '<strong>Semua syarat wajib terpenuhi.</strong> Ada ' . $warn . ' item opsional yang belum siap.';
} else {
    $cls = 'alert-ok';
    $msg = '<strong>Semua ' . $total . ' pemeriksaan lulus.</strong> Aplikasi siap digunakan.';
}
echo '<div class="alert ' . $cls . '">' . $msg . '</div>';
echo '<div class="alert alert-warn" style="background:#eff6ff;border-color:#bfdbfe;color:#1e40af">'
   . 'Jika masih muncul <strong>HTTP ERROR 500</strong>, buka file <code>error_log</code> di folder aplikasi '
   . 'atau cek <em>Error Log</em> cPanel, lalu kirim 10 baris terakhir ke tim IT.</div>';
?>
</div>
</body>
</html>
