<?php
/**
 * Pengaturan sistem (Batch 5) — SLA, eskalasi, email perusahaan, WhatsApp API.
 * Hanya tim IT / admin.
 */
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/url.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/sla.php';
require_it($pdo);

$pageTitle = 'Pengaturan';
$user = current_user();
$msg = '';
$err = '';
$cronStats = null;

// --- Simpan pengaturan ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    if (!csrf_check()) {
        $err = 'Token keamanan tidak valid.';
    } else {
        $section = $_POST['section'] ?? 'general';
        $values = [];
        if ($section === 'sla') {
            $values = [
                'sla_warning_minutes'  => max(5, (int)($_POST['sla_warning_minutes'] ?? 60)),
                'sla_auto_escalate'    => !empty($_POST['sla_auto_escalate']) ? '1' : '0',
                'sla_escalate_max_level' => max(0, min(3, (int)($_POST['sla_escalate_max_level'] ?? 2))),
                'auto_close_days'      => max(0, (int)($_POST['auto_close_days'] ?? 7)),
            ];
            // Kebijakan SLA per prioritas
            foreach (['critical', 'high', 'medium', 'low'] as $p) {
                $resp = max(1, (int)($_POST['response_' . $p] ?? 0));
                $res  = max(1, (int)($_POST['resolution_' . $p] ?? 0));
                try {
                    $stmt = $pdo->prepare(
                        'INSERT INTO sla_policies (priority, response_minutes, resolution_minutes) VALUES (?, ?, ?)
                         ON DUPLICATE KEY UPDATE response_minutes = VALUES(response_minutes), resolution_minutes = VALUES(resolution_minutes)'
                    );
                    $stmt->execute([$p, $resp, $res]);
                } catch (Throwable $e) {
                    error_log('[SLA SAVE] ' . $e->getMessage());
                }
            }
        } elseif ($section === 'email') {
            $values = [
                'notify_email_enabled' => !empty($_POST['notify_email_enabled']) ? '1' : '0',
                'smtp_host'            => trim($_POST['smtp_host'] ?? ''),
                'smtp_port'            => (int)($_POST['smtp_port'] ?? 587),
                'smtp_username'        => trim($_POST['smtp_username'] ?? ''),
                'smtp_secure'          => in_array($_POST['smtp_secure'] ?? 'tls', ['tls', 'ssl', 'none'], true) ? $_POST['smtp_secure'] : 'tls',
                'email_from'           => trim($_POST['email_from'] ?? 'helpdesk@lju.co.id'),
                'email_from_name'      => trim($_POST['email_from_name'] ?? 'LJU IT Helpdesk'),
            ];
            if (!empty($_POST['smtp_password'])) {
                $values['smtp_password'] = $_POST['smtp_password'];
            }
        } elseif ($section === 'whatsapp') {
            $values = [
                'notify_whatsapp_enabled' => !empty($_POST['notify_whatsapp_enabled']) ? '1' : '0',
                'whatsapp_api_url'        => trim($_POST['whatsapp_api_url'] ?? ''),
            ];
            if (!empty($_POST['whatsapp_token'])) {
                $values['whatsapp_token'] = trim($_POST['whatsapp_token']);
            }
        } elseif ($section === 'general') {
            $values = [
                'app_name'     => trim($_POST['app_name'] ?? 'LJU IT Helpdesk'),
                'ticket_prefix' => strtoupper(trim($_POST['ticket_prefix'] ?? 'LJU')),
                'cron_token'   => trim($_POST['cron_token'] ?? '') ?: setting($pdo, 'cron_token', 'ganti_token_cron_anda'),
            ];
        }
        $res = save_settings($pdo, $values);
        if ($res['ok']) {
            $msg = 'Pengaturan disimpan.';
        } else {
            $err = $res['message'];
        }
    }
}

// --- Jalankan pemeriksaan SLA manual ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run_sla') {
    if (!csrf_check()) {
        $err = 'Token keamanan tidak valid.';
    } else {
        $cronStats = run_sla_monitor($pdo, $usersPdo);
        $msg = 'Pemeriksaan SLA selesai dijalankan.';
    }
}

// --- Uji koneksi email / WA ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'test_channel') {
    if (!csrf_check()) {
        $err = 'Token keamanan tidak valid.';
    } else {
        $channel = $_POST['channel'] ?? '';
        $target = trim($_POST['target'] ?? '');
        if ($target === '') {
            $err = 'Isi alamat tujuan uji coba.';
        } elseif ($channel === 'email') {
            $cfg = [
                'host' => setting($pdo, 'smtp_host', ''), 'port' => setting($pdo, 'smtp_port', 587),
                'username' => setting($pdo, 'smtp_username', ''), 'password' => setting($pdo, 'smtp_password', ''),
                'secure' => setting($pdo, 'smtp_secure', 'tls'),
                'from' => setting($pdo, 'email_from', 'helpdesk@lju.co.id'),
                'from_name' => setting($pdo, 'email_from_name', 'LJU IT Helpdesk'),
            ];
            $res = send_email_smtp($target, 'Uji Coba Email — LJU IT Helpdesk',
                notifier_email_template('Uji Coba Email', 'Konfigurasi email helpdesk berhasil. Pesan ini dikirim otomatis oleh sistem.', null, null), $cfg);
            notifier_log($pdo, 'email', $target, 'Uji coba email', 'test', null, $res['ok'] ? 'sent' : 'failed', $res['message']);
            $res['ok'] ? $msg = 'Email uji coba berhasil dikirim ke ' . $target . '.' : $err = 'Email gagal: ' . $res['message'];
        } elseif ($channel === 'whatsapp') {
            $res = send_whatsapp_api(normalize_phone($target),
                "*LJU IT Helpdesk*\n\nUji coba koneksi WhatsApp API berhasil. Pesan ini dikirim otomatis oleh sistem.",
                ['api_url' => setting($pdo, 'whatsapp_api_url', ''), 'token' => setting($pdo, 'whatsapp_token', '')]);
            notifier_log($pdo, 'whatsapp', normalize_phone($target), 'Uji coba WhatsApp', 'test', null, $res['ok'] ? 'sent' : 'failed', $res['message']);
            $res['ok'] ? $msg = 'Pesan WhatsApp uji coba terkirim.' : $err = 'WhatsApp gagal: ' . $res['message'];
        }
    }
}

$policies = sla_policies_map($pdo);
$recentLogs = [];
try {
    $recentLogs = $pdo->query('SELECT * FROM notification_logs ORDER BY created_at DESC LIMIT 15')->fetchAll();
} catch (Throwable $e) {
    error_log('[LOG LIST] ' . $e->getMessage());
}

require_once __DIR__ . '/../includes/header.php';
$prioLabel = ['critical' => 'Critical', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low'];
?>

<?php if ($msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endif; ?>

<?php if ($cronStats): ?>
<div class="card">
    <h3 class="card-title">Hasil Pemeriksaan SLA</h3>
    <div class="stats-grid" style="margin-bottom:0">
        <div class="stat-card"><div class="stat-label">Dicek</div><div class="stat-value"><?= (int)$cronStats['checked'] ?></div></div>
        <div class="stat-card"><div class="stat-label">Peringatan Terkirim</div><div class="stat-value"><?= (int)$cronStats['warned'] ?></div></div>
        <div class="stat-card"><div class="stat-label">Melewati SLA</div><div class="stat-value" style="color:var(--danger)"><?= (int)$cronStats['breached'] ?></div></div>
        <div class="stat-card"><div class="stat-label">Eskalasi Otomatis</div><div class="stat-value"><?= (int)$cronStats['escalated'] ?></div></div>
        <div class="stat-card"><div class="stat-label">Auto Close</div><div class="stat-value"><?= (int)$cronStats['auto_closed'] ?></div></div>
    </div>
    <?php if (!empty($cronStats['errors'])): ?>
        <div class="alert alert-error" style="margin-top:12px"><?= e(implode(' | ', $cronStats['errors'])) ?></div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="action-grid">
    <!-- SLA -->
    <div class="card" style="margin-bottom:0">
        <h3 class="card-title">SLA &amp; Eskalasi</h3>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="section" value="sla">

            <table class="table">
                <thead><tr><th>Prioritas</th><th>Respons (menit)</th><th>Penyelesaian (menit)</th></tr></thead>
                <tbody>
                <?php foreach ($prioLabel as $p => $label): ?>
                    <tr>
                        <td><?= priority_badge($p) ?></td>
                        <td><input type="number" min="1" name="response_<?= $p ?>" class="form-control" value="<?= (int)($policies[$p]['response_minutes'] ?? 60) ?>"></td>
                        <td><input type="number" min="1" name="resolution_<?= $p ?>" class="form-control" value="<?= (int)($policies[$p]['resolution_minutes'] ?? 240) ?>"></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <div class="form-group" style="margin-top:12px">
                <label for="sla_warning_minutes">Peringatan mendekati SLA dikirim (menit sebelum batas)</label>
                <input type="number" min="5" id="sla_warning_minutes" name="sla_warning_minutes" class="form-control" value="<?= (int)setting($pdo, 'sla_warning_minutes', 60) ?>">
            </div>
            <div class="form-group">
                <label for="sla_escalate_max_level">Maksimal eskalasi otomatis per tiket</label>
                <input type="number" min="0" max="3" id="sla_escalate_max_level" name="sla_escalate_max_level" class="form-control" value="<?= (int)setting($pdo, 'sla_escalate_max_level', 2) ?>">
            </div>
            <div class="form-group">
                <label for="auto_close_days">Auto-close tiket Resolved setelah (hari, 0 = nonaktif)</label>
                <input type="number" min="0" id="auto_close_days" name="auto_close_days" class="form-control" value="<?= (int)setting($pdo, 'auto_close_days', 7) ?>">
            </div>
            <label style="display:flex;gap:8px;align-items:center;margin-bottom:14px">
                <input type="checkbox" name="sla_auto_escalate" value="1"<?= setting_bool($pdo, 'sla_auto_escalate', true) ? ' checked' : '' ?>>
                Aktifkan eskalasi otomatis saat SLA terlewati
            </label>
            <button type="submit" class="btn btn-primary">Simpan Pengaturan SLA</button>
        </form>

        <hr style="border:none;border-top:1px solid var(--border);margin:16px 0">
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="run_sla">
            <button type="submit" class="btn btn-ghost">⟳ Jalankan Pemeriksaan SLA Sekarang</button>
        </form>
    </div>

    <!-- Email -->
    <div class="card" style="margin-bottom:0">
        <h3 class="card-title">Email Perusahaan</h3>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="section" value="email">

            <label style="display:flex;gap:8px;align-items:center;margin-bottom:14px">
                <input type="checkbox" name="notify_email_enabled" value="1"<?= setting_bool($pdo, 'notify_email_enabled') ? ' checked' : '' ?>>
                Kirim notifikasi tiket via email
            </label>
            <div class="form-group"><label for="smtp_host">SMTP Host</label>
                <input type="text" id="smtp_host" name="smtp_host" class="form-control" placeholder="mail.lju.co.id" value="<?= e(setting($pdo, 'smtp_host', '')) ?>"></div>
            <div class="form-group"><label for="smtp_port">SMTP Port</label>
                <input type="number" id="smtp_port" name="smtp_port" class="form-control" value="<?= (int)setting($pdo, 'smtp_port', 587) ?>"></div>
            <div class="form-group"><label for="smtp_username">Username SMTP</label>
                <input type="text" id="smtp_username" name="smtp_username" class="form-control" value="<?= e(setting($pdo, 'smtp_username', '')) ?>"></div>
            <div class="form-group"><label for="smtp_password">Password SMTP</label>
                <input type="password" id="smtp_password" name="smtp_password" class="form-control" placeholder="<?= setting($pdo, 'smtp_password') ? '•••••••• (biarkan kosong bila tidak diubah)' : 'Password SMTP' ?>"></div>
            <div class="form-group"><label for="smtp_secure">Keamanan</label>
                <select id="smtp_secure" name="smtp_secure" class="form-control">
                    <?php foreach (['tls' => 'STARTTLS (587)', 'ssl' => 'SSL (465)', 'none' => 'Tanpa enkripsi'] as $k => $v): ?>
                        <option value="<?= $k ?>"<?= setting($pdo, 'smtp_secure', 'tls') === $k ? ' selected' : '' ?>><?= e($v) ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="form-group"><label for="email_from">Alamat Pengirim</label>
                <input type="email" id="email_from" name="email_from" class="form-control" value="<?= e(setting($pdo, 'email_from', 'helpdesk@lju.co.id')) ?>"></div>
            <div class="form-group"><label for="email_from_name">Nama Pengirim</label>
                <input type="text" id="email_from_name" name="email_from_name" class="form-control" value="<?= e(setting($pdo, 'email_from_name', 'LJU IT Helpdesk')) ?>"></div>
            <button type="submit" class="btn btn-primary">Simpan Email</button>
        </form>

        <hr style="border:none;border-top:1px solid var(--border);margin:16px 0">
        <form method="post" class="search-row">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="test_channel">
            <input type="hidden" name="channel" value="email">
            <input type="email" name="target" class="form-control" style="max-width:240px" placeholder="email@lju.co.id" required>
            <button type="submit" class="btn btn-ghost">Kirim Email Uji</button>
        </form>
    </div>

    <!-- WhatsApp -->
    <div class="card" style="margin-bottom:0">
        <h3 class="card-title">WhatsApp API</h3>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="section" value="whatsapp">

            <label style="display:flex;gap:8px;align-items:center;margin-bottom:14px">
                <input type="checkbox" name="notify_whatsapp_enabled" value="1"<?= setting_bool($pdo, 'notify_whatsapp_enabled') ? ' checked' : '' ?>>
                Kirim notifikasi tiket via WhatsApp
            </label>
            <div class="form-group"><label for="whatsapp_api_url">URL API Gateway</label>
                <input type="text" id="whatsapp_api_url" name="whatsapp_api_url" class="form-control" placeholder="https://api.fonnte.com/send" value="<?= e(setting($pdo, 'whatsapp_api_url', 'https://api.fonnte.com/send')) ?>"></div>
            <div class="form-group"><label for="whatsapp_token">Token API</label>
                <input type="password" id="whatsapp_token" name="whatsapp_token" class="form-control" placeholder="<?= setting($pdo, 'whatsapp_token') ? '•••••••• (biarkan kosong bila tidak diubah)' : 'Token API' ?>"></div>
            <div class="alert alert-info text-sm">
                Nomor WhatsApp karyawan diambil dari kolom <code>phone</code>, <code>whatsapp</code>, atau <code>no_hp</code>
                pada tabel <code>users</code>. Nomor otomatis dinormalisasi ke format 62xxxxxxxxxx.
            </div>
            <button type="submit" class="btn btn-primary">Simpan WhatsApp</button>
        </form>

        <hr style="border:none;border-top:1px solid var(--border);margin:16px 0">
        <form method="post" class="search-row">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="test_channel">
            <input type="hidden" name="channel" value="whatsapp">
            <input type="text" name="target" class="form-control" style="max-width:200px" placeholder="08xxxxxxxxxx" required>
            <button type="submit" class="btn btn-ghost">Kirim WA Uji</button>
        </form>
    </div>
</div>

<div class="card">
    <h3 class="card-title">Umum &amp; Cron</h3>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="section" value="general">
        <div class="action-grid">
            <div class="form-group"><label for="app_name">Nama Aplikasi</label>
                <input type="text" id="app_name" name="app_name" class="form-control" value="<?= e(setting($pdo, 'app_name', APP_NAME)) ?>"></div>
            <div class="form-group"><label for="ticket_prefix">Prefix Nomor Tiket</label>
                <input type="text" id="ticket_prefix" name="ticket_prefix" class="form-control" value="<?= e(setting($pdo, 'ticket_prefix', 'LJU')) ?>"></div>
            <div class="form-group"><label for="cron_token">Token Cron (rahasia)</label>
                <input type="text" id="cron_token" name="cron_token" class="form-control" value="<?= e(setting($pdo, 'cron_token', '')) ?>"></div>
        </div>
        <button type="submit" class="btn btn-primary">Simpan</button>
    </form>
    <div class="alert alert-info text-sm" style="margin-top:14px">
        Jadwalkan pemeriksaan SLA otomatis setiap 10 menit di cron server:<br>
        <code>*/10 * * * * curl -s "<?= e(rtrim(APP_URL, '/')) ?>/cron/sla.php?token=<?= e(setting($pdo, 'cron_token', 'TOKEN')) ?>" > /dev/null</code>
    </div>
</div>

<div class="card">
    <h3 class="card-title">Log Pengiriman Notifikasi (15 terakhir)</h3>
    <?php if (empty($recentLogs)): ?>
        <p class="text-muted">Belum ada pengiriman email/WhatsApp.</p>
    <?php else: ?>
        <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Waktu</th><th>Kanal</th><th>Tujuan</th><th>Subjek</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($recentLogs as $log): ?>
                <tr>
                    <td class="text-muted"><?= e(format_date($log['created_at'])) ?></td>
                    <td><span class="badge <?= $log['channel'] === 'email' ? 'badge-open' : 'badge-resolved' ?>"><?= e($log['channel']) ?></span></td>
                    <td><?= e($log['recipient']) ?></td>
                    <td class="text-muted text-sm"><?= e(mb_strimwidth((string)$log['subject'], 0, 60, '…')) ?></td>
                    <td><span class="badge <?= $log['status'] === 'sent' ? 'badge-resolved' : 'badge-critical' ?>"><?= e($log['status']) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
