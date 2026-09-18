<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
$canEdit = can_edit_module('sales');
$canManage = can_manage_module('sales');

$id = (int)input('id');
$stmt = db()->prepare('SELECT so.*, c.name customer_name, c.email customer_email, c.phone customer_phone FROM sales_orders so JOIN customers c ON c.id = so.customer_id WHERE so.id = ?');
$stmt->execute([$id]);
$order = $stmt->fetch();

if (!$order) {
    flash('danger', 'Sales order not found.');
    redirect('/sales/orders.php');
}

if (is_post() && input('action') === 'transition') {
    $newStatus = input('status');
    if ($newStatus === 'cancelled') {
        require_module_manage('sales');
    } else {
        require_module_edit('sales');
    }
    csrf_verify();
    $valid = [
        'pending'   => ['confirmed', 'cancelled'],
        'confirmed' => ['shipped', 'cancelled'],
        'shipped'   => ['completed', 'cancelled'],
    ];
    if (!isset($valid[$order['status']]) || !in_array($newStatus, $valid[$order['status']], true)) {
        flash('danger', 'That status change is not allowed.');
        redirect('/sales/order_view.php?id=' . $id);
    }

    // Sales orders no longer touch stock directly — only a Delivery Note
    // (created separately, once confirmed/shipped) actually deducts it.
    try {
        db()->prepare('UPDATE sales_orders SET status = ? WHERE id = ?')->execute([$newStatus, $id]);
        flash('success', 'Order status updated to "' . $newStatus . '".');
    } catch (Exception $e) {
        flash('danger', 'Could not update order status.');
    }
    redirect('/sales/order_view.php?id=' . $id);
}

$items = db()->prepare('SELECT soi.*, p.name product_name, p.sku FROM sales_order_items soi JOIN products p ON p.id = soi.product_id WHERE order_id = ?');
$items->execute([$id]);
$items = $items->fetchAll();

$dnStmt = db()->prepare('SELECT id, dn_no, status FROM delivery_notes WHERE sales_order_id = ? LIMIT 1');
$dnStmt->execute([$id]);
$existingDn = $dnStmt->fetch();

$badge = ['pending' => 'secondary', 'confirmed' => 'info', 'shipped' => 'primary', 'completed' => 'success', 'cancelled' => 'danger'];

$page_title = 'Sales Order ' . $order['order_no'];
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-1"><?= e($order['order_no']) ?> <span class="badge text-bg-<?= $badge[$order['status']] ?> badge-status"><?= e($order['status']) ?></span></h4>
    <div class="text-muted"><?= e($order['customer_name']) ?> &middot; <?= e($order['order_date']) ?></div>
  </div>
  <div class="page-actions">
    <?php if ($order['status'] === 'pending'): ?>
      <?php if ($canEdit): ?>
      <a href="order_form.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-pen"></i> Edit</a>
      <form method="post" class="d-inline" data-confirm="Confirm this order?">
        <?= csrf_field() ?><input type="hidden" name="action" value="transition"><input type="hidden" name="status" value="confirmed">
        <button class="btn btn-brand btn-sm" type="submit"><i class="fa-solid fa-check"></i> Confirm Order</button>
      </form>
      <?php endif; ?>
      <?php if ($canManage): ?>
      <form method="post" class="d-inline" data-confirm="Cancel this order?">
        <?= csrf_field() ?><input type="hidden" name="action" value="transition"><input type="hidden" name="status" value="cancelled">
        <button class="btn btn-outline-danger btn-sm" type="submit"><i class="fa-solid fa-ban"></i> Cancel</button>
      </form>
      <?php endif; ?>
    <?php elseif ($order['status'] === 'confirmed'): ?>
      <?php if ($canEdit): ?>
      <form method="post" class="d-inline" data-confirm="Mark this order as shipped?">
        <?= csrf_field() ?><input type="hidden" name="action" value="transition"><input type="hidden" name="status" value="shipped">
        <button class="btn btn-brand btn-sm" type="submit"><i class="fa-solid fa-truck"></i> Mark Shipped</button>
      </form>
      <?php endif; ?>
      <?php if ($canManage): ?>
      <form method="post" class="d-inline" data-confirm="Cancel this order?">
        <?= csrf_field() ?><input type="hidden" name="action" value="transition"><input type="hidden" name="status" value="cancelled">
        <button class="btn btn-outline-danger btn-sm" type="submit"><i class="fa-solid fa-ban"></i> Cancel</button>
      </form>
      <?php endif; ?>
    <?php elseif ($order['status'] === 'shipped'): ?>
      <?php if ($canEdit): ?>
      <form method="post" class="d-inline" data-confirm="Mark this order as completed?">
        <?= csrf_field() ?><input type="hidden" name="action" value="transition"><input type="hidden" name="status" value="completed">
        <button class="btn btn-brand btn-sm" type="submit"><i class="fa-solid fa-flag-checkered"></i> Mark Completed</button>
      </form>
      <?php endif; ?>
      <?php if ($canManage): ?>
      <form method="post" class="d-inline" data-confirm="Cancel this order?">
        <?= csrf_field() ?><input type="hidden" name="action" value="transition"><input type="hidden" name="status" value="cancelled">
        <button class="btn btn-outline-danger btn-sm" type="submit"><i class="fa-solid fa-ban"></i> Cancel</button>
      </form>
      <?php endif; ?>
    <?php endif; ?>

    <?php if (in_array($order['status'], ['confirmed', 'shipped'], true)): ?>
      <?php if ($existingDn): ?>
        <a href="<?= base_url('sales/delivery_note_view.php?id=' . $existingDn['id']) ?>" class="btn btn-outline-brand btn-sm"><i class="fa-solid fa-truck"></i> View Delivery Note <?= e($existingDn['dn_no']) ?></a>
      <?php elseif ($canEdit): ?>
        <a href="<?= base_url('sales/delivery_note_form.php?from_order=' . $id) ?>" class="btn btn-outline-brand btn-sm"><i class="fa-solid fa-truck"></i> Create Delivery Note</a>
      <?php endif; ?>
    <?php endif; ?>
    <a href="<?= base_url('print.php?doctype=sales_order&id=' . $id) ?>" target="_blank" class="btn btn-outline-brand btn-sm"><i class="fa-solid fa-print"></i> Print</a>
    <a href="orders.php" class="btn btn-outline-secondary btn-sm">Back to list</a>
  </div>
</div>

<div class="card p-3">
  <div class="table-responsive">
    <table class="table">
      <thead><tr><th>Product</th><th class="text-end">Qty</th><th class="text-end">Unit Price</th><th class="text-end">Subtotal</th></tr></thead>
      <tbody>
      <?php foreach ($items as $it): ?>
        <tr>
          <td><?= e($it['product_name']) ?> <span class="text-muted small">(<?= e($it['sku']) ?>)</span></td>
          <td class="text-end"><?= (int)$it['quantity'] ?></td>
          <td class="text-end"><?= money($it['unit_price']) ?></td>
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
