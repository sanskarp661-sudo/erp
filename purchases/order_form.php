<?php
require_once __DIR__ . '/../includes/auth.php';
require_module_edit('procurement');

$id = (int)input('id');
$order = ['id' => 0, 'po_no' => '', 'vendor_id' => '', 'order_date' => today(), 'notes' => ''];
$items = [];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM purchase_orders WHERE id = ?');
    $stmt->execute([$id]);
    $order = $stmt->fetch();
    if (!$order) {
        flash('danger', 'Purchase order not found.');
        redirect('/purchases/orders.php');
    }
    if ($order['status'] !== 'pending') {
        flash('danger', 'Only orders still in "pending" status can be edited.');
        redirect('/purchases/order_view.php?id=' . $id);
    }
    $stmt = db()->prepare('SELECT * FROM purchase_order_items WHERE po_id = ?');
    $stmt->execute([$id]);
    $items = $stmt->fetchAll();
}

$error = '';

if (is_post()) {
    csrf_verify();
    $vendorId = (int)input('vendor_id');
    $orderDate = input('order_date') ?: today();
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
    } elseif (!$lineItems) {
        $error = 'Please add at least one valid line item.';
    } else {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($id) {
                $pdo->prepare('UPDATE purchase_orders SET vendor_id=?, order_date=?, notes=?, total_amount=? WHERE id=?')
                    ->execute([$vendorId, $orderDate, $notes, $total, $id]);
                $pdo->prepare('DELETE FROM purchase_order_items WHERE po_id=?')->execute([$id]);
                $poId = $id;
            } else {
                $poNo = next_code('PO', 'purchase_orders', 'po_no');
                $pdo->prepare('INSERT INTO purchase_orders (po_no, vendor_id, order_date, status, notes, total_amount, created_by) VALUES (?,?,?,?,?,?,?)')
                    ->execute([$poNo, $vendorId, $orderDate, 'pending', $notes, $total, current_user()['id']]);
                $poId = (int)$pdo->lastInsertId();
            }
            $itemStmt = $pdo->prepare('INSERT INTO purchase_order_items (po_id, product_id, quantity, unit_cost, subtotal) VALUES (?,?,?,?,?)');
            foreach ($lineItems as $li) {
                $itemStmt->execute([$poId, $li['product_id'], $li['quantity'], $li['unit_cost'], $li['subtotal']]);
            }
            $pdo->commit();
            flash('success', $id ? 'Purchase order updated.' : 'Purchase order created.');
            redirect('/purchases/order_view.php?id=' . $poId);
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Could not save purchase order.';
        }
    }

    $order = ['id' => $id, 'po_no' => $order['po_no'] ?? '', 'vendor_id' => $vendorId, 'order_date' => $orderDate, 'notes' => $notes];
    $items = $lineItems;
}

$vendors = db()->query('SELECT id, name FROM vendors ORDER BY name')->fetchAll();
$products = db()->query("SELECT id, sku, name, cost_price, unit FROM products WHERE status='active' ORDER BY name")->fetchAll();

$page_title = $id ? 'Edit Purchase Order' : 'New Purchase Order';
require __DIR__ . '/../includes/header.php';
?>
<div class="card p-4">
  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <div class="row g-3 mb-3">
      <div class="col-sm-5">
        <label class="form-label">Vendor</label>
        <select name="vendor_id" class="form-select" required>
          <option value="">— Select vendor —</option>
          <?php foreach ($vendors as $v): ?>
            <option value="<?= (int)$v['id'] ?>" <?= (string)$order['vendor_id'] === (string)$v['id'] ? 'selected' : '' ?>><?= e($v['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-3">
        <label class="form-label">Order Date</label>
        <input type="date" name="order_date" class="form-control" value="<?= e($order['order_date']) ?>" required>
      </div>
      <div class="col-sm-4">
        <label class="form-label">Notes</label>
        <input type="text" name="notes" class="form-control" value="<?= e($order['notes'] ?? '') ?>">
      </div>
    </div>

    <div class="line-items" data-total-target="#poTotal">
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
      <div class="text-end fs-5 mb-3">Total: <strong id="poTotal">0.00</strong></div>
    </div>

    <div class="page-actions">
      <button type="submit" class="btn btn-brand">Save Purchase Order</button>
      <a href="orders.php" class="btn btn-outline-secondary">Cancel</a>
    </div>
  </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
