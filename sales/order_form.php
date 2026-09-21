<?php
require_once __DIR__ . '/../includes/auth.php';
require_module_edit('sales');

$id = (int)input('id');
$defaultPriceListId = db()->query("SELECT id FROM price_lists WHERE is_default = 1 ORDER BY id LIMIT 1")->fetchColumn();

$order = [
    'id' => 0, 'order_no' => '', 'customer_id' => '', 'contact_person' => '', 'customer_address_id' => '',
    'warehouse_id' => default_warehouse_id(), 'order_date' => today(), 'required_delivery_date' => '',
    'price_list_id' => $defaultPriceListId ?: '', 'currency' => setting('currency_code', 'INR'),
    'sales_channel' => '', 'territory' => '', 'sales_person_id' => '', 'customer_po_no' => '', 'project' => '',
    'status' => 'pending', 'notes' => '',
];
$items = [];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM sales_orders WHERE id = ?');
    $stmt->execute([$id]);
    $order = $stmt->fetch();
    if (!$order) {
        flash('danger', 'Sales order not found.');
        redirect('/sales/orders.php');
    }
    if ($order['status'] !== 'pending') {
        flash('danger', 'Only orders still in "pending" status can be edited.');
        redirect('/sales/order_view.php?id=' . $id);
    }
    $stmt = db()->prepare('SELECT * FROM sales_order_items WHERE order_id = ?');
    $stmt->execute([$id]);
    $items = $stmt->fetchAll();
}

$error = '';
$activeTab = input('tab') === 'items' ? 'items' : 'details';

if (is_post()) {
    csrf_verify();
    $customerId = (int)input('customer_id');
    $contactPerson = input('contact_person');
    $customerAddressId = (int)input('customer_address_id') ?: null;
    $orderDate = input('order_date') ?: today();
    $requiredDeliveryDate = input('required_delivery_date') ?: null;
    $priceListId = (int)input('price_list_id') ?: null;
    $currency = strtoupper(input('currency')) ?: 'INR';
    $salesChannel = input('sales_channel') ?: null;
    $territory = input('territory') ?: null;
    $salesPersonId = (int)input('sales_person_id') ?: null;
    $customerPoNo = input('customer_po_no') ?: null;
    $project = input('project') ?: null;
    $notes = input('notes');

    $productIds = $_POST['product_id'] ?? [];
    $descriptions = $_POST['description'] ?? [];
    $warehouseIds = $_POST['item_warehouse_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $uoms = $_POST['uom'] ?? [];
    $prices = $_POST['unit_price'] ?? [];
    $discounts = $_POST['discount_percent'] ?? [];

    $lineItems = [];
    $total = 0;
    $firstWarehouseId = null;
    foreach ($productIds as $i => $pid) {
        $pid = (int)$pid;
        $qty = (int)($quantities[$i] ?? 0);
        $price = (float)($prices[$i] ?? 0);
        $discount = max(0, min(100, (float)($discounts[$i] ?? 0)));
        $warehouseId = (int)($warehouseIds[$i] ?? 0) ?: null;
        if ($pid > 0 && $qty > 0) {
            $subtotal = round($qty * $price * (1 - $discount / 100), 2);
            $lineItems[] = [
                'product_id' => $pid,
                'description' => $descriptions[$i] ?? '',
                'warehouse_id' => $warehouseId,
                'quantity' => $qty,
                'uom' => $uoms[$i] ?? 'pcs',
                'unit_price' => $price,
                'discount_percent' => $discount,
                'subtotal' => $subtotal,
            ];
            $total += $subtotal;
            if ($firstWarehouseId === null) {
                $firstWarehouseId = $warehouseId;
            }
        }
    }

    if (!$customerId) {
        $error = 'Please select a customer.';
        $activeTab = 'details';
    } elseif (!$lineItems) {
        $error = 'Please add at least one valid line item.';
        $activeTab = 'items';
    } else {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($id) {
                $pdo->prepare('UPDATE sales_orders SET customer_id=?, contact_person=?, customer_address_id=?, warehouse_id=?, order_date=?, required_delivery_date=?, price_list_id=?, currency=?, sales_channel=?, territory=?, sales_person_id=?, customer_po_no=?, project=?, notes=?, total_amount=? WHERE id=?')
                    ->execute([$customerId, $contactPerson, $customerAddressId, $firstWarehouseId, $orderDate, $requiredDeliveryDate, $priceListId, $currency, $salesChannel, $territory, $salesPersonId, $customerPoNo, $project, $notes, $total, $id]);
                $pdo->prepare('DELETE FROM sales_order_items WHERE order_id=?')->execute([$id]);
                $orderId = $id;
            } else {
                $orderNo = next_code('SO', 'sales_orders', 'order_no');
                $pdo->prepare('INSERT INTO sales_orders (order_no, customer_id, contact_person, customer_address_id, warehouse_id, order_date, required_delivery_date, price_list_id, currency, sales_channel, territory, sales_person_id, customer_po_no, project, status, notes, total_amount, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$orderNo, $customerId, $contactPerson, $customerAddressId, $firstWarehouseId, $orderDate, $requiredDeliveryDate, $priceListId, $currency, $salesChannel, $territory, $salesPersonId, $customerPoNo, $project, 'pending', $notes, $total, current_user()['id']]);
                $orderId = (int)$pdo->lastInsertId();
            }
            $itemStmt = $pdo->prepare('INSERT INTO sales_order_items (order_id, product_id, description, warehouse_id, quantity, uom, unit_price, discount_percent, subtotal) VALUES (?,?,?,?,?,?,?,?,?)');
            foreach ($lineItems as $li) {
                $itemStmt->execute([$orderId, $li['product_id'], $li['description'], $li['warehouse_id'], $li['quantity'], $li['uom'], $li['unit_price'], $li['discount_percent'], $li['subtotal']]);
            }
            $pdo->commit();
            flash('success', $id ? 'Sales order updated.' : 'Sales order created.');
            redirect('/sales/order_view.php?id=' . $orderId);
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Could not save sales order.';
        }
    }

    $order = [
        'id' => $id, 'order_no' => $order['order_no'] ?? '', 'customer_id' => $customerId, 'contact_person' => $contactPerson,
        'customer_address_id' => $customerAddressId, 'warehouse_id' => $firstWarehouseId, 'order_date' => $orderDate,
        'required_delivery_date' => $requiredDeliveryDate, 'price_list_id' => $priceListId, 'currency' => $currency,
        'sales_channel' => $salesChannel, 'territory' => $territory, 'sales_person_id' => $salesPersonId,
        'customer_po_no' => $customerPoNo, 'project' => $project, 'status' => $order['status'] ?? 'pending', 'notes' => $notes,
    ];
    $items = $lineItems;
}

$newAddressId = (int)input('new_address_id');
if ($newAddressId) {
    $order['customer_address_id'] = $newAddressId;
}

$customers = db()->query('SELECT id, name FROM customers ORDER BY name')->fetchAll();
$products = db()->query("SELECT p.id, p.sku, p.name, p.selling_price, p.quantity, p.unit, c.name category_name FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.status='active' ORDER BY p.name")->fetchAll();
$warehouses = leaf_warehouses();
$priceLists = db()->query("SELECT id, name, currency FROM price_lists WHERE status='active' ORDER BY is_default DESC, name")->fetchAll();
$salesUsers = db()->query("SELECT id, name FROM users WHERE status='active' ORDER BY name")->fetchAll();
$uoms = db()->query("SELECT name FROM uom WHERE status='active' ORDER BY name")->fetchAll();

$addressesByCustomer = [];
$addrStmt = db()->query('SELECT id, customer_id, label, address_line, city, state, pincode, is_default FROM customer_addresses ORDER BY is_default DESC, label');
foreach ($addrStmt as $a) {
    $addressesByCustomer[(int)$a['customer_id']][] = [
        'id' => (int)$a['id'],
        'text' => $a['label'] . ': ' . $a['address_line'] . ($a['city'] ? ', ' . $a['city'] : ''),
    ];
}

$priceListRates = [];
$plRateStmt = db()->query('SELECT price_list_id, product_id, rate FROM price_list_items');
foreach ($plRateStmt as $r) {
    $priceListRates[(int)$r['price_list_id']][(int)$r['product_id']] = (float)$r['rate'];
}

$productMeta = [];
foreach ($products as $p) {
    $productMeta[(int)$p['id']] = [
        'sku' => $p['sku'], 'name' => $p['name'], 'unit' => $p['unit'],
        'category' => $p['category_name'] ?: '', 'stock' => (int)$p['quantity'], 'rate' => (float)$p['selling_price'],
    ];
}

$salesChannels = ['Direct', 'Online Store', 'Marketplace', 'Retail', 'Distributor', 'POS'];
$statusBadge = ['pending' => 'secondary', 'confirmed' => 'info', 'shipped' => 'primary', 'completed' => 'success', 'cancelled' => 'danger'];

$page_title = $id ? 'Edit Sales Order' : 'New Sales Order';
require __DIR__ . '/../includes/header.php';
?>
<div class="card p-4">
  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0"><?= $id ? e($order['order_no']) : 'New Sales Order' ?> <span class="badge text-bg-<?= $statusBadge[$order['status']] ?? 'secondary' ?> badge-status"><?= e($order['status']) ?></span></h5>
  </div>

  <ul class="nav nav-tabs mb-3">
    <li class="nav-item"><button class="nav-link <?= $activeTab === 'details' ? 'active' : '' ?>" id="tab-details" data-bs-toggle="tab" data-bs-target="#pane-details" type="button">Details</button></li>
    <li class="nav-item"><button class="nav-link <?= $activeTab === 'items' ? 'active' : '' ?>" id="tab-items" data-bs-toggle="tab" data-bs-target="#pane-items" type="button">Items</button></li>
  </ul>

  <form method="post" id="soForm">
    <?= csrf_field() ?>
    <div class="tab-content">
      <div class="tab-pane fade <?= $activeTab === 'details' ? 'show active' : '' ?>" id="pane-details">
        <h6 class="text-muted mb-3"><i class="fa-solid fa-user"></i> Customer &amp; Order Information</h6>
        <div class="row g-3 mb-3">
          <div class="col-sm-3">
            <label class="form-label">Customer <span class="text-danger">*</span></label>
            <select name="customer_id" id="customerSelect" class="form-select" required>
              <option value="">— Select customer —</option>
              <?php foreach ($customers as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= (string)$order['customer_id'] === (string)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-3">
            <label class="form-label">Contact Person</label>
            <input type="text" name="contact_person" class="form-control" value="<?= e($order['contact_person'] ?? '') ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Order Date <span class="text-danger">*</span></label>
            <input type="date" name="order_date" class="form-control" value="<?= e($order['order_date']) ?>" required>
          </div>
          <div class="col-sm-3">
            <label class="form-label">Sales Order No.</label>
            <input type="text" class="form-control" value="<?= $id ? e($order['order_no']) : 'Auto-generated' ?>" disabled>
          </div>

          <div class="col-sm-3">
            <label class="form-label">Customer Address</label>
            <select name="customer_address_id" id="addressSelect" class="form-select">
              <option value="">— Select address —</option>
            </select>
            <a href="#" id="newAddressLink" class="small">+ New Address</a>
          </div>
          <div class="col-sm-3">
            <label class="form-label">Required Delivery Date</label>
            <input type="date" name="required_delivery_date" class="form-control" value="<?= e($order['required_delivery_date'] ?? '') ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Sales Channel</label>
            <select name="sales_channel" class="form-select">
              <option value="">— Select sales channel —</option>
              <?php foreach ($salesChannels as $ch): ?>
                <option value="<?= e($ch) ?>" <?= $order['sales_channel'] === $ch ? 'selected' : '' ?>><?= e($ch) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-3">
            <label class="form-label">Sales Person</label>
            <select name="sales_person_id" class="form-select">
              <option value="">— Select sales person —</option>
              <?php foreach ($salesUsers as $u): ?>
                <option value="<?= (int)$u['id'] ?>" <?= (string)($order['sales_person_id'] ?? '') === (string)$u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-sm-3">
            <label class="form-label">Price List <span class="text-danger">*</span></label>
            <select name="price_list_id" id="priceListSelect" class="form-select" required>
              <?php foreach ($priceLists as $pl): ?>
                <option value="<?= (int)$pl['id'] ?>" data-currency="<?= e($pl['currency']) ?>" <?= (string)$order['price_list_id'] === (string)$pl['id'] ? 'selected' : '' ?>><?= e($pl['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-3">
            <label class="form-label">Territory</label>
            <input type="text" name="territory" class="form-control" placeholder="e.g. West India" value="<?= e($order['territory'] ?? '') ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Customer PO No.</label>
            <input type="text" name="customer_po_no" class="form-control" value="<?= e($order['customer_po_no'] ?? '') ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Currency <span class="text-danger">*</span></label>
            <input type="text" name="currency" id="currencyInput" class="form-control" maxlength="3" style="text-transform:uppercase" value="<?= e($order['currency']) ?>" required>
          </div>

          <div class="col-sm-3">
            <label class="form-label">Project</label>
            <input type="text" name="project" class="form-control" value="<?= e($order['project'] ?? '') ?>">
          </div>
          <div class="col-sm-9">
            <label class="form-label">Notes</label>
            <input type="text" name="notes" class="form-control" value="<?= e($order['notes'] ?? '') ?>">
          </div>
        </div>
      </div>

      <div class="tab-pane fade <?= $activeTab === 'items' ? 'show active' : '' ?>" id="pane-items">
        <h6 class="text-muted mb-3"><i class="fa-solid fa-box"></i> Items</h6>
        <div class="so-line-items">
          <div class="table-responsive">
            <table class="table">
              <thead>
                <tr>
                  <th style="width:20%">Item Code <span class="text-danger">*</span></th>
                  <th style="width:14%">Description</th>
                  <th style="width:14%">Warehouse <span class="text-danger">*</span></th>
                  <th style="width:8%">Qty <span class="text-danger">*</span></th>
                  <th style="width:9%">UOM <span class="text-danger">*</span></th>
                  <th style="width:11%">Rate <span class="text-danger">*</span></th>
                  <th style="width:8%">Discount %</th>
                  <th style="width:12%" class="text-end">Amount</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
              <?php if (!$items): $items = [['product_id' => '', 'description' => '', 'warehouse_id' => default_warehouse_id(), 'quantity' => 1, 'uom' => 'pcs', 'unit_price' => 0, 'discount_percent' => 0]]; endif; ?>
              <?php foreach ($items as $it): ?>
                <tr data-row>
                  <td>
                    <select class="form-select form-select-sm js-product" name="product_id[]">
                      <option value="">— Select item —</option>
                      <?php foreach ($products as $p): ?>
                        <option value="<?= (int)$p['id'] ?>" <?= (string)$it['product_id'] === (string)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?> (<?= e($p['sku']) ?>)</option>
                      <?php endforeach; ?>
                    </select>
                  </td>
                  <td><input type="text" class="form-control form-control-sm" name="description[]" value="<?= e($it['description'] ?? '') ?>"></td>
                  <td>
                    <select class="form-select form-select-sm js-warehouse" name="item_warehouse_id[]">
                      <option value="">— Select —</option>
                      <?php foreach ($warehouses as $w): ?>
                        <option value="<?= (int)$w['id'] ?>" <?= (string)($it['warehouse_id'] ?? '') === (string)$w['id'] ? 'selected' : '' ?>><?= e($w['name']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </td>
                  <td><input type="number" min="1" class="form-control form-control-sm js-qty" name="quantity[]" value="<?= e($it['quantity']) ?>"></td>
                  <td>
                    <select class="form-select form-select-sm js-uom" name="uom[]">
                      <?php foreach ($uoms as $u): ?>
                        <option value="<?= e($u['name']) ?>" <?= (string)($it['uom'] ?? 'pcs') === (string)$u['name'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </td>
                  <td><input type="number" step="0.01" min="0" class="form-control form-control-sm js-price" name="unit_price[]" value="<?= e($it['unit_price']) ?>"></td>
                  <td><input type="number" step="0.01" min="0" max="100" class="form-control form-control-sm js-discount" name="discount_percent[]" value="<?= e($it['discount_percent'] ?? 0) ?>"></td>
                  <td class="text-end js-amount">0.00</td>
                  <td><button type="button" class="btn btn-sm btn-outline-danger js-remove-row"><i class="fa-solid fa-xmark"></i></button></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <button type="button" class="btn btn-sm btn-outline-brand mb-3 js-add-row"><i class="fa-solid fa-plus"></i> Add row</button>

          <div class="row justify-content-end">
            <div class="col-sm-5 col-lg-3">
              <div class="d-flex justify-content-between mb-1"><span class="text-muted">Total Quantity</span><strong id="soTotalQty">0</strong></div>
              <div class="d-flex justify-content-between fs-5"><span>Total</span><strong id="soTotal">0.00</strong></div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="page-actions mt-3">
      <button type="submit" class="btn btn-brand">Save Order</button>
      <a href="orders.php" class="btn btn-outline-secondary">Cancel</a>
    </div>
  </form>
</div>

<?php
$extra_js_inline = "
var productMeta = " . json_encode($productMeta) . ";
var priceListRates = " . json_encode($priceListRates) . ";
var addressesByCustomer = " . json_encode($addressesByCustomer) . ";
var selectedAddressId = " . json_encode((string)($order['customer_address_id'] ?? '')) . ";
var currentCustomerId = " . json_encode((string)($order['customer_id'] ?? '')) . ";

function rateFor(productId, priceListId) {
  if (priceListRates[priceListId] && priceListRates[priceListId][productId] !== undefined) {
    return priceListRates[priceListId][productId];
  }
  return productMeta[productId] ? productMeta[productId].rate : 0;
}

function populateAddresses(customerId, selectId) {
  var sel = document.getElementById('addressSelect');
  if (!sel) return;
  sel.innerHTML = '<option value=\"\">— Select address —</option>';
  var list = addressesByCustomer[customerId] || [];
  list.forEach(function (a) {
    var opt = document.createElement('option');
    opt.value = a.id;
    opt.textContent = a.text;
    if (selectId && String(a.id) === String(selectId)) opt.selected = true;
    sel.appendChild(opt);
  });
}

document.addEventListener('DOMContentLoaded', function () {
  var customerSelect = document.getElementById('customerSelect');
  if (customerSelect) {
    populateAddresses(customerSelect.value, selectedAddressId);
    customerSelect.addEventListener('change', function () { populateAddresses(customerSelect.value, null); });
  }

  var newAddressLink = document.getElementById('newAddressLink');
  if (newAddressLink) {
    newAddressLink.addEventListener('click', function (e) {
      e.preventDefault();
      var cid = customerSelect ? customerSelect.value : '';
      if (!cid) { alert('Select a customer first.'); return; }
      var returnTo = window.location.href.split('#')[0];
      window.location.href = '" . base_url('crm/address_form.php') . "?customer_id=' + cid + '&return_to=' + encodeURIComponent(returnTo);
    });
  }

  var wrap = document.querySelector('.so-line-items');
  if (!wrap) return;
  var tbody = wrap.querySelector('tbody');
  var priceListSelect = document.getElementById('priceListSelect');

  function recalc() {
    var total = 0, totalQty = 0;
    wrap.querySelectorAll('tr[data-row]').forEach(function (row) {
      var qty = parseFloat(row.querySelector('.js-qty')?.value || 0) || 0;
      var price = parseFloat(row.querySelector('.js-price')?.value || 0) || 0;
      var discount = parseFloat(row.querySelector('.js-discount')?.value || 0) || 0;
      var amount = qty * price * (1 - discount / 100);
      var amtEl = row.querySelector('.js-amount');
      if (amtEl) amtEl.textContent = amount.toFixed(2);
      total += amount;
      totalQty += qty;
    });
    document.getElementById('soTotal').textContent = total.toFixed(2);
    document.getElementById('soTotalQty').textContent = totalQty;
  }

  function applyProductDefaults(row) {
    var productSelect = row.querySelector('.js-product');
    var pid = productSelect.value;
    if (!pid || !productMeta[pid]) return;
    var meta = productMeta[pid];
    var uomSelect = row.querySelector('.js-uom');
    if (uomSelect) {
      var found = false;
      for (var i = 0; i < uomSelect.options.length; i++) {
        if (uomSelect.options[i].value === meta.unit) { uomSelect.selectedIndex = i; found = true; break; }
      }
      if (!found) uomSelect.value = meta.unit;
    }
    var priceInput = row.querySelector('.js-price');
    if (priceInput) priceInput.value = rateFor(pid, priceListSelect ? priceListSelect.value : null);
  }

  wrap.addEventListener('input', recalc);

  wrap.addEventListener('change', function (e) {
    if (e.target.classList.contains('js-product')) {
      applyProductDefaults(e.target.closest('tr[data-row]'));
    }
    recalc();
  });

  if (priceListSelect) {
    priceListSelect.addEventListener('change', function () {
      wrap.querySelectorAll('tr[data-row]').forEach(function (row) {
        var pid = row.querySelector('.js-product').value;
        if (pid) {
          row.querySelector('.js-price').value = rateFor(pid, priceListSelect.value);
        }
      });
      recalc();
    });
  }

  wrap.addEventListener('click', function (e) {
    var addBtn = e.target.closest('.js-add-row');
    if (addBtn && tbody) {
      var rows = tbody.querySelectorAll('tr[data-row]');
      var clone = rows[rows.length - 1].cloneNode(true);
      clone.querySelectorAll('input').forEach(function (inp) {
        if (inp.classList.contains('js-qty')) inp.value = 1;
        else if (inp.classList.contains('js-discount')) inp.value = 0;
        else inp.value = '';
      });
      clone.querySelectorAll('select').forEach(function (sel) { sel.selectedIndex = 0; });
      var amt = clone.querySelector('.js-amount');
      if (amt) amt.textContent = '0.00';
      tbody.appendChild(clone);
      recalc();
      return;
    }
    var rmBtn = e.target.closest('.js-remove-row');
    if (rmBtn) {
      var rows2 = tbody.querySelectorAll('tr[data-row]');
      if (rows2.length > 1) {
        rmBtn.closest('tr[data-row]').remove();
        recalc();
      }
    }
  });

  recalc();
});
";
require __DIR__ . '/../includes/footer.php';
?>
