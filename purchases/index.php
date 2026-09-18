<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$pdo = db();
$openPOs = (int)$pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE status IN ('pending','ordered')")->fetchColumn();
$vendorCount = (int)$pdo->query("SELECT COUNT(*) FROM vendors")->fetchColumn();
$monthSpend = (float)$pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM purchase_orders WHERE status <> 'cancelled' AND MONTH(order_date)=MONTH(CURDATE()) AND YEAR(order_date)=YEAR(CURDATE())")->fetchColumn();
$receivedThisMonth = (int)$pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE status='received' AND MONTH(order_date)=MONTH(CURDATE()) AND YEAR(order_date)=YEAR(CURDATE())")->fetchColumn();

$recentPOs = $pdo->query("
  SELECT po.id, po.po_no, po.status, po.total_amount, po.order_date, v.name vendor_name
  FROM purchase_orders po JOIN vendors v ON v.id = po.vendor_id
  ORDER BY po.id DESC LIMIT 8
")->fetchAll();

$badge = ['pending' => 'secondary', 'ordered' => 'info', 'received' => 'success', 'cancelled' => 'danger'];

$page_title = 'Procurement';
require __DIR__ . '/../includes/header.php';
?>
<div class="row g-3 mb-3">
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card"><div class="icon bg-orange"><i class="fa-solid fa-truck-field"></i></div>
      <div><div class="value"><?= $openPOs ?></div><div class="label">Open purchase orders</div></div></div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card"><div class="icon bg-brand"><i class="fa-solid fa-sack-dollar"></i></div>
      <div><div class="value"><?= money($monthSpend) ?></div><div class="label">Spend this month</div></div></div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card"><div class="icon bg-green"><i class="fa-solid fa-handshake"></i></div>
      <div><div class="value"><?= $vendorCount ?></div><div class="label">Vendors</div></div></div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card"><div class="icon bg-purple"><i class="fa-solid fa-box-open"></i></div>
      <div><div class="value"><?= $receivedThisMonth ?></div><div class="label">Received this month</div></div></div>
  </div>
</div>

<div class="d-flex gap-2 mb-3">
  <a href="order_form.php" class="btn btn-brand"><i class="fa-solid fa-plus"></i> New Purchase Order</a>
  <a href="grns.php" class="btn btn-outline-brand">Goods Receipts</a>
  <a href="vendors.php" class="btn btn-outline-brand">Manage Vendors</a>
  <a href="<?= base_url('reports/purchase_report.php') ?>" class="btn btn-outline-secondary">Full Report</a>
</div>

<div class="card p-3">
  <h6 class="mb-2">Recent Purchase Orders</h6>
  <div class="table-responsive">
    <table class="table table-sm">
      <thead><tr><th>PO #</th><th>Vendor</th><th>Date</th><th>Status</th><th class="text-end">Total</th></tr></thead>
      <tbody>
      <?php foreach ($recentPOs as $po): ?>
        <tr>
          <td><a href="order_view.php?id=<?= (int)$po['id'] ?>"><?= e($po['po_no']) ?></a></td>
          <td><?= e($po['vendor_name']) ?></td>
          <td><?= e($po['order_date']) ?></td>
          <td><span class="badge text-bg-<?= $badge[$po['status']] ?? 'secondary' ?> badge-status"><?= e($po['status']) ?></span></td>
          <td class="text-end"><?= money($po['total_amount']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$recentPOs): ?><tr><td colspan="5" class="text-muted text-center">No purchase orders yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
