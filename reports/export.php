<?php
/**
 * Export laporan (Batch 6):
 *   ?type=summary&format=pdf     → ringkasan manajerial (PDF via domPDF)
 *   ?type=tickets&format=csv     → daftar tiket (Excel/CSV)
 *   ?type=sla&format=pdf         → laporan SLA (PDF)
 *   ?type=network&format=pdf     → laporan gangguan jaringan per ruangan (PDF)
 *   format=preview&...           → tampilkan HTML siap cetak di browser (tanpa domPDF)
 */
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/url.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/reports.php';
require_once __DIR__ . '/../includes/sla.php';
require_once __DIR__ . '/../includes/network.php';
require_once __DIR__ . '/../includes/pdf.php';
require_it($pdo);

$type = $_GET['type'] ?? 'summary';
$format = $_GET['format'] ?? 'pdf';

$fromDate = $_GET['from'] ?? date('Y-m-01');
$toDate = $_GET['to'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) $fromDate = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) $toDate = date('Y-m-d');
$from = $fromDate . ' 00:00:00';
$to = $toDate . ' 23:59:59';
$rangeLabel = report_range_label($from, $to);

$esc = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
$prioLabel = ['critical' => 'Critical', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low'];
$statusLabel = ['open' => 'Open', 'assigned' => 'Assigned', 'in_progress' => 'In Progress',
                'waiting_user' => 'Waiting User', 'resolved' => 'Resolved', 'closed' => 'Closed'];

/** Baris KPI untuk HTML laporan. */
function kpi_rows(array $items) {
    $out = '<table class="kpi"><tr>';
    foreach ($items as $label => $value) {
        $out .= '<td><div class="label">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</div>'
             . '<div class="value">' . htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') . '</div></td>';
    }
    return $out . '</tr></table>';
}

/* ============================================================
 * 1) CSV daftar tiket
 * ============================================================ */
if ($format === 'csv' && $type === 'tickets') {
    $rows = [];
    try {
        $stmt = $pdo->prepare(
            "SELECT t.ticket_number, t.created_at, t.title, c.name AS category, t.priority, t.status,
                    t.user_name, t.nama_bidang, t.nama_divisi, t.assigned_name, t.location_room,
                    t.sla_due_at, t.sla_breached, t.resolved_at, t.closed_at, t.rating
             FROM tickets t LEFT JOIN categories c ON c.id = t.category_id
             WHERE t.created_at BETWEEN ? AND ? ORDER BY t.created_at"
        );
        $stmt->execute([$from, $to]);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('[EXPORT CSV] ' . $e->getMessage());
    }

    $filename = 'laporan-tiket-' . $fromDate . '-sd-' . $toDate . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM agar Excel membaca UTF-8
    fputcsv($out, ['Nomor Tiket', 'Tanggal Dibuat', 'Judul', 'Kategori', 'Prioritas', 'Status',
        'Pelapor', 'Nama Bidang', 'Nama Divisi', 'Petugas', 'Ruangan', 'Batas SLA',
        'SLA Terlewati', 'Diselesaikan', 'Ditutup', 'Rating']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['ticket_number'], $r['created_at'], $r['title'], $r['category'],
            $prioLabel[$r['priority']] ?? $r['priority'], $statusLabel[$r['status']] ?? $r['status'],
            $r['user_name'], $r['nama_bidang'], $r['nama_divisi'], $r['assigned_name'],
            $r['location_room'], $r['sla_due_at'], $r['sla_breached'] ? 'Ya' : 'Tidak',
            $r['resolved_at'], $r['closed_at'], $r['rating'],
        ]);
    }
    fclose($out);
    exit;
}

/* ============================================================
 * 2) Susun HTML laporan
 * ============================================================ */
$summary = report_summary($pdo, $from, $to);
$body = '';

// Pembungkus: kegagalan query (mis. migrasi batch belum dijalankan) tidak boleh
// menghasilkan HTTP 500 tanpa penjelasan. Laporan tetap dibuat + catatan error.
try {

if ($type === 'summary') {
    $byCategory = report_by_category($pdo, $from, $to);
    $byPriority = report_by_priority($pdo, $from, $to);
    $byOfficer = report_by_officer($pdo, $from, $to);
    $ratings = report_ratings($pdo, $from, $to);
    $ratingTotal = array_sum($ratings);

    $body .= kpi_rows([
        'Total Tiket'       => $summary['total'],
        'Selesai'           => $summary['resolved'] + $summary['closed'],
        'Masih Terbuka'     => $summary['open'],
        'Melewati SLA'      => $summary['breached'],
        'Kepatuhan SLA'     => $summary['compliance'] . '%',
        'Kepuasan (rata2)'  => $summary['avg_rating'] !== null ? $summary['avg_rating'] . '/5' : '—',
    ]);

    $body .= '<h2>Ringkasan Kinerja</h2><table><tr><th>Metrik</th><th>Nilai</th></tr>'
        . '<tr><td>Rata-rata respons awal</td><td>' . $esc(report_fmt_minutes($summary['avg_response_min'])) . '</td></tr>'
        . '<tr><td>Rata-rata penyelesaian</td><td>' . $esc(report_fmt_minutes($summary['avg_resolution_min'])) . '</td></tr>'
        . '<tr><td>Gangguan jaringan</td><td>' . (int)$summary['network'] . '</td></tr>'
        . '<tr><td>Tiket belum ditugaskan</td><td>' . (int)$summary['unassigned'] . '</td></tr>'
        . '<tr><td>Jumlah penilaian pelanggan</td><td>' . $ratingTotal . '</td></tr>'
        . '</table>';

    $body .= '<h2>Tiket per Kategori</h2><table><tr><th>Kategori</th><th>Total</th><th>Selesai</th><th>Terlambat</th></tr>';
    foreach ($byCategory as $c) {
        $body .= '<tr><td>' . $esc($c['label']) . '</td><td>' . (int)$c['total'] . '</td><td>'
            . (int)$c['selesai'] . '</td><td>' . (int)$c['terlambat'] . '</td></tr>';
    }
    $body .= '</table>';

    $body .= '<h2>Tiket per Prioritas</h2><table><tr><th>Prioritas</th><th>Total</th><th>Selesai</th><th>Terlambat</th><th>Rata-rata Respons</th></tr>';
    foreach ($prioLabel as $p => $label) {
        $r = $byPriority[$p] ?? null;
        $body .= '<tr><td>' . $label . '</td><td>' . ($r ? (int)$r['total'] : 0) . '</td><td>'
            . ($r ? (int)$r['selesai'] : 0) . '</td><td>' . ($r ? (int)$r['terlambat'] : 0) . '</td><td>'
            . ($r ? $esc(report_fmt_minutes($r['resp'] !== null ? round((float)$r['resp']) : null)) : '—') . '</td></tr>';
    }
    $body .= '</table>';

    $body .= '<h2>Kinerja Petugas IT</h2><table><tr><th>Petugas</th><th>Total</th><th>Selesai</th><th>Terlambat</th><th>Resp</th><th>Penyelesaian</th><th>Rating</th></tr>';
    foreach ($byOfficer as $o) {
        $body .= '<tr><td>' . $esc($o['officer']) . '</td><td>' . (int)$o['total'] . '</td><td>'
            . (int)$o['selesai'] . '</td><td>' . (int)$o['terlambat'] . '</td><td>'
            . $esc(report_fmt_minutes($o['resp'] !== null ? round((float)$o['resp']) : null)) . '</td><td>'
            . $esc(report_fmt_minutes($o['resv'] !== null ? round((float)$o['resv']) : null)) . '</td><td>'
            . ($o['rating'] !== null ? $esc(round((float)$o['rating'], 1)) . ' ★' : '—') . '</td></tr>';
    }
    $body .= '</table>';

    $title = 'Laporan Kinerja IT Helpdesk';
    $orientation = 'portrait';
} elseif ($type === 'sla') {
    $stats = sla_report_stats($pdo, $from, $to);
    $body .= kpi_rows([
        'Total Tiket'      => $stats['total'],
        'Selesai'          => $stats['resolved'],
        'Melewati SLA'     => $stats['breached'],
        'Kepatuhan SLA'    => $stats['compliance'] . '%',
        'Rata2 Respons'    => report_fmt_minutes($stats['avg_response_min']),
        'Rata2 Selesai'    => report_fmt_minutes($stats['avg_resolution_min']),
    ]);

    $body .= '<h2>Kepatuhan per Prioritas</h2><table><tr><th>Prioritas</th><th>Total</th><th>Selesai</th><th>Terlambat</th><th>Kepatuhan</th></tr>';
    foreach ($prioLabel as $p => $label) {
        $r = $stats['by_priority'][$p] ?? null;
        $total = $r ? (int)$r['total'] : 0;
        $late = $r ? (int)$r['breached'] : 0;
        $comp = $total > 0 ? round((($total - $late) / $total) * 100, 1) : 100;
        $body .= '<tr><td>' . $label . '</td><td>' . $total . '</td><td>' . ($r ? (int)$r['done'] : 0)
            . '</td><td>' . $late . '</td><td>' . $comp . '%</td></tr>';
    }
    $body .= '</table>';

    $stmt = $pdo->prepare("SELECT * FROM tickets WHERE created_at BETWEEN ? AND ? AND sla_breached = 1 ORDER BY sla_due_at LIMIT 100");
    $stmt->execute([$from, $to]);
    $late = $stmt->fetchAll();
    $body .= '<h2>Tiket Melewati SLA (' . count($late) . ')</h2>';
    if (!$late) {
        $body .= '<p>Tidak ada tiket yang melewati SLA pada periode ini.</p>';
    } else {
        $body .= '<table><tr><th>Nomor</th><th>Judul</th><th>Prioritas</th><th>Status</th><th>Petugas</th><th>Batas SLA</th></tr>';
        foreach ($late as $t) {
            $body .= '<tr><td>' . $esc($t['ticket_number']) . '</td><td>' . $esc(mb_strimwidth($t['title'], 0, 60, '…'))
                . '</td><td>' . $esc($prioLabel[$t['priority']] ?? $t['priority']) . '</td><td>'
                . $esc($statusLabel[$t['status']] ?? $t['status']) . '</td><td>' . $esc($t['assigned_name'] ?: '—')
                . '</td><td>' . $esc($t['sla_due_at']) . '</td></tr>';
        }
        $body .= '</table>';
    }
    $title = 'Laporan SLA';
    $orientation = 'portrait';
} elseif ($type === 'network') {
    $byRoom = report_by_room($pdo, $from, $to);
    $byType = [];
    try {
        $stmt = $pdo->prepare(
            "SELECT COALESCE(network_type, 'lainnya') t, COUNT(*) c FROM tickets
             WHERE is_network_complaint = 1 AND created_at BETWEEN ? AND ? GROUP BY t ORDER BY c DESC"
        );
        $stmt->execute([$from, $to]);
        $byType = $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('[EXPORT NETWORK] ' . $e->getMessage());
    }
    $typeLabels = network_types();

    $body .= kpi_rows([
        'Total Gangguan'   => $summary['network'],
        'Ruangan Terdampak' => count($byRoom),
        'Melewati SLA'     => $summary['breached'],
        'Total Tiket'      => $summary['total'],
    ]);

    $body .= '<h2>Gangguan per Ruangan</h2><table><tr><th>Ruangan</th><th>Total</th><th>Terlambat</th></tr>';
    foreach ($byRoom as $r) {
        $body .= '<tr><td>' . $esc($r['room_name']) . '</td><td>' . (int)$r['total'] . '</td><td>' . (int)$r['terlambat'] . '</td></tr>';
    }
    $body .= '</table>';

    $body .= '<h2>Gangguan per Jenis</h2><table><tr><th>Jenis Gangguan</th><th>Total</th></tr>';
    foreach ($byType as $t) {
        $body .= '<tr><td>' . $esc($typeLabels[$t['t']] ?? $t['t']) . '</td><td>' . (int)$t['c'] . '</td></tr>';
    }
    $body .= '</table>';

    $stmt = $pdo->prepare(
        "SELECT t.*, r.name AS room_name FROM tickets t LEFT JOIN rooms r ON r.id = t.room_id
         WHERE t.is_network_complaint = 1 AND t.created_at BETWEEN ? AND ? ORDER BY t.created_at DESC LIMIT 100"
    );
    $stmt->execute([$from, $to]);
    $list = $stmt->fetchAll();
    $body .= '<h2>Daftar Gangguan Jaringan (' . count($list) . ')</h2>';
    if ($list) {
        $body .= '<table><tr><th>Nomor</th><th>Tanggal</th><th>Ruangan</th><th>Jenis</th><th>Perangkat</th><th>Status</th><th>Hasil Teknisi</th></tr>';
        foreach ($list as $t) {
            $body .= '<tr><td>' . $esc($t['ticket_number']) . '</td><td>' . $esc(date('d/m/Y', strtotime($t['created_at'])))
                . '</td><td>' . $esc($t['room_name'] ?: ($t['location_room'] ?: '—'))
                . '</td><td>' . $esc($typeLabels[$t['network_type']] ?? '—')
                . '</td><td>' . $esc($t['affected_device'] ?: '—')
                . '</td><td>' . $esc($statusLabel[$t['status']] ?? $t['status'])
                . '</td><td>' . $esc($t['technician_result'] ? mb_strimwidth($t['technician_result'], 0, 70, '…') : '—') . '</td></tr>';
        }
        $body .= '</table>';
    }
    $title = 'Laporan Gangguan Jaringan & Internet';
    $orientation = 'landscape';
} else {
    http_response_code(400);
    exit('Jenis laporan tidak dikenal.');
}

} catch (Throwable $e) {
    error_log('[EXPORT] ' . $e->getMessage());
    $body .= '<div style="border:1px solid #fca5a5;background:#fef2f2;color:#991b1b;padding:10px 12px;margin:12px 0;font-size:12px">'
        . '<strong>Sebagian data laporan tidak dapat dibaca.</strong> Kemungkinan migrasi database belum lengkap '
        . '(lihat install-check.php). Detail: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
}

$subtitle = 'Periode: ' . $rangeLabel . ' · Dicetak oleh ' . current_user()['name'];

/* ============================================================
 * 3) Preview HTML (tanpa domPDF) atau PDF
 * ============================================================ */
if ($format === 'preview' || ($format === 'pdf' && !dompdf_available())) {
    header('Content-Type: text/html; charset=UTF-8');
    $html = render_report_html($title, $subtitle, $body, false);
    $missing = ($format === 'pdf' && !dompdf_available());
    $bar = '<div class="pdf-preview-bar no-print"><span><strong>Preview laporan</strong> — ' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
        . ($missing ? ' · <span style="color:#ffd28a">domPDF belum terpasang, ini tampilan preview</span>' : '')
        . '</span><span><a href="javascript:window.print()" class="btn btn-sm btn-ghost" style="color:#fff;border-color:#4a5a70">🖨 Cetak</a> '
        . '<a href="' . htmlspecialchars(url('reports/index.php'), ENT_QUOTES, 'UTF-8') . '" class="btn btn-sm btn-ghost" style="color:#fff;border-color:#4a5a70">Kembali</a></span></div>';
    echo str_replace('<body>', '<body>' . $bar, $html);
    exit;
}

// PDF
$html = render_report_html($title, $subtitle, $body, true);
$filename = strtolower(str_replace(' ', '-', $type)) . '-lju-' . $fromDate . '-sd-' . $toDate . '.pdf';
if (!render_pdf($html, $filename, $orientation)) {
    http_response_code(500);
    exit('domPDF tidak tersedia. Lihat panduan instalasi di README bagian "Export PDF".');
}
exit;
