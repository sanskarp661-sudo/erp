<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
$page_title = 'Budget & Planning';
fin_require_schema();

$pdo = db();
$canEdit = can_edit_module('finance');
$canManage = can_manage_module('finance');
$fyNow = fin_fy_start();
$fyOptions = [];
for ($i = -1; $i < 3; $i++) {
    $s = date('Y-m-d', strtotime($fyNow . " -$i year"));
    $fyOptions[$s] = 'Financial Year ' . fin_fy_label($s);
}
$fy = isset($fyOptions[input('fy')]) ? input('fy') : $fyNow;

if (is_post()) {
    csrf_verify();
    $action = input('action');
    if ($action === 'delete') {
        require_module_manage('finance');
        $id = (int)input('id');
        $stmt = $pdo->prepare('SELECT name FROM fin_budgets WHERE id = ?');
        $stmt->execute([$id]);
        $name = $stmt->fetchColumn();
        $pdo->prepare('DELETE FROM fin_budgets WHERE id = ?')->execute([$id]);
        log_activity('budget', $id, 'deleted', "Budget deleted: $name");
        flash('success', 'Budget deleted.');
    } elseif ($action === 'import') {
        require_module_edit('finance');
        // CSV columns: name, department, category, amount[, 12 monthly amounts]
        $file = $_FILES['csv']['tmp_name'] ?? '';
        $n = 0;
        $skipped = [];
        if ($file && is_uploaded_file($file) && ($h = fopen($file, 'r'))) {
            $depts = array_change_key_case(array_flip($pdo->query('SELECT id, name FROM departments')->fetchAll(PDO::FETCH_KEY_PAIR)));
            $ins = $pdo->prepare('INSERT INTO fin_budgets (name, fiscal_year_start, department_id, category, amount, distribution, status, created_by) VALUES (?,?,?,?,?,?,?,?)');
            $insM = $pdo->prepare('INSERT INTO fin_budget_months (budget_id, month_index, amount) VALUES (?,?,?)');
            $line = 0;
            while (($r = fgetcsv($h)) !== false) {
                $line++;
                $r = array_map('trim', $r);
                if ($line === 1 && strtolower($r[0] ?? '') === 'name') continue;
                if (count($r) < 4 || $r[0] === '' || !is_numeric(str_replace(',', '', $r[3]))) { $skipped[] = $line; continue; }
                $dept = $r[1] !== '' ? ($depts[strtolower($r[1])] ?? null) : null;
                $months = array_slice($r, 4, 12);
                $custom = count($months) === 12 && count(array_filter($months, 'is_numeric')) === 12;
                $amount = $custom ? array_sum(array_map('floatval', $months)) : (float)str_replace(',', '', $r[3]);
                $ins->execute([$r[0], $fy, $dept, $r[2] ?: null, $amount, $custom ? 'custom' : 'equal', 'active', current_user()['id']]);
                $bid = (int)$pdo->lastInsertId();
                if ($custom) foreach ($months as $i => $m) $insM->execute([$bid, $i + 1, (float)$m]);
                $n++;
            }
            fclose($h);
        }
        log_activity('budget', 0, 'imported', "Imported $n budgets for FY " . fin_fy_label($fy));
        flash($n ? 'success' : 'danger', $n ? "Imported $n budgets." . ($skipped ? ' Skipped lines: ' . implode(', ', $skipped) . '.' : '') : 'No budgets imported. Use columns: name, department, category, amount (and optionally 12 monthly amounts).');
    }
    redirect('/accounting/budgets.php?fy=' . $fy);
}

$budgets = fin_budgets($fy);
$elapsed = fin_fy_months_elapsed($fy);
$totalBudget = 0.0;
$budgetMonthly = array_fill(1, 12, 0.0);
foreach ($budgets as $id => &$b) {
    $b['actual_months'] = fin_budget_actuals($b, $fy);
    $b['actual'] = array_sum($b['actual_months']);
    $b['to_date'] = array_sum(array_slice($b['months'], 0, $elapsed, true));
    $b['util'] = $b['amount'] > 0 ? $b['actual'] / $b['amount'] * 100 : 0;
    $b['status'] = $b['actual'] > $b['amount'] ? 'Over Budget' : ($b['actual'] > $b['to_date'] * 1.0001 ? 'At Risk' : 'On Track');
    $totalBudget += (float)$b['amount'];
    foreach ($b['months'] as $mi => $v) $budgetMonthly[$mi] += $v;
}
unset($b);

// Actual spend for the year (all approved expenses) by month.
$stmt = $pdo->prepare("SELECT PERIOD_DIFF(DATE_FORMAT(expense_date, '%Y%m'), DATE_FORMAT(?, '%Y%m')) + 1 mi, SUM(amount) v FROM expenses WHERE status = 'approved' AND expense_date BETWEEN ? AND ? GROUP BY mi");
$stmt->execute([$fy, $fy, fin_fy_end($fy)]);
$actualMonthly = array_fill(1, 12, 0.0);
foreach ($stmt as $r) if ($r['mi'] >= 1 && $r['mi'] <= 12) $actualMonthly[(int)$r['mi']] = (float)$r['v'];
$categoryFilter = trim((string)input('cat'));
if ($categoryFilter !== '') {
    $budgetMonthly = array_fill(1, 12, 0.0);
    $actualMonthly = array_fill(1, 12, 0.0);
    foreach ($budgets as $b) {
        if (($b['category'] ?: $b['department_name'] ?: 'Other') !== $categoryFilter) continue;
        foreach ($b['months'] as $mi => $v) { $budgetMonthly[$mi] += $v; $actualMonthly[$mi] += $b['actual_months'][$mi]; }
    }
}
$actualYtd = array_sum(array_slice($actualMonthly, 0, $elapsed, true));
$budgetToDate = array_sum(array_slice($budgetMonthly, 0, $elapsed, true));
$lyFy = date('Y-m-d', strtotime($fy . ' -1 year'));
$lyBudget = (float)$pdo->query('SELECT COALESCE(SUM(amount),0) FROM fin_budgets WHERE fiscal_year_start = ' . $pdo->quote($lyFy))->fetchColumn();
$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE status = 'approved' AND expense_date BETWEEN ? AND ?");
$stmt->execute([$lyFy, date('Y-m-d', strtotime(min(today(), fin_fy_end($fy)) . ' -1 year'))]);
$lyActual = (float)$stmt->fetchColumn();
$variance = $budgetToDate - $actualYtd;
$planned = array_sum($budgetMonthly);
$utilization = $planned > 0 ? $actualYtd / $planned * 100 : 0;
$paceOk = $actualYtd <= $budgetToDate;

// Budget by category (category, else department).
$byCat = [];
foreach ($budgets as $b) {
    $k = $b['category'] ?: ($b['department_name'] ?: 'Other');
    $byCat[$k] = ($byCat[$k] ?? 0) + (float)$b['amount'];
}
arsort($byCat);
if (count($byCat) > 6) { $top = array_slice($byCat, 0, 5, true); $top['Others'] = array_sum(array_slice($byCat, 5)); $byCat = $top; }

// Department-wise performance.
$byDept = [];
foreach ($budgets as $b) {
    $k = $b['department_name'] ?: ($b['category'] ?: 'Unassigned');
    $byDept[$k] = $byDept[$k] ?? ['budget' => 0, 'actual' => 0, 'to_date' => 0];
    $byDept[$k]['budget'] += (float)$b['amount'];
    $byDept[$k]['actual'] += $b['actual'];
    $byDept[$k]['to_date'] += $b['to_date'];
}
uasort($byDept, fn($a, $b) => $b['budget'] <=> $a['budget']);

$tabs = ['overview' => 'Overview', 'budgets' => 'Budgets', 'forecast' => 'Forecasting', 'variance' => 'Variance Analysis', 'reports' => 'Reports'];
$tab = isset($tabs[input('tab')]) ? input('tab') : 'overview';

// CSV downloads (Reports tab).
$dl = input('download');
if (in_array($dl, ['budgets', 'variance', 'forecast'], true)) {
    $rowsOut = [];
    foreach ($budgets as $b) {
        $runRate = $elapsed ? $b['actual'] / $elapsed : 0;
        if ($dl === 'budgets') $rowsOut[] = [$b['name'], $b['department_name'], $b['category'], $b['cc_name'], $b['amount'], ...array_values($b['months'])];
        if ($dl === 'variance') $rowsOut[] = [$b['name'], $b['amount'], round($b['to_date'], 2), round($b['actual'], 2), round($b['to_date'] - $b['actual'], 2), round($b['util'], 1), $b['status']];
        if ($dl === 'forecast') $rowsOut[] = [$b['name'], $b['amount'], round($b['actual'], 2), round($runRate, 2), round($runRate * 12, 2), round($b['amount'] - $runRate * 12, 2)];
    }
    $monthHeads = [];
    for ($i = 0; $i < 12; $i++) $monthHeads[] = date('M Y', strtotime($fy . " +$i month"));
    $heads = ['budgets' => array_merge(['Budget', 'Department', 'Category', 'Cost Center', 'Annual Amount'], $monthHeads),
        'variance' => ['Budget', 'Annual Budget', 'Budget to Date', 'Actual to Date', 'Variance', 'Utilization %', 'Status'],
        'forecast' => ['Budget', 'Annual Budget', 'Actual YTD', 'Monthly Run Rate', 'Projected Full Year', 'Projected Variance']][$dl];
    log_activity('budget', 0, 'exported', 'Downloaded ' . $dl . ' report for FY ' . fin_fy_label($fy));
    fin_csv("budget-$dl-" . fin_fy_label($fy) . '.csv', $heads, $rowsOut);
}

$activities = $pdo->query("SELECT a.*, u.name actor FROM activity_log a LEFT JOIN users u ON u.id = a.actor_id WHERE a.entity_type = 'budget' ORDER BY a.id DESC LIMIT 5")->fetchAll();
$monthLabels = [];
for ($i = 0; $i < 12; $i++) $monthLabels[] = date('M', strtotime($fy . " +$i month"));
$statusTone = ['On Track' => 'success', 'At Risk' => 'warning', 'Over Budget' => 'danger'];

$extra_js = fin_chart_js();
require __DIR__ . '/../includes/header.php';
$fySelect = '<form method="get"><input type="hidden" name="tab" value="' . e($tab) . '"><select name="fy" class="form-select" onchange="this.form.submit()">';
foreach ($fyOptions as $k => $l) $fySelect .= '<option value="' . $k . '" ' . ($k === $fy ? 'selected' : '') . '>' . $l . '</option>';
$fySelect .= '</select></form>';
fin_page_head('Budget & Planning', 'Plan, track, and manage your budgets to drive better financial decisions.', $fySelect
    . ($canEdit ? '<div class="btn-row"><button class="btn btn-outline-brand" data-bs-toggle="modal" data-bs-target="#importModal"><i class="fa-solid fa-file-import"></i> Import Budget</button>'
    . '<a href="budget_form.php?fy=' . e($fy) . '" class="btn btn-brand"><i class="fa-solid fa-plus"></i> Create Budget</a></div>' : ''));
fin_kpi_row([
    fin_kpi('fa-solid fa-bullseye', 'green', 'Total Budget (FY ' . fin_fy_label($fy) . ')', fin_money($totalBudget), fin_pct_change($totalBudget, $lyBudget), 'vs last year'),
    fin_kpi('fa-solid fa-coins', 'blue', 'Actuals (YTD)', fin_money($actualYtd), fin_pct_change($actualYtd, $lyActual), 'vs last year', null, false),
    fin_kpi('fa-solid fa-chart-simple', 'red', 'Variance (YTD)', fin_money($variance), $budgetToDate > 0 ? round(($actualYtd - $budgetToDate) / $budgetToDate * 100, 1) : null, 'vs budget to date', null, false),
    fin_kpi('fa-solid fa-chart-pie', 'purple', 'Budget Utilization', round($utilization) . '%', null, '', $budgets ? '<span class="d-inline-block rounded-circle me-1" style="width:9px;height:9px;background:' . ($paceOk ? '#16a34a' : '#dc2626') . '"></span>' . ($paceOk ? 'On track' : 'Ahead of plan') : 'No budgets yet'),
]);
?>
<div class="fin-card mb-3">
  <?= fin_tabs($tabs, $tab) ?>
  <div class="pt-3">
  <?php if ($tab === 'overview'): ?>
    <div class="row g-3">
      <div class="col-lg-7">
        <div class="fin-card-head">
          <h2 class="fin-card-title">Budget vs Actual (Monthly)</h2>
          <form method="get" class="d-flex gap-2"><input type="hidden" name="fy" value="<?= e($fy) ?>">
            <select name="cat" class="form-select form-select-sm" onchange="this.form.submit()"><option value="">All Categories</option>
              <?php foreach (array_keys($byCat) as $c): if ($c === 'Others') continue; ?><option <?= $categoryFilter === $c ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select></form>
        </div>
        <?= fin_chart('bvaChart', ['type' => 'bar', 'labels' => $monthLabels, 'datasets' => [
            ['label' => 'Budget', 'data' => array_map(fn($v) => round($v, 2), array_values($budgetMonthly)), 'backgroundColor' => '#2563eb'],
            ['label' => 'Actual', 'data' => array_map(fn($v) => round($v, 2), array_values($actualMonthly)), 'backgroundColor' => '#c4b5fd'],
        ]], 250) ?>
      </div>
      <div class="col-lg-5">
        <div class="fin-card-head"><h2 class="fin-card-title">Budget by Category (YTD)</h2></div>
        <?php if ($byCat): $pal = fin_palette(); ?>
        <div class="row align-items-center g-2">
          <div class="col-sm-6 position-relative">
            <?= fin_chart('catDonut', ['type' => 'doughnut', 'labels' => array_keys($byCat), 'datasets' => [['label' => 'Budget', 'data' => array_values($byCat), 'backgroundColor' => array_slice($pal, 0, count($byCat))]]], 200) ?>
            <div class="position-absolute top-50 start-50 translate-middle text-center" style="pointer-events:none"><div class="fw-bold"><?= fin_money($totalBudget) ?></div><div class="small text-muted">Total Budget</div></div>
          </div>
          <div class="col-sm-6"><ul class="fin-legend"><?php $i = 0; foreach ($byCat as $n => $v): ?><li><span class="dot" style="background:<?= $pal[$i++] ?>"></span><?= e($n) ?><span class="pct"><?= $totalBudget > 0 ? round($v / $totalBudget * 100) : 0 ?>%</span></li><?php endforeach; ?></ul></div>
        </div>
        <?php else: ?><div class="empty-state"><i class="fa-solid fa-bullseye"></i>No budgets for this year yet.</div><?php endif; ?>
      </div>
    </div>
  <?php elseif ($tab === 'budgets' || $tab === 'variance' || $tab === 'forecast'): ?>
    <div class="table-responsive">
      <table class="table fin-table">
        <?php if ($tab === 'budgets'): ?>
          <thead><tr><th>Budget</th><th>Department</th><th>Category</th><th>Cost Center</th><th class="text-end">Annual Budget</th><th class="text-end">Actual</th><th class="text-end">Utilization</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
        <?php elseif ($tab === 'variance'): ?>
          <thead><tr><th>Budget</th><th class="text-end">Annual Budget</th><th class="text-end">Budget to Date</th><th class="text-end">Actual to Date</th><th class="text-end">Variance</th><th class="text-end">Variance %</th><th>Status</th></tr></thead>
        <?php else: ?>
          <thead><tr><th>Budget</th><th class="text-end">Annual Budget</th><th class="text-end">Actual YTD</th><th class="text-end">Monthly Run Rate</th><th class="text-end">Projected Full Year</th><th class="text-end">Projected Variance</th><th>Outlook</th></tr></thead>
        <?php endif; ?>
        <tbody>
        <?php foreach ($budgets as $id => $b): $runRate = $elapsed ? $b['actual'] / $elapsed : 0; $proj = $runRate * 12; ?>
          <tr>
            <td><a href="budget_form.php?id=<?= $id ?>"><?= e($b['name']) ?></a><?= $b['status'] === 'draft' ? ' ' . fin_pill('Draft', 'secondary') : '' ?></td>
            <?php if ($tab === 'budgets'): ?>
              <td><?= e($b['department_name'] ?: '—') ?></td><td><?= e($b['category'] ?: 'All categories') ?></td><td><?= e($b['cc_name'] ?: '—') ?></td>
              <td class="text-end"><?= fin_num($b['amount']) ?></td><td class="text-end"><?= fin_num($b['actual']) ?></td><td class="text-end"><?= round($b['util']) ?>%</td>
              <td><?= fin_pill($b['status'], $statusTone[$b['status']]) ?></td>
              <td class="text-end text-nowrap">
                <?php if ($canEdit): ?><a class="btn btn-sm btn-outline-secondary" href="budget_form.php?id=<?= $id ?>"><i class="fa-solid fa-pen"></i></a><?php endif; ?>
                <?php if ($canManage): ?><form method="post" class="d-inline" data-confirm="Delete this budget?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-trash"></i></button></form><?php endif; ?>
              </td>
            <?php elseif ($tab === 'variance'): $v = $b['to_date'] - $b['actual']; ?>
              <td class="text-end"><?= fin_num($b['amount']) ?></td><td class="text-end"><?= fin_num($b['to_date']) ?></td><td class="text-end"><?= fin_num($b['actual']) ?></td>
              <td class="text-end <?= $v < 0 ? 'text-danger' : 'text-success' ?>"><?= fin_amt($v) ?></td>
              <td class="text-end"><?= $b['to_date'] > 0 ? round(($b['actual'] - $b['to_date']) / $b['to_date'] * 100, 1) . '%' : '—' ?></td>
              <td><?= fin_pill($b['status'], $statusTone[$b['status']]) ?></td>
            <?php else: $pv = $b['amount'] - $proj; ?>
              <td class="text-end"><?= fin_num($b['amount']) ?></td><td class="text-end"><?= fin_num($b['actual']) ?></td><td class="text-end"><?= fin_num($runRate) ?></td>
              <td class="text-end"><?= fin_num($proj) ?></td><td class="text-end"><?= fin_amt($pv) ?></td>
              <td><?= $pv >= 0 ? fin_pill('Within budget', 'success') : fin_pill('Will overrun', 'danger') ?></td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
        <?php if (!$budgets): ?><tr><td colspan="9" class="empty-state"><i class="fa-solid fa-bullseye"></i>No budgets for FY <?= fin_fy_label($fy) ?>. <?php if ($canEdit): ?><a href="budget_form.php?fy=<?= e($fy) ?>">Create one</a>.<?php endif; ?></td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php if ($tab === 'forecast'): ?><p class="small text-muted mb-0">Projection = actual spend so far ÷ <?= $elapsed ?> month<?= $elapsed === 1 ? '' : 's' ?> elapsed × 12.</p><?php endif; ?>
  <?php else: ?>
    <div class="row g-3">
      <?php foreach (['budgets' => ['Budget Register', 'Every budget with its monthly distribution.'], 'variance' => ['Variance Report', 'Budget to date against actual spend.'], 'forecast' => ['Forecast Report', 'Run-rate projection for the full year.']] as $k => [$t, $d]): ?>
        <div class="col-md-4"><a class="fin-tool" href="?<?= e(http_build_query(['fy' => $fy, 'download' => $k])) ?>"><span class="fin-kpi-icon tone-orange"><i class="fa-solid fa-download"></i></span><span><strong><?= $t ?></strong><small><?= $d ?> Downloads as CSV.</small></span></a></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-7">
    <div class="fin-card">
      <div class="fin-card-head"><h2 class="fin-card-title">Department-wise Budget Performance</h2><a class="fin-link" href="?tab=variance&fy=<?= e($fy) ?>">View All <i class="fa-solid fa-arrow-right"></i></a></div>
      <div class="table-responsive">
        <table class="table fin-table">
          <thead><tr><th>Department</th><th class="text-end">Budget (<?= e(setting('currency_symbol', '₹')) ?>)</th><th class="text-end">Actual (<?= e(setting('currency_symbol', '₹')) ?>)</th><th class="text-end">Variance (<?= e(setting('currency_symbol', '₹')) ?>)</th><th class="text-end">Utilization</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($byDept as $name => $d): $st = $d['actual'] > $d['budget'] ? 'Over Budget' : ($d['actual'] > $d['to_date'] * 1.0001 ? 'At Risk' : 'On Track'); ?>
            <tr><td><?= e($name) ?></td><td class="text-end"><?= fin_num($d['budget'], 0) ?></td><td class="text-end"><?= fin_num($d['actual'], 0) ?></td>
              <td class="text-end <?= $d['actual'] - $d['budget'] < 0 ? 'text-success' : 'text-danger' ?>"><?= $d['actual'] - $d['budget'] < 0 ? '(' . fin_num($d['budget'] - $d['actual'], 0) . ')' : fin_num($d['actual'] - $d['budget'], 0) ?></td>
              <td class="text-end"><?= $d['budget'] > 0 ? round($d['actual'] / $d['budget'] * 100) : 0 ?>%</td><td><?= fin_pill($st, $statusTone[$st]) ?></td></tr>
          <?php endforeach; ?>
          <?php if (!$byDept): ?><tr><td colspan="6" class="empty-state"><i class="fa-solid fa-sitemap"></i>No budgets yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="fin-card">
      <div class="fin-card-head"><h2 class="fin-card-title">Planning Tools</h2></div>
      <div class="row g-3">
        <?php foreach ([
            ['budget_form.php?fy=' . $fy, 'fa-solid fa-file-lines', 'blue', 'Create Budget', 'Set up departmental and category budgets'],
            ['?tab=forecast&fy=' . $fy, 'fa-solid fa-chart-column', 'green', 'Forecast', 'Project future financial performance'],
            ['?tab=variance&fy=' . $fy, 'fa-solid fa-right-left', 'purple', 'Compare Actual vs Budget', 'Analyze variances'],
            ['?tab=reports&fy=' . $fy, 'fa-solid fa-download', 'orange', 'Download Reports', 'Export budget reports'],
        ] as [$href, $icon, $tone, $t, $d]): ?>
          <div class="col-sm-6"><a class="fin-tool" href="<?= e($href) ?>"><span class="fin-kpi-icon tone-<?= $tone ?>"><i class="<?= $icon ?>"></i></span><span><strong><?= e($t) ?></strong><small><?= e($d) ?></small></span></a></div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<div class="fin-card">
  <div class="fin-card-head"><h2 class="fin-card-title">Recent Planning Activities</h2></div>
  <table class="table fin-table">
    <thead><tr><th>Date</th><th>Activity</th><th>Performed By</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($activities as $a): ?>
      <tr><td class="text-nowrap"><?= fin_date($a['created_at']) ?></td><td><?= e($a['description'] ?: ucfirst($a['action'])) ?></td><td><?= e($a['actor'] ?? 'System') ?></td><td><?= fin_pill('Completed', 'success') ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$activities): ?><tr><td colspan="4" class="empty-state"><i class="fa-solid fa-clock-rotate-left"></i>No planning activity yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<?php if ($canEdit): ?>
<div class="modal fade" id="importModal" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="post" enctype="multipart/form-data">
  <div class="modal-header"><h5 class="modal-title">Import Budgets (FY <?= fin_fy_label($fy) ?>)</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <?= csrf_field() ?><input type="hidden" name="action" value="import"><input type="hidden" name="fy" value="<?= e($fy) ?>">
    <p class="small text-muted">Upload a CSV with columns <code>name, department, category, amount</code>. Add 12 more columns (one per month from <?= date('M', strtotime($fy)) ?>) to set a monthly split; otherwise the amount is spread evenly. Department must match a department name in HRMS.</p>
    <input type="file" name="csv" accept=".csv,text/csv" class="form-control" required>
  </div>
  <div class="modal-footer"><button class="btn btn-brand">Import</button></div>
</form></div></div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
