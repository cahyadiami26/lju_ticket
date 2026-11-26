<?php
/**
 * Helper tiket: nomor tiket, history, notifikasi, upload lampiran.
 * Dipakai Batch 2 (Employee Portal) dan Batch 3+ (Ticket Management).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

/** Generate nomor tiket unik: LJU-YYYYMMDD-XXXX (XXXX = urutan harian). */
function generate_ticket_number($pdo) {
    $prefix = 'LJU-' . date('Ymd') . '-';
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM tickets WHERE ticket_number LIKE ?');
        $stmt->execute([$prefix . '%']);
        $seq = (int)$stmt->fetch()['c'] + 1 + $attempt;
        $number = $prefix . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
        $check = $pdo->prepare('SELECT 1 FROM tickets WHERE ticket_number = ? LIMIT 1');
        $check->execute([$number]);
        if (!$check->fetch()) {
            return $number;
        }
    }
    // Fallback: tambahkan random suffix jika bentrok terus
    return $prefix . str_pad((string)(rand(1000, 9999)), 4, '0', STR_PAD_LEFT);
}

/** Catat riwayat aktivitas tiket. */
function add_ticket_history($pdo, $ticketId, $userId, $userName, $action, $oldValue = null, $newValue = null, $notes = null) {
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO ticket_history (ticket_id, user_id, user_name, action, old_value, new_value, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$ticketId, $userId, $userName, $action, $oldValue, $newValue, $notes]);
    } catch (Throwable $e) {
        error_log('[HISTORY] ' . $e->getMessage());
    }
}

/** Buat notifikasi in-app untuk satu user. */
function notify_user($pdo, $userId, $title, $message, $link = null, $type = 'info') {
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO notifications (user_id, title, message, link, type) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $title, $message, $link, $type]);
    } catch (Throwable $e) {
        error_log('[NOTIF] ' . $e->getMessage());
    }
}

/** Notifikasi semua tim IT (role admin atau division IT di tabel users). */
function notify_it_team($pdo, $usersPdo, $title, $message, $link = null, $type = 'info') {
    if (!$usersPdo) return;
    try {
        $active = users_has_column($usersPdo, 'is_active') ? 'is_active = 1 AND ' : '';
        $rows = $usersPdo->query("SELECT id FROM users WHERE {$active}(role = 'admin' OR division = 'IT')")->fetchAll();
        foreach ($rows as $r) {
            notify_user($pdo, (int)$r['id'], $title, $message, $link, $type);
        }
    } catch (Throwable $e) {
        error_log('[NOTIF IT] ' . $e->getMessage());
    }
}

/**
 * Proses upload lampiran untuk satu tiket.
 * @return array ['ok'=>bool, 'error'=>string, 'count'=>int]
 */
function handle_ticket_uploads($pdo, $ticketId, $userId, array $files) {
    $result = ['ok' => true, 'error' => '', 'count' => 0];
    if (empty($files['name']) || !is_array($files['name'])) {
        return $result;
    }

    $allowed = array_map('trim', explode(',', strtolower(UPLOAD_ALLOWED_EXT)));
    $dir = rtrim(UPLOAD_DIR, '/') . '/';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $total = count($files['name']);
    for ($i = 0; $i < $total; $i++) {
        if (empty($files['name'][$i])) continue;
        if (isset($files['error'][$i]) && $files['error'][$i] !== UPLOAD_ERR_OK) {
            $result['ok'] = false;
            $result['error'] = 'Gagal mengunggah file: ' . $files['name'][$i];
            continue;
        }
        $ext = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            $result['ok'] = false;
            $result['error'] = 'Format file tidak diizinkan: ' . $files['name'][$i] . ' (diizinkan: ' . implode(', ', $allowed) . ')';
            continue;
        }
        if ($files['size'][$i] > UPLOAD_MAX_SIZE) {
            $result['ok'] = false;
            $result['error'] = 'Ukuran file melebihi batas: ' . $files['name'][$i];
            continue;
        }
        $safeName = $ticketId . '_' . time() . '_' . $i . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $files['name'][$i]);
        $dest = $dir . $safeName;
        if (!move_uploaded_file($files['tmp_name'][$i], $dest)) {
            $result['ok'] = false;
            $result['error'] = 'Gagal menyimpan file: ' . $files['name'][$i];
            continue;
        }
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO ticket_attachments (ticket_id, user_id, file_name, file_path, file_type, file_size)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $ticketId, $userId, $files['name'][$i], $safeName,
                $files['type'][$i] ?? $ext, (int)$files['size'][$i]
            ]);
            $result['count']++;
        } catch (Throwable $e) {
            error_log('[UPLOAD] ' . $e->getMessage());
            @unlink($dest);
        }
    }
    return $result;
}

/** Hitung target respons awal dari kebijakan sla_policies (menit). */
function compute_sla_response_due($pdo, $priority, $createdAt = null) {
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

/** Hitung SLA due dari kebijakan sla_policies. */
function compute_sla_due($pdo, $priority, $createdAt = null) {
    try {
        $stmt = $pdo->prepare('SELECT resolution_minutes FROM sla_policies WHERE priority = ? LIMIT 1');
        $stmt->execute([$priority]);
        $row = $stmt->fetch();
        if (!$row) return null;
        $base = $createdAt ? strtotime($createdAt) : time();
        return date('Y-m-d H:i:s', $base + ((int)$row['resolution_minutes'] * 60));
    } catch (Throwable $e) {
        return null;
    }
}
