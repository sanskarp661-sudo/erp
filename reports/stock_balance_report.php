<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$warehouseFilter = (int)input('warehouse');
$categoryFilter = (int)input('category');
$search = trim((string)input('q'));
$showZero = input('zero') === '1';
$availability = in_array(input('availability'), ['short', 'available'], true) ? input('availability') : '';

$warehouses = leaf_warehouses();
$categories = db()->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();
$products = db()->query("SELECT p.id, p.sku, p.name, p.image, p.unit, p.cost_price, p.category_id, c.name category_name
  FROM products p LEFT JOIN categories c ON c.id = p.category_id
  WHERE p.status = 'active' ORDER BY p.name")->fetchAll();

$actual = [];
foreach (db()->query('SELECT product_id, warehouse_id, SUM(quantity) quantity FROM stock_bins GROUP BY product_id, warehouse_id') as $row) {
    $actual[$row['product_id']][$row['warehouse_id']] = (int)$row['quantity'];
}

// Reserved = qty on Sales Orders (not cancelled) that don't yet have a
// delivered Delivery Note — i.e. committed to a customer but not yet
// fulfilled. Grouped by the order's own warehouse_id (set on the Sales
// Order form); orders without a warehouse set aren't attributed anywhere.
$reserved = [];
$reservedSql = "SELECT soi.product_id, so.warehouse_id, SUM(soi.quantity) qty
    FROM sales_order_items soi
    JOIN sales_orders so ON so.id = soi.order_id
    WHERE so.status <> 'cancelled' AND so.warehouse_id IS NOT NULL
      AND NOT EXISTS (SELECT 1 FROM delivery_notes dn WHERE dn.sales_order_id = so.id AND dn.status = 'delivered')
    GROUP BY soi.product_id, so.warehouse_id";
foreach (db()->query($reservedSql) as $row) {
    $reserved[$row['product_id']][$row['warehouse_id']] = (int)$row['qty'];
}

$rows = [];
foreach ($products as $p) {
    if ($search !== '' && stripos($p['name'], $search) === false && stripos($p['sku'], $search) === false) {
        continue;
    }
    if ($categoryFilter && (int)$p['category_id'] !== $categoryFilter) continue;
    foreach ($warehouses as $w) {
        if ($warehouseFilter && $warehouseFilter !== (int)$w['id']) continue;
        $actualQty = $actual[$p['id']][$w['id']] ?? 0;
        $reservedQty = $reserved[$p['id']][$w['id']] ?? 0;
        if (!$showZero && $actualQty === 0 && $reservedQty === 0) continue;
        $availableQty = $actualQty - $reservedQty;
        if ($availability === 'short' && $availableQty >= 0) continue;
        if ($availability === 'available' && $availableQty <= 0) continue;
        $rows[] = [
            'product_id' => (int)$p['id'],
            'image' => $p['image'],
            'item_code' => $p['sku'],
            'item_name' => $p['name'],
            'category' => $p['category_name'],
            'unit' => $p['unit'],
            'warehouse' => $w['name'],
            'actual_qty' => $actualQty,
            'reserved_qty' => $reservedQty,
            'available_qty' => $availableQty,
            'value' => max(0, $actualQty) * (float)$p['cost_price'],
        ];
    }
}

$totalActual = array_sum(array_column($rows, 'actual_qty'));
$totalReserved = array_sum(array_column($rows, 'reserved_qty'));
$totalAvailable = array_sum(array_column($rows, 'available_qty'));
$totalValue = array_sum(array_column($rows, 'value'));
$shortRows = count(array_filter($rows, fn($r) => $r['available_qty'] < 0));

if (input('export') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="stock-balance-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Item Code', 'Item Name', 'Category', 'Warehouse', 'Unit', 'Actual Qty', 'Reserved Qty', 'Available Qty', 'Stock Value']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['item_code'], $r['item_name'], $r['category'], $r['warehouse'], $r['unit'], $r['actual_qty'], $r['reserved_qty'], $r['available_qty'], round($r['value'], 2)]);
    }
    fclose($out);
    exit;
}

$filtersActive = $warehouseFilter || $categoryFilter || $search !== '' || $showZero || $availability;
$exportQs = http_build_query(array_filter(['warehouse' => $warehouseFilter ?: null, 'category' => $categoryFilter ?: null, 'q' => $search ?: null, 'zero' => $showZero ? '1' : null, 'availability' => $availability ?: null, 'export' => 'csv']));

$page_title = 'Stock Balance Report';
$sidebar_module = input('from') === 'supply-chain' ? 'supply-chain' : 'inventory';
require __DIR__ . '/../includes/header.php';
?>
<div class="dash">
<div class="dash-head">
  <div>
    <nav class="inv-crumbs no-print" aria-label="Breadcrumb"><a href="<?= base_url('inventory/index.php') ?>">Inventory</a> <i class="fa-solid fa-chevron-right"></i> <span>Reports</span></nav>
    <h1 class="dash-title">Stock Balance</h1>
    <p class="dash-sub">On-hand, reserved and available quantity for each item in each warehouse, as of <?= e(date('j M Y, g:i A')) ?>.</p>
  </div>
  <div class="d-flex gap-2 flex-wrap no-print">
    <a href="stock_balance_report.php?<?= e($exportQs) ?>" class="btn btn-outline-secondary"><i class="fa-solid fa-file-csv"></i> Export CSV</a>
    <button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
  </div>
</div>

<div class="dash-kpis inv-kpis-4 mb-3">
  <div class="dash-kpi dash-kpi-blue">
    <span class="dash-kpi-icon"><i class="fa-solid fa-cubes"></i></span>
    <span class="dash-kpi-body"><span class="dash-kpi-label">Actual Qty</span><span class="dash-kpi-value"><?= number_format($totalActual) ?></span><span class="dash-kpi-foot text-muted">on hand</span></span>
  </div>
  <div class="dash-kpi dash-kpi-orange">
    <span class="dash-kpi-icon"><i class="fa-solid fa-lock"></i></span>
    <span class="dash-kpi-body"><span class="dash-kpi-label">Reserved Qty</span><span class="dash-kpi-value"><?= number_format($totalReserved) ?></span><span class="dash-kpi-foot text-muted">on open sales orders</span></span>
  </div>
  <div class="dash-kpi dash-kpi-green">
    <span class="dash-kpi-icon"><i class="fa-solid fa-circle-check"></i></span>
    <span class="dash-kpi-body"><span class="dash-kpi-label">Available Qty</span><span class="dash-kpi-value"><?= number_format($totalAvailable) ?></span><span class="dash-kpi-foot <?= $shortRows ? 'text-danger' : 'text-muted' ?>"><?= $shortRows ? $shortRows . ' row' . ($shortRows === 1 ? '' : 's') . ' short' : 'nothing short' ?></span></span>
  </div>
  <div class="dash-kpi dash-kpi-purple">
    <span class="dash-kpi-icon"><i class="fa-solid fa-sack-dollar"></i></span>
    <span class="dash-kpi-body"><span class="dash-kpi-label">Stock Value</span><span class="dash-kpi-value" title="<?= e(money($totalValue)) ?>"><?= money($totalValue) ?></span><span class="dash-kpi-foot text-muted">at cost price</span></span>
  </div>
</div>

<div class="dash-card">
  <form method="get" class="inv-toolbar no-print">
    <?php if ($sidebar_module === 'supply-chain'): ?><input type="hidden" name="from" value="supply-chain"><?php endif; ?>
    <div class="inv-search">
      <i class="fa-solid fa-magnifying-glass"></i>
      <input type="search" name="q" class="form-control" value="<?= e($search) ?>" placeholder="Search item code or name" aria-label="Search items">
    </div>
    <select name="warehouse" class="form-select" onchange="this.form.submit()" aria-label="Warehouse">
      <option value="">All warehouses</option>
      <?php foreach ($warehouses as $w): ?>
        <option value="<?= (int)$w['id'] ?>" <?= $warehouseFilter === (int)$w['id'] ? 'selected' : '' ?>><?= e($w['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="category" class="form-select" onchange="this.form.submit()" aria-label="Category">
      <option value="">All categories</option>
      <?php foreach ($categories as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= $categoryFilter === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="availability" class="form-select" onchange="this.form.submit()" aria-label="Availability">
      <option value="">Any availability</option>
      <option value="available" <?= $availability === 'available' ? 'selected' : '' ?>>Available to sell</option>
      <option value="short" <?= $availability === 'short' ? 'selected' : '' ?>>Short (reserved &gt; on hand)</option>
    </select>
    <div class="form-check form-switch mb-0">
      <input type="checkbox" class="form-check-input" id="zeroCheck" name="zero" value="1" <?= $showZero ? 'checked' : '' ?> onchange="this.form.submit()">
      <label class="form-check-label small" for="zeroCheck">Show zero rows</label>
    </div>
    <button type="submit" class="btn btn-outline-brand">Search</button>
    <?php if ($filtersActive): ?><a href="stock_balance_report.php<?= $sidebar_module === 'supply-chain' ? '?from=supply-chain' : '' ?>" class="btn btn-link text-decoration-none">Reset</a><?php endif; ?>
  </form>

  <div class="table-responsive">
    <table class="table dash-table inv-table mb-0 align-middle">
      <thead><tr><th>Item</th><th>Warehouse</th><th class="text-end">Actual Qty</th><th class="text-end">Reserved Qty</th><th class="text-end">Available Qty</th><th class="text-end">Stock Value</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $url = base_url('inventory/product_view.php?id=' . $r['product_id']); ?>
        <tr class="dash-row-link" data-href="<?= e($url) ?>">
          <td>
            <a href="<?= e($url) ?>" class="dash-product">
              <span class="dash-thumb"><?php if (!empty($r['image'])): ?><img src="<?= base_url($r['image']) ?>" alt=""><?php else: ?><i class="fa-solid fa-box"></i><?php endif; ?></span>
              <span class="d-flex flex-column"><span class="fw-semibold text-body"><?= e($r['item_name']) ?></span><span class="small text-muted"><?= e($r['item_code']) ?><?= $r['category'] ? ' · ' . e($r['category']) : '' ?></span></span>
            </a>
          </td>
          <td><?= e($r['warehouse']) ?></td>
          <td class="text-end"><?= (int)$r['actual_qty'] ?> <span class="text-muted small"><?= e($r['unit']) ?></span></td>
          <td class="text-end"><?= $r['reserved_qty'] ? (int)$r['reserved_qty'] : '<span class="text-muted">—</span>' ?></td>
          <td class="text-end"><span class="dash-pill <?= $r['available_qty'] < 0 ? 'dash-pill-red' : ($r['available_qty'] == 0 ? 'dash-pill-gray' : 'dash-pill-green') ?>"><?= (int)$r['available_qty'] ?></span></td>
          <td class="text-end"><?= money($r['value']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?>
        <tr><td colspan="6" class="empty-state"><i class="fa-solid fa-scale-balanced"></i><div>No stock to show for this filter.</div></td></tr>
      <?php endif; ?>
      </tbody>
      <?php if ($rows): ?>
      <tfoot><tr class="fw-semibold"><td colspan="2">Total (<?= count($rows) ?> row<?= count($rows) === 1 ? '' : 's' ?>)</td><td class="text-end"><?= number_format($totalActual) ?></td><td class="text-end"><?= number_format($totalReserved) ?></td><td class="text-end"><?= number_format($totalAvailable) ?></td><td class="text-end"><?= money($totalValue) ?></td></tr></tfoot>
      <?php endif; ?>
    </table>
  </div>
  <?php if (!$warehouses): ?><div class="text-muted mt-2">No warehouses set up yet — see Supply Chain &rsaquo; Warehouses.</div><?php endif; ?>
</div>
</div>
<?php
$extra_js = [asset_url('assets/js/inventory.js')];
$extra_js_inline = 'invRowLinks();';
require __DIR__ . '/../includes/footer.php';
