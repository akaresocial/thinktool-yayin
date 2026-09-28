<?php

declare(strict_types=1);

/** Sipariş oluşturma. Fiyat ve stok her zaman sunucuda, güncel verilerle hesaplanır. */
require __DIR__ . '/../_app/bootstrap.php';

api_run(function (): void {
    require_method('POST');
    rate_limit_or_fail('checkout', 12, 600);
    [$d, $errors] = validate_checkout(json_input());
    if ($errors) {
        json_fail('Lütfen işaretli alanları kontrol edin.', 400, ['fieldErrors' => $errors]);
    }
    if ($d['website'] !== '') {
        json_fail('Geçersiz istek', 400);
    }
    $s = settings();
    if ($d['paymentMethod'] === 'card' && !$s['cardEnabled']) {
        json_fail('Kartla ödeme şu an kullanılamıyor.', 400);
    }
    if ($d['paymentMethod'] === 'bank_transfer' && !$s['transferEnabled']) {
        json_fail('Havale/EFT şu an kullanılamıyor.', 400);
    }
    $order = order_create($d, $d['items']);
    set_order_cookie($order['orderNumber'], $order['accessKey']);
    order_after_create($order);
    json_out([
        'ok' => true,
        'orderNumber' => $order['orderNumber'],
        'next' => $d['paymentMethod'] === 'card' ? '/odeme/kart?no=' . $order['orderNumber'] : '/siparis?no=' . $order['orderNumber'] . '&yeni=1',
    ]);
});
