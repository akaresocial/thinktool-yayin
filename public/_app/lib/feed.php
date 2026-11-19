<?php

declare(strict_types=1);

/**
 * Google Merchant Center ürün akışı (RSS 2.0 + g: alanları) — https://support.google.com/merchants/answer/7052112
 *
 * Değerler ürün sayfasındaki yapılandırılmış veriyle (src/app/urun/[slug]/page.tsx) aynı kurallarla üretilir:
 * Google sayfayı da tarar; fiyat ya da stok tutmazsa ürünü reddeder.
 *   - Fiyat: usd_to_try() + günün kuru (kur değişince site de yeniden derlenir, sayfa fiyatı aynı kalır).
 *   - Stok: yalnızca "Stokta yok" out_of_stock; "Sipariş üzerine" sayfada olduğu gibi in_stock.
 */

const FEED_BRAND = 'Thinktool';

/** Yayındaki, fiyatı ve görseli olan ürünlerin akış kayıtları (alan adı → değer; görseller dizi). */
function google_feed_items(): array
{
    $rate = rate_info();
    $categories = array_column(categories_all(), 'title', 'id');
    $items = [];
    foreach (products_all(true) as $p) {
        $images = [];
        foreach ($p['gallery'] as $mediaId) {
            $m = media_by_id((int) $mediaId);
            if ($m) {
                $images[] = site_url(media_url($m));
            }
        }
        $priceTry = usd_to_try((float) $p['priceUsd'], $rate);
        // Görselsiz ya da fiyatsız ürünü Google zaten reddeder
        if (!$images || $priceTry <= 0) {
            continue;
        }
        $compareAt = $p['compareAtPriceUsd'] !== null && (float) $p['compareAtPriceUsd'] > (float) $p['priceUsd']
            ? usd_to_try((float) $p['compareAtPriceUsd'], $rate)
            : null;
        $gtin = gtin_normalize((string) ($p['gtin'] ?? ''));
        $gtin = gtin_valid($gtin) ? $gtin : '';
        $mpn = trim((string) ($p['mpn'] ?? ''));

        $item = [
            // Kalıcı kimlik: SKU sonradan girilse ya da değişse de Google'daki ürün geçmişi kaybolmaz
            'id' => 'TT-' . $p['id'],
            'title' => mb_substr($p['title'], 0, 150),
            'description' => feed_description($p),
            'link' => site_url('/urun/' . $p['slug']),
            'image_link' => $images[0],
            'additional_image_link' => array_slice($images, 1, 10),
            'availability' => $p['stockStatus'] === 'out_of_stock' ? 'out_of_stock' : 'in_stock',
            'price' => feed_money($compareAt ?? $priceTry),
            'sale_price' => $compareAt !== null ? feed_money($priceTry) : '',
            'condition' => 'new',
            'brand' => FEED_BRAND,
            'gtin' => $gtin,
            'mpn' => $mpn,
            // Barkod da parça no da yoksa Google'a "tanımlayıcı yok" denir; aksi hâlde ürün "eksik tanımlayıcı" uyarısı alır
            'identifier_exists' => $gtin === '' && $mpn === '' ? 'no' : '',
            'product_type' => $p['categoryId'] !== null ? ($categories[$p['categoryId']] ?? '') : '',
            'shipping' => ['country' => 'TR', 'service' => 'Ücretsiz kargo', 'price' => feed_money(0)],
        ];
        $items[] = array_filter($item, fn ($v) => $v !== '' && $v !== []);
    }
    return $items;
}

function feed_money(float $try): string
{
    return number_format($try, 2, '.', '') . ' TRY';
}

/** Düz metin açıklama: özet (yoksa SEO açıklaması / kısa açıklama) + öne çıkan maddeler. En fazla 5000 karakter. */
function feed_description(array $p): string
{
    $clean = fn (string $s) => trim(preg_replace('/[ \t]+/u', ' ', html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    $base = '';
    foreach ([$p['summary'], $p['seo']['description'] ?? '', $p['tagline'], $p['title']] as $candidate) {
        $base = $clean((string) $candidate);
        if ($base !== '') {
            break;
        }
    }
    $highlights = array_values(array_filter(array_map(fn ($h) => $clean((string) $h), $p['highlights'] ?? [])));
    $text = $base . ($highlights ? "\n\n" . implode("\n", array_map(fn ($h) => "• $h", $highlights)) : '');
    return mb_substr($text, 0, 5000);
}

function google_feed_xml(): string
{
    $x = fn (string $s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $brand = (string) (settings()['brandName'] ?? 'Thinktool Türkiye');
    $out = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
        . "<rss version=\"2.0\" xmlns:g=\"http://base.google.com/ns/1.0\">\n<channel>\n"
        . '<title>' . $x($brand) . "</title>\n"
        . '<link>' . $x(site_url('/')) . "</link>\n"
        . '<description>' . $x("$brand ürünleri") . "</description>\n";
    foreach (google_feed_items() as $item) {
        $out .= "<item>\n";
        foreach ($item as $key => $value) {
            if ($key === 'shipping') {
                $out .= '  <g:shipping>';
                foreach ($value as $k => $v) {
                    $out .= "<g:$k>" . $x($v) . "</g:$k>";
                }
                $out .= "</g:shipping>\n";
                continue;
            }
            foreach ((array) $value as $v) {
                $out .= "  <g:$key>" . $x((string) $v) . "</g:$key>\n";
            }
        }
        $out .= "</item>\n";
    }
    return $out . "</channel>\n</rss>\n";
}
