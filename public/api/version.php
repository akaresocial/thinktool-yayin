<?php

declare(strict_types=1);

/** İçerik sürümü: yayın deposundaki derleme görevi değişiklik olup olmadığını buradan anlar. */
require __DIR__ . '/../_app/bootstrap.php';

api_run(function (): void {
    json_out(['ok' => true] + content_version(), 200, ['Cache-Control' => 'no-store']);
});
