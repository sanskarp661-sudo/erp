<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
$canEdit = can_edit_module('finance');
$canManage = can_manage_module('finance');

if (is_post() && input('action') === 'delete') {
    require_module_manage('finance');
    csrf_verify();
    $id = (int)input('id');
    db()->prepare('DELETE FROM expenses WHERE id = ?')->execute([$id]);
    flash('success', 'Expense deleted.');
    redirect('/accounting/expenses.php');
}

$userFilter = (int)input('user');
$userFilterName = null;
$sql = "SELECT e.*, u.name user_name FROM expenses e LEFT JOIN users u ON u.id = e.created_by";
$params = [];
if ($userFilter) {
    $sql .= " WHERE e.created_by = ?";
    $params[] = $userFilter;
    $stmt = db()->prepare('SELECT name FROM users WHERE id = ?');
    $stmt->execute([$userFilter]);
    $userFilterName = $stmt->fetchColumn();
}
$sql .= " ORDER BY e.expense_date DESC, e.id DESC";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$expenses = $stmt->fetchAll();

$total = array_sum(array_column($expenses, 'amount'));

$page_title = 'Expenses';
require __DIR__ . '/../includes/header.php';
?>
<?php if ($userFilter): ?>
  <div class="alert alert-info d-flex justify-content-between align-items-center">
    <span>Showing expenses recorded by <strong><?= e($userFilterName ?: 'Unknown user') ?></strong></span>
    <a href="expenses.php" class="btn btn-sm btn-outline-secondary">Clear filter</a>
  </div>
<?php endif; ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <input type="text" class="form-control" style="max-width:280px" placeholder="Search expenses..." data-table-search="#expTable">
  <?php if ($canEdit): ?><a href="expense_form.php" class="btn btn-brand"><i class="fa-solid fa-plus"></i> Add Expense</a><?php endif; ?>
</div>
<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-hover" id="expTable">
      <thead><tr><th>Date</th><th>Category</th><th>Description</th><th>Method</th><th>Recorded by</th><th class="text-end">Amount</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($expenses as $ex): ?>
        <tr>
          <td><?= e($ex['expense_date']) ?></td>
          <td><?= e($ex['category']) ?></td>
          <td class="text-muted"><?= e($ex['description']) ?></td>
          <td class="text-capitalize"><?= e(str_replace('_', ' ', $ex['payment_method'])) ?></td>
          <td><?= e($ex['user_name'] ?? 'System') ?></td>
          <td class="text-end"><?= money($ex['amount']) ?></td>
          <td class="text-end">
            <?php if ($canEdit): ?><a href="expense_form.php?id=<?= (int)$ex['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen"></i></a><?php endif; ?>
            <?php if ($canManage): ?>
            <form method="post" class="d-inline" data-confirm="Delete this expense?">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$ex['id'] ?>">
              <button class="btn btn-sm btn-outline-danger" type="submit"><i class="fa-solid fa-trash"></i></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$expenses): ?><tr><td colspan="7" class="text-muted text-center">No expenses recorded yet.</td></tr><?php endif; ?>
      </tbody>
      <?php if ($expenses): ?>
      <tfoot><tr><th colspan="5" class="text-end">Total</th><th class="text-end"><?= money($total) ?></th><th></th></tr></tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
