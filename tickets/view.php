<?php
/**
 * Detail Tiket: info, komunikasi, lampiran, rating, riwayat.
 * Batch 2: portal karyawan. Batch 3: panel aksi tim IT (assign/status/prioritas/eskalasi/transfer/reopen).
 */
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/url.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ticket.php';
require_once __DIR__ . '/../includes/ticket_actions.php';
require_once __DIR__ . '/../includes/network.php';

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

// --- POST: aksi manajemen tiket (khusus tim IT) ---
$actionMsg = '';
$actionErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'manage') {
    if (!$it) {
        $actionErr = 'Hanya tim IT yang dapat melakukan aksi ini.';
    } elseif (!csrf_check()) {
        $actionErr = 'Token keamanan tidak valid.';
    } else {
        $op = $_POST['op'] ?? '';
        $note = trim($_POST['note'] ?? '');
        $res = ['ok' => false, 'message' => 'Aksi tidak dikenal.'];
        switch ($op) {
            case 'assign':
                $res = assign_ticket($pdo, $usersPdo, $ticket, (int)($_POST['assigned_to'] ?? 0), $user, $note);
                break;
            case 'status':
                $res = change_ticket_status($pdo, $ticket, $_POST['status'] ?? '', $user, $note);
                break;
            case 'priority':
                $res = change_ticket_priority($pdo, $ticket, $_POST['priority'] ?? '', $user, $note);
                break;
            case 'escalate':
                $res = escalate_ticket($pdo, $usersPdo, $ticket, $user, $note);
                break;
            case 'transfer':
                $res = transfer_ticket($pdo, $usersPdo, $ticket, (int)($_POST['transfer_to'] ?? 0), $user, $note);
                break;
            case 'reopen':
                $res = reopen_ticket($pdo, $usersPdo, $ticket, $user, $note);
                break;
        }
        if ($res['ok']) {
            header('Location: ' . url('tickets/view.php') . '?id=' . $ticketId . '&updated=1');
            exit;
        }
        $actionErr = $res['message'];
    }
}

// --- POST: aksi pelapor (konfirmasi tutup / buka kembali) ---
$ownerMsg = '';
$ownerErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'owner') {
    $isOwner = (int)$ticket['user_id'] === (int)$user['id'];
    if (!$isOwner) {
        $ownerErr = 'Aksi ini hanya untuk pelapor tiket.';
    } elseif (!csrf_check()) {
        $ownerErr = 'Token keamanan tidak valid.';
    } else {
        $op = $_POST['op'] ?? '';
        $reason = trim($_POST['reason'] ?? '');
        if ($op === 'close') {
            if ($ticket['status'] !== 'resolved') {
                $ownerErr = 'Tiket hanya bisa ditutup setelah berstatus Resolved.';
            } else {
                try {
                    $pdo->prepare("UPDATE tickets SET status = 'closed', closed_at = NOW(), updated_at = NOW() WHERE id = ?")->execute([$ticketId]);
                    add_ticket_history($pdo, $ticketId, $user['id'], $user['name'], 'closed', 'resolved', 'closed', 'Ditutup & dikonfirmasi oleh pelapor');
                    notify_it_team($pdo, $usersPdo, 'Tiket ditutup pelapor: ' . $ticket['ticket_number'], $ticket['title'], 'tickets/view.php?id=' . $ticketId, 'closed');
                    header('Location: ' . url('tickets/view.php') . '?id=' . $ticketId . '&updated=1');
                    exit;
                } catch (Throwable $e) {
                    error_log('[OWNER CLOSE] ' . $e->getMessage());
                    $ownerErr = 'Gagal menutup tiket.';
                }
            }
        } elseif ($op === 'reopen') {
            $res = reopen_ticket($pdo, $usersPdo, $ticket, $user, $reason !== '' ? $reason : 'Pelapor menyatakan masalah belum selesai');
            if ($res['ok']) {
                header('Location: ' . url('tickets/view.php') . '?id=' . $ticketId . '&updated=1');
                exit;
            }
            $ownerErr = $res['message'];
        }
    }
}

// --- POST: pemeriksaan jaringan & hasil teknisi (khusus IT) ---
$netMsg = '';
$netErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['net_check', 'tech_result'], true)) {
    if (!$it) {
        $netErr = 'Hanya tim IT yang dapat mengisi hasil pemeriksaan.';
    } elseif (!csrf_check()) {
        $netErr = 'Token keamanan tidak valid.';
    } elseif ($_POST['action'] === 'net_check') {
        $res = add_network_check($pdo, $ticketId, $user, $_POST['check_type'] ?? '', $_POST['result'] ?? '', trim($_POST['notes'] ?? ''));
        if ($res['ok']) {
            header('Location: ' . url('tickets/view.php') . '?id=' . $ticketId . '&updated=1');
            exit;
        }
        $netErr = $res['message'];
    } else {
        $res = save_technician_result($pdo, $ticket, $user, [
            'room_id'           => $_POST['room_id'] ?? 0,
            'location_room'     => $_POST['location_room'] ?? '',
            'affected_device'   => $_POST['affected_device'] ?? '',
            'technician_result' => $_POST['technician_result'] ?? '',
            'technician_action' => $_POST['technician_action'] ?? '',
        ]);
        if ($res['ok']) {
            header('Location: ' . url('tickets/view.php') . '?id=' . $ticketId . '&updated=1');
            exit;
        }
        $netErr = $res['message'];
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
$updated = isset($_GET['updated']);
$itStaff = $it ? get_it_staff($pdo, $usersPdo) : [];
$networkChecks = get_network_checks($pdo, $ticketId);
$rooms = get_rooms($pdo);
$netTypes = network_types();
$checkTypes = network_check_types();
$deviceTypes = network_device_types();
$sla = sla_state($ticket);

require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($created): ?>
    <div class="alert alert-success">Tiket <strong><?= e($ticket['ticket_number']) ?></strong> berhasil dibuat. Tim IT akan segera menindaklanjuti.</div>
<?php endif; ?>
<?php if ($updated): ?>
    <div class="alert alert-success">Perubahan tiket berhasil disimpan.</div>
<?php endif; ?>
<?php if (!empty($actionErr)): ?>
    <div class="alert alert-error"><?= e($actionErr) ?></div>
<?php endif; ?>
<?php if (!empty($ownerErr)): ?>
    <div class="alert alert-error"><?= e($ownerErr) ?></div>
<?php endif; ?>
<?php if (!empty($netErr)): ?>
    <div class="alert alert-error"><?= e($netErr) ?></div>
<?php endif; ?>

<div class="flex-between" style="margin-bottom:16px">
    <h2 class="mt-0 mb-0"><?= e($ticket['ticket_number']) ?></h2>
    <div><?= status_badge($ticket['status']) ?> <?= priority_badge($ticket['priority']) ?> <?= sla_badge($ticket) ?></div>
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
            <tr><th>Target SLA</th><td><?= e(format_date($ticket['sla_due_at'])) ?> · <?= sla_badge($ticket) ?></td></tr>
            <?php endif; ?>
            <?php if (!empty($ticket['network_type'])): ?>
            <tr><th>Jenis Gangguan</th><td><?= e($netTypes[$ticket['network_type']] ?? $ticket['network_type']) ?></td></tr>
            <?php endif; ?>
            <?php if (!empty($ticket['checked_at'])): ?>
            <tr><th>Waktu Pemeriksaan</th><td><?= e(format_date($ticket['checked_at'])) ?></td></tr>
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

<?php
$isOwner = (int)$ticket['user_id'] === (int)$user['id'];
if ($isOwner && in_array($ticket['status'], ['resolved', 'closed'], true)):
?>
<div class="card">
    <h3 class="card-title">Konfirmasi Penyelesaian</h3>
    <?php if ($ticket['status'] === 'resolved'): ?>
        <p>Tim IT menyatakan masalah Anda sudah <strong>Resolved</strong>. Mohon konfirmasi:</p>
        <form method="post" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="owner">
            <input type="hidden" name="op" value="close">
            <button type="submit" class="btn btn-primary">✓ Masalah Sudah Selesai — Tutup Tiket</button>
        </form>
        <form method="post" style="margin-top:14px">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="owner">
            <input type="hidden" name="op" value="reopen">
            <div class="form-group" style="margin-bottom:8px">
                <label for="reason">Masih bermasalah? Jelaskan dan buka kembali tiket</label>
                <textarea id="reason" name="reason" class="form-control" placeholder="Contoh: internet kembali lambat sejak pagi ini"></textarea>
            </div>
            <button type="submit" class="btn btn-ghost">↺ Buka Kembali Tiket</button>
        </form>
    <?php else: ?>
        <p>Tiket ini sudah <strong>Closed</strong>. Jika masalah muncul kembali:</p>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="owner">
            <input type="hidden" name="op" value="reopen">
            <div class="form-group" style="margin-bottom:8px">
                <label for="reason">Alasan membuka kembali</label>
                <textarea id="reason" name="reason" class="form-control" placeholder="Jelaskan kendala yang muncul kembali..."></textarea>
            </div>
            <button type="submit" class="btn btn-ghost">↺ Buka Kembali Tiket</button>
        </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($it): ?>
<div class="card">
    <h3 class="card-title">Aksi Tim IT</h3>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="manage">

        <div class="form-group">
            <label for="note">Catatan alasan / keterangan (opsional, tercatat di riwayat)</label>
            <input type="text" id="note" name="note" class="form-control" maxlength="255" placeholder="Contoh: sudah dihubungi via telepon">
        </div>

        <div class="action-grid">
            <div class="action-box">
                <h4>Penugasan</h4>
                <select name="assigned_to" class="form-control">
                    <option value="">— Pilih petugas —</option>
                    <?php foreach ($itStaff as $st): ?>
                        <option value="<?= (int)$st['id'] ?>"<?= (int)($ticket['assigned_to'] ?? 0) === (int)$st['id'] ? ' selected' : '' ?>><?= e($st['label']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" name="op" value="assign" class="btn btn-primary btn-sm">Tugaskan</button>
            </div>

            <div class="action-box">
                <h4>Status</h4>
                <select name="status" class="form-control">
                    <?php foreach (ticket_statuses() as $k => $v): ?>
                        <option value="<?= e($k) ?>"<?= $ticket['status'] === $k ? ' selected' : '' ?>><?= e($v) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" name="op" value="status" class="btn btn-primary btn-sm">Ubah Status</button>
            </div>

            <div class="action-box">
                <h4>Prioritas</h4>
                <select name="priority" class="form-control">
                    <?php foreach (ticket_priorities() as $k => $v): ?>
                        <option value="<?= e($k) ?>"<?= $ticket['priority'] === $k ? ' selected' : '' ?>><?= e($v) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" name="op" value="priority" class="btn btn-ghost btn-sm">Ubah Prioritas</button>
            </div>

            <div class="action-box">
                <h4>Transfer ke Petugas Lain</h4>
                <select name="transfer_to" class="form-control">
                    <option value="">— Pilih petugas —</option>
                    <?php foreach ($itStaff as $st): ?>
                        <?php if ((int)($ticket['assigned_to'] ?? 0) === (int)$st['id']) continue; ?>
                        <option value="<?= (int)$st['id'] ?>"><?= e($st['label']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" name="op" value="transfer" class="btn btn-ghost btn-sm">Transfer</button>
            </div>

            <div class="action-box danger">
                <h4>Eskalasi</h4>
                <div class="text-sm text-muted" style="margin-bottom:8px">Menaikkan prioritas satu tingkat dan memberi notifikasi ke seluruh tim IT.</div>
                <button type="submit" name="op" value="escalate" class="btn btn-ghost btn-sm">Eskalasi Sekarang</button>
            </div>

            <?php if (in_array($ticket['status'], ['resolved', 'closed'], true)): ?>
            <div class="action-box danger">
                <h4>Buka Kembali</h4>
                <div class="text-sm text-muted" style="margin-bottom:8px">Kembalikan tiket ke status In Progress (alasan wajib diisi).</div>
                <button type="submit" name="op" value="reopen" class="btn btn-ghost btn-sm">Reopen Tiket</button>
            </div>
            <?php endif; ?>
        </div>
    </form>

    <div class="text-sm text-muted" style="margin-top:12px">
        Alur status: <strong>Open → Assigned → In Progress → Waiting User → Resolved → Closed</strong>.
        <em>Resolved</em> = IT menyatakan selesai, <em>Closed</em> = dikonfirmasi/ditutup sesuai kebijakan.
    </div>
</div>
<?php endif; ?>

<?php if (!empty($ticket['is_network_complaint']) || $networkChecks): ?>
<div class="card">
    <h3 class="card-title">Penanganan Jaringan</h3>

    <?php if (!empty($ticket['technician_result'])): ?>
        <div class="network-banner">
            <div class="nb-icon">🛠</div>
            <div>
                <strong>Hasil Pemeriksaan Teknisi</strong>
                <div style="white-space:pre-wrap"><?= e($ticket['technician_result']) ?></div>
                <?php if (!empty($ticket['technician_action'])): ?>
                    <div style="margin-top:6px"><strong>Tindakan:</strong> <span style="white-space:pre-wrap"><?= e($ticket['technician_action']) ?></span></div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($networkChecks)): ?>
        <h4 style="margin:14px 0 8px">Checklist Pemeriksaan (<?= count($networkChecks) ?>)</h4>
        <ul class="check-list">
            <?php foreach ($networkChecks as $nc): ?>
                <li class="check-item">
                    <div class="check-type"><?= e($checkTypes[$nc['check_type']] ?? $nc['check_type']) ?> · <?= e(format_date($nc['created_at'])) ?></div>
                    <div class="check-result"><?= e($nc['result']) ?></div>
                    <?php if (!empty($nc['notes'])): ?><div class="check-notes"><?= e($nc['notes']) ?></div><?php endif; ?>
                    <div class="text-muted text-sm">oleh <?= e($nc['user_name'] ?? '-') ?></div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php elseif (empty($ticket['technician_result'])): ?>
        <p class="text-muted">Belum ada hasil pemeriksaan jaringan.</p>
    <?php endif; ?>

    <?php if ($it && !empty($ticket['is_network_complaint'])): ?>
    <hr style="border:none;border-top:1px solid var(--border);margin:18px 0">

    <h4 style="margin:0 0 10px">Input Hasil Penanganan Teknisi</h4>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="tech_result">
        <div class="action-grid">
            <div class="form-group" style="margin-bottom:0">
                <label for="room_id">Ruangan</label>
                <select id="room_id" name="room_id" class="form-control">
                    <option value="0">— Tidak berubah —</option>
                    <?php foreach ($rooms as $r): ?>
                        <option value="<?= (int)$r['id'] ?>"<?= (int)($ticket['room_id'] ?? 0) === (int)$r['id'] ? ' selected' : '' ?>><?= e(room_label($r)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin-bottom:0">
                <label for="affected_device">Perangkat Bermasalah</label>
                <select id="affected_device" name="affected_device" class="form-control">
                    <option value="">— Tidak diketahui —</option>
                    <?php foreach ($deviceTypes as $d): ?>
                        <option value="<?= e($d) ?>"<?= ($ticket['affected_device'] ?? '') === $d ? ' selected' : '' ?>><?= e($d) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-group" style="margin-top:12px">
            <label for="technician_result">Hasil Pemeriksaan *</label>
            <textarea id="technician_result" name="technician_result" class="form-control" placeholder="Contoh: kabel LAN di port 12 putus, ping gateway timeout"><?= e($ticket['technician_result'] ?? '') ?></textarea>
        </div>
        <div class="form-group">
            <label for="technician_action">Tindakan Perbaikan</label>
            <textarea id="technician_action" name="technician_action" class="form-control" style="min-height:80px" placeholder="Contoh: mengganti kabel patch cord, restart access point lantai 2"><?= e($ticket['technician_action'] ?? '') ?></textarea>
        </div>
        <button type="submit" class="btn btn-primary">Simpan Hasil Penanganan</button>
    </form>

    <hr style="border:none;border-top:1px solid var(--border);margin:18px 0">

    <h4 style="margin:0 0 10px">Tambah Checklist Pemeriksaan</h4>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="net_check">
        <div class="action-grid">
            <div class="form-group" style="margin-bottom:0">
                <label for="check_type">Jenis Pemeriksaan</label>
                <select id="check_type" name="check_type" class="form-control">
                    <?php foreach ($checkTypes as $k => $v): ?>
                        <option value="<?= e($k) ?>"><?= e($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin-bottom:0">
                <label for="result">Hasil Singkat *</label>
                <input type="text" id="result" name="result" class="form-control" maxlength="255" placeholder="Contoh: ping 8.8.8.8 = 320 ms, 15% loss">
            </div>
        </div>
        <div class="form-group" style="margin-top:12px">
            <label for="notes">Catatan (opsional)</label>
            <input type="text" id="notes" name="notes" class="form-control" maxlength="255" placeholder="Keterangan tambahan">
        </div>
        <button type="submit" class="btn btn-ghost">Tambah Pemeriksaan</button>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>

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
