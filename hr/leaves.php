<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

if (is_post() && input('action') === 'apply') {
    csrf_verify();
    $employeeId = (int)input('employee_id');
    $leaveType = input('leave_type') ?: 'casual';
    $start = input('start_date');
    $end = input('end_date');
    $reason = input('reason');

    if (!$employeeId || !$start || !$end) {
        flash('danger', 'Employee, start date and end date are required.');
    } elseif ($end < $start) {
        flash('danger', 'End date cannot be before start date.');
    } else {
        db()->prepare('INSERT INTO leaves (employee_id, leave_type, start_date, end_date, reason, status) VALUES (?,?,?,?,?,?)')
            ->execute([$employeeId, $leaveType, $start, $end, $reason, 'pending']);
        flash('success', 'Leave request submitted.');
    }
    redirect('/hr/leaves.php');
}

if (is_post() && input('action') === 'decide') {
    csrf_verify();
    require_role(['admin', 'manager']);
    $id = (int)input('id');
    $decision = input('decision') === 'approved' ? 'approved' : 'rejected';
    db()->prepare('UPDATE leaves SET status = ? WHERE id = ?')->execute([$decision, $id]);
    flash('success', 'Leave request ' . $decision . '.');
    redirect('/hr/leaves.php');
}

$leaves = db()->query("
  SELECT l.*, e.name employee_name, e.employee_code
  FROM leaves l JOIN employees e ON e.id = l.employee_id
  ORDER BY l.applied_at DESC
")->fetchAll();

$employees = db()->query("SELECT id, name, employee_code FROM employees WHERE status='active' ORDER BY name")->fetchAll();
$canDecide = in_array(current_user()['role'], ['admin', 'manager'], true);
$badge = ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger'];

$page_title = 'Leave Requests';
require __DIR__ . '/../includes/header.php';
?>
<div class="row g-3">
  <div class="col-lg-8">
    <div class="card p-3">
      <h6 class="mb-2">All Leave Requests</h6>
      <div class="table-responsive">
        <table class="table table-sm">
          <thead><tr><th>Employee</th><th>Type</th><th>From</th><th>To</th><th>Reason</th><th>Status</th><?php if ($canDecide): ?><th class="text-end">Actions</th><?php endif; ?></tr></thead>
          <tbody>
          <?php foreach ($leaves as $l): ?>
            <tr>
              <td><?= e($l['employee_name']) ?> <span class="text-muted small">(<?= e($l['employee_code']) ?>)</span></td>
              <td class="text-capitalize"><?= e($l['leave_type']) ?></td>
              <td><?= e($l['start_date']) ?></td>
              <td><?= e($l['end_date']) ?></td>
              <td class="text-muted"><?= e($l['reason']) ?></td>
              <td><span class="badge text-bg-<?= $badge[$l['status']] ?> badge-status"><?= e($l['status']) ?></span></td>
              <?php if ($canDecide): ?>
              <td class="text-end">
                <?php if ($l['status'] === 'pending'): ?>
                  <form method="post" class="d-inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="decide">
                    <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                    <input type="hidden" name="decision" value="approved">
                    <button class="btn btn-sm btn-outline-success" type="submit"><i class="fa-solid fa-check"></i></button>
                  </form>
                  <form method="post" class="d-inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="decide">
                    <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                    <input type="hidden" name="decision" value="rejected">
                    <button class="btn btn-sm btn-outline-danger" type="submit"><i class="fa-solid fa-xmark"></i></button>
                  </form>
                <?php endif; ?>
              </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
          <?php if (!$leaves): ?><tr><td colspan="7" class="text-muted text-center">No leave requests yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card p-3">
      <h6 class="mb-3">Apply for Leave</h6>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="apply">
        <div class="mb-2">
          <label class="form-label">Employee</label>
          <select name="employee_id" class="form-select" required>
            <option value="">— Select employee —</option>
            <?php foreach ($employees as $e): ?>
              <option value="<?= (int)$e['id'] ?>"><?= e($e['name']) ?> (<?= e($e['employee_code']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-2">
          <label class="form-label">Leave Type</label>
          <select name="leave_type" class="form-select">
            <option value="casual">Casual</option>
            <option value="sick">Sick</option>
            <option value="annual">Annual</option>
            <option value="unpaid">Unpaid</option>
          </select>
        </div>
        <div class="row g-2">
          <div class="col-6">
            <label class="form-label">From</label>
            <input type="date" name="start_date" class="form-control" required>
          </div>
          <div class="col-6">
            <label class="form-label">To</label>
            <input type="date" name="end_date" class="form-control" required>
          </div>
        </div>
        <div class="mb-2 mt-2">
          <label class="form-label">Reason</label>
          <textarea name="reason" class="form-control" rows="2"></textarea>
        </div>
        <button type="submit" class="btn btn-brand w-100">Submit Request</button>
      </form>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
