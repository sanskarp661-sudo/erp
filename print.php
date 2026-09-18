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
  body { padding: 40px; background: #eef0f3; color: #1f2937; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
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

  /* Bordered "letterhead" card that wraps the redesigned Standard templates */
  .pf-doc-card { background: #fff; border: 1px solid #dfe3e8; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 4px rgba(15, 23, 42, .06); }
  .pf-doc-body { padding: 28px 32px 32px; }

  /* Colored accent header band used at the top of the doc card */
  .pf-band-header { background: #eef2ff; border-bottom: 3px solid #4338ca; padding: 26px 32px; }
  .pf-band-header .pf-brand { color: #312e81; }
  .pf-band-header .pf-subtitle { color: #312e81; }
  .pf-band-header .pf-company-meta { color: #4c4f8a; }
  .pf-band-header .pf-meta-table th { color: #4c4f8a; }
  .pf-band-header .pf-meta-table td { color: #1e1b4b; }
  .pf-band-header.pf-center { text-align: center; }
  .pf-band-header.pf-header { margin-bottom: 0; }

  /* Company / document header block used by the redesigned invoice & PO templates */
  .pf-company-block .pf-brand { font-size: 1.5rem; }
  .pf-company-block .pf-company-meta { color: #6b7280; font-size: .85rem; line-height: 1.5; margin-top: 4px; }
  .pf-meta-table { border-collapse: collapse; margin-left: auto; }
  .pf-meta-table th, .pf-meta-table td { padding: 3px 8px; font-size: .88rem; }
  .pf-meta-table th { text-align: left; color: #6b7280; font-weight: 600; white-space: nowrap; }
  .pf-meta-table td { text-align: right; font-weight: 600; }

  /* Two-column label/value grid (payslip employee info, party details) */
  .pf-info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 24px; margin-bottom: 18px; font-size: .9rem; }
  .pf-info-grid .pf-info-row { display: flex; justify-content: space-between; border-bottom: 1px dashed #e5e7eb; padding: 4px 0; }
  .pf-info-grid .pf-info-label { color: #6b7280; }
  .pf-info-grid .pf-info-value { font-weight: 600; text-align: right; }

  /* Centered pill showing the document period */
  .pf-badge-period { display: inline-block; margin: 6px auto 0; padding: 4px 18px; border-radius: 999px; background: #eef2ff; color: #3730a3; font-weight: 700; font-size: .95rem; }
  .pf-center { text-align: center; }

  /* Side-by-side earnings/deductions tables */
  .pf-two-col { display: flex; gap: 16px; margin-bottom: 18px; }
  .pf-two-col > div { flex: 1; }
  .pf-two-col .pf-table { margin-bottom: 0; }

  /* Highlighted YTD summary box */
  .pf-ytd-box { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 6px; padding: 10px 14px; margin-bottom: 18px; font-size: .85rem; display: flex; justify-content: space-between; }
  .pf-ytd-box strong { display: block; font-size: .95rem; }

  .pf-words-line { font-style: italic; color: #374151; margin: 10px 0 18px; padding-top: 10px; border-top: 1px solid #dee2e6; }

  .pf-signature { display: flex; justify-content: space-between; margin-top: 60px; }
  .pf-signature .pf-sign-box { text-align: center; width: 200px; border-top: 1px solid #6b7280; padding-top: 6px; font-size: .85rem; color: #6b7280; }

  .pf-footer-note { text-align: center; color: #9ca3af; font-size: .78rem; margin-top: 24px; }

  /* Compact product tag / shelf-label card */
  .pf-tag-card { max-width: 340px; margin: 0 auto; background: #fff; border: 1px solid #dfe3e8; border-top: 4px solid #4338ca; border-radius: 10px; padding: 24px; text-align: center; box-shadow: 0 1px 4px rgba(15, 23, 42, .06); }
  .pf-tag-card .pf-brand { font-size: .95rem; color: #6b7280; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; }
  .pf-tag-card .pf-tag-name { font-size: 1.3rem; font-weight: 700; margin: 8px 0 2px; }
  .pf-tag-card .pf-tag-sku { font-family: ui-monospace, monospace; letter-spacing: .1em; color: #6b7280; font-size: .85rem; }
  .pf-tag-card .pf-tag-category { display: inline-block; margin: 10px 0; padding: 3px 12px; border-radius: 999px; background: #f1f5f9; color: #475569; font-size: .78rem; font-weight: 600; }
  .pf-tag-card .pf-tag-price { font-size: 2.4rem; font-weight: 800; color: #111827; margin: 10px 0 4px; }
  .pf-tag-card .pf-tag-unit { color: #6b7280; font-size: .85rem; }
  .pf-tag-card hr { margin: 14px 0; }
  .pf-tag-card .pf-tag-foot { display: flex; justify-content: space-between; font-size: .78rem; color: #6b7280; }

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
