<?php
/**
 * Monitoring Jaringan — MikroTik RouterOS v7 REST API (Batch 7).
 * Traffic interface + grafik, PPPoE active, DHCP lease, simple queue.
 */
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/url.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mikrotik.php';
require_it($pdo);

$pageTitle = 'Monitoring Jaringan';
$cfg = mikrotik_config($pdo);

$tab = $_GET['tab'] ?? 'traffic';
if (!in_array($tab, ['traffic', 'pppoe', 'dhcp', 'queue'], true)) {
    $tab = 'traffic';
}

$selectedIface = $_GET['iface'] ?? '';
$error = '';
$overview = null;
$configured = !empty($cfg['host_url']) && !empty($cfg['username']);

if ($configured) {
    $overview = mikrotik_overview($pdo);
    if (!$overview['ok']) {
        $error = $overview['error'];
    }
}

// Interface yang dipilih di pengaturan
$watched = [];
try {
    $watched = $pdo->query('SELECT * FROM mikrotik_interfaces WHERE is_active = 1 ORDER BY sort_order, interface_name')->fetchAll();
} catch (Throwable $e) {
    error_log('[MON IFACES] ' . $e->getMessage());
}

// --- Data tab ---
$traffic = [];
$pppoe = [];
$dhcp = [];
$queues = [];
$resource = null;
$history = [];

if ($overview && $overview['ok']) {
    $resource = $overview['resource'];
    $traffic = mikrotik_sample_traffic($pdo, $overview['interfaces'], true);
    $pppoe = $overview['pppoe'];
    $dhcp = $overview['dhcp'];
    $queues = $overview['queues'];

    if ($tab === 'traffic' && $selectedIface !== '') {
        $history = mikrotik_traffic_history($pdo, $selectedIface, 180);
    }
    // bersihkan riwayat lama (maksimal sekali per jam)
    if (random_int(1, 12) === 1) {
        mikrotik_prune_history($pdo, defined('MIKROTIK_HISTORY_DAYS') ? MIKROTIK_HISTORY_DAYS : 7);
    }
}

// --- Hitung ringkasan ---
$runningCount = 0;
foreach ($overview['interfaces'] ?? [] as $i) {
    if (($i['running'] ?? '') === 'true') $runningCount++;
}
$dhcpBound = 0;
foreach ($dhcp as $l) {
    if (($l['status'] ?? '') === 'bound') $dhcpBound++;
}

// Skala grafik historis
$maxHist = 1;
foreach ($history as $h) {
    $maxHist = max($maxHist, (int)$h['rx_bps'], (int)$h['tx_bps']);
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="flex-between" style="margin-bottom:16px">
    <div>
        <h2 class="mt-0 mb-0">Monitoring Jaringan</h2>
        <div class="text-muted text-sm">
            <?php if ($resource): ?>
                <?= e($resource['board-name'] ?? 'MikroTik') ?> ·
                RouterOS <?= e($resource['version'] ?? '-') ?> ·
                uptime <?= e($resource['uptime'] ?? '-') ?> ·
                CPU <?= e($resource['cpu-load'] ?? '0') ?>%
            <?php else: ?>
                MikroTik REST API v7
            <?php endif; ?>
        </div>
    </div>
    <div class="quick-actions">
        <a href="<?= e(url('monitoring/settings.php')) ?>" class="btn btn-ghost">⚙ Pengaturan</a>
        <a href="<?= e(url('monitoring/index.php')) ?>?tab=<?= e($tab) ?>" class="btn btn-primary">⟳ Refresh</a>
    </div>
</div>

<?php if (!$configured): ?>
    <div class="card">
        <div class="empty-state">
            <div class="empty-icon">◈</div>
            <p>Koneksi MikroTik belum dikonfigurasi. Isi URL router, username, dan password terlebih dahulu.</p>
            <a href="<?= e(url('monitoring/settings.php')) ?>" class="btn btn-primary">Buka Pengaturan MikroTik</a>
        </div>
    </div>
<?php else: ?>

<?php if ($error): ?>
    <div class="alert alert-error">
        <strong>Gagal menghubungi router:</strong> <?= e($error) ?><br>
        <span class="text-sm">Periksa URL, kredensial, dan pastikan service <code>www-ssl</code> aktif di RouterOS v7.
        <a href="<?= e(url('monitoring/settings.php')) ?>">Buka pengaturan</a></span>
    </div>
<?php endif; ?>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-label">Interface Aktif</div>
        <div class="stat-value"><?= $runningCount ?></div>
        <div class="stat-sub">dari <?= count($overview['interfaces'] ?? []) ?> interface</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">PPPoE Active</div>
        <div class="stat-value" style="color:var(--primary)"><?= count($pppoe) ?></div>
        <div class="stat-sub">sesi terhubung</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">DHCP Lease</div>
        <div class="stat-value"><?= $dhcpBound ?></div>
        <div class="stat-sub">bound dari <?= count($dhcp) ?> lease</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Simple Queue</div>
        <div class="stat-value"><?= count($queues) ?></div>
        <div class="stat-sub">antrian bandwidth</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Memory</div>
        <div class="stat-value" style="font-size:20px">
            <?= e(format_bytes(mikrotik_int($resource['free-memory'] ?? 0))) ?>
        </div>
        <div class="stat-sub">free dari <?= e(format_bytes(mikrotik_int($resource['total-memory'] ?? 0))) ?></div>
    </div>
</div>

<div class="tabs">
    <?php foreach (['traffic' => 'Traffic Interface', 'pppoe' => 'PPPoE Active', 'dhcp' => 'DHCP Lease', 'queue' => 'Simple Queue'] as $k => $label): ?>
        <a href="<?= e(url('monitoring/index.php')) ?>?tab=<?= $k ?>" class="tab<?= $tab === $k ? ' active' : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
    <a href="<?= e(url('monitoring/settings.php')) ?>" class="tab" style="margin-left:auto">⚙ Pengaturan</a>
</div>

<?php if ($tab === 'traffic'): ?>
<div class="card">
    <h3 class="card-title">Traffic Interface</h3>
    <?php if (empty($overview['interfaces'])): ?>
        <p class="text-muted">Tidak ada interface yang terbaca dari router.</p>
    <?php else: ?>
        <div class="table-wrap">
        <table class="table">
            <thead>
                <tr><th>Interface</th><th>Tipe</th><th>Status</th><th>RX</th><th>TX</th><th>Total RX</th><th>Total TX</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($overview['interfaces'] as $iface): ?>
                <?php
                $name = $iface['name'] ?? '';
                $isRunning = ($iface['running'] ?? '') === 'true';
                $t = $traffic[$name] ?? ['rx_bps' => 0, 'tx_bps' => 0, 'rx' => mikrotik_int($iface['rx-byte'] ?? 0), 'tx' => mikrotik_int($iface['tx-byte'] ?? 0)];
                ?>
                <tr>
                    <td><code><?= e($name) ?></code></td>
                    <td class="text-muted"><?= e($iface['type'] ?? '-') ?></td>
                    <td>
                        <span class="badge <?= $isRunning ? 'badge-resolved' : 'badge-closed' ?>"><?= $isRunning ? 'running' : 'down' ?></span>
                        <?php if (($iface['disabled'] ?? 'false') === 'true'): ?><span class="badge badge-closed">disabled</span><?php endif; ?>
                    </td>
                    <td><?= e(format_bps($t['rx_bps'])) ?></td>
                    <td><?= e(format_bps($t['tx_bps'])) ?></td>
                    <td class="text-muted"><?= e(format_bytes($t['rx'])) ?></td>
                    <td class="text-muted"><?= e(format_bytes($t['tx'])) ?></td>
                    <td>
                        <a class="btn btn-sm btn-ghost"
                           href="<?= e(url('monitoring/index.php')) ?>?tab=traffic&iface=<?= e(urlencode($name)) ?>">Grafik</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <div class="text-sm text-muted" style="margin-top:10px">
            Nilai RX/TX adalah kecepatan saat ini (bit/detik) dihitung dari selisih counter antar refresh.
            Grafik historis terisi otomatis setiap halaman ini dibuka atau via cron.
        </div>
    <?php endif; ?>
</div>

<?php if ($selectedIface !== ''): ?>
<div class="card">
    <div class="flex-between">
        <h3 class="card-title mb-0">Grafik Traffic — <code><?= e($selectedIface) ?></code> (3 jam terakhir)</h3>
        <a href="<?= e(url('monitoring/index.php')) ?>?tab=traffic" class="btn btn-sm btn-ghost">Tutup</a>
    </div>
    <?php if (empty($history)): ?>
        <p class="text-muted">Belum ada data historis. Biarkan halaman terbuka atau jalankan cron monitoring
            (<code>cron/mikrotik.php</code>) beberapa kali untuk mengisi grafik.</p>
    <?php else: ?>
        <div class="chart chart-lg">
            <?php foreach ($history as $h): ?>
                <?php
                $hRx = max(2, round(((int)$h['rx_bps'] / $maxHist) * 100));
                $hTx = max(2, round(((int)$h['tx_bps'] / $maxHist) * 100));
                ?>
                <div class="chart-col" title="<?= e(format_date($h['recorded_at'], 'H:i')) ?> · RX <?= e(format_bps($h['rx_bps'])) ?> · TX <?= e(format_bps($h['tx_bps'])) ?>">
                    <div class="chart-pair">
                        <span class="chart-bar" style="height:<?= $hRx ?>%"></span>
                        <span class="chart-bar tx" style="height:<?= $hTx ?>%"></span>
                    </div>
                    <span class="chart-label"><?= e(date('H:i', strtotime($h['recorded_at']))) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="text-sm text-muted" style="margin-top:10px">
            <span class="legend-dot" style="background:var(--primary)"></span> RX (unduh)
            <span class="legend-dot" style="background:var(--accent)"></span> TX (unggah)
            · Puncak <?= e(format_bps($maxHist)) ?>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php elseif ($tab === 'pppoe'): ?>
<div class="card">
    <h3 class="card-title">PPPoE Active (<?= count($pppoe) ?>)</h3>
    <?php if (empty($pppoe)): ?>
        <p class="text-muted">Tidak ada sesi PPPoE aktif.</p>
    <?php else: ?>
        <div class="table-wrap">
        <table class="table">
            <thead><tr><th>User</th><th>Service</th><th>Address</th><th>Caller ID</th><th>Uptime</th><th>Encoding</th></tr></thead>
            <tbody>
            <?php foreach ($pppoe as $p): ?>
                <tr>
                    <td><strong><?= e($p['name'] ?? '-') ?></strong></td>
                    <td class="text-muted"><?= e($p['service'] ?? '-') ?></td>
                    <td><?= e($p['address'] ?? '-') ?></td>
                    <td class="text-muted"><?= e($p['caller-id'] ?? '-') ?></td>
                    <td><?= e($p['uptime'] ?? '-') ?></td>
                    <td class="text-muted"><?= e($p['encoding'] ?? '-') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>

<?php elseif ($tab === 'dhcp'): ?>
<div class="card">
    <h3 class="card-title">DHCP Lease (<?= count($dhcp) ?> · <?= $dhcpBound ?> bound)</h3>
    <?php if (empty($dhcp)): ?>
        <p class="text-muted">Tidak ada DHCP lease.</p>
    <?php else: ?>
        <div class="table-wrap">
        <table class="table">
            <thead><tr><th>IP Address</th><th>MAC Address</th><th>Hostname</th><th>Server</th><th>Status</th><th>Expires</th></tr></thead>
            <tbody>
            <?php foreach ($dhcp as $l): ?>
                <?php $isBound = ($l['status'] ?? '') === 'bound'; ?>
                <tr>
                    <td><code><?= e($l['address'] ?? '-') ?></code></td>
                    <td class="text-muted"><?= e($l['mac-address'] ?? '-') ?></td>
                    <td><?= e($l['host-name'] ?? '-') ?></td>
                    <td class="text-muted"><?= e($l['server'] ?? '-') ?></td>
                    <td><span class="badge <?= $isBound ? 'badge-resolved' : 'badge-closed' ?>"><?= e($l['status'] ?? '-') ?></span></td>
                    <td class="text-muted"><?= e($l['expires-after'] ?? 'dynamic') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>

<?php else: ?>
<div class="card">
    <h3 class="card-title">Simple Queue (<?= count($queues) ?>)</h3>
    <?php if (empty($queues)): ?>
        <p class="text-muted">Tidak ada simple queue di router.</p>
    <?php else: ?>
        <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Nama</th><th>Target</th><th>Max Limit</th><th>Rate</th><th>Bytes</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($queues as $q): ?>
                <tr>
                    <td><strong><?= e($q['name'] ?? '-') ?></strong></td>
                    <td class="text-muted"><?= e($q['target'] ?? '-') ?></td>
                    <td><?= e($q['max-limit'] ?? '-') ?></td>
                    <td><?= e($q['rate'] ?? '0/0') ?></td>
                    <td class="text-muted"><?= e($q['bytes'] ?? '-') ?></td>
                    <td>
                        <?php if (($q['disabled'] ?? 'false') === 'true'): ?>
                            <span class="badge badge-closed">disabled</span>
                        <?php else: ?>
                            <span class="badge badge-resolved">active</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>

<?php if (!empty($queues)): ?>
<div class="card">
    <h3 class="card-title">Grafik Simple Queue</h3>
    <div class="text-sm text-muted" style="margin-bottom:10px">
        Bar biru = RX (unduh), bar hijau = TX (unggah) relatif terhadap batas (<code>max-limit</code>) masing-masing queue.
        Angka RX/TX pada tabel di atas dibaca langsung dari router.
    </div>
    <?php
    $qRows = [];
    foreach ($queues as $q) {
        $limit = explode('/', (string)($q['max-limit'] ?? '0/0'));
        $limRx = (int)preg_replace('/[^0-9]/', '', $limit[0] ?? '0');
        $limTx = (int)preg_replace('/[^0-9]/', '', $limit[1] ?? '0');
        $rate = explode('/', (string)($q['rate'] ?? '0/0'));
        $rx = (int)preg_replace('/[^0-9]/', '', $rate[0] ?? '0');
        $tx = (int)preg_replace('/[^0-9]/', '', $rate[1] ?? '0');
        $qRows[] = [
            'name' => $q['name'] ?? '-', 'rx' => $rx, 'tx' => $tx,
            'lim_rx' => $limRx, 'lim_tx' => $limTx,
            'pct_rx' => $limRx > 0 ? min(100, round(($rx / $limRx) * 100)) : 0,
            'pct_tx' => $limTx > 0 ? min(100, round(($tx / $limTx) * 100)) : 0,
        ];
    }
    ?>
    <?php foreach ($qRows as $qr): ?>
        <div class="queue-row">
            <div class="queue-name"><?= e($qr['name']) ?>
                <?php if ($qr['lim_rx'] > 0): ?>
                    <span class="text-muted text-sm">· limit ↓<?= e(format_bps($qr['lim_rx'] * 1000)) ?> / ↑<?= e(format_bps($qr['lim_tx'] * 1000)) ?></span>
                <?php endif; ?>
            </div>
            <div class="queue-bars" title="RX <?= $qr['pct_rx'] ?>% dari batas">
                <span class="queue-bar rx" style="width:<?= max(2, $qr['pct_rx']) ?>%"></span>
            </div>
            <div class="queue-bars" title="TX <?= $qr['pct_tx'] ?>% dari batas">
                <span class="queue-bar tx" style="width:<?= max(2, $qr['pct_tx']) ?>%"></span>
            </div>
            <div class="queue-value text-sm text-muted">↓ <?= $qr['pct_rx'] ?>% · ↑ <?= $qr['pct_tx'] ?>%</div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
