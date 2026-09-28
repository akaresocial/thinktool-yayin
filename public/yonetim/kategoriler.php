<?php

declare(strict_types=1);

require __DIR__ . '/_inc/admin.php';
require_login();

if (is_post()) {
    csrf_verify();
    $now = now_iso();
    try {
        if (p('action') === 'add') {
            $title = p_str('title', 80);
            if (mb_strlen($title) < 2) {
                throw new UserError('Kategori adı girin.');
            }
            q('INSERT INTO categories (slug, title, description, sort_order, updated_at) VALUES (?,?,?,?,?)', [unique_slug('categories', slugify(p_str('slug', 80) ?: $title), null), $title, p_text('description', 500), (int) p('sortOrder', 100), $now]);
            flash('success', 'Kategori eklendi.');
        } elseif ((int) p('delete_id') > 0) {
            $cid = (int) p('delete_id');
            if ((int) q_val('SELECT COUNT(*) FROM products WHERE category_id = ?', [$cid]) > 0) {
                throw new UserError('Bu kategoride ürün var; önce ürünleri başka kategoriye taşıyın.');
            }
            q('DELETE FROM categories WHERE id = ?', [$cid]);
            flash('success', 'Kategori silindi.');
        } else {
            foreach ((array) ($_POST['c'] ?? []) as $cid => $c) {
                q('UPDATE categories SET title = ?, slug = ?, description = ?, sort_order = ?, updated_at = ? WHERE id = ?', [
                    str_in($c['title'] ?? '', 80), unique_slug('categories', slugify(str_in($c['slug'] ?? '', 80) ?: str_in($c['title'] ?? '', 80)), (int) $cid), str_in($c['description'] ?? '', 500), (int) ($c['sort'] ?? 100), $now, (int) $cid,
                ]);
            }
            flash('success', 'Kategoriler kaydedildi.');
        }
        content_touch();
    } catch (UserError $e) {
        flash('error', $e->getMessage());
    }
    redirect('/yonetim/kategoriler.php');
}

$cats = categories_all();
$counts = [];
foreach (q_all('SELECT category_id, COUNT(*) c FROM products GROUP BY category_id') as $r) {
    $counts[(int) $r['category_id']] = (int) $r['c'];
}
admin_header('Kategoriler', 'kategoriler');
?>
<div class="page-head"><div><h1>Kategoriler</h1><p class="muted">Ürün serileri. Sitede filtre ve menüde görünür.</p></div></div>
<form method="post" class="card card-flush">
  <?= csrf_field() ?>
  <table class="table table-edit">
    <thead><tr><th>Ad</th><th>Adres</th><th>Açıklama</th><th>Sıra</th><th>Ürün</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($cats as $c): ?>
      <tr>
        <td><input name="c[<?= $c['id'] ?>][title]" value="<?= e($c['title']) ?>"></td>
        <td><input name="c[<?= $c['id'] ?>][slug]" value="<?= e($c['slug']) ?>"></td>
        <td><input name="c[<?= $c['id'] ?>][description]" value="<?= e($c['description']) ?>"></td>
        <td><input class="w-xs" name="c[<?= $c['id'] ?>][sort]" value="<?= (int) $c['sortOrder'] ?>"></td>
        <td class="num"><?= $counts[$c['id']] ?? 0 ?></td>
        <td><button class="btn btn-ghost btn-xs danger" type="submit" name="delete_id" value="<?= $c['id'] ?>" data-confirm="Kategori silinsin mi?">Sil</button></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="form-foot"><span></span><button class="btn btn-primary" type="submit">Kaydet</button></div>
</form>
<form method="post" class="card stack narrow">
  <?= csrf_field() ?><input type="hidden" name="action" value="add">
  <header class="card-head"><h2>Yeni kategori</h2></header>
  <div class="row"><?= field('Ad', input('title', '', ['required' => true])) ?><?= field('Sıra', input('sortOrder', '100')) ?></div>
  <?= field('Açıklama', input('description', '')) ?>
  <button class="btn btn-secondary" type="submit">Ekle</button>
</form>
<?php
admin_footer();
