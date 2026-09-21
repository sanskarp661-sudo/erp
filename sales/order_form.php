<?php
require_once __DIR__ . '/../includes/auth.php';
require_module_edit('sales');

$id = (int)input('id');
$defaultPriceListId = db()->query("SELECT id FROM price_lists WHERE is_default = 1 ORDER BY id LIMIT 1")->fetchColumn();

$order = [
    'id' => 0, 'order_no' => '', 'customer_id' => '', 'contact_person' => '', 'customer_address_id' => '',
    'warehouse_id' => default_warehouse_id(), 'order_date' => today(), 'required_delivery_date' => '',
    'price_list_id' => $defaultPriceListId ?: '', 'currency' => setting('currency_code', 'INR'),
    'sales_channel' => '', 'territory' => '', 'sales_person_id' => '', 'customer_po_no' => '', 'project' => '',
    'status' => 'pending', 'notes' => '',
    'tax_template_id' => '', 'place_of_supply' => '', 'gst_category' => '', 'reverse_charge' => 0, 'tax_remarks' => '',
    'rounding_method' => 'nearest', 'rounding_precision' => '0.01', 'additional_discount' => 0, 'additional_charge' => 0,
    'adjustment_type' => 'none', 'adjustment_amount' => 0, 'adjustment_remarks' => '',
];
$items = [];
$taxRows = [];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM sales_orders WHERE id = ?');
    $stmt->execute([$id]);
    $order = $stmt->fetch();
    if (!$order) {
        flash('danger', 'Sales order not found.');
        redirect('/sales/orders.php');
    }
    if ($order['status'] !== 'pending') {
        flash('danger', 'Only orders still in "pending" status can be edited.');
        redirect('/sales/order_view.php?id=' . $id);
    }
    $stmt = db()->prepare('SELECT * FROM sales_order_items WHERE order_id = ?');
    $stmt->execute([$id]);
    $items = $stmt->fetchAll();
    $stmt = db()->prepare('SELECT * FROM sales_order_taxes WHERE order_id = ? ORDER BY sort_order, id');
    $stmt->execute([$id]);
    $taxRows = $stmt->fetchAll();
}

$error = '';
$activeTab = in_array(input('tab'), ['items', 'taxes'], true) ? input('tab') : 'details';

/** Rounds $amount to the nearest multiple of $precision, per $method ('nearest'|'up'|'down'). */
function so_round(float $amount, float $precision, string $method): float
{
    if ($precision <= 0) {
        return round($amount, 2);
    }
    $units = $amount / $precision;
    $rounded = $method === 'up' ? ceil($units) : ($method === 'down' ? floor($units) : round($units));
    return round($rounded * $precision, 2);
}

if (is_post()) {
    csrf_verify();
    $customerId = (int)input('customer_id');
    $contactPerson = input('contact_person');
    $customerAddressId = (int)input('customer_address_id') ?: null;
    $orderDate = input('order_date') ?: today();
    $requiredDeliveryDate = input('required_delivery_date') ?: null;
    $priceListId = (int)input('price_list_id') ?: null;
    $currency = strtoupper(input('currency')) ?: 'INR';
    $salesChannel = input('sales_channel') ?: null;
    $territory = input('territory') ?: null;
    $salesPersonId = (int)input('sales_person_id') ?: null;
    $customerPoNo = input('customer_po_no') ?: null;
    $project = input('project') ?: null;
    $notes = input('notes');

    $taxTemplateId = (int)input('tax_template_id') ?: null;
    $placeOfSupply = input('place_of_supply') ?: null;
    $gstCategory = in_array(input('gst_category'), ['registered_business', 'unregistered_business', 'consumer', 'overseas', 'sez'], true) ? input('gst_category') : null;
    $reverseCharge = input('reverse_charge') ? 1 : 0;
    $taxRemarks = input('tax_remarks') ?: null;
    $roundingMethod = in_array(input('rounding_method'), ['nearest', 'up', 'down'], true) ? input('rounding_method') : 'nearest';
    $roundingPrecision = (float)input('rounding_precision') ?: 0.01;
    $additionalDiscount = max(0, (float)input('additional_discount'));
    $additionalCharge = max(0, (float)input('additional_charge'));
    $adjustmentType = in_array(input('adjustment_type'), ['none', 'add', 'subtract'], true) ? input('adjustment_type') : 'none';
    $adjustmentAmount = max(0, (float)input('adjustment_amount'));
    $adjustmentRemarks = input('adjustment_remarks') ?: null;

    $productIds = $_POST['product_id'] ?? [];
    $descriptions = $_POST['description'] ?? [];
    $warehouseIds = $_POST['item_warehouse_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $uoms = $_POST['uom'] ?? [];
    $prices = $_POST['unit_price'] ?? [];
    $discounts = $_POST['discount_percent'] ?? [];

    $lineItems = [];
    $netAmount = 0;
    $firstWarehouseId = null;
    foreach ($productIds as $i => $pid) {
        $pid = (int)$pid;
        $qty = (int)($quantities[$i] ?? 0);
        $price = (float)($prices[$i] ?? 0);
        $discount = max(0, min(100, (float)($discounts[$i] ?? 0)));
        $warehouseId = (int)($warehouseIds[$i] ?? 0) ?: null;
        if ($pid > 0 && $qty > 0) {
            $subtotal = round($qty * $price * (1 - $discount / 100), 2);
            $lineItems[] = [
                'product_id' => $pid,
                'description' => $descriptions[$i] ?? '',
                'warehouse_id' => $warehouseId,
                'quantity' => $qty,
                'uom' => $uoms[$i] ?? 'pcs',
                'unit_price' => $price,
                'discount_percent' => $discount,
                'subtotal' => $subtotal,
            ];
            $netAmount += $subtotal;
            if ($firstWarehouseId === null) {
                $firstWarehouseId = $warehouseId;
            }
        }
    }

    // Tax/charge rows: amounts are always computed server-side from the
    // server-computed net amount — the client's own live preview is a
    // convenience, never trusted for the figure that gets saved.
    $accountTypes = [];
    foreach (db()->query('SELECT id, account_type FROM ledger_accounts') as $a) {
        $accountTypes[(int)$a['id']] = $a['account_type'];
    }
    $rowTypes = $_POST['row_type'] ?? [];
    $rowAccountIds = $_POST['row_account_head_id'] ?? [];
    $rowDescriptions = $_POST['row_description'] ?? [];
    $rowBasedOns = $_POST['row_based_on'] ?? [];
    $rowRates = $_POST['row_rate_or_amount'] ?? [];

    $taxRowsToSave = [];
    $totalTaxAmount = 0;
    $totalCharges = 0;
    $sort = 0;
    foreach ($rowDescriptions as $i => $desc) {
        $desc = trim($desc);
        $rate = (float)($rowRates[$i] ?? 0);
        if ($desc === '' && $rate == 0) {
            continue;
        }
        $type = in_array($rowTypes[$i] ?? '', ['on_item', 'on_order'], true) ? $rowTypes[$i] : 'on_item';
        $basedOn = in_array($rowBasedOns[$i] ?? '', ['net_amount', 'actual_amount'], true) ? $rowBasedOns[$i] : 'net_amount';
        $accountId = (int)($rowAccountIds[$i] ?? 0) ?: null;
        $amount = $basedOn === 'net_amount' ? round($netAmount * $rate / 100, 2) : round($rate, 2);
        $taxRowsToSave[] = [
            'type' => $type, 'account_head_id' => $accountId, 'description' => $desc,
            'based_on' => $basedOn, 'rate_or_amount' => $rate, 'amount' => $amount, 'sort_order' => $sort++,
        ];
        if ($accountId && ($accountTypes[$accountId] ?? '') === 'tax') {
            $totalTaxAmount += $amount;
        } else {
            $totalCharges += $amount;
        }
    }

    $grandTotal = $netAmount + $totalCharges + $totalTaxAmount + $additionalCharge - $additionalDiscount;
    if ($adjustmentType === 'add') {
        $grandTotal += $adjustmentAmount;
    } elseif ($adjustmentType === 'subtract') {
        $grandTotal -= $adjustmentAmount;
    }
    $grandTotal = so_round($grandTotal, $roundingPrecision, $roundingMethod);

    if (!$customerId) {
        $error = 'Please select a customer.';
        $activeTab = 'details';
    } elseif (!$lineItems) {
        $error = 'Please add at least one valid line item.';
        $activeTab = 'items';
    } else {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $headerColNames = ['customer_id', 'contact_person', 'customer_address_id', 'warehouse_id', 'order_date', 'required_delivery_date', 'price_list_id', 'currency', 'sales_channel', 'territory', 'sales_person_id', 'customer_po_no', 'project', 'notes', 'total_amount', 'net_amount', 'tax_template_id', 'place_of_supply', 'gst_category', 'reverse_charge', 'tax_remarks', 'rounding_method', 'rounding_precision', 'additional_discount', 'additional_charge', 'adjustment_type', 'adjustment_amount', 'adjustment_remarks'];
            $headerVals = [$customerId, $contactPerson, $customerAddressId, $firstWarehouseId, $orderDate, $requiredDeliveryDate, $priceListId, $currency, $salesChannel, $territory, $salesPersonId, $customerPoNo, $project, $notes, $grandTotal, $netAmount, $taxTemplateId, $placeOfSupply, $gstCategory, $reverseCharge, $taxRemarks, $roundingMethod, $roundingPrecision, $additionalDiscount, $additionalCharge, $adjustmentType, $adjustmentAmount, $adjustmentRemarks];
            if ($id) {
                $setClause = implode(', ', array_map(fn($c) => "$c=?", $headerColNames));
                $pdo->prepare("UPDATE sales_orders SET $setClause WHERE id=?")->execute([...$headerVals, $id]);
                $pdo->prepare('DELETE FROM sales_order_items WHERE order_id=?')->execute([$id]);
                $pdo->prepare('DELETE FROM sales_order_taxes WHERE order_id=?')->execute([$id]);
                $orderId = $id;
            } else {
                $orderNo = next_code('SO', 'sales_orders', 'order_no');
                $colList = implode(', ', ['order_no', ...$headerColNames, 'status', 'created_by']);
                $placeholders = implode(',', array_fill(0, count($headerVals) + 3, '?'));
                $pdo->prepare("INSERT INTO sales_orders ($colList) VALUES ($placeholders)")
                    ->execute([$orderNo, ...$headerVals, 'pending', current_user()['id']]);
                $orderId = (int)$pdo->lastInsertId();
            }
            $itemStmt = $pdo->prepare('INSERT INTO sales_order_items (order_id, product_id, description, warehouse_id, quantity, uom, unit_price, discount_percent, subtotal) VALUES (?,?,?,?,?,?,?,?,?)');
            foreach ($lineItems as $li) {
                $itemStmt->execute([$orderId, $li['product_id'], $li['description'], $li['warehouse_id'], $li['quantity'], $li['uom'], $li['unit_price'], $li['discount_percent'], $li['subtotal']]);
            }
            $taxStmt = $pdo->prepare('INSERT INTO sales_order_taxes (order_id, type, account_head_id, description, based_on, rate_or_amount, amount, sort_order) VALUES (?,?,?,?,?,?,?,?)');
            foreach ($taxRowsToSave as $tr) {
                $taxStmt->execute([$orderId, $tr['type'], $tr['account_head_id'], $tr['description'], $tr['based_on'], $tr['rate_or_amount'], $tr['amount'], $tr['sort_order']]);
            }
            $pdo->commit();
            flash('success', $id ? 'Sales order updated.' : 'Sales order created.');
            redirect('/sales/order_view.php?id=' . $orderId);
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Could not save sales order.' . (defined('APP_DEBUG') && APP_DEBUG ? ' DEBUG: ' . $e->getMessage() : '');
        }
    }

    $order = [
        'id' => $id, 'order_no' => $order['order_no'] ?? '', 'customer_id' => $customerId, 'contact_person' => $contactPerson,
        'customer_address_id' => $customerAddressId, 'warehouse_id' => $firstWarehouseId, 'order_date' => $orderDate,
        'required_delivery_date' => $requiredDeliveryDate, 'price_list_id' => $priceListId, 'currency' => $currency,
        'sales_channel' => $salesChannel, 'territory' => $territory, 'sales_person_id' => $salesPersonId,
        'customer_po_no' => $customerPoNo, 'project' => $project, 'status' => $order['status'] ?? 'pending', 'notes' => $notes,
        'tax_template_id' => $taxTemplateId, 'place_of_supply' => $placeOfSupply, 'gst_category' => $gstCategory,
        'reverse_charge' => $reverseCharge, 'tax_remarks' => $taxRemarks, 'rounding_method' => $roundingMethod,
        'rounding_precision' => $roundingPrecision, 'additional_discount' => $additionalDiscount, 'additional_charge' => $additionalCharge,
        'adjustment_type' => $adjustmentType, 'adjustment_amount' => $adjustmentAmount, 'adjustment_remarks' => $adjustmentRemarks,
    ];
    $items = $lineItems;
    $taxRows = $taxRowsToSave;
}

$newAddressId = (int)input('new_address_id');
if ($newAddressId) {
    $order['customer_address_id'] = $newAddressId;
}

$customers = db()->query('SELECT id, name FROM customers ORDER BY name')->fetchAll();
$products = db()->query("SELECT p.id, p.sku, p.name, p.selling_price, p.quantity, p.unit, c.name category_name FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.status='active' ORDER BY p.name")->fetchAll();
$warehouses = leaf_warehouses();
$priceLists = db()->query("SELECT id, name, currency FROM price_lists WHERE status='active' ORDER BY is_default DESC, name")->fetchAll();
$salesUsers = db()->query("SELECT id, name FROM users WHERE status='active' ORDER BY name")->fetchAll();
$uoms = db()->query("SELECT name FROM uom WHERE status='active' ORDER BY name")->fetchAll();

$addressesByCustomer = [];
$addrStmt = db()->query('SELECT id, customer_id, label, address_line, city, state, pincode, is_default FROM customer_addresses ORDER BY is_default DESC, label');
foreach ($addrStmt as $a) {
    $addressesByCustomer[(int)$a['customer_id']][] = [
        'id' => (int)$a['id'],
        'text' => $a['label'] . ': ' . $a['address_line'] . ($a['city'] ? ', ' . $a['city'] : ''),
    ];
}

$priceListRates = [];
$plRateStmt = db()->query('SELECT price_list_id, product_id, rate FROM price_list_items');
foreach ($plRateStmt as $r) {
    $priceListRates[(int)$r['price_list_id']][(int)$r['product_id']] = (float)$r['rate'];
}

$productMeta = [];
foreach ($products as $p) {
    $productMeta[(int)$p['id']] = [
        'sku' => $p['sku'], 'name' => $p['name'], 'unit' => $p['unit'],
        'category' => $p['category_name'] ?: '', 'stock' => (int)$p['quantity'], 'rate' => (float)$p['selling_price'],
    ];
}

$salesChannels = ['Direct', 'Online Store', 'Marketplace', 'Retail', 'Distributor', 'POS'];
$statusBadge = ['pending' => 'secondary', 'confirmed' => 'info', 'shipped' => 'primary', 'completed' => 'success', 'cancelled' => 'danger'];

$ledgerAccounts = db()->query("SELECT id, name, account_type FROM ledger_accounts WHERE status='active' ORDER BY account_type, name")->fetchAll();
$accountMeta = [];
foreach ($ledgerAccounts as $a) {
    $accountMeta[(int)$a['id']] = ['name' => $a['name'], 'type' => $a['account_type']];
}

$taxTemplates = db()->query("SELECT id, name FROM tax_templates WHERE status='active' ORDER BY name")->fetchAll();
$taxTemplateRows = [];
$ttStmt = db()->query('SELECT tax_template_id, type, account_head_id, description, based_on, rate_or_amount FROM tax_template_items ORDER BY tax_template_id, sort_order, id');
foreach ($ttStmt as $r) {
    $taxTemplateRows[(int)$r['tax_template_id']][] = [
        'type' => $r['type'], 'account_head_id' => $r['account_head_id'] ? (int)$r['account_head_id'] : null,
        'description' => $r['description'], 'based_on' => $r['based_on'], 'rate_or_amount' => (float)$r['rate_or_amount'],
    ];
}

$gstCategories = ['registered_business' => 'Registered Business', 'unregistered_business' => 'Unregistered Business', 'consumer' => 'Consumer', 'overseas' => 'Overseas', 'sez' => 'SEZ'];

$page_title = $id ? 'Edit Sales Order' : 'New Sales Order';
require __DIR__ . '/../includes/header.php';
?>
<div class="card p-4">
  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0"><?= $id ? e($order['order_no']) : 'New Sales Order' ?> <span class="badge text-bg-<?= $statusBadge[$order['status']] ?? 'secondary' ?> badge-status"><?= e($order['status']) ?></span></h5>
  </div>

  <ul class="nav nav-tabs mb-3">
    <li class="nav-item"><button class="nav-link <?= $activeTab === 'details' ? 'active' : '' ?>" id="tab-details" data-bs-toggle="tab" data-bs-target="#pane-details" type="button">Details</button></li>
    <li class="nav-item"><button class="nav-link <?= $activeTab === 'items' ? 'active' : '' ?>" id="tab-items" data-bs-toggle="tab" data-bs-target="#pane-items" type="button">Items</button></li>
    <li class="nav-item"><button class="nav-link <?= $activeTab === 'taxes' ? 'active' : '' ?>" id="tab-taxes" data-bs-toggle="tab" data-bs-target="#pane-taxes" type="button">Taxes &amp; Charges</button></li>
  </ul>

  <form method="post" id="soForm">
    <?= csrf_field() ?>
    <div class="tab-content">
      <div class="tab-pane fade <?= $activeTab === 'details' ? 'show active' : '' ?>" id="pane-details">
        <h6 class="text-muted mb-3"><i class="fa-solid fa-user"></i> Customer &amp; Order Information</h6>
        <div class="row g-3 mb-3">
          <div class="col-sm-3">
            <label class="form-label">Customer <span class="text-danger">*</span></label>
            <select name="customer_id" id="customerSelect" class="form-select" required>
              <option value="">— Select customer —</option>
              <?php foreach ($customers as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= (string)$order['customer_id'] === (string)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-3">
            <label class="form-label">Contact Person</label>
            <input type="text" name="contact_person" class="form-control" value="<?= e($order['contact_person'] ?? '') ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Order Date <span class="text-danger">*</span></label>
            <input type="date" name="order_date" class="form-control" value="<?= e($order['order_date']) ?>" required>
          </div>
          <div class="col-sm-3">
            <label class="form-label">Sales Order No.</label>
            <input type="text" class="form-control" value="<?= $id ? e($order['order_no']) : 'Auto-generated' ?>" disabled>
          </div>

          <div class="col-sm-3">
            <label class="form-label">Customer Address</label>
            <select name="customer_address_id" id="addressSelect" class="form-select">
              <option value="">— Select address —</option>
            </select>
            <a href="#" id="newAddressLink" class="small">+ New Address</a>
          </div>
          <div class="col-sm-3">
            <label class="form-label">Required Delivery Date</label>
            <input type="date" name="required_delivery_date" class="form-control" value="<?= e($order['required_delivery_date'] ?? '') ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Sales Channel</label>
            <select name="sales_channel" class="form-select">
              <option value="">— Select sales channel —</option>
              <?php foreach ($salesChannels as $ch): ?>
                <option value="<?= e($ch) ?>" <?= $order['sales_channel'] === $ch ? 'selected' : '' ?>><?= e($ch) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-3">
            <label class="form-label">Sales Person</label>
            <select name="sales_person_id" class="form-select">
              <option value="">— Select sales person —</option>
              <?php foreach ($salesUsers as $u): ?>
                <option value="<?= (int)$u['id'] ?>" <?= (string)($order['sales_person_id'] ?? '') === (string)$u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-sm-3">
            <label class="form-label">Price List <span class="text-danger">*</span></label>
            <select name="price_list_id" id="priceListSelect" class="form-select" required>
              <?php foreach ($priceLists as $pl): ?>
                <option value="<?= (int)$pl['id'] ?>" data-currency="<?= e($pl['currency']) ?>" <?= (string)$order['price_list_id'] === (string)$pl['id'] ? 'selected' : '' ?>><?= e($pl['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-3">
            <label class="form-label">Territory</label>
            <input type="text" name="territory" class="form-control" placeholder="e.g. West India" value="<?= e($order['territory'] ?? '') ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Customer PO No.</label>
            <input type="text" name="customer_po_no" class="form-control" value="<?= e($order['customer_po_no'] ?? '') ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Currency <span class="text-danger">*</span></label>
            <input type="text" name="currency" id="currencyInput" class="form-control" maxlength="3" style="text-transform:uppercase" value="<?= e($order['currency']) ?>" required>
          </div>

          <div class="col-sm-3">
            <label class="form-label">Project</label>
            <input type="text" name="project" class="form-control" value="<?= e($order['project'] ?? '') ?>">
          </div>
          <div class="col-sm-9">
            <label class="form-label">Notes</label>
            <input type="text" name="notes" class="form-control" value="<?= e($order['notes'] ?? '') ?>">
          </div>
        </div>
      </div>

      <div class="tab-pane fade <?= $activeTab === 'items' ? 'show active' : '' ?>" id="pane-items">
        <h6 class="text-muted mb-3"><i class="fa-solid fa-box"></i> Items</h6>
        <div class="so-line-items">
          <div class="table-responsive">
            <table class="table">
              <thead>
                <tr>
                  <th style="width:20%">Item Code <span class="text-danger">*</span></th>
                  <th style="width:14%">Description</th>
                  <th style="width:14%">Warehouse <span class="text-danger">*</span></th>
                  <th style="width:8%">Qty <span class="text-danger">*</span></th>
                  <th style="width:9%">UOM <span class="text-danger">*</span></th>
                  <th style="width:11%">Rate <span class="text-danger">*</span></th>
                  <th style="width:8%">Discount %</th>
                  <th style="width:12%" class="text-end">Amount</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
              <?php if (!$items): $items = [['product_id' => '', 'description' => '', 'warehouse_id' => default_warehouse_id(), 'quantity' => 1, 'uom' => 'pcs', 'unit_price' => 0, 'discount_percent' => 0]]; endif; ?>
              <?php foreach ($items as $it): ?>
                <tr data-row>
                  <td>
                    <select class="form-select form-select-sm js-product" name="product_id[]">
                      <option value="">— Select item —</option>
                      <?php foreach ($products as $p): ?>
                        <option value="<?= (int)$p['id'] ?>" <?= (string)$it['product_id'] === (string)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?> (<?= e($p['sku']) ?>)</option>
                      <?php endforeach; ?>
                    </select>
                  </td>
                  <td><input type="text" class="form-control form-control-sm" name="description[]" value="<?= e($it['description'] ?? '') ?>"></td>
                  <td>
                    <select class="form-select form-select-sm js-warehouse" name="item_warehouse_id[]">
                      <option value="">— Select —</option>
                      <?php foreach ($warehouses as $w): ?>
                        <option value="<?= (int)$w['id'] ?>" <?= (string)($it['warehouse_id'] ?? '') === (string)$w['id'] ? 'selected' : '' ?>><?= e($w['name']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </td>
                  <td><input type="number" min="1" class="form-control form-control-sm js-qty" name="quantity[]" value="<?= e($it['quantity']) ?>"></td>
                  <td>
                    <select class="form-select form-select-sm js-uom" name="uom[]">
                      <?php foreach ($uoms as $u): ?>
                        <option value="<?= e($u['name']) ?>" <?= (string)($it['uom'] ?? 'pcs') === (string)$u['name'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </td>
                  <td><input type="number" step="0.01" min="0" class="form-control form-control-sm js-price" name="unit_price[]" value="<?= e($it['unit_price']) ?>"></td>
                  <td><input type="number" step="0.01" min="0" max="100" class="form-control form-control-sm js-discount" name="discount_percent[]" value="<?= e($it['discount_percent'] ?? 0) ?>"></td>
                  <td class="text-end js-amount">0.00</td>
                  <td><button type="button" class="btn btn-sm btn-outline-danger js-remove-row"><i class="fa-solid fa-xmark"></i></button></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <button type="button" class="btn btn-sm btn-outline-brand mb-3 js-add-row"><i class="fa-solid fa-plus"></i> Add row</button>

          <div class="row justify-content-end">
            <div class="col-sm-5 col-lg-3">
              <div class="d-flex justify-content-between mb-1"><span class="text-muted">Total Quantity</span><strong id="soTotalQty">0</strong></div>
              <div class="d-flex justify-content-between fs-5"><span>Total</span><strong id="soTotal">0.00</strong></div>
            </div>
          </div>
        </div>
      </div>

      <div class="tab-pane fade <?= $activeTab === 'taxes' ? 'show active' : '' ?>" id="pane-taxes">
        <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
          <div>
            <h6 class="text-muted mb-0"><i class="fa-solid fa-percent"></i> Taxes and Charges</h6>
            <div class="small text-muted">Apply taxes, charges and additional costs to this sales order.</div>
          </div>
          <div class="d-flex gap-2 align-items-end">
            <div>
              <label class="form-label small mb-1">Tax Template</label>
              <select id="taxTemplateSelect" class="form-select form-select-sm">
                <option value="">— None —</option>
                <?php foreach ($taxTemplates as $tt): ?>
                  <option value="<?= (int)$tt['id'] ?>" <?= (string)$order['tax_template_id'] === (string)$tt['id'] ? 'selected' : '' ?>><?= e($tt['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <button type="button" id="applyTemplateBtn" class="btn btn-sm btn-outline-brand"><i class="fa-solid fa-download"></i> Apply Template</button>
          </div>
        </div>
        <input type="hidden" name="tax_template_id" id="taxTemplateIdInput" value="<?= e($order['tax_template_id'] ?? '') ?>">

        <div class="so-tax-rows">
          <div class="table-responsive mb-2">
            <table class="table table-sm">
              <thead><tr><th>Type</th><th>Account Head</th><th>Description</th><th style="width:130px">Rate / Amount</th><th>Based On</th><th class="text-end" style="width:110px">Amount</th><th></th></tr></thead>
              <tbody>
              <?php if (!$taxRows): $taxRows = []; endif; ?>
              <?php foreach ($taxRows as $tr): ?>
                <tr data-row>
                  <td>
                    <select class="form-select form-select-sm" name="row_type[]">
                      <option value="on_item" <?= $tr['type'] === 'on_item' ? 'selected' : '' ?>>On Item</option>
                      <option value="on_order" <?= $tr['type'] === 'on_order' ? 'selected' : '' ?>>On Order</option>
                    </select>
                  </td>
                  <td>
                    <select class="form-select form-select-sm js-tax-account" name="row_account_head_id[]">
                      <option value="">— None —</option>
                      <?php foreach ($ledgerAccounts as $a): ?>
                        <option value="<?= (int)$a['id'] ?>" <?= (string)($tr['account_head_id'] ?? '') === (string)$a['id'] ? 'selected' : '' ?>><?= e($a['name']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </td>
                  <td><input type="text" class="form-control form-control-sm" name="row_description[]" value="<?= e($tr['description'] ?? '') ?>"></td>
                  <td><input type="number" step="0.01" class="form-control form-control-sm js-tax-rate" name="row_rate_or_amount[]" value="<?= e($tr['rate_or_amount']) ?>"></td>
                  <td>
                    <select class="form-select form-select-sm js-tax-basedon" name="row_based_on[]">
                      <option value="net_amount" <?= $tr['based_on'] === 'net_amount' ? 'selected' : '' ?>>Net Amount</option>
                      <option value="actual_amount" <?= $tr['based_on'] === 'actual_amount' ? 'selected' : '' ?>>Actual Amount</option>
                    </select>
                  </td>
                  <td class="text-end js-tax-amount"><?= number_format((float)($tr['amount'] ?? 0), 2) ?></td>
                  <td><button type="button" class="btn btn-sm btn-outline-danger so-tax-remove-row"><i class="fa-solid fa-xmark"></i></button></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div class="d-flex gap-2 mb-3">
            <button type="button" class="btn btn-sm btn-outline-brand so-tax-add-row"><i class="fa-solid fa-plus"></i> Add Row</button>
            <button type="button" id="calcTaxesBtn" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-percent"></i> Calculate Taxes</button>
          </div>
        </div>

        <div class="row g-3">
          <div class="col-lg-4">
            <div class="card p-3 h-100">
              <h6 class="mb-3"><i class="fa-solid fa-circle-info"></i> Additional Information</h6>
              <div class="mb-3">
                <label class="form-label">Place of Supply</label>
                <input type="text" name="place_of_supply" class="form-control" value="<?= e($order['place_of_supply'] ?? '') ?>">
              </div>
              <div class="row g-2">
                <div class="col-sm-8">
                  <label class="form-label">GST Category</label>
                  <select name="gst_category" class="form-select">
                    <option value="">— Select —</option>
                    <?php foreach ($gstCategories as $val => $label): ?>
                      <option value="<?= e($val) ?>" <?= $order['gst_category'] === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-sm-4">
                  <label class="form-label d-block">Reverse Charge?</label>
                  <div class="form-check form-switch mt-2">
                    <input type="checkbox" class="form-check-input" name="reverse_charge" value="1" <?= !empty($order['reverse_charge']) ? 'checked' : '' ?>>
                  </div>
                </div>
              </div>
              <div class="mt-3">
                <label class="form-label">Remarks (for tax)</label>
                <textarea name="tax_remarks" class="form-control" rows="2"><?= e($order['tax_remarks'] ?? '') ?></textarea>
              </div>
            </div>
          </div>
          <div class="col-lg-4">
            <div class="card p-3 h-100">
              <h6 class="mb-3"><i class="fa-solid fa-calculator"></i> Rounding and Adjustment</h6>
              <div class="row g-2 mb-2">
                <div class="col-sm-7">
                  <label class="form-label">Rounding Method</label>
                  <select name="rounding_method" id="roundingMethodSelect" class="form-select">
                    <option value="nearest" <?= $order['rounding_method'] === 'nearest' ? 'selected' : '' ?>>Nearest</option>
                    <option value="up" <?= $order['rounding_method'] === 'up' ? 'selected' : '' ?>>Up</option>
                    <option value="down" <?= $order['rounding_method'] === 'down' ? 'selected' : '' ?>>Down</option>
                  </select>
                </div>
                <div class="col-sm-5">
                  <label class="form-label">Precision</label>
                  <input type="number" step="0.01" min="0" name="rounding_precision" id="roundingPrecisionInput" class="form-control" value="<?= e($order['rounding_precision'] ?? '0.01') ?>">
                </div>
              </div>
              <div class="row g-2 mb-2">
                <div class="col-sm-6">
                  <label class="form-label">Additional Discount</label>
                  <input type="number" step="0.01" min="0" name="additional_discount" id="additionalDiscountInput" class="form-control" value="<?= e($order['additional_discount'] ?? 0) ?>">
                </div>
                <div class="col-sm-6">
                  <label class="form-label">Additional Charge</label>
                  <input type="number" step="0.01" min="0" name="additional_charge" id="additionalChargeInput" class="form-control" value="<?= e($order['additional_charge'] ?? 0) ?>">
                </div>
              </div>
              <div class="row g-2">
                <div class="col-sm-5">
                  <label class="form-label">Adjustment Type</label>
                  <select name="adjustment_type" id="adjustmentTypeSelect" class="form-select">
                    <option value="none" <?= $order['adjustment_type'] === 'none' ? 'selected' : '' ?>>None</option>
                    <option value="add" <?= $order['adjustment_type'] === 'add' ? 'selected' : '' ?>>Add</option>
                    <option value="subtract" <?= $order['adjustment_type'] === 'subtract' ? 'selected' : '' ?>>Subtract</option>
                  </select>
                </div>
                <div class="col-sm-7">
                  <label class="form-label">Adjustment Amount</label>
                  <input type="number" step="0.01" min="0" name="adjustment_amount" id="adjustmentAmountInput" class="form-control" value="<?= e($order['adjustment_amount'] ?? 0) ?>">
                </div>
              </div>
              <div class="mt-2">
                <label class="form-label">Adjustment Remarks</label>
                <input type="text" name="adjustment_remarks" class="form-control" value="<?= e($order['adjustment_remarks'] ?? '') ?>">
              </div>
            </div>
          </div>
          <div class="col-lg-4">
            <div class="card p-3 h-100">
              <h6 class="mb-3"><i class="fa-solid fa-chart-pie"></i> Tax Summary</h6>
              <div class="d-flex justify-content-between mb-1"><span class="text-muted">Net Amount</span><strong id="taxSummaryNet">0.00</strong></div>
              <div class="d-flex justify-content-between mb-1"><span class="text-muted">Total Charges</span><strong id="taxSummaryCharges">0.00</strong></div>
              <div class="d-flex justify-content-between mb-1"><span class="text-muted">Total Tax Amount</span><strong id="taxSummaryTax">0.00</strong></div>
              <hr>
              <div class="d-flex justify-content-between fs-5 mb-2"><span>Grand Total</span><strong id="taxSummaryGrandTotal">0.00</strong></div>
              <div class="p-2 rounded bg-light-subtle border d-flex justify-content-between">
                <span class="small text-muted">Effective Tax Rate</span><strong id="taxSummaryRate">0.00%</strong>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="page-actions mt-3">
      <button type="submit" class="btn btn-brand">Save Order</button>
      <a href="orders.php" class="btn btn-outline-secondary">Cancel</a>
    </div>
  </form>
</div>

<?php
$extra_js_inline = "
var productMeta = " . json_encode($productMeta) . ";
var priceListRates = " . json_encode($priceListRates) . ";
var addressesByCustomer = " . json_encode($addressesByCustomer) . ";
var selectedAddressId = " . json_encode((string)($order['customer_address_id'] ?? '')) . ";
var currentCustomerId = " . json_encode((string)($order['customer_id'] ?? '')) . ";

function rateFor(productId, priceListId) {
  if (priceListRates[priceListId] && priceListRates[priceListId][productId] !== undefined) {
    return priceListRates[priceListId][productId];
  }
  return productMeta[productId] ? productMeta[productId].rate : 0;
}

function populateAddresses(customerId, selectId) {
  var sel = document.getElementById('addressSelect');
  if (!sel) return;
  sel.innerHTML = '<option value=\"\">— Select address —</option>';
  var list = addressesByCustomer[customerId] || [];
  list.forEach(function (a) {
    var opt = document.createElement('option');
    opt.value = a.id;
    opt.textContent = a.text;
    if (selectId && String(a.id) === String(selectId)) opt.selected = true;
    sel.appendChild(opt);
  });
}

document.addEventListener('DOMContentLoaded', function () {
  var customerSelect = document.getElementById('customerSelect');
  if (customerSelect) {
    populateAddresses(customerSelect.value, selectedAddressId);
    customerSelect.addEventListener('change', function () { populateAddresses(customerSelect.value, null); });
  }

  var newAddressLink = document.getElementById('newAddressLink');
  if (newAddressLink) {
    newAddressLink.addEventListener('click', function (e) {
      e.preventDefault();
      var cid = customerSelect ? customerSelect.value : '';
      if (!cid) { alert('Select a customer first.'); return; }
      var returnTo = window.location.href.split('#')[0];
      window.location.href = '" . base_url('crm/address_form.php') . "?customer_id=' + cid + '&return_to=' + encodeURIComponent(returnTo);
    });
  }

  var wrap = document.querySelector('.so-line-items');
  if (!wrap) return;
  var tbody = wrap.querySelector('tbody');
  var priceListSelect = document.getElementById('priceListSelect');

  function recalc() {
    var total = 0, totalQty = 0;
    wrap.querySelectorAll('tr[data-row]').forEach(function (row) {
      var qty = parseFloat(row.querySelector('.js-qty')?.value || 0) || 0;
      var price = parseFloat(row.querySelector('.js-price')?.value || 0) || 0;
      var discount = parseFloat(row.querySelector('.js-discount')?.value || 0) || 0;
      var amount = qty * price * (1 - discount / 100);
      var amtEl = row.querySelector('.js-amount');
      if (amtEl) amtEl.textContent = amount.toFixed(2);
      total += amount;
      totalQty += qty;
    });
    document.getElementById('soTotal').textContent = total.toFixed(2);
    document.getElementById('soTotalQty').textContent = totalQty;
    if (window.soRecalcTaxes) window.soRecalcTaxes();
  }

  function applyProductDefaults(row) {
    var productSelect = row.querySelector('.js-product');
    var pid = productSelect.value;
    if (!pid || !productMeta[pid]) return;
    var meta = productMeta[pid];
    var uomSelect = row.querySelector('.js-uom');
    if (uomSelect) {
      var found = false;
      for (var i = 0; i < uomSelect.options.length; i++) {
        if (uomSelect.options[i].value === meta.unit) { uomSelect.selectedIndex = i; found = true; break; }
      }
      if (!found) uomSelect.value = meta.unit;
    }
    var priceInput = row.querySelector('.js-price');
    if (priceInput) priceInput.value = rateFor(pid, priceListSelect ? priceListSelect.value : null);
  }

  wrap.addEventListener('input', recalc);

  wrap.addEventListener('change', function (e) {
    if (e.target.classList.contains('js-product')) {
      applyProductDefaults(e.target.closest('tr[data-row]'));
    }
    recalc();
  });

  if (priceListSelect) {
    priceListSelect.addEventListener('change', function () {
      wrap.querySelectorAll('tr[data-row]').forEach(function (row) {
        var pid = row.querySelector('.js-product').value;
        if (pid) {
          row.querySelector('.js-price').value = rateFor(pid, priceListSelect.value);
        }
      });
      recalc();
    });
  }

  wrap.addEventListener('click', function (e) {
    var addBtn = e.target.closest('.js-add-row');
    if (addBtn && tbody) {
      var rows = tbody.querySelectorAll('tr[data-row]');
      var clone = rows[rows.length - 1].cloneNode(true);
      clone.querySelectorAll('input').forEach(function (inp) {
        if (inp.classList.contains('js-qty')) inp.value = 1;
        else if (inp.classList.contains('js-discount')) inp.value = 0;
        else inp.value = '';
      });
      clone.querySelectorAll('select').forEach(function (sel) { sel.selectedIndex = 0; });
      var amt = clone.querySelector('.js-amount');
      if (amt) amt.textContent = '0.00';
      tbody.appendChild(clone);
      recalc();
      return;
    }
    var rmBtn = e.target.closest('.js-remove-row');
    if (rmBtn) {
      var rows2 = tbody.querySelectorAll('tr[data-row]');
      if (rows2.length > 1) {
        rmBtn.closest('tr[data-row]').remove();
        recalc();
      }
    }
  });

  recalc();
});

var accountMeta = " . json_encode($accountMeta) . ";
var taxTemplateRows = " . json_encode($taxTemplateRows) . ";

function soTaxRowAmount(basedOn, rate, netAmount) {
  return basedOn === 'net_amount' ? (netAmount * rate / 100) : rate;
}

document.addEventListener('DOMContentLoaded', function () {
  var wrap = document.querySelector('.so-tax-rows');
  if (!wrap) return;
  var tbody = wrap.querySelector('tbody');

  function netAmount() {
    var el = document.getElementById('soTotal');
    return el ? (parseFloat(el.textContent) || 0) : 0;
  }

  function soRound(amount, precision, method) {
    precision = parseFloat(precision) || 0;
    if (precision <= 0) return Math.round(amount * 100) / 100;
    var units = amount / precision;
    var rounded = method === 'up' ? Math.ceil(units) : (method === 'down' ? Math.floor(units) : Math.round(units));
    return Math.round(rounded * precision * 100) / 100;
  }

  window.soRecalcTaxes = function () {
    var net = netAmount();
    var totalCharges = 0, totalTax = 0;
    wrap.querySelectorAll('tr[data-row]').forEach(function (row) {
      var basedOn = row.querySelector('.js-tax-basedon').value;
      var rate = parseFloat(row.querySelector('.js-tax-rate').value || 0) || 0;
      var accountId = row.querySelector('.js-tax-account').value;
      var amount = soTaxRowAmount(basedOn, rate, net);
      row.querySelector('.js-tax-amount').textContent = amount.toFixed(2);
      var meta = accountMeta[accountId];
      if (meta && meta.type === 'tax') totalTax += amount;
      else totalCharges += amount;
    });

    var additionalDiscount = parseFloat(document.getElementById('additionalDiscountInput')?.value || 0) || 0;
    var additionalCharge = parseFloat(document.getElementById('additionalChargeInput')?.value || 0) || 0;
    var adjustmentType = document.getElementById('adjustmentTypeSelect')?.value || 'none';
    var adjustmentAmount = parseFloat(document.getElementById('adjustmentAmountInput')?.value || 0) || 0;
    var roundingMethod = document.getElementById('roundingMethodSelect')?.value || 'nearest';
    var roundingPrecision = document.getElementById('roundingPrecisionInput')?.value || '0.01';

    var grand = net + totalCharges + totalTax + additionalCharge - additionalDiscount;
    if (adjustmentType === 'add') grand += adjustmentAmount;
    else if (adjustmentType === 'subtract') grand -= adjustmentAmount;
    grand = soRound(grand, roundingPrecision, roundingMethod);

    document.getElementById('taxSummaryNet').textContent = net.toFixed(2);
    document.getElementById('taxSummaryCharges').textContent = totalCharges.toFixed(2);
    document.getElementById('taxSummaryTax').textContent = totalTax.toFixed(2);
    document.getElementById('taxSummaryGrandTotal').textContent = grand.toFixed(2);
    document.getElementById('taxSummaryRate').textContent = (net > 0 ? (totalTax / net * 100) : 0).toFixed(2) + '%';
  };

  wrap.addEventListener('input', window.soRecalcTaxes);
  wrap.addEventListener('change', window.soRecalcTaxes);
  ['additionalDiscountInput', 'additionalChargeInput', 'adjustmentTypeSelect', 'adjustmentAmountInput', 'roundingMethodSelect', 'roundingPrecisionInput'].forEach(function (id) {
    var el = document.getElementById(id);
    if (el) { el.addEventListener('input', window.soRecalcTaxes); el.addEventListener('change', window.soRecalcTaxes); }
  });

  var calcBtn = document.getElementById('calcTaxesBtn');
  if (calcBtn) calcBtn.addEventListener('click', window.soRecalcTaxes);

  wrap.addEventListener('click', function (e) {
    if (e.target.closest('.so-tax-add-row')) {
      var rows = tbody.querySelectorAll('tr[data-row]');
      if (rows.length) {
        var clone = rows[rows.length - 1].cloneNode(true);
        clone.querySelectorAll('input').forEach(function (inp) { inp.value = ''; });
        clone.querySelectorAll('select').forEach(function (sel) { sel.selectedIndex = 0; });
        clone.querySelector('.js-tax-amount').textContent = '0.00';
        tbody.appendChild(clone);
        window.soRecalcTaxes();
      }
      return;
    }
    var rm = e.target.closest('.so-tax-remove-row');
    if (rm) {
      rm.closest('tr[data-row]').remove();
      window.soRecalcTaxes();
    }
  });

  var applyBtn = document.getElementById('applyTemplateBtn');
  if (applyBtn) {
    applyBtn.addEventListener('click', function () {
      var ttId = document.getElementById('taxTemplateSelect').value;
      document.getElementById('taxTemplateIdInput').value = ttId;
      var rows = taxTemplateRows[ttId] || [];
      tbody.innerHTML = '';
      if (!rows.length) { window.soRecalcTaxes(); return; }
      rows.forEach(function (r) {
        var tr = document.createElement('tr');
        tr.setAttribute('data-row', '');
        tr.innerHTML = '<td><select class=\"form-select form-select-sm\" name=\"row_type[]\"><option value=\"on_item\">On Item</option><option value=\"on_order\">On Order</option></select></td>' +
          '<td><select class=\"form-select form-select-sm js-tax-account\" name=\"row_account_head_id[]\"><option value=\"\">— None —</option></select></td>' +
          '<td><input type=\"text\" class=\"form-control form-control-sm\" name=\"row_description[]\"></td>' +
          '<td><input type=\"number\" step=\"0.01\" class=\"form-control form-control-sm js-tax-rate\" name=\"row_rate_or_amount[]\"></td>' +
          '<td><select class=\"form-select form-select-sm js-tax-basedon\" name=\"row_based_on[]\"><option value=\"net_amount\">Net Amount</option><option value=\"actual_amount\">Actual Amount</option></select></td>' +
          '<td class=\"text-end js-tax-amount\">0.00</td>' +
          '<td><button type=\"button\" class=\"btn btn-sm btn-outline-danger so-tax-remove-row\"><i class=\"fa-solid fa-xmark\"></i></button></td>';
        var accountSelect = tr.querySelector('.js-tax-account');
        Object.keys(accountMeta).forEach(function (accId) {
          var opt = document.createElement('option');
          opt.value = accId;
          opt.textContent = accountMeta[accId].name;
          if (r.account_head_id && String(r.account_head_id) === String(accId)) opt.selected = true;
          accountSelect.appendChild(opt);
        });
        tr.querySelector('select[name=\"row_type[]\"]').value = r.type;
        tr.querySelector('.js-tax-basedon').value = r.based_on;
        tr.querySelector('input[name=\"row_description[]\"]').value = r.description || '';
        tr.querySelector('.js-tax-rate').value = r.rate_or_amount;
        tbody.appendChild(tr);
      });
      window.soRecalcTaxes();
    });
  }

  window.soRecalcTaxes();
});
";
require __DIR__ . '/../includes/footer.php';
?>
