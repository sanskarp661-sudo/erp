<?php
require_once __DIR__ . '/../includes/auth.php';
require_module_edit('inventory');

$id = (int)input('id');
$itemCategory = ['id' => 0, 'name' => '', 'status' => 'active'];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM item_categories WHERE id = ?');
    $stmt->execute([$id]);
    $itemCategory = $stmt->fetch() ?: $itemCategory;
}

if (is_post()) {
    csrf_verify();
    $name = input('name');
    $status = in_array(input('status'), ['active', 'inactive'], true) ? input('status') : 'active';

    if ($name === '') {
        flash('danger', 'Item category name is required.');
    } else {
        try {
            if ($id) {
                db()->prepare('UPDATE item_categories SET name = ?, status = ? WHERE id = ?')->execute([$name, $status, $id]);
                flash('success', 'Item category updated.');
            } else {
                db()->prepare('INSERT INTO item_categories (name, status) VALUES (?, ?)')->execute([$name, $status]);
                flash('success', 'Item category created.');
            }
            redirect('/inventory/item_categories.php');
        } catch (PDOException $e) {
            flash('danger', str_contains($e->getMessage(), 'Duplicate') ? 'An item category with this name already exists.' : 'Could not save item category.');
        }
    }
    $itemCategory = ['id' => $id, 'name' => $name, 'status' => $status];
}

$page_title = $id ? 'Edit Item Category' : 'Add Item Category';
require __DIR__ . '/../includes/header.php';
?>
<div class="card p-4" style="max-width:520px">
  <form method="post">
    <?= csrf_field() ?>
    <div class="mb-3">
      <label class="form-label">Name</label>
      <input type="text" name="name" class="form-control" required value="<?= e($itemCategory['name']) ?>">
    </div>
    <div class="mb-3">
      <label class="form-label">Status</label>
      <select name="status" class="form-select">
        <option value="active" <?= $itemCategory['status'] === 'active' ? 'selected' : '' ?>>Active</option>
        <option value="inactive" <?= $itemCategory['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
      </select>
    </div>
    <div class="page-actions">
      <button type="submit" class="btn btn-brand">Save</button>
      <a href="item_categories.php" class="btn btn-outline-secondary">Cancel</a>
    </div>
  </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
