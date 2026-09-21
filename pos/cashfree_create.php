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
$customerId = (int)input('customer_id');
$customerPhone = trim((string)input('customer_phone'));
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

if (!$customerId) {
    http_response_code(400);
    echo json_encode(['error' => 'Please select a customer.']);
    exit;
}
if (!$lineItems) {
    http_response_code(400);
    echo json_encode(['error' => 'Cart is empty.']);
    exit;
}
if (!preg_match('/^[6-9]\d{9}$/', $customerPhone)) {
    http_response_code(400);
    echo json_encode(['error' => 'Please enter a valid 10-digit mobile number for the UPI payment request.']);
    exit;
}

$stmt = $pdo->prepare('SELECT id, name FROM customers WHERE id = ?');
$stmt->execute([$customerId]);
$customer = $stmt->fetch();
if (!$customer) {
    http_response_code(400);
    echo json_encode(['error' => 'Customer not found.']);
    exit;
}

// Read-only stock/price check. Nothing is deducted or committed here —
// that only happens once the webhook confirms the payment actually
// went through (see includes/pos.php::pos_complete_sale()).
$posWarehouseId = default_warehouse_id();
if (!$posWarehouseId) {
    http_response_code(400);
    echo json_encode(['error' => 'No warehouse is set up yet — ask an admin to create one under Supply Chain → Warehouses.']);
    exit;
}

$subtotal = 0;
$cart = [];
foreach ($lineItems as $pid => $qty) {
    $stmt = $pdo->prepare('SELECT id, name, selling_price FROM products WHERE id = ?');
    $stmt->execute([$pid]);
    $product = $stmt->fetch();
    if (!$product) {
        http_response_code(400);
        echo json_encode(['error' => 'A product in the cart no longer exists.']);
        exit;
    }
    if (warehouse_stock($pid, $posWarehouseId) < $qty) {
        http_response_code(400);
        echo json_encode(['error' => 'Not enough stock for ' . $product['name'] . '.']);
        exit;
    }
    $subtotal += $qty * $product['selling_price'];
    $cart[] = ['product_id' => (int)$pid, 'quantity' => $qty];
}

if ($subtotal <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Cart total must be greater than zero.']);
    exit;
}

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
            'return_url' => base_url('pos/index.php'),
        ]);

        $pdo->prepare('INSERT INTO pos_gateway_payments (reference, gateway, gateway_link_id, link_url, customer_id, cart_json, amount, status, created_by) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$reference, 'cashfree', $link['link_id'] ?? null, $link['link_url'], $customerId, json_encode($cart), $subtotal, 'created', current_user()['id']]);

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
            'return_url' => base_url('pos/index.php'),
        ]);

        $pdo->prepare('INSERT INTO pos_gateway_payments (reference, gateway, gateway_link_id, customer_id, cart_json, amount, status, created_by) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$reference, 'cashfree', $order['cf_order_id'] ?? null, $customerId, json_encode($cart), $subtotal, 'created', current_user()['id']]);

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
