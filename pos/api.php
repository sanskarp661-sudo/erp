<?php
/**
 * JSON actions for the POS screens: hold a cart, save a customer, delete a
 * held order, email a receipt. Every call is POST + CSRF.
 */
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');

function pos_json(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if (!is_logged_in()) {
    pos_json(['ok' => false, 'error' => 'Your session has expired. Please log in again.'], 401);
}
if (!is_post() || !hash_equals($_SESSION['csrf_token'] ?? '', (string)($_POST['csrf_token'] ?? ''))) {
    pos_json(['ok' => false, 'error' => 'This page is out of date. Reload it and try again.'], 400);
}
if (!can_edit_module('pos')) {
    pos_json(['ok' => false, 'error' => 'You do not have access to POS.'], 403);
}
if (!pos_schema_ready()) {
    pos_json(['ok' => false, 'error' => 'Run database migration 031_pos_module.sql first.'], 500);
}

$pdo = db();
$ctx = pos_context();
$action = (string)input('action');

try {
    switch ($action) {
        case 'hold':
            $pdo->beginTransaction();
            $held = pos_hold_cart($pdo, pos_payload_from_request(), $ctx, current_user()['id'], (string)input('note'));
            $pdo->commit();
            pos_json(['ok' => true] + $held);

        case 'save_customer':
            $customer = pos_save_customer($_POST);
            pos_json(['ok' => true, 'customer' => $customer]);

        case 'delete_held':
            if (!pos_can('delete_held')) {
                pos_json(['ok' => false, 'error' => 'Only a POS manager can delete held orders.'], 403);
            }
            $pdo->prepare('DELETE FROM pos_held_orders WHERE id = ?')->execute([(int)input('id')]);
            pos_json(['ok' => true]);

        case 'email_receipt':
            $orderId = (int)input('order_id');
            $email = trim((string)input('email'));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                pos_json(['ok' => false, 'error' => 'Enter a valid email address.']);
            }
            if (!pos_order($orderId)) {
                pos_json(['ok' => false, 'error' => 'Order not found.']);
            }
            if (input('save') === '1') {
                $pdo->prepare("UPDATE customers c JOIN sales_orders so ON so.customer_id = c.id SET c.email = ? WHERE so.id = ? AND (c.email IS NULL OR c.email = '') AND c.name <> 'Walk-in Customer'")
                    ->execute([$email, $orderId]);
            }
            if (!pos_email_receipt($orderId, $email)) {
                pos_json(['ok' => false, 'error' => 'The mail server did not accept the email. Check that PHP mail is enabled for this domain in hPanel > Emails.']);
            }
            pos_json(['ok' => true, 'message' => 'Receipt sent to ' . $email . '.']);

        default:
            pos_json(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
} catch (RuntimeException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    pos_json(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    pos_json(['ok' => false, 'error' => (defined('APP_DEBUG') && APP_DEBUG && can_manage_module('pos')) ? $e->getMessage() : 'Something went wrong. Please try again.'], 500);
}
