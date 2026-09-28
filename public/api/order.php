<?php

declare(strict_types=1);

/** Müşterinin kendi siparişi (sipariş no + çerezdeki gizli anahtar). */
require __DIR__ . '/../_app/bootstrap.php';

api_run(function (): void {
    require_method('GET');
    rate_limit_or_fail('order-view', 240, 600);
    $o = visitor_order((int) ($_GET['no'] ?? 0));
    if (!$o) {
        json_fail('Sipariş bulunamadı. E-postanızdaki bağlantıyı kullanın ya da sipariş takibinden sorgulayın.', 404);
    }
    json_out(['ok' => true, 'order' => order_public($o)]);
});
