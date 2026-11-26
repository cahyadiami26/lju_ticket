<?php
/**
 * Query & agregasi laporan (Batch 6).
 * Semua fungsi menerima rentang tanggal 'Y-m-d H:i:s'.
 */

require_once __DIR__ . '/db.php';

/** Ringkasan utama pada rentang tanggal. */
function report_summary($pdo, $from, $to) {
    $out = [
        'total' => 0, 'open' => 0, 'resolved' => 0, 'closed' => 0,
        'breached' => 0, 'compliance' => 100, 'network' => 0,
        'avg_rating' => null, 'ratings' => 0,
        'avg_response_min' => null, 'avg_resolution_min' => null,
        'unassigned' => 0,
    ];
    try {
        $st = $pdo->prepare('SELECT COUNT(*) c FROM tickets WHERE created_at BETWEEN ? AND ?');
        $st->execute([$from, $to]); $out['total'] = (int)$st->fetch()['c'];

        $st = $pdo->prepare("SELECT COUNT(*) c FROM tickets WHERE created_at BETWEEN ? AND ? AND status IN ('open','assigned','in_progress','waiting_user')");
        $st->execute([$from, $to]); $out['open'] = (int)$st->fetch()['c'];

        $st = $pdo->prepare("SELECT COUNT(*) c FROM tickets WHERE created_at BETWEEN ? AND ? AND status = 'resolved'");
        $st->execute([$from, $to]); $out['resolved'] = (int)$st->fetch()['c'];

        $st = $pdo->prepare("SELECT COUNT(*) c FROM tickets WHERE created_at BETWEEN ? AND ? AND status = 'closed'");
        $st->execute([$from, $to]); $out['closed'] = (int)$st->fetch()['c'];

        $st = $pdo->prepare('SELECT COUNT(*) c FROM tickets WHERE created_at BETWEEN ? AND ? AND sla_breached = 1');
        $st->execute([$from, $to]); $out['breached'] = (int)$st->fetch()['c'];

        $st = $pdo->prepare('SELECT COUNT(*) c FROM tickets WHERE created_at BETWEEN ? AND ? AND is_network_complaint = 1');
        $st->execute([$from, $to]); $out['network'] = (int)$st->fetch()['c'];

        $st = $pdo->prepare('SELECT COUNT(*) c FROM tickets WHERE created_at BETWEEN ? AND ? AND assigned_to IS NULL');
        $st->execute([$from, $to]); $out['unassigned'] = (int)$st->fetch()['c'];

        $st = $pdo->prepare('SELECT AVG(rating) a, COUNT(rating) c FROM tickets WHERE created_at BETWEEN ? AND ? AND rating IS NOT NULL');
        $st->execute([$from, $to]);
        $r = $st->fetch();
        $out['avg_rating'] = ($r && $r['a'] !== null) ? round((float)$r['a'], 2) : null;
        $out['ratings'] = (int)($r['c'] ?? 0);

        $st = $pdo->prepare('SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, responded_at)) a FROM tickets WHERE created_at BETWEEN ? AND ? AND responded_at IS NOT NULL');
        $st->execute([$from, $to]);
        $r = $st->fetch();
        $out['avg_response_min'] = ($r && $r['a'] !== null) ? (int)round((float)$r['a']) : null;

        $st = $pdo->prepare('SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, resolved_at)) a FROM tickets WHERE created_at BETWEEN ? AND ? AND resolved_at IS NOT NULL');
        $st->execute([$from, $to]);
        $r = $st->fetch();
        $out['avg_resolution_min'] = ($r && $r['a'] !== null) ? (int)round((float)$r['a']) : null;
    } catch (Throwable $e) {
        error_log('[REPORT SUMMARY] ' . $e->getMessage());
    }
    $out['compliance'] = $out['total'] > 0 ? round((($out['total'] - $out['breached']) / $out['total']) * 100, 1) : 100;
    return $out;
}

/** Jumlah tiket per kategori. */
function report_by_category($pdo, $from, $to) {
    try {
        $st = $pdo->prepare(
            "SELECT COALESCE(c.name, 'Tanpa kategori') AS label, COUNT(*) total,
                    SUM(t.status IN ('resolved','closed')) selesai,
                    SUM(t.sla_breached = 1) terlambat
             FROM tickets t LEFT JOIN categories c ON c.id = t.category_id
             WHERE t.created_at BETWEEN ? AND ?
             GROUP BY label ORDER BY total DESC"
        );
        $st->execute([$from, $to]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        error_log('[REPORT CATEGORY] ' . $e->getMessage());
        return [];
    }
}

/** Jumlah tiket per prioritas + SLA. */
function report_by_priority($pdo, $from, $to) {
    try {
        $st = $pdo->prepare(
            "SELECT priority, COUNT(*) total,
                    SUM(status IN ('resolved','closed')) selesai,
                    SUM(sla_breached = 1) terlambat,
                    AVG(TIMESTAMPDIFF(MINUTE, created_at, responded_at)) resp
             FROM tickets WHERE created_at BETWEEN ? AND ?
             GROUP BY priority"
        );
        $st->execute([$from, $to]);
        $rows = [];
        foreach ($st->fetchAll() as $r) {
            $rows[$r['priority']] = $r;
        }
        return $rows;
    } catch (Throwable $e) {
        error_log('[REPORT PRIORITY] ' . $e->getMessage());
        return [];
    }
}

/** Kinerja petugas. */
function report_by_officer($pdo, $from, $to) {
    try {
        $st = $pdo->prepare(
            "SELECT COALESCE(assigned_name, 'Belum ditugaskan') officer,
                    COUNT(*) total,
                    SUM(status IN ('resolved','closed')) selesai,
                    SUM(sla_breached = 1) terlambat,
                    AVG(TIMESTAMPDIFF(MINUTE, created_at, responded_at)) resp,
                    AVG(TIMESTAMPDIFF(MINUTE, created_at, resolved_at)) resv,
                    AVG(rating) rating
             FROM tickets WHERE created_at BETWEEN ? AND ?
             GROUP BY officer ORDER BY total DESC"
        );
        $st->execute([$from, $to]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        error_log('[REPORT OFFICER] ' . $e->getMessage());
        return [];
    }
}

/** Tiket per ruangan (khusus gangguan jaringan). */
function report_by_room($pdo, $from, $to) {
    try {
        $st = $pdo->prepare(
            "SELECT COALESCE(r.name, t.location_room, 'Tanpa lokasi') room_name,
                    COUNT(*) total, SUM(t.sla_breached = 1) terlambat
             FROM tickets t LEFT JOIN rooms r ON r.id = t.room_id
             WHERE t.created_at BETWEEN ? AND ? AND t.is_network_complaint = 1
             GROUP BY room_name ORDER BY total DESC LIMIT 20"
        );
        $st->execute([$from, $to]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        error_log('[REPORT ROOM] ' . $e->getMessage());
        return [];
    }
}

/** Tren harian (untuk grafik sederhana). */
function report_daily($pdo, $from, $to) {
    try {
        $st = $pdo->prepare(
            "SELECT DATE(created_at) d, COUNT(*) total,
                    SUM(status IN ('resolved','closed')) selesai
             FROM tickets WHERE created_at BETWEEN ? AND ?
             GROUP BY DATE(created_at) ORDER BY d"
        );
        $st->execute([$from, $to]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        error_log('[REPORT DAILY] ' . $e->getMessage());
        return [];
    }
}

/** Distribusi rating kepuasan. */
function report_ratings($pdo, $from, $to) {
    $out = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
    try {
        $st = $pdo->prepare(
            'SELECT rating, COUNT(*) c FROM tickets WHERE created_at BETWEEN ? AND ? AND rating IS NOT NULL GROUP BY rating'
        );
        $st->execute([$from, $to]);
        foreach ($st->fetchAll() as $r) {
            $out[(int)$r['rating']] = (int)$r['c'];
        }
    } catch (Throwable $e) {
        error_log('[REPORT RATINGS] ' . $e->getMessage());
    }
    return $out;
}

/** Format menit menjadi teks ringkas. */
if (!function_exists('report_fmt_minutes')) {
function report_fmt_minutes($m) {
    if ($m === null || $m === '') return '—';
    $m = (int)$m;
    if ($m < 60) return $m . ' menit';
    if ($m < 1440) return floor($m / 60) . ' jam ' . ($m % 60) . ' mnt';
    return floor($m / 1440) . ' hari ' . floor(($m % 1440) / 60) . ' jam';
}
}

/** Label rentang tanggal untuk judul laporan. */
function report_range_label($from, $to) {
    return date('d M Y', strtotime($from)) . ' — ' . date('d M Y', strtotime($to));
}
