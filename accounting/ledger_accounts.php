<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
$canEdit = can_edit_module('finance');
$canManage = can_manage_module('finance');

if (is_post() && input('action') === 'delete') {
    require_module_manage('finance');
    csrf_verify();
    $id = (int)input('id');
    $stmt = db()->prepare('SELECT COUNT(*) FROM sales_order_taxes WHERE account_head_id = ?');
    $stmt->execute([$id]);
    if ($stmt->fetchColumn() > 0) {
        flash('danger', 'Cannot delete: this account is used on existing sales orders.');
    } else {
        db()->prepare('DELETE FROM ledger_accounts WHERE id = ?')->execute([$id]);
        flash('success', 'Account deleted.');
    }
    redirect('/accounting/ledger_accounts.php');
}

$accounts = db()->query('SELECT * FROM ledger_accounts ORDER BY account_type, name')->fetchAll();
$typeBadge = ['tax' => 'warning', 'income' => 'success', 'expense' => 'danger', 'other' => 'secondary'];

$page_title = 'Chart of Accounts';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <input type="text" class="form-control" style="max-width:260px" placeholder="Search accounts..." data-table-search="#acctTable">
  <?php if ($canEdit): ?><a href="ledger_account_form.php" class="btn btn-brand"><i class="fa-solid fa-plus"></i> Add Account</a><?php endif; ?>
</div>
<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-hover" id="acctTable">
      <thead><tr><th>Name</th><th>Type</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($accounts as $a): ?>
        <tr>
          <td><?= e($a['name']) ?></td>
          <td><span class="badge text-bg-<?= $typeBadge[$a['account_type']] ?? 'secondary' ?> badge-status"><?= e($a['account_type']) ?></span></td>
          <td><span class="badge text-bg-<?= $a['status'] === 'active' ? 'success' : 'secondary' ?> badge-status"><?= e($a['status']) ?></span></td>
          <td class="text-end">
            <?php if ($canEdit): ?><a href="ledger_account_form.php?id=<?= (int)$a['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen"></i></a><?php endif; ?>
            <?php if ($canManage): ?>
            <form method="post" class="d-inline" data-confirm="Delete this account?">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
              <button class="btn btn-sm btn-outline-danger" type="submit"><i class="fa-solid fa-trash"></i></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$accounts): ?><tr><td colspan="4" class="text-muted text-center">No accounts yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
