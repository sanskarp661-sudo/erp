<?php
require_once __DIR__ . '/../includes/auth.php';
require_module_edit('procurement');

$id = (int)input('id');
$fromOrder = (int)input('from_order');
$defaultWarehouseId = default_warehouse_id();
$grn = ['id' => 0, 'purchase_order_id' => $fromOrder ?: null, 'vendor_id' => '', 'warehouse_id' => $defaultWarehouseId, 'posting_date' => today(), 'notes' => ''];
$items = [];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM goods_receipts WHERE id = ?');
    $stmt->execute([$id]);
    $grn = $stmt->fetch();
    if (!$grn) {
        flash('danger', 'Goods receipt not found.');
        redirect('/purchases/grns.php');
    }
    if ($grn['status'] !== 'draft') {
        flash('danger', 'Only draft goods receipts can be edited.');
        redirect('/purchases/grn_view.php?id=' . $id);
    }
    $stmt = db()->prepare('SELECT * FROM goods_receipt_items WHERE grn_id = ?');
    $stmt->execute([$id]);
    $items = $stmt->fetchAll();
} elseif ($fromOrder) {
    $stmt = db()->prepare('SELECT * FROM purchase_orders WHERE id = ?');
    $stmt->execute([$fromOrder]);
    $order = $stmt->fetch();
    if (!$order || !in_array($order['status'], ['ordered', 'received'], true)) {
        flash('danger', 'That purchase order is not ready to be received.');
        redirect('/purchases/orders.php');
    }
    $existing = db()->prepare('SELECT id FROM goods_receipts WHERE purchase_order_id = ?');
    $existing->execute([$fromOrder]);
    if ($existing->fetchColumn()) {
        flash('danger', 'A goods receipt already exists for that purchase order.');
        redirect('/purchases/order_view.php?id=' . $fromOrder);
    }
    $grn['vendor_id'] = $order['vendor_id'];
    $stmt = db()->prepare('SELECT poi.*, p.name product_name FROM purchase_order_items poi JOIN products p ON p.id = poi.product_id WHERE po_id = ?');
    $stmt->execute([$fromOrder]);
    foreach ($stmt->fetchAll() as $oi) {
        $items[] = ['product_id' => $oi['product_id'], 'quantity' => $oi['quantity'], 'unit_cost' => $oi['unit_cost']];
    }
}

$error = '';

if (is_post()) {
    csrf_verify();
    $vendorId = (int)input('vendor_id');
    $warehouseId = (int)input('warehouse_id');
    $purchaseOrderId = input('purchase_order_id') ?: null;
    $postingDate = input('posting_date') ?: today();
    $notes = input('notes');
    $productIds = $_POST['product_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $costs = $_POST['unit_cost'] ?? [];

    $lineItems = [];
    $total = 0;
    foreach ($productIds as $i => $pid) {
        $pid = (int)$pid;
        $qty = (int)($quantities[$i] ?? 0);
        $cost = (float)($costs[$i] ?? 0);
        if ($pid > 0 && $qty > 0) {
            $subtotal = $qty * $cost;
            $lineItems[] = ['product_id' => $pid, 'quantity' => $qty, 'unit_cost' => $cost, 'subtotal' => $subtotal];
            $total += $subtotal;
        }
    }

    if (!$vendorId) {
        $error = 'Please select a vendor.';
    } elseif (!$warehouseId) {
        $error = 'Please select a warehouse.';
    } elseif (!$lineItems) {
        $error = 'Please add at least one valid line item.';
    } else {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($id) {
                $pdo->prepare('UPDATE goods_receipts SET vendor_id=?, warehouse_id=?, purchase_order_id=?, posting_date=?, notes=?, total_amount=? WHERE id=?')
                    ->execute([$vendorId, $warehouseId, $purchaseOrderId, $postingDate, $notes, $total, $id]);
                $pdo->prepare('DELETE FROM goods_receipt_items WHERE grn_id=?')->execute([$id]);
                $grnId = $id;
            } else {
                $grnNo = next_code('GRN', 'goods_receipts', 'grn_no');
                $pdo->prepare('INSERT INTO goods_receipts (grn_no, purchase_order_id, vendor_id, warehouse_id, posting_date, status, notes, total_amount, created_by) VALUES (?,?,?,?,?,?,?,?,?)')
                    ->execute([$grnNo, $purchaseOrderId, $vendorId, $warehouseId, $postingDate, 'draft', $notes, $total, current_user()['id']]);
                $grnId = (int)$pdo->lastInsertId();
            }
            $itemStmt = $pdo->prepare('INSERT INTO goods_receipt_items (grn_id, product_id, quantity, unit_cost, subtotal) VALUES (?,?,?,?,?)');
            foreach ($lineItems as $li) {
                $itemStmt->execute([$grnId, $li['product_id'], $li['quantity'], $li['unit_cost'], $li['subtotal']]);
            }
            $pdo->commit();
            flash('success', $id ? 'Goods receipt updated.' : 'Goods receipt created.');
            redirect('/purchases/grn_view.php?id=' . $grnId);
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Could not save goods receipt.';
        }
    }

    $grn = ['id' => $id, 'purchase_order_id' => $purchaseOrderId, 'vendor_id' => $vendorId, 'warehouse_id' => $warehouseId, 'posting_date' => $postingDate, 'notes' => $notes];
    $items = $lineItems;
}

$vendors = db()->query('SELECT id, name FROM vendors ORDER BY name')->fetchAll();
$products = db()->query("SELECT id, sku, name, cost_price, unit FROM products WHERE status='active' ORDER BY name")->fetchAll();
$warehouses = leaf_warehouses();

$page_title = $id ? 'Edit Goods Receipt' : 'New Goods Receipt';
require __DIR__ . '/../includes/header.php';
?>
<div class="card p-4">
  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
  <?php if (!$warehouses): ?><div class="alert alert-warning">No warehouses set up yet. <a href="<?= base_url('supply-chain/warehouse_form.php') ?>">Create one first</a>.</div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="purchase_order_id" value="<?= e($grn['purchase_order_id'] ?? '') ?>">
    <div class="row g-3 mb-3">
      <div class="col-sm-4">
        <label class="form-label">Vendor</label>
        <select name="vendor_id" class="form-select" required <?= $grn['purchase_order_id'] ? 'disabled' : '' ?>>
          <option value="">— Select vendor —</option>
          <?php foreach ($vendors as $v): ?>
            <option value="<?= (int)$v['id'] ?>" <?= (string)$grn['vendor_id'] === (string)$v['id'] ? 'selected' : '' ?>><?= e($v['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if ($grn['purchase_order_id']): ?><input type="hidden" name="vendor_id" value="<?= e($grn['vendor_id']) ?>"><?php endif; ?>
      </div>
      <div class="col-sm-3">
        <label class="form-label">Warehouse</label>
        <select name="warehouse_id" class="form-select" required>
          <option value="">— Select warehouse —</option>
          <?php foreach ($warehouses as $w): ?>
            <option value="<?= (int)$w['id'] ?>" <?= (string)$grn['warehouse_id'] === (string)$w['id'] ? 'selected' : '' ?>><?= e($w['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-3">
        <label class="form-label">Posting Date</label>
        <input type="date" name="posting_date" class="form-control" value="<?= e($grn['posting_date']) ?>" required>
      </div>
      <div class="col-sm-2">
        <label class="form-label">Notes</label>
        <input type="text" name="notes" class="form-control" value="<?= e($grn['notes'] ?? '') ?>">
      </div>
    </div>

    <div class="line-items" data-total-target="#grnTotal">
      <div class="table-responsive">
        <table class="table">
          <thead><tr><th style="width:38%">Product</th><th style="width:15%">Qty</th><th style="width:18%">Unit Cost</th><th style="width:18%" class="text-end">Subtotal</th><th></th></tr></thead>
          <tbody>
          <?php if (!$items): $items = [['product_id' => '', 'quantity' => 1, 'unit_cost' => 0]]; endif; ?>
          <?php foreach ($items as $it): ?>
            <tr data-row>
              <td>
                <select class="form-select js-product" name="product_id[]">
                  <option value="">— Select product —</option>
                  <?php foreach ($products as $p): ?>
                    <option value="<?= (int)$p['id'] ?>" data-price="<?= e($p['cost_price']) ?>" <?= (string)$it['product_id'] === (string)$p['id'] ? 'selected' : '' ?>>
                      <?= e($p['name']) ?> (<?= e($p['sku']) ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td><input type="number" min="1" class="form-control js-qty" name="quantity[]" value="<?= e($it['quantity']) ?>"></td>
              <td><input type="number" step="0.01" min="0" class="form-control js-price" name="unit_cost[]" value="<?= e($it['unit_cost']) ?>"></td>
              <td class="text-end js-subtotal">0.00</td>
              <td><button type="button" class="btn btn-sm btn-outline-danger js-remove-row"><i class="fa-solid fa-xmark"></i></button></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <button type="button" class="btn btn-sm btn-outline-brand mb-3 js-add-row"><i class="fa-solid fa-plus"></i> Add line</button>
      <div class="text-end fs-5 mb-3">Total: <strong id="grnTotal">0.00</strong></div>
    </div>

    <div class="page-actions">
      <button type="submit" class="btn btn-brand">Save Goods Receipt</button>
      <a href="grns.php" class="btn btn-outline-secondary">Cancel</a>
    </div>
  </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
