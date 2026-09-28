<?php

declare(strict_types=1);

/** Ödeme sayfası, müşteri PayTR'de ödemeyi tamamlayınca siparişin onaylandığını buradan izler. */
require __DIR__ . '/../../_app/bootstrap.php';

api_run(function (): void {
    require_method('POST');
    rate_limit_or_fail('paytr-status', 300, 600);
    $o = visitor_order((int) (json_input()['orderNumber'] ?? 0));
    if (!$o) {
        json_fail('Sipariş bulunamadı.', 404);
    }
    json_out(['ok' => true, 'status' => $o['status'], 'paid' => in_array($o['status'], ['paid', 'shipped', 'completed'], true)]);
});
