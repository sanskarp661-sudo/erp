<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
$canEdit = can_edit_module('hrms');
$canManage = can_manage_module('hrms');

$id = (int)input('id');
$stmt = db()->prepare('SELECT e.*, d.name department_name, m.name manager_name, m.employee_code manager_code, la.name leave_approver_name, u.name user_name
    FROM employees e
    LEFT JOIN departments d ON d.id = e.department_id
    LEFT JOIN employees m ON m.id = e.reports_to_id
    LEFT JOIN users la ON la.id = e.leave_approver_id
    LEFT JOIN users u ON u.id = e.user_id
    WHERE e.id = ?');
$stmt->execute([$id]);
$emp = $stmt->fetch();
if (!$emp) {
    flash('danger', 'Employee not found.');
    redirect('/hr/employees.php');
}

$stmt = db()->prepare('SELECT * FROM employee_salary_components WHERE employee_id = ? ORDER BY sort_order, id');
$stmt->execute([$id]);
$components = $stmt->fetchAll();
$stmt = db()->prepare('SELECT * FROM employee_education WHERE employee_id = ? ORDER BY sort_order, id');
$stmt->execute([$id]);
$education = $stmt->fetchAll();
$stmt = db()->prepare('SELECT * FROM employee_experience WHERE employee_id = ? ORDER BY sort_order, id');
$stmt->execute([$id]);
$experience = $stmt->fetchAll();
$stmt = db()->prepare('SELECT id, name, employee_code, designation FROM employees WHERE reports_to_id = ? ORDER BY name');
$stmt->execute([$id]);
$directReports = $stmt->fetchAll();
$stmt = db()->prepare("SELECT
    (SELECT COUNT(*) FROM leaves WHERE employee_id = ? AND status = 'approved') approved_leaves,
    (SELECT COUNT(*) FROM leaves WHERE employee_id = ? AND status = 'pending') pending_leaves,
    (SELECT COUNT(*) FROM attendance WHERE employee_id = ? AND status = 'present' AND attendance_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) present_this_month,
    (SELECT COUNT(*) FROM salary_slips WHERE employee_id = ?) salary_slips");
$stmt->execute([$id, $id, $id, $id]);
$stats = $stmt->fetch();
$activities = get_activity_log('employee', $id);

$labels = [
    'gender' => ['male' => 'Male', 'female' => 'Female', 'other' => 'Other'],
    'marital_status' => ['single' => 'Single', 'married' => 'Married', 'divorced' => 'Divorced', 'widowed' => 'Widowed'],
    'employment_type' => ['full_time' => 'Full-time', 'part_time' => 'Part-time', 'contract' => 'Contract', 'intern' => 'Intern', 'apprentice' => 'Apprentice'],
    'salary_mode' => ['bank_transfer' => 'Bank Transfer', 'cash' => 'Cash', 'cheque' => 'Cheque'],
    'status' => ['active' => 'Active', 'inactive' => 'Inactive', 'left' => 'Left'],
];
$statusBadge = ['active' => 'success', 'inactive' => 'secondary', 'left' => 'dark'];

function emp_facts(array $facts): string
{
    $html = '';
    foreach ($facts as $label => $value) {
        if ($value === null || $value === '' || $value === '0000-00-00') {
            continue;
        }
        $html .= '<div class="col-sm-6 col-lg-3"><div class="small text-muted">' . e($label) . '</div><div style="white-space:pre-line">' . e($value) . '</div></div>';
    }
    return $html ?: '<div class="col-12 text-muted small">Nothing recorded.</div>';
}

/** Shows only the last four characters of a sensitive number. */
function emp_mask(?string $v): ?string
{
    if ($v === null || $v === '') {
        return null;
    }
    return str_repeat('•', max(0, strlen($v) - 4)) . substr($v, -4);
}

/** "3 yr 2 mo" between two dates. */
function emp_span(?string $from, ?string $to): ?string
{
    if (!$from || !$to || $to < $from) {
        return null;
    }
    $d = (new DateTime($from))->diff(new DateTime($to));
    return ($d->y ? $d->y . ' yr ' : '') . $d->m . ' mo';
}

$age = $emp['date_of_birth'] ? (new DateTime($emp['date_of_birth']))->diff(new DateTime())->y : null;
$tenure = $emp['hire_date'] ? emp_span($emp['hire_date'], $emp['relieving_date'] && $emp['relieving_date'] < today() ? $emp['relieving_date'] : today()) : null;
$earnings = array_filter($components, fn($c) => $c['component_type'] === 'earning');
$deductions = array_filter($components, fn($c) => $c['component_type'] === 'deduction');

$page_title = 'Employee';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-1"><?= e(trim(($emp['salutation'] ? $emp['salutation'] . '. ' : '') . $emp['name'])) ?> <span class="badge text-bg-<?= $statusBadge[$emp['status']] ?? 'secondary' ?> badge-status"><?= e($labels['status'][$emp['status']] ?? $emp['status']) ?></span></h4>
    <div class="text-muted"><?= e($emp['employee_code']) ?><?= $emp['designation'] ? ' &middot; ' . e($emp['designation']) : '' ?><?= $emp['department_name'] ? ' &middot; ' . e($emp['department_name']) : '' ?></div>
  </div>
  <div class="page-actions">
    <?php if ($canEdit): ?><a href="employee_form.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-pen"></i> Edit</a><?php endif; ?>
    <?php if ($canManage): ?>
      <?php if ($emp['status'] === 'active'): ?><a href="salary_slip_form.php?employee=<?= $id ?>" class="btn btn-brand btn-sm"><i class="fa-solid fa-file-invoice-dollar"></i> Generate Salary Slip</a><?php endif; ?>
      <a href="salary_slips.php?employee=<?= $id ?>" class="btn btn-outline-brand btn-sm"><i class="fa-solid fa-money-check-dollar"></i> Salary Slips (<?= (int)$stats['salary_slips'] ?>)</a>
    <?php endif; ?>
    <a href="employees.php" class="btn btn-outline-secondary btn-sm">Back to list</a>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3"><div class="card p-3"><div class="small text-muted">Tenure</div><div class="fs-5"><?= e($tenure ?? '—') ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="card p-3"><div class="small text-muted">Present this month</div><div class="fs-5"><?= (int)$stats['present_this_month'] ?> days</div></div></div>
  <div class="col-6 col-lg-3"><div class="card p-3"><div class="small text-muted">Leaves approved</div><div class="fs-5"><?= (int)$stats['approved_leaves'] ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="card p-3"><div class="small text-muted">Leaves pending</div><div class="fs-5"><?= (int)$stats['pending_leaves'] ?></div></div></div>
</div>

<div class="card p-3 mb-3">
  <h6 class="mb-3">Details</h6>
  <div class="row g-3">
    <?= emp_facts([
        'Gender' => $labels['gender'][$emp['gender']] ?? null,
        'Date of Birth' => $emp['date_of_birth'] ? $emp['date_of_birth'] . ' (age ' . $age . ')' : null,
        'Marital Status' => $labels['marital_status'][$emp['marital_status']] ?? null, 'Blood Group' => $emp['blood_group'],
        "Father's / Spouse's Name" => $emp['father_or_spouse_name'], 'Nationality' => $emp['nationality'],
    ]) ?>
  </div>
</div>

<div class="card p-3 mb-3">
  <h6 class="mb-3">Employment</h6>
  <div class="row g-3">
    <?= emp_facts([
        'Department' => $emp['department_name'], 'Designation' => $emp['designation'],
        'Reports To' => $emp['manager_name'] ? $emp['manager_name'] . ' (' . $emp['manager_code'] . ')' : null,
        'Employment Type' => $labels['employment_type'][$emp['employment_type']] ?? null, 'Grade' => $emp['grade'],
        'Work Location' => $emp['work_location'], 'Work Shift' => $emp['work_shift'], 'Date of Joining' => $emp['hire_date'],
        'Probation Ends' => $emp['probation_end_date'], 'Confirmation Date' => $emp['confirmation_date'],
        'Notice Period' => $emp['notice_period_days'] . ' days', 'Leave Approver' => $emp['leave_approver_name'],
        'User Account' => $emp['user_name'], 'Attendance Device ID' => $emp['biometric_id'],
    ]) ?>
  </div>
  <?php if ($directReports): ?>
    <div class="mt-3 small"><span class="text-muted">Direct reports:</span>
      <?php foreach ($directReports as $i => $r): ?><?= $i ? ', ' : '' ?><a href="employee_view.php?id=<?= (int)$r['id'] ?>"><?= e($r['name']) ?></a><?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div class="card p-3 mb-3">
  <h6 class="mb-3">Contact &amp; Address</h6>
  <div class="row g-3">
    <?= emp_facts([
        'Work Email' => $emp['email'], 'Personal Email' => $emp['personal_email'], 'Mobile' => $emp['phone'],
        'Alternate Phone' => $emp['alternate_phone'], 'Current Address' => $emp['current_address'],
        'Permanent Address' => $emp['permanent_address'] === $emp['current_address'] && $emp['current_address'] ? 'Same as current' : $emp['permanent_address'],
        'City' => $emp['city'], 'State' => $emp['state'], 'Pincode' => $emp['pincode'], 'Country' => $emp['country'],
        'Emergency Contact' => $emp['emergency_contact_name'] ? $emp['emergency_contact_name'] . ($emp['emergency_contact_relation'] ? ' (' . $emp['emergency_contact_relation'] . ')' : '') : null,
        'Emergency Phone' => $emp['emergency_contact_phone'],
    ]) ?>
  </div>
</div>

<?php if ($canManage): ?>
<div class="row g-3 mb-3">
  <div class="col-lg-6">
    <div class="card p-3 h-100">
      <h6 class="mb-2">Salary Structure (monthly)</h6>
      <table class="table table-sm mb-0">
        <tbody>
          <tr><td>Basic Salary</td><td class="text-end"><?= money($emp['salary']) ?></td></tr>
          <?php foreach ($earnings as $c): ?><tr><td><?= e($c['label']) ?></td><td class="text-end"><?= money($c['amount']) ?></td></tr><?php endforeach; ?>
          <tr><th>Gross</th><th class="text-end"><?= money($emp['monthly_gross']) ?></th></tr>
          <?php foreach ($deductions as $c): ?><tr><td class="text-muted"><?= e($c['label']) ?></td><td class="text-end text-muted">-<?= money($c['amount']) ?></td></tr><?php endforeach; ?>
          <tr><th>Net Pay</th><th class="text-end"><?= money((float)$emp['monthly_gross'] - (float)$emp['monthly_deductions']) ?></th></tr>
          <tr><td class="text-muted">Annual CTC</td><td class="text-end"><?= money($emp['annual_ctc']) ?></td></tr>
        </tbody>
      </table>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card p-3 h-100">
      <h6 class="mb-3">Bank &amp; Statutory</h6>
      <div class="row g-3">
        <?= emp_facts([
            'Salary Mode' => $labels['salary_mode'][$emp['salary_mode']] ?? null, 'Bank' => $emp['bank_name'],
            'Account Holder' => $emp['bank_account_holder'], 'Account No.' => emp_mask($emp['bank_account_no']),
            'IFSC' => $emp['bank_ifsc'], 'PAN' => $emp['pan_no'], 'Aadhaar' => emp_mask($emp['aadhaar_no']),
            'UAN' => $emp['uan_no'], 'PF No.' => $emp['pf_no'], 'ESI No.' => $emp['esi_no'],
        ]) ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-lg-6">
    <div class="card p-3 h-100">
      <h6 class="mb-2">Education</h6>
      <?php if ($education): ?>
      <table class="table table-sm mb-0">
        <thead><tr><th>Qualification</th><th>Institute</th><th>Year</th><th>Grade</th></tr></thead>
        <tbody><?php foreach ($education as $ed): ?><tr><td><?= e($ed['qualification']) ?></td><td><?= e($ed['institute'] ?? '') ?></td><td><?= e($ed['year_of_passing'] ?? '') ?></td><td><?= e($ed['grade'] ?? '') ?></td></tr><?php endforeach; ?></tbody>
      </table>
      <?php else: ?><div class="text-muted small">Nothing recorded.</div><?php endif; ?>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card p-3 h-100">
      <h6 class="mb-2">Previous Experience</h6>
      <?php if ($experience): ?>
      <table class="table table-sm mb-0">
        <thead><tr><th>Company</th><th>Designation</th><th>Period</th><?php if ($canManage): ?><th class="text-end">Last Salary</th><?php endif; ?></tr></thead>
        <tbody><?php foreach ($experience as $x): ?><tr>
          <td><?= e($x['company']) ?></td><td><?= e($x['designation'] ?? '') ?></td>
          <td><?= e(trim(($x['from_date'] ?? '') . ' – ' . ($x['to_date'] ?? ''), ' –')) ?><?php if ($s = emp_span($x['from_date'], $x['to_date'])): ?> <span class="text-muted small">(<?= e($s) ?>)</span><?php endif; ?></td>
          <?php if ($canManage): ?><td class="text-end"><?= $x['last_salary'] !== null ? money($x['last_salary']) : '' ?></td><?php endif; ?>
        </tr><?php endforeach; ?></tbody>
      </table>
      <?php else: ?><div class="text-muted small">Nothing recorded.</div><?php endif; ?>
    </div>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-6">
    <div class="card p-3 h-100">
      <h6 class="mb-3">Exit &amp; More Info</h6>
      <div class="row g-3">
        <?= emp_facts([
            'Resignation Date' => $emp['resignation_date'], 'Relieving Date' => $emp['relieving_date'], 'Reason for Leaving' => $emp['exit_reason'],
            'Exit Interview Notes' => $emp['exit_notes'], 'Tags' => $emp['tags'], 'Internal Remarks' => $emp['remarks'],
        ]) ?>
      </div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card p-3 h-100">
      <h6 class="mb-2">Activity</h6>
      <ul class="list-unstyled activity-feed mb-0">
        <?php foreach ($activities as $a): ?>
          <li><?= format_activity($a) ?> <span class="text-muted small">&middot; <?= e(date('M j, Y', strtotime($a['created_at']))) ?></span></li>
        <?php endforeach; ?>
        <?php if (!$activities): ?><li class="text-muted small">No activity yet.</li><?php endif; ?>
      </ul>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
