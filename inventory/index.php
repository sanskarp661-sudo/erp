<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$pdo = db();
$productCount = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE status='active'")->fetchColumn();
$categoryCount = (int)$pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
$lowStockCount = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE quantity <= reorder_level")->fetchColumn();
$inventoryValue = (float)$pdo->query("SELECT COALESCE(SUM(quantity * cost_price),0) FROM products")->fetchColumn();

$lowStockItems = $pdo->query("
  SELECT name, sku, quantity, reorder_level FROM products
  WHERE quantity <= reorder_level ORDER BY quantity ASC LIMIT 8
")->fetchAll();

$recentProducts = $pdo->query("SELECT name, sku, quantity, status FROM products ORDER BY id DESC LIMIT 6")->fetchAll();

$page_title = 'Inventory';
require __DIR__ . '/../includes/header.php';
?>
<div class="row g-3 mb-3">
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card"><div class="icon bg-brand"><i class="fa-solid fa-box"></i></div>
      <div><div class="value"><?= $productCount ?></div><div class="label">Active products</div></div></div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card"><div class="icon bg-green"><i class="fa-solid fa-tags"></i></div>
      <div><div class="value"><?= $categoryCount ?></div><div class="label">Categories</div></div></div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card"><div class="icon bg-orange"><i class="fa-solid fa-warehouse"></i></div>
      <div><div class="value"><?= money($inventoryValue) ?></div><div class="label">Inventory value</div></div></div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card"><div class="icon bg-red"><i class="fa-solid fa-triangle-exclamation"></i></div>
      <div><div class="value"><?= $lowStockCount ?></div><div class="label">Low stock items</div></div></div>
  </div>
</div>

<div class="d-flex gap-2 mb-3">
  <a href="products.php" class="btn btn-brand"><i class="fa-solid fa-plus"></i> Add Product</a>
  <a href="categories.php" class="btn btn-outline-brand">Manage Categories</a>
  <a href="<?= base_url('reports/stock_balance_report.php') ?>" class="btn btn-outline-brand">Stock Balance</a>
  <a href="<?= base_url('reports/inventory_report.php') ?>" class="btn btn-outline-secondary">Full Report</a>
</div>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card p-3">
      <h6 class="mb-2">Low Stock Alerts</h6>
      <div class="table-responsive">
        <table class="table table-sm">
          <thead><tr><th>Product</th><th class="text-end">Qty</th><th class="text-end">Reorder</th></tr></thead>
          <tbody>
          <?php foreach ($lowStockItems as $p): ?>
            <tr><td><?= e($p['name']) ?> <span class="text-muted small">(<?= e($p['sku']) ?>)</span></td><td class="text-end text-danger fw-bold"><?= (int)$p['quantity'] ?></td><td class="text-end"><?= (int)$p['reorder_level'] ?></td></tr>
          <?php endforeach; ?>
          <?php if (!$lowStockItems): ?><tr><td colspan="3" class="text-muted text-center">Stock levels look healthy.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card p-3">
      <h6 class="mb-2">Recently Added Products</h6>
      <div class="table-responsive">
        <table class="table table-sm">
          <thead><tr><th>Product</th><th>SKU</th><th class="text-end">Qty</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($recentProducts as $p): ?>
            <tr><td><?= e($p['name']) ?></td><td><?= e($p['sku']) ?></td><td class="text-end"><?= (int)$p['quantity'] ?></td>
              <td><span class="badge text-bg-<?= $p['status'] === 'active' ? 'success' : 'secondary' ?> badge-status"><?= e($p['status']) ?></span></td></tr>
          <?php endforeach; ?>
          <?php if (!$recentProducts): ?><tr><td colspan="4" class="text-muted text-center">No products yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
