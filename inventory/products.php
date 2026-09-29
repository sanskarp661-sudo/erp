<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
$canEdit = can_edit_module('inventory');
$canManage = can_manage_module('inventory');

if (is_post() && input('action') === 'delete') {
    require_module_manage('inventory');
    csrf_verify();
    $id = (int)input('id');
    try {
        db()->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
        flash('success', 'Product deleted.');
    } catch (PDOException $e) {
        flash('danger', 'Cannot delete: this product is referenced by existing orders or invoices.');
    }
    $back = (string)input('back');
    redirect('/inventory/products.php' . (str_starts_with($back, '?') ? $back : ''));
}

$search = trim((string)input('q'));
$categoryFilter = (int)input('category');
$brandFilter = (int)input('brand');
$statusFilter = in_array(input('status'), ['active', 'inactive'], true) ? input('status') : '';
$stockFilter = in_array(input('stock'), ['in_stock', 'low_stock', 'out_of_stock', 'reorder'], true) ? input('stock') : '';
$sorts = [
    'name'       => ['Name (A–Z)',            'p.name ASC'],
    'newest'     => ['Newest first',          'p.id DESC'],
    'qty_asc'    => ['Stock (low to high)',   'p.quantity ASC, p.name'],
    'qty_desc'   => ['Stock (high to low)',   'p.quantity DESC, p.name'],
    'price_desc' => ['Price (high to low)',   'p.selling_price DESC, p.name'],
    'value_desc' => ['Stock value (highest)', '(GREATEST(p.quantity, 0) * p.cost_price) DESC, p.name'],
];
$sort = array_key_exists((string)input('sort'), $sorts) ? (string)input('sort') : 'name';
$perPage = 25;
$page = max(1, (int)input('page'));

// Filters other than the stock/status "view" tabs, so each tab can show its own count.
$baseWhere = ' WHERE 1=1';
$baseParams = [];
if ($search !== '') {
    $like = '%' . $search . '%';
    $baseWhere .= ' AND (p.name LIKE ? OR p.sku LIKE ? OR p.hsn_sac_code LIKE ? OR p.tags LIKE ?
        OR EXISTS (SELECT 1 FROM product_barcodes pb WHERE pb.product_id = p.id AND pb.barcode = ?))';
    array_push($baseParams, $like, $like, $like, $like, $search);
}
if ($categoryFilter) {
    $baseWhere .= ' AND p.category_id = ?';
    $baseParams[] = $categoryFilter;
}
if ($brandFilter) {
    $baseWhere .= ' AND p.brand_id = ?';
    $baseParams[] = $brandFilter;
}

$viewWhere = '';
$viewParams = [];
if ($statusFilter) {
    $viewWhere .= ' AND p.status = ?';
    $viewParams[] = $statusFilter;
}
if ($stockFilter === 'out_of_stock') {
    $viewWhere .= ' AND p.quantity <= 0';
} elseif ($stockFilter === 'low_stock') {
    $viewWhere .= ' AND p.quantity > 0 AND p.quantity <= p.reorder_level';
} elseif ($stockFilter === 'reorder') {
    $viewWhere .= ' AND p.quantity <= p.reorder_level';
} elseif ($stockFilter === 'in_stock') {
    $viewWhere .= ' AND p.quantity > p.reorder_level';
}

$stmt = db()->prepare("SELECT COUNT(*) total,
    SUM(p.status = 'active') active,
    SUM(p.status <> 'active') inactive,
    SUM(p.quantity > p.reorder_level) in_stock,
    SUM(p.quantity > 0 AND p.quantity <= p.reorder_level) low_stock,
    SUM(p.quantity <= 0) out_of_stock
  FROM products p" . $baseWhere);
$stmt->execute($baseParams);
$tabCounts = array_map('intval', $stmt->fetch());

$from = " FROM products p
  LEFT JOIN categories c ON c.id = p.category_id
  LEFT JOIN brands b ON b.id = p.brand_id" . $baseWhere . $viewWhere;
$params = array_merge($baseParams, $viewParams);

$stmt = db()->prepare("SELECT COUNT(*), COALESCE(SUM(GREATEST(p.quantity, 0) * p.cost_price), 0)" . $from);
$stmt->execute($params);
[$totalRows, $totalValue] = $stmt->fetch(PDO::FETCH_NUM);
$totalRows = (int)$totalRows;
$pages = max(1, (int)ceil($totalRows / $perPage));
$page = min($page, $pages);

$select = "SELECT p.*, c.name category_name, b.name brand_name" . $from . " ORDER BY " . $sorts[$sort][1];

if (input('export') === 'csv') {
    $stmt = db()->prepare($select);
    $stmt->execute($params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="products-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['SKU', 'Name', 'Category', 'Brand', 'HSN/SAC', 'Unit', 'Cost Price', 'Selling Price', 'Quantity', 'Reorder Level', 'Stock Value', 'Status']);
    while ($p = $stmt->fetch()) {
        fputcsv($out, [$p['sku'], $p['name'], $p['category_name'], $p['brand_name'], $p['hsn_sac_code'], $p['unit'],
            $p['cost_price'], $p['selling_price'], $p['quantity'], $p['reorder_level'],
            round(max(0, (int)$p['quantity']) * (float)$p['cost_price'], 2), $p['status']]);
    }
    fclose($out);
    exit;
}

$stmt = db()->prepare($select . ' LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage));
$stmt->execute($params);
$products = $stmt->fetchAll();

$categories = db()->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();
$brands = db()->query("SELECT id, name FROM brands ORDER BY name")->fetchAll();
$filtersActive = $search !== '' || $categoryFilter || $brandFilter || $statusFilter || $stockFilter;

/** Current query string with some keys replaced (null removes a key). */
function products_qs(array $changes = []): string
{
    $keep = ['q', 'category', 'brand', 'status', 'stock', 'sort', 'page'];
    $qs = [];
    foreach ($keep as $k) {
        $v = input($k);
        if ($v !== null && $v !== '' && $v !== '0') $qs[$k] = $v;
    }
    foreach ($changes as $k => $v) {
        if ($v === null || $v === '') unset($qs[$k]); else $qs[$k] = $v;
    }
    return $qs ? '?' . http_build_query($qs) : '';
}

$viewTabs = [
    ['label' => 'All',          'count' => $tabCounts['total'],        'q' => ['status' => null, 'stock' => null],           'on' => !$statusFilter && !$stockFilter],
    ['label' => 'Active',       'count' => $tabCounts['active'],       'q' => ['status' => 'active', 'stock' => null],       'on' => $statusFilter === 'active' && !$stockFilter],
    ['label' => 'In Stock',     'count' => $tabCounts['in_stock'],     'q' => ['status' => null, 'stock' => 'in_stock'],     'on' => !$statusFilter && $stockFilter === 'in_stock'],
    ['label' => 'Low Stock',    'count' => $tabCounts['low_stock'],    'q' => ['status' => null, 'stock' => 'low_stock'],    'on' => !$statusFilter && $stockFilter === 'low_stock'],
    ['label' => 'Out of Stock', 'count' => $tabCounts['out_of_stock'], 'q' => ['status' => null, 'stock' => 'out_of_stock'], 'on' => !$statusFilter && $stockFilter === 'out_of_stock'],
    ['label' => 'Inactive',     'count' => $tabCounts['inactive'],     'q' => ['status' => 'inactive', 'stock' => null],     'on' => $statusFilter === 'inactive' && !$stockFilter],
];
$anyTabOn = (bool)array_filter(array_column($viewTabs, 'on'));

$page_title = 'Products';
require __DIR__ . '/../includes/header.php';
?>
<div class="dash">

<div class="dash-head">
  <div>
    <h1 class="dash-title">Products</h1>
    <p class="dash-sub"><?= number_format($totalRows) ?> item<?= $totalRows === 1 ? '' : 's' ?><?= $filtersActive ? ' ' . ($totalRows === 1 ? 'matches' : 'match') . ' these filters' : '' ?> · <?= money($totalValue) ?> in stock at cost</p>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a href="products.php<?= e(products_qs(['page' => null, 'export' => 'csv'])) ?>" class="btn btn-outline-secondary"><i class="fa-solid fa-file-csv"></i> Export CSV</a>
    <?php if ($canEdit): ?><a href="product_form.php" class="btn btn-brand"><i class="fa-solid fa-plus"></i> Add Product</a><?php endif; ?>
  </div>
</div>

<div class="inv-tabs mb-3">
  <?php foreach ($viewTabs as $t): ?>
    <a href="products.php<?= e(products_qs($t['q'] + ['page' => null])) ?>" class="inv-tab <?= $t['on'] ? 'active' : '' ?>">
      <?= e($t['label']) ?> <span class="inv-tab-count"><?= (int)$t['count'] ?></span>
    </a>
  <?php endforeach; ?>
  <?php if (!$anyTabOn): ?>
    <span class="inv-tab active"><?= $stockFilter === 'reorder' ? 'Needs Reorder' : 'Custom view' ?> <span class="inv-tab-count"><?= $totalRows ?></span></span>
  <?php endif; ?>
</div>

<div class="dash-card">
  <form method="get" class="inv-toolbar" id="productFilters">
    <?php if ($statusFilter): ?><input type="hidden" name="status" value="<?= e($statusFilter) ?>"><?php endif; ?>
    <?php if ($stockFilter): ?><input type="hidden" name="stock" value="<?= e($stockFilter) ?>"><?php endif; ?>
    <div class="inv-search">
      <i class="fa-solid fa-magnifying-glass"></i>
      <input type="search" name="q" value="<?= e($search) ?>" class="form-control" placeholder="Search name, SKU, HSN, tag or barcode" aria-label="Search products">
    </div>
    <select name="category" class="form-select" aria-label="Category" onchange="this.form.submit()">
      <option value="">All categories</option>
      <?php foreach ($categories as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= $categoryFilter === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="brand" class="form-select" aria-label="Brand" onchange="this.form.submit()">
      <option value="">All brands</option>
      <?php foreach ($brands as $b): ?>
        <option value="<?= (int)$b['id'] ?>" <?= $brandFilter === (int)$b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="sort" class="form-select" aria-label="Sort by" onchange="this.form.submit()">
      <?php foreach ($sorts as $k => [$label]): ?>
        <option value="<?= e($k) ?>" <?= $sort === $k ? 'selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-outline-brand">Search</button>
    <?php if ($filtersActive): ?><a href="products.php" class="btn btn-link text-decoration-none">Clear all</a><?php endif; ?>
  </form>

  <div class="table-responsive">
    <table class="table dash-table inv-table mb-0 align-middle">
      <thead><tr>
        <th>Product</th><th>Category</th><th class="text-end">Cost</th><th class="text-end">Price</th>
        <th class="text-end">Margin</th><th style="min-width:150px">Stock</th><th>Status</th><th class="text-end"></th>
      </tr></thead>
      <tbody>
      <?php foreach ($products as $p):
          $qty = (int)$p['quantity'];
          $reorder = (int)$p['reorder_level'];
          $max = max((int)$p['max_stock_level'], $reorder * 3, $qty, 1);
          $stockTone = $qty <= 0 ? 'red' : ($qty <= $reorder ? 'orange' : 'green');
          $stockLabel = $qty <= 0 ? 'Out of stock' : ($qty <= $reorder ? 'Low stock' : 'In stock');
          $price = (float)$p['selling_price'];
          $margin = $price > 0 ? ($price - (float)$p['cost_price']) / $price * 100 : null;
          $viewUrl = 'product_view.php?id=' . (int)$p['id'];
      ?>
        <tr class="dash-row-link" data-href="<?= e($viewUrl) ?>">
          <td>
            <a href="<?= e($viewUrl) ?>" class="dash-product">
              <span class="dash-thumb inv-thumb"><?php if (!empty($p['image'])): ?><img src="<?= base_url($p['image']) ?>" alt=""><?php else: ?><i class="fa-solid fa-box"></i><?php endif; ?></span>
              <span class="d-flex flex-column">
                <span class="fw-semibold text-body"><?= e($p['name']) ?></span>
                <span class="small text-muted"><?= e($p['sku']) ?><?= $p['brand_name'] ? ' · ' . e($p['brand_name']) : '' ?></span>
              </span>
            </a>
          </td>
          <td><?= $p['category_name'] ? e($p['category_name']) : '<span class="text-muted">—</span>' ?></td>
          <td class="text-end"><?= money($p['cost_price']) ?></td>
          <td class="text-end fw-semibold"><?= money($price) ?></td>
          <td class="text-end <?= $margin !== null && $margin < 0 ? 'text-danger' : 'text-muted' ?>"><?= $margin === null ? '—' : number_format($margin, 1) . '%' ?></td>
          <td>
            <div class="d-flex justify-content-between small">
              <span class="fw-semibold"><?= $qty ?> <?= e($p['unit']) ?></span>
              <span class="inv-stock-<?= $stockTone ?>"><?= $stockLabel ?></span>
            </div>
            <div class="inv-bar inv-bar-<?= $stockTone ?>" title="Reorder at <?= $reorder ?>"><span style="width: <?= $qty > 0 ? round(min(100, $qty / $max * 100), 1) : 0 ?>%"></span></div>
          </td>
          <td><span class="dash-pill <?= $p['status'] === 'active' ? 'dash-pill-green' : 'dash-pill-gray' ?>"><?= $p['status'] === 'active' ? 'Active' : 'Inactive' ?></span></td>
          <td class="text-end text-nowrap">
            <?php if ($canEdit): ?><a href="product_form.php?id=<?= (int)$p['id'] ?>" class="btn btn-sm btn-light inv-icon-btn" title="Edit"><i class="fa-solid fa-pen"></i></a><?php endif; ?>
            <div class="dropdown d-inline-block">
              <button class="btn btn-sm btn-light inv-icon-btn" type="button" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}' aria-expanded="false" title="More"><i class="fa-solid fa-ellipsis-vertical"></i></button>
              <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="<?= e($viewUrl) ?>"><i class="fa-solid fa-eye fa-fw"></i> View</a></li>
                <li><a class="dropdown-item" href="<?= base_url('print.php?doctype=product&id=' . (int)$p['id']) ?>" target="_blank"><i class="fa-solid fa-print fa-fw"></i> Print</a></li>
                <li><a class="dropdown-item" href="<?= base_url('reports/stock_balance_report.php?q=' . urlencode($p['sku'])) ?>"><i class="fa-solid fa-scale-balanced fa-fw"></i> Stock balance</a></li>
                <?php if ($canManage): ?>
                <li><hr class="dropdown-divider"></li>
                <li>
                  <form method="post" data-confirm="Delete <?= e($p['name']) ?>?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                    <input type="hidden" name="back" value="<?= e(products_qs()) ?>">
                    <button class="dropdown-item text-danger" type="submit"><i class="fa-solid fa-trash fa-fw"></i> Delete</button>
                  </form>
                </li>
                <?php endif; ?>
              </ul>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$products): ?>
        <tr><td colspan="8" class="empty-state">
          <i class="fa-solid fa-box-open"></i>
          <div><?= $filtersActive ? 'No products match these filters.' : 'No products yet.' ?></div>
          <?php if (!$filtersActive && $canEdit): ?><a href="product_form.php" class="btn btn-brand btn-sm mt-2"><i class="fa-solid fa-plus"></i> Add your first product</a><?php endif; ?>
        </td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($totalRows > 0): ?>
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-3">
    <div class="small text-muted">Showing <?= ($page - 1) * $perPage + 1 ?>–<?= min($page * $perPage, $totalRows) ?> of <?= $totalRows ?></div>
    <?php if ($pages > 1): ?>
    <nav aria-label="Products pages">
      <ul class="pagination pagination-sm mb-0">
        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="products.php<?= e(products_qs(['page' => $page - 1])) ?>">Previous</a></li>
        <?php for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++): ?>
          <li class="page-item <?= $i === $page ? 'active' : '' ?>"><a class="page-link" href="products.php<?= e(products_qs(['page' => $i])) ?>"><?= $i ?></a></li>
        <?php endfor; ?>
        <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>"><a class="page-link" href="products.php<?= e(products_qs(['page' => $page + 1])) ?>">Next</a></li>
      </ul>
    </nav>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

</div>
<?php
$extra_js = [asset_url('assets/js/inventory.js')];
$extra_js_inline = 'invRowLinks();';
require __DIR__ . '/../includes/footer.php';
