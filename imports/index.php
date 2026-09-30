<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/importers.php';
require_login();

$registry = importer_registry();
$allowed = array_filter($registry, fn($imp) => can_manage_module($imp['permission_module']));

$batches = db()->query(
    "SELECT ib.*, u.name creator_name FROM import_batches ib
     LEFT JOIN users u ON u.id = ib.created_by
     ORDER BY ib.created_at DESC LIMIT 30"
)->fetchAll();

$page_title = 'Data Import';
require __DIR__ . '/../includes/header.php';
?>
<p class="text-muted">Bulk-import data from an Excel spreadsheet. Download a blank template for the entity you want, fill it in, then upload it — you'll see a preview of every row (and any errors) before anything is saved.</p>

<div class="row g-3 mb-4">
  <?php foreach ($allowed as $key => $imp): ?>
    <div class="col-md-4">
      <div class="card p-3 h-100">
        <h5 class="mb-1"><?= e($imp['label']) ?></h5>
        <p class="text-muted small mb-3"><?= e($imp['description']) ?></p>
        <div class="d-flex gap-2">
          <a href="upload.php?type=<?= e($key) ?>" class="btn btn-brand btn-sm"><i class="fa-solid fa-upload"></i> Import</a>
          <a href="template.php?type=<?= e($key) ?>" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-download"></i> Template</a>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (!$allowed): ?>
    <div class="col-12"><div class="empty-state"><i class="fa-solid fa-file-import"></i><div>You don't have import access to any entity type.</div></div></div>
  <?php endif; ?>
</div>

<div class="card p-3">
  <h5 class="mb-3">Recent Imports</h5>
  <div class="table-responsive">
    <table class="table table-hover">
      <thead><tr><th>File</th><th>Entity</th><th>Status</th><th class="text-end">Rows</th><th class="text-end">Success</th><th class="text-end">Errors</th><th>By</th><th>Date</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($batches as $b): ?>
        <tr>
          <td><?= e($b['file_name']) ?></td>
          <td><?= e($registry[$b['entity_type']]['label'] ?? $b['entity_type']) ?></td>
          <td>
            <?php
              $badge = ['pending' => 'secondary', 'validated' => 'info', 'processing' => 'warning', 'completed' => 'success', 'failed' => 'danger'][$b['status']] ?? 'secondary';
            ?>
            <span class="badge text-bg-<?= $badge ?>"><?= e(ucfirst($b['status'])) ?></span>
          </td>
          <td class="text-end"><?= (int)$b['total_rows'] ?></td>
          <td class="text-end"><?= (int)$b['success_count'] ?></td>
          <td class="text-end"><?= (int)$b['error_count'] ?></td>
          <td><?= e($b['creator_name'] ?? 'System') ?></td>
          <td><?= e(date('d M Y, h:i A', strtotime($b['created_at']))) ?></td>
          <td class="text-end"><a href="preview.php?batch_id=<?= (int)$b['id'] ?>" class="btn btn-sm btn-outline-secondary">View</a></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$batches): ?>
        <tr><td colspan="9" class="empty-state"><i class="fa-solid fa-file-import"></i><div>No imports yet.</div></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
