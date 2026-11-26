<?php
/**
 * Reporting & Analytics (Batch 6) — dashboard manajerial.
 * Filter periode, ringkasan KPI, tren harian, kategori, prioritas,
 * petugas, ruangan, kepuasan. Export PDF & Excel (CSV) tersedia.
 */
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/url.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/reports.php';
require_it($pdo);

// ---------- Periode ----------
$preset = $_GET['preset'] ?? 'month';
$fromDate = $_GET['from'] ?? '';
$toDate = $_GET['to'] ?? '';

switch ($preset) {
    case 'today':
        $fromDate = date('Y-m-d'); $toDate = date('Y-m-d');
        break;
    case 'week':
        $fromDate = date('Y-m-d', strtotime('monday this week')); $toDate = date('Y-m-d');
        break;
    case 'month':
        $fromDate = date('Y-m-01'); $toDate = date('Y-m-d');
        break;
    case 'last_month':
        $fromDate = date('Y-m-01', strtotime('first day of last month'));
        $toDate = date('Y-m-t', strtotime('first day of last month'));
        break;
    case 'year':
        $fromDate = date('Y-01-01'); $toDate = date('Y-m-d');
        break;
    case 'custom':
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) $fromDate = date('Y-m-01');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) $toDate = date('Y-m-d');
        break;
    default:
        $preset = 'month';
        $fromDate = date('Y-m-01'); $toDate = date('Y-m-d');
}

$from = $fromDate . ' 00:00:00';
$to = $toDate . ' 23:59:59';
$rangeLabel = report_range_label($from, $to);

$summary = report_summary($pdo, $from, $to);
$byCategory = report_by_category($pdo, $from, $to);
$byPriority = report_by_priority($pdo, $from, $to);
$byOfficer = report_by_officer($pdo, $from, $to);
$byRoom = report_by_room($pdo, $from, $to);
$daily = report_daily($pdo, $from, $to);
$ratings = report_ratings($pdo, $from, $to);

$prioLabel = ['critical' => 'Critical', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low'];
$maxDaily = 1;
foreach ($daily as $d) { $maxDaily = max($maxDaily, (int)$d['total']); }
$maxCat = 1;
foreach ($byCategory as $c) { $maxCat = max($maxCat, (int)$c['total']); }
$ratingTotal = array_sum($ratings);

$pageTitle = 'Laporan & Analitik';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="flex-between" style="margin-bottom:16px">
    <div>
        <h2 class="mt-0 mb-0">Laporan &amp; Analitik</h2>
        <div class="text-muted text-sm"><?= e($rangeLabel) ?></div>
    </div>
    <div class="quick-actions">
        <a href="<?= e(url('reports/export.php')) ?>?type=summary&format=pdf&from=<?= e($fromDate) ?>&to=<?= e($toDate) ?>"
           class="btn btn-primary" target="_blank" rel="noopener">⬇ Export PDF</a>
        <a href="<?= e(url('reports/export.php')) ?>?type=tickets&format=csv&from=<?= e($fromDate) ?>&to=<?= e($toDate) ?>"
           class="btn btn-ghost">⬇ Export CSV</a>
    </div>
</div>

<div class="card">
    <form method="get" class="search-row">
        <select name="preset" class="form-control" style="max-width:170px" onchange="this.form.submit()" id="presetSelect">
            <?php foreach (['today' => 'Hari ini', 'week' => 'Minggu ini', 'month' => 'Bulan ini', 'last_month' => 'Bulan lalu', 'year' => 'Tahun ini', 'custom' => 'Kustom'] as $k => $v): ?>
                <option value="<?= $k ?>"<?= $preset === $k ? ' selected' : '' ?>><?= e($v) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="date" name="from" class="form-control" style="max-width:160px" value="<?= e($fromDate) ?>">
        <span class="text-muted">s/d</span>
        <input type="date" name="to" class="form-control" style="max-width:160px" value="<?= e($toDate) ?>">
        <button type="submit" class="btn btn-primary">Terapkan</button>
    </form>
</div>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-label">Total Tiket</div><div class="stat-value"><?= (int)$summary['total'] ?></div><div class="stat-sub"><?= (int)$summary['network'] ?> gangguan jaringan</div></div>
    <div class="stat-card"><div class="stat-label">Selesai</div><div class="stat-value" style="color:var(--success)"><?= (int)$summary['resolved'] + (int)$summary['closed'] ?></div><div class="stat-sub"><?= (int)$summary['resolved'] ?> resolved · <?= (int)$summary['closed'] ?> closed</div></div>
    <div class="stat-card"><div class="stat-label">Masih Terbuka</div><div class="stat-value" style="color:var(--warning)"><?= (int)$summary['open'] ?></div><div class="stat-sub"><?= (int)$summary['unassigned'] ?> belum ditugaskan</div></div>
    <div class="stat-card"><div class="stat-label">Melewati SLA</div><div class="stat-value" style="color:var(--danger)"><?= (int)$summary['breached'] ?></div><div class="stat-sub">Kepatuhan <?= e($summary['compliance']) ?>%</div></div>
    <div class="stat-card"><div class="stat-label">Rata-rata Respons</div><div class="stat-value" style="font-size:20px"><?= e(report_fmt_minutes($summary['avg_response_min'])) ?></div></div>
    <div class="stat-card"><div class="stat-label">Rata-rata Penyelesaian</div><div class="stat-value" style="font-size:20px"><?= e(report_fmt_minutes($summary['avg_resolution_min'])) ?></div></div>
    <div class="stat-card"><div class="stat-label">Kepuasan Pengguna</div>
        <div class="stat-value"><?= $summary['avg_rating'] !== null ? e($summary['avg_rating']) . ' <span style="font-size:14px;color:var(--muted)">/5</span>' : '—' ?></div>
        <div class="stat-sub"><?= (int)$summary['ratings'] ?> penilaian</div>
    </div>
</div>

<div class="action-grid">
    <div class="card" style="margin-bottom:0">
        <h3 class="card-title">Tren Tiket Harian</h3>
        <?php if (empty($daily)): ?>
            <p class="text-muted">Belum ada data pada periode ini.</p>
        <?php else: ?>
            <div class="chart">
                <?php foreach ($daily as $d): ?>
                    <?php
                    $h = max(3, round(((int)$d['total'] / $maxDaily) * 100));
                    $hs = (int)$d['total'] > 0 ? round(((int)$d['selesai'] / (int)$d['total']) * $h) : 0;
                    ?>
                    <div class="chart-col" title="<?= e(format_date($d['d'], 'd M')) ?>: <?= (int)$d['total'] ?> tiket, <?= (int)$d['selesai'] ?> selesai">
                        <div class="chart-bar" style="height:<?= $h ?>%">
                            <span class="chart-bar-done" style="height:<?= $hs ?>%"></span>
                        </div>
                        <span class="chart-label"><?= e(date('d/m', strtotime($d['d']))) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="text-sm text-muted" style="margin-top:10px">
                <span class="legend-dot" style="background:var(--primary)"></span> Total tiket
                <span class="legend-dot" style="background:var(--success)"></span> Selesai
            </div>
        <?php endif; ?>
    </div>

    <div class="card" style="margin-bottom:0">
        <h3 class="card-title">Kepuasan Pengguna</h3>
        <?php if ($ratingTotal === 0): ?>
            <p class="text-muted">Belum ada penilaian pada periode ini.</p>
        <?php else: ?>
            <?php foreach ([5, 4, 3, 2, 1] as $star): ?>
                <?php $pct = round(($ratings[$star] / $ratingTotal) * 100); ?>
                <div class="bar-row" style="grid-template-columns: 60px 1fr 60px">
                    <span class="bar-label"><?= $star ?> ★</span>
                    <span class="bar-track"><span class="bar-fill<?= $star < 4 ? ' alt' : '' ?>" style="width:<?= $pct ?>%"></span></span>
                    <span class="bar-value"><?= (int)$ratings[$star] ?></span>
                </div>
            <?php endforeach; ?>
            <p class="text-muted text-sm" style="margin-top:10px">Rata-rata <?= e($summary['avg_rating']) ?>/5 dari <?= $ratingTotal ?> penilaian.</p>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <h3 class="card-title">Tiket per Kategori</h3>
    <?php if (empty($byCategory)): ?>
        <p class="text-muted">Belum ada data.</p>
    <?php else: ?>
        <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Kategori</th><th style="width:38%">Proporsi</th><th>Total</th><th>Selesai</th><th>Terlambat</th></tr></thead>
            <tbody>
            <?php foreach ($byCategory as $c): ?>
                <?php $pct = round(((int)$c['total'] / $maxCat) * 100); ?>
                <tr>
                    <td><?= e($c['label']) ?></td>
                    <td><span class="bar-track"><span class="bar-fill" style="width:<?= max(3, $pct) ?>%"></span></span></td>
                    <td><strong><?= (int)$c['total'] ?></strong></td>
                    <td><?= (int)$c['selesai'] ?></td>
                    <td><?= (int)$c['terlambat'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>

<div class="action-grid">
    <div class="card" style="margin-bottom:0">
        <h3 class="card-title">Per Prioritas</h3>
        <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Prioritas</th><th>Total</th><th>Selesai</th><th>Terlambat</th><th>Respons</th></tr></thead>
            <tbody>
            <?php foreach ($prioLabel as $p => $label): ?>
                <?php $r = $byPriority[$p] ?? null; ?>
                <tr>
                    <td><?= priority_badge($p) ?></td>
                    <td><?= $r ? (int)$r['total'] : 0 ?></td>
                    <td><?= $r ? (int)$r['selesai'] : 0 ?></td>
                    <td><?= $r ? (int)$r['terlambat'] : 0 ?></td>
                    <td><?= $r ? e(report_fmt_minutes($r['resp'] !== null ? round((float)$r['resp']) : null)) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>

    <div class="card" style="margin-bottom:0">
        <h3 class="card-title">Gangguan Jaringan per Ruangan</h3>
        <?php if (empty($byRoom)): ?>
            <p class="text-muted">Tidak ada gangguan jaringan pada periode ini.</p>
        <?php else: ?>
            <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Ruangan</th><th>Total</th><th>Terlambat</th></tr></thead>
                <tbody>
                <?php foreach ($byRoom as $r): ?>
                    <tr><td><?= e($r['room_name']) ?></td><td><?= (int)$r['total'] ?></td><td><?= (int)$r['terlambat'] ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <h3 class="card-title">Kinerja Petugas IT</h3>
    <?php if (empty($byOfficer)): ?>
        <p class="text-muted">Belum ada data.</p>
    <?php else: ?>
        <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Petugas</th><th>Total</th><th>Selesai</th><th>Terlambat</th><th>Rata-rata Respons</th><th>Rata-rata Penyelesaian</th><th>Rating</th></tr></thead>
            <tbody>
            <?php foreach ($byOfficer as $o): ?>
                <tr>
                    <td><?= e($o['officer']) ?></td>
                    <td><?= (int)$o['total'] ?></td>
                    <td><?= (int)$o['selesai'] ?></td>
                    <td><?= (int)$o['terlambat'] ?></td>
                    <td><?= e(report_fmt_minutes($o['resp'] !== null ? round((float)$o['resp']) : null)) ?></td>
                    <td><?= e(report_fmt_minutes($o['resv'] !== null ? round((float)$o['resv']) : null)) ?></td>
                    <td><?= $o['rating'] !== null ? e(round((float)$o['rating'], 1)) . ' ★' : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>

<script>
(function () {
    var form = document.querySelector('form[method="get"]');
    var sel = document.getElementById('presetSelect');
    if (!form || !sel) return;
    // Pilih rentang kustom otomatis bila pengguna mengubah tanggal manual
    form.querySelectorAll('input[type="date"]').forEach(function (inp) {
        inp.addEventListener('change', function () { sel.value = 'custom'; });
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
