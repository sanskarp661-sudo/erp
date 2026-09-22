<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
$canEdit = can_edit_module('procurement');
$canManage = can_manage_module('procurement');

$id = (int)input('id');
$stmt = db()->prepare('SELECT po.*, v.name vendor_name, v.email vendor_email, v.phone vendor_phone FROM purchase_orders po JOIN vendors v ON v.id = po.vendor_id WHERE po.id = ?');
$stmt->execute([$id]);
$order = $stmt->fetch();

if (!$order) {
    flash('danger', 'Purchase order not found.');
    redirect('/purchases/orders.php');
}

if (is_post() && input('action') === 'transition') {
    $newStatus = input('status');
    if ($newStatus === 'cancelled') {
        require_module_manage('procurement');
    } else {
        require_module_edit('procurement');
    }
    csrf_verify();
    $valid = [
        'pending' => ['ordered', 'cancelled'],
        'ordered' => ['received', 'cancelled'],
    ];
    if (!isset($valid[$order['status']]) || !in_array($newStatus, $valid[$order['status']], true)) {
        flash('danger', 'That status change is not allowed.');
        redirect('/purchases/order_view.php?id=' . $id);
    }

    // Purchase orders no longer touch stock directly — only a Goods
    // Receipt (created separately, once ordered) actually adds it.
    try {
        db()->prepare('UPDATE purchase_orders SET status = ? WHERE id = ?')->execute([$newStatus, $id]);
        flash('success', 'Purchase order status updated to "' . $newStatus . '".');
    } catch (Exception $e) {
        flash('danger', 'Could not update purchase order status.');
    }
    redirect('/purchases/order_view.php?id=' . $id);
}

$items = db()->prepare('SELECT poi.*, p.name product_name, p.sku FROM purchase_order_items poi JOIN products p ON p.id = poi.product_id WHERE po_id = ?');
$items->execute([$id]);
$items = $items->fetchAll();

$grnStmt = db()->prepare('SELECT id, grn_no, status FROM goods_receipts WHERE purchase_order_id = ? LIMIT 1');
$grnStmt->execute([$id]);
$existingGrn = $grnStmt->fetch();

$returnsStmt = db()->prepare('SELECT id, return_no, status, return_date, total_amount FROM purchase_returns WHERE purchase_order_id = ? ORDER BY id DESC');
$returnsStmt->execute([$id]);
$returns = $returnsStmt->fetchAll();
$returnBadge = ['draft' => 'secondary', 'completed' => 'success', 'cancelled' => 'danger'];

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
      <?php if ($canEdit): ?>
      <a href="order_form.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-pen"></i> Edit</a>
      <form method="post" class="d-inline" data-confirm="Mark this order as ordered?">
        <?= csrf_field() ?><input type="hidden" name="action" value="transition"><input type="hidden" name="status" value="ordered">
        <button class="btn btn-brand btn-sm" type="submit"><i class="fa-solid fa-paper-plane"></i> Mark Ordered</button>
      </form>
      <?php endif; ?>
      <?php if ($canManage): ?>
      <form method="post" class="d-inline" data-confirm="Cancel this purchase order?">
        <?= csrf_field() ?><input type="hidden" name="action" value="transition"><input type="hidden" name="status" value="cancelled">
        <button class="btn btn-outline-danger btn-sm" type="submit"><i class="fa-solid fa-ban"></i> Cancel</button>
      </form>
      <?php endif; ?>
    <?php elseif ($order['status'] === 'ordered'): ?>
      <?php if ($canEdit): ?>
      <form method="post" class="d-inline" data-confirm="Mark this order as received? (Receiving stock happens separately via a Goods Receipt.)">
        <?= csrf_field() ?><input type="hidden" name="action" value="transition"><input type="hidden" name="status" value="received">
        <button class="btn btn-brand btn-sm" type="submit"><i class="fa-solid fa-box-open"></i> Mark Received</button>
      </form>
      <?php endif; ?>
      <?php if ($canManage): ?>
      <form method="post" class="d-inline" data-confirm="Cancel this purchase order?">
        <?= csrf_field() ?><input type="hidden" name="action" value="transition"><input type="hidden" name="status" value="cancelled">
        <button class="btn btn-outline-danger btn-sm" type="submit"><i class="fa-solid fa-ban"></i> Cancel</button>
      </form>
      <?php endif; ?>
    <?php endif; ?>

    <?php if (in_array($order['status'], ['ordered', 'received'], true)): ?>
      <?php if ($existingGrn): ?>
        <a href="<?= base_url('purchases/grn_view.php?id=' . $existingGrn['id']) ?>" class="btn btn-outline-brand btn-sm"><i class="fa-solid fa-box-open"></i> View Goods Receipt <?= e($existingGrn['grn_no']) ?></a>
      <?php elseif ($canEdit): ?>
        <a href="<?= base_url('purchases/grn_form.php?from_order=' . $id) ?>" class="btn btn-outline-brand btn-sm"><i class="fa-solid fa-box-open"></i> Create Goods Receipt</a>
      <?php endif; ?>
    <?php endif; ?>
    <?php if ($existingGrn && $existingGrn['status'] === 'received' && $canEdit): ?>
      <a href="<?= base_url('purchases/return_form.php?from_order=' . $id) ?>" class="btn btn-outline-brand btn-sm"><i class="fa-solid fa-rotate-left"></i> New Purchase Return</a>
    <?php endif; ?>
    <a href="<?= base_url('print.php?doctype=purchase_order&id=' . $id) ?>" target="_blank" class="btn btn-outline-brand btn-sm"><i class="fa-solid fa-print"></i> Print</a>
    <a href="orders.php" class="btn btn-outline-secondary btn-sm">Back to list</a>
  </div>
</div>

<div class="card p-3">
  <div class="table-responsive">
    <table class="table">
      <thead><tr><th>Product</th><th class="text-end">Qty</th><th>UOM</th><th class="text-end">Unit Cost</th><th class="text-end">Subtotal</th></tr></thead>
      <tbody>
      <?php foreach ($items as $it): ?>
        <tr>
          <td><?= e($it['product_name']) ?> <span class="text-muted small">(<?= e($it['sku']) ?>)</span></td>
          <td class="text-end"><?= (int)$it['quantity'] ?></td>
          <td><?= e($it['uom'] ?? '') ?></td>
          <td class="text-end"><?= money($it['unit_cost']) ?></td>
          <td class="text-end"><?= money($it['subtotal']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr><th colspan="4" class="text-end">Total</th><th class="text-end"><?= money($order['total_amount']) ?></th></tr>
      </tfoot>
    </table>
  </div>
  <?php if ($order['notes']): ?><div class="mt-2"><strong>Notes:</strong> <?= e($order['notes']) ?></div><?php endif; ?>
</div>

<?php if ($returns): ?>
<div class="card p-3 mt-3">
  <h6 class="mb-2">Purchase Returns</h6>
  <div class="table-responsive">
    <table class="table table-sm">
      <thead><tr><th>Return #</th><th>Date</th><th>Status</th><th class="text-end">Amount</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($returns as $r): ?>
        <tr>
          <td><?= e($r['return_no']) ?></td>
          <td><?= e($r['return_date']) ?></td>
          <td><span class="badge text-bg-<?= $returnBadge[$r['status']] ?? 'secondary' ?> badge-status"><?= e($r['status']) ?></span></td>
          <td class="text-end"><?= money($r['total_amount']) ?></td>
          <td class="text-end"><a href="<?= base_url('purchases/return_view.php?id=' . (int)$r['id']) ?>" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-eye"></i> View</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
