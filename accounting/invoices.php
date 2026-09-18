<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
$canEdit = can_edit_module('finance');

// Lazily flag invoices whose due date has passed as overdue.
db()->exec("UPDATE invoices SET status='overdue' WHERE due_date IS NOT NULL AND due_date < CURDATE() AND status IN ('unpaid','partially_paid')");

$userFilter = (int)input('user');
$userFilterName = null;
$sql = "SELECT i.*, c.name customer_name FROM invoices i JOIN customers c ON c.id = i.customer_id";
$params = [];
if ($userFilter) {
    $sql .= " WHERE i.created_by = ?";
    $params[] = $userFilter;
    $stmt = db()->prepare('SELECT name FROM users WHERE id = ?');
    $stmt->execute([$userFilter]);
    $userFilterName = $stmt->fetchColumn();
}
$sql .= " ORDER BY i.id DESC";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$invoices = $stmt->fetchAll();

$badge = ['unpaid' => 'secondary', 'partially_paid' => 'warning', 'paid' => 'success', 'overdue' => 'danger', 'cancelled' => 'dark'];

$page_title = 'Invoices';
require __DIR__ . '/../includes/header.php';
?>
<?php if ($userFilter): ?>
  <div class="alert alert-info d-flex justify-content-between align-items-center">
    <span>Showing invoices created by <strong><?= e($userFilterName ?: 'Unknown user') ?></strong></span>
    <a href="invoices.php" class="btn btn-sm btn-outline-secondary">Clear filter</a>
  </div>
<?php endif; ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <input type="text" class="form-control" style="max-width:280px" placeholder="Search invoices..." data-table-search="#invTable">
  <?php if ($canEdit): ?><a href="invoice_form.php" class="btn btn-brand"><i class="fa-solid fa-plus"></i> New Invoice</a><?php endif; ?>
</div>
<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-hover" id="invTable">
      <thead><tr><th>Invoice #</th><th>Customer</th><th>Date</th><th>Due</th><th>Status</th><th class="text-end">Total</th><th class="text-end">Balance</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($invoices as $i): ?>
        <tr>
          <td><?= e($i['invoice_no']) ?></td>
          <td><?= e($i['customer_name']) ?></td>
          <td><?= e($i['invoice_date']) ?></td>
          <td><?= e($i['due_date']) ?></td>
          <td><span class="badge text-bg-<?= $badge[$i['status']] ?? 'secondary' ?> badge-status"><?= e(str_replace('_', ' ', $i['status'])) ?></span></td>
          <td class="text-end"><?= money($i['total']) ?></td>
          <td class="text-end"><?= money($i['total'] - $i['amount_paid']) ?></td>
          <td class="text-end">
            <a href="invoice_view.php?id=<?= (int)$i['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-eye"></i> View</a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$invoices): ?><tr><td colspan="8" class="text-muted text-center">No invoices yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
