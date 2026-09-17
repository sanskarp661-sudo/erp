<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

$pdo = db();

$productCount   = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE status='active'")->fetchColumn();
$lowStockCount  = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE quantity <= reorder_level")->fetchColumn();
$customerCount  = (int)$pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
$employeeCount  = (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE status='active'")->fetchColumn();

$monthSales = (float)$pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM sales_orders WHERE status <> 'cancelled' AND MONTH(order_date)=MONTH(CURDATE()) AND YEAR(order_date)=YEAR(CURDATE())")->fetchColumn();
$unpaidTotal = (float)$pdo->query("SELECT COALESCE(SUM(total - amount_paid),0) FROM invoices WHERE status IN ('unpaid','partially_paid','overdue')")->fetchColumn();
$pendingOrders = (int)$pdo->query("SELECT COUNT(*) FROM sales_orders WHERE status='pending'")->fetchColumn();
$openPOs = (int)$pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE status IN ('pending','ordered')")->fetchColumn();

$salesTrend = $pdo->query("
  SELECT DATE(order_date) d, COALESCE(SUM(total_amount),0) t
  FROM sales_orders
  WHERE order_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND status <> 'cancelled'
  GROUP BY DATE(order_date)
")->fetchAll(PDO::FETCH_KEY_PAIR);

$trendLabels = [];
$trendData = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i day"));
    $trendLabels[] = date('M j', strtotime($d));
    $trendData[] = (float)($salesTrend[$d] ?? 0);
}

$recentOrders = $pdo->query("
  SELECT so.order_no, so.status, so.total_amount, so.order_date, c.name customer_name
  FROM sales_orders so JOIN customers c ON c.id = so.customer_id
  ORDER BY so.id DESC LIMIT 5
")->fetchAll();

$lowStockItems = $pdo->query("
  SELECT name, sku, quantity, reorder_level FROM products
  WHERE quantity <= reorder_level ORDER BY quantity ASC LIMIT 5
")->fetchAll();

$page_title = 'Dashboard';
require __DIR__ . '/includes/header.php';
?>
<div class="row g-3 mb-3">
  <?php foreach ($MODULES as $mod): ?>
    <div class="col-6 col-sm-4 col-lg-3">
      <a href="<?= base_url($mod['home']) ?>" class="module-card">
        <div class="module-card-icon"><i class="<?= e($mod['icon']) ?>"></i></div>
        <div class="module-card-label"><?= e($mod['label']) ?></div>
      </a>
    </div>
  <?php endforeach; ?>
</div>

<div class="row g-3 mb-3">
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card">
      <div class="icon bg-brand"><i class="fa-solid fa-sack-dollar"></i></div>
      <div><div class="value"><?= money($monthSales) ?></div><div class="label">Sales this month</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card">
      <div class="icon bg-red"><i class="fa-solid fa-file-invoice-dollar"></i></div>
      <div><div class="value"><?= money($unpaidTotal) ?></div><div class="label">Unpaid invoices</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card">
      <div class="icon bg-orange"><i class="fa-solid fa-box"></i></div>
      <div><div class="value"><?= $productCount ?></div><div class="label">Active products</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card">
      <div class="icon bg-purple"><i class="fa-solid fa-triangle-exclamation"></i></div>
      <div><div class="value"><?= $lowStockCount ?></div><div class="label">Low stock items</div></div>
    </div>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-sm-4">
    <div class="stat-card"><div class="icon bg-green"><i class="fa-solid fa-address-book"></i></div>
      <div><div class="value"><?= $customerCount ?></div><div class="label">Customers</div></div></div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card"><div class="icon bg-brand"><i class="fa-solid fa-cart-shopping"></i></div>
      <div><div class="value"><?= $pendingOrders ?></div><div class="label">Pending sales orders</div></div></div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card"><div class="icon bg-orange"><i class="fa-solid fa-truck-field"></i></div>
      <div><div class="value"><?= $openPOs ?></div><div class="label">Open purchase orders</div></div></div>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card p-3">
      <h6 class="mb-3">Sales — last 7 days</h6>
      <canvas id="salesTrendChart" height="110"></canvas>
    </div>
    <div class="card p-3 mt-3">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h6 class="mb-0">Recent sales orders</h6>
        <a href="<?= base_url('sales/orders.php') ?>" class="small">View all</a>
      </div>
      <div class="table-responsive">
        <table class="table table-sm">
          <thead><tr><th>Order #</th><th>Customer</th><th>Date</th><th>Status</th><th class="text-end">Amount</th></tr></thead>
          <tbody>
          <?php foreach ($recentOrders as $o): ?>
            <tr>
              <td><?= e($o['order_no']) ?></td>
              <td><?= e($o['customer_name']) ?></td>
              <td><?= e($o['order_date']) ?></td>
              <td><span class="badge text-bg-light badge-status"><?= e($o['status']) ?></span></td>
              <td class="text-end"><?= money($o['total_amount']) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$recentOrders): ?><tr><td colspan="5" class="text-muted text-center">No sales orders yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card p-3">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h6 class="mb-0">Low stock alerts</h6>
        <a href="<?= base_url('inventory/products.php') ?>" class="small">View all</a>
      </div>
      <div class="table-responsive">
        <table class="table table-sm">
          <thead><tr><th>Product</th><th>SKU</th><th class="text-end">Qty</th><th class="text-end">Reorder</th></tr></thead>
          <tbody>
          <?php foreach ($lowStockItems as $p): ?>
            <tr>
              <td><?= e($p['name']) ?></td>
              <td><?= e($p['sku']) ?></td>
              <td class="text-end text-danger fw-bold"><?= (int)$p['quantity'] ?></td>
              <td class="text-end"><?= (int)$p['reorder_level'] ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$lowStockItems): ?><tr><td colspan="4" class="text-muted text-center">Stock levels look healthy.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php
$extra_js = ['https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js'];
$extra_js_inline = "
new Chart(document.getElementById('salesTrendChart'), {
  type: 'line',
  data: {
    labels: " . json_encode($trendLabels) . ",
    datasets: [{ label: 'Sales', data: " . json_encode($trendData) . ", borderColor: '#2f6fed', backgroundColor: 'rgba(47,111,237,.1)', tension: .35, fill: true }]
  },
  options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
});
";
require __DIR__ . '/includes/footer.php';
