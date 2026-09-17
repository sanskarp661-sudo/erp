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

<div class="d-flex gap-2 mb-3">
  <a href="employee_form.php" class="btn btn-brand"><i class="fa-solid fa-plus"></i> Add Employee</a>
  <a href="attendance.php" class="btn btn-outline-brand">Mark Attendance</a>
  <a href="leaves.php" class="btn btn-outline-secondary">Leave Requests</a>
</div>

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
<?php require __DIR__ . '/../includes/footer.php'; ?>
