<?php

declare(strict_types=1);

/**
 * PayTR entegrasyonu (önceki Next.js sürümünden birebir taşındı).
 * - iFrame API (Pro API): ödeme formu sitede gömülü açılır.
 * - Link API (Basic API): her sipariş için tahsilat linki oluşturulur.
 * "auto" modunda önce iFrame denenir; mağazada yalnızca Link API açıksa ("yalnizca link cozumu")
 * Link API'ye geçilir ve iFrame 6 saat denenmez. PayTR Pro API açtığında sistem kendiliğinden iFrame'e döner.
 * Mağaza bilgileri yönetim panelinden girilir ve yalnızca veritabanında (web kökü dışında) saklanır.
 */

function paytr_config(): array
{
    $c = paytr_settings();
    if ($c['merchantId'] === '' || $c['merchantKey'] === '' || $c['merchantSalt'] === '') {
        throw new UserError('Kartla ödeme henüz yapılandırılmadı. Lütfen havale/EFT ile ödeyin veya bize ulaşın.', 503);
    }
    return $c;
}

function paytr_hmac(string $data, string $key): string
{
    return base64_encode(hash_hmac('sha256', $data, $key, true));
}

function paytr_integration(): string
{
    $mode = paytr_settings()['integration'] ?? 'auto';
    if ($mode === 'iframe' || $mode === 'link') {
        return $mode;
    }
    return (int) (meta_get('paytr_iframe_unavailable_until') ?? 0) > time() ? 'link' : 'iframe';
}

function paytr_mark_iframe_unavailable(): void
{
    meta_set('paytr_iframe_unavailable_until', (string) (time() + 6 * 3600));
}

function paytr_post(string $url, array $fields): array
{
    $res = http_post_form($url, $fields);
    if ($res['error'] !== '' || $res['body'] === '') {
        return ['status' => 'error', 'reason' => "PayTR'ye bağlanılamadı: " . ($res['error'] ?: 'HTTP ' . $res['status'])];
    }
    $json = json_decode($res['body'], true);
    return is_array($json) ? $json : ['status' => 'error', 'reason' => 'PayTR yanıtı okunamadı'];
}

/* ───────────────────────── iFrame API ───────────────────────── */

/** @return array{token?: string, error?: string, linkOnly?: bool} */
function paytr_iframe_token(array $o, string $merchantOid, string $userIp, string $okUrl, string $failUrl, int $maxInstallment): array
{
    $c = paytr_config();
    $testMode = $c['testMode'] ? '1' : '0';
    $amount = (string) (int) round($o['totalTry'] * 100);
    $basket = base64_encode(json_encode(array_map(fn ($i) => [mb_substr($i['title'], 0, 120), number_format($i['unitPriceTry'], 2, '.', ''), $i['quantity']], $o['items']), JSON_UNESCAPED_UNICODE));
    $noInstallment = '0';
    $max = (string) $maxInstallment;
    $currency = 'TL';
    $hash = $c['merchantId'] . $userIp . $merchantOid . $o['email'] . $amount . $basket . $noInstallment . $max . $currency . $testMode;
    $a = $o['shippingAddress'];
    $res = paytr_post('https://www.paytr.com/odeme/api/get-token', [
        'merchant_id' => $c['merchantId'],
        'user_ip' => $userIp,
        'merchant_oid' => $merchantOid,
        'email' => $o['email'],
        'payment_amount' => $amount,
        'paytr_token' => paytr_hmac($hash . $c['merchantSalt'], $c['merchantKey']),
        'user_basket' => $basket,
        'debug_on' => APP_ENV === 'development' ? '1' : '0',
        'no_installment' => $noInstallment,
        'max_installment' => $max,
        'user_name' => mb_substr($o['customerName'], 0, 60),
        'user_address' => mb_substr(trim(($a['address'] ?? '') . ', ' . ($a['district'] ?? '') . ', ' . ($a['city'] ?? ''), ', ') ?: 'Türkiye', 0, 400),
        'user_phone' => mb_substr($o['phone'] ?: '05000000000', 0, 20),
        'merchant_ok_url' => $okUrl,
        'merchant_fail_url' => $failUrl,
        'timeout_limit' => '30',
        'currency' => $currency,
        'test_mode' => $testMode,
        'lang' => 'tr',
    ]);
    if (($res['status'] ?? '') === 'success' && !empty($res['token'])) {
        return ['token' => (string) $res['token']];
    }
    $reason = (string) ($res['reason'] ?? 'PayTR token alınamadı');
    return ['error' => $reason, 'linkOnly' => (bool) preg_match('/link\s*cozum|basic\s*api/i', $reason)];
}

/** iFrame API bildirim imzası. */
function paytr_verify_callback(array $p): bool
{
    $c = paytr_config();
    $expected = paytr_hmac(($p['merchant_oid'] ?? '') . $c['merchantSalt'] . ($p['status'] ?? '') . ($p['total_amount'] ?? ''), $c['merchantKey']);
    return hash_equals($expected, (string) ($p['hash'] ?? ''));
}

/* ───────────────────────── Link API ───────────────────────── */

/** PayTR'nin beklediği "YYYY-MM-DD HH:MM:SS" (İstanbul saati). */
function paytr_datetime(int $timestamp): string
{
    return (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone('Europe/Istanbul'))->format('Y-m-d H:i:s');
}

/** Bildirim adresi yalnızca herkese açık, port içermeyen bir adres olabilir. */
function paytr_public_url(string $url): bool
{
    $u = parse_url($url);
    return is_array($u) && in_array($u['scheme'] ?? '', ['http', 'https'], true) && empty($u['port'])
        && !preg_match('/^(localhost|127\.|0\.0\.0\.0|\[::1\])/', $u['host'] ?? '') && str_contains($u['host'] ?? '', '.');
}

/**
 * Sipariş tutarında ödeme linki oluşturur.
 * "collection" (tahsilat) linki: müşteri yalnızca tutarı görüp kart ekranına geçer, adresini yeniden girmez.
 * Tutar alanı ödeme sayfasında değiştirilebildiğinden bildirimde ödenen tutar sipariş tutarıyla karşılaştırılır.
 * E-posta PayTR kurallarına uymuyorsa (Türkçe karakter, 100+ karakter) tek kullanımlık "product" linkine düşülür.
 */
function paytr_create_link(array $o, string $callbackId, int $expiresAt, int $maxInstallment): array
{
    $c = paytr_config();
    $items = implode(', ', array_map(fn ($i) => $i['quantity'] > 1 ? "{$i['quantity']} x {$i['title']}" : $i['title'], $o['items']));
    $name = mb_substr(preg_replace('/\s+/', ' ', "Sipariş #{$o['orderNumber']} - $items") ?? '', 0, 200);
    $name = str_pad($name, 4, '.');
    $price = (string) (int) round($o['totalTry'] * 100);
    $currency = 'TL';
    $max = (string) min(12, max(1, $maxInstallment ?: 12));
    $lang = 'tr';
    $email = trim($o['email']);
    $collection = (bool) preg_match('/^[\x21-\x7e]+@[\x21-\x7e]+$/', $email) && strlen($email) <= 100;
    $type = $collection ? 'collection' : 'product';
    $typeField = $collection ? $email : '1';
    $fields = [
        'merchant_id' => $c['merchantId'],
        'name' => $name,
        'price' => $price,
        'currency' => $currency,
        'max_installment' => $max,
        'link_type' => $type,
        'lang' => $lang,
        'expiry_date' => paytr_datetime($expiresAt),
        'paytr_token' => paytr_hmac($name . $price . $currency . $max . $type . $lang . $typeField . $c['merchantSalt'], $c['merchantKey']),
        'debug_on' => APP_ENV === 'development' ? '1' : '0',
    ];
    $fields += $collection ? ['email' => $email] : ['min_count' => '1', 'max_count' => '1'];
    $callbackUrl = site_url('/api/paytr/callback.php');
    if (paytr_public_url($callbackUrl)) {
        $fields['callback_link'] = $callbackUrl;
        $fields['callback_id'] = $callbackId;
    }
    $res = paytr_post('https://www.paytr.com/odeme/api/link/create', $fields);
    if (($res['status'] ?? '') === 'success' && !empty($res['id']) && !empty($res['link'])) {
        return ['id' => (string) $res['id'], 'url' => (string) $res['link'], 'type' => $type];
    }
    return ['error' => (string) ($res['reason'] ?? 'PayTR ödeme linki oluşturulamadı')];
}

function paytr_delete_link(string $id): bool
{
    try {
        $c = paytr_config();
    } catch (UserError) {
        return false;
    }
    $res = paytr_post('https://www.paytr.com/odeme/api/link/delete', [
        'merchant_id' => $c['merchantId'],
        'id' => $id,
        'debug_on' => '0',
        'paytr_token' => paytr_hmac($id . $c['merchantId'] . $c['merchantSalt'], $c['merchantKey']),
    ]);
    $ok = ($res['status'] ?? '') === 'success';
    log_app("PayTR linki $id " . ($ok ? 'kapatıldı' : 'kapatılamadı: ' . ($res['reason'] ?? '?')), 'paytr');
    return $ok;
}

/** Link API bildirim imzası. */
function paytr_verify_link_callback(array $p): bool
{
    $c = paytr_config();
    $expected = paytr_hmac(($p['callback_id'] ?? '') . ($p['merchant_oid'] ?? '') . $c['merchantSalt'] . ($p['status'] ?? '') . ($p['total_amount'] ?? ''), $c['merchantKey']);
    return hash_equals($expected, (string) ($p['hash'] ?? ''));
}

/* ───────────────────────── Yardımcılar ───────────────────────── */

/** Ödeme referansı: TT{sipariş no}A{deneme}. Yalnızca harf ve rakam içerir. */
function merchant_oid_for(int $orderNumber, int $attempt): string
{
    return "TT{$orderNumber}A{$attempt}";
}

function parse_merchant_oid(?string $oid): ?array
{
    return preg_match('/^TT(\d+)A(\d+)$/', (string) $oid, $m) ? ['orderNumber' => (int) $m[1], 'attempt' => (int) $m[2]] : null;
}
