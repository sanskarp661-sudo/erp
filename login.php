<?php
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    redirect('/dashboard.php');
}

$error = '';

if (is_post()) {
    csrf_verify();
    $email = input('email');
    $password = input('password');

    if ($email === '' || $password === '') {
        $error = 'Please enter both email and password.';
    } elseif (attempt_login($email, $password)) {
        $target = $_SESSION['redirect_after_login'] ?? '/dashboard.php';
        unset($_SESSION['redirect_after_login']);
        redirect($target !== '' ? $target : '/dashboard.php');
    } else {
        $error = 'Invalid email or password.';
    }
}

$page_title = 'Login';
require __DIR__ . '/includes/header.php';
?>
<div class="auth-card">
  <h1><i class="fa-solid fa-layer-group text-primary"></i> <?= e(setting('company_name', APP_NAME)) ?></h1>
  <div class="subtitle">Sign in to your ERP account</div>

  <?php foreach (get_flashes() as $f): ?>
    <div class="alert alert-<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
  <?php endforeach; ?>

  <?php if ($error): ?>
    <div class="alert alert-danger"><?= e($error) ?></div>
  <?php endif; ?>

  <form method="post" novalidate>
    <?= csrf_field() ?>
    <div class="mb-3">
      <label class="form-label">Email</label>
      <input type="email" name="email" class="form-control" required autofocus value="<?= e(input('email')) ?>">
    </div>
    <div class="mb-3">
      <label class="form-label">Password</label>
      <input type="password" name="password" class="form-control" required>
    </div>
    <button type="submit" class="btn btn-brand w-100">Sign In</button>
  </form>
  <p class="text-muted mt-3 mb-0" style="font-size:.8rem">
    Default admin login: <code>admin@example.com</code> / <code>Admin@123</code> — change this after first login.
  </p>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
