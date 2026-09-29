<?php
require_once __DIR__ . '/../includes/auth.php';
require_module_edit('hrms');

$id = (int)input('id');
$department = [
    'id' => 0, 'name' => '', 'code' => '', 'parent_id' => '', 'head_employee_id' => '', 'cost_center' => '', 'email' => '',
    'location' => '', 'default_leave_approver_id' => '', 'description' => '', 'status' => 'active',
];
if ($id) {
    $stmt = db()->prepare('SELECT * FROM departments WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        flash('danger', 'Department not found.');
        redirect('/hr/departments.php');
    }
    $department = array_merge($department, $row);
}

$allDepartments = db()->query('SELECT id, name, parent_id FROM departments ORDER BY name')->fetchAll();

/** Ids of $id and every department below it (so a department can't be moved under its own child). */
function department_subtree(int $id, array $all): array
{
    $ids = [$id];
    for ($i = 0; $i < count($ids); $i++) {
        foreach ($all as $d) {
            if ((int)$d['parent_id'] === $ids[$i] && !in_array((int)$d['id'], $ids, true)) {
                $ids[] = (int)$d['id'];
            }
        }
    }
    return $ids;
}
$excludedParents = $id ? department_subtree($id, $allDepartments) : [];

$error = '';
$activeTab = input('tab') === 'more' ? 'more' : 'details';

if (is_post()) {
    csrf_verify();
    $department = array_merge($department, [
        'name' => trim((string)input('name')),
        'code' => strtoupper(trim((string)input('code'))) ?: null,
        'parent_id' => (int)input('parent_id') ?: null,
        'head_employee_id' => (int)input('head_employee_id') ?: null,
        'cost_center' => trim((string)input('cost_center')) ?: null,
        'email' => trim((string)input('email')) ?: null,
        'location' => trim((string)input('location')) ?: null,
        'default_leave_approver_id' => (int)input('default_leave_approver_id') ?: null,
        'description' => trim((string)input('description')) ?: null,
        'status' => input('status') === 'inactive' ? 'inactive' : 'active',
    ]);
    $errorTab = 'details';
    if ($department['name'] === '') {
        $error = 'Department name is required.';
    } elseif ($department['parent_id'] && in_array($department['parent_id'], $excludedParents, true)) {
        $error = 'A department cannot sit under itself or one of its own sub-departments.';
    } elseif ($department['email'] && !filter_var($department['email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'Email is not a valid email address.';
        $errorTab = 'more';
    } else {
        $dup = db()->prepare('SELECT name FROM departments WHERE (LOWER(name) = LOWER(?) OR (code IS NOT NULL AND code = ?)) AND id <> ?');
        $dup->execute([$department['name'], $department['code'], $id]);
        if ($other = $dup->fetchColumn()) {
            $error = 'Department "' . $other . '" already uses this name or code.';
        }
    }
    if ($error === '' && $department['status'] === 'inactive' && $id) {
        $stmt = db()->prepare("SELECT COUNT(*) FROM employees WHERE department_id = ? AND status = 'active'");
        $stmt->execute([$id]);
        if ($n = (int)$stmt->fetchColumn()) {
            $error = 'Move its ' . $n . ' active employee(s) to another department before making it inactive.';
        }
    }

    if ($error === '') {
        $fields = array_intersect_key($department, array_flip(['name', 'code', 'parent_id', 'head_employee_id', 'cost_center', 'email', 'location', 'default_leave_approver_id', 'description', 'status']));
        if ($id) {
            db()->prepare('UPDATE departments SET ' . implode(', ', array_map(fn($c) => "$c = ?", array_keys($fields))) . ' WHERE id = ?')->execute([...array_values($fields), $id]);
            flash('success', 'Department updated.');
        } else {
            db()->prepare('INSERT INTO departments (' . implode(', ', array_keys($fields)) . ') VALUES (' . implode(', ', array_fill(0, count($fields), '?')) . ')')->execute(array_values($fields));
            flash('success', 'Department created.');
        }
        redirect('/hr/departments.php');
    }
    $activeTab = $errorTab;
}

$employees = db()->query("SELECT e.id, e.name, e.employee_code, e.designation, e.department_id FROM employees e WHERE e.status = 'active' ORDER BY e.name")->fetchAll();
$users = db()->query("SELECT id, name FROM users WHERE status='active' ORDER BY name")->fetchAll();
$costCenters = db()->query("SELECT DISTINCT cost_center FROM departments WHERE cost_center IS NOT NULL AND cost_center <> '' ORDER BY cost_center")->fetchAll(PDO::FETCH_COLUMN);
$members = [];
if ($id) {
    $stmt = db()->prepare("SELECT id, name, employee_code, designation, status FROM employees WHERE department_id = ? ORDER BY status = 'active' DESC, name");
    $stmt->execute([$id]);
    $members = $stmt->fetchAll();
}
$sel = fn($a, $b) => (string)$a === (string)$b ? 'selected' : '';

$page_title = $id ? 'Edit Department' : 'New Department';
require __DIR__ . '/../includes/header.php';
?>
<div class="card p-4">
  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0"><?= $id ? e($department['name']) : 'New Department' ?> <span class="badge text-bg-<?= $department['status'] === 'active' ? 'success' : 'secondary' ?> badge-status"><?= e($department['status']) ?></span></h5>
  </div>
  <ul class="nav nav-tabs mb-3">
    <li class="nav-item"><button class="nav-link <?= $activeTab === 'details' ? 'active' : '' ?>" id="tab-details" data-bs-toggle="tab" data-bs-target="#pane-details" type="button">Details</button></li>
    <li class="nav-item"><button class="nav-link <?= $activeTab === 'more' ? 'active' : '' ?>" id="tab-more" data-bs-toggle="tab" data-bs-target="#pane-more" type="button">Contact &amp; More Info</button></li>
    <?php if ($id): ?><li class="nav-item"><button class="nav-link" id="tab-members" data-bs-toggle="tab" data-bs-target="#pane-members" type="button">Employees (<?= count($members) ?>)</button></li><?php endif; ?>
  </ul>
  <form method="post">
    <?= csrf_field() ?>
    <div class="tab-content">
      <div class="tab-pane fade <?= $activeTab === 'details' ? 'show active' : '' ?>" id="pane-details">
        <div class="row g-3 mb-3">
          <div class="col-sm-3">
            <label class="form-label">Department Name <span class="text-danger">*</span></label>
            <input type="text" name="name" class="form-control" maxlength="120" required value="<?= e($department['name']) ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Code</label>
            <input type="text" name="code" class="form-control" maxlength="20" style="text-transform:uppercase" placeholder="e.g. ENG" value="<?= e($department['code'] ?? '') ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Parent Department</label>
            <select name="parent_id" class="form-select">
              <option value="">— None (top level) —</option>
              <?php foreach ($allDepartments as $d): if (in_array((int)$d['id'], $excludedParents, true)) continue; ?>
                <option value="<?= (int)$d['id'] ?>" <?= $sel($department['parent_id'], $d['id']) ?>><?= e($d['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-3">
            <label class="form-label">Status</label>
            <select name="status" class="form-select">
              <option value="active" <?= $sel($department['status'], 'active') ?>>Active</option>
              <option value="inactive" <?= $sel($department['status'], 'inactive') ?>>Inactive</option>
            </select>
          </div>
          <div class="col-sm-3">
            <label class="form-label">Head of Department</label>
            <select name="head_employee_id" class="form-select">
              <option value="">— None —</option>
              <?php foreach ($employees as $emp): ?><option value="<?= (int)$emp['id'] ?>" <?= $sel($department['head_employee_id'], $emp['id']) ?>><?= e($emp['name']) ?><?= $emp['designation'] ? ' — ' . e($emp['designation']) : '' ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-3">
            <label class="form-label">Cost Center</label>
            <input type="text" name="cost_center" class="form-control" maxlength="60" list="costCenterList" value="<?= e($department['cost_center'] ?? '') ?>">
            <datalist id="costCenterList"><?php foreach ($costCenters as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist>
          </div>
          <div class="col-sm-3">
            <label class="form-label">Default Leave Approver</label>
            <select name="default_leave_approver_id" class="form-select">
              <option value="">— HR managers —</option>
              <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>" <?= $sel($department['default_leave_approver_id'], $u['id']) ?>><?= e($u['name']) ?></option><?php endforeach; ?>
            </select>
            <div class="form-text">Used when an employee has no approver of their own.</div>
          </div>
        </div>
      </div>
      <div class="tab-pane fade <?= $activeTab === 'more' ? 'show active' : '' ?>" id="pane-more">
        <div class="row g-3 mb-3">
          <div class="col-sm-3">
            <label class="form-label">Department Email</label>
            <input type="email" name="email" class="form-control" maxlength="150" value="<?= e($department['email'] ?? '') ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Location / Floor</label>
            <input type="text" name="location" class="form-control" maxlength="120" value="<?= e($department['location'] ?? '') ?>">
          </div>
          <div class="col-12">
            <label class="form-label">Description</label>
            <textarea name="description" class="form-control" rows="3" maxlength="255"><?= e($department['description'] ?? '') ?></textarea>
          </div>
        </div>
      </div>
      <?php if ($id): ?>
      <div class="tab-pane fade" id="pane-members">
        <table class="table table-sm">
          <thead><tr><th>Code</th><th>Name</th><th>Designation</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($members as $m): ?>
            <tr><td><?= e($m['employee_code']) ?></td><td><a href="employee_view.php?id=<?= (int)$m['id'] ?>"><?= e($m['name']) ?></a></td><td><?= e($m['designation'] ?? '') ?></td><td><?= e($m['status']) ?></td></tr>
          <?php endforeach; ?>
          <?php if (!$members): ?><tr><td colspan="4" class="text-muted">No employees in this department.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
    <div class="page-actions mt-3">
      <button type="submit" class="btn btn-brand">Save Department</button>
      <a href="departments.php" class="btn btn-outline-secondary">Cancel</a>
    </div>
  </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
