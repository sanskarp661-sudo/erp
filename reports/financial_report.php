<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$start = input('start') ?: date('Y-01-01');
$end = input('end') ?: date('Y-m-d');

$pdo = db();

$revenueStmt = $pdo->prepare("SELECT COALESCE(SUM(total),0) FROM invoices WHERE invoice_date BETWEEN ? AND ? AND status <> 'cancelled'");
$revenueStmt->execute([$start, $end]);
$revenue = (float)$revenueStmt->fetchColumn();

$collectedStmt = $pdo->prepare("SELECT COALESCE(SUM(p.amount),0) FROM payments p JOIN invoices i ON i.id = p.invoice_id WHERE p.payment_date BETWEEN ? AND ?");
$collectedStmt->execute([$start, $end]);
$collected = (float)$collectedStmt->fetchColumn();

$expenseStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date BETWEEN ? AND ?");
$expenseStmt->execute([$start, $end]);
$expenses = (float)$expenseStmt->fetchColumn();

$net = $revenue - $expenses;

$monthlyRevStmt = $pdo->prepare("
  SELECT DATE_FORMAT(invoice_date, '%Y-%m') ym, SUM(total) t
  FROM invoices WHERE invoice_date BETWEEN ? AND ? AND status <> 'cancelled'
  GROUP BY ym ORDER BY ym
");
$monthlyRevStmt->execute([$start, $end]);
$monthlyRev = $monthlyRevStmt->fetchAll(PDO::FETCH_KEY_PAIR);

$monthlyExpStmt = $pdo->prepare("
  SELECT DATE_FORMAT(expense_date, '%Y-%m') ym, SUM(amount) t
  FROM expenses WHERE expense_date BETWEEN ? AND ?
  GROUP BY ym ORDER BY ym
");
$monthlyExpStmt->execute([$start, $end]);
$monthlyExp = $monthlyExpStmt->fetchAll(PDO::FETCH_KEY_PAIR);

$months = array_unique(array_merge(array_keys($monthlyRev), array_keys($monthlyExp)));
sort($months);
$revSeries = array_map(fn($m) => (float)($monthlyRev[$m] ?? 0), $months);
$expSeries = array_map(fn($m) => (float)($monthlyExp[$m] ?? 0), $months);

$byCategoryStmt = $pdo->prepare("SELECT category, SUM(amount) total FROM expenses WHERE expense_date BETWEEN ? AND ? GROUP BY category ORDER BY total DESC");
$byCategoryStmt->execute([$start, $end]);
$byCategory = $byCategoryStmt->fetchAll();

$page_title = 'Financial Report';
require __DIR__ . '/../includes/header.php';
?>
<form method="get" class="row g-2 mb-3 align-items-end">
  <div class="col-auto"><label class="form-label mb-1">From</label><input type="date" name="start" class="form-control" value="<?= e($start) ?>"></div>
  <div class="col-auto"><label class="form-label mb-1">To</label><input type="date" name="end" class="form-control" value="<?= e($end) ?>"></div>
  <div class="col-auto"><button class="btn btn-brand">Apply</button></div>
</form>

<div class="row g-3 mb-3">
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card"><div class="icon bg-green"><i class="fa-solid fa-arrow-trend-up"></i></div>
      <div><div class="value"><?= money($revenue) ?></div><div class="label">Revenue (invoiced)</div></div></div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card"><div class="icon bg-brand"><i class="fa-solid fa-hand-holding-dollar"></i></div>
      <div><div class="value"><?= money($collected) ?></div><div class="label">Cash collected</div></div></div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card"><div class="icon bg-red"><i class="fa-solid fa-arrow-trend-down"></i></div>
      <div><div class="value"><?= money($expenses) ?></div><div class="label">Expenses</div></div></div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card"><div class="icon bg-<?= $net >= 0 ? 'green' : 'red' ?>"><i class="fa-solid fa-scale-balanced"></i></div>
      <div><div class="value"><?= money($net) ?></div><div class="label">Net (Revenue - Expenses)</div></div></div>
  </div>
</div>

<div class="card p-3 mb-3">
  <h6 class="mb-3">Revenue vs Expenses</h6>
  <canvas id="finChart" height="90"></canvas>
</div>

<div class="card p-3">
  <h6 class="mb-2">Expenses by Category</h6>
  <div class="table-responsive">
    <table class="table table-sm">
      <thead><tr><th>Category</th><th class="text-end">Total</th></tr></thead>
      <tbody>
      <?php foreach ($byCategory as $c): ?>
        <tr><td><?= e($c['category']) ?></td><td class="text-end"><?= money($c['total']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$byCategory): ?><tr><td colspan="2" class="text-muted text-center">No expenses in this period.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php
$extra_js = ['https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js'];
$extra_js_inline = "
new Chart(document.getElementById('finChart'), {
  type: 'bar',
  data: {
    labels: " . json_encode($months) . ",
    datasets: [
      { label: 'Revenue', data: " . json_encode($revSeries) . ", backgroundColor: '#16a34a' },
      { label: 'Expenses', data: " . json_encode($expSeries) . ", backgroundColor: '#dc2626' }
    ]
  },
  options: { scales: { y: { beginAtZero: true } } }
});
";
require __DIR__ . '/../includes/footer.php';
