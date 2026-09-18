<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
$canEdit = can_edit_module('sales');

$userFilter = (int)input('user');
$userFilterName = null;
$sql = "SELECT dn.*, c.name customer_name, w.name warehouse_name FROM delivery_notes dn JOIN customers c ON c.id = dn.customer_id JOIN warehouses w ON w.id = dn.warehouse_id";
$params = [];
if ($userFilter) {
    $sql .= " WHERE dn.created_by = ?";
    $params[] = $userFilter;
    $stmt = db()->prepare('SELECT name FROM users WHERE id = ?');
    $stmt->execute([$userFilter]);
    $userFilterName = $stmt->fetchColumn();
}
$sql .= " ORDER BY dn.id DESC";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$notes = $stmt->fetchAll();

$badge = ['draft' => 'secondary', 'delivered' => 'success', 'cancelled' => 'danger'];

$page_title = 'Delivery Notes';
require __DIR__ . '/../includes/header.php';
?>
<?php if ($userFilter): ?>
  <div class="alert alert-info d-flex justify-content-between align-items-center">
    <span>Showing delivery notes created by <strong><?= e($userFilterName ?: 'Unknown user') ?></strong></span>
    <a href="delivery_notes.php" class="btn btn-sm btn-outline-secondary">Clear filter</a>
  </div>
<?php endif; ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <input type="text" class="form-control" style="max-width:280px" placeholder="Search delivery notes..." data-table-search="#dnTable">
  <?php if ($canEdit): ?><a href="delivery_note_form.php" class="btn btn-brand"><i class="fa-solid fa-plus"></i> New Delivery Note</a><?php endif; ?>
</div>
<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-hover" id="dnTable">
      <thead><tr><th>DN #</th><th>Customer</th><th>Warehouse</th><th>Date</th><th>Status</th><th class="text-end">Amount</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($notes as $n): ?>
        <tr>
          <td><?= e($n['dn_no']) ?></td>
          <td><?= e($n['customer_name']) ?></td>
          <td><?= e($n['warehouse_name']) ?></td>
          <td><?= e($n['posting_date']) ?></td>
          <td><span class="badge text-bg-<?= $badge[$n['status']] ?? 'secondary' ?> badge-status"><?= e($n['status']) ?></span></td>
          <td class="text-end"><?= money($n['total_amount']) ?></td>
          <td class="text-end">
            <a href="delivery_note_view.php?id=<?= (int)$n['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-eye"></i> View</a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$notes): ?><tr><td colspan="7" class="text-muted text-center">No delivery notes yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
