<?php
require_once __DIR__ . '/../includes/auth.php';
require_module_edit('sales');

$id = (int)input('id');
$template = ['id' => 0, 'name' => '', 'status' => 'active'];
$rows = [];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM tax_templates WHERE id = ?');
    $stmt->execute([$id]);
    $template = $stmt->fetch() ?: $template;
    $stmt = db()->prepare('SELECT * FROM tax_template_items WHERE tax_template_id = ? ORDER BY sort_order, id');
    $stmt->execute([$id]);
    $rows = $stmt->fetchAll();
}

$error = '';

if (is_post()) {
    csrf_verify();
    $name = input('name');
    $status = input('status') === 'inactive' ? 'inactive' : 'active';
    $types = $_POST['row_type'] ?? [];
    $accountIds = $_POST['row_account_head_id'] ?? [];
    $descriptions = $_POST['row_description'] ?? [];
    $basedOns = $_POST['row_based_on'] ?? [];
    $rateValues = $_POST['row_rate_or_amount'] ?? [];

    if ($name === '') {
        $error = 'Name is required.';
    } else {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($id) {
                $pdo->prepare('UPDATE tax_templates SET name=?, status=? WHERE id=?')->execute([$name, $status, $id]);
                $pdo->prepare('DELETE FROM tax_template_items WHERE tax_template_id=?')->execute([$id]);
                $ttId = $id;
            } else {
                $pdo->prepare('INSERT INTO tax_templates (name, status) VALUES (?,?)')->execute([$name, $status]);
                $ttId = (int)$pdo->lastInsertId();
            }
            $rowStmt = $pdo->prepare('INSERT INTO tax_template_items (tax_template_id, type, account_head_id, description, based_on, rate_or_amount, sort_order) VALUES (?,?,?,?,?,?,?)');
            $sort = 0;
            foreach ($descriptions as $i => $desc) {
                $desc = trim($desc);
                $rate = (float)($rateValues[$i] ?? 0);
                if ($desc === '' && $rate == 0) {
                    continue;
                }
                $type = in_array($types[$i] ?? '', ['on_item', 'on_order'], true) ? $types[$i] : 'on_item';
                $basedOn = in_array($basedOns[$i] ?? '', ['net_amount', 'actual_amount'], true) ? $basedOns[$i] : 'net_amount';
                $accountId = (int)($accountIds[$i] ?? 0) ?: null;
                $rowStmt->execute([$ttId, $type, $accountId, $desc, $basedOn, $rate, $sort++]);
            }
            $pdo->commit();
            flash('success', $id ? 'Tax template updated.' : 'Tax template created.');
            redirect('/sales/tax_templates.php');
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Could not save tax template.';
        }
    }
    $template = ['id' => $id, 'name' => $name, 'status' => $status];
}

$accounts = db()->query("SELECT id, name, account_type FROM ledger_accounts WHERE status='active' ORDER BY account_type, name")->fetchAll();

$page_title = $id ? 'Edit Tax Template' : 'Add Tax Template';
require __DIR__ . '/../includes/header.php';
?>
<div class="card p-4">
  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $id ?>">
    <div class="row g-3 mb-3">
      <div class="col-sm-6">
        <label class="form-label">Name</label>
        <input type="text" name="name" class="form-control" required value="<?= e($template['name']) ?>">
      </div>
      <div class="col-sm-3">
        <label class="form-label">Status</label>
        <select name="status" class="form-select">
          <option value="active" <?= $template['status'] === 'active' ? 'selected' : '' ?>>Active</option>
          <option value="inactive" <?= $template['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>
      </div>
    </div>

    <h6 class="mb-2">Taxes and Charges Table</h6>
    <div class="tt-rows">
      <div class="table-responsive mb-2">
        <table class="table table-sm">
          <thead><tr><th>Type</th><th>Account Head</th><th>Description</th><th>Based On</th><th style="width:130px">Rate / Amount</th><th></th></tr></thead>
          <tbody>
          <?php if (!$rows): $rows = [['type' => 'on_item', 'account_head_id' => '', 'description' => '', 'based_on' => 'net_amount', 'rate_or_amount' => 0]]; endif; ?>
          <?php foreach ($rows as $r): ?>
            <tr data-row>
              <td>
                <select class="form-select form-select-sm" name="row_type[]">
                  <option value="on_item" <?= $r['type'] === 'on_item' ? 'selected' : '' ?>>On Item</option>
                  <option value="on_order" <?= $r['type'] === 'on_order' ? 'selected' : '' ?>>On Order</option>
                </select>
              </td>
              <td>
                <select class="form-select form-select-sm" name="row_account_head_id[]">
                  <option value="">— None —</option>
                  <?php foreach ($accounts as $a): ?>
                    <option value="<?= (int)$a['id'] ?>" <?= (string)($r['account_head_id'] ?? '') === (string)$a['id'] ? 'selected' : '' ?>><?= e($a['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td><input type="text" class="form-control form-control-sm" name="row_description[]" value="<?= e($r['description'] ?? '') ?>"></td>
              <td>
                <select class="form-select form-select-sm" name="row_based_on[]">
                  <option value="net_amount" <?= $r['based_on'] === 'net_amount' ? 'selected' : '' ?>>Net Amount</option>
                  <option value="actual_amount" <?= $r['based_on'] === 'actual_amount' ? 'selected' : '' ?>>Actual Amount</option>
                </select>
              </td>
              <td><input type="number" step="0.01" class="form-control form-control-sm" name="row_rate_or_amount[]" value="<?= e($r['rate_or_amount']) ?>"></td>
              <td><button type="button" class="btn btn-sm btn-outline-danger tt-remove-row"><i class="fa-solid fa-xmark"></i></button></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <button type="button" class="btn btn-sm btn-outline-brand mb-3 tt-add-row"><i class="fa-solid fa-plus"></i> Add Row</button>
    </div>

    <div class="page-actions">
      <button type="submit" class="btn btn-brand">Save</button>
      <a href="tax_templates.php" class="btn btn-outline-secondary">Cancel</a>
    </div>
  </form>
</div>
<?php
$extra_js_inline = "
document.addEventListener('DOMContentLoaded', function () {
  var wrap = document.querySelector('.tt-rows');
  if (!wrap) return;
  var tbody = wrap.querySelector('tbody');
  wrap.addEventListener('click', function (e) {
    if (e.target.closest('.tt-add-row')) {
      var rows = tbody.querySelectorAll('tr[data-row]');
      var clone = rows[rows.length - 1].cloneNode(true);
      clone.querySelectorAll('input').forEach(function (inp) { inp.value = ''; });
      clone.querySelectorAll('select').forEach(function (sel) { sel.selectedIndex = 0; });
      tbody.appendChild(clone);
      return;
    }
    var rm = e.target.closest('.tt-remove-row');
    if (rm) {
      var rows2 = tbody.querySelectorAll('tr[data-row]');
      if (rows2.length > 1) rm.closest('tr[data-row]').remove();
    }
  });
});
";
require __DIR__ . '/../includes/footer.php';
?>
