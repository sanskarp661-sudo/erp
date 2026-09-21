<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
$canEdit = can_edit_module('sales');

$userFilter = (int)input('user');
$userFilterName = null;
$sql = "SELECT so.*, c.name customer_name,
               dn.id dn_id, dn.dn_no, dn.status dn_status,
               inv.id invoice_id, inv.invoice_no, inv.status invoice_status
        FROM sales_orders so
        JOIN customers c ON c.id = so.customer_id
        LEFT JOIN delivery_notes dn ON dn.sales_order_id = so.id
        LEFT JOIN invoices inv ON inv.sales_order_id = so.id";
$params = [];
if ($userFilter) {
    $sql .= " WHERE so.created_by = ?";
    $params[] = $userFilter;
    $stmt = db()->prepare('SELECT name FROM users WHERE id = ?');
    $stmt->execute([$userFilter]);
    $userFilterName = $stmt->fetchColumn();
}
$sql .= " ORDER BY so.id DESC";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

$badge = ['pending' => 'secondary', 'confirmed' => 'info', 'shipped' => 'primary', 'completed' => 'success', 'cancelled' => 'danger'];
$dnBadge = ['draft' => 'secondary', 'delivered' => 'success', 'cancelled' => 'danger'];
$invBadge = ['unpaid' => 'secondary', 'partially_paid' => 'warning', 'paid' => 'success', 'overdue' => 'danger', 'cancelled' => 'dark'];

$page_title = 'Sales Orders';
require __DIR__ . '/../includes/header.php';
?>
<?php if ($userFilter): ?>
  <div class="alert alert-info d-flex justify-content-between align-items-center">
    <span>Showing orders created by <strong><?= e($userFilterName ?: 'Unknown user') ?></strong></span>
    <a href="orders.php" class="btn btn-sm btn-outline-secondary">Clear filter</a>
  </div>
<?php endif; ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <input type="text" class="form-control" style="max-width:280px" placeholder="Search orders..." data-table-search="#ordTable">
  <?php if ($canEdit): ?><a href="order_form.php" class="btn btn-brand"><i class="fa-solid fa-plus"></i> New Sales Order</a><?php endif; ?>
</div>
<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-hover" id="ordTable">
      <thead><tr><th>Order #</th><th>Customer</th><th>Date</th><th>Status</th><th>Delivery</th><th>Invoice</th><th class="text-end">Total</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($orders as $o): ?>
        <tr>
          <td><?= e($o['order_no']) ?></td>
          <td><?= e($o['customer_name']) ?></td>
          <td><?= e($o['order_date']) ?></td>
          <td><span class="badge text-bg-<?= $badge[$o['status']] ?? 'secondary' ?> badge-status"><?= e($o['status']) ?></span></td>
          <td>
            <?php if ($o['dn_id']): ?>
              <a href="<?= base_url('sales/delivery_note_view.php?id=' . (int)$o['dn_id']) ?>"><span class="badge text-bg-<?= $dnBadge[$o['dn_status']] ?? 'secondary' ?> badge-status"><?= e($o['dn_status']) ?></span></a>
            <?php else: ?>
              <span class="text-muted small">Not created</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($o['invoice_id']): ?>
              <a href="<?= base_url('accounting/invoice_view.php?id=' . (int)$o['invoice_id']) ?>"><span class="badge text-bg-<?= $invBadge[$o['invoice_status']] ?? 'secondary' ?> badge-status"><?= e(str_replace('_', ' ', $o['invoice_status'])) ?></span></a>
            <?php else: ?>
              <span class="text-muted small">Not created</span>
            <?php endif; ?>
          </td>
          <td class="text-end"><?= money($o['total_amount']) ?></td>
          <td class="text-end">
            <a href="order_view.php?id=<?= (int)$o['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-eye"></i> View</a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$orders): ?><tr><td colspan="8" class="text-muted text-center">No sales orders yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
