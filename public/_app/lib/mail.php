<?php

declare(strict_types=1);

/**
 * E-posta gönderimi. Sunucudaki posta sistemi (PHP mail) kullanılır: şifre gerekmez, alan adının
 * SPF/DKIM kayıtları hostingde tanımlı olduğundan gelen kutusuna düşme oranı yüksektir.
 * Yerel geliştirmede (TT_ENV=development) e-postalar DATA_DIR/mails klasörüne HTML olarak yazılır.
 */
function send_mail(array $to, string $subject, string $html, array $opts = []): bool
{
    $to = array_values(array_unique(array_filter(array_map('trim', $to), fn ($a) => filter_var($a, FILTER_VALIDATE_EMAIL))));
    if (!$to) {
        return false;
    }
    $s = settings();
    $from = filter_var($s['mailFrom'] ?? '', FILTER_VALIDATE_EMAIL) ? $s['mailFrom'] : 'info@thinktool.com.tr';
    $fromName = $s['brandName'] ?: 'Thinktool Türkiye';
    $status = 'sent';
    $error = '';

    if (APP_ENV === 'development' || is_file(DATA_DIR . '/mail-preview')) {
        $file = DATA_DIR . '/mails/' . date('Ymd-His') . '-' . substr(slugify($subject), 0, 60) . '-' . bin2hex(random_bytes(2)) . '.html';
        file_put_contents($file, "<!-- To: " . e(implode(', ', $to)) . " | Subject: " . e($subject) . " -->\n" . $html);
        $status = 'preview';
    } else {
        $boundary = 'tt-' . bin2hex(random_bytes(12));
        $text = mail_text_version($html);
        $headers = [
            'MIME-Version: 1.0',
            'From: ' . mb_encode_mimeheader($fromName, 'UTF-8', 'B') . " <$from>",
            'Reply-To: ' . (filter_var($opts['replyTo'] ?? '', FILTER_VALIDATE_EMAIL) ? $opts['replyTo'] : $from),
            "Content-Type: multipart/alternative; boundary=\"$boundary\"",
            'X-Mailer: thinktool.com.tr',
        ];
        $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text))
            . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html))
            . "--$boundary--\r\n";
        $ok = @mail(implode(', ', $to), mb_encode_mimeheader($subject, 'UTF-8', 'B'), $body, implode("\r\n", $headers), '-f' . $from);
        if (!$ok) {
            $status = 'failed';
            $error = (string) (error_get_last()['message'] ?? 'mail() başarısız');
        }
    }
    q('INSERT INTO mail_log (recipients, subject, status, error, created_at) VALUES (?,?,?,?,?)', [implode(', ', $to), $subject, $status, $error, now_iso()]);
    if ($status === 'failed') {
        log_app("E-posta gönderilemedi: $subject → " . implode(', ', $to) . " ($error)", 'mail');
    }
    return $status !== 'failed';
}

function mail_text_version(string $html): string
{
    $t = preg_replace('/<(br|\/p|\/tr|\/h[1-6]|\/div)[^>]*>/i', "\n", $html) ?? $html;
    $t = preg_replace('/<a [^>]*href="([^"]+)"[^>]*>(.*?)<\/a>/is', '$2 ($1)', $t) ?? $t;
    $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace("/[ \t]+/", ' ', preg_replace("/\n\s*\n+/", "\n\n", $t) ?? $t) ?? $t);
}

/* ───────────────────────── Şablonlar ───────────────────────── */

const MAIL_BRAND = '#9f1d24';
const MAIL_INK = '#16181b';
const MAIL_MUTED = '#5d636b';
const MAIL_LINE = '#e7e4de';

function mail_layout(string $preheader, string $title, string $body, ?string $footer = null): string
{
    $logo = site_url('/brand/logo-email.png');
    $footer ??= 'Bu e-posta ' . e(preg_replace('#^https?://#', '', site_url())) . ' üzerinden verdiğiniz sipariş nedeniyle gönderildi.';
    return '<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e($title) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#f4f2ee;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:' . MAIL_INK . ';">'
        . '<span style="display:none;max-height:0;overflow:hidden;opacity:0">' . e($preheader) . '</span>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f2ee;padding:24px 12px;"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border:1px solid ' . MAIL_LINE . ';border-radius:14px;overflow:hidden;">'
        . '<tr><td style="padding:22px 28px;border-bottom:1px solid ' . MAIL_LINE . ';"><img src="' . e($logo) . '" width="170" height="28" alt="Thinktool Türkiye" style="display:block;border:0;"></td></tr>'
        . '<tr><td style="padding:28px;">' . $body . '</td></tr>'
        . '<tr><td style="padding:18px 28px;background:#faf9f6;border-top:1px solid ' . MAIL_LINE . ';font-size:12px;line-height:18px;color:' . MAIL_MUTED . ';">' . $footer . '</td></tr>'
        . '</table></td></tr></table></body></html>';
}

function mail_button(string $href, string $label): string
{
    return '<a href="' . e($href) . '" style="display:inline-block;background:' . MAIL_BRAND . ';color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;padding:12px 20px;border-radius:10px;">' . e($label) . '</a>';
}

function mail_items_table(array $o): string
{
    $rows = '';
    foreach ($o['items'] as $i) {
        $rows .= '<tr><td style="padding:10px 0;border-bottom:1px solid ' . MAIL_LINE . ';font-size:14px;">' . e($i['title'])
            . '<br><span style="color:' . MAIL_MUTED . ';font-size:12px;">' . (int) $i['quantity'] . ' adet</span></td>'
            . '<td align="right" style="padding:10px 0;border-bottom:1px solid ' . MAIL_LINE . ';font-size:14px;white-space:nowrap;">' . format_try($i['lineTotalTry']) . '</td></tr>';
    }
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:16px 0 8px;">' . $rows
        . '<tr><td style="padding:10px 0 2px;font-size:13px;color:' . MAIL_MUTED . ';">Kargo</td><td align="right" style="padding:10px 0 2px;font-size:13px;color:' . MAIL_MUTED . ';">Ücretsiz</td></tr>'
        . '<tr><td style="padding:6px 0;font-size:16px;font-weight:700;">Toplam</td><td align="right" style="padding:6px 0;font-size:16px;font-weight:700;">' . format_try($o['totalTry']) . '</td></tr>'
        . ($o['exchangeRate'] ? '<tr><td colspan="2" style="font-size:12px;color:' . MAIL_MUTED . ';">Uygulanan kur: 1 USD = ' . format_rate($o['exchangeRate']) . ' TL</td></tr>' : '')
        . '</table>';
}

function mail_address_block(array $o): string
{
    $a = $o['shippingAddress'];
    $inv = $o['invoice'];
    $invoiceLine = ($inv['type'] ?? '') === 'corporate'
        ? e($inv['companyName'] ?? '') . ' · ' . e($inv['taxOffice'] ?? '') . ' V.D. · ' . e($inv['taxNumber'] ?? '')
        : (!empty($inv['identityNumber']) ? 'Bireysel · T.C. ' . e($inv['identityNumber']) : 'Bireysel');
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:18px;font-size:13px;line-height:20px;"><tr>'
        . '<td valign="top" style="padding-right:12px;width:50%;"><div style="color:' . MAIL_MUTED . ';font-size:11px;letter-spacing:.06em;text-transform:uppercase;margin-bottom:4px;">Teslimat</div>'
        . '<strong>' . e($o['customerName']) . '</strong><br>' . e($a['address'] ?? '') . '<br>' . e($a['district'] ?? '') . ' / ' . e($a['city'] ?? '') . ' ' . e($a['postalCode'] ?? '') . '<br>' . e($o['phone']) . '<br>' . e($o['email']) . '</td>'
        . '<td valign="top" style="width:50%;"><div style="color:' . MAIL_MUTED . ';font-size:11px;letter-spacing:.06em;text-transform:uppercase;margin-bottom:4px;">Fatura</div>'
        . $invoiceLine . (empty($inv['sameAsShipping']) && !empty($inv['address']) ? '<br>' . e($inv['address']) : '') . '</td></tr></table>';
}

function mail_bank_block(array $s, array $o): string
{
    if (!$s['bankAccounts']) {
        return '';
    }
    $list = '';
    foreach ($s['bankAccounts'] as $b) {
        $list .= '<div style="padding:12px 14px;border:1px solid ' . MAIL_LINE . ';border-radius:10px;margin-top:8px;font-size:13px;line-height:20px;"><strong>' . e($b['bank']) . '</strong> · ' . e($b['currency'] ?? 'TRY')
            . '<br>' . e($b['holder']) . '<br><span style="font-family:ui-monospace,Menlo,monospace;font-size:14px;">' . e($b['iban']) . '</span></div>';
    }
    return '<div style="margin:20px 0;padding:16px;background:#fbf6f0;border-radius:12px;"><div style="font-weight:700;margin-bottom:4px;">Havale / EFT bilgileri</div>'
        . '<div style="font-size:13px;color:' . MAIL_MUTED . ';">Açıklama kısmına <strong style="color:' . MAIL_INK . '">#' . $o['orderNumber'] . '</strong> yazmayı unutmayın. Ödemeniz onaylanınca siparişiniz hazırlanır.</div>' . $list . '</div>';
}

/** Sipariş e-postaları: [konu, html] */
function render_order_email(string $variant, array $o, array $s, string $note = ''): array
{
    $orderUrl = site_url('/siparis/' . $o['orderNumber'] . '?k=' . rawurlencode($o['accessKey']));
    $adminUrl = site_url('/yonetim/siparis.php?id=' . $o['id']);
    $no = '#' . $o['orderNumber'];
    $total = format_try($o['totalTry']);
    $phone = $s['mobile'] ?: $s['phone'];
    $muted = 'color:' . MAIL_MUTED . ';';

    switch ($variant) {
        case 'admin-new-order':
        case 'admin-paid':
            $paid = $variant === 'admin-paid';
            $subject = $paid ? "Ödeme alındı $no — $total ({$o['customerName']})" : "Yeni sipariş $no — $total · Havale bekleniyor ({$o['customerName']})";
            $inst = (int) ($o['paytr']['installmentCount'] ?? 0);
            $body = '<h1 style="margin:0 0 6px;font-size:20px;">' . ($paid ? 'Kartla ödeme alındı' : 'Yeni havale/EFT siparişi') . '</h1>'
                . '<p style="margin:0;' . $muted . 'font-size:14px;">Sipariş ' . $no . ' · ' . format_date($o['createdAt'], true) . ' · ' . e(payment_label($o['paymentMethod'])) . ($inst > 1 ? " · $inst taksit" : '') . '</p>'
                . mail_items_table($o) . mail_address_block($o)
                . ($o['customerNote'] ? '<div style="margin-top:16px;padding:12px 14px;background:#faf9f6;border-radius:10px;font-size:13px;"><strong>Müşteri notu:</strong> ' . e($o['customerNote']) . '</div>' : '')
                . '<div style="margin-top:22px;">' . mail_button($adminUrl, 'Siparişi panelde aç') . '</div>';
            return [$subject, mail_layout($subject, $subject, $body)];

        case 'admin-payment-alert':
            $subject = "Kontrol gerekiyor: ödeme bildirimi $no ({$o['customerName']})";
            $body = '<h1 style="margin:0 0 6px;font-size:20px;">Ödeme bildirimi kontrol gerektiriyor</h1>'
                . '<p style="margin:0 0 16px;' . $muted . 'font-size:14px;">Sipariş ' . $no . ' · ' . e(payment_label($o['paymentMethod'])) . '</p>'
                . '<div style="padding:14px 16px;background:#fbf1f1;border:1px solid #f0d4d4;border-radius:12px;font-size:14px;line-height:21px;">' . e($note) . '</div>'
                . '<p style="font-size:13px;' . $muted . 'margin-top:16px;">İşlemi PayTR Mağaza Paneli &gt; İşlemler bölümünden doğrulayın; gerekirse iade veya düzeltme yapın.</p>'
                . mail_items_table($o) . '<div style="margin-top:22px;">' . mail_button($adminUrl, 'Siparişi panelde aç') . '</div>';
            return [$subject, mail_layout($note, $subject, $body)];

        case 'customer-transfer':
            $subject = "Siparişiniz alındı $no — ödeme bekleniyor";
            $body = '<h1 style="margin:0 0 8px;font-size:22px;">Teşekkürler ' . e($o['firstName']) . ', siparişiniz alındı.</h1>'
                . '<p style="margin:0;' . $muted . 'font-size:14px;line-height:22px;">Sipariş numaranız <strong style="color:' . MAIL_INK . '">' . $no . '</strong>. Aşağıdaki hesaba <strong style="color:' . MAIL_INK . '">' . $total . '</strong> tutarında havale/EFT yaptığınızda siparişiniz hazırlanmaya başlar.</p>'
                . mail_bank_block($s, $o) . mail_items_table($o) . mail_address_block($o)
                . '<div style="margin-top:22px;">' . mail_button($orderUrl, 'Siparişimi görüntüle') . '</div>'
                . '<p style="font-size:13px;' . $muted . 'margin-top:18px;">Sorunuz olursa ' . e($phone) . ' numarasından veya WhatsApp üzerinden bize ulaşabilirsiniz.</p>';
            return [$subject, mail_layout("Havale bilgileri ve sipariş özeti $no", $subject, $body)];

        case 'customer-paid':
        case 'customer-received':
            $subject = "Ödemeniz alındı, siparişiniz hazırlanıyor $no";
            $body = '<h1 style="margin:0 0 8px;font-size:22px;">Teşekkürler ' . e($o['firstName']) . '!</h1>'
                . '<p style="margin:0;' . $muted . 'font-size:14px;line-height:22px;">' . $no . ' numaralı siparişinizin ödemesi alındı ve hazırlanmaya başlandı. Kargoya verildiğinde takip numaranızı e-postayla ileteceğiz.</p>'
                . mail_items_table($o) . mail_address_block($o)
                . '<div style="margin-top:22px;">' . mail_button($orderUrl, 'Siparişimi görüntüle') . '</div>'
                . '<p style="font-size:13px;' . $muted . 'margin-top:18px;">Kurulum ve kullanım desteği için ' . e($phone) . ' numarasından bize ulaşabilirsiniz.</p>';
            return [$subject, mail_layout("Sipariş özeti $no", $subject, $body)];

        case 'customer-shipped':
            $link = tracking_link($o);
            $subject = "Siparişiniz kargoya verildi $no";
            $body = '<h1 style="margin:0 0 8px;font-size:22px;">Siparişiniz yola çıktı 🚚</h1>'
                . '<p style="margin:0;' . $muted . 'font-size:14px;line-height:22px;">' . $no . ' numaralı siparişiniz ' . e($o['carrier'] ? carrier_label($o['carrier']) : 'kargo firmasına') . ' teslim edildi.</p>'
                . ($o['trackingNumber'] ? '<div style="margin:18px 0;padding:14px 16px;border:1px solid ' . MAIL_LINE . ';border-radius:12px;font-size:14px;">Takip numarası: <strong style="font-family:ui-monospace,Menlo,monospace;">' . e($o['trackingNumber']) . '</strong></div>' : '')
                . ($link ? '<div style="margin:12px 0 4px;">' . mail_button($link, 'Kargomu takip et') . '</div>' : '')
                . mail_items_table($o)
                . '<p style="font-size:13px;' . $muted . 'margin-top:18px;">Cihazınızın kurulumu ve aktivasyonu için ' . e($phone) . ' numarasından teknik destek alabilirsiniz.</p>';
            return [$subject, mail_layout('Takip numarası: ' . $o['trackingNumber'], $subject, $body)];
    }
    throw new InvalidArgumentException("Bilinmeyen e-posta türü: $variant");
}

/** İletişim formu bildirimi (yönetici). */
function render_contact_email(array $m): array
{
    $subject = 'İletişim formu: ' . ($m['subject'] ?: 'Mesaj') . ' — ' . $m['name'];
    $row = fn (string $label, string $value) => $value === '' ? '' : '<tr><td style="padding:8px 0;border-bottom:1px solid ' . MAIL_LINE . ';font-size:13px;color:' . MAIL_MUTED . ';width:120px;vertical-align:top;">' . e($label) . '</td><td style="padding:8px 0;border-bottom:1px solid ' . MAIL_LINE . ';font-size:14px;">' . e($value) . '</td></tr>';
    $body = '<h1 style="margin:0 0 6px;font-size:20px;">Yeni iletişim mesajı</h1>'
        . '<p style="margin:0 0 16px;color:' . MAIL_MUTED . ';font-size:14px;">' . e($m['subject']) . ' · ' . format_date($m['created_at'], true) . '</p>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $row('Ad soyad', $m['name']) . $row('Telefon', $m['phone']) . $row('E-posta', $m['email']) . $row('İlgili ürün', $m['product']) . '</table>'
        . '<div style="margin-top:16px;padding:14px 16px;background:#faf9f6;border-radius:12px;font-size:14px;line-height:22px;white-space:pre-wrap;">' . e($m['message']) . '</div>'
        . '<div style="margin-top:22px;">' . mail_button(site_url('/yonetim/mesajlar.php'), 'Panelde aç') . '</div>';
    return [$subject, mail_layout(mb_substr($m['message'], 0, 120), $subject, $body, 'Bu e-posta thinktool.com.tr iletişim formundan gönderildi.')];
}
