<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
$canEdit = can_edit_module('inventory');
$canManage = can_manage_module('inventory');

if (is_post() && input('action') === 'delete') {
    require_module_manage('inventory');
    csrf_verify();
    $id = (int)input('id');
    $stmt = db()->prepare('SELECT COUNT(*) FROM products WHERE brand_id = ?');
    $stmt->execute([$id]);
    if ($stmt->fetchColumn() > 0) {
        flash('danger', 'Cannot delete: this brand still has products assigned to it.');
    } else {
        db()->prepare('DELETE FROM brands WHERE id = ?')->execute([$id]);
        flash('success', 'Brand deleted.');
    }
    redirect('/inventory/brands.php');
}

$brands = db()->query("
  SELECT b.*, (SELECT COUNT(*) FROM products p WHERE p.brand_id = b.id) product_count
  FROM brands b ORDER BY b.name
")->fetchAll();

$page_title = 'Brands';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <input type="text" class="form-control" style="max-width:260px" placeholder="Search brands..." data-table-search="#brandTable">
  <?php if ($canEdit): ?><a href="brand_form.php" class="btn btn-brand"><i class="fa-solid fa-plus"></i> Add Brand</a><?php endif; ?>
</div>
<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-hover" id="brandTable">
      <thead><tr><th>Name</th><th>Status</th><th>Products</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($brands as $b): ?>
        <tr>
          <td><?= e($b['name']) ?></td>
          <td><span class="badge text-bg-<?= $b['status'] === 'active' ? 'success' : 'secondary' ?> badge-status"><?= e($b['status']) ?></span></td>
          <td><?= (int)$b['product_count'] ?></td>
          <td class="text-end">
            <?php if ($canEdit): ?><a href="brand_form.php?id=<?= (int)$b['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen"></i></a><?php endif; ?>
            <?php if ($canManage): ?>
            <form method="post" class="d-inline" data-confirm="Delete this brand?">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
              <button class="btn btn-sm btn-outline-danger" type="submit"><i class="fa-solid fa-trash"></i></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$brands): ?><tr><td colspan="4" class="text-muted text-center">No brands yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
