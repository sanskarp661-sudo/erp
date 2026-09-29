<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
$page_title = 'Bank Reconciliation';
fin_require_schema();

$pdo = db();
$gl = fin_gl_sql();
$banks = fin_ledger_options(fn($a) => $a['is_bank'] || $a['account_type'] === 'bank');
$accountId = (int)input('account');
if (!isset($banks[$accountId])) $accountId = (int)array_key_first($banks);
$show = input('show') === 'all' ? 'all' : 'uncleared';
$from = input('from') ?: date('Y-m-01', strtotime('-2 months'));
$to = input('to') ?: today();

if (is_post() && $accountId) {
    require_module_edit('finance');
    csrf_verify();
    $set = $pdo->prepare('INSERT INTO fin_bank_clearances (source_type, source_id, account_id, cleared_on, created_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE cleared_on = VALUES(cleared_on)');
    $del = $pdo->prepare('DELETE FROM fin_bank_clearances WHERE source_type = ? AND source_id = ? AND account_id = ?');
    $n = 0;
    foreach ((array)($_POST['clear'] ?? []) as $key => $date) {
        if (!preg_match('/^([a-z_]+):(\d+)$/', (string)$key, $mm)) continue;
        $date = trim((string)$date);
        if ($date === '') {
            $del->execute([$mm[1], (int)$mm[2], $accountId]);
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $set->execute([$mm[1], (int)$mm[2], $accountId, $date, current_user()['id']]);
            $n++;
        }
    }
    log_activity('bank_reconciliation', $accountId, 'updated', "Reconciled $n entries on " . $banks[$accountId]['name']);
    flash('success', 'Reconciliation saved.');
    redirect('/accounting/bank_reconciliation.php?' . http_build_query(['account' => $accountId, 'show' => $show, 'from' => $from, 'to' => $to]));
}

$rows = [];
$bookBal = $clearedBal = 0.0;
if ($accountId) {
    $bookBal = fin_balance_of([$accountId], $to);
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(g.debit - g.credit), 0) FROM ($gl) g JOIN fin_bank_clearances bc ON bc.source_type = g.source_type AND bc.source_id = g.source_id AND bc.account_id = g.account_id
        WHERE g.account_id = ? AND bc.cleared_on <= ?");
    $stmt->execute([$accountId, $to]);
    $opening = $pdo->prepare("SELECT COALESCE(SUM(debit - credit), 0) FROM ($gl) g WHERE account_id = ? AND source_type = 'opening' AND entry_date <= ?");
    $opening->execute([$accountId, $to]);
    $clearedBal = (float)$stmt->fetchColumn() + (float)$opening->fetchColumn();
    $sql = "SELECT g.*, bc.cleared_on FROM ($gl) g LEFT JOIN fin_bank_clearances bc ON bc.source_type = g.source_type AND bc.source_id = g.source_id AND bc.account_id = g.account_id
        WHERE g.account_id = ? AND g.source_type <> 'opening' AND g.entry_date <= ?" . ($show === 'uncleared' ? ' AND bc.id IS NULL' : ' AND g.entry_date >= ?') . ' ORDER BY g.entry_date, g.voucher_no';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($show === 'uncleared' ? [$accountId, $to] : [$accountId, $to, $from]);
    $rows = $stmt->fetchAll();
}

require __DIR__ . '/../includes/header.php';
fin_page_head('Bank Reconciliation', 'Match your books with the bank statement: enter the date each entry cleared the bank.', '<a href="bank_cash.php" class="btn btn-outline-secondary"><i class="fa-solid fa-chevron-left"></i> Bank &amp; Cash</a>');
?>
<?php if (!$banks): ?>
  <div class="alert alert-info">No bank ledgers yet. <a href="ledger_account_form.php">Create a ledger</a> with "Is Bank Account?" switched on.</div>
<?php else: ?>
<div class="fin-card mb-3">
  <form method="get" class="row g-2 align-items-end">
    <div class="col-md-4"><label class="form-label small">Bank Account</label>
      <select name="account" class="form-select"><?php foreach ($banks as $id => $a): ?><option value="<?= $id ?>" <?= $id === $accountId ? 'selected' : '' ?>><?= e(fin_account_label($a)) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label small">Show</label>
      <select name="show" class="form-select"><option value="uncleared">Uncleared only</option><option value="all" <?= $show === 'all' ? 'selected' : '' ?>>All in period</option></select></div>
    <div class="col-md-2"><label class="form-label small">From</label><input type="date" name="from" value="<?= e($from) ?>" class="form-control"></div>
    <div class="col-md-2"><label class="form-label small">Statement Date</label><input type="date" name="to" value="<?= e($to) ?>" class="form-control"></div>
    <div class="col-md-2"><button class="btn btn-brand w-100">Load</button></div>
  </form>
</div>
<?php fin_kpi_row([
    fin_kpi('fa-solid fa-book', 'blue', 'Balance as per Books', fin_money($bookBal, 2)),
    fin_kpi('fa-solid fa-circle-check', 'green', 'Cleared Balance (as per Bank)', fin_money($clearedBal, 2)),
    fin_kpi('fa-solid fa-hourglass-half', 'orange', 'Uncleared Difference', fin_money($bookBal - $clearedBal, 2)),
    fin_kpi('fa-solid fa-list', 'purple', $show === 'uncleared' ? 'Uncleared Entries' : 'Entries in Period', (string)count($rows)),
]); ?>
<div class="fin-card">
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="account" value="<?= $accountId ?>"><input type="hidden" name="show" value="<?= e($show) ?>">
    <input type="hidden" name="from" value="<?= e($from) ?>"><input type="hidden" name="to" value="<?= e($to) ?>">
    <div class="table-responsive">
      <table class="table fin-table">
        <thead><tr><th>Date</th><th>Voucher No.</th><th>Party</th><th>Description</th><th class="text-end">Deposit</th><th class="text-end">Withdrawal</th><th style="width:190px">Clearance Date</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="text-nowrap"><?= fin_date($r['entry_date']) ?></td>
            <td class="text-nowrap"><?= e($r['voucher_no']) ?><div class="small text-muted"><?= e($r['voucher_type']) ?></div></td>
            <td><?= e($r['party'] ?: '—') ?></td>
            <td><?= e($r['description']) ?></td>
            <td class="text-end"><?= (float)$r['debit'] ? fin_num($r['debit']) : '' ?></td>
            <td class="text-end"><?= (float)$r['credit'] ? fin_num($r['credit']) : '' ?></td>
            <td><div class="input-group input-group-sm">
              <input type="date" class="form-control" name="clear[<?= e($r['source_type'] . ':' . (int)$r['source_id']) ?>]" value="<?= e($r['cleared_on'] ?? '') ?>" min="<?= e($r['entry_date']) ?>">
              <button type="button" class="btn btn-outline-secondary js-today" title="Cleared on statement date"><i class="fa-solid fa-check"></i></button>
            </div></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="7" class="empty-state"><i class="fa-solid fa-circle-check text-success"></i>Everything up to this date is reconciled.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php if ($rows && can_edit_module('finance')): ?><div class="text-end mt-3"><button class="btn btn-brand"><i class="fa-solid fa-floppy-disk"></i> Save Reconciliation</button></div><?php endif; ?>
  </form>
</div>
<?php endif; ?>
<?php
$extra_js_inline = "
document.querySelectorAll('.js-today').forEach(function (b) {
  b.addEventListener('click', function () { b.previousElementSibling.value = " . json_encode($to) . "; });
});";
require __DIR__ . '/../includes/footer.php';
