<?php
require_once __DIR__ . '/../includes/auth.php';
require_module_edit('supply-chain');

$id = (int)input('id');
$warehouse = ['id' => 0, 'name' => '', 'parent_id' => (int)input('parent_id') ?: null, 'is_group' => 0, 'status' => 'active'];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM warehouses WHERE id = ?');
    $stmt->execute([$id]);
    $warehouse = $stmt->fetch();
    if (!$warehouse) {
        flash('danger', 'Warehouse not found.');
        redirect('/supply-chain/warehouses.php');
    }
}

$error = '';

if (is_post()) {
    csrf_verify();
    $name = trim(input('name'));
    $parentId = (int)input('parent_id') ?: null;
    $isGroup = input('is_group') === '1' ? 1 : 0;
    $status = in_array(input('status'), ['active', 'inactive'], true) ? input('status') : 'active';

    if ($name === '') {
        $error = 'Please enter a warehouse name.';
    } elseif ($parentId === $id && $id) {
        $error = 'A warehouse cannot be its own parent.';
    } else {
        try {
            if ($id) {
                db()->prepare('UPDATE warehouses SET name=?, parent_id=?, is_group=?, status=? WHERE id=?')
                    ->execute([$name, $parentId, $isGroup, $status, $id]);
                $warehouseId = $id;
            } else {
                db()->prepare('INSERT INTO warehouses (name, parent_id, is_group, status) VALUES (?,?,?,?)')
                    ->execute([$name, $parentId, $isGroup, $status]);
                $warehouseId = (int)db()->lastInsertId();
            }
            flash('success', $id ? 'Warehouse updated.' : 'Warehouse created.');
            redirect('/supply-chain/warehouses.php');
        } catch (PDOException $e) {
            $error = 'Could not save warehouse.';
        }
    }
    $warehouse = ['id' => $id, 'name' => $name, 'parent_id' => $parentId, 'is_group' => $isGroup, 'status' => $status];
}

$parents = db()->query('SELECT id, name, parent_id FROM warehouses WHERE is_group = 1 ORDER BY name')->fetchAll();

$page_title = $id ? 'Edit Warehouse' : 'New Warehouse';
require __DIR__ . '/../includes/header.php';
?>
<div class="card p-4" style="max-width:560px">
  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <div class="mb-3">
      <label class="form-label">Warehouse Name</label>
      <input type="text" name="name" class="form-control" required value="<?= e($warehouse['name']) ?>" placeholder="e.g. Finished Goods, Mumbai Branch">
    </div>
    <div class="mb-3">
      <label class="form-label">Parent Warehouse</label>
      <select name="parent_id" class="form-select">
        <option value="">— None (top level) —</option>
        <?php foreach ($parents as $p): if ((int)$p['id'] === $id) continue; ?>
          <option value="<?= (int)$p['id'] ?>" <?= (string)$warehouse['parent_id'] === (string)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="form-text">Only "Group" warehouses can be a parent.</div>
    </div>
    <div class="form-check mb-3">
      <input type="checkbox" class="form-check-input" id="isGroupCheck" name="is_group" value="1" <?= $warehouse['is_group'] ? 'checked' : '' ?>>
      <label class="form-check-label" for="isGroupCheck">This is a Group warehouse (organizational only — can't hold stock, only other warehouses under it)</label>
    </div>
    <div class="mb-3">
      <label class="form-label">Status</label>
      <select name="status" class="form-select">
        <option value="active" <?= $warehouse['status'] === 'active' ? 'selected' : '' ?>>Active</option>
        <option value="inactive" <?= $warehouse['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
      </select>
    </div>
    <div class="page-actions">
      <button type="submit" class="btn btn-brand">Save Warehouse</button>
      <a href="warehouses.php" class="btn btn-outline-secondary">Cancel</a>
    </div>
  </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
