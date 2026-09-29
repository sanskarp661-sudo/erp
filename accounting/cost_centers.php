<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
$page_title = 'Cost Centers';
fin_require_schema();

$pdo = db();
$canEdit = can_edit_module('finance');
$canManage = can_manage_module('finance');
$editId = (int)input('edit');
$errors = [];
$form = ['id' => 0, 'code' => '', 'name' => '', 'description' => '', 'status' => 'active'];

// Where a cost center can be referenced; used for usage counts and delete checks.
$usage = function (int $id) use ($pdo): int {
    $n = 0;
    foreach (['expenses', 'fin_journal_entries', 'fin_journal_lines', 'fin_budgets', 'ledger_accounts'] as $t) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM $t WHERE cost_center_id = ?");
        $stmt->execute([$id]);
        $n += (int)$stmt->fetchColumn();
    }
    return $n;
};

if (is_post()) {
    csrf_verify();
    $action = input('action');
    $id = (int)input('id');
    if ($action === 'delete') {
        require_module_manage('finance');
        $stmt = $pdo->prepare('SELECT name FROM fin_cost_centers WHERE id = ?');
        $stmt->execute([$id]);
        $name = $stmt->fetchColumn();
        if ($name === false) {
            flash('danger', 'Cost center not found.');
        } elseif ($usage($id) > 0) {
            flash('danger', "\"$name\" is used by existing entries. Mark it inactive instead.");
        } else {
            $pdo->prepare('DELETE FROM fin_cost_centers WHERE id = ?')->execute([$id]);
            if ((string)setting('fin_default_cost_center_id', '') === (string)$id) {
                $pdo->prepare("UPDATE settings SET setting_value = '' WHERE setting_key = 'fin_default_cost_center_id'")->execute();
            }
            log_activity('cost_center', $id, 'deleted', "Cost center '$name' deleted");
            flash('success', 'Cost center deleted.');
        }
        redirect('/accounting/cost_centers.php');
    }

    require_module_edit('finance');
    $form = [
        'id' => $id,
        'code' => strtoupper(trim((string)input('code'))),
        'name' => trim((string)input('name')),
        'description' => trim((string)input('description')),
        'status' => input('status') === 'inactive' ? 'inactive' : 'active',
    ];
    if ($form['name'] === '') $errors[] = 'Name is required.';
    $dup = $pdo->prepare('SELECT COUNT(*) FROM fin_cost_centers WHERE name = ? AND id <> ?');
    $dup->execute([$form['name'], $id]);
    if ($dup->fetchColumn() > 0) $errors[] = 'A cost center with this name already exists.';
    if ($form['code'] !== '') {
        $dup = $pdo->prepare('SELECT COUNT(*) FROM fin_cost_centers WHERE code = ? AND id <> ?');
        $dup->execute([$form['code'], $id]);
        if ($dup->fetchColumn() > 0) $errors[] = 'This code is already used.';
    }
    if (!$errors) {
        $vals = [$form['code'] ?: null, $form['name'], $form['description'] ?: null, $form['status']];
        if ($id) {
            $pdo->prepare('UPDATE fin_cost_centers SET code = ?, name = ?, description = ?, status = ? WHERE id = ?')->execute([...$vals, $id]);
            log_activity('cost_center', $id, 'updated', "Cost center '{$form['name']}' updated");
        } else {
            $pdo->prepare('INSERT INTO fin_cost_centers (code, name, description, status) VALUES (?,?,?,?)')->execute($vals);
            $id = (int)$pdo->lastInsertId();
            log_activity('cost_center', $id, 'created', "New cost center '{$form['name']}' added");
        }
        flash('success', 'Cost center saved.');
        redirect('/accounting/cost_centers.php');
    }
    $editId = $id;
} elseif ($editId) {
    $stmt = $pdo->prepare('SELECT * FROM fin_cost_centers WHERE id = ?');
    $stmt->execute([$editId]);
    $form = array_map(fn($v) => $v ?? '', $stmt->fetch() ?: $form);
}

$rows = $pdo->query('SELECT * FROM fin_cost_centers ORDER BY status, name')->fetchAll();
$defaultId = (string)setting('fin_default_cost_center_id', '');
$ytdFrom = fin_fy_start();
$spend = $pdo->prepare("SELECT cost_center_id, SUM(amount) FROM expenses WHERE status = 'approved' AND expense_date >= ? AND cost_center_id IS NOT NULL GROUP BY cost_center_id");
$spend->execute([$ytdFrom]);
$spend = $spend->fetchAll(PDO::FETCH_KEY_PAIR);

require __DIR__ . '/../includes/header.php';
fin_page_head('Cost Centers', 'Tag journals, expenses and budgets to see where money is spent.', '<a href="configuration.php" class="btn btn-outline-secondary"><i class="fa-solid fa-arrow-left"></i> Configuration</a>');
?>
<?php if ($errors): ?><div class="alert alert-danger"><?= implode('<br>', array_map('e', $errors)) ?></div><?php endif; ?>
<div class="row g-3">
  <div class="col-lg-8">
    <div class="fin-card">
      <div class="table-responsive">
        <table class="table fin-table">
          <thead><tr><th>Code</th><th>Name</th><th>Description</th><th class="text-end">Expenses (YTD)</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?= e($r['code'] ?? '') ?: '<span class="text-muted">—</span>' ?></td>
              <td><?= e($r['name']) ?><?= (string)$r['id'] === $defaultId ? ' ' . fin_pill('Default', 'info') : '' ?></td>
              <td class="small text-muted"><?= e($r['description'] ?? '') ?></td>
              <td class="text-end"><?= fin_num($spend[$r['id']] ?? 0) ?></td>
              <td><?= $r['status'] === 'active' ? fin_pill('Active', 'success') : fin_pill('Inactive', 'secondary') ?></td>
              <td class="text-end text-nowrap">
                <a class="btn btn-sm btn-outline-secondary" href="expenses.php?cc=<?= (int)$r['id'] ?>" title="View expenses"><i class="fa-solid fa-list"></i></a>
                <?php if ($canEdit): ?><a class="btn btn-sm btn-outline-secondary" href="?edit=<?= (int)$r['id'] ?>"><i class="fa-solid fa-pen"></i></a><?php endif; ?>
                <?php if ($canManage): ?><form method="post" class="d-inline" data-confirm="Delete this cost center?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-trash"></i></button></form><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$rows): ?><tr><td colspan="6" class="empty-state"><i class="fa-solid fa-diagram-project"></i>No cost centers yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="fin-card">
      <div class="fin-card-head"><h2 class="fin-card-title"><?= $editId ? 'Edit Cost Center' : 'Add Cost Center' ?></h2></div>
      <?php if ($canEdit): ?>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$form['id'] ?>">
        <div class="mb-3"><label class="form-label">Name <span class="text-danger">*</span></label><input type="text" name="name" class="form-control" required maxlength="120" value="<?= e($form['name']) ?>"></div>
        <div class="mb-3"><label class="form-label">Code</label><input type="text" name="code" class="form-control text-uppercase" maxlength="20" value="<?= e($form['code']) ?>" placeholder="HO"></div>
        <div class="mb-3"><label class="form-label">Description</label><input type="text" name="description" class="form-control" maxlength="255" value="<?= e($form['description']) ?>"></div>
        <div class="mb-3"><label class="form-label">Status</label><select name="status" class="form-select"><option value="active">Active</option><option value="inactive" <?= $form['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option></select></div>
        <div class="page-actions"><button class="btn btn-brand">Save</button><?php if ($editId): ?><a href="cost_centers.php" class="btn btn-outline-secondary">Cancel</a><?php endif; ?></div>
      </form>
      <?php else: ?><p class="text-muted small mb-0">You can view cost centers. Ask a Finance user to add or change them.</p><?php endif; ?>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php';
