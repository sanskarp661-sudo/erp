<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$pdo = db();
$customerCount = (int)$pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
$newThisMonth = (int)$pdo->query("SELECT COUNT(*) FROM customers WHERE MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())")->fetchColumn();
$withOrders = (int)$pdo->query("SELECT COUNT(DISTINCT customer_id) FROM sales_orders WHERE status <> 'cancelled'")->fetchColumn();

$topCustomers = $pdo->query("
  SELECT c.id, c.name, c.company, COUNT(so.id) orders, COALESCE(SUM(so.total_amount),0) total
  FROM customers c LEFT JOIN sales_orders so ON so.customer_id = c.id AND so.status <> 'cancelled'
  GROUP BY c.id ORDER BY total DESC LIMIT 8
")->fetchAll();

$page_title = 'CRM';
require __DIR__ . '/../includes/header.php';
?>
<div class="row g-3 mb-3">
  <div class="col-sm-6 col-lg-4">
    <div class="stat-card"><div class="icon bg-brand"><i class="fa-solid fa-address-book"></i></div>
      <div><div class="value"><?= $customerCount ?></div><div class="label">Total customers</div></div></div>
  </div>
  <div class="col-sm-6 col-lg-4">
    <div class="stat-card"><div class="icon bg-green"><i class="fa-solid fa-user-plus"></i></div>
      <div><div class="value"><?= $newThisMonth ?></div><div class="label">New this month</div></div></div>
  </div>
  <div class="col-sm-6 col-lg-4">
    <div class="stat-card"><div class="icon bg-orange"><i class="fa-solid fa-cart-shopping"></i></div>
      <div><div class="value"><?= $withOrders ?></div><div class="label">Customers with orders</div></div></div>
  </div>
</div>

<div class="d-flex gap-2 mb-3">
  <a href="customer_form.php" class="btn btn-brand"><i class="fa-solid fa-plus"></i> Add Customer</a>
  <a href="customers.php" class="btn btn-outline-secondary">View All Customers</a>
</div>

<div class="card p-3">
  <h6 class="mb-2">Top Customers by Revenue</h6>
  <div class="table-responsive">
    <table class="table table-sm">
      <thead><tr><th>Customer</th><th>Company</th><th class="text-end">Orders</th><th class="text-end">Total Spent</th></tr></thead>
      <tbody>
      <?php foreach ($topCustomers as $c): ?>
        <tr>
          <td><a href="customer_form.php?id=<?= (int)$c['id'] ?>"><?= e($c['name']) ?></a></td>
          <td class="text-muted"><?= e($c['company']) ?></td>
          <td class="text-end"><?= (int)$c['orders'] ?></td>
          <td class="text-end"><?= money($c['total']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$topCustomers): ?><tr><td colspan="4" class="text-muted text-center">No customers yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
