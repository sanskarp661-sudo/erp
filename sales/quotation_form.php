<?php
require_once __DIR__ . '/../includes/auth.php';
require_module_edit('sales');

$id = (int)input('id');
$quotation = ['id' => 0, 'customer_id' => '', 'quotation_date' => today(), 'valid_till' => date('Y-m-d', strtotime('+30 days')), 'notes' => ''];
$items = [];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM quotations WHERE id = ?');
    $stmt->execute([$id]);
    $quotation = $stmt->fetch();
    if (!$quotation) {
        flash('danger', 'Quotation not found.');
        redirect('/sales/quotations.php');
    }
    if ($quotation['status'] !== 'draft') {
        flash('danger', 'Only draft quotations can be edited.');
        redirect('/sales/quotation_view.php?id=' . $id);
    }
    $stmt = db()->prepare('SELECT * FROM quotation_items WHERE quotation_id = ?');
    $stmt->execute([$id]);
    $items = $stmt->fetchAll();
}

$error = '';

if (is_post()) {
    csrf_verify();
    $customerId = (int)input('customer_id');
    $quotationDate = input('quotation_date') ?: today();
    $validTill = input('valid_till') ?: null;
    $notes = input('notes');
    $productIds = $_POST['product_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $lineUoms = $_POST['uom'] ?? [];
    $prices = $_POST['unit_price'] ?? [];

    $lineItems = [];
    $total = 0;
    foreach ($productIds as $i => $pid) {
        $pid = (int)$pid;
        $qty = (int)($quantities[$i] ?? 0);
        $price = (float)($prices[$i] ?? 0);
        if ($pid > 0 && $qty > 0) {
            $subtotal = $qty * $price;
            $uom = trim($lineUoms[$i] ?? '') ?: null;
            $factor = $uom !== null ? uom_conversion_factor($pid, $uom) : 1.0;
            $lineItems[] = ['product_id' => $pid, 'quantity' => $qty, 'uom' => $uom ?? 'pcs', 'uom_conversion_factor' => $factor, 'unit_price' => $price, 'subtotal' => $subtotal];
            $total += $subtotal;
        }
    }

    if (!$customerId) {
        $error = 'Please select a customer.';
    } elseif (!$lineItems) {
        $error = 'Please add at least one valid line item.';
    } else {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($id) {
                $pdo->prepare('UPDATE quotations SET customer_id=?, quotation_date=?, valid_till=?, notes=?, total_amount=? WHERE id=?')
                    ->execute([$customerId, $quotationDate, $validTill, $notes, $total, $id]);
                $pdo->prepare('DELETE FROM quotation_items WHERE quotation_id=?')->execute([$id]);
                $qId = $id;
            } else {
                $qNo = next_code('QTN', 'quotations', 'quotation_no');
                $pdo->prepare("INSERT INTO quotations (quotation_no, customer_id, quotation_date, valid_till, status, notes, total_amount, created_by) VALUES (?,?,?,?,'draft',?,?,?)")
                    ->execute([$qNo, $customerId, $quotationDate, $validTill, $notes, $total, current_user()['id']]);
                $qId = (int)$pdo->lastInsertId();
            }
            $itemStmt = $pdo->prepare('INSERT INTO quotation_items (quotation_id, product_id, quantity, uom, uom_conversion_factor, unit_price, subtotal) VALUES (?,?,?,?,?,?,?)');
            foreach ($lineItems as $li) {
                $itemStmt->execute([$qId, $li['product_id'], $li['quantity'], $li['uom'], $li['uom_conversion_factor'], $li['unit_price'], $li['subtotal']]);
            }
            $pdo->commit();
            flash('success', $id ? 'Quotation updated.' : 'Quotation created.');
            redirect('/sales/quotation_view.php?id=' . $qId);
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Could not save quotation.';
        }
    }

    $quotation = ['id' => $id, 'customer_id' => $customerId, 'quotation_date' => $quotationDate, 'valid_till' => $validTill, 'notes' => $notes];
    $items = $lineItems;
}

$customers = db()->query('SELECT id, name FROM customers ORDER BY name')->fetchAll();
$products = db()->query("SELECT id, sku, name, selling_price, quantity, unit FROM products WHERE status='active' ORDER BY name")->fetchAll();
$productUomsByProduct = [];
foreach (db()->query('SELECT product_id, uom, conversion_factor FROM product_uoms ORDER BY sort_order, id') as $r) {
    $productUomsByProduct[(int)$r['product_id']][] = ['uom' => $r['uom'], 'factor' => (float)$r['conversion_factor']];
}
$productUomJson = [];
foreach ($products as $p) {
    $map = [$p['unit'] => 1.0];
    foreach ($productUomsByProduct[(int)$p['id']] ?? [] as $u) {
        $map[$u['uom']] = $u['factor'];
    }
    $productUomJson[(int)$p['id']] = json_encode($map);
}

$page_title = $id ? 'Edit Quotation' : 'New Quotation';
require __DIR__ . '/../includes/header.php';
?>
<div class="card p-4">
  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $id ?>">
    <div class="row g-3 mb-3">
      <div class="col-sm-4">
        <label class="form-label">Customer</label>
        <select name="customer_id" class="form-select" required>
          <option value="">— Select customer —</option>
          <?php foreach ($customers as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= (string)$quotation['customer_id'] === (string)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-3">
        <label class="form-label">Quotation Date</label>
        <input type="date" name="quotation_date" class="form-control" value="<?= e($quotation['quotation_date']) ?>" required>
      </div>
      <div class="col-sm-3">
        <label class="form-label">Valid Till</label>
        <input type="date" name="valid_till" class="form-control" value="<?= e($quotation['valid_till'] ?? '') ?>">
      </div>
      <div class="col-sm-2">
        <label class="form-label">Notes</label>
        <input type="text" name="notes" class="form-control" value="<?= e($quotation['notes'] ?? '') ?>">
      </div>
    </div>

    <div class="line-items" data-total-target="#qTotal">
      <div class="table-responsive">
        <table class="table">
          <thead><tr><th style="width:32%">Product</th><th style="width:12%">Qty</th><th style="width:12%">UOM</th><th style="width:16%">Unit Price</th><th style="width:18%" class="text-end">Subtotal</th><th></th></tr></thead>
          <tbody>
          <?php if (!$items): $items = [['product_id' => '', 'quantity' => 1, 'uom' => '', 'unit_price' => 0]]; endif; ?>
          <?php foreach ($items as $it): ?>
            <tr data-row>
              <td>
                <select class="form-select js-product" name="product_id[]">
                  <option value="">— Select product —</option>
                  <?php foreach ($products as $p): ?>
                    <option value="<?= (int)$p['id'] ?>" data-price="<?= e($p['selling_price']) ?>" data-uoms='<?= e($productUomJson[(int)$p['id']]) ?>' <?= (string)$it['product_id'] === (string)$p['id'] ? 'selected' : '' ?>>
                      <?= e($p['name']) ?> (<?= e($p['sku']) ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td><input type="number" min="1" class="form-control js-qty" name="quantity[]" value="<?= e($it['quantity']) ?>"></td>
              <td>
                <select class="form-select js-uom" name="uom[]">
                  <?php if ($it['product_id']): foreach ($productUomJson[(int)$it['product_id']] ? json_decode($productUomJson[(int)$it['product_id']], true) : [] as $uomName => $factor): ?>
                    <option value="<?= e($uomName) ?>" <?= (string)($it['uom'] ?? '') === (string)$uomName ? 'selected' : '' ?>><?= e($uomName) ?></option>
                  <?php endforeach; endif; ?>
                </select>
              </td>
              <td><input type="number" step="0.01" min="0" class="form-control js-price" name="unit_price[]" value="<?= e($it['unit_price']) ?>"></td>
              <td class="text-end js-subtotal">0.00</td>
              <td><button type="button" class="btn btn-sm btn-outline-danger js-remove-row"><i class="fa-solid fa-xmark"></i></button></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <button type="button" class="btn btn-sm btn-outline-brand mb-3 js-add-row"><i class="fa-solid fa-plus"></i> Add line</button>
      <div class="text-end fs-5 mb-3">Total: <strong id="qTotal">0.00</strong></div>
    </div>

    <div class="page-actions">
      <button type="submit" class="btn btn-brand">Save Quotation</button>
      <a href="quotations.php" class="btn btn-outline-secondary">Cancel</a>
    </div>
  </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
