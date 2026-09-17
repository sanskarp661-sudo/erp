<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$orders = db()->query("
  SELECT so.*, c.name customer_name
  FROM sales_orders so JOIN customers c ON c.id = so.customer_id
  ORDER BY so.id DESC
")->fetchAll();

$badge = ['pending' => 'secondary', 'confirmed' => 'info', 'shipped' => 'primary', 'completed' => 'success', 'cancelled' => 'danger'];

$page_title = 'Sales Orders';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <input type="text" class="form-control" style="max-width:280px" placeholder="Search orders..." data-table-search="#ordTable">
  <a href="order_form.php" class="btn btn-brand"><i class="fa-solid fa-plus"></i> New Sales Order</a>
</div>
<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-hover" id="ordTable">
      <thead><tr><th>Order #</th><th>Customer</th><th>Date</th><th>Status</th><th class="text-end">Total</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($orders as $o): ?>
        <tr>
          <td><?= e($o['order_no']) ?></td>
          <td><?= e($o['customer_name']) ?></td>
          <td><?= e($o['order_date']) ?></td>
          <td><span class="badge text-bg-<?= $badge[$o['status']] ?? 'secondary' ?> badge-status"><?= e($o['status']) ?></span></td>
          <td class="text-end"><?= money($o['total_amount']) ?></td>
          <td class="text-end">
            <a href="order_view.php?id=<?= (int)$o['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-eye"></i> View</a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$orders): ?><tr><td colspan="6" class="text-muted text-center">No sales orders yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
