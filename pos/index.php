<?php
require_once __DIR__ . '/../includes/auth.php';
$ctx = pos_page(true);
$pdo = db();

$products = pos_products($ctx['warehouse_id'], $ctx['price_list_id']);
$barcodes = pos_barcodes();
$walkInId = pos_walk_in_customer_id();
$customers = pos_customers();

// Something to load into the cart instead of the browser's saved cart:
// a held order being resumed, a customer picked from the Customers page,
// or the credit from a return processed as an exchange.
$preload = null;
$notice = '';
if ($resumeId = (int)input('resume')) {
    $stmt = $pdo->prepare('SELECT * FROM pos_held_orders WHERE id = ?');
    $stmt->execute([$resumeId]);
    if ($held = $stmt->fetch()) {
        $preload = pos_normalize_payload(json_decode($held['cart_json'], true) ?: []);
        $preload['held_id'] = (int)$held['id'];
        $preload['customer_id'] = $preload['customer_id'] ?: (int)$held['customer_id'];
        $notice = 'Resumed held order ' . $held['hold_no'] . '. It leaves the held list once this sale is paid.';
    }
}
if ($exchangeId = (int)input('exchange')) {
    if ($credit = pos_exchange_credit($exchangeId)) {
        $preload = ['lines' => [], 'discount' => 0, 'held_id' => null, 'customer_id' => (int)$credit['customer_id'], 'exchange_return_id' => (int)$credit['id']];
        $notice = 'Exchange credit of ' . pos_money($credit['exchange_amount']) . ' from return ' . $credit['return_no'] . ' will be applied at checkout. Add the replacement items.';
    } else {
        flash('warning', 'That exchange credit has already been used or refunded.');
    }
}
if ($custId = (int)input('customer')) {
    $preload = $preload ?? ['lines' => [], 'discount' => 0, 'held_id' => null, 'exchange_return_id' => null, 'keep_lines' => true];
    $preload['customer_id'] = $custId;
}

$exchangeCredits = [];
foreach ($pdo->query("SELECT sr.id, sr.return_no, sr.exchange_amount, c.name FROM sales_returns sr JOIN customers c ON c.id = sr.customer_id WHERE sr.exchange_status = 'open' ORDER BY sr.id DESC") as $r) {
    $exchangeCredits[(int)$r['id']] = ['return_no' => $r['return_no'], 'amount' => (float)$r['exchange_amount'], 'customer' => $r['name']];
}

$categories = [];
$brands = [];
foreach ($products as $p) {
    if ($p['category_id']) {
        $categories[(int)$p['category_id']] = $p['category_name'];
    }
    if ($p['brand_id']) {
        $brands[(int)$p['brand_id']] = $p['brand_name'];
    }
}
asort($categories);
asort($brands);

$jsProducts = [];
foreach ($products as $p) {
    $jsProducts[] = [
        'id' => (int)$p['id'], 'sku' => $p['sku'], 'name' => $p['name'],
        'image' => $p['image'] ? base_url($p['image']) : '',
        'cat' => (int)$p['category_id'], 'brand' => (int)$p['brand_id'],
        'price' => (float)$p['rate'], 'tax' => (float)$p['tax_rate'], 'incl' => (int)$p['price_includes_tax'],
        'stock' => (int)$p['stock'], 'stockItem' => (int)$p['is_stock_item'],
        'disc' => (int)($p['allow_discount'] && !$p['not_discountable']), 'maxDisc' => (float)$p['max_discount_percent'],
        'editable' => (int)$p['is_price_editable_in_transactions'], 'minPrice' => (float)$p['minimum_selling_price'],
        'codes' => $barcodes[(int)$p['id']] ?? [],
    ];
}

$needsShift = pos_flag('pos_require_shift') && !$ctx['shift'];
$cfg = [
    'products'      => $jsProducts,
    'categories'    => array_map(fn($id, $n) => ['id' => $id, 'name' => $n], array_keys($categories), array_values($categories)),
    'brands'        => array_map(fn($id, $n) => ['id' => $id, 'name' => $n], array_keys($brands), array_values($brands)),
    'customers'     => array_map(fn($c) => ['id' => (int)$c['id'], 'name' => $c['name'], 'mobile' => $c['mobile'] ?: $c['phone'], 'email' => $c['email'],
        'type' => $c['customer_type'], 'group' => $c['customer_group'], 'gstin' => $c['gstin'], 'credit' => $c['credit_limit'], 'address' => $c['address']], $customers),
    'walkInId'      => $walkInId,
    'exchangeCredits' => $exchangeCredits,
    'storageKey'    => 'pos_cart_' . ($ctx['profile_id'] ?? 0),
    'preload'       => $preload,
    'currency'      => setting('currency_symbol', '$'),
    'inr'           => setting('currency_code', 'INR') === 'INR',
    'taxLabel'      => pos_setting('pos_tax_label'),
    'taxSlabs'      => pos_tax_slabs(),
    'roundOff'      => pos_flag('pos_round_off'),
    'allowDiscount' => pos_flag('pos_allow_discount') && pos_can('discount'),
    'allowOverride' => pos_flag('pos_allow_price_override') && pos_can('price_override'),
    'cashierCap'    => can_manage_module('pos') ? 0 : (float)pos_setting('pos_max_discount_percent'),
    'showImages'    => pos_flag('pos_show_product_images'),
    'scanner'       => pos_flag('pos_enable_barcode_scanner'),
    'matchSku'      => pos_flag('pos_barcode_match_sku'),
    'autoAdd'       => pos_flag('pos_barcode_auto_add'),
    'camera'        => pos_flag('pos_barcode_camera'),
    'needsShift'    => $needsShift,
    'apiUrl'        => base_url('pos/api.php'),
    'checkoutUrl'   => base_url('pos/checkout.php'),
    'csrf'          => csrf_token(),
];

$page_title = 'New Sale';
require __DIR__ . '/../includes/header.php';
?>
<?php if ($notice): ?><div class="pos-banner info"><i class="fa-solid fa-circle-info"></i> <?= e($notice) ?></div><?php endif; ?>
<?php if (!$ctx['warehouse_id']): ?>
  <div class="pos-banner warn"><i class="fa-solid fa-triangle-exclamation"></i> No store is set up yet. Add a warehouse under Supply Chain &gt; Warehouses to start selling.</div>
<?php elseif (!$ctx['profile']): ?>
  <div class="pos-banner warn"><i class="fa-solid fa-triangle-exclamation"></i> No POS terminal is active. <?= can_manage_module('pos') ? '<a href="' . base_url('pos/settings.php?s=profiles') . '">Add one in POS Settings</a>.' : 'Ask a POS manager to add one.' ?></div>
<?php elseif ($needsShift): ?>
  <form method="post" action="<?= base_url('pos/shift.php') ?>" class="pos-banner warn flex-wrap">
    <?= csrf_field() ?><input type="hidden" name="action" value="open"><input type="hidden" name="back" value="index">
    <i class="fa-solid fa-clock"></i>
    <span class="me-auto">Open a shift on <strong><?= e($ctx['profile']['name']) ?></strong> to start taking payments.</span>
    <div class="input-group input-group-sm" style="width:220px">
      <span class="input-group-text">Opening cash <?= e(setting('currency_symbol', '$')) ?></span>
      <input type="number" step="0.01" min="0" name="opening_cash" class="form-control" value="0" required>
    </div>
    <button class="btn btn-sm btn-brand">Open Shift</button>
  </form>
<?php endif; ?>

<div class="pos-sale" id="posSale">
  <aside class="pos-card pos-filters" id="posFilters" hidden>
    <div class="d-flex justify-content-between align-items-center">
      <span class="fw-semibold"><i class="fa-solid fa-filter text-primary"></i> Filters</span>
      <button type="button" class="pos-link" id="posClearFilters">Clear All</button>
    </div>
    <h6>Category</h6>
    <div class="pos-filter-list" id="posFilterCats"></div>
    <h6>Brand</h6>
    <div class="pos-filter-list" id="posFilterBrands"></div>
    <h6>Price Range</h6>
    <div class="pos-range"><div class="pos-range-track"></div><div class="pos-range-fill" id="posRangeFill"></div>
      <input type="range" id="posPriceMinR" step="1"><input type="range" id="posPriceMaxR" step="1"></div>
    <div class="d-flex align-items-center gap-2 mt-2">
      <div class="input-group input-group-sm"><span class="input-group-text"><?= e(setting('currency_symbol', '$')) ?></span><input type="number" class="form-control" id="posPriceMin" min="0"></div>
      <span class="text-muted">–</span>
      <div class="input-group input-group-sm"><span class="input-group-text"><?= e(setting('currency_symbol', '$')) ?></span><input type="number" class="form-control" id="posPriceMax" min="0"></div>
    </div>
    <h6>Availability</h6>
    <div class="d-flex gap-3">
      <div class="form-check"><input class="form-check-input" type="checkbox" id="posInStock" checked><label class="form-check-label" for="posInStock">In Stock</label></div>
      <div class="form-check"><input class="form-check-input" type="checkbox" id="posOutStock"><label class="form-check-label" for="posOutStock">Out of Stock</label></div>
    </div>
  </aside>

  <section>
    <div class="pos-searchbar">
      <div class="pos-search-in">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input type="search" id="posSearch" placeholder="Search or scan barcode (SKU, name, brand...)" autocomplete="off" autofocus>
      </div>
      <button type="button" class="pos-filter-btn" id="posFilterToggle" title="Filters"><i class="fa-solid fa-sliders"></i> <span class="d-none d-sm-inline">Filters</span> <span class="badge text-bg-primary" id="posFilterCount" hidden></span></button>
      <?php if ($cfg['scanner']): ?><button type="button" class="pos-scan-btn" id="posScanBtn" title="Scan barcode"><i class="fa-solid fa-expand"></i></button><?php endif; ?>
    </div>
    <div class="pos-chips" id="posChips"></div>
    <div class="pos-grid <?= $cfg['showImages'] ? '' : 'pos-hide-images' ?>" id="posGrid"></div>
    <div class="pos-empty" id="posGridEmpty" hidden><i class="fa-solid fa-box-open"></i>No products match. Try another search or clear the filters.</div>
  </section>

  <aside class="pos-card pos-cart" id="posCart">
    <div class="pos-cart-head">
      <h2>Cart (<span id="posCartCount">0</span>)</h2>
      <button type="button" class="pos-link-danger" id="posClearTop"><i class="fa-regular fa-trash-can"></i> Clear</button>
    </div>
    <div class="pos-cart-body">
      <div class="d-flex justify-content-between align-items-center mb-1">
        <label class="form-label mb-0 text-muted" for="posCustomer">Customer</label>
        <span>
          <button type="button" class="pos-link me-2" id="posEditCustomer" hidden><i class="fa-solid fa-pen"></i> Edit</button>
          <button type="button" class="pos-link" id="posNewCustomer"><i class="fa-solid fa-plus"></i> New Customer</button>
        </span>
      </div>
      <select class="form-select" id="posCustomer"></select>
      <div class="small text-success mt-1" id="posExchangeNote" hidden></div>

      <div class="pos-lines" id="posLines"></div>
      <div class="pos-empty py-4" id="posCartEmpty"><i class="fa-solid fa-cart-shopping"></i>Cart is empty. Tap a product or scan a barcode.</div>

      <div class="pos-totals">
        <div class="row-t"><span>Subtotal</span><strong id="posSubtotal">0.00</strong></div>
        <div class="row-t" id="posItemDiscRow" hidden><span>Item Discount</span><strong id="posItemDisc">0.00</strong></div>
        <div class="row-t">
          <span>Discount</span>
          <?php if ($cfg['allowDiscount']): ?>
            <span class="pos-disc-in">
              <span class="input-group input-group-sm"><span class="input-group-text"><?= e(setting('currency_symbol', '$')) ?></span><input type="number" min="0" step="0.01" class="form-control" id="posDiscount" value="0.00"></span>
              <button type="button" class="pos-link" id="posApplyDiscount">Apply</button>
            </span>
          <?php else: ?><strong id="posDiscShown">0.00</strong><?php endif; ?>
        </div>
        <div class="row-t"><span id="posTaxLabel"><?= e($cfg['taxLabel']) ?></span><strong id="posTax">0.00</strong></div>
        <div class="row-t" id="posRoundRow" hidden><span>Round Off</span><strong id="posRound">0.00</strong></div>
        <div class="row-t grand"><span>Grand Total</span><span id="posGrand">0.00</span></div>
      </div>
      <div class="row g-2 mt-2">
        <div class="col-6"><button type="button" class="pos-btn-soft w-100" id="posHold"><i class="fa-regular fa-file-lines"></i> Hold</button></div>
        <div class="col-6"><button type="button" class="pos-btn-soft-danger w-100" id="posClear"><i class="fa-regular fa-trash-can"></i> Clear</button></div>
      </div>
      <button type="button" class="pos-pay-btn" id="posPay" disabled>Pay <span id="posPayAmt">0.00</span> <i class="fa-solid fa-arrow-right"></i></button>
      <?php if ($needsShift): ?><div class="small text-muted text-center mt-2">Open a shift above to take payment. You can still build and hold the cart.</div><?php endif; ?>
    </div>
  </aside>
</div>

<form method="post" action="<?= base_url('pos/checkout.php') ?>" id="posCheckoutForm" hidden>
  <?= csrf_field() ?><input type="hidden" name="action" value="review"><input type="hidden" name="cart" id="posCheckoutCart">
</form>

<?php require __DIR__ . '/_customer_modal.php'; ?>

<div class="modal fade pos-modal" id="posItemModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Edit Item</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <div class="d-flex align-items-center gap-3 mb-3">
          <div class="pos-line-thumb" style="width:72px;height:72px" id="piThumb"></div>
          <div><div class="fw-bold fs-5" id="piName"></div><div class="text-muted small" id="piSku"></div></div>
        </div>
        <div class="row g-3">
          <div class="col-md-4"><label class="form-label">Quantity</label>
            <div class="pos-qty lg w-100"><button type="button" id="piDec">−</button><input type="number" min="1" step="1" id="piQty" class="flex-grow-1"><button type="button" id="piInc">+</button></div>
            <div class="form-text" id="piStock"></div></div>
          <div class="col-md-4"><label class="form-label">Price (<?= e(setting('currency_symbol', '$')) ?>)</label><input type="number" min="0" step="0.01" class="form-control form-control-lg" id="piPrice"><div class="form-text" id="piPriceHint"></div></div>
          <div class="col-md-4"><label class="form-label">Discount Type</label><select class="form-select form-select-lg" id="piDiscType"><option value="percent">Percentage</option><option value="amount">Amount</option></select></div>
          <div class="col-md-4"><label class="form-label">Discount Value</label><div class="input-group input-group-lg"><input type="number" min="0" step="0.01" class="form-control" id="piDiscValue"><span class="input-group-text" id="piDiscUnit">%</span></div></div>
          <div class="col-md-4"><label class="form-label">Amount</label><input type="text" class="form-control form-control-lg bg-light" id="piDiscAmount" readonly title="Discount amount"></div>
          <div class="col-md-4"><label class="form-label">Tax</label><select class="form-select form-select-lg" id="piTax"></select></div>
        </div>
        <div class="d-flex justify-content-between align-items-center mt-4">
          <div class="fs-5 fw-bold">Total (<?= e(setting('currency_symbol', '$')) ?>) <span class="ms-2 text-primary" id="piTotal"></span></div>
          <div class="text-danger small" id="piError"></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-danger me-auto" id="piRemove"><i class="fa-regular fa-trash-can"></i> Remove</button>
        <button type="button" class="btn btn-light border btn-lg-pos" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-brand btn-lg-pos" id="piUpdate">Update</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade pos-modal" id="posScanModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Scan Barcode</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <video id="posScanVideo" playsinline muted style="width:100%;border-radius:12px;background:#111" hidden></video>
        <p class="text-muted small mt-2 mb-2" id="posScanMsg">A USB or Bluetooth barcode scanner works anywhere on this screen. Or type the code:</p>
        <div class="input-group"><input type="text" class="form-control" id="posScanCode" placeholder="Barcode or SKU"><button type="button" class="btn btn-brand" id="posScanAdd">Add</button></div>
      </div>
    </div>
  </div>
</div>

<div class="toast-container position-fixed bottom-0 end-0 p-3"><div class="toast align-items-center border-0" id="posToast" role="status"><div class="d-flex"><div class="toast-body" id="posToastBody"></div><button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast"></button></div></div></div>
<?php
$extra_js = [asset_url('assets/js/pos.js')];
$extra_js_inline = 'PosSale.init(' . json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ');';
require __DIR__ . '/../includes/footer.php';
