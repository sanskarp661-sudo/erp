<?php
require_once __DIR__ . '/../includes/auth.php';
require_module_edit('pos');

$pdo = db();

// Every POS sale needs a customer; auto-provision a default "Walk-in
// Customer" so cashiers aren't blocked on creating one first.
$walkInId = (int)$pdo->query("SELECT id FROM customers WHERE name = 'Walk-in Customer' LIMIT 1")->fetchColumn();
if (!$walkInId) {
    $pdo->prepare("INSERT INTO customers (name, company, email, phone, address) VALUES ('Walk-in Customer', NULL, NULL, NULL, NULL)")->execute();
    $walkInId = (int)$pdo->lastInsertId();
}

$error = '';

if (is_post()) {
    csrf_verify();
    $customerId = (int)input('customer_id') ?: $walkInId;
    $method = in_array(input('method'), ['cash', 'bank_transfer', 'card', 'cheque', 'other'], true) ? input('method') : 'cash';
    $productIds = $_POST['product_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];

    $lineItems = [];
    foreach ($productIds as $i => $pid) {
        $pid = (int)$pid;
        $qty = (int)($quantities[$i] ?? 0);
        if ($pid > 0 && $qty > 0) {
            $lineItems[$pid] = ($lineItems[$pid] ?? 0) + $qty;
        }
    }

    if (!$lineItems) {
        $error = 'Cart is empty — add at least one product.';
    } else {
        $posWarehouseId = default_warehouse_id();
        if (!$posWarehouseId) {
            $error = 'No warehouse is set up yet — ask an admin to create one under Supply Chain → Warehouses.';
        }
        $pdo->beginTransaction();
        try {
            if (!$posWarehouseId) {
                throw new RuntimeException($error);
            }
            $subtotal = 0;
            $resolved = [];
            foreach ($lineItems as $pid => $qty) {
                $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ? FOR UPDATE');
                $stmt->execute([$pid]);
                $product = $stmt->fetch();
                if (!$product) {
                    throw new RuntimeException('A product in the cart no longer exists.');
                }
                if (warehouse_stock($pid, $posWarehouseId) < $qty) {
                    throw new RuntimeException('Not enough stock for ' . $product['name'] . '.');
                }
                $lineTotal = $qty * $product['selling_price'];
                $subtotal += $lineTotal;
                $resolved[] = ['product' => $product, 'qty' => $qty, 'unit_price' => $product['selling_price'], 'subtotal' => $lineTotal];
            }

            $orderNo = next_code('SO', 'sales_orders', 'order_no');
            $pdo->prepare("INSERT INTO sales_orders (order_no, customer_id, order_date, status, notes, total_amount, created_by) VALUES (?,?,?,?,?,?,?)")
                ->execute([$orderNo, $customerId, today(), 'completed', 'POS sale', $subtotal, current_user()['id']]);
            $orderId = (int)$pdo->lastInsertId();

            $itemStmt = $pdo->prepare('INSERT INTO sales_order_items (order_id, product_id, quantity, unit_price, subtotal) VALUES (?,?,?,?,?)');
            foreach ($resolved as $r) {
                $itemStmt->execute([$orderId, $r['product']['id'], $r['qty'], $r['unit_price'], $r['subtotal']]);
                stock_move($r['product']['id'], $posWarehouseId, -$r['qty'], 'out', $orderNo, 'POS sale', current_user()['id']);
            }

            $invoiceNo = next_code('INV', 'invoices', 'invoice_no');
            $pdo->prepare("INSERT INTO invoices (invoice_no, sales_order_id, customer_id, invoice_date, due_date, status, subtotal, tax, total, amount_paid, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$invoiceNo, $orderId, $customerId, today(), today(), 'paid', $subtotal, 0, $subtotal, $subtotal, 'POS sale', current_user()['id']]);
            $invoiceId = (int)$pdo->lastInsertId();

            $invItemStmt = $pdo->prepare('INSERT INTO invoice_items (invoice_id, product_id, description, quantity, unit_price, subtotal) VALUES (?,?,?,?,?,?)');
            foreach ($resolved as $r) {
                $invItemStmt->execute([$invoiceId, $r['product']['id'], $r['product']['name'], $r['qty'], $r['unit_price'], $r['subtotal']]);
            }

            $pdo->prepare('INSERT INTO payments (invoice_id, amount, payment_date, method, reference, notes, created_by) VALUES (?,?,?,?,?,?,?)')
                ->execute([$invoiceId, $subtotal, today(), $method, 'POS-' . $orderNo, 'POS sale', current_user()['id']]);

            $pdo->commit();
            flash('success', 'Sale completed — ' . money($subtotal) . ' charged.');
            redirect('/print.php?doctype=invoice&id=' . $invoiceId . '&pos=1');
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = $e->getMessage() ?: 'Could not complete the sale.';
        }
    }
}

$products = $pdo->query("SELECT id, sku, name, selling_price, quantity FROM products WHERE status='active' AND quantity > 0 ORDER BY name")->fetchAll();
$customers = $pdo->query('SELECT id, name FROM customers ORDER BY (name = "Walk-in Customer") DESC, name')->fetchAll();

$page_title = 'POS';
require __DIR__ . '/../includes/header.php';
?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<div class="row g-3">
  <div class="col-lg-8">
    <div class="card p-3">
      <input type="text" id="posSearch" class="form-control mb-3" placeholder="Search products by name or SKU...">
      <div class="row g-2" id="posGrid">
        <?php foreach ($products as $p): ?>
          <div class="col-6 col-md-4 col-xl-3 pos-product" data-name="<?= e(strtolower($p['name'] . ' ' . $p['sku'])) ?>">
            <button type="button" class="btn btn-outline-secondary w-100 h-100 text-start pos-card"
                    data-id="<?= (int)$p['id'] ?>" data-name="<?= e($p['name']) ?>" data-price="<?= e($p['selling_price']) ?>" data-stock="<?= (int)$p['quantity'] ?>">
              <div class="fw-bold"><?= e($p['name']) ?></div>
              <div class="small text-muted"><?= e($p['sku']) ?></div>
              <div class="small"><?= money($p['selling_price']) ?> &middot; <?= (int)$p['quantity'] ?> in stock</div>
            </button>
          </div>
        <?php endforeach; ?>
        <?php if (!$products): ?><div class="col-12 text-muted text-center py-4">No sellable products in stock.</div><?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="card p-3">
      <h6 class="mb-3"><i class="fa-solid fa-cart-shopping"></i> Current Sale</h6>
      <form method="post" id="posForm">
        <?= csrf_field() ?>
        <div class="mb-2">
          <label class="form-label">Customer</label>
          <select name="customer_id" class="form-select">
            <?php foreach ($customers as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= $c['id'] == $walkInId ? 'selected' : '' ?>><?= e($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div id="posCartEmpty" class="text-muted text-center py-4">Cart is empty — tap a product to add it.</div>
        <div class="table-responsive">
          <table class="table table-sm" id="posCartTable" style="display:none">
            <thead><tr><th>Item</th><th class="text-end">Qty</th><th class="text-end">Subtotal</th><th></th></tr></thead>
            <tbody id="posCartBody"></tbody>
          </table>
        </div>
        <div id="posHiddenInputs"></div>
        <div class="d-flex justify-content-between align-items-center fs-5 my-3">
          <span>Total</span><strong id="posTotal">$0.00</strong>
        </div>
        <div class="mb-3">
          <label class="form-label">Payment Method</label>
          <select name="method" class="form-select">
            <option value="cash">Cash</option>
            <option value="card">Card</option>
            <option value="bank_transfer">Bank Transfer</option>
            <option value="other">Other</option>
          </select>
        </div>
        <button type="submit" id="posCheckoutBtn" class="btn btn-brand w-100" disabled>Charge & Complete Sale</button>
      </form>
    </div>
  </div>
</div>

<?php
$currencySymbol = setting('currency_symbol', '$');
$extra_js_inline = "
var cart = {};
var CURRENCY = " . json_encode($currencySymbol) . ";

function fmt(n) { return CURRENCY + n.toFixed(2); }

function renderCart() {
  var body = document.getElementById('posCartBody');
  var hidden = document.getElementById('posHiddenInputs');
  var ids = Object.keys(cart);
  body.innerHTML = '';
  hidden.innerHTML = '';
  var total = 0;
  ids.forEach(function (id) {
    var item = cart[id];
    var sub = item.qty * item.price;
    total += sub;
    var tr = document.createElement('tr');
    tr.innerHTML = '<td>' + item.name + '<div class=\"small text-muted\">' + fmt(item.price) + ' each</div></td>' +
      '<td class=\"text-end\">' +
        '<div class=\"btn-group btn-group-sm\" role=\"group\">' +
          '<button type=\"button\" class=\"btn btn-outline-secondary pos-dec\" data-id=\"' + id + '\">-</button>' +
          '<span class=\"btn btn-outline-secondary disabled\">' + item.qty + '</span>' +
          '<button type=\"button\" class=\"btn btn-outline-secondary pos-inc\" data-id=\"' + id + '\">+</button>' +
        '</div>' +
      '</td>' +
      '<td class=\"text-end\">' + fmt(sub) + '</td>' +
      '<td><button type=\"button\" class=\"btn btn-sm btn-outline-danger pos-remove\" data-id=\"' + id + '\"><i class=\"fa-solid fa-xmark\"></i></button></td>';
    body.appendChild(tr);

    ['product_id', 'quantity'].forEach(function (field) {
      var inp = document.createElement('input');
      inp.type = 'hidden';
      inp.name = field + '[]';
      inp.value = field === 'product_id' ? id : item.qty;
      hidden.appendChild(inp);
    });
  });
  document.getElementById('posCartEmpty').style.display = ids.length ? 'none' : '';
  document.getElementById('posCartTable').style.display = ids.length ? '' : 'none';
  document.getElementById('posTotal').textContent = fmt(total);
  document.getElementById('posCheckoutBtn').disabled = ids.length === 0;
}

document.querySelectorAll('.pos-card').forEach(function (card) {
  card.addEventListener('click', function () {
    var id = card.getAttribute('data-id');
    var stock = parseInt(card.getAttribute('data-stock'), 10);
    if (!cart[id]) {
      cart[id] = { name: card.getAttribute('data-name'), price: parseFloat(card.getAttribute('data-price')), qty: 0, stock: stock };
    }
    if (cart[id].qty < stock) cart[id].qty += 1;
    renderCart();
  });
});

document.getElementById('posCartBody').addEventListener('click', function (e) {
  var incBtn = e.target.closest('.pos-inc');
  var decBtn = e.target.closest('.pos-dec');
  var rmBtn = e.target.closest('.pos-remove');
  if (incBtn) {
    var id = incBtn.getAttribute('data-id');
    if (cart[id].qty < cart[id].stock) cart[id].qty += 1;
  } else if (decBtn) {
    var id2 = decBtn.getAttribute('data-id');
    cart[id2].qty -= 1;
    if (cart[id2].qty <= 0) delete cart[id2];
  } else if (rmBtn) {
    delete cart[rmBtn.getAttribute('data-id')];
  } else {
    return;
  }
  renderCart();
});

document.getElementById('posSearch').addEventListener('input', function () {
  var q = this.value.trim().toLowerCase();
  document.querySelectorAll('.pos-product').forEach(function (el) {
    el.style.display = el.getAttribute('data-name').indexOf(q) > -1 ? '' : 'none';
  });
});

renderCart();
";
require __DIR__ . '/../includes/footer.php';
