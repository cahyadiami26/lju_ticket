<?php
/**
 * Manajemen Tiket (Batch 3) — dashboard kerja tim IT.
 * Filter lanjutan, bulk action (assign/status/prioritas), dan ringkasan SLA.
 */
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/url.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ticket.php';
require_once __DIR__ . '/../includes/ticket_actions.php';
require_it($pdo);

$pageTitle = 'Manajemen Tiket';
$user = current_user();
$flash = '';
$flashType = 'success';

// ---------- Bulk action ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $flash = 'Token keamanan tidak valid.';
        $flashType = 'error';
    } else {
        $action = $_POST['bulk_action'] ?? '';
        $ids = array_map('intval', (array)($_POST['ids'] ?? []));
        $ids = array_values(array_filter($ids));
        if (empty($ids)) {
            $flash = 'Pilih minimal satu tiket.';
            $flashType = 'error';
        } elseif ($action === '') {
            $flash = 'Pilih aksi terlebih dahulu.';
            $flashType = 'error';
        } else {
            $done = 0;
            $failed = [];
            foreach ($ids as $tid) {
                $t = get_ticket($pdo, $tid);
                if (!$t) continue;
                $res = ['ok' => false, 'message' => 'Aksi tidak dikenal.'];
                if ($action === 'assign') {
                    $res = assign_ticket($pdo, $usersPdo, $t, (int)($_POST['assign_to'] ?? 0), $user, 'Penugasan massal');
                } elseif ($action === 'status') {
                    $res = change_ticket_status($pdo, $t, $_POST['new_status'] ?? '', $user, 'Perubahan massal');
                } elseif ($action === 'priority') {
                    $res = change_ticket_priority($pdo, $t, $_POST['new_priority'] ?? '', $user, 'Perubahan massal');
                }
                if ($res['ok']) {
                    $done++;
                } else {
                    $failed[] = $t['ticket_number'] . ': ' . $res['message'];
                }
            }
            $flash = $done . ' tiket berhasil diperbarui.';
            if ($failed) {
                $flashType = 'error';
                $flash .= ' Gagal: ' . implode('; ', array_slice($failed, 0, 3));
            }
        }
    }
}

// ---------- Filter ----------
$f = [
    'status'   => $_GET['status'] ?? '',
    'priority' => $_GET['priority'] ?? '',
    'assignee' => (int)($_GET['assignee'] ?? -1),
    'category' => (int)($_GET['category'] ?? 0),
    'sla'      => $_GET['sla'] ?? '',
    'q'        => trim($_GET['q'] ?? ''),
];
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];
if ($f['status'] !== '') { $where[] = 't.status = ?'; $params[] = $f['status']; }
if ($f['priority'] !== '') { $where[] = 't.priority = ?'; $params[] = $f['priority']; }
if ($f['assignee'] === 0) {
    $where[] = 't.assigned_to IS NULL';
} elseif ($f['assignee'] > 0) {
    $where[] = 't.assigned_to = ?'; $params[] = $f['assignee'];
}
if ($f['category'] > 0) { $where[] = 't.category_id = ?'; $params[] = $f['category']; }
if ($f['sla'] === 'overdue') {
    $where[] = "t.sla_due_at IS NOT NULL AND t.sla_due_at < NOW() AND t.status NOT IN ('resolved','closed')";
} elseif ($f['sla'] === 'warning') {
    $where[] = "t.sla_due_at IS NOT NULL AND t.sla_due_at >= NOW() AND t.sla_due_at <= DATE_ADD(NOW(), INTERVAL 60 MINUTE) AND t.status NOT IN ('resolved','closed')";
}
if ($f['q'] !== '') {
    $where[] = '(t.ticket_number LIKE ? OR t.title LIKE ? OR t.user_name LIKE ?)';
    array_push($params, '%' . $f['q'] . '%', '%' . $f['q'] . '%', '%' . $f['q'] . '%');
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$tickets = [];
$total = 0;
$categories = [];
$staff = [];
$counts = ['open' => 0, 'assigned' => 0, 'in_progress' => 0, 'waiting_user' => 0, 'overdue' => 0, 'unassigned' => 0];
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM tickets t {$whereSql}");
    $stmt->execute($params);
    $total = (int)$stmt->fetch()['c'];

    $orderBy = "FIELD(t.status,'open','assigned','in_progress','waiting_user','resolved','closed'), FIELD(t.priority,'critical','high','medium','low'), t.created_at DESC";
    $stmt = $pdo->prepare(
        "SELECT t.*, c.name AS category_name FROM tickets t
         LEFT JOIN categories c ON c.id = t.category_id
         {$whereSql} ORDER BY {$orderBy} LIMIT {$perPage} OFFSET {$offset}"
    );
    $stmt->execute($params);
    $tickets = $stmt->fetchAll();

    $categories = $pdo->query('SELECT * FROM categories ORDER BY sort_order')->fetchAll();
    $staff = get_it_staff($pdo, $usersPdo);

    $counts = [
        'open'         => (int)$pdo->query("SELECT COUNT(*) c FROM tickets WHERE status = 'open'")->fetch()['c'],
        'assigned'     => (int)$pdo->query("SELECT COUNT(*) c FROM tickets WHERE status = 'assigned'")->fetch()['c'],
        'in_progress'  => (int)$pdo->query("SELECT COUNT(*) c FROM tickets WHERE status = 'in_progress'")->fetch()['c'],
        'waiting_user' => (int)$pdo->query("SELECT COUNT(*) c FROM tickets WHERE status = 'waiting_user'")->fetch()['c'],
        'overdue'      => (int)$pdo->query("SELECT COUNT(*) c FROM tickets WHERE sla_due_at IS NOT NULL AND sla_due_at < NOW() AND status NOT IN ('resolved','closed')")->fetch()['c'],
        'unassigned'   => (int)$pdo->query("SELECT COUNT(*) c FROM tickets WHERE assigned_to IS NULL AND status NOT IN ('resolved','closed')")->fetch()['c'],
    ];
} catch (Throwable $e) {
    error_log('[ADMIN TICKETS] ' . $e->getMessage());
}
$totalPages = max(1, (int)ceil($total / $perPage));

require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($flash): ?>
    <div class="alert alert-<?= $flashType === 'error' ? 'error' : 'success' ?>"><?= e($flash) ?></div>
<?php endif; ?>

<div class="stats-grid">
    <a class="stat-card stat-link" href="<?= e(url('admin/tickets.php')) ?>?status=open">
        <div class="stat-label">Open</div><div class="stat-value"><?= (int)$counts['open'] ?></div><div class="stat-sub">Belum ditangani</div>
    </a>
    <a class="stat-card stat-link" href="<?= e(url('admin/tickets.php')) ?>?status=assigned">
        <div class="stat-label">Assigned</div><div class="stat-value"><?= (int)$counts['assigned'] ?></div><div class="stat-sub">Sudah ada petugas</div>
    </a>
    <a class="stat-card stat-link" href="<?= e(url('admin/tickets.php')) ?>?status=in_progress">
        <div class="stat-label">In Progress</div><div class="stat-value"><?= (int)$counts['in_progress'] ?></div><div class="stat-sub">Sedang dikerjakan</div>
    </a>
    <a class="stat-card stat-link" href="<?= e(url('admin/tickets.php')) ?>?status=waiting_user">
        <div class="stat-label">Waiting User</div><div class="stat-value"><?= (int)$counts['waiting_user'] ?></div><div class="stat-sub">Menunggu pelapor</div>
    </a>
    <a class="stat-card stat-link" href="<?= e(url('admin/tickets.php')) ?>?sla=overdue">
        <div class="stat-label">Melewati SLA</div><div class="stat-value" style="color:var(--danger)"><?= (int)$counts['overdue'] ?></div><div class="stat-sub">Perlu eskalasi</div>
    </a>
    <a class="stat-card stat-link" href="<?= e(url('admin/tickets.php')) ?>?assignee=0">
        <div class="stat-label">Belum Ditugaskan</div><div class="stat-value"><?= (int)$counts['unassigned'] ?></div><div class="stat-sub">Tanpa petugas</div>
    </a>
</div>

<div class="card">
    <form method="get" class="search-row" style="margin-bottom:16px">
        <input type="text" name="q" class="form-control" style="max-width:240px" placeholder="Cari nomor / judul / pelapor..." value="<?= e($f['q']) ?>">
        <select name="status" class="form-control" style="max-width:150px">
            <option value="">Semua Status</option>
            <?php foreach (ticket_statuses() as $k => $v): ?>
                <option value="<?= e($k) ?>"<?= $f['status'] === $k ? ' selected' : '' ?>><?= e($v) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="priority" class="form-control" style="max-width:140px">
            <option value="">Semua Prioritas</option>
            <?php foreach (ticket_priorities() as $k => $v): ?>
                <option value="<?= e($k) ?>"<?= $f['priority'] === $k ? ' selected' : '' ?>><?= e($v) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="assignee" class="form-control" style="max-width:180px">
            <option value="-1">Semua Petugas</option>
            <option value="0"<?= $f['assignee'] === 0 ? ' selected' : '' ?>>— Belum ditugaskan —</option>
            <?php foreach ($staff as $s): ?>
                <option value="<?= (int)$s['id'] ?>"<?= $f['assignee'] === (int)$s['id'] ? ' selected' : '' ?>><?= e($s['label']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="category" class="form-control" style="max-width:190px">
            <option value="0">Semua Kategori</option>
            <?php foreach ($categories as $c): ?>
                <option value="<?= (int)$c['id'] ?>"<?= $f['category'] === (int)$c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="sla" class="form-control" style="max-width:170px">
            <option value="">Semua SLA</option>
            <option value="warning"<?= $f['sla'] === 'warning' ? ' selected' : '' ?>>Mendekati batas (&lt;1 jam)</option>
            <option value="overdue"<?= $f['sla'] === 'overdue' ? ' selected' : '' ?>>Melewati SLA</option>
        </select>
        <button type="submit" class="btn btn-primary">Terapkan</button>
        <a href="<?= e(url('admin/tickets.php')) ?>" class="btn btn-ghost">Reset</a>
    </form>

    <form method="post">
        <?= csrf_field() ?>

        <div class="bulk-bar">
            <span class="text-sm text-muted">Aksi massal untuk tiket terpilih:</span>
            <select name="bulk_action" class="form-control" style="max-width:170px">
                <option value="">— Pilih aksi —</option>
                <option value="assign">Tugaskan ke petugas</option>
                <option value="status">Ubah status</option>
                <option value="priority">Ubah prioritas</option>
            </select>
            <select name="assign_to" class="form-control" style="max-width:190px">
                <option value="">— Petugas —</option>
                <?php foreach ($staff as $s): ?>
                    <option value="<?= (int)$s['id'] ?>"><?= e($s['label']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="new_status" class="form-control" style="max-width:150px">
                <option value="">— Status —</option>
                <?php foreach (ticket_statuses() as $k => $v): ?>
                    <option value="<?= e($k) ?>"><?= e($v) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="new_priority" class="form-control" style="max-width:150px">
                <option value="">— Prioritas —</option>
                <?php foreach (ticket_priorities() as $k => $v): ?>
                    <option value="<?= e($k) ?>"><?= e($v) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary btn-sm">Terapkan</button>
        </div>

        <?php if (empty($tickets)): ?>
            <div class="empty-state">
                <div class="empty-icon">✓</div>
                <p>Tidak ada tiket yang cocok dengan filter ini.</p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th style="width:32px"><input type="checkbox" id="checkAll"></th>
                        <th>Nomor</th><th>Judul</th><th>Pelapor</th><th>Kategori</th>
                        <th>Prioritas</th><th>Status</th><th>Petugas</th><th>SLA</th><th>Dibuat</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($tickets as $t): ?>
                    <?php $sla = sla_state($t); ?>
                    <tr>
                        <td><input type="checkbox" name="ids[]" value="<?= (int)$t['id'] ?>" class="row-check"></td>
                        <td><a href="<?= e(url('tickets/view.php')) ?>?id=<?= (int)$t['id'] ?>"><?= e($t['ticket_number']) ?></a></td>
                        <td>
                            <?= e($t['title']) ?>
                            <?php if (!empty($t['is_network_complaint'])): ?><span class="badge badge-open">Jaringan</span><?php endif; ?>
                        </td>
                        <td class="text-muted"><?= e($t['user_name'] ?? '-') ?></td>
                        <td class="text-muted"><?= e($t['category_name'] ?? '-') ?></td>
                        <td><?= priority_badge($t['priority']) ?></td>
                        <td><?= status_badge($t['status']) ?></td>
                        <td class="text-muted"><?= e($t['assigned_name'] ?: '—') ?></td>
                        <td><?= sla_badge($t) ?></td>
                        <td class="text-muted"><?= e(time_ago($t['created_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </form>

    <?php if ($totalPages > 1): ?>
    <div class="pagination">
        <?php
        $qs = $_GET;
        for ($p = 1; $p <= $totalPages; $p++):
            $qs['page'] = $p;
        ?>
            <a href="?<?= e(http_build_query($qs)) ?>" class="btn btn-sm <?= $p === $page ? 'btn-primary' : 'btn-ghost' ?>"><?= $p ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>

    <div class="text-muted text-sm" style="margin-top:12px">Menampilkan <?= count($tickets) ?> dari <?= (int)$total ?> tiket.</div>
</div>

<script>
(function () {
    var all = document.getElementById('checkAll');
    if (!all) return;
    all.addEventListener('change', function () {
        document.querySelectorAll('.row-check').forEach(function (cb) { cb.checked = all.checked; });
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
