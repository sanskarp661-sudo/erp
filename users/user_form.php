<?php
require_once __DIR__ . '/../includes/auth.php';
require_role(['admin']);

$id = (int)input('id');
$isNew = !$id;
$me = current_user();
$isSelf = !$isNew && $id === $me['id'];

$defaults = [
    'id' => 0, 'name' => '', 'first_name' => '', 'middle_name' => '', 'last_name' => '',
    'username' => '', 'language' => 'en', 'time_zone' => 'UTC', 'mobile_no' => '', 'phone' => '',
    'address' => '', 'bio' => '', 'must_change_password' => 0, 'email' => '', 'role' => 'staff', 'status' => 'active',
];

// Named $editUser (not $user) because includes/header.php sets $user =
// current_user() for the logged-in viewer — reusing $user here would get
// clobbered on include.
$editUser = $defaults;

if (!$isNew) {
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $editUser = $stmt->fetch();
    if (!$editUser) {
        flash('danger', 'User not found.');
        redirect('/users/users.php');
    }
}

$LANGUAGES = ['en' => 'English', 'hi' => 'Hindi', 'es' => 'Spanish', 'fr' => 'French', 'de' => 'German', 'zh' => 'Chinese', 'ar' => 'Arabic', 'pt' => 'Portuguese'];
$TIMEZONES = ['UTC', 'Asia/Kolkata', 'Asia/Dubai', 'Asia/Singapore', 'Asia/Tokyo', 'Europe/London', 'Europe/Berlin', 'America/New_York', 'America/Chicago', 'America/Los_Angeles', 'Australia/Sydney'];

$validTabs = ['details', 'roles', 'more', 'settings', 'connections'];
$activeTab = in_array(input('tab'), $validTabs, true) ? input('tab') : 'details';
$error = '';

function compute_full_name(string $first, string $middle, string $last): string
{
    return trim(preg_replace('/\s+/', ' ', "$first $middle $last"));
}

// ---------------------------------------------------------------------
// POST handlers
// ---------------------------------------------------------------------

if (is_post() && input('form_action') === 'create') {
    csrf_verify();
    $first = input('first_name');
    $middle = input('middle_name');
    $last = input('last_name');
    $email = input('email');
    $username = input('username') ?: null;
    $language = array_key_exists(input('language'), $LANGUAGES) ? input('language') : 'en';
    $timeZone = in_array(input('time_zone'), $TIMEZONES, true) ? input('time_zone') : 'UTC';
    $mobile = input('mobile_no');
    $phone = input('phone');
    $address = input('address');
    $bio = input('bio');
    $role = in_array(input('role'), ['admin', 'manager', 'staff'], true) ? input('role') : 'staff';
    $status = in_array(input('status'), ['active', 'inactive'], true) ? input('status') : 'active';
    $password = input('password');
    $name = compute_full_name($first, $middle, $last);

    if ($first === '' || $email === '') {
        $error = 'First name and email are required.';
    } elseif ($password === '') {
        $error = 'Password is required for a new user.';
    } else {
        try {
            $stmt = db()->prepare('INSERT INTO users (name, first_name, middle_name, last_name, username, language, time_zone, mobile_no, phone, address, bio, email, password_hash, role, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$name, $first, $middle ?: null, $last ?: null, $username, $language, $timeZone, $mobile ?: null, $phone ?: null, $address ?: null, $bio ?: null, $email, password_hash($password, PASSWORD_BCRYPT), $role, $status]);
            $newId = (int)db()->lastInsertId();
            log_activity('user', $newId, 'created');
            flash('success', 'User created.');
            redirect('/users/user_form.php?id=' . $newId);
        } catch (PDOException $e) {
            $error = str_contains($e->getMessage(), 'Duplicate') ? 'A user with this email or username already exists.' : 'Could not create user.';
        }
    }
    $editUser = array_merge($defaults, ['first_name' => $first, 'middle_name' => $middle, 'last_name' => $last, 'email' => $email, 'username' => $username, 'language' => $language, 'time_zone' => $timeZone, 'mobile_no' => $mobile, 'phone' => $phone, 'address' => $address, 'bio' => $bio, 'role' => $role, 'status' => $status]);
}

if (is_post() && input('form_action') === 'save_details' && !$isNew) {
    csrf_verify();
    $first = input('first_name');
    $middle = input('middle_name');
    $last = input('last_name');
    $email = input('email');
    $username = input('username') ?: null;
    $language = array_key_exists(input('language'), $LANGUAGES) ? input('language') : 'en';
    $timeZone = in_array(input('time_zone'), $TIMEZONES, true) ? input('time_zone') : 'UTC';
    $status = $isSelf ? $editUser['status'] : (in_array(input('status'), ['active', 'inactive'], true) ? input('status') : 'active');
    $name = compute_full_name($first, $middle, $last);

    if ($first === '' || $email === '') {
        $error = 'First name and email are required.';
    } else {
        try {
            $old = $editUser;
            db()->prepare('UPDATE users SET name=?, first_name=?, middle_name=?, last_name=?, username=?, language=?, time_zone=?, email=?, status=?, updated_at=NOW() WHERE id=?')
                ->execute([$name, $first, $middle ?: null, $last ?: null, $username, $language, $timeZone, $email, $status, $id]);
            $new = ['first_name' => $first, 'middle_name' => $middle, 'last_name' => $last, 'username' => $username, 'language' => $LANGUAGES[$language], 'time_zone' => $timeZone, 'email' => $email, 'status' => $status];
            log_field_changes('user', $id, [
                'first_name' => $old['first_name'], 'middle_name' => $old['middle_name'], 'last_name' => $old['last_name'],
                'username' => $old['username'], 'language' => $LANGUAGES[$old['language']] ?? $old['language'], 'time_zone' => $old['time_zone'],
                'email' => $old['email'], 'status' => $old['status'],
            ], $new, [
                'first_name' => 'First Name', 'middle_name' => 'Middle Name', 'last_name' => 'Last Name', 'username' => 'Username',
                'language' => 'Language', 'time_zone' => 'Time Zone', 'email' => 'Email', 'status' => 'Status',
            ]);
            flash('success', 'User details updated.');
            redirect('/users/user_form.php?id=' . $id . '&tab=details');
        } catch (PDOException $e) {
            $error = str_contains($e->getMessage(), 'Duplicate') ? 'A user with this email or username already exists.' : 'Could not save user details.';
        }
    }
}

if (is_post() && input('form_action') === 'save_role' && !$isNew) {
    csrf_verify();
    $role = $isSelf ? $editUser['role'] : (in_array(input('role'), ['admin', 'manager', 'staff'], true) ? input('role') : 'staff');
    $old = $editUser['role'];
    db()->prepare('UPDATE users SET role=?, updated_at=NOW() WHERE id=?')->execute([$role, $id]);
    log_field_changes('user', $id, ['role' => $old], ['role' => $role], ['role' => 'Role']);
    flash('success', 'Role updated.');
    redirect('/users/user_form.php?id=' . $id . '&tab=roles');
}

if (is_post() && input('form_action') === 'save_more' && !$isNew) {
    csrf_verify();
    $mobile = input('mobile_no');
    $phone = input('phone');
    $address = input('address');
    $bio = input('bio');
    $old = $editUser;
    db()->prepare('UPDATE users SET mobile_no=?, phone=?, address=?, bio=?, updated_at=NOW() WHERE id=?')
        ->execute([$mobile ?: null, $phone ?: null, $address ?: null, $bio ?: null, $id]);
    log_field_changes('user',
        $id,
        ['mobile_no' => $old['mobile_no'], 'phone' => $old['phone'], 'address' => $old['address'], 'bio' => $old['bio']],
        ['mobile_no' => $mobile, 'phone' => $phone, 'address' => $address, 'bio' => $bio],
        ['mobile_no' => 'Mobile No', 'phone' => 'Phone', 'address' => 'Address', 'bio' => 'Bio']
    );
    flash('success', 'Contact information updated.');
    redirect('/users/user_form.php?id=' . $id . '&tab=more');
}

if (is_post() && input('form_action') === 'change_password' && !$isNew) {
    csrf_verify();
    $newPassword = input('new_password');
    $confirmPassword = input('confirm_password');
    if ($newPassword === '' || strlen($newPassword) < 6) {
        flash('danger', 'Password must be at least 6 characters.');
    } elseif ($newPassword !== $confirmPassword) {
        flash('danger', 'Passwords do not match.');
    } else {
        db()->prepare('UPDATE users SET password_hash=?, updated_at=NOW() WHERE id=?')->execute([password_hash($newPassword, PASSWORD_BCRYPT), $id]);
        // format_activity() escapes description at render time, so store it raw here.
        $who = $isSelf ? 'You' : ($editUser['name'] ?? 'Someone');
        log_activity('user', $id, 'password_changed', $who . ' changed the password');
        flash('success', 'Password updated.');
    }
    redirect('/users/user_form.php?id=' . $id . '&tab=settings');
}

if (is_post() && input('form_action') === 'save_security' && !$isNew) {
    csrf_verify();
    $mustChange = input('must_change_password') === '1' ? 1 : 0;
    $old = (int)$editUser['must_change_password'];
    db()->prepare('UPDATE users SET must_change_password=?, updated_at=NOW() WHERE id=?')->execute([$mustChange, $id]);
    log_field_changes('user', $id, ['must_change_password' => $old ? 'Yes' : 'No'], ['must_change_password' => $mustChange ? 'Yes' : 'No'], ['must_change_password' => 'Require Password Change']);
    flash('success', 'Security settings updated.');
    redirect('/users/user_form.php?id=' . $id . '&tab=settings');
}

if (is_post() && input('form_action') === 'add_comment' && !$isNew) {
    csrf_verify();
    $body = input('body');
    if ($body !== '') {
        add_comment('user', $id, $body);
    }
    redirect('/users/user_form.php?id=' . $id . '&tab=' . $activeTab);
}

// Refresh after any successful write above that didn't redirect (i.e. only
// on validation errors we fall through to re-render with $editUser as the
// re-submitted values already assigned above for 'create'; for edit
// actions we simply re-fetch to reflect current DB state).
if (!$isNew) {
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $editUser = $stmt->fetch() ?: $editUser;
}

$connections = [];
$activities = [];
$comments = [];
if (!$isNew) {
    $pdo = db();
    $connectionQueries = [
        'Sales Orders'    => ["SELECT COUNT(*) FROM sales_orders WHERE created_by = ?", base_url('sales/orders.php?user=' . $id)],
        'Purchase Orders' => ["SELECT COUNT(*) FROM purchase_orders WHERE created_by = ?", base_url('purchases/orders.php?user=' . $id)],
        'Invoices'        => ["SELECT COUNT(*) FROM invoices WHERE created_by = ?", base_url('accounting/invoices.php?user=' . $id)],
        'Payments'        => ["SELECT COUNT(*) FROM payments WHERE created_by = ?", base_url('accounting/payments.php?user=' . $id)],
        'Expenses'        => ["SELECT COUNT(*) FROM expenses WHERE created_by = ?", base_url('accounting/expenses.php?user=' . $id)],
        'Stock Movements' => ["SELECT COUNT(*) FROM stock_movements WHERE created_by = ?", base_url('supply-chain/stock_movements.php?user=' . $id)],
    ];
    foreach ($connectionQueries as $label => [$sql, $url]) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id]);
        $connections[] = ['label' => $label, 'count' => (int)$stmt->fetchColumn(), 'url' => $url];
    }
    $activities = get_activity_log('user', $id);
    $comments = get_comments('user', $id);
}

$page_title = $isNew ? 'New User' : $editUser['name'];
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex align-items-center gap-2 mb-2 text-muted small">
  <a href="<?= base_url('dashboard.php') ?>" class="text-muted"><i class="fa-solid fa-house"></i></a>
  <span>/</span>
  <a href="users.php" class="text-muted">User</a>
  <?php if (!$isNew): ?><span>/</span><span><?= e($editUser['name']) ?></span><?php endif; ?>
</div>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h4 class="mb-0 d-flex align-items-center gap-2">
    <?= e($isNew ? 'New User' : $editUser['name']) ?>
    <?php if (!$isNew): ?>
      <span class="badge text-bg-<?= $editUser['status'] === 'active' ? 'success' : 'secondary' ?>"><?= $editUser['status'] === 'active' ? 'Active' : 'Inactive' ?></span>
    <?php endif; ?>
  </h4>
  <?php if (!$isNew): ?>
  <div class="page-actions">
    <div class="dropdown d-inline-block">
      <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown">Permissions</button>
      <ul class="dropdown-menu dropdown-menu-end">
        <li><button type="button" class="dropdown-item" onclick="document.getElementById('tab-roles').click()">View Role Permissions</button></li>
      </ul>
    </div>
    <div class="dropdown d-inline-block">
      <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown">Password</button>
      <ul class="dropdown-menu dropdown-menu-end">
        <li><button type="button" class="dropdown-item" onclick="document.getElementById('tab-settings').click()">Set New Password</button></li>
      </ul>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<?php if ($isNew): ?>
<!-- ===================== NEW USER: single condensed form ===================== -->
<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#pane-details" type="button">User Details</button></li>
  <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#pane-roles" type="button">Roles &amp; Permissions</button></li>
  <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#pane-more" type="button">More Information</button></li>
</ul>
<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="form_action" value="create">
  <div class="tab-content">
    <div class="tab-pane fade show active card p-4" id="pane-details">
      <div class="row g-3">
        <div class="col-sm-4"><label class="form-label">Email *</label><input type="email" name="email" class="form-control" required value="<?= e($editUser['email']) ?>"></div>
        <div class="col-sm-4"><label class="form-label">First Name *</label><input type="text" name="first_name" class="form-control" required value="<?= e($editUser['first_name']) ?>"></div>
        <div class="col-sm-4"><label class="form-label">Language</label>
          <select name="language" class="form-select"><?php foreach ($LANGUAGES as $code => $label): ?><option value="<?= $code ?>" <?= $editUser['language'] === $code ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
        </div>
        <div class="col-sm-4"><label class="form-label">Middle Name</label><input type="text" name="middle_name" class="form-control" value="<?= e($editUser['middle_name']) ?>"></div>
        <div class="col-sm-4"><label class="form-label">Username</label><input type="text" name="username" class="form-control" value="<?= e($editUser['username']) ?>"></div>
        <div class="col-sm-4"><label class="form-label">Time Zone</label>
          <select name="time_zone" class="form-select"><?php foreach ($TIMEZONES as $tz): ?><option value="<?= e($tz) ?>" <?= $editUser['time_zone'] === $tz ? 'selected' : '' ?>><?= e($tz) ?></option><?php endforeach; ?></select>
        </div>
        <div class="col-sm-4"><label class="form-label">Last Name</label><input type="text" name="last_name" class="form-control" value="<?= e($editUser['last_name']) ?>"></div>
        <div class="col-sm-4"><label class="form-label">Password *</label><input type="password" name="password" class="form-control" required></div>
        <div class="col-sm-4"><label class="form-label">Status</label>
          <select name="status" class="form-select"><option value="active" <?= $editUser['status'] === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= $editUser['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option></select>
        </div>
      </div>
    </div>
    <div class="tab-pane fade card p-4" id="pane-roles">
      <label class="form-label">Role</label>
      <select name="role" class="form-select" style="max-width:280px">
        <option value="admin" <?= $editUser['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
        <option value="manager" <?= $editUser['role'] === 'manager' ? 'selected' : '' ?>>Manager</option>
        <option value="staff" <?= $editUser['role'] === 'staff' ? 'selected' : '' ?>>Staff</option>
      </select>
    </div>
    <div class="tab-pane fade card p-4" id="pane-more">
      <div class="row g-3">
        <div class="col-sm-6"><label class="form-label">Mobile No</label><input type="text" name="mobile_no" class="form-control" value="<?= e($editUser['mobile_no']) ?>"></div>
        <div class="col-sm-6"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control" value="<?= e($editUser['phone']) ?>"></div>
        <div class="col-12"><label class="form-label">Address</label><textarea name="address" class="form-control" rows="2"><?= e($editUser['address']) ?></textarea></div>
        <div class="col-12"><label class="form-label">Bio</label><textarea name="bio" class="form-control" rows="2"><?= e($editUser['bio']) ?></textarea></div>
      </div>
    </div>
  </div>
  <div class="page-actions mt-3">
    <button type="submit" class="btn btn-brand">Create User</button>
    <a href="users.php" class="btn btn-outline-secondary">Cancel</a>
  </div>
</form>

<?php else: ?>
<!-- ===================== EXISTING USER: full tabbed document ===================== -->
<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><button class="nav-link <?= $activeTab === 'details' ? 'active' : '' ?>" id="tab-details" data-bs-toggle="tab" data-bs-target="#pane-details" type="button">User Details</button></li>
  <li class="nav-item"><button class="nav-link <?= $activeTab === 'roles' ? 'active' : '' ?>" id="tab-roles" data-bs-toggle="tab" data-bs-target="#pane-roles" type="button">Roles &amp; Permissions</button></li>
  <li class="nav-item"><button class="nav-link <?= $activeTab === 'more' ? 'active' : '' ?>" id="tab-more" data-bs-toggle="tab" data-bs-target="#pane-more" type="button">More Information</button></li>
  <li class="nav-item"><button class="nav-link <?= $activeTab === 'settings' ? 'active' : '' ?>" id="tab-settings" data-bs-toggle="tab" data-bs-target="#pane-settings" type="button">Settings</button></li>
  <li class="nav-item"><button class="nav-link <?= $activeTab === 'connections' ? 'active' : '' ?>" id="tab-connections" data-bs-toggle="tab" data-bs-target="#pane-connections" type="button">Connections</button></li>
</ul>

<div class="tab-content mb-3">

  <!-- ---- User Details ---- -->
  <div class="tab-pane fade <?= $activeTab === 'details' ? 'show active' : '' ?>" id="pane-details">
    <form method="post" class="card p-4">
      <?= csrf_field() ?>
      <input type="hidden" name="form_action" value="save_details">
      <input type="hidden" name="tab" value="details">
      <div class="form-check mb-3">
        <?php if ($isSelf): ?>
          <input type="hidden" name="status" value="<?= e($editUser['status']) ?>">
        <?php else: ?>
          <!-- Unchecked checkboxes submit nothing, so a hidden "inactive" fallback
               comes first; PHP keeps the last same-named value it receives, so the
               checkbox's "active" (only sent when checked) wins when it's checked. -->
          <input type="hidden" name="status" value="inactive">
        <?php endif; ?>
        <input type="checkbox" class="form-check-input" id="enabledCheck" name="status" value="active" <?= $editUser['status'] === 'active' ? 'checked' : '' ?> <?= $isSelf ? 'disabled' : '' ?>>
        <label class="form-check-label" for="enabledCheck">Enabled</label>
      </div>
      <div class="row g-3">
        <div class="col-sm-4"><label class="form-label">Email *</label><input type="email" name="email" class="form-control" required value="<?= e($editUser['email']) ?>"></div>
        <div class="col-sm-4"><label class="form-label">Full Name</label><input type="text" class="form-control" value="<?= e($editUser['name']) ?>" readonly></div>
        <div class="col-sm-4"><label class="form-label">Language</label>
          <select name="language" class="form-select"><?php foreach ($LANGUAGES as $code => $label): ?><option value="<?= $code ?>" <?= $editUser['language'] === $code ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
        </div>
        <div class="col-sm-4"><label class="form-label">First Name *</label><input type="text" name="first_name" class="form-control" required value="<?= e($editUser['first_name']) ?>"></div>
        <div class="col-sm-4"><label class="form-label">Username</label><input type="text" name="username" class="form-control" value="<?= e($editUser['username']) ?>"></div>
        <div class="col-sm-4"><label class="form-label">Time Zone</label>
          <select name="time_zone" class="form-select"><?php foreach ($TIMEZONES as $tz): ?><option value="<?= e($tz) ?>" <?= $editUser['time_zone'] === $tz ? 'selected' : '' ?>><?= e($tz) ?></option><?php endforeach; ?></select>
        </div>
        <div class="col-sm-4"><label class="form-label">Middle Name</label><input type="text" name="middle_name" class="form-control" value="<?= e($editUser['middle_name']) ?>"></div>
        <div class="col-sm-4"></div>
        <div class="col-sm-4"></div>
        <div class="col-sm-4"><label class="form-label">Last Name</label><input type="text" name="last_name" class="form-control" value="<?= e($editUser['last_name']) ?>"></div>
      </div>
      <div class="page-actions mt-4"><button type="submit" class="btn btn-brand">Save</button></div>
    </form>
  </div>

  <!-- ---- Roles & Permissions ---- -->
  <div class="tab-pane fade <?= $activeTab === 'roles' ? 'show active' : '' ?>" id="pane-roles">
    <form method="post" class="card p-4 mb-3">
      <?= csrf_field() ?>
      <input type="hidden" name="form_action" value="save_role">
      <input type="hidden" name="tab" value="roles">
      <label class="form-label">Role</label>
      <select name="role" class="form-select mb-2" style="max-width:280px" <?= $isSelf ? 'disabled' : '' ?>>
        <option value="admin" <?= $editUser['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
        <option value="manager" <?= $editUser['role'] === 'manager' ? 'selected' : '' ?>>Manager</option>
        <option value="staff" <?= $editUser['role'] === 'staff' ? 'selected' : '' ?>>Staff</option>
      </select>
      <?php if ($isSelf): ?>
        <input type="hidden" name="role" value="<?= e($editUser['role']) ?>">
        <div class="form-text mb-2">You cannot change your own role.</div>
      <?php endif; ?>
      <div><button type="submit" class="btn btn-brand">Save</button></div>
    </form>

    <div class="card p-4">
      <h6 class="mb-3">What each role can access</h6>
      <div class="table-responsive">
        <table class="table table-sm">
          <thead><tr><th>Area</th><th class="text-center">Admin</th><th class="text-center">Manager</th><th class="text-center">Staff</th></tr></thead>
          <tbody>
            <tr><td>Inventory, Supply Chain, Procurement, Sales, POS, CRM</td><td class="text-center"><i class="fa-solid fa-check text-success"></i></td><td class="text-center"><i class="fa-solid fa-check text-success"></i></td><td class="text-center"><i class="fa-solid fa-check text-success"></i></td></tr>
            <tr><td>Finance (invoices, payments, expenses)</td><td class="text-center"><i class="fa-solid fa-check text-success"></i></td><td class="text-center"><i class="fa-solid fa-check text-success"></i></td><td class="text-center"><i class="fa-solid fa-check text-success"></i></td></tr>
            <tr><td>Employee records &amp; attendance</td><td class="text-center"><i class="fa-solid fa-check text-success"></i></td><td class="text-center"><i class="fa-solid fa-check text-success"></i></td><td class="text-center"><i class="fa-solid fa-check text-success"></i></td></tr>
            <tr><td>Employee salary visibility</td><td class="text-center"><i class="fa-solid fa-check text-success"></i></td><td class="text-center"><i class="fa-solid fa-check text-success"></i></td><td class="text-center text-muted">—</td></tr>
            <tr><td>Approve/reject leave requests</td><td class="text-center"><i class="fa-solid fa-check text-success"></i></td><td class="text-center"><i class="fa-solid fa-check text-success"></i></td><td class="text-center text-muted">—</td></tr>
            <tr><td>User management &amp; company settings</td><td class="text-center"><i class="fa-solid fa-check text-success"></i></td><td class="text-center text-muted">—</td><td class="text-center text-muted">—</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ---- More Information ---- -->
  <div class="tab-pane fade <?= $activeTab === 'more' ? 'show active' : '' ?>" id="pane-more">
    <form method="post" class="card p-4">
      <?= csrf_field() ?>
      <input type="hidden" name="form_action" value="save_more">
      <input type="hidden" name="tab" value="more">
      <div class="row g-3">
        <div class="col-sm-6"><label class="form-label">Mobile No</label><input type="text" name="mobile_no" class="form-control" value="<?= e($editUser['mobile_no']) ?>"></div>
        <div class="col-sm-6"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control" value="<?= e($editUser['phone']) ?>"></div>
        <div class="col-12"><label class="form-label">Address</label><textarea name="address" class="form-control" rows="2"><?= e($editUser['address']) ?></textarea></div>
        <div class="col-12"><label class="form-label">Bio</label><textarea name="bio" class="form-control" rows="3"><?= e($editUser['bio']) ?></textarea></div>
      </div>
      <div class="page-actions mt-4"><button type="submit" class="btn btn-brand">Save</button></div>
    </form>
  </div>

  <!-- ---- Settings ---- -->
  <div class="tab-pane fade <?= $activeTab === 'settings' ? 'show active' : '' ?>" id="pane-settings">
    <div class="card p-4 mb-3">
      <h6 class="mb-3">Set New Password</h6>
      <form method="post" class="row g-3">
        <?= csrf_field() ?>
        <input type="hidden" name="form_action" value="change_password">
        <input type="hidden" name="tab" value="settings">
        <div class="col-sm-6"><label class="form-label">New Password</label><input type="password" name="new_password" class="form-control" minlength="6" required></div>
        <div class="col-sm-6"><label class="form-label">Confirm Password</label><input type="password" name="confirm_password" class="form-control" minlength="6" required></div>
        <div class="col-12"><button type="submit" class="btn btn-brand">Update Password</button></div>
      </form>
    </div>
    <div class="card p-4">
      <h6 class="mb-3">Security</h6>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="form_action" value="save_security">
        <input type="hidden" name="tab" value="settings">
        <div class="form-check mb-3">
          <input type="checkbox" class="form-check-input" id="mustChangeCheck" name="must_change_password" value="1" <?= $editUser['must_change_password'] ? 'checked' : '' ?>>
          <label class="form-check-label" for="mustChangeCheck">Require password change at next login</label>
        </div>
        <button type="submit" class="btn btn-brand">Save</button>
      </form>
    </div>
  </div>

  <!-- ---- Connections ---- -->
  <div class="tab-pane fade <?= $activeTab === 'connections' ? 'show active' : '' ?>" id="pane-connections">
    <div class="row g-3">
      <?php foreach ($connections as $c): ?>
        <div class="col-sm-6 col-lg-4">
          <a href="<?= e($c['url']) ?>" class="stat-card text-decoration-none">
            <div class="icon bg-brand"><i class="fa-solid fa-link"></i></div>
            <div><div class="value"><?= $c['count'] ?></div><div class="label"><?= e($c['label']) ?></div></div>
          </a>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- ---- Comments + Activity (persistent, always visible) ---- -->
<div class="row g-3">
  <div class="col-lg-7">
    <div class="card p-3">
      <h6 class="mb-3">Comments</h6>
      <form method="post" class="d-flex gap-2 mb-3">
        <?= csrf_field() ?>
        <input type="hidden" name="form_action" value="add_comment">
        <input type="hidden" name="tab" value="<?= e($activeTab) ?>">
        <div class="rounded-circle bg-success-subtle text-success d-flex align-items-center justify-content-center flex-shrink-0" style="width:36px;height:36px;font-size:.75rem;font-weight:700"><?= e(initials($me['name'])) ?></div>
        <input type="text" name="body" class="form-control" placeholder="Type a reply / comment" required>
        <button type="submit" class="btn btn-outline-brand">Post</button>
      </form>
      <?php foreach ($comments as $c): ?>
        <div class="d-flex gap-2 mb-3">
          <div class="rounded-circle bg-secondary-subtle text-secondary d-flex align-items-center justify-content-center flex-shrink-0" style="width:36px;height:36px;font-size:.75rem;font-weight:700"><?= e(initials($c['author_name'] ?? '?')) ?></div>
          <div>
            <div class="small"><strong><?= e($c['author_name'] ?? 'Someone') ?></strong> <span class="text-muted"><?= e(date('M j, Y g:ia', strtotime($c['created_at']))) ?></span></div>
            <div><?= e($c['body']) ?></div>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if (!$comments): ?><div class="text-muted small">No comments yet.</div><?php endif; ?>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card p-3">
      <h6 class="mb-3">Activity</h6>
      <ul class="list-unstyled activity-feed">
        <?php foreach ($activities as $a): ?>
          <li><?= format_activity($a) ?> <span class="text-muted small">&middot; <?= e(date('M j, Y', strtotime($a['created_at']))) ?></span></li>
        <?php endforeach; ?>
        <?php if (!$activities): ?><li class="text-muted small">No activity yet.</li><?php endif; ?>
      </ul>
    </div>
  </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
