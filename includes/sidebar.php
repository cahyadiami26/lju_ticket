<?php
/** Sidebar navigasi. $currentPage tersedia dari header.php. */
$it = is_it($pdo ?? null);
$nav = [
    ['dashboard.php', 'Dashboard', '▦'],
    // Batch 2 (Employee Portal)
    ['tickets.php', 'Tiket Saya', '✉', false],
    ['tickets/create.php', 'Buat Tiket', '＋', false],
    ['faq.php', 'FAQ / Knowledge Base', '?', false],
    // Batch 3 (Ticket Management) — hanya IT
    ['admin/tickets.php', 'Manajemen Tiket', '⚙', $it],
    // Batch 4 (Network Complaint)
    ['network/index.php', 'Pengaduan Jaringan', '◉', false],
    // Batch 6 (Reporting) — hanya IT
    ['reports/index.php', 'Laporan & Analitik', '▤', $it],
    // Batch 7 (MikroTik Monitoring) — hanya IT
    ['monitoring/index.php', 'Monitoring Jaringan', '◈', $it],
    // Batch 5 (Settings) — hanya IT
    ['settings/index.php', 'Pengaturan', '⚙', $it],
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
            $href = $item[0]; $label = $item[1]; $icon = $item[2];
            $visible = $item[3] ?? true;
            if ($visible === false) continue;
            $active = ($currentPage === basename($href)) ? ' active' : '';
            ?>
            <a href="<?= e($href) ?>" class="sidebar-link<?= $active ?>">
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
