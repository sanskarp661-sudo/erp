<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
$canEdit = can_edit_module('inventory');
$canManage = can_manage_module('inventory');

if (is_post() && input('action') === 'delete') {
    require_module_manage('inventory');
    csrf_verify();
    $id = (int)input('id');
    $stmt = db()->prepare('SELECT COUNT(*) FROM products WHERE unit = (SELECT name FROM uom WHERE id = ?)');
    $stmt->execute([$id]);
    if ($stmt->fetchColumn() > 0) {
        flash('danger', 'Cannot delete: this unit is still used by existing products. Mark it inactive instead.');
    } else {
        db()->prepare('DELETE FROM uom WHERE id = ?')->execute([$id]);
        flash('success', 'Unit deleted.');
    }
    redirect('/inventory/uom.php');
}

$units = db()->query("
  SELECT u.*, (SELECT COUNT(*) FROM products p WHERE p.unit = u.name) product_count
  FROM uom u ORDER BY u.name
")->fetchAll();

$page_title = 'Units of Measure';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <input type="text" class="form-control" style="max-width:260px" placeholder="Search units..." data-table-search="#uomTable">
  <?php if ($canEdit): ?><a href="uom_form.php" class="btn btn-brand"><i class="fa-solid fa-plus"></i> Add Unit</a><?php endif; ?>
</div>
<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-hover" id="uomTable">
      <thead><tr><th>Name</th><th>Status</th><th>Products</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($units as $u): ?>
        <tr>
          <td><?= e($u['name']) ?></td>
          <td><span class="badge text-bg-<?= $u['status'] === 'active' ? 'success' : 'secondary' ?> badge-status"><?= e($u['status']) ?></span></td>
          <td><?= (int)$u['product_count'] ?></td>
          <td class="text-end">
            <?php if ($canEdit): ?><a href="uom_form.php?id=<?= (int)$u['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen"></i></a><?php endif; ?>
            <?php if ($canManage): ?>
            <form method="post" class="d-inline" data-confirm="Delete this unit?">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
              <button class="btn btn-sm btn-outline-danger" type="submit"><i class="fa-solid fa-trash"></i></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$units): ?><tr><td colspan="4" class="text-muted text-center">No units yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
