<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
$canEdit = can_edit_module('sales');

$id = (int)input('id');
$stmt = db()->prepare('SELECT q.*, c.name customer_name FROM quotations q JOIN customers c ON c.id = q.customer_id WHERE q.id = ?');
$stmt->execute([$id]);
$quotation = $stmt->fetch();

if (!$quotation) {
    flash('danger', 'Quotation not found.');
    redirect('/sales/quotations.php');
}

if (is_post() && input('action') === 'transition') {
    require_module_edit('sales');
    csrf_verify();
    $newStatus = input('status');
    $valid = [
        'draft' => ['sent'],
        'sent'  => ['accepted', 'rejected', 'expired'],
    ];
    if (!isset($valid[$quotation['status']]) || !in_array($newStatus, $valid[$quotation['status']], true)) {
        flash('danger', 'That status change is not allowed.');
        redirect('/sales/quotation_view.php?id=' . $id);
    }
    db()->prepare('UPDATE quotations SET status = ? WHERE id = ?')->execute([$newStatus, $id]);
    flash('success', 'Quotation status updated to "' . $newStatus . '".');
    redirect('/sales/quotation_view.php?id=' . $id);
}

$items = db()->prepare('SELECT qi.*, p.name product_name, p.sku FROM quotation_items qi JOIN products p ON p.id = qi.product_id WHERE quotation_id = ?');
$items->execute([$id]);
$items = $items->fetchAll();

$soStmt = db()->prepare('SELECT id, order_no, status FROM sales_orders WHERE quotation_id = ? LIMIT 1');
$soStmt->execute([$id]);
$existingSo = $soStmt->fetch();

$badge = ['draft' => 'secondary', 'sent' => 'info', 'accepted' => 'success', 'rejected' => 'danger', 'expired' => 'dark'];
$soBadge = ['pending' => 'secondary', 'confirmed' => 'info', 'shipped' => 'primary', 'completed' => 'success', 'cancelled' => 'danger'];

$page_title = 'Quotation ' . $quotation['quotation_no'];
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-1"><?= e($quotation['quotation_no']) ?> <span class="badge text-bg-<?= $badge[$quotation['status']] ?? 'secondary' ?> badge-status"><?= e($quotation['status']) ?></span></h4>
    <div class="text-muted"><?= e($quotation['customer_name']) ?> &middot; <?= e($quotation['quotation_date']) ?></div>
  </div>
  <div class="page-actions">
    <?php if ($canEdit): ?>
      <?php if ($quotation['status'] === 'draft'): ?>
        <a href="quotation_form.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-pen"></i> Edit</a>
        <form method="post" class="d-inline" data-confirm="Mark this quotation as sent?">
          <?= csrf_field() ?><input type="hidden" name="action" value="transition"><input type="hidden" name="status" value="sent">
          <button class="btn btn-brand btn-sm" type="submit"><i class="fa-solid fa-paper-plane"></i> Mark Sent</button>
        </form>
      <?php elseif ($quotation['status'] === 'sent'): ?>
        <form method="post" class="d-inline" data-confirm="Mark this quotation as accepted?">
          <?= csrf_field() ?><input type="hidden" name="action" value="transition"><input type="hidden" name="status" value="accepted">
          <button class="btn btn-brand btn-sm" type="submit"><i class="fa-solid fa-check"></i> Mark Accepted</button>
        </form>
        <form method="post" class="d-inline" data-confirm="Mark this quotation as rejected?">
          <?= csrf_field() ?><input type="hidden" name="action" value="transition"><input type="hidden" name="status" value="rejected">
          <button class="btn btn-outline-danger btn-sm" type="submit"><i class="fa-solid fa-xmark"></i> Mark Rejected</button>
        </form>
        <form method="post" class="d-inline" data-confirm="Mark this quotation as expired?">
          <?= csrf_field() ?><input type="hidden" name="action" value="transition"><input type="hidden" name="status" value="expired">
          <button class="btn btn-outline-secondary btn-sm" type="submit"><i class="fa-solid fa-clock"></i> Mark Expired</button>
        </form>
      <?php endif; ?>
      <?php if (in_array($quotation['status'], ['sent', 'accepted'], true) && !$existingSo): ?>
        <a href="<?= base_url('sales/order_form.php?from_quotation=' . $id) ?>" class="btn btn-outline-brand btn-sm"><i class="fa-solid fa-cart-shopping"></i> Create Sales Order</a>
      <?php endif; ?>
    <?php endif; ?>
    <a href="quotations.php" class="btn btn-outline-secondary btn-sm">Back to list</a>
  </div>
</div>

<?php if ($existingSo): ?>
<div class="card p-3 mb-3">
  <h6 class="mb-2">Converted To</h6>
  <div>
    Sales Order <a href="<?= base_url('sales/order_view.php?id=' . (int)$existingSo['id']) ?>"><?= e($existingSo['order_no']) ?></a>
    <span class="badge text-bg-<?= $soBadge[$existingSo['status']] ?? 'secondary' ?> badge-status"><?= e($existingSo['status']) ?></span>
  </div>
</div>
<?php endif; ?>

<div class="card p-3">
  <div class="row g-3 mb-3">
    <div class="col-sm-3"><div class="small text-muted">Customer</div><div><?= e($quotation['customer_name']) ?></div></div>
    <div class="col-sm-3"><div class="small text-muted">Quotation Date</div><div><?= e($quotation['quotation_date']) ?></div></div>
    <div class="col-sm-3"><div class="small text-muted">Valid Till</div><div><?= e($quotation['valid_till'] ?: '—') ?></div></div>
    <div class="col-sm-3"><div class="small text-muted">Notes</div><div><?= e($quotation['notes'] ?: '—') ?></div></div>
  </div>
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
        <tr><th colspan="4" class="text-end">Total</th><th class="text-end"><?= money($quotation['total_amount']) ?></th></tr>
      </tfoot>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
