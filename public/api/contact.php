<?php

declare(strict_types=1);

/** İletişim formu: mesaj panele kaydedilir ve bildirim adreslerine e-postayla iletilir. */
require __DIR__ . '/../_app/bootstrap.php';

api_run(function (): void {
    require_method('POST');
    rate_limit_or_fail('contact', 5, 600, 'Çok fazla mesaj gönderdiniz. Lütfen biraz sonra tekrar deneyin.');
    [$d, $errors] = validate_contact(json_input());
    if ($errors) {
        json_fail('Lütfen işaretli alanları kontrol edin.', 400, ['fieldErrors' => $errors]);
    }
    if ($d['website'] !== '') {
        json_out(['ok' => true]); // bot tuzağı: sessizce kabul
    }
    q('INSERT INTO contact_messages (name, phone, email, subject, product, message, ip, created_at) VALUES (?,?,?,?,?,?,?,?)', [
        $d['name'], $d['phone'], $d['email'], $d['subject'], $d['product'], $d['message'], client_ip(), now_iso(),
    ]);
    $m = q_one('SELECT * FROM contact_messages WHERE id = ?', [(int) db()->lastInsertId()]);
    $s = settings();
    [$subject, $html] = render_contact_email($m);
    send_mail($s['contactNotificationEmails'] ?: [$s['email']], $subject, $html, ['replyTo' => $d['email']]);
    json_out(['ok' => true]);
});
