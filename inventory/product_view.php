<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
$canEdit = can_edit_module('inventory');

$id = (int)input('id');
$stmt = db()->prepare('
  SELECT p.*, c.name category_name, ic.name item_category_name, b.name brand_name, dw.name default_warehouse_name, dpl.name default_price_list_name, da.name discount_account_name
  FROM products p
  LEFT JOIN categories c ON c.id = p.category_id
  LEFT JOIN item_categories ic ON ic.id = p.item_category_id
  LEFT JOIN brands b ON b.id = p.brand_id
  LEFT JOIN warehouses dw ON dw.id = p.default_warehouse_id
  LEFT JOIN price_lists dpl ON dpl.id = p.default_price_list_id
  LEFT JOIN ledger_accounts da ON da.id = p.discount_account_id
  WHERE p.id = ?
');
$stmt->execute([$id]);
$product = $stmt->fetch();

if (!$product) {
    flash('danger', 'Product not found.');
    redirect('/inventory/products.php');
}

$barcodes = db()->prepare('SELECT * FROM product_barcodes WHERE product_id = ? ORDER BY sort_order, id');
$barcodes->execute([$id]);
$barcodes = $barcodes->fetchAll();

$productImages = db()->prepare('SELECT * FROM product_images WHERE product_id = ? ORDER BY sort_order, id');
$productImages->execute([$id]);
$productImages = $productImages->fetchAll();

$productUoms = db()->prepare('SELECT * FROM product_uoms WHERE product_id = ? ORDER BY sort_order, id');
$productUoms->execute([$id]);
$productUoms = $productUoms->fetchAll();

$customFields = db()->prepare("SELECT d.label, d.field_type, v.value FROM custom_field_defs d
  LEFT JOIN custom_field_values v ON v.entity_type = 'product' AND v.field_key = d.field_key AND v.entity_id = ?
  WHERE d.entity_type = 'product' AND d.status = 'active' AND v.value IS NOT NULL AND v.value <> ''
  ORDER BY d.sort_order, d.id");
$customFields->execute([$id]);
$customFields = $customFields->fetchAll();

$productBatches = db()->prepare('
  SELECT pb.*, COALESCE(SUM(sb.quantity), 0) qty_in_stock
  FROM product_batches pb
  LEFT JOIN stock_bins sb ON sb.batch_id = pb.id
  WHERE pb.product_id = ?
  GROUP BY pb.id
  ORDER BY pb.created_at DESC
');
$productBatches->execute([$id]);
$productBatches = $productBatches->fetchAll();

$productSerialsCount = db()->prepare("SELECT status, COUNT(*) c FROM product_serials WHERE product_id = ? GROUP BY status");
$productSerialsCount->execute([$id]);
$productSerialStatusCounts = [];
foreach ($productSerialsCount->fetchAll() as $r) {
    $productSerialStatusCounts[$r['status']] = (int)$r['c'];
}

$priceListRates = db()->prepare('SELECT pli.rate, pl.name price_list_name FROM price_list_items pli JOIN price_lists pl ON pl.id = pli.price_list_id WHERE pli.product_id = ? ORDER BY pl.name');
$priceListRates->execute([$id]);
$priceListRates = $priceListRates->fetchAll();

$customerPrices = db()->prepare('SELECT cp.*, c.name customer_name, pl.name price_list_name FROM product_customer_prices cp JOIN customers c ON c.id = cp.customer_id LEFT JOIN price_lists pl ON pl.id = cp.price_list_id WHERE cp.product_id = ? ORDER BY cp.sort_order, cp.id');
$customerPrices->execute([$id]);
$customerPrices = $customerPrices->fetchAll();

$productTaxes = db()->prepare('SELECT pt.*, la.name account_name FROM product_taxes pt LEFT JOIN ledger_accounts la ON la.id = pt.account_head_id WHERE pt.product_id = ? ORDER BY pt.sort_order, pt.id');
$productTaxes->execute([$id]);
$productTaxes = $productTaxes->fetchAll();

$productCharges = db()->prepare('SELECT pc.*, la.name account_name FROM product_charges pc LEFT JOIN ledger_accounts la ON la.id = pc.account_head_id WHERE pc.product_id = ? ORDER BY pc.sort_order, pc.id');
$productCharges->execute([$id]);
$productCharges = $productCharges->fetchAll();

$productSuppliers = db()->prepare('SELECT ps.*, v.name supplier_name FROM product_suppliers ps JOIN vendors v ON v.id = ps.supplier_id WHERE ps.product_id = ? ORDER BY ps.sort_order, ps.id');
$productSuppliers->execute([$id]);
$productSuppliers = $productSuppliers->fetchAll();

$productCustomerRules = db()->prepare('SELECT cr.*, c.name customer_name, pl.name price_list_name FROM product_customer_rules cr JOIN customers c ON c.id = cr.customer_id LEFT JOIN price_lists pl ON pl.id = cr.price_list_id WHERE cr.product_id = ? ORDER BY cr.sort_order, cr.id');
$productCustomerRules->execute([$id]);
$productCustomerRules = $productCustomerRules->fetchAll();

$defaultSupplierName = null;
if ($product['default_supplier_id']) {
    $stmt = db()->prepare('SELECT name FROM vendors WHERE id = ?');
    $stmt->execute([$product['default_supplier_id']]);
    $defaultSupplierName = $stmt->fetchColumn() ?: null;
}

$defaultTaxTemplateName = null;
if ($product['default_tax_template_id']) {
    $stmt = db()->prepare('SELECT name FROM tax_templates WHERE id = ?');
    $stmt->execute([$product['default_tax_template_id']]);
    $defaultTaxTemplateName = $stmt->fetchColumn() ?: null;
}

$pdo = db();

// --- Stock by warehouse ---
$stockByWarehouse = $pdo->prepare("
  SELECT w.id, w.name, COALESCE(SUM(sb.quantity), 0) quantity
  FROM warehouses w
  LEFT JOIN stock_bins sb ON sb.warehouse_id = w.id AND sb.product_id = ?
  WHERE w.is_group = 0 AND w.status = 'active'
  GROUP BY w.id, w.name
  ORDER BY w.name
");
$stockByWarehouse->execute([$id]);
$stockByWarehouse = $stockByWarehouse->fetchAll();

// --- Monthly trend helper: last 12 calendar months, zero-filled ---
function product_monthly_trend(PDO $pdo, string $sql, int $productId): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$productId]);
    $byMonth = [];
    foreach ($stmt->fetchAll() as $row) {
        $byMonth[$row['ym']] = ['qty' => (int)$row['qty'], 'amount' => (float)$row['amount']];
    }
    $labels = [];
    $qty = [];
    $amount = [];
    for ($i = 11; $i >= 0; $i--) {
        $ym = date('Y-m', strtotime("first day of -$i months"));
        $labels[] = date('M Y', strtotime("first day of -$i months"));
        $qty[] = $byMonth[$ym]['qty'] ?? 0;
        $amount[] = $byMonth[$ym]['amount'] ?? 0;
    }
    return ['labels' => $labels, 'qty' => $qty, 'amount' => $amount];
}

$salesTrend = product_monthly_trend($pdo, "
  SELECT DATE_FORMAT(i.invoice_date, '%Y-%m') ym, SUM(ii.quantity) qty, SUM(ii.subtotal) amount
  FROM invoice_items ii JOIN invoices i ON i.id = ii.invoice_id
  WHERE ii.product_id = ? AND i.status <> 'cancelled'
  GROUP BY ym
", $id);

$purchaseTrend = product_monthly_trend($pdo, "
  SELECT DATE_FORMAT(pi.invoice_date, '%Y-%m') ym, SUM(pii.quantity) qty, SUM(pii.subtotal) amount
  FROM purchase_invoice_items pii JOIN purchase_invoices pi ON pi.id = pii.purchase_invoice_id
  WHERE pii.product_id = ? AND pi.status <> 'cancelled'
  GROUP BY ym
", $id);

$lifetimeSales = $pdo->prepare("SELECT COALESCE(SUM(ii.quantity),0) qty, COALESCE(SUM(ii.subtotal),0) amount FROM invoice_items ii JOIN invoices i ON i.id = ii.invoice_id WHERE ii.product_id = ? AND i.status <> 'cancelled'");
$lifetimeSales->execute([$id]);
$lifetimeSales = $lifetimeSales->fetch();

$lifetimePurchases = $pdo->prepare("SELECT COALESCE(SUM(pii.quantity),0) qty, COALESCE(SUM(pii.subtotal),0) amount FROM purchase_invoice_items pii JOIN purchase_invoices pi ON pi.id = pii.purchase_invoice_id WHERE pii.product_id = ? AND pi.status <> 'cancelled'");
$lifetimePurchases->execute([$id]);
$lifetimePurchases = $lifetimePurchases->fetch();

// --- Connections: recent linked documents across every doctype that
// references this product, so its full transaction history is visible
// in one place (mirrors an ERP "Connections" tab). ---
function conn_fetch(PDO $pdo, string $listSql, string $countSql, int $productId, int $limit = 10): array
{
    $stmt = $pdo->prepare($listSql . ' LIMIT ' . $limit);
    $stmt->execute([$productId]);
    $rows = $stmt->fetchAll();
    $count = $pdo->prepare($countSql);
    $count->execute([$productId]);
    return ['rows' => $rows, 'total' => (int)$count->fetchColumn()];
}

$connections = [
    'sales_orders' => [
        'label' => 'Sales Orders', 'icon' => 'fa-solid fa-cart-shopping',
        'view_url' => fn($r) => base_url('sales/order_view.php?id=' . $r['id']),
        'data' => conn_fetch($pdo,
            "SELECT so.id, so.order_no doc_no, so.order_date doc_date, so.status, c.name party, soi.quantity, soi.subtotal amount
             FROM sales_order_items soi JOIN sales_orders so ON so.id = soi.order_id JOIN customers c ON c.id = so.customer_id
             WHERE soi.product_id = ? ORDER BY so.id DESC",
            "SELECT COUNT(*) FROM sales_order_items soi WHERE soi.product_id = ?", $id),
    ],
    'delivery_notes' => [
        'label' => 'Delivery Notes', 'icon' => 'fa-solid fa-truck',
        'view_url' => fn($r) => base_url('sales/delivery_note_view.php?id=' . $r['id']),
        'data' => conn_fetch($pdo,
            "SELECT dn.id, dn.dn_no doc_no, dn.posting_date doc_date, dn.status, c.name party, dni.quantity, dni.subtotal amount
             FROM delivery_note_items dni JOIN delivery_notes dn ON dn.id = dni.dn_id JOIN customers c ON c.id = dn.customer_id
             WHERE dni.product_id = ? ORDER BY dn.id DESC",
            "SELECT COUNT(*) FROM delivery_note_items dni WHERE dni.product_id = ?", $id),
    ],
    'sales_returns' => [
        'label' => 'Sales Returns', 'icon' => 'fa-solid fa-rotate-left',
        'view_url' => fn($r) => base_url('sales/return_view.php?id=' . $r['id']),
        'data' => conn_fetch($pdo,
            "SELECT sr.id, sr.return_no doc_no, sr.return_date doc_date, sr.status, c.name party, sri.quantity, sri.subtotal amount
             FROM sales_return_items sri JOIN sales_returns sr ON sr.id = sri.sales_return_id JOIN customers c ON c.id = sr.customer_id
             WHERE sri.product_id = ? ORDER BY sr.id DESC",
            "SELECT COUNT(*) FROM sales_return_items sri WHERE sri.product_id = ?", $id),
    ],
    'invoices' => [
        'label' => 'Sales Invoices', 'icon' => 'fa-solid fa-file-invoice-dollar',
        'view_url' => fn($r) => base_url('accounting/invoice_view.php?id=' . $r['id']),
        'data' => conn_fetch($pdo,
            "SELECT i.id, i.invoice_no doc_no, i.invoice_date doc_date, i.status, c.name party, ii.quantity, ii.subtotal amount
             FROM invoice_items ii JOIN invoices i ON i.id = ii.invoice_id JOIN customers c ON c.id = i.customer_id
             WHERE ii.product_id = ? ORDER BY i.id DESC",
            "SELECT COUNT(*) FROM invoice_items ii WHERE ii.product_id = ?", $id),
    ],
    'purchase_orders' => [
        'label' => 'Purchase Orders', 'icon' => 'fa-solid fa-truck-field',
        'view_url' => fn($r) => base_url('purchases/order_view.php?id=' . $r['id']),
        'data' => conn_fetch($pdo,
            "SELECT po.id, po.po_no doc_no, po.order_date doc_date, po.status, v.name party, poi.quantity, poi.subtotal amount
             FROM purchase_order_items poi JOIN purchase_orders po ON po.id = poi.po_id JOIN vendors v ON v.id = po.vendor_id
             WHERE poi.product_id = ? ORDER BY po.id DESC",
            "SELECT COUNT(*) FROM purchase_order_items poi WHERE poi.product_id = ?", $id),
    ],
    'goods_receipts' => [
        'label' => 'Goods Receipts', 'icon' => 'fa-solid fa-box-open',
        'view_url' => fn($r) => base_url('purchases/grn_view.php?id=' . $r['id']),
        'data' => conn_fetch($pdo,
            "SELECT g.id, g.grn_no doc_no, g.posting_date doc_date, g.status, v.name party, gri.quantity, gri.subtotal amount
             FROM goods_receipt_items gri JOIN goods_receipts g ON g.id = gri.grn_id JOIN vendors v ON v.id = g.vendor_id
             WHERE gri.product_id = ? ORDER BY g.id DESC",
            "SELECT COUNT(*) FROM goods_receipt_items gri WHERE gri.product_id = ?", $id),
    ],
    'purchase_returns' => [
        'label' => 'Purchase Returns', 'icon' => 'fa-solid fa-rotate-left',
        'view_url' => fn($r) => base_url('purchases/return_view.php?id=' . $r['id']),
        'data' => conn_fetch($pdo,
            "SELECT pr.id, pr.return_no doc_no, pr.return_date doc_date, pr.status, v.name party, pri.quantity, pri.subtotal amount
             FROM purchase_return_items pri JOIN purchase_returns pr ON pr.id = pri.purchase_return_id JOIN vendors v ON v.id = pr.vendor_id
             WHERE pri.product_id = ? ORDER BY pr.id DESC",
            "SELECT COUNT(*) FROM purchase_return_items pri WHERE pri.product_id = ?", $id),
    ],
    'purchase_invoices' => [
        'label' => 'Purchase Invoices', 'icon' => 'fa-solid fa-file-invoice',
        'view_url' => fn($r) => base_url('accounting/purchase_invoice_view.php?id=' . $r['id']),
        'data' => conn_fetch($pdo,
            "SELECT pi.id, pi.pi_no doc_no, pi.invoice_date doc_date, pi.status, v.name party, pii.quantity, pii.subtotal amount
             FROM purchase_invoice_items pii JOIN purchase_invoices pi ON pi.id = pii.purchase_invoice_id JOIN vendors v ON v.id = pi.vendor_id
             WHERE pii.product_id = ? ORDER BY pi.id DESC",
            "SELECT COUNT(*) FROM purchase_invoice_items pii WHERE pii.product_id = ?", $id),
    ],
];

$movements = conn_fetch($pdo,
    "SELECT sm.id, sm.type, sm.quantity, sm.reference, sm.created_at, w.name warehouse_name
     FROM stock_movements sm LEFT JOIN warehouses w ON w.id = sm.warehouse_id
     WHERE sm.product_id = ? ORDER BY sm.id DESC",
    "SELECT COUNT(*) FROM stock_movements WHERE product_id = ?", $id, 15
);

$statusBadge = ['pending' => 'gray', 'confirmed' => 'blue', 'shipped' => 'purple', 'completed' => 'green', 'cancelled' => 'red',
    'draft' => 'gray', 'delivered' => 'green', 'received' => 'green', 'ordered' => 'blue',
    'unpaid' => 'gray', 'partially_paid' => 'orange', 'paid' => 'green', 'overdue' => 'red'];

$qtyNow = (int)$product['quantity'];
$reorderAt = (int)$product['reorder_level'];
$stockTone = $qtyNow <= 0 ? 'red' : ($qtyNow <= $reorderAt ? 'orange' : 'green');
$stockLabel = $qtyNow <= 0 ? 'Out of Stock' : ($qtyNow <= $reorderAt ? 'Low Stock' : 'In Stock');
$stockPct = $qtyNow > 0 ? round(min(100, $qtyNow / max((int)$product['max_stock_level'], $reorderAt * 3, $qtyNow, 1) * 100), 1) : 0;
$viewMargin = (float)$product['selling_price'] > 0 ? ((float)$product['selling_price'] - (float)$product['cost_price']) / (float)$product['selling_price'] * 100 : null;

$page_title = 'Item Master — ' . $product['name'];
require __DIR__ . '/../includes/header.php';
?>
<div class="inv-view">
<div class="dash-head inv-doc-head">
  <div>
    <nav class="inv-crumbs" aria-label="Breadcrumb">
      <a href="index.php">Inventory</a> <i class="fa-solid fa-chevron-right"></i>
      <a href="products.php">Products</a> <i class="fa-solid fa-chevron-right"></i>
      <span><?= e($product['sku']) ?></span>
    </nav>
    <h1 class="dash-title"><?= e($product['name']) ?>
      <span class="dash-pill <?= $product['status'] === 'active' ? 'dash-pill-green' : 'dash-pill-gray' ?>"><?= $product['status'] === 'active' ? 'Enabled' : 'Disabled' ?></span>
      <span class="dash-pill dash-pill-<?= $stockTone ?>"><?= $stockLabel ?></span>
    </h1>
    <p class="dash-sub"><?= e($product['sku']) ?><?= $product['category_name'] ? ' &middot; ' . e($product['category_name']) : '' ?><?= $product['brand_name'] ? ' &middot; ' . e($product['brand_name']) : '' ?></p>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a href="products.php" class="btn btn-outline-secondary"><i class="fa-solid fa-arrow-left"></i> Products</a>
    <a href="<?= base_url('print.php?doctype=product&id=' . $id) ?>" target="_blank" class="btn btn-outline-secondary"><i class="fa-solid fa-print"></i> Print</a>
    <?php if (can_edit_module('supply-chain')): ?><a href="<?= base_url('supply-chain/stock_entry_form.php') ?>" class="btn btn-outline-brand"><i class="fa-solid fa-dolly"></i> Stock Entry</a><?php endif; ?>
    <?php if ($canEdit): ?><a href="product_form.php?id=<?= $id ?>" class="btn btn-brand"><i class="fa-solid fa-pen"></i> Edit</a><?php endif; ?>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-3">
    <div class="card p-3">
      <div class="inv-hero-img">
        <?php if (!empty($product['image'])): ?>
          <img id="heroImg" src="<?= base_url($product['image']) ?>" alt="">
        <?php else: ?>
          <i class="fa-solid fa-box fa-3x"></i>
        <?php endif; ?>
      </div>
      <?php if (count($productImages) > 1): ?>
        <div class="d-flex gap-2 flex-wrap mt-2">
          <?php foreach ($productImages as $img): ?>
            <img src="<?= base_url($img['image']) ?>" alt="" onclick="document.getElementById('heroImg').src=this.src"
                 style="width:48px;height:48px;object-fit:cover;border-radius:6px;border:1px solid var(--card-border);cursor:pointer">
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <div class="inv-facts">
        <div><span>Selling Price</span><strong><?= money($product['selling_price']) ?></strong></div>
        <div><span>Cost Price</span><strong><?= money($product['cost_price']) ?></strong></div>
        <div><span>Margin</span><strong><?= $viewMargin === null ? '—' : number_format($viewMargin, 1) . '%' ?></strong></div>
        <div><span>Stock Value</span><strong><?= money(max(0, (int)$product['quantity']) * (float)$product['cost_price']) ?></strong></div>
      </div>
      <div class="mt-3">
        <div class="d-flex justify-content-between small">
          <span class="fw-semibold"><?= (int)$product['quantity'] ?> <?= e($product['unit']) ?> in stock</span>
          <span class="inv-stock-<?= $stockTone ?>">Reorder at <?= (int)$product['reorder_level'] ?></span>
        </div>
        <div class="inv-bar inv-bar-<?= $stockTone ?>"><span style="width: <?= $stockPct ?>%"></span></div>
      </div>
    </div>
    <div class="card p-3 mt-3">
      <h6 class="mb-2">Stock by Warehouse</h6>
      <?php $whMax = max(1, ...array_map(fn($w) => (int)$w['quantity'], $stockByWarehouse ?: [['quantity' => 0]])); ?>
      <?php foreach ($stockByWarehouse as $w): ?>
        <div class="inv-wh-row">
          <div class="d-flex justify-content-between small"><span><?= e($w['name']) ?></span><strong><?= (int)$w['quantity'] ?> <?= e($product['unit']) ?></strong></div>
          <div class="inv-bar"><span style="width: <?= max(0, round((int)$w['quantity'] / $whMax * 100, 1)) ?>%"></span></div>
        </div>
      <?php endforeach; ?>
      <?php if (!$stockByWarehouse): ?><div class="text-muted small">No warehouses set up yet.</div><?php endif; ?>
    </div>
    <div class="card p-3 mt-3">
      <h6 class="mb-2">Item Information</h6>
      <div class="small">
        <div class="d-flex justify-content-between"><span class="text-muted">Item Category</span><span><?= e($product['item_category_name'] ?: '—') ?></span></div>
        <div class="d-flex justify-content-between"><span class="text-muted">HSN/SAC Code</span><span><?= e($product['hsn_sac_code'] ?: '—') ?></span></div>
        <div class="d-flex justify-content-between"><span class="text-muted">Item Type</span><span><?= e($product['item_type']) ?></span></div>
        <div class="d-flex justify-content-between"><span class="text-muted">Valuation Method</span><span><?= e($product['valuation_method']) ?></span></div>
        <div class="d-flex justify-content-between"><span class="text-muted">Default Warehouse</span><span><?= e($product['default_warehouse_name'] ?: '—') ?></span></div>
        <div class="d-flex justify-content-between"><span class="text-muted">Default Price List</span><span><?= e($product['default_price_list_name'] ?: '—') ?></span></div>
      </div>
      <hr>
      <div class="d-flex flex-wrap gap-1">
        <?php
        $flagBadges = [
            'is_stock_item' => 'Stock', 'is_sales_item' => 'Sales', 'is_purchase_item' => 'Purchase',
            'is_manufactured_item' => 'Manufactured', 'is_sub_contracted_item' => 'Sub-Contracted',
            'is_asset_item' => 'Asset', 'has_variants' => 'Has Variants',
        ];
        ?>
        <?php foreach ($flagBadges as $fKey => $fLabel): ?>
          <?php if (!empty($product[$fKey])): ?><span class="badge text-bg-light border"><?= e($fLabel) ?></span><?php endif; ?>
        <?php endforeach; ?>
      </div>
      <?php if ($product['tags']): ?><div class="mt-2 small text-muted"><?= e($product['tags']) ?></div><?php endif; ?>
    </div>
    <?php if ($customFields): ?>
    <div class="card p-3 mt-3">
      <h6 class="mb-2">Custom Fields</h6>
      <div class="small">
        <?php foreach ($customFields as $cf): ?>
          <div class="d-flex justify-content-between"><span class="text-muted"><?= e($cf['label']) ?></span><span><?= $cf['field_type'] === 'checkbox' ? ($cf['value'] === '1' ? 'Yes' : 'No') : e($cf['value']) ?></span></div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
    <div class="card p-3 mt-3">
      <h6 class="mb-2">Inventory Settings</h6>
      <div class="small">
        <div class="d-flex justify-content-between"><span class="text-muted">Stock Item Type</span><span><?= e($product['stock_item_type']) ?></span></div>
        <div class="d-flex justify-content-between"><span class="text-muted">Reorder Qty</span><span><?= (int)$product['reorder_qty'] ?></span></div>
        <div class="d-flex justify-content-between"><span class="text-muted">Safety Stock</span><span><?= (int)$product['safety_stock'] ?></span></div>
        <div class="d-flex justify-content-between"><span class="text-muted">Issue / Receipt Method</span><span><?= e($product['issue_method']) ?> / <?= e($product['receipt_method']) ?></span></div>
        <?php $storageParts = array_filter([$product['storage_section'], $product['storage_rack'], $product['storage_shelf'], $product['storage_bin']]); ?>
        <div class="d-flex justify-content-between"><span class="text-muted">Storage Location</span><span><?= $storageParts ? e(implode(' / ', $storageParts)) : '—' ?></span></div>
      </div>
      <?php if ($product['has_batch_no'] || $product['has_serial_no']): ?>
      <hr>
      <div class="d-flex flex-wrap gap-1">
        <?php if ($product['has_batch_no']): ?><span class="badge text-bg-light border">Has Batch No.</span><?php endif; ?>
        <?php if ($product['has_serial_no']): ?><span class="badge text-bg-light border">Has Serial No.</span><?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
    <div class="card p-3 mt-3">
      <h6 class="mb-2">Units of Measure</h6>
      <div class="small">
        <div class="d-flex justify-content-between"><span class="text-muted">Stock UOM</span><span><?= e($product['unit']) ?></span></div>
        <div class="d-flex justify-content-between"><span class="text-muted">Default Purchase UOM</span><span><?= e($product['purchase_uom'] ?: $product['unit']) ?> (&times;<?= e(number_format((float)$product['purchase_uom_conversion_factor'], 3)) ?>)</span></div>
        <div class="d-flex justify-content-between"><span class="text-muted">Default Sales UOM</span><span><?= e($product['sales_uom'] ?: $product['unit']) ?> (&times;<?= e(number_format((float)$product['sales_uom_conversion_factor'], 3)) ?>)</span></div>
      </div>
      <?php if ($productUoms): ?>
      <hr>
      <div class="small text-muted mb-1">Alternate UOMs</div>
      <?php foreach ($productUoms as $pu): ?>
        <div class="d-flex justify-content-between small"><span>1 <?= e($pu['uom']) ?></span><span>= <?= e(number_format((float)$pu['conversion_factor'], 3)) ?> <?= e($product['unit']) ?></span></div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
    <?php if ($product['has_batch_no'] || $product['has_serial_no']): ?>
    <div class="card p-3 mt-3">
      <h6 class="mb-2">Batches &amp; Serial Numbers</h6>
      <?php if ($product['has_batch_no']): ?>
        <?php if (!$productBatches): ?>
          <p class="text-muted small mb-0">No batches received yet.</p>
        <?php else: ?>
        <div class="small text-muted mb-1">Batches</div>
        <?php foreach ($productBatches as $b): ?>
          <div class="d-flex justify-content-between small"><span><?= e($b['batch_no']) ?><?= $b['expiry_date'] ? ' (exp. ' . e($b['expiry_date']) . ')' : '' ?></span><span><?= (int)$b['qty_in_stock'] ?> in stock</span></div>
        <?php endforeach; ?>
        <?php endif; ?>
      <?php endif; ?>
      <?php if ($product['has_serial_no']): ?>
        <?php if ($product['has_batch_no']): ?><hr><?php endif; ?>
        <?php if (!$productSerialStatusCounts): ?>
          <p class="text-muted small mb-0">No serial numbers received yet.</p>
        <?php else: ?>
        <div class="small text-muted mb-1">Serial Numbers</div>
        <?php foreach ($productSerialStatusCounts as $status => $count): ?>
          <div class="d-flex justify-content-between small"><span class="text-capitalize"><?= e(str_replace('_', ' ', $status)) ?></span><span><?= $count ?></span></div>
        <?php endforeach; ?>
        <div class="mt-2"><a href="<?= base_url('inventory/product_form.php?id=' . (int)$id . '&tab=batch') ?>" class="small">View all serial numbers &rarr;</a></div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="card p-3 mt-3">
      <h6 class="mb-2">Pricing</h6>
      <div class="small">
        <div class="d-flex justify-content-between"><span class="text-muted">Last Purchase Rate</span><span><?= money($product['last_purchase_rate']) ?><?= $product['last_purchase_date'] ? ' (' . e($product['last_purchase_date']) . ')' : '' ?></span></div>
        <div class="d-flex justify-content-between"><span class="text-muted">Average Purchase Rate</span><span><?= money($product['average_purchase_rate']) ?></span></div>
        <div class="d-flex justify-content-between"><span class="text-muted">Discount</span><span><?= $product['allow_discount'] ? 'Up to ' . e(number_format((float)$product['max_discount_percent'], 2)) . '%' : 'Not allowed' ?></span></div>
        <?php if ($product['minimum_selling_price'] > 0 || $product['maximum_selling_price'] > 0): ?>
        <div class="d-flex justify-content-between"><span class="text-muted">Selling Price Range</span><span><?= money($product['minimum_selling_price']) ?> – <?= money($product['maximum_selling_price']) ?></span></div>
        <?php endif; ?>
      </div>
      <?php if ($priceListRates): ?>
      <hr>
      <div class="small text-muted mb-1">Price List Rates</div>
      <?php foreach ($priceListRates as $r): ?>
        <div class="d-flex justify-content-between small"><span><?= e($r['price_list_name']) ?></span><span><?= money($r['rate']) ?></span></div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
    <?php if ($customerPrices): ?>
    <div class="card p-3 mt-3">
      <h6 class="mb-2">Customer Specific Pricing</h6>
      <table class="table table-sm mb-0">
        <thead><tr><th>Customer</th><th class="text-end">Rate</th><th class="text-end">Disc. %</th></tr></thead>
        <tbody>
        <?php foreach ($customerPrices as $cp): ?>
          <tr>
            <td><?= e($cp['customer_name']) ?><?= $cp['price_list_name'] ? '<div class="text-muted small">' . e($cp['price_list_name']) . '</div>' : '' ?></td>
            <td class="text-end"><?= money($cp['rate']) ?></td>
            <td class="text-end"><?= e(number_format((float)$cp['discount_percent'], 2)) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
    <div class="card p-3 mt-3">
      <h6 class="mb-2">Accounting &amp; Tax</h6>
      <div class="small">
        <div class="d-flex justify-content-between"><span class="text-muted">Cost Center</span><span><?= e($product['cost_center'] ?: '—') ?></span></div>
        <div class="d-flex justify-content-between"><span class="text-muted">Tax Category</span><span><?= e($product['tax_category'] ?: '—') ?></span></div>
        <div class="d-flex justify-content-between"><span class="text-muted">Tax Template</span><span><?= e($defaultTaxTemplateName ?: '—') ?></span></div>
        <div class="d-flex justify-content-between"><span class="text-muted">Price Includes Tax</span><span><?= $product['price_includes_tax'] ? 'Yes' : 'No' ?></span></div>
      </div>
      <?php if ($product['is_nil_rated'] || $product['is_exempt_from_tax'] || $product['reverse_charge_applicable'] || $product['tds_applicable']): ?>
      <hr>
      <div class="d-flex flex-wrap gap-1">
        <?php if ($product['is_nil_rated']): ?><span class="badge text-bg-light border">Nil Rated</span><?php endif; ?>
        <?php if ($product['is_exempt_from_tax']): ?><span class="badge text-bg-light border">Exempt from Tax</span><?php endif; ?>
        <?php if ($product['reverse_charge_applicable']): ?><span class="badge text-bg-light border">Reverse Charge</span><?php endif; ?>
        <?php if ($product['tds_applicable']): ?><span class="badge text-bg-light border">TDS Applicable</span><?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php if ($productTaxes): ?>
    <div class="card p-3 mt-3">
      <h6 class="mb-2">Default Taxes and Charges</h6>
      <table class="table table-sm mb-0">
        <thead><tr><th>Type</th><th>Account Head</th><th class="text-end">Rate/Amount</th></tr></thead>
        <tbody>
        <?php foreach ($productTaxes as $tx): ?>
          <tr>
            <td><?= e($tx['tax_charge_type'] ?: '—') ?></td>
            <td><?= e($tx['account_name'] ?: '—') ?></td>
            <td class="text-end"><?= e(number_format((float)$tx['rate_or_amount'], 2)) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
    <?php if ($productCharges): ?>
    <div class="card p-3 mt-3">
      <h6 class="mb-2">Additional Charges</h6>
      <table class="table table-sm mb-0">
        <thead><tr><th>Type</th><th>Account Head</th><th class="text-end">Rate/Amount</th></tr></thead>
        <tbody>
        <?php foreach ($productCharges as $ch): ?>
          <tr>
            <td><?= e($ch['charge_type'] ?: '—') ?></td>
            <td><?= e($ch['account_name'] ?: '—') ?></td>
            <td class="text-end"><?= e(number_format((float)$ch['rate_or_amount'], 2)) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
    <div class="card p-3 mt-3">
      <h6 class="mb-2">Sales &amp; Purchase</h6>
      <div class="small">
        <div class="d-flex justify-content-between"><span class="text-muted">Item Customer Group</span><span><?= e($product['item_customer_group'] ?: '—') ?></span></div>
        <div class="d-flex justify-content-between"><span class="text-muted">Default Supplier</span><span><?= e($defaultSupplierName ?: '—') ?></span></div>
        <div class="d-flex justify-content-between"><span class="text-muted">Sales Lead Time</span><span><?= (int)$product['sales_lead_time_days'] ?> days</span></div>
        <div class="d-flex justify-content-between"><span class="text-muted">Delivery Time</span><span><?= (int)$product['delivery_time_days'] ?> days</span></div>
      </div>
      <hr>
      <div class="d-flex flex-wrap gap-1">
        <?php
        $spBadges = [
            'available_for_online_sales' => 'Online', 'available_for_retail_sales' => 'Retail', 'available_for_b2b_sales' => 'B2B',
            'requires_purchase_order' => 'Requires PO', 'is_drop_ship_item' => 'Drop Ship',
        ];
        ?>
        <?php foreach ($spBadges as $spKey => $spLabel): ?>
          <?php if (!empty($product[$spKey])): ?><span class="badge text-bg-light border"><?= e($spLabel) ?></span><?php endif; ?>
        <?php endforeach; ?>
      </div>
      <?php if ($product['sales_description']): ?><div class="mt-2 small text-muted"><?= e($product['sales_description']) ?></div><?php endif; ?>
    </div>
    <?php if ($productSuppliers): ?>
    <div class="card p-3 mt-3">
      <h6 class="mb-2">Preferred Suppliers</h6>
      <table class="table table-sm mb-0">
        <thead><tr><th>Supplier</th><th>Part No.</th><th class="text-end">Last Rate</th></tr></thead>
        <tbody>
        <?php foreach ($productSuppliers as $ps): ?>
          <tr>
            <td><?= e($ps['supplier_name']) ?><?= $ps['is_preferred'] ? ' <span class="badge text-bg-brand">Preferred</span>' : '' ?></td>
            <td><?= e($ps['supplier_part_no'] ?: '—') ?></td>
            <td class="text-end"><?= money($ps['last_purchase_rate']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
    <?php if ($productCustomerRules): ?>
    <div class="card p-3 mt-3">
      <h6 class="mb-2">Customer Specific Sales Rules</h6>
      <table class="table table-sm mb-0">
        <thead><tr><th>Customer</th><th class="text-end">Disc. %</th><th class="text-end">Min–Max Qty</th></tr></thead>
        <tbody>
        <?php foreach ($productCustomerRules as $cr): ?>
          <tr>
            <td><?= e($cr['customer_name']) ?><?= $cr['customer_group'] ? '<div class="text-muted small">' . e($cr['customer_group']) . '</div>' : '' ?></td>
            <td class="text-end"><?= e(number_format((float)$cr['discount_percent'], 2)) ?></td>
            <td class="text-end"><?= (int)$cr['min_qty'] ?>–<?= (int)$cr['max_qty'] ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
    <?php if ($barcodes): ?>
    <div class="card p-3 mt-3">
      <h6 class="mb-2">Barcodes</h6>
      <table class="table table-sm mb-0">
        <thead><tr><th>Barcode</th><th>Type</th><th>UOM</th></tr></thead>
        <tbody>
        <?php foreach ($barcodes as $bc): ?>
          <tr>
            <td><?= e($bc['barcode']) ?><?= $bc['is_default'] ? ' <span class="badge text-bg-brand">Default</span>' : '' ?></td>
            <td><?= e($bc['barcode_type'] ?: '—') ?></td>
            <td><?= e($bc['uom'] ?: '—') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <div class="col-lg-9">
    <div class="dash-kpis inv-kpis-4 mb-3">
      <div class="dash-kpi dash-kpi-green">
        <span class="dash-kpi-icon"><i class="fa-solid fa-arrow-trend-up"></i></span>
        <span class="dash-kpi-body"><span class="dash-kpi-label">Units Sold</span><span class="dash-kpi-value"><?= number_format((int)$lifetimeSales['qty']) ?></span><span class="dash-kpi-foot text-muted">lifetime</span></span>
      </div>
      <div class="dash-kpi dash-kpi-blue">
        <span class="dash-kpi-icon"><i class="fa-solid fa-sack-dollar"></i></span>
        <span class="dash-kpi-body"><span class="dash-kpi-label">Revenue</span><span class="dash-kpi-value"><?= money($lifetimeSales['amount']) ?></span><span class="dash-kpi-foot text-muted">lifetime</span></span>
      </div>
      <div class="dash-kpi dash-kpi-orange">
        <span class="dash-kpi-icon"><i class="fa-solid fa-truck-ramp-box"></i></span>
        <span class="dash-kpi-body"><span class="dash-kpi-label">Units Purchased</span><span class="dash-kpi-value"><?= number_format((int)$lifetimePurchases['qty']) ?></span><span class="dash-kpi-foot text-muted">lifetime</span></span>
      </div>
      <div class="dash-kpi dash-kpi-purple">
        <span class="dash-kpi-icon"><i class="fa-solid fa-receipt"></i></span>
        <span class="dash-kpi-body"><span class="dash-kpi-label">Purchase Spend</span><span class="dash-kpi-value"><?= money($lifetimePurchases['amount']) ?></span><span class="dash-kpi-foot text-muted">lifetime</span></span>
      </div>
    </div>

    <div class="card p-3 mb-3">
      <h6 class="mb-0">Sales Trend</h6>
      <p class="dash-card-sub mb-2">Units and revenue by month, last 12 months.</p>
      <div class="inv-trend"><canvas id="salesTrendChart"></canvas></div>
    </div>
    <div class="card p-3">
      <h6 class="mb-0">Purchase Trend</h6>
      <p class="dash-card-sub mb-2">Units and spend by month, last 12 months.</p>
      <div class="inv-trend"><canvas id="purchaseTrendChart"></canvas></div>
    </div>
  </div>
</div>

<div class="card p-3">
  <h6 class="mb-3">Connections</h6>
  <ul class="nav nav-tabs inv-doc-tabs" role="tablist">
    <?php $first = true; foreach ($connections as $key => $c): ?>
      <li class="nav-item" role="presentation">
        <button class="nav-link <?= $first ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tab-<?= e($key) ?>" type="button">
          <i class="<?= e($c['icon']) ?>"></i> <?= e($c['label']) ?> <span class="inv-tab-count"><?= (int)$c['data']['total'] ?></span>
        </button>
      </li>
    <?php $first = false; endforeach; ?>
    <li class="nav-item" role="presentation">
      <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-movements" type="button">
        <i class="fa-solid fa-arrow-right-arrow-left"></i> Stock Movements <span class="inv-tab-count"><?= (int)$movements['total'] ?></span>
      </button>
    </li>
  </ul>
  <div class="tab-content pt-3">
    <?php $first = true; foreach ($connections as $key => $c): ?>
      <div class="tab-pane fade <?= $first ? 'show active' : '' ?>" id="tab-<?= e($key) ?>">
        <div class="table-responsive">
          <table class="table dash-table mb-2">
            <thead><tr><th>Doc #</th><th>Party</th><th>Date</th><th class="text-end">Qty</th><th class="text-end">Amount</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($c['data']['rows'] as $r): ?>
              <tr>
                <td><a href="<?= $c['view_url']($r) ?>"><?= e($r['doc_no']) ?></a></td>
                <td><?= e($r['party']) ?></td>
                <td><?= e($r['doc_date']) ?></td>
                <td class="text-end"><?= (int)$r['quantity'] ?></td>
                <td class="text-end"><?= money($r['amount']) ?></td>
                <td><span class="dash-pill dash-pill-<?= $statusBadge[$r['status']] ?? 'gray' ?>"><?= e(ucfirst(str_replace('_', ' ', $r['status']))) ?></span></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$c['data']['rows']): ?><tr><td colspan="6" class="text-muted text-center">No <?= e(strtolower($c['label'])) ?> for this item yet.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
        <?php if ($c['data']['total'] > count($c['data']['rows'])): ?>
          <div class="text-muted small">Showing <?= count($c['data']['rows']) ?> of <?= (int)$c['data']['total'] ?>.</div>
        <?php endif; ?>
      </div>
    <?php $first = false; endforeach; ?>

    <div class="tab-pane fade" id="tab-movements">
      <div class="table-responsive">
        <table class="table dash-table mb-2">
          <thead><tr><th>Date</th><th>Type</th><th>Warehouse</th><th class="text-end">Qty</th><th>Reference</th></tr></thead>
          <tbody>
          <?php $moveBadge = ['in' => 'green', 'out' => 'red', 'adjustment' => 'orange']; ?>
          <?php foreach ($movements['rows'] as $m): ?>
            <tr>
              <td><?= e(date('Y-m-d H:i', strtotime($m['created_at']))) ?></td>
              <td><span class="dash-pill dash-pill-<?= $moveBadge[$m['type']] ?? 'gray' ?>"><?= e(ucfirst($m['type'])) ?></span></td>
              <td><?= e($m['warehouse_name'] ?? '—') ?></td>
              <td class="text-end fw-bold <?= (int)$m['quantity'] < 0 ? 'text-danger' : 'text-success' ?>"><?= (int)$m['quantity'] > 0 ? '+' : '' ?><?= (int)$m['quantity'] ?></td>
              <td><?= e($m['reference']) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$movements['rows']): ?><tr><td colspan="5" class="text-muted text-center">No stock movements for this item yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
      <?php if ($movements['total'] > count($movements['rows'])): ?>
        <div class="text-muted small">Showing <?= count($movements['rows']) ?> of <?= (int)$movements['total'] ?>. See Supply Chain &rsaquo; Stock Movements for the full history.</div>
      <?php endif; ?>
    </div>
  </div>
</div>
</div>
<?php
$extra_js = ['https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js'];
$extra_js_inline = "
function trendChart(canvasId, labels, qty, amount, qtyLabel, amountLabel) {
  new Chart(document.getElementById(canvasId), {
    data: {
      labels: labels,
      datasets: [
        { type: 'bar', label: qtyLabel, data: qty, backgroundColor: '#2563eb', borderRadius: 4, maxBarThickness: 22, yAxisID: 'y' },
        { type: 'line', label: amountLabel, data: amount, borderColor: '#16a34a', backgroundColor: '#16a34a', tension: 0.35, pointRadius: 2, yAxisID: 'y1' }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, pointStyle: 'circle', boxWidth: 8, color: '#374151' } } },
      scales: {
        x: { grid: { display: false }, ticks: { color: '#6b7280' } },
        y: { beginAtZero: true, position: 'left', grid: { color: '#eef1f6' }, ticks: { precision: 0, color: '#6b7280' } },
        y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, ticks: { color: '#6b7280' } }
      }
    }
  });
}
trendChart('salesTrendChart', " . json_encode($salesTrend['labels']) . ", " . json_encode($salesTrend['qty']) . ", " . json_encode($salesTrend['amount']) . ", 'Units Sold', 'Revenue');
trendChart('purchaseTrendChart', " . json_encode($purchaseTrend['labels']) . ", " . json_encode($purchaseTrend['qty']) . ", " . json_encode($purchaseTrend['amount']) . ", 'Units Purchased', 'Spend');
";
require __DIR__ . '/../includes/footer.php';
