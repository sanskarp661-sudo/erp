<?php
require_once __DIR__ . '/../includes/auth.php';
require_module_edit('inventory');

$id = (int)input('id');
$brand = ['id' => 0, 'name' => '', 'status' => 'active'];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM brands WHERE id = ?');
    $stmt->execute([$id]);
    $brand = $stmt->fetch() ?: $brand;
}

if (is_post()) {
    csrf_verify();
    $name = input('name');
    $status = in_array(input('status'), ['active', 'inactive'], true) ? input('status') : 'active';

    if ($name === '') {
        flash('danger', 'Brand name is required.');
    } else {
        try {
            if ($id) {
                db()->prepare('UPDATE brands SET name = ?, status = ? WHERE id = ?')->execute([$name, $status, $id]);
                flash('success', 'Brand updated.');
            } else {
                db()->prepare('INSERT INTO brands (name, status) VALUES (?, ?)')->execute([$name, $status]);
                flash('success', 'Brand created.');
            }
            redirect('/inventory/brands.php');
        } catch (PDOException $e) {
            flash('danger', str_contains($e->getMessage(), 'Duplicate') ? 'A brand with this name already exists.' : 'Could not save brand.');
        }
    }
    $brand = ['id' => $id, 'name' => $name, 'status' => $status];
}

$page_title = $id ? 'Edit Brand' : 'Add Brand';
require __DIR__ . '/../includes/header.php';
?>
<div class="card p-4" style="max-width:520px">
  <form method="post">
    <?= csrf_field() ?>
    <div class="mb-3">
      <label class="form-label">Name</label>
      <input type="text" name="name" class="form-control" required value="<?= e($brand['name']) ?>">
    </div>
    <div class="mb-3">
      <label class="form-label">Status</label>
      <select name="status" class="form-select">
        <option value="active" <?= $brand['status'] === 'active' ? 'selected' : '' ?>>Active</option>
        <option value="inactive" <?= $brand['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
      </select>
    </div>
    <div class="page-actions">
      <button type="submit" class="btn btn-brand">Save</button>
      <a href="brands.php" class="btn btn-outline-secondary">Cancel</a>
    </div>
  </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
