<?php

declare(strict_types=1);

require __DIR__ . '/_inc/admin.php';
$me = require_login();

if (is_post()) {
    csrf_verify();
    try {
        $action = p('action');
        if ($action === 'add') {
            $email = mb_strtolower(p_str('email', 120));
            $pass = (string) ($_POST['password'] ?? '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen(p_str('name', 80)) < 2) {
                throw new UserError('Ad ve geçerli bir e-posta girin.');
            }
            if (mb_strlen($pass) < 10) {
                throw new UserError('Şifre en az 10 karakter olmalı.');
            }
            if (q_val('SELECT id FROM users WHERE email = ?', [$email])) {
                throw new UserError('Bu e-postayla bir kullanıcı zaten var.');
            }
            q('INSERT INTO users (email, name, password_hash, created_at) VALUES (?,?,?,?)', [$email, p_str('name', 80), password_hash($pass, PASSWORD_DEFAULT), now_iso()]);
            flash('success', 'Kullanıcı eklendi.');
        } elseif ($action === 'delete') {
            $uid = (int) p('id');
            if ($uid === (int) $me['id']) {
                throw new UserError('Kendi hesabınızı silemezsiniz.');
            }
            q('DELETE FROM users WHERE id = ?', [$uid]);
            flash('success', 'Kullanıcı silindi.');
        } elseif ($action === 'password') {
            $row = q_one('SELECT password_hash FROM users WHERE id = ?', [$me['id']]);
            if (!password_verify((string) ($_POST['current'] ?? ''), $row['password_hash'])) {
                throw new UserError('Mevcut şifre hatalı.');
            }
            $new = (string) ($_POST['new'] ?? '');
            if (mb_strlen($new) < 10 || $new !== (string) ($_POST['new2'] ?? '')) {
                throw new UserError('Yeni şifre en az 10 karakter olmalı ve iki alan eşleşmeli.');
            }
            q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $me['id']]);
            session_regenerate_id(true);
            flash('success', 'Şifreniz değiştirildi.');
        }
    } catch (UserError $e) {
        flash('error', $e->getMessage());
    }
    redirect('/yonetim/kullanicilar.php');
}

$users = q_all('SELECT id, email, name, created_at, last_login_at FROM users ORDER BY id');
admin_header('Kullanıcılar', 'kullanicilar');
?>
<div class="page-head"><div><h1>Kullanıcılar</h1><p class="muted">Yönetim paneline giriş yapabilen kişiler.</p></div></div>
<div class="card card-flush">
  <table class="table">
    <thead><tr><th>Ad</th><th>E-posta</th><th>Son giriş</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): ?>
      <tr>
        <td><strong><?= e($u['name']) ?></strong><?= (int) $u['id'] === (int) $me['id'] ? ' <span class="pill pill-muted">siz</span>' : '' ?></td>
        <td><?= e($u['email']) ?></td>
        <td><?= $u['last_login_at'] ? e(format_date($u['last_login_at'], true)) : '—' ?></td>
        <td><?php if ((int) $u['id'] !== (int) $me['id']): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $u['id'] ?>"><button class="btn btn-ghost btn-xs danger" data-confirm="Kullanıcı silinsin mi?">Sil</button></form><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<div class="grid-2">
  <form method="post" class="card stack">
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <header class="card-head"><h2>Yeni kullanıcı</h2></header>
    <?= field('Ad soyad', input('name', '', ['required' => true])) ?>
    <?= field('E-posta', input('email', '', ['type' => 'email', 'required' => true])) ?>
    <?= field('Şifre', input('password', '', ['type' => 'password', 'required' => true, 'minlength' => 10, 'autocomplete' => 'new-password']), 'En az 10 karakter. Kişiye güvenli bir yoldan iletin.') ?>
    <button class="btn btn-secondary" type="submit">Ekle</button>
  </form>
  <form method="post" class="card stack">
    <?= csrf_field() ?><input type="hidden" name="action" value="password">
    <header class="card-head"><h2>Şifremi değiştir</h2></header>
    <?= field('Mevcut şifre', input('current', '', ['type' => 'password', 'required' => true, 'autocomplete' => 'current-password'])) ?>
    <?= field('Yeni şifre', input('new', '', ['type' => 'password', 'required' => true, 'minlength' => 10, 'autocomplete' => 'new-password'])) ?>
    <?= field('Yeni şifre (tekrar)', input('new2', '', ['type' => 'password', 'required' => true, 'autocomplete' => 'new-password'])) ?>
    <button class="btn btn-secondary" type="submit">Şifreyi değiştir</button>
  </form>
</div>
<?php
admin_footer();
