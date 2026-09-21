<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
$canEdit = can_edit_module('sales');

$quotations = db()->query('
  SELECT q.*, c.name customer_name,
         (SELECT id FROM sales_orders so WHERE so.quotation_id = q.id LIMIT 1) so_id,
         (SELECT order_no FROM sales_orders so WHERE so.quotation_id = q.id LIMIT 1) so_no
  FROM quotations q JOIN customers c ON c.id = q.customer_id ORDER BY q.id DESC
')->fetchAll();

$badge = ['draft' => 'secondary', 'sent' => 'info', 'accepted' => 'success', 'rejected' => 'danger', 'expired' => 'dark'];

$page_title = 'Quotations';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <input type="text" class="form-control" style="max-width:280px" placeholder="Search quotations..." data-table-search="#qTable">
  <?php if ($canEdit): ?><a href="quotation_form.php" class="btn btn-brand"><i class="fa-solid fa-plus"></i> New Quotation</a><?php endif; ?>
</div>
<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-hover" id="qTable">
      <thead><tr><th>Quotation #</th><th>Customer</th><th>Date</th><th>Valid Till</th><th>Status</th><th>Sales Order</th><th class="text-end">Total</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($quotations as $q): ?>
        <tr>
          <td><?= e($q['quotation_no']) ?></td>
          <td><?= e($q['customer_name']) ?></td>
          <td><?= e($q['quotation_date']) ?></td>
          <td><?= e($q['valid_till'] ?: '—') ?></td>
          <td><span class="badge text-bg-<?= $badge[$q['status']] ?? 'secondary' ?> badge-status"><?= e($q['status']) ?></span></td>
          <td>
            <?php if ($q['so_id']): ?>
              <a href="<?= base_url('sales/order_view.php?id=' . (int)$q['so_id']) ?>"><?= e($q['so_no']) ?></a>
            <?php else: ?>
              <span class="text-muted small">Not converted</span>
            <?php endif; ?>
          </td>
          <td class="text-end"><?= money($q['total_amount']) ?></td>
          <td class="text-end">
            <a href="quotation_view.php?id=<?= (int)$q['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-eye"></i> View</a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$quotations): ?><tr><td colspan="8" class="text-muted text-center">No quotations yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
