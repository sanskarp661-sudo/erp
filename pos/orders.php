<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$pdo = db();

$todayStats = $pdo->query("SELECT COUNT(*) cnt, COALESCE(SUM(total_amount),0) total FROM sales_orders WHERE channel='pos' AND order_date = CURDATE()")->fetch();
$weekStats = $pdo->query("SELECT COUNT(*) cnt, COALESCE(SUM(total_amount),0) total FROM sales_orders WHERE channel='pos' AND order_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)")->fetch();

$userFilter = (int)input('user');
$userFilterName = null;
$sql = "SELECT so.*, c.name customer_name, u.name cashier_name FROM sales_orders so JOIN customers c ON c.id = so.customer_id LEFT JOIN users u ON u.id = so.created_by WHERE so.channel = 'pos'";
$params = [];
if ($userFilter) {
    $sql .= " AND so.created_by = ?";
    $params[] = $userFilter;
    $stmt = $pdo->prepare('SELECT name FROM users WHERE id = ?');
    $stmt->execute([$userFilter]);
    $userFilterName = $stmt->fetchColumn();
}
$sql .= " ORDER BY so.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

$page_title = 'POS Orders';
require __DIR__ . '/../includes/header.php';
?>
<?php if ($userFilter): ?>
  <div class="alert alert-info d-flex justify-content-between align-items-center">
    <span>Showing POS sales rung up by <strong><?= e($userFilterName ?: 'Unknown user') ?></strong></span>
    <a href="orders.php" class="btn btn-sm btn-outline-secondary">Clear filter</a>
  </div>
<?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card"><div class="icon bg-brand"><i class="fa-solid fa-cash-register"></i></div>
      <div><div class="value"><?= money($todayStats['total']) ?></div><div class="label">Today's POS sales</div></div></div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card"><div class="icon bg-green"><i class="fa-solid fa-receipt"></i></div>
      <div><div class="value"><?= (int)$todayStats['cnt'] ?></div><div class="label">Today's transactions</div></div></div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card"><div class="icon bg-orange"><i class="fa-solid fa-calendar-week"></i></div>
      <div><div class="value"><?= money($weekStats['total']) ?></div><div class="label">Last 7 days sales</div></div></div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card"><div class="icon bg-purple"><i class="fa-solid fa-chart-simple"></i></div>
      <div><div class="value"><?= (int)$weekStats['cnt'] ?></div><div class="label">Last 7 days transactions</div></div></div>
  </div>
</div>

<div class="d-flex justify-content-between align-items-center mb-3">
  <input type="text" class="form-control" style="max-width:280px" placeholder="Search POS orders..." data-table-search="#posOrdTable">
  <a href="index.php" class="btn btn-brand"><i class="fa-solid fa-cash-register"></i> New Sale</a>
</div>
<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-hover" id="posOrdTable">
      <thead><tr><th>Order #</th><th>Customer</th><th>Cashier</th><th>Date</th><th class="text-end">Total</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($orders as $o): ?>
        <tr>
          <td><?= e($o['order_no']) ?></td>
          <td><?= e($o['customer_name']) ?></td>
          <td><?= e($o['cashier_name'] ?? '—') ?></td>
          <td><?= e($o['order_date']) ?></td>
          <td class="text-end"><?= money($o['total_amount']) ?></td>
          <td class="text-end">
            <a href="<?= base_url('sales/order_view.php?id=' . (int)$o['id']) ?>" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-eye"></i> View</a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$orders): ?><tr><td colspan="6" class="text-muted text-center">No POS sales yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
