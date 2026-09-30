<?php
/**
 * POST /api/v1/orders.php
 *
 * Creates a Sales Order (sales_channel = 'Online Store', status =
 * 'pending') from a website checkout. Line-item prices are always
 * resolved server-side from the ERP's own current pricing — a client-
 * submitted price is never trusted. This endpoint does not touch stock;
 * like every other Sales Order in this app, stock is only deducted once
 * staff create and deliver a Delivery Note against it.
 *
 * Request body (JSON):
 * {
 *   "website_order_id": "WEB-10293",           // your own order id, echoed back on status webhooks/lookups
 *   "customer": {
 *     "name": "Jane Doe",                       // required
 *     "email": "jane@example.com",              // required unless phone given — used to find-or-create the customer
 *     "phone": "9999999999",
 *     "company": null
 *   },
 *   "shipping_address": {                       // optional
 *     "label": "Home",
 *     "address_line": "123 Main St",            // required if shipping_address is present
 *     "city": "Mumbai", "state": "MH", "pincode": "400001", "country": "India",
 *     "contact_person": "Jane Doe", "contact_phone": "9999999999", "contact_email": null
 *   },
 *   "items": [ { "sku": "ABC-123", "quantity": 2 }, ... ],  // required, at least one
 *   "notes": "Please deliver after 5pm"
 * }
 *
 * Response: { "order_no": "SO-000123", "status": "pending", "total_amount": 999.00, "items": [...] }
 *
 * NOTE ON TAXES: this endpoint does not compute GST/tax lines (the ERP's
 * own Sales Order tax engine — sales_order_taxes — is account/template
 * driven and out of scope for a v1 integration). total_amount here is the
 * pre-tax line-item sum. If you need tax-inclusive totals shown at
 * checkout, compute them on the website side, or extend this endpoint.
 */

require_once __DIR__ . '/../../includes/api.php';
api_require_key();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    api_error('Method not allowed.', 405);
}

$body = api_body();

$customer = $body['customer'] ?? [];
$name = trim((string)($customer['name'] ?? ''));
$email = trim((string)($customer['email'] ?? ''));
$phone = trim((string)($customer['phone'] ?? ''));
$items = $body['items'] ?? [];
$websiteOrderId = trim((string)($body['website_order_id'] ?? '')) ?: null;
$notes = trim((string)($body['notes'] ?? '')) ?: null;

if ($name === '') {
    api_error('customer.name is required.');
}
if ($email === '' && $phone === '') {
    api_error('customer.email or customer.phone is required.');
}
if (!is_array($items) || !$items) {
    api_error('items must be a non-empty array.');
}

// Resolve every line item against the ERP's own current product/price
// data before touching the database — a bad SKU or non-positive quantity
// fails the whole order rather than silently dropping a line.
$resolvedItems = [];
foreach ($items as $i => $item) {
    $sku = trim((string)($item['sku'] ?? ''));
    $qty = (int)($item['quantity'] ?? 0);
    if ($sku === '' || $qty <= 0) {
        api_error("items[$i] needs a non-empty sku and a positive quantity.");
    }
    $stmt = db()->prepare("SELECT id, unit, selling_price FROM products WHERE sku = ? AND status = 'active'");
    $stmt->execute([$sku]);
    $product = $stmt->fetch();
    if (!$product) {
        api_error("No active product with sku \"$sku\".", 422);
    }
    $priceStmt = db()->prepare("
      SELECT pli.rate FROM price_list_items pli
      JOIN price_lists pl ON pl.id = pli.price_list_id
      WHERE pli.product_id = ? AND pl.is_default = 1 AND pl.status = 'active'
      LIMIT 1
    ");
    $priceStmt->execute([$product['id']]);
    $rate = $priceStmt->fetchColumn();
    $unitPrice = $rate !== false ? (float)$rate : (float)$product['selling_price'];
    $subtotal = round($qty * $unitPrice, 2);
    $resolvedItems[] = [
        'product_id' => (int)$product['id'], 'sku' => $sku, 'quantity' => $qty,
        'uom' => $product['unit'], 'unit_price' => $unitPrice, 'subtotal' => $subtotal,
    ];
}
$totalAmount = round(array_sum(array_column($resolvedItems, 'subtotal')), 2);

$pdo = db();
$pdo->beginTransaction();
try {
    // Find-or-create the customer, matched by email (or phone if no email
    // was given) — the same customer placing repeat orders should reuse
    // one customers row rather than creating a new one every time.
    $customerId = null;
    if ($email !== '') {
        $stmt = $pdo->prepare('SELECT id FROM customers WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $customerId = $stmt->fetchColumn() ?: null;
    }
    if (!$customerId && $phone !== '') {
        $stmt = $pdo->prepare('SELECT id FROM customers WHERE phone = ? LIMIT 1');
        $stmt->execute([$phone]);
        $customerId = $stmt->fetchColumn() ?: null;
    }
    if (!$customerId) {
        $pdo->prepare('INSERT INTO customers (name, company, email, phone) VALUES (?,?,?,?)')
            ->execute([$name, $customer['company'] ?? null, $email ?: null, $phone ?: null]);
        $customerId = (int)$pdo->lastInsertId();
    } else {
        $customerId = (int)$customerId;
    }

    // Find-or-create the shipping address, matched by (customer_id, address_line).
    $addressId = null;
    $addr = $body['shipping_address'] ?? null;
    if (is_array($addr) && trim((string)($addr['address_line'] ?? '')) !== '') {
        $addressLine = trim($addr['address_line']);
        $stmt = $pdo->prepare('SELECT id FROM customer_addresses WHERE customer_id = ? AND address_line = ? LIMIT 1');
        $stmt->execute([$customerId, $addressLine]);
        $addressId = $stmt->fetchColumn() ?: null;
        if (!$addressId) {
            $pdo->prepare('INSERT INTO customer_addresses (customer_id, label, address_line, city, state, pincode, country, contact_person, contact_phone, contact_email) VALUES (?,?,?,?,?,?,?,?,?,?)')
                ->execute([
                    $customerId, trim((string)($addr['label'] ?? '')) ?: 'Address', $addressLine,
                    $addr['city'] ?? null, $addr['state'] ?? null, $addr['pincode'] ?? null,
                    trim((string)($addr['country'] ?? '')) ?: 'India',
                    $addr['contact_person'] ?? null, $addr['contact_phone'] ?? null, $addr['contact_email'] ?? null,
                ]);
            $addressId = (int)$pdo->lastInsertId();
        } else {
            $addressId = (int)$addressId;
        }
    }

    $orderNo = next_code('SO', 'sales_orders', 'order_no');
    $pdo->prepare('INSERT INTO sales_orders (order_no, customer_id, customer_address_id, ship_to_address_id, warehouse_id, order_date, sales_channel, status, notes, customer_po_no, total_amount, net_amount) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$orderNo, $customerId, $addressId, $addressId, default_warehouse_id(), today(), 'Online Store', 'pending', $notes, $websiteOrderId, $totalAmount, $totalAmount]);
    $orderId = (int)$pdo->lastInsertId();

    $itemStmt = $pdo->prepare('INSERT INTO sales_order_items (order_id, product_id, quantity, uom, uom_conversion_factor, unit_price, discount_percent, subtotal) VALUES (?,?,?,?,1.000,?,0,?)');
    foreach ($resolvedItems as $li) {
        $itemStmt->execute([$orderId, $li['product_id'], $li['quantity'], $li['uom'], $li['unit_price'], $li['subtotal']]);
    }

    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    api_error('Could not create order: ' . $e->getMessage(), 500);
}

api_respond([
    'order_no' => $orderNo,
    'status' => 'pending',
    'total_amount' => $totalAmount,
    'items' => array_map(fn($li) => ['sku' => $li['sku'], 'quantity' => $li['quantity'], 'unit_price' => $li['unit_price'], 'subtotal' => $li['subtotal']], $resolvedItems),
], 201);
