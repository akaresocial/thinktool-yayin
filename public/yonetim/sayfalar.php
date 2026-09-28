<?php

declare(strict_types=1);

require __DIR__ . '/_inc/admin.php';
require_login();
$items = pages_all();
admin_header('Sayfalar', 'sayfalar');
?>
<div class="page-head"><div><h1>Sayfalar</h1><p class="muted">Yasal metinler ve basit içerik sayfaları.</p></div><div class="actions"><a class="btn btn-primary" href="/yonetim/sayfa.php?yeni=1">+ Yeni sayfa</a></div></div>
<div class="card card-flush">
  <table class="table">
    <thead><tr><th>Başlık</th><th>Alt menüde</th><th>Durum</th></tr></thead>
    <tbody>
    <?php foreach ($items as $p): ?>
      <tr class="row-link" data-href="/yonetim/sayfa.php?id=<?= $p['id'] ?>">
        <td><a href="/yonetim/sayfa.php?id=<?= $p['id'] ?>"><strong><?= e($p['title']) ?></strong></a><br><small class="muted">/<?= e($p['slug']) ?></small></td>
        <td><?= $p['showInFooter'] ? 'Evet' : '—' ?></td>
        <td><?= $p['published'] ? '<span class="pill pill-ok">Yayında</span>' : '<span class="pill pill-muted">Taslak</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php
admin_footer();
