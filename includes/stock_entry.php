<?php
/**
 * Stock Entry helpers — shared by supply-chain/stock_entry_form.php and
 * stock_entry_view.php. A Stock Entry is a multi-line document that only
 * moves stock when it is submitted (stock_entry_submit) and puts it all
 * back when a submitted entry is cancelled (stock_entry_cancel). Both go
 * through stock_move(), the single code path allowed to change stock.
 */

require_once __DIR__ . '/stock.php';

/**
 * Called by every Stock Entry page before it touches the database. If the
 * Stock Entry tables are missing (migration 026 never ran, or stopped
 * part-way), shows how to fix it instead of a blank 500. Any other error
 * on these pages is shown to admins so it can be reported.
 */
function stock_entry_require_schema(): void
{
    set_exception_handler(function (Throwable $e) {
        error_log('Stock Entry: ' . $e);
        if (!headers_sent()) http_response_code(500);
        $detail = can_edit_admin_section() || (defined('APP_DEBUG') && APP_DEBUG)
            ? '<pre class="small mb-0" style="white-space:pre-wrap">' . e($e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')') . '</pre>'
            : '<p class="mb-0">Please ask an administrator to check this page.</p>';
        echo '<div class="card p-4 m-3" style="max-width:820px"><h5 class="mb-2">This Stock Entry page hit an error</h5>' . $detail . '</div>';
    });
    $ready = true;
    foreach (['stock_entries', 'stock_entry_items', 'stock_entry_costs'] as $table) {
        if (!db()->query('SHOW TABLES LIKE ' . db()->quote($table))->fetchColumn()) {
            $ready = false;
        }
    }
    if ($ready) {
        return;
    }
    global $page_title;
    $page_title = $page_title ?? 'Stock Entries';
    require __DIR__ . '/header.php';
    echo '<div class="card p-4" style="max-width:720px">'
        . '<h5 class="mb-2"><i class="fa-solid fa-database text-warning"></i> Stock Entry needs a database update</h5>'
        . '<p class="mb-2">The Stock Entry tables are missing from this database. Run migration <code>database/migrations/032_stock_entry_repair.sql</code> to add them.</p>'
        . '<ol class="mb-0"><li>Open <strong>phpMyAdmin</strong> in hPanel and select the ERP database.</li>'
        . '<li>Go to <strong>Import</strong> and upload <code>032_stock_entry_repair.sql</code>.</li>'
        . '<li>Reload this page.</li></ol></div>';
    require __DIR__ . '/footer.php';
    exit;
}

function stock_entry_types(): array
{
    return [
        'material_receipt'  => 'Material Receipt',
        'material_issue'    => 'Material Issue',
        'material_transfer' => 'Material Transfer',
        'stock_adjustment'  => 'Stock Adjustment',
    ];
}

/**
 * Which way a line moves stock: ['out' => bool, 'in' => bool]. Adjustment
 * lines are signed — a negative quantity reduces stock at the line's
 * target warehouse, a positive one adds to it.
 */
function stock_entry_direction(string $type, int $qty): array
{
    switch ($type) {
        case 'material_receipt':  return ['out' => false, 'in' => true];
        case 'material_issue':    return ['out' => true, 'in' => false];
        case 'material_transfer': return ['out' => true, 'in' => true];
        default:                  return ['out' => $qty < 0, 'in' => $qty > 0];
    }
}

/**
 * Fills in basic_amount / additional_cost / valuation_rate / amount on
 * each line and returns [lines, totals]. Additional costs are spread over
 * the incoming lines (by basic amount or by quantity); an entry with no
 * incoming lines (e.g. a Material Issue) spreads them over every line.
 */
function stock_entry_compute(array $lines, float $totalCosts, string $type, string $distributeBy): array
{
    $targets = [];
    foreach ($lines as $i => $li) {
        $lines[$i]['basic_amount'] = round(abs($li['quantity']) * $li['basic_rate'], 2);
        if (stock_entry_direction($type, $li['quantity'])['in']) {
            $targets[] = $i;
        }
    }
    if (!$targets) {
        $targets = array_keys($lines);
    }
    $weightOf = fn($li) => $distributeBy === 'qty' ? abs($li['quantity']) : $li['basic_amount'];
    $totalWeight = 0;
    foreach ($targets as $i) {
        $totalWeight += $weightOf($lines[$i]);
    }

    $allocated = 0;
    $lastTarget = end($targets);
    foreach ($lines as $i => $li) {
        $share = 0;
        if ($totalCosts > 0 && in_array($i, $targets, true)) {
            if ($i === $lastTarget) {
                $share = round($totalCosts - $allocated, 2); // absorb rounding pennies
            } else {
                $share = $totalWeight > 0 ? round($totalCosts * $weightOf($li) / $totalWeight, 2) : round($totalCosts / count($targets), 2);
            }
            $allocated += $share;
        }
        $lines[$i]['additional_cost'] = $share;
        $lines[$i]['amount'] = round($lines[$i]['basic_amount'] + $share, 2);
        $absQty = abs($li['quantity']);
        $lines[$i]['valuation_rate'] = $absQty ? round($lines[$i]['amount'] / $absQty, 2) : 0;
    }

    $totals = ['total_qty' => 0, 'total_outgoing_value' => 0, 'total_incoming_value' => 0];
    foreach ($lines as $li) {
        $dir = stock_entry_direction($type, $li['quantity']);
        $totals['total_qty'] += abs($li['quantity']);
        if ($dir['out']) {
            $totals['total_outgoing_value'] += $li['basic_amount'];
        }
        if ($dir['in']) {
            $totals['total_incoming_value'] += $li['amount'];
        }
    }
    $totals['total_outgoing_value'] = round($totals['total_outgoing_value'], 2);
    $totals['total_incoming_value'] = round($totals['total_incoming_value'], 2);
    $totals['total_additional_costs'] = round($totalCosts, 2);
    $totals['value_difference'] = round($totals['total_incoming_value'] - $totals['total_outgoing_value'], 2);
    return [$lines, $totals];
}

/** Splits a newline/comma separated serial-number list into clean values. */
function stock_entry_serials(?string $raw): array
{
    if ($raw === null || trim($raw) === '') {
        return [];
    }
    return array_values(array_unique(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $raw)), 'strlen')));
}

/** Existing batch id for a product's batch_no, or null. */
function stock_entry_batch_id(int $productId, string $batchNo): ?int
{
    $stmt = db()->prepare('SELECT id FROM product_batches WHERE product_id = ? AND batch_no = ?');
    $stmt->execute([$productId, $batchNo]);
    $id = $stmt->fetchColumn();
    return $id !== false ? (int)$id : null;
}

function stock_entry_load_items(int $entryId): array
{
    $stmt = db()->prepare('SELECT sei.*, p.name product_name, p.sku, p.has_batch_no, p.has_serial_no FROM stock_entry_items sei JOIN products p ON p.id = sei.product_id WHERE sei.stock_entry_id = ? ORDER BY sei.sort_order, sei.id');
    $stmt->execute([$entryId]);
    return $stmt->fetchAll();
}

/**
 * Posts a draft entry's stock movements and marks it submitted. Must run
 * inside a transaction the caller owns. Throws RuntimeException with a
 * user-facing message on any validation failure (not enough stock, a
 * missing batch/serial number, ...), so the caller can roll everything back.
 */
function stock_entry_submit(int $entryId, ?int $userId): void
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM stock_entries WHERE id = ? FOR UPDATE');
    $stmt->execute([$entryId]);
    $entry = $stmt->fetch();
    if (!$entry || $entry['status'] !== 'draft') {
        throw new RuntimeException('Only draft stock entries can be submitted.');
    }
    $type = $entry['entry_type'];
    $typeLabel = stock_entry_types()[$type] ?? $type;
    $items = stock_entry_load_items($entryId);
    if (!$items) {
        throw new RuntimeException('This stock entry has no items.');
    }

    foreach ($items as $n => $it) {
        $line = 'Row ' . ($n + 1) . ' (' . $it['product_name'] . '): ';
        $productId = (int)$it['product_id'];
        $qty = (int)$it['quantity'];
        $stockQty = (int)round(abs($qty) * (float)$it['uom_conversion_factor']);
        if ($stockQty <= 0) {
            throw new RuntimeException($line . 'quantity must not be zero.');
        }
        $dir = stock_entry_direction($type, $qty);
        $source = (int)$it['source_warehouse_id'];
        $target = (int)$it['target_warehouse_id'];
        if ($type === 'stock_adjustment') {
            // Adjustments move stock at a single warehouse.
            $source = $target;
        }
        if (($dir['out'] && !$source) || ($dir['in'] && !$target)) {
            throw new RuntimeException($line . 'warehouse is missing.');
        }

        $batchNo = trim((string)$it['batch_no']);
        if ($it['has_batch_no'] && $batchNo === '') {
            throw new RuntimeException($line . 'this item is batch-tracked, so a batch number is required (Batch & Serial tab).');
        }
        $batchId = null;
        if ($batchNo !== '') {
            if ($dir['out']) {
                $batchId = stock_entry_batch_id($productId, $batchNo);
                if ($batchId === null) {
                    throw new RuntimeException($line . 'batch "' . $batchNo . '" does not exist for this item.');
                }
            } else {
                $batchId = find_or_create_batch($productId, $batchNo, $it['manufacturing_date'] ?: null, $it['expiry_date'] ?: null);
            }
        }

        $serials = stock_entry_serials($it['serial_numbers']);
        if ($it['has_serial_no'] && count($serials) !== $stockQty) {
            throw new RuntimeException($line . 'this item is serial-tracked: enter exactly ' . $stockQty . ' serial number(s) (you entered ' . count($serials) . ').');
        }
        if ($serials && !$it['has_serial_no'] && count($serials) !== $stockQty) {
            throw new RuntimeException($line . 'number of serial numbers (' . count($serials) . ') does not match the quantity (' . $stockQty . ').');
        }

        $notes = 'Stock entry: ' . $typeLabel;
        if ($dir['out']) {
            try {
                stock_move($productId, $source, -$stockQty, $type === 'stock_adjustment' ? 'adjustment' : 'out', $entry['entry_no'], $notes, $userId, $batchId);
            } catch (RuntimeException $e) {
                $avail = $batchId === null ? warehouse_stock($productId, $source) : null;
                throw new RuntimeException($line . 'not enough stock in the source warehouse' . ($avail !== null ? ' (available: ' . $avail . ', needed: ' . $stockQty . ')' : ' for batch ' . $batchNo) . '.');
            }
        }
        if ($dir['in']) {
            stock_move($productId, $target, $stockQty, $type === 'stock_adjustment' ? 'adjustment' : 'in', $entry['entry_no'], $notes, $userId, $batchId);
        }

        if ($serials) {
            if ($dir['out']) {
                $find = $pdo->prepare('SELECT id, status, warehouse_id FROM product_serials WHERE product_id = ? AND serial_no = ? FOR UPDATE');
                foreach ($serials as $sn) {
                    $find->execute([$productId, $sn]);
                    $row = $find->fetch();
                    if (!$row || $row['status'] !== 'in_stock' || (int)$row['warehouse_id'] !== $source) {
                        throw new RuntimeException($line . 'serial number "' . $sn . '" is not in stock at the source warehouse.');
                    }
                    if ($dir['in']) {
                        $pdo->prepare('UPDATE product_serials SET warehouse_id = ? WHERE id = ?')->execute([$target, $row['id']]);
                    } else {
                        $pdo->prepare("UPDATE product_serials SET status = 'issued' WHERE id = ?")->execute([$row['id']]);
                    }
                }
            } else {
                $ins = $pdo->prepare('INSERT INTO product_serials (product_id, serial_no, batch_id, warehouse_id, status, stock_entry_item_id) VALUES (?,?,?,?,?,?)');
                foreach ($serials as $sn) {
                    try {
                        $ins->execute([$productId, $sn, $batchId, $target, 'in_stock', $it['id']]);
                    } catch (PDOException $e) {
                        if ($e->getCode() === '23000') {
                            throw new RuntimeException($line . 'serial number "' . $sn . '" already exists for this item.');
                        }
                        throw $e;
                    }
                }
            }
        }
    }

    $pdo->prepare("UPDATE stock_entries SET status = 'submitted', submitted_at = NOW(), submitted_by = ? WHERE id = ?")->execute([$userId, $entryId]);
}

/**
 * Reverses every movement of a submitted entry and marks it cancelled
 * (a draft is simply marked cancelled). Must run inside a transaction the
 * caller owns; throws RuntimeException if the stock has since been used
 * (e.g. transferred goods already issued from the target warehouse).
 */
function stock_entry_cancel(int $entryId, ?int $userId): void
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM stock_entries WHERE id = ? FOR UPDATE');
    $stmt->execute([$entryId]);
    $entry = $stmt->fetch();
    if (!$entry || $entry['status'] === 'cancelled') {
        throw new RuntimeException('This stock entry is already cancelled.');
    }

    if ($entry['status'] === 'submitted') {
        $type = $entry['entry_type'];
        $notes = 'Stock entry cancelled';
        foreach (array_reverse(stock_entry_load_items($entryId)) as $it) {
            $line = $it['product_name'] . ': ';
            $productId = (int)$it['product_id'];
            $qty = (int)$it['quantity'];
            $stockQty = (int)round(abs($qty) * (float)$it['uom_conversion_factor']);
            $dir = stock_entry_direction($type, $qty);
            $source = (int)$it['source_warehouse_id'];
            $target = (int)$it['target_warehouse_id'];
            if ($type === 'stock_adjustment') {
                $source = $target;
            }
            $batchNo = trim((string)$it['batch_no']);
            $batchId = $batchNo !== '' ? stock_entry_batch_id($productId, $batchNo) : null;

            if ($dir['in']) {
                try {
                    stock_move($productId, $target, -$stockQty, $type === 'stock_adjustment' ? 'adjustment' : 'out', $entry['entry_no'], $notes, $userId, $batchId);
                } catch (RuntimeException $e) {
                    throw new RuntimeException($line . 'cannot cancel, the stock this entry added has already been used.');
                }
            }
            if ($dir['out']) {
                stock_move($productId, $source, $stockQty, $type === 'stock_adjustment' ? 'adjustment' : 'in', $entry['entry_no'], $notes, $userId, $batchId);
            }

            $serials = stock_entry_serials($it['serial_numbers']);
            if ($serials) {
                if (!$dir['out']) {
                    $pdo->prepare('DELETE FROM product_serials WHERE stock_entry_item_id = ?')->execute([$it['id']]);
                } else {
                    $upd = $pdo->prepare("UPDATE product_serials SET status = 'in_stock', warehouse_id = ? WHERE product_id = ? AND serial_no = ?");
                    foreach ($serials as $sn) {
                        $upd->execute([$source, $productId, $sn]);
                    }
                }
            }
        }
    }

    $pdo->prepare("UPDATE stock_entries SET status = 'cancelled' WHERE id = ?")->execute([$entryId]);
}
