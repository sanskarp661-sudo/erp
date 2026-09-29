<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/hr.php';
require_login();

$canEdit = can_edit_module('hrms');
$me = current_user();
$id = (int)input('id');
$leave = [
    'id' => 0, 'application_no' => '', 'employee_id' => '', 'leave_type' => 'casual', 'start_date' => '', 'end_date' => '',
    'half_day' => 0, 'half_day_date' => '', 'total_days' => 0, 'reason' => '', 'contact_during_leave' => '',
    'handover_to_id' => '', 'leave_approver_id' => '', 'status' => 'pending', 'created_by' => $me['id'] ?? null,
];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM leaves WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        flash('danger', 'Leave application not found.');
        redirect('/hr/leaves.php');
    }
    if ($row['status'] !== 'pending') {
        flash('danger', 'Only pending leave applications can be edited.');
        redirect('/hr/leave_view.php?id=' . $id);
    }
    if (!$canEdit && (int)$row['created_by'] !== (int)($me['id'] ?? 0)) {
        flash('danger', 'You can only edit leave applications you submitted.');
        redirect('/hr/leave_view.php?id=' . $id);
    }
    $leave = array_merge($leave, $row);
}

$types = hr_leave_types();
if ($id && !isset($types[$leave['leave_type']])) {
    $types += array_intersect_key(hr_leave_types(false), [$leave['leave_type'] => 1]);
}
$error = '';
$tabs = ['details', 'approval', 'more'];
$activeTab = in_array(input('tab'), $tabs, true) ? input('tab') : 'details';

if (is_post()) {
    csrf_verify();
    $date = function (string $k): ?string {
        $v = trim((string)input($k));
        $d = DateTime::createFromFormat('Y-m-d', $v);
        return $d && $d->format('Y-m-d') === $v ? $v : null;
    };
    $leave = array_merge($leave, [
        'employee_id' => (int)input('employee_id'),
        'leave_type' => array_key_exists(input('leave_type'), $types) ? input('leave_type') : '',
        'start_date' => $date('start_date'),
        'end_date' => $date('end_date'),
        'half_day' => input('half_day') ? 1 : 0,
        'half_day_date' => $date('half_day_date'),
        'reason' => trim((string)input('reason')) ?: null,
        'contact_during_leave' => trim((string)input('contact_during_leave')) ?: null,
        'handover_to_id' => (int)input('handover_to_id') ?: null,
        'leave_approver_id' => (int)input('leave_approver_id') ?: null,
    ]);
    if ($leave['half_day'] && !$leave['half_day_date']) {
        $leave['half_day_date'] = $leave['start_date'];
    }
    if (!$leave['half_day']) {
        $leave['half_day_date'] = null;
    }

    $errorTab = null;
    $fail = function (string $msg, string $tab) use (&$error, &$errorTab) {
        if ($error === '') {
            $error = $msg;
            $errorTab = $tab;
        }
    };
    if (!$leave['employee_id']) {
        $fail('Please select an employee.', 'details');
    }
    if ($leave['leave_type'] === '') {
        $fail('Please select a leave type.', 'details');
    }
    if (!$leave['start_date'] || !$leave['end_date']) {
        $fail('From and To dates are required.', 'details');
    } elseif ($leave['end_date'] < $leave['start_date']) {
        $fail('To date cannot be before From date.', 'details');
    } elseif (substr($leave['start_date'], 0, 4) !== substr($leave['end_date'], 0, 4)) {
        $fail('A leave application cannot span two calendar years; split it into two.', 'details');
    }
    $type = $types[$leave['leave_type']] ?? null;
    if ($leave['half_day'] && $type && !$type['allow_half_day']) {
        $fail($type['name'] . ' cannot be taken as a half day.', 'details');
    }
    if ($leave['half_day'] && $leave['start_date'] && $leave['end_date']
        && ($leave['half_day_date'] < $leave['start_date'] || $leave['half_day_date'] > $leave['end_date'] || hr_is_weekly_off($leave['half_day_date']))) {
        $fail('Half Day Date must be a working day between From and To.', 'details');
    }

    if ($error === '') {
        $leave['total_days'] = hr_leave_days($leave['start_date'], $leave['end_date'], (bool)$leave['half_day']);
        if ($leave['total_days'] <= 0) {
            $fail('The selected dates fall only on Sundays, so there is nothing to apply for.', 'details');
        }
    }
    if ($error === '') {
        $overlap = db()->prepare("SELECT application_no, start_date, end_date FROM leaves
            WHERE employee_id = ? AND status IN ('pending','approved') AND id <> ? AND start_date <= ? AND end_date >= ? LIMIT 1");
        $overlap->execute([$leave['employee_id'], $id, $leave['end_date'], $leave['start_date']]);
        if ($o = $overlap->fetch()) {
            $fail('This overlaps ' . $o['application_no'] . ' (' . $o['start_date'] . ' to ' . $o['end_date'] . ').', 'details');
        }
    }
    if ($error === '') {
        $balance = hr_leave_balance($leave['employee_id'], $leave['leave_type'], (int)substr($leave['start_date'], 0, 4), $id);
        if ($balance !== null && $leave['total_days'] > $balance['available']) {
            $fail('Only ' . rtrim(rtrim(number_format($balance['available'], 1), '0'), '.') . ' day(s) of ' . $type['name'] . ' are available; this application needs ' . rtrim(rtrim(number_format($leave['total_days'], 1), '0'), '.') . '.', 'approval');
        }
    }
    if ($error === '') {
        $stmt = db()->prepare('SELECT hire_date, relieving_date FROM employees WHERE id = ?');
        $stmt->execute([$leave['employee_id']]);
        $emp = $stmt->fetch();
        if ($emp && $emp['hire_date'] && $leave['start_date'] < $emp['hire_date']) {
            $fail('Leave cannot start before the Date of Joining (' . $emp['hire_date'] . ').', 'details');
        } elseif ($emp && $emp['relieving_date'] && $leave['end_date'] > $emp['relieving_date']) {
            $fail('Leave cannot end after the Relieving Date (' . $emp['relieving_date'] . ').', 'details');
        }
    }
    if ($error === '' && $leave['handover_to_id'] === $leave['employee_id']) {
        $fail('Work cannot be handed over to the same employee.', 'approval');
    }

    if ($error === '') {
        // Default approver: the employee's own, else their department's.
        if (!$leave['leave_approver_id']) {
            $stmt = db()->prepare('SELECT COALESCE(e.leave_approver_id, d.default_leave_approver_id) FROM employees e LEFT JOIN departments d ON d.id = e.department_id WHERE e.id = ?');
            $stmt->execute([$leave['employee_id']]);
            $leave['leave_approver_id'] = (int)$stmt->fetchColumn() ?: null;
        }
        $fields = array_intersect_key($leave, array_flip(['employee_id', 'leave_type', 'start_date', 'end_date', 'half_day', 'half_day_date',
            'total_days', 'reason', 'contact_during_leave', 'handover_to_id', 'leave_approver_id']));
        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($id) {
                $pdo->prepare('UPDATE leaves SET ' . implode(', ', array_map(fn($c) => "$c = ?", array_keys($fields))) . ' WHERE id = ?')
                    ->execute([...array_values($fields), $id]);
                $leaveId = $id;
            } else {
                $fields += ['status' => 'pending', 'created_by' => $me['id'] ?? null];
                $pdo->prepare('INSERT INTO leaves (' . implode(', ', array_keys($fields)) . ') VALUES (' . implode(', ', array_fill(0, count($fields), '?')) . ')')
                    ->execute(array_values($fields));
                $leaveId = (int)$pdo->lastInsertId();
                $pdo->prepare('UPDATE leaves SET application_no = ? WHERE id = ?')->execute(['LV-' . str_pad((string)$leaveId, 6, '0', STR_PAD_LEFT), $leaveId]);
            }
            log_activity('leave', $leaveId, $id ? 'edited' : 'created');
            $pdo->commit();
            flash('success', $id ? 'Leave application updated.' : 'Leave application submitted for approval.');
            redirect('/hr/leave_view.php?id=' . $leaveId);
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = 'Could not save leave application.' . (defined('APP_DEBUG') && APP_DEBUG ? ' DEBUG: ' . $e->getMessage() : '');
        }
    }
    if ($errorTab) {
        $activeTab = $errorTab;
    }
}

$employees = db()->query("SELECT e.id, e.name, e.employee_code, e.leave_approver_id, d.default_leave_approver_id
    FROM employees e LEFT JOIN departments d ON d.id = e.department_id
    WHERE e.status = 'active' OR e.id = " . (int)$leave['employee_id'] . ' ORDER BY e.name')->fetchAll();
$approverDefaults = [];
foreach ($employees as $emp) {
    $approverDefaults[(int)$emp['id']] = (int)($emp['leave_approver_id'] ?: $emp['default_leave_approver_id']) ?: null;
}
$users = db()->query("SELECT id, name FROM users WHERE status='active' ORDER BY name")->fetchAll();
$typeMeta = [];
foreach ($types as $code => $t) {
    $typeMeta[$code] = ['name' => $t['name'], 'paid' => (bool)$t['is_paid'], 'half' => (bool)$t['allow_half_day']];
}
$fmt = fn($v) => rtrim(rtrim(number_format((float)$v, 1, '.', ''), '0'), '.');

$page_title = $id ? 'Edit Leave Application' : 'New Leave Application';
require __DIR__ . '/../includes/header.php';
?>
<div class="card p-4">
  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0"><?= $id ? e($leave['application_no']) : 'New Leave Application' ?> <span class="badge text-bg-warning badge-status">pending</span></h5>
  </div>

  <ul class="nav nav-tabs mb-3">
    <li class="nav-item"><button class="nav-link <?= $activeTab === 'details' ? 'active' : '' ?>" id="tab-details" data-bs-toggle="tab" data-bs-target="#pane-details" type="button">Details</button></li>
    <li class="nav-item"><button class="nav-link <?= $activeTab === 'approval' ? 'active' : '' ?>" id="tab-approval" data-bs-toggle="tab" data-bs-target="#pane-approval" type="button">Balance &amp; Approval</button></li>
    <li class="nav-item"><button class="nav-link <?= $activeTab === 'more' ? 'active' : '' ?>" id="tab-more" data-bs-toggle="tab" data-bs-target="#pane-more" type="button">More Info</button></li>
  </ul>

  <form method="post" id="leaveForm">
    <?= csrf_field() ?>
    <div class="tab-content">
      <div class="tab-pane fade <?= $activeTab === 'details' ? 'show active' : '' ?>" id="pane-details">
        <h6 class="text-muted mb-3"><i class="fa-solid fa-plane-departure"></i> Leave Details</h6>
        <div class="row g-3 mb-3">
          <div class="col-sm-3">
            <label class="form-label">Employee <span class="text-danger">*</span></label>
            <select name="employee_id" id="empSelect" class="form-select" required>
              <option value="">— Select employee —</option>
              <?php foreach ($employees as $emp): ?>
                <option value="<?= (int)$emp['id'] ?>" <?= (string)$leave['employee_id'] === (string)$emp['id'] ? 'selected' : '' ?>><?= e($emp['name']) ?> (<?= e($emp['employee_code']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-3">
            <label class="form-label">Leave Type <span class="text-danger">*</span></label>
            <select name="leave_type" id="typeSelect" class="form-select" required>
              <?php foreach ($types as $code => $t): ?>
                <option value="<?= e($code) ?>" <?= $leave['leave_type'] === $code ? 'selected' : '' ?>><?= e($t['name']) ?><?= $t['is_paid'] ? '' : ' (unpaid)' ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-text" id="typeBalance"></div>
          </div>
          <div class="col-sm-3">
            <label class="form-label">From <span class="text-danger">*</span></label>
            <input type="date" name="start_date" id="startDate" class="form-control" required value="<?= e($leave['start_date'] ?? '') ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">To <span class="text-danger">*</span></label>
            <input type="date" name="end_date" id="endDate" class="form-control" required value="<?= e($leave['end_date'] ?? '') ?>">
          </div>

          <div class="col-sm-3 d-flex align-items-end">
            <div class="form-check mb-2">
              <input class="form-check-input" type="checkbox" name="half_day" id="halfDay" value="1" <?= $leave['half_day'] ? 'checked' : '' ?>>
              <label class="form-check-label" for="halfDay">Half day</label>
            </div>
          </div>
          <div class="col-sm-3" id="halfDayDateWrap">
            <label class="form-label">Half Day Date</label>
            <input type="date" name="half_day_date" id="halfDayDate" class="form-control" value="<?= e($leave['half_day_date'] ?? '') ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Total Leave Days</label>
            <input type="text" id="totalDaysOut" class="form-control" disabled>
            <div class="form-text">Sundays are not counted.</div>
          </div>
          <div class="col-sm-3">
            <label class="form-label">Application No.</label>
            <input type="text" class="form-control" value="<?= $id ? e($leave['application_no']) : 'Auto-generated' ?>" disabled>
          </div>
          <div class="col-12">
            <label class="form-label">Reason</label>
            <textarea name="reason" class="form-control" rows="2" maxlength="255"><?= e($leave['reason'] ?? '') ?></textarea>
          </div>
        </div>
      </div>

      <div class="tab-pane fade <?= $activeTab === 'approval' ? 'show active' : '' ?>" id="pane-approval">
        <h6 class="text-muted mb-3"><i class="fa-solid fa-scale-balanced"></i> Leave Balance <span class="small fw-normal" id="balanceYear"></span></h6>
        <div class="table-responsive mb-4" style="max-width:720px">
          <table class="table table-sm">
            <thead><tr><th>Leave Type</th><th class="text-end">Allocated</th><th class="text-end">Taken</th><th class="text-end">Pending</th><th class="text-end">Available</th></tr></thead>
            <tbody id="balanceBody"><tr><td colspan="5" class="text-muted small">Select an employee to see balances.</td></tr></tbody>
          </table>
        </div>

        <h6 class="text-muted mb-3"><i class="fa-solid fa-user-check"></i> Approval</h6>
        <div class="row g-3 mb-3">
          <div class="col-sm-3">
            <label class="form-label">Leave Approver</label>
            <select name="leave_approver_id" id="approverSelect" class="form-select">
              <option value="">— Default (HR managers) —</option>
              <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>" <?= (string)$leave['leave_approver_id'] === (string)$u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?>
            </select>
            <div class="form-text">Defaults to the employee's or department's approver.</div>
          </div>
          <div class="col-sm-3">
            <label class="form-label">Work Handed Over To</label>
            <select name="handover_to_id" class="form-select">
              <option value="">— None —</option>
              <?php foreach ($employees as $emp): ?><option value="<?= (int)$emp['id'] ?>" <?= (string)$leave['handover_to_id'] === (string)$emp['id'] ? 'selected' : '' ?>><?= e($emp['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>

      <div class="tab-pane fade <?= $activeTab === 'more' ? 'show active' : '' ?>" id="pane-more">
        <div class="row g-3 mb-3">
          <div class="col-sm-6">
            <label class="form-label">Contact During Leave</label>
            <input type="text" name="contact_during_leave" class="form-control" maxlength="120" placeholder="Phone or email" value="<?= e($leave['contact_during_leave'] ?? '') ?>">
          </div>
        </div>
      </div>
    </div>

    <div class="page-actions mt-3">
      <button type="submit" class="btn btn-brand"><?= $id ? 'Save Leave Application' : 'Submit for Approval' ?></button>
      <a href="<?= $id ? 'leave_view.php?id=' . $id : 'leaves.php' ?>" class="btn btn-outline-secondary">Cancel</a>
    </div>
  </form>
</div>
<?php
$extra_js_inline = "
var typeMeta = " . json_encode((object)$typeMeta) . ";
var approverDefaults = " . json_encode((object)$approverDefaults) . ";
var balanceUrl = " . json_encode(base_url('hr/leave_balance.php')) . ";
var editingId = " . (int)$id . ";
var balances = {};
function fmt(v) { return (Math.round(v * 2) / 2).toString(); }
function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
function el(id) { return document.getElementById(id); }

function leaveDays() {
  var s = el('startDate').value, e = el('endDate').value;
  if (!s || !e || e < s) return 0;
  var days = 0;
  for (var d = new Date(s + 'T00:00:00'), last = new Date(e + 'T00:00:00'); d <= last; d.setDate(d.getDate() + 1)) {
    if (d.getDay() !== 0) days++;
  }
  return el('halfDay').checked && days > 0 ? days - 0.5 : days;
}

function render() {
  var days = leaveDays();
  el('totalDaysOut').value = el('startDate').value && el('endDate').value ? fmt(days) : '';
  var type = typeMeta[el('typeSelect').value] || {};
  el('halfDay').disabled = type.half === false;
  if (type.half === false) el('halfDay').checked = false;
  el('halfDayDateWrap').style.display = el('halfDay').checked ? '' : 'none';
  if (el('halfDay').checked && !el('halfDayDate').value) el('halfDayDate').value = el('startDate').value;
  var b = balances[el('typeSelect').value];
  var hint = el('typeBalance');
  if (!type.paid) { hint.textContent = 'Unpaid: counted as loss of pay in payroll.'; hint.className = 'form-text'; }
  else if (b) {
    hint.textContent = fmt(b.available) + ' day(s) available' + (days > b.available ? ', not enough for ' + fmt(days) : '');
    hint.className = 'form-text' + (days > b.available ? ' text-danger' : '');
  } else hint.textContent = '';
}

function loadBalances() {
  var emp = el('empSelect').value;
  var year = (el('startDate').value || new Date().toISOString()).slice(0, 4);
  el('balanceYear').textContent = '(' + year + ')';
  if (!emp) { balances = {}; render(); return; }
  fetch(balanceUrl + '?employee=' + emp + '&year=' + year + '&exclude=' + editingId, {credentials: 'same-origin'})
    .then(function (r) { return r.json(); })
    .then(function (data) {
      balances = data;
      var rows = Object.keys(typeMeta).map(function (code) {
        var b = data[code], t = typeMeta[code];
        if (!b) return '<tr><td>' + esc(t.name) + '</td><td class=\"text-end text-muted\" colspan=\"4\">Unpaid, no limit</td></tr>';
        return '<tr><td>' + esc(t.name) + '</td><td class=\"text-end\">' + fmt(b.allocated) + '</td><td class=\"text-end\">' + fmt(b.taken) + '</td><td class=\"text-end\">' + fmt(b.pending) + '</td><td class=\"text-end fw-bold' + (b.available <= 0 ? ' text-danger' : '') + '\">' + fmt(b.available) + '</td></tr>';
      });
      el('balanceBody').innerHTML = rows.join('');
      render();
    });
}

el('empSelect').addEventListener('change', function () {
  var def = approverDefaults[this.value];
  if (def && !el('approverSelect').value) el('approverSelect').value = def;
  loadBalances();
});
el('startDate').addEventListener('change', function () {
  if (!el('endDate').value || el('endDate').value < this.value) el('endDate').value = this.value;
  loadBalances();
});
el('leaveForm').addEventListener('input', render);
el('leaveForm').addEventListener('change', render);
loadBalances();
render();
";
require __DIR__ . '/../includes/footer.php';
