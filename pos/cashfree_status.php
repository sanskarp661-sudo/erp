<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

header('Content-Type: application/json');

$reference = trim((string)input('reference'));
if ($reference === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing reference.']);
    exit;
}

$stmt = db()->prepare('SELECT status, sales_order_id FROM pos_gateway_payments WHERE reference = ?');
$stmt->execute([$reference]);
$row = $stmt->fetch();
if (!$row) {
    http_response_code(404);
    echo json_encode(['error' => 'Not found.']);
    exit;
}

$response = ['status' => $row['status']];
if ($row['status'] === 'paid' && $row['sales_order_id']) {
    $invStmt = db()->prepare('SELECT id FROM invoices WHERE sales_order_id = ? LIMIT 1');
    $invStmt->execute([$row['sales_order_id']]);
    $invoiceId = $invStmt->fetchColumn();
    if ($invoiceId) {
        $response['redirect'] = base_url('print.php?doctype=invoice&id=' . $invoiceId . '&pos=1');
    }
}
echo json_encode($response);
