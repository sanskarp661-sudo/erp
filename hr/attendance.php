<?php
require_once __DIR__ . '/../includes/auth.php';
require_module_edit('hrms');

$date = input('date') ?: today();
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = today();
}

if (is_post()) {
    csrf_verify();
    $postedDate = input('date') ?: today();
    $statuses = $_POST['status'] ?? [];
    $checkIns = $_POST['check_in'] ?? [];
    $checkOuts = $_POST['check_out'] ?? [];

    $pdo = db();
    $stmt = $pdo->prepare("
      INSERT INTO attendance (employee_id, attendance_date, status, check_in, check_out)
      VALUES (?, ?, ?, ?, ?)
      ON DUPLICATE KEY UPDATE status = VALUES(status), check_in = VALUES(check_in), check_out = VALUES(check_out)
    ");
    foreach ($statuses as $empId => $status) {
        $empId = (int)$empId;
        if (!in_array($status, ['present', 'absent', 'half_day', 'leave'], true)) continue;
        $ci = $status === 'present' || $status === 'half_day' ? (trim($checkIns[$empId] ?? '') ?: null) : null;
        $co = $status === 'present' || $status === 'half_day' ? (trim($checkOuts[$empId] ?? '') ?: null) : null;
        $stmt->execute([$empId, $postedDate, $status, $ci, $co]);
    }
    flash('success', 'Attendance saved for ' . $postedDate . '.');
    redirect('/hr/attendance.php?date=' . urlencode($postedDate));
}

$employees = db()->query("SELECT id, employee_code, name FROM employees WHERE status='active' ORDER BY name")->fetchAll();

$existing = [];
$stmt = db()->prepare('SELECT * FROM attendance WHERE attendance_date = ?');
$stmt->execute([$date]);
foreach ($stmt->fetchAll() as $row) {
    $existing[$row['employee_id']] = $row;
}

$page_title = 'Attendance';
require __DIR__ . '/../includes/header.php';
?>
<form method="get" class="mb-3 d-flex gap-2 align-items-end">
  <div>
    <label class="form-label mb-1">Date</label>
    <input type="date" name="date" class="form-control" value="<?= e($date) ?>" onchange="this.form.submit()">
  </div>
</form>

<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="date" value="<?= e($date) ?>">
  <div class="card p-3">
    <div class="table-responsive">
      <table class="table" id="attTable">
        <thead><tr><th>Code</th><th>Employee</th><th>Status</th><th>Check In</th><th>Check Out</th></tr></thead>
        <tbody>
        <?php foreach ($employees as $emp): $rec = $existing[$emp['id']] ?? ['status' => 'present', 'check_in' => '', 'check_out' => '']; ?>
          <tr>
            <td><?= e($emp['employee_code']) ?></td>
            <td><?= e($emp['name']) ?></td>
            <td>
              <select name="status[<?= (int)$emp['id'] ?>]" class="form-select form-select-sm">
                <?php foreach (['present' => 'Present', 'absent' => 'Absent', 'half_day' => 'Half Day', 'leave' => 'Leave'] as $val => $label): ?>
                  <option value="<?= $val ?>" <?= $rec['status'] === $val ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td><input type="time" name="check_in[<?= (int)$emp['id'] ?>]" class="form-control form-control-sm" value="<?= e($rec['check_in']) ?>"></td>
            <td><input type="time" name="check_out[<?= (int)$emp['id'] ?>]" class="form-control form-control-sm" value="<?= e($rec['check_out']) ?>"></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$employees): ?><tr><td colspan="5" class="text-muted text-center">No active employees.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php if ($employees): ?><button type="submit" class="btn btn-brand">Save Attendance</button><?php endif; ?>
  </div>
</form>
<?php require __DIR__ . '/../includes/footer.php'; ?>
