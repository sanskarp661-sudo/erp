<?php
require_once __DIR__ . '/../includes/auth.php';
$ctx = pos_page();
$pdo = db();

$reports = [
    'summary'  => ['Sales Summary', 'fa-solid fa-chart-column'],
    'items'    => ['Item-wise Sales', 'fa-solid fa-box'],
    'payments' => ['Payment Mode', 'fa-regular fa-credit-card'],
    'cashiers' => ['Cashier-wise', 'fa-solid fa-user-tie'],
    'returns'  => ['Returns Report', 'fa-solid fa-rotate-left'],
    'tax'      => ['Tax Report', 'fa-solid fa-percent'],
];
$r = isset($reports[input('r')]) ? input('r') : 'summary';
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)input('from')) ? input('from') : date('Y-m-01');
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)input('to')) ? input('to') : today();
if ($from > $to) {
    [$from, $to] = [$to, $from];
}
$store = (int)input('store');
$storeSql = $store ? ' AND so.warehouse_id = ' . $store : '';

/** Sales KPIs for a date range. */
$kpis = function (string $a, string $b) use ($pdo, $storeSql): array {
    $s = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(total_amount),0) t FROM sales_orders so WHERE so.channel = 'pos' AND so.status <> 'cancelled' AND so.order_date BETWEEN ? AND ?" . $storeSql);
    $s->execute([$a, $b]);
    $sales = $s->fetch();
    $rt = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(sr.total_amount),0) t FROM sales_returns sr JOIN sales_orders so ON so.id = sr.sales_order_id WHERE so.channel = 'pos' AND sr.status = 'completed' AND sr.return_date BETWEEN ? AND ?" . $storeSql);
    $rt->execute([$a, $b]);
    $ret = $rt->fetch();
    return ['sales' => (float)$sales['t'], 'orders' => (int)$sales['n'], 'aov' => $sales['n'] ? (float)$sales['t'] / $sales['n'] : 0.0, 'returns' => (float)$ret['t'], 'return_count' => (int)$ret['n']];
};

$columns = [];
$rows = [];
$foot = null;
switch ($r) {
    case 'summary':
        $cur = $kpis($from, $to);
        $days = (int)((strtotime($to) - strtotime($from)) / 86400) + 1;
        $prevTo = date('Y-m-d', strtotime($from . ' -1 day'));
        $prevFrom = date('Y-m-d', strtotime($prevTo . ' -' . ($days - 1) . ' days'));
        $prev = $kpis($prevFrom, $prevTo);
        $stmt = $pdo->prepare("SELECT so.order_date d, COUNT(*) n, SUM(total_amount) t FROM sales_orders so WHERE so.channel = 'pos' AND so.status <> 'cancelled' AND so.order_date BETWEEN ? AND ?" . $storeSql . ' GROUP BY so.order_date');
        $stmt->execute([$from, $to]);
        $byDay = [];
        foreach ($stmt as $row) {
            $byDay[$row['d']] = $row;
        }
        $columns = ['Date', 'Orders', 'Sales'];
        for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            $rows[] = [$d, (int)($byDay[$d]['n'] ?? 0), round((float)($byDay[$d]['t'] ?? 0), 2)];
        }
        $foot = ['Total', $cur['orders'], $cur['sales']];
        break;

    case 'items':
        $stmt = $pdo->prepare("SELECT p.sku, p.name, COALESCE(cat.name, '-') category, SUM(soi.quantity) qty, SUM(soi.discount_amount) disc, SUM(soi.subtotal) taxable, SUM(soi.tax_amount) tax, SUM(soi.line_total) total,
                (SELECT COALESCE(SUM(sri.quantity),0) FROM sales_return_items sri JOIN sales_returns sr ON sr.id = sri.sales_return_id JOIN sales_orders so2 ON so2.id = sr.sales_order_id
                  WHERE sri.product_id = p.id AND so2.channel = 'pos' AND sr.status = 'completed' AND sr.return_date BETWEEN ? AND ?) returned
            FROM sales_order_items soi JOIN sales_orders so ON so.id = soi.order_id JOIN products p ON p.id = soi.product_id LEFT JOIN categories cat ON cat.id = p.category_id
            WHERE so.channel = 'pos' AND so.status <> 'cancelled' AND so.order_date BETWEEN ? AND ?" . $storeSql . '
            GROUP BY p.id, p.sku, p.name, cat.name ORDER BY total DESC');
        $stmt->execute([$from, $to, $from, $to]);
        $columns = ['SKU', 'Item', 'Category', 'Qty Sold', 'Returned', 'Discount', 'Taxable', 'GST', 'Total'];
        $sum = [0, 0, 0, 0, 0, 0];
        foreach ($stmt as $row) {
            $rows[] = [$row['sku'], $row['name'], $row['category'], (int)$row['qty'], (int)$row['returned'], (float)$row['disc'], (float)$row['taxable'], (float)$row['tax'], (float)$row['total']];
            $sum = [$sum[0] + $row['qty'], $sum[1] + $row['returned'], $sum[2] + $row['disc'], $sum[3] + $row['taxable'], $sum[4] + $row['tax'], $sum[5] + $row['total']];
        }
        $foot = array_merge(['Total', '', ''], $sum);
        break;

    case 'payments':
        $stmt = $pdo->prepare("SELECT p.method, COUNT(CASE WHEN p.amount > 0 THEN 1 END) n, SUM(CASE WHEN p.amount > 0 THEN p.amount ELSE 0 END) received, SUM(CASE WHEN p.amount < 0 THEN -p.amount ELSE 0 END) refunded, SUM(p.amount) net
            FROM payments p JOIN invoices i ON i.id = p.invoice_id JOIN sales_orders so ON so.id = i.sales_order_id
            WHERE so.channel = 'pos' AND p.method <> 'credit_note' AND p.payment_date BETWEEN ? AND ?" . $storeSql . ' GROUP BY p.method ORDER BY net DESC');
        $stmt->execute([$from, $to]);
        $columns = ['Payment Mode', 'Transactions', 'Received', 'Refunded', 'Net Collected'];
        $sum = [0, 0, 0, 0];
        foreach ($stmt as $row) {
            $rows[] = [pos_method_label($row['method']), (int)$row['n'], (float)$row['received'], (float)$row['refunded'], (float)$row['net']];
            $sum = [$sum[0] + $row['n'], $sum[1] + $row['received'], $sum[2] + $row['refunded'], $sum[3] + $row['net']];
        }
        $foot = array_merge(['Total'], $sum);
        break;

    case 'cashiers':
        $stmt = $pdo->prepare("SELECT COALESCE(u.name, 'Online payment') name, u.id uid, COUNT(*) n, SUM(so.total_amount) t, SUM(so.item_discount + so.additional_discount) disc,
                (SELECT COALESCE(SUM(sr.total_amount),0) FROM sales_returns sr JOIN sales_orders so2 ON so2.id = sr.sales_order_id WHERE so2.channel = 'pos' AND sr.status = 'completed' AND sr.created_by <=> u.id AND sr.return_date BETWEEN ? AND ?) ret
            FROM sales_orders so LEFT JOIN users u ON u.id = so.created_by
            WHERE so.channel = 'pos' AND so.status <> 'cancelled' AND so.order_date BETWEEN ? AND ?" . $storeSql . ' GROUP BY u.id, u.name ORDER BY t DESC');
        $stmt->execute([$from, $to, $from, $to]);
        $columns = ['Cashier', 'Orders', 'Sales', 'Avg. Order', 'Discounts Given', 'Returns'];
        $sum = [0, 0, 0, 0];
        foreach ($stmt as $row) {
            $rows[] = ['_link' => $row['uid'] ? base_url('pos/orders.php?' . http_build_query(['user' => $row['uid'], 'from' => $from, 'to' => $to])) : null, $row['name'], (int)$row['n'], (float)$row['t'], (float)$row['t'] / max(1, $row['n']), (float)$row['disc'], (float)$row['ret']];
            $sum = [$sum[0] + $row['n'], $sum[1] + $row['t'], $sum[2] + $row['disc'], $sum[3] + $row['ret']];
        }
        $foot = ['Total', $sum[0], $sum[1], $sum[0] ? $sum[1] / $sum[0] : 0, $sum[2], $sum[3]];
        break;

    case 'returns':
        $stmt = $pdo->prepare("SELECT sr.return_no, sr.return_date, so.pos_no, c.name customer, sr.reason, sr.refund_method, sr.exchange_status,
                (SELECT COALESCE(SUM(quantity),0) FROM sales_return_items WHERE sales_return_id = sr.id) qty, sr.total_amount, u.name by_name
            FROM sales_returns sr JOIN sales_orders so ON so.id = sr.sales_order_id JOIN customers c ON c.id = sr.customer_id LEFT JOIN users u ON u.id = sr.created_by
            WHERE so.channel = 'pos' AND sr.status = 'completed' AND sr.return_date BETWEEN ? AND ?" . $storeSql . ' ORDER BY sr.id DESC');
        $stmt->execute([$from, $to]);
        $columns = ['Return No.', 'Date', 'Invoice', 'Customer', 'Reason', 'Refund', 'Qty', 'Amount', 'Processed By'];
        $sum = [0, 0];
        foreach ($stmt as $row) {
            $refund = $row['refund_method'] === 'exchange' ? 'Exchange' : pos_method_label((string)$row['refund_method']);
            $rows[] = [$row['return_no'], $row['return_date'], $row['pos_no'], $row['customer'], (string)$row['reason'], $refund, (int)$row['qty'], (float)$row['total_amount'], (string)$row['by_name']];
            $sum = [$sum[0] + $row['qty'], $sum[1] + $row['total_amount']];
        }
        $foot = ['Total', '', '', '', '', '', $sum[0], $sum[1], ''];
        break;

    case 'tax':
        $stmt = $pdo->prepare("SELECT soi.tax_rate rate, COUNT(DISTINCT so.id) n, SUM(soi.subtotal) taxable, SUM(soi.tax_amount) tax, SUM(soi.line_total) total
            FROM sales_order_items soi JOIN sales_orders so ON so.id = soi.order_id
            WHERE so.channel = 'pos' AND so.status <> 'cancelled' AND so.order_date BETWEEN ? AND ?" . $storeSql . ' GROUP BY soi.tax_rate ORDER BY soi.tax_rate');
        $stmt->execute([$from, $to]);
        $columns = ['GST Rate', 'Invoices', 'Taxable Value', 'CGST', 'SGST', 'Total GST', 'Invoice Value'];
        $sum = [0, 0, 0, 0, 0];
        foreach ($stmt as $row) {
            $half = round((float)$row['tax'] / 2, 2);
            $rows[] = [pos_number($row['rate'], (float)$row['rate'] == floor((float)$row['rate']) ? 0 : 2) . '%', (int)$row['n'], (float)$row['taxable'], $half, (float)$row['tax'] - $half, (float)$row['tax'], (float)$row['total']];
            $sum = [$sum[0] + $row['taxable'], $sum[1] + $half, $sum[2] + $row['tax'] - $half, $sum[3] + $row['tax'], $sum[4] + $row['total']];
        }
        $foot = array_merge(['Total', ''], $sum);
        break;
}
// Money columns: everything numeric that is not a count.
$countCols = ['Orders', 'Qty Sold', 'Returned', 'Transactions', 'Invoices', 'Qty'];

if (input('export') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="pos-' . $r . '-' . $from . '-to-' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, $columns);
    foreach ($rows as $row) {
        unset($row['_link']);
        fputcsv($out, array_map(fn($v) => is_float($v) ? round($v, 2) : $v, array_values($row)));
    }
    if ($foot && $rows) {
        fputcsv($out, array_map(fn($v) => is_float($v) ? round($v, 2) : $v, $foot));
    }
    exit;
}

$sidebar_subnav = [
    'back_url' => 'pos/index.php', 'back_label' => 'Back to POS', 'icon' => 'fa-solid fa-chart-line', 'label' => 'POS Reports',
    'items' => array_map(fn($k) => ['url' => 'pos/reports.php?' . http_build_query(['r' => $k, 'from' => $from, 'to' => $to, 'store' => $store ?: null]), 'icon' => $reports[$k][1], 'label' => $reports[$k][0], 'active' => $k === $r], array_keys($reports)),
];
$qs = fn(array $over) => '?' . http_build_query(array_filter(array_merge(['r' => $r, 'from' => $from, 'to' => $to, 'store' => $store ?: null], $over), fn($v) => $v !== null && $v !== ''));
$delta = function (float $now, float $before, bool $upIsGood = true): string {
    if ($before <= 0) {
        return $now > 0 ? '<span class="d text-success">New this period</span>' : '<span class="d text-muted">No change</span>';
    }
    $pct = ($now - $before) / $before * 100;
    $good = $upIsGood ? $pct >= 0 : $pct <= 0;
    return '<span class="d ' . ($good ? 'text-success' : 'text-danger') . '"><i class="fa-solid fa-arrow-' . ($pct >= 0 ? 'up' : 'down') . '"></i> ' . number_format(abs($pct), 1) . '% vs previous period</span>';
};
$fmt = function ($v, string $col) use ($countCols): string {
    if (is_int($v) || in_array($col, $countCols, true)) {
        return is_numeric($v) ? number_format((float)$v) : e((string)$v);
    }
    return is_float($v) ? e(pos_money($v)) : e((string)$v);
};

$page_title = 'POS Reports';
require __DIR__ . '/../includes/header.php';
?>
<div class="pos-card pos-card-body">
  <form method="get" class="pos-head">
    <input type="hidden" name="r" value="<?= e($r) ?>">
    <h1 class="pos-title"><?= e($reports[$r][0]) ?></h1>
    <div class="d-flex gap-2 flex-wrap align-items-center">
      <?php if (count($ctx['stores']) > 1): ?>
        <select name="store" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
          <option value="">All stores</option>
          <?php foreach ($ctx['stores'] as $s): ?><option value="<?= (int)$s['id'] ?>" <?= $store === (int)$s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
        </select>
      <?php endif; ?>
      <label class="pos-daterange"><i class="fa-regular fa-calendar"></i>
        <input type="date" name="from" value="<?= e($from) ?>" onchange="this.form.submit()"> <span class="text-muted">-</span>
        <input type="date" name="to" value="<?= e($to) ?>" onchange="this.form.submit()"></label>
      <a class="pos-export" href="<?= e($qs(['export' => 'csv'])) ?>"><i class="fa-solid fa-arrow-up-from-bracket"></i> Export</a>
    </div>
  </form>

  <?php if ($r === 'summary'): ?>
    <div class="pos-kpis mb-4">
      <div class="pos-kpi"><div class="l">Total Sales</div><div class="v"><?= e(pos_money($cur['sales'], 0)) ?></div><?= $delta($cur['sales'], $prev['sales']) ?></div>
      <div class="pos-kpi"><div class="l">Total Orders</div><div class="v"><?= number_format($cur['orders']) ?></div><?= $delta($cur['orders'], $prev['orders']) ?></div>
      <div class="pos-kpi"><div class="l">Average Order Value</div><div class="v"><?= e(pos_money($cur['aov'], 0)) ?></div><?= $delta($cur['aov'], $prev['aov']) ?></div>
      <div class="pos-kpi"><div class="l">Total Returns</div><div class="v"><?= e(pos_money($cur['returns'], 0)) ?></div><?= $delta($cur['returns'], $prev['returns'], false) ?></div>
    </div>
    <h2 class="pos-section-title">Sales Summary</h2>
    <div class="pos-chart mb-2"><canvas id="posSalesChart" aria-label="Daily POS sales"></canvas></div>
    <div class="small text-muted mb-4">Compared with <?= e(pos_date($prevFrom)) ?> to <?= e(pos_date($prevTo)) ?>.</div>
    <details>
      <summary class="fw-semibold mb-2">Daily figures</summary>
  <?php endif; ?>

  <div class="table-responsive">
    <table class="table pos-table">
      <thead><tr><?php foreach ($columns as $i => $c): ?><th class="<?= $i > 0 && !in_array($c, ['Item', 'Category', 'Customer', 'Reason', 'Refund', 'Invoice', 'Date', 'Processed By'], true) ? 'text-end' : '' ?>"><?= e($c) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php foreach ($rows as $row): $link = $row['_link'] ?? null; unset($row['_link']); $row = array_values($row); ?>
        <tr>
          <?php foreach ($row as $i => $v): $c = $columns[$i]; ?>
            <td class="<?= $i > 0 && !in_array($c, ['Item', 'Category', 'Customer', 'Reason', 'Refund', 'Invoice', 'Date', 'Processed By'], true) ? 'text-end' : '' ?>">
              <?php if ($i === 0 && $link): ?><a class="no" href="<?= e($link) ?>"><?= e((string)$v) ?></a>
              <?php elseif ($c === 'Date'): ?><?= e(pos_date((string)$v)) ?>
              <?php else: ?><?= $fmt($v, $c) ?><?php endif; ?>
            </td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="<?= count($columns) ?>" class="text-center text-muted py-5">Nothing to show between <?= e(pos_date($from)) ?> and <?= e(pos_date($to)) ?>.</td></tr><?php endif; ?>
      </tbody>
      <?php if ($foot && $rows): ?>
        <tfoot><tr class="fw-bold"><?php foreach ($foot as $i => $v): $c = $columns[$i]; ?><td class="<?= $i > 0 && !in_array($c, ['Item', 'Category', 'Customer', 'Reason', 'Refund', 'Invoice', 'Date', 'Processed By'], true) ? 'text-end' : '' ?>"><?= $v === '' ? '' : ($i === 0 ? e($v) : $fmt(is_int($v) || in_array($c, $countCols, true) ? $v : (float)$v, $c)) ?></td><?php endforeach; ?></tr></tfoot>
      <?php endif; ?>
    </table>
  </div>
  <?php if ($r === 'summary'): ?></details><?php endif; ?>
</div>
<?php
$extra_js = [asset_url('assets/js/pos.js')];
if ($r === 'summary') {
    $extra_js = ['https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js', asset_url('assets/js/pos.js')];
    $labels = array_map(fn($row) => date('d M', strtotime($row[0])), $rows);
    $extra_js_inline = '
(function () {
  var el = document.getElementById("posSalesChart");
  if (!el || !window.Chart) return;
  new Chart(el, {
    type: "bar",
    data: { labels: ' . json_encode($labels) . ', datasets: [{ label: "Sales", data: ' . json_encode(array_column($rows, 2)) . ', backgroundColor: "#2563eb", borderRadius: 4, maxBarThickness: 26 }] },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { return PosUtil.money(c.parsed.y); } } } },
      scales: { x: { grid: { display: false } }, y: { beginAtZero: true, ticks: { callback: function (v) { return v >= 1000 ? (v / 1000) + "K" : v; } } } } }
  });
})();';
}
require __DIR__ . '/../includes/footer.php';
