<?php
/**
 * Financial statements: Profit & Loss, Balance Sheet, Cash Flow, Trial
 * Balance, Account Statement and Aging. Each report is built once as
 * rows, then shown as a table or downloaded as CSV.
 */
require_once __DIR__ . '/../includes/auth.php';
require_login();
$page_title = 'Financial Reports';
fin_require_schema();

$pdo = db();
$reports = [
    'pl' => 'Profit & Loss Statement', 'bs' => 'Balance Sheet', 'cf' => 'Cash Flow Statement', 'tb' => 'Trial Balance',
    'statement' => 'Account Statement', 'aging' => 'Aging Report',
];
$type = isset($reports[input('type')]) ? input('type') : 'pl';
$today = today();
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)input('from')) ? input('from') : fin_fy_start();
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)input('to')) ? input('to') : $today;
$accounts = fin_accounts();
$accountId = (int)input('account');
$agingKind = input('kind') === 'payable' ? 'payable' : 'receivable';

$pointInTime = in_array($type, ['bs', 'tb', 'aging'], true);
$periodLabel = $pointInTime ? 'As on ' . fin_date($to) : date('M Y', strtotime($from)) . ' - ' . date('M Y', strtotime($to));

// Each row: ['cells' => [...], 'style' => ''|'group'|'total'|'grand', 'indent' => int]. Numeric cells are amounts.
$headers = [];
$rows = [];
$note = '';
$row = fn(array $cells, string $style = '', int $indent = 0) => ['cells' => $cells, 'style' => $style, 'indent' => $indent];

/** Walk the tree under the given root types, emitting group / ledger rows with a value function. */
$treeRows = function (array $rootTypes, callable $value, bool $skipZero = true) use ($accounts, $row) {
    $children = [];
    foreach ($accounts as $id => $a) {
        if (!in_array($a['root_type'], $rootTypes, true)) continue;
        $pid = (int)$a['parent_id'];
        if ($pid && (!isset($accounts[$pid]) || !in_array($accounts[$pid]['root_type'], $rootTypes, true))) $pid = 0;
        $children[$pid][] = $id;
    }
    $out = [];
    $walk = function (int $pid, int $depth) use (&$walk, &$out, $children, $accounts, $value, $row, $skipZero) {
        foreach ($children[$pid] ?? [] as $id) {
            $v = $value($id);
            if ($skipZero && abs($v) < 0.005) continue;
            $a = $accounts[$id];
            $out[] = $row([($a['account_code'] ? $a['account_code'] . ' ' : '') . $a['name'], $v], $a['is_group'] ? 'group' : '', $depth);
            $walk($id, $depth + 1);
        }
    };
    $walk(0, 0);
    return $out;
};

if ($type === 'pl') {
    $totals = fin_account_totals($from, $to);
    $leaf = function (int $id, int $sign) use ($totals) { return $sign * (($totals[$id]['dr'] ?? 0) - ($totals[$id]['cr'] ?? 0)); };
    // Roll leaf values up to groups.
    $rolled = function (int $sign) use ($accounts, $leaf) {
        $vals = array_fill_keys(array_keys($accounts), 0.0);
        foreach ($accounts as $id => $a) {
            if ($a['is_group']) continue;
            $v = $leaf($id, $sign);
            $cur = $id; $guard = 0;
            while ($cur && isset($accounts[$cur]) && $guard++ < 50) { $vals[$cur] += $v; $cur = (int)$accounts[$cur]['parent_id']; }
        }
        return $vals;
    };
    $inc = $rolled(-1);
    $exp = $rolled(1);
    $incomeTotal = array_sum(array_map(fn($id) => $leaf($id, -1), fin_leaf_ids_by_root(['income'])));
    $expenseTotal = array_sum(array_map(fn($id) => $leaf($id, 1), fin_leaf_ids_by_root(['expense'])));
    $headers = ['Particulars', 'Amount'];
    $rows[] = $row(['Income', null], 'group');
    $rows = array_merge($rows, $treeRows(['income'], fn($id) => $inc[$id]));
    $rows[] = $row(['Total Income', $incomeTotal], 'total');
    $rows[] = $row(['Expenses', null], 'group');
    $rows = array_merge($rows, $treeRows(['expense'], fn($id) => $exp[$id]));
    $rows[] = $row(['Total Expenses', $expenseTotal], 'total');
    $rows[] = $row([$incomeTotal - $expenseTotal >= 0 ? 'Net Profit' : 'Net Loss', $incomeTotal - $expenseTotal], 'grand');
} elseif ($type === 'bs') {
    $bal = fin_balances($to);
    $sumRoot = fn(array $roots) => array_sum(array_map(fn($id) => $bal[$id], fin_leaf_ids_by_root($roots)));
    $assets = $sumRoot(['asset']);
    $liab = -$sumRoot(['liability']);
    $equity = -$sumRoot(['equity']);
    $profit = -$sumRoot(['income', 'expense']);
    $headers = ['Particulars', 'Amount'];
    $rows[] = $row(['Assets', null], 'group');
    $rows = array_merge($rows, $treeRows(['asset'], fn($id) => $bal[$id]));
    $rows[] = $row(['Total Assets', $assets], 'grand');
    $rows[] = $row(['Liabilities', null], 'group');
    $rows = array_merge($rows, $treeRows(['liability'], fn($id) => -$bal[$id]));
    $rows[] = $row(['Total Liabilities', $liab], 'total');
    $rows[] = $row(['Equity', null], 'group');
    $rows = array_merge($rows, $treeRows(['equity'], fn($id) => -$bal[$id]));
    $rows[] = $row(['Profit & Loss (current earnings)', $profit], '', 1);
    $rows[] = $row(['Total Equity', $equity + $profit], 'total');
    $rows[] = $row(['Total Liabilities & Equity', $liab + $equity + $profit], 'grand');
    if (abs($assets - ($liab + $equity + $profit)) > 0.5) {
        $note = 'Assets and Liabilities + Equity differ by ' . fin_num($assets - ($liab + $equity + $profit)) . '. Check that ledger opening balances are entered on both sides.';
    }
} elseif ($type === 'cf') {
    $money = array_keys(fin_money_accounts());
    $in = $money ? implode(',', $money) : '0';
    $opening = fin_balance_of($money, date('Y-m-d', strtotime($from . ' -1 day')));
    $stmt = $pdo->prepare('SELECT voucher_type, SUM(debit) i, SUM(credit) o FROM (' . fin_gl_sql() . ") g WHERE account_id IN ($in) AND voucher_type <> 'Contra' AND entry_date BETWEEN ? AND ? GROUP BY voucher_type ORDER BY voucher_type");
    $stmt->execute([$from, $to]);
    $flows = $stmt->fetchAll();
    $label = ['Receipt' => 'Receipts from customers', 'Payment' => 'Payments to vendors', 'Expense' => 'Expenses paid', 'Opening Balance' => 'Opening balances entered in period'];
    $headers = ['Particulars', 'Amount'];
    $rows[] = $row(['Opening Cash & Bank Balance', $opening], 'total');
    $rows[] = $row(['Cash Inflows', null], 'group');
    $tin = $tout = 0;
    foreach ($flows as $f) { if ($f['i'] > 0) { $rows[] = $row([$label[$f['voucher_type']] ?? $f['voucher_type'], (float)$f['i']], '', 1); $tin += $f['i']; } }
    $rows[] = $row(['Total Inflows', $tin], 'total');
    $rows[] = $row(['Cash Outflows', null], 'group');
    foreach ($flows as $f) { if ($f['o'] > 0) { $rows[] = $row([$label[$f['voucher_type']] ?? $f['voucher_type'], -(float)$f['o']], '', 1); $tout += $f['o']; } }
    $rows[] = $row(['Total Outflows', -$tout], 'total');
    $rows[] = $row(['Net Cash Flow', $tin - $tout], 'total');
    $rows[] = $row(['Closing Cash & Bank Balance', $opening + $tin - $tout], 'grand');
} elseif ($type === 'tb') {
    $totals = fin_account_totals(null, $to);
    $headers = ['Account Code', 'Account Name', 'Debit', 'Credit'];
    $tdr = $tcr = 0;
    foreach ($accounts as $id => $a) {
        if ($a['is_group'] || !isset($totals[$id])) continue;
        $b = $totals[$id]['dr'] - $totals[$id]['cr'];
        if (abs($b) < 0.005) continue;
        $rows[] = $row([$a['account_code'] ?: '—', $a['name'], $b > 0 ? $b : 0.0, $b < 0 ? -$b : 0.0]);
        $b > 0 ? $tdr += $b : $tcr -= $b;
    }
    $rows[] = $row(['', 'Total', $tdr, $tcr], 'grand');
    if (abs($tdr - $tcr) > 0.5) $note = 'The trial balance is out by ' . fin_num($tdr - $tcr) . '. Check ledger opening balances.';
} elseif ($type === 'statement') {
    $headers = ['Date', 'Voucher No.', 'Type', 'Party', 'Description', 'Debit', 'Credit', 'Balance'];
    if (isset($accounts[$accountId]) && !$accounts[$accountId]['is_group']) {
        $opening = fin_balance_of([$accountId], date('Y-m-d', strtotime($from . ' -1 day')));
        $rows[] = $row(['', '', '', '', 'Opening Balance', null, null, $opening], 'total');
        $stmt = $pdo->prepare('SELECT * FROM (' . fin_gl_sql() . ') g WHERE account_id = ? AND entry_date BETWEEN ? AND ? ORDER BY entry_date, source_type = \'opening\' DESC, voucher_no');
        $stmt->execute([$accountId, $from, $to]);
        $run = $opening; $tdr = $tcr = 0;
        foreach ($stmt as $r) {
            $run += $r['debit'] - $r['credit'];
            $tdr += $r['debit']; $tcr += $r['credit'];
            $rows[] = $row([fin_date($r['entry_date']), $r['voucher_no'], $r['voucher_type'], (string)$r['party'], (string)$r['description'], (float)$r['debit'], (float)$r['credit'], $run]);
        }
        $rows[] = $row(['', '', '', '', 'Closing Balance', $tdr, $tcr, $run], 'grand');
    } else {
        $note = 'Pick a ledger account to see its statement.';
    }
} elseif ($type === 'aging') {
    $isAP = $agingKind === 'payable';
    [$table, $partyTable, $fk] = $isAP ? ['purchase_invoices', 'vendors', 'vendor_id'] : ['invoices', 'customers', 'customer_id'];
    $stmt = $pdo->prepare("SELECT p.name, DATEDIFF(?, COALESCE(d.due_date, d.invoice_date)) days,
        GREATEST(d.total - COALESCE((SELECT SUM(x.amount) FROM " . ($isAP ? 'purchase_payments x WHERE x.purchase_invoice_id' : 'payments x WHERE x.invoice_id') . " = d.id AND x.payment_date <= ?), 0), 0) bal
        FROM $table d JOIN $partyTable p ON p.id = d.$fk WHERE d.status <> 'cancelled' AND d.invoice_date <= ?");
    $stmt->execute([$to, $to, $to]);
    $buckets = fin_aging_buckets();
    $by = [];
    foreach ($stmt as $r) {
        if ($r['bal'] < 0.005) continue;
        $d = (int)$r['days'];
        $k = $d <= 30 ? 'current' : ($d <= 60 ? '31_60' : ($d <= 90 ? '61_90' : ($d <= 120 ? '91_120' : '120_plus')));
        $by[$r['name']] = $by[$r['name']] ?? array_fill_keys(array_keys($buckets), 0.0);
        $by[$r['name']][$k] += $r['bal'];
    }
    uasort($by, fn($a, $b) => array_sum($b) <=> array_sum($a));
    $headers = array_merge([$isAP ? 'Supplier' : 'Customer'], array_values($buckets), ['Total']);
    $col = array_fill_keys(array_keys($buckets), 0.0);
    foreach ($by as $name => $b) {
        $rows[] = $row(array_merge([$name], array_values($b), [array_sum($b)]));
        foreach ($b as $k => $v) $col[$k] += $v;
    }
    $rows[] = $row(array_merge(['Total'], array_values($col), [array_sum($col)]), 'grand');
}

$title = $type === 'aging' ? ($agingKind === 'payable' ? 'Accounts Payable Aging' : 'Accounts Receivable Aging') : $reports[$type];
if ($type === 'statement' && isset($accounts[$accountId])) $title = 'Account Statement - ' . $accounts[$accountId]['name'];

$logIt = function () use ($pdo, $type, $title, $periodLabel) {
    $qs = $_GET;
    unset($qs['log'], $qs['export']);
    $pdo->prepare('INSERT INTO fin_report_log (report_type, report_name, period_label, query_string, generated_by) VALUES (?,?,?,?,?)')
        ->execute([$type, $title, $periodLabel, http_build_query($qs), current_user()['id']]);
};
if (input('export') === 'csv') {
    $logIt();
    $out = [];
    foreach ($rows as $r) {
        $cells = $r['cells'];
        $cells[0] = str_repeat('  ', $r['indent']) . $cells[0];
        $out[] = array_map(fn($c) => is_float($c) || is_int($c) ? round($c, 2) : ($c ?? ''), $cells);
    }
    fin_csv(preg_replace('/[^a-z0-9]+/', '-', strtolower($title)) . '.csv', $headers, $out);
}
if (input('log')) {
    $logIt();
    $qs = $_GET;
    unset($qs['log']);
    redirect('/accounting/statement.php?' . http_build_query($qs));
}

$page_title = $title;
require __DIR__ . '/../includes/header.php';
$exportUrl = '?' . e(http_build_query(array_merge($_GET, ['export' => 'csv'])));
fin_page_head($title, $periodLabel . ' · ' . setting('company_name', APP_NAME),
    '<div class="btn-row no-print"><a href="reports.php" class="btn btn-outline-secondary"><i class="fa-solid fa-chevron-left"></i> All Reports</a>'
    . '<button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>'
    . '<a href="' . $exportUrl . '" class="btn btn-brand"><i class="fa-solid fa-download"></i> Download</a></div>');
?>
<div class="fin-card mb-3 no-print">
  <form method="get" class="row g-2 align-items-end">
    <div class="col-md-3"><label class="form-label small">Report</label>
      <select name="type" class="form-select" onchange="this.form.submit()"><?php foreach ($reports as $k => $l): ?><option value="<?= $k ?>" <?= $k === $type ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <?php if ($type === 'statement'): ?>
      <div class="col-md-3"><label class="form-label small">Account</label>
        <select name="account" class="form-select"><option value="">Select ledger</option>
          <?php foreach ($accounts as $id => $a): if ($a['is_group']) continue; ?><option value="<?= $id ?>" <?= $id === $accountId ? 'selected' : '' ?>><?= e(fin_account_label($a)) ?></option><?php endforeach; ?></select></div>
    <?php endif; ?>
    <?php if ($type === 'aging'): ?>
      <div class="col-md-3"><label class="form-label small">Aging of</label>
        <select name="kind" class="form-select"><option value="receivable">Receivables (customers)</option><option value="payable" <?= $agingKind === 'payable' ? 'selected' : '' ?>>Payables (suppliers)</option></select></div>
    <?php endif; ?>
    <?php if (!$pointInTime): ?><div class="col-md-2"><label class="form-label small">From</label><input type="date" name="from" value="<?= e($from) ?>" class="form-control"></div><?php endif; ?>
    <div class="col-md-2"><label class="form-label small"><?= $pointInTime ? 'As on' : 'To' ?></label><input type="date" name="to" value="<?= e($to) ?>" class="form-control"></div>
    <div class="col-md-2"><button class="btn btn-brand w-100">Run Report</button></div>
  </form>
</div>
<?php if ($note): ?><div class="alert alert-warning"><?= e($note) ?></div><?php endif; ?>
<div class="fin-card">
  <div class="table-responsive">
    <table class="table fin-table">
      <thead><tr><?php foreach ($headers as $i => $h): ?><th class="<?= $i > 0 && !in_array($h, ['Account Name', 'Voucher No.', 'Type', 'Party', 'Description'], true) ? 'text-end' : '' ?>"><?= e($h) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
          $cls = ['group' => 'fw-semibold', 'total' => 'fw-semibold table-light', 'grand' => 'fw-bold table-primary'][$r['style']] ?? '';
      ?>
        <tr class="<?= $cls ?>">
          <?php foreach ($r['cells'] as $i => $c): ?>
            <?php if ($i === 0): ?><td style="padding-left:<?= 12 + $r['indent'] * 22 ?>px"><?= e((string)$c) ?></td>
            <?php elseif (is_float($c) || is_int($c)): ?><td class="text-end"><?= fin_amt($c) ?></td>
            <?php elseif ($c === null): ?><td></td>
            <?php else: ?><td><?= e((string)$c) ?></td><?php endif; ?>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="<?= max(1, count($headers)) ?>" class="empty-state"><i class="fa-solid fa-file-lines"></i>Nothing to report for this period.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
