<?php
require_once __DIR__ . '/../includes/auth.php';
require_module_edit('hrms');

$id = (int)input('id');
$employee = [
    'id' => 0, 'employee_code' => '', 'name' => '', 'email' => '', 'phone' => '',
    'department_id' => '', 'designation' => '', 'salary' => '0', 'hire_date' => today(), 'status' => 'active',
];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM employees WHERE id = ?');
    $stmt->execute([$id]);
    $employee = $stmt->fetch() ?: $employee;
}

$error = '';

if (is_post()) {
    csrf_verify();
    $employee = [
        'id' => $id,
        'employee_code' => input('employee_code'),
        'name' => input('name'),
        'email' => input('email'),
        'phone' => input('phone'),
        'department_id' => input('department_id') ?: null,
        'designation' => input('designation'),
        'salary' => (float)input('salary'),
        'hire_date' => input('hire_date') ?: null,
        'status' => in_array(input('status'), ['active', 'inactive'], true) ? input('status') : 'active',
    ];

    if ($employee['name'] === '') {
        $error = 'Name is required.';
    } else {
        try {
            if (!$id && $employee['employee_code'] === '') {
                $employee['employee_code'] = 'EMP-' . str_pad((string)((int)db()->query('SELECT COUNT(*) FROM employees')->fetchColumn() + 1), 4, '0', STR_PAD_LEFT);
            }
            if ($id) {
                $stmt = db()->prepare('UPDATE employees SET employee_code=?, name=?, email=?, phone=?, department_id=?, designation=?, salary=?, hire_date=?, status=? WHERE id=?');
                $stmt->execute([$employee['employee_code'], $employee['name'], $employee['email'], $employee['phone'], $employee['department_id'], $employee['designation'], $employee['salary'], $employee['hire_date'], $employee['status'], $id]);
                flash('success', 'Employee updated.');
            } else {
                $stmt = db()->prepare('INSERT INTO employees (employee_code, name, email, phone, department_id, designation, salary, hire_date, status) VALUES (?,?,?,?,?,?,?,?,?)');
                $stmt->execute([$employee['employee_code'], $employee['name'], $employee['email'], $employee['phone'], $employee['department_id'], $employee['designation'], $employee['salary'], $employee['hire_date'], $employee['status']]);
                flash('success', 'Employee created.');
            }
            redirect('/hr/employees.php');
        } catch (PDOException $e) {
            $error = str_contains($e->getMessage(), 'Duplicate') ? 'An employee with this code already exists.' : 'Could not save employee.';
        }
    }
}

$departments = db()->query('SELECT id, name FROM departments ORDER BY name')->fetchAll();

$page_title = $id ? 'Edit Employee' : 'Add Employee';
require __DIR__ . '/../includes/header.php';
?>
<div class="card p-4" style="max-width:680px">
  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <div class="row g-3">
      <div class="col-sm-6">
        <label class="form-label">Employee Code</label>
        <input type="text" name="employee_code" class="form-control" value="<?= e($employee['employee_code']) ?>" placeholder="Auto-generated if left blank">
      </div>
      <div class="col-sm-6">
        <label class="form-label">Name</label>
        <input type="text" name="name" class="form-control" required value="<?= e($employee['name']) ?>">
      </div>
      <div class="col-sm-6">
        <label class="form-label">Email</label>
        <input type="email" name="email" class="form-control" value="<?= e($employee['email']) ?>">
      </div>
      <div class="col-sm-6">
        <label class="form-label">Phone</label>
        <input type="text" name="phone" class="form-control" value="<?= e($employee['phone']) ?>">
      </div>
      <div class="col-sm-6">
        <label class="form-label">Department</label>
        <select name="department_id" class="form-select">
          <option value="">— None —</option>
          <?php foreach ($departments as $d): ?>
            <option value="<?= (int)$d['id'] ?>" <?= (string)$employee['department_id'] === (string)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-6">
        <label class="form-label">Designation</label>
        <input type="text" name="designation" class="form-control" value="<?= e($employee['designation']) ?>">
      </div>
      <div class="col-sm-6">
        <label class="form-label">Salary</label>
        <input type="number" step="0.01" min="0" name="salary" class="form-control" value="<?= e($employee['salary']) ?>">
      </div>
      <div class="col-sm-6">
        <label class="form-label">Hire Date</label>
        <input type="date" name="hire_date" class="form-control" value="<?= e($employee['hire_date']) ?>">
      </div>
      <div class="col-sm-6">
        <label class="form-label">Status</label>
        <select name="status" class="form-select">
          <option value="active" <?= $employee['status'] === 'active' ? 'selected' : '' ?>>Active</option>
          <option value="inactive" <?= $employee['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>
      </div>
    </div>
    <div class="page-actions mt-4">
      <button type="submit" class="btn btn-brand">Save</button>
      <a href="employees.php" class="btn btn-outline-secondary">Cancel</a>
    </div>
  </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
