<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
$canEdit = can_edit_module('sales');
$canManage = can_manage_module('sales');

if (is_post() && input('action') === 'delete') {
    require_module_manage('sales');
    csrf_verify();
    $id = (int)input('id');
    $stmt = db()->prepare('SELECT COUNT(*) FROM sales_orders WHERE tax_template_id = ?');
    $stmt->execute([$id]);
    if ($stmt->fetchColumn() > 0) {
        flash('danger', 'Cannot delete: this template is referenced by existing sales orders.');
    } else {
        db()->prepare('DELETE FROM tax_templates WHERE id = ?')->execute([$id]);
        flash('success', 'Tax template deleted.');
    }
    redirect('/sales/tax_templates.php');
}

$templates = db()->query("
  SELECT tt.*, (SELECT COUNT(*) FROM tax_template_items tti WHERE tti.tax_template_id = tt.id) row_count
  FROM tax_templates tt ORDER BY tt.name
")->fetchAll();

$page_title = 'Tax Templates';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <input type="text" class="form-control" style="max-width:260px" placeholder="Search tax templates..." data-table-search="#ttTable">
  <?php if ($canEdit): ?><a href="tax_template_form.php" class="btn btn-brand"><i class="fa-solid fa-plus"></i> Add Tax Template</a><?php endif; ?>
</div>
<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-hover" id="ttTable">
      <thead><tr><th>Name</th><th>Status</th><th>Rows</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($templates as $t): ?>
        <tr>
          <td><?= e($t['name']) ?></td>
          <td><span class="badge text-bg-<?= $t['status'] === 'active' ? 'success' : 'secondary' ?> badge-status"><?= e($t['status']) ?></span></td>
          <td><?= (int)$t['row_count'] ?></td>
          <td class="text-end">
            <?php if ($canEdit): ?><a href="tax_template_form.php?id=<?= (int)$t['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen"></i></a><?php endif; ?>
            <?php if ($canManage): ?>
            <form method="post" class="d-inline" data-confirm="Delete this tax template?">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
              <button class="btn btn-sm btn-outline-danger" type="submit"><i class="fa-solid fa-trash"></i></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$templates): ?><tr><td colspan="4" class="text-muted text-center">No tax templates yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
