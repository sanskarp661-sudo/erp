<?php
/**
 * Accounts Receivable, and (with $finKind = 'payable', see payables.php)
 * Accounts Payable: KPIs, aging, top parties by outstanding and a tabbed,
 * searchable, paginated invoice / bill list.
 */
require_once __DIR__ . '/../includes/auth.php';
require_login();
$finKind = $finKind ?? 'receivable';
$isAP = $finKind === 'payable';
$page_title = $isAP ? 'Accounts Payable' : 'Accounts Receivable';
fin_require_schema();

$pdo = db();
if (!$isAP) {
    $pdo->exec("UPDATE invoices SET status='overdue' WHERE due_date IS NOT NULL AND due_date < CURDATE() AND status IN ('unpaid','partially_paid')");
}

$cfg = $isAP ? [
    'table' => 'purchase_invoices', 'no' => 'pi_no', 'party_table' => 'vendors', 'party_fk' => 'vendor_id', 'pay_table' => 'purchase_payments', 'pay_fk' => 'purchase_invoice_id',
    'view' => 'purchase_invoice_view.php', 'form' => 'purchase_invoice_form.php', 'doctype' => 'purchase_invoice', 'party' => 'Supplier', 'parties' => 'Suppliers', 'doc' => 'Bill', 'docs' => 'bills',
    'account' => 'payable', 'party_list' => base_url('purchases/vendors.php'),
] : [
    'table' => 'invoices', 'no' => 'invoice_no', 'party_table' => 'customers', 'party_fk' => 'customer_id', 'pay_table' => 'payments', 'pay_fk' => 'invoice_id',
    'view' => 'invoice_view.php', 'form' => 'invoice_form.php', 'doctype' => 'invoice', 'party' => 'Customer', 'parties' => 'Customers', 'doc' => 'Invoice', 'docs' => 'invoices',
    'account' => 'receivable', 'party_list' => base_url('crm/customers.php'),
];
$T = $cfg['table'];
$openStatuses = "('unpaid','partially_paid','overdue')";

/** Outstanding (and overdue) as of a date, using payments dated up to then. */
$asOf = function (string $date, bool $overdueOnly) use ($pdo, $cfg, $T) {
    $sql = "SELECT COALESCE(SUM(GREATEST(d.total - COALESCE((SELECT SUM(p.amount) FROM {$cfg['pay_table']} p WHERE p.{$cfg['pay_fk']} = d.id AND p.payment_date <= ?), 0), 0)), 0)
        FROM $T d WHERE d.status <> 'cancelled' AND d.invoice_date <= ?" . ($overdueOnly ? ' AND d.due_date IS NOT NULL AND d.due_date < ?' : '');
    $stmt = $pdo->prepare($sql);
    $stmt->execute($overdueOnly ? [$date, $date, $date] : [$date, $date]);
    return (float)$stmt->fetchColumn();
};

$today = today();
$lastMonthEnd = date('Y-m-t', strtotime('first day of last month'));
$outstanding = (float)$pdo->query("SELECT COALESCE(SUM(total - amount_paid),0) FROM $T WHERE status IN $openStatuses")->fetchColumn();
$outstandingPrev = $asOf($lastMonthEnd, false);
$openCount = (int)$pdo->query("SELECT COUNT(*) FROM $T WHERE status IN $openStatuses AND total - amount_paid > 0.005")->fetchColumn();
$newThisMonth = (int)$pdo->query("SELECT COUNT(*) FROM $T WHERE status <> 'cancelled' AND invoice_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')")->fetchColumn();
$newLastMonth = (int)$pdo->query("SELECT COUNT(*) FROM $T WHERE status <> 'cancelled' AND invoice_date BETWEEN DATE_FORMAT(CURDATE() - INTERVAL 1 MONTH, '%Y-%m-01') AND LAST_DAY(CURDATE() - INTERVAL 1 MONTH)")->fetchColumn();
$overdue = (float)$pdo->query("SELECT COALESCE(SUM(total - amount_paid),0) FROM $T WHERE status IN $openStatuses AND due_date IS NOT NULL AND due_date < CURDATE()")->fetchColumn();
$overduePrev = $asOf($lastMonthEnd, true);
$partyCount = (int)$pdo->query("SELECT COUNT(*) FROM {$cfg['party_table']}")->fetchColumn();
$partyPrev = (int)$pdo->query("SELECT COUNT(*) FROM {$cfg['party_table']} WHERE created_at <= LAST_DAY(CURDATE() - INTERVAL 1 MONTH) + INTERVAL 1 DAY - INTERVAL 1 SECOND")->fetchColumn();

$aging = fin_aging($finKind);
$top = $pdo->query("SELECT p.id, p.name, SUM(d.total - d.amount_paid) bal FROM $T d JOIN {$cfg['party_table']} p ON p.id = d.{$cfg['party_fk']}
    WHERE d.status IN $openStatuses GROUP BY p.id, p.name HAVING bal > 0.005 ORDER BY bal DESC")->fetchAll();
$topTotal = array_sum(array_column($top, 'bal'));
if (count($top) > 5) {
    $others = array_sum(array_column(array_slice($top, 4), 'bal'));
    $top = array_slice($top, 0, 4);
    $top[] = ['id' => 0, 'name' => 'Others', 'bal' => $others];
}

// ---- invoice / bill list ----
$tabs = $isAP
    ? ['pending' => 'Pending Bills', 'overdue' => 'Overdue Bills', 'paid' => 'Recently Paid', 'all' => 'All Bills']
    : ['outstanding' => 'Outstanding Invoices', 'overdue' => 'Overdue Invoices', 'paid' => 'Recently Paid'];
$tab = isset($tabs[input('tab')]) ? input('tab') : array_key_first($tabs);
$q = trim((string)input('q'));
$partyFilter = (int)input('party');
$dateFrom = input('from');
$dateTo = input('to');

$where = ["d.status <> 'cancelled'"];
$params = [];
if ($tab === 'outstanding' || $tab === 'pending') {
    $where[] = "d.status IN $openStatuses AND d.total - d.amount_paid > 0.005";
} elseif ($tab === 'overdue') {
    $where[] = "d.status IN $openStatuses AND d.due_date IS NOT NULL AND d.due_date < CURDATE()";
} elseif ($tab === 'paid') {
    $where[] = "d.status = 'paid'";
}
if ($q !== '') {
    $where[] = "(d.{$cfg['no']} LIKE ? OR p.name LIKE ? OR d.notes LIKE ?)";
    array_push($params, "%$q%", "%$q%", "%$q%");
}
if ($partyFilter) { $where[] = "d.{$cfg['party_fk']} = ?"; $params[] = $partyFilter; }
if ($dateFrom) { $where[] = 'd.invoice_date >= ?'; $params[] = $dateFrom; }
if ($dateTo) { $where[] = 'd.invoice_date <= ?'; $params[] = $dateTo; }
$whereSql = implode(' AND ', $where);
$order = $tab === 'paid' ? 'last_paid DESC, d.id DESC' : 'd.due_date IS NULL, d.due_date, d.id DESC';
$base = "FROM $T d JOIN {$cfg['party_table']} p ON p.id = d.{$cfg['party_fk']} WHERE $whereSql";
$select = "SELECT d.*, d.{$cfg['no']} doc_no, p.name party_name, (SELECT MAX(x.payment_date) FROM {$cfg['pay_table']} x WHERE x.{$cfg['pay_fk']} = d.id) last_paid $base ORDER BY $order";

if (input('export') === 'csv') {
    $ids = array_filter(array_map('intval', (array)($_GET['ids'] ?? [])));
    $sql = $select;
    if ($ids) {
        $sql = str_replace("WHERE $whereSql", "WHERE $whereSql AND d.id IN (" . implode(',', $ids) . ')', $select);
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = [];
    foreach ($stmt as $r) {
        $rows[] = [$r['doc_no'], $r['party_name'], $r['invoice_date'], $r['due_date'], $r['total'], round($r['total'] - $r['amount_paid'], 2), $r['status']];
    }
    fin_csv(($isAP ? 'payables' : 'receivables') . '-' . $tab . '.csv', [$cfg['doc'] . ' No', $cfg['party'], 'Date', 'Due Date', 'Total', 'Outstanding', 'Status'], $rows);
}

[$pageNo, $perPage, $offset] = fin_page(10);
$countStmt = $pdo->prepare("SELECT COUNT(*) $base");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$stmt = $pdo->prepare("$select LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$docs = $stmt->fetchAll();
$parties = $pdo->query("SELECT id, name FROM {$cfg['party_table']} ORDER BY name")->fetchAll();
$canEdit = can_edit_module('finance');

$extra_js = fin_chart_js();
require __DIR__ . '/../includes/header.php';

$actions = fin_date_chip();
if ($canEdit) {
    $actions .= '<a href="' . $cfg['form'] . '" class="btn btn-brand"><i class="fa-solid fa-plus"></i> Create ' . $cfg['doc'] . '</a>';
}
fin_page_head($isAP ? 'Accounts Payable' : 'Accounts Receivable',
    $isAP ? 'Manage your vendor bills, payments and outstanding dues efficiently.' : 'Track customer payments, outstanding invoices and manage your receivables efficiently.', $actions);
fin_kpi_row([
    fin_kpi($isAP ? 'fa-solid fa-file-invoice' : 'fa-solid fa-indian-rupee-sign', $isAP ? 'red' : 'green', 'Total ' . ($isAP ? 'Payables' : 'Receivables'), fin_money($outstanding), fin_pct_change($outstanding, $outstandingPrev), 'vs last month', null, !$isAP),
    $isAP
        ? fin_kpi('fa-solid fa-file-lines', 'blue', 'Pending Bills', (string)$openCount, null, '', e(fin_money($outstanding)))
        : fin_kpi('fa-solid fa-file-lines', 'blue', 'Open Invoices', (string)$openCount, fin_pct_change($newThisMonth, $newLastMonth), 'new vs last month'),
    fin_kpi('fa-regular fa-clock', 'red', 'Overdue Amount', fin_money($overdue), fin_pct_change($overdue, $overduePrev), 'vs last month', null, false),
    fin_kpi('fa-solid fa-users', 'purple', 'Total ' . $cfg['parties'], (string)$partyCount, fin_pct_change($partyCount, $partyPrev), 'vs last month'),
]);
?>
<div class="row g-3 mb-3">
  <div class="col-lg-7">
    <div class="fin-card">
      <div class="fin-card-head"><div><h2 class="fin-card-title"><?= $isAP ? 'Payables' : 'Receivables' ?> Aging</h2><p class="fin-card-sub">Outstanding amount grouped by due date.</p></div></div>
      <?= fin_chart('agingChart', ['type' => 'bar', 'legend' => false, 'valueLabels' => true, 'labels' => array_map(fn($l) => explode(' (', $l), array_values(fin_aging_buckets())),
          'datasets' => [['label' => 'Outstanding', 'data' => array_map(fn($v) => round($v, 2), array_values($aging)), 'backgroundColor' => ['#86d8a5', '#a8c5fb', '#fcd96b', '#fdba8c', '#fca5a5'], 'maxBarThickness' => 80]]], 250) ?>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="fin-card">
      <div class="fin-card-head"><h2 class="fin-card-title">Top <?= $cfg['parties'] ?> by Outstanding</h2></div>
      <table class="table fin-table">
        <thead><tr><th>#</th><th><?= $cfg['party'] ?></th><th class="text-end">Outstanding (<?= e(setting('currency_symbol', '₹')) ?>)</th><th class="text-end">% of Total</th></tr></thead>
        <tbody>
        <?php foreach ($top as $i => $t): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td><?php if ($t['id']): ?><a href="?<?= e(http_build_query(['tab' => array_key_first($tabs), 'party' => $t['id']])) ?>"><?= e($t['name']) ?></a><?php else: ?><?= e($t['name']) ?><?php endif; ?></td>
            <td class="text-end"><?= fin_num($t['bal'], 0) ?></td>
            <td class="text-end"><?= $topTotal > 0 ? round($t['bal'] / $topTotal * 100) : 0 ?>%</td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$top): ?><tr><td colspan="4" class="empty-state"><i class="fa-solid fa-circle-check text-success"></i>Nothing outstanding.</td></tr><?php endif; ?>
        </tbody>
      </table>
      <div class="text-end"><a class="fin-link" href="<?= e($cfg['party_list']) ?>">View All <?= $cfg['parties'] ?> <i class="fa-solid fa-arrow-right"></i></a></div>
    </div>
  </div>
</div>

<div class="fin-card">
  <div class="fin-toolbar">
    <?= fin_tabs($tabs, $tab) ?>
    <form method="get" class="d-flex gap-2 flex-wrap" id="docSearch">
      <input type="hidden" name="tab" value="<?= e($tab) ?>">
      <div class="fin-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" name="q" value="<?= e($q) ?>" class="form-control" placeholder="Search by <?= strtolower($cfg['doc']) ?> no., <?= strtolower($cfg['party']) ?>..."></div>
      <button class="btn btn-outline-brand" type="button" data-bs-toggle="collapse" data-bs-target="#docFilter"><i class="fa-solid fa-filter"></i> Filter</button>
      <button class="btn btn-outline-secondary" type="button" id="exportBtn"><i class="fa-solid fa-download"></i> Export</button>
    </form>
  </div>
  <div class="collapse <?= ($partyFilter || $dateFrom || $dateTo) ? 'show' : '' ?> mb-3" id="docFilter">
    <form method="get" class="row g-2 align-items-end">
      <input type="hidden" name="tab" value="<?= e($tab) ?>"><input type="hidden" name="q" value="<?= e($q) ?>">
      <div class="col-sm-4"><label class="form-label small"><?= $cfg['party'] ?></label>
        <select name="party" class="form-select form-select-sm"><option value="">All</option>
          <?php foreach ($parties as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $partyFilter === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="col-sm-3"><label class="form-label small">From</label><input type="date" name="from" value="<?= e($dateFrom) ?>" class="form-control form-control-sm"></div>
      <div class="col-sm-3"><label class="form-label small">To</label><input type="date" name="to" value="<?= e($dateTo) ?>" class="form-control form-control-sm"></div>
      <div class="col-sm-2 d-flex gap-1"><button class="btn btn-sm btn-brand">Apply</button><a class="btn btn-sm btn-outline-secondary" href="?tab=<?= e($tab) ?>">Clear</a></div>
    </form>
  </div>
  <div class="table-responsive">
    <table class="table fin-table" id="docTable">
      <thead><tr>
        <th style="width:32px"><input type="checkbox" class="form-check-input" id="checkAll"></th>
        <th><?= $cfg['doc'] ?> No.</th><th><?= $cfg['party'] ?></th><th><?= $cfg['doc'] ?> Date</th><th>Due Date</th>
        <th class="text-end"><?= $isAP ? 'Amount' : 'Total Amount' ?> (<?= e(setting('currency_symbol', '₹')) ?>)</th><th class="text-end">Outstanding (<?= e(setting('currency_symbol', '₹')) ?>)</th>
        <?php if (!$isAP): ?><th class="text-center">Days Overdue</th><?php endif; ?><th>Status</th><th class="text-end">Actions</th>
      </tr></thead>
      <tbody>
      <?php foreach ($docs as $d):
          $bal = round($d['total'] - $d['amount_paid'], 2);
          $daysOver = ($d['due_date'] && $bal > 0) ? max(0, (int)floor((strtotime($today) - strtotime($d['due_date'])) / 86400)) : 0;
          if ($d['status'] === 'paid') { $st = ['Paid', 'success']; }
          elseif ($daysOver > 0) { $st = ['Overdue', 'danger']; }
          elseif ($d['status'] === 'partially_paid') { $st = ['Partially Paid', 'purple']; }
          else { $st = $isAP ? ['Pending', 'warning'] : ['Open', 'info']; }
      ?>
        <tr>
          <td><input type="checkbox" class="form-check-input js-row" value="<?= (int)$d['id'] ?>"></td>
          <td><a href="<?= $cfg['view'] ?>?id=<?= (int)$d['id'] ?>"><?= e($d['doc_no']) ?></a></td>
          <td><?= e($d['party_name']) ?></td>
          <td class="text-nowrap"><?= fin_date($d['invoice_date']) ?></td>
          <td class="text-nowrap"><?= fin_date($d['due_date']) ?></td>
          <td class="text-end"><?= fin_num($d['total']) ?></td>
          <td class="text-end"><?= fin_num($bal) ?></td>
          <?php if (!$isAP): ?><td class="text-center <?= $daysOver ? 'text-danger fw-semibold' : '' ?>"><?= $daysOver ?></td><?php endif; ?>
          <td><?= fin_pill($st[0], $st[1]) ?></td>
          <td class="text-end">
            <div class="dropdown">
              <button class="btn btn-sm btn-link text-secondary" data-bs-toggle="dropdown"><i class="fa-solid fa-ellipsis"></i></button>
              <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="<?= $cfg['view'] ?>?id=<?= (int)$d['id'] ?>"><i class="fa-solid fa-eye"></i> View</a></li>
                <?php if ($bal > 0 && $canEdit): ?><li><a class="dropdown-item" href="<?= $cfg['view'] ?>?id=<?= (int)$d['id'] ?>#payment"><i class="fa-solid fa-money-bill"></i> Record Payment</a></li><?php endif; ?>
                <li><a class="dropdown-item" target="_blank" href="<?= base_url('print.php?doctype=' . $cfg['doctype'] . '&id=' . (int)$d['id']) ?>"><i class="fa-solid fa-print"></i> Print</a></li>
              </ul>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$docs): ?><tr><td colspan="10" class="empty-state"><i class="fa-solid fa-file-circle-check"></i>No <?= $cfg['docs'] ?> here.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= fin_pagination($total, $pageNo, $perPage, $cfg['docs']) ?>
</div>
<?php
$extra_js_inline = "
document.addEventListener('DOMContentLoaded', function () {
  var all = document.getElementById('checkAll');
  all.addEventListener('change', function () { document.querySelectorAll('.js-row').forEach(function (c) { c.checked = all.checked; }); });
  document.getElementById('exportBtn').addEventListener('click', function () {
    var p = new URLSearchParams(window.location.search);
    p.set('export', 'csv');
    p.delete('ids[]');
    document.querySelectorAll('.js-row:checked').forEach(function (c) { p.append('ids[]', c.value); });
    window.location = '?' + p.toString();
  });
});";
require __DIR__ . '/../includes/footer.php';
