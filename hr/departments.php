<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
$canEdit = can_edit_module('hrms');
$canManage = can_manage_module('hrms');

if (is_post() && input('action') === 'delete') {
    require_module_manage('hrms');
    csrf_verify();
    $id = (int)input('id');
    $stmt = db()->prepare('SELECT COUNT(*) FROM employees WHERE department_id = ?');
    $stmt->execute([$id]);
    if ($stmt->fetchColumn() > 0) {
        flash('danger', 'Cannot delete: employees are still assigned to this department.');
    } else {
        db()->prepare('DELETE FROM departments WHERE id = ?')->execute([$id]);
        flash('success', 'Department deleted.');
    }
    redirect('/hr/departments.php');
}

$departments = db()->query("
  SELECT d.*, (SELECT COUNT(*) FROM employees e WHERE e.department_id = d.id) employee_count
  FROM departments d ORDER BY d.name
")->fetchAll();

$page_title = 'Departments';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <input type="text" class="form-control" style="max-width:280px" placeholder="Search departments..." data-table-search="#deptTable">
  <?php if ($canEdit): ?><a href="department_form.php" class="btn btn-brand"><i class="fa-solid fa-plus"></i> Add Department</a><?php endif; ?>
</div>
<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-hover" id="deptTable">
      <thead><tr><th>Name</th><th>Description</th><th>Employees</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($departments as $d): ?>
        <tr>
          <td><?= e($d['name']) ?></td>
          <td class="text-muted"><?= e($d['description']) ?></td>
          <td><?= (int)$d['employee_count'] ?></td>
          <td class="text-end">
            <?php if ($canEdit): ?><a href="department_form.php?id=<?= (int)$d['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen"></i></a><?php endif; ?>
            <?php if ($canManage): ?>
            <form method="post" class="d-inline" data-confirm="Delete this department?">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
              <button class="btn btn-sm btn-outline-danger" type="submit"><i class="fa-solid fa-trash"></i></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$departments): ?><tr><td colspan="4" class="text-muted text-center">No departments yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
