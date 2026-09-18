<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

if (is_post() && input('action') === 'delete') {
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
  <a href="product_form.php" class="btn btn-brand"><i class="fa-solid fa-plus"></i> Add Product</a>
</div>
<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-hover" id="prodTable">
      <thead><tr><th>SKU</th><th>Name</th><th>Category</th><th class="text-end">Cost</th><th class="text-end">Price</th><th class="text-end">Qty</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($products as $p): ?>
        <tr class="<?= $p['quantity'] <= $p['reorder_level'] ? 'table-danger' : '' ?>">
          <td><?= e($p['sku']) ?></td>
          <td><?= e($p['name']) ?></td>
          <td><?= e($p['category_name'] ?? '—') ?></td>
          <td class="text-end"><?= money($p['cost_price']) ?></td>
          <td class="text-end"><?= money($p['selling_price']) ?></td>
          <td class="text-end fw-bold"><?= (int)$p['quantity'] ?> <?= e($p['unit']) ?></td>
          <td><span class="badge text-bg-<?= $p['status'] === 'active' ? 'success' : 'secondary' ?> badge-status"><?= e($p['status']) ?></span></td>
          <td class="text-end">
            <a href="<?= base_url('print.php?doctype=product&id=' . (int)$p['id']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="Print"><i class="fa-solid fa-print"></i></a>
            <a href="product_form.php?id=<?= (int)$p['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen"></i></a>
            <form method="post" class="d-inline" data-confirm="Delete this product?">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
              <button class="btn btn-sm btn-outline-danger" type="submit"><i class="fa-solid fa-trash"></i></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$products): ?><tr><td colspan="8" class="text-muted text-center">No products yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
