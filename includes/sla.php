<?php
/**
 * SLA & Escalation Engine (Batch 5).
 *
 * Dipanggil oleh:
 *  - cron/sla.php           (cron job tiap 5–15 menit)
 *  - settings/index.php     (tombol "Jalankan Pemeriksaan Sekarang")
 *  - dashboard / admin      (opsional, lightweight)
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/ticket.php';
require_once __DIR__ . '/notifier.php';

/** Ambil kebijakan SLA per prioritas → [priority => row]. */
function sla_policies_map($pdo) {
    $out = [];
    try {
        foreach ($pdo->query('SELECT * FROM sla_policies')->fetchAll() as $row) {
            $out[$row['priority']] = $row;
        }
    } catch (Throwable $e) {
        error_log('[SLA POLICIES] ' . $e->getMessage());
    }
    return $out;
}

/**
 * Hitung target respons & penyelesaian untuk sebuah tiket.
 * @return array ['response_due'=>?string, 'resolution_due'=>?string]
 */
function sla_targets($pdo, $priority, $createdAt = null) {
    $base = $createdAt ? strtotime($createdAt) : time();
    $policies = sla_policies_map($pdo);
    $row = $policies[$priority] ?? null;
    if (!$row) return ['response_due' => null, 'resolution_due' => null];
    return [
        'response_due'   => date('Y-m-d H:i:s', $base + ((int)$row['response_minutes'] * 60)),
        'resolution_due' => date('Y-m-d H:i:s', $base + ((int)$row['resolution_minutes'] * 60)),
    ];
}

/**
 * Pemeriksaan SLA utama: peringatan mendekati batas, pelanggaran, eskalasi otomatis.
 * @return array statistik proses
 */
function run_sla_monitor($pdo, $usersPdo, $options = []) {
    $stats = [
        'warned' => 0, 'breached' => 0, 'escalated' => 0,
        'auto_closed' => 0, 'checked' => 0, 'errors' => [],
    ];
    if (!$options) {
        $options = [
            'warn_minutes'   => (int)setting($pdo, 'sla_warning_minutes', 60),
            'auto_escalate'  => setting_bool($pdo, 'sla_auto_escalate', true),
            'max_level'      => (int)setting($pdo, 'sla_escalate_max_level', 2),
            'auto_close'     => (int)setting($pdo, 'auto_close_days', 7),
        ];
    }

    $activeStatuses = "('open','assigned','in_progress','waiting_user')";
    $chain = ['low' => 'medium', 'medium' => 'high', 'high' => 'critical', 'critical' => 'critical'];
    $prioLabel = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'];

    // ---------- 1) Tiket mendekati batas SLA ----------
    try {
        $stmt = $pdo->prepare(
            "SELECT * FROM tickets
             WHERE status IN {$activeStatuses}
               AND sla_due_at IS NOT NULL
               AND sla_due_at > NOW()
               AND sla_due_at <= DATE_ADD(NOW(), INTERVAL ? MINUTE)
               AND (sla_warned_at IS NULL OR sla_warned_at < DATE_SUB(NOW(), INTERVAL 6 HOUR))"
        );
        $stmt->execute([max(1, $options['warn_minutes'])]);
        foreach ($stmt->fetchAll() as $t) {
            $stats['checked']++;
            $leftMin = max(1, (int)round((strtotime($t['sla_due_at']) - time()) / 60));
            $msg = 'Sisa ' . $leftMin . ' menit sebelum batas SLA (' . format_date($t['sla_due_at']) . ').';

            if (!empty($t['assigned_to'])) {
                notify_user_channels($pdo, $usersPdo, (int)$t['assigned_to'],
                    'Peringatan SLA: ' . $t['ticket_number'], $msg,
                    'tickets/view.php?id=' . (int)$t['id'], 'sla', $t);
            } else {
                notify_it_team_channels($pdo, $usersPdo, 'Peringatan SLA: ' . $t['ticket_number'],
                    $msg, 'tickets/view.php?id=' . (int)$t['id'], 'sla', $t);
            }
            $pdo->prepare('UPDATE tickets SET sla_warned_at = NOW() WHERE id = ?')->execute([(int)$t['id']]);
            add_ticket_history($pdo, (int)$t['id'], null, 'Sistem SLA', 'sla_warning', null, $leftMin . ' menit', 'Peringatan mendekati batas SLA');
            $stats['warned']++;
        }
    } catch (Throwable $e) {
        $stats['errors'][] = 'warning: ' . $e->getMessage();
        error_log('[SLA WARNING] ' . $e->getMessage());
    }

    // ---------- 2) Tiket melewati SLA ----------
    try {
        $stmt = $pdo->query(
            "SELECT * FROM tickets
             WHERE status IN {$activeStatuses}
               AND sla_due_at IS NOT NULL
               AND sla_due_at < NOW()
               AND sla_breached = 0"
        );
        foreach ($stmt->fetchAll() as $t) {
            $stats['checked']++;
            $lateMin = (int)round((time() - strtotime($t['sla_due_at'])) / 60);
            $pdo->prepare('UPDATE tickets SET sla_breached = 1 WHERE id = ?')->execute([(int)$t['id']]);

            add_ticket_history($pdo, (int)$t['id'], null, 'Sistem SLA', 'sla_breach',
                $t['sla_due_at'], 'terlambat ' . $lateMin . ' menit', 'Tiket melewati batas SLA');

            $msg = 'Tiket melewati batas SLA ' . $lateMin . ' menit. Prioritas: ' . ($prioLabel[$t['priority']] ?? $t['priority']) . '.';
            notify_it_team_channels($pdo, $usersPdo, 'SLA TERLEWATI: ' . $t['ticket_number'], $msg,
                'tickets/view.php?id=' . (int)$t['id'], 'sla', $t);
            if (!empty($t['user_id'])) {
                notify_user_channels($pdo, $usersPdo, (int)$t['user_id'],
                    'Tiket ' . $t['ticket_number'] . ' terlambat ditangani',
                    'Kami mohon maaf atas keterlambatan. Tiket Anda sedang ditingkatkan penanganannya.',
                    'tickets/view.php?id=' . (int)$t['id'], 'sla', $t);
            }
            $stats['breached']++;

            // ---------- 3) Eskalasi otomatis ----------
            if (!empty($options['auto_escalate']) && (int)$t['escalate_level'] < (int)$options['max_level']) {
                $newPriority = $chain[$t['priority']] ?? 'high';
                if ($newPriority !== $t['priority']) {
                    $targets = sla_targets($pdo, $newPriority, $t['created_at']);
                    $pdo->prepare(
                        'UPDATE tickets SET priority = ?, sla_due_at = ?, escalate_level = escalate_level + 1, escalated_at = NOW(), updated_at = NOW()
                         WHERE id = ?'
                    )->execute([$newPriority, $targets['resolution_due'], (int)$t['id']]);

                    add_ticket_history($pdo, (int)$t['id'], null, 'Sistem SLA', 'escalated',
                        $t['priority'], $newPriority, 'Eskalasi otomatis karena melewati SLA');
                    notify_it_team_channels($pdo, $usersPdo, 'Eskalasi otomatis: ' . $t['ticket_number'],
                        'Prioritas dinaikkan dari ' . ($prioLabel[$t['priority']] ?? $t['priority'])
                        . ' ke ' . ($prioLabel[$newPriority] ?? $newPriority) . ' karena melewati SLA.',
                        'tickets/view.php?id=' . (int)$t['id'], 'escalated', $t);
                    $stats['escalated']++;
                }
            }
        }
    } catch (Throwable $e) {
        $stats['errors'][] = 'breach: ' . $e->getMessage();
        error_log('[SLA BREACH] ' . $e->getMessage());
    }

    // ---------- 4) Auto-close tiket Resolved yang lama ----------
    if (!empty($options['auto_close']) && (int)$options['auto_close'] > 0) {
        try {
            $stmt = $pdo->prepare(
                "SELECT * FROM tickets
                 WHERE status = 'resolved' AND resolved_at IS NOT NULL
                   AND resolved_at <= DATE_SUB(NOW(), INTERVAL ? DAY)"
            );
            $stmt->execute([(int)$options['auto_close']]);
            foreach ($stmt->fetchAll() as $t) {
                $pdo->prepare("UPDATE tickets SET status = 'closed', closed_at = NOW(), updated_at = NOW() WHERE id = ?")
                    ->execute([(int)$t['id']]);
                add_ticket_history($pdo, (int)$t['id'], null, 'Sistem SLA', 'closed', 'resolved', 'closed',
                    'Ditutup otomatis setelah ' . (int)$options['auto_close'] . ' hari tanpa tanggapan');
                if (!empty($t['user_id'])) {
                    notify_user_channels($pdo, $usersPdo, (int)$t['user_id'],
                        'Tiket ' . $t['ticket_number'] . ' ditutup otomatis',
                        'Tiket ditutup karena sudah ' . (int)$options['auto_close'] . ' hari dalam status Resolved. '
                        . 'Buka kembali tiket bila masalah muncul lagi.',
                        'tickets/view.php?id=' . (int)$t['id'], 'closed', $t);
                }
                $stats['auto_closed']++;
            }
        } catch (Throwable $e) {
            $stats['errors'][] = 'auto_close: ' . $e->getMessage();
            error_log('[SLA AUTO CLOSE] ' . $e->getMessage());
        }
    }

    return $stats;
}

/**
 * Statistik kepatuhan SLA untuk laporan.
 * @return array
 */
function sla_report_stats($pdo, $from, $to) {
    $out = [
        'total' => 0, 'resolved' => 0, 'breached' => 0, 'compliance' => 0,
        'avg_response_min' => null, 'avg_resolution_min' => null, 'by_priority' => [],
    ];
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) c FROM tickets WHERE created_at BETWEEN ? AND ?');
        $stmt->execute([$from, $to]);
        $out['total'] = (int)$stmt->fetch()['c'];

        $stmt = $pdo->prepare("SELECT COUNT(*) c FROM tickets WHERE created_at BETWEEN ? AND ? AND status IN ('resolved','closed')");
        $stmt->execute([$from, $to]);
        $out['resolved'] = (int)$stmt->fetch()['c'];

        $stmt = $pdo->prepare('SELECT COUNT(*) c FROM tickets WHERE created_at BETWEEN ? AND ? AND sla_breached = 1');
        $stmt->execute([$from, $to]);
        $out['breached'] = (int)$stmt->fetch()['c'];

        $stmt = $pdo->prepare(
            'SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, responded_at)) a
             FROM tickets WHERE created_at BETWEEN ? AND ? AND responded_at IS NOT NULL'
        );
        $stmt->execute([$from, $to]);
        $row = $stmt->fetch();
        $out['avg_response_min'] = ($row && $row['a'] !== null) ? (int)round((float)$row['a']) : null;

        $stmt = $pdo->prepare(
            'SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, resolved_at)) a
             FROM tickets WHERE created_at BETWEEN ? AND ? AND resolved_at IS NOT NULL'
        );
        $stmt->execute([$from, $to]);
        $row = $stmt->fetch();
        $out['avg_resolution_min'] = $row && $row['a'] !== null ? (int)round((float)$row['a']) : null;

        $stmt = $pdo->prepare(
            "SELECT priority, COUNT(*) total,
                    SUM(sla_breached = 1) breached,
                    SUM(status IN ('resolved','closed')) done
             FROM tickets WHERE created_at BETWEEN ? AND ?
             GROUP BY priority"
        );
        $stmt->execute([$from, $to]);
        foreach ($stmt->fetchAll() as $r) {
            $out['by_priority'][$r['priority']] = $r;
        }
    } catch (Throwable $e) {
        error_log('[SLA REPORT] ' . $e->getMessage());
    }
    $out['compliance'] = $out['total'] > 0 ? round((($out['total'] - $out['breached']) / $out['total']) * 100, 1) : 100;
    return $out;
}
