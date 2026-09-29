<?php
require_once __DIR__ . '/../includes/auth.php';
$ctx = pos_page();
$pdo = db();

$tab = in_array(input('tab'), ['completed', 'draft', 'cancelled'], true) ? input('tab') : 'all';
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)input('from')) ? input('from') : date('Y-m-01');
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)input('to')) ? input('to') : today();
if ($from > $to) {
    [$from, $to] = [$to, $from];
}
$q = trim((string)input('q'));
$cashier = (int)input('user');
$perPage = 25;
$page = max(1, (int)input('page'));

$where = " WHERE so.channel = 'pos' AND so.order_date BETWEEN ? AND ?";
$params = [$from, $to];
if ($q !== '') {
    $where .= ' AND (so.pos_no LIKE ? OR so.order_no LIKE ? OR c.name LIKE ? OR c.mobile LIKE ? OR c.phone LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like);
}
if ($cashier) {
    $where .= ' AND so.created_by = ?';
    $params[] = $cashier;
}
$from_sql = ' FROM sales_orders so JOIN customers c ON c.id = so.customer_id';

$count = function (string $extra) use ($pdo, $from_sql, $where, $params): int {
    $stmt = $pdo->prepare('SELECT COUNT(*)' . $from_sql . $where . $extra);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
};
$heldWhere = ' WHERE DATE(h.created_at) BETWEEN ? AND ?';
$heldParams = [$from, $to];
if ($q !== '') {
    $heldWhere .= ' AND (h.hold_no LIKE ? OR c.name LIKE ?)';
    array_push($heldParams, '%' . $q . '%', '%' . $q . '%');
}
$heldCountStmt = $pdo->prepare('SELECT COUNT(*) FROM pos_held_orders h LEFT JOIN customers c ON c.id = h.customer_id' . $heldWhere);
$heldCountStmt->execute($heldParams);
$counts = [
    'all' => $count(''),
    'completed' => $count(" AND so.status <> 'cancelled'"),
    'draft' => (int)$heldCountStmt->fetchColumn(),
    'cancelled' => $count(" AND so.status = 'cancelled'"),
];

$rows = [];
if ($tab === 'draft') {
    $stmt = $pdo->prepare('SELECT h.*, c.name customer_name FROM pos_held_orders h LEFT JOIN customers c ON c.id = h.customer_id' . $heldWhere . ' ORDER BY h.id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage));
    $stmt->execute($heldParams);
    $rows = $stmt->fetchAll();
    $total = $counts['draft'];
} else {
    $extra = $tab === 'completed' ? " AND so.status <> 'cancelled'" : ($tab === 'cancelled' ? " AND so.status = 'cancelled'" : '');
    $sql = "SELECT so.id, so.pos_no, so.order_no, so.created_at, so.total_amount, so.status, so.payment_method, c.name customer_name, c.mobile customer_mobile, u.name cashier_name,
            (SELECT COALESCE(SUM(quantity),0) FROM sales_order_items WHERE order_id = so.id) items,
            (SELECT COALESCE(SUM(sri.quantity),0) FROM sales_return_items sri JOIN sales_returns sr ON sr.id = sri.sales_return_id WHERE sr.sales_order_id = so.id AND sr.status = 'completed') returned,
            (SELECT status FROM invoices WHERE sales_order_id = so.id ORDER BY id DESC LIMIT 1) inv_status
        $from_sql LEFT JOIN users u ON u.id = so.created_by $where $extra ORDER BY so.id DESC";
    if (input('export') === 'csv') {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="pos-orders-' . $from . '-to-' . $to . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Invoice No', 'Date & Time', 'Customer', 'Mobile', 'Cashier', 'Items', 'Amount', 'Payment', 'Status']);
        foreach ($stmt as $r) {
            fputcsv($out, [$r['pos_no'] ?: $r['order_no'], $r['created_at'], $r['customer_name'], $r['customer_mobile'], $r['cashier_name'], $r['items'], $r['total_amount'], $r['payment_method'], pos_order_status_label($r)[0]]);
        }
        exit;
    }
    $total = $counts[$tab];
    $stmt = $pdo->prepare($sql . ' LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage));
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
}
$pages = max(1, (int)ceil($total / $perPage));

/** [label, pill tone] for a POS order row. */
function pos_order_status_label(array $r): array
{
    if ($r['status'] === 'cancelled') {
        return ['Cancelled', 'red'];
    }
    if ((int)$r['returned'] > 0) {
        return (int)$r['returned'] >= (int)$r['items'] ? ['Returned', 'purple'] : ['Part Returned', 'purple'];
    }
    return match ($r['inv_status']) {
        'partially_paid' => ['Partly Paid', 'orange'],
        'unpaid', 'overdue' => ['Unpaid', 'orange'],
        default => ['Paid', 'green'],
    };
}

$qs = function (array $over) use ($tab, $from, $to, $q, $cashier): string {
    return '?' . http_build_query(array_filter(array_merge(['tab' => $tab, 'from' => $from, 'to' => $to, 'q' => $q, 'user' => $cashier ?: null], $over), fn($v) => $v !== null && $v !== ''));
};

$page_title = 'POS Orders';
require __DIR__ . '/../includes/header.php';
?>
<div class="pos-card pos-card-body">
  <form method="get" class="pos-head" id="poFilter">
    <input type="hidden" name="tab" value="<?= e($tab) ?>">
    <div class="pos-tabs">
      <?php foreach (['all' => 'All', 'completed' => 'Completed', 'draft' => 'Draft', 'cancelled' => 'Cancelled'] as $k => $label): ?>
        <a class="pos-tab <?= $tab === $k ? 'active' : '' ?>" href="<?= e($qs(['tab' => $k, 'page' => null])) ?>"><?= e($label) ?><span class="n"><?= (int)$counts[$k] ?></span></a>
      <?php endforeach; ?>
    </div>
    <div class="d-flex gap-2 flex-wrap align-items-center">
      <div class="inv-search" style="min-width:230px"><i class="fa-solid fa-magnifying-glass"></i><input type="search" name="q" class="form-control" value="<?= e($q) ?>" placeholder="Invoice, customer, mobile"></div>
      <label class="pos-daterange"><i class="fa-regular fa-calendar"></i>
        <input type="date" name="from" value="<?= e($from) ?>" onchange="this.form.submit()"> <span class="text-muted">-</span>
        <input type="date" name="to" value="<?= e($to) ?>" onchange="this.form.submit()"></label>
      <?php if ($tab !== 'draft'): ?><a class="pos-export" href="<?= e($qs(['export' => 'csv'])) ?>"><i class="fa-solid fa-arrow-up-from-bracket"></i> Export</a><?php endif; ?>
    </div>
  </form>
  <?php if ($cashier): ?><div class="pos-banner info py-2">Showing sales by one cashier. <a href="<?= e($qs(['user' => null])) ?>">Show all</a></div><?php endif; ?>

  <div class="table-responsive">
    <table class="table pos-table">
      <?php if ($tab === 'draft'): ?>
        <thead><tr><th>Held No.</th><th>Date &amp; Time</th><th>Customer</th><th>Items</th><th>Amount</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $h): ?>
          <tr><td><a class="no" href="<?= base_url('pos/index.php?resume=' . (int)$h['id']) ?>"><?= e($h['hold_no']) ?></a></td><td><?= e(pos_datetime($h['created_at'])) ?></td>
            <td><?= e($h['customer_name'] ?? 'Walk-in Customer') ?></td><td><?= (int)$h['items_count'] ?></td><td><?= e(pos_money($h['amount'])) ?></td>
            <td><span class="pos-pill gray">Draft</span></td><td><a class="pos-act" href="<?= base_url('pos/index.php?resume=' . (int)$h['id']) ?>">Resume</a></td></tr>
        <?php endforeach; ?>
      <?php else: ?>
        <thead><tr><th>Invoice No.</th><th>Date &amp; Time</th><th>Customer</th><th>Items</th><th>Amount</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): [$label, $tone] = pos_order_status_label($r); ?>
          <tr>
            <td><a class="no" href="<?= base_url('pos/order_view.php?id=' . (int)$r['id']) ?>"><?= e($r['pos_no'] ?: $r['order_no']) ?></a></td>
            <td><?= e(pos_datetime($r['created_at'])) ?></td>
            <td><?= e($r['customer_name']) ?></td>
            <td><?= (int)$r['items'] ?></td>
            <td><?= e(pos_money($r['total_amount'])) ?></td>
            <td><span class="pos-pill <?= e($tone) ?>"><?= e($label) ?></span></td>
            <td><a class="pos-act" href="<?= base_url('pos/order_view.php?id=' . (int)$r['id']) ?>">View</a></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-5">No orders between <?= e(pos_date($from)) ?> and <?= e(pos_date($to)) ?>.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages > 1): ?>
    <div class="d-flex justify-content-between align-items-center mt-3 small text-muted">
      <span>Showing <?= ($page - 1) * $perPage + 1 ?>–<?= min($total, $page * $perPage) ?> of <?= (int)$total ?></span>
      <nav><ul class="pagination pagination-sm mb-0">
        <?php for ($i = max(1, $page - 3); $i <= min($pages, $page + 3); $i++): ?>
          <li class="page-item <?= $i === $page ? 'active' : '' ?>"><a class="page-link" href="<?= e($qs(['page' => $i])) ?>"><?= $i ?></a></li>
        <?php endfor; ?>
      </ul></nav>
    </div>
  <?php endif; ?>
</div>
<?php
$extra_js = [asset_url('assets/js/pos.js')];
require __DIR__ . '/../includes/footer.php';
