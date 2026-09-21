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
    redirect('/inventory/products.php');
}

$categoryFilter = (int)input('category');
$statusFilter = in_array(input('status'), ['active', 'inactive'], true) ? input('status') : '';
$stockFilter = in_array(input('stock'), ['in_stock', 'low_stock', 'out_of_stock'], true) ? input('stock') : '';

$sql = "SELECT p.*, c.name category_name FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE 1=1";
$params = [];
if ($categoryFilter) {
    $sql .= " AND p.category_id = ?";
    $params[] = $categoryFilter;
}
if ($statusFilter) {
    $sql .= " AND p.status = ?";
    $params[] = $statusFilter;
}
if ($stockFilter === 'out_of_stock') {
    $sql .= " AND p.quantity <= 0";
} elseif ($stockFilter === 'low_stock') {
    $sql .= " AND p.quantity > 0 AND p.quantity <= p.reorder_level";
} elseif ($stockFilter === 'in_stock') {
    $sql .= " AND p.quantity > p.reorder_level";
}
$sql .= " ORDER BY p.name";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

$categories = db()->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();
$filtersActive = $categoryFilter || $statusFilter || $stockFilter;

$page_title = 'Products';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <input type="text" class="form-control" style="max-width:280px" placeholder="Search products..." data-table-search="#prodTable">
  <?php if ($canEdit): ?><a href="product_form.php" class="btn btn-brand"><i class="fa-solid fa-plus"></i> Add Product</a><?php endif; ?>
</div>

<form method="get" class="card p-3 mb-3">
  <div class="row g-2 align-items-end">
    <div class="col-sm-3">
      <label class="form-label small mb-1">Category</label>
      <select name="category" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="">All categories</option>
        <?php foreach ($categories as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= $categoryFilter === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-sm-3">
      <label class="form-label small mb-1">Status</label>
      <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="">All statuses</option>
        <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
        <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
      </select>
    </div>
    <div class="col-sm-3">
      <label class="form-label small mb-1">Stock</label>
      <select name="stock" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="">Any stock level</option>
        <option value="in_stock" <?= $stockFilter === 'in_stock' ? 'selected' : '' ?>>In stock</option>
        <option value="low_stock" <?= $stockFilter === 'low_stock' ? 'selected' : '' ?>>Low stock</option>
        <option value="out_of_stock" <?= $stockFilter === 'out_of_stock' ? 'selected' : '' ?>>Out of stock</option>
      </select>
    </div>
    <div class="col-sm-3">
      <button type="submit" class="btn btn-outline-brand btn-sm">Apply</button>
      <?php if ($filtersActive): ?><a href="products.php" class="btn btn-outline-secondary btn-sm">Clear</a><?php endif; ?>
    </div>
  </div>
</form>
<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-hover" id="prodTable">
      <thead><tr><th></th><th>SKU</th><th>Name</th><th>Category</th><th class="text-end">Cost</th><th class="text-end">Price</th><th class="text-end">Qty</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($products as $p): ?>
        <tr class="<?= $p['quantity'] <= $p['reorder_level'] ? 'table-danger' : '' ?>">
          <td>
            <?php if (!empty($p['image'])): ?>
              <img src="<?= base_url($p['image']) ?>" alt="" style="width:36px;height:36px;object-fit:cover;border-radius:6px;border:1px solid #dee2e6">
            <?php else: ?>
              <div class="d-flex align-items-center justify-content-center text-muted" style="width:36px;height:36px;border-radius:6px;background:#f1f3f5"><i class="fa-solid fa-box"></i></div>
            <?php endif; ?>
          </td>
          <td><?= e($p['sku']) ?></td>
          <td><a href="product_view.php?id=<?= (int)$p['id'] ?>"><?= e($p['name']) ?></a></td>
          <td><?= e($p['category_name'] ?? '—') ?></td>
          <td class="text-end"><?= money($p['cost_price']) ?></td>
          <td class="text-end"><?= money($p['selling_price']) ?></td>
          <td class="text-end fw-bold"><?= (int)$p['quantity'] ?> <?= e($p['unit']) ?></td>
          <td><span class="badge text-bg-<?= $p['status'] === 'active' ? 'success' : 'secondary' ?> badge-status"><?= e($p['status']) ?></span></td>
          <td class="text-end">
            <a href="product_view.php?id=<?= (int)$p['id'] ?>" class="btn btn-sm btn-outline-secondary" title="View"><i class="fa-solid fa-eye"></i></a>
            <a href="<?= base_url('print.php?doctype=product&id=' . (int)$p['id']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="Print"><i class="fa-solid fa-print"></i></a>
            <?php if ($canEdit): ?><a href="product_form.php?id=<?= (int)$p['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen"></i></a><?php endif; ?>
            <?php if ($canManage): ?>
            <form method="post" class="d-inline" data-confirm="Delete this product?">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
              <button class="btn btn-sm btn-outline-danger" type="submit"><i class="fa-solid fa-trash"></i></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$products): ?><tr><td colspan="9" class="text-muted text-center"><?= $filtersActive ? 'No products match these filters.' : 'No products yet.' ?></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
