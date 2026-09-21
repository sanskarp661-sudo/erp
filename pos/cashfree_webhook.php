<?php
require_once __DIR__ . '/../includes/auth.php';
// No require_login() — Cashfree calls this server-to-server, with no
// session/cookies of ours. Authenticity comes purely from verifying the
// webhook signature below; nothing in this payload is trusted before
// that check passes.

header('Content-Type: application/json');

$rawBody = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '';
$timestamp = $_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'] ?? '';
$reference = trim((string)($_GET['ref'] ?? ''));

if (!cashfree_verify_webhook_signature($signature, $rawBody, $timestamp)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid signature.']);
    exit;
}

$event = json_decode($rawBody, true);
if (!is_array($event) || $reference === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Malformed payload.']);
    exit;
}

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM pos_gateway_payments WHERE reference = ?');
$stmt->execute([$reference]);
$row = $stmt->fetch();

if (!$row) {
    // Unknown reference — acknowledge so Cashfree doesn't keep retrying,
    // but there's nothing of ours to update.
    http_response_code(200);
    echo json_encode(['ok' => true]);
    exit;
}

$pdo->prepare('UPDATE pos_gateway_payments SET last_webhook_payload = ? WHERE id = ?')->execute([$rawBody, $row['id']]);

// Already handled — Cashfree may redeliver the same webhook more than
// once, so this must be idempotent.
if ($row['status'] !== 'created') {
    http_response_code(200);
    echo json_encode(['ok' => true]);
    exit;
}

$eventType = $event['type'] ?? '';
$paymentStatus = $event['data']['payment']['payment_status'] ?? null;

if ($eventType === 'PAYMENT_SUCCESS_WEBHOOK' && $paymentStatus === 'SUCCESS') {
    $cart = json_decode($row['cart_json'], true) ?: [];
    $lineItems = [];
    foreach ($cart as $item) {
        $pid = (int)$item['product_id'];
        $lineItems[$pid] = ($lineItems[$pid] ?? 0) + (int)$item['quantity'];
    }

    $pdo->beginTransaction();
    try {
        $result = pos_complete_sale($pdo, (int)$row['customer_id'], $lineItems, 'upi', $reference, $row['created_by'] !== null ? (int)$row['created_by'] : null);
        $pdo->prepare('UPDATE pos_gateway_payments SET status = ?, sales_order_id = ? WHERE id = ?')
            ->execute(['paid', $result['order_id'], $row['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        // Cashfree confirmed the money moved but we couldn't fulfil the
        // sale (e.g. stock sold out in the meantime) — flag for manual
        // review rather than silently losing track of a paid order.
        $pdo->prepare('UPDATE pos_gateway_payments SET status = ? WHERE id = ?')->execute(['failed', $row['id']]);
    }
} elseif ($paymentStatus === 'FAILED') {
    $pdo->prepare('UPDATE pos_gateway_payments SET status = ? WHERE id = ?')->execute(['failed', $row['id']]);
}
// Any other event (link expired, refund, etc.) is left as 'created' —
// no action taken.

http_response_code(200);
echo json_encode(['ok' => true]);
