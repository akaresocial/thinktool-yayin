<?php

declare(strict_types=1);

require __DIR__ . '/_inc/admin.php';

if (is_post()) {
    csrf_verify();
    $_SESSION = [];
    session_destroy();
}
redirect('/yonetim/giris.php');
