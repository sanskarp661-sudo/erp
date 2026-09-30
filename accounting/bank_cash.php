<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
$page_title = 'Bank & Cash';
fin_require_schema();

$pdo = db();
$gl = fin_gl_sql();
$today = today();
$lastMonthEnd = date('Y-m-t', strtotime('first day of last month'));
$money = fin_money_accounts();
$isCash = fn($a) => $a['is_cash'] || $a['account_type'] === 'cash';
$bankIds = array_keys(array_filter($money, fn($a) => !$isCash($a)));
$cashIds = array_keys(array_filter($money, $isCash));
$moneyIds = array_keys($money);
$in = $moneyIds ? implode(',', $moneyIds) : '0';

$bank = fin_balance_of($bankIds);
$bankPrev = fin_balance_of($bankIds, $lastMonthEnd);
$cash = fin_balance_of($cashIds);
$cashPrev = fin_balance_of($cashIds, $lastMonthEnd);

// Flows exclude openings and transfers between our own bank / cash accounts.
$flows = function (string $from, string $to) use ($pdo, $gl, $in) {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(debit),0) i, COALESCE(SUM(credit),0) o FROM ($gl) g WHERE account_id IN ($in) AND source_type <> 'opening' AND voucher_type <> 'Contra' AND entry_date BETWEEN ? AND ?");
    $stmt->execute([$from, $to]);
    return $stmt->fetch();
};
$fyStart = fin_fy_start();
$ytd = $flows($fyStart, $today);
$ly = $flows(date('Y-m-d', strtotime($fyStart . ' -1 year')), date('Y-m-d', strtotime($today . ' -1 year')));

$range = in_array(input('range'), ['3', '6', '12'], true) ? (int)input('range') : 6;
$months = fin_last_months($range, 'M');
$from = array_key_first($months) . '-01';
$stmt = $pdo->prepare("SELECT DATE_FORMAT(entry_date, '%Y-%m') ym, SUM(debit) i, SUM(credit) o FROM ($gl) g WHERE account_id IN ($in) AND source_type <> 'opening' AND voucher_type <> 'Contra' AND entry_date BETWEEN ? AND ? GROUP BY ym");
$stmt->execute([$from, $today]);
$m = [];
foreach ($stmt as $r) $m[$r['ym']] = $r;
$inS = $outS = $closeS = [];
foreach ($months as $ym => $l) {
    $inS[] = round((float)($m[$ym]['i'] ?? 0), 2);
    $outS[] = round((float)($m[$ym]['o'] ?? 0), 2);
    $closeS[] = round(fin_balance_of($moneyIds, min($today, date('Y-m-t', strtotime($ym . '-01')))), 2);
}

$balances = fin_balances();

// Recent transactions on bank / cash accounts.
[$pageNo, $perPage, $offset] = fin_page(5);
$c = $pdo->query("SELECT COUNT(*) FROM ($gl) g WHERE account_id IN ($in) AND source_type <> 'opening'");
$total = (int)$c->fetchColumn();
$recent = $pdo->query("SELECT g.*, la.name account_name, bc.cleared_on FROM ($gl) g JOIN ledger_accounts la ON la.id = g.account_id
    LEFT JOIN fin_bank_clearances bc ON bc.source_type = g.source_type AND bc.source_id = g.source_id AND bc.account_id = g.account_id
    WHERE g.account_id IN ($in) AND g.source_type <> 'opening' ORDER BY g.entry_date DESC, g.voucher_no DESC LIMIT $perPage OFFSET $offset")->fetchAll();

$extra_js = fin_chart_js();
require __DIR__ . '/../includes/header.php';
fin_page_head('Bank & Cash', 'Monitor your bank accounts, cash balances and transactions in real-time.', fin_date_chip());
fin_kpi_row([
    fin_kpi('fa-solid fa-building-columns', 'blue', 'Total Bank Balance', fin_money($bank), fin_pct_change($bank, $bankPrev), 'vs last month'),
    fin_kpi('fa-solid fa-money-bill-wave', 'green', 'Total Cash in Hand', fin_money($cash), fin_pct_change($cash, $cashPrev), 'vs last month'),
    fin_kpi('fa-solid fa-arrow-right-arrow-left', 'blue', 'Total Inflows (YTD)', fin_money($ytd['i']), fin_pct_change((float)$ytd['i'], (float)$ly['i']), 'vs last year'),
    fin_kpi('fa-solid fa-arrow-trend-down', 'red', 'Total Outflows (YTD)', fin_money($ytd['o']), fin_pct_change((float)$ytd['o'], (float)$ly['o']), 'vs last year', null, false),
]);
?>
<div class="row g-3 mb-3">
  <div class="col-lg-7">
    <div class="fin-card">
      <div class="fin-card-head">
        <div><h2 class="fin-card-title">Cash Flow Overview</h2><p class="fin-card-sub">Track your bank inflows and outflows over time.</p></div>
        <form method="get"><select name="range" class="form-select form-select-sm" onchange="this.form.submit()">
          <?php foreach (['3' => 'Last 3 Months', '6' => 'Last 6 Months', '12' => 'Last 12 Months'] as $v => $l): ?><option value="<?= $v ?>" <?= (int)$v === $range ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
        </select></form>
      </div>
      <?= fin_chart('cashFlow', ['type' => 'bar', 'labels' => array_values($months), 'datasets' => [
          ['label' => 'Inflow', 'data' => $inS, 'backgroundColor' => '#6fcf97', 'order' => 2],
          ['label' => 'Outflow', 'data' => $outS, 'backgroundColor' => '#fca5a5', 'order' => 3],
          ['type' => 'line', 'label' => 'Closing Balance', 'data' => $closeS, 'borderColor' => '#2563eb', 'backgroundColor' => '#2563eb', 'order' => 1],
      ]], 260) ?>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="fin-card">
      <div class="fin-card-head"><h2 class="fin-card-title">Bank &amp; Cash Accounts</h2><a class="fin-link" href="ledger_accounts.php?type=asset">View All <i class="fa-solid fa-arrow-right"></i></a></div>
      <table class="table fin-table">
        <thead><tr><th>Account Name</th><th>Type</th><th class="text-end">Balance (<?= e(setting('currency_symbol', '₹')) ?>)</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($money as $id => $a): ?>
          <tr>
            <td><i class="fa-solid <?= $isCash($a) ? 'fa-money-bill text-success' : 'fa-building-columns text-primary' ?> me-2"></i><a class="text-reset" href="general_ledger.php?tab=all&account=<?= $id ?>"><?= e($a['name']) ?></a></td>
            <td><?= $isCash($a) ? fin_pill('Cash', 'success') : fin_pill('Bank', 'info') ?></td>
            <td class="text-end"><?= fin_amt($balances[$id]) ?></td>
            <td><?= fin_pill('Active', 'success') ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$money): ?><tr><td colspan="4" class="empty-state"><i class="fa-solid fa-building-columns"></i>No bank or cash ledgers yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<div class="row g-3">
  <div class="col-xxl-8">
    <div class="fin-card">
      <div class="fin-card-head"><h2 class="fin-card-title">Recent Bank &amp; Cash Transactions</h2><a class="fin-link" href="bank_reconciliation.php?show=all">View All <i class="fa-solid fa-arrow-right"></i></a></div>
      <div class="table-responsive">
        <table class="table fin-table">
          <thead><tr><th>Date</th><th>Voucher No.</th><th>Account</th><th>Party</th><th>Description</th><th class="text-end">Amount (<?= e(setting('currency_symbol', '₹')) ?>)</th><th>Type</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($recent as $r): $inflow = $r['debit'] > 0; $acct = $money[(int)$r['account_id']] ?? null; ?>
            <tr>
              <td class="text-nowrap"><?= fin_date($r['entry_date']) ?></td>
              <td class="text-nowrap"><?= e($r['voucher_no']) ?></td>
              <td class="text-nowrap"><?= e($r['account_name']) ?></td>
              <td><?= e($r['party'] ?: '—') ?></td>
              <td><?= e($r['description']) ?></td>
              <td class="text-end"><?= fin_num($inflow ? $r['debit'] : $r['credit']) ?></td>
              <td class="text-nowrap"><?= $inflow ? fin_pill('Inflow', 'success') : fin_pill('Outflow', 'danger') ?></td>
              <td><?php if ($acct && $isCash($acct)): ?><?= fin_pill('Completed', 'success') ?><?php elseif ($r['cleared_on']): ?><?= fin_pill('Reconciled', 'success') ?><?php else: ?><?= fin_pill('Uncleared', 'warning') ?><?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$recent): ?><tr><td colspan="8" class="empty-state"><i class="fa-solid fa-building-columns"></i>No bank or cash transactions yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
      <?= fin_pagination($total, $pageNo, $perPage, 'transactions') ?>
    </div>
  </div>
  <div class="col-xxl-4">
    <div class="fin-card">
      <div class="fin-card-head"><h2 class="fin-card-title">Quick Actions</h2></div>
      <div class="fin-tiles mb-3" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr))">
        <?= fin_action_tile('journal_form.php?type=bank_payment', 'fa-solid fa-file-export', 'blue', 'Record Bank Payment') ?>
        <?= fin_action_tile('journal_form.php?type=bank_receipt', 'fa-solid fa-file-import', 'green', 'Record Bank Receipt') ?>
        <?= fin_action_tile('journal_form.php?type=contra', 'fa-solid fa-right-left', 'purple', 'Bank Transfer') ?>
        <?= fin_action_tile('bank_reconciliation.php', 'fa-solid fa-list-check', 'orange', 'Reconcile Account') ?>
      </div>
      <a class="fin-callout" href="bank_reconciliation.php">
        <i class="fa-solid fa-chart-simple fs-3 text-primary"></i>
        <span><strong>Keep your cash flow healthy</strong><br><small>Reconcile your bank accounts regularly for accurate financial reporting.</small></span>
        <i class="fa-solid fa-arrow-right ms-auto text-primary"></i>
      </a>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
