<?php
/**
 * Notification Center — daftar notifikasi in-app milik user (Batch 3).
 * Notifikasi email & WhatsApp menyusul di Batch 5.
 */
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/url.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ticket.php';

$user = current_user();

// Tandai dibaca / hapus
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check()) {
    $op = $_POST['op'] ?? '';
    if ($op === 'read_all') {
        $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?')->execute([$user['id']]);
    } elseif ($op === 'read' && !empty($_POST['id'])) {
        $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?')->execute([(int)$_POST['id'], $user['id']]);
    } elseif ($op === 'delete' && !empty($_POST['id'])) {
        $pdo->prepare('DELETE FROM notifications WHERE id = ? AND user_id = ?')->execute([(int)$_POST['id'], $user['id']]);
    }
    header('Location: ' . url('notifications.php'));
    exit;
}

$pageTitle = 'Notifikasi';
$items = [];
try {
    $stmt = $pdo->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 100');
    $stmt->execute([$user['id']]);
    $items = $stmt->fetchAll();
} catch (Throwable $e) {
    error_log('[NOTIF LIST] ' . $e->getMessage());
}

$typeIcon = [
    'info' => 'ℹ', 'ticket_created' => '✉', 'comment' => '💬', 'assigned' => '👤',
    'status_changed' => '↻', 'priority_changed' => '⚑', 'escalated' => '⚠',
    'transferred' => '⇄', 'reopened' => '↺', 'closed' => '✓', 'sla' => '⏰',
];

require_once __DIR__ . '/includes/header.php';
?>

<div class="flex-between" style="margin-bottom:16px">
    <h2 class="mt-0 mb-0">Notifikasi</h2>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="op" value="read_all">
        <button type="submit" class="btn btn-ghost">Tandai Semua Dibaca</button>
    </form>
</div>

<div class="card">
    <?php if (empty($items)): ?>
        <div class="empty-state">
            <div class="empty-icon">🔔</div>
            <p>Belum ada notifikasi.</p>
        </div>
    <?php else: ?>
        <ul class="notif-list">
            <?php foreach ($items as $n): ?>
            <li class="notif-item<?= empty($n['is_read']) ? ' unread' : '' ?>">
                <div class="notif-icon"><?= e($typeIcon[$n['type']] ?? '•') ?></div>
                <div class="notif-body">
                    <div class="notif-title"><?= e($n['title']) ?></div>
                    <?php if (!empty($n['message'])): ?><div class="notif-message"><?= e($n['message']) ?></div><?php endif; ?>
                    <div class="notif-meta"><?= e(time_ago($n['created_at'])) ?> · <?= e(format_date($n['created_at'])) ?></div>
                </div>
                <div class="notif-actions">
                    <?php if (!empty($n['link'])): ?>
                        <a href="<?= e(url($n['link'])) ?>" class="btn btn-sm btn-ghost">Buka</a>
                    <?php endif; ?>
                    <?php if (empty($n['is_read'])): ?>
                    <form method="post" style="display:inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="op" value="read">
                        <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-ghost" title="Tandai dibaca">✓</button>
                    </form>
                    <?php endif; ?>
                    <form method="post" style="display:inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="op" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-ghost" title="Hapus">✕</button>
                    </form>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
