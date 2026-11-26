<?php
/**
 * Dashboard — ringkasan tiket karyawan + ringkasan IT.
 * Analitik lengkap ada di Batch 6 (Reporting).
 */
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/url.php';

$pageTitle = 'Dashboard';

// Statistik jaringan (khusus tim IT)
$networkStats = ['total' => 0, 'aktif' => 0, 'ruangan' => 0, 'top_room' => '-'];
if (is_it($pdo)) {
    try {
        $networkStats['total'] = (int)$pdo->query("SELECT COUNT(*) c FROM tickets WHERE is_network_complaint = 1 AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetch()['c'];
        $networkStats['aktif'] = (int)$pdo->query("SELECT COUNT(*) c FROM tickets WHERE is_network_complaint = 1 AND status NOT IN ('resolved','closed')")->fetch()['c'];
        $networkStats['ruangan'] = (int)$pdo->query("SELECT COUNT(DISTINCT COALESCE(room_id, location_room)) c FROM tickets WHERE is_network_complaint = 1 AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetch()['c'];
        $row = $pdo->query("SELECT COALESCE(r.name, t.location_room, '-') AS room_name, COUNT(*) c FROM tickets t LEFT JOIN rooms r ON r.id = t.room_id WHERE t.is_network_complaint = 1 GROUP BY room_name ORDER BY c DESC LIMIT 1")->fetch();
        if ($row) $networkStats['top_room'] = $row['room_name'];
    } catch (Throwable $e) {
        error_log('[DASH NETWORK] ' . $e->getMessage());
    }
}
require_once __DIR__ . '/includes/header.php';

$user = current_user();
$it = is_it($pdo);

// --- Statistik tiket saya ---
$myStats = ['total' => 0, 'open' => 0, 'progress' => 0, 'resolved' => 0];
$myRecent = [];
$myAttention = 0;
try {
    $stmt = $pdo->prepare('SELECT COUNT(*) AS total FROM tickets WHERE user_id = ?');
    $stmt->execute([$user['id']]);
    $myStats['total'] = (int)$stmt->fetch()['total'];

    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM tickets WHERE user_id = ? AND status IN ('open','assigned')");
    $stmt->execute([$user['id']]);
    $myStats['open'] = (int)$stmt->fetch()['c'];

    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM tickets WHERE user_id = ? AND status IN ('in_progress','waiting_user')");
    $stmt->execute([$user['id']]);
    $myStats['progress'] = (int)$stmt->fetch()['c'];

    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM tickets WHERE user_id = ? AND status IN ('resolved','closed')");
    $stmt->execute([$user['id']]);
    $myStats['resolved'] = (int)$stmt->fetch()['c'];

    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM tickets WHERE user_id = ? AND status = 'waiting_user'");
    $stmt->execute([$user['id']]);
    $myAttention = (int)$stmt->fetch()['c'];

    // Tiket yang menunggu tanggapan saya didahulukan
    $stmt = $pdo->prepare(
        "SELECT t.*, c.name AS category_name FROM tickets t
         LEFT JOIN categories c ON c.id = t.category_id
         WHERE t.user_id = ?
         ORDER BY (t.status = 'waiting_user') DESC, t.created_at DESC LIMIT 5"
    );
    $stmt->execute([$user['id']]);
    $myRecent = $stmt->fetchAll();
} catch (Throwable $e) {
    error_log('[DASHBOARD] ' . $e->getMessage());
}

// --- Statistik IT (hanya untuk tim IT) ---
$itStats = null;
if ($it) {
    try {
        $itStats = [
            'open'      => (int)$pdo->query("SELECT COUNT(*) AS c FROM tickets WHERE status = 'open'")->fetch()['c'],
            'assigned'  => (int)$pdo->query("SELECT COUNT(*) AS c FROM tickets WHERE status = 'assigned'")->fetch()['c'],
            'progress'  => (int)$pdo->query("SELECT COUNT(*) AS c FROM tickets WHERE status IN ('in_progress','waiting_user')")->fetch()['c'],
            'critical'  => (int)$pdo->query("SELECT COUNT(*) AS c FROM tickets WHERE priority = 'critical' AND status NOT IN ('resolved','closed')")->fetch()['c'],
            'overdue'   => (int)$pdo->query("SELECT COUNT(*) AS c FROM tickets WHERE sla_due_at IS NOT NULL AND sla_due_at < NOW() AND status NOT IN ('resolved','closed')")->fetch()['c'],
        ];
    } catch (Throwable $e) {
        error_log('[DASHBOARD IT] ' . $e->getMessage());
    }
}
?>

<div class="flex-between" style="margin-bottom:16px">
    <div>
        <h2 class="mt-0 mb-0">Selamat datang, <?= e($user['name']) ?></h2>
        <div class="text-muted text-sm">
            <?= e($user['position'] ?: '-') ?> · Nama Bidang: <?= e($user['division'] ?: '-') ?> · Nama Divisi: <?= e($user['department'] ?: '-') ?>
        </div>
    </div>
    <div class="quick-actions">
        <a href="<?= e(url('tickets/create.php')) ?>" class="btn btn-primary">＋ Buat Tiket Baru</a>
        <a href="<?= e(url('faq.php')) ?>" class="btn btn-ghost">FAQ / Knowledge Base</a>
    </div>
</div>

<?php if ($myAttention > 0): ?>
<div class="alert alert-info">
    <strong><?= (int)$myAttention ?> tiket</strong> menunggu tanggapan Anda.
    <a href="<?= e(url('tickets.php')) ?>?status=waiting_user">Lihat tiket</a>
</div>
<?php endif; ?>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-label">Total Tiket Saya</div><div class="stat-value"><?= (int)$myStats['total'] ?></div><div class="stat-sub">Semua pengaduan</div></div>
    <div class="stat-card"><div class="stat-label">Menunggu / Ditugaskan</div><div class="stat-value"><?= (int)$myStats['open'] ?></div><div class="stat-sub">Status Open / Assigned</div></div>
    <div class="stat-card"><div class="stat-label">Sedang Diproses</div><div class="stat-value"><?= (int)$myStats['progress'] ?></div><div class="stat-sub">In Progress / Waiting User</div></div>
    <div class="stat-card"><div class="stat-label">Selesai</div><div class="stat-value"><?= (int)$myStats['resolved'] ?></div><div class="stat-sub">Resolved / Closed</div></div>
</div>

<?php if ($itStats): ?>
<div class="card">
    <div class="flex-between">
        <h3 class="card-title mb-0">Gangguan Jaringan (30 hari terakhir)</h3>
        <a href="<?= e(url('network/index.php')) ?>" class="btn btn-sm btn-ghost">Buka Modul Jaringan</a>
    </div>
    <div class="stats-grid" style="margin-bottom:0">
        <div class="stat-card"><div class="stat-label">Total Gangguan</div><div class="stat-value"><?= (int)$networkStats['total'] ?></div></div>
        <div class="stat-card"><div class="stat-label">Masih Aktif</div><div class="stat-value" style="color:var(--warning)"><?= (int)$networkStats['aktif'] ?></div></div>
        <div class="stat-card"><div class="stat-label">Ruangan Terdampak</div><div class="stat-value"><?= (int)$networkStats['ruangan'] ?></div></div>
        <div class="stat-card"><div class="stat-label">Gangguan Terbanyak</div><div class="stat-value" style="font-size:16px"><?= e($networkStats['top_room']) ?></div></div>
    </div>
</div>

<div class="card">
    <h3 class="card-title">Ringkasan IT (Seluruh Tiket)</h3>
    <div class="stats-grid" style="margin-bottom:0">
        <div class="stat-card"><div class="stat-label">Open</div><div class="stat-value"><?= (int)$itStats['open'] ?></div></div>
        <div class="stat-card"><div class="stat-label">Assigned</div><div class="stat-value"><?= (int)$itStats['assigned'] ?></div></div>
        <div class="stat-card"><div class="stat-label">In Progress</div><div class="stat-value"><?= (int)$itStats['progress'] ?></div></div>
        <div class="stat-card"><div class="stat-label">Critical Aktif</div><div class="stat-value"><?= (int)$itStats['critical'] ?></div></div>
        <div class="stat-card"><div class="stat-label">Melewati SLA</div><div class="stat-value"><?= (int)$itStats['overdue'] ?></div></div>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="flex-between">
        <h3 class="card-title mb-0">Tiket Terakhir Saya</h3>
        <a href="<?= e(url('tickets.php')) ?>" class="btn btn-sm btn-ghost">Lihat Semua</a>
    </div>
    <?php if (empty($myRecent)): ?>
        <div class="empty-state">
            <div class="empty-icon">✉</div>
            <p>Belum ada tiket. Jika Anda mengalami kendala IT, silakan buat tiket baru.</p>
            <a href="<?= e(url('tickets/create.php')) ?>" class="btn btn-primary">Buat Tiket</a>
        </div>
    <?php else: ?>
        <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Nomor Tiket</th><th>Judul</th><th>Kategori</th><th>Prioritas</th><th>Status</th><th>Dibuat</th></tr></thead>
            <tbody>
            <?php foreach ($myRecent as $t): ?>
                <tr>
                    <td><a href="<?= e(url('tickets/view.php')) ?>?id=<?= (int)$t['id'] ?>"><?= e($t['ticket_number']) ?></a></td>
                    <td><?= e($t['title']) ?></td>
                    <td><?= e($t['category_name'] ?? '-') ?></td>
                    <td><?= priority_badge($t['priority']) ?></td>
                    <td><?= status_badge($t['status']) ?></td>
                    <td class="text-muted"><?= e(time_ago($t['created_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
