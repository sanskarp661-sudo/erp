<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/print_engine.php';

$doctype = input('doctype');
$id = (int)input('id');
// Distinguish "no ?format= at all" (use the doctype's default) from an
// explicit "?format=0" (the "Standard (Built-in)" option was chosen,
// which must override the default) — both cast to the same falsy int.
$formatParam = isset($_GET['format']) ? (int)$_GET['format'] : null;

$doctypes = pf_doctypes();
if (!isset($doctypes[$doctype])) {
    die('Unknown document type.');
}
$config = $doctypes[$doctype];

if ($config['roles']) {
    require_role($config['roles']);
}

$tokens = ($config['fetch'])($id);
if ($tokens === null) {
    die('Record not found.');
}

$allFormats = db()->prepare('SELECT id, name, is_default FROM print_formats WHERE doctype = ? ORDER BY name');
$allFormats->execute([$doctype]);
$allFormats = $allFormats->fetchAll();

$selectedFormat = null;
if ($formatParam) {
    foreach ($allFormats as $f) {
        if ($f['id'] == $formatParam) { $selectedFormat = $f; break; }
    }
} elseif ($formatParam === null) {
    foreach ($allFormats as $f) {
        if ($f['is_default']) { $selectedFormat = $f; break; }
    }
}
// $formatParam === 0 (Standard explicitly chosen) falls through with
// $selectedFormat left null, which renders the built-in default below.

if ($selectedFormat) {
    $stmt = db()->prepare('SELECT html_template FROM print_formats WHERE id = ?');
    $stmt->execute([$selectedFormat['id']]);
    $template = $stmt->fetchColumn();
} else {
    $template = $config['default'];
}

$content = pf_render($template, $tokens);
$currentFormatId = $selectedFormat['id'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= e($config['label']) ?> · <?= e(setting('company_name', APP_NAME)) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<style>
  body { padding: 40px; color: #1f2937; }
  .pf-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px; }
  .pf-brand { font-size: 1.4rem; font-weight: 700; }
  .pf-subtitle { color: #6b7280; }
  .pf-right { text-align: right; }
  .pf-doc-no { font-size: 1.3rem; font-weight: 700; }
  .pf-section { margin-bottom: 18px; }
  .pf-table { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
  .pf-table th, .pf-table td { border: 1px solid #dee2e6; padding: 8px 10px; }
  .pf-table thead { background: #f8f9fa; }
  .pf-totals { width: 100%; max-width: 360px; margin-left: auto; border-collapse: collapse; }
  .pf-totals th, .pf-totals td { padding: 6px 8px; text-align: left; }
  .pf-totals td { text-align: right; }
  .pf-totals .pf-highlight { background: #fff3cd; font-weight: 700; font-size: 1.1rem; }
  .pf-toolbar { max-width: 800px; margin: 0 auto 24px; }
  .pf-content { max-width: 800px; margin: 0 auto; }
  @media print { .no-print { display: none; } body { padding: 0; } .pf-content { max-width: none; } }
</style>
</head>
<body>
  <div class="pf-toolbar no-print d-flex flex-wrap gap-2 align-items-center justify-content-between">
    <form method="get" class="d-flex gap-2 align-items-center">
      <input type="hidden" name="doctype" value="<?= e($doctype) ?>">
      <input type="hidden" name="id" value="<?= (int)$id ?>">
      <label class="small text-muted mb-0">Print Format</label>
      <select name="format" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
        <option value="0" <?= $currentFormatId === 0 ? 'selected' : '' ?>>Standard (Built-in)</option>
        <?php foreach ($allFormats as $f): ?>
          <option value="<?= (int)$f['id'] ?>" <?= $currentFormatId == $f['id'] ? 'selected' : '' ?>><?= e($f['name']) ?><?= $f['is_default'] ? ' (Default)' : '' ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <div class="d-flex gap-2">
      <?php if (is_admin()): ?>
        <a href="<?= base_url('print_formats/form.php?doctype=' . urlencode($doctype)) ?>" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-palette"></i> New Format for <?= e($config['label']) ?></a>
      <?php endif; ?>
      <?php if (input('pos') === '1'): ?>
        <a href="<?= base_url('pos/index.php') ?>" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-cash-register"></i> New Sale</a>
      <?php endif; ?>
      <button class="btn btn-sm btn-primary" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
    </div>
  </div>

  <div class="pf-content"><?= $content ?></div>
</body>
</html>
