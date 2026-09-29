<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/hr.php';
require_module_manage('hrms');

$id = (int)input('id');
$slip = [
    'id' => 0, 'slip_no' => '', 'employee_id' => '', 'pay_month' => date('Y-m'), 'posting_date' => today(), 'status' => 'draft',
    'working_days' => '', 'present_days' => '', 'paid_leave_days' => '', 'lop_days' => '', 'payment_days' => '', 'prorate_lop' => 1,
    'full_basic_salary' => '0', 'salary_mode' => 'bank_transfer', 'bank_name' => '', 'bank_account_no' => '', 'bank_ifsc' => '',
    'notes' => '', 'remarks' => '',
];
$earnings = [];
$deductions = [];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM salary_slips WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        flash('danger', 'Salary slip not found.');
        redirect('/hr/salary_slips.php');
    }
    if ($row['status'] !== 'draft') {
        flash('danger', 'Only draft salary slips can be edited.');
        redirect('/hr/salary_slip_view.php?id=' . $id);
    }
    $slip = array_merge($slip, $row, ['pay_month' => substr($row['pay_period_start'], 0, 7)]);
    if ((float)$slip['full_basic_salary'] == 0) {
        $slip['full_basic_salary'] = $row['basic_salary'];
    }
    $stmt = db()->prepare('SELECT component_type, label, COALESCE(full_amount, amount) amount FROM salary_slip_items WHERE salary_slip_id = ? ORDER BY id');
    $stmt->execute([$id]);
    foreach ($stmt as $it) {
        if ($it['component_type'] === 'earning') {
            $earnings[] = ['label' => $it['label'], 'amount' => $it['amount']];
        } else {
            $deductions[] = ['label' => $it['label'], 'amount' => $it['amount']];
        }
    }
}

$error = '';
$tabs = ['details', 'attendance', 'components', 'payment', 'more'];
$activeTab = in_array(input('tab'), $tabs, true) ? input('tab') : 'details';
$salaryModes = ['bank_transfer' => 'Bank Transfer', 'cash' => 'Cash', 'cheque' => 'Cheque'];

/** Posted label/amount rows with blanks dropped. */
function slip_rows(string $prefix): array
{
    $rows = [];
    foreach ($_POST[$prefix . '_label'] ?? [] as $i => $label) {
        $label = trim((string)$label);
        $amount = round(max(0, (float)($_POST[$prefix . '_amount'][$i] ?? 0)), 2);
        if ($label === '' && $amount == 0) {
            continue;
        }
        $rows[] = ['label' => $label, 'amount' => $amount];
    }
    return $rows;
}

if (is_post()) {
    csrf_verify();
    $days = fn(string $k) => round(max(0, (float)input($k)) * 2) / 2; // nearest half day
    $slip = array_merge($slip, [
        'employee_id' => (int)input('employee_id'),
        'pay_month' => (string)input('pay_month'),
        'posting_date' => input('posting_date') ?: today(),
        'working_days' => $days('working_days'),
        'present_days' => $days('present_days'),
        'paid_leave_days' => $days('paid_leave_days'),
        'lop_days' => $days('lop_days'),
        'prorate_lop' => input('prorate_lop') ? 1 : 0,
        'full_basic_salary' => round(max(0, (float)input('full_basic_salary')), 2),
        'salary_mode' => in_array(input('salary_mode'), array_keys($salaryModes), true) ? input('salary_mode') : 'bank_transfer',
        'bank_name' => trim((string)input('bank_name')) ?: null,
        'bank_account_no' => preg_replace('/\s+/', '', (string)input('bank_account_no')) ?: null,
        'bank_ifsc' => strtoupper(trim((string)input('bank_ifsc'))) ?: null,
        'notes' => trim((string)input('notes')) ?: null,
        'remarks' => trim((string)input('remarks')) ?: null,
    ]);
    $earnings = slip_rows('earn');
    $deductions = slip_rows('ded');

    $errorTab = null;
    $fail = function (string $msg, string $tab) use (&$error, &$errorTab) {
        if ($error === '') {
            $error = $msg;
            $errorTab = $tab;
        }
    };
    if (!$slip['employee_id']) {
        $fail('Please select an employee.', 'details');
    }
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $slip['pay_month'])) {
        $fail('Please select a pay month.', 'details');
    }
    if ($slip['working_days'] <= 0) {
        $fail('Working Days must be more than zero.', 'attendance');
    }
    if ($slip['lop_days'] > $slip['working_days']) {
        $fail('Loss of Pay days cannot exceed Working Days.', 'attendance');
    }
    if ($slip['present_days'] + $slip['paid_leave_days'] + $slip['lop_days'] > $slip['working_days']) {
        $fail('Present + Paid Leave + Loss of Pay days add up to more than the Working Days.', 'attendance');
    }
    foreach (array_merge($earnings, $deductions) as $r) {
        if ($r['label'] === '') {
            $fail('Every earning and deduction needs a name.', 'components');
        }
    }
    if ($slip['salary_mode'] === 'bank_transfer' && (!$slip['bank_account_no'] || !$slip['bank_ifsc'])) {
        $fail('Bank Account No. and IFSC are needed to pay by Bank Transfer.', 'payment');
    }
    if ($slip['bank_ifsc'] && !preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $slip['bank_ifsc'])) {
        $fail('IFSC must look like HDFC0001234.', 'payment');
    }

    // Amounts are always computed here. Earnings (and basic) are prorated
    // by payment days / working days; deductions are fixed.
    $slip['payment_days'] = max(0, $slip['working_days'] - $slip['lop_days']);
    $factor = $slip['prorate_lop'] && $slip['working_days'] > 0 ? $slip['payment_days'] / $slip['working_days'] : 1;
    $basic = round($slip['full_basic_salary'] * $factor, 2);
    $totalEarnings = $basic;
    foreach ($earnings as &$r) {
        $r['full_amount'] = $r['amount'];
        $r['pay_amount'] = round($r['amount'] * $factor, 2);
        $totalEarnings += $r['pay_amount'];
    }
    unset($r);
    $totalDeductions = round(array_sum(array_column($deductions, 'amount')), 2);
    $netPay = round($totalEarnings - $totalDeductions, 2);
    if ($error === '' && $netPay < 0) {
        $fail('Deductions (' . money($totalDeductions) . ') are more than earnings (' . money($totalEarnings) . ').', 'components');
    }

    if ($error === '') {
        $periodStart = $slip['pay_month'] . '-01';
        $periodEnd = date('Y-m-t', strtotime($periodStart));
        $dup = db()->prepare('SELECT id FROM salary_slips WHERE employee_id = ? AND pay_period_start = ? AND pay_period_end = ? AND id <> ?');
        $dup->execute([$slip['employee_id'], $periodStart, $periodEnd, $id]);
        if ($dup->fetchColumn()) {
            $fail('A salary slip for this employee and month already exists.', 'details');
        }
    }

    if ($error === '') {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $fields = [
                'employee_id' => $slip['employee_id'], 'pay_period_start' => $periodStart, 'pay_period_end' => $periodEnd,
                'posting_date' => $slip['posting_date'], 'working_days' => $slip['working_days'], 'present_days' => $slip['present_days'],
                'paid_leave_days' => $slip['paid_leave_days'], 'lop_days' => $slip['lop_days'], 'payment_days' => $slip['payment_days'],
                'prorate_lop' => $slip['prorate_lop'], 'basic_salary' => $basic, 'full_basic_salary' => $slip['full_basic_salary'],
                'total_earnings' => round($totalEarnings, 2), 'total_deductions' => $totalDeductions, 'net_pay' => $netPay,
                'salary_mode' => $slip['salary_mode'], 'bank_name' => $slip['bank_name'], 'bank_account_no' => $slip['bank_account_no'],
                'bank_ifsc' => $slip['bank_ifsc'], 'notes' => $slip['notes'], 'remarks' => $slip['remarks'],
            ];
            if ($id) {
                $pdo->prepare('UPDATE salary_slips SET ' . implode(', ', array_map(fn($c) => "$c = ?", array_keys($fields))) . ' WHERE id = ?')
                    ->execute([...array_values($fields), $id]);
                $slipId = $id;
                $pdo->prepare('DELETE FROM salary_slip_items WHERE salary_slip_id = ?')->execute([$slipId]);
            } else {
                $fields += ['slip_no' => next_code('SAL', 'salary_slips', 'slip_no'), 'status' => 'draft', 'created_by' => current_user()['id']];
                $pdo->prepare('INSERT INTO salary_slips (' . implode(', ', array_keys($fields)) . ') VALUES (' . implode(', ', array_fill(0, count($fields), '?')) . ')')
                    ->execute(array_values($fields));
                $slipId = (int)$pdo->lastInsertId();
            }
            $itemStmt = $pdo->prepare('INSERT INTO salary_slip_items (salary_slip_id, component_type, label, amount, full_amount) VALUES (?,?,?,?,?)');
            foreach ($earnings as $r) {
                $itemStmt->execute([$slipId, 'earning', $r['label'], $r['pay_amount'], $r['full_amount']]);
            }
            foreach ($deductions as $r) {
                $itemStmt->execute([$slipId, 'deduction', $r['label'], $r['amount'], $r['amount']]);
            }
            log_activity('salary_slip', $slipId, $id ? 'edited' : 'created');
            $pdo->commit();
            flash('success', $id ? 'Salary slip updated.' : 'Salary slip generated.');
            redirect('/hr/salary_slip_view.php?id=' . $slipId);
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = str_contains($e->getMessage(), 'Duplicate') ? 'A salary slip for this employee and month already exists.' : 'Could not save salary slip.' . (defined('APP_DEBUG') && APP_DEBUG ? ' DEBUG: ' . $e->getMessage() : '');
            $errorTab = 'details';
        }
    }
    if ($errorTab) {
        $activeTab = $errorTab;
    }
}

$employees = db()->query("SELECT e.id, e.employee_code, e.name, e.salary, e.salary_mode, e.bank_name, e.bank_account_no, e.bank_ifsc, e.designation, e.status, d.name department_name
    FROM employees e LEFT JOIN departments d ON d.id = e.department_id
    WHERE e.status = 'active' OR e.id = " . (int)$slip['employee_id'] . " ORDER BY e.name")->fetchAll();

// Each employee's salary structure (Employee > Salary & Bank) and bank
// details, used to prefill the slip when an employee is picked.
$employeeComponents = [];
foreach (db()->query('SELECT employee_id, component_type, label, amount FROM employee_salary_components ORDER BY employee_id, sort_order, id') as $c) {
    $employeeComponents[(int)$c['employee_id']][$c['component_type']][] = ['label' => $c['label'], 'amount' => $c['amount']];
}
$employeeMeta = [];
foreach ($employees as $emp) {
    $employeeMeta[(int)$emp['id']] = [
        'salary' => $emp['salary'], 'salary_mode' => $emp['salary_mode'], 'bank_name' => $emp['bank_name'] ?? '',
        'bank_account_no' => $emp['bank_account_no'] ?? '', 'bank_ifsc' => $emp['bank_ifsc'] ?? '',
        'department' => $emp['department_name'] ?? '', 'designation' => $emp['designation'] ?? '',
        'earning' => $employeeComponents[(int)$emp['id']]['earning'] ?? [], 'deduction' => $employeeComponents[(int)$emp['id']]['deduction'] ?? [],
    ];
}

// Opened from an employee's page: preselect them and prefill their structure and days.
$prefillDays = null;
if (!is_post() && !$id && ($preselect = (int)input('employee')) && isset($employeeMeta[$preselect])) {
    $m = $employeeMeta[$preselect];
    $slip = array_merge($slip, [
        'employee_id' => $preselect, 'full_basic_salary' => $m['salary'], 'salary_mode' => $m['salary_mode'],
        'bank_name' => $m['bank_name'], 'bank_account_no' => $m['bank_account_no'], 'bank_ifsc' => $m['bank_ifsc'],
    ]);
    $earnings = $m['earning'];
    $deductions = $m['deduction'];
}
if (!is_post() && !$id && $slip['employee_id']) {
    $prefillDays = hr_payroll_days((int)$slip['employee_id'], $slip['pay_month']);
    $slip['working_days'] = $prefillDays['working_days'];
    $slip['present_days'] = $prefillDays['present_days'];
    $slip['paid_leave_days'] = $prefillDays['paid_leave'];
    $slip['lop_days'] = $prefillDays['lop_days'];
}
if ($slip['working_days'] === '') {
    $slip['working_days'] = hr_working_days($slip['pay_month'] . '-01', date('Y-m-t', strtotime($slip['pay_month'] . '-01')));
}

if (!$earnings) {
    $earnings = [['label' => '', 'amount' => '']];
}
if (!$deductions) {
    $deductions = [['label' => '', 'amount' => '']];
}
$currentMeta = $employeeMeta[(int)$slip['employee_id']] ?? ['department' => '', 'designation' => ''];
$fmtDays = fn($v) => $v === '' || $v === null ? '' : rtrim(rtrim(number_format((float)$v, 1, '.', ''), '0'), '.');

$page_title = $id ? 'Edit Salary Slip' : 'Generate Salary Slip';
require __DIR__ . '/../includes/header.php';
?>
<div class="card p-4">
  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0"><?= $id ? e($slip['slip_no']) : 'New Salary Slip' ?> <span class="badge text-bg-secondary badge-status">draft</span></h5>
  </div>

  <ul class="nav nav-tabs mb-3">
    <li class="nav-item"><button class="nav-link <?= $activeTab === 'details' ? 'active' : '' ?>" id="tab-details" data-bs-toggle="tab" data-bs-target="#pane-details" type="button">Details</button></li>
    <li class="nav-item"><button class="nav-link <?= $activeTab === 'attendance' ? 'active' : '' ?>" id="tab-attendance" data-bs-toggle="tab" data-bs-target="#pane-attendance" type="button">Attendance &amp; Pay Days</button></li>
    <li class="nav-item"><button class="nav-link <?= $activeTab === 'components' ? 'active' : '' ?>" id="tab-components" data-bs-toggle="tab" data-bs-target="#pane-components" type="button">Earnings &amp; Deductions</button></li>
    <li class="nav-item"><button class="nav-link <?= $activeTab === 'payment' ? 'active' : '' ?>" id="tab-payment" data-bs-toggle="tab" data-bs-target="#pane-payment" type="button">Payment</button></li>
    <li class="nav-item"><button class="nav-link <?= $activeTab === 'more' ? 'active' : '' ?>" id="tab-more" data-bs-toggle="tab" data-bs-target="#pane-more" type="button">More Info</button></li>
  </ul>

  <form method="post" id="slipForm">
    <?= csrf_field() ?>
    <div class="tab-content">
      <div class="tab-pane fade <?= $activeTab === 'details' ? 'show active' : '' ?>" id="pane-details">
        <h6 class="text-muted mb-3"><i class="fa-solid fa-user-tie"></i> Employee &amp; Period</h6>
        <div class="row g-3 mb-3">
          <div class="col-sm-3">
            <label class="form-label">Employee <span class="text-danger">*</span></label>
            <select name="employee_id" id="empSelect" class="form-select" required>
              <option value="">— Select employee —</option>
              <?php foreach ($employees as $emp): ?>
                <option value="<?= (int)$emp['id'] ?>" data-salary="<?= e($emp['salary']) ?>" <?= (string)$slip['employee_id'] === (string)$emp['id'] ? 'selected' : '' ?>><?= e($emp['name']) ?> (<?= e($emp['employee_code']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-3">
            <label class="form-label">Pay Month <span class="text-danger">*</span></label>
            <input type="month" name="pay_month" id="payMonthInput" class="form-control" required value="<?= e($slip['pay_month']) ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Posting Date</label>
            <input type="date" name="posting_date" class="form-control" value="<?= e($slip['posting_date'] ?? today()) ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Salary Slip No.</label>
            <input type="text" class="form-control" value="<?= $id ? e($slip['slip_no']) : 'Auto-generated' ?>" disabled>
          </div>
          <div class="col-sm-3">
            <label class="form-label">Department</label>
            <input type="text" id="deptOut" class="form-control" value="<?= e($currentMeta['department']) ?>" disabled>
          </div>
          <div class="col-sm-3">
            <label class="form-label">Designation</label>
            <input type="text" id="desigOut" class="form-control" value="<?= e($currentMeta['designation']) ?>" disabled>
          </div>
        </div>
      </div>

      <div class="tab-pane fade <?= $activeTab === 'attendance' ? 'show active' : '' ?>" id="pane-attendance">
        <h6 class="text-muted mb-3"><i class="fa-solid fa-calendar-days"></i> Pay Days <button type="button" class="btn btn-sm btn-outline-brand ms-2" id="fetchDaysBtn"><i class="fa-solid fa-rotate"></i> Fetch from attendance</button></h6>
        <div class="row g-3 mb-2">
          <div class="col-sm-3">
            <label class="form-label">Working Days <span class="text-danger">*</span></label>
            <input type="number" step="0.5" min="0" name="working_days" id="workingDays" class="form-control" value="<?= e($fmtDays($slip['working_days'])) ?>">
            <div class="form-text">Days in the month excluding Sundays.</div>
          </div>
          <div class="col-sm-3">
            <label class="form-label">Present Days</label>
            <input type="number" step="0.5" min="0" name="present_days" id="presentDays" class="form-control" value="<?= e($fmtDays($slip['present_days'])) ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Paid Leave Days</label>
            <input type="number" step="0.5" min="0" name="paid_leave_days" id="paidLeaveDays" class="form-control" value="<?= e($fmtDays($slip['paid_leave_days'])) ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Loss of Pay (LOP) Days</label>
            <input type="number" step="0.5" min="0" name="lop_days" id="lopDays" class="form-control" value="<?= e($fmtDays($slip['lop_days'])) ?>">
            <div class="form-text">Absences, unpaid leave and days outside employment.</div>
          </div>
          <div class="col-sm-3">
            <label class="form-label">Payment Days</label>
            <input type="text" id="paymentDaysOut" class="form-control" disabled>
          </div>
          <div class="col-sm-3 d-flex align-items-end">
            <div class="form-check mb-2">
              <input class="form-check-input" type="checkbox" name="prorate_lop" id="prorateLop" value="1" <?= $slip['prorate_lop'] ? 'checked' : '' ?>>
              <label class="form-check-label" for="prorateLop">Reduce earnings for LOP days</label>
            </div>
          </div>
        </div>
        <div class="small text-muted" id="daysNote"><?php if ($prefillDays && $prefillDays['unmarked'] > 0): ?><?= $fmtDays($prefillDays['unmarked']) ?> working day(s) have no attendance marked and are treated as paid.<?php endif; ?></div>
      </div>

      <div class="tab-pane fade <?= $activeTab === 'components' ? 'show active' : '' ?>" id="pane-components">
        <div class="row g-3 mb-3">
          <div class="col-sm-3">
            <label class="form-label">Basic Salary (monthly)</label>
            <input type="number" step="0.01" min="0" name="full_basic_salary" id="basicSalaryInput" class="form-control" value="<?= e($slip['full_basic_salary']) ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Basic for this slip</label>
            <input type="text" id="basicPayOut" class="form-control" disabled>
          </div>
        </div>
        <div class="row g-3">
          <div class="col-md-6">
            <h6 class="mb-2 text-success"><i class="fa-solid fa-plus"></i> Earnings</h6>
            <div class="payroll-items" data-row-prefix="earn">
              <table class="table table-sm align-middle">
                <thead><tr><th>Component</th><th style="width:26%">Monthly</th><th style="width:22%" class="text-end">This slip</th><th style="width:1%"></th></tr></thead>
                <tbody>
                <?php foreach ($earnings as $row): ?>
                  <tr data-row>
                    <td><input type="text" class="form-control form-control-sm" name="earn_label[]" maxlength="100" value="<?= e($row['label']) ?>" placeholder="e.g. HRA, Bonus"></td>
                    <td><input type="number" step="0.01" min="0" class="form-control form-control-sm text-end" name="earn_amount[]" value="<?= e($row['full_amount'] ?? $row['amount']) ?>"></td>
                    <td class="text-end small js-pay-amount"></td>
                    <td><button type="button" class="btn btn-sm btn-outline-danger js-remove-row" title="Remove"><i class="fa-solid fa-xmark"></i></button></td>
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
              <table class="table table-sm align-middle">
                <thead><tr><th>Component</th><th style="width:30%">Amount</th><th style="width:1%"></th></tr></thead>
                <tbody>
                <?php foreach ($deductions as $row): ?>
                  <tr data-row>
                    <td><input type="text" class="form-control form-control-sm" name="ded_label[]" maxlength="100" value="<?= e($row['label']) ?>" placeholder="e.g. Provident Fund, TDS"></td>
                    <td><input type="number" step="0.01" min="0" class="form-control form-control-sm text-end" name="ded_amount[]" value="<?= e($row['amount']) ?>"></td>
                    <td><button type="button" class="btn btn-sm btn-outline-danger js-remove-row" title="Remove"><i class="fa-solid fa-xmark"></i></button></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
              <button type="button" class="btn btn-sm btn-outline-brand js-add-row">Add deduction</button>
            </div>
          </div>
        </div>

        <div class="card bg-light border-0 p-3 mt-3" style="max-width:380px">
          <div class="d-flex justify-content-between small mb-1"><span>Total Earnings</span><strong id="totalEarnings">0.00</strong></div>
          <div class="d-flex justify-content-between small mb-1"><span>Total Deductions</span><strong id="totalDeductions">0.00</strong></div>
          <div class="d-flex justify-content-between fs-5 border-top pt-2 mt-1"><span>Net Pay</span><strong id="netPay">0.00</strong></div>
        </div>
      </div>

      <div class="tab-pane fade <?= $activeTab === 'payment' ? 'show active' : '' ?>" id="pane-payment">
        <h6 class="text-muted mb-3"><i class="fa-solid fa-building-columns"></i> Pay To</h6>
        <div class="row g-3 mb-3">
          <div class="col-sm-3">
            <label class="form-label">Salary Mode</label>
            <select name="salary_mode" id="salaryModeSelect" class="form-select">
              <?php foreach ($salaryModes as $k => $v): ?><option value="<?= $k ?>" <?= $slip['salary_mode'] === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-3">
            <label class="form-label">Bank Name</label>
            <input type="text" name="bank_name" id="bankNameInput" class="form-control" maxlength="120" value="<?= e($slip['bank_name'] ?? '') ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Account No. <span class="text-danger bank-req">*</span></label>
            <input type="text" name="bank_account_no" id="bankAccountInput" class="form-control" maxlength="40" value="<?= e($slip['bank_account_no'] ?? '') ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">IFSC <span class="text-danger bank-req">*</span></label>
            <input type="text" name="bank_ifsc" id="bankIfscInput" class="form-control" maxlength="11" style="text-transform:uppercase" value="<?= e($slip['bank_ifsc'] ?? '') ?>">
          </div>
        </div>
        <div class="small text-muted">Filled in from the employee's Salary &amp; Bank tab. The payment date and method are recorded when you mark the slip as paid.</div>
      </div>

      <div class="tab-pane fade <?= $activeTab === 'more' ? 'show active' : '' ?>" id="pane-more">
        <div class="row g-3 mb-3">
          <div class="col-sm-6">
            <label class="form-label">Note on Payslip</label>
            <input type="text" name="notes" class="form-control" maxlength="255" value="<?= e($slip['notes'] ?? '') ?>" placeholder="Printed on the payslip">
          </div>
          <div class="col-12">
            <label class="form-label">Internal Remarks</label>
            <textarea name="remarks" class="form-control" rows="3"><?= e($slip['remarks'] ?? '') ?></textarea>
          </div>
        </div>
      </div>
    </div>

    <div class="page-actions mt-3">
      <button type="submit" class="btn btn-brand"><?= $id ? 'Save Salary Slip' : 'Generate Slip' ?></button>
      <a href="<?= $id ? 'salary_slip_view.php?id=' . $id : 'salary_slips.php' ?>" class="btn btn-outline-secondary">Cancel</a>
    </div>
  </form>
</div>
<?php
$extra_js_inline = "
var employeeMeta = " . json_encode((object)$employeeMeta) . ";
var payrollDaysUrl = " . json_encode(base_url('hr/payroll_days.php')) . ";
function num(id) { return parseFloat(document.getElementById(id).value || 0); }
function fmtDays(v) { return String(Math.round(v * 2) / 2); }

// Replaces a payroll table's rows with the given [{label, amount}] list,
// keeping one blank row when the list is empty.
function fillPayrollRows(prefix, rows) {
  var tbody = document.querySelector('.payroll-items[data-row-prefix=\"' + prefix + '\"] tbody');
  var template = tbody.querySelector('tr[data-row]');
  tbody.querySelectorAll('tr[data-row]').forEach(function (tr, i) { if (i > 0) tr.remove(); });
  (rows.length ? rows : [{label: '', amount: ''}]).forEach(function (r, i) {
    var tr = i === 0 ? template : template.cloneNode(true);
    tr.querySelector('input[name=\"' + prefix + '_label[]\"]').value = r.label;
    tr.querySelector('input[name=\"' + prefix + '_amount[]\"]').value = r.amount;
    if (i > 0) tbody.appendChild(tr);
  });
}

function fetchDays() {
  var emp = document.getElementById('empSelect').value, month = document.getElementById('payMonthInput').value;
  if (!emp || !month) return;
  fetch(payrollDaysUrl + '?employee=' + encodeURIComponent(emp) + '&month=' + encodeURIComponent(month), {credentials: 'same-origin'})
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (d.error) return;
      document.getElementById('workingDays').value = fmtDays(d.working_days);
      document.getElementById('presentDays').value = fmtDays(d.present_days);
      document.getElementById('paidLeaveDays').value = fmtDays(d.paid_leave);
      document.getElementById('lopDays').value = fmtDays(d.lop_days);
      document.getElementById('daysNote').textContent = d.unmarked > 0 ? fmtDays(d.unmarked) + ' working day(s) have no attendance marked and are treated as paid.' : '';
      recalcPayroll();
    });
}

document.getElementById('empSelect').addEventListener('change', function () {
  var m = employeeMeta[this.value];
  if (m) {
    document.getElementById('basicSalaryInput').value = m.salary;
    fillPayrollRows('earn', m.earning || []);
    fillPayrollRows('ded', m.deduction || []);
    document.getElementById('salaryModeSelect').value = m.salary_mode || 'bank_transfer';
    document.getElementById('bankNameInput').value = m.bank_name;
    document.getElementById('bankAccountInput').value = m.bank_account_no;
    document.getElementById('bankIfscInput').value = m.bank_ifsc;
    document.getElementById('deptOut').value = m.department;
    document.getElementById('desigOut').value = m.designation;
  }
  fetchDays();
  recalcPayroll();
});
document.getElementById('payMonthInput').addEventListener('change', fetchDays);
document.getElementById('fetchDaysBtn').addEventListener('click', fetchDays);

document.querySelectorAll('.payroll-items').forEach(function (wrap) {
  var tbody = wrap.querySelector('tbody');
  wrap.addEventListener('click', function (e) {
    if (e.target.closest('.js-add-row')) {
      var rows = tbody.querySelectorAll('tr[data-row]');
      var clone = rows[rows.length - 1].cloneNode(true);
      clone.querySelectorAll('input').forEach(function (inp) { inp.value = ''; });
      tbody.appendChild(clone);
      recalcPayroll();
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

// Live preview only; the server recomputes every amount on save.
function recalcPayroll() {
  var working = num('workingDays'), lop = num('lopDays');
  var payment = Math.max(0, working - lop);
  document.getElementById('paymentDaysOut').value = fmtDays(payment);
  var factor = document.getElementById('prorateLop').checked && working > 0 ? payment / working : 1;
  var basic = Math.round(num('basicSalaryInput') * factor * 100) / 100;
  document.getElementById('basicPayOut').value = basic.toFixed(2);
  var earn = basic;
  document.querySelectorAll('.payroll-items[data-row-prefix=earn] tr[data-row]').forEach(function (tr) {
    var full = parseFloat(tr.querySelector('input[name=\"earn_amount[]\"]').value || 0);
    var pay = Math.round(full * factor * 100) / 100;
    tr.querySelector('.js-pay-amount').textContent = full ? pay.toFixed(2) : '';
    earn += pay;
  });
  var ded = 0;
  document.querySelectorAll('input[name=\"ded_amount[]\"]').forEach(function (i) { ded += parseFloat(i.value || 0); });
  document.getElementById('totalEarnings').textContent = earn.toFixed(2);
  document.getElementById('totalDeductions').textContent = ded.toFixed(2);
  document.getElementById('netPay').textContent = (earn - ded).toFixed(2);
  var mode = document.getElementById('salaryModeSelect').value;
  document.querySelectorAll('.bank-req').forEach(function (s) { s.style.display = mode === 'bank_transfer' ? '' : 'none'; });
}
document.getElementById('slipForm').addEventListener('input', recalcPayroll);
document.getElementById('slipForm').addEventListener('change', recalcPayroll);
recalcPayroll();
";
require __DIR__ . '/../includes/footer.php';
