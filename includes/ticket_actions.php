<?php
/**
 * Aksi manajemen tiket (Batch 3) — dipakai bersama oleh:
 *  - tickets/view.php   (aksi cepat oleh tim IT)
 *  - admin/tickets.php  (bulk action dari daftar manajemen)
 *
 * Semua fungsi mengembalikan array ['ok' => bool, 'message' => string].
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/ticket.php';

/** Daftar status valid + label. */
function ticket_statuses() {
    return [
        'open'         => 'Open',
        'assigned'     => 'Assigned',
        'in_progress'  => 'In Progress',
        'waiting_user' => 'Waiting User',
        'resolved'     => 'Resolved',
        'closed'       => 'Closed',
    ];
}

/** Daftar prioritas valid + label. */
function ticket_priorities() {
    return ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'];
}

/**
 * Ambil daftar petugas IT (user aktif dengan role admin / division IT + tabel it_staff).
 * @return array [['id'=>int,'name'=>string,'label'=>string], ...]
 */
function get_it_staff($pdo, $usersPdo) {
    if (!$usersPdo) return [];
    $out = [];
    try {
        $active = users_has_column($usersPdo, 'is_active') ? 'AND is_active = 1' : '';
        $sql = "SELECT id, name, position, division FROM users
                WHERE (role = 'admin' OR division = 'IT') {$active}
                ORDER BY name";
        foreach ($usersPdo->query($sql)->fetchAll() as $r) {
            $out[(int)$r['id']] = [
                'id'    => (int)$r['id'],
                'name'  => $r['name'],
                'label' => trim($r['name'] . ($r['position'] ? ' — ' . $r['position'] : '')),
            ];
        }
        // Tambahan dari tabel it_staff (bila ada petugas yang bukan role admin/IT)
        $stmt = $pdo->query('SELECT user_id FROM it_staff WHERE is_active = 1');
        foreach ($stmt->fetchAll() as $r) {
            $uid = (int)$r['user_id'];
            if (isset($out[$uid])) continue;
            $u = get_user_by_id($usersPdo, $uid);
            if ($u) {
                $out[$uid] = [
                    'id'    => $uid,
                    'name'  => $u['name'],
                    'label' => trim($u['name'] . (!empty($u['position']) ? ' — ' . $u['position'] : '')),
                ];
            }
        }
    } catch (Throwable $e) {
        error_log('[IT STAFF] ' . $e->getMessage());
    }
    ksort($out);
    return array_values($out);
}

/** Hitung due date respons awal dari SLA policy. */
function compute_response_due($pdo, $priority, $createdAt = null) {
    try {
        $stmt = $pdo->prepare('SELECT response_minutes FROM sla_policies WHERE priority = ? LIMIT 1');
        $stmt->execute([$priority]);
        $row = $stmt->fetch();
        if (!$row) return null;
        $base = $createdAt ? strtotime($createdAt) : time();
        return date('Y-m-d H:i:s', $base + ((int)$row['response_minutes'] * 60));
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Ubah status tiket + catat history, timestamp, dan notifikasi pelapor.
 */
function change_ticket_status($pdo, $ticket, $newStatus, $user, $note = '') {
    $valid = array_keys(ticket_statuses());
    if (!in_array($newStatus, $valid, true)) {
        return ['ok' => false, 'message' => 'Status tidak valid.'];
    }
    $old = $ticket['status'];
    if ($old === $newStatus) {
        return ['ok' => false, 'message' => 'Status tidak berubah.'];
    }

    $sets = ['status = ?', 'updated_at = NOW()'];
    $params = [$newStatus];

    // Timestamp otomatis
    if ($newStatus === 'resolved' && empty($ticket['resolved_at'])) {
        $sets[] = 'resolved_at = NOW()';
    }
    if ($newStatus === 'closed' && empty($ticket['closed_at'])) {
        $sets[] = 'closed_at = NOW()';
    }
    if (in_array($newStatus, ['assigned', 'in_progress'], true) && empty($ticket['responded_at'])) {
        $sets[] = 'responded_at = NOW()';
    }
    if ($newStatus === 'open') {
        // reopen: bersihkan timestamp penyelesaian
        $sets[] = 'resolved_at = NULL';
        $sets[] = 'closed_at = NULL';
    }
    $params[] = (int)$ticket['id'];

    try {
        $stmt = $pdo->prepare('UPDATE tickets SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $stmt->execute($params);
    } catch (Throwable $e) {
        error_log('[STATUS] ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Gagal mengubah status.'];
    }

    $action = $newStatus === 'resolved' ? 'resolved' : ($newStatus === 'closed' ? 'closed' : ($old === 'closed' || $old === 'resolved' ? 'reopened' : 'status_changed'));
    add_ticket_history($pdo, (int)$ticket['id'], $user['id'], $user['name'], $action, $old, $newStatus, $note ?: null);

    // Notifikasi pelapor (kecuali yang mengubah adalah pelapor sendiri)
    $statusLabels = ticket_statuses();
    if ((int)$ticket['user_id'] !== (int)$user['id']) {
        notify_user(
            $pdo,
            (int)$ticket['user_id'],
            'Status tiket ' . $ticket['ticket_number'] . ' → ' . ($statusLabels[$newStatus] ?? $newStatus),
            $note ?: ('Tiket Anda diperbarui oleh ' . $user['name'] . '.'),
            'tickets/view.php?id=' . (int)$ticket['id'],
            'status_changed'
        );
    }
    return ['ok' => true, 'message' => 'Status diubah menjadi ' . ($statusLabels[$newStatus] ?? $newStatus) . '.'];
}

/**
 * Ubah prioritas tiket + hitung ulang SLA + history + notifikasi.
 */
function change_ticket_priority($pdo, $ticket, $newPriority, $user, $note = '') {
    if (!array_key_exists($newPriority, ticket_priorities())) {
        return ['ok' => false, 'message' => 'Prioritas tidak valid.'];
    }
    $old = $ticket['priority'];
    if ($old === $newPriority) {
        return ['ok' => false, 'message' => 'Prioritas tidak berubah.'];
    }
    $newDue = compute_sla_due($pdo, $newPriority, $ticket['created_at']);
    try {
        $stmt = $pdo->prepare('UPDATE tickets SET priority = ?, sla_due_at = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$newPriority, $newDue, (int)$ticket['id']]);
    } catch (Throwable $e) {
        error_log('[PRIORITY] ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Gagal mengubah prioritas.'];
    }
    $labels = ticket_priorities();
    add_ticket_history($pdo, (int)$ticket['id'], $user['id'], $user['name'], 'priority_changed', $old, $newPriority, $note ?: null);

    if ((int)$ticket['user_id'] !== (int)$user['id']) {
        notify_user(
            $pdo,
            (int)$ticket['user_id'],
            'Prioritas tiket ' . $ticket['ticket_number'] . ' → ' . ($labels[$newPriority] ?? $newPriority),
            $note ?: ('Prioritas diperbarui oleh ' . $user['name'] . '.'),
            'tickets/view.php?id=' . (int)$ticket['id'],
            'priority_changed'
        );
    }
    return ['ok' => true, 'message' => 'Prioritas diubah menjadi ' . ($labels[$newPriority] ?? $newPriority) . '.'];
}

/**
 * Tugaskan tiket ke petugas IT.
 */
function assign_ticket($pdo, $usersPdo, $ticket, $assigneeId, $user, $note = '') {
    $assigneeId = (int)$assigneeId;
    if ($assigneeId <= 0) {
        return ['ok' => false, 'message' => 'Pilih petugas terlebih dahulu.'];
    }
    $assignee = get_user_by_id($usersPdo, $assigneeId);
    if (!$assignee) {
        return ['ok' => false, 'message' => 'Petugas tidak ditemukan.'];
    }

    $newStatus = in_array($ticket['status'], ['open', 'assigned'], true) ? 'assigned' : $ticket['status'];
    try {
        $stmt = $pdo->prepare(
            'UPDATE tickets SET assigned_to = ?, assigned_name = ?, status = ?, responded_at = COALESCE(responded_at, NOW()), updated_at = NOW()
             WHERE id = ?'
        );
        $stmt->execute([$assigneeId, $assignee['name'], $newStatus, (int)$ticket['id']]);
    } catch (Throwable $e) {
        error_log('[ASSIGN] ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Gagal menugaskan tiket.'];
    }

    $oldName = $ticket['assigned_name'] ?? null;
    add_ticket_history($pdo, (int)$ticket['id'], $user['id'], $user['name'], 'assigned', $oldName, $assignee['name'], $note ?: null);

    // Notifikasi petugas baru + pelapor
    if ($assigneeId !== (int)$user['id']) {
        notify_user($pdo, $assigneeId, 'Tiket ditugaskan kepada Anda',
            $ticket['ticket_number'] . ' — ' . $ticket['title'],
            'tickets/view.php?id=' . (int)$ticket['id'], 'assigned');
    }
    if ((int)$ticket['user_id'] !== (int)$user['id']) {
        notify_user($pdo, (int)$ticket['user_id'], 'Tiket Anda ditangani ' . $assignee['name'],
            $ticket['ticket_number'] . ' — ' . $ticket['title'],
            'tickets/view.php?id=' . (int)$ticket['id'], 'assigned');
    }
    return ['ok' => true, 'message' => 'Tiket ditugaskan kepada ' . $assignee['name'] . '.'];
}

/**
 * Transfer tiket ke petugas lain (wajib ada alasan).
 */
function transfer_ticket($pdo, $usersPdo, $ticket, $toId, $user, $reason) {
    $reason = trim($reason);
    if (mb_strlen($reason) < 5) {
        return ['ok' => false, 'message' => 'Alasan transfer wajib diisi (minimal 5 karakter).'];
    }
    $to = get_user_by_id($usersPdo, (int)$toId);
    if (!$to) {
        return ['ok' => false, 'message' => 'Petugas tujuan tidak ditemukan.'];
    }
    if ((int)($ticket['assigned_to'] ?? 0) === (int)$toId) {
        return ['ok' => false, 'message' => 'Tiket sudah ditangani petugas tersebut.'];
    }
    try {
        $stmt = $pdo->prepare('UPDATE tickets SET assigned_to = ?, assigned_name = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([(int)$toId, $to['name'], (int)$ticket['id']]);
    } catch (Throwable $e) {
        error_log('[TRANSFER] ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Gagal mentransfer tiket.'];
    }
    add_ticket_history($pdo, (int)$ticket['id'], $user['id'], $user['name'], 'transferred',
        $ticket['assigned_name'] ?? '-', $to['name'], 'Alasan: ' . $reason);
    notify_user($pdo, (int)$toId, 'Tiket ditransfer kepada Anda',
        $ticket['ticket_number'] . ' — ' . $reason, 'tickets/view.php?id=' . (int)$ticket['id'], 'transferred');
    return ['ok' => true, 'message' => 'Tiket ditransfer kepada ' . $to['name'] . '.'];
}

/**
 * Eskalasi tiket: naikkan prioritas satu level + notifikasi pimpinan/IT.
 */
function escalate_ticket($pdo, $usersPdo, $ticket, $user, $reason) {
    $reason = trim($reason);
    if (mb_strlen($reason) < 5) {
        return ['ok' => false, 'message' => 'Alasan eskalasi wajib diisi (minimal 5 karakter).'];
    }
    $chain = ['low' => 'medium', 'medium' => 'high', 'high' => 'critical', 'critical' => 'critical'];
    $newPriority = $chain[$ticket['priority']] ?? 'high';
    $newDue = compute_sla_due($pdo, $newPriority, $ticket['created_at']);

    try {
        $stmt = $pdo->prepare('UPDATE tickets SET priority = ?, sla_due_at = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$newPriority, $newDue, (int)$ticket['id']]);
    } catch (Throwable $e) {
        error_log('[ESCALATE] ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Gagal melakukan eskalasi.'];
    }

    $labels = ticket_priorities();
    add_ticket_history($pdo, (int)$ticket['id'], $user['id'], $user['name'], 'escalated',
        $ticket['priority'], $newPriority, 'Alasan: ' . $reason);

    // Notifikasi tim IT + pelapor
    notify_it_team($pdo, $usersPdo, 'Eskalasi tiket ' . $ticket['ticket_number'],
        $reason, 'tickets/view.php?id=' . (int)$ticket['id'], 'escalated');
    if ((int)$ticket['user_id'] !== (int)$user['id']) {
        notify_user($pdo, (int)$ticket['user_id'], 'Tiket Anda dieskalasi',
            'Penanganan ' . $ticket['ticket_number'] . ' ditingkatkan ke prioritas ' . ($labels[$newPriority] ?? $newPriority) . '.',
            'tickets/view.php?id=' . (int)$ticket['id'], 'escalated');
    }
    return ['ok' => true, 'message' => 'Tiket dieskalasi ke prioritas ' . ($labels[$newPriority] ?? $newPriority) . '.'];
}

/**
 * Buka kembali tiket (Resolved/Closed → In Progress).
 */
function reopen_ticket($pdo, $usersPdo, $ticket, $user, $reason) {
    $reason = trim($reason);
    if (!in_array($ticket['status'], ['resolved', 'closed'], true)) {
        return ['ok' => false, 'message' => 'Hanya tiket Resolved/Closed yang bisa dibuka kembali.'];
    }
    if (mb_strlen($reason) < 5) {
        return ['ok' => false, 'message' => 'Alasan membuka kembali wajib diisi (minimal 5 karakter).'];
    }
    try {
        $stmt = $pdo->prepare(
            "UPDATE tickets SET status = 'in_progress', resolved_at = NULL, closed_at = NULL, updated_at = NOW() WHERE id = ?"
        );
        $stmt->execute([(int)$ticket['id']]);
    } catch (Throwable $e) {
        error_log('[REOPEN] ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Gagal membuka kembali tiket.'];
    }
    add_ticket_history($pdo, (int)$ticket['id'], $user['id'], $user['name'], 'reopened',
        $ticket['status'], 'in_progress', 'Alasan: ' . $reason);

    notify_it_team($pdo, $usersPdo, 'Tiket dibuka kembali: ' . $ticket['ticket_number'],
        $reason, 'tickets/view.php?id=' . (int)$ticket['id'], 'reopened');
    if ((int)$ticket['user_id'] !== (int)$user['id']) {
        notify_user($pdo, (int)$ticket['user_id'], 'Tiket Anda dibuka kembali',
            $reason, 'tickets/view.php?id=' . (int)$ticket['id'], 'reopened');
    }
    return ['ok' => true, 'message' => 'Tiket dibuka kembali (In Progress).'];
}

/** Ambil satu tiket (tanpa join kategori). */
function get_ticket($pdo, $ticketId) {
    try {
        $stmt = $pdo->prepare('SELECT * FROM tickets WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$ticketId]);
        return $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        error_log('[GET TICKET] ' . $e->getMessage());
        return null;
    }
}
