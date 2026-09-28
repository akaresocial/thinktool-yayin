<?php

declare(strict_types=1);

/**
 * Statik sitenin derlendiği içerik (yalnızca yayındaki içerik ve herkese açık ayarlar).
 * Sipariş, müşteri, kullanıcı ve PayTR bilgileri bu yanıtta asla bulunmaz.
 */
require __DIR__ . '/../_app/bootstrap.php';

api_run(function (): void {
    require_method('GET');
    json_out(content_export(), 200, ['Cache-Control' => 'no-store']);
});
