<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

if (is_post() && input('action') === 'delete') {
    csrf_verify();
    $id = (int)input('id');
    $stmt = db()->prepare('SELECT COUNT(*) FROM products WHERE category_id = ?');
    $stmt->execute([$id]);
    if ($stmt->fetchColumn() > 0) {
        flash('danger', 'Cannot delete: this category still has products assigned to it.');
    } else {
        db()->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
        flash('success', 'Category deleted.');
    }
    redirect('/inventory/categories.php');
}

$categories = db()->query("
  SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) product_count
  FROM categories c ORDER BY c.name
")->fetchAll();

$page_title = 'Categories';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <input type="text" class="form-control" style="max-width:260px" placeholder="Search categories..." data-table-search="#catTable">
  <a href="category_form.php" class="btn btn-brand"><i class="fa-solid fa-plus"></i> Add Category</a>
</div>
<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-hover" id="catTable">
      <thead><tr><th>Name</th><th>Description</th><th>Products</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($categories as $c): ?>
        <tr>
          <td><?= e($c['name']) ?></td>
          <td class="text-muted"><?= e($c['description']) ?></td>
          <td><?= (int)$c['product_count'] ?></td>
          <td class="text-end">
            <a href="category_form.php?id=<?= (int)$c['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen"></i></a>
            <form method="post" class="d-inline" data-confirm="Delete this category?">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
              <button class="btn btn-sm btn-outline-danger" type="submit"><i class="fa-solid fa-trash"></i></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$categories): ?><tr><td colspan="4" class="text-muted text-center">No categories yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
