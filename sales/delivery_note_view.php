<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
$canEdit = can_edit_module('sales');
$canManage = can_manage_module('sales');

$id = (int)input('id');
$stmt = db()->prepare('SELECT dn.*, c.name customer_name, c.email customer_email, c.phone customer_phone, w.name warehouse_name FROM delivery_notes dn JOIN customers c ON c.id = dn.customer_id JOIN warehouses w ON w.id = dn.warehouse_id WHERE dn.id = ?');
$stmt->execute([$id]);
$dn = $stmt->fetch();

if (!$dn) {
    flash('danger', 'Delivery note not found.');
    redirect('/sales/delivery_notes.php');
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
        'draft'     => ['delivered', 'cancelled'],
        'delivered' => ['cancelled'],
    ];
    if (!isset($valid[$dn['status']]) || !in_array($newStatus, $valid[$dn['status']], true)) {
        flash('danger', 'That status change is not allowed.');
        redirect('/sales/delivery_note_view.php?id=' . $id);
    }

    $itemsStmt = db()->prepare('SELECT * FROM delivery_note_items WHERE dn_id = ?');
    $itemsStmt->execute([$id]);
    $dnItems = $itemsStmt->fetchAll();

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($newStatus === 'delivered') {
            foreach ($dnItems as $it) {
                $stockQty = (int)round($it['quantity'] * $it['uom_conversion_factor']);
                stock_move($it['product_id'], $dn['warehouse_id'], -$stockQty, 'out', $dn['dn_no'], 'Delivery note', current_user()['id']);
            }
        } elseif ($newStatus === 'cancelled' && $dn['status'] === 'delivered') {
            // Reverse the delivery: stock was deducted when delivered.
            foreach ($dnItems as $it) {
                $stockQty = (int)round($it['quantity'] * $it['uom_conversion_factor']);
                stock_move($it['product_id'], $dn['warehouse_id'], $stockQty, 'in', $dn['dn_no'], 'Delivery note cancelled', current_user()['id']);
            }
        }
        $pdo->prepare('UPDATE delivery_notes SET status = ? WHERE id = ?')->execute([$newStatus, $id]);
        $pdo->commit();
        flash('success', 'Delivery note status updated to "' . $newStatus . '".');
    } catch (Exception $e) {
        $pdo->rollBack();
        flash('danger', $e->getMessage() ?: 'Could not update delivery note status.');
    }
    redirect('/sales/delivery_note_view.php?id=' . $id);
}

$items = db()->prepare('SELECT dni.*, p.name product_name, p.sku FROM delivery_note_items dni JOIN products p ON p.id = dni.product_id WHERE dn_id = ?');
$items->execute([$id]);
$items = $items->fetchAll();

$existingInvoice = null;
if ($dn['status'] === 'delivered') {
    $stmt = db()->prepare('SELECT id, invoice_no FROM invoices WHERE delivery_note_id = ? LIMIT 1');
    $stmt->execute([$id]);
    $existingInvoice = $stmt->fetch();
}

$badge = ['draft' => 'secondary', 'delivered' => 'success', 'cancelled' => 'danger'];

$page_title = 'Delivery Note ' . $dn['dn_no'];
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-1"><?= e($dn['dn_no']) ?> <span class="badge text-bg-<?= $badge[$dn['status']] ?> badge-status"><?= e($dn['status']) ?></span></h4>
    <div class="text-muted"><?= e($dn['customer_name']) ?> &middot; <?= e($dn['warehouse_name']) ?> &middot; <?= e($dn['posting_date']) ?></div>
  </div>
  <div class="page-actions">
    <?php if ($dn['status'] === 'draft'): ?>
      <?php if ($canEdit): ?>
      <a href="delivery_note_form.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-pen"></i> Edit</a>
      <form method="post" class="d-inline" data-confirm="Mark this delivery note as delivered? Stock will be deducted.">
        <?= csrf_field() ?><input type="hidden" name="action" value="transition"><input type="hidden" name="status" value="delivered">
        <button class="btn btn-brand btn-sm" type="submit"><i class="fa-solid fa-truck"></i> Mark Delivered</button>
      </form>
      <?php endif; ?>
      <?php if ($canManage): ?>
      <form method="post" class="d-inline" data-confirm="Cancel this delivery note?">
        <?= csrf_field() ?><input type="hidden" name="action" value="transition"><input type="hidden" name="status" value="cancelled">
        <button class="btn btn-outline-danger btn-sm" type="submit"><i class="fa-solid fa-ban"></i> Cancel</button>
      </form>
      <?php endif; ?>
    <?php elseif ($dn['status'] === 'delivered'): ?>
      <?php if ($canManage): ?>
      <form method="post" class="d-inline" data-confirm="Cancel this delivery note? Stock will be restored.">
        <?= csrf_field() ?><input type="hidden" name="action" value="transition"><input type="hidden" name="status" value="cancelled">
        <button class="btn btn-outline-danger btn-sm" type="submit"><i class="fa-solid fa-ban"></i> Cancel</button>
      </form>
      <?php endif; ?>
    <?php endif; ?>

    <?php if ($dn['status'] === 'delivered'): ?>
      <?php if ($existingInvoice): ?>
        <a href="<?= base_url('accounting/invoice_view.php?id=' . $existingInvoice['id']) ?>" class="btn btn-outline-brand btn-sm"><i class="fa-solid fa-file-invoice-dollar"></i> View Invoice <?= e($existingInvoice['invoice_no']) ?></a>
      <?php elseif (can_edit_module('finance')): ?>
        <a href="<?= base_url('accounting/invoice_form.php?from_dn=' . $id) ?>" class="btn btn-outline-brand btn-sm"><i class="fa-solid fa-file-invoice-dollar"></i> Create Invoice</a>
      <?php endif; ?>
    <?php endif; ?>
    <a href="<?= base_url('print.php?doctype=delivery_note&id=' . $id) ?>" target="_blank" class="btn btn-outline-brand btn-sm"><i class="fa-solid fa-print"></i> Print</a>
    <a href="delivery_notes.php" class="btn btn-outline-secondary btn-sm">Back to list</a>
  </div>
</div>

<div class="card p-3">
  <div class="table-responsive">
    <table class="table">
      <thead><tr><th>Product</th><th class="text-end">Qty</th><th>UOM</th><th class="text-end">Unit Price</th><th class="text-end">Subtotal</th></tr></thead>
      <tbody>
      <?php foreach ($items as $it): ?>
        <tr>
          <td><?= e($it['product_name']) ?> <span class="text-muted small">(<?= e($it['sku']) ?>)</span></td>
          <td class="text-end"><?= (int)$it['quantity'] ?></td>
          <td><?= e($it['uom'] ?? '') ?></td>
          <td class="text-end"><?= money($it['unit_price']) ?></td>
          <td class="text-end"><?= money($it['subtotal']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr><th colspan="4" class="text-end">Total</th><th class="text-end"><?= money($dn['total_amount']) ?></th></tr>
      </tfoot>
    </table>
  </div>
  <?php if ($dn['notes']): ?><div class="mt-2"><strong>Notes:</strong> <?= e($dn['notes']) ?></div><?php endif; ?>
  <?php if ($dn['sales_order_id']): ?><div class="mt-2"><strong>Sales Order:</strong> <a href="<?= base_url('sales/order_view.php?id=' . (int)$dn['sales_order_id']) ?>">View</a></div><?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
