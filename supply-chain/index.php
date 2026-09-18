<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$pdo = db();

$inQty = (int)$pdo->query("SELECT COALESCE(SUM(quantity),0) FROM stock_movements WHERE type='in' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();
$outQty = (int)$pdo->query("SELECT COALESCE(SUM(ABS(quantity)),0) FROM stock_movements WHERE type='out' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();
$adjCount = (int)$pdo->query("SELECT COUNT(*) FROM stock_movements WHERE type='adjustment' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();
$totalMovements = (int)$pdo->query("SELECT COUNT(*) FROM stock_movements")->fetchColumn();

$recentMovements = $pdo->query("
  SELECT sm.*, p.name product_name, p.sku
  FROM stock_movements sm JOIN products p ON p.id = sm.product_id
  ORDER BY sm.id DESC LIMIT 10
")->fetchAll();

$page_title = 'Supply Chain';
require __DIR__ . '/../includes/header.php';
?>
<div class="row g-3 mb-3">
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card"><div class="icon bg-green"><i class="fa-solid fa-arrow-down"></i></div>
      <div><div class="value"><?= $inQty ?></div><div class="label">Units in (30 days)</div></div></div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card"><div class="icon bg-red"><i class="fa-solid fa-arrow-up"></i></div>
      <div><div class="value"><?= $outQty ?></div><div class="label">Units out (30 days)</div></div></div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card"><div class="icon bg-orange"><i class="fa-solid fa-sliders"></i></div>
      <div><div class="value"><?= $adjCount ?></div><div class="label">Adjustments (30 days)</div></div></div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card"><div class="icon bg-brand"><i class="fa-solid fa-arrow-right-arrow-left"></i></div>
      <div><div class="value"><?= $totalMovements ?></div><div class="label">Total movements logged</div></div></div>
  </div>
</div>

<div class="d-flex gap-2 mb-3">
  <a href="stock_adjust.php" class="btn btn-brand"><i class="fa-solid fa-plus"></i> New Stock Entry</a>
  <a href="warehouses.php" class="btn btn-outline-brand">Manage Warehouses</a>
  <a href="stock_movements.php" class="btn btn-outline-secondary">View All Movements</a>
</div>

<div class="card p-3">
  <h6 class="mb-2">Recent Stock Movements</h6>
  <div class="table-responsive">
    <table class="table table-sm">
      <thead><tr><th>Date</th><th>Product</th><th>Type</th><th class="text-end">Qty</th><th>Reference</th></tr></thead>
      <tbody>
      <?php $badge = ['in' => 'success', 'out' => 'danger', 'adjustment' => 'warning']; ?>
      <?php foreach ($recentMovements as $m): ?>
        <tr>
          <td><?= e(date('Y-m-d H:i', strtotime($m['created_at']))) ?></td>
          <td><?= e($m['product_name']) ?> <span class="text-muted small">(<?= e($m['sku']) ?>)</span></td>
          <td><span class="badge text-bg-<?= $badge[$m['type']] ?? 'secondary' ?> badge-status"><?= e($m['type']) ?></span></td>
          <td class="text-end fw-bold"><?= (int)$m['quantity'] ?></td>
          <td><?= e($m['reference']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$recentMovements): ?><tr><td colspan="5" class="text-muted text-center">No stock movements recorded yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
