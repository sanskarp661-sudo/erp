<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

$pdo = db();
$user = current_user();

// ---------------------------------------------------------------------
// Store switcher. A "store" is a warehouse; picking one scopes the sales
// widgets (by sales_orders.warehouse_id) and the low stock widgets (by
// stock_bins for that warehouse). The choice sticks for the session.
// ---------------------------------------------------------------------
$stores = $pdo->query("SELECT id, name FROM warehouses WHERE status='active' AND is_group=0 ORDER BY name")->fetchAll();
$storeIds = array_map('intval', array_column($stores, 'id'));
if (isset($_GET['store'])) {
    $requested = (int)$_GET['store'];
    $_SESSION['dash_store'] = in_array($requested, $storeIds, true) ? $requested : 0;
    redirect('/dashboard.php');
}
$storeId = (int)($_SESSION['dash_store'] ?? 0);
if (!in_array($storeId, $storeIds, true)) $storeId = 0;
$storeName = 'All Stores';
foreach ($stores as $s) {
    if ((int)$s['id'] === $storeId) $storeName = $s['name'];
}

// SQL fragment + params that scope a sales_orders query (alias "so") to the store.
$soStoreSql = $storeId ? ' AND so.warehouse_id = ?' : '';
$soStoreParams = $storeId ? [$storeId] : [];

function dash_scalar(PDO $pdo, string $sql, array $params = [])
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

function dash_pct_change(float $now, float $before): ?float
{
    if ($before == 0.0) return $now == 0.0 ? 0.0 : null;
    return round(($now - $before) / $before * 100, 1);
}

// ---------------------------------------------------------------------
// KPI cards
// ---------------------------------------------------------------------
$today = new DateTimeImmutable('today');
$monthStart = $today->modify('first day of this month');
$lastMonthStart = $monthStart->modify('-1 month');
// Month-to-date compared with the same number of days last month.
$dayOfMonth = (int)$today->format('j');
$lastMonthDays = (int)$lastMonthStart->format('t');
$lastMonthSameDay = $lastMonthStart->modify('+' . (min($dayOfMonth, $lastMonthDays) - 1) . ' days');

$monthSales = (float)dash_scalar($pdo,
    "SELECT COALESCE(SUM(so.total_amount),0) FROM sales_orders so
     WHERE so.status <> 'cancelled' AND so.order_date BETWEEN ? AND ?" . $soStoreSql,
    array_merge([$monthStart->format('Y-m-d'), $today->format('Y-m-d')], $soStoreParams));
$lastMonthSales = (float)dash_scalar($pdo,
    "SELECT COALESCE(SUM(so.total_amount),0) FROM sales_orders so
     WHERE so.status <> 'cancelled' AND so.order_date BETWEEN ? AND ?" . $soStoreSql,
    array_merge([$lastMonthStart->format('Y-m-d'), $lastMonthSameDay->format('Y-m-d')], $soStoreParams));
$salesChange = dash_pct_change($monthSales, $lastMonthSales);

$unpaid = $pdo->query("SELECT COALESCE(SUM(total - amount_paid),0) amt, COUNT(*) cnt
                       FROM invoices WHERE status IN ('unpaid','partially_paid','overdue')")->fetch();
$unpaidTotal = (float)$unpaid['amt'];
$unpaidCount = (int)$unpaid['cnt'];

$productCount = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE status='active'")->fetchColumn();
$productCountBefore = (int)dash_scalar($pdo, "SELECT COUNT(*) FROM products WHERE status='active' AND created_at < ?", [$monthStart->format('Y-m-d')]);
$productChange = dash_pct_change($productCount, $productCountBefore);

$customerCount = (int)$pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
$customerCountBefore = (int)dash_scalar($pdo, "SELECT COUNT(*) FROM customers WHERE created_at < ?", [$monthStart->format('Y-m-d')]);
$customerChange = dash_pct_change($customerCount, $customerCountBefore);

$openPOs = (int)$pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE status IN ('pending','ordered')")->fetchColumn();

// Low stock: whole-company quantity by default, or the selected store's bins.
if ($storeId) {
    $lowStockFrom = "FROM products p
        LEFT JOIN (SELECT product_id, SUM(quantity) qty FROM stock_bins WHERE warehouse_id = ? GROUP BY product_id) b
          ON b.product_id = p.id
        WHERE p.status = 'active' AND p.is_stock_item = 1 AND COALESCE(b.qty, 0) <= p.reorder_level";
    $lowStockQtyCol = 'COALESCE(b.qty, 0)';
    $lowStockParams = [$storeId];
} else {
    $lowStockFrom = "FROM products p WHERE p.quantity <= p.reorder_level";
    $lowStockQtyCol = 'p.quantity';
    $lowStockParams = [];
}
$lowStockCount = (int)dash_scalar($pdo, "SELECT COUNT(*) $lowStockFrom", $lowStockParams);
$stmt = $pdo->prepare("SELECT p.id, p.name, p.sku, p.image, p.reorder_level, $lowStockQtyCol qty
                       $lowStockFrom ORDER BY qty ASC, p.name LIMIT 5");
$stmt->execute($lowStockParams);
$lowStockItems = $stmt->fetchAll();

// ---------------------------------------------------------------------
// Sales Overview chart: daily buckets for the last 90 days + monthly
// buckets for the last 12 months, sent to the browser once so the range
// and metric dropdowns switch instantly without a reload.
// ---------------------------------------------------------------------
$stmt = $pdo->prepare("SELECT so.order_date d, SUM(so.total_amount) amt, COUNT(*) cnt
    FROM sales_orders so
    WHERE so.status <> 'cancelled' AND so.order_date >= ?" . $soStoreSql . "
    GROUP BY so.order_date");
$stmt->execute(array_merge([$today->modify('-89 days')->format('Y-m-d')], $soStoreParams));
$daily = [];
foreach ($stmt->fetchAll() as $r) $daily[$r['d']] = [(float)$r['amt'], (int)$r['cnt']];

$stmt = $pdo->prepare("SELECT DATE_FORMAT(so.order_date, '%Y-%m') m, SUM(so.total_amount) amt, COUNT(*) cnt
    FROM sales_orders so
    WHERE so.status <> 'cancelled' AND so.order_date >= ?" . $soStoreSql . "
    GROUP BY DATE_FORMAT(so.order_date, '%Y-%m')");
$stmt->execute(array_merge([$monthStart->modify('-11 months')->format('Y-m-d')], $soStoreParams));
$monthly = [];
foreach ($stmt->fetchAll() as $r) $monthly[$r['m']] = [(float)$r['amt'], (int)$r['cnt']];

$chartSeries = [];
foreach (['7d' => 7, '30d' => 30, '90d' => 90] as $key => $days) {
    $labels = $sales = $orders = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = $today->modify("-$i days");
        $row = $daily[$d->format('Y-m-d')] ?? [0, 0];
        $labels[] = $d->format('M j');
        $sales[] = $row[0];
        $orders[] = $row[1];
    }
    $chartSeries[$key] = ['labels' => $labels, 'sales' => $sales, 'orders' => $orders];
}
$labels = $sales = $orders = [];
for ($i = 11; $i >= 0; $i--) {
    $m = $monthStart->modify("-$i months");
    $row = $monthly[$m->format('Y-m')] ?? [0, 0];
    $labels[] = $m->format('M Y');
    $sales[] = $row[0];
    $orders[] = $row[1];
}
$chartSeries['12m'] = ['labels' => $labels, 'sales' => $sales, 'orders' => $orders];

// ---------------------------------------------------------------------
// Sales by Category (net line-item value, before tax). Categories are
// joined by id only, and names are looked up separately.
// ---------------------------------------------------------------------
$categoryNames = [];
foreach ($pdo->query('SELECT id, name FROM categories') as $c) $categoryNames[(int)$c['id']] = $c['name'];

$catPeriods = [
    'this_month' => [$monthStart->format('Y-m-d'), $today->format('Y-m-d')],
    'last_month' => [$lastMonthStart->format('Y-m-d'), $monthStart->modify('-1 day')->format('Y-m-d')],
    'this_year'  => [$today->format('Y-01-01'), $today->format('Y-m-d')],
    'all'        => ['1000-01-01', '9999-12-31'],
];
$catPalette = ['#2563eb', '#16a34a', '#f59e0b', '#8b5cf6', '#cbd5e1'];
$categorySeries = [];
$catStmt = $pdo->prepare("SELECT p.category_id cid, SUM(soi.subtotal) amt
    FROM sales_order_items soi
    JOIN sales_orders so ON so.id = soi.order_id
    JOIN products p ON p.id = soi.product_id
    WHERE so.status <> 'cancelled' AND so.order_date BETWEEN ? AND ?" . $soStoreSql . "
    GROUP BY p.category_id
    ORDER BY amt DESC");
foreach ($catPeriods as $key => [$from, $to]) {
    $catStmt->execute(array_merge([$from, $to], $soStoreParams));
    $rows = $catStmt->fetchAll();
    $total = array_sum(array_map(fn($r) => (float)$r['amt'], $rows));
    $items = [];
    $others = 0.0;
    foreach ($rows as $i => $r) {
        $amt = (float)$r['amt'];
        if ($amt <= 0) continue;
        if (count($items) < 4) {
            $cid = $r['cid'] !== null ? (int)$r['cid'] : null;
            $items[] = [
                'label' => $cid !== null ? ($categoryNames[$cid] ?? 'Category #' . $cid) : 'Uncategorised',
                'value' => $amt,
                'url'   => $cid !== null ? base_url('inventory/products.php?category=' . $cid) : base_url('inventory/products.php'),
            ];
        } else {
            $others += $amt;
        }
    }
    if ($others > 0) $items[] = ['label' => 'Others', 'value' => $others, 'url' => base_url('inventory/categories.php')];
    foreach ($items as $i => &$it) {
        $it['color'] = $it['label'] === 'Others' ? $catPalette[4] : $catPalette[$i];
        $it['pct'] = $total > 0 ? round($it['value'] / $total * 100) : 0;
    }
    unset($it);
    $categorySeries[$key] = ['total' => $total, 'items' => $items];
}

// ---------------------------------------------------------------------
// Recent sales orders
// ---------------------------------------------------------------------
$stmt = $pdo->prepare("SELECT so.id, so.order_no, so.status, so.total_amount, so.order_date, c.name customer_name
    FROM sales_orders so JOIN customers c ON c.id = so.customer_id
    WHERE 1=1" . $soStoreSql . "
    ORDER BY so.order_date DESC, so.id DESC LIMIT 5");
$stmt->execute($soStoreParams);
$recentOrders = $stmt->fetchAll();
$statusTone = ['pending' => 'dash-pill-gray', 'confirmed' => 'dash-pill-blue', 'shipped' => 'dash-pill-purple', 'completed' => 'dash-pill-green', 'cancelled' => 'dash-pill-red'];

// ---------------------------------------------------------------------
// Header extras (search, notifications, store switcher) rendered by
// includes/header.php when $dashboard_topbar is set.
// ---------------------------------------------------------------------
$overdueInvoices = (int)$pdo->query("SELECT COUNT(*) FROM invoices
    WHERE status = 'overdue' OR (status IN ('unpaid','partially_paid') AND due_date IS NOT NULL AND due_date < CURDATE())")->fetchColumn();
$pendingSalesOrders = (int)$pdo->query("SELECT COUNT(*) FROM sales_orders WHERE status = 'pending'")->fetchColumn();
$dashboard_notifications = [];
if ($lowStockCount) $dashboard_notifications[] = ['icon' => 'fa-solid fa-triangle-exclamation', 'tone' => 'tone-purple', 'text' => $lowStockCount . ' item' . ($lowStockCount === 1 ? '' : 's') . ' at or below reorder level', 'url' => base_url('inventory/products.php?stock=reorder')];
if ($overdueInvoices) $dashboard_notifications[] = ['icon' => 'fa-solid fa-file-invoice-dollar', 'tone' => 'tone-red', 'text' => $overdueInvoices . ' overdue invoice' . ($overdueInvoices === 1 ? '' : 's'), 'url' => base_url('accounting/receivables.php')];
if ($pendingSalesOrders) $dashboard_notifications[] = ['icon' => 'fa-solid fa-cart-shopping', 'tone' => 'tone-blue', 'text' => $pendingSalesOrders . ' sales order' . ($pendingSalesOrders === 1 ? '' : 's') . ' pending', 'url' => base_url('sales/orders.php')];
if ($openPOs) $dashboard_notifications[] = ['icon' => 'fa-solid fa-truck-field', 'tone' => 'tone-orange', 'text' => $openPOs . ' open purchase order' . ($openPOs === 1 ? '' : 's'), 'url' => base_url('purchases/orders.php?status=open')];
$dashboard_topbar = true;
$dashboard_stores = $stores;
$dashboard_store_id = $storeId;
$dashboard_store_name = $storeName;

$quotes = [
    'Small steps make big progress.',
    'Quality is never an accident.',
    'Well begun is half done.',
    'Consistency beats intensity.',
    'Great things are built one order at a time.',
    'Focus on the customer and the rest follows.',
    'Progress, not perfection.',
];
$quote = $quotes[(int)$today->format('z') % count($quotes)];
$firstName = explode(' ', trim($user['name'] ?? ''))[0] ?: 'there';

$quickActions = [
    ['label' => 'New Sale',           'icon' => 'fa-solid fa-cart-shopping', 'tone' => 'text-primary', 'url' => 'pos/index.php',             'module' => 'pos'],
    ['label' => 'New Purchase Order', 'icon' => 'fa-solid fa-truck',         'tone' => 'text-success', 'url' => 'purchases/order_form.php',  'module' => 'procurement'],
    ['label' => 'Add Product',        'icon' => 'fa-solid fa-cube',          'tone' => 'text-warning', 'url' => 'inventory/product_form.php', 'module' => 'inventory'],
    ['label' => 'Add Customer',       'icon' => 'fa-solid fa-user-plus',     'tone' => 'dash-text-purple', 'url' => 'crm/customer_form.php', 'module' => 'crm'],
];
$quickActions = array_values(array_filter($quickActions, fn($a) => can_edit_module($a['module'])));

function dash_change_html(?float $pct): string
{
    if ($pct === null) return '<span class="dash-up"><i class="fa-solid fa-arrow-up"></i> New</span> <span class="text-muted">vs last month</span>';
    $cls = $pct < 0 ? 'dash-down' : 'dash-up';
    $icon = $pct < 0 ? 'fa-arrow-down' : 'fa-arrow-up';
    $val = rtrim(rtrim(number_format(abs($pct), 1), '0'), '.');
    return '<span class="' . $cls . '"><i class="fa-solid ' . $icon . '"></i> ' . $val . '%</span> <span class="text-muted">vs last month</span>';
}

$page_title = 'Dashboard';
require __DIR__ . '/includes/header.php';
?>
<div class="dash">

<div class="dash-head">
  <div>
    <h1 class="dash-title">Welcome back, <?= e($firstName) ?>! <span class="dash-wave">👋</span></h1>
    <p class="dash-sub">Here's what's happening with your business today<?= $storeId ? ' at <strong>' . e($storeName) . '</strong>' : '' ?>.</p>
  </div>
  <div class="dash-head-right">
    <div class="dash-date"><?= e($today->format('l, j F Y')) ?></div>
    <div class="dash-quote">"<?= e($quote) ?>"</div>
  </div>
</div>

<div class="row g-3 mb-3">
  <?php foreach ($MODULES as $mod): ?>
    <div class="col-6 col-sm-4 col-lg-3">
      <a href="<?= base_url($mod['home']) ?>" class="module-card" style="--module-color: <?= e($mod['color']) ?>">
        <div class="module-card-icon"><i class="<?= e($mod['icon']) ?>"></i></div>
        <div class="module-card-label"><?= e($mod['label']) ?></div>
      </a>
    </div>
  <?php endforeach; ?>
</div>

<div class="dash-kpis mb-3">
  <a class="dash-kpi dash-kpi-blue" href="<?= base_url('sales/orders.php') ?>">
    <span class="dash-kpi-icon"><i class="fa-solid fa-cart-shopping"></i></span>
    <span class="dash-kpi-body">
      <span class="dash-kpi-label">Sales This Month</span>
      <span class="dash-kpi-value"><?= money($monthSales) ?></span>
      <span class="dash-kpi-foot"><?= dash_change_html($salesChange) ?></span>
    </span>
  </a>
  <a class="dash-kpi dash-kpi-green" href="<?= base_url('accounting/receivables.php') ?>">
    <span class="dash-kpi-icon"><i class="fa-solid fa-file-invoice-dollar"></i></span>
    <span class="dash-kpi-body">
      <span class="dash-kpi-label">Unpaid Invoices</span>
      <span class="dash-kpi-value"><?= money($unpaidTotal) ?></span>
      <span class="dash-kpi-foot text-muted"><?= $unpaidCount ?> pending</span>
    </span>
  </a>
  <a class="dash-kpi dash-kpi-orange" href="<?= base_url('inventory/products.php?status=active') ?>">
    <span class="dash-kpi-icon"><i class="fa-solid fa-cube"></i></span>
    <span class="dash-kpi-body">
      <span class="dash-kpi-label">Active Products</span>
      <span class="dash-kpi-value"><?= $productCount ?></span>
      <span class="dash-kpi-foot"><?= dash_change_html($productChange) ?></span>
    </span>
  </a>
  <a class="dash-kpi dash-kpi-purple" href="<?= base_url('inventory/products.php?stock=reorder') ?>">
    <span class="dash-kpi-icon"><i class="fa-solid fa-triangle-exclamation"></i></span>
    <span class="dash-kpi-body">
      <span class="dash-kpi-label">Low Stock Items</span>
      <span class="dash-kpi-value"><?= $lowStockCount ?></span>
      <span class="dash-kpi-foot dash-link">View items <i class="fa-solid fa-arrow-right"></i></span>
    </span>
  </a>
  <a class="dash-kpi dash-kpi-teal" href="<?= base_url('crm/customers.php') ?>">
    <span class="dash-kpi-icon"><i class="fa-solid fa-user"></i></span>
    <span class="dash-kpi-body">
      <span class="dash-kpi-label">Total Customers</span>
      <span class="dash-kpi-value"><?= $customerCount ?></span>
      <span class="dash-kpi-foot"><?= dash_change_html($customerChange) ?></span>
    </span>
  </a>
  <a class="dash-kpi dash-kpi-red" href="<?= base_url('purchases/orders.php?status=open') ?>">
    <span class="dash-kpi-icon"><i class="fa-solid fa-truck"></i></span>
    <span class="dash-kpi-body">
      <span class="dash-kpi-label">Open Purchase Orders</span>
      <span class="dash-kpi-value"><?= $openPOs ?></span>
      <span class="dash-kpi-foot dash-link">View details <i class="fa-solid fa-arrow-right"></i></span>
    </span>
  </a>
</div>

<div class="row g-3 mb-3">
  <div class="col-xl-7">
    <div class="dash-card">
      <div class="dash-card-head">
        <div>
          <h2>Sales Overview</h2>
          <p class="dash-card-sub">Track your business performance over time.</p>
        </div>
        <div class="d-flex gap-2">
          <select class="form-select form-select-sm dash-select" id="salesRange" aria-label="Date range">
            <option value="7d">Last 7 Days</option>
            <option value="30d">Last 30 Days</option>
            <option value="90d">Last 90 Days</option>
            <option value="12m">Last 12 Months</option>
          </select>
          <select class="form-select form-select-sm dash-select" id="salesMetric" aria-label="Metric">
            <option value="sales">Sales</option>
            <option value="orders">Orders</option>
            <option value="aov">Avg. Order Value</option>
          </select>
        </div>
      </div>
      <div class="dash-chart-summary" id="salesSummary"></div>
      <div class="dash-chart-wrap"><canvas id="salesChart"></canvas></div>
    </div>
  </div>
  <div class="col-xl-5">
    <div class="dash-card">
      <div class="dash-card-head">
        <div>
          <h2>Sales by Category</h2>
          <p class="dash-card-sub">Net item value, before tax.</p>
        </div>
        <select class="form-select form-select-sm dash-select" id="categoryPeriod" aria-label="Period">
          <option value="this_month">This Month</option>
          <option value="last_month">Last Month</option>
          <option value="this_year">This Year</option>
          <option value="all">All Time</option>
        </select>
      </div>
      <div class="dash-donut">
        <div class="dash-donut-chart">
          <canvas id="categoryChart"></canvas>
          <div class="dash-donut-center">
            <strong id="categoryTotal"></strong>
            <small>Total Sales</small>
          </div>
        </div>
        <ul class="dash-legend" id="categoryLegend"></ul>
      </div>
      <div class="empty-state d-none" id="categoryEmpty">
        <i class="fa-solid fa-chart-pie"></i>
        <div>No sales in this period</div>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-xl-7">
    <div class="dash-card">
      <div class="dash-card-head">
        <h2>Recent Sales Orders</h2>
        <a href="<?= base_url('sales/orders.php') ?>" class="dash-link">View all <i class="fa-solid fa-arrow-right"></i></a>
      </div>
      <div class="table-responsive">
        <table class="table dash-table mb-0">
          <thead><tr><th>Order #</th><th>Customer</th><th>Date</th><th class="text-end">Amount</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($recentOrders as $o): ?>
            <tr class="dash-row-link" data-href="<?= base_url('sales/order_view.php?id=' . (int)$o['id']) ?>">
              <td><a href="<?= base_url('sales/order_view.php?id=' . (int)$o['id']) ?>"><?= e($o['order_no']) ?></a></td>
              <td><?= e($o['customer_name']) ?></td>
              <td><?= e($o['order_date']) ?></td>
              <td class="text-end"><?= money($o['total_amount']) ?></td>
              <td><span class="dash-pill <?= $statusTone[$o['status']] ?? 'dash-pill-gray' ?>"><?= e(ucfirst($o['status'])) ?></span></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$recentOrders): ?>
            <tr><td colspan="5" class="empty-state">
              <i class="fa-solid fa-cart-shopping"></i>
              <div>No sales orders yet</div>
            </td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-xl-5">
    <div class="dash-card">
      <div class="dash-card-head">
        <h2>Low Stock Alerts</h2>
        <a href="<?= base_url('inventory/products.php?stock=reorder') ?>" class="dash-link">View all <i class="fa-solid fa-arrow-right"></i></a>
      </div>
      <div class="table-responsive">
        <table class="table dash-table mb-0">
          <thead><tr><th>Product</th><th>SKU</th><th class="text-end">Qty</th><th class="text-end">Reorder</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($lowStockItems as $p): ?>
            <tr class="dash-row-link" data-href="<?= base_url('inventory/product_view.php?id=' . (int)$p['id']) ?>">
              <td>
                <a href="<?= base_url('inventory/product_view.php?id=' . (int)$p['id']) ?>" class="dash-product">
                  <span class="dash-thumb">
                    <?php if (!empty($p['image'])): ?><img src="<?= base_url($p['image']) ?>" alt=""><?php else: ?><i class="fa-solid fa-box"></i><?php endif; ?>
                  </span>
                  <?= e($p['name']) ?>
                </a>
              </td>
              <td><?= e($p['sku']) ?></td>
              <td class="text-end text-danger fw-semibold"><?= (int)$p['qty'] ?></td>
              <td class="text-end"><?= (int)$p['reorder_level'] ?></td>
              <td class="text-end"><span class="dash-pill dash-pill-red"><?= (int)$p['qty'] <= 0 ? 'Out of Stock' : 'Low Stock' ?></span></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$lowStockItems): ?>
            <tr><td colspan="5" class="empty-state">
              <i class="fa-solid fa-circle-check text-success"></i>
              <div>Stock levels look healthy</div>
            </td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-xl-7">
    <div class="dash-banner">
      <div class="dash-banner-eyebrow">Let's grow together</div>
      <div class="dash-banner-title">Efficient Operations. Happier Customers.</div>
      <div class="dash-banner-text">Manage inventory, sales, and supply chain — all in one place.</div>
      <i class="fa-solid fa-leaf dash-banner-leaf"></i>
      <i class="fa-solid fa-seedling dash-banner-leaf2"></i>
    </div>
  </div>
  <div class="col-xl-5">
    <div class="dash-card">
      <div class="dash-card-head"><h2>Quick Actions</h2></div>
      <?php if ($quickActions): ?>
        <div class="dash-actions">
          <?php foreach ($quickActions as $a): ?>
            <a href="<?= base_url($a['url']) ?>" class="dash-action">
              <i class="<?= e($a['icon']) ?> <?= e($a['tone']) ?>"></i>
              <span><?= e($a['label']) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="text-muted small mb-0">Your role has view-only access, so there are no quick actions to show.</p>
      <?php endif; ?>
    </div>
  </div>
</div>

</div>
<?php
$dashboardData = [
    'currency' => setting('currency_symbol', '$'),
    'sales'    => $chartSeries,
    'category' => $categorySeries,
];
$extra_js = [
    'https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js',
    asset_url('assets/js/dashboard.js'),
];
$extra_js_inline = 'window.DASHBOARD_DATA = ' . json_encode($dashboardData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '; initDashboard(window.DASHBOARD_DATA);';
require __DIR__ . '/includes/footer.php';
