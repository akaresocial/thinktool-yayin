<?php

declare(strict_types=1);

/**
 * Thinktool Türkiye — PHP arka uç çekirdeği.
 *
 * Web kökü bu klasörün bir üstüdür (public_html). Kalıcı veriler web kökünün DIŞINDA tutulur:
 *   ~/thinktool-data/thinktool.sqlite   veritabanı (ürünler, siparişler, ayarlar)
 *   ~/thinktool-data/uploads/           görseller (web kökündeki "uploads" bağlantısıyla sunulur)
 *   ~/thinktool-data/backups/ logs/ mails/
 * Yerel geliştirmede TT_DATA_DIR ve TT_ENV=development ortam değişkenleri kullanılır.
 */

define('APP_DIR', __DIR__);
define('WEB_ROOT', dirname(__DIR__));

/** Hesabın ev dizini (/home/kullanici). Web sunucusu altında HOME tanımlı olmayabilir. */
function tt_home_dir(): string
{
    if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
        $pw = @posix_getpwuid(posix_geteuid());
        if (!empty($pw['dir']) && is_dir($pw['dir'])) {
            return rtrim($pw['dir'], '/');
        }
    }
    $home = (string) getenv('HOME');
    return $home !== '' && is_dir($home) ? rtrim($home, '/') : dirname(WEB_ROOT);
}

define('DATA_DIR', rtrim((string) (getenv('TT_DATA_DIR') ?: tt_home_dir() . '/thinktool-data'), '/'));
// Veriler asla web kökünün içinde olamaz (veritabanı ve siparişler indirilebilir hâle gelirdi).
if (str_starts_with(DATA_DIR . '/', rtrim(WEB_ROOT, '/') . '/')) {
    http_response_code(500);
    exit('Yapılandırma hatası: veri klasörü web kökünün içinde.');
}
define('APP_ENV', (string) (getenv('TT_ENV') ?: 'production'));
define('IS_CLI', PHP_SAPI === 'cli');

date_default_timezone_set('Europe/Istanbul');
mb_internal_encoding('UTF-8');
error_reporting(E_ALL);
ini_set('display_errors', APP_ENV === 'development' ? '1' : '0');
ini_set('log_errors', '1');

// İzinler: görseller web sunucusunca okunur (web kökündeki "uploads" bağlantısı); geri kalan her şey yalnızca hesaba açıktır.
foreach (['' => 0711, '/uploads' => 0755, '/logs' => 0700, '/mails' => 0700, '/backups' => 0700, '/locks' => 0700] as $dir => $mode) {
    if (!is_dir(DATA_DIR . $dir)) {
        @mkdir(DATA_DIR . $dir, $mode, true);
        @chmod(DATA_DIR . $dir, $mode);
    }
}
if (!is_file(DATA_DIR . '/.htaccess')) {
    @file_put_contents(DATA_DIR . '/.htaccess', "Require all denied\n");
}
ini_set('error_log', DATA_DIR . '/logs/php-error.log');

foreach (['util', 'db', 'settings', 'catalog', 'content', 'orders', 'mail', 'paytr', 'rate', 'validate', 'images'] as $lib) {
    require APP_DIR . "/lib/$lib.php";
}
