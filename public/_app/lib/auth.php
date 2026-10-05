<?php

declare(strict_types=1);

/**
 * Yönetici şifre sıfırlama: tek kullanımlık, 30 dakika geçerli bağlantı. Aynı anda yalnızca bir bağlantı geçerlidir
 * (yenisi eskisini geçersiz kılar). Veritabanında anahtarın kendisi değil, özeti tutulur.
 * Bağlantı ya "Şifremi unuttum" ile hesabın e-postasına gönderilir ya da sunucuda `cli.php sifre-sifirla` ile üretilir.
 */
const ADMIN_RESET_TTL = 1800;

function admin_reset_link(array $user): string
{
    $token = random_key(24);
    meta_set('admin_reset', (string) json_encode(['user' => (int) $user['id'], 'hash' => hash('sha256', $token), 'expires' => time() + ADMIN_RESET_TTL]));
    return site_url('/yonetim/sifre.php?t=' . $token);
}

/** Bağlantıdaki anahtar geçerliyse hesabı döndürür. */
function admin_reset_user(string $token): ?array
{
    $r = json_decode((string) meta_get('admin_reset'), true);
    if ($token === '' || !is_array($r) || (int) ($r['expires'] ?? 0) < time() || !hash_equals((string) ($r['hash'] ?? ''), hash('sha256', $token))) {
        return null;
    }
    return q_one('SELECT * FROM users WHERE id = ?', [(int) $r['user']]);
}

function admin_reset_clear(): void
{
    meta_set('admin_reset', '');
}
