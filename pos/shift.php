<?php
require_once __DIR__ . '/../includes/auth.php';
$ctx = pos_page();
$pdo = db();
$user = current_user();

if (is_post()) {
    require_module_edit('pos');
    csrf_verify();
    $action = input('action');
    $back = input('back') === 'index' ? '/pos/index.php' : '/pos/shift.php';

    if ($action === 'open') {
        if (!$ctx['profile']) {
            flash('danger', 'Add a POS terminal in POS Settings before opening a shift.');
        } elseif ($ctx['shift']) {
            flash('warning', 'A shift is already open on ' . $ctx['profile']['name'] . '.');
        } else {
            $opening = round((float)input('opening_cash'), 2);
            if ($opening < 0) {
                flash('danger', 'Opening cash cannot be negative.');
                redirect($back);
            }
            $shiftNo = pos_next_code('SHF-' . date('Y') . '-', 'pos_shifts', 'shift_no', 4);
            $pdo->prepare("INSERT INTO pos_shifts (shift_no, pos_profile_id, warehouse_id, status, opened_by, opened_at, opening_cash, opening_remarks) VALUES (?,?,?,'open',?,NOW(),?,?)")
                ->execute([$shiftNo, $ctx['profile_id'], $ctx['warehouse_id'], $user['id'], $opening, mb_substr((string)input('opening_remarks'), 0, 255) ?: null]);
            flash('success', 'Shift ' . $shiftNo . ' opened on ' . $ctx['profile']['name'] . ' with ' . pos_money($opening) . ' in the drawer.');
        }
        redirect($back);
    }

    if ($action === 'close') {
        if (!pos_can('close_shift')) {
            flash('danger', 'Only a POS manager can close shifts.');
            redirect('/pos/shift.php');
        }
        $shift = $ctx['shift'];
        if (!$shift || (int)$shift['id'] !== (int)input('shift_id')) {
            flash('danger', 'That shift is no longer open.');
            redirect('/pos/shift.php');
        }
        $counted = input('counted_cash');
        if ($counted === '' || !is_numeric($counted) || (float)$counted < 0) {
            flash('danger', 'Enter the cash you counted in the drawer.');
            redirect('/pos/shift.php');
        }
        $t = pos_shift_totals($shift);
        $counted = round((float)$counted, 2);
        $pdo->prepare("UPDATE pos_shifts SET status = 'closed', closed_by = ?, closed_at = NOW(), expected_cash = ?, counted_cash = ?, difference = ?, total_sales = ?, total_returns = ?, remarks = ? WHERE id = ? AND status = 'open'")
            ->execute([$user['id'], $t['expected_cash'], $counted, round($counted - $t['expected_cash'], 2), $t['total_sales'], $t['total_returns'], mb_substr((string)input('remarks'), 0, 255) ?: null, $shift['id']]);
        flash('success', 'Shift ' . $shift['shift_no'] . ' closed. Difference: ' . pos_money($counted - $t['expected_cash']) . '.');
        redirect('/pos/shift.php?view=' . (int)$shift['id']);
    }
}

// Shift report (printable) for one shift.
if ($viewId = (int)input('view')) {
    $stmt = $pdo->prepare('SELECT s.*, pp.name terminal_name, w.name store_name, uo.name opened_by_name, uc.name closed_by_name FROM pos_shifts s
        LEFT JOIN pos_profiles pp ON pp.id = s.pos_profile_id LEFT JOIN warehouses w ON w.id = s.warehouse_id
        LEFT JOIN users uo ON uo.id = s.opened_by LEFT JOIN users uc ON uc.id = s.closed_by WHERE s.id = ?');
    $stmt->execute([$viewId]);
    $vs = $stmt->fetch();
    if (!$vs) {
        flash('danger', 'Shift not found.');
        redirect('/pos/shift.php?tab=history');
    }
    $t = pos_shift_totals($vs);
    $expected = $vs['status'] === 'closed' ? (float)$vs['expected_cash'] : $t['expected_cash'];
    $page_title = 'Shift ' . $vs['shift_no'];
    require __DIR__ . '/../includes/header.php';
    ?>
    <div class="pos-head no-print">
      <h1 class="pos-title">Shift Report <span class="text-muted fw-normal fs-6"><?= e($vs['shift_no']) ?></span></h1>
      <div class="d-flex gap-2"><a href="<?= base_url('pos/shift.php?tab=history') ?>" class="btn btn-light border">Back</a><button class="btn btn-brand" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button></div>
    </div>
    <div class="pos-card pos-card-body" style="max-width:760px">
      <div class="pos-facts">
        <div class="f"><span>Status</span><strong><span class="pos-pill <?= $vs['status'] === 'open' ? 'green' : 'gray' ?>"><?= e(ucfirst($vs['status'])) ?></span></strong></div>
        <div class="f"><span>Terminal / Store</span><strong><?= e(($vs['terminal_name'] ?? '-') . ' · ' . ($vs['store_name'] ?? '-')) ?></strong></div>
        <div class="f"><span>Opened</span><strong><?= e(pos_datetime($vs['opened_at'])) ?> by <?= e($vs['opened_by_name'] ?? '-') ?></strong></div>
        <div class="f"><span>Closed</span><strong><?= $vs['closed_at'] ? e(pos_datetime($vs['closed_at'])) . ' by ' . e($vs['closed_by_name'] ?? '-') : '-' ?></strong></div>
        <div class="f"><span>Orders</span><strong><?= (int)$t['orders'] ?></strong></div>
        <div class="f"><span>Total Sales</span><strong><?= e(pos_money($t['total_sales'])) ?></strong></div>
        <div class="f"><span>Total Returns</span><strong><?= e(pos_money($t['total_returns'])) ?> (<?= (int)$t['returns_count'] ?>)</strong></div>
      </div>
      <hr>
      <h6 class="fw-bold">Money by payment method</h6>
      <table class="table pos-table mb-3"><thead><tr><th>Method</th><th class="text-end">Net amount</th></tr></thead><tbody>
        <?php foreach ($t['methods'] as $m => $amt): ?><tr><td><?= e(pos_method_label($m)) ?></td><td class="text-end"><?= e(pos_money($amt)) ?></td></tr><?php endforeach; ?>
        <?php if (!$t['methods']): ?><tr><td colspan="2" class="text-muted">No payments in this shift.</td></tr><?php endif; ?>
      </tbody></table>
      <div class="pos-facts">
        <div class="f"><span>Opening Cash</span><strong><?= e(pos_money($vs['opening_cash'])) ?></strong></div>
        <div class="f"><span>Cash in / out</span><strong><?= e(pos_money($t['cash_movement'])) ?></strong></div>
        <div class="f"><span>Expected Cash</span><strong><?= e(pos_money($expected)) ?></strong></div>
        <?php if ($vs['status'] === 'closed'): ?>
          <div class="f"><span>Counted Cash</span><strong><?= e(pos_money($vs['counted_cash'])) ?></strong></div>
          <div class="f"><span>Difference</span><strong class="<?= (float)$vs['difference'] < 0 ? 'text-danger' : ((float)$vs['difference'] > 0 ? 'text-success' : '') ?>"><?= e(pos_money($vs['difference'])) ?></strong></div>
          <?php if ($vs['remarks']): ?><div class="f"><span>Remarks</span><strong><?= e($vs['remarks']) ?></strong></div><?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
    <?php
    require __DIR__ . '/../includes/footer.php';
    exit;
}

$tab = input('tab') === 'history' ? 'history' : 'current';
$shift = $ctx['shift'];
$totals = $shift ? pos_shift_totals($shift) : null;

$history = [];
if ($tab === 'history') {
    $terminal = (int)input('terminal');
    $sql = 'SELECT s.*, pp.name terminal_name, uo.name opened_by_name FROM pos_shifts s LEFT JOIN pos_profiles pp ON pp.id = s.pos_profile_id LEFT JOIN users uo ON uo.id = s.opened_by';
    $params = [];
    if ($terminal) {
        $sql .= ' WHERE s.pos_profile_id = ?';
        $params[] = $terminal;
    }
    $sql .= ' ORDER BY s.id DESC LIMIT 100';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $history = $stmt->fetchAll();
}

$page_title = 'Opening & Closing Shift';
require __DIR__ . '/../includes/header.php';
?>
<div class="pos-card pos-card-body">
  <h1 class="pos-title mb-3">Opening &amp; Closing Shift</h1>
  <div class="pos-shift-grid">
    <div>
      <div class="pos-seg-tabs">
        <a href="<?= base_url('pos/shift.php') ?>" class="<?= $tab === 'current' ? 'active' : '' ?>">Current Shift</a>
        <a href="<?= base_url('pos/shift.php?tab=history') ?>" class="<?= $tab === 'history' ? 'active' : '' ?>">Shift History</a>
      </div>
      <div class="pos-card" style="border-top-left-radius:0">
        <div class="pos-card-body">
        <?php if ($tab === 'current'): ?>
          <?php if ($shift): ?>
            <div class="pos-facts">
              <div class="f"><span>Shift Status</span><strong><span class="pos-pill green">Open</span> <span class="text-muted small ms-1"><?= e($shift['shift_no']) ?> · <?= e($ctx['profile']['name']) ?></span></strong></div>
              <div class="f"><span>Opened By</span><strong><?= e($shift['opened_by_name'] ?? '-') ?></strong></div>
              <div class="f"><span>Opened At</span><strong><?= e(pos_datetime($shift['opened_at'])) ?></strong></div>
              <div class="f"><span>Opening Cash (<?= e(setting('currency_symbol', '$')) ?>)</span><strong><?= e(pos_number($shift['opening_cash'])) ?></strong></div>
              <div class="f"><span>Expected Cash (<?= e(setting('currency_symbol', '$')) ?>)</span><strong><?= e(pos_number($totals['expected_cash'])) ?></strong></div>
              <div class="f"><span>Total Sales (<?= e(setting('currency_symbol', '$')) ?>)</span><strong><?= e(pos_number($totals['total_sales'])) ?> <span class="text-muted small fw-normal">(<?= (int)$totals['orders'] ?> orders)</span></strong></div>
              <div class="f"><span>Total Returns (<?= e(setting('currency_symbol', '$')) ?>)</span><strong><?= e(pos_number($totals['total_returns'])) ?></strong></div>
            </div>
            <?php if ($totals['methods']): ?>
              <hr><div class="small text-muted mb-1">Collected by method this shift</div>
              <div class="d-flex flex-wrap gap-2">
                <?php foreach ($totals['methods'] as $m => $amt): ?><span class="pos-pill blue"><?= e(pos_method_label($m)) ?>: <?= e(pos_money($amt)) ?></span><?php endforeach; ?>
              </div>
            <?php endif; ?>
          <?php elseif (!$ctx['profile']): ?>
            <p class="text-muted mb-0">No POS terminal is active. <?= can_manage_module('pos') ? '<a href="' . base_url('pos/settings.php?s=profiles') . '">Add one in POS Settings</a>.' : 'Ask a POS manager to add one.' ?></p>
          <?php else: ?>
            <div class="pos-facts mb-3">
              <div class="f"><span>Shift Status</span><strong><span class="pos-pill gray">Closed</span></strong></div>
              <div class="f"><span>Terminal</span><strong><?= e($ctx['profile']['name']) ?> · <?= e($ctx['store_name']) ?></strong></div>
            </div>
            <p class="text-muted">No shift is open on this terminal. Count the cash in the drawer and open a shift to start selling.</p>
          <?php endif; ?>
        <?php else: ?>
          <form class="d-flex gap-2 mb-3" method="get">
            <input type="hidden" name="tab" value="history">
            <select name="terminal" class="form-select form-select-sm" style="max-width:220px" onchange="this.form.submit()">
              <option value="0">All terminals</option>
              <?php foreach (pos_profiles(false) as $p): ?><option value="<?= (int)$p['id'] ?>" <?= (int)input('terminal') === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
            </select>
          </form>
          <div class="table-responsive">
            <table class="table pos-table">
              <thead><tr><th>Shift</th><th>Terminal</th><th>Opened</th><th>Closed</th><th>By</th><th class="text-end">Sales</th><th class="text-end">Expected</th><th class="text-end">Counted</th><th class="text-end">Diff.</th><th></th></tr></thead>
              <tbody>
              <?php foreach ($history as $h): ?>
                <tr>
                  <td><a class="no" href="<?= base_url('pos/shift.php?view=' . (int)$h['id']) ?>"><?= e($h['shift_no']) ?></a></td>
                  <td><?= e($h['terminal_name'] ?? '-') ?></td>
                  <td class="small"><?= e(pos_datetime($h['opened_at'])) ?></td>
                  <td class="small"><?= $h['closed_at'] ? e(pos_datetime($h['closed_at'])) : '<span class="pos-pill green">Open</span>' ?></td>
                  <td><?= e($h['opened_by_name'] ?? '-') ?></td>
                  <td class="text-end"><?= $h['status'] === 'closed' ? e(pos_money($h['total_sales'])) : '-' ?></td>
                  <td class="text-end"><?= $h['status'] === 'closed' ? e(pos_money($h['expected_cash'])) : '-' ?></td>
                  <td class="text-end"><?= $h['status'] === 'closed' ? e(pos_money($h['counted_cash'])) : '-' ?></td>
                  <td class="text-end <?= (float)$h['difference'] < 0 ? 'text-danger' : ((float)$h['difference'] > 0 ? 'text-success' : '') ?>"><?= $h['status'] === 'closed' ? e(pos_money($h['difference'])) : '-' ?></td>
                  <td><a class="pos-act" href="<?= base_url('pos/shift.php?view=' . (int)$h['id']) ?>">View</a></td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$history): ?><tr><td colspan="10" class="text-center text-muted py-4">No shifts yet.</td></tr><?php endif; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="pos-card">
      <?php if ($shift): ?>
        <form method="post" class="pos-card-body">
          <?= csrf_field() ?><input type="hidden" name="action" value="close"><input type="hidden" name="shift_id" value="<?= (int)$shift['id'] ?>">
          <h2 class="pos-card-title mb-3">Close Shift</h2>
          <div class="row g-2 align-items-center mb-3">
            <label class="col-6 text-muted" for="shCounted">Counted Cash (<?= e(setting('currency_symbol', '$')) ?>)</label>
            <div class="col-6"><input type="number" step="0.01" min="0" class="form-control" name="counted_cash" id="shCounted" value="<?= e(number_format($totals['expected_cash'], 2, '.', '')) ?>" required></div>
          </div>
          <div class="row g-2 align-items-center mb-3">
            <label class="col-6 text-muted" for="shDiff">Difference (<?= e(setting('currency_symbol', '$')) ?>)</label>
            <div class="col-6"><input type="text" class="form-control bg-light" id="shDiff" readonly value="0.00"></div>
          </div>
          <label class="form-label text-muted" for="shRemarks">Remarks (Optional)</label>
          <textarea class="form-control mb-3" name="remarks" id="shRemarks" rows="2" placeholder="Enter remarks..." maxlength="255"></textarea>
          <?php if (pos_can('close_shift')): ?>
            <div class="text-end"><button class="btn btn-brand btn-lg px-5" data-confirm="Close this shift? Sales on this terminal will need a new shift.">Close Shift</button></div>
          <?php else: ?>
            <div class="small text-muted">Only a POS manager can close shifts.</div>
          <?php endif; ?>
        </form>
      <?php elseif ($ctx['profile']): ?>
        <form method="post" class="pos-card-body">
          <?= csrf_field() ?><input type="hidden" name="action" value="open">
          <h2 class="pos-card-title mb-3">Open Shift</h2>
          <div class="row g-2 align-items-center mb-3">
            <label class="col-6 text-muted" for="shOpening">Opening Cash (<?= e(setting('currency_symbol', '$')) ?>)</label>
            <div class="col-6"><input type="number" step="0.01" min="0" class="form-control" name="opening_cash" id="shOpening" value="0.00" required></div>
          </div>
          <label class="form-label text-muted" for="shORemarks">Remarks (Optional)</label>
          <textarea class="form-control mb-3" name="opening_remarks" id="shORemarks" rows="2" maxlength="255"></textarea>
          <div class="text-end"><button class="btn btn-brand btn-lg px-5">Open Shift</button></div>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php
if ($shift) {
    $extra_js_inline = '(function(){var c=document.getElementById("shCounted"),d=document.getElementById("shDiff"),exp=' . json_encode($totals['expected_cash']) . ';
      function u(){var v=(parseFloat(c.value)||0)-exp;d.value=(Math.round(v*100)/100).toFixed(2);d.classList.toggle("text-danger",v<-0.004);d.classList.toggle("text-success",v>0.004);}c.addEventListener("input",u);u();})();';
}
$extra_js = [asset_url('assets/js/pos.js')];
require __DIR__ . '/../includes/footer.php';
