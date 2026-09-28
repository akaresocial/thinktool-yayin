<?php

declare(strict_types=1);

/**
 * İlk yönetici hesabı. Yalnızca hiç kullanıcı yokken ve kurulumdan (veya "allow-setup" komutundan)
 * sonraki 2 saat içinde açıktır; böylece başkası ilk hesabı oluşturamaz.
 */
require __DIR__ . '/_inc/admin.php';

if ((int) q_val('SELECT COUNT(*) FROM users') > 0) {
    redirect('/yonetim/giris.php');
}
$marker = DATA_DIR . '/allow-setup';
$open = is_file($marker) && filemtime($marker) > time() - 7200;

$errors = [];
$name = $email = '';
if ($open && is_post()) {
    csrf_verify();
    if (!rate_limit('setup:' . client_ip(), 10, 900)) {
        $errors[] = 'Çok fazla deneme. Biraz sonra tekrar deneyin.';
    }
    $name = p_str('name', 80);
    $email = mb_strtolower(p_str('email', 120));
    $password = (string) ($_POST['password'] ?? '');
    if (mb_strlen($name) < 2) {
        $errors[] = 'Adınızı girin.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Geçerli bir e-posta girin.';
    }
    if (mb_strlen($password) < 10) {
        $errors[] = 'Şifre en az 10 karakter olmalı.';
    }
    if ($password !== (string) ($_POST['password2'] ?? '')) {
        $errors[] = 'Şifreler eşleşmiyor.';
    }
    if (!$errors) {
        q('INSERT INTO users (email, name, password_hash, created_at) VALUES (?,?,?,?)', [$email, $name, password_hash($password, PASSWORD_DEFAULT), now_iso()]);
        @unlink($marker);
        admin_login(q_one('SELECT * FROM users WHERE email = ?', [$email]));
        flash('success', 'Hoş geldiniz! Hesabınız oluşturuldu.');
        redirect('/yonetim/');
    }
}

admin_header('Kurulum');
?>
<div class="auth">
  <img src="/brand/logo.svg" alt="Thinktool Türkiye" height="30">
  <h1>İlk yönetici hesabı</h1>
  <?php if (!$open): ?>
    <div class="flash flash-error">Kurulum ekranının süresi doldu. Yeniden açılması için site yöneticinize başvurun.</div>
  <?php else: ?>
    <p class="muted">Bu hesapla siparişleri, ürünleri ve ayarları yöneteceksiniz. Güçlü bir şifre seçin.</p>
    <?php foreach ($errors as $err): ?><div class="flash flash-error"><?= e($err) ?></div><?php endforeach; ?>
    <form method="post" class="stack">
      <?= csrf_field() ?>
      <?= field('Ad soyad', input('name', $name, ['required' => true, 'autocomplete' => 'name'])) ?>
      <?= field('E-posta', input('email', $email, ['type' => 'email', 'required' => true, 'autocomplete' => 'username'])) ?>
      <?= field('Şifre', input('password', '', ['type' => 'password', 'required' => true, 'minlength' => 10, 'autocomplete' => 'new-password']), 'En az 10 karakter.') ?>
      <?= field('Şifre (tekrar)', input('password2', '', ['type' => 'password', 'required' => true, 'autocomplete' => 'new-password'])) ?>
      <button class="btn btn-primary btn-block" type="submit">Hesabı oluştur</button>
    </form>
  <?php endif; ?>
</div>
<?php
admin_footer();
