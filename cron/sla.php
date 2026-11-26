<?php
/**
 * Cron SLA & Escalation — jalankan tiap 10 menit.
 *
 * CLI  : php /path/to/lju_ticket/cron/sla.php
 * HTTP : curl "https://helpdesk.lju.co.id/cron/sla.php?token=TOKEN"
 *
 * Token diambil dari pengaturan `cron_token` (menu Pengaturan).
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/sla.php';

$isCli = (PHP_SAPI === 'cli');
$expected = (string)setting($pdo, 'cron_token', '');

if (!$isCli) {
    $token = (string)($_GET['token'] ?? '');
    if ($expected === '' || !hash_equals($expected, $token)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "403 Forbidden — token cron tidak valid.\n";
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$startedAt = microtime(true);
$stats = run_sla_monitor($pdo, $usersPdo);
$duration = round(microtime(true) - $startedAt, 2);

$lines = [
    '[' . date('Y-m-d H:i:s') . '] SLA monitor selesai dalam ' . $duration . 's',
    '  dicek           : ' . $stats['checked'],
    '  peringatan      : ' . $stats['warned'],
    '  melewati SLA    : ' . $stats['breached'],
    '  eskalasi        : ' . $stats['escalated'],
    '  auto close      : ' . $stats['auto_closed'],
];
if (!empty($stats['errors'])) {
    $lines[] = '  error           : ' . implode(' | ', $stats['errors']);
}
$output = implode("\n", $lines) . "\n";

echo $output;
error_log('[CRON SLA] ' . str_replace("\n", ' | ', trim($output)));
