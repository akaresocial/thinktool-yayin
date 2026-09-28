<?php

declare(strict_types=1);

/**
 * PayTR ödeme bildirimi — iFrame API ve Link API bildirimlerinin ortak adresi.
 * - iFrame: PayTR panelindeki Bildirim URL'si. Eski adres (/index.php?wc-api=wc_gateway_paytrcheckout)
 *   .htaccess ile buraya yönlendirilir; panelde değişiklik gerekmez.
 * - Link API: her link oluşturulurken callback_link olarak bu adres verilir (callback_id = TT{no}A{deneme}).
 * PayTR "OK" yanıtı alana kadar bildirimi tekrarlar; aynı bildirim tekrar gelse de sonuç değişmez.
 */
require __DIR__ . '/../../_app/bootstrap.php';

function paytr_ok(): never
{
    header('Content-Type: text/plain; charset=utf-8');
    echo 'OK';
    exit;
}

function paytr_reject(string $message, int $status = 400): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    paytr_reject('PAYTR notification failed: method', 405);
}

$p = array_map(fn ($v) => is_string($v) ? $v : '', $_POST);
$viaLink = !empty($p['callback_id']);

try {
    $valid = !empty($p['merchant_oid']) && !empty($p['hash']) && ($viaLink ? paytr_verify_link_callback($p) : paytr_verify_callback($p));
} catch (UserError) {
    $valid = false;
}
if (!$valid) {
    log_app('PayTR bildirimi imza doğrulamasından geçmedi: ' . ($p['callback_id'] ?? $p['merchant_oid'] ?? '?'), 'paytr');
    paytr_reject('PAYTR notification failed: bad hash');
}

// Link API'de merchant_oid PayTR'nin işlem numarasıdır; siparişi callback_id'den buluruz.
$reference = $viaLink ? $p['callback_id'] : $p['merchant_oid'];
$parsed = parse_merchant_oid($reference);
if (!$parsed) {
    // Eski WooCommerce siparişleri (…PAYTRWOO…) veya bilinmeyen kayıt: tekrar denenmemesi için onayla.
    log_app("PayTR bildirimi eski/bilinmeyen sipariş için: $reference ({$p['status']})", 'paytr');
    paytr_ok();
}

try {
    $order = order_by_number($parsed['orderNumber']);
    if (!$order) {
        log_app("PayTR bildirimi: sipariş bulunamadı $reference", 'paytr');
        paytr_ok();
    }

    $success = ($p['status'] ?? '') === 'success';
    $chargedTry = ((float) ($p['total_amount'] ?? 0)) / 100;
    $paidKurus = (int) ($p['payment_amount'] ?? $p['total_amount'] ?? 0);
    $expectedKurus = (int) round($order['totalTry'] * 100);
    $transactionId = $viaLink ? $p['merchant_oid'] : null;
    $label = ($transactionId ?? $reference) . ' · ' . number_format($chargedTry, 2, ',', '.') . ' TL';

    if (in_array($order['status'], PAID_STATUSES, true)) {
        // Aynı işlemin tekrar bildirimi normaldir; farklı bir başarılı işlem mükerrer ödeme demektir.
        $known = $viaLink ? (($order['paytr']['transactionId'] ?? null) === $transactionId) : (($order['paytr']['merchantOid'] ?? null) === $reference);
        if ($success && !$known) {
            $note = "Mükerrer ödeme bildirimi: sipariş zaten ödenmişken yeni bir başarılı işlem geldi ($label). PayTR panelinden kontrol edip gerekirse iade edin.";
            order_append_note($order['id'], $note);
            order_payment_alert(order_by_id($order['id']), $note);
            log_app("PayTR mükerrer ödeme #{$order['orderNumber']}: $label", 'paytr');
        }
        paytr_ok();
    }

    $paytr = [
        'status' => $p['status'] ?? '',
        'paymentType' => $p['payment_type'] ?? '',
        'installmentCount' => isset($p['installment_count']) ? (int) $p['installment_count'] : ($order['paytr']['installmentCount'] ?? null),
        'totalAmount' => $chargedTry,
        'testMode' => ($p['test_mode'] ?? '') === '1',
        'failedReason' => $success ? '' : trim(($p['failed_reason_code'] ?? '') . ' – ' . ($p['failed_reason_msg'] ?? ''), ' –'),
    ] + ($viaLink ? ['transactionId' => $transactionId] : ['merchantOid' => $reference]);

    if ($success) {
        if ($paidKurus < $expectedKurus) {
            $note = sprintf('Eksik ödeme: sipariş toplamı %s TL, PayTR\'de ödenen %s TL (%s). Sipariş otomatik onaylanmadı.', number_format($expectedKurus / 100, 2, ',', '.'), number_format($paidKurus / 100, 2, ',', '.'), $label);
            order_update($order['id'], ['paytr' => $paytr], false);
            order_append_note($order['id'], $note);
            order_payment_alert(order_by_id($order['id']), $note);
            paytr_ok();
        }
        $after = order_update($order['id'], ['status' => 'paid', 'paytr' => $paytr], true, 'PayTR');
        order_event($order['id'], 'paytr', "Ödeme alındı: $label");
        if ($paidKurus > $expectedKurus) {
            $note = sprintf('Fazla ödeme: sipariş toplamı %s TL, PayTR\'de ödenen %s TL (%s). Farkı iade edin.', number_format($expectedKurus / 100, 2, ',', '.'), number_format($paidKurus / 100, 2, ',', '.'), $label);
            order_append_note($order['id'], $note);
            order_payment_alert($after, $note);
        }
        // Ödeme alındıktan sonra siparişin linki kapatılır; tahsilat linkleri aksi hâlde yeniden ödenebilir.
        if (!empty($order['paytr']['linkId'])) {
            paytr_delete_link((string) $order['paytr']['linkId']);
        }
    } elseif ($order['status'] === 'pending_payment') {
        order_update($order['id'], ['status' => 'payment_failed', 'paytr' => $paytr], true, 'PayTR');
    }
    paytr_ok();
} catch (Throwable $e) {
    log_app('PayTR bildirimi işlenemedi: ' . $e->getMessage(), 'paytr');
    paytr_reject('PAYTR notification failed: server', 500);
}
