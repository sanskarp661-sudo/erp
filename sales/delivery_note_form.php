<?php
require_once __DIR__ . '/../includes/auth.php';
require_module_edit('sales');

$id = (int)input('id');
$fromOrder = (int)input('from_order');
$defaultWarehouseId = default_warehouse_id();
$dn = ['id' => 0, 'sales_order_id' => $fromOrder ?: null, 'customer_id' => '', 'warehouse_id' => $defaultWarehouseId, 'posting_date' => today(), 'notes' => ''];
$items = [];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM delivery_notes WHERE id = ?');
    $stmt->execute([$id]);
    $dn = $stmt->fetch();
    if (!$dn) {
        flash('danger', 'Delivery note not found.');
        redirect('/sales/delivery_notes.php');
    }
    if ($dn['status'] !== 'draft') {
        flash('danger', 'Only draft delivery notes can be edited.');
        redirect('/sales/delivery_note_view.php?id=' . $id);
    }
    $stmt = db()->prepare('SELECT * FROM delivery_note_items WHERE dn_id = ?');
    $stmt->execute([$id]);
    $items = $stmt->fetchAll();
} elseif ($fromOrder) {
    $stmt = db()->prepare('SELECT * FROM sales_orders WHERE id = ?');
    $stmt->execute([$fromOrder]);
    $order = $stmt->fetch();
    if (!$order || !in_array($order['status'], ['confirmed', 'shipped'], true)) {
        flash('danger', 'That sales order is not ready for delivery.');
        redirect('/sales/orders.php');
    }
    $existing = db()->prepare('SELECT id FROM delivery_notes WHERE sales_order_id = ?');
    $existing->execute([$fromOrder]);
    if ($existing->fetchColumn()) {
        flash('danger', 'A delivery note already exists for that sales order.');
        redirect('/sales/order_view.php?id=' . $fromOrder);
    }
    $dn['customer_id'] = $order['customer_id'];
    $stmt = db()->prepare('SELECT soi.*, p.name product_name FROM sales_order_items soi JOIN products p ON p.id = soi.product_id WHERE order_id = ?');
    $stmt->execute([$fromOrder]);
    foreach ($stmt->fetchAll() as $oi) {
        $items[] = ['product_id' => $oi['product_id'], 'quantity' => $oi['quantity'], 'uom' => $oi['uom'], 'unit_price' => $oi['unit_price']];
    }
}

$error = '';

if (is_post()) {
    csrf_verify();
    $customerId = (int)input('customer_id');
    $warehouseId = (int)input('warehouse_id');
    $salesOrderId = input('sales_order_id') ?: null;
    $postingDate = input('posting_date') ?: today();
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
    } elseif (!$warehouseId) {
        $error = 'Please select a warehouse.';
    } elseif (!$lineItems) {
        $error = 'Please add at least one valid line item.';
    } else {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($id) {
                $pdo->prepare('UPDATE delivery_notes SET customer_id=?, warehouse_id=?, sales_order_id=?, posting_date=?, notes=?, total_amount=? WHERE id=?')
                    ->execute([$customerId, $warehouseId, $salesOrderId, $postingDate, $notes, $total, $id]);
                $pdo->prepare('DELETE FROM delivery_note_items WHERE dn_id=?')->execute([$id]);
                $dnId = $id;
            } else {
                $dnNo = next_code('DN', 'delivery_notes', 'dn_no');
                $pdo->prepare('INSERT INTO delivery_notes (dn_no, sales_order_id, customer_id, warehouse_id, posting_date, status, notes, total_amount, created_by) VALUES (?,?,?,?,?,?,?,?,?)')
                    ->execute([$dnNo, $salesOrderId, $customerId, $warehouseId, $postingDate, 'draft', $notes, $total, current_user()['id']]);
                $dnId = (int)$pdo->lastInsertId();
            }
            $itemStmt = $pdo->prepare('INSERT INTO delivery_note_items (dn_id, product_id, quantity, uom, uom_conversion_factor, unit_price, subtotal) VALUES (?,?,?,?,?,?,?)');
            foreach ($lineItems as $li) {
                $itemStmt->execute([$dnId, $li['product_id'], $li['quantity'], $li['uom'], $li['uom_conversion_factor'], $li['unit_price'], $li['subtotal']]);
            }
            $pdo->commit();
            flash('success', $id ? 'Delivery note updated.' : 'Delivery note created.');
            redirect('/sales/delivery_note_view.php?id=' . $dnId);
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Could not save delivery note.';
        }
    }

    $dn = ['id' => $id, 'sales_order_id' => $salesOrderId, 'customer_id' => $customerId, 'warehouse_id' => $warehouseId, 'posting_date' => $postingDate, 'notes' => $notes];
    $items = $lineItems;
}

$customers = db()->query('SELECT id, name FROM customers ORDER BY name')->fetchAll();
$products = db()->query("SELECT id, sku, name, selling_price, quantity, unit FROM products WHERE status='active' ORDER BY name")->fetchAll();
$warehouses = leaf_warehouses();
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

$page_title = $id ? 'Edit Delivery Note' : 'New Delivery Note';
require __DIR__ . '/../includes/header.php';
?>
<div class="card p-4">
  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
  <?php if (!$warehouses): ?><div class="alert alert-warning">No warehouses set up yet. <a href="<?= base_url('supply-chain/warehouse_form.php') ?>">Create one first</a>.</div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="sales_order_id" value="<?= e($dn['sales_order_id'] ?? '') ?>">
    <div class="row g-3 mb-3">
      <div class="col-sm-4">
        <label class="form-label">Customer</label>
        <select name="customer_id" class="form-select" required <?= $dn['sales_order_id'] ? 'disabled' : '' ?>>
          <option value="">— Select customer —</option>
          <?php foreach ($customers as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= (string)$dn['customer_id'] === (string)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if ($dn['sales_order_id']): ?><input type="hidden" name="customer_id" value="<?= e($dn['customer_id']) ?>"><?php endif; ?>
      </div>
      <div class="col-sm-3">
        <label class="form-label">Warehouse</label>
        <select name="warehouse_id" class="form-select" required>
          <option value="">— Select warehouse —</option>
          <?php foreach ($warehouses as $w): ?>
            <option value="<?= (int)$w['id'] ?>" <?= (string)$dn['warehouse_id'] === (string)$w['id'] ? 'selected' : '' ?>><?= e($w['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-3">
        <label class="form-label">Posting Date</label>
        <input type="date" name="posting_date" class="form-control" value="<?= e($dn['posting_date']) ?>" required>
      </div>
      <div class="col-sm-2">
        <label class="form-label">Notes</label>
        <input type="text" name="notes" class="form-control" value="<?= e($dn['notes'] ?? '') ?>">
      </div>
    </div>

    <div class="line-items" data-total-target="#dnTotal">
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
                      <?= e($p['name']) ?> (<?= e($p['sku']) ?>) — <?= (int)$p['quantity'] ?> <?= e($p['unit']) ?> in stock (all warehouses)
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
      <div class="text-end fs-5 mb-3">Total: <strong id="dnTotal">0.00</strong></div>
    </div>

    <div class="page-actions">
      <button type="submit" class="btn btn-brand">Save Delivery Note</button>
      <a href="delivery_notes.php" class="btn btn-outline-secondary">Cancel</a>
    </div>
  </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
