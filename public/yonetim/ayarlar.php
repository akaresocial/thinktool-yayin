<?php

declare(strict_types=1);

require __DIR__ . '/_inc/admin.php';
require_login();

$emails = fn (string $key) => array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', p_text($key, 2000)) ?: []), fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));

if (is_post()) {
    csrf_verify();
    $action = p('action');
    try {
        if ($action === 'paytr') {
            $patch = [
                'merchantId' => p_str('merchantId', 20),
                'testMode' => p_bool('testMode'),
                'integration' => in_array(p('integration'), ['auto', 'link', 'iframe'], true) ? p('integration') : 'auto',
            ];
            // Gizli anahtarlar yalnızca yeni değer girildiğinde değişir (sayfada hiçbir zaman gösterilmez).
            if (p_str('merchantKey', 100) !== '') {
                $patch['merchantKey'] = p_str('merchantKey', 100);
            }
            if (p_str('merchantSalt', 100) !== '') {
                $patch['merchantSalt'] = p_str('merchantSalt', 100);
            }
            paytr_settings_save($patch);
            meta_set('paytr_iframe_unavailable_until', '0');
            flash('success', 'PayTR bilgileri kaydedildi.');
        } elseif ($action === 'testmail') {
            $to = p_str('to', 120);
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                throw new UserError('Geçerli bir e-posta girin.');
            }
            $ok = send_mail([$to], 'Thinktool — deneme e-postası', mail_layout('Deneme e-postası', 'Deneme', '<h1 style="margin:0 0 8px;font-size:20px;">E-posta gönderimi çalışıyor ✓</h1><p style="color:#5d636b;font-size:14px;">Bu mesaj yönetim panelinden gönderilen bir denemedir. Sipariş bildirimleri de bu yolla gönderilir.</p>', 'Thinktool yönetim paneli'));
            $ok ? flash('success', "Deneme e-postası gönderildi: $to (gelen kutusu ve spam klasörünü kontrol edin)") : flash('error', 'E-posta gönderilemedi. Aşağıdaki gönderim kaydına bakın.');
        } else {
            $banks = array_values(array_filter(array_map(fn ($b) => [
                'bank' => str_in($b['bank'] ?? '', 80),
                'holder' => str_in($b['holder'] ?? '', 120),
                'currency' => in_array($b['currency'] ?? 'TRY', ['TRY', 'USD', 'EUR'], true) ? ($b['currency'] ?? 'TRY') : 'TRY',
                'iban' => strtoupper(preg_replace('/[^A-Za-z0-9 ]/', '', str_in($b['iban'] ?? '', 40)) ?? ''),
            ], p_json('bankAccounts')), fn ($b) => $b['bank'] !== '' && $b['iban'] !== ''));
            settings_save([
                'brandName' => p_str('brandName', 80),
                'legalName' => p_str('legalName', 200),
                'taxOffice' => p_str('taxOffice', 80),
                'taxNumber' => p_str('taxNumber', 20),
                'about' => p_text('about', 1000),
                'phone' => p_str('phone', 30),
                'mobile' => p_str('mobile', 30),
                'whatsappMessage' => p_str('whatsappMessage', 200),
                'email' => p_str('email', 120),
                'supportEmail' => p_str('supportEmail', 120),
                'address' => p_text('address', 300),
                'mapsUrl' => p_str('mapsUrl', 300),
                'social' => ['instagram' => p_str('social_instagram', 200), 'youtube' => p_str('social_youtube', 200), 'facebook' => p_str('social_facebook', 200), 'linkedin' => p_str('social_linkedin', 200)],
                'orderNotificationEmails' => $emails('orderNotificationEmails'),
                'contactNotificationEmails' => $emails('contactNotificationEmails'),
                'mailFrom' => filter_var(p_str('mailFrom', 120), FILTER_VALIDATE_EMAIL) ? p_str('mailFrom', 120) : 'info@thinktool.com.tr',
                'cardEnabled' => p_bool('cardEnabled'),
                'cardDescription' => p_str('cardDescription', 300),
                'maxInstallment' => max(0, min(12, (int) p('maxInstallment'))),
                'installmentTableToken' => p_str('installmentTableToken', 120),
                'transferEnabled' => p_bool('transferEnabled'),
                'transferDescription' => p_text('transferDescription', 600),
                'bankAccounts' => $banks,
                'shippingNote' => p_str('shippingNote', 300),
                'priceNote' => p_str('priceNote', 300),
                'rateSource' => in_array(p('rateSource'), ['tcmb_forex_selling', 'tcmb_banknote_selling', 'manual'], true) ? p('rateSource') : 'tcmb_forex_selling',
                'manualRate' => p_num('manualRate'),
                'markupPercent' => p_num('markupPercent') ?? 0,
                'roundTo' => in_array((int) p('roundTo'), [1, 10, 100], true) ? (int) p('roundTo') : 1,
                'announcement' => ['enabled' => p_bool('announcement_enabled'), 'text' => p_str('announcement_text', 200)],
                'gtmId' => p_str('gtmId', 30),
                'googleSiteVerification' => p_str('googleSiteVerification', 120),
                'googleAdsId' => p_str('googleAdsId', 30),
                'googleAdsPurchaseLabel' => p_str('googleAdsPurchaseLabel', 60),
                'defaultTitle' => p_str('defaultTitle', 120),
                'defaultDescription' => p_str('defaultDescription', 320),
            ]);
            flash('success', 'Ayarlar kaydedildi. Fiyat/kur değişiklikleri hemen, diğerleri birkaç dakika içinde sitede görünür.');
        }
    } catch (UserError $e) {
        flash('error', $e->getMessage());
    }
    redirect('/yonetim/ayarlar.php' . ($action ? '#' . $action : ''));
}

$s = settings(true);
$pt = paytr_settings();
$rate = rate_info($s);
$installments = [0 => 'Tüm taksit seçenekleri', 1 => 'Tek çekim'];
for ($i = 2; $i <= 12; $i++) {
    $installments[$i] = "$i taksite kadar";
}
$mailLog = q_all('SELECT * FROM mail_log ORDER BY id DESC LIMIT 12');
$iframeOff = (int) (meta_get('paytr_iframe_unavailable_until') ?? 0) > time();

admin_header('Ayarlar', 'ayarlar');
?>
<div class="page-head"><div><h1>Ayarlar</h1></div></div>
<nav class="tabs anchors">
  <a href="#firma">Firma</a><a href="#iletisim">İletişim</a><a href="#bildirim">Bildirimler</a><a href="#odeme">Ödeme</a><a href="#kur">Döviz kuru</a><a href="#pazarlama">Pazarlama</a><a href="#paytr">PayTR</a><a href="#testmail">E-posta</a>
</nav>

<form method="post" class="stack" data-serialize>
  <?= csrf_field() ?>
  <section class="card stack" id="firma">
    <header class="card-head"><h2>Firma</h2></header>
    <div class="row"><?= field('Marka adı', input('brandName', $s['brandName'])) ?><?= field('Ticari ünvan', input('legalName', $s['legalName']), 'Sözleşmelerde "Satıcı" olarak görünür.') ?></div>
    <div class="row"><?= field('Vergi dairesi', input('taxOffice', $s['taxOffice'])) ?><?= field('Vergi numarası', input('taxNumber', $s['taxNumber']), 'Girilirse sözleşmelerde otomatik görünür.') ?></div>
    <?= field('Kısa tanıtım', textarea('about', $s['about'], 3), 'Site alt bilgisinde görünür.') ?>
  </section>

  <section class="card stack" id="iletisim">
    <header class="card-head"><h2>İletişim</h2></header>
    <div class="row"><?= field('Telefon', input('phone', $s['phone'])) ?><?= field('GSM / WhatsApp', input('mobile', $s['mobile'])) ?></div>
    <?= field('WhatsApp hazır mesajı', input('whatsappMessage', $s['whatsappMessage'])) ?>
    <div class="row"><?= field('E-posta', input('email', $s['email'], ['type' => 'email'])) ?><?= field('Destek e-postası', input('supportEmail', $s['supportEmail'], ['type' => 'email'])) ?></div>
    <?= field('Adres', textarea('address', $s['address'], 2)) ?>
    <?= field('Google Haritalar bağlantısı', input('mapsUrl', $s['mapsUrl'])) ?>
    <div class="row">
      <?= field('Instagram', input('social_instagram', $s['social']['instagram'] ?? '')) ?>
      <?= field('YouTube', input('social_youtube', $s['social']['youtube'] ?? '')) ?>
      <?= field('Facebook', input('social_facebook', $s['social']['facebook'] ?? '')) ?>
      <?= field('LinkedIn', input('social_linkedin', $s['social']['linkedin'] ?? '')) ?>
    </div>
  </section>

  <section class="card stack" id="bildirim">
    <header class="card-head"><h2>E-posta bildirimleri</h2></header>
    <?= field('Sipariş bildirimleri gidecek adresler', textarea('orderNotificationEmails', implode("\n", $s['orderNotificationEmails']), 3), 'Her satıra bir adres. Her yeni sipariş ve kartla ödeme bu adreslere gider.') ?>
    <?= field('İletişim formu mesajları gidecek adresler', textarea('contactNotificationEmails', implode("\n", $s['contactNotificationEmails']), 2)) ?>
    <?= field('Gönderen adres', input('mailFrom', $s['mailFrom'], ['type' => 'email']), 'Hostingde tanımlı bir e-posta hesabı olmalı (ör. info@thinktool.com.tr).') ?>
  </section>

  <section class="card stack" id="odeme">
    <header class="card-head"><h2>Ödeme</h2></header>
    <?= checkbox('cardEnabled', (bool) $s['cardEnabled'], 'Kartla ödeme açık (PayTR)') ?>
    <div class="row"><?= field('Kart açıklaması', input('cardDescription', $s['cardDescription'])) ?><?= field('Taksit', select('maxInstallment', $installments, $s['maxInstallment'])) ?></div>
    <?= field('PayTR taksit tablosu token (isteğe bağlı)', input('installmentTableToken', $s['installmentTableToken'])) ?>
    <?= checkbox('transferEnabled', (bool) $s['transferEnabled'], 'Havale/EFT açık') ?>
    <?= field('Havale açıklaması', textarea('transferDescription', $s['transferDescription'], 2)) ?>
    <input type="hidden" name="bankAccounts" value="<?= e(json_encode($s['bankAccounts'], JSON_UNESCAPED_UNICODE)) ?>">
    <p class="field-label">Banka hesapları</p>
    <div data-repeater="bankAccounts" data-fields='[{"name":"bank","label":"Banka","width":"20%"},{"name":"holder","label":"Hesap sahibi","width":"28%"},{"name":"currency","label":"Para birimi","width":"12%","placeholder":"TRY"},{"name":"iban","label":"IBAN"}]'></div>
    <div class="row"><?= field('Kargo notu', input('shippingNote', $s['shippingNote'])) ?><?= field('Fiyat notu', input('priceNote', $s['priceNote']), 'Ör. "Fiyatlara KDV dahildir."') ?></div>
  </section>

  <section class="card stack" id="kur">
    <header class="card-head"><h2>Döviz kuru</h2><span class="muted">Şu an 1 USD = <strong><?= e(format_rate($rate['rate'])) ?> TL</strong></span></header>
    <div class="row">
      <?= field('Kur kaynağı', select('rateSource', ['tcmb_forex_selling' => 'TCMB döviz satış', 'tcmb_banknote_selling' => 'TCMB efektif satış', 'manual' => 'Elle girilen kur'], $s['rateSource'])) ?>
      <?= field('Elle kur (TL)', input('manualRate', $s['manualRate'] ?? '', ['inputmode' => 'decimal']), 'Yalnızca "Elle girilen kur" seçiliyse kullanılır.') ?>
      <?= field('Kâr payı (%)', input('markupPercent', $s['markupPercent'], ['inputmode' => 'decimal']), 'Kura eklenir. Ör. 2 → %2 yüksek.') ?>
      <?= field('Yuvarlama', select('roundTo', [1 => '1 TL', 10 => '10 TL', 100 => '100 TL'], $s['roundTo'])) ?>
    </div>
    <p class="muted small">TCMB döviz satış: <?= $s['tcmbForexSelling'] ? e(format_rate((float) $s['tcmbForexSelling'])) : '—' ?> · efektif satış: <?= $s['tcmbBanknoteSelling'] ? e(format_rate((float) $s['tcmbBanknoteSelling'])) : '—' ?> · bülten: <?= e($s['tcmbDate'] ?: '—') ?> · güncelleme: <?= $s['rateUpdatedAt'] ? e(format_date($s['rateUpdatedAt'], true)) : '—' ?>. Kur her gün 10:00'da otomatik güncellenir; hemen güncellemek için Panel sayfasındaki düğmeyi kullanın.</p>
  </section>

  <section class="card stack" id="pazarlama">
    <header class="card-head"><h2>Pazarlama ve SEO</h2></header>
    <div class="row"><?= checkbox('announcement_enabled', (bool) ($s['announcement']['enabled'] ?? false), 'Duyuru çubuğunu göster') ?></div>
    <?= field('Duyuru metni', input('announcement_text', $s['announcement']['text'] ?? ''), 'Boşsa kargo/taksit/güncelleme bilgileri gösterilir.') ?>
    <div class="row">
      <?= field('Google Tag Manager', input('gtmId', $s['gtmId'], ['placeholder' => 'GTM-XXXXXXX'])) ?>
      <?= field('Google Ads kimliği', input('googleAdsId', $s['googleAdsId'], ['placeholder' => 'AW-XXXXXXXXX'])) ?>
      <?= field('Ads satın alma etiketi', input('googleAdsPurchaseLabel', $s['googleAdsPurchaseLabel'])) ?>
    </div>
    <?= field('Search Console doğrulama kodu', input('googleSiteVerification', $s['googleSiteVerification'])) ?>
    <?= field('Varsayılan sayfa başlığı', input('defaultTitle', $s['defaultTitle'])) ?>
    <?= field('Varsayılan açıklama', textarea('defaultDescription', $s['defaultDescription'], 2)) ?>
  </section>

  <div class="save-bar"><button class="btn btn-primary" type="submit">Ayarları kaydet</button></div>
</form>

<form method="post" class="card stack" id="paytr" autocomplete="off">
  <?= csrf_field() ?><input type="hidden" name="action" value="paytr">
  <header class="card-head"><h2>PayTR mağaza bilgileri</h2>
    <span class="muted small"><?= $pt['merchantKey'] !== '' ? '✓ Tanımlı' : 'Tanımlı değil' ?> · <?= $iframeOff ? 'Mağazada yalnızca Link API açık → ödeme linki kullanılıyor' : 'Mod: ' . e($pt['integration']) ?></span></header>
  <p class="muted small">PayTR Mağaza Paneli › Bilgi sayfasındaki değerler. Anahtar ve gizli anahtar bu sayfada asla gösterilmez; değiştirmek için yeni değeri girin.</p>
  <div class="row">
    <?= field('Mağaza no (merchant_id)', input('merchantId', $pt['merchantId'], ['inputmode' => 'numeric'])) ?>
    <?= field('Mağaza parolası (merchant_key)', input('merchantKey', '', ['type' => 'password', 'placeholder' => $pt['merchantKey'] !== '' ? '•••••••• (değiştirmek için yazın)' : '', 'autocomplete' => 'new-password'])) ?>
    <?= field('Mağaza gizli anahtarı (merchant_salt)', input('merchantSalt', '', ['type' => 'password', 'placeholder' => $pt['merchantSalt'] !== '' ? '•••••••• (değiştirmek için yazın)' : '', 'autocomplete' => 'new-password'])) ?>
  </div>
  <div class="row">
    <?= field('Entegrasyon', select('integration', ['auto' => 'Otomatik (önerilen)', 'link' => 'Yalnızca ödeme linki', 'iframe' => 'Yalnızca gömülü form'], $pt['integration'])) ?>
    <?= checkbox('testMode', (bool) $pt['testMode'], 'Test modu (yalnızca gömülü formda geçerli)') ?>
  </div>
  <p class="muted small">Bildirim URL'si: <code><?= e(site_url('/api/paytr/callback.php')) ?></code> — PayTR panelindeki eski adres de çalışmaya devam eder.</p>
  <button class="btn btn-secondary" type="submit">PayTR bilgilerini kaydet</button>
</form>

<form method="post" class="card stack" id="testmail">
  <?= csrf_field() ?><input type="hidden" name="action" value="testmail">
  <header class="card-head"><h2>E-posta gönderimi</h2></header>
  <div class="row"><?= field('Deneme e-postası gönder', input('to', $s['orderNotificationEmails'][0] ?? '', ['type' => 'email'])) ?><div class="field"><span class="field-label">&nbsp;</span><button class="btn btn-secondary" type="submit">Gönder</button></div></div>
  <?php if ($mailLog): ?>
  <table class="table compact">
    <thead><tr><th>Zaman</th><th>Alıcı</th><th>Konu</th><th>Durum</th></tr></thead>
    <tbody><?php foreach ($mailLog as $m): ?><tr><td class="small"><?= e(format_date($m['created_at'], true)) ?></td><td class="small"><?= e($m['recipients']) ?></td><td class="small"><?= e($m['subject']) ?></td><td><?= $m['status'] === 'failed' ? '<span class="pill pill-bad" title="' . e($m['error']) . '">Başarısız</span>' : ($m['status'] === 'preview' ? '<span class="pill pill-muted">Önizleme</span>' : '<span class="pill pill-ok">Gönderildi</span>') ?></td></tr><?php endforeach; ?></tbody>
  </table>
  <?php endif; ?>
</form>
<?php
admin_footer();
