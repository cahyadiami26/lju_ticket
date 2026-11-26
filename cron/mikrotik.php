<?php
/**
 * Cron sampling traffic MikroTik (Batch 7) — jalankan tiap 1–5 menit
 * agar grafik historis terisi walau tidak ada yang membuka halaman monitoring.
 *
 * CLI  : php /path/to/lju_ticket/cron/mikrotik.php
 * HTTP : curl "https://helpdesk.lju.co.id/cron/mikrotik.php?token=TOKEN"
 */
require_once __DIR__ . '/../includes/constants.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/mikrotik.php';

$isCli = (PHP_SAPI === 'cli');
$expected = (string)setting($pdo, 'cron_token', '');

if (!$isCli) {
    if ($expected === '' || !hash_equals($expected, (string)($_GET['token'] ?? ''))) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "403 Forbidden — token cron tidak valid.\n";
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$cfg = mikrotik_config($pdo);
if (empty($cfg['host_url']) || empty($cfg['is_active'])) {
    $out = '[' . date('Y-m-d H:i:s') . "] MikroTik tidak aktif / belum dikonfigurasi — dilewati.\n";
    echo $out;
    exit;
}

$res = mikrotik_interfaces($pdo);
if (!$res['ok']) {
    mikrotik_test_connection($pdo);
    $out = '[' . date('Y-m-d H:i:s') . '] Gagal membaca interface: ' . $res['error'] . "\n";
    echo $out;
    error_log('[CRON MIKROTIK] ' . trim($out));
    exit;
}

$samples = mikrotik_sample_traffic($pdo, $res['data'], true);
$pruned = 0;
try {
    $stmt = $pdo->prepare('DELETE FROM mikrotik_traffic_history WHERE recorded_at < DATE_SUB(NOW(), INTERVAL ? DAY)');
    $stmt->execute([defined('MIKROTIK_HISTORY_DAYS') ? MIKROTIK_HISTORY_DAYS : 7]);
    $pruned = $stmt->rowCount();
} catch (Throwable $e) {
    error_log('[CRON MIKROTIK PRUNE] ' . $e->getMessage());
}

$lines = [
    '[' . date('Y-m-d H:i:s') . '] Sampling traffic selesai',
    '  interface : ' . count($samples),
    '  riwayat dibersihkan: ' . $pruned . ' baris',
];
foreach (array_slice($samples, 0, 5, true) as $name => $s) {
    $lines[] = '  ' . str_pad($name, 22) . ' RX ' . format_bps($s['rx_bps']) . ' / TX ' . format_bps($s['tx_bps']);
}
$out = implode("\n", $lines) . "\n";
echo $out;
error_log('[CRON MIKROTIK] ' . str_replace("\n", ' | ', trim($out)));
