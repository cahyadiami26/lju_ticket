<?php
/**
 * Notification Center — pengiriman multi-kanal (Batch 5).
 * Kanal: in-app (tabel notifications), email perusahaan (SMTP / mail()),
 * WhatsApp (WhatsApp API gateway, default Fonnte-compatible).
 *
 * Semua pengiriman eksternal bersifat "best effort": kegagalan dicatat di
 * notification_logs dan tidak pernah menggagalkan transaksi tiket.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';

/**
 * Ambil data user (email + nomor WhatsApp) dari database users.
 * Nomor WA opsional: kolom `phone`, `whatsapp`, atau `no_hp` bila tersedia.
 */
function notifier_user_contact($usersPdo, $userId) {
    $out = ['email' => null, 'phone' => null, 'name' => null];
    if (!$usersPdo) return $out;
    try {
        $stmt = $usersPdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$userId]);
        $u = $stmt->fetch();
        if (!$u) return $out;
        $out['email'] = $u['email'] ?? null;
        $out['name']  = $u['name'] ?? null;
        foreach (['phone', 'whatsapp', 'no_hp', 'handphone', 'telepon'] as $col) {
            if (!empty($u[$col])) {
                $out['phone'] = $u[$col];
                break;
            }
        }
    } catch (Throwable $e) {
        error_log('[NOTIFIER CONTACT] ' . $e->getMessage());
    }
    return $out;
}

/** Normalisasi nomor telepon Indonesia ke format 62xxxxxxxxxx. */
function normalize_phone($phone) {
    $p = preg_replace('/[^0-9+]/', '', (string)$phone);
    if ($p === '') return '';
    if (strpos($p, '+') === 0) $p = substr($p, 1);
    if (strpos($p, '0') === 0) $p = '62' . substr($p, 1);
    if (strpos($p, '62') !== 0) $p = '62' . $p;
    return $p;
}

/** Catat percobaan kirim ke notification_logs. */
function notifier_log($pdo, $channel, $recipient, $subject, $body, $ticketId, $status, $response = null) {
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO notification_logs (channel, recipient, subject, body, ticket_id, status, response)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$channel, $recipient, $subject, $body, $ticketId ?: null, $status,
            is_string($response) ? mb_substr($response, 0, 2000) : json_encode($response)]);
    } catch (Throwable $e) {
        error_log('[NOTIFIER LOG] ' . $e->getMessage());
    }
}

/**
 * Kirim email via SMTP native (tanpa library), fallback ke mail().
 * @return array ['ok'=>bool,'message'=>string]
 */
function send_email_smtp($to, $subject, $bodyHtml, $config) {
    $host = $config['host'] ?? '';
    $port = (int)($config['port'] ?? 587);
    $user = $config['username'] ?? '';
    $pass = $config['password'] ?? '';
    $from = $config['from'] ?? 'helpdesk@lju.co.id';
    $fromName = $config['from_name'] ?? 'LJU IT Helpdesk';
    $secure = strtolower($config['secure'] ?? 'tls'); // tls | ssl | none

    // Tanpa konfigurasi SMTP → gunakan mail() server
    if ($host === '') {
        $headers = "MIME-Version: 1.0\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "From: " . mb_encode_mimeheader($fromName) . " <{$from}>\r\n";
        $ok = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $bodyHtml, $headers);
        return ['ok' => (bool)$ok, 'message' => $ok ? 'Email dikirim via mail()' : 'mail() gagal'];
    }

    $errno = 0;
    $errstr = '';
    $prefix = ($secure === 'ssl') ? 'ssl://' : '';
    $fp = @stream_socket_client($prefix . $host . ':' . $port, $errno, $errstr, 15);
    if (!$fp) {
        return ['ok' => false, 'message' => 'Koneksi SMTP gagal: ' . $errstr];
    }
    stream_set_timeout($fp, 15);

    $read = function () use ($fp) {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $data;
    };
    $write = function ($cmd) use ($fp) {
        fwrite($fp, $cmd . "\r\n");
    };
    $expect = function ($code) use ($read) {
        $resp = $read();
        return strpos(ltrim($resp), (string)$code) === 0 ? $resp : false;
    };

    $read(); // banner
    $write('EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'helpdesk.lju.co.id'));
    if ($expect(250) === false) {
        fclose($fp);
        return ['ok' => false, 'message' => 'SMTP EHLO ditolak'];
    }
    if ($secure === 'tls') {
        $write('STARTTLS');
        if ($expect(220) === false) {
            fclose($fp);
            return ['ok' => false, 'message' => 'STARTTLS ditolak'];
        }
        if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($fp);
            return ['ok' => false, 'message' => 'Negosiasi TLS gagal'];
        }
        $write('EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'helpdesk.lju.co.id'));
        $expect(250);
    }
    if ($user !== '') {
        $write('AUTH LOGIN');
        if ($expect(334) === false) { fclose($fp); return ['ok' => false, 'message' => 'AUTH LOGIN ditolak']; }
        $write(base64_encode($user));
        if ($expect(334) === false) { fclose($fp); return ['ok' => false, 'message' => 'Username SMTP ditolak']; }
        $write(base64_encode($pass));
        if ($expect(235) === false) { fclose($fp); return ['ok' => false, 'message' => 'Autentikasi SMTP gagal']; }
    }
    $write('MAIL FROM:<' . $from . '>');
    if ($expect(250) === false) { fclose($fp); return ['ok' => false, 'message' => 'MAIL FROM ditolak']; }
    $write('RCPT TO:<' . $to . '>');
    if ($expect(250) === false && $expect(251) === false) { fclose($fp); return ['ok' => false, 'message' => 'RCPT TO ditolak']; }
    $write('DATA');
    if ($expect(354) === false) { fclose($fp); return ['ok' => false, 'message' => 'DATA ditolak']; }

    $headers = 'From: ' . mb_encode_mimeheader($fromName) . " <{$from}>\r\n"
        . "To: <{$to}>\r\n"
        . "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "Date: " . date('r') . "\r\n";
    $data = $headers . "\r\n" . $bodyHtml . "\r\n.";
    fwrite($fp, $data . "\r\n");
    $resp = $read();
    $write('QUIT');
    fclose($fp);
    return ['ok' => strpos(ltrim($resp), '250') === 0, 'message' => trim($resp)];
}

/**
 * Kirim WhatsApp via API gateway (default Fonnte-compatible: POST token + target + message).
 * @return array ['ok'=>bool,'message'=>string]
 */
function send_whatsapp_api($phone, $message, $config) {
    $url = $config['api_url'] ?? '';
    $token = $config['token'] ?? '';
    if ($url === '' || $token === '') {
        return ['ok' => false, 'message' => 'Konfigurasi WhatsApp API belum lengkap'];
    }
    $payload = http_build_query([
        'target'  => $phone,
        'message' => $message,
        'countryCode' => '62',
    ]);
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Authorization: {$token}\r\nContent-Type: application/x-www-form-urlencoded\r\n",
            'content' => $payload,
            'timeout' => 15,
            'ignore_errors' => true,
        ],
    ]);
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) {
        return ['ok' => false, 'message' => 'Gagal menghubungi WhatsApp API'];
    }
    $json = json_decode($resp, true);
    $ok = is_array($json) ? !empty($json['status']) : true;
    return ['ok' => $ok, 'message' => mb_substr($resp, 0, 300)];
}

/** Template HTML email notifikasi tiket. */
function notifier_email_template($title, $message, $ticket = null, $link = null) {
    $brand = '#0b5cad';
    $ticketRow = '';
    if ($ticket) {
        $ticketRow = '<tr><td style="padding:4px 0;color:#6b7686">Nomor Tiket</td><td style="padding:4px 0;font-weight:700">'
            . htmlspecialchars($ticket['ticket_number'] ?? '', ENT_QUOTES, 'UTF-8') . '</td></tr>'
            . '<tr><td style="padding:4px 0;color:#6b7686">Judul</td><td style="padding:4px 0">'
            . htmlspecialchars($ticket['title'] ?? '', ENT_QUOTES, 'UTF-8') . '</td></tr>';
    }
    $button = $link
        ? '<p style="margin:20px 0 0"><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8')
          . '" style="background:' . $brand . ';color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none;font-weight:600">Buka di Helpdesk</a></p>'
        : '';
    return '<div style="font-family:Segoe UI,Arial,sans-serif;max-width:560px;margin:0 auto;background:#f4f6fa;padding:24px">'
        . '<div style="background:#fff;border-radius:12px;padding:24px;border:1px solid #e3e8f0">'
        . '<div style="font-size:12px;letter-spacing:.08em;color:' . $brand . ';font-weight:700">LJU IT HELPDESK</div>'
        . '<h2 style="margin:8px 0 4px;color:#1a2332;font-size:18px">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h2>'
        . '<p style="color:#334155;line-height:1.6">' . nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8')) . '</p>'
        . ($ticketRow ? '<table style="font-size:13px;margin:12px 0;width:100%">' . $ticketRow . '</table>' : '')
        . $button
        . '</div>'
        . '<div style="text-align:center;color:#8794a6;font-size:11px;margin-top:14px">PT Lampung Jasa Utama — helpdesk.lju.co.id</div>'
        . '</div>';
}

/**
 * Kirim notifikasi ke satu user melalui kanal aktif (in-app selalu; email/WA bila diaktifkan).
 * @return array ['ok'=>bool,'channels'=>array]
 */
function notify_user_channels($pdo, $usersPdo, $userId, $title, $message, $link = null, $type = 'info', $ticket = null) {
    $result = ['ok' => true, 'channels' => []];

    // 1) In-app (selalu)
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO notifications (user_id, title, message, link, type, channel, send_status)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([(int)$userId, $title, $message, $link, $type, 'app', 'sent']);
        $notifId = (int)$pdo->lastInsertId();
        $result['channels'][] = 'app';
    } catch (Throwable $e) {
        error_log('[NOTIFY APP] ' . $e->getMessage());
        $notifId = 0;
    }

    $contact = notifier_user_contact($usersPdo, $userId);
    $absLink = $link ? (defined('APP_URL') ? rtrim(APP_URL, '/') . '/' . ltrim($link, '/') : $link) : null;

    // 2) Email
    if (setting_bool($pdo, 'notify_email_enabled') && !empty($contact['email'])) {
        $cfg = [
            'host'      => setting($pdo, 'smtp_host', ''),
            'port'      => setting($pdo, 'smtp_port', 587),
            'username'  => setting($pdo, 'smtp_username', ''),
            'password'  => setting($pdo, 'smtp_password', ''),
            'secure'    => setting($pdo, 'smtp_secure', 'tls'),
            'from'      => setting($pdo, 'email_from', 'helpdesk@lju.co.id'),
            'from_name' => setting($pdo, 'email_from_name', 'LJU IT Helpdesk'),
        ];
        $html = notifier_email_template($title, $message, $ticket, $absLink);
        $res = send_email_smtp($contact['email'], $title, $html, $cfg);
        $result['channels'][] = 'email:' . ($res['ok'] ? 'sent' : 'failed');
        notifier_log($pdo, 'email', $contact['email'], $title, $html, $ticket['id'] ?? null,
            $res['ok'] ? 'sent' : 'failed', $res['message']);
        if ($notifId) {
            try {
                $pdo->prepare('UPDATE notifications SET send_status = ?, send_error = ? WHERE id = ?')
                    ->execute([$res['ok'] ? 'sent' : 'failed', $res['ok'] ? null : mb_substr($res['message'], 0, 250), $notifId]);
            } catch (Throwable $e) { /* abaikan */ }
        }
    }

    // 3) WhatsApp
    if (setting_bool($pdo, 'notify_whatsapp_enabled') && !empty($contact['phone'])) {
        $waCfg = [
            'api_url' => setting($pdo, 'whatsapp_api_url', ''),
            'token'   => setting($pdo, 'whatsapp_token', ''),
        ];
        $text = "*LJU IT Helpdesk*\n\n" . $title . "\n\n" . $message
            . ($ticket ? "\n\nTiket: " . $ticket['ticket_number'] : '')
            . ($absLink ? "\n" . $absLink : '');
        $res = send_whatsapp_api(normalize_phone($contact['phone']), $text, $waCfg);
        $result['channels'][] = 'whatsapp:' . ($res['ok'] ? 'sent' : 'failed');
        notifier_log($pdo, 'whatsapp', normalize_phone($contact['phone']), $title, $text, $ticket['id'] ?? null,
            $res['ok'] ? 'sent' : 'failed', $res['message']);
    }

    return $result;
}

/** Kirim notifikasi ke seluruh tim IT melalui kanal aktif. */
function notify_it_team_channels($pdo, $usersPdo, $title, $message, $link = null, $type = 'info', $ticket = null) {
    if (!$usersPdo) return 0;
    $sent = 0;
    try {
        $active = users_has_column($usersPdo, 'is_active') ? 'is_active = 1 AND ' : '';
        $rows = $usersPdo->query("SELECT id FROM users WHERE {$active}(role = 'admin' OR division = 'IT')")->fetchAll();
        foreach ($rows as $r) {
            notify_user_channels($pdo, $usersPdo, (int)$r['id'], $title, $message, $link, $type, $ticket);
            $sent++;
        }
    } catch (Throwable $e) {
        error_log('[NOTIFY IT CHANNELS] ' . $e->getMessage());
    }
    return $sent;
}
