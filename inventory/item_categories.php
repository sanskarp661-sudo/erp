<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
$canEdit = can_edit_module('inventory');
$canManage = can_manage_module('inventory');

if (is_post() && input('action') === 'delete') {
    require_module_manage('inventory');
    csrf_verify();
    $id = (int)input('id');
    $stmt = db()->prepare('SELECT COUNT(*) FROM products WHERE item_category_id = ?');
    $stmt->execute([$id]);
    if ($stmt->fetchColumn() > 0) {
        flash('danger', 'Cannot delete: this item category still has products assigned to it.');
    } else {
        db()->prepare('DELETE FROM item_categories WHERE id = ?')->execute([$id]);
        flash('success', 'Item category deleted.');
    }
    redirect('/inventory/item_categories.php');
}

$itemCategories = db()->query("
  SELECT ic.*, (SELECT COUNT(*) FROM products p WHERE p.item_category_id = ic.id) product_count
  FROM item_categories ic ORDER BY ic.name
")->fetchAll();

$page_title = 'Item Categories';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <input type="text" class="form-control" style="max-width:260px" placeholder="Search item categories..." data-table-search="#icTable">
  <?php if ($canEdit): ?><a href="item_category_form.php" class="btn btn-brand"><i class="fa-solid fa-plus"></i> Add Item Category</a><?php endif; ?>
</div>
<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-hover" id="icTable">
      <thead><tr><th>Name</th><th>Status</th><th>Products</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($itemCategories as $ic): ?>
        <tr>
          <td><?= e($ic['name']) ?></td>
          <td><span class="badge text-bg-<?= $ic['status'] === 'active' ? 'success' : 'secondary' ?> badge-status"><?= e($ic['status']) ?></span></td>
          <td><?= (int)$ic['product_count'] ?></td>
          <td class="text-end">
            <?php if ($canEdit): ?><a href="item_category_form.php?id=<?= (int)$ic['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen"></i></a><?php endif; ?>
            <?php if ($canManage): ?>
            <form method="post" class="d-inline" data-confirm="Delete this item category?">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$ic['id'] ?>">
              <button class="btn btn-sm btn-outline-danger" type="submit"><i class="fa-solid fa-trash"></i></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$itemCategories): ?><tr><td colspan="4" class="text-muted text-center">No item categories yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
