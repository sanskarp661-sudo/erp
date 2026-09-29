<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
$canEdit = can_edit_module('procurement');
$canManage = can_manage_module('procurement');

$id = (int)input('id');
$stmt = db()->prepare('SELECT po.*, v.name vendor_name, v.email vendor_email, v.phone vendor_phone, pl.name price_list_name, w.name ship_to_name, sp.name shipping_partner_name, b.name buyer_name, ap.name approver_name
    FROM purchase_orders po JOIN vendors v ON v.id = po.vendor_id
    LEFT JOIN price_lists pl ON pl.id = po.price_list_id
    LEFT JOIN warehouses w ON w.id = po.ship_to_warehouse_id
    LEFT JOIN shipping_partners sp ON sp.id = po.shipping_partner_id
    LEFT JOIN users b ON b.id = po.buyer_id
    LEFT JOIN users ap ON ap.id = po.approver_id
    WHERE po.id = ?');
$stmt->execute([$id]);
$order = $stmt->fetch();

if (!$order) {
    flash('danger', 'Purchase order not found.');
    redirect('/purchases/orders.php');
}

if (is_post() && input('action') === 'transition') {
    $newStatus = input('status');
    if ($newStatus === 'cancelled') {
        require_module_manage('procurement');
    } else {
        require_module_edit('procurement');
    }
    csrf_verify();
    $valid = [
        'pending' => ['ordered', 'cancelled'],
        'ordered' => ['received', 'cancelled'],
    ];
    if (!isset($valid[$order['status']]) || !in_array($newStatus, $valid[$order['status']], true)) {
        flash('danger', 'That status change is not allowed.');
        redirect('/purchases/order_view.php?id=' . $id);
    }

    // Purchase orders no longer touch stock directly — only a Goods
    // Receipt (created separately, once ordered) actually adds it.
    try {
        db()->prepare('UPDATE purchase_orders SET status = ? WHERE id = ?')->execute([$newStatus, $id]);
        flash('success', 'Purchase order status updated to "' . $newStatus . '".');
    } catch (Exception $e) {
        flash('danger', 'Could not update purchase order status.');
    }
    redirect('/purchases/order_view.php?id=' . $id);
}

$items = db()->prepare('SELECT poi.*, p.name product_name, p.sku, w.name warehouse_name FROM purchase_order_items poi JOIN products p ON p.id = poi.product_id LEFT JOIN warehouses w ON w.id = poi.warehouse_id WHERE po_id = ? ORDER BY poi.id');
$items->execute([$id]);
$items = $items->fetchAll();

$taxes = db()->prepare('SELECT t.*, la.name account_name FROM purchase_order_taxes t LEFT JOIN ledger_accounts la ON la.id = t.account_head_id WHERE po_id = ? ORDER BY sort_order, t.id');
$taxes->execute([$id]);
$taxes = $taxes->fetchAll();

$schedule = db()->prepare('SELECT * FROM purchase_order_payment_schedule WHERE po_id = ? ORDER BY sort_order, id');
$schedule->execute([$id]);
$schedule = $schedule->fetchAll();
$dueOnLabels = ['order_date' => 'PO Date', 'on_receipt' => 'Goods Receipt', 'on_invoice' => 'Invoice Date', 'fixed_days' => 'Fixed Days'];

/** Renders label/value pairs, skipping empty values. */
function po_facts(array $facts): string
{
    $html = '';
    foreach ($facts as $label => $value) {
        if ($value === null || $value === '' || $value === '0000-00-00') {
            continue;
        }
        $html .= '<div class="col-sm-6 col-lg-3"><div class="small text-muted">' . e($label) . '</div><div>' . e($value) . '</div></div>';
    }
    return $html ?: '<div class="col-12 text-muted small">Nothing recorded.</div>';
}

$grnStmt = db()->prepare('SELECT id, grn_no, status FROM goods_receipts WHERE purchase_order_id = ? LIMIT 1');
$grnStmt->execute([$id]);
$existingGrn = $grnStmt->fetch();

$returnsStmt = db()->prepare('SELECT id, return_no, status, return_date, total_amount FROM purchase_returns WHERE purchase_order_id = ? ORDER BY id DESC');
$returnsStmt->execute([$id]);
$returns = $returnsStmt->fetchAll();
$returnBadge = ['draft' => 'secondary', 'completed' => 'success', 'cancelled' => 'danger'];

$badge = ['pending' => 'secondary', 'ordered' => 'info', 'received' => 'success', 'cancelled' => 'danger'];

$page_title = 'Purchase Order ' . $order['po_no'];
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-1"><?= e($order['po_no']) ?> <span class="badge text-bg-<?= $badge[$order['status']] ?> badge-status"><?= e($order['status']) ?></span></h4>
    <div class="text-muted"><?= e($order['vendor_name']) ?> &middot; <?= e($order['order_date']) ?></div>
  </div>
  <div class="page-actions">
    <?php if ($order['status'] === 'pending'): ?>
      <?php if ($canEdit): ?>
      <a href="order_form.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-pen"></i> Edit</a>
      <form method="post" class="d-inline" data-confirm="Mark this order as ordered?">
        <?= csrf_field() ?><input type="hidden" name="action" value="transition"><input type="hidden" name="status" value="ordered">
        <button class="btn btn-brand btn-sm" type="submit"><i class="fa-solid fa-paper-plane"></i> Mark Ordered</button>
      </form>
      <?php endif; ?>
      <?php if ($canManage): ?>
      <form method="post" class="d-inline" data-confirm="Cancel this purchase order?">
        <?= csrf_field() ?><input type="hidden" name="action" value="transition"><input type="hidden" name="status" value="cancelled">
        <button class="btn btn-outline-danger btn-sm" type="submit"><i class="fa-solid fa-ban"></i> Cancel</button>
      </form>
      <?php endif; ?>
    <?php elseif ($order['status'] === 'ordered'): ?>
      <?php if ($canEdit): ?>
      <form method="post" class="d-inline" data-confirm="Mark this order as received? (Receiving stock happens separately via a Goods Receipt.)">
        <?= csrf_field() ?><input type="hidden" name="action" value="transition"><input type="hidden" name="status" value="received">
        <button class="btn btn-brand btn-sm" type="submit"><i class="fa-solid fa-box-open"></i> Mark Received</button>
      </form>
      <?php endif; ?>
      <?php if ($canManage): ?>
      <form method="post" class="d-inline" data-confirm="Cancel this purchase order?">
        <?= csrf_field() ?><input type="hidden" name="action" value="transition"><input type="hidden" name="status" value="cancelled">
        <button class="btn btn-outline-danger btn-sm" type="submit"><i class="fa-solid fa-ban"></i> Cancel</button>
      </form>
      <?php endif; ?>
    <?php endif; ?>

    <?php if (in_array($order['status'], ['ordered', 'received'], true)): ?>
      <?php if ($existingGrn): ?>
        <a href="<?= base_url('purchases/grn_view.php?id=' . $existingGrn['id']) ?>" class="btn btn-outline-brand btn-sm"><i class="fa-solid fa-box-open"></i> View Goods Receipt <?= e($existingGrn['grn_no']) ?></a>
      <?php elseif ($canEdit): ?>
        <a href="<?= base_url('purchases/grn_form.php?from_order=' . $id) ?>" class="btn btn-outline-brand btn-sm"><i class="fa-solid fa-box-open"></i> Create Goods Receipt</a>
      <?php endif; ?>
    <?php endif; ?>
    <?php if ($existingGrn && $existingGrn['status'] === 'received' && $canEdit): ?>
      <a href="<?= base_url('purchases/return_form.php?from_order=' . $id) ?>" class="btn btn-outline-brand btn-sm"><i class="fa-solid fa-rotate-left"></i> New Purchase Return</a>
    <?php endif; ?>
    <a href="<?= base_url('print.php?doctype=purchase_order&id=' . $id) ?>" target="_blank" class="btn btn-outline-brand btn-sm"><i class="fa-solid fa-print"></i> Print</a>
    <a href="orders.php" class="btn btn-outline-secondary btn-sm">Back to list</a>
  </div>
</div>

<div class="card p-3 mb-3">
  <h6 class="mb-3">Details</h6>
  <div class="row g-3">
    <?= po_facts([
        'Vendor Contact' => $order['vendor_contact'], 'Vendor Address' => $order['vendor_address'], 'Vendor GSTIN' => $order['vendor_gstin'],
        'Required By' => $order['required_by'], 'Purchase Type' => $order['purchase_type'], 'Buyer' => $order['buyer_name'],
        'Price List' => $order['price_list_name'], 'Currency' => $order['currency'], 'Vendor Quotation No.' => $order['vendor_quote_no'],
        'Material Request No.' => $order['material_request_no'], 'Project' => $order['project'],
    ]) ?>
  </div>
</div>

<div class="card p-3 mb-3">
  <h6 class="mb-2">Items</h6>
  <div class="table-responsive">
    <table class="table">
      <thead><tr><th>Product</th><th>Receive Into</th><th class="text-end">Qty</th><th>UOM</th><th class="text-end">Rate</th><th class="text-end">Disc. %</th><th>Required By</th><th class="text-end">Amount</th></tr></thead>
      <tbody>
      <?php foreach ($items as $it): ?>
        <tr>
          <td><?= e($it['product_name']) ?> <span class="text-muted small">(<?= e($it['sku']) ?>)</span><?php if (!empty($it['description'])): ?><div class="small text-muted"><?= e($it['description']) ?></div><?php endif; ?></td>
          <td><?= e($it['warehouse_name'] ?? '—') ?></td>
          <td class="text-end"><?= (int)$it['quantity'] ?></td>
          <td><?= e($it['uom'] ?? '') ?></td>
          <td class="text-end"><?= money((float)$it['rate'] > 0 ? $it['rate'] : $it['unit_cost']) ?></td>
          <td class="text-end"><?= (float)$it['discount_percent'] ? rtrim(rtrim(number_format((float)$it['discount_percent'], 2), '0'), '.') : '—' ?></td>
          <td><?= e($it['required_by'] ?? '—') ?></td>
          <td class="text-end"><?= money($it['subtotal']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr><th colspan="7" class="text-end">Net Total</th><th class="text-end"><?= money((float)$order['net_amount'] > 0 ? $order['net_amount'] : array_sum(array_column($items, 'subtotal'))) ?></th></tr>
        <?php foreach ($taxes as $t): ?>
          <tr><td colspan="7" class="text-end text-muted"><?= e($t['description'] ?: ($t['account_name'] ?? 'Charge')) ?><?= $t['based_on'] === 'net_amount' ? ' @ ' . rtrim(rtrim(number_format((float)$t['rate_or_amount'], 2), '0'), '.') . '%' : '' ?></td><td class="text-end"><?= money($t['amount']) ?></td></tr>
        <?php endforeach; ?>
        <?php if ((float)$order['additional_charge']): ?><tr><td colspan="7" class="text-end text-muted">Additional Charge</td><td class="text-end"><?= money($order['additional_charge']) ?></td></tr><?php endif; ?>
        <?php if ((float)$order['additional_discount']): ?><tr><td colspan="7" class="text-end text-muted">Additional Discount</td><td class="text-end">-<?= money($order['additional_discount']) ?></td></tr><?php endif; ?>
        <?php if ($order['adjustment_type'] !== 'none' && (float)$order['adjustment_amount']): ?><tr><td colspan="7" class="text-end text-muted">Adjustment<?= $order['adjustment_remarks'] ? ' (' . e($order['adjustment_remarks']) . ')' : '' ?></td><td class="text-end"><?= $order['adjustment_type'] === 'subtract' ? '-' : '' ?><?= money($order['adjustment_amount']) ?></td></tr><?php endif; ?>
        <tr><th colspan="7" class="text-end">Grand Total</th><th class="text-end"><?= money($order['total_amount']) ?></th></tr>
      </tfoot>
    </table>
  </div>
  <?php if ($order['notes']): ?><div class="mt-2"><strong>Notes:</strong> <?= e($order['notes']) ?></div><?php endif; ?>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-6">
    <div class="card p-3 h-100">
      <h6 class="mb-3">Shipping &amp; Delivery</h6>
      <div class="row g-3">
        <?= po_facts([
            'Ship-to Warehouse' => $order['ship_to_name'], 'Priority' => ucfirst($order['delivery_priority'] ?? ''),
            'Expected Dispatch' => $order['expected_dispatch_date'], 'Expected Delivery' => $order['expected_delivery_date'],
            'Incoterms' => $order['delivery_terms'], 'Mode of Transport' => $order['mode_of_transport'],
            'Transporter' => $order['shipping_partner_name'], 'Freight Terms' => $order['freight_terms'],
            'Tracking / LR No.' => $order['tracking_no'], 'Partial Receipt' => $order['allow_partial_receipt'] ? 'Allowed' : 'Not allowed',
            'Inspection' => $order['inspection_required'] ? 'Required' : null, 'Instructions' => $order['delivery_remarks'],
        ]) ?>
      </div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card p-3 h-100">
      <h6 class="mb-3">Payment Terms</h6>
      <div class="row g-3 mb-2">
        <?= po_facts([
            'Terms' => $order['payment_terms'], 'Method' => $order['payment_method'],
            'Advance' => (float)$order['advance_percentage'] ? rtrim(rtrim(number_format((float)$order['advance_percentage'], 2), '0'), '.') . '% (' . money($order['total_amount'] * $order['advance_percentage'] / 100) . ')' : null,
            'Vendor Bank' => $order['vendor_bank_details'], 'Instructions' => $order['payment_instructions'],
        ]) ?>
      </div>
      <?php if ($schedule): ?>
        <table class="table table-sm mb-0">
          <thead><tr><th>Due On</th><th class="text-end">Days</th><th>Type</th><th class="text-end">%</th><th class="text-end">Amount</th></tr></thead>
          <tbody>
          <?php foreach ($schedule as $ps): ?>
            <tr><td><?= e($dueOnLabels[$ps['due_on']] ?? $ps['due_on']) ?></td><td class="text-end"><?= (int)$ps['days_from'] ?></td><td><?= e(ucwords(str_replace('_', ' ', $ps['payment_type']))) ?></td><td class="text-end"><?= rtrim(rtrim(number_format((float)$ps['percentage'], 2), '0'), '.') ?></td><td class="text-end"><?= money($ps['amount']) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="card p-3 mb-3">
  <h6 class="mb-3">More Info</h6>
  <div class="row g-3">
    <?= po_facts([
        'Order Type' => $order['order_type'], 'Approver' => $order['approver_name'], 'Cost Center' => $order['cost_center'],
        'Business Unit' => $order['business_unit'], 'Place of Supply' => $order['place_of_supply'],
        'Reverse Charge' => $order['reverse_charge'] ? 'Yes' : null, 'Tags' => $order['tags'],
        'Internal Remarks' => $order['remarks_internal'],
    ]) ?>
  </div>
  <?php if ($order['terms_conditions']): ?><div class="mt-3"><div class="small text-muted">Terms &amp; Conditions</div><div style="white-space:pre-line"><?= e($order['terms_conditions']) ?></div></div><?php endif; ?>
</div>

<?php if ($returns): ?>
<div class="card p-3 mt-3">
  <h6 class="mb-2">Purchase Returns</h6>
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
          <td class="text-end"><a href="<?= base_url('purchases/return_view.php?id=' . (int)$r['id']) ?>" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-eye"></i> View</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
