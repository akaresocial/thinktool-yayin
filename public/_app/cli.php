<?php

declare(strict_types=1);

/**
 * Komut satırı görevleri (cPanel cron ve yayın betiği çalıştırır; web'den erişilemez).
 *   php _app/cli.php install --seed=_seed/snapshot.json --url=https://thinktool.com.tr
 *   php _app/cli.php cron            (10 dakikada bir: kur, yedek, temizlik)
 *   php _app/cli.php rate --force    (kuru hemen güncelle)
 *   php _app/cli.php backup
 *   php _app/cli.php allow-setup     (ilk yönetici kurulum ekranını 2 saatliğine aç)
 *   php _app/cli.php status
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/bootstrap.php';

$cmd = $argv[1] ?? 'help';
$opt = [];
foreach (array_slice($argv, 2) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) {
        $opt[$m[1]] = $m[2] ?? true;
    }
}
$out = fn (string $s) => fwrite(STDOUT, '[' . date('H:i:s') . "] $s\n");

try {
    switch ($cmd) {
        case 'install':
            db();
            if (!empty($opt['url']) && is_string($opt['url'])) {
                meta_set('site_url', rtrim($opt['url'], '/'));
            }
            if (!empty($opt['ops']) && is_string($opt['ops'])) {
                meta_set('ops_dir', rtrim($opt['ops'], '/'));
            }
            if ((int) q_val('SELECT COUNT(*) FROM products') === 0 && !empty($opt['seed']) && is_file((string) $opt['seed'])) {
                $r = content_import(json_decode((string) file_get_contents((string) $opt['seed']), true) ?: []);
                $out('İlk içerik yüklendi: ' . json_encode($r, JSON_UNESCAPED_UNICODE));
            }
            if ((int) q_val('SELECT COUNT(*) FROM users') === 0 && !is_file(DATA_DIR . '/allow-setup')) {
                touch(DATA_DIR . '/allow-setup');
                $out('İlk yönetici kurulumu açıldı (2 saat): /yonetim/kurulum.php');
            }
            $out('Kurulum tamam. Şema sürümü: ' . meta_get('schema_version'));
            break;

        case 'cron':
            cron_run($out);
            break;

        case 'rate':
            $r = rate_sync((bool) ($opt['force'] ?? false));
            $out($r['updated'] ? 'Kur güncellendi: ' . json_encode($r['rates']) : $r['reason']);
            break;

        case 'backup':
            $out('Yedek: ' . backup_run());
            break;

        case 'allow-setup':
            touch(DATA_DIR . '/allow-setup');
            $out('İlk yönetici kurulumu 2 saatliğine açıldı.');
            break;

        case 'status':
            $out(json_encode([
                'schema' => meta_get('schema_version'),
                'content' => content_version(),
                'products' => (int) q_val('SELECT COUNT(*) FROM products'),
                'orders' => (int) q_val('SELECT COUNT(*) FROM orders'),
                'users' => (int) q_val('SELECT COUNT(*) FROM users'),
                'rate' => rate_info(),
            ], JSON_UNESCAPED_UNICODE));
            break;

        default:
            $out('Komutlar: install, cron, rate [--force], backup, allow-setup, status');
    }
} catch (Throwable $e) {
    log_app('CLI hatası (' . $cmd . '): ' . $e->getMessage(), 'error');
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

/** Zamanlanmış görevler: günlük kur (10:00 sonrası), günlük yedek (03:00 sonrası), temizlik. */
function cron_run(callable $out): void
{
    try {
        $r = rate_sync();
        if ($r['updated']) {
            $out('Kur güncellendi: 1 USD = ' . $r['rates']['forexSelling'] . ' TL');
        }
    } catch (Throwable $e) {
        log_app('Kur güncellenemedi: ' . $e->getMessage(), 'error');
        $out('Kur güncellenemedi: ' . $e->getMessage());
    }
    $now = istanbul_now();
    if ($now['hour'] >= 3 && meta_get('last_backup_day') !== $now['dateKey']) {
        $out('Yedek: ' . backup_run());
        meta_set('last_backup_day', $now['dateKey']);
    }
    q('DELETE FROM rate_limits WHERE window_start < ?', [time() - 86400]);
    foreach (glob(DATA_DIR . '/mails/*.html') ?: [] as $f) {
        if (filemtime($f) < time() - 30 * 86400) {
            @unlink($f);
        }
    }
    meta_set('last_cron_at', now_iso());
}

/** Veritabanının tutarlı kopyası (site çalışırken bile güvenli), son 14 gün saklanır. */
function backup_run(): string
{
    $dir = DATA_DIR . '/backups';
    $file = $dir . '/thinktool-' . date('Ymd-His') . '.sqlite';
    db()->exec('VACUUM INTO ' . db()->quote($file));
    $gz = $file . '.gz';
    file_put_contents($gz, gzencode((string) file_get_contents($file), 6));
    unlink($file);
    foreach (glob($dir . '/thinktool-*.sqlite.gz') ?: [] as $old) {
        if (filemtime($old) < time() - 14 * 86400) {
            unlink($old);
        }
    }
    return basename($gz) . ' (' . round(filesize($gz) / 1024) . ' KB)';
}
