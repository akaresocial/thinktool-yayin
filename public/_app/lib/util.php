<?php

declare(strict_types=1);

/** Kullanıcıya gösterilecek hata (ör. stok yok). */
final class UserError extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 400)
    {
        parent::__construct($message);
    }
}


/* ───────────────────────── Yanıt ve istek ───────────────────────── */

/** JSON yanıt verip betiği bitirir. */
function json_out(array $data, int $status = 200, array $headers = []): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    if (!isset($headers['Cache-Control'])) {
        header('Cache-Control: no-store');
    }
    foreach ($headers as $k => $v) {
        header("$k: $v");
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_fail(string $error, int $status = 400, array $extra = []): never
{
    json_out(['ok' => false, 'error' => $error] + $extra, $status);
}

function require_method(string ...$methods): void
{
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', $methods, true)) {
        header('Allow: ' . implode(', ', $methods));
        json_fail('Geçersiz istek yöntemi', 405);
    }
}

/** İstek gövdesindeki JSON'u okur (en fazla 64 KB). */
function json_input(): array
{
    $raw = file_get_contents('php://input', false, null, 0, 65536);
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function str_in(mixed $v, int $max = 500): string
{
    if (!is_scalar($v)) {
        return '';
    }
    $s = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $v) ?? '');
    return mb_substr($s, 0, $max);
}

/* ───────────────────────── Zaman ───────────────────────── */

/** Veritabanında saklanan UTC zaman damgası (ISO 8601). */
function now_iso(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

/** İstanbul saatine göre gün anahtarı (YYYY-MM-DD) ve saat. */
function istanbul_now(?string $iso = null): array
{
    $d = new DateTimeImmutable($iso ?? 'now', new DateTimeZone('UTC'));
    $d = $d->setTimezone(new DateTimeZone('Europe/Istanbul'));
    return ['dateKey' => $d->format('Y-m-d'), 'hour' => (int) $d->format('G')];
}

const TR_MONTHS = ['', 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];

/** "2026-09-28T11:24:45Z" → "28 Eylül 2026" (isteğe bağlı saat ile) */
function format_date(?string $iso, bool $withTime = false): string
{
    if (!$iso) {
        return '';
    }
    try {
        $d = (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone('Europe/Istanbul'));
    } catch (Exception) {
        return '';
    }
    $s = $d->format('j') . ' ' . TR_MONTHS[(int) $d->format('n')] . ' ' . $d->format('Y');
    return $withTime ? $s . ' ' . $d->format('H:i') : $s;
}

/* ───────────────────────── Biçimlendirme ───────────────────────── */

function e(mixed $v): string
{
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** 38841 → "₺38.841" */
function format_try(float|int $v): string
{
    return '₺' . number_format((float) $v, 0, ',', '.');
}

/** 38841.5 → "₺38.841,50" */
function format_try_exact(float|int $v): string
{
    return '₺' . number_format((float) $v, 2, ',', '.');
}

function format_usd(float|int $v): string
{
    return '$' . number_format((float) $v, 0, ',', '.');
}

function format_rate(float $v): string
{
    return number_format($v, 4, ',', '.');
}

/** "0532 459 51 90" → "905324595190" */
function phone_e164(string $phone): string
{
    $d = preg_replace('/\D/', '', $phone) ?? '';
    if (str_starts_with($d, '90')) {
        return $d;
    }
    if (str_starts_with($d, '0')) {
        return '9' . $d;
    }
    return '90' . $d;
}

function whatsapp_href(string $phone, string $message = ''): string
{
    return 'https://wa.me/' . phone_e164($phone) . ($message !== '' ? '?text=' . rawurlencode($message) : '');
}

/** Türkçe karakterleri dönüştürerek URL uyumlu kısa ad üretir. */
function slugify(string $s): string
{
    $map = ['ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'İ' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u', 'Ç' => 'c', 'Ğ' => 'g', 'Ö' => 'o', 'Ş' => 's', 'Ü' => 'u', 'â' => 'a', 'î' => 'i', 'û' => 'u'];
    $s = strtr($s, $map);
    $s = mb_strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    return trim($s, '-') ?: 'icerik';
}

/** URL'de güvenle taşınabilen rastgele anahtar. */
function random_key(int $bytes = 18): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

/* ───────────────────────── Ortam ───────────────────────── */

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

/** Sitenin kök adresi: istekten (web) ya da ayarlardan (cron). Sonunda / yoktur. */
function site_url(string $path = ''): string
{
    static $origin = null;
    if ($origin === null) {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if (!IS_CLI && $host !== '' && preg_match('/^[a-z0-9.\-:]+$/i', $host)) {
            $origin = (is_https() ? 'https://' : 'http://') . $host;
        } else {
            $origin = rtrim((string) (meta_get('site_url') ?: 'https://thinktool.com.tr'), '/');
        }
    }
    return $origin . $path;
}

function log_app(string $message, string $file = 'app'): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    @file_put_contents(DATA_DIR . "/logs/$file.log", $line, FILE_APPEND | LOCK_EX);
}

/* ───────────────────────── Sipariş erişim çerezi ───────────────────────── */

/**
 * Sipariş sayfalarına giriş yapmadan erişim, siparişe özel gizli anahtarla sağlanır.
 * Anahtar yalnızca httpOnly çerezde tutulur; adreste ve JavaScript'te görünmez (analitik araçlarına sızmaz).
 */
function order_cookie_name(int $orderNumber): string
{
    return 'tt_order_' . $orderNumber;
}

function set_order_cookie(int $orderNumber, string $key): void
{
    setcookie(order_cookie_name($orderNumber), $key, [
        'expires' => time() + 180 * 86400,
        'path' => '/',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function order_cookie_key(int $orderNumber): string
{
    return str_in($_COOKIE[order_cookie_name($orderNumber)] ?? '', 64);
}

/* ───────────────────────── Hız sınırı ───────────────────────── */

/** Basit, veritabanında tutulan hız sınırı. Sınır aşıldıysa false döner. */
function rate_limit(string $key, int $limit, int $windowSeconds): bool
{
    $now = time();
    $key = substr(hash('sha256', $key), 0, 40);
    return tx(function () use ($key, $limit, $windowSeconds, $now): bool {
        $row = q_one('SELECT window_start, count FROM rate_limits WHERE key = ?', [$key]);
        if (!$row || (int) $row['window_start'] + $windowSeconds < $now) {
            q('INSERT OR REPLACE INTO rate_limits (key, window_start, count) VALUES (?, ?, 1)', [$key, $now]);
            return true;
        }
        q('UPDATE rate_limits SET count = count + 1 WHERE key = ?', [$key]);
        return (int) $row['count'] + 1 <= $limit;
    });
}

function rate_limit_or_fail(string $key, int $limit, int $windowSeconds, string $message = 'Çok fazla deneme yaptınız. Lütfen birkaç dakika sonra tekrar deneyin.'): void
{
    if (!rate_limit($key . ':' . client_ip(), $limit, $windowSeconds)) {
        json_fail($message, 429);
    }
}

/* ───────────────────────── HTTP istemcisi ───────────────────────── */

/** Form verisiyle POST (PayTR). Yanıt gövdesi ve durum kodu döner. */
function http_post_form(string $url, array $fields, int $timeout = 20): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    return ['status' => $status, 'body' => is_string($body) ? $body : '', 'error' => $error];
}

function http_get(string $url, int $timeout = 15, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => 'thinktool.com.tr',
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    return ['status' => $status, 'body' => is_string($body) ? $body : '', 'error' => $error];
}

/** API ucunu çalıştırır: kullanıcı hataları anlaşılır mesajla, diğerleri günlüğe yazılıp genel mesajla döner. */
function api_run(callable $fn): never
{
    try {
        $fn();
        json_out(['ok' => true]);
    } catch (UserError $e) {
        json_fail($e->getMessage(), $e->status);
    } catch (Throwable $e) {
        log_app(get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), 'error');
        json_fail('Beklenmeyen bir hata oluştu. Lütfen tekrar deneyin ya da bize ulaşın.', 500);
    }
}
