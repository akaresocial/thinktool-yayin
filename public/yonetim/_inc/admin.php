<?php

declare(strict_types=1);

/**
 * Yönetim paneli ortak kodu: oturum, yetki, CSRF, form yardımcıları ve sayfa düzeni.
 */
require dirname(__DIR__, 2) . '/_app/bootstrap.php';

const ADMIN_IDLE_SECONDS = 12 * 3600;

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

@mkdir(DATA_DIR . '/sessions', 0700, true);
session_save_path(DATA_DIR . '/sessions');
session_name('tt_admin');
session_set_cookie_params(['lifetime' => 0, 'path' => '/yonetim', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
ini_set('session.gc_maxlifetime', (string) ADMIN_IDLE_SECONDS);
ini_set('session.use_strict_mode', '1');
session_start();

function admin_user(): ?array
{
    static $user = false;
    if ($user !== false) {
        return $user;
    }
    $id = (int) ($_SESSION['uid'] ?? 0);
    if ($id && (time() - (int) ($_SESSION['seen'] ?? 0)) < ADMIN_IDLE_SECONDS) {
        $_SESSION['seen'] = time();
        $user = q_one('SELECT id, email, name FROM users WHERE id = ?', [$id]);
    } else {
        $user = null;
    }
    return $user;
}

function require_login(): array
{
    if ((int) q_val('SELECT COUNT(*) FROM users') === 0) {
        redirect('/yonetim/kurulum.php');
    }
    $u = admin_user();
    if (!$u) {
        redirect('/yonetim/giris.php?r=' . rawurlencode($_SERVER['REQUEST_URI'] ?? '/yonetim/'));
    }
    return $u;
}

function admin_login(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $user['id'];
    $_SESSION['seen'] = time();
    q('UPDATE users SET last_login_at = ? WHERE id = ?', [now_iso(), $user['id']]);
}

function redirect(string $url): never
{
    header('Location: ' . $url, true, 303);
    exit;
}

/* ───────────────────────── CSRF ───────────────────────── */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = random_key(24);
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): void
{
    $sent = (string) ($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($sent === '' || !hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        exit('Oturum doğrulaması başarısız oldu. Sayfayı yenileyip tekrar deneyin.');
    }
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/* ───────────────────────── Bildirimler ───────────────────────── */

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = [$type, $message];
}

function flash_render(): string
{
    $html = '';
    foreach ($_SESSION['flash'] ?? [] as [$type, $message]) {
        $html .= '<div class="flash flash-' . e($type) . '" role="status">' . e($message) . '</div>';
    }
    unset($_SESSION['flash']);
    return $html;
}

/* ───────────────────────── Form yardımcıları ───────────────────────── */

function p(string $key, mixed $default = ''): mixed
{
    return $_POST[$key] ?? $default;
}

function p_str(string $key, int $max = 500): string
{
    return str_in($_POST[$key] ?? '', $max);
}

function p_text(string $key, int $max = 20000): string
{
    $v = (string) ($_POST[$key] ?? '');
    return mb_substr(str_replace("\r\n", "\n", $v), 0, $max);
}

function p_bool(string $key): bool
{
    return !empty($_POST[$key]);
}

function p_num(string $key): ?float
{
    $v = str_replace(',', '.', trim((string) ($_POST[$key] ?? '')));
    return $v === '' || !is_numeric($v) ? null : (float) $v;
}

function p_json(string $key): array
{
    $v = json_decode((string) ($_POST[$key] ?? ''), true);
    return is_array($v) ? $v : [];
}

/** Yönetim panelinde girilen HTML'i temizler (betik, olay öznitelikleri ve javascript: bağlantıları çıkarılır). */
function clean_html(string $html): string
{
    $html = preg_replace('#<(script|style|iframe|object|embed|form|input|button|textarea|select|meta|link)\b[^>]*>.*?</\1>#is', '', $html) ?? '';
    $html = preg_replace('#<(script|style|iframe|object|embed|form|input|button|textarea|select|meta|link)\b[^>]*/?>#is', '', $html) ?? '';
    $html = preg_replace('#\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html) ?? '';
    $html = preg_replace('#(href|src)\s*=\s*(["\']?)\s*(javascript|data|vbscript):#i', '$1=$2#', $html) ?? '';
    return trim($html);
}

function field(string $label, string $control, string $hint = '', string $class = ''): string
{
    return '<label class="field ' . e($class) . '"><span class="field-label">' . e($label) . '</span>' . $control
        . ($hint !== '' ? '<span class="field-hint">' . $hint . '</span>' : '') . '</label>';
}

function input(string $name, mixed $value = '', array $attrs = []): string
{
    $a = '';
    foreach ($attrs + ['type' => 'text'] as $k => $v) {
        if ($v === true) {
            $a .= ' ' . e($k);
        } elseif ($v !== false && $v !== null) {
            $a .= ' ' . e($k) . '="' . e($v) . '"';
        }
    }
    return '<input name="' . e($name) . '" value="' . e($value) . '"' . $a . '>';
}

function textarea(string $name, mixed $value = '', int $rows = 3, array $attrs = []): string
{
    $a = '';
    foreach ($attrs as $k => $v) {
        $a .= ' ' . e($k) . '="' . e($v) . '"';
    }
    return '<textarea name="' . e($name) . '" rows="' . $rows . '"' . $a . '>' . e($value) . '</textarea>';
}

function select(string $name, array $options, mixed $value, array $attrs = []): string
{
    $a = '';
    foreach ($attrs as $k => $v) {
        $a .= ' ' . e($k) . '="' . e($v) . '"';
    }
    $html = '<select name="' . e($name) . '"' . $a . '>';
    foreach ($options as $k => $label) {
        $html .= '<option value="' . e($k) . '"' . ((string) $k === (string) $value ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    return $html . '</select>';
}

function checkbox(string $name, bool $checked, string $label): string
{
    return '<label class="check"><input type="checkbox" name="' . e($name) . '" value="1"' . ($checked ? ' checked' : '') . '> <span>' . e($label) . '</span></label>';
}

function checkbox_value(string $name, string $value, bool $checked, string $label): string
{
    return '<label class="check"><input type="checkbox" name="' . e($name) . '" value="' . e($value) . '"' . ($checked ? ' checked' : '') . '> <span>' . e($label) . '</span></label>';
}

function status_pill(string $status): string
{
    $tone = match ($status) {
        'paid', 'completed' => 'ok',
        'shipped' => 'info',
        'pending_payment', 'awaiting_transfer' => 'warn',
        'cancelled', 'payment_failed', 'refunded' => 'bad',
        default => 'muted',
    };
    return '<span class="pill pill-' . $tone . '">' . e(status_label($status)) . '</span>';
}

/* ───────────────────────── Sayfa düzeni ───────────────────────── */

function admin_header(string $title, string $active = '', array $opts = []): void
{
    $u = admin_user();
    $newMessages = $u ? (int) q_val("SELECT COUNT(*) FROM contact_messages WHERE status = 'new'") : 0;
    $pendingOrders = $u ? (int) q_val("SELECT COUNT(*) FROM orders WHERE status IN ('awaiting_transfer','paid')") : 0;
    $nav = [
        'panel' => ['/yonetim/', 'Panel', ''],
        'siparisler' => ['/yonetim/siparisler.php', 'Siparişler', $pendingOrders ? (string) $pendingOrders : ''],
        'urunler' => ['/yonetim/urunler.php', 'Ürünler', ''],
        'kategoriler' => ['/yonetim/kategoriler.php', 'Kategoriler', ''],
        'yazilar' => ['/yonetim/yazilar.php', 'Blog', ''],
        'sayfalar' => ['/yonetim/sayfalar.php', 'Sayfalar', ''],
        'medya' => ['/yonetim/medya.php', 'Medya', ''],
        'mesajlar' => ['/yonetim/mesajlar.php', 'Mesajlar', $newMessages ? (string) $newMessages : ''],
        'ayarlar' => ['/yonetim/ayarlar.php', 'Ayarlar', ''],
        'kullanicilar' => ['/yonetim/kullanicilar.php', 'Kullanıcılar', ''],
        'yayin' => ['/yonetim/yayin.php', 'Yayın durumu', ''],
    ];
    ?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> · Thinktool Panel</title>
<link rel="icon" href="/brand/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="/yonetim/assets/admin.css?v=<?= e(admin_asset_version('admin.css')) ?>">
<?php if (!empty($opts['editor'])): ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css">
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js" defer></script>
<?php endif; ?>
<script src="/yonetim/assets/admin.js?v=<?= e(admin_asset_version('admin.js')) ?>" defer></script>
</head>
<body class="<?= $u ? 'has-nav' : 'no-nav' ?>" data-csrf="<?= e(csrf_token()) ?>">
<?php if ($u): ?>
<header class="topbar">
  <button type="button" class="menu-toggle" data-menu-toggle aria-label="Menü">☰</button>
  <a class="brand" href="/yonetim/"><img src="/brand/logo.svg" alt="Thinktool Türkiye" height="22"><span>Panel</span></a>
  <div class="topbar-right">
    <a href="/" target="_blank" rel="noopener" class="btn btn-ghost btn-sm">Siteyi aç ↗</a>
    <form method="post" action="/yonetim/cikis.php"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit">Çıkış</button></form>
  </div>
</header>
<nav class="sidenav" data-menu>
  <p class="sidenav-user"><?= e($u['name']) ?><br><small><?= e($u['email']) ?></small></p>
  <?php foreach ($nav as $key => [$href, $label, $badge]): ?>
    <a href="<?= e($href) ?>" class="<?= $key === $active ? 'active' : '' ?>"><?= e($label) ?><?php if ($badge !== ''): ?><span class="badge"><?= e($badge) ?></span><?php endif; ?></a>
  <?php endforeach; ?>
</nav>
<?php endif; ?>
<main class="main">
<?= flash_render() ?>
    <?php
}

function admin_footer(): void
{
    echo "</main>\n</body>\n</html>\n";
}

/** Panel CSS/JS sürüm damgası: dosya değişince tarayıcı önbelleği kendiliğinden yenilenir. */
function admin_asset_version(string $file): string
{
    $path = dirname(__DIR__) . '/assets/' . $file;
    return is_file($path) ? substr(md5((string) filemtime($path) . filesize($path)), 0, 8) : '1';
}
