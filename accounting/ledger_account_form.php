<?php
require_once __DIR__ . '/../includes/auth.php';
require_module_edit('finance');
$page_title = 'Create Ledger';
fin_require_schema();

$pdo = db();
$id = (int)input('id');
$accounts = fin_accounts();
$types = fin_account_types();
$rootOfType = [
    'bank' => 'asset', 'cash' => 'asset', 'receivable' => 'asset', 'current_asset' => 'asset', 'fixed_asset' => 'asset', 'stock' => 'asset',
    'payable' => 'liability', 'current_liability' => 'liability', 'loan' => 'liability', 'tax' => 'liability',
    'equity' => 'equity', 'income' => 'income', 'expense' => 'expense', 'cost_of_goods_sold' => 'expense', 'other' => 'expense',
];
$categories = ['balance_sheet' => 'Balance Sheet', 'profit_loss' => 'Profit & Loss'];
$taxApplicability = ['taxable' => 'Taxable', 'exempt' => 'Exempt', 'nil_rated' => 'Nil Rated', 'non_gst' => 'Non-GST'];
$currencies = ['INR' => '₹ INR - Indian Rupee (₹)', 'USD' => '$ USD - US Dollar', 'EUR' => '€ EUR - Euro', 'GBP' => '£ GBP - British Pound', 'AED' => 'AED - UAE Dirham'];
$costCenters = $pdo->query("SELECT id, name FROM fin_cost_centers WHERE status = 'active' ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);
$taxTemplates = $pdo->query('SELECT id, name FROM tax_templates ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);
$projects = $pdo->query("SELECT DISTINCT CONVERT(project USING utf8mb4) COLLATE utf8mb4_unicode_ci FROM ledger_accounts WHERE project IS NOT NULL AND project <> '' UNION SELECT DISTINCT project FROM expenses WHERE project IS NOT NULL AND project <> ''")->fetchAll(PDO::FETCH_COLUMN);

$acct = [
    'id' => 0, 'name' => '', 'account_code' => '', 'description' => '', 'parent_id' => (int)input('parent') ?: '', 'is_group' => 0,
    'account_type' => '', 'account_nature' => 'debit', 'statement_category' => '', 'currency' => strtoupper((string)setting('currency_code', 'INR')),
    'opening_balance' => '0.00', 'opening_date' => today(), 'cost_center_id' => '', 'project' => '', 'is_bank' => 0, 'is_cash' => 0,
    'bank_account_no' => '', 'bank_ifsc' => '', 'tax_applicability' => '', 'default_tax_template_id' => '', 'tags' => '', 'status' => 'active', 'system_key' => null,
];
if ($id) {
    if (!isset($accounts[$id])) {
        flash('danger', 'Account not found.');
        redirect('/accounting/ledger_accounts.php');
    }
    $acct = array_merge($acct, array_map(fn($v) => $v ?? '', $accounts[$id]));
    $page_title = 'Edit Ledger';
}

/** ids of $rootId and everything under it. */
$subtree = function (int $rootId) use ($accounts): array {
    $ids = [$rootId => true];
    do {
        $added = false;
        foreach ($accounts as $aid => $a) {
            if (!isset($ids[$aid]) && isset($ids[(int)$a['parent_id']])) { $ids[$aid] = true; $added = true; }
        }
    } while ($added);
    return $ids;
};

$errors = [];
if (is_post()) {
    csrf_verify();
    $old = $acct;
    $acct = array_merge($acct, [
        'name' => trim((string)input('name')),
        'account_code' => trim((string)input('account_code')),
        'description' => trim((string)input('description')),
        'parent_id' => (int)input('parent_id') ?: '',
        'is_group' => input('is_group') ? 1 : 0,
        'account_type' => isset($types[input('account_type')]) ? input('account_type') : '',
        'account_nature' => input('account_nature') === 'credit' ? 'credit' : 'debit',
        'statement_category' => isset($categories[input('statement_category')]) ? input('statement_category') : '',
        'currency' => isset($currencies[input('currency')]) ? input('currency') : 'INR',
        'opening_balance' => round((float)input('opening_balance'), 2),
        'opening_date' => input('opening_date') ?: null,
        'cost_center_id' => (int)input('cost_center_id') ?: '',
        'project' => trim((string)input('project')),
        'is_bank' => input('is_bank') ? 1 : 0,
        'is_cash' => input('is_cash') ? 1 : 0,
        'bank_account_no' => trim((string)input('bank_account_no')),
        'bank_ifsc' => strtoupper(trim((string)input('bank_ifsc'))),
        'tax_applicability' => isset($taxApplicability[input('tax_applicability')]) ? input('tax_applicability') : '',
        'default_tax_template_id' => (int)input('default_tax_template_id') ?: '',
        'tags' => implode(', ', array_filter(array_map('trim', explode(',', (string)input('tags'))))),
        'status' => input('status') ? 'active' : 'inactive',
    ]);

    if ($acct['name'] === '') $errors[] = 'Ledger Name is required.';
    if ($acct['account_code'] === '') $errors[] = 'Account Code is required.';
    if ($acct['account_type'] === '') $errors[] = 'Account Type is required.';
    $parent = $acct['parent_id'] ? ($accounts[$acct['parent_id']] ?? null) : null;
    if (!$acct['is_group'] && !$parent) $errors[] = 'Pick a Parent Account / Account Group.';
    if ($parent && !$parent['is_group']) $errors[] = 'The parent must be a group account.';
    if ($id && $acct['parent_id'] && isset($subtree($id)[$acct['parent_id']])) $errors[] = 'An account cannot sit under itself or one of its own children.';
    if ($acct['is_bank'] && $acct['is_cash']) $errors[] = 'An account can be a bank account or a cash account, not both.';
    foreach (['name' => 'name', 'account_code' => 'code'] as $col => $label) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM ledger_accounts WHERE $col = ? AND id <> ?");
        $stmt->execute([$acct[$col], $id]);
        if ($acct[$col] !== '' && $stmt->fetchColumn() > 0) $errors[] = "Another account already uses this $label.";
    }
    if ($id && $acct['is_group'] && !$old['is_group']) {
        $stmt = $pdo->prepare('SELECT (SELECT COUNT(*) FROM fin_journal_lines WHERE account_id = ?) + (SELECT COUNT(*) FROM expenses WHERE account_id = ? OR paid_from_account_id = ?)');
        $stmt->execute([$id, $id, $id]);
        if ($stmt->fetchColumn() > 0 || $old['system_key']) $errors[] = 'This ledger already has postings, so it cannot become a group.';
    }
    if ($id && !$acct['is_group'] && $old['is_group']) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM ledger_accounts WHERE parent_id = ?');
        $stmt->execute([$id]);
        if ($stmt->fetchColumn() > 0) $errors[] = 'This group still has accounts under it, so it cannot become a ledger.';
    }
    if ($acct['is_group']) {
        $acct['opening_balance'] = 0;
    }

    if (!$errors) {
        $rootType = $parent['root_type'] ?? ($rootOfType[$acct['account_type']] ?? 'expense');
        $category = $acct['statement_category'] ?: (in_array($rootType, ['income', 'expense'], true) ? 'profit_loss' : 'balance_sheet');
        $cols = ['name', 'account_code', 'description', 'parent_id', 'is_group', 'account_type', 'account_nature', 'currency', 'opening_balance', 'opening_date',
            'cost_center_id', 'project', 'is_bank', 'is_cash', 'bank_account_no', 'bank_ifsc', 'tax_applicability', 'default_tax_template_id', 'tags', 'status'];
        $vals = [];
        foreach ($cols as $c) {
            $v = $acct[$c];
            $vals[] = ($v === '' && !in_array($c, ['name', 'account_code'], true)) ? null : $v;
        }
        $cols[] = 'root_type'; $vals[] = $rootType;
        $cols[] = 'statement_category'; $vals[] = $category;
        if ($id) {
            $pdo->prepare('UPDATE ledger_accounts SET ' . implode(', ', array_map(fn($c) => "$c = ?", $cols)) . ' WHERE id = ?')->execute([...$vals, $id]);
            log_field_changes('ledger_account', $id, $old, $acct, ['name' => 'Name', 'account_code' => 'Code', 'parent_id' => 'Parent', 'account_type' => 'Account Type', 'opening_balance' => 'Opening Balance', 'status' => 'Status']);
            flash('success', 'Ledger updated.');
        } else {
            $pdo->prepare('INSERT INTO ledger_accounts (' . implode(', ', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute($vals);
            $id = (int)$pdo->lastInsertId();
            log_activity('ledger_account', $id, 'created', "New ledger '{$acct['name']}' added");
            flash('success', 'Ledger "' . $acct['name'] . '" created.');
        }
        redirect('/accounting/ledger_accounts.php');
    }
}

$groups = array_filter($accounts, fn($a) => $a['is_group'] && (int)$a['id'] !== $id);
$sel = fn($a, $b) => (string)$a === (string)$b ? 'selected' : '';
$chk = fn($v) => $v ? 'checked' : '';
$sym = setting('currency_symbol', '₹');

require __DIR__ . '/../includes/header.php';
?>
<a href="general_ledger.php" class="text-reset text-decoration-none small"><i class="fa-solid fa-chevron-left"></i> Back</a>
<?php fin_page_head($id ? 'Edit Ledger' : 'Create Ledger', $id ? 'Update this ledger account.' : 'Add a new ledger account to organize and track your financial transactions.',
    '<a href="ledger_accounts.php" class="btn btn-outline-secondary"><i class="fa-regular fa-calendar-check"></i> View Chart of Accounts</a>'); ?>
<?php if ($errors): ?><div class="alert alert-danger"><?= implode('<br>', array_map('e', $errors)) ?></div><?php endif; ?>
<?php if ($acct['system_key']): ?><div class="alert alert-info small"><i class="fa-solid fa-lock"></i> This is a system account: invoices, payments and expenses post to it automatically.</div><?php endif; ?>

<form method="post" id="ledgerForm">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= $id ?>">
  <div class="row g-3 mb-3">
    <div class="col-lg-6">
      <div class="fin-section-card">
        <h2>Basic Information</h2><p class="fin-card-sub">Enter the basic details of the ledger account.</p>
        <div class="mb-3"><label class="form-label">Ledger Name <span class="text-danger">*</span></label>
          <input type="text" name="name" class="form-control" maxlength="120" required placeholder="e.g. HDFC Bank - Operations" value="<?= e($acct['name']) ?>"></div>
        <div class="mb-3"><label class="form-label">Account Code <span class="text-danger">*</span></label>
          <input type="text" name="account_code" class="form-control" maxlength="20" required placeholder="e.g. 1020" value="<?= e($acct['account_code']) ?>"></div>
        <div><label class="form-label">Description</label>
          <textarea name="description" class="form-control" rows="3" maxlength="255" placeholder="Enter a short description for this ledger..."><?= e($acct['description']) ?></textarea></div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="fin-section-card">
        <h2>Account Classification</h2><p class="fin-card-sub">Define the account type and its position in the chart of accounts.</p>
        <div class="row g-3">
          <div class="col-sm-6"><label class="form-label">Parent Account / Account Group <span class="text-danger" id="parentReq">*</span></label>
            <select name="parent_id" class="form-select" id="parentSelect">
              <option value="">Select parent account</option>
              <?php foreach ($groups as $gid => $g): ?><option value="<?= $gid ?>" data-root="<?= e($g['root_type']) ?>" <?= $sel($acct['parent_id'], $gid) ?>><?= e(fin_account_label($g)) ?></option><?php endforeach; ?>
            </select></div>
          <div class="col-sm-6"><label class="form-label">Account Type <span class="text-danger">*</span></label>
            <select name="account_type" class="form-select" required id="typeSelect">
              <option value="">Select account type</option>
              <?php foreach ($types as $k => $l): ?><option value="<?= $k ?>" <?= $sel($acct['account_type'], $k) ?>><?= e($l) ?></option><?php endforeach; ?>
            </select>
            <div class="form-text">"Tax" ledgers count as tax on sales and purchase orders.</div></div>
          <div class="col-12"><label class="form-label d-block">Account Nature <span class="text-danger">*</span></label>
            <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="account_nature" id="natDr" value="debit" <?= $chk($acct['account_nature'] === 'debit') ?>><label class="form-check-label" for="natDr">Debit</label></div>
            <div class="form-check form-check-inline ms-4"><input class="form-check-input" type="radio" name="account_nature" id="natCr" value="credit" <?= $chk($acct['account_nature'] === 'credit') ?>><label class="form-check-label" for="natCr">Credit</label></div></div>
          <div class="col-12"><label class="form-label">Financial Statement Category</label>
            <select name="statement_category" class="form-select" id="catSelect">
              <option value="">Select category</option>
              <?php foreach ($categories as $k => $l): ?><option value="<?= $k ?>" <?= $sel($acct['statement_category'], $k) ?>><?= $l ?></option><?php endforeach; ?>
            </select></div>
        </div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="fin-section-card">
        <h2>Financial Settings</h2><p class="fin-card-sub">Define currency, opening balance and other financial details.</p>
        <div class="row g-3">
          <div class="col-sm-6"><label class="form-label">Currency <span class="text-danger">*</span></label>
            <select name="currency" class="form-select"><?php foreach ($currencies as $k => $l): ?><option value="<?= $k ?>" <?= $sel($acct['currency'], $k) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
          <div class="col-sm-6"><label class="form-label">Opening Balance</label>
            <div class="input-group"><span class="input-group-text"><?= e($sym) ?></span><input type="number" step="0.01" name="opening_balance" class="form-control js-ledger-only" value="<?= e(number_format((float)$acct['opening_balance'], 2, '.', '')) ?>"></div>
            <div class="form-text">In the account's nature; negative for the opposite side.</div></div>
          <div class="col-sm-6"><label class="form-label">As on Date</label><input type="date" name="opening_date" class="form-control" value="<?= e($acct['opening_date']) ?>"></div>
          <div class="col-sm-6"><label class="form-label">Cost Center</label>
            <select name="cost_center_id" class="form-select"><option value="">Select cost center</option>
              <?php foreach ($costCenters as $k => $l): ?><option value="<?= (int)$k ?>" <?= $sel($acct['cost_center_id'], $k) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
          <div class="col-sm-6"><label class="form-label">Project (Optional)</label>
            <input type="text" name="project" class="form-control" list="projectList" maxlength="100" placeholder="Select project" value="<?= e($acct['project']) ?>">
            <datalist id="projectList"><?php foreach ($projects as $p): ?><option value="<?= e($p) ?>"><?php endforeach; ?></datalist></div>
        </div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="fin-section-card">
        <h2>Additional Settings</h2><p class="fin-card-sub">Additional configurations for this ledger.</p>
        <div class="form-check form-switch fin-switch-row ps-0">
          <label class="form-check-label" for="isGroup">Is Group?</label><input class="form-check-input ms-0" type="checkbox" name="is_group" id="isGroup" value="1" <?= $chk($acct['is_group']) ?> <?= $acct['system_key'] ? 'disabled' : '' ?>>
          <small class="text-muted">A group holds other accounts; only ledgers take postings.</small>
          <?php if ($acct['system_key'] && $acct['is_group']): ?><input type="hidden" name="is_group" value="1"><?php endif; ?>
        </div>
        <div class="form-check form-switch fin-switch-row ps-0">
          <label class="form-check-label" for="isBank">Is Bank Account?</label><input class="form-check-input ms-0 js-ledger-only" type="checkbox" name="is_bank" id="isBank" value="1" <?= $chk($acct['is_bank']) ?>>
          <small class="text-muted">Enable if this is a bank account.</small>
        </div>
        <div class="row g-2 mb-2" id="bankFields">
          <div class="col-sm-6"><input type="text" name="bank_account_no" class="form-control form-control-sm" maxlength="40" placeholder="Bank account number" value="<?= e($acct['bank_account_no']) ?>"></div>
          <div class="col-sm-6"><input type="text" name="bank_ifsc" class="form-control form-control-sm" maxlength="11" style="text-transform:uppercase" placeholder="IFSC" value="<?= e($acct['bank_ifsc']) ?>"></div>
        </div>
        <div class="form-check form-switch fin-switch-row ps-0">
          <label class="form-check-label" for="isCash">Is Cash Account?</label><input class="form-check-input ms-0 js-ledger-only" type="checkbox" name="is_cash" id="isCash" value="1" <?= $chk($acct['is_cash']) ?>>
          <small class="text-muted">Enable if this is a cash account.</small>
        </div>
        <div class="row g-3">
          <div class="col-sm-6"><label class="form-label">Tax Applicable</label>
            <select name="tax_applicability" class="form-select"><option value="">Select tax applicability</option>
              <?php foreach ($taxApplicability as $k => $l): ?><option value="<?= $k ?>" <?= $sel($acct['tax_applicability'], $k) ?>><?= $l ?></option><?php endforeach; ?></select></div>
          <div class="col-sm-6"><label class="form-label">Default Tax</label>
            <select name="default_tax_template_id" class="form-select"><option value="">Select tax</option>
              <?php foreach ($taxTemplates as $k => $l): ?><option value="<?= (int)$k ?>" <?= $sel($acct['default_tax_template_id'], $k) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
          <div class="col-sm-6"><label class="form-label">Tags (Optional)</label>
            <input type="text" name="tags" class="form-control" maxlength="255" placeholder="Add tags..." value="<?= e($acct['tags']) ?>">
            <div class="form-text">Separate tags with commas.</div></div>
          <div class="col-sm-6"><label class="form-label">Status</label>
            <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="status" id="statusSw" value="1" <?= $chk($acct['status'] === 'active') ?>><label class="form-check-label" for="statusSw">Active</label></div>
            <div class="form-text">Inactive accounts will not be available for transactions.</div></div>
        </div>
      </div>
    </div>
  </div>
  <div class="fin-form-footer">
    <a href="ledger_accounts.php" class="btn btn-outline-secondary px-4">Cancel</a>
    <button type="submit" class="btn btn-brand px-4">Save Ledger</button>
  </div>
</form>
<?php
$extra_js_inline = "
document.addEventListener('DOMContentLoaded', function () {
  var isGroup = document.getElementById('isGroup'), isBank = document.getElementById('isBank'), isCash = document.getElementById('isCash');
  var bankFields = document.getElementById('bankFields'), parentReq = document.getElementById('parentReq');
  var typeSel = document.getElementById('typeSelect'), catSel = document.getElementById('catSelect'), parentSel = document.getElementById('parentSelect');
  function sync() {
    bankFields.style.display = isBank.checked && !isGroup.checked ? '' : 'none';
    parentReq.style.visibility = isGroup.checked ? 'hidden' : 'visible';
    document.querySelectorAll('.js-ledger-only').forEach(function (el) { el.disabled = isGroup.checked; });
  }
  [isGroup, isBank].forEach(function (el) { el.addEventListener('change', sync); });
  isBank.addEventListener('change', function () { if (isBank.checked) { isCash.checked = false; if (!typeSel.value) typeSel.value = 'bank'; } });
  isCash.addEventListener('change', function () { if (isCash.checked) { isBank.checked = false; if (!typeSel.value) typeSel.value = 'cash'; } sync(); });
  // Picking a parent suggests nature and statement category from its root type.
  parentSel.addEventListener('change', function () {
    var o = parentSel.options[parentSel.selectedIndex], root = o ? o.getAttribute('data-root') : '';
    if (!root) return;
    document.getElementById(root === 'asset' || root === 'expense' ? 'natDr' : 'natCr').checked = true;
    catSel.value = (root === 'income' || root === 'expense') ? 'profit_loss' : 'balance_sheet';
  });
  sync();
});";
require __DIR__ . '/../includes/footer.php';
