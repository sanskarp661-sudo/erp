<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
$canEdit = can_edit_module('sales');
$canManage = can_manage_module('sales');

$id = (int)input('id');
$stmt = db()->prepare('
  SELECT so.*, c.name customer_name, c.email customer_email, c.phone customer_phone, w.name warehouse_name,
         pl.name price_list_name, u.name sales_person_name, ca.label address_label, ca.address_line, ca.city, ca.state, ca.pincode,
         sa.label ship_to_label, sa.address_line ship_to_address_line, sa.city ship_to_city, sa.contact_person ship_to_contact_person,
         sa.contact_phone ship_to_contact_phone, sp.name shipping_partner_name, q.quotation_no
  FROM sales_orders so
  JOIN customers c ON c.id = so.customer_id
  LEFT JOIN warehouses w ON w.id = so.warehouse_id
  LEFT JOIN price_lists pl ON pl.id = so.price_list_id
  LEFT JOIN users u ON u.id = so.sales_person_id
  LEFT JOIN customer_addresses ca ON ca.id = so.customer_address_id
  LEFT JOIN customer_addresses sa ON sa.id = so.ship_to_address_id
  LEFT JOIN shipping_partners sp ON sp.id = so.shipping_partner_id
  LEFT JOIN quotations q ON q.id = so.quotation_id
  WHERE so.id = ?
');
$stmt->execute([$id]);
$order = $stmt->fetch();

if (!$order) {
    flash('danger', 'Sales order not found.');
    redirect('/sales/orders.php');
}

if (is_post() && input('action') === 'transition') {
    $newStatus = input('status');
    if ($newStatus === 'cancelled') {
        require_module_manage('sales');
    } else {
        require_module_edit('sales');
    }
    csrf_verify();
    $valid = [
        'pending'   => ['confirmed', 'cancelled'],
        'confirmed' => ['shipped', 'cancelled'],
        'shipped'   => ['completed', 'cancelled'],
    ];
    if (!isset($valid[$order['status']]) || !in_array($newStatus, $valid[$order['status']], true)) {
        flash('danger', 'That status change is not allowed.');
        redirect('/sales/order_view.php?id=' . $id);
    }

    // Sales orders no longer touch stock directly — only a Delivery Note
    // (created separately, once confirmed/shipped) actually deducts it.
    try {
        db()->prepare('UPDATE sales_orders SET status = ? WHERE id = ?')->execute([$newStatus, $id]);
        flash('success', 'Order status updated to "' . $newStatus . '".');
    } catch (Exception $e) {
        flash('danger', 'Could not update order status.');
    }
    redirect('/sales/order_view.php?id=' . $id);
}

$items = db()->prepare('SELECT soi.*, p.name product_name, p.sku, w.name item_warehouse_name FROM sales_order_items soi JOIN products p ON p.id = soi.product_id LEFT JOIN warehouses w ON w.id = soi.warehouse_id WHERE order_id = ?');
$items->execute([$id]);
$items = $items->fetchAll();

$taxRows = db()->prepare('SELECT sot.*, la.name account_name FROM sales_order_taxes sot LEFT JOIN ledger_accounts la ON la.id = sot.account_head_id WHERE order_id = ? ORDER BY sort_order, id');
$taxRows->execute([$id]);
$taxRows = $taxRows->fetchAll();

$paymentSchedule = db()->prepare('SELECT * FROM sales_order_payment_schedule WHERE order_id = ? ORDER BY sort_order, id');
$paymentSchedule->execute([$id]);
$paymentSchedule = $paymentSchedule->fetchAll();

$salesTeam = db()->prepare('SELECT sst.*, u.name sales_person_name FROM sales_order_sales_team sst JOIN users u ON u.id = sst.sales_person_id WHERE order_id = ? ORDER BY sort_order, sst.id');
$salesTeam->execute([$id]);
$salesTeam = $salesTeam->fetchAll();

$dnStmt = db()->prepare('SELECT id, dn_no, status FROM delivery_notes WHERE sales_order_id = ? LIMIT 1');
$dnStmt->execute([$id]);
$existingDn = $dnStmt->fetch();

$invStmt = db()->prepare('SELECT id, invoice_no, status FROM invoices WHERE sales_order_id = ? LIMIT 1');
$invStmt->execute([$id]);
$existingInvoice = $invStmt->fetch();

$returnsStmt = db()->prepare('SELECT id, return_no, status, return_date, total_amount FROM sales_returns WHERE sales_order_id = ? ORDER BY id DESC');
$returnsStmt->execute([$id]);
$returns = $returnsStmt->fetchAll();
$returnBadge = ['draft' => 'secondary', 'completed' => 'success', 'cancelled' => 'danger'];

$badge = ['pending' => 'secondary', 'confirmed' => 'info', 'shipped' => 'primary', 'completed' => 'success', 'cancelled' => 'danger'];
$dnBadge = ['draft' => 'secondary', 'delivered' => 'success', 'cancelled' => 'danger'];
$invBadge = ['unpaid' => 'secondary', 'partially_paid' => 'warning', 'paid' => 'success', 'overdue' => 'danger', 'cancelled' => 'dark'];

$page_title = 'Sales Order ' . $order['order_no'];
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-1"><?= e($order['order_no']) ?> <span class="badge text-bg-<?= $badge[$order['status']] ?> badge-status"><?= e($order['status']) ?></span></h4>
    <div class="text-muted"><?= e($order['customer_name']) ?><?= $order['warehouse_name'] ? ' &middot; ' . e($order['warehouse_name']) : '' ?> &middot; <?= e($order['order_date']) ?></div>
  </div>
  <div class="page-actions">
    <?php if ($order['status'] === 'pending'): ?>
      <?php if ($canEdit): ?>
      <a href="order_form.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-pen"></i> Edit</a>
      <form method="post" class="d-inline" data-confirm="Confirm this order?">
        <?= csrf_field() ?><input type="hidden" name="action" value="transition"><input type="hidden" name="status" value="confirmed">
        <button class="btn btn-brand btn-sm" type="submit"><i class="fa-solid fa-check"></i> Confirm Order</button>
      </form>
      <?php endif; ?>
      <?php if ($canManage): ?>
      <form method="post" class="d-inline" data-confirm="Cancel this order?">
        <?= csrf_field() ?><input type="hidden" name="action" value="transition"><input type="hidden" name="status" value="cancelled">
        <button class="btn btn-outline-danger btn-sm" type="submit"><i class="fa-solid fa-ban"></i> Cancel</button>
      </form>
      <?php endif; ?>
    <?php elseif ($order['status'] === 'confirmed'): ?>
      <?php if ($canEdit): ?>
      <form method="post" class="d-inline" data-confirm="Mark this order as shipped?">
        <?= csrf_field() ?><input type="hidden" name="action" value="transition"><input type="hidden" name="status" value="shipped">
        <button class="btn btn-brand btn-sm" type="submit"><i class="fa-solid fa-truck"></i> Mark Shipped</button>
      </form>
      <?php endif; ?>
      <?php if ($canManage): ?>
      <form method="post" class="d-inline" data-confirm="Cancel this order?">
        <?= csrf_field() ?><input type="hidden" name="action" value="transition"><input type="hidden" name="status" value="cancelled">
        <button class="btn btn-outline-danger btn-sm" type="submit"><i class="fa-solid fa-ban"></i> Cancel</button>
      </form>
      <?php endif; ?>
    <?php elseif ($order['status'] === 'shipped'): ?>
      <?php if ($canEdit): ?>
      <form method="post" class="d-inline" data-confirm="Mark this order as completed?">
        <?= csrf_field() ?><input type="hidden" name="action" value="transition"><input type="hidden" name="status" value="completed">
        <button class="btn btn-brand btn-sm" type="submit"><i class="fa-solid fa-flag-checkered"></i> Mark Completed</button>
      </form>
      <?php endif; ?>
      <?php if ($canManage): ?>
      <form method="post" class="d-inline" data-confirm="Cancel this order?">
        <?= csrf_field() ?><input type="hidden" name="action" value="transition"><input type="hidden" name="status" value="cancelled">
        <button class="btn btn-outline-danger btn-sm" type="submit"><i class="fa-solid fa-ban"></i> Cancel</button>
      </form>
      <?php endif; ?>
    <?php endif; ?>

    <?php if (in_array($order['status'], ['confirmed', 'shipped'], true)): ?>
      <?php if ($existingDn): ?>
        <a href="<?= base_url('sales/delivery_note_view.php?id=' . $existingDn['id']) ?>" class="btn btn-outline-brand btn-sm"><i class="fa-solid fa-truck"></i> View Delivery Note <?= e($existingDn['dn_no']) ?></a>
      <?php elseif ($canEdit): ?>
        <a href="<?= base_url('sales/delivery_note_form.php?from_order=' . $id) ?>" class="btn btn-outline-brand btn-sm"><i class="fa-solid fa-truck"></i> Create Delivery Note</a>
      <?php endif; ?>
    <?php endif; ?>
    <?php if ($existingDn && $existingDn['status'] === 'delivered' && $canEdit): ?>
      <a href="<?= base_url('sales/return_form.php?from_order=' . $id) ?>" class="btn btn-outline-brand btn-sm"><i class="fa-solid fa-rotate-left"></i> New Sales Return</a>
    <?php endif; ?>
    <a href="<?= base_url('print.php?doctype=sales_order&id=' . $id) ?>" target="_blank" class="btn btn-outline-brand btn-sm"><i class="fa-solid fa-print"></i> Print</a>
    <a href="orders.php" class="btn btn-outline-secondary btn-sm">Back to list</a>
  </div>
</div>

<div class="card p-3 mb-3">
  <h6 class="mb-3">Document Flow</h6>
  <div class="d-flex align-items-center flex-wrap gap-3">
    <div style="min-width:150px">
      <div class="small text-muted">Sales Order</div>
      <div class="fw-bold"><?= e($order['order_no']) ?></div>
      <span class="badge text-bg-<?= $badge[$order['status']] ?> badge-status"><?= e($order['status']) ?></span>
    </div>
    <i class="fa-solid fa-arrow-right text-muted"></i>
    <div style="min-width:150px">
      <div class="small text-muted">Delivery Note</div>
      <?php if ($existingDn): ?>
        <div class="fw-bold"><a href="<?= base_url('sales/delivery_note_view.php?id=' . (int)$existingDn['id']) ?>"><?= e($existingDn['dn_no']) ?></a></div>
        <span class="badge text-bg-<?= $dnBadge[$existingDn['status']] ?? 'secondary' ?> badge-status"><?= e($existingDn['status']) ?></span>
      <?php else: ?>
        <div class="text-muted">Not created</div>
      <?php endif; ?>
    </div>
    <i class="fa-solid fa-arrow-right text-muted"></i>
    <div style="min-width:150px">
      <div class="small text-muted">Sales Invoice</div>
      <?php if ($existingInvoice): ?>
        <div class="fw-bold"><a href="<?= base_url('accounting/invoice_view.php?id=' . (int)$existingInvoice['id']) ?>"><?= e($existingInvoice['invoice_no']) ?></a></div>
        <span class="badge text-bg-<?= $invBadge[$existingInvoice['status']] ?? 'secondary' ?> badge-status"><?= e(str_replace('_', ' ', $existingInvoice['status'])) ?></span>
      <?php else: ?>
        <div class="text-muted">Not created<?= (!$existingDn || $existingDn['status'] !== 'delivered') ? ' (needs a delivered Delivery Note)' : '' ?></div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="card p-3 mb-3">
  <h6 class="mb-3">Order Information</h6>
  <div class="row g-3">
    <div class="col-sm-3"><div class="small text-muted">Contact Person</div><div><?= e($order['contact_person'] ?: '—') ?></div></div>
    <div class="col-sm-3"><div class="small text-muted">Customer Address</div><div><?= $order['address_label'] ? e($order['address_label'] . ': ' . $order['address_line']) : '—' ?></div></div>
    <div class="col-sm-3"><div class="small text-muted">Required Delivery Date</div><div><?= e($order['required_delivery_date'] ?: '—') ?></div></div>
    <div class="col-sm-3"><div class="small text-muted">Sales Channel</div><div><?= e($order['sales_channel'] ?: '—') ?></div></div>
    <div class="col-sm-3"><div class="small text-muted">Price List</div><div><?= e($order['price_list_name'] ?: '—') ?></div></div>
    <div class="col-sm-3"><div class="small text-muted">Currency</div><div><?= e($order['currency']) ?></div></div>
    <div class="col-sm-3"><div class="small text-muted">Territory</div><div><?= e($order['territory'] ?: '—') ?></div></div>
    <div class="col-sm-3"><div class="small text-muted">Sales Person</div><div><?= e($order['sales_person_name'] ?: '—') ?></div></div>
    <div class="col-sm-3"><div class="small text-muted">Customer PO No.</div><div><?= e($order['customer_po_no'] ?: '—') ?></div></div>
    <div class="col-sm-3"><div class="small text-muted">Project</div><div><?= e($order['project'] ?: '—') ?></div></div>
  </div>
</div>

<div class="card p-3 mb-3">
  <h6 class="mb-3">Shipping &amp; Delivery</h6>
  <div class="row g-3">
    <div class="col-sm-3"><div class="small text-muted">Promised Delivery Date</div><div><?= e($order['promised_delivery_date'] ?: '—') ?></div></div>
    <div class="col-sm-3"><div class="small text-muted">Delivery Priority</div><div class="text-capitalize"><?= e($order['delivery_priority']) ?></div></div>
    <div class="col-sm-3"><div class="small text-muted">Ship To</div><div><?= $order['ship_to_label'] ? e($order['ship_to_label'] . ': ' . $order['ship_to_address_line']) : '—' ?></div></div>
    <div class="col-sm-3"><div class="small text-muted">Ship To Contact</div><div><?= e($order['ship_to_contact_person'] ?: '—') ?><?= $order['ship_to_contact_phone'] ? ' · ' . e($order['ship_to_contact_phone']) : '' ?></div></div>
    <div class="col-sm-3"><div class="small text-muted">Shipping Partner</div><div><?= e($order['shipping_partner_name'] ?: '—') ?></div></div>
    <div class="col-sm-3"><div class="small text-muted">Tracking No.</div><div><?= e($order['tracking_no'] ?: '—') ?></div></div>
    <div class="col-sm-3"><div class="small text-muted">Expected Dispatch</div><div><?= e($order['expected_dispatch_date'] ?: '—') ?></div></div>
    <div class="col-sm-3"><div class="small text-muted">Expected Delivery</div><div><?= e($order['expected_delivery_date'] ?: '—') ?></div></div>
  </div>
</div>

<div class="card p-3">
  <div class="table-responsive">
    <table class="table">
      <thead><tr><th>Product</th><th>Warehouse</th><th class="text-end">Qty</th><th>UOM</th><th class="text-end">Rate</th><th class="text-end">Discount %</th><th class="text-end">Amount</th></tr></thead>
      <tbody>
      <?php foreach ($items as $it): ?>
        <tr>
          <td><?= e($it['product_name']) ?> <span class="text-muted small">(<?= e($it['sku']) ?>)</span><?= $it['description'] ? '<div class="text-muted small">' . e($it['description']) . '</div>' : '' ?></td>
          <td><?= e($it['item_warehouse_name'] ?: '—') ?></td>
          <td class="text-end"><?= (int)$it['quantity'] ?></td>
          <td><?= e($it['uom']) ?></td>
          <td class="text-end"><?= money($it['unit_price']) ?></td>
          <td class="text-end"><?= e(number_format((float)$it['discount_percent'], 2)) ?></td>
          <td class="text-end"><?= money($it['subtotal']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr><th colspan="6" class="text-end">Net Amount</th><th class="text-end"><?= money($order['net_amount']) ?></th></tr>
      </tfoot>
    </table>
  </div>
  <?php if ($order['notes']): ?><div class="mt-2"><strong>Notes:</strong> <?= e($order['notes']) ?></div><?php endif; ?>
</div>

<div class="card p-3 mt-3">
  <h6 class="mb-2">Taxes and Charges</h6>
  <?php if ($taxRows): ?>
  <div class="table-responsive">
    <table class="table table-sm">
      <thead><tr><th>Type</th><th>Account Head</th><th>Description</th><th class="text-end">Rate / Amount</th><th>Based On</th><th class="text-end">Amount</th></tr></thead>
      <tbody>
      <?php foreach ($taxRows as $tr): ?>
        <tr>
          <td><?= $tr['type'] === 'on_item' ? 'On Item' : 'On Order' ?></td>
          <td><?= e($tr['account_name'] ?: '—') ?></td>
          <td><?= e($tr['description']) ?></td>
          <td class="text-end"><?= $tr['based_on'] === 'net_amount' ? e(number_format((float)$tr['rate_or_amount'], 2)) . '%' : money($tr['rate_or_amount']) ?></td>
          <td><?= $tr['based_on'] === 'net_amount' ? 'Net Amount' : 'Actual Amount' ?></td>
          <td class="text-end"><?= money($tr['amount']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
  <div class="text-muted small mb-2">No taxes or charges applied.</div>
  <?php endif; ?>
  <div class="row justify-content-end mt-2">
    <div class="col-sm-6 col-lg-4">
      <?php if ($order['additional_discount'] > 0): ?><div class="d-flex justify-content-between mb-1"><span class="text-muted">Additional Discount</span><span>-<?= money($order['additional_discount']) ?></span></div><?php endif; ?>
      <?php if ($order['additional_charge'] > 0): ?><div class="d-flex justify-content-between mb-1"><span class="text-muted">Additional Charge</span><span><?= money($order['additional_charge']) ?></span></div><?php endif; ?>
      <?php if ($order['adjustment_type'] !== 'none' && $order['adjustment_amount'] > 0): ?>
        <div class="d-flex justify-content-between mb-1"><span class="text-muted">Adjustment (<?= $order['adjustment_type'] === 'add' ? '+' : '-' ?>)</span><span><?= money($order['adjustment_amount']) ?></span></div>
      <?php endif; ?>
      <div class="d-flex justify-content-between fs-5 border-top pt-2"><span>Grand Total</span><strong><?= money($order['total_amount']) ?></strong></div>
    </div>
  </div>
</div>

<div class="card p-3 mt-3">
  <h6 class="mb-2">Payment Terms</h6>
  <div class="row g-3 mb-2">
    <div class="col-sm-4"><div class="small text-muted">Payment Terms</div><div><?= e($order['payment_terms'] ?: '—') ?></div></div>
    <div class="col-sm-4"><div class="small text-muted">Payment Method</div><div><?= e($order['payment_method'] ?: '—') ?></div></div>
    <div class="col-sm-4"><div class="small text-muted">Payment Reference</div><div><?= e($order['payment_reference'] ?: '—') ?></div></div>
  </div>
  <?php if ($paymentSchedule): ?>
  <div class="table-responsive">
    <table class="table table-sm">
      <thead><tr><th>Due On</th><th>Payment Type</th><th class="text-end">%</th><th class="text-end">Amount</th><th>Remarks</th></tr></thead>
      <tbody>
      <?php foreach ($paymentSchedule as $ps): ?>
        <tr>
          <td><?= ['order_date' => 'Order Date', 'on_delivery' => 'Delivery', 'fixed_days' => $ps['days_from'] . ' days'][$ps['due_on']] ?? e($ps['due_on']) ?></td>
          <td class="text-capitalize"><?= e(str_replace('_', ' ', $ps['payment_type'])) ?></td>
          <td class="text-end"><?= e(number_format((float)$ps['percentage'], 2)) ?></td>
          <td class="text-end"><?= money($ps['amount']) ?></td>
          <td><?= e($ps['remarks']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
  <div class="text-muted small">No payment schedule set.</div>
  <?php endif; ?>
  <?php if ($order['require_advance_payment']): ?>
    <div class="mt-2 small"><strong>Advance:</strong> <?= e(number_format((float)$order['advance_percentage'], 2)) ?>% due<?= $order['advance_valid_till'] ? ' (valid till ' . e($order['advance_valid_till']) . ')' : '' ?></div>
  <?php endif; ?>
</div>

<div class="card p-3 mt-3">
  <h6 class="mb-3">More Info</h6>
  <div class="row g-3 mb-3">
    <div class="col-sm-3"><div class="small text-muted">Reference Quotation</div><div><?= $order['quotation_no'] ? '<a href="' . base_url('sales/quotation_view.php?id=' . (int)$order['quotation_id']) . '">' . e($order['quotation_no']) . '</a>' : '—' ?></div></div>
    <div class="col-sm-3"><div class="small text-muted">Opportunity</div><div><?= e($order['opportunity'] ?: '—') ?></div></div>
    <div class="col-sm-3"><div class="small text-muted">Customer PO Date</div><div><?= e($order['customer_po_date'] ?: '—') ?></div></div>
    <div class="col-sm-3"><div class="small text-muted">Campaign / Source</div><div><?= e($order['campaign_source'] ?: '—') ?></div></div>
    <div class="col-sm-3"><div class="small text-muted">Sales Group</div><div><?= e($order['sales_group'] ?: '—') ?></div></div>
    <div class="col-sm-3"><div class="small text-muted">Sales Office</div><div><?= e($order['sales_office'] ?: '—') ?></div></div>
    <div class="col-sm-3"><div class="small text-muted">Cost Center</div><div><?= e($order['cost_center'] ?: '—') ?></div></div>
    <div class="col-sm-3"><div class="small text-muted">Business Unit</div><div><?= e($order['business_unit'] ?: '—') ?></div></div>
    <div class="col-sm-3"><div class="small text-muted">Valid Till</div><div><?= e($order['valid_till'] ?: '—') ?></div></div>
    <div class="col-sm-3"><div class="small text-muted">Order Type</div><div><?= e($order['order_type'] ?: '—') ?></div></div>
    <div class="col-sm-3"><div class="small text-muted">Tags</div><div><?= e($order['tags'] ?: '—') ?></div></div>
    <div class="col-sm-3"><div class="small text-muted">End Customer</div><div><?= e($order['end_customer'] ?: '—') ?></div></div>
    <div class="col-sm-3"><div class="small text-muted">Channel Partner</div><div><?= e($order['channel_partner'] ?: '—') ?></div></div>
    <div class="col-sm-3"><div class="small text-muted">Deal Registration No.</div><div><?= e($order['deal_registration_no'] ?: '—') ?></div></div>
    <div class="col-sm-3"><div class="small text-muted">Market Segment</div><div><?= e($order['market_segment'] ?: '—') ?></div></div>
    <div class="col-sm-3"><div class="small text-muted">Region</div><div><?= e($order['region'] ?: '—') ?></div></div>
    <div class="col-sm-3"><div class="small text-muted">Expected Close Date</div><div><?= e($order['expected_close_date'] ?: '—') ?></div></div>
  </div>
  <?php if ($order['remarks_internal']): ?><div class="mb-3"><div class="small text-muted">Remarks (Internal)</div><div><?= e($order['remarks_internal']) ?></div></div><?php endif; ?>
  <?php if ($salesTeam): ?>
  <h6 class="mb-2">Sales Team</h6>
  <div class="table-responsive">
    <table class="table table-sm">
      <thead><tr><th>Sales Person</th><th>Role</th><th class="text-end">Commission %</th></tr></thead>
      <tbody>
      <?php foreach ($salesTeam as $st): ?>
        <tr>
          <td><?= e($st['sales_person_name']) ?></td>
          <td><?= e($st['role'] ?: '—') ?></td>
          <td class="text-end"><?= e(number_format((float)$st['commission_percent'], 2)) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php if ($returns): ?>
<div class="card p-3 mt-3">
  <h6 class="mb-2">Sales Returns</h6>
  <div class="table-responsive">
    <table class="table table-sm">
      <thead><tr><th>Return #</th><th>Date</th><th>Status</th><th class="text-end">Amount</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($returns as $r): ?>
        <tr>
          <td><?= e($r['return_no']) ?></td>
          <td><?= e($r['return_date']) ?></td>
          <td><span class="badge text-bg-<?= $returnBadge[$r['status']] ?? 'secondary' ?> badge-status"><?= e($r['status']) ?></span></td>
          <td class="text-end"><?= money($r['total_amount']) ?></td>
          <td class="text-end"><a href="<?= base_url('sales/return_view.php?id=' . (int)$r['id']) ?>" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-eye"></i> View</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
