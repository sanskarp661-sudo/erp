<?php
require_once __DIR__ . '/../includes/auth.php';
$ctx = pos_page(true);
$pdo = db();

if (!is_post()) {
    redirect('/pos/index.php');
}
csrf_verify();

$payload = pos_payload_from_request();
$error = '';

if (input('action') === 'complete') {
    $payments = json_decode((string)input('payments'), true);
    $payments = is_array($payments) ? $payments : [];
    if (pos_flag('pos_require_shift') && !$ctx['shift']) {
        $error = 'Open a shift on ' . ($ctx['profile']['name'] ?? 'this terminal') . ' before taking payments.';
    } else {
        $pdo->beginTransaction();
        try {
            $result = pos_complete_sale($pdo, $payload, $payments, $ctx, current_user()['id']);
            $pdo->commit();
            redirect('/pos/success.php?id=' . $result['order_id'] . (input('print_receipt') === '1' ? '&print=1' : ''));
        } catch (RuntimeException $e) {
            $pdo->rollBack();
            $error = $e->getMessage();
        } catch (Throwable $e) {
            $pdo->rollBack();
            $error = 'Could not complete the sale.' . (APP_DEBUG && can_manage_module('pos') ? ' ' . $e->getMessage() : '');
        }
    }
}

try {
    $cart = pos_price_cart($payload, $ctx);
} catch (RuntimeException $e) {
    flash('danger', $e->getMessage());
    redirect('/pos/index.php');
}

$walkInId = pos_walk_in_customer_id();
$payload['customer_id'] = $payload['customer_id'] ?: $walkInId;
$customers = pos_customers();
$credit = $payload['exchange_return_id'] ? pos_exchange_credit($payload['exchange_return_id']) : null;
if ($payload['exchange_return_id'] && !$credit) {
    $payload['exchange_return_id'] = null;
}
$creditUsed = $credit ? min((float)$credit['exchange_amount'], $cart['grand_total']) : 0.0;
$due = round($cart['grand_total'] - $creditUsed, 2);
$modes = pos_payment_modes();
$rates = array_keys($cart['tax_breakup']);
$taxLabel = pos_setting('pos_tax_label') . (count($rates) === 1 ? ' (' . $rates[0] . '%)' : '');
$cashfree = cashfree_configured() && in_array('upi', $modes, true) && !$credit;

$cfg = [
    'due'               => $due,
    'modes'             => $modes,
    'currency'          => setting('currency_symbol', '$'),
    'inr'               => setting('currency_code', 'INR') === 'INR',
    'walkInId'          => $walkInId,
    'customers'         => array_map(fn($c) => ['id' => (int)$c['id'], 'name' => $c['name'], 'mobile' => $c['mobile'] ?: $c['phone'], 'email' => $c['email'],
        'type' => $c['customer_type'], 'group' => $c['customer_group'], 'gstin' => $c['gstin'], 'credit' => $c['credit_limit'], 'address' => $c['address']], $customers),
    'apiUrl'            => base_url('pos/api.php'),
    'csrf'              => csrf_token(),
    'storageKey'        => 'pos_cart_' . ($ctx['profile_id'] ?? 0),
    'cashfree'          => $cashfree,
    'cashfreeCreateUrl' => base_url('pos/cashfree_create.php'),
    'cashfreeStatusUrl' => base_url('pos/cashfree_status.php'),
];
$quick = array_filter(array_map('floatval', explode(',', pos_setting('pos_quick_cash'))), fn($v) => $v > 0);
$icons = ['cash' => 'fa-solid fa-money-bill-wave', 'card' => 'fa-regular fa-credit-card', 'upi' => 'fa-solid fa-mobile-screen-button', 'wallet' => 'fa-solid fa-wallet', 'split' => 'fa-solid fa-table-cells-large'];

$page_title = 'Checkout';
require __DIR__ . '/../includes/header.php';
?>
<div class="pos-head"><h1 class="pos-title">Checkout</h1>
  <span class="text-muted small"><?= e($ctx['store_name']) ?> · <?= e($ctx['profile']['name'] ?? '') ?><?= $ctx['shift'] ? ' · ' . e($ctx['shift']['shift_no']) : '' ?></span></div>
<?php if ($error): ?><div class="alert alert-danger"><i class="fa-solid fa-circle-exclamation"></i> <?= e($error) ?></div><?php endif; ?>
<?php if (pos_flag('pos_require_shift') && !$ctx['shift']): ?>
  <div class="pos-banner warn"><i class="fa-solid fa-clock"></i> No shift is open on this terminal. <a href="<?= base_url('pos/shift.php') ?>">Open a shift</a> to take payment.</div>
<?php endif; ?>

<form method="post" id="coForm" class="pos-checkout">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="complete">
  <input type="hidden" name="cart" id="coCart" value="<?= e(json_encode($payload)) ?>">
  <input type="hidden" name="payments" id="coPayments">

  <div class="pos-card">
    <div class="pos-panel-head">Customer Details</div>
    <div class="pos-customer-chip">
      <span class="av" id="coCustAv">?</span>
      <div class="flex-grow-1"><div class="fw-semibold" id="coCustName"></div><div class="small text-muted" id="coCustMeta"></div></div>
    </div>
    <div class="px-3 py-3">
      <label class="form-label small text-muted" for="coCustomer">Change customer</label>
      <select class="form-select" id="coCustomer">
        <?php foreach ($customers as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= (int)$c['id'] === $payload['customer_id'] ? 'selected' : '' ?>><?= e($c['name']) ?><?= ($c['mobile'] ?: $c['phone']) ? ' · ' . e($c['mobile'] ?: $c['phone']) : '' ?></option>
        <?php endforeach; ?>
      </select>
      <div class="d-flex justify-content-between mt-3">
        <button type="button" class="pos-link" id="coNewCustomer"><i class="fa-solid fa-plus"></i> New Customer</button>
        <button type="button" class="pos-link" id="coEditCustomer"><i class="fa-solid fa-pen-to-square"></i> Edit</button>
      </div>
    </div>
    <div class="px-3 pb-3">
      <div class="small text-muted mb-1"><?= (int)$cart['items_count'] ?> item<?= $cart['items_count'] == 1 ? '' : 's' ?></div>
      <?php foreach ($cart['lines'] as $l): ?>
        <div class="d-flex justify-content-between small py-1 border-bottom"><span class="text-truncate me-2"><?= (int)$l['qty'] ?> × <?= e($l['name']) ?></span><span class="text-nowrap"><?= e(pos_money($l['gross'] - $l['discount'])) ?></span></div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="pos-card">
    <div class="pos-panel-head">Bill Summary</div>
    <div class="pos-kv"><span>Subtotal</span><strong><?= e(pos_money($cart['subtotal'])) ?></strong></div>
    <div class="pos-kv"><span>Item Discount</span><span>- <?= e(pos_money($cart['item_discount'])) ?></span></div>
    <div class="pos-kv"><span>Additional Discount</span><span>- <?= e(pos_money($cart['additional_discount'])) ?></span></div>
    <div class="pos-kv"><span><?= e($taxLabel) ?></span><strong><?= e(pos_money($cart['tax'])) ?></strong></div>
    <div class="pos-kv"><span>Round Off</span><span><?= $cart['round_off'] < 0 ? '- ' : '' ?><?= e(pos_money(abs($cart['round_off']))) ?></span></div>
    <div class="pos-kv grand"><span>Grand Total</span><strong><?= e(pos_money($cart['grand_total'])) ?></strong></div>
    <?php if ($credit): ?>
      <div class="pos-kv"><span class="text-success"><i class="fa-solid fa-right-left"></i> Exchange Credit (<?= e($credit['return_no']) ?>)</span><span class="text-success">- <?= e(pos_money($creditUsed)) ?></span></div>
      <div class="pos-kv"><span class="fw-semibold">Amount Due</span><strong><?= e(pos_money($due)) ?></strong></div>
      <?php if ((float)$credit['exchange_amount'] > $cart['grand_total']): ?><div class="px-3 py-2 small text-muted">The remaining <?= e(pos_money((float)$credit['exchange_amount'] - $cart['grand_total'])) ?> of credit is refunded in cash.</div><?php endif; ?>
    <?php endif; ?>
    <?php if (count($cart['tax_breakup']) > 1): ?>
      <div class="px-3 py-2 small text-muted">
        <?php foreach ($cart['tax_breakup'] as $rate => $b): ?><div class="d-flex justify-content-between"><span><?= e(pos_setting('pos_tax_label') . ' ' . $rate) ?>% on <?= e(pos_money($b['taxable'])) ?></span><span><?= e(pos_money($b['tax'])) ?></span></div><?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="pos-card">
    <div class="pos-card-body">
      <div class="fw-bold mb-3" style="color:#0f1b3d">Payment Method</div>
      <div class="pos-methods mb-3">
        <?php foreach ($modes as $m): ?>
          <button type="button" class="pos-method m-<?= e($m) ?>" data-method="<?= e($m) ?>"><i class="<?= e($icons[$m]) ?>"></i><?= e(pos_method_label($m)) ?></button>
        <?php endforeach; ?>
        <?php if (pos_flag('pos_pay_split') && count($modes) > 1): ?>
          <button type="button" class="pos-method" data-method="split"><i class="<?= e($icons['split']) ?>"></i>Split</button>
        <?php endif; ?>
      </div>

      <div id="coSingle">
        <label class="form-label text-muted" for="coReceived">Received Amount (<?= e(setting('currency_symbol', '$')) ?>)</label>
        <input type="number" step="0.01" min="0" class="form-control form-control-lg mb-3" id="coReceived" value="<?= e(number_format($due, 2, '.', '')) ?>">
        <div id="coRefWrap" class="mb-3" hidden>
          <label class="form-label text-muted" for="coRef" id="coRefLabel">Reference</label>
          <input type="text" class="form-control" id="coRef" maxlength="120" placeholder="Optional">
        </div>
      </div>
      <div id="coSplit" hidden>
        <div id="coSplitRows"></div>
        <button type="button" class="pos-link mb-3" id="coAddSplit"><i class="fa-solid fa-plus"></i> Add payment</button>
        <div class="small text-muted mb-2" id="coSplitInfo"></div>
      </div>

      <label class="form-label" style="color:#c2410c" for="coChange" id="coChangeLabel">Change (<?= e(setting('currency_symbol', '$')) ?>)</label>
      <input type="text" class="form-control form-control-lg bg-light mb-3" id="coChange" readonly value="0.00">

      <div id="coCashOnly">
        <div class="pos-quick mb-2">
          <?php foreach ($quick as $q): ?><button type="button" data-amt="<?= e($q) ?>"><?= e(pos_money($q, 0)) ?></button><?php endforeach; ?>
        </div>
        <button type="button" class="pos-link mb-3" id="coExact">Exact amount</button>
      </div>

      <?php if ($cashfree): ?>
        <div id="coCfWrap" class="border rounded-3 p-3 mb-3" hidden>
          <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="coCashfree"><label class="form-check-label" for="coCashfree">Collect through Cashfree (QR / payment request)</label></div>
          <input type="tel" class="form-control form-control-sm" id="coCfPhone" maxlength="10" placeholder="Customer's 10-digit mobile">
          <div id="coCfPanel" class="text-center mt-3" hidden><div id="coCfQr" class="d-flex justify-content-center mb-2"></div><div class="small text-muted"><span class="spinner-border spinner-border-sm"></span> Waiting for payment confirmation...</div></div>
        </div>
      <?php endif; ?>

      <div class="form-check mb-3">
        <input class="form-check-input" type="checkbox" name="print_receipt" value="1" id="coPrint" <?= pos_flag('pos_print_receipt_auto') ? 'checked' : '' ?>>
        <label class="form-check-label text-primary" for="coPrint">Print receipt after payment</label>
      </div>
      <div class="text-danger small mb-2" id="coError"></div>
      <div class="d-flex gap-2">
        <a href="<?= base_url('pos/index.php') ?>" class="btn btn-light border btn-lg" style="min-width:130px">Back</a>
        <button type="submit" class="btn btn-brand btn-lg flex-grow-1 fw-semibold" id="coSubmit">Complete Payment <i class="fa-solid fa-arrow-right"></i></button>
      </div>
    </div>
  </div>
</form>

<?php require __DIR__ . '/_customer_modal.php'; ?>
<?php
$extra_js = [asset_url('assets/js/pos.js')];
if ($cashfree) {
    $extra_js[] = cashfree_product() === 'payment_link' ? 'https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js' : 'https://sdk.cashfree.com/js/v3/cashfree.js';
}
$extra_js_inline = 'PosCheckout.init(' . json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ');';
require __DIR__ . '/../includes/footer.php';
