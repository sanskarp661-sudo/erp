<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
$page_title = 'General Ledger';
fin_require_schema();

$pdo = db();
$gl = fin_gl_sql();
$accounts = fin_accounts();
$costCenters = $pdo->query('SELECT id, name FROM fin_cost_centers ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);
$today = today();
$fyStart = fin_fy_start();
$lastMonthEnd = date('Y-m-t', strtotime('first day of last month'));

/** Debit / credit sums of real postings (openings excluded) in a date range. */
$sums = function (string $from, string $to) use ($pdo, $gl) {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(debit),0) dr, COALESCE(SUM(credit),0) cr FROM ($gl) g WHERE source_type <> 'opening' AND entry_date BETWEEN ? AND ?");
    $stmt->execute([$from, $to]);
    return $stmt->fetch();
};
$ytd = $sums($fyStart, $today);
$ly = $sums(date('Y-m-d', strtotime($fyStart . ' -1 year')), date('Y-m-d', strtotime($today . ' -1 year')));
// Closing balance = cash & bank on hand (what the ledger closes with).
$moneyIds = array_keys(fin_money_accounts());
$closing = fin_balance_of($moneyIds);
$closingPrev = fin_balance_of($moneyIds, $lastMonthEnd);
$leafCount = count(array_filter($accounts, fn($a) => !$a['is_group']));
$leafPrev = count(array_filter($accounts, fn($a) => !$a['is_group'] && $a['created_at'] <= $lastMonthEnd . ' 23:59:59'));

// Monthly activity for this / last fiscal year.
$yearSel = input('year') === 'last' ? 'last' : 'this';
$chartStart = $yearSel === 'last' ? date('Y-m-d', strtotime($fyStart . ' -1 year')) : $fyStart;
$chartEnd = fin_fy_end($chartStart);
$stmt = $pdo->prepare("SELECT DATE_FORMAT(entry_date, '%Y-%m') ym, SUM(debit) dr, SUM(credit) cr FROM ($gl) g WHERE source_type <> 'opening' AND entry_date BETWEEN ? AND ? GROUP BY ym");
$stmt->execute([$chartStart, $chartEnd]);
$monthly = [];
foreach ($stmt as $r) $monthly[$r['ym']] = $r;
$mLabels = $mDr = $mCr = [];
for ($i = 0; $i < 12; $i++) {
    $ts = strtotime($chartStart . " +$i month");
    if ($yearSel === 'this' && $ts > strtotime($today)) break;
    $ym = date('Y-m', $ts);
    $mLabels[] = date('M', $ts);
    $mDr[] = round((float)($monthly[$ym]['dr'] ?? 0), 2);
    $mCr[] = round((float)($monthly[$ym]['cr'] ?? 0), 2);
}

// Top 5 account balances.
$balances = fin_balances();
$leafBal = [];
foreach ($accounts as $id => $a) {
    if (!$a['is_group'] && abs($balances[$id]) > 0.005) $leafBal[$id] = $balances[$id];
}
uasort($leafBal, fn($x, $y) => abs($y) <=> abs($x));
$leafBal = array_slice($leafBal, 0, 5, true);

// ---- entries ----
$tabs = ['all' => 'All Entries', 'account' => 'By Account', 'cost_center' => 'By Cost Center', 'project' => 'By Project'];
$tab = isset($tabs[input('tab')]) ? input('tab') : 'all';
$q = trim((string)input('q'));
$acctFilter = (int)input('account');
$ccFilter = (int)input('cc');
$projFilter = trim((string)input('project'));
$typeFilter = trim((string)input('vtype'));
$from = input('from');
$to = input('to');

$where = ['1=1'];
$params = [];
if ($q !== '') { $where[] = '(g.voucher_no LIKE ? OR la.name LIKE ? OR la.account_code LIKE ? OR g.description LIKE ? OR g.party LIKE ?)'; array_push($params, "%$q%", "%$q%", "%$q%", "%$q%", "%$q%"); }
if ($acctFilter) { $where[] = 'g.account_id = ?'; $params[] = $acctFilter; }
if ($ccFilter) { $where[] = 'g.cost_center_id = ?'; $params[] = $ccFilter; }
if ($projFilter !== '') { $where[] = 'g.project = ?'; $params[] = $projFilter; }
if ($typeFilter !== '') { $where[] = 'g.voucher_type = ?'; $params[] = $typeFilter; }
if ($from) { $where[] = 'g.entry_date >= ?'; $params[] = $from; }
if ($to) { $where[] = 'g.entry_date <= ?'; $params[] = $to; }
$whereSql = implode(' AND ', $where);

// Running balance per account over its whole history, then filtered.
$ledgerSql = "SELECT g.*, la.account_code, la.name account_name,
    SUM(g.debit - g.credit) OVER (PARTITION BY g.account_id ORDER BY g.entry_date, g.source_type = 'opening' DESC, g.voucher_no, g.source_id ROWS UNBOUNDED PRECEDING) running
    FROM ($gl) g JOIN ledger_accounts la ON la.id = g.account_id";
$listSql = "SELECT * FROM ($ledgerSql) x WHERE " . str_replace(['g.', 'la.name', 'la.account_code'], ['x.', 'x.account_name', 'x.account_code'], $whereSql);

if (input('export') === 'csv') {
    $stmt = $pdo->prepare("$listSql ORDER BY entry_date DESC, voucher_no DESC");
    $stmt->execute($params);
    $rows = [];
    foreach ($stmt as $r) {
        $rows[] = [$r['entry_date'], $r['voucher_no'], $r['voucher_type'], $r['account_code'], $r['account_name'], $r['party'], $r['description'], $r['debit'], $r['credit'], round($r['running'], 2)];
    }
    fin_csv('general-ledger.csv', ['Date', 'Voucher No', 'Voucher Type', 'Account Code', 'Account Name', 'Party', 'Description', 'Debit', 'Credit', 'Balance'], $rows);
}

[$pageNo, $perPage, $offset] = fin_page(10);
if ($tab === 'all') {
    $c = $pdo->prepare("SELECT COUNT(*) FROM ($listSql) y");
    $c->execute($params);
    $total = (int)$c->fetchColumn();
    $stmt = $pdo->prepare("$listSql ORDER BY entry_date DESC, voucher_no DESC, account_id LIMIT $perPage OFFSET $offset");
    $stmt->execute($params);
    $entries = $stmt->fetchAll();
} else {
    $groupCol = ['account' => 'g.account_id', 'cost_center' => 'g.cost_center_id', 'project' => 'g.project'][$tab];
    $stmt = $pdo->prepare("SELECT $groupCol k, COUNT(*) n, SUM(g.debit) dr, SUM(g.credit) cr FROM ($gl) g JOIN ledger_accounts la ON la.id = g.account_id
        WHERE $whereSql GROUP BY $groupCol ORDER BY SUM(g.debit) + SUM(g.credit) DESC");
    $stmt->execute($params);
    $groups = $stmt->fetchAll();
}
$voucherTypes = $pdo->query("SELECT DISTINCT voucher_type FROM ($gl) g ORDER BY voucher_type")->fetchAll(PDO::FETCH_COLUMN);
$canEdit = can_edit_module('finance');

$extra_js = fin_chart_js();
require __DIR__ . '/../includes/header.php';

$exportUrl = '?' . e(http_build_query(array_merge($_GET, ['export' => 'csv', 'tab' => 'all'])));
$actions = '<div class="btn-row"><a href="' . $exportUrl . '" class="btn btn-outline-secondary"><i class="fa-solid fa-download"></i> Export</a>'
    . '<a href="journals.php" class="btn btn-outline-brand"><i class="fa-solid fa-book-open"></i> Journal Entries</a>'
    . ($canEdit ? '<a href="ledger_account_form.php" class="btn btn-brand"><i class="fa-solid fa-plus"></i> Create Ledger</a>' : '') . '</div>';
fin_page_head('General Ledger', 'View and analyze all accounting transactions across your organization.', $actions);
fin_kpi_row([
    fin_kpi('fa-solid fa-book-open', 'blue', 'Total Accounts', (string)$leafCount, fin_pct_change($leafCount, $leafPrev), 'vs last month'),
    fin_kpi('fa-solid fa-arrow-trend-up', 'green', 'Total Debits (YTD)', fin_money($ytd['dr']), fin_pct_change((float)$ytd['dr'], (float)$ly['dr']), 'vs last year'),
    fin_kpi('fa-solid fa-arrow-trend-down', 'red', 'Total Credits (YTD)', fin_money($ytd['cr']), fin_pct_change((float)$ytd['cr'], (float)$ly['cr']), 'vs last year'),
    fin_kpi('fa-solid fa-scale-balanced', 'purple', 'Closing Balance', fin_money($closing), fin_pct_change($closing, $closingPrev), 'vs last month'),
]);
?>
<div class="row g-3 mb-3">
  <div class="col-lg-7">
    <div class="fin-card">
      <div class="fin-card-head">
        <div><h2 class="fin-card-title">Monthly Ledger Activity</h2><p class="fin-card-sub">Total debits and credits over time.</p></div>
        <form method="get"><select name="year" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="this">This Year (FY <?= fin_fy_label($fyStart) ?>)</option>
          <option value="last" <?= $yearSel === 'last' ? 'selected' : '' ?>>Last Year</option>
        </select></form>
      </div>
      <?= fin_chart('ledgerChart', ['type' => 'bar', 'labels' => $mLabels, 'datasets' => [
          ['label' => 'Total Debits', 'data' => $mDr, 'backgroundColor' => '#2563eb'],
          ['label' => 'Total Credits', 'data' => $mCr, 'backgroundColor' => '#c4b5fd'],
      ]], 260) ?>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="fin-card">
      <div class="fin-card-head"><h2 class="fin-card-title">Account Balances (Top 5)</h2><a class="fin-link" href="ledger_accounts.php">View All <i class="fa-solid fa-arrow-right"></i></a></div>
      <table class="table fin-table">
        <thead><tr><th>Account Code</th><th>Account Name</th><th class="text-end">Balance (<?= e(setting('currency_symbol', '₹')) ?>)</th></tr></thead>
        <tbody>
        <?php foreach ($leafBal as $id => $b): ?>
          <tr><td><?= e($accounts[$id]['account_code'] ?: '—') ?></td><td><a href="?tab=all&account=<?= $id ?>" class="text-reset"><?= e($accounts[$id]['name']) ?></a></td><td class="text-end"><?= fin_amt($b) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$leafBal): ?><tr><td colspan="3" class="empty-state"><i class="fa-solid fa-book"></i>No postings yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="fin-card">
  <div class="fin-toolbar">
    <div>
      <h2 class="fin-card-title mb-2">General Ledger Entries</h2>
      <?= fin_tabs($tabs, $tab) ?>
    </div>
    <form method="get" class="d-flex gap-2 flex-wrap">
      <input type="hidden" name="tab" value="<?= e($tab) ?>">
      <div class="fin-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" name="q" value="<?= e($q) ?>" class="form-control" placeholder="Search by voucher no., account, or description..."></div>
      <button class="btn btn-outline-brand" type="button" data-bs-toggle="collapse" data-bs-target="#glFilter"><i class="fa-solid fa-filter"></i> Filter</button>
      <a href="<?= $exportUrl ?>" class="btn btn-outline-secondary"><i class="fa-solid fa-download"></i> Export</a>
    </form>
  </div>
  <div class="collapse <?= ($acctFilter || $ccFilter || $projFilter !== '' || $typeFilter !== '' || $from || $to) ? 'show' : '' ?> mb-3" id="glFilter">
    <form method="get" class="row g-2 align-items-end">
      <input type="hidden" name="tab" value="<?= e($tab) ?>"><input type="hidden" name="q" value="<?= e($q) ?>">
      <div class="col-md-3"><label class="form-label small">Account</label>
        <select name="account" class="form-select form-select-sm"><option value="">All accounts</option>
          <?php foreach (array_filter($accounts, fn($a) => !$a['is_group']) as $id => $a): ?><option value="<?= $id ?>" <?= $acctFilter === $id ? 'selected' : '' ?>><?= e(fin_account_label($a)) ?></option><?php endforeach; ?>
        </select></div>
      <div class="col-md-2"><label class="form-label small">Voucher Type</label>
        <select name="vtype" class="form-select form-select-sm"><option value="">All</option>
          <?php foreach ($voucherTypes as $vt): ?><option <?= $typeFilter === $vt ? 'selected' : '' ?>><?= e($vt) ?></option><?php endforeach; ?>
        </select></div>
      <div class="col-md-2"><label class="form-label small">Cost Center</label>
        <select name="cc" class="form-select form-select-sm"><option value="">All</option>
          <?php foreach ($costCenters as $id => $n): ?><option value="<?= (int)$id ?>" <?= $ccFilter === (int)$id ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?>
        </select></div>
      <div class="col-md-2"><label class="form-label small">From</label><input type="date" name="from" value="<?= e($from) ?>" class="form-control form-control-sm"></div>
      <div class="col-md-2"><label class="form-label small">To</label><input type="date" name="to" value="<?= e($to) ?>" class="form-control form-control-sm"></div>
      <div class="col-md-1 d-flex gap-1"><button class="btn btn-sm btn-brand">Apply</button></div>
      <?php if ($projFilter !== ''): ?><input type="hidden" name="project" value="<?= e($projFilter) ?>"><?php endif; ?>
    </form>
  </div>

  <div class="table-responsive">
  <?php if ($tab === 'all'): ?>
    <table class="table fin-table">
      <thead><tr><th>Date</th><th>Voucher No.</th><th>Account Code</th><th>Account Name</th><th>Description</th><th class="text-end">Debit (<?= e(setting('currency_symbol', '₹')) ?>)</th><th class="text-end">Credit (<?= e(setting('currency_symbol', '₹')) ?>)</th><th class="text-end">Balance (<?= e(setting('currency_symbol', '₹')) ?>)</th></tr></thead>
      <tbody>
      <?php
      $links = ['invoice' => 'invoice_view.php?id=', 'payment' => null, 'purchase_invoice' => 'purchase_invoice_view.php?id=', 'purchase_payment' => null, 'expense' => 'expense_form.php?id=', 'journal' => 'journal_view.php?id=', 'opening' => 'ledger_account_form.php?id='];
      foreach ($entries as $en):
          $href = $links[$en['source_type']] ?? null;
      ?>
        <tr>
          <td class="text-nowrap"><?= fin_date($en['entry_date']) ?></td>
          <td class="text-nowrap"><?php if ($href): ?><a href="<?= $href . (int)$en['source_id'] ?>"><?= e($en['voucher_no']) ?></a><?php else: ?><?= e($en['voucher_no']) ?><?php endif; ?><div class="small text-muted"><?= e($en['voucher_type']) ?></div></td>
          <td><?= e($en['account_code'] ?: '—') ?></td>
          <td><a class="text-reset" href="?tab=all&account=<?= (int)$en['account_id'] ?>"><?= e($en['account_name']) ?></a></td>
          <td><?= e($en['description']) ?></td>
          <td class="text-end"><?= fin_num($en['debit']) ?></td>
          <td class="text-end"><?= fin_num($en['credit']) ?></td>
          <td class="text-end"><?= fin_amt($en['running']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$entries): ?><tr><td colspan="8" class="empty-state"><i class="fa-solid fa-book"></i>No ledger entries match.</td></tr><?php endif; ?>
      </tbody>
    </table>
  <?php else: ?>
    <table class="table fin-table">
      <thead><tr><th><?= e($tabs[$tab] === 'By Account' ? 'Account' : ($tab === 'cost_center' ? 'Cost Center' : 'Project')) ?></th><th class="text-end">Entries</th><th class="text-end">Debit</th><th class="text-end">Credit</th><th class="text-end">Net (Dr − Cr)</th></tr></thead>
      <tbody>
      <?php foreach ($groups as $gr):
          if ($tab === 'account') { $label = isset($accounts[(int)$gr['k']]) ? fin_account_label($accounts[(int)$gr['k']]) : '—'; $link = ['tab' => 'all', 'account' => $gr['k']]; }
          elseif ($tab === 'cost_center') { $label = $gr['k'] ? ($costCenters[$gr['k']] ?? '—') : 'Unassigned'; $link = $gr['k'] ? ['tab' => 'all', 'cc' => $gr['k']] : null; }
          else { $label = $gr['k'] !== null && $gr['k'] !== '' ? $gr['k'] : 'Unassigned'; $link = ($gr['k'] ?? '') !== '' ? ['tab' => 'all', 'project' => $gr['k']] : null; }
      ?>
        <tr>
          <td><?php if ($link): ?><a href="?<?= e(http_build_query($link)) ?>"><?= e($label) ?></a><?php else: ?><span class="text-muted"><?= e($label) ?></span><?php endif; ?></td>
          <td class="text-end"><?= (int)$gr['n'] ?></td>
          <td class="text-end"><?= fin_num($gr['dr']) ?></td>
          <td class="text-end"><?= fin_num($gr['cr']) ?></td>
          <td class="text-end"><?= fin_amt($gr['dr'] - $gr['cr']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$groups): ?><tr><td colspan="5" class="empty-state"><i class="fa-solid fa-book"></i>No ledger entries match.</td></tr><?php endif; ?>
      </tbody>
    </table>
  <?php endif; ?>
  </div>
  <?php if ($tab === 'all') echo fin_pagination($total, $pageNo, $perPage, 'entries'); ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
