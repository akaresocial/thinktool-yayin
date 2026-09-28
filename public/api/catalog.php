<?php

declare(strict_types=1);

/** Canlı fiyat, stok ve kur. Statik sayfalardaki fiyatlar bu yanıtla güncellenir. */
require __DIR__ . '/../_app/bootstrap.php';

api_run(function (): void {
    require_method('GET');
    $live = catalog_live();
    json_out(['ok' => true] + $live, 200, ['Cache-Control' => 'public, max-age=30, stale-while-revalidate=120']);
});
