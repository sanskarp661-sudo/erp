<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/hr.php';
require_login();

$canManage = can_manage_module('hrms');
$statusFilter = in_array(input('status'), ['pending', 'approved', 'rejected', 'cancelled', 'all'], true) ? input('status') : 'all';
$employeeFilter = (int)input('employee');
$year = (int)input('year') ?: (int)date('Y');

$where = ['YEAR(l.start_date) = ?'];
$params = [$year];
if ($statusFilter !== 'all') {
    $where[] = 'l.status = ?';
    $params[] = $statusFilter;
}
if ($employeeFilter) {
    $where[] = 'l.employee_id = ?';
    $params[] = $employeeFilter;
}
$stmt = db()->prepare('
  SELECT l.*, e.name employee_name, e.employee_code, lt.name type_name, ap.name approver_name
  FROM leaves l JOIN employees e ON e.id = l.employee_id
  LEFT JOIN leave_types lt ON lt.code = l.leave_type
  LEFT JOIN users ap ON ap.id = l.leave_approver_id
  WHERE ' . implode(' AND ', $where) . '
  ORDER BY l.status = \'pending\' DESC, l.start_date DESC, l.id DESC');
$stmt->execute($params);
$leaves = $stmt->fetchAll();

$employees = db()->query("SELECT id, name, employee_code FROM employees ORDER BY name")->fetchAll();
$badge = ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger', 'cancelled' => 'secondary'];
$fmt = fn($v) => rtrim(rtrim(number_format((float)$v, 1, '.', ''), '0'), '.');
$pendingForMe = count(array_filter($leaves, fn($l) => $l['status'] === 'pending' && hr_can_decide_leave($l)));

$page_title = 'Leave Applications';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-end mb-3 flex-wrap gap-2">
  <form method="get" class="d-flex gap-2 flex-wrap">
    <input type="text" class="form-control" style="max-width:220px" placeholder="Search..." data-table-search="#leaveTable">
    <select name="status" class="form-select" style="width:auto" onchange="this.form.submit()">
      <?php foreach (['all' => 'All statuses', 'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'] as $k => $v): ?>
        <option value="<?= $k ?>" <?= $statusFilter === $k ? 'selected' : '' ?>><?= $v ?></option>
      <?php endforeach; ?>
    </select>
    <select name="employee" class="form-select" style="width:auto" onchange="this.form.submit()">
      <option value="">All employees</option>
      <?php foreach ($employees as $emp): ?><option value="<?= (int)$emp['id'] ?>" <?= $employeeFilter === (int)$emp['id'] ? 'selected' : '' ?>><?= e($emp['name']) ?></option><?php endforeach; ?>
    </select>
    <select name="year" class="form-select" style="width:auto" onchange="this.form.submit()">
      <?php for ($y = (int)date('Y') + 1; $y >= (int)date('Y') - 3; $y--): ?><option value="<?= $y ?>" <?= $year === $y ? 'selected' : '' ?>><?= $y ?></option><?php endfor; ?>
    </select>
  </form>
  <div class="page-actions">
    <?php if ($canManage): ?><a href="leave_types.php" class="btn btn-outline-secondary"><i class="fa-solid fa-list"></i> Leave Types</a><?php endif; ?>
    <a href="leave_form.php" class="btn btn-brand"><i class="fa-solid fa-plus"></i> New Leave Application</a>
  </div>
</div>
<?php if ($pendingForMe): ?><div class="alert alert-warning py-2"><?= $pendingForMe ?> leave application(s) are waiting for your approval.</div><?php endif; ?>
<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-hover" id="leaveTable">
      <thead><tr><th>Application</th><th>Employee</th><th>Type</th><th>From</th><th>To</th><th class="text-end">Days</th><th>Approver</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($leaves as $l): ?>
        <tr>
          <td><a href="leave_view.php?id=<?= (int)$l['id'] ?>"><?= e($l['application_no'] ?: '#' . $l['id']) ?></a></td>
          <td><?= e($l['employee_name']) ?> <span class="text-muted small">(<?= e($l['employee_code']) ?>)</span></td>
          <td><?= e($l['type_name'] ?? ucfirst($l['leave_type'])) ?><?= $l['half_day'] ? ' <span class="badge text-bg-light">½</span>' : '' ?></td>
          <td><?= e($l['start_date']) ?></td>
          <td><?= e($l['end_date']) ?></td>
          <td class="text-end"><?= $fmt($l['total_days']) ?></td>
          <td class="small"><?= e($l['approver_name'] ?? 'HR managers') ?></td>
          <td><span class="badge text-bg-<?= $badge[$l['status']] ?? 'secondary' ?> badge-status"><?= e($l['status']) ?></span></td>
          <td class="text-end">
            <a href="leave_view.php?id=<?= (int)$l['id'] ?>" class="btn btn-sm btn-outline-secondary"><?= $l['status'] === 'pending' && hr_can_decide_leave($l) ? 'Review' : '<i class="fa-solid fa-eye"></i>' ?></a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$leaves): ?><tr><td colspan="9" class="text-muted text-center">No leave applications for these filters.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
