<?php
require_once __DIR__ . '/../includes/auth.php';
require_module_edit('procurement');

$id = (int)input('id');
$vendor = ['id' => 0, 'name' => '', 'company' => '', 'email' => '', 'phone' => '', 'address' => ''];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM vendors WHERE id = ?');
    $stmt->execute([$id]);
    $vendor = $stmt->fetch() ?: $vendor;
}

$error = '';

if (is_post()) {
    csrf_verify();
    $vendor = [
        'id' => $id,
        'name' => input('name'),
        'company' => input('company'),
        'email' => input('email'),
        'phone' => input('phone'),
        'address' => input('address'),
    ];

    if ($vendor['name'] === '') {
        $error = 'Name is required.';
    } else {
        if ($id) {
            $stmt = db()->prepare('UPDATE vendors SET name=?, company=?, email=?, phone=?, address=? WHERE id=?');
            $stmt->execute([$vendor['name'], $vendor['company'], $vendor['email'], $vendor['phone'], $vendor['address'], $id]);
            flash('success', 'Vendor updated.');
        } else {
            $stmt = db()->prepare('INSERT INTO vendors (name, company, email, phone, address) VALUES (?,?,?,?,?)');
            $stmt->execute([$vendor['name'], $vendor['company'], $vendor['email'], $vendor['phone'], $vendor['address']]);
            flash('success', 'Vendor created.');
        }
        redirect('/purchases/vendors.php');
    }
}

$page_title = $id ? 'Edit Vendor' : 'Add Vendor';
require __DIR__ . '/../includes/header.php';
?>
<div class="card p-4" style="max-width:600px">
  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <div class="row g-3">
      <div class="col-sm-6">
        <label class="form-label">Name</label>
        <input type="text" name="name" class="form-control" required value="<?= e($vendor['name']) ?>">
      </div>
      <div class="col-sm-6">
        <label class="form-label">Company</label>
        <input type="text" name="company" class="form-control" value="<?= e($vendor['company']) ?>">
      </div>
      <div class="col-sm-6">
        <label class="form-label">Email</label>
        <input type="email" name="email" class="form-control" value="<?= e($vendor['email']) ?>">
      </div>
      <div class="col-sm-6">
        <label class="form-label">Phone</label>
        <input type="text" name="phone" class="form-control" value="<?= e($vendor['phone']) ?>">
      </div>
      <div class="col-12">
        <label class="form-label">Address</label>
        <textarea name="address" class="form-control" rows="2"><?= e($vendor['address']) ?></textarea>
      </div>
    </div>
    <div class="page-actions mt-4">
      <button type="submit" class="btn btn-brand">Save</button>
      <a href="vendors.php" class="btn btn-outline-secondary">Cancel</a>
    </div>
  </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
