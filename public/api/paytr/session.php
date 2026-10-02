<?php

declare(strict_types=1);

/**
 * Kartla ödeme oturumu:
 * - iFrame API açıksa gömülü ödeme formu için token ({ mode: 'iframe' }),
 * - yalnızca Link API açıksa sipariş için ödeme linki ({ mode: 'link' }).
 * Aynı sipariş için eşzamanlı istekler kilitle sıraya alınır; açık bir link varsa yeniden kullanılır.
 */
require __DIR__ . '/../../_app/bootstrap.php';

const LINK_TTL = 48 * 3600;
const LINK_MIN_REMAINING = 30 * 60;

api_run(function (): void {
    require_method('POST');
    rate_limit_or_fail('paytr-session', 20, 600);
    $no = (int) (json_input()['orderNumber'] ?? 0);
    if (!visitor_order($no)) {
        json_fail('Sipariş bulunamadı.', 404);
    }

    @mkdir(DATA_DIR . '/locks', 0750, true);
    $lock = fopen(DATA_DIR . "/locks/order-$no.lock", 'c');
    flock($lock, LOCK_EX);

    $o = visitor_order($no);
    if ($o['paymentMethod'] !== 'card') {
        json_fail('Bu sipariş kartla ödeme için oluşturulmadı.', 400);
    }
    if (!in_array($o['status'], ['pending_payment', 'payment_failed'], true)) {
        json_fail('Bu siparişin ödemesi zaten alınmış.', 409, ['paid' => true]);
    }
    $p = $o['paytr'];
    if (($p['integration'] ?? '') === 'link' && !empty($p['linkUrl']) && strtotime((string) ($p['linkExpiresAt'] ?? '')) - time() > LINK_MIN_REMAINING) {
        json_out(['ok' => true, 'mode' => 'link', 'url' => $p['linkUrl']]);
    }

    // en fazla taksit (0 = PayTR'deki tüm seçenekler); peşin fiyatına taksitlerin vade farkı PayTR panelinde ayarlanır
    $max = max(0, min(12, (int) (settings()['installmentLimit'] ?? 0)));
    $attempt = (int) ($p['attempts'] ?? 0) + 1;
    $oid = merchant_oid_for($no, $attempt);
    order_update($o['id'], ['status' => 'pending_payment', 'paytr' => ['merchantOid' => $oid, 'attempts' => $attempt]], false);

    if (paytr_integration() === 'iframe') {
        order_update($o['id'], ['paytr' => ['integration' => 'iframe']], false);
        $k = rawurlencode($o['accessKey']);
        $res = paytr_iframe_token(
            $o,
            $oid,
            client_ip(),
            site_url("/siparis/$no?k=$k&odeme=tamam"),
            site_url("/siparis/$no?k=$k&to=kart&hata=1"),
            $max,
        );
        if (isset($res['token'])) {
            json_out(['ok' => true, 'mode' => 'iframe', 'token' => $res['token']]);
        }
        if (empty($res['linkOnly'])) {
            log_app("PayTR token hatası #$no: {$res['error']}", 'paytr');
            json_fail('Ödeme ekranı açılamadı: ' . $res['error'], 502);
        }
        paytr_mark_iframe_unavailable();
        log_app('PayTR: mağazada yalnızca Link API (Basic API) açık; ödeme linkiyle devam ediliyor.', 'paytr');
    }

    $expires = time() + LINK_TTL;
    $link = paytr_create_link($o, $oid, $expires, $max);
    if (isset($link['error'])) {
        log_app("PayTR ödeme linki oluşturulamadı #$no: {$link['error']}", 'paytr');
        json_fail('Ödeme sayfası hazırlanamadı: ' . $link['error'], 502);
    }
    order_update($o['id'], ['paytr' => ['integration' => 'link', 'linkId' => $link['id'], 'linkUrl' => $link['url'], 'linkExpiresAt' => gmdate('Y-m-d\TH:i:s\Z', $expires)]], false);
    order_event($o['id'], 'paytr', "Ödeme linki oluşturuldu ($oid, {$link['type']}): {$link['url']}");
    if (!empty($p['linkId']) && $p['linkId'] !== $link['id']) {
        paytr_delete_link((string) $p['linkId']);
    }
    json_out(['ok' => true, 'mode' => 'link', 'url' => $link['url']]);
});
