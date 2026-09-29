<?php
require_once __DIR__ . '/../includes/auth.php';
$ctx = pos_page();
$pdo = db();

// Pay out an open exchange credit in cash instead of spending it.
if (is_post() && input('action') === 'refund_credit') {
    require_module_edit('pos');
    csrf_verify();
    if (!pos_can('returns')) {
        flash('danger', 'You do not have permission to process returns.');
        redirect('/pos/returns.php');
    }
    if (pos_flag('pos_require_shift') && !$ctx['shift']) {
        flash('danger', 'Open a shift before paying out cash.');
        redirect('/pos/returns.php');
    }
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT * FROM sales_returns WHERE id = ? AND exchange_status = 'open' FOR UPDATE");
        $stmt->execute([(int)input('return_id')]);
        $credit = $stmt->fetch();
        if (!$credit) {
            throw new RuntimeException('That exchange credit has already been used or refunded.');
        }
        $amount = (float)$credit['exchange_amount'];
        $uid = current_user()['id'];
        $pay = $pdo->prepare('INSERT INTO payments (invoice_id, amount, payment_date, method, reference, notes, created_by, pos_shift_id) VALUES (?,?,?,?,?,?,?,?)');
        $pay->execute([$credit['invoice_id'], $amount, today(), 'store_credit', $credit['return_no'], 'Exchange credit paid out', $uid, $ctx['shift_id']]);
        $pay->execute([$credit['invoice_id'], -$amount, today(), 'cash', $credit['return_no'], 'Exchange credit paid out in cash', $uid, $ctx['shift_id']]);
        $pdo->prepare("UPDATE sales_returns SET exchange_status = 'refunded' WHERE id = ?")->execute([$credit['id']]);
        $pdo->commit();
        flash('success', pos_money($amount) . ' from ' . $credit['return_no'] . ' paid out in cash.');
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('danger', $e->getMessage());
    }
    redirect('/pos/returns.php');
}

$q = trim((string)input('q'));
$view = input('view') === 'returns' ? 'returns' : 'invoices';
$perPage = 25;
$page = max(1, (int)input('page'));

$credits = $pdo->query("SELECT sr.*, c.name customer_name, c.mobile, so.pos_no FROM sales_returns sr JOIN customers c ON c.id = sr.customer_id JOIN sales_orders so ON so.id = sr.sales_order_id
    WHERE sr.exchange_status = 'open' ORDER BY sr.id DESC")->fetchAll();

if ($view === 'invoices') {
    $where = " WHERE so.channel = 'pos'";
    $params = [];
    if ($q !== '') {
        $where .= ' AND (so.pos_no LIKE ? OR so.order_no LIKE ? OR c.name LIKE ? OR c.mobile LIKE ? OR c.phone LIKE ?)';
        $params = array_fill(0, 5, '%' . $q . '%');
    } else {
        // Most returns happen within a month; search reaches older bills.
        $where .= ' AND so.order_date >= ?';
        $params[] = date('Y-m-d', strtotime('-30 days'));
    }
    $cnt = $pdo->prepare('SELECT COUNT(*) FROM sales_orders so JOIN customers c ON c.id = so.customer_id' . $where);
    $cnt->execute($params);
    $total = (int)$cnt->fetchColumn();
    $stmt = $pdo->prepare("SELECT so.id, so.pos_no, so.order_no, so.created_at, so.total_amount, so.status, c.name customer_name, c.mobile,
            (SELECT COALESCE(SUM(quantity),0) FROM sales_order_items WHERE order_id = so.id) items,
            (SELECT COALESCE(SUM(sri.quantity),0) FROM sales_return_items sri JOIN sales_returns sr ON sr.id = sri.sales_return_id WHERE sr.sales_order_id = so.id AND sr.status = 'completed') returned
        FROM sales_orders so JOIN customers c ON c.id = so.customer_id $where ORDER BY so.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
} else {
    $where = " WHERE so.channel = 'pos'";
    $params = [];
    if ($q !== '') {
        $where .= ' AND (sr.return_no LIKE ? OR so.pos_no LIKE ? OR c.name LIKE ? OR c.mobile LIKE ?)';
        $params = array_fill(0, 4, '%' . $q . '%');
    }
    $cnt = $pdo->prepare('SELECT COUNT(*) FROM sales_returns sr JOIN sales_orders so ON so.id = sr.sales_order_id JOIN customers c ON c.id = sr.customer_id' . $where);
    $cnt->execute($params);
    $total = (int)$cnt->fetchColumn();
    $stmt = $pdo->prepare("SELECT sr.*, so.pos_no, c.name customer_name, u.name created_by_name, (SELECT COALESCE(SUM(quantity),0) FROM sales_return_items WHERE sales_return_id = sr.id) items
        FROM sales_returns sr JOIN sales_orders so ON so.id = sr.sales_order_id JOIN customers c ON c.id = sr.customer_id LEFT JOIN users u ON u.id = sr.created_by
        $where ORDER BY sr.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
}
$pages = max(1, (int)ceil($total / $perPage));
$canReturn = pos_can('returns') && can_edit_module('pos');

$page_title = 'Returns & Exchanges';
require __DIR__ . '/../includes/header.php';
?>
<?php if ($credits): ?>
  <div class="pos-card pos-card-body mb-3">
    <h2 class="pos-section-title"><i class="fa-solid fa-ticket text-primary"></i> Open exchange credits</h2>
    <div class="table-responsive">
      <table class="table pos-table mb-0">
        <thead><tr><th>Return No.</th><th>From Invoice</th><th>Customer</th><th>Date</th><th>Credit</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($credits as $c): ?>
          <tr>
            <td><?= e($c['return_no']) ?></td>
            <td><a class="no" href="<?= base_url('pos/order_view.php?id=' . (int)$c['sales_order_id']) ?>"><?= e($c['pos_no']) ?></a></td>
            <td><?= e($c['customer_name']) ?><div class="small text-muted"><?= e($c['mobile'] ?? '') ?></div></td>
            <td><?= e(pos_date($c['return_date'])) ?></td>
            <td class="fw-semibold"><?= e(pos_money($c['exchange_amount'])) ?></td>
            <td class="text-end text-nowrap">
              <?php if ($canReturn): ?>
                <a class="pos-act" href="<?= base_url('pos/index.php?exchange=' . (int)$c['id']) ?>"><i class="fa-solid fa-cart-shopping"></i> Use in new sale</a>
                <form method="post" class="d-inline ms-2"><?= csrf_field() ?><input type="hidden" name="action" value="refund_credit"><input type="hidden" name="return_id" value="<?= (int)$c['id'] ?>">
                  <button class="pos-act border-0 bg-transparent text-danger" data-confirm="Pay <?= e(pos_money($c['exchange_amount'])) ?> to <?= e($c['customer_name']) ?> in cash?">Refund in cash</button></form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<div class="pos-card pos-card-body">
  <div class="pos-head">
    <h1 class="pos-title">Returns &amp; Exchanges</h1>
    <div class="pos-tabs">
      <a class="pos-tab <?= $view === 'invoices' ? 'active' : '' ?>" href="?view=invoices">Invoices</a>
      <a class="pos-tab <?= $view === 'returns' ? 'active' : '' ?>" href="?view=returns">Return History</a>
    </div>
  </div>
  <form method="get" class="mb-3">
    <input type="hidden" name="view" value="<?= e($view) ?>">
    <div class="pos-searchbar" style="max-width:560px">
      <div class="pos-search-in"><i class="fa-solid fa-magnifying-glass"></i>
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search by invoice no, customer or mobile" autofocus></div>
    </div>
  </form>

  <div class="table-responsive">
    <table class="table pos-table">
      <?php if ($view === 'invoices'): ?>
        <thead><tr><th>Invoice No.</th><th>Date</th><th>Customer</th><th>Items</th><th>Amount</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r):
            $state = $r['status'] === 'cancelled' ? ['Cancelled', 'red'] : ((int)$r['returned'] >= (int)$r['items'] && (int)$r['returned'] > 0 ? ['Returned', 'purple'] : ((int)$r['returned'] > 0 ? ['Part Returned', 'purple'] : ['Completed', 'green']));
            $returnable = $r['status'] !== 'cancelled' && (int)$r['returned'] < (int)$r['items']; ?>
          <tr>
            <td><a class="no" href="<?= base_url('pos/order_view.php?id=' . (int)$r['id']) ?>"><?= e($r['pos_no'] ?: $r['order_no']) ?></a></td>
            <td><?= e(pos_datetime($r['created_at'])) ?></td>
            <td><?= e($r['customer_name']) ?><?php if ($r['mobile']): ?><div class="small text-muted"><?= e($r['mobile']) ?></div><?php endif; ?></td>
            <td><?= (int)$r['items'] ?></td>
            <td><?= e(pos_money($r['total_amount'])) ?></td>
            <td><span class="pos-pill <?= e($state[1]) ?>"><?= e($state[0]) ?></span></td>
            <td><?php if ($returnable && $canReturn): ?><a class="pos-act" href="<?= base_url('pos/return.php?order=' . (int)$r['id']) ?>"><i class="fa-solid fa-rotate-left"></i> Return</a><?php else: ?><span class="text-muted small">-</span><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-5"><?= $q !== '' ? 'No POS invoice matches "' . e($q) . '".' : 'No POS sales in the last 30 days. Search to find older invoices.' ?></td></tr><?php endif; ?>
        </tbody>
      <?php else: ?>
        <thead><tr><th>Return No.</th><th>Date</th><th>Invoice</th><th>Customer</th><th>Items</th><th>Refund</th><th>Amount</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= e($r['return_no']) ?></td>
            <td><?= e(pos_date($r['return_date'])) ?></td>
            <td><a class="no" href="<?= base_url('pos/order_view.php?id=' . (int)$r['sales_order_id']) ?>"><?= e($r['pos_no']) ?></a></td>
            <td><?= e($r['customer_name']) ?></td>
            <td><?= (int)$r['items'] ?></td>
            <td><?php if ($r['refund_method'] === 'exchange'): ?><span class="pos-pill blue">Exchange <?= e($r['exchange_status'] === 'none' ? '' : $r['exchange_status']) ?></span><?php else: ?><?= e(pos_method_label((string)$r['refund_method'])) ?><?php endif; ?></td>
            <td><?= e(pos_money($r['total_amount'])) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-5">No returns yet.</td></tr><?php endif; ?>
        </tbody>
      <?php endif; ?>
    </table>
  </div>
  <?php if ($pages > 1): ?>
    <nav class="d-flex justify-content-end"><ul class="pagination pagination-sm mb-0">
      <?php for ($i = max(1, $page - 3); $i <= min($pages, $page + 3); $i++): ?>
        <li class="page-item <?= $i === $page ? 'active' : '' ?>"><a class="page-link" href="?<?= e(http_build_query(['view' => $view, 'q' => $q, 'page' => $i])) ?>"><?= $i ?></a></li>
      <?php endfor; ?>
    </ul></nav>
  <?php endif; ?>
</div>
<?php
$extra_js = [asset_url('assets/js/pos.js')];
require __DIR__ . '/../includes/footer.php';
