<?php
/**
 * Helper umum: escaping, CSRF, tanggal, badge.
 */

if (!function_exists('e')) {
    function e($value) {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token() {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field() {
        return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
    }
}

if (!function_exists('csrf_check')) {
    function csrf_check() {
        return isset($_POST['_csrf']) && hash_equals($_SESSION['_csrf'] ?? '', $_POST['_csrf']);
    }
}

if (!function_exists('format_date')) {
    function format_date($datetime, $format = 'd M Y H:i') {
        if (empty($datetime)) return '-';
        $ts = strtotime($datetime);
        return $ts ? date($format, $ts) : '-';
    }
}

if (!function_exists('time_ago')) {
    function time_ago($datetime) {
        if (empty($datetime)) return '-';
        $ts = strtotime($datetime);
        if (!$ts) return '-';
        $diff = time() - $ts;
        if ($diff < 60) return 'baru saja';
        if ($diff < 3600) return floor($diff / 60) . ' menit lalu';
        if ($diff < 86400) return floor($diff / 3600) . ' jam lalu';
        if ($diff < 2592000) return floor($diff / 86400) . ' hari lalu';
        return date('d M Y', $ts);
    }
}

if (!function_exists('initials')) {
    function initials($name) {
        $name = trim((string)$name);
        if ($name === '') return '?';
        $parts = preg_split('/\s+/', $name);
        $out = strtoupper(mb_substr($parts[0], 0, 1));
        if (count($parts) > 1) {
            $out .= strtoupper(mb_substr(end($parts), 0, 1));
        }
        return $out;
    }
}

if (!function_exists('status_badge')) {
    function status_badge($status) {
        $map = [
            'open'         => ['Open', 'badge-open'],
            'assigned'     => ['Assigned', 'badge-assigned'],
            'in_progress'  => ['In Progress', 'badge-progress'],
            'waiting_user' => ['Waiting User', 'badge-waiting'],
            'resolved'     => ['Resolved', 'badge-resolved'],
            'closed'       => ['Closed', 'badge-closed'],
        ];
        $label = $map[$status][0] ?? ucfirst(str_replace('_', ' ', (string)$status));
        $class = $map[$status][1] ?? 'badge-closed';
        return '<span class="badge ' . e($class) . '">' . e($label) . '</span>';
    }
}

if (!function_exists('priority_badge')) {
    function priority_badge($priority) {
        $map = [
            'low'      => ['Low', 'badge-low'],
            'medium'   => ['Medium', 'badge-medium'],
            'high'     => ['High', 'badge-high'],
            'critical' => ['Critical', 'badge-critical'],
        ];
        $label = $map[$priority][0] ?? ucfirst((string)$priority);
        $class = $map[$priority][1] ?? 'badge-low';
        return '<span class="badge ' . e($class) . '">' . e($label) . '</span>';
    }
}

if (!function_exists('history_label')) {
    /** Label manusiawi untuk kolom ticket_history.action */
    function history_label($action) {
        $map = [
            'created'          => 'Tiket dibuat',
            'assigned'         => 'Ditugaskan ke petugas',
            'status_changed'   => 'Status diubah',
            'priority_changed' => 'Prioritas diubah',
            'comment'          => 'Komentar ditambahkan',
            'attachment'       => 'Lampiran ditambahkan',
            'escalated'        => 'Tiket dieskalasi',
            'transferred'      => 'Tiket ditransfer',
            'reopened'         => 'Tiket dibuka kembali',
            'resolved'         => 'Tiket diselesaikan',
            'closed'           => 'Tiket ditutup',
            'rated'            => 'Rating layanan diberikan',
            'sla_breach'       => 'Melewati batas SLA',
        ];
        return $map[$action] ?? ucfirst(str_replace('_', ' ', (string)$action));
    }
}

if (!function_exists('sla_state')) {
    /**
     * Status SLA sebuah tiket.
     * @return array ['state' => ok|warning|overdue|done|none, 'diff' => detik, 'text' => label]
     */
    function sla_state($ticket) {
        if (empty($ticket['sla_due_at'])) {
            return ['state' => 'none', 'diff' => 0, 'text' => 'Tanpa SLA'];
        }
        if (in_array($ticket['status'] ?? '', ['resolved', 'closed'], true)) {
            return ['state' => 'done', 'diff' => 0, 'text' => 'Selesai'];
        }
        $diff = strtotime($ticket['sla_due_at']) - time();
        $abs = abs($diff);
        $h = floor($abs / 3600);
        $m = floor(($abs % 3600) / 60);
        $human = $h > 0 ? ($h . 'j ' . $m . 'm') : ($m . 'm');

        if ($diff < 0) {
            return ['state' => 'overdue', 'diff' => $diff, 'text' => 'Lewat ' . $human];
        }
        if ($diff <= 3600) {
            return ['state' => 'warning', 'diff' => $diff, 'text' => 'Sisa ' . $human];
        }
        // > 1 hari tampilkan dalam hari
        if ($diff > 86400) {
            return ['state' => 'ok', 'diff' => $diff, 'text' => 'Sisa ' . floor($diff / 86400) . ' hari'];
        }
        return ['state' => 'ok', 'diff' => $diff, 'text' => 'Sisa ' . $human];
    }
}

if (!function_exists('sla_badge')) {
    function sla_badge($ticket) {
        $s = sla_state($ticket);
        $class = [
            'ok'      => 'badge-resolved',
            'warning' => 'badge-medium',
            'overdue' => 'badge-critical',
            'done'    => 'badge-closed',
            'none'    => 'badge-closed',
        ][$s['state']] ?? 'badge-closed';
        return '<span class="badge ' . e($class) . '">' . e($s['text']) . '</span>';
    }
}
