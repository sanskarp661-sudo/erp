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

$statusFilter = in_array(input('status'), ['active', 'inactive', 'blocked'], true) ? input('status') : '';
$stmt = db()->prepare("
  SELECT c.*, (SELECT COUNT(*) FROM sales_orders so WHERE so.customer_id = c.id) order_count
  FROM customers c" . ($statusFilter ? ' WHERE c.status = ?' : '') . " ORDER BY c.name
");
$stmt->execute($statusFilter ? [$statusFilter] : []);
$customers = $stmt->fetchAll();
$exposures = customer_credit_exposures();
$statusBadge = ['active' => 'success', 'inactive' => 'secondary', 'blocked' => 'danger'];

$page_title = 'Customers';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div class="d-flex gap-2">
    <input type="text" class="form-control" style="max-width:280px" placeholder="Search customers..." data-table-search="#custTable">
    <form method="get">
      <select name="status" class="form-select" style="min-width:150px" onchange="this.form.submit()">
        <option value="">All statuses</option>
        <?php foreach (['active' => 'Active', 'inactive' => 'Inactive', 'blocked' => 'Blocked'] as $val => $label): ?>
          <option value="<?= $val ?>" <?= $statusFilter === $val ? 'selected' : '' ?>><?= $label ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>
  <?php if ($canEdit): ?><a href="customer_form.php" class="btn btn-brand"><i class="fa-solid fa-plus"></i> Add Customer</a><?php endif; ?>
</div>
<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-hover" id="custTable">
      <thead><tr><th>Code</th><th>Name</th><th>Group</th><th>Territory</th><th>Email</th><th>Phone</th><th>Orders</th><th class="text-end">Outstanding</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($customers as $c): ?>
        <tr>
          <td class="text-muted"><?= e($c['customer_code']) ?></td>
          <td>
            <?php if ($canEdit): ?><a href="customer_form.php?id=<?= (int)$c['id'] ?>"><?= e($c['name']) ?></a><?php else: ?><?= e($c['name']) ?><?php endif; ?>
            <?php if ($c['company'] && $c['company'] !== $c['name']): ?><div class="small text-muted"><?= e($c['company']) ?></div><?php endif; ?>
          </td>
          <td><?= e($c['customer_group']) ?></td>
          <td><?= e($c['territory']) ?></td>
          <td><?= e($c['email']) ?></td>
          <td><?= e($c['phone'] ?: $c['mobile']) ?></td>
          <td><?= (int)$c['order_count'] ?></td>
          <td class="text-end"><?= money($exposures[(int)$c['id']]['outstanding'] ?? 0) ?></td>
          <td>
            <span class="badge text-bg-<?= $statusBadge[$c['status']] ?? 'secondary' ?>"><?= e(ucfirst($c['status'])) ?></span>
            <?php if ($c['credit_hold']): ?><span class="badge text-bg-warning">Credit Hold</span><?php endif; ?>
          </td>
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
      <?php if (!$customers): ?><tr><td colspan="10" class="text-muted text-center">No customers yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
