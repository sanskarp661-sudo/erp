<?php
require_once __DIR__ . '/../includes/auth.php';
require_role(['admin', 'manager']);

$id = (int)input('id');
$stmt = db()->prepare("
  SELECT s.*, e.name employee_name, e.employee_code, e.designation, e.email employee_email, d.name department_name
  FROM salary_slips s
  JOIN employees e ON e.id = s.employee_id
  LEFT JOIN departments d ON d.id = e.department_id
  WHERE s.id = ?
");
$stmt->execute([$id]);
$slip = $stmt->fetch();

if (!$slip) {
    die('Salary slip not found.');
}

$items = db()->prepare('SELECT * FROM salary_slip_items WHERE salary_slip_id = ? ORDER BY id');
$items->execute([$id]);
$items = $items->fetchAll();
$earnings = array_filter($items, fn($i) => $i['component_type'] === 'earning');
$deductions = array_filter($items, fn($i) => $i['component_type'] === 'deduction');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Salary Slip <?= e($slip['slip_no']) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body { padding: 40px; color: #1f2937; }
  .brand { font-size: 1.4rem; font-weight: 700; }
  @media print { .no-print { display: none; } body { padding: 0; } }
</style>
</head>
<body>
<div class="container">
  <div class="d-flex justify-content-between align-items-start mb-4">
    <div>
      <div class="brand"><?= e(setting('company_name', APP_NAME)) ?></div>
      <div class="text-muted">Payslip</div>
    </div>
    <div class="text-end">
      <div class="fs-4 fw-bold"><?= e($slip['slip_no']) ?></div>
      <div>Period: <?= e(date('M j', strtotime($slip['pay_period_start']))) ?> &ndash; <?= e(date('M j, Y', strtotime($slip['pay_period_end']))) ?></div>
      <div>Status: <?= e(ucfirst($slip['status'])) ?></div>
    </div>
  </div>

  <div class="row mb-4">
    <div class="col-6">
      <strong>Employee:</strong><br>
      <?= e($slip['employee_name']) ?> (<?= e($slip['employee_code']) ?>)<br>
      <?php if ($slip['designation']): ?><?= e($slip['designation']) ?><br><?php endif; ?>
      <?php if ($slip['department_name']): ?><?= e($slip['department_name']) ?><br><?php endif; ?>
      <?php if ($slip['employee_email']): ?><?= e($slip['employee_email']) ?><?php endif; ?>
    </div>
  </div>

  <table class="table table-bordered">
    <thead class="table-light"><tr><th>Earnings</th><th class="text-end">Amount</th></tr></thead>
    <tbody>
      <tr><td>Basic Salary</td><td class="text-end"><?= money($slip['basic_salary']) ?></td></tr>
      <?php foreach ($earnings as $e): ?>
        <tr><td><?= e($e['label']) ?></td><td class="text-end"><?= money($e['amount']) ?></td></tr>
      <?php endforeach; ?>
      <tr class="table-light"><th>Total Earnings</th><th class="text-end"><?= money($slip['total_earnings']) ?></th></tr>
    </tbody>
  </table>

  <table class="table table-bordered">
    <thead class="table-light"><tr><th>Deductions</th><th class="text-end">Amount</th></tr></thead>
    <tbody>
      <?php foreach ($deductions as $d): ?>
        <tr><td><?= e($d['label']) ?></td><td class="text-end"><?= money($d['amount']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$deductions): ?><tr><td class="text-muted">None</td><td class="text-end">&mdash;</td></tr><?php endif; ?>
      <tr class="table-light"><th>Total Deductions</th><th class="text-end"><?= money($slip['total_deductions']) ?></th></tr>
    </tbody>
  </table>

  <table class="table">
    <tbody><tr class="table-success"><th class="fs-4">Net Pay</th><th class="text-end fs-4"><?= money($slip['net_pay']) ?></th></tr></tbody>
  </table>

  <?php if ($slip['status'] === 'paid'): ?>
    <p class="text-muted">Paid on <?= e($slip['payment_date']) ?> via <?= e(str_replace('_', ' ', ucfirst($slip['payment_method']))) ?>.</p>
  <?php endif; ?>

  <div class="no-print mt-4">
    <button class="btn btn-primary" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
  </div>
</div>
</body>
</html>
