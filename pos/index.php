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
            $pdo->prepare("INSERT INTO sales_orders (order_no, customer_id, order_date, status, channel, notes, total_amount, created_by) VALUES (?,?,?,?,'pos',?,?,?)")
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

$products = $pdo->query("SELECT id, sku, name, image, category_id, selling_price, quantity FROM products WHERE status='active' AND quantity > 0 ORDER BY name")->fetchAll();
$categories = $pdo->query("SELECT c.id, c.name FROM categories c WHERE EXISTS (SELECT 1 FROM products p WHERE p.category_id = c.id AND p.status='active' AND p.quantity > 0) ORDER BY c.name")->fetchAll();
$customers = $pdo->query('SELECT id, name FROM customers ORDER BY (name = "Walk-in Customer") DESC, name')->fetchAll();

$page_title = 'POS';
require __DIR__ . '/../includes/header.php';
?>
<div id="posWrapper">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0"><i class="fa-solid fa-cash-register"></i> Point of Sale</h5>
    <button type="button" id="posFullscreenBtn" class="btn btn-outline-secondary btn-sm" title="Toggle fullscreen">
      <i class="fa-solid fa-expand" id="posFullscreenIcon"></i> <span class="d-none d-sm-inline">Fullscreen</span>
    </button>
  </div>

  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

  <form method="post" id="posForm">
    <?= csrf_field() ?>
    <div id="posHiddenInputs"></div>

    <!-- Screen 1: browse products and build the cart -->
    <div id="posBrowseView">
      <div class="row g-3">
        <div class="col-lg-8">
          <div class="card p-3">
            <div class="d-flex gap-2 mb-3 flex-wrap align-items-center">
              <div class="input-group" style="max-width:320px">
                <span class="input-group-text"><i class="fa-solid fa-barcode"></i></span>
                <input type="text" id="posSearch" class="form-control" placeholder="Search or scan barcode (SKU)...">
              </div>
              <div class="btn-group flex-wrap" id="posCategoryTabs" role="group">
                <button type="button" class="btn btn-sm btn-brand pos-cat-btn active" data-cat="all">All</button>
                <?php foreach ($categories as $c): ?>
                  <button type="button" class="btn btn-sm btn-outline-secondary pos-cat-btn" data-cat="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></button>
                <?php endforeach; ?>
              </div>
            </div>
            <div class="row g-3" id="posGrid" style="max-height:65vh;overflow-y:auto">
              <?php foreach ($products as $p): ?>
                <div class="col-6 col-md-4 col-xl-3 pos-product" data-name="<?= e(strtolower($p['name'] . ' ' . $p['sku'])) ?>" data-sku="<?= e(strtolower($p['sku'])) ?>" data-cat="<?= (int)($p['category_id'] ?? 0) ?>">
                  <div class="pos-card card h-100"
                       data-id="<?= (int)$p['id'] ?>" data-name="<?= e($p['name']) ?>" data-price="<?= e($p['selling_price']) ?>"
                       data-stock="<?= (int)$p['quantity'] ?>" data-image="<?= $p['image'] ? e(base_url($p['image'])) : '' ?>">
                    <div class="pos-card-img">
                      <?php if ($p['image']): ?><img src="<?= base_url($p['image']) ?>" alt=""><?php else: ?><i class="fa-solid fa-box"></i><?php endif; ?>
                    </div>
                    <div class="p-2">
                      <div class="fw-bold text-truncate" title="<?= e($p['name']) ?>"><?= e($p['name']) ?></div>
                      <div class="small text-muted"><?= (int)$p['quantity'] ?> in stock</div>
                      <div class="d-flex justify-content-between align-items-center mt-1">
                        <strong><?= money($p['selling_price']) ?></strong>
                        <span class="btn btn-sm btn-brand"><i class="fa-solid fa-plus"></i></span>
                      </div>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
              <?php if (!$products): ?><div class="col-12 text-muted text-center py-4">No sellable products in stock.</div><?php endif; ?>
            </div>
          </div>
        </div>

        <div class="col-lg-4">
          <div class="card p-3">
            <h6 class="mb-3"><i class="fa-solid fa-cart-shopping"></i> Shopping Cart</h6>
            <div class="mb-2">
              <label class="form-label">Customer</label>
              <select name="customer_id" class="form-select">
                <?php foreach ($customers as $c): ?>
                  <option value="<?= (int)$c['id'] ?>" <?= $c['id'] == $walkInId ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div id="posCartEmpty" class="text-muted text-center py-4">Cart is empty — tap a product to add it.</div>
            <div id="posCartList" style="display:none;max-height:38vh;overflow-y:auto"></div>
            <div class="d-flex justify-content-between align-items-center fs-5 my-3">
              <span>Total</span><strong id="posTotal1">$0.00</strong>
            </div>
            <button type="button" id="posGoToCheckout" class="btn btn-brand w-100" disabled>Proceed to Checkout <i class="fa-solid fa-arrow-right"></i></button>
          </div>
        </div>
      </div>
    </div>

    <!-- Screen 2: checkout / payment -->
    <div id="posCheckoutView" style="display:none">
      <div class="row g-3">
        <div class="col-lg-7">
          <div class="card p-3">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <h6 class="mb-0">Your Shopping Bag</h6>
              <button type="button" id="posBackToBrowse" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-arrow-left"></i> Back to Items</button>
            </div>
            <div class="table-responsive">
              <table class="table align-middle">
                <thead><tr><th>Item</th><th class="text-end">Qty</th><th class="text-end">Price</th><th class="text-end">Subtotal</th><th></th></tr></thead>
                <tbody id="posCheckoutBody"></tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="col-lg-5">
          <div class="card p-3">
            <div class="mb-3">
              <label class="form-label">Payment Method</label>
              <select name="method" class="form-select">
                <option value="cash">Cash</option>
                <option value="card">Card</option>
                <option value="bank_transfer">Bank Transfer</option>
                <option value="other">Other</option>
              </select>
            </div>
            <div class="mb-2">
              <label class="form-label">Amount Tendered</label>
              <input type="text" id="posTendered" class="form-control form-control-lg text-end" value="0.00" readonly>
            </div>
            <div class="pos-keypad mb-3">
              <?php foreach (['7', '8', '9', '4', '5', '6', '1', '2', '3', '0', '.', 'C'] as $k): ?>
                <button type="button" class="btn btn-outline-secondary pos-key" data-key="<?= e($k) ?>"><?= e($k) ?></button>
              <?php endforeach; ?>
              <button type="button" class="btn btn-outline-danger pos-key-back" id="posKeyBack"><i class="fa-solid fa-delete-left"></i></button>
            </div>
            <div class="border-top pt-3">
              <div class="d-flex justify-content-between mb-1"><span class="text-muted">Subtotal</span><strong id="posSubtotal2">$0.00</strong></div>
              <div class="d-flex justify-content-between mb-1 fs-5"><span>Total</span><strong id="posTotal2">$0.00</strong></div>
              <div class="d-flex justify-content-between mb-3"><span class="text-muted" id="posChangeLabel">Change Due</span><strong id="posChange">$0.00</strong></div>
              <button type="submit" id="posConfirmBtn" class="btn btn-brand btn-lg w-100">Confirm &amp; Complete Sale</button>
              <button type="button" id="posVoidCart" class="btn btn-outline-danger w-100 mt-2">Void Cart</button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </form>
</div>

<?php
$currencySymbol = setting('currency_symbol', '$');
$extra_js_inline = "
var cart = {};
var CURRENCY = " . json_encode($currencySymbol) . ";
var currentCat = 'all';

function fmt(n) { return CURRENCY + n.toFixed(2); }

function cartTotal() {
  var total = 0;
  Object.keys(cart).forEach(function (id) { total += cart[id].qty * cart[id].price; });
  return total;
}

function thumbHtml(image) {
  return image ? '<img src=\"' + image + '\">' : '<i class=\"fa-solid fa-box\"></i>';
}

function renderHiddenInputs() {
  var hidden = document.getElementById('posHiddenInputs');
  hidden.innerHTML = '';
  Object.keys(cart).forEach(function (id) {
    ['product_id', 'quantity'].forEach(function (field) {
      var inp = document.createElement('input');
      inp.type = 'hidden';
      inp.name = field + '[]';
      inp.value = field === 'product_id' ? id : cart[id].qty;
      hidden.appendChild(inp);
    });
  });
}

function renderBrowseCart() {
  var ids = Object.keys(cart);
  var list = document.getElementById('posCartList');
  list.innerHTML = '';
  ids.forEach(function (id) {
    var item = cart[id];
    var sub = item.qty * item.price;
    var row = document.createElement('div');
    row.className = 'd-flex align-items-center gap-2 py-2 border-bottom';
    row.innerHTML = '<div class=\"pos-cart-thumb\">' + thumbHtml(item.image) + '</div>' +
      '<div class=\"flex-grow-1\"><div class=\"fw-bold small\">' + item.name + '</div><div class=\"text-muted small\">' + fmt(item.price) + ' &times; ' + item.qty + '</div></div>' +
      '<div class=\"fw-bold small\">' + fmt(sub) + '</div>' +
      '<button type=\"button\" class=\"btn btn-sm btn-outline-danger pos-remove\" data-id=\"' + id + '\"><i class=\"fa-solid fa-xmark\"></i></button>';
    list.appendChild(row);
  });
  document.getElementById('posCartEmpty').style.display = ids.length ? 'none' : '';
  document.getElementById('posCartList').style.display = ids.length ? '' : 'none';
  document.getElementById('posTotal1').textContent = fmt(cartTotal());
  document.getElementById('posGoToCheckout').disabled = ids.length === 0;
  renderHiddenInputs();
}

function renderCheckout() {
  var body = document.getElementById('posCheckoutBody');
  body.innerHTML = '';
  Object.keys(cart).forEach(function (id) {
    var item = cart[id];
    var sub = item.qty * item.price;
    var tr = document.createElement('tr');
    tr.innerHTML = '<td><div class=\"d-flex align-items-center gap-2\"><div class=\"pos-cart-thumb\">' + thumbHtml(item.image) + '</div><div class=\"fw-bold\">' + item.name + '</div></div></td>' +
      '<td class=\"text-end\"><div class=\"btn-group btn-group-sm\"><button type=\"button\" class=\"btn btn-outline-secondary pos-dec2\" data-id=\"' + id + '\">-</button><span class=\"btn btn-outline-secondary disabled\">' + item.qty + '</span><button type=\"button\" class=\"btn btn-outline-secondary pos-inc2\" data-id=\"' + id + '\">+</button></div></td>' +
      '<td class=\"text-end\">' + fmt(item.price) + '</td>' +
      '<td class=\"text-end\">' + fmt(sub) + '</td>' +
      '<td><button type=\"button\" class=\"btn btn-sm btn-outline-danger pos-remove2\" data-id=\"' + id + '\"><i class=\"fa-solid fa-xmark\"></i></button></td>';
    body.appendChild(tr);
  });
  var total = cartTotal();
  document.getElementById('posSubtotal2').textContent = fmt(total);
  document.getElementById('posTotal2').textContent = fmt(total);
  updateChange();
}

function updateChange() {
  var tendered = parseFloat(document.getElementById('posTendered').value) || 0;
  var total = cartTotal();
  var diff = tendered - total;
  var label = document.getElementById('posChangeLabel');
  var el = document.getElementById('posChange');
  label.textContent = diff < 0 ? 'Amount Remaining' : 'Change Due';
  el.textContent = fmt(Math.abs(diff));
  el.className = diff < 0 ? 'text-danger' : 'text-success';
}

function showBrowse() {
  document.getElementById('posBrowseView').style.display = '';
  document.getElementById('posCheckoutView').style.display = 'none';
}

function showCheckout() {
  renderCheckout();
  document.getElementById('posBrowseView').style.display = 'none';
  document.getElementById('posCheckoutView').style.display = '';
  document.getElementById('posTendered').value = '0.00';
  updateChange();
}

function applyFilters() {
  var q = document.getElementById('posSearch').value.trim().toLowerCase();
  document.querySelectorAll('.pos-product').forEach(function (el) {
    var matchesCat = currentCat === 'all' || el.getAttribute('data-cat') === currentCat;
    var matchesSearch = !q || el.getAttribute('data-name').indexOf(q) > -1;
    el.style.display = (matchesCat && matchesSearch) ? '' : 'none';
  });
}

document.getElementById('posGrid').addEventListener('click', function (e) {
  var card = e.target.closest('.pos-card');
  if (!card) return;
  var id = card.getAttribute('data-id');
  var stock = parseInt(card.getAttribute('data-stock'), 10);
  if (!cart[id]) cart[id] = { name: card.getAttribute('data-name'), price: parseFloat(card.getAttribute('data-price')), qty: 0, stock: stock, image: card.getAttribute('data-image') };
  if (cart[id].qty < stock) cart[id].qty += 1;
  renderBrowseCart();
});

document.getElementById('posCartList').addEventListener('click', function (e) {
  var btn = e.target.closest('.pos-remove');
  if (!btn) return;
  delete cart[btn.getAttribute('data-id')];
  renderBrowseCart();
});

document.getElementById('posCheckoutBody').addEventListener('click', function (e) {
  var inc = e.target.closest('.pos-inc2');
  var dec = e.target.closest('.pos-dec2');
  var rm = e.target.closest('.pos-remove2');
  if (inc) {
    var id = inc.getAttribute('data-id');
    if (cart[id].qty < cart[id].stock) cart[id].qty += 1;
  } else if (dec) {
    var id2 = dec.getAttribute('data-id');
    cart[id2].qty -= 1;
    if (cart[id2].qty <= 0) delete cart[id2];
  } else if (rm) {
    delete cart[rm.getAttribute('data-id')];
  } else {
    return;
  }
  renderHiddenInputs();
  if (Object.keys(cart).length === 0) { showBrowse(); renderBrowseCart(); return; }
  renderCheckout();
});

document.getElementById('posCategoryTabs').addEventListener('click', function (e) {
  var btn = e.target.closest('.pos-cat-btn');
  if (!btn) return;
  document.querySelectorAll('.pos-cat-btn').forEach(function (b) { b.classList.remove('active', 'btn-brand'); b.classList.add('btn-outline-secondary'); });
  btn.classList.add('active', 'btn-brand');
  btn.classList.remove('btn-outline-secondary');
  currentCat = btn.getAttribute('data-cat');
  applyFilters();
});

document.getElementById('posSearch').addEventListener('input', applyFilters);

document.getElementById('posSearch').addEventListener('keydown', function (e) {
  if (e.key !== 'Enter') return;
  e.preventDefault();
  var q = this.value.trim().toLowerCase();
  if (!q) return;
  var match = null;
  document.querySelectorAll('.pos-card').forEach(function (card) {
    if (card.closest('.pos-product').getAttribute('data-sku') === q) match = card;
  });
  if (!match) return;
  var id = match.getAttribute('data-id');
  var stock = parseInt(match.getAttribute('data-stock'), 10);
  if (!cart[id]) cart[id] = { name: match.getAttribute('data-name'), price: parseFloat(match.getAttribute('data-price')), qty: 0, stock: stock, image: match.getAttribute('data-image') };
  if (cart[id].qty < stock) cart[id].qty += 1;
  renderBrowseCart();
  this.value = '';
  applyFilters();
});

document.getElementById('posGoToCheckout').addEventListener('click', showCheckout);
document.getElementById('posBackToBrowse').addEventListener('click', showBrowse);
document.getElementById('posVoidCart').addEventListener('click', function () {
  if (!confirm('Clear the entire cart?')) return;
  cart = {};
  renderBrowseCart();
  showBrowse();
});

document.querySelectorAll('.pos-key').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var key = btn.getAttribute('data-key');
    var input = document.getElementById('posTendered');
    if (key === 'C') {
      input.value = '0.00';
    } else {
      var cur = input.value === '0.00' ? '' : input.value;
      if (key === '.' && cur.indexOf('.') > -1) return;
      input.value = cur + key;
    }
    updateChange();
  });
});
document.getElementById('posKeyBack').addEventListener('click', function () {
  var input = document.getElementById('posTendered');
  input.value = input.value.length > 1 ? input.value.slice(0, -1) : '0.00';
  updateChange();
});

var fsBtn = document.getElementById('posFullscreenBtn');
var fsIcon = document.getElementById('posFullscreenIcon');
fsBtn.addEventListener('click', function () {
  if (!document.fullscreenElement) {
    document.getElementById('posWrapper').requestFullscreen().catch(function () {});
  } else {
    document.exitFullscreen();
  }
});
document.addEventListener('fullscreenchange', function () {
  fsIcon.className = document.fullscreenElement ? 'fa-solid fa-compress' : 'fa-solid fa-expand';
});

renderBrowseCart();
";
require __DIR__ . '/../includes/footer.php';
