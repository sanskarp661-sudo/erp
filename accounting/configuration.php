<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
$page_title = 'Configuration';
fin_require_schema();

$pdo = db();
$canManage = can_manage_module('finance');
$costCenters = $pdo->query("SELECT id, name FROM fin_cost_centers WHERE status = 'active' ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);
$monthNames = [];
for ($m = 1; $m <= 12; $m++) $monthNames[$m] = date('F', mktime(0, 0, 0, $m, 1));

// Editable settings, grouped by the section that edits them: key => [label, default].
$sections = [
    'preferences' => ['Accounting Preferences', [
        'fin_fy_start_month' => ['Fiscal year starts in', '4'],
        'fin_default_cost_center_id' => ['Default cost center', ''],
    ]],
    'tax' => ['Tax Configuration', [
        'fin_tds_applicable' => ['TDS applicable', '1'],
    ]],
    'numbering' => ['Document Numbering', [
        'fin_prefix_journal' => ['Journal Entry prefix', 'JV'],
        'fin_prefix_bank_payment' => ['Bank Payment prefix', 'BP'],
        'fin_prefix_bank_receipt' => ['Bank Receipt prefix', 'BR'],
        'fin_prefix_cash_payment' => ['Cash Payment prefix', 'CP'],
        'fin_prefix_cash_receipt' => ['Cash Receipt prefix', 'CR'],
        'fin_prefix_contra' => ['Contra prefix', 'CT'],
        'fin_prefix_expense' => ['Expense prefix', 'EXP'],
        'fin_number_padding' => ['Number of digits', '4'],
    ]],
    'approvals' => ['Approval Workflows', [
        'fin_expense_approval_limit' => ['Expense approval limit', '0'],
    ]],
];
$section = isset($sections[input('section')]) || in_array(input('section'), ['audit', 'integrations'], true) ? input('section') : '';

$display = function (string $key, $value) use ($costCenters, $monthNames): string {
    switch ($key) {
        case 'fin_fy_start_month': return $monthNames[(int)$value] ?? (string)$value;
        case 'fin_default_cost_center_id': return $costCenters[(int)$value] ?? 'None';
        case 'fin_tds_applicable': return $value === '1' ? 'Yes' : 'No';
        case 'fin_expense_approval_limit': return (float)$value > 0 ? fin_money($value) : 'No approval needed';
        default: return (string)$value;
    }
};

$errors = [];
if (is_post() && $section && $section !== 'audit') {
    require_module_manage('finance');
    csrf_verify();
    $new = [];
    foreach ($sections[$section][1] as $key => [$label, $default]) {
        $v = trim((string)input($key));
        switch ($key) {
            case 'fin_fy_start_month':
                if (!isset($monthNames[(int)$v])) $errors[] = 'Pick a valid month.';
                $v = (string)(int)$v;
                break;
            case 'fin_default_cost_center_id':
                $v = isset($costCenters[(int)$v]) ? (string)(int)$v : '';
                break;
            case 'fin_tds_applicable':
                $v = input($key) ? '1' : '0';
                break;
            case 'fin_number_padding':
                if ((int)$v < 3 || (int)$v > 8) $errors[] = 'Number of digits must be between 3 and 8.';
                $v = (string)(int)$v;
                break;
            case 'fin_expense_approval_limit':
                if ((float)$v < 0) $errors[] = 'Approval limit cannot be negative.';
                $v = (string)round((float)$v, 2);
                break;
            default: // prefixes
                $v = strtoupper($v);
                if (!preg_match('/^[A-Z0-9]{1,6}$/', $v)) $errors[] = $label . ' must be 1-6 letters or digits.';
        }
        $new[$key] = $v;
    }
    if ($section === 'numbering') {
        $prefixes = array_filter($new, fn($k) => str_starts_with($k, 'fin_prefix_'), ARRAY_FILTER_USE_KEY);
        if (count(array_unique($prefixes)) < count($prefixes)) $errors[] = 'Each document type needs its own prefix.';
    }
    if (!$errors) {
        $stmt = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        $changed = 0;
        foreach ($new as $key => $v) {
            $old = (string)setting($key, $sections[$section][1][$key][1]);
            if ($old === $v) continue;
            $stmt->execute([$key, $v]);
            $label = $sections[$section][1][$key][0];
            log_activity('fin_settings', 0, 'updated', 'Changed ' . (ctype_upper(substr($label, 1, 1)) ? $label : lcfirst($label)) . ' from ' . $display($key, $old) . ' to ' . $display($key, $v), $key, $old, $v);
            $changed++;
        }
        flash('success', $changed ? $sections[$section][0] . ' saved.' : 'Nothing changed.');
        redirect('/accounting/configuration.php');
    }
}

// Finance audit trail: every finance record type, newest first.
$entityModules = [
    'fin_settings' => 'Accounting Preferences', 'ledger_account' => 'Chart of Accounts', 'journal' => 'Journal Entries',
    'expense' => 'Expenses', 'tax_filing' => 'Tax & Compliance', 'budget' => 'Budget & Planning',
    'cost_center' => 'Cost Centers', 'bank_reconciliation' => 'Bank Reconciliation',
];
$settingSection = [];
foreach ($sections as [$title, $fields]) foreach ($fields as $key => $_) $settingSection[$key] = $title;
$in = implode(',', array_map([$pdo, 'quote'], array_keys($entityModules)));
$lastUpdated = $pdo->query("SELECT MAX(created_at) FROM activity_log WHERE entity_type IN ('fin_settings', 'cost_center', 'ledger_account')")->fetchColumn();

if ($section === 'audit') {
    [$page, $perPage, $offset] = fin_page(20);
    $total = (int)$pdo->query("SELECT COUNT(*) FROM activity_log WHERE entity_type IN ($in)")->fetchColumn();
    $log = $pdo->query("SELECT a.*, u.name user_name FROM activity_log a LEFT JOIN users u ON u.id = a.actor_id WHERE a.entity_type IN ($in) ORDER BY a.id DESC LIMIT $perPage OFFSET $offset")->fetchAll();
} else {
    $log = $pdo->query("SELECT a.*, u.name user_name FROM activity_log a LEFT JOIN users u ON u.id = a.actor_id WHERE a.entity_type IN ($in) ORDER BY a.id DESC LIMIT 5")->fetchAll();
}

$fyStart = fin_fy_start();
$cards = [
    ['Organization Settings', 'Company details, address, currency and GSTIN', 'fa-solid fa-building', 'blue', base_url('users/settings.php')],
    ['Chart of Accounts', 'Manage account groups, ledgers and account structure', 'fa-solid fa-sitemap', 'green', 'ledger_accounts.php'],
    ['Accounting Preferences', 'Fiscal year and default cost center', 'fa-solid fa-book-open', 'purple', '?section=preferences'],
    ['Tax Configuration', 'GST templates, TDS and other tax settings', 'fa-solid fa-file-invoice', 'red', '?section=tax'],
    ['Document Numbering', 'Define prefixes, series and numbering formats', 'fa-solid fa-list-ol', 'orange', '?section=numbering'],
    ['Cost Center & Profit Center', 'Manage cost centers used for allocation and reports', 'fa-solid fa-diagram-project', 'blue', 'cost_centers.php'],
    ['User Roles & Permissions', 'Manage users, roles and access rights', 'fa-solid fa-users', 'purple', base_url('users/users.php')],
    ['Approval Workflows', 'Set up approval rules for expenses', 'fa-solid fa-file-circle-check', 'green', '?section=approvals'],
    ['Integrations', 'Payment gateway and other connected systems', 'fa-solid fa-share-nodes', 'red', '?section=integrations'],
];

$auditBtn = '<a href="?section=audit" class="btn btn-outline-brand"><i class="fa-solid fa-clock-rotate-left"></i> Audit Log</a>';
$lastChip = '<span class="fin-chip"><i class="fa-solid fa-gear"></i> <span><small class="d-block text-muted" style="font-size:.72rem">Last updated</small>'
    . e($lastUpdated ? date('d M Y, h:i A', strtotime($lastUpdated)) : 'Not changed yet') . '</span></span>';

require __DIR__ . '/../includes/header.php';
fin_page_head('Configuration', 'Set up and manage your finance configurations to match your business needs.', '<div class="d-flex gap-2 flex-wrap align-items-center">' . $lastChip . $auditBtn . '</div>');
?>
<?php if ($errors): ?><div class="alert alert-danger"><?= implode('<br>', array_map('e', $errors)) ?></div><?php endif; ?>

<?php if ($section && $section !== 'audit' && $section !== 'integrations'): [$secTitle, $fields] = $sections[$section]; $ro = $canManage ? '' : 'disabled'; ?>
<div class="fin-card mb-3">
  <div class="fin-card-head"><h2 class="fin-card-title"><?= e($secTitle) ?></h2><a href="configuration.php" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-xmark"></i> Close</a></div>
  <?php if (!$canManage): ?><div class="alert alert-secondary small py-2">View only. A Finance manager can change these settings.</div><?php endif; ?>
  <form method="post" action="?section=<?= e($section) ?>">
    <?= csrf_field() ?>
    <div class="row g-3">
    <?php foreach ($fields as $key => [$label, $default]): $v = is_post() ? (string)input($key) : (string)setting($key, $default); ?>
      <?php if ($key === 'fin_fy_start_month'): ?>
        <div class="col-sm-4"><label class="form-label"><?= e($label) ?></label>
          <select name="<?= $key ?>" class="form-select" <?= $ro ?>><?php foreach ($monthNames as $m => $n): ?><option value="<?= $m ?>" <?= (int)$v === $m ? 'selected' : '' ?>><?= $n ?></option><?php endforeach; ?></select>
          <div class="form-text">Current year: <?= fin_date($fyStart) ?> to <?= fin_date(fin_fy_end($fyStart)) ?>.</div></div>
      <?php elseif ($key === 'fin_default_cost_center_id'): ?>
        <div class="col-sm-4"><label class="form-label"><?= e($label) ?></label>
          <select name="<?= $key ?>" class="form-select" <?= $ro ?>><option value="">None</option><?php foreach ($costCenters as $id => $n): ?><option value="<?= (int)$id ?>" <?= (string)$id === $v ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select>
          <div class="form-text">Pre-filled on new journals and expenses.</div></div>
      <?php elseif ($key === 'fin_tds_applicable'): ?>
        <div class="col-sm-4"><div class="form-check form-switch mt-4"><input class="form-check-input" type="checkbox" role="switch" id="tdsSw" name="<?= $key ?>" value="1" <?= $v === '1' ? 'checked' : '' ?> <?= $ro ?>>
          <label class="form-check-label" for="tdsSw"><?= e($label) ?></label></div><div class="form-text">Shows TDS payments in the compliance calendar.</div></div>
      <?php elseif ($key === 'fin_expense_approval_limit'): ?>
        <div class="col-sm-4"><label class="form-label"><?= e($label) ?></label>
          <div class="input-group"><span class="input-group-text"><?= e(setting('currency_symbol', '₹')) ?></span><input type="number" step="0.01" min="0" name="<?= $key ?>" class="form-control" value="<?= e($v) ?>" <?= $ro ?>></div>
          <div class="form-text">Expenses above this amount, entered by someone who isn't a Finance manager, wait for approval before posting. 0 turns approvals off.</div></div>
      <?php elseif ($key === 'fin_number_padding'): ?>
        <div class="col-sm-3"><label class="form-label"><?= e($label) ?></label><input type="number" min="3" max="8" name="<?= $key ?>" id="padInput" class="form-control" value="<?= e($v) ?>" <?= $ro ?>></div>
      <?php else: ?>
        <div class="col-sm-3"><label class="form-label"><?= e($label) ?></label><input type="text" name="<?= $key ?>" class="form-control text-uppercase prefix-input" maxlength="6" value="<?= e($v) ?>" <?= $ro ?>>
          <div class="form-text">e.g. <span class="prefix-preview"></span></div></div>
      <?php endif; ?>
    <?php endforeach; ?>
    </div>
    <?php if ($section === 'tax'): ?>
      <div class="fin-callout mt-3"><div>GSTIN is set in <a href="<?= e(base_url('users/settings.php')) ?>">Organization Settings</a>, and GST rates live in <a href="<?= e(base_url('sales/tax_templates.php')) ?>">Sales Tax Templates</a>. Filings and due dates are tracked in <a href="tax_compliance.php">Tax &amp; Compliance</a>.</div></div>
    <?php elseif ($section === 'numbering'): ?>
      <p class="small text-muted mt-3 mb-0">New documents are numbered PREFIX-YEAR-NNNN. Changing a prefix only affects documents created afterwards.</p>
    <?php endif; ?>
    <?php if ($canManage): ?><div class="page-actions mt-3"><button class="btn btn-brand">Save Settings</button><a href="configuration.php" class="btn btn-outline-secondary">Cancel</a></div><?php endif; ?>
  </form>
</div>
<?php elseif ($section === 'integrations'): ?>
<div class="fin-card mb-3">
  <div class="fin-card-head"><h2 class="fin-card-title">Integrations</h2><a href="configuration.php" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-xmark"></i> Close</a></div>
  <div class="table-responsive"><table class="table fin-table">
    <thead><tr><th>System</th><th>Used for</th><th>Status</th></tr></thead>
    <tbody>
      <tr><td>Cashfree Payments</td><td>UPI and card collections at the POS</td>
        <td><?= function_exists('cashfree_configured') && cashfree_configured() ? fin_pill('Connected', 'success') : fin_pill('Not configured', 'secondary') ?></td></tr>
    </tbody>
  </table></div>
  <p class="small text-muted mb-0">Gateway keys are set by your administrator in the server configuration file, never in the database.</p>
</div>
<?php endif; ?>

<?php if ($section !== 'audit'): ?>
<div class="row g-3 mb-3">
  <?php foreach ($cards as [$t, $d, $icon, $tone, $href]): ?>
    <div class="col-md-6 col-xl-4">
      <a class="fin-config-card" href="<?= e($href) ?>"><span class="fin-kpi-icon tone-<?= $tone ?>"><i class="<?= $icon ?>"></i></span>
        <span><strong><?= e($t) ?></strong><small><?= e($d) ?></small></span><i class="fa-solid fa-chevron-right chev"></i></a>
    </div>
  <?php endforeach; ?>
</div>
<div class="fin-card mb-3">
  <div class="fin-card-head"><h2 class="fin-card-title">Key Configuration Details</h2>
    <?php if ($canManage): ?><a href="?section=preferences" class="btn btn-sm btn-outline-brand"><i class="fa-solid fa-pen"></i> Edit Settings</a><?php endif; ?></div>
  <div class="row g-4">
    <?php
    $prefixes = array_map(fn($k) => setting('fin_prefix_' . $k, ''), ['journal', 'bank_payment', 'expense']);
    $details = [
        'Company Name' => setting('company_name', APP_NAME),
        'Financial Year' => fin_date($fyStart) . ' - ' . fin_date(fin_fy_end($fyStart)),
        'Base Currency' => setting('currency_code', 'INR') . ' (' . setting('currency_symbol', '₹') . ')',
        'Accounting Method' => 'Accrual',
        'GSTIN' => setting('company_tax_id', '') ?: 'Not set',
        'TDS Applicable' => setting('fin_tds_applicable', '1') === '1' ? 'Yes' : 'No',
        'Default Cost Center' => $costCenters[(int)setting('fin_default_cost_center_id', '')] ?? 'None',
        'Document Numbering' => 'Auto (' . implode(', ', array_filter($prefixes)) . ')',
    ];
    foreach ($details as $k => $v): ?>
      <div class="col-sm-6 col-lg-3 fin-kv"><div class="k"><?= e($k) ?></div><div class="v"><?= e($v) ?></div></div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="fin-card">
  <div class="fin-card-head"><h2 class="fin-card-title"><?= $section === 'audit' ? 'Finance Audit Log' : 'Configuration Activity Log' ?></h2>
    <?php if ($section === 'audit'): ?><a href="configuration.php" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-arrow-left"></i> Back</a>
    <?php else: ?><a class="fin-link" href="?section=audit">View All <i class="fa-solid fa-arrow-right"></i></a><?php endif; ?></div>
  <div class="table-responsive">
    <table class="table fin-table">
      <thead><tr><th>Date &amp; Time</th><th>User</th><th>Module</th><th>Action</th><th>Details</th></tr></thead>
      <tbody>
      <?php foreach ($log as $a): ?>
        <tr>
          <td class="text-nowrap"><?= e(date('d M Y, h:i A', strtotime($a['created_at']))) ?></td>
          <td><?= e($a['user_name'] ?? 'System') ?></td>
          <td><?= e($a['entity_type'] === 'fin_settings' && isset($settingSection[$a['field_name']]) ? $settingSection[$a['field_name']] : ($entityModules[$a['entity_type']] ?? $a['entity_type'])) ?></td>
          <td><?= e(ucfirst(str_replace('_', ' ', $a['action']))) ?></td>
          <td><?= e($a['description'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$log): ?><tr><td colspan="5" class="empty-state"><i class="fa-solid fa-clock-rotate-left"></i>Changes to finance settings and records will be listed here.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($section === 'audit') echo fin_pagination($total, $page, $perPage, 'entries'); ?>
</div>
<?php
$extra_js_inline = "
document.addEventListener('DOMContentLoaded', function () {
  var pad = document.getElementById('padInput');
  function preview() {
    var n = pad ? Math.min(8, Math.max(3, +pad.value || 4)) : 4;
    document.querySelectorAll('.prefix-input').forEach(function (i) {
      i.parentNode.querySelector('.prefix-preview').textContent = (i.value.toUpperCase() || 'XX') + '-' + new Date().getFullYear() + '-' + '1'.padStart(n, '0');
    });
  }
  document.querySelectorAll('.prefix-input').forEach(function (i) { i.addEventListener('input', preview); });
  if (pad) pad.addEventListener('input', preview);
  preview();
});";
require __DIR__ . '/../includes/footer.php';
