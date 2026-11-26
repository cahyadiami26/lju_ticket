<?php
/**
 * Pengaturan koneksi MikroTik (Batch 7).
 * URL router, username, password (terenkripsi), verifikasi SSL, daftar interface.
 */
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/url.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mikrotik.php';
require_it($pdo);

$pageTitle = 'Pengaturan MikroTik';
$msg = '';
$err = '';
$testResult = null;

// ---------- Simpan koneksi ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    if (!csrf_check()) {
        $err = 'Token keamanan tidak valid.';
    } else {
        $hostUrl = rtrim(trim($_POST['host_url'] ?? ''), '/');
        $username = trim($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $verify = !empty($_POST['verify_ssl']) ? 1 : 0;

        if ($hostUrl !== '' && !preg_match('#^https?://#i', $hostUrl)) {
            $hostUrl = 'https://' . $hostUrl;
        }
        if ($hostUrl === '') {
            $err = 'URL router wajib diisi (contoh: https://192.168.88.1).';
        } elseif ($username === '') {
            $err = 'Username router wajib diisi.';
        } else {
            $cfg = mikrotik_config($pdo);
            try {
                if ((int)$cfg['id'] > 0) {
                    $sql = 'UPDATE mikrotik_settings SET host_url = ?, username = ?, verify_ssl = ?, is_active = ?';
                    $params = [$hostUrl, $username, $verify, !empty($_POST['is_active']) ? 1 : 0];
                    if ($password !== '') {
                        $sql .= ', password = ?';
                        $params[] = mikrotik_encrypt($password);
                    }
                    $sql .= ' WHERE id = ?';
                    $params[] = (int)$cfg['id'];
                    $pdo->prepare($sql)->execute($params);
                } else {
                    $pdo->prepare(
                        'INSERT INTO mikrotik_settings (host_url, username, password, verify_ssl, is_active)
                         VALUES (?, ?, ?, ?, ?)'
                    )->execute([$hostUrl, $username, mikrotik_encrypt($password), $verify, !empty($_POST['is_active']) ? 1 : 0]);
                }
                $msg = 'Pengaturan koneksi MikroTik disimpan.';
            } catch (Throwable $e) {
                error_log('[MIKROTIK SAVE] ' . $e->getMessage());
                $err = 'Gagal menyimpan pengaturan.';
            }
        }
    }
}

// ---------- Uji koneksi ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'test') {
    if (!csrf_check()) {
        $err = 'Token keamanan tidak valid.';
    } else {
        $testResult = mikrotik_test_connection($pdo);
        if ($testResult['ok']) {
            $msg = 'Koneksi ke router berhasil.';
        } else {
            $err = 'Koneksi gagal: ' . $testResult['message'];
        }
    }
}

// ---------- Muat interface dari router ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'load_interfaces') {
    if (!csrf_check()) {
        $err = 'Token keamanan tidak valid.';
    } else {
        $res = mikrotik_interfaces($pdo);
        if (!$res['ok']) {
            $err = 'Gagal memuat interface: ' . $res['error'];
        } else {
            $saved = 0;
            foreach ($res['data'] as $iface) {
                $name = $iface['name'] ?? '';
                if ($name === '') continue;
                try {
                    $pdo->prepare(
                        'INSERT INTO mikrotik_interfaces (interface_name, label, is_active) VALUES (?, ?, 1)
                         ON DUPLICATE KEY UPDATE label = VALUES(label)'
                    )->execute([$name, $name]);
                    $saved++;
                } catch (Throwable $e) {
                    error_log('[MIKROTIK IFACE] ' . $e->getMessage());
                }
            }
            $msg = $saved . ' interface dimuat dari router.';
        }
    }
}

// ---------- Simpan pilihan interface ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_interfaces') {
    if (!csrf_check()) {
        $err = 'Token keamanan tidak valid.';
    } else {
        $selected = array_map('intval', (array)($_POST['iface'] ?? []));
        try {
            $rows = $pdo->query('SELECT id FROM mikrotik_interfaces')->fetchAll();
            $stmt = $pdo->prepare('UPDATE mikrotik_interfaces SET is_active = ? WHERE id = ?');
            foreach ($rows as $r) {
                $stmt->execute([in_array((int)$r['id'], $selected, true) ? 1 : 0, (int)$r['id']]);
            }
            $msg = 'Pilihan interface monitoring disimpan.';
        } catch (Throwable $e) {
            error_log('[MIKROTIK IFACE SAVE] ' . $e->getMessage());
            $err = 'Gagal menyimpan pilihan interface.';
        }
    }
}

$cfg = mikrotik_config($pdo);
$interfaces = [];
try {
    $interfaces = $pdo->query('SELECT * FROM mikrotik_interfaces ORDER BY sort_order, interface_name')->fetchAll();
} catch (Throwable $e) {
    error_log('[MIKROTIK LIST] ' . $e->getMessage());
}

require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endif; ?>

<div class="flex-between" style="margin-bottom:16px">
    <div>
        <h2 class="mt-0 mb-0">Pengaturan MikroTik</h2>
        <div class="text-muted text-sm">Koneksi REST API RouterOS v7 untuk monitoring jaringan.</div>
    </div>
    <a href="<?= e(url('monitoring/index.php')) ?>" class="btn btn-ghost">Buka Monitoring →</a>
</div>

<?php if (!empty($cfg['last_check_at'])): ?>
<div class="alert <?= $cfg['last_check_status'] === 'ok' ? 'alert-success' : 'alert-error' ?>">
    Status terakhir: <strong><?= $cfg['last_check_status'] === 'ok' ? 'Terhubung' : 'Gagal' ?></strong>
    — <?= e($cfg['last_check_message']) ?> (<?= e(format_date($cfg['last_check_at'])) ?>)
    <?php if (!empty($cfg['router_board'])): ?>
        <br>Router: <?= e($cfg['router_board']) ?> · RouterOS <?= e($cfg['router_version']) ?> · uptime <?= e($cfg['router_uptime']) ?>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="action-grid">
    <div class="card" style="margin-bottom:0">
        <h3 class="card-title">Koneksi Router</h3>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">

            <div class="form-group">
                <label for="host_url">URL Router *</label>
                <input type="text" id="host_url" name="host_url" class="form-control" placeholder="https://192.168.88.1"
                       value="<?= e($cfg['host_url']) ?>" required>
                <div class="text-muted text-sm" style="margin-top:4px">Gunakan HTTPS dan aktifkan <code>www-ssl</code> di router.</div>
            </div>
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" class="form-control" placeholder="helpdesk"
                       value="<?= e($cfg['username']) ?>" required autocomplete="off">
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" class="form-control" autocomplete="new-password"
                       placeholder="<?= !empty($cfg['has_password']) ? '•••••••• (kosongkan bila tidak diubah)' : 'Password router' ?>">
                <div class="text-muted text-sm" style="margin-top:4px">Disimpan terenkripsi AES-256 (kunci: <code>ENCRYPT_KEY</code> di config).</div>
            </div>
            <label style="display:flex;gap:8px;align-items:center;margin-bottom:10px">
                <input type="checkbox" name="verify_ssl" value="1"<?= !empty($cfg['verify_ssl']) ? ' checked' : '' ?>>
                Verifikasi sertifikat SSL
            </label>
            <label style="display:flex;gap:8px;align-items:center;margin-bottom:14px">
                <input type="checkbox" name="is_active" value="1"<?= !empty($cfg['is_active']) ? ' checked' : '' ?>>
                Aktifkan monitoring MikroTik
            </label>
            <div class="quick-actions">
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>

        <form method="post" style="margin-top:12px">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="test">
            <button type="submit" class="btn btn-ghost">⇄ Uji Koneksi Sekarang</button>
        </form>

        <div class="alert alert-info text-sm" style="margin-top:14px">
            <strong>Persiapan di RouterOS v7:</strong><br>
            <code>/ip service enable www-ssl</code><br>
            <code>/user add name=helpdesk group=read password=RAHASIA</code><br>
            <code>/ip service set www-ssl certificate=... </code> (sertifikat valid agar SSL terverifikasi)
        </div>
    </div>

    <div class="card" style="margin-bottom:0">
        <h3 class="card-title">Interface yang Dimonitor</h3>
        <form method="post" style="margin-bottom:12px">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="load_interfaces">
            <button type="submit" class="btn btn-ghost btn-sm">⟳ Muat Daftar Interface dari Router</button>
        </form>

        <?php if (empty($interfaces)): ?>
            <p class="text-muted">Belum ada interface. Klik tombol di atas setelah koneksi berhasil.</p>
        <?php else: ?>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_interfaces">
                <div class="table-wrap">
                <table class="table">
                    <thead><tr><th style="width:40px">Aktif</th><th>Interface</th><th>Label</th></tr></thead>
                    <tbody>
                    <?php foreach ($interfaces as $iface): ?>
                        <tr>
                            <td><input type="checkbox" name="iface[]" value="<?= (int)$iface['id'] ?>"<?= !empty($iface['is_active']) ? ' checked' : '' ?>></td>
                            <td><code><?= e($iface['interface_name']) ?></code></td>
                            <td class="text-muted"><?= e($iface['label']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <button type="submit" class="btn btn-primary btn-sm" style="margin-top:10px">Simpan Pilihan</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
