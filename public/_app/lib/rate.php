<?php

declare(strict_types=1);

const RATE_UPDATE_HOUR = 10;

/** TCMB günlük kur bülteninden USD satış kurlarını okur. */
function tcmb_fetch_usd(): array
{
    $res = http_get('https://www.tcmb.gov.tr/kurlar/today.xml', 12, ['Accept: application/xml']);
    if ($res['status'] !== 200 || $res['body'] === '') {
        throw new RuntimeException('TCMB yanıtı alınamadı: ' . ($res['error'] ?: 'HTTP ' . $res['status']));
    }
    $xml = $res['body'];
    if (!preg_match('/<Currency[^>]*CurrencyCode="USD"[^>]*>(.*?)<\/Currency>/s', $xml, $block)) {
        throw new RuntimeException('TCMB bülteninde USD bulunamadı');
    }
    $read = fn (string $tag) => preg_match("/<$tag>([^<]+)<\/$tag>/", $block[1], $m) ? (float) $m[1] : 0.0;
    $forex = $read('ForexSelling');
    $banknote = $read('BanknoteSelling');
    preg_match('/Tarih="([^"]+)"/', $xml, $date);
    if ($forex <= 1) {
        throw new RuntimeException('TCMB bülteninde geçerli USD kuru yok');
    }
    return ['forexSelling' => $forex, 'banknoteSelling' => $banknote > 1 ? $banknote : $forex, 'date' => $date[1] ?? ''];
}

/**
 * Günlük kuru günceller. $force verilmezse: saat 10:00'dan önceyse ya da bugün 10:00 sonrası zaten alınmışsa atlanır.
 * Kur değişimi fiyatları değiştirdiği için site yeniden derlenmek üzere işaretlenir.
 */
function rate_sync(bool $force = false): array
{
    $s = settings(true);
    $now = istanbul_now();
    $last = $s['rateUpdatedAt'] ? istanbul_now($s['rateUpdatedAt']) : null;
    $haveRate = (float) ($s['tcmbForexSelling'] ?? 0) > 1;
    $alreadyToday = $last && $last['dateKey'] === $now['dateKey'] && $last['hour'] >= RATE_UPDATE_HOUR;
    if (!$force && $haveRate && ($now['hour'] < RATE_UPDATE_HOUR || $alreadyToday)) {
        return ['updated' => false, 'reason' => 'Bugünün kuru zaten güncel'];
    }
    $r = tcmb_fetch_usd();
    settings_save([
        'tcmbForexSelling' => $r['forexSelling'],
        'tcmbBanknoteSelling' => $r['banknoteSelling'],
        'tcmbDate' => $r['date'],
        'rateUpdatedAt' => now_iso(),
    ]);
    log_app("Döviz kuru güncellendi: 1 USD = {$r['forexSelling']} TL (TCMB {$r['date']})");
    return ['updated' => true, 'rates' => $r];
}
