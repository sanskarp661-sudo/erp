<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/hr.php';
require_module_manage('hrms');

$id = (int)input('id');
$type = ['id' => 0, 'code' => '', 'name' => '', 'annual_allocation' => 12, 'is_paid' => 1, 'allow_half_day' => 1, 'status' => 'active', 'sort_order' => 0];
if ($id) {
    $stmt = db()->prepare('SELECT * FROM leave_types WHERE id = ?');
    $stmt->execute([$id]);
    $type = $stmt->fetch() ?: $type;
}
$error = '';

if (is_post()) {
    csrf_verify();
    $type = array_merge($type, [
        'code' => $id ? $type['code'] : strtolower(preg_replace('/[^a-z0-9_]+/i', '_', trim((string)input('code')))),
        'name' => trim((string)input('name')),
        'annual_allocation' => max(0, min(365, round((float)input('annual_allocation') * 2) / 2)),
        'is_paid' => input('is_paid') ? 1 : 0,
        'allow_half_day' => input('allow_half_day') ? 1 : 0,
        'status' => input('status') === 'inactive' ? 'inactive' : 'active',
        'sort_order' => max(0, (int)input('sort_order')),
    ]);
    if (!$type['is_paid']) {
        $type['annual_allocation'] = 0;
    }
    if ($type['name'] === '' || $type['code'] === '' || $type['code'] === '_') {
        $error = 'Code and Name are required.';
    } else {
        try {
            if ($id) {
                db()->prepare('UPDATE leave_types SET name=?, annual_allocation=?, is_paid=?, allow_half_day=?, status=?, sort_order=? WHERE id=?')
                    ->execute([$type['name'], $type['annual_allocation'], $type['is_paid'], $type['allow_half_day'], $type['status'], $type['sort_order'], $id]);
            } else {
                db()->prepare('INSERT INTO leave_types (code, name, annual_allocation, is_paid, allow_half_day, status, sort_order) VALUES (?,?,?,?,?,?,?)')
                    ->execute([$type['code'], $type['name'], $type['annual_allocation'], $type['is_paid'], $type['allow_half_day'], $type['status'], $type['sort_order']]);
            }
            flash('success', 'Leave type saved.');
            redirect('/hr/leave_types.php');
        } catch (PDOException $e) {
            $error = str_contains($e->getMessage(), 'Duplicate') ? 'A leave type with code "' . $type['code'] . '" already exists.' : 'Could not save leave type.';
        }
    }
}

$types = db()->query("SELECT lt.*, (SELECT COUNT(*) FROM leaves l WHERE l.leave_type = lt.code) used FROM leave_types lt ORDER BY sort_order, name")->fetchAll();
$fmt = fn($v) => rtrim(rtrim(number_format((float)$v, 1, '.', ''), '0'), '.');

$page_title = 'Leave Types';
require __DIR__ . '/../includes/header.php';
?>
<div class="row g-3">
  <div class="col-lg-8">
    <div class="card p-3">
      <table class="table table-hover mb-0">
        <thead><tr><th>Code</th><th>Name</th><th class="text-end">Days / year</th><th>Paid</th><th>Half Day</th><th>Status</th><th class="text-end">Applications</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($types as $t): ?>
          <tr>
            <td><code><?= e($t['code']) ?></code></td>
            <td><?= e($t['name']) ?></td>
            <td class="text-end"><?= $t['is_paid'] ? $fmt($t['annual_allocation']) : '—' ?></td>
            <td><?= $t['is_paid'] ? 'Paid' : '<span class="text-danger">Unpaid (LOP)</span>' ?></td>
            <td><?= $t['allow_half_day'] ? 'Allowed' : 'No' ?></td>
            <td><span class="badge text-bg-<?= $t['status'] === 'active' ? 'success' : 'secondary' ?> badge-status"><?= e($t['status']) ?></span></td>
            <td class="text-end"><?= (int)$t['used'] ?></td>
            <td class="text-end"><a href="leave_types.php?id=<?= (int)$t['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen"></i></a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <a href="leaves.php" class="btn btn-outline-secondary mt-3">Back to Leave Applications</a>
  </div>
  <div class="col-lg-4">
    <div class="card p-3">
      <h6 class="mb-3"><?= $id ? 'Edit ' . e($type['name']) : 'New Leave Type' ?></h6>
      <?php if ($error): ?><div class="alert alert-danger py-2"><?= e($error) ?></div><?php endif; ?>
      <form method="post">
        <?= csrf_field() ?>
        <div class="row g-2">
          <div class="col-6">
            <label class="form-label">Code</label>
            <input type="text" name="code" class="form-control" maxlength="30" value="<?= e($type['code']) ?>" <?= $id ? 'disabled' : 'required' ?> placeholder="e.g. maternity">
          </div>
          <div class="col-6">
            <label class="form-label">Days per Year</label>
            <input type="number" step="0.5" min="0" max="365" name="annual_allocation" class="form-control" value="<?= e($fmt($type['annual_allocation'])) ?>">
          </div>
          <div class="col-12">
            <label class="form-label">Name</label>
            <input type="text" name="name" class="form-control" maxlength="80" required value="<?= e($type['name']) ?>">
          </div>
          <div class="col-6">
            <label class="form-label">Status</label>
            <select name="status" class="form-select"><option value="active" <?= $type['status'] === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= $type['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option></select>
          </div>
          <div class="col-6">
            <label class="form-label">Sort Order</label>
            <input type="number" min="0" name="sort_order" class="form-control" value="<?= (int)$type['sort_order'] ?>">
          </div>
          <div class="col-12">
            <div class="form-check"><input class="form-check-input" type="checkbox" name="is_paid" id="isPaid" value="1" <?= $type['is_paid'] ? 'checked' : '' ?>><label class="form-check-label" for="isPaid">Paid leave (unticked = loss of pay in payroll)</label></div>
            <div class="form-check"><input class="form-check-input" type="checkbox" name="allow_half_day" id="allowHalf" value="1" <?= $type['allow_half_day'] ? 'checked' : '' ?>><label class="form-check-label" for="allowHalf">Can be taken as a half day</label></div>
          </div>
        </div>
        <div class="d-flex gap-2 mt-3">
          <button type="submit" class="btn btn-brand">Save</button>
          <?php if ($id): ?><a href="leave_types.php" class="btn btn-outline-secondary">New instead</a><?php endif; ?>
        </div>
      </form>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
