<?php

declare(strict_types=1);

/**
 * Google Merchant Center ürün akışı (yalnızca yayındaki ürünler; canlı fiyat ve stok). Üretimi: _app/lib/feed.php
 * Merchant Center → Ürünler → Ürün kaynağı ekle → "Dosya" → zamanlanmış getirme (günlük, 10:30 sonrası):
 *   https://thinktool.com.tr/api/google-feed.php
 * robots.txt bu adrese özel izin verir (src/app/robots.ts); /api/ geri kalanı taranmaz.
 */
require __DIR__ . '/../_app/bootstrap.php';

try {
    require_method('GET', 'HEAD');
    $xml = google_feed_xml();
} catch (Throwable $e) {
    log_app('Google ürün akışı: ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), 'error');
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo "Ürün akışı şu an oluşturulamadı.\n";
    exit;
}

header('Content-Type: application/xml; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=300');
echo $xml;
