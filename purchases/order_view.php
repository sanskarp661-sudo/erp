<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$id = (int)input('id');
$stmt = db()->prepare('SELECT po.*, v.name vendor_name, v.email vendor_email, v.phone vendor_phone FROM purchase_orders po JOIN vendors v ON v.id = po.vendor_id WHERE po.id = ?');
$stmt->execute([$id]);
$order = $stmt->fetch();

if (!$order) {
    flash('danger', 'Purchase order not found.');
    redirect('/purchases/orders.php');
}

if (is_post() && input('action') === 'transition') {
    csrf_verify();
    $newStatus = input('status');
    $valid = [
        'pending' => ['ordered', 'cancelled'],
        'ordered' => ['received', 'cancelled'],
    ];
    if (!isset($valid[$order['status']]) || !in_array($newStatus, $valid[$order['status']], true)) {
        flash('danger', 'That status change is not allowed.');
        redirect('/purchases/order_view.php?id=' . $id);
    }

    $itemsStmt = db()->prepare('SELECT * FROM purchase_order_items WHERE po_id = ?');
    $itemsStmt->execute([$id]);
    $orderItems = $itemsStmt->fetchAll();

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($newStatus === 'received') {
            foreach ($orderItems as $it) {
                $pdo->prepare('UPDATE products SET quantity = quantity + ? WHERE id = ?')->execute([$it['quantity'], $it['product_id']]);
                $pdo->prepare("INSERT INTO stock_movements (product_id, type, quantity, reference, notes, created_by) VALUES (?, 'in', ?, ?, 'Purchase order received', ?)")
                    ->execute([$it['product_id'], $it['quantity'], $order['po_no'], current_user()['id']]);
            }
        }
        $pdo->prepare('UPDATE purchase_orders SET status = ? WHERE id = ?')->execute([$newStatus, $id]);
        $pdo->commit();
        flash('success', 'Purchase order status updated to "' . $newStatus . '".');
    } catch (Exception $e) {
        $pdo->rollBack();
        flash('danger', 'Could not update purchase order status.');
    }
    redirect('/purchases/order_view.php?id=' . $id);
}

$items = db()->prepare('SELECT poi.*, p.name product_name, p.sku FROM purchase_order_items poi JOIN products p ON p.id = poi.product_id WHERE po_id = ?');
$items->execute([$id]);
$items = $items->fetchAll();

$badge = ['pending' => 'secondary', 'ordered' => 'info', 'received' => 'success', 'cancelled' => 'danger'];

$page_title = 'Purchase Order ' . $order['po_no'];
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-1"><?= e($order['po_no']) ?> <span class="badge text-bg-<?= $badge[$order['status']] ?> badge-status"><?= e($order['status']) ?></span></h4>
    <div class="text-muted"><?= e($order['vendor_name']) ?> &middot; <?= e($order['order_date']) ?></div>
  </div>
  <div class="page-actions">
    <?php if ($order['status'] === 'pending'): ?>
      <a href="order_form.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-pen"></i> Edit</a>
      <form method="post" class="d-inline" data-confirm="Mark this order as ordered?">
        <?= csrf_field() ?><input type="hidden" name="action" value="transition"><input type="hidden" name="status" value="ordered">
        <button class="btn btn-brand btn-sm" type="submit"><i class="fa-solid fa-paper-plane"></i> Mark Ordered</button>
      </form>
      <form method="post" class="d-inline" data-confirm="Cancel this purchase order?">
        <?= csrf_field() ?><input type="hidden" name="action" value="transition"><input type="hidden" name="status" value="cancelled">
        <button class="btn btn-outline-danger btn-sm" type="submit"><i class="fa-solid fa-ban"></i> Cancel</button>
      </form>
    <?php elseif ($order['status'] === 'ordered'): ?>
      <form method="post" class="d-inline" data-confirm="Receive this order? Stock will be added.">
        <?= csrf_field() ?><input type="hidden" name="action" value="transition"><input type="hidden" name="status" value="received">
        <button class="btn btn-brand btn-sm" type="submit"><i class="fa-solid fa-box-open"></i> Mark Received</button>
      </form>
      <form method="post" class="d-inline" data-confirm="Cancel this purchase order?">
        <?= csrf_field() ?><input type="hidden" name="action" value="transition"><input type="hidden" name="status" value="cancelled">
        <button class="btn btn-outline-danger btn-sm" type="submit"><i class="fa-solid fa-ban"></i> Cancel</button>
      </form>
    <?php endif; ?>
    <a href="<?= base_url('print.php?doctype=purchase_order&id=' . $id) ?>" target="_blank" class="btn btn-outline-brand btn-sm"><i class="fa-solid fa-print"></i> Print</a>
    <a href="orders.php" class="btn btn-outline-secondary btn-sm">Back to list</a>
  </div>
</div>

<div class="card p-3">
  <div class="table-responsive">
    <table class="table">
      <thead><tr><th>Product</th><th class="text-end">Qty</th><th class="text-end">Unit Cost</th><th class="text-end">Subtotal</th></tr></thead>
      <tbody>
      <?php foreach ($items as $it): ?>
        <tr>
          <td><?= e($it['product_name']) ?> <span class="text-muted small">(<?= e($it['sku']) ?>)</span></td>
          <td class="text-end"><?= (int)$it['quantity'] ?></td>
          <td class="text-end"><?= money($it['unit_cost']) ?></td>
          <td class="text-end"><?= money($it['subtotal']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr><th colspan="3" class="text-end">Total</th><th class="text-end"><?= money($order['total_amount']) ?></th></tr>
      </tfoot>
    </table>
  </div>
  <?php if ($order['notes']): ?><div class="mt-2"><strong>Notes:</strong> <?= e($order['notes']) ?></div><?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
