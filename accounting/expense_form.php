<?php
require_once __DIR__ . '/../includes/auth.php';
require_module_edit('finance');

$id = (int)input('id');
$expense = ['id' => 0, 'category' => '', 'description' => '', 'amount' => '0', 'expense_date' => today(), 'payment_method' => 'cash'];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM expenses WHERE id = ?');
    $stmt->execute([$id]);
    $expense = $stmt->fetch() ?: $expense;
}

$error = '';

if (is_post()) {
    csrf_verify();
    $expense = [
        'id' => $id,
        'category' => input('category'),
        'description' => input('description'),
        'amount' => (float)input('amount'),
        'expense_date' => input('expense_date') ?: today(),
        'payment_method' => input('payment_method') ?: 'cash',
    ];

    if ($expense['category'] === '' || $expense['amount'] <= 0) {
        $error = 'Category and a positive amount are required.';
    } else {
        if ($id) {
            $stmt = db()->prepare('UPDATE expenses SET category=?, description=?, amount=?, expense_date=?, payment_method=? WHERE id=?');
            $stmt->execute([$expense['category'], $expense['description'], $expense['amount'], $expense['expense_date'], $expense['payment_method'], $id]);
            flash('success', 'Expense updated.');
        } else {
            $stmt = db()->prepare('INSERT INTO expenses (category, description, amount, expense_date, payment_method, created_by) VALUES (?,?,?,?,?,?)');
            $stmt->execute([$expense['category'], $expense['description'], $expense['amount'], $expense['expense_date'], $expense['payment_method'], current_user()['id']]);
            flash('success', 'Expense recorded.');
        }
        redirect('/accounting/expenses.php');
    }
}

$page_title = $id ? 'Edit Expense' : 'Add Expense';
require __DIR__ . '/../includes/header.php';
?>
<div class="card p-4" style="max-width:520px">
  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <div class="mb-3">
      <label class="form-label">Category</label>
      <input type="text" name="category" class="form-control" required value="<?= e($expense['category']) ?>" placeholder="Rent, Utilities, Supplies...">
    </div>
    <div class="mb-3">
      <label class="form-label">Description</label>
      <input type="text" name="description" class="form-control" value="<?= e($expense['description']) ?>">
    </div>
    <div class="row g-3">
      <div class="col-sm-6">
        <label class="form-label">Amount</label>
        <input type="number" step="0.01" min="0.01" name="amount" class="form-control" required value="<?= e($expense['amount']) ?>">
      </div>
      <div class="col-sm-6">
        <label class="form-label">Date</label>
        <input type="date" name="expense_date" class="form-control" required value="<?= e($expense['expense_date']) ?>">
      </div>
      <div class="col-sm-6">
        <label class="form-label">Payment Method</label>
        <select name="payment_method" class="form-select">
          <?php foreach (['cash', 'bank_transfer', 'card', 'cheque', 'other'] as $m): ?>
            <option value="<?= $m ?>" <?= $expense['payment_method'] === $m ? 'selected' : '' ?>><?= e(str_replace('_', ' ', ucfirst($m))) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="page-actions mt-4">
      <button type="submit" class="btn btn-brand">Save</button>
      <a href="expenses.php" class="btn btn-outline-secondary">Cancel</a>
    </div>
  </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
