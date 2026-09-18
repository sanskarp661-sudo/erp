<?php
require_once __DIR__ . '/../includes/auth.php';
require_module_edit('sales');

$id = (int)input('id');
$fromOrder = (int)input('from_order');
$defaultWarehouseId = default_warehouse_id();
$return = ['id' => 0, 'sales_order_id' => 0, 'customer_id' => '', 'warehouse_id' => $defaultWarehouseId, 'return_date' => today(), 'reason' => ''];
$savedQty = [];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM sales_returns WHERE id = ?');
    $stmt->execute([$id]);
    $return = $stmt->fetch();
    if (!$return) {
        flash('danger', 'Sales return not found.');
        redirect('/sales/returns.php');
    }
    if ($return['status'] !== 'draft') {
        flash('danger', 'Only draft sales returns can be edited.');
        redirect('/sales/return_view.php?id=' . $id);
    }
    $fromOrder = (int)$return['sales_order_id'];
    $stmt = db()->prepare('SELECT product_id, quantity FROM sales_return_items WHERE sales_return_id = ?');
    $stmt->execute([$id]);
    foreach ($stmt->fetchAll() as $row) {
        $savedQty[(int)$row['product_id']] = (int)$row['quantity'];
    }
} elseif (!$fromOrder) {
    flash('danger', 'A sales return must be created from a sales order.');
    redirect('/sales/orders.php');
}

$soStmt = db()->prepare('SELECT so.*, c.name customer_name FROM sales_orders so JOIN customers c ON c.id = so.customer_id WHERE so.id = ?');
$soStmt->execute([$fromOrder]);
$order = $soStmt->fetch();
if (!$order) {
    flash('danger', 'Sales order not found.');
    redirect('/sales/orders.php');
}
$dnStmt = db()->prepare("SELECT id FROM delivery_notes WHERE sales_order_id = ? AND status = 'delivered' LIMIT 1");
$dnStmt->execute([$fromOrder]);
if (!$dnStmt->fetchColumn()) {
    flash('danger', 'This sales order has not been delivered yet — a Sales Return can only be created once it has been delivered.');
    redirect('/sales/order_view.php?id=' . $fromOrder);
}
$return['sales_order_id'] = $fromOrder;
$return['customer_id'] = $order['customer_id'];

// Remaining returnable qty per product = ordered qty - already returned on
// this order by any OTHER non-cancelled return (this draft's own rows are
// excluded so editing it doesn't count itself as "already returned").
$returnedStmt = db()->prepare("SELECT sri.product_id, SUM(sri.quantity) qty FROM sales_return_items sri JOIN sales_returns sr ON sr.id = sri.sales_return_id WHERE sr.sales_order_id = ? AND sr.status <> 'cancelled' AND sr.id <> ? GROUP BY sri.product_id");
$returnedStmt->execute([$fromOrder, $id]);
$alreadyReturned = [];
foreach ($returnedStmt->fetchAll() as $row) {
    $alreadyReturned[(int)$row['product_id']] = (int)$row['qty'];
}

$soItemsStmt = db()->prepare('SELECT soi.*, p.name product_name, p.sku FROM sales_order_items soi JOIN products p ON p.id = soi.product_id WHERE order_id = ?');
$soItemsStmt->execute([$fromOrder]);
$lines = [];
foreach ($soItemsStmt->fetchAll() as $oi) {
    $pid = (int)$oi['product_id'];
    $remaining = (int)$oi['quantity'] - ($alreadyReturned[$pid] ?? 0);
    if ($remaining <= 0 && !isset($savedQty[$pid])) continue;
    $lines[] = [
        'product_id' => $pid,
        'product_name' => $oi['product_name'],
        'sku' => $oi['sku'],
        'ordered_qty' => (int)$oi['quantity'],
        'unit_price' => $oi['unit_price'],
        'remaining' => $remaining,
        'qty' => $savedQty[$pid] ?? 0,
    ];
}

$error = '';

if (is_post()) {
    csrf_verify();
    $warehouseId = (int)input('warehouse_id');
    $returnDate = input('return_date') ?: today();
    $reason = input('reason');
    $submittedQty = $_POST['quantity'] ?? [];

    $lineItems = [];
    $total = 0;
    $capError = '';
    foreach ($lines as $li) {
        $qty = (int)($submittedQty[$li['product_id']] ?? 0);
        if ($qty <= 0) continue;
        if ($qty > $li['remaining']) {
            $capError = 'Return quantity for ' . $li['product_name'] . ' cannot exceed ' . $li['remaining'] . ' (already returned or not yet delivered).';
            break;
        }
        $subtotal = $qty * $li['unit_price'];
        $lineItems[] = ['product_id' => $li['product_id'], 'quantity' => $qty, 'unit_price' => $li['unit_price'], 'subtotal' => $subtotal];
        $total += $subtotal;
    }

    if (!$warehouseId) {
        $error = 'Please select a warehouse.';
    } elseif ($capError) {
        $error = $capError;
    } elseif (!$lineItems) {
        $error = 'Please enter a return quantity for at least one item.';
    } else {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($id) {
                $pdo->prepare('UPDATE sales_returns SET warehouse_id=?, return_date=?, reason=?, total_amount=? WHERE id=?')
                    ->execute([$warehouseId, $returnDate, $reason, $total, $id]);
                $pdo->prepare('DELETE FROM sales_return_items WHERE sales_return_id=?')->execute([$id]);
                $returnId = $id;
            } else {
                $returnNo = next_code('SR', 'sales_returns', 'return_no');
                $pdo->prepare('INSERT INTO sales_returns (return_no, sales_order_id, customer_id, warehouse_id, return_date, status, reason, total_amount, created_by) VALUES (?,?,?,?,?,?,?,?,?)')
                    ->execute([$returnNo, $fromOrder, $order['customer_id'], $warehouseId, $returnDate, 'draft', $reason, $total, current_user()['id']]);
                $returnId = (int)$pdo->lastInsertId();
            }
            $itemStmt = $pdo->prepare('INSERT INTO sales_return_items (sales_return_id, product_id, quantity, unit_price, subtotal) VALUES (?,?,?,?,?)');
            foreach ($lineItems as $li) {
                $itemStmt->execute([$returnId, $li['product_id'], $li['quantity'], $li['unit_price'], $li['subtotal']]);
            }
            $pdo->commit();
            flash('success', $id ? 'Sales return updated.' : 'Sales return created as a draft.');
            redirect('/sales/return_view.php?id=' . $returnId);
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Could not save sales return.';
        }
    }

    $return['warehouse_id'] = $warehouseId;
    $return['return_date'] = $returnDate;
    $return['reason'] = $reason;
    foreach ($lines as &$li) {
        $li['qty'] = (int)($submittedQty[$li['product_id']] ?? 0);
    }
    unset($li);
}

$warehouses = leaf_warehouses();

$page_title = $id ? 'Edit Sales Return' : 'New Sales Return';
require __DIR__ . '/../includes/header.php';
?>
<div class="card p-4">
  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
  <?php if (!$warehouses): ?><div class="alert alert-warning">No warehouses set up yet. <a href="<?= base_url('supply-chain/warehouse_form.php') ?>">Create one first</a>.</div><?php endif; ?>
  <div class="mb-3 text-muted">Against Sales Order <a href="order_view.php?id=<?= (int)$fromOrder ?>"><?= e($order['order_no']) ?></a> &middot; <?= e($order['customer_name']) ?></div>
  <form method="post">
    <?= csrf_field() ?>
    <div class="row g-3 mb-3">
      <div class="col-sm-4">
        <label class="form-label">Warehouse (stock returns into)</label>
        <select name="warehouse_id" class="form-select" required>
          <option value="">— Select warehouse —</option>
          <?php foreach ($warehouses as $w): ?>
            <option value="<?= (int)$w['id'] ?>" <?= (string)$return['warehouse_id'] === (string)$w['id'] ? 'selected' : '' ?>><?= e($w['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-3">
        <label class="form-label">Return Date</label>
        <input type="date" name="return_date" class="form-control" value="<?= e($return['return_date']) ?>" required>
      </div>
      <div class="col-sm-5">
        <label class="form-label">Reason</label>
        <input type="text" name="reason" class="form-control" value="<?= e($return['reason'] ?? '') ?>" placeholder="e.g. damaged goods, wrong item">
      </div>
    </div>

    <div class="table-responsive">
      <table class="table">
        <thead><tr><th>Product</th><th class="text-end">Ordered</th><th class="text-end">Returnable</th><th class="text-end" style="width:15%">Return Qty</th><th class="text-end">Unit Price</th></tr></thead>
        <tbody>
        <?php foreach ($lines as $li): ?>
          <tr>
            <td><?= e($li['product_name']) ?> <span class="text-muted small">(<?= e($li['sku']) ?>)</span></td>
            <td class="text-end"><?= (int)$li['ordered_qty'] ?></td>
            <td class="text-end"><?= (int)$li['remaining'] ?></td>
            <td class="text-end"><input type="number" min="0" max="<?= (int)$li['remaining'] ?>" class="form-control form-control-sm text-end" name="quantity[<?= (int)$li['product_id'] ?>]" value="<?= (int)$li['qty'] ?>"></td>
            <td class="text-end"><?= money($li['unit_price']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$lines): ?><tr><td colspan="5" class="text-muted text-center">Every item on this order has already been fully returned.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="page-actions">
      <button type="submit" class="btn btn-brand">Save Sales Return</button>
      <a href="<?= base_url('sales/order_view.php?id=' . $fromOrder) ?>" class="btn btn-outline-secondary">Cancel</a>
    </div>
  </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
