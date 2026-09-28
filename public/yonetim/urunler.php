<?php

declare(strict_types=1);

require __DIR__ . '/_inc/admin.php';
require_login();

// Hızlı düzenleme: fiyat, eski fiyat, stok durumu/adedi, yayında, öne çıkan, sıra
if (is_post()) {
    csrf_verify();
    $rows = is_array($_POST['p'] ?? null) ? $_POST['p'] : [];
    $changed = 0;
    tx(function () use ($rows, &$changed): void {
        foreach ($rows as $id => $r) {
            $p = product_by_id((int) $id);
            if (!$p) {
                continue;
            }
            $price = str_replace(',', '.', trim((string) ($r['price'] ?? '')));
            $compare = str_replace(',', '.', trim((string) ($r['compare'] ?? '')));
            $qty = trim((string) ($r['qty'] ?? ''));
            $next = array_replace($p, [
                'priceUsd' => is_numeric($price) ? max(0, (float) $price) : $p['priceUsd'],
                'compareAtPriceUsd' => is_numeric($compare) && (float) $compare > 0 ? (float) $compare : null,
                'stockStatus' => array_key_exists((string) ($r['stock'] ?? ''), STOCK_STATUSES) ? (string) $r['stock'] : $p['stockStatus'],
                'stockQuantity' => $qty === '' ? null : max(0, (int) $qty),
                'published' => !empty($r['published']),
                'featured' => !empty($r['featured']),
                'sortOrder' => (int) ($r['sort'] ?? $p['sortOrder']),
            ]);
            $keys = ['priceUsd', 'compareAtPriceUsd', 'stockStatus', 'stockQuantity', 'published', 'featured', 'sortOrder'];
            if (array_intersect_key($next, array_flip($keys)) != array_intersect_key($p, array_flip($keys))) {
                product_save($next, $p['id']);
                $changed++;
            }
        }
    });
    flash('success', $changed ? "$changed ürün güncellendi. Fiyat ve stok değişiklikleri sitede hemen görünür." : 'Değişiklik yok.');
    redirect('/yonetim/urunler.php');
}

$products = products_all();
$cats = [];
foreach (categories_all() as $c) {
    $cats[$c['id']] = $c['title'];
}
$rate = rate_info();

admin_header('Ürünler', 'urunler');
?>
<div class="page-head">
  <div><h1>Ürünler</h1><p class="muted">Fiyatlar USD girilir; sitede günlük kurla TL gösterilir (bugün 1 USD = <?= e(format_rate($rate['rate'])) ?> TL).</p></div>
  <div class="actions"><a class="btn btn-primary" href="/yonetim/urun.php?yeni=1">+ Yeni ürün</a></div>
</div>

<form method="post" class="card card-flush">
  <?= csrf_field() ?>
  <div class="table-scroll">
  <table class="table table-edit">
    <thead><tr><th>Ürün</th><th>Fiyat (USD)</th><th>Eski fiyat</th><th>Stok</th><th>Adet</th><th title="Sitede yayında">Yayında</th><th title="Ana sayfada öne çıkan">Öne çıkan</th><th>Sıra</th></tr></thead>
    <tbody>
    <?php foreach ($products as $p): $img = $p['gallery'] ? media_by_id((int) $p['gallery'][0]) : null; ?>
      <tr class="<?= $p['published'] ? '' : 'is-muted' ?>">
        <td class="product-cell">
          <?php if ($img): ?><img src="<?= e(media_url($img, 320)) ?>" alt="" width="56" height="45" loading="lazy"><?php endif; ?>
          <span><a href="/yonetim/urun.php?id=<?= $p['id'] ?>"><strong><?= e($p['title']) ?></strong></a><br><small class="muted"><?= e($cats[$p['categoryId']] ?? 'Kategorisiz') ?> · ≈ <?= e(format_try(usd_to_try($p['priceUsd'], $rate))) ?></small></span>
        </td>
        <td><input class="w-num" name="p[<?= $p['id'] ?>][price]" value="<?= e(rtrim(rtrim(number_format($p['priceUsd'], 2, '.', ''), '0'), '.')) ?>" inputmode="decimal"></td>
        <td><input class="w-num" name="p[<?= $p['id'] ?>][compare]" value="<?= $p['compareAtPriceUsd'] !== null ? e(rtrim(rtrim(number_format($p['compareAtPriceUsd'], 2, '.', ''), '0'), '.')) : '' ?>" inputmode="decimal" placeholder="—"></td>
        <td><?= select("p[{$p['id']}][stock]", STOCK_STATUSES, $p['stockStatus']) ?></td>
        <td><input class="w-xs" name="p[<?= $p['id'] ?>][qty]" value="<?= $p['stockQuantity'] ?? '' ?>" inputmode="numeric" placeholder="∞"></td>
        <td class="center"><input type="checkbox" name="p[<?= $p['id'] ?>][published]" value="1" <?= $p['published'] ? 'checked' : '' ?>></td>
        <td class="center"><input type="checkbox" name="p[<?= $p['id'] ?>][featured]" value="1" <?= $p['featured'] ? 'checked' : '' ?>></td>
        <td><input class="w-xs" name="p[<?= $p['id'] ?>][sort]" value="<?= (int) $p['sortOrder'] ?>" inputmode="numeric"></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <div class="form-foot">
    <p class="muted small">Stok adedi boşsa takip edilmez. Adet girilirse her siparişte düşer, 0 olunca ürün "Stokta yok" olur.</p>
    <button class="btn btn-primary" type="submit">Değişiklikleri kaydet</button>
  </div>
</form>
<?php
admin_footer();
