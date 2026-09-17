<?php
require_once __DIR__ . '/../includes/auth.php';
require_role(['admin']);

$id = (int)input('id');
// Named $editUser (not $user) because includes/header.php sets $user = current_user()
// for the logged-in viewer — reusing $user here would get clobbered on include.
$editUser = ['id' => 0, 'name' => '', 'email' => '', 'role' => 'staff', 'status' => 'active'];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $editUser = $stmt->fetch() ?: $editUser;
}

$error = '';

if (is_post()) {
    csrf_verify();
    $name = input('name');
    $email = input('email');
    $role = in_array(input('role'), ['admin', 'manager', 'staff'], true) ? input('role') : 'staff';
    $status = in_array(input('status'), ['active', 'inactive'], true) ? input('status') : 'active';
    $password = input('password');

    if ($id === current_user()['id'] && ($role !== 'admin' || $status !== 'active')) {
        $error = 'You cannot change your own role or deactivate your own account.';
    } elseif ($name === '' || $email === '') {
        $error = 'Name and email are required.';
    } elseif (!$id && $password === '') {
        $error = 'Password is required for a new user.';
    } else {
        try {
            if ($id) {
                if ($password !== '') {
                    $stmt = db()->prepare('UPDATE users SET name=?, email=?, role=?, status=?, password_hash=? WHERE id=?');
                    $stmt->execute([$name, $email, $role, $status, password_hash($password, PASSWORD_BCRYPT), $id]);
                } else {
                    $stmt = db()->prepare('UPDATE users SET name=?, email=?, role=?, status=? WHERE id=?');
                    $stmt->execute([$name, $email, $role, $status, $id]);
                }
                flash('success', 'User updated.');
            } else {
                $stmt = db()->prepare('INSERT INTO users (name, email, password_hash, role, status) VALUES (?,?,?,?,?)');
                $stmt->execute([$name, $email, password_hash($password, PASSWORD_BCRYPT), $role, $status]);
                flash('success', 'User created.');
            }
            redirect('/users/users.php');
        } catch (PDOException $e) {
            $error = str_contains($e->getMessage(), 'Duplicate') ? 'A user with this email already exists.' : 'Could not save user.';
        }
    }
    $editUser = ['id' => $id, 'name' => $name, 'email' => $email, 'role' => $role, 'status' => $status];
}

$page_title = $id ? 'Edit User' : 'Add User';
require __DIR__ . '/../includes/header.php';
?>
<div class="card p-4" style="max-width:520px">
  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <div class="mb-3">
      <label class="form-label">Name</label>
      <input type="text" name="name" class="form-control" required value="<?= e($editUser['name']) ?>">
    </div>
    <div class="mb-3">
      <label class="form-label">Email</label>
      <input type="email" name="email" class="form-control" required value="<?= e($editUser['email']) ?>">
    </div>
    <div class="mb-3">
      <label class="form-label">Password <?= $id ? '(leave blank to keep current)' : '' ?></label>
      <input type="password" name="password" class="form-control" <?= $id ? '' : 'required' ?>>
    </div>
    <div class="row g-3">
      <div class="col-sm-6">
        <label class="form-label">Role</label>
        <select name="role" class="form-select" <?= $id === current_user()['id'] ? 'disabled' : '' ?>>
          <option value="admin" <?= $editUser['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
          <option value="manager" <?= $editUser['role'] === 'manager' ? 'selected' : '' ?>>Manager</option>
          <option value="staff" <?= $editUser['role'] === 'staff' ? 'selected' : '' ?>>Staff</option>
        </select>
        <?php if ($id === current_user()['id']): ?><input type="hidden" name="role" value="<?= e($editUser['role']) ?>"><?php endif; ?>
      </div>
      <div class="col-sm-6">
        <label class="form-label">Status</label>
        <select name="status" class="form-select" <?= $id === current_user()['id'] ? 'disabled' : '' ?>>
          <option value="active" <?= $editUser['status'] === 'active' ? 'selected' : '' ?>>Active</option>
          <option value="inactive" <?= $editUser['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>
        <?php if ($id === current_user()['id']): ?><input type="hidden" name="status" value="<?= e($editUser['status']) ?>"><?php endif; ?>
      </div>
    </div>
    <div class="page-actions mt-4">
      <button type="submit" class="btn btn-brand">Save</button>
      <a href="users.php" class="btn btn-outline-secondary">Cancel</a>
    </div>
  </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
