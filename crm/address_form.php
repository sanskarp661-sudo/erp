<?php
require_once __DIR__ . '/../includes/auth.php';
require_module_edit('crm');

$id = (int)input('id');
$customerId = (int)input('customer_id');
$returnTo = input('return_to');

$address = ['id' => 0, 'customer_id' => $customerId, 'label' => '', 'address_line' => '', 'city' => '', 'state' => '', 'pincode' => '', 'country' => 'India', 'contact_person' => '', 'contact_phone' => '', 'contact_email' => '', 'is_default' => 0];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM customer_addresses WHERE id = ?');
    $stmt->execute([$id]);
    $address = $stmt->fetch() ?: $address;
    $customerId = (int)$address['customer_id'];
}

$stmt = db()->prepare('SELECT * FROM customers WHERE id = ?');
$stmt->execute([$customerId]);
$customer = $stmt->fetch();
if (!$customer) {
    flash('danger', 'Customer not found.');
    redirect('/crm/customers.php');
}

$error = '';

if (is_post()) {
    csrf_verify();
    $address = [
        'id' => $id,
        'customer_id' => $customerId,
        'label' => input('label') ?: 'Address',
        'address_line' => input('address_line'),
        'city' => input('city'),
        'state' => input('state'),
        'pincode' => input('pincode'),
        'country' => input('country') ?: 'India',
        'contact_person' => input('contact_person'),
        'contact_phone' => input('contact_phone'),
        'contact_email' => input('contact_email'),
        'is_default' => input('is_default') ? 1 : 0,
    ];

    if ($address['address_line'] === '') {
        $error = 'Address line is required.';
    } else {
        $pdo = db();
        $pdo->beginTransaction();
        if ($address['is_default']) {
            $pdo->prepare('UPDATE customer_addresses SET is_default = 0 WHERE customer_id = ?')->execute([$customerId]);
        }
        if ($id) {
            $pdo->prepare('UPDATE customer_addresses SET label=?, address_line=?, city=?, state=?, pincode=?, country=?, contact_person=?, contact_phone=?, contact_email=?, is_default=? WHERE id=?')
                ->execute([$address['label'], $address['address_line'], $address['city'], $address['state'], $address['pincode'], $address['country'], $address['contact_person'], $address['contact_phone'], $address['contact_email'], $address['is_default'], $id]);
            $newId = $id;
        } else {
            $pdo->prepare('INSERT INTO customer_addresses (customer_id, label, address_line, city, state, pincode, country, contact_person, contact_phone, contact_email, is_default) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$customerId, $address['label'], $address['address_line'], $address['city'], $address['state'], $address['pincode'], $address['country'], $address['contact_person'], $address['contact_phone'], $address['contact_email'], $address['is_default']]);
            $newId = (int)$pdo->lastInsertId();
        }
        $pdo->commit();
        flash('success', 'Address saved.');
        if ($returnTo) {
            $sep = strpos($returnTo, '?') !== false ? '&' : '?';
            redirect($returnTo . $sep . 'new_address_id=' . $newId);
        }
        redirect('/crm/customer_addresses.php?customer_id=' . $customerId);
    }
}

$page_title = ($id ? 'Edit Address' : 'Add Address') . ' — ' . $customer['name'];
require __DIR__ . '/../includes/header.php';
?>
<div class="card p-4" style="max-width:640px">
  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="customer_id" value="<?= $customerId ?>">
    <?php if ($returnTo): ?><input type="hidden" name="return_to" value="<?= e($returnTo) ?>"><?php endif; ?>
    <div class="row g-3">
      <div class="col-sm-6">
        <label class="form-label">Label</label>
        <input type="text" name="label" class="form-control" placeholder="e.g. Billing, Shipping, HQ" value="<?= e($address['label']) ?>">
      </div>
      <div class="col-sm-6 d-flex align-items-end">
        <div class="form-check">
          <input type="checkbox" name="is_default" id="isDefault" class="form-check-input" value="1" <?= $address['is_default'] ? 'checked' : '' ?>>
          <label class="form-check-label" for="isDefault">Default address for this customer</label>
        </div>
      </div>
      <div class="col-12">
        <label class="form-label">Address Line</label>
        <textarea name="address_line" class="form-control" rows="2" required><?= e($address['address_line']) ?></textarea>
      </div>
      <div class="col-sm-4">
        <label class="form-label">City</label>
        <input type="text" name="city" class="form-control" value="<?= e($address['city']) ?>">
      </div>
      <div class="col-sm-4">
        <label class="form-label">State</label>
        <input type="text" name="state" class="form-control" value="<?= e($address['state']) ?>">
      </div>
      <div class="col-sm-4">
        <label class="form-label">Pincode</label>
        <input type="text" name="pincode" class="form-control" value="<?= e($address['pincode']) ?>">
      </div>
      <div class="col-sm-6">
        <label class="form-label">Contact Person</label>
        <input type="text" name="contact_person" class="form-control" value="<?= e($address['contact_person']) ?>">
      </div>
      <div class="col-sm-3">
        <label class="form-label">Contact Phone</label>
        <input type="text" name="contact_phone" class="form-control" value="<?= e($address['contact_phone']) ?>">
      </div>
      <div class="col-sm-3">
        <label class="form-label">Contact Email</label>
        <input type="email" name="contact_email" class="form-control" value="<?= e($address['contact_email']) ?>">
      </div>
    </div>
    <div class="page-actions mt-4">
      <button type="submit" class="btn btn-brand">Save</button>
      <a href="<?= e($returnTo ?: ('customer_addresses.php?customer_id=' . $customerId)) ?>" class="btn btn-outline-secondary">Cancel</a>
    </div>
  </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
