<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$pdo = db();
$employeeCount = (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE status='active'")->fetchColumn();
$departmentCount = (int)$pdo->query("SELECT COUNT(*) FROM departments")->fetchColumn();
$pendingLeaves = (int)$pdo->query("SELECT COUNT(*) FROM leaves WHERE status='pending'")->fetchColumn();

$today = today();
$stmt = $pdo->prepare('SELECT COUNT(*) FROM attendance WHERE attendance_date = ? AND status = "present"');
$stmt->execute([$today]);
$presentToday = (int)$stmt->fetchColumn();

$recentLeaves = $pdo->query("
  SELECT l.*, e.name employee_name
  FROM leaves l JOIN employees e ON e.id = l.employee_id
  ORDER BY l.applied_at DESC LIMIT 8
")->fetchAll();

$badge = ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger'];
$slipBadge = ['draft' => 'secondary', 'paid' => 'success'];

$canSeeSalary = can_manage_module('hrms');
$payrollThisMonth = 0;
$draftSlips = 0;
$recentSlips = [];
if ($canSeeSalary) {
    $payrollThisMonth = (float)$pdo->query("SELECT COALESCE(SUM(net_pay),0) FROM salary_slips WHERE status='paid' AND MONTH(payment_date)=MONTH(CURDATE()) AND YEAR(payment_date)=YEAR(CURDATE())")->fetchColumn();
    $draftSlips = (int)$pdo->query("SELECT COUNT(*) FROM salary_slips WHERE status='draft'")->fetchColumn();
    $recentSlips = $pdo->query("
      SELECT s.*, e.name employee_name
      FROM salary_slips s JOIN employees e ON e.id = s.employee_id
      ORDER BY s.id DESC LIMIT 8
    ")->fetchAll();
}

$page_title = 'HRMS';
require __DIR__ . '/../includes/header.php';
?>
<div class="row g-3 mb-3">
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card"><div class="icon bg-brand"><i class="fa-solid fa-user-tie"></i></div>
      <div><div class="value"><?= $employeeCount ?></div><div class="label">Active employees</div></div></div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card"><div class="icon bg-green"><i class="fa-solid fa-sitemap"></i></div>
      <div><div class="value"><?= $departmentCount ?></div><div class="label">Departments</div></div></div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card"><div class="icon bg-orange"><i class="fa-solid fa-calendar-check"></i></div>
      <div><div class="value"><?= $presentToday ?></div><div class="label">Present today</div></div></div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card"><div class="icon bg-purple"><i class="fa-solid fa-plane-departure"></i></div>
      <div><div class="value"><?= $pendingLeaves ?></div><div class="label">Pending leave requests</div></div></div>
  </div>
</div>

<?php if ($canSeeSalary): ?>
<div class="row g-3 mb-3">
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card"><div class="icon bg-brand"><i class="fa-solid fa-money-check-dollar"></i></div>
      <div><div class="value"><?= money($payrollThisMonth) ?></div><div class="label">Payroll paid this month</div></div></div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card"><div class="icon bg-orange"><i class="fa-solid fa-hourglass-half"></i></div>
      <div><div class="value"><?= $draftSlips ?></div><div class="label">Draft salary slips</div></div></div>
  </div>
</div>
<?php endif; ?>

<div class="d-flex gap-2 mb-3 flex-wrap">
  <a href="employee_form.php" class="btn btn-brand"><i class="fa-solid fa-plus"></i> Add Employee</a>
  <a href="attendance.php" class="btn btn-outline-brand">Mark Attendance</a>
  <a href="leaves.php" class="btn btn-outline-secondary">Leave Requests</a>
  <?php if ($canSeeSalary): ?><a href="salary_slip_form.php" class="btn btn-outline-secondary"><i class="fa-solid fa-money-check-dollar"></i> Generate Salary Slip</a><?php endif; ?>
</div>

<div class="row g-3">
  <div class="col-lg-<?= $canSeeSalary ? '6' : '12' ?>">
    <div class="card p-3">
      <h6 class="mb-2">Recent Leave Requests</h6>
      <div class="table-responsive">
        <table class="table table-sm">
          <thead><tr><th>Employee</th><th>Type</th><th>From</th><th>To</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($recentLeaves as $l): ?>
            <tr>
              <td><?= e($l['employee_name']) ?></td>
              <td class="text-capitalize"><?= e($l['leave_type']) ?></td>
              <td><?= e($l['start_date']) ?></td>
              <td><?= e($l['end_date']) ?></td>
              <td><span class="badge text-bg-<?= $badge[$l['status']] ?? 'secondary' ?> badge-status"><?= e($l['status']) ?></span></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$recentLeaves): ?><tr><td colspan="5" class="text-muted text-center">No leave requests yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php if ($canSeeSalary): ?>
  <div class="col-lg-6">
    <div class="card p-3">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h6 class="mb-0">Recent Salary Slips</h6>
        <a href="salary_slips.php" class="small">View all</a>
      </div>
      <div class="table-responsive">
        <table class="table table-sm">
          <thead><tr><th>Employee</th><th>Period</th><th class="text-end">Net Pay</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($recentSlips as $s): ?>
            <tr>
              <td><a href="salary_slip_view.php?id=<?= (int)$s['id'] ?>"><?= e($s['employee_name']) ?></a></td>
              <td><?= e(date('M Y', strtotime($s['pay_period_start']))) ?></td>
              <td class="text-end"><?= money($s['net_pay']) ?></td>
              <td><span class="badge text-bg-<?= $slipBadge[$s['status']] ?> badge-status"><?= e($s['status']) ?></span></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$recentSlips): ?><tr><td colspan="4" class="text-muted text-center">No salary slips yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
