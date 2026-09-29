<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
$page_title = 'Journal Entries';
fin_require_schema();

$pdo = db();
$types = fin_voucher_types();
$typeFilter = isset($types[input('type')]) ? input('type') : '';
$statusFilter = in_array(input('status'), ['draft', 'submitted', 'cancelled'], true) ? input('status') : '';
$q = trim((string)input('q'));

$where = ['1=1'];
$params = [];
if ($typeFilter) { $where[] = 'j.voucher_type = ?'; $params[] = $typeFilter; }
if ($statusFilter) { $where[] = 'j.status = ?'; $params[] = $statusFilter; }
if ($q !== '') { $where[] = '(j.voucher_no LIKE ? OR j.party_name LIKE ? OR j.narration LIKE ? OR j.reference_no LIKE ?)'; array_push($params, "%$q%", "%$q%", "%$q%", "%$q%"); }
$base = 'FROM fin_journal_entries j WHERE ' . implode(' AND ', $where);
[$pageNo, $perPage, $offset] = fin_page(15);
$c = $pdo->prepare("SELECT COUNT(*) $base");
$c->execute($params);
$total = (int)$c->fetchColumn();
$stmt = $pdo->prepare("SELECT j.* $base ORDER BY j.posting_date DESC, j.id DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$entries = $stmt->fetchAll();
$statusPill = ['draft' => ['Draft', 'secondary'], 'submitted' => ['Submitted', 'success'], 'cancelled' => ['Cancelled', 'danger']];

require __DIR__ . '/../includes/header.php';
fin_page_head('Journal Entries', 'Journal vouchers, bank and cash payments, receipts and transfers.',
    can_edit_module('finance') ? '<div class="btn-row"><div class="dropdown"><button class="btn btn-brand dropdown-toggle" data-bs-toggle="dropdown"><i class="fa-solid fa-plus"></i> New Voucher</button><ul class="dropdown-menu dropdown-menu-end">'
        . implode('', array_map(fn($k, $l) => '<li><a class="dropdown-item" href="journal_form.php?type=' . $k . '">' . e($l) . '</a></li>', array_keys($types), $types)) . '</ul></div></div>' : '');
?>
<div class="fin-card">
  <form method="get" class="d-flex gap-2 flex-wrap mb-3">
    <div class="fin-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" name="q" value="<?= e($q) ?>" class="form-control" placeholder="Search voucher no., party, narration..."></div>
    <select name="type" class="form-select" style="width:auto" onchange="this.form.submit()"><option value="">All types</option>
      <?php foreach ($types as $k => $l): ?><option value="<?= $k ?>" <?= $typeFilter === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
    <select name="status" class="form-select" style="width:auto" onchange="this.form.submit()"><option value="">All statuses</option>
      <?php foreach ($statusPill as $k => $p): ?><option value="<?= $k ?>" <?= $statusFilter === $k ? 'selected' : '' ?>><?= $p[0] ?></option><?php endforeach; ?></select>
  </form>
  <div class="table-responsive">
    <table class="table fin-table">
      <thead><tr><th>Date</th><th>Voucher No.</th><th>Type</th><th>Party</th><th>Narration</th><th class="text-end">Amount (<?= e(setting('currency_symbol', '₹')) ?>)</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($entries as $j): ?>
        <tr>
          <td class="text-nowrap"><?= fin_date($j['posting_date']) ?></td>
          <td><a href="journal_view.php?id=<?= (int)$j['id'] ?>"><?= e($j['voucher_no']) ?></a></td>
          <td><?= e($types[$j['voucher_type']] ?? $j['voucher_type']) ?></td>
          <td><?= e($j['party_name'] ?: '—') ?></td>
          <td><?= e($j['narration']) ?></td>
          <td class="text-end"><?= fin_num($j['total_debit']) ?></td>
          <td><?= fin_pill($statusPill[$j['status']][0], $statusPill[$j['status']][1]) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$entries): ?><tr><td colspan="7" class="empty-state"><i class="fa-solid fa-book-open"></i>No vouchers yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= fin_pagination($total, $pageNo, $perPage, 'vouchers') ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
