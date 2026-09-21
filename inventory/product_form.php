<?php
require_once __DIR__ . '/../includes/auth.php';
require_module_edit('inventory');

$id = (int)input('id');
$product = [
    'id' => 0, 'sku' => '', 'name' => '', 'image' => null, 'category_id' => '', 'unit' => 'pcs',
    'cost_price' => '0', 'selling_price' => '0', 'quantity' => '0', 'reorder_level' => '0', 'status' => 'active',
];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM products WHERE id = ?');
    $stmt->execute([$id]);
    $product = $stmt->fetch() ?: $product;
}
// Captured before any POST handling touches $product — quantity and the
// current image are never taken from client input on an edit; quantity
// only ever changes via Stock Movements (which keeps stock_bins and the
// products.quantity aggregate consistent), and the image only changes
// via a new upload or the explicit "remove" checkbox below.
$existingQuantity = $id ? (int)$product['quantity'] : 0;
$existingImage = $id ? $product['image'] : null;

$error = '';
$maxImageBytes = 3 * 1024 * 1024;
$mimeToExt = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

if (is_post()) {
    csrf_verify();
    $imagePath = $existingImage;

    if (!empty($_FILES['image']['name']) && ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['image'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $error = 'Image upload failed. Please try again.';
        } elseif ($file['size'] > $maxImageBytes) {
            $error = 'Image must be smaller than 3 MB.';
        } else {
            $info = @getimagesize($file['tmp_name']);
            if (!$info || !isset($mimeToExt[$info['mime']])) {
                $error = 'Please upload a valid JPG, PNG, WEBP, or GIF image.';
            } else {
                $destDir = __DIR__ . '/../uploads/products/';
                if (!is_dir($destDir)) mkdir($destDir, 0755, true);
                $filename = bin2hex(random_bytes(16)) . '.' . $mimeToExt[$info['mime']];
                if (move_uploaded_file($file['tmp_name'], $destDir . $filename)) {
                    if ($existingImage && is_file(__DIR__ . '/../' . $existingImage)) {
                        @unlink(__DIR__ . '/../' . $existingImage);
                    }
                    $imagePath = 'uploads/products/' . $filename;
                } else {
                    $error = 'Could not save the uploaded image.';
                }
            }
        }
    } elseif (input('remove_image') === '1') {
        if ($existingImage && is_file(__DIR__ . '/../' . $existingImage)) {
            @unlink(__DIR__ . '/../' . $existingImage);
        }
        $imagePath = null;
    }

    $openingQty = $id ? $existingQuantity : (int)input('opening_quantity');
    $openingWarehouseId = (int)input('opening_warehouse_id') ?: null;

    $product = [
        'id' => $id,
        'sku' => input('sku'),
        'name' => input('name'),
        'image' => $imagePath,
        'category_id' => input('category_id') ?: null,
        'unit' => input('unit') ?: 'pcs',
        'cost_price' => (float)input('cost_price'),
        'selling_price' => (float)input('selling_price'),
        'quantity' => $openingQty,
        'reorder_level' => (int)input('reorder_level'),
        'status' => in_array(input('status'), ['active', 'inactive'], true) ? input('status') : 'active',
    ];

    if ($error) {
        // Image error already set above.
    } elseif ($product['sku'] === '' || $product['name'] === '') {
        $error = 'SKU and Name are required.';
    } elseif (!$id && $openingQty > 0 && !$openingWarehouseId) {
        $error = 'Please select a warehouse for the opening stock quantity.';
    } else {
        $pdo = db();
        try {
            if ($id) {
                $stmt = $pdo->prepare('UPDATE products SET sku=?, name=?, image=?, category_id=?, unit=?, cost_price=?, selling_price=?, reorder_level=?, status=? WHERE id=?');
                $stmt->execute([$product['sku'], $product['name'], $product['image'], $product['category_id'], $product['unit'], $product['cost_price'], $product['selling_price'], $product['reorder_level'], $product['status'], $id]);
                flash('success', 'Product updated.');
            } else {
                $stmt = $pdo->prepare('INSERT INTO products (sku, name, image, category_id, unit, cost_price, selling_price, quantity, reorder_level, status) VALUES (?,?,?,?,?,?,?,0,?,?)');
                $stmt->execute([$product['sku'], $product['name'], $product['image'], $product['category_id'], $product['unit'], $product['cost_price'], $product['selling_price'], $product['reorder_level'], $product['status']]);
                $newId = (int)$pdo->lastInsertId();
                if ($openingQty > 0 && $openingWarehouseId) {
                    stock_move($newId, $openingWarehouseId, $openingQty, 'in', 'Initial stock', 'Opening balance on product creation', current_user()['id']);
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
$warehouses = leaf_warehouses();

$page_title = $id ? 'Edit Product' : 'Add Product';
require __DIR__ . '/../includes/header.php';
?>
<div class="card p-4" style="max-width:680px">
  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <div class="row g-3">
      <div class="col-sm-6">
        <label class="form-label">SKU</label>
        <input type="text" name="sku" class="form-control" required value="<?= e($product['sku']) ?>">
      </div>
      <div class="col-sm-6">
        <label class="form-label">Name</label>
        <input type="text" name="name" class="form-control" required value="<?= e($product['name']) ?>">
      </div>
      <div class="col-sm-12">
        <label class="form-label">Photo</label>
        <?php if (!empty($existingImage)): ?>
          <div class="d-flex align-items-center gap-3 mb-2">
            <img src="<?= base_url($existingImage) ?>" alt="" style="width:80px;height:80px;object-fit:cover;border-radius:8px;border:1px solid #dee2e6">
            <div class="form-check">
              <input type="checkbox" class="form-check-input" id="removeImage" name="remove_image" value="1">
              <label class="form-check-label" for="removeImage">Remove current photo</label>
            </div>
          </div>
        <?php endif; ?>
        <input type="file" name="image" class="form-control" accept="image/jpeg,image/png,image/webp,image/gif">
        <div class="form-text">JPG, PNG, WEBP or GIF, up to 3 MB.</div>
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
      <?php if ($id): ?>
      <div class="col-sm-6">
        <label class="form-label">Quantity in stock <span class="text-muted">(use Stock Movements to adjust)</span></label>
        <input type="number" class="form-control" value="<?= (int)$existingQuantity ?>" readonly>
      </div>
      <?php else: ?>
      <div class="col-sm-6">
        <label class="form-label">Opening Quantity</label>
        <input type="number" min="0" name="opening_quantity" class="form-control" value="<?= e($product['quantity']) ?>">
      </div>
      <div class="col-sm-6">
        <label class="form-label">Opening Warehouse <span class="text-muted">(required if quantity &gt; 0)</span></label>
        <select name="opening_warehouse_id" class="form-select">
          <option value="">— Select warehouse —</option>
          <?php foreach ($warehouses as $w): ?>
            <option value="<?= (int)$w['id'] ?>" <?= (string)input('opening_warehouse_id') === (string)$w['id'] ? 'selected' : '' ?>><?= e($w['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
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
