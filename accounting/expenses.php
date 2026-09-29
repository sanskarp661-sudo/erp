<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
$page_title = 'Expenses';
fin_require_schema();
$canEdit = can_edit_module('finance');
$canManage = can_manage_module('finance');
$pdo = db();

if (is_post()) {
    csrf_verify();
    $id = (int)input('id');
    $action = input('action');
    $back = '/accounting/expenses.php' . (input('return') ? '?' . input('return') : '');
    if ($action === 'delete') {
        require_module_manage('finance');
        $pdo->prepare('DELETE FROM expenses WHERE id = ?')->execute([$id]);
        log_activity('expense', $id, 'deleted');
        flash('success', 'Expense deleted.');
    } elseif (in_array($action, ['approve', 'reject'], true)) {
        require_module_manage('finance');
        $status = $action === 'approve' ? 'approved' : 'rejected';
        $pdo->prepare('UPDATE expenses SET status = ?, approved_by = ?, approved_at = NOW() WHERE id = ?')->execute([$status, current_user()['id'], $id]);
        log_activity('expense', $id, $status);
        flash('success', 'Expense ' . $status . '.');
    }
    redirect($back);
}

$today = today();
$fyStart = fin_fy_start();
$lyStart = date('Y-m-d', strtotime($fyStart . ' -1 year'));
$lyToday = date('Y-m-d', strtotime($today . ' -1 year'));
$sumBetween = function (string $from, string $to) use ($pdo) {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE status = 'approved' AND expense_date BETWEEN ? AND ?");
    $stmt->execute([$from, $to]);
    return (float)$stmt->fetchColumn();
};
$ytd = $sumBetween($fyStart, $today);
$ly = $sumBetween($lyStart, $lyToday);
$countAll = (int)$pdo->query('SELECT COUNT(*) FROM expenses')->fetchColumn();
$countThis = (int)$pdo->query("SELECT COUNT(*) FROM expenses WHERE expense_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')")->fetchColumn();
$countLast = (int)$pdo->query("SELECT COUNT(*) FROM expenses WHERE expense_date BETWEEN DATE_FORMAT(CURDATE() - INTERVAL 1 MONTH, '%Y-%m-01') AND LAST_DAY(CURDATE() - INTERVAL 1 MONTH)")->fetchColumn();
$pending = (int)$pdo->query("SELECT COUNT(*) FROM expenses WHERE status = 'pending'")->fetchColumn();
$pendingAmt = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE status = 'pending'")->fetchColumn();
$monthsElapsed = max(1, (int)((strtotime(date('Y-m-01')) - strtotime($fyStart)) / 2629800) + 1);
$avg = $ytd / $monthsElapsed;
$avgLy = $ly / $monthsElapsed;

$modes = fin_payment_modes();
$tabs = ['all' => 'All Expenses', 'category' => 'By Category', 'cost_center' => 'By Cost Center', 'project' => 'By Project', 'vendor' => 'By Vendor'];
$tab = isset($tabs[input('tab')]) ? input('tab') : 'all';
$q = trim((string)input('q'));
$statusFilter = in_array(input('status'), ['pending', 'approved', 'rejected'], true) ? input('status') : '';
$catFilter = trim((string)input('category'));
$modeFilter = isset($modes[input('mode')]) ? input('mode') : '';
$ccFilter = (int)input('cc');
$projFilter = trim((string)input('project'));
$vendorFilter = trim((string)input('payee'));
$from = input('from');
$to = input('to');

$payee = 'COALESCE(v.name, NULLIF(e.payee, \'\'), \'—\')';
$where = ['1=1'];
$params = [];
if ($q !== '') { $where[] = "(e.expense_no LIKE ? OR $payee LIKE ? OR e.description LIKE ? OR e.category LIKE ? OR e.reference LIKE ?)"; array_push($params, "%$q%", "%$q%", "%$q%", "%$q%", "%$q%"); }
if ($statusFilter) { $where[] = 'e.status = ?'; $params[] = $statusFilter; }
if ($catFilter !== '') { $where[] = 'e.category = ?'; $params[] = $catFilter; }
if ($modeFilter) { $where[] = 'e.payment_method = ?'; $params[] = $modeFilter; }
if ($ccFilter) { $where[] = 'e.cost_center_id = ?'; $params[] = $ccFilter; }
if ($projFilter !== '') { $where[] = 'e.project = ?'; $params[] = $projFilter; }
if ($vendorFilter !== '') { $where[] = "$payee = ?"; $params[] = $vendorFilter; }
if ($from) { $where[] = 'e.expense_date >= ?'; $params[] = $from; }
if ($to) { $where[] = 'e.expense_date <= ?'; $params[] = $to; }
$base = 'FROM expenses e LEFT JOIN vendors v ON v.id = e.vendor_id LEFT JOIN fin_cost_centers cc ON cc.id = e.cost_center_id WHERE ' . implode(' AND ', $where);

if (input('export') === 'csv') {
    $stmt = $pdo->prepare("SELECT e.*, $payee payee_name, cc.name cc_name $base ORDER BY e.expense_date DESC, e.id DESC");
    $stmt->execute($params);
    $rows = [];
    foreach ($stmt as $r) {
        $rows[] = [$r['expense_date'], fin_expense_no_display($r), $r['payee_name'], $r['category'], $r['description'], $r['amount'], $r['tax_amount'], $r['status'], $modes[$r['payment_method']] ?? $r['payment_method'], $r['cc_name'], $r['project']];
    }
    fin_csv('expenses.csv', ['Date', 'Voucher No', 'Vendor / Payee', 'Category', 'Description', 'Amount', 'Tax', 'Status', 'Payment Mode', 'Cost Center', 'Project'], $rows);
}

if ($tab === 'all') {
    [$pageNo, $perPage, $offset] = fin_page(10);
    $c = $pdo->prepare("SELECT COUNT(*) $base");
    $c->execute($params);
    $total = (int)$c->fetchColumn();
    $stmt = $pdo->prepare("SELECT e.*, $payee payee_name $base ORDER BY e.expense_date DESC, e.id DESC LIMIT $perPage OFFSET $offset");
    $stmt->execute($params);
    $expenses = $stmt->fetchAll();
} else {
    $groupExpr = ['category' => 'e.category', 'cost_center' => "COALESCE(cc.name, 'Unassigned')", 'project' => "COALESCE(NULLIF(e.project, ''), 'Unassigned')", 'vendor' => $payee][$tab];
    $stmt = $pdo->prepare("SELECT $groupExpr k, COUNT(*) n, SUM(CASE WHEN e.status = 'approved' THEN e.amount ELSE 0 END) approved, SUM(CASE WHEN e.status = 'pending' THEN e.amount ELSE 0 END) pend, MAX(e.expense_date) last_date, MIN(e.cost_center_id) cc_id
        $base GROUP BY k ORDER BY approved DESC");
    $stmt->execute($params);
    $groups = $stmt->fetchAll();
    $groupTotal = array_sum(array_column($groups, 'approved'));
}
$categories = fin_expense_categories();
$costCenters = $pdo->query('SELECT id, name FROM fin_cost_centers ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);
$statusPill = ['approved' => ['Approved', 'success'], 'pending' => ['Pending', 'warning'], 'rejected' => ['Rejected', 'danger']];
$returnQs = http_build_query($_GET);

require __DIR__ . '/../includes/header.php';
fin_page_head('Expenses', 'Track, manage, and analyze all your business expenses.', fin_date_chip());
fin_kpi_row([
    fin_kpi('fa-solid fa-file-invoice', 'blue', 'Total Expenses (YTD)', fin_money($ytd), fin_pct_change($ytd, $ly), 'vs last year', null, false),
    fin_kpi('fa-solid fa-chart-line', 'red', 'Total Bills', (string)$countAll, fin_pct_change($countThis, $countLast), 'this month vs last'),
    fin_kpi('fa-regular fa-clock', 'green', 'Pending Approvals', (string)$pending, null, '', $pending ? e(fin_money($pendingAmt)) . ' awaiting approval' : 'Nothing waiting'),
    fin_kpi('fa-solid fa-chart-pie', 'purple', 'Average Expense per Month', fin_money($avg), fin_pct_change($avg, $avgLy), 'vs last year', null, false),
]);
$exportUrl = '?' . e(http_build_query(array_merge($_GET, ['export' => 'csv'])));
?>
<div class="fin-card">
  <div class="fin-toolbar">
    <?= fin_tabs($tabs, $tab) ?>
    <form method="get" class="d-flex gap-2 flex-wrap">
      <input type="hidden" name="tab" value="<?= e($tab) ?>">
      <div class="fin-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" name="q" value="<?= e($q) ?>" class="form-control" placeholder="Search by voucher no., vendor, description..."></div>
      <button class="btn btn-outline-brand" type="button" data-bs-toggle="collapse" data-bs-target="#expFilter"><i class="fa-solid fa-filter"></i> Filter</button>
      <a href="<?= $exportUrl ?>" class="btn btn-outline-secondary"><i class="fa-solid fa-download"></i> Export</a>
      <?php if ($canEdit): ?><a href="expense_form.php" class="btn btn-brand"><i class="fa-solid fa-plus"></i> New Expense</a><?php endif; ?>
    </form>
  </div>
  <div class="collapse <?= ($statusFilter || $catFilter !== '' || $modeFilter || $ccFilter || $from || $to) ? 'show' : '' ?> mb-3" id="expFilter">
    <form method="get" class="row g-2 align-items-end">
      <input type="hidden" name="tab" value="<?= e($tab) ?>"><input type="hidden" name="q" value="<?= e($q) ?>">
      <div class="col-md-2"><label class="form-label small">Status</label><select name="status" class="form-select form-select-sm"><option value="">All</option>
        <?php foreach ($statusPill as $k => $p): ?><option value="<?= $k ?>" <?= $statusFilter === $k ? 'selected' : '' ?>><?= $p[0] ?></option><?php endforeach; ?></select></div>
      <div class="col-md-2"><label class="form-label small">Category</label><select name="category" class="form-select form-select-sm"><option value="">All</option>
        <?php foreach ($categories as $c): ?><option <?= $catFilter === $c ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-2"><label class="form-label small">Payment Mode</label><select name="mode" class="form-select form-select-sm"><option value="">All</option>
        <?php foreach ($modes as $k => $l): ?><option value="<?= $k ?>" <?= $modeFilter === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
      <div class="col-md-2"><label class="form-label small">Cost Center</label><select name="cc" class="form-select form-select-sm"><option value="">All</option>
        <?php foreach ($costCenters as $k => $l): ?><option value="<?= (int)$k ?>" <?= $ccFilter === (int)$k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-1"><label class="form-label small">From</label><input type="date" name="from" value="<?= e($from) ?>" class="form-control form-control-sm"></div>
      <div class="col-md-1"><label class="form-label small">To</label><input type="date" name="to" value="<?= e($to) ?>" class="form-control form-control-sm"></div>
      <div class="col-md-2 d-flex gap-1"><button class="btn btn-sm btn-brand">Apply</button><a class="btn btn-sm btn-outline-secondary" href="?tab=<?= e($tab) ?>">Clear</a></div>
    </form>
  </div>

  <div class="table-responsive">
  <?php if ($tab === 'all'): ?>
    <table class="table fin-table">
      <thead><tr><th>Date</th><th>Voucher No.</th><th>Vendor / Payee</th><th>Category</th><th>Description</th><th class="text-end">Amount (<?= e(setting('currency_symbol', '₹')) ?>)</th><th>Status</th><th>Payment Mode</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($expenses as $ex): $sp = $statusPill[$ex['status']] ?? ['—', 'secondary']; ?>
        <tr>
          <td class="text-nowrap"><?= fin_date($ex['expense_date']) ?></td>
          <td class="text-nowrap"><a href="expense_form.php?id=<?= (int)$ex['id'] ?>" class="text-reset"><?= e(fin_expense_no_display($ex)) ?></a></td>
          <td><?= e($ex['payee_name']) ?></td>
          <td><?= e($ex['category']) ?></td>
          <td><?= e($ex['description']) ?></td>
          <td class="text-end"><?= fin_num($ex['amount']) ?></td>
          <td><?= fin_pill($sp[0], $sp[1]) ?></td>
          <td><?= e($modes[$ex['payment_method']] ?? $ex['payment_method']) ?></td>
          <td class="text-end">
            <div class="dropdown">
              <button class="btn btn-sm btn-link text-secondary" data-bs-toggle="dropdown"><i class="fa-solid fa-ellipsis"></i></button>
              <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="expense_form.php?id=<?= (int)$ex['id'] ?>"><i class="fa-solid fa-<?= $canEdit ? 'pen' : 'eye' ?>"></i> <?= $canEdit ? 'Edit' : 'View' ?></a></li>
                <?php if ($canManage): foreach (['approve' => ['Approve', 'fa-check', 'pending,rejected'], 'reject' => ['Reject', 'fa-xmark', 'pending,approved'], 'delete' => ['Delete', 'fa-trash', 'pending,approved,rejected']] as $act => [$lbl, $ico, $allowed]):
                    if (!in_array($ex['status'], explode(",", $allowed), true)) continue; ?>
                  <li><form method="post" <?= $act === 'delete' ? 'data-confirm="Delete this expense?"' : '' ?>><?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$ex['id'] ?>"><input type="hidden" name="action" value="<?= $act ?>"><input type="hidden" name="return" value="<?= e($returnQs) ?>">
                    <button class="dropdown-item <?= $act === 'delete' ? 'text-danger' : '' ?>"><i class="fa-solid <?= $ico ?>"></i> <?= $lbl ?></button></form></li>
                <?php endforeach; endif; ?>
              </ul>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$expenses): ?><tr><td colspan="9" class="empty-state"><i class="fa-solid fa-receipt"></i>No expenses match.</td></tr><?php endif; ?>
      </tbody>
    </table>
  <?php else: ?>
    <table class="table fin-table">
      <thead><tr><th><?= e(substr($tabs[$tab], 3)) ?></th><th class="text-end">Vouchers</th><th class="text-end">Approved (<?= e(setting('currency_symbol', '₹')) ?>)</th><th class="text-end">Pending (<?= e(setting('currency_symbol', '₹')) ?>)</th><th class="text-end">% of Total</th><th>Last Expense</th></tr></thead>
      <tbody>
      <?php foreach ($groups as $g):
          $filterKey = ['category' => 'category', 'cost_center' => 'cc', 'project' => 'project', 'vendor' => 'payee'][$tab];
          $filterVal = $tab === 'cost_center' ? $g['cc_id'] : $g['k'];
          $linkable = $g['k'] !== 'Unassigned' && $g['k'] !== '—';
      ?>
        <tr>
          <td><?php if ($linkable): ?><a href="?<?= e(http_build_query(['tab' => 'all', $filterKey => $filterVal])) ?>"><?= e($g['k']) ?></a><?php else: ?><span class="text-muted"><?= e($g['k']) ?></span><?php endif; ?></td>
          <td class="text-end"><?= (int)$g['n'] ?></td>
          <td class="text-end"><?= fin_num($g['approved']) ?></td>
          <td class="text-end"><?= fin_num($g['pend']) ?></td>
          <td class="text-end"><?= $groupTotal > 0 ? round($g['approved'] / $groupTotal * 100, 1) : 0 ?>%</td>
          <td class="text-nowrap"><?= fin_date($g['last_date']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$groups): ?><tr><td colspan="6" class="empty-state"><i class="fa-solid fa-receipt"></i>No expenses match.</td></tr><?php endif; ?>
      </tbody>
    </table>
  <?php endif; ?>
  </div>
  <?php if ($tab === 'all') echo fin_pagination($total, $pageNo, $perPage, 'entries'); ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
