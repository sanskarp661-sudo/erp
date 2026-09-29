<?php
require_once __DIR__ . '/../includes/auth.php';
$ctx = pos_page();
$pdo = db();

if (is_post() && input('action') === 'delete') {
    require_module_edit('pos');
    csrf_verify();
    if (!pos_can('delete_held')) {
        flash('danger', 'Only a POS manager can delete held orders.');
    } else {
        $stmt = $pdo->prepare('SELECT hold_no FROM pos_held_orders WHERE id = ?');
        $stmt->execute([(int)input('id')]);
        if ($no = $stmt->fetchColumn()) {
            $pdo->prepare('DELETE FROM pos_held_orders WHERE id = ?')->execute([(int)input('id')]);
            flash('success', 'Held order ' . $no . ' deleted.');
        }
    }
    redirect('/pos/held.php');
}

$sort = in_array(input('sort'), ['date', 'customer', 'amount'], true) ? input('sort') : 'date';
$dir = input('dir') === 'asc' ? 'ASC' : 'DESC';
$orderBy = ['date' => 'h.created_at', 'customer' => 'c.name', 'amount' => 'h.amount'][$sort] . ' ' . $dir . ', h.id DESC';
$scope = input('scope') === 'all' ? 'all' : 'terminal';
$where = $scope === 'terminal' && $ctx['profile_id'] ? ' WHERE h.pos_profile_id = ' . (int)$ctx['profile_id'] : '';
$held = $pdo->query("SELECT h.*, c.name customer_name, u.name created_by_name, pp.name terminal_name FROM pos_held_orders h
    LEFT JOIN customers c ON c.id = h.customer_id LEFT JOIN users u ON u.id = h.created_by LEFT JOIN pos_profiles pp ON pp.id = h.pos_profile_id
    $where ORDER BY $orderBy")->fetchAll();

$products = [];
foreach ($pdo->query('SELECT id, name FROM products') as $p) {
    $products[(int)$p['id']] = $p['name'];
}
$sortLink = function (string $key, string $label) use ($sort, $dir, $scope): string {
    $next = $sort === $key && $dir === 'DESC' ? 'asc' : 'desc';
    $arrow = $sort === $key ? ($dir === 'DESC' ? ' <i class="fa-solid fa-arrow-down-long"></i>' : ' <i class="fa-solid fa-arrow-up-long"></i>') : '';
    return '<a href="?sort=' . $key . '&dir=' . $next . '&scope=' . $scope . '">' . e($label) . $arrow . '</a>';
};

$page_title = 'Held Orders';
require __DIR__ . '/../includes/header.php';
?>
<div class="pos-card pos-card-body">
  <div class="pos-head">
    <h1 class="pos-title">Held Orders</h1>
    <div class="d-flex gap-2 align-items-center">
      <div class="pos-tabs">
        <a class="pos-tab <?= $scope === 'terminal' ? 'active' : '' ?>" href="?scope=terminal">This terminal</a>
        <a class="pos-tab <?= $scope === 'all' ? 'active' : '' ?>" href="?scope=all">All terminals</a>
      </div>
      <a href="<?= base_url('pos/index.php') ?>" class="btn btn-brand"><i class="fa-solid fa-plus"></i> New Sale</a>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table pos-table">
      <thead><tr><th>Order No.</th><th><?= $sortLink('date', 'Date & Time') ?></th><th><?= $sortLink('customer', 'Customer') ?></th><th>Items</th><th><?= $sortLink('amount', 'Amount') ?></th><th>Actions</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($held as $h): $cart = json_decode($h['cart_json'], true) ?: []; ?>
        <tr>
          <td><a class="no" href="<?= base_url('pos/index.php?resume=' . (int)$h['id']) ?>"><?= e($h['hold_no']) ?></a></td>
          <td><?= e(pos_datetime($h['created_at'])) ?></td>
          <td><?= e($h['customer_name'] ?? 'Walk-in Customer') ?></td>
          <td class="<?= (int)$h['items_count'] >= 4 ? 'text-danger' : '' ?>"><?= (int)$h['items_count'] ?></td>
          <td><?= e(pos_money($h['amount'], 0)) ?></td>
          <td><a href="<?= base_url('pos/index.php?resume=' . (int)$h['id']) ?>" class="pos-act"><i class="fa-solid fa-rotate"></i> Resume</a></td>
          <td class="text-end">
            <div class="dropdown">
              <button class="pos-dots" type="button" data-bs-toggle="dropdown" aria-label="More actions"><i class="fa-solid fa-ellipsis"></i></button>
              <div class="dropdown-menu dropdown-menu-end p-2" style="min-width:280px">
                <div class="small text-muted px-2">Held by <?= e($h['created_by_name'] ?? '-') ?> on <?= e($h['terminal_name'] ?? '-') ?></div>
                <ul class="list-unstyled small px-2 my-2">
                  <?php foreach ((array)($cart['lines'] ?? []) as $l): ?>
                    <li><?= (int)($l['qty'] ?? 0) ?> × <?= e($products[(int)($l['product_id'] ?? 0)] ?? 'Removed product') ?></li>
                  <?php endforeach; ?>
                </ul>
                <a class="dropdown-item rounded" href="<?= base_url('pos/index.php?resume=' . (int)$h['id']) ?>"><i class="fa-solid fa-rotate"></i> Resume</a>
                <?php if (pos_can('delete_held')): ?>
                  <form method="post" class="m-0"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$h['id'] ?>">
                    <button class="dropdown-item rounded text-danger" data-confirm="Delete held order <?= e($h['hold_no']) ?>?"><i class="fa-regular fa-trash-can"></i> Delete</button></form>
                <?php endif; ?>
              </div>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$held): ?><tr><td colspan="7" class="text-center text-muted py-5"><i class="fa-regular fa-file-lines fa-2x d-block mb-2 opacity-50"></i>No held orders. Use Hold on New Sale to park a cart.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php
$extra_js = [asset_url('assets/js/pos.js')];
require __DIR__ . '/../includes/footer.php';
