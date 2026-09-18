<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$start = input('start') ?: date('Y-m-01');
$end = input('end') ?: date('Y-m-d');

$pdo = db();

$summaryStmt = $pdo->prepare("
  SELECT COUNT(*) order_count, COALESCE(SUM(total_amount),0) total_purchases
  FROM purchase_orders WHERE order_date BETWEEN ? AND ? AND status <> 'cancelled'
");
$summaryStmt->execute([$start, $end]);
$summary = $summaryStmt->fetch();

$byVendorStmt = $pdo->prepare("
  SELECT v.name, COUNT(po.id) orders, SUM(po.total_amount) total
  FROM purchase_orders po JOIN vendors v ON v.id = po.vendor_id
  WHERE po.order_date BETWEEN ? AND ? AND po.status <> 'cancelled'
  GROUP BY v.id ORDER BY total DESC LIMIT 10
");
$byVendorStmt->execute([$start, $end]);
$byVendor = $byVendorStmt->fetchAll();

$topProductsStmt = $pdo->prepare("
  SELECT p.name, p.sku, SUM(poi.quantity) qty, SUM(poi.subtotal) spend
  FROM purchase_order_items poi
  JOIN purchase_orders po ON po.id = poi.po_id
  JOIN products p ON p.id = poi.product_id
  WHERE po.order_date BETWEEN ? AND ? AND po.status <> 'cancelled'
  GROUP BY p.id ORDER BY spend DESC LIMIT 10
");
$topProductsStmt->execute([$start, $end]);
$topProducts = $topProductsStmt->fetchAll();

$page_title = 'Purchase Report';
require __DIR__ . '/../includes/header.php';
?>
<form method="get" class="row g-2 mb-3 align-items-end no-print">
  <div class="col-auto"><label class="form-label mb-1">From</label><input type="date" name="start" class="form-control" value="<?= e($start) ?>"></div>
  <div class="col-auto"><label class="form-label mb-1">To</label><input type="date" name="end" class="form-control" value="<?= e($end) ?>"></div>
  <div class="col-auto"><button class="btn btn-brand">Apply</button></div>
  <div class="col-auto"><button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button></div>
</form>

<div class="row g-3 mb-3">
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card"><div class="icon bg-orange"><i class="fa-solid fa-truck-field"></i></div>
      <div><div class="value"><?= money($summary['total_purchases']) ?></div><div class="label">Total purchases</div></div></div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card"><div class="icon bg-brand"><i class="fa-solid fa-file-lines"></i></div>
      <div><div class="value"><?= (int)$summary['order_count'] ?></div><div class="label">Purchase orders</div></div></div>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card p-3">
      <h6 class="mb-2">Spend by Vendor</h6>
      <div class="table-responsive">
        <table class="table table-sm">
          <thead><tr><th>Vendor</th><th class="text-end">Orders</th><th class="text-end">Total</th></tr></thead>
          <tbody>
          <?php foreach ($byVendor as $v): ?>
            <tr><td><?= e($v['name']) ?></td><td class="text-end"><?= (int)$v['orders'] ?></td><td class="text-end"><?= money($v['total']) ?></td></tr>
          <?php endforeach; ?>
          <?php if (!$byVendor): ?><tr><td colspan="3" class="text-muted text-center">No purchases in this period.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card p-3">
      <h6 class="mb-2">Top Purchased Products</h6>
      <div class="table-responsive">
        <table class="table table-sm">
          <thead><tr><th>Product</th><th class="text-end">Qty</th><th class="text-end">Spend</th></tr></thead>
          <tbody>
          <?php foreach ($topProducts as $p): ?>
            <tr><td><?= e($p['name']) ?> <span class="text-muted small">(<?= e($p['sku']) ?>)</span></td><td class="text-end"><?= (int)$p['qty'] ?></td><td class="text-end"><?= money($p['spend']) ?></td></tr>
          <?php endforeach; ?>
          <?php if (!$topProducts): ?><tr><td colspan="3" class="text-muted text-center">No purchases in this period.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
