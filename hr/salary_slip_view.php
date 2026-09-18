<?php
require_once __DIR__ . '/../includes/auth.php';
require_role(['admin', 'manager']);

$id = (int)input('id');
$stmt = db()->prepare("
  SELECT s.*, e.name employee_name, e.employee_code, e.designation, d.name department_name
  FROM salary_slips s
  JOIN employees e ON e.id = s.employee_id
  LEFT JOIN departments d ON d.id = e.department_id
  WHERE s.id = ?
");
$stmt->execute([$id]);
$slip = $stmt->fetch();

if (!$slip) {
    flash('danger', 'Salary slip not found.');
    redirect('/hr/salary_slips.php');
}

if (is_post() && input('action') === 'mark_paid' && $slip['status'] === 'draft') {
    csrf_verify();
    $paymentDate = input('payment_date') ?: today();
    $method = in_array(input('payment_method'), ['cash', 'bank_transfer', 'cheque', 'other'], true) ? input('payment_method') : 'bank_transfer';

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO expenses (category, description, amount, expense_date, payment_method, created_by) VALUES ('Payroll', ?, ?, ?, ?, ?)")
            ->execute(['Salary - ' . $slip['employee_name'] . ' (' . $slip['slip_no'] . ')', $slip['net_pay'], $paymentDate, $method, current_user()['id']]);
        $expenseId = (int)$pdo->lastInsertId();

        $pdo->prepare('UPDATE salary_slips SET status = "paid", payment_date = ?, payment_method = ?, expense_id = ? WHERE id = ?')
            ->execute([$paymentDate, $method, $expenseId, $id]);

        $pdo->commit();
        flash('success', 'Salary slip marked as paid and recorded as a Finance expense.');
    } catch (Exception $e) {
        $pdo->rollBack();
        flash('danger', 'Could not mark salary slip as paid.');
    }
    redirect('/hr/salary_slip_view.php?id=' . $id);
}

if (is_post() && input('action') === 'delete' && $slip['status'] === 'draft') {
    csrf_verify();
    db()->prepare('DELETE FROM salary_slips WHERE id = ?')->execute([$id]);
    flash('success', 'Salary slip deleted.');
    redirect('/hr/salary_slips.php');
}

$items = db()->prepare('SELECT * FROM salary_slip_items WHERE salary_slip_id = ? ORDER BY id');
$items->execute([$id]);
$items = $items->fetchAll();
$earnings = array_filter($items, fn($i) => $i['component_type'] === 'earning');
$deductions = array_filter($items, fn($i) => $i['component_type'] === 'deduction');

$page_title = 'Salary Slip ' . $slip['slip_no'];
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-1"><?= e($slip['slip_no']) ?> <span class="badge text-bg-<?= $slip['status'] === 'paid' ? 'success' : 'secondary' ?> badge-status"><?= e($slip['status']) ?></span></h4>
    <div class="text-muted"><?= e($slip['employee_name']) ?> (<?= e($slip['employee_code']) ?>) &middot; <?= e(date('M j', strtotime($slip['pay_period_start']))) ?> &ndash; <?= e(date('M j, Y', strtotime($slip['pay_period_end']))) ?></div>
  </div>
  <div class="page-actions">
    <a href="<?= base_url('print.php?doctype=salary_slip&id=' . $id) ?>" target="_blank" class="btn btn-outline-brand btn-sm"><i class="fa-solid fa-print"></i> Print Payslip</a>
    <?php if ($slip['status'] === 'draft'): ?>
      <form method="post" class="d-inline" data-confirm="Delete this draft salary slip?">
        <?= csrf_field() ?><input type="hidden" name="action" value="delete">
        <button class="btn btn-outline-danger btn-sm" type="submit"><i class="fa-solid fa-trash"></i> Delete</button>
      </form>
    <?php endif; ?>
    <a href="salary_slips.php" class="btn btn-outline-secondary btn-sm">Back to list</a>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card p-3">
      <div class="table-responsive">
        <table class="table">
          <thead><tr><th>Earnings</th><th class="text-end">Amount</th></tr></thead>
          <tbody>
            <tr><td>Basic Salary</td><td class="text-end"><?= money($slip['basic_salary']) ?></td></tr>
            <?php foreach ($earnings as $e): ?>
              <tr><td><?= e($e['label']) ?></td><td class="text-end"><?= money($e['amount']) ?></td></tr>
            <?php endforeach; ?>
            <tr class="table-light"><th>Total Earnings</th><th class="text-end"><?= money($slip['total_earnings']) ?></th></tr>
          </tbody>
        </table>
        <table class="table">
          <thead><tr><th>Deductions</th><th class="text-end">Amount</th></tr></thead>
          <tbody>
            <?php foreach ($deductions as $d): ?>
              <tr><td><?= e($d['label']) ?></td><td class="text-end"><?= money($d['amount']) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$deductions): ?><tr><td class="text-muted">No deductions</td><td class="text-end">&mdash;</td></tr><?php endif; ?>
            <tr class="table-light"><th>Total Deductions</th><th class="text-end"><?= money($slip['total_deductions']) ?></th></tr>
          </tbody>
        </table>
        <table class="table">
          <tbody><tr class="table-success"><th class="fs-5">Net Pay</th><th class="text-end fs-5"><?= money($slip['net_pay']) ?></th></tr></tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <?php if ($slip['status'] === 'draft'): ?>
    <div class="card p-3">
      <h6 class="mb-3">Mark as Paid</h6>
      <p class="small text-muted">This will also record a <?= money($slip['net_pay']) ?> expense under Finance → Expenses (category "Payroll").</p>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="mark_paid">
        <div class="mb-2">
          <label class="form-label">Payment Date</label>
          <input type="date" name="payment_date" class="form-control" value="<?= today() ?>" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Payment Method</label>
          <select name="payment_method" class="form-select">
            <option value="bank_transfer">Bank Transfer</option>
            <option value="cash">Cash</option>
            <option value="cheque">Cheque</option>
            <option value="other">Other</option>
          </select>
        </div>
        <button type="submit" class="btn btn-brand w-100">Mark as Paid</button>
      </form>
    </div>
    <?php else: ?>
    <div class="card p-3">
      <h6 class="mb-2"><i class="fa-solid fa-circle-check text-success"></i> Paid</h6>
      <div class="small text-muted mb-1">Payment Date: <?= e($slip['payment_date']) ?></div>
      <div class="small text-muted mb-1">Method: <?= e(str_replace('_', ' ', ucfirst($slip['payment_method']))) ?></div>
      <?php if ($slip['expense_id']): ?>
        <a href="<?= base_url('accounting/expenses.php') ?>" class="small">View in Finance → Expenses</a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
