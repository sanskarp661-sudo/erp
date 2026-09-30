<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
$page_title = 'Expense';
fin_require_schema();

$pdo = db();
$id = (int)input('id');
$canEdit = can_edit_module('finance');
$canManage = can_manage_module('finance');
if (!$id) require_module_edit('finance');
$activeTab = 'details';
$modes = fin_payment_modes();

$expense = [
    'id' => 0, 'expense_no' => '', 'expense_date' => today(), 'category' => '', 'account_id' => '', 'vendor_id' => '', 'payee' => '', 'description' => '',
    'amount' => '', 'tax_amount' => '0', 'payment_method' => 'bank_transfer', 'paid_from_account_id' => '', 'reference' => '',
    'cost_center_id' => setting('fin_default_cost_center_id', ''), 'department_id' => '', 'project' => '', 'status' => 'approved', 'notes' => '',
    'approved_by' => '', 'approved_at' => '', 'created_by' => '', 'created_at' => '',
];
if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM expenses WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        flash('danger', 'Expense not found.');
        redirect('/accounting/expenses.php');
    }
    $expense = array_merge($expense, array_map(fn($v) => $v ?? '', $row));
}
$readOnly = !$canEdit;

$expenseLedgers = fin_ledger_options(fn($a) => $a['root_type'] === 'expense');
$moneyAccounts = fin_money_accounts();
$vendors = $pdo->query('SELECT id, name FROM vendors ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);
$costCenters = $pdo->query("SELECT id, name FROM fin_cost_centers WHERE status = 'active' ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);
$departments = $pdo->query("SELECT id, name FROM departments ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);
$projects = $pdo->query("SELECT DISTINCT project FROM expenses WHERE project IS NOT NULL AND project <> '' ORDER BY project")->fetchAll(PDO::FETCH_COLUMN);
$categories = fin_expense_categories();
$approvalLimit = (float)setting('fin_expense_approval_limit', '0');

$errors = [];
if (is_post()) {
    require_module_edit('finance');
    csrf_verify();
    $activeTab = input('active_tab') ?: 'details';
    $old = $expense;
    $expense = array_merge($expense, [
        'expense_date' => input('expense_date') ?: today(),
        'category' => trim((string)input('category')),
        'account_id' => (int)input('account_id') ?: '',
        'vendor_id' => (int)input('vendor_id') ?: '',
        'payee' => trim((string)input('payee')),
        'description' => trim((string)input('description')),
        'amount' => round((float)input('amount'), 2),
        'tax_amount' => round((float)input('tax_amount'), 2),
        'payment_method' => isset($modes[input('payment_method')]) ? input('payment_method') : 'cash',
        'paid_from_account_id' => (int)input('paid_from_account_id') ?: '',
        'reference' => trim((string)input('reference')),
        'cost_center_id' => (int)input('cost_center_id') ?: '',
        'department_id' => (int)input('department_id') ?: '',
        'project' => trim((string)input('project')),
        'notes' => trim((string)input('notes')),
    ]);
    if ($expense['category'] === '') $errors[] = 'Category is required.';
    if ($expense['amount'] <= 0) $errors[] = 'Amount must be more than zero.';
    if ($expense['tax_amount'] < 0 || $expense['tax_amount'] >= $expense['amount']) $errors[] = 'Tax amount must be less than the total amount.';
    if ($expense['account_id'] && !isset($expenseLedgers[$expense['account_id']])) $errors[] = 'Pick an active expense ledger.';
    if ($expense['paid_from_account_id'] && !isset($moneyAccounts[$expense['paid_from_account_id']])) $errors[] = 'Pick an active bank or cash account to pay from.';
    if ($expense['vendor_id'] && !isset($vendors[$expense['vendor_id']])) $errors[] = 'Pick a valid vendor.';

    if (!$errors) {
        // Over the approval limit needs a Finance manager's approval, unless a manager is entering it.
        $needsApproval = $approvalLimit > 0 && $expense['amount'] > $approvalLimit && !$canManage;
        if ($needsApproval) {
            $status = 'pending';
        } elseif ($canManage || !$id) {
            $status = 'approved';
        } else {
            // A non-manager editing a pending or rejected expense sends it (back) for approval.
            $status = $old['status'] === 'approved' ? 'approved' : 'pending';
        }
        $cols = ['expense_date', 'category', 'account_id', 'vendor_id', 'payee', 'description', 'amount', 'tax_amount', 'payment_method', 'paid_from_account_id', 'reference', 'cost_center_id', 'department_id', 'project', 'notes'];
        $vals = array_map(fn($c) => $expense[$c] === '' ? null : $expense[$c], $cols);
        $cols[] = 'status'; $vals[] = $status;
        if ($id) {
            $pdo->prepare('UPDATE expenses SET ' . implode(', ', array_map(fn($c) => "$c = ?", $cols)) . ' WHERE id = ?')->execute([...$vals, $id]);
            log_field_changes('expense', $id, $old, $expense + ['status' => $status], ['amount' => 'Amount', 'category' => 'Category', 'expense_date' => 'Date', 'status' => 'Status']);
        } else {
            $no = fin_next_no('expense', 'EXP', 'expenses', 'expense_no');
            $cols[] = 'expense_no'; $vals[] = $no;
            $cols[] = 'created_by'; $vals[] = current_user()['id'];
            if ($status === 'approved') { $cols[] = 'approved_by'; $vals[] = current_user()['id']; $cols[] = 'approved_at'; $vals[] = date('Y-m-d H:i:s'); }
            $pdo->prepare('INSERT INTO expenses (' . implode(', ', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute($vals);
            $id = (int)$pdo->lastInsertId();
            log_activity('expense', $id, 'created', "Expense $no recorded");
        }
        flash('success', $status === 'pending' ? 'Expense saved and sent for approval.' : 'Expense saved.');
        redirect('/accounting/expenses.php');
    }
}

$sel = fn($a, $b) => (string)$a === (string)$b ? 'selected' : '';
$ro = $readOnly ? 'disabled' : '';
$statusPill = ['approved' => ['Approved', 'success'], 'pending' => ['Pending', 'warning'], 'rejected' => ['Rejected', 'danger']][$expense['status']] ?? ['Draft', 'secondary'];
$approver = null;
if ($expense['approved_by']) {
    $stmt = $pdo->prepare('SELECT name FROM users WHERE id = ?');
    $stmt->execute([$expense['approved_by']]);
    $approver = $stmt->fetchColumn();
}
$page_title = $id ? fin_expense_no_display($expense) : 'New Expense';

require __DIR__ . '/../includes/header.php';
?>
<div class="card p-4">
  <?php if ($errors): ?><div class="alert alert-danger"><?= implode('<br>', array_map('e', $errors)) ?></div><?php endif; ?>
  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0"><?= $id ? e(fin_expense_no_display($expense)) : 'New Expense' ?> <?php if ($id): ?><?= fin_pill($statusPill[0], $statusPill[1]) ?><?php endif; ?></h5>
    <?php if ($id && $canManage && $expense['status'] !== 'approved'): ?>
      <form method="post" action="expenses.php"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="approve">
        <button class="btn btn-sm btn-success"><i class="fa-solid fa-check"></i> Approve</button></form>
    <?php endif; ?>
  </div>
  <?php if (!$id && $approvalLimit > 0 && !$canManage): ?><div class="alert alert-info small py-2">Expenses above <?= e(fin_money($approvalLimit, 2)) ?> go to a Finance manager for approval before they post to the ledger.</div><?php endif; ?>
  <ul class="nav nav-tabs mb-3" id="expTabs">
    <?php foreach (['details' => 'Details', 'payment' => 'Payment', 'accounting' => 'Accounting', 'more' => 'More Info'] as $key => $label): ?>
      <li class="nav-item"><button class="nav-link <?= $activeTab === $key ? 'active' : '' ?>" data-tab="<?= $key ?>" data-bs-toggle="tab" data-bs-target="#pane-<?= $key ?>" type="button"><?= $label ?></button></li>
    <?php endforeach; ?>
  </ul>
  <form method="post" id="expForm">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="active_tab" id="activeTabInput" value="<?= e($activeTab) ?>">
    <div class="tab-content">
      <div class="tab-pane fade <?= $activeTab === 'details' ? 'show active' : '' ?>" id="pane-details">
        <h6 class="text-muted mb-3"><i class="fa-solid fa-receipt"></i> Expense Details</h6>
        <div class="row g-3">
          <div class="col-sm-3"><label class="form-label">Voucher No.</label><input type="text" class="form-control" disabled value="<?= e($id ? fin_expense_no_display($expense) : 'Auto on save') ?>"></div>
          <div class="col-sm-3"><label class="form-label">Expense Date <span class="text-danger">*</span></label><input type="date" name="expense_date" class="form-control" required <?= $ro ?> value="<?= e($expense['expense_date']) ?>"></div>
          <div class="col-sm-3"><label class="form-label">Category <span class="text-danger">*</span></label>
            <input type="text" name="category" class="form-control" list="catList" required maxlength="100" <?= $ro ?> placeholder="e.g. Office Supplies" value="<?= e($expense['category']) ?>">
            <datalist id="catList"><?php foreach ($categories as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist></div>
          <div class="col-sm-3"><label class="form-label">Expense Ledger</label>
            <select name="account_id" class="form-select" <?= $ro ?>><option value="">Default (Operating Expenses)</option>
              <?php foreach ($expenseLedgers as $k => $a): ?><option value="<?= $k ?>" <?= $sel($expense['account_id'], $k) ?>><?= e(fin_account_label($a)) ?></option><?php endforeach; ?></select></div>
          <div class="col-sm-3"><label class="form-label">Vendor</label>
            <select name="vendor_id" class="form-select" <?= $ro ?>><option value="">— Not a registered vendor —</option>
              <?php foreach ($vendors as $k => $n): ?><option value="<?= (int)$k ?>" <?= $sel($expense['vendor_id'], $k) ?>><?= e($n) ?></option><?php endforeach; ?></select></div>
          <div class="col-sm-3"><label class="form-label">Payee (if not a vendor)</label><input type="text" name="payee" class="form-control" maxlength="150" <?= $ro ?> placeholder="e.g. Local Transport" value="<?= e($expense['payee']) ?>"></div>
          <div class="col-sm-3"><label class="form-label">Amount (incl. tax) <span class="text-danger">*</span></label>
            <div class="input-group"><span class="input-group-text"><?= e(setting('currency_symbol', '₹')) ?></span><input type="number" step="0.01" min="0.01" name="amount" class="form-control" required <?= $ro ?> value="<?= e($expense['amount']) ?>"></div></div>
          <div class="col-sm-3"><label class="form-label">Input Tax (GST)</label>
            <div class="input-group"><span class="input-group-text"><?= e(setting('currency_symbol', '₹')) ?></span><input type="number" step="0.01" min="0" name="tax_amount" class="form-control" <?= $ro ?> value="<?= e($expense['tax_amount']) ?>"></div>
            <div class="form-text">Claimable GST included in the amount.</div></div>
          <div class="col-sm-12"><label class="form-label">Description</label><input type="text" name="description" class="form-control" maxlength="255" <?= $ro ?> placeholder="e.g. Stationery Purchase" value="<?= e($expense['description']) ?>"></div>
        </div>
      </div>

      <div class="tab-pane fade <?= $activeTab === 'payment' ? 'show active' : '' ?>" id="pane-payment">
        <h6 class="text-muted mb-3"><i class="fa-solid fa-money-check"></i> Payment</h6>
        <div class="row g-3">
          <div class="col-sm-3"><label class="form-label">Payment Mode</label>
            <select name="payment_method" id="payMode" class="form-select" <?= $ro ?>><?php foreach ($modes as $k => $l): ?><option value="<?= $k ?>" <?= $sel($expense['payment_method'], $k) ?>><?= $l ?></option><?php endforeach; ?></select></div>
          <div class="col-sm-3"><label class="form-label">Paid From</label>
            <select name="paid_from_account_id" class="form-select" <?= $ro ?>><option value="">Default (by payment mode)</option>
              <?php foreach ($moneyAccounts as $k => $a): ?><option value="<?= $k ?>" <?= $sel($expense['paid_from_account_id'], $k) ?>><?= e(fin_account_label($a)) ?></option><?php endforeach; ?></select>
            <div class="form-text">Default: Cash in Hand for cash, the primary bank account otherwise.</div></div>
          <div class="col-sm-3"><label class="form-label">Reference / UTR / Bill No.</label><input type="text" name="reference" class="form-control" maxlength="120" <?= $ro ?> value="<?= e($expense['reference']) ?>"></div>
        </div>
      </div>

      <div class="tab-pane fade <?= $activeTab === 'accounting' ? 'show active' : '' ?>" id="pane-accounting">
        <h6 class="text-muted mb-3"><i class="fa-solid fa-sitemap"></i> Accounting Dimensions</h6>
        <div class="row g-3">
          <div class="col-sm-3"><label class="form-label">Cost Center</label>
            <select name="cost_center_id" class="form-select" <?= $ro ?>><option value="">—</option>
              <?php foreach ($costCenters as $k => $l): ?><option value="<?= (int)$k ?>" <?= $sel($expense['cost_center_id'], $k) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
          <div class="col-sm-3"><label class="form-label">Department</label>
            <select name="department_id" class="form-select" <?= $ro ?>><option value="">—</option>
              <?php foreach ($departments as $k => $l): ?><option value="<?= (int)$k ?>" <?= $sel($expense['department_id'], $k) ?>><?= e($l) ?></option><?php endforeach; ?></select>
            <div class="form-text">Budget & Planning tracks actuals by department.</div></div>
          <div class="col-sm-3"><label class="form-label">Project</label>
            <input type="text" name="project" class="form-control" list="projList" maxlength="100" <?= $ro ?> value="<?= e($expense['project']) ?>">
            <datalist id="projList"><?php foreach ($projects as $p): ?><option value="<?= e($p) ?>"><?php endforeach; ?></datalist></div>
        </div>
      </div>

      <div class="tab-pane fade <?= $activeTab === 'more' ? 'show active' : '' ?>" id="pane-more">
        <h6 class="text-muted mb-3"><i class="fa-solid fa-circle-info"></i> More Info</h6>
        <div class="row g-3">
          <div class="col-sm-6"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="4" <?= $ro ?>><?= e($expense['notes']) ?></textarea></div>
          <?php if ($id): ?>
          <div class="col-sm-3 fin-kv"><div class="k">Status</div><div class="v"><?= fin_pill($statusPill[0], $statusPill[1]) ?></div>
            <div class="k mt-3"><?= $expense['status'] === 'rejected' ? 'Rejected' : 'Approved' ?> by</div><div class="v"><?= e($approver ?: '—') ?><?= $expense['approved_at'] ? ' · ' . e(fin_date($expense['approved_at'])) : '' ?></div></div>
          <div class="col-sm-3 fin-kv"><div class="k">Recorded</div><div class="v"><?= e(fin_date($expense['created_at'])) ?></div></div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="page-actions mt-4">
      <?php if (!$readOnly): ?><button type="submit" class="btn btn-brand">Save Expense</button><?php endif; ?>
      <a href="expenses.php" class="btn btn-outline-secondary"><?= $readOnly ? 'Back' : 'Cancel' ?></a>
    </div>
  </form>
</div>
<?php
$extra_js_inline = "
document.addEventListener('DOMContentLoaded', function () {
  var activeTabInput = document.getElementById('activeTabInput');
  document.querySelectorAll('#expTabs [data-tab]').forEach(function (b) { b.addEventListener('shown.bs.tab', function () { activeTabInput.value = b.dataset.tab; }); });
  var form = document.getElementById('expForm');
  form.addEventListener('invalid', function (e) {
    var pane = e.target.closest('.tab-pane');
    if (pane && !pane.classList.contains('active')) {
      var btn = document.querySelector('[data-bs-target=\"#' + pane.id + '\"]');
      if (btn) bootstrap.Tab.getOrCreateInstance(btn).show();
    }
  }, true);
});";
require __DIR__ . '/../includes/footer.php';
