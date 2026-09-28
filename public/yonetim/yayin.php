<?php

declare(strict_types=1);

require __DIR__ . '/_inc/admin.php';
require_login();

$content = content_version();
$built = json_decode((string) @file_get_contents(WEB_ROOT . '/version.json'), true) ?: [];
$ops = meta_get('ops_dir') ?: dirname(WEB_ROOT) . '/thinktool-ops';
$status = json_decode((string) @file_get_contents("$ops/status.json"), true) ?: [];
$log = is_file("$ops/deploy.log") ? array_slice(file("$ops/deploy.log", FILE_IGNORE_NEW_LINES) ?: [], -30) : [];
$pending = (int) ($built['contentVersion'] ?? 0) < $content['version'];

admin_header('Yayın durumu', 'yayin');
?>
<div class="page-head"><div><h1>Yayın durumu</h1><p class="muted">Site sayfaları, içerik değiştiğinde otomatik olarak yeniden oluşturulup yayına alınır.</p></div></div>
<div class="stats">
  <div class="stat"><span class="stat-label">Sitedeki içerik</span><span class="stat-value"><?= $pending ? 'Güncelleniyor…' : 'Güncel ✓' ?></span></div>
  <div class="stat"><span class="stat-label">Son derleme</span><span class="stat-value small-value"><?= !empty($built['builtAt']) ? e(format_date($built['builtAt'], true)) : '—' ?></span></div>
  <div class="stat"><span class="stat-label">Son kurulum</span><span class="stat-value small-value"><?= !empty($status['time']) ? e(format_date(gmdate('Y-m-d\TH:i:s\Z', strtotime($status['time'])), true)) : '—' ?></span></div>
  <div class="stat"><span class="stat-label">Durum</span><span class="stat-value small-value"><?= e($status['state'] ?? '—') ?></span></div>
</div>
<section class="card stack">
  <header class="card-head"><h2>Nasıl çalışır?</h2></header>
  <ul class="bullets">
    <li><strong>Fiyat, stok ve kur</strong> değişiklikleri sitede <strong>hemen</strong> görünür.</li>
    <li><strong>Ürün ekleme/silme, açıklama, görsel, blog ve sayfa</strong> değişiklikleri 15 dakika içinde otomatik yayına alınır.</li>
    <li>Her yayın öncesi site yedeklenir, yayından sonra test edilir; sorun olursa önceki sürüme otomatik dönülür.</li>
  </ul>
  <p class="muted small">İçerik sürümü: panel <?= (int) $content['version'] ?> · sitede <?= (int) ($built['contentVersion'] ?? 0) ?><?= !empty($built['source']) ? ' · kod ' . e(substr((string) $built['source'], 0, 7)) : '' ?></p>
</section>
<?php
$gd = function_exists('gd_info') ? gd_info() : [];
$lastBackup = glob(DATA_DIR . '/backups/thinktool-*.sqlite.gz') ?: [];
rsort($lastBackup);
$system = [
    'PHP' => PHP_VERSION,
    'SQLite' => (string) q_val('SELECT sqlite_version()'),
    'Görsel işleme (WebP)' => !empty($gd['WebP Support']) ? 'var' : 'YOK',
    'E-posta (mail)' => function_exists('mail') ? 'var' : 'YOK',
    'Son zamanlanmış görev' => meta_get('last_cron_at') ? format_date((string) meta_get('last_cron_at'), true) : '—',
    'Son yedek' => $lastBackup ? basename($lastBackup[0]) . ' (' . round(filesize($lastBackup[0]) / 1024) . ' KB)' : '—',
];
?>
<section class="card">
  <header class="card-head"><h2>Sistem</h2></header>
  <table class="table"><tbody>
  <?php foreach ($system as $k => $v): ?>
    <tr><td class="muted"><?= e($k) ?></td><td><?= e($v) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table>
</section>
<?php if ($log): ?>
<section class="card">
  <header class="card-head"><h2>Yayın günlüğü</h2></header>
  <pre class="log"><?= e(implode("\n", $log)) ?></pre>
</section>
<?php endif; ?>
<?php
admin_footer();
