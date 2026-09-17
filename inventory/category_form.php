<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$id = (int)input('id');
$category = ['id' => 0, 'name' => '', 'description' => ''];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM categories WHERE id = ?');
    $stmt->execute([$id]);
    $category = $stmt->fetch() ?: $category;
}

if (is_post()) {
    csrf_verify();
    $name = input('name');
    $description = input('description');

    if ($name === '') {
        flash('danger', 'Category name is required.');
    } else {
        if ($id) {
            $stmt = db()->prepare('UPDATE categories SET name = ?, description = ? WHERE id = ?');
            $stmt->execute([$name, $description, $id]);
            flash('success', 'Category updated.');
        } else {
            $stmt = db()->prepare('INSERT INTO categories (name, description) VALUES (?, ?)');
            $stmt->execute([$name, $description]);
            flash('success', 'Category created.');
        }
        redirect('/inventory/categories.php');
    }
    $category = ['id' => $id, 'name' => $name, 'description' => $description];
}

$page_title = $id ? 'Edit Category' : 'Add Category';
require __DIR__ . '/../includes/header.php';
?>
<div class="card p-4" style="max-width:520px">
  <form method="post">
    <?= csrf_field() ?>
    <div class="mb-3">
      <label class="form-label">Name</label>
      <input type="text" name="name" class="form-control" required value="<?= e($category['name']) ?>">
    </div>
    <div class="mb-3">
      <label class="form-label">Description</label>
      <textarea name="description" class="form-control" rows="3"><?= e($category['description']) ?></textarea>
    </div>
    <div class="page-actions">
      <button type="submit" class="btn btn-brand">Save</button>
      <a href="categories.php" class="btn btn-outline-secondary">Cancel</a>
    </div>
  </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
