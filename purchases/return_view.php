<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
$canEdit = can_edit_module('procurement');
$canManage = can_manage_module('procurement');

$id = (int)input('id');
$stmt = db()->prepare('SELECT pr.*, v.name vendor_name, w.name warehouse_name, po.po_no FROM purchase_returns pr JOIN vendors v ON v.id = pr.vendor_id JOIN warehouses w ON w.id = pr.warehouse_id JOIN purchase_orders po ON po.id = pr.purchase_order_id WHERE pr.id = ?');
$stmt->execute([$id]);
$ret = $stmt->fetch();

if (!$ret) {
    flash('danger', 'Purchase return not found.');
    redirect('/purchases/returns.php');
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
        'draft'     => ['completed', 'cancelled'],
        'completed' => ['cancelled'],
    ];
    if (!isset($valid[$ret['status']]) || !in_array($newStatus, $valid[$ret['status']], true)) {
        flash('danger', 'That status change is not allowed.');
        redirect('/purchases/return_view.php?id=' . $id);
    }

    $itemsStmt = db()->prepare('SELECT * FROM purchase_return_items WHERE purchase_return_id = ?');
    $itemsStmt->execute([$id]);
    $retItems = $itemsStmt->fetchAll();

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($newStatus === 'completed') {
            foreach ($retItems as $it) {
                $stockQty = (int)round($it['quantity'] * $it['uom_conversion_factor']);
                stock_move($it['product_id'], $ret['warehouse_id'], -$stockQty, 'out', $ret['return_no'], 'Purchase return', current_user()['id']);
            }

            $invStmt = $pdo->prepare("SELECT * FROM purchase_invoices WHERE purchase_order_id = ? AND status <> 'cancelled' ORDER BY id DESC LIMIT 1");
            $invStmt->execute([$ret['purchase_order_id']]);
            $invoice = $invStmt->fetch();

            $debitAmount = 0;
            $invoiceId = null;
            if ($invoice) {
                $invoiceId = $invoice['id'];
                $balance = $invoice['total'] - $invoice['amount_paid'];
                if ($balance > 0.009) {
                    $debitAmount = min((float)$ret['total_amount'], $balance);
                    $pdo->prepare('INSERT INTO purchase_payments (purchase_invoice_id, amount, payment_date, method, reference, notes, created_by) VALUES (?,?,?,?,?,?,?)')
                        ->execute([$invoiceId, $debitAmount, $ret['return_date'], 'debit_note', $ret['return_no'], 'Debit note for Purchase Return ' . $ret['return_no'], current_user()['id']]);
                    $newPaid = $invoice['amount_paid'] + $debitAmount;
                    $newInvStatus = $newPaid >= $invoice['total'] - 0.01 ? 'paid' : 'partially_paid';
                    $pdo->prepare('UPDATE purchase_invoices SET amount_paid = ?, status = ? WHERE id = ?')->execute([$newPaid, $newInvStatus, $invoiceId]);
                }
            }
            $pdo->prepare('UPDATE purchase_returns SET status = ?, purchase_invoice_id = ?, debit_amount = ? WHERE id = ?')->execute([$newStatus, $invoiceId, $debitAmount, $id]);

            if (!$invoice) {
                flash('success', 'Purchase return completed and stock removed. No linked purchase invoice was found, so no debit note was issued.');
            } elseif ($debitAmount < (float)$ret['total_amount'] - 0.009) {
                flash('success', 'Purchase return completed, stock removed, and a debit note of ' . money($debitAmount) . ' applied to the bill. The bill was already at/near zero balance — arrange the remaining ' . money($ret['total_amount'] - $debitAmount) . ' with the vendor separately.');
            } else {
                flash('success', 'Purchase return completed, stock removed, and a debit note of ' . money($debitAmount) . ' applied to the bill.');
            }
        } elseif ($newStatus === 'cancelled' && $ret['status'] === 'completed') {
            foreach ($retItems as $it) {
                $stockQty = (int)round($it['quantity'] * $it['uom_conversion_factor']);
                stock_move($it['product_id'], $ret['warehouse_id'], $stockQty, 'in', $ret['return_no'], 'Purchase return cancelled', current_user()['id']);
            }
            if ((float)$ret['debit_amount'] > 0.009 && $ret['purchase_invoice_id']) {
                $invStmt = $pdo->prepare('SELECT * FROM purchase_invoices WHERE id = ?');
                $invStmt->execute([$ret['purchase_invoice_id']]);
                $invoice = $invStmt->fetch();
                if ($invoice && $invoice['status'] !== 'cancelled') {
                    $newPaid = max(0, $invoice['amount_paid'] - $ret['debit_amount']);
                    if ($newPaid <= 0.009) {
                        $newInvStatus = 'unpaid';
                    } elseif ($newPaid >= $invoice['total'] - 0.01) {
                        $newInvStatus = 'paid';
                    } else {
                        $newInvStatus = 'partially_paid';
                    }
                    $pdo->prepare('UPDATE purchase_invoices SET amount_paid = ?, status = ? WHERE id = ?')->execute([$newPaid, $newInvStatus, $ret['purchase_invoice_id']]);
                    $pdo->prepare('INSERT INTO purchase_payments (purchase_invoice_id, amount, payment_date, method, reference, notes, created_by) VALUES (?,?,?,?,?,?,?)')
                        ->execute([$ret['purchase_invoice_id'], -$ret['debit_amount'], today(), 'debit_note', $ret['return_no'], 'Reversed: Purchase Return ' . $ret['return_no'] . ' cancelled', current_user()['id']]);
                }
            }
            $pdo->prepare('UPDATE purchase_returns SET status = ? WHERE id = ?')->execute([$newStatus, $id]);
            flash('success', 'Purchase return cancelled; stock and any applied debit note have been reversed.');
        } else {
            $pdo->prepare('UPDATE purchase_returns SET status = ? WHERE id = ?')->execute([$newStatus, $id]);
            flash('success', 'Purchase return cancelled.');
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        flash('danger', $e->getMessage() ?: 'Could not update purchase return status.');
    }
    redirect('/purchases/return_view.php?id=' . $id);
}

$items = db()->prepare('SELECT pri.*, p.name product_name, p.sku FROM purchase_return_items pri JOIN products p ON p.id = pri.product_id WHERE purchase_return_id = ?');
$items->execute([$id]);
$items = $items->fetchAll();

$linkedInvoice = null;
if ($ret['purchase_invoice_id']) {
    $stmt = db()->prepare('SELECT id, pi_no FROM purchase_invoices WHERE id = ?');
    $stmt->execute([$ret['purchase_invoice_id']]);
    $linkedInvoice = $stmt->fetch();
}

$badge = ['draft' => 'secondary', 'completed' => 'success', 'cancelled' => 'danger'];

$page_title = 'Purchase Return ' . $ret['return_no'];
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-1"><?= e($ret['return_no']) ?> <span class="badge text-bg-<?= $badge[$ret['status']] ?> badge-status"><?= e($ret['status']) ?></span></h4>
    <div class="text-muted"><?= e($ret['vendor_name']) ?> &middot; <?= e($ret['warehouse_name']) ?> &middot; <?= e($ret['return_date']) ?></div>
  </div>
  <div class="page-actions">
    <?php if ($ret['status'] === 'draft'): ?>
      <?php if ($canEdit): ?>
      <a href="return_form.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-pen"></i> Edit</a>
      <form method="post" class="d-inline" data-confirm="Complete this purchase return? Stock will be removed and a debit note issued against the bill if one exists.">
        <?= csrf_field() ?><input type="hidden" name="action" value="transition"><input type="hidden" name="status" value="completed">
        <button class="btn btn-brand btn-sm" type="submit"><i class="fa-solid fa-rotate-left"></i> Complete Return</button>
      </form>
      <?php endif; ?>
      <?php if ($canManage): ?>
      <form method="post" class="d-inline" data-confirm="Cancel this purchase return?">
        <?= csrf_field() ?><input type="hidden" name="action" value="transition"><input type="hidden" name="status" value="cancelled">
        <button class="btn btn-outline-danger btn-sm" type="submit"><i class="fa-solid fa-ban"></i> Cancel</button>
      </form>
      <?php endif; ?>
    <?php elseif ($ret['status'] === 'completed'): ?>
      <?php if ($canManage): ?>
      <form method="post" class="d-inline" data-confirm="Cancel this purchase return? Stock and any debit note will be reversed.">
        <?= csrf_field() ?><input type="hidden" name="action" value="transition"><input type="hidden" name="status" value="cancelled">
        <button class="btn btn-outline-danger btn-sm" type="submit"><i class="fa-solid fa-ban"></i> Cancel</button>
      </form>
      <?php endif; ?>
    <?php endif; ?>
    <a href="<?= base_url('print.php?doctype=purchase_return&id=' . $id) ?>" target="_blank" class="btn btn-outline-brand btn-sm"><i class="fa-solid fa-print"></i> Print</a>
    <a href="returns.php" class="btn btn-outline-secondary btn-sm">Back to list</a>
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
        <tr><th colspan="4" class="text-end">Total</th><th class="text-end"><?= money($ret['total_amount']) ?></th></tr>
        <?php if ($ret['debit_amount'] > 0): ?>
        <tr><th colspan="4" class="text-end">Debit Note Applied</th><th class="text-end text-success"><?= money($ret['debit_amount']) ?></th></tr>
        <?php endif; ?>
      </tfoot>
    </table>
  </div>
  <?php if ($ret['reason']): ?><div class="mt-2"><strong>Reason:</strong> <?= e($ret['reason']) ?></div><?php endif; ?>
  <div class="mt-2"><strong>Purchase Order:</strong> <a href="<?= base_url('purchases/order_view.php?id=' . (int)$ret['purchase_order_id']) ?>"><?= e($ret['po_no']) ?></a></div>
  <?php if ($linkedInvoice): ?><div class="mt-2"><strong>Debit applied to Bill:</strong> <a href="<?= base_url('accounting/purchase_invoice_view.php?id=' . (int)$linkedInvoice['id']) ?>"><?= e($linkedInvoice['pi_no']) ?></a></div><?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
