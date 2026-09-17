<?php
require_once __DIR__ . '/../includes/auth.php';
require_role(['admin']);

if (is_post() && input('action') === 'delete') {
    csrf_verify();
    $id = (int)input('id');
    if ($id === current_user()['id']) {
        flash('danger', 'You cannot delete your own account.');
    } else {
        $adminCount = (int)db()->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
        $stmt = db()->prepare('SELECT role FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $target = $stmt->fetch();
        if ($target && $target['role'] === 'admin' && $adminCount <= 1) {
            flash('danger', 'Cannot delete the last remaining admin account.');
        } else {
            db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            flash('success', 'User deleted.');
        }
    }
    redirect('/users/users.php');
}

$users = db()->query('SELECT * FROM users ORDER BY name')->fetchAll();

$page_title = 'Users';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <input type="text" class="form-control" style="max-width:280px" placeholder="Search users..." data-table-search="#userTable">
  <a href="user_form.php" class="btn btn-brand"><i class="fa-solid fa-plus"></i> Add User</a>
</div>
<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-hover" id="userTable">
      <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <tr>
          <td><?= e($u['name']) ?></td>
          <td><?= e($u['email']) ?></td>
          <td><span class="badge text-bg-light text-capitalize"><?= e($u['role']) ?></span></td>
          <td><span class="badge text-bg-<?= $u['status'] === 'active' ? 'success' : 'secondary' ?> badge-status"><?= e($u['status']) ?></span></td>
          <td class="text-end">
            <a href="user_form.php?id=<?= (int)$u['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen"></i></a>
            <?php if ($u['id'] !== current_user()['id']): ?>
            <form method="post" class="d-inline" data-confirm="Delete this user?">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
              <button class="btn btn-sm btn-outline-danger" type="submit"><i class="fa-solid fa-trash"></i></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
