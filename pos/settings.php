<?php
require_once __DIR__ . '/../includes/auth.php';
require_module_manage('pos');
$ctx = pos_page();
$pdo = db();

$sections = [
    'general'       => ['General', 'fa-solid fa-gear'],
    'profiles'      => ['POS Profile', 'fa-solid fa-cash-register'],
    'price_list'    => ['Price List', 'fa-solid fa-tags'],
    'taxes'         => ['Taxes', 'fa-solid fa-percent'],
    'payment_modes' => ['Payment Modes', 'fa-regular fa-credit-card'],
    'receipt'       => ['Receipt Format', 'fa-solid fa-receipt'],
    'barcode'       => ['Barcode Settings', 'fa-solid fa-barcode'],
    'permissions'   => ['Permissions', 'fa-solid fa-user-shield'],
];
$s = isset($sections[input('s')]) ? input('s') : 'general';

// Which settings each section saves: [flags (checkboxes), values].
$fields = [
    'general'       => [['pos_allow_discount', 'pos_allow_price_override', 'pos_print_receipt_auto', 'pos_show_product_images', 'pos_enable_barcode_scanner', 'pos_require_shift', 'pos_allow_credit_sale'],
                        ['pos_default_warehouse_id', 'pos_default_profile_id', 'pos_default_price_list_id', 'pos_default_customer_id', 'pos_max_discount_percent']],
    'price_list'    => [[], ['pos_default_price_list_id']],
    'taxes'         => [['pos_round_off'], ['pos_tax_label', 'pos_default_tax_rate', 'pos_tax_slabs']],
    'payment_modes' => [['pos_pay_card', 'pos_pay_upi', 'pos_pay_wallet', 'pos_pay_split'], ['pos_quick_cash']],
    'receipt'       => [['pos_receipt_show_customer', 'pos_receipt_show_tax_breakup'], ['pos_receipt_width', 'pos_receipt_header', 'pos_receipt_address', 'pos_receipt_phone', 'pos_receipt_gstin', 'pos_receipt_footer']],
    'barcode'       => [['pos_barcode_match_sku', 'pos_barcode_auto_add', 'pos_barcode_camera', 'pos_enable_barcode_scanner'], []],
    'permissions'   => [[], ['pos_perm_discount', 'pos_perm_price_override', 'pos_perm_returns', 'pos_perm_cancel', 'pos_perm_close_shift', 'pos_perm_delete_held']],
];
$permissions = [
    'pos_perm_discount'       => ['Give discounts', 'Line and bill discounts on New Sale.'],
    'pos_perm_price_override' => ['Change item price', 'Edit the selling price in Edit Item.'],
    'pos_perm_returns'        => ['Process returns & exchanges', 'Return items and refund or issue exchange credit.'],
    'pos_perm_cancel'         => ['Cancel a completed sale', 'Reverses stock and refunds every payment.'],
    'pos_perm_close_shift'    => ['Close a shift', 'Count the drawer and close the shift.'],
    'pos_perm_delete_held'    => ['Delete held orders', 'Remove parked carts from Held Orders.'],
];

$errors = [];
if (is_post()) {
    csrf_verify();
    $action = input('action');

    if ($action === 'save' && isset($fields[$s])) {
        [$flags, $values] = $fields[$s];
        $save = [];
        foreach ($flags as $k) {
            $save[$k] = input($k) === '1' ? '1' : '0';
        }
        foreach ($values as $k) {
            $save[$k] = trim((string)input($k));
        }
        if (isset($save['pos_max_discount_percent']) && (!is_numeric($save['pos_max_discount_percent'] ?: '0') || (float)$save['pos_max_discount_percent'] < 0 || (float)$save['pos_max_discount_percent'] > 100)) {
            $errors[] = 'Maximum discount must be between 0 and 100.';
        }
        if (isset($save['pos_default_tax_rate']) && (!is_numeric($save['pos_default_tax_rate']) || (float)$save['pos_default_tax_rate'] < 0 || (float)$save['pos_default_tax_rate'] > 100)) {
            $errors[] = 'Default tax rate must be between 0 and 100.';
        }
        if (isset($save['pos_tax_slabs'])) {
            $slabs = array_values(array_unique(array_filter(array_map('trim', explode(',', $save['pos_tax_slabs'])), fn($v) => $v !== '')));
            foreach ($slabs as $v) {
                if (!is_numeric($v) || (float)$v < 0 || (float)$v > 100) {
                    $errors[] = 'Tax slabs must be numbers between 0 and 100, separated by commas.';
                    break;
                }
            }
            sort($slabs, SORT_NUMERIC);
            $save['pos_tax_slabs'] = implode(',', $slabs);
        }
        if (isset($save['pos_quick_cash'])) {
            $amounts = array_values(array_filter(array_map('trim', explode(',', $save['pos_quick_cash'])), fn($v) => $v !== ''));
            foreach ($amounts as $v) {
                if (!is_numeric($v) || (float)$v <= 0) {
                    $errors[] = 'Quick cash amounts must be positive numbers, separated by commas.';
                    break;
                }
            }
            $save['pos_quick_cash'] = implode(',', array_slice($amounts, 0, 6));
        }
        if (isset($save['pos_receipt_width']) && !in_array($save['pos_receipt_width'], ['58', '80'], true)) {
            $save['pos_receipt_width'] = '80';
        }
        if (isset($save['pos_receipt_gstin']) && $save['pos_receipt_gstin'] !== '') {
            $save['pos_receipt_gstin'] = strtoupper($save['pos_receipt_gstin']);
            if (!preg_match('/^[0-9]{2}[A-Z0-9]{13}$/', $save['pos_receipt_gstin'])) {
                $errors[] = 'GSTIN must be 15 characters, e.g. 29ABCDE1234F1Z5.';
            }
        }
        foreach ($values as $k) {
            if (str_starts_with($k, 'pos_perm_')) {
                $save[$k] = $save[$k] === 'manage' ? 'manage' : 'edit';
            }
        }
        if (!$errors) {
            pos_save_settings($save);
            flash('success', $sections[$s][0] . ' settings saved.');
            redirect('/pos/settings.php?s=' . $s);
        }
    }

    if ($action === 'save_profile') {
        $id = (int)input('id');
        $name = trim((string)input('name'));
        $wh = (int)input('warehouse_id') ?: null;
        $pl = (int)input('price_list_id') ?: null;
        $status = input('status') === 'inactive' ? 'inactive' : 'active';
        if ($name === '') {
            $errors[] = 'Give the terminal a name, e.g. POS-02.';
        } else {
            $dupe = $pdo->prepare('SELECT id FROM pos_profiles WHERE name = ? AND id <> ?');
            $dupe->execute([$name, $id]);
            if ($dupe->fetchColumn()) {
                $errors[] = 'A terminal called ' . $name . ' already exists.';
            }
        }
        if (!$errors && $id && $status === 'inactive') {
            $open = $pdo->prepare("SELECT shift_no FROM pos_shifts WHERE pos_profile_id = ? AND status = 'open'");
            $open->execute([$id]);
            if ($no = $open->fetchColumn()) {
                $errors[] = 'Close shift ' . $no . ' on this terminal before turning it off.';
            }
        }
        if (!$errors) {
            if ($id) {
                $pdo->prepare('UPDATE pos_profiles SET name = ?, warehouse_id = ?, price_list_id = ?, status = ? WHERE id = ?')->execute([mb_substr($name, 0, 60), $wh, $pl, $status, $id]);
            } else {
                $pdo->prepare('INSERT INTO pos_profiles (name, warehouse_id, price_list_id, status) VALUES (?,?,?,?)')->execute([mb_substr($name, 0, 60), $wh, $pl, $status]);
            }
            flash('success', 'Terminal ' . $name . ' saved.');
            redirect('/pos/settings.php?s=profiles');
        }
        $s = 'profiles';
    }

    if ($action === 'delete_profile') {
        $id = (int)input('id');
        $used = $pdo->prepare('SELECT (SELECT COUNT(*) FROM sales_orders WHERE pos_profile_id = ?) + (SELECT COUNT(*) FROM pos_shifts WHERE pos_profile_id = ?)');
        $used->execute([$id, $id]);
        if ((int)$used->fetchColumn() > 0) {
            flash('warning', 'This terminal has sales or shifts, so it was switched off instead of deleted.');
            $pdo->prepare("UPDATE pos_profiles SET status = 'inactive' WHERE id = ? AND id NOT IN (SELECT pos_profile_id FROM pos_shifts WHERE status = 'open' AND pos_profile_id IS NOT NULL)")->execute([$id]);
        } else {
            $pdo->prepare('UPDATE pos_held_orders SET pos_profile_id = NULL WHERE pos_profile_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM pos_profiles WHERE id = ?')->execute([$id]);
            flash('success', 'Terminal deleted.');
        }
        redirect('/pos/settings.php?s=profiles');
    }
}

$val = fn(string $k) => is_post() && input('action') === 'save' && array_key_exists($k, $_POST) ? (string)$_POST[$k] : pos_setting($k);
$on = fn(string $k) => is_post() && input('action') === 'save' ? input($k) === '1' : pos_flag($k);
$stores = leaf_warehouses();
$priceLists = $pdo->query("SELECT pl.*, (SELECT COUNT(*) FROM price_list_items WHERE price_list_id = pl.id) items FROM price_lists pl WHERE pl.status = 'active' ORDER BY pl.name")->fetchAll();
$allProfiles = pos_profiles(false);

$sidebar_subnav = [
    'back_url' => 'pos/index.php', 'back_label' => 'Back to POS', 'icon' => 'fa-solid fa-sliders', 'label' => 'POS Settings',
    'items' => array_map(fn($k) => ['url' => 'pos/settings.php?s=' . $k, 'icon' => $sections[$k][1], 'label' => $sections[$k][0], 'active' => $k === $s], array_keys($sections)),
];

/** A labelled on/off switch row. */
function pos_switch(string $key, string $label, bool $checked, string $help = ''): string
{
    return '<div class="pos-toggle"><label for="' . e($key) . '">' . e($label) . ($help !== '' ? '<div class="small text-muted">' . e($help) . '</div>' : '') . '</label>'
        . '<div class="form-check form-switch m-0"><input type="hidden" name="' . e($key) . '" value="0"><input class="form-check-input" type="checkbox" role="switch" id="' . e($key) . '" name="' . e($key) . '" value="1"' . ($checked ? ' checked' : '') . '></div></div>';
}

$page_title = 'POS Settings';
require __DIR__ . '/../includes/header.php';
?>
<div class="pos-card">
  <div class="pos-card-body border-bottom"><h1 class="pos-title mb-0"><?= e($sections[$s][0]) ?> Settings</h1></div>
  <?php foreach ($errors as $err): ?><div class="alert alert-danger m-3 mb-0"><?= e($err) ?></div><?php endforeach; ?>

  <?php if ($s === 'profiles'):
      $edit = null;
      foreach ($allProfiles as $p) {
          if ((int)$p['id'] === (int)input('edit')) {
              $edit = $p;
          }
      }
      if (is_post() && input('action') === 'save_profile') {
          $edit = ['id' => (int)input('id'), 'name' => input('name'), 'warehouse_id' => input('warehouse_id'), 'price_list_id' => input('price_list_id'), 'status' => input('status')];
      } ?>
    <div class="pos-settings-grid">
      <div class="pos-card-body">
        <h2 class="pos-section-title">Terminals</h2>
        <div class="table-responsive">
          <table class="table pos-table mb-0">
            <thead><tr><th>Terminal</th><th>Store</th><th>Price List</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($allProfiles as $p): ?>
              <tr>
                <td class="fw-semibold"><?= e($p['name']) ?><?= (int)$p['id'] === (int)pos_setting('pos_default_profile_id') ? ' <span class="pos-pill blue">Default</span>' : '' ?></td>
                <td><?= e($p['warehouse_name'] ?? 'Any store') ?></td>
                <td><?= e($p['price_list_name'] ?? 'Default') ?></td>
                <td><span class="pos-pill <?= $p['status'] === 'active' ? 'green' : 'gray' ?>"><?= e(ucfirst($p['status'])) ?></span></td>
                <td class="text-end text-nowrap">
                  <a class="pos-act me-2" href="?s=profiles&edit=<?= (int)$p['id'] ?>">Edit</a>
                  <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="delete_profile"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                    <button class="pos-act border-0 bg-transparent text-danger" data-confirm="Delete terminal <?= e($p['name']) ?>?">Delete</button></form>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$allProfiles): ?><tr><td colspan="5" class="text-center text-muted py-4">No terminals yet. Add one to start selling.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
      <div class="pos-card-body">
        <h2 class="pos-section-title"><?= $edit && !empty($edit['id']) ? 'Edit ' . e($edit['name']) : 'Add Terminal' ?></h2>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save_profile"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
          <div class="mb-3"><label class="form-label" for="ppName">Name <span class="text-danger">*</span></label><input class="form-control" id="ppName" name="name" maxlength="60" required value="<?= e($edit['name'] ?? '') ?>" placeholder="POS-02"></div>
          <div class="mb-3"><label class="form-label" for="ppStore">Store (warehouse)</label>
            <select class="form-select" id="ppStore" name="warehouse_id"><option value="">Any store</option>
              <?php foreach ($stores as $w): ?><option value="<?= (int)$w['id'] ?>" <?= (int)($edit['warehouse_id'] ?? 0) === (int)$w['id'] ? 'selected' : '' ?>><?= e($w['name']) ?></option><?php endforeach; ?>
            </select></div>
          <div class="mb-3"><label class="form-label" for="ppPl">Price List</label>
            <select class="form-select" id="ppPl" name="price_list_id"><option value="">Use the POS default</option>
              <?php foreach ($priceLists as $pl): ?><option value="<?= (int)$pl['id'] ?>" <?= (int)($edit['price_list_id'] ?? 0) === (int)$pl['id'] ? 'selected' : '' ?>><?= e($pl['name']) ?></option><?php endforeach; ?>
            </select></div>
          <div class="mb-3"><label class="form-label" for="ppStatus">Status</label>
            <select class="form-select" id="ppStatus" name="status"><option value="active">Active</option><option value="inactive" <?= ($edit['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option></select></div>
          <div class="d-flex gap-2"><button class="btn btn-brand">Save Terminal</button><?php if ($edit): ?><a class="btn btn-light border" href="?s=profiles">Cancel</a><?php endif; ?></div>
        </form>
      </div>
    </div>

  <?php else: ?>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save">
    <?php if ($s === 'general'): ?>
      <div class="pos-settings-grid">
        <div class="pos-card-body">
          <div class="mb-3"><label class="form-label" for="gWh">Default Warehouse</label>
            <select class="form-select" id="gWh" name="pos_default_warehouse_id"><option value="">First store</option>
              <?php foreach ($stores as $w): ?><option value="<?= (int)$w['id'] ?>" <?= $val('pos_default_warehouse_id') === (string)$w['id'] ? 'selected' : '' ?>><?= e($w['name']) ?></option><?php endforeach; ?>
            </select></div>
          <div class="mb-3"><label class="form-label" for="gProf">Default POS Profile</label>
            <select class="form-select" id="gProf" name="pos_default_profile_id"><option value="">First terminal</option>
              <?php foreach ($allProfiles as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $val('pos_default_profile_id') === (string)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
            </select></div>
          <div class="mb-3"><label class="form-label" for="gPl">Default Price List</label>
            <select class="form-select" id="gPl" name="pos_default_price_list_id"><option value="">Item selling price</option>
              <?php foreach ($priceLists as $pl): ?><option value="<?= (int)$pl['id'] ?>" <?= $val('pos_default_price_list_id') === (string)$pl['id'] ? 'selected' : '' ?>><?= e($pl['name']) ?></option><?php endforeach; ?>
            </select></div>
          <div class="mb-3"><label class="form-label" for="gCust">Default Customer</label>
            <select class="form-select" id="gCust" name="pos_default_customer_id"><option value="">Walk-in Customer</option>
              <?php foreach (pos_customers() as $c): if ($c['name'] === 'Walk-in Customer') continue; ?><option value="<?= (int)$c['id'] ?>" <?= $val('pos_default_customer_id') === (string)$c['id'] ? 'selected' : '' ?>><?= e($c['name'] . ($c['mobile'] ? ' · ' . $c['mobile'] : '')) ?></option><?php endforeach; ?>
            </select></div>
          <div class="mb-1"><label class="form-label" for="gMax">Maximum discount for cashiers (%)</label>
            <input type="number" class="form-control" id="gMax" name="pos_max_discount_percent" min="0" max="100" step="0.01" value="<?= e($val('pos_max_discount_percent')) ?>">
            <div class="form-text">0 means no limit. POS managers are never limited.</div></div>
        </div>
        <div class="pos-card-body">
          <?= pos_switch('pos_allow_discount', 'Allow Discount', $on('pos_allow_discount')) ?>
          <?= pos_switch('pos_allow_price_override', 'Allow Price Override', $on('pos_allow_price_override')) ?>
          <?= pos_switch('pos_print_receipt_auto', 'Print Receipt Automatically', $on('pos_print_receipt_auto')) ?>
          <?= pos_switch('pos_show_product_images', 'Show Product Images', $on('pos_show_product_images')) ?>
          <?= pos_switch('pos_enable_barcode_scanner', 'Enable Barcode Scanner', $on('pos_enable_barcode_scanner')) ?>
          <?= pos_switch('pos_require_shift', 'Require an open shift to sell', $on('pos_require_shift')) ?>
          <?= pos_switch('pos_allow_credit_sale', 'Allow part payment (balance on account)', $on('pos_allow_credit_sale'), 'Named customers only, within their credit limit.') ?>
        </div>
      </div>

    <?php elseif ($s === 'price_list'): ?>
      <div class="pos-card-body">
        <p class="text-muted">POS sells at the terminal's price list, else this default, else each item's selling price. Prices are edited in Sales &gt; Price Lists.</p>
        <div class="mb-3" style="max-width:420px"><label class="form-label" for="plDef">Default Price List</label>
          <select class="form-select" id="plDef" name="pos_default_price_list_id"><option value="">Item selling price</option>
            <?php foreach ($priceLists as $pl): ?><option value="<?= (int)$pl['id'] ?>" <?= $val('pos_default_price_list_id') === (string)$pl['id'] ? 'selected' : '' ?>><?= e($pl['name']) ?></option><?php endforeach; ?>
          </select></div>
        <table class="table pos-table" style="max-width:640px">
          <thead><tr><th>Price List</th><th>Currency</th><th class="text-end">Items priced</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($priceLists as $pl): ?>
            <tr><td><?= e($pl['name']) ?><?= $pl['is_default'] ? ' <span class="pos-pill gray">Company default</span>' : '' ?></td><td><?= e($pl['currency']) ?></td><td class="text-end"><?= (int)$pl['items'] ?></td>
              <td class="text-end"><a class="pos-act" href="<?= base_url('sales/price_list_form.php?id=' . (int)$pl['id']) ?>">Edit prices</a></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

    <?php elseif ($s === 'taxes'): ?>
      <div class="pos-card-body" style="max-width:640px">
        <div class="mb-3"><label class="form-label" for="tLabel">Tax label on bills</label><input class="form-control" id="tLabel" name="pos_tax_label" maxlength="20" value="<?= e($val('pos_tax_label')) ?>"></div>
        <div class="mb-3"><label class="form-label" for="tRate">Default tax rate (%)</label><input type="number" class="form-control" id="tRate" name="pos_default_tax_rate" min="0" max="100" step="0.01" value="<?= e($val('pos_default_tax_rate')) ?>">
          <div class="form-text">Used for items with no tax set on the item or its tax template.</div></div>
        <div class="mb-3"><label class="form-label" for="tSlabs">Tax slabs a cashier can pick (%)</label><input class="form-control" id="tSlabs" name="pos_tax_slabs" value="<?= e($val('pos_tax_slabs')) ?>" placeholder="0,5,12,18,28"></div>
        <?= pos_switch('pos_round_off', 'Round the grand total to the nearest rupee', $on('pos_round_off')) ?>
        <div class="small text-muted mt-2">Intra-state bills print the tax as CGST + SGST (half each).</div>
      </div>

    <?php elseif ($s === 'payment_modes'): ?>
      <div class="pos-card-body" style="max-width:640px">
        <div class="pos-toggle"><label>Cash<div class="small text-muted">Always available.</div></label><div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" checked disabled aria-label="Cash"></div></div>
        <?= pos_switch('pos_pay_card', 'Card', $on('pos_pay_card')) ?>
        <?= pos_switch('pos_pay_upi', 'UPI', $on('pos_pay_upi'), function_exists('cashfree_configured') && cashfree_configured() ? 'Cashfree QR is also offered at checkout.' : '') ?>
        <?= pos_switch('pos_pay_wallet', 'Wallet', $on('pos_pay_wallet')) ?>
        <?= pos_switch('pos_pay_split', 'Split payment', $on('pos_pay_split'), 'Take one bill across several methods.') ?>
        <div class="mt-3"><label class="form-label" for="pmQuick">Quick cash buttons</label><input class="form-control" id="pmQuick" name="pos_quick_cash" value="<?= e($val('pos_quick_cash')) ?>" placeholder="100,500,1000,2000"></div>
      </div>

    <?php elseif ($s === 'receipt'): ?>
      <div class="pos-settings-grid">
        <div class="pos-card-body">
          <div class="mb-3"><label class="form-label" for="rWidth">Paper width</label>
            <select class="form-select" id="rWidth" name="pos_receipt_width"><option value="80">80 mm</option><option value="58" <?= $val('pos_receipt_width') === '58' ? 'selected' : '' ?>>58 mm</option></select></div>
          <div class="mb-3"><label class="form-label" for="rHead">Store name on receipt</label><input class="form-control" id="rHead" name="pos_receipt_header" maxlength="120" value="<?= e($val('pos_receipt_header')) ?>" placeholder="<?= e(setting('company_name', 'Company name')) ?>"></div>
          <div class="mb-3"><label class="form-label" for="rAddr">Address</label><textarea class="form-control" id="rAddr" name="pos_receipt_address" rows="2" maxlength="255"><?= e($val('pos_receipt_address')) ?></textarea></div>
          <div class="row g-3 mb-3">
            <div class="col-sm-6"><label class="form-label" for="rPhone">Phone</label><input class="form-control" id="rPhone" name="pos_receipt_phone" maxlength="40" value="<?= e($val('pos_receipt_phone')) ?>"></div>
            <div class="col-sm-6"><label class="form-label" for="rGst">GSTIN</label><input class="form-control text-uppercase" id="rGst" name="pos_receipt_gstin" maxlength="15" value="<?= e($val('pos_receipt_gstin')) ?>"></div>
          </div>
          <div class="mb-3"><label class="form-label" for="rFoot">Footer message</label><input class="form-control" id="rFoot" name="pos_receipt_footer" maxlength="200" value="<?= e($val('pos_receipt_footer')) ?>"></div>
          <?= pos_switch('pos_receipt_show_customer', 'Show customer details', $on('pos_receipt_show_customer')) ?>
          <?= pos_switch('pos_receipt_show_tax_breakup', 'Show CGST / SGST breakup', $on('pos_receipt_show_tax_breakup')) ?>
        </div>
        <div class="pos-card-body">
          <?php $lastId = (int)$pdo->query("SELECT MAX(id) FROM sales_orders WHERE channel = 'pos'")->fetchColumn(); ?>
          <h2 class="pos-section-title">Preview</h2>
          <?php if ($lastId): ?>
            <iframe src="<?= base_url('pos/receipt.php?id=' . $lastId . '&preview=1') ?>" title="Receipt preview" style="width:100%;height:520px;border:1px solid var(--card-border);border-radius:10px;background:#fff"></iframe>
            <div class="small text-muted mt-1">Your latest sale. Save to refresh the preview.</div>
          <?php else: ?><p class="text-muted">Make a sale to see a preview here.</p><?php endif; ?>
        </div>
      </div>

    <?php elseif ($s === 'barcode'): ?>
      <div class="pos-card-body" style="max-width:640px">
        <?= pos_switch('pos_enable_barcode_scanner', 'Enable Barcode Scanner', $on('pos_enable_barcode_scanner'), 'USB / Bluetooth scanners that type the code and press Enter.') ?>
        <?= pos_switch('pos_barcode_match_sku', 'Also match item SKU', $on('pos_barcode_match_sku')) ?>
        <?= pos_switch('pos_barcode_auto_add', 'Add to cart as soon as a code matches', $on('pos_barcode_auto_add')) ?>
        <?= pos_switch('pos_barcode_camera', 'Allow camera scanning', $on('pos_barcode_camera'), 'Uses the device camera where the browser supports it.') ?>
        <?php $codes = (int)$pdo->query('SELECT COUNT(*) FROM product_barcodes')->fetchColumn(); ?>
        <div class="small text-muted mt-3"><?= number_format($codes) ?> barcodes are set up on items. Add them on each item in Inventory.</div>
      </div>

    <?php elseif ($s === 'permissions'): ?>
      <div class="pos-card-body">
        <p class="text-muted">Choose who can do each action. "Cashiers" are users with Edit access to POS; "Managers only" means Manage access or admin.</p>
        <table class="table pos-table" style="max-width:760px">
          <thead><tr><th>Action</th><th style="width:220px">Allowed for</th></tr></thead>
          <tbody>
          <?php foreach ($permissions as $k => [$label, $help]): ?>
            <tr><td><div class="fw-semibold"><?= e($label) ?></div><div class="small text-muted"><?= e($help) ?></div></td>
              <td><select class="form-select" name="<?= e($k) ?>" aria-label="<?= e($label) ?>"><option value="edit">Cashiers</option><option value="manage" <?= $val($k) === 'manage' ? 'selected' : '' ?>>Managers only</option></select></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <div class="small text-muted">Give users POS access in Admin &gt; Users.</div>
      </div>
    <?php endif; ?>
    <div class="pos-card-body border-top d-flex justify-content-end"><button class="btn btn-brand btn-lg-pos">Save Settings</button></div>
  </form>
  <?php endif; ?>
</div>
<?php
$extra_js = [asset_url('assets/js/pos.js')];
require __DIR__ . '/../includes/footer.php';
