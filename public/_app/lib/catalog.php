<?php

declare(strict_types=1);

const VEHICLE_TYPES = [
    'binek' => 'Binek araç',
    'hafif-ticari' => 'Hafif ticari',
    'agir-vasita' => 'Ağır vasıta',
    'elektrikli-hibrit' => 'Elektrikli & hibrit',
];

const CAPABILITIES = [
    'tam-sistem' => 'Tam sistem teşhis',
    'online-programlama' => 'Online programlama',
    'ecu-kodlama' => 'ECU kodlama',
    'cift-yonlu' => 'Çift yönlü kontrol',
    'adas' => 'ADAS kalibrasyonu',
    'bakim-sifirlama' => 'Bakım sıfırlama',
    'ai-teshis' => 'Yapay zekâ destekli teşhis',
    'doip-canfd' => 'DoIP / CAN FD',
    'modul' => 'Modüler aksesuar desteği',
    'tpms' => 'TPMS (lastik basıncı)',
    'uzaktan-destek' => 'Uzaktan teknik destek',
    'ev-batarya' => 'EV batarya teşhisi',
];

const STOCK_STATUSES = [
    'in_stock' => 'Stokta',
    'out_of_stock' => 'Stokta yok',
    'on_request' => 'Sipariş üzerine',
];

/* ───────────────────────── Kur ───────────────────────── */

/** Hiç kur alınamamışsa kullanılacak son çare (panelden "Elle kur" girilmesi önerilir). */
const FALLBACK_RATE = 49.0;

/**
 * Geçerli kur: TCMB (efektif/döviz satış) ya da elle girilen kur + kâr payı.
 * Kur her sabah 10:00'da TCMB'den alınıp kaydedildiği için gün boyu sabittir.
 */
function rate_info(?array $s = null): array
{
    $s ??= settings();
    $roundTo = (int) ($s['roundTo'] ?? 1) ?: 1;
    $markup = 1 + ((float) ($s['markupPercent'] ?? 0)) / 100;
    $manual = (float) ($s['manualRate'] ?? 0);
    if (($s['rateSource'] ?? '') === 'manual' && $manual > 1) {
        return ['rate' => $manual * $markup, 'date' => 'elle', 'source' => 'manual', 'roundTo' => $roundTo];
    }
    $banknote = ($s['rateSource'] ?? '') === 'tcmb_banknote_selling';
    $tcmb = (float) ($banknote ? ($s['tcmbBanknoteSelling'] ?? 0) : ($s['tcmbForexSelling'] ?? 0));
    if ($tcmb > 1) {
        return [
            'rate' => $tcmb * $markup,
            'date' => (string) ($s['tcmbDate'] ?? ''),
            'source' => $banknote ? 'tcmb_banknote_selling' : 'tcmb_forex_selling',
            'roundTo' => $roundTo,
        ];
    }
    return ['rate' => ($manual > 1 ? $manual : FALLBACK_RATE) * $markup, 'date' => 'yedek', 'source' => $manual > 1 ? 'manual' : 'fallback', 'roundTo' => $roundTo];
}

/** USD fiyatı TL'ye çevirir ve ayardaki yuvarlamayı uygular (sitedeki hesapla aynı). */
function usd_to_try(float $usd, array $rate): float
{
    $step = max(1, (int) ($rate['roundTo'] ?? 1));
    return round(($usd * (float) $rate['rate']) / $step) * $step;
}

/* ───────────────────────── Medya ───────────────────────── */

function media_public(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'file' => $row['file'],
        'alt' => $row['alt'],
        'width' => (int) $row['width'],
        'height' => (int) $row['height'],
        'mime' => $row['mime'],
        'variants' => json_decode($row['variants'], true) ?: [],
    ];
}

function media_all(): array
{
    return array_map('media_public', q_all('SELECT * FROM media ORDER BY id'));
}

function media_by_id(int $id): ?array
{
    $row = q_one('SELECT * FROM media WHERE id = ?', [$id]);
    return $row ? media_public($row) : null;
}

function media_url(array $m, ?int $width = null): string
{
    if ($width) {
        $best = null;
        foreach ($m['variants'] as $w) {
            if ($w >= $width && ($best === null || $w < $best)) {
                $best = $w;
            }
        }
        if ($best !== null) {
            return '/uploads/' . preg_replace('/\.[a-z0-9]+$/i', '', $m['file']) . "-$best.webp";
        }
    }
    return '/uploads/' . $m['file'];
}

/* ───────────────────────── Kategoriler ───────────────────────── */

function categories_all(): array
{
    return array_map(fn ($c) => [
        'id' => (int) $c['id'],
        'slug' => $c['slug'],
        'title' => $c['title'],
        'description' => $c['description'],
        'sortOrder' => (int) $c['sort_order'],
    ], q_all('SELECT * FROM categories ORDER BY sort_order, id'));
}

/* ───────────────────────── Ürünler ───────────────────────── */

/** Ürünün tüm alanlarının varsayılanları (içerik biçimiyle birebir). */
function product_defaults(): array
{
    return [
        'id' => 0,
        'slug' => '',
        'title' => '',
        'modelName' => '',
        'badge' => '',
        'tagline' => '',
        'summary' => '',
        'gallery' => [],
        'priceUsd' => 0,
        'compareAtPriceUsd' => null,
        'stockStatus' => 'in_stock',
        'stockQuantity' => null,
        'sku' => '',
        'vehicleTypes' => [],
        'capabilities' => [],
        'keyFigures' => [],
        'highlights' => [],
        'specs' => [],
        'compare' => ['screen' => '', 'memory' => '', 'battery' => '', 'maintenanceResets' => null, 'brandCoverage' => '', 'softwareUpdates' => '', 'os' => ''],
        'boxContents' => '',
        'sections' => [],
        'descriptionHtml' => '',
        'faqs' => [],
        'seo' => ['title' => '', 'description' => '', 'image' => null],
        'published' => true,
        'featured' => false,
        'categoryId' => null,
        'sortOrder' => 100,
        'legacyId' => null,
        'createdAt' => '',
        'updatedAt' => '',
    ];
}

/** Ayrı sütunlarda tutulan alanlar (listeleme/filtreleme için); geri kalanı "data" JSON'undadır. */
const PRODUCT_COLUMNS = [
    'priceUsd' => 'price_usd',
    'compareAtPriceUsd' => 'compare_at_usd',
    'stockStatus' => 'stock_status',
    'stockQuantity' => 'stock_quantity',
    'published' => 'published',
    'featured' => 'featured',
    'categoryId' => 'category_id',
    'sortOrder' => 'sort_order',
];

function product_from_row(array $row): array
{
    $data = json_decode($row['data'], true) ?: [];
    $p = array_replace(product_defaults(), $data);
    $p['id'] = (int) $row['id'];
    $p['slug'] = $row['slug'];
    $p['title'] = $row['title'];
    $p['priceUsd'] = (float) $row['price_usd'];
    $p['compareAtPriceUsd'] = $row['compare_at_usd'] === null ? null : (float) $row['compare_at_usd'];
    $p['stockStatus'] = $row['stock_status'];
    $p['stockQuantity'] = $row['stock_quantity'] === null ? null : (int) $row['stock_quantity'];
    $p['published'] = (bool) $row['published'];
    $p['featured'] = (bool) $row['featured'];
    $p['categoryId'] = $row['category_id'] === null ? null : (int) $row['category_id'];
    $p['sortOrder'] = (int) $row['sort_order'];
    $p['createdAt'] = $row['created_at'];
    $p['updatedAt'] = $row['updated_at'];
    return $p;
}

function products_all(bool $publishedOnly = false): array
{
    $sql = 'SELECT * FROM products' . ($publishedOnly ? ' WHERE published = 1' : '') . ' ORDER BY sort_order, id';
    return array_map('product_from_row', q_all($sql));
}

function product_by_id(int $id): ?array
{
    $row = q_one('SELECT * FROM products WHERE id = ?', [$id]);
    return $row ? product_from_row($row) : null;
}

/**
 * Ürünü kaydeder (yeni ya da mevcut). Alanlar içerik biçimindedir.
 * Kısa ad benzersiz olmalıdır; çakışırsa sonuna sayı eklenir.
 */
function product_save(array $p, ?int $id = null): int
{
    $p = array_replace(product_defaults(), $p);
    $now = now_iso();
    $slug = slugify($p['slug'] ?: $p['title']);
    $base = $slug;
    for ($i = 2; q_val('SELECT id FROM products WHERE slug = ? AND id IS NOT ?', [$slug, $id]) !== null; $i++) {
        $slug = "$base-$i";
    }
    $data = array_diff_key($p, array_flip(['id', 'slug', 'title', 'createdAt', 'updatedAt', ...array_keys(PRODUCT_COLUMNS)]));
    $values = [
        $slug,
        $p['title'],
        json_encode($data, JSON_UNESCAPED_UNICODE),
        (float) $p['priceUsd'],
        $p['compareAtPriceUsd'] === null || $p['compareAtPriceUsd'] === '' ? null : (float) $p['compareAtPriceUsd'],
        array_key_exists($p['stockStatus'], STOCK_STATUSES) ? $p['stockStatus'] : 'in_stock',
        $p['stockQuantity'] === null || $p['stockQuantity'] === '' ? null : (int) $p['stockQuantity'],
        $p['published'] ? 1 : 0,
        $p['featured'] ? 1 : 0,
        $p['categoryId'] ?: null,
        (int) $p['sortOrder'],
    ];
    if ($id) {
        q('UPDATE products SET slug=?, title=?, data=?, price_usd=?, compare_at_usd=?, stock_status=?, stock_quantity=?, published=?, featured=?, category_id=?, sort_order=?, updated_at=? WHERE id=?', [...$values, $now, $id]);
    } else {
        $created = $p['createdAt'] ?: $now;
        $updated = $p['updatedAt'] ?: $now;
        $explicitId = $p['id'] ? (int) $p['id'] : null;
        q('INSERT INTO products (id, slug, title, data, price_usd, compare_at_usd, stock_status, stock_quantity, published, featured, category_id, sort_order, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [$explicitId, ...$values, $created, $updated]);
        $id = (int) db()->lastInsertId();
    }
    content_touch();
    return $id;
}

/** Siteye canlı yansıyan fiyat ve stok bilgisi (statik sayfalar bununla güncellenir). */
function catalog_live(): array
{
    $rate = rate_info();
    $items = [];
    foreach (q_all('SELECT id, slug, price_usd, compare_at_usd, stock_status, published FROM products') as $r) {
        $items[] = [
            'id' => (int) $r['id'],
            'slug' => $r['slug'],
            'priceTry' => usd_to_try((float) $r['price_usd'], $rate),
            'compareAtTry' => $r['compare_at_usd'] !== null && (float) $r['compare_at_usd'] > (float) $r['price_usd'] ? usd_to_try((float) $r['compare_at_usd'], $rate) : null,
            'stockStatus' => $r['stock_status'],
            'published' => (bool) $r['published'],
        ];
    }
    return ['rate' => $rate, 'products' => $items];
}
