<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
$canEdit = can_edit_module('procurement');

$userFilter = (int)input('user');
$userFilterName = null;
$sql = "SELECT pr.*, v.name vendor_name, w.name warehouse_name, po.po_no FROM purchase_returns pr JOIN vendors v ON v.id = pr.vendor_id JOIN warehouses w ON w.id = pr.warehouse_id JOIN purchase_orders po ON po.id = pr.purchase_order_id";
$params = [];
if ($userFilter) {
    $sql .= " WHERE pr.created_by = ?";
    $params[] = $userFilter;
    $stmt = db()->prepare('SELECT name FROM users WHERE id = ?');
    $stmt->execute([$userFilter]);
    $userFilterName = $stmt->fetchColumn();
}
$sql .= " ORDER BY pr.id DESC";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$returns = $stmt->fetchAll();

$badge = ['draft' => 'secondary', 'completed' => 'success', 'cancelled' => 'danger'];

$page_title = 'Purchase Returns';
require __DIR__ . '/../includes/header.php';
?>
<?php if ($userFilter): ?>
  <div class="alert alert-info d-flex justify-content-between align-items-center">
    <span>Showing purchase returns created by <strong><?= e($userFilterName ?: 'Unknown user') ?></strong></span>
    <a href="returns.php" class="btn btn-sm btn-outline-secondary">Clear filter</a>
  </div>
<?php endif; ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <input type="text" class="form-control" style="max-width:280px" placeholder="Search purchase returns..." data-table-search="#prTable">
</div>
<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-hover" id="prTable">
      <thead><tr><th>Return #</th><th>Vendor</th><th>Purchase Order</th><th>Warehouse</th><th>Date</th><th>Status</th><th class="text-end">Amount</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($returns as $r): ?>
        <tr>
          <td><?= e($r['return_no']) ?></td>
          <td><?= e($r['vendor_name']) ?></td>
          <td><a href="order_view.php?id=<?= (int)$r['purchase_order_id'] ?>"><?= e($r['po_no']) ?></a></td>
          <td><?= e($r['warehouse_name']) ?></td>
          <td><?= e($r['return_date']) ?></td>
          <td><span class="badge text-bg-<?= $badge[$r['status']] ?? 'secondary' ?> badge-status"><?= e($r['status']) ?></span></td>
          <td class="text-end"><?= money($r['total_amount']) ?></td>
          <td class="text-end">
            <a href="return_view.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-eye"></i> View</a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$returns): ?><tr><td colspan="8" class="text-muted text-center">No purchase returns yet. Create one from a received Purchase Order.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
