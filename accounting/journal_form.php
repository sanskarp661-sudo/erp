<?php
require_once __DIR__ . '/../includes/auth.php';
require_module_edit('finance');
$page_title = 'Journal Entry';
fin_require_schema();

$pdo = db();
$id = (int)input('id');
$types = fin_voucher_types();
$activeTab = 'details';

$entry = [
    'id' => 0, 'voucher_no' => '', 'voucher_type' => isset($types[input('type')]) ? input('type') : 'journal', 'posting_date' => today(),
    'reference_no' => '', 'reference_date' => '', 'party_name' => '', 'money_account_id' => '', 'cost_center_id' => setting('fin_default_cost_center_id', ''),
    'project' => '', 'narration' => '', 'remarks' => '', 'status' => 'draft',
];
$lines = [];
if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM fin_journal_entries WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        flash('danger', 'Journal entry not found.');
        redirect('/accounting/journals.php');
    }
    if ($row['status'] !== 'draft') {
        flash('warning', 'Only draft vouchers can be edited.');
        redirect('/accounting/journal_view.php?id=' . $id);
    }
    $entry = array_merge($entry, array_map(fn($v) => $v ?? '', $row));
    $stmt = $pdo->prepare('SELECT * FROM fin_journal_lines WHERE journal_id = ? ORDER BY sort_order, id');
    $stmt->execute([$id]);
    foreach ($stmt as $l) {
        // The bank / cash side of a payment or receipt is shown in Details, not as a line.
        if ($entry['money_account_id'] && (int)$l['account_id'] === (int)$entry['money_account_id'] && (int)$l['sort_order'] === 0) continue;
        $lines[] = $l;
    }
}

$ledgers = fin_ledger_options();
$moneyAccounts = fin_money_accounts();
$costCenters = $pdo->query("SELECT id, name FROM fin_cost_centers WHERE status = 'active' ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);

$errors = [];
if (is_post()) {
    csrf_verify();
    $activeTab = input('active_tab') ?: 'details';
    $entry = array_merge($entry, [
        'voucher_type' => isset($types[input('voucher_type')]) ? input('voucher_type') : 'journal',
        'posting_date' => input('posting_date') ?: today(),
        'reference_no' => trim((string)input('reference_no')),
        'reference_date' => input('reference_date') ?: '',
        'party_name' => trim((string)input('party_name')),
        'money_account_id' => (int)input('money_account_id') ?: '',
        'cost_center_id' => (int)input('cost_center_id') ?: '',
        'project' => trim((string)input('project')),
        'narration' => trim((string)input('narration')),
        'remarks' => trim((string)input('remarks')),
    ]);
    $flow = fin_voucher_flow($entry['voucher_type']);
    $lines = [];
    $acctIds = (array)($_POST['line_account'] ?? []);
    foreach ($acctIds as $i => $aid) {
        $aid = (int)$aid;
        $dr = round((float)($_POST['line_debit'][$i] ?? 0), 2);
        $cr = round((float)($_POST['line_credit'][$i] ?? 0), 2);
        if ($flow === 'payment') $cr = 0;
        if ($flow === 'receipt') $dr = 0;
        if (!$aid && !$dr && !$cr) continue;
        $lines[] = ['account_id' => $aid, 'debit' => $dr, 'credit' => $cr, 'cost_center_id' => (int)($_POST['line_cc'][$i] ?? 0) ?: null,
            'line_narration' => trim((string)($_POST['line_narration'][$i] ?? ''))];
    }

    foreach ($lines as $n => $l) {
        $row = $n + 1;
        if (!isset($ledgers[$l['account_id']])) $errors[] = "Row $row: pick an active ledger account.";
        if ($l['debit'] < 0 || $l['credit'] < 0) $errors[] = "Row $row: amounts cannot be negative.";
        if ($l['debit'] > 0 && $l['credit'] > 0) $errors[] = "Row $row: enter either a debit or a credit, not both.";
        if (!$l['debit'] && !$l['credit']) $errors[] = "Row $row: enter an amount.";
        if ($entry['voucher_type'] === 'contra' && !isset($moneyAccounts[$l['account_id']])) $errors[] = "Row $row: a contra entry moves money between bank and cash accounts only.";
    }
    $postLines = $lines;
    if ($flow !== 'journal') {
        $money = $entry['money_account_id'];
        if (!isset($moneyAccounts[$money])) {
            $errors[] = 'Pick the bank or cash account the money ' . ($flow === 'payment' ? 'is paid from.' : 'is received into.');
        } else {
            $isCash = $moneyAccounts[$money]['is_cash'] || $moneyAccounts[$money]['account_type'] === 'cash';
            if (str_starts_with($entry['voucher_type'], 'bank_') && $isCash) $errors[] = 'A bank voucher must use a bank account.';
            if (str_starts_with($entry['voucher_type'], 'cash_') && !$isCash) $errors[] = 'A cash voucher must use a cash account.';
        }
        foreach ($lines as $l) {
            if ((int)$l['account_id'] === (int)$money) $errors[] = 'A line cannot post to the same bank / cash account.';
        }
        $sum = array_sum(array_column($lines, $flow === 'payment' ? 'debit' : 'credit'));
        array_unshift($postLines, ['account_id' => (int)$money, 'debit' => $flow === 'receipt' ? $sum : 0, 'credit' => $flow === 'payment' ? $sum : 0,
            'cost_center_id' => null, 'line_narration' => '']);
    }
    $totalDr = round(array_sum(array_column($postLines, 'debit')), 2);
    $totalCr = round(array_sum(array_column($postLines, 'credit')), 2);
    if (!$lines) $errors[] = 'Add at least one account line.';
    elseif ($flow === 'journal' && count($lines) < 2) $errors[] = 'A journal entry needs at least two lines.';
    if ($totalDr <= 0) $errors[] = 'The voucher total must be more than zero.';
    if (abs($totalDr - $totalCr) > 0.005) $errors[] = 'Debits (' . fin_num($totalDr) . ') and credits (' . fin_num($totalCr) . ') must be equal.';

    if (!$errors) {
        $submit = input('do') === 'submit';
        $pdo->beginTransaction();
        try {
            $vals = [$entry['voucher_type'], $entry['posting_date'], $entry['reference_no'] ?: null, $entry['reference_date'] ?: null, $entry['party_name'] ?: null,
                $flow === 'journal' ? null : $entry['money_account_id'], $entry['cost_center_id'] ?: null, $entry['project'] ?: null, $entry['narration'] ?: null,
                $totalDr, $totalCr, $entry['remarks'] ?: null];
            if ($id) {
                $pdo->prepare('UPDATE fin_journal_entries SET voucher_type=?, posting_date=?, reference_no=?, reference_date=?, party_name=?, money_account_id=?, cost_center_id=?, project=?, narration=?, total_debit=?, total_credit=?, remarks=? WHERE id=?')
                    ->execute([...$vals, $id]);
                $pdo->prepare('DELETE FROM fin_journal_lines WHERE journal_id = ?')->execute([$id]);
            } else {
                [$pk, $pd] = fin_voucher_prefix_key($entry['voucher_type']);
                $no = fin_next_no($pk, $pd, 'fin_journal_entries', 'voucher_no');
                $pdo->prepare('INSERT INTO fin_journal_entries (voucher_type, posting_date, reference_no, reference_date, party_name, money_account_id, cost_center_id, project, narration, total_debit, total_credit, remarks, voucher_no, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([...$vals, $no, current_user()['id']]);
                $id = (int)$pdo->lastInsertId();
                log_activity('journal', $id, 'created', $types[$entry['voucher_type']] . ' ' . $no . ' created');
            }
            $ins = $pdo->prepare('INSERT INTO fin_journal_lines (journal_id, account_id, debit, credit, cost_center_id, project, line_narration, sort_order) VALUES (?,?,?,?,?,?,?,?)');
            foreach ($postLines as $n => $l) {
                $ins->execute([$id, $l['account_id'], $l['debit'], $l['credit'], $l['cost_center_id'], null, $l['line_narration'] ?: null, $flow === 'journal' ? $n + 1 : $n]);
            }
            if ($submit) {
                $pdo->prepare("UPDATE fin_journal_entries SET status='submitted', submitted_at=NOW() WHERE id=?")->execute([$id]);
                log_activity('journal', $id, 'submitted');
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        flash('success', $submit ? 'Voucher submitted and posted to the ledger.' : 'Voucher saved as draft.');
        redirect('/accounting/journal_view.php?id=' . $id);
    }
}
if (!$lines) {
    $lines = [['account_id' => '', 'debit' => '', 'credit' => '', 'cost_center_id' => '', 'line_narration' => '']];
    if (fin_voucher_flow($entry['voucher_type']) === 'journal') $lines[] = $lines[0];
}
if (!$entry['money_account_id'] && fin_voucher_flow($entry['voucher_type']) !== 'journal') {
    $entry['money_account_id'] = str_starts_with($entry['voucher_type'], 'cash_') ? fin_account_id('cash') : fin_account_id('bank');
}
$sel = fn($a, $b) => (string)$a === (string)$b ? 'selected' : '';
$page_title = $id ? 'Edit ' . $entry['voucher_no'] : 'New ' . $types[$entry['voucher_type']];

require __DIR__ . '/../includes/header.php';
?>
<div class="card p-4">
  <?php if ($errors): ?><div class="alert alert-danger"><?= implode('<br>', array_map('e', $errors)) ?></div><?php endif; ?>
  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0"><?= $id ? e($entry['voucher_no']) : 'New Voucher' ?> <?= fin_pill('Draft', 'secondary') ?></h5>
    <a href="journals.php" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-list"></i> All Journal Entries</a>
  </div>
  <ul class="nav nav-tabs mb-3" id="jvTabs">
    <?php foreach (['details' => 'Details', 'accounts' => 'Accounts', 'more' => 'More Info'] as $key => $label): ?>
      <li class="nav-item"><button class="nav-link <?= $activeTab === $key ? 'active' : '' ?>" data-tab="<?= $key ?>" data-bs-toggle="tab" data-bs-target="#pane-<?= $key ?>" type="button"><?= $label ?></button></li>
    <?php endforeach; ?>
  </ul>
  <form method="post" id="jvForm">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="active_tab" id="activeTabInput" value="<?= e($activeTab) ?>">
    <div class="tab-content">
      <div class="tab-pane fade <?= $activeTab === 'details' ? 'show active' : '' ?>" id="pane-details">
        <h6 class="text-muted mb-3"><i class="fa-solid fa-file-pen"></i> Voucher Details</h6>
        <div class="row g-3">
          <div class="col-sm-3"><label class="form-label">Voucher Type <span class="text-danger">*</span></label>
            <select name="voucher_type" id="voucherType" class="form-select">
              <?php foreach ($types as $k => $l): ?><option value="<?= $k ?>" data-flow="<?= fin_voucher_flow($k) ?>" <?= $sel($entry['voucher_type'], $k) ?>><?= e($l) ?></option><?php endforeach; ?>
            </select></div>
          <div class="col-sm-3"><label class="form-label">Voucher No.</label><input type="text" class="form-control" disabled value="<?= e($entry['voucher_no'] ?: 'Auto on save') ?>"></div>
          <div class="col-sm-3"><label class="form-label">Posting Date <span class="text-danger">*</span></label><input type="date" name="posting_date" class="form-control" required value="<?= e($entry['posting_date']) ?>"></div>
          <div class="col-sm-3 js-money"><label class="form-label"><span id="moneyLabel">Paid From</span> <span class="text-danger">*</span></label>
            <select name="money_account_id" class="form-select">
              <option value="">Select bank / cash account</option>
              <?php foreach ($moneyAccounts as $k => $a): ?><option value="<?= $k ?>" <?= $sel($entry['money_account_id'], $k) ?>><?= e(fin_account_label($a)) ?></option><?php endforeach; ?>
            </select></div>
          <div class="col-sm-3"><label class="form-label">Party / Payee</label><input type="text" name="party_name" class="form-control" maxlength="150" placeholder="e.g. ABC Suppliers" value="<?= e($entry['party_name']) ?>"></div>
          <div class="col-sm-3"><label class="form-label">Reference No.</label><input type="text" name="reference_no" class="form-control" maxlength="60" placeholder="Cheque / UTR / bill no." value="<?= e($entry['reference_no']) ?>"></div>
          <div class="col-sm-3"><label class="form-label">Reference Date</label><input type="date" name="reference_date" class="form-control" value="<?= e($entry['reference_date']) ?>"></div>
          <div class="col-sm-3"><label class="form-label">Cost Center</label>
            <select name="cost_center_id" class="form-select"><option value="">—</option>
              <?php foreach ($costCenters as $k => $l): ?><option value="<?= (int)$k ?>" <?= $sel($entry['cost_center_id'], $k) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
          <div class="col-sm-3"><label class="form-label">Project</label><input type="text" name="project" class="form-control" maxlength="100" value="<?= e($entry['project']) ?>"></div>
          <div class="col-sm-9"><label class="form-label">Narration</label><input type="text" name="narration" class="form-control" maxlength="255" placeholder="e.g. Payment for raw materials" value="<?= e($entry['narration']) ?>"></div>
        </div>
      </div>

      <div class="tab-pane fade <?= $activeTab === 'accounts' ? 'show active' : '' ?>" id="pane-accounts">
        <h6 class="text-muted mb-1"><i class="fa-solid fa-table-list"></i> Account Lines</h6>
        <p class="small text-muted mb-3" id="flowHint"></p>
        <div class="table-responsive">
          <table class="table table-sm align-middle" id="lineTable">
            <thead><tr><th style="min-width:260px">Account</th><th class="js-dr" style="width:150px">Debit</th><th class="js-cr" style="width:150px">Credit</th><th style="width:180px">Cost Center</th><th>Narration</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($lines as $l): ?>
              <tr data-row>
                <td><select name="line_account[]" class="form-select form-select-sm">
                  <option value="">Select account</option>
                  <?php foreach ($ledgers as $k => $a): ?><option value="<?= $k ?>" <?= $sel($l['account_id'], $k) ?>><?= e(fin_account_label($a)) ?></option><?php endforeach; ?>
                </select></td>
                <td class="js-dr"><input type="number" step="0.01" min="0" name="line_debit[]" class="form-control form-control-sm text-end js-amt" value="<?= (float)$l['debit'] ? e($l['debit']) : '' ?>"></td>
                <td class="js-cr"><input type="number" step="0.01" min="0" name="line_credit[]" class="form-control form-control-sm text-end js-amt" value="<?= (float)$l['credit'] ? e($l['credit']) : '' ?>"></td>
                <td><select name="line_cc[]" class="form-select form-select-sm"><option value="">—</option>
                  <?php foreach ($costCenters as $k => $n): ?><option value="<?= (int)$k ?>" <?= $sel($l['cost_center_id'], $k) ?>><?= e($n) ?></option><?php endforeach; ?></select></td>
                <td><input type="text" name="line_narration[]" class="form-control form-control-sm" maxlength="255" value="<?= e($l['line_narration']) ?>"></td>
                <td><button type="button" class="btn btn-sm btn-outline-danger js-remove"><i class="fa-solid fa-xmark"></i></button></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr class="fw-semibold"><td class="text-end">Total</td><td class="js-dr text-end" id="totDr">0.00</td><td class="js-cr text-end" id="totCr">0.00</td><td colspan="3" id="diffCell" class="small"></td></tr></tfoot>
          </table>
        </div>
        <button type="button" class="btn btn-sm btn-outline-brand" id="addLine"><i class="fa-solid fa-plus"></i> Add Row</button>
      </div>

      <div class="tab-pane fade <?= $activeTab === 'more' ? 'show active' : '' ?>" id="pane-more">
        <h6 class="text-muted mb-3"><i class="fa-solid fa-circle-info"></i> More Info</h6>
        <div class="row g-3"><div class="col-sm-6"><label class="form-label">Remarks</label><textarea name="remarks" class="form-control" rows="4"><?= e($entry['remarks']) ?></textarea></div></div>
      </div>
    </div>
    <div class="page-actions mt-4">
      <button type="submit" name="do" value="draft" class="btn btn-outline-brand">Save Draft</button>
      <button type="submit" name="do" value="submit" class="btn btn-brand">Save &amp; Submit</button>
      <a href="journals.php" class="btn btn-outline-secondary">Cancel</a>
    </div>
  </form>
</div>
<?php
$extra_js_inline = "
document.addEventListener('DOMContentLoaded', function () {
  var activeTabInput = document.getElementById('activeTabInput');
  document.querySelectorAll('#jvTabs [data-tab]').forEach(function (b) { b.addEventListener('shown.bs.tab', function () { activeTabInput.value = b.dataset.tab; }); });
  var type = document.getElementById('voucherType'), tbody = document.querySelector('#lineTable tbody');
  var hints = { journal: 'Debits and credits must be equal.', payment: 'Enter what the money is paid for; the bank / cash account in Details is credited with the total.', receipt: 'Enter where the money comes from; the bank / cash account in Details is debited with the total.' };
  function flow() { return type.options[type.selectedIndex].dataset.flow; }
  function recalc() {
    var dr = 0, cr = 0;
    tbody.querySelectorAll('tr').forEach(function (tr) {
      dr += parseFloat(tr.querySelector('[name=\"line_debit[]\"]').value || 0);
      cr += parseFloat(tr.querySelector('[name=\"line_credit[]\"]').value || 0);
    });
    document.getElementById('totDr').textContent = dr.toFixed(2);
    document.getElementById('totCr').textContent = cr.toFixed(2);
    var d = document.getElementById('diffCell');
    if (flow() === 'journal') { var diff = dr - cr; d.textContent = Math.abs(diff) < 0.005 ? 'Balanced' : 'Difference: ' + diff.toFixed(2); d.className = 'small ' + (Math.abs(diff) < 0.005 ? 'text-success' : 'text-danger'); }
    else { d.textContent = ''; }
  }
  function sync() {
    var f = flow();
    document.querySelectorAll('.js-money').forEach(function (el) { el.style.display = f === 'journal' ? 'none' : ''; });
    document.getElementById('moneyLabel').textContent = f === 'receipt' ? 'Received Into' : 'Paid From';
    document.querySelectorAll('.js-dr').forEach(function (el) { el.style.display = f === 'receipt' ? 'none' : ''; });
    document.querySelectorAll('.js-cr').forEach(function (el) { el.style.display = f === 'payment' ? 'none' : ''; });
    document.getElementById('flowHint').textContent = hints[f];
    recalc();
  }
  type.addEventListener('change', sync);
  tbody.addEventListener('input', recalc);
  tbody.addEventListener('click', function (e) {
    var b = e.target.closest('.js-remove');
    if (b && tbody.querySelectorAll('tr').length > 1) { b.closest('tr').remove(); recalc(); }
  });
  document.getElementById('addLine').addEventListener('click', function () {
    var tr = tbody.querySelector('tr').cloneNode(true);
    tr.querySelectorAll('input').forEach(function (i) { i.value = ''; });
    tr.querySelectorAll('select').forEach(function (s) { s.selectedIndex = 0; });
    tbody.appendChild(tr);
    sync();
  });
  sync();
});";
require __DIR__ . '/../includes/footer.php';
