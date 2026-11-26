<?php
/**
 * Buat Tiket Baru (Employee Portal — Batch 2).
 */
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/url.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ticket.php';

$pageTitle = 'Buat Tiket Baru';

$user = current_user();

$categories = [];
try {
    $categories = $pdo->query('SELECT * FROM categories WHERE is_active = 1 ORDER BY sort_order')->fetchAll();
} catch (Throwable $e) {
    error_log('[CREATE] ' . $e->getMessage());
}

$networkTypes = [
    '' => '— Pilih jenis gangguan jaringan —',
    'internet_lambat' => 'Internet lambat',
    'internet_putus' => 'Internet terputus',
    'wifi' => 'WiFi tidak dapat terhubung',
    'lan' => 'LAN tidak berfungsi',
    'tidak_stabil' => 'Jaringan tidak stabil',
    'akses_aplikasi' => 'Gangguan akses aplikasi internal',
    'lainnya' => 'Lainnya',
];

$errors = [];
$old = ['category_id' => '', 'title' => '', 'description' => '', 'priority' => 'medium', 'network_type' => '', 'location_room' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Token keamanan tidak valid. Silakan muat ulang halaman.';
    }

    $old['category_id'] = (int)($_POST['category_id'] ?? 0);
    $old['title'] = trim($_POST['title'] ?? '');
    $old['description'] = trim($_POST['description'] ?? '');
    $old['priority'] = $_POST['priority'] ?? 'medium';
    $old['network_type'] = $_POST['network_type'] ?? '';
    $old['location_room'] = trim($_POST['location_room'] ?? '');

    if (!$old['category_id']) $errors[] = 'Kategori wajib dipilih.';
    if (mb_strlen($old['title']) < 5) $errors[] = 'Judul minimal 5 karakter.';
    if (mb_strlen($old['description']) < 10) $errors[] = 'Deskripsi minimal 10 karakter.';
    if (!in_array($old['priority'], ['low', 'medium', 'high', 'critical'], true)) $errors[] = 'Prioritas tidak valid.';

    // Cek kategori jaringan
    $isNetwork = 0;
    foreach ($categories as $c) {
        if ((int)$c['id'] === $old['category_id']) {
            $isNetwork = (int)$c['is_network'];
            break;
        }
    }
    if ($isNetwork && $old['network_type'] === '') {
        $errors[] = 'Untuk kategori jaringan, jenis gangguan wajib dipilih.';
    }

    if (empty($errors)) {
        $ticketNumber = generate_ticket_number($pdo);
        $slaDue = compute_sla_due($pdo, $old['priority']);
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO tickets
                 (ticket_number, user_id, user_name, user_email, nama_bidang, nama_divisi,
                  category_id, title, description, priority, status,
                  is_network_complaint, network_type, location_room, sla_due_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $ticketNumber, $user['id'], $user['name'], $user['email'],
                $user['division'], $user['department'],
                $old['category_id'], $old['title'], $old['description'],
                $old['priority'], 'open',
                $isNetwork, $isNetwork ? $old['network_type'] : null,
                $isNetwork ? $old['location_room'] : null, $slaDue
            ]);
            $ticketId = (int)$pdo->lastInsertId();

            add_ticket_history($pdo, $ticketId, $user['id'], $user['name'], 'created', null, 'open', 'Tiket dibuat oleh pelapor');

            // Upload lampiran
            $uploadMsg = '';
            if (!empty($_FILES['attachments'])) {
                $up = handle_ticket_uploads($pdo, $ticketId, $user['id'], $_FILES['attachments']);
                if (!$up['ok']) $uploadMsg = ' ' . $up['error'];
                if ($up['count'] > 0) {
                    add_ticket_history($pdo, $ticketId, $user['id'], $user['name'], 'attachment', null, $up['count'] . ' file', 'Lampiran diunggah saat pembuatan tiket');
                }
            }

            // Notifikasi ke tim IT
            notify_it_team($pdo, $usersPdo, 'Tiket baru: ' . $ticketNumber, $old['title'], 'tickets/view.php?id=' . $ticketId, 'ticket_created');

            header('Location: ' . url('tickets/view.php') . '?id=' . $ticketId . '&created=1');
            exit;
        } catch (Throwable $e) {
            error_log('[CREATE TICKET] ' . $e->getMessage());
            $errors[] = 'Gagal membuat tiket. Silakan coba lagi.';
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="card" style="max-width:760px">
    <h3 class="card-title">Formulir Pengaduan / Permintaan Bantuan IT</h3>

    <?php if ($errors): ?>
        <div class="alert alert-error">
            <strong>Periksa kembali:</strong>
            <ul style="margin:6px 0 0;padding-left:18px">
                <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>

        <div class="form-group">
            <label for="category_id">Kategori Pengaduan *</label>
            <select id="category_id" name="category_id" class="form-control" required>
                <option value="">— Pilih kategori —</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= (int)$c['id'] ?>"<?= (int)$old['category_id'] === (int)$c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div id="networkFields" style="display:none">
            <div class="form-group">
                <label for="network_type">Jenis Gangguan Jaringan *</label>
                <select id="network_type" name="network_type" class="form-control">
                    <?php foreach ($networkTypes as $v => $l): ?>
                        <option value="<?= e($v) ?>"<?= $old['network_type'] === $v ? ' selected' : '' ?>><?= e($l) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="location_room">Lokasi / Ruangan Gangguan</label>
                <input type="text" id="location_room" name="location_room" class="form-control" placeholder="Contoh: Gedung A Lantai 2 Ruang Keuangan" value="<?= e($old['location_room']) ?>">
            </div>
        </div>

        <div class="form-group">
            <label for="title">Judul Pengaduan *</label>
            <input type="text" id="title" name="title" class="form-control" maxlength="255" required value="<?= e($old['title']) ?>" placeholder="Contoh: Internet lambat di ruang kerja saya">
        </div>

        <div class="form-group">
            <label for="description">Deskripsi / Kronologi *</label>
            <textarea id="description" name="description" class="form-control" required placeholder="Jelaskan kendala yang dialami, sejak kapan, dan dampaknya..."><?= e($old['description']) ?></textarea>
        </div>

        <div class="form-group">
            <label for="priority">Prioritas</label>
            <select id="priority" name="priority" class="form-control">
                <option value="low"<?= $old['priority'] === 'low' ? ' selected' : '' ?>>Low — tidak mendesak</option>
                <option value="medium"<?= $old['priority'] === 'medium' ? ' selected' : '' ?>>Medium — mengganggu pekerjaan</option>
                <option value="high"<?= $old['priority'] === 'high' ? ' selected' : '' ?>>High — pekerjaan terhambat</option>
                <option value="critical"<?= $old['priority'] === 'critical' ? ' selected' : '' ?>>Critical — operasional berhenti</option>
            </select>
            <div class="text-muted text-sm" style="margin-top:4px">SLA: Critical 15 mnt/2 jam · High 30 mnt/4 jam · Medium 2 jam/1 hari · Low 4 jam/3 hari.</div>
        </div>

        <div class="form-group">
            <label for="attachments">Lampiran (foto, screenshot, dokumen — maks <?= (int)(UPLOAD_MAX_SIZE / 1024 / 1024) ?> MB/file)</label>
            <input type="file" id="attachments" name="attachments[]" class="form-control" multiple accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.xls,.xlsx,.txt,.zip,.mp4">
        </div>

        <div class="quick-actions">
            <button type="submit" class="btn btn-primary">Kirim Tiket</button>
            <a href="<?= e(url('tickets.php')) ?>" class="btn btn-ghost">Batal</a>
        </div>
    </form>
</div>

<script>
(function () {
    var networkIds = <?= json_encode(array_map(function ($c) { return (int)$c['id']; }, array_filter($categories, function ($c) { return (int)$c['is_network'] === 1; }))) ?>;
    var sel = document.getElementById('category_id');
    var box = document.getElementById('networkFields');
    function toggle() {
        var show = networkIds.indexOf(parseInt(sel.value, 10)) !== -1;
        box.style.display = show ? 'block' : 'none';
    }
    sel.addEventListener('change', toggle);
    toggle();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
