<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
$canEdit = can_edit_module('sales');

$userFilter = (int)input('user');
$userFilterName = null;
$sql = "SELECT sr.*, c.name customer_name, w.name warehouse_name, so.order_no FROM sales_returns sr JOIN customers c ON c.id = sr.customer_id JOIN warehouses w ON w.id = sr.warehouse_id JOIN sales_orders so ON so.id = sr.sales_order_id";
$params = [];
if ($userFilter) {
    $sql .= " WHERE sr.created_by = ?";
    $params[] = $userFilter;
    $stmt = db()->prepare('SELECT name FROM users WHERE id = ?');
    $stmt->execute([$userFilter]);
    $userFilterName = $stmt->fetchColumn();
}
$sql .= " ORDER BY sr.id DESC";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$returns = $stmt->fetchAll();

$badge = ['draft' => 'secondary', 'completed' => 'success', 'cancelled' => 'danger'];

$page_title = 'Sales Returns';
require __DIR__ . '/../includes/header.php';
?>
<?php if ($userFilter): ?>
  <div class="alert alert-info d-flex justify-content-between align-items-center">
    <span>Showing sales returns created by <strong><?= e($userFilterName ?: 'Unknown user') ?></strong></span>
    <a href="returns.php" class="btn btn-sm btn-outline-secondary">Clear filter</a>
  </div>
<?php endif; ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <input type="text" class="form-control" style="max-width:280px" placeholder="Search sales returns..." data-table-search="#srTable">
</div>
<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-hover" id="srTable">
      <thead><tr><th>Return #</th><th>Customer</th><th>Sales Order</th><th>Warehouse</th><th>Date</th><th>Status</th><th class="text-end">Amount</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($returns as $r): ?>
        <tr>
          <td><?= e($r['return_no']) ?></td>
          <td><?= e($r['customer_name']) ?></td>
          <td><a href="order_view.php?id=<?= (int)$r['sales_order_id'] ?>"><?= e($r['order_no']) ?></a></td>
          <td><?= e($r['warehouse_name']) ?></td>
          <td><?= e($r['return_date']) ?></td>
          <td><span class="badge text-bg-<?= $badge[$r['status']] ?? 'secondary' ?> badge-status"><?= e($r['status']) ?></span></td>
          <td class="text-end"><?= money($r['total_amount']) ?></td>
          <td class="text-end">
            <a href="return_view.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-eye"></i> View</a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$returns): ?><tr><td colspan="8" class="text-muted text-center">No sales returns yet. Create one from a delivered Sales Order.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
