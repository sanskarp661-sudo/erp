<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/stock_entry.php';
require_login();
stock_entry_require_schema();
$canEdit = can_edit_module('supply-chain');
$canManage = can_manage_module('supply-chain');

$id = (int)input('id');
$loadEntry = function () use ($id) {
    $stmt = db()->prepare('SELECT se.*, sw.name source_name, tw.name target_name, v.name vendor_name, sp.name shipping_partner_name,
            rq.name requested_by_name, ap.name approver_name, cb.name created_by_name, sb.name submitted_by_name
        FROM stock_entries se
        LEFT JOIN warehouses sw ON sw.id = se.source_warehouse_id
        LEFT JOIN warehouses tw ON tw.id = se.target_warehouse_id
        LEFT JOIN vendors v ON v.id = se.vendor_id
        LEFT JOIN shipping_partners sp ON sp.id = se.shipping_partner_id
        LEFT JOIN users rq ON rq.id = se.requested_by
        LEFT JOIN users ap ON ap.id = se.approver_id
        LEFT JOIN users cb ON cb.id = se.created_by
        LEFT JOIN users sb ON sb.id = se.submitted_by
        WHERE se.id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch();
};
$entry = $loadEntry();
if (!$entry) {
    flash('danger', 'Stock entry not found.');
    redirect('/supply-chain/stock_entries.php');
}

if (is_post() && input('action') === 'transition') {
    $newStatus = input('status');
    if ($newStatus === 'cancelled') {
        require_module_manage('supply-chain');
    } else {
        require_module_edit('supply-chain');
    }
    csrf_verify();
    $valid = ['draft' => ['submitted', 'cancelled'], 'submitted' => ['cancelled']];
    if (!isset($valid[$entry['status']]) || !in_array($newStatus, $valid[$entry['status']], true)) {
        flash('danger', 'That status change is not allowed.');
        redirect('/supply-chain/stock_entry_view.php?id=' . $id);
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($newStatus === 'submitted') {
            stock_entry_submit($id, current_user()['id']);
        } else {
            stock_entry_cancel($id, current_user()['id']);
        }
        $pdo->commit();
        log_activity('stock_entry', $id, $newStatus);
        flash('success', $newStatus === 'submitted' ? 'Stock entry submitted and stock updated.' : ($entry['status'] === 'submitted' ? 'Stock entry cancelled and its stock movements reversed.' : 'Stock entry cancelled.'));
    } catch (Exception $e) {
        $pdo->rollBack();
        flash('danger', $e instanceof RuntimeException ? $e->getMessage() : 'Could not update stock entry.' . (defined('APP_DEBUG') && APP_DEBUG ? ' DEBUG: ' . $e->getMessage() : ''));
    }
    redirect('/supply-chain/stock_entry_view.php?id=' . $id);
}

$items = db()->prepare('SELECT sei.*, p.name product_name, p.sku, sw.name source_name, tw.name target_name FROM stock_entry_items sei JOIN products p ON p.id = sei.product_id LEFT JOIN warehouses sw ON sw.id = sei.source_warehouse_id LEFT JOIN warehouses tw ON tw.id = sei.target_warehouse_id WHERE stock_entry_id = ? ORDER BY sei.sort_order, sei.id');
$items->execute([$id]);
$items = $items->fetchAll();

$costs = db()->prepare('SELECT c.*, la.name account_name FROM stock_entry_costs c LEFT JOIN ledger_accounts la ON la.id = c.account_head_id WHERE stock_entry_id = ? ORDER BY sort_order, c.id');
$costs->execute([$id]);
$costs = $costs->fetchAll();

$movements = db()->prepare('SELECT sm.*, p.name product_name, w.name warehouse_name, pb.batch_no FROM stock_movements sm JOIN products p ON p.id = sm.product_id LEFT JOIN warehouses w ON w.id = sm.warehouse_id LEFT JOIN product_batches pb ON pb.id = sm.batch_id WHERE sm.reference = ? ORDER BY sm.id');
$movements->execute([$entry['entry_no']]);
$movements = $movements->fetchAll();

/** Renders label/value pairs, skipping empty values. */
function se_facts(array $facts): string
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

$types = stock_entry_types();
$type = $entry['entry_type'];
$isAdjustment = $type === 'stock_adjustment';
$showSource = in_array($type, ['material_issue', 'material_transfer'], true);
$showTarget = $type !== 'material_issue';
$hasBatchInfo = (bool)array_filter($items, fn($it) => $it['batch_no'] || $it['serial_numbers']);
$badge = ['draft' => 'secondary', 'submitted' => 'success', 'cancelled' => 'danger'];
$moveBadge = ['in' => 'success', 'out' => 'danger', 'adjustment' => 'warning'];

$page_title = 'Stock Entry ' . $entry['entry_no'];
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-1"><?= e($entry['entry_no']) ?> <span class="badge text-bg-<?= $badge[$entry['status']] ?? 'secondary' ?> badge-status"><?= e($entry['status']) ?></span></h4>
    <div class="text-muted"><?= e($types[$type] ?? $type) ?> &middot; <?= e($entry['posting_date']) ?><?= $entry['posting_time'] ? ' ' . e(substr($entry['posting_time'], 0, 5)) : '' ?></div>
  </div>
  <div class="page-actions">
    <?php if ($entry['status'] === 'draft' && $canEdit): ?>
      <a href="stock_entry_form.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-pen"></i> Edit</a>
      <form method="post" class="d-inline" data-confirm="Submit this stock entry? Stock levels will be updated.">
        <?= csrf_field() ?><input type="hidden" name="action" value="transition"><input type="hidden" name="status" value="submitted">
        <button class="btn btn-brand btn-sm" type="submit"><i class="fa-solid fa-check"></i> Submit</button>
      </form>
    <?php endif; ?>
    <?php if ($entry['status'] !== 'cancelled' && $canManage): ?>
      <form method="post" class="d-inline" data-confirm="<?= $entry['status'] === 'submitted' ? 'Cancel this stock entry? All of its stock movements will be reversed.' : 'Cancel this draft stock entry?' ?>">
        <?= csrf_field() ?><input type="hidden" name="action" value="transition"><input type="hidden" name="status" value="cancelled">
        <button class="btn btn-outline-danger btn-sm" type="submit"><i class="fa-solid fa-ban"></i> Cancel</button>
      </form>
    <?php endif; ?>
    <?php if ($canEdit): ?>
      <a href="stock_entry_form.php?type=<?= e($type) ?>" class="btn btn-outline-brand btn-sm"><i class="fa-solid fa-plus"></i> New <?= e($types[$type] ?? 'Stock Entry') ?></a>
    <?php endif; ?>
    <a href="stock_entries.php" class="btn btn-outline-secondary btn-sm">Back to list</a>
  </div>
</div>

<div class="card p-3 mb-3">
  <h6 class="mb-3">Details</h6>
  <div class="row g-3">
    <?= se_facts([
        'Stock Entry Type' => $types[$type] ?? $type, 'Purpose / Reason' => $entry['purpose'],
        'Default Source Warehouse' => $entry['source_name'], ($isAdjustment ? 'Warehouse' : 'Default Target Warehouse') => $entry['target_name'],
        'Vendor' => $entry['vendor_name'], 'Reference No.' => $entry['reference_no'], 'Requested By' => $entry['requested_by_name'],
        'Department' => $entry['department'], 'Project' => $entry['project'], 'Created By' => $entry['created_by_name'],
        'Submitted' => $entry['submitted_at'] ? $entry['submitted_at'] . ($entry['submitted_by_name'] ? ' by ' . $entry['submitted_by_name'] : '') : null,
    ]) ?>
  </div>
  <?php if ($entry['notes']): ?><div class="mt-3"><strong>Notes:</strong> <?= e($entry['notes']) ?></div><?php endif; ?>
</div>

<div class="card p-3 mb-3">
  <h6 class="mb-2">Items</h6>
  <div class="table-responsive">
    <table class="table">
      <thead><tr>
        <th>Item</th>
        <?php if ($showSource): ?><th>Source</th><?php endif; ?>
        <?php if ($showTarget): ?><th><?= $isAdjustment ? 'Warehouse' : 'Target' ?></th><?php endif; ?>
        <th class="text-end">Qty</th><th>UOM</th><th class="text-end">Basic Rate</th><th class="text-end">Add. Cost</th><th class="text-end">Valuation Rate</th><th class="text-end">Amount</th>
      </tr></thead>
      <tbody>
      <?php foreach ($items as $it): ?>
        <tr>
          <td><?= e($it['product_name']) ?> <span class="text-muted small">(<?= e($it['sku']) ?>)</span><?php if ($it['description']): ?><div class="small text-muted"><?= e($it['description']) ?></div><?php endif; ?></td>
          <?php if ($showSource): ?><td><?= e($it['source_name'] ?? '—') ?></td><?php endif; ?>
          <?php if ($showTarget): ?><td><?= e($it['target_name'] ?? '—') ?></td><?php endif; ?>
          <td class="text-end <?= (int)$it['quantity'] < 0 ? 'text-danger' : '' ?>"><?= (int)$it['quantity'] > 0 && $isAdjustment ? '+' : '' ?><?= (int)$it['quantity'] ?></td>
          <td><?= e($it['uom']) ?></td>
          <td class="text-end"><?= money($it['basic_rate']) ?></td>
          <td class="text-end"><?= (float)$it['additional_cost'] ? money($it['additional_cost']) : '—' ?></td>
          <td class="text-end"><?= money($it['valuation_rate']) ?></td>
          <td class="text-end"><?= money($it['amount']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <?php $span = 6 + ($showSource ? 1 : 0) + ($showTarget ? 1 : 0); ?>
        <tr><td colspan="<?= $span ?>" class="text-end text-muted">Total Quantity</td><td class="text-end"><?= (int)$entry['total_qty'] ?></td></tr>
        <?php if ((float)$entry['total_outgoing_value']): ?><tr><td colspan="<?= $span ?>" class="text-end text-muted">Total Outgoing Value</td><td class="text-end"><?= money($entry['total_outgoing_value']) ?></td></tr><?php endif; ?>
        <?php if ((float)$entry['total_incoming_value']): ?><tr><td colspan="<?= $span ?>" class="text-end text-muted">Total Incoming Value</td><td class="text-end"><?= money($entry['total_incoming_value']) ?></td></tr><?php endif; ?>
        <?php if ((float)$entry['total_additional_costs']): ?><tr><td colspan="<?= $span ?>" class="text-end text-muted">Additional Costs</td><td class="text-end"><?= money($entry['total_additional_costs']) ?></td></tr><?php endif; ?>
        <tr><th colspan="<?= $span ?>" class="text-end">Value Difference</th><th class="text-end"><?= money($entry['value_difference']) ?></th></tr>
      </tfoot>
    </table>
  </div>
</div>

<?php if ($hasBatchInfo): ?>
<div class="card p-3 mb-3">
  <h6 class="mb-2">Batch &amp; Serial Numbers</h6>
  <div class="table-responsive">
    <table class="table table-sm mb-0">
      <thead><tr><th>Item</th><th>Batch No.</th><th>Mfg. Date</th><th>Expiry Date</th><th>Serial Numbers</th></tr></thead>
      <tbody>
      <?php foreach ($items as $it): if (!$it['batch_no'] && !$it['serial_numbers']) continue; ?>
        <tr>
          <td><?= e($it['product_name']) ?></td>
          <td><?= e($it['batch_no'] ?: '—') ?></td>
          <td><?= e($it['manufacturing_date'] ?: '—') ?></td>
          <td><?= e($it['expiry_date'] ?: '—') ?></td>
          <td class="small"><?= $it['serial_numbers'] ? e(str_replace("\n", ', ', $it['serial_numbers'])) : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-lg-6">
    <div class="card p-3 h-100">
      <h6 class="mb-3">Additional Costs <?php if ($costs): ?><span class="small text-muted fw-normal">— distributed by <?= $entry['distribute_costs_by'] === 'qty' ? 'quantity' : 'amount' ?></span><?php endif; ?></h6>
      <?php if ($costs): ?>
        <table class="table table-sm mb-0">
          <thead><tr><th>Expense Account</th><th>Description</th><th class="text-end">Amount</th></tr></thead>
          <tbody>
          <?php foreach ($costs as $c): ?>
            <tr><td><?= e($c['account_name'] ?? '—') ?></td><td><?= e($c['description'] ?? '') ?></td><td class="text-end"><?= money($c['amount']) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: ?>
        <div class="text-muted small">No additional costs.</div>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card p-3 h-100">
      <h6 class="mb-3">Transport</h6>
      <div class="row g-3">
        <?= se_facts([
            'Dispatch Date' => $entry['dispatch_date'], 'Expected Arrival' => $entry['expected_arrival_date'],
            'Mode of Transport' => $entry['mode_of_transport'], 'Transporter' => $entry['shipping_partner_name'],
            'Vehicle No.' => $entry['vehicle_no'], 'Driver' => trim(($entry['driver_name'] ?? '') . ($entry['driver_phone'] ? ' (' . $entry['driver_phone'] . ')' : '')),
            'Tracking / LR No.' => $entry['tracking_no'], 'E-Way Bill No.' => $entry['eway_bill_no'], 'Remarks' => $entry['transport_remarks'],
        ]) ?>
      </div>
    </div>
  </div>
</div>

<div class="card p-3 mb-3">
  <h6 class="mb-3">More Info</h6>
  <div class="row g-3">
    <?= se_facts([
        'Cost Center' => $entry['cost_center'], 'Business Unit' => $entry['business_unit'], 'Approver' => $entry['approver_name'],
        'Quality Inspection' => $entry['inspection_required'] ? 'Required' : null, 'Tags' => $entry['tags'],
    ]) ?>
  </div>
  <?php if ($entry['remarks_internal']): ?><div class="mt-3"><div class="small text-muted">Remarks (Internal)</div><div style="white-space:pre-line"><?= e($entry['remarks_internal']) ?></div></div><?php endif; ?>
</div>

<div class="card p-3 mb-3">
  <h6 class="mb-2">Stock Ledger</h6>
  <?php if ($movements): ?>
  <div class="table-responsive">
    <table class="table table-sm mb-0">
      <thead><tr><th>Date</th><th>Item</th><th>Warehouse</th><th>Batch</th><th>Type</th><th class="text-end">Qty</th><th>Notes</th></tr></thead>
      <tbody>
      <?php foreach ($movements as $m): ?>
        <tr>
          <td><?= e(date('Y-m-d H:i', strtotime($m['created_at']))) ?></td>
          <td><?= e($m['product_name']) ?></td>
          <td><?= e($m['warehouse_name'] ?? '—') ?></td>
          <td><?= e($m['batch_no'] ?? '—') ?></td>
          <td><span class="badge text-bg-<?= $moveBadge[$m['type']] ?? 'secondary' ?> badge-status"><?= e($m['type']) ?></span></td>
          <td class="text-end fw-bold"><?= (int)$m['quantity'] ?></td>
          <td class="text-muted small"><?= e($m['notes']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
    <div class="text-muted small">No stock has moved yet. Stock is updated when the entry is submitted.</div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
