<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/stock_entry.php';
require_login();
$canEdit = can_edit_module('supply-chain');

$types = stock_entry_types();
$typeFilter = isset($types[input('type')]) ? input('type') : '';
$statusFilter = in_array(input('status'), ['draft', 'submitted', 'cancelled'], true) ? input('status') : '';

$sql = "SELECT se.*, sw.name source_name, tw.name target_name, (SELECT COUNT(*) FROM stock_entry_items i WHERE i.stock_entry_id = se.id) item_count
    FROM stock_entries se
    LEFT JOIN warehouses sw ON sw.id = se.source_warehouse_id
    LEFT JOIN warehouses tw ON tw.id = se.target_warehouse_id
    WHERE 1=1";
$params = [];
if ($typeFilter) {
    $sql .= ' AND se.entry_type = ?';
    $params[] = $typeFilter;
}
if ($statusFilter) {
    $sql .= ' AND se.status = ?';
    $params[] = $statusFilter;
}
$sql .= ' ORDER BY se.id DESC LIMIT 500';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$entries = $stmt->fetchAll();

$badge = ['draft' => 'secondary', 'submitted' => 'success', 'cancelled' => 'danger'];

$page_title = 'Stock Entries';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <form method="get" class="d-flex gap-2 flex-wrap">
    <input type="text" class="form-control" style="max-width:240px" placeholder="Search stock entries..." data-table-search="#seTable">
    <select name="type" class="form-select" style="width:auto" onchange="this.form.submit()">
      <option value="">All types</option>
      <?php foreach ($types as $val => $label): ?><option value="<?= $val ?>" <?= $typeFilter === $val ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
    </select>
    <select name="status" class="form-select" style="width:auto" onchange="this.form.submit()">
      <option value="">All statuses</option>
      <?php foreach (['draft' => 'Draft', 'submitted' => 'Submitted', 'cancelled' => 'Cancelled'] as $val => $label): ?><option value="<?= $val ?>" <?= $statusFilter === $val ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
    </select>
  </form>
  <?php if ($canEdit): ?><a href="stock_entry_form.php" class="btn btn-brand"><i class="fa-solid fa-plus"></i> New Stock Entry</a><?php endif; ?>
</div>
<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-hover" id="seTable">
      <thead><tr><th>Entry #</th><th>Type</th><th>Date</th><th>From</th><th>To</th><th class="text-end">Items</th><th class="text-end">Qty</th><th class="text-end">Value</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($entries as $se): ?>
        <tr>
          <td><?= e($se['entry_no']) ?></td>
          <td><?= e($types[$se['entry_type']] ?? $se['entry_type']) ?></td>
          <td><?= e($se['posting_date']) ?></td>
          <td><?= e($se['source_name'] ?? '—') ?></td>
          <td><?= e($se['target_name'] ?? '—') ?></td>
          <td class="text-end"><?= (int)$se['item_count'] ?></td>
          <td class="text-end"><?= (int)$se['total_qty'] ?></td>
          <td class="text-end"><?= money(max((float)$se['total_incoming_value'], (float)$se['total_outgoing_value'])) ?></td>
          <td><span class="badge text-bg-<?= $badge[$se['status']] ?? 'secondary' ?> badge-status"><?= e($se['status']) ?></span></td>
          <td class="text-end"><a href="stock_entry_view.php?id=<?= (int)$se['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-eye"></i> View</a></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$entries): ?><tr><td colspan="10" class="text-muted text-center">No stock entries yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
