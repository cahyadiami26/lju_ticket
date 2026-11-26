<?php
/**
 * Sidebar navigasi.
 * Variabel dari header.php: $currentRel (path relatif thd root aplikasi), $user, $unreadNotifications.
 * Item dengan status implementasi false = disembunyikan sampai batch-nya selesai.
 */
$it = is_it($pdo ?? null);
$nav = [
    ['dashboard.php', 'Dashboard', '▦', true],
    ['tickets.php', 'Tiket Saya', '✉', true],
    ['tickets/create.php', 'Buat Tiket', '＋', true],
    ['faq.php', 'FAQ / Knowledge Base', '?', true],
    // Batch 3 (Ticket Management) — hanya IT
    ['admin/tickets.php', 'Manajemen Tiket', '⚙', false],
    ['admin/faq.php', 'Kelola FAQ', '✎', $it],
    // Batch 4 (Network Complaint)
    ['network/index.php', 'Pengaduan Jaringan', '◉', false],
    // Batch 6 (Reporting) — hanya IT
    ['reports/index.php', 'Laporan & Analitik', '▤', false],
    // Batch 7 (MikroTik Monitoring) — hanya IT
    ['monitoring/index.php', 'Monitoring Jaringan', '◈', false],
    // Batch 5 (Settings) — hanya IT
    ['settings/index.php', 'Pengaturan', '⚙', false],
];
?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <img src="<?= e(LOGO_URL) ?>" alt="LJU" class="sidebar-logo" onerror="this.style.display='none'">
        <div>
            <div class="sidebar-brand-name">LJU IT Helpdesk</div>
            <div class="sidebar-brand-sub">PT Lampung Jasa Utama</div>
        </div>
    </div>
    <nav class="sidebar-nav">
        <?php foreach ($nav as $item): ?>
            <?php
            $href   = $item[0];
            $label  = $item[1];
            $icon   = $item[2];
            if (empty($item[3])) continue; // belum diimplementasi di batch ini

            // "Tiket Saya" jangan ikut aktif saat di halaman Buat Tiket
            $active = '';
            if ($currentRel === $href) {
                $active = ' active';
            } elseif ($href === 'tickets.php' && $currentRel === 'tickets/view.php') {
                $active = ' active';
            }
            ?>
            <a href="<?= e(url($href)) ?>" class="sidebar-link<?= $active ?>">
                <span class="sidebar-icon"><?= e($icon) ?></span>
                <span><?= e($label) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
    <div class="sidebar-footer">
        <div class="sidebar-user">
            <?= avatar_html($user ?? current_user(), 32) ?>
            <div>
                <div class="sidebar-user-name"><?= e($user['name'] ?? '') ?></div>
                <div class="sidebar-user-role"><?= e($it ? 'Tim IT' : 'Karyawan') ?></div>
            </div>
        </div>
    </div>
</aside>
