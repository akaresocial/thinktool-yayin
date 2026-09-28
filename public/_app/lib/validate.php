<?php

declare(strict_types=1);

const PHONE_PATTERN = '/^[+\d][\d\s()\-]{9,18}$/';

/**
 * Ödeme formu doğrulaması (sitedeki form kurallarıyla aynı).
 * @return array{0: array, 1: array<string,string>} [temiz veri, alan hataları]
 */
function validate_checkout(array $in): array
{
    $e = [];
    $d = [
        'email' => mb_strtolower(str_in($in['email'] ?? '', 120)),
        'phone' => str_in($in['phone'] ?? '', 24),
        'firstName' => str_in($in['firstName'] ?? '', 60),
        'lastName' => str_in($in['lastName'] ?? '', 60),
        'city' => str_in($in['city'] ?? '', 40),
        'district' => str_in($in['district'] ?? '', 60),
        'address' => str_in($in['address'] ?? '', 400),
        'postalCode' => str_in($in['postalCode'] ?? '', 10),
        'invoiceType' => ($in['invoiceType'] ?? '') === 'corporate' ? 'corporate' : 'individual',
        'identityNumber' => str_in($in['identityNumber'] ?? '', 11),
        'companyName' => str_in($in['companyName'] ?? '', 160),
        'taxOffice' => str_in($in['taxOffice'] ?? '', 80),
        'taxNumber' => str_in($in['taxNumber'] ?? '', 11),
        'billingSame' => ($in['billingSame'] ?? true) !== false,
        'billingAddress' => str_in($in['billingAddress'] ?? '', 400),
        'note' => str_in($in['note'] ?? '', 1000),
        'paymentMethod' => ($in['paymentMethod'] ?? '') === 'bank_transfer' ? 'bank_transfer' : 'card',
        'consent' => ($in['consent'] ?? false) === true,
        'website' => str_in($in['website'] ?? '', 200),
    ];
    $items = [];
    foreach (is_array($in['items'] ?? null) ? $in['items'] : [] as $it) {
        $id = (int) ($it['id'] ?? 0);
        $qty = (int) ($it['qty'] ?? 0);
        if ($id > 0 && $qty >= 1 && $qty <= 20) {
            $items[$id] = ['id' => $id, 'qty' => min(20, ($items[$id]['qty'] ?? 0) + $qty)];
        }
    }
    $d['items'] = array_values($items);

    if (!$d['items']) {
        $e['items'] = 'Sepetiniz boş';
    }
    if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
        $e['email'] = 'Geçerli bir e-posta adresi girin';
    }
    if (!preg_match(PHONE_PATTERN, $d['phone'])) {
        $e['phone'] = 'Geçerli bir telefon numarası girin';
    }
    if (mb_strlen($d['firstName']) < 2) {
        $e['firstName'] = 'Adınızı girin';
    }
    if (mb_strlen($d['lastName']) < 2) {
        $e['lastName'] = 'Soyadınızı girin';
    }
    if (mb_strlen($d['city']) < 2) {
        $e['city'] = 'İl seçin';
    }
    if (mb_strlen($d['district']) < 2) {
        $e['district'] = 'İlçe girin';
    }
    if (mb_strlen($d['address']) < 10) {
        $e['address'] = 'Açık adresinizi girin';
    }
    if ($d['identityNumber'] !== '' && !preg_match('/^\d{11}$/', $d['identityNumber'])) {
        $e['identityNumber'] = 'T.C. kimlik numarası 11 haneli olmalı';
    }
    if ($d['invoiceType'] === 'corporate') {
        if ($d['companyName'] === '') {
            $e['companyName'] = 'Firma ünvanını girin';
        }
        if ($d['taxOffice'] === '') {
            $e['taxOffice'] = 'Vergi dairesini girin';
        }
        if (!preg_match('/^\d{10,11}$/', $d['taxNumber'])) {
            $e['taxNumber'] = 'Vergi numarası 10 haneli olmalı';
        }
    }
    if (!$d['billingSame'] && mb_strlen($d['billingAddress']) < 10) {
        $e['billingAddress'] = 'Fatura adresini girin';
    }
    if (!$d['consent']) {
        $e['consent'] = 'Sözleşmeleri onaylamanız gerekiyor';
    }
    return [$d, $e];
}

const CONTACT_SUBJECTS = ['Ürünler hakkında bilgi', 'Ürün siparişi', 'Yazılım desteği', 'Yedek parça', 'Opsiyonel parça tedariği', 'Diğer'];

function validate_contact(array $in): array
{
    $e = [];
    $d = [
        'name' => str_in($in['name'] ?? '', 80),
        'phone' => str_in($in['phone'] ?? '', 24),
        'email' => mb_strtolower(str_in($in['email'] ?? '', 120)),
        'subject' => str_in($in['subject'] ?? '', 60),
        'product' => str_in($in['product'] ?? '', 120),
        'message' => str_in($in['message'] ?? '', 3000),
        'consent' => ($in['consent'] ?? false) === true,
        'website' => str_in($in['website'] ?? '', 200),
    ];
    if (mb_strlen($d['name']) < 3) {
        $e['name'] = 'Adınızı ve soyadınızı girin';
    }
    if (!preg_match(PHONE_PATTERN, $d['phone'])) {
        $e['phone'] = 'Geçerli bir telefon numarası girin';
    }
    if ($d['email'] !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
        $e['email'] = 'Geçerli bir e-posta adresi girin';
    }
    if (!in_array($d['subject'], CONTACT_SUBJECTS, true)) {
        $e['subject'] = 'Bir konu seçin';
    }
    if (mb_strlen($d['message']) < 10) {
        $e['message'] = 'Mesajınız en az 10 karakter olmalı';
    }
    if (!$d['consent']) {
        $e['consent'] = 'Aydınlatma metnini onaylamanız gerekiyor';
    }
    return [$d, $e];
}
