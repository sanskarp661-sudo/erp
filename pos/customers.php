<?php
require_once __DIR__ . '/../includes/auth.php';
$ctx = pos_page();
$pdo = db();

$q = trim((string)input('q'));
$perPage = 25;
$page = max(1, (int)input('page'));
$where = " WHERE c.status <> 'blocked'";
$params = [];
if ($q !== '') {
    $where .= ' AND (c.name LIKE ? OR c.mobile LIKE ? OR c.phone LIKE ? OR c.email LIKE ? OR c.gstin LIKE ?)';
    $params = array_fill(0, 5, '%' . $q . '%');
}
$cnt = $pdo->prepare('SELECT COUNT(*) FROM customers c' . $where);
$cnt->execute($params);
$total = (int)$cnt->fetchColumn();

// POS customers first (most recent buyers), then everyone else by name.
$stmt = $pdo->prepare("SELECT c.id, c.name, c.mobile, c.phone, c.email, c.customer_type, c.customer_group, c.gstin, c.credit_limit, c.address,
        COALESCE(s.orders, 0) orders, COALESCE(s.spent, 0) spent, s.last_at,
        (SELECT COALESCE(SUM(i.total - i.amount_paid), 0) FROM invoices i WHERE i.customer_id = c.id AND i.status IN ('unpaid','partially_paid','overdue')) due
    FROM customers c
    LEFT JOIN (SELECT customer_id, COUNT(*) orders, SUM(total_amount) spent, MAX(created_at) last_at FROM sales_orders WHERE channel = 'pos' AND status <> 'cancelled' GROUP BY customer_id) s ON s.customer_id = c.id
    $where ORDER BY (c.name = 'Walk-in Customer') DESC, (s.last_at IS NULL), s.last_at DESC, c.name LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
$stmt->execute($params);
$rows = $stmt->fetchAll();
$pages = max(1, (int)ceil($total / $perPage));
$defaultId = (int)pos_setting('pos_default_customer_id');
$canEdit = can_edit_module('pos');

$page_title = 'POS Customers';
require __DIR__ . '/../includes/header.php';
?>
<div class="pos-card pos-card-body">
  <div class="pos-head">
    <h1 class="pos-title">Customers</h1>
    <div class="d-flex gap-2 align-items-center flex-wrap">
      <form method="get" class="inv-search" style="min-width:280px"><i class="fa-solid fa-magnifying-glass"></i><input type="search" name="q" class="form-control" value="<?= e($q) ?>" placeholder="Name, mobile, email or GSTIN"></form>
      <?php if ($canEdit): ?><button type="button" class="btn btn-brand" id="pcuNew"><i class="fa-solid fa-plus"></i> New Customer</button><?php endif; ?>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table pos-table">
      <thead><tr><th>Customer</th><th>Mobile</th><th>Group</th><th class="text-end">Orders</th><th class="text-end">Total Spent</th><th class="text-end">Balance Due</th><th>Last Visit</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $c):
          $json = ['id' => (int)$c['id'], 'name' => $c['name'], 'mobile' => $c['mobile'] ?: $c['phone'], 'email' => $c['email'], 'address' => $c['address'], 'gstin' => $c['gstin'], 'group' => $c['customer_group'], 'credit' => $c['credit_limit'], 'type' => $c['customer_type']];
          $walkIn = $c['name'] === 'Walk-in Customer'; ?>
        <tr>
          <td>
            <div class="fw-semibold"><?= e($c['name']) ?><?php if ((int)$c['id'] === $defaultId): ?> <span class="pos-pill blue">Default</span><?php endif; ?></div>
            <div class="small text-muted"><?= e($c['email'] ?? '') ?><?= $c['gstin'] ? ' · GSTIN ' . e($c['gstin']) : '' ?></div>
          </td>
          <td><?= e($c['mobile'] ?: ($c['phone'] ?? '')) ?></td>
          <td><?= e($c['customer_group'] ?? '') ?></td>
          <td class="text-end"><?= (int)$c['orders'] ?></td>
          <td class="text-end"><?= e(pos_money($c['spent'])) ?></td>
          <td class="text-end <?= (float)$c['due'] > 0.009 ? 'text-danger fw-semibold' : 'text-muted' ?>"><?= e(pos_money($c['due'])) ?></td>
          <td><?= $c['last_at'] ? e(pos_date($c['last_at'])) : '<span class="text-muted">-</span>' ?></td>
          <td class="text-end text-nowrap">
            <?php if ((int)$c['orders'] > 0): ?><a class="pos-act me-2" href="<?= base_url('pos/orders.php?' . http_build_query(['q' => $c['mobile'] ?: $c['name'], 'from' => '2000-01-01'])) ?>">Orders</a><?php endif; ?>
            <?php if ($canEdit && !$walkIn): ?><button type="button" class="pos-act border-0 bg-transparent me-2 pcu-edit" data-c="<?= e(json_encode($json)) ?>">Edit</button><?php endif; ?>
            <?php if ($canEdit): ?><a class="pos-act" href="<?= base_url('pos/index.php?customer=' . (int)$c['id']) ?>"><i class="fa-solid fa-cart-shopping"></i> New Sale</a><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-5">No customers match "<?= e($q) ?>".</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages > 1): ?>
    <nav class="d-flex justify-content-end"><ul class="pagination pagination-sm mb-0">
      <?php for ($i = max(1, $page - 3); $i <= min($pages, $page + 3); $i++): ?>
        <li class="page-item <?= $i === $page ? 'active' : '' ?>"><a class="page-link" href="?<?= e(http_build_query(['q' => $q, 'page' => $i])) ?>"><?= $i ?></a></li>
      <?php endfor; ?>
    </ul></nav>
  <?php endif; ?>
</div>
<?php if ($canEdit) {
    require __DIR__ . '/_customer_modal.php';
} ?>
<?php
$extra_js = [asset_url('assets/js/pos.js')];
$extra_js_inline = $canEdit ? '
PosCustomer.init(' . json_encode(base_url('pos/api.php')) . ', ' . json_encode(csrf_token()) . ');
function pcuSaved() { window.location.reload(); }
document.getElementById("pcuNew").addEventListener("click", function () { PosCustomer.open(null, pcuSaved); });
document.querySelectorAll(".pcu-edit").forEach(function (b) { b.addEventListener("click", function () { PosCustomer.open(JSON.parse(b.dataset.c), pcuSaved); }); });
' : '';
require __DIR__ . '/../includes/footer.php';
