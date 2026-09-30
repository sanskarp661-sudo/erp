<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/hr.php';
require_login();

$canEdit = can_edit_module('hrms');
$me = current_user();
$id = (int)input('id');

$load = function () use ($id) {
    $stmt = db()->prepare('SELECT l.*, e.name employee_name, e.employee_code, e.designation, d.name department_name,
        lt.name type_name, lt.is_paid, ap.name approver_name, db.name decided_by_name, cb.name created_by_name, h.name handover_name
        FROM leaves l JOIN employees e ON e.id = l.employee_id
        LEFT JOIN departments d ON d.id = e.department_id
        LEFT JOIN leave_types lt ON lt.code = l.leave_type
        LEFT JOIN users ap ON ap.id = l.leave_approver_id
        LEFT JOIN users db ON db.id = l.decided_by
        LEFT JOIN users cb ON cb.id = l.created_by
        LEFT JOIN employees h ON h.id = l.handover_to_id
        WHERE l.id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch();
};
$leave = $load();
if (!$leave) {
    flash('danger', 'Leave application not found.');
    redirect('/hr/leaves.php');
}
$canDecide = hr_can_decide_leave($leave);
$isOwner = (int)$leave['created_by'] === (int)($me['id'] ?? 0);
$canCancel = ($canEdit || $isOwner || $canDecide) && in_array($leave['status'], ['pending', 'approved'], true);

if (is_post()) {
    csrf_verify();
    $action = input('action');
    $remarks = trim((string)input('decision_remarks')) ?: null;
    $pdo = db();
    if (in_array($action, ['approved', 'rejected'], true) && $leave['status'] === 'pending') {
        if (!$canDecide) {
            flash('danger', 'You are not the approver for this leave application.');
            redirect('/hr/leave_view.php?id=' . $id);
        }
        if ($action === 'approved') {
            $balance = hr_leave_balance((int)$leave['employee_id'], $leave['leave_type'], (int)substr($leave['start_date'], 0, 4), $id);
            if ($balance !== null && (float)$leave['total_days'] > $balance['available']) {
                flash('danger', 'Cannot approve: only ' . $balance['available'] . ' day(s) of ' . $leave['type_name'] . ' are left.');
                redirect('/hr/leave_view.php?id=' . $id);
            }
        } elseif (!$remarks) {
            flash('danger', 'Please give a reason for rejecting.');
            redirect('/hr/leave_view.php?id=' . $id);
        }
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE leaves SET status = ?, decided_by = ?, decided_at = NOW(), decision_remarks = ? WHERE id = ?')
            ->execute([$action, $me['id'] ?? null, $remarks, $id]);
        if ($action === 'approved') {
            hr_mark_leave_attendance($leave);
        }
        log_activity('leave', $id, 'field_changed', null, 'Status', 'pending', $action);
        $pdo->commit();
        flash('success', $action === 'approved' ? 'Leave approved and attendance marked.' : 'Leave rejected.');
    } elseif ($action === 'cancel' && $canCancel) {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE leaves SET status = 'cancelled', decision_remarks = COALESCE(?, decision_remarks) WHERE id = ?")->execute([$remarks, $id]);
        hr_clear_leave_attendance($id);
        log_activity('leave', $id, 'field_changed', null, 'Status', $leave['status'], 'cancelled');
        $pdo->commit();
        flash('success', 'Leave application cancelled.' . ($leave['status'] === 'approved' ? ' Its attendance marks were removed.' : ''));
    }
    redirect('/hr/leave_view.php?id=' . $id);
}

$balance = hr_leave_balance((int)$leave['employee_id'], $leave['leave_type'], (int)substr($leave['start_date'], 0, 4));
$activities = get_activity_log('leave', $id);
$badge = ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger', 'cancelled' => 'secondary'];
$fmt = fn($v) => rtrim(rtrim(number_format((float)$v, 1, '.', ''), '0'), '.');

function leave_facts(array $facts): string
{
    $html = '';
    foreach ($facts as $label => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        $html .= '<div class="col-sm-6 col-lg-3"><div class="small text-muted">' . e($label) . '</div><div>' . e($value) . '</div></div>';
    }
    return $html;
}

$page_title = 'Leave ' . $leave['application_no'];
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-1"><?= e($leave['application_no']) ?> <span class="badge text-bg-<?= $badge[$leave['status']] ?? 'secondary' ?> badge-status"><?= e($leave['status']) ?></span></h4>
    <div class="text-muted"><?= e($leave['employee_name']) ?> (<?= e($leave['employee_code']) ?>) &middot; <?= e($leave['type_name'] ?? $leave['leave_type']) ?> &middot; <?= $fmt($leave['total_days']) ?> day(s)</div>
  </div>
  <div class="page-actions">
    <?php if ($leave['status'] === 'pending' && ($canEdit || $isOwner)): ?><a href="leave_form.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-pen"></i> Edit</a><?php endif; ?>
    <?php if ($canCancel): ?>
      <form method="post" class="d-inline" data-confirm="Cancel this leave application?">
        <?= csrf_field() ?><input type="hidden" name="action" value="cancel">
        <button class="btn btn-outline-danger btn-sm" type="submit"><i class="fa-solid fa-ban"></i> Cancel Leave</button>
      </form>
    <?php endif; ?>
    <a href="leaves.php" class="btn btn-outline-secondary btn-sm">Back to list</a>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-8">
    <div class="card p-3 mb-3">
      <h6 class="mb-3">Details</h6>
      <div class="row g-3">
        <?= leave_facts([
            'From' => $leave['start_date'], 'To' => $leave['end_date'],
            'Half Day' => $leave['half_day'] ? 'Yes, on ' . ($leave['half_day_date'] ?: $leave['start_date']) : null,
            'Total Days' => $fmt($leave['total_days']) . ($leave['is_paid'] === 0 || $leave['is_paid'] === '0' ? ' (unpaid)' : ''),
            'Department' => $leave['department_name'], 'Designation' => $leave['designation'],
            'Handed Over To' => $leave['handover_name'], 'Contact During Leave' => $leave['contact_during_leave'],
            'Applied' => date('M j, Y', strtotime($leave['applied_at'])) . ($leave['created_by_name'] ? ' by ' . $leave['created_by_name'] : ''),
            'Approver' => $leave['approver_name'] ?: 'HR managers',
            'Decided' => $leave['decided_at'] ? date('M j, Y', strtotime($leave['decided_at'])) . ($leave['decided_by_name'] ? ' by ' . $leave['decided_by_name'] : '') : null,
        ]) ?>
      </div>
      <?php if ($leave['reason']): ?><div class="mt-3"><div class="small text-muted">Reason</div><div><?= e($leave['reason']) ?></div></div><?php endif; ?>
      <?php if ($leave['decision_remarks']): ?><div class="mt-3"><div class="small text-muted">Approver Remarks</div><div><?= e($leave['decision_remarks']) ?></div></div><?php endif; ?>
    </div>
    <div class="card p-3">
      <h6 class="mb-2">Activity</h6>
      <ul class="list-unstyled activity-feed mb-0">
        <?php foreach ($activities as $a): ?><li><?= format_activity($a) ?> <span class="text-muted small">&middot; <?= e(date('M j, Y', strtotime($a['created_at']))) ?></span></li><?php endforeach; ?>
        <?php if (!$activities): ?><li class="text-muted small">No activity yet.</li><?php endif; ?>
      </ul>
    </div>
  </div>
  <div class="col-lg-4">
    <?php if ($balance): ?>
    <div class="card p-3 mb-3">
      <h6 class="mb-2"><?= e($leave['type_name']) ?> balance, <?= e(substr($leave['start_date'], 0, 4)) ?></h6>
      <div class="d-flex justify-content-between small"><span>Allocated</span><span><?= $fmt($balance['allocated']) ?></span></div>
      <div class="d-flex justify-content-between small"><span>Taken</span><span><?= $fmt($balance['taken']) ?></span></div>
      <div class="d-flex justify-content-between small"><span>Pending</span><span><?= $fmt($balance['pending']) ?></span></div>
      <div class="d-flex justify-content-between border-top mt-1 pt-1"><strong>Available</strong><strong><?= $fmt($balance['available']) ?></strong></div>
    </div>
    <?php endif; ?>
    <?php if ($leave['status'] === 'pending' && $canDecide): ?>
    <div class="card p-3">
      <h6 class="mb-2">Decision</h6>
      <form method="post">
        <?= csrf_field() ?>
        <label class="form-label small">Remarks (required to reject)</label>
        <textarea name="decision_remarks" class="form-control mb-2" rows="2" maxlength="255"></textarea>
        <div class="d-flex gap-2">
          <button type="submit" name="action" value="approved" class="btn btn-success flex-fill"><i class="fa-solid fa-check"></i> Approve</button>
          <button type="submit" name="action" value="rejected" class="btn btn-outline-danger flex-fill"><i class="fa-solid fa-xmark"></i> Reject</button>
        </div>
        <div class="form-text">Approving marks attendance as Leave on these days.</div>
      </form>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
