<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$error = '';

if (is_post()) {
    csrf_verify();
    $productId = (int)input('product_id');
    $type = input('type');
    $qty = (int)input('quantity');
    $reference = input('reference');
    $notes = input('notes');

    $stmt = db()->prepare('SELECT * FROM products WHERE id = ?');
    $stmt->execute([$productId]);
    $product = $stmt->fetch();

    if (!$product) {
        $error = 'Please select a valid product.';
    } elseif (!in_array($type, ['in', 'out', 'adjustment'], true)) {
        $error = 'Invalid movement type.';
    } elseif ($qty === 0) {
        $error = 'Quantity cannot be zero.';
    } elseif ($type === 'out' && $qty > $product['quantity']) {
        $error = 'Not enough stock: only ' . $product['quantity'] . ' ' . $product['unit'] . ' available.';
    } else {
        $delta = $type === 'out' ? -abs($qty) : ($type === 'in' ? abs($qty) : $qty);
        $newQty = max(0, $product['quantity'] + $delta);

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE products SET quantity = ? WHERE id = ?')->execute([$newQty, $productId]);
            $pdo->prepare('INSERT INTO stock_movements (product_id, type, quantity, reference, notes, created_by) VALUES (?,?,?,?,?,?)')
                ->execute([$productId, $type, $delta, $reference, $notes, current_user()['id']]);
            $pdo->commit();
            flash('success', 'Stock movement recorded.');
            redirect('/supply-chain/stock_movements.php');
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Could not record stock movement.';
        }
    }
}

$products = db()->query('SELECT id, sku, name, quantity, unit FROM products WHERE status = "active" ORDER BY name')->fetchAll();

$page_title = 'New Stock Movement';
require __DIR__ . '/../includes/header.php';
?>
<div class="card p-4" style="max-width:560px">
  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <div class="mb-3">
      <label class="form-label">Product</label>
      <select name="product_id" class="form-select" required>
        <option value="">— Select product —</option>
        <?php foreach ($products as $p): ?>
          <option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?> (<?= e($p['sku']) ?>) — current: <?= (int)$p['quantity'] ?> <?= e($p['unit']) ?></option>
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
      <input type="text" name="reference" class="form-control" placeholder="e.g. PO-000012, manual count...">
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
