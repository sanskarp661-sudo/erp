<?php
require_once __DIR__ . '/../includes/auth.php';
require_module_edit('supply-chain');

$error = '';

if (is_post()) {
    csrf_verify();
    $productId = (int)input('product_id');
    $warehouseId = (int)input('warehouse_id');
    $type = input('type');
    $qty = (int)input('quantity');
    $reference = input('reference');
    $notes = input('notes');

    $stmt = db()->prepare('SELECT * FROM products WHERE id = ?');
    $stmt->execute([$productId]);
    $product = $stmt->fetch();

    $stmt = db()->prepare("SELECT * FROM warehouses WHERE id = ? AND is_group = 0");
    $stmt->execute([$warehouseId]);
    $warehouse = $stmt->fetch();

    if (!$product) {
        $error = 'Please select a valid product.';
    } elseif (!$warehouse) {
        $error = 'Please select a valid warehouse.';
    } elseif (!in_array($type, ['in', 'out', 'adjustment'], true)) {
        $error = 'Invalid movement type.';
    } elseif ($qty === 0) {
        $error = 'Quantity cannot be zero.';
    } else {
        $delta = $type === 'out' ? -abs($qty) : ($type === 'in' ? abs($qty) : $qty);

        $pdo = db();
        $pdo->beginTransaction();
        try {
            stock_move($productId, $warehouseId, $delta, $type, $reference, $notes, current_user()['id']);
            $pdo->commit();
            flash('success', 'Stock movement recorded.');
            redirect('/supply-chain/stock_movements.php');
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = $e->getMessage() ?: 'Could not record stock movement.';
        }
    }
}

$products = db()->query('SELECT id, sku, name, quantity, unit FROM products WHERE status = "active" ORDER BY name')->fetchAll();
$warehouses = leaf_warehouses();
$defaultWarehouseId = default_warehouse_id();

$page_title = 'New Stock Movement';
require __DIR__ . '/../includes/header.php';
?>
<div class="card p-4" style="max-width:560px">
  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
  <?php if (!$warehouses): ?>
    <div class="alert alert-warning">No warehouses set up yet. <a href="warehouse_form.php">Create one first</a>.</div>
  <?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <div class="mb-3">
      <label class="form-label">Product</label>
      <select name="product_id" class="form-select" required>
        <option value="">— Select product —</option>
        <?php foreach ($products as $p): ?>
          <option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?> (<?= e($p['sku']) ?>) — total: <?= (int)$p['quantity'] ?> <?= e($p['unit']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="mb-3">
      <label class="form-label">Warehouse</label>
      <select name="warehouse_id" class="form-select" required>
        <option value="">— Select warehouse —</option>
        <?php foreach ($warehouses as $w): ?>
          <option value="<?= (int)$w['id'] ?>" <?= $w['id'] == $defaultWarehouseId ? 'selected' : '' ?>><?= e($w['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="row g-3">
      <div class="col-sm-6">
        <label class="form-label">Movement Type</label>
        <select name="type" class="form-select" required>
          <option value="in">Stock In (received / restock)</option>
          <option value="out">Stock Out (damaged / lost / used)</option>
          <option value="adjustment">Adjustment (+/- correction)</option>
        </select>
      </div>
      <div class="col-sm-6">
        <label class="form-label">Quantity</label>
        <input type="number" name="quantity" class="form-control" required>
        <div class="form-text">For "Adjustment" you may enter a negative number.</div>
      </div>
    </div>
    <div class="mb-3 mt-3">
      <label class="form-label">Reference</label>
      <input type="text" name="reference" class="form-control" placeholder="e.g. manual count, damage report...">
    </div>
    <div class="mb-3">
      <label class="form-label">Notes</label>
      <textarea name="notes" class="form-control" rows="2"></textarea>
    </div>
    <div class="page-actions">
      <button type="submit" class="btn btn-brand">Save Movement</button>
      <a href="stock_movements.php" class="btn btn-outline-secondary">Cancel</a>
    </div>
  </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
