<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
$canEdit = can_edit_module('crm');
$canManage = can_manage_module('crm');

$customerId = (int)input('customer_id');
$stmt = db()->prepare('SELECT * FROM customers WHERE id = ?');
$stmt->execute([$customerId]);
$customer = $stmt->fetch();
if (!$customer) {
    flash('danger', 'Customer not found.');
    redirect('/crm/customers.php');
}

if (is_post() && input('action') === 'delete') {
    require_module_manage('crm');
    csrf_verify();
    db()->prepare('DELETE FROM customer_addresses WHERE id = ? AND customer_id = ?')->execute([(int)input('id'), $customerId]);
    flash('success', 'Address deleted.');
    redirect('/crm/customer_addresses.php?customer_id=' . $customerId);
}

$addresses = db()->prepare('SELECT * FROM customer_addresses WHERE customer_id = ? ORDER BY is_default DESC, label');
$addresses->execute([$customerId]);
$addresses = $addresses->fetchAll();

$page_title = 'Addresses — ' . $customer['name'];
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h5 class="mb-0"><?= e($customer['name']) ?> — Addresses</h5>
  <?php if ($canEdit): ?><a href="address_form.php?customer_id=<?= $customerId ?>" class="btn btn-brand"><i class="fa-solid fa-plus"></i> Add Address</a><?php endif; ?>
</div>
<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-hover">
      <thead><tr><th>Label</th><th>Address</th><th>Contact</th><th>Default</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($addresses as $a): ?>
        <tr>
          <td><?= e($a['label']) ?></td>
          <td><?= e($a['address_line']) ?><?= $a['city'] ? ', ' . e($a['city']) : '' ?><?= $a['state'] ? ', ' . e($a['state']) : '' ?><?= $a['pincode'] ? ' - ' . e($a['pincode']) : '' ?></td>
          <td><?= e($a['contact_person']) ?><?= $a['contact_phone'] ? ' · ' . e($a['contact_phone']) : '' ?></td>
          <td><?php if ($a['is_default']): ?><span class="badge text-bg-brand badge-status">Default</span><?php endif; ?></td>
          <td class="text-end">
            <?php if ($canEdit): ?><a href="address_form.php?id=<?= (int)$a['id'] ?>&customer_id=<?= $customerId ?>" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen"></i></a><?php endif; ?>
            <?php if ($canManage): ?>
            <form method="post" class="d-inline" data-confirm="Delete this address?">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
              <button class="btn btn-sm btn-outline-danger" type="submit"><i class="fa-solid fa-trash"></i></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$addresses): ?><tr><td colspan="5" class="text-muted text-center">No addresses yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<div class="mt-3"><a href="customer_form.php?id=<?= $customerId ?>" class="btn btn-outline-secondary btn-sm">Back to Customer</a></div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
