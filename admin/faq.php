<?php
/**
 * CMS-lite: FAQ / Knowledge Base Management (Batch 2).
 * Tim IT dapat menambah, mengubah, mempublikasi, dan menghapus artikel.
 */
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/url.php';
require_once __DIR__ . '/../includes/auth.php';
require_it($pdo);

$pageTitle = 'Kelola FAQ';

$user = current_user();
$editId = (int)($_GET['edit'] ?? 0);
$msg = '';
$err = '';

// --- Simpan (tambah / ubah) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['save', 'delete'], true)) {
    if (!csrf_check()) {
        $err = 'Token keamanan tidak valid.';
    } elseif ($_POST['action'] === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $pdo->prepare('DELETE FROM faq_articles WHERE id = ?')->execute([$id]);
            $msg = 'Artikel berhasil dihapus.';
        } catch (Throwable $e) {
            error_log('[FAQ DELETE] ' . $e->getMessage());
            $err = 'Gagal menghapus artikel.';
        }
        $editId = 0;
    } else {
        $id = (int)($_POST['id'] ?? 0);
        $data = [
            'category_id'  => (int)($_POST['category_id'] ?? 0) ?: null,
            'title'        => trim($_POST['title'] ?? ''),
            'content'      => trim($_POST['content'] ?? ''),
            'keywords'     => trim($_POST['keywords'] ?? ''),
            'is_published' => !empty($_POST['is_published']) ? 1 : 0,
        ];
        if (mb_strlen($data['title']) < 5) $err = 'Judul minimal 5 karakter.';
        elseif (mb_strlen($data['content']) < 20) $err = 'Isi artikel minimal 20 karakter.';
        else {
            try {
                if ($id > 0) {
                    $stmt = $pdo->prepare(
                        'UPDATE faq_articles SET category_id = ?, title = ?, content = ?, keywords = ?, is_published = ? WHERE id = ?'
                    );
                    $stmt->execute([$data['category_id'], $data['title'], $data['content'], $data['keywords'], $data['is_published'], $id]);
                    $msg = 'Artikel berhasil diperbarui.';
                } else {
                    $stmt = $pdo->prepare(
                        'INSERT INTO faq_articles (category_id, title, content, keywords, is_published) VALUES (?, ?, ?, ?, ?)'
                    );
                    $stmt->execute([$data['category_id'], $data['title'], $data['content'], $data['keywords'], $data['is_published']]);
                    $editId = (int)$pdo->lastInsertId();
                    $msg = 'Artikel baru berhasil ditambahkan.';
                }
            } catch (Throwable $e) {
                error_log('[FAQ SAVE] ' . $e->getMessage());
                $err = 'Gagal menyimpan artikel.';
            }
        }
    }
}

$categories = [];
$articles = [];
$editing = null;
try {
    $categories = $pdo->query('SELECT * FROM categories ORDER BY sort_order')->fetchAll();
    $articles = $pdo->query(
        'SELECT f.*, c.name AS category_name FROM faq_articles f
         LEFT JOIN categories c ON c.id = f.category_id
         ORDER BY f.updated_at DESC'
    )->fetchAll();
    if ($editId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM faq_articles WHERE id = ? LIMIT 1');
        $stmt->execute([$editId]);
        $editing = $stmt->fetch() ?: null;
    }
} catch (Throwable $e) {
    error_log('[FAQ ADMIN] ' . $e->getMessage());
}
$form = $editing ?: ['id' => 0, 'category_id' => '', 'title' => '', 'content' => '', 'keywords' => '', 'is_published' => 1];

// Header dirender setelah semua POST selesai (agar redirect berjalan)
require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endif; ?>

<div class="card">
    <h3 class="card-title"><?= $editing ? 'Ubah Artikel' : 'Tambah Artikel Baru' ?></h3>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int)$form['id'] ?>">

        <div class="form-group">
            <label for="title">Judul *</label>
            <input type="text" id="title" name="title" class="form-control" required maxlength="255" value="<?= e($form['title']) ?>">
        </div>

        <div class="form-group">
            <label for="category_id">Kategori</label>
            <select id="category_id" name="category_id" class="form-control">
                <option value="0">— Umum —</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= (int)$c['id'] ?>"<?= (int)$form['category_id'] === (int)$c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="content">Isi Panduan *</label>
            <textarea id="content" name="content" class="form-control" style="min-height:200px" required placeholder="Tulis langkah-langkah penyelesaian..."><?= e($form['content']) ?></textarea>
        </div>

        <div class="form-group">
            <label for="keywords">Kata Kunci (pisahkan dengan koma)</label>
            <input type="text" id="keywords" name="keywords" class="form-control" maxlength="255" value="<?= e($form['keywords']) ?>" placeholder="wifi, internet, password">
        </div>

        <label style="display:flex;gap:8px;align-items:center;margin-bottom:14px">
            <input type="checkbox" name="is_published" value="1"<?= !empty($form['is_published']) ? ' checked' : '' ?>> Publikasikan ke karyawan
        </label>

        <div class="quick-actions">
            <button type="submit" class="btn btn-primary"><?= $editing ? 'Simpan Perubahan' : 'Tambah Artikel' ?></button>
            <?php if ($editing): ?><a href="<?= e(url('admin/faq.php')) ?>" class="btn btn-ghost">Batal</a><?php endif; ?>
        </div>
    </form>
</div>

<div class="card">
    <h3 class="card-title">Daftar Artikel (<?= count($articles) ?>)</h3>
    <?php if (empty($articles)): ?>
        <p class="text-muted">Belum ada artikel.</p>
    <?php else: ?>
        <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Judul</th><th>Kategori</th><th>Status</th><th>Dilihat</th><th>Diperbarui</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($articles as $a): ?>
                <tr>
                    <td><a href="<?= e(url('faq.php')) ?>?id=<?= (int)$a['id'] ?>" target="_blank" rel="noopener"><?= e($a['title']) ?></a></td>
                    <td><?= e($a['category_name'] ?? 'Umum') ?></td>
                    <td><?= !empty($a['is_published']) ? '<span class="badge badge-resolved">Published</span>' : '<span class="badge badge-closed">Draft</span>' ?></td>
                    <td class="text-muted"><?= (int)$a['views'] ?></td>
                    <td class="text-muted"><?= e(time_ago($a['updated_at'])) ?></td>
                    <td style="white-space:nowrap">
                        <a href="<?= e(url('admin/faq.php')) ?>?edit=<?= (int)$a['id'] ?>" class="btn btn-sm btn-ghost">Ubah</a>
                        <form method="post" style="display:inline" onsubmit="return confirm('Hapus artikel ini?')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-ghost">Hapus</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
