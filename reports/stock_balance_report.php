<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$warehouseFilter = (int)input('warehouse');
$search = trim((string)input('q'));
$showZero = input('zero') === '1';

$warehouses = leaf_warehouses();
$products = db()->query("SELECT id, sku, name FROM products WHERE status = 'active' ORDER BY name")->fetchAll();

$actual = [];
foreach (db()->query('SELECT product_id, warehouse_id, quantity FROM stock_bins') as $row) {
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
    if ($search && stripos($p['name'], $search) === false && stripos($p['sku'], $search) === false) {
        continue;
    }
    foreach ($warehouses as $w) {
        if ($warehouseFilter && $warehouseFilter !== (int)$w['id']) continue;
        $actualQty = $actual[$p['id']][$w['id']] ?? 0;
        $reservedQty = $reserved[$p['id']][$w['id']] ?? 0;
        if (!$showZero && $actualQty === 0 && $reservedQty === 0) continue;
        $rows[] = [
            'item_code' => $p['sku'],
            'item_name' => $p['name'],
            'warehouse' => $w['name'],
            'actual_qty' => $actualQty,
            'reserved_qty' => $reservedQty,
            'available_qty' => $actualQty - $reservedQty,
        ];
    }
}

$totalActual = array_sum(array_column($rows, 'actual_qty'));
$totalReserved = array_sum(array_column($rows, 'reserved_qty'));
$totalAvailable = array_sum(array_column($rows, 'available_qty'));

$page_title = 'Stock Balance Report';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-2 no-print">
  <h5 class="mb-0">Stock Balance Report</h5>
  <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
</div>

<form method="get" class="row g-2 align-items-end mb-3 no-print">
  <div class="col-sm-3">
    <label class="form-label">Warehouse</label>
    <select name="warehouse" class="form-select form-select-sm" onchange="this.form.submit()">
      <option value="">All warehouses</option>
      <?php foreach ($warehouses as $w): ?>
        <option value="<?= (int)$w['id'] ?>" <?= $warehouseFilter === (int)$w['id'] ? 'selected' : '' ?>><?= e($w['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-sm-3">
    <label class="form-label">Item</label>
    <input type="text" name="q" class="form-control form-control-sm" value="<?= e($search) ?>" placeholder="Search item code or name">
  </div>
  <div class="col-sm-3">
    <div class="form-check mt-4">
      <input type="checkbox" class="form-check-input" id="zeroCheck" name="zero" value="1" <?= $showZero ? 'checked' : '' ?> onchange="this.form.submit()">
      <label class="form-check-label" for="zeroCheck">Show zero-balance rows</label>
    </div>
  </div>
  <div class="col-sm-3 text-sm-end">
    <button type="submit" class="btn btn-outline-brand btn-sm">Apply</button>
    <a href="stock_balance_report.php" class="btn btn-outline-secondary btn-sm">Reset</a>
  </div>
</form>

<div class="row g-3 mb-3">
  <div class="col-sm-4">
    <div class="stat-card"><div class="icon bg-brand"><i class="fa-solid fa-cubes"></i></div>
      <div><div class="value"><?= (int)$totalActual ?></div><div class="label">Actual Qty</div></div></div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card"><div class="icon bg-orange"><i class="fa-solid fa-lock"></i></div>
      <div><div class="value"><?= (int)$totalReserved ?></div><div class="label">Reserved Qty</div></div></div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card"><div class="icon bg-green"><i class="fa-solid fa-check"></i></div>
      <div><div class="value"><?= (int)$totalAvailable ?></div><div class="label">Available Qty</div></div></div>
  </div>
</div>

<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-sm">
      <thead><tr><th>Item Code</th><th>Item Name</th><th>Warehouse</th><th class="text-end">Actual Qty</th><th class="text-end">Reserved Qty</th><th class="text-end">Available Qty</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= e($r['item_code']) ?></td>
          <td><?= e($r['item_name']) ?></td>
          <td><?= e($r['warehouse']) ?></td>
          <td class="text-end"><?= (int)$r['actual_qty'] ?></td>
          <td class="text-end"><?= $r['reserved_qty'] ? (int)$r['reserved_qty'] : '—' ?></td>
          <td class="text-end fw-bold <?= $r['available_qty'] < 0 ? 'text-danger' : '' ?>"><?= (int)$r['available_qty'] ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="6" class="text-muted text-center">No stock to show for this filter.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if (!$warehouses): ?><div class="text-muted mt-2">No warehouses set up yet — see Supply Chain &rsaquo; Warehouses.</div><?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
