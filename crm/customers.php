<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
$canEdit = can_edit_module('crm');
$canManage = can_manage_module('crm');

if (is_post() && input('action') === 'delete') {
    require_module_manage('crm');
    csrf_verify();
    $id = (int)input('id');
    try {
        db()->prepare('DELETE FROM customers WHERE id = ?')->execute([$id]);
        flash('success', 'Customer deleted.');
    } catch (PDOException $e) {
        flash('danger', 'Cannot delete: this customer has existing sales orders or invoices.');
    }
    redirect('/crm/customers.php');
}

$customers = db()->query("
  SELECT c.*, (SELECT COUNT(*) FROM sales_orders so WHERE so.customer_id = c.id) order_count
  FROM customers c ORDER BY c.name
")->fetchAll();

$page_title = 'Customers';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <input type="text" class="form-control" style="max-width:280px" placeholder="Search customers..." data-table-search="#custTable">
  <?php if ($canEdit): ?><a href="customer_form.php" class="btn btn-brand"><i class="fa-solid fa-plus"></i> Add Customer</a><?php endif; ?>
</div>
<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-hover" id="custTable">
      <thead><tr><th>Name</th><th>Company</th><th>Email</th><th>Phone</th><th>Orders</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($customers as $c): ?>
        <tr>
          <td><?= e($c['name']) ?></td>
          <td><?= e($c['company']) ?></td>
          <td><?= e($c['email']) ?></td>
          <td><?= e($c['phone']) ?></td>
          <td><?= (int)$c['order_count'] ?></td>
          <td class="text-end">
            <a href="<?= base_url('print.php?doctype=customer&id=' . (int)$c['id']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="Print"><i class="fa-solid fa-print"></i></a>
            <?php if ($canEdit): ?><a href="customer_form.php?id=<?= (int)$c['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen"></i></a><?php endif; ?>
            <?php if ($canManage): ?>
            <form method="post" class="d-inline" data-confirm="Delete this customer?">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
              <button class="btn btn-sm btn-outline-danger" type="submit"><i class="fa-solid fa-trash"></i></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$customers): ?><tr><td colspan="6" class="text-muted text-center">No customers yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
