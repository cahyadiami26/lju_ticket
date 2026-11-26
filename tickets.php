<?php
/**
 * Riwayat & Tracking Tiket Saya (Employee Portal — Batch 2).
 * Karyawan melihat tiketnya sendiri; tim IT melihat semua tiket.
 */
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/url.php';
require_once __DIR__ . '/includes/db.php';

$pageTitle = 'Tiket Saya';
require_once __DIR__ . '/includes/header.php';

$user = current_user();
$it = is_it($pdo);

// Filter
$status = $_GET['status'] ?? '';
$categoryId = (int)($_GET['category'] ?? 0);
$search = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

$categories = [];
try {
    $categories = $pdo->query('SELECT * FROM categories WHERE is_active = 1 ORDER BY sort_order')->fetchAll();
} catch (Throwable $e) {
    error_log('[TICKETS] ' . $e->getMessage());
}

$where = [];
$params = [];
if (!$it) {
    $where[] = 't.user_id = ?';
    $params[] = $user['id'];
}
if ($status !== '') {
    $where[] = 't.status = ?';
    $params[] = $status;
}
if ($categoryId > 0) {
    $where[] = 't.category_id = ?';
    $params[] = $categoryId;
}
if ($search !== '') {
    $where[] = '(t.ticket_number LIKE ? OR t.title LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$total = 0;
$tickets = [];
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM tickets t {$whereSql}");
    $stmt->execute($params);
    $total = (int)$stmt->fetch()['c'];

    $stmt = $pdo->prepare(
        "SELECT t.*, c.name AS category_name FROM tickets t
         LEFT JOIN categories c ON c.id = t.category_id
         {$whereSql}
         ORDER BY t.created_at DESC LIMIT {$perPage} OFFSET {$offset}"
    );
    $stmt->execute($params);
    $tickets = $stmt->fetchAll();
} catch (Throwable $e) {
    error_log('[TICKETS LIST] ' . $e->getMessage());
}
$totalPages = max(1, (int)ceil($total / $perPage));

$statusOptions = [
    '' => 'Semua Status',
    'open' => 'Open', 'assigned' => 'Assigned', 'in_progress' => 'In Progress',
    'waiting_user' => 'Waiting User', 'resolved' => 'Resolved', 'closed' => 'Closed',
];
?>

<div class="flex-between" style="margin-bottom:16px">
    <h2 class="mt-0 mb-0"><?= $it ? 'Semua Tiket' : 'Tiket Saya' ?></h2>
    <div class="quick-actions">
        <?php if ($it): ?>
            <a href="<?= e(url('admin/tickets.php')) ?>" class="btn btn-ghost">Manajemen Tiket</a>
        <?php endif; ?>
        <a href="<?= e(url('tickets/create.php')) ?>" class="btn btn-primary">＋ Buat Tiket Baru</a>
    </div>
</div>

<div class="card">
    <form method="get" class="flex-between" style="margin-bottom:16px">
        <div style="display:flex;gap:8px;flex-wrap:wrap;flex:1">
            <input type="text" name="q" class="form-control" style="max-width:240px" placeholder="Cari nomor / judul tiket..." value="<?= e($search) ?>">
            <select name="status" class="form-control" style="max-width:180px">
                <?php foreach ($statusOptions as $v => $l): ?>
                    <option value="<?= e($v) ?>"<?= $status === $v ? ' selected' : '' ?>><?= e($l) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="category" class="form-control" style="max-width:220px">
                <option value="0">Semua Kategori</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= (int)$c['id'] ?>"<?= $categoryId === (int)$c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary">Filter</button>
        </div>
    </form>

    <?php if (empty($tickets)): ?>
        <div class="empty-state">
            <div class="empty-icon">✉</div>
            <p>Belum ada tiket yang sesuai filter.</p>
            <a href="<?= e(url('tickets/create.php')) ?>" class="btn btn-primary">Buat Tiket</a>
        </div>
    <?php else: ?>
        <div class="table-wrap">
        <table class="table">
            <thead><tr>
                <th>Nomor Tiket</th><th>Judul</th><th>Kategori</th>
                <?php if ($it): ?><th>Pelapor</th><?php endif; ?>
                <th>Prioritas</th><th>Status</th><th>Dibuat</th>
            </tr></thead>
            <tbody>
            <?php foreach ($tickets as $t): ?>
                <tr>
                    <td><a href="<?= e(url('tickets/view.php')) ?>?id=<?= (int)$t['id'] ?>"><?= e($t['ticket_number']) ?></a></td>
                    <td><?= e($t['title']) ?></td>
                    <td><?= e($t['category_name'] ?? '-') ?></td>
                    <?php if ($it): ?><td><?= e($t['user_name'] ?? '-') ?></td><?php endif; ?>
                    <td><?= priority_badge($t['priority']) ?></td>
                    <td><?= status_badge($t['status']) ?></td>
                    <td class="text-muted"><?= e(time_ago($t['created_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php if ($totalPages > 1): ?>
        <div style="display:flex;gap:6px;justify-content:center;margin-top:16px">
            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                <a href="<?= e(url('tickets.php')) ?>?<?= e(http_build_query(['status' => $status, 'category' => $categoryId, 'q' => $search, 'page' => $p])) ?>"
                   class="btn btn-sm <?= $p === $page ? 'btn-primary' : 'btn-ghost' ?>"><?= $p ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
