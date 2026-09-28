<?php

declare(strict_types=1);

/** Sipariş takibi: sipariş no + e-posta eşleşirse erişim çerezi verilir. */
require __DIR__ . '/../_app/bootstrap.php';

api_run(function (): void {
    require_method('POST');
    rate_limit_or_fail('order-lookup', 15, 600);
    $in = json_input();
    $no = (int) preg_replace('/\D/', '', (string) ($in['no'] ?? ''));
    $email = mb_strtolower(str_in($in['email'] ?? '', 120));
    $o = $no ? order_by_number($no) : null;
    if (!$o || $email === '' || !hash_equals(mb_strtolower($o['email']), $email)) {
        json_fail('Bu bilgilerle eşleşen bir sipariş bulunamadı. Sipariş numaranızı ve e-posta adresinizi kontrol edin.', 404);
    }
    set_order_cookie($o['orderNumber'], $o['accessKey']);
    json_out(['ok' => true, 'next' => '/siparis?no=' . $o['orderNumber']]);
});
