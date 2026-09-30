<?php
/**
 * Shared list page for the simple Inventory masters. The including page sets
 * $cfg first; see includes/inventory_masters.php for its keys.
 */
require_once __DIR__ . '/../includes/inventory_masters.php';
require_login();
if (!isset($cfg)) {
    http_response_code(404);
    exit;
}

    $canEdit = can_edit_module('inventory');
    $canManage = can_manage_module('inventory');
    $table = $cfg['table'];

    if (is_post() && input('action') === 'delete') {
        require_module_manage('inventory');
        csrf_verify();
        $stmt = db()->prepare("SELECT * FROM $table WHERE id = ?");
        $stmt->execute([(int)input('id')]);
        $row = $stmt->fetch();
        if ($row) {
            $usage = inv_master_usage($cfg, [$row]);
            if (!empty($usage[(int)$row['id']]['products'])) {
                flash('danger', 'Cannot delete: ' . $row['name'] . ' is still used by products.' . ($cfg['status'] ? ' Mark it inactive instead.' : ''));
            } else {
                db()->prepare("DELETE FROM $table WHERE id = ?")->execute([(int)$row['id']]);
                flash('success', $cfg['singular'] . ' deleted.');
            }
        }
        redirect('/inventory/' . $cfg['list']);
    }

    $rows = db()->query("SELECT * FROM $table ORDER BY name")->fetchAll();
    $usage = inv_master_usage($cfg, $rows);
    $statusFilter = $cfg['status'] && in_array(input('status'), ['active', 'inactive'], true) ? input('status') : '';
    $usageFilter = in_array(input('usage'), ['used', 'unused'], true) ? input('usage') : '';

    $counts = ['all' => count($rows), 'active' => 0, 'inactive' => 0, 'used' => 0, 'unused' => 0];
    $maxValue = 1.0;
    foreach ($rows as $r) {
        $u = $usage[(int)$r['id']] ?? null;
        if ($cfg['status']) $counts[$r['status'] === 'active' ? 'active' : 'inactive']++;
        $counts[$u && $u['products'] ? 'used' : 'unused']++;
        $maxValue = max($maxValue, (float)($u['value'] ?? 0));
    }
    $rows = array_values(array_filter($rows, function ($r) use ($statusFilter, $usageFilter, $usage) {
        if ($statusFilter && $r['status'] !== $statusFilter) return false;
        $used = !empty($usage[(int)$r['id']]['products']);
        if ($usageFilter === 'used' && !$used) return false;
        if ($usageFilter === 'unused' && $used) return false;
        return true;
    }));

    $tabs = [['All', 'all', []]];
    if ($cfg['status']) {
        $tabs[] = ['Active', 'active', ['status' => 'active']];
        $tabs[] = ['Inactive', 'inactive', ['status' => 'inactive']];
    }
    $tabs[] = ['In Use', 'used', ['usage' => 'used']];
    $tabs[] = ['Unused', 'unused', ['usage' => 'unused']];
    $currentTab = $statusFilter ?: ($usageFilter ?: 'all');

        $page_title = $cfg['title'];
    require __DIR__ . '/../includes/header.php';
    ?>
<div class="dash">
<div class="dash-head">
  <div>
    <nav class="inv-crumbs" aria-label="Breadcrumb"><a href="index.php">Inventory</a> <i class="fa-solid fa-chevron-right"></i> <span><?= e($cfg['title']) ?></span></nav>
    <h1 class="dash-title"><?= e($cfg['title']) ?></h1>
    <p class="dash-sub"><?= $counts['all'] ?> <?= e(strtolower($counts['all'] === 1 ? $cfg['singular'] : $cfg['title'])) ?> · <?= $counts['used'] ?> in use by products</p>
  </div>
  <?php if ($canEdit): ?>
  <div><a href="<?= e($cfg['form']) ?>" class="btn btn-brand"><i class="fa-solid fa-plus"></i> Add <?= e($cfg['singular']) ?></a></div>
  <?php endif; ?>
</div>

<div class="inv-tabs mb-3">
  <?php foreach ($tabs as [$label, $key, $q]): ?>
    <a href="<?= e($cfg['list'] . ($q ? '?' . http_build_query($q) : '')) ?>" class="inv-tab <?= $currentTab === $key ? 'active' : '' ?>"><?= e($label) ?> <span class="inv-tab-count"><?= (int)$counts[$key] ?></span></a>
  <?php endforeach; ?>
</div>

<div class="dash-card">
  <div class="inv-toolbar">
    <div class="inv-search">
      <i class="fa-solid fa-magnifying-glass"></i>
      <input type="search" class="form-control" placeholder="Search <?= e(strtolower($cfg['title'])) ?>..." data-table-search="#masterTable" aria-label="Search">
    </div>
  </div>
  <div class="table-responsive">
    <table class="table dash-table inv-table mb-0 align-middle" id="masterTable">
      <thead><tr>
        <th>Name</th>
        <?php if ($cfg['description']): ?><th>Description</th><?php endif; ?>
        <th class="text-end">Products</th><th class="text-end">Units in Stock</th><th style="min-width:170px">Stock Value</th>
        <?php if ($cfg['status']): ?><th>Status</th><?php endif; ?>
        <th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
          $u = $usage[(int)$r['id']] ?? ['products' => 0, 'units' => 0, 'value' => 0];
          $inUse = (int)$u['products'] > 0;
          $editUrl = $cfg['form'] . '?id=' . (int)$r['id'];
      ?>
        <tr<?= $canEdit ? ' class="dash-row-link" data-href="' . e($editUrl) . '"' : '' ?>>
          <td>
            <span class="dash-product">
              <span class="inv-avatar"><?= e(strtoupper(mb_substr(trim($r['name']), 0, 1)) ?: '?') ?></span>
              <?php if ($canEdit): ?><a href="<?= e($editUrl) ?>" class="fw-semibold text-body"><?= e($r['name']) ?></a><?php else: ?><span class="fw-semibold"><?= e($r['name']) ?></span><?php endif; ?>
            </span>
          </td>
          <?php if ($cfg['description']): ?><td class="text-muted small"><?= $r['description'] !== null && $r['description'] !== '' ? e($r['description']) : '—' ?></td><?php endif; ?>
          <td class="text-end"><?php if ($inUse): ?><a href="<?= e(inv_master_filter_url($cfg, $r)) ?>" class="dash-link"><?= (int)$u['products'] ?></a><?php else: ?><span class="text-muted">0</span><?php endif; ?></td>
          <td class="text-end"><?= number_format((int)$u['units']) ?></td>
          <td>
            <div class="small fw-semibold"><?= money($u['value']) ?></div>
            <div class="inv-bar"><span style="width: <?= round((float)$u['value'] / $maxValue * 100, 1) ?>%"></span></div>
          </td>
          <?php if ($cfg['status']): ?><td><span class="dash-pill <?= $r['status'] === 'active' ? 'dash-pill-green' : 'dash-pill-gray' ?>"><?= $r['status'] === 'active' ? 'Active' : 'Inactive' ?></span></td><?php endif; ?>
          <td class="text-end text-nowrap">
            <?php if ($canEdit): ?><a href="<?= e($editUrl) ?>" class="btn btn-sm btn-light inv-icon-btn" title="Edit"><i class="fa-solid fa-pen"></i></a><?php endif; ?>
            <?php if ($canManage): ?>
              <?php if ($inUse): ?>
                <span class="d-inline-block" tabindex="0" title="Used by <?= (int)$u['products'] ?> product<?= (int)$u['products'] === 1 ? '' : 's' ?>, so it can't be deleted"><button class="btn btn-sm btn-light inv-icon-btn" type="button" disabled><i class="fa-solid fa-trash"></i></button></span>
              <?php else: ?>
                <form method="post" class="d-inline" data-confirm="Delete <?= e($r['name']) ?>?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button class="btn btn-sm btn-light inv-icon-btn inv-icon-danger" type="submit" title="Delete"><i class="fa-solid fa-trash"></i></button>
                </form>
              <?php endif; ?>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?>
        <tr><td colspan="8" class="empty-state">
          <i class="<?= e($cfg['icon']) ?>"></i>
          <div><?= $counts['all'] ? 'Nothing matches this view.' : 'No ' . e(strtolower($cfg['title'])) . ' yet.' ?></div>
          <?php if (!$counts['all'] && $canEdit): ?><a href="<?= e($cfg['form']) ?>" class="btn btn-brand btn-sm mt-2"><i class="fa-solid fa-plus"></i> Add <?= e(strtolower($cfg['singular'])) ?></a><?php endif; ?>
        </td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
</div>
    <?php
        $extra_js = [asset_url('assets/js/inventory.js')];
    $extra_js_inline = 'invRowLinks();';
    require __DIR__ . '/../includes/footer.php';
