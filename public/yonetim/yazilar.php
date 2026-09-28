<?php

declare(strict_types=1);

require __DIR__ . '/_inc/admin.php';
require_login();
$items = posts_all();
admin_header('Blog', 'yazilar');
?>
<div class="page-head"><div><h1>Blog yazıları</h1></div><div class="actions"><a class="btn btn-primary" href="/yonetim/yazi.php?yeni=1">+ Yeni yazı</a></div></div>
<div class="card card-flush">
  <table class="table">
    <thead><tr><th>Başlık</th><th>Yayın tarihi</th><th>Durum</th></tr></thead>
    <tbody>
    <?php foreach ($items as $p): ?>
      <tr class="row-link" data-href="/yonetim/yazi.php?id=<?= $p['id'] ?>">
        <td><a href="/yonetim/yazi.php?id=<?= $p['id'] ?>"><strong><?= e($p['title']) ?></strong></a><br><small class="muted">/blog/<?= e($p['slug']) ?></small></td>
        <td><?= e(format_date($p['publishedAt'])) ?></td>
        <td><?= $p['published'] ? '<span class="pill pill-ok">Yayında</span>' : '<span class="pill pill-muted">Taslak</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php
admin_footer();
