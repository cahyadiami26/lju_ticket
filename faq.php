<?php
/**
 * FAQ / Knowledge Base (Batch 2).
 * ?id= untuk detail artikel, ?q= untuk pencarian.
 */
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/url.php';

$pageTitle = 'FAQ / Knowledge Base';
require_once __DIR__ . '/includes/header.php';

$articleId = (int)($_GET['id'] ?? 0);
$search = trim($_GET['q'] ?? '');

// --- Detail artikel ---
if ($articleId > 0) {
    $article = null;
    try {
        $stmt = $pdo->prepare(
            'SELECT f.*, c.name AS category_name FROM faq_articles f
             LEFT JOIN categories c ON c.id = f.category_id
             WHERE f.id = ? AND f.is_published = 1 LIMIT 1'
        );
        $stmt->execute([$articleId]);
        $article = $stmt->fetch() ?: null;
        if ($article) {
            $pdo->prepare('UPDATE faq_articles SET views = views + 1 WHERE id = ?')->execute([$articleId]);
        }
    } catch (Throwable $e) {
        error_log('[FAQ DETAIL] ' . $e->getMessage());
    }
    if (!$article) {
        echo '<div class="card"><p>Artikel tidak ditemukan.</p><a href="' . e(url('faq.php')) . '" class="btn btn-ghost">Kembali ke FAQ</a></div>';
        require_once __DIR__ . '/includes/footer.php';
        exit;
    }
    ?>
    <div class="card" style="max-width:760px">
        <div class="text-muted text-sm"><?= e($article['category_name'] ?? 'Umum') ?> · <?= (int)$article['views'] ?> dilihat</div>
        <h2 class="mt-0"><?= e($article['title']) ?></h2>
        <div style="white-space:pre-wrap"><?= e($article['content']) ?></div>
        <div style="margin-top:16px"><a href="<?= e(url('faq.php')) ?>" class="btn btn-ghost">← Kembali ke FAQ</a></div>
    </div>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// --- Daftar artikel ---
$articles = [];
try {
    if ($search !== '') {
        $stmt = $pdo->prepare(
            'SELECT f.*, c.name AS category_name FROM faq_articles f
             LEFT JOIN categories c ON c.id = f.category_id
             WHERE f.is_published = 1 AND (f.title LIKE ? OR f.content LIKE ? OR f.keywords LIKE ?)
             ORDER BY f.views DESC, f.created_at DESC LIMIT 50'
        );
        $like = '%' . $search . '%';
        $stmt->execute([$like, $like, $like]);
    } else {
        $stmt = $pdo->query(
            'SELECT f.*, c.name AS category_name FROM faq_articles f
             LEFT JOIN categories c ON c.id = f.category_id
             WHERE f.is_published = 1
             ORDER BY f.views DESC, f.created_at DESC LIMIT 50'
        );
    }
    $articles = $stmt->fetchAll();
} catch (Throwable $e) {
    error_log('[FAQ LIST] ' . $e->getMessage());
}
?>

<div class="card">
    <h3 class="card-title">Panduan Mengatasi Masalah Umum</h3>
    <form method="get" style="display:flex;gap:8px;margin-bottom:16px">
        <input type="text" name="q" class="form-control" style="max-width:360px" placeholder="Cari panduan... (misal: wifi, printer, password)" value="<?= e($search) ?>">
        <button type="submit" class="btn btn-primary">Cari</button>
    </form>
    <?php if (empty($articles)): ?>
        <div class="empty-state">
            <div class="empty-icon">?</div>
            <p>Tidak ada panduan yang cocok. Jika masalah Anda belum teratasi, silakan buat tiket baru.</p>
            <a href="<?= e(url('tickets/create.php')) ?>" class="btn btn-primary">Buat Tiket</a>
        </div>
    <?php else: ?>
        <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Judul</th><th>Kategori</th><th>Dilihat</th></tr></thead>
            <tbody>
            <?php foreach ($articles as $a): ?>
                <tr>
                    <td><a href="<?= e(url('faq.php')) ?>?id=<?= (int)$a['id'] ?>"><?= e($a['title']) ?></a></td>
                    <td><?= e($a['category_name'] ?? '-') ?></td>
                    <td class="text-muted"><?= (int)$a['views'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
