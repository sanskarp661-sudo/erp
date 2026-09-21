<?php
require_once __DIR__ . '/../includes/auth.php';
require_module_edit('sales');

$id = (int)input('id');
$priceList = ['id' => 0, 'name' => '', 'currency' => setting('currency_code', 'INR'), 'is_default' => 0, 'status' => 'active'];
$rates = []; // product_id => rate

if ($id) {
    $stmt = db()->prepare('SELECT * FROM price_lists WHERE id = ?');
    $stmt->execute([$id]);
    $priceList = $stmt->fetch() ?: $priceList;
    $stmt = db()->prepare('SELECT product_id, rate FROM price_list_items WHERE price_list_id = ?');
    $stmt->execute([$id]);
    foreach ($stmt->fetchAll() as $r) {
        $rates[(int)$r['product_id']] = $r['rate'];
    }
}

$error = '';

if (is_post()) {
    csrf_verify();
    $name = input('name');
    $currency = strtoupper(input('currency')) ?: 'INR';
    $isDefault = input('is_default') ? 1 : 0;
    $status = input('status') === 'inactive' ? 'inactive' : 'active';
    $productIds = $_POST['rate_product_id'] ?? [];
    $rateValues = $_POST['rate_value'] ?? [];

    if ($name === '') {
        $error = 'Name is required.';
    } else {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($isDefault) {
                $pdo->exec('UPDATE price_lists SET is_default = 0');
            }
            if ($id) {
                $pdo->prepare('UPDATE price_lists SET name=?, currency=?, is_default=?, status=? WHERE id=?')
                    ->execute([$name, $currency, $isDefault, $status, $id]);
                $pdo->prepare('DELETE FROM price_list_items WHERE price_list_id=?')->execute([$id]);
                $plId = $id;
            } else {
                $pdo->prepare('INSERT INTO price_lists (name, currency, is_default, status) VALUES (?,?,?,?)')
                    ->execute([$name, $currency, $isDefault, $status]);
                $plId = (int)$pdo->lastInsertId();
            }
            $rateStmt = $pdo->prepare('INSERT INTO price_list_items (price_list_id, product_id, rate) VALUES (?,?,?)');
            foreach ($productIds as $i => $pid) {
                $pid = (int)$pid;
                $rate = (float)($rateValues[$i] ?? 0);
                if ($pid > 0 && $rate > 0) {
                    $rateStmt->execute([$plId, $pid, $rate]);
                }
            }
            $pdo->commit();
            flash('success', $id ? 'Price list updated.' : 'Price list created.');
            redirect('/sales/price_lists.php');
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Could not save price list.';
        }
    }
    $priceList = ['id' => $id, 'name' => $name, 'currency' => $currency, 'is_default' => $isDefault, 'status' => $status];
}

$products = db()->query("SELECT id, sku, name, selling_price FROM products WHERE status='active' ORDER BY name")->fetchAll();

$page_title = $id ? 'Edit Price List' : 'Add Price List';
require __DIR__ . '/../includes/header.php';
?>
<div class="card p-4">
  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $id ?>">
    <div class="row g-3 mb-3">
      <div class="col-sm-4">
        <label class="form-label">Name</label>
        <input type="text" name="name" class="form-control" required value="<?= e($priceList['name']) ?>">
      </div>
      <div class="col-sm-2">
        <label class="form-label">Currency</label>
        <input type="text" name="currency" class="form-control" maxlength="3" style="text-transform:uppercase" value="<?= e($priceList['currency']) ?>">
      </div>
      <div class="col-sm-2">
        <label class="form-label">Status</label>
        <select name="status" class="form-select">
          <option value="active" <?= $priceList['status'] === 'active' ? 'selected' : '' ?>>Active</option>
          <option value="inactive" <?= $priceList['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>
      </div>
      <div class="col-sm-4 d-flex align-items-end">
        <div class="form-check">
          <input type="checkbox" name="is_default" id="isDefault" class="form-check-input" value="1" <?= $priceList['is_default'] ? 'checked' : '' ?>>
          <label class="form-check-label" for="isDefault">Default price list (used as the fallback for new Sales Orders)</label>
        </div>
      </div>
    </div>

    <h6 class="mb-2">Item Rates <span class="text-muted small fw-normal">— leave blank to fall back to the item's standard selling price</span></h6>
    <div class="table-responsive mb-3">
      <table class="table table-sm">
        <thead><tr><th>Item</th><th>Standard Rate</th><th style="width:180px">Rate on this Price List</th></tr></thead>
        <tbody>
        <?php foreach ($products as $p): ?>
          <tr>
            <td><?= e($p['name']) ?> <span class="text-muted small">(<?= e($p['sku']) ?>)</span>
              <input type="hidden" name="rate_product_id[]" value="<?= (int)$p['id'] ?>">
            </td>
            <td class="text-muted"><?= money($p['selling_price']) ?></td>
            <td><input type="number" step="0.01" min="0" class="form-control form-control-sm" name="rate_value[]" placeholder="<?= e($p['selling_price']) ?>" value="<?= isset($rates[$p['id']]) ? e($rates[$p['id']]) : '' ?>"></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$products): ?><tr><td colspan="3" class="text-muted text-center">No active items yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="page-actions">
      <button type="submit" class="btn btn-brand">Save</button>
      <a href="price_lists.php" class="btn btn-outline-secondary">Cancel</a>
    </div>
  </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
