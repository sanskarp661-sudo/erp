<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
$canEdit = can_edit_module('procurement');
$canManage = can_manage_module('procurement');

if (is_post() && input('action') === 'delete') {
    require_module_manage('procurement');
    csrf_verify();
    $id = (int)input('id');
    try {
        db()->prepare('DELETE FROM vendors WHERE id = ?')->execute([$id]);
        flash('success', 'Vendor deleted.');
    } catch (PDOException $e) {
        flash('danger', 'Cannot delete: this vendor has existing purchase orders.');
    }
    redirect('/purchases/vendors.php');
}

$vendors = db()->query("
  SELECT v.*, (SELECT COUNT(*) FROM purchase_orders po WHERE po.vendor_id = v.id) order_count
  FROM vendors v ORDER BY v.name
")->fetchAll();

$page_title = 'Vendors';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <input type="text" class="form-control" style="max-width:280px" placeholder="Search vendors..." data-table-search="#vendTable">
  <?php if ($canEdit): ?><a href="vendor_form.php" class="btn btn-brand"><i class="fa-solid fa-plus"></i> Add Vendor</a><?php endif; ?>
</div>
<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-hover" id="vendTable">
      <thead><tr><th>Name</th><th>Company</th><th>Email</th><th>Phone</th><th>Orders</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($vendors as $v): ?>
        <tr>
          <td><?= e($v['name']) ?></td>
          <td><?= e($v['company']) ?></td>
          <td><?= e($v['email']) ?></td>
          <td><?= e($v['phone']) ?></td>
          <td><?= (int)$v['order_count'] ?></td>
          <td class="text-end">
            <a href="<?= base_url('print.php?doctype=vendor&id=' . (int)$v['id']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="Print"><i class="fa-solid fa-print"></i></a>
            <?php if ($canEdit): ?><a href="vendor_form.php?id=<?= (int)$v['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen"></i></a><?php endif; ?>
            <?php if ($canManage): ?>
            <form method="post" class="d-inline" data-confirm="Delete this vendor?">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
              <button class="btn btn-sm btn-outline-danger" type="submit"><i class="fa-solid fa-trash"></i></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$vendors): ?><tr><td colspan="6" class="text-muted text-center">No vendors yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
