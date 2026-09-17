<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$start = input('start') ?: date('Y-m-01');
$end = input('end') ?: date('Y-m-d');

$pdo = db();

$summaryStmt = $pdo->prepare("
  SELECT COUNT(*) order_count, COALESCE(SUM(total_amount),0) total_sales
  FROM sales_orders WHERE order_date BETWEEN ? AND ? AND status <> 'cancelled'
");
$summaryStmt->execute([$start, $end]);
$summary = $summaryStmt->fetch();

$byDayStmt = $pdo->prepare("
  SELECT DATE(order_date) d, SUM(total_amount) t
  FROM sales_orders WHERE order_date BETWEEN ? AND ? AND status <> 'cancelled'
  GROUP BY DATE(order_date) ORDER BY d
");
$byDayStmt->execute([$start, $end]);
$byDay = $byDayStmt->fetchAll();

$topProductsStmt = $pdo->prepare("
  SELECT p.name, p.sku, SUM(soi.quantity) qty, SUM(soi.subtotal) revenue
  FROM sales_order_items soi
  JOIN sales_orders so ON so.id = soi.order_id
  JOIN products p ON p.id = soi.product_id
  WHERE so.order_date BETWEEN ? AND ? AND so.status <> 'cancelled'
  GROUP BY p.id ORDER BY revenue DESC LIMIT 10
");
$topProductsStmt->execute([$start, $end]);
$topProducts = $topProductsStmt->fetchAll();

$byCustomerStmt = $pdo->prepare("
  SELECT c.name, COUNT(so.id) orders, SUM(so.total_amount) total
  FROM sales_orders so JOIN customers c ON c.id = so.customer_id
  WHERE so.order_date BETWEEN ? AND ? AND so.status <> 'cancelled'
  GROUP BY c.id ORDER BY total DESC LIMIT 10
");
$byCustomerStmt->execute([$start, $end]);
$byCustomer = $byCustomerStmt->fetchAll();

$labels = array_map(fn($r) => $r['d'], $byDay);
$data = array_map(fn($r) => (float)$r['t'], $byDay);

$page_title = 'Sales Report';
require __DIR__ . '/../includes/header.php';
?>
<form method="get" class="row g-2 mb-3 align-items-end">
  <div class="col-auto">
    <label class="form-label mb-1">From</label>
    <input type="date" name="start" class="form-control" value="<?= e($start) ?>">
  </div>
  <div class="col-auto">
    <label class="form-label mb-1">To</label>
    <input type="date" name="end" class="form-control" value="<?= e($end) ?>">
  </div>
  <div class="col-auto"><button class="btn btn-brand">Apply</button></div>
</form>

<div class="row g-3 mb-3">
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card"><div class="icon bg-brand"><i class="fa-solid fa-sack-dollar"></i></div>
      <div><div class="value"><?= money($summary['total_sales']) ?></div><div class="label">Total sales</div></div></div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card"><div class="icon bg-green"><i class="fa-solid fa-cart-shopping"></i></div>
      <div><div class="value"><?= (int)$summary['order_count'] ?></div><div class="label">Orders</div></div></div>
  </div>
</div>

<div class="card p-3 mb-3">
  <h6 class="mb-3">Sales Trend</h6>
  <canvas id="salesChart" height="90"></canvas>
</div>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card p-3">
      <h6 class="mb-2">Top Products</h6>
      <div class="table-responsive">
        <table class="table table-sm">
          <thead><tr><th>Product</th><th class="text-end">Qty Sold</th><th class="text-end">Revenue</th></tr></thead>
          <tbody>
          <?php foreach ($topProducts as $p): ?>
            <tr><td><?= e($p['name']) ?> <span class="text-muted small">(<?= e($p['sku']) ?>)</span></td><td class="text-end"><?= (int)$p['qty'] ?></td><td class="text-end"><?= money($p['revenue']) ?></td></tr>
          <?php endforeach; ?>
          <?php if (!$topProducts): ?><tr><td colspan="3" class="text-muted text-center">No sales in this period.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card p-3">
      <h6 class="mb-2">Top Customers</h6>
      <div class="table-responsive">
        <table class="table table-sm">
          <thead><tr><th>Customer</th><th class="text-end">Orders</th><th class="text-end">Total</th></tr></thead>
          <tbody>
          <?php foreach ($byCustomer as $c): ?>
            <tr><td><?= e($c['name']) ?></td><td class="text-end"><?= (int)$c['orders'] ?></td><td class="text-end"><?= money($c['total']) ?></td></tr>
          <?php endforeach; ?>
          <?php if (!$byCustomer): ?><tr><td colspan="3" class="text-muted text-center">No sales in this period.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php
$extra_js = ['https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js'];
$extra_js_inline = "
new Chart(document.getElementById('salesChart'), {
  type: 'bar',
  data: { labels: " . json_encode($labels) . ", datasets: [{ label: 'Sales', data: " . json_encode($data) . ", backgroundColor: '#2f6fed' }] },
  options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
});
";
require __DIR__ . '/../includes/footer.php';
