<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/stock_entry.php';
require_module_edit('supply-chain');
stock_entry_require_schema();

$id = (int)input('id');
$entryTypes = stock_entry_types();
$initialType = isset($entryTypes[input('type')]) ? input('type') : 'material_receipt';

$entry = [
    'id' => 0, 'entry_no' => '', 'entry_type' => $initialType, 'posting_date' => today(), 'posting_time' => date('H:i'),
    'purpose' => '', 'source_warehouse_id' => in_array($initialType, ['material_issue', 'material_transfer'], true) ? default_warehouse_id() : '',
    'target_warehouse_id' => in_array($initialType, ['material_receipt', 'stock_adjustment'], true) ? default_warehouse_id() : '',
    'vendor_id' => '', 'reference_no' => '', 'requested_by' => current_user()['id'] ?? '', 'department' => '', 'project' => '', 'notes' => '',
    'distribute_costs_by' => 'amount',
    'mode_of_transport' => '', 'shipping_partner_id' => '', 'vehicle_no' => '', 'driver_name' => '', 'driver_phone' => '',
    'tracking_no' => '', 'eway_bill_no' => '', 'dispatch_date' => '', 'expected_arrival_date' => '', 'transport_remarks' => '',
    'cost_center' => '', 'business_unit' => '', 'approver_id' => '', 'inspection_required' => 0, 'remarks_internal' => '', 'tags' => '',
    'status' => 'draft',
];
$items = [];
$costRows = [];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM stock_entries WHERE id = ?');
    $stmt->execute([$id]);
    $entry = $stmt->fetch();
    if (!$entry) {
        flash('danger', 'Stock entry not found.');
        redirect('/supply-chain/stock_entries.php');
    }
    if ($entry['status'] !== 'draft') {
        flash('danger', 'Only draft stock entries can be edited.');
        redirect('/supply-chain/stock_entry_view.php?id=' . $id);
    }
    $entry['posting_time'] = $entry['posting_time'] ? substr($entry['posting_time'], 0, 5) : '';
    $stmt = db()->prepare('SELECT * FROM stock_entry_items WHERE stock_entry_id = ? ORDER BY sort_order, id');
    $stmt->execute([$id]);
    $items = $stmt->fetchAll();
    $stmt = db()->prepare('SELECT * FROM stock_entry_costs WHERE stock_entry_id = ? ORDER BY sort_order, id');
    $stmt->execute([$id]);
    $costRows = $stmt->fetchAll();
}

$error = '';
$activeTab = in_array(input('tab'), ['items', 'batch', 'costs', 'transport', 'more'], true) ? input('tab') : 'details';

if (is_post()) {
    csrf_verify();
    $submitAfterSave = input('submit_after_save') === '1';

    $entryType = isset($entryTypes[input('entry_type')]) ? input('entry_type') : 'material_receipt';
    $postingDate = input('posting_date') ?: today();
    $postingTime = preg_match('/^\d{2}:\d{2}/', input('posting_time')) ? input('posting_time') : null;
    $purpose = input('purpose') ?: null;
    $sourceWarehouseId = (int)input('source_warehouse_id') ?: null;
    $targetWarehouseId = (int)input('target_warehouse_id') ?: null;
    $vendorId = (int)input('vendor_id') ?: null;
    $referenceNo = input('reference_no') ?: null;
    $requestedBy = (int)input('requested_by') ?: null;
    $department = input('department') ?: null;
    $project = input('project') ?: null;
    $notes = input('notes') ?: null;
    $distributeBy = input('distribute_costs_by') === 'qty' ? 'qty' : 'amount';

    $modeOfTransport = input('mode_of_transport') ?: null;
    $shippingPartnerId = (int)input('shipping_partner_id') ?: null;
    $vehicleNo = strtoupper(input('vehicle_no')) ?: null;
    $driverName = input('driver_name') ?: null;
    $driverPhone = input('driver_phone') ?: null;
    $trackingNo = input('tracking_no') ?: null;
    $ewayBillNo = input('eway_bill_no') ?: null;
    $dispatchDate = input('dispatch_date') ?: null;
    $expectedArrivalDate = input('expected_arrival_date') ?: null;
    $transportRemarks = input('transport_remarks') ?: null;

    $costCenter = input('cost_center') ?: null;
    $businessUnit = input('business_unit') ?: null;
    $approverId = (int)input('approver_id') ?: null;
    $inspectionRequired = input('inspection_required') ? 1 : 0;
    $remarksInternal = input('remarks_internal') ?: null;
    $tags = input('tags') ?: null;

    // Only leaf, active warehouses can hold stock.
    $validWarehouses = [];
    foreach (leaf_warehouses() as $w) {
        $validWarehouses[(int)$w['id']] = true;
    }
    $validProducts = [];
    foreach (db()->query("SELECT id FROM products WHERE status = 'active' AND is_stock_item = 1") as $p) {
        $validProducts[(int)$p['id']] = true;
    }
    $whOrNull = fn($v) => ($v = (int)$v) && isset($validWarehouses[$v]) ? $v : null;
    $sourceWarehouseId = $whOrNull($sourceWarehouseId);
    $targetWarehouseId = $whOrNull($targetWarehouseId);

    $productIds = (array)($_POST['product_id'] ?? []);
    $descriptions = (array)($_POST['description'] ?? []);
    $sourceIds = (array)($_POST['item_source_warehouse_id'] ?? []);
    $targetIds = (array)($_POST['item_target_warehouse_id'] ?? []);
    $quantities = (array)($_POST['quantity'] ?? []);
    $lineUoms = (array)($_POST['uom'] ?? []);
    $rates = (array)($_POST['basic_rate'] ?? []);
    $batchNos = (array)($_POST['batch_no'] ?? []);
    $mfgDates = (array)($_POST['manufacturing_date'] ?? []);
    $expiryDates = (array)($_POST['expiry_date'] ?? []);
    $serialLists = (array)($_POST['serial_numbers'] ?? []);

    $dir = stock_entry_direction($entryType, 1);
    $lineItems = [];
    $lineError = '';
    foreach ($productIds as $i => $pid) {
        $pid = (int)$pid;
        $qty = (int)($quantities[$i] ?? 0);
        if (!$pid || !isset($validProducts[$pid]) || $qty === 0) {
            continue;
        }
        if ($entryType !== 'stock_adjustment') {
            $qty = abs($qty);
        }
        $src = in_array($entryType, ['material_issue', 'material_transfer'], true) ? ($whOrNull($sourceIds[$i] ?? 0) ?: $sourceWarehouseId) : null;
        $tgt = in_array($entryType, ['material_receipt', 'material_transfer', 'stock_adjustment'], true) ? ($whOrNull($targetIds[$i] ?? 0) ?: $targetWarehouseId) : null;
        $rowNo = count($lineItems) + 1;
        if ($dir['out'] && !$src && !$lineError) {
            $lineError = "Row $rowNo: pick a source warehouse (or set a default one on the Details tab).";
        }
        if (($dir['in'] || $entryType === 'stock_adjustment') && !$tgt && !$lineError) {
            $lineError = "Row $rowNo: pick a " . ($entryType === 'stock_adjustment' ? 'warehouse' : 'target warehouse') . ' (or set a default one on the Details tab).';
        }
        if ($entryType === 'material_transfer' && $src && $src === $tgt && !$lineError) {
            $lineError = "Row $rowNo: source and target warehouse must be different for a transfer.";
        }
        $uom = trim($lineUoms[$i] ?? '') ?: 'pcs';
        $lineItems[] = [
            'product_id' => $pid,
            'description' => trim($descriptions[$i] ?? '') ?: null,
            'source_warehouse_id' => $src,
            'target_warehouse_id' => $tgt,
            'quantity' => $qty,
            'uom' => $uom,
            'uom_conversion_factor' => uom_conversion_factor($pid, $uom),
            'basic_rate' => max(0, round((float)($rates[$i] ?? 0), 2)),
            'batch_no' => trim($batchNos[$i] ?? '') ?: null,
            'manufacturing_date' => ($mfgDates[$i] ?? '') ?: null,
            'expiry_date' => ($expiryDates[$i] ?? '') ?: null,
            'serial_numbers' => implode("\n", stock_entry_serials($serialLists[$i] ?? '')) ?: null,
        ];
    }

    $accountIds = [];
    foreach (db()->query('SELECT id FROM ledger_accounts') as $a) {
        $accountIds[(int)$a['id']] = true;
    }
    $costAccountIds = (array)($_POST['cost_account_head_id'] ?? []);
    $costDescriptions = (array)($_POST['cost_description'] ?? []);
    $costAmounts = (array)($_POST['cost_amount'] ?? []);
    $costsToSave = [];
    $totalCosts = 0;
    $sort = 0;
    foreach ($costAmounts as $i => $amt) {
        $amt = round(max(0, (float)$amt), 2);
        $desc = trim($costDescriptions[$i] ?? '');
        $acc = (int)($costAccountIds[$i] ?? 0);
        if ($amt == 0 && $desc === '') {
            continue;
        }
        $costsToSave[] = ['account_head_id' => isset($accountIds[$acc]) ? $acc : null, 'description' => $desc ?: null, 'amount' => $amt, 'sort_order' => $sort++];
        $totalCosts += $amt;
    }

    [$lineItems, $totals] = stock_entry_compute($lineItems, $totalCosts, $entryType, $distributeBy);

    if (!$lineItems) {
        $error = 'Please add at least one item with a quantity.';
        $activeTab = 'items';
    } elseif ($lineError) {
        $error = $lineError;
        $activeTab = 'items';
    } elseif ($entryType === 'material_transfer' && $sourceWarehouseId && $sourceWarehouseId === $targetWarehouseId) {
        $error = 'Default source and target warehouse must be different for a transfer.';
        $activeTab = 'details';
    } else {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $header = [
                'entry_type' => $entryType, 'posting_date' => $postingDate, 'posting_time' => $postingTime, 'purpose' => $purpose,
                'source_warehouse_id' => $dir['out'] ? $sourceWarehouseId : null,
                'target_warehouse_id' => ($dir['in'] || $entryType === 'stock_adjustment') ? $targetWarehouseId : null,
                'vendor_id' => $entryType === 'material_receipt' ? $vendorId : null, 'reference_no' => $referenceNo,
                'requested_by' => $requestedBy, 'department' => $department, 'project' => $project, 'notes' => $notes,
                'distribute_costs_by' => $distributeBy, 'total_qty' => $totals['total_qty'],
                'total_outgoing_value' => $totals['total_outgoing_value'], 'total_incoming_value' => $totals['total_incoming_value'],
                'total_additional_costs' => $totals['total_additional_costs'], 'value_difference' => $totals['value_difference'],
                'mode_of_transport' => $modeOfTransport, 'shipping_partner_id' => $shippingPartnerId, 'vehicle_no' => $vehicleNo,
                'driver_name' => $driverName, 'driver_phone' => $driverPhone, 'tracking_no' => $trackingNo, 'eway_bill_no' => $ewayBillNo,
                'dispatch_date' => $dispatchDate, 'expected_arrival_date' => $expectedArrivalDate, 'transport_remarks' => $transportRemarks,
                'cost_center' => $costCenter, 'business_unit' => $businessUnit, 'approver_id' => $approverId,
                'inspection_required' => $inspectionRequired, 'remarks_internal' => $remarksInternal, 'tags' => $tags,
            ];
            $cols = array_keys($header);
            $vals = array_values($header);
            if ($id) {
                $set = implode(', ', array_map(fn($c) => "$c=?", $cols));
                $pdo->prepare("UPDATE stock_entries SET $set WHERE id=? AND status='draft'")->execute([...$vals, $id]);
                $pdo->prepare('DELETE FROM stock_entry_items WHERE stock_entry_id=?')->execute([$id]);
                $pdo->prepare('DELETE FROM stock_entry_costs WHERE stock_entry_id=?')->execute([$id]);
                $entryId = $id;
            } else {
                $entryNo = next_code('STE', 'stock_entries', 'entry_no');
                $colList = implode(', ', ['entry_no', ...$cols, 'status', 'created_by']);
                $ph = implode(',', array_fill(0, count($vals) + 3, '?'));
                $pdo->prepare("INSERT INTO stock_entries ($colList) VALUES ($ph)")->execute([$entryNo, ...$vals, 'draft', current_user()['id']]);
                $entryId = (int)$pdo->lastInsertId();
            }
            $itemStmt = $pdo->prepare('INSERT INTO stock_entry_items (stock_entry_id, product_id, description, source_warehouse_id, target_warehouse_id, quantity, uom, uom_conversion_factor, basic_rate, basic_amount, additional_cost, valuation_rate, amount, batch_no, manufacturing_date, expiry_date, serial_numbers, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            foreach (array_values($lineItems) as $n => $li) {
                $itemStmt->execute([$entryId, $li['product_id'], $li['description'], $li['source_warehouse_id'], $li['target_warehouse_id'], $li['quantity'], $li['uom'], $li['uom_conversion_factor'], $li['basic_rate'], $li['basic_amount'], $li['additional_cost'], $li['valuation_rate'], $li['amount'], $li['batch_no'], $li['manufacturing_date'], $li['expiry_date'], $li['serial_numbers'], $n]);
            }
            $costStmt = $pdo->prepare('INSERT INTO stock_entry_costs (stock_entry_id, account_head_id, description, amount, sort_order) VALUES (?,?,?,?,?)');
            foreach ($costsToSave as $c) {
                $costStmt->execute([$entryId, $c['account_head_id'], $c['description'], $c['amount'], $c['sort_order']]);
            }
            $pdo->commit();
            log_activity('stock_entry', $entryId, $id ? 'edited' : 'created');
        } catch (Exception $e) {
            $pdo->rollBack();
            $entryId = 0;
            $error = 'Could not save stock entry.' . (defined('APP_DEBUG') && APP_DEBUG ? ' DEBUG: ' . $e->getMessage() : '');
        }

        if ($entryId) {
            if (!$submitAfterSave) {
                flash('success', $id ? 'Stock entry updated.' : 'Stock entry saved as draft.');
                redirect('/supply-chain/stock_entry_view.php?id=' . $entryId);
            }
            // Saved as a draft first, so a failed submit (e.g. not enough
            // stock) leaves the user's work intact to fix and resubmit.
            $pdo->beginTransaction();
            try {
                stock_entry_submit($entryId, current_user()['id']);
                $pdo->commit();
                log_activity('stock_entry', $entryId, 'submitted');
                flash('success', 'Stock entry submitted and stock updated.');
            } catch (Exception $e) {
                $pdo->rollBack();
                flash('danger', 'Saved as draft, but could not submit: ' . ($e instanceof RuntimeException ? $e->getMessage() : 'unexpected error.'));
            }
            redirect('/supply-chain/stock_entry_view.php?id=' . $entryId);
        }
    }

    $entry = array_merge($entry, [
        'entry_type' => $entryType, 'posting_date' => $postingDate, 'posting_time' => $postingTime, 'purpose' => $purpose,
        'source_warehouse_id' => $sourceWarehouseId, 'target_warehouse_id' => $targetWarehouseId, 'vendor_id' => $vendorId,
        'reference_no' => $referenceNo, 'requested_by' => $requestedBy, 'department' => $department, 'project' => $project,
        'notes' => $notes, 'distribute_costs_by' => $distributeBy, 'mode_of_transport' => $modeOfTransport,
        'shipping_partner_id' => $shippingPartnerId, 'vehicle_no' => $vehicleNo, 'driver_name' => $driverName,
        'driver_phone' => $driverPhone, 'tracking_no' => $trackingNo, 'eway_bill_no' => $ewayBillNo, 'dispatch_date' => $dispatchDate,
        'expected_arrival_date' => $expectedArrivalDate, 'transport_remarks' => $transportRemarks, 'cost_center' => $costCenter,
        'business_unit' => $businessUnit, 'approver_id' => $approverId, 'inspection_required' => $inspectionRequired,
        'remarks_internal' => $remarksInternal, 'tags' => $tags,
    ]);
    $items = array_values($lineItems);
    $costRows = $costsToSave;
}

$products = db()->query("SELECT id, sku, name, unit, cost_price, valuation_rate, has_batch_no, has_serial_no, batch_expiry_required FROM products WHERE status = 'active' AND is_stock_item = 1 ORDER BY name")->fetchAll();
$warehouses = leaf_warehouses();
$vendors = db()->query('SELECT id, name, company FROM vendors ORDER BY name')->fetchAll();
$users = db()->query("SELECT id, name FROM users WHERE status = 'active' ORDER BY name")->fetchAll();
$shippingPartners = db()->query("SELECT id, name FROM shipping_partners WHERE status = 'active' ORDER BY name")->fetchAll();
$ledgerAccounts = db()->query("SELECT id, name FROM ledger_accounts WHERE status = 'active' AND account_type <> 'tax'" . ledger_heads_filter() . " ORDER BY name")->fetchAll();

$productUomsByProduct = [];
foreach (db()->query('SELECT product_id, uom, conversion_factor FROM product_uoms ORDER BY sort_order, id') as $r) {
    $productUomsByProduct[(int)$r['product_id']][$r['uom']] = (float)$r['conversion_factor'];
}
$productMeta = [];
foreach ($products as $p) {
    $productMeta[(int)$p['id']] = [
        'sku' => $p['sku'], 'name' => $p['name'], 'unit' => $p['unit'],
        'rate' => (float)$p['valuation_rate'] > 0 ? (float)$p['valuation_rate'] : (float)$p['cost_price'],
        'uoms' => [$p['unit'] => 1.0] + ($productUomsByProduct[(int)$p['id']] ?? []),
        'batch' => (int)$p['has_batch_no'], 'serial' => (int)$p['has_serial_no'], 'expiry' => (int)$p['batch_expiry_required'],
    ];
}
// Live stock per product per warehouse, and per batch, for the
// "Available" column and the batch pick-list.
$stockMap = [];
foreach (db()->query('SELECT product_id, warehouse_id, SUM(quantity) qty FROM stock_bins GROUP BY product_id, warehouse_id') as $r) {
    $stockMap[(int)$r['product_id']][(int)$r['warehouse_id']] = (int)$r['qty'];
}
$batchMap = [];
foreach (db()->query('SELECT pb.product_id, pb.batch_no, pb.expiry_date, sb.warehouse_id, sb.quantity FROM product_batches pb LEFT JOIN stock_bins sb ON sb.batch_id = pb.id ORDER BY pb.batch_no') as $r) {
    $pid = (int)$r['product_id'];
    $batchMap[$pid][$r['batch_no']]['expiry'] = $r['expiry_date'];
    if ($r['warehouse_id']) {
        $batchMap[$pid][$r['batch_no']]['wh'][(int)$r['warehouse_id']] = (int)$r['quantity'];
    }
}
$warehouseNames = [];
foreach ($warehouses as $w) {
    $warehouseNames[(int)$w['id']] = $w['name'];
}

$purposes = ['Opening Stock', 'Restock', 'Production Consumption', 'Internal Use', 'Damaged / Expired', 'Lost / Theft', 'Sample / Promotion', 'Warehouse Rebalancing', 'Physical Count Correction'];
$statusBadge = ['draft' => 'secondary', 'submitted' => 'success', 'cancelled' => 'danger'];
$itemsForRender = $items ?: [['product_id' => '', 'description' => '', 'source_warehouse_id' => '', 'target_warehouse_id' => '', 'quantity' => 1, 'uom' => '', 'basic_rate' => 0, 'batch_no' => '', 'manufacturing_date' => '', 'expiry_date' => '', 'serial_numbers' => '']];

/** <option> list of leaf warehouses. */
function se_warehouse_options(array $warehouses, $selected, string $blank): string
{
    $html = '<option value="">' . e($blank) . '</option>';
    foreach ($warehouses as $w) {
        $html .= '<option value="' . (int)$w['id'] . '"' . ((string)$selected === (string)$w['id'] ? ' selected' : '') . '>' . e($w['name']) . '</option>';
    }
    return $html;
}

$page_title = $id ? 'Edit Stock Entry' : 'New Stock Entry';
require __DIR__ . '/../includes/header.php';
?>
<div class="card p-4">
  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
  <?php if (!$warehouses): ?>
    <div class="alert alert-warning">No warehouses set up yet. <a href="warehouse_form.php">Create one first</a>.</div>
  <?php endif; ?>
  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0"><?= $id ? e($entry['entry_no']) : 'New Stock Entry' ?> <span class="badge text-bg-<?= $statusBadge[$entry['status']] ?? 'secondary' ?> badge-status"><?= e($entry['status']) ?></span></h5>
  </div>

  <ul class="nav nav-tabs mb-3">
    <li class="nav-item"><button class="nav-link <?= $activeTab === 'details' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#pane-details" type="button">Details</button></li>
    <li class="nav-item"><button class="nav-link <?= $activeTab === 'items' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#pane-items" type="button">Items</button></li>
    <li class="nav-item"><button class="nav-link <?= $activeTab === 'batch' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#pane-batch" type="button">Batch &amp; Serial</button></li>
    <li class="nav-item"><button class="nav-link <?= $activeTab === 'costs' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#pane-costs" type="button">Additional Costs</button></li>
    <li class="nav-item"><button class="nav-link <?= $activeTab === 'transport' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#pane-transport" type="button">Transport</button></li>
    <li class="nav-item"><button class="nav-link <?= $activeTab === 'more' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#pane-more" type="button">More Info</button></li>
  </ul>

  <form method="post" id="seForm">
    <?= csrf_field() ?>
    <div class="tab-content">
      <div class="tab-pane fade <?= $activeTab === 'details' ? 'show active' : '' ?>" id="pane-details">
        <h6 class="text-muted mb-3"><i class="fa-solid fa-dolly"></i> Stock Entry Information</h6>
        <div class="row g-3 mb-3">
          <div class="col-sm-3">
            <label class="form-label">Stock Entry Type <span class="text-danger">*</span></label>
            <select name="entry_type" id="entryTypeSelect" class="form-select" required>
              <?php foreach ($entryTypes as $val => $label): ?>
                <option value="<?= $val ?>" <?= $entry['entry_type'] === $val ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-text" id="entryTypeHelp"></div>
          </div>
          <div class="col-sm-3">
            <label class="form-label">Posting Date <span class="text-danger">*</span></label>
            <input type="date" name="posting_date" class="form-control" value="<?= e($entry['posting_date']) ?>" required>
          </div>
          <div class="col-sm-3">
            <label class="form-label">Posting Time</label>
            <input type="time" name="posting_time" class="form-control" value="<?= e($entry['posting_time'] ?? '') ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Stock Entry No.</label>
            <input type="text" class="form-control" value="<?= $id ? e($entry['entry_no']) : 'Auto-generated' ?>" disabled>
          </div>

          <div class="col-sm-3 js-needs-source">
            <label class="form-label">Default Source Warehouse</label>
            <select name="source_warehouse_id" id="defaultSourceSelect" class="form-select"><?= se_warehouse_options($warehouses, $entry['source_warehouse_id'] ?? '', '— Select warehouse —') ?></select>
          </div>
          <div class="col-sm-3 js-needs-target">
            <label class="form-label" id="defaultTargetLabel">Default Target Warehouse</label>
            <select name="target_warehouse_id" id="defaultTargetSelect" class="form-select"><?= se_warehouse_options($warehouses, $entry['target_warehouse_id'] ?? '', '— Select warehouse —') ?></select>
          </div>
          <div class="col-sm-3">
            <label class="form-label">Purpose / Reason</label>
            <input type="text" name="purpose" class="form-control" list="purposeList" value="<?= e($entry['purpose'] ?? '') ?>">
            <datalist id="purposeList"><?php foreach ($purposes as $pu): ?><option value="<?= e($pu) ?>"><?php endforeach; ?></datalist>
          </div>
          <div class="col-sm-3 js-receipt-only">
            <label class="form-label">Vendor</label>
            <select name="vendor_id" class="form-select">
              <option value="">— Select vendor —</option>
              <?php foreach ($vendors as $v): ?>
                <option value="<?= (int)$v['id'] ?>" <?= (string)($entry['vendor_id'] ?? '') === (string)$v['id'] ? 'selected' : '' ?>><?= e($v['name']) ?><?= $v['company'] ? ' (' . e($v['company']) . ')' : '' ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-sm-3">
            <label class="form-label">Reference No.</label>
            <input type="text" name="reference_no" class="form-control" placeholder="e.g. challan, count sheet" value="<?= e($entry['reference_no'] ?? '') ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Requested By</label>
            <select name="requested_by" class="form-select">
              <option value="">— Select —</option>
              <?php foreach ($users as $u): ?>
                <option value="<?= (int)$u['id'] ?>" <?= (string)($entry['requested_by'] ?? '') === (string)$u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-3">
            <label class="form-label">Department</label>
            <input type="text" name="department" class="form-control" value="<?= e($entry['department'] ?? '') ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Project</label>
            <input type="text" name="project" class="form-control" value="<?= e($entry['project'] ?? '') ?>">
          </div>

          <div class="col-sm-12">
            <label class="form-label">Notes</label>
            <input type="text" name="notes" class="form-control" maxlength="255" value="<?= e($entry['notes'] ?? '') ?>">
          </div>
        </div>
      </div>

      <div class="tab-pane fade <?= $activeTab === 'items' ? 'show active' : '' ?>" id="pane-items">
        <h6 class="text-muted mb-3"><i class="fa-solid fa-box"></i> Items</h6>
        <div class="se-line-items">
          <div class="table-responsive">
            <table class="table">
              <thead>
                <tr>
                  <th style="width:20%">Item Code <span class="text-danger">*</span></th>
                  <th style="width:12%">Description</th>
                  <th style="width:13%" class="js-needs-source">Source Warehouse</th>
                  <th style="width:13%" class="js-needs-target js-target-label">Target Warehouse</th>
                  <th style="width:8%">Qty <span class="text-danger">*</span></th>
                  <th style="width:8%">UOM</th>
                  <th style="width:7%" class="text-end">Available</th>
                  <th style="width:9%">Basic Rate</th>
                  <th style="width:9%" class="text-end">Amount</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($itemsForRender as $n => $it): ?>
                <tr data-row data-uid="r<?= $n ?>">
                  <td>
                    <select class="form-select form-select-sm js-product" name="product_id[]">
                      <option value="">— Select item —</option>
                      <?php foreach ($products as $p): ?>
                        <option value="<?= (int)$p['id'] ?>" <?= (string)$it['product_id'] === (string)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?> (<?= e($p['sku']) ?>)</option>
                      <?php endforeach; ?>
                    </select>
                  </td>
                  <td><input type="text" class="form-control form-control-sm" name="description[]" value="<?= e($it['description'] ?? '') ?>"></td>
                  <td class="js-needs-source"><select class="form-select form-select-sm js-src" name="item_source_warehouse_id[]"><?= se_warehouse_options($warehouses, $it['source_warehouse_id'] ?? '', '— Default —') ?></select></td>
                  <td class="js-needs-target"><select class="form-select form-select-sm js-tgt" name="item_target_warehouse_id[]"><?= se_warehouse_options($warehouses, $it['target_warehouse_id'] ?? '', '— Default —') ?></select></td>
                  <td><input type="number" step="1" class="form-control form-control-sm js-qty" name="quantity[]" value="<?= e($it['quantity']) ?>"></td>
                  <td>
                    <select class="form-select form-select-sm js-uom" name="uom[]">
                      <?php $rowUoms = $it['product_id'] && isset($productMeta[(int)$it['product_id']]) ? $productMeta[(int)$it['product_id']]['uoms'] : ['pcs' => 1.0]; ?>
                      <?php foreach ($rowUoms as $uomName => $factor): ?>
                        <option value="<?= e($uomName) ?>" <?= (string)($it['uom'] ?? '') === (string)$uomName ? 'selected' : '' ?>><?= e($uomName) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </td>
                  <td class="text-end small js-available">—</td>
                  <td><input type="number" step="0.01" min="0" class="form-control form-control-sm js-rate" name="basic_rate[]" value="<?= e($it['basic_rate'] ?? 0) ?>"></td>
                  <td class="text-end js-amount">0.00</td>
                  <td><button type="button" class="btn btn-sm btn-outline-danger js-remove-row"><i class="fa-solid fa-xmark"></i></button></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
            <div>
              <button type="button" class="btn btn-sm btn-outline-brand mb-2 js-add-row"><i class="fa-solid fa-plus"></i> Add row</button>
              <div class="small text-muted" id="adjustHint">For a Stock Adjustment enter a negative quantity to reduce stock.</div>
            </div>
            <div class="col-sm-5 col-lg-3">
              <div class="d-flex justify-content-between mb-1"><span class="text-muted">Total Quantity</span><strong id="seTotalQty">0</strong></div>
              <div class="d-flex justify-content-between fs-5"><span>Total Value</span><strong id="seTotalValue">0.00</strong></div>
            </div>
          </div>
        </div>
      </div>

      <div class="tab-pane fade <?= $activeTab === 'batch' ? 'show active' : '' ?>" id="pane-batch">
        <h6 class="text-muted mb-1"><i class="fa-solid fa-barcode"></i> Batch &amp; Serial Numbers</h6>
        <div class="small text-muted mb-3">One row per item line. Batch-tracked and serial-tracked items must be filled in before the entry can be submitted. For outgoing stock, pick a batch that is in stock at the source warehouse.</div>
        <div class="table-responsive">
          <table class="table table-sm align-middle">
            <thead><tr><th style="width:4%">#</th><th style="width:22%">Item</th><th style="width:17%">Batch No.</th><th style="width:13%">Mfg. Date</th><th style="width:13%">Expiry Date</th><th>Serial Numbers <span class="text-muted small fw-normal">(one per line)</span></th></tr></thead>
            <tbody id="batchBody">
            <?php foreach ($itemsForRender as $n => $it): ?>
              <tr data-uid="r<?= $n ?>">
                <td class="js-bs-no"><?= $n + 1 ?></td>
                <td class="js-bs-item small">—</td>
                <td><input type="text" class="form-control form-control-sm js-batch" name="batch_no[]" autocomplete="off" value="<?= e($it['batch_no'] ?? '') ?>"><div class="small text-muted js-batch-hint"></div></td>
                <td><input type="date" class="form-control form-control-sm js-mfg" name="manufacturing_date[]" value="<?= e($it['manufacturing_date'] ?? '') ?>"></td>
                <td><input type="date" class="form-control form-control-sm js-exp" name="expiry_date[]" value="<?= e($it['expiry_date'] ?? '') ?>"></td>
                <td><textarea class="form-control form-control-sm js-serials" name="serial_numbers[]" rows="1"><?= e($it['serial_numbers'] ?? '') ?></textarea><div class="small js-serial-hint"></div></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <datalist id="batchOptions"></datalist>
      </div>

      <div class="tab-pane fade <?= $activeTab === 'costs' ? 'show active' : '' ?>" id="pane-costs">
        <h6 class="text-muted mb-1"><i class="fa-solid fa-coins"></i> Additional Costs</h6>
        <div class="small text-muted mb-3">Freight, handling or loading costs are added to the value of the incoming items (for a Material Issue, to all items).</div>
        <div class="row g-3">
          <div class="col-lg-7">
            <div class="card p-3 h-100 se-cost-rows">
              <div class="table-responsive mb-2">
                <table class="table table-sm">
                  <thead><tr><th style="width:35%">Expense Account</th><th>Description</th><th style="width:22%" class="text-end">Amount</th><th></th></tr></thead>
                  <tbody>
                  <?php $costRowsForRender = $costRows ?: [['account_head_id' => '', 'description' => '', 'amount' => '']]; ?>
                  <?php foreach ($costRowsForRender as $c): ?>
                    <tr data-row>
                      <td>
                        <select class="form-select form-select-sm" name="cost_account_head_id[]">
                          <option value="">— None —</option>
                          <?php foreach ($ledgerAccounts as $a): ?>
                            <option value="<?= (int)$a['id'] ?>" <?= (string)($c['account_head_id'] ?? '') === (string)$a['id'] ? 'selected' : '' ?>><?= e($a['name']) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </td>
                      <td><input type="text" class="form-control form-control-sm" name="cost_description[]" value="<?= e($c['description'] ?? '') ?>"></td>
                      <td><input type="number" step="0.01" min="0" class="form-control form-control-sm text-end js-cost-amount" name="cost_amount[]" value="<?= e($c['amount'] ?? '') ?>"></td>
                      <td><button type="button" class="btn btn-sm btn-outline-danger js-cost-remove"><i class="fa-solid fa-xmark"></i></button></td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <button type="button" class="btn btn-sm btn-outline-brand js-cost-add"><i class="fa-solid fa-plus"></i> Add Cost</button>
                <div class="d-flex align-items-center gap-2">
                  <label class="form-label small mb-0">Distribute by</label>
                  <select name="distribute_costs_by" id="distributeBySelect" class="form-select form-select-sm" style="width:auto">
                    <option value="amount" <?= $entry['distribute_costs_by'] === 'amount' ? 'selected' : '' ?>>Amount</option>
                    <option value="qty" <?= $entry['distribute_costs_by'] === 'qty' ? 'selected' : '' ?>>Quantity</option>
                  </select>
                </div>
              </div>
            </div>
          </div>
          <div class="col-lg-5">
            <div class="card p-3 h-100">
              <h6 class="mb-3"><i class="fa-solid fa-chart-pie"></i> Value Summary</h6>
              <div class="d-flex justify-content-between mb-1"><span class="text-muted">Total Outgoing Value</span><strong id="sumOutgoing">0.00</strong></div>
              <div class="d-flex justify-content-between mb-1"><span class="text-muted">Total Incoming Value</span><strong id="sumIncoming">0.00</strong></div>
              <div class="d-flex justify-content-between mb-1"><span class="text-muted">Additional Costs</span><strong id="sumCosts">0.00</strong></div>
              <hr>
              <div class="d-flex justify-content-between fs-5"><span>Value Difference</span><strong id="sumDiff">0.00</strong></div>
              <div class="small text-muted">Incoming − outgoing. A transfer with costs shows the costs here.</div>
            </div>
          </div>
        </div>
        <div class="card p-3 mt-3">
          <h6 class="mb-2">Valuation per Item</h6>
          <div class="table-responsive">
            <table class="table table-sm mb-0">
              <thead><tr><th>Item</th><th class="text-end">Qty</th><th class="text-end">Basic Amount</th><th class="text-end">Additional Cost</th><th class="text-end">Valuation Rate</th><th class="text-end">Amount</th></tr></thead>
              <tbody id="valuationBody"></tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="tab-pane fade <?= $activeTab === 'transport' ? 'show active' : '' ?>" id="pane-transport">
        <div class="row g-3 mb-3">
          <div class="col-lg-6">
            <div class="card p-3 h-100">
              <h6 class="mb-3"><i class="fa-solid fa-truck"></i> Dispatch</h6>
              <div class="row g-2 mb-2">
                <div class="col-sm-6">
                  <label class="form-label">Dispatch Date</label>
                  <input type="date" name="dispatch_date" class="form-control" value="<?= e($entry['dispatch_date'] ?? '') ?>">
                </div>
                <div class="col-sm-6">
                  <label class="form-label">Expected Arrival</label>
                  <input type="date" name="expected_arrival_date" class="form-control" value="<?= e($entry['expected_arrival_date'] ?? '') ?>">
                </div>
              </div>
              <div class="row g-2 mb-2">
                <div class="col-sm-6">
                  <label class="form-label">Mode of Transport</label>
                  <select name="mode_of_transport" class="form-select">
                    <option value="">— Select —</option>
                    <?php foreach (['Road', 'Rail', 'Air', 'Sea', 'Courier', 'Hand Carry', 'Vendor Delivery'] as $m): ?>
                      <option value="<?= e($m) ?>" <?= ($entry['mode_of_transport'] ?? '') === $m ? 'selected' : '' ?>><?= e($m) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-sm-6">
                  <label class="form-label">Transporter / Shipping Partner</label>
                  <select name="shipping_partner_id" class="form-select">
                    <option value="">— Select —</option>
                    <?php foreach ($shippingPartners as $sp): ?>
                      <option value="<?= (int)$sp['id'] ?>" <?= (string)($entry['shipping_partner_id'] ?? '') === (string)$sp['id'] ? 'selected' : '' ?>><?= e($sp['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
              <div>
                <label class="form-label">Transport Remarks</label>
                <textarea name="transport_remarks" class="form-control" rows="2" maxlength="255"><?= e($entry['transport_remarks'] ?? '') ?></textarea>
              </div>
            </div>
          </div>
          <div class="col-lg-6">
            <div class="card p-3 h-100">
              <h6 class="mb-3"><i class="fa-solid fa-id-card"></i> Vehicle &amp; Documents</h6>
              <div class="row g-2 mb-2">
                <div class="col-sm-6">
                  <label class="form-label">Vehicle No.</label>
                  <input type="text" name="vehicle_no" class="form-control" style="text-transform:uppercase" placeholder="e.g. MH12AB1234" value="<?= e($entry['vehicle_no'] ?? '') ?>">
                </div>
                <div class="col-sm-6">
                  <label class="form-label">E-Way Bill No.</label>
                  <input type="text" name="eway_bill_no" class="form-control" maxlength="30" value="<?= e($entry['eway_bill_no'] ?? '') ?>">
                </div>
              </div>
              <div class="row g-2 mb-2">
                <div class="col-sm-6">
                  <label class="form-label">Driver Name</label>
                  <input type="text" name="driver_name" class="form-control" value="<?= e($entry['driver_name'] ?? '') ?>">
                </div>
                <div class="col-sm-6">
                  <label class="form-label">Driver Phone</label>
                  <input type="text" name="driver_phone" class="form-control" value="<?= e($entry['driver_phone'] ?? '') ?>">
                </div>
              </div>
              <div>
                <label class="form-label">Tracking / LR No.</label>
                <input type="text" name="tracking_no" class="form-control" value="<?= e($entry['tracking_no'] ?? '') ?>">
              </div>
            </div>
          </div>
        </div>
        <div class="card p-3">
          <h6 class="mb-2">Items Moving</h6>
          <div class="table-responsive">
            <table class="table table-sm">
              <thead><tr><th>Item Code</th><th>Item Name</th><th>From</th><th>To</th><th class="text-end">Qty</th><th>UOM</th></tr></thead>
              <tbody id="movingBody"><tr><td colspan="6" class="text-muted text-center">Add items on the Items tab first.</td></tr></tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="tab-pane fade <?= $activeTab === 'more' ? 'show active' : '' ?>" id="pane-more">
        <div class="row g-3">
          <div class="col-lg-6">
            <div class="card p-3 mb-3">
              <h6 class="mb-3">Classification</h6>
              <div class="row g-3">
                <div class="col-sm-6">
                  <label class="form-label">Cost Center</label>
                  <input type="text" name="cost_center" class="form-control" value="<?= e($entry['cost_center'] ?? '') ?>">
                </div>
                <div class="col-sm-6">
                  <label class="form-label">Business Unit</label>
                  <input type="text" name="business_unit" class="form-control" value="<?= e($entry['business_unit'] ?? '') ?>">
                </div>
                <div class="col-sm-6">
                  <label class="form-label">Approver</label>
                  <select name="approver_id" class="form-select">
                    <option value="">— Select approver —</option>
                    <?php foreach ($users as $u): ?>
                      <option value="<?= (int)$u['id'] ?>" <?= (string)($entry['approver_id'] ?? '') === (string)$u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-sm-6">
                  <label class="form-label">Tags</label>
                  <input type="text" name="tags" class="form-control" placeholder="Comma-separated" value="<?= e($entry['tags'] ?? '') ?>">
                </div>
                <div class="col-sm-12">
                  <div class="form-check"><input type="checkbox" class="form-check-input" id="inspectionChk" name="inspection_required" value="1" <?= !empty($entry['inspection_required']) ? 'checked' : '' ?>><label class="form-check-label" for="inspectionChk">Quality Inspection Required</label></div>
                </div>
              </div>
            </div>
          </div>
          <div class="col-lg-6">
            <div class="card p-3 mb-3">
              <h6 class="mb-3">Remarks</h6>
              <label class="form-label">Remarks (Internal)</label>
              <textarea name="remarks_internal" class="form-control" rows="5"><?= e($entry['remarks_internal'] ?? '') ?></textarea>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="page-actions mt-3">
      <button type="submit" class="btn btn-outline-brand">Save Draft</button>
      <button type="submit" name="submit_after_save" value="1" class="btn btn-brand" data-confirm="Submit this stock entry? Stock levels will be updated.">Save &amp; Submit</button>
      <a href="<?= $id ? 'stock_entry_view.php?id=' . $id : 'stock_entries.php' ?>" class="btn btn-outline-secondary">Cancel</a>
    </div>
  </form>
</div>

<?php
$extra_js_inline = "
var productMeta = " . json_encode($productMeta) . ";
var stockMap = " . json_encode($stockMap ?: new stdClass()) . ";
var batchMap = " . json_encode($batchMap ?: new stdClass()) . ";
var warehouseNames = " . json_encode($warehouseNames ?: new stdClass()) . ";
var typeHelp = {
  material_receipt: 'Adds stock to the target warehouse (e.g. opening stock, found stock).',
  material_issue: 'Removes stock from the source warehouse (consumption, damage, samples).',
  material_transfer: 'Moves stock from the source warehouse to the target warehouse.',
  stock_adjustment: 'Corrects stock at one warehouse: positive qty adds, negative qty removes.'
};

function seEsc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
function seMoney(n) { return (Math.round(n * 100) / 100).toFixed(2); }

document.addEventListener('DOMContentLoaded', function () {
  var form = document.getElementById('seForm');
  var typeSel = document.getElementById('entryTypeSelect');
  var defSrc = document.getElementById('defaultSourceSelect');
  var defTgt = document.getElementById('defaultTargetSelect');
  var itemsWrap = document.querySelector('.se-line-items');
  var itemsBody = itemsWrap.querySelector('tbody');
  var batchBody = document.getElementById('batchBody');
  var costWrap = document.querySelector('.se-cost-rows');
  var costBody = costWrap.querySelector('tbody');
  var uidSeq = itemsBody.querySelectorAll('tr[data-row]').length;

  function type() { return typeSel.value; }
  function needsSource() { return type() === 'material_issue' || type() === 'material_transfer'; }
  function needsTarget() { return type() !== 'material_issue'; }
  function direction(qty) {
    switch (type()) {
      case 'material_receipt': return {out: false, in: true};
      case 'material_issue': return {out: true, in: false};
      case 'material_transfer': return {out: true, in: true};
      default: return {out: qty < 0, in: qty > 0};
    }
  }
  function rowSource(row) { return row.querySelector('.js-src').value || defSrc.value; }
  function rowTarget(row) { return row.querySelector('.js-tgt').value || defTgt.value; }
  function rowQty(row) { return parseInt(row.querySelector('.js-qty').value, 10) || 0; }
  function rowFactor(row) {
    var m = productMeta[row.querySelector('.js-product').value];
    var u = row.querySelector('.js-uom').value;
    return m && m.uoms && m.uoms[u] ? m.uoms[u] : 1;
  }
  // Warehouse whose stock matters for this row: source when stock leaves
  // it; for an adjustment, the single warehouse it corrects.
  function rowStockWarehouse(row) {
    if (type() === 'stock_adjustment') return rowTarget(row);
    return needsSource() ? rowSource(row) : rowTarget(row);
  }
  function batchRowFor(row) { return batchBody.querySelector('tr[data-uid=\"' + row.dataset.uid + '\"]'); }

  function applyTypeVisibility() {
    document.querySelectorAll('.js-needs-source').forEach(function (el) { el.classList.toggle('d-none', !needsSource()); });
    document.querySelectorAll('.js-needs-target').forEach(function (el) { el.classList.toggle('d-none', !needsTarget()); });
    document.querySelectorAll('.js-receipt-only').forEach(function (el) { el.classList.toggle('d-none', type() !== 'material_receipt'); });
    var lbl = type() === 'stock_adjustment' ? 'Warehouse' : 'Target Warehouse';
    document.getElementById('defaultTargetLabel').textContent = 'Default ' + lbl;
    document.querySelector('th.js-target-label').textContent = lbl;
    document.getElementById('adjustHint').classList.toggle('d-none', type() !== 'stock_adjustment');
    document.getElementById('entryTypeHelp').textContent = typeHelp[type()] || '';
    itemsBody.querySelectorAll('.js-qty').forEach(function (q) { q.min = type() === 'stock_adjustment' ? '' : '1'; });
  }

  // Keep one Batch & Serial row per item row, in the same order, so the
  // batch_no[] / serial_numbers[] arrays line up with product_id[].
  function syncBatchRows() {
    var n = 0;
    itemsBody.querySelectorAll('tr[data-row]').forEach(function (row) {
      n++;
      var br = batchRowFor(row);
      if (!br) {
        br = document.createElement('tr');
        br.dataset.uid = row.dataset.uid;
        br.innerHTML = '<td class=\"js-bs-no\"></td><td class=\"js-bs-item small\">—</td>' +
          '<td><input type=\"text\" class=\"form-control form-control-sm js-batch\" name=\"batch_no[]\" autocomplete=\"off\"><div class=\"small text-muted js-batch-hint\"></div></td>' +
          '<td><input type=\"date\" class=\"form-control form-control-sm js-mfg\" name=\"manufacturing_date[]\"></td>' +
          '<td><input type=\"date\" class=\"form-control form-control-sm js-exp\" name=\"expiry_date[]\"></td>' +
          '<td><textarea class=\"form-control form-control-sm js-serials\" name=\"serial_numbers[]\" rows=\"1\"></textarea><div class=\"small js-serial-hint\"></div></td>';
      }
      batchBody.appendChild(br); // (re)append keeps the order in sync
      br.querySelector('.js-bs-no').textContent = n;
      var m = productMeta[row.querySelector('.js-product').value];
      var label = m ? seEsc(m.name) + ' <span class=\"text-muted\">(' + seEsc(m.sku) + ')</span>' : '—';
      if (m && m.batch) label += ' <span class=\"badge text-bg-warning\">Batch</span>';
      if (m && m.serial) label += ' <span class=\"badge text-bg-info\">Serial</span>';
      br.querySelector('.js-bs-item').innerHTML = label;

      var stockQty = Math.round(Math.abs(rowQty(row)) * rowFactor(row));
      var dir = direction(rowQty(row));
      var batchInput = br.querySelector('.js-batch');
      var hint = br.querySelector('.js-batch-hint');
      var batches = m ? (batchMap[row.querySelector('.js-product').value] || {}) : {};
      var wh = rowStockWarehouse(row);
      var listId = 'batchList-' + row.dataset.uid;
      var dl = document.getElementById(listId);
      if (!dl) { dl = document.createElement('datalist'); dl.id = listId; br.appendChild(dl); }
      dl.innerHTML = Object.keys(batches).filter(function (b) { return !dir.out || (batches[b].wh && batches[b].wh[wh] > 0); }).map(function (b) {
        var q = batches[b].wh && batches[b].wh[wh] ? batches[b].wh[wh] : 0;
        return '<option value=\"' + seEsc(b) + '\">' + (dir.out ? 'In stock: ' + q : '') + (batches[b].expiry ? ' · Exp ' + seEsc(batches[b].expiry) : '') + '</option>';
      }).join('');
      batchInput.setAttribute('list', listId);
      var hintText = '';
      if (m && m.batch && !batchInput.value.trim()) hintText = '<span class=\"text-danger\">Batch required</span>';
      else if (batchInput.value.trim() && dir.out) {
        var b = batches[batchInput.value.trim()];
        var bq = b && b.wh && b.wh[wh] ? b.wh[wh] : 0;
        hintText = b ? 'In stock here: ' + bq : '<span class=\"text-danger\">Batch not found</span>';
      } else if (batchInput.value.trim() && batches[batchInput.value.trim()]) hintText = 'Existing batch';
      else if (batchInput.value.trim()) hintText = 'New batch will be created';
      hint.innerHTML = hintText;
      var needDates = !dir.out && batchInput.value.trim() && !batches[batchInput.value.trim()];
      // readOnly, not disabled: disabled inputs are left out of the POST and
      // would shift manufacturing_date[] / expiry_date[] out of line.
      ['.js-mfg', '.js-exp'].forEach(function (sel) {
        var inp = br.querySelector(sel);
        inp.readOnly = !needDates;
        inp.classList.toggle('bg-light', !needDates);
      });

      var serials = br.querySelector('.js-serials').value.split(/[\\n,]+/).map(function (s) { return s.trim(); }).filter(Boolean);
      var sh = br.querySelector('.js-serial-hint');
      if ((m && m.serial) || serials.length) {
        var ok = serials.length === stockQty;
        sh.className = 'small js-serial-hint ' + (ok ? 'text-success' : 'text-danger');
        sh.textContent = serials.length + ' of ' + stockQty + ' serial number(s)';
      } else {
        sh.textContent = '';
      }
    });
    batchBody.querySelectorAll('tr[data-uid]').forEach(function (br) {
      if (!itemsBody.querySelector('tr[data-uid=\"' + br.dataset.uid + '\"]')) br.remove();
    });
  }

  function recalc() {
    var totalQty = 0, rows = [];
    itemsBody.querySelectorAll('tr[data-row]').forEach(function (row) {
      var pid = row.querySelector('.js-product').value;
      var qty = rowQty(row);
      var rate = parseFloat(row.querySelector('.js-rate').value || 0) || 0;
      var basic = Math.round(Math.abs(qty) * rate * 100) / 100;
      var wh = rowStockWarehouse(row);
      var availEl = row.querySelector('.js-available');
      if (pid && wh) {
        var avail = stockMap[pid] && stockMap[pid][wh] ? stockMap[pid][wh] : 0;
        var need = Math.round(Math.abs(qty) * rowFactor(row));
        var short = direction(qty).out && need > avail;
        availEl.innerHTML = '<span class=\"' + (short ? 'text-danger fw-bold' : '') + '\">' + avail + '</span>';
        availEl.title = short ? 'Not enough stock at ' + (warehouseNames[wh] || 'this warehouse') : '';
      } else {
        availEl.textContent = '—';
      }
      if (pid && qty) { totalQty += Math.abs(qty); rows.push({row: row, pid: pid, qty: qty, basic: basic}); }
    });

    var totalCosts = 0;
    costBody.querySelectorAll('.js-cost-amount').forEach(function (i) { totalCosts += Math.max(0, parseFloat(i.value || 0) || 0); });
    totalCosts = Math.round(totalCosts * 100) / 100;
    var byQty = document.getElementById('distributeBySelect').value === 'qty';
    var targets = rows.filter(function (r) { return direction(r.qty).in; });
    if (!targets.length) targets = rows;
    var weight = function (r) { return byQty ? Math.abs(r.qty) : r.basic; };
    var totalWeight = targets.reduce(function (s, r) { return s + weight(r); }, 0);
    var allocated = 0;
    targets.forEach(function (r, i) {
      var share = i === targets.length - 1 ? Math.round((totalCosts - allocated) * 100) / 100
        : Math.round((totalWeight > 0 ? totalCosts * weight(r) / totalWeight : totalCosts / targets.length) * 100) / 100;
      r.cost = totalCosts > 0 ? share : 0;
      allocated += r.cost;
    });

    var outVal = 0, inVal = 0, valHtml = '';
    itemsBody.querySelectorAll('tr[data-row]').forEach(function (row) { row.querySelector('.js-amount').textContent = '0.00'; });
    rows.forEach(function (r) {
      r.cost = r.cost || 0;
      var amount = r.basic + r.cost;
      r.row.querySelector('.js-amount').textContent = seMoney(amount);
      var dir = direction(r.qty);
      if (dir.out) outVal += r.basic;
      if (dir.in) inVal += amount;
      var m = productMeta[r.pid];
      valHtml += '<tr><td>' + seEsc(m.name) + '</td><td class=\"text-end\">' + r.qty + '</td><td class=\"text-end\">' + seMoney(r.basic) + '</td><td class=\"text-end\">' + seMoney(r.cost) + '</td><td class=\"text-end\">' + seMoney(amount / Math.abs(r.qty)) + '</td><td class=\"text-end\">' + seMoney(amount) + '</td></tr>';
    });
    document.getElementById('valuationBody').innerHTML = valHtml || '<tr><td colspan=\"6\" class=\"text-muted text-center\">Add items on the Items tab first.</td></tr>';
    document.getElementById('seTotalQty').textContent = totalQty;
    document.getElementById('seTotalValue').textContent = seMoney(rows.reduce(function (s, r) { return s + r.basic + r.cost; }, 0));
    document.getElementById('sumOutgoing').textContent = seMoney(outVal);
    document.getElementById('sumIncoming').textContent = seMoney(inVal);
    document.getElementById('sumCosts').textContent = seMoney(totalCosts);
    document.getElementById('sumDiff').textContent = seMoney(inVal - outVal);

    var mv = '';
    rows.forEach(function (r) {
      var m = productMeta[r.pid];
      var dir = direction(r.qty);
      var from = type() === 'stock_adjustment' ? (r.qty < 0 ? warehouseNames[rowTarget(r.row)] : 'Adjustment') : (dir.out ? warehouseNames[rowSource(r.row)] : '—');
      var to = type() === 'stock_adjustment' ? (r.qty > 0 ? warehouseNames[rowTarget(r.row)] : 'Adjustment') : (dir.in ? warehouseNames[rowTarget(r.row)] : 'Consumed');
      mv += '<tr><td>' + seEsc(m.sku) + '</td><td>' + seEsc(m.name) + '</td><td>' + seEsc(from || '—') + '</td><td>' + seEsc(to || '—') + '</td><td class=\"text-end\">' + Math.abs(r.qty) + '</td><td>' + seEsc(r.row.querySelector('.js-uom').value) + '</td></tr>';
    });
    document.getElementById('movingBody').innerHTML = mv || '<tr><td colspan=\"6\" class=\"text-muted text-center\">Add items on the Items tab first.</td></tr>';
    syncBatchRows();
  }

  function applyProductDefaults(row) {
    var m = productMeta[row.querySelector('.js-product').value];
    if (!m) return;
    var uomSel = row.querySelector('.js-uom');
    uomSel.innerHTML = '';
    Object.keys(m.uoms || {}).forEach(function (name) {
      var o = document.createElement('option'); o.value = name; o.textContent = name; uomSel.appendChild(o);
    });
    row.querySelector('.js-rate').value = m.rate;
  }

  typeSel.addEventListener('change', function () {
    if (needsSource() && !defSrc.value && defTgt.value) defSrc.value = defTgt.value;
    if (needsTarget() && !defTgt.value && defSrc.value && type() !== 'material_transfer') defTgt.value = defSrc.value;
    applyTypeVisibility();
    recalc();
  });
  [defSrc, defTgt].forEach(function (el) { el.addEventListener('change', recalc); });
  itemsWrap.addEventListener('input', recalc);
  itemsWrap.addEventListener('change', function (e) {
    var row = e.target.closest('tr[data-row]');
    if (e.target.classList.contains('js-product')) applyProductDefaults(row);
    if (e.target.classList.contains('js-uom')) {
      // Rates are per stock unit in the item master; scale to the line's UOM.
      var m = productMeta[row.querySelector('.js-product').value];
      if (m) row.querySelector('.js-rate').value = Math.round(m.rate * rowFactor(row) * 100) / 100;
    }
    recalc();
  });
  itemsWrap.addEventListener('click', function (e) {
    if (e.target.closest('.js-add-row')) {
      var rows = itemsBody.querySelectorAll('tr[data-row]');
      var clone = rows[rows.length - 1].cloneNode(true);
      clone.dataset.uid = 'n' + (uidSeq++);
      clone.querySelectorAll('input').forEach(function (inp) { inp.value = inp.classList.contains('js-qty') ? 1 : (inp.classList.contains('js-rate') ? 0 : ''); });
      clone.querySelectorAll('select').forEach(function (sel) { sel.selectedIndex = 0; });
      clone.querySelector('.js-uom').innerHTML = '<option value=\"pcs\">pcs</option>';
      itemsBody.appendChild(clone);
      recalc();
      return;
    }
    var rm = e.target.closest('.js-remove-row');
    if (rm && itemsBody.querySelectorAll('tr[data-row]').length > 1) {
      rm.closest('tr[data-row]').remove();
      recalc();
    }
  });
  batchBody.addEventListener('input', syncBatchRows);

  costWrap.addEventListener('input', recalc);
  costWrap.addEventListener('change', recalc);
  document.getElementById('distributeBySelect').addEventListener('change', recalc);
  costWrap.addEventListener('click', function (e) {
    if (e.target.closest('.js-cost-add')) {
      var rows = costBody.querySelectorAll('tr[data-row]');
      var clone = rows[rows.length - 1].cloneNode(true);
      clone.querySelectorAll('input').forEach(function (inp) { inp.value = ''; });
      clone.querySelectorAll('select').forEach(function (sel) { sel.selectedIndex = 0; });
      costBody.appendChild(clone);
      return;
    }
    var rm = e.target.closest('.js-cost-remove');
    if (rm) {
      var tr = rm.closest('tr[data-row]');
      if (costBody.querySelectorAll('tr[data-row]').length > 1) tr.remove();
      else tr.querySelectorAll('input').forEach(function (inp) { inp.value = ''; });
      recalc();
    }
  });

  // Jump to the tab holding the first invalid field instead of letting
  // the browser silently refuse to submit from a hidden tab.
  form.addEventListener('invalid', function (e) {
    var pane = e.target.closest('.tab-pane');
    if (pane && !pane.classList.contains('active')) {
      var btn = document.querySelector('[data-bs-target=\"#' + pane.id + '\"]');
      if (btn) bootstrap.Tab.getOrCreateInstance(btn).show();
    }
  }, true);

  applyTypeVisibility();
  recalc();
});
";
require __DIR__ . '/../includes/footer.php';
?>
