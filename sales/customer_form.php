<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$id = (int)input('id');
$customer = ['id' => 0, 'name' => '', 'company' => '', 'email' => '', 'phone' => '', 'address' => ''];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM customers WHERE id = ?');
    $stmt->execute([$id]);
    $customer = $stmt->fetch() ?: $customer;
}

$error = '';

if (is_post()) {
    csrf_verify();
    $customer = [
        'id' => $id,
        'name' => input('name'),
        'company' => input('company'),
        'email' => input('email'),
        'phone' => input('phone'),
        'address' => input('address'),
    ];

    if ($customer['name'] === '') {
        $error = 'Name is required.';
    } else {
        if ($id) {
            $stmt = db()->prepare('UPDATE customers SET name=?, company=?, email=?, phone=?, address=? WHERE id=?');
            $stmt->execute([$customer['name'], $customer['company'], $customer['email'], $customer['phone'], $customer['address'], $id]);
            flash('success', 'Customer updated.');
        } else {
            $stmt = db()->prepare('INSERT INTO customers (name, company, email, phone, address) VALUES (?,?,?,?,?)');
            $stmt->execute([$customer['name'], $customer['company'], $customer['email'], $customer['phone'], $customer['address']]);
            flash('success', 'Customer created.');
        }
        redirect('/sales/customers.php');
    }
}

$page_title = $id ? 'Edit Customer' : 'Add Customer';
require __DIR__ . '/../includes/header.php';
?>
<div class="card p-4" style="max-width:600px">
  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <div class="row g-3">
      <div class="col-sm-6">
        <label class="form-label">Name</label>
        <input type="text" name="name" class="form-control" required value="<?= e($customer['name']) ?>">
      </div>
      <div class="col-sm-6">
        <label class="form-label">Company</label>
        <input type="text" name="company" class="form-control" value="<?= e($customer['company']) ?>">
      </div>
      <div class="col-sm-6">
        <label class="form-label">Email</label>
        <input type="email" name="email" class="form-control" value="<?= e($customer['email']) ?>">
      </div>
      <div class="col-sm-6">
        <label class="form-label">Phone</label>
        <input type="text" name="phone" class="form-control" value="<?= e($customer['phone']) ?>">
      </div>
      <div class="col-12">
        <label class="form-label">Address</label>
        <textarea name="address" class="form-control" rows="2"><?= e($customer['address']) ?></textarea>
      </div>
    </div>
    <div class="page-actions mt-4">
      <button type="submit" class="btn btn-brand">Save</button>
      <a href="customers.php" class="btn btn-outline-secondary">Cancel</a>
    </div>
  </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
