<?php
/**
 * Detail Tiket: info, komunikasi, lampiran, rating, riwayat (Batch 2).
 * Aksi manajemen (assign/status/transfer) ada di Batch 3.
 */
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/url.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ticket.php';

$user = current_user();
$it = is_it($pdo);
$ticketId = (int)($_GET['id'] ?? 0);

$ticket = null;
try {
    $stmt = $pdo->prepare(
        'SELECT t.*, c.name AS category_name FROM tickets t
         LEFT JOIN categories c ON c.id = t.category_id
         WHERE t.id = ? LIMIT 1'
    );
    $stmt->execute([$ticketId]);
    $ticket = $stmt->fetch() ?: null;
} catch (Throwable $e) {
    error_log('[VIEW] ' . $e->getMessage());
}

if (!$ticket) {
    http_response_code(404);
    $pageTitle = 'Tiket Tidak Ditemukan';
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="card"><p>Tiket tidak ditemukan.</p><a href="' . e(url('tickets.php')) . '" class="btn btn-ghost">Kembali</a></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// Otorisasi: pemilik tiket atau tim IT
if ((int)$ticket['user_id'] !== (int)$user['id'] && !$it) {
    http_response_code(403);
    $pageTitle = 'Akses Ditolak';
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="card"><p>Anda tidak memiliki akses ke tiket ini.</p><a href="' . e(url('tickets.php')) . '" class="btn btn-ghost">Kembali</a></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// Semua POST diproses SEBELUM header dirender, agar redirect berjalan.
$pageTitle = 'Tiket ' . $ticket['ticket_number'];

// --- POST: tambah komentar ---
$commentError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'comment') {
    if (!csrf_check()) {
        $commentError = 'Token keamanan tidak valid.';
    } else {
        $text = trim($_POST['comment'] ?? '');
        $isInternal = ($it && !empty($_POST['is_internal'])) ? 1 : 0;
        if (mb_strlen($text) < 1) {
            $commentError = 'Komentar tidak boleh kosong.';
        } else {
            try {
                $stmt = $pdo->prepare('INSERT INTO ticket_comments (ticket_id, user_id, user_name, comment, is_internal) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([$ticketId, $user['id'], $user['name'], $text, $isInternal]);
                add_ticket_history($pdo, $ticketId, $user['id'], $user['name'], 'comment', null, $isInternal ? 'internal' : 'public', $isInternal ? 'Catatan internal' : 'Komentar ditambahkan');
                // Notifikasi lawan bicara
                if ($isInternal) {
                    // Catatan internal tidak perlu notifikasi ke pelapor
                } elseif ((int)$ticket['user_id'] === (int)$user['id'] && !empty($ticket['assigned_to'])) {
                    notify_user($pdo, (int)$ticket['assigned_to'], 'Komentar baru pada ' . $ticket['ticket_number'], mb_substr($text, 0, 120), 'tickets/view.php?id=' . $ticketId, 'comment');
                } elseif ((int)$ticket['user_id'] !== (int)$user['id']) {
                    notify_user($pdo, (int)$ticket['user_id'], 'Balasan baru pada ' . $ticket['ticket_number'], mb_substr($text, 0, 120), 'tickets/view.php?id=' . $ticketId, 'comment');
                }
                header('Location: ' . url('tickets/view.php') . '?id=' . $ticketId);
                exit;
            } catch (Throwable $e) {
                error_log('[COMMENT] ' . $e->getMessage());
                $commentError = 'Gagal mengirim komentar.';
            }
        }
    }
}

// --- POST: upload lampiran tambahan ---
$uploadError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload') {
    if (!csrf_check()) {
        $uploadError = 'Token keamanan tidak valid.';
    } elseif (!empty($_FILES['attachments'])) {
        $up = handle_ticket_uploads($pdo, $ticketId, $user['id'], $_FILES['attachments']);
        if (!$up['ok']) $uploadError = $up['error'];
        if ($up['count'] > 0) {
            add_ticket_history($pdo, $ticketId, $user['id'], $user['name'], 'attachment', null, $up['count'] . ' file', 'Lampiran tambahan diunggah');
        }
        if (empty($uploadError)) {
            header('Location: ' . url('tickets/view.php') . '?id=' . $ticketId);
            exit;
        }
    }
}

// --- POST: rating layanan ---
$ratingError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'rating') {
    if (!csrf_check()) {
        $ratingError = 'Token keamanan tidak valid.';
    } else {
        $rating = (int)($_POST['rating'] ?? 0);
        $ratingComment = trim($_POST['rating_comment'] ?? '');
        if ($rating < 1 || $rating > 5) {
            $ratingError = 'Rating harus antara 1 sampai 5.';
        } elseif (!in_array($ticket['status'], ['resolved', 'closed'], true)) {
            $ratingError = 'Rating hanya dapat diberikan setelah tiket selesai.';
        } elseif (!empty($ticket['rating'])) {
            $ratingError = 'Anda sudah memberikan rating untuk tiket ini.';
        } else {
            try {
                $stmt = $pdo->prepare('UPDATE tickets SET rating = ?, rating_comment = ? WHERE id = ?');
                $stmt->execute([$rating, $ratingComment ?: null, $ticketId]);
                add_ticket_history($pdo, $ticketId, $user['id'], $user['name'], 'rated', null, (string)$rating, 'Rating layanan: ' . $rating . '/5');
                $ticket['rating'] = $rating;
                $ticket['rating_comment'] = $ratingComment;
                header('Location: ' . url('tickets/view.php') . '?id=' . $ticketId);
                exit;
            } catch (Throwable $e) {
                error_log('[RATING] ' . $e->getMessage());
                $ratingError = 'Gagal menyimpan rating.';
            }
        }
    }
}

// --- Data pendukung ---
$comments = [];
$attachments = [];
$history = [];
try {
    $stmt = $pdo->prepare('SELECT * FROM ticket_comments WHERE ticket_id = ? ORDER BY created_at ASC');
    $stmt->execute([$ticketId]);
    $comments = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT * FROM ticket_attachments WHERE ticket_id = ? ORDER BY created_at ASC');
    $stmt->execute([$ticketId]);
    $attachments = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT * FROM ticket_history WHERE ticket_id = ? ORDER BY created_at ASC');
    $stmt->execute([$ticketId]);
    $history = $stmt->fetchAll();
} catch (Throwable $e) {
    error_log('[VIEW DATA] ' . $e->getMessage());
}

$canRate = in_array($ticket['status'], ['resolved', 'closed'], true) && empty($ticket['rating']) && (int)$ticket['user_id'] === (int)$user['id'];
$created = isset($_GET['created']);

require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($created): ?>
    <div class="alert alert-success">Tiket <strong><?= e($ticket['ticket_number']) ?></strong> berhasil dibuat. Tim IT akan segera menindaklanjuti.</div>
<?php endif; ?>

<div class="flex-between" style="margin-bottom:16px">
    <h2 class="mt-0 mb-0"><?= e($ticket['ticket_number']) ?></h2>
    <div><?= status_badge($ticket['status']) ?> <?= priority_badge($ticket['priority']) ?></div>
</div>

<div class="card">
    <h3 class="card-title"><?= e($ticket['title']) ?></h3>
    <table class="table">
        <tbody>
            <tr><th style="width:180px">Kategori</th><td><?= e($ticket['category_name'] ?? '-') ?></td></tr>
            <tr><th>Pelapor</th><td><?= e($ticket['user_name'] ?? '-') ?> (<?= e($ticket['user_email'] ?? '-') ?>)</td></tr>
            <tr><th>Nama Bidang / Divisi</th><td><?= e($ticket['nama_bidang'] ?? '-') ?> / <?= e($ticket['nama_divisi'] ?? '-') ?></td></tr>
            <tr><th>Petugas</th><td><?= e($ticket['assigned_name'] ?? 'Belum ditugaskan') ?></td></tr>
            <tr><th>Dibuat</th><td><?= e(format_date($ticket['created_at'])) ?> (<?= e(time_ago($ticket['created_at'])) ?>)</td></tr>
            <?php if (!empty($ticket['sla_due_at'])): ?>
            <tr><th>Target SLA</th><td><?= e(format_date($ticket['sla_due_at'])) ?></td></tr>
            <?php endif; ?>
            <?php if (!empty($ticket['network_type'])): ?>
            <tr><th>Jenis Gangguan</th><td><?= e($ticket['network_type']) ?></td></tr>
            <?php endif; ?>
            <?php if (!empty($ticket['location_room'])): ?>
            <tr><th>Lokasi / Ruangan</th><td><?= e($ticket['location_room']) ?></td></tr>
            <?php endif; ?>
            <?php if (!empty($ticket['affected_device'])): ?>
            <tr><th>Perangkat Bermasalah</th><td><?= e($ticket['affected_device']) ?></td></tr>
            <?php endif; ?>
            <?php if (!empty($ticket['technician_result'])): ?>
            <tr><th>Hasil Pemeriksaan</th><td><?= e($ticket['technician_result']) ?></td></tr>
            <?php endif; ?>
        </tbody>
    </table>
    <h4 style="margin:16px 0 6px">Deskripsi</h4>
    <p style="white-space:pre-wrap"><?= e($ticket['description']) ?></p>

    <?php if (!empty($ticket['rating'])): ?>
    <h4 style="margin:16px 0 6px">Rating Layanan</h4>
    <p><span class="rating-stars"><?= str_repeat('★', (int)$ticket['rating']) ?><?= str_repeat('☆', 5 - (int)$ticket['rating']) ?></span>
       <strong><?= (int)$ticket['rating'] ?>/5</strong>
       <?php if (!empty($ticket['rating_comment'])): ?> — <?= e($ticket['rating_comment']) ?><?php endif; ?></p>
    <?php endif; ?>
</div>

<div class="card">
    <h3 class="card-title">Lampiran (<?= count($attachments) ?>)</h3>
    <?php if (empty($attachments)): ?>
        <p class="text-muted">Belum ada lampiran.</p>
    <?php else: ?>
        <ul class="attachment-list">
            <?php foreach ($attachments as $a): ?>
            <li>
                <a href="<?= e(url('uploads/tickets/' . $a['file_path'])) ?>" target="_blank" rel="noopener">📎 <?= e($a['file_name']) ?></a>
                <span class="attachment-meta"><?= e(round(((int)$a['file_size']) / 1024, 1)) ?> KB · <?= e(time_ago($a['created_at'])) ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data" style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="upload">
        <input type="file" name="attachments[]" class="form-control" style="max-width:320px" multiple accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.xls,.xlsx,.txt,.zip,.mp4">
        <button type="submit" class="btn btn-ghost">Upload Lampiran</button>
    </form>
    <?php if ($uploadError): ?><div class="alert alert-error" style="margin-top:10px"><?= e($uploadError) ?></div><?php endif; ?>
</div>

<div class="card">
    <h3 class="card-title">Komunikasi Tiket</h3>
    <?php if (empty($comments)): ?>
        <p class="text-muted">Belum ada komentar.</p>
    <?php else: ?>
        <?php foreach ($comments as $c): ?>
            <?php if (!empty($c['is_internal']) && !$it) continue; ?>
            <div class="comment-item<?= !empty($c['is_internal']) ? ' is-internal' : '' ?>">
                <div class="comment-head">
                    <span style="display:flex;align-items:center;gap:8px">
                        <?= avatar_html(['name' => $c['user_name'] ?? '-'], 28) ?>
                        <strong><?= e($c['user_name'] ?? '-') ?></strong>
                        <?php if (!empty($c['is_internal'])): ?><span class="badge badge-waiting">Internal</span><?php endif; ?>
                        <?php if (!empty($c['user_id']) && (int)$c['user_id'] === (int)$ticket['user_id']): ?><span class="badge badge-open">Pelapor</span><?php endif; ?>
                    </span>
                    <span class="text-muted text-sm"><?= e(format_date($c['created_at'])) ?> · <?= e(time_ago($c['created_at'])) ?></span>
                </div>
                <p class="comment-body"><?= e($c['comment']) ?></p>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($commentError): ?><div class="alert alert-error" style="margin-top:10px"><?= e($commentError) ?></div><?php endif; ?>
    <form method="post" style="margin-top:14px">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="comment">
        <div class="form-group">
            <label for="comment">Tulis komentar</label>
            <textarea id="comment" name="comment" class="form-control" placeholder="Tulis pesan untuk <?= $it ? 'pelapor / tim IT' : 'tim IT' ?>..."></textarea>
        </div>
        <?php if ($it): ?>
        <label style="display:flex;gap:6px;align-items:center;margin-bottom:12px">
            <input type="checkbox" name="is_internal" value="1"> Catatan internal (hanya terlihat tim IT)
        </label>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary">Kirim Komentar</button>
    </form>
</div>

<?php if ($canRate): ?>
<div class="card">
    <h3 class="card-title">Beri Rating Layanan</h3>
    <?php if ($ratingError): ?><div class="alert alert-error"><?= e($ratingError) ?></div><?php endif; ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="rating">
        <div class="form-group">
            <label>Rating layanan (1–5 bintang)</label>
            <div class="rating-picker" id="ratingPicker">
                <?php foreach ([1 => 'Tidak Puas', 2 => 'Kurang Puas', 3 => 'Cukup', 4 => 'Puas', 5 => 'Sangat Puas'] as $val => $lbl): ?>
                    <input type="radio" id="rating<?= $val ?>" name="rating" value="<?= $val ?>"<?= $val === 5 ? ' checked' : '' ?>>
                    <label for="rating<?= $val ?>" title="<?= $val ?> — <?= e($lbl) ?>">★</label>
                <?php endforeach; ?>
            </div>
            <div class="text-muted text-sm" id="ratingLabel" style="margin-top:4px">Sangat Puas</div>
        </div>
        <div class="form-group">
            <label for="rating_comment">Komentar (opsional)</label>
            <textarea id="rating_comment" name="rating_comment" class="form-control" placeholder="Ceritakan pengalaman Anda..."></textarea>
        </div>
        <button type="submit" class="btn btn-primary">Kirim Rating</button>
    </form>
</div>
<?php endif; ?>

<div class="card">
    <h3 class="card-title">Riwayat Penanganan</h3>
    <?php if (empty($history)): ?>
        <p class="text-muted">Belum ada riwayat.</p>
    <?php else: ?>
        <ul class="timeline">
            <?php foreach ($history as $h): ?>
            <li>
                <div class="timeline-action">
                    <?= e(history_label($h['action'])) ?>
                    <?php if (!empty($h['new_value'])): ?> <span class="text-muted">→</span> <?= e($h['new_value']) ?><?php endif; ?>
                </div>
                <div class="timeline-meta">oleh <?= e($h['user_name'] ?? '-') ?> · <?= e(format_date($h['created_at'])) ?></div>
                <?php if (!empty($h['notes'])): ?><div class="text-sm"><?= e($h['notes']) ?></div><?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <div style="margin-top:14px"><a href="<?= e(url('tickets.php')) ?>" class="btn btn-ghost">← Kembali ke Daftar Tiket</a></div>
</div>

<script>
(function () {
    var names = {1: 'Tidak Puas', 2: 'Kurang Puas', 3: 'Cukup', 4: 'Puas', 5: 'Sangat Puas'};
    var picker = document.getElementById('ratingPicker');
    var out = document.getElementById('ratingLabel');
    if (!picker || !out) return;
    picker.addEventListener('change', function (ev) {
        if (ev.target.name === 'rating') out.textContent = names[ev.target.value] || '';
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
