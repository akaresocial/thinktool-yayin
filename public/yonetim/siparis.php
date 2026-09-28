<?php

declare(strict_types=1);

require __DIR__ . '/_inc/admin.php';
$user = require_login();

$id = (int) ($_GET['id'] ?? 0);
$o = order_by_id($id);
if (!$o) {
    flash('error', 'Sipariş bulunamadı.');
    redirect('/yonetim/siparisler.php');
}

if (is_post()) {
    csrf_verify();
    try {
        if (p('action') === 'delete') {
            if (!in_array($o['status'], ['pending_payment', 'payment_failed', 'cancelled'], true)) {
                throw new UserError('Yalnızca ödenmemiş veya iptal edilmiş siparişler silinebilir.');
            }
            q('DELETE FROM orders WHERE id = ?', [$id]);
            flash('success', "Sipariş #{$o['orderNumber']} silindi.");
            redirect('/yonetim/siparisler.php');
        }
        if (p('action') === 'resend') {
            order_notifications($o, null);
            flash('success', 'E-postalar yeniden gönderildi.');
            redirect("/yonetim/siparis.php?id=$id");
        }
        $status = p_str('status', 40);
        $patch = [
            'adminNote' => p_text('adminNote', 5000),
            'carrier' => array_key_exists(p_str('carrier', 20), CARRIERS) ? p_str('carrier', 20) : '',
            'trackingNumber' => p_str('trackingNumber', 80),
            'trackingUrl' => filter_var(p_str('trackingUrl', 300), FILTER_VALIDATE_URL) ? p_str('trackingUrl', 300) : '',
        ];
        if (isset(ORDER_STATUSES[$status])) {
            $patch['status'] = $status;
        }
        if (($patch['status'] ?? '') === 'shipped' && $patch['trackingNumber'] === '' && $patch['trackingUrl'] === '' && $patch['carrier'] !== 'other') {
            throw new UserError('Kargoya verildi durumu için kargo firması ve takip numarası girin.');
        }
        $after = order_update($id, $patch, true, $user['name']);
        flash('success', $after['status'] !== $o['status'] ? 'Sipariş güncellendi: ' . status_label($after['status']) . '. Gerekli e-postalar gönderildi.' : 'Sipariş kaydedildi.');
    } catch (UserError $e) {
        flash('error', $e->getMessage());
    }
    redirect("/yonetim/siparis.php?id=$id");
}

$events = q_all('SELECT * FROM order_events WHERE order_id = ? ORDER BY id DESC', [$id]);
$a = $o['shippingAddress'];
$inv = $o['invoice'];
$pt = $o['paytr'];
$customerUrl = '/siparis/' . $o['orderNumber'] . '?k=' . rawurlencode($o['accessKey']);

admin_header('Sipariş #' . $o['orderNumber'], 'siparisler');
?>
<div class="page-head">
  <div>
    <p class="eyebrow"><a href="/yonetim/siparisler.php">← Siparişler</a></p>
    <h1>#<?= $o['orderNumber'] ?> <small><?= e($o['customerName']) ?></small></h1>
    <p class="muted"><?= e(format_date($o['createdAt'], true)) ?> · <?= e(payment_label($o['paymentMethod'])) ?> · <?= status_pill($o['status']) ?></p>
  </div>
  <div class="actions"><a class="btn btn-ghost" href="<?= e($customerUrl) ?>" target="_blank" rel="noopener">Müşteri sayfası ↗</a></div>
</div>

<div class="grid-main">
  <div class="stack">
    <section class="card">
      <header class="card-head"><h2>Ürünler</h2></header>
      <table class="table">
        <thead><tr><th>Ürün</th><th class="num">Adet</th><th class="num">Birim</th><th class="num">Tutar</th></tr></thead>
        <tbody>
        <?php foreach ($o['items'] as $i): ?>
          <tr>
            <td><?= $i['productId'] ? '<a href="/yonetim/urun.php?id=' . (int) $i['productId'] . '">' . e($i['title']) . '</a>' : e($i['title']) ?><br><small class="muted"><?= e(format_usd($i['unitPriceUsd'])) ?> × kur</small></td>
            <td class="num"><?= (int) $i['quantity'] ?></td>
            <td class="num"><?= e(format_try($i['unitPriceTry'])) ?></td>
            <td class="num"><?= e(format_try($i['lineTotalTry'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr><td colspan="3">Kargo</td><td class="num">Ücretsiz</td></tr>
          <tr><td colspan="3"><strong>Toplam</strong><br><small class="muted">Kur: 1 USD = <?= e(format_rate($o['exchangeRate'])) ?> TL · <?= e(format_usd($o['subtotalUsd'])) ?></small></td><td class="num"><strong><?= e(format_try($o['totalTry'])) ?></strong></td></tr>
        </tfoot>
      </table>
    </section>

    <section class="card">
      <header class="card-head"><h2>Müşteri</h2></header>
      <div class="grid-2 tight">
        <div>
          <p class="label">Teslimat</p>
          <p><strong><?= e($o['customerName']) ?></strong><br><?= e($a['address'] ?? '') ?><br><?= e(($a['district'] ?? '') . ' / ' . ($a['city'] ?? '') . ' ' . ($a['postalCode'] ?? '')) ?></p>
          <p><a href="tel:<?= e($o['phone']) ?>"><?= e($o['phone']) ?></a><br><a href="mailto:<?= e($o['email']) ?>"><?= e($o['email']) ?></a></p>
          <p><a class="btn btn-sm btn-whatsapp" href="<?= e(whatsapp_href($o['phone'], "Merhaba {$o['firstName']}, #{$o['orderNumber']} numaralı siparişiniz hakkında yazıyoruz.")) ?>" target="_blank" rel="noopener">WhatsApp</a></p>
        </div>
        <div>
          <p class="label">Fatura</p>
          <?php if (($inv['type'] ?? '') === 'corporate'): ?>
            <p><strong><?= e($inv['companyName'] ?? '') ?></strong><br><?= e(($inv['taxOffice'] ?? '') . ' V.D. · ' . ($inv['taxNumber'] ?? '')) ?></p>
          <?php else: ?>
            <p><strong>Bireysel</strong><?= !empty($inv['identityNumber']) ? '<br>T.C. ' . e($inv['identityNumber']) : '' ?></p>
          <?php endif; ?>
          <p><?= !empty($inv['sameAsShipping']) ? 'Teslimat adresiyle aynı' : e($inv['address'] ?? '') ?></p>
          <?php if ($o['customerNote']): ?><p class="label">Müşteri notu</p><p class="note"><?= nl2br(e($o['customerNote'])) ?></p><?php endif; ?>
        </div>
      </div>
    </section>

    <?php if ($o['paymentMethod'] === 'card'): ?>
    <section class="card">
      <header class="card-head"><h2>PayTR ödeme kaydı</h2></header>
      <dl class="dl">
        <dt>Ödeme referansı</dt><dd><?= e($pt['merchantOid'] ?? '—') ?></dd>
        <dt>Yöntem</dt><dd><?= e(match ($pt['integration'] ?? '') { 'link' => 'Ödeme linki (Link API)', 'iframe' => 'Gömülü form (iFrame API)', default => '—' }) ?></dd>
        <dt>PayTR işlem no</dt><dd><?= e($pt['transactionId'] ?? '—') ?></dd>
        <dt>Sonuç</dt><dd><?= e($pt['status'] ?? '—') ?><?= !empty($pt['failedReason']) ? ' · ' . e($pt['failedReason']) : '' ?></dd>
        <dt>Tahsil edilen</dt><dd><?= isset($pt['totalAmount']) ? e(format_try_exact((float) $pt['totalAmount'])) : '—' ?><?= !empty($pt['installmentCount']) && $pt['installmentCount'] > 1 ? ' · ' . (int) $pt['installmentCount'] . ' taksit' : '' ?></dd>
        <dt>Ödeme linki</dt><dd><?= !empty($pt['linkUrl']) ? '<a href="' . e($pt['linkUrl']) . '" target="_blank" rel="noopener">' . e($pt['linkUrl']) . '</a>' : '—' ?></dd>
        <dt>Deneme</dt><dd><?= (int) ($pt['attempts'] ?? 0) ?><?= !empty($pt['testMode']) ? ' · TEST işlemi' : '' ?></dd>
      </dl>
    </section>
    <?php endif; ?>

    <section class="card">
      <header class="card-head"><h2>Geçmiş</h2></header>
      <ul class="timeline">
        <?php foreach ($events as $ev): ?>
          <li><span class="muted small"><?= e(format_date($ev['created_at'], true)) ?></span> <?= e($ev['message']) ?></li>
        <?php endforeach; ?>
      </ul>
    </section>
  </div>

  <aside class="stack sticky">
    <form method="post" class="card stack">
      <?= csrf_field() ?>
      <header class="card-head"><h2>Durum ve kargo</h2></header>
      <?= field('Sipariş durumu', select('status', ORDER_STATUSES, $o['status'])) ?>
      <?= field('Kargo firması', select('carrier', ['' => '— Seçin —'] + array_map(fn ($c) => $c[0], CARRIERS), $o['carrier'])) ?>
      <?= field('Takip numarası', input('trackingNumber', $o['trackingNumber'])) ?>
      <?= field('Takip bağlantısı (isteğe bağlı)', input('trackingUrl', $o['trackingUrl'], ['type' => 'url', 'placeholder' => 'https://']), 'Boş bırakılırsa firmanın takip sayfası kullanılır.') ?>
      <?= field('İç not (müşteri görmez)', textarea('adminNote', $o['adminNote'], 4)) ?>
      <button class="btn btn-primary btn-block" type="submit">Kaydet</button>
      <p class="muted small">"Ödendi" (havale onayı) ve "Kargoya verildi" durumlarında müşteriye otomatik e-posta gider.</p>
    </form>
    <div class="card stack">
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="resend"><button class="btn btn-secondary btn-block" type="submit" data-confirm="Bu durumun e-postaları tekrar gönderilsin mi?">E-postaları yeniden gönder</button></form>
      <?php if (in_array($o['status'], ['pending_payment', 'payment_failed', 'cancelled'], true)): ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><button class="btn btn-danger btn-block" type="submit" data-confirm="Sipariş kalıcı olarak silinsin mi?">Siparişi sil</button></form>
      <?php endif; ?>
    </div>
  </aside>
</div>
<?php
admin_footer();
