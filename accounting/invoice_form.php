<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$id = (int)input('id');
$fromOrder = (int)input('from_order');
$invoice = ['id' => 0, 'customer_id' => '', 'sales_order_id' => $fromOrder ?: null, 'invoice_date' => today(), 'due_date' => date('Y-m-d', strtotime('+14 days')), 'tax' => '0', 'notes' => ''];
$items = [];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM invoices WHERE id = ?');
    $stmt->execute([$id]);
    $invoice = $stmt->fetch();
    if (!$invoice) {
        flash('danger', 'Invoice not found.');
        redirect('/accounting/invoices.php');
    }
    if ($invoice['amount_paid'] > 0 || $invoice['status'] !== 'unpaid') {
        flash('danger', 'This invoice already has activity and can no longer be edited.');
        redirect('/accounting/invoice_view.php?id=' . $id);
    }
    $stmt = db()->prepare('SELECT * FROM invoice_items WHERE invoice_id = ?');
    $stmt->execute([$id]);
    $items = $stmt->fetchAll();
} elseif ($fromOrder) {
    $stmt = db()->prepare('SELECT * FROM sales_orders WHERE id = ?');
    $stmt->execute([$fromOrder]);
    $order = $stmt->fetch();
    if ($order) {
        $invoice['customer_id'] = $order['customer_id'];
        $stmt = db()->prepare('SELECT soi.*, p.name product_name FROM sales_order_items soi JOIN products p ON p.id = soi.product_id WHERE order_id = ?');
        $stmt->execute([$fromOrder]);
        foreach ($stmt->fetchAll() as $oi) {
            $items[] = ['product_id' => $oi['product_id'], 'description' => $oi['product_name'], 'quantity' => $oi['quantity'], 'unit_price' => $oi['unit_price']];
        }
    }
}

$error = '';

if (is_post()) {
    csrf_verify();
    $customerId = (int)input('customer_id');
    $salesOrderId = input('sales_order_id') ?: null;
    $invoiceDate = input('invoice_date') ?: today();
    $dueDate = input('due_date') ?: null;
    $tax = (float)input('tax');
    $notes = input('notes');
    $productIds = $_POST['product_id'] ?? [];
    $descriptions = $_POST['description'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $prices = $_POST['unit_price'] ?? [];

    $lineItems = [];
    $subtotal = 0;
    foreach ($descriptions as $i => $desc) {
        $desc = trim($desc);
        $qty = (int)($quantities[$i] ?? 0);
        $price = (float)($prices[$i] ?? 0);
        $pid = (int)($productIds[$i] ?? 0) ?: null;
        if ($desc !== '' && $qty > 0) {
            $sub = $qty * $price;
            $lineItems[] = ['product_id' => $pid, 'description' => $desc, 'quantity' => $qty, 'unit_price' => $price, 'subtotal' => $sub];
            $subtotal += $sub;
        }
    }
    $total = $subtotal + $tax;

    if (!$customerId) {
        $error = 'Please select a customer.';
    } elseif (!$lineItems) {
        $error = 'Please add at least one valid line item.';
    } else {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($id) {
                $pdo->prepare('UPDATE invoices SET customer_id=?, sales_order_id=?, invoice_date=?, due_date=?, subtotal=?, tax=?, total=?, notes=? WHERE id=?')
                    ->execute([$customerId, $salesOrderId, $invoiceDate, $dueDate, $subtotal, $tax, $total, $notes, $id]);
                $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id=?')->execute([$id]);
                $invId = $id;
            } else {
                $invNo = next_code('INV', 'invoices', 'invoice_no');
                $pdo->prepare('INSERT INTO invoices (invoice_no, sales_order_id, customer_id, invoice_date, due_date, status, subtotal, tax, total, amount_paid, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,0,?,?)')
                    ->execute([$invNo, $salesOrderId, $customerId, $invoiceDate, $dueDate, 'unpaid', $subtotal, $tax, $total, $notes, current_user()['id']]);
                $invId = (int)$pdo->lastInsertId();
            }
            $itemStmt = $pdo->prepare('INSERT INTO invoice_items (invoice_id, product_id, description, quantity, unit_price, subtotal) VALUES (?,?,?,?,?,?)');
            foreach ($lineItems as $li) {
                $itemStmt->execute([$invId, $li['product_id'], $li['description'], $li['quantity'], $li['unit_price'], $li['subtotal']]);
            }
            $pdo->commit();
            flash('success', $id ? 'Invoice updated.' : 'Invoice created.');
            redirect('/accounting/invoice_view.php?id=' . $invId);
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Could not save invoice.';
        }
    }

    $invoice = ['id' => $id, 'customer_id' => $customerId, 'sales_order_id' => $salesOrderId, 'invoice_date' => $invoiceDate, 'due_date' => $dueDate, 'tax' => $tax, 'notes' => $notes];
    $items = $lineItems;
}

$customers = db()->query('SELECT id, name FROM customers ORDER BY name')->fetchAll();
$products = db()->query("SELECT id, sku, name, selling_price FROM products WHERE status='active' ORDER BY name")->fetchAll();

$page_title = $id ? 'Edit Invoice' : 'New Invoice';
require __DIR__ . '/../includes/header.php';
?>
<div class="card p-4">
  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="sales_order_id" value="<?= e($invoice['sales_order_id'] ?? '') ?>">
    <div class="row g-3 mb-3">
      <div class="col-sm-4">
        <label class="form-label">Customer</label>
        <select name="customer_id" class="form-select" required>
          <option value="">— Select customer —</option>
          <?php foreach ($customers as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= (string)$invoice['customer_id'] === (string)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-3">
        <label class="form-label">Invoice Date</label>
        <input type="date" name="invoice_date" class="form-control" value="<?= e($invoice['invoice_date']) ?>" required>
      </div>
      <div class="col-sm-3">
        <label class="form-label">Due Date</label>
        <input type="date" name="due_date" class="form-control" value="<?= e($invoice['due_date']) ?>">
      </div>
      <div class="col-sm-2">
        <label class="form-label">Tax</label>
        <input type="number" step="0.01" min="0" name="tax" id="taxInput" class="form-control" value="<?= e($invoice['tax'] ?? 0) ?>">
      </div>
    </div>

    <div class="line-items" data-total-target="#invSubtotal">
      <div class="table-responsive">
        <table class="table">
          <thead><tr><th style="width:26%">Product (optional)</th><th style="width:24%">Description</th><th style="width:12%">Qty</th><th style="width:14%">Unit Price</th><th style="width:14%" class="text-end">Subtotal</th><th></th></tr></thead>
          <tbody>
          <?php if (!$items): $items = [['product_id' => '', 'description' => '', 'quantity' => 1, 'unit_price' => 0]]; endif; ?>
          <?php foreach ($items as $it): ?>
            <tr data-row>
              <td>
                <select class="form-select js-product js-fill-desc">
                  <option value="">— None —</option>
                  <?php foreach ($products as $p): ?>
                    <option value="<?= (int)$p['id'] ?>" data-price="<?= e($p['selling_price']) ?>" data-name="<?= e($p['name']) ?>" <?= (string)($it['product_id'] ?? '') === (string)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?> (<?= e($p['sku']) ?>)</option>
                  <?php endforeach; ?>
                </select>
                <input type="hidden" class="js-product-id" name="product_id[]" value="<?= e($it['product_id'] ?? '') ?>">
              </td>
              <td><input type="text" class="form-control" name="description[]" value="<?= e($it['description']) ?>" required></td>
              <td><input type="number" min="1" class="form-control js-qty" name="quantity[]" value="<?= e($it['quantity']) ?>"></td>
              <td><input type="number" step="0.01" min="0" class="form-control js-price" name="unit_price[]" value="<?= e($it['unit_price']) ?>"></td>
              <td class="text-end js-subtotal">0.00</td>
              <td><button type="button" class="btn btn-sm btn-outline-danger js-remove-row"><i class="fa-solid fa-xmark"></i></button></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <button type="button" class="btn btn-sm btn-outline-brand mb-3 js-add-row"><i class="fa-solid fa-plus"></i> Add line</button>
      <div class="text-end mb-1">Subtotal: <strong id="invSubtotal">0.00</strong></div>
      <div class="text-end mb-1">Tax: <strong id="invTaxDisplay"><?= number_format((float)($invoice['tax'] ?? 0), 2) ?></strong></div>
      <div class="text-end fs-5 mb-3">Total: <strong id="invTotal">0.00</strong></div>
    </div>

    <div class="mb-3">
      <label class="form-label">Notes</label>
      <textarea name="notes" class="form-control" rows="2"><?= e($invoice['notes'] ?? '') ?></textarea>
    </div>

    <div class="page-actions">
      <button type="submit" class="btn btn-brand">Save Invoice</button>
      <a href="invoices.php" class="btn btn-outline-secondary">Cancel</a>
    </div>
  </form>
</div>
<?php
$extra_js_inline = "
document.querySelector('.line-items').addEventListener('change', function (e) {
  if (!e.target.classList.contains('js-fill-desc')) return;
  var sel = e.target;
  var row = sel.closest('tr[data-row]');
  var opt = sel.options[sel.selectedIndex];
  var descInput = row.querySelector('input[name=\"description[]\"]');
  var priceInput = row.querySelector('.js-price');
  var idInput = row.querySelector('.js-product-id');
  if (opt && opt.value) {
    if (descInput && !descInput.value) descInput.value = opt.getAttribute('data-name') || '';
    if (priceInput) priceInput.value = opt.getAttribute('data-price') || 0;
    if (idInput) idInput.value = opt.value;
  } else if (idInput) {
    idInput.value = '';
  }
});
function recalcInvoiceTotal() {
  var subtotal = parseFloat(document.getElementById('invSubtotal').textContent || 0);
  var tax = parseFloat(document.getElementById('taxInput').value || 0);
  document.getElementById('invTaxDisplay').textContent = tax.toFixed(2);
  document.getElementById('invTotal').textContent = (subtotal + tax).toFixed(2);
}
document.querySelector('.line-items').addEventListener('input', recalcInvoiceTotal);
document.querySelector('.line-items').addEventListener('click', function(){ setTimeout(recalcInvoiceTotal, 0); });
document.getElementById('taxInput').addEventListener('input', recalcInvoiceTotal);
recalcInvoiceTotal();
";
require __DIR__ . '/../includes/footer.php';
