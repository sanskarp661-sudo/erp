<?php
/**
 * Per-warehouse stock helpers. This is the ONLY code path allowed to
 * change stock — Delivery Notes deduct through it, GRNs add through it,
 * POS and manual Stock Entries use it too. Sales/Purchase Orders never
 * touch stock directly; only the documents that record actual physical
 * movement (Delivery Note, GRN, Stock Entry, POS sale) do.
 *
 * products.quantity is kept as a maintained total across all warehouses
 * (updated here alongside the per-warehouse stock_bins row) so existing
 * total-stock displays (product list, dashboard, low-stock alerts,
 * inventory report) keep working unchanged.
 */

require_once __DIR__ . '/functions.php';

/** Current quantity of a product at a specific warehouse, across all batches (0 if no bin rows yet). */
function warehouse_stock(int $productId, int $warehouseId): int
{
    $stmt = db()->prepare('SELECT COALESCE(SUM(quantity), 0) FROM stock_bins WHERE product_id = ? AND warehouse_id = ?');
    $stmt->execute([$productId, $warehouseId]);
    return (int)$stmt->fetchColumn();
}

/**
 * Applies a signed quantity change to a product's stock at one warehouse
 * (optionally scoped to one batch — pass null for non-batch-tracked
 * stock), keeps products.quantity in sync, and logs a stock_movements row.
 * Must be called inside a transaction the caller owns (it does not
 * commit). Throws RuntimeException if a negative delta would take the bin
 * below zero. $batchId defaults to null so every pre-Phase-7 call site
 * keeps working unchanged.
 */
function stock_move(int $productId, int $warehouseId, int $delta, string $type, ?string $reference, ?string $notes, ?int $userId, ?int $batchId = null): void
{
    $pdo = db();

    $stmt = $pdo->prepare('SELECT quantity FROM stock_bins WHERE product_id = ? AND warehouse_id = ? AND batch_id <=> ? FOR UPDATE');
    $stmt->execute([$productId, $warehouseId, $batchId]);
    $current = $stmt->fetchColumn();

    if ($current === false) {
        if ($delta < 0) {
            throw new RuntimeException('Not enough stock in that warehouse.');
        }
        $pdo->prepare('INSERT INTO stock_bins (product_id, warehouse_id, batch_id, quantity) VALUES (?, ?, ?, ?)')
            ->execute([$productId, $warehouseId, $batchId, $delta]);
    } else {
        $newQty = (int)$current + $delta;
        if ($newQty < 0) {
            throw new RuntimeException('Not enough stock in that warehouse.');
        }
        $pdo->prepare('UPDATE stock_bins SET quantity = ? WHERE product_id = ? AND warehouse_id = ? AND batch_id <=> ?')
            ->execute([$newQty, $productId, $warehouseId, $batchId]);
    }

    $pdo->prepare('UPDATE products SET quantity = quantity + ? WHERE id = ?')->execute([$delta, $productId]);

    $pdo->prepare('INSERT INTO stock_movements (product_id, warehouse_id, batch_id, type, quantity, reference, notes, created_by) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$productId, $warehouseId, $batchId, $type, $delta, $reference, $notes, $userId]);
}

/**
 * How many stock-UOM units one unit of $uom equals for a product — 1.0 if
 * $uom is the product's own stock unit or isn't in its alternate-UOM list.
 * Used to convert a transaction line's quantity (entered in whatever UOM
 * the line uses) into a stock-UOM quantity before it reaches stock_move().
 */
function uom_conversion_factor(int $productId, string $uom): float
{
    $stmt = db()->prepare('SELECT unit FROM products WHERE id = ?');
    $stmt->execute([$productId]);
    $stockUnit = $stmt->fetchColumn();
    if ($stockUnit !== false && $uom === $stockUnit) {
        return 1.0;
    }
    $stmt = db()->prepare('SELECT conversion_factor FROM product_uoms WHERE product_id = ? AND uom = ?');
    $stmt->execute([$productId, $uom]);
    $factor = $stmt->fetchColumn();
    return $factor !== false ? (float)$factor : 1.0;
}

/**
 * Finds a product's existing batch by batch_no, or creates it (recording
 * manufacturing/expiry dates only the first time a batch_no is seen — a
 * later GRN receiving the same batch_no again doesn't overwrite them).
 * Returns the batch's id.
 */
function find_or_create_batch(int $productId, string $batchNo, ?string $manufacturingDate, ?string $expiryDate): int
{
    $stmt = db()->prepare('SELECT id FROM product_batches WHERE product_id = ? AND batch_no = ?');
    $stmt->execute([$productId, $batchNo]);
    $id = $stmt->fetchColumn();
    if ($id !== false) {
        return (int)$id;
    }
    db()->prepare('INSERT INTO product_batches (product_id, batch_no, manufacturing_date, expiry_date) VALUES (?,?,?,?)')
        ->execute([$productId, $batchNo, $manufacturingDate ?: null, $expiryDate ?: null]);
    return (int)db()->lastInsertId();
}

/** Non-group (leaf) warehouses only — the ones that can actually hold stock. */
function leaf_warehouses(): array
{
    return db()->query("SELECT id, name FROM warehouses WHERE is_group = 0 AND status = 'active' ORDER BY name")->fetchAll();
}

/** The warehouse new documents should default to (first active leaf warehouse). */
function default_warehouse_id(): ?int
{
    $id = db()->query("SELECT id FROM warehouses WHERE is_group = 0 AND status = 'active' ORDER BY id LIMIT 1")->fetchColumn();
    return $id !== false ? (int)$id : null;
}

/** Full ancestor-prefixed label for a warehouse, e.g. "All Warehouses / Stores". */
function warehouse_path(array $warehousesById, int $id): string
{
    $parts = [];
    $cursor = $warehousesById[$id] ?? null;
    while ($cursor) {
        array_unshift($parts, $cursor['name']);
        $cursor = $cursor['parent_id'] ? ($warehousesById[$cursor['parent_id']] ?? null) : null;
    }
    return implode(' / ', $parts);
}
