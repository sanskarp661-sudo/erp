<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/integration_settings.php';
require_admin_section();
$canEdit = can_edit_admin_section();

if (is_post() && input('form_action') === 'add_api_client') {
    require_admin_edit();
    csrf_verify();
    $name = trim(input('name')) ?: 'Unnamed system';
    $key = bin2hex(random_bytes(24));
    db()->prepare("INSERT INTO api_clients (name, api_key, status) VALUES (?, ?, 'active')")->execute([$name, $key]);
    log_activity('api_client', (int)db()->lastInsertId(), 'created', "API client \"$name\" created");
    flash('success', "\"$name\" added. Copy its key below and give it to that system.");
    redirect('/users/integrations.php');
}

if (is_post() && input('form_action') === 'toggle_api_client') {
    require_admin_edit();
    csrf_verify();
    $id = (int)input('id');
    db()->prepare("UPDATE api_clients SET status = IF(status='active','inactive','active') WHERE id=?")->execute([$id]);
    redirect('/users/integrations.php');
}

if (is_post() && input('form_action') === 'delete_api_client') {
    require_admin_edit();
    csrf_verify();
    $id = (int)input('id');
    db()->prepare('DELETE FROM api_clients WHERE id=?')->execute([$id]);
    flash('success', 'API client removed — it can no longer call the ERP.');
    redirect('/users/integrations.php');
}

if (is_post() && input('form_action') === 'save_webhook') {
    require_admin_edit();
    csrf_verify();
    $url = trim(input('website_webhook_url'));
    db()->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
        ->execute(['website_webhook_url', $url]);
    flash('success', 'Webhook URL saved.');
    redirect('/users/integrations.php');
}

if (is_post() && input('form_action') === 'save_cashfree') {
    require_admin_edit();
    csrf_verify();
    $values = [
        'cashfree_client_id' => trim(input('cashfree_client_id')),
        'cashfree_client_secret' => trim(input('cashfree_client_secret')),
        'cashfree_env' => input('cashfree_env') === 'production' ? 'production' : 'sandbox',
        'cashfree_product' => input('cashfree_product') === 'payment_link' ? 'payment_link' : 'orders',
    ];
    $stmt = db()->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    foreach ($values as $k => $v) {
        $stmt->execute([$k, $v]);
    }
    flash('success', 'Payment gateway settings saved.');
    redirect('/users/integrations.php');
}

$apiClients = db()->query('SELECT * FROM api_clients ORDER BY id')->fetchAll();
$legacyKeyActive = empty($apiClients) && integration_setting('WEBSITE_API_KEY') !== '';

$page_title = 'Integrations';
require __DIR__ . '/../includes/header.php';
?>
<div class="dash">
<div class="dash-head">
  <div>
    <h1 class="dash-title">Website &amp; Payment Integration</h1>
    <p class="dash-sub">Everything a developer needs to connect your website or another system to this ERP, and your payment gateway settings — all in one place.</p>
  </div>
</div>
<?php if (!$canEdit): ?><div class="alert alert-secondary">View only — you don't have permission to change integration settings.</div><?php endif; ?>

<div class="card p-4 mb-3">
  <h6 class="mb-1">Your ERP's API</h6>
  <p class="text-muted small mb-3">Give these to whoever is building the website or system integration.</p>
  <div class="mb-3">
    <label class="form-label small mb-1">API Base URL</label>
    <div class="input-group">
      <input type="text" class="form-control" readonly value="<?= e(base_url('api/v1/')) ?>" data-secret="<?= e(base_url('api/v1/')) ?>">
      <button class="btn btn-outline-secondary" type="button" data-copy title="Copy"><i class="fa-solid fa-copy"></i></button>
    </div>
  </div>
  <div class="small">
    <div class="mb-2"><code>GET products.php</code> — list products with <code>show_in_website = 1</code> (paginated, or <code>?sku=</code> for one)</div>
    <div class="mb-2"><code>GET order_status.php?order_id=...</code> — poll a website-origin order's status</div>
    <div class="mb-0">Every request needs an <code>X-API-Key</code> header — see API Clients below. Requests are rejected with 401 otherwise.</div>
  </div>
</div>

<div class="card p-4 mb-3">
  <div class="d-flex justify-content-between align-items-start mb-1">
    <h6 class="mb-0">API Clients</h6>
  </div>
  <p class="text-muted small mb-3">A named key per system that's allowed to call the API above — your website, and any other system you connect later. Revoke one without affecting the others.</p>

  <?php if ($legacyKeyActive): ?>
    <div class="alert alert-info small">A legacy API key is configured outside this list (in config.php or an integration file) and is still accepted. Add a client below whenever you're ready to switch to this.</div>
  <?php endif; ?>

  <div class="table-responsive mb-3">
    <table class="table table-sm align-middle mb-0">
      <thead><tr><th>Name</th><th>API Key</th><th>Status</th><th>Last Used</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($apiClients as $c): ?>
        <tr>
          <td class="fw-semibold"><?= e($c['name']) ?></td>
          <td style="min-width:280px">
            <div class="input-group input-group-sm">
              <input type="text" class="form-control font-monospace" readonly
                     value="<?= e(str_repeat('•', 8) . substr($c['api_key'], -4)) ?>"
                     data-secret="<?= e($c['api_key']) ?>" data-masked="<?= e(str_repeat('•', 8) . substr($c['api_key'], -4)) ?>">
              <button class="btn btn-outline-secondary" type="button" data-reveal title="Show/hide"><i class="fa-solid fa-eye"></i></button>
              <button class="btn btn-outline-secondary" type="button" data-copy title="Copy"><i class="fa-solid fa-copy"></i></button>
            </div>
          </td>
          <td><span class="dash-pill <?= $c['status'] === 'active' ? 'dash-pill-green' : 'dash-pill-gray' ?>"><?= $c['status'] === 'active' ? 'Active' : 'Revoked' ?></span></td>
          <td class="small text-muted"><?= $c['last_used_at'] ? e(date('d M Y, H:i', strtotime($c['last_used_at']))) : 'Never' ?></td>
          <td class="text-end text-nowrap">
            <?php if ($canEdit): ?>
            <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="form_action" value="toggle_api_client"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
              <button class="btn btn-sm btn-light" type="submit" title="<?= $c['status'] === 'active' ? 'Revoke' : 'Reactivate' ?>"><i class="fa-solid fa-<?= $c['status'] === 'active' ? 'ban' : 'rotate-left' ?>"></i></button>
            </form>
            <form method="post" class="d-inline" data-confirm="Delete API client &quot;<?= e($c['name']) ?>&quot;? Any system still using this key will stop working immediately.">
              <?= csrf_field() ?><input type="hidden" name="form_action" value="delete_api_client"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
              <button class="btn btn-sm btn-light text-danger" type="submit" title="Delete"><i class="fa-solid fa-trash"></i></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$apiClients): ?>
        <tr><td colspan="5" class="text-muted small text-center py-3">No API clients yet. Add one below — the website is a good first one.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($canEdit): ?>
  <form method="post" class="d-flex gap-2">
    <?= csrf_field() ?>
    <input type="hidden" name="form_action" value="add_api_client">
    <input type="text" name="name" class="form-control" placeholder="e.g. Website, Mobile App, Accounting System" required style="max-width:320px">
    <button type="submit" class="btn btn-brand text-nowrap"><i class="fa-solid fa-plus"></i> Add API Client</button>
  </form>
  <?php endif; ?>
</div>

<div class="card p-4 mb-3">
  <h6 class="mb-1">Outgoing Webhook</h6>
  <p class="text-muted small mb-3">Where the ERP POSTs order-status updates (<code>order.status_changed</code>, <code>order.delivered</code>) for orders placed via the website. Signed with the first active API client's key above (<code>X-Webhook-Signature</code> header, HMAC-SHA256). Leave blank to disable — the website can still poll <code>order_status.php</code>.</p>
  <?php if ($canEdit): ?>
  <form method="post" class="d-flex gap-2">
    <?= csrf_field() ?>
    <input type="hidden" name="form_action" value="save_webhook">
    <input type="url" name="website_webhook_url" class="form-control" placeholder="https://yourstore.com/webhooks/erp.php" value="<?= e(setting('website_webhook_url', '')) ?>">
    <button type="submit" class="btn btn-brand text-nowrap">Save</button>
  </form>
  <?php else: ?>
    <div class="small"><?= e(setting('website_webhook_url', '') ?: '— not set —') ?></div>
  <?php endif; ?>
</div>

<div class="card p-4">
  <h6 class="mb-1">Payment Gateway — Cashfree</h6>
  <p class="text-muted small mb-3">Used by POS's "Pay via Cashfree" flow. From your Cashfree merchant dashboard: Developers &gt; API Keys.</p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="form_action" value="save_cashfree">
    <div class="row g-3 mb-3">
      <div class="col-sm-6">
        <label class="form-label">Client ID</label>
        <div class="input-group">
          <input type="text" name="cashfree_client_id" class="form-control" value="<?= e(cashfree_setting('CLIENT_ID')) ?>" <?= $canEdit ? '' : 'readonly' ?>>
          <?php if (cashfree_setting('CLIENT_ID')): ?><button class="btn btn-outline-secondary" type="button" data-copy-value="<?= e(cashfree_setting('CLIENT_ID')) ?>" title="Copy"><i class="fa-solid fa-copy"></i></button><?php endif; ?>
        </div>
      </div>
      <div class="col-sm-6">
        <label class="form-label">Client Secret</label>
        <div class="input-group">
          <input type="<?= cashfree_setting('CLIENT_SECRET') ? 'password' : 'text' ?>" name="cashfree_client_secret" class="form-control" value="<?= e(cashfree_setting('CLIENT_SECRET')) ?>" <?= $canEdit ? '' : 'readonly' ?> autocomplete="off">
        </div>
      </div>
      <div class="col-sm-6">
        <label class="form-label">Environment</label>
        <select name="cashfree_env" class="form-select" <?= $canEdit ? '' : 'disabled' ?>>
          <option value="sandbox" <?= cashfree_setting('ENV') !== 'production' ? 'selected' : '' ?>>Sandbox (testing)</option>
          <option value="production" <?= cashfree_setting('ENV') === 'production' ? 'selected' : '' ?>>Production (real payments)</option>
        </select>
      </div>
      <div class="col-sm-6">
        <label class="form-label">Product</label>
        <select name="cashfree_product" class="form-select" <?= $canEdit ? '' : 'disabled' ?>>
          <option value="orders" <?= cashfree_setting('PRODUCT') !== 'payment_link' ? 'selected' : '' ?>>Standard Checkout (default, works for any approved account)</option>
          <option value="payment_link" <?= cashfree_setting('PRODUCT') === 'payment_link' ? 'selected' : '' ?>>Payment Link (needs enabling by Cashfree support)</option>
        </select>
      </div>
    </div>
    <div class="small mb-3">
      <span class="dash-pill <?= cashfree_configured() ? 'dash-pill-green' : 'dash-pill-gray' ?>"><?= cashfree_configured() ? 'Configured' : 'Not configured' ?></span>
    </div>
    <?php if ($canEdit): ?><button type="submit" class="btn btn-brand">Save</button><?php endif; ?>
  </form>
</div>

</div>
<?php
$extra_js_inline = "
document.querySelectorAll('[data-reveal]').forEach(function(btn) {
  btn.addEventListener('click', function() {
    var input = btn.closest('.input-group').querySelector('input');
    if (input.value === input.dataset.secret) { input.value = input.dataset.masked; }
    else { input.value = input.dataset.secret; }
  });
});
document.querySelectorAll('[data-copy]').forEach(function(btn) {
  btn.addEventListener('click', function() {
    var input = btn.closest('.input-group').querySelector('input');
    var text = input.dataset.secret || input.value;
    navigator.clipboard.writeText(text).then(function() {
      var icon = btn.querySelector('i');
      icon.className = 'fa-solid fa-check';
      setTimeout(function() { icon.className = 'fa-solid fa-copy'; }, 1200);
    });
  });
});
document.querySelectorAll('[data-copy-value]').forEach(function(btn) {
  btn.addEventListener('click', function() {
    navigator.clipboard.writeText(btn.dataset.copyValue);
    var icon = btn.querySelector('i');
    icon.className = 'fa-solid fa-check';
    setTimeout(function() { icon.className = 'fa-solid fa-copy'; }, 1200);
  });
});
";
require __DIR__ . '/../includes/footer.php';
