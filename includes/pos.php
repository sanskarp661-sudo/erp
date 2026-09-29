<?php
/**
 * POS module: settings, terminal/store/shift context, cart pricing, and
 * the sale / return / cancel postings shared by every POS screen and by
 * the Cashfree webhook.
 *
 * A POS sale still creates a normal Sales Order (channel='pos') + Invoice
 * + Payment rows, and moves stock through stock_move(), so it shows up in
 * Sales, Finance and stock reports exactly like any other sale. The
 * pos_* tables (migration 031) only add terminals, shifts and held carts.
 *
 * Pricing is always recomputed here on the server from the product master;
 * the browser's numbers are only a preview (assets/js/pos.js mirrors the
 * same maths so both agree to the paisa).
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/stock.php';

/** Every POS setting with its default. Stored in `settings` under the same key. */
function pos_settings_defaults(): array
{
    return [
        'pos_default_warehouse_id'    => '',
        'pos_default_profile_id'      => '',
        'pos_default_price_list_id'   => '',
        'pos_default_customer_id'     => '',
        'pos_allow_discount'          => '1',
        'pos_allow_price_override'    => '1',
        'pos_print_receipt_auto'      => '1',
        'pos_show_product_images'     => '1',
        'pos_enable_barcode_scanner'  => '1',
        'pos_require_shift'           => '1',
        'pos_allow_credit_sale'       => '1',
        'pos_max_discount_percent'    => '0',
        'pos_tax_label'               => 'GST',
        'pos_default_tax_rate'        => '18',
        'pos_tax_slabs'               => '0,5,12,18,28',
        'pos_round_off'               => '0',
        'pos_pay_cash'                => '1',
        'pos_pay_card'                => '1',
        'pos_pay_upi'                 => '1',
        'pos_pay_wallet'              => '1',
        'pos_pay_split'               => '1',
        'pos_quick_cash'              => '100,500,1000,2000',
        'pos_receipt_width'           => '80',
        'pos_receipt_header'          => '',
        'pos_receipt_address'         => '',
        'pos_receipt_phone'           => '',
        'pos_receipt_gstin'           => '',
        'pos_receipt_footer'          => 'Thank you for shopping with us!',
        'pos_receipt_show_customer'   => '1',
        'pos_receipt_show_tax_breakup'=> '1',
        'pos_barcode_match_sku'       => '1',
        'pos_barcode_auto_add'        => '1',
        'pos_barcode_camera'          => '1',
        'pos_perm_discount'           => 'edit',
        'pos_perm_price_override'     => 'edit',
        'pos_perm_returns'            => 'edit',
        'pos_perm_cancel'             => 'manage',
        'pos_perm_close_shift'        => 'edit',
        'pos_perm_delete_held'        => 'edit',
    ];
}

function pos_setting(string $key): string
{
    return (string)setting($key, pos_settings_defaults()[$key] ?? '');
}

function pos_flag(string $key): bool
{
    return pos_setting($key) === '1';
}

function pos_save_settings(array $values): void
{
    $stmt = db()->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    foreach ($values as $k => $v) {
        if (array_key_exists($k, pos_settings_defaults())) {
            $stmt->execute([$k, (string)$v]);
        }
    }
}

/**
 * Whether the signed-in user may do a POS action. Each action is set on
 * POS Settings > Permissions to either 'edit' (anyone who can sell) or
 * 'manage' (POS managers / admins only).
 */
function pos_can(string $action): bool
{
    return pos_setting('pos_perm_' . $action) === 'manage' ? can_manage_module('pos') : can_edit_module('pos');
}

/** True once migration 031 has been run on this database. */
function pos_schema_ready(): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            db()->query('SELECT 1 FROM pos_profiles LIMIT 1');
            $ready = (bool)db()->query("SHOW COLUMNS FROM sales_orders LIKE 'pos_no'")->fetch();
        } catch (PDOException $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** Stops the page with a friendly card when migration 031 hasn't been run yet. */
function pos_require_schema(): void
{
    if (pos_schema_ready()) {
        return;
    }
    $page_title = 'POS';
    require __DIR__ . '/header.php';
    echo '<div class="dash-card" style="max-width:640px"><h2 class="h5">One more step: update the database</h2>'
        . '<p class="text-muted mb-2">The new POS screens need <code>database/migrations/031_pos_module.sql</code>. '
        . 'Open phpMyAdmin, select this ERP database, go to the Import tab and run that file, then reload this page.</p></div>';
    require __DIR__ . '/footer.php';
    exit;
}

/* ------------------------------------------------------------------ */
/* Formatting                                                          */
/* ------------------------------------------------------------------ */

/** Number with Indian digit grouping (1,06,552.82) when the currency is INR. */
function pos_number($amount, int $decimals = 2): string
{
    $amount = round((float)$amount, $decimals);
    $neg = $amount < 0;
    $parts = explode('.', number_format(abs($amount), $decimals, '.', ''));
    $int = $parts[0];
    if (setting('currency_code', 'INR') === 'INR') {
        if (strlen($int) > 3) {
            $int = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', substr($int, 0, -3)) . ',' . substr($int, -3);
        }
    } else {
        $int = number_format((float)$int, 0, '', ',');
    }
    return ($neg ? '-' : '') . $int . ($decimals > 0 ? '.' . $parts[1] : '');
}

function pos_money($amount, int $decimals = 2): string
{
    $amount = (float)$amount;
    return ($amount < 0 ? '-' : '') . setting('currency_symbol', '$') . pos_number(abs($amount), $decimals);
}

function pos_datetime(?string $dt): string
{
    return $dt ? date('d M Y, h:i A', strtotime($dt)) : '';
}

function pos_date(?string $d): string
{
    return $d ? date('d M Y', strtotime($d)) : '';
}

/** Labels for payment methods as POS shows them. */
function pos_method_label(string $method): string
{
    return [
        'cash' => 'Cash', 'card' => 'Card', 'upi' => 'UPI', 'wallet' => 'Wallet', 'store_credit' => 'Exchange Credit',
        'credit_note' => 'Credit Note', 'bank_transfer' => 'Bank Transfer', 'cheque' => 'Cheque', 'other' => 'Other',
    ][$method] ?? ucfirst(str_replace('_', ' ', $method));
}

/** Tax slabs offered in the Edit Item modal. */
function pos_tax_slabs(): array
{
    $slabs = [];
    foreach (explode(',', pos_setting('pos_tax_slabs')) as $s) {
        if (is_numeric(trim($s))) {
            $slabs[] = (float)trim($s);
        }
    }
    $slabs[] = (float)pos_setting('pos_default_tax_rate');
    $slabs = array_values(array_unique($slabs, SORT_REGULAR));
    sort($slabs);
    return $slabs;
}

/** Payment methods switched on in POS Settings > Payment Modes. */
function pos_payment_modes(): array
{
    $modes = [];
    foreach (['cash', 'card', 'upi', 'wallet'] as $m) {
        if (pos_flag('pos_pay_' . $m)) {
            $modes[] = $m;
        }
    }
    return $modes ?: ['cash'];
}

/**
 * Next number in a prefixed series, e.g. POS-2026-000124. Looks only at
 * codes that share the prefix, so it never collides with other series in
 * the same column.
 */
function pos_next_code(string $prefix, string $table, string $column, int $pad): string
{
    $stmt = db()->prepare("SELECT $column FROM $table WHERE $column LIKE ? ORDER BY LENGTH($column) DESC, $column DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();
    $num = 1;
    if ($last && preg_match('/(\d+)$/', $last, $m)) {
        $num = (int)$m[1] + 1;
    }
    return $prefix . str_pad((string)$num, $pad, '0', STR_PAD_LEFT);
}

/* ------------------------------------------------------------------ */
/* Store / terminal / shift context                                    */
/* ------------------------------------------------------------------ */

function pos_profiles(bool $activeOnly = true): array
{
    return db()->query('SELECT pp.*, w.name warehouse_name, pl.name price_list_name FROM pos_profiles pp
        LEFT JOIN warehouses w ON w.id = pp.warehouse_id LEFT JOIN price_lists pl ON pl.id = pp.price_list_id'
        . ($activeOnly ? " WHERE pp.status = 'active'" : '') . ' ORDER BY pp.name')->fetchAll();
}

/**
 * The store (warehouse), terminal (POS profile), price list and open shift
 * this browser session is selling from. ?pos_store= / ?pos_terminal= on
 * any POS page switch them (the topbar dropdowns link there).
 */
function pos_context(): array
{
    static $ctx = null;
    if ($ctx !== null) {
        return $ctx;
    }
    $profiles = pos_profiles();
    $profilesById = array_column($profiles, null, 'id');
    $stores = leaf_warehouses();
    $storesById = array_column($stores, null, 'id');

    $switched = false;
    if (isset($_GET['pos_terminal']) && isset($profilesById[(int)$_GET['pos_terminal']])) {
        $p = $profilesById[(int)$_GET['pos_terminal']];
        $_SESSION['pos_profile_id'] = (int)$p['id'];
        if ($p['warehouse_id'] && isset($storesById[(int)$p['warehouse_id']])) {
            $_SESSION['pos_store_id'] = (int)$p['warehouse_id'];
        }
        $switched = true;
    }
    if (isset($_GET['pos_store']) && isset($storesById[(int)$_GET['pos_store']])) {
        $storeId = (int)$_GET['pos_store'];
        $_SESSION['pos_store_id'] = $storeId;
        $current = $profilesById[(int)($_SESSION['pos_profile_id'] ?? 0)] ?? null;
        if (!$current || (int)$current['warehouse_id'] !== $storeId) {
            foreach ($profiles as $p) {
                if ((int)$p['warehouse_id'] === $storeId) {
                    $_SESSION['pos_profile_id'] = (int)$p['id'];
                    break;
                }
            }
        }
        $switched = true;
    }
    if ($switched) {
        $uri = $_SERVER['REQUEST_URI'] ?? '/pos/index.php';
        $path = parse_url($uri, PHP_URL_PATH);
        parse_str((string)parse_url($uri, PHP_URL_QUERY), $q);
        unset($q['pos_store'], $q['pos_terminal']);
        redirect($path . ($q ? '?' . http_build_query($q) : ''));
    }

    $profile = $profilesById[(int)($_SESSION['pos_profile_id'] ?? 0)]
        ?? $profilesById[(int)pos_setting('pos_default_profile_id')]
        ?? ($profiles[0] ?? null);

    $storeId = (int)($_SESSION['pos_store_id'] ?? 0);
    if (!isset($storesById[$storeId])) {
        $storeId = (int)($profile['warehouse_id'] ?? 0);
    }
    if (!isset($storesById[$storeId])) {
        $storeId = (int)pos_setting('pos_default_warehouse_id');
    }
    if (!isset($storesById[$storeId])) {
        $storeId = (int)default_warehouse_id();
    }

    $priceListId = (int)($profile['price_list_id'] ?? 0) ?: (int)pos_setting('pos_default_price_list_id');
    if (!$priceListId) {
        $priceListId = (int)db()->query('SELECT id FROM price_lists WHERE is_default = 1 ORDER BY id LIMIT 1')->fetchColumn();
    }

    $ctx = [
        'profiles'      => $profiles,
        'stores'        => $stores,
        'profile'       => $profile,
        'profile_id'    => $profile ? (int)$profile['id'] : null,
        'warehouse_id'  => $storeId ?: null,
        'store_name'    => $storesById[$storeId]['name'] ?? 'No store',
        'price_list_id' => $priceListId ?: null,
        'shift'         => $profile ? pos_open_shift((int)$profile['id']) : null,
    ];
    $ctx['shift_id'] = $ctx['shift'] ? (int)$ctx['shift']['id'] : null;
    return $ctx;
}

/** Sets up a POS page: login, schema check, context, and the POS topbar. */
function pos_page(bool $needsEdit = false): array
{
    if ($needsEdit) {
        require_module_edit('pos');
    } else {
        require_login();
    }
    pos_require_schema();
    $ctx = pos_context();
    $GLOBALS['pos_topbar'] = $ctx;
    return $ctx;
}

function pos_open_shift(int $profileId): ?array
{
    $stmt = db()->prepare("SELECT s.*, u.name opened_by_name FROM pos_shifts s LEFT JOIN users u ON u.id = s.opened_by WHERE s.status = 'open' AND s.pos_profile_id = ? ORDER BY s.id DESC LIMIT 1");
    $stmt->execute([$profileId]);
    return $stmt->fetch() ?: null;
}

/** Live figures for one shift: sales, returns, per-method money, expected cash. */
function pos_shift_totals(array $shift): array
{
    $pdo = db();
    $sid = (int)$shift['id'];
    $s = $pdo->prepare("SELECT COUNT(*) cnt, COALESCE(SUM(total_amount),0) total FROM sales_orders WHERE pos_shift_id = ? AND channel = 'pos' AND status <> 'cancelled'");
    $s->execute([$sid]);
    $sales = $s->fetch();
    $r = $pdo->prepare("SELECT COUNT(*) cnt, COALESCE(SUM(total_amount),0) total FROM sales_returns WHERE pos_shift_id = ? AND status = 'completed'");
    $r->execute([$sid]);
    $ret = $r->fetch();
    $m = $pdo->prepare("SELECT method, COALESCE(SUM(amount),0) total FROM payments WHERE pos_shift_id = ? AND method <> 'credit_note' GROUP BY method");
    $m->execute([$sid]);
    $methods = [];
    foreach ($m as $row) {
        $methods[$row['method']] = (float)$row['total'];
    }
    $cashIn = $methods['cash'] ?? 0.0;
    return [
        'orders'        => (int)$sales['cnt'],
        'total_sales'   => (float)$sales['total'],
        'returns_count' => (int)$ret['cnt'],
        'total_returns' => (float)$ret['total'],
        'methods'       => $methods,
        'cash_movement' => $cashIn,
        'expected_cash' => round((float)$shift['opening_cash'] + $cashIn, 2),
    ];
}

/* ------------------------------------------------------------------ */
/* Products, prices and tax                                            */
/* ------------------------------------------------------------------ */

/** GST rate for each product id: item taxes, else its tax template, else the POS default. */
function pos_tax_rates(array $products): array
{
    $pdo = db();
    $itemRates = [];
    foreach ($pdo->query('SELECT product_id, SUM(rate_or_amount) r FROM product_taxes GROUP BY product_id') as $row) {
        $itemRates[(int)$row['product_id']] = (float)$row['r'];
    }
    $tplRates = [];
    foreach ($pdo->query("SELECT tax_template_id, SUM(rate_or_amount) r FROM tax_template_items WHERE type = 'on_item' AND based_on = 'net_amount' GROUP BY tax_template_id") as $row) {
        $tplRates[(int)$row['tax_template_id']] = (float)$row['r'];
    }
    $default = (float)pos_setting('pos_default_tax_rate');
    $out = [];
    foreach ($products as $p) {
        $id = (int)$p['id'];
        if (!empty($p['is_exempt_from_tax']) || !empty($p['is_nil_rated'])) {
            $out[$id] = 0.0;
        } elseif (isset($itemRates[$id]) && $itemRates[$id] > 0) {
            $out[$id] = $itemRates[$id];
        } elseif (!empty($p['default_tax_template_id']) && isset($tplRates[(int)$p['default_tax_template_id']])) {
            $out[$id] = $tplRates[(int)$p['default_tax_template_id']];
        } else {
            $out[$id] = $default;
        }
    }
    return $out;
}

const POS_PRODUCT_COLUMNS = 'p.id, p.sku, p.name, p.image, p.category_id, p.brand_id, p.unit, p.hsn_sac_code, p.selling_price,
    p.price_includes_tax, p.is_exempt_from_tax, p.is_nil_rated, p.default_tax_template_id, p.allow_discount, p.not_discountable,
    p.max_discount_percent, p.is_price_editable_in_transactions, p.minimum_selling_price, p.is_stock_item, p.status';

/**
 * Sellable products with their price on the given price list, GST rate
 * and stock at the given store. $ids limits it to those products.
 */
function pos_products(?int $warehouseId, ?int $priceListId, ?array $ids = null, bool $lock = false): array
{
    $pdo = db();
    $sql = 'SELECT ' . POS_PRODUCT_COLUMNS . ', pli.rate list_rate, c.name category_name, b.name brand_name
        FROM products p
        LEFT JOIN price_list_items pli ON pli.product_id = p.id AND pli.price_list_id = ?
        LEFT JOIN categories c ON c.id = p.category_id
        LEFT JOIN brands b ON b.id = p.brand_id';
    $params = [(int)$priceListId];
    if ($ids !== null) {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        $sql .= ' WHERE p.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $params = array_merge($params, $ids);
    } else {
        $sql .= " WHERE p.status = 'active' AND p.is_sales_item = 1 AND p.available_for_retail_sales = 1";
    }
    $sql .= ' ORDER BY p.name';
    if ($lock) {
        $sql .= ' FOR UPDATE';
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    if (!$rows) {
        return [];
    }

    $stock = [];
    if ($warehouseId) {
        $stmt = $pdo->prepare('SELECT product_id, SUM(quantity) q FROM stock_bins WHERE warehouse_id = ? GROUP BY product_id');
        $stmt->execute([$warehouseId]);
        foreach ($stmt as $r) {
            $stock[(int)$r['product_id']] = (int)$r['q'];
        }
    }
    $rates = pos_tax_rates($rows);
    $out = [];
    foreach ($rows as $p) {
        $id = (int)$p['id'];
        $p['rate'] = $p['list_rate'] !== null ? (float)$p['list_rate'] : (float)$p['selling_price'];
        $p['tax_rate'] = $rates[$id];
        $p['stock'] = $stock[$id] ?? 0;
        $out[$id] = $p;
    }
    return $out;
}

/** Barcodes per product id. */
function pos_barcodes(): array
{
    $out = [];
    foreach (db()->query('SELECT product_id, barcode FROM product_barcodes') as $r) {
        $out[(int)$r['product_id']][] = $r['barcode'];
    }
    return $out;
}

/** The customer every sale falls back to. Created on first use. */
function pos_walk_in_customer_id(): int
{
    $pdo = db();
    $default = (int)pos_setting('pos_default_customer_id');
    if ($default) {
        $stmt = $pdo->prepare('SELECT id FROM customers WHERE id = ?');
        $stmt->execute([$default]);
        if ($stmt->fetchColumn()) {
            return $default;
        }
    }
    $id = (int)$pdo->query("SELECT id FROM customers WHERE name = 'Walk-in Customer' ORDER BY id LIMIT 1")->fetchColumn();
    if (!$id) {
        $pdo->prepare("INSERT INTO customers (name, customer_type, customer_group) VALUES ('Walk-in Customer', 'individual', 'Retail')")->execute();
        $id = (int)$pdo->lastInsertId();
    }
    return $id;
}

function pos_is_walk_in(int $customerId): bool
{
    $stmt = db()->prepare('SELECT name FROM customers WHERE id = ?');
    $stmt->execute([$customerId]);
    return $customerId === pos_walk_in_customer_id() || $stmt->fetchColumn() === 'Walk-in Customer';
}

/* ------------------------------------------------------------------ */
/* Cart pricing                                                        */
/* ------------------------------------------------------------------ */

/**
 * Validates a cart payload against the product master and prices it.
 *
 * Payload: {customer_id, discount, lines: [{product_id, qty, price,
 * disc_type ('percent'|'amount'), disc_value, tax_rate}], held_id,
 * exchange_return_id}. Returns the priced lines and bill totals, or throws
 * RuntimeException with a message the cashier can act on.
 *
 * $trusted skips the per-user discount/price permission checks (used by
 * the Cashfree webhook, whose cart was already checked when the QR was made).
 */
function pos_price_cart(array $payload, array $ctx, bool $checkStock = true, bool $trusted = false, bool $lock = false): array
{
    $rawLines = is_array($payload['lines'] ?? null) ? $payload['lines'] : [];
    $qtyByProduct = [];
    foreach ($rawLines as $l) {
        $pid = (int)($l['product_id'] ?? 0);
        $qty = (int)($l['qty'] ?? 0);
        if ($pid > 0 && $qty > 0) {
            $qtyByProduct[$pid] = ($qtyByProduct[$pid] ?? 0) + $qty;
        }
    }
    if (!$qtyByProduct) {
        throw new RuntimeException('Cart is empty. Add at least one product.');
    }
    $products = pos_products($ctx['warehouse_id'], $ctx['price_list_id'], array_keys($qtyByProduct), $lock);

    $discountAllowed = $trusted || (pos_flag('pos_allow_discount') && pos_can('discount'));
    $overrideAllowed = $trusted || (pos_flag('pos_allow_price_override') && pos_can('price_override'));
    $cashierCap = (!$trusted && !can_manage_module('pos')) ? (float)pos_setting('pos_max_discount_percent') : 0.0;
    $slabs = pos_tax_slabs();

    $lines = [];
    foreach ($rawLines as $l) {
        $pid = (int)($l['product_id'] ?? 0);
        $qty = (int)($l['qty'] ?? 0);
        if ($pid <= 0 || $qty <= 0) {
            continue;
        }
        $p = $products[$pid] ?? null;
        if (!$p || $p['status'] !== 'active') {
            throw new RuntimeException('A product in the cart is no longer available.');
        }

        $price = (float)$p['rate'];
        if (isset($l['price']) && is_numeric($l['price']) && abs((float)$l['price'] - $price) > 0.004) {
            if (!$overrideAllowed || !$p['is_price_editable_in_transactions']) {
                throw new RuntimeException('Price changes are not allowed for ' . $p['name'] . '.');
            }
            $price = round((float)$l['price'], 2);
            if ($price < 0) {
                throw new RuntimeException('Price for ' . $p['name'] . ' cannot be negative.');
            }
            if ((float)$p['minimum_selling_price'] > 0 && $price < (float)$p['minimum_selling_price']) {
                throw new RuntimeException($p['name'] . ' cannot be sold below ' . pos_money($p['minimum_selling_price']) . '.');
            }
        }

        $gross = round($qty * $price, 2);
        $discType = ($l['disc_type'] ?? 'percent') === 'amount' ? 'amount' : 'percent';
        $discValue = max(0, (float)($l['disc_value'] ?? 0));
        $disc = 0.0;
        if ($discValue > 0) {
            if (!$discountAllowed || !$p['allow_discount'] || $p['not_discountable']) {
                throw new RuntimeException('Discount is not allowed on ' . $p['name'] . '.');
            }
            $disc = $discType === 'percent' ? round($gross * min($discValue, 100) / 100, 2) : min(round($discValue, 2), $gross);
            $pct = $gross > 0 ? $disc / $gross * 100 : 0;
            $cap = (float)$p['max_discount_percent'] > 0 ? (float)$p['max_discount_percent'] : 0;
            if ($cashierCap > 0) {
                $cap = $cap > 0 ? min($cap, $cashierCap) : $cashierCap;
            }
            if ($cap > 0 && $pct > $cap + 0.001) {
                throw new RuntimeException('Discount on ' . $p['name'] . ' cannot exceed ' . rtrim(rtrim(number_format($cap, 2), '0'), '.') . '%.');
            }
        }

        $taxRate = (float)$p['tax_rate'];
        if (isset($l['tax_rate']) && is_numeric($l['tax_rate'])) {
            $asked = (float)$l['tax_rate'];
            if (abs($asked - $taxRate) > 0.001) {
                if (!in_array($asked, $slabs, false)) {
                    throw new RuntimeException('Tax rate ' . $asked . '% is not one of the configured slabs.');
                }
                $taxRate = $asked;
            }
        }

        $factor = $p['price_includes_tax'] ? 1 + $taxRate / 100 : 1;
        $subExcl = round($gross / $factor, 2);
        $discExcl = round($disc / $factor, 2);
        $lines[] = [
            'product_id'   => $pid,
            'name'         => $p['name'],
            'sku'          => $p['sku'],
            'unit'         => $p['unit'] ?: 'pcs',
            'is_stock'     => (int)$p['is_stock_item'] === 1,
            'image'        => $p['image'],
            'qty'          => $qty,
            'price'        => $price,
            'list_price'   => (float)$p['rate'],
            'inclusive'    => (int)$p['price_includes_tax'],
            'disc_type'    => $discType,
            'disc_value'   => $discValue,
            'discount'     => $disc,
            'tax_rate'     => $taxRate,
            'gross'        => $gross,
            'sub_excl'     => $subExcl,
            'disc_excl'    => $discExcl,
            'taxable'      => round($subExcl - $discExcl, 2),
        ];
        if ($checkStock && $p['is_stock_item'] && $p['stock'] < $qtyByProduct[$pid]) {
            throw new RuntimeException('Only ' . max(0, $p['stock']) . ' of ' . $p['name'] . ' in stock at this store.');
        }
    }

    $taxableTotal = round(array_sum(array_column($lines, 'taxable')), 2);
    $addDisc = max(0, round((float)($payload['discount'] ?? 0), 2));
    if ($addDisc > 0 && !$discountAllowed) {
        throw new RuntimeException('Bill discounts are not allowed.');
    }
    $addDisc = min($addDisc, $taxableTotal);
    if ($addDisc > 0 && $cashierCap > 0 && $taxableTotal > 0 && $addDisc / $taxableTotal * 100 > $cashierCap + 0.001) {
        throw new RuntimeException('Bill discount cannot exceed ' . $cashierCap . '% of the bill.');
    }

    $allocated = 0.0;
    $tax = 0.0;
    $breakup = [];
    $n = count($lines);
    foreach ($lines as $i => &$line) {
        $share = 0.0;
        if ($addDisc > 0 && $taxableTotal > 0) {
            $share = $i === $n - 1 ? round($addDisc - $allocated, 2) : round($addDisc * $line['taxable'] / $taxableTotal, 2);
            $allocated += $share;
        }
        $line['bill_discount'] = $share;
        $line['net'] = round($line['taxable'] - $share, 2);
        $line['tax'] = round($line['net'] * $line['tax_rate'] / 100, 2);
        $line['total'] = round($line['net'] + $line['tax'], 2);
        $tax += $line['tax'];
        $key = rtrim(rtrim(number_format($line['tax_rate'], 2, '.', ''), '0'), '.');
        $breakup[$key]['taxable'] = round(($breakup[$key]['taxable'] ?? 0) + $line['net'], 2);
        $breakup[$key]['tax'] = round(($breakup[$key]['tax'] ?? 0) + $line['tax'], 2);
    }
    unset($line);
    ksort($breakup, SORT_NUMERIC);

    $net = round($taxableTotal - $addDisc, 2);
    $tax = round($tax, 2);
    $unrounded = round($net + $tax, 2);
    $roundOff = pos_flag('pos_round_off') ? round(round($unrounded) - $unrounded, 2) : 0.0;

    return [
        'lines'               => $lines,
        'items_count'         => array_sum(array_column($lines, 'qty')),
        'subtotal'            => round(array_sum(array_column($lines, 'sub_excl')), 2),
        'item_discount'       => round(array_sum(array_column($lines, 'disc_excl')), 2),
        'additional_discount' => $addDisc,
        'net'                 => $net,
        'tax'                 => $tax,
        'round_off'           => $roundOff,
        'grand_total'         => round($unrounded + $roundOff, 2),
        'tax_breakup'         => $breakup,
    ];
}

/** Normalises a stored/posted cart so older payloads ({product_id, quantity}) still work. */
function pos_normalize_payload(array $payload): array
{
    if (!isset($payload['lines']) && isset($payload[0]['product_id'])) {
        $payload = ['lines' => array_map(fn($r) => ['product_id' => (int)$r['product_id'], 'qty' => (int)($r['quantity'] ?? $r['qty'] ?? 0)], $payload)];
    }
    $payload['lines'] = array_values(array_filter((array)($payload['lines'] ?? []), 'is_array'));
    $payload['customer_id'] = (int)($payload['customer_id'] ?? 0);
    $payload['discount'] = (float)($payload['discount'] ?? 0);
    $payload['held_id'] = (int)($payload['held_id'] ?? 0) ?: null;
    $payload['exchange_return_id'] = (int)($payload['exchange_return_id'] ?? 0) ?: null;
    return $payload;
}

/** Decodes the cart JSON posted by the New Sale / Checkout screens. */
function pos_payload_from_request(string $field = 'cart'): array
{
    $data = json_decode((string)($_POST[$field] ?? ''), true);
    return pos_normalize_payload(is_array($data) ? $data : []);
}

/** An open exchange credit (from a return processed as "Exchange"), or null. */
function pos_exchange_credit(int $returnId): ?array
{
    $stmt = db()->prepare("SELECT sr.*, c.name customer_name, so.pos_no FROM sales_returns sr JOIN customers c ON c.id = sr.customer_id JOIN sales_orders so ON so.id = sr.sales_order_id WHERE sr.id = ? AND sr.exchange_status = 'open'");
    $stmt->execute([$returnId]);
    return $stmt->fetch() ?: null;
}

/* ------------------------------------------------------------------ */
/* Postings                                                            */
/* ------------------------------------------------------------------ */

function pos_invoice_status(float $paid, float $total): string
{
    if ($paid >= $total - 0.009) {
        return 'paid';
    }
    return $paid > 0.009 ? 'partially_paid' : 'unpaid';
}

/**
 * Completes a POS sale inside the caller's transaction: Sales Order +
 * items, stock out, Invoice + items, and one payment row per tender.
 *
 * $payments: [['method' => 'cash'|'card'|'upi'|'wallet', 'amount' => x, 'reference' => '...'], ...].
 * Cash beyond the bill is change; other methods may not exceed what's due.
 * Paying less than the bill is a credit sale (named customers only).
 *
 * @return array ['order_id', 'invoice_id', 'pos_no', 'grand_total', 'change']
 */
function pos_complete_sale(PDO $pdo, array $payload, array $payments, array $ctx, ?int $createdBy, bool $trusted = false): array
{
    if (!$ctx['warehouse_id']) {
        throw new RuntimeException('No store is set up yet. Add a warehouse under Supply Chain > Warehouses.');
    }
    $payload = pos_normalize_payload($payload);
    $cart = pos_price_cart($payload, $ctx, true, $trusted, true);
    $grand = $cart['grand_total'];

    $customerId = $payload['customer_id'] ?: pos_walk_in_customer_id();
    $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ?');
    $stmt->execute([$customerId]);
    $customer = $stmt->fetch();
    if (!$customer) {
        throw new RuntimeException('Customer not found.');
    }

    // Exchange credit from a return is applied first, as its own tender.
    $credit = null;
    if ($payload['exchange_return_id']) {
        $credit = pos_exchange_credit($payload['exchange_return_id']);
        if (!$credit) {
            throw new RuntimeException('That exchange credit has already been used or refunded.');
        }
    }

    $tenders = [];
    foreach ($payments as $p) {
        $method = (string)($p['method'] ?? '');
        $amount = round((float)($p['amount'] ?? 0), 2);
        if ($amount <= 0) {
            continue;
        }
        if (!in_array($method, ['cash', 'card', 'upi', 'wallet'], true)) {
            throw new RuntimeException('Unknown payment method.');
        }
        $tenders[] = ['method' => $method, 'amount' => $amount, 'reference' => mb_substr(trim((string)($p['reference'] ?? '')), 0, 120) ?: null];
    }

    $creditUsed = $credit ? min((float)$credit['exchange_amount'], $grand) : 0.0;
    $due = round($grand - $creditUsed, 2);
    $received = round(array_sum(array_column($tenders, 'amount')), 2);
    $cashReceived = round(array_sum(array_map(fn($t) => $t['method'] === 'cash' ? $t['amount'] : 0, $tenders)), 2);
    $nonCash = round($received - $cashReceived, 2);
    if ($nonCash > $due + 0.009) {
        throw new RuntimeException('Card / UPI / Wallet amounts cannot be more than the amount due (' . pos_money($due) . ').');
    }
    $change = max(0, round($received - $due, 2));
    $paid = round(min($received, $due) + $creditUsed, 2);

    if ($paid < $grand - 0.009) {
        if (!$trusted && !pos_flag('pos_allow_credit_sale')) {
            throw new RuntimeException('Please collect the full amount (' . pos_money($due) . ').');
        }
        if (pos_is_walk_in($customerId)) {
            throw new RuntimeException('Pick or add a named customer to leave a balance due. Walk-in sales must be paid in full.');
        }
        if ($customer['credit_hold']) {
            throw new RuntimeException($customer['name'] . ' is on credit hold. Please collect the full amount.');
        }
        $limit = (float)$customer['credit_limit'];
        $owing = $grand - $paid;
        if ($limit > 0 && !$customer['bypass_credit_check']) {
            $exposure = customer_credit_exposure($customerId)['exposure'];
            if ($exposure + $owing > $limit + 0.009) {
                throw new RuntimeException('This would take ' . $customer['name'] . ' over their credit limit of ' . pos_money($limit) . ' (currently owes ' . pos_money($exposure) . ').');
            }
        }
    }

    $posNo = pos_next_code('POS-' . date('Y') . '-', 'sales_orders', 'pos_no', 6);
    $orderNo = next_code('SO', 'sales_orders', 'order_no');
    $methodsUsed = array_values(array_unique(array_column($tenders, 'method')));
    if ($credit) {
        array_unshift($methodsUsed, 'store_credit');
    }
    $methodLabel = count($methodsUsed) > 1 ? 'Split' : pos_method_label($methodsUsed[0] ?? 'cash');
    if (!$methodsUsed) {
        $methodLabel = 'Credit';
    }

    $pdo->prepare("INSERT INTO sales_orders (order_no, pos_no, pos_profile_id, pos_shift_id, customer_id, warehouse_id, price_list_id, order_date, status, channel, notes,
            total_amount, net_amount, additional_discount, item_discount, tax_amount, round_off, amount_received, change_amount, payment_method, created_by)
        VALUES (?,?,?,?,?,?,?,?,'completed','pos',?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$orderNo, $posNo, $ctx['profile_id'], $ctx['shift_id'], $customerId, $ctx['warehouse_id'], $ctx['price_list_id'], today(), 'POS sale',
            $grand, $cart['net'], $cart['additional_discount'], $cart['item_discount'], $cart['tax'], $cart['round_off'], $received + $creditUsed, $change, $methodLabel, $createdBy]);
    $orderId = (int)$pdo->lastInsertId();

    $itemStmt = $pdo->prepare('INSERT INTO sales_order_items (order_id, product_id, description, warehouse_id, quantity, uom, unit_price, discount_percent, discount_amount, tax_rate, tax_amount, subtotal, line_total)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ($cart['lines'] as $l) {
        $itemStmt->execute([$orderId, $l['product_id'], $l['name'], $ctx['warehouse_id'], $l['qty'], $l['unit'], $l['price'],
            $l['disc_type'] === 'percent' ? min($l['disc_value'], 100) : 0, $l['discount'], $l['tax_rate'], $l['tax'], $l['taxable'], $l['total']]);
        if ($l['is_stock']) {
            stock_move($l['product_id'], $ctx['warehouse_id'], -$l['qty'], 'out', $posNo, 'POS sale', $createdBy);
        }
    }

    $invoiceNo = next_code('INV', 'invoices', 'invoice_no');
    $pdo->prepare('INSERT INTO invoices (invoice_no, sales_order_id, customer_id, invoice_date, due_date, status, subtotal, tax, total, amount_paid, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$invoiceNo, $orderId, $customerId, today(), today(), pos_invoice_status($paid, $grand), $cart['net'] + $cart['round_off'], $cart['tax'], $grand, $paid, 'POS sale ' . $posNo, $createdBy]);
    $invoiceId = (int)$pdo->lastInsertId();

    $invItem = $pdo->prepare('INSERT INTO invoice_items (invoice_id, product_id, description, quantity, unit_price, subtotal) VALUES (?,?,?,?,?,?)');
    foreach ($cart['lines'] as $l) {
        $invItem->execute([$invoiceId, $l['product_id'], $l['name'], $l['qty'], $l['price'], $l['net']]);
    }

    $payStmt = $pdo->prepare('INSERT INTO payments (invoice_id, amount, payment_date, method, reference, notes, created_by, pos_shift_id) VALUES (?,?,?,?,?,?,?,?)');
    if ($credit && $creditUsed > 0) {
        $payStmt->execute([$invoiceId, $creditUsed, today(), 'store_credit', $credit['return_no'], 'Exchange credit from ' . $credit['return_no'], $createdBy, $ctx['shift_id']]);
        $leftover = round((float)$credit['exchange_amount'] - $creditUsed, 2);
        if ($leftover > 0.009) {
            // Credit bigger than the new bill: give the rest back in cash
            // against the original invoice.
            $payStmt->execute([$credit['invoice_id'], $leftover, today(), 'store_credit', $credit['return_no'], 'Exchange credit balance refunded', $createdBy, $ctx['shift_id']]);
            $payStmt->execute([$credit['invoice_id'], -$leftover, today(), 'cash', $credit['return_no'], 'Exchange credit balance refunded in cash', $createdBy, $ctx['shift_id']]);
        }
        $pdo->prepare("UPDATE sales_returns SET exchange_status = 'used', exchange_order_id = ? WHERE id = ?")->execute([$orderId, $credit['id']]);
    }
    $changeLeft = $change;
    foreach ($tenders as $t) {
        $amount = $t['amount'];
        if ($t['method'] === 'cash' && $changeLeft > 0) {
            $take = min($changeLeft, $amount);
            $amount = round($amount - $take, 2);
            $changeLeft = round($changeLeft - $take, 2);
        }
        if ($amount > 0) {
            $payStmt->execute([$invoiceId, $amount, today(), $t['method'], $t['reference'] ?? $posNo, 'POS sale ' . $posNo, $createdBy, $ctx['shift_id']]);
        }
    }

    if ($payload['held_id']) {
        $pdo->prepare('DELETE FROM pos_held_orders WHERE id = ?')->execute([$payload['held_id']]);
    }

    return ['order_id' => $orderId, 'invoice_id' => $invoiceId, 'pos_no' => $posNo, 'grand_total' => $grand, 'change' => $change];
}

/** A POS order with customer, cashier, terminal and invoice. */
function pos_order(int $orderId): ?array
{
    $stmt = db()->prepare("SELECT so.*, c.name customer_name, c.mobile customer_mobile, c.phone customer_phone, c.email customer_email, c.gstin customer_gstin, c.address customer_address,
            u.name cashier_name, pp.name terminal_name, w.name store_name, i.id invoice_id, i.invoice_no, i.status invoice_status, i.amount_paid, i.total invoice_total, sh.shift_no
        FROM sales_orders so JOIN customers c ON c.id = so.customer_id LEFT JOIN users u ON u.id = so.created_by
        LEFT JOIN pos_profiles pp ON pp.id = so.pos_profile_id LEFT JOIN warehouses w ON w.id = so.warehouse_id
        LEFT JOIN pos_shifts sh ON sh.id = so.pos_shift_id
        LEFT JOIN invoices i ON i.id = (SELECT MAX(id) FROM invoices WHERE sales_order_id = so.id)
        WHERE so.id = ? AND so.channel = 'pos'");
    $stmt->execute([$orderId]);
    return $stmt->fetch() ?: null;
}

function pos_order_items(int $orderId): array
{
    $stmt = db()->prepare('SELECT soi.*, p.name product_name, p.sku, p.image, p.is_stock_item, p.hsn_sac_code FROM sales_order_items soi JOIN products p ON p.id = soi.product_id WHERE soi.order_id = ? ORDER BY soi.id');
    $stmt->execute([$orderId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        // Orders made before migration 031 have no line_total.
        if ((float)$r['line_total'] == 0.0 && (float)$r['subtotal'] > 0) {
            $r['line_total'] = $r['subtotal'];
        }
    }
    return $rows;
}

function pos_order_payments(int $invoiceId): array
{
    $stmt = db()->prepare('SELECT * FROM payments WHERE invoice_id = ? ORDER BY id');
    $stmt->execute([$invoiceId]);
    return $stmt->fetchAll();
}

/** Units already returned per product on an order (completed returns only). */
function pos_returned_qty(int $orderId): array
{
    $stmt = db()->prepare("SELECT sri.product_id, SUM(sri.quantity) q FROM sales_return_items sri JOIN sales_returns sr ON sr.id = sri.sales_return_id WHERE sr.sales_order_id = ? AND sr.status = 'completed' GROUP BY sri.product_id");
    $stmt->execute([$orderId]);
    $out = [];
    foreach ($stmt as $r) {
        $out[(int)$r['product_id']] = (int)$r['q'];
    }
    return $out;
}

/** 'none' | 'partial' | 'full' return state of an order. */
function pos_return_state(int $orderId): string
{
    $returned = pos_returned_qty($orderId);
    if (!$returned) {
        return 'none';
    }
    $sold = 0;
    foreach (pos_order_items($orderId) as $it) {
        $sold += (int)$it['quantity'];
    }
    return array_sum($returned) >= $sold ? 'full' : 'partial';
}

/**
 * Processes a POS return inside the caller's transaction: stock back in,
 * a credit note on the invoice, and either a refund (cash/card/UPI/wallet)
 * or an open exchange credit to spend on the next sale.
 *
 * $qtys: [product_id => qty]. $refundMethod: cash|card|upi|wallet|exchange.
 */
function pos_process_return(PDO $pdo, array $order, array $qtys, string $reason, string $refundMethod, array $ctx, ?int $userId): array
{
    if ($order['status'] === 'cancelled') {
        throw new RuntimeException('This order was cancelled, so it cannot be returned.');
    }
    if (!in_array($refundMethod, ['cash', 'card', 'upi', 'wallet', 'exchange'], true)) {
        throw new RuntimeException('Pick how to refund the customer.');
    }
    $items = pos_order_items((int)$order['id']);
    $returned = pos_returned_qty((int)$order['id']);
    $soldByProduct = [];
    $totalByProduct = [];
    foreach ($items as $it) {
        $pid = (int)$it['product_id'];
        $soldByProduct[$pid] = ($soldByProduct[$pid] ?? 0) + (int)$it['quantity'];
        $totalByProduct[$pid] = ($totalByProduct[$pid] ?? 0) + (float)$it['line_total'];
        $meta[$pid] = $it;
    }

    $lines = [];
    $total = 0.0;
    foreach ($qtys as $pid => $qty) {
        $pid = (int)$pid;
        $qty = (int)$qty;
        if ($qty <= 0) {
            continue;
        }
        if (!isset($soldByProduct[$pid])) {
            throw new RuntimeException('That item is not on this invoice.');
        }
        $remaining = $soldByProduct[$pid] - ($returned[$pid] ?? 0);
        if ($qty > $remaining) {
            throw new RuntimeException('Only ' . $remaining . ' of ' . $meta[$pid]['product_name'] . ' can still be returned.');
        }
        // What the customer actually paid per unit (after all discounts, incl. tax).
        $amount = $qty === $remaining
            ? round($totalByProduct[$pid] - round($totalByProduct[$pid] * ($returned[$pid] ?? 0) / $soldByProduct[$pid], 2), 2)
            : round($totalByProduct[$pid] * $qty / $soldByProduct[$pid], 2);
        $lines[] = ['product_id' => $pid, 'qty' => $qty, 'unit_price' => round($amount / $qty, 2), 'amount' => $amount, 'uom' => $meta[$pid]['uom'] ?: 'pcs', 'is_stock' => (int)$meta[$pid]['is_stock_item'] === 1];
        $total += $amount;
    }
    if (!$lines) {
        throw new RuntimeException('Enter a return quantity for at least one item.');
    }
    $total = round($total, 2);
    $warehouseId = (int)($ctx['warehouse_id'] ?: $order['warehouse_id']);
    if (!$warehouseId) {
        throw new RuntimeException('No store is selected to receive the returned stock.');
    }

    $returnNo = next_code('SR', 'sales_returns', 'return_no');
    $pdo->prepare("INSERT INTO sales_returns (return_no, sales_order_id, customer_id, warehouse_id, invoice_id, return_date, status, reason, total_amount, credit_amount, created_by, pos_shift_id, refund_method, exchange_status)
        VALUES (?,?,?,?,?,?,'completed',?,?,?,?,?,?,?)")
        ->execute([$returnNo, $order['id'], $order['customer_id'], $warehouseId, $order['invoice_id'], today(), mb_substr($reason, 0, 255) ?: null, $total, $total, $userId, $ctx['shift_id'], $refundMethod, $refundMethod === 'exchange' ? 'open' : 'none']);
    $returnId = (int)$pdo->lastInsertId();

    $itemStmt = $pdo->prepare('INSERT INTO sales_return_items (sales_return_id, product_id, quantity, uom, unit_price, subtotal) VALUES (?,?,?,?,?,?)');
    foreach ($lines as $l) {
        $itemStmt->execute([$returnId, $l['product_id'], $l['qty'], $l['uom'], $l['unit_price'], $l['amount']]);
        if ($l['is_stock']) {
            stock_move($l['product_id'], $warehouseId, $l['qty'], 'in', $returnNo, 'POS return ' . $order['pos_no'], $userId);
        }
    }

    $refund = 0.0;
    if ($order['invoice_id']) {
        $inv = $pdo->prepare('SELECT * FROM invoices WHERE id = ? FOR UPDATE');
        $inv->execute([$order['invoice_id']]);
        $invoice = $inv->fetch();
        $pay = $pdo->prepare('INSERT INTO payments (invoice_id, amount, payment_date, method, reference, notes, created_by, pos_shift_id) VALUES (?,?,?,?,?,?,?,?)');
        // The credit note first clears any balance still owed; whatever the
        // customer had actually paid for these items goes back to them.
        $pay->execute([$invoice['id'], $total, today(), 'credit_note', $returnNo, 'Credit note for POS return ' . $returnNo, $userId, $ctx['shift_id']]);
        $refund = round(max(0, min($total, (float)$invoice['amount_paid'] + $total - (float)$invoice['total'])), 2);
        if ($refund > 0) {
            $method = $refundMethod === 'exchange' ? 'store_credit' : $refundMethod;
            $pay->execute([$invoice['id'], -$refund, today(), $method, $returnNo, $refundMethod === 'exchange' ? 'Exchange credit issued' : 'Refund for POS return ' . $returnNo, $userId, $ctx['shift_id']]);
        }
        $newPaid = round((float)$invoice['amount_paid'] + $total - $refund, 2);
        $pdo->prepare('UPDATE invoices SET amount_paid = ?, status = ? WHERE id = ?')->execute([$newPaid, pos_invoice_status($newPaid, (float)$invoice['total']), $invoice['id']]);
    }
    if ($refundMethod === 'exchange') {
        // Only what the customer had actually paid can be spent again.
        $pdo->prepare('UPDATE sales_returns SET exchange_amount = ?, exchange_status = ? WHERE id = ?')
            ->execute([$refund, $refund > 0.009 ? 'open' : 'none', $returnId]);
    }

    return ['return_id' => $returnId, 'return_no' => $returnNo, 'total' => $total, 'refund' => $refund];
}

/**
 * Cancels a POS sale inside the caller's transaction: stock back in, every
 * tender refunded (exchange credit comes back as cash), invoice + order
 * marked cancelled.
 */
function pos_cancel_order(PDO $pdo, array $order, array $ctx, ?int $userId, string $reason = ''): void
{
    if ($order['status'] === 'cancelled') {
        throw new RuntimeException('This order is already cancelled.');
    }
    if (pos_returned_qty((int)$order['id'])) {
        throw new RuntimeException('Items on this order were already returned. Use Returns & Exchanges for the rest.');
    }
    foreach (pos_order_items((int)$order['id']) as $it) {
        if ($it['is_stock_item']) {
            stock_move((int)$it['product_id'], (int)($it['warehouse_id'] ?: $order['warehouse_id'] ?: $ctx['warehouse_id']), (int)$it['quantity'], 'in', $order['pos_no'], 'POS sale cancelled', $userId);
        }
    }
    if ($order['invoice_id']) {
        $stmt = $pdo->prepare("SELECT method, SUM(amount) amt FROM payments WHERE invoice_id = ? AND method <> 'credit_note' GROUP BY method HAVING SUM(amount) > 0.009");
        $stmt->execute([$order['invoice_id']]);
        $pay = $pdo->prepare('INSERT INTO payments (invoice_id, amount, payment_date, method, reference, notes, created_by, pos_shift_id) VALUES (?,?,?,?,?,?,?,?)');
        foreach ($stmt->fetchAll() as $row) {
            $method = $row['method'] === 'store_credit' ? 'cash' : $row['method'];
            if ($row['method'] === 'store_credit') {
                $pay->execute([$order['invoice_id'], -(float)$row['amt'], today(), 'store_credit', $order['pos_no'], 'Exchange credit reversed on cancel', $userId, $ctx['shift_id']]);
                $pay->execute([$order['invoice_id'], (float)$row['amt'], today(), 'cash', $order['pos_no'], 'Exchange credit reversed on cancel', $userId, $ctx['shift_id']]);
            }
            $pay->execute([$order['invoice_id'], -(float)$row['amt'], today(), $method, $order['pos_no'], 'Refund: POS sale cancelled', $userId, $ctx['shift_id']]);
        }
        $pdo->prepare("UPDATE invoices SET status = 'cancelled', amount_paid = 0 WHERE id = ?")->execute([$order['invoice_id']]);
    }
    $pdo->prepare("UPDATE sales_orders SET status = 'cancelled', remarks_internal = ? WHERE id = ?")
        ->execute([mb_substr('Cancelled at POS' . ($reason !== '' ? ': ' . $reason : ''), 0, 500), $order['id']]);
}

/** Saves (or updates) a held cart. Prices are recalculated so the list shows a real amount. */
function pos_hold_cart(PDO $pdo, array $payload, array $ctx, ?int $userId, string $note = ''): array
{
    $payload = pos_normalize_payload($payload);
    $cart = pos_price_cart($payload, $ctx, false);
    $customerId = $payload['customer_id'] ?: pos_walk_in_customer_id();
    $json = json_encode($payload);
    if ($payload['held_id']) {
        $stmt = $pdo->prepare('SELECT id, hold_no FROM pos_held_orders WHERE id = ?');
        $stmt->execute([$payload['held_id']]);
        if ($row = $stmt->fetch()) {
            $pdo->prepare('UPDATE pos_held_orders SET customer_id = ?, cart_json = ?, items_count = ?, amount = ?, note = ?, pos_profile_id = ?, warehouse_id = ? WHERE id = ?')
                ->execute([$customerId, $json, $cart['items_count'], $cart['grand_total'], $note ?: null, $ctx['profile_id'], $ctx['warehouse_id'], $row['id']]);
            return ['id' => (int)$row['id'], 'hold_no' => $row['hold_no']];
        }
    }
    $holdNo = pos_next_code('HLD-', 'pos_held_orders', 'hold_no', 3);
    $pdo->prepare('INSERT INTO pos_held_orders (hold_no, pos_profile_id, warehouse_id, customer_id, cart_json, items_count, amount, note, created_by) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$holdNo, $ctx['profile_id'], $ctx['warehouse_id'], $customerId, $json, $cart['items_count'], $cart['grand_total'], $note ?: null, $userId]);
    return ['id' => (int)$pdo->lastInsertId(), 'hold_no' => $holdNo];
}

/** Customers for the POS pickers, walk-in first. */
function pos_customers(): array
{
    return db()->query("SELECT id, name, mobile, phone, email, customer_type, customer_group, gstin, credit_limit, address FROM customers WHERE status <> 'blocked' ORDER BY (name = 'Walk-in Customer') DESC, name")->fetchAll();
}

/**
 * Creates or updates a customer from the POS Add / Edit Customer modal.
 * Returns the saved row.
 */
function pos_save_customer(array $in): array
{
    $pdo = db();
    $id = (int)($in['id'] ?? 0);
    $type = ($in['customer_type'] ?? 'individual') === 'company' ? 'company' : 'individual';
    $name = trim((string)($in['name'] ?? ''));
    $mobile = preg_replace('/[^0-9+]/', '', (string)($in['mobile'] ?? ''));
    $email = trim((string)($in['email'] ?? ''));
    $gstin = strtoupper(trim((string)($in['gstin'] ?? '')));
    $groups = ['Retail', 'Wholesale', 'Commercial', 'Distributor', 'Dealer', 'Government', 'Non Profit', 'Individual'];
    $group = in_array($in['customer_group'] ?? '', $groups, true) ? $in['customer_group'] : 'Retail';
    $limit = trim((string)($in['credit_limit'] ?? ''));

    if ($name === '') {
        throw new RuntimeException('Full name is required.');
    }
    if (!preg_match('/^\+?\d{10,13}$/', $mobile)) {
        throw new RuntimeException('Enter a valid mobile number (10 digits).');
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('That email address does not look right.');
    }
    if ($gstin !== '' && !preg_match('/^[0-9]{2}[A-Z0-9]{13}$/', $gstin)) {
        throw new RuntimeException('GSTIN must be 15 characters, e.g. 29ABCDE1234F1Z5.');
    }
    if ($limit !== '' && (!is_numeric($limit) || (float)$limit < 0)) {
        throw new RuntimeException('Credit limit must be zero or more.');
    }
    $dupe = $pdo->prepare('SELECT id FROM customers WHERE (mobile = ? OR phone = ?) AND id <> ? LIMIT 1');
    $dupe->execute([$mobile, $mobile, $id]);
    if ($dupe->fetchColumn()) {
        throw new RuntimeException('A customer with this mobile number already exists.');
    }

    $vals = [$name, $type, $group, $type === 'company' ? $name : null, $mobile, $email ?: null, mb_substr(trim((string)($in['address'] ?? '')), 0, 255) ?: null, $gstin ?: null, $limit === '' ? null : (float)$limit];
    if ($id) {
        $pdo->prepare('UPDATE customers SET name = ?, customer_type = ?, customer_group = ?, company = ?, mobile = ?, email = ?, address = ?, gstin = ?, credit_limit = ? WHERE id = ?')
            ->execute(array_merge($vals, [$id]));
    } else {
        $pdo->prepare("INSERT INTO customers (name, customer_type, customer_group, company, mobile, email, address, gstin, credit_limit, sales_channel, customer_since) VALUES (?,?,?,?,?,?,?,?,?,'POS',CURDATE())")
            ->execute($vals);
        $id = (int)$pdo->lastInsertId();
    }
    if (!empty($in['make_default'])) {
        pos_save_settings(['pos_default_customer_id' => $id]);
    }
    $stmt = $pdo->prepare('SELECT id, name, mobile, phone, email, customer_type, customer_group, gstin, credit_limit, address FROM customers WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch();
}

/** Plain-HTML receipt for printing and email. */
function pos_receipt_html(int $orderId, bool $forEmail = false): string
{
    $o = pos_order($orderId);
    if (!$o) {
        return '';
    }
    $items = pos_order_items($orderId);
    $payments = $o['invoice_id'] ? pos_order_payments((int)$o['invoice_id']) : [];
    $width = pos_setting('pos_receipt_width') === '58' ? '58mm' : '80mm';
    $company = setting('company_name', APP_NAME);
    $taxLabel = pos_setting('pos_tax_label');
    $breakup = [];
    foreach ($items as $it) {
        $k = rtrim(rtrim(number_format((float)$it['tax_rate'], 2, '.', ''), '0'), '.');
        $breakup[$k]['taxable'] = ($breakup[$k]['taxable'] ?? 0) + (float)$it['line_total'] - (float)$it['tax_amount'];
        $breakup[$k]['tax'] = ($breakup[$k]['tax'] ?? 0) + (float)$it['tax_amount'];
    }
    ksort($breakup, SORT_NUMERIC);
    $subtotal = 0;
    foreach ($items as $it) {
        $subtotal += (float)$it['quantity'] * (float)$it['unit_price'];
    }

    ob_start();
    ?>
<div class="pos-receipt" style="width:<?= $forEmail ? '100%;max-width:380px' : $width ?>;margin:0 auto;font-family:'Courier New',monospace;font-size:12px;color:#111;line-height:1.4">
  <div style="text-align:center">
    <div style="font-size:15px;font-weight:700"><?= e($company) ?></div>
    <?php if (pos_setting('pos_receipt_header') !== ''): ?><div><?= nl2br(e(pos_setting('pos_receipt_header'))) ?></div><?php endif; ?>
    <?php if (pos_setting('pos_receipt_address') !== ''): ?><div><?= nl2br(e(pos_setting('pos_receipt_address'))) ?></div><?php endif; ?>
    <?php if ($o['store_name']): ?><div><?= e($o['store_name']) ?></div><?php endif; ?>
    <?php if (pos_setting('pos_receipt_phone') !== ''): ?><div>Ph: <?= e(pos_setting('pos_receipt_phone')) ?></div><?php endif; ?>
    <?php if (pos_setting('pos_receipt_gstin') !== ''): ?><div>GSTIN: <?= e(pos_setting('pos_receipt_gstin')) ?></div><?php endif; ?>
    <div style="font-weight:700;margin-top:6px">TAX INVOICE</div>
  </div>
  <hr style="border:0;border-top:1px dashed #555">
  <table style="width:100%;font-size:12px"><tr><td>Invoice: <?= e($o['pos_no'] ?: $o['order_no']) ?></td></tr>
    <tr><td>Date: <?= e(pos_datetime($o['created_at'])) ?></td></tr>
    <tr><td>Cashier: <?= e($o['cashier_name'] ?? '-') ?><?= $o['terminal_name'] ? ' · ' . e($o['terminal_name']) : '' ?></td></tr>
    <?php if (pos_flag('pos_receipt_show_customer')): ?>
      <tr><td>Customer: <?= e($o['customer_name']) ?><?= ($o['customer_mobile'] ?: $o['customer_phone']) ? ' (' . e($o['customer_mobile'] ?: $o['customer_phone']) . ')' : '' ?></td></tr>
      <?php if ($o['customer_gstin']): ?><tr><td>Cust. GSTIN: <?= e($o['customer_gstin']) ?></td></tr><?php endif; ?>
    <?php endif; ?>
  </table>
  <hr style="border:0;border-top:1px dashed #555">
  <table style="width:100%;font-size:12px;border-collapse:collapse">
    <tr><th style="text-align:left">Item</th><th style="text-align:right">Qty</th><th style="text-align:right">Rate</th><th style="text-align:right">Amt</th></tr>
    <?php foreach ($items as $it): ?>
      <tr><td colspan="4"><?= e($it['product_name']) ?></td></tr>
      <tr><td style="color:#555"><?= (float)$it['discount_amount'] > 0 ? 'Disc ' . e(pos_number($it['discount_amount'])) : '' ?></td>
        <td style="text-align:right"><?= (int)$it['quantity'] ?></td>
        <td style="text-align:right"><?= e(pos_number($it['unit_price'])) ?></td>
        <td style="text-align:right"><?= e(pos_number((float)$it['quantity'] * (float)$it['unit_price'] - (float)$it['discount_amount'])) ?></td></tr>
    <?php endforeach; ?>
  </table>
  <hr style="border:0;border-top:1px dashed #555">
  <table style="width:100%;font-size:12px">
    <tr><td>Subtotal</td><td style="text-align:right"><?= e(pos_number($subtotal)) ?></td></tr>
    <?php $discTotal = array_sum(array_map(fn($i) => (float)$i['discount_amount'], $items)) + (float)$o['additional_discount']; ?>
    <?php if ($discTotal > 0): ?><tr><td>Discount</td><td style="text-align:right">-<?= e(pos_number($discTotal)) ?></td></tr><?php endif; ?>
    <tr><td><?= e($taxLabel) ?></td><td style="text-align:right"><?= e(pos_number($o['tax_amount'])) ?></td></tr>
    <?php if ((float)$o['round_off'] != 0): ?><tr><td>Round Off</td><td style="text-align:right"><?= e(pos_number($o['round_off'])) ?></td></tr><?php endif; ?>
    <tr style="font-weight:700;font-size:14px"><td>GRAND TOTAL</td><td style="text-align:right"><?= e(pos_money($o['total_amount'])) ?></td></tr>
  </table>
  <?php if (pos_flag('pos_receipt_show_tax_breakup') && $breakup): ?>
    <hr style="border:0;border-top:1px dashed #555">
    <table style="width:100%;font-size:11px">
      <tr><th style="text-align:left"><?= e($taxLabel) ?>%</th><th style="text-align:right">Taxable</th><th style="text-align:right">CGST</th><th style="text-align:right">SGST</th></tr>
      <?php foreach ($breakup as $rate => $b): ?>
        <tr><td><?= e($rate) ?>%</td><td style="text-align:right"><?= e(pos_number($b['taxable'])) ?></td><td style="text-align:right"><?= e(pos_number($b['tax'] / 2)) ?></td><td style="text-align:right"><?= e(pos_number($b['tax'] / 2)) ?></td></tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
  <hr style="border:0;border-top:1px dashed #555">
  <table style="width:100%;font-size:12px">
    <?php foreach ($payments as $p): if ($p['method'] === 'credit_note' || (float)$p['amount'] <= 0 || $p['notes'] === 'Exchange credit balance refunded') continue; ?>
      <tr><td><?= e(pos_method_label($p['method'])) ?></td><td style="text-align:right"><?= e(pos_number($p['amount'])) ?></td></tr>
    <?php endforeach; ?>
    <?php if ((float)$o['change_amount'] > 0): ?><tr><td>Cash tendered</td><td style="text-align:right"><?= e(pos_number($o['amount_received'])) ?></td></tr><tr><td>Change</td><td style="text-align:right"><?= e(pos_number($o['change_amount'])) ?></td></tr><?php endif; ?>
    <?php if ($o['invoice_id'] && (float)$o['invoice_total'] - (float)$o['amount_paid'] > 0.009 && $o['status'] !== 'cancelled'): ?>
      <tr style="font-weight:700"><td>Balance due</td><td style="text-align:right"><?= e(pos_number((float)$o['invoice_total'] - (float)$o['amount_paid'])) ?></td></tr>
    <?php endif; ?>
  </table>
  <?php if ($o['status'] === 'cancelled'): ?><div style="text-align:center;font-weight:700;margin-top:6px">*** CANCELLED ***</div><?php endif; ?>
  <hr style="border:0;border-top:1px dashed #555">
  <div style="text-align:center"><?= nl2br(e(pos_setting('pos_receipt_footer'))) ?></div>
</div>
    <?php
    return (string)ob_get_clean();
}

/** Emails the receipt with PHP mail(). Returns true when the server accepted it. */
function pos_email_receipt(int $orderId, string $to): bool
{
    $o = pos_order($orderId);
    if (!$o || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $company = setting('company_name', APP_NAME);
    $host = parse_url(defined('APP_URL') ? APP_URL : '', PHP_URL_HOST) ?: 'localhost';
    $from = setting('company_email') ?: ('no-reply@' . preg_replace('/^www\./', '', $host));
    $subject = 'Your receipt ' . ($o['pos_no'] ?: $o['order_no']) . ' from ' . $company;
    $body = '<!DOCTYPE html><html><body style="background:#f4f6fb;padding:20px"><div style="background:#fff;padding:20px;border-radius:10px;max-width:420px;margin:0 auto">'
        . '<p style="font-family:Arial,sans-serif">Thank you for your purchase. Your receipt is below.</p>'
        . pos_receipt_html($orderId, true) . '</div></body></html>';
    $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n"
        . 'From: ' . mb_encode_mimeheader($company) . " <$from>\r\n";
    return @mail($to, mb_encode_mimeheader($subject), $body, $headers);
}
