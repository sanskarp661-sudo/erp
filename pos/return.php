<?php
require_once __DIR__ . '/../includes/auth.php';
$ctx = pos_page(true);
$pdo = db();

$order = pos_order((int)input('order'));
if (!$order) {
    flash('danger', 'POS order not found.');
    redirect('/pos/returns.php');
}
if (!pos_can('returns')) {
    flash('danger', 'You do not have permission to process returns.');
    redirect('/pos/order_view.php?id=' . (int)$order['id']);
}
if ($order['status'] === 'cancelled') {
    flash('warning', 'This sale was cancelled, so nothing can be returned.');
    redirect('/pos/order_view.php?id=' . (int)$order['id']);
}

$items = pos_order_items((int)$order['id']);
$returned = pos_returned_qty((int)$order['id']);
// One row per product (a product can appear on several lines).
$rows = [];
foreach ($items as $it) {
    $pid = (int)$it['product_id'];
    if (!isset($rows[$pid])) {
        $rows[$pid] = ['product_id' => $pid, 'name' => $it['product_name'], 'sku' => $it['sku'], 'sold' => 0, 'total' => 0.0];
    }
    $rows[$pid]['sold'] += (int)$it['quantity'];
    $rows[$pid]['total'] += (float)$it['line_total'];
}
foreach ($rows as $pid => &$r) {
    $r['returned'] = $returned[$pid] ?? 0;
    $r['left'] = $r['sold'] - $r['returned'];
    $r['unit'] = $r['sold'] ? round($r['total'] / $r['sold'], 2) : 0;
}
unset($r);
$modes = array_intersect_key(['cash' => 'Cash', 'card' => 'Card', 'upi' => 'UPI', 'wallet' => 'Wallet'], array_flip(array_merge(['cash'], pos_payment_modes())));
$modes['exchange'] = 'Exchange (credit for a new sale)';
$needShift = pos_flag('pos_require_shift') && !$ctx['shift'];

$errors = [];
if (is_post()) {
    csrf_verify();
    $qtys = array_map('intval', (array)($_POST['qty'] ?? []));
    $method = (string)input('refund_method');
    if ($needShift) {
        $errors[] = 'Open a shift on this terminal before processing a return.';
    } elseif (!isset($modes[$method])) {
        $errors[] = 'Pick how to refund the customer.';
    } else {
        try {
            $pdo->beginTransaction();
            $res = pos_process_return($pdo, $order, $qtys, trim((string)input('reason')), $method, $ctx, current_user()['id']);
            $pdo->commit();
            if ($method === 'exchange' && $res['refund'] > 0.009) {
                flash('success', 'Return ' . $res['return_no'] . ' saved. ' . pos_money($res['refund']) . ' credit is applied to this new sale.');
                redirect('/pos/index.php?exchange=' . $res['return_id']);
            }
            $msg = 'Return ' . $res['return_no'] . ' saved for ' . pos_money($res['total']) . '.';
            $msg .= $res['refund'] > 0.009 ? ' Refund ' . pos_money($res['refund']) . ' by ' . pos_method_label($method === 'exchange' ? 'cash' : $method) . '.' : ' The amount was taken off the balance due.';
            flash('success', $msg);
            redirect('/pos/order_view.php?id=' . (int)$order['id']);
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $e->getMessage();
        }
    }
}
$balance = round((float)$order['invoice_total'] - (float)$order['amount_paid'], 2);

$page_title = 'Return ' . $order['pos_no'];
require __DIR__ . '/../includes/header.php';
?>
<div class="pos-head mb-3">
  <div>
    <a href="<?= base_url('pos/returns.php') ?>" class="small text-decoration-none"><i class="fa-solid fa-arrow-left"></i> Returns &amp; Exchanges</a>
    <h1 class="pos-title mt-1">Return items from <?= e($order['pos_no']) ?></h1>
    <div class="text-muted small"><?= e($order['customer_name']) ?> · <?= e(pos_datetime($order['created_at'])) ?> · Bill <?= e(pos_money($order['total_amount'])) ?></div>
  </div>
</div>
<?php if ($needShift): ?>
  <div class="pos-banner warn">No shift is open on <?= e($ctx['profile']['name'] ?? 'this terminal') ?>. <a href="<?= base_url('pos/shift.php') ?>">Open a shift</a> to process returns.</div>
<?php endif; ?>
<?php foreach ($errors as $err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endforeach; ?>

<form method="post" id="rtForm"><?= csrf_field() ?>
  <div class="row g-3">
    <div class="col-lg-8">
      <div class="pos-card">
        <div class="table-responsive">
          <table class="table pos-table mb-0">
            <thead><tr><th>Item</th><th class="text-end">Sold</th><th class="text-end">Returned</th><th class="text-end">Paid / unit</th><th style="width:150px">Return Qty</th><th class="text-end">Refund</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><div class="fw-semibold"><?= e($r['name']) ?></div><div class="small text-muted"><?= e($r['sku']) ?></div></td>
                <td class="text-end"><?= (int)$r['sold'] ?></td>
                <td class="text-end"><?= (int)$r['returned'] ?></td>
                <td class="text-end"><?= e(pos_money($r['unit'])) ?></td>
                <td>
                  <?php if ($r['left'] > 0): ?>
                    <div class="pos-qty">
                      <button type="button" data-step="-1" aria-label="Less">−</button>
                      <input type="number" name="qty[<?= (int)$r['product_id'] ?>]" min="0" max="<?= (int)$r['left'] ?>" value="<?= (int)($_POST['qty'][$r['product_id']] ?? 0) ?>" data-unit="<?= e((string)$r['unit']) ?>" class="rt-qty">
                      <button type="button" data-step="1" aria-label="More">+</button>
                    </div>
                  <?php else: ?><span class="small text-muted">All returned</span><?php endif; ?>
                </td>
                <td class="text-end rt-line">-</td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <button type="button" class="btn btn-link px-0 mt-2" id="rtAll">Return everything</button>
    </div>
    <div class="col-lg-4">
      <div class="pos-card pos-card-body">
        <h2 class="pos-section-title">Refund</h2>
        <label class="form-label" for="rtReason">Reason</label>
        <select class="form-select mb-3" id="rtReason" name="reason">
          <?php foreach (['Size / fit issue', 'Damaged or defective', 'Wrong item', 'Changed mind', 'Other'] as $reason): ?>
            <option <?= input('reason') === $reason ? 'selected' : '' ?>><?= e($reason) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="form-label">Refund method</div>
        <?php foreach ($modes as $k => $label): ?>
          <div class="form-check mb-1"><input class="form-check-input" type="radio" name="refund_method" id="rm<?= e($k) ?>" value="<?= e($k) ?>" <?= (input('refund_method') ?: 'cash') === $k ? 'checked' : '' ?>><label class="form-check-label" for="rm<?= e($k) ?>"><?= e($label) ?></label></div>
        <?php endforeach; ?>
        <div class="pos-kv px-0 mt-3"><span>Return value</span><strong id="rtTotal"><?= e(pos_money(0)) ?></strong></div>
        <?php if ($balance > 0.009): ?><div class="small text-muted mt-2">This bill still has <?= e(pos_money($balance)) ?> due. The return clears that first; only the rest is refunded.</div><?php endif; ?>
        <button class="btn btn-brand w-100 btn-lg-pos mt-3" id="rtSubmit" <?= $needShift ? 'disabled' : '' ?>>Process Return</button>
      </div>
    </div>
  </div>
</form>
<?php
$extra_js = [asset_url('assets/js/pos.js')];
$extra_js_inline = '
(function () {
  var inputs = document.querySelectorAll(".rt-qty");
  function recalc() {
    var total = 0;
    inputs.forEach(function (i) {
      var q = Math.max(0, Math.min(parseInt(i.value || "0", 10) || 0, parseInt(i.max, 10)));
      if (String(q) !== i.value) { i.value = q; }
      var amt = q * parseFloat(i.dataset.unit);
      total += amt;
      i.closest("tr").querySelector(".rt-line").textContent = q ? PosUtil.money(amt) : "-";
    });
    document.getElementById("rtTotal").textContent = PosUtil.money(total);
    return total;
  }
  document.querySelectorAll(".pos-qty button").forEach(function (b) {
    b.addEventListener("click", function () { var i = b.parentNode.querySelector("input"); i.value = (parseInt(i.value || "0", 10) || 0) + parseInt(b.dataset.step, 10); recalc(); });
  });
  inputs.forEach(function (i) { i.addEventListener("input", recalc); });
  document.getElementById("rtAll").addEventListener("click", function () { inputs.forEach(function (i) { i.value = i.max; }); recalc(); });
  document.getElementById("rtForm").addEventListener("submit", function (e) {
    if (recalc() <= 0) { e.preventDefault(); PosUtil.toast("Enter a return quantity for at least one item.", "danger"); }
  });
  recalc();
})();';
require __DIR__ . '/../includes/footer.php';
