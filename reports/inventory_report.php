<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$pdo = db();

$products = $pdo->query("
  SELECT p.*, c.name category_name
  FROM products p LEFT JOIN categories c ON c.id = p.category_id
  ORDER BY (p.quantity * p.cost_price) DESC
")->fetchAll();

$totalValue = 0;
$totalUnits = 0;
$lowStock = [];
$byCategory = [];
foreach ($products as $p) {
    $value = $p['quantity'] * $p['cost_price'];
    $totalValue += $value;
    $totalUnits += $p['quantity'];
    if ($p['quantity'] <= $p['reorder_level']) {
        $lowStock[] = $p;
    }
    $cat = $p['category_name'] ?? 'Uncategorized';
    $byCategory[$cat] = ($byCategory[$cat] ?? 0) + $value;
}
arsort($byCategory);

$page_title = 'Inventory Report';
require __DIR__ . '/../includes/header.php';
?>
<div class="row g-3 mb-3">
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card"><div class="icon bg-brand"><i class="fa-solid fa-warehouse"></i></div>
      <div><div class="value"><?= money($totalValue) ?></div><div class="label">Inventory value (at cost)</div></div></div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card"><div class="icon bg-green"><i class="fa-solid fa-cubes"></i></div>
      <div><div class="value"><?= (int)$totalUnits ?></div><div class="label">Total units in stock</div></div></div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card"><div class="icon bg-red"><i class="fa-solid fa-triangle-exclamation"></i></div>
      <div><div class="value"><?= count($lowStock) ?></div><div class="label">Low stock items</div></div></div>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-5">
    <div class="card p-3">
      <h6 class="mb-3">Inventory Value by Category</h6>
      <canvas id="catChart" height="220"></canvas>
    </div>
  </div>
  <div class="col-lg-7">
    <div class="card p-3">
      <h6 class="mb-2">Low Stock Items</h6>
      <div class="table-responsive">
        <table class="table table-sm">
          <thead><tr><th>Product</th><th class="text-end">Qty</th><th class="text-end">Reorder Level</th></tr></thead>
          <tbody>
          <?php foreach ($lowStock as $p): ?>
            <tr><td><?= e($p['name']) ?> <span class="text-muted small">(<?= e($p['sku']) ?>)</span></td><td class="text-end text-danger fw-bold"><?= (int)$p['quantity'] ?></td><td class="text-end"><?= (int)$p['reorder_level'] ?></td></tr>
          <?php endforeach; ?>
          <?php if (!$lowStock): ?><tr><td colspan="3" class="text-muted text-center">Stock levels look healthy.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="card p-3 mt-3">
  <h6 class="mb-2">All Products</h6>
  <div class="table-responsive">
    <table class="table table-sm">
      <thead><tr><th>SKU</th><th>Name</th><th>Category</th><th class="text-end">Qty</th><th class="text-end">Cost</th><th class="text-end">Stock Value</th></tr></thead>
      <tbody>
      <?php foreach ($products as $p): ?>
        <tr>
          <td><?= e($p['sku']) ?></td><td><?= e($p['name']) ?></td><td><?= e($p['category_name'] ?? '—') ?></td>
          <td class="text-end"><?= (int)$p['quantity'] ?></td><td class="text-end"><?= money($p['cost_price']) ?></td>
          <td class="text-end"><?= money($p['quantity'] * $p['cost_price']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php
$extra_js = ['https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js'];
$extra_js_inline = "
new Chart(document.getElementById('catChart'), {
  type: 'doughnut',
  data: { labels: " . json_encode(array_keys($byCategory)) . ", datasets: [{ data: " . json_encode(array_values($byCategory)) . ", backgroundColor: ['#2f6fed','#16a34a','#ea580c','#7c3aed','#dc2626','#0891b2','#ca8a04'] }] }
});
";
require __DIR__ . '/../includes/footer.php';
