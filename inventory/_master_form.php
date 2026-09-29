<?php
/**
 * Shared add/edit form page for the simple Inventory masters. The including page sets
 * $cfg first; see includes/inventory_masters.php for its keys.
 */
require_once __DIR__ . '/../includes/inventory_masters.php';
require_login();
if (!isset($cfg)) {
    http_response_code(404);
    exit;
}

    require_module_edit('inventory');
    $table = $cfg['table'];
    $id = (int)input('id');
    $row = ['id' => 0, 'name' => '', 'description' => '', 'status' => 'active'];
    if ($id) {
        $stmt = db()->prepare("SELECT * FROM $table WHERE id = ?");
        $stmt->execute([$id]);
        $found = $stmt->fetch();
        if (!$found) {
            flash('danger', $cfg['singular'] . ' not found.');
            redirect('/inventory/' . $cfg['list']);
        }
        $row = array_merge($row, $found);
    }
    $oldName = $row['name'];
    $error = '';

    if (is_post()) {
        csrf_verify();
        $row['name'] = trim((string)input('name'));
        if ($cfg['description']) $row['description'] = trim((string)input('description'));
        if ($cfg['status']) $row['status'] = input('status') === 'inactive' ? 'inactive' : 'active';

        $dup = db()->prepare("SELECT COUNT(*) FROM $table WHERE name = ? AND id <> ?");
        $dup->execute([$row['name'], $id]);
        if ($row['name'] === '') {
            $error = $cfg['singular'] . ' name is required.';
        } elseif ($dup->fetchColumn() > 0) {
            $error = 'A ' . strtolower($cfg['singular']) . ' called "' . $row['name'] . '" already exists.';
        } else {
            $cols = ['name' => $row['name']];
            if ($cfg['description']) $cols['description'] = $row['description'];
            if ($cfg['status']) $cols['status'] = $row['status'];
            try {
                $pdo = db();
                $pdo->beginTransaction();
                if ($id) {
                    $set = implode(', ', array_map(fn($c) => "$c = ?", array_keys($cols)));
                    $pdo->prepare("UPDATE $table SET $set WHERE id = ?")->execute([...array_values($cols), $id]);
                    if (!$cfg['fk'] && $row['name'] !== $oldName) {
                        // products.unit stores the unit's name, so follow a rename.
                        $pdo->prepare('UPDATE products SET unit = ? WHERE unit = ?')->execute([$row['name'], $oldName]);
                    }
                } else {
                    $pdo->prepare("INSERT INTO $table (" . implode(', ', array_keys($cols)) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
                        ->execute(array_values($cols));
                }
                $pdo->commit();
                flash('success', $cfg['singular'] . ($id ? ' updated.' : ' created.'));
                redirect('/inventory/' . $cfg['list']);
            } catch (PDOException $e) {
                if (db()->inTransaction()) db()->rollBack();
                $error = 'Could not save ' . strtolower($cfg['singular']) . '.';
            }
        }
    }

    $usageRow = $id ? (inv_master_usage($cfg, [['id' => $id, 'name' => $oldName]])[$id] ?? null) : null;
    $usedBy = $id ? inv_master_products($cfg, ['id' => $id, 'name' => $oldName]) : [];

        $page_title = ($id ? 'Edit ' : 'Add ') . $cfg['singular'];
    require __DIR__ . '/../includes/header.php';
    ?>
<div class="dash-head inv-doc-head">
  <div>
    <nav class="inv-crumbs" aria-label="Breadcrumb">
      <a href="index.php">Inventory</a> <i class="fa-solid fa-chevron-right"></i>
      <a href="<?= e($cfg['list']) ?>"><?= e($cfg['title']) ?></a> <i class="fa-solid fa-chevron-right"></i>
      <span><?= $id ? 'Edit' : 'New' ?></span>
    </nav>
    <h1 class="dash-title"><?= $id ? e($oldName) : 'New ' . e($cfg['singular']) ?>
      <?php if ($cfg['status'] && $id): ?><span class="dash-pill <?= $row['status'] === 'active' ? 'dash-pill-green' : 'dash-pill-gray' ?>"><?= $row['status'] === 'active' ? 'Active' : 'Inactive' ?></span><?php endif; ?>
    </h1>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card p-4 inv-doc">
      <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
      <form method="post" id="masterForm">
        <?= csrf_field() ?>
        <h6 class="mb-1"><?= e($cfg['singular']) ?> Details</h6>
        <p class="text-muted small mb-3">Used to group and filter products across inventory, sales and purchasing.</p>
        <div class="mb-3">
          <label class="form-label">Name <span class="text-danger">*</span></label>
          <input type="text" name="name" class="form-control" required maxlength="120" value="<?= e($row['name']) ?>" placeholder="<?= e($cfg['placeholder'] ?? '') ?>" autofocus>
          <?php if (!$cfg['fk'] && $id): ?><div class="form-text">Renaming a unit also updates every product that uses it.</div><?php endif; ?>
        </div>
        <?php if ($cfg['description']): ?>
        <div class="mb-3">
          <label class="form-label">Description</label>
          <textarea name="description" class="form-control" rows="3" maxlength="255"><?= e($row['description']) ?></textarea>
        </div>
        <?php endif; ?>
        <?php if ($cfg['status']): ?>
        <div class="mb-3">
          <label class="form-label">Status</label>
          <div class="d-flex gap-2">
            <input type="radio" class="btn-check" name="status" id="stActive" value="active" <?= $row['status'] === 'active' ? 'checked' : '' ?>>
            <label class="btn btn-outline-success btn-sm" for="stActive"><i class="fa-solid fa-circle-check"></i> Active</label>
            <input type="radio" class="btn-check" name="status" id="stInactive" value="inactive" <?= $row['status'] === 'inactive' ? 'checked' : '' ?>>
            <label class="btn btn-outline-secondary btn-sm" for="stInactive"><i class="fa-solid fa-circle-pause"></i> Inactive</label>
          </div>
          <div class="form-text"><?= e($cfg['status_help'] ?? 'Inactive entries stay on products that already use them but are hidden when picking for new products.') ?></div>
        </div>
        <?php endif; ?>
        <div class="page-actions inv-save-bar">
          <a href="<?= e($cfg['list']) ?>" class="btn btn-outline-secondary">Cancel</a>
          <button type="submit" class="btn btn-brand"><i class="fa-solid fa-floppy-disk"></i> Save</button>
        </div>
      </form>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="dash-card">
      <div class="dash-card-head"><h2>Usage</h2>
        <?php if ($usageRow && $usageRow['products']): ?><a href="<?= e(inv_master_filter_url($cfg, ['id' => $id, 'name' => $oldName])) ?>" class="dash-link">View products <i class="fa-solid fa-arrow-right"></i></a><?php endif; ?>
      </div>
      <?php if ($id): ?>
        <div class="inv-facts mt-0 mb-3">
          <div><span>Products</span><strong><?= (int)($usageRow['products'] ?? 0) ?></strong></div>
          <div><span>Stock Value</span><strong><?= money($usageRow['value'] ?? 0) ?></strong></div>
        </div>
        <?php foreach ($usedBy as $p): ?>
          <a href="product_view.php?id=<?= (int)$p['id'] ?>" class="inv-wh d-flex align-items-center gap-2">
            <span class="dash-thumb"><?php if (!empty($p['image'])): ?><img src="<?= base_url($p['image']) ?>" alt=""><?php else: ?><i class="fa-solid fa-box"></i><?php endif; ?></span>
            <span class="flex-grow-1"><span class="d-block fw-semibold small"><?= e($p['name']) ?></span><span class="small text-muted"><?= e($p['sku']) ?></span></span>
            <span class="small"><?= (int)$p['quantity'] ?> <?= e($p['unit']) ?></span>
          </a>
        <?php endforeach; ?>
        <?php if (!$usedBy): ?><div class="empty-state py-3"><i class="fa-solid fa-box-open"></i><div>No products use this yet</div></div><?php endif; ?>
      <?php else: ?>
        <div class="empty-state py-3"><i class="<?= e($cfg['icon']) ?>"></i><div>Products using this <?= e(strtolower($cfg['singular'])) ?> will show here after you save it.</div></div>
      <?php endif; ?>
    </div>
  </div>
</div>
    <?php
    require __DIR__ . '/../includes/footer.php';
