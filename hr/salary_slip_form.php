<?php
require_once __DIR__ . '/../includes/auth.php';
require_module_manage('hrms');

$error = '';
$employeeId = '';
$payMonth = date('Y-m');
$basicSalary = '0';
$earnings = [];
$deductions = [];

if (is_post()) {
    csrf_verify();
    $employeeId = (int)input('employee_id');
    $payMonth = input('pay_month');
    $basicSalary = (float)input('basic_salary');
    $earnLabels = $_POST['earn_label'] ?? [];
    $earnAmounts = $_POST['earn_amount'] ?? [];
    $dedLabels = $_POST['ded_label'] ?? [];
    $dedAmounts = $_POST['ded_amount'] ?? [];

    $earnings = [];
    foreach ($earnLabels as $i => $label) {
        $label = trim($label);
        $amount = (float)($earnAmounts[$i] ?? 0);
        if ($label !== '' && $amount != 0) {
            $earnings[] = ['label' => $label, 'amount' => $amount];
        }
    }
    $deductions = [];
    foreach ($dedLabels as $i => $label) {
        $label = trim($label);
        $amount = (float)($dedAmounts[$i] ?? 0);
        if ($label !== '' && $amount != 0) {
            $deductions[] = ['label' => $label, 'amount' => $amount];
        }
    }

    if (!$employeeId || !preg_match('/^\d{4}-\d{2}$/', $payMonth)) {
        $error = 'Please select an employee and a pay period.';
    } else {
        $periodStart = $payMonth . '-01';
        $periodEnd = date('Y-m-t', strtotime($periodStart));

        $dupStmt = db()->prepare('SELECT id FROM salary_slips WHERE employee_id = ? AND pay_period_start = ? AND pay_period_end = ?');
        $dupStmt->execute([$employeeId, $periodStart, $periodEnd]);
        if ($dupStmt->fetchColumn()) {
            $error = 'A salary slip for this employee and pay period already exists.';
        } else {
            $totalEarnings = $basicSalary + array_sum(array_column($earnings, 'amount'));
            $totalDeductions = array_sum(array_column($deductions, 'amount'));
            $netPay = $totalEarnings - $totalDeductions;

            $pdo = db();
            $pdo->beginTransaction();
            try {
                $slipNo = next_code('SAL', 'salary_slips', 'slip_no');
                $pdo->prepare('INSERT INTO salary_slips (slip_no, employee_id, pay_period_start, pay_period_end, basic_salary, total_earnings, total_deductions, net_pay, status, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$slipNo, $employeeId, $periodStart, $periodEnd, $basicSalary, $totalEarnings, $totalDeductions, $netPay, 'draft', current_user()['id']]);
                $slipId = (int)$pdo->lastInsertId();

                $itemStmt = $pdo->prepare('INSERT INTO salary_slip_items (salary_slip_id, component_type, label, amount) VALUES (?,?,?,?)');
                foreach ($earnings as $e) {
                    $itemStmt->execute([$slipId, 'earning', $e['label'], $e['amount']]);
                }
                foreach ($deductions as $d) {
                    $itemStmt->execute([$slipId, 'deduction', $d['label'], $d['amount']]);
                }
                $pdo->commit();
                flash('success', 'Salary slip generated.');
                redirect('/hr/salary_slip_view.php?id=' . $slipId);
            } catch (PDOException $e) {
                $pdo->rollBack();
                $error = str_contains($e->getMessage(), 'Duplicate') ? 'A salary slip for this employee and pay period already exists.' : 'Could not generate salary slip.';
            }
        }
    }
}

$employees = db()->query("SELECT id, employee_code, name, salary FROM employees WHERE status='active' ORDER BY name")->fetchAll();

$page_title = 'Generate Salary Slip';
require __DIR__ . '/../includes/header.php';
?>
<div class="card p-4">
  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
  <form method="post" id="slipForm">
    <?= csrf_field() ?>
    <div class="row g-3 mb-3">
      <div class="col-sm-4">
        <label class="form-label">Employee</label>
        <select name="employee_id" id="empSelect" class="form-select" required>
          <option value="">— Select employee —</option>
          <?php foreach ($employees as $emp): ?>
            <option value="<?= (int)$emp['id'] ?>" data-salary="<?= e($emp['salary']) ?>" <?= (string)$employeeId === (string)$emp['id'] ? 'selected' : '' ?>><?= e($emp['name']) ?> (<?= e($emp['employee_code']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-4">
        <label class="form-label">Pay Period (Month)</label>
        <input type="month" name="pay_month" class="form-control" required value="<?= e($payMonth) ?>">
      </div>
      <div class="col-sm-4">
        <label class="form-label">Basic Salary</label>
        <input type="number" step="0.01" min="0" name="basic_salary" id="basicSalaryInput" class="form-control" value="<?= e($basicSalary) ?>">
      </div>
    </div>

    <div class="row g-3">
      <div class="col-md-6">
        <h6 class="mb-2 text-success"><i class="fa-solid fa-plus"></i> Additional Earnings</h6>
        <div class="payroll-items" data-row-prefix="earn">
          <table class="table table-sm">
            <thead><tr><th>Label</th><th style="width:35%">Amount</th><th></th></tr></thead>
            <tbody>
            <?php if (!$earnings): $earnings = [['label' => '', 'amount' => '']]; endif; ?>
            <?php foreach ($earnings as $row): ?>
              <tr data-row>
                <td><input type="text" class="form-control" name="earn_label[]" value="<?= e($row['label']) ?>" placeholder="e.g. HRA, Bonus"></td>
                <td><input type="number" step="0.01" class="form-control" name="earn_amount[]" value="<?= e($row['amount']) ?>"></td>
                <td><button type="button" class="btn btn-sm btn-outline-danger js-remove-row"><i class="fa-solid fa-xmark"></i></button></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <button type="button" class="btn btn-sm btn-outline-brand js-add-row">Add earning</button>
        </div>
      </div>
      <div class="col-md-6">
        <h6 class="mb-2 text-danger"><i class="fa-solid fa-minus"></i> Deductions</h6>
        <div class="payroll-items" data-row-prefix="ded">
          <table class="table table-sm">
            <thead><tr><th>Label</th><th style="width:35%">Amount</th><th></th></tr></thead>
            <tbody>
            <?php if (!$deductions): $deductions = [['label' => '', 'amount' => '']]; endif; ?>
            <?php foreach ($deductions as $row): ?>
              <tr data-row>
                <td><input type="text" class="form-control" name="ded_label[]" value="<?= e($row['label']) ?>" placeholder="e.g. Provident Fund, Tax"></td>
                <td><input type="number" step="0.01" class="form-control" name="ded_amount[]" value="<?= e($row['amount']) ?>"></td>
                <td><button type="button" class="btn btn-sm btn-outline-danger js-remove-row"><i class="fa-solid fa-xmark"></i></button></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <button type="button" class="btn btn-sm btn-outline-brand js-add-row">Add deduction</button>
        </div>
      </div>
    </div>

    <div class="card bg-light border-0 p-3 mt-2 mb-3" style="max-width:360px">
      <div class="d-flex justify-content-between small mb-1"><span>Total Earnings</span><strong id="totalEarnings">0.00</strong></div>
      <div class="d-flex justify-content-between small mb-1"><span>Total Deductions</span><strong id="totalDeductions">0.00</strong></div>
      <div class="d-flex justify-content-between fs-5 border-top pt-2 mt-1"><span>Net Pay</span><strong id="netPay">0.00</strong></div>
    </div>

    <div class="page-actions">
      <button type="submit" class="btn btn-brand">Generate Slip</button>
      <a href="salary_slips.php" class="btn btn-outline-secondary">Cancel</a>
    </div>
  </form>
</div>
<?php
$extra_js_inline = "
document.getElementById('empSelect').addEventListener('change', function () {
  var opt = this.options[this.selectedIndex];
  var salary = opt ? opt.getAttribute('data-salary') : null;
  if (salary !== null) document.getElementById('basicSalaryInput').value = salary;
  recalcPayroll();
});

document.querySelectorAll('.payroll-items').forEach(function (wrap) {
  var tbody = wrap.querySelector('tbody');
  wrap.addEventListener('click', function (e) {
    if (e.target.closest('.js-add-row')) {
      var rows = tbody.querySelectorAll('tr[data-row]');
      var clone = rows[rows.length - 1].cloneNode(true);
      clone.querySelectorAll('input').forEach(function (inp) { inp.value = ''; });
      tbody.appendChild(clone);
      return;
    }
    var rmBtn = e.target.closest('.js-remove-row');
    if (rmBtn) {
      var rows2 = tbody.querySelectorAll('tr[data-row]');
      if (rows2.length > 1) rmBtn.closest('tr[data-row]').remove();
      else rmBtn.closest('tr[data-row]').querySelectorAll('input').forEach(function (inp) { inp.value = ''; });
      recalcPayroll();
    }
  });
});

function recalcPayroll() {
  var basic = parseFloat(document.getElementById('basicSalaryInput').value || 0);
  var earn = basic;
  document.querySelectorAll('input[name=\"earn_amount[]\"]').forEach(function (i) { earn += parseFloat(i.value || 0); });
  var ded = 0;
  document.querySelectorAll('input[name=\"ded_amount[]\"]').forEach(function (i) { ded += parseFloat(i.value || 0); });
  document.getElementById('totalEarnings').textContent = earn.toFixed(2);
  document.getElementById('totalDeductions').textContent = ded.toFixed(2);
  document.getElementById('netPay').textContent = (earn - ded).toFixed(2);
}
document.getElementById('slipForm').addEventListener('input', recalcPayroll);
recalcPayroll();
";
require __DIR__ . '/../includes/footer.php';
