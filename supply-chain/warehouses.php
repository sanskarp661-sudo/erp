<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
$canEdit = can_edit_module('supply-chain');
$canManage = can_manage_module('supply-chain');

if (is_post() && input('action') === 'delete') {
    require_module_manage('supply-chain');
    csrf_verify();
    $id = (int)input('id');
    $stmt = db()->prepare('SELECT COUNT(*) FROM warehouses WHERE parent_id = ?');
    $stmt->execute([$id]);
    $childCount = (int)$stmt->fetchColumn();
    $stmt = db()->prepare('SELECT COALESCE(SUM(quantity),0) FROM stock_bins WHERE warehouse_id = ?');
    $stmt->execute([$id]);
    $stockCount = (int)$stmt->fetchColumn();
    if ($childCount > 0) {
        flash('danger', 'Cannot delete: this warehouse has sub-warehouses. Delete or move them first.');
    } elseif ($stockCount > 0) {
        flash('danger', 'Cannot delete: this warehouse still holds stock.');
    } else {
        try {
            db()->prepare('DELETE FROM warehouses WHERE id = ?')->execute([$id]);
            flash('success', 'Warehouse deleted.');
        } catch (PDOException $e) {
            flash('danger', 'Cannot delete: this warehouse is referenced by existing documents.');
        }
    }
    redirect('/supply-chain/warehouses.php');
}

$all = db()->query('SELECT w.*, (SELECT COALESCE(SUM(quantity),0) FROM stock_bins b WHERE b.warehouse_id = w.id) stock FROM warehouses w ORDER BY w.name')->fetchAll();
$byParent = [];
foreach ($all as $w) {
    $byParent[$w['parent_id'] ?? 0][] = $w;
}

function render_warehouse_tree(array $byParent, ?int $parentId, int $depth, bool $canEdit, bool $canManage): void
{
    foreach ($byParent[$parentId ?? 0] ?? [] as $w) {
        echo '<tr>';
        echo '<td><span style="padding-left:' . ($depth * 24) . 'px">';
        echo $w['is_group'] ? '<i class="fa-solid fa-folder text-muted me-2"></i>' : '<i class="fa-solid fa-warehouse text-muted me-2"></i>';
        echo e($w['name']) . '</span></td>';
        echo '<td>' . ($w['is_group'] ? '<span class="badge text-bg-light">Group</span>' : '<span class="badge text-bg-light">Warehouse</span>') . '</td>';
        echo '<td class="text-end">' . ($w['is_group'] ? '&mdash;' : (int)$w['stock']) . '</td>';
        echo '<td><span class="badge text-bg-' . ($w['status'] === 'active' ? 'success' : 'secondary') . '">' . e($w['status']) . '</span></td>';
        echo '<td class="text-end">';
        if ($canEdit) {
            echo '<a href="warehouse_form.php?id=' . (int)$w['id'] . '" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen"></i></a> ';
            echo '<a href="warehouse_form.php?parent_id=' . (int)$w['id'] . '" class="btn btn-sm btn-outline-brand" title="Add sub-warehouse"><i class="fa-solid fa-plus"></i></a> ';
        }
        if ($canManage) {
            echo '<form method="post" class="d-inline" data-confirm="Delete this warehouse?">';
            echo csrf_field();
            echo '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . (int)$w['id'] . '">';
            echo '<button class="btn btn-sm btn-outline-danger" type="submit"><i class="fa-solid fa-trash"></i></button>';
            echo '</form>';
        }
        echo '</td></tr>';
        render_warehouse_tree($byParent, (int)$w['id'], $depth + 1, $canEdit, $canManage);
    }
}

$page_title = 'Warehouses';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <p class="text-muted mb-0">Warehouses are organized as a tree. A "Group" warehouse is organizational only and can't hold stock directly — add leaf warehouses under it for actual storage locations.</p>
  <?php if ($canEdit): ?><a href="warehouse_form.php" class="btn btn-brand text-nowrap ms-3"><i class="fa-solid fa-plus"></i> New Warehouse</a><?php endif; ?>
</div>
<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-hover">
      <thead><tr><th>Warehouse</th><th>Type</th><th class="text-end">Stock on Hand</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
        <?php if ($all): render_warehouse_tree($byParent, null, 0, $canEdit, $canManage); else: ?>
          <tr><td colspan="5" class="empty-state"><i class="fa-solid fa-warehouse"></i><div>No warehouses yet.</div></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
