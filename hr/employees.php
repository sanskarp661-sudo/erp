<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

if (is_post() && input('action') === 'delete') {
    csrf_verify();
    $id = (int)input('id');
    db()->prepare('DELETE FROM employees WHERE id = ?')->execute([$id]);
    flash('success', 'Employee deleted.');
    redirect('/hr/employees.php');
}

$employees = db()->query("
  SELECT e.*, d.name department_name
  FROM employees e LEFT JOIN departments d ON d.id = e.department_id
  ORDER BY e.name
")->fetchAll();

$canSeeSalary = in_array(current_user()['role'], ['admin', 'manager'], true);

$page_title = 'Employees';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <input type="text" class="form-control" style="max-width:280px" placeholder="Search employees..." data-table-search="#empTable">
  <a href="employee_form.php" class="btn btn-brand"><i class="fa-solid fa-plus"></i> Add Employee</a>
</div>
<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-hover" id="empTable">
      <thead><tr><th>Code</th><th>Name</th><th>Department</th><th>Designation</th><th>Hire Date</th><?php if ($canSeeSalary): ?><th class="text-end">Salary</th><?php endif; ?><th>Status</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($employees as $emp): ?>
        <tr>
          <td><?= e($emp['employee_code']) ?></td>
          <td><?= e($emp['name']) ?></td>
          <td><?= e($emp['department_name'] ?? '—') ?></td>
          <td><?= e($emp['designation']) ?></td>
          <td><?= e($emp['hire_date']) ?></td>
          <?php if ($canSeeSalary): ?><td class="text-end"><?= money($emp['salary']) ?></td><?php endif; ?>
          <td><span class="badge text-bg-<?= $emp['status'] === 'active' ? 'success' : 'secondary' ?> badge-status"><?= e($emp['status']) ?></span></td>
          <td class="text-end">
            <a href="employee_form.php?id=<?= (int)$emp['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen"></i></a>
            <form method="post" class="d-inline" data-confirm="Delete this employee?">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$emp['id'] ?>">
              <button class="btn btn-sm btn-outline-danger" type="submit"><i class="fa-solid fa-trash"></i></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$employees): ?><tr><td colspan="8" class="text-muted text-center">No employees yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
