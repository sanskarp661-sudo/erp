<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/importers.php';
require_once __DIR__ . '/../includes/import_engine.php';
require_login();

$type = input('type');
$importer = get_importer($type);
if (!$importer) {
    flash('danger', 'Unknown import type.');
    redirect('/imports/index.php');
}
require_module_manage($importer['permission_module']);

$error = '';
$maxBytes = 10 * 1024 * 1024;
$allowedExt = ['xlsx', 'xls', 'csv'];

if (is_post()) {
    csrf_verify();
    $file = $_FILES['file'] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $error = 'Please choose a file to upload.';
    } elseif ($file['error'] !== UPLOAD_ERR_OK) {
        $error = 'File upload failed. Please try again.';
    } elseif ($file['size'] > $maxBytes) {
        $error = 'File must be smaller than 10 MB.';
    } else {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) {
            $error = 'Please upload an .xlsx, .xls, or .csv file.';
        } else {
            $destDir = __DIR__ . '/../uploads/imports/';
            if (!is_dir($destDir)) {
                mkdir($destDir, 0755, true);
            }
            $storedName = bin2hex(random_bytes(16)) . '.' . $ext;
            $storedFullPath = $destDir . $storedName;
            if (!move_uploaded_file($file['tmp_name'], $storedFullPath)) {
                $error = 'Could not save the uploaded file.';
            } else {
                try {
                    $parsed = import_read_spreadsheet($storedFullPath);
                    if (!$parsed['rows']) {
                        $error = 'That file has no data rows.';
                        unlink($storedFullPath);
                    } else {
                        $batchId = import_create_batch($type, $file['name'], 'uploads/imports/' . $storedName, $parsed['rows']);
                        redirect('/imports/preview.php?batch_id=' . $batchId);
                    }
                } catch (Exception $e) {
                    $error = 'Could not read that spreadsheet. Make sure it is a valid Excel or CSV file.';
                    @unlink($storedFullPath);
                }
            }
        }
    }
}

$page_title = 'Import ' . $importer['label'];
require __DIR__ . '/../includes/header.php';
?>
<nav class="mb-3"><a href="index.php">&larr; Data Import</a></nav>
<div class="card p-4" style="max-width: 640px">
  <h4 class="mb-1">Import <?= e($importer['label']) ?></h4>
  <p class="text-muted"><?= e($importer['description']) ?></p>
  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

  <p class="mb-1">
    Don't have a file yet? Download the <?= e($importer['label']) ?> template:
    <a href="template.php?type=<?= e($type) ?>&amp;sample=0">Blank</a>
    <?php if (!empty($importer['sample_rows'])): ?>
      &middot; <a href="template.php?type=<?= e($type) ?>&amp;sample=5">+5 sample rows</a>
      &middot; <a href="template.php?type=<?= e($type) ?>&amp;sample=50">+50 sample rows</a>
      &middot; <a href="template.php?type=<?= e($type) ?>&amp;sample=all">All records</a>
    <?php endif; ?>
  </p>
  <p class="text-muted small">
    The "sample" options fill the template with your own existing <?= e(strtolower($importer['label'])) ?> data —
    handy as a worked example, or as a starting point for a bulk edit (change values, then re-import).
    Required columns: <?php
      $required = array_map(fn($c) => $c['key'], array_filter($importer['columns'], fn($c) => $c['required']));
      echo e(implode(', ', $required));
    ?>.
  </p>

  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <div class="mb-3">
      <label class="form-label">Spreadsheet (.xlsx, .xls, or .csv)</label>
      <input type="file" name="file" class="form-control" accept=".xlsx,.xls,.csv" required>
    </div>
    <div class="page-actions">
      <button type="submit" class="btn btn-brand"><i class="fa-solid fa-upload"></i> Upload &amp; Preview</button>
      <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
    </div>
  </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
