<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$id = (int)input('id');
$stmt = db()->prepare('SELECT so.*, c.name customer_name, c.email customer_email, c.phone customer_phone FROM sales_orders so JOIN customers c ON c.id = so.customer_id WHERE so.id = ?');
$stmt->execute([$id]);
$order = $stmt->fetch();

if (!$order) {
    flash('danger', 'Sales order not found.');
    redirect('/sales/orders.php');
}

if (is_post() && input('action') === 'transition') {
    csrf_verify();
    $newStatus = input('status');
    $valid = [
        'pending'   => ['confirmed', 'cancelled'],
        'confirmed' => ['shipped', 'cancelled'],
        'shipped'   => ['completed', 'cancelled'],
    ];
    if (!isset($valid[$order['status']]) || !in_array($newStatus, $valid[$order['status']], true)) {
        flash('danger', 'That status change is not allowed.');
        redirect('/sales/order_view.php?id=' . $id);
    }

    $itemsStmt = db()->prepare('SELECT * FROM sales_order_items WHERE order_id = ?');
    $itemsStmt->execute([$id]);
    $orderItems = $itemsStmt->fetchAll();

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($newStatus === 'confirmed') {
            // Deduct stock now that the order is confirmed.
            foreach ($orderItems as $it) {
                $p = $pdo->prepare('SELECT quantity, name, unit FROM products WHERE id = ? FOR UPDATE');
                $p->execute([$it['product_id']]);
                $prod = $p->fetch();
                if (!$prod || $prod['quantity'] < $it['quantity']) {
                    throw new RuntimeException('Insufficient stock for ' . ($prod['name'] ?? 'a product') . '.');
                }
            }
            foreach ($orderItems as $it) {
                $pdo->prepare('UPDATE products SET quantity = quantity - ? WHERE id = ?')->execute([$it['quantity'], $it['product_id']]);
                $pdo->prepare("INSERT INTO stock_movements (product_id, type, quantity, reference, notes, created_by) VALUES (?, 'out', ?, ?, 'Sales order confirmed', ?)")
                    ->execute([$it['product_id'], -$it['quantity'], $order['order_no'], current_user()['id']]);
            }
        } elseif ($newStatus === 'cancelled' && in_array($order['status'], ['confirmed', 'shipped'], true)) {
            // Restock: stock was already deducted when confirmed.
            foreach ($orderItems as $it) {
                $pdo->prepare('UPDATE products SET quantity = quantity + ? WHERE id = ?')->execute([$it['quantity'], $it['product_id']]);
                $pdo->prepare("INSERT INTO stock_movements (product_id, type, quantity, reference, notes, created_by) VALUES (?, 'in', ?, ?, 'Sales order cancelled', ?)")
                    ->execute([$it['product_id'], $it['quantity'], $order['order_no'], current_user()['id']]);
            }
        }

        $pdo->prepare('UPDATE sales_orders SET status = ? WHERE id = ?')->execute([$newStatus, $id]);
        $pdo->commit();
        flash('success', 'Order status updated to "' . $newStatus . '".');
    } catch (Exception $e) {
        $pdo->rollBack();
        flash('danger', $e->getMessage() ?: 'Could not update order status.');
    }
    redirect('/sales/order_view.php?id=' . $id);
}

$items = db()->prepare('SELECT soi.*, p.name product_name, p.sku FROM sales_order_items soi JOIN products p ON p.id = soi.product_id WHERE order_id = ?');
$items->execute([$id]);
$items = $items->fetchAll();

$invoiceStmt = db()->prepare('SELECT id, invoice_no FROM invoices WHERE sales_order_id = ? LIMIT 1');
$invoiceStmt->execute([$id]);
$existingInvoice = $invoiceStmt->fetch();

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
      <a href="order_form.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-pen"></i> Edit</a>
      <form method="post" class="d-inline" data-confirm="Confirm this order? Stock will be deducted.">
        <?= csrf_field() ?><input type="hidden" name="action" value="transition"><input type="hidden" name="status" value="confirmed">
        <button class="btn btn-brand btn-sm" type="submit"><i class="fa-solid fa-check"></i> Confirm Order</button>
      </form>
      <form method="post" class="d-inline" data-confirm="Cancel this order?">
        <?= csrf_field() ?><input type="hidden" name="action" value="transition"><input type="hidden" name="status" value="cancelled">
        <button class="btn btn-outline-danger btn-sm" type="submit"><i class="fa-solid fa-ban"></i> Cancel</button>
      </form>
    <?php elseif ($order['status'] === 'confirmed'): ?>
      <form method="post" class="d-inline" data-confirm="Mark this order as shipped?">
        <?= csrf_field() ?><input type="hidden" name="action" value="transition"><input type="hidden" name="status" value="shipped">
        <button class="btn btn-brand btn-sm" type="submit"><i class="fa-solid fa-truck"></i> Mark Shipped</button>
      </form>
      <form method="post" class="d-inline" data-confirm="Cancel this order? Stock will be restored.">
        <?= csrf_field() ?><input type="hidden" name="action" value="transition"><input type="hidden" name="status" value="cancelled">
        <button class="btn btn-outline-danger btn-sm" type="submit"><i class="fa-solid fa-ban"></i> Cancel</button>
      </form>
    <?php elseif ($order['status'] === 'shipped'): ?>
      <form method="post" class="d-inline" data-confirm="Mark this order as completed?">
        <?= csrf_field() ?><input type="hidden" name="action" value="transition"><input type="hidden" name="status" value="completed">
        <button class="btn btn-brand btn-sm" type="submit"><i class="fa-solid fa-flag-checkered"></i> Mark Completed</button>
      </form>
      <form method="post" class="d-inline" data-confirm="Cancel this order? Stock will be restored.">
        <?= csrf_field() ?><input type="hidden" name="action" value="transition"><input type="hidden" name="status" value="cancelled">
        <button class="btn btn-outline-danger btn-sm" type="submit"><i class="fa-solid fa-ban"></i> Cancel</button>
      </form>
    <?php endif; ?>

    <?php if (in_array($order['status'], ['confirmed', 'shipped', 'completed'], true)): ?>
      <?php if ($existingInvoice): ?>
        <a href="<?= base_url('accounting/invoice_view.php?id=' . $existingInvoice['id']) ?>" class="btn btn-outline-brand btn-sm"><i class="fa-solid fa-file-invoice-dollar"></i> View Invoice <?= e($existingInvoice['invoice_no']) ?></a>
      <?php else: ?>
        <a href="<?= base_url('accounting/invoice_form.php?from_order=' . $id) ?>" class="btn btn-outline-brand btn-sm"><i class="fa-solid fa-file-invoice-dollar"></i> Create Invoice</a>
      <?php endif; ?>
    <?php endif; ?>
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
