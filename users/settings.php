<?php
require_once __DIR__ . '/../includes/auth.php';
require_role(['admin']);

if (is_post()) {
    csrf_verify();
    $companyName = input('company_name') ?: 'My Company';
    $currency = input('currency_symbol') ?: '$';
    $taxRate = input('tax_rate') ?: '0';

    $stmt = db()->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    $stmt->execute(['company_name', $companyName]);
    $stmt->execute(['currency_symbol', $currency]);
    $stmt->execute(['tax_rate', $taxRate]);

    flash('success', 'Settings updated.');
    redirect('/users/settings.php');
}

$page_title = 'Settings';
require __DIR__ . '/../includes/header.php';
?>
<div class="card p-4" style="max-width:520px">
  <form method="post">
    <?= csrf_field() ?>
    <div class="mb-3">
      <label class="form-label">Company Name</label>
      <input type="text" name="company_name" class="form-control" value="<?= e(setting('company_name', APP_NAME)) ?>">
    </div>
    <div class="mb-3">
      <label class="form-label">Currency Symbol</label>
      <input type="text" name="currency_symbol" class="form-control" value="<?= e(setting('currency_symbol', '$')) ?>">
    </div>
    <div class="mb-3">
      <label class="form-label">Default Tax Rate (%)</label>
      <input type="number" step="0.01" min="0" name="tax_rate" class="form-control" value="<?= e(setting('tax_rate', '0')) ?>">
      <div class="form-text">Informational only — invoices still let you enter a specific tax amount per invoice.</div>
    </div>
    <button type="submit" class="btn btn-brand">Save Settings</button>
  </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
