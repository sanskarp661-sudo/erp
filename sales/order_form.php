<?php
require_once __DIR__ . '/../includes/auth.php';
require_module_edit('sales');

$id = (int)input('id');
$order = ['id' => 0, 'order_no' => '', 'customer_id' => '', 'order_date' => today(), 'notes' => ''];
$items = [];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM sales_orders WHERE id = ?');
    $stmt->execute([$id]);
    $order = $stmt->fetch();
    if (!$order) {
        flash('danger', 'Sales order not found.');
        redirect('/sales/orders.php');
    }
    if ($order['status'] !== 'pending') {
        flash('danger', 'Only orders still in "pending" status can be edited.');
        redirect('/sales/order_view.php?id=' . $id);
    }
    $stmt = db()->prepare('SELECT * FROM sales_order_items WHERE order_id = ?');
    $stmt->execute([$id]);
    $items = $stmt->fetchAll();
}

$error = '';

if (is_post()) {
    csrf_verify();
    $customerId = (int)input('customer_id');
    $orderDate = input('order_date') ?: today();
    $notes = input('notes');
    $productIds = $_POST['product_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $prices = $_POST['unit_price'] ?? [];

    $lineItems = [];
    $total = 0;
    foreach ($productIds as $i => $pid) {
        $pid = (int)$pid;
        $qty = (int)($quantities[$i] ?? 0);
        $price = (float)($prices[$i] ?? 0);
        if ($pid > 0 && $qty > 0) {
            $subtotal = $qty * $price;
            $lineItems[] = ['product_id' => $pid, 'quantity' => $qty, 'unit_price' => $price, 'subtotal' => $subtotal];
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
                $pdo->prepare('UPDATE sales_orders SET customer_id=?, order_date=?, notes=?, total_amount=? WHERE id=?')
                    ->execute([$customerId, $orderDate, $notes, $total, $id]);
                $pdo->prepare('DELETE FROM sales_order_items WHERE order_id=?')->execute([$id]);
                $orderId = $id;
            } else {
                $orderNo = next_code('SO', 'sales_orders', 'order_no');
                $pdo->prepare('INSERT INTO sales_orders (order_no, customer_id, order_date, status, notes, total_amount, created_by) VALUES (?,?,?,?,?,?,?)')
                    ->execute([$orderNo, $customerId, $orderDate, 'pending', $notes, $total, current_user()['id']]);
                $orderId = (int)$pdo->lastInsertId();
            }
            $itemStmt = $pdo->prepare('INSERT INTO sales_order_items (order_id, product_id, quantity, unit_price, subtotal) VALUES (?,?,?,?,?)');
            foreach ($lineItems as $li) {
                $itemStmt->execute([$orderId, $li['product_id'], $li['quantity'], $li['unit_price'], $li['subtotal']]);
            }
            $pdo->commit();
            flash('success', $id ? 'Sales order updated.' : 'Sales order created.');
            redirect('/sales/order_view.php?id=' . $orderId);
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Could not save sales order.';
        }
    }

    $order = ['id' => $id, 'order_no' => $order['order_no'] ?? '', 'customer_id' => $customerId, 'order_date' => $orderDate, 'notes' => $notes];
    $items = $lineItems;
}

$customers = db()->query('SELECT id, name FROM customers ORDER BY name')->fetchAll();
$products = db()->query("SELECT id, sku, name, selling_price, quantity, unit FROM products WHERE status='active' ORDER BY name")->fetchAll();

$page_title = $id ? 'Edit Sales Order' : 'New Sales Order';
require __DIR__ . '/../includes/header.php';
?>
<div class="card p-4">
  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <div class="row g-3 mb-3">
      <div class="col-sm-5">
        <label class="form-label">Customer</label>
        <select name="customer_id" class="form-select" required>
          <option value="">— Select customer —</option>
          <?php foreach ($customers as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= (string)$order['customer_id'] === (string)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
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

    <div class="line-items" data-total-target="#soTotal">
      <div class="table-responsive">
        <table class="table">
          <thead><tr><th style="width:38%">Product</th><th style="width:15%">Qty</th><th style="width:18%">Unit Price</th><th style="width:18%" class="text-end">Subtotal</th><th></th></tr></thead>
          <tbody>
          <?php if (!$items): $items = [['product_id' => '', 'quantity' => 1, 'unit_price' => 0]]; endif; ?>
          <?php foreach ($items as $it): ?>
            <tr data-row>
              <td>
                <select class="form-select js-product" name="product_id[]">
                  <option value="">— Select product —</option>
                  <?php foreach ($products as $p): ?>
                    <option value="<?= (int)$p['id'] ?>" data-price="<?= e($p['selling_price']) ?>" <?= (string)$it['product_id'] === (string)$p['id'] ? 'selected' : '' ?>>
                      <?= e($p['name']) ?> (<?= e($p['sku']) ?>) — <?= (int)$p['quantity'] ?> <?= e($p['unit']) ?> in stock
                    </option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td><input type="number" min="1" class="form-control js-qty" name="quantity[]" value="<?= e($it['quantity']) ?>"></td>
              <td><input type="number" step="0.01" min="0" class="form-control js-price" name="unit_price[]" value="<?= e($it['unit_price']) ?>"></td>
              <td class="text-end js-subtotal">0.00</td>
              <td><button type="button" class="btn btn-sm btn-outline-danger js-remove-row"><i class="fa-solid fa-xmark"></i></button></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <button type="button" class="btn btn-sm btn-outline-brand mb-3 js-add-row"><i class="fa-solid fa-plus"></i> Add line</button>
      <div class="text-end fs-5 mb-3">Total: <strong id="soTotal">0.00</strong></div>
    </div>

    <div class="page-actions">
      <button type="submit" class="btn btn-brand">Save Order</button>
      <a href="orders.php" class="btn btn-outline-secondary">Cancel</a>
    </div>
  </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
