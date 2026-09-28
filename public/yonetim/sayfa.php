<?php

declare(strict_types=1);

require __DIR__ . '/_inc/admin.php';
require_login();

$id = (int) ($_GET['id'] ?? 0);
$page = $id ? (array_values(array_filter(pages_all(), fn ($p) => $p['id'] === $id))[0] ?? null) : page_defaults();
if ($id && !$page) {
    redirect('/yonetim/sayfalar.php');
}
if (is_post()) {
    csrf_verify();
    if (p('action') === 'delete' && $id) {
        q('DELETE FROM pages WHERE id = ?', [$id]);
        content_touch();
        flash('success', 'Sayfa silindi.');
        redirect('/yonetim/sayfalar.php');
    }
    $data = array_replace($page, [
        'title' => p_str('title', 200),
        'slug' => p_str('slug', 200),
        'intro' => p_text('intro', 600),
        'contentHtml' => clean_html(p_text('contentHtml', 300000)),
        'published' => p_bool('published'),
        'showInFooter' => p_bool('showInFooter'),
        'sortOrder' => (int) p('sortOrder', 100),
        'seo' => ['title' => p_str('seo_title', 200), 'description' => p_str('seo_description', 320), 'image' => null],
    ]);
    if (mb_strlen($data['title']) < 3) {
        flash('error', 'Başlık girin.');
        $page = $data;
    } else {
        $savedId = page_save($data, $id ?: null);
        flash('success', 'Sayfa kaydedildi. Site birkaç dakika içinde güncellenir.');
        redirect("/yonetim/sayfa.php?id=$savedId");
    }
}
admin_header($id ? $page['title'] : 'Yeni sayfa', 'sayfalar', ['editor' => true]);
?>
<form method="post" data-serialize>
<?= csrf_field() ?>
<div class="page-head">
  <div><p class="eyebrow"><a href="/yonetim/sayfalar.php">← Sayfalar</a></p><h1><?= $id ? e($page['title']) : 'Yeni sayfa' ?></h1></div>
  <div class="actions"><button class="btn btn-primary" type="submit">Kaydet</button></div>
</div>
<div class="grid-main">
  <div class="stack">
    <section class="card stack">
      <?= field('Başlık', input('title', $page['title'], ['required' => true])) ?>
      <?= field('Giriş', textarea('intro', $page['intro'], 2)) ?>
      <input type="hidden" name="contentHtml" value="<?= e($page['contentHtml']) ?>">
      <p class="field-label">İçerik</p>
      <p class="field-hint">Metinde <code>{{UNVAN}}</code>, <code>{{ADRES}}</code>, <code>{{TELEFON}}</code>, <code>{{EPOSTA}}</code>, <code>{{VERGI}}</code>, <code>{{MARKA}}</code> yazarsanız Ayarlar'daki bilgilerle otomatik doldurulur.</p>
      <div data-richtext="contentHtml"></div>
    </section>
    <section class="card stack">
      <header class="card-head"><h2>Arama motoru (SEO)</h2></header>
      <?= field('Sayfa başlığı', input('seo_title', $page['seo']['title'] ?? '')) ?>
      <?= field('Açıklama', textarea('seo_description', $page['seo']['description'] ?? '', 2)) ?>
    </section>
  </div>
  <aside class="stack sticky">
    <section class="card stack">
      <?= checkbox('published', (bool) $page['published'], 'Yayında') ?>
      <?= checkbox('showInFooter', (bool) $page['showInFooter'], 'Alt menüde göster') ?>
      <?= field('Sıralama', input('sortOrder', $page['sortOrder'])) ?>
      <?= field('Adres (kısa ad)', input('slug', $page['slug'], ['placeholder' => 'Otomatik'])) ?>
      <button class="btn btn-primary btn-block" type="submit">Kaydet</button>
    </section>
    <?php if ($id): ?><section class="card"><button class="btn btn-danger btn-block" type="submit" name="action" value="delete" formnovalidate data-confirm="Sayfa silinsin mi?">Sayfayı sil</button></section><?php endif; ?>
  </aside>
</div>
</form>
<?php
admin_footer();
