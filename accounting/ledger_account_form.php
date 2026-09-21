<?php
require_once __DIR__ . '/../includes/auth.php';
require_module_edit('finance');

$id = (int)input('id');
$account = ['id' => 0, 'name' => '', 'account_type' => 'other', 'status' => 'active'];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM ledger_accounts WHERE id = ?');
    $stmt->execute([$id]);
    $account = $stmt->fetch() ?: $account;
}

$error = '';

if (is_post()) {
    csrf_verify();
    $name = input('name');
    $accountType = in_array(input('account_type'), ['tax', 'income', 'expense', 'other'], true) ? input('account_type') : 'other';
    $status = input('status') === 'inactive' ? 'inactive' : 'active';

    if ($name === '') {
        $error = 'Name is required.';
    } else {
        if ($id) {
            db()->prepare('UPDATE ledger_accounts SET name=?, account_type=?, status=? WHERE id=?')->execute([$name, $accountType, $status, $id]);
            flash('success', 'Account updated.');
        } else {
            db()->prepare('INSERT INTO ledger_accounts (name, account_type, status) VALUES (?,?,?)')->execute([$name, $accountType, $status]);
            flash('success', 'Account created.');
        }
        redirect('/accounting/ledger_accounts.php');
    }
    $account = ['id' => $id, 'name' => $name, 'account_type' => $accountType, 'status' => $status];
}

$page_title = $id ? 'Edit Account' : 'Add Account';
require __DIR__ . '/../includes/header.php';
?>
<div class="card p-4" style="max-width:520px">
  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $id ?>">
    <div class="mb-3">
      <label class="form-label">Name</label>
      <input type="text" name="name" class="form-control" required value="<?= e($account['name']) ?>">
    </div>
    <div class="mb-3">
      <label class="form-label">Account Type</label>
      <select name="account_type" class="form-select">
        <option value="tax" <?= $account['account_type'] === 'tax' ? 'selected' : '' ?>>Tax</option>
        <option value="income" <?= $account['account_type'] === 'income' ? 'selected' : '' ?>>Income</option>
        <option value="expense" <?= $account['account_type'] === 'expense' ? 'selected' : '' ?>>Expense</option>
        <option value="other" <?= $account['account_type'] === 'other' ? 'selected' : '' ?>>Other</option>
      </select>
      <div class="form-text">"Tax" accounts count toward Total Tax Amount on documents; everything else counts toward Total Charges.</div>
    </div>
    <div class="mb-3">
      <label class="form-label">Status</label>
      <select name="status" class="form-select">
        <option value="active" <?= $account['status'] === 'active' ? 'selected' : '' ?>>Active</option>
        <option value="inactive" <?= $account['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
      </select>
    </div>
    <div class="page-actions">
      <button type="submit" class="btn btn-brand">Save</button>
      <a href="ledger_accounts.php" class="btn btn-outline-secondary">Cancel</a>
    </div>
  </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
