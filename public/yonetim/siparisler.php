<?php

declare(strict_types=1);

require __DIR__ . '/_inc/admin.php';
require_login();

$status = (string) ($_GET['durum'] ?? '');
$search = str_in($_GET['ara'] ?? '', 80);
$page = max(1, (int) ($_GET['sayfa'] ?? 1));
$per = 50;

$where = [];
$params = [];
if ($status !== '' && isset(ORDER_STATUSES[$status])) {
    $where[] = 'status = ?';
    $params[] = $status;
}
if ($search !== '') {
    $digits = preg_replace('/\D/', '', $search);
    $where[] = "(first_name || ' ' || last_name LIKE ? OR email LIKE ? OR phone LIKE ?" . ($digits !== '' ? ' OR order_number = ?' : '') . ')';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
    if ($digits !== '') {
        $params[] = (int) $digits;
    }
}
$sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$total = (int) q_val("SELECT COUNT(*) FROM orders $sqlWhere", $params);
$rows = q_all("SELECT id, order_number, first_name, last_name, email, phone, total_try, status, payment_method, created_at FROM orders $sqlWhere ORDER BY id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $params);
$counts = [];
foreach (q_all('SELECT status, COUNT(*) c FROM orders GROUP BY status') as $r) {
    $counts[$r['status']] = (int) $r['c'];
}

admin_header('Siparişler', 'siparisler');
?>
<div class="page-head">
  <div><h1>Siparişler</h1><p class="muted">Durumu "Kargoya verildi" yapıp takip numarası girerseniz müşteriye otomatik e-posta gider.</p></div>
</div>

<nav class="tabs">
  <a href="/yonetim/siparisler.php" class="<?= $status === '' ? 'active' : '' ?>">Tümü <span><?= array_sum($counts) ?></span></a>
  <?php foreach (ORDER_STATUSES as $k => $label): if (empty($counts[$k])) continue; ?>
    <a href="?durum=<?= e($k) ?>" class="<?= $status === $k ? 'active' : '' ?>"><?= e($label) ?> <span><?= $counts[$k] ?></span></a>
  <?php endforeach; ?>
</nav>

<form class="toolbar" method="get">
  <?php if ($status): ?><input type="hidden" name="durum" value="<?= e($status) ?>"><?php endif; ?>
  <input type="search" name="ara" value="<?= e($search) ?>" placeholder="Sipariş no, ad, e-posta veya telefon">
  <button class="btn btn-secondary" type="submit">Ara</button>
</form>

<div class="card card-flush">
<?php if (!$rows): ?>
  <p class="empty">Sipariş bulunamadı.</p>
<?php else: ?>
  <table class="table">
    <thead><tr><th>Sipariş</th><th>Müşteri</th><th>Ödeme</th><th class="num">Tutar</th><th>Durum</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $o): ?>
      <tr class="row-link" data-href="/yonetim/siparis.php?id=<?= (int) $o['id'] ?>">
        <td><a href="/yonetim/siparis.php?id=<?= (int) $o['id'] ?>"><strong>#<?= (int) $o['order_number'] ?></strong></a><br><small class="muted"><?= e(format_date($o['created_at'], true)) ?></small></td>
        <td><?= e($o['first_name'] . ' ' . $o['last_name']) ?><br><small class="muted"><?= e($o['email']) ?> · <?= e($o['phone']) ?></small></td>
        <td><?= e(payment_label($o['payment_method'])) ?></td>
        <td class="num"><strong><?= e(format_try((float) $o['total_try'])) ?></strong></td>
        <td><?= status_pill($o['status']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</div>
<?php if ($total > $per): ?>
  <nav class="pager">
    <?php for ($i = 1; $i <= (int) ceil($total / $per); $i++): ?>
      <a href="?<?= e(http_build_query(['durum' => $status, 'ara' => $search, 'sayfa' => $i])) ?>" class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
    <?php endfor; ?>
  </nav>
<?php endif; ?>
<?php
admin_footer();
