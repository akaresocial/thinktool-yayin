<?php

declare(strict_types=1);

require __DIR__ . '/_inc/admin.php';
$user = require_login();

if (is_post() && p('action') === 'rate') {
    csrf_verify();
    try {
        $r = rate_sync(true);
        flash('success', 'Kur güncellendi: 1 USD = ' . format_rate((float) $r['rates']['forexSelling']) . ' TL (TCMB ' . $r['rates']['date'] . ')');
    } catch (Throwable $e) {
        flash('error', 'Kur alınamadı: ' . $e->getMessage());
    }
    redirect('/yonetim/');
}

$todayStart = (new DateTimeImmutable('today', new DateTimeZone('Europe/Istanbul')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
$weekStart = (new DateTimeImmutable('-6 days today', new DateTimeZone('Europe/Istanbul')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
$revenueStatuses = "('paid','shipped','completed')";
$stats = [
    ['Bugünkü siparişler', (int) q_val('SELECT COUNT(*) FROM orders WHERE created_at >= ?', [$todayStart]), ''],
    ['Havale bekleyen', (int) q_val("SELECT COUNT(*) FROM orders WHERE status = 'awaiting_transfer'"), '/yonetim/siparisler.php?durum=awaiting_transfer'],
    ['Kargolanacak', (int) q_val("SELECT COUNT(*) FROM orders WHERE status = 'paid'"), '/yonetim/siparisler.php?durum=paid'],
    ['Son 7 gün ciro', format_try((float) (q_val("SELECT SUM(total_try) FROM orders WHERE status IN $revenueStatuses AND created_at >= ?", [$weekStart]) ?? 0)), ''],
];
$recent = q_all('SELECT id, order_number, first_name, last_name, total_try, status, payment_method, created_at FROM orders ORDER BY id DESC LIMIT 8');
$s = settings();
$rate = rate_info($s);
$messages = (int) q_val("SELECT COUNT(*) FROM contact_messages WHERE status = 'new'");
$outOfStock = (int) q_val("SELECT COUNT(*) FROM products WHERE stock_status = 'out_of_stock' AND published = 1");

admin_header('Panel', 'panel');
$first = explode(' ', $user['name'])[0];
?>
<div class="page-head">
  <div><p class="eyebrow">THINKTOOL Türkiye</p><h1>Merhaba <?= e($first) ?></h1></div>
  <div class="actions"><a class="btn btn-primary" href="/yonetim/urun.php?yeni=1">+ Yeni ürün</a></div>
</div>

<div class="stats">
  <?php foreach ($stats as [$label, $value, $href]): ?>
    <?= $href ? '<a class="stat" href="' . e($href) . '">' : '<div class="stat">' ?>
      <span class="stat-label"><?= e($label) ?></span>
      <span class="stat-value"><?= e($value) ?></span>
    <?= $href ? '</a>' : '</div>' ?>
  <?php endforeach; ?>
</div>

<div class="grid-2">
  <section class="card">
    <header class="card-head"><h2>Son siparişler</h2><a href="/yonetim/siparisler.php">Tümü →</a></header>
    <?php if (!$recent): ?>
      <p class="muted">Henüz sipariş yok.</p>
    <?php else: ?>
      <table class="table">
        <tbody>
        <?php foreach ($recent as $o): ?>
          <tr class="row-link" data-href="/yonetim/siparis.php?id=<?= (int) $o['id'] ?>">
            <td><a href="/yonetim/siparis.php?id=<?= (int) $o['id'] ?>"><strong>#<?= (int) $o['order_number'] ?></strong></a><br><small class="muted"><?= e(format_date($o['created_at'], true)) ?></small></td>
            <td><?= e($o['first_name'] . ' ' . $o['last_name']) ?><br><small class="muted"><?= e(payment_label($o['payment_method'])) ?></small></td>
            <td class="num"><?= e(format_try((float) $o['total_try'])) ?></td>
            <td><?= status_pill($o['status']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>

  <div class="stack">
    <section class="card">
      <header class="card-head"><h2>Döviz kuru</h2></header>
      <p class="big">1 USD = <?= e(format_rate($rate['rate'])) ?> TL</p>
      <p class="muted">
        <?= e(match ($rate['source']) { 'manual' => 'Elle girilen kur', 'tcmb_banknote_selling' => 'TCMB efektif satış', 'tcmb_forex_selling' => 'TCMB döviz satış', default => 'Yedek kur — lütfen güncelleyin' }) ?>
        <?= $rate['date'] && $rate['source'] !== 'manual' ? ' · bülten ' . e($rate['date']) : '' ?>
        <?= (float) $s['markupPercent'] ? ' · kâr payı %' . e($s['markupPercent']) : '' ?>
      </p>
      <p class="muted small">Her gün 10:00'da otomatik güncellenir<?= $s['rateUpdatedAt'] ? '. Son güncelleme: ' . e(format_date($s['rateUpdatedAt'], true)) : '' ?>.</p>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="rate"><button class="btn btn-secondary btn-sm" type="submit">Kuru şimdi güncelle</button></form>
    </section>
    <section class="card">
      <header class="card-head"><h2>Hızlı bakış</h2></header>
      <ul class="quick">
        <li><a href="/yonetim/mesajlar.php">Yeni iletişim mesajı</a><b><?= $messages ?></b></li>
        <li><a href="/yonetim/urunler.php">Stokta olmayan ürün</a><b><?= $outOfStock ?></b></li>
        <li><a href="/yonetim/yayin.php">Yayın durumu</a><b>→</b></li>
      </ul>
    </section>
  </div>
</div>
<?php
admin_footer();
