<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$pdo = db();
$today = new DateTimeImmutable('today');
$monthStart = $today->modify('first day of this month');

// ---------------------------------------------------------------------
// KPI cards
// ---------------------------------------------------------------------
$counts = $pdo->query("SELECT
    SUM(status = 'active') active_items,
    SUM(status <> 'active') inactive_items,
    SUM(status = 'active' AND quantity <= 0) out_of_stock,
    SUM(status = 'active' AND quantity > 0 AND quantity <= reorder_level) low_stock,
    COALESCE(SUM(CASE WHEN quantity > 0 THEN quantity * cost_price ELSE 0 END), 0) stock_value,
    COALESCE(SUM(CASE WHEN quantity > 0 THEN quantity ELSE 0 END), 0) stock_units
  FROM products")->fetch();
$activeItems = (int)$counts['active_items'];
$inactiveItems = (int)$counts['inactive_items'];
$outOfStock = (int)$counts['out_of_stock'];
$lowStock = (int)$counts['low_stock'];
$stockValue = (float)$counts['stock_value'];
$stockUnits = (int)$counts['stock_units'];
$categoryCount = (int)$pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();

$stmt = $pdo->prepare("SELECT
    COALESCE(SUM(CASE WHEN quantity > 0 THEN quantity ELSE 0 END), 0) qty_in,
    COALESCE(SUM(CASE WHEN quantity < 0 THEN -quantity ELSE 0 END), 0) qty_out
  FROM stock_movements WHERE created_at >= ?");
$stmt->execute([$monthStart->format('Y-m-d 00:00:00')]);
$monthFlow = $stmt->fetch();

// ---------------------------------------------------------------------
// Stock Movement chart: inward vs outward units per day / month.
// ---------------------------------------------------------------------
function inv_flow_series(PDO $pdo, DateTimeImmutable $today, string $range): array
{
    $monthly = $range === '12m';
    if ($monthly) {
        $start = $today->modify('first day of this month')->modify('-11 months');
        $keyFmt = 'Y-m';
        $sqlFmt = '%Y-%m';
        $step = '+1 month';
        $labelFmt = 'M Y';
    } else {
        $start = $today->modify('-' . ((int)$range - 1) . ' days');
        $keyFmt = 'Y-m-d';
        $sqlFmt = '%Y-%m-%d';
        $step = '+1 day';
        $labelFmt = 'M j';
    }
    $stmt = $pdo->prepare("SELECT DATE_FORMAT(created_at, '$sqlFmt') k,
        SUM(CASE WHEN quantity > 0 THEN quantity ELSE 0 END) qty_in,
        SUM(CASE WHEN quantity < 0 THEN -quantity ELSE 0 END) qty_out
      FROM stock_movements WHERE created_at >= ? GROUP BY k");
    $stmt->execute([$start->format('Y-m-d 00:00:00')]);
    $rows = [];
    foreach ($stmt->fetchAll() as $r) $rows[$r['k']] = $r;

    $out = ['labels' => [], 'in' => [], 'out' => []];
    for ($d = $start; $d <= $today; $d = $d->modify($step)) {
        $k = $d->format($keyFmt);
        $out['labels'][] = $d->format($labelFmt);
        $out['in'][] = (int)($rows[$k]['qty_in'] ?? 0);
        $out['out'][] = (int)($rows[$k]['qty_out'] ?? 0);
    }
    return $out;
}
$flowSeries = [
    '7'   => inv_flow_series($pdo, $today, '7'),
    '30'  => inv_flow_series($pdo, $today, '30'),
    '90'  => inv_flow_series($pdo, $today, '90'),
    '12m' => inv_flow_series($pdo, $today, '12m'),
];

// Stock value by category (donut).
$categoryValue = $pdo->query("SELECT p.category_id, c.name,
    SUM(p.quantity * p.cost_price) value, SUM(p.quantity) units
  FROM products p LEFT JOIN categories c ON c.id = p.category_id
  WHERE p.quantity > 0
  GROUP BY p.category_id, c.name
  ORDER BY value DESC")->fetchAll();
$categorySeries = [];
foreach ($categoryValue as $r) {
    $categorySeries[] = [
        'name'  => $r['name'] ?? 'Uncategorised',
        'value' => round((float)$r['value'], 2),
        'units' => (int)$r['units'],
        'url'   => base_url('inventory/products.php' . ($r['category_id'] ? '?category=' . (int)$r['category_id'] : '')),
    ];
}

// Stock by warehouse.
$warehouseStock = $pdo->query("SELECT w.id, w.name, COALESCE(SUM(b.quantity), 0) units,
    COALESCE(SUM(b.quantity * p.cost_price), 0) value, COUNT(DISTINCT CASE WHEN b.quantity > 0 THEN b.product_id END) items
  FROM warehouses w
  LEFT JOIN stock_bins b ON b.warehouse_id = w.id
  LEFT JOIN products p ON p.id = b.product_id
  WHERE w.is_group = 0 AND w.status = 'active'
  GROUP BY w.id, w.name
  ORDER BY value DESC, w.name")->fetchAll();
$maxWarehouseValue = max(1.0, ...array_map(fn($w) => (float)$w['value'], $warehouseStock ?: [['value' => 0]]));

// Tables.
$lowStockItems = $pdo->query("SELECT id, name, sku, image, unit, quantity, reorder_level FROM products
  WHERE status = 'active' AND quantity <= reorder_level AND (quantity <= 0 OR reorder_level > 0)
  ORDER BY quantity <= 0 DESC, quantity - reorder_level ASC, name LIMIT 6")->fetchAll();

$recentMovements = $pdo->query("SELECT m.id, m.type, m.quantity, m.reference, m.created_at,
    p.id product_id, p.name product_name, p.sku, p.image, w.name warehouse_name
  FROM stock_movements m
  JOIN products p ON p.id = m.product_id
  LEFT JOIN warehouses w ON w.id = m.warehouse_id
  ORDER BY m.id DESC LIMIT 6")->fetchAll();

$quickActions = [
    ['label' => 'Add Product',     'icon' => 'fa-solid fa-cube',              'tone' => 'tone-blue',   'url' => 'inventory/product_form.php',        'module' => 'inventory'],
    ['label' => 'New Stock Entry', 'icon' => 'fa-solid fa-dolly',             'tone' => 'tone-green',  'url' => 'supply-chain/stock_entry_form.php', 'module' => 'supply-chain'],
    ['label' => 'Purchase Order',  'icon' => 'fa-solid fa-truck',             'tone' => 'tone-orange', 'url' => 'purchases/order_form.php',          'module' => 'procurement'],
    ['label' => 'Stock Balance',   'icon' => 'fa-solid fa-scale-balanced',    'tone' => 'tone-purple', 'url' => 'reports/stock_balance_report.php',  'module' => null],
    ['label' => 'Categories',      'icon' => 'fa-solid fa-tags',              'tone' => 'tone-teal',   'url' => 'inventory/categories.php',          'module' => null],
    ['label' => 'Inventory Report','icon' => 'fa-solid fa-chart-line',        'tone' => 'tone-red',    'url' => 'reports/inventory_report.php',      'module' => null],
];
$quickActions = array_values(array_filter($quickActions, fn($a) => $a['module'] === null || can_edit_module($a['module'])));

$page_title = 'Inventory';
require __DIR__ . '/../includes/header.php';
?>
<div class="dash">

<div class="dash-head">
  <div>
    <h1 class="dash-title">Inventory Overview</h1>
    <p class="dash-sub">Stock levels, value and movement across every warehouse.</p>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a href="products.php" class="btn btn-outline-brand"><i class="fa-solid fa-list"></i> All Products</a>
    <?php if (can_edit_module('inventory')): ?>
      <a href="product_form.php" class="btn btn-brand"><i class="fa-solid fa-plus"></i> Add Product</a>
    <?php endif; ?>
  </div>
</div>

<div class="dash-kpis mb-3">
  <a class="dash-kpi dash-kpi-blue" href="products.php?status=active">
    <span class="dash-kpi-icon"><i class="fa-solid fa-cube"></i></span>
    <span class="dash-kpi-body">
      <span class="dash-kpi-label">Active Products</span>
      <span class="dash-kpi-value"><?= $activeItems ?></span>
      <span class="dash-kpi-foot text-muted"><?= $inactiveItems ?> inactive · <?= $categoryCount ?> categories</span>
    </span>
  </a>
  <a class="dash-kpi dash-kpi-green" href="<?= base_url('reports/stock_balance_report.php') ?>">
    <span class="dash-kpi-icon"><i class="fa-solid fa-sack-dollar"></i></span>
    <span class="dash-kpi-body">
      <span class="dash-kpi-label">Stock Value</span>
      <span class="dash-kpi-value" title="<?= e(money($stockValue)) ?>"><?= money($stockValue) ?></span>
      <span class="dash-kpi-foot text-muted"><?= number_format($stockUnits) ?> units at cost</span>
    </span>
  </a>
  <a class="dash-kpi dash-kpi-orange" href="products.php?stock=low_stock">
    <span class="dash-kpi-icon"><i class="fa-solid fa-triangle-exclamation"></i></span>
    <span class="dash-kpi-body">
      <span class="dash-kpi-label">Low Stock</span>
      <span class="dash-kpi-value"><?= $lowStock ?></span>
      <span class="dash-kpi-foot dash-link">View items <i class="fa-solid fa-arrow-right"></i></span>
    </span>
  </a>
  <a class="dash-kpi dash-kpi-red" href="products.php?stock=out_of_stock">
    <span class="dash-kpi-icon"><i class="fa-solid fa-ban"></i></span>
    <span class="dash-kpi-body">
      <span class="dash-kpi-label">Out of Stock</span>
      <span class="dash-kpi-value"><?= $outOfStock ?></span>
      <span class="dash-kpi-foot dash-link">View items <i class="fa-solid fa-arrow-right"></i></span>
    </span>
  </a>
  <a class="dash-kpi dash-kpi-teal" href="<?= base_url('supply-chain/stock_movements.php') ?>">
    <span class="dash-kpi-icon"><i class="fa-solid fa-arrow-down"></i></span>
    <span class="dash-kpi-body">
      <span class="dash-kpi-label">Stock In</span>
      <span class="dash-kpi-value"><?= number_format((int)$monthFlow['qty_in']) ?></span>
      <span class="dash-kpi-foot text-muted">units received this month</span>
    </span>
  </a>
  <a class="dash-kpi dash-kpi-purple" href="<?= base_url('supply-chain/stock_movements.php') ?>">
    <span class="dash-kpi-icon"><i class="fa-solid fa-arrow-up"></i></span>
    <span class="dash-kpi-body">
      <span class="dash-kpi-label">Stock Out</span>
      <span class="dash-kpi-value"><?= number_format((int)$monthFlow['qty_out']) ?></span>
      <span class="dash-kpi-foot text-muted">units issued this month</span>
    </span>
  </a>
</div>

<div class="row g-3 mb-3">
  <div class="col-xl-7">
    <div class="dash-card">
      <div class="dash-card-head">
        <div>
          <h2>Stock Movement</h2>
          <p class="dash-card-sub">Units received vs issued across all warehouses.</p>
        </div>
        <select class="form-select form-select-sm dash-select" id="flowRange" aria-label="Date range">
          <option value="7">Last 7 Days</option>
          <option value="30" selected>Last 30 Days</option>
          <option value="90">Last 90 Days</option>
          <option value="12m">Last 12 Months</option>
        </select>
      </div>
      <div class="dash-chart-summary" id="flowSummary"></div>
      <div class="dash-chart-wrap"><canvas id="flowChart"></canvas></div>
    </div>
  </div>
  <div class="col-xl-5">
    <div class="dash-card">
      <div class="dash-card-head">
        <div>
          <h2>Stock Value by Category</h2>
          <p class="dash-card-sub">On-hand quantity at cost price.</p>
        </div>
      </div>
      <?php if ($categorySeries): ?>
      <div class="dash-donut">
        <div class="dash-donut-chart">
          <canvas id="categoryChart"></canvas>
          <div class="dash-donut-center">
            <strong id="categoryTotal"></strong>
            <small>Stock Value</small>
          </div>
        </div>
        <ul class="dash-legend" id="categoryLegend"></ul>
      </div>
      <?php else: ?>
      <div class="empty-state">
        <i class="fa-solid fa-chart-pie"></i>
        <div>No stock on hand yet</div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-xl-7">
    <div class="dash-card">
      <div class="dash-card-head">
        <h2>Low Stock Alerts</h2>
        <a href="products.php?stock=reorder" class="dash-link">View all <i class="fa-solid fa-arrow-right"></i></a>
      </div>
      <div class="table-responsive">
        <table class="table dash-table mb-0">
          <thead><tr><th>Product</th><th>SKU</th><th class="text-end">In Stock</th><th class="text-end">Reorder Level</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($lowStockItems as $p): ?>
            <tr class="dash-row-link" data-href="product_view.php?id=<?= (int)$p['id'] ?>">
              <td>
                <a href="product_view.php?id=<?= (int)$p['id'] ?>" class="dash-product">
                  <span class="dash-thumb"><?php if (!empty($p['image'])): ?><img src="<?= base_url($p['image']) ?>" alt=""><?php else: ?><i class="fa-solid fa-box"></i><?php endif; ?></span>
                  <?= e($p['name']) ?>
                </a>
              </td>
              <td><?= e($p['sku']) ?></td>
              <td class="text-end text-danger fw-semibold"><?= (int)$p['quantity'] ?> <?= e($p['unit']) ?></td>
              <td class="text-end"><?= (int)$p['reorder_level'] ?></td>
              <td class="text-end"><span class="dash-pill <?= (int)$p['quantity'] <= 0 ? 'dash-pill-red' : 'dash-pill-orange' ?>"><?= (int)$p['quantity'] <= 0 ? 'Out of Stock' : 'Low Stock' ?></span></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$lowStockItems): ?>
            <tr><td colspan="5" class="empty-state"><i class="fa-solid fa-circle-check text-success"></i><div>Stock levels look healthy</div></td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-xl-5">
    <div class="dash-card">
      <div class="dash-card-head">
        <h2>Stock by Warehouse</h2>
        <a href="<?= base_url('supply-chain/warehouses.php') ?>" class="dash-link">Warehouses <i class="fa-solid fa-arrow-right"></i></a>
      </div>
      <?php foreach ($warehouseStock as $w): ?>
        <a class="inv-wh" href="<?= base_url('reports/stock_balance_report.php?warehouse=' . (int)$w['id']) ?>">
          <div class="d-flex justify-content-between align-items-baseline gap-2">
            <span class="fw-semibold"><i class="fa-solid fa-warehouse text-muted me-1"></i> <?= e($w['name']) ?></span>
            <span class="fw-semibold"><?= money($w['value']) ?></span>
          </div>
          <div class="inv-bar"><span style="width: <?= round((float)$w['value'] / $maxWarehouseValue * 100, 1) ?>%"></span></div>
          <div class="small text-muted"><?= number_format((int)$w['units']) ?> units · <?= (int)$w['items'] ?> product<?= (int)$w['items'] === 1 ? '' : 's' ?></div>
        </a>
      <?php endforeach; ?>
      <?php if (!$warehouseStock): ?>
        <div class="empty-state"><i class="fa-solid fa-warehouse"></i><div>No warehouses yet</div></div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-xl-7">
    <div class="dash-card">
      <div class="dash-card-head">
        <h2>Recent Stock Movements</h2>
        <a href="<?= base_url('supply-chain/stock_movements.php') ?>" class="dash-link">View all <i class="fa-solid fa-arrow-right"></i></a>
      </div>
      <div class="table-responsive">
        <table class="table dash-table mb-0">
          <thead><tr><th>Product</th><th>Warehouse</th><th>Reference</th><th>Date</th><th class="text-end">Qty</th></tr></thead>
          <tbody>
          <?php foreach ($recentMovements as $m): ?>
            <tr class="dash-row-link" data-href="product_view.php?id=<?= (int)$m['product_id'] ?>">
              <td>
                <a href="product_view.php?id=<?= (int)$m['product_id'] ?>" class="dash-product">
                  <span class="dash-thumb"><?php if (!empty($m['image'])): ?><img src="<?= base_url($m['image']) ?>" alt=""><?php else: ?><i class="fa-solid fa-box"></i><?php endif; ?></span>
                  <?= e($m['product_name']) ?>
                </a>
              </td>
              <td><?= e($m['warehouse_name'] ?? '—') ?></td>
              <td><?= e($m['reference'] ?: ucfirst($m['type'])) ?></td>
              <td class="text-nowrap"><?= e(date('d M Y', strtotime($m['created_at']))) ?></td>
              <td class="text-end"><span class="dash-pill <?= (int)$m['quantity'] >= 0 ? 'dash-pill-green' : 'dash-pill-red' ?>"><?= (int)$m['quantity'] >= 0 ? '+' : '' ?><?= (int)$m['quantity'] ?></span></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$recentMovements): ?>
            <tr><td colspan="5" class="empty-state"><i class="fa-solid fa-arrow-right-arrow-left"></i><div>No stock movements yet</div></td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-xl-5">
    <div class="dash-card">
      <div class="dash-card-head"><h2>Quick Actions</h2></div>
      <div class="inv-actions">
        <?php foreach ($quickActions as $a): ?>
          <a href="<?= base_url($a['url']) ?>" class="dash-action">
            <span class="fin-kpi-icon <?= e($a['tone']) ?>" style="width:42px;height:42px;font-size:1.1rem"><i class="<?= e($a['icon']) ?>"></i></span>
            <span><?= e($a['label']) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

</div>
<?php
$inventoryData = [
    'currency' => setting('currency_symbol', '$'),
    'flow'     => $flowSeries,
    'category' => $categorySeries,
];
$extra_js = [
    'https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js',
    asset_url('assets/js/inventory.js'),
];
$extra_js_inline = 'initInventoryOverview(' . json_encode($inventoryData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ');';
require __DIR__ . '/../includes/footer.php';
