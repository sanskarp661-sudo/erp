<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$movements = db()->query("
  SELECT sm.*, p.name product_name, p.sku, u.name user_name
  FROM stock_movements sm
  JOIN products p ON p.id = sm.product_id
  LEFT JOIN users u ON u.id = sm.created_by
  ORDER BY sm.id DESC LIMIT 200
")->fetchAll();

$page_title = 'Stock Movements';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <input type="text" class="form-control" style="max-width:280px" placeholder="Search movements..." data-table-search="#moveTable">
  <a href="stock_adjust.php" class="btn btn-brand"><i class="fa-solid fa-plus"></i> New Stock Movement</a>
</div>
<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-hover" id="moveTable">
      <thead><tr><th>Date</th><th>Product</th><th>Type</th><th class="text-end">Qty</th><th>Reference</th><th>Notes</th><th>By</th></tr></thead>
      <tbody>
      <?php foreach ($movements as $m): ?>
        <tr>
          <td><?= e(date('Y-m-d H:i', strtotime($m['created_at']))) ?></td>
          <td><?= e($m['product_name']) ?> <span class="text-muted small">(<?= e($m['sku']) ?>)</span></td>
          <td>
            <?php $badge = ['in' => 'success', 'out' => 'danger', 'adjustment' => 'warning']; ?>
            <span class="badge text-bg-<?= $badge[$m['type']] ?? 'secondary' ?> badge-status"><?= e($m['type']) ?></span>
          </td>
          <td class="text-end fw-bold"><?= (int)$m['quantity'] ?></td>
          <td><?= e($m['reference']) ?></td>
          <td class="text-muted"><?= e($m['notes']) ?></td>
          <td><?= e($m['user_name'] ?? 'System') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$movements): ?><tr><td colspan="7" class="text-muted text-center">No stock movements recorded yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
