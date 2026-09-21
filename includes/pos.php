<?php
/**
 * Shared POS sale-completion logic. Used by both the synchronous
 * cash/card/etc. checkout in pos/index.php and the Cashfree webhook
 * (pos/cashfree_webhook.php), which only calls this once payment is
 * actually confirmed — so both paths produce an identical Sales Order /
 * Invoice / stock movement, just triggered at different points in time.
 */

require_once __DIR__ . '/functions.php';

/**
 * @param array $lineItems [product_id => quantity, ...]
 * @throws RuntimeException if there's no warehouse, a product vanished, or stock is short.
 * @return array ['order_id' => int, 'invoice_id' => int, 'subtotal' => float]
 */
function pos_complete_sale(PDO $pdo, int $customerId, array $lineItems, string $method, ?string $reference, ?int $createdBy): array
{
    $posWarehouseId = default_warehouse_id();
    if (!$posWarehouseId) {
        throw new RuntimeException('No warehouse is set up yet — ask an admin to create one under Supply Chain → Warehouses.');
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
        ->execute([$orderNo, $customerId, today(), 'completed', 'POS sale', $subtotal, $createdBy]);
    $orderId = (int)$pdo->lastInsertId();

    $itemStmt = $pdo->prepare('INSERT INTO sales_order_items (order_id, product_id, quantity, unit_price, subtotal) VALUES (?,?,?,?,?)');
    foreach ($resolved as $r) {
        $itemStmt->execute([$orderId, $r['product']['id'], $r['qty'], $r['unit_price'], $r['subtotal']]);
        stock_move($r['product']['id'], $posWarehouseId, -$r['qty'], 'out', $orderNo, 'POS sale', $createdBy);
    }

    $invoiceNo = next_code('INV', 'invoices', 'invoice_no');
    $pdo->prepare("INSERT INTO invoices (invoice_no, sales_order_id, customer_id, invoice_date, due_date, status, subtotal, tax, total, amount_paid, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$invoiceNo, $orderId, $customerId, today(), today(), 'paid', $subtotal, 0, $subtotal, $subtotal, 'POS sale', $createdBy]);
    $invoiceId = (int)$pdo->lastInsertId();

    $invItemStmt = $pdo->prepare('INSERT INTO invoice_items (invoice_id, product_id, description, quantity, unit_price, subtotal) VALUES (?,?,?,?,?,?)');
    foreach ($resolved as $r) {
        $invItemStmt->execute([$invoiceId, $r['product']['id'], $r['product']['name'], $r['qty'], $r['unit_price'], $r['subtotal']]);
    }

    $pdo->prepare('INSERT INTO payments (invoice_id, amount, payment_date, method, reference, notes, created_by) VALUES (?,?,?,?,?,?,?)')
        ->execute([$invoiceId, $subtotal, today(), $method, $reference ?? ('POS-' . $orderNo), 'POS sale', $createdBy]);

    return ['order_id' => $orderId, 'invoice_id' => $invoiceId, 'subtotal' => $subtotal];
}
