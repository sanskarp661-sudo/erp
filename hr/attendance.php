<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/hr.php';
require_module_edit('hrms');

$validDate = fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) ? $v : null;
$date = $validDate(input('date')) ?? today();
$month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)input('month')) ? input('month') : substr($date, 0, 7);
$departmentFilter = (int)input('department');
$activeTab = input('tab') === 'summary' ? 'summary' : 'daily';
$statusLabels = ['present' => 'Present', 'absent' => 'Absent', 'half_day' => 'Half Day', 'leave' => 'Leave'];

if (is_post()) {
    csrf_verify();
    $postedDate = $validDate(input('date'));
    if (!$postedDate) {
        flash('danger', 'Invalid date.');
        redirect('/hr/attendance.php');
    }
    if ($postedDate > today()) {
        flash('danger', 'Attendance cannot be marked for a future date.');
        redirect('/hr/attendance.php?date=' . urlencode($postedDate));
    }
    // Days covered by an approved leave are managed by that leave, not here.
    $onLeave = db()->prepare('SELECT employee_id FROM attendance WHERE attendance_date = ? AND leave_id IS NOT NULL');
    $onLeave->execute([$postedDate]);
    $locked = array_flip($onLeave->fetchAll(PDO::FETCH_COLUMN));

    $stmt = db()->prepare('
      INSERT INTO attendance (employee_id, attendance_date, status, shift, check_in, check_out, late_entry, early_exit, working_hours, notes, marked_by)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
      ON DUPLICATE KEY UPDATE status = VALUES(status), shift = VALUES(shift), check_in = VALUES(check_in), check_out = VALUES(check_out),
        late_entry = VALUES(late_entry), early_exit = VALUES(early_exit), working_hours = VALUES(working_hours), notes = VALUES(notes), marked_by = VALUES(marked_by)
    ');
    $saved = 0;
    $errors = [];
    foreach ($_POST['status'] ?? [] as $empId => $status) {
        $empId = (int)$empId;
        if (isset($locked[$empId]) || !isset($statusLabels[$status])) {
            continue;
        }
        $shift = array_key_exists($_POST['shift'][$empId] ?? '', HR_SHIFTS) ? $_POST['shift'][$empId] : null;
        $worked = $status === 'present' || $status === 'half_day';
        $time = fn($v) => preg_match('/^\d{2}:\d{2}(:\d{2})?$/', trim((string)$v)) ? substr(trim($v), 0, 5) : null;
        $ci = $worked ? $time($_POST['check_in'][$empId] ?? '') : null;
        $co = $worked ? $time($_POST['check_out'][$empId] ?? '') : null;
        $flags = $worked ? hr_attendance_flags($shift, $ci, $co) : ['late_entry' => 0, 'early_exit' => 0, 'working_hours' => null];
        $notes = trim((string)($_POST['notes'][$empId] ?? '')) ?: null;
        $stmt->execute([$empId, $postedDate, $status, $shift, $ci, $co, $flags['late_entry'], $flags['early_exit'], $flags['working_hours'], $notes ? mb_substr($notes, 0, 255) : null, current_user()['id'] ?? null]);
        $saved++;
    }
    flash('success', 'Attendance saved for ' . $saved . ' employee(s) on ' . date('D, M j, Y', strtotime($postedDate)) . '.');
    redirect('/hr/attendance.php?date=' . urlencode($postedDate) . ($departmentFilter ? '&department=' . $departmentFilter : ''));
}

$departments = db()->query('SELECT id, name FROM departments ORDER BY name')->fetchAll();
$deptSql = $departmentFilter ? ' AND e.department_id = ' . $departmentFilter : '';

// ---- Daily sheet ----
// Active employees who had joined by this date and not yet been relieved.
$stmt = db()->prepare("SELECT e.id, e.employee_code, e.name, e.work_shift, d.name department_name
    FROM employees e LEFT JOIN departments d ON d.id = e.department_id
    WHERE e.status <> 'left' AND (e.hire_date IS NULL OR e.hire_date <= ?) AND (e.relieving_date IS NULL OR e.relieving_date >= ?) $deptSql
    ORDER BY e.name");
$stmt->execute([$date, $date]);
$employees = $stmt->fetchAll();

$existing = [];
$stmt = db()->prepare('SELECT a.*, l.application_no FROM attendance a LEFT JOIN leaves l ON l.id = a.leave_id WHERE a.attendance_date = ?');
$stmt->execute([$date]);
foreach ($stmt->fetchAll() as $row) {
    $existing[$row['employee_id']] = $row;
}
$isSunday = hr_is_weekly_off($date);
$isFuture = $date > today();

// ---- Monthly summary ----
$monthStart = $month . '-01';
$monthEnd = date('Y-m-t', strtotime($monthStart));
$monthDates = hr_date_range($monthStart, $monthEnd);
$stmt = db()->prepare("SELECT e.id, e.employee_code, e.name FROM employees e
    WHERE (e.status <> 'left' OR e.relieving_date >= ?) AND (e.hire_date IS NULL OR e.hire_date <= ?) $deptSql ORDER BY e.name");
$stmt->execute([$monthStart, $monthEnd]);
$summaryEmployees = $stmt->fetchAll();
$grid = [];
$stmt = db()->prepare('SELECT employee_id, attendance_date, status, late_entry, working_hours FROM attendance WHERE attendance_date BETWEEN ? AND ?');
$stmt->execute([$monthStart, $monthEnd]);
foreach ($stmt as $r) {
    $grid[$r['employee_id']][$r['attendance_date']] = $r;
}
$codes = ['present' => ['P', 'success'], 'absent' => ['A', 'danger'], 'half_day' => ['H', 'warning'], 'leave' => ['L', 'info']];

$page_title = 'Attendance';
require __DIR__ . '/../includes/header.php';
?>
<div class="card p-4">
  <ul class="nav nav-tabs mb-3">
    <li class="nav-item"><button class="nav-link <?= $activeTab === 'daily' ? 'active' : '' ?>" id="tab-daily" data-bs-toggle="tab" data-bs-target="#pane-daily" type="button">Daily Attendance</button></li>
    <li class="nav-item"><button class="nav-link <?= $activeTab === 'summary' ? 'active' : '' ?>" id="tab-summary" data-bs-toggle="tab" data-bs-target="#pane-summary" type="button">Monthly Summary</button></li>
  </ul>
  <div class="tab-content">
    <div class="tab-pane fade <?= $activeTab === 'daily' ? 'show active' : '' ?>" id="pane-daily">
      <form method="get" class="row g-3 mb-3 align-items-end">
        <div class="col-sm-3">
          <label class="form-label">Date</label>
          <input type="date" name="date" class="form-control" max="<?= today() ?>" value="<?= e($date) ?>" onchange="this.form.submit()">
        </div>
        <div class="col-sm-3">
          <label class="form-label">Department</label>
          <select name="department" class="form-select" onchange="this.form.submit()">
            <option value="">All departments</option>
            <?php foreach ($departments as $d): ?><option value="<?= (int)$d['id'] ?>" <?= $departmentFilter === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-sm-6 text-sm-end">
          <a href="?date=<?= e(date('Y-m-d', strtotime($date . ' -1 day'))) ?>&department=<?= $departmentFilter ?: '' ?>" class="btn btn-outline-secondary btn-sm">&larr; Previous day</a>
          <?php if ($date < today()): ?><a href="?date=<?= e(date('Y-m-d', strtotime($date . ' +1 day'))) ?>&department=<?= $departmentFilter ?: '' ?>" class="btn btn-outline-secondary btn-sm">Next day &rarr;</a><?php endif; ?>
        </div>
      </form>
      <?php if ($isSunday): ?><div class="alert alert-info py-2">This is a Sunday (weekly off). Mark attendance only for people who worked.</div><?php endif; ?>
      <?php if ($isFuture): ?><div class="alert alert-warning py-2">Attendance cannot be marked for a future date.</div><?php endif; ?>

      <form method="post" id="attForm">
        <?= csrf_field() ?>
        <input type="hidden" name="date" value="<?= e($date) ?>">
        <div class="d-flex gap-2 mb-2 flex-wrap">
          <button type="button" class="btn btn-sm btn-outline-success js-mark-all" data-status="present">Mark all Present</button>
          <button type="button" class="btn btn-sm btn-outline-danger js-mark-all" data-status="absent">Mark all Absent</button>
          <span class="small text-muted align-self-center ms-2" id="countsOut"></span>
        </div>
        <div class="table-responsive">
          <table class="table table-sm align-middle" id="attTable">
            <thead><tr><th>Employee</th><th style="width:12%">Shift</th><th style="width:12%">Status</th><th style="width:11%">Check In</th><th style="width:11%">Check Out</th><th style="width:9%" class="text-end">Hours</th><th style="width:10%"></th><th>Notes</th></tr></thead>
            <tbody>
            <?php foreach ($employees as $emp):
                $eid = (int)$emp['id'];
                $rec = $existing[$eid] ?? ['status' => $isSunday ? '' : 'present', 'shift' => $emp['work_shift'], 'check_in' => '', 'check_out' => '', 'notes' => '', 'late_entry' => 0, 'early_exit' => 0, 'working_hours' => null, 'leave_id' => null];
                $lockedRow = !empty($rec['leave_id']);
                $shift = $rec['shift'] ?: ($emp['work_shift'] ?: 'General');
            ?>
              <tr data-row data-emp="<?= $eid ?>">
                <td><a href="employee_view.php?id=<?= $eid ?>"><?= e($emp['name']) ?></a> <div class="small text-muted"><?= e($emp['employee_code']) ?><?= $emp['department_name'] ? ' &middot; ' . e($emp['department_name']) : '' ?></div></td>
                <?php if ($lockedRow): ?>
                  <td colspan="6"><span class="badge text-bg-info"><?= e($statusLabels[$rec['status']] ?? $rec['status']) ?></span> <span class="small text-muted">from approved leave <a href="leave_view.php?id=<?= (int)$rec['leave_id'] ?>"><?= e($rec['application_no']) ?></a></span></td>
                  <td></td>
                <?php else: ?>
                <td>
                  <select name="shift[<?= $eid ?>]" class="form-select form-select-sm js-shift">
                    <?php foreach (HR_SHIFTS as $name => [$s, $en]): ?><option value="<?= e($name) ?>" title="<?= $s ?>–<?= $en ?>" data-start="<?= $s ?>" data-end="<?= $en ?>" <?= $shift === $name ? 'selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?>
                  </select>
                </td>
                <td>
                  <select name="status[<?= $eid ?>]" class="form-select form-select-sm js-status" <?= $isFuture ? 'disabled' : '' ?>>
                    <?php if ($rec['status'] === ''): ?><option value="">— Not marked —</option><?php endif; ?>
                    <?php foreach ($statusLabels as $val => $label): ?><option value="<?= $val ?>" <?= $rec['status'] === $val ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
                  </select>
                </td>
                <td><input type="time" name="check_in[<?= $eid ?>]" class="form-control form-control-sm js-in" value="<?= e(substr((string)$rec['check_in'], 0, 5)) ?>"></td>
                <td><input type="time" name="check_out[<?= $eid ?>]" class="form-control form-control-sm js-out" value="<?= e(substr((string)$rec['check_out'], 0, 5)) ?>"></td>
                <td class="text-end small js-hours"></td>
                <td class="small js-flags"></td>
                <td><input type="text" name="notes[<?= $eid ?>]" class="form-control form-control-sm" maxlength="255" value="<?= e($rec['notes'] ?? '') ?>"></td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
            <?php if (!$employees): ?><tr><td colspan="8" class="text-muted text-center">No employees on the rolls for this date.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
        <div class="small text-muted mb-2">Late entry / early exit use a <?= HR_GRACE_MINUTES ?>-minute grace period. Days on approved leave are set from the leave application.</div>
        <?php if ($employees && !$isFuture): ?><button type="submit" class="btn btn-brand">Save Attendance</button><?php endif; ?>
      </form>
    </div>

    <div class="tab-pane fade <?= $activeTab === 'summary' ? 'show active' : '' ?>" id="pane-summary">
      <form method="get" class="row g-3 mb-3 align-items-end">
        <input type="hidden" name="tab" value="summary">
        <input type="hidden" name="date" value="<?= e($date) ?>">
        <div class="col-sm-3">
          <label class="form-label">Month</label>
          <input type="month" name="month" class="form-control" value="<?= e($month) ?>" onchange="this.form.submit()">
        </div>
        <div class="col-sm-3">
          <label class="form-label">Department</label>
          <select name="department" class="form-select" onchange="this.form.submit()">
            <option value="">All departments</option>
            <?php foreach ($departments as $d): ?><option value="<?= (int)$d['id'] ?>" <?= $departmentFilter === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-sm-6 small text-muted">
          <?php foreach ($codes as $st => [$c, $tone]): ?><span class="badge text-bg-<?= $tone ?> me-1"><?= $c ?></span><?= $statusLabels[$st] ?> &nbsp;<?php endforeach; ?>
          <span class="text-danger">•</span> late entry
        </div>
      </form>
      <div class="table-responsive">
        <table class="table table-sm table-bordered text-center small" style="font-size:.75rem">
          <thead>
            <tr>
              <th class="text-start">Employee</th>
              <?php foreach ($monthDates as $d): ?><th class="<?= hr_is_weekly_off($d) ? 'table-light text-muted' : '' ?>" style="min-width:26px"><?= (int)substr($d, 8) ?><div class="fw-normal"><?= substr(date('D', strtotime($d)), 0, 2) ?></div></th><?php endforeach; ?>
              <th>P</th><th>H</th><th>L</th><th>A</th><th>Late</th><th>Hours</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($summaryEmployees as $emp): $t = ['present' => 0, 'half_day' => 0, 'leave' => 0, 'absent' => 0, 'late' => 0, 'hours' => 0.0]; ?>
            <tr>
              <td class="text-start text-nowrap"><a href="employee_view.php?id=<?= (int)$emp['id'] ?>"><?= e($emp['name']) ?></a></td>
              <?php foreach ($monthDates as $d):
                  $r = $grid[$emp['id']][$d] ?? null;
                  if ($r) {
                      $t[$r['status']]++;
                      $t['late'] += (int)$r['late_entry'];
                      $t['hours'] += (float)$r['working_hours'];
                  }
              ?>
                <td class="<?= hr_is_weekly_off($d) ? 'table-light' : '' ?>">
                  <?php if ($r): [$c, $tone] = $codes[$r['status']]; ?><a href="?date=<?= e($d) ?>" class="text-decoration-none text-<?= $tone ?> fw-bold"><?= $c ?></a><?= $r['late_entry'] ? '<span class="text-danger">•</span>' : '' ?><?php else: ?><span class="text-muted">–</span><?php endif; ?>
                </td>
              <?php endforeach; ?>
              <td class="fw-bold"><?= $t['present'] ?></td><td><?= $t['half_day'] ?></td><td><?= $t['leave'] ?></td><td class="<?= $t['absent'] ? 'text-danger' : '' ?>"><?= $t['absent'] ?></td><td><?= $t['late'] ?></td><td><?= $t['hours'] ? number_format($t['hours'], 1) : '' ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$summaryEmployees): ?><tr><td colspan="<?= count($monthDates) + 7 ?>" class="text-muted">No employees for this month.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php
$extra_js_inline = "
(function () {
  var grace = " . HR_GRACE_MINUTES . ";
  function mins(t) { return t ? parseInt(t.slice(0, 2), 10) * 60 + parseInt(t.slice(3, 5), 10) : null; }
  // Same rules as hr_attendance_flags() on the server, which recomputes on save.
  function refreshRow(tr) {
    var status = tr.querySelector('.js-status'); if (!status) return;
    var worked = status.value === 'present' || status.value === 'half_day';
    var ci = tr.querySelector('.js-in'), co = tr.querySelector('.js-out');
    ci.disabled = co.disabled = !worked;
    var opt = tr.querySelector('.js-shift').selectedOptions[0];
    var s = mins(opt.dataset.start), e = mins(opt.dataset.end), overnight = e <= s;
    var inM = mins(ci.value), outM = mins(co.value), flags = [];
    if (worked && inM !== null) { var i2 = overnight && inM < s - 240 ? inM + 1440 : inM; if (i2 > s + grace) flags.push('<span class=\"badge text-bg-danger\">Late</span>'); }
    if (worked && outM !== null) { var o2 = overnight && outM < s ? outM + 1440 : outM; if (o2 < (overnight ? e + 1440 : e) - grace) flags.push('<span class=\"badge text-bg-warning\">Early</span>'); }
    tr.querySelector('.js-flags').innerHTML = flags.join(' ');
    var h = '';
    if (worked && inM !== null && outM !== null) { var d = outM - inM; if (d < 0) d += 1440; h = (d / 60).toFixed(2); }
    tr.querySelector('.js-hours').textContent = h;
  }
  function counts() {
    var c = {present: 0, absent: 0, half_day: 0, leave: 0};
    document.querySelectorAll('.js-status').forEach(function (s) { if (c[s.value] !== undefined) c[s.value]++; });
    var el = document.getElementById('countsOut');
    if (el) el.textContent = c.present + ' present, ' + c.half_day + ' half day, ' + c.leave + ' on leave, ' + c.absent + ' absent';
  }
  var form = document.getElementById('attForm');
  form.addEventListener('input', function (e) { var tr = e.target.closest('tr[data-row]'); if (tr) refreshRow(tr); counts(); });
  form.addEventListener('change', function (e) { var tr = e.target.closest('tr[data-row]'); if (tr) refreshRow(tr); counts(); });
  document.querySelectorAll('.js-mark-all').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('.js-status').forEach(function (s) { if (!s.disabled) s.value = b.dataset.status; });
      document.querySelectorAll('tr[data-row]').forEach(refreshRow);
      counts();
    });
  });
  document.querySelectorAll('tr[data-row]').forEach(refreshRow);
  counts();
})();
";
require __DIR__ . '/../includes/footer.php';
