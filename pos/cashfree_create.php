<?php
require_once __DIR__ . '/../includes/auth.php';
require_module_edit('pos');

header('Content-Type: application/json');

if (!is_post()) {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}
csrf_verify();

if (!cashfree_configured()) {
    http_response_code(400);
    echo json_encode(['error' => 'Cashfree is not configured. Add CASHFREE_CLIENT_ID / CASHFREE_CLIENT_SECRET in config/config.php.']);
    exit;
}

$pdo = db();
$ctx = pos_context();
$payload = pos_payload_from_request();
$customerPhone = trim((string)input('customer_phone'));

if (pos_flag('pos_require_shift') && !$ctx['shift']) {
    http_response_code(400);
    echo json_encode(['error' => 'Open a shift on this terminal before taking payments.']);
    exit;
}
if (!preg_match('/^[6-9]\d{9}$/', $customerPhone)) {
    http_response_code(400);
    echo json_encode(['error' => 'Please enter a valid 10-digit mobile number for the UPI payment request.']);
    exit;
}
if ($payload['exchange_return_id']) {
    http_response_code(400);
    echo json_encode(['error' => 'Exchange credit cannot be combined with Cashfree. Take the balance as Cash, Card or UPI instead.']);
    exit;
}

// Read-only price + stock check. Nothing is deducted or committed here;
// that only happens once the webhook confirms the payment actually went
// through (see includes/pos.php: pos_complete_sale()).
try {
    $cart = pos_price_cart($payload, $ctx);
} catch (RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}
$customerId = $payload['customer_id'] ?: pos_walk_in_customer_id();
$stmt = $pdo->prepare('SELECT id, name FROM customers WHERE id = ?');
$stmt->execute([$customerId]);
$customer = $stmt->fetch();
if (!$customer) {
    http_response_code(400);
    echo json_encode(['error' => 'Customer not found.']);
    exit;
}
$subtotal = $cart['grand_total'];
if ($subtotal <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Cart total must be greater than zero.']);
    exit;
}

// Freeze the prices the customer is paying, so the webhook posts exactly this bill.
$payload['customer_id'] = $customerId;
$payload['lines'] = array_map(fn($l) => ['product_id' => $l['product_id'], 'qty' => $l['qty'], 'price' => $l['price'],
    'disc_type' => $l['disc_type'], 'disc_value' => $l['disc_value'], 'tax_rate' => $l['tax_rate']], $cart['lines']);
$stored = json_encode(['payload' => $payload, 'ctx' => [
    'warehouse_id' => $ctx['warehouse_id'], 'profile_id' => $ctx['profile_id'], 'shift_id' => $ctx['shift_id'], 'price_list_id' => $ctx['price_list_id'],
]]);

$reference = 'POSGW' . strtoupper(bin2hex(random_bytes(8)));
$notifyUrl = base_url('pos/cashfree_webhook.php') . '?ref=' . urlencode($reference);

try {
    if (cashfree_product() === 'payment_link') {
        $link = cashfree_create_payment_link([
            'link_id' => $reference,
            'amount' => $subtotal,
            'purpose' => 'POS Sale',
            'customer_name' => $customer['name'],
            'customer_phone' => $customerPhone,
            'notify_url' => $notifyUrl,
            'return_url' => base_url('pos/orders.php'),
        ]);

        $pdo->prepare('INSERT INTO pos_gateway_payments (reference, gateway, gateway_link_id, link_url, customer_id, cart_json, amount, status, created_by) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$reference, 'cashfree', $link['link_id'] ?? null, $link['link_url'], $customerId, $stored, $subtotal, 'created', current_user()['id']]);

        echo json_encode(['reference' => $reference, 'mode' => 'payment_link', 'link_url' => $link['link_url'], 'amount' => $subtotal]);
    } else {
        $order = cashfree_create_order([
            'order_id' => $reference,
            'amount' => $subtotal,
            'purpose' => 'POS Sale',
            'customer_id' => 'CUST' . $customerId,
            'customer_name' => $customer['name'],
            'customer_phone' => $customerPhone,
            'notify_url' => $notifyUrl,
            'return_url' => base_url('pos/orders.php'),
        ]);

        $pdo->prepare('INSERT INTO pos_gateway_payments (reference, gateway, gateway_link_id, customer_id, cart_json, amount, status, created_by) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$reference, 'cashfree', $order['cf_order_id'] ?? null, $customerId, $stored, $subtotal, 'created', current_user()['id']]);

        echo json_encode([
            'reference' => $reference,
            'mode' => 'orders',
            'payment_session_id' => $order['payment_session_id'],
            'cashfree_env' => defined('CASHFREE_ENV') && CASHFREE_ENV === 'production' ? 'production' : 'sandbox',
            'amount' => $subtotal,
        ]);
    }
} catch (Throwable $e) {
    http_response_code(502);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}
