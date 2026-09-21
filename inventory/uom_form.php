<?php
require_once __DIR__ . '/../includes/auth.php';
require_module_edit('inventory');

$id = (int)input('id');
$uom = ['id' => 0, 'name' => '', 'status' => 'active'];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM uom WHERE id = ?');
    $stmt->execute([$id]);
    $uom = $stmt->fetch() ?: $uom;
}

$error = '';

if (is_post()) {
    csrf_verify();
    $name = trim(input('name'));
    $status = in_array(input('status'), ['active', 'inactive'], true) ? input('status') : 'active';

    if ($name === '') {
        $error = 'Unit name is required.';
    } else {
        try {
            if ($id) {
                $oldName = $uom['name'];
                db()->prepare('UPDATE uom SET name = ?, status = ? WHERE id = ?')->execute([$name, $status, $id]);
                if ($name !== $oldName) {
                    // Keep existing products pointed at the renamed unit —
                    // products.unit stores the plain name, not this row's id.
                    db()->prepare('UPDATE products SET unit = ? WHERE unit = ?')->execute([$name, $oldName]);
                }
                flash('success', 'Unit updated.');
            } else {
                db()->prepare('INSERT INTO uom (name, status) VALUES (?, ?)')->execute([$name, $status]);
                flash('success', 'Unit created.');
            }
            redirect('/inventory/uom.php');
        } catch (PDOException $e) {
            $error = str_contains($e->getMessage(), 'Duplicate') ? 'A unit with this name already exists.' : 'Could not save unit.';
        }
    }
    $uom = ['id' => $id, 'name' => $name, 'status' => $status];
}

$page_title = $id ? 'Edit Unit' : 'Add Unit';
require __DIR__ . '/../includes/header.php';
?>
<div class="card p-4" style="max-width:480px">
  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <div class="mb-3">
      <label class="form-label">Name</label>
      <input type="text" name="name" class="form-control" required value="<?= e($uom['name']) ?>" placeholder="e.g. pcs, kg, box, litre">
    </div>
    <div class="mb-3">
      <label class="form-label">Status</label>
      <select name="status" class="form-select">
        <option value="active" <?= $uom['status'] === 'active' ? 'selected' : '' ?>>Active</option>
        <option value="inactive" <?= $uom['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
      </select>
      <div class="form-text">Inactive units stay on any product that already uses them, but won't appear in the picker for new products.</div>
    </div>
    <div class="page-actions">
      <button type="submit" class="btn btn-brand">Save</button>
      <a href="uom.php" class="btn btn-outline-secondary">Cancel</a>
    </div>
  </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
