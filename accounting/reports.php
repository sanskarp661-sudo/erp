<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
$page_title = 'Financial Reports';
fin_require_schema();

$pdo = db();
$today = today();
$fyStart = fin_fy_start();
$lyStart = date('Y-m-d', strtotime($fyStart . ' -1 year'));
$lyToday = date('Y-m-d', strtotime($today . ' -1 year'));
$lastMonthEnd = date('Y-m-t', strtotime('first day of last month'));

$pl = function (string $from, string $to) {
    $t = fin_account_totals($from, $to);
    $inc = $exp = 0.0;
    foreach (fin_leaf_ids_by_root(['income']) as $id) $inc += ($t[$id]['cr'] ?? 0) - ($t[$id]['dr'] ?? 0);
    foreach (fin_leaf_ids_by_root(['expense']) as $id) $exp += ($t[$id]['dr'] ?? 0) - ($t[$id]['cr'] ?? 0);
    return [$inc, $exp];
};
[$rev, $exp] = $pl($fyStart, $today);
[$revLy, $expLy] = $pl($lyStart, $lyToday);
$moneyIds = array_keys(fin_money_accounts());
$cash = fin_balance_of($moneyIds);
$cashPrev = fin_balance_of($moneyIds, $lastMonthEnd);

// P&L by month for this / last fiscal year.
$yearSel = input('year') === 'last' ? 'last' : 'this';
$chartStart = $yearSel === 'last' ? $lyStart : $fyStart;
$incM = fin_monthly_totals(fin_leaf_ids_by_root(['income']), $chartStart, fin_fy_end($chartStart));
$expM = fin_monthly_totals(fin_leaf_ids_by_root(['expense']), $chartStart, fin_fy_end($chartStart));
$labels = $rS = $eS = $nS = [];
for ($i = 0; $i < 12; $i++) {
    $ts = strtotime($chartStart . " +$i month");
    if ($yearSel === 'this' && $ts > strtotime($today)) break;
    $ym = date('Y-m', $ts);
    $r = ($incM[$ym]['cr'] ?? 0) - ($incM[$ym]['dr'] ?? 0);
    $x = ($expM[$ym]['dr'] ?? 0) - ($expM[$ym]['cr'] ?? 0);
    $labels[] = date('M', $ts);
    $rS[] = round($r, 2); $eS[] = round($x, 2); $nS[] = round($r - $x, 2);
}

// Expense breakdown YTD: expense vouchers by category, everything else by ledger.
$stmt = $pdo->prepare("SELECT CASE WHEN g.source_type = 'expense' THEN e.category ELSE la.name END k, SUM(g.debit - g.credit) v
    FROM (" . fin_gl_sql() . ") g JOIN ledger_accounts la ON la.id = g.account_id LEFT JOIN expenses e ON g.source_type = 'expense' AND e.id = g.source_id
    WHERE la.root_type = 'expense' AND la.is_group = 0 AND g.entry_date BETWEEN ? AND ? GROUP BY k HAVING v > 0 ORDER BY v DESC");
$stmt->execute([$fyStart, $today]);
$breakdown = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
if (count($breakdown) > 6) {
    $top = array_slice($breakdown, 0, 5, true);
    $top['Others'] = array_sum(array_slice($breakdown, 5));
    $breakdown = $top;
}
$bTotal = array_sum($breakdown);

$recent = $pdo->query("SELECT r.*, u.name user_name FROM fin_report_log r LEFT JOIN users u ON u.id = r.generated_by ORDER BY r.id DESC LIMIT 5")->fetchAll();
$typeNames = ['pl' => 'Profit & Loss', 'bs' => 'Balance Sheet', 'cf' => 'Cash Flow', 'tb' => 'Trial Balance', 'statement' => 'Account Statement', 'aging' => 'Aging Report'];

$fy = ['from' => $fyStart, 'to' => $today];
$cards = [
    ['Profit & Loss', 'Income, expenses and net profit', 'fa-solid fa-sack-dollar', 'green', 'statement.php?' . http_build_query(['type' => 'pl'] + $fy + ['log' => 1])],
    ['Balance Sheet', 'Assets, liabilities and equity position', 'fa-solid fa-scale-balanced', 'blue', 'statement.php?' . http_build_query(['type' => 'bs', 'to' => $today, 'log' => 1])],
    ['Cash Flow', 'Inflow and outflow of cash', 'fa-solid fa-right-left', 'purple', 'statement.php?' . http_build_query(['type' => 'cf'] + $fy + ['log' => 1])],
    ['Trial Balance', 'Verify account balances', 'fa-solid fa-file-lines', 'orange', 'statement.php?' . http_build_query(['type' => 'tb', 'to' => $today, 'log' => 1])],
    ['General Ledger', 'Detailed account transactions', 'fa-solid fa-book-open', 'blue', 'general_ledger.php'],
    ['Account Statement', 'Individual account statement', 'fa-solid fa-user', 'red', 'statement.php?' . http_build_query(['type' => 'statement'] + $fy)],
    ['Aging Reports', 'Receivables and payables aging', 'fa-regular fa-clock', 'green', 'statement.php?' . http_build_query(['type' => 'aging', 'to' => $today, 'log' => 1])],
    ['Custom Reports', 'Revenue and expense summary for any date range', 'fa-solid fa-sliders', 'slate', base_url('reports/financial_report.php')],
];

$extra_js = fin_chart_js();
require __DIR__ . '/../includes/header.php';
fin_page_head('Financial Reports', 'Get real-time insights into your business performance with accurate and comprehensive reports.', fin_date_chip(fin_date($fyStart) . ' - ' . fin_date($today)));
fin_kpi_row([
    fin_kpi('fa-solid fa-chart-simple', 'blue', 'Total Revenue (YTD)', fin_money($rev), fin_pct_change($rev, $revLy), 'vs last year'),
    fin_kpi('fa-solid fa-arrow-trend-down', 'red', 'Total Expenses (YTD)', fin_money($exp), fin_pct_change($exp, $expLy), 'vs last year', null, false),
    fin_kpi('fa-solid fa-coins', 'green', 'Net Profit (YTD)', fin_money($rev - $exp), fin_pct_change($rev - $exp, $revLy - $expLy), 'vs last year'),
    fin_kpi('fa-solid fa-chart-pie', 'purple', 'Cash Balance', fin_money($cash), fin_pct_change($cash, $cashPrev), 'vs last month'),
]);
?>
<div class="fin-card mb-3">
  <div class="fin-card-head">
    <div><h2 class="fin-card-title">Reports</h2><p class="fin-card-sub">Choose a report category to view detailed insights.</p></div>
    <div class="fin-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" class="form-control form-control-sm" id="reportSearch" placeholder="Search reports..."></div>
  </div>
  <div class="row g-3" id="reportCards">
    <?php foreach ($cards as [$t, $d, $icon, $tone, $href]): ?>
      <div class="col-sm-6 col-xl-3" data-report="<?= e(strtolower($t . ' ' . $d)) ?>">
        <a class="fin-tool" href="<?= e($href) ?>"><span class="fin-kpi-icon tone-<?= $tone ?>"><i class="<?= $icon ?>"></i></span><span><strong><?= e($t) ?></strong><small><?= e($d) ?></small></span></a>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<div class="row g-3 mb-3">
  <div class="col-lg-7">
    <div class="fin-card">
      <div class="fin-card-head">
        <h2 class="fin-card-title">Profit &amp; Loss Overview</h2>
        <form method="get"><select name="year" class="form-select form-select-sm" onchange="this.form.submit()"><option value="this">This Year</option><option value="last" <?= $yearSel === 'last' ? 'selected' : '' ?>>Last Year</option></select></form>
      </div>
      <?= fin_chart('plChart', ['type' => 'bar', 'labels' => $labels, 'datasets' => [
          ['label' => 'Revenue', 'data' => $rS, 'backgroundColor' => '#2563eb'],
          ['label' => 'Expenses', 'data' => $eS, 'backgroundColor' => '#c4b5fd'],
          ['label' => 'Net Profit', 'data' => $nS, 'backgroundColor' => '#22c55e'],
      ]], 250) ?>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="fin-card">
      <div class="fin-card-head"><h2 class="fin-card-title">Expense Breakdown (YTD)</h2></div>
      <?php if ($breakdown): $pal = fin_palette(); ?>
      <div class="row align-items-center g-2">
        <div class="col-sm-6 position-relative">
          <?= fin_chart('expYtd', ['type' => 'doughnut', 'labels' => array_keys($breakdown), 'datasets' => [['label' => 'Expenses', 'data' => array_map(fn($v) => round((float)$v, 2), array_values($breakdown)), 'backgroundColor' => array_slice($pal, 0, count($breakdown))]]], 210) ?>
          <div class="position-absolute top-50 start-50 translate-middle text-center" style="pointer-events:none"><div class="fw-bold"><?= fin_money($bTotal) ?></div><div class="small text-muted">Total Expenses</div></div>
        </div>
        <div class="col-sm-6"><ul class="fin-legend">
          <?php $i = 0; foreach ($breakdown as $name => $v): ?><li><span class="dot" style="background:<?= $pal[$i++] ?>"></span><?= e($name) ?><span class="pct"><?= round($v / $bTotal * 100) ?>%</span></li><?php endforeach; ?>
        </ul></div>
      </div>
      <?php else: ?><div class="empty-state"><i class="fa-solid fa-chart-pie"></i>No expenses this year yet.</div><?php endif; ?>
    </div>
  </div>
</div>
<div class="fin-card">
  <div class="fin-card-head"><h2 class="fin-card-title">Recent Reports</h2></div>
  <div class="table-responsive">
    <table class="table fin-table">
      <thead><tr><th>Report Name</th><th>Report Type</th><th>Period</th><th>Generated On</th><th>Generated By</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($recent as $r): ?>
        <tr>
          <td><?= e($r['report_name']) ?></td>
          <td><?= e($typeNames[$r['report_type']] ?? $r['report_type']) ?></td>
          <td><?= e($r['period_label']) ?></td>
          <td class="text-nowrap"><?= e(date('d M Y, h:i A', strtotime($r['generated_at']))) ?></td>
          <td><?= e($r['user_name'] ?? '—') ?></td>
          <td class="text-end text-nowrap">
            <a class="btn btn-sm btn-outline-brand" href="statement.php?<?= e($r['query_string']) ?>&export=csv"><i class="fa-solid fa-download"></i> Download</a>
            <a class="btn btn-sm btn-link text-secondary" href="statement.php?<?= e($r['query_string']) ?>" title="Open"><i class="fa-solid fa-arrow-up-right-from-square"></i></a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$recent): ?><tr><td colspan="6" class="empty-state"><i class="fa-solid fa-file-lines"></i>Reports you open or download will be listed here.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php
$extra_js_inline = "
document.getElementById('reportSearch').addEventListener('input', function () {
  var q = this.value.trim().toLowerCase();
  document.querySelectorAll('#reportCards [data-report]').forEach(function (c) { c.style.display = c.dataset.report.indexOf(q) > -1 ? '' : 'none'; });
});";
require __DIR__ . '/../includes/footer.php';
