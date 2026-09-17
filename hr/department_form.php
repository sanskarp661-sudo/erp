<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$id = (int)input('id');
$department = ['id' => 0, 'name' => '', 'description' => ''];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM departments WHERE id = ?');
    $stmt->execute([$id]);
    $department = $stmt->fetch() ?: $department;
}

if (is_post()) {
    csrf_verify();
    $name = input('name');
    $description = input('description');

    if ($name === '') {
        flash('danger', 'Department name is required.');
    } else {
        if ($id) {
            db()->prepare('UPDATE departments SET name=?, description=? WHERE id=?')->execute([$name, $description, $id]);
            flash('success', 'Department updated.');
        } else {
            db()->prepare('INSERT INTO departments (name, description) VALUES (?,?)')->execute([$name, $description]);
            flash('success', 'Department created.');
        }
        redirect('/hr/departments.php');
    }
    $department = ['id' => $id, 'name' => $name, 'description' => $description];
}

$page_title = $id ? 'Edit Department' : 'Add Department';
require __DIR__ . '/../includes/header.php';
?>
<div class="card p-4" style="max-width:520px">
  <form method="post">
    <?= csrf_field() ?>
    <div class="mb-3">
      <label class="form-label">Name</label>
      <input type="text" name="name" class="form-control" required value="<?= e($department['name']) ?>">
    </div>
    <div class="mb-3">
      <label class="form-label">Description</label>
      <textarea name="description" class="form-control" rows="3"><?= e($department['description']) ?></textarea>
    </div>
    <div class="page-actions">
      <button type="submit" class="btn btn-brand">Save</button>
      <a href="departments.php" class="btn btn-outline-secondary">Cancel</a>
    </div>
  </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
