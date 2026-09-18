<?php
require_once __DIR__ . '/../includes/auth.php';
require_module_edit('procurement');

$id = (int)input('id');
$fromOrder = (int)input('from_order');
$defaultWarehouseId = default_warehouse_id();
$return = ['id' => 0, 'purchase_order_id' => 0, 'vendor_id' => '', 'warehouse_id' => $defaultWarehouseId, 'return_date' => today(), 'reason' => ''];
$savedQty = [];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM purchase_returns WHERE id = ?');
    $stmt->execute([$id]);
    $return = $stmt->fetch();
    if (!$return) {
        flash('danger', 'Purchase return not found.');
        redirect('/purchases/returns.php');
    }
    if ($return['status'] !== 'draft') {
        flash('danger', 'Only draft purchase returns can be edited.');
        redirect('/purchases/return_view.php?id=' . $id);
    }
    $fromOrder = (int)$return['purchase_order_id'];
    $stmt = db()->prepare('SELECT product_id, quantity FROM purchase_return_items WHERE purchase_return_id = ?');
    $stmt->execute([$id]);
    foreach ($stmt->fetchAll() as $row) {
        $savedQty[(int)$row['product_id']] = (int)$row['quantity'];
    }
} elseif (!$fromOrder) {
    flash('danger', 'A purchase return must be created from a purchase order.');
    redirect('/purchases/orders.php');
}

$poStmt = db()->prepare('SELECT po.*, v.name vendor_name FROM purchase_orders po JOIN vendors v ON v.id = po.vendor_id WHERE po.id = ?');
$poStmt->execute([$fromOrder]);
$order = $poStmt->fetch();
if (!$order) {
    flash('danger', 'Purchase order not found.');
    redirect('/purchases/orders.php');
}
$grnStmt = db()->prepare("SELECT id FROM goods_receipts WHERE purchase_order_id = ? AND status = 'received' LIMIT 1");
$grnStmt->execute([$fromOrder]);
if (!$grnStmt->fetchColumn()) {
    flash('danger', 'This purchase order has not been received yet — a Purchase Return can only be created once it has been received.');
    redirect('/purchases/order_view.php?id=' . $fromOrder);
}
$return['purchase_order_id'] = $fromOrder;
$return['vendor_id'] = $order['vendor_id'];

// Remaining returnable qty per product = received qty - already returned on
// this order by any OTHER non-cancelled return (this draft's own rows are
// excluded so editing it doesn't count itself as "already returned").
$returnedStmt = db()->prepare("SELECT pri.product_id, SUM(pri.quantity) qty FROM purchase_return_items pri JOIN purchase_returns pr ON pr.id = pri.purchase_return_id WHERE pr.purchase_order_id = ? AND pr.status <> 'cancelled' AND pr.id <> ? GROUP BY pri.product_id");
$returnedStmt->execute([$fromOrder, $id]);
$alreadyReturned = [];
foreach ($returnedStmt->fetchAll() as $row) {
    $alreadyReturned[(int)$row['product_id']] = (int)$row['qty'];
}

$poItemsStmt = db()->prepare('SELECT poi.*, p.name product_name, p.sku FROM purchase_order_items poi JOIN products p ON p.id = poi.product_id WHERE po_id = ?');
$poItemsStmt->execute([$fromOrder]);
$lines = [];
foreach ($poItemsStmt->fetchAll() as $oi) {
    $pid = (int)$oi['product_id'];
    $remaining = (int)$oi['quantity'] - ($alreadyReturned[$pid] ?? 0);
    if ($remaining <= 0 && !isset($savedQty[$pid])) continue;
    $lines[] = [
        'product_id' => $pid,
        'product_name' => $oi['product_name'],
        'sku' => $oi['sku'],
        'ordered_qty' => (int)$oi['quantity'],
        'unit_cost' => $oi['unit_cost'],
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
            $capError = 'Return quantity for ' . $li['product_name'] . ' cannot exceed ' . $li['remaining'] . ' (already returned or not yet received).';
            break;
        }
        $subtotal = $qty * $li['unit_cost'];
        $lineItems[] = ['product_id' => $li['product_id'], 'quantity' => $qty, 'unit_cost' => $li['unit_cost'], 'subtotal' => $subtotal];
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
                $pdo->prepare('UPDATE purchase_returns SET warehouse_id=?, return_date=?, reason=?, total_amount=? WHERE id=?')
                    ->execute([$warehouseId, $returnDate, $reason, $total, $id]);
                $pdo->prepare('DELETE FROM purchase_return_items WHERE purchase_return_id=?')->execute([$id]);
                $returnId = $id;
            } else {
                $returnNo = next_code('PR', 'purchase_returns', 'return_no');
                $pdo->prepare('INSERT INTO purchase_returns (return_no, purchase_order_id, vendor_id, warehouse_id, return_date, status, reason, total_amount, created_by) VALUES (?,?,?,?,?,?,?,?,?)')
                    ->execute([$returnNo, $fromOrder, $order['vendor_id'], $warehouseId, $returnDate, 'draft', $reason, $total, current_user()['id']]);
                $returnId = (int)$pdo->lastInsertId();
            }
            $itemStmt = $pdo->prepare('INSERT INTO purchase_return_items (purchase_return_id, product_id, quantity, unit_cost, subtotal) VALUES (?,?,?,?,?)');
            foreach ($lineItems as $li) {
                $itemStmt->execute([$returnId, $li['product_id'], $li['quantity'], $li['unit_cost'], $li['subtotal']]);
            }
            $pdo->commit();
            flash('success', $id ? 'Purchase return updated.' : 'Purchase return created as a draft.');
            redirect('/purchases/return_view.php?id=' . $returnId);
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Could not save purchase return.';
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

$page_title = $id ? 'Edit Purchase Return' : 'New Purchase Return';
require __DIR__ . '/../includes/header.php';
?>
<div class="card p-4">
  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
  <?php if (!$warehouses): ?><div class="alert alert-warning">No warehouses set up yet. <a href="<?= base_url('supply-chain/warehouse_form.php') ?>">Create one first</a>.</div><?php endif; ?>
  <div class="mb-3 text-muted">Against Purchase Order <a href="order_view.php?id=<?= (int)$fromOrder ?>"><?= e($order['po_no']) ?></a> &middot; <?= e($order['vendor_name']) ?></div>
  <form method="post">
    <?= csrf_field() ?>
    <div class="row g-3 mb-3">
      <div class="col-sm-4">
        <label class="form-label">Warehouse (stock returns from)</label>
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
        <thead><tr><th>Product</th><th class="text-end">Received</th><th class="text-end">Returnable</th><th class="text-end" style="width:15%">Return Qty</th><th class="text-end">Unit Cost</th></tr></thead>
        <tbody>
        <?php foreach ($lines as $li): ?>
          <tr>
            <td><?= e($li['product_name']) ?> <span class="text-muted small">(<?= e($li['sku']) ?>)</span></td>
            <td class="text-end"><?= (int)$li['ordered_qty'] ?></td>
            <td class="text-end"><?= (int)$li['remaining'] ?></td>
            <td class="text-end"><input type="number" min="0" max="<?= (int)$li['remaining'] ?>" class="form-control form-control-sm text-end" name="quantity[<?= (int)$li['product_id'] ?>]" value="<?= (int)$li['qty'] ?>"></td>
            <td class="text-end"><?= money($li['unit_cost']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$lines): ?><tr><td colspan="5" class="text-muted text-center">Every item on this order has already been fully returned.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="page-actions">
      <button type="submit" class="btn btn-brand">Save Purchase Return</button>
      <a href="<?= base_url('purchases/order_view.php?id=' . $fromOrder) ?>" class="btn btn-outline-secondary">Cancel</a>
    </div>
  </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
