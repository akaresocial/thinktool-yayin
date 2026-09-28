<?php

declare(strict_types=1);

require __DIR__ . '/_inc/admin.php';
require_login();

const MESSAGE_STATUSES = ['new' => 'Yeni', 'answered' => 'Dönüş yapıldı', 'archived' => 'Arşiv'];

if (is_post()) {
    csrf_verify();
    $mid = (int) p('id');
    if (p('action') === 'delete') {
        q('DELETE FROM contact_messages WHERE id = ?', [$mid]);
        flash('success', 'Mesaj silindi.');
    } elseif (isset(MESSAGE_STATUSES[p_str('status')])) {
        q('UPDATE contact_messages SET status = ? WHERE id = ?', [p_str('status'), $mid]);
    }
    redirect('/yonetim/mesajlar.php' . (isset($_GET['durum']) ? '?durum=' . rawurlencode((string) $_GET['durum']) : ''));
}

$filter = (string) ($_GET['durum'] ?? 'new');
$rows = $filter === 'all' ? q_all('SELECT * FROM contact_messages ORDER BY id DESC LIMIT 200') : q_all('SELECT * FROM contact_messages WHERE status = ? ORDER BY id DESC LIMIT 200', [$filter]);
admin_header('Mesajlar', 'mesajlar');
?>
<div class="page-head"><div><h1>İletişim mesajları</h1><p class="muted">Sitedeki formdan gelen mesajlar; her biri ayrıca e-postayla da iletilir.</p></div></div>
<nav class="tabs">
  <?php foreach (MESSAGE_STATUSES + ['all' => 'Tümü'] as $k => $label): ?>
    <a href="?durum=<?= $k ?>" class="<?= $filter === $k ? 'active' : '' ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</nav>
<?php if (!$rows): ?><p class="empty card">Bu listede mesaj yok.</p><?php endif; ?>
<div class="stack">
<?php foreach ($rows as $m): ?>
  <article class="card message">
    <header class="card-head">
      <div><h2><?= e($m['name']) ?> <small class="muted"><?= e($m['subject']) ?></small></h2>
      <p class="muted small"><?= e(format_date($m['created_at'], true)) ?><?= $m['product'] ? ' · ' . e($m['product']) : '' ?></p></div>
      <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $m['id'] ?>"><?= select('status', MESSAGE_STATUSES, $m['status'], ['onchange' => 'this.form.submit()']) ?></form>
    </header>
    <p class="note"><?= nl2br(e($m['message'])) ?></p>
    <p class="contact-links">
      <a class="btn btn-sm btn-secondary" href="tel:<?= e($m['phone']) ?>"><?= e($m['phone']) ?></a>
      <a class="btn btn-sm btn-whatsapp" href="<?= e(whatsapp_href($m['phone'], 'Merhaba ' . explode(' ', $m['name'])[0] . ', Thinktool Türkiye\'den yazıyoruz.')) ?>" target="_blank" rel="noopener">WhatsApp</a>
      <?php if ($m['email']): ?><a class="btn btn-sm btn-secondary" href="mailto:<?= e($m['email']) ?>?subject=<?= rawurlencode('Re: ' . $m['subject']) ?>"><?= e($m['email']) ?></a><?php endif; ?>
      <form method="post" class="inline-form push"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $m['id'] ?>"><button class="btn btn-ghost btn-xs danger" name="action" value="delete" data-confirm="Mesaj silinsin mi?">Sil</button></form>
    </p>
  </article>
<?php endforeach; ?>
</div>
<?php
admin_footer();
