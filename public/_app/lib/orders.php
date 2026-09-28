<?php

declare(strict_types=1);

const ORDER_STATUSES = [
    'pending_payment' => 'Ödeme bekleniyor (kart)',
    'awaiting_transfer' => 'Havale/EFT bekleniyor',
    'paid' => 'Ödendi – hazırlanıyor',
    'shipped' => 'Kargoya verildi',
    'completed' => 'Tamamlandı',
    'cancelled' => 'İptal edildi',
    'payment_failed' => 'Ödeme başarısız',
    'refunded' => 'İade edildi',
];

const PAYMENT_METHODS = [
    'card' => 'Kredi / banka kartı (PayTR)',
    'bank_transfer' => 'Havale / EFT',
];

const CARRIERS = [
    'yurtici' => ['Yurtiçi Kargo', 'https://www.yurticikargo.com/tr/online-servisler/gonderi-sorgula?code='],
    'aras' => ['Aras Kargo', 'https://kargotakip.araskargo.com.tr/mainpage.aspx?code='],
    'mng' => ['MNG Kargo', 'https://www.mngkargo.com.tr/gonderi-takip/?code='],
    'ptt' => ['PTT Kargo', 'https://gonderitakip.ptt.gov.tr/Track/Verify?q='],
    'surat' => ['Sürat Kargo', 'https://suratkargo.com.tr/KargoTakip/?kargotakipno='],
    'ups' => ['UPS', 'https://www.ups.com/track?loc=tr_TR&tracknum='],
    'dhl' => ['DHL', 'https://www.dhl.com/tr-tr/home/tracking.html?tracking-id='],
    'other' => ['Diğer / elden teslim', ''],
];

/** Stok düşülmüş kabul edilen durumlar. */
const STOCK_HOLDING_STATUSES = ['awaiting_transfer', 'paid', 'shipped', 'completed'];
const PAID_STATUSES = ['paid', 'shipped', 'completed', 'refunded'];
const FIRST_ORDER_NUMBER = 4001;

function status_label(string $s): string
{
    return ORDER_STATUSES[$s] ?? $s;
}

function payment_label(string $s): string
{
    return PAYMENT_METHODS[$s] ?? $s;
}

function carrier_label(string $c): string
{
    return CARRIERS[$c][0] ?? $c;
}

function tracking_link(array $o): ?string
{
    if (!empty($o['trackingUrl'])) {
        return $o['trackingUrl'];
    }
    $base = CARRIERS[$o['carrier'] ?? ''][1] ?? '';
    return $base && !empty($o['trackingNumber']) ? $base . rawurlencode($o['trackingNumber']) : null;
}

/* ───────────────────────── Okuma ───────────────────────── */

function order_from_row(array $r): array
{
    $items = array_map(fn ($i) => [
        'id' => (int) $i['id'],
        'productId' => $i['product_id'] === null ? null : (int) $i['product_id'],
        'title' => $i['title'],
        'quantity' => (int) $i['quantity'],
        'unitPriceUsd' => (float) $i['unit_price_usd'],
        'unitPriceTry' => (float) $i['unit_price_try'],
        'lineTotalTry' => (float) $i['line_total_try'],
    ], q_all('SELECT * FROM order_items WHERE order_id = ? ORDER BY id', [(int) $r['id']]));
    return [
        'id' => (int) $r['id'],
        'orderNumber' => (int) $r['order_number'],
        'accessKey' => $r['access_key'],
        'status' => $r['status'],
        'paymentMethod' => $r['payment_method'],
        'totalTry' => (float) $r['total_try'],
        'subtotalUsd' => (float) $r['subtotal_usd'],
        'exchangeRate' => (float) $r['exchange_rate'],
        'shippingTry' => (float) $r['shipping_try'],
        'firstName' => $r['first_name'],
        'lastName' => $r['last_name'],
        'customerName' => trim($r['first_name'] . ' ' . $r['last_name']),
        'email' => $r['email'],
        'phone' => $r['phone'],
        'shippingAddress' => json_decode($r['shipping_address'], true) ?: [],
        'invoice' => json_decode($r['invoice'], true) ?: [],
        'customerNote' => $r['customer_note'],
        'adminNote' => $r['admin_note'],
        'carrier' => $r['carrier'],
        'trackingNumber' => $r['tracking_number'],
        'trackingUrl' => $r['tracking_url'],
        'paytr' => json_decode($r['paytr'], true) ?: [],
        'consent' => json_decode($r['consent'], true) ?: [],
        'source' => $r['source'],
        'paidAt' => $r['paid_at'],
        'shippedAt' => $r['shipped_at'],
        'createdAt' => $r['created_at'],
        'updatedAt' => $r['updated_at'],
        'items' => $items,
    ];
}

function order_by_id(int $id): ?array
{
    $r = q_one('SELECT * FROM orders WHERE id = ?', [$id]);
    return $r ? order_from_row($r) : null;
}

function order_by_number(int $no): ?array
{
    $r = q_one('SELECT * FROM orders WHERE order_number = ?', [$no]);
    return $r ? order_from_row($r) : null;
}

/** Sipariş numarası ve gizli anahtarla sipariş (giriş yapmadan erişim). */
function order_by_key(int $no, string $key): ?array
{
    if ($no <= 0 || strlen($key) < 10) {
        return null;
    }
    $o = order_by_number($no);
    return $o && hash_equals($o['accessKey'], $key) ? $o : null;
}

/** Ziyaretçinin kendi siparişi: çerezdeki anahtarla. */
function visitor_order(int $no): ?array
{
    return order_by_key($no, order_cookie_key($no));
}

/* ───────────────────────── Oluşturma ───────────────────────── */

function next_order_number(): int
{
    $max = (int) (q_val('SELECT MAX(order_number) FROM orders') ?? 0);
    return max(FIRST_ORDER_NUMBER, $max + 1);
}

/**
 * Siparişi oluşturur. Fiyatlar her zaman sunucuda, veritabanındaki güncel değerlerle hesaplanır.
 * @param array $input doğrulanmış ödeme formu
 * @param array $cart  [[id, qty], ...]
 */
function order_create(array $input, array $cart): array
{
    $s = settings();
    $rate = rate_info($s);
    return tx(function () use ($input, $cart, $rate): array {
        $lines = [];
        foreach ($cart as $line) {
            $p = product_by_id((int) $line['id']);
            if (!$p || !$p['published']) {
                throw new UserError('Sepetinizdeki bir ürün artık satışta değil. Lütfen sepetinizi kontrol edin.', 409);
            }
            if ($p['stockStatus'] === 'out_of_stock') {
                throw new UserError("{$p['title']} şu an stokta yok.", 409);
            }
            if ($p['stockQuantity'] !== null && $p['stockQuantity'] < (int) $line['qty']) {
                throw new UserError("{$p['title']} için stokta {$p['stockQuantity']} adet var.", 409);
            }
            $unitTry = usd_to_try($p['priceUsd'], $rate);
            $lines[] = ['productId' => $p['id'], 'title' => $p['title'], 'quantity' => (int) $line['qty'], 'unitPriceUsd' => $p['priceUsd'], 'unitPriceTry' => $unitTry, 'lineTotalTry' => $unitTry * (int) $line['qty']];
        }
        $subtotalUsd = array_sum(array_map(fn ($l) => $l['unitPriceUsd'] * $l['quantity'], $lines));
        $totalTry = array_sum(array_map(fn ($l) => $l['lineTotalTry'], $lines));
        $no = next_order_number();
        $now = now_iso();
        $card = $input['paymentMethod'] === 'card';
        $invoice = [
            'type' => $input['invoiceType'],
            'identityNumber' => $input['invoiceType'] === 'individual' ? $input['identityNumber'] : '',
            'companyName' => $input['invoiceType'] === 'corporate' ? $input['companyName'] : '',
            'taxOffice' => $input['invoiceType'] === 'corporate' ? $input['taxOffice'] : '',
            'taxNumber' => $input['invoiceType'] === 'corporate' ? $input['taxNumber'] : '',
            'sameAsShipping' => $input['billingSame'],
            'address' => $input['billingSame'] ? '' : $input['billingAddress'],
        ];
        q('INSERT INTO orders (order_number, access_key, status, payment_method, total_try, subtotal_usd, exchange_rate, shipping_try, first_name, last_name, email, phone, shipping_address, invoice, customer_note, consent, source, created_at, updated_at) VALUES (?,?,?,?,?,?,?,0,?,?,?,?,?,?,?,?,?,?,?)', [
            $no,
            random_key(),
            $card ? 'pending_payment' : 'awaiting_transfer',
            $input['paymentMethod'],
            $totalTry,
            $subtotalUsd,
            round((float) $rate['rate'], 4),
            $input['firstName'],
            $input['lastName'],
            $input['email'],
            $input['phone'],
            json_encode(['address' => $input['address'], 'district' => $input['district'], 'city' => $input['city'], 'postalCode' => $input['postalCode']], JSON_UNESCAPED_UNICODE),
            json_encode($invoice, JSON_UNESCAPED_UNICODE),
            $input['note'],
            json_encode(['acceptedAt' => $now, 'ip' => client_ip(), 'userAgent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250)], JSON_UNESCAPED_UNICODE),
            'web',
            $now,
            $now,
        ]);
        $id = (int) db()->lastInsertId();
        foreach ($lines as $l) {
            q('INSERT INTO order_items (order_id, product_id, title, quantity, unit_price_usd, unit_price_try, line_total_try) VALUES (?,?,?,?,?,?,?)', [$id, $l['productId'], $l['title'], $l['quantity'], $l['unitPriceUsd'], $l['unitPriceTry'], $l['lineTotalTry']]);
        }
        order_event($id, 'created', 'Sipariş oluşturuldu (' . payment_label($input['paymentMethod']) . ', ' . format_try($totalTry) . ')');
        return order_by_id($id);
    });
}

/** Sipariş oluşturulduktan sonra (işlem dışında) stok ve e-posta etkileri. */
function order_after_create(array $order): void
{
    order_effects($order, null);
}

/* ───────────────────────── Güncelleme ───────────────────────── */

function order_event(int $orderId, string $type, string $message): void
{
    q('INSERT INTO order_events (order_id, type, message, created_at) VALUES (?,?,?,?)', [$orderId, $type, $message, now_iso()]);
}

/**
 * Sipariş alanlarını günceller. Durum değiştiyse stok ve e-posta etkileri uygulanır
 * ($effects=false ile yalnızca kayıt güncellenir; örn. ödeme oturumu bilgisi).
 */
function order_update(int $id, array $patch, bool $effects = true, string $actor = 'sistem'): array
{
    return tx(function () use ($id, $patch, $effects, $actor): array {
        $before = order_by_id($id);
        if (!$before) {
            throw new UserError('Sipariş bulunamadı', 404);
        }
        $map = [
            'status' => 'status', 'adminNote' => 'admin_note', 'carrier' => 'carrier', 'trackingNumber' => 'tracking_number',
            'trackingUrl' => 'tracking_url', 'paymentMethod' => 'payment_method', 'email' => 'email', 'phone' => 'phone',
            'firstName' => 'first_name', 'lastName' => 'last_name',
        ];
        $sets = [];
        $params = [];
        foreach ($map as $k => $col) {
            if (array_key_exists($k, $patch)) {
                $sets[] = "$col = ?";
                $params[] = (string) $patch[$k];
            }
        }
        if (array_key_exists('paytr', $patch)) {
            $sets[] = 'paytr = ?';
            $params[] = json_encode(array_replace($before['paytr'], $patch['paytr']), JSON_UNESCAPED_UNICODE);
        }
        if (isset($patch['status']) && $patch['status'] !== $before['status']) {
            if ($patch['status'] === 'paid' && !$before['paidAt']) {
                $sets[] = 'paid_at = ?';
                $params[] = now_iso();
            }
            if ($patch['status'] === 'shipped' && !$before['shippedAt']) {
                $sets[] = 'shipped_at = ?';
                $params[] = now_iso();
            }
        }
        if (!$sets) {
            return $before;
        }
        $sets[] = 'updated_at = ?';
        $params[] = now_iso();
        $params[] = $id;
        q('UPDATE orders SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
        $after = order_by_id($id);
        if ($after['status'] !== $before['status']) {
            order_event($id, 'status', status_label($before['status']) . ' → ' . status_label($after['status']) . " ($actor)");
            if ($effects) {
                // Stok aynı işlemde; e-postalar işlem bittikten sonra gönderilir.
                stock_sync($after, $before['status']);
                register_shutdown_function(fn () => order_notifications($after, $before['status']));
            }
        }
        return $after;
    });
}

function order_append_note(int $id, string $note): void
{
    $o = order_by_id($id);
    if (!$o) {
        return;
    }
    $line = '[' . date('d.m.Y H:i') . '] ' . $note;
    q('UPDATE orders SET admin_note = ?, updated_at = ? WHERE id = ?', [trim($o['adminNote'] . "\n" . $line), now_iso(), $id]);
}

/** Durum geçişinin yan etkileri: stok ve e-postalar. */
function order_effects(array $order, ?string $prevStatus): void
{
    stock_sync($order, $prevStatus);
    order_notifications($order, $prevStatus);
}

/**
 * Sipariş stok tutan bir duruma girince (havale bekleniyor / ödendi) stok adedi düşer,
 * iptal/iade/başarısız durumlarına dönünce geri eklenir. Stok adedi boş olan ürünler takip edilmez.
 */
function stock_sync(array $order, ?string $prevStatus): void
{
    $before = $prevStatus !== null && in_array($prevStatus, STOCK_HOLDING_STATUSES, true);
    $after = in_array($order['status'], STOCK_HOLDING_STATUSES, true);
    if ($before === $after) {
        return;
    }
    $direction = $after ? -1 : 1;
    foreach ($order['items'] as $item) {
        if (!$item['productId']) {
            continue;
        }
        $p = q_one('SELECT stock_quantity, stock_status FROM products WHERE id = ?', [$item['productId']]);
        if (!$p || $p['stock_quantity'] === null) {
            continue;
        }
        $qty = max(0, (int) $p['stock_quantity'] + $direction * $item['quantity']);
        $status = $qty === 0 ? 'out_of_stock' : ($p['stock_status'] === 'out_of_stock' && $direction > 0 ? 'in_stock' : $p['stock_status']);
        q('UPDATE products SET stock_quantity = ?, stock_status = ?, updated_at = ? WHERE id = ?', [$qty, $status, now_iso(), $item['productId']]);
    }
}

/** Hangi geçişte hangi e-posta gider (önceki sistemle aynı). */
function order_notifications(array $order, ?string $prev): void
{
    $status = $order['status'];
    $plan = [];
    if ($prev === null && $status === 'awaiting_transfer') {
        $plan = ['admin' => 'admin-new-order', 'customer' => 'customer-transfer'];
    } elseif ($status === 'paid' && $prev !== 'paid') {
        $plan = $order['paymentMethod'] === 'card'
            ? ['admin' => 'admin-paid', 'customer' => 'customer-paid']
            : ['customer' => 'customer-received'];
    } elseif ($status === 'shipped' && $prev !== 'shipped') {
        $plan = ['customer' => 'customer-shipped'];
    }
    $s = settings();
    if (isset($plan['admin'])) {
        $to = $s['orderNotificationEmails'] ?: [$s['email']];
        [$subject, $html] = render_order_email($plan['admin'], $order, $s);
        $ok = send_mail($to, $subject, $html, ['replyTo' => $order['email']]);
        order_event($order['id'], 'mail', ($ok ? 'E-posta gönderildi' : 'E-posta GÖNDERİLEMEDİ') . ' (yönetici): ' . $subject);
    }
    if (isset($plan['customer']) && $order['email']) {
        [$subject, $html] = render_order_email($plan['customer'], $order, $s);
        $ok = send_mail([$order['email']], $subject, $html, ['replyTo' => $s['email']]);
        order_event($order['id'], 'mail', ($ok ? 'E-posta gönderildi' : 'E-posta GÖNDERİLEMEDİ') . ' (müşteri): ' . $subject);
    }
}

/** Olağan dışı ödeme bildirimlerinde (mükerrer ödeme, tutar farkı) yöneticileri uyarır. */
function order_payment_alert(array $order, string $note): void
{
    $s = settings();
    $to = $s['orderNotificationEmails'] ?: [$s['email']];
    [$subject, $html] = render_order_email('admin-payment-alert', $order, $s, $note);
    send_mail($to, $subject, $html);
    order_event($order['id'], 'alert', $note);
}

/* ───────────────────────── Müşteriye gösterilen sipariş ───────────────────────── */

/** Sipariş sayfası için güvenli alanlar (iç not, IP, PayTR ayrıntıları hariç). */
function order_public(array $o): array
{
    $s = settings();
    return [
        'orderNumber' => $o['orderNumber'],
        'status' => $o['status'],
        'statusLabel' => status_label($o['status']),
        'paymentMethod' => $o['paymentMethod'],
        'paymentLabel' => payment_label($o['paymentMethod']),
        'totalTry' => $o['totalTry'],
        'createdAt' => $o['createdAt'],
        'firstName' => $o['firstName'],
        'lastName' => $o['lastName'],
        'phone' => $o['phone'],
        'email' => $o['email'],
        'shippingAddress' => $o['shippingAddress'],
        'invoice' => ['type' => $o['invoice']['type'] ?? 'individual', 'companyName' => $o['invoice']['companyName'] ?? '', 'taxOffice' => $o['invoice']['taxOffice'] ?? '', 'taxNumber' => $o['invoice']['taxNumber'] ?? '', 'sameAsShipping' => (bool) ($o['invoice']['sameAsShipping'] ?? true), 'address' => $o['invoice']['address'] ?? ''],
        'items' => array_map(fn ($i) => ['productId' => $i['productId'], 'title' => $i['title'], 'quantity' => $i['quantity'], 'unitPriceTry' => $i['unitPriceTry'], 'lineTotalTry' => $i['lineTotalTry']], $o['items']),
        'carrier' => $o['carrier'],
        'carrierLabel' => $o['carrier'] ? carrier_label($o['carrier']) : '',
        'trackingNumber' => $o['trackingNumber'],
        'trackingLink' => tracking_link($o),
        'installmentCount' => (int) ($o['paytr']['installmentCount'] ?? 0),
        'bankAccounts' => $o['status'] === 'awaiting_transfer' ? $s['bankAccounts'] : [],
        'ads' => ['id' => $s['googleAdsId'], 'purchaseLabel' => $s['googleAdsPurchaseLabel']],
    ];
}
