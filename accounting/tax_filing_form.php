<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
$page_title = 'Tax Filing';
fin_require_schema();

$pdo = db();
$id = (int)input('id');
$canEdit = can_edit_module('finance');
if (!$id) require_module_edit('finance');
$returnTypes = fin_tax_return_types();
$categories = fin_tax_categories();

$presetType = isset($returnTypes[input('return_type')]) ? input('return_type') : '';
$presetCat = isset($categories[input('category')]) ? input('category') : ($presetType ? $returnTypes[$presetType][0] : 'gst');
if (!$presetType) {
    foreach ($returnTypes as $t => [$c]) { if ($c === $presetCat) { $presetType = $t; break; } }
}
$presetPeriod = preg_match('/^\d{4}-\d{2}$/', (string)input('period')) ? input('period') . '-01' : date('Y-m-01', strtotime('first day of last month'));

$f = [
    'id' => 0, 'category' => $presetCat, 'return_type' => $presetType, 'period_month' => $presetPeriod, 'due_date' => $presetType ? fin_tax_due_date($presetType, $presetPeriod) : '',
    'filing_date' => '', 'status' => 'pending', 'tax_liability' => '', 'tax_paid' => '', 'payment_date' => '', 'challan_no' => '', 'ack_no' => '', 'remarks' => '',
];
if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM fin_tax_filings WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        flash('danger', 'Filing not found.');
        redirect('/accounting/tax_compliance.php');
    }
    $f = array_merge($f, array_map(fn($v) => $v ?? '', $row));
} elseif ($f['category'] === 'gst' && in_array($f['return_type'], ['GSTR-3B', 'GST Payment', 'GSTR-1'], true)) {
    $books = fin_gst_from_books($f['period_month']);
    $f['tax_liability'] = $f['return_type'] === 'GSTR-1' ? $books['output'] : $books['net'];
}

$errors = [];
if (is_post()) {
    require_module_edit('finance');
    csrf_verify();
    $f = array_merge($f, [
        'category' => isset($categories[input('category')]) ? input('category') : 'other',
        'return_type' => trim((string)input('return_type')),
        'period_month' => preg_match('/^\d{4}-\d{2}$/', (string)input('period')) ? input('period') . '-01' : '',
        'due_date' => input('due_date'),
        'filing_date' => input('filing_date'),
        'status' => in_array(input('status'), ['pending', 'filed', 'paid'], true) ? input('status') : 'pending',
        'tax_liability' => round((float)input('tax_liability'), 2),
        'tax_paid' => round((float)input('tax_paid'), 2),
        'payment_date' => input('payment_date'),
        'challan_no' => trim((string)input('challan_no')),
        'ack_no' => trim((string)input('ack_no')),
        'remarks' => trim((string)input('remarks')),
    ]);
    if ($f['return_type'] === '') $errors[] = 'Return type is required.';
    if ($f['period_month'] === '') $errors[] = 'Period is required.';
    if ($f['due_date'] === '' && $f['period_month']) $f['due_date'] = fin_tax_due_date($f['return_type'], $f['period_month']);
    if ($f['status'] === 'filed' && !$f['filing_date']) $f['filing_date'] = today();
    if ($f['status'] === 'paid' && !$f['payment_date']) $f['payment_date'] = today();
    if ($f['tax_liability'] < 0 || $f['tax_paid'] < 0) $errors[] = 'Amounts cannot be negative.';
    $dup = $pdo->prepare('SELECT COUNT(*) FROM fin_tax_filings WHERE return_type = ? AND period_month = ? AND id <> ?');
    $dup->execute([$f['return_type'], $f['period_month'], $id]);
    if ($dup->fetchColumn() > 0) $errors[] = $f['return_type'] . ' for ' . date('M Y', strtotime($f['period_month'])) . ' is already recorded.';

    if (!$errors) {
        $cols = ['category', 'return_type', 'period_month', 'due_date', 'filing_date', 'status', 'tax_liability', 'tax_paid', 'payment_date', 'challan_no', 'ack_no', 'remarks'];
        $vals = array_map(fn($c) => $f[$c] === '' ? null : $f[$c], $cols);
        if ($id) {
            $pdo->prepare('UPDATE fin_tax_filings SET ' . implode(', ', array_map(fn($c) => "$c = ?", $cols)) . ' WHERE id = ?')->execute([...$vals, $id]);
            log_activity('tax_filing', $id, 'updated', $f['return_type'] . ' ' . date('M Y', strtotime($f['period_month'])) . ' marked ' . $f['status']);
        } else {
            $pdo->prepare('INSERT INTO fin_tax_filings (' . implode(', ', $cols) . ', created_by) VALUES (' . implode(',', array_fill(0, count($cols) + 1, '?')) . ')')->execute([...$vals, current_user()['id']]);
            $id = (int)$pdo->lastInsertId();
            log_activity('tax_filing', $id, 'created', $f['return_type'] . ' ' . date('M Y', strtotime($f['period_month'])) . ' recorded');
        }
        flash('success', 'Filing saved.');
        redirect('/accounting/tax_compliance.php?tab=' . (in_array($f['category'], ['gst', 'tds'], true) ? $f['category'] : 'other'));
    }
}
$books = $f['period_month'] ? fin_gst_from_books($f['period_month']) : null;
$sel = fn($a, $b) => (string)$a === (string)$b ? 'selected' : '';
$ro = $canEdit ? '' : 'disabled';
$page_title = $id ? $f['return_type'] . ' · ' . date('M Y', strtotime($f['period_month'])) : 'New Tax Filing';

require __DIR__ . '/../includes/header.php';
?>
<div class="card p-4">
  <?php if ($errors): ?><div class="alert alert-danger"><?= implode('<br>', array_map('e', $errors)) ?></div><?php endif; ?>
  <h5 class="mb-3"><?= e($page_title) ?></h5>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $id ?>">
    <h6 class="text-muted mb-3"><i class="fa-solid fa-file-lines"></i> Return</h6>
    <div class="row g-3 mb-4">
      <div class="col-sm-3"><label class="form-label">Category</label>
        <select name="category" id="catSel" class="form-select" <?= $ro ?>><?php foreach ($categories as $k => $l): ?><option value="<?= $k ?>" <?= $sel($f['category'], $k) ?>><?= $l ?></option><?php endforeach; ?></select></div>
      <div class="col-sm-3"><label class="form-label">Return Type <span class="text-danger">*</span></label>
        <input type="text" name="return_type" id="typeInput" class="form-control" list="typeList" required maxlength="40" <?= $ro ?> value="<?= e($f['return_type']) ?>">
        <datalist id="typeList"><?php foreach ($returnTypes as $t => [$c, $d]): ?><option value="<?= e($t) ?>" data-cat="<?= $c ?>" data-due="<?= e((string)$d) ?>"><?php endforeach; ?></datalist></div>
      <div class="col-sm-3"><label class="form-label">Period <span class="text-danger">*</span></label>
        <input type="month" name="period" id="periodInput" class="form-control" required <?= $ro ?> value="<?= e(substr((string)$f['period_month'], 0, 7)) ?>"></div>
      <div class="col-sm-3"><label class="form-label">Due Date</label>
        <input type="date" name="due_date" id="dueInput" class="form-control" <?= $ro ?> value="<?= e($f['due_date']) ?>"><div class="form-text">Filled from the statutory calendar.</div></div>
      <div class="col-sm-3"><label class="form-label">Status</label>
        <select name="status" class="form-select" <?= $ro ?>><?php foreach (['pending' => 'Pending', 'filed' => 'Filed', 'paid' => 'Paid'] as $k => $l): ?><option value="<?= $k ?>" <?= $sel($f['status'], $k) ?>><?= $l ?></option><?php endforeach; ?></select></div>
      <div class="col-sm-3"><label class="form-label">Filing Date</label><input type="date" name="filing_date" class="form-control" <?= $ro ?> value="<?= e($f['filing_date']) ?>"></div>
      <div class="col-sm-3"><label class="form-label">Acknowledgement No. (ARN)</label><input type="text" name="ack_no" class="form-control" maxlength="60" <?= $ro ?> value="<?= e($f['ack_no']) ?>"></div>
      <div class="col-sm-3"><label class="form-label">Tax Liability</label>
        <div class="input-group"><span class="input-group-text"><?= e(setting('currency_symbol', '₹')) ?></span><input type="number" step="0.01" min="0" name="tax_liability" class="form-control" <?= $ro ?> value="<?= e($f['tax_liability']) ?>"></div>
        <?php if ($books && $f['category'] === 'gst'): ?><div class="form-text">Books for <?= date('M Y', strtotime($f['period_month'])) ?>: output <?= fin_num($books['output']) ?>, ITC <?= fin_num($books['input']) ?>, net <?= fin_num($books['net']) ?>.</div><?php endif; ?></div>
    </div>
    <h6 class="text-muted mb-3"><i class="fa-solid fa-money-bill-transfer"></i> Payment</h6>
    <div class="row g-3 mb-4">
      <div class="col-sm-3"><label class="form-label">Tax Paid</label>
        <div class="input-group"><span class="input-group-text"><?= e(setting('currency_symbol', '₹')) ?></span><input type="number" step="0.01" min="0" name="tax_paid" class="form-control" <?= $ro ?> value="<?= e($f['tax_paid']) ?>"></div></div>
      <div class="col-sm-3"><label class="form-label">Payment Date</label><input type="date" name="payment_date" class="form-control" <?= $ro ?> value="<?= e($f['payment_date']) ?>"></div>
      <div class="col-sm-3"><label class="form-label">Challan / CIN No.</label><input type="text" name="challan_no" class="form-control" maxlength="60" <?= $ro ?> value="<?= e($f['challan_no']) ?>"></div>
      <div class="col-sm-3"><label class="form-label">Remarks</label><input type="text" name="remarks" class="form-control" maxlength="255" <?= $ro ?> value="<?= e($f['remarks']) ?>"></div>
    </div>
    <div class="page-actions">
      <?php if ($canEdit): ?><button class="btn btn-brand">Save Filing</button><?php endif; ?>
      <a href="tax_compliance.php" class="btn btn-outline-secondary">Cancel</a>
    </div>
  </form>
</div>
<?php
$extra_js_inline = "
document.addEventListener('DOMContentLoaded', function () {
  var t = document.getElementById('typeInput'), p = document.getElementById('periodInput'), d = document.getElementById('dueInput'), c = document.getElementById('catSel');
  function due() {
    var o = document.querySelector('#typeList option[value=\"' + t.value.replace(/\"/g, '') + '\"]');
    if (!o || !p.value) return;
    c.value = o.dataset.cat;
    var parts = p.value.split('-'), y = +parts[0], m = +parts[1] + 1;
    if (m > 12) { m = 1; y++; }
    var rule = o.dataset.due, day = rule === 'q' ? new Date(y, m, 0).getDate() : +rule;
    d.value = y + '-' + String(m).padStart(2, '0') + '-' + String(day).padStart(2, '0');
  }
  t.addEventListener('change', due);
  p.addEventListener('change', due);
});";
require __DIR__ . '/../includes/footer.php';
