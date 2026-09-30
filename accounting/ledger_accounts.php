<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
$page_title = 'Chart of Accounts';
fin_require_schema();
$canEdit = can_edit_module('finance');
$canManage = can_manage_module('finance');
$pdo = db();

if (is_post() && input('action') === 'delete') {
    require_module_manage('finance');
    csrf_verify();
    $id = (int)input('id');
    $acct = fin_accounts()[$id] ?? null;
    $checks = [
        'SELECT COUNT(*) FROM ledger_accounts WHERE parent_id = ?' => 'it has child accounts',
        'SELECT COUNT(*) FROM fin_journal_lines WHERE account_id = ?' => 'journal entries post to it',
        'SELECT COUNT(*) FROM expenses WHERE account_id = ? OR paid_from_account_id = ?' => 'expenses use it',
        'SELECT COUNT(*) FROM sales_order_taxes WHERE account_head_id = ?' => 'sales orders use it',
        'SELECT COUNT(*) FROM purchase_order_taxes WHERE account_head_id = ?' => 'purchase orders use it',
    ];
    $blocked = null;
    if (!$acct) {
        $blocked = 'account not found';
    } elseif ($acct['system_key']) {
        $blocked = 'it is a system account the ledger posts to';
    } else {
        foreach ($checks as $sql => $why) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_fill(0, substr_count($sql, '?'), $id));
            if ($stmt->fetchColumn() > 0) { $blocked = $why; break; }
        }
    }
    if ($blocked) {
        flash('danger', "Cannot delete: $blocked.");
    } else {
        try {
            $pdo->prepare('DELETE FROM ledger_accounts WHERE id = ?')->execute([$id]);
            log_activity('ledger_account', $id, 'deleted', 'Deleted account ' . $acct['name']);
            flash('success', 'Account deleted.');
        } catch (PDOException $e) {
            flash('danger', 'Cannot delete: this account is used elsewhere. Mark it inactive instead.');
        }
    }
    redirect('/accounting/ledger_accounts.php');
}

$accounts = fin_accounts();
$balances = fin_balances();
$rootTypes = fin_root_types();
$typeFilter = isset($rootTypes[input('type')]) ? input('type') : '';
$statusFilter = in_array(input('status'), ['active', 'inactive'], true) ? input('status') : '';
$q = trim((string)input('q'));

// Build the tree (children sorted by code), then flatten depth-first.
$children = [];
foreach ($accounts as $id => $a) {
    $pid = (int)$a['parent_id'];
    if ($pid && !isset($accounts[$pid])) $pid = 0;
    $children[$pid][] = $id;
}
$rootOrder = array_flip(array_keys($rootTypes));
if (isset($children[0])) {
    usort($children[0], function ($x, $y) use ($accounts, $rootOrder) {
        $rx = $rootOrder[$accounts[$x]['root_type']] ?? 9;
        $ry = $rootOrder[$accounts[$y]['root_type']] ?? 9;
        return [$rx, !$accounts[$x]['is_group'], $accounts[$x]['account_code'] ?? 'zz', $accounts[$x]['name']] <=> [$ry, !$accounts[$y]['is_group'], $accounts[$y]['account_code'] ?? 'zz', $accounts[$y]['name']];
    });
}
$rows = [];
$walk = function (int $pid, int $depth) use (&$walk, &$rows, $children) {
    foreach ($children[$pid] ?? [] as $id) {
        $rows[] = [$id, $depth];
        $walk($id, $depth + 1);
    }
};
$walk(0, 0);

// Filtering keeps matches plus their ancestors, so the tree still reads.
$filtering = $q !== '' || $typeFilter || $statusFilter;
$visible = [];
if ($filtering) {
    foreach ($accounts as $id => $a) {
        $hit = (!$typeFilter || $a['root_type'] === $typeFilter) && (!$statusFilter || $a['status'] === $statusFilter)
            && ($q === '' || stripos($a['name'] . ' ' . $a['account_code'] . ' ' . $a['description'], $q) !== false);
        if (!$hit) continue;
        $cur = $id;
        $guard = 0;
        while ($cur && isset($accounts[$cur]) && $guard++ < 50) {
            $visible[$cur] = true;
            $cur = (int)$accounts[$cur]['parent_id'];
        }
    }
    $rows = array_values(array_filter($rows, fn($r) => isset($visible[$r[0]])));
}

if (input('export') === 'csv') {
    $out = [];
    foreach ($rows as [$id, $depth]) {
        $a = $accounts[$id];
        $out[] = [$a['account_code'], str_repeat('  ', $depth) . $a['name'], $a['is_group'] ? 'Group' : 'Ledger', $rootTypes[$a['root_type']] ?? '', fin_account_types()[$a['account_type']] ?? $a['account_type'], round($balances[$id], 2), $a['status']];
    }
    fin_csv('chart-of-accounts.csv', ['Account Code', 'Account Name', 'Kind', 'Root Type', 'Account Type', 'Balance (Dr - Cr)', 'Status'], $out);
}

require __DIR__ . '/../includes/header.php';
$exportUrl = '?' . e(http_build_query(array_merge($_GET, ['export' => 'csv'])));
fin_page_head('Chart of Accounts', "View and manage your organization's account structure.",
    '<div class="btn-row"><a href="' . $exportUrl . '" class="btn btn-outline-secondary"><i class="fa-solid fa-download"></i> Export</a>'
    . ($canEdit ? '<a href="ledger_account_form.php" class="btn btn-brand"><i class="fa-solid fa-plus"></i> Create Ledger</a>' : '') . '</div>');
?>
<div class="fin-card">
  <form method="get" class="d-flex gap-2 flex-wrap mb-3">
    <div class="fin-search flex-grow-1" style="max-width:520px"><i class="fa-solid fa-magnifying-glass"></i><input type="search" name="q" value="<?= e($q) ?>" class="form-control" placeholder="Search by account name, code or description..."></div>
    <select name="type" class="form-select" style="width:auto" onchange="this.form.submit()">
      <option value="">All Account Types</option>
      <?php foreach ($rootTypes as $k => $l): ?><option value="<?= $k ?>" <?= $typeFilter === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
    </select>
    <select name="status" class="form-select" style="width:auto" onchange="this.form.submit()">
      <option value="">All Statuses</option>
      <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
      <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
    </select>
    <button class="btn btn-outline-brand ms-auto"><i class="fa-solid fa-filter"></i> Filter</button>
  </form>
  <div class="table-responsive">
    <table class="table fin-table" id="coaTable">
      <thead><tr><th>Account Name</th><th>Account Code</th><th>Account Type</th><th class="text-end">Balance (<?= e(setting('currency_symbol', '₹')) ?>)</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($rows as [$id, $depth]): $a = $accounts[$id]; $hasKids = !empty($children[$id]); ?>
        <tr data-id="<?= $id ?>" data-parent="<?= (int)$a['parent_id'] ?>">
          <td style="padding-left:<?= 12 + $depth * 28 ?>px">
            <span class="fin-tree-name">
              <?php if ($hasKids): ?><button type="button" class="fin-tree-toggle js-toggle"><i class="fa-solid fa-chevron-down"></i></button><?php else: ?><span style="width:20px;display:inline-block"></span><?php endif; ?>
              <i class="fa-solid <?= $a['is_group'] ? 'fa-folder' : 'fa-file-lines' ?>"></i>
              <?php if ($a['is_group']): ?><strong><?= e($a['name']) ?></strong><?php else: ?><a class="text-reset" href="general_ledger.php?tab=all&account=<?= $id ?>"><?= e($a['name']) ?></a><?php endif; ?>
            </span>
          </td>
          <td><?= e($a['account_code'] ?: '—') ?></td>
          <td><?= $a['is_group'] ? fin_pill('Group', 'info') : fin_pill('Ledger', 'secondary') ?></td>
          <td class="text-end"><?= fin_amt($balances[$id]) ?></td>
          <td><?= $a['status'] === 'active' ? fin_pill('Active', 'success') : fin_pill('Inactive', 'secondary') ?></td>
          <td class="text-end">
            <div class="dropdown">
              <button class="btn btn-sm btn-link text-secondary" data-bs-toggle="dropdown"><i class="fa-solid fa-ellipsis"></i></button>
              <ul class="dropdown-menu dropdown-menu-end">
                <?php if ($canEdit): ?><li><a class="dropdown-item" href="ledger_account_form.php?id=<?= $id ?>"><i class="fa-solid fa-pen"></i> Edit</a></li><?php endif; ?>
                <?php if ($canEdit && $a['is_group']): ?><li><a class="dropdown-item" href="ledger_account_form.php?parent=<?= $id ?>"><i class="fa-solid fa-plus"></i> Add Account Under</a></li><?php endif; ?>
                <?php if (!$a['is_group']): ?><li><a class="dropdown-item" href="general_ledger.php?tab=all&account=<?= $id ?>"><i class="fa-solid fa-book"></i> View Ledger</a></li><?php endif; ?>
                <?php if ($canManage && !$a['system_key']): ?>
                <li><form method="post" data-confirm="Delete this account?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $id ?>">
                  <button class="dropdown-item text-danger"><i class="fa-solid fa-trash"></i> Delete</button></form></li>
                <?php endif; ?>
              </ul>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="6" class="empty-state"><i class="fa-solid fa-folder-open"></i>No accounts match.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="fin-pager"><div class="text-muted small">Showing <?= count($rows) ?> of <?= count($accounts) ?> accounts</div></div>
</div>
<?php
$extra_js_inline = "
document.addEventListener('DOMContentLoaded', function () {
  var rows = Array.prototype.slice.call(document.querySelectorAll('#coaTable tbody tr[data-id]'));
  function descendants(id) {
    var out = [];
    rows.forEach(function (r) { if (r.dataset.parent === id) { out.push(r); out = out.concat(descendants(r.dataset.id)); } });
    return out;
  }
  document.querySelectorAll('.js-toggle').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var tr = btn.closest('tr');
      var collapsed = tr.classList.toggle('collapsed');
      btn.querySelector('i').className = 'fa-solid ' + (collapsed ? 'fa-chevron-right' : 'fa-chevron-down');
      descendants(tr.dataset.id).forEach(function (r) {
        if (collapsed) { r.style.display = 'none'; }
        else {
          // Only show rows whose ancestors are all expanded.
          var p = r.dataset.parent, hidden = false;
          while (p && p !== '0') { var pr = document.querySelector('tr[data-id=\"' + p + '\"]'); if (!pr) break; if (pr.classList.contains('collapsed')) { hidden = true; break; } p = pr.dataset.parent; }
          r.style.display = hidden ? 'none' : '';
        }
      });
    });
  });
});";
require __DIR__ . '/../includes/footer.php';
