<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
$canEdit = can_edit_module('sales');
$canManage = can_manage_module('sales');

if (is_post() && input('action') === 'delete') {
    require_module_manage('sales');
    csrf_verify();
    $id = (int)input('id');
    $stmt = db()->prepare('SELECT COUNT(*) FROM sales_orders WHERE payment_terms_template_id = ?');
    $stmt->execute([$id]);
    if ($stmt->fetchColumn() > 0) {
        flash('danger', 'Cannot delete: this template is referenced by existing sales orders.');
    } else {
        db()->prepare('DELETE FROM payment_terms_templates WHERE id = ?')->execute([$id]);
        flash('success', 'Payment terms template deleted.');
    }
    redirect('/sales/payment_terms_templates.php');
}

$templates = db()->query("
  SELECT pt.*, (SELECT COUNT(*) FROM payment_terms_template_items pti WHERE pti.template_id = pt.id) row_count
  FROM payment_terms_templates pt ORDER BY pt.name
")->fetchAll();

$page_title = 'Payment Terms Templates';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <input type="text" class="form-control" style="max-width:260px" placeholder="Search templates..." data-table-search="#pttTable">
  <?php if ($canEdit): ?><a href="payment_terms_template_form.php" class="btn btn-brand"><i class="fa-solid fa-plus"></i> Add Template</a><?php endif; ?>
</div>
<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-hover" id="pttTable">
      <thead><tr><th>Name</th><th>Status</th><th>Rows</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($templates as $t): ?>
        <tr>
          <td><?= e($t['name']) ?></td>
          <td><span class="badge text-bg-<?= $t['status'] === 'active' ? 'success' : 'secondary' ?> badge-status"><?= e($t['status']) ?></span></td>
          <td><?= (int)$t['row_count'] ?></td>
          <td class="text-end">
            <?php if ($canEdit): ?><a href="payment_terms_template_form.php?id=<?= (int)$t['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen"></i></a><?php endif; ?>
            <?php if ($canManage): ?>
            <form method="post" class="d-inline" data-confirm="Delete this template?">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
              <button class="btn btn-sm btn-outline-danger" type="submit"><i class="fa-solid fa-trash"></i></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$templates): ?><tr><td colspan="4" class="text-muted text-center">No payment terms templates yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
