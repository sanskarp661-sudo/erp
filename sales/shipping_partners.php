<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
$canEdit = can_edit_module('sales');
$canManage = can_manage_module('sales');

if (is_post() && input('action') === 'delete') {
    require_module_manage('sales');
    csrf_verify();
    $id = (int)input('id');
    $stmt = db()->prepare('SELECT COUNT(*) FROM sales_orders WHERE shipping_partner_id = ?');
    $stmt->execute([$id]);
    if ($stmt->fetchColumn() > 0) {
        flash('danger', 'Cannot delete: this shipping partner is used by existing sales orders.');
    } else {
        db()->prepare('DELETE FROM shipping_partners WHERE id = ?')->execute([$id]);
        flash('success', 'Shipping partner deleted.');
    }
    redirect('/sales/shipping_partners.php');
}

if (is_post() && input('action') === 'save') {
    require_module_edit('sales');
    csrf_verify();
    $name = input('name');
    if ($name !== '') {
        db()->prepare('INSERT INTO shipping_partners (name) VALUES (?)')->execute([$name]);
        flash('success', 'Shipping partner added.');
    }
    redirect('/sales/shipping_partners.php');
}

$partners = db()->query('SELECT * FROM shipping_partners ORDER BY name')->fetchAll();

$page_title = 'Shipping Partners';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <input type="text" class="form-control" style="max-width:260px" placeholder="Search shipping partners..." data-table-search="#spTable">
</div>

<?php if ($canEdit): ?>
<div class="card p-3 mb-3">
  <form method="post" class="row g-2 align-items-end">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <div class="col-sm-4">
      <label class="form-label">New Shipping Partner</label>
      <input type="text" name="name" class="form-control" placeholder="e.g. Delhivery" required>
    </div>
    <div class="col-sm-auto"><button type="submit" class="btn btn-brand"><i class="fa-solid fa-plus"></i> Add</button></div>
  </form>
</div>
<?php endif; ?>

<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-hover" id="spTable">
      <thead><tr><th>Name</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($partners as $p): ?>
        <tr>
          <td><?= e($p['name']) ?></td>
          <td><span class="badge text-bg-<?= $p['status'] === 'active' ? 'success' : 'secondary' ?> badge-status"><?= e($p['status']) ?></span></td>
          <td class="text-end">
            <?php if ($canManage): ?>
            <form method="post" class="d-inline" data-confirm="Delete this shipping partner?">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
              <button class="btn btn-sm btn-outline-danger" type="submit"><i class="fa-solid fa-trash"></i></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$partners): ?><tr><td colspan="3" class="text-muted text-center">No shipping partners yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
