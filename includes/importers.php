<?php
/**
 * Registry of importable entity types for the Excel data-import feature
 * (imports/*.php). Each entry describes one spreadsheet-importable entity:
 * its columns (used to build the downloadable template and the preview
 * table), which module permission gates it, and how to validate/commit one
 * row. Adding a new importable entity means adding one array entry plus a
 * pair of validate_row/commit_row functions — the upload/preview/commit
 * pages themselves are entirely generic over this registry.
 *
 * validate_row(array $row): array
 *   $row is the spreadsheet row as ['Header Label' => cell value, ...].
 *   Returns ['ok' => bool, 'errors' => string[], 'data' => array|null].
 *   Must not write to the database — preview runs this over every row
 *   before anything is committed, so the user sees every problem at once.
 *
 * commit_row(array $data): array
 *   $data is the 'data' array a passing validate_row returned. Only called
 *   during the real commit step, and expected to insert/update its own
 *   table(s) and return ['status' => 'success'|'error', 'message' =>
 *   string, 'record_id' => int|null].
 *
 * sample_rows(?int $limit): array
 *   Optional. Pulls real existing rows from the entity's own table for the
 *   template download's "+5/+50/All records" options (imports/template.php),
 *   shaped the same way validate_row's $row is: ['Header Label' => value,
 *   ...]. $limit is a row count, or null for every row with no LIMIT. An
 *   importer with no sample_rows callback just gets a blank template
 *   regardless of what the user picks.
 */

function importer_registry(): array
{
    return [
        'categories' => [
            'label' => 'Categories',
            'description' => 'Product categories, upserted by name.',
            'permission_module' => 'inventory',
            'columns' => [
                ['key' => 'Name', 'required' => true],
                ['key' => 'Description', 'required' => false],
            ],
            'validate_row' => 'importer_validate_categories',
            'commit_row' => 'importer_commit_categories',
            'sample_rows' => 'importer_sample_categories',
        ],
        'brands' => [
            'label' => 'Brands',
            'description' => 'Product brands, upserted by name.',
            'permission_module' => 'inventory',
            'columns' => [
                ['key' => 'Name', 'required' => true],
                ['key' => 'Status', 'required' => false, 'hint' => 'active or inactive (default active)'],
            ],
            'validate_row' => 'importer_validate_brands',
            'commit_row' => 'importer_commit_brands',
            'sample_rows' => 'importer_sample_brands',
        ],
        'uom' => [
            'label' => 'Units of Measure',
            'description' => 'Units of measure (pcs, kg, box, ...), upserted by name.',
            'permission_module' => 'inventory',
            'columns' => [
                ['key' => 'Name', 'required' => true],
                ['key' => 'Status', 'required' => false, 'hint' => 'active or inactive (default active)'],
            ],
            'validate_row' => 'importer_validate_uom',
            'commit_row' => 'importer_commit_uom',
            'sample_rows' => 'importer_sample_uom',
        ],
        'products' => [
            'label' => 'Items / Products',
            'description' => 'Item Master, upserted by SKU. Category/Item Category/Brand/Unit must already exist — import those first if needed.',
            'permission_module' => 'inventory',
            'columns' => [
                ['key' => 'SKU', 'required' => true],
                ['key' => 'Name', 'required' => true],
                ['key' => 'Category', 'required' => false, 'hint' => 'Must match an existing Category name'],
                ['key' => 'Item Category', 'required' => false, 'hint' => 'Must match an existing Item Category name'],
                ['key' => 'Brand', 'required' => false, 'hint' => 'Must match an existing Brand name'],
                ['key' => 'HSN/SAC Code', 'required' => false],
                ['key' => 'Unit', 'required' => false, 'hint' => 'Must match an existing Unit of Measure name; default pcs'],
                ['key' => 'Cost Price', 'required' => false],
                ['key' => 'Selling Price', 'required' => false],
                ['key' => 'Opening Stock', 'required' => false, 'hint' => 'Only applied when the item is first created, not on re-import'],
                ['key' => 'Warehouse', 'required' => false, 'hint' => 'Required if Opening Stock is given; must match an existing warehouse name'],
                ['key' => 'Reorder Level', 'required' => false],
                ['key' => 'Description', 'required' => false],
                ['key' => 'Status', 'required' => false, 'hint' => 'active or inactive (default active)'],
            ],
            'validate_row' => 'importer_validate_products',
            'commit_row' => 'importer_commit_products',
            'sample_rows' => 'importer_sample_products',
        ],
        'customers' => [
            'label' => 'Customers',
            'description' => 'Upserted by Customer Code when given, otherwise by exact Name match.',
            'permission_module' => 'sales',
            'columns' => [
                ['key' => 'Customer Code', 'required' => false, 'hint' => 'Leave blank to auto-generate (CUST-00001)'],
                ['key' => 'Name', 'required' => true],
                ['key' => 'Customer Type', 'required' => false, 'hint' => 'company or individual (default company)'],
                ['key' => 'Company', 'required' => false],
                ['key' => 'Email', 'required' => false],
                ['key' => 'Phone', 'required' => false],
                ['key' => 'Mobile', 'required' => false],
                ['key' => 'GSTIN', 'required' => false],
                ['key' => 'Address', 'required' => false],
                ['key' => 'Status', 'required' => false, 'hint' => 'active, inactive, or blocked (default active)'],
            ],
            'validate_row' => 'importer_validate_customers',
            'commit_row' => 'importer_commit_customers',
            'sample_rows' => 'importer_sample_customers',
        ],
        'vendors' => [
            'label' => 'Vendors',
            'description' => 'Upserted by exact Name match.',
            'permission_module' => 'procurement',
            'columns' => [
                ['key' => 'Name', 'required' => true],
                ['key' => 'Company', 'required' => false],
                ['key' => 'Email', 'required' => false],
                ['key' => 'Phone', 'required' => false],
                ['key' => 'Address', 'required' => false],
            ],
            'validate_row' => 'importer_validate_vendors',
            'commit_row' => 'importer_commit_vendors',
            'sample_rows' => 'importer_sample_vendors',
        ],
        'chart_of_accounts' => [
            'label' => 'Chart of Accounts',
            'description' => 'Ledger accounts, upserted by exact Account Name match.',
            'permission_module' => 'finance',
            'columns' => [
                ['key' => 'Account Name', 'required' => true],
                ['key' => 'Account Code', 'required' => false],
                ['key' => 'Parent Account', 'required' => false, 'hint' => 'Must match an existing account name'],
                ['key' => 'Root Type', 'required' => false, 'hint' => 'asset, liability, equity, income, or expense'],
                ['key' => 'Account Type', 'required' => false, 'hint' => 'tax, income, expense, bank, cash, receivable, payable, etc. (default other)'],
                ['key' => 'Is Group', 'required' => false, 'hint' => 'yes or no (default no)'],
                ['key' => 'Opening Balance', 'required' => false],
                ['key' => 'Status', 'required' => false, 'hint' => 'active or inactive (default active)'],
            ],
            'validate_row' => 'importer_validate_chart_of_accounts',
            'commit_row' => 'importer_commit_chart_of_accounts',
            'sample_rows' => 'importer_sample_chart_of_accounts',
        ],
        'tax_templates' => [
            'label' => 'Tax Templates',
            'description' => 'Single-rate tax templates, upserted by Template Name. For multi-component tax (CGST+SGST etc.), add the other rate lines from the Tax Templates screen after import.',
            'permission_module' => 'finance',
            'columns' => [
                ['key' => 'Template Name', 'required' => true],
                ['key' => 'Tax Rate %', 'required' => true],
                ['key' => 'Account Name', 'required' => false, 'hint' => 'A ledger account from Chart of Accounts to post this tax to'],
                ['key' => 'Based On', 'required' => false, 'hint' => 'net_amount or actual_amount (default net_amount)'],
                ['key' => 'Status', 'required' => false, 'hint' => 'active or inactive (default active)'],
            ],
            'validate_row' => 'importer_validate_tax_templates',
            'commit_row' => 'importer_commit_tax_templates',
            'sample_rows' => 'importer_sample_tax_templates',
        ],
        'price_lists' => [
            'label' => 'Price Lists',
            'description' => 'Price list headers, upserted by List Name. Per-product rates are set from the Price List screen after import.',
            'permission_module' => 'finance',
            'columns' => [
                ['key' => 'List Name', 'required' => true],
                ['key' => 'Currency', 'required' => false, 'hint' => '3-letter code, default INR'],
                ['key' => 'Is Default', 'required' => false, 'hint' => 'yes or no (default no)'],
                ['key' => 'Status', 'required' => false, 'hint' => 'active or inactive (default active)'],
            ],
            'validate_row' => 'importer_validate_price_lists',
            'commit_row' => 'importer_commit_price_lists',
            'sample_rows' => 'importer_sample_price_lists',
        ],
        'purchase_orders' => [
            'label' => 'Purchase Orders',
            'description' => 'Creates real, live Purchase Orders in "pending" status. Give every line of the same order the same Order Ref to group them into one multi-line PO.',
            'permission_module' => 'procurement',
            'grouped' => true,
            'group_key' => 'Order Ref',
            'columns' => [
                ['key' => 'Order Ref', 'required' => true, 'hint' => 'Same value on every line of one order groups them together'],
                ['key' => 'Vendor', 'required' => true, 'hint' => 'Must match an existing vendor name — only needed on the order\'s first line'],
                ['key' => 'Order Date', 'required' => false, 'hint' => 'YYYY-MM-DD, default today — only needed on the order\'s first line'],
                ['key' => 'Required By', 'required' => false, 'hint' => 'YYYY-MM-DD — only needed on the order\'s first line'],
                ['key' => 'Warehouse', 'required' => false, 'hint' => 'Ship-to warehouse name — only needed on the order\'s first line'],
                ['key' => 'Notes', 'required' => false, 'hint' => 'Only needed on the order\'s first line'],
                ['key' => 'Product', 'required' => true, 'hint' => 'SKU or exact product name'],
                ['key' => 'Quantity', 'required' => true],
                ['key' => 'Rate', 'required' => true],
            ],
            'validate_group' => 'importer_validate_purchase_orders',
            'commit_group' => 'importer_commit_purchase_orders',
            'sample_rows' => 'importer_sample_purchase_orders',
        ],
        'sales_orders' => [
            'label' => 'Sales Orders',
            'description' => 'Creates real, live Sales Orders in "pending" status. Give every line of the same order the same Order Ref to group them into one multi-line SO.',
            'permission_module' => 'sales',
            'grouped' => true,
            'group_key' => 'Order Ref',
            'columns' => [
                ['key' => 'Order Ref', 'required' => true, 'hint' => 'Same value on every line of one order groups them together'],
                ['key' => 'Customer', 'required' => true, 'hint' => 'Must match an existing customer name — only needed on the order\'s first line'],
                ['key' => 'Order Date', 'required' => false, 'hint' => 'YYYY-MM-DD, default today — only needed on the order\'s first line'],
                ['key' => 'Required Delivery Date', 'required' => false, 'hint' => 'YYYY-MM-DD — only needed on the order\'s first line'],
                ['key' => 'Warehouse', 'required' => false, 'hint' => 'Only needed on the order\'s first line'],
                ['key' => 'Notes', 'required' => false, 'hint' => 'Only needed on the order\'s first line'],
                ['key' => 'Product', 'required' => true, 'hint' => 'SKU or exact product name'],
                ['key' => 'Quantity', 'required' => true],
                ['key' => 'Rate', 'required' => true],
            ],
            'validate_group' => 'importer_validate_sales_orders',
            'commit_group' => 'importer_commit_sales_orders',
            'sample_rows' => 'importer_sample_sales_orders',
        ],
    ];
}

function get_importer(string $key): ?array
{
    return importer_registry()[$key] ?? null;
}

/** Trims every cell and drops rows where every cell is blank. */
function importer_row_is_blank(array $row): bool
{
    foreach ($row as $v) {
        if (trim((string)$v) !== '') return false;
    }
    return true;
}

function importer_parse_status(mixed $value): string
{
    $v = strtolower(trim((string)$value));
    return $v === 'inactive' ? 'inactive' : 'active';
}

// --- Categories -------------------------------------------------------

function importer_validate_categories(array $row): array
{
    $name = trim((string)($row['Name'] ?? ''));
    if ($name === '') {
        return ['ok' => false, 'errors' => ['Name is required.'], 'data' => null];
    }
    $description = trim((string)($row['Description'] ?? ''));
    return ['ok' => true, 'errors' => [], 'data' => ['name' => $name, 'description' => $description !== '' ? $description : null]];
}

function importer_commit_categories(array $data): array
{
    $stmt = db()->prepare('SELECT id FROM categories WHERE name = ? LIMIT 1');
    $stmt->execute([$data['name']]);
    $existing = $stmt->fetch();
    if ($existing) {
        db()->prepare('UPDATE categories SET description = ? WHERE id = ?')->execute([$data['description'], $existing['id']]);
        return ['status' => 'success', 'message' => 'Updated existing category.', 'record_id' => (int)$existing['id']];
    }
    db()->prepare('INSERT INTO categories (name, description) VALUES (?, ?)')->execute([$data['name'], $data['description']]);
    return ['status' => 'success', 'message' => 'Created new category.', 'record_id' => (int)db()->lastInsertId()];
}

// --- Brands -------------------------------------------------------------

function importer_validate_brands(array $row): array
{
    $name = trim((string)($row['Name'] ?? ''));
    if ($name === '') {
        return ['ok' => false, 'errors' => ['Name is required.'], 'data' => null];
    }
    return ['ok' => true, 'errors' => [], 'data' => ['name' => $name, 'status' => importer_parse_status($row['Status'] ?? '')]];
}

function importer_commit_brands(array $data): array
{
    $stmt = db()->prepare('SELECT id FROM brands WHERE name = ? LIMIT 1');
    $stmt->execute([$data['name']]);
    $existing = $stmt->fetch();
    if ($existing) {
        db()->prepare('UPDATE brands SET status = ? WHERE id = ?')->execute([$data['status'], $existing['id']]);
        return ['status' => 'success', 'message' => 'Updated existing brand.', 'record_id' => (int)$existing['id']];
    }
    db()->prepare('INSERT INTO brands (name, status) VALUES (?, ?)')->execute([$data['name'], $data['status']]);
    return ['status' => 'success', 'message' => 'Created new brand.', 'record_id' => (int)db()->lastInsertId()];
}

// --- Units of Measure -----------------------------------------------------

function importer_validate_uom(array $row): array
{
    $name = trim((string)($row['Name'] ?? ''));
    if ($name === '') {
        return ['ok' => false, 'errors' => ['Name is required.'], 'data' => null];
    }
    return ['ok' => true, 'errors' => [], 'data' => ['name' => $name, 'status' => importer_parse_status($row['Status'] ?? '')]];
}

function importer_commit_uom(array $data): array
{
    $stmt = db()->prepare('SELECT id FROM uom WHERE name = ? LIMIT 1');
    $stmt->execute([$data['name']]);
    $existing = $stmt->fetch();
    if ($existing) {
        db()->prepare('UPDATE uom SET status = ? WHERE id = ?')->execute([$data['status'], $existing['id']]);
        return ['status' => 'success', 'message' => 'Updated existing unit.', 'record_id' => (int)$existing['id']];
    }
    db()->prepare('INSERT INTO uom (name, status) VALUES (?, ?)')->execute([$data['name'], $data['status']]);
    return ['status' => 'success', 'message' => 'Created new unit.', 'record_id' => (int)db()->lastInsertId()];
}

// --- Shared lookup helpers (used by every importer below) -----------------

/** Case-insensitive exact-name lookup. $table/$nameCol are always fixed strings from this file, never user input. */
function importer_find_id_by_name(string $table, string $nameCol, string $name): ?int
{
    $name = trim($name);
    if ($name === '') return null;
    $stmt = db()->prepare("SELECT id FROM $table WHERE LOWER($nameCol) = LOWER(?) LIMIT 1");
    $stmt->execute([$name]);
    $id = $stmt->fetchColumn();
    return $id !== false ? (int)$id : null;
}

/** Matches a product by exact SKU first (case-insensitive collation), then by exact product name. */
function importer_find_product(string $key): ?array
{
    $key = trim($key);
    if ($key === '') return null;
    $stmt = db()->prepare('SELECT id, unit FROM products WHERE sku = ? LIMIT 1');
    $stmt->execute([$key]);
    $p = $stmt->fetch();
    if ($p) return $p;
    $stmt = db()->prepare('SELECT id, unit FROM products WHERE LOWER(name) = LOWER(?) LIMIT 1');
    $stmt->execute([$key]);
    $p = $stmt->fetch();
    return $p ?: null;
}

/** Lenient date parse ("2024-01-15", "01/15/2024", "15-Jan-2024", ...) -> 'Y-m-d', or null if blank/unparseable. */
function importer_parse_date(mixed $value): ?string
{
    $value = trim((string)$value);
    if ($value === '') return null;
    $ts = strtotime($value);
    return $ts !== false ? date('Y-m-d', $ts) : null;
}

function importer_parse_bool(mixed $value): bool
{
    return in_array(strtolower(trim((string)$value)), ['yes', 'y', 'true', '1'], true);
}

// --- Products / Item Master -------------------------------------------

function importer_validate_products(array $row): array
{
    $errors = [];
    $sku = trim((string)($row['SKU'] ?? ''));
    $name = trim((string)($row['Name'] ?? ''));
    if ($sku === '') $errors[] = 'SKU is required.';
    if ($name === '') $errors[] = 'Name is required.';

    $categoryId = null;
    $categoryName = trim((string)($row['Category'] ?? ''));
    if ($categoryName !== '') {
        $categoryId = importer_find_id_by_name('categories', 'name', $categoryName);
        if (!$categoryId) $errors[] = "Category not found: $categoryName.";
    }

    $itemCategoryId = null;
    $itemCategoryName = trim((string)($row['Item Category'] ?? ''));
    if ($itemCategoryName !== '') {
        $itemCategoryId = importer_find_id_by_name('item_categories', 'name', $itemCategoryName);
        if (!$itemCategoryId) $errors[] = "Item Category not found: $itemCategoryName.";
    }

    $brandId = null;
    $brandName = trim((string)($row['Brand'] ?? ''));
    if ($brandName !== '') {
        $brandId = importer_find_id_by_name('brands', 'name', $brandName);
        if (!$brandId) $errors[] = "Brand not found: $brandName.";
    }

    $unit = trim((string)($row['Unit'] ?? '')) ?: 'pcs';
    if (!importer_find_id_by_name('uom', 'name', $unit)) {
        $errors[] = "Unit of Measure not found: $unit.";
    }

    $openingQty = (int)($row['Opening Stock'] ?? 0);
    $warehouseId = null;
    $warehouseName = trim((string)($row['Warehouse'] ?? ''));
    if ($warehouseName !== '') {
        $warehouseId = importer_find_id_by_name('warehouses', 'name', $warehouseName);
        if (!$warehouseId) $errors[] = "Warehouse not found: $warehouseName.";
    } elseif ($openingQty > 0) {
        $errors[] = 'Warehouse is required when Opening Stock is given.';
    }

    $status = in_array(strtolower(trim((string)($row['Status'] ?? ''))), ['inactive'], true) ? 'inactive' : 'active';

    if ($errors) {
        return ['ok' => false, 'errors' => $errors, 'data' => null];
    }

    return ['ok' => true, 'errors' => [], 'data' => [
        'sku' => $sku, 'name' => $name, 'category_id' => $categoryId, 'item_category_id' => $itemCategoryId,
        'brand_id' => $brandId, 'hsn_sac_code' => trim((string)($row['HSN/SAC Code'] ?? '')) ?: null,
        'unit' => $unit, 'cost_price' => (float)($row['Cost Price'] ?? 0), 'selling_price' => (float)($row['Selling Price'] ?? 0),
        'opening_qty' => $openingQty, 'warehouse_id' => $warehouseId, 'reorder_level' => (int)($row['Reorder Level'] ?? 0),
        'description' => trim((string)($row['Description'] ?? '')) ?: null, 'status' => $status,
    ]];
}

function importer_commit_products(array $data): array
{
    $stmt = db()->prepare('SELECT id FROM products WHERE sku = ? LIMIT 1');
    $stmt->execute([$data['sku']]);
    $existing = $stmt->fetch();

    if ($existing) {
        db()->prepare('UPDATE products SET name=?, category_id=?, item_category_id=?, brand_id=?, hsn_sac_code=?, unit=?, cost_price=?, selling_price=?, reorder_level=?, description=?, status=? WHERE id=?')
            ->execute([$data['name'], $data['category_id'], $data['item_category_id'], $data['brand_id'], $data['hsn_sac_code'], $data['unit'], $data['cost_price'], $data['selling_price'], $data['reorder_level'], $data['description'], $data['status'], $existing['id']]);
        return ['status' => 'success', 'message' => 'Updated existing item.', 'record_id' => (int)$existing['id']];
    }

    db()->prepare('INSERT INTO products (sku, name, category_id, item_category_id, brand_id, hsn_sac_code, unit, cost_price, selling_price, reorder_level, description, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$data['sku'], $data['name'], $data['category_id'], $data['item_category_id'], $data['brand_id'], $data['hsn_sac_code'], $data['unit'], $data['cost_price'], $data['selling_price'], $data['reorder_level'], $data['description'], $data['status']]);
    $newId = (int)db()->lastInsertId();

    $message = 'Created new item.';
    if ($data['opening_qty'] > 0 && $data['warehouse_id']) {
        stock_move($newId, $data['warehouse_id'], $data['opening_qty'], 'in', 'Initial stock', 'Opening balance via Excel import', current_user()['id']);
        $message = 'Created new item with opening stock.';
    }
    return ['status' => 'success', 'message' => $message, 'record_id' => $newId];
}

// --- Customers ----------------------------------------------------------

function importer_validate_customers(array $row): array
{
    $name = trim((string)($row['Name'] ?? ''));
    if ($name === '') {
        return ['ok' => false, 'errors' => ['Name is required.'], 'data' => null];
    }
    $type = strtolower(trim((string)($row['Customer Type'] ?? ''))) === 'individual' ? 'individual' : 'company';
    $status = trim((string)($row['Status'] ?? ''));
    $status = in_array(strtolower($status), ['inactive', 'blocked'], true) ? strtolower($status) : 'active';

    return ['ok' => true, 'errors' => [], 'data' => [
        'customer_code' => strtoupper(trim((string)($row['Customer Code'] ?? ''))) ?: null,
        'name' => $name, 'customer_type' => $type,
        'company' => trim((string)($row['Company'] ?? '')) ?: null,
        'email' => trim((string)($row['Email'] ?? '')) ?: null,
        'phone' => trim((string)($row['Phone'] ?? '')) ?: null,
        'mobile' => trim((string)($row['Mobile'] ?? '')) ?: null,
        'gstin' => strtoupper(trim((string)($row['GSTIN'] ?? ''))) ?: null,
        'address' => trim((string)($row['Address'] ?? '')) ?: null,
        'status' => $status,
    ]];
}

function importer_commit_customers(array $data): array
{
    $pdo = db();
    $existing = null;
    if ($data['customer_code']) {
        $stmt = $pdo->prepare('SELECT id FROM customers WHERE customer_code = ? LIMIT 1');
        $stmt->execute([$data['customer_code']]);
        $existing = $stmt->fetch();
    } else {
        $stmt = $pdo->prepare('SELECT id FROM customers WHERE LOWER(name) = LOWER(?) LIMIT 1');
        $stmt->execute([$data['name']]);
        $existing = $stmt->fetch();
    }

    $cols = ['name', 'customer_type', 'company', 'email', 'phone', 'mobile', 'gstin', 'address', 'status'];
    $vals = array_map(fn($c) => $data[$c], $cols);

    if ($existing) {
        $pdo->prepare('UPDATE customers SET ' . implode(', ', array_map(fn($c) => "$c=?", $cols)) . ' WHERE id=?')
            ->execute([...$vals, $existing['id']]);
        return ['status' => 'success', 'message' => 'Updated existing customer.', 'record_id' => (int)$existing['id']];
    }

    $pdo->prepare('INSERT INTO customers (customer_code, ' . implode(', ', $cols) . ') VALUES (?,' . implode(',', array_fill(0, count($cols), '?')) . ')')
        ->execute([$data['customer_code'], ...$vals]);
    $newId = (int)$pdo->lastInsertId();
    if (!$data['customer_code']) {
        $pdo->prepare("UPDATE customers SET customer_code = CONCAT('CUST-', LPAD(id, 5, '0')) WHERE id = ?")->execute([$newId]);
    }
    return ['status' => 'success', 'message' => 'Created new customer.', 'record_id' => $newId];
}

// --- Vendors --------------------------------------------------------------

function importer_validate_vendors(array $row): array
{
    $name = trim((string)($row['Name'] ?? ''));
    if ($name === '') {
        return ['ok' => false, 'errors' => ['Name is required.'], 'data' => null];
    }
    return ['ok' => true, 'errors' => [], 'data' => [
        'name' => $name,
        'company' => trim((string)($row['Company'] ?? '')) ?: null,
        'email' => trim((string)($row['Email'] ?? '')) ?: null,
        'phone' => trim((string)($row['Phone'] ?? '')) ?: null,
        'address' => trim((string)($row['Address'] ?? '')) ?: null,
    ]];
}

function importer_commit_vendors(array $data): array
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id FROM vendors WHERE LOWER(name) = LOWER(?) LIMIT 1');
    $stmt->execute([$data['name']]);
    $existing = $stmt->fetch();

    if ($existing) {
        $pdo->prepare('UPDATE vendors SET company=?, email=?, phone=?, address=? WHERE id=?')
            ->execute([$data['company'], $data['email'], $data['phone'], $data['address'], $existing['id']]);
        return ['status' => 'success', 'message' => 'Updated existing vendor.', 'record_id' => (int)$existing['id']];
    }

    $pdo->prepare('INSERT INTO vendors (name, company, email, phone, address) VALUES (?,?,?,?,?)')
        ->execute([$data['name'], $data['company'], $data['email'], $data['phone'], $data['address']]);
    return ['status' => 'success', 'message' => 'Created new vendor.', 'record_id' => (int)$pdo->lastInsertId()];
}

// --- Chart of Accounts ------------------------------------------------

const IMPORTER_ACCOUNT_TYPES = ['tax', 'income', 'expense', 'other', 'bank', 'cash', 'receivable', 'payable', 'current_asset', 'fixed_asset', 'stock', 'current_liability', 'loan', 'equity', 'cost_of_goods_sold'];
const IMPORTER_ROOT_TYPES = ['asset', 'liability', 'equity', 'income', 'expense'];

function importer_validate_chart_of_accounts(array $row): array
{
    $errors = [];
    $name = trim((string)($row['Account Name'] ?? ''));
    if ($name === '') $errors[] = 'Account Name is required.';

    $parentId = null;
    $parentName = trim((string)($row['Parent Account'] ?? ''));
    if ($parentName !== '') {
        $parentId = importer_find_id_by_name('ledger_accounts', 'name', $parentName);
        if (!$parentId) $errors[] = "Parent Account not found: $parentName.";
    }

    $rootType = strtolower(trim((string)($row['Root Type'] ?? '')));
    if ($rootType !== '' && !in_array($rootType, IMPORTER_ROOT_TYPES, true)) {
        $errors[] = 'Root Type must be one of: ' . implode(', ', IMPORTER_ROOT_TYPES) . '.';
        $rootType = '';
    }

    $accountType = strtolower(trim((string)($row['Account Type'] ?? '')));
    if ($accountType === '') {
        $accountType = 'other';
    } elseif (!in_array($accountType, IMPORTER_ACCOUNT_TYPES, true)) {
        $errors[] = 'Account Type must be one of: ' . implode(', ', IMPORTER_ACCOUNT_TYPES) . '.';
    }

    if ($errors) {
        return ['ok' => false, 'errors' => $errors, 'data' => null];
    }

    return ['ok' => true, 'errors' => [], 'data' => [
        'name' => $name, 'account_code' => trim((string)($row['Account Code'] ?? '')) ?: null,
        'parent_id' => $parentId, 'root_type' => $rootType ?: null, 'account_type' => $accountType,
        'is_group' => importer_parse_bool($row['Is Group'] ?? '') ? 1 : 0,
        'opening_balance' => (float)($row['Opening Balance'] ?? 0),
        'status' => importer_parse_status($row['Status'] ?? ''),
    ]];
}

function importer_commit_chart_of_accounts(array $data): array
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id FROM ledger_accounts WHERE LOWER(name) = LOWER(?) LIMIT 1');
    $stmt->execute([$data['name']]);
    $existing = $stmt->fetch();

    $cols = ['account_code', 'parent_id', 'root_type', 'account_type', 'is_group', 'opening_balance', 'status'];
    $vals = array_map(fn($c) => $data[$c], $cols);

    if ($existing) {
        $pdo->prepare('UPDATE ledger_accounts SET ' . implode(', ', array_map(fn($c) => "$c=?", $cols)) . ' WHERE id=?')
            ->execute([...$vals, $existing['id']]);
        return ['status' => 'success', 'message' => 'Updated existing account.', 'record_id' => (int)$existing['id']];
    }

    $pdo->prepare('INSERT INTO ledger_accounts (name, ' . implode(', ', $cols) . ') VALUES (?,' . implode(',', array_fill(0, count($cols), '?')) . ')')
        ->execute([$data['name'], ...$vals]);
    return ['status' => 'success', 'message' => 'Created new account.', 'record_id' => (int)$pdo->lastInsertId()];
}

// --- Tax Templates ------------------------------------------------------

function importer_validate_tax_templates(array $row): array
{
    $errors = [];
    $name = trim((string)($row['Template Name'] ?? ''));
    if ($name === '') $errors[] = 'Template Name is required.';

    $rateRaw = trim((string)($row['Tax Rate %'] ?? ''));
    if ($rateRaw === '') $errors[] = 'Tax Rate % is required.';
    $rate = (float)$rateRaw;

    $accountId = null;
    $accountName = trim((string)($row['Account Name'] ?? ''));
    if ($accountName !== '') {
        $accountId = importer_find_id_by_name('ledger_accounts', 'name', $accountName);
        if (!$accountId) $errors[] = "Account not found: $accountName.";
    }

    $basedOn = strtolower(trim((string)($row['Based On'] ?? ''))) === 'actual_amount' ? 'actual_amount' : 'net_amount';

    if ($errors) {
        return ['ok' => false, 'errors' => $errors, 'data' => null];
    }

    return ['ok' => true, 'errors' => [], 'data' => [
        'name' => $name, 'rate' => $rate, 'account_id' => $accountId, 'based_on' => $basedOn,
        'status' => importer_parse_status($row['Status'] ?? ''),
    ]];
}

function importer_commit_tax_templates(array $data): array
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id FROM tax_templates WHERE LOWER(name) = LOWER(?) LIMIT 1');
    $stmt->execute([$data['name']]);
    $existing = $stmt->fetch();

    if ($existing) {
        $templateId = (int)$existing['id'];
        $pdo->prepare('UPDATE tax_templates SET status = ? WHERE id = ?')->execute([$data['status'], $templateId]);
        $pdo->prepare('DELETE FROM tax_template_items WHERE tax_template_id = ?')->execute([$templateId]);
        $message = 'Updated existing tax template.';
    } else {
        $pdo->prepare('INSERT INTO tax_templates (name, status) VALUES (?, ?)')->execute([$data['name'], $data['status']]);
        $templateId = (int)$pdo->lastInsertId();
        $message = 'Created new tax template.';
    }

    $pdo->prepare('INSERT INTO tax_template_items (tax_template_id, type, account_head_id, description, based_on, rate_or_amount, sort_order) VALUES (?,?,?,?,?,?,0)')
        ->execute([$templateId, 'on_item', $data['account_id'], $data['name'], $data['based_on'], $data['rate']]);

    return ['status' => 'success', 'message' => $message, 'record_id' => $templateId];
}

// --- Price Lists -------------------------------------------------------

function importer_validate_price_lists(array $row): array
{
    $name = trim((string)($row['List Name'] ?? ''));
    if ($name === '') {
        return ['ok' => false, 'errors' => ['List Name is required.'], 'data' => null];
    }
    return ['ok' => true, 'errors' => [], 'data' => [
        'name' => $name,
        'currency' => strtoupper(trim((string)($row['Currency'] ?? ''))) ?: 'INR',
        'is_default' => importer_parse_bool($row['Is Default'] ?? '') ? 1 : 0,
        'status' => importer_parse_status($row['Status'] ?? ''),
    ]];
}

function importer_commit_price_lists(array $data): array
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id FROM price_lists WHERE LOWER(name) = LOWER(?) LIMIT 1');
    $stmt->execute([$data['name']]);
    $existing = $stmt->fetch();

    if ($data['is_default']) {
        $pdo->exec('UPDATE price_lists SET is_default = 0 WHERE is_default = 1');
    }

    if ($existing) {
        $pdo->prepare('UPDATE price_lists SET currency=?, is_default=?, status=? WHERE id=?')
            ->execute([$data['currency'], $data['is_default'], $data['status'], $existing['id']]);
        return ['status' => 'success', 'message' => 'Updated existing price list.', 'record_id' => (int)$existing['id']];
    }

    $pdo->prepare('INSERT INTO price_lists (name, currency, is_default, status) VALUES (?,?,?,?)')
        ->execute([$data['name'], $data['currency'], $data['is_default'], $data['status']]);
    return ['status' => 'success', 'message' => 'Created new price list.', 'record_id' => (int)$pdo->lastInsertId()];
}

// --- Purchase Orders (grouped: one order per distinct Order Ref) ----------

function importer_validate_purchase_orders(array $rows): array
{
    $first = $rows[0]['raw'];
    $errors = [];

    $vendorName = trim((string)($first['Vendor'] ?? ''));
    $vendorId = null;
    if ($vendorName === '') {
        $errors[] = 'Vendor is required.';
    } else {
        $vendorId = importer_find_id_by_name('vendors', 'name', $vendorName);
        if (!$vendorId) $errors[] = "Vendor not found: $vendorName.";
    }

    $warehouseId = null;
    $warehouseName = trim((string)($first['Warehouse'] ?? ''));
    if ($warehouseName !== '') {
        $warehouseId = importer_find_id_by_name('warehouses', 'name', $warehouseName);
        if (!$warehouseId) $errors[] = "Warehouse not found: $warehouseName.";
    } else {
        $warehouseId = default_warehouse_id();
    }

    $items = [];
    foreach ($rows as $r) {
        $raw = $r['raw'];
        $productKey = trim((string)($raw['Product'] ?? ''));
        if ($productKey === '') {
            $errors[] = "Row {$r['row_num']}: Product is required.";
            continue;
        }
        $product = importer_find_product($productKey);
        if (!$product) {
            $errors[] = "Row {$r['row_num']}: Product not found: $productKey.";
            continue;
        }
        $qty = (int)($raw['Quantity'] ?? 0);
        if ($qty <= 0) {
            $errors[] = "Row {$r['row_num']}: Quantity must be greater than 0.";
            continue;
        }
        $rate = (float)($raw['Rate'] ?? 0);
        if ($rate < 0) {
            $errors[] = "Row {$r['row_num']}: Rate cannot be negative.";
            continue;
        }
        $items[] = ['product_id' => (int)$product['id'], 'unit' => $product['unit'], 'quantity' => $qty, 'rate' => $rate];
    }

    if (!$errors && !$items) {
        $errors[] = 'No valid line items found for this order.';
    }

    if ($errors) {
        return ['ok' => false, 'errors' => $errors];
    }

    return ['ok' => true, 'errors' => [], 'summary' => "Vendor: $vendorName, " . count($items) . ' line item(s)', 'data' => [
        'vendor_id' => $vendorId,
        'order_date' => importer_parse_date($first['Order Date'] ?? '') ?: today(),
        'required_by' => importer_parse_date($first['Required By'] ?? ''),
        'warehouse_id' => $warehouseId,
        'notes' => trim((string)($first['Notes'] ?? '')) ?: null,
        'items' => $items,
    ]];
}

function importer_commit_purchase_orders(array $rows, array $data): array
{
    $pdo = db();
    $netAmount = 0;
    foreach ($data['items'] as $it) {
        $netAmount += $it['quantity'] * $it['rate'];
    }
    $netAmount = round($netAmount, 2);

    $poNo = next_code('PO', 'purchase_orders', 'po_no');
    $pdo->prepare('INSERT INTO purchase_orders (po_no, vendor_id, order_date, required_by, notes, ship_to_warehouse_id, total_amount, net_amount, status, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$poNo, $data['vendor_id'], $data['order_date'], $data['required_by'], $data['notes'], $data['warehouse_id'], $netAmount, $netAmount, 'pending', current_user()['id']]);
    $poId = (int)$pdo->lastInsertId();

    $itemStmt = $pdo->prepare('INSERT INTO purchase_order_items (po_id, product_id, warehouse_id, quantity, uom, uom_conversion_factor, rate, discount_percent, unit_cost, subtotal) VALUES (?,?,?,?,?,1.000,?,0,?,?)');
    foreach ($data['items'] as $it) {
        $subtotal = round($it['quantity'] * $it['rate'], 2);
        $itemStmt->execute([$poId, $it['product_id'], $data['warehouse_id'], $it['quantity'], $it['unit'], $it['rate'], $it['rate'], $subtotal]);
    }

    return ['status' => 'success', 'message' => "Created $poNo (" . count($data['items']) . ' line item(s)).', 'record_id' => $poId];
}

// --- Sales Orders (grouped: one order per distinct Order Ref) -------------

function importer_validate_sales_orders(array $rows): array
{
    $first = $rows[0]['raw'];
    $errors = [];

    $customerName = trim((string)($first['Customer'] ?? ''));
    $customerId = null;
    if ($customerName === '') {
        $errors[] = 'Customer is required.';
    } else {
        $customerId = importer_find_id_by_name('customers', 'name', $customerName);
        if (!$customerId) $errors[] = "Customer not found: $customerName.";
    }

    $warehouseId = null;
    $warehouseName = trim((string)($first['Warehouse'] ?? ''));
    if ($warehouseName !== '') {
        $warehouseId = importer_find_id_by_name('warehouses', 'name', $warehouseName);
        if (!$warehouseId) $errors[] = "Warehouse not found: $warehouseName.";
    } else {
        $warehouseId = default_warehouse_id();
    }

    $items = [];
    foreach ($rows as $r) {
        $raw = $r['raw'];
        $productKey = trim((string)($raw['Product'] ?? ''));
        if ($productKey === '') {
            $errors[] = "Row {$r['row_num']}: Product is required.";
            continue;
        }
        $product = importer_find_product($productKey);
        if (!$product) {
            $errors[] = "Row {$r['row_num']}: Product not found: $productKey.";
            continue;
        }
        $qty = (int)($raw['Quantity'] ?? 0);
        if ($qty <= 0) {
            $errors[] = "Row {$r['row_num']}: Quantity must be greater than 0.";
            continue;
        }
        $rate = (float)($raw['Rate'] ?? 0);
        if ($rate < 0) {
            $errors[] = "Row {$r['row_num']}: Rate cannot be negative.";
            continue;
        }
        $items[] = ['product_id' => (int)$product['id'], 'unit' => $product['unit'], 'quantity' => $qty, 'rate' => $rate];
    }

    if (!$errors && !$items) {
        $errors[] = 'No valid line items found for this order.';
    }

    if ($errors) {
        return ['ok' => false, 'errors' => $errors];
    }

    return ['ok' => true, 'errors' => [], 'summary' => "Customer: $customerName, " . count($items) . ' line item(s)', 'data' => [
        'customer_id' => $customerId,
        'order_date' => importer_parse_date($first['Order Date'] ?? '') ?: today(),
        'required_delivery_date' => importer_parse_date($first['Required Delivery Date'] ?? ''),
        'warehouse_id' => $warehouseId,
        'notes' => trim((string)($first['Notes'] ?? '')) ?: null,
        'items' => $items,
    ]];
}

function importer_commit_sales_orders(array $rows, array $data): array
{
    $pdo = db();
    $netAmount = 0;
    foreach ($data['items'] as $it) {
        $netAmount += $it['quantity'] * $it['rate'];
    }
    $netAmount = round($netAmount, 2);

    $orderNo = next_code('SO', 'sales_orders', 'order_no');
    $pdo->prepare('INSERT INTO sales_orders (order_no, customer_id, warehouse_id, order_date, required_delivery_date, notes, total_amount, net_amount, status, channel, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$orderNo, $data['customer_id'], $data['warehouse_id'], $data['order_date'], $data['required_delivery_date'], $data['notes'], $netAmount, $netAmount, 'pending', 'online', current_user()['id']]);
    $orderId = (int)$pdo->lastInsertId();

    $itemStmt = $pdo->prepare('INSERT INTO sales_order_items (order_id, product_id, warehouse_id, quantity, uom, uom_conversion_factor, unit_price, discount_percent, subtotal) VALUES (?,?,?,?,?,1.000,?,0,?)');
    foreach ($data['items'] as $it) {
        $subtotal = round($it['quantity'] * $it['rate'], 2);
        $itemStmt->execute([$orderId, $it['product_id'], $data['warehouse_id'], $it['quantity'], $it['unit'], $it['rate'], $subtotal]);
    }

    return ['status' => 'success', 'message' => "Created $orderNo (" . count($data['items']) . ' line item(s)).', 'record_id' => $orderId];
}

// --- Sample-data exporters (template download's +5/+50/All records) -------

/** A trusted-int LIMIT clause, or '' for no limit (every row). */
function importer_limit_clause(?int $limit): string
{
    return $limit === null ? '' : ' LIMIT ' . max(0, $limit);
}

function importer_sample_categories(?int $limit): array
{
    $rows = db()->query('SELECT name, description FROM categories ORDER BY id DESC' . importer_limit_clause($limit))->fetchAll();
    return array_map(fn($r) => ['Name' => $r['name'], 'Description' => $r['description']], $rows);
}

function importer_sample_brands(?int $limit): array
{
    $rows = db()->query('SELECT name, status FROM brands ORDER BY id DESC' . importer_limit_clause($limit))->fetchAll();
    return array_map(fn($r) => ['Name' => $r['name'], 'Status' => $r['status']], $rows);
}

function importer_sample_uom(?int $limit): array
{
    $rows = db()->query('SELECT name, status FROM uom ORDER BY id DESC' . importer_limit_clause($limit))->fetchAll();
    return array_map(fn($r) => ['Name' => $r['name'], 'Status' => $r['status']], $rows);
}

function importer_sample_products(?int $limit): array
{
    $sql = 'SELECT p.sku, p.name, c.name AS category_name, ic.name AS item_category_name, b.name AS brand_name,
                   p.hsn_sac_code, p.unit, p.cost_price, p.selling_price, p.reorder_level, p.description, p.status
            FROM products p
            LEFT JOIN categories c ON c.id = p.category_id
            LEFT JOIN item_categories ic ON ic.id = p.item_category_id
            LEFT JOIN brands b ON b.id = p.brand_id
            ORDER BY p.id DESC' . importer_limit_clause($limit);
    $rows = db()->query($sql)->fetchAll();
    return array_map(fn($r) => [
        'SKU' => $r['sku'], 'Name' => $r['name'], 'Category' => $r['category_name'], 'Item Category' => $r['item_category_name'],
        'Brand' => $r['brand_name'], 'HSN/SAC Code' => $r['hsn_sac_code'], 'Unit' => $r['unit'],
        'Cost Price' => $r['cost_price'], 'Selling Price' => $r['selling_price'],
        // Opening Stock/Warehouse are a one-time, create-only action (see importer_commit_products)
        // — left blank here so re-importing an exported row never looks like it will add stock again.
        'Opening Stock' => '', 'Warehouse' => '',
        'Reorder Level' => $r['reorder_level'], 'Description' => $r['description'], 'Status' => $r['status'],
    ], $rows);
}

function importer_sample_customers(?int $limit): array
{
    $rows = db()->query('SELECT * FROM customers ORDER BY id DESC' . importer_limit_clause($limit))->fetchAll();
    return array_map(fn($r) => [
        'Customer Code' => $r['customer_code'], 'Name' => $r['name'], 'Customer Type' => $r['customer_type'],
        'Company' => $r['company'], 'Email' => $r['email'], 'Phone' => $r['phone'], 'Mobile' => $r['mobile'],
        'GSTIN' => $r['gstin'], 'Address' => $r['address'], 'Status' => $r['status'],
    ], $rows);
}

function importer_sample_vendors(?int $limit): array
{
    $rows = db()->query('SELECT * FROM vendors ORDER BY id DESC' . importer_limit_clause($limit))->fetchAll();
    return array_map(fn($r) => [
        'Name' => $r['name'], 'Company' => $r['company'], 'Email' => $r['email'], 'Phone' => $r['phone'], 'Address' => $r['address'],
    ], $rows);
}

function importer_sample_chart_of_accounts(?int $limit): array
{
    $sql = 'SELECT a.*, p.name AS parent_name FROM ledger_accounts a
            LEFT JOIN ledger_accounts p ON p.id = a.parent_id
            ORDER BY a.id DESC' . importer_limit_clause($limit);
    $rows = db()->query($sql)->fetchAll();
    return array_map(fn($r) => [
        'Account Name' => $r['name'], 'Account Code' => $r['account_code'], 'Parent Account' => $r['parent_name'],
        'Root Type' => $r['root_type'], 'Account Type' => $r['account_type'], 'Is Group' => $r['is_group'] ? 'yes' : 'no',
        'Opening Balance' => $r['opening_balance'], 'Status' => $r['status'],
    ], $rows);
}

function importer_sample_tax_templates(?int $limit): array
{
    $sql = "SELECT t.name, t.status, ti.rate_or_amount, ti.based_on, la.name AS account_name
            FROM tax_templates t
            LEFT JOIN tax_template_items ti ON ti.tax_template_id = t.id AND ti.sort_order = 0
            LEFT JOIN ledger_accounts la ON la.id = ti.account_head_id
            ORDER BY t.id DESC" . importer_limit_clause($limit);
    $rows = db()->query($sql)->fetchAll();
    return array_map(fn($r) => [
        'Template Name' => $r['name'], 'Tax Rate %' => $r['rate_or_amount'], 'Account Name' => $r['account_name'],
        'Based On' => $r['based_on'], 'Status' => $r['status'],
    ], $rows);
}

function importer_sample_price_lists(?int $limit): array
{
    $rows = db()->query('SELECT * FROM price_lists ORDER BY id DESC' . importer_limit_clause($limit))->fetchAll();
    return array_map(fn($r) => [
        'List Name' => $r['name'], 'Currency' => $r['currency'], 'Is Default' => $r['is_default'] ? 'yes' : 'no', 'Status' => $r['status'],
    ], $rows);
}

function importer_sample_purchase_orders(?int $limit): array
{
    $sql = 'SELECT po.po_no, v.name AS vendor_name, po.order_date, po.required_by, w.name AS warehouse_name, po.notes,
                   p.sku, poi.quantity, poi.rate
            FROM purchase_order_items poi
            JOIN purchase_orders po ON po.id = poi.po_id
            JOIN vendors v ON v.id = po.vendor_id
            LEFT JOIN warehouses w ON w.id = po.ship_to_warehouse_id
            JOIN products p ON p.id = poi.product_id
            ORDER BY po.id DESC, poi.id ASC' . importer_limit_clause($limit);
    $rows = db()->query($sql)->fetchAll();
    return array_map(fn($r) => [
        'Order Ref' => $r['po_no'], 'Vendor' => $r['vendor_name'], 'Order Date' => $r['order_date'],
        'Required By' => $r['required_by'], 'Warehouse' => $r['warehouse_name'], 'Notes' => $r['notes'],
        'Product' => $r['sku'], 'Quantity' => $r['quantity'], 'Rate' => $r['rate'],
    ], $rows);
}

function importer_sample_sales_orders(?int $limit): array
{
    $sql = 'SELECT so.order_no, c.name AS customer_name, so.order_date, so.required_delivery_date, w.name AS warehouse_name, so.notes,
                   p.sku, soi.quantity, soi.unit_price
            FROM sales_order_items soi
            JOIN sales_orders so ON so.id = soi.order_id
            JOIN customers c ON c.id = so.customer_id
            LEFT JOIN warehouses w ON w.id = so.warehouse_id
            JOIN products p ON p.id = soi.product_id
            ORDER BY so.id DESC, soi.id ASC' . importer_limit_clause($limit);
    $rows = db()->query($sql)->fetchAll();
    return array_map(fn($r) => [
        'Order Ref' => $r['order_no'], 'Customer' => $r['customer_name'], 'Order Date' => $r['order_date'],
        'Required Delivery Date' => $r['required_delivery_date'], 'Warehouse' => $r['warehouse_name'], 'Notes' => $r['notes'],
        'Product' => $r['sku'], 'Quantity' => $r['quantity'], 'Rate' => $r['unit_price'],
    ], $rows);
}
