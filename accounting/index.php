<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
$page_title = 'Finance';
fin_require_schema();

$pdo = db();
$pdo->exec("UPDATE invoices SET status='overdue' WHERE due_date IS NOT NULL AND due_date < CURDATE() AND status IN ('unpaid','partially_paid')");

$today = today();
$lastMonthEnd = date('Y-m-t', strtotime('first day of last month'));
$moneyIds = array_keys(fin_money_accounts());
$ar = fin_account_id('receivable');
$ap = fin_account_id('payable');

$receivables = fin_balance_of([$ar]);
$receivablesPrev = fin_balance_of([$ar], $lastMonthEnd);
$payables = -fin_balance_of([$ap]);
$payablesPrev = -fin_balance_of([$ap], $lastMonthEnd);
$cashBank = fin_balance_of($moneyIds);
$cashBankPrev = fin_balance_of($moneyIds, $lastMonthEnd);

$incomeIds = fin_leaf_ids_by_root(['income']);
$expenseIds = fin_leaf_ids_by_root(['expense']);
$fyStart = fin_fy_start();
$lyStart = date('Y-m-d', strtotime($fyStart . ' -1 year'));
$lyToday = date('Y-m-d', strtotime($today . ' -1 year'));
$incomeTotals = fin_account_totals($fyStart, $today);
$incomeYtd = 0.0;
foreach ($incomeIds as $id) { $incomeYtd += ($incomeTotals[$id]['cr'] ?? 0) - ($incomeTotals[$id]['dr'] ?? 0); }
$incomeLyTotals = fin_account_totals($lyStart, $lyToday);
$incomeLy = 0.0;
foreach ($incomeIds as $id) { $incomeLy += ($incomeLyTotals[$id]['cr'] ?? 0) - ($incomeLyTotals[$id]['dr'] ?? 0); }

// Income vs expenses, by month.
$range = in_array(input('range'), ['3', '6', '12'], true) ? (int)input('range') : 6;
$months = fin_last_months($range);
$from = array_key_first($months) . '-01';
$incM = fin_monthly_totals($incomeIds, $from, $today);
$expM = fin_monthly_totals($expenseIds, $from, $today);
$incomeSeries = $expenseSeries = [];
foreach ($months as $ym => $label) {
    $incomeSeries[] = round(($incM[$ym]['cr'] ?? 0) - ($incM[$ym]['dr'] ?? 0), 2);
    $expenseSeries[] = round(($expM[$ym]['dr'] ?? 0) - ($expM[$ym]['cr'] ?? 0), 2);
}

// Expense breakdown this month, by expense ledger (top 5 + others).
$monthStart = date('Y-m-01');
$monthTotals = fin_account_totals($monthStart, $today);
$accounts = fin_accounts();
$breakdown = [];
foreach ($expenseIds as $id) {
    $v = ($monthTotals[$id]['dr'] ?? 0) - ($monthTotals[$id]['cr'] ?? 0);
    if ($v > 0.005) $breakdown[$accounts[$id]['name']] = $v;
}
arsort($breakdown);
if (count($breakdown) > 6) {
    $top = array_slice($breakdown, 0, 5, true);
    $top['Others'] = array_sum(array_slice($breakdown, 5));
    $breakdown = $top;
}
$breakdownTotal = array_sum($breakdown);

// Recent transactions: receipts, payments and expenses, newest first.
$recent = $pdo->query("
  (SELECT p.payment_date d, 'Receipt' t, CONVERT(c.name USING utf8mb4) COLLATE utf8mb4_unicode_ci party, CONVERT(CONCAT('Payment for ', i.invoice_no) USING utf8mb4) COLLATE utf8mb4_unicode_ci descr, p.amount amt, 'Completed' st, CONCAT('invoice_view.php?id=', i.id) url, p.id sid
     FROM payments p JOIN invoices i ON i.id = p.invoice_id JOIN customers c ON c.id = i.customer_id)
  UNION ALL
  (SELECT pp.payment_date, 'Payment', v.name, CONCAT('Payment for ', pi.pi_no), pp.amount, 'Completed', CONCAT('purchase_invoice_view.php?id=', pi.id), pp.id
     FROM purchase_payments pp JOIN purchase_invoices pi ON pi.id = pp.purchase_invoice_id JOIN vendors v ON v.id = pi.vendor_id)
  UNION ALL
  (SELECT e.expense_date, 'Expense', " . fin_coll('COALESCE(v.name, e.payee, e.category)') . ", COALESCE(NULLIF(e.description,''), e.category), e.amount,
          CASE e.status WHEN 'approved' THEN 'Completed' WHEN 'pending' THEN 'Pending' ELSE 'Rejected' END, CONCAT('expense_form.php?id=', e.id), e.id
     FROM expenses e LEFT JOIN vendors v ON v.id = e.vendor_id)
  ORDER BY d DESC, sid DESC LIMIT 5
")->fetchAll();
$typeTone = ['Receipt' => 'success', 'Payment' => 'danger', 'Expense' => 'warning'];
$statusTone = ['Completed' => 'success', 'Pending' => 'warning', 'Rejected' => 'danger'];

$extra_js = fin_chart_js();
require __DIR__ . '/../includes/header.php';

fin_page_head('Finance Overview', 'Get a real-time view of your financial health and manage your business finances efficiently.', fin_date_chip());
fin_kpi_row([
    fin_kpi('fa-solid fa-wallet', 'blue', 'Total Receivables', fin_money($receivables), fin_pct_change($receivables, $receivablesPrev), 'vs last month'),
    fin_kpi('fa-solid fa-credit-card', 'red', 'Total Payables', fin_money($payables), fin_pct_change($payables, $payablesPrev), 'vs last month', null, false, 'red'),
    fin_kpi('fa-solid fa-building-columns', 'green', 'Cash & Bank Balance', fin_money($cashBank), fin_pct_change($cashBank, $cashBankPrev), 'vs last month'),
    fin_kpi('fa-solid fa-chart-simple', 'purple', 'Total Income (YTD)', fin_money($incomeYtd), fin_pct_change($incomeYtd, $incomeLy), 'vs last year'),
]);
?>
<div class="row g-3 mb-3">
  <div class="col-lg-7">
    <div class="fin-card">
      <div class="fin-card-head">
        <div><h2 class="fin-card-title">Income vs Expenses</h2><p class="fin-card-sub">Track your financial performance over time.</p></div>
        <form method="get"><select name="range" class="form-select form-select-sm" onchange="this.form.submit()">
          <?php foreach (['3' => 'Last 3 Months', '6' => 'Last 6 Months', '12' => 'Last 12 Months'] as $v => $l): ?>
            <option value="<?= $v ?>" <?= (int)$v === $range ? 'selected' : '' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select></form>
      </div>
      <?= fin_chart('incExpChart', ['type' => 'bar', 'labels' => array_values($months), 'datasets' => [
          ['label' => 'Income', 'data' => $incomeSeries, 'backgroundColor' => '#2563eb'],
          ['label' => 'Expenses', 'data' => $expenseSeries, 'backgroundColor' => '#c4b5fd'],
      ]], 270) ?>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="fin-card">
      <div class="fin-card-head"><h2 class="fin-card-title">Expense Breakdown (This Month)</h2></div>
      <?php if ($breakdown): $pal = fin_palette(); ?>
      <div class="row align-items-center g-2">
        <div class="col-sm-6 position-relative">
          <?= fin_chart('expDonut', ['type' => 'doughnut', 'labels' => array_keys($breakdown), 'datasets' => [['label' => 'Expenses', 'data' => array_map(fn($v) => round($v, 2), array_values($breakdown)), 'backgroundColor' => array_slice($pal, 0, count($breakdown))]]], 220) ?>
          <div class="position-absolute top-50 start-50 translate-middle text-center" style="pointer-events:none"><div class="fw-bold fs-5"><?= fin_money($breakdownTotal) ?></div><div class="small text-muted">Total Expenses</div></div>
        </div>
        <div class="col-sm-6">
          <ul class="fin-legend">
            <?php $i = 0; foreach ($breakdown as $name => $v): ?>
              <li><span class="dot" style="background:<?= $pal[$i++] ?>"></span><?= e($name) ?><span class="pct"><?= round($v / $breakdownTotal * 100) ?>%</span></li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>
      <?php else: ?>
        <div class="empty-state"><i class="fa-solid fa-chart-pie"></i>No expenses posted this month yet.</div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="fin-card">
      <div class="fin-card-head"><h2 class="fin-card-title">Recent Transactions</h2><a class="fin-link" href="general_ledger.php">View all <i class="fa-solid fa-arrow-right"></i></a></div>
      <div class="table-responsive">
        <table class="table fin-table">
          <thead><tr><th>Date</th><th>Type</th><th>Party</th><th>Description</th><th class="text-end">Amount (<?= e(setting('currency_symbol', '₹')) ?>)</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($recent as $r): ?>
            <tr>
              <td class="text-nowrap"><?= fin_date($r['d']) ?></td>
              <td><?= fin_pill($r['t'], $typeTone[$r['t']]) ?></td>
              <td><?= e($r['party']) ?></td>
              <td><a href="<?= e($r['url']) ?>" class="text-reset"><?= e($r['descr']) ?></a></td>
              <td class="text-end"><?= fin_num($r['amt']) ?></td>
              <td><?= fin_pill($r['st'], $statusTone[$r['st']]) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$recent): ?><tr><td colspan="6" class="empty-state"><i class="fa-solid fa-receipt"></i>No transactions yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="fin-card">
      <div class="fin-card-head"><h2 class="fin-card-title">Quick Actions</h2></div>
      <div class="fin-tiles">
        <?= fin_action_tile('journal_form.php?type=bank_payment', 'fa-solid fa-file-export', 'green', 'Record Payment') ?>
        <?= fin_action_tile('journal_form.php?type=bank_receipt', 'fa-solid fa-file-import', 'blue', 'Record Receipt') ?>
        <?= fin_action_tile('journal_form.php', 'fa-solid fa-file-pen', 'orange', 'Create Journal Entry') ?>
        <?= fin_action_tile('expense_form.php', 'fa-solid fa-wallet', 'purple', 'Add Expense') ?>
        <?= fin_action_tile('bank_reconciliation.php', 'fa-solid fa-building-columns', 'red', 'Bank Reconciliation') ?>
        <?= fin_action_tile('reports.php', 'fa-solid fa-file-lines', 'slate', 'View Reports') ?>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
