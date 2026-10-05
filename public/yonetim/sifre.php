<?php

declare(strict_types=1);

/**
 * Şifremi unuttum: e-postaya tek kullanımlık bağlantı gönderilir (?t=…), bağlantıdan yeni şifre belirlenir.
 * Hesap olsun olmasın aynı yanıt verilir; böylece hangi e-postaların kayıtlı olduğu anlaşılmaz.
 */
require __DIR__ . '/_inc/admin.php';

if ((int) q_val('SELECT COUNT(*) FROM users') === 0) {
    redirect('/yonetim/kurulum.php');
}
if (admin_user()) {
    redirect('/yonetim/');
}

$token = str_in($_GET['t'] ?? '', 64);
$user = $token !== '' ? admin_reset_user($token) : null;
$errors = [];
$sent = false;
$email = '';

if (is_post()) {
    csrf_verify();
    if (!rate_limit('reset:' . client_ip(), 8, 900)) {
        $errors[] = 'Çok fazla deneme. 15 dakika sonra tekrar deneyin.';
    } elseif ($token === '') {
        $email = mb_strtolower(p_str('email', 120));
        $found = q_one('SELECT * FROM users WHERE email = ?', [$email]);
        if ($found && rate_limit("reset-user:$email", 3, 3600)) {
            $link = admin_reset_link($found);
            send_mail([$found['email']], 'Yönetim paneli şifre sıfırlama', '<p>Merhaba ' . e($found['name']) . ',</p>'
                . '<p>Thinktool yönetim paneli şifrenizi sıfırlamak için aşağıdaki bağlantıyı 30 dakika içinde açın:</p>'
                . '<p><a href="' . e($link) . '">Yeni şifre belirle</a></p>'
                . '<p>Bu isteği siz yapmadıysanız bu e-postayı yok sayın; şifreniz değişmez.</p>');
        }
        $sent = true;
    } elseif ($user) {
        $password = (string) ($_POST['password'] ?? '');
        if (mb_strlen($password) < 10) {
            $errors[] = 'Şifre en az 10 karakter olmalı.';
        }
        if ($password !== (string) ($_POST['password2'] ?? '')) {
            $errors[] = 'Şifreler eşleşmiyor.';
        }
        if (!$errors) {
            q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
            admin_reset_clear();
            admin_login($user);
            flash('success', 'Şifreniz değiştirildi.');
            redirect('/yonetim/');
        }
    }
}

admin_header('Şifre sıfırlama');
?>
<div class="auth">
  <img src="/brand/logo.svg" alt="Thinktool Türkiye" height="30">
  <h1><?= $token !== '' ? 'Yeni şifre' : 'Şifremi unuttum' ?></h1>
  <?php foreach ($errors as $err): ?><div class="flash flash-error"><?= e($err) ?></div><?php endforeach; ?>
  <?php if ($token !== '' && !$user): ?>
    <div class="flash flash-error">Bağlantı geçersiz ya da süresi dolmuş.</div>
    <a class="btn btn-primary btn-block" href="/yonetim/sifre.php">Yeni bağlantı iste</a>
  <?php elseif ($user): ?>
    <p class="muted"><?= e($user['email']) ?> hesabı için yeni şifrenizi belirleyin.</p>
    <form method="post" class="stack">
      <?= csrf_field() ?>
      <input type="hidden" name="username" value="<?= e($user['email']) ?>" autocomplete="username">
      <?= field('Yeni şifre', input('password', '', ['type' => 'password', 'required' => true, 'minlength' => 10, 'autocomplete' => 'new-password', 'autofocus' => true]), 'En az 10 karakter.') ?>
      <?= field('Yeni şifre (tekrar)', input('password2', '', ['type' => 'password', 'required' => true, 'autocomplete' => 'new-password'])) ?>
      <button class="btn btn-primary btn-block" type="submit">Şifreyi kaydet</button>
    </form>
  <?php elseif ($sent): ?>
    <div class="flash flash-success">Bu e-posta bir yönetici hesabına aitse şifre sıfırlama bağlantısı gönderildi. Gelen kutunuzu (ve gereksiz klasörünü) kontrol edin; bağlantı 30 dakika geçerlidir.</div>
    <a class="btn btn-ghost btn-block" href="/yonetim/giris.php">Girişe dön</a>
  <?php else: ?>
    <p class="muted">Hesabınızın e-posta adresini yazın; şifrenizi yenilemeniz için bir bağlantı gönderelim.</p>
    <form method="post" class="stack">
      <?= csrf_field() ?>
      <?= field('E-posta', input('email', $email, ['type' => 'email', 'autocomplete' => 'username', 'required' => true, 'autofocus' => true])) ?>
      <button class="btn btn-primary btn-block" type="submit">Bağlantı gönder</button>
    </form>
    <a class="muted small" href="/yonetim/giris.php">Girişe dön</a>
  <?php endif; ?>
</div>
<?php
admin_footer();
