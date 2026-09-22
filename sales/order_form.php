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
    'promised_delivery_date' => '', 'delivery_priority' => 'normal', 'fulfillment_type' => 'complete_order',
    'delivery_terms' => '', 'shipping_rule' => '', 'delivery_remarks' => '', 'ship_to_address_id' => '',
    'shipping_partner_id' => '', 'shipping_service_type' => '', 'shipping_method' => '', 'tracking_no' => '',
    'expected_dispatch_date' => '', 'expected_delivery_date' => '', 'create_dn_after_submit' => 1,
    'update_stock_on_submit' => 1, 'allow_partial_delivery' => 0, 'notify_customer' => 0,
    'print_picking_list' => 0, 'print_shipping_label' => 0, 'include_shipping_in_total' => 1,
    'payment_terms_template_id' => '', 'payment_terms' => '', 'payment_method' => '', 'payment_due_date_basis' => 'against_delivery',
    'payment_instructions' => '', 'require_advance_payment' => 0, 'advance_percentage' => 0, 'advance_valid_till' => '',
    'interest_on_late_payment' => 0, 'late_interest_rate' => 0, 'late_grace_period_days' => 0, 'late_payment_terms' => '',
    'payment_reference' => '', 'special_payment_terms' => '', 'allow_partial_payments' => 1, 'send_payment_reminder' => 0,
    'quotation_id' => '', 'opportunity' => '', 'customer_po_date' => '', 'campaign_source' => '',
    'sales_group' => '', 'sales_office' => '', 'cost_center' => '', 'business_unit' => '', 'valid_till' => '',
    'order_type' => 'Standard Order', 'tags' => '', 'remarks_internal' => '', 'end_customer' => '',
    'channel_partner' => '', 'deal_registration_no' => '', 'market_segment' => '', 'region' => '', 'expected_close_date' => '',
];
$items = [];
$taxRows = [];
$paymentSchedule = [];
$salesTeam = [];

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
    $stmt = db()->prepare('SELECT * FROM sales_order_payment_schedule WHERE order_id = ? ORDER BY sort_order, id');
    $stmt->execute([$id]);
    $paymentSchedule = $stmt->fetchAll();
    $stmt = db()->prepare('SELECT * FROM sales_order_sales_team WHERE order_id = ? ORDER BY sort_order, id');
    $stmt->execute([$id]);
    $salesTeam = $stmt->fetchAll();
} elseif ($fromQuotationId = (int)input('from_quotation')) {
    $stmt = db()->prepare('SELECT * FROM quotations WHERE id = ?');
    $stmt->execute([$fromQuotationId]);
    $quotation = $stmt->fetch();
    if ($quotation) {
        $order['customer_id'] = $quotation['customer_id'];
        $order['quotation_id'] = $fromQuotationId;
        $stmt = db()->prepare('SELECT qi.*, p.unit FROM quotation_items qi JOIN products p ON p.id = qi.product_id WHERE quotation_id = ?');
        $stmt->execute([$fromQuotationId]);
        foreach ($stmt->fetchAll() as $qi) {
            $items[] = [
                'product_id' => $qi['product_id'], 'description' => '', 'warehouse_id' => default_warehouse_id(),
                'quantity' => $qi['quantity'], 'uom' => $qi['unit'] ?: 'pcs', 'unit_price' => $qi['unit_price'], 'discount_percent' => 0,
            ];
        }
    }
}

$error = '';
$activeTab = in_array(input('tab'), ['items', 'taxes', 'shipping', 'payment', 'more'], true) ? input('tab') : 'details';

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

    $promisedDeliveryDate = input('promised_delivery_date') ?: null;
    $deliveryPriority = in_array(input('delivery_priority'), ['low', 'normal', 'high', 'urgent'], true) ? input('delivery_priority') : 'normal';
    $fulfillmentType = in_array(input('fulfillment_type'), ['complete_order', 'partial_allowed'], true) ? input('fulfillment_type') : 'complete_order';
    $deliveryTerms = input('delivery_terms') ?: null;
    $shippingRule = input('shipping_rule') ?: null;
    $deliveryRemarks = input('delivery_remarks') ?: null;
    $shipToAddressId = (int)input('ship_to_address_id') ?: null;
    $shippingPartnerId = (int)input('shipping_partner_id') ?: null;
    $shippingServiceType = input('shipping_service_type') ?: null;
    $shippingMethod = input('shipping_method') ?: null;
    $trackingNo = input('tracking_no') ?: null;
    $expectedDispatchDate = input('expected_dispatch_date') ?: null;
    $expectedDeliveryDate = input('expected_delivery_date') ?: null;
    $createDnAfterSubmit = input('create_dn_after_submit') ? 1 : 0;
    $updateStockOnSubmit = input('update_stock_on_submit') ? 1 : 0;
    $allowPartialDelivery = input('allow_partial_delivery') ? 1 : 0;
    $notifyCustomer = input('notify_customer') ? 1 : 0;
    $printPickingList = input('print_picking_list') ? 1 : 0;
    $printShippingLabel = input('print_shipping_label') ? 1 : 0;
    $includeShippingInTotal = input('include_shipping_in_total') ? 1 : 0;

    $paymentTermsTemplateId = (int)input('payment_terms_template_id') ?: null;
    $paymentTerms = input('payment_terms') ?: null;
    $paymentMethod = input('payment_method') ?: null;
    $paymentDueDateBasis = in_array(input('payment_due_date_basis'), ['against_delivery', 'against_order_date', 'fixed_date'], true) ? input('payment_due_date_basis') : 'against_delivery';
    $paymentInstructions = input('payment_instructions') ?: null;
    $requireAdvancePayment = input('require_advance_payment') ? 1 : 0;
    $advancePercentage = max(0, min(100, (float)input('advance_percentage')));
    $advanceValidTill = input('advance_valid_till') ?: null;
    $interestOnLatePayment = input('interest_on_late_payment') ? 1 : 0;
    $lateInterestRate = max(0, (float)input('late_interest_rate'));
    $lateGracePeriodDays = max(0, (int)input('late_grace_period_days'));
    $latePaymentTerms = input('late_payment_terms') ?: null;
    $paymentReference = input('payment_reference') ?: null;
    $specialPaymentTerms = input('special_payment_terms') ?: null;
    $allowPartialPayments = input('allow_partial_payments') ? 1 : 0;
    $sendPaymentReminder = input('send_payment_reminder') ? 1 : 0;

    $quotationId = (int)input('quotation_id') ?: null;
    $opportunity = input('opportunity') ?: null;
    $customerPoDate = input('customer_po_date') ?: null;
    $campaignSource = input('campaign_source') ?: null;
    $salesGroup = input('sales_group') ?: null;
    $salesOffice = input('sales_office') ?: null;
    $costCenter = input('cost_center') ?: null;
    $businessUnit = input('business_unit') ?: null;
    $validTill = input('valid_till') ?: null;
    $orderType = input('order_type') ?: 'Standard Order';
    $tags = input('tags') ?: null;
    $remarksInternal = input('remarks_internal') ?: null;
    $endCustomer = input('end_customer') ?: null;
    $channelPartner = input('channel_partner') ?: null;
    $dealRegistrationNo = input('deal_registration_no') ?: null;
    $marketSegment = input('market_segment') ?: null;
    $region = input('region') ?: null;
    $expectedCloseDate = input('expected_close_date') ?: null;

    $stSalesPersonIds = $_POST['team_sales_person_id'] ?? [];
    $stRoles = $_POST['team_role'] ?? [];
    $stCommissions = $_POST['team_commission_percent'] ?? [];
    $salesTeamToSave = [];
    $stSort = 0;
    foreach ($stSalesPersonIds as $i => $spId) {
        $spId = (int)$spId;
        if ($spId <= 0) {
            continue;
        }
        $salesTeamToSave[] = [
            'sales_person_id' => $spId, 'role' => trim($stRoles[$i] ?? '') ?: null,
            'commission_percent' => max(0, min(100, (float)($stCommissions[$i] ?? 0))), 'sort_order' => $stSort++,
        ];
    }

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
            $uom = trim($uoms[$i] ?? '') ?: 'pcs';
            $lineItems[] = [
                'product_id' => $pid,
                'description' => $descriptions[$i] ?? '',
                'warehouse_id' => $warehouseId,
                'quantity' => $qty,
                'uom' => $uom,
                'uom_conversion_factor' => uom_conversion_factor($pid, $uom),
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

    // Payment schedule row amounts are always computed server-side from
    // the server-computed grand total, same discipline as tax rows.
    $scheduleDueOns = $_POST['sched_due_on'] ?? [];
    $scheduleDaysFroms = $_POST['sched_days_from'] ?? [];
    $schedulePaymentTypes = $_POST['sched_payment_type'] ?? [];
    $schedulePercentages = $_POST['sched_percentage'] ?? [];
    $scheduleRemarksArr = $_POST['sched_remarks'] ?? [];
    $paymentScheduleToSave = [];
    $sort = 0;
    foreach ($schedulePercentages as $i => $pct) {
        $pct = max(0, min(100, (float)$pct));
        $remarks = trim($scheduleRemarksArr[$i] ?? '');
        if ($pct == 0 && $remarks === '') {
            continue;
        }
        $dueOn = in_array($scheduleDueOns[$i] ?? '', ['order_date', 'on_delivery', 'fixed_days'], true) ? $scheduleDueOns[$i] : 'order_date';
        $paymentType = in_array($schedulePaymentTypes[$i] ?? '', ['advance', 'part_payment', 'balance'], true) ? $schedulePaymentTypes[$i] : 'balance';
        $daysFrom = (int)($scheduleDaysFroms[$i] ?? 0);
        $paymentScheduleToSave[] = [
            'due_on' => $dueOn, 'days_from' => $daysFrom, 'payment_type' => $paymentType,
            'percentage' => $pct, 'amount' => round($grandTotal * $pct / 100, 2), 'remarks' => $remarks, 'sort_order' => $sort++,
        ];
    }

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
            $headerColNames = ['customer_id', 'contact_person', 'customer_address_id', 'warehouse_id', 'order_date', 'required_delivery_date', 'price_list_id', 'currency', 'sales_channel', 'territory', 'sales_person_id', 'customer_po_no', 'project', 'notes', 'total_amount', 'net_amount', 'tax_template_id', 'place_of_supply', 'gst_category', 'reverse_charge', 'tax_remarks', 'rounding_method', 'rounding_precision', 'additional_discount', 'additional_charge', 'adjustment_type', 'adjustment_amount', 'adjustment_remarks', 'promised_delivery_date', 'delivery_priority', 'fulfillment_type', 'delivery_terms', 'shipping_rule', 'delivery_remarks', 'ship_to_address_id', 'shipping_partner_id', 'shipping_service_type', 'shipping_method', 'tracking_no', 'expected_dispatch_date', 'expected_delivery_date', 'create_dn_after_submit', 'update_stock_on_submit', 'allow_partial_delivery', 'notify_customer', 'print_picking_list', 'print_shipping_label', 'include_shipping_in_total', 'payment_terms_template_id', 'payment_terms', 'payment_method', 'payment_due_date_basis', 'payment_instructions', 'require_advance_payment', 'advance_percentage', 'advance_valid_till', 'interest_on_late_payment', 'late_interest_rate', 'late_grace_period_days', 'late_payment_terms', 'payment_reference', 'special_payment_terms', 'allow_partial_payments', 'send_payment_reminder', 'quotation_id', 'opportunity', 'customer_po_date', 'campaign_source', 'sales_group', 'sales_office', 'cost_center', 'business_unit', 'valid_till', 'order_type', 'tags', 'remarks_internal', 'end_customer', 'channel_partner', 'deal_registration_no', 'market_segment', 'region', 'expected_close_date'];
            $headerVals = [$customerId, $contactPerson, $customerAddressId, $firstWarehouseId, $orderDate, $requiredDeliveryDate, $priceListId, $currency, $salesChannel, $territory, $salesPersonId, $customerPoNo, $project, $notes, $grandTotal, $netAmount, $taxTemplateId, $placeOfSupply, $gstCategory, $reverseCharge, $taxRemarks, $roundingMethod, $roundingPrecision, $additionalDiscount, $additionalCharge, $adjustmentType, $adjustmentAmount, $adjustmentRemarks, $promisedDeliveryDate, $deliveryPriority, $fulfillmentType, $deliveryTerms, $shippingRule, $deliveryRemarks, $shipToAddressId, $shippingPartnerId, $shippingServiceType, $shippingMethod, $trackingNo, $expectedDispatchDate, $expectedDeliveryDate, $createDnAfterSubmit, $updateStockOnSubmit, $allowPartialDelivery, $notifyCustomer, $printPickingList, $printShippingLabel, $includeShippingInTotal, $paymentTermsTemplateId, $paymentTerms, $paymentMethod, $paymentDueDateBasis, $paymentInstructions, $requireAdvancePayment, $advancePercentage, $advanceValidTill, $interestOnLatePayment, $lateInterestRate, $lateGracePeriodDays, $latePaymentTerms, $paymentReference, $specialPaymentTerms, $allowPartialPayments, $sendPaymentReminder, $quotationId, $opportunity, $customerPoDate, $campaignSource, $salesGroup, $salesOffice, $costCenter, $businessUnit, $validTill, $orderType, $tags, $remarksInternal, $endCustomer, $channelPartner, $dealRegistrationNo, $marketSegment, $region, $expectedCloseDate];
            if ($id) {
                $setClause = implode(', ', array_map(fn($c) => "$c=?", $headerColNames));
                $pdo->prepare("UPDATE sales_orders SET $setClause WHERE id=?")->execute([...$headerVals, $id]);
                $pdo->prepare('DELETE FROM sales_order_items WHERE order_id=?')->execute([$id]);
                $pdo->prepare('DELETE FROM sales_order_taxes WHERE order_id=?')->execute([$id]);
                $pdo->prepare('DELETE FROM sales_order_payment_schedule WHERE order_id=?')->execute([$id]);
                $pdo->prepare('DELETE FROM sales_order_sales_team WHERE order_id=?')->execute([$id]);
                $orderId = $id;
            } else {
                $orderNo = next_code('SO', 'sales_orders', 'order_no');
                $colList = implode(', ', ['order_no', ...$headerColNames, 'status', 'created_by']);
                $placeholders = implode(',', array_fill(0, count($headerVals) + 3, '?'));
                $pdo->prepare("INSERT INTO sales_orders ($colList) VALUES ($placeholders)")
                    ->execute([$orderNo, ...$headerVals, 'pending', current_user()['id']]);
                $orderId = (int)$pdo->lastInsertId();
            }
            $itemStmt = $pdo->prepare('INSERT INTO sales_order_items (order_id, product_id, description, warehouse_id, quantity, uom, uom_conversion_factor, unit_price, discount_percent, subtotal) VALUES (?,?,?,?,?,?,?,?,?,?)');
            foreach ($lineItems as $li) {
                $itemStmt->execute([$orderId, $li['product_id'], $li['description'], $li['warehouse_id'], $li['quantity'], $li['uom'], $li['uom_conversion_factor'], $li['unit_price'], $li['discount_percent'], $li['subtotal']]);
            }
            $taxStmt = $pdo->prepare('INSERT INTO sales_order_taxes (order_id, type, account_head_id, description, based_on, rate_or_amount, amount, sort_order) VALUES (?,?,?,?,?,?,?,?)');
            foreach ($taxRowsToSave as $tr) {
                $taxStmt->execute([$orderId, $tr['type'], $tr['account_head_id'], $tr['description'], $tr['based_on'], $tr['rate_or_amount'], $tr['amount'], $tr['sort_order']]);
            }
            $scheduleStmt = $pdo->prepare('INSERT INTO sales_order_payment_schedule (order_id, due_on, days_from, payment_type, percentage, amount, remarks, sort_order) VALUES (?,?,?,?,?,?,?,?)');
            foreach ($paymentScheduleToSave as $ps) {
                $scheduleStmt->execute([$orderId, $ps['due_on'], $ps['days_from'], $ps['payment_type'], $ps['percentage'], $ps['amount'], $ps['remarks'], $ps['sort_order']]);
            }
            $teamStmt = $pdo->prepare('INSERT INTO sales_order_sales_team (order_id, sales_person_id, role, commission_percent, sort_order) VALUES (?,?,?,?,?)');
            foreach ($salesTeamToSave as $st) {
                $teamStmt->execute([$orderId, $st['sales_person_id'], $st['role'], $st['commission_percent'], $st['sort_order']]);
            }
            $pdo->commit();
            log_activity('sales_order', $orderId, $id ? 'edited' : 'created');
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
        'promised_delivery_date' => $promisedDeliveryDate, 'delivery_priority' => $deliveryPriority, 'fulfillment_type' => $fulfillmentType,
        'delivery_terms' => $deliveryTerms, 'shipping_rule' => $shippingRule, 'delivery_remarks' => $deliveryRemarks,
        'ship_to_address_id' => $shipToAddressId, 'shipping_partner_id' => $shippingPartnerId, 'shipping_service_type' => $shippingServiceType,
        'shipping_method' => $shippingMethod, 'tracking_no' => $trackingNo, 'expected_dispatch_date' => $expectedDispatchDate,
        'expected_delivery_date' => $expectedDeliveryDate, 'create_dn_after_submit' => $createDnAfterSubmit,
        'update_stock_on_submit' => $updateStockOnSubmit, 'allow_partial_delivery' => $allowPartialDelivery,
        'notify_customer' => $notifyCustomer, 'print_picking_list' => $printPickingList, 'print_shipping_label' => $printShippingLabel,
        'include_shipping_in_total' => $includeShippingInTotal,
        'payment_terms_template_id' => $paymentTermsTemplateId, 'payment_terms' => $paymentTerms, 'payment_method' => $paymentMethod,
        'payment_due_date_basis' => $paymentDueDateBasis, 'payment_instructions' => $paymentInstructions,
        'require_advance_payment' => $requireAdvancePayment, 'advance_percentage' => $advancePercentage, 'advance_valid_till' => $advanceValidTill,
        'interest_on_late_payment' => $interestOnLatePayment, 'late_interest_rate' => $lateInterestRate,
        'late_grace_period_days' => $lateGracePeriodDays, 'late_payment_terms' => $latePaymentTerms,
        'payment_reference' => $paymentReference, 'special_payment_terms' => $specialPaymentTerms,
        'allow_partial_payments' => $allowPartialPayments, 'send_payment_reminder' => $sendPaymentReminder,
        'quotation_id' => $quotationId, 'opportunity' => $opportunity, 'customer_po_date' => $customerPoDate,
        'campaign_source' => $campaignSource, 'sales_group' => $salesGroup, 'sales_office' => $salesOffice,
        'cost_center' => $costCenter, 'business_unit' => $businessUnit, 'valid_till' => $validTill,
        'order_type' => $orderType, 'tags' => $tags, 'remarks_internal' => $remarksInternal,
        'end_customer' => $endCustomer, 'channel_partner' => $channelPartner, 'deal_registration_no' => $dealRegistrationNo,
        'market_segment' => $marketSegment, 'region' => $region, 'expected_close_date' => $expectedCloseDate,
    ];
    $items = $lineItems;
    $taxRows = $taxRowsToSave;
    $paymentSchedule = $paymentScheduleToSave;
    $salesTeam = $salesTeamToSave;
}

$newAddressId = (int)input('new_address_id');
if ($newAddressId) {
    $order['customer_address_id'] = $newAddressId;
}

$customers = db()->query('SELECT id, name, credit_limit FROM customers ORDER BY name')->fetchAll();
$customerCreditLimits = [];
foreach ($customers as $c) {
    $customerCreditLimits[(int)$c['id']] = $c['credit_limit'] !== null ? (float)$c['credit_limit'] : null;
}
$products = db()->query("SELECT p.id, p.sku, p.name, p.selling_price, p.quantity, p.unit, c.name category_name FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.status='active' ORDER BY p.name")->fetchAll();
$warehouses = leaf_warehouses();
$priceLists = db()->query("SELECT id, name, currency FROM price_lists WHERE status='active' ORDER BY is_default DESC, name")->fetchAll();
$salesUsers = db()->query("SELECT id, name FROM users WHERE status='active' ORDER BY name")->fetchAll();
$uoms = db()->query("SELECT name FROM uom WHERE status='active' ORDER BY name")->fetchAll();

$addressesByCustomer = [];
$addrStmt = db()->query('SELECT id, customer_id, label, address_line, city, state, pincode, contact_person, contact_phone, contact_email, is_default FROM customer_addresses ORDER BY is_default DESC, label');
foreach ($addrStmt as $a) {
    $addressesByCustomer[(int)$a['customer_id']][] = [
        'id' => (int)$a['id'],
        'text' => $a['label'] . ': ' . $a['address_line'] . ($a['city'] ? ', ' . $a['city'] : ''),
        'address_line' => $a['address_line'], 'city' => $a['city'], 'state' => $a['state'], 'pincode' => $a['pincode'],
        'contact_person' => $a['contact_person'], 'contact_phone' => $a['contact_phone'], 'contact_email' => $a['contact_email'],
    ];
}

$shippingPartners = db()->query("SELECT id, name FROM shipping_partners WHERE status='active' ORDER BY name")->fetchAll();

$paymentTermsTemplates = db()->query("SELECT id, name FROM payment_terms_templates WHERE status='active' ORDER BY name")->fetchAll();
$paymentTermsTemplateRows = [];
$pttStmt = db()->query('SELECT template_id, due_on, days_from, payment_type, percentage, remarks FROM payment_terms_template_items ORDER BY template_id, sort_order, id');
foreach ($pttStmt as $r) {
    $paymentTermsTemplateRows[(int)$r['template_id']][] = [
        'due_on' => $r['due_on'], 'days_from' => (int)$r['days_from'], 'payment_type' => $r['payment_type'],
        'percentage' => (float)$r['percentage'], 'remarks' => $r['remarks'],
    ];
}

$warehouseNames = [];
foreach ($warehouses as $w) {
    $warehouseNames[(int)$w['id']] = $w['name'];
}

$quotationsByCustomer = [];
$qStmt = db()->query("SELECT id, customer_id, quotation_no FROM quotations WHERE status IN ('sent','accepted') ORDER BY id DESC");
foreach ($qStmt as $q) {
    $quotationsByCustomer[(int)$q['customer_id']][] = ['id' => (int)$q['id'], 'text' => $q['quotation_no']];
}
if ($order['quotation_id']) {
    $stmt = db()->prepare('SELECT quotation_no FROM quotations WHERE id = ?');
    $stmt->execute([$order['quotation_id']]);
    $currentQuotationNo = $stmt->fetchColumn();
} else {
    $currentQuotationNo = null;
}

$priceListRates = [];
$plRateStmt = db()->query('SELECT price_list_id, product_id, rate FROM price_list_items');
foreach ($plRateStmt as $r) {
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
    $productMeta[(int)$p['id']] = [
        'sku' => $p['sku'], 'name' => $p['name'], 'unit' => $p['unit'],
        'category' => $p['category_name'] ?: '', 'stock' => (int)$p['quantity'], 'rate' => (float)$p['selling_price'],
        'uoms' => $uomMap,
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
    <li class="nav-item"><button class="nav-link <?= $activeTab === 'shipping' ? 'active' : '' ?>" id="tab-shipping" data-bs-toggle="tab" data-bs-target="#pane-shipping" type="button">Shipping &amp; Delivery</button></li>
    <li class="nav-item"><button class="nav-link <?= $activeTab === 'payment' ? 'active' : '' ?>" id="tab-payment" data-bs-toggle="tab" data-bs-target="#pane-payment" type="button">Payment Terms</button></li>
    <li class="nav-item"><button class="nav-link <?= $activeTab === 'more' ? 'active' : '' ?>" id="tab-more" data-bs-toggle="tab" data-bs-target="#pane-more" type="button">More Info</button></li>
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
          <div class="col-sm-3">
            <label class="form-label">Reference Quotation</label>
            <select name="quotation_id" id="quotationSelect" class="form-select">
              <option value="">— None —</option>
              <?php if ($order['quotation_id'] && $currentQuotationNo): ?>
                <option value="<?= (int)$order['quotation_id'] ?>" selected><?= e($currentQuotationNo) ?></option>
              <?php endif; ?>
            </select>
          </div>
          <div class="col-sm-6">
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
                      <?php $rowUoms = $it['product_id'] && isset($productMeta[(int)$it['product_id']]) ? $productMeta[(int)$it['product_id']]['uoms'] : ['pcs' => 1.0]; ?>
                      <?php foreach ($rowUoms as $uomName => $factor): ?>
                        <option value="<?= e($uomName) ?>" <?= (string)($it['uom'] ?? 'pcs') === (string)$uomName ? 'selected' : '' ?>><?= e($uomName) ?></option>
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

      <div class="tab-pane fade <?= $activeTab === 'shipping' ? 'show active' : '' ?>" id="pane-shipping">
        <div class="row g-3 mb-3">
          <div class="col-lg-6">
            <div class="card p-3 h-100">
              <h6 class="mb-3"><i class="fa-solid fa-truck"></i> Delivery Information</h6>
              <div class="row g-2 mb-2">
                <div class="col-sm-6">
                  <label class="form-label">Promised Delivery Date</label>
                  <input type="date" name="promised_delivery_date" class="form-control" value="<?= e($order['promised_delivery_date'] ?? '') ?>">
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
                  <label class="form-label">Fulfillment Type</label>
                  <select name="fulfillment_type" class="form-select">
                    <option value="complete_order" <?= $order['fulfillment_type'] === 'complete_order' ? 'selected' : '' ?>>Complete Order</option>
                    <option value="partial_allowed" <?= $order['fulfillment_type'] === 'partial_allowed' ? 'selected' : '' ?>>Partial Allowed</option>
                  </select>
                </div>
                <div class="col-sm-6">
                  <label class="form-label">Delivery Terms (Incoterms)</label>
                  <select name="delivery_terms" class="form-select">
                    <option value="">— Select incoterms —</option>
                    <?php foreach (['EXW', 'FOB', 'CIF', 'DAP', 'DDP', 'CPT'] as $term): ?>
                      <option value="<?= $term ?>" <?= $order['delivery_terms'] === $term ? 'selected' : '' ?>><?= $term ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
              <div class="mb-2">
                <label class="form-label">Shipping Rule</label>
                <select name="shipping_rule" class="form-select">
                  <option value="">— Select shipping rule —</option>
                  <?php foreach (['As per Stock Availability', 'Ship Complete Only', 'Ship as Available'] as $rule): ?>
                    <option value="<?= e($rule) ?>" <?= $order['shipping_rule'] === $rule ? 'selected' : '' ?>><?= e($rule) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label class="form-label">Remarks (Delivery)</label>
                <textarea name="delivery_remarks" class="form-control" rows="2"><?= e($order['delivery_remarks'] ?? '') ?></textarea>
              </div>
            </div>
          </div>
          <div class="col-lg-6">
            <div class="card p-3 h-100">
              <h6 class="mb-3"><i class="fa-solid fa-location-dot"></i> Shipping Address</h6>
              <div class="mb-2">
                <label class="form-label">Ship To</label>
                <select name="ship_to_address_id" id="shipToSelect" class="form-select">
                  <option value="">— Select address —</option>
                </select>
              </div>
              <div id="shipToPreview" class="p-2 rounded bg-light-subtle border mb-2 small text-muted">Select an address to see its details.</div>
              <div class="row g-2">
                <div class="col-sm-4">
                  <label class="form-label">Contact Person</label>
                  <input type="text" id="shipToContactPerson" class="form-control form-control-sm" disabled>
                </div>
                <div class="col-sm-4">
                  <label class="form-label">Contact Number</label>
                  <input type="text" id="shipToContactPhone" class="form-control form-control-sm" disabled>
                </div>
                <div class="col-sm-4">
                  <label class="form-label">Email</label>
                  <input type="text" id="shipToContactEmail" class="form-control form-control-sm" disabled>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="row g-3 mb-3">
          <div class="col-lg-6">
            <div class="card p-3 h-100">
              <h6 class="mb-3"><i class="fa-solid fa-box"></i> Shipping Details</h6>
              <div class="row g-2 mb-2">
                <div class="col-sm-4">
                  <label class="form-label">Shipping Partner / Courier</label>
                  <select name="shipping_partner_id" class="form-select">
                    <option value="">— Select —</option>
                    <?php foreach ($shippingPartners as $sp): ?>
                      <option value="<?= (int)$sp['id'] ?>" <?= (string)($order['shipping_partner_id'] ?? '') === (string)$sp['id'] ? 'selected' : '' ?>><?= e($sp['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-sm-4">
                  <label class="form-label">Service Type</label>
                  <select name="shipping_service_type" class="form-select">
                    <option value="">— Select —</option>
                    <?php foreach (['Surface', 'Air', 'Express'] as $svc): ?>
                      <option value="<?= $svc ?>" <?= $order['shipping_service_type'] === $svc ? 'selected' : '' ?>><?= $svc ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-sm-4">
                  <label class="form-label">Shipping Method</label>
                  <select name="shipping_method" class="form-select">
                    <option value="">— Select —</option>
                    <?php foreach (['Prepaid', 'COD'] as $m): ?>
                      <option value="<?= $m ?>" <?= $order['shipping_method'] === $m ? 'selected' : '' ?>><?= $m ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
              <div class="row g-2">
                <div class="col-sm-4">
                  <label class="form-label">Tracking No.</label>
                  <input type="text" name="tracking_no" class="form-control" value="<?= e($order['tracking_no'] ?? '') ?>">
                </div>
                <div class="col-sm-4">
                  <label class="form-label">Expected Dispatch Date</label>
                  <input type="date" name="expected_dispatch_date" class="form-control" value="<?= e($order['expected_dispatch_date'] ?? '') ?>">
                </div>
                <div class="col-sm-4">
                  <label class="form-label">Expected Delivery Date</label>
                  <input type="date" name="expected_delivery_date" class="form-control" value="<?= e($order['expected_delivery_date'] ?? '') ?>">
                </div>
              </div>
            </div>
          </div>
          <div class="col-lg-6">
            <div class="card p-3 h-100">
              <h6 class="mb-3"><i class="fa-solid fa-gear"></i> Additional Options</h6>
              <div class="row">
                <div class="col-sm-6">
                  <div class="form-check mb-2"><input type="checkbox" class="form-check-input" id="createDnChk" name="create_dn_after_submit" value="1" <?= !empty($order['create_dn_after_submit']) ? 'checked' : '' ?>><label class="form-check-label" for="createDnChk">Create Delivery Note after Submit</label></div>
                  <div class="form-check mb-2"><input type="checkbox" class="form-check-input" id="updStockChk" name="update_stock_on_submit" value="1" <?= !empty($order['update_stock_on_submit']) ? 'checked' : '' ?>><label class="form-check-label" for="updStockChk">Update Stock on Submit</label></div>
                  <div class="form-check mb-2"><input type="checkbox" class="form-check-input" id="partialChk" name="allow_partial_delivery" value="1" <?= !empty($order['allow_partial_delivery']) ? 'checked' : '' ?>><label class="form-check-label" for="partialChk">Allow Partial Delivery</label></div>
                  <div class="form-check mb-2"><input type="checkbox" class="form-check-input" id="notifyChk" name="notify_customer" value="1" <?= !empty($order['notify_customer']) ? 'checked' : '' ?>><label class="form-check-label" for="notifyChk">Notify Customer</label></div>
                </div>
                <div class="col-sm-6">
                  <div class="form-check mb-2"><input type="checkbox" class="form-check-input" id="pickChk" name="print_picking_list" value="1" <?= !empty($order['print_picking_list']) ? 'checked' : '' ?>><label class="form-check-label" for="pickChk">Print Picking List</label></div>
                  <div class="form-check mb-2"><input type="checkbox" class="form-check-input" id="labelChk" name="print_shipping_label" value="1" <?= !empty($order['print_shipping_label']) ? 'checked' : '' ?>><label class="form-check-label" for="labelChk">Print Shipping Label</label></div>
                  <div class="form-check mb-2"><input type="checkbox" class="form-check-input" id="inclShipChk" name="include_shipping_in_total" value="1" <?= !empty($order['include_shipping_in_total']) ? 'checked' : '' ?>><label class="form-check-label" for="inclShipChk">Include Shipping Charges in Order Total</label></div>
                </div>
              </div>
              <div class="alert alert-secondary small mb-0 mt-2">Delivery notes and shipments are still created manually from the order view — these checkboxes save your intent, ready for automation in a later update.</div>
            </div>
          </div>
        </div>

        <div class="card p-3">
          <h6 class="mb-2">Delivery Items</h6>
          <div class="table-responsive">
            <table class="table table-sm">
              <thead><tr><th>Item Code</th><th>Item Name</th><th>Warehouse</th><th class="text-end">Qty Ordered</th><th class="text-end">Qty to Deliver</th><th>UOM</th><th class="text-end">Pending Qty</th></tr></thead>
              <tbody>
              <?php foreach ($items as $it): $p = $productMeta[(int)($it['product_id'] ?? 0)] ?? null; ?>
                <?php if (!$p) continue; ?>
                <tr>
                  <td><?= e($p['sku']) ?></td>
                  <td><?= e($p['name']) ?></td>
                  <td><?= e($warehouseNames[(int)($it['warehouse_id'] ?? 0)] ?? '—') ?></td>
                  <td class="text-end"><?= (int)$it['quantity'] ?></td>
                  <td class="text-end"><?= (int)$it['quantity'] ?></td>
                  <td><?= e($it['uom'] ?? 'pcs') ?></td>
                  <td class="text-end">0</td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$items): ?><tr><td colspan="7" class="text-muted text-center">Add items on the Items tab first.</td></tr><?php endif; ?>
              </tbody>
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
          <div class="col-lg-6">
            <div class="card p-3 h-100">
              <h6 class="mb-3">General Terms</h6>
              <div class="row g-2 mb-2">
                <div class="col-sm-7">
                  <label class="form-label">Payment Terms</label>
                  <input type="text" name="payment_terms" class="form-control" placeholder="e.g. 30% Advance, 70% Within 30 Days" value="<?= e($order['payment_terms'] ?? '') ?>">
                </div>
                <div class="col-sm-5">
                  <label class="form-label">Credit Limit</label>
                  <input type="text" id="creditLimitDisplay" class="form-control" value="<?= $order['customer_id'] && isset($customerCreditLimits[(int)$order['customer_id']]) && $customerCreditLimits[(int)$order['customer_id']] !== null ? money($customerCreditLimits[(int)$order['customer_id']]) : '—' ?>" disabled>
                </div>
              </div>
              <div class="row g-2 mb-2">
                <div class="col-sm-6">
                  <label class="form-label">Payment Method</label>
                  <select name="payment_method" class="form-select">
                    <option value="">— Select —</option>
                    <?php foreach (['Bank Transfer', 'Cash', 'Cheque', 'UPI', 'Card'] as $pm): ?>
                      <option value="<?= $pm ?>" <?= $order['payment_method'] === $pm ? 'selected' : '' ?>><?= $pm ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-sm-6">
                  <label class="form-label">Payment Due Date Basis</label>
                  <select name="payment_due_date_basis" class="form-select">
                    <option value="against_delivery" <?= $order['payment_due_date_basis'] === 'against_delivery' ? 'selected' : '' ?>>Against Delivery</option>
                    <option value="against_order_date" <?= $order['payment_due_date_basis'] === 'against_order_date' ? 'selected' : '' ?>>Against Order Date</option>
                    <option value="fixed_date" <?= $order['payment_due_date_basis'] === 'fixed_date' ? 'selected' : '' ?>>Fixed Date</option>
                  </select>
                </div>
              </div>
              <div>
                <label class="form-label">Payment Instructions (for customer)</label>
                <textarea name="payment_instructions" class="form-control" rows="3"><?= e($order['payment_instructions'] ?? '') ?></textarea>
              </div>
            </div>
          </div>
          <div class="col-lg-6">
            <div class="card p-3 h-100">
              <h6 class="mb-3">Payment Schedule <span class="text-muted small fw-normal">— % of order total, must total 100</span></h6>
              <div class="table-responsive mb-2">
                <table class="table table-sm">
                  <thead><tr><th>Due On</th><th style="width:70px">Days</th><th>Type</th><th style="width:90px">%</th><th class="text-end" style="width:100px">Amount</th><th></th></tr></thead>
                  <tbody class="pts-tbody">
                  <?php if (!$paymentSchedule): $paymentSchedule = []; endif; ?>
                  <?php foreach ($paymentSchedule as $ps): ?>
                    <tr data-row>
                      <td>
                        <select class="form-select form-select-sm pts-due-on" name="sched_due_on[]">
                          <option value="order_date" <?= $ps['due_on'] === 'order_date' ? 'selected' : '' ?>>Order Date</option>
                          <option value="on_delivery" <?= $ps['due_on'] === 'on_delivery' ? 'selected' : '' ?>>Delivery</option>
                          <option value="fixed_days" <?= $ps['due_on'] === 'fixed_days' ? 'selected' : '' ?>>Fixed Days</option>
                        </select>
                      </td>
                      <td><input type="number" min="0" class="form-control form-control-sm" name="sched_days_from[]" value="<?= e($ps['days_from']) ?>"></td>
                      <td>
                        <select class="form-select form-select-sm" name="sched_payment_type[]">
                          <option value="advance" <?= $ps['payment_type'] === 'advance' ? 'selected' : '' ?>>Advance</option>
                          <option value="part_payment" <?= $ps['payment_type'] === 'part_payment' ? 'selected' : '' ?>>Part Payment</option>
                          <option value="balance" <?= $ps['payment_type'] === 'balance' ? 'selected' : '' ?>>Balance</option>
                        </select>
                      </td>
                      <td><input type="number" step="0.01" min="0" max="100" class="form-control form-control-sm pts-percentage" name="sched_percentage[]" value="<?= e($ps['percentage']) ?>"></td>
                      <td class="text-end pts-amount"><?= number_format((float)($ps['amount'] ?? 0), 2) ?></td>
                      <td><button type="button" class="btn btn-sm btn-outline-danger pts-remove-row"><i class="fa-solid fa-xmark"></i></button></td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (!$paymentSchedule): ?>
                    <tr data-row>
                      <td>
                        <select class="form-select form-select-sm pts-due-on" name="sched_due_on[]">
                          <option value="order_date">Order Date</option>
                          <option value="on_delivery">Delivery</option>
                          <option value="fixed_days">Fixed Days</option>
                        </select>
                      </td>
                      <td><input type="number" min="0" class="form-control form-control-sm" name="sched_days_from[]" value="0"></td>
                      <td>
                        <select class="form-select form-select-sm" name="sched_payment_type[]">
                          <option value="advance">Advance</option>
                          <option value="part_payment">Part Payment</option>
                          <option value="balance" selected>Balance</option>
                        </select>
                      </td>
                      <td><input type="number" step="0.01" min="0" max="100" class="form-control form-control-sm pts-percentage" name="sched_percentage[]" value="100"></td>
                      <td class="text-end pts-amount">0.00</td>
                      <td><button type="button" class="btn btn-sm btn-outline-danger pts-remove-row"><i class="fa-solid fa-xmark"></i></button></td>
                    </tr>
                  <?php endif; ?>
                  </tbody>
                </table>
              </div>
              <div class="d-flex justify-content-between align-items-center">
                <button type="button" class="btn btn-sm btn-outline-brand pts-add-row"><i class="fa-solid fa-plus"></i> Add Payment Term</button>
                <div class="small">Total: <strong id="ptsPercentTotal">0</strong>% · <strong id="ptsAmountTotal">0.00</strong></div>
              </div>
            </div>
          </div>
        </div>

        <div class="row g-3 mb-3">
          <div class="col-lg-4">
            <div class="card p-3 h-100">
              <h6 class="mb-3">Advance Payment</h6>
              <div class="form-check form-switch mb-2">
                <input type="checkbox" class="form-check-input" id="requireAdvanceChk" name="require_advance_payment" value="1" <?= !empty($order['require_advance_payment']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="requireAdvanceChk">Require Advance Payment?</label>
              </div>
              <div class="row g-2 mb-2">
                <div class="col-sm-6">
                  <label class="form-label">Advance %</label>
                  <input type="number" step="0.01" min="0" max="100" name="advance_percentage" id="advancePercentageInput" class="form-control" value="<?= e($order['advance_percentage'] ?? 0) ?>">
                </div>
                <div class="col-sm-6">
                  <label class="form-label">Advance Amount</label>
                  <input type="text" id="advanceAmountDisplay" class="form-control" disabled value="0.00">
                </div>
              </div>
              <div>
                <label class="form-label">Advance Valid Till</label>
                <input type="date" name="advance_valid_till" class="form-control" value="<?= e($order['advance_valid_till'] ?? '') ?>">
              </div>
            </div>
          </div>
          <div class="col-lg-4">
            <div class="card p-3 h-100">
              <h6 class="mb-3">Late Payment</h6>
              <div class="form-check form-switch mb-2">
                <input type="checkbox" class="form-check-input" id="lateInterestChk" name="interest_on_late_payment" value="1" <?= !empty($order['interest_on_late_payment']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="lateInterestChk">Interest on Late Payment</label>
              </div>
              <div class="row g-2 mb-2">
                <div class="col-sm-6">
                  <label class="form-label">Interest Rate %</label>
                  <input type="number" step="0.01" min="0" name="late_interest_rate" class="form-control" value="<?= e($order['late_interest_rate'] ?? 0) ?>">
                </div>
                <div class="col-sm-6">
                  <label class="form-label">Grace Period (Days)</label>
                  <input type="number" min="0" name="late_grace_period_days" class="form-control" value="<?= e($order['late_grace_period_days'] ?? 0) ?>">
                </div>
              </div>
              <div>
                <label class="form-label">Late Payment Terms</label>
                <textarea name="late_payment_terms" class="form-control" rows="2"><?= e($order['late_payment_terms'] ?? '') ?></textarea>
              </div>
            </div>
          </div>
          <div class="col-lg-4">
            <div class="card p-3 h-100">
              <h6 class="mb-3">Additional Information</h6>
              <div class="mb-2">
                <label class="form-label">Payment Reference</label>
                <input type="text" name="payment_reference" class="form-control" value="<?= e($order['payment_reference'] ?? '') ?>">
              </div>
              <div class="mb-2">
                <label class="form-label">Special Terms</label>
                <textarea name="special_payment_terms" class="form-control" rows="2"><?= e($order['special_payment_terms'] ?? '') ?></textarea>
              </div>
              <div class="form-check mb-1">
                <input type="checkbox" class="form-check-input" id="allowPartialPayChk" name="allow_partial_payments" value="1" <?= !empty($order['allow_partial_payments']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="allowPartialPayChk">Allow Partial Payments</label>
              </div>
              <div class="form-check">
                <input type="checkbox" class="form-check-input" id="sendReminderChk" name="send_payment_reminder" value="1" <?= !empty($order['send_payment_reminder']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="sendReminderChk">Send Payment Reminder to Customer</label>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="tab-pane fade <?= $activeTab === 'more' ? 'show active' : '' ?>" id="pane-more">
        <div class="row g-3">
          <div class="col-lg-6">
            <div class="card p-3 mb-3">
              <h6 class="mb-3">Reference Information</h6>
              <div class="row g-3">
                <div class="col-sm-6">
                  <label class="form-label">Opportunity</label>
                  <input type="text" name="opportunity" class="form-control" value="<?= e($order['opportunity'] ?? '') ?>">
                </div>
                <div class="col-sm-6">
                  <label class="form-label">Customer PO Date</label>
                  <input type="date" name="customer_po_date" class="form-control" value="<?= e($order['customer_po_date'] ?? '') ?>">
                </div>
                <div class="col-sm-12">
                  <label class="form-label">Campaign / Source</label>
                  <input type="text" name="campaign_source" class="form-control" value="<?= e($order['campaign_source'] ?? '') ?>">
                </div>
              </div>
            </div>
            <div class="card p-3 mb-3">
              <h6 class="mb-3">Additional Classification</h6>
              <div class="row g-3">
                <div class="col-sm-6">
                  <label class="form-label">Sales Group</label>
                  <input type="text" name="sales_group" class="form-control" value="<?= e($order['sales_group'] ?? '') ?>">
                </div>
                <div class="col-sm-6">
                  <label class="form-label">Sales Office</label>
                  <input type="text" name="sales_office" class="form-control" value="<?= e($order['sales_office'] ?? '') ?>">
                </div>
                <div class="col-sm-6">
                  <label class="form-label">Cost Center</label>
                  <input type="text" name="cost_center" class="form-control" value="<?= e($order['cost_center'] ?? '') ?>">
                </div>
                <div class="col-sm-6">
                  <label class="form-label">Business Unit</label>
                  <input type="text" name="business_unit" class="form-control" value="<?= e($order['business_unit'] ?? '') ?>">
                </div>
              </div>
            </div>
            <div class="card p-3 mb-3">
              <h6 class="mb-3">Internal Information</h6>
              <div class="row g-3">
                <div class="col-sm-6">
                  <label class="form-label">Valid Till</label>
                  <input type="date" name="valid_till" class="form-control" value="<?= e($order['valid_till'] ?? '') ?>">
                </div>
                <div class="col-sm-6">
                  <label class="form-label">Order Type</label>
                  <select name="order_type" class="form-select">
                    <?php foreach (['Standard Order', 'Maintenance Order', 'Shopping Cart Order'] as $ot): ?>
                      <option value="<?= e($ot) ?>" <?= ($order['order_type'] ?: 'Standard Order') === $ot ? 'selected' : '' ?>><?= e($ot) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-sm-12">
                  <label class="form-label">Tags</label>
                  <input type="text" name="tags" class="form-control" placeholder="Comma-separated" value="<?= e($order['tags'] ?? '') ?>">
                </div>
                <div class="col-sm-12">
                  <label class="form-label">Remarks (Internal)</label>
                  <textarea name="remarks_internal" class="form-control" rows="2"><?= e($order['remarks_internal'] ?? '') ?></textarea>
                </div>
              </div>
            </div>
          </div>
          <div class="col-lg-6">
            <div class="card p-3 mb-3">
              <h6 class="mb-3">Sales Team</h6>
              <div class="table-responsive">
                <table class="table table-sm so-team-rows">
                  <thead><tr><th style="width:45%">Sales Person</th><th style="width:30%">Role</th><th style="width:18%">Commission %</th><th></th></tr></thead>
                  <tbody>
                  <?php if (!$salesTeam): $salesTeam = [['sales_person_id' => '', 'role' => '', 'commission_percent' => 0]]; endif; ?>
                  <?php foreach ($salesTeam as $st): ?>
                    <tr data-row>
                      <td>
                        <select class="form-select form-select-sm" name="team_sales_person_id[]">
                          <option value="">— Select —</option>
                          <?php foreach ($salesUsers as $u): ?>
                            <option value="<?= (int)$u['id'] ?>" <?= (string)($st['sales_person_id'] ?? '') === (string)$u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </td>
                      <td><input type="text" class="form-control form-control-sm" name="team_role[]" value="<?= e($st['role'] ?? '') ?>"></td>
                      <td><input type="number" step="0.01" min="0" max="100" class="form-control form-control-sm" name="team_commission_percent[]" value="<?= e($st['commission_percent'] ?? 0) ?>"></td>
                      <td><button type="button" class="btn btn-sm btn-outline-danger so-team-remove-row"><i class="fa-solid fa-xmark"></i></button></td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <button type="button" class="btn btn-sm btn-outline-brand so-team-add-row"><i class="fa-solid fa-plus"></i> Add row</button>
            </div>
            <div class="card p-3 mb-3">
              <h6 class="mb-3">Custom Fields</h6>
              <div class="row g-3">
                <div class="col-sm-6">
                  <label class="form-label">End Customer</label>
                  <input type="text" name="end_customer" class="form-control" value="<?= e($order['end_customer'] ?? '') ?>">
                </div>
                <div class="col-sm-6">
                  <label class="form-label">Channel Partner</label>
                  <input type="text" name="channel_partner" class="form-control" value="<?= e($order['channel_partner'] ?? '') ?>">
                </div>
                <div class="col-sm-6">
                  <label class="form-label">Deal Registration No.</label>
                  <input type="text" name="deal_registration_no" class="form-control" value="<?= e($order['deal_registration_no'] ?? '') ?>">
                </div>
                <div class="col-sm-6">
                  <label class="form-label">Market Segment</label>
                  <input type="text" name="market_segment" class="form-control" value="<?= e($order['market_segment'] ?? '') ?>">
                </div>
                <div class="col-sm-6">
                  <label class="form-label">Region</label>
                  <input type="text" name="region" class="form-control" value="<?= e($order['region'] ?? '') ?>">
                </div>
                <div class="col-sm-6">
                  <label class="form-label">Expected Close Date</label>
                  <input type="date" name="expected_close_date" class="form-control" value="<?= e($order['expected_close_date'] ?? '') ?>">
                </div>
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
var quotationsByCustomer = " . json_encode($quotationsByCustomer) . ";
var selectedQuotationId = " . json_encode((string)($order['quotation_id'] ?? '')) . ";
var selectedAddressId = " . json_encode((string)($order['customer_address_id'] ?? '')) . ";
var selectedShipToId = " . json_encode((string)($order['ship_to_address_id'] ?? '')) . ";
var currentCustomerId = " . json_encode((string)($order['customer_id'] ?? '')) . ";

function rateFor(productId, priceListId) {
  if (priceListRates[priceListId] && priceListRates[priceListId][productId] !== undefined) {
    return priceListRates[priceListId][productId];
  }
  return productMeta[productId] ? productMeta[productId].rate : 0;
}

function populateAddressSelect(selectEl, customerId, selectId) {
  if (!selectEl) return;
  selectEl.innerHTML = '<option value=\"\">— Select address —</option>';
  var list = addressesByCustomer[customerId] || [];
  list.forEach(function (a) {
    var opt = document.createElement('option');
    opt.value = a.id;
    opt.textContent = a.text;
    if (selectId && String(a.id) === String(selectId)) opt.selected = true;
    selectEl.appendChild(opt);
  });
}

function populateQuotationSelect(customerId, selectId) {
  var sel = document.getElementById('quotationSelect');
  if (!sel) return;
  sel.innerHTML = '<option value=\"\">— None —</option>';
  var list = quotationsByCustomer[customerId] || [];
  list.forEach(function (q) {
    var opt = document.createElement('option');
    opt.value = q.id;
    opt.textContent = q.text;
    if (selectId && String(q.id) === String(selectId)) opt.selected = true;
    sel.appendChild(opt);
  });
}

function updateShipToPreview() {
  var sel = document.getElementById('shipToSelect');
  var preview = document.getElementById('shipToPreview');
  if (!sel || !preview) return;
  var list = addressesByCustomer[currentCustomerId] || [];
  var addr = list.filter(function (a) { return String(a.id) === String(sel.value); })[0];
  document.getElementById('shipToContactPerson').value = addr ? (addr.contact_person || '') : '';
  document.getElementById('shipToContactPhone').value = addr ? (addr.contact_phone || '') : '';
  document.getElementById('shipToContactEmail').value = addr ? (addr.contact_email || '') : '';
  if (!addr) { preview.textContent = 'Select an address to see its details.'; return; }
  preview.textContent = addr.address_line + (addr.city ? ', ' + addr.city : '') + (addr.state ? ', ' + addr.state : '') + (addr.pincode ? ' - ' + addr.pincode : '');
}

document.addEventListener('DOMContentLoaded', function () {
  var customerSelect = document.getElementById('customerSelect');
  var addressSelect = document.getElementById('addressSelect');
  var shipToSelect = document.getElementById('shipToSelect');
  if (customerSelect) {
    populateAddressSelect(addressSelect, customerSelect.value, selectedAddressId);
    populateAddressSelect(shipToSelect, customerSelect.value, selectedShipToId);
    populateQuotationSelect(customerSelect.value, selectedQuotationId);
    updateShipToPreview();
    currentCustomerId = customerSelect.value;
    customerSelect.addEventListener('change', function () {
      currentCustomerId = customerSelect.value;
      populateAddressSelect(addressSelect, customerSelect.value, null);
      populateAddressSelect(shipToSelect, customerSelect.value, null);
      populateQuotationSelect(customerSelect.value, null);
      updateShipToPreview();
    });
  }
  if (shipToSelect) {
    shipToSelect.addEventListener('change', updateShipToPreview);
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
      uomSelect.innerHTML = '';
      Object.keys(meta.uoms || {}).forEach(function (name) {
        var o = document.createElement('option');
        o.value = name;
        o.textContent = name;
        uomSelect.appendChild(o);
      });
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
    window.soGrandTotal = grand;
    if (window.soRecalcPaymentSchedule) window.soRecalcPaymentSchedule();
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

var paymentTermsTemplateRows = " . json_encode($paymentTermsTemplateRows) . ";
var customerCreditLimits = " . json_encode($customerCreditLimits) . ";

document.addEventListener('DOMContentLoaded', function () {
  var wrap = document.querySelector('.pts-tbody');
  if (!wrap) return;

  window.soRecalcPaymentSchedule = function () {
    var grand = window.soGrandTotal || 0;
    var pctTotal = 0, amtTotal = 0;
    wrap.querySelectorAll('tr[data-row]').forEach(function (row) {
      var pct = parseFloat(row.querySelector('.pts-percentage')?.value || 0) || 0;
      var amt = grand * pct / 100;
      var amtEl = row.querySelector('.pts-amount');
      if (amtEl) amtEl.textContent = amt.toFixed(2);
      pctTotal += pct;
      amtTotal += amt;
    });
    document.getElementById('ptsPercentTotal').textContent = pctTotal.toFixed(2).replace(/\\.00$/, '');
    document.getElementById('ptsAmountTotal').textContent = amtTotal.toFixed(2);

    var advancePct = parseFloat(document.getElementById('advancePercentageInput')?.value || 0) || 0;
    var advanceAmountEl = document.getElementById('advanceAmountDisplay');
    if (advanceAmountEl) advanceAmountEl.value = (grand * advancePct / 100).toFixed(2);
  };

  wrap.addEventListener('input', window.soRecalcPaymentSchedule);
  wrap.addEventListener('change', window.soRecalcPaymentSchedule);

  var advanceInput = document.getElementById('advancePercentageInput');
  if (advanceInput) advanceInput.addEventListener('input', window.soRecalcPaymentSchedule);

  wrap.closest('.card').addEventListener('click', function (e) {
    if (e.target.closest('.pts-add-row')) {
      var rows = wrap.querySelectorAll('tr[data-row]');
      var clone = rows[rows.length - 1].cloneNode(true);
      clone.querySelectorAll('input').forEach(function (inp) { inp.value = inp.classList.contains('pts-percentage') ? 0 : 0; });
      clone.querySelectorAll('select').forEach(function (sel) { sel.selectedIndex = 0; });
      clone.querySelector('.pts-amount').textContent = '0.00';
      wrap.appendChild(clone);
      window.soRecalcPaymentSchedule();
      return;
    }
    var rm = e.target.closest('.pts-remove-row');
    if (rm) {
      var rows2 = wrap.querySelectorAll('tr[data-row]');
      if (rows2.length > 1) {
        rm.closest('tr[data-row]').remove();
        window.soRecalcPaymentSchedule();
      }
    }
  });

  var applyBtn = document.getElementById('applyPaymentTemplateBtn');
  if (applyBtn) {
    applyBtn.addEventListener('click', function () {
      var ptId = document.getElementById('paymentTermsTemplateSelect').value;
      document.getElementById('paymentTermsTemplateIdInput').value = ptId;
      var rows = paymentTermsTemplateRows[ptId] || [];
      wrap.innerHTML = '';
      rows.forEach(function (r) {
        var tr = document.createElement('tr');
        tr.setAttribute('data-row', '');
        tr.innerHTML = '<td><select class=\"form-select form-select-sm pts-due-on\" name=\"sched_due_on[]\"><option value=\"order_date\">Order Date</option><option value=\"on_delivery\">Delivery</option><option value=\"fixed_days\">Fixed Days</option></select></td>' +
          '<td><input type=\"number\" min=\"0\" class=\"form-control form-control-sm\" name=\"sched_days_from[]\"></td>' +
          '<td><select class=\"form-select form-select-sm\" name=\"sched_payment_type[]\"><option value=\"advance\">Advance</option><option value=\"part_payment\">Part Payment</option><option value=\"balance\">Balance</option></select></td>' +
          '<td><input type=\"number\" step=\"0.01\" min=\"0\" max=\"100\" class=\"form-control form-control-sm pts-percentage\" name=\"sched_percentage[]\"></td>' +
          '<td class=\"text-end pts-amount\">0.00</td>' +
          '<td><button type=\"button\" class=\"btn btn-sm btn-outline-danger pts-remove-row\"><i class=\"fa-solid fa-xmark\"></i></button></td>';
        tr.querySelector('.pts-due-on').value = r.due_on;
        tr.querySelector('input[name=\"sched_days_from[]\"]').value = r.days_from;
        tr.querySelector('select[name=\"sched_payment_type[]\"]').value = r.payment_type;
        tr.querySelector('.pts-percentage').value = r.percentage;
        wrap.appendChild(tr);
      });
      if (!rows.length) {
        var tr2 = document.createElement('tr');
        tr2.setAttribute('data-row', '');
        tr2.innerHTML = '<td><select class=\"form-select form-select-sm pts-due-on\" name=\"sched_due_on[]\"><option value=\"order_date\">Order Date</option></select></td><td><input type=\"number\" class=\"form-control form-control-sm\" name=\"sched_days_from[]\" value=\"0\"></td><td><select class=\"form-select form-select-sm\" name=\"sched_payment_type[]\"><option value=\"balance\">Balance</option></select></td><td><input type=\"number\" class=\"form-control form-control-sm pts-percentage\" name=\"sched_percentage[]\" value=\"100\"></td><td class=\"text-end pts-amount\">0.00</td><td><button type=\"button\" class=\"btn btn-sm btn-outline-danger pts-remove-row\"><i class=\"fa-solid fa-xmark\"></i></button></td>';
        wrap.appendChild(tr2);
      }
      window.soRecalcPaymentSchedule();
    });
  }

  var customerSelect = document.getElementById('customerSelect');
  var creditLimitDisplay = document.getElementById('creditLimitDisplay');
  if (customerSelect && creditLimitDisplay) {
    customerSelect.addEventListener('change', function () {
      var limit = customerCreditLimits[customerSelect.value];
      creditLimitDisplay.value = (limit === undefined || limit === null) ? '—' : Number(limit).toFixed(2);
    });
  }

  window.soRecalcPaymentSchedule();
});

document.addEventListener('DOMContentLoaded', function () {
  var wrap = document.querySelector('.so-team-rows');
  if (!wrap) return;
  var tbody = wrap.querySelector('tbody');

  wrap.closest('.card').addEventListener('click', function (e) {
    if (e.target.closest('.so-team-add-row')) {
      var rows = tbody.querySelectorAll('tr[data-row]');
      var clone = rows[rows.length - 1].cloneNode(true);
      clone.querySelectorAll('input').forEach(function (inp) { inp.value = ''; });
      clone.querySelectorAll('select').forEach(function (sel) { sel.selectedIndex = 0; });
      tbody.appendChild(clone);
      return;
    }
    var rm = e.target.closest('.so-team-remove-row');
    if (rm) {
      var rows2 = tbody.querySelectorAll('tr[data-row]');
      if (rows2.length > 1) {
        rm.closest('tr[data-row]').remove();
      }
    }
  });
});
";
require __DIR__ . '/../includes/footer.php';
?>
