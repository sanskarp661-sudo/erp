<?php
require_once __DIR__ . '/../includes/auth.php';
require_role(['admin']);
require_once __DIR__ . '/../includes/print_engine.php';

if (is_post() && input('action') === 'delete') {
    csrf_verify();
    $id = (int)input('id');
    db()->prepare('DELETE FROM print_formats WHERE id = ?')->execute([$id]);
    flash('success', 'Print format deleted.');
    redirect('/print_formats/index.php');
}

if (is_post() && input('action') === 'set_default') {
    csrf_verify();
    $id = (int)input('id');
    $stmt = db()->prepare('SELECT doctype FROM print_formats WHERE id = ?');
    $stmt->execute([$id]);
    $doctype = $stmt->fetchColumn();
    if ($doctype) {
        $pdo = db();
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE print_formats SET is_default = 0 WHERE doctype = ?')->execute([$doctype]);
        $pdo->prepare('UPDATE print_formats SET is_default = 1 WHERE id = ?')->execute([$id]);
        $pdo->commit();
        flash('success', 'Default print format updated.');
    }
    redirect('/print_formats/index.php');
}

$formats = db()->query('SELECT pf.*, u.name creator_name FROM print_formats pf LEFT JOIN users u ON u.id = pf.created_by ORDER BY pf.doctype, pf.name')->fetchAll();
$doctypes = pf_doctypes();

$page_title = 'Print Formats';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <p class="text-muted mb-0">Custom print formats you create here become selectable only on that document type's print page — e.g. a format created for Sales Invoice only appears when printing an invoice.</p>
  <a href="form.php" class="btn btn-brand text-nowrap ms-3"><i class="fa-solid fa-plus"></i> New Print Format</a>
</div>
<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-hover">
      <thead><tr><th>Name</th><th>Document Type</th><th>Created By</th><th>Default</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($formats as $f): ?>
        <tr>
          <td><?= e($f['name']) ?></td>
          <td><?= e($doctypes[$f['doctype']]['label'] ?? $f['doctype']) ?></td>
          <td><?= e($f['creator_name'] ?? 'System') ?></td>
          <td>
            <?php if ($f['is_default']): ?>
              <span class="badge text-bg-success">Default</span>
            <?php else: ?>
              <form method="post" class="d-inline">
                <?= csrf_field() ?><input type="hidden" name="action" value="set_default"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                <button class="btn btn-sm btn-outline-secondary" type="submit">Set as Default</button>
              </form>
            <?php endif; ?>
          </td>
          <td class="text-end">
            <a href="form.php?id=<?= (int)$f['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen"></i></a>
            <form method="post" class="d-inline" data-confirm="Delete this print format?">
              <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
              <button class="btn btn-sm btn-outline-danger" type="submit"><i class="fa-solid fa-trash"></i></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$formats): ?>
        <tr><td colspan="5" class="empty-state"><i class="fa-solid fa-palette"></i><div>No custom print formats yet — every document uses its built-in standard layout until you add one.</div></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
