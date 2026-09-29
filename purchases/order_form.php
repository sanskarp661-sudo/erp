<?php
require_once __DIR__ . '/../includes/auth.php';
require_module_edit('procurement');

$id = (int)input('id');
$defaultPriceListId = db()->query("SELECT id FROM price_lists WHERE name = 'Standard Buying' AND status = 'active' LIMIT 1")->fetchColumn()
    ?: db()->query("SELECT id FROM price_lists WHERE is_default = 1 ORDER BY id LIMIT 1")->fetchColumn();

$order = [
    'id' => 0, 'po_no' => '', 'vendor_id' => '', 'vendor_contact' => '', 'vendor_address' => '',
    'order_date' => today(), 'required_by' => '', 'purchase_type' => '', 'buyer_id' => current_user()['id'] ?? '',
    'price_list_id' => $defaultPriceListId ?: '', 'currency' => setting('currency_code', 'INR'),
    'vendor_gstin' => '', 'vendor_quote_no' => '', 'material_request_no' => '', 'project' => '',
    'status' => 'pending', 'notes' => '',
    'tax_template_id' => '', 'place_of_supply' => '', 'gst_category' => '', 'reverse_charge' => 0, 'tax_remarks' => '',
    'rounding_method' => 'nearest', 'rounding_precision' => '0.01', 'additional_discount' => 0, 'additional_charge' => 0,
    'adjustment_type' => 'none', 'adjustment_amount' => 0, 'adjustment_remarks' => '',
    'ship_to_warehouse_id' => default_warehouse_id(), 'expected_delivery_date' => '', 'expected_dispatch_date' => '',
    'delivery_priority' => 'normal', 'delivery_terms' => '', 'mode_of_transport' => '', 'shipping_partner_id' => '',
    'freight_terms' => '', 'tracking_no' => '', 'delivery_remarks' => '', 'allow_partial_receipt' => 1, 'inspection_required' => 0,
    'payment_terms_template_id' => '', 'payment_terms' => '', 'payment_method' => '', 'payment_due_date_basis' => 'against_invoice',
    'advance_percentage' => 0, 'vendor_bank_details' => '', 'payment_instructions' => '',
    'order_type' => 'Standard', 'cost_center' => '', 'business_unit' => '', 'approver_id' => '',
    'terms_conditions' => '', 'remarks_internal' => '', 'tags' => '',
];
$items = [];
$taxRows = [];
$paymentSchedule = [];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM purchase_orders WHERE id = ?');
    $stmt->execute([$id]);
    $order = $stmt->fetch();
    if (!$order) {
        flash('danger', 'Purchase order not found.');
        redirect('/purchases/orders.php');
    }
    if ($order['status'] !== 'pending') {
        flash('danger', 'Only orders still in "pending" status can be edited.');
        redirect('/purchases/order_view.php?id=' . $id);
    }
    $stmt = db()->prepare('SELECT * FROM purchase_order_items WHERE po_id = ? ORDER BY id');
    $stmt->execute([$id]);
    $items = $stmt->fetchAll();
    $stmt = db()->prepare('SELECT * FROM purchase_order_taxes WHERE po_id = ? ORDER BY sort_order, id');
    $stmt->execute([$id]);
    $taxRows = $stmt->fetchAll();
    $stmt = db()->prepare('SELECT * FROM purchase_order_payment_schedule WHERE po_id = ? ORDER BY sort_order, id');
    $stmt->execute([$id]);
    $paymentSchedule = $stmt->fetchAll();
}

$error = '';
$activeTab = in_array(input('tab'), ['items', 'taxes', 'shipping', 'payment', 'more'], true) ? input('tab') : 'details';

/** Rounds $amount to the nearest multiple of $precision, per $method ('nearest'|'up'|'down'). */
function po_round(float $amount, float $precision, string $method): float
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
    $vendorId = (int)input('vendor_id');
    $vendorContact = input('vendor_contact') ?: null;
    $vendorAddress = input('vendor_address') ?: null;
    $orderDate = input('order_date') ?: today();
    $requiredBy = input('required_by') ?: null;
    $purchaseType = input('purchase_type') ?: null;
    $buyerId = (int)input('buyer_id') ?: null;
    $priceListId = (int)input('price_list_id') ?: null;
    $currency = strtoupper(input('currency')) ?: 'INR';
    $vendorGstin = strtoupper(input('vendor_gstin')) ?: null;
    $vendorQuoteNo = input('vendor_quote_no') ?: null;
    $materialRequestNo = input('material_request_no') ?: null;
    $project = input('project') ?: null;
    $notes = input('notes');

    $taxTemplateId = (int)input('tax_template_id') ?: null;
    $placeOfSupply = input('place_of_supply') ?: null;
    $gstCategory = in_array(input('gst_category'), ['registered_business', 'unregistered_business', 'composition', 'overseas', 'sez'], true) ? input('gst_category') : null;
    $reverseCharge = input('reverse_charge') ? 1 : 0;
    $taxRemarks = input('tax_remarks') ?: null;
    $roundingMethod = in_array(input('rounding_method'), ['nearest', 'up', 'down'], true) ? input('rounding_method') : 'nearest';
    $roundingPrecision = (float)input('rounding_precision') ?: 0.01;
    $additionalDiscount = max(0, (float)input('additional_discount'));
    $additionalCharge = max(0, (float)input('additional_charge'));
    $adjustmentType = in_array(input('adjustment_type'), ['none', 'add', 'subtract'], true) ? input('adjustment_type') : 'none';
    $adjustmentAmount = max(0, (float)input('adjustment_amount'));
    $adjustmentRemarks = input('adjustment_remarks') ?: null;

    $shipToWarehouseId = (int)input('ship_to_warehouse_id') ?: null;
    $expectedDeliveryDate = input('expected_delivery_date') ?: null;
    $expectedDispatchDate = input('expected_dispatch_date') ?: null;
    $deliveryPriority = in_array(input('delivery_priority'), ['low', 'normal', 'high', 'urgent'], true) ? input('delivery_priority') : 'normal';
    $deliveryTerms = input('delivery_terms') ?: null;
    $modeOfTransport = input('mode_of_transport') ?: null;
    $shippingPartnerId = (int)input('shipping_partner_id') ?: null;
    $freightTerms = input('freight_terms') ?: null;
    $trackingNo = input('tracking_no') ?: null;
    $deliveryRemarks = input('delivery_remarks') ?: null;
    $allowPartialReceipt = input('allow_partial_receipt') ? 1 : 0;
    $inspectionRequired = input('inspection_required') ? 1 : 0;

    $paymentTermsTemplateId = (int)input('payment_terms_template_id') ?: null;
    $paymentTerms = input('payment_terms') ?: null;
    $paymentMethod = input('payment_method') ?: null;
    $paymentDueDateBasis = in_array(input('payment_due_date_basis'), ['against_receipt', 'against_order_date', 'against_invoice'], true) ? input('payment_due_date_basis') : 'against_invoice';
    $advancePercentage = max(0, min(100, (float)input('advance_percentage')));
    $vendorBankDetails = input('vendor_bank_details') ?: null;
    $paymentInstructions = input('payment_instructions') ?: null;

    $orderType = in_array(input('order_type'), ['Standard', 'Subcontracting', 'Blanket', 'Service', 'Import'], true) ? input('order_type') : 'Standard';
    $costCenter = input('cost_center') ?: null;
    $businessUnit = input('business_unit') ?: null;
    $approverId = (int)input('approver_id') ?: null;
    $termsConditions = input('terms_conditions') ?: null;
    $remarksInternal = input('remarks_internal') ?: null;
    $tags = input('tags') ?: null;

    $productIds = $_POST['product_id'] ?? [];
    $descriptions = $_POST['description'] ?? [];
    $warehouseIds = $_POST['item_warehouse_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $lineUoms = $_POST['uom'] ?? [];
    $rates = $_POST['rate'] ?? [];
    $discounts = $_POST['discount_percent'] ?? [];
    $lineRequiredBys = $_POST['item_required_by'] ?? [];

    $lineItems = [];
    $netAmount = 0;
    foreach ($productIds as $i => $pid) {
        $pid = (int)$pid;
        $qty = (int)($quantities[$i] ?? 0);
        $rate = max(0, (float)($rates[$i] ?? 0));
        $discount = max(0, min(100, (float)($discounts[$i] ?? 0)));
        if ($pid > 0 && $qty > 0) {
            // unit_cost is the net cost per unit (after line discount) —
            // GRNs, returns and purchase invoices copy it straight from here.
            $unitCost = round($rate * (1 - $discount / 100), 2);
            $subtotal = round($qty * $rate * (1 - $discount / 100), 2);
            $uom = trim($lineUoms[$i] ?? '') ?: 'pcs';
            $lineItems[] = [
                'product_id' => $pid,
                'description' => trim($descriptions[$i] ?? '') ?: null,
                'warehouse_id' => (int)($warehouseIds[$i] ?? 0) ?: $shipToWarehouseId,
                'quantity' => $qty,
                'uom' => $uom,
                'uom_conversion_factor' => uom_conversion_factor($pid, $uom),
                'rate' => $rate,
                'discount_percent' => $discount,
                'unit_cost' => $unitCost,
                'subtotal' => $subtotal,
                'required_by' => ($lineRequiredBys[$i] ?? '') ?: $requiredBy,
            ];
            $netAmount += $subtotal;
        }
    }

    // Tax/charge rows: amounts are always computed server-side from the
    // server-computed net amount — the client's live preview is never trusted.
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
    $grandTotal = po_round($grandTotal, $roundingPrecision, $roundingMethod);

    $scheduleDueOns = $_POST['sched_due_on'] ?? [];
    $scheduleDaysFroms = $_POST['sched_days_from'] ?? [];
    $schedulePaymentTypes = $_POST['sched_payment_type'] ?? [];
    $schedulePercentages = $_POST['sched_percentage'] ?? [];
    $scheduleRemarksArr = $_POST['sched_remarks'] ?? [];
    $paymentScheduleToSave = [];
    $schedulePctTotal = 0;
    $sort = 0;
    foreach ($schedulePercentages as $i => $pct) {
        $pct = max(0, min(100, (float)$pct));
        $remarks = trim($scheduleRemarksArr[$i] ?? '');
        if ($pct == 0 && $remarks === '') {
            continue;
        }
        $dueOn = in_array($scheduleDueOns[$i] ?? '', ['order_date', 'on_receipt', 'on_invoice', 'fixed_days'], true) ? $scheduleDueOns[$i] : 'order_date';
        $paymentType = in_array($schedulePaymentTypes[$i] ?? '', ['advance', 'part_payment', 'balance'], true) ? $schedulePaymentTypes[$i] : 'balance';
        $paymentScheduleToSave[] = [
            'due_on' => $dueOn, 'days_from' => max(0, (int)($scheduleDaysFroms[$i] ?? 0)), 'payment_type' => $paymentType,
            'percentage' => $pct, 'amount' => round($grandTotal * $pct / 100, 2), 'remarks' => $remarks ?: null, 'sort_order' => $sort++,
        ];
        $schedulePctTotal += $pct;
    }

    if (!$vendorId) {
        $error = 'Please select a vendor.';
        $activeTab = 'details';
    } elseif (!$lineItems) {
        $error = 'Please add at least one valid line item.';
        $activeTab = 'items';
    } elseif ($paymentScheduleToSave && abs($schedulePctTotal - 100) > 0.01) {
        $error = 'Payment schedule percentages must add up to 100% (currently ' . rtrim(rtrim(number_format($schedulePctTotal, 2), '0'), '.') . '%).';
        $activeTab = 'payment';
    } else {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $header = [
                'vendor_id' => $vendorId, 'vendor_contact' => $vendorContact, 'vendor_address' => $vendorAddress,
                'order_date' => $orderDate, 'required_by' => $requiredBy, 'purchase_type' => $purchaseType, 'buyer_id' => $buyerId,
                'price_list_id' => $priceListId, 'currency' => $currency, 'vendor_gstin' => $vendorGstin,
                'vendor_quote_no' => $vendorQuoteNo, 'material_request_no' => $materialRequestNo, 'project' => $project,
                'notes' => $notes, 'total_amount' => $grandTotal, 'net_amount' => $netAmount,
                'tax_template_id' => $taxTemplateId, 'place_of_supply' => $placeOfSupply, 'gst_category' => $gstCategory,
                'reverse_charge' => $reverseCharge, 'tax_remarks' => $taxRemarks, 'rounding_method' => $roundingMethod,
                'rounding_precision' => $roundingPrecision, 'additional_discount' => $additionalDiscount,
                'additional_charge' => $additionalCharge, 'adjustment_type' => $adjustmentType,
                'adjustment_amount' => $adjustmentAmount, 'adjustment_remarks' => $adjustmentRemarks,
                'ship_to_warehouse_id' => $shipToWarehouseId, 'expected_delivery_date' => $expectedDeliveryDate,
                'expected_dispatch_date' => $expectedDispatchDate, 'delivery_priority' => $deliveryPriority,
                'delivery_terms' => $deliveryTerms, 'mode_of_transport' => $modeOfTransport,
                'shipping_partner_id' => $shippingPartnerId, 'freight_terms' => $freightTerms, 'tracking_no' => $trackingNo,
                'delivery_remarks' => $deliveryRemarks, 'allow_partial_receipt' => $allowPartialReceipt,
                'inspection_required' => $inspectionRequired,
                'payment_terms_template_id' => $paymentTermsTemplateId, 'payment_terms' => $paymentTerms,
                'payment_method' => $paymentMethod, 'payment_due_date_basis' => $paymentDueDateBasis,
                'advance_percentage' => $advancePercentage, 'vendor_bank_details' => $vendorBankDetails,
                'payment_instructions' => $paymentInstructions,
                'order_type' => $orderType, 'cost_center' => $costCenter, 'business_unit' => $businessUnit,
                'approver_id' => $approverId, 'terms_conditions' => $termsConditions,
                'remarks_internal' => $remarksInternal, 'tags' => $tags,
            ];
            $headerColNames = array_keys($header);
            $headerVals = array_values($header);
            if ($id) {
                $setClause = implode(', ', array_map(fn($c) => "$c=?", $headerColNames));
                $pdo->prepare("UPDATE purchase_orders SET $setClause WHERE id=?")->execute([...$headerVals, $id]);
                $pdo->prepare('DELETE FROM purchase_order_items WHERE po_id=?')->execute([$id]);
                $pdo->prepare('DELETE FROM purchase_order_taxes WHERE po_id=?')->execute([$id]);
                $pdo->prepare('DELETE FROM purchase_order_payment_schedule WHERE po_id=?')->execute([$id]);
                $poId = $id;
            } else {
                $poNo = next_code('PO', 'purchase_orders', 'po_no');
                $colList = implode(', ', ['po_no', ...$headerColNames, 'status', 'created_by']);
                $placeholders = implode(',', array_fill(0, count($headerVals) + 3, '?'));
                $pdo->prepare("INSERT INTO purchase_orders ($colList) VALUES ($placeholders)")
                    ->execute([$poNo, ...$headerVals, 'pending', current_user()['id']]);
                $poId = (int)$pdo->lastInsertId();
            }
            $itemStmt = $pdo->prepare('INSERT INTO purchase_order_items (po_id, product_id, description, warehouse_id, quantity, uom, uom_conversion_factor, rate, discount_percent, unit_cost, subtotal, required_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
            foreach ($lineItems as $li) {
                $itemStmt->execute([$poId, $li['product_id'], $li['description'], $li['warehouse_id'], $li['quantity'], $li['uom'], $li['uom_conversion_factor'], $li['rate'], $li['discount_percent'], $li['unit_cost'], $li['subtotal'], $li['required_by']]);
            }
            $taxStmt = $pdo->prepare('INSERT INTO purchase_order_taxes (po_id, type, account_head_id, description, based_on, rate_or_amount, amount, sort_order) VALUES (?,?,?,?,?,?,?,?)');
            foreach ($taxRowsToSave as $tr) {
                $taxStmt->execute([$poId, $tr['type'], $tr['account_head_id'], $tr['description'], $tr['based_on'], $tr['rate_or_amount'], $tr['amount'], $tr['sort_order']]);
            }
            $scheduleStmt = $pdo->prepare('INSERT INTO purchase_order_payment_schedule (po_id, due_on, days_from, payment_type, percentage, amount, remarks, sort_order) VALUES (?,?,?,?,?,?,?,?)');
            foreach ($paymentScheduleToSave as $ps) {
                $scheduleStmt->execute([$poId, $ps['due_on'], $ps['days_from'], $ps['payment_type'], $ps['percentage'], $ps['amount'], $ps['remarks'], $ps['sort_order']]);
            }
            $pdo->commit();
            log_activity('purchase_order', $poId, $id ? 'edited' : 'created');
            flash('success', $id ? 'Purchase order updated.' : 'Purchase order created.');
            redirect('/purchases/order_view.php?id=' . $poId);
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Could not save purchase order.' . (defined('APP_DEBUG') && APP_DEBUG ? ' DEBUG: ' . $e->getMessage() : '');
        }
    }

    $order = array_merge(['id' => $id, 'po_no' => $order['po_no'] ?? '', 'status' => $order['status'] ?? 'pending'], [
        'vendor_id' => $vendorId, 'vendor_contact' => $vendorContact, 'vendor_address' => $vendorAddress,
        'order_date' => $orderDate, 'required_by' => $requiredBy, 'purchase_type' => $purchaseType, 'buyer_id' => $buyerId,
        'price_list_id' => $priceListId, 'currency' => $currency, 'vendor_gstin' => $vendorGstin,
        'vendor_quote_no' => $vendorQuoteNo, 'material_request_no' => $materialRequestNo, 'project' => $project, 'notes' => $notes,
        'tax_template_id' => $taxTemplateId, 'place_of_supply' => $placeOfSupply, 'gst_category' => $gstCategory,
        'reverse_charge' => $reverseCharge, 'tax_remarks' => $taxRemarks, 'rounding_method' => $roundingMethod,
        'rounding_precision' => $roundingPrecision, 'additional_discount' => $additionalDiscount, 'additional_charge' => $additionalCharge,
        'adjustment_type' => $adjustmentType, 'adjustment_amount' => $adjustmentAmount, 'adjustment_remarks' => $adjustmentRemarks,
        'ship_to_warehouse_id' => $shipToWarehouseId, 'expected_delivery_date' => $expectedDeliveryDate,
        'expected_dispatch_date' => $expectedDispatchDate, 'delivery_priority' => $deliveryPriority, 'delivery_terms' => $deliveryTerms,
        'mode_of_transport' => $modeOfTransport, 'shipping_partner_id' => $shippingPartnerId, 'freight_terms' => $freightTerms,
        'tracking_no' => $trackingNo, 'delivery_remarks' => $deliveryRemarks, 'allow_partial_receipt' => $allowPartialReceipt,
        'inspection_required' => $inspectionRequired, 'payment_terms_template_id' => $paymentTermsTemplateId,
        'payment_terms' => $paymentTerms, 'payment_method' => $paymentMethod, 'payment_due_date_basis' => $paymentDueDateBasis,
        'advance_percentage' => $advancePercentage, 'vendor_bank_details' => $vendorBankDetails,
        'payment_instructions' => $paymentInstructions, 'order_type' => $orderType, 'cost_center' => $costCenter,
        'business_unit' => $businessUnit, 'approver_id' => $approverId, 'terms_conditions' => $termsConditions,
        'remarks_internal' => $remarksInternal, 'tags' => $tags,
    ]);
    $items = $lineItems;
    $taxRows = $taxRowsToSave;
    $paymentSchedule = $paymentScheduleToSave;
}

$vendors = db()->query('SELECT id, name, company, email, phone, address FROM vendors ORDER BY name')->fetchAll();
$vendorMeta = [];
foreach ($vendors as $v) {
    $vendorMeta[(int)$v['id']] = ['address' => $v['address'] ?? '', 'phone' => $v['phone'] ?? '', 'email' => $v['email'] ?? ''];
}
$products = db()->query("SELECT id, sku, name, cost_price, unit FROM products WHERE status='active' ORDER BY name")->fetchAll();
$warehouses = leaf_warehouses();
$priceLists = db()->query("SELECT id, name, currency FROM price_lists WHERE status='active' ORDER BY name")->fetchAll();
$users = db()->query("SELECT id, name FROM users WHERE status='active' ORDER BY name")->fetchAll();
$shippingPartners = db()->query("SELECT id, name FROM shipping_partners WHERE status='active' ORDER BY name")->fetchAll();

$priceListRates = [];
foreach (db()->query('SELECT price_list_id, product_id, rate FROM price_list_items') as $r) {
    $priceListRates[(int)$r['price_list_id']][(int)$r['product_id']] = (float)$r['rate'];
}

$productUomsByProduct = [];
foreach (db()->query('SELECT product_id, uom, conversion_factor FROM product_uoms ORDER BY sort_order, id') as $r) {
    $productUomsByProduct[(int)$r['product_id']][] = ['uom' => $r['uom'], 'factor' => (float)$r['conversion_factor']];
}
$productMeta = [];
foreach ($products as $p) {
    $uomMap = [$p['unit'] => 1.0];
    foreach ($productUomsByProduct[(int)$p['id']] ?? [] as $u) {
        $uomMap[$u['uom']] = $u['factor'];
    }
    $productMeta[(int)$p['id']] = ['sku' => $p['sku'], 'name' => $p['name'], 'unit' => $p['unit'], 'rate' => (float)$p['cost_price'], 'uoms' => $uomMap];
}

$warehouseNames = [];
foreach ($warehouses as $w) {
    $warehouseNames[(int)$w['id']] = $w['name'];
}

$ledgerAccounts = db()->query("SELECT id, name, account_type FROM ledger_accounts WHERE status='active'" . ledger_heads_filter() . " ORDER BY account_type, name")->fetchAll();
$accountMeta = [];
foreach ($ledgerAccounts as $a) {
    $accountMeta[(int)$a['id']] = ['name' => $a['name'], 'type' => $a['account_type']];
}

$taxTemplates = db()->query("SELECT id, name FROM tax_templates WHERE status='active' ORDER BY name")->fetchAll();
$taxTemplateRows = [];
foreach (db()->query('SELECT tax_template_id, type, account_head_id, description, based_on, rate_or_amount FROM tax_template_items ORDER BY tax_template_id, sort_order, id') as $r) {
    $taxTemplateRows[(int)$r['tax_template_id']][] = [
        'type' => $r['type'], 'account_head_id' => $r['account_head_id'] ? (int)$r['account_head_id'] : null,
        'description' => $r['description'], 'based_on' => $r['based_on'], 'rate_or_amount' => (float)$r['rate_or_amount'],
    ];
}

$paymentTermsTemplates = db()->query("SELECT id, name FROM payment_terms_templates WHERE status='active' ORDER BY name")->fetchAll();
$paymentTermsTemplateRows = [];
$dueOnFromSales = ['order_date' => 'order_date', 'on_delivery' => 'on_receipt', 'fixed_days' => 'fixed_days'];
foreach (db()->query('SELECT template_id, due_on, days_from, payment_type, percentage, remarks FROM payment_terms_template_items ORDER BY template_id, sort_order, id') as $r) {
    $paymentTermsTemplateRows[(int)$r['template_id']][] = [
        'due_on' => $dueOnFromSales[$r['due_on']] ?? 'order_date', 'days_from' => (int)$r['days_from'],
        'payment_type' => $r['payment_type'], 'percentage' => (float)$r['percentage'], 'remarks' => $r['remarks'],
    ];
}

$purchaseTypes = ['Raw Material', 'Consumables', 'Capital Goods', 'Trading Goods', 'Services', 'Subcontracting'];
$gstCategories = ['registered_business' => 'Registered Business', 'unregistered_business' => 'Unregistered Business', 'composition' => 'Composition Dealer', 'overseas' => 'Overseas / Import', 'sez' => 'SEZ'];
$dueOnOptions = ['order_date' => 'Order Date', 'on_receipt' => 'Goods Receipt', 'on_invoice' => 'Invoice Date', 'fixed_days' => 'Fixed Days'];
$paymentTypeOptions = ['advance' => 'Advance', 'part_payment' => 'Part Payment', 'balance' => 'Balance'];
$statusBadge = ['pending' => 'secondary', 'ordered' => 'info', 'received' => 'success', 'cancelled' => 'danger'];

$page_title = $id ? 'Edit Purchase Order' : 'New Purchase Order';
require __DIR__ . '/../includes/header.php';
?>
<div class="card p-4">
  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0"><?= $id ? e($order['po_no']) : 'New Purchase Order' ?> <span class="badge text-bg-<?= $statusBadge[$order['status']] ?? 'secondary' ?> badge-status"><?= e($order['status']) ?></span></h5>
  </div>

  <ul class="nav nav-tabs mb-3">
    <li class="nav-item"><button class="nav-link <?= $activeTab === 'details' ? 'active' : '' ?>" id="tab-details" data-bs-toggle="tab" data-bs-target="#pane-details" type="button">Details</button></li>
    <li class="nav-item"><button class="nav-link <?= $activeTab === 'items' ? 'active' : '' ?>" id="tab-items" data-bs-toggle="tab" data-bs-target="#pane-items" type="button">Items</button></li>
    <li class="nav-item"><button class="nav-link <?= $activeTab === 'taxes' ? 'active' : '' ?>" id="tab-taxes" data-bs-toggle="tab" data-bs-target="#pane-taxes" type="button">Taxes &amp; Charges</button></li>
    <li class="nav-item"><button class="nav-link <?= $activeTab === 'shipping' ? 'active' : '' ?>" id="tab-shipping" data-bs-toggle="tab" data-bs-target="#pane-shipping" type="button">Shipping &amp; Delivery</button></li>
    <li class="nav-item"><button class="nav-link <?= $activeTab === 'payment' ? 'active' : '' ?>" id="tab-payment" data-bs-toggle="tab" data-bs-target="#pane-payment" type="button">Payment Terms</button></li>
    <li class="nav-item"><button class="nav-link <?= $activeTab === 'more' ? 'active' : '' ?>" id="tab-more" data-bs-toggle="tab" data-bs-target="#pane-more" type="button">More Info</button></li>
  </ul>

  <form method="post" id="poForm">
    <?= csrf_field() ?>
    <div class="tab-content">
      <div class="tab-pane fade <?= $activeTab === 'details' ? 'show active' : '' ?>" id="pane-details">
        <h6 class="text-muted mb-3"><i class="fa-solid fa-building"></i> Vendor &amp; Order Information</h6>
        <div class="row g-3 mb-3">
          <div class="col-sm-3">
            <label class="form-label">Vendor <span class="text-danger">*</span></label>
            <select name="vendor_id" id="vendorSelect" class="form-select" required>
              <option value="">— Select vendor —</option>
              <?php foreach ($vendors as $v): ?>
                <option value="<?= (int)$v['id'] ?>" <?= (string)$order['vendor_id'] === (string)$v['id'] ? 'selected' : '' ?>><?= e($v['name']) ?><?= $v['company'] ? ' (' . e($v['company']) . ')' : '' ?></option>
              <?php endforeach; ?>
            </select>
            <a href="<?= base_url('purchases/vendor_form.php') ?>" class="small">+ New Vendor</a>
          </div>
          <div class="col-sm-3">
            <label class="form-label">Vendor Contact</label>
            <input type="text" name="vendor_contact" id="vendorContactInput" class="form-control" value="<?= e($order['vendor_contact'] ?? '') ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">PO Date <span class="text-danger">*</span></label>
            <input type="date" name="order_date" class="form-control" value="<?= e($order['order_date']) ?>" required>
          </div>
          <div class="col-sm-3">
            <label class="form-label">Purchase Order No.</label>
            <input type="text" class="form-control" value="<?= $id ? e($order['po_no']) : 'Auto-generated' ?>" disabled>
          </div>

          <div class="col-sm-3">
            <label class="form-label">Vendor Address</label>
            <input type="text" name="vendor_address" id="vendorAddressInput" class="form-control" value="<?= e($order['vendor_address'] ?? '') ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Required By</label>
            <input type="date" name="required_by" id="requiredByInput" class="form-control" value="<?= e($order['required_by'] ?? '') ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Purchase Type</label>
            <select name="purchase_type" class="form-select">
              <option value="">— Select purchase type —</option>
              <?php foreach ($purchaseTypes as $pt): ?>
                <option value="<?= e($pt) ?>" <?= ($order['purchase_type'] ?? '') === $pt ? 'selected' : '' ?>><?= e($pt) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-3">
            <label class="form-label">Buyer</label>
            <select name="buyer_id" class="form-select">
              <option value="">— Select buyer —</option>
              <?php foreach ($users as $u): ?>
                <option value="<?= (int)$u['id'] ?>" <?= (string)($order['buyer_id'] ?? '') === (string)$u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
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
            <label class="form-label">Vendor GSTIN</label>
            <input type="text" name="vendor_gstin" class="form-control" maxlength="15" style="text-transform:uppercase" placeholder="e.g. 27ABCDE1234F1Z5" value="<?= e($order['vendor_gstin'] ?? '') ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Vendor Quotation No.</label>
            <input type="text" name="vendor_quote_no" class="form-control" value="<?= e($order['vendor_quote_no'] ?? '') ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Currency <span class="text-danger">*</span></label>
            <input type="text" name="currency" id="currencyInput" class="form-control" maxlength="3" style="text-transform:uppercase" value="<?= e($order['currency']) ?>" required>
          </div>

          <div class="col-sm-3">
            <label class="form-label">Project</label>
            <input type="text" name="project" class="form-control" value="<?= e($order['project'] ?? '') ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Material Request No.</label>
            <input type="text" name="material_request_no" class="form-control" value="<?= e($order['material_request_no'] ?? '') ?>">
          </div>
          <div class="col-sm-6">
            <label class="form-label">Notes</label>
            <input type="text" name="notes" class="form-control" value="<?= e($order['notes'] ?? '') ?>">
          </div>
        </div>
      </div>

      <div class="tab-pane fade <?= $activeTab === 'items' ? 'show active' : '' ?>" id="pane-items">
        <h6 class="text-muted mb-3"><i class="fa-solid fa-box"></i> Items</h6>
        <div class="po-line-items">
          <div class="table-responsive">
            <table class="table">
              <thead>
                <tr>
                  <th style="width:19%">Item Code <span class="text-danger">*</span></th>
                  <th style="width:13%">Description</th>
                  <th style="width:13%">Receive Into</th>
                  <th style="width:7%">Qty <span class="text-danger">*</span></th>
                  <th style="width:8%">UOM</th>
                  <th style="width:10%">Rate <span class="text-danger">*</span></th>
                  <th style="width:7%">Disc. %</th>
                  <th style="width:11%">Required By</th>
                  <th style="width:10%" class="text-end">Amount</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
              <?php if (!$items): $items = [['product_id' => '', 'description' => '', 'warehouse_id' => $order['ship_to_warehouse_id'] ?? '', 'quantity' => 1, 'uom' => '', 'rate' => 0, 'discount_percent' => 0, 'required_by' => '']]; endif; ?>
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
                    <select class="form-select form-select-sm" name="item_warehouse_id[]">
                      <option value="">— Ship-to —</option>
                      <?php foreach ($warehouses as $w): ?>
                        <option value="<?= (int)$w['id'] ?>" <?= (string)($it['warehouse_id'] ?? '') === (string)$w['id'] ? 'selected' : '' ?>><?= e($w['name']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </td>
                  <td><input type="number" min="1" class="form-control form-control-sm js-qty" name="quantity[]" value="<?= e($it['quantity']) ?>"></td>
                  <td>
                    <select class="form-select form-select-sm js-uom" name="uom[]">
                      <?php $rowUoms = $it['product_id'] && isset($productMeta[(int)$it['product_id']]) ? $productMeta[(int)$it['product_id']]['uoms'] : ['pcs' => 1.0]; ?>
                      <?php foreach ($rowUoms as $uomName => $factor): ?>
                        <option value="<?= e($uomName) ?>" <?= (string)($it['uom'] ?? '') === (string)$uomName ? 'selected' : '' ?>><?= e($uomName) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </td>
                  <td><input type="number" step="0.01" min="0" class="form-control form-control-sm js-price" name="rate[]" value="<?= e($it['rate'] ?? $it['unit_cost'] ?? 0) ?>"></td>
                  <td><input type="number" step="0.01" min="0" max="100" class="form-control form-control-sm js-discount" name="discount_percent[]" value="<?= e($it['discount_percent'] ?? 0) ?>"></td>
                  <td><input type="date" class="form-control form-control-sm" name="item_required_by[]" value="<?= e($it['required_by'] ?? '') ?>"></td>
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
              <div class="d-flex justify-content-between mb-1"><span class="text-muted">Total Quantity</span><strong id="poTotalQty">0</strong></div>
              <div class="d-flex justify-content-between fs-5"><span>Net Total</span><strong id="poTotal">0.00</strong></div>
            </div>
          </div>
        </div>
      </div>

      <div class="tab-pane fade <?= $activeTab === 'taxes' ? 'show active' : '' ?>" id="pane-taxes">
        <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
          <div>
            <h6 class="text-muted mb-0"><i class="fa-solid fa-percent"></i> Taxes and Charges</h6>
            <div class="small text-muted">Apply input GST, freight and other charges to this purchase order.</div>
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

        <div class="po-tax-rows">
          <div class="table-responsive mb-2">
            <table class="table table-sm">
              <thead><tr><th>Type</th><th>Account Head</th><th>Description</th><th style="width:130px">Rate / Amount</th><th>Based On</th><th class="text-end" style="width:110px">Amount</th><th></th></tr></thead>
              <tbody>
              <?php if (!$taxRows): $taxRows = [['type' => 'on_item', 'account_head_id' => '', 'description' => '', 'rate_or_amount' => '', 'based_on' => 'net_amount', 'amount' => 0]]; endif; ?>
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
                      <option value="net_amount" <?= $tr['based_on'] === 'net_amount' ? 'selected' : '' ?>>Net Amount (%)</option>
                      <option value="actual_amount" <?= $tr['based_on'] === 'actual_amount' ? 'selected' : '' ?>>Actual Amount</option>
                    </select>
                  </td>
                  <td class="text-end js-tax-amount"><?= number_format((float)($tr['amount'] ?? 0), 2) ?></td>
                  <td><button type="button" class="btn btn-sm btn-outline-danger po-tax-remove-row"><i class="fa-solid fa-xmark"></i></button></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div class="d-flex gap-2 mb-3">
            <button type="button" class="btn btn-sm btn-outline-brand po-tax-add-row"><i class="fa-solid fa-plus"></i> Add Row</button>
            <button type="button" id="calcTaxesBtn" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-percent"></i> Calculate Taxes</button>
          </div>
        </div>

        <div class="row g-3">
          <div class="col-lg-4">
            <div class="card p-3 h-100">
              <h6 class="mb-3"><i class="fa-solid fa-circle-info"></i> Additional Information</h6>
              <div class="mb-3">
                <label class="form-label">Place of Supply</label>
                <input type="text" name="place_of_supply" class="form-control" placeholder="e.g. 27-Maharashtra" value="<?= e($order['place_of_supply'] ?? '') ?>">
              </div>
              <div class="row g-2">
                <div class="col-sm-8">
                  <label class="form-label">Vendor GST Category</label>
                  <select name="gst_category" class="form-select">
                    <option value="">— Select —</option>
                    <?php foreach ($gstCategories as $val => $label): ?>
                      <option value="<?= e($val) ?>" <?= ($order['gst_category'] ?? '') === $val ? 'selected' : '' ?>><?= e($label) ?></option>
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

      <div class="tab-pane fade <?= $activeTab === 'shipping' ? 'show active' : '' ?>" id="pane-shipping">
        <div class="row g-3 mb-3">
          <div class="col-lg-6">
            <div class="card p-3 h-100">
              <h6 class="mb-3"><i class="fa-solid fa-warehouse"></i> Receiving</h6>
              <div class="row g-2 mb-2">
                <div class="col-sm-6">
                  <label class="form-label">Ship-to Warehouse <span class="text-danger">*</span></label>
                  <select name="ship_to_warehouse_id" id="shipToWarehouseSelect" class="form-select" required>
                    <option value="">— Select warehouse —</option>
                    <?php foreach ($warehouses as $w): ?>
                      <option value="<?= (int)$w['id'] ?>" <?= (string)($order['ship_to_warehouse_id'] ?? '') === (string)$w['id'] ? 'selected' : '' ?>><?= e($w['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-sm-6">
                  <label class="form-label">Delivery Priority</label>
                  <select name="delivery_priority" class="form-select">
                    <?php foreach (['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'] as $val => $label): ?>
                      <option value="<?= $val ?>" <?= $order['delivery_priority'] === $val ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
              <div class="row g-2 mb-2">
                <div class="col-sm-6">
                  <label class="form-label">Expected Dispatch Date</label>
                  <input type="date" name="expected_dispatch_date" class="form-control" value="<?= e($order['expected_dispatch_date'] ?? '') ?>">
                </div>
                <div class="col-sm-6">
                  <label class="form-label">Expected Delivery Date</label>
                  <input type="date" name="expected_delivery_date" class="form-control" value="<?= e($order['expected_delivery_date'] ?? '') ?>">
                </div>
              </div>
              <div class="form-check mb-1 mt-2"><input type="checkbox" class="form-check-input" id="partialReceiptChk" name="allow_partial_receipt" value="1" <?= !empty($order['allow_partial_receipt']) ? 'checked' : '' ?>><label class="form-check-label" for="partialReceiptChk">Allow Partial Receipt</label></div>
              <div class="form-check"><input type="checkbox" class="form-check-input" id="inspectionChk" name="inspection_required" value="1" <?= !empty($order['inspection_required']) ? 'checked' : '' ?>><label class="form-check-label" for="inspectionChk">Quality Inspection Required on Receipt</label></div>
            </div>
          </div>
          <div class="col-lg-6">
            <div class="card p-3 h-100">
              <h6 class="mb-3"><i class="fa-solid fa-truck"></i> Transport</h6>
              <div class="row g-2 mb-2">
                <div class="col-sm-6">
                  <label class="form-label">Delivery Terms (Incoterms)</label>
                  <select name="delivery_terms" class="form-select">
                    <option value="">— Select incoterms —</option>
                    <?php foreach (['EXW', 'FOR', 'FOB', 'CIF', 'CPT', 'DAP', 'DDP'] as $term): ?>
                      <option value="<?= $term ?>" <?= ($order['delivery_terms'] ?? '') === $term ? 'selected' : '' ?>><?= $term ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-sm-6">
                  <label class="form-label">Mode of Transport</label>
                  <select name="mode_of_transport" class="form-select">
                    <option value="">— Select —</option>
                    <?php foreach (['Road', 'Rail', 'Air', 'Sea', 'Courier', 'Vendor Delivery', 'Self Pickup'] as $m): ?>
                      <option value="<?= e($m) ?>" <?= ($order['mode_of_transport'] ?? '') === $m ? 'selected' : '' ?>><?= e($m) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
              <div class="row g-2 mb-2">
                <div class="col-sm-6">
                  <label class="form-label">Transporter / Shipping Partner</label>
                  <select name="shipping_partner_id" class="form-select">
                    <option value="">— Select —</option>
                    <?php foreach ($shippingPartners as $sp): ?>
                      <option value="<?= (int)$sp['id'] ?>" <?= (string)($order['shipping_partner_id'] ?? '') === (string)$sp['id'] ? 'selected' : '' ?>><?= e($sp['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-sm-6">
                  <label class="form-label">Freight Terms</label>
                  <select name="freight_terms" class="form-select">
                    <option value="">— Select —</option>
                    <?php foreach (['Paid by Vendor', 'To Pay (Buyer)', 'Included in Price'] as $ft): ?>
                      <option value="<?= e($ft) ?>" <?= ($order['freight_terms'] ?? '') === $ft ? 'selected' : '' ?>><?= e($ft) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
              <div class="mb-2">
                <label class="form-label">Tracking / LR No.</label>
                <input type="text" name="tracking_no" class="form-control" value="<?= e($order['tracking_no'] ?? '') ?>">
              </div>
              <div>
                <label class="form-label">Delivery Instructions</label>
                <textarea name="delivery_remarks" class="form-control" rows="2"><?= e($order['delivery_remarks'] ?? '') ?></textarea>
              </div>
            </div>
          </div>
        </div>

        <div class="card p-3">
          <h6 class="mb-2">Items to Receive</h6>
          <div class="table-responsive">
            <table class="table table-sm">
              <thead><tr><th>Item Code</th><th>Item Name</th><th>Receive Into</th><th class="text-end">Qty Ordered</th><th>UOM</th><th>Required By</th></tr></thead>
              <tbody id="receiveItemsBody"><tr><td colspan="6" class="text-muted text-center">Add items on the Items tab first.</td></tr></tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="tab-pane fade <?= $activeTab === 'payment' ? 'show active' : '' ?>" id="pane-payment">
        <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
          <h6 class="text-muted mb-0"><i class="fa-solid fa-credit-card"></i> Payment Terms</h6>
          <div class="d-flex gap-2 align-items-end">
            <div>
              <label class="form-label small mb-1">Payment Terms Template</label>
              <select id="paymentTermsTemplateSelect" class="form-select form-select-sm">
                <option value="">— None —</option>
                <?php foreach ($paymentTermsTemplates as $pt): ?>
                  <option value="<?= (int)$pt['id'] ?>" <?= (string)$order['payment_terms_template_id'] === (string)$pt['id'] ? 'selected' : '' ?>><?= e($pt['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <button type="button" id="applyPaymentTemplateBtn" class="btn btn-sm btn-outline-brand"><i class="fa-solid fa-download"></i> Apply Template</button>
          </div>
        </div>
        <input type="hidden" name="payment_terms_template_id" id="paymentTermsTemplateIdInput" value="<?= e($order['payment_terms_template_id'] ?? '') ?>">

        <div class="row g-3 mb-3">
          <div class="col-lg-5">
            <div class="card p-3 h-100">
              <h6 class="mb-3">General Terms</h6>
              <div class="mb-2">
                <label class="form-label">Payment Terms</label>
                <input type="text" name="payment_terms" class="form-control" placeholder="e.g. 30% Advance, 70% Within 30 Days of Invoice" value="<?= e($order['payment_terms'] ?? '') ?>">
              </div>
              <div class="row g-2 mb-2">
                <div class="col-sm-6">
                  <label class="form-label">Payment Method</label>
                  <select name="payment_method" class="form-select">
                    <option value="">— Select —</option>
                    <?php foreach (['Bank Transfer', 'NEFT / RTGS', 'Cheque', 'UPI', 'Cash', 'Letter of Credit'] as $pm): ?>
                      <option value="<?= e($pm) ?>" <?= ($order['payment_method'] ?? '') === $pm ? 'selected' : '' ?>><?= e($pm) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-sm-6">
                  <label class="form-label">Due Date Basis</label>
                  <select name="payment_due_date_basis" class="form-select">
                    <option value="against_invoice" <?= $order['payment_due_date_basis'] === 'against_invoice' ? 'selected' : '' ?>>Against Invoice</option>
                    <option value="against_receipt" <?= $order['payment_due_date_basis'] === 'against_receipt' ? 'selected' : '' ?>>Against Goods Receipt</option>
                    <option value="against_order_date" <?= $order['payment_due_date_basis'] === 'against_order_date' ? 'selected' : '' ?>>Against PO Date</option>
                  </select>
                </div>
              </div>
              <div class="row g-2 mb-2">
                <div class="col-sm-6">
                  <label class="form-label">Advance to Vendor %</label>
                  <input type="number" step="0.01" min="0" max="100" name="advance_percentage" id="advancePercentageInput" class="form-control" value="<?= e($order['advance_percentage'] ?? 0) ?>">
                </div>
                <div class="col-sm-6">
                  <label class="form-label">Advance Amount</label>
                  <input type="text" id="advanceAmountDisplay" class="form-control" disabled value="0.00">
                </div>
              </div>
              <div class="mb-2">
                <label class="form-label">Vendor Bank Details</label>
                <input type="text" name="vendor_bank_details" class="form-control" placeholder="Bank, A/c no., IFSC" value="<?= e($order['vendor_bank_details'] ?? '') ?>">
              </div>
              <div>
                <label class="form-label">Payment Instructions</label>
                <textarea name="payment_instructions" class="form-control" rows="2"><?= e($order['payment_instructions'] ?? '') ?></textarea>
              </div>
            </div>
          </div>
          <div class="col-lg-7">
            <div class="card p-3 h-100">
              <h6 class="mb-3">Payment Schedule <span class="text-muted small fw-normal">— % of order total, must total 100</span></h6>
              <div class="table-responsive mb-2">
                <table class="table table-sm">
                  <thead><tr><th style="min-width:150px">Due On</th><th style="width:70px">Days</th><th style="min-width:120px">Type</th><th style="width:85px">%</th><th class="text-end" style="width:100px">Amount</th><th>Due Date</th><th></th></tr></thead>
                  <tbody class="pts-tbody">
                  <?php if (!$paymentSchedule): $paymentSchedule = [['due_on' => 'on_invoice', 'days_from' => 30, 'payment_type' => 'balance', 'percentage' => 100, 'amount' => 0]]; endif; ?>
                  <?php foreach ($paymentSchedule as $ps): ?>
                    <tr data-row>
                      <td>
                        <select class="form-select form-select-sm pts-due-on" name="sched_due_on[]">
                          <?php foreach ($dueOnOptions as $val => $label): ?>
                            <option value="<?= $val ?>" <?= $ps['due_on'] === $val ? 'selected' : '' ?>><?= $label ?></option>
                          <?php endforeach; ?>
                        </select>
                      </td>
                      <td><input type="number" min="0" class="form-control form-control-sm pts-days" name="sched_days_from[]" value="<?= e($ps['days_from']) ?>"></td>
                      <td>
                        <select class="form-select form-select-sm" name="sched_payment_type[]">
                          <?php foreach ($paymentTypeOptions as $val => $label): ?>
                            <option value="<?= $val ?>" <?= $ps['payment_type'] === $val ? 'selected' : '' ?>><?= $label ?></option>
                          <?php endforeach; ?>
                        </select>
                      </td>
                      <td><input type="number" step="0.01" min="0" max="100" class="form-control form-control-sm pts-percentage" name="sched_percentage[]" value="<?= e($ps['percentage']) ?>"></td>
                      <td class="text-end pts-amount"><?= number_format((float)($ps['amount'] ?? 0), 2) ?></td>
                      <td class="small text-muted pts-due-date">—</td>
                      <td><button type="button" class="btn btn-sm btn-outline-danger pts-remove-row"><i class="fa-solid fa-xmark"></i></button></td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <div class="d-flex justify-content-between align-items-center">
                <button type="button" class="btn btn-sm btn-outline-brand pts-add-row"><i class="fa-solid fa-plus"></i> Add Payment Term</button>
                <div class="small">Total: <strong id="ptsPercentTotal">0</strong>% · <strong id="ptsAmountTotal">0.00</strong></div>
              </div>
              <div id="ptsWarning" class="alert alert-warning small py-1 px-2 mt-2 mb-0 d-none">Percentages should add up to 100%.</div>
            </div>
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
                  <label class="form-label">Order Type</label>
                  <select name="order_type" class="form-select">
                    <?php foreach (['Standard', 'Subcontracting', 'Blanket', 'Service', 'Import'] as $ot): ?>
                      <option value="<?= e($ot) ?>" <?= ($order['order_type'] ?: 'Standard') === $ot ? 'selected' : '' ?>><?= e($ot) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-sm-6">
                  <label class="form-label">Approver</label>
                  <select name="approver_id" class="form-select">
                    <option value="">— Select approver —</option>
                    <?php foreach ($users as $u): ?>
                      <option value="<?= (int)$u['id'] ?>" <?= (string)($order['approver_id'] ?? '') === (string)$u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-sm-6">
                  <label class="form-label">Cost Center</label>
                  <input type="text" name="cost_center" class="form-control" value="<?= e($order['cost_center'] ?? '') ?>">
                </div>
                <div class="col-sm-6">
                  <label class="form-label">Business Unit</label>
                  <input type="text" name="business_unit" class="form-control" value="<?= e($order['business_unit'] ?? '') ?>">
                </div>
                <div class="col-sm-12">
                  <label class="form-label">Tags</label>
                  <input type="text" name="tags" class="form-control" placeholder="Comma-separated" value="<?= e($order['tags'] ?? '') ?>">
                </div>
              </div>
            </div>
          </div>
          <div class="col-lg-6">
            <div class="card p-3 mb-3">
              <h6 class="mb-3">Terms &amp; Remarks</h6>
              <div class="mb-3">
                <label class="form-label">Terms &amp; Conditions (printed on PO)</label>
                <textarea name="terms_conditions" class="form-control" rows="4"><?= e($order['terms_conditions'] ?? '') ?></textarea>
              </div>
              <div>
                <label class="form-label">Remarks (Internal)</label>
                <textarea name="remarks_internal" class="form-control" rows="3"><?= e($order['remarks_internal'] ?? '') ?></textarea>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="page-actions mt-3">
      <button type="submit" class="btn btn-brand">Save Purchase Order</button>
      <a href="orders.php" class="btn btn-outline-secondary">Cancel</a>
    </div>
  </form>
</div>

<?php
$extra_js_inline = "
var productMeta = " . json_encode($productMeta) . ";
var priceListRates = " . json_encode($priceListRates) . ";
var vendorMeta = " . json_encode($vendorMeta) . ";
var warehouseNames = " . json_encode($warehouseNames) . ";
var accountMeta = " . json_encode($accountMeta) . ";
var taxTemplateRows = " . json_encode($taxTemplateRows) . ";
var paymentTermsTemplateRows = " . json_encode($paymentTermsTemplateRows) . ";
var dueOnOptions = " . json_encode($dueOnOptions) . ";
var paymentTypeOptions = " . json_encode($paymentTypeOptions) . ";

function poEsc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
function poOptions(map, selected) {
  return Object.keys(map).map(function (k) { return '<option value=\"' + k + '\"' + (String(k) === String(selected) ? ' selected' : '') + '>' + poEsc(map[k]) + '</option>'; }).join('');
}
function rateFor(productId, priceListId) {
  if (priceListRates[priceListId] && priceListRates[priceListId][productId] !== undefined) return priceListRates[priceListId][productId];
  return productMeta[productId] ? productMeta[productId].rate : 0;
}

// ---- Details: vendor defaults ----
document.addEventListener('DOMContentLoaded', function () {
  var vendorSelect = document.getElementById('vendorSelect');
  var priceListSelect = document.getElementById('priceListSelect');
  var currencyInput = document.getElementById('currencyInput');
  if (vendorSelect) {
    vendorSelect.addEventListener('change', function () {
      var m = vendorMeta[vendorSelect.value];
      if (!m) return;
      var addr = document.getElementById('vendorAddressInput');
      var contact = document.getElementById('vendorContactInput');
      if (addr) addr.value = m.address || '';
      if (contact && !contact.value) contact.value = m.phone || m.email || '';
    });
  }
  if (priceListSelect && currencyInput) {
    priceListSelect.addEventListener('change', function () {
      var opt = priceListSelect.selectedOptions[0];
      if (opt && opt.dataset.currency) currencyInput.value = opt.dataset.currency;
    });
  }
});

// ---- Items ----
document.addEventListener('DOMContentLoaded', function () {
  var wrap = document.querySelector('.po-line-items');
  if (!wrap) return;
  var tbody = wrap.querySelector('tbody');
  var priceListSelect = document.getElementById('priceListSelect');

  function renderReceiveTable() {
    var body = document.getElementById('receiveItemsBody');
    if (!body) return;
    var shipTo = document.getElementById('shipToWarehouseSelect');
    var html = '';
    wrap.querySelectorAll('tr[data-row]').forEach(function (row) {
      var pid = row.querySelector('.js-product').value;
      if (!pid || !productMeta[pid]) return;
      var wh = row.querySelector('select[name=\"item_warehouse_id[]\"]').value || (shipTo ? shipTo.value : '');
      var req = row.querySelector('input[name=\"item_required_by[]\"]').value || document.getElementById('requiredByInput').value || '—';
      html += '<tr><td>' + poEsc(productMeta[pid].sku) + '</td><td>' + poEsc(productMeta[pid].name) + '</td><td>' + poEsc(warehouseNames[wh] || '—') + '</td><td class=\"text-end\">' + (parseInt(row.querySelector('.js-qty').value, 10) || 0) + '</td><td>' + poEsc(row.querySelector('.js-uom').value) + '</td><td>' + poEsc(req) + '</td></tr>';
    });
    body.innerHTML = html || '<tr><td colspan=\"6\" class=\"text-muted text-center\">Add items on the Items tab first.</td></tr>';
  }
  window.poRenderReceiveTable = renderReceiveTable;

  function recalc() {
    var total = 0, totalQty = 0;
    wrap.querySelectorAll('tr[data-row]').forEach(function (row) {
      var qty = parseFloat(row.querySelector('.js-qty').value || 0) || 0;
      var price = parseFloat(row.querySelector('.js-price').value || 0) || 0;
      var discount = parseFloat(row.querySelector('.js-discount').value || 0) || 0;
      var amount = Math.round(qty * price * (1 - discount / 100) * 100) / 100;
      row.querySelector('.js-amount').textContent = amount.toFixed(2);
      total += amount;
      totalQty += qty;
    });
    document.getElementById('poTotal').textContent = total.toFixed(2);
    document.getElementById('poTotalQty').textContent = totalQty;
    renderReceiveTable();
    if (window.poRecalcTaxes) window.poRecalcTaxes();
  }

  function applyProductDefaults(row) {
    var pid = row.querySelector('.js-product').value;
    if (!pid || !productMeta[pid]) return;
    var meta = productMeta[pid];
    var uomSelect = row.querySelector('.js-uom');
    uomSelect.innerHTML = '';
    Object.keys(meta.uoms || {}).forEach(function (name) {
      var o = document.createElement('option');
      o.value = name; o.textContent = name;
      uomSelect.appendChild(o);
    });
    row.querySelector('.js-price').value = rateFor(pid, priceListSelect ? priceListSelect.value : null);
  }

  wrap.addEventListener('input', recalc);
  wrap.addEventListener('change', function (e) {
    if (e.target.classList.contains('js-product')) applyProductDefaults(e.target.closest('tr[data-row]'));
    recalc();
  });
  if (priceListSelect) {
    priceListSelect.addEventListener('change', function () {
      wrap.querySelectorAll('tr[data-row]').forEach(function (row) {
        var pid = row.querySelector('.js-product').value;
        if (pid) row.querySelector('.js-price').value = rateFor(pid, priceListSelect.value);
      });
      recalc();
    });
  }
  ['shipToWarehouseSelect', 'requiredByInput'].forEach(function (id) {
    var el = document.getElementById(id);
    if (el) el.addEventListener('change', renderReceiveTable);
  });

  wrap.addEventListener('click', function (e) {
    if (e.target.closest('.js-add-row')) {
      var rows = tbody.querySelectorAll('tr[data-row]');
      var clone = rows[rows.length - 1].cloneNode(true);
      clone.querySelectorAll('input').forEach(function (inp) {
        inp.value = inp.classList.contains('js-qty') ? 1 : (inp.classList.contains('js-discount') ? 0 : '');
      });
      clone.querySelectorAll('select').forEach(function (sel) { sel.selectedIndex = 0; });
      clone.querySelector('.js-uom').innerHTML = '<option value=\"pcs\">pcs</option>';
      clone.querySelector('.js-amount').textContent = '0.00';
      tbody.appendChild(clone);
      recalc();
      return;
    }
    var rm = e.target.closest('.js-remove-row');
    if (rm && tbody.querySelectorAll('tr[data-row]').length > 1) {
      rm.closest('tr[data-row]').remove();
      recalc();
    }
  });

  recalc();
});

// ---- Taxes & Charges ----
document.addEventListener('DOMContentLoaded', function () {
  var wrap = document.querySelector('.po-tax-rows');
  if (!wrap) return;
  var tbody = wrap.querySelector('tbody');

  function accountOptions(selected) {
    var html = '<option value=\"\">— None —</option>';
    Object.keys(accountMeta).forEach(function (id) {
      html += '<option value=\"' + id + '\"' + (String(id) === String(selected) ? ' selected' : '') + '>' + poEsc(accountMeta[id].name) + '</option>';
    });
    return html;
  }
  function newTaxRow(r) {
    var tr = document.createElement('tr');
    tr.setAttribute('data-row', '');
    tr.innerHTML = '<td><select class=\"form-select form-select-sm\" name=\"row_type[]\">' + poOptions({on_item: 'On Item', on_order: 'On Order'}, r.type) + '</select></td>' +
      '<td><select class=\"form-select form-select-sm js-tax-account\" name=\"row_account_head_id[]\">' + accountOptions(r.account_head_id) + '</select></td>' +
      '<td><input type=\"text\" class=\"form-control form-control-sm\" name=\"row_description[]\" value=\"' + poEsc(r.description || '') + '\"></td>' +
      '<td><input type=\"number\" step=\"0.01\" class=\"form-control form-control-sm js-tax-rate\" name=\"row_rate_or_amount[]\" value=\"' + (r.rate_or_amount === undefined ? '' : r.rate_or_amount) + '\"></td>' +
      '<td><select class=\"form-select form-select-sm js-tax-basedon\" name=\"row_based_on[]\">' + poOptions({net_amount: 'Net Amount (%)', actual_amount: 'Actual Amount'}, r.based_on || 'net_amount') + '</select></td>' +
      '<td class=\"text-end js-tax-amount\">0.00</td>' +
      '<td><button type=\"button\" class=\"btn btn-sm btn-outline-danger po-tax-remove-row\"><i class=\"fa-solid fa-xmark\"></i></button></td>';
    return tr;
  }
  function poRound(amount, precision, method) {
    precision = parseFloat(precision) || 0;
    if (precision <= 0) return Math.round(amount * 100) / 100;
    var units = amount / precision;
    var rounded = method === 'up' ? Math.ceil(units) : (method === 'down' ? Math.floor(units) : Math.round(units));
    return Math.round(rounded * precision * 100) / 100;
  }

  window.poRecalcTaxes = function () {
    var net = parseFloat(document.getElementById('poTotal').textContent) || 0;
    var totalCharges = 0, totalTax = 0;
    tbody.querySelectorAll('tr[data-row]').forEach(function (row) {
      var basedOn = row.querySelector('.js-tax-basedon').value;
      var rate = parseFloat(row.querySelector('.js-tax-rate').value || 0) || 0;
      var amount = Math.round((basedOn === 'net_amount' ? net * rate / 100 : rate) * 100) / 100;
      row.querySelector('.js-tax-amount').textContent = amount.toFixed(2);
      var meta = accountMeta[row.querySelector('.js-tax-account').value];
      if (meta && meta.type === 'tax') totalTax += amount; else totalCharges += amount;
    });
    var val = function (id) { return parseFloat(document.getElementById(id).value || 0) || 0; };
    var grand = net + totalCharges + totalTax + val('additionalChargeInput') - val('additionalDiscountInput');
    var adjType = document.getElementById('adjustmentTypeSelect').value;
    if (adjType === 'add') grand += val('adjustmentAmountInput');
    else if (adjType === 'subtract') grand -= val('adjustmentAmountInput');
    grand = poRound(grand, document.getElementById('roundingPrecisionInput').value, document.getElementById('roundingMethodSelect').value);

    document.getElementById('taxSummaryNet').textContent = net.toFixed(2);
    document.getElementById('taxSummaryCharges').textContent = totalCharges.toFixed(2);
    document.getElementById('taxSummaryTax').textContent = totalTax.toFixed(2);
    document.getElementById('taxSummaryGrandTotal').textContent = grand.toFixed(2);
    document.getElementById('taxSummaryRate').textContent = (net > 0 ? totalTax / net * 100 : 0).toFixed(2) + '%';
    window.poGrandTotal = grand;
    if (window.poRecalcPaymentSchedule) window.poRecalcPaymentSchedule();
  };

  wrap.addEventListener('input', window.poRecalcTaxes);
  wrap.addEventListener('change', window.poRecalcTaxes);
  ['additionalDiscountInput', 'additionalChargeInput', 'adjustmentTypeSelect', 'adjustmentAmountInput', 'roundingMethodSelect', 'roundingPrecisionInput'].forEach(function (id) {
    var el = document.getElementById(id);
    if (el) { el.addEventListener('input', window.poRecalcTaxes); el.addEventListener('change', window.poRecalcTaxes); }
  });
  document.getElementById('calcTaxesBtn').addEventListener('click', window.poRecalcTaxes);

  wrap.addEventListener('click', function (e) {
    if (e.target.closest('.po-tax-add-row')) {
      tbody.appendChild(newTaxRow({type: 'on_item', based_on: 'net_amount'}));
      window.poRecalcTaxes();
      return;
    }
    var rm = e.target.closest('.po-tax-remove-row');
    if (rm) {
      rm.closest('tr[data-row]').remove();
      window.poRecalcTaxes();
    }
  });

  document.getElementById('applyTemplateBtn').addEventListener('click', function () {
    var ttId = document.getElementById('taxTemplateSelect').value;
    document.getElementById('taxTemplateIdInput').value = ttId;
    tbody.innerHTML = '';
    (taxTemplateRows[ttId] || []).forEach(function (r) { tbody.appendChild(newTaxRow(r)); });
    window.poRecalcTaxes();
  });

  window.poRecalcTaxes();
});

// ---- Payment Terms ----
document.addEventListener('DOMContentLoaded', function () {
  var wrap = document.querySelector('.pts-tbody');
  if (!wrap) return;

  function newScheduleRow(r) {
    var tr = document.createElement('tr');
    tr.setAttribute('data-row', '');
    tr.innerHTML = '<td><select class=\"form-select form-select-sm pts-due-on\" name=\"sched_due_on[]\">' + poOptions(dueOnOptions, r.due_on || 'order_date') + '</select></td>' +
      '<td><input type=\"number\" min=\"0\" class=\"form-control form-control-sm pts-days\" name=\"sched_days_from[]\" value=\"' + (r.days_from || 0) + '\"></td>' +
      '<td><select class=\"form-select form-select-sm\" name=\"sched_payment_type[]\">' + poOptions(paymentTypeOptions, r.payment_type || 'balance') + '</select></td>' +
      '<td><input type=\"number\" step=\"0.01\" min=\"0\" max=\"100\" class=\"form-control form-control-sm pts-percentage\" name=\"sched_percentage[]\" value=\"' + (r.percentage || 0) + '\"></td>' +
      '<td class=\"text-end pts-amount\">0.00</td><td class=\"small text-muted pts-due-date\">—</td>' +
      '<td><button type=\"button\" class=\"btn btn-sm btn-outline-danger pts-remove-row\"><i class=\"fa-solid fa-xmark\"></i></button></td>';
    return tr;
  }

  window.poRecalcPaymentSchedule = function () {
    var grand = window.poGrandTotal || 0;
    var orderDate = document.querySelector('input[name=\"order_date\"]').value;
    var pctTotal = 0, amtTotal = 0;
    wrap.querySelectorAll('tr[data-row]').forEach(function (row) {
      var pct = parseFloat(row.querySelector('.pts-percentage').value || 0) || 0;
      var amt = Math.round(grand * pct) / 100;
      row.querySelector('.pts-amount').textContent = amt.toFixed(2);
      var dueOn = row.querySelector('.pts-due-on').value;
      var days = parseInt(row.querySelector('.pts-days').value, 10) || 0;
      var dueEl = row.querySelector('.pts-due-date');
      if ((dueOn === 'order_date' || dueOn === 'fixed_days') && orderDate) {
        var d = new Date(orderDate + 'T00:00:00');
        d.setDate(d.getDate() + days);
        dueEl.textContent = d.toISOString().slice(0, 10);
      } else {
        dueEl.textContent = days ? days + ' days after ' + (dueOn === 'on_receipt' ? 'receipt' : 'invoice') : 'On ' + (dueOn === 'on_receipt' ? 'receipt' : 'invoice');
      }
      pctTotal += pct;
      amtTotal += amt;
    });
    document.getElementById('ptsPercentTotal').textContent = pctTotal.toFixed(2).replace(/\\.00$/, '');
    document.getElementById('ptsAmountTotal').textContent = amtTotal.toFixed(2);
    document.getElementById('ptsWarning').classList.toggle('d-none', !wrap.querySelector('tr[data-row]') || Math.abs(pctTotal - 100) < 0.01);
    var advPct = parseFloat(document.getElementById('advancePercentageInput').value || 0) || 0;
    document.getElementById('advanceAmountDisplay').value = (grand * advPct / 100).toFixed(2);
  };

  wrap.addEventListener('input', window.poRecalcPaymentSchedule);
  wrap.addEventListener('change', window.poRecalcPaymentSchedule);
  document.getElementById('advancePercentageInput').addEventListener('input', window.poRecalcPaymentSchedule);
  document.querySelector('input[name=\"order_date\"]').addEventListener('change', window.poRecalcPaymentSchedule);

  wrap.closest('.card').addEventListener('click', function (e) {
    if (e.target.closest('.pts-add-row')) {
      wrap.appendChild(newScheduleRow({due_on: 'on_invoice', payment_type: 'balance', percentage: 0}));
      window.poRecalcPaymentSchedule();
      return;
    }
    var rm = e.target.closest('.pts-remove-row');
    if (rm) {
      rm.closest('tr[data-row]').remove();
      window.poRecalcPaymentSchedule();
    }
  });

  document.getElementById('applyPaymentTemplateBtn').addEventListener('click', function () {
    var ptId = document.getElementById('paymentTermsTemplateSelect').value;
    document.getElementById('paymentTermsTemplateIdInput').value = ptId;
    var rows = paymentTermsTemplateRows[ptId] || [];
    wrap.innerHTML = '';
    rows.forEach(function (r) { wrap.appendChild(newScheduleRow(r)); });
    if (!rows.length) wrap.appendChild(newScheduleRow({due_on: 'on_invoice', days_from: 30, payment_type: 'balance', percentage: 100}));
    var nameInput = document.querySelector('input[name=\"payment_terms\"]');
    var sel = document.getElementById('paymentTermsTemplateSelect');
    if (ptId && nameInput && !nameInput.value) nameInput.value = sel.selectedOptions[0].textContent.trim();
    window.poRecalcPaymentSchedule();
  });

  window.poRecalcPaymentSchedule();
});

// Jump to the tab holding the first invalid required field instead of
// letting the browser silently refuse to submit from a hidden tab.
document.addEventListener('DOMContentLoaded', function () {
  var form = document.getElementById('poForm');
  form.addEventListener('invalid', function (e) {
    var pane = e.target.closest('.tab-pane');
    if (pane && !pane.classList.contains('active')) {
      var btn = document.querySelector('[data-bs-target=\"#' + pane.id + '\"]');
      if (btn) bootstrap.Tab.getOrCreateInstance(btn).show();
    }
  }, true);
});
";
require __DIR__ . '/../includes/footer.php';
?>
