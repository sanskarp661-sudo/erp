<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
$page_title = 'Budget';
fin_require_schema();

$pdo = db();
$id = (int)input('id');
$canEdit = can_edit_module('finance');
if (!$id) require_module_edit('finance');

$costCenters = $pdo->query("SELECT id, name FROM fin_cost_centers WHERE status = 'active' ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);
$departments = $pdo->query('SELECT id, name FROM departments ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);
$categories = fin_expense_categories();

$curFy = fin_fy_start();
$fyOptions = [];
for ($i = -1; $i <= 2; $i++) {
    $s = date('Y-m-d', strtotime($curFy . " $i year"));
    $fyOptions[$s] = 'FY ' . fin_fy_label($s);
}
$presetFy = isset($fyOptions[input('fy')]) ? input('fy') : $curFy;

$b = ['id' => 0, 'name' => '', 'fiscal_year_start' => $presetFy, 'department_id' => '', 'category' => '', 'cost_center_id' => '',
    'amount' => '', 'distribution' => 'equal', 'status' => 'active', 'notes' => ''];
$months = array_fill(1, 12, '');
if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM fin_budgets WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        flash('danger', 'Budget not found.');
        redirect('/accounting/budgets.php');
    }
    $b = array_merge($b, array_map(fn($v) => $v ?? '', $row));
    $fyOptions[$b['fiscal_year_start']] ??= 'FY ' . fin_fy_label($b['fiscal_year_start']);
    $months = fin_budgets($b['fiscal_year_start'])[$id]['months'];
}

$errors = [];
$activeTab = 'details';
if (is_post()) {
    require_module_edit('finance');
    csrf_verify();
    $activeTab = in_array(input('active_tab'), ['details', 'monthly', 'more'], true) ? input('active_tab') : 'details';
    $old = $b;
    $b = array_merge($b, [
        'name' => trim((string)input('name')),
        'fiscal_year_start' => isset($fyOptions[input('fiscal_year_start')]) ? input('fiscal_year_start') : '',
        'department_id' => isset($departments[input('department_id')]) ? (int)input('department_id') : '',
        'category' => trim((string)input('category')),
        'cost_center_id' => isset($costCenters[input('cost_center_id')]) ? (int)input('cost_center_id') : '',
        'amount' => round((float)input('amount'), 2),
        'distribution' => input('distribution') === 'custom' ? 'custom' : 'equal',
        'status' => in_array(input('status'), ['draft', 'active', 'closed'], true) ? input('status') : 'active',
        'notes' => trim((string)input('notes')),
    ]);
    $posted = (array)($_POST['months'] ?? []);
    for ($m = 1; $m <= 12; $m++) $months[$m] = round((float)($posted[$m] ?? 0), 2);

    if ($b['name'] === '') $errors[] = 'Budget name is required.';
    if ($b['fiscal_year_start'] === '') $errors[] = 'Choose a fiscal year.';
    if ($b['distribution'] === 'custom') {
        if (min($months) < 0) $errors[] = 'Monthly amounts cannot be negative.';
        $b['amount'] = round(array_sum($months), 2);
    }
    if ($b['amount'] <= 0) $errors[] = 'Budget amount must be greater than zero.';
    $dup = $pdo->prepare('SELECT COUNT(*) FROM fin_budgets WHERE name = ? AND fiscal_year_start = ? AND id <> ?');
    $dup->execute([$b['name'], $b['fiscal_year_start'], $id]);
    if ($dup->fetchColumn() > 0) $errors[] = 'A budget named "' . $b['name'] . '" already exists for ' . ($fyOptions[$b['fiscal_year_start']] ?? 'this year') . '.';

    if (!$errors) {
        $cols = ['name', 'fiscal_year_start', 'department_id', 'category', 'cost_center_id', 'amount', 'distribution', 'status', 'notes'];
        $vals = array_map(fn($c) => $b[$c] === '' ? null : $b[$c], $cols);
        $pdo->beginTransaction();
        if ($id) {
            $pdo->prepare('UPDATE fin_budgets SET ' . implode(', ', array_map(fn($c) => "$c = ?", $cols)) . ' WHERE id = ?')->execute([...$vals, $id]);
            $changes = [];
            if ((float)$old['amount'] !== (float)$b['amount']) $changes[] = 'amount ' . fin_num($old['amount']) . ' → ' . fin_num($b['amount']);
            if ($old['status'] !== $b['status']) $changes[] = 'status ' . $old['status'] . ' → ' . $b['status'];
            log_activity('budget', $id, 'updated', 'Budget updated: ' . $b['name'] . ($changes ? ' (' . implode(', ', $changes) . ')' : ''));
        } else {
            $pdo->prepare('INSERT INTO fin_budgets (' . implode(', ', $cols) . ', created_by) VALUES (' . implode(',', array_fill(0, count($cols) + 1, '?')) . ')')->execute([...$vals, current_user()['id']]);
            $id = (int)$pdo->lastInsertId();
            log_activity('budget', $id, 'created', 'New budget created: ' . $b['name'] . ' (' . fin_money($b['amount']) . ')');
        }
        $pdo->prepare('DELETE FROM fin_budget_months WHERE budget_id = ?')->execute([$id]);
        if ($b['distribution'] === 'custom') {
            $ins = $pdo->prepare('INSERT INTO fin_budget_months (budget_id, month_index, amount) VALUES (?,?,?)');
            foreach ($months as $m => $v) $ins->execute([$id, $m, $v]);
        }
        $pdo->commit();
        flash('success', 'Budget saved.');
        redirect('/accounting/budgets.php?tab=budgets&fy=' . urlencode($b['fiscal_year_start']));
    }
}

if ($b['distribution'] === 'equal' && (float)$b['amount'] > 0 && !is_post()) {
    $months = array_fill(1, 12, round((float)$b['amount'] / 12, 2));
}
$sel = fn($a, $c) => (string)$a === (string)$c ? 'selected' : '';
$ro = $canEdit ? '' : 'disabled';
$symbol = setting('currency_symbol', '₹');
$page_title = $id ? $b['name'] : 'New Budget';

require __DIR__ . '/../includes/header.php';
?>
<div class="card p-4">
  <?php if ($errors): ?><div class="alert alert-danger"><?= implode('<br>', array_map('e', $errors)) ?></div><?php endif; ?>
  <h5 class="mb-3"><?= e($page_title) ?></h5>
  <ul class="nav nav-tabs mb-3" id="budTabs">
    <?php foreach (['details' => 'Details', 'monthly' => 'Monthly Distribution', 'more' => 'More Info'] as $key => $label): ?>
      <li class="nav-item"><button class="nav-link <?= $activeTab === $key ? 'active' : '' ?>" data-tab="<?= $key ?>" data-bs-toggle="tab" data-bs-target="#pane-<?= $key ?>" type="button"><?= $label ?></button></li>
    <?php endforeach; ?>
  </ul>
  <form method="post" id="budForm">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="active_tab" id="activeTabInput" value="<?= e($activeTab) ?>">
    <div class="tab-content">
      <div class="tab-pane fade <?= $activeTab === 'details' ? 'show active' : '' ?>" id="pane-details">
        <h6 class="text-muted mb-3"><i class="fa-solid fa-bullseye"></i> Budget Details</h6>
        <div class="row g-3">
          <div class="col-sm-6"><label class="form-label">Budget Name <span class="text-danger">*</span></label><input type="text" name="name" class="form-control" required maxlength="150" <?= $ro ?> value="<?= e($b['name']) ?>" placeholder="e.g. Marketing FY <?= e(fin_fy_label($presetFy)) ?>"></div>
          <div class="col-sm-3"><label class="form-label">Fiscal Year <span class="text-danger">*</span></label>
            <select name="fiscal_year_start" class="form-select" <?= $ro ?>><?php foreach ($fyOptions as $k => $l): ?><option value="<?= e($k) ?>" <?= $sel($b['fiscal_year_start'], $k) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
          <div class="col-sm-3"><label class="form-label">Status</label>
            <select name="status" class="form-select" <?= $ro ?>><?php foreach (['draft' => 'Draft', 'active' => 'Active', 'closed' => 'Closed'] as $k => $l): ?><option value="<?= $k ?>" <?= $sel($b['status'], $k) ?>><?= $l ?></option><?php endforeach; ?></select></div>
          <div class="col-sm-3"><label class="form-label">Department</label>
            <select name="department_id" class="form-select" <?= $ro ?>><option value="">All departments</option><?php foreach ($departments as $k => $l): ?><option value="<?= (int)$k ?>" <?= $sel($b['department_id'], $k) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
          <div class="col-sm-3"><label class="form-label">Expense Category</label>
            <input type="text" name="category" class="form-control" list="catList" maxlength="100" <?= $ro ?> value="<?= e($b['category']) ?>" placeholder="All categories">
            <datalist id="catList"><?php foreach ($categories as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist></div>
          <div class="col-sm-3"><label class="form-label">Cost Center</label>
            <select name="cost_center_id" class="form-select" <?= $ro ?>><option value="">All cost centers</option><?php foreach ($costCenters as $k => $l): ?><option value="<?= (int)$k ?>" <?= $sel($b['cost_center_id'], $k) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
          <div class="col-sm-3"><label class="form-label">Annual Amount <span class="text-danger">*</span></label>
            <div class="input-group"><span class="input-group-text"><?= e($symbol) ?></span><input type="number" step="0.01" min="0" name="amount" id="amountInput" class="form-control" <?= $ro ?> value="<?= e($b['amount']) ?>"></div>
            <div class="form-text" id="amountHint"><?= $b['distribution'] === 'custom' ? 'Sum of the monthly amounts.' : 'Spread evenly over 12 months.' ?></div></div>
        </div>
        <p class="small text-muted mt-3 mb-0">Actuals are approved expenses that match the department, category and cost center you pick here. Leave one blank to include all.</p>
      </div>
      <div class="tab-pane fade <?= $activeTab === 'monthly' ? 'show active' : '' ?>" id="pane-monthly">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
          <h6 class="text-muted mb-0"><i class="fa-solid fa-calendar-days"></i> Monthly Distribution</h6>
          <div class="d-flex gap-2 align-items-center">
            <select name="distribution" id="distSel" class="form-select form-select-sm w-auto" <?= $ro ?>>
              <option value="equal" <?= $sel($b['distribution'], 'equal') ?>>Equal every month</option>
              <option value="custom" <?= $sel($b['distribution'], 'custom') ?>>Custom per month</option>
            </select>
            <?php if ($canEdit): ?><button type="button" class="btn btn-sm btn-outline-brand" id="spreadBtn"><i class="fa-solid fa-equals"></i> Spread evenly</button><?php endif; ?>
          </div>
        </div>
        <div class="row g-3">
          <?php for ($m = 1; $m <= 12; $m++): $ts = strtotime($b['fiscal_year_start'] . ' +' . ($m - 1) . ' month'); ?>
            <div class="col-6 col-sm-4 col-lg-2"><label class="form-label small"><?= date('M Y', $ts) ?></label>
              <input type="number" step="0.01" min="0" name="months[<?= $m ?>]" class="form-control form-control-sm month-input" <?= $ro ?> value="<?= e($months[$m]) ?>"></div>
          <?php endfor; ?>
        </div>
        <div class="mt-3 small">Total: <strong id="monthTotal"><?= e(fin_money(array_sum(array_map('floatval', $months)), 2)) ?></strong></div>
      </div>
      <div class="tab-pane fade <?= $activeTab === 'more' ? 'show active' : '' ?>" id="pane-more">
        <h6 class="text-muted mb-3"><i class="fa-solid fa-circle-info"></i> More Info</h6>
        <div class="row g-3">
          <div class="col-sm-6"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="3" maxlength="255" <?= $ro ?>><?= e($b['notes']) ?></textarea></div>
          <?php if ($id): ?>
          <div class="col-sm-3 fin-kv"><div class="k">Created</div><div class="v"><?= e(fin_date($b['created_at'])) ?></div>
            <div class="k mt-3">Last updated</div><div class="v"><?= e($b['updated_at'] ? fin_date($b['updated_at']) : '—') ?></div></div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="page-actions mt-4">
      <?php if ($canEdit): ?><button type="submit" class="btn btn-brand">Save Budget</button><?php endif; ?>
      <a href="budgets.php?fy=<?= e($b['fiscal_year_start']) ?>&tab=budgets" class="btn btn-outline-secondary"><?= $canEdit ? 'Cancel' : 'Back' ?></a>
    </div>
  </form>
</div>
<?php
$extra_js_inline = "
document.addEventListener('DOMContentLoaded', function () {
  var activeTabInput = document.getElementById('activeTabInput');
  document.querySelectorAll('#budTabs [data-tab]').forEach(function (b) { b.addEventListener('shown.bs.tab', function () { activeTabInput.value = b.dataset.tab; }); });
  var amount = document.getElementById('amountInput'), dist = document.getElementById('distSel'), hint = document.getElementById('amountHint'),
      total = document.getElementById('monthTotal'), inputs = document.querySelectorAll('.month-input'), spread = document.getElementById('spreadBtn');
  var fmt = new Intl.NumberFormat('en-IN', { style: 'currency', currency: window.FIN_CURRENCY || 'INR' });
  function sum() { var s = 0; inputs.forEach(function (i) { s += parseFloat(i.value) || 0; }); return Math.round(s * 100) / 100; }
  function even() {
    var a = parseFloat(amount.value) || 0, per = Math.floor(a / 12 * 100) / 100;
    inputs.forEach(function (i, n) { i.value = n < 11 ? per.toFixed(2) : (a - per * 11).toFixed(2); });
  }
  function sync() {
    var custom = dist.value === 'custom';
    amount.readOnly = custom;
    inputs.forEach(function (i) { i.readOnly = !custom; });
    if (custom) amount.value = sum().toFixed(2); else even();
    hint.textContent = custom ? 'Sum of the monthly amounts.' : 'Spread evenly over 12 months.';
    total.textContent = fmt.format(sum());
  }
  amount.addEventListener('input', function () { if (dist.value !== 'custom') { even(); total.textContent = fmt.format(sum()); } });
  inputs.forEach(function (i) { i.addEventListener('input', function () { amount.value = sum().toFixed(2); total.textContent = fmt.format(sum()); }); });
  dist.addEventListener('change', sync);
  if (spread) spread.addEventListener('click', function () { var a = parseFloat(amount.value) || sum(); amount.value = a.toFixed(2); even(); total.textContent = fmt.format(sum()); });
  sync();
  document.getElementById('budForm').addEventListener('invalid', function (e) {
    var pane = e.target.closest('.tab-pane');
    if (pane && !pane.classList.contains('active')) {
      var btn = document.querySelector('[data-bs-target=\"#' + pane.id + '\"]');
      if (btn) bootstrap.Tab.getOrCreateInstance(btn).show();
    }
  }, true);
});";
require __DIR__ . '/../includes/footer.php';
