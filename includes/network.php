<?php
/**
 * Helper modul Network & Internet Complaint (Batch 4).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

/** Daftar jenis gangguan jaringan (value => label). */
function network_types() {
    return [
        'internet_lambat' => 'Internet lambat',
        'internet_putus'  => 'Internet terputus / mati total',
        'wifi'            => 'WiFi tidak dapat terhubung',
        'lan'             => 'LAN / kabel tidak berfungsi',
        'tidak_stabil'    => 'Jaringan tidak stabil (putus-nyambung)',
        'akses_aplikasi'  => 'Gangguan akses aplikasi internal',
        'lainnya'         => 'Lainnya',
    ];
}

/** Daftar jenis pemeriksaan teknisi. */
function network_check_types() {
    return [
        'ping'        => 'Ping / traceroute',
        'speedtest'   => 'Uji kecepatan (speedtest)',
        'wifi_signal' => 'Kekuatan sinyal WiFi / access point',
        'lan_port'    => 'Uji port LAN / kabel',
        'perangkat'   => 'Pemeriksaan perangkat (router/switch/AP)',
        'lainnya'     => 'Pemeriksaan lainnya',
    ];
}

/** Daftar perangkat jaringan yang umum bermasalah. */
function network_device_types() {
    return [
        'Access Point', 'Switch', 'Router / MikroTik', 'Kabel LAN', 'Port LAN / RJ45',
        'Komputer / Laptop', 'Printer jaringan', 'Perangkat lain',
    ];
}

/** Ambil semua ruangan aktif. */
function get_rooms($pdo, $onlyActive = true) {
    try {
        $sql = 'SELECT * FROM rooms ' . ($onlyActive ? 'WHERE is_active = 1 ' : '') . 'ORDER BY building, floor, name';
        return $pdo->query($sql)->fetchAll();
    } catch (Throwable $e) {
        error_log('[ROOMS] ' . $e->getMessage());
        return [];
    }
}

/** Label lokasi lengkap sebuah ruangan. */
function room_label($room) {
    if (!$room) return '-';
    $parts = array_filter([$room['name'] ?? '', $room['building'] ?? '', $room['floor'] ?? '']);
    return implode(' · ', $parts);
}

/** Ringkasan jumlah gangguan jaringan per ruangan. */
function network_summary_by_room($pdo, $days = 90) {
    try {
        $stmt = $pdo->prepare(
            "SELECT t.room_id AS room_id,
                    COALESCE(r.name, t.location_room, 'Tanpa lokasi') AS room_name,
                    COUNT(*) AS total,
                    SUM(t.status NOT IN ('resolved','closed')) AS aktif,
                    MAX(t.created_at) AS terakhir
             FROM tickets t
             LEFT JOIN rooms r ON r.id = t.room_id
             WHERE t.is_network_complaint = 1 AND t.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY t.room_id, room_name
             ORDER BY total DESC"
        );
        $stmt->execute([(int)$days]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('[NETWORK ROOM SUMMARY] ' . $e->getMessage());
        return [];
    }
}

/** Ringkasan gangguan jaringan per jenis gangguan. */
function network_summary_by_type($pdo, $days = 90) {
    try {
        $stmt = $pdo->prepare(
            "SELECT COALESCE(network_type, 'lainnya') AS network_type, COUNT(*) AS total
             FROM tickets
             WHERE is_network_complaint = 1 AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY network_type ORDER BY total DESC"
        );
        $stmt->execute([(int)$days]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('[NETWORK TYPE SUMMARY] ' . $e->getMessage());
        return [];
    }
}

/** Ambil riwayat gangguan jaringan untuk satu ruangan. */
function network_tickets_by_room($pdo, $roomId = null, $roomName = null, $limit = 50) {
    try {
        if ($roomId) {
            $stmt = $pdo->prepare(
                'SELECT t.*, c.name AS category_name FROM tickets t
                 LEFT JOIN categories c ON c.id = t.category_id
                 WHERE t.is_network_complaint = 1 AND t.room_id = ?
                 ORDER BY t.created_at DESC LIMIT ' . (int)$limit
            );
            $stmt->execute([(int)$roomId]);
        } else {
            $stmt = $pdo->prepare(
                'SELECT t.*, c.name AS category_name FROM tickets t
                 LEFT JOIN categories c ON c.id = t.category_id
                 WHERE t.is_network_complaint = 1 AND t.location_room = ?
                 ORDER BY t.created_at DESC LIMIT ' . (int)$limit
            );
            $stmt->execute([(string)$roomName]);
        }
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('[NETWORK BY ROOM] ' . $e->getMessage());
        return [];
    }
}

/** Checklist pemeriksaan jaringan milik sebuah tiket. */
function get_network_checks($pdo, $ticketId) {
    try {
        $stmt = $pdo->prepare('SELECT * FROM network_checks WHERE ticket_id = ? ORDER BY created_at ASC');
        $stmt->execute([(int)$ticketId]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('[NETWORK CHECKS] ' . $e->getMessage());
        return [];
    }
}

/**
 * Simpan satu hasil pemeriksaan jaringan.
 * @return array ['ok'=>bool,'message'=>string]
 */
function add_network_check($pdo, $ticketId, $user, $checkType, $result, $notes = '') {
    $types = network_check_types();
    if (!isset($types[$checkType])) {
        return ['ok' => false, 'message' => 'Jenis pemeriksaan tidak valid.'];
    }
    $result = trim($result);
    if ($result === '') {
        return ['ok' => false, 'message' => 'Hasil pemeriksaan wajib diisi.'];
    }
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO network_checks (ticket_id, user_id, user_name, check_type, result, notes)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([(int)$ticketId, $user['id'], $user['name'], $checkType, $result, $notes ?: null]);
    } catch (Throwable $e) {
        error_log('[NETWORK CHECK ADD] ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Gagal menyimpan pemeriksaan.'];
    }
    if (function_exists('add_ticket_history')) {
        add_ticket_history($pdo, (int)$ticketId, $user['id'], $user['name'], 'network_check',
            null, $types[$checkType], 'Hasil: ' . $result);
    }
    return ['ok' => true, 'message' => 'Hasil pemeriksaan disimpan.'];
}

/**
 * Simpan hasil penanganan teknisi pada tiket jaringan.
 * @return array ['ok'=>bool,'message'=>string]
 */
function save_technician_result($pdo, $ticket, $user, $data) {
    $roomId = (int)($data['room_id'] ?? 0);
    $locationRoom = trim($data['location_room'] ?? '');
    $device = trim($data['affected_device'] ?? '');
    $result = trim($data['technician_result'] ?? '');
    $action = trim($data['technician_action'] ?? '');

    if (mb_strlen($result) < 5) {
        return ['ok' => false, 'message' => 'Hasil pemeriksaan wajib diisi (minimal 5 karakter).'];
    }

    // Jika ruangan dipilih dari daftar, pakai namanya sebagai lokasi
    if ($roomId > 0) {
        try {
            $stmt = $pdo->prepare('SELECT * FROM rooms WHERE id = ? LIMIT 1');
            $stmt->execute([$roomId]);
            $room = $stmt->fetch();
            if ($room) {
                $locationRoom = $room['name'];
            }
        } catch (Throwable $e) {
            error_log('[ROOM LOOKUP] ' . $e->getMessage());
        }
    }

    try {
        $stmt = $pdo->prepare(
            'UPDATE tickets
             SET room_id = ?, location_room = ?, affected_device = ?, technician_result = ?,
                 technician_action = ?, checked_at = NOW(), updated_at = NOW()
             WHERE id = ?'
        );
        $stmt->execute([
            $roomId > 0 ? $roomId : null,
            $locationRoom ?: null,
            $device ?: null,
            $result,
            $action ?: null,
            (int)$ticket['id'],
        ]);
    } catch (Throwable $e) {
        error_log('[TECH RESULT] ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Gagal menyimpan hasil penanganan.'];
    }

    if (function_exists('add_ticket_history')) {
        add_ticket_history($pdo, (int)$ticket['id'], $user['id'], $user['name'], 'technician_result',
            $ticket['technician_result'] ?? null, mb_substr($result, 0, 200), 'Perangkat: ' . ($device ?: '-'));
    }
    // Beri tahu pelapor bahwa hasil pemeriksaan tersedia
    if ((int)$ticket['user_id'] !== (int)$user['id'] && function_exists('notify_user')) {
        notify_user($pdo, (int)$ticket['user_id'], 'Hasil pemeriksaan tiket ' . $ticket['ticket_number'],
            $result, 'tickets/view.php?id=' . (int)$ticket['id'], 'info');
    }
    return ['ok' => true, 'message' => 'Hasil penanganan teknisi disimpan.'];
}
