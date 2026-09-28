<?php

declare(strict_types=1);

require __DIR__ . '/_inc/admin.php';
require_login();

$id = (int) ($_GET['id'] ?? 0);
$post = $id ? (array_values(array_filter(posts_all(), fn ($p) => $p['id'] === $id))[0] ?? null) : post_defaults();
if ($id && !$post) {
    redirect('/yonetim/yazilar.php');
}
if (is_post()) {
    csrf_verify();
    if (p('action') === 'delete' && $id) {
        q('DELETE FROM posts WHERE id = ?', [$id]);
        content_touch();
        flash('success', 'Yazı silindi.');
        redirect('/yonetim/yazilar.php');
    }
    $date = p_str('publishedAt', 10);
    $data = array_replace($post, [
        'title' => p_str('title', 200),
        'slug' => p_str('slug', 200),
        'excerpt' => p_text('excerpt', 600),
        'cover' => (int) p('cover') ?: null,
        'contentHtml' => clean_html(p_text('contentHtml', 200000)),
        'published' => p_bool('published'),
        'publishedAt' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date . 'T09:00:00Z' : ($post['publishedAt'] ?: now_iso()),
        'seo' => ['title' => p_str('seo_title', 200), 'description' => p_str('seo_description', 320), 'image' => null],
    ]);
    if (mb_strlen($data['title']) < 3) {
        flash('error', 'Başlık girin.');
        $post = $data;
    } else {
        $savedId = post_save($data, $id ?: null);
        flash('success', 'Yazı kaydedildi. Site birkaç dakika içinde güncellenir.');
        redirect("/yonetim/yazi.php?id=$savedId");
    }
}
$mediaMap = [];
foreach (media_all() as $m) {
    $mediaMap[$m['id']] = ['url' => media_url($m, 320), 'alt' => $m['alt']];
}
admin_header($id ? $post['title'] : 'Yeni yazı', 'yazilar', ['editor' => true]);
?>
<script>window.TT_MEDIA = <?= json_encode($mediaMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;</script>
<form method="post" data-serialize>
<?= csrf_field() ?>
<div class="page-head">
  <div><p class="eyebrow"><a href="/yonetim/yazilar.php">← Blog</a></p><h1><?= $id ? e($post['title']) : 'Yeni yazı' ?></h1></div>
  <div class="actions"><button class="btn btn-primary" type="submit">Kaydet</button></div>
</div>
<div class="grid-main">
  <div class="stack">
    <section class="card stack">
      <?= field('Başlık', input('title', $post['title'], ['required' => true])) ?>
      <?= field('Özet', textarea('excerpt', $post['excerpt'], 3), 'Listelerde ve arama sonuçlarında görünür.') ?>
      <input type="hidden" name="contentHtml" value="<?= e($post['contentHtml']) ?>">
      <p class="field-label">İçerik</p>
      <div data-richtext="contentHtml"></div>
    </section>
    <section class="card stack">
      <header class="card-head"><h2>Arama motoru (SEO)</h2></header>
      <?= field('Sayfa başlığı', input('seo_title', $post['seo']['title'] ?? '')) ?>
      <?= field('Açıklama', textarea('seo_description', $post['seo']['description'] ?? '', 2)) ?>
    </section>
  </div>
  <aside class="stack sticky">
    <section class="card stack">
      <?= checkbox('published', (bool) $post['published'], 'Yayında') ?>
      <?= field('Yayın tarihi', input('publishedAt', $post['publishedAt'] ? substr($post['publishedAt'], 0, 10) : date('Y-m-d'), ['type' => 'date'])) ?>
      <?= field('Adres (kısa ad)', input('slug', $post['slug'], ['placeholder' => 'Otomatik'])) ?>
      <input type="hidden" name="cover" value="<?= e($post['cover'] ?? '') ?>">
      <p class="field-label">Kapak görseli</p>
      <div data-single-media="cover"></div>
      <button class="btn btn-primary btn-block" type="submit">Kaydet</button>
    </section>
    <?php if ($id): ?><section class="card"><button class="btn btn-danger btn-block" type="submit" name="action" value="delete" formnovalidate data-confirm="Yazı silinsin mi?">Yazıyı sil</button></section><?php endif; ?>
  </aside>
</div>
</form>
<?php
admin_footer();
