<?php
/**
 * Auth helpers — session user dari tabel `users` (database terpisah).
 * Setiap request, session di-refresh dari DB users agar selalu mutakhir.
 * Mapping nomenclature:
 *   users.division   → Nama Bidang
 *   users.department → Nama Divisi
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

/** Ambil satu baris user dari database users berdasarkan id. */
function get_user_by_id($usersPdo, $userId) {
    if (!$usersPdo || empty($userId)) return null;
    try {
        $stmt = $usersPdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        error_log('[AUTH] ' . $e->getMessage());
        return null;
    }
}

/** Tulis ulang $_SESSION dari baris tabel users. */
function set_session_from_user($userRow) {
    if (!$userRow) return;
    $_SESSION['user_id']    = $userRow['id'];
    $_SESSION['user_nik']   = $userRow['nik'];
    $_SESSION['username']   = $userRow['username'];
    $_SESSION['user_name']  = $userRow['name'];
    $_SESSION['user_email'] = $userRow['email'];
    $_SESSION['role']       = $userRow['role'];
    $_SESSION['position']   = $userRow['position'];
    $_SESSION['division']   = $userRow['division'];   // → Nama Bidang
    $_SESSION['department'] = $userRow['department']; // → Nama Divisi
    $_SESSION['avatar']     = $userRow['avatar'];
    $_SESSION['photo']      = $userRow['photo'];
    $_SESSION['signature']  = $userRow['signature'];
    $_SESSION['rfid']       = $userRow['rfid'];
    $_SESSION['webmail_user'] = $userRow['webmail_user'];
    $_SESSION['webmail_pass'] = $userRow['webmail_pass'];
}

/** Refresh session dari DB users (dipanggil di setiap halaman terproteksi). */
function refresh_session_from_db($usersPdo) {
    if (empty($_SESSION['user_id'])) return;
    $row = get_user_by_id($usersPdo, $_SESSION['user_id']);
    if ($row) {
        set_session_from_user($row);
    }
}

refresh_session_from_db($usersPdo ?? null);

/** User saat ini (dari session). */
function current_user() {
    return [
        'id'         => $_SESSION['user_id'] ?? null,
        'nik'        => $_SESSION['user_nik'] ?? null,
        'username'   => $_SESSION['username'] ?? '',
        'name'       => $_SESSION['user_name'] ?? '',
        'email'      => $_SESSION['user_email'] ?? '',
        'role'       => $_SESSION['role'] ?? 'user',
        'position'   => $_SESSION['position'] ?? '',
        'division'   => $_SESSION['division'] ?? '',   // Nama Bidang
        'department' => $_SESSION['department'] ?? '', // Nama Divisi
        'avatar'     => $_SESSION['avatar'] ?? '',
        'photo'      => $_SESSION['photo'] ?? '',
    ];
}

/** Nama Bidang (mapping dari users.division). */
function nama_bidang() {
    return $_SESSION['division'] ?? '';
}

/** Nama Divisi (mapping dari users.department). */
function nama_divisi() {
    return $_SESSION['department'] ?? '';
}

/** Apakah user adalah admin (role admin di tabel users). */
function is_admin() {
    return ($_SESSION['role'] ?? '') === 'admin';
}

/**
 * Apakah user adalah tim IT.
 * Kriteria: role admin ATAU division = 'IT' ATAU terdaftar di tabel it_staff.
 */
function is_it($pdo = null) {
    if (is_admin()) return true;
    if (($_SESSION['division'] ?? '') === 'IT') return true;
    if ($pdo && !empty($_SESSION['user_id'])) {
        try {
            $stmt = $pdo->prepare('SELECT 1 FROM it_staff WHERE user_id = ? AND is_active = 1 LIMIT 1');
            $stmt->execute([$_SESSION['user_id']]);
            return (bool)$stmt->fetch();
        } catch (Throwable $e) {
            return false;
        }
    }
    return false;
}

/** Proteksi: hanya IT/admin. Redirect ke dashboard jika tidak berhak. */
function require_it($pdo = null) {
    if (!is_it($pdo)) {
        header('Location: dashboard.php');
        exit;
    }
}

/** URL avatar: https://dms.lju.co.id/uploads/avatars/{avatar} */
function avatar_url($user = null) {
    $avatar = $user['avatar'] ?? ($_SESSION['avatar'] ?? '');
    if (!empty($avatar)) {
        return AVATAR_BASE_URL . ltrim($avatar, '/');
    }
    return null; // fallback ke inisial di view
}

/** HTML avatar dengan fallback inisial. */
function avatar_html($user = null, $size = 40) {
    $name = $user['name'] ?? ($_SESSION['user_name'] ?? 'User');
    $url  = avatar_url($user);
    if ($url) {
        return '<img src="' . e($url) . '" alt="' . e($name) . '" class="avatar" style="width:' . (int)$size . 'px;height:' . (int)$size . 'px" onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'inline-flex\'">'
            . '<span class="avatar avatar-initial" style="width:' . (int)$size . 'px;height:' . (int)$size . 'px;display:none">' . e(initials($name)) . '</span>';
    }
    return '<span class="avatar avatar-initial" style="width:' . (int)$size . 'px;height:' . (int)$size . 'px">' . e(initials($name)) . '</span>';
}
