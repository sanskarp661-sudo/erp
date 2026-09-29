<?php
require_once __DIR__ . '/../includes/auth.php';
require_module_edit('hrms');

// Salary, bank and statutory IDs are only visible to HR managers, matching
// the Employees list, which hides the Salary column from everyone else.
$canManage = can_manage_module('hrms');

$id = (int)input('id');
$employee = [
    'id' => 0, 'employee_code' => '', 'salutation' => '', 'name' => '', 'gender' => '', 'date_of_birth' => '',
    'marital_status' => '', 'blood_group' => '', 'nationality' => 'Indian', 'father_or_spouse_name' => '', 'status' => 'active',
    'department_id' => '', 'designation' => '', 'reports_to_id' => '', 'employment_type' => 'full_time', 'grade' => '',
    'work_location' => '', 'work_shift' => '', 'hire_date' => today(), 'probation_end_date' => '', 'confirmation_date' => '',
    'notice_period_days' => 30, 'leave_approver_id' => '', 'user_id' => '', 'biometric_id' => '',
    'email' => '', 'personal_email' => '', 'phone' => '', 'alternate_phone' => '', 'current_address' => '',
    'permanent_address' => '', 'city' => '', 'state' => '', 'pincode' => '', 'country' => 'India',
    'emergency_contact_name' => '', 'emergency_contact_relation' => '', 'emergency_contact_phone' => '',
    'salary' => '0', 'salary_mode' => 'bank_transfer', 'bank_name' => '', 'bank_account_holder' => '', 'bank_account_no' => '',
    'bank_ifsc' => '', 'pan_no' => '', 'aadhaar_no' => '', 'uan_no' => '', 'pf_no' => '', 'esi_no' => '',
    'monthly_gross' => 0, 'monthly_deductions' => 0, 'annual_ctc' => 0,
    'resignation_date' => '', 'relieving_date' => '', 'exit_reason' => '', 'exit_notes' => '', 'remarks' => '', 'tags' => '',
];
$components = [];
$education = [];
$experience = [];
$original = null;

if ($id) {
    $stmt = db()->prepare('SELECT * FROM employees WHERE id = ?');
    $stmt->execute([$id]);
    $original = $stmt->fetch();
    if (!$original) {
        flash('danger', 'Employee not found.');
        redirect('/hr/employees.php');
    }
    $employee = $original;
    $stmt = db()->prepare('SELECT component_type, label, amount FROM employee_salary_components WHERE employee_id = ? ORDER BY sort_order, id');
    $stmt->execute([$id]);
    $components = $stmt->fetchAll();
    $stmt = db()->prepare('SELECT qualification, institute, year_of_passing, grade FROM employee_education WHERE employee_id = ? ORDER BY sort_order, id');
    $stmt->execute([$id]);
    $education = $stmt->fetchAll();
    $stmt = db()->prepare('SELECT company, designation, from_date, to_date, last_salary FROM employee_experience WHERE employee_id = ? ORDER BY sort_order, id');
    $stmt->execute([$id]);
    $experience = $stmt->fetchAll();
}

$error = '';
$tabs = ['details', 'employment', 'contact', 'salary', 'qualifications', 'more'];
$activeTab = in_array(input('tab'), $tabs, true) ? input('tab') : 'details';
if ($activeTab === 'salary' && !$canManage) {
    $activeTab = 'details';
}

$salutations = ['Mr', 'Ms', 'Mrs', 'Dr', 'Mx'];
$genders = ['male' => 'Male', 'female' => 'Female', 'other' => 'Other'];
$maritalStatuses = ['single' => 'Single', 'married' => 'Married', 'divorced' => 'Divorced', 'widowed' => 'Widowed'];
$bloodGroups = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
$statuses = ['active' => 'Active', 'inactive' => 'Inactive', 'left' => 'Left'];
$employmentTypes = ['full_time' => 'Full-time', 'part_time' => 'Part-time', 'contract' => 'Contract', 'intern' => 'Intern', 'apprentice' => 'Apprentice'];
$workShifts = ['General', 'Morning', 'Evening', 'Night', 'Rotational'];
$salaryModes = ['bank_transfer' => 'Bank Transfer', 'cash' => 'Cash', 'cheque' => 'Cheque'];
$exitReasons = ['Resignation', 'Better Opportunity', 'Relocation', 'Higher Studies', 'Retirement', 'Termination', 'Contract End', 'Other'];

/** Returns a trimmed POST value or null when blank. */
function emp_input(string $key): ?string
{
    $v = trim((string)input($key));
    return $v === '' ? null : $v;
}

/** Validates a Y-m-d date string, returning it or null. */
function emp_date(?string $v): ?string
{
    if ($v === null) {
        return null;
    }
    $d = DateTime::createFromFormat('Y-m-d', $v);
    return $d && $d->format('Y-m-d') === $v ? $v : null;
}

/** Next EMP-0001-style code, based on the highest existing number (so deletes never cause a duplicate). */
function next_employee_code(): string
{
    $max = (int)db()->query("SELECT MAX(CAST(SUBSTRING(employee_code, 5) AS UNSIGNED)) FROM employees WHERE employee_code REGEXP '^EMP-[0-9]+$'")->fetchColumn();
    return 'EMP-' . str_pad((string)($max + 1), 4, '0', STR_PAD_LEFT);
}

if (is_post()) {
    csrf_verify();
    $pick = fn(string $key, array $allowed, $default = null) => in_array(input($key), $allowed, true) ? input($key) : $default;

    $posted = [
        // Details
        'employee_code' => emp_input('employee_code'),
        'salutation' => $pick('salutation', $salutations),
        'name' => emp_input('name') ?? '',
        'gender' => $pick('gender', array_keys($genders)),
        'date_of_birth' => emp_date(emp_input('date_of_birth')),
        'marital_status' => $pick('marital_status', array_keys($maritalStatuses)),
        'blood_group' => $pick('blood_group', $bloodGroups),
        'nationality' => emp_input('nationality'),
        'father_or_spouse_name' => emp_input('father_or_spouse_name'),
        'status' => $pick('status', array_keys($statuses), 'active'),
        // Employment
        'department_id' => (int)input('department_id') ?: null,
        'designation' => emp_input('designation'),
        'reports_to_id' => (int)input('reports_to_id') ?: null,
        'employment_type' => $pick('employment_type', array_keys($employmentTypes), 'full_time'),
        'grade' => emp_input('grade'),
        'work_location' => emp_input('work_location'),
        'work_shift' => $pick('work_shift', $workShifts),
        'hire_date' => emp_date(emp_input('hire_date')),
        'probation_end_date' => emp_date(emp_input('probation_end_date')),
        'confirmation_date' => emp_date(emp_input('confirmation_date')),
        'notice_period_days' => max(0, min(365, (int)input('notice_period_days'))),
        'leave_approver_id' => (int)input('leave_approver_id') ?: null,
        'user_id' => (int)input('user_id') ?: null,
        'biometric_id' => emp_input('biometric_id'),
        // Contact & Address
        'email' => emp_input('email'),
        'personal_email' => emp_input('personal_email'),
        'phone' => emp_input('phone'),
        'alternate_phone' => emp_input('alternate_phone'),
        'current_address' => emp_input('current_address'),
        'permanent_address' => input('same_as_current') ? emp_input('current_address') : emp_input('permanent_address'),
        'city' => emp_input('city'),
        'state' => emp_input('state'),
        'pincode' => emp_input('pincode'),
        'country' => emp_input('country'),
        'emergency_contact_name' => emp_input('emergency_contact_name'),
        'emergency_contact_relation' => emp_input('emergency_contact_relation'),
        'emergency_contact_phone' => emp_input('emergency_contact_phone'),
        // Exit & More Info
        'resignation_date' => emp_date(emp_input('resignation_date')),
        'relieving_date' => emp_date(emp_input('relieving_date')),
        'exit_reason' => $pick('exit_reason', $exitReasons),
        'exit_notes' => emp_input('exit_notes'),
        'remarks' => emp_input('remarks'),
        'tags' => emp_input('tags'),
    ];

    // Salary & Bank: only HR managers can change it; everyone else keeps
    // whatever is already stored (or the defaults, for a new employee).
    $saveSalary = $canManage;
    if ($saveSalary) {
        $posted += [
            'salary' => max(0, round((float)input('salary'), 2)),
            'salary_mode' => $pick('salary_mode', array_keys($salaryModes), 'bank_transfer'),
            'bank_name' => emp_input('bank_name'),
            'bank_account_holder' => emp_input('bank_account_holder'),
            'bank_account_no' => ($v = emp_input('bank_account_no')) !== null ? preg_replace('/\s+/', '', $v) : null,
            'bank_ifsc' => ($v = emp_input('bank_ifsc')) !== null ? strtoupper($v) : null,
            'pan_no' => ($v = emp_input('pan_no')) !== null ? strtoupper($v) : null,
            'aadhaar_no' => ($v = emp_input('aadhaar_no')) !== null ? preg_replace('/\D/', '', $v) : null,
            'uan_no' => emp_input('uan_no'),
            'pf_no' => emp_input('pf_no'),
            'esi_no' => emp_input('esi_no'),
        ];

        $componentsToSave = [];
        $earningsTotal = 0;
        $deductionsTotal = 0;
        foreach ($_POST['comp_label'] ?? [] as $i => $label) {
            $label = trim((string)$label);
            $amount = round(max(0, (float)($_POST['comp_amount'][$i] ?? 0)), 2);
            if ($label === '' && $amount == 0) {
                continue;
            }
            $type = ($_POST['comp_type'][$i] ?? '') === 'deduction' ? 'deduction' : 'earning';
            $componentsToSave[] = ['component_type' => $type, 'label' => $label, 'amount' => $amount];
            if ($type === 'earning') {
                $earningsTotal += $amount;
            } else {
                $deductionsTotal += $amount;
            }
        }
        // Totals are always computed here, never taken from the live preview.
        $posted['monthly_gross'] = round($posted['salary'] + $earningsTotal, 2);
        $posted['monthly_deductions'] = round($deductionsTotal, 2);
        $posted['annual_ctc'] = round($posted['monthly_gross'] * 12, 2);
    }

    $educationToSave = [];
    foreach ($_POST['edu_qualification'] ?? [] as $i => $q) {
        $row = [
            'qualification' => trim((string)$q),
            'institute' => trim((string)($_POST['edu_institute'][$i] ?? '')) ?: null,
            'year_of_passing' => (int)($_POST['edu_year'][$i] ?? 0) ?: null,
            'grade' => trim((string)($_POST['edu_grade'][$i] ?? '')) ?: null,
        ];
        if ($row['qualification'] === '' && !$row['institute'] && !$row['year_of_passing'] && !$row['grade']) {
            continue;
        }
        $educationToSave[] = $row;
    }

    $experienceToSave = [];
    foreach ($_POST['exp_company'] ?? [] as $i => $c) {
        $lastSalary = trim((string)($_POST['exp_last_salary'][$i] ?? ''));
        $row = [
            'company' => trim((string)$c),
            'designation' => trim((string)($_POST['exp_designation'][$i] ?? '')) ?: null,
            'from_date' => emp_date(trim((string)($_POST['exp_from'][$i] ?? '')) ?: null),
            'to_date' => emp_date(trim((string)($_POST['exp_to'][$i] ?? '')) ?: null),
            'last_salary' => $lastSalary === '' ? null : max(0, round((float)$lastSalary, 2)),
        ];
        if ($row['company'] === '' && !$row['designation'] && !$row['from_date'] && !$row['to_date'] && $row['last_salary'] === null) {
            continue;
        }
        $experienceToSave[] = $row;
    }

    // Validation, each error pointing at the tab that holds the field.
    $errorTab = null;
    $fail = function (string $msg, string $tab) use (&$error, &$errorTab) {
        if ($error === '') {
            $error = $msg;
            $errorTab = $tab;
        }
    };
    if ($posted['name'] === '') {
        $fail('Full Name is required.', 'details');
    }
    if ($posted['date_of_birth'] && $posted['date_of_birth'] > today()) {
        $fail('Date of Birth cannot be in the future.', 'details');
    }
    if (!$posted['hire_date']) {
        $fail('Date of Joining is required.', 'employment');
    }
    if ($posted['date_of_birth'] && $posted['hire_date'] && $posted['hire_date'] <= $posted['date_of_birth']) {
        $fail('Date of Joining must be after Date of Birth.', 'employment');
    }
    if ($posted['probation_end_date'] && $posted['hire_date'] && $posted['probation_end_date'] < $posted['hire_date']) {
        $fail('Probation End Date cannot be before the Date of Joining.', 'employment');
    }
    if ($posted['confirmation_date'] && $posted['hire_date'] && $posted['confirmation_date'] < $posted['hire_date']) {
        $fail('Confirmation Date cannot be before the Date of Joining.', 'employment');
    }
    if ($id && $posted['reports_to_id'] === $id) {
        $fail('An employee cannot report to themselves.', 'employment');
    }
    foreach (['email' => 'Work Email', 'personal_email' => 'Personal Email'] as $f => $label) {
        if ($posted[$f] && !filter_var($posted[$f], FILTER_VALIDATE_EMAIL)) {
            $fail($label . ' is not a valid email address.', 'contact');
        }
    }
    if ($posted['pincode'] && !preg_match('/^[A-Za-z0-9 -]{3,12}$/', $posted['pincode'])) {
        $fail('Pincode is not valid.', 'contact');
    }
    if ($saveSalary) {
        if ($posted['pan_no'] && !preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $posted['pan_no'])) {
            $fail('PAN must look like ABCDE1234F.', 'salary');
        }
        if ($posted['aadhaar_no'] && !preg_match('/^[0-9]{12}$/', $posted['aadhaar_no'])) {
            $fail('Aadhaar Number must be 12 digits.', 'salary');
        }
        if ($posted['bank_ifsc'] && !preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $posted['bank_ifsc'])) {
            $fail('IFSC must look like HDFC0001234.', 'salary');
        }
        if ($posted['uan_no'] && !preg_match('/^[0-9]{12}$/', $posted['uan_no'])) {
            $fail('UAN must be 12 digits.', 'salary');
        }
        if ($posted['salary_mode'] === 'bank_transfer' && $posted['salary'] > 0 && (!$posted['bank_account_no'] || !$posted['bank_ifsc'])) {
            $fail('Bank Account No. and IFSC are required when Salary Mode is Bank Transfer.', 'salary');
        }
        foreach ($componentsToSave as $c) {
            if ($c['label'] === '') {
                $fail('Every salary component needs a name.', 'salary');
            }
        }
        if ($posted['monthly_deductions'] > $posted['monthly_gross']) {
            $fail('Monthly deductions cannot exceed monthly gross pay.', 'salary');
        }
    }
    foreach ($educationToSave as $e) {
        if ($e['qualification'] === '') {
            $fail('Every education row needs a Qualification.', 'qualifications');
        }
        if ($e['year_of_passing'] && ($e['year_of_passing'] < 1950 || $e['year_of_passing'] > (int)date('Y') + 5)) {
            $fail('Year of Passing ' . $e['year_of_passing'] . ' is not valid.', 'qualifications');
        }
    }
    foreach ($experienceToSave as $x) {
        if ($x['company'] === '') {
            $fail('Every work experience row needs a Company.', 'qualifications');
        }
        if ($x['from_date'] && $x['to_date'] && $x['to_date'] < $x['from_date']) {
            $fail('Work experience at ' . $x['company'] . ' ends before it starts.', 'qualifications');
        }
    }
    if ($posted['resignation_date'] && $posted['hire_date'] && $posted['resignation_date'] < $posted['hire_date']) {
        $fail('Resignation Date cannot be before the Date of Joining.', 'more');
    }
    if ($posted['relieving_date'] && $posted['resignation_date'] && $posted['relieving_date'] < $posted['resignation_date']) {
        $fail('Relieving Date cannot be before the Resignation Date.', 'more');
    }
    if ($posted['relieving_date'] && $posted['hire_date'] && $posted['relieving_date'] < $posted['hire_date']) {
        $fail('Relieving Date cannot be before the Date of Joining.', 'more');
    }
    if ($posted['status'] === 'left' && !$posted['relieving_date']) {
        $fail('Set a Relieving Date before marking the employee as Left.', 'more');
    }
    if ($posted['relieving_date'] && $posted['relieving_date'] <= today() && $posted['status'] === 'active') {
        $fail('This employee has been relieved; set Status to Left (Details tab) or clear the Relieving Date.', 'more');
    }

    if ($error === '') {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            if (!$id && $posted['employee_code'] === null) {
                $posted['employee_code'] = next_employee_code();
            }
            if ($posted['employee_code'] === null) {
                $posted['employee_code'] = $original['employee_code'];
            }
            $cols = array_keys($posted);
            if ($id) {
                $sql = 'UPDATE employees SET ' . implode(', ', array_map(fn($c) => "$c = ?", $cols)) . ' WHERE id = ?';
                $pdo->prepare($sql)->execute([...array_values($posted), $id]);
                $employeeId = $id;
            } else {
                $sql = 'INSERT INTO employees (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')';
                $pdo->prepare($sql)->execute(array_values($posted));
                $employeeId = (int)$pdo->lastInsertId();
            }

            if ($saveSalary) {
                $pdo->prepare('DELETE FROM employee_salary_components WHERE employee_id = ?')->execute([$employeeId]);
                $stmt = $pdo->prepare('INSERT INTO employee_salary_components (employee_id, component_type, label, amount, sort_order) VALUES (?,?,?,?,?)');
                foreach ($componentsToSave as $i => $c) {
                    $stmt->execute([$employeeId, $c['component_type'], $c['label'], $c['amount'], $i]);
                }
            }
            $pdo->prepare('DELETE FROM employee_education WHERE employee_id = ?')->execute([$employeeId]);
            $stmt = $pdo->prepare('INSERT INTO employee_education (employee_id, qualification, institute, year_of_passing, grade, sort_order) VALUES (?,?,?,?,?,?)');
            foreach ($educationToSave as $i => $e) {
                $stmt->execute([$employeeId, $e['qualification'], $e['institute'], $e['year_of_passing'], $e['grade'], $i]);
            }
            $pdo->prepare('DELETE FROM employee_experience WHERE employee_id = ?')->execute([$employeeId]);
            $stmt = $pdo->prepare('INSERT INTO employee_experience (employee_id, company, designation, from_date, to_date, last_salary, sort_order) VALUES (?,?,?,?,?,?,?)');
            foreach ($experienceToSave as $i => $x) {
                $stmt->execute([$employeeId, $x['company'], $x['designation'], $x['from_date'], $x['to_date'], $x['last_salary'], $i]);
            }

            if ($id) {
                $labels = [
                    'name' => 'Name', 'status' => 'Status', 'department_id' => 'Department', 'designation' => 'Designation',
                    'reports_to_id' => 'Reports To', 'employment_type' => 'Employment Type', 'hire_date' => 'Date of Joining',
                    'resignation_date' => 'Resignation Date', 'relieving_date' => 'Relieving Date',
                ];
                $old = $original;
                $new = $posted;
                // Log names rather than ids for the link fields.
                $deptNames = $pdo->query('SELECT id, name FROM departments')->fetchAll(PDO::FETCH_KEY_PAIR);
                $empNames = $pdo->query('SELECT id, name FROM employees')->fetchAll(PDO::FETCH_KEY_PAIR);
                foreach ([&$old, &$new] as &$side) {
                    $side['department_id'] = $deptNames[$side['department_id'] ?? 0] ?? null;
                    $side['reports_to_id'] = $empNames[$side['reports_to_id'] ?? 0] ?? null;
                    $side['status'] = $statuses[$side['status']] ?? $side['status'];
                    $side['employment_type'] = $employmentTypes[$side['employment_type']] ?? $side['employment_type'];
                }
                unset($side);
                if ($saveSalary) {
                    $labels += ['salary' => 'Basic Salary', 'annual_ctc' => 'Annual CTC'];
                    foreach (['salary', 'annual_ctc'] as $f) {
                        $old[$f] = number_format((float)$old[$f], 2, '.', '');
                        $new[$f] = number_format((float)$new[$f], 2, '.', '');
                    }
                }
                log_field_changes('employee', $employeeId, $old, $new, $labels);
            } else {
                log_activity('employee', $employeeId, 'created');
            }
            $pdo->commit();
            flash('success', $id ? 'Employee updated.' : 'Employee created.');
            redirect('/hr/employee_view.php?id=' . $employeeId);
        } catch (PDOException $e) {
            $pdo->rollBack();
            if (str_contains($e->getMessage(), 'Duplicate')) {
                $error = 'An employee with code ' . $posted['employee_code'] . ' already exists.';
                $errorTab = 'details';
            } else {
                $error = 'Could not save employee.' . (defined('APP_DEBUG') && APP_DEBUG ? ' DEBUG: ' . $e->getMessage() : '');
            }
        }
    }

    // Re-render with what was posted.
    $employee = array_merge($employee, $posted, ['id' => $id, 'employee_code' => $posted['employee_code'] ?? '']);
    if ($saveSalary) {
        $components = $componentsToSave;
    }
    $education = $educationToSave;
    $experience = $experienceToSave;
    if ($errorTab) {
        $activeTab = $errorTab;
    }
}

$departments = db()->query('SELECT id, name FROM departments ORDER BY name')->fetchAll();
$managerStmt = db()->prepare("SELECT id, name, employee_code FROM employees WHERE status = 'active' AND id <> ? ORDER BY name");
$managerStmt->execute([$id]);
$managers = $managerStmt->fetchAll();
$users = db()->query("SELECT id, name, email FROM users WHERE status='active' ORDER BY name")->fetchAll();
$designations = db()->query("SELECT DISTINCT designation FROM employees WHERE designation IS NOT NULL AND designation <> '' ORDER BY designation")->fetchAll(PDO::FETCH_COLUMN);

if (!$components) {
    $components = [['component_type' => 'earning', 'label' => '', 'amount' => '']];
}
if (!$education) {
    $education = [['qualification' => '', 'institute' => '', 'year_of_passing' => '', 'grade' => '']];
}
if (!$experience) {
    $experience = [['company' => '', 'designation' => '', 'from_date' => '', 'to_date' => '', 'last_salary' => '']];
}

$statusBadge = ['active' => 'success', 'inactive' => 'secondary', 'left' => 'dark'];
$sel = fn($a, $b) => (string)$a === (string)$b ? 'selected' : '';

$page_title = $id ? 'Edit Employee' : 'New Employee';
require __DIR__ . '/../includes/header.php';
?>
<div class="card p-4">
  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0"><?= $id ? e($employee['name']) . ' <span class="text-muted small">' . e($employee['employee_code']) . '</span>' : 'New Employee' ?> <span class="badge text-bg-<?= $statusBadge[$employee['status']] ?? 'secondary' ?> badge-status"><?= e($statuses[$employee['status']] ?? $employee['status']) ?></span></h5>
  </div>

  <ul class="nav nav-tabs mb-3">
    <li class="nav-item"><button class="nav-link <?= $activeTab === 'details' ? 'active' : '' ?>" id="tab-details" data-bs-toggle="tab" data-bs-target="#pane-details" type="button">Details</button></li>
    <li class="nav-item"><button class="nav-link <?= $activeTab === 'employment' ? 'active' : '' ?>" id="tab-employment" data-bs-toggle="tab" data-bs-target="#pane-employment" type="button">Employment</button></li>
    <li class="nav-item"><button class="nav-link <?= $activeTab === 'contact' ? 'active' : '' ?>" id="tab-contact" data-bs-toggle="tab" data-bs-target="#pane-contact" type="button">Contact &amp; Address</button></li>
    <?php if ($canManage): ?>
    <li class="nav-item"><button class="nav-link <?= $activeTab === 'salary' ? 'active' : '' ?>" id="tab-salary" data-bs-toggle="tab" data-bs-target="#pane-salary" type="button">Salary &amp; Bank</button></li>
    <?php endif; ?>
    <li class="nav-item"><button class="nav-link <?= $activeTab === 'qualifications' ? 'active' : '' ?>" id="tab-qualifications" data-bs-toggle="tab" data-bs-target="#pane-qualifications" type="button">Qualifications &amp; Experience</button></li>
    <li class="nav-item"><button class="nav-link <?= $activeTab === 'more' ? 'active' : '' ?>" id="tab-more" data-bs-toggle="tab" data-bs-target="#pane-more" type="button">Exit &amp; More Info</button></li>
  </ul>

  <form method="post" id="employeeForm">
    <?= csrf_field() ?>
    <div class="tab-content">
      <div class="tab-pane fade <?= $activeTab === 'details' ? 'show active' : '' ?>" id="pane-details">
        <h6 class="text-muted mb-3"><i class="fa-solid fa-id-card"></i> Personal Details</h6>
        <div class="row g-3 mb-3">
          <div class="col-sm-3">
            <label class="form-label">Employee Code</label>
            <input type="text" name="employee_code" class="form-control" maxlength="30" value="<?= e($employee['employee_code']) ?>" placeholder="Auto-generated if left blank">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Salutation</label>
            <select name="salutation" class="form-select">
              <option value="">—</option>
              <?php foreach ($salutations as $s): ?><option value="<?= e($s) ?>" <?= $sel($employee['salutation'], $s) ?>><?= e($s) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-3">
            <label class="form-label">Full Name <span class="text-danger">*</span></label>
            <input type="text" name="name" class="form-control" maxlength="150" required value="<?= e($employee['name']) ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Status</label>
            <select name="status" id="statusSelect" class="form-select">
              <?php foreach ($statuses as $k => $v): ?><option value="<?= $k ?>" <?= $sel($employee['status'], $k) ?>><?= e($v) ?></option><?php endforeach; ?>
            </select>
          </div>

          <div class="col-sm-3">
            <label class="form-label">Gender</label>
            <select name="gender" class="form-select">
              <option value="">— Select gender —</option>
              <?php foreach ($genders as $k => $v): ?><option value="<?= $k ?>" <?= $sel($employee['gender'], $k) ?>><?= e($v) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-3">
            <label class="form-label">Date of Birth</label>
            <input type="date" name="date_of_birth" id="dobInput" class="form-control" max="<?= today() ?>" value="<?= e($employee['date_of_birth'] ?? '') ?>">
            <div class="form-text" id="ageHint"></div>
          </div>
          <div class="col-sm-3">
            <label class="form-label">Marital Status</label>
            <select name="marital_status" class="form-select">
              <option value="">— Select —</option>
              <?php foreach ($maritalStatuses as $k => $v): ?><option value="<?= $k ?>" <?= $sel($employee['marital_status'], $k) ?>><?= e($v) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-3">
            <label class="form-label">Blood Group</label>
            <select name="blood_group" class="form-select">
              <option value="">— Select —</option>
              <?php foreach ($bloodGroups as $b): ?><option value="<?= e($b) ?>" <?= $sel($employee['blood_group'], $b) ?>><?= e($b) ?></option><?php endforeach; ?>
            </select>
          </div>

          <div class="col-sm-3">
            <label class="form-label">Father's / Spouse's Name</label>
            <input type="text" name="father_or_spouse_name" class="form-control" maxlength="150" value="<?= e($employee['father_or_spouse_name'] ?? '') ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Nationality</label>
            <input type="text" name="nationality" class="form-control" maxlength="60" value="<?= e($employee['nationality'] ?? '') ?>">
          </div>
        </div>
      </div>

      <div class="tab-pane fade <?= $activeTab === 'employment' ? 'show active' : '' ?>" id="pane-employment">
        <h6 class="text-muted mb-3"><i class="fa-solid fa-briefcase"></i> Role &amp; Reporting</h6>
        <div class="row g-3 mb-4">
          <div class="col-sm-3">
            <label class="form-label">Department</label>
            <select name="department_id" class="form-select">
              <option value="">— None —</option>
              <?php foreach ($departments as $d): ?><option value="<?= (int)$d['id'] ?>" <?= $sel($employee['department_id'], $d['id']) ?>><?= e($d['name']) ?></option><?php endforeach; ?>
            </select>
            <a href="<?= base_url('hr/department_form.php') ?>" class="small">+ New Department</a>
          </div>
          <div class="col-sm-3">
            <label class="form-label">Designation</label>
            <input type="text" name="designation" class="form-control" maxlength="120" list="designationList" value="<?= e($employee['designation'] ?? '') ?>">
            <datalist id="designationList"><?php foreach ($designations as $d): ?><option value="<?= e($d) ?>"><?php endforeach; ?></datalist>
          </div>
          <div class="col-sm-3">
            <label class="form-label">Reports To</label>
            <select name="reports_to_id" class="form-select">
              <option value="">— None —</option>
              <?php foreach ($managers as $m): ?><option value="<?= (int)$m['id'] ?>" <?= $sel($employee['reports_to_id'], $m['id']) ?>><?= e($m['name']) ?> (<?= e($m['employee_code']) ?>)</option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-3">
            <label class="form-label">Employment Type</label>
            <select name="employment_type" class="form-select">
              <?php foreach ($employmentTypes as $k => $v): ?><option value="<?= $k ?>" <?= $sel($employee['employment_type'], $k) ?>><?= e($v) ?></option><?php endforeach; ?>
            </select>
          </div>

          <div class="col-sm-3">
            <label class="form-label">Grade</label>
            <input type="text" name="grade" class="form-control" maxlength="40" placeholder="e.g. L2, Senior" value="<?= e($employee['grade'] ?? '') ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Work Location / Branch</label>
            <input type="text" name="work_location" class="form-control" maxlength="120" value="<?= e($employee['work_location'] ?? '') ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Work Shift</label>
            <select name="work_shift" class="form-select">
              <option value="">— Select shift —</option>
              <?php foreach ($workShifts as $s): ?><option value="<?= e($s) ?>" <?= $sel($employee['work_shift'], $s) ?>><?= e($s) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-3">
            <label class="form-label">Attendance Device ID</label>
            <input type="text" name="biometric_id" class="form-control" maxlength="40" placeholder="Biometric / punch ID" value="<?= e($employee['biometric_id'] ?? '') ?>">
          </div>
        </div>

        <h6 class="text-muted mb-3"><i class="fa-solid fa-calendar-check"></i> Dates &amp; Approvals</h6>
        <div class="row g-3 mb-3">
          <div class="col-sm-3">
            <label class="form-label">Date of Joining <span class="text-danger">*</span></label>
            <input type="date" name="hire_date" id="hireDateInput" class="form-control" required value="<?= e($employee['hire_date'] ?? '') ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Probation End Date</label>
            <input type="date" name="probation_end_date" id="probationInput" class="form-control" value="<?= e($employee['probation_end_date'] ?? '') ?>">
            <div class="small">
              <a href="#" class="js-probation" data-months="3">+3 months</a> &middot;
              <a href="#" class="js-probation" data-months="6">+6 months</a>
            </div>
          </div>
          <div class="col-sm-3">
            <label class="form-label">Confirmation Date</label>
            <input type="date" name="confirmation_date" class="form-control" value="<?= e($employee['confirmation_date'] ?? '') ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Notice Period (days)</label>
            <input type="number" name="notice_period_days" id="noticeInput" class="form-control" min="0" max="365" value="<?= (int)$employee['notice_period_days'] ?>">
          </div>

          <div class="col-sm-3">
            <label class="form-label">Leave Approver</label>
            <select name="leave_approver_id" class="form-select">
              <option value="">— Select user —</option>
              <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>" <?= $sel($employee['leave_approver_id'], $u['id']) ?>><?= e($u['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-3">
            <label class="form-label">Linked User Account</label>
            <select name="user_id" class="form-select">
              <option value="">— None —</option>
              <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>" <?= $sel($employee['user_id'], $u['id']) ?>><?= e($u['name']) ?> (<?= e($u['email']) ?>)</option><?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>

      <div class="tab-pane fade <?= $activeTab === 'contact' ? 'show active' : '' ?>" id="pane-contact">
        <h6 class="text-muted mb-3"><i class="fa-solid fa-address-book"></i> Contact</h6>
        <div class="row g-3 mb-4">
          <div class="col-sm-3">
            <label class="form-label">Work Email</label>
            <input type="email" name="email" class="form-control" maxlength="150" value="<?= e($employee['email'] ?? '') ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Personal Email</label>
            <input type="email" name="personal_email" class="form-control" maxlength="150" value="<?= e($employee['personal_email'] ?? '') ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Mobile</label>
            <input type="tel" name="phone" class="form-control" maxlength="40" value="<?= e($employee['phone'] ?? '') ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Alternate Phone</label>
            <input type="tel" name="alternate_phone" class="form-control" maxlength="40" value="<?= e($employee['alternate_phone'] ?? '') ?>">
          </div>
        </div>

        <h6 class="text-muted mb-3"><i class="fa-solid fa-location-dot"></i> Address</h6>
        <div class="row g-3 mb-4">
          <div class="col-sm-6">
            <label class="form-label">Current Address</label>
            <textarea name="current_address" id="currentAddress" class="form-control" rows="2" maxlength="255"><?= e($employee['current_address'] ?? '') ?></textarea>
          </div>
          <div class="col-sm-6">
            <label class="form-label d-flex justify-content-between">
              <span>Permanent Address</span>
              <span class="form-check form-check-inline m-0 small fw-normal">
                <input class="form-check-input" type="checkbox" name="same_as_current" id="sameAsCurrent" value="1" <?= !empty($employee['current_address']) && ($employee['current_address'] ?? '') === ($employee['permanent_address'] ?? '') ? 'checked' : '' ?>>
                <label class="form-check-label" for="sameAsCurrent">Same as current</label>
              </span>
            </label>
            <textarea name="permanent_address" id="permanentAddress" class="form-control" rows="2" maxlength="255"><?= e($employee['permanent_address'] ?? '') ?></textarea>
          </div>
          <div class="col-sm-3">
            <label class="form-label">City</label>
            <input type="text" name="city" class="form-control" maxlength="80" value="<?= e($employee['city'] ?? '') ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">State</label>
            <input type="text" name="state" class="form-control" maxlength="80" value="<?= e($employee['state'] ?? '') ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Pincode</label>
            <input type="text" name="pincode" class="form-control" maxlength="12" value="<?= e($employee['pincode'] ?? '') ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Country</label>
            <input type="text" name="country" class="form-control" maxlength="60" value="<?= e($employee['country'] ?? '') ?>">
          </div>
        </div>

        <h6 class="text-muted mb-3"><i class="fa-solid fa-phone-volume"></i> Emergency Contact</h6>
        <div class="row g-3 mb-3">
          <div class="col-sm-3">
            <label class="form-label">Contact Name</label>
            <input type="text" name="emergency_contact_name" class="form-control" maxlength="150" value="<?= e($employee['emergency_contact_name'] ?? '') ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Relation</label>
            <input type="text" name="emergency_contact_relation" class="form-control" maxlength="60" list="relationList" value="<?= e($employee['emergency_contact_relation'] ?? '') ?>">
            <datalist id="relationList"><option value="Father"><option value="Mother"><option value="Spouse"><option value="Sibling"><option value="Friend"></datalist>
          </div>
          <div class="col-sm-3">
            <label class="form-label">Contact Phone</label>
            <input type="tel" name="emergency_contact_phone" class="form-control" maxlength="40" value="<?= e($employee['emergency_contact_phone'] ?? '') ?>">
          </div>
        </div>
      </div>

      <?php if ($canManage): ?>
      <div class="tab-pane fade <?= $activeTab === 'salary' ? 'show active' : '' ?>" id="pane-salary">
        <h6 class="text-muted mb-3"><i class="fa-solid fa-money-check-dollar"></i> Salary Structure (monthly)</h6>
        <div class="row g-3 mb-3">
          <div class="col-sm-3">
            <label class="form-label">Basic Salary</label>
            <input type="number" step="0.01" min="0" name="salary" id="basicInput" class="form-control" value="<?= e($employee['salary']) ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Salary Mode</label>
            <select name="salary_mode" id="salaryModeSelect" class="form-select">
              <?php foreach ($salaryModes as $k => $v): ?><option value="<?= $k ?>" <?= $sel($employee['salary_mode'], $k) ?>><?= e($v) ?></option><?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="emp-rows mb-2" data-rows="components">
          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead><tr><th style="width:20%">Type</th><th>Component</th><th style="width:22%">Amount</th><th style="width:1%"></th></tr></thead>
              <tbody>
              <?php foreach ($components as $c): ?>
                <tr data-row>
                  <td><select name="comp_type[]" class="form-select form-select-sm"><option value="earning" <?= $sel($c['component_type'], 'earning') ?>>Earning</option><option value="deduction" <?= $sel($c['component_type'], 'deduction') ?>>Deduction</option></select></td>
                  <td><input type="text" name="comp_label[]" class="form-control form-control-sm" maxlength="100" list="componentList" placeholder="e.g. HRA, Provident Fund" value="<?= e($c['label']) ?>"></td>
                  <td><input type="number" step="0.01" min="0" name="comp_amount[]" class="form-control form-control-sm text-end" value="<?= e($c['amount']) ?>"></td>
                  <td><button type="button" class="btn btn-sm btn-outline-danger js-remove-row" title="Remove"><i class="fa-solid fa-xmark"></i></button></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <datalist id="componentList"><option value="HRA"><option value="Conveyance Allowance"><option value="Special Allowance"><option value="Medical Allowance"><option value="Provident Fund"><option value="ESI"><option value="Professional Tax"><option value="TDS"></datalist>
          <button type="button" class="btn btn-sm btn-outline-brand js-add-row">Add component</button>
        </div>

        <div class="card bg-light border-0 p-3 mb-4" style="max-width:380px">
          <div class="d-flex justify-content-between small mb-1"><span>Monthly Gross</span><strong id="grossOut">0.00</strong></div>
          <div class="d-flex justify-content-between small mb-1"><span>Monthly Deductions</span><strong id="dedOut">0.00</strong></div>
          <div class="d-flex justify-content-between small mb-1"><span>Monthly Net Pay</span><strong id="netOut">0.00</strong></div>
          <div class="d-flex justify-content-between fs-6 border-top pt-2 mt-1"><span>Annual CTC</span><strong id="ctcOut">0.00</strong></div>
          <div class="form-text">Generate Salary Slip prefills these components for this employee.</div>
        </div>

        <h6 class="text-muted mb-3"><i class="fa-solid fa-building-columns"></i> Bank &amp; Statutory Details</h6>
        <div class="row g-3 mb-3">
          <div class="col-sm-3">
            <label class="form-label">Bank Name</label>
            <input type="text" name="bank_name" class="form-control" maxlength="120" value="<?= e($employee['bank_name'] ?? '') ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Account Holder Name</label>
            <input type="text" name="bank_account_holder" id="accountHolderInput" class="form-control" maxlength="150" value="<?= e($employee['bank_account_holder'] ?? '') ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Account No. <span class="text-danger bank-req">*</span></label>
            <input type="text" name="bank_account_no" class="form-control" maxlength="40" inputmode="numeric" value="<?= e($employee['bank_account_no'] ?? '') ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">IFSC <span class="text-danger bank-req">*</span></label>
            <input type="text" name="bank_ifsc" class="form-control" maxlength="11" style="text-transform:uppercase" placeholder="e.g. HDFC0001234" value="<?= e($employee['bank_ifsc'] ?? '') ?>">
          </div>

          <div class="col-sm-3">
            <label class="form-label">PAN</label>
            <input type="text" name="pan_no" class="form-control" maxlength="10" style="text-transform:uppercase" placeholder="ABCDE1234F" value="<?= e($employee['pan_no'] ?? '') ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Aadhaar No.</label>
            <input type="text" name="aadhaar_no" class="form-control" maxlength="14" inputmode="numeric" value="<?= e($employee['aadhaar_no'] ?? '') ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">UAN</label>
            <input type="text" name="uan_no" class="form-control" maxlength="12" inputmode="numeric" value="<?= e($employee['uan_no'] ?? '') ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">PF No.</label>
            <input type="text" name="pf_no" class="form-control" maxlength="30" value="<?= e($employee['pf_no'] ?? '') ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">ESI No.</label>
            <input type="text" name="esi_no" class="form-control" maxlength="20" value="<?= e($employee['esi_no'] ?? '') ?>">
          </div>
        </div>
      </div>
      <?php endif; ?>

      <div class="tab-pane fade <?= $activeTab === 'qualifications' ? 'show active' : '' ?>" id="pane-qualifications">
        <h6 class="text-muted mb-3"><i class="fa-solid fa-graduation-cap"></i> Education</h6>
        <div class="emp-rows mb-4" data-rows="education">
          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead><tr><th style="width:28%">Qualification</th><th>Institute / University</th><th style="width:14%">Year of Passing</th><th style="width:14%">Grade / %</th><th style="width:1%"></th></tr></thead>
              <tbody>
              <?php foreach ($education as $ed): ?>
                <tr data-row>
                  <td><input type="text" name="edu_qualification[]" class="form-control form-control-sm" maxlength="120" list="qualificationList" value="<?= e($ed['qualification']) ?>"></td>
                  <td><input type="text" name="edu_institute[]" class="form-control form-control-sm" maxlength="150" value="<?= e($ed['institute'] ?? '') ?>"></td>
                  <td><input type="number" name="edu_year[]" class="form-control form-control-sm" min="1950" max="<?= (int)date('Y') + 5 ?>" value="<?= e($ed['year_of_passing'] ?? '') ?>"></td>
                  <td><input type="text" name="edu_grade[]" class="form-control form-control-sm" maxlength="20" value="<?= e($ed['grade'] ?? '') ?>"></td>
                  <td><button type="button" class="btn btn-sm btn-outline-danger js-remove-row" title="Remove"><i class="fa-solid fa-xmark"></i></button></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <datalist id="qualificationList"><option value="10th"><option value="12th"><option value="Diploma"><option value="B.Com"><option value="B.Sc"><option value="B.Tech / B.E."><option value="BBA"><option value="MBA"><option value="M.Tech"><option value="CA"></datalist>
          <button type="button" class="btn btn-sm btn-outline-brand js-add-row">Add qualification</button>
        </div>

        <h6 class="text-muted mb-3"><i class="fa-solid fa-building"></i> Previous Work Experience <span class="small fw-normal" id="expTotal"></span></h6>
        <div class="emp-rows mb-3" data-rows="experience">
          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead><tr><th style="width:24%">Company</th><th>Designation</th><th style="width:15%">From</th><th style="width:15%">To</th><th style="width:14%">Last Salary</th><th style="width:1%"></th></tr></thead>
              <tbody>
              <?php foreach ($experience as $x): ?>
                <tr data-row>
                  <td><input type="text" name="exp_company[]" class="form-control form-control-sm" maxlength="150" value="<?= e($x['company']) ?>"></td>
                  <td><input type="text" name="exp_designation[]" class="form-control form-control-sm" maxlength="120" value="<?= e($x['designation'] ?? '') ?>"></td>
                  <td><input type="date" name="exp_from[]" class="form-control form-control-sm" value="<?= e($x['from_date'] ?? '') ?>"></td>
                  <td><input type="date" name="exp_to[]" class="form-control form-control-sm" value="<?= e($x['to_date'] ?? '') ?>"></td>
                  <td><input type="number" step="0.01" min="0" name="exp_last_salary[]" class="form-control form-control-sm text-end" value="<?= e($x['last_salary'] ?? '') ?>"></td>
                  <td><button type="button" class="btn btn-sm btn-outline-danger js-remove-row" title="Remove"><i class="fa-solid fa-xmark"></i></button></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <button type="button" class="btn btn-sm btn-outline-brand js-add-row">Add experience</button>
        </div>
      </div>

      <div class="tab-pane fade <?= $activeTab === 'more' ? 'show active' : '' ?>" id="pane-more">
        <h6 class="text-muted mb-3"><i class="fa-solid fa-door-open"></i> Exit</h6>
        <div class="row g-3 mb-4">
          <div class="col-sm-3">
            <label class="form-label">Resignation Date</label>
            <input type="date" name="resignation_date" id="resignationInput" class="form-control" value="<?= e($employee['resignation_date'] ?? '') ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Relieving Date</label>
            <input type="date" name="relieving_date" id="relievingInput" class="form-control" value="<?= e($employee['relieving_date'] ?? '') ?>">
            <div class="form-text" id="relievingHint"></div>
          </div>
          <div class="col-sm-3">
            <label class="form-label">Reason for Leaving</label>
            <select name="exit_reason" class="form-select">
              <option value="">— Select reason —</option>
              <?php foreach ($exitReasons as $r): ?><option value="<?= e($r) ?>" <?= $sel($employee['exit_reason'], $r) ?>><?= e($r) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label">Exit Interview Notes</label>
            <textarea name="exit_notes" class="form-control" rows="2"><?= e($employee['exit_notes'] ?? '') ?></textarea>
          </div>
        </div>

        <h6 class="text-muted mb-3"><i class="fa-solid fa-circle-info"></i> More Info</h6>
        <div class="row g-3 mb-3">
          <div class="col-sm-6">
            <label class="form-label">Tags</label>
            <input type="text" name="tags" class="form-control" maxlength="255" placeholder="Comma separated" value="<?= e($employee['tags'] ?? '') ?>">
          </div>
          <div class="col-12">
            <label class="form-label">Internal Remarks</label>
            <textarea name="remarks" class="form-control" rows="3"><?= e($employee['remarks'] ?? '') ?></textarea>
          </div>
        </div>
      </div>
    </div>

    <div class="page-actions mt-3">
      <button type="submit" class="btn btn-brand">Save Employee</button>
      <a href="<?= $id ? 'employee_view.php?id=' . $id : 'employees.php' ?>" class="btn btn-outline-secondary">Cancel</a>
    </div>
  </form>
</div>

<?php
$extra_js_inline = "
(function () {
  var form = document.getElementById('employeeForm');

  // Repeating rows: add clones the last row blank, remove keeps one row.
  document.querySelectorAll('.emp-rows').forEach(function (wrap) {
    var tbody = wrap.querySelector('tbody');
    wrap.addEventListener('click', function (e) {
      if (e.target.closest('.js-add-row')) {
        var rows = tbody.querySelectorAll('tr[data-row]');
        var clone = rows[rows.length - 1].cloneNode(true);
        clone.querySelectorAll('input').forEach(function (i) { i.value = ''; });
        tbody.appendChild(clone);
        var first = clone.querySelector('input'); if (first) first.focus();
        return;
      }
      var rm = e.target.closest('.js-remove-row');
      if (rm) {
        var tr = rm.closest('tr[data-row]');
        if (tbody.querySelectorAll('tr[data-row]').length > 1) tr.remove();
        else tr.querySelectorAll('input').forEach(function (i) { i.value = ''; });
        recalc();
      }
    });
  });

  // Salary preview (the server recomputes on save).
  function recalc() {
    var basic = document.getElementById('basicInput');
    if (basic) {
      var gross = parseFloat(basic.value || 0), ded = 0;
      document.querySelectorAll('[data-rows=components] tr[data-row]').forEach(function (tr) {
        var amt = parseFloat(tr.querySelector('[name=\"comp_amount[]\"]').value || 0);
        if (tr.querySelector('[name=\"comp_type[]\"]').value === 'deduction') ded += amt; else gross += amt;
      });
      document.getElementById('grossOut').textContent = gross.toFixed(2);
      document.getElementById('dedOut').textContent = ded.toFixed(2);
      document.getElementById('netOut').textContent = (gross - ded).toFixed(2);
      document.getElementById('ctcOut').textContent = (gross * 12).toFixed(2);
      var mode = document.getElementById('salaryModeSelect').value;
      document.querySelectorAll('.bank-req').forEach(function (s) { s.style.display = mode === 'bank_transfer' ? '' : 'none'; });
    }
    // Total previous experience.
    var months = 0;
    document.querySelectorAll('[data-rows=experience] tr[data-row]').forEach(function (tr) {
      var f = tr.querySelector('[name=\"exp_from[]\"]').value, t = tr.querySelector('[name=\"exp_to[]\"]').value;
      if (f && t && t >= f) { var a = new Date(f), b = new Date(t); months += (b.getFullYear() - a.getFullYear()) * 12 + (b.getMonth() - a.getMonth()); }
    });
    document.getElementById('expTotal').textContent = months > 0 ? '(' + Math.floor(months / 12) + ' yr ' + (months % 12) + ' mo in total)' : '';
    // Age hint.
    var dob = document.getElementById('dobInput').value;
    var hint = document.getElementById('ageHint');
    if (dob) {
      var d = new Date(dob), now = new Date(), age = now.getFullYear() - d.getFullYear();
      if (now.getMonth() < d.getMonth() || (now.getMonth() === d.getMonth() && now.getDate() < d.getDate())) age--;
      hint.textContent = age >= 0 ? 'Age ' + age : '';
    } else hint.textContent = '';
    // Relieving date suggestion from resignation + notice period.
    var res = document.getElementById('resignationInput').value;
    var rh = document.getElementById('relievingHint');
    if (res) {
      var r = new Date(res); r.setDate(r.getDate() + parseInt(document.getElementById('noticeInput').value || 0, 10));
      rh.innerHTML = 'Per notice period: <a href=\"#\" id=\"useRelieving\">' + r.toISOString().slice(0, 10) + '</a>';
    } else rh.textContent = '';
  }
  form.addEventListener('input', recalc);
  form.addEventListener('change', recalc);

  document.getElementById('relievingHint').addEventListener('click', function (e) {
    if (e.target.id === 'useRelieving') { e.preventDefault(); document.getElementById('relievingInput').value = e.target.textContent; }
  });

  document.querySelectorAll('.js-probation').forEach(function (a) {
    a.addEventListener('click', function (e) {
      e.preventDefault();
      var h = document.getElementById('hireDateInput').value;
      if (!h) return;
      var d = new Date(h); d.setMonth(d.getMonth() + parseInt(a.dataset.months, 10));
      document.getElementById('probationInput').value = d.toISOString().slice(0, 10);
    });
  });

  // Permanent address mirrors current address while the box is ticked.
  var same = document.getElementById('sameAsCurrent'), cur = document.getElementById('currentAddress'), perm = document.getElementById('permanentAddress');
  function syncAddress() { if (same.checked) perm.value = cur.value; perm.readOnly = same.checked; }
  same.addEventListener('change', syncAddress);
  cur.addEventListener('input', syncAddress);
  syncAddress();

  // Account holder defaults to the employee's name.
  var holder = document.getElementById('accountHolderInput');
  if (holder) holder.addEventListener('focus', function () { if (!holder.value) holder.value = form.querySelector('[name=name]').value; });

  // Open the first tab holding an invalid field when the browser blocks submit.
  form.addEventListener('invalid', function (e) {
    var pane = e.target.closest('.tab-pane');
    if (pane && !pane.classList.contains('active')) {
      var btn = document.querySelector('[data-bs-target=\"#' + pane.id + '\"]');
      if (btn && window.bootstrap) bootstrap.Tab.getOrCreateInstance(btn).show();
    }
  }, true);

  recalc();
})();
";
require __DIR__ . '/../includes/footer.php';
