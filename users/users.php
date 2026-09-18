<?php
require_once __DIR__ . '/../includes/auth.php';
require_admin_section();

if (is_post() && input('action') === 'delete') {
    require_manage_users();
    csrf_verify();
    $id = (int)input('id');
    if ($id === current_user()['id']) {
        flash('danger', 'You cannot delete your own account.');
    } else {
        $systemAdminCount = (int)db()->query("SELECT COUNT(DISTINCT user_id) FROM user_roles WHERE role_key = 'system_admin'")->fetchColumn();
        $stmt = db()->prepare("SELECT 1 FROM user_roles WHERE user_id = ? AND role_key = 'system_admin'");
        $stmt->execute([$id]);
        $targetIsSystemAdmin = (bool)$stmt->fetchColumn();
        if ($targetIsSystemAdmin && $systemAdminCount <= 1) {
            flash('danger', 'Cannot delete the last remaining System Admin account.');
        } else {
            db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            flash('success', 'User deleted.');
        }
    }
    redirect('/users/users.php');
}

$users = db()->query('SELECT * FROM users ORDER BY name')->fetchAll();
$userRoles = [];
foreach (db()->query('SELECT user_id, role_key FROM user_roles') as $row) {
    $userRoles[$row['user_id']][] = $row['role_key'];
}
$canManage = can_manage_users();

$page_title = 'Users';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <input type="text" class="form-control" style="max-width:280px" placeholder="Search users..." data-table-search="#userTable">
  <?php if ($canManage): ?>
    <a href="user_form.php" class="btn btn-brand"><i class="fa-solid fa-plus"></i> Add User</a>
  <?php endif; ?>
</div>
<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-hover" id="userTable">
      <thead><tr><th>Name</th><th>Email</th><th>Roles</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <tr>
          <td><?= e($u['name']) ?></td>
          <td><?= e($u['email']) ?></td>
          <td>
            <?php foreach ($userRoles[$u['id']] ?? [] as $rk): ?>
              <span class="badge text-bg-light"><?= e(role_label($rk)) ?></span>
            <?php endforeach; ?>
            <?php if (empty($userRoles[$u['id']])): ?><span class="text-muted small">No roles</span><?php endif; ?>
          </td>
          <td><span class="badge text-bg-<?= $u['status'] === 'active' ? 'success' : 'secondary' ?> badge-status"><?= e($u['status']) ?></span></td>
          <td class="text-end">
            <a href="user_form.php?id=<?= (int)$u['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-<?= $canManage ? 'pen' : 'eye' ?>"></i></a>
            <?php if ($canManage && $u['id'] !== current_user()['id']): ?>
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
