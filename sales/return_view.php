<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
$canEdit = can_edit_module('sales');
$canManage = can_manage_module('sales');

$id = (int)input('id');
$stmt = db()->prepare('SELECT sr.*, c.name customer_name, w.name warehouse_name, so.order_no FROM sales_returns sr JOIN customers c ON c.id = sr.customer_id JOIN warehouses w ON w.id = sr.warehouse_id JOIN sales_orders so ON so.id = sr.sales_order_id WHERE sr.id = ?');
$stmt->execute([$id]);
$ret = $stmt->fetch();

if (!$ret) {
    flash('danger', 'Sales return not found.');
    redirect('/sales/returns.php');
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
        'draft'     => ['completed', 'cancelled'],
        'completed' => ['cancelled'],
    ];
    if (!isset($valid[$ret['status']]) || !in_array($newStatus, $valid[$ret['status']], true)) {
        flash('danger', 'That status change is not allowed.');
        redirect('/sales/return_view.php?id=' . $id);
    }

    $itemsStmt = db()->prepare('SELECT * FROM sales_return_items WHERE sales_return_id = ?');
    $itemsStmt->execute([$id]);
    $retItems = $itemsStmt->fetchAll();

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($newStatus === 'completed') {
            foreach ($retItems as $it) {
                stock_move($it['product_id'], $ret['warehouse_id'], $it['quantity'], 'in', $ret['return_no'], 'Sales return', current_user()['id']);
            }

            $invStmt = $pdo->prepare("SELECT * FROM invoices WHERE sales_order_id = ? AND status <> 'cancelled' ORDER BY id DESC LIMIT 1");
            $invStmt->execute([$ret['sales_order_id']]);
            $invoice = $invStmt->fetch();

            $creditAmount = 0;
            $invoiceId = null;
            if ($invoice) {
                $invoiceId = $invoice['id'];
                $balance = $invoice['total'] - $invoice['amount_paid'];
                if ($balance > 0.009) {
                    $creditAmount = min((float)$ret['total_amount'], $balance);
                    $pdo->prepare('INSERT INTO payments (invoice_id, amount, payment_date, method, reference, notes, created_by) VALUES (?,?,?,?,?,?,?)')
                        ->execute([$invoiceId, $creditAmount, $ret['return_date'], 'credit_note', $ret['return_no'], 'Credit note for Sales Return ' . $ret['return_no'], current_user()['id']]);
                    $newPaid = $invoice['amount_paid'] + $creditAmount;
                    $newInvStatus = $newPaid >= $invoice['total'] - 0.01 ? 'paid' : 'partially_paid';
                    $pdo->prepare('UPDATE invoices SET amount_paid = ?, status = ? WHERE id = ?')->execute([$newPaid, $newInvStatus, $invoiceId]);
                }
            }
            $pdo->prepare('UPDATE sales_returns SET status = ?, invoice_id = ?, credit_amount = ? WHERE id = ?')->execute([$newStatus, $invoiceId, $creditAmount, $id]);

            if (!$invoice) {
                flash('success', 'Sales return completed and stock restored. No linked sales invoice was found, so no credit note was issued.');
            } elseif ($creditAmount < (float)$ret['total_amount'] - 0.009) {
                flash('success', 'Sales return completed, stock restored, and a credit note of ' . money($creditAmount) . ' applied to invoice. The invoice was already at/near zero balance — settle the remaining ' . money($ret['total_amount'] - $creditAmount) . ' with the customer separately.');
            } else {
                flash('success', 'Sales return completed, stock restored, and a credit note of ' . money($creditAmount) . ' applied to the invoice.');
            }
        } elseif ($newStatus === 'cancelled' && $ret['status'] === 'completed') {
            foreach ($retItems as $it) {
                stock_move($it['product_id'], $ret['warehouse_id'], -$it['quantity'], 'out', $ret['return_no'], 'Sales return cancelled', current_user()['id']);
            }
            if ((float)$ret['credit_amount'] > 0.009 && $ret['invoice_id']) {
                $invStmt = $pdo->prepare('SELECT * FROM invoices WHERE id = ?');
                $invStmt->execute([$ret['invoice_id']]);
                $invoice = $invStmt->fetch();
                if ($invoice && $invoice['status'] !== 'cancelled') {
                    $newPaid = max(0, $invoice['amount_paid'] - $ret['credit_amount']);
                    if ($newPaid <= 0.009) {
                        $newInvStatus = 'unpaid';
                    } elseif ($newPaid >= $invoice['total'] - 0.01) {
                        $newInvStatus = 'paid';
                    } else {
                        $newInvStatus = 'partially_paid';
                    }
                    $pdo->prepare('UPDATE invoices SET amount_paid = ?, status = ? WHERE id = ?')->execute([$newPaid, $newInvStatus, $ret['invoice_id']]);
                    $pdo->prepare('INSERT INTO payments (invoice_id, amount, payment_date, method, reference, notes, created_by) VALUES (?,?,?,?,?,?,?)')
                        ->execute([$ret['invoice_id'], -$ret['credit_amount'], today(), 'credit_note', $ret['return_no'], 'Reversed: Sales Return ' . $ret['return_no'] . ' cancelled', current_user()['id']]);
                }
            }
            $pdo->prepare('UPDATE sales_returns SET status = ? WHERE id = ?')->execute([$newStatus, $id]);
            flash('success', 'Sales return cancelled; stock and any applied credit note have been reversed.');
        } else {
            $pdo->prepare('UPDATE sales_returns SET status = ? WHERE id = ?')->execute([$newStatus, $id]);
            flash('success', 'Sales return cancelled.');
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        flash('danger', $e->getMessage() ?: 'Could not update sales return status.');
    }
    redirect('/sales/return_view.php?id=' . $id);
}

$items = db()->prepare('SELECT sri.*, p.name product_name, p.sku FROM sales_return_items sri JOIN products p ON p.id = sri.product_id WHERE sales_return_id = ?');
$items->execute([$id]);
$items = $items->fetchAll();

$linkedInvoice = null;
if ($ret['invoice_id']) {
    $stmt = db()->prepare('SELECT id, invoice_no FROM invoices WHERE id = ?');
    $stmt->execute([$ret['invoice_id']]);
    $linkedInvoice = $stmt->fetch();
}

$badge = ['draft' => 'secondary', 'completed' => 'success', 'cancelled' => 'danger'];

$page_title = 'Sales Return ' . $ret['return_no'];
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-1"><?= e($ret['return_no']) ?> <span class="badge text-bg-<?= $badge[$ret['status']] ?> badge-status"><?= e($ret['status']) ?></span></h4>
    <div class="text-muted"><?= e($ret['customer_name']) ?> &middot; <?= e($ret['warehouse_name']) ?> &middot; <?= e($ret['return_date']) ?></div>
  </div>
  <div class="page-actions">
    <?php if ($ret['status'] === 'draft'): ?>
      <?php if ($canEdit): ?>
      <a href="return_form.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-pen"></i> Edit</a>
      <form method="post" class="d-inline" data-confirm="Complete this sales return? Stock will be added back and a credit note issued against the invoice if one exists.">
        <?= csrf_field() ?><input type="hidden" name="action" value="transition"><input type="hidden" name="status" value="completed">
        <button class="btn btn-brand btn-sm" type="submit"><i class="fa-solid fa-rotate-left"></i> Complete Return</button>
      </form>
      <?php endif; ?>
      <?php if ($canManage): ?>
      <form method="post" class="d-inline" data-confirm="Cancel this sales return?">
        <?= csrf_field() ?><input type="hidden" name="action" value="transition"><input type="hidden" name="status" value="cancelled">
        <button class="btn btn-outline-danger btn-sm" type="submit"><i class="fa-solid fa-ban"></i> Cancel</button>
      </form>
      <?php endif; ?>
    <?php elseif ($ret['status'] === 'completed'): ?>
      <?php if ($canManage): ?>
      <form method="post" class="d-inline" data-confirm="Cancel this sales return? Stock and any credit note will be reversed.">
        <?= csrf_field() ?><input type="hidden" name="action" value="transition"><input type="hidden" name="status" value="cancelled">
        <button class="btn btn-outline-danger btn-sm" type="submit"><i class="fa-solid fa-ban"></i> Cancel</button>
      </form>
      <?php endif; ?>
    <?php endif; ?>
    <a href="<?= base_url('print.php?doctype=sales_return&id=' . $id) ?>" target="_blank" class="btn btn-outline-brand btn-sm"><i class="fa-solid fa-print"></i> Print</a>
    <a href="returns.php" class="btn btn-outline-secondary btn-sm">Back to list</a>
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
        <tr><th colspan="3" class="text-end">Total</th><th class="text-end"><?= money($ret['total_amount']) ?></th></tr>
        <?php if ($ret['credit_amount'] > 0): ?>
        <tr><th colspan="3" class="text-end">Credit Note Applied</th><th class="text-end text-success"><?= money($ret['credit_amount']) ?></th></tr>
        <?php endif; ?>
      </tfoot>
    </table>
  </div>
  <?php if ($ret['reason']): ?><div class="mt-2"><strong>Reason:</strong> <?= e($ret['reason']) ?></div><?php endif; ?>
  <div class="mt-2"><strong>Sales Order:</strong> <a href="<?= base_url('sales/order_view.php?id=' . (int)$ret['sales_order_id']) ?>"><?= e($ret['order_no']) ?></a></div>
  <?php if ($linkedInvoice): ?><div class="mt-2"><strong>Credit applied to Invoice:</strong> <a href="<?= base_url('accounting/invoice_view.php?id=' . (int)$linkedInvoice['id']) ?>"><?= e($linkedInvoice['invoice_no']) ?></a></div><?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
