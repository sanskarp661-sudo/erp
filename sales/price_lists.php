<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
$canEdit = can_edit_module('sales');
$canManage = can_manage_module('sales');

if (is_post() && input('action') === 'delete') {
    require_module_manage('sales');
    csrf_verify();
    $id = (int)input('id');
    $stmt = db()->prepare('SELECT COUNT(*) FROM sales_orders WHERE price_list_id = ?');
    $stmt->execute([$id]);
    if ($stmt->fetchColumn() > 0) {
        flash('danger', 'Cannot delete: this price list is used by existing sales orders.');
    } else {
        db()->prepare('DELETE FROM price_lists WHERE id = ?')->execute([$id]);
        flash('success', 'Price list deleted.');
    }
    redirect('/sales/price_lists.php');
}

$priceLists = db()->query("
  SELECT pl.*, (SELECT COUNT(*) FROM price_list_items pli WHERE pli.price_list_id = pl.id) item_count
  FROM price_lists pl ORDER BY pl.is_default DESC, pl.name
")->fetchAll();

$page_title = 'Price Lists';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <input type="text" class="form-control" style="max-width:260px" placeholder="Search price lists..." data-table-search="#plTable">
  <?php if ($canEdit): ?><a href="price_list_form.php" class="btn btn-brand"><i class="fa-solid fa-plus"></i> Add Price List</a><?php endif; ?>
</div>
<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-hover" id="plTable">
      <thead><tr><th>Name</th><th>Currency</th><th>Default</th><th>Status</th><th>Priced Items</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($priceLists as $pl): ?>
        <tr>
          <td><?= e($pl['name']) ?></td>
          <td><?= e($pl['currency']) ?></td>
          <td><?php if ($pl['is_default']): ?><span class="badge text-bg-brand badge-status">Default</span><?php endif; ?></td>
          <td><span class="badge text-bg-<?= $pl['status'] === 'active' ? 'success' : 'secondary' ?> badge-status"><?= e($pl['status']) ?></span></td>
          <td><?= (int)$pl['item_count'] ?></td>
          <td class="text-end">
            <?php if ($canEdit): ?><a href="price_list_form.php?id=<?= (int)$pl['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen"></i></a><?php endif; ?>
            <?php if ($canManage && !$pl['is_default']): ?>
            <form method="post" class="d-inline" data-confirm="Delete this price list?">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$pl['id'] ?>">
              <button class="btn btn-sm btn-outline-danger" type="submit"><i class="fa-solid fa-trash"></i></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$priceLists): ?><tr><td colspan="6" class="text-muted text-center">No price lists yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
