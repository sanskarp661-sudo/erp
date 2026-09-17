<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$id = (int)input('id');

db()->prepare("UPDATE invoices SET status='overdue' WHERE id=? AND due_date IS NOT NULL AND due_date < CURDATE() AND status IN ('unpaid','partially_paid')")->execute([$id]);

$stmt = db()->prepare('SELECT i.*, c.name customer_name, c.email customer_email, c.phone customer_phone, c.address customer_address FROM invoices i JOIN customers c ON c.id = i.customer_id WHERE i.id = ?');
$stmt->execute([$id]);
$invoice = $stmt->fetch();

if (!$invoice) {
    flash('danger', 'Invoice not found.');
    redirect('/accounting/invoices.php');
}

if (is_post() && input('action') === 'record_payment') {
    csrf_verify();
    $amount = (float)input('amount');
    $balance = $invoice['total'] - $invoice['amount_paid'];

    if ($invoice['status'] === 'cancelled') {
        flash('danger', 'This invoice is cancelled; payments cannot be recorded.');
    } elseif ($amount <= 0) {
        flash('danger', 'Payment amount must be greater than zero.');
    } elseif ($amount > $balance + 0.01) {
        flash('danger', 'Payment amount cannot exceed the outstanding balance of ' . money($balance) . '.');
    } else {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO payments (invoice_id, amount, payment_date, method, reference, notes, created_by) VALUES (?,?,?,?,?,?,?)')
                ->execute([$id, $amount, input('payment_date') ?: today(), input('method') ?: 'cash', input('reference'), input('notes'), current_user()['id']]);

            $newPaid = $invoice['amount_paid'] + $amount;
            $newStatus = $newPaid >= $invoice['total'] - 0.01 ? 'paid' : 'partially_paid';
            $pdo->prepare('UPDATE invoices SET amount_paid = ?, status = ? WHERE id = ?')->execute([$newPaid, $newStatus, $id]);

            $pdo->commit();
            flash('success', 'Payment recorded.');
        } catch (Exception $e) {
            $pdo->rollBack();
            flash('danger', 'Could not record payment.');
        }
    }
    redirect('/accounting/invoice_view.php?id=' . $id);
}

$items = db()->prepare('SELECT * FROM invoice_items WHERE invoice_id = ?');
$items->execute([$id]);
$items = $items->fetchAll();

$payments = db()->prepare('SELECT p.*, u.name user_name FROM payments p LEFT JOIN users u ON u.id = p.created_by WHERE invoice_id = ? ORDER BY p.id DESC');
$payments->execute([$id]);
$payments = $payments->fetchAll();

$balance = $invoice['total'] - $invoice['amount_paid'];
$badge = ['unpaid' => 'secondary', 'partially_paid' => 'warning', 'paid' => 'success', 'overdue' => 'danger', 'cancelled' => 'dark'];

$page_title = 'Invoice ' . $invoice['invoice_no'];
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-1"><?= e($invoice['invoice_no']) ?> <span class="badge text-bg-<?= $badge[$invoice['status']] ?? 'secondary' ?> badge-status"><?= e(str_replace('_', ' ', $invoice['status'])) ?></span></h4>
    <div class="text-muted"><?= e($invoice['customer_name']) ?> &middot; Issued <?= e($invoice['invoice_date']) ?><?= $invoice['due_date'] ? ' &middot; Due ' . e($invoice['due_date']) : '' ?></div>
  </div>
  <div class="page-actions">
    <?php if ($invoice['amount_paid'] == 0 && $invoice['status'] === 'unpaid'): ?>
      <a href="invoice_form.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-pen"></i> Edit</a>
    <?php endif; ?>
    <a href="invoice_print.php?id=<?= $id ?>" target="_blank" class="btn btn-outline-brand btn-sm"><i class="fa-solid fa-print"></i> Print / PDF</a>
    <a href="invoices.php" class="btn btn-outline-secondary btn-sm">Back to list</a>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-8">
    <div class="card p-3">
      <div class="table-responsive">
        <table class="table">
          <thead><tr><th>Description</th><th class="text-end">Qty</th><th class="text-end">Unit Price</th><th class="text-end">Subtotal</th></tr></thead>
          <tbody>
          <?php foreach ($items as $it): ?>
            <tr>
              <td><?= e($it['description']) ?></td>
              <td class="text-end"><?= (int)$it['quantity'] ?></td>
              <td class="text-end"><?= money($it['unit_price']) ?></td>
              <td class="text-end"><?= money($it['subtotal']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr><th colspan="3" class="text-end">Subtotal</th><th class="text-end"><?= money($invoice['subtotal']) ?></th></tr>
            <tr><th colspan="3" class="text-end">Tax</th><th class="text-end"><?= money($invoice['tax']) ?></th></tr>
            <tr><th colspan="3" class="text-end">Total</th><th class="text-end"><?= money($invoice['total']) ?></th></tr>
            <tr><th colspan="3" class="text-end">Paid</th><th class="text-end text-success"><?= money($invoice['amount_paid']) ?></th></tr>
            <tr><th colspan="3" class="text-end">Balance Due</th><th class="text-end text-danger"><?= money($balance) ?></th></tr>
          </tfoot>
        </table>
      </div>
      <?php if ($invoice['notes']): ?><div class="mt-2"><strong>Notes:</strong> <?= e($invoice['notes']) ?></div><?php endif; ?>
    </div>

    <div class="card p-3 mt-3">
      <h6 class="mb-2">Payment History</h6>
      <div class="table-responsive">
        <table class="table table-sm">
          <thead><tr><th>Date</th><th>Method</th><th>Reference</th><th>By</th><th class="text-end">Amount</th></tr></thead>
          <tbody>
          <?php foreach ($payments as $p): ?>
            <tr>
              <td><?= e($p['payment_date']) ?></td>
              <td class="text-capitalize"><?= e(str_replace('_', ' ', $p['method'])) ?></td>
              <td><?= e($p['reference']) ?></td>
              <td><?= e($p['user_name'] ?? 'System') ?></td>
              <td class="text-end"><?= money($p['amount']) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$payments): ?><tr><td colspan="5" class="text-muted text-center">No payments recorded yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <?php if ($balance > 0.009 && $invoice['status'] !== 'cancelled'): ?>
    <div class="card p-3">
      <h6 class="mb-3">Record Payment</h6>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="record_payment">
        <div class="mb-2">
          <label class="form-label">Amount (balance: <?= money($balance) ?>)</label>
          <input type="number" step="0.01" min="0.01" max="<?= e($balance) ?>" name="amount" class="form-control" value="<?= e($balance) ?>" required>
        </div>
        <div class="mb-2">
          <label class="form-label">Payment Date</label>
          <input type="date" name="payment_date" class="form-control" value="<?= today() ?>" required>
        </div>
        <div class="mb-2">
          <label class="form-label">Method</label>
          <select name="method" class="form-select">
            <option value="cash">Cash</option>
            <option value="bank_transfer">Bank Transfer</option>
            <option value="card">Card</option>
            <option value="cheque">Cheque</option>
            <option value="other">Other</option>
          </select>
        </div>
        <div class="mb-2">
          <label class="form-label">Reference</label>
          <input type="text" name="reference" class="form-control">
        </div>
        <div class="mb-3">
          <label class="form-label">Notes</label>
          <textarea name="notes" class="form-control" rows="2"></textarea>
        </div>
        <button type="submit" class="btn btn-brand w-100">Record Payment</button>
      </form>
    </div>
    <?php else: ?>
    <div class="card p-3 text-center text-muted"><i class="fa-solid fa-circle-check fa-2x text-success mb-2"></i><br>This invoice is fully settled.</div>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
