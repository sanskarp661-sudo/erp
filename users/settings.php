<?php
require_once __DIR__ . '/../includes/auth.php';
require_admin_section();
$canEdit = can_edit_admin_section();

if (is_post()) {
    require_admin_edit();
    csrf_verify();
    $values = [
        'company_name' => input('company_name') ?: 'My Company',
        'currency_symbol' => input('currency_symbol') ?: '$',
        'tax_rate' => input('tax_rate') ?: '0',
        'company_address' => input('company_address'),
        'company_phone' => input('company_phone'),
        'company_email' => input('company_email'),
        'company_tax_id' => input('company_tax_id'),
    ];

    $stmt = db()->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    foreach ($values as $key => $value) {
        $stmt->execute([$key, $value]);
    }

    flash('success', 'Settings updated.');
    redirect('/users/settings.php');
}

$page_title = 'Settings';
require __DIR__ . '/../includes/header.php';
?>
<?php $ro = $canEdit ? '' : 'disabled'; ?>
<div class="card p-4" style="max-width:560px">
  <?php if (!$canEdit): ?><div class="alert alert-secondary">View only — you don't have permission to change settings.</div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <h6 class="mb-3">General</h6>
    <div class="mb-3">
      <label class="form-label">Company Name</label>
      <input type="text" name="company_name" class="form-control" value="<?= e(setting('company_name', APP_NAME)) ?>" <?= $ro ?>>
    </div>
    <div class="mb-3">
      <label class="form-label">Currency Symbol</label>
      <input type="text" name="currency_symbol" class="form-control" value="<?= e(setting('currency_symbol', '$')) ?>" <?= $ro ?>>
    </div>
    <div class="mb-3">
      <label class="form-label">Default Tax Rate (%)</label>
      <input type="number" step="0.01" min="0" name="tax_rate" class="form-control" value="<?= e(setting('tax_rate', '0')) ?>" <?= $ro ?>>
      <div class="form-text">Informational only — invoices still let you enter a specific tax amount per invoice.</div>
    </div>

    <hr class="my-4">
    <h6 class="mb-1">Company Details for Printed Documents</h6>
    <p class="small text-muted">Shown on invoice, purchase order, and salary slip print formats.</p>
    <div class="mb-3">
      <label class="form-label">Company Address</label>
      <textarea name="company_address" class="form-control" rows="2" <?= $ro ?>><?= e(setting('company_address', '')) ?></textarea>
    </div>
    <div class="row g-3 mb-3">
      <div class="col-sm-6">
        <label class="form-label">Phone</label>
        <input type="text" name="company_phone" class="form-control" value="<?= e(setting('company_phone', '')) ?>" <?= $ro ?>>
      </div>
      <div class="col-sm-6">
        <label class="form-label">Email</label>
        <input type="email" name="company_email" class="form-control" value="<?= e(setting('company_email', '')) ?>" <?= $ro ?>>
      </div>
    </div>
    <div class="mb-3">
      <label class="form-label">Tax ID / GSTIN</label>
      <input type="text" name="company_tax_id" class="form-control" value="<?= e(setting('company_tax_id', '')) ?>" <?= $ro ?>>
    </div>

    <?php if ($canEdit): ?><button type="submit" class="btn btn-brand">Save Settings</button><?php endif; ?>
  </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
