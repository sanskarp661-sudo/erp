<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$pdo = db();
$pdo->exec("UPDATE invoices SET status='overdue' WHERE due_date IS NOT NULL AND due_date < CURDATE() AND status IN ('unpaid','partially_paid')");

$monthRevenue = (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM invoices WHERE status <> 'cancelled' AND MONTH(invoice_date)=MONTH(CURDATE()) AND YEAR(invoice_date)=YEAR(CURDATE())")->fetchColumn();
$unpaidTotal = (float)$pdo->query("SELECT COALESCE(SUM(total - amount_paid),0) FROM invoices WHERE status IN ('unpaid','partially_paid','overdue')")->fetchColumn();
$overdueCount = (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE status='overdue'")->fetchColumn();
$monthExpenses = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE MONTH(expense_date)=MONTH(CURDATE()) AND YEAR(expense_date)=YEAR(CURDATE())")->fetchColumn();

$recentInvoices = $pdo->query("
  SELECT i.id, i.invoice_no, i.status, i.total, i.amount_paid, i.invoice_date, c.name customer_name
  FROM invoices i JOIN customers c ON c.id = i.customer_id
  ORDER BY i.id DESC LIMIT 8
")->fetchAll();

$badge = ['unpaid' => 'secondary', 'partially_paid' => 'warning', 'paid' => 'success', 'overdue' => 'danger', 'cancelled' => 'dark'];

$page_title = 'Finance';
require __DIR__ . '/../includes/header.php';
?>
<div class="row g-3 mb-3">
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card"><div class="icon bg-green"><i class="fa-solid fa-arrow-trend-up"></i></div>
      <div><div class="value"><?= money($monthRevenue) ?></div><div class="label">Invoiced this month</div></div></div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card"><div class="icon bg-red"><i class="fa-solid fa-file-invoice-dollar"></i></div>
      <div><div class="value"><?= money($unpaidTotal) ?></div><div class="label">Outstanding balance</div></div></div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card"><div class="icon bg-orange"><i class="fa-solid fa-triangle-exclamation"></i></div>
      <div><div class="value"><?= $overdueCount ?></div><div class="label">Overdue invoices</div></div></div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card"><div class="icon bg-purple"><i class="fa-solid fa-receipt"></i></div>
      <div><div class="value"><?= money($monthExpenses) ?></div><div class="label">Expenses this month</div></div></div>
  </div>
</div>

<div class="d-flex gap-2 mb-3">
  <a href="invoice_form.php" class="btn btn-brand"><i class="fa-solid fa-plus"></i> New Invoice</a>
  <a href="purchase_invoices.php" class="btn btn-outline-brand">Purchase Invoices</a>
  <a href="expense_form.php" class="btn btn-outline-brand">Add Expense</a>
  <a href="<?= base_url('reports/financial_report.php') ?>" class="btn btn-outline-secondary">Full Report</a>
</div>

<div class="card p-3">
  <h6 class="mb-2">Recent Invoices</h6>
  <div class="table-responsive">
    <table class="table table-sm">
      <thead><tr><th>Invoice #</th><th>Customer</th><th>Date</th><th>Status</th><th class="text-end">Total</th><th class="text-end">Balance</th></tr></thead>
      <tbody>
      <?php foreach ($recentInvoices as $i): ?>
        <tr>
          <td><a href="invoice_view.php?id=<?= (int)$i['id'] ?>"><?= e($i['invoice_no']) ?></a></td>
          <td><?= e($i['customer_name']) ?></td>
          <td><?= e($i['invoice_date']) ?></td>
          <td><span class="badge text-bg-<?= $badge[$i['status']] ?? 'secondary' ?> badge-status"><?= e(str_replace('_', ' ', $i['status'])) ?></span></td>
          <td class="text-end"><?= money($i['total']) ?></td>
          <td class="text-end"><?= money($i['total'] - $i['amount_paid']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$recentInvoices): ?><tr><td colspan="6" class="text-muted text-center">No invoices yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
