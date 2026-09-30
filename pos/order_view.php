<?php
require_once __DIR__ . '/../includes/auth.php';
$ctx = pos_page();
$pdo = db();

$order = pos_order((int)input('id'));
if (!$order) {
    flash('danger', 'POS order not found.');
    redirect('/pos/orders.php');
}

if (is_post() && input('action') === 'cancel') {
    require_module_edit('pos');
    csrf_verify();
    if (!pos_can('cancel')) {
        flash('danger', 'Only a POS manager can cancel a sale.');
    } else {
        try {
            $pdo->beginTransaction();
            pos_cancel_order($pdo, $order, $ctx, current_user()['id'], trim((string)input('reason')));
            $pdo->commit();
            flash('success', 'Sale ' . $order['pos_no'] . ' cancelled. Stock is back in and the payment was refunded.');
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('danger', $e->getMessage());
        }
    }
    redirect('/pos/order_view.php?id=' . (int)$order['id']);
}

$items = pos_order_items((int)$order['id']);
$payments = $order['invoice_id'] ? pos_order_payments((int)$order['invoice_id']) : [];
$returnedQty = pos_returned_qty((int)$order['id']);
$returnState = pos_return_state((int)$order['id']);
$stmt = $pdo->prepare("SELECT sr.*, u.name created_by_name FROM sales_returns sr LEFT JOIN users u ON u.id = sr.created_by WHERE sr.sales_order_id = ? ORDER BY sr.id");
$stmt->execute([$order['id']]);
$returns = $stmt->fetchAll();

$cancelled = $order['status'] === 'cancelled';
$balance = $order['invoice_id'] && !$cancelled ? round((float)$order['invoice_total'] - (float)$order['amount_paid'], 2) : 0;
[$label, $tone] = $cancelled ? ['Cancelled', 'red']
    : ($returnState === 'full' ? ['Returned', 'purple']
    : ($returnState === 'partial' ? ['Part Returned', 'purple']
    : ($balance > 0.009 ? [(float)$order['amount_paid'] > 0 ? 'Partly Paid' : 'Unpaid', 'orange'] : ['Paid', 'green'])));
$gross = 0.0;
foreach ($items as $it) {
    $gross += (float)$it['unit_price'] * (int)$it['quantity'];
}

$page_title = 'POS Order ' . ($order['pos_no'] ?: $order['order_no']);
require __DIR__ . '/../includes/header.php';
?>
<div class="pos-head mb-3">
  <div>
    <a href="<?= base_url('pos/orders.php') ?>" class="small text-decoration-none"><i class="fa-solid fa-arrow-left"></i> POS Orders</a>
    <h1 class="pos-title mt-1"><?= e($order['pos_no'] ?: $order['order_no']) ?> <span class="pos-pill <?= e($tone) ?> align-middle ms-1"><?= e($label) ?></span></h1>
    <div class="text-muted small"><?= e(pos_datetime($order['created_at'])) ?> · <?= e($order['store_name'] ?? '-') ?> · <?= e($order['terminal_name'] ?? '-') ?> · Cashier <?= e($order['cashier_name'] ?? '-') ?><?= $order['shift_no'] ? ' · Shift ' . e($order['shift_no']) : '' ?></div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <button type="button" class="pos-btn-soft" id="ovPrint"><i class="fa-solid fa-print"></i> Print Receipt</button>
    <button type="button" class="pos-btn-soft" data-bs-toggle="modal" data-bs-target="#ovEmailModal"><i class="fa-regular fa-envelope"></i> Email</button>
    <?php if (!$cancelled && $returnState !== 'full' && pos_can('returns')): ?>
      <a class="pos-btn-soft" href="<?= base_url('pos/return.php?order=' . (int)$order['id']) ?>"><i class="fa-solid fa-rotate-left"></i> Return / Exchange</a>
    <?php endif; ?>
    <?php if (!$cancelled && $returnState === 'none' && pos_can('cancel')): ?>
      <button type="button" class="pos-btn-soft pos-btn-soft-danger" data-bs-toggle="modal" data-bs-target="#ovCancelModal"><i class="fa-solid fa-ban"></i> Cancel Sale</button>
    <?php endif; ?>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-8">
    <div class="pos-card">
      <div class="pos-card-body pb-0"><h2 class="pos-section-title">Items</h2></div>
      <div class="table-responsive">
        <table class="table pos-table mb-0">
          <thead><tr><th>Item</th><th class="text-end">Qty</th><th class="text-end">Price</th><th class="text-end">Discount</th><th class="text-end">GST</th><th class="text-end">Total</th></tr></thead>
          <tbody>
          <?php foreach ($items as $it): $ret = $returnedQty[(int)$it['product_id']] ?? 0; ?>
            <tr>
              <td><div class="fw-semibold"><?= e($it['product_name']) ?></div><div class="small text-muted"><?= e($it['sku']) ?><?= $it['hsn_sac_code'] ? ' · HSN ' . e($it['hsn_sac_code']) : '' ?><?= $ret ? ' · <span class="text-danger">' . (int)$ret . ' returned</span>' : '' ?></div></td>
              <td class="text-end"><?= (int)$it['quantity'] ?></td>
              <td class="text-end"><?= e(pos_money($it['unit_price'])) ?></td>
              <td class="text-end"><?= (float)$it['discount_amount'] > 0 ? e(pos_money($it['discount_amount'])) : '-' ?></td>
              <td class="text-end"><?= e(pos_number($it['tax_rate'], 0)) ?>%<div class="small text-muted"><?= e(pos_money($it['tax_amount'])) ?></div></td>
              <td class="text-end fw-semibold"><?= e(pos_money($it['line_total'])) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="pos-card mt-3">
      <div class="pos-card-body pb-0"><h2 class="pos-section-title">Payments</h2></div>
      <div class="table-responsive">
        <table class="table pos-table mb-0">
          <thead><tr><th>Date</th><th>Method</th><th>Reference</th><th>Note</th><th class="text-end">Amount</th></tr></thead>
          <tbody>
          <?php foreach ($payments as $p): ?>
            <tr>
              <td><?= e(pos_date($p['payment_date'])) ?></td>
              <td><?= e(pos_method_label($p['method'])) ?></td>
              <td><?= e($p['reference'] ?? '') ?></td>
              <td class="small text-muted"><?= e($p['notes'] ?? '') ?></td>
              <td class="text-end <?= (float)$p['amount'] < 0 ? 'text-danger' : '' ?>"><?= e(pos_money($p['amount'])) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$payments): ?><tr><td colspan="5" class="text-center text-muted py-4">No payments recorded.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php if ($returns): ?>
      <div class="pos-card mt-3">
        <div class="pos-card-body pb-0"><h2 class="pos-section-title">Returns</h2></div>
        <div class="table-responsive">
          <table class="table pos-table mb-0">
            <thead><tr><th>Return No.</th><th>Date</th><th>Refund</th><th>Reason</th><th>By</th><th class="text-end">Amount</th></tr></thead>
            <tbody>
            <?php foreach ($returns as $r): ?>
              <tr>
                <td><?= e($r['return_no']) ?></td>
                <td><?= e(pos_date($r['return_date'])) ?></td>
                <td><?= e($r['refund_method'] === 'exchange' ? 'Exchange credit' . ($r['exchange_status'] === 'open' ? ' (open)' : ($r['exchange_status'] === 'used' ? ' (used)' : ($r['exchange_status'] === 'refunded' ? ' (refunded)' : ''))) : pos_method_label((string)$r['refund_method'])) ?></td>
                <td class="small text-muted"><?= e($r['reason'] ?? '') ?></td>
                <td><?= e($r['created_by_name'] ?? '-') ?></td>
                <td class="text-end"><?= e(pos_money($r['total_amount'])) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <div class="col-lg-4">
    <div class="pos-card">
      <div class="pos-card-body pb-2"><h2 class="pos-section-title">Customer</h2>
        <div class="fw-semibold"><?= e($order['customer_name']) ?></div>
        <div class="small text-muted"><?= e($order['customer_mobile'] ?: ($order['customer_phone'] ?? '')) ?><?= $order['customer_email'] ? ' · ' . e($order['customer_email']) : '' ?></div>
        <?php if ($order['customer_gstin']): ?><div class="small text-muted">GSTIN <?= e($order['customer_gstin']) ?></div><?php endif; ?>
      </div>
    </div>
    <div class="pos-card mt-3">
      <div class="pos-card-body pb-2"><h2 class="pos-section-title">Bill Summary</h2></div>
      <div class="pos-kv"><span>Subtotal</span><strong><?= e(pos_money($gross)) ?></strong></div>
      <div class="pos-kv"><span>Item Discount</span><strong>- <?= e(pos_money($order['item_discount'])) ?></strong></div>
      <div class="pos-kv"><span>Additional Discount</span><strong>- <?= e(pos_money($order['additional_discount'])) ?></strong></div>
      <div class="pos-kv"><span>GST</span><strong><?= e(pos_money($order['tax_amount'])) ?></strong></div>
      <?php if ((float)$order['round_off'] != 0.0): ?><div class="pos-kv"><span>Round Off</span><strong><?= e(pos_money($order['round_off'])) ?></strong></div><?php endif; ?>
      <div class="pos-kv grand"><span>Grand Total</span><strong><?= e(pos_money($order['total_amount'])) ?></strong></div>
      <?php if ((float)$order['amount_received'] > 0): ?><div class="pos-kv"><span>Received</span><strong><?= e(pos_money($order['amount_received'])) ?></strong></div><?php endif; ?>
      <?php if ((float)$order['change_amount'] > 0): ?><div class="pos-kv"><span>Change Returned</span><strong><?= e(pos_money($order['change_amount'])) ?></strong></div><?php endif; ?>
      <?php if ($balance > 0.009): ?><div class="pos-kv"><span class="text-danger">Balance Due</span><strong class="text-danger"><?= e(pos_money($balance)) ?></strong></div><?php endif; ?>
    </div>
    <div class="small text-muted mt-3 px-1">
      Sales order <a href="<?= base_url('sales/order_view.php?id=' . (int)$order['id']) ?>"><?= e($order['order_no']) ?></a>
      <?php if ($order['invoice_no']): ?> · Invoice <?= e($order['invoice_no']) ?><?php endif; ?>
      <?php if ($cancelled && $order['remarks_internal']): ?><div class="mt-1"><?= e($order['remarks_internal']) ?></div><?php endif; ?>
    </div>
  </div>
</div>

<div class="modal fade pos-modal" id="ovEmailModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" id="ovEmailForm">
      <div class="modal-header"><h5 class="modal-title">Send Receipt by Email</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <label class="form-label" for="ovEmail">Email address</label>
        <input type="email" class="form-control" id="ovEmail" required value="<?= e($order['customer_email'] ?? '') ?>" placeholder="customer@example.com">
        <div class="small mt-2" id="ovEmailMsg"></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-light border btn-lg-pos" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-brand btn-lg-pos" id="ovSend">Send</button></div>
    </form>
  </div>
</div>

<?php if (!$cancelled && pos_can('cancel')): ?>
<div class="modal fade pos-modal" id="ovCancelModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="cancel">
      <div class="modal-header"><h5 class="modal-title">Cancel Sale <?= e($order['pos_no']) ?>?</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <p class="mb-2">Stock goes back into <?= e($order['store_name'] ?? 'the store') ?> and every payment on this sale is refunded (<?= e(pos_money($order['amount_paid'])) ?>).</p>
        <label class="form-label" for="ovReason">Reason</label>
        <input class="form-control" id="ovReason" name="reason" maxlength="200" placeholder="e.g. Billed by mistake">
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-light border btn-lg-pos" data-bs-dismiss="modal">Keep Sale</button><button class="btn btn-danger btn-lg-pos">Cancel Sale</button></div>
    </form>
  </div>
</div>
<?php endif; ?>
<iframe class="pos-receipt-frame" id="ovFrame" title="Receipt"></iframe>
<?php
$extra_js = [asset_url('assets/js/pos.js')];
$extra_js_inline = '
(function () {
  var frame = document.getElementById("ovFrame"), url = ' . json_encode(base_url('pos/receipt.php?id=' . (int)$order['id'])) . ';
  document.getElementById("ovPrint").addEventListener("click", function () {
    frame.onload = function () { try { frame.contentWindow.focus(); frame.contentWindow.print(); } catch (e) { window.open(url, "_blank"); } };
    frame.src = url + "&t=" + Date.now();
  });
  document.getElementById("ovEmailForm").addEventListener("submit", function (e) {
    e.preventDefault();
    var msg = document.getElementById("ovEmailMsg"), btn = document.getElementById("ovSend");
    btn.disabled = true; msg.className = "small mt-2 text-muted"; msg.textContent = "Sending...";
    PosUtil.post(' . json_encode(base_url('pos/api.php')) . ', { action: "email_receipt", csrf_token: ' . json_encode(csrf_token()) . ', order_id: ' . (int)$order['id'] . ', email: document.getElementById("ovEmail").value })
      .then(function (res) { btn.disabled = false; msg.className = "small mt-2 " + (res.ok ? "text-success" : "text-danger"); msg.textContent = res.ok ? res.message : res.error; });
  });
})();';
require __DIR__ . '/../includes/footer.php';
