<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
$page_title = 'Tax & Compliance';
fin_require_schema();

$pdo = db();
$today = today();
$canEdit = can_edit_module('finance');
$returnTypes = fin_tax_return_types();
$categories = fin_tax_categories();

if (is_post() && input('action') === 'delete') {
    require_module_manage('finance');
    csrf_verify();
    $pdo->prepare('DELETE FROM fin_tax_filings WHERE id = ?')->execute([(int)input('id')]);
    flash('success', 'Filing deleted.');
    redirect('/accounting/tax_compliance.php?' . input('return'));
}

// Fiscal year selector (current + 2 previous).
$fyNow = fin_fy_start();
$fyOptions = [];
for ($i = 0; $i < 3; $i++) {
    $s = date('Y-m-d', strtotime($fyNow . " -$i year"));
    $fyOptions[$s] = 'Financial Year ' . fin_fy_label($s);
}
$fy = isset($fyOptions[input('fy')]) ? input('fy') : $fyNow;
$fyEnd = fin_fy_end($fy);

$sumFy = function (string $col, string $fyStart) use ($pdo) {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM($col),0) FROM fin_tax_filings WHERE period_month BETWEEN ? AND ?");
    $stmt->execute([$fyStart, fin_fy_end($fyStart)]);
    return (float)$stmt->fetchColumn();
};
$lastFy = date('Y-m-d', strtotime($fyNow . ' -1 year'));
$liability = $sumFy('tax_liability', $fyNow);
$liabilityLy = $sumFy('tax_liability', $lastFy);
$paid = $sumFy('tax_paid', $fyNow);
$paidLy = $sumFy('tax_paid', $lastFy);
$pendingCount = (int)$pdo->query("SELECT COUNT(*) FROM fin_tax_filings WHERE status = 'pending'")->fetchColumn();
$pendingNewThisMonth = (int)$pdo->query("SELECT COUNT(*) FROM fin_tax_filings WHERE status = 'pending' AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')")->fetchColumn();

// Upcoming due dates: the statutory calendar for the next ~45 days, minus what's already filed / paid.
$done = [];
foreach ($pdo->query("SELECT return_type, period_month, status FROM fin_tax_filings") as $r) {
    $done[$r['return_type'] . '|' . $r['period_month']] = $r['status'];
}
$calendar = ['GSTR-3B' => 'GST Return Filing', 'GSTR-1' => 'GST Return Filing', 'TDS Payment' => 'TDS Payment', 'PF Return' => 'Statutory Compliance', 'ESI Return' => 'Statutory Compliance'];
if (setting('fin_tds_applicable', '1') !== '1') unset($calendar['TDS Payment']);
$upcoming = [];
for ($back = 3; $back >= 0; $back--) {
    $period = date('Y-m-01', strtotime(date('Y-m-01') . " -$back month"));
    foreach ($calendar as $type => $sub) {
        $due = fin_tax_due_date($type, $period);
        $status = $done[$type . '|' . $period] ?? null;
        if (in_array($status, ['filed', 'paid'], true)) continue;
        $days = (int)round((strtotime($due) - strtotime($today)) / 86400);
        if ($days > 45 || ($days < 0 && $status === null && $back > 1)) continue;
        $upcoming[] = ['type' => $type, 'period' => $period, 'due' => $due, 'days' => $days, 'sub' => $sub];
    }
}
usort($upcoming, fn($a, $b) => $a['due'] <=> $b['due']);
$upcoming = array_slice($upcoming, 0, 5);
// Overdue = pending filings past due plus calendar items past due, each return/period counted once.
$overdueKeys = [];
foreach ($upcoming as $u) {
    if ($u['days'] < 0) $overdueKeys[$u['type'] . '|' . $u['period']] = true;
}
foreach ($pdo->query("SELECT return_type, period_month FROM fin_tax_filings WHERE status = 'pending' AND due_date < CURDATE()") as $r) {
    $overdueKeys[$r['return_type'] . '|' . $r['period_month']] = true;
}
$overdueItems = count($overdueKeys);
$onTrack = $overdueItems === 0;

// ---- tabs ----
$tabs = ['gst' => 'GST', 'tds' => 'TDS', 'other' => 'Other Compliance', 'returns' => 'Returns & Filings', 'reports' => 'Reports'];
$tab = isset($tabs[input('tab')]) ? input('tab') : 'gst';
$filings = [];
$reportRows = [];
if ($tab === 'reports') {
    $cursor = strtotime($fy);
    while ($cursor <= strtotime(min($fyEnd, $today))) {
        $pm = date('Y-m-01', $cursor);
        $reportRows[$pm] = fin_gst_from_books($pm);
        $cursor = strtotime('+1 month', $cursor);
    }
    if (input('export') === 'csv') {
        $out = [];
        foreach ($reportRows as $pm => $r) $out[] = [date('M Y', strtotime($pm)), $r['output'], $r['input'], $r['net']];
        fin_csv('gst-summary-' . fin_fy_label($fy) . '.csv', ['Period', 'Output GST', 'Input GST (ITC)', 'Net GST Payable'], $out);
    }
} else {
    $where = ['period_month BETWEEN ? AND ?'];
    $params = [$fy, $fyEnd];
    if ($tab === 'gst') { $where[] = "category = 'gst'"; }
    elseif ($tab === 'tds') { $where[] = "category = 'tds'"; }
    elseif ($tab === 'other') { $where[] = "category NOT IN ('gst','tds')"; }
    [$pageNo, $perPage, $offset] = fin_page(8);
    $c = $pdo->prepare('SELECT COUNT(*) FROM fin_tax_filings WHERE ' . implode(' AND ', $where));
    $c->execute($params);
    $total = (int)$c->fetchColumn();
    $stmt = $pdo->prepare('SELECT * FROM fin_tax_filings WHERE ' . implode(' AND ', $where) . " ORDER BY period_month DESC, due_date DESC LIMIT $perPage OFFSET $offset");
    $stmt->execute($params);
    $filings = $stmt->fetchAll();
    if (input('export') === 'csv') {
        $stmt = $pdo->prepare('SELECT * FROM fin_tax_filings WHERE ' . implode(' AND ', $where) . ' ORDER BY period_month DESC');
        $stmt->execute($params);
        $out = [];
        foreach ($stmt as $f) $out[] = [$f['return_type'], date('M Y', strtotime($f['period_month'])), $f['due_date'], $f['filing_date'], $f['status'], $f['tax_liability'], $f['tax_paid'], $f['ack_no'], $f['challan_no']];
        fin_csv('tax-filings.csv', ['Return Type', 'Period', 'Due Date', 'Filing Date', 'Status', 'Tax Liability', 'Tax Paid', 'ACK No', 'Challan No'], $out);
    }
}
$activities = $pdo->query("SELECT * FROM fin_tax_filings WHERE status IN ('filed','paid') ORDER BY COALESCE(filing_date, payment_date) DESC, id DESC LIMIT 5")->fetchAll();

require __DIR__ . '/../includes/header.php';
fin_page_head('Tax & Compliance', 'Manage your GST, TDS, and other statutory compliance requirements with ease.', fin_date_chip());
fin_kpi_row([
    fin_kpi('fa-solid fa-file-invoice', 'blue', 'Total Tax Liability (YTD)', fin_money($liability), fin_pct_change($liability, $liabilityLy), 'vs last year', null, false),
    fin_kpi('fa-solid fa-money-bill-transfer', 'green', 'Total Tax Paid (YTD)', fin_money($paid), fin_pct_change($paid, $paidLy), 'vs last year'),
    fin_kpi('fa-solid fa-hourglass-half', 'red', 'Pending Filings', (string)$pendingCount, null, '', $pendingNewThisMonth ? '<span class="text-danger fw-semibold"><i class="fa-solid fa-arrow-up"></i> ' . $pendingNewThisMonth . '</span> added this month' : 'None added this month'),
    fin_kpi('fa-solid fa-shield-halved', 'green', 'Compliance Status', $onTrack ? '<span class="fin-pill fin-pill-success fs-6">On Track</span>' : '<span class="fin-pill fin-pill-danger fs-6">Action Needed</span>', null, '',
        $onTrack ? 'No critical alerts' : $overdueItems . ' overdue ' . ($overdueItems === 1 ? 'item' : 'items')),
]);
$returnQs = e(http_build_query($_GET));
$statusPill = ['filed' => ['Filed', 'success'], 'paid' => ['Paid', 'success'], 'pending' => ['Pending', 'warning']];
?>
<div class="row g-3">
  <div class="col-xl-8">
    <div class="fin-card mb-3" style="height:auto">
      <?= fin_tabs($tabs, $tab) ?>
      <div class="fin-card-head mt-3">
        <div>
          <h2 class="fin-card-title"><?= ['gst' => 'GST Filings', 'tds' => 'TDS Filings & Payments', 'other' => 'PF, ESI & Other Compliance', 'returns' => 'All Returns & Filings', 'reports' => 'GST Summary from Books'][$tab] ?></h2>
          <p class="fin-card-sub"><?= $tab === 'reports' ? 'Output tax on sales, input tax credit on purchases and expenses, and the net payable each month.' : 'View and manage your returns, payments and acknowledgements.' ?></p>
        </div>
        <form method="get" class="d-flex gap-2 flex-wrap">
          <input type="hidden" name="tab" value="<?= e($tab) ?>">
          <select name="fy" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
            <?php foreach ($fyOptions as $k => $l): ?><option value="<?= $k ?>" <?= $k === $fy ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
          </select>
          <a class="btn btn-sm btn-outline-secondary" href="?<?= e(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>"><i class="fa-solid fa-download"></i> Export</a>
          <?php if ($canEdit && $tab !== 'reports'): $cat = ['gst' => 'gst', 'tds' => 'tds', 'other' => 'pf'][$tab] ?? 'gst'; ?>
            <a class="btn btn-sm btn-brand" href="tax_filing_form.php?category=<?= $cat ?>"><i class="fa-solid fa-plus"></i> <?= $tab === 'gst' ? 'File GST Return' : ($tab === 'tds' ? 'Record TDS' : 'New Filing') ?></a>
          <?php endif; ?>
        </form>
      </div>
      <div class="table-responsive">
      <?php if ($tab === 'reports'): ?>
        <table class="table fin-table">
          <thead><tr><th>Period</th><th class="text-end">Output GST</th><th class="text-end">Input GST (ITC)</th><th class="text-end">Net GST Payable</th><th class="text-end"></th></tr></thead>
          <tbody>
          <?php foreach ($reportRows as $pm => $r): ?>
            <tr><td><?= date('M Y', strtotime($pm)) ?></td><td class="text-end"><?= fin_num($r['output']) ?></td><td class="text-end"><?= fin_num($r['input']) ?></td><td class="text-end fw-semibold"><?= fin_num($r['net']) ?></td>
              <td class="text-end"><?php if ($canEdit): ?><a class="btn btn-sm btn-outline-brand" href="tax_filing_form.php?category=gst&return_type=GSTR-3B&period=<?= substr($pm, 0, 7) ?>">File 3B</a><?php endif; ?></td></tr>
          <?php endforeach; ?>
          <?php if ($reportRows): ?><tr class="fw-semibold"><td>Total</td><td class="text-end"><?= fin_num(array_sum(array_column($reportRows, 'output'))) ?></td><td class="text-end"><?= fin_num(array_sum(array_column($reportRows, 'input'))) ?></td><td class="text-end"><?= fin_num(array_sum(array_column($reportRows, 'net'))) ?></td><td></td></tr><?php endif; ?>
          </tbody>
        </table>
      <?php else: ?>
        <table class="table fin-table">
          <thead><tr><th>Return Type</th><th>Period</th><th>Due Date</th><th>Filing Date</th><th>Status</th><th class="text-end">Tax Liability (<?= e(setting('currency_symbol', '₹')) ?>)</th><th class="text-end">Actions</th></tr></thead>
          <tbody>
          <?php foreach ($filings as $f):
              $sp = $statusPill[$f['status']];
              if ($f['status'] === 'pending' && $f['due_date'] < $today) $sp = ['Overdue', 'danger'];
          ?>
            <tr>
              <td><?= e($f['return_type']) ?><?php if ($tab === 'returns' || $tab === 'other'): ?><div class="small text-muted"><?= e($categories[$f['category']] ?? '') ?></div><?php endif; ?></td>
              <td><?= date('M Y', strtotime($f['period_month'])) ?></td>
              <td class="text-nowrap"><?= fin_date($f['due_date']) ?></td>
              <td class="text-nowrap"><?= fin_date($f['filing_date'] ?: $f['payment_date']) ?></td>
              <td><?= fin_pill($sp[0], $sp[1]) ?></td>
              <td class="text-end"><?= fin_num($f['tax_liability']) ?></td>
              <td class="text-end"><div class="dropdown"><button class="btn btn-sm btn-link text-secondary" data-bs-toggle="dropdown"><i class="fa-solid fa-ellipsis"></i></button>
                <ul class="dropdown-menu dropdown-menu-end">
                  <li><a class="dropdown-item" href="tax_filing_form.php?id=<?= (int)$f['id'] ?>"><i class="fa-solid fa-<?= $canEdit ? 'pen' : 'eye' ?>"></i> <?= $canEdit ? 'Edit' : 'View' ?></a></li>
                  <?php if (can_manage_module('finance')): ?><li><form method="post" data-confirm="Delete this filing?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><input type="hidden" name="return" value="<?= $returnQs ?>">
                    <button class="dropdown-item text-danger"><i class="fa-solid fa-trash"></i> Delete</button></form></li><?php endif; ?>
                </ul></div></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$filings): ?><tr><td colspan="7" class="empty-state"><i class="fa-solid fa-file-circle-check"></i>No filings recorded for this year.</td></tr><?php endif; ?>
          </tbody>
        </table>
      <?php endif; ?>
      </div>
      <?php if ($tab !== 'reports') echo fin_pagination($total, $pageNo, $perPage, 'entries'); ?>
    </div>

    <div class="fin-card" style="height:auto">
      <div class="fin-card-head"><h2 class="fin-card-title">Recent Compliance Activities</h2><a class="fin-link" href="?tab=returns">View All <i class="fa-solid fa-arrow-right"></i></a></div>
      <table class="table fin-table">
        <thead><tr><th>Date</th><th>Activity</th><th>Type</th><th>Status</th><th>Remarks</th></tr></thead>
        <tbody>
        <?php foreach ($activities as $a): $isPay = $a['status'] === 'paid' || str_contains($a['return_type'], 'Payment'); ?>
          <tr>
            <td class="text-nowrap"><?= fin_date($a['filing_date'] ?: $a['payment_date']) ?></td>
            <td><?= e(($isPay ? 'Paid ' : 'Filed ') . $a['return_type'] . ' for ' . date('M Y', strtotime($a['period_month']))) ?></td>
            <td><?= e($categories[$a['category']] ?? '') ?></td>
            <td><?= fin_pill('Success', 'success') ?></td>
            <td><?= e($a['remarks'] ?: ($isPay ? 'Payment completed' : 'Filed successfully')) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$activities): ?><tr><td colspan="5" class="empty-state"><i class="fa-solid fa-clock-rotate-left"></i>No filings recorded yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="col-xl-4">
    <div class="fin-card mb-3" style="height:auto">
      <div class="fin-card-head"><h2 class="fin-card-title">Upcoming Due Dates</h2><a class="fin-link" href="?tab=returns">View All <i class="fa-solid fa-arrow-right"></i></a></div>
      <?php foreach ($upcoming as $u): $urgent = $u['days'] <= 5; ?>
        <a class="d-flex gap-3 align-items-center py-2 border-bottom text-reset text-decoration-none" href="<?= $canEdit ? 'tax_filing_form.php?return_type=' . urlencode($u['type']) . '&period=' . substr($u['period'], 0, 7) : '#' ?>">
          <div class="fin-date-badge <?= $urgent ? 'urgent' : '' ?>"><strong><?= date('d', strtotime($u['due'])) ?></strong><small><?= date('M', strtotime($u['due'])) ?></small></div>
          <div class="flex-grow-1"><div class="fw-medium"><?= e($u['type']) ?> (<?= date('M Y', strtotime($u['period'])) ?>)</div><small class="text-muted"><?= e($u['sub']) ?></small></div>
          <?= $u['days'] < 0 ? fin_pill('Overdue ' . abs($u['days']) . 'd', 'danger') : fin_pill($u['days'] === 0 ? 'Due today' : 'Due in ' . $u['days'] . ' days', $urgent ? 'danger' : 'info') ?>
        </a>
      <?php endforeach; ?>
      <?php if (!$upcoming): ?><div class="empty-state"><i class="fa-solid fa-circle-check text-success"></i>Nothing due in the next 45 days.</div><?php endif; ?>
    </div>
    <div class="fin-card" style="height:auto">
      <div class="fin-card-head"><h2 class="fin-card-title">Quick Actions</h2></div>
      <div class="fin-tiles cols-2 mb-3">
        <?= fin_action_tile('tax_filing_form.php?category=gst', 'fa-solid fa-file-lines', 'blue', 'File GST Return') ?>
        <?= fin_action_tile('tax_filing_form.php?category=tds&return_type=TDS+Payment', 'fa-solid fa-indian-rupee-sign', 'green', 'Make Tax Payment') ?>
        <?= fin_action_tile('?tab=reports&export=csv', 'fa-solid fa-file-arrow-down', 'slate', 'Download Forms') ?>
        <?= fin_action_tile('?tab=reports', 'fa-solid fa-chart-column', 'blue', 'View Tax Reports') ?>
      </div>
      <a class="fin-callout" href="configuration.php?section=tax">
        <i class="fa-solid fa-shield-halved fs-3 text-primary"></i>
        <span><strong>Stay Compliant, Always</strong><br><small>Get reminders, file on time, and avoid penalties with automated compliance tracking.</small></span>
        <i class="fa-solid fa-arrow-right ms-auto text-primary"></i>
      </a>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
