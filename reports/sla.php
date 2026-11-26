<?php
/**
 * Laporan SLA bulanan (Batch 5) — kepatuhan, rata-rata respons & penyelesaian.
 * Halaman ringkas; laporan lengkap + export PDF ada di Batch 6.
 */
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/url.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/sla.php';
require_once __DIR__ . '/../includes/reports.php';
require_it($pdo);

$pageTitle = 'Laporan SLA';
$user = current_user();

// Periode: bulan (YYYY-MM) atau rentang kustom
$month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$from = $month . '-01 00:00:00';
$to = date('Y-m-t 23:59:59', strtotime($from));

$stats = sla_report_stats($pdo, $from, $to);

// Tiket yang melewati SLA pada periode ini
$breached = [];
$byOfficer = [];
try {
    $stmt = $pdo->prepare(
        "SELECT * FROM tickets WHERE created_at BETWEEN ? AND ? AND sla_breached = 1
         ORDER BY sla_due_at ASC LIMIT 50"
    );
    $stmt->execute([$from, $to]);
    $breached = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        "SELECT COALESCE(assigned_name, 'Belum ditugaskan') AS officer,
                COUNT(*) total,
                SUM(status IN ('resolved','closed')) selesai,
                SUM(sla_breached = 1) terlambat,
                AVG(TIMESTAMPDIFF(MINUTE, created_at, responded_at)) resp
         FROM tickets WHERE created_at BETWEEN ?
         GROUP BY officer ORDER BY total DESC"
    );
    $stmt->execute([$month . '-01']);
    $byOfficer = $stmt->fetchAll();
} catch (Throwable $e) {
    error_log('[SLA REPORT PAGE] ' . $e->getMessage());
}

$prioLabel = ['critical' => 'Critical', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low'];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="flex-between" style="margin-bottom:16px">
    <div>
        <h2 class="mt-0 mb-0">Laporan SLA</h2>
        <div class="text-muted text-sm">Periode <?= e(date('F Y', strtotime($from))) ?></div>
    </div>
    <div class="quick-actions">
        <a href="<?= e(url('reports/export.php')) ?>?type=sla&format=pdf&from=<?= e(date('Y-m-01', strtotime($from))) ?>&to=<?= e(date('Y-m-d', strtotime($to))) ?>"
           class="btn btn-primary" target="_blank" rel="noopener">⬇ Export PDF</a>
        <form method="get" class="search-row">
            <input type="month" name="month" class="form-control" style="max-width:180px" value="<?= e($month) ?>">
            <button type="submit" class="btn btn-ghost">Tampilkan</button>
        </form>
    </div>
</div>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-label">Total Tiket</div><div class="stat-value"><?= (int)$stats['total'] ?></div></div>
    <div class="stat-card"><div class="stat-label">Selesai</div><div class="stat-value" style="color:var(--success)"><?= (int)$stats['resolved'] ?></div></div>
    <div class="stat-card"><div class="stat-label">Melewati SLA</div><div class="stat-value" style="color:var(--danger)"><?= (int)$stats['breached'] ?></div></div>
    <div class="stat-card"><div class="stat-label">Kepatuhan SLA</div><div class="stat-value"><?= e($stats['compliance']) ?>%</div>
        <div class="sla-meter <?= $stats['compliance'] < 80 ? 'overdue' : ($stats['compliance'] < 95 ? 'warning' : '') ?>"><span style="width:<?= min(100, (float)$stats['compliance']) ?>%"></span></div>
    </div>
    <div class="stat-card"><div class="stat-label">Rata-rata Respons</div><div class="stat-value" style="font-size:20px"><?= e(report_fmt_minutes($stats['avg_response_min'])) ?></div></div>
    <div class="stat-card"><div class="stat-label">Rata-rata Penyelesaian</div><div class="stat-value" style="font-size:20px"><?= e(report_fmt_minutes($stats['avg_resolution_min'])) ?></div></div>
</div>

<div class="card">
    <h3 class="card-title">Kepatuhan per Prioritas</h3>
    <div class="table-wrap">
    <table class="table">
        <thead><tr><th>Prioritas</th><th>Total</th><th>Selesai</th><th>Melewati SLA</th><th>Kepatuhan</th></tr></thead>
        <tbody>
        <?php foreach ($prioLabel as $p => $label): ?>
            <?php
            $r = $stats['by_priority'][$p] ?? ['total' => 0, 'done' => 0, 'breached' => 0];
            $total = (int)$r['total'];
            $comp = $total > 0 ? round((($total - (int)$r['breached']) / $total) * 100, 1) : 100;
            ?>
            <tr>
                <td><?= priority_badge($p) ?></td>
                <td><?= $total ?></td>
                <td><?= (int)$r['done'] ?></td>
                <td><?= (int)$r['breached'] ?></td>
                <td><?= e($comp) ?>%</td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<div class="card">
    <h3 class="card-title">Kinerja Petugas (bulan ini)</h3>
    <?php if (empty($byOfficer)): ?>
        <p class="text-muted">Belum ada data.</p>
    <?php else: ?>
        <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Petugas</th><th>Total</th><th>Selesai</th><th>Terlambat</th><th>Rata-rata Respons</th></tr></thead>
            <tbody>
            <?php foreach ($byOfficer as $o): ?>
                <tr>
                    <td><?= e($o['officer']) ?></td>
                    <td><?= (int)$o['total'] ?></td>
                    <td><?= (int)$o['selesai'] ?></td>
                    <td><?= (int)$o['terlambat'] ?></td>
                    <td><?= e(report_fmt_minutes($o['resp'] !== null ? round((float)$o['resp']) : null)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <h3 class="card-title">Tiket Melewati SLA (<?= count($breached) ?>)</h3>
    <?php if (empty($breached)): ?>
        <p class="text-muted">Tidak ada tiket yang melewati SLA pada periode ini. 🎉</p>
    <?php else: ?>
        <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Nomor</th><th>Judul</th><th>Prioritas</th><th>Status</th><th>Petugas</th><th>Batas SLA</th></tr></thead>
            <tbody>
            <?php foreach ($breached as $t): ?>
                <tr>
                    <td><a href="<?= e(url('tickets/view.php')) ?>?id=<?= (int)$t['id'] ?>"><?= e($t['ticket_number']) ?></a></td>
                    <td><?= e($t['title']) ?></td>
                    <td><?= priority_badge($t['priority']) ?></td>
                    <td><?= status_badge($t['status']) ?></td>
                    <td class="text-muted"><?= e($t['assigned_name'] ?: '—') ?></td>
                    <td class="text-muted"><?= e(format_date($t['sla_due_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
