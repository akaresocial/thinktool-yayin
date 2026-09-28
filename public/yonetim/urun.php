<?php

declare(strict_types=1);

require __DIR__ . '/_inc/admin.php';
require_login();

$id = (int) ($_GET['id'] ?? 0);
$product = $id ? product_by_id($id) : product_defaults();
if ($id && !$product) {
    flash('error', 'Ürün bulunamadı.');
    redirect('/yonetim/urunler.php');
}

if (is_post()) {
    csrf_verify();
    if (p('action') === 'delete' && $id) {
        q('DELETE FROM products WHERE id = ?', [$id]);
        content_touch();
        flash('success', 'Ürün silindi. Site birkaç dakika içinde güncellenir.');
        redirect('/yonetim/urunler.php');
    }
    $cleanList = fn (array $rows, array $keys) => array_values(array_filter(array_map(
        fn ($r) => array_map(fn ($k) => str_in($r[$k] ?? '', 2000), array_combine($keys, $keys)),
        $rows,
    ), fn ($r) => implode('', $r) !== ''));
    $compare = [];
    foreach (['screen', 'memory', 'battery', 'brandCoverage', 'softwareUpdates', 'os'] as $k) {
        $compare[$k] = p_str("compare_$k", 120);
    }
    $compare['maintenanceResets'] = p_num('compare_maintenanceResets') === null ? null : (int) p_num('compare_maintenanceResets');
    $data = array_replace($product, [
        'title' => p_str('title', 200),
        'modelName' => p_str('modelName', 80),
        'slug' => p_str('slug', 200),
        'badge' => p_str('badge', 40),
        'tagline' => p_str('tagline', 200),
        'summary' => p_text('summary', 1000),
        'categoryId' => (int) p('categoryId') ?: null,
        'published' => p_bool('published'),
        'featured' => p_bool('featured'),
        'sortOrder' => (int) p('sortOrder', 100),
        'priceUsd' => max(0, p_num('priceUsd') ?? 0),
        'compareAtPriceUsd' => (p_num('compareAtPriceUsd') ?? 0) > 0 ? p_num('compareAtPriceUsd') : null,
        'stockStatus' => array_key_exists(p_str('stockStatus'), STOCK_STATUSES) ? p_str('stockStatus') : 'in_stock',
        'stockQuantity' => p_num('stockQuantity') === null ? null : max(0, (int) p_num('stockQuantity')),
        'sku' => p_str('sku', 60),
        'gallery' => array_values(array_filter(array_map('intval', p_json('gallery')))),
        'vehicleTypes' => array_values(array_intersect(array_keys(VEHICLE_TYPES), (array) ($_POST['vehicleTypes'] ?? []))),
        'capabilities' => array_values(array_intersect(array_keys(CAPABILITIES), (array) ($_POST['capabilities'] ?? []))),
        'keyFigures' => array_slice($cleanList(p_json('keyFigures'), ['value', 'label']), 0, 4),
        'highlights' => array_values(array_filter(array_map(fn ($r) => str_in($r['text'] ?? '', 300), p_json('highlights')))),
        'specs' => $cleanList(p_json('specs'), ['label', 'value']),
        'compare' => $compare,
        'boxContents' => p_text('boxContents', 3000),
        'sections' => sanitize_sections(p_json('sections')),
        'descriptionHtml' => clean_html(p_text('descriptionHtml', 100000)),
        'faqs' => $cleanList(p_json('faqs'), ['question', 'answer']),
        'seo' => ['title' => p_str('seo_title', 200), 'description' => p_str('seo_description', 320), 'image' => $product['seo']['image'] ?? null],
    ]);
    $errors = [];
    if (mb_strlen($data['title']) < 3) {
        $errors[] = 'Ürün adı girin.';
    }
    if ($data['modelName'] === '') {
        $errors[] = 'Model adı girin (ör. "Master X2").';
    }
    if ($data['priceUsd'] <= 0) {
        $errors[] = 'Satış fiyatı (USD) girin.';
    }
    if ($errors) {
        foreach ($errors as $err) {
            flash('error', $err);
        }
        $product = $data;
    } else {
        $savedId = product_save($data, $id ?: null);
        flash('success', ($id ? 'Ürün kaydedildi.' : 'Ürün oluşturuldu.') . ' Fiyat ve stok hemen, içerik değişiklikleri birkaç dakika içinde sitede görünür.');
        redirect("/yonetim/urun.php?id=$savedId");
    }
}

/** Tanıtım bölümlerini doğrular (yalnızca bilinen blok türleri ve alanları). */
function sanitize_sections(array $blocks): array
{
    $out = [];
    foreach ($blocks as $b) {
        $t = $b['type'] ?? '';
        $img = fn ($v) => is_numeric($v) && (int) $v > 0 ? (int) $v : null;
        $out[] = match ($t) {
            'feature' => ['type' => 'feature', 'eyebrow' => str_in($b['eyebrow'] ?? '', 80), 'heading' => str_in($b['heading'] ?? '', 200), 'body' => str_in($b['body'] ?? '', 5000), 'bullets' => array_values(array_map(fn ($x) => ['title' => str_in($x['title'] ?? '', 120), 'text' => str_in($x['text'] ?? '', 600)], (array) ($b['bullets'] ?? []))), 'image' => $img($b['image'] ?? null), 'layout' => in_array($b['layout'] ?? '', ['image-right', 'image-left', 'image-below'], true) ? $b['layout'] : 'image-right'],
            'cards' => ['type' => 'cards', 'eyebrow' => str_in($b['eyebrow'] ?? '', 80), 'heading' => str_in($b['heading'] ?? '', 200), 'intro' => str_in($b['intro'] ?? '', 1000), 'items' => array_values(array_map(fn ($x) => ['title' => str_in($x['title'] ?? '', 120), 'text' => str_in($x['text'] ?? '', 600), 'image' => $img($x['image'] ?? null)], (array) ($b['items'] ?? [])))],
            'gallery' => ['type' => 'gallery', 'heading' => str_in($b['heading'] ?? '', 200), 'images' => array_values(array_filter(array_map($img, (array) ($b['images'] ?? []))))],
            'video' => ['type' => 'video', 'heading' => str_in($b['heading'] ?? '', 200), 'youtubeId' => preg_replace('/[^A-Za-z0-9_\-]/', '', youtube_id((string) ($b['youtubeId'] ?? '')))],
            'callout' => ['type' => 'callout', 'text' => str_in($b['text'] ?? '', 1000), 'tone' => ($b['tone'] ?? '') === 'warning' ? 'warning' : 'info'],
            default => null,
        };
    }
    return array_values(array_filter($out));
}

/** YouTube adresinden video kimliği ("youtube.com/watch?v=XXXX" veya doğrudan kimlik). */
function youtube_id(string $v): string
{
    if (preg_match('~(?:v=|youtu\.be/|embed/|shorts/)([A-Za-z0-9_\-]{6,})~', $v, $m)) {
        return $m[1];
    }
    return $v;
}

$categories = ['' => '— Kategori seçin —'];
foreach (categories_all() as $c) {
    $categories[$c['id']] = $c['title'];
}
$mediaMap = [];
foreach (media_all() as $m) {
    $mediaMap[$m['id']] = ['url' => media_url($m, 320), 'alt' => $m['alt']];
}
$p = $product;
$json = fn ($v) => e(json_encode($v, JSON_UNESCAPED_UNICODE));

admin_header($id ? $p['title'] : 'Yeni ürün', 'urunler', ['editor' => true]);
?>
<script>window.TT_MEDIA = <?= json_encode($mediaMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;</script>
<form method="post" class="product-form" data-serialize>
<?= csrf_field() ?>
<div class="page-head">
  <div>
    <p class="eyebrow"><a href="/yonetim/urunler.php">← Ürünler</a></p>
    <h1><?= $id ? e($p['title']) : 'Yeni ürün' ?></h1>
    <?php if ($id): ?><p class="muted">Sitede: <a href="/urun/<?= e($p['slug']) ?>" target="_blank" rel="noopener">/urun/<?= e($p['slug']) ?> ↗</a></p><?php endif; ?>
  </div>
  <div class="actions"><button class="btn btn-primary" type="submit">Kaydet</button></div>
</div>

<div class="grid-main">
  <div class="stack">
    <section class="card stack">
      <header class="card-head"><h2>Genel</h2></header>
      <?= field('Ürün adı', input('title', $p['title'], ['required' => true]), 'Ör. "Thinktool Master X2 Arıza Tespit Cihazı"') ?>
      <div class="row">
        <?= field('Model adı', input('modelName', $p['modelName'], ['required' => true]), 'Kartlarda ve karşılaştırmada: "Master X2"') ?>
        <?= field('Etiket', input('badge', $p['badge']), 'Ör. "Yeni", "Çok satan" (isteğe bağlı)') ?>
      </div>
      <?= field('Kısa açıklama', input('tagline', $p['tagline']), 'Ürün adının altında görünen tek satır.') ?>
      <?= field('Özet', textarea('summary', $p['summary'], 3), 'Ürün kartında ve satın alma kutusunda görünür.') ?>
    </section>

    <section class="card stack">
      <header class="card-head"><h2>Görseller</h2><span class="muted small">İlk görsel ana görseldir. Beyaz arka planlı görseller önerilir.</span></header>
      <input type="hidden" name="gallery" value="<?= $json($p['gallery']) ?>">
      <div data-gallery="gallery"></div>
    </section>

    <section class="card stack">
      <header class="card-head"><h2>Öne çıkan değerler</h2><span class="muted small">Kartta en fazla 4 satır (ör. 10.1" / Ekran)</span></header>
      <input type="hidden" name="keyFigures" value="<?= $json($p['keyFigures']) ?>">
      <div data-repeater="keyFigures" data-max="4" data-fields='[{"name":"value","label":"Değer","width":"35%"},{"name":"label","label":"Açıklama"}]'></div>
    </section>

    <section class="card stack">
      <header class="card-head"><h2>Araç tipleri ve yetenekler</h2><span class="muted small">Filtreleme ve karşılaştırmada kullanılır</span></header>
      <div class="checks"><?php foreach (VEHICLE_TYPES as $k => $label): ?><?= checkbox_value('vehicleTypes[]', $k, in_array($k, $p['vehicleTypes'], true), $label) ?><?php endforeach; ?></div>
      <div class="checks"><?php foreach (CAPABILITIES as $k => $label): ?><?= checkbox_value('capabilities[]', $k, in_array($k, $p['capabilities'], true), $label) ?><?php endforeach; ?></div>
    </section>

    <section class="card stack">
      <header class="card-head"><h2>Karşılaştırma bilgileri</h2><span class="muted small">Bilinmeyenleri boş bırakın</span></header>
      <div class="row">
        <?= field('Ekran', input('compare_screen', $p['compare']['screen'] ?? '')) ?>
        <?= field('Bellek', input('compare_memory', $p['compare']['memory'] ?? '')) ?>
        <?= field('Batarya', input('compare_battery', $p['compare']['battery'] ?? '')) ?>
      </div>
      <div class="row">
        <?= field('Bakım sıfırlama (adet)', input('compare_maintenanceResets', $p['compare']['maintenanceResets'] ?? '', ['inputmode' => 'numeric'])) ?>
        <?= field('Marka kapsamı', input('compare_brandCoverage', $p['compare']['brandCoverage'] ?? '')) ?>
        <?= field('Yazılım güncelleme', input('compare_softwareUpdates', $p['compare']['softwareUpdates'] ?? '')) ?>
        <?= field('İşletim sistemi', input('compare_os', $p['compare']['os'] ?? '')) ?>
      </div>
    </section>

    <section class="card stack">
      <header class="card-head"><h2>Teknik özellikler</h2></header>
      <input type="hidden" name="specs" value="<?= $json($p['specs']) ?>">
      <div data-repeater="specs" data-fields='[{"name":"label","label":"Özellik","width":"40%"},{"name":"value","label":"Değer"}]'></div>
      <?= field('Kutu içeriği', textarea('boxContents', $p['boxContents'], 4), 'Her satıra bir parça.') ?>
      <input type="hidden" name="highlights" value="<?= $json(array_map(fn ($t) => ['text' => $t], $p['highlights'])) ?>">
      <p class="field-label">Öne çıkanlar (madde işaretleri)</p>
      <div data-repeater="highlights" data-fields='[{"name":"text","label":"Madde"}]'></div>
    </section>

    <section class="card stack">
      <header class="card-head"><h2>Açıklama</h2></header>
      <input type="hidden" name="descriptionHtml" value="<?= e($p['descriptionHtml']) ?>">
      <div data-richtext="descriptionHtml"></div>
    </section>

    <section class="card stack">
      <header class="card-head"><h2>Tanıtım bölümleri</h2><span class="muted small">Ürün sayfasında sırayla görünen görsel/metin bölümleri</span></header>
      <input type="hidden" name="sections" value="<?= $json($p['sections']) ?>">
      <div data-sections="sections"></div>
    </section>

    <section class="card stack">
      <header class="card-head"><h2>Sık sorulan sorular</h2><span class="muted small">Boş bırakılırsa genel sorular gösterilir</span></header>
      <input type="hidden" name="faqs" value="<?= $json($p['faqs']) ?>">
      <div data-repeater="faqs" data-fields='[{"name":"question","label":"Soru"},{"name":"answer","label":"Cevap","type":"textarea"}]'></div>
    </section>

    <section class="card stack">
      <header class="card-head"><h2>Arama motoru (SEO)</h2></header>
      <?= field('Sayfa başlığı', input('seo_title', $p['seo']['title'] ?? ''), 'Boşsa ürün adı kullanılır.') ?>
      <?= field('Açıklama', textarea('seo_description', $p['seo']['description'] ?? '', 2), '150–160 karakter önerilir. Boşsa özet kullanılır.') ?>
    </section>
  </div>

  <aside class="stack sticky">
    <section class="card stack">
      <header class="card-head"><h2>Fiyat ve stok</h2></header>
      <?= field('Satış fiyatı (USD)', input('priceUsd', $p['priceUsd'] ?: '', ['inputmode' => 'decimal', 'required' => true]), 'Sitede günlük kurla TL gösterilir: ≈ <strong data-tl-preview>' . e(format_try(usd_to_try((float) $p['priceUsd'], rate_info()))) . '</strong>') ?>
      <?= field('Eski fiyat (USD)', input('compareAtPriceUsd', $p['compareAtPriceUsd'] ?? '', ['inputmode' => 'decimal']), 'Doluysa üstü çizili gösterilir.') ?>
      <?= field('Stok durumu', select('stockStatus', STOCK_STATUSES, $p['stockStatus'])) ?>
      <?= field('Stok adedi', input('stockQuantity', $p['stockQuantity'] ?? '', ['inputmode' => 'numeric', 'placeholder' => 'Takip yok']), 'Doluysa her ödenen siparişte düşer.') ?>
      <?= field('Stok kodu (SKU)', input('sku', $p['sku'])) ?>
    </section>
    <section class="card stack">
      <header class="card-head"><h2>Yayın</h2></header>
      <?= checkbox('published', (bool) $p['published'], 'Sitede yayında') ?>
      <?= checkbox('featured', (bool) $p['featured'], 'Ana sayfada öne çıkar') ?>
      <?= field('Kategori', select('categoryId', $categories, $p['categoryId'] ?? '')) ?>
      <?= field('Sıralama', input('sortOrder', $p['sortOrder'], ['inputmode' => 'numeric']), 'Küçük sayı önce listelenir.') ?>
      <?= field('Adres (kısa ad)', input('slug', $p['slug'], ['placeholder' => 'Otomatik']), $id ? 'Yayındaki ürünlerde değiştirmeyin (Google sıralaması).' : 'Boş bırakılırsa ürün adından oluşturulur.') ?>
      <button class="btn btn-primary btn-block" type="submit">Kaydet</button>
    </section>
    <?php if ($id): ?>
    <section class="card">
      <button class="btn btn-danger btn-block" type="submit" name="action" value="delete" formnovalidate data-confirm="Ürün kalıcı olarak silinsin mi? (Satışı durdurmak için 'Sitede yayında' kutusunu kaldırmanız yeterli.)">Ürünü sil</button>
    </section>
    <?php endif; ?>
  </aside>
</div>
</form>
<script>window.TT_RATE = <?= json_encode(rate_info()) ?>;</script>
<?php
admin_footer();
