<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$orders = db()->query("
  SELECT po.*, v.name vendor_name
  FROM purchase_orders po JOIN vendors v ON v.id = po.vendor_id
  ORDER BY po.id DESC
")->fetchAll();

$badge = ['pending' => 'secondary', 'ordered' => 'info', 'received' => 'success', 'cancelled' => 'danger'];

$page_title = 'Purchase Orders';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <input type="text" class="form-control" style="max-width:280px" placeholder="Search purchase orders..." data-table-search="#poTable">
  <a href="order_form.php" class="btn btn-brand"><i class="fa-solid fa-plus"></i> New Purchase Order</a>
</div>
<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-hover" id="poTable">
      <thead><tr><th>PO #</th><th>Vendor</th><th>Date</th><th>Status</th><th class="text-end">Total</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($orders as $o): ?>
        <tr>
          <td><?= e($o['po_no']) ?></td>
          <td><?= e($o['vendor_name']) ?></td>
          <td><?= e($o['order_date']) ?></td>
          <td><span class="badge text-bg-<?= $badge[$o['status']] ?? 'secondary' ?> badge-status"><?= e($o['status']) ?></span></td>
          <td class="text-end"><?= money($o['total_amount']) ?></td>
          <td class="text-end">
            <a href="order_view.php?id=<?= (int)$o['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-eye"></i> View</a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$orders): ?><tr><td colspan="6" class="text-muted text-center">No purchase orders yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
