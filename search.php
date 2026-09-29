<?php
/**
 * Global search used by the dashboard's top search bar.
 *  - search.php?q=...            -> full results page
 *  - search.php?q=...&format=json -> top matches for the live dropdown
 * Every lookup is a single-table LIKE against a bound parameter, so no
 * cross-table string comparison (and no collation clash) is involved.
 */
require_once __DIR__ . '/includes/auth.php';
require_login();

$q = trim((string)input('q'));
$asJson = input('format') === 'json';
$limit = $asJson ? 4 : 25;

$canEditCrm = can_edit_module('crm');
$canEditProcurement = can_edit_module('procurement');

$groups = [
    'products' => [
        'label' => 'Products', 'icon' => 'fa-solid fa-box',
        'sql'   => "SELECT id, name title, sku sub FROM products WHERE name LIKE ? OR sku LIKE ? ORDER BY name LIMIT $limit",
        'binds' => 2,
        'url'   => fn($r) => base_url('inventory/product_view.php?id=' . (int)$r['id']),
    ],
    'customers' => [
        'label' => 'Customers', 'icon' => 'fa-solid fa-address-book',
        'sql'   => "SELECT id, name title, phone, email, customer_code FROM customers
                    WHERE name LIKE ? OR phone LIKE ? OR email LIKE ? OR customer_code LIKE ? ORDER BY name LIMIT $limit",
        'binds' => 4,
        'url'   => fn($r) => $canEditCrm ? base_url('crm/customer_form.php?id=' . (int)$r['id']) : base_url('crm/customers.php'),
    ],
    'sales_orders' => [
        'label' => 'Sales Orders', 'icon' => 'fa-solid fa-cart-shopping',
        'sql'   => "SELECT id, order_no title, order_date d, status st FROM sales_orders WHERE order_no LIKE ? ORDER BY id DESC LIMIT $limit",
        'binds' => 1,
        'url'   => fn($r) => base_url('sales/order_view.php?id=' . (int)$r['id']),
    ],
    'purchase_orders' => [
        'label' => 'Purchase Orders', 'icon' => 'fa-solid fa-truck-field',
        'sql'   => "SELECT id, po_no title, order_date d, status st FROM purchase_orders WHERE po_no LIKE ? ORDER BY id DESC LIMIT $limit",
        'binds' => 1,
        'url'   => fn($r) => base_url('purchases/order_view.php?id=' . (int)$r['id']),
    ],
    'invoices' => [
        'label' => 'Invoices', 'icon' => 'fa-solid fa-file-invoice-dollar',
        'sql'   => "SELECT id, invoice_no title, invoice_date d, status st FROM invoices WHERE invoice_no LIKE ? ORDER BY id DESC LIMIT $limit",
        'binds' => 1,
        'url'   => fn($r) => base_url('accounting/invoice_view.php?id=' . (int)$r['id']),
    ],
    'vendors' => [
        'label' => 'Vendors', 'icon' => 'fa-solid fa-handshake',
        'sql'   => "SELECT id, name title, phone, email FROM vendors WHERE name LIKE ? OR phone LIKE ? OR email LIKE ? ORDER BY name LIMIT $limit",
        'binds' => 3,
        'url'   => fn($r) => $canEditProcurement ? base_url('purchases/vendor_form.php?id=' . (int)$r['id']) : base_url('purchases/vendors.php'),
    ],
    'employees' => [
        'label' => 'Employees', 'icon' => 'fa-solid fa-user-tie',
        'sql'   => "SELECT id, name title, employee_code FROM employees WHERE name LIKE ? OR employee_code LIKE ? ORDER BY name LIMIT $limit",
        'binds' => 2,
        'url'   => fn($r) => base_url('hr/employee_view.php?id=' . (int)$r['id']),
    ],
];

$results = [];
$total = 0;
if ($q !== '') {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    foreach ($groups as $key => $g) {
        try {
            $stmt = db()->prepare($g['sql']);
            $stmt->execute(array_fill(0, $g['binds'], $like));
            $rows = $stmt->fetchAll();
        } catch (PDOException $e) {
            $rows = []; // a module's table may not exist yet on older installs
        }
        if (!$rows) continue;
        $items = [];
        foreach ($rows as $r) {
            if (isset($r['d'])) {
                $sub = $r['d'] . ' · ' . ucwords(str_replace('_', ' ', (string)$r['st']));
            } else {
                $sub = '';
                foreach (['sub', 'phone', 'email', 'customer_code', 'employee_code'] as $col) {
                    if (!empty($r[$col])) { $sub = $r[$col]; break; }
                }
            }
            $items[] = ['title' => (string)$r['title'], 'sub' => (string)$sub, 'url' => $g['url']($r)];
        }
        $results[$key] = ['label' => $g['label'], 'icon' => $g['icon'], 'items' => $items];
        $total += count($items);
    }
}

if ($asJson) {
    header('Content-Type: application/json');
    echo json_encode(['q' => $q, 'groups' => array_values($results), 'more_url' => base_url('search.php?q=' . urlencode($q))]);
    exit;
}

$page_title = 'Search';
require __DIR__ . '/includes/header.php';
?>
<form method="get" class="card p-3 mb-3">
  <div class="input-group">
    <span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span>
    <input type="search" name="q" class="form-control" value="<?= e($q) ?>" placeholder="Search products, customers, orders, invoices, vendors, employees..." autofocus>
    <button class="btn btn-brand">Search</button>
  </div>
</form>

<?php if ($q === ''): ?>
  <div class="card p-4 empty-state"><i class="fa-solid fa-magnifying-glass"></i><div>Type something to search across the ERP.</div></div>
<?php elseif (!$results): ?>
  <div class="card p-4 empty-state"><i class="fa-solid fa-circle-question"></i><div>No results for "<?= e($q) ?>".</div></div>
<?php else: ?>
  <p class="text-muted small"><?= $total ?> result<?= $total === 1 ? '' : 's' ?> for "<?= e($q) ?>"</p>
  <div class="row g-3">
    <?php foreach ($results as $group): ?>
      <div class="col-lg-6">
        <div class="card p-3 h-100">
          <h6 class="mb-2"><i class="<?= e($group['icon']) ?> me-1 text-muted"></i> <?= e($group['label']) ?> <span class="badge text-bg-light"><?= count($group['items']) ?></span></h6>
          <div class="list-group list-group-flush">
            <?php foreach ($group['items'] as $it): ?>
              <a href="<?= e($it['url']) ?>" class="list-group-item list-group-item-action d-flex justify-content-between">
                <span><?= e($it['title']) ?></span>
                <small class="text-muted"><?= e($it['sub']) ?></small>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php';
