<?php
require_once __DIR__ . '/../includes/auth.php';
require_module_edit('inventory');
$canManage = can_manage_module('inventory');

// Only Item Master (products) uses custom fields today, but the two
// tables behind this page are generic (entity_type), so another doctype
// could reuse them later without a schema change.
const CF_ENTITY = 'product';

$FIELD_TYPES = ['text' => 'Text', 'number' => 'Number', 'date' => 'Date', 'select' => 'Dropdown', 'checkbox' => 'Checkbox', 'textarea' => 'Multi-line Text'];

function cf_slugify(string $label): string
{
    $slug = strtolower(trim($label));
    $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
    return trim($slug, '_') ?: 'field';
}

$editId = (int)input('edit');
$editField = null;
if ($editId) {
    $stmt = db()->prepare('SELECT * FROM custom_field_defs WHERE id = ? AND entity_type = ?');
    $stmt->execute([$editId, CF_ENTITY]);
    $editField = $stmt->fetch();
}

if (is_post() && input('form_action') === 'save') {
    csrf_verify();
    $id = (int)input('id');
    $label = trim(input('label'));
    $type = array_key_exists(input('field_type'), $FIELD_TYPES) ? input('field_type') : 'text';
    $options = trim(input('options'));
    $required = input('is_required') === '1' ? 1 : 0;
    $status = input('status') === 'inactive' ? 'inactive' : 'active';

    if ($label === '') {
        flash('danger', 'Label is required.');
        redirect('/inventory/custom_fields.php' . ($id ? '?edit=' . $id : ''));
    }

    try {
        if ($id) {
            db()->prepare('UPDATE custom_field_defs SET label=?, field_type=?, options=?, is_required=?, status=? WHERE id=? AND entity_type=?')
                ->execute([$label, $type, $options ?: null, $required, $status, $id, CF_ENTITY]);
            flash('success', 'Field updated.');
        } else {
            $key = cf_slugify($label);
            $base = $key;
            $n = 2;
            while (true) {
                $check = db()->prepare('SELECT 1 FROM custom_field_defs WHERE entity_type=? AND field_key=?');
                $check->execute([CF_ENTITY, $key]);
                if (!$check->fetch()) break;
                $key = $base . '_' . $n++;
            }
            $sortStmt = db()->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM custom_field_defs WHERE entity_type=?');
            $sortStmt->execute([CF_ENTITY]);
            $nextSort = (int)$sortStmt->fetchColumn();
            db()->prepare('INSERT INTO custom_field_defs (entity_type, field_key, label, field_type, options, is_required, sort_order, status) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([CF_ENTITY, $key, $label, $type, $options ?: null, $required, $nextSort, $status]);
            flash('success', 'Field added. It will now show on every item in Item Master.');
        }
    } catch (PDOException $e) {
        flash('danger', 'Could not save field.');
    }
    redirect('/inventory/custom_fields.php');
}

if (is_post() && input('form_action') === 'delete') {
    require_module_manage('inventory');
    csrf_verify();
    $id = (int)input('id');
    $pdo = db();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT field_key FROM custom_field_defs WHERE id=? AND entity_type=?');
    $stmt->execute([$id, CF_ENTITY]);
    $row = $stmt->fetch();
    if ($row) {
        $pdo->prepare('DELETE FROM custom_field_values WHERE entity_type=? AND field_key=?')->execute([CF_ENTITY, $row['field_key']]);
        $pdo->prepare('DELETE FROM custom_field_defs WHERE id=?')->execute([$id]);
        flash('success', 'Field deleted, along with any values stored for it on existing items.');
    }
    $pdo->commit();
    redirect('/inventory/custom_fields.php');
}

if (is_post() && in_array(input('form_action'), ['move_up', 'move_down'], true)) {
    require_module_manage('inventory');
    csrf_verify();
    $id = (int)input('id');
    $stmt = db()->prepare('SELECT * FROM custom_field_defs WHERE entity_type=? ORDER BY sort_order, id');
    $stmt->execute([CF_ENTITY]);
    $all = $stmt->fetchAll();
    $idx = null;
    foreach ($all as $i => $f) {
        if ((int)$f['id'] === $id) { $idx = $i; break; }
    }
    $swapWith = input('form_action') === 'move_up' ? $idx - 1 : $idx + 1;
    if ($idx !== null && isset($all[$swapWith])) {
        $a = $all[$idx];
        $b = $all[$swapWith];
        $pdo = db();
        $pdo->prepare('UPDATE custom_field_defs SET sort_order=? WHERE id=?')->execute([(int)$b['sort_order'], (int)$a['id']]);
        $pdo->prepare('UPDATE custom_field_defs SET sort_order=? WHERE id=?')->execute([(int)$a['sort_order'], (int)$b['id']]);
    }
    redirect('/inventory/custom_fields.php');
}

$fields = db()->prepare('SELECT * FROM custom_field_defs WHERE entity_type=? ORDER BY sort_order, id');
$fields->execute([CF_ENTITY]);
$fields = $fields->fetchAll();

$page_title = 'Custom Fields';
require __DIR__ . '/../includes/header.php';
?>
<div class="dash">
<div class="dash-head">
  <div>
    <nav class="inv-crumbs" aria-label="Breadcrumb"><a href="index.php">Inventory</a> <i class="fa-solid fa-chevron-right"></i> <span>Custom Fields</span></nav>
    <h1 class="dash-title">Custom Fields — Item Master</h1>
    <p class="dash-sub">Add your own fields to every item, without asking for a code change. New fields show up on the product form's Custom Fields tab right away.</p>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="card p-4">
      <h6 class="mb-3"><?= $editField ? 'Edit Field' : 'Add a Field' ?></h6>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="form_action" value="save">
        <input type="hidden" name="id" value="<?= $editField ? (int)$editField['id'] : 0 ?>">
        <div class="mb-3">
          <label class="form-label">Label *</label>
          <input type="text" name="label" class="form-control" required value="<?= e($editField['label'] ?? '') ?>" placeholder="e.g. Material, Warranty Period, Country of Origin">
        </div>
        <?php if ($editField): ?>
          <div class="mb-3">
            <label class="form-label">Field Key</label>
            <input type="text" class="form-control" value="<?= e($editField['field_key']) ?>" readonly>
            <div class="form-text">Fixed once created — this is what's stored against each item.</div>
          </div>
        <?php endif; ?>
        <div class="mb-3">
          <label class="form-label">Field Type</label>
          <select name="field_type" class="form-select">
            <?php foreach ($FIELD_TYPES as $val => $label): ?>
              <option value="<?= $val ?>" <?= ($editField['field_type'] ?? 'text') === $val ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label">Options (for Dropdown only)</label>
          <textarea name="options" class="form-control" rows="3" placeholder="One option per line"><?= e($editField['options'] ?? '') ?></textarea>
        </div>
        <div class="form-check mb-3">
          <input type="checkbox" class="form-check-input" id="cfRequired" name="is_required" value="1" <?= !empty($editField['is_required']) ? 'checked' : '' ?>>
          <label class="form-check-label" for="cfRequired">Required on every item</label>
        </div>
        <div class="mb-3">
          <label class="form-label">Status</label>
          <select name="status" class="form-select">
            <option value="active" <?= ($editField['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active — shows on the item form</option>
            <option value="inactive" <?= ($editField['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive — hidden, values kept</option>
          </select>
        </div>
        <div class="d-flex gap-2">
          <button type="submit" class="btn btn-brand"><?= $editField ? 'Save Changes' : 'Add Field' ?></button>
          <?php if ($editField): ?><a href="custom_fields.php" class="btn btn-outline-secondary">Cancel</a><?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="card p-0">
      <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
          <thead><tr><th></th><th>Label</th><th>Type</th><th>Required</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
          <tbody>
          <?php foreach ($fields as $i => $f): ?>
            <tr>
              <td class="text-nowrap">
                <?php if ($canManage): ?>
                <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="form_action" value="move_up"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                  <button class="btn btn-sm btn-light" type="submit" <?= $i === 0 ? 'disabled' : '' ?> title="Move up"><i class="fa-solid fa-chevron-up"></i></button>
                </form>
                <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="form_action" value="move_down"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                  <button class="btn btn-sm btn-light" type="submit" <?= $i === count($fields) - 1 ? 'disabled' : '' ?> title="Move down"><i class="fa-solid fa-chevron-down"></i></button>
                </form>
                <?php endif; ?>
              </td>
              <td>
                <div class="fw-semibold"><?= e($f['label']) ?></div>
                <div class="text-muted small"><?= e($f['field_key']) ?></div>
              </td>
              <td><?= e($FIELD_TYPES[$f['field_type']] ?? $f['field_type']) ?></td>
              <td><?= $f['is_required'] ? '<span class="dash-pill dash-pill-orange">Required</span>' : '<span class="text-muted">Optional</span>' ?></td>
              <td><span class="dash-pill <?= $f['status'] === 'active' ? 'dash-pill-green' : 'dash-pill-gray' ?>"><?= $f['status'] === 'active' ? 'Active' : 'Inactive' ?></span></td>
              <td class="text-end text-nowrap">
                <a href="custom_fields.php?edit=<?= (int)$f['id'] ?>" class="btn btn-sm btn-light" title="Edit"><i class="fa-solid fa-pen"></i></a>
                <?php if ($canManage): ?>
                  <form method="post" class="d-inline" data-confirm="Delete '<?= e($f['label']) ?>'? This also removes its saved value from every item.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="form_action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                    <button class="btn btn-sm btn-light text-danger" type="submit" title="Delete"><i class="fa-solid fa-trash"></i></button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$fields): ?>
            <tr><td colspan="6" class="empty-state">
              <i class="fa-solid fa-sliders"></i>
              <div>No custom fields yet. Add one on the left to see it appear on the item form.</div>
            </td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
