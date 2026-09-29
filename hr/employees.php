<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
$canEdit = can_edit_module('hrms');
$canManage = can_manage_module('hrms');

if (is_post() && input('action') === 'delete') {
    require_module_manage('hrms');
    csrf_verify();
    $id = (int)input('id');
    db()->prepare('DELETE FROM employees WHERE id = ?')->execute([$id]);
    flash('success', 'Employee deleted.');
    redirect('/hr/employees.php');
}

$statusFilter = in_array(input('status'), ['active', 'inactive', 'left', 'all'], true) ? input('status') : 'current';
$departmentFilter = (int)input('department');
$conds = [];
$params = [];
if ($statusFilter === 'current') {
    $conds[] = "e.status <> 'left'";
} elseif ($statusFilter !== 'all') {
    $conds[] = 'e.status = ?';
    $params[] = $statusFilter;
}
if ($departmentFilter) {
    $conds[] = 'e.department_id = ?';
    $params[] = $departmentFilter;
}
$where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';
$stmt = db()->prepare("
  SELECT e.*, d.name department_name, m.name manager_name
  FROM employees e LEFT JOIN departments d ON d.id = e.department_id
  LEFT JOIN employees m ON m.id = e.reports_to_id
  $where
  ORDER BY e.name
");
$stmt->execute($params);
$departments = db()->query('SELECT id, name FROM departments ORDER BY name')->fetchAll();
$employees = $stmt->fetchAll();
$statusBadge = ['active' => 'success', 'inactive' => 'secondary', 'left' => 'dark'];

$canSeeSalary = can_manage_module('hrms');

$page_title = 'Employees';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <div class="d-flex gap-2">
    <input type="text" class="form-control" style="max-width:280px" placeholder="Search employees..." data-table-search="#empTable">
    <form method="get" class="d-flex gap-2">
      <select name="status" class="form-select" onchange="this.form.submit()">
        <?php foreach (['current' => 'Current (not left)', 'active' => 'Active', 'inactive' => 'Inactive', 'left' => 'Left', 'all' => 'All'] as $k => $v): ?>
          <option value="<?= $k ?>" <?= $statusFilter === $k ? 'selected' : '' ?>><?= e($v) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="department" class="form-select" onchange="this.form.submit()">
        <option value="">All departments</option>
        <?php foreach ($departments as $d): ?><option value="<?= (int)$d['id'] ?>" <?= $departmentFilter === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
      </select>
    </form>
  </div>
  <?php if ($canEdit): ?><a href="employee_form.php" class="btn btn-brand"><i class="fa-solid fa-plus"></i> New Employee</a><?php endif; ?>
</div>
<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-hover" id="empTable">
      <thead><tr><th>Code</th><th>Name</th><th>Department</th><th>Designation</th><th>Reports To</th><th>Date of Joining</th><?php if ($canSeeSalary): ?><th class="text-end">Salary</th><?php endif; ?><th>Status</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($employees as $emp): ?>
        <tr>
          <td><?= e($emp['employee_code']) ?></td>
          <td><a href="employee_view.php?id=<?= (int)$emp['id'] ?>"><?= e($emp['name']) ?></a></td>
          <td><?= e($emp['department_name'] ?? '—') ?></td>
          <td><?= e($emp['designation']) ?></td>
          <td><?= e($emp['manager_name'] ?? '—') ?></td>
          <td><?= e($emp['hire_date']) ?></td>
          <?php if ($canSeeSalary): ?><td class="text-end"><?= money($emp['salary']) ?></td><?php endif; ?>
          <td><span class="badge text-bg-<?= $statusBadge[$emp['status']] ?? 'secondary' ?> badge-status"><?= e($emp['status']) ?></span></td>
          <td class="text-end">
            <?php if ($canSeeSalary): ?><a href="salary_slips.php?employee=<?= (int)$emp['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Salary Slips"><i class="fa-solid fa-money-check-dollar"></i></a><?php endif; ?>
            <?php if ($canEdit): ?><a href="employee_form.php?id=<?= (int)$emp['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen"></i></a><?php endif; ?>
            <?php if ($canManage): ?>
            <form method="post" class="d-inline" data-confirm="Delete this employee?">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$emp['id'] ?>">
              <button class="btn btn-sm btn-outline-danger" type="submit"><i class="fa-solid fa-trash"></i></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$employees): ?><tr><td colspan="9" class="text-muted text-center">No employees yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
