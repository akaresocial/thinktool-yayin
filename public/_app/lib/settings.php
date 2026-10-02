<?php

declare(strict_types=1);

/** Site ayarlarının varsayılanları. Kayıtlı değerler bunların üzerine yazılır. */
function settings_defaults(): array
{
    return [
        'brandName' => 'Thinktool Türkiye',
        'legalName' => 'Hedef Diagnostik Teknoloji Bilişim Mekatronik İthalat İhracat Sanayi ve Ticaret Limited Şirketi',
        'taxOffice' => '',
        'taxNumber' => '',
        'about' => '',
        'phone' => '0850 346 58 18',
        'mobile' => '0532 459 51 90',
        'whatsappMessage' => 'Merhaba, ürünleriniz hakkında bilgi almak istiyorum.',
        'email' => 'info@thinktool.com.tr',
        'supportEmail' => 'destek@thinktool.com.tr',
        'address' => '',
        'mapsUrl' => '',
        'social' => ['instagram' => '', 'youtube' => '', 'facebook' => '', 'linkedin' => ''],
        'orderNotificationEmails' => [],
        'contactNotificationEmails' => ['info@thinktool.com.tr'],
        'mailFrom' => 'info@thinktool.com.tr',
        'cardEnabled' => true,
        'cardDescription' => 'Ödeme PayTR güvencesiyle alınır.',
        // Peşin fiyatına (vade farksız) taksit sayısı: sitede "Peşin fiyatına 3 taksit · 3 × ₺…" olarak gösterilir; 0 = gösterme.
        // Vade farkını mağazanın üstlenmesi PayTR panelinde (taksit ayarları) yapılır.
        'cashInstallments' => 3,
        // Kartla ödemede PayTR'ye gönderilen en fazla taksit (max_installment): 0 = PayTR'deki tüm seçenekler, 1 = tek çekim
        'installmentLimit' => 0,
        'installmentTableToken' => '',
        'transferEnabled' => true,
        'transferDescription' => 'Siparişinizin ardından IBAN bilgileri gösterilir. Açıklamaya sipariş numaranızı yazın.',
        'bankAccounts' => [],
        'shippingNote' => '',
        'priceNote' => '',
        'rateSource' => 'tcmb_forex_selling',
        'manualRate' => null,
        'markupPercent' => 0,
        'roundTo' => 1,
        'tcmbForexSelling' => null,
        'tcmbBanknoteSelling' => null,
        'tcmbDate' => '',
        'rateUpdatedAt' => '',
        'announcement' => ['enabled' => false, 'text' => ''],
        // Ana sayfa vitrini (hero slaytı): sırayla en fazla 3 ürün kimliği; boşsa varsayılan seçim
        'heroProducts' => [],
        'gtmId' => '',
        'googleSiteVerification' => '',
        'googleAdsId' => '',
        'googleAdsPurchaseLabel' => '',
        'defaultTitle' => '',
        'defaultDescription' => '',
    ];
}

/** Siteye (statik derlemeye) aktarılmayan, yalnızca sunucuda kalan ayarlar. */
const SETTINGS_PRIVATE = ['orderNotificationEmails', 'contactNotificationEmails', 'mailFrom'];

function settings(bool $fresh = false): array
{
    static $cache = null;
    if ($cache === null || $fresh) {
        $raw = q_val("SELECT value FROM settings WHERE key = 'site'");
        $saved = $raw ? (json_decode((string) $raw, true) ?: []) : [];
        $cache = array_replace(settings_defaults(), $saved);
    }
    return $cache;
}

/** Ayarları kısmen günceller. Herkese açık bir ayar değiştiyse site yeniden derlenmek üzere işaretlenir. */
function settings_save(array $patch): array
{
    $before = settings(true);
    $next = array_replace($before, array_intersect_key($patch, settings_defaults()));
    q("INSERT INTO settings (key, value) VALUES ('site', ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value", [json_encode($next, JSON_UNESCAPED_UNICODE)]);
    $publicChanged = false;
    foreach ($patch as $k => $v) {
        if (!in_array($k, SETTINGS_PRIVATE, true) && ($before[$k] ?? null) !== ($next[$k] ?? null)) {
            $publicChanged = true;
        }
    }
    if ($publicChanged) {
        content_touch();
    }
    return settings(true);
}

function settings_public(): array
{
    return array_diff_key(settings(), array_flip(SETTINGS_PRIVATE));
}

/** PayTR mağaza bilgileri — yalnızca veritabanında, asla siteye aktarılmaz. */
function paytr_settings(): array
{
    $raw = q_val("SELECT value FROM settings WHERE key = 'paytr'");
    $saved = $raw ? (json_decode((string) $raw, true) ?: []) : [];
    return array_replace([
        'merchantId' => '',
        'merchantKey' => '',
        'merchantSalt' => '',
        'testMode' => false,
        'integration' => 'auto',
    ], $saved);
}

function paytr_settings_save(array $patch): void
{
    $next = array_replace(paytr_settings(), $patch);
    q("INSERT INTO settings (key, value) VALUES ('paytr', ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value", [json_encode($next, JSON_UNESCAPED_UNICODE)]);
}
