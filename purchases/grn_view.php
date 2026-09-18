<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
$canEdit = can_edit_module('procurement');
$canManage = can_manage_module('procurement');

$id = (int)input('id');
$stmt = db()->prepare('SELECT g.*, v.name vendor_name, v.email vendor_email, v.phone vendor_phone, w.name warehouse_name FROM goods_receipts g JOIN vendors v ON v.id = g.vendor_id JOIN warehouses w ON w.id = g.warehouse_id WHERE g.id = ?');
$stmt->execute([$id]);
$grn = $stmt->fetch();

if (!$grn) {
    flash('danger', 'Goods receipt not found.');
    redirect('/purchases/grns.php');
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
        'draft'    => ['received', 'cancelled'],
        'received' => ['cancelled'],
    ];
    if (!isset($valid[$grn['status']]) || !in_array($newStatus, $valid[$grn['status']], true)) {
        flash('danger', 'That status change is not allowed.');
        redirect('/purchases/grn_view.php?id=' . $id);
    }

    $itemsStmt = db()->prepare('SELECT * FROM goods_receipt_items WHERE grn_id = ?');
    $itemsStmt->execute([$id]);
    $grnItems = $itemsStmt->fetchAll();

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($newStatus === 'received') {
            foreach ($grnItems as $it) {
                stock_move($it['product_id'], $grn['warehouse_id'], $it['quantity'], 'in', $grn['grn_no'], 'Goods receipt', current_user()['id']);
            }
        } elseif ($newStatus === 'cancelled' && $grn['status'] === 'received') {
            // Reverse the receipt: stock was added when received.
            foreach ($grnItems as $it) {
                stock_move($it['product_id'], $grn['warehouse_id'], -$it['quantity'], 'out', $grn['grn_no'], 'Goods receipt cancelled', current_user()['id']);
            }
        }
        $pdo->prepare('UPDATE goods_receipts SET status = ? WHERE id = ?')->execute([$newStatus, $id]);
        $pdo->commit();
        flash('success', 'Goods receipt status updated to "' . $newStatus . '".');
    } catch (Exception $e) {
        $pdo->rollBack();
        flash('danger', $e->getMessage() ?: 'Could not update goods receipt status.');
    }
    redirect('/purchases/grn_view.php?id=' . $id);
}

$items = db()->prepare('SELECT gri.*, p.name product_name, p.sku FROM goods_receipt_items gri JOIN products p ON p.id = gri.product_id WHERE grn_id = ?');
$items->execute([$id]);
$items = $items->fetchAll();

$existingPi = null;
if ($grn['status'] === 'received') {
    $stmt = db()->prepare('SELECT id, pi_no FROM purchase_invoices WHERE goods_receipt_id = ? LIMIT 1');
    $stmt->execute([$id]);
    $existingPi = $stmt->fetch();
}

$badge = ['draft' => 'secondary', 'received' => 'success', 'cancelled' => 'danger'];

$page_title = 'Goods Receipt ' . $grn['grn_no'];
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-1"><?= e($grn['grn_no']) ?> <span class="badge text-bg-<?= $badge[$grn['status']] ?> badge-status"><?= e($grn['status']) ?></span></h4>
    <div class="text-muted"><?= e($grn['vendor_name']) ?> &middot; <?= e($grn['warehouse_name']) ?> &middot; <?= e($grn['posting_date']) ?></div>
  </div>
  <div class="page-actions">
    <?php if ($grn['status'] === 'draft'): ?>
      <?php if ($canEdit): ?>
      <a href="grn_form.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-pen"></i> Edit</a>
      <form method="post" class="d-inline" data-confirm="Mark this goods receipt as received? Stock will be added.">
        <?= csrf_field() ?><input type="hidden" name="action" value="transition"><input type="hidden" name="status" value="received">
        <button class="btn btn-brand btn-sm" type="submit"><i class="fa-solid fa-box-open"></i> Mark Received</button>
      </form>
      <?php endif; ?>
      <?php if ($canManage): ?>
      <form method="post" class="d-inline" data-confirm="Cancel this goods receipt?">
        <?= csrf_field() ?><input type="hidden" name="action" value="transition"><input type="hidden" name="status" value="cancelled">
        <button class="btn btn-outline-danger btn-sm" type="submit"><i class="fa-solid fa-ban"></i> Cancel</button>
      </form>
      <?php endif; ?>
    <?php elseif ($grn['status'] === 'received'): ?>
      <?php if ($canManage): ?>
      <form method="post" class="d-inline" data-confirm="Cancel this goods receipt? Stock will be reversed.">
        <?= csrf_field() ?><input type="hidden" name="action" value="transition"><input type="hidden" name="status" value="cancelled">
        <button class="btn btn-outline-danger btn-sm" type="submit"><i class="fa-solid fa-ban"></i> Cancel</button>
      </form>
      <?php endif; ?>
    <?php endif; ?>

    <?php if ($grn['status'] === 'received'): ?>
      <?php if ($existingPi): ?>
        <a href="<?= base_url('accounting/purchase_invoice_view.php?id=' . $existingPi['id']) ?>" class="btn btn-outline-brand btn-sm"><i class="fa-solid fa-file-invoice-dollar"></i> View Bill <?= e($existingPi['pi_no']) ?></a>
      <?php elseif (can_edit_module('finance')): ?>
        <a href="<?= base_url('accounting/purchase_invoice_form.php?from_grn=' . $id) ?>" class="btn btn-outline-brand btn-sm"><i class="fa-solid fa-file-invoice-dollar"></i> Create Bill</a>
      <?php endif; ?>
    <?php endif; ?>
    <a href="<?= base_url('print.php?doctype=grn&id=' . $id) ?>" target="_blank" class="btn btn-outline-brand btn-sm"><i class="fa-solid fa-print"></i> Print</a>
    <a href="grns.php" class="btn btn-outline-secondary btn-sm">Back to list</a>
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
        <tr><th colspan="3" class="text-end">Total</th><th class="text-end"><?= money($grn['total_amount']) ?></th></tr>
      </tfoot>
    </table>
  </div>
  <?php if ($grn['notes']): ?><div class="mt-2"><strong>Notes:</strong> <?= e($grn['notes']) ?></div><?php endif; ?>
  <?php if ($grn['purchase_order_id']): ?><div class="mt-2"><strong>Purchase Order:</strong> <a href="<?= base_url('purchases/order_view.php?id=' . (int)$grn['purchase_order_id']) ?>">View</a></div><?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
