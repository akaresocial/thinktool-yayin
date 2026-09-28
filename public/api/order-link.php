<?php

declare(strict_types=1);

/**
 * E-postalardaki ve PayTR dönüşündeki sipariş bağlantısı: /siparis/4001?k=ANAHTAR
 * Anahtar çereze alınır ve adresten silinmiş sayfaya yönlendirilir (anahtar analitik araçlarına düşmez).
 */
require __DIR__ . '/../_app/bootstrap.php';

$no = (int) ($_GET['no'] ?? 0);
$key = str_in($_GET['k'] ?? '', 64);
$to = str_in($_GET['to'] ?? '', 20) === 'kart' ? '/odeme/kart' : '/siparis';
if ($no > 0 && $key !== '' && order_by_key($no, $key)) {
    set_order_cookie($no, $key);
}
$params = ['no' => $no];
foreach (['yeni', 'odeme', 'hata'] as $flag) {
    if (isset($_GET[$flag])) {
        $params[$flag] = str_in($_GET[$flag], 10);
    }
}
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('Location: ' . $to . '?' . http_build_query($params), true, 303);
