<?php
/**
 * Network & Internet Complaint — dashboard (Batch 4).
 * Ringkasan gangguan jaringan, sebaran per ruangan & jenis, riwayat per ruangan.
 */
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/url.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ticket.php';
require_once __DIR__ . '/../includes/network.php';

$pageTitle = 'Pengaduan Jaringan';
$user = current_user();
$it = is_it($pdo);

$days = (int)($_GET['days'] ?? 90);
if (!in_array($days, [7, 30, 90, 365], true)) {
    $days = 90;
}

$stats = ['total' => 0, 'aktif' => 0, 'selesai' => 0, 'ruangan' => 0];
$byRoom = [];
$byType = [];
try {
    $where = "is_network_complaint = 1 AND created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)";
    // Karyawan hanya melihat ringkasan tiketnya sendiri; tim IT melihat seluruh data
    if (!$it) {
        $stmt = $pdo->prepare("SELECT COUNT(*) c FROM tickets WHERE {$where} AND user_id = ?");
        $stmt->execute([$user['id']]);
        $stats['total'] = (int)$stmt->fetch()['c'];
        $stmt = $pdo->prepare("SELECT COUNT(*) c FROM tickets WHERE {$where} AND user_id = ? AND status NOT IN ('resolved','closed')");
        $stmt->execute([$user['id']]);
        $stats['aktif'] = (int)$stmt->fetch()['c'];
        $stmt = $pdo->prepare("SELECT COUNT(*) c FROM tickets WHERE {$where} AND user_id = ? AND status IN ('resolved','closed')");
        $stmt->execute([$user['id']]);
        $stats['selesai'] = (int)$stmt->fetch()['c'];
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT COALESCE(room_id, location_room)) c FROM tickets WHERE {$where} AND user_id = ?");
        $stmt->execute([$user['id']]);
        $stats['ruangan'] = (int)$stmt->fetch()['c'];
    } else {
        $stats['total'] = (int)$pdo->query("SELECT COUNT(*) c FROM tickets WHERE {$where}")->fetch()['c'];
        $stats['aktif'] = (int)$pdo->query("SELECT COUNT(*) c FROM tickets WHERE {$where} AND status NOT IN ('resolved','closed')")->fetch()['c'];
        $stats['selesai'] = (int)$pdo->query("SELECT COUNT(*) c FROM tickets WHERE {$where} AND status IN ('resolved','closed')")->fetch()['c'];
        $stats['ruangan'] = (int)$pdo->query("SELECT COUNT(DISTINCT COALESCE(room_id, location_room)) c FROM tickets WHERE {$where}")->fetch()['c'];
        $byRoom = network_summary_by_room($pdo, $days);
        $byType = network_summary_by_type($pdo, $days);
    }
} catch (Throwable $e) {
    error_log('[NETWORK DASH] ' . $e->getMessage());
}

$rooms = get_rooms($pdo);
$types = network_types();
$maxRoom = 1;
foreach ($byRoom as $r) {
    $maxRoom = max($maxRoom, (int)$r['total']);
}
$maxType = 1;
foreach ($byType as $t) {
    $maxType = max($maxType, (int)$t['total']);
}

// Riwayat satu ruangan (bila dipilih)
$roomId = (int)($_GET['room'] ?? 0);
$room = null;
$roomHistory = [];
if ($roomId > 0) {
    try {
        $stmt = $pdo->prepare('SELECT * FROM rooms WHERE id = ? LIMIT 1');
        $stmt->execute([$roomId]);
        $room = $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        error_log('[ROOM] ' . $e->getMessage());
    }
    if ($room) {
        $roomHistory = network_tickets_by_room($pdo, $roomId);
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="flex-between" style="margin-bottom:16px">
    <div>
        <h2 class="mt-0 mb-0">Pengaduan Jaringan &amp; Internet</h2>
        <div class="text-muted text-sm">Pantau gangguan jaringan, sebaran per ruangan, dan riwayat penanganannya.</div>
    </div>
    <div class="quick-actions">
        <form method="get" style="display:flex;gap:8px">
            <select name="days" class="form-control" style="max-width:140px" onchange="this.form.submit()">
                <?php foreach ([7 => '7 hari', 30 => '30 hari', 90 => '90 hari', 365 => '1 tahun'] as $d => $l): ?>
                    <option value="<?= $d ?>"<?= $days === $d ? ' selected' : '' ?>><?= $l ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php if ($it): ?>
        <a href="<?= e(url('reports/export.php')) ?>?type=network&format=pdf&from=<?= e(date('Y-m-d', strtotime('-' . (int)$days . ' days'))) ?>&to=<?= e(date('Y-m-d')) ?>"
           class="btn btn-ghost" target="_blank" rel="noopener">⬇ Export PDF</a>
        <?php endif; ?>
        <a href="<?= e(url('tickets/create.php')) ?>?network=1" class="btn btn-primary">＋ Lapor Gangguan Jaringan</a>
    </div>
</div>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-label">Total Gangguan</div><div class="stat-value"><?= (int)$stats['total'] ?></div><div class="stat-sub"><?= (int)$days ?> hari terakhir</div></div>
    <div class="stat-card"><div class="stat-label">Masih Aktif</div><div class="stat-value" style="color:var(--warning)"><?= (int)$stats['aktif'] ?></div><div class="stat-sub">Belum selesai</div></div>
    <div class="stat-card"><div class="stat-label">Selesai</div><div class="stat-value" style="color:var(--success)"><?= (int)$stats['selesai'] ?></div><div class="stat-sub">Resolved / Closed</div></div>
    <div class="stat-card"><div class="stat-label">Ruangan Terdampak</div><div class="stat-value"><?= (int)$stats['ruangan'] ?></div><div class="stat-sub">Lokasi berbeda</div></div>
</div>

<?php if ($it): ?>
<div class="action-grid">
    <div class="card" style="margin-bottom:0">
        <h3 class="card-title">Gangguan per Ruangan</h3>
        <?php if (empty($byRoom)): ?>
            <p class="text-muted">Belum ada data gangguan jaringan pada periode ini.</p>
        <?php else: ?>
            <div class="bar-list">
                <?php foreach ($byRoom as $r): ?>
                    <?php $pct = max(4, round(((int)$r['total'] / $maxRoom) * 100)); ?>
                    <a class="bar-row" href="<?= e(url('network/index.php')) ?>?days=<?= $days ?>&room=<?= (int)($r['room_id'] ?? 0) ?>">
                        <span class="bar-label"><?= e($r['room_name']) ?></span>
                        <span class="bar-track"><span class="bar-fill" style="width:<?= $pct ?>%"></span></span>
                        <span class="bar-value"><?= (int)$r['total'] ?><?php if ((int)$r['aktif'] > 0): ?> <span class="badge badge-medium"><?= (int)$r['aktif'] ?> aktif</span><?php endif; ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="card" style="margin-bottom:0">
        <h3 class="card-title">Jenis Gangguan</h3>
        <?php if (empty($byType)): ?>
            <p class="text-muted">Belum ada data.</p>
        <?php else: ?>
            <div class="bar-list">
                <?php foreach ($byType as $t): ?>
                    <?php $pct = max(4, round(((int)$t['total'] / $maxType) * 100)); ?>
                    <div class="bar-row">
                        <span class="bar-label"><?= e($types[$t['network_type']] ?? ucfirst($t['network_type'])) ?></span>
                        <span class="bar-track"><span class="bar-fill alt" style="width:<?= $pct ?>%"></span></span>
                        <span class="bar-value"><?= (int)$t['total'] ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($room): ?>
<div class="card">
    <div class="flex-between">
        <h3 class="card-title mb-0">Riwayat Gangguan — <?= e(room_label($room)) ?></h3>
        <a href="<?= e(url('network/index.php')) ?>?days=<?= $days ?>" class="btn btn-sm btn-ghost">Tutup</a>
    </div>
    <?php if (empty($roomHistory)): ?>
        <p class="text-muted">Belum ada gangguan tercatat untuk ruangan ini.</p>
    <?php else: ?>
        <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Nomor</th><th>Judul</th><th>Jenis</th><th>Pelapor</th><th>Status</th><th>Hasil Teknisi</th><th>Tanggal</th></tr></thead>
            <tbody>
            <?php foreach ($roomHistory as $t): ?>
                <tr>
                    <td><a href="<?= e(url('tickets/view.php')) ?>?id=<?= (int)$t['id'] ?>"><?= e($t['ticket_number']) ?></a></td>
                    <td><?= e($t['title']) ?></td>
                    <td><?= e($types[$t['network_type']] ?? '-') ?></td>
                    <td class="text-muted"><?= e($t['user_name'] ?? '-') ?></td>
                    <td><?= status_badge($t['status']) ?></td>
                    <td class="text-muted text-sm"><?= e($t['technician_result'] ? mb_strimwidth($t['technician_result'], 0, 70, '…') : '—') ?></td>
                    <td class="text-muted"><?= e(format_date($t['created_at'], 'd M Y')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
    <h3 class="card-title">Riwayat Gangguan per Ruangan</h3>
    <p class="text-muted text-sm">Pilih ruangan untuk melihat seluruh riwayat gangguan jaringan di lokasi tersebut.</p>
    <div class="room-grid">
        <?php foreach ($rooms as $r): ?>
            <?php
            $countSql = $it
                ? 'SELECT COUNT(*) c FROM tickets WHERE is_network_complaint = 1 AND room_id = ?'
                : 'SELECT COUNT(*) c FROM tickets WHERE is_network_complaint = 1 AND room_id = ? AND user_id = ?';
            $cnt = 0;
            try {
                $stmt = $pdo->prepare($countSql);
                $stmt->execute($it ? [(int)$r['id']] : [(int)$r['id'], $user['id']]);
                $cnt = (int)$stmt->fetch()['c'];
            } catch (Throwable $e) { /* abaikan */ }
            ?>
            <a class="room-card" href="<?= e(url('network/index.php')) ?>?days=<?= $days ?>&room=<?= (int)$r['id'] ?>">
                <div class="room-name"><?= e($r['name']) ?></div>
                <div class="room-meta"><?= e(trim(($r['building'] ?? '') . ' ' . ($r['floor'] ?? ''))) ?></div>
                <div class="room-count"><?= $cnt ?> gangguan</div>
            </a>
        <?php endforeach; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
