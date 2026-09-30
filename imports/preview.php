<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/importers.php';
require_once __DIR__ . '/../includes/import_engine.php';
require_login();

$batchId = (int)input('batch_id');
$stmt = db()->prepare('SELECT * FROM import_batches WHERE id = ?');
$stmt->execute([$batchId]);
$batch = $stmt->fetch();
if (!$batch) {
    flash('danger', 'Import batch not found.');
    redirect('/imports/index.php');
}

$importer = get_importer($batch['entity_type']);
if (!$importer) {
    flash('danger', 'Unknown import type.');
    redirect('/imports/index.php');
}
require_module_manage($importer['permission_module']);

if (is_post() && input('action') === 'commit') {
    csrf_verify();
    if ($batch['status'] !== 'validated') {
        flash('danger', 'This batch has already been processed.');
        redirect('/imports/preview.php?batch_id=' . $batchId);
    }
    import_commit_batch($importer, $batchId);
    flash('success', 'Import finished.');
    redirect('/imports/preview.php?batch_id=' . $batchId);
}

if ($batch['status'] === 'pending') {
    import_validate_batch($importer, $batchId);
    $stmt->execute([$batchId]);
    $batch = $stmt->fetch();
}

$rowStmt = db()->prepare('SELECT * FROM import_batch_rows WHERE import_batch_id = ? ORDER BY row_num');
$rowStmt->execute([$batchId]);
$rows = $rowStmt->fetchAll();

$validRowCount = 0;
foreach ($rows as $r) {
    if ($r['status'] !== 'error') $validRowCount++;
}

$page_title = 'Preview Import';
require __DIR__ . '/../includes/header.php';
?>
<nav class="mb-3"><a href="index.php">&larr; Data Import</a></nav>
<div class="card p-4 mb-3">
  <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
    <div>
      <h4 class="mb-1"><?= e($importer['label']) ?> &mdash; <?= e($batch['file_name']) ?></h4>
      <p class="text-muted mb-0"><?= (int)$batch['total_rows'] ?> row<?= (int)$batch['total_rows'] === 1 ? '' : 's' ?> found.</p>
    </div>
    <?php if ($batch['status'] === 'validated'): ?>
      <form method="post" data-confirm="Import <?= $validRowCount ?> row(s) now? Rows with errors will be skipped.">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="commit">
        <button type="submit" class="btn btn-brand" <?= $validRowCount ? '' : 'disabled' ?>><i class="fa-solid fa-check"></i> Commit <?= $validRowCount ?> row(s)</button>
      </form>
    <?php elseif ($batch['status'] === 'completed'): ?>
      <span class="badge text-bg-success fs-6">Completed &mdash; <?= (int)$batch['success_count'] ?> succeeded, <?= (int)$batch['error_count'] ?> failed</span>
    <?php endif; ?>
  </div>
</div>

<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-hover">
      <thead><tr>
        <th>#</th>
        <?php foreach ($importer['columns'] as $c): ?><th><?= e($c['key']) ?></th><?php endforeach; ?>
        <th>Result</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $raw = json_decode($r['raw_data'], true) ?: []; ?>
        <tr class="<?= $r['status'] === 'error' ? 'table-danger' : ($r['status'] === 'success' ? 'table-success' : '') ?>">
          <td><?= (int)$r['row_num'] ?></td>
          <?php foreach ($importer['columns'] as $c): ?><td><?= e((string)($raw[$c['key']] ?? '')) ?></td><?php endforeach; ?>
          <td>
            <?php if ($r['status'] === 'error'): ?>
              <span class="text-danger"><i class="fa-solid fa-circle-exclamation"></i> <?= e($r['message']) ?></span>
            <?php elseif ($r['status'] === 'success'): ?>
              <span class="text-success"><i class="fa-solid fa-circle-check"></i> <?= e($r['message']) ?></span>
            <?php else: ?>
              <span class="text-muted">Ready</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
