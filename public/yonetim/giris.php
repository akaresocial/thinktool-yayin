<?php

declare(strict_types=1);

require __DIR__ . '/_inc/admin.php';

if ((int) q_val('SELECT COUNT(*) FROM users') === 0) {
    redirect('/yonetim/kurulum.php');
}
if (admin_user()) {
    redirect('/yonetim/');
}

$error = '';
$email = '';
if (is_post()) {
    csrf_verify();
    $email = mb_strtolower(p_str('email', 120));
    $ip = client_ip();
    if (!rate_limit("login-ip:$ip", 10, 900) || !rate_limit("login-user:$email", 6, 900)) {
        $error = 'Çok fazla hatalı deneme. 15 dakika sonra tekrar deneyin.';
    } else {
        $user = q_one('SELECT * FROM users WHERE email = ?', [$email]);
        if ($user && password_verify((string) ($_POST['password'] ?? ''), $user['password_hash'])) {
            if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash((string) $_POST['password'], PASSWORD_DEFAULT), $user['id']]);
            }
            admin_login($user);
            $r = (string) ($_GET['r'] ?? '/yonetim/');
            redirect(str_starts_with($r, '/yonetim/') ? $r : '/yonetim/');
        }
        usleep(400000);
        $error = 'E-posta veya şifre hatalı.';
    }
}

admin_header('Giriş');
?>
<div class="auth">
  <img src="/brand/logo.svg" alt="Thinktool Türkiye" height="30">
  <h1>Yönetim paneli</h1>
  <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
  <form method="post" class="stack">
    <?= csrf_field() ?>
    <?= field('E-posta', input('email', $email, ['type' => 'email', 'autocomplete' => 'username', 'required' => true, 'autofocus' => true])) ?>
    <?= field('Şifre', input('password', '', ['type' => 'password', 'autocomplete' => 'current-password', 'required' => true])) ?>
    <button class="btn btn-primary btn-block" type="submit">Giriş yap</button>
  </form>
  <a class="muted small" href="/yonetim/sifre.php">Şifremi unuttum</a>
</div>
<?php
admin_footer();
