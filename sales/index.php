<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$pdo = db();
$monthSales = (float)$pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM sales_orders WHERE status <> 'cancelled' AND MONTH(order_date)=MONTH(CURDATE()) AND YEAR(order_date)=YEAR(CURDATE())")->fetchColumn();
$pendingOrders = (int)$pdo->query("SELECT COUNT(*) FROM sales_orders WHERE status='pending'")->fetchColumn();
$completedThisMonth = (int)$pdo->query("SELECT COUNT(*) FROM sales_orders WHERE status='completed' AND MONTH(order_date)=MONTH(CURDATE()) AND YEAR(order_date)=YEAR(CURDATE())")->fetchColumn();
$avgOrderValue = (float)$pdo->query("SELECT COALESCE(AVG(total_amount),0) FROM sales_orders WHERE status <> 'cancelled'")->fetchColumn();

$recentOrders = $pdo->query("
  SELECT so.id, so.order_no, so.status, so.total_amount, so.order_date, c.name customer_name
  FROM sales_orders so JOIN customers c ON c.id = so.customer_id
  ORDER BY so.id DESC LIMIT 8
")->fetchAll();

$badge = ['pending' => 'secondary', 'confirmed' => 'info', 'shipped' => 'primary', 'completed' => 'success', 'cancelled' => 'danger'];

$page_title = 'Sales';
require __DIR__ . '/../includes/header.php';
?>
<div class="row g-3 mb-3">
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card"><div class="icon bg-brand"><i class="fa-solid fa-sack-dollar"></i></div>
      <div><div class="value"><?= money($monthSales) ?></div><div class="label">Sales this month</div></div></div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card"><div class="icon bg-orange"><i class="fa-solid fa-hourglass-half"></i></div>
      <div><div class="value"><?= $pendingOrders ?></div><div class="label">Pending orders</div></div></div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card"><div class="icon bg-green"><i class="fa-solid fa-flag-checkered"></i></div>
      <div><div class="value"><?= $completedThisMonth ?></div><div class="label">Completed this month</div></div></div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card"><div class="icon bg-purple"><i class="fa-solid fa-chart-simple"></i></div>
      <div><div class="value"><?= money($avgOrderValue) ?></div><div class="label">Average order value</div></div></div>
  </div>
</div>

<div class="d-flex gap-2 mb-3">
  <a href="order_form.php" class="btn btn-brand"><i class="fa-solid fa-plus"></i> New Sales Order</a>
  <a href="<?= base_url('crm/customers.php') ?>" class="btn btn-outline-brand">View Customers</a>
  <a href="<?= base_url('reports/sales_report.php') ?>" class="btn btn-outline-secondary">Full Report</a>
</div>

<div class="card p-3">
  <h6 class="mb-2">Recent Sales Orders</h6>
  <div class="table-responsive">
    <table class="table table-sm">
      <thead><tr><th>Order #</th><th>Customer</th><th>Date</th><th>Status</th><th class="text-end">Amount</th></tr></thead>
      <tbody>
      <?php foreach ($recentOrders as $o): ?>
        <tr>
          <td><a href="order_view.php?id=<?= (int)$o['id'] ?>"><?= e($o['order_no']) ?></a></td>
          <td><?= e($o['customer_name']) ?></td>
          <td><?= e($o['order_date']) ?></td>
          <td><span class="badge text-bg-<?= $badge[$o['status']] ?? 'secondary' ?> badge-status"><?= e($o['status']) ?></span></td>
          <td class="text-end"><?= money($o['total_amount']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$recentOrders): ?><tr><td colspan="5" class="text-muted text-center">No sales orders yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
