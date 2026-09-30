<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$pdo = db();
$categoryFilter = (int)input('category');
$classFilter = in_array(input('class'), ['A', 'B', 'C'], true) ? input('class') : '';

$categories = $pdo->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();

$sql = "SELECT p.id, p.sku, p.name, p.image, p.unit, p.quantity, p.reorder_level, p.cost_price, p.selling_price,
    p.category_id, c.name category_name
  FROM products p LEFT JOIN categories c ON c.id = p.category_id
  WHERE p.status = 'active'";
$params = [];
if ($categoryFilter) {
    $sql .= ' AND p.category_id = ?';
    $params[] = $categoryFilter;
}
$sql .= ' ORDER BY (GREATEST(p.quantity, 0) * p.cost_price) DESC, p.name';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

// Value each item at cost, then rank for ABC analysis: A = items making up
// the first 80% of stock value, B = the next 15%, C = the rest.
$totalValue = 0.0;
$totalRetail = 0.0;
$totalUnits = 0;
$itemsInStock = 0;
$lowStock = [];
$byCategory = [];
foreach ($products as &$p) {
    $qty = max(0, (int)$p['quantity']);
    $p['value'] = $qty * (float)$p['cost_price'];
    $p['retail'] = $qty * (float)$p['selling_price'];
    $totalValue += $p['value'];
    $totalRetail += $p['retail'];
    $totalUnits += $qty;
    if ($qty > 0) $itemsInStock++;
    if ((int)$p['quantity'] <= (int)$p['reorder_level'] && ((int)$p['quantity'] <= 0 || (int)$p['reorder_level'] > 0)) $lowStock[] = $p;
    $key = (int)$p['category_id'];
    $byCategory[$key] ??= ['name' => $p['category_name'] ?? 'Uncategorised', 'value' => 0.0, 'id' => $key];
    $byCategory[$key]['value'] += $p['value'];
}
unset($p);
$running = 0.0;
$classCounts = ['A' => 0, 'B' => 0, 'C' => 0];
$classValue = ['A' => 0.0, 'B' => 0.0, 'C' => 0.0];
foreach ($products as &$p) {
    $share = $totalValue > 0 ? $p['value'] / $totalValue * 100 : 0;
    $before = $running;
    $running += $share;
    $p['share'] = $share;
    $p['class'] = $p['value'] <= 0 ? 'C' : ($before < 80 ? 'A' : ($before < 95 ? 'B' : 'C'));
    $classCounts[$p['class']]++;
    $classValue[$p['class']] += $p['value'];
}
unset($p);
$potentialMargin = $totalRetail - $totalValue;

$rows = $classFilter ? array_values(array_filter($products, fn($p) => $p['class'] === $classFilter)) : $products;

if (input('export') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="inventory-valuation-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['SKU', 'Name', 'Category', 'Unit', 'Qty', 'Cost Price', 'Stock Value', 'Share %', 'ABC Class', 'Selling Price', 'Retail Value']);
    foreach ($rows as $p) {
        fputcsv($out, [$p['sku'], $p['name'], $p['category_name'], $p['unit'], $p['quantity'], $p['cost_price'], round($p['value'], 2), round($p['share'], 2), $p['class'], $p['selling_price'], round($p['retail'], 2)]);
    }
    fclose($out);
    exit;
}

usort($byCategory, fn($a, $b) => $b['value'] <=> $a['value']);
$categorySeries = [];
foreach ($byCategory as $c) {
    if ($c['value'] <= 0) continue;
    $categorySeries[] = ['name' => $c['name'], 'value' => round($c['value'], 2), 'url' => 'inventory_report.php' . ($c['id'] ? '?category=' . $c['id'] : '')];
}

$qs = fn(array $changes) => '?' . http_build_query(array_filter(array_merge(['category' => $categoryFilter ?: null, 'class' => $classFilter ?: null], $changes), fn($v) => $v !== null && $v !== ''));
$classTone = ['A' => 'dash-pill-blue', 'B' => 'dash-pill-purple', 'C' => 'dash-pill-gray'];

$page_title = 'Inventory Report';
$sidebar_module = 'inventory';
require __DIR__ . '/../includes/header.php';
?>
<div class="dash">
<div class="dash-head">
  <div>
    <nav class="inv-crumbs no-print" aria-label="Breadcrumb"><a href="<?= base_url('inventory/index.php') ?>">Inventory</a> <i class="fa-solid fa-chevron-right"></i> <span>Reports</span></nav>
    <h1 class="dash-title">Inventory Valuation</h1>
    <p class="dash-sub">Stock value at cost for active items<?= $categoryFilter ? ' in this category' : '' ?>, ranked with ABC analysis.</p>
  </div>
  <div class="d-flex gap-2 flex-wrap no-print">
    <select class="form-select dash-select" aria-label="Category" onchange="location.href = this.value">
      <option value="inventory_report.php<?= e($qs(['category' => null])) ?>">All categories</option>
      <?php foreach ($categories as $c): ?>
        <option value="inventory_report.php<?= e($qs(['category' => (int)$c['id']])) ?>" <?= $categoryFilter === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <a href="inventory_report.php<?= e($qs(['export' => 'csv'])) ?>" class="btn btn-outline-secondary"><i class="fa-solid fa-file-csv"></i> Export CSV</a>
    <button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
  </div>
</div>

<div class="dash-kpis inv-kpis-4 mb-3">
  <div class="dash-kpi dash-kpi-blue">
    <span class="dash-kpi-icon"><i class="fa-solid fa-sack-dollar"></i></span>
    <span class="dash-kpi-body"><span class="dash-kpi-label">Stock Value</span><span class="dash-kpi-value" title="<?= e(money($totalValue)) ?>"><?= money($totalValue) ?></span><span class="dash-kpi-foot text-muted">at cost price</span></span>
  </div>
  <div class="dash-kpi dash-kpi-green">
    <span class="dash-kpi-icon"><i class="fa-solid fa-tags"></i></span>
    <span class="dash-kpi-body"><span class="dash-kpi-label">Retail Value</span><span class="dash-kpi-value" title="<?= e(money($totalRetail)) ?>"><?= money($totalRetail) ?></span><span class="dash-kpi-foot text-muted"><?= money($potentialMargin) ?> potential margin</span></span>
  </div>
  <div class="dash-kpi dash-kpi-teal">
    <span class="dash-kpi-icon"><i class="fa-solid fa-cubes"></i></span>
    <span class="dash-kpi-body"><span class="dash-kpi-label">Units in Stock</span><span class="dash-kpi-value"><?= number_format($totalUnits) ?></span><span class="dash-kpi-foot text-muted"><?= $itemsInStock ?> of <?= count($products) ?> items in stock</span></span>
  </div>
  <a class="dash-kpi dash-kpi-red" href="<?= base_url('inventory/products.php?stock=reorder') ?>">
    <span class="dash-kpi-icon"><i class="fa-solid fa-triangle-exclamation"></i></span>
    <span class="dash-kpi-body"><span class="dash-kpi-label">Needs Reorder</span><span class="dash-kpi-value"><?= count($lowStock) ?></span><span class="dash-kpi-foot dash-link">View items <i class="fa-solid fa-arrow-right"></i></span></span>
  </a>
</div>

<div class="row g-3 mb-3">
  <div class="col-xl-5">
    <div class="dash-card">
      <div class="dash-card-head"><div><h2>Value by Category</h2><p class="dash-card-sub">Click a category to focus the report on it.</p></div></div>
      <?php if ($categorySeries): ?>
      <div class="dash-donut">
        <div class="dash-donut-chart">
          <canvas id="categoryChart"></canvas>
          <div class="dash-donut-center"><strong id="categoryTotal"></strong><small>Stock Value</small></div>
        </div>
        <ul class="dash-legend" id="categoryLegend"></ul>
      </div>
      <?php else: ?>
      <div class="empty-state"><i class="fa-solid fa-chart-pie"></i><div>No stock on hand</div></div>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-xl-7">
    <div class="dash-card">
      <div class="dash-card-head"><div><h2>ABC Analysis</h2><p class="dash-card-sub">A items hold the first 80% of stock value, B the next 15%, C the rest. Count A items often and keep them well stocked.</p></div></div>
      <div class="row g-2">
        <?php foreach (['A' => 'fa-star', 'B' => 'fa-star-half-stroke', 'C' => 'fa-circle'] as $cls => $icon): $pct = $totalValue > 0 ? $classValue[$cls] / $totalValue * 100 : 0; ?>
          <div class="col-sm-4">
            <a href="inventory_report.php<?= e($qs(['class' => $classFilter === $cls ? null : $cls])) ?>" class="inv-abc <?= $classFilter === $cls ? 'active' : '' ?>">
              <span class="dash-pill <?= $classTone[$cls] ?>">Class <?= $cls ?></span>
              <strong><?= $classCounts[$cls] ?> item<?= $classCounts[$cls] === 1 ? '' : 's' ?></strong>
              <span class="small text-muted"><?= money($classValue[$cls]) ?> · <?= number_format($pct, 1) ?>% of value</span>
              <div class="inv-bar"><span style="width: <?= round($pct, 1) ?>%"></span></div>
            </a>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<div class="dash-card">
  <div class="dash-card-head">
    <div><h2>Item Valuation</h2><p class="dash-card-sub"><?= count($rows) ?> item<?= count($rows) === 1 ? '' : 's' ?><?= $classFilter ? ' in class ' . $classFilter : '' ?>, highest value first.</p></div>
    <?php if ($classFilter || $categoryFilter): ?><a href="inventory_report.php" class="dash-link no-print">Show all</a><?php endif; ?>
  </div>
  <div class="table-responsive">
    <table class="table dash-table inv-table mb-0 align-middle">
      <thead><tr><th>Item</th><th>Category</th><th class="text-end">Qty</th><th class="text-end">Cost</th><th class="text-end">Stock Value</th><th style="min-width:140px">Share</th><th>Class</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $p): $url = base_url('inventory/product_view.php?id=' . (int)$p['id']); ?>
        <tr class="dash-row-link" data-href="<?= e($url) ?>">
          <td>
            <a href="<?= e($url) ?>" class="dash-product">
              <span class="dash-thumb"><?php if (!empty($p['image'])): ?><img src="<?= base_url($p['image']) ?>" alt=""><?php else: ?><i class="fa-solid fa-box"></i><?php endif; ?></span>
              <span class="d-flex flex-column"><span class="fw-semibold text-body"><?= e($p['name']) ?></span><span class="small text-muted"><?= e($p['sku']) ?></span></span>
            </a>
          </td>
          <td><?= $p['category_name'] ? e($p['category_name']) : '<span class="text-muted">—</span>' ?></td>
          <td class="text-end <?= (int)$p['quantity'] <= (int)$p['reorder_level'] ? 'text-danger fw-semibold' : '' ?>"><?= (int)$p['quantity'] ?> <span class="text-muted small"><?= e($p['unit']) ?></span></td>
          <td class="text-end"><?= money($p['cost_price']) ?></td>
          <td class="text-end fw-semibold"><?= money($p['value']) ?></td>
          <td>
            <div class="small"><?= number_format($p['share'], 1) ?>%</div>
            <div class="inv-bar"><span style="width: <?= round(min(100, $p['share']), 1) ?>%"></span></div>
          </td>
          <td><span class="dash-pill <?= $classTone[$p['class']] ?>"><?= $p['class'] ?></span></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="7" class="empty-state"><i class="fa-solid fa-box-open"></i><div>No items to show.</div></td></tr><?php endif; ?>
      </tbody>
      <?php if ($rows): ?>
      <tfoot><tr class="fw-semibold"><td colspan="4">Total</td><td class="text-end"><?= money(array_sum(array_column($rows, 'value'))) ?></td><td colspan="2"></td></tr></tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>
</div>
<?php
$extra_js = [
    'https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js',
    asset_url('assets/js/inventory.js'),
];
$extra_js_inline = 'initInventoryReport(' . json_encode(['currency' => setting('currency_symbol', '$'), 'category' => $categorySeries], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ');';
require __DIR__ . '/../includes/footer.php';
