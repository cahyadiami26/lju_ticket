<?php
/**
 * MikroTik RouterOS v7 REST API client (Batch 7).
 *
 * Prasyarat di router:
 *   /ip service enable www-ssl   (atau www untuk HTTP, tidak disarankan)
 *   /user add name=helpdesk group=read password=... 
 *
 * Endpoint yang dipakai:
 *   GET /rest/system/resource
 *   GET /rest/interface
 *   GET /rest/interface/ethernet
 *   GET /rest/ppp/active
 *   GET /rest/ip/dhcp-server/lease
 *   GET /rest/queue/simple
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';

/** Kunci enkripsi password router (dari config: ENCRYPT_KEY). */
function mikrotik_crypto_key() {
    $key = defined('ENCRYPT_KEY') ? ENCRYPT_KEY : 'lju-helpdesk-default-key';
    return hash('sha256', $key, true);
}

/** Enkripsi password router (AES-256-CBC, base64). */
function mikrotik_encrypt($plain) {
    if ($plain === '' || $plain === null) return '';
    if (!function_exists('openssl_encrypt')) return base64_encode('plain:' . $plain); // fallback
    $iv = random_bytes(16);
    $cipher = openssl_encrypt($plain, 'AES-256-CBC', mikrotik_crypto_key(), OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $cipher);
}

/** Dekripsi password router. */
function mikrotik_decrypt($stored) {
    if ($stored === '' || $stored === null) return '';
    $raw = base64_decode($stored, true);
    if ($raw === false) return '';
    if (strpos($raw, 'plain:') === 0) return substr($raw, 6);
    if (!function_exists('openssl_decrypt') || strlen($raw) < 17) return '';
    $iv = substr($raw, 0, 16);
    $cipher = substr($raw, 16);
    $plain = openssl_decrypt($cipher, 'AES-256-CBC', mikrotik_crypto_key(), OPENSSL_RAW_DATA, $iv);
    return $plain === false ? '' : $plain;
}

/** Ambil konfigurasi MikroTik (baris tunggal). */
function mikrotik_config($pdo, $withPassword = false) {
    $cfg = [
        'id' => 0, 'host_url' => '', 'username' => '', 'password' => '',
        'verify_ssl' => 0, 'is_active' => 0,
        'last_check_at' => null, 'last_check_status' => null, 'last_check_message' => null,
        'router_board' => null, 'router_version' => null, 'router_uptime' => null,
    ];
    try {
        $row = $pdo->query('SELECT * FROM mikrotik_settings ORDER BY id LIMIT 1')->fetch();
        if ($row) {
            $cfg = array_merge($cfg, $row);
            if ($withPassword) {
                $cfg['password'] = mikrotik_decrypt($row['password']);
            } else {
                $cfg['password'] = '';
                $cfg['has_password'] = !empty($row['password']);
            }
        }
    } catch (Throwable $e) {
        error_log('[MIKROTIK CONFIG] ' . $e->getMessage());
    }
    return $cfg;
}

/**
 * Panggil REST API MikroTik.
 * @return array ['ok'=>bool,'data'=>mixed,'code'=>int,'error'=>string]
 */
function mikrotik_request($pdo, $path, $method = 'GET', $body = null) {
    $cfg = mikrotik_config($pdo, true);
    if (empty($cfg['host_url'])) {
        return ['ok' => false, 'data' => null, 'code' => 0, 'error' => 'URL router belum diatur.'];
    }
    $base = rtrim($cfg['host_url'], '/');
    $url = $base . '/rest' . $path;

    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'data' => null, 'code' => 0, 'error' => 'Ekstensi cURL tidak tersedia di server.'];
    }
    $headers = ['Accept: application/json'];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_USERPWD        => $cfg['username'] . ':' . $cfg['password'],
        CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_SSL_VERIFYPEER => !empty($cfg['verify_ssl']),
        CURLOPT_SSL_VERIFYHOST => !empty($cfg['verify_ssl']) ? 2 : 0,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'data' => null, 'code' => 0, 'error' => 'Gagal terhubung: ' . $curlErr];
    }
    if ($code === 401) {
        return ['ok' => false, 'data' => null, 'code' => 401, 'error' => 'Autentikasi gagal (periksa username/password router).'];
    }
    if ($code === 404) {
        return ['ok' => false, 'data' => null, 'code' => 404, 'error' => 'Endpoint tidak ditemukan. Pastikan RouterOS v7 dan REST API aktif.'];
    }
    if ($code < 200 || $code >= 300) {
        return ['ok' => false, 'data' => $response, 'code' => $code, 'error' => 'Router membalas HTTP ' . $code];
    }
    $data = json_decode($response, true);
    if ($data === null && trim($response) !== '') {
        return ['ok' => false, 'data' => null, 'code' => $code, 'error' => 'Respons bukan JSON valid.'];
    }
    return ['ok' => true, 'data' => $data ?? [], 'code' => $code, 'error' => ''];
}

/** Uji koneksi + simpan status ke DB. */
function mikrotik_test_connection($pdo) {
    $res = mikrotik_request($pdo, '/system/resource');
    $status = $res['ok'] ? 'ok' : 'error';
    $message = $res['ok'] ? 'Koneksi berhasil' : $res['error'];

    $board = $version = $uptime = null;
    if ($res['ok'] && is_array($res['data'])) {
        $d = $res['data'][0] ?? $res['data'];
        $board = $d['board-name'] ?? null;
        $version = $d['version'] ?? null;
        $uptime = $d['uptime'] ?? null;
    }
    try {
        $stmt = $pdo->prepare(
            'UPDATE mikrotik_settings
             SET last_check_at = NOW(), last_check_status = ?, last_check_message = ?,
                 router_board = COALESCE(?, router_board), router_version = COALESCE(?, router_version),
                 router_uptime = COALESCE(?, router_uptime)
             WHERE id = (SELECT id FROM (SELECT id FROM mikrotik_settings ORDER BY id LIMIT 1) x)'
        );
        $stmt->execute([$status, mb_substr($message, 0, 250), $board, $version, $uptime]);
    } catch (Throwable $e) {
        error_log('[MIKROTIK SAVE STATUS] ' . $e->getMessage());
    }
    return ['ok' => $res['ok'], 'message' => $message, 'data' => $res['data'], 'code' => $res['code']];
}

/** Ambil daftar interface (dengan counter & status). */
function mikrotik_interfaces($pdo) {
    $res = mikrotik_request($pdo, '/interface');
    if (!$res['ok']) return ['ok' => false, 'error' => $res['error'], 'data' => []];
    $rows = is_array($res['data']) ? $res['data'] : [];
    // Urutkan: running dulu, lalu nama
    usort($rows, function ($a, $b) {
        $ra = ($a['running'] ?? 'false') === 'true' ? 0 : 1;
        $rb = ($b['running'] ?? 'false') === 'true' ? 0 : 1;
        return $ra === $rb ? strcmp($a['name'] ?? '', $b['name'] ?? '') : $ra - $rb;
    });
    return ['ok' => true, 'error' => '', 'data' => $rows];
}

/** Konversi angka dari router (bisa string "123456") ke int. */
function mikrotik_int($v) {
    if (is_int($v)) return $v;
    if (is_float($v)) return (int)$v;
    return (int)preg_replace('/[^0-9]/', '', (string)$v);
}

/** Format bit/detik menjadi teks. */
function format_bps($bps) {
    $bps = (float)$bps;
    $units = ['bps', 'Kbps', 'Mbps', 'Gbps'];
    $i = 0;
    while ($bps >= 1000 && $i < count($units) - 1) {
        $bps /= 1000;
        $i++;
    }
    return ($bps >= 100 ? number_format($bps, 0) : number_format($bps, 2)) . ' ' . $units[$i];
}

/** Format byte ke ukuran terbaca. */
function format_bytes($bytes) {
    $bytes = (float)$bytes;
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return ($bytes >= 100 ? number_format($bytes, 0) : number_format($bytes, 1)) . ' ' . $units[$i];
}

/**
 * Sampel traffic: hitung bps dari selisih counter.
 * Menyimpan snapshot ke mikrotik_traffic_history untuk grafik historis.
 * @return array nama_interface => ['rx_bps'=>, 'tx_bps'=>, 'rx'=>, 'tx'=>]
 */
function mikrotik_sample_traffic($pdo, $rows, $store = false) {
    $cacheFile = sys_get_temp_dir() . '/lju_mikrotik_counters.json';
    $prev = [];
    if (is_file($cacheFile)) {
        $prev = json_decode((string)@file_get_contents($cacheFile), true) ?: [];
    }
    $now = microtime(true);
    $out = [];
    foreach ($rows as $r) {
        $name = $r['name'] ?? '';
        if ($name === '') continue;
        $rx = mikrotik_int($r['rx-byte'] ?? 0);
        $tx = mikrotik_int($r['tx-byte'] ?? 0);
        $rxBps = $txBps = 0;
        if (isset($prev[$name]['t'], $prev[$name]['rx'], $prev[$name]['tx'])) {
            $dt = max(0.001, $now - (float)$prev[$name]['t']);
            $dRx = $rx - (int)$prev[$name]['rx'];
            $dTx = $tx - (int)$prev[$name]['tx'];
            if ($dRx >= 0) $rxBps = (int)round(($dRx * 8) / $dt);
            if ($dTx >= 0) $txBps = (int)round(($dTx * 8) / $dt);
        }
        $prev[$name] = ['t' => $now, 'rx' => $rx, 'tx' => $tx];
        $out[$name] = ['rx_bps' => $rxBps, 'tx_bps' => $txBps, 'rx' => $rx, 'tx' => $tx];
        if ($store) {
            try {
                $stmt = $pdo->prepare('INSERT INTO mikrotik_traffic_history (interface_name, rx_bps, tx_bps) VALUES (?, ?, ?)');
                $stmt->execute([$name, $rxBps, $txBps]);
            } catch (Throwable $e) {
                error_log('[MIKROTIK HISTORY] ' . $e->getMessage());
            }
        }
    }
    @file_put_contents($cacheFile, json_encode($prev));
    return $out;
}

/** Riwayat traffic untuk grafik. */
function mikrotik_traffic_history($pdo, $interface, $minutes = 60) {
    try {
        $stmt = $pdo->prepare(
            'SELECT rx_bps, tx_bps, recorded_at FROM mikrotik_traffic_history
             WHERE interface_name = ? AND recorded_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
             ORDER BY recorded_at ASC LIMIT 500'
        );
        $stmt->execute([$interface, (int)$minutes]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('[MIKROTIK HISTORY READ] ' . $e->getMessage());
        return [];
    }
}

/** Hapus riwayat lebih lama dari N hari. */
function mikrotik_prune_history($pdo, $days = 7) {
    try {
        $stmt = $pdo->prepare('DELETE FROM mikrotik_traffic_history WHERE recorded_at < DATE_SUB(NOW(), INTERVAL ? DAY)');
        $stmt->execute([(int)$days]);
    } catch (Throwable $e) {
        error_log('[MIKROTIK PRUNE] ' . $e->getMessage());
    }
}

/** Data singkat dashboard monitoring. */
function mikrotik_overview($pdo) {
    $out = [
        'resource' => null, 'interfaces' => [], 'pppoe' => [], 'dhcp' => [], 'queues' => [],
        'error' => '', 'ok' => true,
    ];
    $res = mikrotik_request($pdo, '/system/resource');
    if (!$res['ok']) {
        return array_merge($out, ['ok' => false, 'error' => $res['error']]);
    }
    $out['resource'] = is_array($res['data']) ? ($res['data'][0] ?? $res['data']) : [];

    $ifaces = mikrotik_interfaces($pdo);
    $out['interfaces'] = $ifaces['data'];

    $p = mikrotik_request($pdo, '/ppp/active');
    $out['pppoe'] = $p['ok'] ? (is_array($p['data']) ? $p['data'] : []) : [];

    $d = mikrotik_request($pdo, '/ip/dhcp-server/lease');
    $out['dhcp'] = $d['ok'] ? (is_array($d['data']) ? $d['data'] : []) : [];

    $q = mikrotik_request($pdo, '/queue/simple');
    $out['queues'] = $q['ok'] ? (is_array($q['data']) ? $q['data'] : []) : [];

    return $out;
}
