<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
$canEdit = can_edit_module('procurement');

$userFilter = (int)input('user');
$userFilterName = null;
$sql = "SELECT g.*, v.name vendor_name, w.name warehouse_name FROM goods_receipts g JOIN vendors v ON v.id = g.vendor_id JOIN warehouses w ON w.id = g.warehouse_id";
$params = [];
if ($userFilter) {
    $sql .= " WHERE g.created_by = ?";
    $params[] = $userFilter;
    $stmt = db()->prepare('SELECT name FROM users WHERE id = ?');
    $stmt->execute([$userFilter]);
    $userFilterName = $stmt->fetchColumn();
}
$sql .= " ORDER BY g.id DESC";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$grns = $stmt->fetchAll();

$badge = ['draft' => 'secondary', 'received' => 'success', 'cancelled' => 'danger'];

$page_title = 'Goods Receipts';
require __DIR__ . '/../includes/header.php';
?>
<?php if ($userFilter): ?>
  <div class="alert alert-info d-flex justify-content-between align-items-center">
    <span>Showing goods receipts created by <strong><?= e($userFilterName ?: 'Unknown user') ?></strong></span>
    <a href="grns.php" class="btn btn-sm btn-outline-secondary">Clear filter</a>
  </div>
<?php endif; ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <input type="text" class="form-control" style="max-width:280px" placeholder="Search goods receipts..." data-table-search="#grnTable">
  <?php if ($canEdit): ?><a href="grn_form.php" class="btn btn-brand"><i class="fa-solid fa-plus"></i> New Goods Receipt</a><?php endif; ?>
</div>
<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-hover" id="grnTable">
      <thead><tr><th>GRN #</th><th>Vendor</th><th>Warehouse</th><th>Date</th><th>Status</th><th class="text-end">Amount</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($grns as $g): ?>
        <tr>
          <td><?= e($g['grn_no']) ?></td>
          <td><?= e($g['vendor_name']) ?></td>
          <td><?= e($g['warehouse_name']) ?></td>
          <td><?= e($g['posting_date']) ?></td>
          <td><span class="badge text-bg-<?= $badge[$g['status']] ?? 'secondary' ?> badge-status"><?= e($g['status']) ?></span></td>
          <td class="text-end"><?= money($g['total_amount']) ?></td>
          <td class="text-end">
            <a href="grn_view.php?id=<?= (int)$g['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-eye"></i> View</a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$grns): ?><tr><td colspan="7" class="text-muted text-center">No goods receipts yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
