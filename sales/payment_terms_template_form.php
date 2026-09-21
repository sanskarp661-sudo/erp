<?php
require_once __DIR__ . '/../includes/auth.php';
require_module_edit('sales');

$id = (int)input('id');
$template = ['id' => 0, 'name' => '', 'status' => 'active'];
$rows = [];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM payment_terms_templates WHERE id = ?');
    $stmt->execute([$id]);
    $template = $stmt->fetch() ?: $template;
    $stmt = db()->prepare('SELECT * FROM payment_terms_template_items WHERE template_id = ? ORDER BY sort_order, id');
    $stmt->execute([$id]);
    $rows = $stmt->fetchAll();
}

$error = '';

if (is_post()) {
    csrf_verify();
    $name = input('name');
    $status = input('status') === 'inactive' ? 'inactive' : 'active';
    $dueOns = $_POST['row_due_on'] ?? [];
    $daysFroms = $_POST['row_days_from'] ?? [];
    $paymentTypes = $_POST['row_payment_type'] ?? [];
    $percentages = $_POST['row_percentage'] ?? [];
    $remarksArr = $_POST['row_remarks'] ?? [];

    if ($name === '') {
        $error = 'Name is required.';
    } else {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($id) {
                $pdo->prepare('UPDATE payment_terms_templates SET name=?, status=? WHERE id=?')->execute([$name, $status, $id]);
                $pdo->prepare('DELETE FROM payment_terms_template_items WHERE template_id=?')->execute([$id]);
                $ptId = $id;
            } else {
                $pdo->prepare('INSERT INTO payment_terms_templates (name, status) VALUES (?,?)')->execute([$name, $status]);
                $ptId = (int)$pdo->lastInsertId();
            }
            $rowStmt = $pdo->prepare('INSERT INTO payment_terms_template_items (template_id, due_on, days_from, payment_type, percentage, remarks, sort_order) VALUES (?,?,?,?,?,?,?)');
            $sort = 0;
            foreach ($percentages as $i => $pct) {
                $pct = (float)$pct;
                $remarks = trim($remarksArr[$i] ?? '');
                if ($pct == 0 && $remarks === '') {
                    continue;
                }
                $dueOn = in_array($dueOns[$i] ?? '', ['order_date', 'on_delivery', 'fixed_days'], true) ? $dueOns[$i] : 'order_date';
                $paymentType = in_array($paymentTypes[$i] ?? '', ['advance', 'part_payment', 'balance'], true) ? $paymentTypes[$i] : 'balance';
                $daysFrom = (int)($daysFroms[$i] ?? 0);
                $rowStmt->execute([$ptId, $dueOn, $daysFrom, $paymentType, $pct, $remarks, $sort++]);
            }
            $pdo->commit();
            flash('success', $id ? 'Template updated.' : 'Template created.');
            redirect('/sales/payment_terms_templates.php');
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Could not save template.';
        }
    }
    $template = ['id' => $id, 'name' => $name, 'status' => $status];
}

$page_title = $id ? 'Edit Payment Terms Template' : 'Add Payment Terms Template';
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

    <h6 class="mb-2">Payment Schedule <span class="text-muted small fw-normal">— percentages should add up to 100%</span></h6>
    <div class="ptt-rows">
      <div class="table-responsive mb-2">
        <table class="table table-sm">
          <thead><tr><th>Due On</th><th style="width:90px">Days From</th><th>Payment Type</th><th style="width:110px">Percentage</th><th>Remarks</th><th></th></tr></thead>
          <tbody>
          <?php if (!$rows): $rows = [['due_on' => 'order_date', 'days_from' => 0, 'payment_type' => 'advance', 'percentage' => 0, 'remarks' => '']]; endif; ?>
          <?php foreach ($rows as $r): ?>
            <tr data-row>
              <td>
                <select class="form-select form-select-sm" name="row_due_on[]">
                  <option value="order_date" <?= $r['due_on'] === 'order_date' ? 'selected' : '' ?>>On Order Date</option>
                  <option value="on_delivery" <?= $r['due_on'] === 'on_delivery' ? 'selected' : '' ?>>On Delivery</option>
                  <option value="fixed_days" <?= $r['due_on'] === 'fixed_days' ? 'selected' : '' ?>>Fixed Days</option>
                </select>
              </td>
              <td><input type="number" min="0" class="form-control form-control-sm" name="row_days_from[]" value="<?= e($r['days_from']) ?>"></td>
              <td>
                <select class="form-select form-select-sm" name="row_payment_type[]">
                  <option value="advance" <?= $r['payment_type'] === 'advance' ? 'selected' : '' ?>>Advance</option>
                  <option value="part_payment" <?= $r['payment_type'] === 'part_payment' ? 'selected' : '' ?>>Part Payment</option>
                  <option value="balance" <?= $r['payment_type'] === 'balance' ? 'selected' : '' ?>>Balance</option>
                </select>
              </td>
              <td><input type="number" step="0.01" min="0" max="100" class="form-control form-control-sm" name="row_percentage[]" value="<?= e($r['percentage']) ?>"></td>
              <td><input type="text" class="form-control form-control-sm" name="row_remarks[]" value="<?= e($r['remarks'] ?? '') ?>"></td>
              <td><button type="button" class="btn btn-sm btn-outline-danger ptt-remove-row"><i class="fa-solid fa-xmark"></i></button></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <button type="button" class="btn btn-sm btn-outline-brand mb-3 ptt-add-row"><i class="fa-solid fa-plus"></i> Add Payment Term</button>
    </div>

    <div class="page-actions">
      <button type="submit" class="btn btn-brand">Save</button>
      <a href="payment_terms_templates.php" class="btn btn-outline-secondary">Cancel</a>
    </div>
  </form>
</div>
<?php
$extra_js_inline = "
document.addEventListener('DOMContentLoaded', function () {
  var wrap = document.querySelector('.ptt-rows');
  if (!wrap) return;
  var tbody = wrap.querySelector('tbody');
  wrap.addEventListener('click', function (e) {
    if (e.target.closest('.ptt-add-row')) {
      var rows = tbody.querySelectorAll('tr[data-row]');
      var clone = rows[rows.length - 1].cloneNode(true);
      clone.querySelectorAll('input').forEach(function (inp) { inp.value = ''; });
      clone.querySelectorAll('select').forEach(function (sel) { sel.selectedIndex = 0; });
      tbody.appendChild(clone);
      return;
    }
    var rm = e.target.closest('.ptt-remove-row');
    if (rm) {
      var rows2 = tbody.querySelectorAll('tr[data-row]');
      if (rows2.length > 1) rm.closest('tr[data-row]').remove();
    }
  });
});
";
require __DIR__ . '/../includes/footer.php';
?>
