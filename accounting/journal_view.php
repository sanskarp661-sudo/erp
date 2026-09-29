<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
$page_title = 'Journal Entry';
fin_require_schema();

$pdo = db();
$id = (int)input('id');
$load = function () use ($pdo, $id) {
    $stmt = $pdo->prepare('SELECT j.*, cc.name cc_name, u.name user_name FROM fin_journal_entries j LEFT JOIN fin_cost_centers cc ON cc.id = j.cost_center_id LEFT JOIN users u ON u.id = j.created_by WHERE j.id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch();
};
$entry = $load();
if (!$entry) {
    flash('danger', 'Journal entry not found.');
    redirect('/accounting/journals.php');
}

if (is_post()) {
    csrf_verify();
    $action = input('action');
    if ($action === 'submit' && $entry['status'] === 'draft') {
        require_module_edit('finance');
        if (abs($entry['total_debit'] - $entry['total_credit']) > 0.005 || $entry['total_debit'] <= 0) {
            flash('danger', 'This voucher does not balance; edit it before submitting.');
        } else {
            $pdo->prepare("UPDATE fin_journal_entries SET status='submitted', submitted_at=NOW() WHERE id=?")->execute([$id]);
            log_activity('journal', $id, 'submitted');
            flash('success', 'Voucher submitted and posted to the ledger.');
        }
    } elseif ($action === 'cancel' && $entry['status'] === 'submitted') {
        require_module_manage('finance');
        $pdo->prepare("UPDATE fin_journal_entries SET status='cancelled' WHERE id=?")->execute([$id]);
        $pdo->prepare("DELETE FROM fin_bank_clearances WHERE source_type='journal' AND source_id=?")->execute([$id]);
        log_activity('journal', $id, 'cancelled');
        flash('success', 'Voucher cancelled; its postings were reversed out of the ledger.');
    } elseif ($action === 'delete' && $entry['status'] === 'draft') {
        require_module_manage('finance');
        $pdo->prepare('DELETE FROM fin_journal_entries WHERE id=?')->execute([$id]);
        flash('success', 'Draft voucher deleted.');
        redirect('/accounting/journals.php');
    }
    redirect('/accounting/journal_view.php?id=' . $id);
}

$stmt = $pdo->prepare('SELECT l.*, la.name account_name, la.account_code, cc.name cc_name FROM fin_journal_lines l JOIN ledger_accounts la ON la.id = l.account_id LEFT JOIN fin_cost_centers cc ON cc.id = l.cost_center_id WHERE l.journal_id = ? ORDER BY l.sort_order, l.id');
$stmt->execute([$id]);
$lines = $stmt->fetchAll();
$types = fin_voucher_types();
$statusPill = ['draft' => ['Draft', 'secondary'], 'submitted' => ['Submitted', 'success'], 'cancelled' => ['Cancelled', 'danger']][$entry['status']];
$canEdit = can_edit_module('finance');
$canManage = can_manage_module('finance');
$page_title = $entry['voucher_no'];

require __DIR__ . '/../includes/header.php';
?>
<div class="card p-4 mb-3">
  <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
    <div>
      <h5 class="mb-1"><?= e($entry['voucher_no']) ?> <?= fin_pill($statusPill[0], $statusPill[1]) ?></h5>
      <div class="text-muted small"><?= e($types[$entry['voucher_type']] ?? $entry['voucher_type']) ?> · <?= fin_date($entry['posting_date']) ?> · by <?= e($entry['user_name'] ?? 'System') ?></div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
      <?php if ($entry['status'] === 'draft' && $canEdit): ?>
        <a href="journal_form.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen"></i> Edit</a>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="submit"><button class="btn btn-sm btn-brand"><i class="fa-solid fa-check"></i> Submit</button></form>
      <?php endif; ?>
      <?php if ($entry['status'] === 'draft' && $canManage): ?>
        <form method="post" data-confirm="Delete this draft voucher?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><button class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-trash"></i> Delete</button></form>
      <?php endif; ?>
      <?php if ($entry['status'] === 'submitted' && $canManage): ?>
        <form method="post" data-confirm="Cancel this voucher? Its postings will be removed from the ledger."><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><button class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-ban"></i> Cancel</button></form>
      <?php endif; ?>
      <a href="journals.php" class="btn btn-sm btn-outline-secondary">All Vouchers</a>
    </div>
  </div>
  <div class="row g-3 fin-kv">
    <?php foreach ([
        'Party / Payee' => $entry['party_name'] ?: '—', 'Reference No.' => $entry['reference_no'] ?: '—', 'Reference Date' => fin_date($entry['reference_date']),
        'Cost Center' => $entry['cc_name'] ?: '—', 'Project' => $entry['project'] ?: '—', 'Narration' => $entry['narration'] ?: '—',
    ] as $k => $v): ?>
      <div class="col-sm-4 col-lg-2"><div class="k"><?= e($k) ?></div><div class="v"><?= e($v) ?></div></div>
    <?php endforeach; ?>
  </div>
</div>
<div class="fin-card">
  <h2 class="fin-card-title mb-3">Account Lines</h2>
  <table class="table fin-table">
    <thead><tr><th>Account Code</th><th>Account</th><th>Cost Center</th><th>Narration</th><th class="text-end">Debit</th><th class="text-end">Credit</th></tr></thead>
    <tbody>
    <?php foreach ($lines as $l): ?>
      <tr><td><?= e($l['account_code'] ?: '—') ?></td><td><a class="text-reset" href="general_ledger.php?tab=all&account=<?= (int)$l['account_id'] ?>"><?= e($l['account_name']) ?></a></td>
        <td><?= e($l['cc_name'] ?? '—') ?></td><td><?= e($l['line_narration']) ?></td>
        <td class="text-end"><?= (float)$l['debit'] ? fin_num($l['debit']) : '' ?></td><td class="text-end"><?= (float)$l['credit'] ? fin_num($l['credit']) : '' ?></td></tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr class="fw-semibold"><td colspan="4" class="text-end">Total</td><td class="text-end"><?= fin_num($entry['total_debit']) ?></td><td class="text-end"><?= fin_num($entry['total_credit']) ?></td></tr></tfoot>
  </table>
  <?php if ($entry['remarks']): ?><div class="small text-muted"><strong>Remarks:</strong> <?= nl2br(e($entry['remarks'])) ?></div><?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
