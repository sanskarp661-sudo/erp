<?php
require_once __DIR__ . '/../includes/auth.php';
require_role(['admin', 'manager']);

if (is_post() && input('action') === 'delete') {
    csrf_verify();
    $id = (int)input('id');
    $stmt = db()->prepare('SELECT status FROM salary_slips WHERE id = ?');
    $stmt->execute([$id]);
    $slip = $stmt->fetch();
    if ($slip && $slip['status'] === 'paid') {
        flash('danger', 'Cannot delete a paid salary slip.');
    } else {
        db()->prepare('DELETE FROM salary_slips WHERE id = ?')->execute([$id]);
        flash('success', 'Salary slip deleted.');
    }
    redirect('/hr/salary_slips.php');
}

$employeeFilter = (int)input('employee');
$employeeFilterName = null;
$sql = "SELECT s.*, e.name employee_name, e.employee_code FROM salary_slips s JOIN employees e ON e.id = s.employee_id";
$params = [];
if ($employeeFilter) {
    $sql .= " WHERE s.employee_id = ?";
    $params[] = $employeeFilter;
    $stmt = db()->prepare('SELECT name FROM employees WHERE id = ?');
    $stmt->execute([$employeeFilter]);
    $employeeFilterName = $stmt->fetchColumn();
}
$sql .= " ORDER BY s.pay_period_start DESC, s.id DESC";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$slips = $stmt->fetchAll();

$badge = ['draft' => 'secondary', 'paid' => 'success'];

$page_title = 'Salary Slips';
require __DIR__ . '/../includes/header.php';
?>
<?php if ($employeeFilter): ?>
  <div class="alert alert-info d-flex justify-content-between align-items-center">
    <span>Showing salary slips for <strong><?= e($employeeFilterName ?: 'Unknown employee') ?></strong></span>
    <a href="salary_slips.php" class="btn btn-sm btn-outline-secondary">Clear filter</a>
  </div>
<?php endif; ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <input type="text" class="form-control" style="max-width:280px" placeholder="Search salary slips..." data-table-search="#slipTable">
  <a href="salary_slip_form.php" class="btn btn-brand"><i class="fa-solid fa-plus"></i> Generate Salary Slip</a>
</div>
<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-hover" id="slipTable">
      <thead><tr><th>Slip #</th><th>Employee</th><th>Pay Period</th><th class="text-end">Net Pay</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($slips as $s): ?>
        <tr>
          <td><?= e($s['slip_no']) ?></td>
          <td><?= e($s['employee_name']) ?> <span class="text-muted small">(<?= e($s['employee_code']) ?>)</span></td>
          <td><?= e(date('M j', strtotime($s['pay_period_start']))) ?> &ndash; <?= e(date('M j, Y', strtotime($s['pay_period_end']))) ?></td>
          <td class="text-end fw-bold"><?= money($s['net_pay']) ?></td>
          <td><span class="badge text-bg-<?= $badge[$s['status']] ?> badge-status"><?= e($s['status']) ?></span></td>
          <td class="text-end">
            <a href="salary_slip_view.php?id=<?= (int)$s['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-eye"></i> View</a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$slips): ?><tr><td colspan="6" class="empty-state"><i class="fa-solid fa-money-check-dollar"></i><div>No salary slips generated yet.</div></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
