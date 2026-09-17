<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

if (is_post() && input('action') === 'delete') {
    csrf_verify();
    $id = (int)input('id');
    db()->prepare('DELETE FROM expenses WHERE id = ?')->execute([$id]);
    flash('success', 'Expense deleted.');
    redirect('/accounting/expenses.php');
}

$expenses = db()->query("
  SELECT e.*, u.name user_name FROM expenses e LEFT JOIN users u ON u.id = e.created_by ORDER BY e.expense_date DESC, e.id DESC
")->fetchAll();

$total = array_sum(array_column($expenses, 'amount'));

$page_title = 'Expenses';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <input type="text" class="form-control" style="max-width:280px" placeholder="Search expenses..." data-table-search="#expTable">
  <a href="expense_form.php" class="btn btn-brand"><i class="fa-solid fa-plus"></i> Add Expense</a>
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
            <a href="expense_form.php?id=<?= (int)$ex['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen"></i></a>
            <form method="post" class="d-inline" data-confirm="Delete this expense?">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$ex['id'] ?>">
              <button class="btn btn-sm btn-outline-danger" type="submit"><i class="fa-solid fa-trash"></i></button>
            </form>
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
