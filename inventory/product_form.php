<?php
require_once __DIR__ . '/../includes/auth.php';
require_module_edit('inventory');

$id = (int)input('id');
$product = [
    'id' => 0, 'sku' => '', 'name' => '', 'image' => null, 'category_id' => '', 'description' => '',
    'item_category_id' => '', 'brand_id' => '', 'hsn_sac_code' => '',
    'is_stock_item' => 1, 'stock_item_type' => 'Regular Stock Item', 'opening_stock_date' => today(), 'valuation_rate' => 0,
    'is_sales_item' => 1, 'is_purchase_item' => 1, 'is_manufactured_item' => 0,
    'is_sub_contracted_item' => 0, 'is_asset_item' => 0, 'has_variants' => 0,
    'unit' => 'pcs', 'purchase_uom' => '', 'sales_uom' => '',
    'purchase_uom_conversion_factor' => '1.000', 'sales_uom_conversion_factor' => '1.000',
    'default_warehouse_id' => '', 'default_bin' => '', 'issue_method' => 'FIFO', 'receipt_method' => 'FIFO',
    'allow_negative_stock' => 0, 'auto_create_batch_serial' => 0,
    'default_price_list_id' => '', 'min_stock_level' => 0, 'max_stock_level' => 0,
    'lead_time_days' => 0, 'shelf_life_days' => 0,
    'item_type' => 'Finished Good', 'valuation_method' => 'FIFO',
    'price_determination' => 'Based on Price List', 'last_purchase_rate' => 0, 'last_purchase_date' => '', 'average_purchase_rate' => 0,
    'allow_discount' => 1, 'max_discount_percent' => 0, 'discount_account_id' => '', 'apply_discount_on' => 'net_total', 'enable_additional_discount_sales' => 1,
    'price_last_updated_at' => null, 'price_updated_by' => null,
    'is_price_editable_in_transactions' => 1, 'include_in_price_suggestions' => 1, 'allow_zero_price' => 0, 'show_in_website' => 0,
    'minimum_selling_price' => 0, 'maximum_selling_price' => 0,
    'income_account_id' => '', 'cogs_account_id' => '', 'purchase_expense_account_id' => '', 'stock_in_hand_account_id' => '',
    'stock_adjustment_account_id' => '', 'under_over_valuation_account_id' => '', 'scrap_expense_account_id' => '', 'gain_loss_account_id' => '',
    'capitalization_threshold' => 0, 'include_in_period_closing_entry' => 1,
    'cost_center' => '', 'default_project' => '', 'activity_type' => '', 'budget' => '',
    'tax_category' => '', 'is_nil_rated' => 0, 'is_exempt_from_tax' => 0, 'reverse_charge_applicable' => 0, 'tds_applicable' => 0,
    'default_tax_template_id' => '', 'price_includes_tax' => 0, 'tax_calculation_based_on' => 'Net Amount',
    'tax_exemption_reason' => '', 'tax_exemption_applicable_from' => '', 'tax_notes' => '',
    'default_supplier_id' => '', 'default_purchase_price_list_id' => '',
    'purchase_min_order_qty' => 0, 'purchase_max_order_qty' => 0, 'purchase_order_qty_increment' => 1,
    'receipt_tolerance_percent' => 0, 'over_delivery_allowance_percent' => 0,
    'default_package_type' => '', 'items_per_package' => 1, 'purchase_description' => '',
    'requires_purchase_order' => 1, 'allow_receipt_without_po' => 0, 'track_supplier_batch_serial' => 0,
    'include_in_supplier_portal' => 0, 'is_drop_ship_item' => 0, 'allow_subcontracting' => 0, 'maintain_last_purchase_rate' => 0,
    'inspection_required' => 'No', 'sampling_rate_percent' => 0, 'quality_rating_default' => '', 'reject_if_quality_check_fails' => 0,
    'item_customer_group' => '', 'sales_min_order_qty' => 0, 'sales_max_order_qty' => 0, 'sales_order_qty_increment' => 1,
    'sales_lead_time_days' => 0, 'delivery_time_days' => 0, 'weight_for_shipping_kg' => 0,
    'sales_description' => '', 'marketing_material' => '', 'item_website' => '',
    'available_for_online_sales' => 1, 'available_for_retail_sales' => 1, 'available_for_b2b_sales' => 1,
    'not_discountable' => 0, 'requires_approval_for_discount' => 0, 'show_in_customer_portal' => 0,
    'default_monthly_sales_qty' => 0, 'seasonal_demand' => 'Normal', 'preferred_sales_warehouse_id' => '',
    'standard_weight_kg' => 0, 'standard_volume_ltr' => 0, 'gross_weight_kg' => 0, 'net_weight_kg' => 0, 'tags' => '',
    'cost_price' => '0', 'selling_price' => '0', 'quantity' => '0',
    'reorder_level' => '0', 'reorder_qty' => 0, 'safety_stock' => 0, 'enable_reorder_notifications' => 1, 'consider_in_mrp' => 1,
    'has_batch_no' => 0, 'has_serial_no' => 0, 'batch_expiry_required' => 0, 'batch_number_series' => '',
    'storage_section' => '', 'storage_rack' => '', 'storage_shelf' => '', 'storage_bin' => '',
    'track_stock_ageing' => 0, 'include_in_stock_report' => 1, 'allow_stock_transfer' => 1, 'is_kit_or_set' => 0,
    'use_alternative_item' => 0, 'restrict_warehouse' => 0, 'block_for_stock_transactions' => 0, 'exclude_from_inventory_valuation' => 0,
    'status' => 'active',
];
$barcodes = [];
$stockByWarehouse = [];
$priceListRates = [];
$customerPrices = [];
$productTaxes = [];
$productCharges = [];
$productSuppliers = [];
$productCustomerRules = [];
$productUoms = [];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM products WHERE id = ?');
    $stmt->execute([$id]);
    $product = $stmt->fetch() ?: $product;
    $stmt = db()->prepare('SELECT * FROM product_barcodes WHERE product_id = ? ORDER BY sort_order, id');
    $stmt->execute([$id]);
    $barcodes = $stmt->fetchAll();
    $stmt = db()->prepare("
      SELECT w.id, w.name, COALESCE(sb.quantity, 0) quantity
      FROM warehouses w
      LEFT JOIN stock_bins sb ON sb.warehouse_id = w.id AND sb.product_id = ?
      WHERE w.is_group = 0 AND w.status = 'active'
      ORDER BY w.name
    ");
    $stmt->execute([$id]);
    $stockByWarehouse = $stmt->fetchAll();
    $stmt = db()->prepare('SELECT price_list_id, rate FROM price_list_items WHERE product_id = ?');
    $stmt->execute([$id]);
    foreach ($stmt->fetchAll() as $r) {
        $priceListRates[(int)$r['price_list_id']] = $r['rate'];
    }
    $stmt = db()->prepare('SELECT * FROM product_customer_prices WHERE product_id = ? ORDER BY sort_order, id');
    $stmt->execute([$id]);
    $customerPrices = $stmt->fetchAll();
    $stmt = db()->prepare('SELECT * FROM product_taxes WHERE product_id = ? ORDER BY sort_order, id');
    $stmt->execute([$id]);
    $productTaxes = $stmt->fetchAll();
    $stmt = db()->prepare('SELECT * FROM product_charges WHERE product_id = ? ORDER BY sort_order, id');
    $stmt->execute([$id]);
    $productCharges = $stmt->fetchAll();
    $stmt = db()->prepare('SELECT * FROM product_suppliers WHERE product_id = ? ORDER BY sort_order, id');
    $stmt->execute([$id]);
    $productSuppliers = $stmt->fetchAll();
    $stmt = db()->prepare('SELECT * FROM product_customer_rules WHERE product_id = ? ORDER BY sort_order, id');
    $stmt->execute([$id]);
    $productCustomerRules = $stmt->fetchAll();
    $stmt = db()->prepare('SELECT * FROM product_uoms WHERE product_id = ? ORDER BY sort_order, id');
    $stmt->execute([$id]);
    $productUoms = $stmt->fetchAll();
}
// Captured before any POST handling touches $product — quantity and the
// current image are never taken from client input on an edit; quantity
// only ever changes via Stock Movements (which keeps stock_bins and the
// products.quantity aggregate consistent), and the image only changes
// via a new upload or the explicit "remove" checkbox below.
$existingQuantity = $id ? (int)$product['quantity'] : 0;
$existingImage = $id ? $product['image'] : null;

$activeTab = in_array(input('tab'), ['inventory', 'uom', 'pricing', 'accounting', 'tax', 'sales', 'purchase'], true) ? input('tab') : 'details';
$error = '';
$maxImageBytes = 3 * 1024 * 1024;
$mimeToExt = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

if (is_post()) {
    csrf_verify();
    $imagePath = $existingImage;

    if (!empty($_FILES['image']['name']) && ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['image'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $error = 'Image upload failed. Please try again.';
        } elseif ($file['size'] > $maxImageBytes) {
            $error = 'Image must be smaller than 3 MB.';
        } else {
            $info = @getimagesize($file['tmp_name']);
            if (!$info || !isset($mimeToExt[$info['mime']])) {
                $error = 'Please upload a valid JPG, PNG, WEBP, or GIF image.';
            } else {
                $destDir = __DIR__ . '/../uploads/products/';
                if (!is_dir($destDir)) mkdir($destDir, 0755, true);
                $filename = bin2hex(random_bytes(16)) . '.' . $mimeToExt[$info['mime']];
                if (move_uploaded_file($file['tmp_name'], $destDir . $filename)) {
                    if ($existingImage && is_file(__DIR__ . '/../' . $existingImage)) {
                        @unlink(__DIR__ . '/../' . $existingImage);
                    }
                    $imagePath = 'uploads/products/' . $filename;
                } else {
                    $error = 'Could not save the uploaded image.';
                }
            }
        }
    } elseif (input('remove_image') === '1') {
        if ($existingImage && is_file(__DIR__ . '/../' . $existingImage)) {
            @unlink(__DIR__ . '/../' . $existingImage);
        }
        $imagePath = null;
    }

    $openingQty = $id ? $existingQuantity : (int)input('opening_quantity');
    $openingWarehouseId = (int)input('opening_warehouse_id') ?: null;

    $product = [
        'id' => $id,
        'sku' => input('sku'),
        'name' => input('name'),
        'image' => $imagePath,
        'category_id' => input('category_id') ?: null,
        'description' => input('description') ?: null,
        'item_category_id' => input('item_category_id') ?: null,
        'brand_id' => input('brand_id') ?: null,
        'hsn_sac_code' => input('hsn_sac_code') ?: null,
        'is_stock_item' => input('is_stock_item') === '1' ? 1 : 0,
        'stock_item_type' => in_array(input('stock_item_type'), ['Regular Stock Item', 'Non-Stock Item', 'Fixed Asset Item'], true) ? input('stock_item_type') : 'Regular Stock Item',
        'opening_stock_date' => input('opening_stock_date') ?: null,
        'valuation_rate' => (float)input('valuation_rate'),
        'is_sales_item' => input('is_sales_item') === '1' ? 1 : 0,
        'is_purchase_item' => input('is_purchase_item') === '1' ? 1 : 0,
        'is_manufactured_item' => input('is_manufactured_item') === '1' ? 1 : 0,
        'is_sub_contracted_item' => input('is_sub_contracted_item') === '1' ? 1 : 0,
        'is_asset_item' => input('is_asset_item') === '1' ? 1 : 0,
        'has_variants' => input('has_variants') === '1' ? 1 : 0,
        'unit' => input('unit') ?: 'pcs',
        'purchase_uom' => input('purchase_uom') ?: null,
        'sales_uom' => input('sales_uom') ?: null,
        'purchase_uom_conversion_factor' => (float)input('purchase_uom_conversion_factor') ?: 1.0,
        'sales_uom_conversion_factor' => (float)input('sales_uom_conversion_factor') ?: 1.0,
        'default_warehouse_id' => input('default_warehouse_id') ?: null,
        'default_bin' => input('default_bin') ?: null,
        'issue_method' => in_array(input('issue_method'), ['FIFO', 'LIFO', 'Moving Average'], true) ? input('issue_method') : 'FIFO',
        'receipt_method' => in_array(input('receipt_method'), ['FIFO', 'LIFO', 'Moving Average'], true) ? input('receipt_method') : 'FIFO',
        'allow_negative_stock' => input('allow_negative_stock') === '1' ? 1 : 0,
        'auto_create_batch_serial' => input('auto_create_batch_serial') === '1' ? 1 : 0,
        'default_price_list_id' => input('default_price_list_id') ?: null,
        'min_stock_level' => (int)input('min_stock_level'),
        'max_stock_level' => (int)input('max_stock_level'),
        'lead_time_days' => (int)input('lead_time_days'),
        'shelf_life_days' => (int)input('shelf_life_days'),
        'item_type' => in_array(input('item_type'), ['Finished Good', 'Raw Material', 'Service Item', 'Consumable'], true) ? input('item_type') : 'Finished Good',
        'valuation_method' => in_array(input('valuation_method'), ['FIFO', 'LIFO', 'Moving Average'], true) ? input('valuation_method') : 'FIFO',
        'price_determination' => in_array(input('price_determination'), ['Based on Price List', 'Fixed Rate'], true) ? input('price_determination') : 'Based on Price List',
        'last_purchase_rate' => (float)input('last_purchase_rate'),
        'last_purchase_date' => input('last_purchase_date') ?: null,
        'average_purchase_rate' => (float)input('average_purchase_rate'),
        'allow_discount' => input('allow_discount') === '1' ? 1 : 0,
        'max_discount_percent' => (float)input('max_discount_percent'),
        'discount_account_id' => input('discount_account_id') ?: null,
        'apply_discount_on' => in_array(input('apply_discount_on'), ['net_total', 'grand_total'], true) ? input('apply_discount_on') : 'net_total',
        'enable_additional_discount_sales' => input('enable_additional_discount_sales') === '1' ? 1 : 0,
        'price_last_updated_at' => date('Y-m-d H:i:s'),
        'price_updated_by' => current_user()['id'],
        'is_price_editable_in_transactions' => input('is_price_editable_in_transactions') === '1' ? 1 : 0,
        'include_in_price_suggestions' => input('include_in_price_suggestions') === '1' ? 1 : 0,
        'allow_zero_price' => input('allow_zero_price') === '1' ? 1 : 0,
        'show_in_website' => input('show_in_website') === '1' ? 1 : 0,
        'minimum_selling_price' => (float)input('minimum_selling_price'),
        'maximum_selling_price' => (float)input('maximum_selling_price'),
        'income_account_id' => input('income_account_id') ?: null,
        'cogs_account_id' => input('cogs_account_id') ?: null,
        'purchase_expense_account_id' => input('purchase_expense_account_id') ?: null,
        'stock_in_hand_account_id' => input('stock_in_hand_account_id') ?: null,
        'stock_adjustment_account_id' => input('stock_adjustment_account_id') ?: null,
        'under_over_valuation_account_id' => input('under_over_valuation_account_id') ?: null,
        'scrap_expense_account_id' => input('scrap_expense_account_id') ?: null,
        'gain_loss_account_id' => input('gain_loss_account_id') ?: null,
        'capitalization_threshold' => (float)input('capitalization_threshold'),
        'include_in_period_closing_entry' => input('include_in_period_closing_entry') === '1' ? 1 : 0,
        'cost_center' => input('cost_center') ?: null,
        'default_project' => input('default_project') ?: null,
        'activity_type' => input('activity_type') ?: null,
        'budget' => input('budget') ?: null,
        'tax_category' => input('tax_category') ?: null,
        'is_nil_rated' => input('is_nil_rated') === '1' ? 1 : 0,
        'is_exempt_from_tax' => input('is_exempt_from_tax') === '1' ? 1 : 0,
        'reverse_charge_applicable' => input('reverse_charge_applicable') === '1' ? 1 : 0,
        'tds_applicable' => input('tds_applicable') === '1' ? 1 : 0,
        'default_tax_template_id' => input('default_tax_template_id') ?: null,
        'price_includes_tax' => input('price_includes_tax') === '1' ? 1 : 0,
        'tax_calculation_based_on' => in_array(input('tax_calculation_based_on'), ['Net Amount', 'Gross Amount'], true) ? input('tax_calculation_based_on') : 'Net Amount',
        'tax_exemption_reason' => input('tax_exemption_reason') ?: null,
        'tax_exemption_applicable_from' => input('tax_exemption_applicable_from') ?: null,
        'tax_notes' => input('tax_notes') ?: null,
        'default_supplier_id' => input('default_supplier_id') ?: null,
        'default_purchase_price_list_id' => input('default_purchase_price_list_id') ?: null,
        'purchase_min_order_qty' => (int)input('purchase_min_order_qty'),
        'purchase_max_order_qty' => (int)input('purchase_max_order_qty'),
        'purchase_order_qty_increment' => (int)input('purchase_order_qty_increment') ?: 1,
        'receipt_tolerance_percent' => (float)input('receipt_tolerance_percent'),
        'over_delivery_allowance_percent' => (float)input('over_delivery_allowance_percent'),
        'default_package_type' => input('default_package_type') ?: null,
        'items_per_package' => (int)input('items_per_package') ?: 1,
        'purchase_description' => input('purchase_description') ?: null,
        'requires_purchase_order' => input('requires_purchase_order') === '1' ? 1 : 0,
        'allow_receipt_without_po' => input('allow_receipt_without_po') === '1' ? 1 : 0,
        'track_supplier_batch_serial' => input('track_supplier_batch_serial') === '1' ? 1 : 0,
        'include_in_supplier_portal' => input('include_in_supplier_portal') === '1' ? 1 : 0,
        'is_drop_ship_item' => input('is_drop_ship_item') === '1' ? 1 : 0,
        'allow_subcontracting' => input('allow_subcontracting') === '1' ? 1 : 0,
        'maintain_last_purchase_rate' => input('maintain_last_purchase_rate') === '1' ? 1 : 0,
        'inspection_required' => in_array(input('inspection_required'), ['Yes', 'No'], true) ? input('inspection_required') : 'No',
        'sampling_rate_percent' => (float)input('sampling_rate_percent'),
        'quality_rating_default' => input('quality_rating_default') ?: null,
        'reject_if_quality_check_fails' => input('reject_if_quality_check_fails') === '1' ? 1 : 0,
        'item_customer_group' => input('item_customer_group') ?: null,
        'sales_min_order_qty' => (int)input('sales_min_order_qty'),
        'sales_max_order_qty' => (int)input('sales_max_order_qty'),
        'sales_order_qty_increment' => (int)input('sales_order_qty_increment') ?: 1,
        'sales_lead_time_days' => (int)input('sales_lead_time_days'),
        'delivery_time_days' => (int)input('delivery_time_days'),
        'weight_for_shipping_kg' => (float)input('weight_for_shipping_kg'),
        'sales_description' => input('sales_description') ?: null,
        'marketing_material' => input('marketing_material') ?: null,
        'item_website' => input('item_website') ?: null,
        'available_for_online_sales' => input('available_for_online_sales') === '1' ? 1 : 0,
        'available_for_retail_sales' => input('available_for_retail_sales') === '1' ? 1 : 0,
        'available_for_b2b_sales' => input('available_for_b2b_sales') === '1' ? 1 : 0,
        'not_discountable' => input('not_discountable') === '1' ? 1 : 0,
        'requires_approval_for_discount' => input('requires_approval_for_discount') === '1' ? 1 : 0,
        'show_in_customer_portal' => input('show_in_customer_portal') === '1' ? 1 : 0,
        'default_monthly_sales_qty' => (int)input('default_monthly_sales_qty'),
        'seasonal_demand' => in_array(input('seasonal_demand'), ['Low', 'Normal', 'High'], true) ? input('seasonal_demand') : 'Normal',
        'preferred_sales_warehouse_id' => input('preferred_sales_warehouse_id') ?: null,
        'standard_weight_kg' => (float)input('standard_weight_kg'),
        'standard_volume_ltr' => (float)input('standard_volume_ltr'),
        'gross_weight_kg' => (float)input('gross_weight_kg'),
        'net_weight_kg' => (float)input('net_weight_kg'),
        'tags' => input('tags') ?: null,
        'cost_price' => (float)input('cost_price'),
        'selling_price' => (float)input('selling_price'),
        'quantity' => $openingQty,
        'reorder_level' => (int)input('reorder_level'),
        'reorder_qty' => (int)input('reorder_qty'),
        'safety_stock' => (int)input('safety_stock'),
        'enable_reorder_notifications' => input('enable_reorder_notifications') === '1' ? 1 : 0,
        'consider_in_mrp' => input('consider_in_mrp') === '1' ? 1 : 0,
        'has_batch_no' => input('has_batch_no') === '1' ? 1 : 0,
        'has_serial_no' => input('has_serial_no') === '1' ? 1 : 0,
        'batch_expiry_required' => input('batch_expiry_required') === '1' ? 1 : 0,
        'batch_number_series' => input('batch_number_series') ?: null,
        'storage_section' => input('storage_section') ?: null,
        'storage_rack' => input('storage_rack') ?: null,
        'storage_shelf' => input('storage_shelf') ?: null,
        'storage_bin' => input('storage_bin') ?: null,
        'track_stock_ageing' => input('track_stock_ageing') === '1' ? 1 : 0,
        'include_in_stock_report' => input('include_in_stock_report') === '1' ? 1 : 0,
        'allow_stock_transfer' => input('allow_stock_transfer') === '1' ? 1 : 0,
        'is_kit_or_set' => input('is_kit_or_set') === '1' ? 1 : 0,
        'use_alternative_item' => input('use_alternative_item') === '1' ? 1 : 0,
        'restrict_warehouse' => input('restrict_warehouse') === '1' ? 1 : 0,
        'block_for_stock_transactions' => input('block_for_stock_transactions') === '1' ? 1 : 0,
        'exclude_from_inventory_valuation' => input('exclude_from_inventory_valuation') === '1' ? 1 : 0,
        'status' => in_array(input('status'), ['active', 'inactive'], true) ? input('status') : 'active',
    ];

    $altUoms = $_POST['alt_uom'] ?? [];
    $altUomFactors = $_POST['alt_uom_factor'] ?? [];
    $productUomsToSave = [];
    $uomSort = 0;
    foreach ($altUoms as $i => $uomName) {
        $uomName = trim($uomName);
        $factor = (float)($altUomFactors[$i] ?? 0);
        if ($uomName === '' || $factor <= 0) {
            continue;
        }
        $productUomsToSave[] = ['uom' => $uomName, 'conversion_factor' => $factor, 'sort_order' => $uomSort++];
    }

    $bcCodes = $_POST['barcode'] ?? [];
    $bcTypes = $_POST['barcode_type'] ?? [];
    $bcUoms = $_POST['barcode_uom'] ?? [];
    $bcDefaults = $_POST['barcode_is_default'] ?? [];
    $barcodesToSave = [];
    $bcSort = 0;
    foreach ($bcCodes as $i => $code) {
        $code = trim($code);
        if ($code === '') {
            continue;
        }
        $barcodesToSave[] = [
            'barcode' => $code, 'barcode_type' => trim($bcTypes[$i] ?? '') ?: null, 'uom' => trim($bcUoms[$i] ?? '') ?: null,
            'is_default' => ((string)($bcDefaults[$i] ?? '') === (string)$bcSort) ? 1 : 0, 'sort_order' => $bcSort++,
        ];
    }
    $defaultBcIndex = (int)input('barcode_default_index');
    foreach ($barcodesToSave as $i => &$bc) {
        $bc['is_default'] = ($i === $defaultBcIndex) ? 1 : 0;
    }
    unset($bc);

    $plRates = $_POST['price_list_rate'] ?? [];
    $ratesToSave = [];
    foreach ($plRates as $plId => $rate) {
        $rate = (float)$rate;
        if ($rate > 0) {
            $ratesToSave[(int)$plId] = $rate;
        }
    }

    $cpCustomerIds = $_POST['cp_customer_id'] ?? [];
    $cpPriceListIds = $_POST['cp_price_list_id'] ?? [];
    $cpRates = $_POST['cp_rate'] ?? [];
    $cpDiscounts = $_POST['cp_discount_percent'] ?? [];
    $cpValidFroms = $_POST['cp_valid_from'] ?? [];
    $cpValidTos = $_POST['cp_valid_to'] ?? [];
    $customerPricesToSave = [];
    $cpSort = 0;
    foreach ($cpCustomerIds as $i => $custId) {
        $custId = (int)$custId;
        if ($custId <= 0) {
            continue;
        }
        $customerPricesToSave[] = [
            'customer_id' => $custId, 'price_list_id' => (int)($cpPriceListIds[$i] ?? 0) ?: null,
            'rate' => (float)($cpRates[$i] ?? 0), 'discount_percent' => (float)($cpDiscounts[$i] ?? 0),
            'valid_from' => trim($cpValidFroms[$i] ?? '') ?: null, 'valid_to' => trim($cpValidTos[$i] ?? '') ?: null,
            'sort_order' => $cpSort++,
        ];
    }

    $txTypes = $_POST['tax_charge_type'] ?? [];
    $txAccounts = $_POST['tax_account_head_id'] ?? [];
    $txRates = $_POST['tax_rate_or_amount'] ?? [];
    $txIncluded = $_POST['tax_included_in_price'] ?? []; // one entry per row, kept in sync with the checkbox via a hidden field (see product-tax-rows JS)
    $txApplicable = $_POST['tax_applicable_on'] ?? [];
    $productTaxesToSave = [];
    $txSort = 0;
    foreach ($txTypes as $i => $type) {
        $type = trim($type);
        $accountId = (int)($txAccounts[$i] ?? 0);
        if ($type === '' && $accountId <= 0) {
            continue;
        }
        $productTaxesToSave[] = [
            'tax_charge_type' => $type ?: null, 'account_head_id' => $accountId ?: null,
            'rate_or_amount' => (float)($txRates[$i] ?? 0),
            'included_in_price' => ((string)($txIncluded[$i] ?? '0') === '1') ? 1 : 0,
            'applicable_on' => in_array($txApplicable[$i] ?? '', ['net_total', 'grand_total'], true) ? $txApplicable[$i] : 'net_total',
            'sort_order' => $txSort++,
        ];
    }

    $chTypes = $_POST['charge_type'] ?? [];
    $chAccounts = $_POST['charge_account_head_id'] ?? [];
    $chRates = $_POST['charge_rate_or_amount'] ?? [];
    $chApplicable = $_POST['charge_applicable_on'] ?? [];
    $productChargesToSave = [];
    $chSort = 0;
    foreach ($chTypes as $i => $type) {
        $type = trim($type);
        $accountId = (int)($chAccounts[$i] ?? 0);
        if ($type === '' && $accountId <= 0) {
            continue;
        }
        $productChargesToSave[] = [
            'charge_type' => $type ?: null, 'account_head_id' => $accountId ?: null,
            'rate_or_amount' => (float)($chRates[$i] ?? 0),
            'applicable_on' => in_array($chApplicable[$i] ?? '', ['net_total', 'grand_total'], true) ? $chApplicable[$i] : 'net_total',
            'sort_order' => $chSort++,
        ];
    }

    $psSupplierIds = $_POST['ps_supplier_id'] ?? [];
    $psPartNos = $_POST['ps_supplier_part_no'] ?? [];
    $psLeadTimes = $_POST['ps_lead_time_days'] ?? [];
    $psRates = $_POST['ps_last_purchase_rate'] ?? [];
    $productSuppliersToSave = [];
    $psSort = 0;
    $psPreferredIndex = (int)input('ps_preferred_index');
    foreach ($psSupplierIds as $i => $supplierId) {
        $supplierId = (int)$supplierId;
        if ($supplierId <= 0) {
            continue;
        }
        $productSuppliersToSave[] = [
            'supplier_id' => $supplierId, 'supplier_part_no' => trim($psPartNos[$i] ?? '') ?: null,
            'lead_time_days' => (int)($psLeadTimes[$i] ?? 0), 'last_purchase_rate' => (float)($psRates[$i] ?? 0),
            'is_preferred' => ($i === $psPreferredIndex) ? 1 : 0, 'sort_order' => $psSort++,
        ];
    }

    $crCustomerIds = $_POST['cr_customer_id'] ?? [];
    $crCustomerGroups = $_POST['cr_customer_group'] ?? [];
    $crPriceListIds = $_POST['cr_price_list_id'] ?? [];
    $crDiscounts = $_POST['cr_discount_percent'] ?? [];
    $crMinQtys = $_POST['cr_min_qty'] ?? [];
    $crMaxQtys = $_POST['cr_max_qty'] ?? [];
    $productCustomerRulesToSave = [];
    $crSort = 0;
    foreach ($crCustomerIds as $i => $custId) {
        $custId = (int)$custId;
        if ($custId <= 0) {
            continue;
        }
        $productCustomerRulesToSave[] = [
            'customer_id' => $custId, 'customer_group' => trim($crCustomerGroups[$i] ?? '') ?: null,
            'price_list_id' => (int)($crPriceListIds[$i] ?? 0) ?: null, 'discount_percent' => (float)($crDiscounts[$i] ?? 0),
            'min_qty' => (int)($crMinQtys[$i] ?? 0), 'max_qty' => (int)($crMaxQtys[$i] ?? 0), 'sort_order' => $crSort++,
        ];
    }

    if ($error) {
        // Image error already set above.
    } elseif ($product['sku'] === '' || $product['name'] === '') {
        $error = 'SKU and Name are required.';
    } elseif (!$id && $openingQty > 0 && !$openingWarehouseId) {
        $error = 'Please select a warehouse for the opening stock quantity.';
    } else {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            // $headerColNames must exactly match $product's keys (minus 'id'/'quantity',
            // which are handled separately) — $headerVals is derived from it below so
            // the two can never drift out of parallel sync as more columns are added.
            $headerColNames = [
                'sku', 'name', 'image', 'category_id', 'description', 'item_category_id', 'brand_id', 'hsn_sac_code',
                'is_stock_item', 'stock_item_type', 'opening_stock_date', 'valuation_rate',
                'is_sales_item', 'is_purchase_item', 'is_manufactured_item', 'is_sub_contracted_item', 'is_asset_item', 'has_variants',
                'unit', 'purchase_uom', 'sales_uom', 'purchase_uom_conversion_factor', 'sales_uom_conversion_factor',
                'default_warehouse_id', 'default_bin', 'issue_method', 'receipt_method', 'allow_negative_stock', 'auto_create_batch_serial',
                'default_price_list_id', 'min_stock_level', 'max_stock_level', 'lead_time_days', 'shelf_life_days',
                'item_type', 'valuation_method',
                'price_determination', 'last_purchase_rate', 'last_purchase_date', 'average_purchase_rate',
                'allow_discount', 'max_discount_percent', 'discount_account_id', 'apply_discount_on', 'enable_additional_discount_sales',
                'price_last_updated_at', 'price_updated_by', 'is_price_editable_in_transactions', 'include_in_price_suggestions',
                'allow_zero_price', 'show_in_website', 'minimum_selling_price', 'maximum_selling_price',
                'income_account_id', 'cogs_account_id', 'purchase_expense_account_id', 'stock_in_hand_account_id',
                'stock_adjustment_account_id', 'under_over_valuation_account_id', 'scrap_expense_account_id', 'gain_loss_account_id',
                'capitalization_threshold', 'include_in_period_closing_entry',
                'cost_center', 'default_project', 'activity_type', 'budget',
                'tax_category', 'is_nil_rated', 'is_exempt_from_tax', 'reverse_charge_applicable', 'tds_applicable',
                'default_tax_template_id', 'price_includes_tax', 'tax_calculation_based_on',
                'tax_exemption_reason', 'tax_exemption_applicable_from', 'tax_notes',
                'default_supplier_id', 'default_purchase_price_list_id', 'purchase_min_order_qty', 'purchase_max_order_qty', 'purchase_order_qty_increment',
                'receipt_tolerance_percent', 'over_delivery_allowance_percent', 'default_package_type', 'items_per_package', 'purchase_description',
                'requires_purchase_order', 'allow_receipt_without_po', 'track_supplier_batch_serial', 'include_in_supplier_portal',
                'is_drop_ship_item', 'allow_subcontracting', 'maintain_last_purchase_rate',
                'inspection_required', 'sampling_rate_percent', 'quality_rating_default', 'reject_if_quality_check_fails',
                'item_customer_group', 'sales_min_order_qty', 'sales_max_order_qty', 'sales_order_qty_increment',
                'sales_lead_time_days', 'delivery_time_days', 'weight_for_shipping_kg',
                'sales_description', 'marketing_material', 'item_website',
                'available_for_online_sales', 'available_for_retail_sales', 'available_for_b2b_sales',
                'not_discountable', 'requires_approval_for_discount', 'show_in_customer_portal',
                'default_monthly_sales_qty', 'seasonal_demand', 'preferred_sales_warehouse_id',
                'standard_weight_kg', 'standard_volume_ltr', 'gross_weight_kg', 'net_weight_kg', 'tags',
                'cost_price', 'selling_price', 'reorder_level', 'reorder_qty', 'safety_stock', 'enable_reorder_notifications', 'consider_in_mrp',
                'has_batch_no', 'has_serial_no', 'batch_expiry_required', 'batch_number_series',
                'storage_section', 'storage_rack', 'storage_shelf', 'storage_bin',
                'track_stock_ageing', 'include_in_stock_report', 'allow_stock_transfer', 'is_kit_or_set',
                'use_alternative_item', 'restrict_warehouse', 'block_for_stock_transactions', 'exclude_from_inventory_valuation',
                'status',
            ];
            $headerVals = array_map(fn($c) => $product[$c], $headerColNames);

            if ($id) {
                $setClause = implode(', ', array_map(fn($c) => "$c=?", $headerColNames));
                $pdo->prepare("UPDATE products SET $setClause WHERE id=?")->execute([...$headerVals, $id]);
                $pdo->prepare('DELETE FROM product_barcodes WHERE product_id=?')->execute([$id]);
                $newId = $id;
            } else {
                $colList = implode(', ', $headerColNames);
                $placeholders = implode(',', array_fill(0, count($headerVals), '?'));
                $pdo->prepare("INSERT INTO products ($colList) VALUES ($placeholders)")->execute($headerVals);
                $newId = (int)$pdo->lastInsertId();
            }

            $bcStmt = $pdo->prepare('INSERT INTO product_barcodes (product_id, barcode, barcode_type, uom, is_default, sort_order) VALUES (?,?,?,?,?,?)');
            foreach ($barcodesToSave as $bc) {
                $bcStmt->execute([$newId, $bc['barcode'], $bc['barcode_type'], $bc['uom'], $bc['is_default'], $bc['sort_order']]);
            }

            $pdo->prepare('DELETE FROM price_list_items WHERE product_id=?')->execute([$newId]);
            $plStmt = $pdo->prepare('INSERT INTO price_list_items (price_list_id, product_id, rate) VALUES (?,?,?)');
            foreach ($ratesToSave as $plId => $rate) {
                $plStmt->execute([$plId, $newId, $rate]);
            }

            $pdo->prepare('DELETE FROM product_customer_prices WHERE product_id=?')->execute([$newId]);
            $cpStmt = $pdo->prepare('INSERT INTO product_customer_prices (product_id, customer_id, price_list_id, rate, discount_percent, valid_from, valid_to, sort_order) VALUES (?,?,?,?,?,?,?,?)');
            foreach ($customerPricesToSave as $cp) {
                $cpStmt->execute([$newId, $cp['customer_id'], $cp['price_list_id'], $cp['rate'], $cp['discount_percent'], $cp['valid_from'], $cp['valid_to'], $cp['sort_order']]);
            }

            $pdo->prepare('DELETE FROM product_taxes WHERE product_id=?')->execute([$newId]);
            $txStmt = $pdo->prepare('INSERT INTO product_taxes (product_id, tax_charge_type, account_head_id, rate_or_amount, included_in_price, applicable_on, sort_order) VALUES (?,?,?,?,?,?,?)');
            foreach ($productTaxesToSave as $tx) {
                $txStmt->execute([$newId, $tx['tax_charge_type'], $tx['account_head_id'], $tx['rate_or_amount'], $tx['included_in_price'], $tx['applicable_on'], $tx['sort_order']]);
            }

            $pdo->prepare('DELETE FROM product_charges WHERE product_id=?')->execute([$newId]);
            $chStmt = $pdo->prepare('INSERT INTO product_charges (product_id, charge_type, account_head_id, rate_or_amount, applicable_on, sort_order) VALUES (?,?,?,?,?,?)');
            foreach ($productChargesToSave as $ch) {
                $chStmt->execute([$newId, $ch['charge_type'], $ch['account_head_id'], $ch['rate_or_amount'], $ch['applicable_on'], $ch['sort_order']]);
            }

            $pdo->prepare('DELETE FROM product_suppliers WHERE product_id=?')->execute([$newId]);
            $psStmt = $pdo->prepare('INSERT INTO product_suppliers (product_id, supplier_id, supplier_part_no, lead_time_days, last_purchase_rate, is_preferred, sort_order) VALUES (?,?,?,?,?,?,?)');
            foreach ($productSuppliersToSave as $ps) {
                $psStmt->execute([$newId, $ps['supplier_id'], $ps['supplier_part_no'], $ps['lead_time_days'], $ps['last_purchase_rate'], $ps['is_preferred'], $ps['sort_order']]);
            }

            $pdo->prepare('DELETE FROM product_customer_rules WHERE product_id=?')->execute([$newId]);
            $crStmt = $pdo->prepare('INSERT INTO product_customer_rules (product_id, customer_id, customer_group, price_list_id, discount_percent, min_qty, max_qty, sort_order) VALUES (?,?,?,?,?,?,?,?)');
            foreach ($productCustomerRulesToSave as $cr) {
                $crStmt->execute([$newId, $cr['customer_id'], $cr['customer_group'], $cr['price_list_id'], $cr['discount_percent'], $cr['min_qty'], $cr['max_qty'], $cr['sort_order']]);
            }

            $pdo->prepare('DELETE FROM product_uoms WHERE product_id=?')->execute([$newId]);
            $uomStmt = $pdo->prepare('INSERT INTO product_uoms (product_id, uom, conversion_factor, sort_order) VALUES (?,?,?,?)');
            foreach ($productUomsToSave as $pu) {
                $uomStmt->execute([$newId, $pu['uom'], $pu['conversion_factor'], $pu['sort_order']]);
            }

            if (!$id && $openingQty > 0 && $openingWarehouseId) {
                stock_move($newId, $openingWarehouseId, $openingQty, 'in', 'Initial stock', 'Opening balance on product creation', current_user()['id']);
            }

            $pdo->commit();
            log_activity('product', $newId, $id ? 'edited' : 'created');
            flash('success', $id ? 'Item updated.' : 'Item created.');
            redirect('/inventory/product_view.php?id=' . $newId);
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = str_contains($e->getMessage(), 'Duplicate') ? 'An item with this SKU already exists.' : 'Could not save item.';
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = $e->getMessage() ?: 'Could not save item.';
        }
    }

    $barcodes = $barcodesToSave;
    $priceListRates = $ratesToSave;
    $customerPrices = $customerPricesToSave;
    $productTaxes = $productTaxesToSave;
    $productCharges = $productChargesToSave;
    $productSuppliers = $productSuppliersToSave;
    $productCustomerRules = $productCustomerRulesToSave;
    $productUoms = $productUomsToSave;
}

$categories = db()->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();
$itemCategories = db()->query("SELECT id, name FROM item_categories WHERE status='active' ORDER BY name")->fetchAll();
$brands = db()->query("SELECT id, name FROM brands WHERE status='active' ORDER BY name")->fetchAll();
$units = db()->query("SELECT id, name FROM uom WHERE status = 'active' ORDER BY name")->fetchAll();
$warehouses = leaf_warehouses();
$priceLists = db()->query("SELECT id, name, currency FROM price_lists WHERE status='active' ORDER BY name")->fetchAll();
$customers = db()->query('SELECT id, name FROM customers ORDER BY name')->fetchAll();
$ledgerAccounts = db()->query("SELECT id, name FROM ledger_accounts WHERE status='active' ORDER BY name")->fetchAll();
$taxTemplates = db()->query("SELECT id, name FROM tax_templates WHERE status='active' ORDER BY name")->fetchAll();
$vendors = db()->query('SELECT id, name FROM vendors ORDER BY name')->fetchAll();
$brandName = null;
foreach ($brands as $b) {
    if ((string)$b['id'] === (string)$product['brand_id']) {
        $brandName = $b['name'];
        break;
    }
}

$page_title = $id ? 'Edit Item' : 'New Item';
require __DIR__ . '/../includes/header.php';
?>
<div class="card p-4">
  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0"><?= $id ? e($product['sku']) . ' — ' . e($product['name']) : 'New Item' ?> <span class="badge text-bg-<?= $product['status'] === 'active' ? 'success' : 'secondary' ?> badge-status"><?= $product['status'] === 'active' ? 'Enabled' : 'Disabled' ?></span></h5>
  </div>

  <ul class="nav nav-tabs mb-3">
    <li class="nav-item"><button class="nav-link <?= $activeTab === 'details' ? 'active' : '' ?>" id="tab-details" data-bs-toggle="tab" data-bs-target="#pane-details" type="button">Details</button></li>
    <li class="nav-item"><button class="nav-link <?= $activeTab === 'inventory' ? 'active' : '' ?>" id="tab-inventory" data-bs-toggle="tab" data-bs-target="#pane-inventory" type="button">Inventory</button></li>
    <li class="nav-item"><button class="nav-link <?= $activeTab === 'uom' ? 'active' : '' ?>" id="tab-uom" data-bs-toggle="tab" data-bs-target="#pane-uom" type="button">Units of Measure</button></li>
    <li class="nav-item"><button class="nav-link <?= $activeTab === 'pricing' ? 'active' : '' ?>" id="tab-pricing" data-bs-toggle="tab" data-bs-target="#pane-pricing" type="button">Pricing</button></li>
    <li class="nav-item"><button class="nav-link <?= $activeTab === 'accounting' ? 'active' : '' ?>" id="tab-accounting" data-bs-toggle="tab" data-bs-target="#pane-accounting" type="button">Accounting</button></li>
    <li class="nav-item"><button class="nav-link <?= $activeTab === 'tax' ? 'active' : '' ?>" id="tab-tax" data-bs-toggle="tab" data-bs-target="#pane-tax" type="button">Tax &amp; Charges</button></li>
    <li class="nav-item"><button class="nav-link <?= $activeTab === 'sales' ? 'active' : '' ?>" id="tab-sales" data-bs-toggle="tab" data-bs-target="#pane-sales" type="button">Sales</button></li>
    <li class="nav-item"><button class="nav-link <?= $activeTab === 'purchase' ? 'active' : '' ?>" id="tab-purchase" data-bs-toggle="tab" data-bs-target="#pane-purchase" type="button">Purchase</button></li>
  </ul>

  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $id ?>">

    <div class="tab-content">
      <div class="tab-pane fade <?= $activeTab === 'details' ? 'show active' : '' ?>" id="pane-details">
        <div class="row g-3">
          <div class="col-lg-8">
            <div class="card p-3 mb-3">
              <h6 class="mb-1">Basic Information</h6>
              <p class="text-muted small mb-3">Define basic details about the item.</p>
              <div class="row g-3">
                <div class="col-sm-6">
                  <label class="form-label">Item Code <span class="text-danger">*</span></label>
                  <input type="text" name="sku" class="form-control" required value="<?= e($product['sku']) ?>">
                </div>
                <div class="col-sm-6">
                  <label class="form-label">Item Name <span class="text-danger">*</span></label>
                  <input type="text" name="name" class="form-control" required value="<?= e($product['name']) ?>">
                </div>
                <div class="col-sm-4">
                  <label class="form-label">Item Group <span class="text-danger">*</span></label>
                  <select name="category_id" class="form-select">
                    <option value="">— None —</option>
                    <?php foreach ($categories as $c): ?>
                      <option value="<?= (int)$c['id'] ?>" <?= (string)$product['category_id'] === (string)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-sm-4">
                  <label class="form-label">Item Category</label>
                  <select name="item_category_id" class="form-select">
                    <option value="">— None —</option>
                    <?php foreach ($itemCategories as $ic): ?>
                      <option value="<?= (int)$ic['id'] ?>" <?= (string)$product['item_category_id'] === (string)$ic['id'] ? 'selected' : '' ?>><?= e($ic['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-sm-4">
                  <label class="form-label">Brand</label>
                  <select name="brand_id" class="form-select">
                    <option value="">— None —</option>
                    <?php foreach ($brands as $b): ?>
                      <option value="<?= (int)$b['id'] ?>" <?= (string)$product['brand_id'] === (string)$b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-sm-4">
                  <label class="form-label">HSN/SAC Code</label>
                  <input type="text" name="hsn_sac_code" class="form-control" value="<?= e($product['hsn_sac_code'] ?? '') ?>">
                </div>
                <div class="col-sm-8">
                  <label class="form-label">Description</label>
                  <textarea name="description" class="form-control" rows="1"><?= e($product['description'] ?? '') ?></textarea>
                </div>
              </div>
            </div>

            <div class="card p-3 mb-3">
              <h6 class="mb-3">Default Units</h6>
              <div class="row g-3">
                <div class="col-sm-4">
                  <label class="form-label">Stock UOM <span class="text-danger">*</span></label>
                  <select name="unit" class="form-select">
                    <?php $unitListed = false; ?>
                    <?php foreach ($units as $u): ?>
                      <option value="<?= e($u['name']) ?>" <?= $product['unit'] === $u['name'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
                      <?php if ($product['unit'] === $u['name']) $unitListed = true; ?>
                    <?php endforeach; ?>
                    <?php if ($product['unit'] !== '' && !$unitListed): ?>
                      <option value="<?= e($product['unit']) ?>" selected><?= e($product['unit']) ?> (not in Units of Measure)</option>
                    <?php endif; ?>
                  </select>
                  <div class="form-text">Manage the list under Inventory &rsaquo; <a href="uom.php">Units of Measure</a>.</div>
                </div>
                <div class="col-sm-4">
                  <label class="form-label">Purchase UOM</label>
                  <select name="purchase_uom" class="form-select">
                    <option value="">— Same as Stock UOM —</option>
                    <?php foreach ($units as $u): ?>
                      <option value="<?= e($u['name']) ?>" <?= $product['purchase_uom'] === $u['name'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-sm-4">
                  <label class="form-label">Sales UOM</label>
                  <select name="sales_uom" class="form-select">
                    <option value="">— Same as Stock UOM —</option>
                    <?php foreach ($units as $u): ?>
                      <option value="<?= e($u['name']) ?>" <?= $product['sales_uom'] === $u['name'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-sm-6">
                  <label class="form-label">Conversion Factor (Purchase &rarr; Stock)</label>
                  <input type="number" step="0.001" min="0" name="purchase_uom_conversion_factor" class="form-control" value="<?= e($product['purchase_uom_conversion_factor']) ?>">
                </div>
                <div class="col-sm-6">
                  <label class="form-label">Conversion Factor (Sales &rarr; Stock)</label>
                  <input type="number" step="0.001" min="0" name="sales_uom_conversion_factor" class="form-control" value="<?= e($product['sales_uom_conversion_factor']) ?>">
                </div>
              </div>
              <div class="form-text mt-1">The full alternate-UOM system (multiple UOMs per item, used directly in transaction line items) lives on the Units of Measure tab, coming in a later phase.</div>
            </div>

            <div class="card p-3 mb-3">
              <h6 class="mb-3">Additional Details</h6>
              <div class="row g-3">
                <div class="col-sm-4">
                  <label class="form-label">Item Type</label>
                  <select name="item_type" class="form-select">
                    <?php foreach (['Finished Good', 'Raw Material', 'Service Item', 'Consumable'] as $it): ?>
                      <option value="<?= e($it) ?>" <?= $product['item_type'] === $it ? 'selected' : '' ?>><?= e($it) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-sm-4">
                  <label class="form-label">Valuation Method</label>
                  <select name="valuation_method" class="form-select">
                    <?php foreach (['FIFO', 'LIFO', 'Moving Average'] as $vm): ?>
                      <option value="<?= e($vm) ?>" <?= $product['valuation_method'] === $vm ? 'selected' : '' ?>><?= e($vm) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-sm-4">
                  <label class="form-label">Standard Weight (kg)</label>
                  <input type="number" step="0.001" min="0" name="standard_weight_kg" class="form-control" value="<?= e($product['standard_weight_kg']) ?>">
                </div>
                <div class="col-sm-4">
                  <label class="form-label">Standard Volume (ltr)</label>
                  <input type="number" step="0.001" min="0" name="standard_volume_ltr" class="form-control" value="<?= e($product['standard_volume_ltr']) ?>">
                </div>
                <div class="col-sm-4">
                  <label class="form-label">Gross Weight (kg)</label>
                  <input type="number" step="0.001" min="0" name="gross_weight_kg" class="form-control" value="<?= e($product['gross_weight_kg']) ?>">
                </div>
                <div class="col-sm-4">
                  <label class="form-label">Net Weight (kg)</label>
                  <input type="number" step="0.001" min="0" name="net_weight_kg" class="form-control" value="<?= e($product['net_weight_kg']) ?>">
                </div>
                <div class="col-sm-12">
                  <label class="form-label">Tags</label>
                  <input type="text" name="tags" class="form-control" placeholder="Comma-separated" value="<?= e($product['tags'] ?? '') ?>">
                </div>
              </div>
            </div>

            <div class="card p-3 mb-3">
              <h6 class="mb-1">Pricing &amp; Status</h6>
              <p class="text-muted small mb-3">Basic pricing and lifecycle status. A full Pricing tab is coming in a later phase.</p>
              <div class="row g-3">
                <div class="col-sm-3">
                  <label class="form-label">Cost Price</label>
                  <input type="number" step="0.01" min="0" name="cost_price" class="form-control" value="<?= e($product['cost_price']) ?>">
                </div>
                <div class="col-sm-3">
                  <label class="form-label">Selling Price</label>
                  <input type="number" step="0.01" min="0" name="selling_price" class="form-control" value="<?= e($product['selling_price']) ?>">
                </div>
                <div class="col-sm-3">
                  <label class="form-label">Reorder Level</label>
                  <input type="number" name="reorder_level" class="form-control" value="<?= e($product['reorder_level']) ?>">
                </div>
                <div class="col-sm-3">
                  <label class="form-label">Status</label>
                  <select name="status" class="form-select">
                    <option value="active" <?= $product['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $product['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                  </select>
                </div>
                <?php if ($id): ?>
                <div class="col-sm-6">
                  <label class="form-label">Quantity in stock <span class="text-muted">(use Stock Movements to adjust)</span></label>
                  <input type="number" class="form-control" value="<?= (int)$existingQuantity ?>" readonly>
                </div>
                <?php else: ?>
                <div class="col-sm-6">
                  <label class="form-label">Opening Quantity</label>
                  <input type="number" min="0" name="opening_quantity" class="form-control" value="<?= e($product['quantity']) ?>">
                </div>
                <div class="col-sm-6">
                  <label class="form-label">Opening Warehouse <span class="text-muted">(required if quantity &gt; 0)</span></label>
                  <select name="opening_warehouse_id" class="form-select">
                    <option value="">— Select warehouse —</option>
                    <?php foreach ($warehouses as $w): ?>
                      <option value="<?= (int)$w['id'] ?>" <?= (string)input('opening_warehouse_id') === (string)$w['id'] ? 'selected' : '' ?>><?= e($w['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <div class="col-lg-4">
            <div class="card p-3 mb-3">
              <h6 class="mb-3">Item Image</h6>
              <?php if (!empty($existingImage)): ?>
                <div class="d-flex align-items-center gap-3 mb-2">
                  <img src="<?= base_url($existingImage) ?>" alt="" style="width:80px;height:80px;object-fit:cover;border-radius:8px;border:1px solid #dee2e6">
                  <div class="form-check">
                    <input type="checkbox" class="form-check-input" id="removeImage" name="remove_image" value="1">
                    <label class="form-check-label" for="removeImage">Remove current photo</label>
                  </div>
                </div>
              <?php endif; ?>
              <input type="file" name="image" class="form-control" accept="image/jpeg,image/png,image/webp,image/gif">
              <div class="form-text">JPG, PNG, WEBP or GIF, up to 3 MB.</div>
            </div>

            <div class="card p-3 mb-3">
              <h6 class="mb-3">Quick Settings</h6>
              <?php
              $quickSettings = [
                  'is_stock_item' => 'Is Stock Item', 'is_sales_item' => 'Is Sales Item', 'is_purchase_item' => 'Is Purchase Item',
                  'is_manufactured_item' => 'Is Manufactured Item', 'is_sub_contracted_item' => 'Is Sub-Contracted Item',
                  'is_asset_item' => 'Is Asset Item', 'has_variants' => 'Has Variants',
              ];
              ?>
              <?php foreach ($quickSettings as $qsKey => $qsLabel): ?>
                <div class="form-check form-switch mb-2">
                  <input type="checkbox" class="form-check-input" id="qs_<?= e($qsKey) ?>" name="<?= e($qsKey) ?>" value="1" <?= !empty($product[$qsKey]) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="qs_<?= e($qsKey) ?>"><?= e($qsLabel) ?></label>
                </div>
              <?php endforeach; ?>
            </div>

            <div class="card p-3 mb-3">
              <h6 class="mb-3">Item Defaults</h6>
              <div class="mb-2">
                <label class="form-label">Default Warehouse</label>
                <select name="default_warehouse_id" class="form-select">
                  <option value="">— None —</option>
                  <?php foreach ($warehouses as $w): ?>
                    <option value="<?= (int)$w['id'] ?>" <?= (string)$product['default_warehouse_id'] === (string)$w['id'] ? 'selected' : '' ?>><?= e($w['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="mb-2">
                <label class="form-label">Default Price List</label>
                <select name="default_price_list_id" class="form-select">
                  <option value="">— None —</option>
                  <?php foreach ($priceLists as $pl): ?>
                    <option value="<?= (int)$pl['id'] ?>" <?= (string)$product['default_price_list_id'] === (string)$pl['id'] ? 'selected' : '' ?>><?= e($pl['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="row g-2">
                <div class="col-6">
                  <label class="form-label">Min Stock Level</label>
                  <input type="number" min="0" name="min_stock_level" class="form-control" value="<?= e($product['min_stock_level']) ?>">
                </div>
                <div class="col-6">
                  <label class="form-label">Max Stock Level</label>
                  <input type="number" min="0" name="max_stock_level" class="form-control" value="<?= e($product['max_stock_level']) ?>">
                </div>
                <div class="col-6">
                  <label class="form-label">Lead Time (Days)</label>
                  <input type="number" min="0" name="lead_time_days" class="form-control" value="<?= e($product['lead_time_days']) ?>">
                </div>
                <div class="col-6">
                  <label class="form-label">Shelf Life (Days)</label>
                  <input type="number" min="0" name="shelf_life_days" class="form-control" value="<?= e($product['shelf_life_days']) ?>">
                </div>
              </div>
            </div>

            <div class="card p-3 mb-3">
              <h6 class="mb-3">Barcodes</h6>
              <div class="table-responsive">
                <table class="table table-sm product-barcode-rows">
                  <thead><tr><th>Barcode</th><th>Type</th><th>UOM</th><th class="text-center">Default</th><th></th></tr></thead>
                  <tbody>
                  <?php if (!$barcodes): $barcodes = [['barcode' => '', 'barcode_type' => '', 'uom' => '', 'is_default' => 1]]; endif; ?>
                  <?php foreach ($barcodes as $bi => $bc): ?>
                    <tr data-row>
                      <td><input type="text" class="form-control form-control-sm" name="barcode[]" value="<?= e($bc['barcode']) ?>"></td>
                      <td><input type="text" class="form-control form-control-sm" name="barcode_type[]" value="<?= e($bc['barcode_type'] ?? '') ?>"></td>
                      <td><input type="text" class="form-control form-control-sm" name="barcode_uom[]" value="<?= e($bc['uom'] ?? '') ?>"></td>
                      <td class="text-center"><input type="radio" name="barcode_default_index" value="<?= (int)$bi ?>" <?= !empty($bc['is_default']) ? 'checked' : '' ?>></td>
                      <td><button type="button" class="btn btn-sm btn-outline-danger product-barcode-remove-row"><i class="fa-solid fa-xmark"></i></button></td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <button type="button" class="btn btn-sm btn-outline-brand product-barcode-add-row"><i class="fa-solid fa-plus"></i> Add Barcode</button>
            </div>
          </div>
        </div>
      </div>

      <div class="tab-pane fade <?= $activeTab === 'inventory' ? 'show active' : '' ?>" id="pane-inventory">
        <div class="row g-3">
          <div class="col-lg-4">
            <div class="card p-3 mb-3">
              <h6 class="mb-3">Stock Settings</h6>
              <div class="mb-2">
                <label class="form-label">Stock Item Type</label>
                <select name="stock_item_type" class="form-select">
                  <?php foreach (['Regular Stock Item', 'Non-Stock Item', 'Fixed Asset Item'] as $sit): ?>
                    <option value="<?= e($sit) ?>" <?= $product['stock_item_type'] === $sit ? 'selected' : '' ?>><?= e($sit) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="row g-2">
                <div class="col-6">
                  <label class="form-label">Opening Stock Date</label>
                  <input type="date" name="opening_stock_date" class="form-control" value="<?= e($product['opening_stock_date'] ?? '') ?>">
                </div>
                <div class="col-6">
                  <label class="form-label">Valuation Rate</label>
                  <input type="number" step="0.01" min="0" name="valuation_rate" class="form-control" value="<?= e($product['valuation_rate']) ?>">
                </div>
              </div>
            </div>

            <div class="card p-3 mb-3">
              <h6 class="mb-3">Inventory Dimensions</h6>
              <div class="row g-2">
                <div class="col-6">
                  <label class="form-label">Default Bin</label>
                  <input type="text" name="default_bin" class="form-control" value="<?= e($product['default_bin'] ?? '') ?>">
                </div>
                <div class="col-6">
                  <label class="form-label">Issue Method</label>
                  <select name="issue_method" class="form-select">
                    <?php foreach (['FIFO', 'LIFO', 'Moving Average'] as $m): ?>
                      <option value="<?= e($m) ?>" <?= $product['issue_method'] === $m ? 'selected' : '' ?>><?= e($m) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-6">
                  <label class="form-label">Receipt Method</label>
                  <select name="receipt_method" class="form-select">
                    <?php foreach (['FIFO', 'LIFO', 'Moving Average'] as $m): ?>
                      <option value="<?= e($m) ?>" <?= $product['receipt_method'] === $m ? 'selected' : '' ?>><?= e($m) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
              <div class="form-check form-switch mt-2">
                <input type="checkbox" class="form-check-input" id="allowNegStock" name="allow_negative_stock" value="1" <?= !empty($product['allow_negative_stock']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="allowNegStock">Allow Negative Stock</label>
              </div>
              <div class="form-check form-switch">
                <input type="checkbox" class="form-check-input" id="autoCreateBatchSerial" name="auto_create_batch_serial" value="1" <?= !empty($product['auto_create_batch_serial']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="autoCreateBatchSerial">Auto Create Batch/Serial No.</label>
              </div>
            </div>

            <div class="card p-3 mb-3">
              <h6 class="mb-3">Batch &amp; Serial Settings</h6>
              <p class="text-muted small mb-2">Full batch/serial configuration and tracking is on the Batch &amp; Serial No. tab, coming in a later phase.</p>
              <div class="form-check form-switch">
                <input type="checkbox" class="form-check-input" id="hasBatchNo" name="has_batch_no" value="1" <?= !empty($product['has_batch_no']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="hasBatchNo">Has Batch No.</label>
              </div>
              <div class="form-check form-switch">
                <input type="checkbox" class="form-check-input" id="hasSerialNo" name="has_serial_no" value="1" <?= !empty($product['has_serial_no']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="hasSerialNo">Has Serial No.</label>
              </div>
              <div class="form-check form-switch mb-2">
                <input type="checkbox" class="form-check-input" id="batchExpiryRequired" name="batch_expiry_required" value="1" <?= !empty($product['batch_expiry_required']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="batchExpiryRequired">Batch Expiry Required</label>
              </div>
              <label class="form-label">Batch Number Series</label>
              <input type="text" name="batch_number_series" class="form-control" placeholder="BATCH-YYYY-####" value="<?= e($product['batch_number_series'] ?? '') ?>">
            </div>
          </div>

          <div class="col-lg-4">
            <div class="card p-3 mb-3">
              <h6 class="mb-3">Stock Levels</h6>
              <div class="row g-2">
                <div class="col-6">
                  <label class="form-label">Reorder Qty</label>
                  <input type="number" min="0" name="reorder_qty" class="form-control" value="<?= e($product['reorder_qty']) ?>">
                </div>
                <div class="col-6">
                  <label class="form-label">Safety Stock</label>
                  <input type="number" min="0" name="safety_stock" class="form-control" value="<?= e($product['safety_stock']) ?>">
                </div>
              </div>
              <div class="form-text mb-2">Reorder Level and Max Stock Level are on the Details tab.</div>
              <div class="form-check form-switch">
                <input type="checkbox" class="form-check-input" id="enableReorderNotif" name="enable_reorder_notifications" value="1" <?= !empty($product['enable_reorder_notifications']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="enableReorderNotif">Enable Reorder Notifications</label>
              </div>
              <div class="form-check form-switch">
                <input type="checkbox" class="form-check-input" id="considerInMrp" name="consider_in_mrp" value="1" <?= !empty($product['consider_in_mrp']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="considerInMrp">Consider in MRP</label>
              </div>
            </div>

            <div class="card p-3 mb-3">
              <h6 class="mb-3">Storage &amp; Shelf Information</h6>
              <div class="row g-2">
                <div class="col-6">
                  <label class="form-label">Storage Section</label>
                  <input type="text" name="storage_section" class="form-control" value="<?= e($product['storage_section'] ?? '') ?>">
                </div>
                <div class="col-6">
                  <label class="form-label">Rack</label>
                  <input type="text" name="storage_rack" class="form-control" value="<?= e($product['storage_rack'] ?? '') ?>">
                </div>
                <div class="col-6">
                  <label class="form-label">Shelf</label>
                  <input type="text" name="storage_shelf" class="form-control" value="<?= e($product['storage_shelf'] ?? '') ?>">
                </div>
                <div class="col-6">
                  <label class="form-label">Bin</label>
                  <input type="text" name="storage_bin" class="form-control" value="<?= e($product['storage_bin'] ?? '') ?>">
                </div>
              </div>
            </div>

            <div class="card p-3 mb-3">
              <h6 class="mb-3">Additional Inventory Options</h6>
              <?php
              $invOptions = [
                  'track_stock_ageing' => 'Track Stock Ageing', 'include_in_stock_report' => 'Include in Stock Report',
                  'allow_stock_transfer' => 'Allow Stock Transfer', 'is_kit_or_set' => 'This Item is a Kit/Set',
                  'use_alternative_item' => 'Use Alternative Item', 'restrict_warehouse' => 'Restrict Warehouse',
                  'block_for_stock_transactions' => 'Block for Stock Transactions', 'exclude_from_inventory_valuation' => 'Exclude from Inventory Valuation',
              ];
              ?>
              <div class="row g-1">
                <?php foreach ($invOptions as $ioKey => $ioLabel): ?>
                  <div class="col-6 form-check">
                    <input type="checkbox" class="form-check-input" id="io_<?= e($ioKey) ?>" name="<?= e($ioKey) ?>" value="1" <?= !empty($product[$ioKey]) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="io_<?= e($ioKey) ?>"><?= e($ioLabel) ?></label>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>

          <div class="col-lg-4">
            <div class="card p-3 mb-3">
              <h6 class="mb-2">Warehouse Wise Stock</h6>
              <p class="text-muted small mb-2">View-only — use Stock Movements to change stock.</p>
              <table class="table table-sm mb-0">
                <thead><tr><th>Warehouse</th><th class="text-end">Current Stock</th></tr></thead>
                <tbody>
                <?php foreach ($stockByWarehouse as $w): ?>
                  <tr><td><?= e($w['name']) ?></td><td class="text-end fw-bold"><?= (int)$w['quantity'] ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$stockByWarehouse): ?><tr><td class="text-muted text-center" colspan="2"><?= $id ? 'No warehouses set up yet.' : 'Save the item first to see stock by warehouse.' ?></td></tr><?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <div class="tab-pane fade <?= $activeTab === 'uom' ? 'show active' : '' ?>" id="pane-uom">
        <div class="row g-3">
          <div class="col-lg-5">
            <div class="card p-3 mb-3">
              <h6 class="mb-3">Default UOMs</h6>
              <p class="text-muted small mb-3">Set on their own tabs — shown here for reference only.</p>
              <div class="row g-3">
                <div class="col-12">
                  <label class="form-label">Stock UOM</label>
                  <input type="text" class="form-control" value="<?= e($product['unit']) ?>" disabled>
                  <div class="form-text">Set on the Details tab.</div>
                </div>
                <div class="col-12">
                  <label class="form-label">Default Purchase UOM</label>
                  <input type="text" class="form-control" value="<?= e($product['purchase_uom'] ?: $product['unit']) ?> (&times;<?= e($product['purchase_uom_conversion_factor']) ?>)" disabled>
                  <div class="form-text">Set on the Purchase tab.</div>
                </div>
                <div class="col-12">
                  <label class="form-label">Default Sales UOM</label>
                  <input type="text" class="form-control" value="<?= e($product['sales_uom'] ?: $product['unit']) ?> (&times;<?= e($product['sales_uom_conversion_factor']) ?>)" disabled>
                  <div class="form-text">Set on the Sales tab.</div>
                </div>
              </div>
            </div>
          </div>
          <div class="col-lg-7">
            <div class="card p-3 mb-3">
              <h6 class="mb-1">Alternate UOMs</h6>
              <p class="text-muted small mb-3">Other units this item can be bought, sold, or transferred in, and how many Stock UOM units each one equals. These are the UOMs offered on Purchase Order, GRN, Quotation, Sales Order, and Delivery Note line items.</p>
              <div class="table-responsive">
                <table class="table table-sm product-uom-rows">
                  <thead><tr><th>UOM</th><th style="width:45%">1 UOM = how many Stock UOM (<?= e($product['unit']) ?>)</th><th></th></tr></thead>
                  <tbody>
                  <?php if (!$productUoms): $productUoms = [['uom' => '', 'conversion_factor' => '']]; endif; ?>
                  <?php foreach ($productUoms as $pu): ?>
                    <tr data-row>
                      <td>
                        <select class="form-select form-select-sm" name="alt_uom[]">
                          <option value="">— Select —</option>
                          <?php foreach ($units as $u): ?>
                            <option value="<?= e($u['name']) ?>" <?= (string)($pu['uom'] ?? '') === (string)$u['name'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </td>
                      <td><input type="number" step="0.001" min="0" class="form-control form-control-sm" name="alt_uom_factor[]" value="<?= e($pu['conversion_factor'] ?? '') ?>"></td>
                      <td><button type="button" class="btn btn-sm btn-outline-danger product-uom-remove-row"><i class="fa-solid fa-xmark"></i></button></td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <button type="button" class="btn btn-sm btn-outline-brand product-uom-add-row"><i class="fa-solid fa-plus"></i> Add Alternate UOM</button>
            </div>
          </div>
        </div>
      </div>

      <div class="tab-pane fade <?= $activeTab === 'pricing' ? 'show active' : '' ?>" id="pane-pricing">
        <div class="row g-3">
          <div class="col-lg-6">
            <div class="card p-3 mb-3">
              <h6 class="mb-1">Valuation &amp; Pricing Method</h6>
              <p class="text-muted small mb-3">Define how the item is valued and priced.</p>
              <div class="row g-3">
                <div class="col-sm-6">
                  <label class="form-label">Price Determination</label>
                  <select name="price_determination" class="form-select">
                    <?php foreach (['Based on Price List', 'Fixed Rate'] as $pd): ?>
                      <option value="<?= e($pd) ?>" <?= $product['price_determination'] === $pd ? 'selected' : '' ?>><?= e($pd) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-sm-6">
                  <label class="form-label">Standard Rate</label>
                  <input type="number" step="0.01" min="0" name="selling_price" class="form-control" value="<?= e($product['selling_price']) ?>">
                  <div class="form-text">Same field as Selling Price on the Details tab.</div>
                </div>
                <div class="col-sm-4">
                  <label class="form-label">Last Purchase Rate</label>
                  <input type="number" step="0.01" min="0" name="last_purchase_rate" class="form-control" value="<?= e($product['last_purchase_rate']) ?>">
                </div>
                <div class="col-sm-4">
                  <label class="form-label">Last Purchase Date</label>
                  <input type="date" name="last_purchase_date" class="form-control" value="<?= e($product['last_purchase_date'] ?? '') ?>">
                </div>
                <div class="col-sm-4">
                  <label class="form-label">Average Purchase Rate</label>
                  <input type="number" step="0.01" min="0" name="average_purchase_rate" class="form-control" value="<?= e($product['average_purchase_rate']) ?>">
                </div>
              </div>
              <div class="form-text mt-1">These purchase-rate fields are reference values, not yet auto-updated from GRNs or Purchase Invoices.</div>
            </div>

            <div class="card p-3 mb-3">
              <h6 class="mb-1">Default Price List Rates</h6>
              <p class="text-muted small mb-3">Set default selling price for different price lists. Leave a rate at 0 to fall back to the Standard Rate above.</p>
              <div class="table-responsive">
                <table class="table table-sm mb-0">
                  <thead><tr><th>Price List</th><th>Currency</th><th class="text-end">Rate</th></tr></thead>
                  <tbody>
                  <?php foreach ($priceLists as $pl): ?>
                    <tr>
                      <td><?= e($pl['name']) ?></td>
                      <td><?= e($pl['currency']) ?></td>
                      <td><input type="number" step="0.01" min="0" class="form-control form-control-sm text-end" name="price_list_rate[<?= (int)$pl['id'] ?>]" value="<?= e($priceListRates[(int)$pl['id']] ?? '') ?>"></td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (!$priceLists): ?><tr><td colspan="3" class="text-muted text-center">No active price lists. Manage them under Sales &rsaquo; Price Lists.</td></tr><?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>

            <div class="card p-3 mb-3">
              <h6 class="mb-3">Discount Rules</h6>
              <div class="form-check form-switch mb-2">
                <input type="checkbox" class="form-check-input" id="allowDiscount" name="allow_discount" value="1" <?= !empty($product['allow_discount']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="allowDiscount">Allow Discount</label>
              </div>
              <div class="row g-2">
                <div class="col-sm-4">
                  <label class="form-label">Max Discount (%)</label>
                  <input type="number" step="0.01" min="0" max="100" name="max_discount_percent" class="form-control" value="<?= e($product['max_discount_percent']) ?>">
                </div>
                <div class="col-sm-4">
                  <label class="form-label">Discount Account</label>
                  <select name="discount_account_id" class="form-select">
                    <option value="">— None —</option>
                    <?php foreach ($ledgerAccounts as $la): ?>
                      <option value="<?= (int)$la['id'] ?>" <?= (string)$product['discount_account_id'] === (string)$la['id'] ? 'selected' : '' ?>><?= e($la['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-sm-4">
                  <label class="form-label">Apply Discount On</label>
                  <select name="apply_discount_on" class="form-select">
                    <option value="net_total" <?= $product['apply_discount_on'] === 'net_total' ? 'selected' : '' ?>>Net Total</option>
                    <option value="grand_total" <?= $product['apply_discount_on'] === 'grand_total' ? 'selected' : '' ?>>Grand Total</option>
                  </select>
                </div>
              </div>
              <div class="form-check form-switch mt-2">
                <input type="checkbox" class="form-check-input" id="enableAddlDiscount" name="enable_additional_discount_sales" value="1" <?= !empty($product['enable_additional_discount_sales']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="enableAddlDiscount">Enable Additional Discount in Sales Orders</label>
              </div>
            </div>
          </div>

          <div class="col-lg-6">
            <div class="card p-3 mb-3">
              <h6 class="mb-1">Customer Specific Pricing</h6>
              <p class="text-muted small mb-3">Set special pricing for individual customers (optional).</p>
              <div class="table-responsive">
                <table class="table table-sm product-cp-rows">
                  <thead><tr><th>Customer</th><th>Price List</th><th>Rate</th><th>Disc. %</th><th>Valid From</th><th>Valid To</th><th></th></tr></thead>
                  <tbody>
                  <?php foreach ($customerPrices as $cp): ?>
                    <tr data-row>
                      <td>
                        <select class="form-select form-select-sm" name="cp_customer_id[]">
                          <option value="">— Select —</option>
                          <?php foreach ($customers as $c): ?>
                            <option value="<?= (int)$c['id'] ?>" <?= (string)($cp['customer_id'] ?? '') === (string)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </td>
                      <td>
                        <select class="form-select form-select-sm" name="cp_price_list_id[]">
                          <option value="">— Any —</option>
                          <?php foreach ($priceLists as $pl): ?>
                            <option value="<?= (int)$pl['id'] ?>" <?= (string)($cp['price_list_id'] ?? '') === (string)$pl['id'] ? 'selected' : '' ?>><?= e($pl['name']) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </td>
                      <td><input type="number" step="0.01" min="0" class="form-control form-control-sm" name="cp_rate[]" value="<?= e($cp['rate'] ?? '') ?>"></td>
                      <td><input type="number" step="0.01" min="0" max="100" class="form-control form-control-sm" name="cp_discount_percent[]" value="<?= e($cp['discount_percent'] ?? 0) ?>"></td>
                      <td><input type="date" class="form-control form-control-sm" name="cp_valid_from[]" value="<?= e($cp['valid_from'] ?? '') ?>"></td>
                      <td><input type="date" class="form-control form-control-sm" name="cp_valid_to[]" value="<?= e($cp['valid_to'] ?? '') ?>"></td>
                      <td><button type="button" class="btn btn-sm btn-outline-danger product-cp-remove-row"><i class="fa-solid fa-xmark"></i></button></td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (!$customerPrices): ?>
                    <tr data-row>
                      <td>
                        <select class="form-select form-select-sm" name="cp_customer_id[]">
                          <option value="">— Select —</option>
                          <?php foreach ($customers as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
                        </select>
                      </td>
                      <td>
                        <select class="form-select form-select-sm" name="cp_price_list_id[]">
                          <option value="">— Any —</option>
                          <?php foreach ($priceLists as $pl): ?><option value="<?= (int)$pl['id'] ?>"><?= e($pl['name']) ?></option><?php endforeach; ?>
                        </select>
                      </td>
                      <td><input type="number" step="0.01" min="0" class="form-control form-control-sm" name="cp_rate[]"></td>
                      <td><input type="number" step="0.01" min="0" max="100" class="form-control form-control-sm" name="cp_discount_percent[]" value="0"></td>
                      <td><input type="date" class="form-control form-control-sm" name="cp_valid_from[]"></td>
                      <td><input type="date" class="form-control form-control-sm" name="cp_valid_to[]"></td>
                      <td><button type="button" class="btn btn-sm btn-outline-danger product-cp-remove-row"><i class="fa-solid fa-xmark"></i></button></td>
                    </tr>
                  <?php endif; ?>
                  </tbody>
                </table>
              </div>
              <button type="button" class="btn btn-sm btn-outline-brand product-cp-add-row"><i class="fa-solid fa-plus"></i> Add Customer Price</button>
            </div>

            <div class="card p-3 mb-3">
              <h6 class="mb-3">Other Pricing Information</h6>
              <div class="row g-2 mb-2">
                <div class="col-sm-6">
                  <label class="form-label">Minimum Selling Price</label>
                  <input type="number" step="0.01" min="0" name="minimum_selling_price" class="form-control" value="<?= e($product['minimum_selling_price']) ?>">
                </div>
                <div class="col-sm-6">
                  <label class="form-label">Maximum Selling Price</label>
                  <input type="number" step="0.01" min="0" name="maximum_selling_price" class="form-control" value="<?= e($product['maximum_selling_price']) ?>">
                </div>
              </div>
              <?php if ($id && $product['price_last_updated_at']): ?>
                <div class="text-muted small mb-2">Price last updated <?= e(date('M j, Y g:ia', strtotime($product['price_last_updated_at']))) ?></div>
              <?php endif; ?>
              <div class="form-check form-switch">
                <input type="checkbox" class="form-check-input" id="priceEditable" name="is_price_editable_in_transactions" value="1" <?= !empty($product['is_price_editable_in_transactions']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="priceEditable">Is Price Editable in Transactions</label>
              </div>
              <div class="form-check form-switch">
                <input type="checkbox" class="form-check-input" id="includeInPriceSuggestions" name="include_in_price_suggestions" value="1" <?= !empty($product['include_in_price_suggestions']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="includeInPriceSuggestions">Include in Price Suggestions</label>
              </div>
              <div class="form-check form-switch">
                <input type="checkbox" class="form-check-input" id="allowZeroPrice" name="allow_zero_price" value="1" <?= !empty($product['allow_zero_price']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="allowZeroPrice">Allow Zero Price</label>
              </div>
              <div class="form-check form-switch">
                <input type="checkbox" class="form-check-input" id="showInWebsite" name="show_in_website" value="1" <?= !empty($product['show_in_website']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="showInWebsite">Show in Website</label>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="tab-pane fade <?= $activeTab === 'accounting' ? 'show active' : '' ?>" id="pane-accounting">
        <div class="row g-3">
          <div class="col-lg-6">
            <div class="card p-3 mb-3">
              <h6 class="mb-1">Account Head Mapping</h6>
              <p class="text-muted small mb-3">Define the default accounts to be used in transactions for this item.</p>
              <div class="row g-3">
                <?php
                $acctFields = [
                    'income_account_id' => 'Income Account (Sales)', 'cogs_account_id' => 'Cost of Goods Sold (COGS)',
                    'purchase_expense_account_id' => 'Expense Account (Purchase)', 'stock_in_hand_account_id' => 'Stock in Hand Account',
                    'stock_adjustment_account_id' => 'Stock Adjustment Account', 'under_over_valuation_account_id' => 'Under/Over Valuation Account',
                ];
                ?>
                <?php foreach ($acctFields as $afKey => $afLabel): ?>
                  <div class="col-sm-6">
                    <label class="form-label"><?= e($afLabel) ?></label>
                    <select name="<?= e($afKey) ?>" class="form-select">
                      <option value="">— None —</option>
                      <?php foreach ($ledgerAccounts as $la): ?>
                        <option value="<?= (int)$la['id'] ?>" <?= (string)$product[$afKey] === (string)$la['id'] ? 'selected' : '' ?>><?= e($la['name']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>

            <div class="card p-3 mb-3">
              <h6 class="mb-1">Valuation Settings</h6>
              <p class="text-muted small mb-3">Define how the item value is calculated in accounting.</p>
              <div class="row g-3">
                <div class="col-sm-6">
                  <label class="form-label">Expense Account for Scrap</label>
                  <select name="scrap_expense_account_id" class="form-select">
                    <option value="">— None —</option>
                    <?php foreach ($ledgerAccounts as $la): ?>
                      <option value="<?= (int)$la['id'] ?>" <?= (string)$product['scrap_expense_account_id'] === (string)$la['id'] ? 'selected' : '' ?>><?= e($la['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-sm-6">
                  <label class="form-label">Gain/Loss Account</label>
                  <select name="gain_loss_account_id" class="form-select">
                    <option value="">— None —</option>
                    <?php foreach ($ledgerAccounts as $la): ?>
                      <option value="<?= (int)$la['id'] ?>" <?= (string)$product['gain_loss_account_id'] === (string)$la['id'] ? 'selected' : '' ?>><?= e($la['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-sm-6">
                  <label class="form-label">Capitalization Threshold</label>
                  <input type="number" step="0.01" min="0" name="capitalization_threshold" class="form-control" value="<?= e($product['capitalization_threshold']) ?>">
                </div>
                <div class="col-sm-6 d-flex align-items-end">
                  <div class="form-check form-switch mb-2">
                    <input type="checkbox" class="form-check-input" id="includePeriodClosing" name="include_in_period_closing_entry" value="1" <?= !empty($product['include_in_period_closing_entry']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="includePeriodClosing">Include in Period Closing Entry</label>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div class="col-lg-6">
            <div class="card p-3 mb-3">
              <h6 class="mb-1">Cost Allocation</h6>
              <p class="text-muted small mb-3">Configure additional costing parameters.</p>
              <div class="row g-3">
                <div class="col-sm-6">
                  <label class="form-label">Cost Center</label>
                  <input type="text" name="cost_center" class="form-control" value="<?= e($product['cost_center'] ?? '') ?>">
                </div>
                <div class="col-sm-6">
                  <label class="form-label">Project (Default)</label>
                  <input type="text" name="default_project" class="form-control" value="<?= e($product['default_project'] ?? '') ?>">
                </div>
                <div class="col-sm-6">
                  <label class="form-label">Activity Type</label>
                  <input type="text" name="activity_type" class="form-control" value="<?= e($product['activity_type'] ?? '') ?>">
                </div>
                <div class="col-sm-6">
                  <label class="form-label">Budget (Optional)</label>
                  <input type="text" name="budget" class="form-control" value="<?= e($product['budget'] ?? '') ?>">
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="tab-pane fade <?= $activeTab === 'tax' ? 'show active' : '' ?>" id="pane-tax">
        <div class="row g-3">
          <div class="col-lg-6">
            <div class="card p-3 mb-3">
              <h6 class="mb-1">Tax Category</h6>
              <p class="text-muted small mb-3">Define the default tax category for this item.</p>
              <div class="row g-3">
                <div class="col-sm-6">
                  <label class="form-label">Tax Category</label>
                  <input type="text" name="tax_category" class="form-control" value="<?= e($product['tax_category'] ?? '') ?>">
                </div>
                <div class="col-sm-6">
                  <label class="form-label">HSN/SAC Code</label>
                  <input type="text" class="form-control" value="<?= e($product['hsn_sac_code'] ?? '') ?>" disabled>
                  <div class="form-text">Set on the Details tab.</div>
                </div>
                <div class="col-sm-6 form-check">
                  <input type="checkbox" class="form-check-input" id="isNilRated" name="is_nil_rated" value="1" <?= !empty($product['is_nil_rated']) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="isNilRated">Is Nil Rated</label>
                </div>
                <div class="col-sm-6 form-check">
                  <input type="checkbox" class="form-check-input" id="isExemptFromTax" name="is_exempt_from_tax" value="1" <?= !empty($product['is_exempt_from_tax']) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="isExemptFromTax">Is Exempt from Tax</label>
                </div>
                <div class="col-sm-6 form-check">
                  <input type="checkbox" class="form-check-input" id="reverseChargeApplicable" name="reverse_charge_applicable" value="1" <?= !empty($product['reverse_charge_applicable']) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="reverseChargeApplicable">Reverse Charge Applicable</label>
                </div>
                <div class="col-sm-6 form-check">
                  <input type="checkbox" class="form-check-input" id="tdsApplicable" name="tds_applicable" value="1" <?= !empty($product['tds_applicable']) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="tdsApplicable">TDS Applicable</label>
                </div>
              </div>
            </div>

            <div class="card p-3 mb-3">
              <h6 class="mb-1">Tax Template</h6>
              <p class="text-muted small mb-3">Select a tax template to automatically apply taxes and charges.</p>
              <select name="default_tax_template_id" class="form-select">
                <option value="">— None —</option>
                <?php foreach ($taxTemplates as $tt): ?>
                  <option value="<?= (int)$tt['id'] ?>" <?= (string)$product['default_tax_template_id'] === (string)$tt['id'] ? 'selected' : '' ?>><?= e($tt['name']) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-text mt-1">Manage templates under Sales &rsaquo; Tax Templates.</div>
            </div>

            <div class="card p-3 mb-3">
              <h6 class="mb-1">Price Includes Tax</h6>
              <p class="text-muted small mb-3">Define whether the item price includes applicable taxes.</p>
              <div class="form-check form-switch mb-2">
                <input type="checkbox" class="form-check-input" id="priceIncludesTax" name="price_includes_tax" value="1" <?= !empty($product['price_includes_tax']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="priceIncludesTax">Price Includes Tax</label>
              </div>
              <label class="form-label">Tax Calculation Based On</label>
              <select name="tax_calculation_based_on" class="form-select">
                <option value="Net Amount" <?= $product['tax_calculation_based_on'] === 'Net Amount' ? 'selected' : '' ?>>Net Amount</option>
                <option value="Gross Amount" <?= $product['tax_calculation_based_on'] === 'Gross Amount' ? 'selected' : '' ?>>Gross Amount</option>
              </select>
            </div>

            <div class="card p-3 mb-3">
              <h6 class="mb-1">Tax Exemptions &amp; Conditions</h6>
              <p class="text-muted small mb-3">Define tax exemptions or special conditions for this item (if any).</p>
              <div class="row g-3 mb-2">
                <div class="col-sm-6">
                  <label class="form-label">Tax Exemption Reason</label>
                  <input type="text" name="tax_exemption_reason" class="form-control" value="<?= e($product['tax_exemption_reason'] ?? '') ?>">
                </div>
                <div class="col-sm-6">
                  <label class="form-label">Applicable From</label>
                  <input type="date" name="tax_exemption_applicable_from" class="form-control" value="<?= e($product['tax_exemption_applicable_from'] ?? '') ?>">
                </div>
              </div>
              <label class="form-label">Notes</label>
              <textarea name="tax_notes" class="form-control" rows="2"><?= e($product['tax_notes'] ?? '') ?></textarea>
            </div>
          </div>

          <div class="col-lg-6">
            <div class="card p-3 mb-3">
              <h6 class="mb-1">Default Taxes and Charges</h6>
              <p class="text-muted small mb-3">Set default taxes and charges applied when this item is used in transactions.</p>
              <div class="table-responsive">
                <table class="table table-sm product-tax-rows">
                  <thead><tr><th>Tax / Charge Type</th><th>Account Head</th><th>Rate/Amount</th><th class="text-center">Incl.</th><th>Applicable On</th><th></th></tr></thead>
                  <tbody>
                  <?php if (!$productTaxes): $productTaxes = [['tax_charge_type' => '', 'account_head_id' => '', 'rate_or_amount' => 0, 'included_in_price' => 0, 'applicable_on' => 'net_total']]; endif; ?>
                  <?php foreach ($productTaxes as $ti => $tx): ?>
                    <tr data-row>
                      <td><input type="text" class="form-control form-control-sm" name="tax_charge_type[]" value="<?= e($tx['tax_charge_type'] ?? '') ?>"></td>
                      <td>
                        <select class="form-select form-select-sm" name="tax_account_head_id[]">
                          <option value="">— None —</option>
                          <?php foreach ($ledgerAccounts as $la): ?>
                            <option value="<?= (int)$la['id'] ?>" <?= (string)($tx['account_head_id'] ?? '') === (string)$la['id'] ? 'selected' : '' ?>><?= e($la['name']) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </td>
                      <td><input type="number" step="0.01" min="0" class="form-control form-control-sm" name="tax_rate_or_amount[]" value="<?= e($tx['rate_or_amount'] ?? 0) ?>"></td>
                      <td class="text-center">
                        <input type="hidden" class="js-tax-included-hidden" name="tax_included_in_price[]" value="<?= !empty($tx['included_in_price']) ? '1' : '0' ?>">
                        <input type="checkbox" class="form-check-input js-tax-included-checkbox" <?= !empty($tx['included_in_price']) ? 'checked' : '' ?>>
                      </td>
                      <td>
                        <select class="form-select form-select-sm" name="tax_applicable_on[]">
                          <option value="net_total" <?= ($tx['applicable_on'] ?? 'net_total') === 'net_total' ? 'selected' : '' ?>>Net Total</option>
                          <option value="grand_total" <?= ($tx['applicable_on'] ?? '') === 'grand_total' ? 'selected' : '' ?>>Grand Total</option>
                        </select>
                      </td>
                      <td><button type="button" class="btn btn-sm btn-outline-danger product-tax-remove-row"><i class="fa-solid fa-xmark"></i></button></td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <button type="button" class="btn btn-sm btn-outline-brand product-tax-add-row"><i class="fa-solid fa-plus"></i> Add Tax/Charge</button>
            </div>

            <div class="card p-3 mb-3">
              <h6 class="mb-1">Additional Charges</h6>
              <p class="text-muted small mb-3">Set any additional charges (freight, handling, etc.) for this item.</p>
              <div class="table-responsive">
                <table class="table table-sm product-charge-rows">
                  <thead><tr><th>Charge Type</th><th>Account Head</th><th>Rate/Amount</th><th>Applicable On</th><th></th></tr></thead>
                  <tbody>
                  <?php if (!$productCharges): $productCharges = [['charge_type' => '', 'account_head_id' => '', 'rate_or_amount' => 0, 'applicable_on' => 'net_total']]; endif; ?>
                  <?php foreach ($productCharges as $ch): ?>
                    <tr data-row>
                      <td><input type="text" class="form-control form-control-sm" name="charge_type[]" value="<?= e($ch['charge_type'] ?? '') ?>"></td>
                      <td>
                        <select class="form-select form-select-sm" name="charge_account_head_id[]">
                          <option value="">— None —</option>
                          <?php foreach ($ledgerAccounts as $la): ?>
                            <option value="<?= (int)$la['id'] ?>" <?= (string)($ch['account_head_id'] ?? '') === (string)$la['id'] ? 'selected' : '' ?>><?= e($la['name']) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </td>
                      <td><input type="number" step="0.01" min="0" class="form-control form-control-sm" name="charge_rate_or_amount[]" value="<?= e($ch['rate_or_amount'] ?? 0) ?>"></td>
                      <td>
                        <select class="form-select form-select-sm" name="charge_applicable_on[]">
                          <option value="net_total" <?= ($ch['applicable_on'] ?? 'net_total') === 'net_total' ? 'selected' : '' ?>>Net Total</option>
                          <option value="grand_total" <?= ($ch['applicable_on'] ?? '') === 'grand_total' ? 'selected' : '' ?>>Grand Total</option>
                        </select>
                      </td>
                      <td><button type="button" class="btn btn-sm btn-outline-danger product-charge-remove-row"><i class="fa-solid fa-xmark"></i></button></td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <button type="button" class="btn btn-sm btn-outline-brand product-charge-add-row"><i class="fa-solid fa-plus"></i> Add Charge</button>
            </div>
          </div>
        </div>
      </div>

      <div class="tab-pane fade <?= $activeTab === 'sales' ? 'show active' : '' ?>" id="pane-sales">
        <div class="row g-3">
          <div class="col-lg-6">
            <div class="card p-3 mb-3">
              <h6 class="mb-1">Sales Settings</h6>
              <p class="text-muted small mb-3">Define default sales behavior for this item.</p>
              <div class="row g-3">
                <div class="col-sm-6">
                  <label class="form-label">Item Customer Group</label>
                  <input type="text" name="item_customer_group" class="form-control" value="<?= e($product['item_customer_group'] ?? '') ?>">
                </div>
                <div class="col-sm-6">
                  <label class="form-label">Preferred Warehouse for Sales</label>
                  <select name="preferred_sales_warehouse_id" class="form-select">
                    <option value="">— None —</option>
                    <?php foreach ($warehouses as $w): ?>
                      <option value="<?= (int)$w['id'] ?>" <?= (string)$product['preferred_sales_warehouse_id'] === (string)$w['id'] ? 'selected' : '' ?>><?= e($w['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-sm-4">
                  <label class="form-label">Minimum Order Qty</label>
                  <input type="number" min="0" name="sales_min_order_qty" class="form-control" value="<?= e($product['sales_min_order_qty']) ?>">
                </div>
                <div class="col-sm-4">
                  <label class="form-label">Maximum Order Qty</label>
                  <input type="number" min="0" name="sales_max_order_qty" class="form-control" value="<?= e($product['sales_max_order_qty']) ?>">
                </div>
                <div class="col-sm-4">
                  <label class="form-label">Order Qty Increment</label>
                  <input type="number" min="1" name="sales_order_qty_increment" class="form-control" value="<?= e($product['sales_order_qty_increment']) ?>">
                </div>
                <div class="col-sm-4">
                  <label class="form-label">Lead Time (Days)</label>
                  <input type="number" min="0" name="sales_lead_time_days" class="form-control" value="<?= e($product['sales_lead_time_days']) ?>">
                </div>
                <div class="col-sm-4">
                  <label class="form-label">Delivery Time (Days)</label>
                  <input type="number" min="0" name="delivery_time_days" class="form-control" value="<?= e($product['delivery_time_days']) ?>">
                </div>
                <div class="col-sm-4">
                  <label class="form-label">Weight for Shipping (kg)</label>
                  <input type="number" step="0.001" min="0" name="weight_for_shipping_kg" class="form-control" value="<?= e($product['weight_for_shipping_kg']) ?>">
                </div>
              </div>
            </div>

            <div class="card p-3 mb-3">
              <h6 class="mb-1">Sales Description</h6>
              <p class="text-muted small mb-3">Description that will appear in sales transactions (optional).</p>
              <textarea name="sales_description" class="form-control" rows="3"><?= e($product['sales_description'] ?? '') ?></textarea>
            </div>

            <div class="card p-3 mb-3">
              <h6 class="mb-1">Marketing Information</h6>
              <p class="text-muted small mb-3">Additional details for sales and customer reference.</p>
              <div class="row g-3">
                <div class="col-sm-4">
                  <label class="form-label">Brand</label>
                  <input type="text" class="form-control" value="<?= e($brandName ?? '') ?>" disabled>
                  <div class="form-text">Set on the Details tab.</div>
                </div>
                <div class="col-sm-4">
                  <label class="form-label">Marketing Material</label>
                  <input type="text" name="marketing_material" class="form-control" value="<?= e($product['marketing_material'] ?? '') ?>">
                </div>
                <div class="col-sm-4">
                  <label class="form-label">Item Website</label>
                  <input type="text" name="item_website" class="form-control" value="<?= e($product['item_website'] ?? '') ?>">
                </div>
              </div>
            </div>

            <div class="card p-3 mb-3">
              <h6 class="mb-1">Sales UOM &amp; Packaging</h6>
              <p class="text-muted small mb-3">Sales unit and conversion factor (set on the Details tab; packaging fields are on the Purchase tab).</p>
              <div class="row g-3">
                <div class="col-sm-6">
                  <label class="form-label">Sales UOM</label>
                  <input type="text" class="form-control" value="<?= e($product['sales_uom'] ?: $product['unit']) ?>" disabled>
                </div>
                <div class="col-sm-6">
                  <label class="form-label">Sales UOM Conversion Factor</label>
                  <input type="text" class="form-control" value="<?= e($product['sales_uom_conversion_factor']) ?>" disabled>
                </div>
              </div>
            </div>
          </div>

          <div class="col-lg-6">
            <div class="card p-3 mb-3">
              <h6 class="mb-1">Customer Specific Settings</h6>
              <p class="text-muted small mb-3">Define rules for specific customers (optional).</p>
              <div class="table-responsive">
                <table class="table table-sm product-cr-rows">
                  <thead><tr><th>Customer</th><th>Group</th><th>Price List</th><th>Disc. %</th><th>Min Qty</th><th>Max Qty</th><th></th></tr></thead>
                  <tbody>
                  <?php if (!$productCustomerRules): $productCustomerRules = [['customer_id' => '', 'customer_group' => '', 'price_list_id' => '', 'discount_percent' => 0, 'min_qty' => '', 'max_qty' => '']]; endif; ?>
                  <?php foreach ($productCustomerRules as $cr): ?>
                    <tr data-row>
                      <td>
                        <select class="form-select form-select-sm" name="cr_customer_id[]">
                          <option value="">— Select —</option>
                          <?php foreach ($customers as $c): ?>
                            <option value="<?= (int)$c['id'] ?>" <?= (string)($cr['customer_id'] ?? '') === (string)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </td>
                      <td><input type="text" class="form-control form-control-sm" name="cr_customer_group[]" value="<?= e($cr['customer_group'] ?? '') ?>"></td>
                      <td>
                        <select class="form-select form-select-sm" name="cr_price_list_id[]">
                          <option value="">— Any —</option>
                          <?php foreach ($priceLists as $pl): ?>
                            <option value="<?= (int)$pl['id'] ?>" <?= (string)($cr['price_list_id'] ?? '') === (string)$pl['id'] ? 'selected' : '' ?>><?= e($pl['name']) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </td>
                      <td><input type="number" step="0.01" min="0" max="100" class="form-control form-control-sm" name="cr_discount_percent[]" value="<?= e($cr['discount_percent'] ?? 0) ?>"></td>
                      <td><input type="number" min="0" class="form-control form-control-sm" name="cr_min_qty[]" value="<?= e($cr['min_qty'] ?? '') ?>"></td>
                      <td><input type="number" min="0" class="form-control form-control-sm" name="cr_max_qty[]" value="<?= e($cr['max_qty'] ?? '') ?>"></td>
                      <td><button type="button" class="btn btn-sm btn-outline-danger product-cr-remove-row"><i class="fa-solid fa-xmark"></i></button></td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <button type="button" class="btn btn-sm btn-outline-brand product-cr-add-row"><i class="fa-solid fa-plus"></i> Add Customer Rule</button>
            </div>

            <div class="card p-3 mb-3">
              <h6 class="mb-1">Item Availability for Sales</h6>
              <p class="text-muted small mb-3">Control item availability in different sales channels.</p>
              <?php
              $availFields = [
                  'available_for_online_sales' => 'Available for Online Sales', 'available_for_retail_sales' => 'Available for Retail Sales',
                  'available_for_b2b_sales' => 'Available for B2B Sales', 'not_discountable' => 'Not Discountable',
                  'requires_approval_for_discount' => 'Requires Approval for Discount', 'show_in_customer_portal' => 'Show in Customer Portal',
              ];
              ?>
              <div class="row g-1">
                <?php foreach ($availFields as $avKey => $avLabel): ?>
                  <div class="col-6 form-check">
                    <input type="checkbox" class="form-check-input" id="av_<?= e($avKey) ?>" name="<?= e($avKey) ?>" value="1" <?= !empty($product[$avKey]) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="av_<?= e($avKey) ?>"><?= e($avLabel) ?></label>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>

            <div class="card p-3 mb-3">
              <h6 class="mb-1">Sales Forecasting (Optional)</h6>
              <p class="text-muted small mb-3">Helps in demand planning and availability.</p>
              <div class="row g-3">
                <div class="col-sm-6">
                  <label class="form-label">Default Monthly Sales Qty</label>
                  <input type="number" min="0" name="default_monthly_sales_qty" class="form-control" value="<?= e($product['default_monthly_sales_qty']) ?>">
                </div>
                <div class="col-sm-6">
                  <label class="form-label">Seasonal Demand</label>
                  <select name="seasonal_demand" class="form-select">
                    <?php foreach (['Low', 'Normal', 'High'] as $sd): ?>
                      <option value="<?= e($sd) ?>" <?= $product['seasonal_demand'] === $sd ? 'selected' : '' ?>><?= e($sd) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="tab-pane fade <?= $activeTab === 'purchase' ? 'show active' : '' ?>" id="pane-purchase">
        <div class="row g-3">
          <div class="col-lg-6">
            <div class="card p-3 mb-3">
              <h6 class="mb-1">Purchase Settings</h6>
              <p class="text-muted small mb-3">Define default purchase behavior for this item.</p>
              <div class="row g-3">
                <div class="col-sm-6">
                  <label class="form-label">Default Supplier</label>
                  <select name="default_supplier_id" class="form-select">
                    <option value="">— None —</option>
                    <?php foreach ($vendors as $v): ?>
                      <option value="<?= (int)$v['id'] ?>" <?= (string)$product['default_supplier_id'] === (string)$v['id'] ? 'selected' : '' ?>><?= e($v['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-sm-6">
                  <label class="form-label">Default Purchase Price List</label>
                  <select name="default_purchase_price_list_id" class="form-select">
                    <option value="">— None —</option>
                    <?php foreach ($priceLists as $pl): ?>
                      <option value="<?= (int)$pl['id'] ?>" <?= (string)$product['default_purchase_price_list_id'] === (string)$pl['id'] ? 'selected' : '' ?>><?= e($pl['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-sm-4">
                  <label class="form-label">Minimum Order Qty</label>
                  <input type="number" min="0" name="purchase_min_order_qty" class="form-control" value="<?= e($product['purchase_min_order_qty']) ?>">
                </div>
                <div class="col-sm-4">
                  <label class="form-label">Maximum Order Qty</label>
                  <input type="number" min="0" name="purchase_max_order_qty" class="form-control" value="<?= e($product['purchase_max_order_qty']) ?>">
                </div>
                <div class="col-sm-4">
                  <label class="form-label">Order Qty Increment</label>
                  <input type="number" min="1" name="purchase_order_qty_increment" class="form-control" value="<?= e($product['purchase_order_qty_increment']) ?>">
                </div>
                <div class="col-sm-4">
                  <label class="form-label">Lead Time (Days)</label>
                  <input type="number" min="0" name="lead_time_days" class="form-control" value="<?= e($product['lead_time_days']) ?>">
                  <div class="form-text">Same field as Lead Time on the Details tab.</div>
                </div>
                <div class="col-sm-4">
                  <label class="form-label">Receipt Tolerance (%)</label>
                  <input type="number" step="0.01" min="0" name="receipt_tolerance_percent" class="form-control" value="<?= e($product['receipt_tolerance_percent']) ?>">
                </div>
                <div class="col-sm-4">
                  <label class="form-label">Over Delivery Allowance (%)</label>
                  <input type="number" step="0.01" min="0" name="over_delivery_allowance_percent" class="form-control" value="<?= e($product['over_delivery_allowance_percent']) ?>">
                </div>
              </div>
            </div>

            <div class="card p-3 mb-3">
              <h6 class="mb-1">Purchase UOM &amp; Packaging</h6>
              <p class="text-muted small mb-3">Define purchase unit and packaging details.</p>
              <div class="row g-3">
                <div class="col-sm-6">
                  <label class="form-label">Purchase UOM</label>
                  <input type="text" class="form-control" value="<?= e($product['purchase_uom'] ?: $product['unit']) ?>" disabled>
                  <div class="form-text">Set on the Details tab.</div>
                </div>
                <div class="col-sm-6">
                  <label class="form-label">Conversion Factor (to Stock UOM)</label>
                  <input type="text" class="form-control" value="<?= e($product['purchase_uom_conversion_factor']) ?>" disabled>
                </div>
                <div class="col-sm-6">
                  <label class="form-label">Default Package Type</label>
                  <input type="text" name="default_package_type" class="form-control" value="<?= e($product['default_package_type'] ?? '') ?>">
                </div>
                <div class="col-sm-6">
                  <label class="form-label">Items per Package</label>
                  <input type="number" min="1" name="items_per_package" class="form-control" value="<?= e($product['items_per_package']) ?>">
                </div>
              </div>
            </div>

            <div class="card p-3 mb-3">
              <h6 class="mb-1">Purchase Description</h6>
              <p class="text-muted small mb-3">Description for purchase orders (optional).</p>
              <textarea name="purchase_description" class="form-control" rows="3"><?= e($product['purchase_description'] ?? '') ?></textarea>
            </div>
          </div>

          <div class="col-lg-6">
            <div class="card p-3 mb-3">
              <h6 class="mb-1">Preferred Suppliers</h6>
              <p class="text-muted small mb-3">Maintain preferred suppliers and their purchase terms.</p>
              <div class="table-responsive">
                <table class="table table-sm product-ps-rows">
                  <thead><tr><th>Supplier</th><th>Part No.</th><th>Lead Time</th><th>Last Rate</th><th class="text-center">Preferred</th><th></th></tr></thead>
                  <tbody>
                  <?php if (!$productSuppliers): $productSuppliers = [['supplier_id' => '', 'supplier_part_no' => '', 'lead_time_days' => '', 'last_purchase_rate' => '', 'is_preferred' => 1]]; endif; ?>
                  <?php foreach ($productSuppliers as $pi => $ps): ?>
                    <tr data-row>
                      <td>
                        <select class="form-select form-select-sm" name="ps_supplier_id[]">
                          <option value="">— Select —</option>
                          <?php foreach ($vendors as $v): ?>
                            <option value="<?= (int)$v['id'] ?>" <?= (string)($ps['supplier_id'] ?? '') === (string)$v['id'] ? 'selected' : '' ?>><?= e($v['name']) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </td>
                      <td><input type="text" class="form-control form-control-sm" name="ps_supplier_part_no[]" value="<?= e($ps['supplier_part_no'] ?? '') ?>"></td>
                      <td><input type="number" min="0" class="form-control form-control-sm" name="ps_lead_time_days[]" value="<?= e($ps['lead_time_days'] ?? '') ?>"></td>
                      <td><input type="number" step="0.01" min="0" class="form-control form-control-sm" name="ps_last_purchase_rate[]" value="<?= e($ps['last_purchase_rate'] ?? '') ?>"></td>
                      <td class="text-center"><input type="radio" name="ps_preferred_index" value="<?= (int)$pi ?>" <?= !empty($ps['is_preferred']) ? 'checked' : '' ?>></td>
                      <td><button type="button" class="btn btn-sm btn-outline-danger product-ps-remove-row"><i class="fa-solid fa-xmark"></i></button></td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <button type="button" class="btn btn-sm btn-outline-brand product-ps-add-row"><i class="fa-solid fa-plus"></i> Add Supplier</button>
            </div>

            <div class="card p-3 mb-3">
              <h6 class="mb-1">Additional Purchase Options</h6>
              <?php
              $purchOptions = [
                  'requires_purchase_order' => 'Requires Purchase Order', 'allow_receipt_without_po' => 'Allow Receipt without Purchase Order',
                  'track_supplier_batch_serial' => 'Track Supplier Batch / Serial No.', 'include_in_supplier_portal' => 'Include in Supplier Portal',
                  'is_drop_ship_item' => 'Is Drop Ship Item', 'allow_subcontracting' => 'Allow Subcontracting',
                  'maintain_last_purchase_rate' => 'Maintain Last Purchase Rate',
              ];
              ?>
              <div class="row g-1">
                <?php foreach ($purchOptions as $poKey => $poLabel): ?>
                  <div class="col-6 form-check">
                    <input type="checkbox" class="form-check-input" id="po_<?= e($poKey) ?>" name="<?= e($poKey) ?>" value="1" <?= !empty($product[$poKey]) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="po_<?= e($poKey) ?>"><?= e($poLabel) ?></label>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>

            <div class="card p-3 mb-3">
              <h6 class="mb-1">Quality &amp; Inspection</h6>
              <p class="text-muted small mb-3">Define quality inspection settings for purchase receipts.</p>
              <div class="row g-3">
                <div class="col-sm-6">
                  <label class="form-label">Inspection Required</label>
                  <select name="inspection_required" class="form-select">
                    <option value="No" <?= $product['inspection_required'] === 'No' ? 'selected' : '' ?>>No</option>
                    <option value="Yes" <?= $product['inspection_required'] === 'Yes' ? 'selected' : '' ?>>Yes</option>
                  </select>
                </div>
                <div class="col-sm-6">
                  <label class="form-label">Sampling Rate (%)</label>
                  <input type="number" step="0.01" min="0" max="100" name="sampling_rate_percent" class="form-control" value="<?= e($product['sampling_rate_percent']) ?>">
                </div>
                <div class="col-sm-6">
                  <label class="form-label">Quality Rating (Default)</label>
                  <input type="text" name="quality_rating_default" class="form-control" value="<?= e($product['quality_rating_default'] ?? '') ?>">
                </div>
                <div class="col-sm-6 d-flex align-items-end">
                  <div class="form-check mb-2">
                    <input type="checkbox" class="form-check-input" id="rejectIfQcFails" name="reject_if_quality_check_fails" value="1" <?= !empty($product['reject_if_quality_check_fails']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="rejectIfQcFails">Reject if Quality Check Fails</label>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="page-actions mt-3">
      <button type="submit" class="btn btn-brand">Save</button>
      <a href="products.php" class="btn btn-outline-secondary">Cancel</a>
    </div>
  </form>
</div>
<?php
$extra_js_inline = "
document.addEventListener('DOMContentLoaded', function () {
  var wrap = document.querySelector('.product-barcode-rows');
  if (!wrap) return;
  var tbody = wrap.querySelector('tbody');

  wrap.closest('.card').addEventListener('click', function (e) {
    if (e.target.closest('.product-barcode-add-row')) {
      var rows = tbody.querySelectorAll('tr[data-row]');
      var clone = rows[rows.length - 1].cloneNode(true);
      clone.querySelectorAll('input[type=text]').forEach(function (inp) { inp.value = ''; });
      clone.querySelectorAll('input[type=radio]').forEach(function (r) { r.checked = false; });
      tbody.appendChild(clone);
      return;
    }
    var rm = e.target.closest('.product-barcode-remove-row');
    if (rm) {
      var rows2 = tbody.querySelectorAll('tr[data-row]');
      if (rows2.length > 1) {
        rm.closest('tr[data-row]').remove();
      }
    }
  });
});

document.addEventListener('DOMContentLoaded', function () {
  var wrap = document.querySelector('.product-uom-rows');
  if (!wrap) return;
  var tbody = wrap.querySelector('tbody');

  wrap.closest('.card').addEventListener('click', function (e) {
    if (e.target.closest('.product-uom-add-row')) {
      var rows = tbody.querySelectorAll('tr[data-row]');
      var clone = rows[rows.length - 1].cloneNode(true);
      clone.querySelectorAll('input').forEach(function (inp) { inp.value = ''; });
      clone.querySelectorAll('select').forEach(function (sel) { sel.selectedIndex = 0; });
      tbody.appendChild(clone);
      return;
    }
    var rm = e.target.closest('.product-uom-remove-row');
    if (rm) {
      var rows2 = tbody.querySelectorAll('tr[data-row]');
      if (rows2.length > 1) {
        rm.closest('tr[data-row]').remove();
      }
    }
  });
});

document.addEventListener('DOMContentLoaded', function () {
  var wrap = document.querySelector('.product-cp-rows');
  if (!wrap) return;
  var tbody = wrap.querySelector('tbody');

  wrap.closest('.card').addEventListener('click', function (e) {
    if (e.target.closest('.product-cp-add-row')) {
      var rows = tbody.querySelectorAll('tr[data-row]');
      var clone = rows[rows.length - 1].cloneNode(true);
      clone.querySelectorAll('input').forEach(function (inp) { inp.value = inp.name === 'cp_discount_percent[]' ? '0' : ''; });
      clone.querySelectorAll('select').forEach(function (sel) { sel.selectedIndex = 0; });
      tbody.appendChild(clone);
      return;
    }
    var rm = e.target.closest('.product-cp-remove-row');
    if (rm) {
      var rows2 = tbody.querySelectorAll('tr[data-row]');
      if (rows2.length > 1) {
        rm.closest('tr[data-row]').remove();
      }
    }
  });
});

document.addEventListener('DOMContentLoaded', function () {
  var wrap = document.querySelector('.product-tax-rows');
  if (!wrap) return;
  var tbody = wrap.querySelector('tbody');

  function syncIncludedHidden(row) {
    var hidden = row.querySelector('.js-tax-included-hidden');
    var checkbox = row.querySelector('.js-tax-included-checkbox');
    if (hidden && checkbox) hidden.value = checkbox.checked ? '1' : '0';
  }

  wrap.addEventListener('change', function (e) {
    if (e.target.classList.contains('js-tax-included-checkbox')) {
      syncIncludedHidden(e.target.closest('tr[data-row]'));
    }
  });

  wrap.closest('.card').addEventListener('click', function (e) {
    if (e.target.closest('.product-tax-add-row')) {
      var rows = tbody.querySelectorAll('tr[data-row]');
      var clone = rows[rows.length - 1].cloneNode(true);
      clone.querySelectorAll('input[type=text], input[type=number]').forEach(function (inp) { inp.value = inp.name === 'tax_rate_or_amount[]' ? '0' : ''; });
      clone.querySelectorAll('.js-tax-included-checkbox').forEach(function (cb) { cb.checked = false; });
      clone.querySelectorAll('.js-tax-included-hidden').forEach(function (h) { h.value = '0'; });
      clone.querySelectorAll('select').forEach(function (sel) { sel.selectedIndex = 0; });
      tbody.appendChild(clone);
      return;
    }
    var rm = e.target.closest('.product-tax-remove-row');
    if (rm) {
      var rows2 = tbody.querySelectorAll('tr[data-row]');
      if (rows2.length > 1) {
        rm.closest('tr[data-row]').remove();
      }
    }
  });
});

document.addEventListener('DOMContentLoaded', function () {
  var wrap = document.querySelector('.product-charge-rows');
  if (!wrap) return;
  var tbody = wrap.querySelector('tbody');

  wrap.closest('.card').addEventListener('click', function (e) {
    if (e.target.closest('.product-charge-add-row')) {
      var rows = tbody.querySelectorAll('tr[data-row]');
      var clone = rows[rows.length - 1].cloneNode(true);
      clone.querySelectorAll('input[type=text], input[type=number]').forEach(function (inp) { inp.value = inp.name === 'charge_rate_or_amount[]' ? '0' : ''; });
      clone.querySelectorAll('select').forEach(function (sel) { sel.selectedIndex = 0; });
      tbody.appendChild(clone);
      return;
    }
    var rm = e.target.closest('.product-charge-remove-row');
    if (rm) {
      var rows2 = tbody.querySelectorAll('tr[data-row]');
      if (rows2.length > 1) {
        rm.closest('tr[data-row]').remove();
      }
    }
  });
});

document.addEventListener('DOMContentLoaded', function () {
  var wrap = document.querySelector('.product-cr-rows');
  if (!wrap) return;
  var tbody = wrap.querySelector('tbody');

  wrap.closest('.card').addEventListener('click', function (e) {
    if (e.target.closest('.product-cr-add-row')) {
      var rows = tbody.querySelectorAll('tr[data-row]');
      var clone = rows[rows.length - 1].cloneNode(true);
      clone.querySelectorAll('input').forEach(function (inp) { inp.value = inp.name === 'cr_discount_percent[]' ? '0' : ''; });
      clone.querySelectorAll('select').forEach(function (sel) { sel.selectedIndex = 0; });
      tbody.appendChild(clone);
      return;
    }
    var rm = e.target.closest('.product-cr-remove-row');
    if (rm) {
      var rows2 = tbody.querySelectorAll('tr[data-row]');
      if (rows2.length > 1) {
        rm.closest('tr[data-row]').remove();
      }
    }
  });
});

document.addEventListener('DOMContentLoaded', function () {
  var wrap = document.querySelector('.product-ps-rows');
  if (!wrap) return;
  var tbody = wrap.querySelector('tbody');

  wrap.closest('.card').addEventListener('click', function (e) {
    if (e.target.closest('.product-ps-add-row')) {
      var rows = tbody.querySelectorAll('tr[data-row]');
      var clone = rows[rows.length - 1].cloneNode(true);
      clone.querySelectorAll('input[type=text], input[type=number]').forEach(function (inp) { inp.value = ''; });
      clone.querySelectorAll('input[type=radio]').forEach(function (r) { r.checked = false; });
      clone.querySelectorAll('select').forEach(function (sel) { sel.selectedIndex = 0; });
      tbody.appendChild(clone);
      return;
    }
    var rm = e.target.closest('.product-ps-remove-row');
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
