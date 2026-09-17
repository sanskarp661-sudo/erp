<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$userFilter = (int)input('user');
$userFilterName = null;
$sql = "SELECT p.*, i.invoice_no, c.name customer_name FROM payments p JOIN invoices i ON i.id = p.invoice_id JOIN customers c ON c.id = i.customer_id";
$params = [];
if ($userFilter) {
    $sql .= " WHERE p.created_by = ?";
    $params[] = $userFilter;
    $stmt = db()->prepare('SELECT name FROM users WHERE id = ?');
    $stmt->execute([$userFilter]);
    $userFilterName = $stmt->fetchColumn();
}
$sql .= " ORDER BY p.id DESC";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$payments = $stmt->fetchAll();

$page_title = 'Payments';
require __DIR__ . '/../includes/header.php';
?>
<?php if ($userFilter): ?>
  <div class="alert alert-info d-flex justify-content-between align-items-center">
    <span>Showing payments recorded by <strong><?= e($userFilterName ?: 'Unknown user') ?></strong></span>
    <a href="payments.php" class="btn btn-sm btn-outline-secondary">Clear filter</a>
  </div>
<?php endif; ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <input type="text" class="form-control" style="max-width:280px" placeholder="Search payments..." data-table-search="#payTable">
  <div class="text-muted small">Payments are recorded from an invoice's page.</div>
</div>
<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-hover" id="payTable">
      <thead><tr><th>Date</th><th>Invoice</th><th>Customer</th><th>Method</th><th>Reference</th><th class="text-end">Amount</th></tr></thead>
      <tbody>
      <?php foreach ($payments as $p): ?>
        <tr>
          <td><?= e($p['payment_date']) ?></td>
          <td><a href="invoice_view.php?id=<?= (int)$p['invoice_id'] ?>"><?= e($p['invoice_no']) ?></a></td>
          <td><?= e($p['customer_name']) ?></td>
          <td class="text-capitalize"><?= e(str_replace('_', ' ', $p['method'])) ?></td>
          <td><?= e($p['reference']) ?></td>
          <td class="text-end"><?= money($p['amount']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$payments): ?><tr><td colspan="6" class="text-muted text-center">No payments recorded yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
