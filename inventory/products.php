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

$products = db()->query("
  SELECT p.*, c.name category_name
  FROM products p LEFT JOIN categories c ON c.id = p.category_id
  ORDER BY p.name
")->fetchAll();

$page_title = 'Products';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <input type="text" class="form-control" style="max-width:280px" placeholder="Search products..." data-table-search="#prodTable">
  <?php if ($canEdit): ?><a href="product_form.php" class="btn btn-brand"><i class="fa-solid fa-plus"></i> Add Product</a><?php endif; ?>
</div>
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
      <?php if (!$products): ?><tr><td colspan="9" class="text-muted text-center">No products yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
