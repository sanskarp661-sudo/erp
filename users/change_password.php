<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$error = '';

if (is_post()) {
    csrf_verify();
    $newPassword = input('new_password');
    $confirmPassword = input('confirm_password');

    if ($newPassword === '' || strlen($newPassword) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        $id = current_user()['id'];
        db()->prepare('UPDATE users SET password_hash=?, must_change_password=0, updated_at=NOW() WHERE id=?')
            ->execute([password_hash($newPassword, PASSWORD_BCRYPT), $id]);
        log_activity('user', $id, 'password_changed', 'You changed the password');
        $_SESSION['user']['must_change_password'] = false;
        flash('success', 'Password updated — you can now continue.');
        redirect('/dashboard.php');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Change Password Required · <?= e(setting('company_name', APP_NAME)) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link href="<?= base_url('assets/css/style.css') ?>" rel="stylesheet">
</head>
<body>
<div class="auth-wrapper">
  <div class="auth-card">
    <h1><i class="fa-solid fa-lock text-warning"></i> Password Change Required</h1>
    <div class="subtitle">An administrator requires you to set a new password before continuing.</div>

    <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

    <form method="post">
      <?= csrf_field() ?>
      <div class="mb-3">
        <label class="form-label">New Password</label>
        <input type="password" name="new_password" class="form-control" minlength="6" required autofocus>
      </div>
      <div class="mb-3">
        <label class="form-label">Confirm Password</label>
        <input type="password" name="confirm_password" class="form-control" minlength="6" required>
      </div>
      <button type="submit" class="btn btn-brand w-100">Set Password &amp; Continue</button>
    </form>
    <p class="text-muted mt-3 mb-0" style="font-size:.8rem">
      <a href="<?= base_url('logout.php') ?>">Log out</a> instead.
    </p>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
