<?php

declare(strict_types=1);

require __DIR__ . '/_inc/admin.php';
require_login();

/* JSON uçları (görsel seçici ve yükleme) */
if (isset($_GET['json'])) {
    header('Content-Type: application/json; charset=utf-8');
    if (is_post()) {
        csrf_verify();
        try {
            if (($_GET['json'] ?? '') === 'upload') {
                $files = $_FILES['files'] ?? null;
                $out = [];
                if ($files && is_array($files['name'])) {
                    foreach ($files['name'] as $i => $name) {
                        $out[] = image_store_upload(['name' => $name, 'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i], 'size' => $files['size'][$i]], p_str('alt', 200));
                    }
                }
                echo json_encode(['ok' => true, 'items' => array_map(fn ($m) => $m + ['url' => media_url($m, 320)], $out)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }
        } catch (UserError $e) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    echo json_encode(['ok' => true, 'items' => array_map(fn ($m) => $m + ['url' => media_url($m, 320)], array_reverse(media_all()))], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (is_post()) {
    csrf_verify();
    $mid = (int) p('id');
    try {
        if (p('action') === 'delete') {
            media_delete($mid);
            flash('success', 'Görsel silindi.');
        } elseif (p('action') === 'alt') {
            q('UPDATE media SET alt = ? WHERE id = ?', [p_str('alt', 200), $mid]);
            content_touch();
            flash('success', 'Açıklama kaydedildi.');
        } elseif (p('action') === 'upload' && !empty($_FILES['files'])) {
            $f = $_FILES['files'];
            $n = 0;
            foreach ((array) $f['name'] as $i => $name) {
                image_store_upload(['name' => $name, 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]], '');
                $n++;
            }
            flash('success', "$n görsel yüklendi.");
        }
    } catch (UserError $e) {
        flash('error', $e->getMessage());
    }
    redirect('/yonetim/medya.php');
}

$items = array_reverse(media_all());
admin_header('Medya', 'medya');
?>
<div class="page-head">
  <div><h1>Medya</h1><p class="muted">Yüklenen görseller otomatik küçültülür ve sitede hızlı yüklenen boyutları oluşturulur.</p></div>
  <form class="actions" method="post" enctype="multipart/form-data">
    <?= csrf_field() ?><input type="hidden" name="action" value="upload">
    <label class="btn btn-primary">Görsel yükle<input type="file" name="files[]" accept="image/*" multiple hidden onchange="this.form.submit()"></label>
  </form>
</div>
<div class="media-grid">
  <?php foreach ($items as $m): ?>
    <figure class="media-item">
      <a href="<?= e(media_url($m)) ?>" target="_blank" rel="noopener"><img src="<?= e(media_url($m, 320)) ?>" alt="<?= e($m['alt']) ?>" loading="lazy"></a>
      <figcaption>
        <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="alt"><input type="hidden" name="id" value="<?= $m['id'] ?>"><input name="alt" value="<?= e($m['alt']) ?>" placeholder="Açıklama (alt metin)"><button class="btn btn-ghost btn-xs" type="submit">✓</button></form>
        <span class="muted small"><?= $m['width'] ?>×<?= $m['height'] ?></span>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $m['id'] ?>"><button class="btn btn-ghost btn-xs danger" type="submit" data-confirm="Görsel silinsin mi?">Sil</button></form>
      </figcaption>
    </figure>
  <?php endforeach; ?>
</div>
<?php
admin_footer();
