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
    $children = db()->prepare('SELECT COUNT(*) FROM departments WHERE parent_id = ?');
    $children->execute([$id]);
    if ($stmt->fetchColumn() > 0) {
        flash('danger', 'Cannot delete: employees are still assigned to this department.');
    } elseif ($children->fetchColumn() > 0) {
        flash('danger', 'Cannot delete: it has sub-departments. Move or delete them first.');
    } else {
        db()->prepare('DELETE FROM departments WHERE id = ?')->execute([$id]);
        flash('success', 'Department deleted.');
    }
    redirect('/hr/departments.php');
}

$rows = db()->query("
  SELECT d.*, h.name head_name, (SELECT COUNT(*) FROM employees e WHERE e.department_id = d.id AND e.status <> 'left') employee_count
  FROM departments d LEFT JOIN employees h ON h.id = d.head_employee_id ORDER BY d.name
")->fetchAll();

// Tree order: each department followed by its sub-departments, indented.
$byParent = [];
foreach ($rows as $r) {
    $byParent[(int)$r['parent_id']][] = $r;
}
$departments = [];
$walk = function (int $parentId, int $depth) use (&$walk, &$departments, $byParent) {
    foreach ($byParent[$parentId] ?? [] as $r) {
        $r['depth'] = $depth;
        $departments[] = $r;
        $walk((int)$r['id'], $depth + 1);
    }
};
$walk(0, 0);

$page_title = 'Departments';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <input type="text" class="form-control" style="max-width:280px" placeholder="Search departments..." data-table-search="#deptTable">
  <?php if ($canEdit): ?><a href="department_form.php" class="btn btn-brand"><i class="fa-solid fa-plus"></i> New Department</a><?php endif; ?>
</div>
<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-hover" id="deptTable">
      <thead><tr><th>Name</th><th>Code</th><th>Head of Department</th><th>Cost Center</th><th>Employees</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($departments as $d): ?>
        <tr>
          <td style="padding-left:<?= 8 + 20 * $d['depth'] ?>px"><?= $d['depth'] ? '<span class="text-muted">&#8627;</span> ' : '' ?><a href="department_form.php?id=<?= (int)$d['id'] ?>"><?= e($d['name']) ?></a><?php if ($d['description']): ?><div class="small text-muted"><?= e($d['description']) ?></div><?php endif; ?></td>
          <td><?= e($d['code'] ?? '') ?></td>
          <td><?= e($d['head_name'] ?? '—') ?></td>
          <td><?= e($d['cost_center'] ?? '') ?></td>
          <td><a href="employees.php?department=<?= (int)$d['id'] ?>"><?= (int)$d['employee_count'] ?></a></td>
          <td><span class="badge text-bg-<?= $d['status'] === 'active' ? 'success' : 'secondary' ?> badge-status"><?= e($d['status']) ?></span></td>
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
      <?php if (!$departments): ?><tr><td colspan="7" class="text-muted text-center">No departments yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
