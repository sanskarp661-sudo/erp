<?php
require_once __DIR__ . '/../includes/auth.php';
$ctx = pos_page(true);

$order = pos_order((int)input('id'));
if (!$order) {
    flash('danger', 'POS order not found.');
    redirect('/pos/orders.php');
}
$balance = $order['invoice_id'] ? round((float)$order['invoice_total'] - (float)$order['amount_paid'], 2) : 0;

$page_title = 'Payment Successful';
require __DIR__ . '/../includes/header.php';
?>
<div class="pos-card pos-success">
  <span class="pos-success-icon"><i class="fa-solid fa-check"></i></span>
  <h1>Payment Successful!</h1>
  <div class="fw-bold fs-5">Invoice No: <?= e($order['pos_no'] ?: $order['order_no']) ?></div>
  <div class="text-muted mt-1">Date: <?= e(pos_datetime($order['created_at'])) ?></div>
  <div class="fw-bold mt-3" style="font-size:1.9rem;color:#0f1b3d">Grand Total: <?= e(pos_money($order['total_amount'])) ?></div>
  <?php if ((float)$order['change_amount'] > 0): ?>
    <div class="mt-2 fs-5 text-success">Change to return: <strong><?= e(pos_money($order['change_amount'])) ?></strong></div>
  <?php endif; ?>
  <?php if ($balance > 0.009): ?>
    <div class="mt-2 text-danger">Balance due on account: <strong><?= e(pos_money($balance)) ?></strong></div>
  <?php endif; ?>
  <div class="text-muted small mt-2"><?= e($order['customer_name']) ?> · Paid by <?= e($order['payment_method'] ?: '-') ?></div>

  <div class="pos-success-actions">
    <button type="button" class="pos-btn-soft" id="psPrint"><i class="fa-solid fa-print"></i> Print Receipt</button>
    <button type="button" class="pos-btn-soft" data-bs-toggle="modal" data-bs-target="#psEmailModal"><i class="fa-regular fa-envelope"></i> Send by Email</button>
    <a href="<?= base_url('pos/index.php') ?>" class="pos-btn-soft" id="psNew">New Sale</a>
    <a href="<?= base_url('pos/orders.php') ?>" class="pos-btn-soft">View POS Orders</a>
  </div>
  <div class="mt-3"><a class="small" href="<?= base_url('pos/order_view.php?id=' . (int)$order['id']) ?>">Open order details</a></div>
</div>

<div class="modal fade pos-modal" id="psEmailModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" id="psEmailForm">
      <div class="modal-header"><h5 class="modal-title">Send Receipt by Email</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <label class="form-label" for="psEmail">Email address</label>
        <input type="email" class="form-control" id="psEmail" required value="<?= e($order['customer_email'] ?? '') ?>" placeholder="customer@example.com">
        <?php if (!$order['customer_email'] && $order['customer_name'] !== 'Walk-in Customer'): ?>
          <div class="form-check mt-2"><input class="form-check-input" type="checkbox" id="psSave" checked><label class="form-check-label" for="psSave">Save this email on <?= e($order['customer_name']) ?></label></div>
        <?php endif; ?>
        <div class="small mt-2" id="psEmailMsg"></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-light border btn-lg-pos" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-brand btn-lg-pos" id="psSend">Send</button></div>
    </form>
  </div>
</div>
<iframe class="pos-receipt-frame" id="psFrame" title="Receipt"></iframe>
<?php
$receiptUrl = base_url('pos/receipt.php?id=' . (int)$order['id']);
$extra_js = [asset_url('assets/js/pos.js')];
$extra_js_inline = '
PosUtil.store.del(' . json_encode('pos_cart_' . ($ctx['profile_id'] ?? 0)) . ');
(function () {
  var frame = document.getElementById("psFrame"), url = ' . json_encode($receiptUrl) . ';
  function printReceipt() {
    frame.onload = function () { try { frame.contentWindow.focus(); frame.contentWindow.print(); } catch (e) { window.open(url, "_blank"); } };
    frame.src = url + "&t=" + Date.now();
  }
  document.getElementById("psPrint").addEventListener("click", printReceipt);
  ' . (input('print') === '1' ? 'printReceipt();' : '') . '
  document.getElementById("psEmailForm").addEventListener("submit", function (e) {
    e.preventDefault();
    var msg = document.getElementById("psEmailMsg"), btn = document.getElementById("psSend"), save = document.getElementById("psSave");
    btn.disabled = true; msg.className = "small mt-2 text-muted"; msg.textContent = "Sending...";
    PosUtil.post(' . json_encode(base_url('pos/api.php')) . ', { action: "email_receipt", csrf_token: ' . json_encode(csrf_token()) . ', order_id: ' . (int)$order['id'] . ', email: document.getElementById("psEmail").value, save: save && save.checked ? "1" : "0" })
      .then(function (res) {
        btn.disabled = false;
        msg.className = "small mt-2 " + (res.ok ? "text-success" : "text-danger");
        msg.textContent = res.ok ? res.message : res.error;
      });
  });
  document.addEventListener("keydown", function (e) { if (e.key === "Enter" && !document.querySelector(".modal.show")) { window.location.href = document.getElementById("psNew").href; } });
})();';
require __DIR__ . '/../includes/footer.php';
