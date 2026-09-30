<?php
/**
 * GET /api/v1/order_status.php?order_no=SO-000123
 * GET /api/v1/order_status.php?website_order_id=WEB-10293
 *
 * Order-status lookup — a polling fallback for anything a missed webhook
 * (see includes/webhooks.php) wouldn't have delivered. Exactly one of the
 * two params is required; website_order_id matches sales_orders.customer_po_no,
 * which is where /api/v1/orders.php stored the value you passed in as
 * "website_order_id" at order-creation time.
 *
 * Response includes the Sales Order's own status plus, when they exist,
 * its linked Delivery Note (fulfillment) and Invoice (payment) status —
 * only Online Store orders (sales_channel = 'Online Store') are visible
 * through this endpoint.
 */

require_once __DIR__ . '/../../includes/api.php';
api_require_key();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    api_error('Method not allowed.', 405);
}

$orderNo = input('order_no');
$websiteOrderId = input('website_order_id');

if ($orderNo === '' && $websiteOrderId === '') {
    api_error('Provide either order_no or website_order_id.');
}

if ($orderNo !== '') {
    $stmt = db()->prepare("SELECT * FROM sales_orders WHERE order_no = ? AND sales_channel = 'Online Store'");
    $stmt->execute([$orderNo]);
} else {
    $stmt = db()->prepare("SELECT * FROM sales_orders WHERE customer_po_no = ? AND sales_channel = 'Online Store' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$websiteOrderId]);
}
$order = $stmt->fetch();

if (!$order) {
    api_error('Order not found.', 404);
}

$dnStmt = db()->prepare('SELECT dn_no, status FROM delivery_notes WHERE sales_order_id = ? ORDER BY id DESC LIMIT 1');
$dnStmt->execute([$order['id']]);
$dn = $dnStmt->fetch();

$invStmt = db()->prepare("SELECT invoice_no, status, amount_paid, total FROM invoices WHERE sales_order_id = ? AND status <> 'cancelled' ORDER BY id DESC LIMIT 1");
$invStmt->execute([$order['id']]);
$invoice = $invStmt->fetch();

api_respond([
    'order_no' => $order['order_no'],
    'website_order_id' => $order['customer_po_no'],
    'status' => $order['status'],
    'total_amount' => (float)$order['total_amount'],
    'order_date' => $order['order_date'],
    'delivery_note' => $dn ? ['dn_no' => $dn['dn_no'], 'status' => $dn['status']] : null,
    'invoice' => $invoice ? [
        'invoice_no' => $invoice['invoice_no'], 'status' => $invoice['status'],
        'amount_paid' => (float)$invoice['amount_paid'], 'total' => (float)$invoice['total'],
    ] : null,
]);
