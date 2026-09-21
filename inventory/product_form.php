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
}
// Captured before any POST handling touches $product — quantity and the
// current image are never taken from client input on an edit; quantity
// only ever changes via Stock Movements (which keeps stock_bins and the
// products.quantity aggregate consistent), and the image only changes
// via a new upload or the explicit "remove" checkbox below.
$existingQuantity = $id ? (int)$product['quantity'] : 0;
$existingImage = $id ? $product['image'] : null;

$activeTab = in_array(input('tab'), ['inventory'], true) ? input('tab') : 'details';
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
                'item_type', 'valuation_method', 'standard_weight_kg', 'standard_volume_ltr', 'gross_weight_kg', 'net_weight_kg', 'tags',
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
}

$categories = db()->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();
$itemCategories = db()->query("SELECT id, name FROM item_categories WHERE status='active' ORDER BY name")->fetchAll();
$brands = db()->query("SELECT id, name FROM brands WHERE status='active' ORDER BY name")->fetchAll();
$units = db()->query("SELECT id, name FROM uom WHERE status = 'active' ORDER BY name")->fetchAll();
$warehouses = leaf_warehouses();
$priceLists = db()->query("SELECT id, name FROM price_lists WHERE status='active' ORDER BY name")->fetchAll();

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
";
require __DIR__ . '/../includes/footer.php';
?>
