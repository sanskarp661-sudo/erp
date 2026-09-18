<?php
require_once __DIR__ . '/../includes/auth.php';
require_module_edit('inventory');

$id = (int)input('id');
$product = [
    'id' => 0, 'sku' => '', 'name' => '', 'category_id' => '', 'unit' => 'pcs',
    'cost_price' => '0', 'selling_price' => '0', 'quantity' => '0', 'reorder_level' => '0', 'status' => 'active',
];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM products WHERE id = ?');
    $stmt->execute([$id]);
    $product = $stmt->fetch() ?: $product;
}

$error = '';

if (is_post()) {
    csrf_verify();
    $product = [
        'id' => $id,
        'sku' => input('sku'),
        'name' => input('name'),
        'category_id' => input('category_id') ?: null,
        'unit' => input('unit') ?: 'pcs',
        'cost_price' => (float)input('cost_price'),
        'selling_price' => (float)input('selling_price'),
        'quantity' => (int)input('quantity'),
        'reorder_level' => (int)input('reorder_level'),
        'status' => in_array(input('status'), ['active', 'inactive'], true) ? input('status') : 'active',
    ];

    if ($product['sku'] === '' || $product['name'] === '') {
        $error = 'SKU and Name are required.';
    } else {
        try {
            if ($id) {
                $stmt = db()->prepare('UPDATE products SET sku=?, name=?, category_id=?, unit=?, cost_price=?, selling_price=?, quantity=?, reorder_level=?, status=? WHERE id=?');
                $stmt->execute([$product['sku'], $product['name'], $product['category_id'], $product['unit'], $product['cost_price'], $product['selling_price'], $product['quantity'], $product['reorder_level'], $product['status'], $id]);
                flash('success', 'Product updated.');
            } else {
                $stmt = db()->prepare('INSERT INTO products (sku, name, category_id, unit, cost_price, selling_price, quantity, reorder_level, status) VALUES (?,?,?,?,?,?,?,?,?)');
                $stmt->execute([$product['sku'], $product['name'], $product['category_id'], $product['unit'], $product['cost_price'], $product['selling_price'], $product['quantity'], $product['reorder_level'], $product['status']]);
                $newId = (int)db()->lastInsertId();
                if ($product['quantity'] > 0) {
                    $stmt = db()->prepare("INSERT INTO stock_movements (product_id, type, quantity, reference, notes, created_by) VALUES (?, 'in', ?, 'Initial stock', 'Opening balance on product creation', ?)");
                    $stmt->execute([$newId, $product['quantity'], current_user()['id']]);
                }
                flash('success', 'Product created.');
            }
            redirect('/inventory/products.php');
        } catch (PDOException $e) {
            $error = str_contains($e->getMessage(), 'Duplicate') ? 'A product with this SKU already exists.' : 'Could not save product.';
        }
    }
}

$categories = db()->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();

$page_title = $id ? 'Edit Product' : 'Add Product';
require __DIR__ . '/../includes/header.php';
?>
<div class="card p-4" style="max-width:680px">
  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <div class="row g-3">
      <div class="col-sm-6">
        <label class="form-label">SKU</label>
        <input type="text" name="sku" class="form-control" required value="<?= e($product['sku']) ?>" <?= $id ? '' : '' ?>>
      </div>
      <div class="col-sm-6">
        <label class="form-label">Name</label>
        <input type="text" name="name" class="form-control" required value="<?= e($product['name']) ?>">
      </div>
      <div class="col-sm-6">
        <label class="form-label">Category</label>
        <select name="category_id" class="form-select">
          <option value="">— None —</option>
          <?php foreach ($categories as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= (string)$product['category_id'] === (string)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-6">
        <label class="form-label">Unit</label>
        <input type="text" name="unit" class="form-control" value="<?= e($product['unit']) ?>" placeholder="pcs, kg, box...">
      </div>
      <div class="col-sm-6">
        <label class="form-label">Cost Price</label>
        <input type="number" step="0.01" min="0" name="cost_price" class="form-control" value="<?= e($product['cost_price']) ?>">
      </div>
      <div class="col-sm-6">
        <label class="form-label">Selling Price</label>
        <input type="number" step="0.01" min="0" name="selling_price" class="form-control" value="<?= e($product['selling_price']) ?>">
      </div>
      <div class="col-sm-6">
        <label class="form-label">Quantity in stock<?= $id ? ' (use Stock Movements to adjust)' : '' ?></label>
        <input type="number" name="quantity" class="form-control" value="<?= e($product['quantity']) ?>" <?= $id ? 'readonly' : '' ?>>
      </div>
      <div class="col-sm-6">
        <label class="form-label">Reorder Level</label>
        <input type="number" name="reorder_level" class="form-control" value="<?= e($product['reorder_level']) ?>">
      </div>
      <div class="col-sm-6">
        <label class="form-label">Status</label>
        <select name="status" class="form-select">
          <option value="active" <?= $product['status'] === 'active' ? 'selected' : '' ?>>Active</option>
          <option value="inactive" <?= $product['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>
      </div>
    </div>
    <div class="page-actions mt-4">
      <button type="submit" class="btn btn-brand">Save</button>
      <a href="products.php" class="btn btn-outline-secondary">Cancel</a>
    </div>
  </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
